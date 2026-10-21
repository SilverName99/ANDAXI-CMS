<?php

declare(strict_types=1);

/**
 * Licența site-ului de verificare, pe server, prin SSH (docs/VERIFICARE.md, „Licența”): pune pe copie un pachet de
 * pluginuri, semnat aici cu cheia licenței de verificare (o face configureaza.php, doar pe acest server; niciun site
 * adevărat nu are cheia ei publică). Pachetele vin din catalogul codului deployat (config/plugins.php), exact ca
 * tools/dev/licenta.php în dezvoltare, deci nu rămân niciodată în urma lui:
 *
 *   $PHP tools/verificare/licenta.php bioscem                 # pluginurile Bioscem (licente_dev)
 *   $PHP tools/verificare/licenta.php bioscem blog=oprit      # la fel, cu blogul oprit (plugin=pornit|se_opreste|oprit)
 *   $PHP tools/verificare/licenta.php magazin                 # un pachet: prezentare, catalog, magazin, asociatie
 *   $PHP tools/verificare/licenta.php grafoanaytis            # licențele de dezvoltare: bioscem, biovitality, vertij, grafoanaytis
 *   $PHP tools/verificare/licenta.php toate                   # toate pluginurile (și unealta internă)
 *   $PHP tools/verificare/licenta.php presetari               # numele de mai sus
 *   $PHP tools/verificare/licenta.php arata                   # licența de acum și starea fiecărui plugin
 *
 * Înainte de a pune o licență șterge `licenta-buna.json` și `stare-aplicata.json`, deci orice pachet poate urma
 * oricărui alt pachet (pe un site adevărat o licență mai veche decât ultima bună e refuzată). Apoi, pe o copie
 * încărcată (tabelul `_verificare`) și la zi cu migrările, rulează pașii de activare ai pluginurilor pornite acum
 * (config/activari.php), ca o licență nouă venită de la CRM; pe baza goală ei așteaptă încărcarea.
 *
 * Refuză (cod 1, fără să schimbe nimic) dacă: PHP-ul e mai vechi decât `php_min`; codul nu e exact în
 * `~/domains/<domeniu>/public_html`; `.env` nu are `SITE_COPIE_VERIFICARE=1`; `LICENTA_SITE` nu începe cu
 * „verificare”; cheia privată lipsește sau nu e perechea lui `LICENTA_CHEIE_PUBLICA`; pachetul sau un plugin nu
 * există. Pașii de activare nu rulează pe o bază scrisă în `.env`-ul altui site din cont.
 *
 * Fișierul folosește doar sintaxa PHP 7.0 (codul aplicației îl încarcă abia după verificări).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);

function licenta_opreste($mesaj)
{
    fwrite(STDERR, 'EROARE: ' . $mesaj . "\n");
    exit(1);
}

$textConfig = (string) @file_get_contents($radacina . '/config/app.php');
$phpMin = preg_match("/'php_min'\\s*=>\\s*'([0-9.]+)'/", $textConfig, $m) === 1 ? $m[1] : '8.3';
if (version_compare(PHP_VERSION, $phpMin, '<')) {
    licenta_opreste('PHP ' . PHP_VERSION . ' e mai vechi decât minimul codului (' . $phpMin . '). Rulează cu un PHP mai nou, '
        . 'de exemplu /opt/alt/php83/usr/bin/php (versiunile: ls /opt/alt | grep php).');
}
if (!extension_loaded('sodium')) {
    licenta_opreste('lipsește extensia PHP sodium (hPanel → PHP Configuration → PHP extensions).');
}

require_once $radacina . '/src/Support/Env.php';
require_once $radacina . '/src/Support/Verificare.php';
require_once $radacina . '/tools/verificare/lib/cont.php';

use App\Support\Verificare;

$argumente = array_slice($argv, 1);
$comanda = (string) array_shift($argumente);
if ($comanda === '') {
    licenta_opreste('lipsește pachetul (de exemplu: licenta.php bioscem; licenta.php presetari arată numele).');
}

// ── Unde suntem ──

$acasa = (string) getenv('HOME');
if ($acasa === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
    $utilizatorSistem = posix_getpwuid(posix_geteuid());
    $acasa = is_array($utilizatorSistem) ? (string) $utilizatorSistem['dir'] : '';
}
if ($acasa === '' || Verificare::dosarSite((string) realpath($radacina), $acasa) === null) {
    licenta_opreste('codul nu e în ~/domains/<domeniu>/public_html: licenta.php rulează doar din dosarul site-ului de verificare.');
}
$caleEnv = Verificare::caleEnv();
$cheiEnv = is_file($caleEnv) && !is_link($caleEnv) ? Verificare::citesteEnv((string) @file_get_contents($caleEnv)) : array();
if (trim(isset($cheiEnv['SITE_COPIE_VERIFICARE']) ? $cheiEnv['SITE_COPIE_VERIFICARE'] : '') !== '1') {
    licenta_opreste('.env-ul de aici lipsește sau nu are SITE_COPIE_VERIFICARE=1: licenta.php merge doar pe site-ul de verificare.');
}
if (strpos(trim(isset($cheiEnv['LICENTA_SITE']) ? $cheiEnv['LICENTA_SITE'] : ''), 'verificare') !== 0) {
    licenta_opreste('LICENTA_SITE din .env nu începe cu „verificare”: cheia de aici semnează doar licențele copiei.');
}
$cheiePublica = null;
$cheiePrivata = Verificare::cheiePrivataLicenta($cheiePublica);
if ($cheiePrivata === null) {
    licenta_opreste('lipsește cheia licenței de verificare (storage/licenta/' . Verificare::FISIER_CHEIE_LICENTA . '): '
        . PHP_BINARY . ' tools/verificare/configureaza.php --rescrie o face.');
}
if (trim(isset($cheiEnv['LICENTA_CHEIE_PUBLICA']) ? $cheiEnv['LICENTA_CHEIE_PUBLICA'] : '') !== $cheiePublica) {
    licenta_opreste('LICENTA_CHEIE_PUBLICA din .env nu e perechea cheii private de aici: '
        . PHP_BINARY . ' tools/verificare/configureaza.php --rescrie le pune la loc.');
}

// Codul aplicației (în linia de comandă bootstrap.php nu pornește nicio sesiune și nu deschide baza).
require_once $radacina . '/bootstrap.php';
// Pachetul, semnătura și pașii de activare: comune cu incarca.php (tools/verificare/lib/licenta-copie.php).
require_once $radacina . '/tools/verificare/lib/licenta-copie.php';

use Unelte\Verificare\LicentaCopie;

$catalog = \App\Support\Plugins::catalog();

if ($comanda === 'presetari') {
    echo implode("\n", LicentaCopie::presetari($catalog)), "\n";
    exit(0);
}

$arataStari = function () {
    foreach (\App\Support\Plugins::stari() as $cheie => $stare) {
        echo '  ', str_pad($cheie, 20), $stare, "\n";
    }
    foreach (\App\Support\Plugins::mesaje() as $mesaj) {
        echo 'Notă: ', $mesaj, "\n";
    }
};

if ($comanda === 'arata') {
    $rezultat = \App\Support\Licenta::incarca();
    if ($rezultat['licenta'] === null) {
        echo "Nicio licență bună: site-ul merge doar cu nucleul.\n";
    } else {
        echo 'Licența „', $rezultat['licenta']['site'], '” v', $rezultat['licenta']['versiune'], ' din ', $rezultat['licenta']['emisa_la'], "\n";
    }
    $arataStari();
    exit(0);
}

// ── Pachetul ──

try {
    $pluginuri = LicentaCopie::pluginuri($catalog, $comanda, $argumente);
    $pusa = LicentaCopie::pune($pluginuri, $cheiePrivata);
} catch (RuntimeException $e) {
    licenta_opreste($e->getMessage());
}
echo 'Licența v', $pusa['versiune'], ' pentru „', $pusa['site'], '” (', $comanda, '): ', $pusa['pornite'] === array() ? 'doar nucleul' : implode(', ', $pusa['pornite']), "\n";
foreach ($pusa['mesaje'] as $mesaj) {
    echo 'Notă: ', $mesaj, "\n";
}

// ── Pașii de activare, doar pe o copie încărcată ──

foreach (LicentaCopie::activari($acasa) as $rand) {
    echo $rand, "\n";
}
exit(0);
