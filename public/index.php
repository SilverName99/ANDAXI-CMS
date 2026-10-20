<?php

declare(strict_types=1);

// Poarta (App\Support\Verificare, docs/VERIFICARE.md), prima, înaintea fișierelor încărcate,
// a stratului SEO și a sesiunii: un site fără bază configurată (deploy proaspăt, fără .env)
// răspunde 503 „neconfigurat”, nu paginile aplicației; site-ul de verificare
// (SITE_COPIE_VERIFICARE=1) cere parola și e noindex. Altfel nu face nimic.
require_once __DIR__ . '/../src/Support/Verificare.php';
\App\Support\Verificare::poarta();

// Serve /uploads/ files directly from disk; avoids 502 when nginx forwards missing static files to PHP.
(static function (): void {
    $reqPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    if (!str_starts_with($reqPath, '/uploads/')) {
        return;
    }
    // Prevent path traversal
    $resolved = realpath(__DIR__ . $reqPath);
    $uploadsRoot = realpath(__DIR__ . '/uploads');
    if ($resolved === false || $uploadsRoot === false || !str_starts_with($resolved, $uploadsRoot . DIRECTORY_SEPARATOR)) {
        http_response_code(404);
        exit;
    }
    if (!is_file($resolved)) {
        http_response_code(404);
        exit;
    }
    $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
    $mimeMap = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
        'mp4' => 'video/mp4', 'pdf' => 'application/pdf',
    ];
    $mime = $mimeMap[$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($resolved));
    header('Cache-Control: public, max-age=604800');
    readfile($resolved);
    exit;
})();

// Poza pentru distribuire pe Facebook / WhatsApp (og:image, 1200×630):
// /og/{versiune}/uploads/… . Înaintea sesiunii și a redirecționărilor, ca o poză
// să nu trimită cookie-uri; vezi App\Support\ImagineDistribuire (nucleu: orice
// site, doar pozele din /uploads/products și /uploads/gallery).
if (str_starts_with((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? ''), '/og/')) {
    require_once __DIR__ . '/../src/Support/ImagineDistribuire.php';
    \App\Support\ImagineDistribuire::raspunde(dirname(__DIR__));
}

// O singură adresă pentru fiecare pagină (gazda din APP_URL, fără „/” final) și
// adresele vechi ale site-ului (redirecturi.json din dosarul site-ului, vezi
// docs/SEO.md). Doar GET/HEAD; niciodată pe coș, plată, cont, admin, API, health,
// confirmările de plată sau ERP și nici pe adresele „mereu deschise” ale
// pluginurilor (SeoRedirects::caiExcluse). Rulează înaintea sesiunii.
require_once __DIR__ . '/../src/Support/SeoRedirects.php';
\App\Support\SeoRedirects::incepe(dirname(__DIR__));

require_once __DIR__ . '/../bootstrap.php';

use App\Http\GardaPluginuri;
use App\Http\Router;
use App\Support\Database;
use App\Support\Plugins;
use App\Support\ResponseCache;
use App\Support\Settings;

// Capture Google Ads click id (gclid / gbraid / wbraid) so an order placed
// later in the session can be attributed to the ad that brought the visitor.
(static function (): void {
    $clickId = '';
    foreach (['gclid', 'gbraid', 'wbraid'] as $param) {
        $value = trim((string) ($_GET[$param] ?? ''));
        if ($value !== '') {
            $clickId = $value;
            break;
        }
    }
    if ($clickId === '' && strtolower(trim((string) ($_GET['utm_source'] ?? ''))) === 'google') {
        $clickId = 'utm-google';
    }
    if ($clickId === '') {
        return;
    }
    $clickId = substr(preg_replace('/[^A-Za-z0-9_\-\.]/', '', $clickId) ?? '', 0, 255);
    if ($clickId === '') {
        return;
    }
    setcookie('bv_gclid', $clickId, [
        'expires' => time() + 60 * 60 * 24 * 30,
        'path' => '/',
        'samesite' => 'Lax',
    ]);
    $_COOKIE['bv_gclid'] = $clickId;
})();

$appConfig = require __DIR__ . '/../config/app.php';
$cacheDb = Database::connection((array) ($appConfig['db'] ?? []));
$cacheSettings = Settings::all($cacheDb);
ResponseCache::applyAssetCacheHeaders($cacheSettings, (string) ($_SERVER['REQUEST_URI'] ?? ''));
// Mod mentenanță: se verifică înaintea cache-ului de pagină, ca vizitatorii să
// nu primească o pagină salvată dinainte de activare.
if (\App\Support\Maintenance::intercepteaza(
    $cacheSettings,
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    (string) ($_SERVER['REQUEST_URI'] ?? '/')
)) {
    return;
}
// Pluginurile site-ului, din licență. Înaintea cache-ului de pagină: dacă
// licența s-a schimbat, cache-ul se golește aici, ca nimeni să nu primească o
// pagină salvată de când pluginul avea altă stare.
Plugins::incarca();
// Pașii de activare ai unui plugin pornit de curând (config/activari.php: pagina /acorduri-gdpr,
// pragul ERP), o singură dată pe bază; fără nicio interogare în plus când nu e nimic de făcut.
\App\Support\Activari::laCerere($cacheDb, $cacheSettings);
if (Plugins::hartaSiteDeRefacut()) {
    (new \App\Http\Controllers\AdminController())->refaHartaSiteDupaPluginuri();
}
$pageCacheContext = ResponseCache::beginRequest(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    (string) ($_SERVER['REQUEST_URI'] ?? '/'),
    $cacheSettings
);
if (($pageCacheContext['served'] ?? false) === true) {
    return;
}

// Rutele stau în src/Http/rute.php. Garda oprește rutele pluginurilor pe care
// site-ul nu le are (404 pe site, „Modulul nu este inclus în abonament” în admin).
$router = new Router([GardaPluginuri::class, 'permite']);
(require __DIR__ . '/../src/Http/rute.php')($router);

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
ResponseCache::finishRequest($pageCacheContext);
