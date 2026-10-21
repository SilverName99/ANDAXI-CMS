<?php

declare(strict_types=1);

/**
 * Amprenta paginilor unui site, înainte și după (WP-11, G3; docs/VERIFICARE.md, „Amprenta paginilor”): ce vede din afară
 * un vizitator (și Google) pe fiecare adresă publică, ca două site-uri, sau același site înainte și după o schimbare, să
 * se poată compara. Doar GET, câte o cerere pe secundă, fără cookie-uri; nu atinge nicio bază și nu citește niciun .env.
 * Rulează de pe server sau de pe orice calculator.
 *
 *   php tools/verificare/amprenta-site.php --baza=https://bioscem.ro --iesire=amprenta-live.tsv
 *   read -rs VERIFICARE_POARTA_PAROLA; export VERIFICARE_POARTA_PAROLA        # parola porții, fără să apară pe ecran
 *   php tools/verificare/amprenta-site.php --baza=https://<domeniul-verificare> --poarta=verificare --iesire=amprenta-verificare.tsv
 *   php tools/verificare/amprenta-site.php --compara amprenta-live.tsv amprenta-verificare.tsv            # câmpurile structurale
 *   php tools/verificare/amprenta-site.php --compara inainte.tsv dupa.tsv --strict                         # și corpul paginilor
 *
 * Opțiuni: --max=<n> (implicit 3000 de adrese), --pauza=<secunde> (implicit 1; cel puțin 1 pe un site care nu e pe
 * calculatorul tău). Parola porții doar în VERIFICARE_POARTA_PAROLA, niciodată în linia de comandă, și doar pe https.
 *
 * Trei folosiri (planul de verificare, §2.4): (1) Etapa 1: bioscem.ro (codul vechi) față de site-ul de verificare (codul
 * comun pe copia de azi-noapte), doar câmpurile structurale; (2) repetiția din Etapa 4: pe site-ul de verificare, codul
 * vechi față de codul comun, pe aceeași bază, cu --strict; (3) noaptea adoptării: site-ul live înainte și după.
 *
 * Ce cere și ce scrie: capul lui lib/amprenta.php. Cod de ieșire: 0 amprenta scrisă (cu --compara: „LA FEL”); 1 oprită
 * (site-ul nu răspunde, parola porții greșită; cu --compara: „DIFERĂ”); 2 refuzată (argumentele, un fișier care nu e o
 * amprentă), fără nicio cerere.
 *
 * Fișierul folosește doar sintaxa PHP 7.0 (ca un PHP prea vechi să-și vadă versiunea); lib/amprenta.php e PHP 8.3.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Erorile PHP: doar texte fixe, fără calea contului (capul lui lib/intrare.php).
require_once __DIR__ . '/lib/intrare.php';

$textConfig = (string) @file_get_contents(dirname(__DIR__, 2) . '/config/app.php');
$phpMin = preg_match("/'php_min'\\s*=>\\s*'([0-9.]+)'/", $textConfig, $m) === 1 ? $m[1] : '8.3';
if (version_compare(PHP_VERSION, $phpMin, '<')) {
    fwrite(STDERR, 'EROARE: PHP ' . PHP_VERSION . ' e mai vechi decât minimul codului (' . $phpMin . '). Rulează cu un PHP mai nou, '
        . "de exemplu /opt/alt/php83/usr/bin/php (versiunile: ls /opt/alt | grep php). Nu am cerut nimic.\n");
    exit(1);
}
foreach (array('curl', 'dom') as $extensie) {
    if (!extension_loaded($extensie)) {
        fwrite(STDERR, 'EROARE: lipsește extensia PHP ' . $extensie . ". Nu am cerut nimic.\n");
        exit(1);
    }
}

require_once __DIR__ . '/lib/amprenta.php';

exit(\Unelte\Verificare\AmprentaSite::ruleaza($argv));
