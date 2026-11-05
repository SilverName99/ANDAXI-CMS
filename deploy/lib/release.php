<?php

declare(strict_types=1);

/**
 * deploy/release.sh: o versiune de cod (`~/platforma/releases/<versiune>/`) dintr-un commit al repo-ului, verificată.
 * Nu atinge niciun site: un site trece pe ea doar prin adopta.sh sau actualizeaza.sh (docs/DEPLOY.md).
 *
 *   deploy/release.sh --ref=origin/claude/etapa1-cod-comun            # planul: commit-ul, versiunea, dosarul (nu scrie nimic)
 *   deploy/release.sh --ref=origin/claude/etapa1-cod-comun --aplica   # o face: git archive, VERSION, COMMIT, php -l, SHA256SUMS
 *   deploy/release.sh --verifica=2026.11.05-e4f5a6b                   # o versiune urcată e neatinsă?
 *   deploy/release.sh --lista                                         # versiunile și ce site-uri le folosesc
 *   deploy/release.sh --sterge=2026.10.30-1234567 --aplica            # șterge o versiune pe care n-o mai folosește niciun site
 *
 * Opțiuni: --repo=<clonă> (implicit ~/platforma/repo, altfel repo-ul în care stă scriptul), --acasa=<dosar> (implicit
 * HOME), --versiune=<v> (în loc de <data commit-ului>-<7 caractere>), --lansator (înlocuiește ~/platforma/bin/platforma cu
 * cel al versiunii, dacă diferă).
 *
 * Versiunea: `<anul>.<luna>.<ziua commit-ului>-<primele 7 caractere ale commit-ului>`, de exemplu 2026.11.05-e4f5a6b
 * (forma pe care o cere stub-ul). Același commit dă mereu aceeași versiune, deci o a doua rulare doar o verifică.
 * Fișierele versiunii sunt doar de citit (versiunea nu se mai schimbă după urcare); `--sterge` le face întâi scriibile.
 */

namespace Platforma\Deploy;

require __DIR__ . '/deploy.php';

exit(porneste(static function () use ($argv): int {
    $o = Optiuni::citeste($argv, ['ref=', 'repo=', 'acasa=', 'versiune=', 'verifica=', 'sterge=', 'lista', 'plan', 'aplica', 'lansator']);
    $aplica = $o->aplica();
    $acasa = Site::acasa($o);
    $releases = $acasa . '/platforma/releases';

    if ($o->are('lista')) {
        return lista($acasa, $releases);
    }
    if (($v = $o->text('verifica')) !== null) {
        Iesire::titlu('Verificarea versiunii ' . $v);
        $probleme = Versiune::verifica($releases . '/' . $v, $v);
        foreach ($probleme as $p) {
            Iesire::nu($p);
        }
        if ($probleme !== []) {
            throw new Oprire('versiunea ' . $v . ' nu e cea urcată (' . count($probleme) . ' probleme)', 1,
                ['fă-o din nou: șterge-o (--sterge=' . $v . ' --aplica, dacă n-o folosește niciun site) și rulează iar --ref=… --aplica']);
        }
        Iesire::ok('versiunea ' . $v . ' e neatinsă (VERSION, fiecare fișier din SHA256SUMS)');
        return 0;
    }
    if (($v = $o->text('sterge')) !== null) {
        return sterge($acasa, $releases, $v, $aplica);
    }

    $ref = $o->cere('ref', '<commit sau ramură> (de exemplu origin/claude/etapa1-cod-comun)');
    $repo = $o->text('repo') ?? (is_dir($acasa . '/platforma/repo/.git') ? $acasa . '/platforma/repo' : dirname(__DIR__, 2));
    $git = static fn (string ...$a): array => Comanda::ruleaza(array_merge(['git', '-C', $repo], $a));
    if ($git('rev-parse', '--git-dir')['cod'] !== 0) {
        throw new Oprire('nu e un repo git: ' . $repo . ' (dă --repo=)', 2);
    }
    $r = $git('rev-parse', '--verify', '--quiet', $ref . '^{commit}');
    $commit = trim($r['iesire']);
    if ($r['cod'] !== 0 || preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
        throw new Oprire('nu găsesc commit-ul „' . $ref . '” în ' . $repo, 1, ['git -C ' . $repo . ' fetch origin, apoi din nou']);
    }
    $data = trim($git('show', '-s', '--format=%cd', '--date=format:%Y.%m.%d', $commit)['iesire']);
    $versiune = $o->text('versiune') ?? $data . '-' . substr($commit, 0, 7);
    if (preg_match(Versiune::TIPAR, $versiune) !== 1 || !str_ends_with($versiune, '-' . substr($commit, 0, 7))) {
        throw new Oprire('versiunea „' . $versiune . '” trebuie să aibă forma 2026.11.05-' . substr($commit, 0, 7), 2);
    }
    $tinta = $releases . '/' . $versiune;
    Iesire::titlu('Versiunea de cod ' . $versiune);
    Iesire::info('repo     : ' . $repo);
    Iesire::info('commit   : ' . $commit . '  ' . trim($git('show', '-s', '--format=%s', $commit)['iesire']));
    Iesire::info('dosarul  : ' . $tinta);

    if (is_dir($tinta)) {
        $probleme = Versiune::verifica($tinta, $versiune);
        if ($probleme !== []) {
            foreach ($probleme as $p) {
                Iesire::nu($p);
            }
            throw new Oprire('versiunea există deja, dar nu mai e cea urcată', 1, ['deploy/release.sh --sterge=' . $versiune . ' --aplica, apoi din nou']);
        }
        Iesire::ok('există deja și e neatinsă; nimic de făcut');
        lansator($acasa, $tinta, $aplica && $o->are('lansator'));
        return 0;
    }
    if (!$aplica) {
        $n = count(array_filter(explode("\n", $git('ls-tree', '-r', '--name-only', $commit)['iesire'])));
        Iesire::info('ar face  : git archive (' . $n . ' fișiere în commit, fără site-uri/ și tools/), VERSION, COMMIT, php -l pe fiecare fișier PHP, '
            . 'SHA256SUMS, fișierele doar de citit');
        Iesire::scrie('');
        Iesire::scrie('PLAN: nimic scris. Pentru a o face: aceeași comandă cu --aplica.');
        return 0;
    }

    Fisiere::dosar($releases, 0755);
    $temp = $releases . '/.' . $versiune . '.tmp-' . getmypid();
    $curata = static function () use ($temp, $releases): void {
        if (is_dir($temp)) {
            Fisiere::sterge($temp, [$releases]);
        }
    };
    try {
        Fisiere::dosar($temp, 0755);
        $r = Comanda::shell('git -C ' . escapeshellarg($repo) . ' archive --format=tar ' . escapeshellarg($commit) . ' | tar -xf - -C ' . escapeshellarg($temp));
        if ($r['cod'] !== 0) {
            throw new Oprire('git archive n-a mers: ' . trim($r['erori']));
        }
        file_put_contents($temp . '/VERSION', $versiune . "\n");
        file_put_contents($temp . '/COMMIT', $commit . "\n");
        verificaContinutul($temp, $versiune);
        $fisiere = Fisiere::lista($temp);
        $sume = '';
        foreach ($fisiere as $rel) {
            $sume .= hash_file('sha256', $temp . '/' . $rel) . '  ' . $rel . "\n";
        }
        file_put_contents($temp . '/SHA256SUMS', $sume);
        Iesire::ok('SHA256SUMS: ' . count($fisiere) . ' fișiere');
        $r = Comanda::ruleaza(['chmod', '-R', 'a-w', $temp]);
        if ($r['cod'] !== 0) {
            throw new Oprire('nu pot face fișierele doar de citit: ' . trim($r['erori']));
        }
        if (!@rename($temp, $tinta)) {
            throw new Oprire('nu pot muta ' . $temp . ' în ' . $tinta);
        }
    } finally {
        $curata();
    }
    $probleme = Versiune::verifica($tinta, $versiune);
    if ($probleme !== []) {
        throw new Oprire('versiunea făcută nu trece verificarea: ' . implode('; ', $probleme));
    }
    Iesire::ok('versiunea ' . $versiune . ' e gata, doar de citit: ' . $tinta);
    lansator($acasa, $tinta, true);
    Iesire::scrie('');
    Iesire::scrie('GATA. Niciun site nu s-a schimbat. Un site trece pe ea cu deploy/adopta.sh (prima dată) sau deploy/actualizeaza.sh --versiune=' . $versiune . '.');
    return 0;
}));

/** Ce trebuie să aibă (și să nu aibă) o versiune; php -l pe fiecare fișier PHP. */
function verificaContinutul(string $dosar, string $versiune): void
{
    $lipsa = [];
    foreach (['bootstrap.php', 'public/index.php', 'bin/platforma', 'scripts/migreaza.php', 'scripts/diagnostic.php', 'scripts/install.php',
        'deploy/stub/index.php', 'deploy/stub/.htaccess', 'deploy/stub/mentenanta.html', 'database/migrari/migrari.lock',
        'database/migrari/ultima.php', 'config/app.php', 'public/assets'] as $f) {
        if (!file_exists($dosar . '/' . $f)) {
            $lipsa[] = $f;
        }
    }
    if ($lipsa !== []) {
        throw new Oprire('versiunii îi lipsesc: ' . implode(', ', $lipsa));
    }
    foreach (['site-uri', 'tools', '.env', 'storage/logs/php.log'] as $f) {
        if (file_exists($dosar . '/' . $f)) {
            throw new Oprire('versiunea are ' . $f . ' (n-ar trebui: .gitattributes, export-ignore; nicio dată a unui site în cod)');
        }
    }
    foreach (Fisiere::lista($dosar) as $rel) {
        if (preg_match('#(^|/)\.env($|\.)#', $rel) === 1 && !str_ends_with($rel, '.env.example')) {
            throw new Oprire('versiunea are un fișier .env: ' . $rel);
        }
    }
    Iesire::ok('conținutul: fișierele cerute există; fără site-uri/, tools/ și .env');
    $lock = json_decode((string) file_get_contents($dosar . '/database/migrari/migrari.lock'), true);
    if (!is_array($lock) || !is_array($lock['migrari'] ?? null) || $lock['migrari'] === []) {
        throw new Oprire('database/migrari/migrari.lock nu e JSON-ul așteptat');
    }
    Iesire::ok('migrari.lock: ' . count($lock['migrari']) . ' migrări publicate');
    $php = Comanda::php();
    $r = Comanda::ruleaza(array_merge($php, ['-r', 'echo PHP_VERSION;']));
    $phpMin = Versiune::phpMin($dosar);
    $versiunePhp = trim($r['iesire']);
    if ($r['cod'] !== 0 || version_compare($versiunePhp, $phpMin, '<')) {
        throw new Oprire('PHP-ul liniei de comandă (' . $versiunePhp . ') e sub minimul codului (' . $phpMin . '): ' . implode(' ', $php));
    }
    $deVerificat = array_values(array_filter(Fisiere::lista($dosar), static fn (string $f): bool => str_ends_with($f, '.php') || $f === 'bin/platforma'));
    $erori = [];
    foreach (array_chunk($deVerificat, 60) as $bucata) {
        $r = Comanda::ruleaza(array_merge($php, ['-n', '-l'], array_map(static fn (string $f): string => $dosar . '/' . $f, $bucata)));
        if ($r['cod'] !== 0) {
            foreach (explode("\n", $r['iesire'] . "\n" . $r['erori']) as $linie) {
                if (preg_match('/(Parse|Fatal|Compile) error/i', $linie) === 1) {
                    $erori[] = trim(str_replace($dosar . '/', '', $linie));
                }
            }
        }
    }
    if ($erori !== []) {
        throw new Oprire('php -l: ' . count($erori) . ' fișiere cu erori: ' . implode(' | ', array_slice($erori, 0, 5)));
    }
    Iesire::ok('php -l: ' . count($deVerificat) . ' fișiere PHP fără erori de sintaxă (PHP ' . $versiunePhp . ', minim ' . $phpMin . ')');
    if (trim((string) file_get_contents($dosar . '/VERSION')) !== $versiune) {
        throw new Oprire('VERSION nu s-a scris');
    }
}

/**
 * ~/platforma/bin/platforma, lansatorul din cronuri: o copie a lui bin/platforma (nu depinde de versiune, citește
 * RELEASE-ul site-ului). Se pune dacă lipsește; unul diferit se înlocuiește doar cu --lansator.
 */
function lansator(string $acasa, string $versiune, bool $inlocuieste): void
{
    $tinta = $acasa . '/platforma/bin/platforma';
    $nou = (string) file_get_contents($versiune . '/bin/platforma');
    if (is_file($tinta) && (string) file_get_contents($tinta) === $nou) {
        Iesire::ok('lansatorul cronurilor (' . $tinta . ') e la zi');
        return;
    }
    if (is_file($tinta) && !$inlocuieste) {
        Iesire::atentie('lansatorul cronurilor (' . $tinta . ') diferă de bin/platforma al versiunii; îl înlocuiește aceeași comandă cu --aplica --lansator');
        return;
    }
    Fisiere::dosar(dirname($tinta), 0755);
    Fisiere::scrie($tinta, $nou, 0755);
    Iesire::ok('lansatorul cronurilor pus: ' . $tinta);
}

/** @return array<string, list<string>> versiunea → site-urile (domeniile) care o au în RELEASE */
function folosite(string $acasa): array
{
    $rez = [];
    foreach (glob($acasa . '/domains/*/site/RELEASE') ?: [] as $f) {
        $rez[trim((string) file_get_contents($f))][] = basename(dirname($f, 2));
    }
    // După o actualizare reușită, versiunea de dinainte rămâne pentru o întoarcere de mână: nici ea nu se șterge.
    foreach (glob($acasa . '/domains/*/site/deploy/actualizare.stare') ?: [] as $f) {
        $d = json_decode((string) file_get_contents($f), true);
        if (is_array($d) && ($d['gata'] ?? false) === true && is_string($d['versiune_veche'] ?? null)) {
            $rez[$d['versiune_veche']][] = basename(dirname($f, 3)) . ' (versiunea de dinaintea ultimei actualizări)';
        }
    }
    return $rez;
}

function lista(string $acasa, string $releases): int
{
    Iesire::titlu('Versiunile din ' . $releases);
    $folosite = folosite($acasa);
    foreach (glob($releases . '/*/VERSION') ?: [] as $f) {
        $v = basename(dirname($f));
        Iesire::info(str_pad($v, 22) . (isset($folosite[$v]) ? 'folosită de ' . implode(', ', array_unique($folosite[$v])) : 'nefolosită'));
    }
    return 0;
}

function sterge(string $acasa, string $releases, string $v, bool $aplica): int
{
    if (preg_match(Versiune::TIPAR, $v) !== 1 || !is_dir($releases . '/' . $v)) {
        throw new Oprire('nu există versiunea ' . $v . ' în ' . $releases, 2);
    }
    $folosite = folosite($acasa);
    if (isset($folosite[$v])) {
        throw new Oprire('versiunea ' . $v . ' e folosită de ' . implode(', ', array_unique($folosite[$v])) . '; n-o șterg');
    }
    if (!$aplica) {
        Iesire::scrie('PLAN: aș șterge ' . $releases . '/' . $v . ' (n-o folosește niciun site). Cu --aplica o șterge.');
        return 0;
    }
    // Copiile locale din site/releases/ (open_basedir) întâi: legături fixe spre aceleași fișiere, șterse fără să le facă
    // scriibile (Fisiere::sterge schimbă doar dosarele).
    foreach (glob($acasa . '/domains/*/site/releases/' . $v, GLOB_ONLYDIR) ?: [] as $copie) {
        Fisiere::sterge($copie, [dirname($copie)]);
        Iesire::ok('ștearsă copia locală: ' . $copie);
    }
    Fisiere::sterge($releases . '/' . $v, [$releases]);
    Iesire::ok('ștearsă: ' . $releases . '/' . $v);
    return 0;
}
