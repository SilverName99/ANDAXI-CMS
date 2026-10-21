<?php

declare(strict_types=1);

/**
 * ERP-ul fals al testelor (tools/dev/test-reparatii.php): un router pentru `php -S` pe 127.0.0.1, care răspunde
 * ca API-ul ERP-ului, doar cât le trebuie testelor, și notează fiecare cerere. Nicio cerere nu pleacă de aici.
 *
 *   ERP_FALS_DOSAR=<dosar> php -S 127.0.0.1:<port> tools/dev/lib/erp-fals.php
 *
 * În <dosar> (citite la fiecare cerere, deci testul schimbă comportamentul fără să repornească serverul):
 *  - `mod`: `normal` (implicit), `500` (orice cerere primește o eroare 500) sau `incet` (așteaptă `pauza`
 *    secunde, apoi răspunde normal; un client cu timeout mai scurt renunță înainte);
 *  - `pauza`: secundele modului `incet` (implicit 10; se acceptă și zecimale);
 *  - `cunoscute.json`: SKU => disponibil, SKU-urile pe care ERP-ul le cunoaște; celelalte sunt „necunoscute”;
 *  - `cereri.jsonl`: scris de aici, un rând pe cerere: metoda, calea, SKU-urile cerute (la stoc) și numărul
 *    comenzii (la trimiterea unei comenzi).
 *
 * Răspunsuri: POST /api/site/stoc (stocul, ca ERP-ul: `sku`, `cunoscut`, `disponibil`, `existent`),
 * POST /api/comenzi-site/ingest (comanda primită, `comandaId` = FALS-<numărul comenzii>), GET /api/site/ping,
 * orice alt GET o listă goală (notificări, greutăți), orice alt POST `{"ok": true}`. Cheia de integrare nu se
 * verifică. Doar date de test.
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$dosar = rtrim((string) getenv('ERP_FALS_DOSAR'), '/');
if ($dosar === '' || !is_dir($dosar)) {
    http_response_code(500);
    echo 'ERP_FALS_DOSAR lipsește';
    return true;
}
$citeste = static fn (string $nume, string $implicit): string => is_file($dosar . '/' . $nume)
    ? trim((string) file_get_contents($dosar . '/' . $nume)) : $implicit;

$metoda = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$cale = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$corp = json_decode((string) file_get_contents('php://input'), true);
$corp = is_array($corp) ? $corp : [];

$rand = ['metoda' => $metoda, 'cale' => $cale, 'la' => microtime(true)];
if ($cale === '/api/site/stoc') {
    $rand['skuuri'] = array_values(array_map(static fn ($s): string => strtoupper(trim((string) $s)), (array) ($corp['skuuri'] ?? [])));
}
if ($cale === '/api/comenzi-site/ingest') {
    $rand['numar'] = (string) ($corp['numarSite'] ?? '');
}
file_put_contents($dosar . '/cereri.jsonl', json_encode($rand, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

$mod = $citeste('mod', 'normal');
if ($mod === 'incet') {
    usleep((int) round(max(0.0, (float) $citeste('pauza', '10')) * 1000000));
}
header('Content-Type: application/json');
if ($mod === '500') {
    http_response_code(500);
    echo json_encode(['error' => 'Eroare de test a ERP-ului fals.']);
    return true;
}

if ($cale === '/api/site/stoc') {
    $cunoscute = json_decode($citeste('cunoscute.json', '{}'), true);
    $cunoscute = is_array($cunoscute) ? array_change_key_case($cunoscute, CASE_UPPER) : [];
    $raspuns = [];
    foreach ($rand['skuuri'] as $sku) {
        $raspuns[] = array_key_exists($sku, $cunoscute)
            ? ['sku' => $sku, 'cunoscut' => true, 'disponibil' => (float) $cunoscute[$sku], 'existent' => true]
            : ['sku' => $sku, 'cunoscut' => false, 'disponibil' => 0, 'existent' => false];
    }
    echo json_encode($raspuns);
} elseif ($cale === '/api/comenzi-site/ingest') {
    echo json_encode(['comandaId' => 'FALS-' . $rand['numar'], 'status' => 'noua']);
} elseif ($cale === '/api/site/ping') {
    echo json_encode(['ok' => true]);
} elseif ($metoda === 'GET') {
    echo '[]';
} else {
    echo json_encode(['ok' => true]);
}
return true;
