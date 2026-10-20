<?php

declare(strict_types=1);

/**
 * Testele SEO ale nucleului (docs/SEO.md): adresa unică a fiecărei pagini,
 * redirecționările adreselor vechi ale site-ului, sitemap-ul, robots.txt și
 * datele structurate, pe fiecare pachet de pluginuri.
 *
 *   tools/dev/php.sh tools/dev/test-seo.php
 *
 * A. Fără server: căile care nu se redirecționează niciodată (lista nucleului,
 *    fiecare adresă „mereu deschisă” din catalogul pluginurilor, fiecare rută de
 *    plată, cont, admin, API, plus seo.json), harta (pachetul demo și pachetul
 *    Bioscem: format, ținte, lanțuri și bucle), robots, JSON-LD, etichetele din
 *    sitemap.
 * B. Pe serverul de dezvoltare (APP_URL pe http, deci fără regula de gazdă):
 *    „/” final, bare duble, majuscule, harta (lanț, buclă, 410, țintă pe alt
 *    site, sursă exclusă, pagină existentă, parametrii păstrați), POST și HEAD,
 *    fără Set-Cookie și cu no-store pe 301; apoi pe fiecare pachet (toate,
 *    magazin, catalog, prezentare, asociație, magazinul „se oprește”, Bioscem):
 *    sitemap-ul (fiecare adresă 200, fără adresele pluginurilor ascunse, fără
 *    pagini de sistem sau blocate), robots.txt, JSON-LD, harta cu ținte ale
 *    pluginurilor ascunse; un sitemap.xml vechi pe disc; mentenanța.
 * C. Pe un al doilea server, cu APP_URL=https://exemplu-demo.test (inventat):
 *    www → gazda corectă într-un singur 301; fără buclă pe http; o gazdă
 *    necunoscută rămâne neatinsă; FIECARE rută GET exclusă și FIECARE POST, pe
 *    www, răspunde exact ca pe gazda corectă; coșul și contul rămân pe www.
 * D. Oprirea de urgență (SEO_REDIRECTURI=0): nicio redirecționare SEO.
 *
 * Serverele în plus pornesc pe CMS_DEV_PORT+2 și +3 și se opresc la final; la
 * final se pune la loc licența „toate”. Raportul: $CMS_DEV_DIR/test-seo-raport.txt;
 * cod de ieșire 0 doar dacă totul a trecut.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';

use App\Http\Router;
use App\Support\AvertismentProduse;
use App\Support\Database;
use App\Support\DateSite;
use App\Support\FluxGoogleMerchant;
use App\Support\JsonLd;
use App\Support\LogoAntet;
use App\Support\Plugins;
use App\Support\PoliticaLivrare;
use App\Support\ResponseCache;
use App\Support\Robots;
use App\Support\SeoMeta;
use App\Support\SeoRedirects;
use App\Support\Settings;
use App\Support\Sitemap;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-seo-raport.txt';
@file_put_contents($fisierRaport, '');
$trecute = 0;
$esuate = 0;
$scrie = static function (string $linie) use ($fisierRaport): void {
    echo $linie, "\n";
    file_put_contents($fisierRaport, $linie . "\n", FILE_APPEND);
};
$verifica = static function (string $eticheta, bool $ok, string $detalii = '') use (&$trecute, &$esuate, $scrie): void {
    if ($ok) {
        $trecute++;
        $scrie('  OK    ' . $eticheta);
    } else {
        $esuate++;
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . $detalii : ''));
    }
};

$scrie('Test SEO ANDAXI-CMS — ' . date('Y-m-d H:i:s'));
$dosarDemo = DateSite::dosar();
$scrie('  INFO  datele site-ului: ' . $dosarDemo);
if (!is_file($dosarDemo . '/redirecturi.json')) {
    $scrie('EROARE: lipsește ' . $dosarDemo . '/redirecturi.json (pachetul demo, pus de tools/dev/comun.sh)');
    exit(1);
}

$router = new Router();
(require $radacina . '/src/Http/rute.php')($router);
$catalog = Plugins::catalog();
/** Calea concretă a unui tipar de rută, cu o valoare inventată pentru fiecare parametru. */
$caleConcreta = static fn (string $tipar, string $valoare = 'test-seo'): string
    => (string) preg_replace('#\{[a-zA-Z_][a-zA-Z0-9_]*\*?\}#', $valoare, str_replace('\\.', '.', $tipar));

// =====================================================================
// A. Fără server
// =====================================================================
$scrie('');
$scrie('== A. Reguli (fără server)');

// A1. Căile care nu se redirecționează niciodată.
$mereuDeschise = [];
foreach (array_merge([$catalog['nucleu']], $catalog['pluginuri']) as $definitie) {
    foreach ((array) ($definitie['rute_mereu_deschise'] ?? []) as $text) {
        $mereuDeschise[] = $caleConcreta((string) preg_replace('#^(GET|POST)\s+#', '', (string) $text));
    }
}
$neexcluse = [];
foreach ($mereuDeschise as $cale) {
    foreach ([$cale, $cale . '/', strtoupper($cale), '/' . $cale] as $varianta) {
        if (!SeoRedirects::eExclusa($varianta)) {
            $neexcluse[] = $varianta;
        }
    }
}
$verifica(count($mereuDeschise) . ' adrese „mereu deschise” din catalog (webhook-uri, întoarceri de plată, ERP, newsletter) excluse, '
    . 'și cu „/” final, majuscule, „//”', $mereuDeschise !== [] && $neexcluse === [], implode(', ', $neexcluse));

$prefixeSensibile = ['/admin', '/api/', '/webhook/', '/auth/', '/plata/', '/checkout', '/cos', '/contul-meu', '/login', '/register',
    '/newsletter/', '/health', '/robots.txt', '/sitemap.xml', '/review-form/', '/gdpr-agreements/', '/contact/send'];
$ruteSensibile = 0;
$neexcluse = [];
foreach ($router->rute() as $ruta) {
    $cale = $caleConcreta($ruta['tipar']);
    foreach ($prefixeSensibile as $prefix) {
        if ($cale === rtrim($prefix, '/') || str_starts_with($cale, rtrim($prefix, '/') . '/')) {
            $ruteSensibile++;
            if (!SeoRedirects::eExclusa($cale)) {
                $neexcluse[] = $ruta['metoda'] . ' ' . $cale;
            }
            break;
        }
    }
}
$verifica($ruteSensibile . ' rute de plată, coș, cont, admin, API, health, robots, sitemap: toate excluse', $neexcluse === [],
    implode(', ', $neexcluse));
$exclusDa = ['/cos', '/cos/', '//cos', '/COS', '/%63os', '/cos/adauga/1', '/checkout/bt/retur', '/webhook/stripe/', '/api/erp/notificare',
    '/admin/orders', '/newsletter/track/click/1/1/x', '/zona-privata-demo', '/zona-privata-demo/pagina'];
$exclusNu = ['/', '/cosmetice', '/cos-de-cadouri', '/checkouts', '/produs/ceai-de-menta', '/magazin', '/plata-la-livrare', '/administrare-ceai'];
$verifica('excluse: ' . implode(' ', $exclusDa), array_filter($exclusDa, static fn ($c) => !SeoRedirects::eExclusa($c)) === []);
$verifica('neexcluse: ' . implode(' ', $exclusNu), array_filter($exclusNu, static fn ($c) => SeoRedirects::eExclusa($c)) === []);

// A2. Harta demo.
$regula = static fn (string $cale): ?array => SeoRedirects::regulaPentru($cale);
$verifica('harta: cheia se potrivește cu „/” final, majuscule și parametri', $regula('/produse/') === ['/magazin', 301]
    && $regula('/PRODUSE?utm_source=x') === ['/magazin', 301] && $regula('/majuscule-veche') === ['/contact', 301]);
$verifica('harta: 410 exact și pe prefix', $regula('/tag/js') === [null, 410] && $regula('/wp-admin/admin-ajax.php') === [null, 410]
    && $regula('/wp-content/plugins/x/y.js') === [null, 410] && $regula('/wp-content/uploads/a.jpg') === null);
$verifica('harta: o țintă pe alt site („https://…”, „//…”) se ignoră', $regula('/tinta-straina') === null && $regula('/tinta-doua-bare') === null);
$verifica('harta: lanțul A → B → C se urmează într-un salt; bucla, ținta 410 și ținta egală cu adresa nu redirecționează',
    SeoRedirects::tintaFinala('/lant-a', '/lant-b') === '/magazin' && SeoRedirects::tintaFinala('/bucla-a', '/bucla-b') === null
    && SeoRedirects::tintaFinala('/spre-disparut', '/disparut') === null && SeoRedirects::tintaFinala('/sine', '/sine') === null);

// A3. Pachetul de date Bioscem (site-uri/bioscem).
DateSite::reseteaza($radacina . '/site-uri/bioscem');
SeoRedirects::reseteaza();
$reguli = SeoRedirects::reguli();
$coduri = array_count_values(array_map(static fn (array $r): int => (int) ($r[2] ?? 0), $reguli['exacte']));
$verifica('Bioscem: ' . count($reguli['exacte']) . ' reguli exacte (' . ($coduri[301] ?? 0) . ' × 301, ' . ($coduri[410] ?? 0) . ' × 410) și '
    . count($reguli['prefixe']) . ' prefixe 410, ca în Bioscem 4254ca4 (128 = 118 + 10: livrarea 1, cele 14 reguli din 19 oct și '
    . '/retur-garantie din 20 oct; 6 prefixe)',
    count($reguli['exacte']) === 128 && ($coduri[301] ?? 0) === 118 && ($coduri[410] ?? 0) === 10 && count($reguli['prefixe']) === 6);
$surse = [];
$probleme = [];
foreach ($reguli['exacte'] as $r) {
    [$din, $spre, $cod] = [(string) $r[0], $r[1], (int) $r[2]];
    if (SeoRedirects::cheie($din) !== $din) {
        $probleme[] = 'cheie nenormalizată ' . $din;
    }
    if (isset($surse[$din])) {
        $probleme[] = 'dublură ' . $din;
    }
    $surse[$din] = true;
    if ($cod === 301 && (!is_string($spre) || !SeoRedirects::tintaValida($spre) || SeoRedirects::cheie($spre) === $din)) {
        $probleme[] = 'țintă greșită ' . $din . ' → ' . var_export($spre, true);
    }
    if ($cod === 410 && $spre !== null) {
        $probleme[] = '410 cu țintă ' . $din;
    }
    if (SeoRedirects::eExclusa($din)) {
        $probleme[] = 'sursă exclusă (nu s-ar aplica niciodată) ' . $din;
    }
    if ($spre === '/' && $din !== '/index.php') {
        $probleme[] = 'trimisă la prima pagină ' . $din;
    }
}
foreach ($reguli['exacte'] as $r) {
    if (is_string($r[1]) && isset($surse[SeoRedirects::cheie($r[1])])) {
        $probleme[] = 'lanț ' . $r[0] . ' → ' . $r[1];
    }
    if (is_string($r[1]) && preg_match('#^/eticheta/([^/?]+)#', $r[1], $m) === 1
        && in_array($m[1], (array) DateSite::seo('sitemap.etichete_excluse', []), true)) {
        $probleme[] = 'țintă spre o etichetă scoasă din Google (D3) ' . $r[0] . ' → ' . $r[1];
    }
}
$verifica('Bioscem: chei normalizate, fără dubluri, ținte locale, fără lanțuri, fără surse excluse, prima pagină doar pentru /index.php, '
    . 'nicio țintă spre etichetele cu nume de boli', $probleme === [], implode('; ', array_slice($probleme, 0, 10)));
$verifica('Bioscem: etichetele din sitemap după D2/D3 (minim 3; 12 mici păstrate; 19 cu nume de boli scoase)',
    (int) DateSite::seo('sitemap.eticheta_minim_produse') === 3 && count((array) DateSite::seo('sitemap.etichete_pastrate')) === 12
    && count((array) DateSite::seo('sitemap.etichete_excluse')) === 19
    && Sitemap::etichetaInSitemap('vitamina-d', 1) && !Sitemap::etichetaInSitemap('vitamina-d', 0)
    && !Sitemap::etichetaInSitemap('relaxare', 2) && Sitemap::etichetaInSitemap('somn', 3)
    && !Sitemap::etichetaInSitemap('insomnie', 5) && !Sitemap::etichetaInSitemap('antidepresiv', 8));
$verifica('Bioscem: /cos-cumparaturi blocată în robots.txt și exclusă din redirecționări (seo.json)',
    Robots::blocheaza('/cos-cumparaturi') && SeoRedirects::eExclusa('/cos-cumparaturi') && in_array('/cos-cumparaturi', Robots::reguli(), true));
$verifica('Bioscem: numele și logo-urile pentru Google din seo.json', DateSite::seo('organizatie.nume') === 'Bioscem Shop'
    && count((array) DateSite::seo('organizatie.logo')) === 4);
DateSite::reseteaza();
SeoRedirects::reseteaza();
$verifica('nucleul fără datele unui site: nicio regulă, etichetele de la 1 produs', (static function () use ($radacina): bool {
    DateSite::reseteaza($radacina . '/storage/nu-exista-' . getmypid());
    SeoRedirects::reseteaza();
    $ok = SeoRedirects::reguli() === ['exacte' => [], 'prefixe' => []] && SeoRedirects::regulaPentru('/produse') === null
        && Sitemap::etichetaInSitemap('orice', 1) && !Sitemap::etichetaInSitemap('orice', 0) && !Robots::blocheaza('/pagina-sistem-demo');
    DateSite::reseteaza();
    SeoRedirects::reseteaza();
    return $ok;
})());

// A4. robots: reguli exacte.
$verifica('robots: /cos, /cos?x, /cos/… și /checkout blocate; /cosmetice și /cos-de-cadouri nu',
    Robots::blocheaza('/cos') && Robots::blocheaza('/cos?x=1') && Robots::blocheaza('/cos/adauga/1') && Robots::blocheaza('/checkout')
    && !Robots::blocheaza('/cosmetice') && !Robots::blocheaza('/cos-de-cadouri') && !Robots::blocheaza('/produs/x'));

// A5. JSON-LD: Product doar cu ofertă sau recenzii.
$baza = ['url' => 'https://exemplu.test/produs/x', 'name' => 'Produs </script><b>x</b>', 'images' => [], 'description' => '', 'sku' => 'X1', 'brand' => ''];
$faraNimic = JsonLd::produs($baza + ['oferta' => false, 'price' => 10.0]);
$doarRecenzii = JsonLd::produs($baza + ['oferta' => false, 'price' => 10.0,
    'reviews' => ['count' => 2, 'average' => 4.5, 'items' => [['user_name' => 'Ana', 'rating' => 5, 'review_text' => 'Bun']]]]);
$cuOferta = JsonLd::produs($baza + ['oferta' => true, 'price' => 14.5, 'availability' => 'PreOrder']);
$pretZero = JsonLd::produs($baza + ['oferta' => true, 'price' => 0.0]);
$verifica('JSON-LD: fără ofertă și fără recenzii nu se scrie Product (Google cere offers, review sau aggregateRating)', $faraNimic === [] && $pretZero === []);
$verifica('JSON-LD: doar cu recenzii: Product fără offers, cu aggregateRating', isset($doarRecenzii['aggregateRating']) && !isset($doarRecenzii['offers']));
$verifica('JSON-LD: cu ofertă: Offer în RON, PreOrder, NewCondition', ($cuOferta['offers']['price'] ?? '') === '14.50'
    && ($cuOferta['offers']['priceCurrency'] ?? '') === 'RON' && ($cuOferta['offers']['availability'] ?? '') === 'https://schema.org/PreOrder');
$verifica('JSON-LD: textul nu poate închide eticheta <script>', !str_contains(JsonLd::script($cuOferta), '</script><b>')
    && substr_count(JsonLd::script($cuOferta), '</script>') === 1);

// A6. Livrarea 2 SEO (Bioscem 6c8c789 … 0250480): ce era scris în codul Bioscem stă acum în datele
// site-ului (seo.json); un site fără ele nu declară nimic. Comparația cu codul Bioscem dovedește
// Bioscem; aici, că datele chiar vin din pachet și că un pachet fără ele e neutru.
$setariLivrare = ['shipping_fixed_enabled' => '1', 'shipping_fixed_base' => '19.99', 'shipping_free_bucharest' => '80',
    'shipping_free_province' => '80'];
DateSite::reseteaza($radacina . '/site-uri/bioscem');
$verifica('Bioscem: numele implicit din seo.json, sufixul vechi „BIOSCEM” scos din titluri, prefixul id-urilor din flux',
    SeoMeta::numeMagazinImplicit() === 'Bioscem Shop' && SeoMeta::curataTitlu('Ceai de mentă | BIOSCEM') === 'Ceai de mentă'
    && FluxGoogleMerchant::prefixId() === 'BS-', SeoMeta::curataTitlu('Ceai de mentă | BIOSCEM') . ' / ' . FluxGoogleMerchant::prefixId());
$verifica('Bioscem: returul din seo.json (14 zile, /termeni-conditii, transportul înapoi plătit de client), FAN Courier, fără termen de livrare',
    PoliticaLivrare::activ() && PoliticaLivrare::retur() === ['zile' => 14, 'pagina' => '/termeni-conditii', 'taxe' => 'client']
    && PoliticaLivrare::serviciuAdresa() === 'FAN Courier' && PoliticaLivrare::termenLivrare() === null
    && PoliticaLivrare::linkRetur('https://exemplu.test/') === 'https://exemplu.test/termeni-conditii');
$verifica('Bioscem: livrarea cu prețuri fixe: sub prag tariful la adresă, peste prag gratuit, la reducere sau între praguri nimic',
    PoliticaLivrare::optiuni($setariLivrare, 14.5, false) === [['serviciu' => 'FAN Courier', 'pret' => 19.99]]
    && PoliticaLivrare::optiuni($setariLivrare, 89.0, false) === [['serviciu' => 'FAN Courier', 'pret' => 0.0]]
    && PoliticaLivrare::optiuni($setariLivrare, 169.0, true) === []
    && PoliticaLivrare::optiuni(['shipping_free_province' => '150'] + $setariLivrare, 100.0, false) === []
    && PoliticaLivrare::optiuni(['shipping_fixed_enabled' => '0'] + $setariLivrare, 14.5, false) === []);
$opriri = (static function (): array {
    putenv('SEO_LIVRARE_RETUR=0');
    $livrare = PoliticaLivrare::activ() || PoliticaLivrare::retur() !== [];
    putenv('SEO_LIVRARE_RETUR');
    putenv('AVERTISMENT_PRODUSE=0');
    $avertisment = AvertismentProduse::activ();
    putenv('AVERTISMENT_PRODUSE');
    return [$livrare, $avertisment, PoliticaLivrare::activ(), AvertismentProduse::activ()];
})();
$verifica('opririle de urgență: SEO_LIVRARE_RETUR=0 scoate livrarea și returul, AVERTISMENT_PRODUSE=0 avertismentul; fără ele, pornite',
    $opriri === [false, false, true, true], json_encode($opriri));
// Logo-ul din antet: variantele WebP doar cu PNG-ul de exact mărimea din seo.json și cu ambele fișiere.
$dosarLogo = sys_get_temp_dir() . '/test-seo-logo-' . getmypid();
$antetLogo = '<header><a href="/"><img src="/uploads/gallery/bioscem-logo-2.png" alt="Bioscem" width="212" height="52"></a></header>';
$logo = (static function () use ($dosarLogo, $antetLogo, $radacina): array {
    @mkdir($dosarLogo . '/uploads/gallery', 0700, true);
    @mkdir($dosarLogo . '/assets/img', 0700, true);
    foreach (['bioscem-logo-2-212.webp', 'bioscem-logo-2-423.webp'] as $fisier) {
        copy($radacina . '/site-uri/bioscem/public/assets/img/' . $fisier, $dosarLogo . '/assets/img/' . $fisier);
    }
    $png = static function (int $l, int $i) use ($dosarLogo): void {
        $imagine = imagecreatetruecolor($l, $i);
        imagepng($imagine, $dosarLogo . '/uploads/gallery/bioscem-logo-2.png');
    };
    $png(977, 240);
    $cuWebp = LogoAntet::webp($antetLogo, $dosarLogo);
    $png(500, 240);
    $altLogo = LogoAntet::webp($antetLogo, $dosarLogo);
    $png(977, 240);
    unlink($dosarLogo . '/assets/img/bioscem-logo-2-423.webp');
    $faraVarianta = LogoAntet::webp($antetLogo, $dosarLogo);
    array_map('unlink', glob($dosarLogo . '/*/*/*') ?: []);
    @rmdir($dosarLogo . '/uploads/gallery');
    @rmdir($dosarLogo . '/uploads');
    @rmdir($dosarLogo . '/assets/img');
    @rmdir($dosarLogo . '/assets');
    @rmdir($dosarLogo);
    return [$cuWebp, $altLogo, $faraVarianta];
})();
$verifica('Bioscem: logo-ul din antet primește <picture> cu cele două WebP; alt PNG sau o variantă lipsă îl lasă neatins',
    str_contains($logo[0], '<picture style="display:contents"><source type="image/webp" srcset="/assets/img/bioscem-logo-2-212.webp?v=')
    && str_contains($logo[0], ' 2x"><img src="/uploads/gallery/bioscem-logo-2.png"') && $logo[1] === $antetLogo && $logo[2] === $antetLogo,
    $logo[0]);
DateSite::reseteaza();
$verifica('pachetul demo (fără livrare_retur, logo_antet, flux_merchant): nimic declarat, prefixul implicit „P-”, numele din seo.json',
    !PoliticaLivrare::activ() && PoliticaLivrare::retur() === [] && PoliticaLivrare::optiuni($setariLivrare, 14.5, false) === []
    && FluxGoogleMerchant::prefixId() === 'P-' && LogoAntet::webp($antetLogo) === $antetLogo
    && SeoMeta::numeMagazinImplicit() === 'Firma Demo din pachet' && SeoMeta::curataTitlu('Ceai | BIOSCEM') === 'Ceai | BIOSCEM');
$verifica('fără datele unui site: numele implicit din config (APP_NAME), nu „Bioscem Shop”', (static function () use ($radacina): bool {
    DateSite::reseteaza($radacina . '/storage/nu-exista-' . getmypid());
    $config = require $radacina . '/config/app.php';
    $ok = SeoMeta::numeMagazinImplicit() === trim((string) ($config['name'] ?? '')) && !PoliticaLivrare::activ()
        && \App\Support\ImagineDistribuire::implicita([], 'https://exemplu.test') === null;
    DateSite::reseteaza();
    return $ok;
})());

// =====================================================================
// Serverul de dezvoltare și cererile
// =====================================================================
$port = (int) (getenv('CMS_DEV_PORT') ?: 8701);
/**
 * O cerere HTTP fără redirecționări urmate. $antete: ['Host: …', …].
 *
 * @return array{cod: int, antete: array<string, string>, cookies: list<string>, corp: string}
 */
$cerere = static function (int $port, string $metoda, string $cale, array $antete = [], ?string $jar = null, array $date = []): array {
    $c = curl_init('http://127.0.0.1:' . $port . $cale);
    $antetePrimite = [];
    $cookies = [];
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metoda,
        CURLOPT_NOBODY => $metoda === 'HEAD',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOPROXY => '*',
        CURLOPT_PROXY => '',
        CURLOPT_TIMEOUT => 60,
        CURLOPT_PATH_AS_IS => true,
        CURLOPT_HTTPHEADER => $antete,
        CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$antetePrimite, &$cookies): int {
            if (str_contains($linie, ':')) {
                [$nume, $valoare] = explode(':', $linie, 2);
                $nume = strtolower(trim($nume));
                $antetePrimite[$nume] = trim($valoare);
                if ($nume === 'set-cookie') {
                    $cookies[] = trim($valoare);
                }
            }
            return strlen($linie);
        },
    ]);
    if ($metoda === 'POST') {
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($date));
    }
    if ($jar !== null) {
        curl_setopt($c, CURLOPT_COOKIEFILE, $jar);
        curl_setopt($c, CURLOPT_COOKIEJAR, $jar);
    }
    $corp = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $cod, 'antete' => $antetePrimite, 'cookies' => $cookies, 'corp' => $corp];
};
$locatie = static fn (array $r): string => (string) ($r['antete']['location'] ?? '');

$amPornitServerul = false;
if ($cerere($port, 'GET', '/health')['cod'] !== 200) {
    exec(escapeshellarg($radacina . '/tools/dev/server.sh') . ' 2>&1', $iesire, $codServer);
    if ($codServer !== 0) {
        $scrie('EROARE: serverul nu pornește: ' . implode(' ', $iesire));
        exit(1);
    }
    $amPornitServerul = true;
}
/** Un server în plus, cu alt mediu (APP_URL, SEO_REDIRECTURI…), pe portul dat. */
$serverePornite = [];
$pornesteServer = static function (int $portNou, array $mediu) use ($radacina, $dosarDev, $cerere, &$serverePornite): bool {
    $ini = [];
    foreach (['pdo_mysql.default_socket', 'error_reporting', 'display_errors', 'log_errors', 'error_log', 'sendmail_path', 'memory_limit',
        'allow_url_fopen'] as $cheie) {
        $valoare = ini_get($cheie);
        if ($valoare !== false && $valoare !== '') {
            $ini[] = '-d ' . escapeshellarg($cheie . '=' . $valoare);
        }
    }
    if (!in_array('-d ' . escapeshellarg('allow_url_fopen=0'), $ini, true)) {
        $ini[] = '-d allow_url_fopen=0';
    }
    $env = 'PHP_CLI_SERVER_WORKERS=2';
    foreach ($mediu as $nume => $valoare) {
        $env .= ' ' . $nume . '=' . escapeshellarg((string) $valoare);
    }
    $comanda = 'cd ' . escapeshellarg($radacina) . ' && env ' . $env . ' nohup ' . escapeshellarg(PHP_BINARY) . ' ' . implode(' ', $ini)
        . ' -S 127.0.0.1:' . $portNou . ' -t public tools/dev/router.php >> ' . escapeshellarg($dosarDev . '/server-seo-' . $portNou . '.log')
        . ' 2>&1 & echo $!';
    $pid = (int) trim((string) shell_exec($comanda));
    $serverePornite[$portNou] = $pid;
    for ($i = 0; $i < 50; $i++) {
        if ($cerere($portNou, 'GET', '/health')['cod'] === 200) {
            return true;
        }
        usleep(200000);
    }
    return false;
};
$opresteServerele = static function () use (&$serverePornite): void {
    foreach ($serverePornite as $pid) {
        if ($pid > 0) {
            // Cu tot cu procesele de lucru: pe PHP 8.0 nu se opresc odată cu procesul principal.
            exec('pkill -TERM -P ' . (int) $pid . ' 2>/dev/null; kill ' . (int) $pid . ' 2>/dev/null');
            for ($i = 0; $i < 50 && trim((string) shell_exec('pgrep -P ' . (int) $pid . ' 2>/dev/null')) !== ''; $i++) {
                usleep(100000);
            }
        }
    }
    $serverePornite = [];
};

$app = require $radacina . '/config/app.php';
$db = Database::connection((array) $app['db']);
if (!$db instanceof PDO || (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn() < 10) {
    $scrie('EROARE: baza de test nu are datele demo; rulează întâi tools/dev/instaleaza.sh');
    exit(1);
}
$unu = static fn (string $sql): string => (string) ($db->query($sql)->fetchColumn() ?: '');
$parola = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));
$adminEmail = (string) (getenv('CMS_DEV_ADMIN_EMAIL') ?: 'admin@exemplu.ro');
$idMenta = $unu("SELECT id FROM products WHERE slug = 'ceai-de-menta'");
$licenta = static function (string ...$argumente) use ($radacina): string {
    exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' tools/dev/licenta.php ' . implode(' ', array_map('escapeshellarg', $argumente)) . ' 2>&1',
        $iesire, $cod);
    if ($cod !== 0) {
        throw new RuntimeException('licența nu s-a pus: ' . implode(' ', $iesire));
    }
    Plugins::reseteaza();
    Plugins::stari();
    return implode(' ', $iesire);
};
$fisierErori = $dosarDev . '/php-erori.log';
$pozitieErori = is_file($fisierErori) ? (int) filesize($fisierErori) : 0;
$jaruri = [];
$jarNou = static function (string $nume) use ($dosarDev, &$jaruri): string {
    $jar = $dosarDev . '/test-seo-' . $nume . '-' . getmypid() . '.txt';
    @unlink($jar);
    $jaruri[] = $jar;
    return $jar;
};

try {
    $licenta('toate');

    // =================================================================
    // B. Pe serverul de dezvoltare (fără regula de gazdă: APP_URL e pe http)
    // =================================================================
    $scrie('');
    $scrie('== B. Adresa unică și harta (server de dezvoltare, licența „toate”)');
    $asteptari = [
        // [cale, cod, Location]
        ['/produs/ceai-de-menta/', 301, '/produs/ceai-de-menta'],
        ['//produs//ceai-de-menta', 301, '/produs/ceai-de-menta'],
        ['/produs/CEAI-DE-MENTA', 301, '/produs/ceai-de-menta'],
        ['/produs/CEAI-DE-MENTA?gclid=a1', 301, '/produs/ceai-de-menta?gclid=a1'],
        ['/categorie/CEAIURI', 301, '/categorie/ceaiuri'],
        ['/categorie/ceaiuri/', 301, '/categorie/ceaiuri'],
        ['/marca/Livada-Demo', 301, '/marca/livada-demo'],
        ['/eticheta/DEMO', 301, '/eticheta/demo'],
        ['/Termeni-Si-Conditii', 301, '/termeni-si-conditii'],
        ['/termeni-si-conditii/', 301, '/termeni-si-conditii'],
        ['/magazin/?sort=price_asc&utm_source=x', 301, '/magazin?sort=price_asc&utm_source=x'],
        ['/blog/', 301, '/blog'],
        ['/', 200, ''],
        ['/magazin', 200, ''],
        ['/produs/ceai-de-menta', 200, ''],
        ['/magazin?sort=price_asc', 200, ''],
        ['/eticheta/nu-exista/', 404, ''],
        ['/pagina-care-nu-exista/', 404, ''],
        // Harta demo (tools/dev/site-demo/redirecturi.json).
        ['/produse', 301, '/magazin'],
        ['/produse/?gclid=x1&utm_source=google', 301, '/magazin?gclid=x1&utm_source=google'],
        ['/PRODUSE', 301, '/magazin'],
        ['/brand/livada-demo/', 301, '/marca/livada-demo'],
        ['/articol-vechi-despre-ceai', 301, '/categorie/ceaiuri'],
        ['/produs/ceai-menta-vechi', 301, '/produs/ceai-de-menta'],
        ['/cart', 301, '/cos'],
        ['/jurnal-vechi', 301, '/blog'],
        ['/despre-firma-veche', 301, '/contact'],
        ['/Majuscule-Veche/', 301, '/contact'],
        ['/lant-a', 301, '/magazin'],
        ['/bucla-a', 404, ''],
        ['/bucla-b', 404, ''],
        ['/spre-disparut', 404, ''],
        ['/disparut', 410, ''],
        ['/sine', 404, ''],
        ['/termeni-si-conditii', 200, ''],
        ['/tag/js', 410, ''],
        ['/tag/js/', 410, ''],
        ['/wp-admin/admin-ajax.php', 410, ''],
        ['/wp-content/plugins/x/y.js?ver=1', 410, ''],
        ['/tinta-straina', 404, ''],
        ['/tinta-doua-bare', 404, ''],
        ['/zona-privata-demo/pagina', 404, ''],
    ];
    $gresite = [];
    $fara = ['cookie' => [], 'cache' => []];
    foreach ($asteptari as [$cale, $cod, $spre]) {
        $r = $cerere($port, 'GET', $cale);
        if ($r['cod'] !== $cod || $locatie($r) !== $spre) {
            $gresite[] = $cale . ' → ' . $r['cod'] . ' ' . $locatie($r) . ' (așteptat ' . $cod . ' ' . $spre . ')';
        }
        if ($cod === 301) {
            if ($r['cookies'] !== []) {
                $fara['cookie'][] = $cale . ': ' . implode(' | ', $r['cookies']);
            }
            if (($r['antete']['cache-control'] ?? '') !== 'no-store') {
                $fara['cache'][] = $cale . ': ' . ($r['antete']['cache-control'] ?? '-');
            }
        }
    }
    $verifica(count($asteptari) . ' adrese: „/” final, bare duble, majuscule, harta (lanț, buclă, 410, țintă străină, pagină existentă), '
        . 'parametrii păstrați, 404 fără 301 spre 404', $gresite === [], implode("\n          ", $gresite));
    $verifica('fiecare 301: fără Set-Cookie pentru un vizitator nou', $fara['cookie'] === [], implode('; ', $fara['cookie']));
    $verifica('fiecare 301: Cache-Control: no-store', $fara['cache'] === [], implode('; ', $fara['cache']));
    $r = $cerere($port, 'GET', '/checkout/vechi');
    $verifica('o adresă veche pe o cale exclusă (/checkout/vechi) nu e redirecționată de hartă', !($r['cod'] === 301 && $locatie($r) === '/magazin'),
        $r['cod'] . ' ' . $locatie($r));

    $jarSesiune = $jarNou('sesiune');
    $cerere($port, 'POST', '/cos/adauga/' . $idMenta, [], $jarSesiune, ['quantity' => '1']);
    $r = $cerere($port, 'GET', '/produse?gclid=TestSeo1', [], $jarSesiune);
    $verifica('cine are sesiune își primește cookie-urile pe 301 (bv_gclid)', $r['cod'] === 301
        && array_filter($r['cookies'], static fn ($c) => str_starts_with($c, 'bv_gclid=TestSeo1')) !== [], implode(' | ', $r['cookies']));
    $r = $cerere($port, 'GET', '/produse?gclid=TestSeo2');
    $verifica('vizitator nou: 301 fără cookie, cu gclid păstrat în adresă', $r['cod'] === 301 && $r['cookies'] === []
        && $locatie($r) === '/magazin?gclid=TestSeo2');

    $h1 = $cerere($port, 'HEAD', '/produs/ceai-de-menta/');
    $h2 = $cerere($port, 'HEAD', '/produs/ceai-de-menta');
    // Pe adresa corectă stratul nu face nimic: HEAD rămâne ca înainte (routerul n-are rute HEAD, deci 404, moștenit).
    $verifica('HEAD: 301 pe „/” final; pe adresa corectă neschimbat (404 moștenit: routerul n-are rute HEAD)',
        $h1['cod'] === 301 && $locatie($h1) === '/produs/ceai-de-menta' && $h2['cod'] === 404, $h1['cod'] . ' ' . $h2['cod']);
    $p1 = $cerere($port, 'POST', '/produse');
    $p2 = $cerere($port, 'POST', '/produs/ceai-de-menta/');
    $p3 = $cerere($port, 'POST', '/cos/adauga/' . $idMenta . '/', [], $jarNou('post'), ['quantity' => '1']);
    $p4 = $cerere($port, 'POST', '/cos/adauga/' . $idMenta, [], $jarNou('post2'), ['quantity' => '1']);
    $verifica('POST nu e atins: nici harta, nici „/” final (adăugarea în coș cu „/” răspunde ca fără)',
        $p1['cod'] !== 301 && $p2['cod'] !== 301 && $p3['cod'] === $p4['cod'] && $locatie($p3) === $locatie($p4),
        $p1['cod'] . ' ' . $p2['cod'] . ' ' . $p3['cod'] . ' ' . $locatie($p3) . ' / ' . $p4['cod'] . ' ' . $locatie($p4));

    // Sitemap-ul: un fișier vechi pe disc nu-l acoperă; butonul din admin îl șterge.
    $fisierVechi = $radacina . '/public/sitemap.xml';
    $existaInainte = is_file($fisierVechi) ? (string) file_get_contents($fisierVechi) : null;
    file_put_contents($fisierVechi, "<?xml version=\"1.0\"?><urlset><!-- sitemap vechi test-seo --></urlset>\n");
    $s = $cerere($port, 'GET', '/sitemap.xml');
    $verifica('un public/sitemap.xml vechi nu acoperă sitemap-ul construit din bază', $s['cod'] === 200
        && !str_contains($s['corp'], 'sitemap vechi test-seo') && str_contains($s['corp'], '<urlset'));
    $jarAdmin = $jarNou('admin');
    $cerere($port, 'POST', '/admin/login', [], $jarAdmin, ['email' => $adminEmail, 'password' => $parola]);
    $g = $cerere($port, 'POST', '/admin/settings/store', [], $jarAdmin, ['action' => 'generate_sitemap', 'sitemap_filename' => 'sitemap.xml']);
    $verifica('butonul „Generează sitemap” șterge fișierul vechi și nu mai scrie unul static', $g['cod'] === 302 && !is_file($fisierVechi));
    if ($existaInainte !== null) {
        file_put_contents($fisierVechi, $existaInainte);
    }

    // Mentenanța: robots.txt normal, paginile 503, sitemap-ul și confirmările merg.
    $mentenantaInainte = (string) (Settings::all($db)['maintenance_enabled'] ?? '0');
    Settings::save($db, ['maintenance_enabled' => '1']);
    ResponseCache::purgePageCache();
    $robotsM = $cerere($port, 'GET', '/robots.txt');
    $acasaM = $cerere($port, 'GET', '/');
    $slashM = $cerere($port, 'GET', '/produs/ceai-de-menta/');
    $sitemapM = $cerere($port, 'GET', '/sitemap.xml');
    $webhookM = $cerere($port, 'POST', '/webhook/stripe');
    Settings::save($db, ['maintenance_enabled' => $mentenantaInainte]);
    ResponseCache::purgePageCache();
    $verifica('mentenanță: robots.txt rămâne cel normal (nu „Disallow: /”)', $robotsM['cod'] === 200
        && !str_contains($robotsM['corp'], "Disallow: /\n") && str_contains($robotsM['corp'], 'Disallow: /admin'));
    $verifica('mentenanță: paginile 503 cu Retry-After, fără 301 (nici pe „/” final); sitemap 200; webhook-ul răspunde',
        $acasaM['cod'] === 503 && isset($acasaM['antete']['retry-after']) && $slashM['cod'] === 503 && $sitemapM['cod'] === 200
        && in_array($webhookM['cod'], [400, 401, 403], true),
        $acasaM['cod'] . ' ' . $slashM['cod'] . ' ' . $sitemapM['cod'] . ' ' . $webhookM['cod']);

    // ----- Pe fiecare pachet: sitemap, robots, JSON-LD, harta cu ținte ascunse -----
    $produseActive = array_map('strval', $db->query("SELECT slug FROM products WHERE is_active = 1 AND deleted_at IS NULL AND TRIM(COALESCE(slug,'')) <> ''")
        ->fetchAll(PDO::FETCH_COLUMN));
    $produseAscunse = array_map('strval', $db->query('SELECT slug FROM products WHERE is_active = 0 OR deleted_at IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
    $jsonLd = static function (string $html): array {
        $noduri = [];
        if (preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m) > 0) {
            foreach ($m[1] as $json) {
                $nod = json_decode($json, true);
                $noduri[] = is_array($nod) ? $nod : ['@type' => 'JSON invalid'];
            }
        }
        return $noduri;
    };
    $tipuri = static fn (array $noduri): array => array_map(static fn (array $n): string => (string) ($n['@type'] ?? '?'), $noduri);
    $nodTip = static function (array $noduri, string $tip): ?array {
        foreach ($noduri as $n) {
            if (($n['@type'] ?? '') === $tip) {
                return $n;
            }
        }
        return null;
    };
    $scenarii = [
        'toate' => ['toate'],
        'magazin' => ['magazin'],
        'catalog' => ['catalog'],
        'catalog + recenzii' => ['catalog', 'recenzii=pornit'],
        'prezentare' => ['prezentare'],
        'asociatie' => ['asociatie'],
        'toate, magazinul se oprește' => ['toate', 'magazin=se_opreste'],
        'bioscem' => ['bioscem'],
    ];
    foreach ($scenarii as $numeScenariu => $argumente) {
        $scrie('');
        $scrie('-- Pachetul „' . $numeScenariu . '”');
        $licenta(...$argumente);
        $cuCatalog = Plugins::vizibil('catalog');
        $cuBlog = Plugins::vizibil('blog');
        $cuOferta = Plugins::permiteInceput('magazin');
        $cuRecenzii = Plugins::vizibil('recenzii');

        // Sitemap.
        $s = $cerere($port, 'GET', '/sitemap.xml');
        preg_match_all('#<loc>(.*?)</loc>#', $s['corp'], $m);
        $locuri = array_map(static fn ($l) => html_entity_decode((string) $l, ENT_QUOTES | ENT_XML1, 'UTF-8'), $m[1]);
        $cai = array_map(static fn (string $l): string => (string) substr($l, strlen('http://127.0.0.1:' . $port)), $locuri);
        $verifica('sitemap: 200, XML, noindex, ' . count($cai) . ' adrese, fără dubluri, toate pe ' . 'http://127.0.0.1:' . $port,
            $s['cod'] === 200 && str_starts_with((string) ($s['antete']['content-type'] ?? ''), 'application/xml')
            && ($s['antete']['x-robots-tag'] ?? '') === 'noindex' && $cai !== [] && count(array_unique($cai)) === count($cai)
            && array_filter($locuri, static fn ($l) => !str_starts_with($l, 'http://127.0.0.1:' . $port . '/')) === []);
        $proaste = [];
        foreach ($cai as $cale) {
            if (!Plugins::adresaVizibila($cale)) {
                $proaste[] = $cale . ' (plugin ascuns)';
            }
            if ($cale !== '/' && Robots::blocheaza($cale)) {
                $proaste[] = $cale . ' (blocată în robots.txt)';
            }
            $r = $cerere($port, 'GET', $cale);
            if ($r['cod'] !== 200 || $locatie($r) !== '') {
                $proaste[] = $cale . ' → ' . $r['cod'] . ' ' . $locatie($r);
            }
        }
        $verifica('sitemap: fiecare adresă răspunde 200, fără redirecționare, niciuna a unui plugin ascuns sau blocată', $proaste === [],
            implode(', ', array_slice($proaste, 0, 10)));
        $produseInHarta = array_values(array_filter($cai, static fn ($c) => str_starts_with($c, '/produs/')));
        $asteptateProduse = $cuCatalog ? array_map(static fn ($s) => '/produs/' . rawurlencode($s), $produseActive) : [];
        sort($produseInHarta);
        sort($asteptateProduse);
        $verifica('sitemap: ' . ($cuCatalog ? 'toate cele ' . count($asteptateProduse) . ' produse active, niciunul inactiv sau șters'
            : 'niciun produs (fără catalog)'), $produseInHarta === $asteptateProduse
            && array_intersect($cai, array_map(static fn ($s) => '/produs/' . rawurlencode($s), $produseAscunse)) === []);
        $verifica('sitemap: /magazin, categoriile, mărcile, etichetele doar cu catalogul', in_array('/magazin', $cai, true) === $cuCatalog
            && in_array('/categorie/ceaiuri', $cai, true) === $cuCatalog && in_array('/marca/livada-demo', $cai, true) === $cuCatalog
            && in_array('/eticheta/demo', $cai, true) === $cuCatalog);
        $verifica('sitemap: etichetele după seo.json (sub minim doar cele păstrate; cele excluse niciodată)',
            in_array('/eticheta/precomanda', $cai, true) === $cuCatalog && !in_array('/eticheta/reducere', $cai, true));
        $articole = array_filter($cai, static fn ($c) => str_starts_with($c, '/blog/'));
        $verifica('sitemap: /blog și articolele doar cu blogul', in_array('/blog', $cai, true) === $cuBlog && ($articole !== []) === $cuBlog);
        $sistem = array_intersect($cai, ['/cos', '/checkout', '/checkout/succes', '/contul-meu', '/login', '/register', '/404', '/acorduri-gdpr',
            '/pagina-sistem-demo']);
        $verifica('sitemap: nicio pagină de sistem (coș, checkout, cont, login, 404, acorduri GDPR); prima pagină și /contact da',
            $sistem === [] && in_array('/', $cai, true) && in_array('/contact', $cai, true), implode(', ', $sistem));

        // robots.txt.
        $robots = $cerere($port, 'GET', '/robots.txt')['corp'];
        $linii = array_values(array_filter(explode("\n", $robots), static fn ($l) => str_starts_with($l, 'Disallow: ')));
        $asteptate = ['Disallow: /admin', 'Disallow: /api/', 'Disallow: /auth/', 'Disallow: /webhook/'];
        if (Plugins::vizibil('magazin')) {
            array_push($asteptate, 'Disallow: /cos$', 'Disallow: /cos?', 'Disallow: /cos/', 'Disallow: /checkout');
        }
        if (Plugins::vizibil('conturi_clienti')) {
            array_push($asteptate, 'Disallow: /contul-meu', 'Disallow: /login', 'Disallow: /register');
        }
        if (Plugins::vizibil('link_plata')) {
            $asteptate[] = 'Disallow: /plata/';
        }
        if (Plugins::vizibil('newsletter')) {
            $asteptate[] = 'Disallow: /newsletter/';
        }
        if (Plugins::vizibil('acorduri_gdpr')) {
            $asteptate[] = 'Disallow: /gdpr-agreements';
        }
        $asteptate[] = 'Disallow: /pagina-sistem-demo';
        $verifica('robots.txt: exact regulile pluginurilor de pe site + cea din seo.json, Sitemap: …/sitemap.xml', $linii === $asteptate
            && str_contains($robots, "\nSitemap: http://127.0.0.1:" . $port . "/sitemap.xml\n"), implode(' | ', $linii));
        // Livrarea 2 SEO: fluxul Merchant Center (magazin) și adresele vechi ale categoriilor (catalog).
        $permise = array_values(array_filter(explode("\n", $robots), static fn ($l) => str_starts_with($l, 'Allow: ')));
        $cuMagazin = Plugins::vizibil('magazin');
        $verifica('robots.txt: „Allow: /feed/google-merchant.xml” ' . ($cuMagazin ? 'da, înaintea regulilor Disallow' : 'nu (fără magazin)'),
            $permise === ($cuMagazin ? ['Allow: /feed/google-merchant.xml'] : [])
            && (!$cuMagazin || strpos($robots, 'Allow: ') < strpos($robots, 'Disallow: ')), implode(' | ', $permise));
        $flux = $cerere($port, 'GET', '/feed/google-merchant.xml');
        preg_match_all('#<item>#', $flux['corp'], $itemi);
        $verifica('fluxul Merchant Center: ' . ($cuMagazin ? '200, XML, noindex, ' . count($itemi[0]) . ' produse' . ($cuOferta ? '' : ' (magazinul se oprește: niciunul)')
            : '404 (fără magazin)'), $cuMagazin
            ? $flux['cod'] === 200 && str_starts_with((string) ($flux['antete']['content-type'] ?? ''), 'application/xml')
                && ($cuOferta ? count($itemi[0]) > 0 : count($itemi[0]) === 0)
            : $flux['cod'] === 404, $flux['cod'] . ' ' . ($flux['antete']['content-type'] ?? '-'));
        $veche = $cerere($port, 'GET', '/magazin?category=Ceaiuri&sort=price_asc&gclid=TestSeo3');
        $verifica('/magazin?category=Ceaiuri: ' . ($cuCatalog ? 'un 301 la /categorie/ceaiuri (cu sortarea și gclid)' : '404 (fără catalog)'),
            $cuCatalog ? $veche['cod'] === 301 && $locatie($veche) === '/categorie/ceaiuri?sort=price_asc&gclid=TestSeo3' : $veche['cod'] === 404,
            $veche['cod'] . ' ' . $locatie($veche));

        // JSON-LD: prima pagină (nucleu).
        $acasa = $jsonLd($cerere($port, 'GET', '/')['corp']);
        $org = $nodTip($acasa, 'Organization');
        $verifica('JSON-LD prima pagină: Organization + WebSite (nucleu), numele din setări, logo-ul pătrat din seo.json',
            $tipuri($acasa) === ['Organization', 'WebSite'] && ($org['name'] ?? '') === 'Firma Demo'
            && ($org['logo'] ?? '') === 'http://127.0.0.1:' . $port . '/uploads/demo/ceai-de-menta.png', json_encode($tipuri($acasa)));
        if ($cuCatalog) {
            $menta = $jsonLd($cerere($port, 'GET', '/produs/ceai-de-menta')['corp']);
            $chia = $jsonLd($cerere($port, 'GET', '/produs/seminte-de-chia')['corp']);
            $zinc = $jsonLd($cerere($port, 'GET', '/produs/zinc-15-mg')['corp']);
            $epuizat = $jsonLd($cerere($port, 'GET', '/produs/ceai-de-fructe-de-padure')['corp']);
            $inactiv = $jsonLd($cerere($port, 'GET', '/produs/ceai-rooibos')['corp']);
            $neaprobat = $jsonLd($cerere($port, 'GET', '/produs/vitamina-c-500-mg')['corp']);
            $categorie = $jsonLd($cerere($port, 'GET', '/categorie/ceaiuri')['corp']);
            $pMenta = $nodTip($menta, 'Product');
            $areProdusMenta = $cuOferta || $cuRecenzii;
            $verifica('JSON-LD produs cu recenzie aprobată: Product ' . ($areProdusMenta ? 'da' : 'nu') . ', Offer ' . ($cuOferta ? 'da' : 'nu')
                . ', stele ' . ($cuRecenzii ? 'da' : 'nu') . ', firimituri',
                ($pMenta !== null) === $areProdusMenta && isset($pMenta['offers']) === $cuOferta
                && isset($pMenta['aggregateRating']) === $cuRecenzii && $nodTip($menta, 'BreadcrumbList') !== null,
                json_encode($menta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $pChia = $nodTip($chia, 'Product');
            $verifica('JSON-LD produs fără recenzii: Product doar cu ofertă (' . ($cuOferta ? 'da' : 'nu') . '), fără stele inventate',
                ($pChia !== null) === $cuOferta && !isset($pChia['aggregateRating']) && !isset($pChia['review']));
            if ($cuOferta) {
                // Precomanda doar cu pluginul ei; fără el, ca pe pagină: după bifa „epuizat” a produsului.
                $zincEpuizat = $unu("SELECT out_of_stock FROM products WHERE slug = 'zinc-15-mg'") === '1';
                $dispZinc = Plugins::permiteInceput('precomenzi') ? 'PreOrder' : ($zincEpuizat ? 'OutOfStock' : 'InStock');
                $verifica('JSON-LD Offer: preț din pagină, RON, InStock; produsul la precomandă ' . $dispZinc . '; epuizatul OutOfStock',
                    ($pMenta['offers']['price'] ?? '') === '14.50' && ($pMenta['offers']['priceCurrency'] ?? '') === 'RON'
                    && ($pMenta['offers']['availability'] ?? '') === 'https://schema.org/InStock'
                    && ($nodTip($zinc, 'Product')['offers']['availability'] ?? '') === 'https://schema.org/' . $dispZinc
                    && ($nodTip($epuizat, 'Product')['offers']['availability'] ?? '') === 'https://schema.org/OutOfStock');
            }
            $verifica('JSON-LD: produsul inactiv fără Product; recenzia neaprobată nu dă stele',
                $nodTip($inactiv, 'Product') === null && !isset($nodTip($neaprobat, 'Product')['aggregateRating']));
            $verifica('JSON-LD categorie: doar firimiturile (Acasă → categoria)', $tipuri($categorie) === ['BreadcrumbList']
                && count((array) ($categorie[0]['itemListElement'] ?? [])) === 2);
        }

        // Harta: ținte ale pluginurilor ascunse rămân 404.
        $hartaPlugin = [
            ['/produse', '/magazin', $cuCatalog],
            ['/cart', '/cos', Plugins::vizibil('magazin')],
            ['/jurnal-vechi', '/blog', $cuBlog],
            ['/despre-firma-veche', '/contact', true],
        ];
        $gresite = [];
        foreach ($hartaPlugin as [$din, $spre, $vizibila]) {
            $r = $cerere($port, 'GET', $din);
            if ($vizibila ? ($r['cod'] !== 301 || $locatie($r) !== $spre) : $r['cod'] !== 404) {
                $gresite[] = $din . ' → ' . $r['cod'] . ' ' . $locatie($r);
            }
        }
        $verifica('harta: o țintă a unui plugin ascuns rămâne 404, restul 301', $gresite === [], implode(', ', $gresite));
    }
    $licenta('toate');

    // =================================================================
    // C. Gazda canonică (APP_URL=https://exemplu-demo.test)
    // =================================================================
    $scrie('');
    $scrie('== C. Gazda: www → https://exemplu-demo.test (al doilea server, APP_URL inventat)');
    $portGazda = $port + 2;
    $canonic = 'https://exemplu-demo.test';
    if (!$pornesteServer($portGazda, ['APP_URL' => $canonic])) {
        throw new RuntimeException('serverul cu APP_URL ' . $canonic . ' nu pornește (portul ' . $portGazda . ')');
    }
    $www = ['Host: www.exemplu-demo.test'];
    $wwwHttps = ['Host: www.exemplu-demo.test', 'X-Forwarded-Proto: https'];
    $corect = ['Host: exemplu-demo.test', 'X-Forwarded-Proto: https'];
    $corectHttp = ['Host: exemplu-demo.test'];
    $gazde = [
        ['www, https, produs', $wwwHttps, '/produs/ceai-de-menta', $canonic . '/produs/ceai-de-menta', 200],
        ['www, http, produs', $www, '/produs/ceai-de-menta', $canonic . '/produs/ceai-de-menta', 200],
        ['www, prima pagină', $www, '/', $canonic . '/', 200],
        ['www + „/” final + gclid', $www, '/produs/ceai-de-menta/?gclid=TeSt1&utm_source=google',
            $canonic . '/produs/ceai-de-menta?gclid=TeSt1&utm_source=google', 200],
        ['www + adresă din hartă', $www, '/produse/', $canonic . '/magazin', 200],
        ['www + litere mari', $www, '/produs/CEAI-DE-MENTA', $canonic . '/produs/ceai-de-menta', 200],
        ['www + produs găsit aproximativ', $www, '/produs/ceai-menta', null, null],
        ['www + pagină inexistentă', $www, '/nu-exista-asa-ceva', $canonic . '/nu-exista-asa-ceva', 404],
        ['gazda corectă + „/” final', $corectHttp, '/magazin/', $canonic . '/magazin', 200],
        // Bioscem 919357a: categoria veche pe www sau cu „/” final, într-un singur salt, fără ?category= lipit înapoi.
        ['www + categorie veche', $www, '/magazin?category=Ceaiuri', $canonic . '/categorie/ceaiuri', 200],
        ['www + categorie veche + „/” final + gclid', $www, '/magazin/?category=Ceaiuri&gclid=TeSt2',
            $canonic . '/categorie/ceaiuri?gclid=TeSt2', 200],
    ];
    $gresite = [];
    foreach ($gazde as [$eticheta, $antete, $cale, $spre, $codFinal]) {
        $r = $cerere($portGazda, 'GET', $cale, $antete);
        if ($spre === null) {
            // Potrivirea aproximativă a aplicației: un singur salt, pe gazda corectă.
            if ($r['cod'] !== 301 || !str_starts_with($locatie($r), $canonic . '/produs/')) {
                $gresite[] = $eticheta . ': ' . $r['cod'] . ' ' . $locatie($r);
            }
            continue;
        }
        if ($r['cod'] !== 301 || $locatie($r) !== $spre || $r['cookies'] !== [] || ($r['antete']['cache-control'] ?? '') !== 'no-store') {
            $gresite[] = $eticheta . ': ' . $r['cod'] . ' ' . $locatie($r) . ' cookie: ' . implode('|', $r['cookies']);
            continue;
        }
        $urmatoarea = $cerere($portGazda, 'GET', (string) substr($spre, strlen($canonic)), $corect);
        if ($urmatoarea['cod'] !== $codFinal) {
            $gresite[] = $eticheta . ': după salt ' . $urmatoarea['cod'] . ' ' . $locatie($urmatoarea);
        }
    }
    $verifica(count($gazde) . ' variante: un singur 301 la https://exemplu-demo.test, fără cookie, no-store, apoi pagina', $gresite === [],
        implode("\n          ", $gresite));
    $h = $cerere($portGazda, 'HEAD', '/magazin', $www);
    $verifica('HEAD pe www → 301', $h['cod'] === 301 && $locatie($h) === $canonic . '/magazin');
    $faraBucla = [];
    foreach (['/', '/magazin', '/produs/ceai-de-menta', '/categorie/ceaiuri'] as $cale) {
        foreach ([$corectHttp, $corect, ['Host: exemplu-demo.test', 'X-Forwarded-Proto: http'], ['Host: exemplu-demo.test', 'X-Forwarded-Ssl: off']] as $antete) {
            $r = $cerere($portGazda, 'GET', $cale, $antete);
            if ($r['cod'] !== 200) {
                $faraBucla[] = $cale . ' [' . implode(', ', $antete) . '] → ' . $r['cod'] . ' ' . $locatie($r);
            }
        }
    }
    $verifica('gazda corectă, pe http sau https, cu orice antet de schemă: 200 direct (nicio buclă posibilă)', $faraBucla === [],
        implode('; ', $faraBucla));
    $necunoscute = [];
    foreach (['127.0.0.1:' . $portGazda, 'adresa-tehnica.gazduire.test', 'exemplu-demo.test.altceva.test'] as $gazda) {
        $r = $cerere($portGazda, 'GET', '/magazin', ['Host: ' . $gazda]);
        if ($r['cod'] !== 200) {
            $necunoscute[] = $gazda . ' → ' . $r['cod'];
        }
    }
    $verifica('o gazdă necunoscută (IP, adresa tehnică) rămâne neatinsă', $necunoscute === [], implode('; ', $necunoscute));

    // Fiecare rută exclusă (GET) și fiecare POST, pe www: exact ca pe gazda corectă.
    $comparate = 0;
    $diferente = [];
    $jarW = $jarNou('w');
    $jarC = $jarNou('c');
    $cereriExcluse = [];
    foreach ($router->rute() as $ruta) {
        $cale = $caleConcreta($ruta['tipar'], $ruta['metoda'] === 'GET' ? 'test-seo' : '999999');
        if ($ruta['metoda'] === 'GET' && !SeoRedirects::eExclusa($cale)) {
            continue;
        }
        $cereriExcluse[] = [$ruta['metoda'], $cale];
        $cereriExcluse[] = [$ruta['metoda'], $cale . '/'];
    }
    foreach ($mereuDeschise as $cale) {
        $cereriExcluse[] = ['GET', $cale];
        $cereriExcluse[] = ['POST', $cale];
    }
    foreach ($cereriExcluse as [$metoda, $cale]) {
        $a = $cerere($portGazda, $metoda, $cale, $www, $jarW);
        $b = $cerere($portGazda, $metoda, $cale, $corectHttp, $jarC);
        $comparate++;
        $locA = (string) preg_replace('#^https?://(www\.)?exemplu-demo\.test#', '', $locatie($a));
        $locB = (string) preg_replace('#^https?://(www\.)?exemplu-demo\.test#', '', $locatie($b));
        // Un 301 al stratului SEO ar apărea doar pe www (gazda corectă nu are ce muta).
        if ($a['cod'] !== $b['cod'] || $locA !== $locB) {
            $diferente[] = $metoda . ' ' . $cale . ': www ' . $a['cod'] . ' ' . $locatie($a) . ' / corect ' . $b['cod'] . ' ' . $locatie($b);
        }
    }
    $verifica($comparate . ' cereri pe căile excluse (fiecare POST, fiecare GET exclus, adresele mereu deschise, cu și fără „/”): '
        . 'pe www exact ca pe gazda corectă, nicio redirecționare SEO', $diferente === [], implode("\n          ", array_slice($diferente, 0, 20)));
    $w = [];
    foreach (['/webhook/stripe', '/webhook/euplatesc', '/webhook/bt-ipay', '/api/erp/notificare', '/api/erp/adrese'] as $cale) {
        $w[$cale] = $cerere($portGazda, 'POST', $cale, $wwwHttps)['cod'];
    }
    $verifica('confirmările de plată și ERP pe www (POST, https): răspund, fără 3xx ('
        . implode(', ', array_map(static fn ($k, $v) => $k . ' ' . $v, array_keys($w), $w)) . ')', array_diff($w, [400, 401, 403]) === []);

    // Coșul și contul pe www rămân pe www; vizitatorii noi, cookie-urile inventate și sesiunile goale se mută.
    $jarCos = $jarNou('cos-www');
    $cerere($portGazda, 'POST', '/cos/adauga/' . $idMenta, $www, $jarCos, ['quantity' => '1']);
    $cuCos = $cerere($portGazda, 'GET', '/produs/ceai-de-menta', $www, $jarCos);
    $jarGol = $jarNou('gol-www');
    $cerere($portGazda, 'GET', '/cos', $www, $jarGol);
    $gol = $cerere($portGazda, 'GET', '/produs/ceai-de-menta', $www, $jarGol);
    $inventat = $cerere($portGazda, 'GET', '/produs/ceai-de-menta', array_merge($www, ['Cookie: PHPSESSID=inventat0123456789abcdef']));
    $jarClient = $jarNou('client-www');
    $cerere($portGazda, 'POST', '/login', $www, $jarClient, ['email' => 'andrei.exemplu@exemplu.ro', 'password' => $parola]);
    $client = $cerere($portGazda, 'GET', '/produs/ceai-de-menta', $www, $jarClient);
    $verifica('www: cine are produse în coș sau e logat rămâne pe www; sesiune goală și cookie inventat se mută',
        $cuCos['cod'] === 200 && $client['cod'] === 200 && $gol['cod'] === 301 && $inventat['cod'] === 301,
        'coș ' . $cuCos['cod'] . ', client ' . $client['cod'] . ', gol ' . $gol['cod'] . ', inventat ' . $inventat['cod']);
    $s = $cerere($portGazda, 'GET', '/sitemap.xml', $corect);
    $robots = $cerere($portGazda, 'GET', '/robots.txt', $corect);
    preg_match_all('#<loc>(.*?)</loc>#', $s['corp'], $m);
    $verifica('sitemap și robots.txt pe https://exemplu-demo.test', $m[1] !== []
        && array_filter($m[1], static fn ($l) => !str_starts_with((string) $l, $canonic . '/')) === []
        && str_contains($robots['corp'], 'Sitemap: ' . $canonic . '/sitemap.xml'));
    $p = $jsonLd($cerere($portGazda, 'GET', '/produs/ceai-de-menta', $corect)['corp']);
    $verifica('JSON-LD cu adresele pe https://exemplu-demo.test', ($nodTip($p, 'Product')['url'] ?? '') === $canonic . '/produs/ceai-de-menta');

    // =================================================================
    // D. Oprirea de urgență
    // =================================================================
    $scrie('');
    $scrie('== D. Oprirea de urgență (SEO_REDIRECTURI=0)');
    $portOprit = $port + 3;
    if (!$pornesteServer($portOprit, ['APP_URL' => $canonic, 'SEO_REDIRECTURI' => '0'])) {
        throw new RuntimeException('serverul cu SEO_REDIRECTURI=0 nu pornește (portul ' . $portOprit . ')');
    }
    $o = [
        'www' => $cerere($portOprit, 'GET', '/produs/ceai-de-menta', $www)['cod'],
        'harta' => $cerere($portOprit, 'GET', '/produse', $corect)['cod'],
        '/ final' => $cerere($portOprit, 'GET', '/produs/ceai-de-menta/', $corect)['cod'],
        'majuscule' => $cerere($portOprit, 'GET', '/produs/CEAI-DE-MENTA', $corect)['cod'],
        'categorie' => $cerere($portOprit, 'GET', '/categorie/CEAIURI', $corect)['cod'],
    ];
    $verifica('nicio redirecționare SEO (' . implode(', ', array_map(static fn ($k, $v) => $k . ' ' . $v, array_keys($o), $o)) . ')',
        $o === ['www' => 200, 'harta' => 404, '/ final' => 200, 'majuscule' => 200, 'categorie' => 200]);
    $verifica('sitemap, robots.txt și datele structurate rămân', $cerere($portOprit, 'GET', '/sitemap.xml', $corect)['cod'] === 200
        && str_contains($cerere($portOprit, 'GET', '/', $corect)['corp'], 'application/ld+json'));
} catch (Throwable $e) {
    $verifica('testul a mers până la capăt', false, $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $opresteServerele();
    try {
        $licenta('toate');
    } catch (Throwable) {
    }
    foreach ($jaruri as $jar) {
        @unlink($jar);
    }
    if ($amPornitServerul) {
        exec(escapeshellarg($radacina . '/tools/dev/server.sh') . ' --opreste >/dev/null 2>&1');
    }
}

// Jurnalul PHP: nimic nou.
clearstatcache();
$marime = is_file($fisierErori) ? (int) filesize($fisierErori) : 0;
$noi = $marime > $pozitieErori ? (string) @file_get_contents($fisierErori, false, null, $pozitieErori) : '';
$grave = array_values(array_filter(explode("\n", $noi), static fn (string $l): bool
    => preg_match('/PHP (Warning|Notice|Fatal error|Parse error|Recoverable fatal error)/', $l) === 1));
$scrie('');
$verifica('jurnalul PHP: niciun avertisment sau eroare nouă', $grave === [], implode("\n          ", array_slice($grave, 0, 10)));

$scrie('');
$scrie('Rezultat: ' . $trecute . ' verificări trecute, ' . $esuate . ' eșuate.');
exit($esuate === 0 ? 0 : 1);
