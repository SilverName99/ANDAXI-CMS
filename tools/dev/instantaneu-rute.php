<?php

declare(strict_types=1);

/**
 * Instantaneul rutelor (testul G1): pentru fiecare licență de test, ce face garda cu
 * fiecare rută, ce intrări are meniul adminului și ce cronuri rulează, comparat cu
 * fișierele scrise în git, tools/dev/instantanee/rute-<licența>.txt. Orice schimbare
 * a suprafeței publice a unui pachet apare astfel într-un `git diff`, pe care îl vede
 * cine revizuiește (testul B al pluginurilor își calculează așteptările din catalog,
 * deci o declarație greșită în catalog ar trece de el).
 *
 *   tools/dev/php.sh tools/dev/instantaneu-rute.php                 # compară; o schimbare = diff + cod 1
 *   tools/dev/php.sh tools/dev/instantaneu-rute.php --actualizeaza  # rescrie fișierele (apoi: git diff tools/dev/instantanee)
 *   tools/dev/php.sh tools/dev/instantaneu-rute.php grafoanaytis    # doar unele licențe
 *
 * Fără HTTP și fără bază (sub o secundă): stările se dau direct (Plugins::folosesteStari),
 * iar răspunsul gărzii se calculează ca în App\Http\GardaPluginuri::permite:
 *   MEREU      adresă mereu deschisă (plăți, ERP, linkurile din emailuri): ajunge la controller oricum;
 *   CONTROLLER ruta e a nucleului sau a unui plugin de pe site;
 *   403        admin: „Modulul nu este inclus în abonament” (nelogat: la login);
 *   404        site: pagina 404 (pe /api/: un 404 scurt în JSON).
 *
 * Licențele: toate, fara (fără licență: doar nucleul), cele de dezvoltare ale site-urilor
 * existente (config/plugins.php → licente_dev) și pachetele (→ pachete).
 *
 * Plus regulile scrise aici, care nu depind de catalog (ar rămâne adevărate chiar dacă
 * cineva greșește proprietarul unei rute): fără magazin, coșul, checkout-ul, plățile și
 * comenzile nu ajung niciodată la controller; la fel conturile fără conturi de clienți,
 * recenziile fără recenzii, acordurile GDPR fără pluginul lor (MEREU contează aici tot ca
 * „ajunge la controller”); adresele mereu deschise rămân MEREU în orice licență și sunt
 * doar cele din lista fixă $mereuDeschiseFixe (o adresă declarată mereu deschisă doar în
 * catalog pică); licente_dev.bioscem e exact ce avea Bioscem la 4915478. Ele pică și cu
 * --actualizeaza.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';
require_once __DIR__ . '/lib/cereri-rute.php';

use App\Http\Router;
use App\Support\Plugins;

$actualizeaza = in_array('--actualizeaza', $argv, true);
$alese = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => !str_starts_with($a, '--')));
$dosar = __DIR__ . '/instantanee';
$catalog = Plugins::catalog();

// Licențele de test: nume => stările din licență.
$licente = [
    'toate' => array_fill_keys(Plugins::chei(), Plugins::PORNIT),
];
foreach ((array) ($catalog['licente_dev'] ?? []) as $nume => $lista) {
    $licente[(string) $nume] = array_fill_keys(array_map('strval', (array) $lista), Plugins::PORNIT);
}
foreach ((array) ($catalog['pachete'] ?? []) as $nume => $pachet) {
    $licente[(string) $nume] = array_fill_keys(array_merge((array) ($pachet['pluginuri'] ?? []),
        (array) ($pachet['cel_putin_una_din'] ?? [])), Plugins::PORNIT);
}
$licente['fara'] = [];
if ($alese !== []) {
    $necunoscute = array_diff($alese, array_keys($licente));
    if ($necunoscute !== []) {
        fwrite(STDERR, 'Licențe necunoscute: ' . implode(', ', $necunoscute) . ' (' . implode(', ', array_keys($licente)) . ")\n");
        exit(1);
    }
    $licente = array_intersect_key($licente, array_flip($alese));
}

$probleme = [];

// Licența de dezvoltare a Bioscem = regula veche („tot ce e portat, fără unealta internă”)
// la 4915478, scrisă aici ca să nu se schimbe odată cu catalogul.
$bioscemLa4915478 = ['catalog', 'magazin', 'livrare_fan', 'plata_stripe', 'plata_euplatesc', 'plata_bt', 'link_plata', 'erp',
    'cupoane', 'fidelitate', 'recenzii', 'precomenzi', 'oferte_bbd', 'produse_promo', 'conturi_clienti', 'blog', 'newsletter',
    'chat_live', 'manechin', 'acorduri_gdpr'];
$bioscemAcum = array_map('strval', (array) ($catalog['licente_dev']['bioscem'] ?? []));
if ($bioscemAcum !== $bioscemLa4915478) {
    $probleme[] = 'licente_dev.bioscem nu mai e lista Bioscem de la 4915478: în plus ' . implode(', ', array_diff($bioscemAcum, $bioscemLa4915478))
        . '; lipsă ' . implode(', ', array_diff($bioscemLa4915478, $bioscemAcum)) . ' (o schimbare aici se face doar explicit, cu motivul ei)';
}
foreach ((array) ($catalog['licente_dev'] ?? []) as $nume => $lista) {
    foreach ((array) $lista as $cheie) {
        if (Plugins::definitie((string) $cheie) === null || $cheie === Plugins::NUCLEU) {
            $probleme[] = 'licente_dev.' . $nume . ' are pluginul necunoscut „' . $cheie . '”';
        }
        if ((Plugins::definitie((string) $cheie)['intern'] ?? false) === true) {
            $probleme[] = 'licente_dev.' . $nume . ' are unealta internă „' . $cheie . '” (nu se vinde)';
        }
    }
}

$router = new Router();
(require $radacina . '/src/Http/rute.php')($router);
// Valori fixe pentru parametrii rutelor (instantaneul afișează tiparul, nu valoarea).
$cereri = cereri_rute($router, static fn (string $tipar, string $nume, string $metoda): string => 'x');

/** Adresa afișată: tiparul, cu parametrii puși de variantă, plus GET/POST-ul variantei. */
$afisat = static function (array $c): string {
    $cale = $c['pagina'] !== null ? '/' . $c['pagina'] : $c['tipar'];
    foreach ($c['din_ruta'] as $nume => $valoare) {
        $cale = (string) preg_replace('#\{' . preg_quote((string) $nume, '#') . '\*?\}#', (string) $valoare, $cale);
    }
    $cale = str_replace('\\.', '.', $cale);
    if ($c['get'] !== []) {
        $cale .= '?' . http_build_query($c['get']);
    }
    if ($c['post'] !== []) {
        $cale .= ' (' . http_build_query($c['post']) . ')';
    }
    return $cale;
};

/** Ce face garda cu cererea (ca GardaPluginuri::permite), după stările de acum. */
$raspuns = static function (array $c): array {
    $proprietar = Plugins::proprietarRuta($c['metoda'], $c['tipar'], $c['parametri'], $c['get'], $c['post']);
    $admin = $c['tipar'] === '/admin' || str_starts_with($c['tipar'], '/admin/');
    $rezultat = match (true) {
        $proprietar['mereu_deschis'] => 'MEREU',
        Plugins::oricareVizibil($proprietar['chei']) => 'CONTROLLER',
        $admin => '403',
        default => '404',
    };
    return [$rezultat, implode('|', $proprietar['chei'])];
};

// Regulile care nu depind de catalog: [plugin, metoda (null = oricare), tipar pe adresa afișată].
$faraPlugin = [
    ['magazin', null, '#^/cos(/|$|\?| )#'],
    ['magazin', null, '#^/checkout$#'],
    ['magazin', null, '#^/api/cart/#'],
    ['magazin', null, '#^/api/checkout/#'],
    ['magazin', null, '#^/plata/#'],
    ['magazin', null, '#^/admin/orders#'],
    ['magazin', null, '#^/admin/coupons#'],
    ['magazin', null, '#^/admin/settings/floating-cart$#'],
    ['conturi_clienti', null, '#^/login$#'],
    ['conturi_clienti', null, '#^/register$#'],
    ['conturi_clienti', null, '#^/contul-meu#'],
    ['conturi_clienti', null, '#^/auth/google#'],
    ['recenzii', 'POST', '#^/produs/\{slug\}/review$#'],
    ['recenzii', null, '#^/review-form/#'],
    ['acorduri_gdpr', null, '#^/gdpr-agreements/#'],
    ['acorduri_gdpr', 'GET', '#^/acorduri-gdpr$#'],
    ['acorduri_gdpr', null, '#^/admin/users/gdpr-agreements#'],
];
// Adresele la care răspund procesatorii, ERP-ul și linkurile din emailurile trimise: singurele
// care au voie să fie MEREU (ajung la controller și fără pluginul lor). Una nouă, declarată în
// catalog (rute_mereu_deschise), pică până se adaugă și aici, cu metoda ei.
$mereuDeschiseFixe = ['POST /webhook/stripe', 'POST /webhook/euplatesc', 'POST /webhook/bt-ipay', 'GET /checkout/bt/retur',
    'POST /checkout/succes/{orderNumber}', 'POST /api/erp/notificare', 'POST /api/erp/adrese', 'GET /newsletter/unsubscribe/{token}',
    'GET /newsletter/track/open/{campaignId}/{subscriberId}/{token}', 'GET /newsletter/track/click/{campaignId}/{subscriberId}/{token}'];
$mereuDeschiseFixeNorm = [];
foreach ($mereuDeschiseFixe as $adresa) {
    [$metodaFixa, $tiparFix] = explode(' ', $adresa, 2);
    $mereuDeschiseFixeNorm[$metodaFixa . ' ' . Plugins::normalizeazaTipar($tiparFix)] = true;
}
$mereuDeschiseCatalog = [];
foreach (array_merge([$catalog['nucleu']], $catalog['pluginuri']) as $definitie) {
    foreach ((array) ($definitie['rute_mereu_deschise'] ?? []) as $text) {
        $mereuDeschiseCatalog[] = Plugins::normalizeazaTipar((string) $text);
    }
}

@mkdir($dosar, 0775, true);
$schimbate = [];
foreach ($licente as $numeLicenta => $stari) {
    Plugins::folosesteStari($stari);
    $vizibile = array_keys(array_filter(Plugins::stari(), static fn (string $s): bool => $s !== Plugins::OPRIT));

    $rute = [];
    $gasiteMereu = [];
    foreach ($cereri as $c) {
        [$rezultat, $chei] = $raspuns($c);
        $cale = $afisat($c);
        $rute[] = sprintf('%-4s %s -> %s [%s]', $c['metoda'], $cale, $rezultat, $chei);
        $cheieTipar = $c['metoda'] . ' ' . Plugins::normalizeazaTipar($c['tipar']);
        // MEREU ajunge și el la controller; e voie doar pentru adresele din lista fixă de mai sus.
        $mereuFix = isset($mereuDeschiseFixeNorm[$cheieTipar]);
        if ($rezultat === 'MEREU') {
            $gasiteMereu[$cheieTipar] = true;
            if (!$mereuFix) {
                $probleme[] = $numeLicenta . ': ' . $c['metoda'] . ' ' . $cale . ' e mereu deschisă (' . $chei . '), dar nu e în lista fixă '
                    . '$mereuDeschiseFixe din instantaneu-rute.php (o adresă nouă mereu deschisă se adaugă acolo, explicit, cu motivul ei)';
            }
        }
        $ajungeLaController = $rezultat === 'CONTROLLER' || ($rezultat === 'MEREU' && !$mereuFix);
        foreach ($faraPlugin as [$plugin, $metoda, $tipar]) {
            if (!Plugins::vizibil($plugin) && ($metoda === null || $metoda === $c['metoda']) && preg_match($tipar, $cale) === 1
                && $ajungeLaController) {
                $probleme[] = $numeLicenta . ': ' . $c['metoda'] . ' ' . $cale . ' ajunge la controller (' . $rezultat . ') fără pluginul '
                    . $plugin . ' (proprietar: ' . $chei . ')';
            }
        }
    }
    foreach (array_unique(array_merge(array_map([Plugins::class, 'normalizeazaTipar'], $mereuDeschiseFixe), $mereuDeschiseCatalog)) as $adresa) {
        // O declarație fără metodă acoperă și GET, și POST.
        $variante = preg_match('#^(GET|POST) #', $adresa) === 1 ? [$adresa] : ['GET ' . $adresa, 'POST ' . $adresa];
        if (array_filter($variante, static fn (string $v): bool => isset($gasiteMereu[$v])) === []) {
            $probleme[] = $numeLicenta . ': adresa ' . $adresa . ' nu mai e mereu deschisă (sau nu mai e în router)';
        }
    }
    sort($rute);

    $meniu = [];
    foreach (Plugins::meniuAdmin() as $slot) {
        foreach ($slot['intrari'] as $intrare) {
            $grup = $slot['tip'] === 'link' ? '' : ($slot['eticheta'] !== '' ? $slot['eticheta'] : $slot['cheie']) . ' > ';
            $proprietari = isset($intrare['oricare_din']) ? implode('|', (array) $intrare['oricare_din']) : (string) $intrare['plugin'];
            $meniu[] = $grup . $intrare['eticheta'] . ' -> ' . $intrare['href'] . ' [' . $proprietari . ']';
        }
    }

    $cronuri = [];
    foreach ($catalog['pluginuri'] as $cheie => $definitie) {
        foreach ((array) ($definitie['cron'] ?? []) as $script => $cerinta) {
            $cronuri[] = $script . ' -> ' . (Plugins::cronPermis((string) $script) ? 'RULEAZA' : 'OPRIT') . ' [' . $cheie . ', cere ' . $cerinta . ']';
        }
    }
    sort($cronuri);

    $text = '# Instantaneul rutelor pentru licența „' . $numeLicenta . '”. Nu se scrie de mână: '
        . "tools/dev/php.sh tools/dev/instantaneu-rute.php --actualizeaza\n"
        . '# Pe site: ' . ($vizibile === [] ? 'doar nucleul' : implode(', ', $vizibile)) . "\n"
        . "# MEREU = adresă mereu deschisă; CONTROLLER = ajunge la controller; 403 = „Modulul nu este inclus în abonament”; 404 = pagina 404\n"
        . "\nRUTE (" . count($rute) . ")\n" . implode("\n", $rute) . "\n"
        . "\nMENIU (" . count($meniu) . ")\n" . implode("\n", $meniu) . "\n"
        . "\nCRON (" . count($cronuri) . ")\n" . implode("\n", $cronuri) . "\n";

    $fisier = $dosar . '/rute-' . $numeLicenta . '.txt';
    $vechi = is_file($fisier) ? (string) file_get_contents($fisier) : null;
    if ($vechi === $text) {
        echo '  OK    ', str_pad($numeLicenta, 13), count($rute), ' rute, ', count($meniu), ' intrări de meniu, ', count($cronuri), " cronuri\n";
        continue;
    }
    if ($actualizeaza) {
        file_put_contents($fisier, $text);
        echo '  SCRIS ', str_pad($numeLicenta, 13), substr($fisier, strlen($radacina) + 1), $vechi === null ? ' (nou)' : '', "\n";
        continue;
    }
    $schimbate[] = $numeLicenta;
    if ($vechi === null) {
        echo '  LIPSĂ ', str_pad($numeLicenta, 13), substr($fisier, strlen($radacina) + 1), " (rulează cu --actualizeaza și pune fișierul în git)\n";
        continue;
    }
    $temporar = tempnam(sys_get_temp_dir(), 'instantaneu-');
    file_put_contents($temporar, $text);
    $diff = (string) shell_exec('diff -u --label ' . escapeshellarg('git: ' . basename($fisier)) . ' --label ' . escapeshellarg('acum: ' . basename($fisier))
        . ' ' . escapeshellarg($fisier) . ' ' . escapeshellarg($temporar));
    @unlink($temporar);
    echo '  DIFERĂ ', $numeLicenta, ":\n", $diff;
}
Plugins::reseteaza();

foreach (array_unique($probleme) as $problema) {
    echo '  EȘEC  ', $problema, "\n";
}
if ($schimbate !== []) {
    echo "\nSuprafața s-a schimbat pentru: ", implode(', ', $schimbate), ". Dacă e voit: --actualizeaza, apoi fișierele intră în commit.\n";
}
if ($probleme !== [] || $schimbate !== []) {
    exit(1);
}
echo $actualizeaza ? "Instantaneele sunt la zi.\n" : "Instantaneul rutelor: neschimbat, regulile fixe respectate.\n";
exit(0);
