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

$catalog = \App\Support\Plugins::catalog();
$dosarLicenta = \App\Support\Licenta::dosar();

if ($comanda === 'presetari') {
    echo implode("\n", array_merge(array('toate'), array_keys((array) (isset($catalog['licente_dev']) ? $catalog['licente_dev'] : array())),
        array_keys((array) (isset($catalog['pachete']) ? $catalog['pachete'] : array())))), "\n";
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

$chei = array_keys($catalog['pluginuri']);
$pluginuri = array();
if ($comanda === 'toate') {
    $pluginuri = array_fill_keys($chei, 'pornit');
} elseif (isset($catalog['licente_dev'][$comanda])) {
    foreach ((array) $catalog['licente_dev'][$comanda] as $cheie) {
        $pluginuri[(string) $cheie] = 'pornit';
    }
} elseif (isset($catalog['pachete'][$comanda])) {
    $pachet = $catalog['pachete'][$comanda];
    foreach (array_merge((array) (isset($pachet['pluginuri']) ? $pachet['pluginuri'] : array()),
        (array) (isset($pachet['cel_putin_una_din']) ? $pachet['cel_putin_una_din'] : array())) as $cheie) {
        $pluginuri[(string) $cheie] = 'pornit';
    }
} else {
    licenta_opreste('nu există pachetul „' . $comanda . '” (licenta.php presetari arată numele).');
}
foreach ($argumente as $argument) {
    if (strpos($argument, '=') === false) {
        licenta_opreste('nu înțeleg „' . $argument . '” (aștept plugin=stare, de exemplu blog=oprit).');
    }
    $parti = explode('=', $argument, 2);
    $cheie = trim($parti[0]);
    $stare = trim($parti[1]);
    if (!in_array($cheie, $chei, true)) {
        licenta_opreste('pluginul „' . $cheie . '” nu există în config/plugins.php.');
    }
    if (!in_array($stare, \App\Support\Licenta::STARI, true)) {
        licenta_opreste('starea „' . $stare . '” nu există (pornit, se_opreste, oprit).');
    }
    $pluginuri[$cheie] = $stare;
}
foreach (array_keys($pluginuri) as $cheie) {
    if (!in_array($cheie, $chei, true)) {
        licenta_opreste('pachetul „' . $comanda . '” are pluginul necunoscut „' . $cheie . '” (config/plugins.php).');
    }
}
// Licența listează doar ce nu e oprit (lipsă = oprit), ca cea a CRM-ului.
$pluginuri = array_filter($pluginuri, function ($stare) {
    return $stare !== 'oprit';
});

// Orice pachet poate urma oricărui alt pachet: fără ultima licență bună și fără starea aplicată.
$versiune = 0;
foreach (array(\App\Support\Licenta::FISIER, \App\Support\Licenta::FISIER_BUN) as $fisier) {
    $date = json_decode((string) @file_get_contents($dosarLicenta . '/' . $fisier), true);
    $versiune = max($versiune, is_array($date) ? (int) (isset($date['versiune']) ? $date['versiune'] : 0) : 0);
}
foreach (array(\App\Support\Licenta::FISIER_BUN, \App\Support\Plugins::FISIER_STARE) as $fisier) {
    if (is_file($dosarLicenta . '/' . $fisier) && !@unlink($dosarLicenta . '/' . $fisier)) {
        licenta_opreste('nu pot șterge storage/licenta/' . $fisier . ' (drepturi de scriere?).');
    }
}
$licenta = array(
    'site' => \App\Support\Licenta::site(),
    'emisa_la' => gmdate('Y-m-d\TH:i:s\Z'),
    'versiune' => $versiune + 1,
    'pluginuri' => $pluginuri,
);
$rezultat = \App\Support\Licenta::instaleaza(\App\Support\Licenta::semneaza($licenta, $cheiePrivata));
if (!$rezultat['ok']) {
    licenta_opreste('licența nu a fost pusă: ' . $rezultat['eroare']);
}
\App\Support\Plugins::reseteaza();
$pornite = array();
foreach (\App\Support\Plugins::stari() as $cheie => $stare) {
    if ($stare !== 'oprit') {
        $pornite[] = $cheie . ($stare === 'se_opreste' ? ' (se oprește)' : '');
    }
}
echo 'Licența v', $licenta['versiune'], ' pentru „', $licenta['site'], '” (', $comanda, '): ', $pornite === array() ? 'doar nucleul' : implode(', ', $pornite), "\n";
foreach (\App\Support\Plugins::mesaje() as $mesaj) {
    echo 'Notă: ', $mesaj, "\n";
}

// ── Pașii de activare, doar pe o copie încărcată ──

$app = require $radacina . '/config/app.php';
$db = null;
try {
    $db = \App\Support\Database::connection((array) (isset($app['db']) ? $app['db'] : array()));
} catch (Throwable $e) {
    $db = null;
}
if (!$db instanceof PDO) {
    echo "Notă: baza nu răspunde; pașii de activare rulează la prima cerere de pe copie.\n";
    exit(0);
}
$copie = (int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '_verificare'")->fetchColumn() > 0;
if (!$copie || \Unelte\Verificare\Cont::bazaAltuiSite($acasa, (string) \App\Support\Env::get('DB_DATABASE', ''), (string) \App\Support\Env::get('DB_HOST', 'localhost')) !== null) {
    echo "Notă: pe bază nu e încă o copie de verificare; pașii de activare ai pluginurilor rulează după încărcare.\n";
    exit(0);
}
try {
    foreach (\App\Support\Activari::ruleaza($db) as $pas) {
        echo 'Activare ', $pas['plugin'], ': ', $pas['rezultat'], "\n";
    }
    $asteapta = \App\Support\Activari::asteptare($db);
    if ($asteapta !== null) {
        echo 'Notă: pașii de activare așteaptă migrările (garda: ', $asteapta, '); rulează după scripts/migreaza.php --aplica.', "\n";
    }
} catch (Throwable $e) {
    echo 'Notă: pașii de activare n-au rulat acum (', \App\Support\Migrari\Schema::faraValori($e->getMessage()), '); rulează la prima cerere.', "\n";
}
exit(0);
