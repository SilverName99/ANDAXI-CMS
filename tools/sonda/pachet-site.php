<?php

declare(strict_types=1);

/**
 * Verificările pachetului unui site pe baza lui (DB-24; planul de-Bioscem §7.5): V1–V12, doar numere, DA/NU și stări
 * (egal / diferit / lipsa / gol, calculate pe server), nicio valoare de setare, niciun HTML, niciun rând. Strict în
 * citire, cu garanțiile lui tools/verificare/lib/live.php. Rulează proprietarul, prin SSH, din dosarul site-ului de
 * verificare: întâi pe copia de verificare (datele Bioscem anonimizate), înainte de repetiția adoptării, și o dată pe
 * site-ul live, chiar înainte de adoptare (docs/VERIFICARE.md, „Verificările pe site-urile live”):
 *
 *   $PHP tools/sonda/pachet-site.php --site=bioscem
 *
 * Opțiunile: ca la tools/verificare/agregate.php (--dosar=<dosarul site-ului live>, --fara-fisier, --buget=<secunde>).
 * Pachetul comparat e site-uri/<site>/ al site-ului de verificare (clona codului; privat/ lipsește acolo, deci cheile
 * private ale înghețării apar doar lipsa / gol / nevid). Ce verifică fiecare V: capul lui tools/sonda/lib/pachet-site.php.
 * Rezultatul se scrie și în storage/verificare/sonde/ al site-ului de verificare (în afara git-ului, OD-49).
 *
 * Cod de ieșire: 0 = toate verificările au rulat; 1 = unele au căzut sau au fost sărite; 2 = refuzat (nimic citit).
 *
 * Fișierul (și tools/verificare/lib/intrare.php, încărcat primul) folosește doar sintaxa PHP 7.0, ca un PHP prea vechi
 * să-și vadă versiunea.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Erorile PHP: doar texte fixe, fără calea contului (capul lui lib/intrare.php).
require_once dirname(__DIR__) . '/verificare/lib/intrare.php';

$radacina = dirname(__DIR__, 2);
$textConfig = (string) @file_get_contents($radacina . '/config/app.php');
$phpMin = preg_match("/'php_min'\\s*=>\\s*'([0-9.]+)'/", $textConfig, $m) === 1 ? $m[1] : '8.3';
if (version_compare(PHP_VERSION, $phpMin, '<')) {
    fwrite(STDERR, 'EROARE: PHP ' . PHP_VERSION . ' e mai vechi decât minimul codului (' . $phpMin . '). Rulează cu un PHP mai nou, '
        . "de exemplu /opt/alt/php83/usr/bin/php (versiunile: ls /opt/alt | grep php).\n");
    exit(1);
}
require_once dirname(__DIR__) . '/verificare/lib/php-cli.php';
$lipsaExtensii = \Unelte\Verificare\PhpCli::lipsa(array('pdo_mysql'), 'tools/sonda/pachet-site.php');
if ($lipsaExtensii !== null) {
    fwrite(STDERR, 'EROARE: ' . $lipsaExtensii . ".\n");
    exit(1);
}

require_once dirname(__DIR__) . '/verificare/lib/agregate.php';

exit(\Unelte\Verificare\Agregate::ruleaza($argv, $radacina, require __DIR__ . '/lib/pachet-site.php',
    'verificările pachetului site-ului pe baza lui (DB-24, V1–V12)', 'pachet-site'));
