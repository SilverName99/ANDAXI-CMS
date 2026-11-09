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
 * D. Stripe (singurul procesator pornit, ca la Biovitality): linkul nou e Stripe, sesiunea plătită face comanda plătită,
 *    sesiunea plății inițiale venită după: nota.
 * E. Banca Transilvania, în procesul testului, în producție pe o bancă falsă (tools/dev/lib/bt-fals.php): comanda BT eșuată →
 *    link → plata obișnuită a comenzii („{nr}-R2”, cu linkul pe ea) → callback-ul (autorizarea) → comanda plătită, emailul,
 *    trimisă în ERP; încasarea abia la aprobarea din ERP; garda: plata inițială autorizată totuși (găsită de cronul BT) anulează
 *    linkul, iar plata linkului autorizată după se eliberează singură.
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
    WHERE order_number IN ('1045', '1057', '1058', '1044', '1047')");
$setari = static fn (): array => \App\Support\Settings::all($db);

// ── A. Regulile, fără server ───────────────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== A. Regulile, fără server');
$c1057 = $comanda('1057'); // EuPlătesc, plata eșuată, 237,00 lei
$c1028 = $comanda('1028'); // EuPlătesc, plătită
$c1026 = $comanda('1026'); // ramburs
$c1047 = $comanda('1047'); // Stripe, anulată
$c1001 = $comanda('1001'); // ramburs, finalizată
$verifica('felul linkului după sumă: totalul = plata întreagă, mai puțin = diferență',
    PaymentLink::esteIntegral(['amount' => '237.00'], $c1057) && PaymentLink::esteIntegral(['amount' => '236.995'], $c1057)
    && !PaymentLink::esteIntegral(['amount' => '25.00'], $c1057) && !PaymentLink::esteIntegral(['amount' => '25.00'], ['total' => 0]));
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
$linkProba = ['status' => 'pending', 'amount' => '237.00'];
$verifica('linkul de plată întreagă nu se mai plătește pe o comandă plătită, anulată sau cu totalul scăzut; o diferență nu se atinge',
    str_contains(PaymentLink::motivLinkNeplatibil($linkProba, $c1028 + ['total' => '237.00']), 'deja plătită')
    && str_contains(PaymentLink::motivLinkNeplatibil($linkProba, ['status' => 'cancelled'] + $c1057), 'anulată')
    && str_contains(PaymentLink::motivLinkNeplatibil(['amount' => '300.00'] + $linkProba, $c1057), 'Totalul comenzii')
    && PaymentLink::motivLinkNeplatibil($linkProba, $c1057) === ''
    && PaymentLink::motivLinkNeplatibil(['status' => 'pending', 'amount' => '20.00'], $c1028) === ''
    && PaymentLink::motivLinkNeplatibil(['status' => 'paid'] + $linkProba, $c1028) === '');

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
    && $lk[0]['referinta'] === '1057-P1', $r['corp'] . ' ' . json_encode($lk));
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
$linkInCurs = PaymentLink::creeaza($db, $id1058, '1058', 193.0, PaymentLink::PROCESATOR_BT, []);
$idInCurs = $txBt($c1058, 'registered', 120, (int) ($linkInCurs['id'] ?? 0));
$r = $trimite($id1058, $csrf);
$verifica('refuz: plata linkului trimis e încă în curs la bancă', ($r['json']['ok'] ?? true) === false
    && str_contains((string) ($r['json']['error'] ?? ''), 'în curs') && count($linkuri($id1058)) === 1, $r['corp']);
$db->prepare('DELETE FROM bt_ipay_transactions WHERE id = :id')->execute(['id' => $idInCurs]);
$db->prepare('DELETE FROM order_payment_links WHERE order_id = :id')->execute(['id' => $id1058]);
$r = $cerere('POST', '/admin/orders/' . $id1057 . '/payment-link', $jarAdmin);
$verifica('„Trimite link de plată pentru diferență” (fără tip) rămâne cum era: comanda nu are diferență', ($r['json']['ok'] ?? true) === false
    && ($r['json']['error'] ?? '') === 'Comanda nu are diferență de încasat.', $r['corp']);
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
$verifica('„Plătește cu cardul”: formularul EuPlătesc, pe referința linkului (1057-P1), „Comanda 1057”, 237.00',
    $formular['cod'] === 200 && str_contains($formular['corp'], 'name="invoice_id" value="1057-P1"')
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
$r = $ipn('1057-P1', '237.00', '5');
$verifica('notificare de refuz pentru link: linkul rămâne de plătit, comanda neatinsă', $r['cod'] === 200
    && $unu('SELECT status FROM order_payment_links WHERE token = :t', ['t' => $token]) === 'pending'
    && $comanda('1057')['payment_status'] === $inainte['payment_status'] && $comanda('1057')['status'] === $inainte['status'], $r['corp']);
$r = $ipn('1057-P1', '236.00');
$verifica('notificare aprobată cu altă sumă (236,00): refuzată, comanda neatinsă', $r['cod'] === 400
    && $unu('SELECT status FROM order_payment_links WHERE token = :t', ['t' => $token]) === 'pending'
    && $comanda('1057')['payment_status'] === 'failed', $r['corp']);
$emailuriInainte = $emailuriComanda($id1057, 'new_order');
$r = $ipn('1057-P1', '237.00', '0', 'EPLINK1057');
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
$r = $ipn('1057-P1', '237.00', '0', 'EPLINK1057');
$verifica('aceeași notificare a doua oară: nimic schimbat (încă un email sau o trimitere în ERP nu)', $r['cod'] === 200
    && $comanda('1057')['paid_amount'] === '237.00' && $emailuriComanda($id1057, 'new_order') === $emailuriInainte + 1 && $trimiseInErp('1057') === 1);
$pagina = $cerere('GET', '/plata/' . rawurlencode($token), $jarClient);
$verifica('pagina linkului după plată: „Plata a fost efectuată”', $pagina['cod'] === 200 && str_contains($pagina['corp'], 'Plata a fost efectuată')
    && str_contains($pagina['corp'], 'Mulțumim! Plata pentru comanda'));
$stare = $cerere('GET', '/admin/orders/' . $id1057 . '/payment-link', $jarAdmin);
$verifica('fereastra comenzii: linkul „plătit”, fără buton (comanda e plătită), fără notă', ($stare['json']['linkuri'][0]['stare'] ?? '') === 'plătit'
    && ($stare['json']['integrala']['arata'] ?? true) === false && ($stare['json']['platita_de_doua_ori'] ?? true) === false, $stare['corp']);

// Garda plății duble, 1: plata inițială (1057) se confirmă totuși, după link.
$r = $ipn('1057', '237.00', '0', 'EPINITIAL1057');
$dupa = $comanda('1057');
$verifica('plata inițială confirmată după link: comanda marcată „Plătită de două ori”, cu ce e de rambursat; suma încasată tot 237,00, niciun email sau ERP în plus',
    $r['cod'] === 200 && str_starts_with((string) $dupa['payment_error'], PaymentLink::NOTA_DUBLA . ':') && str_contains((string) $dupa['payment_error'], '1057-P1')
    && str_contains((string) $dupa['payment_error'], 'Rambursează') && $dupa['paid_amount'] === '237.00'
    && $emailuriComanda($id1057, 'new_order') === $emailuriInainte + 1 && $trimiseInErp('1057') === 1, (string) $dupa['payment_error']);
$stare = $cerere('GET', '/admin/orders/' . $id1057 . '/payment-link', $jarAdmin);
$lista = $cerere('GET', '/admin/orders?search=1057', $jarAdmin)['corp'];
$verifica('… în admin: fereastra o spune („platita_de_doua_ori”, nota), lista arată „Motiv: Plătită de două ori…”',
    ($stare['json']['platita_de_doua_ori'] ?? false) === true && str_starts_with((string) ($stare['json']['nota'] ?? ''), 'Plătită de două ori')
    && str_contains($lista, 'Motiv: Plătită de două ori'), $stare['corp']);
$r = $ipn('1057', '237.00', '0', 'EPINITIAL1057');
$verifica('… notificarea repetată: nota rămâne', str_starts_with((string) $comanda('1057')['payment_error'], PaymentLink::NOTA_DUBLA));

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
$r = $ipn('1045-P1', '74.80', '0', 'EPLINK1045');
$dupa = $comanda('1045');
$verifica('… plata linkului vine totuși (clientul era deja pe pagina EuPlătesc): linkul plătit, comanda „Plătită de două ori”, nimic adunat, niciun email nou',
    $r['cod'] === 200 && $unu('SELECT status FROM order_payment_links WHERE token = :t', ['t' => $token45]) === 'paid'
    && str_starts_with((string) $dupa['payment_error'], PaymentLink::NOTA_DUBLA . ':') && $dupa['paid_amount'] === '74.80'
    && $emailuriComanda($id1045, 'new_order') === $emailuri45 + 1, json_encode($dupa, JSON_UNESCAPED_UNICODE));

// ── D. Stripe ──────────────────────────────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== D. Stripe (singurul procesator pornit)');
$secretWebhook = 'whsec_test_inventat';
foreach (['euplatesc_enabled' => '0', 'bt_ipay_enabled' => '0', 'stripe_enabled' => '1', 'stripe_secret_key' => 'sk_test_inventat',
    'stripe_webhook_secret' => $secretWebhook] as $cheie => $valoare) {
    $setare($cheie, $valoare);
}
// Comanda Stripe 1047 (anulată în datele demo) devine una cu plata eșuată, încă activă.
$db->exec("UPDATE orders SET status = 'failed', payment_status = 'failed', paid_amount = NULL, payment_error = 'Card refuzat (test)' WHERE order_number = '1047'");
$c1047 = $comanda('1047');
$id1047 = (int) $c1047['id'];
$r = $trimite($id1047, $csrf);
$lk47 = $linkuri($id1047);
$verifica('linkul nou al comenzii Stripe eșuate e Stripe (63,00 lei)', ($r['json']['ok'] ?? false) === true && ($r['json']['procesator'] ?? '') === 'Stripe'
    && count($lk47) === 1 && $lk47[0]['procesator'] === 'stripe' && $lk47[0]['amount'] === '63.00', $r['corp']);
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
$sesiune = static fn (array $metadate, int $suma, string $referinta): array => ['id' => 'evt_test_' . bin2hex(random_bytes(4)),
    'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_' . bin2hex(random_bytes(6)), 'object' => 'checkout.session',
        'payment_status' => 'paid', 'amount_total' => $suma, 'currency' => 'ron', 'client_reference_id' => $referinta,
        'payment_intent' => 'pi_test_' . bin2hex(random_bytes(4)), 'metadata' => $metadate]]];
$emailuri47 = $emailuriComanda($id1047, 'new_order');
$r = $webhook($sesiune(['payment_link_token' => (string) $lk47[0]['token']], 6300, (string) $lk47[0]['referinta']));
$dupa = $comanda('1047');
$verifica('sesiunea Stripe a linkului plătită: linkul plătit, comanda plătită („eșuată” → „în așteptare”, 63,00), emailul, ERP-ul',
    $r['cod'] === 200 && $unu('SELECT status FROM order_payment_links WHERE id = :id', ['id' => (int) $lk47[0]['id']]) === 'paid'
    && $dupa['payment_status'] === 'paid' && $dupa['status'] === 'pending' && $dupa['paid_amount'] === '63.00'
    && $emailuriComanda($id1047, 'new_order') === $emailuri47 + 1 && $trimiseInErp('1047') === 1, $r['corp'] . ' ' . json_encode($dupa));
$r = $webhook($sesiune(['order_number' => '1047', 'order_id' => (string) $id1047], 6300, '1047'));
$verifica('sesiunea plății inițiale venită după: „Plătită de două ori” (Stripe), suma tot 63,00', $r['cod'] === 200
    && str_starts_with((string) $comanda('1047')['payment_error'], PaymentLink::NOTA_DUBLA . ': plata inițială a comenzii (Stripe')
    && $comanda('1047')['paid_amount'] === '63.00', (string) $comanda('1047')['payment_error']);

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
$linkBt = $posibil['ok'] ? PaymentLink::creeaza($db, $id1058, '1058', $posibil['suma'], PaymentLink::procesorPentruLinkNou($setari()), $setari()) : null;
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

// Garda la BT: plata inițială (1044, „Plată în așteptare” de două zile) se autorizează totuși, după ce linkul a pornit.
$c1044 = $comanda('1044');
$id1044 = (int) $c1044['id'];
$initiala44 = $txBt($c1044, 'registered', 7200, 0, '1044');
$posibil = PaymentLink::plataIntegrala($db, $c1044, $setari());
$link44 = $posibil['ok'] ? PaymentLink::creeaza($db, $id1044, '1044', $posibil['suma'], PaymentLink::PROCESATOR_BT, $setari()) : null;
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
$verifica('baza pusă la loc: CHECKSUM TABLE identic pe fiecare tabel', $sumeBaza() === $sumeInainte,
    implode(', ', array_keys(array_diff_assoc($sumeBaza(), $sumeInainte))));
$erori = is_file($fisierErori) ? (string) file_get_contents($fisierErori, false, null, $pozitieErori) : '';
$verifica('jurnalul PHP: niciun Warning, Notice sau Fatal nou', preg_match('/PHP (Warning|Notice|Fatal|Parse)/', $erori) !== 1, mb_substr($erori, 0, 1200));

$scrie('');
$scrie('Rezultat: ' . $trecute . ' trecute, ' . $esuate . ' eșuate. Raport: ' . $fisierRaport);
exit($esuate === 0 ? 0 : 1);
