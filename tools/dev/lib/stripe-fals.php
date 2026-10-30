<?php

declare(strict_types=1);

/**
 * Un Stripe fals pentru teste (WP-15, WP-25): router pentru serverul PHP încorporat, pornit de un test cu
 *
 *   STRIPE_FALS_DOSAR=<dosar> php -S 127.0.0.1:<port> tools/dev/lib/stripe-fals.php
 *
 * și folosit de aplicație prin `STRIPE_API_BASE=http://127.0.0.1:<port>` (App\Support\StripeGateway::apiBase primește doar
 * o adresă locală). Fiecare cerere se scrie, ca un rând JSON, în `<dosar>/cereri.jsonl`: metoda, calea, query-ul, antetele
 * (numele scrise mic) și corpul. Răspunsul: `<dosar>/raspunsuri.json` = {"GET /v1/webhook_endpoints": {"cod": 200, "corp":
 * {…}}, …}; fără o intrare, 200 cu un obiect de probă. Nimic nu pleacă spre Stripe; date inventate.
 */

$dosar = rtrim((string) getenv('STRIPE_FALS_DOSAR'), '/');
if ($dosar === '' || !is_dir($dosar)) {
    http_response_code(500);
    echo '{"error":{"message":"STRIPE_FALS_DOSAR lipsește"}}';
    return true;
}

$antete = [];
foreach ($_SERVER as $cheie => $valoare) {
    if (str_starts_with($cheie, 'HTTP_')) {
        $antete[strtolower(str_replace('_', '-', substr($cheie, 5)))] = (string) $valoare;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) {
    $antete['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
}
$metoda = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$cale = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$query = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY) ?? '');
$corp = (string) file_get_contents('php://input');

file_put_contents($dosar . '/cereri.jsonl', json_encode([
    'metoda' => $metoda,
    'cale' => $cale,
    'query' => $query,
    'antete' => $antete,
    'corp' => $corp,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

$raspunsuri = json_decode((string) @file_get_contents($dosar . '/raspunsuri.json'), true);
$raspuns = is_array($raspunsuri) ? ($raspunsuri[$metoda . ' ' . $cale] ?? null) : null;
header('Content-Type: application/json');
if (is_array($raspuns)) {
    http_response_code((int) ($raspuns['cod'] ?? 200));
    echo json_encode($raspuns['corp'] ?? new stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return true;
}
echo json_encode(['id' => 'obj_proba', 'object' => 'proba', 'cale' => $cale], JSON_UNESCAPED_SLASHES);
return true;
