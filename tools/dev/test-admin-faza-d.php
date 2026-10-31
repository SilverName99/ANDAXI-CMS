<?php

declare(strict_types=1);

/**
 * Testele paginilor refăcute în faza D, partea a doua (WP-32 … WP-34), ce nu se vede în contractul adminului (G-admin) și
 * în plimbarea cu tastatura (G-tastatura):
 *
 *   tools/dev/php.sh tools/dev/test-admin-faza-d.php
 *
 * A. Acasă (WP-32): pe o bază separată (cms_test_acasa, structura tabelelor din baza de dezvoltare, plus câteva mii de
 *    comenzi, coșuri și produse inventate), fiecare interogare a lui App\Support\AcasaAdmin are, în EXPLAIN, un index pe
 *    fiecare tabel al ei (nicio citire a întregului tabel; indecșii magazin/0029 și catalog/0025, MIG-19), iar cifrele sunt
 *    cele numărate direct (de procesat, vânzările de azi și de săptămâna trecută, graficul, coșurile, stocul, „De făcut”).
 *    Baza se șterge la final.
 * B. Lista comenzilor (WP-33), cu un server al testului (portul CMS_DEV_PORT + 41, licența „toate” într-un dosar al lui):
 *    plimbarea tools/dev/plimbare-comenzi.cjs (fiecare comandă demo în panoul din dreapta, Esc și focusul, bucățile FAN, BT,
 *    linkul de plată, promoționalele; formularele rândurilor; bara acțiunilor în masă cu A/C/P/Esc fără să trimită nimic;
 *    scurtăturile de la „?”); nicio cerere spre alt server; baza neatinsă (CHECKSUM TABLE pe orders înainte = după).
 * C. Editorul produsului (WP-34), pe același fel de server: tools/dev/salvare-produs.cjs deschide câteva produse (unul cu
 *    oferte BBD, unul cu precomandă și limite, unul cu câmpuri suplimentare), verifică contorul modificărilor (0 la deschidere,
 *    1 după o literă, Esc întreabă) și le salvează neatinse cu Ctrl+S: rândul produsului (fără updated_at), categoriile,
 *    câmpurile suplimentare și SEO-ul lui rămân aceleași. A doua oară cu ofertele BBD și precomenzile oprite: secțiunile lor
 *    lipsesc, iar salvarea păstrează ce era scris (regula 3 din plan). Baza se pune la loc.
 * D. Paginile unite, împărțite sau puse pe tab-uri în WP-35 (IA-01 … IA-10): tools/dev/salvare-setari.cjs trimite formularele
 *    lor (livrarea și contul FAN, plățile pe tab-uri, Google în „SEO și Google”, câteva părți ale setărilor, fidelitatea,
 *    emailurile) pe codul etichetei admin-inainte-b și pe codul curent; cererile se opresc înainte de server și trebuie să aibă
 *    aceleași perechi câmp = valoare (contul FAN salvat din „Curier FAN” se întoarce la tab-ul lui: doar „tab” diferă). Plus
 *    constructorul de emailuri: o campanie salvată neatinsă de două ori are același JSON al blocurilor, octet cu octet.
 *    Setările, campaniile și punctele rămân neatinse (CHECKSUM TABLE), campania se pune la loc.
 *
 * Raportul: $CMS_DEV_DIR/test-admin-faza-d-raport.txt; cod de ieșire 0 doar dacă totul a trecut. Date inventate.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';

use App\Support\AcasaAdmin;
use App\Support\Database;
use App\Support\Plugins;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-admin-faza-d-raport.txt';
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

$config = require $radacina . '/config/app.php';
$db = Database::connection($config['db']);
if (!$db instanceof PDO) {
    fwrite(STDERR, "EROARE: baza de dezvoltare nu răspunde (tools/dev/instaleaza.sh)\n");
    exit(1);
}
$numeBaza = (string) $db->query('SELECT DATABASE()')->fetchColumn();

// =====================================================================
// A. Acasă (WP-32)
// =====================================================================
$scrie('== A. Acasă: indecșii interogărilor și cifrele (App\Support\AcasaAdmin)');

/** Instrucțiunile trimise de AcasaAdmin, cu parametrii lor, pentru EXPLAIN. */
final class InstructiuneInregistrata extends PDOStatement
{
    /** @var list<array{sql: string, parametri: array}> */
    public static array $jurnal = [];

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        self::$jurnal[] = ['sql' => $this->queryString, 'parametri' => $params ?? []];
        return parent::execute($params);
    }
}

$bazaTest = 'cms_test_acasa';
$db->exec('DROP DATABASE IF EXISTS ' . $bazaTest);
$db->exec('CREATE DATABASE ' . $bazaTest . ' CHARACTER SET utf8mb4');
$tabele = ['orders', 'cart_abandonments', 'products', 'product_reviews', 'settings'];
foreach ($tabele as $tabel) {
    $creare = (string) $db->query('SHOW CREATE TABLE `' . $numeBaza . '`.`' . $tabel . '`')->fetch(PDO::FETCH_NUM)[1];
    // Fără cheile străine (tabelele users/… nu sunt copiate).
    $creare = (string) preg_replace('/,\s*CONSTRAINT `[^`]+` FOREIGN KEY [^\n]+/', '', $creare);
    $db->exec('USE ' . $bazaTest);
    $db->exec($creare);
    $db->exec('USE `' . $numeBaza . '`');
}
$t = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['db']['host'], (int) $config['db']['port'], $bazaTest),
    (string) $config['db']['username'], (string) $config['db']['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$acum = new DateTimeImmutable('today 15:30');
mt_srand(32);
$stari = ['pending', 'processing', 'completed', 'completed', 'completed', 'completed', 'cancelled', 'pending_payment', 'refunded', 'failed', 'returned'];
$stariVechi = ['completed', 'completed', 'completed', 'completed', 'completed', 'completed', 'completed', 'cancelled', 'refunded', 'returned', 'failed'];
$plati = ['cod', 'euplatesc', 'stripe', 'btipay', 'bank_transfer'];
$valori = [];
$asteptat = ['noi' => 0, 'in_procesare' => 0, 'azi' => [0, 0.0], 'trecut' => [0, 0.0], 'grafic' => 0.0, 'fan' => 0, 'op' => 0];
$primaGrafic = $acum->setTime(0, 0)->modify('-13 days');
for ($i = 1; $i <= 4000; $i++) {
    // Ca pe un magazin adevărat: comenzile vechi sunt încheiate, doar cele recente mai sunt de lucrat.
    $stare = $i > 3600 ? $stari[mt_rand(0, count($stari) - 1)] : $stariVechi[mt_rand(0, count($stariVechi) - 1)];
    // Cele mai multe comenzi sunt vechi (până la 2 ani), câteva în ultimele 30 de zile.
    $creata = $i > 3600 ? $acum->modify('-' . mt_rand(0, 30 * 24 * 60) . ' minutes') : $acum->modify('-' . mt_rand(31, 730) . ' days');
    $total = round(mt_rand(1000, 90000) / 100, 2);
    $plata = $plati[mt_rand(0, 4)];
    $sters = mt_rand(0, 40) === 0;
    $awb = mt_rand(0, 1) === 0 ? null : 'DEMO' . $i;
    $platit = mt_rand(0, 1) === 0 ? 'paid' : 'unpaid';
    $valori[] = sprintf("(%d, 'T%06d', '%s', '%s', '%s', %.2f, 0, %.2f, 'Test', 'Exemplu', 't%d@exemplu.ro', '0700000000', 'Str. Test 1', 'Iasi', 'Iasi', '700000', '%s', %s, %s, 'pending')",
        $i, $i, $stare, $plata, $platit, $total, $total, $i, $creata->format('Y-m-d H:i:s'), $sters ? "'" . $creata->format('Y-m-d H:i:s') . "'" : 'NULL',
        $awb === null ? 'NULL' : "'" . $awb . "'");
    if ($sters) {
        continue;
    }
    $vanzare = !in_array($stare, AcasaAdmin::FARA_VANZARE, true);
    $asteptat['noi'] += $stare === 'pending' ? 1 : 0;
    $asteptat['in_procesare'] += $stare === 'processing' ? 1 : 0;
    $asteptat['fan'] += $stare === 'processing' && $awb === null ? 1 : 0;
    $asteptat['op'] += in_array($stare, ['pending', 'processing'], true) && $plata === 'bank_transfer' && $platit !== 'paid' ? 1 : 0;
    if ($vanzare && $creata >= $acum->setTime(0, 0) && $creata <= $acum) {
        $asteptat['azi'][0]++;
        $asteptat['azi'][1] += $total;
    }
    if ($vanzare && $creata >= $acum->setTime(0, 0)->modify('-7 days') && $creata <= $acum->modify('-7 days')) {
        $asteptat['trecut'][0]++;
        $asteptat['trecut'][1] += $total;
    }
    if ($vanzare && $creata >= $primaGrafic && $creata <= $acum) {
        $asteptat['grafic'] += $total;
    }
}
foreach (array_chunk($valori, 500) as $bucata) {
    $t->exec('INSERT INTO orders (id, order_number, status, payment_method, payment_status, total, shipping_cost, subtotal, billing_first_name,
        billing_last_name, billing_email, billing_phone, billing_address_line1, billing_city, billing_county, billing_postcode, created_at,
        deleted_at, fan_awb, erp_status) VALUES ' . implode(', ', $bucata));
}
$valori = [];
for ($i = 1; $i <= 3000; $i++) {
    $vazut = $acum->modify('-' . mt_rand(0, 60 * 24 * 60) . ' minutes');
    $valori[] = sprintf("('s%d', 'c%d@exemplu.ro', '%s', %s)", $i, $i, $vazut->format('Y-m-d H:i:s'), mt_rand(0, 3) === 0 ? "'" . $vazut->format('Y-m-d H:i:s') . "'" : 'NULL');
}
$t->exec('INSERT INTO cart_abandonments (session_id, email, last_seen_at, converted_at) VALUES ' . implode(', ', $valori));
$valori = [];
for ($i = 1; $i <= 1500; $i++) {
    $valori[] = sprintf("('Produs test %d', 'produs-test-%d', 10, %d, %d, %d)", $i, $i, mt_rand(0, 300) === 0 ? 0 : mt_rand(0, 400), mt_rand(0, 200) === 0 ? 1 : 0,
        mt_rand(0, 20) === 0 ? 0 : 1);
}
$t->exec('INSERT INTO products (name, slug, price, stock, out_of_stock, is_active) VALUES ' . implode(', ', $valori));
$t->query('ANALYZE TABLE orders, cart_abandonments, products')->fetchAll();

$t->setAttribute(PDO::ATTR_STATEMENT_CLASS, [InstructiuneInregistrata::class]);
$setari = ['erp_enabled' => '1', 'email_abandoned_after_minutes' => '60'];
InstructiuneInregistrata::$jurnal = [];
$deProcesat = AcasaAdmin::deProcesat($t);
$vanzari = AcasaAdmin::vanzariAzi($t, $acum);
$grafic = AcasaAdmin::grafic($t, $acum);
$cosuri = AcasaAdmin::cosuriAzi($t, $acum, $setari);
$stoc = AcasaAdmin::stocScazut($t);
$recente = AcasaAdmin::comenziRecente($t, 7);
$magazin = AcasaAdmin::deFacutMagazin($t, $setari);
$fan = AcasaAdmin::deFacutFan($t, $setari);
$erp = AcasaAdmin::deFacutErp($t, $setari);
$precomenzi = AcasaAdmin::deFacutPrecomenzi($t, $setari);
$linkPlata = AcasaAdmin::deFacutLinkPlata($t, $setari);
AcasaAdmin::sanatateErp($t, $setari);
$jurnal = InstructiuneInregistrata::$jurnal;
$t->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);

// query() nu trece prin execute(): interogările fără parametri le refac aici, din aceleași funcții, prin prepare.
$faraIndex = [];
$verificate = 0;
foreach ($jurnal as $intrare) {
    if (!preg_match('/^\s*SELECT/i', $intrare['sql']) || str_contains($intrare['sql'], 'information_schema')) {
        continue;
    }
    $stmt = $t->prepare('EXPLAIN ' . $intrare['sql']);
    $stmt->execute($intrare['parametri']);
    foreach ($stmt->fetchAll() as $rand) {
        if (!in_array((string) $rand['table'], $tabele, true)) {
            continue;
        }
        $verificate++;
        if ((string) ($rand['key'] ?? '') === '' || strtoupper((string) $rand['type']) === 'ALL') {
            $faraIndex[] = trim((string) preg_replace('/\s+/', ' ', $intrare['sql'])) . ' → ' . $rand['type'] . ' ' . ($rand['key'] ?? '-');
        }
    }
}
$verifica('EXPLAIN: ' . $verificate . ' citiri de tabel din ' . count($jurnal) . ' interogări, toate pe un index (4000 de comenzi, 3000 de coșuri, 1500 de produse)',
    $faraIndex === [] && $verificate >= 10, implode("\n          ", $faraIndex));

// Interogările făcute cu query() (fără parametri) nu ajung în jurnal: le verific separat, cu textul lor din cod.
$faraParametri = [
    "SELECT status, COUNT(*) AS n, MIN(created_at) AS prima FROM orders WHERE status IN ('pending', 'processing') AND deleted_at IS NULL GROUP BY status",
    'SELECT id, name, stock, out_of_stock FROM products WHERE out_of_stock = 1 AND is_active = 1 AND deleted_at IS NULL',
    'SELECT id, name, stock, out_of_stock FROM products WHERE is_active = 1 AND stock < ' . AcasaAdmin::PRAG_STOC . ' AND deleted_at IS NULL',
];
$sursa = (string) file_get_contents($radacina . '/src/Support/AcasaAdmin.php');
$faraIndex = [];
foreach ($faraParametri as $sql) {
    foreach ($t->query('EXPLAIN ' . $sql)->fetchAll() as $rand) {
        if ((string) ($rand['key'] ?? '') === '' || strtoupper((string) $rand['type']) === 'ALL') {
            $faraIndex[] = $sql . ' → ' . $rand['type'];
        }
    }
}
$verifica('EXPLAIN: de procesat și stocul (interogări fără parametri) pe index; textul lor e cel din AcasaAdmin',
    $faraIndex === [] && str_contains($sursa, "WHERE out_of_stock = 1 AND is_active = 1 AND deleted_at IS NULL")
    && str_contains($sursa, "WHERE is_active = 1 AND stock < ' . self::PRAG_STOC . ' AND deleted_at IS NULL"), implode('; ', $faraIndex));

$verifica('de procesat: ' . $deProcesat['total'] . ' (noi ' . $deProcesat['noi'] . ', în procesare ' . $deProcesat['in_procesare'] . ') = numărate direct',
    $deProcesat['noi'] === $asteptat['noi'] && $deProcesat['in_procesare'] === $asteptat['in_procesare']);
$verifica('vânzări azi ' . $vanzari['azi']['comenzi'] . ' / ' . $vanzari['azi']['suma'] . ' lei și săptămâna trecută la aceeași oră = numărate direct',
    $vanzari['azi']['comenzi'] === $asteptat['azi'][0] && abs($vanzari['azi']['suma'] - $asteptat['azi'][1]) < 0.01
    && $vanzari['saptamana_trecuta']['comenzi'] === $asteptat['trecut'][0] && abs($vanzari['saptamana_trecuta']['suma'] - $asteptat['trecut'][1]) < 0.01);
$verifica('graficul: 14 zile, ultima „azi”, totalul = suma comenzilor din ultimele 14 zile',
    count($grafic['zile']) === 14 && $grafic['zile'][13]['zi'] === 'azi' && $grafic['zile'][13]['data'] === $acum->format('Y-m-d')
    && abs($grafic['total'] - $asteptat['grafic']) < 0.01);
$cosuriDirect = (array) $t->query("SELECT SUM(converted_at IS NULL AND last_seen_at <= '" . $acum->modify('-60 minutes')->format('Y-m-d H:i:s')
    . "') AS a, SUM(converted_at IS NOT NULL) AS r FROM cart_abandonments WHERE last_seen_at >= '" . $acum->setTime(0, 0)->format('Y-m-d H:i:s') . "'")->fetch();
$verifica('coșurile de azi: ' . $cosuri['abandonate'] . ' abandonate, ' . $cosuri['recuperate'] . ' terminate cu o comandă',
    $cosuri['abandonate'] === (int) $cosuriDirect['a'] && $cosuri['recuperate'] === (int) $cosuriDirect['r']);
$stocDirect = (int) $t->query('SELECT COUNT(*) FROM products WHERE is_active = 1 AND deleted_at IS NULL AND (out_of_stock = 1 OR stock < ' . AcasaAdmin::PRAG_STOC . ')')->fetchColumn();
$verifica('stocul scăzut: ' . $stoc['total'] . ' produse (' . $stoc['epuizate'] . ' epuizate), cel mult 6 în listă, epuizatele întâi',
    $stoc['total'] === $stocDirect && $stoc['epuizate'] + $stoc['putine'] === $stoc['total'] && count($stoc['produse']) <= 6
    && ($stoc['produse'] === [] || $stoc['produse'][0]['epuizat'] === ($stoc['epuizate'] > 0)));
$verifica('comenzile recente: 7, cea mai nouă întâi, fără cele șterse',
    count($recente) === 7 && (int) $recente[0]['id'] > (int) $recente[6]['id']);
$verifica('„De făcut”: comenzi noi, ordine de plată de verificat, fără AWB = numărate direct',
    $magazin[0]['numar'] === $asteptat['noi'] && $magazin[1]['numar'] === $asteptat['op'] && $fan[0]['numar'] === $asteptat['fan']
    && $magazin[2]['numar'] === $stoc['total'] && ($erp[0]['numar'] ?? -1) === 0 && $precomenzi[0]['numar'] === 0 && $linkPlata[0]['numar'] === 0);
$verifica('„De făcut” ERP: nimic cu ERP-ul oprit din setări', AcasaAdmin::deFacutErp($t, ['erp_enabled' => '0']) === []);

// „De făcut” prin puncte: un plugin ascuns nu-și arată rândul.
$stariInainte = Plugins::stari();
Plugins::folosesteStari(array_merge($stariInainte, ['erp' => Plugins::OPRIT, 'recenzii' => Plugins::OPRIT, 'livrare_fan' => Plugins::PORNIT]));
\App\Support\Hooks::reseteaza();
$pluginuri = array_unique(array_column(AcasaAdmin::deFacut($t, ['erp_enabled' => '1']), 'plugin'));
$sanatatePluginuri = array_column(AcasaAdmin::sanatate($t, ['erp_enabled' => '1']), 'plugin');
$verifica('„De făcut” și sănătatea prin config/hooks.php: cu ERP și recenziile oprite, niciun rând al lor; FAN pornit are rândul lui',
    !in_array('erp', $pluginuri, true) && !in_array('recenzii', $pluginuri, true) && in_array('livrare_fan', $pluginuri, true)
    && !in_array('erp', $sanatatePluginuri, true) && in_array('livrare_fan', $sanatatePluginuri, true), implode(',', $pluginuri));
Plugins::reseteaza();
Plugins::incarca();
\App\Support\Hooks::reseteaza();

$db->exec('DROP DATABASE IF EXISTS ' . $bazaTest);

// =====================================================================
// B. Lista comenzilor (WP-33)
// =====================================================================
$scrie('');
$scrie('== B. Lista comenzilor: panoul comenzii, bara acțiunilor în masă, scurtăturile (tools/dev/plimbare-comenzi.cjs)');
$portTest = (int) (getenv('CMS_DEV_PORT') ?: 8701) + 41;
$dosarTest = $dosarDev . '/test-admin-faza-d';
exec('rm -rf ' . escapeshellarg($dosarTest));
@mkdir($dosarTest . '/licenta', 0770, true);
putenv('LICENTA_DOSAR=' . $dosarTest . '/licenta');
$phpDev = [PHP_BINARY];
foreach (['pdo_mysql.default_socket', 'sendmail_path', 'memory_limit', 'error_reporting'] as $ini) {
    if ((string) ini_get($ini) !== '') {
        array_push($phpDev, '-d', $ini . '=' . ini_get($ini));
    }
}
array_push($phpDev, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $dosarTest . '/php-erori.log');
$pornesteServer = static function () use ($phpDev, $portTest, $radacina, $dosarTest) {
    $server = proc_open(array_merge($phpDev, ['-S', '127.0.0.1:' . $portTest, '-t', $radacina . '/public', $radacina . '/tools/dev/router.php']),
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dosarTest . '/server.log', 'a'], 2 => ['file', $dosarTest . '/server.log', 'a']], $_, $radacina);
    for ($i = 0; $i < 60; $i++) {
        usleep(200000);
        $c = curl_init('http://127.0.0.1:' . $portTest . '/health');
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 2]);
        curl_exec($c);
        $gata = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE) === 200;
        curl_close($c);
        if ($gata) {
            return $server;
        }
    }
    return $server;
};
$sumaComenzi = static fn (): string => json_encode($db->query('CHECKSUM TABLE orders, order_items, settings')->fetchAll(PDO::FETCH_NUM));
$inainte = $sumaComenzi();
// Ca panoul să aibă și bucățile care cer o stare anume (datele demo nu o au): ERP-ul pornit din setări (formularele 🔄) și o
// comandă plătită cu cardul cu 10 lei de încasat în plus (linkul de plată pentru diferență). Puse la loc după plimbare.
$erpInainte = $db->query("SELECT `value` FROM settings WHERE `key` = 'erp_enabled'")->fetchColumn();
// Schimbarea licenței (a doua plimbare) notează din nou pașii de activare ai pluginurilor (momentul lor): se pun la loc.
$activariInainte = $db->query("SELECT `value` FROM settings WHERE `key` = 'activari_pluginuri'")->fetchColumn();
$db->exec("INSERT INTO settings (`key`, `value`) VALUES ('erp_enabled', '1') ON DUPLICATE KEY UPDATE `value` = '1'");
$comandaRest = $db->query("SELECT id, paid_amount, updated_at FROM orders WHERE deleted_at IS NULL AND LOWER(payment_status) = 'paid'
    AND payment_method IN ('euplatesc', 'stripe', 'btipay', 'card') AND status <> 'cancelled' ORDER BY id LIMIT 1")->fetch() ?: null;
if ($comandaRest !== null) {
    $db->prepare('UPDATE orders SET paid_amount = total - 10, updated_at = updated_at WHERE id = ?')->execute([(int) $comandaRest['id']]);
}
$server = $pornesteServer();
/** O plimbare cu licența dată (argumentele lui licenta.php); rezultatul ei sau null. */
$plimba = static function (array $licenta, string $nume) use ($phpDev, $radacina, $portTest, $dosarTest, $dosarDev): ?array {
    exec(implode(' ', array_map('escapeshellarg', array_merge($phpDev, [$radacina . '/tools/dev/licenta.php'], $licenta))) . ' 2>&1', $_, $codLicenta);
    $fisier = $dosarTest . '/plimbare-comenzi-' . $nume . '.json';
    $env = ['CMS_DEV_URL=http://127.0.0.1:' . $portTest, 'CMS_DEV_PAROLA=' . trim((string) @file_get_contents($dosarDev . '/admin-parola.txt')),
        'CMS_DEV_ADMIN_EMAIL=' . (getenv('CMS_DEV_ADMIN_EMAIL') ?: 'admin@exemplu.ro'), 'CMS_DEV_REZULTAT=' . $fisier,
        'NODE_PATH=' . (getenv('NODE_PATH') ?: '/opt/node22/lib/node_modules'), 'PLAYWRIGHT_BROWSERS_PATH=' . (getenv('PLAYWRIGHT_BROWSERS_PATH') ?: '/opt/pw-browsers')];
    exec('env ' . implode(' ', array_map('escapeshellarg', $env)) . ' node ' . escapeshellarg($radacina . '/tools/dev/plimbare-comenzi.cjs') . ' 2>&1', $iesire, $cod);
    $date = json_decode((string) @file_get_contents($fisier), true);
    return $codLicenta === 0 && $cod === 0 && is_array($date) ? $date : null;
};
$plimbare = $plimba(['toate'], 'toate');
// Fără livrarea FAN și fără ERP: „A”, „Generează AWB”, filtrul livrării, filtrul ERP și „Trimite în ERP” nu există.
$plimbareFara = $plimba(['toate', 'livrare_fan=oprit', 'erp=oprit'], 'fara-fan-erp');
$stareServer = proc_get_status($server);
exec('pkill -TERM -P ' . (int) $stareServer['pid'] . ' 2>/dev/null');
proc_terminate($server);
proc_close($server);
putenv('LICENTA_DOSAR');
if ($comandaRest !== null) {
    $db->prepare('UPDATE orders SET paid_amount = ?, updated_at = ? WHERE id = ?')
        ->execute([$comandaRest['paid_amount'], $comandaRest['updated_at'], (int) $comandaRest['id']]);
}
if ($activariInainte !== false) {
    $db->prepare("UPDATE settings SET `value` = ? WHERE `key` = 'activari_pluginuri'")->execute([$activariInainte]);
}
if ($erpInainte === false) {
    $db->exec("DELETE FROM settings WHERE `key` = 'erp_enabled'");
} else {
    $db->prepare("UPDATE settings SET `value` = ? WHERE `key` = 'erp_enabled'")->execute([$erpInainte]);
}
if (!is_array($plimbare)) {
    $verifica('plimbarea prin lista comenzilor s-a terminat', false, 'vezi ' . $dosarTest);
} else {
    $b = $plimbare['bucati'];
    $verifica('fiecare comandă demo (' . $plimbare['comenzi'] . ') se deschide în panou, cu numărul, clientul și produsele; Esc îl închide și focusul revine; '
        . 'nicio eroare JavaScript', $plimbare['comenzi'] > 0 && $plimbare['deschise'] === $plimbare['comenzi'] && $b['produse'] === $plimbare['comenzi']
        && $plimbare['probleme'] === [], implode('; ', array_slice($plimbare['probleme'], 0, 10)));
    $verifica('bucățile panoului pe comenzile demo: FANbox ' . $b['fanbox'] . ', plata BT ' . $b['bt'] . ', link de plată ' . $b['link_plata']
        . ', promoționale ' . $b['promo'], $b['fanbox'] > 0 && $b['bt'] > 0 && $b['link_plata'] > 0 && $b['promo'] > 0);
    $r = $plimbare['randuri'];
    $verifica('formularele rândurilor: AWB ' . $r['awb'] . ', ERP ' . $r['erp'] . ', precomandă ' . $r['precomanda'] . ', OP ' . $r['op'] . ', coș ' . $r['cos']
        . ', acțiuni ' . $r['actiuni'], $r['awb'] > 0 && $r['erp'] > 0 && $r['precomanda'] > 0 && $r['op'] > 0 && $r['cos'] > 0 && $r['actiuni'] === $plimbare['comenzi']);
    $verifica('Enter în „Transport (lei)”: ' . json_encode($plimbare['enterTransport'] ?? null) . '; butonul implicit al barei: ' . ($plimbare['implicit'] ?? '')
        . ' (fără JavaScript, Enter tot transportul trimite)', ($plimbare['enterTransport']['actiune'] ?? '') === 'shipping'
        && ($plimbare['enterTransport']['valoare'] ?? '') === '30' && ($plimbare['implicit'] ?? '') === 'bulk_action=shipping'
        && is_array($plimbareFara) && ($plimbareFara['implicit'] ?? '') === 'bulk_action=shipping' && ($plimbareFara['enterTransport']['actiune'] ?? '') === 'shipping');
    $verifica('scurtăturile paginii la „?”: ' . implode(' ', (array) $plimbare['scurtaturi']), in_array('A', (array) $plimbare['scurtaturi'], true)
        && in_array('C', (array) $plimbare['scurtaturi'], true) && in_array('P', (array) $plimbare['scurtaturi'], true) && !empty($plimbare['areFan']));
    $verifica('nicio cerere spre alt server', (array) $plimbare['blocate'] === [], implode(', ', (array) $plimbare['blocate']));
}
$verifica('fără livrarea FAN și fără ERP: nici „A” (scurtătura și lista de la „?”), nici „Generează AWB”, filtrele livrării și ERP-ului, „Trimite în ERP”',
    is_array($plimbareFara) && empty($plimbareFara['areFan']) && !in_array('A', (array) $plimbareFara['scurtaturi'], true)
    && $plimbareFara['filtre'] === ['livrare' => false, 'erp' => false, 'trimiteErp' => false, 'awb' => false]
    && is_array($plimbare) && $plimbare['filtre'] === ['livrare' => true, 'erp' => true, 'trimiteErp' => true, 'awb' => true]
    && $plimbareFara['probleme'] === [], json_encode($plimbareFara['filtre'] ?? null) . ' ' . implode('; ', (array) ($plimbareFara['probleme'] ?? [])));
$verifica('baza neatinsă de plimbare, după ce s-au pus la loc cele două pregătiri (CHECKSUM TABLE orders, order_items, settings)', $sumaComenzi() === $inainte);

// =====================================================================
// C. Editorul produsului (WP-34)
// =====================================================================
$scrie('');
$scrie('== C. Editorul produsului: salvarea neatinsă, contorul modificărilor, Ctrl+S, secțiunile pluginurilor (tools/dev/salvare-produs.cjs)');
$idProdusBbd = (int) $db->query('SELECT id FROM products WHERE deleted_at IS NULL AND bbd_enabled = 1 ORDER BY id LIMIT 1')->fetchColumn();
$idProdusPre = (int) $db->query('SELECT id FROM products WHERE deleted_at IS NULL AND bbd_enabled = 0 ORDER BY id LIMIT 1')->fetchColumn();
$idProdusCampuri = (int) $db->query('SELECT v.product_id FROM product_extra_field_values v JOIN products p ON p.id = v.product_id
    WHERE p.deleted_at IS NULL ORDER BY v.product_id LIMIT 1')->fetchColumn();
$idsProduse = array_values(array_unique(array_filter([$idProdusPre, $idProdusBbd, $idProdusCampuri])));
$listaProduse = implode(', ', $idsProduse);
$randPre = $db->query('SELECT preorder_enabled, preorder_max_per_order, preorder_max_total, updated_at FROM products WHERE id = ' . $idProdusPre)->fetch();
// Precomanda cu limite pe un produs fără oferte, ca salvarea fără plugin să aibă ce păstra.
$db->prepare('UPDATE products SET preorder_enabled = 1, preorder_max_per_order = 3, preorder_max_total = 20, updated_at = updated_at WHERE id = ?')
    ->execute([$idProdusPre]);
$amprentaProduse = static function () use ($db, $listaProduse): array {
    $fara = static function (array $randuri, array $coloane): array {
        return array_map(static function (array $r) use ($coloane): array {
            foreach ($coloane as $c) {
                unset($r[$c]);
            }
            return $r;
        }, $randuri);
    };
    return [
        'produse' => $fara($db->query('SELECT * FROM products WHERE id IN (' . $listaProduse . ') ORDER BY id')->fetchAll(), ['updated_at']),
        'categorii' => $db->query('SELECT product_id, category_id FROM product_category_links WHERE product_id IN (' . $listaProduse . ') ORDER BY product_id, category_id')->fetchAll(),
        'campuri' => $fara($db->query('SELECT * FROM product_extra_field_values WHERE product_id IN (' . $listaProduse . ') ORDER BY product_id, field_id')->fetchAll(),
            ['id', 'created_at', 'updated_at']),
        'seo' => $fara($db->query("SELECT * FROM seo_pages WHERE page_type = 'product' AND page_ref IN ('" . str_replace(', ', "', '", $listaProduse) . "') ORDER BY page_ref")
            ->fetchAll(), ['id', 'created_at', 'updated_at']),
    ];
};
$inainteProduse = $amprentaProduse();
// Copiile rândurilor atinse (doar ale produselor testului; produsele nu se șterg niciodată: order_items le-ar pierde legătura).
$refuriSeo = "'" . str_replace(', ', "', '", $listaProduse) . "'";
$copiiProduse = [
    'products' => $db->query('SELECT * FROM products WHERE id IN (' . $listaProduse . ')')->fetchAll(),
    'product_category_links' => $db->query('SELECT * FROM product_category_links WHERE product_id IN (' . $listaProduse . ')')->fetchAll(),
    'product_extra_field_values' => $db->query('SELECT * FROM product_extra_field_values WHERE product_id IN (' . $listaProduse . ')')->fetchAll(),
    'seo_pages' => $db->query("SELECT * FROM seo_pages WHERE page_type = 'product' AND page_ref IN (" . $refuriSeo . ')')->fetchAll(),
    'settings' => $db->query('SELECT * FROM settings')->fetchAll(),
];
@mkdir($dosarTest . '/licenta', 0770, true);
putenv('LICENTA_DOSAR=' . $dosarTest . '/licenta');
$server = $pornesteServer();
$salveaza = static function (array $licenta, string $nume, int $port = 0, bool $vechi = false) use ($phpDev, $radacina, $portTest, $dosarTest, $dosarDev, $idsProduse): ?array {
    exec(implode(' ', array_map('escapeshellarg', array_merge($phpDev, [$radacina . '/tools/dev/licenta.php'], $licenta))) . ' 2>&1', $_, $codLicenta);
    $fisier = $dosarTest . '/salvare-produs-' . $nume . '.json';
    $env = ['CMS_DEV_URL=http://127.0.0.1:' . ($port > 0 ? $port : $portTest), 'CMS_DEV_PAROLA=' . trim((string) @file_get_contents($dosarDev . '/admin-parola.txt')),
        'CMS_DEV_ADMIN_EMAIL=' . (getenv('CMS_DEV_ADMIN_EMAIL') ?: 'admin@exemplu.ro'), 'CMS_DEV_REZULTAT=' . $fisier,
        'CMS_DEV_PRODUSE=' . implode(',', $idsProduse), 'CMS_DEV_VECHI=' . ($vechi ? '1' : '0'),
        'NODE_PATH=' . (getenv('NODE_PATH') ?: '/opt/node22/lib/node_modules'), 'PLAYWRIGHT_BROWSERS_PATH=' . (getenv('PLAYWRIGHT_BROWSERS_PATH') ?: '/opt/pw-browsers')];
    exec('env ' . implode(' ', array_map('escapeshellarg', $env)) . ' node ' . escapeshellarg($radacina . '/tools/dev/salvare-produs.cjs') . ' 2>&1', $iesire, $cod);
    $date = json_decode((string) @file_get_contents($fisier), true);
    return $codLicenta === 0 && $cod === 0 && is_array($date) ? $date : ['salvate' => [], 'probleme' => array_merge(['codul ' . $cod], array_slice($iesire, -3)), 'blocate' => [], 'sectiuni' => []];
};
$puneLaLoc = static function () use ($db, $copiiProduse, $listaProduse, $refuriSeo): void {
    $insereaza = static function (string $tabel, array $randuri) use ($db): void {
        foreach ($randuri as $rand) {
            $coloane = array_keys($rand);
            $db->prepare('INSERT INTO ' . $tabel . ' (`' . implode('`, `', $coloane) . '`) VALUES (' . implode(', ', array_fill(0, count($coloane), '?')) . ')')
                ->execute(array_values($rand));
        }
    };
    foreach ($copiiProduse['products'] as $rand) {
        $coloane = array_keys($rand);
        $db->prepare('UPDATE products SET `' . implode('` = ?, `', $coloane) . '` = ? WHERE id = ?')->execute(array_merge(array_values($rand), [$rand['id']]));
    }
    $db->exec('DELETE FROM product_category_links WHERE product_id IN (' . $listaProduse . ')');
    $insereaza('product_category_links', $copiiProduse['product_category_links']);
    $db->exec('DELETE FROM product_extra_field_values WHERE product_id IN (' . $listaProduse . ')');
    $insereaza('product_extra_field_values', $copiiProduse['product_extra_field_values']);
    $db->exec("DELETE FROM seo_pages WHERE page_type = 'product' AND page_ref IN (" . $refuriSeo . ')');
    $insereaza('seo_pages', $copiiProduse['seo_pages']);
    $db->exec('DELETE FROM settings');
    $insereaza('settings', $copiiProduse['settings']);
};
// 1. Editorul de dinainte (codul etichetei admin-inainte-b, scos de contract-admin.php în $CMS_DEV_DIR/baza-admin-<commit>), pe al
//    doilea server: ce scrie o salvare neatinsă acolo e referința (salvarea normalizează valorile goale, de exemplu NULL → „[]”).
$dosarEticheta = (glob($dosarDev . '/baza-admin-*/public/index.php') ?: [])[0] ?? '';
if ($dosarEticheta === '') {
    exec('git -C ' . escapeshellarg($radacina) . ' rev-parse --short 167232d6ce4b0e8c6333a6b86b6b44f85c31641f 2>/dev/null', $scurt);
    $dosarEticheta = $dosarDev . '/baza-admin-' . trim((string) ($scurt[0] ?? '167232d'));
    @mkdir($dosarEticheta, 0770, true);
    exec('git -C ' . escapeshellarg($radacina) . ' archive 167232d6ce4b0e8c6333a6b86b6b44f85c31641f | tar -x -C ' . escapeshellarg($dosarEticheta));
    $dosarEticheta .= '/public/index.php';
}
$radacinaEticheta = dirname($dosarEticheta, 2);
$portEticheta = $portTest + 1;
$serverEticheta = proc_open(array_merge($phpDev, ['-S', '127.0.0.1:' . $portEticheta, '-t', $radacinaEticheta . '/public', $radacina . '/tools/dev/router.php']),
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dosarTest . '/server-eticheta.log', 'a'], 2 => ['file', $dosarTest . '/server-eticheta.log', 'a']], $_, $radacinaEticheta);
usleep(800000);
$vechi = $salveaza(['toate'], 'vechi', $portEticheta, true);
$dupaVechi = $amprentaProduse();
$stareEticheta = proc_get_status($serverEticheta);
exec('pkill -TERM -P ' . (int) $stareEticheta['pid'] . ' 2>/dev/null');
proc_terminate($serverEticheta);
proc_close($serverEticheta);
$puneLaLoc();
// 2. Editorul nou, aceeași salvare neatinsă: același rezultat. 3. A doua salvare neatinsă: nicio coloană schimbată.
// 4. Fără ofertele BBD și precomenzi: tot nicio coloană schimbată (valorile secțiunilor ascunse rămân).
$cuToate = $salveaza(['toate'], 'toate');
$dupaToate = $amprentaProduse();
$aDoua = $salveaza(['toate'], 'a-doua');
$dupaADoua = $amprentaProduse();
$faraPluginuri = $salveaza(['toate', 'oferte_bbd=oprit', 'precomenzi=oprit'], 'fara-bbd-precomenzi');
$dupaFara = $amprentaProduse();
$stareServer = proc_get_status($server);
exec('pkill -TERM -P ' . (int) $stareServer['pid'] . ' 2>/dev/null');
proc_terminate($server);
proc_close($server);
putenv('LICENTA_DOSAR');
$verifica('editorul de dinainte (eticheta admin-inainte-b): ' . count($vechi['salvate']) . ' produse salvate neatinse, ca referință',
    count($vechi['salvate']) === count($idsProduse) && $vechi['probleme'] === [], implode('; ', $vechi['probleme']));
$verifica('cu toate pluginurile: ' . count($cuToate['salvate']) . ' produse (' . $listaProduse . ') deschise și salvate cu Ctrl+S, contorul 0 / 1 / 0, Esc întreabă; '
    . 'nicio eroare JavaScript', count($cuToate['salvate']) === count($idsProduse) && $cuToate['probleme'] === [] && $cuToate['blocate'] === [],
    implode('; ', $cuToate['probleme']));
$verifica('editorul nou scrie la o salvare neatinsă exact ce scria editorul de dinainte (produsul fără updated_at, categoriile, câmpurile, SEO-ul)',
    $dupaToate === $dupaVechi, json_encode(array_map(null, $dupaVechi['produse'], $dupaToate['produse'])));
$verifica('a doua salvare neatinsă nu schimbă nicio coloană', count($aDoua['salvate']) === count($idsProduse) && $dupaADoua === $dupaToate,
    json_encode(array_map(null, $dupaToate['produse'], $dupaADoua['produse'])));
$verifica('secțiunile: cu toate pluginurile ' . count($cuToate['sectiuni']) . ' (cu Oferte BBD și Precomandă), fără ele '
    . count($faraPluginuri['sectiuni']) . ' (fără)', in_array('produs-bbd', $cuToate['sectiuni'], true) && in_array('produs-precomanda', $cuToate['sectiuni'], true)
    && !in_array('produs-bbd', $faraPluginuri['sectiuni'], true) && !in_array('produs-precomanda', $faraPluginuri['sectiuni'], true)
    && in_array('produs-seo', $faraPluginuri['sectiuni'], true));
$verifica('fără ofertele BBD și fără precomenzi: salvarea păstrează ofertele, precomanda și limitele ei, nicio coloană schimbată',
    count($faraPluginuri['salvate']) === count($idsProduse) && $faraPluginuri['probleme'] === [] && $dupaFara === $dupaToate
    && (int) ($dupaFara['produse'][0]['preorder_max_total'] ?? 0) === 20,
    implode('; ', $faraPluginuri['probleme']) . ' ' . json_encode(array_map(null, $dupaToate['produse'], $dupaFara['produse'])));
// Baza la loc: tabelele atinse, rând cu rând (salvarea scrie updated_at, iar schimbarea licenței pașii de activare).
$puneLaLoc();
$db->prepare('UPDATE products SET preorder_enabled = ?, preorder_max_per_order = ?, preorder_max_total = ?, updated_at = ? WHERE id = ?')
    ->execute([$randPre['preorder_enabled'], $randPre['preorder_max_per_order'], $randPre['preorder_max_total'], $randPre['updated_at'], $idProdusPre]);

// =====================================================================
// D. Paginile WP-35: aceeași cerere la salvare; constructorul de emailuri
// =====================================================================
$scrie('');
$scrie('== D. Formularele paginilor unite, împărțite sau pe tab-uri (WP-35): aceeași cerere ca înainte (tools/dev/salvare-setari.cjs)');
$tabeleD = 'settings, newsletter_campaigns, loyalty_points_rules, loyalty_points_transactions, users';
$sumaD = static fn (): string => json_encode($db->query('CHECKSUM TABLE ' . $tabeleD)->fetchAll(PDO::FETCH_NUM));
$idCampanie = (int) $db->query("SELECT id FROM newsletter_campaigns WHERE status = 'draft' ORDER BY id LIMIT 1")->fetchColumn();
$randCampanie = $idCampanie > 0 ? $db->query('SELECT * FROM newsletter_campaigns WHERE id = ' . $idCampanie)->fetch() : null;
@mkdir($dosarTest . '/licenta', 0770, true);
putenv('LICENTA_DOSAR=' . $dosarTest . '/licenta');
exec(implode(' ', array_map('escapeshellarg', array_merge($phpDev, [$radacina . '/tools/dev/licenta.php', 'toate']))) . ' 2>&1', $_, $codLicentaD);
// După licență (pașii de activare ai pluginurilor se notează în settings la schimbarea ei).
$inainteD = $sumaD();
$trimite = static function (int $port, bool $vechi, string $nume, int $campanie = 0) use ($radacina, $dosarTest, $dosarDev): array {
    $fisier = $dosarTest . '/salvare-setari-' . $nume . '.json';
    @unlink($fisier);
    $env = ['CMS_DEV_URL=http://127.0.0.1:' . $port, 'CMS_DEV_PAROLA=' . trim((string) @file_get_contents($dosarDev . '/admin-parola.txt')),
        'CMS_DEV_ADMIN_EMAIL=' . (getenv('CMS_DEV_ADMIN_EMAIL') ?: 'admin@exemplu.ro'), 'CMS_DEV_REZULTAT=' . $fisier,
        'CMS_DEV_VECHI=' . ($vechi ? '1' : '0'), 'CMS_DEV_CAMPANIE=' . $campanie,
        'NODE_PATH=' . (getenv('NODE_PATH') ?: '/opt/node22/lib/node_modules'), 'PLAYWRIGHT_BROWSERS_PATH=' . (getenv('PLAYWRIGHT_BROWSERS_PATH') ?: '/opt/pw-browsers')];
    exec('env ' . implode(' ', array_map('escapeshellarg', $env)) . ' node ' . escapeshellarg($radacina . '/tools/dev/salvare-setari.cjs') . ' 2>&1', $iesire, $cod);
    $date = json_decode((string) @file_get_contents($fisier), true);
    return $cod === 0 && is_array($date) ? $date : ['cazuri' => [], 'probleme' => array_merge(['codul ' . $cod], array_slice($iesire, -3)), 'blocate' => [], 'builder' => []];
};
// Perechile unei cereri, în ordine alfabetică (jetonul CSRF al sesiunii înlocuit); multipart: câmpurile simple.
$perechi = static function (array $cerere): array {
    $corp = (string) ($cerere['corp'] ?? '');
    $lista = [];
    if (str_contains((string) ($cerere['tip'] ?? ''), 'multipart')) {
        preg_match_all('/name="([^"]+)"(?:; filename="[^"]*")?\r\n(?:[^\r\n]+\r\n)*\r\n(.*?)\r\n--/s', $corp, $m, PREG_SET_ORDER);
        foreach ($m as $camp) {
            $lista[] = $camp[1] . '=' . $camp[2];
        }
    } else {
        foreach (explode('&', $corp) as $bucata) {
            if ($bucata !== '') {
                [$k, $v] = array_pad(explode('=', $bucata, 2), 2, '');
                $lista[] = urldecode($k) . '=' . urldecode($v);
            }
        }
    }
    $lista = array_map(static fn (string $p): string => (string) preg_replace('/\b[0-9a-f]{64}\b/', '<TOKEN>', $p), $lista);
    sort($lista, SORT_STRING);
    return ['adresa' => (string) ($cerere['adresa'] ?? ''), 'perechi' => $lista];
};
$radacinaEticheta = dirname((glob($dosarDev . '/baza-admin-*/public/index.php') ?: [''])[0], 2);
$portEticheta = $portTest + 1;
$serverEticheta = proc_open(array_merge($phpDev, ['-S', '127.0.0.1:' . $portEticheta, '-t', $radacinaEticheta . '/public', $radacina . '/tools/dev/router.php']),
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dosarTest . '/server-eticheta.log', 'a'], 2 => ['file', $dosarTest . '/server-eticheta.log', 'a']], $_, $radacinaEticheta);
usleep(800000);
$vechiD = $trimite($portEticheta, true, 'vechi', $idCampanie);
// JSON-ul blocurilor scris de constructorul de dinainte după două salvări neatinse (prima pornește de la blocul implicit al unei
// campanii goale; a doua citește JSON-ul salvat, deci are toate câmpurile blocurilor).
$blocuriEticheta = $idCampanie > 0 ? (string) $db->query('SELECT blocks_json FROM newsletter_campaigns WHERE id = ' . $idCampanie)->fetchColumn() : '';
$stareEticheta = proc_get_status($serverEticheta);
exec('pkill -TERM -P ' . (int) $stareEticheta['pid'] . ' 2>/dev/null');
proc_terminate($serverEticheta);
proc_close($serverEticheta);
$server = $pornesteServer();
// Constructorul nou pornește de la JSON-ul scris de cel vechi: după două salvări neatinse trebuie să fie același, octet cu octet.
$blocuriInainte = $blocuriEticheta;
$nouD = $trimite($portTest, false, 'nou', $idCampanie);
$blocuriDupa = $idCampanie > 0 ? (string) $db->query('SELECT blocks_json FROM newsletter_campaigns WHERE id = ' . $idCampanie)->fetchColumn() : '';
$stareServer = proc_get_status($server);
exec('pkill -TERM -P ' . (int) $stareServer['pid'] . ' 2>/dev/null');
proc_terminate($server);
proc_close($server);
putenv('LICENTA_DOSAR');
$verifica('licența „toate” pusă; ' . count($vechiD['cazuri']) . ' formulare trimise pe codul de dinainte, ' . count($nouD['cazuri']) . ' pe codul nou; nicio eroare JavaScript, nicio cerere spre alt server',
    $codLicentaD === 0 && $vechiD['probleme'] === [] && $nouD['probleme'] === [] && $vechiD['blocate'] === [] && $nouD['blocate'] === []
    && count($vechiD['cazuri']) === count($nouD['cazuri']) && count($nouD['cazuri']) >= 16,
    implode('; ', array_merge($vechiD['probleme'], $nouD['probleme'], $vechiD['blocate'], $nouD['blocate'])));
foreach ($nouD['cazuri'] as $numeCaz => $cerereNoua) {
    $asteptat = $perechi((array) ($vechiD['cazuri'][$numeCaz] ?? []));
    if (str_starts_with($numeCaz, 'livrare: contul FAN')) {
        // Contul FAN salvat din „Curier FAN”: aceeași cerere, cu întoarcerea la tab-ul lui (?tab=fan-api, adresă veche cunoscută).
        $asteptat['perechi'] = array_map(static fn (string $p): string => $p === 'tab=delivery-settings' ? 'tab=fan-api' : $p, $asteptat['perechi']);
        sort($asteptat['perechi'], SORT_STRING);
    }
    $primit = $perechi((array) $cerereNoua);
    $verifica('„' . $numeCaz . '”: ' . $primit['adresa'] . ', ' . count($primit['perechi']) . ' câmpuri, aceleași ca înainte', $primit === $asteptat,
        json_encode(['lipsă' => array_values(array_diff($asteptat['perechi'], $primit['perechi'])), 'în plus' => array_values(array_diff($primit['perechi'], $asteptat['perechi'])),
            'adresa' => [$asteptat['adresa'], $primit['adresa']]], JSON_UNESCAPED_UNICODE));
}
$verifica('constructorul de emailuri: campania ' . $idCampanie . ' (JSON-ul scris de constructorul de dinainte) salvată neatinsă de două ori cu cel nou (răspunsuri '
    . implode(', ', $nouD['builder']) . '), JSON-ul blocurilor identic octet cu octet (' . strlen($blocuriInainte) . ' octeți)',
    $idCampanie > 0 && count($vechiD['builder']) === 2 && count($nouD['builder']) === 2 && !in_array(0, $nouD['builder'], true)
    && $blocuriDupa === $blocuriInainte && $blocuriInainte !== '', md5($blocuriInainte) . ' → ' . md5($blocuriDupa) . ' ' . $blocuriDupa);
$verifica('„Oferte BBD”: „Deschide produsul” deschide editorul produsului, cu secțiunea ofertelor', ($nouD['bbd']['deschis'] ?? false) === true
    && ($nouD['bbd']['bbd'] ?? false) === true, json_encode($nouD['bbd'] ?? null));
if (is_array($randCampanie)) {
    $coloane = array_keys($randCampanie);
    $db->prepare('UPDATE newsletter_campaigns SET `' . implode('` = ?, `', $coloane) . '` = ? WHERE id = ?')->execute(array_merge(array_values($randCampanie), [$randCampanie['id']]));
}
$verifica('setările, campaniile, regulile și tranzacțiile punctelor, clienții: neatinse (cererile s-au oprit înainte de server; campania pusă la loc)', $sumaD() === $inainteD);

$scrie('');
$scrie(sprintf('Rezultat: %d trecute, %d eșuate. Raport: %s', $trecute, $esuate, $fisierRaport));
exit($esuate === 0 ? 0 : 1);
