<?php

declare(strict_types=1);

/**
 * Routerul de dezvoltare cu „Content-Type: text/html” trimis explicit înaintea aplicației, pentru testul cache-ului de
 * pagini (tools/dev/test-porturi-vt-gr.php, WP-15 A). App\Support\ResponseCache ține o pagină doar dacă antetul apare
 * în headers_list(), iar tipul implicit al PHP (default_mimetype) nu apare acolo: fără acest router (și cu
 * session.cache_limiter gol) nicio pagină obișnuită n-ar ajunge în cache, deci integritatea lui n-ar avea ce verifica.
 * Doar în teste; aplicația nu se schimbă (DEZVOLTARE.md, „Erori moștenite”).
 */

$cale = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
if (!str_starts_with($cale, '/uploads/') && !str_starts_with($cale, '/assets/')) {
    header('Content-Type: text/html; charset=utf-8');
}
return require dirname(__DIR__) . '/router.php';
