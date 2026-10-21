<?php

declare(strict_types=1);

/**
 * Verificările agregate pe un site live (WP-10, G4): doar numere, DA/NU și nume de
 * structură, citite strict în citire din baza site-ului (docs/VERIFICARE.md, „Verificările
 * pe site-urile live”). Rulează proprietarul, noaptea, prin SSH, din dosarul site-ului de
 * verificare:
 *
 *   $PHP tools/verificare/agregate.php --set=bioscem
 *   $PHP tools/verificare/agregate.php --set=grafoanaytis
 *   $PHP tools/verificare/agregate.php --set=biovitality
 *   $PHP tools/verificare/agregate.php --set=vertij
 *
 * Opțiuni: --dosar=<dosarul site-ului live> (implicit îl caută după domeniul setului în
 * ~/domains/<domeniu>/public_html și ~/public_html), --fara-fisier (doar pe ecran),
 * --buget=<secunde> (implicit 300; după el nu mai pornește nicio verificare).
 *
 * Seturile: tools/verificare/seturi/<set>.php (fiecare cu verificările comune din
 * seturi/comun.php). Ce garantează citirea (sesiune doar de citire, doar SELECT/SHOW cu
 * LIMIT, nimic scris pe site-ul live, nicio valoare din rânduri pe ecran): capul lui
 * tools/verificare/lib/live.php. Rezultatul se scrie și în storage/verificare/sonde/ al
 * site-ului de verificare (ignorat de git, de neatins din browser); de acolo îl trimite
 * proprietarul. Cu numere scoase din datele live, nu intră niciodată în git (OD-49).
 *
 * Cod de ieșire: 0 = toate verificările au rulat; 1 = unele au căzut sau au fost sărite, sau
 * o eroare PHP fatală (memoria, timpul) a oprit rularea („OPRIT”; verificările de până acolo
 * sunt scrise); restul rezultatului e bun; 2 = refuzat (nimic citit din bază).
 *
 * Fișierul (și lib/intrare.php, încărcat primul) folosește doar sintaxa PHP 7.0, ca un PHP
 * prea vechi să-și vadă versiunea.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Erorile PHP: doar texte fixe, fără calea contului (capul lui lib/intrare.php).
require_once __DIR__ . '/lib/intrare.php';

$radacina = dirname(__DIR__, 2);
$textConfig = (string) @file_get_contents($radacina . '/config/app.php');
$phpMin = preg_match("/'php_min'\\s*=>\\s*'([0-9.]+)'/", $textConfig, $m) === 1 ? $m[1] : '8.3';
if (version_compare(PHP_VERSION, $phpMin, '<')) {
    fwrite(STDERR, 'EROARE: PHP ' . PHP_VERSION . ' e mai vechi decât minimul codului (' . $phpMin . '). Rulează cu un PHP mai nou, '
        . "de exemplu /opt/alt/php83/usr/bin/php (versiunile: ls /opt/alt | grep php).\n");
    exit(1);
}
require_once __DIR__ . '/lib/php-cli.php';
$lipsaExtensii = \Unelte\Verificare\PhpCli::lipsa(array('pdo_mysql'), 'tools/verificare/agregate.php');
if ($lipsaExtensii !== null) {
    fwrite(STDERR, 'EROARE: ' . $lipsaExtensii . ".\n");
    exit(1);
}

require_once __DIR__ . '/lib/agregate.php';

exit(\Unelte\Verificare\Agregate::ruleaza($argv, $radacina));
