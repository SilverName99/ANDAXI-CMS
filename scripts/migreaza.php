<?php

declare(strict_types=1);

/**
 * Migrările bazei de date (docs/MIGRARI.md). Singurul loc din care se schimbă schema:
 * paginile nu trimit DDL (pe o bază la zi, funcțiile ensure* moștenite ies pe loc: garda
 * App\Support\Migrari\Garda). O bază nouă se face cu scripts/install.php, care rulează
 * aceleași migrări, apoi seed-ul neutru și primul administrator.
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

use App\Support\Migrari\Consola;
use App\Support\Migrari\Eroare;
use App\Support\Migrari\Registru;
use App\Support\Migrari\Runner;
use App\Support\Migrari\Schema;
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
        echo Consola::text($raport);
    }
    exit((int) $raport['cod']);
};
$opreste = static function (int $cod, string $mesaj, string $comanda) use ($termina): void {
    $termina(['comanda' => $comanda, 'cod' => $cod, 'mesaj' => $mesaj, 'migrari' => [], 'avertismente' => []]);
};

$phpMin = (string) ($config['php_min'] ?? '8.3');
if (version_compare(PHP_VERSION, $phpMin, '<')) {
    $opreste(Eroare::CONFIGURARE, 'PHP ' . PHP_VERSION . ' e mai vechi decât minimul codului (' . $phpMin . ', config/app.php php_min).', $comanda);
}

try {
    // Site-ul: --site, altfel SITE_SLUG din .env; cele două nu au voie să se contrazică.
    $optiuni['site'] = Consola::site($optiuni['site'] ?? null);
    // Conexiunea: aceleași setări ca Database::connection, cu eroarea spusă pe nume și un
    // lock_wait_timeout scurt (src/Support/Migrari/Consola.php).
    [$pdo, $server] = Consola::conecteaza((array) ($config['db'] ?? []), $asteptare);
} catch (Eroare $e) {
    $opreste($e->getCode(), $e->getMessage(), $comanda);
}
// Dosarul migrărilor, jurnalul, dosarul site-ului, versiunea codului și, doar pentru teste
// (tools/dev/test-migrari.php), opririle și pauzele (Consola::optiuniDinMediu).
$dosarMigrari = Consola::dosarMigrari($radacina);
$optiuni = Consola::optiuniDinMediu($radacina, $optiuni);

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
