<?php

declare(strict_types=1);

/**
 * Testele porturilor din Biovitality și grafoanaytis ale catalogului, banilor și recenziilor (planul Etapei 1: WP-19,
 * WP-20, WP-21), cu serverul de dezvoltare, pe baza de test cu datele demo.
 *
 *   tools/dev/php.sh tools/dev/test-porturi-bv.php
 *
 * WP-19 — catalogul (C8, C14b + GR-08, C14c, C14d, C14e, GR-09, GR-16, GR-17):
 * A. App\Support\ProductOrder și App\Support\Catalog fără server: așezarea manuală (identitate fără nicio poziție,
 *    exemplul [D, C, B, A] → [D, A, C, B], egalități, poziții peste listă, 300 de liste la întâmplare fără produs
 *    pierdut sau dublat), sortarea „Ordinea din catalog”, slug-ul paginii catalogului, sortarea implicită.
 * B. Linkul de ciornă (C8): un produs inactiv, cu catalog_ascunde_inactive = 1, nu apare în magazin, în categorie, în
 *    API și în numărătoarea categoriei, iar pagina lui dă 404; cu cheia lui (?draft=) se vede, cu bara de ciornă,
 *    `Cache-Control: no-store, private` și `X-Robots-Tag: noindex, nofollow`, și nu intră niciodată în cache-ul paginilor
 *    (o pagină obișnuită da, ca martor); o cheie greșită sau goală nu merge; cu 0, ca înainte (în liste, fără bară).
 * C. Ascuns din catalog (C14c): lipsește din {{shop_catalog}}, din API și din numărătoare, dar are pagina lui, e în
 *    harta site-ului și se poate pune în coș.
 * D. Ordinea (C14b, GR-08): cu catalog_sortare_implicita = catalog catalogul și API-ul urmează pozițiile (cele fără
 *    poziție la urmă, alfabetic), opțiunea „Ordinea din catalog” apare prima; fără setare, ?sort=catalog dă alfabetica
 *    și opțiunea lipsește; poziția 1 aduce produsul primul pe prima pagină (ProductOrder::aseaza în loadProducts).
 * E. Pagina catalogului (GR-09): catalog_pagina = produse → /produse arată catalogul (filtrul categoriei, căutarea cu
 *    noindex), /magazin face 301 la /produse cu parametrii, harta site-ului are /produse și nu /magazin, linkurile de
 *    rezervă (pagina 404) duc acolo.
 * F. Câmpurile suplimentare (C14d): un câmp „html” e markup cu catalog_camp_html_brut = 1 și text escapat cu 0; unul
 *    „text” e mereu escapat; un câmp „Imagine” și unul „doar la șabloanele care îl folosesc” nu intră în lista paginii
 *    implicite și nici ca tab; adminul salvează tipul „Imagine” și bifa.
 * G. {{products_by_template:…}} (C14e): doar produsele active ale șablonului, cu limita, titlul, subtitlul și bannerul;
 *    un link `javascript:` nu intră; un șablon inexistent nu lasă nimic; codul în editorul paginilor.
 * H. GR-16, GR-17: {{product_produse_similare}} (banda simplă, titlul din setări, „Vezi produsul”),
 *    catalog_sortare_vizibila = 0 fără butonul sortării, {{produse_serviciu}} pe o pagină de serviciu.
 * I. Adminul: editorul produsului salvează poziția și bifa „ascuns din catalog” (și le păstrează când formularul nu le
 *    trimite), un produs nou primește cheia de ciornă, lista arată linkul de ciornă.
 *
 * Toată baza (fiecare tabel) e pusă la loc la final, verificat cu CHECKSUM TABLE; la fel licența „toate”, cache-ul
 * paginilor și sesiunile făcute aici. Serverul de dezvoltare se oprește la final dacă l-a pornit testul. Raportul:
 * $CMS_DEV_DIR/test-porturi-bv-raport.txt; cod de ieșire 0 doar dacă totul a trecut. Date inventate.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';
require_once __DIR__ . '/lib/salvari-admin.php';

use App\Support\Catalog;
use App\Support\Database;
use App\Support\Paths;
use App\Support\ProductOrder;
use App\Support\ResponseCache;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-porturi-bv-raport.txt';
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
$scrie('Test porturi BV/GR (WP-19…WP-21) ANDAXI-CMS — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

$port = (int) (getenv('CMS_DEV_PORT') ?: '8701');
$baza = 'http://127.0.0.1:' . $port;

/** O cerere spre serverul de dezvoltare; antetele cu numele scris mic. */
$cerere = static function (string $metoda, string $cale, ?string $jar = null, array $date = [], int $timeout = 60) use ($baza): array {
    $antete = [];
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
    $corp = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $cod, 'antete' => $antete, 'corp' => $corp];
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

// ── Instantaneul întregii baze: fiecare tabel, pus la loc la final și verificat cu CHECKSUM TABLE.
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
$jarAdmin = $dosarDev . '/test-porturi-bv-admin-' . getmypid() . '.txt';
$jarViz = $dosarDev . '/test-porturi-bv-viz-' . getmypid() . '.txt';
$licentaSchimbata = false;

$puneLaLoc = static function () use (&$licentaSchimbata, $db, $tabeleBaza, $instantaneu, $radacina, $jarAdmin, $jarViz, $sesiuniInainte, $amPornitServerul): void {
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
    foreach ([$jarAdmin, $jarViz] as $jar) {
        @unlink($jar);
    }
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
/** Slug-urile produselor din cardurile catalogului ({{shop_catalog}}), în ordinea din pagină. */
$sluguriCatalog = static function (string $html): array {
    $sectiune = strstr($html, 'data-shop-catalog-token="1"') ?: '';
    preg_match_all('#<h3 class="bv-popular-card__name"><a href="/produs/([^"]+)"#', $sectiune, $m);
    return array_map('rawurldecode', $m[1]);
};
/** Slug-urile din API-ul catalogului, în ordinea lui. */
$sluguriApi = static function (string $query = '') use ($cerere): array {
    $json = json_decode($cerere('GET', '/api/shop/catalog' . $query)['corp'], true);
    return array_map(static fn (array $p): string => (string) ($p['slug'] ?? ''), (array) ($json['items'] ?? []));
};
/** Numărul de produse arătat lângă o categorie în filtrul catalogului. */
$numarCategorie = static function (string $html, string $categorie): ?int {
    return preg_match('#data-filter-value="' . preg_quote($categorie, '#') . '"[^>]*>\s*' . preg_quote($categorie, '#') . ' \((\d+)\)#u', $html, $m) === 1
        ? (int) $m[1] : null;
};

if (!$logareAdmin()) {
    $scrie('EROARE: login-ul adminului de test nu merge (parola din ' . $dosarDev . '/admin-parola.txt)');
    exit(1);
}

// =====================================================================
// WP-19 A. ProductOrder și Catalog, fără server
// =====================================================================
$scrie('');
$scrie('== WP-19 A. ProductOrder și Catalog');
$p = static fn (string $nume, mixed $poz = null, int $id = 0): array => ['id' => $id, 'name' => $nume, 'sort_position' => $poz];
$nume = static fn (array $lista): string => implode('', array_map(static fn (array $x): string => (string) $x['name'], $lista));
$faraPozitii = [$p('D', null, 4), $p('C', 0, 3), $p('B', '', 2), $p('A', 'x', 1)];
$verifica('aseaza: fără nicio poziție (NULL, 0, gol, text) lista rămâne exact aceeași', ProductOrder::aseaza($faraPozitii) === $faraPozitii);
$verifica('aseaza: [D, C, B, A] cu A pe poziția 2 → [D, A, C, B]',
    $nume(ProductOrder::aseaza([$p('D'), $p('C'), $p('B'), $p('A', 2)])) === 'DACB');
$verifica('aseaza: două produse cu aceeași poziție → cel care venea mai devreme ia locul, celălalt următorul',
    $nume(ProductOrder::aseaza([$p('D'), $p('C', 1), $p('B'), $p('A', 1)])) === 'CADB');
$verifica('aseaza: o poziție peste lungimea listei → ultimul', $nume(ProductOrder::aseaza([$p('D', 99), $p('C'), $p('B')])) === 'CBD');
$verifica('aseaza: lista goală', ProductOrder::aseaza([]) === []);
mt_srand(19);
$bune = true;
for ($k = 0; $k < 300; $k++) {
    $lista = [];
    $n = mt_rand(1, 15);
    for ($j = 0; $j < $n; $j++) {
        $lista[] = $p('p' . $j, mt_rand(0, 3) === 0 ? mt_rand(1, 20) : null, $j);
    }
    $rez = ProductOrder::aseaza($lista);
    $ids = array_column($rez, 'id');
    sort($ids);
    if ($ids !== range(0, $n - 1)) {
        $bune = false;
        break;
    }
}
$verifica('aseaza: 300 de liste la întâmplare, niciun produs pierdut sau dublat', $bune);
$verifica('dupaCatalog: pozițiile crescător, apoi cele fără poziție, alfabetic',
    $nume(ProductOrder::dupaCatalog([$p('b', null, 1), $p('Z', 2, 2), $p('a', null, 3), $p('M', 1, 4), $p('C', 0, 5)])) === 'MZabC');
$verifica('dinFormular: „3” → 3; „0”, „-2”, „” și un tablou → NULL',
    ProductOrder::dinFormular('3') === 3 && ProductOrder::dinFormular('0') === null && ProductOrder::dinFormular('-2') === null
    && ProductOrder::dinFormular('') === null && ProductOrder::dinFormular(['1']) === null);
$verifica('Catalog::pagina: „produse”, „Servicii/Lipire”→ litere mici, „../x”, „a b” și gol → „magazin”',
    Catalog::pagina(['catalog_pagina' => 'produse']) === 'produse' && Catalog::pagina(['catalog_pagina' => '/Servicii/Lipire/']) === 'servicii/lipire'
    && Catalog::pagina(['catalog_pagina' => '../x']) === 'magazin' && Catalog::pagina(['catalog_pagina' => 'a b']) === 'magazin'
    && Catalog::pagina([]) === 'magazin' && Catalog::url(['catalog_pagina' => 'produse']) === '/produse');
$verifica('Catalog::sortareImplicita: catalog, price_desc; o valoare necunoscută → alpha_asc',
    Catalog::sortareImplicita(['catalog_sortare_implicita' => 'catalog']) === 'catalog'
    && Catalog::sortareImplicita(['catalog_sortare_implicita' => 'price_desc']) === 'price_desc'
    && Catalog::sortareImplicita(['catalog_sortare_implicita' => 'nu']) === 'alpha_asc');
$verifica('Catalog::conditieActive: cu 1 „ AND p.is_active = 1”, cu 0 nimic',
    Catalog::conditieActive(['catalog_ascunde_inactive' => '1'], 'p') === ' AND p.is_active = 1'
    && Catalog::conditieActive(['catalog_ascunde_inactive' => '0'], 'p') === '');
$cheie = Catalog::cheieCiorna();
$verifica('Catalog::cheieCiorna: 32 de caractere hexazecimale; cheiePotrivita doar pe cheia exactă, nevidă',
    preg_match('/^[0-9a-f]{32}$/', $cheie) === 1 && Catalog::cheiePotrivita(['preview_token' => $cheie], $cheie)
    && !Catalog::cheiePotrivita(['preview_token' => $cheie], substr($cheie, 0, 31)) && !Catalog::cheiePotrivita(['preview_token' => ''], '')
    && !Catalog::cheiePotrivita(['preview_token' => $cheie], ''));

// =====================================================================
// WP-19 B. Linkul de ciornă (C8)
// =====================================================================
$scrie('');
$scrie('== WP-19 B. Produsele inactive și linkul de ciornă (C8)');
$idInactiv = (int) $unu("SELECT id FROM products WHERE is_active = 0 AND deleted_at IS NULL AND category = 'Ceaiuri' ORDER BY id LIMIT 1");
$slugInactiv = $unu('SELECT slug FROM products WHERE id = :id', ['id' => $idInactiv]);
$cheieInactiv = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
$db->prepare('UPDATE products SET preview_token = :c WHERE id = :id')->execute(['c' => $cheieInactiv, 'id' => $idInactiv]);
$activeCeaiuri = (int) $unu("SELECT COUNT(*) FROM products WHERE category = 'Ceaiuri' AND deleted_at IS NULL AND is_active = 1");
$toateCeaiuri = (int) $unu("SELECT COUNT(*) FROM products WHERE category = 'Ceaiuri' AND deleted_at IS NULL");
$verifica('datele demo: un ceai inactiv („' . $slugInactiv . '”)', $idInactiv > 0 && $slugInactiv !== '' && $toateCeaiuri > $activeCeaiuri);

$setare('catalog_ascunde_inactive', '1');
$magazin = $cerere('GET', '/magazin')['corp'];
$verifica('cu catalog_ascunde_inactive = 1: produsul inactiv lipsește din /magazin', !in_array($slugInactiv, $sluguriCatalog($magazin), true));
$verifica('… și din /categorie/ceaiuri', !in_array($slugInactiv, $sluguriCatalog($cerere('GET', '/categorie/ceaiuri')['corp']), true));
$verifica('… și din /api/shop/catalog', !in_array($slugInactiv, $sluguriApi(), true));
$verifica('… numărătoarea categoriei „Ceaiuri” = ' . $activeCeaiuri . ' (doar cele active)', $numarCategorie($magazin, 'Ceaiuri') === $activeCeaiuri,
    'găsit ' . var_export($numarCategorie($magazin, 'Ceaiuri'), true));
$r = $cerere('GET', '/produs/' . $slugInactiv);
$verifica('… pagina lui fără cheie: 404', $r['cod'] === 404, 'cod ' . $r['cod']);
$verifica('… cu o cheie greșită: 404', $cerere('GET', '/produs/' . $slugInactiv . '?draft=' . str_repeat('0', 32))['cod'] === 404);
$verifica('… cu cheia goală: 404', $cerere('GET', '/produs/' . $slugInactiv . '?draft=')['cod'] === 404);
$r = $cerere('GET', '/produs/' . $slugInactiv . '?draft=' . $cheieInactiv);
$verifica('… cu cheia lui: 200, cu bara de ciornă', $r['cod'] === 200 && str_contains($r['corp'], 'class="produs-ciorna"')
    && str_contains($r['corp'], 'Pagina se vede doar prin acest link'), 'cod ' . $r['cod']);
$verifica('… fără cache și fără indexare (Cache-Control: no-store, private; X-Robots-Tag: noindex, nofollow)',
    ($r['antete']['cache-control'] ?? '') === 'no-store, private' && ($r['antete']['x-robots-tag'] ?? '') === 'noindex, nofollow',
    json_encode(array_intersect_key($r['antete'], ['cache-control' => 1, 'x-robots-tag' => 1])));
// Cache-ul paginilor (ResponseCache::finishRequest) păstrează doar un răspuns pe care isResponseCacheable îl acceptă:
// antetele ciornei îl refuză; martorul, aceeași pagină fără ele, e acceptat. Pe serverul de dezvoltare orice pagină de
// produs deschide sesiunea (Set-Cookie), deci nu se poate măsura un HIT pe /produs/*; se verifică regula însăși.
$cacheabil = new ReflectionMethod(ResponseCache::class, 'isResponseCacheable');
$antetePagina = ['Content-Type: text/html; charset=utf-8'];
$verifica('cache-ul paginilor (martor): o pagină HTML 200 fără antetele ciornei ar intra în cache',
    $cacheabil->invoke(null, 200, $antetePagina, '<html>x</html>') === true);
$verifica('… pagina de ciornă, cu antetele ei, nu intră niciodată',
    $cacheabil->invoke(null, 200, array_merge($antetePagina, ['Cache-Control: no-store, private', 'X-Robots-Tag: noindex, nofollow']), '<html>x</html>') === false);
$setare('cache_pages_enabled', '1');
$setare('cache_pages_rules_json', json_encode([['path_pattern' => '/produs/*', 'ttl_seconds' => 600]]));
ResponseCache::purgePageCache();
$cerere('GET', '/produs/' . $slugInactiv . '?draft=' . $cheieInactiv);
$r = $cerere('GET', '/produs/' . $slugInactiv . '?draft=' . $cheieInactiv);
$verifica('… cu cache-ul pornit pe /produs/*: a doua cerere a ciornei tot BYPASS, cu bara',
    ($r['antete']['x-page-cache'] ?? '') === 'BYPASS' && str_contains($r['corp'], 'class="produs-ciorna"'),
    'x-page-cache: ' . ($r['antete']['x-page-cache'] ?? '-'));
$setare('cache_pages_enabled', '0');
$setare('cache_pages_rules_json', null);
ResponseCache::purgePageCache();
$verifica('… produsul inactiv nu se poate pune în coș (nici de pe linkul de ciornă)',
    $cerere('POST', '/cos/adauga/' . $idInactiv, $jarViz, ['quantity' => '1'])['cod'] === 302
    && !str_contains($cerere('GET', '/api/cart/summary', $jarViz)['corp'], '"product_id":' . $idInactiv . ','));

$setare('catalog_ascunde_inactive', '0');
$magazin = $cerere('GET', '/magazin')['corp'];
$verifica('cu catalog_ascunde_inactive = 0 (Bioscem înghețat): produsul inactiv e în /magazin, ca înainte',
    in_array($slugInactiv, $sluguriCatalog($magazin), true) && $numarCategorie($magazin, 'Ceaiuri') === $toateCeaiuri);
$r = $cerere('GET', '/produs/' . $slugInactiv);
$verifica('… pagina lui: 200, fără bara de ciornă și fără antetele ciornei', $r['cod'] === 200 && !str_contains($r['corp'], 'produs-ciorna')
    && !isset($r['antete']['x-robots-tag']));
$r = $cerere('GET', '/produs/' . $slugInactiv . '?draft=' . $cheieInactiv);
$verifica('… linkul de ciornă merge oricum (bara, no-store)', $r['cod'] === 200 && str_contains($r['corp'], 'produs-ciorna')
    && ($r['antete']['cache-control'] ?? '') === 'no-store, private');
$setare('catalog_ascunde_inactive', null);
$verifica('fără rândul setării (implicitul nucleului, 1): pagina produsului inactiv dă 404', $cerere('GET', '/produs/' . $slugInactiv)['cod'] === 404);

// =====================================================================
// WP-19 C. Ascuns din catalog (C14c)
// =====================================================================
$scrie('');
$scrie('== WP-19 C. Ascuns din lista magazinului (C14c)');
$idAscuns = (int) $unu("SELECT id FROM products WHERE slug = 'ceai-de-tei'");
$db->prepare('UPDATE products SET hidden_in_catalog = 1 WHERE id = :id')->execute(['id' => $idAscuns]);
$magazin = $cerere('GET', '/magazin')['corp'];
$verifica('produsul ascuns lipsește din {{shop_catalog}} (/magazin, /categorie/ceaiuri) și din API',
    !in_array('ceai-de-tei', $sluguriCatalog($magazin), true)
    && !in_array('ceai-de-tei', $sluguriCatalog($cerere('GET', '/categorie/ceaiuri')['corp']), true)
    && !in_array('ceai-de-tei', $sluguriApi(), true));
$verifica('… numărătoarea categoriei scade cu 1', $numarCategorie($magazin, 'Ceaiuri') === $activeCeaiuri - 1,
    'găsit ' . var_export($numarCategorie($magazin, 'Ceaiuri'), true));
$verifica('… are pagina lui (200)', $cerere('GET', '/produs/ceai-de-tei')['cod'] === 200);
$verifica('… e în harta site-ului', str_contains($cerere('GET', '/sitemap.xml')['corp'], '/produs/ceai-de-tei<'));
$verifica('… se poate pune în coș', $cerere('POST', '/cos/adauga/' . $idAscuns, $jarViz, ['quantity' => '1'])['cod'] === 302
    && str_contains($cerere('GET', '/api/cart/summary', $jarViz)['corp'], 'Ceai de tei'));
$db->prepare('UPDATE products SET hidden_in_catalog = 0 WHERE id = :id')->execute(['id' => $idAscuns]);

// =====================================================================
// WP-19 D. Ordinea (C14b, GR-08)
// =====================================================================
$scrie('');
$scrie('== WP-19 D. Ordinea din catalog și poziția în liste (C14b, GR-08)');
$alfabetic = $sluguriCatalog($cerere('GET', '/magazin')['corp']);
$db->exec("UPDATE products SET sort_position = 1 WHERE slug = 'probiotic-10-tulpini'");
$db->exec("UPDATE products SET sort_position = 2 WHERE slug = 'ceai-de-tei'");
$db->exec("UPDATE products SET sort_position = 2 WHERE slug = 'zinc-15-mg'");
$magazin = $cerere('GET', '/magazin')['corp'];
$verifica('fără catalog_sortare_implicita: catalogul rămâne alfabetic (pozițiile nu schimbă sortarea), fără opțiunea „Ordinea din catalog”',
    $sluguriCatalog($magazin) === $alfabetic && !str_contains($magazin, 'Ordinea din catalog'));
$verifica('… ?sort=catalog dă tot alfabetica', $sluguriCatalog($cerere('GET', '/magazin?sort=catalog')['corp']) === $alfabetic
    && (json_decode($cerere('GET', '/api/shop/catalog?sort=catalog')['corp'], true)['sort'] ?? '') === 'alpha_asc');
$setare('catalog_sortare_implicita', 'catalog');
$magazin = $cerere('GET', '/magazin')['corp'];
$ordine = $sluguriCatalog($magazin);
$verifica('cu catalog_sortare_implicita = catalog: întâi pozițiile (1, apoi cele două cu 2, alfabetic), restul alfabetic',
    array_slice($ordine, 0, 3) === ['probiotic-10-tulpini', 'ceai-de-tei', 'zinc-15-mg']
    && array_slice($ordine, 3) === array_values(array_diff($alfabetic, ['probiotic-10-tulpini', 'ceai-de-tei', 'zinc-15-mg'])),
    implode(',', array_slice($ordine, 0, 6)));
$verifica('… opțiunea „Ordinea din catalog” e prima și aleasă', preg_match('#sort-btn" data-shop-sort-toggle>\s*Ordinea din catalog#u', $magazin) === 1
    && strpos($magazin, '?sort=catalog') < strpos($magazin, '?sort=alpha_asc'));
$verifica('… API-ul urmează aceeași ordine', array_slice($sluguriApi(), 0, 3) === ['probiotic-10-tulpini', 'ceai-de-tei', 'zinc-15-mg']);
$verifica('… ?sort=alpha_asc rămâne alfabetica', $sluguriCatalog($cerere('GET', '/magazin?sort=alpha_asc')['corp']) === $alfabetic);
$setare('catalog_sortare_implicita', null);
// Listele în ordinea bazei (cel mai nou primul), ca {{products_by_template:…}}: poziția 1 aduce produsul primul, iar cele
// două cu poziția 2 urmează în ordinea lor de dinainte (cel mai nou primul), restul se dau la o parte.
$slugSablonD = $unu('SELECT slug FROM product_templates ORDER BY id LIMIT 1');
$db->exec("INSERT INTO pages (title, slug, html_content, is_published, created_at, updated_at) VALUES ('Lista test', 'lista-test',
    '{{products_by_template:" . $slugSablonD . "|limit=4}}', 1, NOW(), NOW())");
preg_match_all('#<h3 class="bv-popular-card__name"><a href="/produs/([^"]+)"#', $cerere('GET', '/lista-test')['corp'], $m);
$celeMaiNoi = array_column($db->query('SELECT slug FROM products WHERE product_template_id = (SELECT id FROM product_templates ORDER BY id LIMIT 1)
    AND deleted_at IS NULL AND is_active = 1 AND slug NOT IN (\'probiotic-10-tulpini\', \'ceai-de-tei\', \'zinc-15-mg\') ORDER BY id DESC LIMIT 1')->fetchAll(PDO::FETCH_ASSOC), 'slug');
$verifica('poziția în listele în ordinea bazei (ProductOrder::aseaza în loadProducts): 1 = primul, cele două cu 2 pe locurile 2 și 3 (cel mai nou primul), apoi restul',
    $m[1] === ['probiotic-10-tulpini', 'zinc-15-mg', 'ceai-de-tei', $celeMaiNoi[0] ?? ''], implode(',', $m[1]));
$db->exec("DELETE FROM pages WHERE slug = 'lista-test'");
$db->exec('UPDATE products SET sort_position = NULL');

// =====================================================================
// WP-19 E. Pagina catalogului (GR-09)
// =====================================================================
$scrie('');
$scrie('== WP-19 E. Catalogul pe altă pagină (GR-09)');
$db->exec("INSERT INTO pages (title, slug, html_content, is_published, created_at, updated_at)
    VALUES ('Produse', 'produse', '<h1>Produsele noastre</h1>{{shop_catalog}}', 1, NOW(), NOW())");
$setare('catalog_pagina', 'produse');
$r = $cerere('GET', '/produse');
$verifica('catalog_pagina = produse: /produse arată catalogul', $r['cod'] === 200 && count($sluguriCatalog($r['corp'])) > 10
    && str_contains($r['corp'], 'Produsele noastre'));
$verifica('… sortarea rămâne pe /produse', str_contains($r['corp'], 'href="/produse?sort=price_asc"'));
$r = $cerere('GET', '/produse?categorie=Ceaiuri');
$verifica('… /produse?categorie=Ceaiuri arată doar ceaiurile', $r['cod'] === 200 && $sluguriCatalog($r['corp']) !== []
    && array_filter($sluguriCatalog($r['corp']), static fn (string $s): bool => !str_starts_with($s, 'ceai')) === []);
$verifica('… căutarea /produse?q=ceai are noindex', str_contains($cerere('GET', '/produse?q=ceai')['corp'], 'noindex,follow'));
$r = $cerere('GET', '/magazin?sort=price_asc&utm_source=test');
$verifica('… /magazin face 301 la /produse, cu parametrii', $r['cod'] === 301
    && ($r['antete']['location'] ?? '') === '/produse?sort=price_asc&utm_source=test', $r['cod'] . ' ' . ($r['antete']['location'] ?? ''));
$harta = $cerere('GET', '/sitemap.xml')['corp'];
$verifica('… harta site-ului are /produse o dată și nu /magazin', substr_count($harta, '/produse</loc>') === 1 && !str_contains($harta, '/magazin</loc>'));
// Pagina 404 a nucleului (vederea not-found.php), fără pagina „404” din bază.
$db->exec("UPDATE pages SET is_published = 0 WHERE slug = '404'");
$r = $cerere('GET', '/pagina-care-nu-exista-xyz');
$verifica('… pagina 404 a nucleului duce la /produse („Vezi toate produsele” și căutarea)', $r['cod'] === 404
    && str_contains($r['corp'], 'action="/produse"') && str_contains($r['corp'], 'href="/produse"'), 'cod ' . $r['cod']);
$db->exec("UPDATE pages SET is_published = 1 WHERE slug = '404'");
$verifica('… API-ul catalogului merge la fel', count($sluguriApi()) > 10);
$setare('catalog_pagina', null);
$db->exec("DELETE FROM pages WHERE slug = 'produse'");
$verifica('fără setare: /magazin rămâne catalogul (200)', $cerere('GET', '/magazin')['cod'] === 200);

// =====================================================================
// WP-19 F. Câmpurile suplimentare (C14d)
// =====================================================================
$scrie('');
$scrie('== WP-19 F. Câmpurile suplimentare: html, Imagine, doar la șablon (C14d)');
$adauga = $db->prepare('INSERT INTO product_extra_fields (name, field_key, field_type, placeholder, default_value, is_required, template_only, sort_order, is_active)
    VALUES (:n, :k, :t, NULL, NULL, 0, :o, :s, 1)');
$campuri = [];
foreach ([['Lista HTML', 'test_lista', 'html', 0], ['Nota text', 'test_nota', 'text', 0], ['Poza pachet', 'test_poza', 'image', 0],
    ['Doar sablon', 'test_doar', 'text', 1]] as $i => [$n, $k, $t, $o]) {
    $adauga->execute(['n' => $n, 'k' => $k, 't' => $t, 'o' => $o, 's' => 90 + $i]);
    $campuri[$k] = (int) $db->lastInsertId();
}
$idSablon = (int) $unu("SELECT id FROM product_templates ORDER BY id LIMIT 1");
$sablonVechi = $unu('SELECT html_content FROM product_templates WHERE id = :id', ['id' => $idSablon]);
$db->prepare('UPDATE product_templates SET html_content = :h WHERE id = :id')->execute(['id' => $idSablon,
    'h' => $sablonVechi . '<div id="t-lista">{{field_test_lista}}</div><div id="t-nota">{{field_test_nota}}</div><img id="t-poza" src="{{field_test_poza}}" alt=""><div id="t-doar">{{field_test_doar}}</div>']);
$idCuSablon = (int) $unu("SELECT id FROM products WHERE slug = 'ceai-de-menta'");
$idFaraSablon = (int) $unu("SELECT id FROM products WHERE slug = 'ceai-de-musetel'");
$valoare = $db->prepare('INSERT INTO product_extra_field_values (product_id, field_id, `value`) VALUES (:p, :f, :v)');
foreach ([$idCuSablon, $idFaraSablon] as $idProdus) {
    $valoare->execute(['p' => $idProdus, 'f' => $campuri['test_lista'], 'v' => '<ul><li>Unu</li></ul>']);
    $valoare->execute(['p' => $idProdus, 'f' => $campuri['test_nota'], 'v' => '<b>nu e tag</b>']);
    $valoare->execute(['p' => $idProdus, 'f' => $campuri['test_poza'], 'v' => '/uploads/gallery/poza-"test".webp']);
    $valoare->execute(['p' => $idProdus, 'f' => $campuri['test_doar'], 'v' => 'Valoare doar pentru sablon']);
}
$setare('catalog_camp_html_brut', '1');
$pagina = $cerere('GET', '/produs/ceai-de-menta')['corp'];
$verifica('cu catalog_camp_html_brut = 1: câmpul „html” intră ca markup în șablon', str_contains($pagina, '<div id="t-lista"><ul><li>Unu</li></ul></div>'));
$verifica('… câmpul „text” rămâne escapat', str_contains($pagina, '<div id="t-nota">&lt;b&gt;nu e tag&lt;/b&gt;</div>'));
$verifica('… câmpul „Imagine” e adresa, escapată în atribut', str_contains($pagina, '<img id="t-poza" src="/uploads/gallery/poza-&quot;test&quot;.webp"'));
$verifica('… câmpul „doar la șablon” intră în șablon', str_contains($pagina, '<div id="t-doar">Valoare doar pentru sablon</div>'));
$setare('catalog_camp_html_brut', '0');
$verifica('cu catalog_camp_html_brut = 0 (Bioscem înghețat): câmpul „html” e escapat, ca înainte',
    str_contains($cerere('GET', '/produs/ceai-de-menta')['corp'], '<div id="t-lista">&lt;ul&gt;&lt;li&gt;Unu&lt;/li&gt;&lt;/ul&gt;</div>'));
$setare('catalog_camp_html_brut', null);
$pagina = $cerere('GET', '/produs/ceai-de-musetel')['corp'];
$verifica('pagina fără șablon: „Lista HTML” și „Nota text” în informațiile suplimentare, „Poza pachet” și „Doar sablon” nu',
    str_contains($pagina, '<small>Lista HTML</small>') && str_contains($pagina, '<small>Nota text</small>')
    && !str_contains($pagina, 'Poza pachet') && !str_contains($pagina, 'Doar sablon') && !str_contains($pagina, 'poza-&quot;test'));
// Tabul câmpurilor ({{product_tabs_section}}): fără Imagine și fără câmpul de șablon.
$db->prepare('UPDATE product_templates SET html_content = :h WHERE id = :id')->execute(['id' => $idSablon, 'h' => '{{product_tabs_section}}']);
$pagina = $cerere('GET', '/produs/ceai-de-menta')['corp'];
$verifica('{{product_tabs_section}}: tab pentru „Lista HTML” și „Nota text”, nu pentru „Poza pachet” și „Doar sablon”',
    str_contains($pagina, 'Lista HTML') && str_contains($pagina, 'Nota text') && !str_contains($pagina, 'Poza pachet') && !str_contains($pagina, 'Doar sablon'));
$db->prepare('UPDATE product_templates SET html_content = :h WHERE id = :id')->execute(['id' => $idSablon, 'h' => $sablonVechi]);
// Adminul: tipul „Imagine” și bifa se salvează; editorul produsului are datele filtrului.
$r = $cerere('POST', '/admin/products/fields', $jarAdmin, ['id' => (string) $campuri['test_nota'], 'name' => 'Nota text', 'field_key' => 'test_nota',
    'field_type' => 'image', 'sort_order' => '91', 'is_active' => '1', 'template_only_trimis' => '1', 'template_only' => '1']);
$verifica('Admin → Câmpuri suplimentare: tipul „Imagine” și „apare doar la template-urile care îl folosesc” se salvează',
    $r['cod'] === 302 && $unu('SELECT CONCAT(field_type, \'/\', template_only) FROM product_extra_fields WHERE id = :id', ['id' => $campuri['test_nota']]) === 'image/1',
    $unu('SELECT CONCAT(field_type, \'/\', template_only) FROM product_extra_fields WHERE id = :id', ['id' => $campuri['test_nota']]));
$cerere('POST', '/admin/products/fields', $jarAdmin, ['id' => (string) $campuri['test_nota'], 'name' => 'Nota text', 'field_key' => 'test_nota',
    'field_type' => 'text', 'sort_order' => '91', 'is_active' => '1']);
$verifica('… fără marcajul bifei (alt formular) bifa rămâne', $unu('SELECT CONCAT(field_type, \'/\', template_only) FROM product_extra_fields WHERE id = :id',
    ['id' => $campuri['test_nota']]) === 'text/1');
$admin = $cerere('GET', '/admin/products', $jarAdmin)['corp'];
$dateAdmin = preg_match('#<script type="application/json" id="produse-catalog-date">(.*?)</script>#s', $admin, $mm) === 1 ? json_decode($mm[1], true) : null;
$verifica('Admin → Produse: datele editorului au câmpul „doar la șablon” și codurile cerute de șablon',
    is_array($dateAdmin) && (int) ($dateAdmin['campuri'][(string) $campuri['test_doar']]['doar_sablon'] ?? 0) === 1
    && in_array('test_doar', (array) ($dateAdmin['sabloane'][(string) $idSablon] ?? []), true) === false
    && str_contains($admin, 'data-camp-imagine="' . $campuri['test_poza'] . '"') && str_contains($admin, 'id="camp-extra-ascunse"'),
    is_array($dateAdmin) ? json_encode($dateAdmin['campuri'][(string) $campuri['test_doar']] ?? null) : 'fără date');

// =====================================================================
// WP-19 G. {{products_by_template:…}} (C14e)
// =====================================================================
$scrie('');
$scrie('== WP-19 G. Produsele unui șablon pe o pagină (C14e)');
$slugSablon = $unu('SELECT slug FROM product_templates WHERE id = :id', ['id' => $idSablon]);
$cuSablon = (int) $unu('SELECT COUNT(*) FROM products WHERE product_template_id = :t AND deleted_at IS NULL AND is_active = 1', ['t' => $idSablon]);
$db->exec("INSERT INTO pages (title, slug, html_content, is_published, created_at, updated_at) VALUES ('Pachete test', 'pachete-test',
    '<p>Sus</p>{{products_by_template:" . $slugSablon . "|limit=3|link=/pachete-test|text=Vezi <toate>|titlu=Pachete|subtitlu=Alese}}"
    . "<p>Mijloc</p>{{ products_by_template : " . $slugSablon . " }}<p>Jos</p>{{products_by_template:nu-exista}}{{products_by_template:" . $slugSablon
    . "|limit=1|link=javascript:alert(1)}}', 1, NOW(), NOW())");
$pagina = $cerere('GET', '/pachete-test')['corp'];
preg_match_all('#<section class="bv-tpl-products">(.*?)</section>#s', $pagina, $sectiuni);
$carduri = array_map(static fn (string $s): int => substr_count($s, 'class="bv-popular-card '), $sectiuni[1]);
$verifica('trei secțiuni (șablonul inexistent nu lasă nimic): 3 carduri cu limita, ' . $cuSablon . ' fără limită, 1',
    $carduri === [3, $cuSablon, 1], json_encode($carduri));
$verifica('… titlul, subtitlul și bannerul (textul escapat), o singură dată stilurile cardului per secțiune',
    str_contains($sectiuni[1][0] ?? '', '<h2 class="bv-tpl-products__title">Pachete</h2>') && str_contains($sectiuni[1][0] ?? '', 'Alese</p>')
    && str_contains($sectiuni[1][0] ?? '', 'href="/pachete-test"') && str_contains($sectiuni[1][0] ?? '', 'Vezi &lt;toate&gt;'));
$verifica('… un link javascript: nu intră (fără banner)', !str_contains($pagina, 'javascript:alert'));
$verifica('… doar produsele active ale șablonului (fără cel inactiv și fără un produs fără șablon)',
    !str_contains(implode('', $sectiuni[1]), '/produs/' . $slugInactiv . '"') && !str_contains(implode('', $sectiuni[1]), '/produs/ceai-de-musetel"'));
$verifica('… niciun cod {{products_by_template rămas', !str_contains($pagina, 'products_by_template'));
$editor = $cerere('GET', '/admin/pages/new', $jarAdmin)['corp'];
$verifica('editorul paginilor: codul {{products_by_template:…}} în listă și chenarul previzualizării',
    str_contains($editor, '{{products_by_template:slug-template}}') && str_contains($editor, 'Produsele template-ului'));
$db->exec("DELETE FROM pages WHERE slug = 'pachete-test'");

// =====================================================================
// WP-19 H. GR-16, GR-17
// =====================================================================
$scrie('');
$scrie('== WP-19 H. Banda simplă a produselor, sortarea ascunsă, produsele serviciului (GR-16, GR-17)');
$db->prepare('UPDATE product_templates SET html_content = :h WHERE id = :id')->execute(['id' => $idSablon,
    'h' => $sablonVechi . '{{product_produse_similare}}']);
$setare('catalog_similare_subtitlu', 'Subtitlu de test');
$pagina = $cerere('GET', '/produs/ceai-de-menta')['corp'];
$verifica('{{product_produse_similare}}: banda simplă, cu titlul implicit, subtitlul din setări și „Vezi produsul”',
    str_contains($pagina, '<section id="produse-similare"') && str_contains($pagina, 'Produse asemănătoare')
    && str_contains($pagina, 'Subtitlu de test') && str_contains($pagina, 'Vezi produsul') && !str_contains($pagina, '{{product_produse_similare}}'));
$verifica('… cardurile duc la produse din aceeași categorie, fără preț', preg_match_all('#<section id="produse-similare".*?</section>#s', $pagina, $banda) === 1
    && substr_count($banda[0][0], 'href="/produs/ceai-') >= 1 && !str_contains($banda[0][0], ' lei'));
$setare('catalog_similare_subtitlu', null);
$db->prepare('UPDATE product_templates SET html_content = :h WHERE id = :id')->execute(['id' => $idSablon, 'h' => $sablonVechi]);
$setare('catalog_sortare_vizibila', '0');
$verifica('catalog_sortare_vizibila = 0: catalogul fără butonul sortării', !str_contains($cerere('GET', '/magazin')['corp'], 'class="shop-catalog-v2__sort-btn"'));
$setare('catalog_sortare_vizibila', null);
$verifica('… implicit (1): butonul e acolo', str_contains($cerere('GET', '/magazin')['corp'], 'class="shop-catalog-v2__sort-btn"'));
$verifica('cu magazinul pe site, fiecare card are partea de jos (preț, coș)',
    substr_count($cerere('GET', '/magazin')['corp'], 'class="bv-popular-card__bottom"') === count($sluguriCatalog($cerere('GET', '/magazin')['corp'])));
$licentaSchimbata = true;
exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/tools/dev/licenta.php') . ' catalog 2>&1', $iesireLicenta, $codLicenta);
$magazin = $cerere('GET', '/magazin')['corp'];
$verifica('licența „catalog” (fără magazin): cardurile n-au partea de jos goală, iar lista e tot acolo',
    $codLicenta === 0 && !str_contains($magazin, 'bv-popular-card__bottom">') && count($sluguriCatalog($magazin)) > 10,
    'licența: ' . implode(' ', $iesireLicenta));
exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/tools/dev/licenta.php') . ' toate 2>&1', $iesireLicenta, $codLicenta);
$licentaSchimbata = $codLicenta !== 0;
$db->exec("INSERT INTO pages (title, slug, html_content, is_published, created_at, updated_at)
    VALUES ('Ceaiuri la comandă', 'servicii/ceaiuri', '<p>Serviciu</p>{{produse_serviciu}}', 1, NOW(), NOW())");
$pagina = $cerere('GET', '/servicii/ceaiuri')['corp'];
preg_match_all('#href="/produs/([a-z0-9-]+)"#', (string) (strstr($pagina, 'id="produse-serviciu"') ?: ''), $m);
$verifica('{{produse_serviciu}} pe /servicii/ceaiuri: ceaiurile active (' . $activeCeaiuri . '), alfabetic, fără cel inactiv',
    count($m[1]) === $activeCeaiuri && !in_array($slugInactiv, $m[1], true) && !str_contains($pagina, '{{produse_serviciu}}'),
    implode(',', $m[1]));
$db->exec("UPDATE pages SET slug = 'servicii/nimic-aici' WHERE slug = 'servicii/ceaiuri'");
$pagina = $cerere('GET', '/servicii/nimic-aici')['corp'];
$verifica('… pe o pagină fără categorie potrivită tokenul dispare', !str_contains($pagina, 'produse-serviciu') && !str_contains($pagina, '{{produse_serviciu}}'));
$db->exec("DELETE FROM pages WHERE slug = 'servicii/nimic-aici'");

// =====================================================================
// WP-19 I. Adminul: câmpurile catalogului în editorul produsului
// =====================================================================
$scrie('');
$scrie('== WP-19 I. Adminul: poziția, „ascuns din catalog”, cheia de ciornă');
$produs = $db->query("SELECT * FROM products WHERE slug = 'ceai-de-ghimbir'")->fetch(PDO::FETCH_ASSOC) ?: [];
$idGhimbir = (int) ($produs['id'] ?? 0);
$corp = [
    'name' => (string) $produs['name'], 'slug' => (string) $produs['slug'], 'sku' => (string) $produs['sku'],
    'category_id' => (string) $produs['category_id'], 'product_template_id' => (string) $produs['product_template_id'],
    'price' => (string) $produs['price'], 'stock' => (string) $produs['stock'], 'vat_percent' => (string) $produs['vat_percent'],
    'vat_included' => (string) $produs['vat_included'], 'weight_grams' => (string) $produs['weight_grams'],
    'short_description' => (string) $produs['short_description'], 'description' => (string) $produs['description'],
    'image_url' => (string) $produs['image_url'], 'is_active' => '1',
];
$db->prepare('UPDATE products SET preview_token = NULL WHERE id = :id')->execute(['id' => $idGhimbir]);
$r = $cerere('POST', '/admin/products/' . $idGhimbir . '/update', $jarAdmin, $corp + ['sort_position' => '4', 'catalog_campuri_trimise' => '1', 'hidden_in_catalog' => '1']);
$rand = $db->query('SELECT sort_position, hidden_in_catalog, preview_token FROM products WHERE id = ' . $idGhimbir)->fetch(PDO::FETCH_ASSOC);
$verifica('salvarea produsului: poziția 4, ascuns din catalog, iar produsul fără cheie primește una',
    $r['cod'] === 302 && (int) $rand['sort_position'] === 4 && (int) $rand['hidden_in_catalog'] === 1
    && preg_match('/^[0-9a-f]{32}$/', (string) $rand['preview_token']) === 1, json_encode($rand));
$cheieGhimbir = (string) $rand['preview_token'];
$cerere('POST', '/admin/products/' . $idGhimbir . '/update', $jarAdmin, $corp);
$rand = $db->query('SELECT sort_position, hidden_in_catalog, preview_token FROM products WHERE id = ' . $idGhimbir)->fetch(PDO::FETCH_ASSOC);
$verifica('… o salvare care nu trimite câmpurile catalogului (alt formular) le păstrează, cheia rămâne aceeași',
    (int) $rand['sort_position'] === 4 && (int) $rand['hidden_in_catalog'] === 1 && $rand['preview_token'] === $cheieGhimbir, json_encode($rand));
$cerere('POST', '/admin/products/' . $idGhimbir . '/update', $jarAdmin, $corp + ['sort_position' => '', 'catalog_campuri_trimise' => '1']);
$rand = $db->query('SELECT sort_position, hidden_in_catalog FROM products WHERE id = ' . $idGhimbir)->fetch(PDO::FETCH_ASSOC);
$verifica('… poziția golită și bifa scoasă din formular: NULL și 0', $rand['sort_position'] === null && (int) $rand['hidden_in_catalog'] === 0, json_encode($rand));
$admin = $cerere('GET', '/admin/products', $jarAdmin)['corp'];
$dateAdmin = preg_match('#<script type="application/json" id="produse-catalog-date">(.*?)</script>#s', $admin, $mm) === 1 ? json_decode($mm[1], true) : [];
$verifica('Admin → Produse: linkul de ciornă al produsului inactiv în datele listei, nimic pentru unul activ',
    ($dateAdmin['produse'][(string) $idInactiv]['draft_url'] ?? '') === '/produs/' . $slugInactiv . '?draft=' . $cheieInactiv
    && ($dateAdmin['produse'][(string) $idGhimbir]['draft_url'] ?? 'x') === ''
    && str_contains($admin, 'id="p_sort_position"') && str_contains($admin, 'name="catalog_campuri_trimise"'));

// =====================================================================
// Final: totul la loc
// =====================================================================
$scrie('');
$scrie('== Final: baza, licența, cache-ul și sesiunile la loc');
$puneLaLoc();
$sumeDupa = $sumeBaza();
$diferite = array_keys(array_diff_assoc($sumeDupa, $sumeInainte) + array_diff_assoc($sumeInainte, $sumeDupa));
$verifica('baza e exact cum era (CHECKSUM TABLE pe toate cele ' . count($sumeInainte) . ' tabele)', $diferite === [], implode(', ', $diferite));
$eroriNoi = is_file($fisierErori) ? (string) @file_get_contents($fisierErori, false, null, $pozitieErori) : '';
$verifica('nicio eroare PHP nouă în ' . basename($fisierErori), trim($eroriNoi) === '', mb_substr($eroriNoi, 0, 800));

$scrie('');
$scrie(sprintf('Rezultat: %d trecute, %d eșuate. Raport: %s', $trecute, $esuate, $fisierRaport));
exit($esuate === 0 ? 0 : 1);
