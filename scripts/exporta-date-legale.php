<?php

declare(strict_types=1);

/**
 * Exportul datelor cu păstrare legală, la cererea clientului (WP-26; decizia OD-51 = b; App\Support\ExportDateLegale).
 * Merge oricare ar fi starea pluginului (citește tabelele direct), deci și pentru un plugin oprit, ale cărui date nu se
 * mai văd în admin. Îl rulează doar ANDAXI, pe server, din linia de comandă:
 *
 *   php scripts/exporta-date-legale.php --ce=comenzi
 *   php scripts/exporta-date-legale.php --ce=acorduri_gdpr --din=2025-01-01
 *   php scripts/exporta-date-legale.php --ce=donatii
 *
 * Scrie un CSV (UTF-8, virgulă) în storage/export/ (drepturi 0600) și afișează doar numărul de rânduri și linkul
 * `<APP_URL>/_andaxi/export/<token>`, bun o singură dată, 24 de ore; fișierul se șterge după descărcare sau după
 * expirare. Nu afișează nimic din date. Coduri: 0 = gata, 1 = n-a mers, 2 = argumente greșite.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

use App\Support\AppUrl;
use App\Support\Database;
use App\Support\ExportDateLegale;

$ce = null;
$din = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--ce=')) {
        $ce = substr($argument, 5);
    } elseif (str_starts_with($argument, '--din=')) {
        $din = substr($argument, 6);
    } else {
        fwrite(STDERR, 'argument necunoscut: ' . $argument . " (vezi începutul fișierului)\n");
        exit(2);
    }
}
if ($ce === null || !isset(ExportDateLegale::TIPURI[$ce])) {
    fwrite(STDERR, '--ce=' . implode('|', array_keys(ExportDateLegale::TIPURI)) . " lipsește sau e greșit\n");
    exit(2);
}

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection((array) ($config['db'] ?? []));
if (!$db instanceof PDO) {
    fwrite(STDERR, "Conexiunea la baza de date nu este disponibilă.\n");
    exit(1);
}

try {
    $rezultat = ExportDateLegale::creeaza($db, $ce, $din);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Exportul nu s-a făcut: ' . $e->getMessage() . "\n");
    exit(str_contains($e->getMessage(), '--') ? 2 : 1);
}

echo 'rânduri  : ' . $rezultat['randuri'] . "\n";
echo 'link     : ' . rtrim(AppUrl::base(), '/') . '/_andaxi/export/' . $rezultat['token'] . "\n";
echo 'valabil  : până la ' . date('Y-m-d H:i', $rezultat['expira']) . ", o singură descărcare\n";
exit(0);
