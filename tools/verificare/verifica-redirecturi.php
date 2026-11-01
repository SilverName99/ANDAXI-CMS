<?php

declare(strict_types=1);

/**
 * Verificarea adreselor vechi ale unui site (WP-39, G-M7; PLAN-SEO §3 B, „Condiție fermă”; docs/SEO.md §2c): fiecare
 * adresă dintr-o listă (sitemap-ul vechi, paginile din Search Console pe 16 luni, linkurile externe) trebuie să dea, pe
 * site-ul nou, una dintre:
 *   - 200: o pagină HTML care se poate indexa (fără noindex în <meta name="robots"> sau X-Robots-Tag) și al cărei
 *     canonical e chiar ea;
 *   - 301 (sau 308) într-un singur salt, pe același site, spre o astfel de pagină 200;
 *   - 410, doar dacă adresa e una „dispărută” voit: o regulă 410 din redirecturi.json al pachetului (--pachet=<site>)
 *     sau o adresă din lista dată cu --intentionate=<fișier> (de exemplu exportul CSV al regulilor 410 din admin).
 * Orice altceva (404, 302, lanț de redirecționări, o țintă pe alt site, o pagină noindex sau cu canonicalul altei pagini)
 * e o problemă. Unealta scrie câte adrese au dat fiecare rezultat și căile publice care pică (fără date personale: sunt
 * adresele publice ale site-ului), cu motivul. Doar GET, fără cookie-uri, o cerere pe secundă (implicit); nu atinge
 * nicio bază și nu citește niciun .env.
 *
 *   php tools/verificare/verifica-redirecturi.php --lista=adrese-vechi.csv --baza=https://<site> [--ua=googlebot|browser] \
 *       [--pachet=bioscem] [--intentionate=reguli-410.csv] [--pauza=1] [--poarta=verificare]
 *
 * Lista: o adresă pe rând (întreagă, „https://site-vechi.ro/cale?x=1”, sau doar calea „/cale”), sau un CSV a cărui primă
 * coloană e adresa; rândurile care nu încep cu „/” sau „http” (un antet, un comentariu „#”) se sar. Din adresele întregi
 * se ia calea cu interogarea (lista vine de pe domeniul vechi; se cere de pe --baza). Parola porții unui site de
 * verificare doar în VERIFICARE_POARTA_PAROLA, doar pe https.
 *
 * Cod de ieșire: 0 nicio problemă; 1 cel puțin o problemă (sau site-ul nu răspunde); 2 refuzată (argumentele, lista
 * goală), fără nicio cerere. Rulează pe verificare cu pachetul site-ului înainte de trecere și pe domeniul live imediat
 * după (planul, WP-39).
 *
 * Fișierul folosește doar sintaxa PHP 7.0 (ca un PHP prea vechi să-și vadă versiunea); lib/redirecturi.php e PHP 8.3.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/lib/intrare.php';

$textConfig = (string) @file_get_contents(dirname(__DIR__, 2) . '/config/app.php');
$phpMin = preg_match("/'php_min'\\s*=>\\s*'([0-9.]+)'/", $textConfig, $m) === 1 ? $m[1] : '8.3';
if (version_compare(PHP_VERSION, $phpMin, '<')) {
    fwrite(STDERR, 'EROARE: PHP ' . PHP_VERSION . ' e mai vechi decât minimul codului (' . $phpMin . '). Rulează cu un PHP mai nou, '
        . "de exemplu /opt/alt/php83/usr/bin/php (versiunile: ls /opt/alt | grep php). Nu am cerut nimic.\n");
    exit(2);
}
require_once __DIR__ . '/lib/php-cli.php';
$lipsaExtensii = \Unelte\Verificare\PhpCli::lipsa(array('curl', 'dom'), 'tools/verificare/verifica-redirecturi.php');
if ($lipsaExtensii !== null) {
    fwrite(STDERR, 'EROARE: ' . $lipsaExtensii . ". Nu am cerut nimic.\n");
    exit(2);
}

require_once __DIR__ . '/lib/redirecturi.php';

exit(\Unelte\Verificare\VerificaRedirecturi::ruleaza($argv));
