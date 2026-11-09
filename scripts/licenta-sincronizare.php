<?php

declare(strict_types=1);

/**
 * Preluarea automată a licenței de la CRM (App\Support\LicentaSincronizare; docs/PLUGINURI.md, „Preluarea automată”). Al
 * nucleului: rulează oricare ar fi licența (și fără niciuna), deci n-are garda pluginurilor. La 5 minute, prin bin/platforma
 * (rândul de cron e mai jos, ca un comentariu de o linie). Nu are nevoie de bază.
 *
 * Ieșire: 0 licență nouă pusă sau la zi (și „ocupat”: o altă preluare rulează acum), 1 un răspuns respins sau CRM-ul care nu
 * răspunde (licența de acum rămâne), 2 preluarea neconfigurată (LICENTA_URL, LICENTA_CHEIE).
 */

// Cronul (hPanel → Advanced → Cron Jobs; docs/DEPLOY.md, „Cronurile”):
//   */5 * * * *  /opt/alt/php83/usr/bin/php -d extension=sodium.so ~/platforma/bin/platforma --site=<dosarul site-ului> scripts/licenta-sincronizare.php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

use App\Support\LicentaSincronizare;

$rezultat = LicentaSincronizare::ruleaza('cron');
$linie = 'Licența: ' . $rezultat['rezultat'] . ': ' . $rezultat['mesaj'];
if (in_array($rezultat['rezultat'], [LicentaSincronizare::OK, LicentaSincronizare::LA_ZI, LicentaSincronizare::OCUPAT], true)) {
    echo $linie, "\n";
    exit(0);
}
fwrite(STDERR, $linie . "\n");
exit($rezultat['rezultat'] === LicentaSincronizare::NECONFIGURAT ? 2 : 1);
