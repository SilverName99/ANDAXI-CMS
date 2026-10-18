<?php

declare(strict_types=1);

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

require_once __DIR__ . '/../bootstrap.php';

use App\Http\Router;
use App\Support\Database;
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
$pageCacheContext = ResponseCache::beginRequest(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    (string) ($_SERVER['REQUEST_URI'] ?? '/'),
    $cacheSettings
);
if (($pageCacheContext['served'] ?? false) === true) {
    return;
}

// Rutele stau în src/Http/rute.php.
$router = new Router();
(require __DIR__ . '/../src/Http/rute.php')($router);

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
ResponseCache::finishRequest($pageCacheContext);
