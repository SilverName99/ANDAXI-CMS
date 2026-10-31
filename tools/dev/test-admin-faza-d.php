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

$scrie('');
$scrie(sprintf('Rezultat: %d trecute, %d eșuate. Raport: %s', $trecute, $esuate, $fisierRaport));
exit($esuate === 0 ? 0 : 1);
