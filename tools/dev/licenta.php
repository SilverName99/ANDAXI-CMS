<?php

declare(strict_types=1);

/**
 * Licențe de test pentru dezvoltare: o pereche de chei Ed25519 locală (în locul
 * celei a CRM-ului) și licențe semnate cu ea, ca testele să poată schimba
 * pachetul site-ului.
 *
 *   tools/dev/php.sh tools/dev/licenta.php cheie                 # perechea de chei (o face dacă lipsește)
 *   tools/dev/php.sh tools/dev/licenta.php toate                 # toate pluginurile pornite
 *   tools/dev/php.sh tools/dev/licenta.php bioscem               # licența de dezvoltare a Bioscem (licente_dev, fără unealta internă)
 *   tools/dev/php.sh tools/dev/licenta.php grafoanaytis          # la fel: biovitality, vertij, grafoanaytis
 *   tools/dev/php.sh tools/dev/licenta.php magazin               # pachetul Magazin
 *   tools/dev/php.sh tools/dev/licenta.php magazin cupoane=pornit magazin=se_opreste
 *   tools/dev/php.sh tools/dev/licenta.php lista blog=pornit     # doar ce scrie (restul oprit)
 *   tools/dev/php.sh tools/dev/licenta.php fara                  # șterge licența și starea aplicată (site nou: doar nucleul)
 *   tools/dev/php.sh tools/dev/licenta.php stari                 # starea efectivă a fiecărui plugin
 *   tools/dev/php.sh tools/dev/licenta.php cron                  # cronurile pluginurilor și dacă ar rula acum
 *   tools/dev/php.sh tools/dev/licenta.php arata                 # licența folosită acum și problemele ei
 *   tools/dev/php.sh tools/dev/licenta.php presetari             # numele licențelor de test (pentru smoke.sh)
 *
 * Opțiuni: --versiune=N (implicit: următoarea), --site=ID (implicit LICENTA_SITE),
 * --redirect=plugin:/cale (adresele publice ale pluginului oprit duc acolo).
 *
 * Pachetele: prezentare, catalog, magazin, asociatie (din config/plugins.php,
 * fără opționale; la Magazin toate cele trei plăți cu cardul), toate (inclusiv
 * unealta internă) și licențele de dezvoltare ale celor patru site-uri existente
 * (bioscem, biovitality, vertij, grafoanaytis: liste scrise explicit în
 * config/plugins.php → licente_dev, ce are codul vechi al fiecăruia; un plugin portat
 * mai târziu nu intră singur în ele). Cheia privată stă în $CMS_DEV_DIR (implicit
 * storage/logs/dev), niciodată în git; site-ul primește doar cheia publică, prin
 * LICENTA_CHEIE_PUBLICA (tools/dev/comun.sh o pune singur).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierPrivat = $dosarDev . '/licenta-cheie-privata.txt';
$fisierPublic = $dosarDev . '/licenta-cheie-publica.txt';

$argumente = array_slice($argv, 1);
$comanda = (string) (array_shift($argumente) ?? '');

$opreste = static function (string $mesaj): void {
    fwrite(STDERR, 'EROARE: ' . $mesaj . "\n");
    exit(1);
};

// Perechea de chei de dezvoltare: înainte de bootstrap, ca s-o poată cere și comun.sh.
if ($comanda === 'cheie') {
    if (!is_file($fisierPrivat) || !is_file($fisierPublic)) {
        if (!is_dir($dosarDev)) {
            mkdir($dosarDev, 0770, true);
        }
        $pereche = sodium_crypto_sign_keypair();
        file_put_contents($fisierPrivat, base64_encode(sodium_crypto_sign_secretkey($pereche)) . "\n");
        chmod($fisierPrivat, 0600);
        file_put_contents($fisierPublic, base64_encode(sodium_crypto_sign_publickey($pereche)) . "\n");
    }
    echo trim((string) file_get_contents($fisierPublic)), "\n";
    exit(0);
}

require_once $radacina . '/bootstrap.php';

use App\Support\Licenta;
use App\Support\Plugins;

$optiuni = [];
$perechi = [];
foreach ($argumente as $argument) {
    if (str_starts_with($argument, '--')) {
        [$nume, $valoare] = array_pad(explode('=', substr($argument, 2), 2), 2, '');
        $optiuni[$nume][] = $valoare;
        continue;
    }
    if (!str_contains($argument, '=')) {
        $opreste('nu înțeleg „' . $argument . '” (aștept cheie=stare).');
    }
    [$cheie, $stare] = explode('=', $argument, 2);
    $perechi[trim($cheie)] = trim($stare);
}

$catalog = Plugins::catalog();
$dosarLicenta = Licenta::dosar();

$versiuneDinFisier = static function (string $fisier): int {
    $date = is_file($fisier) ? json_decode((string) file_get_contents($fisier), true) : null;
    return is_array($date) ? (int) ($date['versiune'] ?? 0) : 0;
};

switch ($comanda) {
    case 'presetari':
        // Toate numele pe care le înțelege comanda de mai jos (fără „lista”).
        echo implode("\n", array_merge(['toate', 'fara'], array_keys((array) ($catalog['licente_dev'] ?? [])),
            array_keys((array) ($catalog['pachete'] ?? [])))), "\n";
        exit(0);

    case 'stari':
        foreach (Plugins::stari() as $cheie => $stare) {
            echo $cheie, ' ', $stare, "\n";
        }
        echo 'licenta ', Plugins::licentaLipseste() ? 'lipsa' : 'ok', "\n";
        exit(0);

    case 'cron':
        // Pentru testul de fum: fiecare cron din catalog, al cui e și dacă ar rula acum.
        foreach ($catalog['pluginuri'] as $cheie => $definitie) {
            foreach ((array) ($definitie['cron'] ?? []) as $script => $cerinta) {
                echo $script, ' ', $cheie, ' ', Plugins::cronPermis($script) ? 'ruleaza' : 'oprit', "\n";
            }
        }
        exit(0);

    case 'arata':
        $rezultat = Licenta::incarca();
        if ($rezultat['licenta'] === null) {
            echo "Nicio licență bună: site-ul merge doar cu nucleul.\n";
        } else {
            $l = $rezultat['licenta'];
            echo 'Licența ', $l['site'], ' v', $l['versiune'], ' din ', $l['emisa_la'], ' (', $rezultat['sursa'], ")\n";
        }
        foreach (Plugins::stari() as $cheie => $stare) {
            echo '  ', str_pad($cheie, 20), $stare, "\n";
        }
        foreach (Plugins::mesaje() as $mesaj) {
            echo 'Problemă: ', $mesaj, "\n";
        }
        exit(0);

    case 'fara':
        // Și starea aplicată: altfel site-ul ar merge mai departe pe ultima licență aplicată.
        foreach ([Licenta::FISIER, Licenta::FISIER_BUN, Plugins::FISIER_STARE] as $fisier) {
            @unlink($dosarLicenta . '/' . $fisier);
        }
        echo "Licența a fost ștearsă: site-ul merge doar cu nucleul.\n";
        exit(0);
}

$chei = array_keys($catalog['pluginuri']);
$pluginuri = [];
if ($comanda === 'toate') {
    $pluginuri = array_fill_keys($chei, 'pornit');
} elseif (isset($catalog['licente_dev'][$comanda])) {
    // Licența de dezvoltare a unui site existent: lista explicită din catalog.
    foreach ((array) $catalog['licente_dev'][$comanda] as $cheie) {
        if (!in_array($cheie, $chei, true)) {
            $opreste('licente_dev.' . $comanda . ' are pluginul necunoscut „' . $cheie . '”.');
        }
        $pluginuri[(string) $cheie] = 'pornit';
    }
} elseif ($comanda === 'lista') {
    // doar perechile date
} elseif (isset($catalog['pachete'][$comanda])) {
    $pachet = $catalog['pachete'][$comanda];
    foreach (array_merge((array) ($pachet['pluginuri'] ?? []), (array) ($pachet['cel_putin_una_din'] ?? [])) as $cheie) {
        $pluginuri[(string) $cheie] = 'pornit';
    }
} else {
    $opreste('comandă necunoscută „' . $comanda . '”. Vezi începutul fișierului tools/dev/licenta.php.');
}
foreach ($perechi as $cheie => $stare) {
    if (!in_array($cheie, $chei, true)) {
        $opreste('pluginul „' . $cheie . '” nu există în config/plugins.php.');
    }
    if (!in_array($stare, Licenta::STARI, true)) {
        $opreste('starea „' . $stare . '” nu există (pornit, se_opreste, oprit).');
    }
    $pluginuri[$cheie] = $stare;
}
// Licența listează doar ce nu e oprit (lipsă = oprit), ca cea a CRM-ului.
$pluginuri = array_filter($pluginuri, static fn (string $stare): bool => $stare !== 'oprit');

if (!is_file($fisierPrivat)) {
    $opreste('lipsește cheia de dezvoltare; rulează întâi: tools/dev/php.sh tools/dev/licenta.php cheie');
}
$cheiePrivata = Licenta::decodeazaBase64((string) file_get_contents($fisierPrivat));
if ($cheiePrivata === null || strlen($cheiePrivata) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    $opreste('cheia privată din ' . $fisierPrivat . ' e stricată.');
}

$versiune = isset($optiuni['versiune'][0])
    ? (int) $optiuni['versiune'][0]
    : max($versiuneDinFisier($dosarLicenta . '/' . Licenta::FISIER), $versiuneDinFisier($dosarLicenta . '/' . Licenta::FISIER_BUN)) + 1;
$licenta = [
    'site' => (string) ($optiuni['site'][0] ?? Licenta::site()),
    'emisa_la' => gmdate('Y-m-d\TH:i:s\Z'),
    'versiune' => $versiune,
    'pluginuri' => $pluginuri,
];
foreach ((array) ($optiuni['redirect'] ?? []) as $redirect) {
    [$cheie, $cale] = array_pad(explode(':', $redirect, 2), 2, '');
    $licenta['redirectionari'][$cheie] = $cale;
}

$text = Licenta::semneaza($licenta, $cheiePrivata);
$rezultat = Licenta::instaleaza($text);
if (!$rezultat['ok']) {
    $opreste('licența nu a fost instalată: ' . $rezultat['eroare']);
}

Plugins::reseteaza();
$pornite = [];
foreach (Plugins::stari() as $cheie => $stare) {
    if ($stare !== 'oprit') {
        $pornite[] = $cheie . ($stare === 'se_opreste' ? ' (se oprește)' : '');
    }
}
echo 'Licența v', $versiune, ' pentru „', $licenta['site'], '” (', $comanda, '): ',
    $pornite === [] ? 'doar nucleul' : implode(', ', $pornite), "\n";
foreach (Plugins::mesaje() as $mesaj) {
    echo 'Notă: ', $mesaj, "\n";
}
