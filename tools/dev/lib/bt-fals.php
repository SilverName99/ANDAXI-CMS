<?php

declare(strict_types=1);

/**
 * Banca Transilvania iPay falsă, pentru teste (tools/dev/test-link-plata.php): un router pentru `php -S` pe 127.0.0.1,
 * folosit de aplicație prin `BT_IPAY_BASE_URL_OVERRIDE=http://127.0.0.1:<port>` (BtIpayGateway::urlBaza; există doar pentru
 * teste). Răspunde ca API-ul băncii (/payment/rest/<operație>.do), doar cât le trebuie testelor:
 *
 *   BT_FALS_DOSAR=<dosar> php -S 127.0.0.1:<port> tools/dev/lib/bt-fals.php
 *
 *  - registerPreAuth.do: plata înregistrată (`orderStatus` 0), `orderId` = „fals-<orderNumber>”, `formUrl` = pagina de
 *    plată, pe gazda acestui server (BtIpayGateway::urlPlataValid o acceptă doar pe gazda serverului de test);
 *  - getOrderStatusExtended.do: starea din `<dosar>/stari.json` (orderId => câmpurile răspunsului: orderStatus, amount,
 *    currency, actionCode, paymentAmountInfo, cardAuthInfo), scrisă de test („clientul a plătit”); fără ea, înregistrată;
 *  - deposit.do: încasarea (starea 2, `depositedAmount` = suma cerută); reverse.do: eliberarea (starea 3);
 *    refund.do: rambursarea (starea 4).
 *
 * Fiecare cerere se scrie ca un rând JSON în `<dosar>/cereri.jsonl` (operația și parametrii, fără parolă). Credențialele
 * nu se verifică. Nimic nu pleacă spre bancă; doar date inventate.
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$dosar = rtrim((string) getenv('BT_FALS_DOSAR'), '/');
header('Content-Type: application/json');
if ($dosar === '' || !is_dir($dosar)) {
    http_response_code(500);
    echo '{"errorCode":"99","errorMessage":"BT_FALS_DOSAR lipsește"}';
    return true;
}

$cale = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$parametri = $_POST;
unset($parametri['password'], $parametri['userName']);
file_put_contents($dosar . '/cereri.jsonl', json_encode(['cale' => $cale, 'parametri' => $parametri],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

$fisierStari = $dosar . '/stari.json';
$blocare = fopen($dosar . '/stari.lock', 'c');
if ($blocare !== false) {
    flock($blocare, LOCK_EX);
}
$stari = is_file($fisierStari) ? (json_decode((string) file_get_contents($fisierStari), true) ?: []) : [];
$scrie = static function () use (&$stari, $fisierStari): void {
    file_put_contents($fisierStari, json_encode($stari, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
};
$id = trim((string) ($_POST['orderId'] ?? ''));
$raspuns = ['errorCode' => '0'];

switch (basename($cale)) {
    case 'registerPreAuth.do':
    case 'register.do':
        $numar = preg_replace('/[^A-Za-z0-9\-_]/', '', (string) ($_POST['orderNumber'] ?? '')) ?? '';
        $id = 'fals-' . $numar;
        $stari[$id] = ['orderNumber' => $numar, 'orderStatus' => 0, 'amount' => (int) ($_POST['amount'] ?? 0), 'currency' => '946',
            'actionCode' => -1, 'paymentAmountInfo' => ['approvedAmount' => 0, 'depositedAmount' => 0, 'refundedAmount' => 0]];
        $scrie();
        $raspuns += ['orderId' => $id, 'formUrl' => 'http://127.0.0.1:' . (int) ($_SERVER['SERVER_PORT'] ?? 80) . '/pagina-de-plata/' . $id];
        break;
    case 'getOrderStatusExtended.do':
        if ($id === '' || !isset($stari[$id])) {
            $raspuns = ['errorCode' => '6', 'errorMessage' => 'Comanda nu există (banca falsă).'];
            break;
        }
        $raspuns += $stari[$id] + ['attributes' => [['name' => 'mdOrder', 'value' => $id]]];
        $raspuns['errorCode'] = '0';
        break;
    case 'deposit.do':
        if (!isset($stari[$id]) || (int) ($stari[$id]['orderStatus'] ?? 0) !== 1) {
            $raspuns = ['errorCode' => '7', 'errorMessage' => 'Plata nu e autorizată (banca falsă).'];
            break;
        }
        $suma = (int) ($_POST['amount'] ?? 0);
        $stari[$id]['orderStatus'] = 2;
        $stari[$id]['paymentAmountInfo']['depositedAmount'] = $suma > 0 ? $suma : (int) $stari[$id]['amount'];
        $scrie();
        break;
    case 'reverse.do':
        if (!isset($stari[$id]) || (int) ($stari[$id]['orderStatus'] ?? 0) !== 1) {
            $raspuns = ['errorCode' => '7', 'errorMessage' => 'Plata nu e autorizată (banca falsă).'];
            break;
        }
        $stari[$id]['orderStatus'] = 3;
        $scrie();
        break;
    case 'refund.do':
        if (!isset($stari[$id]) || (int) ($stari[$id]['orderStatus'] ?? 0) !== 2) {
            $raspuns = ['errorCode' => '7', 'errorMessage' => 'Plata nu e încasată (banca falsă).'];
            break;
        }
        $stari[$id]['orderStatus'] = 4;
        $stari[$id]['paymentAmountInfo']['refundedAmount'] = (int) ($_POST['amount'] ?? 0);
        $scrie();
        break;
    default:
        $raspuns = ['errorCode' => '5', 'errorMessage' => 'Operație necunoscută (banca falsă): ' . $cale];
}
if ($blocare !== false) {
    flock($blocare, LOCK_UN);
    fclose($blocare);
}
echo json_encode($raspuns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
return true;
