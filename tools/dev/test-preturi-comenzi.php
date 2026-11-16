<?php

declare(strict_types=1);

/**
 * Consecvența prețurilor unei comenzi pe fiecare suprafață (cerută pe 16 nov 2026, după o clientă Biovitality cu oferta
 * campaniei „1+1, al doilea la −50%”: checkout-ul arăta o sumă, emailul de expediere alta; docs/PLUGINURI.md, „Campanii pe
 * site”, „Consecvența sumelor”). Cu serverul de dezvoltare, pe baza de test cu datele demo și prețuri puse de test:
 *
 *   tools/dev/php.sh tools/dev/test-preturi-comenzi.php
 *
 * Pentru fiecare combinație (oferta campaniei pe fiecare tip, peste prețul redus, perioada în curs și lotul BBD; cupoanele:
 * procent, fix, unic, fără cumulare, doar pe produsele alese, cu minim de bucăți; punctele folosite; transportul: sub și peste
 * prag, produsul cu transport gratuit, FANbox, ridicarea de la sediu; precomanda; cantități > 1 și rotunjirea la cent; plata la
 * livrare și cu cardul prin EuPlătesc) comanda se plasează prin site, apoi fiecare suprafață se compară cu rândul comenzii din
 * bază (sursa unică după plasare):
 *  - înainte: sumarul coșului (coșul plutitor, /api/cart/summary), pagina coșului, pagina checkout-ului (totalul, transportul,
 *    rândul ofertei, liniile);
 *  - comanda: orders + order_items (totalul = produse − cupon − puncte + transport, suma liniilor = subtotalul, reducerile ofertei
 *    pe linii = cea a comenzii, prețul de dinainte × cantitate − oferta = totalul liniei);
 *  - după: emailul „Comandă nouă” (liniile, rândul ofertei, totalul), pagina de confirmare, contul clientului, Admin → Comenzi
 *    (datele ferestrei comenzii și ce arată ea pe linii), payload-ul ERP (/api/comenzi-site/ingest), suma trimisă la EuPlătesc;
 *  - expedierea: emailul „Comandă expediată” din Admin (starea „completed”) și din ERP (aprobarea cu AWB-ul deja pe comandă), cu
 *    șablonul implicit și cu un șablon care are și {{order_totals_html}} / {{order_summary}};
 *  - după o corectură venită din ERP (comanda_modificata, exact cum o trimite ERP-ul: prețurile fără TVA la 4 zecimale și
 *    înapoi), după o cantitate schimbată în ERP pe linia ofertei și după o reducere comercială din Admin: rândul comenzii, emailul
 *    de expediere și fereastra din Admin rămân consecvente.
 *
 * Curățenia: toată baza (fiecare tabel) e pusă la loc, verificat cu CHECKSUM TABLE; emailurile capturate de test se șterg;
 * jurnalul PHP fără avertismente noi. Raportul: $CMS_DEV_DIR/test-preturi-comenzi-raport.txt (doar date demo). Cod de ieșire 0
 * doar dacă totul a trecut.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';
require_once __DIR__ . '/lib/salvari-admin.php';

use App\Support\Campanii;
use App\Support\Database;
use App\Support\OfertaCampanie;
use App\Support\Paths;
use App\Support\ResponseCache;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-preturi-comenzi-raport.txt';
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
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . mb_substr($detalii, 0, 1800) : ''));
    }
};
$scrie('Test consecvența prețurilor comenzilor ANDAXI-CMS — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

// ── Serverul și baza ─────────────────────────────────────────────────────────────────────────────────────────────────
$port = (int) (getenv('CMS_DEV_PORT') ?: '8701');
$baza = 'http://127.0.0.1:' . $port;
$browser = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
$cerere = static function (string $metoda, string $cale, ?string $jar = null, array|string $date = [], array $antete = []) use ($baza, $browser): array {
    $primite = [];
    $c = curl_init(str_starts_with($cale, 'http') ? $cale : $baza . $cale);
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOPROXY => '*',
        CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => array_merge(['User-Agent: ' . $browser, 'Accept: text/html,application/xhtml+xml,*/*;q=0.8'], $antete),
        CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$primite): int {
            if (str_contains($linie, ':')) {
                [$nume, $valoare] = explode(':', $linie, 2);
                $primite[strtolower(trim($nume))] = trim($valoare);
            }
            return strlen($linie);
        },
    ]);
    if ($metoda === 'POST') {
        curl_setopt($c, CURLOPT_POSTFIELDS, is_string($date) ? $date : http_build_query($date));
    }
    if ($jar !== null) {
        curl_setopt($c, CURLOPT_COOKIEFILE, $jar);
        curl_setopt($c, CURLOPT_COOKIEJAR, $jar);
    }
    $corp = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $cod, 'antete' => $primite, 'corp' => $corp];
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
if (!$db instanceof PDO || (int) $db->query('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND is_active = 1')->fetchColumn() < 10) {
    $scrie('EROARE: baza de test nu are datele demo; rulează întâi tools/dev/instaleaza.sh');
    exit(1);
}
if (!Campanii::disponibil($db)) {
    $scrie('EROARE: baza nu are tabelele pluginului campanii (tools/dev/php.sh scripts/migreaza.php --aplica)');
    exit(1);
}
$unu = static function (string $sql, array $param = []) use ($db): string {
    $stmt = $db->prepare($sql);
    $stmt->execute($param);
    $valoare = $stmt->fetchColumn();
    return $valoare === false || $valoare === null ? '' : (string) $valoare;
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
    foreach ($db->query('CHECKSUM TABLE ' . $lista)->fetchAll(PDO::FETCH_NUM) as $rand) {
        $sume[(string) $rand[0]] = (string) $rand[1];
    }
    return $sume;
};
$instantaneu = salvari_instantaneu($db, $tabeleBaza);
$sumeInainte = $sumeBaza();
$sesiuniInainte = glob(Paths::stocare('sessions') . '/sess_*') ?: [];
$mailuriLaInceput = glob($dosarDev . '/mail/*') ?: [];
$fisierErori = $dosarDev . '/php-erori.log';
$pozitieErori = is_file($fisierErori) ? (int) filesize($fisierErori) : 0;
$parola = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));
$adminEmail = (string) (getenv('CMS_DEV_ADMIN_EMAIL') ?: 'admin@exemplu.ro');
$jaruri = [];
$jar = static function (string $nume) use ($dosarDev, &$jaruri): string {
    $cale = $dosarDev . '/test-preturi-' . $nume . '-' . getmypid() . '.txt';
    @unlink($cale);
    $jaruri[] = $cale;
    return $cale;
};
$licentaSchimbata = false;
$puneLaLoc = static function () use (&$licentaSchimbata, &$jaruri, $db, $tabeleBaza, $instantaneu, $radacina, $sesiuniInainte, $mailuriLaInceput,
    $dosarDev, $amPornitServerul): void {
    if ($licentaSchimbata) {
        exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/tools/dev/licenta.php') . ' toate >/dev/null 2>&1');
        $licentaSchimbata = false;
    }
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    salvari_pune_la_loc($db, $tabeleBaza, $instantaneu);
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    ResponseCache::purgePageCache();
    foreach (array_diff(glob(Paths::stocare('sessions') . '/sess_*') ?: [], $sesiuniInainte) as $sesiune) {
        @unlink($sesiune);
    }
    foreach (array_diff(glob($dosarDev . '/mail/*') ?: [], $mailuriLaInceput) as $mail) {
        @unlink($mail);
    }
    foreach ($jaruri as $cale) {
        @unlink($cale);
    }
    $jaruri = [];
    if ($amPornitServerul) {
        exec(escapeshellarg($radacina . '/tools/dev/server.sh') . ' --opreste >/dev/null 2>&1');
    }
};
register_shutdown_function(static function () use ($puneLaLoc): void {
    $puneLaLoc();
});
exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/tools/dev/licenta.php') . ' toate 2>&1', $iesireLicenta, $codLicenta);
if ($codLicenta !== 0) {
    $scrie('EROARE: licența „toate” nu s-a pus');
    exit(1);
}

$setare = static function (string $cheie, ?string $valoare) use ($db): void {
    if ($valoare === null) {
        $db->prepare('DELETE FROM settings WHERE `key` = :k')->execute(['k' => $cheie]);
        return;
    }
    $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (:k, :v) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
        ->execute(['k' => $cheie, 'v' => $valoare]);
};

// ── Datele testului: prețuri, setări, cupoane, campania ─────────────────────────────────────────────────────────────
// Produsele demo (doar din cele fără reducere proprie, fără BBD și fără precomandă), cu prețuri alese ca rotunjirile să conteze.
$faraReducere = ' AND (sale_price IS NULL OR sale_price <= 0 OR sale_price >= price) AND COALESCE(bbd_enabled, 0) = 0
    AND COALESCE(preorder_enabled, 0) = 0 AND out_of_stock = 0 AND stock >= 20' . " AND (sale_price_periods_json IS NULL OR sale_price_periods_json IN ('', '[]'))";
$ids = array_map('intval', $db->query('SELECT id FROM products WHERE deleted_at IS NULL AND is_active = 1 AND slug <> ""' . $faraReducere
    . ' ORDER BY id LIMIT 8')->fetchAll(PDO::FETCH_COLUMN));
$idBbd = (int) $unu('SELECT id FROM products WHERE deleted_at IS NULL AND is_active = 1 AND bbd_enabled = 1 AND bbd_entries_json LIKE "%reduced_price%" ORDER BY id LIMIT 1');
$idPrecomanda = (int) $unu('SELECT id FROM products WHERE deleted_at IS NULL AND is_active = 1 AND preorder_enabled = 1 ORDER BY id LIMIT 1');
if (count($ids) < 8 || $idBbd <= 0 || $idPrecomanda <= 0) {
    $scrie('EROARE: datele demo nu au destule produse (8 obișnuite, unul BBD, unul în precomandă)');
    exit(1);
}
// A 100, B 80, C 45,99 și D 60 (în afara campaniei), R 50 redus la 40, T 30 cu o perioadă de reducere în curs (24),
// G 70 cu transport gratuit, E 33,33 (rotunjirea la −20%).
[$pA, $pB, $pC, $pD, $pR, $pT, $pG, $pE] = $ids;
$preturi = [$pA => [100.00, null], $pB => [80.00, null], $pC => [45.99, null], $pD => [60.00, null], $pR => [50.00, 40.00],
    $pT => [30.00, null], $pG => [70.00, null], $pE => [33.33, null]];
$cuGratuit = (bool) $unu("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'free_shipping'");
foreach ($preturi as $id => [$pret, $redus]) {
    $db->prepare('UPDATE products SET price = :p, sale_price = :s, vat_percent = 21, vat_included = 1, stock = 200, sale_price_periods_json = NULL'
        . ($cuGratuit ? ', free_shipping = 0' : '') . ' WHERE id = :id')->execute(['p' => $pret, 's' => $redus, 'id' => $id]);
}
$db->prepare('UPDATE products SET sale_price_periods_json = :j WHERE id = :id')->execute(['id' => $pT, 'j' => json_encode([[
    'start_date' => date('Y-m-d H:i:s', time() - 86400), 'end_date' => date('Y-m-d H:i:s', time() + 86400), 'sale_price' => 24.00]])]);
if ($cuGratuit) {
    $db->prepare('UPDATE products SET free_shipping = 1 WHERE id = :id')->execute(['id' => $pG]);
}
$db->prepare('UPDATE products SET vat_percent = 21, vat_included = 1, stock = 200 WHERE id IN (:a, :b)')->execute(['a' => $idBbd, 'b' => $idPrecomanda]);
$loturi = (new ReflectionMethod(\App\Support\CheckoutCalculator::class, 'normalizeProductBbdEntries'))
    ->invoke(null, $unu('SELECT bbd_entries_json FROM products WHERE id = :id', ['id' => $idBbd]));
$lotBbd = is_array($loturi[0] ?? null) ? $loturi[0] : [];
$pretBbd = (float) $unu('SELECT price FROM products WHERE id = :id', ['id' => $idBbd]);

// Transportul: tarif propriu (fără prețul fix și fără tariful FAN), provincia 19 lei sub 250 lei, Bucureștiul 12 lei sub 150.
// Punctele: 0,10 lei/punct, minim 100, cel mult 50% din comandă, 1 punct/leu. ERP-ul pornit (adresa: gaura neagră), EuPlătesc
// pornit (cheile inventate), ridicarea de la sediu oferită.
foreach ([
    'shipping_fixed_enabled' => '0', 'fan_live_tariff_enabled' => '0', 'shipping_free_province' => '250', 'shipping_cost_province' => '19',
    'shipping_free_bucharest' => '150', 'shipping_cost_bucharest' => '12', 'shipping_max_cost' => '40', 'shipping_include_coupons' => '1',
    'floating_cart_free_shipping_threshold' => '0',
    'loyalty_points_enabled' => '1', 'loyalty_points_earn_rate' => '1', 'loyalty_points_redeem_value' => '0.10',
    'loyalty_points_min_redeem' => '100', 'loyalty_points_max_redeem_percent' => '50', 'loyalty_points_promo_active' => '0',
    'loyalty_points_weekend_multiplier' => '1',
    'erp_enabled' => '1', 'erp_url' => 'http://127.0.0.1:9', 'erp_api_key' => 'cheie-erp-test-preturi', 'erp_timeout' => '2',
    'euplatesc_enabled' => '1', 'euplatesc_merchant_id' => 'comerciant-test', 'euplatesc_secret_key' => '00112233445566778899AABBCCDDEEFF',
    'stripe_enabled' => '0', 'bt_ipay_enabled' => '0',
    'shipping_pickup_enabled' => '1', 'shipping_pickup_address' => 'Strada Depozitului 1, București',
] as $cheie => $valoare) {
    $setare($cheie, $valoare);
}
foreach (['new_order', 'shipped', 'delivered'] as $tipMail) {
    $setare(\App\Support\OrderMailer::templateRecipientModeKey($tipMail), 'customer');
}
// Cupoanele testului (inventate), lângă DEMO10 (10%) și UNIC-DEMO-0002 (fix 20, unic) din datele demo.
$insCupon = $db->prepare('INSERT INTO coupons (name, code, type, value, is_active, is_unique, product_ids_json, apply_only_selected_products,
    stacks_with_discounts, min_items_count) VALUES (:n, :c, :t, :v, 1, 0, :p, :sel, :stack, :min)');
foreach ([
    ['TSTFARACUMUL', 'percent', 10, null, 0, 0, 0], ['TSTALESE', 'fixed', 10, json_encode([$pD]), 1, 1, 0],
    ['TSTMIN3', 'percent', 5, null, 0, 1, 3],
] as [$cod, $tip, $valoare, $produse, $alese, $cumul, $min]) {
    $insCupon->execute(['n' => 'Test ' . $cod, 'c' => $cod, 't' => $tip, 'v' => $valoare, 'p' => $produse, 'sel' => $alese, 'stack' => $cumul, 'min' => $min]);
}
$db->exec("UPDATE coupons SET used_at = NULL, is_active = 1 WHERE code = 'UNIC-DEMO-0002'");
// Clientul demo cu puncte (parola: cea a adminului de test, ca în datele demo).
$emailClient = 'andrei.exemplu@exemplu.ro';
$db->prepare('UPDATE users SET loyalty_points = 1000 WHERE email = :e')->execute(['e' => $emailClient]);
// Campania, fără trimiterea la pagina ei și fără banner (doar oferta); tipul ofertei se schimbă pe caz.
$db->prepare("INSERT INTO pages (title, slug, html_content, is_published) VALUES ('Campanie test prețuri', 'test-preturi-campanie', '<p>Test</p>', 0)")->execute();
$idPagina = (int) $db->lastInsertId();
$db->prepare('INSERT INTO campanii (nume, slug, pornita, incepe_la, se_termina_la, pagina_id, pagina_la_intrare, banner_pornit, oferta_tip,
    oferta_n, oferta_m, oferta_procent) VALUES (:n, :s, 0, :i, :t, :p, 0, 0, NULL, NULL, NULL, NULL)')->execute(['n' => 'Black Weekends',
    's' => 'test-preturi-black-weekends', 'i' => date('Y-m-d H:i:s', time() - 3600), 't' => date('Y-m-d H:i:s', time() + 86400), 'p' => $idPagina]);
$idCampanie = (int) $db->lastInsertId();
$produseCampanie = [$pA, $pB, $pC, $pR, $pT, $pE, $idBbd];
$puneCampania = static function (?string $tip, int $n = 0, int $m = 0, float $procent = 0.0, array $preturiFixe = []) use ($db, $idCampanie, $produseCampanie): void {
    $db->prepare('UPDATE campanii SET pornita = :on, oferta_tip = :t, oferta_n = :n, oferta_m = :m, oferta_procent = :p WHERE id = :id')->execute([
        'on' => $tip === null ? 0 : 1, 't' => $tip, 'n' => $n ?: null, 'm' => $m ?: null, 'p' => $procent ?: null, 'id' => $idCampanie]);
    $db->prepare('DELETE FROM campanii_produse WHERE campanie_id = :id')->execute(['id' => $idCampanie]);
    $ins = $db->prepare('INSERT INTO campanii_produse (campanie_id, product_id, pret_campanie) VALUES (:c, :p, :pret)');
    foreach ($produseCampanie as $idProdus) {
        $ins->execute(['c' => $idCampanie, 'p' => $idProdus, 'pret' => $preturiFixe[$idProdus] ?? null]);
    }
    ResponseCache::purgePageCache();
};

// ── Unelte: banii din pagini și din emailuri ────────────────────────────────────────────────────────────────────────
// Pagina: number_format(x, 2) („1,234.56 lei”); emailurile: „1.234,56 lei”.
$leiPagina = static fn (string $t): float => (float) str_replace(',', '', trim(html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8')));
$leiEmail = static function (string $t): float {
    $t = trim(html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8'));
    $t = (string) preg_replace('/[^0-9,.\-−]/u', '', $t);
    $minus = str_contains($t, '−') || str_starts_with($t, '-');
    $t = str_replace(['−', '-', '.'], '', $t);
    $v = (float) str_replace(',', '.', $t);
    return $minus ? -$v : $v;
};
$egal = static fn (float $a, float $b, float $toleranta = 0.005): bool => abs($a - $b) < $toleranta;
$mailuriNoi = static function (array $inainte) use ($dosarDev): array {
    $noi = [];
    $fisiere = array_values(array_diff(glob($dosarDev . '/mail/*') ?: [], $inainte));
    sort($fisiere);
    foreach ($fisiere as $fisier) {
        $brut = str_replace("\r\n", "\n", (string) file_get_contents($fisier));
        [$antete, $corp] = array_pad(explode("\n\n", $brut, 2), 2, '');
        preg_match('/^Subject: (.*)$/mi', $antete, $subiect);
        $s = trim($subiect[1] ?? '');
        if (preg_match_all('/=\?UTF-8\?B\?([^?]+)\?=/i', $s, $bucati)) {
            $s = implode('', array_map('base64_decode', $bucati[1]));
        }
        $noi[] = ['fisier' => $fisier, 'subiect' => $s, 'html' => $corp];
    }
    return $noi;
};
/** Liniile, rândul ofertei, tabelul totalurilor și „Total”-ul mare dintr-un email al comenzii. */
$citesteEmail = static function (string $html) use ($leiEmail): array {
    $linii = [];
    preg_match_all('#<span style="font-weight:700;">(.*?)</span><br><span style="color:\#5f7680;font-size:13px;">(.*?)</span></td><td[^>]*>(.*?)</td>#s', $html, $m, PREG_SET_ORDER);
    foreach ($m as $r) {
        $sub = html_entity_decode(strip_tags($r[2]), ENT_QUOTES, 'UTF-8');
        $cant = preg_match('/^(\d+) × ([0-9.,]+) lei/u', $sub, $mm) === 1 ? (int) $mm[1] : null;
        $pretUnitar = $cant !== null ? $leiEmail($mm[2]) : null;
        $oferta = preg_match('/ofertă: −([0-9.,]+) lei/u', $sub, $mo) === 1 ? $leiEmail($mo[1]) : 0.0;
        $linii[] = ['nume' => html_entity_decode($r[1], ENT_QUOTES, 'UTF-8'), 'cantitate' => $cant, 'pret' => $pretUnitar, 'oferta' => $oferta,
            'total' => $leiEmail($r[3])];
    }
    $oferta = null;
    if (preg_match('#<!--ax-oferta-campaniei-->.*?<td[^>]*>(.*?)</td><td[^>]*>(.*?)</td>#s', $html, $mo) === 1) {
        $oferta = ['text' => trim(html_entity_decode(strip_tags($mo[1]), ENT_QUOTES, 'UTF-8')), 'suma' => -$leiEmail($mo[2])];
    }
    $totaluri = [];
    preg_match_all('#<td style="padding:9px 0;[^"]*">([^<]*)</td><td style="padding:9px 0 9px 14px;[^"]*">([^<]*)</td>#u', $html, $mt, PREG_SET_ORDER);
    foreach ($mt as $r) {
        $totaluri[html_entity_decode($r[1], ENT_QUOTES, 'UTF-8')] = html_entity_decode($r[2], ENT_QUOTES, 'UTF-8');
    }
    $mare = preg_match('#font-size:36px;[^>]*>([^<]*lei)</td>#', $html, $mm2) === 1 ? $leiEmail($mm2[1]) : null;
    $text = preg_match('#data-test-rezumat>(.*?)</div>#s', $html, $mr) === 1 ? html_entity_decode(strip_tags(str_replace('<br />', "\n", $mr[1])), ENT_QUOTES, 'UTF-8') : '';
    return ['linii' => $linii, 'oferta' => $oferta, 'totaluri' => $totaluri, 'total_mare' => $mare, 'rezumat' => $text];
};
/**
 * Emailul unei comenzi față de rândul ei din bază: fiecare linie (totalul ei; „cantitate × preț − ofertă” = totalul), rândul
 * ofertei, totalul mare, tabelul totalurilor (Produse − Reducere − Puncte − Reducere comercială + Transport = Total) și
 * textul {{order_summary}}. Întoarce lista nepotrivirilor.
 */
$compara = static function (array $e, array $comanda, array $linii) use ($leiEmail, $egal): array {
    $rau = [];
    if (count($e['linii']) !== count($linii)) {
        $rau[] = 'linii ' . count($e['linii']) . ' în email, ' . count($linii) . ' în comandă';
    }
    foreach (array_values($linii) as $i => $l) {
        $el = $e['linii'][$i] ?? null;
        if ($el === null) {
            continue;
        }
        if (!$egal($el['total'], (float) $l['line_total'])) {
            $rau[] = 'linia ' . ($i + 1) . ': ' . $el['total'] . ' în email, ' . $l['line_total'] . ' în comandă';
        }
        if ($el['cantitate'] !== null && $el['cantitate'] !== (int) $l['quantity']) {
            $rau[] = 'linia ' . ($i + 1) . ': cantitatea ' . $el['cantitate'] . ' în email, ' . $l['quantity'] . ' în comandă';
        }
        if ($el['cantitate'] !== null && !$egal(round($el['cantitate'] * (float) $el['pret'] - $el['oferta'], 2), (float) $l['line_total'], 0.011)) {
            $rau[] = 'linia ' . ($i + 1) . ': „' . $el['cantitate'] . ' × ' . $el['pret'] . ($el['oferta'] > 0 ? ' − ' . $el['oferta'] : '') . '” ≠ '
                . $l['line_total'];
        }
        if (!$egal($el['oferta'], (float) ($l['campanie_reducere'] ?? 0))) {
            $rau[] = 'linia ' . ($i + 1) . ': oferta ' . $el['oferta'] . ' în email, ' . (float) ($l['campanie_reducere'] ?? 0) . ' în comandă';
        }
    }
    $ofertaComanda = (float) ($comanda['campanie_reducere'] ?? 0);
    if ($ofertaComanda > 0 ? ($e['oferta'] === null || !$egal($e['oferta']['suma'], $ofertaComanda)) : $e['oferta'] !== null) {
        $rau[] = 'rândul ofertei: ' . json_encode($e['oferta'], JSON_UNESCAPED_UNICODE) . ', în comandă ' . $ofertaComanda;
    }
    $sumaLinii = array_sum(array_column($e['linii'], 'total'));
    if (!$egal($sumaLinii, (float) $comanda['subtotal'], 0.011)) {
        $rau[] = 'suma liniilor din email ' . round($sumaLinii, 2) . ' ≠ produsele comenzii ' . $comanda['subtotal'];
    }
    if ($e['total_mare'] !== null && !$egal($e['total_mare'], (float) $comanda['total'])) {
        $rau[] = 'totalul ' . $e['total_mare'] . ' în email, ' . $comanda['total'] . ' în comandă';
    }
    if ($e['totaluri'] !== []) {
        $t = $e['totaluri'];
        $val = static fn (string $k): float => isset($t[$k]) ? $leiEmail($t[$k]) : 0.0;
        $transport = isset($t['Transport']) && !in_array($t['Transport'], ['Gratuit', 'Ridicare de la sediu'], true) ? $val('Transport') : 0.0;
        $calculat = round($val('Produse') + $val('Reducere') + $val('Puncte de fidelitate') + $val('Reducere comercială') + $transport, 2);
        if (!$egal($val('Total'), (float) $comanda['total'])) {
            $rau[] = 'Total ' . $val('Total') . ' în tabelul emailului, ' . $comanda['total'] . ' în comandă';
        }
        if (!$egal($calculat, (float) $comanda['total'], 0.011)) {
            $rau[] = 'tabelul totalurilor nu se adună: ' . json_encode($t, JSON_UNESCAPED_UNICODE) . ' = ' . $calculat . ', totalul comenzii ' . $comanda['total'];
        }
        if (!$egal($val('Produse'), (float) $comanda['subtotal'])) {
            $rau[] = 'Produse ' . $val('Produse') . ' în email, subtotalul comenzii ' . $comanda['subtotal'];
        }
    }
    return $rau;
};

// ── Unelte: coșul, checkout-ul, comanda ─────────────────────────────────────────────────────────────────────────────
$sumarCos = static fn (string $j): array => (array) json_decode($cerere('GET', '/api/cart/summary', $j, [], ['Accept: application/json'])['corp'], true);
$adauga = static function (string $j, int $id, int $cant, string $lot = '') use ($cerere): void {
    $cerere('POST', '/cos/adauga/' . $id, $j, ['quantity' => (string) $cant] + ($lot !== '' ? ['bbd_key' => $lot] : []));
};
$logareClient = static function (string $j) use ($cerere, $emailClient, $parola): bool {
    $r = $cerere('POST', '/login', $j, ['email' => $emailClient, 'password' => $parola]);
    return $r['cod'] === 302 && str_contains((string) ($r['antete']['location'] ?? ''), '/contul-meu');
};
$campuriFacturare = static fn (bool $client): array => [
    'billing_first_name' => 'Test', 'billing_last_name' => 'Preturi', 'billing_phone' => '0700000782',
    'billing_email' => $client ? $emailClient : 'test-preturi@exemplu.ro', 'billing_street' => 'Strada Exemplului', 'billing_street_no' => '12',
    'billing_city' => 'Cluj-Napoca', 'billing_county' => 'Cluj', 'billing_postcode' => '400001',
    'shipping_same_as_billing' => '1', 'accept_terms' => '1',
];

// ── Adminul ──────────────────────────────────────────────────────────────────────────────────────────────────────────
$jarAdmin = $jar('admin');
$r = $cerere('POST', '/admin/login', $jarAdmin, ['email' => $adminEmail, 'password' => $parola]);
if ($r['cod'] !== 302) {
    $scrie('EROARE: administratorul nu se poate loga (' . $r['cod'] . ')');
    exit(1);
}
$jeton = static fn (string $html): string => preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
/** Datele ferestrei comenzii din Admin → Comenzi (atributul data-order) și ce arată ea pe linii (aceeași regulă ca scriptul). */
$fereastraAdmin = static function (string $numar) use ($cerere, $jarAdmin): array {
    $pagina = $cerere('GET', '/admin/orders?search=' . rawurlencode($numar), $jarAdmin)['corp'];
    $date = [];
    if (preg_match_all('/data-order="([^"]+)"/', $pagina, $m)) {
        foreach ($m[1] as $atribut) {
            $o = json_decode(html_entity_decode($atribut, ENT_QUOTES, 'UTF-8'), true);
            if (is_array($o) && (string) ($o['order_number'] ?? '') === $numar) {
                $date = $o;
                break;
            }
        }
    }
    // Reflectă renderOrderDetails din views/admin/orders.php: suma afișată pe fiecare linie.
    $script = (string) (preg_match('#const itemsHtml = items\.map\(\(it\) => \{(.*?)\}\)\.join#s', $pagina, $ms) === 1 ? $ms[1] : '');
    $cuTotalLinie = str_contains($script, 'cuOfertaCampanie || lineTotal > 0 ? lineTotal');
    $blocata = in_array(strtolower((string) ($date['status'] ?? '')), ['processing', 'completed', 'cancelled', 'refunded'], true)
        && trim((string) ($date['edit_unlocked_at'] ?? '')) === '';
    $cuOferta = (float) ($date['campanie_reducere'] ?? 0) > 0.004;
    $afisate = [];
    foreach ((array) ($date['items'] ?? []) as $it) {
        $cant = max(1, (int) ($it['quantity'] ?? 1));
        $totalLinie = (float) ($it['line_total'] ?? 0);
        $unitar = (float) ($it['unit_price'] ?? 0);
        $afisate[] = round(($blocata || $cuOferta) && ($cuOferta || $cuTotalLinie) ? $totalLinie : $unitar * $cant, 2);
    }
    return ['date' => $date, 'afisate' => $afisate, 'csrf' => preg_match('/name="_csrf" value="([^"]+)"/', $pagina, $mc) === 1 ? $mc[1] : '',
        'pagina' => $pagina];
};

// ── Cazurile ─────────────────────────────────────────────────────────────────────────────────────────────────────────
$lotCheie = (string) ($lotBbd['key'] ?? '');
$cazuri = [
    // Cazul clientei: două produse din oferta „1+1 −50%”, plata la livrare (al doilea, cel mai ieftin, la jumătate: 80 → 40).
    ['nume' => 'clienta: 1+1 −50% pe A 100 și B 80, ramburs', 'oferta' => ['al_n', 2, 0, 50], 'linii' => [[$pA, 1], [$pB, 1]],
        'asteptat' => ['subtotal' => 140.00, 'campanie' => 40.00, 'transport' => 19.00, 'total' => 159.00], 'erp' => true],
    ['nume' => 'clienta + 150 de puncte', 'oferta' => ['al_n', 2, 0, 50], 'linii' => [[$pA, 1], [$pB, 1]], 'client' => true, 'puncte' => 150,
        'asteptat' => ['subtotal' => 140.00, 'campanie' => 40.00, 'puncte' => 15.00, 'transport' => 19.00, 'total' => 144.00]],
    ['nume' => 'clienta + cuponul DEMO10 (doar pe D, din afara ofertei)', 'oferta' => ['al_n', 2, 0, 50], 'linii' => [[$pA, 1], [$pB, 1], [$pD, 1]],
        'cupon' => 'DEMO10', 'asteptat' => ['subtotal' => 200.00, 'campanie' => 40.00, 'reducere' => 6.00, 'transport' => 19.00, 'total' => 213.00]],
    ['nume' => 'clienta + cupon + puncte, peste pragul transportului gratuit', 'oferta' => ['al_n', 2, 0, 50],
        'linii' => [[$pA, 2], [$pB, 1], [$pD, 1]], 'cupon' => 'DEMO10', 'client' => true, 'puncte' => 200, 'erp' => true,
        'asteptat' => ['subtotal' => 290.00, 'campanie' => 50.00, 'reducere' => 6.00, 'puncte' => 20.00, 'transport' => 0.0, 'total' => 264.00]],
    ['nume' => '1+1 −50%, A × 3 și C 45,99 (media la cent, rotunjirea în sus)', 'oferta' => ['al_n', 2, 0, 50], 'linii' => [[$pA, 3], [$pC, 1]],
        'asteptat' => ['subtotal' => 272.99, 'campanie' => 73.00, 'transport' => 0.0, 'total' => 272.99], 'erp' => true],
    ['nume' => '1+1 −50% pe același produs, A × 2 (o singură linie)', 'oferta' => ['al_n', 2, 0, 50], 'linii' => [[$pA, 2]],
        'asteptat' => ['subtotal' => 150.00, 'campanie' => 50.00, 'transport' => 19.00, 'total' => 169.00]],
    ['nume' => '3 la preț de 2 pe A, B, C (C gratuit)', 'oferta' => ['n_la_m', 3, 2, 0], 'linii' => [[$pA, 1], [$pB, 1], [$pC, 1]],
        'asteptat' => ['subtotal' => 180.00, 'campanie' => 45.99, 'transport' => 19.00, 'total' => 199.00]],
    ['nume' => '−20% peste prețul redus (R 40), peste perioada în curs (T 24) și E 33,33 × 3', 'oferta' => ['procent', 0, 0, 20],
        'linii' => [[$pR, 1], [$pT, 1], [$pE, 3]], 'asteptat' => ['subtotal' => 131.19, 'campanie' => 32.80, 'transport' => 19.00, 'total' => 150.19]],
    ['nume' => '1+1 −50% cu lotul BBD ales și A, cuponul TSTMIN3 (minim 3 bucăți) pe D', 'oferta' => ['al_n', 2, 0, 50],
        'linii' => [[$idBbd, 1, $lotCheie], [$pA, 1], [$pD, 1]], 'cupon' => 'TSTMIN3'],
    ['nume' => 'preț de campanie: A 85 (aplicat), B 90 (peste prețul lui: linia nu e a ofertei, primește DEMO10)', 'oferta' => ['pret_fix', 0, 0, 0],
        'fixe' => [$pA => 85.00, $pB => 90.00], 'linii' => [[$pA, 2], [$pB, 1]], 'cupon' => 'DEMO10',
        'asteptat' => ['subtotal' => 250.00, 'campanie' => 30.00, 'reducere' => 8.00, 'transport' => 19.00, 'total' => 261.00]],
    ['nume' => 'fără campanie: R redus × 2, D, cuponul fără cumulare (doar pe D), puncte', 'oferta' => null, 'linii' => [[$pR, 2], [$pD, 1]],
        'cupon' => 'TSTFARACUMUL', 'client' => true, 'puncte' => 100,
        'asteptat' => ['subtotal' => 140.00, 'reducere' => 6.00, 'puncte' => 10.00, 'transport' => 19.00, 'total' => 143.00]],
    ['nume' => 'fără campanie: cuponul TSTALESE (fix 10, doar pe D, cu alte produse în coș)', 'oferta' => null, 'linii' => [[$pD, 1], [$pC, 2]],
        'cupon' => 'TSTALESE', 'erp' => 'pret', 'asteptat' => ['subtotal' => 151.98, 'reducere' => 10.00, 'transport' => 19.00, 'total' => 160.98]],
    ['nume' => '1+1 −50% cu un cod unic (UNIC-DEMO-0002, fix 20) pe D', 'oferta' => ['al_n', 2, 0, 50], 'linii' => [[$pA, 1], [$pB, 1], [$pD, 1]],
        'cupon' => 'UNIC-DEMO-0002', 'asteptat' => ['subtotal' => 200.00, 'campanie' => 40.00, 'reducere' => 20.00, 'transport' => 19.00, 'total' => 199.00]],
    ['nume' => '1+1 −50% și produsul cu transport gratuit (G)', 'oferta' => ['al_n', 2, 0, 50], 'linii' => [[$pA, 1], [$pB, 1], [$pG, 1]],
        'asteptat' => ['subtotal' => 210.00, 'campanie' => 40.00, 'transport' => 0.0, 'total' => 210.00], 'gratuit' => true],
    ['nume' => '1+1 −50% la FANbox', 'oferta' => ['al_n', 2, 0, 50], 'linii' => [[$pA, 1], [$pB, 1]], 'fanbox' => true,
        'asteptat' => ['subtotal' => 140.00, 'campanie' => 40.00, 'transport' => 19.00, 'total' => 159.00]],
    ['nume' => '1+1 −50% cu ridicarea de la sediu', 'oferta' => ['al_n', 2, 0, 50], 'linii' => [[$pA, 1], [$pB, 1]], 'ridicare' => true,
        // Pagina se deschide cu livrarea prin curier (bifa ridicării o schimbă scriptul paginii); comanda: fără transport.
        'asteptat' => ['subtotal' => 140.00, 'campanie' => 40.00, 'transport' => 19.00, 'total' => 159.00]],
    ['nume' => '1+1 −50% cu un produs în precomandă', 'oferta' => ['al_n', 2, 0, 50], 'linii' => [[$pA, 1], [$pB, 1], [$idPrecomanda, 1]]],
    ['nume' => 'clienta, plata cu cardul prin EuPlătesc', 'oferta' => ['al_n', 2, 0, 50], 'linii' => [[$pA, 1], [$pB, 1]], 'plata' => 'euplatesc',
        'asteptat' => ['subtotal' => 140.00, 'campanie' => 40.00, 'transport' => 19.00, 'total' => 159.00]],
];
if (!$cuGratuit) {
    $cazuri = array_values(array_filter($cazuri, static fn (array $c): bool => empty($c['gratuit'])));
}

$comenziPlasate = [];
$tabel = [];
$notaSuprafata = static function (string $caz, string $suprafata, bool $ok) use (&$tabel): void {
    $tabel[$caz][$suprafata] = ($tabel[$caz][$suprafata] ?? true) && $ok;
};
$scrie('');
$scrie('== A. Combinațiile: coșul, checkout-ul, comanda, emailul, contul, adminul, ERP-ul, plata');
foreach ($cazuri as $nr => $caz) {
    $et = '#' . ($nr + 1) . ' ' . $caz['nume'];
    $scrie('');
    $scrie('-- ' . $et);
    $oferta = $caz['oferta'];
    $oferta === null ? $puneCampania(null) : $puneCampania($oferta[0], $oferta[1], $oferta[2], (float) $oferta[3], $caz['fixe'] ?? []);
    OfertaCampanie::reseteaza();
    $db->exec('DELETE FROM checkout_submit_logs');
    $j = $jar('caz' . $nr);
    $client = !empty($caz['client']);
    if ($client && !$logareClient($j)) {
        $verifica($et . ': logarea clientului', false);
        continue;
    }
    foreach ($caz['linii'] as $linie) {
        $adauga($j, (int) $linie[0], (int) $linie[1], (string) ($linie[2] ?? ''));
    }
    $cerere('POST', '/cos/judet', $j, ['county' => 'Cluj']);
    if (!empty($caz['cupon'])) {
        $cerere('POST', '/cos/cupon', $j, ['coupon_code' => $caz['cupon']]);
    }
    if (!empty($caz['puncte'])) {
        $cerere('POST', '/cos/puncte', $j, ['points' => (string) $caz['puncte']]);
    }
    $s = $sumarCos($j);
    $cos = $cerere('GET', '/cos', $j)['corp'];
    $paginaCheckout = $cerere('GET', '/checkout', $j)['corp'];

    // Sumarul (coșul plutitor) și pagina coșului: totalul, rândul ofertei, fiecare linie.
    $totalSumar = (float) ($s['total'] ?? -1);
    $ofertaSumar = (float) ($s['campanie_oferta']['reducere'] ?? 0);
    $liniiSumar = (array) ($s['lines'] ?? []);
    $calculSumar = round((float) $s['subtotal'] - (float) $s['discount'] - (float) $s['points_discount'] + (float) $s['shipping'], 2);
    $okSumar = $egal($totalSumar, $calculSumar) && $egal(array_sum(array_column($liniiSumar, 'line_total')), (float) $s['subtotal'], 0.011)
        && $egal(array_sum(array_map(static fn (array $l): float => (float) ($l['campanie_reducere'] ?? 0), $liniiSumar)), $ofertaSumar)
        && (($caz['cupon'] ?? '') === '' ? ($s['coupon'] ?? null) === null : strtoupper((string) $caz['cupon']) === (string) ($s['coupon']['code'] ?? ''));
    $asteptat = (array) ($caz['asteptat'] ?? []);
    if ($asteptat !== []) {
        $okSumar = $okSumar && $egal((float) $s['subtotal'], $asteptat['subtotal']) && $egal($ofertaSumar, (float) ($asteptat['campanie'] ?? 0))
            && $egal((float) $s['discount'], (float) ($asteptat['reducere'] ?? 0)) && $egal((float) $s['points_discount'], (float) ($asteptat['puncte'] ?? 0))
            && $egal((float) $s['shipping'], $asteptat['transport']) && $egal($totalSumar, $asteptat['total']);
    }
    $verifica($et . ': sumarul coșului (coșul plutitor)' . ($asteptat !== [] ? ' = calculul așteptat (' . json_encode($asteptat) . ')' : ''),
        $okSumar, json_encode(array_intersect_key($s, array_flip(['subtotal', 'discount', 'points_discount', 'shipping', 'total', 'coupon_error', 'campanie_oferta'])), JSON_UNESCAPED_UNICODE)
        . ' puncte ' . json_encode($s['points'] ?? null));
    $notaSuprafata($et, 'coș plutitor', $okSumar);
    // Pragul transportului gratuit din coșul plutitor = cel al coșului și al checkout-ului (aceeași referință).
    $ramasPlutitor = (float) ($s['shipping_remaining_for_free'] ?? 0);
    $verifica($et . ': coșul plutitor spune „transport gratuit” exact când coșul îl dă', ($ramasPlutitor <= 0.009) === ((float) $s['shipping'] <= 0.009)
        || !empty($caz['ridicare']), 'rămas ' . $ramasPlutitor . ', transport ' . $s['shipping']);
    preg_match('#<div class="bv-cart-v2__total">\s*<span>Total</span>\s*<strong>([^<]*)</strong>#', $cos, $mTotalCos);
    preg_match('#data-campanie-sumar><span>.*?</span><strong>-([^<]*)</strong>#s', $cos, $mOfertaCos);
    preg_match_all('#<div class="bv-cart-v2__price">([^<]*)</div>#', $cos, $mLiniiCos);
    $liniiAsteptateCos = array_map(static fn (array $l): float => round((float) ($l['coupon_discount'] ?? 0) > 0 ? (float) $l['line_total_after_coupon'] : (float) $l['line_total'], 2), $liniiSumar);
    $okCos = isset($mTotalCos[1]) && $egal($leiPagina($mTotalCos[1]), $totalSumar)
        && ($ofertaSumar > 0 ? isset($mOfertaCos[1]) && $egal($leiPagina($mOfertaCos[1]), $ofertaSumar) : !isset($mOfertaCos[1]))
        && array_map($leiPagina, $mLiniiCos[1]) == $liniiAsteptateCos;
    $verifica($et . ': pagina coșului (totalul, rândul ofertei, liniile)', $okCos, 'total ' . ($mTotalCos[1] ?? '?') . ', oferta ' . ($mOfertaCos[1] ?? '-')
        . ', linii ' . json_encode($mLiniiCos[1]) . ' față de ' . json_encode($liniiAsteptateCos));
    $notaSuprafata($et, 'pagina coșului', $okCos);
    // Checkout: totalul, transportul, rândul ofertei.
    preg_match('#data-checkout-total>([^<]*)</strong>#', $paginaCheckout, $mTotalCh);
    preg_match('#data-checkout-shipping>([^<]*)</strong>#', $paginaCheckout, $mTranspCh);
    preg_match('#bv-checkout-v3__row--danger" data-campanie-sumar><span>.*?</span><strong>-([^<]*)</strong>#s', $paginaCheckout, $mOfertaCh);
    $okCheckout = isset($mTotalCh[1]) && $egal($leiPagina($mTotalCh[1]), $totalSumar) && isset($mTranspCh[1])
        && $egal($leiPagina($mTranspCh[1]), (float) $s['shipping'])
        && ($ofertaSumar > 0 ? isset($mOfertaCh[1]) && $egal($leiPagina($mOfertaCh[1]), $ofertaSumar) : !isset($mOfertaCh[1]));
    $verifica($et . ': pagina checkout-ului (totalul, transportul, rândul ofertei)', $okCheckout, 'total ' . ($mTotalCh[1] ?? '?') . ', transport '
        . ($mTranspCh[1] ?? '?') . ', oferta ' . ($mOfertaCh[1] ?? '-'));
    $notaSuprafata($et, 'checkout', $okCheckout);

    // Comanda.
    preg_match('/name="checkout_form_token" value="([^"]*)"/', $paginaCheckout, $tok);
    preg_match('/name="checkout_form_rendered_at" value="([^"]*)"/', $paginaCheckout, $rnd);
    sleep(3);
    $ultima = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM orders')->fetchColumn();
    $mailuriInainte = glob($dosarDev . '/mail/*') ?: [];
    $plata = (string) ($caz['plata'] ?? 'cod');
    $campuri = ['checkout_form_token' => $tok[1] ?? '', 'checkout_form_rendered_at' => $rnd[1] ?? '', 'payment_method' => $plata] + $campuriFacturare($client);
    if (!empty($caz['fanbox'])) {
        $campuri += ['livrare_fanbox' => '1', 'fan_locker_id' => $unu('SELECT MIN(id) FROM fan_lockers')];
    }
    if (!empty($caz['ridicare'])) {
        $campuri['ridicare_personala'] = '1';
    }
    $trimis = $cerere('POST', '/checkout', $j, $campuri);
    $comanda = $db->query('SELECT * FROM orders WHERE id > ' . $ultima . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($comanda)) {
        $verifica($et . ': comanda plasată', false, $trimis['cod'] . ' ' . ($trimis['antete']['location'] ?? '') . ' ' . mb_substr(strip_tags($trimis['corp']), 0, 300));
        continue;
    }
    $idComanda = (int) $comanda['id'];
    $numar = (string) $comanda['order_number'];
    $linii = $db->query('SELECT * FROM order_items WHERE order_id = ' . $idComanda . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $comenziPlasate[] = ['caz' => $caz, 'et' => $et, 'id' => $idComanda, 'numar' => $numar];
    $sumaLinii = array_sum(array_map(static fn (array $l): float => (float) $l['line_total'], $linii));
    $sumaOferta = array_sum(array_map(static fn (array $l): float => (float) ($l['campanie_reducere'] ?? 0), $linii));
    $liniiRele = [];
    foreach ($linii as $l) {
        if ((float) ($l['campanie_reducere'] ?? 0) > 0
            && !$egal(round((int) $l['quantity'] * (float) $l['campanie_pret_initial'] - (float) $l['campanie_reducere'], 2), (float) $l['line_total'])) {
            $liniiRele[] = $l['product_name'];
        }
        if (!$egal(round((float) $l['unit_price'] * (int) $l['quantity'], 2), (float) $l['line_total'], 0.011 * max(1, (int) $l['quantity']))) {
            $liniiRele[] = $l['product_name'] . ' (preț × cantitate)';
        }
    }
    // Cu ridicarea de la sediu: totalul sumarului fără transport (ca după bifa din pagină).
    $transportComanda = !empty($caz['ridicare']) ? 0.0 : (float) $s['shipping'];
    $okComanda = $egal((float) $comanda['total'], round($totalSumar - (float) $s['shipping'] + $transportComanda, 2))
        && $egal((float) $comanda['subtotal'], (float) $s['subtotal'])
        && $egal((float) $comanda['discount_total'], (float) $s['discount']) && $egal((float) $comanda['loyalty_points_discount'], (float) $s['points_discount'])
        && $egal((float) $comanda['shipping_cost'], $transportComanda) && $egal($sumaLinii, (float) $comanda['subtotal'], 0.011)
        && $egal($sumaOferta, (float) ($comanda['campanie_reducere'] ?? 0)) && $egal($sumaOferta, $ofertaSumar)
        && $egal((float) $comanda['total'], round((float) $comanda['subtotal'] - (float) $comanda['discount_total'] - (float) $comanda['loyalty_points_discount']
            + (float) $comanda['shipping_cost'], 2)) && $liniiRele === [];
    $verifica($et . ': comanda ' . $numar . ' în bază = sumarul de la checkout (total ' . $comanda['total'] . ')', $okComanda,
        json_encode(array_intersect_key($comanda, array_flip(['subtotal', 'discount_total', 'loyalty_points_discount', 'shipping_cost', 'total', 'campanie_reducere', 'coupon_code'])))
        . ' linii ' . $sumaLinii . ' oferta pe linii ' . $sumaOferta . ' ' . implode(', ', $liniiRele));
    $notaSuprafata($et, 'comanda (DB)', $okComanda);

    // Plata: suma trimisă procesatorului (EuPlătesc: câmpul `amount` al formularului) = totalul comenzii.
    if ($plata === 'euplatesc') {
        preg_match('/name="amount" value="([^"]*)"/', $trimis['corp'], $mSuma);
        $okPlata = isset($mSuma[1]) && $egal((float) $mSuma[1], (float) $comanda['total']);
        $verifica($et . ': suma trimisă la EuPlătesc = totalul comenzii', $okPlata, ($mSuma[1] ?? '?') . ' / ' . $comanda['total']);
        $notaSuprafata($et, 'plata (EuPlătesc)', $okPlata);
        // Plătită (ca după notificarea EuPlătesc): emailul „Comandă nouă” pleacă acum.
        $db->prepare("UPDATE orders SET payment_status = 'paid', paid_amount = total, status = 'pending' WHERE id = :id")->execute(['id' => $idComanda]);
        \App\Support\EmailAutomation::sendOrderTemplateById($db, \App\Support\Settings::all($db), $idComanda, 'new_order');
        $comanda = $db->query('SELECT * FROM orders WHERE id = ' . $idComanda)->fetch(PDO::FETCH_ASSOC);
    }

    // Emailul „Comandă nouă”.
    $mailuri = array_values(array_filter($mailuriNoi($mailuriInainte), static fn (array $m): bool => str_contains($m['html'], $numar)));
    $e = $mailuri !== [] ? $citesteEmail($mailuri[0]['html']) : null;
    $rau = $e !== null ? $compara($e, $comanda, $linii) : ['niciun email'];
    $verifica($et . ': emailul „Comandă nouă” = comanda (liniile, oferta, totalul)', $rau === [], implode('; ', $rau));
    $notaSuprafata($et, 'email comandă nouă', $rau === []);

    // ERP: payload-ul (liniile cu prețul plătit, transportul, reducerile, totalul).
    $payload = \App\Support\ErpSync::buildPayload($db, $comanda);
    $sumaErp = 0.0;
    foreach ((array) ($payload['linii'] ?? []) as $le) {
        $sumaErp += round((float) $le['pretUnitar'] * (float) $le['cantitate'], 2);
    }
    $okErp = $egal($sumaErp, (float) $comanda['subtotal'], 0.011) && $egal((float) $payload['total'], (float) $comanda['total'])
        && $egal((float) $payload['transport'], (float) $comanda['shipping_cost']) && $egal((float) $payload['discountCupon'], (float) $comanda['discount_total'])
        && $egal((float) $payload['discountPuncte'], (float) $comanda['loyalty_points_discount'])
        && $egal(round($sumaErp + (float) $payload['transport'] - (float) $payload['discountCupon'] - (float) $payload['discountPuncte'] - (float) $payload['discountManual'], 2),
            (float) $payload['total'], 0.011);
    $verifica($et . ': payload-ul ERP (liniile, transportul, cuponul, punctele, totalul) = comanda', $okErp, 'linii ' . $sumaErp . ' '
        . json_encode(array_intersect_key($payload, array_flip(['transport', 'discountCupon', 'discountPuncte', 'total']))));
    $notaSuprafata($et, 'ERP', $okErp);

    // Contul clientului și pagina de confirmare.
    if ($client) {
        $cont = $cerere('GET', '/contul-meu?section=orders', $j)['corp'];
        // Tabelul („<td>”) sau lista din tema contului („account-section-v2__order-item”).
        $okCont = (preg_match('#<td>' . preg_quote($numar, '#') . '</td>.*?<td>([0-9.,]+) lei</td>#s', $cont, $mCont) === 1
            || preg_match('#<strong>' . preg_quote($numar, '#') . '</strong>.*?<strong>([0-9.,]+) lei</strong>#s', $cont, $mCont) === 1)
            && $egal($leiPagina($mCont[1]), (float) $comanda['total']);
        $verifica($et . ': contul clientului („Comenzile mele”) = totalul comenzii', $okCont, $mCont[1] ?? '?');
        $notaSuprafata($et, 'contul clientului', $okCont);
    }
    $succes = $cerere('GET', '/checkout/succes/' . rawurlencode($numar), $j)['corp'];
    $okSucces = !str_contains($succes, (string) $numar) || str_contains($succes, number_format((float) $comanda['total'], 2, ',', '.'));
    $verifica($et . ': pagina de confirmare a comenzii (totalul)', $okSucces);

    // Admin → Comenzi: totalul și ce arată fereastra pe fiecare linie.
    $f = $fereastraAdmin($numar);
    $okAdmin = $egal((float) ($f['date']['total'] ?? -1), (float) $comanda['total'])
        && $f['afisate'] == array_map(static fn (array $l): float => round((float) $l['line_total'], 2), $linii)
        && $egal((float) ($f['date']['campanie_reducere'] ?? 0), (float) ($comanda['campanie_reducere'] ?? 0));
    $verifica($et . ': Admin → Comenzi (totalul, liniile afișate, oferta)', $okAdmin, json_encode($f['afisate']) . ' total ' . ($f['date']['total'] ?? '?'));
    $notaSuprafata($et, 'admin', $okAdmin);
}

// ── B. Expedierea: emailul „Comandă expediată” din Admin și din ERP ──────────────────────────────────────────────────
$scrie('');
$scrie('== B. Expedierea: emailul de expediere (Admin și ERP), cu șablonul implicit și cu totalurile');
$eveniment = static function (array $date) use ($cerere): array {
    $r = $cerere('POST', '/api/erp/notificare', null, json_encode($date, JSON_UNESCAPED_UNICODE),
        ['Content-Type: application/json', 'X-Andaxi-Site-Key: cheie-erp-test-preturi', 'Accept: application/json']);
    return ['cod' => $r['cod'], 'json' => (array) json_decode($r['corp'], true)];
};
/** Comanda văzută de ERP, ca în ANDAXI-ERP (comenzi-site.service.ts): prețul fără TVA la 4 zecimale, apoi înapoi cu TVA. */
$caInErp = static function (array $payload, array $schimbari = [], array $neturi = []): array {
    $linii = [];
    $produse = '0';
    foreach ((array) $payload['linii'] as $i => $l) {
        $cota = (string) $l['cotaTva'];
        $cant = (string) ($schimbari[$i] ?? $l['cantitate']);
        $net = !empty($l['tvaInclus']) ? round((float) $l['pretUnitar'] / (1 + (float) $cota / 100), 4) : (float) $l['pretUnitar'];
        // Un preț fără TVA scris de operator în ERP (de exemplu 578,51: cu TVA 699,9971, deci 700,00 pe bucată și 2.799,99 pe 4).
        $net = isset($neturi[$i]) ? (float) $neturi[$i] : $net;
        $brut = $net * (1 + (float) $cota / 100);
        $valoare = round($brut * (float) $cant, 2);
        $produse = (string) ((float) $produse + $brut * (float) $cant);
        $linii[] = ['sku' => $l['sku'], 'denumire' => $l['denumire'], 'cantitate' => $cant, 'pretUnitar' => (string) $net,
            'pretUnitarCuTva' => (string) round($brut, 2), 'valoareCuTva' => (string) $valoare, 'cotaTva' => $cota, 'reducere' => '0',
            'pretUnitarCuTvaInitial' => null];
    }
    $total = round((float) $produse + (float) $payload['transport'] - (float) $payload['discountCupon'] - (float) $payload['discountPuncte']
        - (float) $payload['discountManual'], 2);
    return ['eveniment' => 'comanda_modificata', 'numarSite' => $payload['numarSite'], 'motiv' => 'Test', 'total' => (string) $total,
        'transport' => (string) $payload['transport'], 'linii' => $linii];
};
$expediereEmail = static function (int $idComanda, string $numar, array $mailuriInainte) use ($mailuriNoi, $citesteEmail): ?array {
    $mailuri = array_values(array_filter($mailuriNoi($mailuriInainte), static fn (array $m): bool => str_contains($m['html'], $numar)
        && str_contains($m['html'], 'Urm')));
    return $mailuri !== [] ? $citesteEmail($mailuri[count($mailuri) - 1]['html']) : null;
};
$sablonTotaluri = '<p>Comanda {{order_number}} a plecat. AWB {{awb}}</p>{{order_items_html}}{{order_totals_html}}<p>Total: <span>{{order_total}}</span></p>'
    . '<div data-test-rezumat>{{order_summary}}</div><a href="{{tracking_url}}">Urmărește coletul</a>';
$recitesteComanda = static function (int $id) use ($db): array {
    return [
        (array) $db->query('SELECT * FROM orders WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC),
        $db->query('SELECT * FROM order_items WHERE order_id = ' . $id . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [],
    ];
};
$verificaRezumat = static function (array $e, array $linii) use ($leiEmail, $egal): array {
    $rau = [];
    $randuri = array_values(array_filter(array_map('trim', explode("\n", $e['rezumat']))));
    foreach (array_values($linii) as $i => $l) {
        $r = $randuri[$i] ?? '';
        if (preg_match('/ x(\d+) - ([0-9.,]+ lei)/u', $r, $m) !== 1 || (int) $m[1] !== (int) $l['quantity'] || !$egal($leiEmail($m[2]), (float) $l['line_total'])) {
            $rau[] = '{{order_summary}} linia ' . ($i + 1) . ': „' . $r . '”';
        }
    }
    return $rau;
};
$erpFacut = 0;
foreach ($comenziPlasate as $k => $plasata) {
    $et = $plasata['et'];
    $idComanda = $plasata['id'];
    $numar = $plasata['numar'];
    // 1) Admin: starea „completed” trimite „Comandă expediată” (cu AWB-ul pe comandă), șablonul implicit.
    if ($k % 2 === 0 || !empty($plasata['caz']['erp'])) {
        $setare('email_template_shipped_body', null);
    } else {
        $setare('email_template_shipped_body', $sablonTotaluri);
    }
    $db->prepare("UPDATE orders SET fan_awb = :awb WHERE id = :id")->execute(['awb' => '7' . str_pad((string) $idComanda, 12, '0', STR_PAD_LEFT), 'id' => $idComanda]);
    if (!empty($plasata['caz']['ridicare'])) {
        continue; // Ridicarea de la sediu nu are email de expediere (portul 807058e).
    }
    if (!empty($plasata['caz']['erp'])) {
        // 2) ERP: o corectură care nu schimbă nimic (de exemplu adresa, „date comandă”): liniile se întorc prin prețul fără TVA.
        [$comanda, $linii] = $recitesteComanda($idComanda);
        $payload = \App\Support\ErpSync::buildPayload($db, $comanda);
        $r = $eveniment($caInErp($payload));
        [$comanda, $linii] = $recitesteComanda($idComanda);
        $sumaLinii = array_sum(array_map(static fn (array $l): float => (float) $l['line_total'], $linii));
        $sumaOferta = array_sum(array_map(static fn (array $l): float => (float) ($l['campanie_reducere'] ?? 0), $linii));
        $verifica($et . ': corectura din ERP fără schimbări: comanda rămâne consecventă (suma liniilor = subtotalul, oferta pe linii = a comenzii)',
            $r['cod'] === 200 && $egal($sumaLinii, (float) $comanda['subtotal'], 0.011) && $egal($sumaOferta, (float) ($comanda['campanie_reducere'] ?? 0))
            && $egal((float) $comanda['total'], round((float) $comanda['subtotal'] - (float) $comanda['discount_total'] - (float) $comanda['loyalty_points_discount']
                + (float) $comanda['shipping_cost'], 2), 0.011),
            $r['cod'] . ' ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE) . ' subtotal ' . $comanda['subtotal'] . ' linii ' . $sumaLinii . ' total ' . $comanda['total']
            . ' oferta ' . ($comanda['campanie_reducere'] ?? '-') . '/' . $sumaOferta);
        // 3) ERP: operatorul scade cu o bucată cantitatea primei linii cu mai multe bucăți (sau a primei linii a ofertei).
        $schimbari = [];
        foreach ((array) $payload['linii'] as $i => $l) {
            if ((int) $l['cantitate'] > 1) {
                $schimbari[$i] = (string) ((int) $l['cantitate'] - 1);
                break;
            }
        }
        if ($schimbari === []) {
            $schimbari[count((array) $payload['linii']) - 1] = '2';
        }
        $neturi = [];
        if ($plasata['caz']['erp'] === 'pret') {
            $schimbari = [0 => '4'];
            $neturi = [0 => 578.51];
        }
        $r = $eveniment($caInErp(\App\Support\ErpSync::buildPayload($db, $comanda), $schimbari, $neturi));
        [$comanda, $linii] = $recitesteComanda($idComanda);
        $sumaLinii = array_sum(array_map(static fn (array $l): float => (float) $l['line_total'], $linii));
        $sumaOferta = array_sum(array_map(static fn (array $l): float => (float) ($l['campanie_reducere'] ?? 0), $linii));
        $liniiRele = [];
        foreach ($linii as $l) {
            if ((float) ($l['campanie_reducere'] ?? 0) > 0
                && !$egal(round((int) $l['quantity'] * (float) $l['campanie_pret_initial'] - (float) $l['campanie_reducere'], 2), (float) $l['line_total'])) {
                $liniiRele[] = $l['product_name'] . ': ' . $l['quantity'] . ' × ' . $l['campanie_pret_initial'] . ' − ' . $l['campanie_reducere'] . ' ≠ ' . $l['line_total'];
            }
        }
        $verifica($et . ': cantitatea schimbată în ERP: subtotalul = liniile, oferta pe linii = a comenzii, fiecare linie a ofertei se adună',
            $r['cod'] === 200 && $egal($sumaLinii, (float) $comanda['subtotal'], 0.011) && $egal($sumaOferta, (float) ($comanda['campanie_reducere'] ?? 0))
            && $liniiRele === [], $r['cod'] . ' subtotal ' . $comanda['subtotal'] . ' linii ' . $sumaLinii . ' oferta ' . ($comanda['campanie_reducere'] ?? '-')
            . '/' . $sumaOferta . ' ' . implode('; ', $liniiRele));
        $notaSuprafata($et, 'corectura din ERP', $r['cod'] === 200 && $egal($sumaLinii, (float) $comanda['subtotal'], 0.011) && $liniiRele === []);
        // 4) Aprobarea din ERP, cu AWB-ul deja pe comandă: „Comandă expediată”, o dată cu șablonul implicit, o dată cu totalurile.
        foreach (['implicit' => null, 'cu totalurile' => $sablonTotaluri] as $numeSablon => $sablon) {
            $setare('email_template_shipped_body', $sablon);
            $db->prepare("DELETE FROM email_logs WHERE order_id = :id AND email_type = 'shipped'")->execute(['id' => $idComanda]);
            $db->prepare("UPDATE orders SET status = 'pending' WHERE id = :id")->execute(['id' => $idComanda]);
            $mailuriInainte = glob($dosarDev . '/mail/*') ?: [];
            $r = $eveniment(['eveniment' => 'comanda_aprobata', 'numarSite' => $numar, 'facturaNumar' => 'TST' . $idComanda]);
            [$comanda, $linii] = $recitesteComanda($idComanda);
            $e = $expediereEmail($idComanda, $numar, $mailuriInainte);
            $rau = $e !== null ? array_merge($compara($e, $comanda, $linii), $sablon !== null ? $verificaRezumat($e, $linii) : []) : ['niciun email (' . $r['cod'] . ' '
                . json_encode($r['json'], JSON_UNESCAPED_UNICODE) . ')'];
            $verifica($et . ': aprobarea din ERP → „Comandă expediată” (șablonul ' . $numeSablon . ') = comanda corectată', $rau === [], implode('; ', $rau));
            $notaSuprafata($et, 'email expediere (ERP)', $rau === []);
        }
        $f = $fereastraAdmin($numar);
        $okAdmin = $egal((float) ($f['date']['total'] ?? -1), (float) $comanda['total'])
            && $f['afisate'] == array_map(static fn (array $l): float => round((float) $l['line_total'], 2), $linii)
            && $egal((float) ($f['date']['campanie_reducere'] ?? 0), array_sum(array_map(static fn (array $l): float => (float) ($l['campanie_reducere'] ?? 0), $linii)));
        $verifica($et . ': Admin → Comenzi după corectura din ERP (totalul, liniile afișate, rândul ofertei)', $okAdmin, json_encode($f['afisate']) . ' față de '
            . json_encode(array_column($linii, 'line_total')) . ', oferta ' . ($f['date']['campanie_reducere'] ?? '-'));
        $notaSuprafata($et, 'admin', $okAdmin);
        $erpFacut++;
        continue;
    }
    $f = $fereastraAdmin($numar);
    $mailuriInainte = glob($dosarDev . '/mail/*') ?: [];
    $r = $cerere('POST', '/admin/orders/' . $idComanda . '/status', $jarAdmin, ['_csrf' => $f['csrf'], 'status' => 'completed',
        'back_url' => '/admin/orders']);
    [$comanda, $linii] = $recitesteComanda($idComanda);
    $e = $expediereEmail($idComanda, $numar, $mailuriInainte);
    $cuTotaluri = (string) $unu("SELECT `value` FROM settings WHERE `key` = 'email_template_shipped_body'") !== '';
    $rau = $e !== null ? array_merge($compara($e, $comanda, $linii), $cuTotaluri ? $verificaRezumat($e, $linii) : []) : ['niciun email (' . $r['cod'] . ')'];
    $verifica($et . ': Admin „completed” → „Comandă expediată”' . ($cuTotaluri ? ' (cu totalurile)' : ' (șablonul implicit)') . ' = comanda', $rau === [], implode('; ', $rau));
    $notaSuprafata($et, 'email expediere (Admin)', $rau === []);
}
$verifica('corecturile din ERP au rulat pe comenzile alese (' . $erpFacut . ')', $erpFacut >= 3);

// ── C. Reducerea comercială din Admin, apoi emailul de expediere cu totalurile ──────────────────────────────────────────
$scrie('');
$scrie('== C. Reducerea comercială din Admin → emailul de expediere');
$cuReducere = null;
foreach ($comenziPlasate as $plasata) {
    if (($plasata['caz']['oferta'] ?? null) === null && empty($plasata['caz']['ridicare'])) {
        $cuReducere = $plasata;
        break;
    }
}
if ($cuReducere !== null) {
    $idComanda = $cuReducere['id'];
    $numar = $cuReducere['numar'];
    $db->prepare("UPDATE orders SET status = 'pending', edit_unlocked_at = NULL WHERE id = :id")->execute(['id' => $idComanda]);
    $f = $fereastraAdmin($numar);
    $r = $cerere('POST', '/admin/orders/' . $idComanda . '/discount', $jarAdmin, ['_csrf' => $f['csrf'], 'mode' => 'suma', 'value' => '12.50',
        'reason' => 'Test']);
    [$comanda, $linii] = $recitesteComanda($idComanda);
    $verifica('reducerea comercială de 12,50 lei salvată din Admin (totalul scade cu ea)', (float) ($comanda['manual_discount'] ?? 0) > 0,
        $r['cod'] . ' ' . mb_substr($r['corp'], 0, 300));
    $setare('email_template_shipped_body', $sablonTotaluri);
    $db->prepare("DELETE FROM email_logs WHERE order_id = :id AND email_type = 'shipped'")->execute(['id' => $idComanda]);
    $f = $fereastraAdmin($numar);
    $mailuriInainte = glob($dosarDev . '/mail/*') ?: [];
    $cerere('POST', '/admin/orders/' . $idComanda . '/status', $jarAdmin, ['_csrf' => $f['csrf'], 'status' => 'completed', 'back_url' => '/admin/orders']);
    [$comanda, $linii] = $recitesteComanda($idComanda);
    $e = $expediereEmail($idComanda, $numar, $mailuriInainte);
    $rau = $e !== null ? $compara($e, $comanda, $linii) : ['niciun email'];
    $verifica($cuReducere['et'] . ': „Comandă expediată” după reducerea comercială: tabelul totalurilor se adună la totalul comenzii', $rau === [], implode('; ', $rau));
    $notaSuprafata($cuReducere['et'], 'email după reducerea comercială', $rau === []);
}

// ── D. Pragul transportului gratuit fără „shipping_include_coupons”: coșul plutitor ca restul ──────────────────────────
$scrie('');
$scrie('== D. Pragul transportului gratuit, cu reducerile scoase din prag (shipping_include_coupons = 0)');
$setare('shipping_include_coupons', '0');
$puneCampania('al_n', 2, 0, 50);
OfertaCampanie::reseteaza();
$j = $jar('prag');
$adauga($j, $pA, 2);
$adauga($j, $pB, 1);
$cerere('POST', '/cos/judet', $j, ['county' => 'Cluj']);
$s = $sumarCos($j);
$cos = $cerere('GET', '/cos', $j)['corp'];
$gratuitInCos = str_contains($cos, 'Ai transport gratuit pe comandă.');
$verifica('A × 2 + B (280 lei înainte de ofertă, 230 după): transportul, pagina coșului și coșul plutitor spun același lucru despre pragul de 250 lei',
    ((float) $s['shipping'] <= 0.009) === $gratuitInCos && ((float) ($s['shipping_remaining_for_free'] ?? -1) <= 0.009) === $gratuitInCos,
    'transport ' . $s['shipping'] . ', coșul: ' . ($gratuitInCos ? 'gratuit' : 'nu') . ', plutitorul: mai lipsesc ' . ($s['shipping_remaining_for_free'] ?? '?'));
$setare('shipping_include_coupons', '1');

// ── Tabelul ───────────────────────────────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== Tabelul combinații × suprafețe (OK / EȘEC)');
foreach ($tabel as $caz => $suprafete) {
    $scrie('  ' . $caz);
    $scrie('      ' . implode(' | ', array_map(static fn (string $s, bool $ok): string => $s . ' ' . ($ok ? 'OK' : 'EȘEC'), array_keys($suprafete), $suprafete)));
}

// ── E. Curățenia ──────────────────────────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== E. Curățenia');
$puneLaLoc();
$verifica('baza pusă la loc: CHECKSUM TABLE identic pe fiecare tabel', $sumeBaza() === $sumeInainte,
    implode(', ', array_keys(array_diff_assoc($sumeBaza(), $sumeInainte))));
$erori = is_file($fisierErori) ? (string) file_get_contents($fisierErori, false, null, $pozitieErori) : '';
$verifica('jurnalul PHP: niciun Warning, Notice sau Fatal nou', preg_match('/PHP (Warning|Notice|Fatal|Parse)/', $erori) !== 1, mb_substr($erori, 0, 1200));

$scrie('');
$scrie('Rezultat: ' . $trecute . ' trecute, ' . $esuate . ' eșuate. Raport: ' . $fisierRaport);
exit($esuate === 0 ? 0 : 1);
