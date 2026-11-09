<?php

declare(strict_types=1);

/**
 * Testele linkului de plată pentru o comandă cu plata cu cardul eșuată (pluginul `link_plata`, 9 nov 2026; docs/PLUGINURI.md,
 * „Link de plată pentru o comandă cu plata eșuată”), cu serverul de dezvoltare, pe baza de test cu datele demo:
 *
 *   tools/dev/php.sh tools/dev/test-link-plata.php
 *
 * A. Regulile, fără server: ce comandă primește un link de plată întreagă (PaymentLink::plataIntegrala: cu cardul, neplătită,
 *    plata eșuată, expirată, anulată sau neterminată după expirare, fără o plată BT autorizată sau în curs), ce link se mai
 *    poate plăti (motivLinkNeplatibil), felul linkului după sumă.
 * B. Adminul: zona din fereastra comenzii (marcajele, scriptul), GET /admin/orders/{id}/payment-link, trimiterea (fără jeton:
 *    refuzată; cu jeton: linkul și emailul „Finalizează plata comenzii”; din nou: același link), refuzurile (comanda plătită,
 *    autorizarea BT activă, plata unui link încă în curs, ramburs), rolurile, linkul pentru diferență neschimbat.
 * C. EuPlătesc (BT pornit, dar nu în producție: linkul pleacă prin EuPlătesc): pagina linkului, formularul procesatorului,
 *    notificarea refuzată / cu altă sumă / bună (semnate); comanda devine plătită ca după checkout (starea, suma, emailul
 *    „comandă nouă”, trimisă în ERP-ul fals), o a doua notificare nu schimbă nimic; garda plății duble: plata inițială venită
 *    după link (nota „Plătită de două ori”), plata inițială venită înaintea linkului (linkul anulat, pagina și pornirea
 *    refuzate, iar dacă linkul se plătește totuși: nota, fără sumă adunată).
 * D. Stripe (singurul procesator pornit, ca la Biovitality), pe un al doilea server cu Stripe-ul fals (tools/dev/lib/stripe-fals.php,
 *    porturile CMS_DEV_PORT + 63 și + 64): linkul nou e Stripe, sesiunea plătită face comanda plătită, sesiunea plății
 *    inițiale venită după: nota.
 * E. Banca Transilvania, în procesul testului, în producție pe o bancă falsă (tools/dev/lib/bt-fals.php): comanda BT eșuată →
 *    link → plata obișnuită a comenzii („{nr}-R2”, cu linkul pe ea) → callback-ul (autorizarea) → comanda plătită, emailul,
 *    trimisă în ERP; încasarea abia la aprobarea din ERP; garda: plata inițială autorizată totuși (găsită de cronul BT) anulează
 *    linkul, iar plata linkului autorizată după se eliberează singură.
 * Revizuirea din 9 nov (un caz pe fiecare constatare): felul linkului scris la creare (`-PI<n>`), nu dedus din total (un
 * link de plată întreagă rămâne „dublură” și cu totalul crescut); orice încasare anulează linkurile mai mari decât restul
 * („Înregistrează încasarea”, plata inițială); o diferență mai mare decât restul nu mai pornește; același link plătit de două
 * ori (EuPlătesc, Stripe) și o diferență veche plătită peste una nouă; nota numește exact plata de rambursat și pe cea
 * socotită; plata BT socotită a unei comenzi „Plătită de două ori” nu se eliberează / rambursează fără confirmare; Stripe:
 * sesiunea checkout-ului se expiră înainte de link (și Stripe căzut, și plătită: refuz), sesiunea linkului se refolosește
 * cât e deschisă și expiră după 35 de minute, checkout-ul expiră odată cu plățile neterminate; notificări deodată (un email,
 * o trimitere în ERP), o scriere care nu reușește (400, reîncercarea reușește), două trimiteri deodată (un link); „suma
 * diferă” nu șterge nota; o rambursare BT a unei diferențe trimite din nou comanda în ERP; linkul pentru diferență cere CSRF.
 * F. Curățenia: toată baza (fiecare tabel) pusă la loc, verificat cu CHECKSUM TABLE; licența, sesiunile; jurnalul PHP fără
 *    avertismente noi.
 *
 * Raportul: $CMS_DEV_DIR/test-link-plata-raport.txt. Cod de ieșire 0 doar dacă totul a trecut. Doar date inventate.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';
require_once __DIR__ . '/lib/salvari-admin.php';

use App\Support\BtIpayGateway;
use App\Support\BtIpayPayments;
use App\Support\Database;
use App\Support\EuPlatescGateway;
use App\Support\PaymentLink;
use App\Support\Paths;
use App\Support\ResponseCache;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-link-plata-raport.txt';
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
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . mb_substr($detalii, 0, 1500) : ''));
    }
};
$scrie('Test linkul de plată pentru o comandă cu plata eșuată ANDAXI-CMS — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

// ── Serverul și baza ─────────────────────────────────────────────────────────────────────────────────────────────────
$port = (int) (getenv('CMS_DEV_PORT') ?: '8701');
$baza = 'http://127.0.0.1:' . $port;
$cerere = static function (string $metoda, string $cale, ?string $jar = null, array $date = [], array $antete = []) use ($baza): array {
    $primite = [];
    $url = str_starts_with($cale, 'http') ? $cale : $baza . $cale;
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOPROXY => '*',
        CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => array_merge(['User-Agent: Mozilla/5.0 (X11; Linux x86_64) test-link-plata'], $antete),
        CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$primite): int {
            if (str_contains($linie, ':')) {
                [$nume, $valoare] = explode(':', $linie, 2);
                $primite[strtolower(trim($nume))] = trim($valoare);
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
    return ['cod' => $cod, 'antete' => $primite, 'corp' => $corp, 'json' => json_decode($corp, true)];
};
$amPornitServerul = false;
if ($cerere('GET', '/health')['cod'] !== 200) {
    exec(escapeshellarg($radacina . '/tools/dev/server.sh') . ' 2>&1', $iesire, $codServer);
    if ($codServer !== 0) {
        $scrie('EROARE: serverul nu pornește: ' . implode(' ', $iesire));
        exit(1);
    }
    $amPornitServerul = true;
}
$app = require $radacina . '/config/app.php';
$db = Database::connection((array) $app['db']);
if (!$db instanceof PDO || (int) $db->query("SELECT COUNT(*) FROM orders WHERE order_number IN ('1028', '1045', '1057', '1058')")->fetchColumn() !== 4) {
    $scrie('EROARE: baza de test nu are comenzile demo; rulează întâi tools/dev/instaleaza.sh');
    exit(1);
}
PaymentLink::ensureSchema($db);
BtIpayPayments::ensureSchema($db);
$unu = static function (string $sql, array $param = []) use ($db): string {
    $stmt = $db->prepare($sql);
    $stmt->execute($param);
    $valoare = $stmt->fetchColumn();
    return $valoare === false || $valoare === null ? '' : (string) $valoare;
};
$rand = static function (string $sql, array $param = []) use ($db): array {
    $stmt = $db->prepare($sql);
    $stmt->execute($param);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($r) ? $r : [];
};
$comanda = static fn (string $numar): array => $rand('SELECT * FROM orders WHERE order_number = :n AND deleted_at IS NULL', ['n' => $numar]);
$setare = static function (string $cheie, ?string $valoare) use ($db): void {
    if ($valoare === null) {
        $db->prepare('DELETE FROM settings WHERE `key` = :k')->execute(['k' => $cheie]);
        return;
    }
    $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (:k, :v) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
        ->execute(['k' => $cheie, 'v' => $valoare]);
};

// Instantaneul întregii baze: fiecare tabel, pus la loc la final și verificat cu CHECKSUM TABLE.
$tabeleBaza = [];
foreach ($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
    ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) as $tabel) {
    $chei = $db->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND CONSTRAINT_NAME = 'PRIMARY'");
    $chei->execute(['t' => $tabel]);
    $coloaneCheie = $chei->fetchAll(PDO::FETCH_COLUMN);
    $tabeleBaza[] = ['tabel' => (string) $tabel, 'unde' => '1 = 1', 'cheie' => count($coloaneCheie) === 1 ? (string) $coloaneCheie[0] : null];
}
$sumeBaza = static function () use ($db, $tabeleBaza): array {
    $sume = [];
    $lista = implode(', ', array_map(static fn (array $t): string => '`' . $t['tabel'] . '`', $tabeleBaza));
    foreach ($db->query('CHECKSUM TABLE ' . $lista)->fetchAll(PDO::FETCH_NUM) as $r) {
        $sume[(string) $r[0]] = (string) $r[1];
    }
    return $sume;
};
$instantaneu = salvari_instantaneu($db, $tabeleBaza);
$sumeInainte = $sumeBaza();
$sesiuniInainte = glob(Paths::stocare('sessions') . '/sess_*') ?: [];
$fisierErori = $dosarDev . '/php-erori.log';
$pozitieErori = is_file($fisierErori) ? (int) filesize($fisierErori) : 0;
$parolaAdmin = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));
$jarAdmin = $dosarDev . '/test-link-plata-admin-' . getmypid() . '.txt';
$jarBlog = $dosarDev . '/test-link-plata-blog-' . getmypid() . '.txt';
$jarClient = $dosarDev . '/test-link-plata-client-' . getmypid() . '.txt';
$dosarBt = $dosarDev . '/test-link-plata-bt-' . getmypid();
$dosarErp = $dosarDev . '/test-link-plata-erp-' . getmypid();
$portBt = $port + 61;
$portErp = $port + 62;
$procese = [];

$puneLaLoc = static function () use ($db, $tabeleBaza, $instantaneu, $jarAdmin, $jarBlog, $jarClient, $sesiuniInainte, $amPornitServerul,
    $radacina, &$procese, $dosarBt, $dosarErp): void {
    foreach ($procese as $pid) {
        if ($pid > 0 && posix_kill($pid, 0)) {
            // Întâi procesele de lucru ale lui `php -S` (copiii lui), apoi serverul.
            exec('pkill -TERM -P ' . (int) $pid . ' 2>/dev/null');
            posix_kill($pid, SIGTERM);
        }
    }
    $procese = [];
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    salvari_pune_la_loc($db, $tabeleBaza, $instantaneu);
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    ResponseCache::purgePageCache();
    foreach (array_diff(glob(Paths::stocare('sessions') . '/sess_*') ?: [], $sesiuniInainte) as $sesiune) {
        @unlink($sesiune);
    }
    foreach ([$jarAdmin, $jarBlog, $jarClient] as $jar) {
        @unlink($jar);
    }
    foreach ([$dosarBt, $dosarErp] as $dosar) {
        foreach (glob($dosar . '/*') ?: [] as $fisier) {
            @unlink($fisier);
        }
        @rmdir($dosar);
    }
    if ($amPornitServerul) {
        exec(escapeshellarg($radacina . '/tools/dev/server.sh') . ' --opreste >/dev/null 2>&1');
    }
};
register_shutdown_function(static function () use ($puneLaLoc): void {
    $puneLaLoc();
});

/** Un server `php -S` al testului (banca falsă, ERP-ul fals): pid-ul lui, oprit la final. */
$porneste = static function (string $router, int $portServer, array $mediu) use ($radacina, $dosarDev, &$procese, $cerere): bool {
    $env = '';
    foreach ($mediu as $cheie => $valoare) {
        $env .= ' ' . $cheie . '=' . escapeshellarg($valoare);
    }
    $pid = (int) trim((string) shell_exec('cd ' . escapeshellarg($radacina) . ' && env PHP_CLI_SERVER_WORKERS=4' . $env . ' nohup '
        . escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d log_errors=1 -d ' . escapeshellarg('error_log=' . $dosarDev . '/php-erori.log')
        . ' -S 127.0.0.1:' . $portServer . ' ' . escapeshellarg($router) . ' > /dev/null 2>&1 & echo $!'));
    $procese[] = $pid;
    for ($i = 0; $i < 50; $i++) {
        if ($cerere('GET', 'http://127.0.0.1:' . $portServer . '/api/site/ping')['cod'] > 0) {
            return true;
        }
        usleep(100000);
    }
    return false;
};
@mkdir($dosarErp, 0770, true);
file_put_contents($dosarErp . '/mod', 'normal');
file_put_contents($dosarErp . '/cunoscute.json', '{}');
file_put_contents($dosarErp . '/cereri.jsonl', '');
@mkdir($dosarBt, 0770, true);
file_put_contents($dosarBt . '/cereri.jsonl', '');
if (!$porneste($radacina . '/tools/dev/lib/erp-fals.php', $portErp, ['ERP_FALS_DOSAR' => $dosarErp])
    || !$porneste($radacina . '/tools/dev/lib/bt-fals.php', $portBt, ['BT_FALS_DOSAR' => $dosarBt])) {
    $scrie('EROARE: ERP-ul fals sau banca falsă nu pornesc (porturile ' . $portErp . ', ' . $portBt . ')');
    exit(1);
}
$trimiseInErp = static function (string $numar) use ($dosarErp): int {
    $n = 0;
    foreach (file($dosarErp . '/cereri.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linie) {
        $r = json_decode($linie, true);
        if (is_array($r) && ($r['cale'] ?? '') === '/api/comenzi-site/ingest' && (string) ($r['numar'] ?? '') === $numar) {
            $n++;
        }
    }
    return $n;
};
$emailuriComanda = static fn (int $orderId, string $tip): int => (int) $unu('SELECT COUNT(*) FROM email_logs WHERE order_id = :o AND email_type = :t',
    ['o' => $orderId, 't' => $tip]);

// Setările plăților și ale ERP-ului (inventate): EuPlătesc pornit, BT pornit și deschis clienților (dar serverul de dezvoltare e în
// modul test, deci BT nu încasează linkuri: linkurile pleacă prin EuPlătesc), ERP-ul fals.
$secretEu = '00112233445566778899AABBCCDDEEFF';
foreach (['euplatesc_enabled' => '1', 'euplatesc_merchant_id' => 'comerciant-test', 'euplatesc_secret_key' => $secretEu,
    'bt_ipay_enabled' => '1', 'bt_ipay_admin_only' => '0', 'bt_ipay_expire_minutes' => '60', 'stripe_enabled' => '0',
    'erp_enabled' => '1', 'erp_url' => 'http://127.0.0.1:' . $portErp, 'erp_api_key' => 'cheie-erp-fals', 'erp_timeout' => '20',
] as $cheie => $valoare) {
    $setare($cheie, $valoare);
}
// Ca o comandă plasată din checkout: fără sumă încasată (NULL; datele demo pun 0,00), încă netrimisă în ERP.
$db->exec("UPDATE orders SET erp_status = 'pending', erp_attempts = 0, erp_next_retry_at = NULL, paid_amount = NULL
    WHERE order_number IN ('1036', '1040', '1042', '1043', '1044', '1045', '1047', '1057', '1058')");
$setari = static fn (): array => \App\Support\Settings::all($db);

// ── A. Regulile, fără server ───────────────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== A. Regulile, fără server');
$c1057 = $comanda('1057'); // EuPlătesc, plata eșuată, 237,00 lei
$c1028 = $comanda('1028'); // EuPlătesc, plătită
$c1026 = $comanda('1026'); // ramburs
$c1047 = $comanda('1047'); // Stripe, anulată
$c1001 = $comanda('1001'); // ramburs, finalizată
$verifica('felul linkului e cel scris la creare, în referință („-PI<n>” = plata întreagă, „-P<n>” = diferență), nu din total',
    PaymentLink::esteIntegral(['referinta' => '1057-PI1', 'amount' => '10.00']) && !PaymentLink::esteIntegral(['referinta' => '1057-P2', 'amount' => '237.00'])
    && PaymentLink::pareReferintaDeLink('1057-PI3') && PaymentLink::pareReferintaDeLink('1057-P2') && !PaymentLink::pareReferintaDeLink('1057'));
$verifica('„nimic încasat”: plătită nu, eșuată cu 0,00 da, o sumă trecută nu, rambursată nu',
    !PaymentLink::nimicIncasat($c1028) && PaymentLink::nimicIncasat($c1057)
    && !PaymentLink::nimicIncasat(['payment_status' => 'failed', 'paid_amount' => '10.00'])
    && !PaymentLink::nimicIncasat(['payment_status' => 'refunded', 'paid_amount' => null]));
$posibil = PaymentLink::plataIntegrala($db, $c1057, $setari());
$verifica('comanda EuPlătesc cu plata eșuată, nimic încasat: se poate trimite linkul, pentru tot totalul (237,00)',
    $posibil['ok'] && abs($posibil['suma'] - 237.0) < 0.001, json_encode($posibil, JSON_UNESCAPED_UNICODE));
$refuzuri = [
    'comanda plătită' => [$c1028, 'deja plătită'],
    'ramburs' => [$c1026, 'cu cardul'],
    'anulată' => [$c1047, 'anulată'],
    'finalizată (ramburs)' => [$c1001, 'cu cardul'],
    'finalizată (card)' => [['status' => 'completed', 'payment_status' => 'failed'] + $c1057, 'încheiată'],
    'rambursată' => [['status' => 'pending', 'payment_status' => 'refunded'] + $c1057, 'rambursată'],
    'o sumă deja încasată' => [['paid_amount' => '50.00'] + $c1057, 'deja încasată'],
    '„Plată în așteptare” de 10 minute' => [['status' => 'pending_payment', 'payment_status' => 'pending',
        'created_at' => date('Y-m-d H:i:s', time() - 600)] + $c1057, 'încă în curs'],
];
$gresite = [];
foreach ($refuzuri as $nume => [$o, $text]) {
    $r = PaymentLink::plataIntegrala($db, $o, $setari());
    if ($r['ok'] || !str_contains($r['motiv'], $text)) {
        $gresite[] = $nume . ' → ' . json_encode($r, JSON_UNESCAPED_UNICODE);
    }
}
$verifica('refuzurile: plătită, ramburs, anulată, finalizată, rambursată, cu o sumă încasată, plata încă în curs', $gresite === [], implode('; ', $gresite));
$verifica('„Plată în așteptare” de mai mult decât expirarea (60 de minute): plata n-a fost terminată, se poate',
    PaymentLink::plataIntegrala($db, ['status' => 'pending_payment', 'payment_status' => 'pending', 'created_at' => date('Y-m-d H:i:s', time() - 7200)]
        + $c1057, $setari())['ok']);
$verifica('suma eliberată la BT, comanda încă activă („în așteptare”, „Neplătit”): se poate',
    PaymentLink::plataIntegrala($db, ['status' => 'pending', 'payment_status' => 'unpaid'] + $c1057, $setari())['ok']);

// Plățile BT ale comenzii (rânduri inventate în bt_ipay_transactions, șterse la final).
$c1058 = $comanda('1058'); // BT, plata eșuată, 193,00 lei
$txBt = static function (array $o, string $stare, int $vechimeSecunde, int $linkId = 0, string $numar = '') use ($db): int {
    $db->prepare('INSERT INTO bt_ipay_transactions (order_id, link_id, kind, mode, bt_order_number, bt_order_id, amount_minor, currency, state,
        created_at, updated_at) VALUES (:o, :l, "order", "live", :n, :bt, :s, 946, :st, :c, :c2)')->execute([
        'o' => (int) $o['id'], 'l' => $linkId > 0 ? $linkId : null, 'n' => $numar !== '' ? $numar : 'TST-' . bin2hex(random_bytes(4)),
        'bt' => 'fals-' . ($numar !== '' ? $numar : bin2hex(random_bytes(4))), 's' => (int) round((float) $o['total'] * 100), 'st' => $stare,
        'c' => date('Y-m-d H:i:s', time() - $vechimeSecunde), 'c2' => date('Y-m-d H:i:s', time() - $vechimeSecunde)]);
    return (int) $db->lastInsertId();
};
$idAut = $txBt($c1058, 'authorized', 7200);
$rAut = PaymentLink::plataIntegrala($db, $c1058, $setari());
$db->prepare('UPDATE bt_ipay_transactions SET state = "registered" WHERE id = :id')->execute(['id' => $idAut]);
$db->prepare('UPDATE bt_ipay_transactions SET created_at = :c WHERE id = :id')->execute(['c' => date('Y-m-d H:i:s', time() - 300), 'id' => $idAut]);
$rInCurs = PaymentLink::plataIntegrala($db, $c1058, $setari());
$db->prepare('UPDATE bt_ipay_transactions SET state = "expired", created_at = :c WHERE id = :id')->execute(['c' => date('Y-m-d H:i:s', time() - 7200), 'id' => $idAut]);
$rExpirata = PaymentLink::plataIntegrala($db, $c1058, $setari());
$verifica('BT: o autorizare activă a comenzii → refuz; o plată pornită acum 5 minute → refuz („în curs”); expirată → se poate',
    !$rAut['ok'] && str_contains($rAut['motiv'], 'autorizată') && !$rInCurs['ok'] && str_contains($rInCurs['motiv'], 'în curs') && $rExpirata['ok'],
    json_encode([$rAut, $rInCurs, $rExpirata], JSON_UNESCAPED_UNICODE));
$db->prepare('DELETE FROM bt_ipay_transactions WHERE id = :id')->execute(['id' => $idAut]);
$linkProba = ['status' => 'pending', 'amount' => '237.00', 'referinta' => '1057-PI1'];
$difProba = ['status' => 'pending', 'amount' => '20.00', 'referinta' => '1028-P1'];
$verifica('linkul de plată întreagă nu se mai plătește pe o comandă plătită, anulată sau cu totalul scăzut; o diferență nu se atinge',
    str_contains(PaymentLink::motivLinkNeplatibil($linkProba, $c1028 + ['total' => '237.00']), 'deja plătită')
    && str_contains(PaymentLink::motivLinkNeplatibil($linkProba, ['status' => 'cancelled'] + $c1057), 'anulată')
    && str_contains(PaymentLink::motivLinkNeplatibil(['amount' => '300.00'] + $linkProba, $c1057), 'Totalul comenzii')
    && str_contains(PaymentLink::motivLinkNeplatibil(['amount' => '200.00'] + $linkProba, $c1057), 'Totalul comenzii')
    && PaymentLink::motivLinkNeplatibil($linkProba, $c1057) === ''
    && PaymentLink::motivLinkNeplatibil(['status' => 'paid'] + $linkProba, $c1028) === '');
$verifica('linkul pentru diferență nu se mai plătește peste restul de acum (o comandă plătită cu 20 de lei rest: 20 da, 25 nu; cu restul încasat: nu)',
    PaymentLink::motivLinkNeplatibil($difProba, ['total' => '142.80', 'paid_amount' => '122.80'] + $c1028) === ''
    && str_contains(PaymentLink::motivLinkNeplatibil(['amount' => '25.00'] + $difProba, ['total' => '142.80', 'paid_amount' => '122.80'] + $c1028), '20,00 lei')
    && str_contains(PaymentLink::motivLinkNeplatibil($difProba, $c1028), 'deja încasată'));

// ── B. Adminul ─────────────────────────────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== B. Adminul');
$r = $cerere('POST', '/admin/login', $jarAdmin, ['email' => (string) (getenv('CMS_DEV_ADMIN_EMAIL') ?: 'admin@exemplu.ro'), 'password' => $parolaAdmin]);
$verifica('logarea administratorului general', $r['cod'] === 302, (string) $r['cod']);
$lista = $cerere('GET', '/admin/orders', $jarAdmin)['corp'];
$csrf = preg_match('/window\.LP_CSRF = "([0-9a-f]{64})";/', $lista, $m) === 1 ? $m[1] : '';
$verifica('Admin → Comenzi: zona linkurilor în fereastra comenzii și scriptul ei, între marcajele lor (lp:fereastra, lp:linkuri-js), cu jetonul',
    substr_count($lista, '<!--port:lp:fereastra-->${typeof window.lpZonaLinkuri') === 1 && substr_count($lista, '<!--/port:lp:fereastra-->') === 1
    && substr_count($lista, '<!--port:lp:linkuri-js-->') === 1 && str_contains($lista, '/assets/js/admin/link-plata.js?v=') && $csrf !== '');
$deTrimis = preg_match('/window\.LP_DE_TRIMIS = (\{[^;]*\});/', $lista, $m) === 1 ? (array) json_decode($m[1], true) : [];
$verifica('lista: „Trimite din nou link de plată” pe rândurile comenzilor cu cardul cu plata eșuată (1057, 1058), nu pe cele plătite (1028) sau cu ramburs',
    isset($deTrimis[(string) $c1057['id']]) && isset($deTrimis[(string) $c1058['id']]) && !isset($deTrimis[(string) $c1028['id']])
    && !isset($deTrimis[(string) $c1026['id']]) && abs((float) $deTrimis[(string) $c1057['id']] - 237.0) < 0.001, json_encode($deTrimis));
$js = $cerere('GET', '/assets/js/admin/link-plata.js');
$verifica('scriptul zonei se servește (200, JavaScript)', $js['cod'] === 200 && str_contains($js['corp'], 'window.lpZonaLinkuri'));

$id1057 = (int) $c1057['id'];
$stare = $cerere('GET', '/admin/orders/' . $id1057 . '/payment-link', $jarAdmin);
$verifica('GET /admin/orders/{id}/payment-link: comanda cu plata eșuată arată butonul, se poate trimite, 237,00 lei, niciun link încă',
    $stare['cod'] === 200 && ($stare['json']['integrala']['arata'] ?? false) === true && ($stare['json']['integrala']['poate'] ?? false) === true
    && abs((float) ($stare['json']['integrala']['suma'] ?? 0) - 237.0) < 0.001 && ($stare['json']['linkuri'] ?? null) === []
    && str_contains((string) ($stare['antete']['cache-control'] ?? ''), 'no-store'), $stare['corp']);
$stare = $cerere('GET', '/admin/orders/' . (int) $c1028['id'] . '/payment-link', $jarAdmin);
$verifica('… pe o comandă plătită: fără buton (nimic de arătat)', ($stare['json']['integrala']['arata'] ?? true) === false, $stare['corp']);

$linkuri = static fn (int $orderId): array => $db->query('SELECT * FROM order_payment_links WHERE order_id = ' . $orderId . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$trimite = static fn (int $orderId, string $jeton, ?string $jar = null): array => $cerere('POST', '/admin/orders/' . $orderId . '/payment-link',
    $jar ?? $jarAdmin, ['tip' => 'integrala', '_csrf' => $jeton], ['X-CSRF-Token: ' . $jeton]);
$r = $trimite($id1057, '');
$verifica('trimiterea fără jetonul anti-CSRF: refuzată, niciun link', ($r['json']['ok'] ?? true) === false
    && str_contains((string) ($r['json']['error'] ?? ''), 'Reîncarcă pagina') && $linkuri($id1057) === [], $r['corp']);
$r = $trimite($id1057, str_repeat('a', 64));
$verifica('… cu un jeton greșit: refuzată, niciun link', ($r['json']['ok'] ?? true) === false && $linkuri($id1057) === [], $r['corp']);
$istoricInainte = (int) $unu("SELECT COUNT(*) FROM email_send_history WHERE order_id = :o AND email_type = 'payment_link'", ['o' => $id1057]);
$r = $trimite($id1057, $csrf);
$lk = $linkuri($id1057);
$verifica('„Trimite link de plată” (EuPlătesc, BT nefiind în producție): linkul pentru 237,00 lei, în așteptare, „{nr}-P1”, răspunsul o spune',
    ($r['json']['ok'] ?? false) === true && ($r['json']['procesator'] ?? '') === 'EuPlătesc' && ($r['json']['refolosit'] ?? true) === false
    && count($lk) === 1 && $lk[0]['amount'] === '237.00' && $lk[0]['status'] === 'pending' && $lk[0]['procesator'] === 'euplatesc'
    && $lk[0]['referinta'] === '1057-PI1', $r['corp'] . ' ' . json_encode($lk));
$email = $rand("SELECT subject, status, recipient FROM email_send_history WHERE order_id = :o AND email_type = 'payment_link' ORDER BY id DESC LIMIT 1",
    ['o' => $id1057]);
$verifica('emailul clientului: „Finalizează plata comenzii 1057”, pe adresa comenzii', (int) $unu("SELECT COUNT(*) FROM email_send_history
    WHERE order_id = :o AND email_type = 'payment_link'", ['o' => $id1057]) === $istoricInainte + 1
    && ($email['subject'] ?? '') === 'Finalizează plata comenzii 1057' && ($email['recipient'] ?? '') === $c1057['billing_email'], json_encode($email));
$r = $trimite($id1057, $csrf);
$verifica('trimis din nou: același link (refolosit), niciun rând nou', ($r['json']['ok'] ?? false) === true && ($r['json']['refolosit'] ?? false) === true
    && count($linkuri($id1057)) === 1 && str_contains((string) ($r['json']['url'] ?? ''), '/plata/' . $lk[0]['token']), $r['corp']);
$stare = $cerere('GET', '/admin/orders/' . $id1057 . '/payment-link', $jarAdmin);
$verifica('fereastra comenzii: linkul „trimis”, pentru toată comanda, prin EuPlătesc, cu adresa lui',
    ($stare['json']['linkuri'][0]['stare'] ?? '') === 'trimis' && ($stare['json']['linkuri'][0]['tip'] ?? '') === 'integrala'
    && ($stare['json']['linkuri'][0]['procesator'] ?? '') === 'EuPlătesc' && str_contains((string) ($stare['json']['linkuri'][0]['url'] ?? ''), '/plata/'),
    $stare['corp']);

$r = $trimite((int) $c1028['id'], $csrf);
$verifica('refuz: comanda e deja plătită', ($r['json']['ok'] ?? true) === false && str_contains((string) ($r['json']['error'] ?? ''), 'deja plătită')
    && $linkuri((int) $c1028['id']) === [], $r['corp']);
$r = $trimite((int) $c1026['id'], $csrf);
$verifica('refuz: o comandă cu ramburs', ($r['json']['ok'] ?? true) === false && str_contains((string) ($r['json']['error'] ?? ''), 'cu cardul'), $r['corp']);
$id1058 = (int) $c1058['id'];
$idAut = $txBt($c1058, 'authorized', 7200);
$r = $trimite($id1058, $csrf);
$verifica('refuz: comanda are o plată BT autorizată (suma blocată pe card)', ($r['json']['ok'] ?? true) === false
    && str_contains((string) ($r['json']['error'] ?? ''), 'autorizată') && $linkuri($id1058) === [], $r['corp']);
$db->prepare('DELETE FROM bt_ipay_transactions WHERE id = :id')->execute(['id' => $idAut]);
// Un link al comenzii cu plata încă în curs la bancă (pornită acum 2 minute): nu se trimite altul.
$linkInCurs = PaymentLink::creeaza($db, $id1058, '1058', 193.0, PaymentLink::PROCESATOR_BT, [], PaymentLink::TIP_INTEGRALA);
$idInCurs = $txBt($c1058, 'registered', 120, (int) ($linkInCurs['id'] ?? 0));
$r = $trimite($id1058, $csrf);
$verifica('refuz: plata linkului trimis e încă în curs la bancă', ($r['json']['ok'] ?? true) === false
    && str_contains((string) ($r['json']['error'] ?? ''), 'în curs') && count($linkuri($id1058)) === 1, $r['corp']);
$db->prepare('DELETE FROM bt_ipay_transactions WHERE id = :id')->execute(['id' => $idInCurs]);
$db->prepare('DELETE FROM order_payment_links WHERE order_id = :id')->execute(['id' => $id1058]);
$r = $cerere('POST', '/admin/orders/' . $id1057 . '/payment-link', $jarAdmin);
$verifica('„Trimite link de plată pentru diferență” (fără tip) fără jetonul anti-CSRF: refuzat', ($r['json']['ok'] ?? true) === false
    && str_contains((string) ($r['json']['error'] ?? ''), 'Reîncarcă pagina'), $r['corp']);
$r = $cerere('POST', '/admin/orders/' . $id1057 . '/payment-link', $jarAdmin, [], ['X-CSRF-Token: ' . $csrf]);
$verifica('… cu jetonul: rămâne cum era (comanda nu are diferență)', ($r['json']['ok'] ?? true) === false
    && ($r['json']['error'] ?? '') === 'Comanda nu are diferență de încasat.', $r['corp']);
$verifica('… și fereastra trimite jetonul (antetul X-CSRF-Token)', str_contains($lista, "{method:'POST', headers: {'X-CSRF-Token': window.LP_CSRF || ''}})"));
$r = $cerere('POST', '/admin/login', $jarBlog, ['email' => 'blog@exemplu.ro', 'password' => $parolaAdmin]);
$rBlog = $trimite($id1057, $csrf, $jarBlog);
$gBlog = $cerere('GET', '/admin/orders/' . $id1057 . '/payment-link', $jarBlog);
$verifica('rolul „blog”: nici trimiterea, nici lista (trimis spre pagina lui), niciun link nou', $r['cod'] === 302 && $rBlog['cod'] === 302
    && $gBlog['cod'] === 302 && count($linkuri($id1057)) === 1, $rBlog['cod'] . ' ' . $gBlog['cod']);
$rNelogat = $cerere('GET', '/admin/orders/' . $id1057 . '/payment-link');
$verifica('nelogat: login', $rNelogat['cod'] === 302 && str_contains((string) ($rNelogat['antete']['location'] ?? ''), '/admin/login'));

// ── C. EuPlătesc ───────────────────────────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== C. EuPlătesc: pagina, plata, comanda plătită, garda plății duble');
$token = (string) $lk[0]['token'];
$pagina = $cerere('GET', '/plata/' . rawurlencode($token), $jarClient);
$verifica('pagina linkului: „Finalizează plata comenzii”, plata neterminată, 237,00 lei, butonul, EuPlătesc', $pagina['cod'] === 200
    && str_contains($pagina['corp'], 'Finalizează plata comenzii</h1>') && str_contains($pagina['corp'], 'nu a fost')
    && str_contains($pagina['corp'], '237,00 lei') && str_contains($pagina['corp'], 'Plătește cu cardul')
    && str_contains($pagina['corp'], 'procesatorului EuPlătesc') && str_contains($pagina['corp'], '<title>Finalizează plata — comanda 1057')
    && !str_contains($pagina['corp'], 'Diferență de plată'));
$formular = $cerere('POST', '/plata/' . rawurlencode($token), $jarClient);
$verifica('„Plătește cu cardul”: formularul EuPlătesc, pe referința linkului (1057-PI1), „Comanda 1057”, 237.00',
    $formular['cod'] === 200 && str_contains($formular['corp'], 'name="invoice_id" value="1057-PI1"')
    && str_contains($formular['corp'], 'name="order_desc" value="Comanda 1057"') && str_contains($formular['corp'], 'name="amount" value="237.00"'),
    mb_substr(strip_tags($formular['corp']), 0, 400));

$ipn = static function (string $factura, string $suma, string $actiune = '0', string $epId = '') use ($cerere, $secretEu): array {
    $date = ['amount' => $suma, 'curr' => 'RON', 'invoice_id' => $factura, 'ep_id' => $epId !== '' ? $epId : 'EP' . strtoupper(bin2hex(random_bytes(6))),
        'merch_id' => 'comerciant-test', 'action' => $actiune, 'message' => $actiune === '0' ? 'Approved' : 'Insufficient funds',
        'approval' => $actiune === '0' ? 'A' . random_int(10000, 99999) : '', 'timestamp' => gmdate('YmdHis'), 'nonce' => bin2hex(random_bytes(8))];
    $date['fp_hash'] = EuPlatescGateway::hash(array_map(static fn (string $k): string => (string) $date[$k],
        ['amount', 'curr', 'invoice_id', 'ep_id', 'merch_id', 'action', 'message', 'approval', 'timestamp', 'nonce']), $secretEu);
    return $cerere('POST', '/webhook/euplatesc', null, $date);
};
$inainte = $comanda('1057');
$r = $ipn('1057-PI1', '237.00', '5');
$verifica('notificare de refuz pentru link: linkul rămâne de plătit, comanda neatinsă', $r['cod'] === 200
    && $unu('SELECT status FROM order_payment_links WHERE token = :t', ['t' => $token]) === 'pending'
    && $comanda('1057')['payment_status'] === $inainte['payment_status'] && $comanda('1057')['status'] === $inainte['status'], $r['corp']);
$r = $ipn('1057-PI1', '236.00');
$verifica('notificare aprobată cu altă sumă (236,00): refuzată, comanda neatinsă', $r['cod'] === 400
    && $unu('SELECT status FROM order_payment_links WHERE token = :t', ['t' => $token]) === 'pending'
    && $comanda('1057')['payment_status'] === 'failed', $r['corp']);
$emailuriInainte = $emailuriComanda($id1057, 'new_order');
$r = $ipn('1057-PI1', '237.00', '0', 'EPLINK1057');
$dupa = $comanda('1057');
$verifica('notificarea bună: linkul plătit (cu id-ul EuPlătesc)', $r['cod'] === 200 && $r['corp'] === 'ok'
    && $unu('SELECT status FROM order_payment_links WHERE token = :t', ['t' => $token]) === 'paid'
    && $unu('SELECT ep_id FROM order_payment_links WHERE token = :t', ['t' => $token]) === 'EPLINK1057', $r['corp']);
$verifica('… comanda plătită ca după checkout: „Plătit”, „eșuată” → „în așteptare”, încasat 237,00, motivul eșecului șters',
    $dupa['payment_status'] === 'paid' && $dupa['status'] === 'pending' && $dupa['paid_amount'] === '237.00' && $dupa['payment_error'] === null
    && $dupa['paid_at'] !== null, json_encode(array_intersect_key($dupa, array_flip(['status', 'payment_status', 'paid_amount', 'payment_error']))));
$verifica('… emailul „comandă nouă” (o dată) și comanda trimisă în ERP-ul fals (o dată, „trimisă”)',
    $emailuriComanda($id1057, 'new_order') === $emailuriInainte + 1 && $trimiseInErp('1057') === 1 && $dupa['erp_status'] === 'sent',
    'emailuri ' . $emailuriComanda($id1057, 'new_order') . ', ERP ' . $trimiseInErp('1057') . ' ' . $dupa['erp_status']);
$verifica('… plata eșuată de dinainte rămâne în jurnal (order_platita_link_integral, cu motivul ei)',
    str_contains($unu("SELECT context_json FROM admin_activity_log WHERE action = 'order_platita_link_integral' ORDER BY id DESC LIMIT 1"),
        '"order_id":' . $id1057));
$r = $ipn('1057-PI1', '237.00', '0', 'EPLINK1057');
$verifica('aceeași notificare a doua oară: nimic schimbat (încă un email sau o trimitere în ERP nu)', $r['cod'] === 200
    && $comanda('1057')['paid_amount'] === '237.00' && $emailuriComanda($id1057, 'new_order') === $emailuriInainte + 1 && $trimiseInErp('1057') === 1);
$pagina = $cerere('GET', '/plata/' . rawurlencode($token), $jarClient);
$verifica('pagina linkului după plată: „Plata a fost efectuată”', $pagina['cod'] === 200 && str_contains($pagina['corp'], 'Plata a fost efectuată')
    && str_contains($pagina['corp'], 'Mulțumim! Plata pentru comanda'));
$stare = $cerere('GET', '/admin/orders/' . $id1057 . '/payment-link', $jarAdmin);
$verifica('fereastra comenzii: linkul „plătit”, fără buton (comanda e plătită), fără notă', ($stare['json']['linkuri'][0]['stare'] ?? '') === 'plătit'
    && ($stare['json']['integrala']['arata'] ?? true) === false && ($stare['json']['platita_de_doua_ori'] ?? true) === false, $stare['corp']);

// Garda plății duble, 0: același link plătit a doua oară (alt ep_id).
$r = $ipn('1057-PI1', '237.00', '0', 'EPLINK1057B');
$dupa = $comanda('1057');
$verifica('același link plătit a doua oară (alt ep_id): nota „Plătită de două ori” numește plata de rambursat (EPLINK1057B) și pe cea socotită '
    . '(EPLINK1057); nimic adunat, în jurnal', $r['cod'] === 200
    && str_starts_with((string) $dupa['payment_error'], PaymentLink::NOTA_DUBLA . '. De rambursat (nesocotită pe comandă): EuPlătesc, ep_id EPLINK1057B, 237,00 lei')
    && str_contains((string) $dupa['payment_error'], 'Socotită pe comandă (nu o rambursa, nu o elibera): EuPlătesc, ep_id EPLINK1057, 237,00 lei')
    && $dupa['paid_amount'] === '237.00' && $unu('SELECT ep_id FROM order_payment_links WHERE token = :t', ['t' => $token]) === 'EPLINK1057'
    && str_contains($unu("SELECT context_json FROM admin_activity_log WHERE action = 'order_platita_de_doua_ori' ORDER BY id DESC LIMIT 1"), 'EPLINK1057B'),
    (string) $dupa['payment_error']);
// Nota se scrie din nou de la zero pentru cazul următor.
$db->prepare('UPDATE orders SET payment_error = NULL WHERE id = :id')->execute(['id' => $id1057]);

// Garda plății duble, 1: plata inițială (1057) se confirmă totuși, după link.
$r = $ipn('1057', '237.00', '0', 'EPINITIAL1057');
$dupa = $comanda('1057');
$verifica('plata inițială confirmată după link: nota numește exact plata de rambursat (EuPlătesc EPINITIAL1057) și cea socotită (linkul 1057-PI1, '
    . 'EPLINK1057); suma încasată tot 237,00, ep_id-ul comenzii neschimbat, niciun email sau ERP în plus',
    $r['cod'] === 200 && str_starts_with((string) $dupa['payment_error'], PaymentLink::NOTA_DUBLA . '. De rambursat (nesocotită pe comandă): EuPlătesc, ep_id EPINITIAL1057')
    && str_contains((string) $dupa['payment_error'], 'Socotită pe comandă (nu o rambursa, nu o elibera): EuPlătesc, ep_id EPLINK1057, 237,00 lei (linkul 1057-PI1)')
    && $dupa['euplatesc_ep_id'] !== 'EPINITIAL1057' && $dupa['paid_amount'] === '237.00'
    && $emailuriComanda($id1057, 'new_order') === $emailuriInainte + 1 && $trimiseInErp('1057') === 1, (string) $dupa['payment_error']);
$stare = $cerere('GET', '/admin/orders/' . $id1057 . '/payment-link', $jarAdmin);
$lista = $cerere('GET', '/admin/orders?search=1057', $jarAdmin)['corp'];
$verifica('… în admin: fereastra o spune („platita_de_doua_ori”, nota), lista arată „Motiv: Plătită de două ori…”',
    ($stare['json']['platita_de_doua_ori'] ?? false) === true && str_starts_with((string) ($stare['json']['nota'] ?? ''), 'Plătită de două ori')
    && str_contains($lista, 'Motiv: Plătită de două ori'), $stare['corp']);
$r = $ipn('1057', '237.00', '0', 'EPINITIAL1057');
$verifica('… notificarea repetată: nota rămâne, fără să se repete', str_starts_with((string) $comanda('1057')['payment_error'], PaymentLink::NOTA_DUBLA)
    && substr_count((string) $comanda('1057')['payment_error'], 'EPINITIAL1057') === 1);
$r = $ipn('1057', '200.00', '0', 'EPSUMA1057');
$verifica('o notificare cu altă sumă pe comanda plătită de două ori: „suma diferă” se adaugă, nota rămâne', $r['cod'] === 200
    && str_starts_with((string) $comanda('1057')['payment_error'], PaymentLink::NOTA_DUBLA . '.') && str_contains((string) $comanda('1057')['payment_error'], 'diferă')
    && $comanda('1057')['euplatesc_ep_id'] !== 'EPSUMA1057', (string) $comanda('1057')['payment_error']);

// Garda plății duble, 2: plata inițială (1045, „Plată în așteptare” de două zile) se confirmă înaintea linkului.
$c1045 = $comanda('1045');
$id1045 = (int) $c1045['id'];
$r = $trimite($id1045, $csrf);
$lk45 = $linkuri($id1045);
$verifica('comanda 1045 („Plată în așteptare” de două zile, EuPlătesc): linkul pentru 74,80 lei', ($r['json']['ok'] ?? false) === true
    && count($lk45) === 1 && $lk45[0]['amount'] === '74.80', $r['corp']);
$token45 = (string) ($lk45[0]['token'] ?? '');
$emailuri45 = $emailuriComanda($id1045, 'new_order');
$r = $ipn('1045', '74.80', '0', 'EPINITIAL1045');
$dupa = $comanda('1045');
$verifica('plata inițială confirmată totuși (callback întârziat): comanda plătită (o dată), linkul anulat',
    $r['cod'] === 200 && $dupa['payment_status'] === 'paid' && $dupa['paid_amount'] === '74.80'
    && $unu('SELECT status FROM order_payment_links WHERE token = :t', ['t' => $token45]) === 'cancelled'
    && $emailuriComanda($id1045, 'new_order') === $emailuri45 + 1, json_encode($dupa));
$pagina = $cerere('GET', '/plata/' . rawurlencode($token45), $jarClient);
$start = $cerere('POST', '/plata/' . rawurlencode($token45), $jarClient);
$verifica('… pagina linkului: „Comanda a fost deja plătită”, fără buton; „Plătește” nu mai pornește nicio plată', $pagina['cod'] === 404
    && str_contains($pagina['corp'], 'Comanda a fost deja plătită') && !str_contains($pagina['corp'], 'Plătește cu cardul')
    && $start['cod'] === 404 && !str_contains($start['corp'], 'invoice_id'));
$r = $trimite($id1045, $csrf);
$verifica('… și adminul nu mai poate trimite altul (comanda e plătită)', ($r['json']['ok'] ?? true) === false
    && str_contains((string) ($r['json']['error'] ?? ''), 'deja plătită'));
$r = $ipn('1045-PI1', '74.80', '0', 'EPLINK1045');
$dupa = $comanda('1045');
$verifica('… plata linkului vine totuși (clientul era deja pe pagina EuPlătesc): linkul plătit, nota numește plata linkului (EPLINK1045) de '
    . 'rambursat și plata inițială (EPINITIAL1045) socotită, nimic adunat, niciun email nou',
    $r['cod'] === 200 && $unu('SELECT status FROM order_payment_links WHERE token = :t', ['t' => $token45]) === 'paid'
    && str_starts_with((string) $dupa['payment_error'], PaymentLink::NOTA_DUBLA . '. De rambursat (nesocotită pe comandă): EuPlătesc, ep_id EPLINK1045')
    && str_contains((string) $dupa['payment_error'], 'Socotită pe comandă (nu o rambursa, nu o elibera): EuPlătesc, ep_id EPINITIAL1045')
    && $dupa['paid_amount'] === '74.80' && $emailuriComanda($id1045, 'new_order') === $emailuri45 + 1, json_encode($dupa, JSON_UNESCAPED_UNICODE));

// Felul linkului e cel de la creare: un link de plată întreagă nu devine „diferență” când crește totalul (1040).
$c1040 = $comanda('1040');
$id1040 = (int) $c1040['id'];
$r = $trimite($id1040, $csrf);
$lk40 = $linkuri($id1040)[0] ?? [];
$r2 = $ipn('1040', '128.00', '0', 'EPINITIAL1040');
$db->prepare('UPDATE orders SET total = 150.00 WHERE id = :id')->execute(['id' => $id1040]);
$pagina = $cerere('GET', '/plata/' . rawurlencode((string) ($lk40['token'] ?? '')), $jarClient);
$verifica('1040: linkul de plată întreagă (1040-PI1); plata inițială confirmată totuși îl anulează; cu totalul crescut la 150 (restul 22,00 < 128,00) '
    . 'pagina tot „Comanda a fost deja plătită”', ($r['json']['ok'] ?? false) === true && ($lk40['referinta'] ?? '') === '1040-PI1'
    && $unu('SELECT status FROM order_payment_links WHERE id = :id', ['id' => (int) ($lk40['id'] ?? 0)]) === 'cancelled'
    && $pagina['cod'] === 404 && str_contains($pagina['corp'], 'Comanda a fost deja plătită'), $r['corp'] . ' ' . $r2['corp']);
$r = $ipn('1040-PI1', '128.00', '0', 'EPLINK1040');
$dupa = $comanda('1040');
$verifica('… plătit totuși: dublură (nu „diferență”), nimic adunat (încasat tot 128,00), nota numește EPLINK1040', $r['cod'] === 200 && $dupa['paid_amount'] === '128.00'
    && str_contains((string) $dupa['payment_error'], 'De rambursat (nesocotită pe comandă): EuPlătesc, ep_id EPLINK1040'), json_encode($dupa, JSON_UNESCAPED_UNICODE));

// Linkurile pentru diferență: încasarea consemnată anulează linkul; pagina refuză un link mai mare decât restul; plătit totuși: nota (1028).
$c1028 = $comanda('1028');
$id1028 = (int) $c1028['id'];
$db->prepare('UPDATE orders SET total = 150.00 WHERE id = :id')->execute(['id' => $id1028]);
$r = $cerere('POST', '/admin/orders/' . $id1028 . '/payment-link', $jarAdmin, ['_csrf' => $csrf]);
$lk28 = $linkuri($id1028)[0] ?? [];
$verifica('1028 (plătită 122,80, total crescut la 150): linkul pentru diferență, 27,20 lei, „1028-P1”', ($r['json']['ok'] ?? false) === true
    && ($lk28['amount'] ?? '') === '27.20' && ($lk28['referinta'] ?? '') === '1028-P1', $r['corp']);
$r = $cerere('POST', '/admin/orders/' . $id1028 . '/incasare', $jarAdmin, ['suma' => '27.20', 'metoda' => 'op']);
$pagina = $cerere('GET', '/plata/' . rawurlencode((string) ($lk28['token'] ?? '')), $jarClient);
$verifica('… „Înregistrează încasarea” (OP, 27,20): linkul se anulează, pagina lui spune „Diferența a fost deja încasată”', ($r['json']['ok'] ?? false) === true
    && $unu('SELECT status FROM order_payment_links WHERE id = :id', ['id' => (int) ($lk28['id'] ?? 0)]) === 'cancelled'
    && $pagina['cod'] === 404 && str_contains($pagina['corp'], 'Diferența a fost deja încasată'), $r['corp']);
$r = $ipn('1028-P1', '27.20', '0', 'EPDIF1028');
$dupa = $comanda('1028');
$verifica('… plătit totuși (clientul era pe pagina EuPlătesc): suma se socotește (177,20), iar peste total nota numește 27,20 lei din EPDIF1028 de rambursat',
    $r['cod'] === 200 && $dupa['paid_amount'] === '177.20'
    && str_starts_with((string) $dupa['payment_error'], PaymentLink::NOTA_DUBLA . '. De rambursat (nesocotită pe comandă): 27,20 lei din EuPlătesc, ep_id EPDIF1028'),
    json_encode($dupa, JSON_UNESCAPED_UNICODE));
$c1033 = $comanda('1033');
$id1033 = (int) $c1033['id'];
$db->prepare('UPDATE orders SET total = 520.00 WHERE id = :id')->execute(['id' => $id1033]);
$lk33 = PaymentLink::creeaza($db, $id1033, '1033', 25.0, PaymentLink::PROCESATOR_EUPLATESC, []);
$db->prepare('UPDATE orders SET paid_amount = 510.00 WHERE id = :id')->execute(['id' => $id1033]);
$pagina = $cerere('GET', '/plata/' . rawurlencode((string) ($lk33['token'] ?? '')), $jarClient);
$start = $cerere('POST', '/plata/' . rawurlencode((string) ($lk33['token'] ?? '')), $jarClient);
$verifica('1033: o diferență de 25,00 cu restul scăzut la 10,00 nu mai pornește („au rămas de plătit 10,00 lei”), linkul se anulează', $pagina['cod'] === 404
    && str_contains($pagina['corp'], 'au rămas de plătit 10,00 lei') && $start['cod'] === 404 && !str_contains($start['corp'], 'invoice_id')
    && $unu('SELECT status FROM order_payment_links WHERE id = :id', ['id' => (int) ($lk33['id'] ?? 0)]) === 'cancelled');
$c1035 = $comanda('1035');
$id1035 = (int) $c1035['id'];
$db->prepare('UPDATE orders SET total = 130.00 WHERE id = :id')->execute(['id' => $id1035]);
$lkA = PaymentLink::creeaza($db, $id1035, '1035', 26.0, PaymentLink::PROCESATOR_EUPLATESC, []);
$db->prepare('UPDATE orders SET total = 140.00 WHERE id = :id')->execute(['id' => $id1035]);
$lkB = PaymentLink::creeaza($db, $id1035, '1035', 36.0, PaymentLink::PROCESATOR_EUPLATESC, []);
$r = $ipn((string) ($lkA['referinta'] ?? ''), '26.00', '0', 'EPDIFA1035');
$dupa = $comanda('1035');
$verifica('1035: linkul vechi (26,00, anulat de cel nou de 36,00) plătit totuși: socotit (130,00), iar linkul nou, mai mare decât restul de 10,00, se anulează; fără notă',
    $r['cod'] === 200 && $dupa['paid_amount'] === '130.00' && $unu('SELECT status FROM order_payment_links WHERE id = :id', ['id' => (int) ($lkB['id'] ?? 0)]) === 'cancelled'
    && !PaymentLink::platitaDeDouaOri($dupa), json_encode($dupa, JSON_UNESCAPED_UNICODE));

// Două notificări EuPlătesc deodată pentru aceeași plată (1042): comanda devine plătită o dată, un singur email, o singură trimitere în ERP.
$id1042 = (int) $comanda('1042')['id'];
$emailuri42 = $emailuriComanda($id1042, 'new_order');
$date42 = static function () use ($secretEu): array {
    $d = ['amount' => '203.80', 'curr' => 'RON', 'invoice_id' => '1042', 'ep_id' => 'EPPARALEL1042', 'merch_id' => 'comerciant-test', 'action' => '0',
        'message' => 'Approved', 'approval' => 'A42', 'timestamp' => gmdate('YmdHis'), 'nonce' => bin2hex(random_bytes(8))];
    $d['fp_hash'] = EuPlatescGateway::hash(array_map(static fn (string $k): string => (string) $d[$k],
        ['amount', 'curr', 'invoice_id', 'ep_id', 'merch_id', 'action', 'message', 'approval', 'timestamp', 'nonce']), $secretEu);
    return $d;
};
$multi = curl_multi_init();
$maner = [];
for ($i = 0; $i < 4; $i++) {
    $c = curl_init($baza . '/webhook/euplatesc');
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($date42()), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60]);
    curl_multi_add_handle($multi, $c);
    $maner[] = $c;
}
do {
    curl_multi_exec($multi, $active);
    curl_multi_select($multi, 0.2);
} while ($active > 0);
$coduri = array_map(static fn ($c): int => (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE), $maner);
foreach ($maner as $c) {
    curl_multi_remove_handle($multi, $c);
}
curl_multi_close($multi);
$verifica('patru notificări deodată (1042): toate 200, comanda plătită, un singur email „comandă nouă”, o singură trimitere în ERP',
    $coduri === [200, 200, 200, 200] && $comanda('1042')['payment_status'] === 'paid' && $emailuriComanda($id1042, 'new_order') === $emailuri42 + 1
    && $trimiseInErp('1042') === 1, json_encode($coduri) . ' emailuri ' . $emailuriComanda($id1042, 'new_order') . ' ERP ' . $trimiseInErp('1042'));

// O scriere care nu reușește (rândul comenzii blocat): EuPlătesc primește o eroare și reîncearcă; nimic pe jumătate (1036).
$id1036 = (int) $comanda('1036')['id'];
$r = $trimite($id1036, $csrf);
$lk36 = $linkuri($id1036)[0] ?? [];
$db->exec('SET GLOBAL innodb_lock_wait_timeout = 2');
$db->beginTransaction();
$db->query('SELECT id FROM orders WHERE id = ' . $id1036 . ' FOR UPDATE')->fetchAll();
$r = $ipn((string) ($lk36['referinta'] ?? ''), '195.00', '0', 'EPBLOCAT1036');
$db->commit();
$db->exec('SET GLOBAL innodb_lock_wait_timeout = 50');
$stareBlocat = [$unu('SELECT status FROM order_payment_links WHERE id = :id', ['id' => (int) ($lk36['id'] ?? 0)]), $comanda('1036')['payment_status']];
$r2 = $ipn((string) ($lk36['referinta'] ?? ''), '195.00', '0', 'EPBLOCAT1036');
$verifica('rândul comenzii blocat (scrierea nu reușește): notificarea primește 400, linkul rămâne de plătit, comanda neplătită; reîncercarea reușește',
    $r['cod'] === 400 && $stareBlocat === ['pending', 'unpaid'] && $r2['cod'] === 200
    && $unu('SELECT status FROM order_payment_links WHERE id = :id', ['id' => (int) ($lk36['id'] ?? 0)]) === 'paid'
    && $comanda('1036')['payment_status'] === 'paid', $r['cod'] . ' ' . json_encode($stareBlocat) . ' ' . $r2['cod']);

// Două trimiteri deodată, din două sesiuni de admin, pentru aceeași comandă (1043): un singur link.
$jarAdmin2 = $dosarDev . '/test-link-plata-admin2-' . getmypid() . '.txt';
$cerere('POST', '/admin/login', $jarAdmin2, ['email' => (string) (getenv('CMS_DEV_ADMIN_EMAIL') ?: 'admin@exemplu.ro'), 'password' => $parolaAdmin]);
$csrf2 = preg_match('/window\.LP_CSRF = "([0-9a-f]{64})";/', $cerere('GET', '/admin/orders', $jarAdmin2)['corp'], $m) === 1 ? $m[1] : '';
$id1043 = (int) $comanda('1043')['id'];
$db->prepare('UPDATE orders SET created_at = :c WHERE id = :id')->execute(['c' => date('Y-m-d H:i:s', time() - 2 * 86400), 'id' => $id1043]);
$multi = curl_multi_init();
$maner = [];
foreach ([[$jarAdmin, $csrf], [$jarAdmin2, $csrf2]] as [$jar, $jeton]) {
    $c = curl_init($baza . '/admin/orders/' . $id1043 . '/payment-link');
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['tip' => 'integrala', '_csrf' => $jeton]),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEFILE => $jar]);
    curl_multi_add_handle($multi, $c);
    $maner[] = $c;
}
do {
    curl_multi_exec($multi, $active);
    curl_multi_select($multi, 0.2);
} while ($active > 0);
$raspunsuri = array_map(static fn ($c): array => (array) json_decode((string) curl_multi_getcontent($c), true), $maner);
foreach ($maner as $c) {
    curl_multi_remove_handle($multi, $c);
}
curl_multi_close($multi);
@unlink($jarAdmin2);
$verifica('două trimiteri deodată (1043, din două sesiuni): amândouă reușesc, cu același link; un singur rând', ($raspunsuri[0]['ok'] ?? false) === true
    && ($raspunsuri[1]['ok'] ?? false) === true && ($raspunsuri[0]['url'] ?? 'a') === ($raspunsuri[1]['url'] ?? 'b') && count($linkuri($id1043)) === 1,
    json_encode($raspunsuri, JSON_UNESCAPED_UNICODE));

// ── D. Stripe ──────────────────────────────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== D. Stripe (singurul procesator pornit), pe un al doilea server cu Stripe-ul fals');
$secretWebhook = 'whsec_test_inventat';
foreach (['euplatesc_enabled' => '0', 'bt_ipay_enabled' => '0', 'stripe_enabled' => '1', 'stripe_secret_key' => 'sk_test_inventat',
    'stripe_webhook_secret' => $secretWebhook, 'stripe_api_version' => null] as $cheie => $valoare) {
    $setare($cheie, $valoare);
}
// Stripe-ul fals (tools/dev/lib/stripe-fals.php) și al doilea server al codului curent, cu STRIPE_API_BASE spre el.
$dosarStripe = $dosarDev . '/test-link-plata-stripe-' . getmypid();
@mkdir($dosarStripe, 0770, true);
file_put_contents($dosarStripe . '/cereri.jsonl', '');
$portStripe = $port + 63;
$port2 = $port + 64;
$baza2 = 'http://127.0.0.1:' . $port2;
$raspunsuriStripe = static function (array $r) use ($dosarStripe): void {
    file_put_contents($dosarStripe . '/raspunsuri.json', json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
};
$cereriStripe = static function () use ($dosarStripe): array {
    $lista = [];
    foreach (file($dosarStripe . '/cereri.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linie) {
        $lista[] = (array) json_decode($linie, true);
    }
    file_put_contents($dosarStripe . '/cereri.jsonl', '');
    return $lista;
};
$raspunsuriStripe([]);
$pornit2 = $porneste($radacina . '/tools/dev/lib/stripe-fals.php', $portStripe, ['STRIPE_FALS_DOSAR' => $dosarStripe]);
$pid2 = (int) trim((string) shell_exec('cd ' . escapeshellarg($radacina) . ' && env PHP_CLI_SERVER_WORKERS=4 CMS_DEV_PORT=' . $port2
    . ' STRIPE_API_BASE=' . escapeshellarg('http://127.0.0.1:' . $portStripe) . ' nohup tools/dev/php.sh -S 127.0.0.1:' . $port2
    . ' -t public tools/dev/router.php > /dev/null 2>&1 & echo $!'));
$procese[] = $pid2;
for ($i = 0; $i < 80 && $cerere('GET', $baza2 . '/health')['cod'] !== 200; $i++) {
    usleep(100000);
}
$cereriStripe();
$verifica('al doilea server (Stripe-ul fals ca STRIPE_API_BASE) pornește', $pornit2 && $cerere('GET', $baza2 . '/health')['cod'] === 200);
$cerere2 = static fn (string $metoda, string $cale, ?string $jar = null, array $date = [], array $antete = []): array
    => $cerere($metoda, $baza2 . $cale, $jar, $date, $antete);

// Comanda Stripe 1047 (anulată în datele demo) devine una cu plata eșuată, încă activă, cu sesiunea checkout-ului ei.
$db->exec("UPDATE orders SET status = 'failed', payment_status = 'failed', paid_amount = NULL, payment_error = 'Card refuzat (test)',
    stripe_session_id = 'cs_test_initiala1047', stripe_payment_intent_id = 'pi_test_initiala1047' WHERE order_number = '1047'");
$c1047 = $comanda('1047');
$id1047 = (int) $c1047['id'];
$trimite2 = static fn (int $orderId): array => $cerere2('POST', '/admin/orders/' . $orderId . '/payment-link', $jarAdmin,
    ['tip' => 'integrala', '_csrf' => $csrf], ['X-CSRF-Token: ' . $csrf]);
$raspunsuriStripe(['GET /v1/checkout/sessions/cs_test_initiala1047' => ['cod' => 500, 'corp' => ['error' => ['message' => 'Stripe căzut (test)']]]]);
$r = $trimite2($id1047);
$verifica('comanda Stripe: dacă Stripe nu spune ce e cu sesiunea checkout-ului, linkul nu se trimite', ($r['json']['ok'] ?? true) === false
    && str_contains((string) ($r['json']['error'] ?? ''), 'Nu am putut închide sesiunea') && $linkuri($id1047) === [], $r['corp']);
$raspunsuriStripe(['GET /v1/checkout/sessions/cs_test_initiala1047' => ['corp' => ['id' => 'cs_test_initiala1047', 'status' => 'complete', 'payment_status' => 'paid']]]);
$r = $trimite2($id1047);
$verifica('… dacă sesiunea checkout-ului apare plătită: refuz (se așteaptă webhook-ul), niciun link', ($r['json']['ok'] ?? true) === false
    && str_contains((string) ($r['json']['error'] ?? ''), 'apare plătită') && $linkuri($id1047) === [], $r['corp']);
$cereriStripe();
$raspunsuriStripe([
    'GET /v1/checkout/sessions/cs_test_initiala1047' => ['corp' => ['id' => 'cs_test_initiala1047', 'status' => 'open', 'payment_status' => 'unpaid']],
    'POST /v1/checkout/sessions/cs_test_initiala1047/expire' => ['corp' => ['id' => 'cs_test_initiala1047', 'status' => 'expired', 'payment_status' => 'unpaid']],
]);
$r = $trimite2($id1047);
$lk47 = $linkuri($id1047);
$cai = array_map(static fn (array $c): string => ($c['metoda'] ?? '') . ' ' . ($c['cale'] ?? ''), $cereriStripe());
$verifica('… deschisă: se expiră întâi (POST …/expire), apoi linkul nou e Stripe (63,00 lei, „1047-PI1”)', ($r['json']['ok'] ?? false) === true
    && ($r['json']['procesator'] ?? '') === 'Stripe' && in_array('POST /v1/checkout/sessions/cs_test_initiala1047/expire', $cai, true)
    && count($lk47) === 1 && $lk47[0]['procesator'] === 'stripe' && $lk47[0]['amount'] === '63.00' && $lk47[0]['referinta'] === '1047-PI1',
    $r['corp'] . ' ' . json_encode($cai));
$token47 = (string) $lk47[0]['token'];
$sesiuneLink = static fn (string $id, string $stare): array => ['id' => $id, 'object' => 'checkout.session', 'status' => $stare,
    'payment_status' => 'unpaid', 'url' => 'https://checkout.stripe.exemplu/pay/' . $id, 'metadata' => ['payment_link_token' => $token47]];
$raspunsuriStripe(['POST /v1/checkout/sessions' => ['corp' => $sesiuneLink('cs_test_linka', 'open')]]);
$r = $cerere2('POST', '/plata/' . rawurlencode($token47), $jarClient);
$creare = array_values(array_filter($cereriStripe(), static fn (array $c): bool => ($c['cale'] ?? '') === '/v1/checkout/sessions'));
parse_str((string) ($creare[0]['corp'] ?? ''), $campuriCreare);
$expira = (int) ($campuriCreare['expires_at'] ?? 0);
$verifica('„Plătește cu cardul”: o sesiune Stripe nouă, care expiră singură în ~35 de minute (≥ 30, minimul Stripe), id-ul ei ținut pe link',
    $r['cod'] === 303 && ($r['antete']['location'] ?? '') === 'https://checkout.stripe.exemplu/pay/cs_test_linka' && count($creare) === 1
    && $expira >= time() + 30 * 60 && $expira <= time() + 40 * 60 && ($campuriCreare['line_items'][0]['price_data']['product_data']['name'] ?? '') === 'Comanda 1047'
    && $unu('SELECT ep_id FROM order_payment_links WHERE token = :t', ['t' => $token47]) === 'cs_test_linka', $r['cod'] . ' ' . json_encode($campuriCreare));
$raspunsuriStripe(['GET /v1/checkout/sessions/cs_test_linka' => ['corp' => $sesiuneLink('cs_test_linka', 'open')],
    'POST /v1/checkout/sessions' => ['corp' => $sesiuneLink('cs_test_nu_trebuia', 'open')]]);
$r = $cerere2('POST', '/plata/' . rawurlencode($token47), $jarClient);
$cai = array_map(static fn (array $c): string => ($c['metoda'] ?? '') . ' ' . ($c['cale'] ?? ''), $cereriStripe());
$verifica('„Plătește” a doua oară: aceeași sesiune, încă deschisă (nicio sesiune nouă la Stripe)', $r['cod'] === 303
    && ($r['antete']['location'] ?? '') === 'https://checkout.stripe.exemplu/pay/cs_test_linka' && !in_array('POST /v1/checkout/sessions', $cai, true),
    json_encode($cai));
$raspunsuriStripe(['GET /v1/checkout/sessions/cs_test_linka' => ['corp' => $sesiuneLink('cs_test_linka', 'expired')],
    'POST /v1/checkout/sessions' => ['corp' => $sesiuneLink('cs_test_linkb', 'open')]]);
$r = $cerere2('POST', '/plata/' . rawurlencode($token47), $jarClient);
$verifica('… după ce sesiunea a expirat: una nouă (cs_test_linkb), ținută pe link', $r['cod'] === 303
    && ($r['antete']['location'] ?? '') === 'https://checkout.stripe.exemplu/pay/cs_test_linkb'
    && $unu('SELECT ep_id FROM order_payment_links WHERE token = :t', ['t' => $token47]) === 'cs_test_linkb');

$webhook = static function (array $eveniment) use ($baza, $secretWebhook): array {
    $corp = (string) json_encode($eveniment);
    $t = time();
    $c = curl_init($baza . '/webhook/stripe');
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $corp, CURLOPT_RETURNTRANSFER => true, CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '',
        CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ['Content-Type: application/json',
            'Stripe-Signature: t=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $corp, $secretWebhook)]]);
    $raspuns = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $cod, 'corp' => $raspuns];
};
$sesiune = static fn (array $metadate, int $suma, string $referinta, string $id = ''): array => ['id' => 'evt_test_' . bin2hex(random_bytes(4)),
    'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => $id !== '' ? $id : 'cs_test_' . bin2hex(random_bytes(6)),
        'object' => 'checkout.session', 'payment_status' => 'paid', 'amount_total' => $suma, 'currency' => 'ron', 'client_reference_id' => $referinta,
        'payment_intent' => 'pi_test_' . bin2hex(random_bytes(4)), 'metadata' => $metadate]]];
$emailuri47 = $emailuriComanda($id1047, 'new_order');
$r = $webhook($sesiune(['payment_link_token' => $token47], 6300, '1047-PI1', 'cs_test_linkb'));
$dupa = $comanda('1047');
$verifica('sesiunea Stripe a linkului plătită: linkul plătit, comanda plătită („eșuată” → „în așteptare”, 63,00), emailul, ERP-ul',
    $r['cod'] === 200 && $unu('SELECT status FROM order_payment_links WHERE token = :t', ['t' => $token47]) === 'paid'
    && $dupa['payment_status'] === 'paid' && $dupa['status'] === 'pending' && $dupa['paid_amount'] === '63.00'
    && $emailuriComanda($id1047, 'new_order') === $emailuri47 + 1 && $trimiseInErp('1047') === 1, $r['corp'] . ' ' . json_encode($dupa));
$r = $webhook($sesiune(['payment_link_token' => $token47], 6300, '1047-PI1', 'cs_test_linka'));
$dupa = $comanda('1047');
$verifica('același link plătit și pe sesiunea veche (cs_test_linka): nota numește cs_test_linka de rambursat, cs_test_linkb socotită; nimic adunat',
    $r['cod'] === 200 && $dupa['paid_amount'] === '63.00'
    && str_starts_with((string) $dupa['payment_error'], PaymentLink::NOTA_DUBLA . '. De rambursat (nesocotită pe comandă): Stripe, sesiunea cs_test_linka')
    && str_contains((string) $dupa['payment_error'], 'Socotită pe comandă (nu o rambursa, nu o elibera): Stripe, sesiunea cs_test_linkb')
    && str_contains((string) $dupa['payment_error'], 'panoul Stripe'), (string) $dupa['payment_error']);
$r = $webhook($sesiune(['order_number' => '1047', 'order_id' => (string) $id1047], 6300, '1047', 'cs_test_initiala1047'));
$dupa = $comanda('1047');
$verifica('sesiunea checkout-ului venită după (cs_test_initiala1047): adăugată la notă ca plată de rambursat; sesiunea comenzii, suma și emailurile neschimbate',
    $r['cod'] === 200 && str_contains((string) $dupa['payment_error'], 'De rambursat (nesocotită pe comandă): Stripe, sesiunea cs_test_initiala1047')
    && $dupa['paid_amount'] === '63.00' && $emailuriComanda($id1047, 'new_order') === $emailuri47 + 1 && $trimiseInErp('1047') === 1,
    (string) $dupa['payment_error']);
$r = $webhook(['id' => 'evt_exp_' . bin2hex(random_bytes(4)), 'type' => 'checkout.session.expired', 'data' => ['object' => ['id' => 'cs_test_initiala1047',
    'object' => 'checkout.session', 'payment_status' => 'unpaid', 'status' => 'expired', 'metadata' => ['order_number' => '1047', 'order_id' => (string) $id1047]]]]);
$verifica('… o sesiune expirată a comenzii plătite nu atinge nimic (nici nota, nici sesiunea socotită)', $r['cod'] === 200
    && $comanda('1047')['payment_status'] === 'paid' && PaymentLink::platitaDeDouaOri($comanda('1047'))
    && $comanda('1047')['stripe_session_id'] === 'cs_test_initiala1047');

// Sesiunea checkout-ului unei comenzi noi expiră și ea singură, odată cu plățile neterminate (bt_ipay_expire_minutes = 60).
putenv('STRIPE_API_BASE=http://127.0.0.1:' . $portStripe);
$_ENV['STRIPE_API_BASE'] = 'http://127.0.0.1:' . $portStripe;
$raspunsuriStripe(['POST /v1/checkout/sessions' => ['corp' => ['id' => 'cs_test_checkout', 'url' => 'https://checkout.stripe.exemplu/pay/cs_test_checkout']]]);
$cereriStripe();
$site = (new ReflectionClass(\App\Http\Controllers\SiteController::class))->newInstanceWithoutConstructor();
$c1026 = $comanda('1026');
(new ReflectionMethod($site, 'createStripeCheckoutSession'))->invoke($site, $db, (int) $c1026['id'], (string) $c1026['order_number'],
    ['total' => (float) $c1026['total']], ['billing_email' => 'client@exemplu.ro']);
parse_str((string) ($cereriStripe()[0]['corp'] ?? ''), $campuriCheckout);
$expira = (int) ($campuriCheckout['expires_at'] ?? 0);
$verifica('checkout-ul Stripe: sesiunea expiră după ~61 de minute (expirarea plăților + 1), nu după 24 de ore', $expira >= time() + 60 * 60
    && $expira <= time() + 63 * 60, json_encode($campuriCheckout));
putenv('STRIPE_API_BASE');
unset($_ENV['STRIPE_API_BASE']);

// ── E. Banca Transilvania (în procesul testului, banca falsă) ───────────────────────────────────────────────────────────
$scrie('');
$scrie('== E. Banca Transilvania: plata obișnuită a comenzii, din link (banca falsă)');
foreach (['BT_IPAY_MODE' => 'live', 'BT_IPAY_LIVE_USER' => 'fals', 'BT_IPAY_LIVE_PASS' => 'fals', 'BT_IPAY_LIVE_CALLBACK_KEY' => 'cheie-falsa',
    'BT_IPAY_BASE_URL_OVERRIDE' => 'http://127.0.0.1:' . $portBt] as $cheie => $valoare) {
    putenv($cheie . '=' . $valoare);
    $_ENV[$cheie] = $valoare;
}
foreach (['euplatesc_enabled' => '1', 'bt_ipay_enabled' => '1', 'stripe_enabled' => '0'] as $cheie => $valoare) {
    $setare($cheie, $valoare);
}
$stariBt = static function (array $schimbari) use ($dosarBt): void {
    $stari = is_file($dosarBt . '/stari.json') ? (json_decode((string) file_get_contents($dosarBt . '/stari.json'), true) ?: []) : [];
    foreach ($schimbari as $id => $s) {
        $stari[$id] = array_replace_recursive($stari[$id] ?? [], $s);
    }
    file_put_contents($dosarBt . '/stari.json', json_encode($stari));
};
$autorizata = static fn (int $suma): array => ['orderStatus' => 1, 'amount' => $suma, 'currency' => '946', 'actionCode' => 0,
    'cardAuthInfo' => ['approvalCode' => 'BT' . random_int(1000, 9999)], 'paymentAmountInfo' => ['approvedAmount' => $suma, 'depositedAmount' => 0]];
$verifica('BT în producție, deschis clienților, pe banca falsă: linkurile noi merg prin BT', BtIpayGateway::mod() === BtIpayGateway::MOD_LIVE
    && PaymentLink::procesorPentruLinkNou($setari()) === PaymentLink::PROCESATOR_BT);
// Plata inițială a comenzii 1058, refuzată la bancă (rândul ei rămâne).
$idInitiala = $txBt($c1058, 'declined', 7200, 0, '1058');
$posibil = PaymentLink::plataIntegrala($db, $comanda('1058'), $setari());
$linkBt = $posibil['ok'] ? PaymentLink::creeaza($db, $id1058, '1058', $posibil['suma'], PaymentLink::procesorPentruLinkNou($setari()), $setari(),
    PaymentLink::TIP_INTEGRALA) : null;
$verifica('comanda BT 1058 cu plata refuzată: linkul de plată întreagă (193,00), prin BT', is_array($linkBt) && $linkBt['procesator'] === 'btipay'
    && $linkBt['amount'] === '193.00', json_encode([$posibil, $linkBt], JSON_UNESCAPED_UNICODE));
$start = BtIpayPayments::pornestePlataIntegrala($db, (array) $linkBt, 'http://127.0.0.1:' . $port . '/checkout/bt/retur', 'Mozilla/5.0');
$tx = BtIpayPayments::tx($db, (int) $start['tx_id']) ?? [];
$verifica('„Plătește cu cardul”: o plată obișnuită a comenzii la bancă („1058-R2”, „Comanda 1058”, 193,00), cu linkul pe ea, pagina băncii',
    $start['ok'] && ($tx['kind'] ?? '') === 'order' && (int) ($tx['link_id'] ?? 0) === (int) $linkBt['id'] && ($tx['bt_order_number'] ?? '') === '1058-R2'
    && (int) ($tx['amount_minor'] ?? 0) === 19300 && str_contains($start['url'], '/pagina-de-plata/fals-1058-R2')
    && str_contains((string) file_get_contents($dosarBt . '/cereri.jsonl'), 'Comanda 1058'), json_encode([$start, $tx]));
$din2 = BtIpayPayments::pornestePlataIntegrala($db, (array) PaymentLink::dupaId($db, (int) $linkBt['id']), 'http://127.0.0.1:' . $port . '/checkout/bt/retur');
$verifica('„Plătește” a doua oară în 10 minute: aceeași plată (aceeași pagină a băncii), nimic nou la bancă', $din2['ok'] && $din2['tx_id'] === $start['tx_id']
    && (int) $unu('SELECT COUNT(*) FROM bt_ipay_transactions WHERE order_id = :o', ['o' => $id1058]) === 2);
$emailuri58 = $emailuriComanda($id1058, 'new_order');
$stariBt(['fals-1058-R2' => $autorizata(19300)]);
$callback = BtIpayPayments::verificaDinCallback($db, (int) $start['tx_id']);
$tx = BtIpayPayments::tx($db, (int) $start['tx_id']) ?? [];
$dupa = $comanda('1058');
$verifica('callback-ul (autorizarea la bancă): plata autorizată și socotită pe comandă, încă neîncasată (încasarea vine la factura din ERP)',
    $callback['ok'] && ($tx['state'] ?? '') === 'authorized' && (int) ($tx['in_comanda'] ?? 0) === 1 && array_key_exists('deposit_requested_at', $tx) && $tx['deposit_requested_at'] === null
    && (int) ($tx['deposited_minor'] ?? -1) === 0, json_encode([$callback, $tx]));
$verifica('… comanda plătită ca după checkout: „Plătit”, „eșuată” → „în așteptare”, încasat 193,00; linkul plătit',
    $dupa['payment_status'] === 'paid' && $dupa['status'] === 'pending' && $dupa['paid_amount'] === '193.00'
    && $unu('SELECT status FROM order_payment_links WHERE id = :id', ['id' => (int) $linkBt['id']]) === 'paid', json_encode($dupa));
$verifica('… emailul „comandă nouă” și comanda trimisă în ERP-ul fals; plata refuzată de dinainte rămâne', $emailuriComanda($id1058, 'new_order') === $emailuri58 + 1
    && $trimiseInErp('1058') === 1 && $dupa['erp_status'] === 'sent' && $unu('SELECT state FROM bt_ipay_transactions WHERE id = :id', ['id' => $idInitiala]) === 'declined');
$aprobare = BtIpayPayments::incaseazaLaAprobare($db, $id1058, 193.0, $setari());
$tx = BtIpayPayments::tx($db, (int) $start['tx_id']) ?? [];
$verifica('aprobarea comenzii în ERP: plata se încasează (2 faze, ca la checkout)', ($tx['state'] ?? '') === 'deposited'
    && (int) ($tx['deposited_minor'] ?? 0) === 19300, json_encode([$aprobare, $tx], JSON_UNESCAPED_UNICODE));

// O diferență BT pe aceeași comandă (total crescut cu 20 de lei), încasată imediat; apoi rambursată: ERP-ul află de fiecare dată suma.
$db->prepare('UPDATE orders SET total = 213.00 WHERE id = :id')->execute(['id' => $id1058]);
$linkDif = PaymentLink::creeaza($db, $id1058, '1058', 20.0, PaymentLink::PROCESATOR_BT, $setari());
$startDif = BtIpayPayments::pornestePlataLink($db, (array) $linkDif, 'http://127.0.0.1:' . $port . '/checkout/bt/retur');
$numarDif = (string) ($startDif['numar'] ?? '');
$stariBt(['fals-' . $numarDif => $autorizata(2000)]);
$erp58 = $trimiseInErp('1058');
BtIpayPayments::verificaDinCallback($db, (int) $startDif['tx_id']);
$txDif = BtIpayPayments::tx($db, (int) $startDif['tx_id']) ?? [];
$verifica('diferența BT (20,00, „' . $numarDif . '”): autorizată, încasată imediat, socotită (213,00), comanda trimisă din nou în ERP',
    $startDif['ok'] && ($txDif['state'] ?? '') === 'deposited' && (int) ($txDif['in_comanda'] ?? 0) === 1 && $comanda('1058')['paid_amount'] === '213.00'
    && $trimiseInErp('1058') === $erp58 + 1, json_encode([$startDif, $txDif]));
$rambursare = BtIpayPayments::ramburseaza($db, (int) $startDif['tx_id'], 2000, 'admin');
$verifica('… rambursată din admin: încasat 193,00, iar comanda pleacă din nou în ERP (cu suma nouă)', $rambursare['ok']
    && $comanda('1058')['paid_amount'] === '193.00' && $trimiseInErp('1058') === $erp58 + 2, json_encode($rambursare, JSON_UNESCAPED_UNICODE));

// Pe o comandă „Plătită de două ori”, plata BT socotită nu se rambursează și nu se eliberează din fereastră fără confirmarea explicită.
$db->prepare('UPDATE orders SET payment_error = :n WHERE id = :id')->execute(['id' => $id1058,
    'n' => PaymentLink::NOTA_DUBLA . '. De rambursat (nesocotită pe comandă): EuPlătesc, ep_id EPTEST, 193,00 lei. Socotită pe comandă: Banca Transilvania, plata 1058-R2.']);
$jurnalInainte = (int) $unu('SELECT COUNT(*) FROM bt_ipay_log');
$r = $cerere('POST', '/admin/orders/' . $id1058 . '/bt/refund', $jarAdmin, ['tx_id' => (string) $start['tx_id'], 'suma' => '193,00', '_csrf' => $csrf]);
$r2 = $cerere('POST', '/admin/orders/' . $id1058 . '/bt/reverse', $jarAdmin, ['tx_id' => (string) $start['tx_id'], '_csrf' => $csrf, 'confirm_activa' => '1']);
$verifica('comanda „Plătită de două ori”: „Rambursează” / „Anulează autorizarea” pe plata BT socotită (1058-R2) sunt refuzate, cu nota (fără niciun apel la bancă)',
    ($r['json']['ok'] ?? true) === false && ($r['json']['cere_confirmare_dubla'] ?? false) === true && str_contains((string) ($r['json']['error'] ?? ''), 'SOCOTITĂ')
    && str_contains((string) ($r['json']['error'] ?? ''), 'EPTEST') && ($r2['json']['cere_confirmare_dubla'] ?? false) === true
    && (int) $unu('SELECT COUNT(*) FROM bt_ipay_log') === $jurnalInainte, $r['corp'] . ' ' . $r2['corp']);
$r = $cerere('POST', '/admin/orders/' . $id1058 . '/bt/status', $jarAdmin, ['tx_id' => (string) $start['tx_id'], '_csrf' => $csrf]);
$r3 = $cerere('POST', '/admin/orders/' . $id1058 . '/bt/refund', $jarAdmin, ['tx_id' => (string) $start['tx_id'], 'suma' => '1,00', '_csrf' => $csrf,
    'confirm_dubla' => '1']);
$verifica('… verificarea stării merge; cu confirm_dubla=1 rambursarea trece de gardă (aici serverul e în modul test, deci nu pleacă nimic)',
    !isset($r['json']['cere_confirmare_dubla']) && !isset($r3['json']['cere_confirmare_dubla']), $r['corp'] . ' ' . $r3['corp']);
$db->prepare('UPDATE orders SET payment_error = NULL WHERE id = :id')->execute(['id' => $id1058]);

// Garda la BT: plata inițială (1044, „Plată în așteptare” de două zile) se autorizează totuși, după ce linkul a pornit.
$c1044 = $comanda('1044');
$id1044 = (int) $c1044['id'];
$initiala44 = $txBt($c1044, 'registered', 7200, 0, '1044');
$posibil = PaymentLink::plataIntegrala($db, $c1044, $setari());
$link44 = $posibil['ok'] ? PaymentLink::creeaza($db, $id1044, '1044', $posibil['suma'], PaymentLink::PROCESATOR_BT, $setari(), PaymentLink::TIP_INTEGRALA) : null;
$start44 = is_array($link44) ? BtIpayPayments::pornestePlataIntegrala($db, $link44, 'http://127.0.0.1:' . $port . '/checkout/bt/retur') : ['ok' => false, 'tx_id' => 0];
$verifica('comanda BT 1044 cu plata începută acum două ore: linkul se trimite, plata lui pornește („1044-R2”)', $start44['ok']
    && $unu('SELECT bt_order_number FROM bt_ipay_transactions WHERE id = :id', ['id' => (int) $start44['tx_id']]) === '1044-R2', json_encode([$posibil, $start44]));
$stariBt(['fals-1044' => $autorizata(11300)]);
$cron = BtIpayPayments::cron($db, $setari());
$dupa = $comanda('1044');
$verifica('cronul BT găsește plata inițială autorizată totuși: comanda plătită din ea, linkul anulat', $dupa['payment_status'] === 'paid'
    && $dupa['paid_amount'] === '113.00' && $unu('SELECT status FROM order_payment_links WHERE id = :id', ['id' => (int) $link44['id']]) === 'cancelled'
    && $unu('SELECT state FROM bt_ipay_transactions WHERE id = :id', ['id' => $initiala44]) === 'authorized', json_encode([$cron, $dupa]));
$stariBt(['fals-1044-R2' => $autorizata(11300)]);
BtIpayPayments::verificaDinCallback($db, (int) $start44['tx_id']);
$tx44 = BtIpayPayments::tx($db, (int) $start44['tx_id']) ?? [];
$verifica('… plata linkului autorizată după: eliberată singură la bancă (nicio sumă luată de două ori), nesocotită; comanda tot 113,00',
    ($tx44['state'] ?? '') === 'reversed' && (int) ($tx44['in_comanda'] ?? 1) === 0 && $comanda('1044')['paid_amount'] === '113.00'
    && str_contains((string) file_get_contents($dosarBt . '/cereri.jsonl'), '"cale":"/payment/rest/reverse.do","parametri":{"orderId":"fals-1044-R2"}'),
    json_encode($tx44));
$din3 = BtIpayPayments::pornestePlataIntegrala($db, (array) PaymentLink::dupaId($db, (int) $link44['id']), 'http://127.0.0.1:' . $port . '/checkout/bt/retur');
$verifica('… și linkul anulat nu mai pornește nicio plată', !$din3['ok']);
foreach (['BT_IPAY_MODE' => 'test', 'BT_IPAY_LIVE_USER' => '', 'BT_IPAY_LIVE_PASS' => '', 'BT_IPAY_BASE_URL_OVERRIDE' => 'http://127.0.0.1:9'] as $cheie => $valoare) {
    putenv($cheie . '=' . $valoare);
    $_ENV[$cheie] = $valoare;
}

// ── F. Curățenia ──────────────────────────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== F. Curățenia');
$puneLaLoc();
foreach (glob($dosarStripe . '/*') ?: [] as $fisier) {
    @unlink($fisier);
}
@rmdir($dosarStripe);
$verifica('baza pusă la loc: CHECKSUM TABLE identic pe fiecare tabel', $sumeBaza() === $sumeInainte,
    implode(', ', array_keys(array_diff_assoc($sumeBaza(), $sumeInainte))));
$erori = is_file($fisierErori) ? (string) file_get_contents($fisierErori, false, null, $pozitieErori) : '';
$verifica('jurnalul PHP: niciun Warning, Notice sau Fatal nou', preg_match('/PHP (Warning|Notice|Fatal|Parse)/', $erori) !== 1, mb_substr($erori, 0, 1200));

$scrie('');
$scrie('Rezultat: ' . $trecute . ' trecute, ' . $esuate . ' eșuate. Raport: ' . $fisierRaport);
exit($esuate === 0 ? 0 : 1);
