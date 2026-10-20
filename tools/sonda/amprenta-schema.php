<?php

declare(strict_types=1);

/**
 * Sonda schemei unui site live (WP-10; MIG-08 din planul Etapei 1): structura bazei (tabele,
 * coloane, indecși, chei străine, cu motorul, colația și mărimea), valorile serverului care
 * contează pentru migrări (versiunea, sql_mode, timpii de așteptare), drepturile
 * utilizatorului bazei (doar numele drepturilor), numele cheilor din `settings` și câteva
 * numere (rânduri pe tabel, rămășițele vechi pe care le citesc migrările de date). Nicio
 * valoare din rânduri, nicio parolă. Rulează proprietarul, noaptea, prin SSH, din dosarul
 * site-ului de verificare (docs/VERIFICARE.md, „Verificările pe site-urile live”):
 *
 *   $PHP tools/sonda/amprenta-schema.php --site=bioscem
 *   $PHP tools/sonda/amprenta-schema.php --site=grafoanaytis      # la fel: biovitality, vertij
 *
 * Opțiuni: --dosar=<dosarul site-ului live> (implicit îl caută după domeniu, ca agregate.php),
 * --stdout (JSON-ul pe ecran, nimic scris; de exemplu `ssh … '…' > amprenta.json`),
 * --buget=<secunde> (implicit 300).
 *
 * Rezultatul (JSON) se scrie în storage/verificare/sonde/ al site-ului de verificare; are
 * numere scoase din datele live, deci nu intră în git (OD-49). Local,
 * tools/dev/migrari/din-sonda.php îl transformă în fixture-ul tools/dev/migrari/vechi/privat/
 * <cod>-live.sql și în extra-<cod>.txt (tot în afara git-ului), pentru testele migrărilor.
 * Garanțiile citirii: capul lui tools/verificare/lib/live.php.
 *
 * Cod de ieșire: 0 = totul citit; 1 = unele părți au căzut (scrise în „erori”, restul e bun);
 * 2 = refuzat (nimic citit).
 *
 * Fișierul (și tools/verificare/lib/intrare.php, încărcat primul) folosește doar sintaxa
 * PHP 7.0, ca un PHP prea vechi să-și vadă versiunea.
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
if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "EROARE: lipsește extensia PHP pdo_mysql.\n");
    exit(1);
}

require_once __DIR__ . '/lib/amprenta-schema.php';

exit(\Unelte\Verificare\AmprentaSchema::ruleaza($argv, $radacina));
