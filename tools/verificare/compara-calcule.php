<?php

declare(strict_types=1);

/**
 * Comparația calculelor pe site-ul de verificare (golden master, WP-11; docs/VERIFICARE.md, „Comparația calculelor”):
 * codul vechi al site-ului, copiat de încărcare în ~/domains/<verificare>/cod-vechi/<site>/, și codul comun calculează,
 * pe aceeași copie anonimizată și pentru aceleași comenzi, sumarul coșului (subtotal, TVA, reducere, puncte, transport,
 * total și fiecare linie), recalculul transportului din admin, punctele promise și ce pleacă în ERP. Pe server, prin SSH,
 * din dosarul site-ului de verificare, după încărcare (pasul 6):
 *
 *   $PHP tools/verificare/compara-calcule.php                                  # profilul bioscem, ultimele 500 de comenzi
 *   $PHP tools/verificare/compara-calcule.php --n=2000                         # mai multe comenzi
 *   $PHP tools/verificare/compara-calcule.php --php-vechi=/opt/alt/php81/usr/bin/php   # codul vechi pe PHP-ul lui live
 *   $PHP tools/verificare/compara-calcule.php --fara-cupoane-valide            # cupoanele rămân cum sunt pe copie
 *   tools/dev/php.sh tools/verificare/compara-calcule.php --local --cod-vechi=<dosar>  # doar local (tools/dev/compara-calcule-local.sh)
 *
 * Alte opțiuni: --profil=bioscem (singurul azi; Biovitality vine în WP-11b), --prin-fisiere (lucrătorii primesc și dau
 * datele prin fișiere 0600 din storage/verificare/tmp, șterse la final, ca atunci când găzduirea oprește proc_open),
 * --cod-nou=<dosar> și --declarate=<fișier.json> (diferențele voite ale codului comun, pe comenzile demo; doar cu --local).
 *
 * Pe ecran doar numere și nume de câmpuri (lib/calcule.php: ce garantează, ce se compară, codurile de ieșire): niciun
 * id, număr de comandă, nume, sumă sau produs. Ultimul rând: „REZULTAT: TRECE” (cod 0) sau „REZULTAT: PICĂ” (cod 1);
 * „REFUZAT: …” (cod 2) nu a schimbat nimic.
 *
 * Fișierul folosește doar sintaxa PHP 7.0 (ca un PHP prea vechi să-și vadă versiunea); lib/calcule.php e PHP 8.3.
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
        . "de exemplu /opt/alt/php83/usr/bin/php (versiunile: ls /opt/alt | grep php). Nu am atins nimic.\n");
    exit(1);
}
// O extensie lipsă cu modulul pe server primește reparația exactă (-d extension=…); procesele copil primesc singure aceleași -d.
require_once __DIR__ . '/lib/php-cli.php';
$lipsaExtensii = \Unelte\Verificare\PhpCli::lipsa(array('pdo_mysql'), 'tools/verificare/compara-calcule.php');
if ($lipsaExtensii !== null) {
    fwrite(STDERR, 'EROARE: ' . $lipsaExtensii . ". Nu am atins nimic.\n");
    exit(1);
}

require_once __DIR__ . '/lib/live.php';
require_once __DIR__ . '/lib/cont.php';
require_once __DIR__ . '/lib/calcule.php';

exit(\Unelte\Verificare\ComparaCalcule::ruleaza($argv, $radacina));
