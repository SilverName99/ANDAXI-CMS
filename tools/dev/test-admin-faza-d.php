<?php

declare(strict_types=1);

/**
 * Testele paginilor refăcute în faza D (WP-32 … WP-36), ce nu se vede în contractul adminului (G-admin) și
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
 *    constructorul de emailuri: o campanie salvată neatinsă de două ori are același JSON al blocurilor, octet cu octet. Editoarele
 *    de emailuri din pagină (șablonul unui email de comandă, al unui newsletter, o campanie) trimit HTML-ul emailului octet cu
 *    octet ca pe codul Bioscem însuși (arborele scos de compara-cu-baza.sh, rulat întâi), nu doar ca pe codul etichetei.
 *    Setările, campaniile și punctele rămân neatinse (CHECKSUM TABLE), campania se pune la loc.
 *
 * E. Căutarea în tot adminul și scurtăturile (WP-36; App\Support\CautareAdmin): pe fiecare licență de test (toate, fara, cele
 *    patru site-uri, cele patru pachete) și fiecare rol (general, magazin, blog, emailuri), o căutare după o comandă demo, un cod
 *    de produs, un client, o pagină, o postare și câteva cuvinte ale paginilor din admin dă doar sursele pe care rolul le are și
 *    site-ul le vede (tabelul drepturilor e scris aici, nu luat din Auth), fiecare rezultat și fiecare „G <literă>” se poate
 *    deschide, iar ce se vede nu are niciun semn al unui plugin oprit (tools/dev/lib/semne-pluginuri.php, comune cu
 *    test-pluginuri.php). Pe o bază separată (cms_test_cauta: 500 de produse, 4.000 de comenzi, 1.000 de clienți) fiecare căutare
 *    răspunde sub 300 ms, fiecare interogare are LIMIT, limita de timp a serverului și textul doar ca parametru legat, iar „%%”,
 *    „__” și ghilimelele se caută ca text. În browser (tools/dev/plimbare-cautare.cjs, licențele „toate” și „grafoanaytis”): Ctrl+K
 *    pe fiecare rol, lista de la „?”, „G <literă>”, Enter pe o comandă (panoul ei), Esc și focusul, tastarea în câmpuri (nicio
 *    scurtătură), „G C” pe lista comenzilor cu o comandă bifată (nicio confirmare, nicio cerere POST), „C” singur (confirmarea
 *    de până acum) și JSON-ul fără sesiune (401). Baza neatinsă.
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
$puneCampania = static function () use ($db, $randCampanie): void {
    if (is_array($randCampanie)) {
        $coloane = array_keys($randCampanie);
        $db->prepare('UPDATE newsletter_campaigns SET `' . implode('` = ?, `', $coloane) . '` = ? WHERE id = ?')->execute(array_merge(array_values($randCampanie), [$randCampanie['id']]));
    }
};
// Un șablon de newsletter al testului (datele demo n-au niciunul), cu blocuri de text, buton și imagine: editorul lui din pagină
// construiește HTML-ul emailului (newsletter_html) la salvare. Se șterge la final.
$db->prepare('INSERT INTO newsletter_templates (name, subject, blocks_json, html_content, is_active) VALUES (?, ?, ?, ?, 1)')->execute([
    'Șablon demo al testului', 'Noutăți demo', json_encode([
        ['type' => 'header', 'content' => 'Titlu demo', 'align' => 'center', 'background' => '#1a7a5e', 'text_color' => '#ffffff'],
        ['type' => 'text', 'content' => 'Text demo pentru abonați.', 'align' => 'left', 'background' => '#ffffff', 'text_color' => '#1f2937'],
        ['type' => 'button', 'label' => 'Vezi oferta', 'url' => 'https://exemplu.ro/oferta', 'align' => 'center', 'background' => '#1a7a5e',
            'text_color' => '#ffffff', 'radius' => 6],
    ], JSON_UNESCAPED_UNICODE), '']);
$idSablonNl = (int) $db->lastInsertId();
@mkdir($dosarTest . '/licenta', 0770, true);
putenv('LICENTA_DOSAR=' . $dosarTest . '/licenta');
exec(implode(' ', array_map('escapeshellarg', array_merge($phpDev, [$radacina . '/tools/dev/licenta.php', 'toate']))) . ' 2>&1', $_, $codLicentaD);
// După licență (pașii de activare ai pluginurilor se notează în settings la schimbarea ei).
$inainteD = $sumaD();
$trimite = static function (int $port, bool $vechi, string $nume, int $campanie = 0) use ($radacina, $dosarTest, $dosarDev, $idSablonNl): array {
    $fisier = $dosarTest . '/salvare-setari-' . $nume . '.json';
    @unlink($fisier);
    $env = ['CMS_DEV_URL=http://127.0.0.1:' . $port, 'CMS_DEV_PAROLA=' . trim((string) @file_get_contents($dosarDev . '/admin-parola.txt')),
        'CMS_DEV_ADMIN_EMAIL=' . (getenv('CMS_DEV_ADMIN_EMAIL') ?: 'admin@exemplu.ro'), 'CMS_DEV_REZULTAT=' . $fisier,
        'CMS_DEV_VECHI=' . ($vechi ? '1' : '0'), 'CMS_DEV_CAMPANIE=' . $campanie, 'CMS_DEV_SABLON=' . $idSablonNl,
        'CMS_DEV_DOAR_EMAILURI=' . ($nume === 'bioscem' ? '1' : '0'),
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
// Codul Bioscem însuși (arborele scos de compara-cu-baza.sh, Nou-site-bioscem la ultimul commit revizuit), pe aceeași bază:
// editoarele de emailuri din pagină trebuie să construiască același HTML al emailului (revizuirea WP-35).
$puneCampania();
$radacinaBioscem = dirname((glob($dosarDev . '/baza-Nou-site-bioscem-*/public/index.php') ?: [''])[0], 2);
$bioscemD = null;
if ($radacinaBioscem !== '.' && is_dir($radacinaBioscem)) {
    $serverBioscem = proc_open(array_merge($phpDev, ['-S', '127.0.0.1:' . $portEticheta, '-t', $radacinaBioscem . '/public', $radacina . '/tools/dev/router.php']),
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dosarTest . '/server-bioscem.log', 'a'], 2 => ['file', $dosarTest . '/server-bioscem.log', 'a']], $_, $radacinaBioscem);
    usleep(800000);
    $bioscemD = $trimite($portEticheta, true, 'bioscem', $idCampanie);
    $stareBioscem = proc_get_status($serverBioscem);
    exec('pkill -TERM -P ' . (int) $stareBioscem['pid'] . ' 2>/dev/null');
    proc_terminate($serverBioscem);
    proc_close($serverBioscem);
    $puneCampania();
}
$server = $pornesteServer();
// Constructorul nou pornește, ca cel vechi, de la campania goală: după două salvări neatinse, același JSON, octet cu octet.
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
    && count($vechiD['cazuri']) === count($nouD['cazuri']) && count($nouD['cazuri']) >= 19,
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
$verifica('codul Bioscem însuși: ' . count((array) ($bioscemD['cazuri'] ?? [])) . ' editoare de emailuri trimise, nicio eroare JavaScript', is_array($bioscemD)
    && ($bioscemD['probleme'] ?? []) === [] && count($bioscemD['cazuri']) === 5, implode('; ', (array) ($bioscemD['probleme'] ?? ['arborele Bioscem lipsește: rulează întâi compara-cu-baza.sh'])));
foreach ($nouD['cazuri'] as $numeCaz => $cerereNoua) {
    if (!str_starts_with($numeCaz, 'emailuri: ')) {
        continue;
    }
    $bioscem = (array) ($bioscemD['cazuri'][$numeCaz] ?? []);
    // Corpul întreg, octet cu octet (doar jetonul CSRF al sesiunii înlocuit): HTML-ul emailului se salvează exact așa.
    $corp = static fn (array $c): string => (string) preg_replace('/\b[0-9a-f]{64}\b/', '<TOKEN>', (string) ($c['corp'] ?? ''));
    $verifica('„' . $numeCaz . '”: cererea codului nou e octet cu octet cea a codului Bioscem (' . strlen($corp((array) $cerereNoua)) . ' octeți)',
        $bioscem !== [] && $corp($bioscem) === $corp((array) $cerereNoua),
        json_encode(['lipsă' => array_values(array_diff($perechi($bioscem)['perechi'], $perechi((array) $cerereNoua)['perechi'])),
            'în plus' => array_values(array_diff($perechi((array) $cerereNoua)['perechi'], $perechi($bioscem)['perechi']))], JSON_UNESCAPED_UNICODE));
}
$verifica('constructorul de emailuri: campania ' . $idCampanie . ' (pornind, pe ambele coduri, de la campania goală) salvată neatinsă de două ori cu cel nou (răspunsuri '
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
$db->prepare('DELETE FROM newsletter_templates WHERE id = ?')->execute([$idSablonNl]);

// =====================================================================
// E. Căutarea în tot adminul și scurtăturile (WP-36)
// =====================================================================
$scrie('');
$scrie('== E. Căutarea în tot adminul (Ctrl+K) și scurtăturile: fiecare licență de test × fiecare rol, doar ce se vede și e permis');
$semneText = require __DIR__ . '/lib/semne-pluginuri.php';
$valoare = static fn (string $sql): string => (string) ($db->query($sql)->fetchColumn() ?: '');
// Câte un caz pe sursă, din datele demo: [textul căutat, sursa la care trebuie să ajungă].
$cazuriCautare = [
    'comanda' => [$valoare('SELECT order_number FROM orders WHERE deleted_at IS NULL ORDER BY id LIMIT 1'), 'comenzi'],
    'sku' => [$valoare("SELECT sku FROM products WHERE deleted_at IS NULL AND sku <> '' ORDER BY id LIMIT 1"), 'produse'],
    'client' => [$valoare('SELECT email FROM users ORDER BY id LIMIT 1'), 'clienti'],
    'pagina' => [$valoare("SELECT title FROM pages WHERE deleted_at IS NULL AND slug = 'despre-noi'"), 'pagini'],
    'postare' => [$valoare('SELECT title FROM blog_posts WHERE deleted_at IS NULL ORDER BY id LIMIT 1'), 'postari'],
];
$verifica('cazurile din datele demo: ' . implode(', ', array_map(static fn (array $c): string => '„' . $c[0] . '”', $cazuriCautare)),
    !in_array('', array_column($cazuriCautare, 0), true));
// Ce liste are fiecare rol (scris aici, nu luat din Auth, ca o regulă schimbată acolo să se vadă): adminul general tot; rolul
// magazin comenzile și produsele; blogul și emailurile postările. Și pluginul fiecărei surse (null = nucleu).
$drepturiRol = ['general' => ['comenzi', 'produse', 'clienti', 'pagini', 'postari'], 'magazin' => ['comenzi', 'produse'],
    'blog' => ['postari'], 'emailuri' => ['postari']];
$pluginSursa = ['comenzi' => 'magazin', 'produse' => 'catalog', 'clienti' => 'conturi_clienti', 'pagini' => null, 'postari' => 'blog'];
$roluriAuth = ['general' => [\App\Support\Auth::ROLE_GENERAL], 'magazin' => [\App\Support\Auth::ROLE_STORE],
    'blog' => [\App\Support\Auth::ROLE_BLOG], 'emailuri' => [\App\Support\Auth::ROLE_EMAILS]];
$catalogPluginuri = Plugins::catalog();
$preseturi = ['toate' => array_keys($catalogPluginuri['pluginuri']), 'fara' => []];
foreach ((array) $catalogPluginuri['licente_dev'] as $numePreset => $lista) {
    $preseturi[$numePreset] = (array) $lista;
}
foreach ((array) $catalogPluginuri['pachete'] as $numePreset => $pachet) {
    $preseturi[$numePreset] = array_merge((array) ($pachet['pluginuri'] ?? []), (array) ($pachet['cel_putin_una_din'] ?? []));
}
// Cuvinte care ating paginile din admin, tab-urile setărilor și acțiunile (inclusiv numele pluginurilor).
$cuvinteGenerale = ['setari', 'plati', 'livrare', 'puncte', 'cupon', 'blog', 'newsletter', 'nou', 'cos', 'tva', 'fan', 'stripe', 'erp',
    'recenzii', 'sabloane', 'abonament', 'oferte', 'card', 'clienti', 'comenzi'];
/** Semnele pluginurilor oprite (tools/dev/lib/semne-pluginuri.php) și numele lor din catalog, într-un text. */
$semneAscunse = static function (string $text) use ($semneText): array {
    $gasite = [];
    foreach (Plugins::stari() as $plugin => $stare) {
        if ($stare !== Plugins::OPRIT) {
            continue;
        }
        $semne = $semneText[$plugin] ?? [];
        $nume = (string) (Plugins::definitie((string) $plugin)['nume'] ?? '');
        if (mb_strlen($nume) >= 4) {
            $semne[] = '/(?<!\p{L})' . preg_quote($nume, '/') . '(?!\p{L})/u';
        }
        foreach ($semne as $semn) {
            if (preg_match($semn, $text, $m) === 1) {
                $gasite[] = '„' . $m[0] . '” (' . $plugin . ' oprit)';
            }
        }
    }
    return $gasite;
};
$sursePeRol = [];
$numarCautari = 0;
$faraComenziCuFara = true;
$magazinFaraPostari = true;
foreach ($preseturi as $numePreset => $lista) {
    Plugins::folosesteStari(array_fill_keys($lista, Plugins::PORNIT));
    $probleme = [];
    foreach ($roluriAuth as $rol => $roluri) {
        $asteptate = array_values(array_filter($drepturiRol[$rol],
            static fn (string $s): bool => $pluginSursa[$s] === null || Plugins::vizibil((string) $pluginSursa[$s])));
        $surse = array_values(array_diff(\App\Support\CautareAdmin::surse($roluri), ['setari', 'actiuni']));
        $sursePeRol[$numePreset][$rol] = $surse;
        if ($surse !== $asteptate) {
            $probleme[] = $rol . ': sursele ' . implode(',', $surse) . ' în loc de ' . implode(',', $asteptate);
        }
        $texte = [];
        foreach (array_merge(array_map(static fn (array $c): string => $c[0], $cazuriCautare), $cuvinteGenerale) as $q) {
            $numarCautari++;
            $r = \App\Support\CautareAdmin::cauta($db, $q, $roluri);
            $gasite = array_column($r['grupuri'], 'sursa');
            foreach ($cazuriCautare as $numeCaz => [$qCaz, $sursaCaz]) {
                if ($qCaz === $q && in_array($sursaCaz, $gasite, true) !== in_array($sursaCaz, $asteptate, true)) {
                    $probleme[] = $rol . ' ' . $numeCaz . ': ' . $sursaCaz . (in_array($sursaCaz, $gasite, true) ? ' arătată fără drept sau fără plugin' : ' lipsă');
                }
            }
            foreach (array_diff($gasite, $asteptate, ['setari', 'actiuni']) as $s) {
                $probleme[] = $rol . ' „' . $q . '”: sursa ' . $s . ' fără drept sau fără plugin';
            }
            if ($numePreset === 'fara' && in_array('comenzi', $gasite, true)) {
                $faraComenziCuFara = false;
            }
            if ($rol === 'magazin' && in_array('postari', $gasite, true)) {
                $magazinFaraPostari = false;
            }
            foreach ($r['grupuri'] as $grup) {
                foreach ($grup['rezultate'] as $rez) {
                    if (!\App\Support\Auth::canAccessPath($rez['url'], $roluri) || !Plugins::adresaVizibila($rez['url'])) {
                        $probleme[] = $rol . ' „' . $q . '”: ' . $rez['url'] . ' nu se poate deschide';
                    }
                }
            }
            // Doar ce se vede (eticheta grupului, titlul și detaliul rezultatelor): nu textul căutat și nici adresele (cele ale
            // nucleului pot conține „newsletters”, /admin/emails/newsletters?tab=history).
            foreach ($r['grupuri'] as $grup) {
                $texte[] = $grup['eticheta'];
                foreach ($grup['rezultate'] as $rez) {
                    $texte[] = $rez['titlu'] . ' · ' . $rez['detaliu'];
                }
            }
        }
        $salturi = \App\Support\CautareAdmin::salturi($roluri);
        foreach ($salturi as $salt) {
            if (!\App\Support\Auth::canAccessPath($salt['url'], $roluri) || !Plugins::adresaVizibila($salt['url'])) {
                $probleme[] = $rol . ': „G ' . strtoupper($salt['litera']) . '” duce la ' . $salt['url'] . ', care nu se poate deschide';
            }
        }
        foreach ($salturi as $salt) {
            $texte[] = $salt['eticheta'] . ' · ' . $salt['pagina'];
        }
        $texte[] = \App\Support\CautareAdmin::indemn($roluri);
        foreach (array_unique($semneAscunse(implode("\n", $texte))) as $semn) {
            $probleme[] = $rol . ': ' . $semn;
        }
    }
    $verifica('licența „' . $numePreset . '”: 4 roluri × ' . (count($cazuriCautare) + count($cuvinteGenerale)) . ' căutări — surse '
        . implode(' | ', array_map(static fn (string $rol, array $s): string => $rol . ' ' . ($s === [] ? '-' : implode(',', $s)),
            array_keys($sursePeRol[$numePreset]), $sursePeRol[$numePreset]))
        . '; fiecare rezultat și fiecare „G <literă>” se poate deschide; niciun nume sau text al unui plugin oprit', $probleme === [],
        implode('; ', array_slice(array_values(array_unique($probleme)), 0, 10)));
}
$verifica('fără licență („fara”) nicio comandă, pentru niciun rol; rolul magazin nu primește niciodată postări', $faraComenziCuFara && $magazinFaraPostari);
Plugins::reseteaza();
Plugins::incarca();

// Viteza și interogările, pe o bază separată cu 500 de produse, 4.000 de comenzi, 1.000 de clienți, 60 de pagini și 150 de postări.
$bazaCautare = 'cms_test_cauta';
$db->exec('DROP DATABASE IF EXISTS ' . $bazaCautare);
$db->exec('CREATE DATABASE ' . $bazaCautare . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
foreach (['orders', 'products', 'users', 'pages', 'blog_posts'] as $tabel) {
    $creare = (string) $db->query('SHOW CREATE TABLE `' . $numeBaza . '`.`' . $tabel . '`')->fetch(PDO::FETCH_NUM)[1];
    $creare = (string) preg_replace('/,\s*CONSTRAINT `[^`]+` FOREIGN KEY [^\n]+/', '', $creare);
    $db->exec('USE ' . $bazaCautare);
    $db->exec($creare);
    $db->exec('USE `' . $numeBaza . '`');
}
$tc = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['db']['host'], (int) $config['db']['port'], $bazaCautare),
    (string) $config['db']['username'], (string) $config['db']['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
mt_srand(36);
$cuvinteProdus = ['Ceai', 'Miere', 'Ulei', 'Sirop', 'Pudră', 'Capsule', 'Tinctură', 'Cremă', 'Balsam', 'Sare'];
$adjective = ['de mentă', 'de tei', 'de cocos', 'de cătină', 'de ghimbir', 'bio', 'cu propolis', 'de lavandă', 'de măceșe', 'de ovăz'];
$valori = [];
for ($i = 1; $i <= 500; $i++) {
    $valori[] = sprintf("(%d, '%s', 'T-%04d', 'test-%d', 10.00, %d, %d)", $i, $cuvinteProdus[$i % 10] . ' ' . $adjective[intdiv($i, 10) % 10] . ' ' . $i,
        $i, $i, mt_rand(0, 40), $i % 9 === 0 ? 0 : 1);
}
$tc->exec('INSERT INTO products (id, name, sku, slug, price, stock, is_active) VALUES ' . implode(', ', $valori));
$prenumeT = ['Ana', 'Ioana', 'Mihai', 'Radu', 'Elena', 'Victor', 'Daria', 'Paul', 'Sorin', 'Irina'];
$numeT = ['Exemplu', 'Demo', 'Test', 'Proba', 'Model'];
foreach (array_chunk(range(1, 4000), 1000) as $bucata) {
    $valori = [];
    foreach ($bucata as $i) {
        $valori[] = sprintf("(%d, 'T%06d', 'completed', 'cod', 'paid', 50.00, 0, 50.00, '%s', '%s', 'c%d@exemplu.ro', '0700000000', 'Str. Test 1', 'Iasi', 'Iasi', '700000', '2026-10-01 10:00:00')",
            $i, $i, $prenumeT[$i % 10], $numeT[$i % 5], $i);
    }
    $tc->exec('INSERT INTO orders (id, order_number, status, payment_method, payment_status, subtotal, shipping_cost, total, billing_first_name, billing_last_name,
        billing_email, billing_phone, billing_address_line1, billing_city, billing_county, billing_postcode, created_at) VALUES ' . implode(', ', $valori));
}
$valori = [];
for ($i = 1; $i <= 1000; $i++) {
    $valori[] = sprintf("('%s', '%s', 'client%d@exemplu.ro', 'x')", $prenumeT[$i % 10], $numeT[$i % 5], $i);
}
$tc->exec('INSERT INTO users (first_name, last_name, email, password_hash) VALUES ' . implode(', ', $valori));
$valori = [];
for ($i = 1; $i <= 60; $i++) {
    $valori[] = sprintf("('Pagina de test %d', 'pagina-test-%d', '', 1)", $i, $i);
}
$tc->exec('INSERT INTO pages (title, slug, html_content, is_published) VALUES ' . implode(', ', $valori));
$valori = [];
for ($i = 1; $i <= 150; $i++) {
    $valori[] = sprintf("('Articol despre %s %d', 'articol-%d', '', 1, '2026-09-01 10:00:00')", $cuvinteProdus[$i % 10], $i, $i);
}
$tc->exec('INSERT INTO blog_posts (title, slug, content, is_published, published_at) VALUES ' . implode(', ', $valori));
Plugins::folosesteStari(array_fill_keys(array_keys($catalogPluginuri['pluginuri']), Plugins::PORNIT));
$tc->setAttribute(PDO::ATTR_STATEMENT_CLASS, [InstructiuneInregistrata::class]);
$cautariViteza = ['T001234', '1234', 'Ana Exemplu', 'client77@exemplu.ro', 'T-0042', 'ceai', 'miere bio', 'cătină', 'pagina test', 'articol', 'ex', 'zzzz'];
$celMaiLent = 0;
$detaliiViteza = [];
$grupuriCeai = [];
foreach ($cautariViteza as $q) {
    for ($k = 0; $k < 3; $k++) {
        InstructiuneInregistrata::$jurnal = [];
        $r = \App\Support\CautareAdmin::cauta($tc, $q, [\App\Support\Auth::ROLE_GENERAL]);
        $celMaiLent = max($celMaiLent, $r['ms']);
    }
    $detaliiViteza[] = $q . ' ' . $r['ms'] . ' ms';
    if ($q === 'ceai') {
        $grupuriCeai = $r['grupuri'];
    }
}
$tc->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
$verifica('viteza: ' . count($cautariViteza) . ' căutări × 3 pe 500 de produse, 4.000 de comenzi, 1.000 de clienți; cea mai lentă ' . $celMaiLent
    . ' ms (cel mult 300)', $celMaiLent < 300, implode(', ', $detaliiViteza));
$limitate = true;
$faraTextInSql = true;
InstructiuneInregistrata::$jurnal = [];
$tc->setAttribute(PDO::ATTR_STATEMENT_CLASS, [InstructiuneInregistrata::class]);
\App\Support\CautareAdmin::cauta($tc, "ceai' OR '1'='1", [\App\Support\Auth::ROLE_GENERAL]);
$injectie = \App\Support\CautareAdmin::cauta($tc, "zz' OR '1'='1", [\App\Support\Auth::ROLE_GENERAL]);
$procente = \App\Support\CautareAdmin::cauta($tc, '%%', [\App\Support\Auth::ROLE_GENERAL]);
$liniute = \App\Support\CautareAdmin::cauta($tc, '__', [\App\Support\Auth::ROLE_GENERAL]);
$tc->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
$esteMaria = stripos((string) $tc->getAttribute(PDO::ATTR_SERVER_VERSION), 'mariadb') !== false;
foreach (InstructiuneInregistrata::$jurnal as $instructiune) {
    $sql = $instructiune['sql'];
    $limitate = $limitate && preg_match('/\bLIMIT \d+$/', trim($sql)) === 1
        && ($esteMaria ? str_starts_with($sql, 'SET STATEMENT max_statement_time = 0.250 FOR SELECT ') : str_contains($sql, 'MAX_EXECUTION_TIME(250)'));
    $faraTextInSql = $faraTextInSql && !str_contains($sql, "'1'='1") && !str_contains($sql, 'ceai') && $instructiune['parametri'] !== [];
}
$doarDinBaza = static fn (array $r): array => array_values(array_diff(array_column($r['grupuri'], 'sursa'), ['setari', 'actiuni']));
$verifica('interogările: ' . count(InstructiuneInregistrata::$jurnal) . ', fiecare cu LIMIT, cu limita de timp a serverului ('
    . ($esteMaria ? 'max_statement_time 0,25 s' : 'MAX_EXECUTION_TIME 250') . ') și cu textul căutat doar ca parametru legat', $limitate && $faraTextInSql
    && count(InstructiuneInregistrata::$jurnal) >= 15, json_encode(array_slice(array_column(InstructiuneInregistrata::$jurnal, 'sql'), 0, 3)));
$verifica('„zz\' OR \'1\'=\'1”, „%%” și „__” se caută ca text (niciun rând din bază), fără erori', $doarDinBaza($injectie) === []
    && $doarDinBaza($procente) === [] && $doarDinBaza($liniute) === [], json_encode([$injectie, $procente, $liniute], JSON_UNESCAPED_UNICODE));
$verifica('cel mult ' . \App\Support\CautareAdmin::LIMITA . ' rezultate pe sursă („ceai” găsește 50 de produse și 15 postări)',
    $grupuriCeai !== [] && max(array_map(static fn (array $g): int => count($g['rezultate']), $grupuriCeai)) <= \App\Support\CautareAdmin::LIMITA + 2
    && count(array_values(array_filter($grupuriCeai, static fn (array $g): bool => $g['sursa'] === 'produse'))[0]['rezultate'] ?? []) === \App\Support\CautareAdmin::LIMITA);
Plugins::reseteaza();
Plugins::incarca();
$db->exec('DROP DATABASE IF EXISTS ' . $bazaCautare);

// În browser, pe serverul testului: fereastra de la Ctrl+K pentru fiecare rol, lista de la „?”, „G <literă>”, tastarea în câmpuri,
// lista comenzilor cu o comandă bifată (tools/dev/plimbare-cautare.cjs). Licența „toate” (toate rolurile), apoi „grafoanaytis”.
@mkdir($dosarTest . '/licenta', 0770, true);
putenv('LICENTA_DOSAR=' . $dosarTest . '/licenta');
$activariInainteE = $db->query("SELECT `value` FROM settings WHERE `key` = 'activari_pluginuri'")->fetchColumn();
$sumaE = static fn (): string => json_encode($db->query('CHECKSUM TABLE orders, order_items, products, users, pages, blog_posts')->fetchAll(PDO::FETCH_NUM));
$inainteE = $sumaE();
$server = $pornesteServer();
$emailuriRol = ['adm' => (string) (getenv('CMS_DEV_ADMIN_EMAIL') ?: 'admin@exemplu.ro'), 'magazin' => 'magazin@exemplu.ro', 'blog' => 'blog@exemplu.ro',
    'emailuri' => 'emailuri@exemplu.ro'];
$rolAuth = ['adm' => 'general', 'magazin' => 'magazin', 'blog' => 'blog', 'emailuri' => 'emailuri'];
$plimbaCautare = static function (string $licenta, array $roluri, bool $comenzi) use ($phpDev, $radacina, $portTest, $dosarTest, $dosarDev, $cazuriCautare, $emailuriRol): ?array {
    exec(implode(' ', array_map('escapeshellarg', array_merge($phpDev, [$radacina . '/tools/dev/licenta.php', $licenta]))) . ' 2>&1', $_, $codLicenta);
    $intrare = $dosarTest . '/plimbare-cautare-' . $licenta . '-intrare.json';
    $fisier = $dosarTest . '/plimbare-cautare-' . $licenta . '.json';
    @unlink($fisier);
    file_put_contents($intrare, json_encode([
        'cazuri' => array_map(static fn (string $nume, array $c): array => ['nume' => $nume, 'q' => $c[0]], array_keys($cazuriCautare), $cazuriCautare),
        'roluri' => array_map(static fn (string $rol): array => ['rol' => $rol, 'email' => $emailuriRol[$rol]], $roluri),
        'comanda' => $cazuriCautare['comanda'][0],
        'comenzi' => $comenzi,
        'campText' => ['pagina' => '/admin/settings/store?tab=general', 'selector' => 'input[name="maintenance_title"]'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $env = ['CMS_DEV_URL=http://127.0.0.1:' . $portTest, 'CMS_DEV_PAROLA=' . trim((string) @file_get_contents($dosarDev . '/admin-parola.txt')),
        'CMS_DEV_PLIMBARE=' . $intrare, 'CMS_DEV_REZULTAT=' . $fisier,
        'NODE_PATH=' . (getenv('NODE_PATH') ?: '/opt/node22/lib/node_modules'), 'PLAYWRIGHT_BROWSERS_PATH=' . (getenv('PLAYWRIGHT_BROWSERS_PATH') ?: '/opt/pw-browsers')];
    exec('env ' . implode(' ', array_map('escapeshellarg', $env)) . ' node ' . escapeshellarg($radacina . '/tools/dev/plimbare-cautare.cjs') . ' 2>&1', $iesire, $cod);
    $date = json_decode((string) @file_get_contents($fisier), true);
    return $codLicenta === 0 && $cod === 0 && is_array($date) ? $date : ['eroare' => implode(' ', array_slice($iesire, -3))];
};
// Pe grafoanaytis nu există comenzi: doar rolurile, căutările, lista de la „?” și „G <literă>”.
$rezultateBrowser = ['toate' => $plimbaCautare('toate', ['adm', 'magazin', 'blog', 'emailuri'], true),
    'grafoanaytis' => $plimbaCautare('grafoanaytis', ['adm', 'magazin'], false)];
$stareServer = proc_get_status($server);
exec('pkill -TERM -P ' . (int) $stareServer['pid'] . ' 2>/dev/null');
proc_terminate($server);
proc_close($server);
putenv('LICENTA_DOSAR');
if ($activariInainteE !== false) {
    $db->prepare("UPDATE settings SET `value` = ? WHERE `key` = 'activari_pluginuri'")->execute([$activariInainteE]);
}
foreach ($rezultateBrowser as $licentaBrowser => $plimbareCautare) {
    if (!isset($plimbareCautare['roluri'])) {
        $verifica('plimbarea căutării (licența ' . $licentaBrowser . ') s-a terminat', false, (string) ($plimbareCautare['eroare'] ?? '') . ' (vezi ' . $dosarTest . ')');
        continue;
    }
    // Ce trebuie să vadă fiecare rol pe licența serverului: aceleași calcule, în acest proces, cu aceeași licență.
    Plugins::folosesteStari(array_fill_keys($preseturi[$licentaBrowser], Plugins::PORNIT));
    $probleme = $plimbareCautare['probleme'];
    $rezumat = [];
    foreach ($plimbareCautare['roluri'] as $rol => $dateRol) {
        $roluri = $roluriAuth[$rolAuth[$rol]];
        foreach ($cazuriCautare as $numeCaz => [$q]) {
            $asteptat = array_column(\App\Support\CautareAdmin::cauta($db, $q, $roluri)['grupuri'], 'sursa');
            $primit = array_column((array) ($dateRol['cazuri'][$numeCaz] ?? []), 'sursa');
            if ($primit !== $asteptat) {
                $probleme[] = $rol . ' ' . $numeCaz . ': ' . implode(',', $primit) . ' în loc de ' . implode(',', $asteptat);
            }
        }
        $salturiAsteptate = array_map(static fn (array $s): string => $s['litera'] . ' ' . $s['url'], \App\Support\CautareAdmin::salturi($roluri));
        $salturiPrimite = array_map(static fn (array $s): string => $s['litera'] . ' ' . $s['url'], (array) $dateRol['salturi']);
        if ($salturiPrimite !== $salturiAsteptate) {
            $probleme[] = $rol . ': lista „G” ' . implode(', ', $salturiPrimite) . ' în loc de ' . implode(', ', $salturiAsteptate);
        }
        if (($dateRol['salt']['ajuns'] ?? null) !== ($dateRol['salt']['asteptat'] ?? '')) {
            $probleme[] = $rol . ': „G ' . strtoupper((string) ($dateRol['salt']['litera'] ?? '?')) . '” a dus la ' . ($dateRol['salt']['ajuns'] ?? '-');
        }
        foreach (array_unique($semneAscunse((string) ($dateRol['textTaste'] ?? ''))) as $semn) {
            $probleme[] = $rol . ' lista de la „?”: ' . $semn;
        }
        foreach ((array) $dateRol['erori'] as $eroare) {
            $probleme[] = $rol . ' JavaScript: ' . $eroare;
        }
        $rezumat[] = $rol . ' G ' . implode('', array_column((array) $dateRol['salturi'], 'litera'));
    }
    foreach ((array) $plimbareCautare['postari'] as $post) {
        $probleme[] = 'cerere POST (oprită): ' . $post;
    }
    foreach ((array) $plimbareCautare['blocate'] as $gazda) {
        $probleme[] = 'cerere spre alt server: ' . $gazda;
    }
    $verifica('în browser, licența „' . $licentaBrowser . '”: Ctrl+K și cele ' . count($cazuriCautare) . ' căutări pe fiecare rol dau exact sursele permise; '
        . 'lista de la „?” are doar grupurile vizibile (' . implode('; ', $rezumat) . '), „G <literă>” duce acolo; nicio eroare JavaScript, nicio cerere POST '
        . 'sau spre alt server', $probleme === [], implode('; ', array_slice($probleme, 0, 10)));
    $g = (array) ($plimbareCautare['general'] ?? []);
    $nrComanda = $cazuriCautare['comanda'][0];
    if ($licentaBrowser === 'toate') {
        $verifica('Enter pe primul rezultat al numărului comenzii ' . $nrComanda . ': lista comenzilor cu panoul comenzii deschis; Esc închide fereastra căutării '
            . 'și focusul revine pe căutarea din antet', ($g['primul'] ?? '') === '/admin/orders?q=' . $nrComanda
            && ($g['dupaEnter']['url'] ?? '') === '/admin/orders?q=' . $nrComanda && !empty($g['dupaEnter']['panou']) && !empty($g['focusInapoi']), json_encode($g));
        $verifica('într-un câmp de text, „?”, „g”, „c”, Ctrl+K și „gp” se scriu în câmp: nicio fereastră, nicio notă „G”, aceeași pagină',
            ($g['inCamp']['valoare'] ?? '') === '?gcgp' && ($g['inCamp']['deschise'] ?? ['?']) === [] && !empty($g['inCamp']['aceeasiPagina'])
            && empty($g['inCamp']['nota']), json_encode($g['inCamp'] ?? null, JSON_UNESCAPED_UNICODE));
        $verifica('lista comenzilor, o comandă bifată: „G X” nu face nimic, „G C” deschide Comenzi fără nicio confirmare (tasta C nu ajunge la „Confirmă”), '
            . '„C” singur cere tot confirmarea (refuzată: nimic trimis)', ($g['gNecunoscut']['confirmari'] ?? -1) === 0 && ($g['gNecunoscut']['url'] ?? '') === '/admin/orders'
            && !empty($g['gNecunoscut']['bifata']) && ($g['gc']['confirmari'] ?? -1) === 0 && ($g['gc']['url'] ?? '') === '/admin/orders'
            && count((array) ($g['cSingur']['confirmari'] ?? [])) === 1 && ($g['cSingur']['url'] ?? '') === '/admin/orders', json_encode($g, JSON_UNESCAPED_UNICODE));
        $verifica('fără sesiune, JSON-ul căutării răspunde 401 și nu dă nimic', ($plimbareCautare['anonim']['cod'] ?? 0) === 401
            && !str_contains((string) ($plimbareCautare['anonim']['corp'] ?? ''), $nrComanda), json_encode($plimbareCautare['anonim'] ?? null, JSON_UNESCAPED_UNICODE));
    }
}
Plugins::reseteaza();
Plugins::incarca();
$verifica('baza neatinsă de plimbarea căutării (CHECKSUM TABLE orders, order_items, products, users, pages, blog_posts)', $sumaE() === $inainteE);

$scrie('');
$scrie(sprintf('Rezultat: %d trecute, %d eșuate. Raport: %s', $trecute, $esuate, $fisierRaport));
exit($esuate === 0 ? 0 : 1);
