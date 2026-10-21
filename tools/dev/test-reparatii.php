<?php

declare(strict_types=1);

/**
 * Testele reparațiilor din Biovitality și ale pragului ERP (planul Etapei 1, WP-12), cu serverul de dezvoltare,
 * pe baza de test cu datele demo, și cu un ERP fals (tools/dev/lib/erp-fals.php) pe portul CMS_DEV_PORT + 19.
 *
 *   tools/dev/php.sh tools/dev/test-reparatii.php
 *
 * A. Ofertele BBD în admin: rezervările comenzilor demo pe o ofertă (Admin → Produse, fereastra produsului) se
 *    numără și după ce i se schimbă prețul redus (cheia rămâne; C1, din WP-R); o salvare a produsului care nu trimite
 *    deloc câmpul ofertelor (alt formular, o unealtă; formularul din Produse îl trimite mereu) le păstrează (C2).
 * B. Postarea importată cu categoria doar ca text (X1b): editorul o arată ca notă și o trimite înapoi, re-salvarea
 *    o păstrează; o categorie din listă cu același nume, cu numele scris altfel (fără diacritice, alte litere mari)
 *    sau cu slug-ul textului e bifată, iar re-salvarea o leagă.
 * C. Adminul: CodeMirror și js-beautify doar în cele patru editoare de cod (C6; al patrulea, șablonul de blog, pe un
 *    șablon făcut aici, fiindcă datele demo n-au niciunul); pozele celor șase selectoare de imagini (produse, Setări
 *    magazin, newslettere, editorul postării, autorii blogului) se încarcă leneș (C7).
 * D. Garda pluginurilor pe /api/…, cu licența „prezentare” (fără magazin): JSON-ul are și `error` (X6).
 * E. Stocul din ERP (C3), cu ERP-ul fals: un SKU necunoscut în ERP se întreabă o dată la 60 de secunde, nu la
 *    fiecare afișare (și din nou după 60 de secunde); un ERP care răspunde cu eroare nu mai e întrebat 60 de
 *    secunde; unul care tace ține pagina cel mult cât timeout-ul scurt (3 secunde), nu cât `erp_timeout` (20).
 * F. Lacătul sesiunii (C4): heartbeat-ul coșului, sumarul lui, catalogul și cele mai vândute, lente fiindcă
 *    așteaptă ERP-ul, nu țin în loc o pagină cerută în același timp din același browser; martorul negativ: pagina
 *    unui produs (care ține sesiunea) o ține în loc, deci măsurătoarea vede o serializare.
 * G. Pragul trimiterii în ERP (E1, `erp_trimite_din`): cu o dată, cronul trimite doar comanda de după prag, scrie
 *    de ce și numărătorile din Setări → ERP ANDAXI spun același lucru; Admin → Comenzi arată comanda de dinainte
 *    („ERP: înainte de legare”, 🔄 cu confirmare) și o trimite de mână. O comandă de dinainte deja în fluxul
 *    ERP-ului (ajunsă acolo, trimisă o dată, încercată de la prag cu ERP-ul căzut, ținută de modulul oprit în ERP)
 *    nu e ținută de prag: o trimitere eșuată se reîncearcă automat, contoarele o numără la „Eșuate”, fără eticheta
 *    „înainte de legare” (fiecare urmă pe rând). Cu `''` totul ca până acum; cu cheia lipsă sau greșită, nimic nu
 *    pleacă automat.
 *
 * Toată baza (fiecare tabel) e pusă la loc la final, verificat cu CHECKSUM TABLE; la fel licența „toate”, cache-ul
 * stocului din ERP și sesiunile făcute aici. Serverul de dezvoltare se oprește la final dacă l-a pornit testul, ERP-ul
 * fals mereu. Raportul: $CMS_DEV_DIR/test-reparatii-raport.txt; cod de ieșire 0 doar dacă totul a trecut. Date
 * inventate.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';
require_once __DIR__ . '/lib/salvari-admin.php';

use App\Support\Database;
use App\Support\ErpStock;
use App\Support\ErpSync;
use App\Support\Paths;
use App\Support\Plugins;
use App\Support\Settings;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-reparatii-raport.txt';
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
$scrie('Test reparații WP-12 ANDAXI-CMS — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

$port = (int) (getenv('CMS_DEV_PORT') ?: '8701');
$baza = 'http://127.0.0.1:' . $port;
$portErp = $port + 19;
$adresaErp = 'http://127.0.0.1:' . $portErp;

/** Un handle curl pentru serverul de dezvoltare (sau altă adresă întreagă), cu antetele strânse în $antete. */
$faCurl = static function (string $metoda, string $cale, ?string $jar, array $date, array &$antete, int $timeout = 60) use ($baza) {
    $c = curl_init(str_starts_with($cale, 'http') ? $cale : $baza . $cale);
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metoda,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOPROXY => '*',
        CURLOPT_PROXY => '',
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$antete): int {
            if (str_contains($linie, ':')) {
                [$nume, $valoare] = explode(':', $linie, 2);
                $antete[strtolower(trim($nume))] = trim($valoare);
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
    return $c;
};
$cerere = static function (string $metoda, string $cale, ?string $jar = null, array $date = [], int $timeout = 60) use ($faCurl): array {
    $antete = [];
    $c = $faCurl($metoda, $cale, $jar, $date, $antete, $timeout);
    $corp = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $timp = (float) curl_getinfo($c, CURLINFO_TOTAL_TIME);
    curl_close($c);
    return ['cod' => $cod, 'antete' => $antete, 'corp' => $corp, 'timp' => $timp];
};
/**
 * Două cereri în paralel: a doua pleacă după $intarziere secunde, cât prima e în lucru. Întoarce durata fiecăreia.
 *
 * @param array{0: string, 1: string, 2: ?string, 3?: array<string, mixed>} $prima
 * @param array{0: string, 1: string, 2: ?string, 3?: array<string, mixed>} $aDoua
 * @return array{0: array{cod: int, timp: float}, 1: array{cod: int, timp: float}}
 */
$inParalel = static function (array $prima, array $aDoua, float $intarziere) use ($faCurl): array {
    $mh = curl_multi_init();
    $antete1 = [];
    $antete2 = [];
    $h1 = $faCurl($prima[0], $prima[1], $prima[2], $prima[3] ?? [], $antete1);
    curl_multi_add_handle($mh, $h1);
    $start = microtime(true);
    $h2 = null;
    do {
        curl_multi_exec($mh, $active);
        if ($h2 === null && microtime(true) - $start >= $intarziere) {
            $h2 = $faCurl($aDoua[0], $aDoua[1], $aDoua[2], $aDoua[3] ?? [], $antete2);
            curl_multi_add_handle($mh, $h2);
            curl_multi_exec($mh, $active);
        }
        curl_multi_select($mh, 0.02);
    } while ($active > 0 || $h2 === null);
    $rez = [];
    foreach ([$h1, $h2] as $h) {
        $rez[] = ['cod' => (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'timp' => (float) curl_getinfo($h, CURLINFO_TOTAL_TIME)];
        curl_multi_remove_handle($mh, $h);
        curl_close($h);
    }
    curl_multi_close($mh);
    return $rez;
};

// Serverul de dezvoltare: îl pornește testul dacă nu merge.
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
if (!$db instanceof PDO || (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn() < 10) {
    $scrie('EROARE: baza de test nu are datele demo; rulează întâi tools/dev/instaleaza.sh');
    exit(1);
}
$unu = static function (string $sql, array $param = []) use ($db): string {
    $stmt = $db->prepare($sql);
    $stmt->execute($param);
    $valoare = $stmt->fetchColumn();
    return $valoare === false || $valoare === null ? '' : (string) $valoare;
};

// ── Instantaneul întregii baze: fiecare tabel, pus la loc la final (rândurile cu cheia primară, ca în
// compara-cu-baza.php; tabelele fără cheie simplă se rescriu de la zero) și verificat cu CHECKSUM TABLE.
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
$fisierErori = $dosarDev . '/php-erori.log';
$pozitieErori = is_file($fisierErori) ? (int) filesize($fisierErori) : 0;

$parolaAdmin = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));
$adminEmail = (string) (getenv('CMS_DEV_ADMIN_EMAIL') ?: 'admin@exemplu.ro');
$jarAdmin = $dosarDev . '/test-reparatii-admin-' . getmypid() . '.txt';
$jarViz = $dosarDev . '/test-reparatii-viz-' . getmypid() . '.txt';
$dosarErp = $dosarDev . '/test-reparatii-erp-' . getmypid();
$pidErp = 0;
$licentaSchimbata = false;

$puneLaLoc = static function () use (
    &$pidErp, &$licentaSchimbata, $db, $tabeleBaza, $instantaneu, $radacina, $jarAdmin, $jarViz, $dosarErp, $sesiuniInainte, $amPornitServerul
): void {
    if ($pidErp > 0) {
        exec('pkill -TERM -P ' . $pidErp . ' 2>/dev/null; kill ' . $pidErp . ' 2>/dev/null');
        for ($i = 0; $i < 50 && trim((string) shell_exec('pgrep -P ' . $pidErp . ' 2>/dev/null')) !== ''; $i++) {
            usleep(100000);
        }
        $pidErp = 0;
    }
    if ($licentaSchimbata) {
        exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/tools/dev/licenta.php') . ' toate >/dev/null 2>&1');
        $licentaSchimbata = false;
    }
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    salvari_pune_la_loc($db, $tabeleBaza, $instantaneu);
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    ErpStock::flush();
    foreach (array_diff(glob(Paths::stocare('sessions') . '/sess_*') ?: [], $sesiuniInainte) as $sesiune) {
        @unlink($sesiune);
    }
    foreach ([$jarAdmin, $jarViz] as $jar) {
        @unlink($jar);
    }
    foreach (glob($dosarErp . '/*') ?: [] as $fisier) {
        @unlink($fisier);
    }
    @rmdir($dosarErp);
    if ($amPornitServerul) {
        exec(escapeshellarg($radacina . '/tools/dev/server.sh') . ' --opreste >/dev/null 2>&1');
    }
};
register_shutdown_function(static function () use ($puneLaLoc): void {
    $puneLaLoc();
});

$logareAdmin = static function () use ($cerere, $jarAdmin, $adminEmail, $parolaAdmin): bool {
    @unlink($jarAdmin);
    $r = $cerere('POST', '/admin/login', $jarAdmin, ['email' => $adminEmail, 'password' => $parolaAdmin]);
    return $r['cod'] === 302 && str_starts_with((string) ($r['antete']['location'] ?? ''), '/admin');
};
$setare = static function (string $cheie, ?string $valoare) use ($db): void {
    if ($valoare === null) {
        $db->prepare('DELETE FROM settings WHERE `key` = :k')->execute(['k' => $cheie]);
        return;
    }
    $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (:k, :v) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
        ->execute(['k' => $cheie, 'v' => $valoare]);
};

if (!$logareAdmin()) {
    $scrie('EROARE: login-ul adminului de test nu merge (parola din ' . $dosarDev . '/admin-parola.txt)');
    exit(1);
}

// =====================================================================
// A. Ofertele BBD în admin (C1, C2)
// =====================================================================
$scrie('');
$scrie('== A. Ofertele BBD în admin: rezervările după o schimbare de preț (C1), salvarea fără câmpul ofertelor (C2)');
$rezervare = $db->query("SELECT oi.product_id, oi.bbd_key, SUM(oi.quantity) AS q
    FROM order_items oi JOIN orders o ON o.id = oi.order_id
    WHERE oi.bbd_key IS NOT NULL AND oi.bbd_key <> '' AND o.deleted_at IS NULL
      AND o.status NOT IN ('cancelled', 'failed', 'refunded', 'returned', 'pending_payment')
    GROUP BY oi.product_id, oi.bbd_key ORDER BY oi.product_id, oi.bbd_key LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$produsFereastra = static function (int $id) use ($cerere, $jarAdmin): ?array {
    $pagina = $cerere('GET', '/admin/products', $jarAdmin);
    if (preg_match_all("#data-product='([^']*)'#", $pagina['corp'], $m) > 0) {
        foreach ($m[1] as $json) {
            $produs = json_decode(html_entity_decode($json, ENT_QUOTES), true);
            if (is_array($produs) && (int) ($produs['id'] ?? 0) === $id) {
                return $produs;
            }
        }
    }
    return null;
};
if (!is_array($rezervare)) {
    $verifica('datele demo au o comandă activă pe o ofertă BBD', false);
} else {
    $idProdus = (int) $rezervare['product_id'];
    $cheie = (string) $rezervare['bbd_key'];
    $rezervate = (int) $rezervare['q'];
    $produs = $db->query('SELECT * FROM products WHERE id = ' . $idProdus)->fetch(PDO::FETCH_ASSOC);
    $fereastra = $produsFereastra($idProdus);
    // Ofertele cum le are editorul (fereastra produsului): cu cheia fiecăreia, și când în bază ea lipsește (datele demo).
    $oferte = is_array($fereastra['bbd_entries'] ?? null) ? $fereastra['bbd_entries'] : [];
    $verifica("fereastra produsului #$idProdus: oferta $cheie are $rezervate rezervate de comenzi",
        (int) ($fereastra['bbd_stock_usage'][$cheie] ?? -1) === $rezervate, json_encode($fereastra['bbd_stock_usage'] ?? null));
    $corpProdus = [
        'name' => (string) $produs['name'], 'slug' => (string) $produs['slug'], 'sku' => (string) ($produs['sku'] ?? ''),
        'category_id' => (string) ($produs['category_id'] ?? ''), 'product_template_id' => (string) ($produs['product_template_id'] ?? ''),
        'price' => (string) $produs['price'], 'stock' => (string) $produs['stock'], 'vat_percent' => (string) ($produs['vat_percent'] ?? '19'),
        'vat_included' => (string) ($produs['vat_included'] ?? '1'), 'weight_grams' => (string) ($produs['weight_grams'] ?? ''),
        'brand' => (string) ($produs['brand'] ?? ''), 'short_description' => (string) ($produs['short_description'] ?? ''),
        'description' => (string) ($produs['description'] ?? ''), 'image_url' => (string) ($produs['image_url'] ?? ''),
        'is_active' => '1', 'bbd_enabled' => '1',
    ];
    // Prețul redus al ofertei rezervate schimbat, cheile trimise ca de editor.
    $trimise = [];
    $pretNou = null;
    foreach ($oferte as $oferta) {
        $rand = ['key' => (string) ($oferta['key'] ?? ''), 'date' => (string) ($oferta['date'] ?? ''), 'lot' => (string) ($oferta['lot'] ?? ''),
            'reduced_price' => (string) ($oferta['reduced_price'] ?? ''), 'stock' => (string) ($oferta['stock'] ?? '')];
        if ($rand['key'] === $cheie) {
            $pretNou = number_format((float) $rand['reduced_price'] + 1.25, 2, '.', '');
            $rand['reduced_price'] = $pretNou;
        }
        $trimise[] = $rand;
    }
    $r = $cerere('POST', '/admin/products/' . $idProdus . '/update', $jarAdmin,
        $corpProdus + ['bbd_entries_json' => json_encode($trimise, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $dupa = json_decode($unu('SELECT bbd_entries_json FROM products WHERE id = ' . $idProdus), true) ?: [];
    $oferta = array_values(array_filter($dupa, static fn ($o): bool => is_array($o) && (string) ($o['key'] ?? '') === $cheie))[0] ?? [];
    $verifica("salvarea cu prețul redus schimbat ($pretNou): oferta își păstrează cheia",
        $r['cod'] === 302 && $pretNou !== null && abs((float) ($oferta['reduced_price'] ?? 0) - (float) $pretNou) < 0.001,
        'cod ' . $r['cod'] . ', ' . json_encode($dupa, JSON_UNESCAPED_UNICODE));
    $fereastra = $produsFereastra($idProdus);
    $verifica("… și fereastra produsului numără în continuare cele $rezervate rezervate pe ea",
        (int) ($fereastra['bbd_stock_usage'][$cheie] ?? -1) === $rezervate, json_encode($fereastra['bbd_stock_usage'] ?? null));

    // C2: formularul fără bbd_entries_json (JavaScript-ul editorului căzut); descrierea scurtă schimbată arată că salvarea a ajuns.
    $inainte = $unu('SELECT bbd_entries_json FROM products WHERE id = ' . $idProdus);
    $descriere = 'Descriere scurtă salvată fără câmpul ofertelor (test-reparatii)';
    $r = $cerere('POST', '/admin/products/' . $idProdus . '/update', $jarAdmin, ['short_description' => $descriere] + $corpProdus);
    $randDupa = $db->query('SELECT bbd_entries_json, short_description FROM products WHERE id = ' . $idProdus)->fetch(PDO::FETCH_ASSOC);
    $verifica('salvarea produsului fără câmpul ofertelor le păstrează pe cele din bază (C2)',
        $r['cod'] === 302 && ($randDupa['short_description'] ?? '') === $descriere && ($randDupa['bbd_entries_json'] ?? null) === $inainte
        && $inainte !== '' && $inainte !== '[]', 'cod ' . $r['cod'] . ', ' . json_encode($randDupa, JSON_UNESCAPED_UNICODE));
    $r = $cerere('POST', '/admin/products/' . $idProdus . '/update', $jarAdmin, $corpProdus + ['bbd_entries_json' => '[]']);
    $verifica('… iar cu câmpul trimis gol (ofertele șterse în editor) le șterge, ca până acum',
        $r['cod'] === 302 && $unu('SELECT bbd_entries_json FROM products WHERE id = ' . $idProdus) === '[]',
        $unu('SELECT bbd_entries_json FROM products WHERE id = ' . $idProdus));
    $db->prepare('UPDATE products SET bbd_entries_json = :j, short_description = :s WHERE id = :id')
        ->execute(['j' => $produs['bbd_entries_json'], 's' => $produs['short_description'], 'id' => $idProdus]);
}

// =====================================================================
// B. Postarea cu categoria doar ca text (X1b)
// =====================================================================
$scrie('');
$scrie('== B. Postarea importată cu categoria doar ca text (X1b)');
$idPostare = (int) $unu("SELECT id FROM blog_posts WHERE slug = 'sfaturi-vechi-importate' AND deleted_at IS NULL");
$postare = $db->query('SELECT * FROM blog_posts WHERE id = ' . $idPostare)->fetch(PDO::FETCH_ASSOC) ?: [];
$textCategorie = (string) ($postare['category'] ?? '');
$verifica('datele demo: postarea „sfaturi-vechi-importate” are categoria doar ca text, fără id și fără legături',
    $idPostare > 0 && $textCategorie !== '' && (int) ($postare['category_id'] ?? 0) === 0
    && $unu('SELECT COUNT(*) FROM blog_post_categories WHERE post_id = ' . $idPostare) === '0'
    && $unu('SELECT COUNT(*) FROM blog_categories WHERE name = :n', ['n' => $textCategorie]) === '0',
    json_encode(['id' => $idPostare, 'category' => $textCategorie, 'category_id' => $postare['category_id'] ?? null], JSON_UNESCAPED_UNICODE));
$tabelePostare = [
    ['tabel' => 'blog_posts', 'unde' => 'id = :p1', 'param' => ['p1' => $idPostare], 'cheie' => 'id'],
    ['tabel' => 'blog_post_categories', 'unde' => 'post_id = :p2', 'param' => ['p2' => $idPostare]],
    ['tabel' => 'blog_categories', 'unde' => '1 = 1', 'cheie' => 'id'],
    ['tabel' => 'seo_pages', 'unde' => "page_type = 'blog_post' AND page_ref = :p3", 'param' => ['p3' => (string) $idPostare], 'cheie' => 'id'],
];
$instantaneuPostare = salvari_instantaneu($db, $tabelePostare);
$corpPostare = static fn (array $campuri): array => [
    'action' => 'save', 'id' => (string) $idPostare, 'title' => (string) $postare['title'], 'slug' => (string) $postare['slug'],
    'excerpt' => 'Rezumat schimbat la re-salvare (test-reparatii)', 'content' => (string) ($postare['content'] ?? ''),
    'reading_minutes' => (string) ($postare['reading_minutes'] ?? '1'), 'published_at' => (string) ($postare['published_at'] ?? ''),
    'author_id' => (string) ($postare['author_id'] ?? ''), 'template_id' => (string) ($postare['template_id'] ?? ''),
    'featured_image_url' => (string) ($postare['featured_image_url'] ?? ''),
] + ((int) ($postare['is_published'] ?? 0) === 1 ? ['is_published' => '1'] : []) + $campuri;
// Ce trimite editorul (ca un browser): bifele categoriilor și câmpul ascuns „category”, dacă există.
$campuriDinEditor = static function (string $html): array {
    $campuri = [];
    if (preg_match_all('#name="category_ids\[\]" value="(\d+)" checked>#', $html, $m) > 0) {
        $campuri['category_ids'] = array_values($m[1]);
    }
    if (preg_match('#<input type="hidden" name="category" value="([^"]*)">#', $html, $m) === 1) {
        $campuri['category'] = html_entity_decode($m[1], ENT_QUOTES);
    }
    return $campuri;
};
$editor = $cerere('GET', '/admin/blog/posts/editor?post=' . $idPostare, $jarAdmin);
$campuri = $campuriDinEditor($editor['corp']);
$verifica('editorul arată categoria-text ca notă și o trimite înapoi (câmpul ascuns), fără nicio bifă',
    $editor['cod'] === 200 && str_contains($editor['corp'], 'data-categorie-doar-text')
    && str_contains($editor['corp'], '„' . htmlspecialchars($textCategorie, ENT_QUOTES) . '”')
    && ($campuri['category'] ?? null) === $textCategorie && !isset($campuri['category_ids']),
    'cod ' . $editor['cod'] . ', ' . json_encode($campuri, JSON_UNESCAPED_UNICODE));
$r = $cerere('POST', '/admin/blog/posts', $jarAdmin, $corpPostare($campuri));
$dupa = $db->query('SELECT category, category_id, excerpt FROM blog_posts WHERE id = ' . $idPostare)->fetch(PDO::FETCH_ASSOC) ?: [];
$verifica('re-salvarea păstrează categoria-text (înainte: NULL)', $r['cod'] === 302 && ($dupa['category'] ?? null) === $textCategorie
    && (int) ($dupa['category_id'] ?? 0) === 0 && str_contains((string) ($dupa['excerpt'] ?? ''), 'test-reparatii'),
    'cod ' . $r['cod'] . ', ' . json_encode($dupa, JSON_UNESCAPED_UNICODE));
$db->exec('SET FOREIGN_KEY_CHECKS = 0');
salvari_pune_la_loc($db, $tabelePostare, $instantaneuPostare);
$db->exec('SET FOREIGN_KEY_CHECKS = 1');

// O categorie din listă care se potrivește cu textul: bifată în editor, legată la re-salvare.
$idCategorieNoua = (int) $unu('SELECT COALESCE(MAX(id), 0) + 1 FROM blog_categories');
$slugText = \App\Support\Slug::categorie($textCategorie);
foreach ([
    'același nume' => [$textCategorie, 'categorie-test-reparatii'],
    'numele fără diacritice, cu majuscule' => [mb_strtoupper(str_replace('-', ' ', $slugText)), 'alt-slug-test-reparatii'],
    'alt nume, slug-ul textului' => ['Arhiva de articole (test-reparatii)', $slugText],
] as $cum => [$nume, $slug]) {
    $db->prepare('INSERT INTO blog_categories (id, name, slug, sort_order, is_active) VALUES (:id, :n, :s, 99, 1)')
        ->execute(['id' => $idCategorieNoua, 'n' => $nume, 's' => $slug]);
    $editor = $cerere('GET', '/admin/blog/posts/editor?post=' . $idPostare, $jarAdmin);
    $campuri = $campuriDinEditor($editor['corp']);
    $verifica("categoria „{$nume}” ({$cum}): bifată în editor, fără notă",
        $editor['cod'] === 200 && ($campuri['category_ids'] ?? []) === [(string) $idCategorieNoua]
        && !str_contains($editor['corp'], 'data-categorie-doar-text'), json_encode($campuri, JSON_UNESCAPED_UNICODE));
    $r = $cerere('POST', '/admin/blog/posts', $jarAdmin, $corpPostare($campuri));
    $dupa = $db->query('SELECT category, category_id FROM blog_posts WHERE id = ' . $idPostare)->fetch(PDO::FETCH_ASSOC) ?: [];
    $verifica('… re-salvarea o leagă: categoria principală și legătura',
        $r['cod'] === 302 && (int) ($dupa['category_id'] ?? 0) === $idCategorieNoua && ($dupa['category'] ?? null) === $nume
        && $unu('SELECT GROUP_CONCAT(category_id) FROM blog_post_categories WHERE post_id = ' . $idPostare) === (string) $idCategorieNoua,
        'cod ' . $r['cod'] . ', ' . json_encode($dupa, JSON_UNESCAPED_UNICODE));
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    salvari_pune_la_loc($db, $tabelePostare, $instantaneuPostare);
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
}

// =====================================================================
// C. Adminul: editoarele de cod (C6) și pozele selectoarelor (C7)
// =====================================================================
$scrie('');
$scrie('== C. Adminul: CodeMirror doar în editoarele de cod (C6), pozele selectoarelor leneșe (C7)');
$cdn = 'https://cdn.jsdelivr.net/npm/codemirror@5.65.18/lib/codemirror.min.js';
$idPagina = $unu('SELECT id FROM pages ORDER BY id LIMIT 1');
$idSablon = $unu('SELECT id FROM product_templates ORDER BY id LIMIT 1');
// Al patrulea editor de cod, al șabloanelor de blog: datele demo n-au niciun șablon de blog, deci testul face unul
// (baza se pune la loc la final).
$db->prepare('INSERT INTO blog_templates (name, slug, description, html_content, css_content, js_content, is_active)
    VALUES (?, ?, ?, ?, \'\', \'\', 1)')->execute(['Șablon de test (test-reparatii)', 'sablon-test-reparatii-' . getmypid(), '',
    '<article><h1>{{blog_title}}</h1>{{blog_content}}</article>']);
$idSablonBlog = (int) $db->lastInsertId();
foreach (['/admin/design' => true, '/admin/pages/' . $idPagina . '/edit' => true, '/admin/pages/new' => true,
    '/admin/products/templates/' . $idSablon . '/builder' => true, '/admin/blog/templates/' . $idSablonBlog . '/builder' => true,
    '/admin' => false, '/admin/products' => false, '/admin/orders' => false, '/admin/blog/posts/editor?post=' . $idPostare => false,
    '/admin/settings/store' => false, '/admin/blog/templates' => false, '/admin/blog/authors' => false] as $cale => $cuEditor) {
    $r = $cerere('GET', $cale, $jarAdmin);
    $verifica($cale . ': ' . ($cuEditor ? 'încarcă' : 'nu încarcă') . ' CodeMirror și js-beautify',
        $r['cod'] === 200 && str_contains($r['corp'], $cdn) === $cuEditor && str_contains($r['corp'], 'beautify-html.min.js') === $cuEditor,
        'cod ' . $r['cod']);
}
// Câte poze are fiecare selector (galeria demo, cu filtrul și limita controllerului, ca în compara-cu-baza.php): produse
// și Setări magazin doar „image” (300; Setări magazin are două selectoare), celelalte „image” sau fără tip (500).
$pozeImagine = min(300, (int) $unu("SELECT COUNT(*) FROM gallery_images WHERE media_type = 'image'"));
$pozeSauFaraTip = min(500, (int) $unu("SELECT COUNT(*) FROM gallery_images WHERE media_type = 'image' OR media_type IS NULL"));
foreach (['/admin/products' => $pozeImagine, '/admin/settings/store' => 2 * $pozeImagine,
    '/admin/emails/newsletters?tab=campaigns' => $pozeSauFaraTip, '/admin/blog/posts/editor?post=' . $idPostare => $pozeSauFaraTip,
    '/admin/blog/authors' => $pozeSauFaraTip] as $cale => $pozeAsteptate) {
    $r = $cerere('GET', $cale, $jarAdmin);
    $lenese = substr_count($r['corp'], '<img loading="lazy" decoding="async" src="');
    $verifica($cale . ': fiecare poză a selectorului de imagini (' . $pozeAsteptate . ') are loading="lazy" decoding="async"',
        $r['cod'] === 200 && $pozeAsteptate >= 1 && $lenese === $pozeAsteptate, 'cod ' . $r['cod'] . ', găsite ' . $lenese);
}

// =====================================================================
// D. Garda pluginurilor pe /api/… (X6)
// =====================================================================
$scrie('');
$scrie('== D. Garda pe /api/…, cu licența „prezentare” (fără magazin): JSON-ul are și `error` (X6)');
$licentaSchimbata = true;
exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/tools/dev/licenta.php') . ' prezentare 2>&1', $iesireLicenta, $codLicenta);
$r = $cerere('GET', '/api/cart/summary');
$json = json_decode($r['corp'], true);
$verifica('GET /api/cart/summary: 404 de la gardă, {"ok":false,"message":…,"error":"Pagina nu a fost găsită."}',
    $codLicenta === 0 && $r['cod'] === 404 && isset($r['antete']['x-andaxi-garda']) && is_array($json) && ($json['ok'] ?? null) === false
    && ($json['message'] ?? '') === 'Pagina nu a fost găsită.' && ($json['error'] ?? '') === 'Pagina nu a fost găsită.',
    'cod ' . $r['cod'] . ', ' . $r['corp'] . ' ' . implode(' ', $iesireLicenta));
exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/tools/dev/licenta.php') . ' toate 2>&1', $iesireLicenta, $codLicenta);
$licentaSchimbata = $codLicenta !== 0;
Plugins::reseteaza();
$r = $cerere('GET', '/api/cart/summary');
$verifica('… cu licența „toate” la loc, sumarul coșului răspunde din nou (200)', $r['cod'] === 200, 'cod ' . $r['cod']);

// =====================================================================
// ERP-ul fals, pentru E, F și G
// =====================================================================
@mkdir($dosarErp, 0770, true);
$scrieErp = static function (string $fisier, string $continut) use ($dosarErp): void {
    file_put_contents($dosarErp . '/' . $fisier, $continut);
};
$scrieErp('mod', 'normal');
$scrieErp('cunoscute.json', '{}');
$scrieErp('cereri.jsonl', '');
$pidErp = (int) trim((string) shell_exec('cd ' . escapeshellarg($radacina) . ' && env PHP_CLI_SERVER_WORKERS=4 ERP_FALS_DOSAR='
    . escapeshellarg($dosarErp) . ' nohup ' . escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d log_errors=1 -d '
    . escapeshellarg('error_log=' . $dosarDev . '/php-erori.log') . ' -S 127.0.0.1:' . $portErp . ' tools/dev/lib/erp-fals.php >> '
    . escapeshellarg($dosarErp . '/server.log') . ' 2>&1 & echo $!'));
$erpPornit = false;
for ($i = 0; $i < 50 && !$erpPornit; $i++) {
    $erpPornit = $cerere('GET', $adresaErp . '/api/site/ping')['cod'] === 200;
    $erpPornit || usleep(100000);
}
if (!$erpPornit) {
    $verifica('ERP-ul fals pornește pe ' . $adresaErp, false);
    exit(1);
}
/** @return list<array<string, mixed>> cererile primite de ERP-ul fals (fără ping-ul de pornire). */
$cereriErp = static function () use ($dosarErp): array {
    $randuri = [];
    foreach (file($dosarErp . '/cereri.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linie) {
        $rand = json_decode($linie, true);
        if (is_array($rand) && ($rand['cale'] ?? '') !== '/api/site/ping') {
            $randuri[] = $rand;
        }
    }
    return $randuri;
};
$cereriStoc = static fn (?string $sku = null): int => count(array_filter($cereriErp(), static fn (array $r): bool => ($r['cale'] ?? '') === '/api/site/stoc'
    && ($sku === null || in_array(strtoupper($sku), (array) ($r['skuuri'] ?? []), true))));
$setare('erp_enabled', '1');
$setare('erp_stock_enabled', '1');
$setare('erp_url', $adresaErp);
$setare('erp_api_key', 'cheie-erp-fals');
$setare('erp_timeout', '20');
$fisierCache = Paths::stocare('cache/erp-stock.json');
$fisierEsec = Paths::stocare('cache/erp-stock-esec');

// =====================================================================
// E. Stocul din ERP (C3)
// =====================================================================
$scrie('');
$scrie('== E. Stocul din ERP (C3): SKU necunoscut, ERP căzut, ERP care tace');
$skuMenta = $unu("SELECT sku FROM products WHERE slug = 'ceai-de-menta'");
$skuCunoscut = $unu("SELECT sku FROM products WHERE slug <> 'ceai-de-menta' AND sku IS NOT NULL AND sku <> '' AND deleted_at IS NULL ORDER BY id LIMIT 1");
$scrieErp('cunoscute.json', (string) json_encode([$skuCunoscut => 7]));
ErpStock::flush();
for ($i = 0; $i < 3; $i++) {
    $cerere('GET', '/produs/ceai-de-menta');
}
$verifica("SKU-ul necunoscut $skuMenta: trei afișări ale produsului, o singură întrebare la ERP", $cereriStoc($skuMenta) === 1,
    $cereriStoc($skuMenta) . ' cereri');
$cache = json_decode((string) @file_get_contents($fisierCache), true);
$verifica('… ținut minte în cache-ul stocului, cu momentul răspunsului (necunoscut_la)',
    is_array($cache) && is_numeric($cache[strtoupper($skuMenta)]['necunoscut_la'] ?? null) && (float) ($cache[strtoupper($skuCunoscut)] ?? -1) === 7.0,
    json_encode(array_intersect_key(is_array($cache) ? $cache : [], [strtoupper($skuMenta) => 1, strtoupper($skuCunoscut) => 1])));
ErpStock::flush();
$disponibil = ErpStock::availability($db, [$skuMenta, $skuCunoscut]);
$verifica('availability(): SKU-ul cunoscut cu disponibilul din ERP, cel necunoscut lipsește (rămâne stocul de pe site)',
    $disponibil === [strtoupper($skuCunoscut) => 7.0], json_encode($disponibil));
// Răspunsul ERP-ului de acum 61 de secunde: SKU-ul necunoscut se întreabă din nou.
$cache = json_decode((string) @file_get_contents($fisierCache), true) ?: [];
$cache[strtoupper($skuMenta)] = ['necunoscut_la' => time() - 61];
file_put_contents($fisierCache, json_encode($cache));
$inainte = $cereriStoc($skuMenta);
$cerere('GET', '/produs/ceai-de-menta');
$cerere('GET', '/produs/ceai-de-menta');
$verifica('… după 60 de secunde se întreabă din nou, o dată', $cereriStoc($skuMenta) === $inainte + 1, ($cereriStoc($skuMenta) - $inainte) . ' cereri noi');

// ERP-ul răspunde cu eroare: întrebat o dată, apoi 60 de secunde deloc.
ErpStock::flush();
$scrieErp('mod', '500');
$inainte = $cereriStoc();
$r = $cerere('GET', '/produs/ceai-de-menta');
$dupaPrima = $cereriStoc();
$cerere('GET', '/produs/ceai-de-menta');
$cerere('GET', '/magazin');
$verifica('ERP cu eroare 500: pagina merge (stocul de pe site), ERP-ul întrebat o dată, apoi deloc 60 de secunde',
    $r['cod'] === 200 && $dupaPrima === $inainte + 1 && $cereriStoc() === $dupaPrima && is_file($fisierEsec),
    'cod ' . $r['cod'] . ', cereri: ' . ($dupaPrima - $inainte) . ' la prima afișare, ' . ($cereriStoc() - $dupaPrima) . ' după');
touch($fisierEsec, time() - 61);
$cerere('GET', '/produs/ceai-de-menta');
$verifica('… după 60 de secunde se întreabă din nou', $cereriStoc() === $dupaPrima + 1, ($cereriStoc() - $dupaPrima) . ' cereri noi');
$scrieErp('mod', 'normal');

// ERP-ul tace (răspunde după 10 secunde): pagina așteaptă cel mult timeout-ul scurt.
ErpStock::flush();
$scrieErp('mod', 'incet');
$scrieErp('pauza', '10');
$r = $cerere('GET', '/produs/ceai-de-menta');
$verifica('ERP care tace: pagina produsului vine după timeout-ul scurt (~3 s), nu după erp_timeout (20 s) sau răspunsul ERP-ului (10 s)',
    $r['cod'] === 200 && $r['timp'] >= 2.5 && $r['timp'] < 6.0, sprintf('cod %d, %.2f s', $r['cod'], $r['timp']));
$r = $cerere('GET', '/produs/ceai-de-menta');
$verifica('… iar următoarea afișare nu mai așteaptă deloc (eșecul ținut minte)', $r['cod'] === 200 && $r['timp'] < 1.5,
    sprintf('cod %d, %.2f s', $r['cod'], $r['timp']));
$scrieErp('mod', 'normal');
ErpStock::flush();
sleep(1);

// =====================================================================
// F. Lacătul sesiunii (C4)
// =====================================================================
$scrie('');
$scrie('== F. Lacătul sesiunii (C4): cererile de fundal lente nu țin în loc navigarea');
$idMenta = (int) $unu("SELECT id FROM products WHERE slug = 'ceai-de-menta'");
$scrieErp('cunoscute.json', (string) json_encode([$skuMenta => 50, $skuCunoscut => 7]));
@unlink($jarViz);
$r = $cerere('POST', '/cos/adauga/' . $idMenta, $jarViz, ['quantity' => '1']);
$verifica('vizitatorul are un produs în coș', $r['cod'] === 302 && is_file($jarViz) && str_contains((string) file_get_contents($jarViz), 'PHPSESSID'),
    'cod ' . $r['cod']);
$scrieErp('mod', 'incet');
$scrieErp('pauza', '2');
foreach ([
    'heartbeat-ul coșului (POST /api/cart/heartbeat)' => ['POST', '/api/cart/heartbeat', $jarViz, ['email' => 'test-reparatii@exemplu.ro']],
    'sumarul coșului (GET /api/cart/summary)' => ['GET', '/api/cart/summary', $jarViz],
    'catalogul (GET /api/shop/catalog)' => ['GET', '/api/shop/catalog', $jarViz],
    'cele mai vândute (GET /api/products/best-sellers)' => ['GET', '/api/products/best-sellers?limit=6', $jarViz],
    'martorul negativ: pagina unui produs (ține sesiunea)' => ['GET', '/produs/ceai-de-menta', $jarViz],
] as $ce => $lenta) {
    ErpStock::flush();
    [$rLenta, $rPagina] = $inParalel($lenta, ['GET', '/contact', $jarViz], 0.4);
    $martor = str_starts_with($ce, 'martorul');
    $verifica($ce . ($martor ? ': /contact, cerută în același timp, o așteaptă' : ': /contact, cerută în același timp, nu o așteaptă'),
        in_array($rLenta['cod'], [200, 204], true) && $rPagina['cod'] === 200 && $rLenta['timp'] >= 1.8
        && ($martor ? $rPagina['timp'] >= 1.2 : $rPagina['timp'] < 1.0),
        sprintf('cererea lentă: cod %d, %.2f s; /contact: cod %d, %.2f s', $rLenta['cod'], $rLenta['timp'], $rPagina['cod'], $rPagina['timp']));
}
$scrieErp('mod', 'normal');
ErpStock::flush();
$setare('erp_stock_enabled', '0');
// După eliberare, heartbeat-ul lucrează mai departe cu id-ul sesiunii: coșul vizitatorului e notat (coșurile abandonate).
$sesiune = '';
foreach (file($jarViz) ?: [] as $linie) {
    if (preg_match('/\tPHPSESSID\t(\S+)/', $linie, $m) === 1) {
        $sesiune = $m[1];
    }
}
$verifica('… și heartbeat-ul a notat coșul vizitatorului, cu emailul primit, pe id-ul sesiunii',
    $sesiune !== '' && $unu('SELECT email FROM cart_abandonments WHERE session_id = :s', ['s' => $sesiune]) === 'test-reparatii@exemplu.ro',
    'sesiunea ' . ($sesiune !== '' ? 'găsită' : 'lipsă'));

// =====================================================================
// G. Pragul trimiterii în ERP (E1)
// =====================================================================
$scrie('');
$scrie('== G. Pragul trimiterii în ERP (E1, erp_trimite_din)');
$comenzi = $db->query("SELECT id, order_number FROM orders WHERE deleted_at IS NULL
    AND status NOT IN ('cancelled', 'refunded', 'failed', 'returned') AND payment_method = 'cod'
    AND (preorder_status IS NULL OR preorder_status <> 'asteptare') ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
if (count($comenzi) < 2) {
    $verifica('datele demo au două comenzi ramburs active', false);
} else {
    [$veche, $noua] = $comenzi;
    $prag = date('Y-m-d H:i:s', time() - 3600);
    $puneDeTrimis = static function () use ($db, $veche, $noua, $prag): void {
        $db->exec("UPDATE orders SET erp_status = 'sent' WHERE deleted_at IS NULL");
        // Netrimise și neîncercate niciodată (nicio urmă a ERP-ului): cea veche e istoricul pe care pragul îl ține deoparte.
        $faraUrme = "UPDATE orders SET erp_status = 'pending', erp_attempts = 0, erp_next_retry_at = NULL, erp_order_id = NULL,
            erp_last_error = NULL, erp_synced_at = NULL, erp_last_attempt_at = NULL, created_at = :c WHERE id = :id";
        $db->prepare($faraUrme)->execute(['c' => date('Y-m-d H:i:s', strtotime($prag) - 2 * 86400), 'id' => $veche['id']]);
        $db->prepare($faraUrme)->execute(['c' => date('Y-m-d H:i:s', strtotime($prag) + 600), 'id' => $noua['id']]);
    };
    $cereriComenzi = static fn (): array => array_values(array_map(static fn (array $r): string => (string) ($r['numar'] ?? ''),
        array_filter($cereriErp(), static fn (array $r): bool => ($r['cale'] ?? '') === '/api/comenzi-site/ingest')));
    $contoare = static function () use ($cerere, $jarAdmin): array {
        $r = $cerere('GET', '/admin/settings/erp', $jarAdmin);
        preg_match('#În așteptare</div>\s*<div[^>]*>(\d+)</div>#u', $r['corp'], $asteptare);
        preg_match('#Eșuate</div>\s*<div[^>]*>(\d+)</div>#u', $r['corp'], $esuate);
        preg_match('#data-prag-erp="([a-z]+)"#', $r['corp'], $stare);
        preg_match('#\((\d+) (?:netrimise|așteaptă)#u', $r['corp'], $inainte);
        return ['cod' => $r['cod'], 'in_asteptare' => (int) ($asteptare[1] ?? -1), 'esuate' => (int) ($esuate[1] ?? -1),
            'prag' => (string) ($stare[1] ?? ''), 'inainte_de_prag' => isset($inainte[1]) ? (int) $inainte[1] : null, 'corp' => $r['corp']];
    };
    $randComanda = static function (string $numar) use ($cerere, $jarAdmin): string {
        return $cerere('GET', '/admin/orders?q=' . rawurlencode($numar), $jarAdmin)['corp'];
    };

    // Cu o dată.
    $setare('erp_trimite_din', $prag);
    $puneDeTrimis();
    $c = $contoare();
    $verifica("prag $prag: Setări → ERP ANDAXI numără 1 în așteptare (de după prag) și 1 de dinainte, cu nota pragului",
        $c['cod'] === 200 && $c['in_asteptare'] === 1 && $c['esuate'] === 0 && $c['prag'] === 'data' && $c['inainte_de_prag'] === 1
        && str_contains($c['corp'], date('d.m.Y H:i', strtotime($prag))),
        json_encode(array_diff_key($c, ['corp' => 1])));
    $html = $randComanda((string) $veche['order_number']);
    $verifica('Admin → Comenzi: comanda de dinainte arată „ERP: înainte de legare”, iar 🔄 cere confirmare',
        str_contains($html, 'ERP: înainte de legare')
        && str_contains($html, 'Comanda ' . $veche['order_number'] . ' e de dinainte de legarea cu ERP-ul și nu se trimite automat.'),
        'eticheta sau confirmarea lipsesc');
    $html = $randComanda((string) $noua['order_number']);
    $verifica('… cea de după prag nu', !str_contains($html, 'ERP: înainte de legare') && !str_contains($html, 'e de dinainte de legarea cu ERP-ul'));
    exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' scripts/erp-sync.php 2>&1', $iesireCron, $codCron);
    $textCron = implode("\n", $iesireCron);
    $verifica('cronul erp-sync.php trimite doar comanda de după prag (' . $noua['order_number'] . ') și spune de ce nu și pe cealaltă',
        $codCron === 0 && str_contains($textCron, 'Comenzi încercate: 1 — trimise: 1, eșuate: 0')
        && str_contains($textCron, 'Comenzile plasate înainte de ' . $prag . ' (legarea cu ERP-ul) pe care ERP-ul nu le-a primit niciodată '
            . 'nu se trimit automat.')
        && $cereriComenzi() === [(string) $noua['order_number']],
        'cod ' . $codCron . ', ' . $textCron . ', ERP-ul a primit: ' . json_encode($cereriComenzi()));
    $stari = $db->query('SELECT id, erp_status, erp_order_id FROM orders WHERE id IN (' . (int) $veche['id'] . ', ' . (int) $noua['id'] . ') ORDER BY id')
        ->fetchAll(PDO::FETCH_ASSOC);
    $verifica('… în bază: cea de după prag trimisă (FALS-' . $noua['order_number'] . '), cealaltă în așteptare',
        ($stari[0]['erp_status'] ?? '') === 'pending' && ($stari[1]['erp_status'] ?? '') === 'sent'
        && ($stari[1]['erp_order_id'] ?? '') === 'FALS-' . $noua['order_number'], json_encode($stari));
    $c = $contoare();
    $verifica('… iar contoarele spun același lucru: 0 în așteptare, 1 de dinainte', $c['in_asteptare'] === 0 && $c['inainte_de_prag'] === 1,
        json_encode(array_diff_key($c, ['corp' => 1])));
    // Ca formularul paginii: butonul trimite și setările (cheia goală o păstrează pe cea salvată).
    $r = $cerere('POST', '/admin/settings/erp', $jarAdmin, ['action' => 'retry', 'erp_enabled' => '1', 'erp_url' => $adresaErp,
        'erp_api_key' => '', 'erp_timeout' => '20']);
    $flash = $cerere('GET', '/admin/settings/erp', $jarAdmin)['corp'];
    $verifica('„Retrimite” din Setări → ERP: nimic de retrimis, cu nota pragului', $r['cod'] === 302
        && str_contains($flash, 'Nu există comenzi de retrimis acum.')
        && str_contains($flash, '(legarea cu ERP-ul) pe care ERP-ul nu le-a primit niciodată nu se trimit de aici')
        && count($cereriComenzi()) === 1, 'cod ' . $r['cod']);
    $r = $cerere('POST', '/admin/orders/' . (int) $veche['id'] . '/erp-retry', $jarAdmin);
    $verifica('🔄 pe comanda de dinainte o trimite de mână', $r['cod'] === 302
        && $unu('SELECT erp_status FROM orders WHERE id = ' . (int) $veche['id']) === 'sent'
        && $cereriComenzi() === [(string) $noua['order_number'], (string) $veche['order_number']], 'cod ' . $r['cod'] . ', ERP-ul a primit '
        . json_encode($cereriComenzi()) . ', eroarea: ' . $unu('SELECT erp_last_error FROM orders WHERE id = ' . (int) $veche['id']));

    // Comanda de dinainte deja în fluxul ERP-ului (revizuirea WP-12): pragul ține deoparte doar istoricul pe care ERP-ul nu
    // l-a văzut niciodată. Cu fiecare urmă pe rând, pe comanda de dinainte (cea de după prag rămâne de trimis): contoarele o
    // numără la „Eșuate” sau „În așteptare”, nu la cele de dinainte, rândul ei din Comenzi n-are „înainte de legare”, iar
    // retryPending le trimite pe amândouă. Urma „încercată de la prag” e făcută chiar de 🔄, cu ERP-ul fals căzut.
    $urme = [
        'ajunsă în ERP (referința erp_order_id), cu o modificare eșuată' => ['failed', static function () use ($db, $veche): void {
            $db->prepare("UPDATE orders SET erp_status = 'failed', erp_order_id = :ref, erp_attempts = 2,
                erp_last_error = 'ERP-ul nu a răspuns (test-reparatii).' WHERE id = :id")
                ->execute(['ref' => 'FALS-' . $veche['order_number'], 'id' => $veche['id']]);
        }],
        'trimisă o dată (erp_synced_at), fără referință, cu o modificare eșuată' => ['failed', static function () use ($db, $veche, $prag): void {
            $db->prepare("UPDATE orders SET erp_status = 'failed', erp_synced_at = :la, erp_attempts = 2,
                erp_last_error = 'ERP-ul nu a răspuns (test-reparatii).' WHERE id = :id")
                ->execute(['la' => date('Y-m-d H:i:s', strtotime($prag) - 86400), 'id' => $veche['id']]);
        }],
        'trimisă de mână (🔄) de la prag, cu ERP-ul căzut' => ['failed', static function () use ($db, $veche, $cerere, $jarAdmin, $scrieErp, $unu, $verifica): void {
            $scrieErp('mod', '500');
            $cerere('POST', '/admin/orders/' . (int) $veche['id'] . '/erp-retry', $jarAdmin);
            $scrieErp('mod', 'normal');
            $verifica('… 🔄 cu ERP-ul căzut: comanda e „eșuată”, cu încercarea notată', $unu('SELECT CONCAT(erp_status, \'/\', erp_attempts, \'/\',
                erp_last_attempt_at IS NOT NULL) FROM orders WHERE id = ' . (int) $veche['id']) === 'failed/1/1');
            // Termenul reîncercării (backoff) a venit.
            $db->prepare('UPDATE orders SET erp_next_retry_at = NULL WHERE id = :id')->execute(['id' => $veche['id']]);
        }],
        'ținută de modulul „Magazin online” oprit în ERP (repornit între timp)' => ['pending', static function () use ($db, $veche): void {
            $db->prepare("UPDATE orders SET erp_status = 'pending', erp_last_error = :e WHERE id = :id")
                ->execute(['e' => \App\Support\ErpModul::mesaj(\App\Support\ErpModul::OPRIT), 'id' => $veche['id']]);
        }],
    ];
    foreach ($urme as $descriere => [$stareErp, $pregateste]) {
        $scrieErp('cereri.jsonl', '');
        $puneDeTrimis();
        $pregateste();
        $scrieErp('cereri.jsonl', '');
        $c = $contoare();
        $verifica('comanda de dinainte ' . $descriere . ': contoarele o numără la „' . ($stareErp === 'failed' ? 'Eșuate' : 'În așteptare')
            . '”, 0 de dinainte', $c['in_asteptare'] === ($stareErp === 'failed' ? 1 : 2) && $c['esuate'] === ($stareErp === 'failed' ? 1 : 0)
            && $c['prag'] === 'data' && $c['inainte_de_prag'] === 0, json_encode(array_diff_key($c, ['corp' => 1])));
        $html = $randComanda((string) $veche['order_number']);
        $verifica('… în Comenzi fără „ERP: înainte de legare” și fără confirmarea ei',
            !str_contains($html, 'ERP: înainte de legare') && !str_contains($html, 'e de dinainte de legarea cu ERP-ul'));
        $rezultat = ErpSync::retryPending($db);
        $verifica('… retryPending o reîncearcă (și pe cea de după prag): ERP-ul le primește pe amândouă, cea veche e trimisă',
            $rezultat['incercate'] === 2 && $rezultat['reusite'] === 2
            && $cereriComenzi() === [(string) $veche['order_number'], (string) $noua['order_number']]
            && $unu('SELECT erp_status FROM orders WHERE id = ' . (int) $veche['id']) === 'sent',
            json_encode($rezultat) . ', ERP-ul a primit ' . json_encode($cereriComenzi()));
    }

    // Fără prag (''): ca până acum.
    $scrieErp('cereri.jsonl', '');
    $setare('erp_trimite_din', '');
    $puneDeTrimis();
    $c = $contoare();
    $verifica("prag '' (fără prag): 2 în așteptare, nicio notă de prag", $c['in_asteptare'] === 2 && $c['prag'] === '' && $c['inainte_de_prag'] === null,
        json_encode(array_diff_key($c, ['corp' => 1])));
    $rezultat = ErpSync::retryPending($db);
    $verifica("… retryPending le trimite pe amândouă (prag „fara”)", $rezultat['incercate'] === 2 && $rezultat['reusite'] === 2
        && $rezultat['prag'] === 'fara' && count($cereriComenzi()) === 2, json_encode($rezultat) . ', erorile: '
        . $unu('SELECT GROUP_CONCAT(erp_last_error SEPARATOR \' | \') FROM orders WHERE id IN (' . (int) $veche['id'] . ', ' . (int) $noua['id'] . ')'));

    // Cheia lipsă (pasul de activare n-a rulat) sau greșită: nimic automat.
    foreach (['lipsa' => null, 'gresit' => 'de ieri'] as $stare => $valoare) {
        $scrieErp('cereri.jsonl', '');
        $setare('erp_trimite_din', $valoare);
        $puneDeTrimis();
        $c = $contoare();
        $verifica('prag ' . ($valoare === null ? 'lipsă' : '„' . $valoare . '”') . ": 0 în așteptare, 2 care așteaptă pragul, nota „{$stare}”",
            $c['in_asteptare'] === 0 && $c['prag'] === $stare && $c['inainte_de_prag'] === 2, json_encode(array_diff_key($c, ['corp' => 1])));
        $rezultat = ErpSync::retryPending($db);
        $verifica('… retryPending nu trimite nimic', $rezultat['incercate'] === 0 && $rezultat['prag'] === $stare && $cereriComenzi() === [],
            json_encode($rezultat));
    }
}

// =====================================================================
// Final: totul la loc
// =====================================================================
$scrie('');
$scrie('== Final: baza, licența, cache-ul și sesiunile la loc');
$puneLaLoc();
$sumeDupa = $sumeBaza();
$diferite = array_keys(array_diff_assoc($sumeDupa, $sumeInainte) + array_diff_assoc($sumeInainte, $sumeDupa));
$verifica('baza e exact cum era (CHECKSUM TABLE pe toate cele ' . count($sumeInainte) . ' tabele)', $diferite === [], implode(', ', $diferite));
$verifica('ERP-ul fals e oprit', $cerere('GET', $adresaErp . '/api/site/ping', null, [], 2)['cod'] === 0);
$eroriNoi = is_file($fisierErori) ? (string) @file_get_contents($fisierErori, false, null, $pozitieErori) : '';
$verifica('nicio eroare PHP nouă în ' . basename($fisierErori), trim($eroriNoi) === '', mb_substr($eroriNoi, 0, 800));

$scrie('');
$scrie(sprintf('Rezultat: %d trecute, %d eșuate. Raport: %s', $trecute, $esuate, $fisierRaport));
exit($esuate === 0 ? 0 : 1);
