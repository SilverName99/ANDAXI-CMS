<?php

declare(strict_types=1);

/**
 * Lista albă a cheilor de setări pentru încărcarea copiei de verificare (revizuirea WP-11b; docs/VERIFICARE.md, „Încărcarea
 * copiei”): scrie `tools/verificare/reguli/setari-cunoscute.php`, cu cheile pe care le știe fiecare cod, citite din cod (nu
 * din vreo bază): codul comun (arborele de lucru) și codul vechi al fiecărui site cu reguli (clona lui la „ultimul commit
 * revizuit” din docs/de-portat.md, citită cu `git show`). Scanerul încărcării oprește o sursă care are în `settings` o cheie
 * pe care n-o știe niciun cod, în afară de cele neutralizate (Reguli::scaneazaSetari), ca la o coloană necunoscută.
 *
 *   tools/dev/php.sh tools/dev/genereaza-setari-cunoscute.php             # scrie fișierul
 *   tools/dev/php.sh tools/dev/genereaza-setari-cunoscute.php --verifica  # nu scrie: e la zi? (cod 1 dacă nu)
 *
 * Ce e o cheie „știută” de un cod (în src/, views/, scripts/, config/, database/ și bootstrap.php; migrările scriu și ele chei):
 *  - cheile din `Settings::DEFAULTS` (src/Support/Settings.php);
 *  - o citire `$settings['cheie']`, `$setari['cheie']` (și `$designSettings`, `$adminSettings`, `$setariGdpr`, `->settings['cheie']`);
 *  - un apel `Settings::<metodă>($db, 'cheie', …)`;
 *  - cheile scrise de `Settings::save($db, [...])` (cele literale din tabloul dat) și, într-un fișier cu `Settings::save($db, $x)`,
 *    cele puse în `$x` (`$x['cheie'] = …` sau `$x = ['cheie' => …]`);
 *  - constantele `CHEIE…`/`KEY…` = 'cheie' și cheile scrise direct în SQL (`INSERT INTO settings … ('cheie'`, `` `key` = 'cheie' ``,
 *    `->execute(['k' => 'cheie'`).
 * Familiile cu nume făcute din mers (`email_template_<tip>_<câmp>`, `customer_registration_field_<câmp>`) sunt în reguli, ca
 * tipar. Lista e o aproximare largă a ce folosește codul; o cheie care lipsește din ea oprește încărcarea (fail-closed), cu
 * numele ei, și se adaugă aici după ce o vedem.
 *
 * Cod de ieșire: 0 scris sau la zi; 1 o clonă lipsă sau (--verifica) altfel decât fișierul din git.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
$fisier = $radacina . '/tools/verificare/reguli/setari-cunoscute.php';
$verifica = in_array('--verifica', $argv, true);
foreach (array_slice($argv, 1) as $argument) {
    if ($argument !== '--verifica') {
        fwrite(STDERR, "argument necunoscut: {$argument} (--verifica)\n");
        exit(1);
    }
}

/** Cheile de pe primul nivel ale fiecărui tablou care începe cu $inceput (ultimul caracter, `[`) în $text. @return list<string> */
function chei_tablou_dupa(string $text, string $inceput): array
{
    $chei = [];
    $poz = 0;
    while (($poz = strpos($text, $inceput, $poz)) !== false) {
        $start = $poz + strlen($inceput) - 1;
        $adancime = 0;
        $nivelUnu = '';
        for ($i = $start; $i < strlen($text); $i++) {
            $c = $text[$i];
            if ($c === '[') {
                $adancime++;
            } elseif ($c === ']') {
                $adancime--;
                if ($adancime === 0) {
                    break;
                }
            }
            $nivelUnu .= $adancime === 1 ? $c : ' ';
        }
        preg_match_all("/'([a-z][a-z0-9_]*)'\\s*=>/", $nivelUnu, $m);
        array_push($chei, ...$m[1]);
        $poz = $start + 1;
    }
    return $chei;
}

/** Cheile știute de un cod, din textele fișierelor lui (cale → text). @param array<string, string> $fisiere @return list<string> */
function chei_din_cod(array $fisiere): array
{
    $chei = [];
    $adauga = static function (string $cheie) use (&$chei): void {
        if (preg_match('/^[a-z][a-z0-9_]{1,99}$/', $cheie) === 1) {
            $chei[$cheie] = true;
        }
    };
    foreach ($fisiere as $cale => $text) {
        if ($cale === 'src/Support/Settings.php' && preg_match('/const DEFAULTS = \[(.*?)\n    \];/s', $text, $m) === 1) {
            preg_match_all("/^\\s*'([a-z0-9_]+)'\\s*=>/m", $m[1], $d);
            array_map($adauga, $d[1]);
        }
        $tipare = [
            // Variabilele care țin setările (nu `$btSetari`, `$setariComanda`: configurarea unui plugin, starea unei comenzi).
            "/\\$(?:settings|designSettings|adminSettings|testSettings|setari|setariGdpr)\\s*\\[\\s*'([a-z][a-z0-9_]*)'\\s*\\]/",
            "/->settings\\s*\\[\\s*'([a-z][a-z0-9_]*)'\\s*\\]/",
            "/Settings::[A-Za-z]+\\(\\s*\\$[A-Za-z]+\\s*,\\s*'([a-z][a-z0-9_]*)'/",
            "/const\\s+(?:CHEIE|KEY)[A-Z_]*\\s*=\\s*'([a-z][a-z0-9_]*)'/",
            "/INSERT INTO settings[^;]{0,200}?VALUES\\s*\\(\\s*'([a-z][a-z0-9_]*)'/i",
            "/`key`\\s*=\\s*'([a-z][a-z0-9_]*)'/",
            "/execute\\(\\s*\\[\\s*'k'\\s*=>\\s*'([a-z][a-z0-9_]*)'/",
        ];
        foreach ($tipare as $tipar) {
            preg_match_all($tipar, $text, $m);
            array_map($adauga, $m[1]);
        }
        // Ce ajunge în Settings::save: tabloul literal dat direct (`Settings::save($db, ['cheie' => …])`) și, pentru o variabilă
        // dată lui (`Settings::save($db, $x)`), cheile puse în ea (`$x['cheie'] = …`, `$x = ['cheie' => …]`) **în aceeași funcție,
        // înaintea salvării**: alte tablouri cu același nume (răspunsuri JSON, rânduri) nu sunt setări. Doar cheile de pe primul
        // nivel al tabloului (un json_encode([...]) din el nu e o setare).
        $functii = preg_split('/\bfunction\b/', $text) ?: [$text];
        foreach ($functii as $bucata) {
            foreach (chei_tablou_dupa($bucata, 'Settings::save($db, [') as $cheie) {
                $adauga($cheie);
            }
            preg_match_all('/Settings::save\(\s*\$db\s*,\s*\$([A-Za-z_]+)\s*\)/', $bucata, $v, PREG_OFFSET_CAPTURE);
            foreach ($v[1] as [$variabila, $pozSalvare]) {
                $inainte = substr($bucata, 0, $pozSalvare);
                preg_match_all('/\$' . $variabila . "\\s*\\[\\s*'([a-z][a-z0-9_]*)'\\s*\\]\\s*=(?!=)/", $inainte, $m);
                array_map($adauga, $m[1]);
                foreach (chei_tablou_dupa($inainte, '$' . $variabila . ' = [') as $cheie) {
                    $adauga($cheie);
                }
            }
        }
    }
    $lista = array_keys($chei);
    sort($lista, SORT_STRING);
    return $lista;
}

/** Fișierele PHP de cod ale arborelui de lucru (cale relativă → text). @return array<string, string> */
function fisiere_arbore(string $dosar): array
{
    $r = [];
    foreach (['src', 'views', 'scripts', 'config', 'database'] as $sub) {
        if (!is_dir($dosar . '/' . $sub)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dosar . '/' . $sub, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                $r[substr($f->getPathname(), strlen($dosar) + 1)] = (string) file_get_contents($f->getPathname());
            }
        }
    }
    if (is_file($dosar . '/bootstrap.php')) {
        $r['bootstrap.php'] = (string) file_get_contents($dosar . '/bootstrap.php');
    }
    ksort($r);
    return $r;
}

/** Fișierele PHP de cod ale unei clone la un commit (git show). @return array<string, string> */
function fisiere_commit(string $clona, string $commit): array
{
    exec('git -C ' . escapeshellarg($clona) . ' ls-tree -r --name-only ' . escapeshellarg($commit) . ' -- src views scripts config database bootstrap.php 2>/dev/null', $cai, $cod);
    if ($cod !== 0 || $cai === []) {
        throw new RuntimeException('nu citesc ' . basename($clona) . '@' . $commit);
    }
    $r = [];
    foreach ($cai as $cale) {
        if (str_ends_with($cale, '.php')) {
            $r[$cale] = (string) shell_exec('git -C ' . escapeshellarg($clona) . ' show ' . escapeshellarg($commit . ':' . $cale) . ' 2>/dev/null');
        }
    }
    ksort($r);
    return $r;
}

// Site-urile cu reguli de încărcare (tools/verificare/reguli/<site>.php) și clonele lor (ca tools/dev/comun.sh).
$clone = ['bioscem' => ['CMS_DEV_REPO_BS', 'Nou-site-bioscem'], 'biovitality' => ['CMS_DEV_REPO_BV', 'Mutare-Site-Biovitality']];
$liste = ['nucleu' => chei_din_cod(fisiere_arbore($radacina))];
$surse = ['nucleu' => 'ANDAXI-CMS (arborele de lucru)'];
foreach ($clone as $site => [$variabila, $implicit]) {
    $clona = (string) (getenv($variabila) ?: dirname($radacina) . '/' . $implicit);
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($radacina . '/tools/dev/de-portat.php') . ' --revizuit=' . $site . ' 2>/dev/null', $iesire, $cod);
    $commit = trim((string) end($iesire));
    $iesire = [];
    if ($cod !== 0 || preg_match('/^[0-9a-f]{7,40}$/', $commit) !== 1 || !is_dir($clona)) {
        fwrite(STDERR, 'EROARE: clona ' . $site . ' (' . $clona . ') sau „ultimul commit revizuit” lipsește' . "\n");
        exit(1);
    }
    $liste[$site] = chei_din_cod(fisiere_commit($clona, $commit));
    $surse[$site] = $implicit . '@' . $commit;
}

$text = "<?php\n\ndeclare(strict_types=1);\n\n"
    . "/**\n * Cheile de setări știute de fiecare cod (lista albă a scanerului setărilor la încărcarea copiei de verificare; Reguli::scaneazaSetari).\n"
    . " * Generat de tools/dev/genereaza-setari-cunoscute.php din cod (nu din vreo bază); nu se editează de mână. Se generează din nou\n"
    . " * după o setare nouă în codul comun și după ce se mută „ultimul commit revizuit” al unui site (testul încărcării verifică).\n */\n\n"
    . 'return ' . var_export(['surse' => $surse] + $liste, true) . ";\n";

if ($verifica) {
    $laZi = is_file($fisier) && (string) file_get_contents($fisier) === $text;
    echo ($laZi ? 'OK    ' : 'EȘEC  ') . 'tools/verificare/reguli/setari-cunoscute.php ' . ($laZi ? 'la zi' : 'e altul decât cel generat acum (rulează fără --verifica)')
        . ' (' . implode(', ', array_map(static fn (string $s, array $l): string => $s . ' ' . count($l), array_keys($liste), $liste)) . ")\n";
    exit($laZi ? 0 : 1);
}
file_put_contents($fisier, $text);
echo 'scris tools/verificare/reguli/setari-cunoscute.php (' . implode(', ', array_map(static fn (string $s, array $l): string => $s . ' ' . count($l), array_keys($liste), $liste)) . ")\n";
exit(0);
