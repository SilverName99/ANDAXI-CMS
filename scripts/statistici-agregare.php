<?php

declare(strict_types=1);

/**
 * Agregarea statisticilor (pluginul `statistici`; App\Support\StatisticiAgregare): zilele neîncheiate, din evenimentele brute, în
 * vizite, pagini, produse, căutări și comenzile atribuite; apoi ștergerea evenimentelor mai vechi de `statistici_zile_brute` zile
 * și a sărurilor altor zile. O dată pe oră, prin bin/platforma (docs/PLUGINURI.md, „Statistici site”). Dacă nu rulează, pagina
 * din admin agregă la deschidere.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;
use App\Support\Settings;
use App\Support\StatisticiAgregare;

if (!\App\Support\Plugins::cronPermis(__FILE__)) {
    // Ieșire 0 când licența oprește pluginul; 2 când site-ul n-are licență (de văzut în monitorizare).
    [$mesajCron, $codCron] = \App\Support\Plugins::cronOprit(__FILE__);
    echo $mesajCron . "\n";
    exit($codCron);
}

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection($config['db']);
if (!$db instanceof PDO) {
    fwrite(STDERR, "Conexiunea la baza de date nu este disponibila.\n");
    exit(1);
}

try {
    $rez = StatisticiAgregare::ruleaza($db, Settings::all($db));
} catch (Throwable $e) {
    fwrite(STDERR, 'Agregarea statisticilor nu a mers: ' . $e->getMessage() . "\n");
    exit(1);
}
if ($rez['ocupat']) {
    echo "Statistici: o altă agregare rulează acum; nimic de făcut.\n";
    exit(0);
}
echo 'Statistici: zile agregate ' . count($rez['zile']) . ($rez['zile'] !== [] ? ' (' . $rez['zile'][0] . ' … ' . end($rez['zile']) . ')' : '')
    . ', evenimente ' . $rez['evenimente'] . ', vizite ' . $rez['vizite'] . ', evenimente vechi șterse ' . $rez['sterse'] . "\n";
