<?php

declare(strict_types=1);

/**
 * Golden master-ul Biovitality, local (planul Etapei 1, WP-11b; docs/VERIFICARE.md, „Comparația calculelor”): aceeași
 * comparație ca tools/dev/test-calcule.php, cu profilul `biovitality`, pe o bază făcută ca a codului Biovitality și cu codul
 * Biovitality însuși (commit-ul revizuit din docs/de-portat.md). Îl rulează tools/dev/compara-calcule-local.sh (poarta G-calc),
 * care scoate întâi codul Biovitality cu `git archive`:
 *
 *   tools/dev/php.sh tools/dev/test-calcule-bv.php --cod-vechi=<copia codului Biovitality>
 *
 * Baza cms_calc_bv: structura din tools/dev/migrari/vechi/bv.sql (schema pe care o face codul Biovitality la 779e0d8), rândurile
 * demo ale bazei de dezvoltare copiate în coloanele comune (setările fără cheile pe care înghețarea Biovitality le scrie doar unde
 * lipsesc, ca pe site, unde codul vechi nu le avea), plus comenzi inventate pentru ce e al Biovitality: un produs cu transport
 * gratuit (WP-20, C14a) singur în coș, sub pragul de gratuitate; un cupon cerut cu spații și litere mici („ vara 10 ”, C9), un
 * produs-pachet (`bundle_items_json`) într-o comandă, o comandă plătită cu Stripe, cu număr „BV…”, una cu peste 13 kg (coletele:
 * `fan_kg_max_colet` = 0 din înghețare, ca codul Biovitality). Apoi migrările codului comun cu `--site=biovitality` (pachetul
 * într-o copie, cu valori private inventate), ca la adoptare, și licența de dezvoltare a Biovitality.
 *
 * C1 compara-calcule.php --local --profil=biovitality: „REZULTAT: TRECE”, „diferite 0”, „excluse: pachete 1” (comanda cu pachetul
 *    nu e printre cazuri), „excluse: epuizate N” (comenzile doar cu produse epuizate, un coș gol în ambele coduri, nu sunt cazuri), cupoanele aplicate în ambele coduri (și cel cu spații), payload-ul ERP identic, transportul din admin
 *    identic (și pe comanda cu transport gratuit, pe care codul Biovitality îl pune în admin, nu în funcție); diferențele voite pe
 *    clase ale profilului (ComparaCalcule::DECLARATE_PROFIL, aceleași și pe server): produsele epuizate scoase din coș în ambele coduri
 *    (cât numără baza) și cheile ERP noi ale codului comun (adresa de facturare, nota internă), lipsă în codul vechi, numărate; cele pe
 *    comandă ar sta în $diferenteDeclarateBv (azi niciuna).
 * C2 mutațiile codului comun (într-o copie, --cod-nou): transportul gratuit pe produs ignorat, codul de cupon necompactat,
 *    coletele împărțite la 13 kg indiferent de setare: fiecare „PICĂ” și își numește câmpul (deci comparația vede ce e al
 *    Biovitality); un cod vechi care trimite și el o cheie declarată nouă, altfel: „PICĂ” (o cheie a amândurora se compară mereu);
 *    refuzul unui profil necunoscut și al profilului Biovitality pe o bază fără bundle_items_json (cod 2).
 * C3 amprenta paginilor (amprenta-site.php) pe codul Biovitality (+26) și pe codul comun cu pachetul Biovitality (+27), pe aceeași
 *    bază: amândouă merg pe paginile publice (lista fixă, prima pagină, harta), comparația spune ce diferă (informativ: e lista a
 *    ce vede altfel un vizitator Biovitality după adoptare; pe server o face proprietarul pe biovitality.ro și pe copie).
 *
 * Își face și își șterge baza cms_calc_bv, cms_calc_bv_fara și dosarul $CMS_DEV_DIR/calcule-bv; baza de dezvoltare trebuie să fie
 * făcută de tools/dev/instaleaza.sh (o citește, n-o schimbă). Raportul: $CMS_DEV_DIR/test-calcule-bv-raport.txt; cod de ieșire 0 doar
 * dacă totul a trecut. Toate datele sunt inventate.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-calcule-bv-raport.txt';
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
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . mb_substr($detalii, 0, 3000) : ''));
    }
};

$codVechi = '';
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--cod-vechi=(.+)$/', $argument, $m) === 1) {
        $codVechi = (string) realpath($m[1]);
        continue;
    }
    fwrite(STDERR, "argument necunoscut: {$argument} (--cod-vechi=<copia codului Biovitality>)\n");
    exit(2);
}
if ($codVechi === '' || !is_file($codVechi . '/src/Support/CheckoutCalculator.php') || !is_file($codVechi . '/src/Support/ProductBundles.php')) {
    fwrite(STDERR, "--cod-vechi trebuie să fie o copie a codului Biovitality (o face tools/dev/compara-calcule-local.sh)\n");
    exit(2);
}
$scrie('Test golden master Biovitality — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION . ', codul vechi ' . basename($codVechi));

$sock = (string) (getenv('CMS_DEV_SOCK') ?: '/tmp/claude-0/maria/sock');
$bazaDev = (string) getenv('DB_DATABASE');
$bazaBv = 'cms_calc_bv';
$bazaFara = 'cms_calc_bv_fara';
$dosarTest = $dosarDev . '/calcule-bv';
$pachetCopie = $dosarTest . '/pachet';
$dosarLicenta = $dosarTest . '/licenta';
$codMutat = $dosarTest . '/cod-nou';
$portVechi = (int) (getenv('CMS_DEV_PORT') ?: 8701) + 26;
$portNou = (int) (getenv('CMS_DEV_PORT') ?: 8701) + 27;
$servere = [];

$conectare = static function (?string $db = null) use ($sock): PDO {
    return new PDO('mysql:unix_socket=' . $sock . ($db !== null ? ';dbname=' . $db : '') . ';charset=utf8mb4', (string) (getenv('CMS_DEV_DB_USER') ?: 'root'),
        (string) (getenv('CMS_DEV_DB_PAROLA') ?: ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
};
$opresteServerele = static function () use (&$servere): void {
    foreach ($servere as $pid) {
        exec('pkill -TERM -P ' . (int) $pid . ' 2>/dev/null; kill ' . (int) $pid . ' 2>/dev/null');
    }
    $servere = [];
};
$curata = static function () use ($conectare, $bazaBv, $bazaFara, $dosarTest, $dosarDev, $opresteServerele): void {
    $opresteServerele();
    try {
        $root = $conectare();
        foreach ([$bazaBv, $bazaFara] as $b) {
            $root->exec('DROP DATABASE IF EXISTS ' . $b);
        }
    } catch (Throwable) {
    }
    if (str_starts_with($dosarTest, $dosarDev . '/') && is_dir($dosarTest)) {
        exec('chmod -R u+rwX ' . escapeshellarg($dosarTest) . ' 2>/dev/null; rm -rf ' . escapeshellarg($dosarTest));
    }
    foreach (glob($dosarDev . '/calcule-tmp/calcule-cupoane-*') ?: [] as $f) {
        @unlink($f);
    }
};
register_shutdown_function($curata);
$curata();

/** O comandă cu mediul dat (proc_open, cu termen). */
$proces = static function (array $comanda, array $mediu, int $termen = 900) use ($radacina): array {
    $p = proc_open($comanda, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tevi, $radacina, $mediu);
    if (!is_resource($p)) {
        return ['cod' => -1, 'iesire' => '', 'tot' => 'nu pornește'];
    }
    stream_set_blocking($tevi[1], false);
    stream_set_blocking($tevi[2], false);
    $iesire = '';
    $erori = '';
    $limita = microtime(true) + $termen;
    while (!feof($tevi[1]) || !feof($tevi[2])) {
        $citire = array_values(array_filter([$tevi[1], $tevi[2]], static fn ($t): bool => !feof($t)));
        $w = null;
        $e = null;
        if (@stream_select($citire, $w, $e, 1) === false) {
            break;
        }
        foreach ($citire as $t) {
            $bucata = (string) fread($t, 65536);
            $t === $tevi[1] ? $iesire .= $bucata : $erori .= $bucata;
        }
        if (microtime(true) > $limita) {
            proc_terminate($p, 9);
            $erori .= "\n[TIMP DEPĂȘIT]";
            break;
        }
    }
    fclose($tevi[1]);
    fclose($tevi[2]);
    return ['cod' => proc_close($p), 'iesire' => $iesire, 'tot' => $iesire . $erori];
};
$ini = ['-d', 'pdo_mysql.default_socket=' . $sock, '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', '-d', 'log_errors=0', '-d', 'memory_limit=512M',
    '-d', 'allow_url_fopen=0'];
$mediuBv = static function (array $inPlus = []) use ($bazaBv, $pachetCopie, $dosarLicenta): array {
    $mediu = array_merge(getenv(), ['DB_DATABASE' => $bazaBv, 'SITE_DATE_DOSAR' => $pachetCopie, 'LICENTA_DOSAR' => $dosarLicenta], $inPlus);
    foreach ($mediu as $k => $v) {
        if ($v === null) {
            unset($mediu[$k]);
        }
    }
    return $mediu;
};
$php = static fn (string $script, array $argumente, array $mediuInPlus = []): array
    => $proces(array_merge([PHP_BINARY], $ini, [$radacina . '/' . $script], $argumente), $mediuBv($mediuInPlus));
$ultimul = static fn (string $text): string => trim((string) strrchr("\n" . trim($text), "\n"));
$numar = static fn (string $text, string $tipar): ?int => preg_match($tipar, $text, $m) === 1 ? (int) $m[1] : null;
/** Coloanele unui tabel (nume → [tip, nul, implicit, extra, lungime, complet]). */
$coloane = static function (PDO $db, string $tabel): array {
    $s = $db->prepare('SELECT COLUMN_NAME AS c, DATA_TYPE AS tip, IS_NULLABLE AS nul, COLUMN_DEFAULT AS implicit, EXTRA AS extra FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
    $s->execute([$tabel]);
    $r = [];
    foreach ($s->fetchAll() as $c) {
        $r[(string) $c['c']] = $c;
    }
    return $r;
};
/** Un rând nou: valorile date, iar celelalte coloane NOT NULL fără implicit primesc o valoare potrivită tipului. */
$rand = static function (PDO $db, string $tabel, array $valori) use ($coloane): int {
    $r = [];
    foreach ($coloane($db, $tabel) as $nume => $c) {
        if (array_key_exists($nume, $valori)) {
            $r[$nume] = $valori[$nume];
        } elseif ($c['nul'] === 'NO' && $c['implicit'] === null && stripos((string) $c['extra'], 'auto_increment') === false) {
            $r[$nume] = in_array($c['tip'], ['tinyint', 'smallint', 'int', 'bigint', 'decimal'], true) ? 0
                : (in_array($c['tip'], ['datetime', 'timestamp'], true) ? date('Y-m-d H:i:s') : '');
        }
    }
    $db->prepare('INSERT INTO `' . $tabel . '` (`' . implode('`, `', array_keys($r)) . '`) VALUES (' . implode(', ', array_fill(0, count($r), '?')) . ')')
        ->execute(array_values($r));
    return (int) $db->lastInsertId();
};

/**
 * Diferențele voite ale codului comun față de codul Biovitality pe comenzile testului (ca $diferenteDeclarate din test-calcule.php):
 * comanda (după eticheta ei de mai jos) → câmpurile care diferă, exact, și motivul. Pe server nu există declarații: o diferență
 * declarată aici e una pe care copia ar arăta-o și acolo, deci intră în site-uri/biovitality/ce-se-schimba.md.
 *
 * @var array<string, array{campuri: list<string>, motiv: string}>
 */
$diferenteDeclarateBv = [];

try {
    if (preg_match('/^cms_[a-z0-9_]+$/', $bazaDev) !== 1 || (int) $conectare($bazaDev)->query('SELECT COUNT(*) FROM products')->fetchColumn() < 10) {
        throw new RuntimeException('baza de dezvoltare (DB_DATABASE) lipsește sau e goală: rulează întâi tools/dev/instaleaza.sh');
    }
    $root = $conectare();

    // ── Pachetul Biovitality, într-o copie, cu valori private inventate (cele adevărate nu intră în bazele de dezvoltare, OD-49) ──
    $scrie('');
    $scrie('Baza în forma Biovitality');
    exec('mkdir -p ' . escapeshellarg($pachetCopie) . ' ' . escapeshellarg($dosarLicenta) . ' && cp -a ' . escapeshellarg($radacina . '/site-uri/biovitality/.')
        . ' ' . escapeshellarg($pachetCopie) . ' && rm -rf ' . escapeshellarg($pachetCopie . '/privat'));
    // Înghețarea Biovitality e provizorie (sursa nu e încă confirmată ca live, Etapa 0): pe server merge doar cu --permite-provizoriu pe
    // copia de verificare (încărcarea îl dă); aici, în copia testului, fără câmpul „provizoriu” (aceleași setări).
    $inghetare = json_decode((string) file_get_contents($pachetCopie . '/setari-inghetate.json'), true);
    unset($inghetare['provizoriu']);
    $textInghetare = json_encode($inghetare, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    file_put_contents($pachetCopie . '/setari-inghetate.json', $textInghetare);
    $privat = ['site' => $inghetare['site'], 'sursa' => $inghetare['sursa'], 'pentru' => hash('sha256', str_replace("\r\n", "\n", $textInghetare)), 'setari' => []];
    $cheiLipsa = [];
    foreach ((array) $inghetare['setari'] as $cheie => $intrare) {
        if (($intrare['privat'] ?? false) === true) {
            $privat['setari'][$cheie] = str_contains((string) $cheie, 'telefon') ? '0700 000 009' : 'test.' . $cheie . '@exemplu.ro';
        }
        if (($intrare['mod'] ?? '') === 'lipsa') {
            $cheiLipsa[] = (string) $cheie;
        }
    }
    mkdir($pachetCopie . '/privat', 0700, true);
    file_put_contents($pachetCopie . '/privat/setari-inghetate.json', (string) json_encode($privat));

    // ── Structura codului Biovitality și rândurile demo ──
    $root->exec('CREATE DATABASE ' . $bazaBv . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $db = $conectare($bazaBv);
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    preg_match_all('/^CREATE TABLE `([^`]+)`.*?;$/ms', (string) file_get_contents($radacina . '/tools/dev/migrari/vechi/bv.sql'), $creari, PREG_SET_ORDER);
    $dev = $conectare($bazaDev);
    $copiate = 0;
    foreach ($creari as [$sql, $tabel]) {
        $db->exec($sql);
    }
    foreach ($creari as [, $tabel]) {
        $comune = array_values(array_intersect(array_keys($coloane($db, $tabel)), array_keys($coloane($dev, $tabel))));
        if ($comune === []) {
            continue;
        }
        $lista = '`' . implode('`, `', $comune) . '`';
        $unde = $tabel === 'settings' ? ' WHERE `key` NOT IN (' . implode(', ', array_map([$db, 'quote'], $cheiLipsa)) . ')' : '';
        $copiate += (int) $db->exec('INSERT INTO `' . $tabel . '` (' . $lista . ') SELECT ' . $lista . ' FROM `' . $bazaDev . '`.`' . $tabel . '`' . $unde);
    }

    // Plățile codului Biovitality: ramburs și Stripe (n-are BT, EuPlătesc și ordin de plată, deci nici comenzi cu ele).
    $db->exec("UPDATE orders SET payment_method = CASE WHEN payment_method IN ('btipay', 'euplatesc') THEN 'stripe' WHEN payment_method = 'bank_transfer'
        THEN 'cod' ELSE payment_method END");

    // ── Ce e al Biovitality: comenzi inventate, câte una pe fiecare lucru al lui ──
    $client = (int) $db->query('SELECT MIN(id) FROM users')->fetchColumn();
    $produse = $db->query('SELECT id, price, weight_grams FROM products WHERE is_active = 1 AND deleted_at IS NULL AND stock > 5 AND (bbd_enabled = 0 OR bbd_enabled IS NULL)
        ORDER BY id LIMIT 4')->fetchAll();
    if (count($produse) < 4) {
        throw new RuntimeException('datele demo n-au 4 produse active cu stoc');
    }
    [$pGratuit, $pPachet, $pGreu, $pCupon] = array_map(static fn (array $p): int => (int) $p['id'], $produse);
    $db->prepare('UPDATE products SET free_shipping = 1 WHERE id = ?')->execute([$pGratuit]);
    $db->prepare('UPDATE products SET bundle_items_json = ? WHERE id = ?')->execute(['[{"product_id":' . $pCupon . ',"quantity":2}]', $pPachet]);
    $db->prepare('UPDATE products SET weight_grams = 7000 WHERE id = ?')->execute([$pGreu]);
    $rand($db, 'coupons', ['code' => 'VARA10', 'name' => 'Vara demo', 'type' => 'percent', 'value' => 10, 'is_active' => 1, 'is_unique' => 0]);
    $comenzi = [];
    $comanda = static function (string $eticheta, array $linii, array $campuri) use ($db, $rand, $client, &$comenzi): int {
        $subtotal = 0.0;
        foreach ($linii as [$produs, $cantitate]) {
            $subtotal += $cantitate * (float) $db->query('SELECT price FROM products WHERE id = ' . (int) $produs)->fetchColumn();
        }
        $id = $rand($db, 'orders', $campuri + ['order_number' => 'BV' . date('YmdHis') . random_int(100, 999), 'user_id' => $client, 'status' => 'completed',
            'payment_method' => 'ramburs', 'payment_status' => 'pending', 'shipping_method' => 'fan_courier', 'billing_first_name' => 'Client', 'billing_last_name' => 'Demo',
            'billing_email' => 'client.bv@exemplu.ro', 'billing_phone' => '0700000001', 'billing_county' => 'Cluj', 'billing_city' => 'Cluj-Napoca',
            'billing_address_line1' => 'Strada Exemplului 1', 'subtotal' => $subtotal, 'shipping_cost' => 0, 'total' => $subtotal, 'created_at' => date('Y-m-d H:i:s')]);
        foreach ($linii as [$produs, $cantitate]) {
            $pret = (float) $db->query('SELECT price FROM products WHERE id = ' . (int) $produs)->fetchColumn();
            $rand($db, 'order_items', ['order_id' => $id, 'product_id' => $produs, 'product_name' => 'Produs demo', 'quantity' => $cantitate, 'unit_price' => $pret,
                'line_total' => $pret * $cantitate]);
        }
        $comenzi[$eticheta] = $id;
        return $id;
    };
    $comanda('transport gratuit pe produs, singur în coș', [[$pGratuit, 1]], []);
    $comanda('cuponul cerut cu spații și litere mici', [[$pCupon, 2]], ['coupon_code' => ' vara 10 ']);
    $comanda('produsul-pachet', [[$pPachet, 1], [$pCupon, 1]], []);
    $comanda('plătită cu Stripe', [[$pCupon, 1]], ['payment_method' => 'stripe', 'payment_status' => 'paid', 'stripe_session_id' => 'cs_test_bv',
        'stripe_payment_intent_id' => 'pi_test_bv']);
    $comanda('peste 13 kg (coletele)', [[$pGreu, 3]], []);
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    $verifica('baza cms_calc_bv: structura codului Biovitality (bv.sql), ' . $copiate . ' rânduri demo, 5 comenzi inventate (transport gratuit pe produs, cupon cu spații, '
        . 'produs-pachet, Stripe, peste 13 kg)', $copiate > 500 && count($comenzi) === 5);

    // ── Ca la adoptare: migrările cu pașii Biovitality (înghețarea), apoi licența de dezvoltare ──
    $r = $php('scripts/migreaza.php', ['--aplica', '--site=biovitality']);
    $verifica('scripts/migreaza.php --aplica --site=biovitality pe baza Biovitality: cod 0 (baseline-ul, migrările de date, înghețarea Biovitality)', $r['cod'] === 0,
        mb_substr($r['tot'], -2000));
    $setari = $db->query('SELECT `key`, `value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    $verifica('înghețarea Biovitality pe bază: fan_kg_max_colet 0, numerotarea veche „BV”, Stripe pornit', ($setari['fan_kg_max_colet'] ?? null) === '0'
        && ($setari['order_number_mode'] ?? null) === 'legacy' && ($setari['order_number_legacy_prefix'] ?? null) === 'BV' && ($setari['stripe_enabled'] ?? null) === '1',
        json_encode(array_intersect_key($setari, array_flip(['fan_kg_max_colet', 'order_number_mode', 'stripe_enabled']))) ?: '');
    $r = $php('tools/dev/licenta.php', ['biovitality']);
    $verifica('licența de dezvoltare a Biovitality, în dosarul testului', $r['cod'] === 0 && is_file($dosarLicenta . '/licenta.json'), $r['tot']);

    // ── C1: comparația ──
    $scrie('');
    $scrie('C1. compara-calcule.php --local --profil=biovitality, față de codul Biovitality');
    $fisierDeclarate = $dosarTest . '/declarate.json';
    $declarate = [];
    foreach ($diferenteDeclarateBv as $eticheta => $d) {
        $declarate[] = ['comanda' => $comenzi[$eticheta], 'campuri' => $d['campuri'], 'motiv' => $d['motiv']];
    }
    file_put_contents($fisierDeclarate, (string) json_encode($declarate));
    $compara = static fn (array $argumente): array => $php('tools/verificare/compara-calcule.php', array_merge(['--local', '--profil=biovitality',
        '--cod-vechi=' . $codVechi], $declarate !== [] ? ['--declarate=' . $fisierDeclarate] : [], $argumente));
    $r = $compara([]);
    $scrie(implode("\n", array_map(static fn (string $l): string => '        | ' . $l, explode("\n", trim($r['iesire'])))));
    $cazuri = $numar($r['iesire'], '/^cazuri (\d+) \|/m');
    $eligibile = (int) $db->query("SELECT COUNT(*) FROM orders WHERE deleted_at IS NULL AND status <> 'failed'")->fetchColumn();
    $verifica('C1 „REZULTAT: TRECE”, diferite 0, erori 0' . ($declarate !== [] ? ', diferențele voite declarate potrivite exact (' . count($declarate) . ')' : ''),
        $r['cod'] === 0 && $ultimul($r['iesire']) === 'REZULTAT: TRECE' && str_contains($r['iesire'], '| diferite 0 | erori vechi 0 | erori nou 0'), mb_substr($r['tot'], -3000));
    // Comenzile fără pachet: cele cu toate liniile epuizate (excluse) și liniile epuizate ale celorlalte (scoase din coș).
    $faraPachet = "o.deleted_at IS NULL AND o.status <> 'failed' AND NOT EXISTS (SELECT 1 FROM order_items x INNER JOIN products y ON y.id = x.product_id
        WHERE x.order_id = o.id AND y.bundle_items_json IS NOT NULL AND TRIM(y.bundle_items_json) NOT IN ('', '[]', '{}', 'null'))";
    $doarEpuizate = "NOT EXISTS (SELECT 1 FROM order_items z INNER JOIN products w ON w.id = z.product_id WHERE z.order_id = o.id AND w.out_of_stock = 0)";
    $excluseEpuizate = (int) $db->query('SELECT COUNT(*) FROM orders o WHERE ' . $faraPachet . ' AND EXISTS (SELECT 1 FROM order_items v WHERE v.order_id = o.id) AND '
        . $doarEpuizate)->fetchColumn();
    $verifica('C1 coșurile cu un produs-pachet („excluse: pachete 1”) și cele doar cu produse epuizate (gol în ambele coduri, „excluse: epuizate ' . $excluseEpuizate
        . '”) nu sunt printre cazuri (' . $cazuri . ' = ' . $eligibile . ' comenzi − 1 − ' . $excluseEpuizate . ')', $excluseEpuizate > 0
        && str_contains($r['iesire'], 'excluse: pachete 1 (') && str_contains($r['iesire'], "\nexcluse: epuizate " . $excluseEpuizate . ' (')
        && $cazuri === $eligibile - 1 - $excluseEpuizate);
    $epuizateAsteptate = (int) $db->query('SELECT COUNT(*) FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id INNER JOIN products p ON p.id = oi.product_id
        WHERE p.out_of_stock = 1 AND ' . $faraPachet . ' AND NOT ' . $doarEpuizate)->fetchColumn();
    $verifica('C1 produsele epuizate scoase din coș în ambele coduri (diferența declarată pe profil): ' . $epuizateAsteptate . ' linii, cât numără baza, iar cazurile '
        . 'lor identice', $epuizateAsteptate > 0 && preg_match('/^  produse epuizate scoase din coș, în ambele coduri: ' . $epuizateAsteptate . ' linii în \d+ cazuri/m',
            $r['iesire']) === 1);
    $verifica('C1 cheile ERP noi ale codului comun (adresa de facturare, nota internă), declarate pe profil: lipsă în codul vechi, necomparate, numărate pe fiecare caz',
        preg_match('/^payload ERP: chei noi ale codului comun \(lipsă în codul vechi; declarate pe profil, necomparate\): facturareAdresa (\d+), facturareCodPostal \1, '
            . 'facturareJudet \1, facturareOras \1, observatiiInterne \1$/m', $r['iesire'], $mNoi) === 1 && (int) $mNoi[1] === $cazuri);
    $verifica('C1 cupoanele aplicate în ambele coduri la fel (și cel cerut cu spații), punctele folosite, transportul din admin și payload-ul ERP identice pe toate cazurile',
        preg_match('/^cupoane aplicate fără eroare: vechi (\d+), nou \1 \|/m', $r['iesire']) === 1 && (int) $numar($r['iesire'], '/^cupoane aplicate fără eroare: vechi (\d+)/m') >= 4
        && $numar($r['iesire'], '/^payload ERP: identice (\d+)/m') === $cazuri && $numar($r['iesire'], '/^transportul din admin: identice (\d+)/m') === $cazuri);

    // ── C2: mutațiile codului comun și refuzurile ──
    $scrie('');
    $scrie('C2. Mutațiile codului comun (într-o copie, --cod-nou): fiecare pică și își numește câmpul');
    $fisiere = array_filter(explode("\0", (string) shell_exec('git -C ' . escapeshellarg($radacina) . ' ls-files -z --cached --others --exclude-standard')));
    foreach ($fisiere as $f) {
        if (!is_file($radacina . '/' . $f) || str_starts_with($f, 'storage/') || str_starts_with($f, 'public/uploads/') || str_starts_with($f, '.env')) {
            continue;
        }
        @mkdir(dirname($codMutat . '/' . $f), 0770, true);
        copy($radacina . '/' . $f, $codMutat . '/' . $f);
    }
    foreach (['storage/sessions', 'storage/cache', 'storage/logs', 'storage/licenta'] as $d) {
        @mkdir($codMutat . '/' . $d, 0770, true);
    }
    $mutatii = [
        'transportul gratuit pe produs ignorat în coș (CheckoutCalculator::buildSummary, C14a)' => ['src/Support/CheckoutCalculator.php',
            '$transportGratuitDinProdus = $db instanceof PDO', '$transportGratuitDinProdus = false && $db instanceof PDO', '/^diferențe pe câmp: .*\bshipping \d+/m'],
        'codul de cupon cerut cu spații necăutat și fără ele (CheckoutCalculator, C9)' => ['src/Support/CheckoutCalculator.php',
            "if (!is_array(\$row) && \$compact !== '' && \$compact !== \$code) {", 'if (false) {', '/^diferențe pe câmp: .*\b(discount|cupon_ok) \d+/m'],
        'coletele împărțite la 13 kg oricare ar fi setarea (ErpSync::coleteCalcul)' => ['src/Support/ErpSync.php',
            "'kgMaxColet' => max(0.0, (float) str_replace(',', '.', (string) (\$settings['fan_kg_max_colet'] ?? '13'))),", "'kgMaxColet' => 13.0,",
            '/^payload ERP: identice \d+ \| chei diferite: .*\bcoleteCalcul \d+/m'],
    ];
    foreach ($mutatii as $ce => [$fisier, $din, $in, $semn]) {
        $original = (string) file_get_contents($codMutat . '/' . $fisier);
        if (substr_count($original, $din) < 1) {
            $verifica('C2 mutația „' . $ce . '” se aplică (textul ei e în ' . $fisier . ')', false);
            continue;
        }
        file_put_contents($codMutat . '/' . $fisier, str_replace($din, $in, $original));
        $r = $compara(['--cod-nou=' . $codMutat]);
        file_put_contents($codMutat . '/' . $fisier, $original);
        $verifica('C2 ' . $ce . ': „PICĂ”, cu câmpul ei', $r['cod'] === 1 && $ultimul($r['iesire']) === 'REZULTAT: PICĂ' && preg_match($semn, $r['iesire']) === 1,
            mb_substr($r['tot'], -1500));
    }
    // Regulile profilului, scoase într-o copie a uneltei (orchestratorul copiei, cu codul copiei): fără scoaterea produselor epuizate,
    // cazurile lor diferă; fără excluderea pachetelor, comanda cu pachetul e printre cazuri.
    $calculeCopie = $codMutat . '/tools/verificare/lib/calcule.php';
    $calculeOriginal = (string) file_get_contents($calculeCopie);
    foreach ([
        'fără scoaterea produselor epuizate (DECLARATE_PROFIL fara_epuizate)' => ["'fara_epuizate' => true,", "'fara_epuizate' => false,",
            static fn (array $r): bool => $r['cod'] === 1 && preg_match('/^diferențe pe câmp: .*\bsubtotal \d+/m', $r['iesire']) === 1],
        'fără excluderea coșurilor doar cu produse epuizate (un coș gol în ambele coduri numărat „identic”)' => ['if ($ramase === [] && $liniiComanda !== []) {',
            'if (false) {', static fn (array $r): bool => str_contains($r['iesire'], "\nexcluse: epuizate 0 (")
            && $numar($r['iesire'], '/^cazuri (\d+) \|/m') === $eligibile - 1],
        'fără excluderea coșurilor cu pachete (PROFILURI_CU_PACHETE)' => ["public const PROFILURI_CU_PACHETE = ['biovitality'];", 'public const PROFILURI_CU_PACHETE = [];',
            static fn (array $r): bool => !str_contains($r['iesire'], 'excluse: pachete') && $numar($r['iesire'], '/^cazuri (\d+) \|/m') === $eligibile - $excluseEpuizate],
    ] as $ce => [$din, $in, $semn]) {
        file_put_contents($calculeCopie, str_replace($din, $in, $calculeOriginal));
        $r = $proces(array_merge([PHP_BINARY], $ini, [$codMutat . '/tools/verificare/compara-calcule.php', '--local', '--profil=biovitality', '--cod-vechi=' . $codVechi]),
            $mediuBv());
        file_put_contents($calculeCopie, $calculeOriginal);
        $verifica('C2 regula profilului scoasă, ' . $ce . ': comparația o vede', substr_count($calculeOriginal, $din) === 1 && $semn($r), mb_substr($r['tot'], -1500));
    }
    // Cheile ERP noi se lasă necomparate doar când lipsesc din payload-ul vechi: un cod vechi care trimite și el una (altfel) pică.
    $codVechiMutat = $dosarTest . '/cod-vechi-mutat';
    exec('cp -a ' . escapeshellarg($codVechi) . ' ' . escapeshellarg($codVechiMutat));
    $erpVechi = (string) file_get_contents($codVechiMutat . '/src/Support/ErpSync.php');
    $randObservatii = "'observatii' => (string) (\$order['notes'] ?? ''),";
    file_put_contents($codVechiMutat . '/src/Support/ErpSync.php', str_replace($randObservatii, $randObservatii . "\n            'observatiiInterne' => 'alt text',", $erpVechi));
    $r = $php('tools/verificare/compara-calcule.php', ['--local', '--profil=biovitality', '--cod-vechi=' . $codVechiMutat]);
    $verifica('C2 o cheie ERP declarată nouă (observatiiInterne) pe care o trimite și codul vechi, altfel: se compară și „PICĂ”, cu cheia ei',
        substr_count($erpVechi, $randObservatii) === 1 && $r['cod'] === 1 && $ultimul($r['iesire']) === 'REZULTAT: PICĂ'
        && preg_match('/^payload ERP: identice 0 \| chei diferite: observatiiInterne \d+$/m', $r['iesire']) === 1, mb_substr($r['tot'], -1500));
    $r = $php('tools/verificare/compara-calcule.php', ['--local', '--profil=vertij', '--cod-vechi=' . $codVechi]);
    $verifica('C2 un profil necunoscut (vertij) → refuzat (cod 2), cu profilurile știute', $r['cod'] === 2 && str_contains($r['tot'], 'profil necunoscut (vertij)')
        && str_contains($r['tot'], '--profil=biovitality'), $r['tot']);
    // Profilul Biovitality pe o bază fără bundle_items_json (de exemplu o copie Bioscem): refuz, nimic schimbat.
    $root->exec('CREATE DATABASE ' . $bazaFara . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    foreach ($root->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . $bazaBv . "' AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $root->exec('CREATE TABLE ' . $bazaFara . '.`' . $t . '` LIKE ' . $bazaBv . '.`' . $t . '`');
        $root->exec('INSERT INTO ' . $bazaFara . '.`' . $t . '` SELECT * FROM ' . $bazaBv . '.`' . $t . '`');
    }
    $root->exec('ALTER TABLE ' . $bazaFara . '.products DROP COLUMN bundle_items_json');
    $sumaCupoane = (string) $root->query('CHECKSUM TABLE ' . $bazaFara . '.coupons')->fetch(PDO::FETCH_NUM)[1];
    $r = $proces(array_merge([PHP_BINARY], $ini, [$radacina . '/tools/verificare/compara-calcule.php', '--local', '--profil=biovitality', '--cod-vechi=' . $codVechi]),
        $mediuBv(['DB_DATABASE' => $bazaFara]));
    $verifica('C2 profilul Biovitality pe o bază fără products.bundle_items_json → refuzat (cod 2), cupoanele neatinse', $r['cod'] === 2
        && str_contains($r['tot'], 'nu e o copie biovitality') && (string) $root->query('CHECKSUM TABLE ' . $bazaFara . '.coupons')->fetch(PDO::FETCH_NUM)[1] === $sumaCupoane,
        $r['tot']);

    // ── C3: amprenta paginilor pe codul Biovitality și pe codul comun cu pachetul Biovitality ──
    $scrie('');
    $scrie('C3. Amprenta paginilor (amprenta-site.php): codul Biovitality (+' . ($portVechi - (int) (getenv('CMS_DEV_PORT') ?: 8701)) . ') și codul comun cu pachetul '
        . 'Biovitality (+' . ($portNou - (int) (getenv('CMS_DEV_PORT') ?: 8701)) . '), pe aceeași bază');
    $porneste = static function (string $cod, int $port, array $mediuInPlus) use (&$servere, $ini, $mediuBv, $radacina, $dosarTest): bool {
        $jurnal = $dosarTest . '/server-' . $port . '.log';
        $p = proc_open(array_merge([PHP_BINARY], $ini, ['-d', 'display_errors=0', '-S', '127.0.0.1:' . $port, '-t', $cod . '/public', $radacina . '/tools/dev/router.php']),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $jurnal, 'a'], 2 => ['file', $jurnal, 'a']], $tevi, $cod,
            $mediuBv(['APP_URL' => 'http://127.0.0.1:' . $port, 'PHP_CLI_SERVER_WORKERS' => '4'] + $mediuInPlus));
        $servere[] = (int) proc_get_status($p)['pid'];
        for ($i = 0; $i < 100; $i++) {
            $c = curl_init('http://127.0.0.1:' . $port . '/robots.txt');
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
            curl_exec($c);
            $raspuns = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
            curl_close($c);
            if ($raspuns > 0) {
                return true;
            }
            usleep(100000);
        }
        return false;
    };
    $pornite = $porneste($codVechi, $portVechi, ['CMS_DEV_MARCAJE_PORT' => null]) && $porneste($radacina, $portNou, []);
    $verifica('C3 serverele pornesc (codul Biovitality și codul comun, pe cms_calc_bv)', $pornite);
    $amprente = [];
    foreach (['vechi' => $portVechi, 'nou' => $portNou] as $parte => $port) {
        $fisier = $dosarTest . '/amprenta-' . $parte . '.tsv';
        // Conținutul e al „site-ului” codului vechi (ca o copie de verificare cu --gazda-sursa=biovitality.ro): aceeași gazdă sursă.
        $r = $php('tools/verificare/amprenta-site.php', ['--baza=http://127.0.0.1:' . $port, '--iesire=' . $fisier, '--pauza=0', '--max=400',
            '--gazda-sursa=127.0.0.1:' . $portVechi]);
        $randuri = is_file($fisier) ? count(file($fisier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : 0;
        $amprente[$parte] = $fisier;
        $verifica('C3 amprenta codului ' . ($parte === 'vechi' ? 'Biovitality' : 'comun cu pachetul Biovitality') . ': cod 0, ' . $randuri . ' rânduri (lista fixă, prima pagină, '
            . 'harta)', $r['cod'] === 0 && $randuri > 15, mb_substr($r['tot'], -1500));
    }
    $r = $php('tools/verificare/amprenta-site.php', ['--compara', $amprente['vechi'], $amprente['nou']]);
    $scrie(implode("\n", array_map(static fn (string $l): string => '        | ' . $l, array_slice(explode("\n", trim($r['iesire'])), 0, 60))));
    $verifica('C3 comparația celor două amprente rulează și se încheie cu „REZULTAT: LA FEL” sau „DIFERĂ” (lista: ce vede altfel un vizitator Biovitality după '
        . 'adoptare, informativ; în raport)', in_array($r['cod'], [0, 1], true) && preg_match('/^REZULTAT: (LA FEL|DIFERĂ)/m', $r['iesire']) === 1, mb_substr($r['tot'], -1500));
} catch (Throwable $e) {
    $verifica('testul a rulat până la capăt', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $curata();
}

$scrie('');
$scrie('Rezultat: ' . $trecute . ' trecute, ' . $esuate . ' eșuate');
exit($esuate === 0 ? 0 : 1);
