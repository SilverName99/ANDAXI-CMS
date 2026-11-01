<?php

declare(strict_types=1);

/**
 * tools/dev/test-seo.php, secțiunea E3 (WP-39, SEO partea B, B3/B4, BV-B5, VT-S1, GR): sitemap-ul pe tipuri, datele
 * structurate ale fiecărui fel de pagină, livrarea și returul din admin, codul EAN, lista blogului, catalogul GR și
 * verificarea adreselor vechi (tools/verificare/verifica-redirecturi.php). Inclus de test-seo.php după E2, cu
 * variabilele lui ($verifica, $scrie, $cerere, $port, $db, $radacina, $jarNou, $adminEmail, $parola, $locatie, $app).
 *
 *  1. /sitemap.xml e indexul; fiecare sitemap din el răspunde 200 (XML); fiecare adresă din fiecare sitemap răspunde
 *     200, are canonicalul spre ea și nu are noindex; fiecare <url> are <lastmod>; produsele cu poze au <image:image>.
 *  2. Nu intră: pagina ștearsă, nepublicată, de sistem, doar pentru membri, produsul inactiv, categoria de blog
 *     „noindex”, eticheta de blog sub minimul de articole (intră cu un articol publicat).
 *  3. Datele structurate: Product (name, image, offers.price/priceCurrency/availability; gtin13 cu un EAN valid),
 *     BreadcrumbList (position/name/item), BlogPosting (headline, datePublished, author, image), Organization (name,
 *     url, logo; NGO din seo.json → organizatie.tip), Event (name, startDate, location), ItemList (itemListElement);
 *     niciun bloc pe o pagină doar pentru membri.
 *  4. Livrarea și returul din admin (Setări magazin → SEO Google), codul EAN din editorul produsului (unul greșit nu se
 *     salvează), blogul (căutarea, filtrele din adresă, paginile 2+), /produse?categorie= pe catalogul GR.
 *  5. verifica-redirecturi.php pe o listă inventată de 40 de adrese (200, 301, 410 voit și o problemă voită).
 */

use App\Support\DateSite;
use App\Support\JsonLd;
use App\Support\PoliticaLivrare;
use App\Support\Seo;
use App\Support\Settings;
use App\Support\Sitemap;

$scrie('');
$scrie('== E3. Sitemap-ul pe tipuri, datele structurate, verificarea adreselor vechi (WP-39)');

$cale = static fn (string $adresa): string => (string) (parse_url($adresa, PHP_URL_PATH) ?? '/')
    . (parse_url($adresa, PHP_URL_QUERY) !== null ? '?' . parse_url($adresa, PHP_URL_QUERY) : '');
/** Toate adresele sitemap-urilor: [tip => [loc => ['lastmod' => …, 'poze' => n]]]. */
$citesteSitemapuri = static function () use ($cerere, $port, $cale): array {
    $index = $cerere($port, 'GET', '/sitemap.xml')['corp'];
    preg_match_all('#<sitemap>\s*<loc>([^<]+)</loc>#', $index, $m);
    $tipuri = [];
    foreach ($m[1] as $loc) {
        $r = $cerere($port, 'GET', $cale($loc));
        $tip = (string) preg_replace('#^/sitemap-([a-z-]+)\.xml$#', '$1', $cale($loc));
        $tipuri[$tip] = ['cod' => $r['cod'], 'xml' => str_contains($r['corp'], '<urlset'), 'adrese' => []];
        preg_match_all('#<url>(.*?)</url>#s', $r['corp'], $u);
        foreach ($u[1] as $bloc) {
            preg_match('#<loc>([^<]+)</loc>#', $bloc, $l);
            preg_match('#<lastmod>([^<]+)</lastmod>#', $bloc, $d);
            $tipuri[$tip]['adrese'][html_entity_decode((string) ($l[1] ?? ''), ENT_QUOTES | ENT_XML1)] = [
                'lastmod' => (string) ($d[1] ?? ''), 'poze' => substr_count($bloc, '<image:loc>')];
        }
    }
    return ['index' => $index, 'tipuri' => $tipuri];
};
$toateAdresele = static function (array $s): array {
    $toate = [];
    foreach ($s['tipuri'] as $tip) {
        $toate += $tip['adrese'];
    }
    return $toate;
};

// ---------------------------------------------------------------------------------------------------------------------
// 1. Indexul și sitemap-urile pe tipuri.
// ---------------------------------------------------------------------------------------------------------------------
$s = $citesteSitemapuri();
$verifica('/sitemap.xml e indexul sitemap-urilor pe tipuri (pagini, produse, categorii, mărci, etichete, articole, categoriile blogului), '
    . 'fiecare 200 cu <urlset>', str_contains($s['index'], '<sitemapindex') && array_diff(['pagini', 'produse', 'categorii', 'marci', 'articole',
    'blog-categorii'], array_keys($s['tipuri'])) === [] && array_filter($s['tipuri'], static fn (array $t): bool => $t['cod'] !== 200 || !$t['xml']) === [],
    implode(', ', array_keys($s['tipuri'])));
$verifica('un tip necunoscut sau gol: 404 (/sitemap-nimic.xml, /sitemap-blog-etichete.xml fără etichete cu articole)',
    $cerere($port, 'GET', '/sitemap-nimic.xml')['cod'] === 404 && $cerere($port, 'GET', '/sitemap-blog-etichete.xml')['cod'] === 404);
$toate = $toateAdresele($s);
$rele = [];
$faraData = [];
foreach ($toate as $loc => $info) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $info['lastmod']) !== 1) {
        $faraData[] = $cale($loc);
    }
    $r = $cerere($port, 'GET', $cale($loc));
    preg_match('#<link rel="canonical" href="([^"]+)"#', $r['corp'], $c);
    if ($r['cod'] !== 200 || ($c[1] ?? '') !== $loc || preg_match('#<meta name="robots" content="[^"]*noindex#', $r['corp']) === 1
        || str_contains(strtolower((string) ($r['antete']['x-robots-tag'] ?? '')), 'noindex')) {
        $rele[] = $cale($loc) . ' (' . $r['cod'] . ', canonical ' . ($c[1] ?? '—') . ')';
    }
}
$verifica('fiecare adresă din fiecare sitemap (' . count($toate) . ') răspunde 200, are canonicalul spre ea și nu are noindex', $rele === [],
    implode('; ', array_slice($rele, 0, 5)));
$verifica('fiecare <url> are <lastmod> (data ultimei schimbări, UTC)', $faraData === [], implode(', ', array_slice($faraData, 0, 5)));
$produseCuPoze = array_filter($s['tipuri']['produse']['adrese'], static fn (array $i): bool => $i['poze'] > 0);
$verifica('produsele cu poze au <image:image> în sitemap-ul produselor (' . count($produseCuPoze) . ' din '
    . count($s['tipuri']['produse']['adrese']) . '); articolele cu poză la fel', count($produseCuPoze) > 0
    && count($produseCuPoze) === (int) $db->query("SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND is_active = 1
        AND TRIM(COALESCE(image_url, '')) <> '' AND image_url NOT LIKE '%product-placeholder%'")->fetchColumn()
    && array_filter($s['tipuri']['articole']['adrese'], static fn (array $i): bool => $i['poze'] > 0) !== []);
$verifica('fără seo_pages.updated_at la <lastmod>: Sitemap nu citește tabelul seo_pages decât pentru noindex',
    preg_match('/FROM\s+seo_pages|seo_pages\.updated_at\s*[,)]|updated_at.*seo_pages/i', (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '',
        (string) file_get_contents($radacina . '/src/Support/Sitemap.php'))) !== 1);

// ---------------------------------------------------------------------------------------------------------------------
// 2. Ce nu intră.
// ---------------------------------------------------------------------------------------------------------------------
$idPaginaE3 = 0;
$idProdusE3 = 0;
$idCategorieBlog = (int) $db->query("SELECT id FROM blog_categories WHERE slug = 'noutati'")->fetchColumn();
$idEticheta = (int) $db->query("SELECT id FROM blog_tags WHERE slug = 'ceaiuri-demo'")->fetchColumn();
$idArticol = (int) $db->query("SELECT id FROM blog_posts WHERE slug = 'cum-alegi-un-ceai-pentru-seara'")->fetchColumn();
$setariInainte = Settings::all($db);
try {
    $db->exec("DELETE FROM pages WHERE slug LIKE 'e3-%'");
    $pune = $db->prepare('INSERT INTO pages (title, slug, html_content, is_published, deleted_at, requires_active_account) VALUES (?, ?, ?, ?, ?, ?)');
    $pune->execute(['E3 ștearsă', 'e3-stearsa', '<p>x</p>', 1, date('Y-m-d H:i:s'), 0]);
    $pune->execute(['E3 ciornă', 'e3-ciorna', '<p>x</p>', 0, null, 0]);
    $pune->execute(['E3 membri', 'e3-membri', '<p>x</p>', 1, null, 1]);
    $pune->execute(['E3 publică', 'e3-publica', '<p>x</p>', 1, null, 0]);
    $idPaginaE3 = (int) $db->query("SELECT id FROM pages WHERE slug = 'e3-publica'")->fetchColumn();
    $db->prepare("INSERT INTO products (name, slug, price, stock, is_active, description, short_description) VALUES ('Produs E3 inactiv', 'produs-e3-inactiv', 10, 5, 0, '', '')")->execute();
    $idProdusE3 = (int) $db->lastInsertId();
    Settings::save($db, ['catalog_ascunde_inactive' => '1']);
    Seo::salveaza($db, 'blog_category', (string) $idCategorieBlog, ['robots' => 'noindex,follow']);
    $s2 = $citesteSitemapuri();
    $toate2 = array_map($cale, array_keys($toateAdresele($s2)));
    $intrate = array_values(array_intersect(['/e3-stearsa', '/e3-ciorna', '/e3-membri', '/cos', '/checkout', '/contul-meu', '/404',
        '/produs/produs-e3-inactiv', '/blog/categorie/noutati', '/blog/eticheta/ceaiuri-demo'], $toate2));
    $verifica('nu intră: pagina ștearsă, ciorna, pagina doar pentru membri, paginile de sistem, produsul inactiv (cu catalog_ascunde_inactive), '
        . 'categoria de blog „noindex”, eticheta de blog fără articole publicate; pagina publicată nouă intră', $intrate === []
        && in_array('/e3-publica', $toate2, true), implode(', ', $intrate));
    // Eticheta de blog cu un articol publicat intră (și pagina ei e indexabilă); sub minimul din seo.json, noindex.
    $db->prepare('INSERT IGNORE INTO blog_post_tags (post_id, tag_id) VALUES (?, ?)')->execute([$idArticol, $idEticheta]);
    $s3 = $citesteSitemapuri();
    $paginaEticheta = $cerere($port, 'GET', '/blog/eticheta/ceaiuri-demo')['corp'];
    $verifica('eticheta de blog cu un articol publicat: în /sitemap-blog-etichete.xml și fără noindex; fără articole: noindex,follow',
        isset($s3['tipuri']['blog-etichete']) && in_array('/blog/eticheta/ceaiuri-demo', array_map($cale, array_keys($toateAdresele($s3))), true)
        && !str_contains($paginaEticheta, 'noindex') && Sitemap::minimArticoleEticheta() === 1);
} finally {
    $db->exec("DELETE FROM pages WHERE slug LIKE 'e3-%'");
    if ($idProdusE3 > 0) {
        $db->prepare('DELETE FROM products WHERE id = ?')->execute([$idProdusE3]);
    }
    $db->prepare('DELETE FROM blog_post_tags WHERE post_id = ? AND tag_id = ?')->execute([$idArticol, $idEticheta]);
    Seo::sterge($db, 'blog_category', (string) $idCategorieBlog);
    Settings::save($db, ['catalog_ascunde_inactive' => (string) ($setariInainte['catalog_ascunde_inactive'] ?? '1')]);
}
$verifica('eticheta fără articole publicate: pagina ei e noindex,follow', str_contains($cerere($port, 'GET', '/blog/eticheta/ceaiuri-demo')['corp'],
    '<meta name="robots" content="noindex,follow">'));

// ---------------------------------------------------------------------------------------------------------------------
// 3. Datele structurate.
// ---------------------------------------------------------------------------------------------------------------------
$jsonLd = static function (string $corp): array {
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $corp, $m);
    $noduri = [];
    foreach ($m[1] as $json) {
        $nod = json_decode($json, true);
        if (is_array($nod)) {
            $noduri[(string) ($nod['@type'] ?? '?')] = $nod;
        }
    }
    return $noduri;
};
$are = static fn (array $nod, array $chei): array => array_values(array_filter($chei, static function (string $cheie) use ($nod): bool {
    $valoare = $nod;
    foreach (explode('.', $cheie) as $parte) {
        if (!is_array($valoare) || !array_key_exists($parte, $valoare)) {
            return true;
        }
        $valoare = $valoare[$parte];
    }
    return $valoare === '' || $valoare === [] || $valoare === null;
}));
$idMenta = (int) $db->query("SELECT id FROM products WHERE slug = 'ceai-de-menta'")->fetchColumn();
$eanInainte = $db->query('SELECT ean FROM products WHERE id = ' . $idMenta)->fetchColumn();
try {
    $db->prepare('UPDATE products SET ean = ? WHERE id = ?')->execute(['5901234123457', $idMenta]);
    $produs = $jsonLd($cerere($port, 'GET', '/produs/ceai-de-menta')['corp']);
    $lipsa = $are($produs['Product'] ?? [], ['name', 'image', 'offers.price', 'offers.priceCurrency', 'offers.availability']);
    $verifica('Product: name, image, offers.price, offers.priceCurrency, offers.availability; cu EAN valid, gtin13', $lipsa === []
        && ($produs['Product']['gtin13'] ?? '') === '5901234123457', implode(', ', $lipsa));
    $firimituri = (array) ($produs['BreadcrumbList']['itemListElement'] ?? []);
    $faraItem = array_filter(array_slice($firimituri, 0, -1), static fn (array $e): bool => !isset($e['position'], $e['name'], $e['item']));
    $verifica('BreadcrumbList: fiecare element cu position, name și item (ultimul, pagina curentă, fără item)', count($firimituri) >= 2
        && $faraItem === [] && isset(end($firimituri)['position'], end($firimituri)['name']));
    $fluxFisier = \App\Support\FluxGoogleMerchant::fisierCache();
    @unlink($fluxFisier);
    $flux = $cerere($port, 'GET', '/feed/google-merchant.xml')['corp'];
    $verifica('fluxul Google Merchant: g:gtin pentru produsul cu EAN', str_contains($flux, '<g:gtin>5901234123457</g:gtin>'));
    @unlink($fluxFisier);
} finally {
    $db->prepare('UPDATE products SET ean = ? WHERE id = ?')->execute([$eanInainte === false ? null : $eanInainte, $idMenta]);
}
$articol = $jsonLd($cerere($port, 'GET', '/blog/cum-alegi-un-ceai-pentru-seara')['corp']);
$lipsa = $are($articol['BlogPosting'] ?? [], ['headline', 'datePublished', 'author.name', 'image']);
$verifica('BlogPosting: headline, datePublished, author, image; firimiturile Acasă → Blog → categoria → articolul', $lipsa === []
    && array_column((array) ($articol['BreadcrumbList']['itemListElement'] ?? []), 'name') === ['Acasă', 'Blog', 'Noutăți', 'Cum alegi un ceai pentru seară'],
    implode(', ', $lipsa) . ' ' . json_encode(array_column((array) ($articol['BreadcrumbList']['itemListElement'] ?? []), 'name'), JSON_UNESCAPED_UNICODE));
$eveniment = $jsonLd($cerere($port, 'GET', '/blog/atelier-demo-de-degustare')['corp']);
$lipsa = $are($eveniment['Event'] ?? [], ['name', 'startDate', 'location.name']);
$verifica('Event pe articolul-eveniment: name, startDate (ziua), location; „Gratuit” = ofertă de 0 lei', $lipsa === []
    // Ziua din bază (datele demo pun evenimentul la 20 de zile de la încărcarea lor), nu o dată scrisă în test.
    && ($eveniment['Event']['startDate'] ?? '') === substr((string) $db->query("SELECT event_start_date FROM blog_posts WHERE slug = 'atelier-demo-de-degustare'")->fetchColumn(), 0, 10)
    && ($eveniment['Event']['offers']['price'] ?? '') === '0.00'
    && !isset($articol['Event']), implode(', ', $lipsa) . ' ' . json_encode($eveniment['Event'] ?? null, JSON_UNESCAPED_UNICODE));
$acasa = $jsonLd($cerere($port, 'GET', '/')['corp']);
$lipsa = $are($acasa['Organization'] ?? [], ['name', 'url', 'logo']);
$verifica('Organization pe prima pagină: name, url, logo', $lipsa === [], implode(', ', $lipsa));
$dosarDemoE3 = DateSite::dosar();
try {
    DateSite::reseteaza($radacina . '/site-uri/vertij');
    $tipVt = (string) DateSite::seo('organizatie.tip', '');
    $nodVt = JsonLd::organizatie(['name' => 'Asociația Demo', 'url' => 'https://exemplu.test', 'tip' => $tipVt, 'logo' => 'https://exemplu.test/l.png']);
} finally {
    DateSite::reseteaza($dosarDemoE3);
}
$verifica('vertij (seo.json → organizatie.tip): NGO cu name, url, logo; un tip necunoscut rămâne Organization', ($nodVt['@type'] ?? '') === 'NGO'
    && $are($nodVt, ['name', 'url', 'logo']) === [] && JsonLd::organizatie(['name' => 'X', 'url' => 'https://x.test', 'tip' => 'Ceva'])['@type'] === 'Organization');
$lista = $jsonLd($cerere($port, 'GET', '/categorie/ceaiuri')['corp']);
$elemente = (array) ($lista['ItemList']['itemListElement'] ?? []);
$verifica('ItemList pe categorie: itemListElement cu position și url (produsele afișate); nu pe căutare', $elemente !== []
    && array_filter($elemente, static fn (array $e): bool => !isset($e['position'], $e['url']) || !str_contains((string) $e['url'], '/produs/')) === []
    && !isset($jsonLd($cerere($port, 'GET', '/magazin?q=ceai')['corp'])['ItemList'])
    && isset($jsonLd($cerere($port, 'GET', '/blog')['corp'])['ItemList']));
// Un articol doar pentru membri: nicio dată structurată.
try {
    $db->prepare('UPDATE blog_posts SET requires_active_account = 1 WHERE id = ?')->execute([$idArticol]);
    $restrans = $cerere($port, 'GET', '/blog/cum-alegi-un-ceai-pentru-seara');
    $verifica('un articol doar pentru membri: niciun bloc JSON-LD pe pagina lui (' . $restrans['cod'] . ')',
        !str_contains($restrans['corp'], 'application/ld+json'));
} finally {
    $db->prepare('UPDATE blog_posts SET requires_active_account = 0 WHERE id = ?')->execute([$idArticol]);
}

// ---------------------------------------------------------------------------------------------------------------------
// 4. Livrarea și returul din admin, codul EAN, blogul, catalogul GR.
// ---------------------------------------------------------------------------------------------------------------------
$verifica('PoliticaLivrare: fără seo.json (demo) și fără setări, nimic; cu setările din admin, returul lor; „0” oprește chiar și cu seo.json',
    !PoliticaLivrare::activ([]) && PoliticaLivrare::retur([]) === []
    && PoliticaLivrare::retur(['seo_livrare_retur' => '1', 'seo_retur_zile' => '30', 'seo_retur_pagina' => '/retur', 'seo_retur_taxe' => 'gratuit'])
        === ['zile' => 30, 'pagina' => '/retur', 'taxe' => 'gratuit']
    && PoliticaLivrare::retur(['seo_livrare_retur' => '1', 'seo_retur_zile' => 'x', 'seo_retur_pagina' => '/retur']) === []
    && !PoliticaLivrare::activ(['seo_livrare_retur' => '0']));
$jarE3 = $jarNou('seo-e3');
$cerere($port, 'POST', '/admin/login', [], $jarE3, ['email' => $adminEmail, 'password' => $parola]);
$paginaSetari = $cerere($port, 'GET', '/admin/settings/store?tab=seo', [], $jarE3)['corp'];
preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $paginaSetari, $mJeton);
$cheiLivrare = array_merge(PoliticaLivrare::SETARI_ADMIN, ['seo_livrare_pregatire_min', 'seo_livrare_pregatire_max', 'seo_livrare_transport_min',
    'seo_livrare_transport_max']);
$livrareInainte = array_intersect_key(Settings::all($db), array_flip($cheiLivrare));
try {
    $cerere($port, 'POST', '/admin/settings/store', [], $jarE3, ['action' => 'save_livrare_retur_google', 'seo_livrare_retur' => '1',
        'seo_retur_zile' => '14', 'seo_retur_pagina' => '/termeni-si-conditii', 'seo_retur_taxe' => 'client', 'seo_livrare_serviciu' => 'Curier Demo']);
    $faraJeton = (string) (Settings::all($db)['seo_retur_zile'] ?? '');
    $r = $cerere($port, 'POST', '/admin/settings/store', [], $jarE3, ['_csrf' => (string) ($mJeton[1] ?? ''), 'action' => 'save_livrare_retur_google',
        'seo_livrare_retur' => '1', 'seo_retur_zile' => '14', 'seo_retur_pagina' => '/termeni-si-conditii', 'seo_retur_taxe' => 'client',
        'seo_livrare_serviciu' => 'Curier Demo', 'seo_livrare_pregatire_min' => '0', 'seo_livrare_pregatire_max' => '1',
        'seo_livrare_transport_min' => '1', 'seo_livrare_transport_max' => '3']);
    $acasaCuRetur = $jsonLd($cerere($port, 'GET', '/')['corp']);
    $verifica('Setări magazin → SEO Google → „Livrare și retur în Google”: secțiunea există, fără jeton nu salvează, cu jeton returul ajunge în '
        . 'Organization (14 zile, transportul plătit de client, linkul condițiilor)', str_contains($paginaSetari, 'id="setari-livrare-retur"')
        && $faraJeton === (string) ($livrareInainte['seo_retur_zile'] ?? '') && $r['cod'] === 302
        && ($acasaCuRetur['Organization']['hasMerchantReturnPolicy']['merchantReturnDays'] ?? null) === 14
        && str_ends_with((string) ($acasaCuRetur['Organization']['hasMerchantReturnPolicy']['merchantReturnLink'] ?? ''), '/termeni-si-conditii'),
        json_encode($acasaCuRetur['Organization']['hasMerchantReturnPolicy'] ?? null));
    $cerere($port, 'POST', '/admin/settings/store', [], $jarE3, ['_csrf' => (string) ($mJeton[1] ?? ''), 'action' => 'save_livrare_retur_google',
        'seo_livrare_retur' => '0', 'seo_retur_zile' => '14', 'seo_retur_pagina' => '/termeni-si-conditii']);
    $verifica('„Nu”: returul dispare din Organization', !isset($jsonLd($cerere($port, 'GET', '/')['corp'])['Organization']['hasMerchantReturnPolicy']));
} finally {
    Settings::save($db, array_merge(array_fill_keys($cheiLivrare, ''), $livrareInainte));
}
// Codul EAN din editorul produsului (pe un produs adăugat aici, șters la final).
$idProdusEan = 0;
try {
    $db->exec("DELETE FROM products WHERE slug = 'produs-e3-ean'");
    $db->prepare("INSERT INTO products (name, slug, price, stock, is_active, description, short_description) VALUES ('Produs E3 EAN', 'produs-e3-ean', 10, 5, 1, '', '')")
        ->execute();
    $idProdusEan = (int) $db->lastInsertId();
    $salveaza = static fn (string $ean): array => $cerere($port, 'POST', '/admin/products/' . $idProdusEan . '/update', [], $jarE3, [
        'name' => 'Produs E3 EAN', 'slug' => 'produs-e3-ean', 'price' => '10', 'stock' => '5', 'is_active' => '1', 'vat_percent' => '21',
        'vat_included' => '1', 'ean' => $ean]);
    $salveaza('5901234123457');
    $bun = $db->query('SELECT ean FROM products WHERE id = ' . $idProdusEan)->fetchColumn();
    $salveaza('5901234123458');
    $dupaGresit = $db->query('SELECT ean FROM products WHERE id = ' . $idProdusEan)->fetchColumn();
    $mesaj = $cerere($port, 'GET', '/admin/products', [], $jarE3)['corp'];
    $verifica('codul EAN din editor: unul valid se salvează, unul cu cifra de control greșită nu (rămâne cel de dinainte), cu mesaj; câmpul e în editor',
        $bun === '5901234123457' && $dupaGresit === '5901234123457' && str_contains($mesaj, 'nu e valid') && str_contains($mesaj, 'name="ean" id="p_ean"'),
        var_export([$bun, $dupaGresit], true));
} finally {
    if ($idProdusEan > 0) {
        $db->prepare('DELETE FROM products WHERE id = ?')->execute([$idProdusEan]);
    }
}
// Blogul: căutarea, filtrele din adresă, paginile 2+.
$cautare = $cerere($port, 'GET', '/blog?q=ceai')['corp'];
$filtru = $cerere($port, 'GET', '/blog?categorie=noutati')['corp'];
$filtruEticheta = $cerere($port, 'GET', '/blog?eticheta=ceaiuri-demo')['corp'];
$verifica('blogul (avansat): căutarea e noindex,follow fără canonical; ?categorie= și ?eticheta= au canonicalul paginii lor',
    str_contains($cautare, '<meta name="robots" content="noindex,follow">') && !str_contains($cautare, 'rel="canonical"')
    && str_contains($filtru, '<link rel="canonical" href="http://127.0.0.1:' . $port . '/blog/categorie/noutati">')
    && str_contains($filtruEticheta, '<link rel="canonical" href="http://127.0.0.1:' . $port . '/blog/eticheta/ceaiuri-demo">'));
$idsNoi = [];
try {
    $puneArticol = $db->prepare("INSERT INTO blog_posts (title, slug, excerpt, content, is_published, published_at, category_id) VALUES (?, ?, 'E3', '<p>E3</p>', 1, NOW() - INTERVAL 1 DAY, ?)");
    for ($i = 0; $i < 14; $i++) {
        $puneArticol->execute(['Articol E3 ' . $i, 'articol-e3-' . $i, $idCategorieBlog]);
        $idsNoi[] = (int) $db->lastInsertId();
    }
    $pagina2 = $cerere($port, 'GET', '/blog?page=2')['corp'];
    $pagina99 = $cerere($port, 'GET', '/blog?page=99')['corp'];
    preg_match('#<link rel="canonical" href="([^"]+)"#', $pagina99, $c99);
    $verifica('pagina 2 a blogului are canonicalul ei (?page=2); o pagină care nu există (?page=99) e ultima, cu canonicalul ei',
        str_contains($pagina2, '<link rel="canonical" href="http://127.0.0.1:' . $port . '/blog?page=2">')
        && preg_match('#/blog\?page=\d$#', (string) ($c99[1] ?? '')) === 1 && !str_contains((string) ($c99[1] ?? ''), 'page=99'), (string) ($c99[1] ?? ''));
} finally {
    if ($idsNoi !== []) {
        $db->exec('DELETE FROM blog_post_categories WHERE post_id IN (' . implode(',', $idsNoi) . ')');
        $db->exec('DELETE FROM blog_posts WHERE id IN (' . implode(',', $idsNoi) . ')');
    }
}
// Catalogul pe altă pagină (GR, OD-24): /produse?categorie=x → 301 /categorie/x.
$catalogInainte = (string) ($setariInainte['catalog_pagina'] ?? 'magazin');
try {
    $db->exec("DELETE FROM pages WHERE slug = 'produse-e3'");
    $db->prepare("INSERT INTO pages (title, slug, html_content, is_published) VALUES ('Produse E3', 'produse-e3', '{{shop_catalog}}', 1)")->execute();
    Settings::save($db, ['catalog_pagina' => 'produse-e3']);
    $r1 = $cerere($port, 'GET', '/produse-e3?categorie=ceaiuri&sort=price_asc');
    $r2 = $cerere($port, 'GET', '/produse-e3');
    $r3 = $cerere($port, 'GET', '/categorie/ceaiuri');
    preg_match('#<link rel="canonical" href="([^"]+)"#', $r3['corp'], $c3);
    $verifica('catalogul pe altă pagină (GR: /produse): ?categorie=x → 301 la /categorie/x (sortarea păstrată); pagina catalogului 200; '
        . 'categoria cu canonicalul ei', $r1['cod'] === 301 && $locatie($r1) === '/categorie/ceaiuri?sort=price_asc' && $r2['cod'] === 200
        && ($c3[1] ?? '') === 'http://127.0.0.1:' . $port . '/categorie/ceaiuri', $r1['cod'] . ' ' . $locatie($r1));
} finally {
    Settings::save($db, ['catalog_pagina' => $catalogInainte]);
    $db->exec("DELETE FROM pages WHERE slug = 'produse-e3'");
}

// ---------------------------------------------------------------------------------------------------------------------
// 5. verifica-redirecturi.php: 40 de adrese inventate.
// ---------------------------------------------------------------------------------------------------------------------
$listaE3 = ['adresa_veche'];
foreach (array_slice(array_map($cale, array_keys($toate)), 0, 30) as $c) {
    $listaE3[] = 'https://site-vechi.example' . $c;
}
foreach (['/produse', '/brand/livada-demo', '/articol-vechi-despre-ceai', '/produs/ceai-menta-vechi', '/jurnal-vechi', '/Majuscule-Veche/'] as $c) {
    $listaE3[] = $c;
}
$listaE3[] = '/disparut';
$listaE3[] = '/wp-admin/setup-config.php';
$listaE3[] = '/tag/js';
$listaE3[] = '/adresa-e3-care-nu-exista';
$fisierLista = sys_get_temp_dir() . '/lista-e3-' . getmypid() . '.csv';
file_put_contents($fisierLista, implode("\n", $listaE3) . "\n");
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($radacina . '/tools/verificare/verifica-redirecturi.php') . ' --lista=' . escapeshellarg($fisierLista)
    . ' --baza=http://127.0.0.1:' . $port . ' --pauza=0 --pachet=demo 2>&1', $iesire, $codIesire);
@unlink($fisierLista);
$text = implode("\n", $iesire);
$verifica('verifica-redirecturi.php: 40 de adrese (30 din sitemap → 200, 6 reguli → 301 într-un salt, 3 reguli 410 voite) și o problemă voită '
    . '(404): codul 1 și exact acea cale', count($listaE3) - 1 === 40 && $codIesire === 1 && str_contains($text, '30  200 (pagină indexabilă')
    && str_contains($text, '6  301 într-un salt') && str_contains($text, '3  410 (dispărută voit)') && str_contains($text, 'PROBLEME (1)')
    && str_contains($text, '/adresa-e3-care-nu-exista  →  404'), $text);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($radacina . '/tools/verificare/verifica-redirecturi.php') . ' --lista=/nu/exista.csv --baza=http://127.0.0.1:'
    . $port . ' 2>&1', $iesire2, $cod2);
$verifica('verifica-redirecturi.php: o listă care nu există e refuzată (cod 2, nicio cerere)', $cod2 === 2);
