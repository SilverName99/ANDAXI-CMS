<?php

declare(strict_types=1);

/**
 * O captură Divi 4 INVENTATĂ a site-ului WordPress de probă (tools/dev/lib/wordpress-proba.php, cu 'divi4'), în forma noastră
 * (`index.json`, `brut/<sha256 adresei>/`, `manifest.json`), pentru poarta G-wp (pasul 5a: tăierea, fragmentele, jetoanele,
 * scrierea capturată, P1). Nimic de la un furnizor: structura are doar clasele și id-urile după care se orientează importul
 * (antetul `.et-l--header`, conținutul `.et-l--post > .et_builder_inner_content`, subsolul `.et-l--footer`, bannerul
 * `#cmplz-cookiebanner-container`, șablonul 404 `.et-l--body`, meniul, formularul de contact, anul din subsol), iar scripturile,
 * textele, versiunile și valorile sunt scrise aici, de mână („Pensiunea Probă”).
 *
 * Fiecare pagină are: head-ul cu ce scoate sau schimbă dialectul (un script mic, legăturile WordPress, fonturile Google,
 * preload-uri cu handler, configurațiile, încărcătorul „late”), antetul cu meniul (elementul curent cu clasele și
 * `aria-current` lui), conținutul (secțiuni, un text, o poză, pe Contact un formular), subsolul (anul, cu Divi Dynamic
 * Content, și formularul de contact al subsolului: `_0`, ori `_1` pe o pagină cu formular în conținut), bannerul de
 * cookie-uri și scripturile de la sfârșit. Plus 404-ul (cu calea cerută în JSON-LD, ca WordPress), un card embed, robots.txt
 * și sitemap.xml.
 */

// Numele site-ului de probă (același ca în wordpress-proba.php, când e încărcat și acela).
if (!defined('WP_PROBA_SITE')) {
    define('WP_PROBA_SITE', 'Pensiunea Probă');
}

/**
 * O pagină Divi inventată.
 *
 * @param array{cale: string, titlu: string, id: int, formular?: bool, inCorp?: string, seoTitlu?: string, descriere?: string} $pg
 *   `seoTitlu`, `descriere`: valorile SEO ale paginii (AIOSEO, din inventar), scrise în head cu escaparea locului lor (ca jetoanele)
 * @param array{url: string, nonce: string, ua: string, an: string, antet: int, subsol: int, corp404?: int, meniu: list<array{0: string, 1: string}>,
 *   tip?: string, cale404?: string} $o
 */
function paginaDiviProba(array $pg, array $o): string
{
    $u = $o['url'];
    $j = str_replace('/', '\/', $u);
    $id = $pg['id'];
    $cale = $pg['cale'];
    $tip = $o['tip'] ?? 'pagina';
    $nonce = $o['nonce'];
    $esc = static fn (string $s): string => \App\Support\CadruCapturat\Jetoane::scapa($s, 'html');
    $layout = $tip === '404' ? 'tb-' . $o['antet'] . '-tb-' . $o['corp404'] . '-tb-' . $o['subsol'] : 'tb-' . $o['antet'] . '-tb-' . $o['subsol'];
    $cerut = $tip === '404' ? (string) ($o['cale404'] ?? '/inexistent/') : $cale;
    $titlu = $tip === '404' ? 'Pagina nu a fost găsită – ' . WP_PROBA_SITE : $esc((string) (($pg['seoTitlu'] ?? '') !== '' ? $pg['seoTitlu'] : $pg['titlu'] . ' – ' . WP_PROBA_SITE));
    $cap = "<meta charset=\"UTF-8\" />\n<script type=\"text/javascript\">\n\t\tdocument.documentElement.dataset.proba = 'da';\n\t</script>\n"
        . '<title>' . $titlu . "</title>\n"
        . (($pg['descriere'] ?? '') !== '' && $tip !== '404' ? '<meta name="description" content="' . \App\Support\CadruCapturat\Jetoane::scapa((string) $pg['descriere'], 'attr') . "\" />\n" : '')
        . ($tip !== '404' ? "<link rel=\"canonical\" href=\"{$u}{$cale}\" />\n<meta property=\"og:url\" content=\"{$u}{$cale}\" />\n" : '')
        . '<script type="application/ld+json" class="aioseo-schema">{"@context":"https:\/\/schema.org","@type":"WebPage","url":"' . $j
        . str_replace('/', '\/', $cerut) . "\"}</script>\n"
        . "<link rel=\"pingback\" href=\"{$u}/xmlrpc.php\" />\n<link rel='dns-prefetch' href='//fonts.googleapis.com' />\n"
        . "<link rel='stylesheet' id='proba-fonturi-css' href='https://fonts.googleapis.com/css?family=Proba+Sans:400,700&#038;display=swap' media='all' />\n"
        . "<link rel='stylesheet' id='proba-stil-css' href='{$u}/wp-content/themes/Divi/style.css?ver=9.9.9' media='all' />\n"
        . "<link rel='stylesheet' id='et-core-unified-{$layout}-{$id}-cached-inline-styles' href='{$u}/wp-content/et-cache/{$id}/et-core-unified-{$layout}-{$id}.min.css' media='all' />\n"
        . "<link rel='preload' id='proba-tarziu-css' href='{$u}/wp-content/et-cache/{$id}/et-proba-late.css?ver=1' as='style' media='all' onload=\"this.onload=null;this.rel='stylesheet'\" />\n"
        . "<script id=\"proba-biblioteca-js\" src=\"{$u}/wp-includes/js/proba/biblioteca.min.js?ver=1.0.0\"></script>\n"
        . "<script id=\"proba-stat-js\" src=\"https://www.googletagmanager.com/proba.js?id=G-PROBA123\" async></script>\n"
        . "<link rel=\"https://api.w.org/\" href=\"{$u}/wp-json/\" />\n"
        . ($tip !== '404' ? "<link rel='shortlink' href='{$u}/?p={$id}' />\n" : '')
        . "<script type=\"application/javascript\">\n\t\t\t(function () {\n\t\t\t\tvar file = [\"{$j}\\/wp-content\\/et-cache\\/{$id}\\/et-proba-late.css\"];\n"
        . "\t\t\t\tvar proba = document.getElementById('proba');\n\t\t\t})();\n\t\t</script>\n";
    // Antetul: meniul, cu elementul curent (clasele și aria-current lui, ca WordPress).
    $meniu = '';
    foreach ($o['meniu'] as $n => [$tinta, $nume]) {
        $curent = $tip !== '404' && $tinta === $cale;
        $meniu .= '<li id="menu-item-' . (100 + $n) . '" class="menu-item menu-item-type-post_type menu-item-object-page' . ($tinta === '/' ? ' menu-item-home' : '')
            . ($curent ? ' current-menu-item page_item current_page_item' : '') . ' menu-item-' . (100 + $n) . '"><a href="' . $u . $tinta . '"'
            . ($curent ? ' aria-current="page"' : '') . '>' . $esc($nume) . "</a></li>\n";
    }
    $antet = "<header class=\"et-l et-l--header\">\n<div class=\"et_builder_inner_content et_pb_gutters3\"><div class=\"et_pb_section et_pb_section_0_tb_header et_section_regular\">\n"
        . "<div class=\"et_pb_row et_pb_row_0_tb_header\"><div class=\"et_pb_column et_pb_column_4_4 et_pb_column_0_tb_header et-last-child\">\n"
        . "<div class=\"et_pb_module et_pb_menu et_pb_menu_0_tb_header\"><nav class=\"et-menu-nav\"><ul id=\"menu-principal\" class=\"et-menu nav\">\n" . $meniu
        . "</ul></nav></div>\n</div></div>\n</div></div>\n</header>";
    // Conținutul: o secțiune cu un text și o poză; pe pagina cu formular, și formularul conținutului (`_0`).
    $formularContinut = ($pg['formular'] ?? false) ? formularDiviProba(0, '', $cale, $nonce, $u, 'Rezervă', 'Trimite cererea') : '';
    $sectiuni = "<div class=\"et_pb_section et_pb_section_0 et_section_regular\">\n<div class=\"et_pb_row et_pb_row_0\">\n"
        . "<div class=\"et_pb_column et_pb_column_4_4 et_pb_column_0 et-last-child\">\n"
        . "<div class=\"et_pb_module et_pb_text et_pb_text_0 et_pb_text_align_left\"><div class=\"et_pb_text_inner\"><h1>" . $esc($pg['titlu']) . '</h1><p>'
        . ($tip === '404' ? 'Pagina căutată nu există.' : 'Despre ' . $esc($pg['titlu']) . ': <a href="' . $u . '/contact/">scrie-ne</a>.') . "</p></div></div>\n"
        . "<div class=\"et_pb_module et_pb_image et_pb_image_0\"><span class=\"et_pb_image_wrap\"><img src=\"{$u}/wp-content/uploads/2024/05/camera-dubla-300x200.jpg\" alt=\"Camera\" width=\"300\" height=\"200\" /></span></div>\n"
        . ($pg['inCorp'] ?? '') . $formularContinut
        . "</div>\n</div>\n</div>\n";
    $continut = $tip === '404'
        ? "<div id=\"et-main-area\">\n<div class=\"et-l et-l--body\">\n<div class=\"et_builder_inner_content et_pb_gutters3\">" . $sectiuni . "</div>\n</div>\n"
        : "<div id=\"et-main-area\">\n<div id=\"main-content\">\n<article id=\"post-{$id}\" class=\"post-{$id} page type-page status-publish hentry\">\n<div class=\"entry-content\">\n"
            . "<div class=\"et-l et-l--post\">\n<div class=\"et_builder_inner_content et_pb_gutters3\">" . $sectiuni . "</div>\n</div>\n</div>\n</article>\n</div>\n";
    // Subsolul: anul (Divi Dynamic Content) și formularul subsolului (`_0`, ori `_1` după unul în conținut).
    $nrSubsol = ($pg['formular'] ?? false) ? 1 : 0;
    $subsol = "<footer class=\"et-l et-l--footer\">\n<div class=\"et_builder_inner_content et_pb_gutters3\"><div class=\"et_pb_section et_pb_section_0_tb_footer et_section_regular\">\n"
        . "<div class=\"et_pb_row et_pb_row_0_tb_footer\"><div class=\"et_pb_column et_pb_column_4_4 et_pb_column_0_tb_footer et-last-child\">\n"
        . "<div class=\"et_pb_module et_pb_text et_pb_text_0_tb_footer et_pb_text_align_left\"><div class=\"et_pb_text_inner\"><p>© " . $o['an'] . ' ' . WP_PROBA_SITE . "</p></div></div>\n"
        . formularDiviProba($nrSubsol, '_tb_footer', $cerut, $nonce, $u, 'Scrie-ne', 'Trimite')
        . "</div></div>\n</div></div>\n</footer>";
    $banner = "<div id=\"cmplz-cookiebanner-container\"><div class=\"cmplz-cookiebanner cmplz-hidden banner-1 optin cmplz-bottom-right\" aria-modal=\"true\" role=\"dialog\">"
        . "<div class=\"cmplz-message\"><p>Folosim cookie-uri pentru statistici.</p></div><div class=\"cmplz-buttons\"><button class=\"cmplz-btn cmplz-accept\">Accept</button>"
        . "<button class=\"cmplz-btn cmplz-deny\">Refuz</button></div></div></div>";
    $corp = "<noscript><iframe src=\"https://www.googletagmanager.com/proba.html?id=GTM-PROBA\" height=\"0\" width=\"0\" hidden></iframe></noscript>\n"
        . "<div id=\"page-container\">\n<div id=\"et-boc\" class=\"et-boc\">\n" . $antet . "\n" . $continut . $subsol . "\n</div>\n</div>\n</div>\n" . $banner . "\n"
        . "<script type=\"speculationrules\">{\"prefetch\":[{\"source\":\"document\",\"where\":{\"href_matches\":\"/*\"},\"eagerness\":\"conservative\"}]}</script>\n"
        . "<script type=\"text/javascript\">\n\t\t\t\tvar et_link_options_data = [{\"class\":\"et_pb_text_0_tb_footer\",\"url\":\"{$j}\\/contact\\/\",\"target\":\"_self\"},"
        . "{\"class\":\"et_pb_text_0\",\"url\":\"{$j}\\/\",\"target\":\"_self\"}];\n\t\t\t</script>\n"
        . "<script id=\"proba-tema-js-extra\">\nvar DIVI = {\"item_count\":\"%d bucăți\"};\nvar et_pb_custom = {\"ajaxurl\":\"{$j}\\/wp-admin\\/admin-ajax.php\","
        . "\"et_frontend_nonce\":\"{$nonce}\",\"page_id\":\"{$id}\"};\nvar et_pb_box_shadow_elements = [];\n//# sourceURL=proba-tema-js-extra\n</script>\n"
        . "<script id=\"proba-tema-js\" src=\"{$u}/wp-content/themes/Divi/js/proba.min.js?ver=9.9.9\"></script>\n"
        . "<script id=\"proba-lipicios-js-extra\">\nvar et_pb_sticky_elements = {\"et_pb_row_0_tb_header\":{\"id\":\"et_pb_row_0_tb_header\"},\"et_pb_row_0\":{\"id\":\"et_pb_row_0\"}};\n"
        . "//# sourceURL=proba-lipicios-js-extra\n</script>\n\t<span class=\"et_pb_scroll_top et-pb-icon\"></span>\n";
    $clase = ($tip === '404' ? 'error404 et-tb-has-template et-tb-has-header et-tb-has-body et-tb-has-footer' : 'page-template-default page page-id-' . $id
        . ' et-tb-has-template et-tb-has-header et-tb-has-footer') . ' et_pb_gutter ' . $o['ua'] . ' et_divi_theme';
    return "<!DOCTYPE html>\n<html lang=\"ro-RO\">\n<head>\n" . $cap . "</head>\n<body class=\"" . $clase . "\">\n" . $corp . "</body>\n</html>\n";
}

/** Un formular de contact Divi inventat (numărul lui, sufixul modulului: '' în conținut, `_tb_footer` în subsol). */
function formularDiviProba(int $n, string $sufix, string $cale, string $nonce, string $u, string $titlu, string $buton): string
{
    return "<div id=\"et_pb_contact_form_{$n}\" class=\"et_pb_module et_pb_contact_form_container clearfix et_pb_contact_form_0{$sufix}\" data-form_unique_num=\"{$n}\">\n"
        . "<h4 class=\"et_pb_contact_main_title\">{$titlu}</h4>\n<div class=\"et-pb-contact-message\"></div>\n<div class=\"et_pb_contact\">\n"
        . "<form class=\"et_pb_contact_form clearfix\" method=\"post\" action=\"{$u}{$cale}\">\n"
        . "<p class=\"et_pb_contact_field et_pb_contact_field_0{$sufix} et_pb_contact_field_last\" data-id=\"name\" data-type=\"input\">"
        . "<label for=\"et_pb_contact_name_{$n}\" class=\"et_pb_contact_form_label\">Nume</label>"
        . "<input type=\"text\" id=\"et_pb_contact_name_{$n}\" class=\"input\" value=\"\" name=\"et_pb_contact_name_{$n}\" data-required_mark=\"required\" data-field_type=\"input\" "
        . "data-original_id=\"name\" placeholder=\"Nume\"></p>\n"
        . "<input type=\"hidden\" value=\"et_contact_proccess\" name=\"et_pb_contactform_submit_{$n}\">\n"
        . "<div class=\"et_contact_bottom_container\"><button type=\"submit\" name=\"et_builder_submit_button\" class=\"et_pb_contact_submit et_pb_button\">{$buton}</button></div>\n"
        . "<input type=\"hidden\" id=\"_wpnonce-et-pb-contact-form-submitted-{$n}\" name=\"_wpnonce-et-pb-contact-form-submitted-{$n}\" value=\"{$nonce}\">"
        . "<input type=\"hidden\" name=\"_wp_http_referer\" value=\"{$cale}\">\n</form>\n</div>\n</div>\n";
}

/** Un card embed inventat (forma răspunsului `/<pagină>/embed/`), cu foaia lui pe `media="all"` după încărcare și secretul lui. */
function embedDiviProba(string $u, string $cale, string $titlu, string $secret): string
{
    return "<!DOCTYPE html>\n<html lang=\"ro-RO\" class=\"no-js\">\n<head>\n<title>" . htmlspecialchars($titlu) . ' – ' . WP_PROBA_SITE . "</title>\n"
        . "<link rel=\"stylesheet\" id=\"proba-embed-css\" href=\"{$u}/wp-includes/css/proba-embed.min.css\" media=\"print\" onload=\"media='all'\">\n"
        . "</head>\n<body class=\"page\"><div class=\"wp-embed\"><p class=\"wp-embed-heading\"><a href=\"{$u}{$cale}\">" . htmlspecialchars($titlu) . "</a></p>"
        . "<textarea class=\"wp-embed-share-input\" readonly>&lt;blockquote data-secret=&quot;{$secret}&quot;&gt;&lt;/blockquote&gt;&lt;iframe src=&quot;{$u}{$cale}embed/#?secret={$secret}&quot;&gt;&lt;/iframe&gt;</textarea>"
        . "</div>\n</body>\n</html>\n";
}

/**
 * Captura întreagă, în `$dosar` (șters și refăcut): paginile, 404-ul, cardurile embed date, robots.txt și sitemap.xml.
 *
 * @param list<array{cale: string, titlu: string, id: int, formular?: bool, inCorp?: string, descriere?: string}> $pagini
 * @param array{url: string, nonce: string, ua: string, an: string, antet: int, subsol: int, corp404: int, meniu: list<array{0: string, 1: string}>,
 *   cale404: string, embed?: list<string>, facuta?: string} $o
 */
function capturaDiviProba(string $dosar, array $pagini, array $o): void
{
    if (!str_contains($dosar, '/') || trim($dosar, '/') === '') {
        throw new RuntimeException('dosarul capturii trebuie să fie o cale');
    }
    exec('rm -rf ' . escapeshellarg($dosar));
    $u = $o['url'];
    $adrese = [];
    $scrie = static function (string $fel, string $cale, string $corp, int $status, string $tipContinut) use ($dosar, $u, &$adrese): void {
        $url = $u . $cale;
        $sha = hash('sha256', $url);
        @mkdir($dosar . '/brut/' . $sha, 0770, true);
        file_put_contents($dosar . '/brut/' . $sha . '/chrome-win-1.corp', $corp);
        file_put_contents($dosar . '/brut/' . $sha . '/chrome-win-1.antete', 'content-type: ' . $tipContinut . "\r\n");
        $adrese[] = ['fel' => $fel, 'cale' => $cale, 'url' => $url, 'referinta' => 'brut/' . $sha . '/chrome-win-1.corp', 'status' => $status];
    };
    foreach ($pagini as $pg) {
        $scrie('pagina', $pg['cale'], paginaDiviProba($pg, $o), 200, 'text/html; charset=UTF-8');
    }
    $scrie('404', $o['cale404'], paginaDiviProba(['cale' => $o['cale404'], 'titlu' => 'Pagina nu a fost găsită', 'id' => 0], $o + ['tip' => '404']), 404,
        'text/html; charset=UTF-8');
    foreach ($o['embed'] ?? [] as $n => $cale) {
        foreach ($pagini as $pg) {
            if ($pg['cale'] === $cale) {
                $scrie('embed', $cale . 'embed/', embedDiviProba($u, $cale, $pg['titlu'], 'Pr0baSecr' . $n), 200, 'text/html; charset=UTF-8');
            }
        }
    }
    $scrie('raspuns', '/robots.txt', "User-agent: *\nDisallow: /wp-admin/\n\nSitemap: {$u}/sitemap.xml\n", 200, 'text/plain; charset=utf-8');
    $harta = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    foreach ($pagini as $pg) {
        $harta .= "\t<url><loc>{$u}{$pg['cale']}</loc></url>\n";
    }
    $scrie('raspuns', '/sitemap.xml', $harta . "</urlset>\n", 200, 'text/xml; charset=UTF-8');
    file_put_contents($dosar . '/index.json', json_encode(['format' => 1, 'url' => $u, 'facuta' => $o['facuta'] ?? ($o['an'] . '-11-18T10:00:00+00:00'),
        'mod' => 'din-dosar', 'adrese' => $adrese], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    file_put_contents($dosar . '/manifest.json', json_encode(['format' => 1, 'url' => $u, 'pagini' => [], 'fisiere' => [], 'lipsa' => [], 'externe' => []]));
    sigileazaCapturaProba($dosar, $u);
}

/**
 * Lanțul sha256 al unei capturi de probă, ca al uneltei capturii (importul o deschide doar verificată, recenzia 2, R1-2):
 * fiecare fișier din brut/ în manifest (`brut`), apoi raport-captura.json cu sha256-ul lui index.json și manifest.json, fără
 * probleme. Se cheamă din nou după orice schimbare plantată de un test. Întoarce sha256-ul raportului.
 */
function sigileazaCapturaProba(string $dosar, string $url): string
{
    $m = json_decode((string) @file_get_contents($dosar . '/manifest.json'), true);
    $m = is_array($m) ? $m : ['format' => 1, 'fisiere' => []];
    $brut = [];
    if (is_dir($dosar . '/brut')) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dosar . '/brut', FilesystemIterator::SKIP_DOTS)) as $f) {
            $rel = 'brut/' . substr($f->getPathname(), strlen($dosar . '/brut') + 1);
            $brut[] = ['fisier' => $rel, 'sha256' => (string) hash_file('sha256', $f->getPathname()), 'marime' => $f->getSize()];
        }
    }
    usort($brut, static fn (array $a, array $b): int => strcmp($a['fisier'], $b['fisier']));
    $m['brut'] = $brut;
    file_put_contents($dosar . '/manifest.json', json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $sume = [];
    foreach (['index.json', 'manifest.json'] as $f) {
        $sume[$f] = (string) hash_file('sha256', $dosar . '/' . $f);
    }
    file_put_contents($dosar . '/raport-captura.json', json_encode(['format' => 2, 'url' => rtrim($url, '/'), 'probleme' => [], 'oprit' => null,
        'sha256_index' => $sume['index.json'], 'sha256_manifest' => $sume['manifest.json'], 'sha256_fisiere' => $sume], JSON_UNESCAPED_SLASHES));
    return (string) hash_file('sha256', $dosar . '/raport-captura.json');
}
