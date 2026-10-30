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
 * H. GR-16, GR-17: {{product_produse_similare}} (banda simplă, titlul din setări, „Cere ofertă” cu pluginul cereri_oferta,
 *    WP-26, „Vezi produsul” fără el),
 *    catalog_sortare_vizibila = 0 fără butonul sortării, {{produse_serviciu}} pe o pagină de serviciu.
 * I. Adminul: editorul produsului salvează poziția și bifa „ascuns din catalog” (și le păstrează când formularul nu le
 *    trimite), un produs nou primește cheia de ciornă, lista arată linkul de ciornă.
 *
 * WP-20 — banii (C14a, C9, X5):
 * A. Transportul gratuit pe produs (C14a): fără bifă coșul și API-ul lui sunt exact ca înainte (fără cheia nouă); cu un
 *    produs bifat în coș, transportul 0, totalul fără el, „Ai transport gratuit pe comandă.” cu bara plină, cota de transport
 *    a checkout-ului 0 și cu prețul fix pornit (martor: 19 lei fără bifă); adminRecalcShipping în ordinea transport pus de
 *    mână > produs gratuit > prag > transport curent; în admin, reducerea comercială și schimbarea produselor (din lista
 *    nouă) dau transport 0, iar transportul pus de mână rămâne; PoliticaLivrare (JSON-LD, fluxul Merchant) dă 0 lei la
 *    produsul bifat; editorul produsului salvează bifa doar când formularul o trimite.
 * B. Cupoanele (C9): „DEMO 10”, cu tab și spațiu nedespărțitor, găsește DEMO10; un cod care chiar are spații găsește
 *    întâi potrivirea exactă; un cupon oprit nu se găsește; în coș rămâne codul din bază.
 * C. Linkul de plată prin Stripe (X5): procesorul unui link nou (Stripe după BT și EuPlătesc), pagina linkului, câmpurile
 *    sesiunii Stripe Checkout (suma în bani, referința și cheia linkului, fără metadatele comenzii; nicio cerere spre
 *    Stripe), webhook-ul semnat local: semnătură greșită, altă sumă, sesiune neplătită, payment_intent al linkului → nimic;
 *    sesiunea plătită → linkul plătit și suma adăugată la încasat, o singură dată, fără să schimbe starea comenzii; o sesiune
 *    Stripe cu cheia unui link EuPlătesc → nimic; adminul trimite un link Stripe când e singurul procesator.
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
use App\Support\CheckoutCalculator;
use App\Support\Database;
use App\Support\Paths;
use App\Support\PaymentLink;
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
// Cu licența „toate”, cereri_oferta e pe site (portat în WP-26): legătura cardului spune „Cere ofertă”.
$verifica('{{product_produse_similare}}: banda simplă, cu titlul implicit, subtitlul din setări și „Cere ofertă” (cereri_oferta pe site)',
    str_contains($pagina, '<section id="produse-similare"') && str_contains($pagina, 'Produse asemănătoare')
    && str_contains($pagina, 'Subtitlu de test') && str_contains($pagina, 'Cere ofertă') && !str_contains($pagina, 'Vezi produsul')
    && !str_contains($pagina, '{{product_produse_similare}}'));
$licentaSchimbata = true;
exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/tools/dev/licenta.php') . ' toate cereri_oferta=oprit 2>&1',
    $iesireLicenta, $codLicenta);
$faraOferta = $cerere('GET', '/produs/ceai-de-menta')['corp'];
$verifica('… fără cereri_oferta: „Vezi produsul”', $codLicenta === 0 && str_contains($faraOferta, 'Vezi produsul') && !str_contains($faraOferta, 'Cere ofertă'),
    implode(' ', $iesireLicenta));
exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/tools/dev/licenta.php') . ' toate 2>&1', $iesireLicenta, $codLicenta);
$licentaSchimbata = $codLicenta !== 0;
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
// WP-20 A. Transportul gratuit pe produs (C14a)
// =====================================================================
$scrie('');
$scrie('== WP-20 A. Transportul gratuit pe produs (C14a)');
$setare('erp_enabled', '0'); // nicio cerere spre ERP din salvările de mai jos
$setare('shipping_fixed_enabled', '0');
$setare('shipping_free_province', '200');
$setare('shipping_free_bucharest', '200');
$setare('shipping_cost_province', '15');
$setare('shipping_max_cost', '40');
$db->exec('UPDATE products SET free_shipping = 0');
$idGratuit = (int) $unu("SELECT id FROM products WHERE slug = 'ceai-de-tei' AND is_active = 1 AND deleted_at IS NULL AND bbd_enabled = 0");
$idAlt = (int) $unu("SELECT id FROM products WHERE slug = 'fulgi-de-ovaz-integral' AND is_active = 1 AND deleted_at IS NULL");
$verifica('produsele demo ale testului (ceai de tei, fulgi de ovăz), active, ieftine (sub pragul de 200 lei)', $idGratuit > 0 && $idAlt > 0
    && (float) $unu('SELECT price FROM products WHERE id = ' . $idGratuit) + (float) $unu('SELECT price FROM products WHERE id = ' . $idAlt) < 100);

$verifica('CheckoutCalculator::produseAuTransportGratuit: fără produs bifat, nu; lista goală sau id-uri greșite, nu',
    !CheckoutCalculator::produseAuTransportGratuit($db, [$idGratuit, $idAlt]) && !CheckoutCalculator::produseAuTransportGratuit($db, [])
    && !CheckoutCalculator::produseAuTransportGratuit($db, [0, -3]));
$coloanaPrag = static fn (string $corp): bool => str_contains($corp, 'Ai transport gratuit pe comandă.');
@unlink($jarViz);
$cerere('POST', '/cos/judet', $jarViz, ['county' => 'Cluj']);
$cerere('POST', '/cos/adauga/' . $idGratuit, $jarViz, ['quantity' => '1']);
$cerere('POST', '/cos/adauga/' . $idAlt, $jarViz, ['quantity' => '1']);
$sumar = json_decode($cerere('GET', '/api/cart/summary', $jarViz)['corp'], true) ?: [];
$cos = $cerere('GET', '/cos', $jarViz)['corp'];
$verifica('fără bifă: coșul arată „Mai adaugă … pentru transport gratuit”, API-ul coșului fără cheia shipping_free_by_product (exact ca înainte)',
    count((array) ($sumar['lines'] ?? [])) === 2 && !array_key_exists('shipping_free_by_product', $sumar)
    && (float) ($sumar['shipping_remaining_for_free'] ?? 0) > 0 && str_contains($cos, 'pentru transport gratuit') && !$coloanaPrag($cos),
    json_encode(array_intersect_key($sumar, array_flip(['shipping', 'shipping_remaining_for_free', 'total']))));
$totalFara = (float) ($sumar['total'] ?? 0);
$transportFara = (float) ($sumar['shipping'] ?? 0);

$db->prepare('UPDATE products SET free_shipping = 1 WHERE id = :id')->execute(['id' => $idGratuit]);
$sumar = json_decode($cerere('GET', '/api/cart/summary', $jarViz)['corp'], true) ?: [];
$cos = $cerere('GET', '/cos', $jarViz)['corp'];
$verifica('cu bifa pe un produs din coș: transport 0, totalul scade cu transportul, shipping_free_by_product, „Ai transport gratuit pe comandă.” cu bara plină',
    ($sumar['shipping_free_by_product'] ?? null) === true && (float) ($sumar['shipping'] ?? -1) === 0.0
    && (float) ($sumar['shipping_remaining_for_free'] ?? -1) === 0.0 && abs($totalFara - $transportFara - (float) ($sumar['total'] ?? 0)) < 0.01
    && $coloanaPrag($cos) && str_contains($cos, 'width:100.00%'),
    'fără: transport ' . $transportFara . ', total ' . $totalFara . ' | cu: ' . json_encode(array_intersect_key($sumar, array_flip(['shipping', 'shipping_remaining_for_free', 'total', 'shipping_free_by_product']))));
// Prețul fix al transportului (Setări livrare) și tariful curierului: bifa le bate pe amândouă.
$setare('shipping_fixed_enabled', '1');
$setare('shipping_fixed_base', '19');
$cota = json_decode($cerere('GET', '/api/checkout/shipping-quote?' . http_build_query(['billing_county' => 'Cluj', 'billing_city' => 'Cluj-Napoca',
    'billing_street' => 'Strada Exemplu', 'billing_street_no' => '1', 'billing_postcode' => '400000']), $jarViz)['corp'], true) ?: [];
$verifica('cu prețul fix al transportului pornit: cota de transport a checkout-ului 0, fără să ceară transport (requires_shipping_charge false)',
    ($cota['ok'] ?? false) === true && (float) ($cota['shipping'] ?? -1) === 0.0 && ($cota['requires_shipping_charge'] ?? true) === false, json_encode($cota));
$db->prepare('UPDATE products SET free_shipping = 0 WHERE id = :id')->execute(['id' => $idGratuit]);
$cota = json_decode($cerere('GET', '/api/checkout/shipping-quote?' . http_build_query(['billing_county' => 'Cluj', 'billing_city' => 'Cluj-Napoca',
    'billing_street' => 'Strada Exemplu', 'billing_street_no' => '1', 'billing_postcode' => '400000']), $jarViz)['corp'], true) ?: [];
$verifica('… martor: fără bifă, același coș plătește prețul fix (19 lei)', (float) ($cota['shipping'] ?? 0) === 19.0, json_encode($cota));
$setare('shipping_fixed_enabled', '0');
@unlink($jarViz);

// Recalculările din admin: transportul pus de mână > produsul cu transport gratuit > pragul > transportul curent.
$s = ['shipping_free_province' => '200', 'shipping_free_bucharest' => '200', 'shipping_cost_province' => '15', 'shipping_max_cost' => '40',
    'shipping_include_coupons' => '1'];
$verifica('adminRecalcShipping: transportul pus de mână bate produsul gratuit (25), produsul gratuit bate transportul curent (0), fără el ca înainte (12)',
    CheckoutCalculator::adminRecalcShipping($s, 'Cluj', 50.0, 0.0, 25.0, true, true) === 25.0
    && CheckoutCalculator::adminRecalcShipping($s, 'Cluj', 50.0, 0.0, 12.0, false, true) === 0.0
    && CheckoutCalculator::adminRecalcShipping($s, 'Cluj', 50.0, 0.0, 0.0, false, true) === 0.0
    && CheckoutCalculator::adminRecalcShipping($s, 'Cluj', 50.0, 0.0, 12.0, false, false) === 12.0
    && CheckoutCalculator::adminRecalcShipping($s, 'Cluj', 50.0, 0.0, 12.0) === 12.0);

// O comandă demo neprocesată, cu transport plătit: reducerea comercială și schimbarea produselor din admin.
$idComanda = (int) $unu("SELECT o.id FROM orders o WHERE o.deleted_at IS NULL AND o.status IN ('pending', 'pending_payment') AND o.shipping_cost > 0
    AND o.shipping_manual = 0 AND o.subtotal < 150 AND NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND oi.product_id = " . $idGratuit . ")
    ORDER BY o.id LIMIT 1");
$verifica('o comandă demo neprocesată, cu transport, sub prag, fără ceaiul de tei', $idComanda > 0);
$transportComanda = (float) $unu('SELECT shipping_cost FROM orders WHERE id = ' . $idComanda);
$r = json_decode($cerere('POST', '/admin/orders/' . $idComanda . '/discount', $jarAdmin, ['mode' => 'suma', 'value' => '1', 'reason' => 'test'])['corp'], true) ?: [];
$verifica('fără produs gratuit în comandă: reducerea din admin păstrează transportul (' . $transportComanda . ')', ($r['ok'] ?? false) === true
    && (float) $unu('SELECT shipping_cost FROM orders WHERE id = ' . $idComanda) === $transportComanda, json_encode($r));
$db->prepare('UPDATE products SET free_shipping = 1 WHERE id = :id')->execute(['id' => $idGratuit]);
$linii = $db->query('SELECT product_id, quantity FROM order_items WHERE order_id = ' . $idComanda . ' AND product_id IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
$corpLinii = ['product_id' => array_merge(array_column($linii, 'product_id'), [(string) $idGratuit]),
    'quantity' => array_merge(array_column($linii, 'quantity'), ['1'])];
$r = json_decode($cerere('POST', '/admin/orders/' . $idComanda . '/items', $jarAdmin, $corpLinii)['corp'], true) ?: [];
$comanda = $db->query('SELECT shipping_cost, subtotal, total, manual_discount, discount_total, loyalty_points_discount FROM orders WHERE id = '
    . $idComanda)->fetch(PDO::FETCH_ASSOC);
$reducereComanda = min((float) $comanda['subtotal'], (float) $comanda['manual_discount'] + (float) $comanda['discount_total'] + (float) $comanda['loyalty_points_discount']);
$verifica('schimbarea produselor din admin, cu ceaiul de tei adăugat: transportul 0 (din lista nouă, nu din bază), totalul = subtotal − reduceri',
    ($r['ok'] ?? false) === true && (float) $comanda['shipping_cost'] === 0.0
    && abs((float) $comanda['total'] - ((float) $comanda['subtotal'] - $reducereComanda)) < 0.01, json_encode($r) . ' ' . json_encode($comanda));
$db->prepare('UPDATE orders SET shipping_cost = 14, shipping_manual = 0 WHERE id = :id')->execute(['id' => $idComanda]);
$r = json_decode($cerere('POST', '/admin/orders/' . $idComanda . '/discount', $jarAdmin, ['mode' => 'suma', 'value' => '2', 'reason' => 'test'])['corp'], true) ?: [];
$verifica('reducerea din admin pe comanda cu produsul gratuit: transportul 0', ($r['ok'] ?? false) === true
    && (float) $unu('SELECT shipping_cost FROM orders WHERE id = ' . $idComanda) === 0.0, json_encode($r));
$db->prepare('UPDATE orders SET shipping_cost = 14, shipping_manual = 1 WHERE id = :id')->execute(['id' => $idComanda]);
$r = json_decode($cerere('POST', '/admin/orders/' . $idComanda . '/discount', $jarAdmin, ['mode' => 'suma', 'value' => '3', 'reason' => 'test'])['corp'], true) ?: [];
$verifica('… cu transportul pus de mână (shipping_manual = 1): rămâne 14', ($r['ok'] ?? false) === true
    && (float) $unu('SELECT shipping_cost FROM orders WHERE id = ' . $idComanda) === 14.0, json_encode($r));

// Promisiunea de livrare din datele pentru Google (JSON-LD, fluxul Merchant): 0 lei la produsul bifat.
$setariFixe = ['shipping_fixed_enabled' => '1', 'shipping_fixed_base' => '19', 'shipping_free_province' => '200', 'shipping_free_bucharest' => '200'];
if (\App\Support\PoliticaLivrare::activ()) {
    $faraBifa = \App\Support\PoliticaLivrare::optiuni($setariFixe, 22.0);
    $cuBifa = \App\Support\PoliticaLivrare::optiuni($setariFixe, 22.0, true);
    $verifica('PoliticaLivrare::optiuni: produsul bifat are livrarea 0 lei, cel nebifat prețul fix (19)', ($cuBifa[0]['pret'] ?? null) === 0.0
        && ($faraBifa[0]['pret'] ?? null) === 19.0, json_encode([$faraBifa, $cuBifa]));
} else {
    $verifica('PoliticaLivrare::optiuni: livrarea în datele Google e oprită pe site, deci nicio opțiune, cu sau fără bifă',
        \App\Support\PoliticaLivrare::optiuni($setariFixe, 22.0, true) === [] && \App\Support\PoliticaLivrare::optiuni($setariFixe, 22.0) === []);
}

// Adminul: bifa în editorul produsului (cu magazinul pe site), salvată doar când formularul o trimite.
$produs = $db->query('SELECT * FROM products WHERE id = ' . $idAlt)->fetch(PDO::FETCH_ASSOC) ?: [];
$corp = [
    'name' => (string) $produs['name'], 'slug' => (string) $produs['slug'], 'sku' => (string) $produs['sku'],
    'category_id' => (string) $produs['category_id'], 'product_template_id' => (string) $produs['product_template_id'],
    'price' => (string) $produs['price'], 'stock' => (string) $produs['stock'], 'vat_percent' => (string) $produs['vat_percent'],
    'vat_included' => (string) $produs['vat_included'], 'weight_grams' => (string) $produs['weight_grams'],
    'short_description' => (string) $produs['short_description'], 'description' => (string) $produs['description'],
    'image_url' => (string) $produs['image_url'], 'is_active' => '1',
];
$cerere('POST', '/admin/products/' . $idAlt . '/update', $jarAdmin, $corp + ['transport_campuri_trimise' => '1', 'free_shipping' => '1']);
$bifat = (int) $unu('SELECT free_shipping FROM products WHERE id = ' . $idAlt);
$cerere('POST', '/admin/products/' . $idAlt . '/update', $jarAdmin, $corp);
$pastrat = (int) $unu('SELECT free_shipping FROM products WHERE id = ' . $idAlt);
$cerere('POST', '/admin/products/' . $idAlt . '/update', $jarAdmin, $corp + ['transport_campuri_trimise' => '1']);
$scos = (int) $unu('SELECT free_shipping FROM products WHERE id = ' . $idAlt);
$admin = $cerere('GET', '/admin/products', $jarAdmin)['corp'];
$dateAdmin = preg_match('#<script type="application/json" id="produse-catalog-date">(.*?)</script>#s', $admin, $mm) === 1 ? json_decode($mm[1], true) : [];
$verifica('editorul produsului: bifa salvată (1), păstrată de o salvare fără ea (1), scoasă din formular (0); în pagină și în datele listei',
    $bifat === 1 && $pastrat === 1 && $scos === 0 && str_contains($admin, 'id="p_free_shipping"') && str_contains($admin, 'name="transport_campuri_trimise"')
    && (int) ($dateAdmin['produse'][(string) $idGratuit]['free_shipping'] ?? 0) === 1 && !isset($dateAdmin['produse'][(string) $idAlt]['free_shipping']),
    $bifat . '/' . $pastrat . '/' . $scos);
$db->exec('UPDATE products SET free_shipping = 0');

// =====================================================================
// WP-20 B. Cupoanele cu spații (C9)
// =====================================================================
$scrie('');
$scrie('== WP-20 B. Codul cuponului scris cu spații (C9)');
$cuponDupaCod = static function (string $cod) use ($db): ?array {
    $m = new ReflectionMethod(CheckoutCalculator::class, 'loadCouponByCode');
    $m->setAccessible(true);
    return $m->invoke(null, $db, $cod);
};
$verifica('„DEMO 10”, „ demo10 ”, „DE MO 10” (cu tab și spațiu nedespărțitor) găsesc cuponul DEMO10, cu codul din bază',
    ($cuponDupaCod('DEMO 10')['code'] ?? '') === 'DEMO10' && ($cuponDupaCod(' demo10 ')['code'] ?? '') === 'DEMO10'
    && ($cuponDupaCod("DE\tMO\u{00A0}10")['code'] ?? '') === 'DEMO10');
$db->exec("INSERT INTO coupons (name, code, type, value, is_active) VALUES ('Test spații', 'CALC 77', 'percent', 5, 1), ('Test compact', 'CALC77', 'percent', 7, 1)");
$verifica('un cod care chiar are spații („CALC 77”) găsește întâi potrivirea exactă, nu varianta compactă (CALC77)',
    ($cuponDupaCod('calc 77')['code'] ?? '') === 'CALC 77' && ($cuponDupaCod('CALC77')['code'] ?? '') === 'CALC77'
    && ($cuponDupaCod('C ALC77')['code'] ?? '') === 'CALC77');
$verifica('un cod inexistent rămâne inexistent; un cupon oprit nu se găsește nici cu spații', $cuponDupaCod('NU EXISTA') === null
    && $cuponDupaCod('OPRIT 20') === null);
@unlink($jarViz);
$cerere('POST', '/cos/adauga/' . $idAlt, $jarViz, ['quantity' => '3']);
$cerere('POST', '/cos/cupon', $jarViz, ['coupon_code' => 'demo 10']);
$sumar = json_decode($cerere('GET', '/api/cart/summary', $jarViz)['corp'], true) ?: [];
$verifica('în coș: „demo 10” se aplică, iar coșul ține codul din bază (DEMO10)', ($sumar['coupon']['code'] ?? '') === 'DEMO10'
    && (float) ($sumar['discount'] ?? 0) > 0, json_encode(array_intersect_key($sumar, array_flip(['coupon', 'coupon_error', 'discount']))));
@unlink($jarViz);

// =====================================================================
// WP-20 C. Linkul de plată prin Stripe (X5)
// =====================================================================
$scrie('');
$scrie('== WP-20 C. Linkul de plată a diferenței prin Stripe (X5)');
$stripe = ['stripe_enabled' => '1', 'stripe_secret_key' => 'sk_test_inventat'];
$eu = ['euplatesc_enabled' => '1', 'euplatesc_merchant_id' => 'inventat', 'euplatesc_secret_key' => 'inventat'];
$verifica('procesorul unui link nou: Stripe doar când nici BT, nici EuPlătesc nu sunt pornite; EuPlătesc rămâne înaintea lui; fără cheie, niciunul',
    PaymentLink::procesorPentruLinkNou($stripe) === PaymentLink::PROCESATOR_STRIPE
    && PaymentLink::procesorPentruLinkNou($stripe + $eu) === PaymentLink::PROCESATOR_EUPLATESC
    && PaymentLink::procesorPentruLinkNou(['stripe_enabled' => '1', 'stripe_secret_key' => '']) === ''
    && PaymentLink::procesorPentruLinkNou(['stripe_enabled' => '0', 'stripe_secret_key' => 'sk_test_x']) === '');
$verifica('procesatorul unui link: „stripe” recunoscut; necunoscut = EuPlătesc (ca înainte); Stripe disponibil doar cu setările lui',
    PaymentLink::procesator(['procesator' => 'stripe']) === 'stripe' && PaymentLink::procesator(['procesator' => 'altceva']) === 'euplatesc'
    && PaymentLink::procesorDisponibil('stripe', $stripe) && !PaymentLink::procesorDisponibil('stripe', $eu)
    && !PaymentLink::procesorScosDinLicenta('stripe'));

$comandaPlatita = $db->query("SELECT id, order_number, total, paid_amount, status, payment_status FROM orders WHERE deleted_at IS NULL AND payment_status = 'paid'
    AND total > 40 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$idPlatita = (int) ($comandaPlatita['id'] ?? 0);
$db->prepare('UPDATE orders SET paid_amount = ROUND(total - 25.5, 2) WHERE id = :id')->execute(['id' => $idPlatita]);
$link = PaymentLink::creeaza($db, $idPlatita, (string) $comandaPlatita['order_number'], 25.5, PaymentLink::PROCESATOR_STRIPE, []);
$verifica('o comandă demo plătită, cu 25,50 lei rest: linkul nou ține procesatorul „stripe”', $idPlatita > 0 && is_array($link)
    && (string) ($link['procesator'] ?? '') === 'stripe' && (string) ($link['status'] ?? '') === 'pending');
$token = (string) ($link['token'] ?? '');
$setare('stripe_enabled', '1');
$pagina = $cerere('GET', '/plata/' . rawurlencode($token));
$verifica('pagina linkului: suma, butonul de plată și „pe pagina procesatorului Stripe”', $pagina['cod'] === 200 && str_contains($pagina['corp'], '25,50 lei')
    && str_contains($pagina['corp'], 'pe pagina procesatorului Stripe') && str_contains($pagina['corp'], 'Plătește cu cardul'));

// Câmpurile sesiunii Stripe Checkout (fără cererea spre Stripe).
$site = (new ReflectionClass(\App\Http\Controllers\SiteController::class))->newInstanceWithoutConstructor();
$campuri = (new ReflectionMethod($site, 'campuriStripeLink'))->invoke($site, $link, ['order_number' => (string) $comandaPlatita['order_number'],
    'billing_email' => 'client@exemplu.ro'], ['stripe_currency' => 'RON'], 'https://magazin.exemplu.ro');
$verifica('sesiunea Stripe a linkului: suma în bani (2550), referința linkului, cheia lui în metadate (și pe plată), întoarcerea pe /plata/…, '
    . 'fără metadatele comenzii (order_number / order_id)', is_array($campuri) && $campuri['line_items[0][price_data][unit_amount]'] === '2550'
    && $campuri['line_items[0][price_data][currency]'] === 'ron' && $campuri['client_reference_id'] === (string) $link['referinta']
    && $campuri['metadata[payment_link_token]'] === $token && $campuri['payment_intent_data[metadata][payment_link_token]'] === $token
    && str_starts_with($campuri['success_url'], 'https://magazin.exemplu.ro/plata/' . $token . '?platit=1&session_id=')
    && !isset($campuri['metadata[order_number]']) && !isset($campuri['metadata[order_id]']) && ($campuri['customer_email'] ?? '') === 'client@exemplu.ro',
    json_encode($campuri));
$verifica('… un link cu suma 0: nicio sesiune', (new ReflectionMethod($site, 'campuriStripeLink'))->invoke($site, ['amount' => '0'] + $link, [], [], '') === null);

/** Un eveniment Stripe semnat local cu secretul webhook-ului din setări (whsec_…), trimis la /webhook/stripe. */
$secretWebhook = 'whsec_test_inventat';
$setare('stripe_webhook_secret', $secretWebhook);
$webhook = static function (array $eveniment, ?string $secret = null) use ($baza, $secretWebhook): array {
    $corp = (string) json_encode($eveniment);
    $t = time();
    $semnatura = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $corp, $secret ?? $secretWebhook);
    $c = curl_init($baza . '/webhook/stripe');
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $corp, CURLOPT_RETURNTRANSFER => true, CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '',
        CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Stripe-Signature: ' . $semnatura]]);
    $raspuns = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $cod, 'corp' => $raspuns];
};
$sesiune = static fn (string $tok, int $suma, string $stare = 'paid'): array => ['id' => 'evt_test_' . bin2hex(random_bytes(4)),
    'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_' . bin2hex(random_bytes(6)), 'object' => 'checkout.session',
        'payment_status' => $stare, 'amount_total' => $suma, 'currency' => 'ron', 'client_reference_id' => 'ref', 'payment_intent' => 'pi_test_inventat',
        'metadata' => ['payment_link_token' => $tok]]]];
$stareComanda = static fn () => $db->query('SELECT status, payment_status, paid_amount, total FROM orders WHERE id = ' . $idPlatita)->fetch(PDO::FETCH_ASSOC);
$inainte = $stareComanda();
$r = $webhook($sesiune($token, 2550), 'whsec_alt_secret');
$verifica('webhook cu semnătură greșită: 400, linkul tot neplătit', $r['cod'] === 400
    && $unu('SELECT status FROM order_payment_links WHERE token = :t', ['t' => $token]) === 'pending');
$r = $webhook($sesiune($token, 2549));
$verifica('sesiune plătită cu altă sumă (25,49 în loc de 25,50): linkul rămâne neplătit, comanda neatinsă', $r['cod'] === 200
    && $unu('SELECT status FROM order_payment_links WHERE token = :t', ['t' => $token]) === 'pending' && $stareComanda() === $inainte);
$r = $webhook($sesiune($token, 2550, 'unpaid'));
$verifica('sesiune neplătită (payment_status unpaid): linkul rămâne neplătit, comanda neatinsă (nu devine „eșuată”)', $r['cod'] === 200
    && $unu('SELECT status FROM order_payment_links WHERE token = :t', ['t' => $token]) === 'pending' && $stareComanda() === $inainte);
$r = $webhook(['id' => 'evt_pi', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_test_inventat', 'object' => 'payment_intent',
    'metadata' => ['payment_link_token' => $token, 'order_number' => (string) $comandaPlatita['order_number']]]]]);
$verifica('payment_intent.succeeded al unei plăți de link: ignorat (linkul se confirmă pe sesiune), comanda neatinsă', $r['cod'] === 200
    && $unu('SELECT status FROM order_payment_links WHERE token = :t', ['t' => $token]) === 'pending' && $stareComanda() === $inainte);
$r = $webhook($sesiune($token, 2550));
$dupa = $stareComanda();
$verifica('checkout.session.completed plătit, cu suma linkului: linkul plătit, încasat pe comandă +25,50 (= totalul), starea comenzii neschimbată',
    $r['cod'] === 200 && $unu('SELECT status FROM order_payment_links WHERE token = :t', ['t' => $token]) === 'paid'
    && abs((float) $dupa['paid_amount'] - (float) $dupa['total']) < 0.005 && $dupa['status'] === $inainte['status']
    && $dupa['payment_status'] === 'paid', json_encode([$inainte, $dupa]));
$r = $webhook($sesiune($token, 2550));
$verifica('… același eveniment a doua oară: suma nu se adaugă din nou', $r['cod'] === 200 && $stareComanda() === $dupa);
// Un link EuPlătesc nu se plătește printr-o sesiune Stripe.
$db->prepare('UPDATE orders SET paid_amount = ROUND(total - 10, 2) WHERE id = :id')->execute(['id' => $idPlatita]);
$linkEu = PaymentLink::creeaza($db, $idPlatita, (string) $comandaPlatita['order_number'], 10.0, PaymentLink::PROCESATOR_EUPLATESC, []);
$inainte = $stareComanda();
$r = $webhook($sesiune((string) ($linkEu['token'] ?? ''), 1000));
$verifica('sesiune Stripe cu cheia unui link EuPlătesc: linkul rămâne neplătit, comanda neatinsă', is_array($linkEu) && $r['cod'] === 200
    && $unu('SELECT status FROM order_payment_links WHERE token = :t', ['t' => (string) $linkEu['token']]) === 'pending' && $stareComanda() === $inainte);
// Adminul: „Trimite linkul de plată” alege Stripe când e singurul procesator pornit (emailul pleacă în dosarul de dezvoltare).
$setare('euplatesc_enabled', '0');
$setare('bt_ipay_enabled', '0');
$setare('stripe_secret_key', 'sk_test_inventat');
$db->prepare("UPDATE order_payment_links SET status = 'cancelled' WHERE order_id = :id AND status = 'pending'")->execute(['id' => $idPlatita]);
$db->prepare('UPDATE orders SET paid_amount = ROUND(total - 7, 2) WHERE id = :id')->execute(['id' => $idPlatita]);
$r = json_decode($cerere('POST', '/admin/orders/' . $idPlatita . '/payment-link', $jarAdmin)['corp'], true) ?: [];
$verifica('Admin → comanda → „Trimite linkul de plată”: cu doar Stripe pornit, linkul nou e Stripe, iar răspunsul o spune',
    (($r['ok'] ?? false) === true || str_contains((string) ($r['error'] ?? ''), 'emailul nu a plecat')) && ($r['procesator'] ?? 'Stripe') === 'Stripe'
    && $unu("SELECT procesator FROM order_payment_links WHERE order_id = :id AND status = 'pending' ORDER BY id DESC LIMIT 1", ['id' => $idPlatita]) === 'stripe',
    json_encode($r));

// =====================================================================
// WP-21 A. Anti-spamul recenziilor (C10)
// =====================================================================
$scrie('');
$scrie('== WP-21 A. Recenziile: capcana, limitele, filtrul Spam, bulina (C10)');
$verifica('ReviewGuard: tiparele de atac, linkurile, BBCode-ul, domeniile de test, numele generate și textele fără cuvinte sunt prinse',
    \App\Support\ReviewGuard::motivDinContinut('Ion', "-1' OR 2+150-150-1=0+0+0+1 or 'H3CkKkLG'='") === 'tipar de atac automat'
    && \App\Support\ReviewGuard::motivDinContinut('Ion', 'bun 1 UNION SELECT parola FROM admini') === 'tipar de atac automat'
    && \App\Support\ReviewGuard::motivDinContinut('Ion', 'vezi https://a.ro si www.b.ro') === 'prea multe linkuri'
    && \App\Support\ReviewGuard::motivDinContinut('Ion', 'super [url=http://x]aici[/url]') === 'cod BBCode de spam'
    && \App\Support\ReviewGuard::motivDinContinut('Ion', 'Produs foarte bun', 'ion@mailinator.com') === 'adresă de email de test sau temporară'
    && \App\Support\ReviewGuard::motivDinContinut('fnfOzvSR fnfOzvSR', 'Produs foarte bun') === 'nume generat automat'
    && \App\Support\ReviewGuard::motivDinContinut('Ion', '=1=2=3=4=5=6=7=8') === 'text fără cuvinte, doar semne');
$verifica('… recenziile adevărate trec (diacritice, „=” într-o frază, nume cu majuscule, un singur link)',
    \App\Support\ReviewGuard::motivDinContinut('Maria-Ioana Popescu', 'Ceaiul e foarte bun, l-am luat de 3 ori = cel mai bun raport preț/calitate.') === null
    && \App\Support\ReviewGuard::motivDinContinut('Ștefan Țurcanu', 'Recomand! Detalii pe https://exemplu.ro', 'stefan@exemplu.ro') === null
    && \App\Support\ReviewGuard::motiv(['name' => 'Ana', 'text' => 'Foarte bun', 'opened_at' => time() - 60]) === null
    && \App\Support\ReviewGuard::motiv(['name' => 'Ana', 'text' => 'Foarte bun', 'honeypot' => 'http://spam']) === 'câmp-capcană completat'
    && \App\Support\ReviewGuard::motiv(['name' => 'Ana', 'text' => 'Foarte bun', 'opened_at' => time()]) === 'formular trimis prea repede');

$setare('recenzii_insigna_meniu', '1');
$produsRecenzie = $db->query("SELECT id, slug FROM products WHERE is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$paginaProdus = $cerere('GET', '/produs/' . rawurlencode((string) $produsRecenzie['slug']))['corp'];
$verifica('formularul de recenzie are capcana (review_website, ascunsă) și momentul deschiderii (review_opened_at)',
    str_contains($paginaProdus, 'name="review_website" value="" tabindex="-1"') && preg_match('/name="review_opened_at" value="\d{10}"/', $paginaProdus) === 1);
// Recenziile de mai jos vin de la 127.0.0.1: rândurile vechi de la aceeași adresă nu au voie să atingă limita.
$db->exec("UPDATE product_reviews SET ip_address = NULL WHERE ip_address = '127.0.0.1'");
$trimiteRecenzie = static function (array $campuri) use ($cerere, $produsRecenzie, $jarViz): array {
    return $cerere('POST', '/produs/' . rawurlencode((string) $produsRecenzie['slug']) . '/review', $jarViz, $campuri + ['review_rating' => '5',
        'review_opened_at' => (string) (time() - 30), 'review_website' => '']);
};
@unlink($jarViz);
$trimiteRecenzie(['review_name' => 'Ana Test', 'review_text' => 'Recenzie adevărată de test, produs bun ' . getmypid()]);
$normala = $db->query("SELECT is_spam, spam_reason, ip_address, is_approved FROM product_reviews ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$verifica('o recenzie obișnuită: în așteptare, nemarcată, cu adresa vizitatorului (127.0.0.1)', (int) $normala['is_spam'] === 0
    && $normala['spam_reason'] === null && $normala['ip_address'] === '127.0.0.1' && (int) $normala['is_approved'] === 0, json_encode($normala));
$trimiteRecenzie(['review_name' => 'Robot', 'review_text' => 'Text de robot, capcana completată ' . getmypid(), 'review_website' => 'http://spam.example']);
$capcana = $db->query("SELECT id, is_spam, spam_reason FROM product_reviews ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$verifica('capcana completată: salvată, dar marcată spam („câmp-capcană completat”), nu se pierde', (int) $capcana['is_spam'] === 1
    && $capcana['spam_reason'] === 'câmp-capcană completat', json_encode($capcana));
$trimiteRecenzie(['review_name' => 'Ana Test', 'review_text' => 'Recenzie adevărată de test, produs bun ' . getmypid()]);
$verifica('același text trimis din nou: spam („text identic …”)', $unu('SELECT spam_reason FROM product_reviews ORDER BY id DESC LIMIT 1') === 'text identic cu o recenzie trimisă recent');
$trimiteRecenzie(['review_name' => 'Ana Test', 'review_text' => 'A patra recenzie de la aceeași adresă ' . getmypid()]);
$trimiteRecenzie(['review_name' => 'Ana Test', 'review_text' => 'A cincea recenzie de la aceeași adresă ' . getmypid()]);
$verifica('a cincea recenzie într-o oră de la aceeași adresă: spam („prea multe recenzii de la aceeași adresă IP”)',
    $unu('SELECT spam_reason FROM product_reviews ORDER BY id DESC LIMIT 1') === 'prea multe recenzii de la aceeași adresă IP');
$cerere('POST', '/review-form/submit', $jarViz, ['product_id' => (string) $produsRecenzie['id'], 'review_name' => 'QR Test', 'review_email' => 'qr@exemplu.ro',
    'review_text' => 'Prin formularul QR ' . getmypid(), 'review_rating' => '4', 'review_opened_at' => (string) time(), 'redirect_to' => '/']);
$verifica('formularul QR: trimis imediat după deschidere → spam („formular trimis prea repede”)',
    $unu('SELECT spam_reason FROM product_reviews ORDER BY id DESC LIMIT 1') === 'formular trimis prea repede');
@unlink($jarViz);

$inAsteptare = (int) $unu('SELECT COUNT(*) FROM product_reviews WHERE is_approved = 0 AND is_spam = 0');
$spam = (int) $unu('SELECT COUNT(*) FROM product_reviews WHERE is_spam = 1');
$lista = $cerere('GET', '/admin/products/reviews?status=pending', $jarAdmin)['corp'];
$listaSpam = $cerere('GET', '/admin/products/reviews?status=spam', $jarAdmin)['corp'];
$verifica('Admin → Recenzii: „Pending (' . $inAsteptare . ')” fără spam, „Spam (' . $spam . ')” cu ele, butoanele spamului; bulina meniului = ' . $inAsteptare,
    str_contains($lista, 'Pending (' . $inAsteptare . ')') && str_contains($lista, 'Spam (' . $spam . ')') && str_contains($lista, 'Detectează spamul')
    && str_contains($lista, 'Golește spamul (' . $spam . ')') && str_contains($lista, 'id="recenzii-spam-date"')
    && !str_contains($lista, 'Text de robot, capcana completată') && str_contains($listaSpam, 'Text de robot, capcana completată')
    && preg_match('#Recenzii<span class="nav-badge" title="' . $inAsteptare . ' în așteptare"[^>]*>' . $inAsteptare . '</span>#u', $lista) === 1);
$setare('recenzii_insigna_meniu', '0');
$verifica('… cu recenzii_insigna_meniu = 0 (înghețarea Bioscem): fără bulină', !str_contains($cerere('GET', '/admin/products/reviews', $jarAdmin)['corp'], 'nav-badge'));
$setare('recenzii_insigna_meniu', '1');
$idNormala = (int) $unu("SELECT id FROM product_reviews WHERE review_text LIKE 'Recenzie adevărată de test%' ORDER BY id ASC LIMIT 1");
$cerere('POST', '/admin/products/reviews', $jarAdmin, ['id' => (string) $idNormala, 'action' => 'spam', 'status' => 'pending']);
$marcata = $db->query('SELECT is_spam, spam_reason, is_approved FROM product_reviews WHERE id = ' . $idNormala)->fetch(PDO::FETCH_ASSOC);
$cerere('POST', '/admin/products/reviews', $jarAdmin, ['id' => (string) $idNormala, 'action' => 'not_spam', 'status' => 'spam']);
$scoasa = $db->query('SELECT is_spam, spam_reason FROM product_reviews WHERE id = ' . $idNormala)->fetch(PDO::FETCH_ASSOC);
$verifica('„Spam” pe rând: marcată manual (și neaprobată); „Nu e spam”: înapoi în așteptare', (int) $marcata['is_spam'] === 1
    && $marcata['spam_reason'] === 'marcată manual' && (int) $marcata['is_approved'] === 0 && (int) $scoasa['is_spam'] === 0 && $scoasa['spam_reason'] === null);
$db->prepare("INSERT INTO product_reviews (product_id, user_name, user_email, rating, review_text, source, is_approved) VALUES (:p, 'Scaner', '', 5, :t, 'product_page', 0)")
    ->execute(['p' => (int) $produsRecenzie['id'], 't' => '1 AND SLEEP(5) ' . getmypid()]);
$idAtac = (int) $db->lastInsertId();
$cerere('POST', '/admin/products/reviews', $jarAdmin, ['action' => 'scan_spam', 'status' => 'pending']);
$verifica('„Detectează spamul”: recenzia veche cu tipar de atac trece în spam, cea adevărată nu', (int) $unu('SELECT is_spam FROM product_reviews WHERE id = ' . $idAtac) === 1
    && (int) $unu('SELECT is_spam FROM product_reviews WHERE id = ' . $idNormala) === 0);
// OD-15: adresele mai vechi de recenzii_ip_zile (90) se golesc la deschiderea listei; cu 0 rămân.
$db->prepare("INSERT INTO product_reviews (product_id, user_name, user_email, rating, review_text, source, is_approved, ip_address, created_at)
    VALUES (:p, 'Veche', '', 5, :t, 'product_page', 1, '198.51.100.7', NOW() - INTERVAL 100 DAY)")->execute(['p' => (int) $produsRecenzie['id'], 't' => 'Recenzie veche ' . getmypid()]);
$idVeche = (int) $db->lastInsertId();
$setare('recenzii_ip_zile', '0');
$cerere('GET', '/admin/products/reviews', $jarAdmin);
$pastrata = $unu('SELECT ip_address FROM product_reviews WHERE id = ' . $idVeche);
$setare('recenzii_ip_zile', '90');
$cerere('GET', '/admin/products/reviews', $jarAdmin);
$verifica('adresa unei recenzii mai vechi de 90 de zile se golește (OD-15, recenzii_ip_zile); cu 0 rămâne; cea de azi rămâne',
    $pastrata === '198.51.100.7' && $unu('SELECT ip_address FROM product_reviews WHERE id = ' . $idVeche) === ''
    && $unu("SELECT COUNT(*) FROM product_reviews WHERE ip_address = '127.0.0.1'") !== '0');
$totalInainte = (int) $unu('SELECT COUNT(*) FROM product_reviews');
$spamInainte = (int) $unu('SELECT COUNT(*) FROM product_reviews WHERE is_spam = 1');
$cerere('POST', '/admin/products/reviews', $jarAdmin, ['action' => 'delete_spam_all', 'status' => 'spam']);
$verifica('„Golește spamul”: șterge exact recenziile spam (' . $spamInainte . '), restul rămân', (int) $unu('SELECT COUNT(*) FROM product_reviews WHERE is_spam = 1') === 0
    && (int) $unu('SELECT COUNT(*) FROM product_reviews') === $totalInainte - $spamInainte);

// =====================================================================
// WP-21 B. Cupoanele unice: produsele schimbate în masă (C12)
// =====================================================================
$scrie('');
$scrie('== WP-21 B. Cupoanele unice: produsele în masă (C12)');
$cupoaneUnice = array_map('intval', $db->query("SELECT id FROM coupons WHERE is_unique = 1 AND used_at IS NULL ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN));
$produseCupon = array_map('intval', $db->query('SELECT id FROM products WHERE is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
$cerere('POST', '/admin/coupons/unique/action', $jarAdmin, ['action' => 'bulk_edit', 'scope' => 'selected', 'ids' => array_map('strval', $cupoaneUnice),
    'edit_products' => '1', 'product_ids' => array_map('strval', $produseCupon), 'apply_only_selected_products' => '1']);
$randuriCupoane = $db->query('SELECT product_ids_json, apply_only_selected_products FROM coupons WHERE id IN (' . implode(',', $cupoaneUnice) . ')')->fetchAll(PDO::FETCH_ASSOC);
$asteptat = json_encode($produseCupon);
$verifica('editarea în masă cu „Produse”: lista bifată și „doar la produsele selectate” pe ambele cupoane alese',
    count($cupoaneUnice) === 2 && count($randuriCupoane) === 2 && array_unique(array_column($randuriCupoane, 'product_ids_json')) === [(string) $randuriCupoane[0]['product_ids_json']]
    && json_decode((string) $randuriCupoane[0]['product_ids_json'], true) === $produseCupon && (int) $randuriCupoane[0]['apply_only_selected_products'] === 1,
    json_encode($randuriCupoane) . ' vs ' . $asteptat);
$cerere('POST', '/admin/coupons/unique/action', $jarAdmin, ['action' => 'bulk_edit', 'scope' => 'selected', 'ids' => array_map('strval', $cupoaneUnice), 'edit_products' => '1']);
$verifica('… fără niciun produs bifat: fără restricție de produs (lista goală), „doar la selectate” scos',
    (int) $unu('SELECT COUNT(*) FROM coupons WHERE id IN (' . implode(',', $cupoaneUnice) . ") AND (product_ids_json IS NULL OR product_ids_json IN ('', '[]'))
        AND apply_only_selected_products = 0") === 2);
$paginaUnice = $cerere('GET', '/admin/coupons/unique', $jarAdmin)['corp'];
$verifica('pagina cupoanelor unice: rândul „Produse” în panoul de editare și produsele fiecărui cupon în datele lui',
    str_contains($paginaUnice, 'name="edit_products"') && str_contains($paginaUnice, 'id="vuc-produse-date"') && str_contains($paginaUnice, '"' . $cupoaneUnice[0] . '":{"p":'));

// =====================================================================
// WP-21 C. Produsele promoționale: „dat la” / „dat de”, exportul Excel (C13)
// =====================================================================
$scrie('');
$scrie('== WP-21 C. Produsele promoționale: „dat la”, „dat de”, exportul Excel (C13)');
$promo = $db->query('SELECT order_id, promo_product_id FROM order_promo_items ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
$idComandaPromo = (int) $promo['order_id'];
$altPromo = (int) $unu('SELECT id FROM promotional_products WHERE id <> :p ORDER BY id LIMIT 1', ['p' => (int) $promo['promo_product_id']]);
$db->prepare("UPDATE order_promo_items SET given_at = '2026-01-02 03:04:05', given_by = 'operator.vechi@exemplu.ro' WHERE order_id = :o")->execute(['o' => $idComandaPromo]);
$r = json_decode($cerere('POST', '/admin/orders/' . $idComandaPromo . '/promo', $jarAdmin, ['promo_product_id' => [(string) $promo['promo_product_id'], (string) $altPromo],
    'quantity' => ['1', '2']])['corp'], true) ?: [];
$randuriPromo = $db->query('SELECT promo_product_id, given_at, given_by FROM order_promo_items WHERE order_id = ' . $idComandaPromo . ' ORDER BY promo_product_id')
    ->fetchAll(PDO::FETCH_ASSOC);
$peProdus = array_column($randuriPromo, null, 'promo_product_id');
$verifica('re-salvarea promoționalelor comenzii: cel deja dat își păstrează „dat la / dat de”, cel nou primește acum și emailul adminului',
    ($r['ok'] ?? false) === true && ($peProdus[(int) $promo['promo_product_id']]['given_at'] ?? '') === '2026-01-02 03:04:05'
    && ($peProdus[(int) $promo['promo_product_id']]['given_by'] ?? '') === 'operator.vechi@exemplu.ro'
    && abs(strtotime((string) ($peProdus[$altPromo]['given_at'] ?? '')) - time()) < 120 && ($peProdus[$altPromo]['given_by'] ?? '') === $adminEmail,
    json_encode($randuriPromo));
$api = json_decode($cerere('GET', '/admin/promo-products/' . $altPromo . '/recipients', $jarAdmin)['corp'], true) ?: [];
$verifica('„Cui a fost dat” (API): „dat la” și „dat de” pe fiecare rând', ($api['ok'] ?? false) === true
    && in_array($adminEmail, array_column((array) ($api['recipients'] ?? []), 'given_by'), true), json_encode($api));
$export = $cerere('GET', '/admin/promo-products/export?from=2000-01-01', $jarAdmin);
$fisierXlsx = $dosarDev . '/test-porturi-bv-' . getmypid() . '.xlsx';
file_put_contents($fisierXlsx, $export['corp']);
$zip = new ZipArchive();
$deschis = $zip->open($fisierXlsx) === true;
$foaie2 = $deschis ? (string) $zip->getFromName('xl/worksheets/sheet2.xml') : '';
$workbook = $deschis ? (string) $zip->getFromName('xl/workbook.xml') : '';
if ($deschis) {
    $zip->close();
}
@unlink($fisierXlsx);
$verifica('exportul Excel: o arhivă xlsx validă (Content-Type, numele fișierului), foile „Produse” și „Cui a fost dat”, cu „Dat la”, „Dat de” și adminul',
    $export['cod'] === 200 && str_contains((string) ($export['antete']['content-type'] ?? ''), 'spreadsheetml')
    && str_contains((string) ($export['antete']['content-disposition'] ?? ''), 'promotionale-') && $deschis
    && str_contains($workbook, 'name="Produse"') && str_contains($workbook, 'name="Cui a fost dat"')
    && str_contains($foaie2, '>Dat la<') && str_contains($foaie2, '>Dat de<') && str_contains($foaie2, htmlspecialchars($adminEmail, ENT_XML1)),
    'cod ' . $export['cod'] . ' ' . json_encode($export['antete']));
$paginaPromo = $cerere('GET', '/admin/promo-products', $jarAdmin)['corp'];
$verifica('pagina promoționalelor: butonul „Export Excel” (sus și lângă filtru, cu intervalul) și fereastra care derulează',
    substr_count($paginaPromo, '⭳ Export Excel') === 2 && str_contains($paginaPromo, '#promo-detail-body{flex:1 1 auto;min-height:0;overflow-y:auto;'));

// =====================================================================
// WP-21 D. Newsletterul: fundalul gol, blocul video, statisticile, diagnosticul (C11)
// =====================================================================
$scrie('');
$scrie('== WP-21 D. Newsletterul: fundalul gol, blocul video, statisticile, diagnosticul, popup-ul (C11)');
$randeaza = static fn (array $bloc): string => \App\Support\NewsletterService::renderHtmlFromBlocks(\App\Support\NewsletterService::normalizeBlocks([$bloc]), 'Test');
$cuAlb = $randeaza(['type' => 'text', 'content' => 'Text', 'background' => '#ffffff']);
$faraCheie = $randeaza(['type' => 'text', 'content' => 'Text']);
$gol = $randeaza(['type' => 'text', 'content' => 'Text', 'background' => '']);
$verifica('fundalul blocului: #ffffff și cheia lipsă ies ca înainte (background:#ffffff;), gol iese fără fundal (nu „background:;”)',
    str_contains($cuAlb, 'background:#ffffff;') && $faraCheie === $cuAlb && !str_contains($gol, 'background:;') && $gol !== $cuAlb);
$video = $randeaza(['type' => 'video', 'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'thumbnail_url' => '/uploads/video-thumbs/x.jpg',
    'title' => 'Titlu video', 'label' => '▶ Vezi pe YouTube']);
$verifica('blocul video în email: coperta ca link spre videoclip, titlul și butonul; niciun <video> sau <iframe>',
    str_contains($video, 'href="https://www.youtube.com/watch?v=dQw4w9WgXcQ"') && str_contains($video, 'video-thumbs/x.jpg') && str_contains($video, 'Titlu video')
    && str_contains($video, '▶ Vezi pe YouTube') && !str_contains($video, '<video') && !str_contains($video, '<iframe'));
$verifica('VideoEmbed::furnizor / copertaSiTitlu fără cereri în afară: TikTok, YouTube, Vimeo, fișier; un link necunoscut sau ftp:// → eroare; un .mp4 → fără oEmbed',
    \App\Support\VideoEmbed::furnizor('https://www.tiktok.com/@cont/video/123') === 'tiktok' && \App\Support\VideoEmbed::furnizor('https://youtu.be/dQw4w9WgXcQ') === 'youtube'
    && \App\Support\VideoEmbed::furnizor('https://vimeo.com/123456') === 'vimeo' && \App\Support\VideoEmbed::furnizor('/uploads/gallery/clip.mp4') === 'file'
    && \App\Support\VideoEmbed::furnizor('https://exemplu.ro/pagina') === ''
    && (\App\Support\VideoEmbed::copertaSiTitlu('https://exemplu.ro/pagina')['ok'] ?? true) === false
    && (\App\Support\VideoEmbed::copertaSiTitlu('ftp://exemplu.ro/a.mp4')['ok'] ?? true) === false
    && (\App\Support\VideoEmbed::copertaSiTitlu('https://exemplu.ro/clip.mp4')['provider'] ?? '') === 'file');
$lookup = json_decode($cerere('POST', '/admin/emails/builder/video-lookup', $jarAdmin, ['url' => 'https://exemplu.ro/pagina'])['corp'], true) ?: [];
$lookupAnonim = json_decode($cerere('POST', '/admin/emails/builder/video-lookup', null, ['url' => 'https://exemplu.ro/clip.mp4'])['corp'], true);
$verifica('POST /admin/emails/builder/video-lookup: un link nerecunoscut → ok false cu mesaj; fără admin, nimic', ($lookup['ok'] ?? true) === false
    && str_contains((string) ($lookup['error'] ?? ''), 'Nu recunosc linkul') && (!is_array($lookupAnonim) || ($lookupAnonim['ok'] ?? true) === false), json_encode($lookup));
$constructor = $cerere('GET', '/admin/emails/builder', $jarAdmin)['corp'];
$verifica('constructorul de emailuri: blocul „Video / TikTok” în paletă și în cod, fundalul blocurilor din coloane („Fără fundal”)',
    str_contains($constructor, 'data-block-type="video"') && str_contains($constructor, 'window.ebVideoLookup') && str_contains($constructor, 'function subBlockDefault(type)')
    && str_contains($constructor, 'Fără fundal (se vede culoarea coloanei)'));
$idCampanie = (int) $unu('SELECT id FROM newsletter_campaigns ORDER BY id LIMIT 1');
// Cu subscriber_id: din WP-R (Bioscem e2e2d77) statisticile numără doar trimiterile unui abonat (cele fără, testele de admin, nu).
$stmtSend = $db->prepare("INSERT INTO newsletter_campaign_sends (campaign_id, subscriber_id, email, status, opened_at, open_count, clicked_at, click_count, sent_at)
    VALUES (:c, :sid, :e, :s, :o, :oc, NULL, 0, NOW())");
foreach ([['deschis1@exemplu.ro', 'sent', date('Y-m-d H:i:s'), 1, 900001], ['nedeschis1@exemplu.ro', 'sent', null, 0, 900002],
    ['esuat1@exemplu.ro', 'failed', null, 0, 900003]] as [$e, $st, $o, $oc, $sid]) {
    $stmtSend->execute(['c' => $idCampanie, 'sid' => $sid, 'e' => $e, 's' => $st, 'o' => $o, 'oc' => $oc]);
}
$stats = $cerere('GET', '/admin/emails/newsletters?tab=stats&campaign_id=' . $idCampanie . '&recip_f=failed', $jarAdmin)['corp'];
$verifica('statisticile campaniei: destinatarii filtrați pe server (doar eșuatele), panoul care se reîncarcă pe loc',
    str_contains($stats, 'esuat1@exemplu.ro') && !str_contains($stats, 'deschis1@exemplu.ro') && str_contains($stats, 'id="nl-recip-panel"'));
$iesire = [];
exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/tools/newsletter/diagnostic-campanie.php') . ' ' . $idCampanie
    . ' --fata-de=' . $idCampanie . ' --emailuri 2>&1', $iesire, $codDiag);
$verifica('tools/newsletter/diagnostic-campanie.php: rulează (cod 0), fără nicio adresă de email în ieșire, nici cu --emailuri',
    $codDiag === 0 && !str_contains(implode("\n", $iesire), '@'), implode(' | ', array_slice($iesire, 0, 6)));
$verifica('popup-ul de abonare: public/assets/css/optin-popup.css și public/assets/js/optin-popup.js',
    is_file($radacina . '/public/assets/css/optin-popup.css') && is_file($radacina . '/public/assets/js/optin-popup.js')
    && $cerere('GET', '/assets/js/optin-popup.js')['cod'] === 200);

// =====================================================================
// Revizuirea WP-19…WP-21: recenzia aprobată din Spam, momentul lipsă, cronul, cursa cuponului
// =====================================================================
$scrie('');
$scrie('== Revizuire: recenzia aprobată din Spam, formularul fără moment, adresele golite de cron, cursa cuponului');
$db->prepare("INSERT INTO product_reviews (product_id, user_name, user_email, rating, review_text, source, is_approved, is_spam, spam_reason)
    VALUES (:p, 'Din spam', '', 5, :t, 'product_page', 0, 1, 'marcată manual')")->execute(['p' => (int) $produsRecenzie['id'], 't' => 'Aprobată din spam ' . getmypid()]);
$idDinSpam = (int) $db->lastInsertId();
$db->prepare("INSERT INTO product_reviews (product_id, user_name, user_email, rating, review_text, source, is_approved, is_spam, spam_reason)
    VALUES (:p, 'Spam publicat', '', 5, :t, 'product_page', 1, 1, 'marcată manual')")->execute(['p' => (int) $produsRecenzie['id'], 't' => 'Publicată și marcată ' . getmypid()]);
$idPublicataMarcata = (int) $db->lastInsertId();
$cerere('POST', '/admin/products/reviews', $jarAdmin, ['id' => (string) $idDinSpam, 'action' => 'approve', 'status' => 'spam']);
$aprobata = $db->query('SELECT is_approved, is_spam, spam_reason FROM product_reviews WHERE id = ' . $idDinSpam)->fetch(PDO::FETCH_ASSOC) ?: [];
$cerere('POST', '/admin/products/reviews', $jarAdmin, ['action' => 'delete_spam_all', 'status' => 'spam']);
$verifica('„Aprobă” din tabul Spam: publicată și scoasă din spam; „Golește spamul” nu șterge nicio recenzie aprobată',
    (int) ($aprobata['is_approved'] ?? 0) === 1 && (int) ($aprobata['is_spam'] ?? 1) === 0 && array_key_exists('spam_reason', $aprobata) && $aprobata['spam_reason'] === null
    && $unu('SELECT COUNT(*) FROM product_reviews WHERE id IN (' . $idDinSpam . ', ' . $idPublicataMarcata . ')') === '2',
    json_encode($aprobata));
$db->exec('DELETE FROM product_reviews WHERE id IN (' . $idDinSpam . ', ' . $idPublicataMarcata . ')');

// Formularul fără review_opened_at: primit în termenul de grație de după migrarea recenzii/0030 (pagini din cache), spam după.
$pornire = $unu("SELECT terminata_la FROM _platforma_migrari WHERE id = 'recenzii/0030_recenzii_spam'");
$zileGratie = \App\Support\ReviewGuard::ZILE_GRATIE_FARA_MOMENT;
$verifica('fără momentul deschiderii: acceptat în cele ' . $zileGratie . ' zile de după pornirea anti-spamului, spam după; fără migrare, mereu acceptat',
    $pornire !== '' && !\App\Support\ReviewGuard::faraMomentSuspect(date('Y-m-d H:i:s', time() - 3600), time())
    && \App\Support\ReviewGuard::faraMomentSuspect(date('Y-m-d H:i:s', time() - ($zileGratie * 86400 + 60)), time())
    && !\App\Support\ReviewGuard::faraMomentSuspect(null, time())
    && \App\Support\ReviewGuard::motiv(['name' => 'Ana', 'text' => 'Foarte bun'], $db) === null);
$db->prepare("UPDATE _platforma_migrari SET terminata_la = :t WHERE id = 'recenzii/0030_recenzii_spam'")
    ->execute(['t' => date('Y-m-d H:i:s', time() - ($zileGratie + 1) * 86400)]);
$motivDupaGratie = \App\Support\ReviewGuard::motiv(['name' => 'Ana', 'text' => 'Foarte bun'], $db);
$motivCuMoment = \App\Support\ReviewGuard::motiv(['name' => 'Ana', 'text' => 'Foarte bun', 'opened_at' => time() - 60], $db);
$db->prepare("UPDATE _platforma_migrari SET terminata_la = :t WHERE id = 'recenzii/0030_recenzii_spam'")->execute(['t' => $pornire]);
$verifica('… după termen: formularul fără moment e spam („formular fără momentul deschiderii”), cel cu moment trece',
    $motivDupaGratie === 'formular fără momentul deschiderii' && $motivCuMoment === null, (string) $motivDupaGratie);

// OD-15: cronul magazinului golește și el adresele vechi (nu doar o recenzie nouă sau lista din admin).
$db->prepare("INSERT INTO product_reviews (product_id, user_name, user_email, rating, review_text, source, is_approved, ip_address, created_at)
    VALUES (:p, 'Veche cron', '', 5, :t, 'product_page', 1, '198.51.100.9', NOW() - INTERVAL 120 DAY)")->execute(['p' => (int) $produsRecenzie['id'], 't' => 'Recenzie veche cron ' . getmypid()]);
$idVecheCron = (int) $db->lastInsertId();
$mailuriInainte = glob($dosarDev . '/mail/*') ?: [];
exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/scripts/abandoned-cart-emails.php') . ' --limit=1 2>&1', $iesireCron, $codCron);
array_map('unlink', array_diff(glob($dosarDev . '/mail/*') ?: [], $mailuriInainte));
$verifica('cronul scripts/abandoned-cart-emails.php golește adresa unei recenzii de 120 de zile (recenzii_ip_zile 90), cu aceeași ieșire',
    $codCron === 0 && $unu('SELECT ip_address FROM product_reviews WHERE id = ' . $idVecheCron) === ''
    && str_starts_with((string) end($iesireCron), 'Abandoned cart email sync: '), implode(' | ', $iesireCron));
$db->exec('DELETE FROM product_reviews WHERE id = ' . $idVecheCron);

// Cursa cuponului: între verificarea din coș (buildSummary) și tranzacția comenzii, alt client îl folosește. Testul ține
// el rândul cuponului blocat (o tranzacție deschisă) cât timp comanda e trimisă, apoi îl folosește și închide tranzacția:
// comanda nu se creează, clientul ajunge în coș cu „Cuponul a fost deja folosit.”.
$cursaCupon = static function (string $cod, callable $folosesteCuponul) use ($db, $cerere, $unu, $idAlt, $dosarDev, $baza): array {
    $jar = $dosarDev . '/test-porturi-bv-cursa-' . getmypid() . '.txt';
    @unlink($jar);
    $cerere('POST', '/cos/adauga/' . $idAlt, $jar, ['quantity' => '1']);
    $cerere('POST', '/cos/cupon', $jar, ['coupon_code' => $cod]);
    $pagina = $cerere('GET', '/checkout', $jar)['corp'];
    preg_match('/name="checkout_form_token" value="([^"]*)"/', $pagina, $token);
    preg_match('/name="checkout_form_rendered_at" value="([^"]*)"/', $pagina, $randat);
    sleep(3); // garda anti-bot cere câteva secunde între afișare și trimitere
    $ultima = (int) $unu('SELECT COALESCE(MAX(id), 0) FROM orders');
    $db->beginTransaction();
    $db->prepare('SELECT id FROM coupons WHERE code = :c FOR UPDATE')->execute(['c' => $cod]);
    $folosesteCuponul($db);
    $c = curl_init($baza . '/checkout');
    $antete = [];
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$antete): int {
            if (str_contains($linie, ':')) {
                [$nume, $valoare] = explode(':', $linie, 2);
                $antete[strtolower(trim($nume))] = trim($valoare);
            }
            return strlen($linie);
        },
        CURLOPT_POSTFIELDS => http_build_query([
            'checkout_form_token' => $token[1] ?? '', 'checkout_form_rendered_at' => $randat[1] ?? '',
            'billing_first_name' => 'Test', 'billing_last_name' => 'Cursa', 'billing_phone' => '0700000779',
            'billing_email' => 'cursa@exemplu.ro', 'billing_street' => 'Strada Exemplului', 'billing_street_no' => '9',
            'billing_city' => 'Satu Inventat', 'billing_county' => 'Cluj', 'billing_postcode' => '400001',
            'shipping_same_as_billing' => '1', 'payment_method' => 'cod', 'accept_terms' => '1',
        ])]);
    $multi = curl_multi_init();
    curl_multi_add_handle($multi, $c);
    $pana = microtime(true) + 2.5; // cererea trece de verificarea din coș și așteaptă rândul blocat
    do {
        curl_multi_exec($multi, $active);
        curl_multi_select($multi, 0.1);
    } while ($active && microtime(true) < $pana);
    $db->commit();
    do {
        curl_multi_exec($multi, $active);
        curl_multi_select($multi, 0.2);
    } while ($active);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_multi_remove_handle($multi, $c);
    curl_multi_close($multi);
    $comenziNoi = (int) $unu('SELECT COUNT(*) FROM orders WHERE id > ' . $ultima);
    $cos = $cerere('GET', '/cos', $jar)['corp'];
    @unlink($jar);
    return ['cod' => $cod, 'location' => $antete['location'] ?? '', 'comenzi_noi' => $comenziNoi, 'mesaj' => str_contains($cos, 'Cuponul a fost deja folosit.')];
};
$codUnic = 'CURSA-UNIC-' . getmypid();
$db->prepare("INSERT INTO coupons (name, code, type, value, is_active, is_unique) VALUES ('Cursa unic', :c, 'percent', 10, 1, 1)")->execute(['c' => $codUnic]);
$r = $cursaCupon($codUnic, static function (PDO $db) use ($codUnic): void {
    $db->prepare('UPDATE coupons SET used_at = NOW() WHERE code = :c')->execute(['c' => $codUnic]);
});
$verifica('cupon unic folosit de altă comandă în timpul finalizării: nicio comandă, înapoi în coș cu „Cuponul a fost deja folosit.”',
    $r['cod'] === 302 && $r['location'] === '/cos' && $r['comenzi_noi'] === 0 && $r['mesaj'], json_encode($r));
$codMax = 'CURSA-MAX-' . getmypid();
$db->prepare("INSERT INTO coupons (name, code, type, value, is_active, max_uses_total) VALUES ('Cursa max', :c, 'percent', 10, 1, 1)")->execute(['c' => $codMax]);
$comandaMartor = (int) $unu("SELECT id FROM orders WHERE deleted_at IS NULL AND status NOT IN ('cancelled', 'failed', 'refunded', 'returned') ORDER BY id LIMIT 1");
$cuponMartor = $unu('SELECT coupon_code FROM orders WHERE id = ' . $comandaMartor);
$r = $cursaCupon($codMax, static function (PDO $db) use ($codMax, $comandaMartor): void {
    $db->prepare('UPDATE orders SET coupon_code = :c WHERE id = :id')->execute(['c' => $codMax, 'id' => $comandaMartor]);
});
$verifica('cupon cu max_uses_total = 1 a cărui ultimă folosire o ia altă comandă în timpul finalizării: nicio comandă, înapoi în coș',
    $r['cod'] === 302 && $r['location'] === '/cos' && $r['comenzi_noi'] === 0 && $r['mesaj'], json_encode($r));
$db->prepare('UPDATE orders SET coupon_code = :c WHERE id = :id')->execute(['c' => $cuponMartor !== '' ? $cuponMartor : null, 'id' => $comandaMartor]);
$db->prepare('DELETE FROM coupons WHERE code IN (:a, :b)')->execute(['a' => $codUnic, 'b' => $codMax]);

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
