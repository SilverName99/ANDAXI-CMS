<?php

declare(strict_types=1);

/**
 * Din amprenta unui site live (tools/sonda/amprenta-schema.php, WP-10, MIG-08), fixture-ul
 * migrărilor: schema bazei ca SQL (doar structură, fără niciun rând) și lista obiectelor pe
 * care site-ul le are în plus față de schema așteptată (extra-<cod>.txt, pentru testul T2 al migrărilor, WP-04).
 * Rulează local, pe fișierul trimis de proprietar:
 *
 *   tools/dev/php.sh tools/dev/migrari/din-sonda.php tools/dev/migrari/vechi/privat/amprenta-bioscem-….json
 *   tools/dev/php.sh tools/dev/migrari/din-sonda.php <fișier.json> --fata-de=<amprenta.json>
 *   tools/dev/php.sh tools/dev/migrari/din-sonda.php <fișier.json> --iesire=<dosar>   # alt dosar (testele)
 *
 * Scrie în tools/dev/migrari/vechi/privat/ (ignorat de git: repo-ul e public, decizia OD-49, iar
 * fișierele sunt scoase din datele live): <cod>-live.sql, extra-<cod>.txt și
 * <cod>-fata-de-asteptat.txt (ce lipsește sau diferă față de referință). Referința: --fata-de,
 * altfel database/migrari/asteptat.json (WP-03), altfel nu se scriu decât schema și un
 * avertisment. Fixture-urile din git (tools/dev/migrari/vechi/<cod>.sql, WP-04) rămân cele din cod,
 * potrivite de mână după structura de aici, niciodată după numerele ei.
 *
 * Refuză: un fișier de intrare din repo care nu e ignorat de git (îl muți întâi în privat/), un
 * JSON care nu e al sondei, un dosar de ieșire din repo pe care git nu-l ignoră (--iesire poate
 * fi doar în afara repo-ului sau într-un dosar ignorat). Căile relative sunt față de dosarul
 * curent (tools/dev/php.sh pornește din rădăcina repo-ului) și se fac absolute, cu legăturile
 * rezolvate, înainte de verificare: aceeași cale se verifică și se scrie.
 *
 * SQL-ul refăcut din amprentă se încarcă pe același fel de server ca sursa (familia e scrisă
 * în capul lui); pe MariaDB și pe MySQL 8 dă înapoi aceeași amprentă (tools/dev/test-sonde.php).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = (string) realpath(dirname(__DIR__, 3));
require_once $radacina . '/src/Support/Migrari/Amprenta.php';
require_once $radacina . '/src/Support/Migrari/Server.php';

use App\Support\Migrari\Amprenta;

$dosarIesire = $radacina . '/tools/dev/migrari/vechi/privat';

$opreste = static function (string $mesaj): never {
    fwrite(STDERR, 'EROARE: ' . $mesaj . "\n");
    exit(1);
};

/**
 * Calea absolută, cu legăturile rezolvate, a unui fișier sau dosar care poate să nu existe încă:
 * relativă la dosarul curent dacă nu începe cu „/”, cel mai apropiat părinte care există trecut
 * prin realpath, restul adăugat. null pentru „..” într-o parte care nu există (nu se poate
 * rezolva fără să ghicim).
 */
$absoluta = static function (string $cale): ?string {
    if (!str_starts_with($cale, '/')) {
        $cale = (string) getcwd() . '/' . $cale;
    }
    $rest = [];
    $curent = rtrim($cale, '/');
    while (($real = realpath($curent === '' ? '/' : $curent)) === false) {
        $nume = basename($curent);
        if ($nume === '..') {
            return null;
        }
        if ($nume !== '.' && $nume !== '') {
            array_unshift($rest, $nume);
        }
        $parinte = dirname($curent);
        if ($parinte === $curent) {
            return null;
        }
        $curent = $parinte;
    }
    return rtrim($real, '/') . ($rest !== [] ? '/' . implode('/', $rest) : '');
};

/**
 * Fișierul e în repo și git nu-l ignoră (deci ar putea intra într-un commit)? Calea se face întâi
 * absolută (relativă la dosarul curent, cu legăturile rezolvate, și pentru un fișier care nu
 * există încă); una care nu se poate rezolva e tratată ca neignorată (refuz).
 */
$neignorat = static function (string $cale) use ($radacina, $absoluta): bool {
    $real = $absoluta($cale);
    if ($real === null) {
        return true;
    }
    if ($real !== $radacina && !str_starts_with($real, $radacina . '/')) {
        return false;
    }
    if ($real === $radacina) {
        return true;
    }
    exec('git -C ' . escapeshellarg($radacina) . ' check-ignore -q ' . escapeshellarg(substr($real, strlen($radacina) + 1)) . ' 2>/dev/null', $iesire, $cod);
    return $cod !== 0;
};

$intrare = null;
$fataDe = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--fata-de=')) {
        $fataDe = substr($argument, strlen('--fata-de='));
    } elseif (str_starts_with($argument, '--iesire=')) {
        $dosarIesire = rtrim(substr($argument, strlen('--iesire=')), '/');
    } elseif (!str_starts_with($argument, '--') && $intrare === null) {
        $intrare = $argument;
    } else {
        $opreste('argument necunoscut: ' . $argument . ' (vezi capul fișierului)');
    }
}
if ($intrare === null || !is_file($intrare)) {
    $opreste('dă fișierul JSON al sondei (tools/sonda/amprenta-schema.php)');
}
if ($neignorat($intrare)) {
    $opreste('fișierul sondei e în repo și git nu-l ignoră; mută-l în tools/dev/migrari/vechi/privat/ (are numere din datele live)');
}
$sonda = json_decode((string) file_get_contents($intrare), true);
if (!is_array($sonda) || ($sonda['unealta'] ?? '') !== 'amprenta-schema' || (int) ($sonda['format'] ?? 0) !== 1 || !is_array($sonda['brut'] ?? null)) {
    $opreste('fișierul nu e o amprentă a sondei (format 1)');
}
$cod = (string) ($sonda['site']['cod'] ?? '');
if (preg_match('/^[a-z]{2,10}$/', $cod) !== 1) {
    $opreste('amprenta nu spune site-ul (site.cod)');
}
$familie = ($sonda['server']['familie'] ?? '') === 'mysql' ? 'mysql' : 'mariadb';

// Ieșirea, ca o cale absolută (relativă la dosarul curent dacă e dată așa): verificată și scrisă
// pe aceeași cale, pentru fiecare dintre cele trei fișiere.
$dosarIesire = $absoluta($dosarIesire);
if ($dosarIesire === null) {
    $opreste('--iesire: calea are „..” într-un dosar care nu există; dă o cale fără „..”');
}
foreach ([$cod . '-live.sql', 'extra-' . $cod . '.txt', $cod . '-fata-de-asteptat.txt'] as $fisier) {
    if ($neignorat($dosarIesire . '/' . $fisier)) {
        $opreste('dosarul de ieșire e în repo și git nu-l ignoră (.gitignore); nu scriu acolo date scoase din live');
    }
}
if (!is_dir($dosarIesire) && !@mkdir($dosarIesire, 0700, true) && !is_dir($dosarIesire)) {
    $opreste('nu pot face dosarul de ieșire');
}

$brut = $sonda['brut'];
$amprenta = Amprenta::normalizeaza($brut, $familie);

/** Un nume între backtick-uri. */
$id = static fn (string $nume): string => '`' . str_replace('`', '``', $nume) . '`';

// Coloanele în ordinea lor din tabel (amprenta le ține alfabetic), cu colația și expresia generată.
$detalii = [];
foreach ($brut['coloane'] ?? [] as $c) {
    $detalii[(string) $c['tabel']][(string) $c['coloana']] = $c;
}

$sql = '-- Schema site-ului ' . (string) ($sonda['site']['nume'] ?? $cod) . ', din sonda din ' . (string) ($sonda['generat_la'] ?? '?')
    . ' (' . ($familie === 'mysql' ? 'MySQL' : 'MariaDB') . ' ' . (string) ($sonda['server']['versiune'] ?? '?') . ").\n"
    . "-- Doar structură, niciun rând. Făcut de tools/dev/migrari/din-sonda.php; NU intră în git (OD-49).\n"
    . "SET FOREIGN_KEY_CHECKS = 0;\n\n";
foreach ($amprenta['tabele'] as $tabel => $t) {
    $colatieTabel = (string) ($t['colatie'] ?? '');
    $coloane = $t['coloane'];
    uksort($coloane, static fn (string $a, string $b): int => ((int) ($detalii[$tabel][$a]['pozitie'] ?? 0)) <=> ((int) ($detalii[$tabel][$b]['pozitie'] ?? 0)) ?: strcmp($a, $b));
    $randuri = [];
    foreach ($coloane as $nume => $c) {
        $d = $detalii[$tabel][$nume] ?? [];
        $text = $id($nume) . ' ' . $c['tip'];
        $colatie = (string) ($d['colatie'] ?? '');
        if ($colatie !== '' && $colatie !== $colatieTabel && preg_match('/^[a-z0-9_]+$/', $colatie) === 1) {
            $text .= ' COLLATE ' . $colatie;
        }
        $generata = trim((string) ($d['expresie_generata'] ?? ''));
        if ($generata !== '' && preg_match('/\b(virtual|stored|persistent)\b/', $c['extra']) === 1) {
            $text .= ' GENERATED ALWAYS AS (' . $generata . ') ' . (preg_match('/\b(stored|persistent)\b/', $c['extra']) === 1 ? 'STORED' : 'VIRTUAL');
            $text .= $c['null'] ? ' NULL' : ' NOT NULL';
            $randuri[] = '  ' . $text;
            continue;
        }
        $text .= $c['null'] ? ' NULL' : ' NOT NULL';
        $implicit = $c['implicit'];
        if ($implicit !== null) {
            $literal = str_starts_with($implicit, "'") || preg_match('/^-?[0-9.]+(e[+-]?\d+)?$/i', $implicit) === 1
                || preg_match("/^(b|x)'/i", $implicit) === 1 || str_starts_with(strtolower($implicit), '0x')
                || preg_match('/^current_timestamp(\(\d\))?$/', $implicit) === 1;
            $text .= ' DEFAULT ' . ($literal ? $implicit : '(' . $implicit . ')');
        }
        if ($c['extra'] !== '') {
            $text .= ' ' . $c['extra'];
        }
        $randuri[] = '  ' . $text;
    }
    $indecsi = $t['indecsi'];
    if (isset($indecsi['PRIMARY'])) {
        $indecsi = ['PRIMARY' => $indecsi['PRIMARY']] + $indecsi;
    }
    $coloaneIndex = static function (array $lista) use ($id): string {
        return implode(', ', array_map(static function (string $c) use ($id): string {
            if (str_starts_with($c, '(')) {
                return $c; // index pe o expresie
            }
            return preg_match('/^(.*)\((\d+)\)$/', $c, $m) === 1 ? $id($m[1]) . '(' . $m[2] . ')' : $id($c);
        }, $lista));
    };
    foreach ($indecsi as $nume => $i) {
        $fel = $i['tip'] ?? '';
        $randuri[] = '  ' . ($nume === 'PRIMARY' ? 'PRIMARY KEY' : (($fel === 'FULLTEXT' ? 'FULLTEXT ' : ($fel === 'SPATIAL' ? 'SPATIAL ' : ($i['unic'] ? 'UNIQUE ' : '')))
            . 'KEY ' . $id($nume))) . ' (' . $coloaneIndex($i['coloane']) . ')';
    }
    foreach ($t['fk'] as $nume => $f) {
        $randuri[] = '  CONSTRAINT ' . $id($nume) . ' FOREIGN KEY (' . implode(', ', array_map($id, $f['coloane'])) . ') REFERENCES '
            . $id($f['tabel']) . ' (' . implode(', ', array_map($id, $f['referite'])) . ') ON DELETE ' . $f['la_stergere'] . ' ON UPDATE ' . $f['la_actualizare'];
    }
    $setCaractere = $colatieTabel !== '' ? explode('_', $colatieTabel)[0] : 'utf8mb4';
    $sql .= 'CREATE TABLE ' . $id($tabel) . " (\n" . implode(",\n", $randuri) . "\n) ENGINE=" . ((string) ($t['motor'] ?? '') !== '' ? $t['motor'] : 'InnoDB')
        . ' DEFAULT CHARSET=' . $setCaractere . ($colatieTabel !== '' ? ' COLLATE=' . $colatieTabel : '') . ";\n\n";
}
$sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
file_put_contents($dosarIesire . '/' . $cod . '-live.sql', $sql);
$afisat = static fn (string $fisier): string => str_starts_with($dosarIesire, $radacina . '/') ? substr($dosarIesire, strlen($radacina) + 1) . '/' . $fisier : $fisier;
$scrise = [$afisat($cod . '-live.sql')];

$referinta = $fataDe ?? (is_file($radacina . '/database/migrari/asteptat.json') ? $radacina . '/database/migrari/asteptat.json' : null);
if ($referinta !== null) {
    $asteptat = json_decode((string) @file_get_contents($referinta), true);
    if (!is_array($asteptat) || !is_array($asteptat['tabele'] ?? null)) {
        $opreste('referința nu e o amprentă (--fata-de sau database/migrari/asteptat.json)');
    }
    $diferente = Amprenta::compara($asteptat, $amprenta);
    sort($diferente['in_plus']);
    file_put_contents($dosarIesire . '/extra-' . $cod . '.txt', '# Obiectele pe care site-ul ' . $cod . " le are în plus față de schema așteptată (din sonda live).\n"
        . "# Făcut de tools/dev/migrari/din-sonda.php; NU intră în git (OD-49).\n" . implode("\n", $diferente['in_plus']) . ($diferente['in_plus'] !== [] ? "\n" : ''));
    file_put_contents($dosarIesire . '/' . $cod . '-fata-de-asteptat.txt', '# Site-ul ' . $cod . ' față de ' . basename($referinta) . "\n\n## Lipsesc (le adaugă migrările)\n"
        . implode("\n", $diferente['lipsa']) . "\n\n## Altfel decât se așteaptă (avertismente)\n" . implode("\n", $diferente['avertismente']) . "\n");
    $scrise[] = $afisat('extra-' . $cod . '.txt');
    $scrise[] = $afisat($cod . '-fata-de-asteptat.txt');
    echo 'față de ' . basename($referinta) . ': lipsesc ' . count($diferente['lipsa']) . ', în plus ' . count($diferente['in_plus'])
        . ', avertismente ' . count($diferente['avertismente']) . "\n";
} else {
    echo "ATENȚIE: fără referință (nici --fata-de, nici database/migrari/asteptat.json): extra-{$cod}.txt nu s-a scris.\n";
}
$numarIndecsi = array_sum(array_map(static fn (array $t): int => count($t['indecsi']), $amprenta['tabele']));
$numarFk = array_sum(array_map(static fn (array $t): int => count($t['fk']), $amprenta['tabele']));
echo 'din-sonda ' . $cod . ': ' . count($amprenta['tabele']) . ' tabele, ' . array_sum(array_map(static fn (array $t): int => count($t['coloane']), $amprenta['tabele']))
    . ' coloane, ' . $numarIndecsi . ' indecși, ' . $numarFk . ' chei străine; scrise: ' . implode(', ', $scrise) . "\n";
