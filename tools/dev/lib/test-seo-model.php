<?php

declare(strict_types=1);

/**
 * tools/dev/test-seo.php, secțiunea E1 (WP-37, SEO partea B, B1): modelul SEO al paginilor (App\Support\Seo), panoul din
 * admin și mărcile și etichetele ca entități (App\Support\MarciEtichete, migrarea catalog/0042). Inclus de test-seo.php
 * (pe serverul de dezvoltare, licența „toate”), cu variabilele lui: $verifica, $scrie, $cerere, $port, $db, $radacina,
 * $jarNou, $adminEmail, $parola.
 *
 *  1. Seo::rezolva dă exact ce dădea views/layout.php înainte (regula veche scrisă aici, în test, ca oracol), pe fiecare
 *     combinație: prima pagină, titlul scris, setările, SEO_TITLURI oprit, 404/410, căile excluse, „fără canonical”, robots.
 *  2. Pe fiecare pagină demo (harta site-ului, plus marca, eticheta, categoria și eticheta blogului, magazinul): fără
 *     rânduri SEO noi, <title>, description, canonical, robots și og:type; un rând de fiecare tip schimbă exact câmpurile
 *     lui (și doar pe pagina lui); după ștergerea rândurilor, fiecare pagină e din nou exact ca la început.
 *  3. Verificările panoului: lipsă, peste 60/155 de caractere, același titlu pe altă pagină (cu linkul ei).
 *  4. Mărcile și etichetele: migrarea rulată din nou de două ori dă aceleași rânduri; sincronizarea nu mai schimbă nimic;
 *     fiecare /marca/{slug} și /eticheta/{slug} din produse răspunde 200 și are rândul ei.
 */

use App\Support\MarciEtichete;
use App\Support\Seo;
use App\Support\SeoIndexare;
use App\Support\SeoMeta;
use App\Support\Slug;

$scrie('');
$scrie('== E1. Modelul SEO al paginilor și panoul din admin (WP-37)');

// ---------------------------------------------------------------------------------------------------------------------
// 1. Oracolul: regula din views/layout.php de dinainte de WP-37, copiată.
// ---------------------------------------------------------------------------------------------------------------------
$oracol = static function (array $ctx): array {
    $designSettings = $ctx['setari'];
    $requestPath = $ctx['cale'];
    $siteBaseTitle = SeoMeta::numeMagazin($designSettings);
    $pageTitle = trim((string) ($ctx['title'] ?? ''));
    $homeSeoTitle = trim((string) ($designSettings['store_seo_home_title'] ?? ''));
    $homeSeoDescription = trim((string) ($designSettings['store_seo_home_description'] ?? ''));
    $metaTitleFinal = trim((string) ($ctx['metaTitle'] ?? ''));
    $metaDescriptionFinal = trim((string) ($ctx['metaDescription'] ?? ''));
    if (SeoMeta::activ()) {
        if ($requestPath === '/' && $homeSeoTitle !== '') {
            $metaTitleFinal = $homeSeoTitle;
        }
        if ($requestPath === '/' && $homeSeoDescription !== '') {
            $metaDescriptionFinal = $homeSeoDescription;
        }
        $metaTitleFinal = SeoMeta::titluPagina($metaTitleFinal, $pageTitle, $siteBaseTitle);
    } elseif ($metaTitleFinal === '') {
        if ($requestPath === '/' && $homeSeoTitle !== '') {
            $metaTitleFinal = $homeSeoTitle;
        } elseif ($pageTitle === '' || strcasecmp($pageTitle, $siteBaseTitle) === 0) {
            $metaTitleFinal = $siteBaseTitle;
        } else {
            $metaTitleFinal = $pageTitle . ' | ' . $siteBaseTitle;
        }
    }
    if ($metaDescriptionFinal === '') {
        if ($requestPath === '/') {
            $metaDescriptionFinal = $homeSeoDescription;
        }
        if ($metaDescriptionFinal === '') {
            $metaDescriptionFinal = trim((string) ($designSettings['store_seo_default_description'] ?? ''));
        }
    }
    $appUrl = $ctx['appUrl'];
    $canonicalUrlFinal = trim((string) ($ctx['metaCanonicalUrl'] ?? ''));
    if ($canonicalUrlFinal === '') {
        $canonicalUrlFinal = $appUrl . ($requestPath === '/' ? '/' : $requestPath);
    }
    if (!empty($ctx['faraCanonical']) || SeoIndexare::faraCanonicalPentruCod((int) $ctx['cod'], (string) $ctx['cerere'])) {
        $canonicalUrlFinal = '';
    }
    $robots = isset($ctx['metaRobots']) && preg_match('/^[a-z ,-]+$/i', trim((string) $ctx['metaRobots'])) === 1 ? trim((string) $ctx['metaRobots']) : '';
    return ['titlu' => $metaTitleFinal, 'descriere' => $metaDescriptionFinal, 'canonical' => $canonicalUrlFinal, 'robots' => $robots,
        'og_type' => 'website'];
};
$setariVariante = [
    [],
    ['store_name' => 'Magazin Test E1'],
    ['store_seo_home_title' => 'Acasă scris', 'store_seo_home_description' => 'Descrierea primei pagini'],
    ['store_seo_default_description' => 'Descrierea implicită a site-ului', 'store_name' => 'Magazin Test E1'],
];
$cazuri = 0;
$diferite = [];
$seoTitluriInainte = getenv('SEO_TITLURI');
foreach (['1', '0'] as $titluriActive) {
    putenv('SEO_TITLURI=' . $titluriActive);
    $_ENV['SEO_TITLURI'] = $titluriActive;
    $_SERVER['SEO_TITLURI'] = $titluriActive;
    foreach ($setariVariante as $setari) {
        foreach (['/', '/produs/ceai-de-menta', '/cos', '/magazin'] as $cale) {
            foreach (['', 'Titlul scris | ceva -', 'Magazin Test E1'] as $metaTitle) {
                foreach (['', 'Ceai de mentă', 'Magazin Test E1'] as $titlu) {
                    foreach ([[200, false, ''], [404, false, 'noindex,follow'], [410, false, ''], [200, true, 'noindex, follow'],
                        [200, false, 'x<y'], [200, false, 'NOINDEX,nofollow']] as [$cod, $faraCanonical, $robots]) {
                        foreach (['', 'https://exemplu.test/alta'] as $canonical) {
                            $ctx = ['setari' => $setari, 'cale' => $cale, 'title' => $titlu, 'metaTitle' => $metaTitle,
                                'metaDescription' => $metaTitle === '' ? '' : 'Descriere scrisă', 'metaCanonicalUrl' => $canonical,
                                'metaRobots' => $robots, 'faraCanonical' => $faraCanonical, 'cod' => $cod, 'cerere' => $cale . '?x=1',
                                'appUrl' => 'http://127.0.0.1:' . $port];
                            $asteptat = $oracol($ctx);
                            $primit = array_intersect_key(Seo::rezolva($ctx), $asteptat);
                            $cazuri++;
                            if ($primit !== $asteptat && count($diferite) < 3) {
                                $diferite[] = json_encode([$ctx, $asteptat, $primit], JSON_UNESCAPED_UNICODE);
                            }
                        }
                    }
                }
            }
        }
    }
}
if ($seoTitluriInainte === false) {
    putenv('SEO_TITLURI');
    unset($_ENV['SEO_TITLURI'], $_SERVER['SEO_TITLURI']);
} else {
    putenv('SEO_TITLURI=' . $seoTitluriInainte);
    $_ENV['SEO_TITLURI'] = $_SERVER['SEO_TITLURI'] = $seoTitluriInainte;
}
$verifica('Seo::rezolva = regula de dinainte din views/layout.php, pe ' . $cazuri . ' de combinații (prima pagină, titlul scris, '
    . 'setările, SEO_TITLURI oprit, 404/410, căi excluse, fără canonical, robots)', $diferite === [], implode(' | ', $diferite));
$verifica('Seo::rezolva: og:type „website” fără nimic scris, valoarea scrisă doar din listă',
    Seo::rezolva(['cale' => '/', 'appUrl' => 'x'])['og_type'] === 'website'
    && Seo::rezolva(['cale' => '/', 'appUrl' => 'x', 'metaOgType' => 'article'])['og_type'] === 'article'
    && Seo::rezolva(['cale' => '/', 'appUrl' => 'x', 'metaOgType' => 'altceva"'])['og_type'] === 'website');
$verifica('Seo::normalizeaza: robots și og:type doar din listă, canonical doar http(s), textul listei fără script',
    Seo::normalizeaza(['robots' => 'noindex, follow', 'og_type' => 'video', 'canonical_url' => 'javascript:x', 'intro_html' => '<p onclick="x()">Da</p><script>y()</script>'])
        === ['title' => '', 'description' => '', 'canonical_url' => '', 'image_url' => '', 'robots' => 'noindex,follow', 'og_type' => '',
            'intro_html' => Seo::curataIntro('<p onclick="x()">Da</p><script>y()</script>')]
    && !str_contains(Seo::curataIntro('<p onclick="x()">Da</p><script>y()</script>'), 'script')
    && !str_contains(Seo::curataIntro('<p onclick="x()">Da</p>'), 'onclick'));

// ---------------------------------------------------------------------------------------------------------------------
// 2. Paginile demo: fără rânduri noi = ca înainte; fiecare rând schimbă exact câmpurile lui; după ștergere, ca la început.
// ---------------------------------------------------------------------------------------------------------------------
$cap = static function (string $html): array {
    $unu = static function (string $tipar) use ($html): ?string {
        return preg_match($tipar, $html, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
    };
    return [
        'titlu' => $unu('#<title>(.*?)</title>#s'),
        'descriere' => $unu('#<meta name="description" content="([^"]*)"#'),
        'canonical' => $unu('#<link rel="canonical" href="([^"]*)"#'),
        'robots' => $unu('#<meta name="robots" content="([^"]*)"#'),
        'og_type' => $unu('#<meta property="og:type" content="([^"]*)"#'),
        'intro' => str_contains($html, '<div class="lista-intro">') ? 'da' : null,
    ];
};
$sitemap = $cerere($port, 'GET', '/sitemap.xml')['corp'];
preg_match_all('#<loc>https?://[^/<]+(/[^<]*)</loc>#', $sitemap, $m);
$pagini = array_values(array_unique(array_merge($m[1], ['/magazin', '/marca/livada-demo', '/eticheta/demo', '/eticheta/reducere',
    '/blog/categorie/noutati', '/blog/eticheta/ceaiuri-demo', '/categorie/ceaiuri', '/produs/nu-exista-e1', '/blog'])));
$idBlogCategorie = (string) (int) $db->query("SELECT id FROM blog_categories WHERE slug = 'noutati'")->fetchColumn();
$idBlogEticheta = (string) (int) $db->query("SELECT id FROM blog_tags WHERE slug = 'ceaiuri-demo'")->fetchColumn();
$idCategorie = (int) $db->query("SELECT id FROM product_categories WHERE slug = 'ceaiuri'")->fetchColumn();
$idProdus = (string) (int) $db->query("SELECT id FROM products WHERE slug = 'ceai-de-menta'")->fetchColumn();
$idPagina = (string) (int) $db->query("SELECT id FROM pages WHERE slug = 'despre-noi' AND deleted_at IS NULL")->fetchColumn();
$randuriTest = [
    // [tip, ref, câmpuri, pagina, ce se schimbă pe ea]
    ['tag', 'demo', ['robots' => 'noindex,follow'], '/eticheta/demo', ['robots' => 'noindex,follow']],
    ['brand', 'livada-demo', ['title' => 'Livada Demo: ceaiuri E1', 'intro_html' => '<p>Textul mărcii E1.</p>'], '/marca/livada-demo',
        ['titlu' => 'Livada Demo: ceaiuri E1', 'intro' => 'da']],
    ['listing_default', Seo::REF_LISTA, ['description' => 'Toate produsele E1, descriere scrisă.'], '/magazin',
        ['descriere' => 'Toate produsele E1, descriere scrisă.']],
    ['blog_category', $idBlogCategorie, ['title' => 'Noutăți E1', 'intro_html' => '<p>Despre noutăți.</p>'], '/blog/categorie/noutati',
        ['titlu' => 'Noutăți E1', 'intro' => 'da']],
    ['blog_tag', $idBlogEticheta, ['og_type' => 'article', 'description' => 'Ceaiuri, eticheta E1.'], '/blog/eticheta/ceaiuri-demo',
        ['og_type' => 'article', 'descriere' => 'Ceaiuri, eticheta E1.']],
    ['product', $idProdus, ['robots' => 'noindex,nofollow', 'og_type' => 'product'], '/produs/ceai-de-menta',
        ['robots' => 'noindex,nofollow', 'og_type' => 'product']],
    ['custom_page', $idPagina, ['canonical_url' => 'https://exemplu.test/despre-noi-e1'], '/despre-noi',
        ['canonical' => 'https://exemplu.test/despre-noi-e1']],
];
// Rândurile acestor pagini de dinainte (datele demo pot avea unele), puse la loc la final.
$inainte = [];
foreach ($randuriTest as [$tip, $ref]) {
    $stmt = $db->prepare('SELECT * FROM seo_pages WHERE page_type = ? AND page_ref = ?');
    $stmt->execute([$tip, $ref]);
    $inainte[$tip . '|' . $ref] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$introCategorieInainte = $db->query('SELECT intro_html FROM product_categories WHERE id = ' . $idCategorie)->fetchColumn();
$puneLaLoc = static function () use ($db, $inainte, $idCategorie, $introCategorieInainte): void {
    foreach ($inainte as $cheie => $rand) {
        [$tip, $ref] = explode('|', $cheie, 2);
        $db->prepare('DELETE FROM seo_pages WHERE page_type = ? AND page_ref = ?')->execute([$tip, $ref]);
        if (is_array($rand)) {
            $coloane = array_keys($rand);
            $db->prepare('INSERT INTO seo_pages (' . implode(', ', $coloane) . ') VALUES (' . implode(', ', array_fill(0, count($coloane), '?')) . ')')
                ->execute(array_values($rand));
        }
    }
    $db->prepare('UPDATE product_categories SET intro_html = ? WHERE id = ?')->execute([$introCategorieInainte === false ? null : $introCategorieInainte, $idCategorie]);
    \App\Support\ResponseCache::purgePageCache();
};
try {
    foreach ($inainte as $cheie => $rand) {
        [$tip, $ref] = explode('|', $cheie, 2);
        $db->prepare('DELETE FROM seo_pages WHERE page_type = ? AND page_ref = ?')->execute([$tip, $ref]);
    }
    $db->prepare('UPDATE product_categories SET intro_html = NULL WHERE id = ?')->execute([$idCategorie]);
    \App\Support\ResponseCache::purgePageCache();
    $baza = [];
    foreach ($pagini as $cale) {
        $baza[$cale] = $cap($cerere($port, 'GET', $cale)['corp']);
    }
    $faraIntro = array_filter($baza, static fn (array $c): bool => $c['intro'] === null);
    $verifica('fără rânduri SEO: ' . count($pagini) . ' pagini demo, niciuna cu text de listă, og:type „website” peste tot',
        count($faraIntro) === count($baza) && array_filter($baza, static fn (array $c): bool => $c['og_type'] !== 'website') === []);
    // Fiecare rând, singur: schimbă exact câmpurile lui, pe pagina lui, nimic altundeva.
    foreach ($randuriTest as [$tip, $ref, $campuri, $paginaRand, $schimbari]) {
        Seo::salveaza($db, $tip, $ref, $campuri);
        \App\Support\ResponseCache::purgePageCache();
        $probleme = [];
        foreach ($pagini as $cale) {
            $acum = $cap($cerere($port, 'GET', $cale)['corp']);
            $asteptat = $cale === $paginaRand ? array_merge($baza[$cale], $schimbari) : $baza[$cale];
            if ($acum !== $asteptat) {
                $probleme[] = $cale . ': ' . json_encode(array_diff_assoc(array_map('strval', $acum), array_map('strval', $asteptat)), JSON_UNESCAPED_UNICODE);
            }
        }
        $verifica('un rând „' . $tip . '” (' . implode(', ', array_keys($campuri)) . ') schimbă doar ' . implode(', ', array_keys($schimbari))
            . ' pe ' . $paginaRand, $probleme === [], implode('; ', array_slice($probleme, 0, 4)));
        Seo::sterge($db, $tip, $ref);
    }
    // Categoria de produse: textul de deasupra listei stă în product_categories.intro_html.
    $db->prepare('UPDATE product_categories SET intro_html = ? WHERE id = ?')->execute(['<p>Ceaiurile noastre, E1.</p>', $idCategorie]);
    \App\Support\ResponseCache::purgePageCache();
    $c = $cerere($port, 'GET', '/categorie/ceaiuri');
    $q = $cerere($port, 'GET', '/categorie/ceaiuri?q=menta');
    $verifica('categoria: textul de deasupra listei apare (și nu pe căutarea internă); restul capului e cel de la început',
        str_contains($c['corp'], '<div class="lista-intro"><p>Ceaiurile noastre, E1.</p></div>')
        && array_merge($cap($c['corp']), ['intro' => null]) === $baza['/categorie/ceaiuri'] && !str_contains($q['corp'], 'Ceaiurile noastre, E1.'));
    $db->prepare('UPDATE product_categories SET intro_html = NULL WHERE id = ?')->execute([$idCategorie]);
    \App\Support\ResponseCache::purgePageCache();
    $inapoi = [];
    foreach ($pagini as $cale) {
        if ($cap($cerere($port, 'GET', $cale)['corp']) !== $baza[$cale]) {
            $inapoi[] = $cale;
        }
    }
    $verifica('după ștergerea rândurilor, fiecare pagină demo e din nou exact ca la început', $inapoi === [], implode(', ', $inapoi));

    // Admin: salvările păstrează câmpurile noi pe care formularul nu le are și le scriu pe cele pe care le are.
    $jarSeo = $jarNou('seo-e1');
    $cerere($port, 'POST', '/admin/login', [], $jarSeo, ['email' => $adminEmail, 'password' => $parola]);
    Seo::salveaza($db, 'brand', 'livada-demo', ['robots' => 'noindex,follow', 'intro_html' => '<p>Păstrat.</p>']);
    $cerere($port, 'POST', '/admin/categories/brand-seo', [], $jarSeo, ['brand_slug' => 'livada-demo', 'seo_title' => 'Titlu nou', 'seo_description' => '']);
    $dupaVechi = Seo::citeste($db, 'brand', 'livada-demo');
    $cerere($port, 'POST', '/admin/categories/brand-seo', [], $jarSeo, ['brand_slug' => 'livada-demo', 'seo_title' => 'Titlu nou', 'seo_description' => 'D',
        'seo_robots' => '', 'seo_intro_html' => '<p>Nou</p><script>x()</script>']);
    $dupaNou = Seo::citeste($db, 'brand', 'livada-demo');
    $cerere($port, 'POST', '/admin/categories/brand-seo', [], $jarSeo, ['seo_tip' => 'tag', 'tag_slug' => 'demo', 'seo_title' => 'Eticheta demo E1', 'seo_robots' => 'noindex,follow']);
    $cerere($port, 'POST', '/admin/categories/brand-seo', [], $jarSeo, ['seo_tip' => 'listing_default', 'seo_title' => '', 'seo_description' => 'Lista E1']);
    $verifica('admin: un formular fără robots și text de listă nu le șterge; cu ele le scrie (curățate); etichetele și lista produselor se '
        . 'salvează prin același formular', $dupaVechi['robots'] === 'noindex,follow' && $dupaVechi['intro_html'] === '<p>Păstrat.</p>'
        && $dupaVechi['title'] === 'Titlu nou' && $dupaNou['robots'] === '' && str_contains($dupaNou['intro_html'], '<p>Nou</p>')
        && !str_contains($dupaNou['intro_html'], 'script') && Seo::citeste($db, 'tag', 'demo')['title'] === 'Eticheta demo E1'
        && Seo::citeste($db, 'tag', 'demo')['robots'] === 'noindex,follow' && Seo::citeste($db, 'listing_default', Seo::REF_LISTA)['description'] === 'Lista E1',
        json_encode([$dupaVechi, $dupaNou], JSON_UNESCAPED_UNICODE));
    $pagCategorii = $cerere($port, 'GET', '/admin/categories', [], $jarSeo)['corp'];
    $verifica('admin: Categorii produse are panoul SEO la fiecare categorie, la lista produselor, la mărci și la etichete',
        substr_count($pagCategorii, 'data-adm-seo=') >= 5 && str_contains($pagCategorii, '{&quot;tip&quot;:&quot;tag&quot;')
        && str_contains($pagCategorii, '{&quot;tip&quot;:&quot;listing_default&quot;') && str_contains($pagCategorii, 'name="intro_html"'));
    $v = json_decode($cerere($port, 'GET', '/admin/products/seo-verifica?tip=product&ref=1&titlu=a&descriere=b', ['Accept: application/json'], $jarSeo)['corp'], true);
    $v2 = $cerere($port, 'GET', '/admin/products/seo-verifica?tip=custom_page&ref=1&titlu=a', [], $jarSeo)['cod'];
    $v3 = $cerere($port, 'GET', '/admin/products/seo-verifica?tip=product&ref=1&titlu=a')['cod'];
    $verifica('verificarea panoului: JSON pentru tipurile adresei ei, 400 pentru altele, 401 fără logare', ($v['ok'] ?? false) === true
        && $v2 === 400 && $v3 === 401, json_encode([$v, $v2, $v3]));
    foreach (['brand' => 'livada-demo', 'tag' => 'demo', 'listing_default' => Seo::REF_LISTA] as $tip => $ref) {
        Seo::sterge($db, $tip, $ref);
    }
} finally {
    $puneLaLoc();
}

// ---------------------------------------------------------------------------------------------------------------------
// 3. Verificările panoului, pe datele demo.
// ---------------------------------------------------------------------------------------------------------------------
$coduri = static fn (array $v, string $camp): array => array_values(array_map(static fn (array $x): string => $x['cod'],
    array_filter($v, static fn (array $x): bool => $x['camp'] === $camp)));
$idAlt = (string) (int) $db->query("SELECT id FROM products WHERE slug <> 'ceai-de-menta' AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
$titluDuplicat = 'Ceai de mentă bio, titlu E1 dublat';
$inainteAlt = Seo::citeste($db, 'product', $idAlt);
$areRandAlt = Seo::areRand($db, 'product', $idAlt);
try {
    Seo::salveaza($db, 'product', $idAlt, ['title' => $titluDuplicat, 'description' => 'Descrierea E1 dublată.']);
    $gol = Seo::verificari($db, 'product', $idProdus, '', '');
    $lung = Seo::verificari($db, 'product', $idProdus, str_repeat('a', 61), str_repeat('b', 156));
    $exact = Seo::verificari($db, 'product', $idProdus, str_repeat('a', 60), str_repeat('b', 155));
    $dublat = Seo::verificari($db, 'product', $idProdus, '  ceai DE mentă bio,  titlu E1 dublat ', 'Descrierea E1 dublată.');
    $sine = Seo::verificari($db, 'product', $idAlt, $titluDuplicat, '');
    $alte = (array) ($dublat[0]['alte'] ?? []);
    $verifica('panoul: titlu și descriere lipsă', $coduri($gol, 'titlu') === ['lipsa'] && $coduri($gol, 'descriere') === ['lipsa']);
    $verifica('panoul: peste 60 / 155 de caractere „prea lung”, exact 60 / 155 nu', $coduri($lung, 'titlu') === ['lung']
        && $coduri($lung, 'descriere') === ['lung'] && $exact === []);
    $verifica('panoul: același titlu (litere mari, spații) și aceeași descriere pe alt produs: „duplicat”, cu linkul lui; nu și față de el însuși',
        $coduri($dublat, 'titlu') === ['duplicat'] && $coduri($dublat, 'descriere') === ['duplicat']
        && ($alte[0]['link'] ?? '') === '/admin/products#editeaza-' . $idAlt && str_starts_with((string) ($alte[0]['nume'] ?? ''), 'produsul „')
        && $coduri($sine, 'titlu') === [], json_encode($dublat, JSON_UNESCAPED_UNICODE));
} finally {
    if ($areRandAlt) {
        Seo::salveaza($db, 'product', $idAlt, $inainteAlt);
    } else {
        Seo::sterge($db, 'product', $idAlt);
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// 4. Mărcile și etichetele ca entități.
// ---------------------------------------------------------------------------------------------------------------------
$instantaneu = static function () use ($db): array {
    return [
        'marci' => $db->query('SELECT slug, name FROM product_brands ORDER BY slug')->fetchAll(PDO::FETCH_NUM),
        'etichete' => $db->query('SELECT slug, name FROM product_tags ORDER BY slug')->fetchAll(PDO::FETCH_NUM),
        'legaturi' => $db->query('SELECT l.product_id, t.slug FROM product_tag_links l JOIN product_tags t ON t.id = l.tag_id ORDER BY 1, 2')
            ->fetchAll(PDO::FETCH_NUM),
    ];
};
$golesteEntitati = static function () use ($db): void {
    $db->exec('DELETE FROM product_tag_links');
    $db->exec('DELETE FROM product_tags');
    $db->exec('DELETE FROM product_brands');
};
// Migrarea catalog/0042, rulată din nou (rândul ei din registru scos), pe tabelele golite: de două ori.
$reruleazaMigrarea = static function () use ($db, $radacina): int {
    $db->exec("DELETE FROM _platforma_migrari WHERE id = 'catalog/0042_marci_etichete'");
    exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' scripts/migreaza.php --aplica 2>&1', $iesire, $cod);
    return $cod;
};
$golesteEntitati();
$cod1 = $reruleazaMigrarea();
$dupaMigrare = $instantaneu();
$cod2 = $reruleazaMigrarea();
$dupaADoua = $instantaneu();
$schimbate = MarciEtichete::sincronizeaza($db);
$dupaSincronizare = $instantaneu();
$golesteEntitati();
MarciEtichete::sincronizeaza($db);
$dinSincronizare = $instantaneu();
$aDouaSincronizare = MarciEtichete::sincronizeaza($db);
$verifica('mărcile și etichetele: migrarea catalog/0042 rulată de două ori dă aceleași rânduri, sincronizarea după ea nu schimbă nimic, '
    . 'iar sincronizarea de la zero dă exact rândurile migrării (de două ori = la fel)', $cod1 === 0 && $cod2 === 0 && $dupaMigrare === $dupaADoua
    && $schimbate === 0 && $dupaSincronizare === $dupaMigrare && $dinSincronizare === $dupaMigrare && $aDouaSincronizare === 0
    && $dupaMigrare['marci'] !== [] && $dupaMigrare['legaturi'] !== [],
    'coduri ' . $cod1 . '/' . $cod2 . ', schimbate ' . $schimbate . '/' . $aDouaSincronizare . ', ' . count($dupaMigrare['marci']) . ' mărci, '
    . count($dupaMigrare['etichete']) . ' etichete, ' . count($dupaMigrare['legaturi']) . ' legături');
// Fiecare marcă și etichetă din produse are rândul ei, cu slug-ul paginii de azi, iar pagina răspunde 200 (aceleași adrese).
$slugMarci = array_column($dupaMigrare['marci'], 0);
$slugEtichete = array_column($dupaMigrare['etichete'], 0);
$probleme = [];
foreach ($db->query('SELECT brand, tags_json FROM products WHERE deleted_at IS NULL AND is_active = 1')->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $marca = trim((string) $p['brand']);
    if ($marca !== '') {
        $s = Slug::simplu($marca);
        if (!in_array($s, $slugMarci, true) || $cerere($port, 'GET', '/marca/' . rawurlencode($s))['cod'] !== 200) {
            $probleme[] = '/marca/' . $s;
        }
    }
    foreach ((array) json_decode((string) $p['tags_json'], true) as $e) {
        $s = Slug::simplu((string) $e);
        if ($s !== '' && (!in_array($s, $slugEtichete, true) || $cerere($port, 'GET', '/eticheta/' . rawurlencode($s))['cod'] !== 200)) {
            $probleme[] = '/eticheta/' . $s;
        }
    }
}
$verifica('fiecare marcă și etichetă a produselor active are rândul ei (slug-ul paginii de azi) și pagina ei răspunde 200',
    $probleme === [], implode(', ', array_unique($probleme)));
// Un produs salvat cu altă etichetă: legăturile lui se schimbă, ale celorlalte nu.
$stmt = $db->prepare('SELECT tags_json FROM products WHERE id = ?');
$stmt->execute([(int) $idProdus]);
$eticheteInainte = $stmt->fetchColumn();
try {
    $db->prepare('UPDATE products SET tags_json = ? WHERE id = ?')->execute([json_encode(['Etichetă Nouă E1']), (int) $idProdus]);
    MarciEtichete::sincronizeazaProdus($db, (int) $idProdus);
    $legaturi = $db->prepare('SELECT t.slug FROM product_tag_links l JOIN product_tags t ON t.id = l.tag_id WHERE l.product_id = ?');
    $legaturi->execute([(int) $idProdus]);
    $verifica('un produs salvat cu altă etichetă: o singură legătură, la „eticheta-noua-e1”', $legaturi->fetchAll(PDO::FETCH_COLUMN) === ['eticheta-noua-e1']);
} finally {
    $db->prepare('UPDATE products SET tags_json = ? WHERE id = ?')->execute([$eticheteInainte, (int) $idProdus]);
    $db->exec("DELETE FROM product_tags WHERE slug = 'eticheta-noua-e1'");
    MarciEtichete::sincronizeaza($db);
}
$verifica('după test: entitățile sunt exact cele ale migrării', $instantaneu() === $dupaMigrare);
