<?php

declare(strict_types=1);

/**
 * Migrările bazei de date (docs/MIGRARI.md). Singurul loc din care se schimbă schema:
 * paginile nu trimit DDL (WP-05 scoate și ce mai trimit azi funcțiile ensure*).
 *
 *   php scripts/migreaza.php --plan        ce s-ar face pe această bază (nu schimbă nimic)
 *   php scripts/migreaza.php --aplica      aplică ce lipsește, apoi verifică baza
 *   php scripts/migreaza.php --verifica    baza față de database/migrari/asteptat.json
 *   php scripts/migreaza.php --stare       ce s-a aplicat, ce lipsește, ce e în curs
 *   php scripts/migreaza.php --amprenta    amprenta schemei acestei baze, JSON (doar structură)
 *
 * Opțiuni: --json (ieșire pentru mașini: actualizeaza.sh, CRM-ul); --permite-blocante
 * (migrările care pot opri scrierile într-un tabel; doar cu site-ul în MENTENANTA);
 * --permite-contract (ștergeri, la un release după ce niciun cod nu mai folosește obiectul);
 * --buget=300 (secunde; după ele nu mai începe nicio operație: codul 13, se rulează din nou);
 * --asteptare-lacat=5 (secunde de așteptat după un tabel ocupat, apoi se reîncearcă);
 * --pana-la=<id> (se oprește după migrarea dată); --site=<site> (implicit SITE_SLUG din
 * .env; rulează și pașii acelui site).
 *
 * Codurile de ieșire: 0 nimic de făcut / totul aplicat și verificat; 1 eroare într-o migrare;
 * 2 configurare, conexiune sau server nesuportat; 3 alt runner (sau sincronizarea FAN) ține
 * lacătul; 4 baza nu e a unui site ANDAXI; 10 (--plan) migrări de aplicat; 11 migrări blocante
 * sau de tip contract fără --permite-…; 12 o migrare aplicată s-a schimbat sau a dispărut;
 * 13 bugetul de timp s-a terminat; 14 verificarea a găsit un obiect lipsă; 15 lipsește un
 * parametru al site-ului.
 *
 * Nu afișează niciodată parole, DSN-uri sau valori din rânduri: doar nume de obiecte și numere.
 * Rulează fără sesiune PHP (bootstrap.php, în linia de comandă).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

use App\Support\DateSite;
use App\Support\Migrari\Eroare;
use App\Support\Migrari\Registru;
use App\Support\Migrari\Runner;
use App\Support\Migrari\Schema;
use App\Support\Migrari\Server;
use App\Support\Plugins;

$radacina = dirname(__DIR__);
$config = require $radacina . '/config/app.php';

$ajutor = <<<TEXT
Folosire: php scripts/migreaza.php --plan|--aplica|--verifica|--stare|--amprenta [opțiuni]
  --json  --permite-blocante  --permite-contract  --buget=300  --asteptare-lacat=5
  --pana-la=<id>  --site=<site>
Pe larg: docs/MIGRARI.md
TEXT;

$comanda = null;
$json = false;
$optiuni = [];
$asteptare = 5;
foreach (array_slice($argv, 1) as $argument) {
    if (in_array($argument, ['--plan', '--aplica', '--verifica', '--stare', '--amprenta'], true)) {
        if ($comanda !== null) {
            fwrite(STDERR, "O singură comandă odată.\n" . $ajutor . "\n");
            exit(2);
        }
        $comanda = substr($argument, 2);
    } elseif ($argument === '--json') {
        $json = true;
    } elseif ($argument === '--permite-blocante') {
        $optiuni['permite_blocante'] = true;
    } elseif ($argument === '--permite-contract') {
        $optiuni['permite_contract'] = true;
    } elseif (preg_match('/^--buget=(\d+(\.\d+)?)$/', $argument, $m) === 1) {
        $optiuni['buget'] = (float) $m[1];
    } elseif (preg_match('/^--asteptare-lacat=(\d+)$/', $argument, $m) === 1 && (int) $m[1] >= 1) {
        $asteptare = (int) $m[1];
    } elseif (preg_match('#^--pana-la=([a-z0-9_]+(/[a-z0-9_-]+)+)$#', $argument, $m) === 1) {
        $optiuni['pana_la'] = $m[1];
    } elseif (preg_match('/^--site=([a-z0-9][a-z0-9-]*)$/', $argument, $m) === 1) {
        $optiuni['site'] = $m[1];
    } elseif ($argument === '--ajutor' || $argument === '-h' || $argument === '--help') {
        echo $ajutor, "\n";
        exit(0);
    } else {
        fwrite(STDERR, 'Argument necunoscut sau greșit: ' . $argument . "\n" . $ajutor . "\n");
        exit(2);
    }
}
if ($comanda === null) {
    fwrite(STDERR, $ajutor . "\n");
    exit(2);
}

/** Ieșirea: textul pentru oameni (sau JSON-ul) și codul. */
$termina = static function (array $raport) use ($json): void {
    if ($json) {
        echo json_encode($raport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    } else {
        echo migreaza_text($raport);
    }
    exit((int) $raport['cod']);
};
$opreste = static function (int $cod, string $mesaj, string $comanda) use ($termina): void {
    $termina(['comanda' => $comanda, 'cod' => $cod, 'mesaj' => $mesaj, 'migrari' => [], 'avertismente' => []]);
};

$phpMin = (string) ($config['php_min'] ?? '8.0');
if (version_compare(PHP_VERSION, $phpMin, '<')) {
    $opreste(Eroare::CONFIGURARE, 'PHP ' . PHP_VERSION . ' e mai vechi decât minimul codului (' . $phpMin . ', config/app.php php_min).', $comanda);
}

// Site-ul: --site, altfel SITE_SLUG din .env; cele două nu au voie să se contrazică.
$siteEnv = trim((string) (getenv('SITE_SLUG') ?: ''));
if (isset($optiuni['site']) && $siteEnv !== '' && $siteEnv !== $optiuni['site']) {
    $opreste(Eroare::CONFIGURARE, '--site=' . $optiuni['site'] . ', dar SITE_SLUG din .env este „' . $siteEnv . '”; refuz să rulez pașii altui site.', $comanda);
}
if (!isset($optiuni['site']) && $siteEnv !== '') {
    if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $siteEnv) !== 1) {
        $opreste(Eroare::CONFIGURARE, 'SITE_SLUG din .env nu e un nume de site valid (litere mici, cifre, -).', $comanda);
    }
    $optiuni['site'] = $siteEnv;
}

// Conexiunea: aceleași setări ca Database::connection (utf8mb4, excepții, fusul orar al aplicației),
// dar cu eroarea spusă pe nume (fără DSN, utilizator sau parolă).
$db = (array) ($config['db'] ?? []);
if (trim((string) ($db['database'] ?? '')) === '') {
    $opreste(Eroare::CONFIGURARE, 'Baza de date nu e configurată (DB_DATABASE lipsește din .env).', $comanda);
}
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', (string) $db['host'], (int) $db['port'], (string) $db['database']),
        (string) $db['username'],
        (string) $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    $cod = (int) ($e->errorInfo[1] ?? 0);
    if ($cod === 0 && preg_match('/\[(\d{4})\]/', $e->getMessage(), $m) === 1) {
        $cod = (int) $m[1];
    }
    $motiv = match ($cod) {
        1045, 1698 => 'acces refuzat: utilizatorul sau parola din .env',
        1044 => 'utilizatorul nu are drept pe această bază',
        1049 => 'baza din DB_DATABASE nu există',
        2002, 2003, 2005, 2006 => 'serverul nu răspunde la adresa din DB_HOST/DB_PORT (sau la socket)',
        default => 'eroarea ' . ($cod ?: '?'),
    };
    $opreste(Eroare::CONFIGURARE, 'Nu mă pot conecta la baza de date (' . $motiv . ').', $comanda);
}
try {
    $pdo->exec("SET time_zone = '" . date('P') . "'");
} catch (PDOException) {
    // La fel ca aplicația: rămâne fusul serverului.
}
$server = Server::detecteaza($pdo);
if (!$server->suportat()) {
    $opreste(Eroare::CONFIGURARE, 'Serverul ' . $server->nume() . ' e prea vechi: minimul e MariaDB 10.3 sau MySQL 8.0 (docs/MIGRARI.md).', $comanda);
}
// Un ALTER care așteaptă un tabel ocupat (de exemplu un export lung) nu are voie să țină coada
// cererilor site-ului în spatele lui: renunță repede și reîncearcă (implicit pe server: o zi).
$pdo->exec('SET SESSION lock_wait_timeout = ' . $asteptare . ', SESSION innodb_lock_wait_timeout = ' . $asteptare);

// Doar pentru teste (tools/dev/test-migrari.php): alt dosar de migrări, oprirea „ca un proces
// omorât” după k operații sau la a k-a trecere printr-un punct din Schema (în tranzacția unei
// bucăți, chiar după commit-ul ei), pauze scurte. Pe site-uri variabilele nu există.
$dosarMigrari = getenv('MIGRARI_TEST_DOSAR') ?: $radacina . '/database/migrari';
if (($k = getenv('MIGRARI_TEST_OPRESTE_DUPA')) !== false && ctype_digit($k) && (int) $k > 0) {
    $optiuni['opreste_dupa'] = (int) $k;
}
if (($punct = getenv('MIGRARI_TEST_OPRESTE_IN')) !== false && preg_match('/^[a-z_]+:[1-9]\d*$/', $punct) === 1) {
    $optiuni['opreste_in'] = $punct;
}
if (($pauze = getenv('MIGRARI_TEST_PAUZE')) !== false && preg_match('/^\d+(\.\d+)?(,\d+(\.\d+)?)*$/', $pauze) === 1) {
    $optiuni['pauze'] = array_map('floatval', explode(',', $pauze));
}
if (($pauza = getenv('MIGRARI_TEST_PAUZA_BUCATI_MS')) !== false && ctype_digit($pauza)) {
    $optiuni['pauza_bucati_ms'] = (int) $pauza;
}
$optiuni['jurnal'] = getenv('MIGRARI_TEST_JURNAL') ?: $radacina . '/storage/logs/migrari.log';
$optiuni['dosar_site'] = DateSite::dosar();
$versiune = is_file($radacina . '/VERSION') ? trim((string) file_get_contents($radacina . '/VERSION')) : '';
$optiuni['cod_versiune'] = $versiune !== '' ? substr($versiune, 0, 40) : null;

$registru = new Registru($dosarMigrari, array_merge(['nucleu'], Plugins::chei()));
$runner = new Runner($pdo, $server, $registru, $optiuni);

try {
    if ($comanda === 'amprenta') {
        echo $runner->amprenta();
        exit(0);
    }
    $raport = match ($comanda) {
        'plan' => $runner->plan(),
        'aplica' => $runner->aplica(),
        'verifica' => $runner->verifica(),
        'stare' => $runner->stare(),
    };
} catch (Eroare $e) {
    $opreste($e->getCode(), $e->getMessage(), $comanda);
} catch (Throwable $e) {
    $opreste(Eroare::MIGRARE, 'eroare neașteptată: ' . Schema::faraValori($e->getMessage()), $comanda);
}
$termina($raport);

/** Raportul, pentru oameni. Ultimul rând e mesajul (îl arată porti.sh și jurnalele cronului). */
function migreaza_text(array $r): string
{
    $iesire = [];
    $titluri = ['plan' => 'plan (nu schimb nimic)', 'aplica' => 'aplicare', 'verifica' => 'verificare', 'stare' => 'stare'];
    $cap = 'Migrări — ' . ($titluri[$r['comanda']] ?? $r['comanda']);
    if (isset($r['server'])) {
        $cap .= ' · ' . ($r['server']['familie'] === 'mariadb' ? 'MariaDB ' : 'MySQL ') . $r['server']['versiune'] . ' · baza ' . $r['baza']
            . ($r['site'] !== null ? ' · site ' . $r['site'] : '');
    }
    $iesire[] = $cap;
    if (isset($r['registru'])) {
        $iesire[] = 'În cod: ' . $r['registru']['migrari'] . ' migrări (ultima ' . sprintf('%04d', $r['registru']['ultima_ord']) . ')'
            . ($r['site'] !== null ? ', ' . $r['registru']['pasi_site'] . ' pași ai site-ului' : '');
    }
    if ($r['comanda'] === 'aplica' && isset($r['sql_mode'])) {
        $iesire[] = 'sql_mode: ' . ($r['sql_mode'] !== '' ? $r['sql_mode'] : '(gol)') . ' (al serverului, neschimbat)';
    }
    foreach ($r['migrari'] ?? [] as $m) {
        if ($r['comanda'] === 'aplica' && isset($m['operatii_acum'])) {
            $iesire[] = sprintf('  %-10s %s  (%d operații, %d existau deja, %d ms)', $m['stare'], $m['id'], $m['operatii_acum'], $m['fara_efect'], $m['durata_ms']);
            continue;
        }
        $iesire[] = '  ' . $m['id'] . '  ' . $m['tip'] . ' · ' . ($m['stare'] === 'noua' ? 'nouă' : $m['stare'])
            . ($m['blocanta_efectiv'] ?? false ? ' · BLOCANTĂ' : '') . ($m['tip'] === 'contract' ? ' · CONTRACT' : '')
            . ' — ' . $m['descriere'];
        $faraEfect = 0;
        foreach ($m['operatii'] ?? [] as $o) {
            if ($o['clasa'] === 'fara_efect') {
                $faraEfect++;
                continue;
            }
            $iesire[] = sprintf('      %-9s %s %s%s', $o['clasa'], $o['fel'], $o['obiect'], $o['detaliu'] !== '' ? ' (' . $o['detaliu'] . ')' : '');
        }
        if ($faraEfect > 0) {
            $iesire[] = '      (' . $faraEfect . ' există deja)';
        }
    }
    if ($r['comanda'] === 'stare' && isset($r['ultima_ord'])) {
        $iesire[] = 'ultima_ord: ' . $r['ultima_ord'] . ($r['ultima_migrare'] !== null ? ' (ultima terminată: ' . $r['ultima_migrare'] . ')' : '');
        $iesire[] = 'sql_mode: ' . $r['sql_mode'] . ' · lock_wait_timeout: ' . $r['lock_wait_timeout'] . ' s';
        $iesire[] = 'de aplicat: ' . ($r['de_aplicat'] === [] ? 'nimic' : implode(', ', $r['de_aplicat']));
        if ($r['blocante_de_aplicat'] !== []) {
            $iesire[] = 'blocante: ' . implode(', ', $r['blocante_de_aplicat']);
        }
        if ($r['in_curs'] !== []) {
            $iesire[] = 'întrerupte: ' . implode(', ', $r['in_curs']);
        }
        $iesire[] = 'runner activ acum: ' . ($r['runner_activ'] ? 'da' : 'nu');
        if (isset($r['stare']['ultima_rulare'])) {
            $iesire[] = 'ultima rulare: ' . $r['stare']['ultima_rulare'];
        }
    }
    if (isset($r['verificare'])) {
        foreach ($r['verificare']['lipsa'] as $obiect) {
            $iesire[] = '  LIPSĂ    ' . $obiect;
        }
        foreach ($r['verificare']['avertismente'] as $text) {
            $iesire[] = '  ATENȚIE  ' . $text;
        }
        foreach (array_slice($r['verificare']['in_plus'], 0, 30) as $obiect) {
            $iesire[] = '  în plus  ' . $obiect;
        }
        if (count($r['verificare']['in_plus']) > 30) {
            $iesire[] = '  în plus  … încă ' . (count($r['verificare']['in_plus']) - 30);
        }
    }
    foreach ($r['avertismente'] ?? [] as $text) {
        $iesire[] = 'ATENȚIE: ' . $text;
    }
    $iesire[] = ($r['cod'] !== 0 ? 'Cod ' . $r['cod'] . ': ' : '') . $r['mesaj'];
    return implode("\n", $iesire) . "\n";
}
