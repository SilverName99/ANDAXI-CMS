<?php

declare(strict_types=1);

/**
 * Încărcarea copiei pe site-ul de verificare, pe server, prin SSH, din dosarul site-ului de verificare
 * (docs/VERIFICARE.md, „Încărcarea copiei”; WP-09):
 *
 *   $PHP tools/verificare/incarca.php --din=bioscem                 # copia anonimizată a Bioscem (noaptea)
 *   $PHP tools/verificare/incarca.php --din=bioscem --reincarca     # o face din nou peste copia de dinainte
 *   $PHP tools/verificare/incarca.php --din=bioscem --reia          # continuă o încărcare oprită (de la grupul la care a rămas)
 *   $PHP tools/verificare/incarca.php --demo --pachet=<site> [--licenta=<pachet>]   # copia demonstrativă, fără date personale
 *
 * Opțiuni (--din): --dosar=<dosarul site-ului live> (implicit îl caută după domeniu, ca agregate.php), --fara-cod-vechi
 * (fără copia codului vechi în ~/domains/<verificare>/cod-vechi/), --fara-bt-test (fără datele de test ale băncii, decizia
 * D3), --buget=<secunde> (după el nu mai începe niciun grup; continui cu --reia), --licenta=<pachet> (implicit al site-ului),
 * --da (fără întrebarea „da”; adresa și parola contului de admin se cer oricum).
 *
 * Ce face, pe scurt (capul lui tools/verificare/lib/incarcare.php): `_verificare` scris primul (anonimizat = 0), sursa citită
 * strict în citire (o sesiune READ ONLY, un instantaneu consecvent pe grup, fără buffer), un scaner care oprește totul la o
 * coloană fără regulă, structura, datele cu pseudonimele făcute în drum (tools/verificare/reguli/<site>.php), setările
 * neutralizate, contul tău de admin, codul vechi în afara public_html, migrările (`migreaza --aplica --site=<site>`),
 * verificările A1–A9 (o verificare care pică șterge copia singură), licența, apoi anonimizat = 1 și incarcat-la.
 *
 * Pe ecran: doar DA/NU, numere, nume de tabele și de coloane, durate. Niciodată o valoare dintr-un rând, o parolă, numele
 * bazei sau o adresă întreagă. Textul se salvează și în storage/verificare/sonde/ (ca rezultatele verificărilor).
 *
 * Cod de ieșire: 0 GATA; 1 oprită, copia ștearsă singură; 2 refuzată, nimic atins; 3 oprită (bugetul, legătura pierdută, o
 * eroare fatală PHP), copia neterminată rămâne închisă: --reia continuă, sterge.php o șterge.
 *
 * Fișierul (și lib/intrare.php, lib/intrebari.php) folosește doar sintaxa PHP 7.0, ca un PHP prea vechi să-și vadă versiunea.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Erorile PHP: doar texte fixe, fără calea contului (capul lui lib/intrare.php).
require_once __DIR__ . '/lib/intrare.php';

$radacina = dirname(__DIR__, 2);

/** Oprirea la o întrebare (lib/intrebari.php): înainte de orice schimbare. */
function verificare_opreste($mesaj)
{
    fwrite(STDERR, 'REFUZAT: ' . $mesaj . "\n");
    exit(2);
}

$textConfig = (string) @file_get_contents($radacina . '/config/app.php');
$phpMin = preg_match("/'php_min'\\s*=>\\s*'([0-9.]+)'/", $textConfig, $m) === 1 ? $m[1] : '8.3';
if (version_compare(PHP_VERSION, $phpMin, '<')) {
    fwrite(STDERR, 'EROARE: PHP ' . PHP_VERSION . ' e mai vechi decât minimul codului (' . $phpMin . '). Rulează cu un PHP mai nou, '
        . "de exemplu /opt/alt/php83/usr/bin/php (versiunile: ls /opt/alt | grep php). Nu am atins nimic.\n");
    exit(1);
}
foreach (array('pdo_mysql', 'sodium') as $extensie) {
    if (!extension_loaded($extensie)) {
        fwrite(STDERR, 'EROARE: lipsește extensia PHP ' . $extensie . " (hPanel → PHP Configuration → PHP extensions). Nu am atins nimic.\n");
        exit(1);
    }
}

require_once __DIR__ . '/lib/intrebari.php';
require_once __DIR__ . '/lib/incarcare.php';

exit(\Unelte\Verificare\Incarcare::ruleaza($argv, $radacina));
