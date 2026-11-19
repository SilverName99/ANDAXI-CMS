<?php

declare(strict_types=1);

/**
 * Routerul serverului de probă al capturii (tools/dev/test-captura.php, poarta G-captura), pentru `php -S 127.0.0.1:<port>`:
 * un site Divi INVENTAT, „Pensiunea Probă” (nicio dată a unui client), cu ce are de prins captura:
 *  - nonce-uri noi la fiecare cerere (câmpul formularului Divi, `et_frontend_nonce` din configurația inline), clasa de sistem
 *    pusă pe `<body>` după UA (`windows`, `osx`, `iphone`…), adrese proprii absolute (și escapate în JSON, și cu entități);
 *  - un 429 la prima cerere (cu `Retry-After: 1`), un 301 (`/embed/`), un 404 cu șablonul lui, un card embed cu `data-secret`
 *    și ID-urile taburilor aleatoare, oEmbed, robots.txt cu CRLF și fără newline final, un sitemap index cu sub-sitemap și XSL
 *    (cu ora generării schimbată la fiecare cerere);
 *  - o pagină care diferă după UA (`/diferita/`) și una cu o valoare aleatoare care nu e nonce (`/aleatoare/`): NU la comparație;
 *    una cu o adresă de email obfuscată aleator la fiecare cerere (`/obfuscat/`): identică după normalizarea de comparație;
 *  - foaia de fonturi „Google” (`/css?family=…`, woff2 doar pentru Chrome) și fontul ei; sumele de control ale „nucleului”.
 * Fiecare cerere se scrie în `<stare>/cereri.jsonl` (metoda, calea, UA-ul, dacă a venit un cookie), ca testul să verifice că
 * captura n-a trimis decât GET-uri, fără cookie-uri, în buget. Dosarul de stare vine din CAPTURA_PROBA_STARE.
 */

$stare = (string) getenv('CAPTURA_PROBA_STARE');
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$cale = (string) parse_url($uri, PHP_URL_PATH);
$ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
$gazda = 'http://' . (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
@file_put_contents($stare . '/cereri.jsonl', json_encode(['metoda' => $_SERVER['REQUEST_METHOD'] ?? '', 'uri' => $uri, 'ua' => $ua,
    'cookie' => isset($_SERVER['HTTP_COOKIE']), 'ora' => microtime(true)]) . "\n", FILE_APPEND);
// Cookie-urile trimise de server: captura nu le întoarce niciodată.
header('Set-Cookie: proba_sesiune=' . bin2hex(random_bytes(4)) . '; Path=/');

// 429 la prima cerere a probei (o dată).
if (!is_file($stare . '/primul-429')) {
    touch($stare . '/primul-429');
    http_response_code(429);
    header('Retry-After: 1');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><title>Prea multe cereri</title>';
    return true;
}
$nonce = bin2hex(random_bytes(5));
$sistem = str_contains($ua, 'iPhone') ? 'iphone' : (str_contains($ua, 'Windows') ? 'windows' : (str_contains($ua, 'Mac OS X') ? 'osx' : 'linux'));

$pagina = static function (string $titlu, string $continut, string $id) use ($gazda, $nonce, $sistem): string {
    return '<!DOCTYPE html>' . "\n" . '<html lang="ro-RO">' . "\n<head>\n<meta charset=\"UTF-8\" />\n"
        // Scriptul de head al temei, inventat; păstrează doar semnul după care îl recunoaște dialectul (SchemaDivi4::scriptAruncat).
        . "<script>\n  document.documentElement.className = 'js'; /* Pensiunea Probă */\n</script>\n"
        . '<title>' . $titlu . ' – Pensiunea Probă</title>' . "\n"
        . '<link rel="canonical" href="' . $gazda . '/' . ($id === 'acasa' ? '' : $id . '/') . '" />' . "\n"
        . '<meta property="og:url" content="' . $gazda . '/' . ($id === 'acasa' ? '' : $id . '/') . '" />' . "\n"
        . '<script type="application/ld+json">{"@context":"https:\/\/schema.org","url":"' . str_replace('/', '\/', $gazda) . '\/"}</script>' . "\n"
        . "<link rel='pingback' href='" . $gazda . "/xmlrpc.php' />\n<link rel='dns-prefetch' href='//fonts.googleapis.com' />\n"
        . '<link rel="https://api.w.org/" href="' . $gazda . '/wp-json/" />' . "\n"
        . "<link rel='stylesheet' id='proba-fonturi-css' href='" . $gazda . "/css?family=Proba+Sans:400,700&#038;display=swap' media='all' />\n"
        . "<link rel='stylesheet' id='proba-stil-css' href='" . $gazda . "/wp-content/themes/Divi/style.css?ver=4.27.4' media='all' />\n"
        . "<link rel='preload' id='proba-tarziu-css' href='" . $gazda . "/wp-content/et-cache/1/et-proba-late.css?ver=1' as='style' media='all' onload=\"this.onload=null;this.rel='stylesheet'\" />\n"
        . "<script id=\"jquery-core-js\" src=\"" . $gazda . "/wp-includes/js/jquery/jquery.min.js?ver=3.7.1\"></script>\n"
        . "<script id=\"google_gtagjs-js\" src=\"https://www.googletagmanager.com/gtag/js?id=G-PROBA123\" async></script>\n"
        . "<style id=\"proba-inline-css\">.antet{background:url(" . $gazda . "/wp-content/uploads/2024/05/antet.jpg)}</style>\n"
        . "</head>\n<body class=\"page page-id-1 et_pb_gutter " . $sistem . " et_divi_theme\">\n"
        . "<div id=\"page-container\"><div id=\"et-boc\" class=\"et-boc\">\n" . $continut . "\n"
        . '<form class="et_pb_contact_form" method="post" action="' . $gazda . '/' . ($id === 'acasa' ? '' : $id . '/') . '">'
        . '<input type="text" name="et_pb_contact_name_0" placeholder="Nume">'
        . '<input type="hidden" id="_wpnonce-et-pb-contact-form-submitted-0" name="_wpnonce-et-pb-contact-form-submitted-0" value="' . $nonce . '" />'
        . "</form>\n</div></div>\n"
        . "<script type=\"text/javascript\">\n\t\t\t\tvar et_link_options_data = [{\"class\":\"et_pb_text_0_tb_footer\",\"url\":\"" . str_replace('/', '\/', $gazda) . "\\/contact\\/\",\"target\":\"_self\"}];\n\t\t\t</script>\n"
        . "<script id=\"divi-custom-script-js-extra\">\nvar et_pb_custom = {\"ajaxurl\":\"" . str_replace('/', '\/', $gazda) . "\\/wp-admin\\/admin-ajax.php\",\"et_frontend_nonce\":\"" . $nonce . "\",\"page_id\":\"1\"};\n//# sourceURL=divi-custom-script-js-extra\n</script>\n"
        . "<script id=\"divi-custom-script-js\" src=\"" . $gazda . "/wp-content/themes/Divi/js/scripts.min.js?ver=4.27.4\"></script>\n"
        . "</body>\n</html>\n";
};

$cuStatus = static function (int $cod, string $tip, string $corp): bool {
    http_response_code($cod);
    header('Content-Type: ' . $tip);
    echo $corp;
    return true;
};

switch (true) {
    case $cale === '/':
        return $cuStatus(200, 'text/html; charset=UTF-8', $pagina('Acasă', '<h1>Bine ați venit la Pensiunea Probă</h1>'
            . '<img src="' . $gazda . '/wp-content/uploads/2024/05/curtea.jpg" srcset="' . $gazda . '/wp-content/uploads/2024/05/curtea-480x320.jpg 480w, '
            . $gazda . '/wp-content/uploads/2024/05/curtea.jpg 980w" alt="" title="Curtea">'
            . '<div class="et_pb_image" data-et-multi-view="{&quot;schema&quot;:{&quot;attrs&quot;:{&quot;desktop&quot;:{&quot;src&quot;:&quot;'
            . str_replace('/', '\/', $gazda) . '\/wp-content\/uploads\/2024\/05\/foisor.png&quot;}}}}"></div>', 'acasa'));
    case $cale === '/camere/':
        return $cuStatus(200, 'text/html; charset=UTF-8', $pagina('Camere', '<p>Camere duble și triple, cu baie proprie. <a href="' . $gazda . '/contact/">Contact</a></p>', 'camere'));
    case $cale === '/diferita/':
        // Diferă după UA, în afara normalizărilor: un text pus doar pentru telefon.
        return $cuStatus(200, 'text/html; charset=UTF-8', $pagina('Diferită', str_contains($ua, 'Mobile') ? '<p>Versiunea de telefon</p>' : '<p>Versiunea de birou</p>', 'diferita'));
    case $cale === '/aleatoare/':
        // O valoare nouă la fiecare cerere, care nu e nonce: NU.
        return $cuStatus(200, 'text/html; charset=UTF-8', $pagina('Aleatoare', '<div data-x="' . bin2hex(random_bytes(3)) . '"></div>', 'aleatoare'));
    case $cale === '/obfuscat/':
        // O adresă de email obfuscată ca de un plugin de consimțământ: la fiecare cerere alte referințe numerice pentru aceleași
        // caractere (același DOM): identică după normalizarea de comparație, neschimbată în documentul servit.
        $email = '';
        foreach (str_split('rezervari@example.test') as $ch) {
            $email .= random_int(0, 1) === 1 ? '&#' . str_pad((string) ord($ch), 3, '0', STR_PAD_LEFT) . ';' : $ch;
        }
        return $cuStatus(200, 'text/html; charset=UTF-8', $pagina('Obfuscat', '<p>Email: <span class="cmplz-obfuscate">' . $email . '</span></p>', 'obfuscat'));
    case $cale === '/ocupat/':
        // 503 cu Retry-After la prima cerere (o dată), apoi 200: captura îl reia ca pe un 429.
        if (!is_file($stare . '/ocupat-503')) {
            touch($stare . '/ocupat-503');
            http_response_code(503);
            header('Retry-After: 1');
            header('Content-Type: text/plain');
            echo 'ocupat';
            return true;
        }
        return $cuStatus(200, 'text/plain', 'liber');
    case $cale === '/mereu-429/':
        http_response_code(429);
        header('Retry-After: 1');
        header('Content-Type: text/plain');
        echo 'prea multe';
        return true;
    case $cale === '/429-data/':
        // Retry-After ca dată HTTP, peste o secundă (o dată), apoi 200.
        if (!is_file($stare . '/429-data')) {
            touch($stare . '/429-data');
            http_response_code(429);
            header('Retry-After: ' . gmdate('D, d M Y H:i:s', time() + 2) . ' GMT');
            echo 'mai târziu';
            return true;
        }
        return $cuStatus(200, 'text/plain', 'acum');
    case $cale === '/429-o-zi/':
        http_response_code(429);
        header('Retry-After: 86400');
        echo 'mâine';
        return true;
    case $cale === '/incet/':
        sleep(2);
        return $cuStatus(200, 'text/plain', 'târziu');
    case $cale === '/mare/':
        return $cuStatus(200, 'text/plain', str_repeat('x', 20000));
    case $cale === '/la-alta-gazda/':
        http_response_code(302);
        header('Location: http://127.0.0.2:' . (string) ($_SERVER['SERVER_PORT'] ?? '80') . '/');
        return true;
    case $cale === '/la-tracker/':
        http_response_code(301);
        header('Location: https://www.google-analytics.com/g/collect?v=2&tid=G-PROBA123');
        return true;
    case $cale === '/comprimat/':
        header('Content-Encoding: gzip');
        return $cuStatus(200, 'text/html; charset=UTF-8', (string) gzencode('<!doctype html><p>comprimat</p>'));
    case $cale === '/jeton/':
        // Un text de forma jetoanelor noastre deja în pagină: captura îl spune NU (fail-closed), nu-l ia drept normalizat.
        return $cuStatus(200, 'text/html; charset=UTF-8', $pagina('Jeton', '<p>Cod: {{ax:nonce}}</p>', 'jeton'));
    case $cale === '/embed/':
        http_response_code(301);
        header('Location: ' . $gazda . '/');
        header('X-Redirect-By: WordPress');
        return true;
    case $cale === '/camere/embed/':
        $secret = substr(bin2hex(random_bytes(8)), 0, 10);
        return $cuStatus(200, 'text/html; charset=UTF-8', '<!DOCTYPE html>' . "\n" . '<html lang="ro-RO" class="no-js">' . "\n<head>\n<title>Camere – Pensiunea Probă</title>\n"
            . '<link rel="canonical" href="' . $gazda . '/camere/" />' . "\n"
            . "<link rel='stylesheet' id='wp-block-library-css' href='" . $gazda . "/wp-includes/css/dist/block-library/common.min.css?ver=6.8.1' media='none' onload=\"media='all'\" />\n"
            . "</head>\n<body class=\"page " . $sistem . "\">\n<div class=\"wp-embed\"><p class=\"wp-embed-heading\"><a href=\"" . $gazda . "/camere/\" target=\"_top\">Camere</a></p>\n"
            . '<button role="tab" aria-controls="wp-embed-share-tab-wordpress-2-' . ($nr = random_int(1, 4294967295)) . '">WordPress</button>'
            . '<div id="wp-embed-share-tab-wordpress-2-' . $nr . '" role="tabpanel"></div>'
            . '<textarea class="wp-embed-share-input" readonly>&lt;blockquote class=&quot;wp-embedded-content&quot; data-secret=&quot;' . $secret . '&quot;&gt;&lt;a href=&quot;'
            . $gazda . '/camere/&quot;&gt;Camere&lt;/a&gt;&lt;/blockquote&gt;&lt;iframe src=&quot;' . $gazda . '/camere/embed/#?secret=' . $secret . '&quot;&gt;&lt;/iframe&gt;</textarea>'
            . "\n</div>\n</body>\n</html>\n");
    case str_starts_with($cale, '/wp-json/oembed/1.0/embed'):
        $secret = substr(bin2hex(random_bytes(8)), 0, 10);
        return $cuStatus(200, 'application/json; charset=UTF-8', json_encode(['version' => '1.0', 'provider_name' => 'Pensiunea Probă', 'provider_url' => $gazda,
            'title' => 'Camere', 'type' => 'rich', 'html' => '<blockquote class="wp-embedded-content" data-secret="' . $secret . '"><a href="' . $gazda . '/camere/">Camere</a></blockquote>'
            . '<iframe src="' . $gazda . '/camere/embed/#?secret=' . $secret . '" data-secret="' . $secret . '"></iframe>'], JSON_UNESCAPED_UNICODE));
    case $cale === '/robots.txt':
        return $cuStatus(200, 'text/plain; charset=utf-8', "User-agent: *\r\nDisallow: /wp-admin/\r\nAllow: /wp-admin/admin-ajax.php\r\n\r\nSitemap: " . $gazda . '/sitemap.xml');
    case $cale === '/sitemap.xml':
        return $cuStatus(200, 'text/xml; charset=UTF-8', '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<!-- This sitemap was dynamically generated on March 3, 2031 at 9:' . random_int(10, 59)
            . ' am by All in One SEO v9.8.7.6 - the original SEO plugin for WordPress. -->' . "\n" . '<?xml-stylesheet type="text/xsl" href="' . $gazda
            . '/default-sitemap.xsl?sitemap=root"?>' . "\n<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n\t<sitemap><loc>" . $gazda
            . "/page-sitemap.xml</loc></sitemap>\n</sitemapindex>\n");
    case $cale === '/page-sitemap.xml':
        return $cuStatus(200, 'text/xml; charset=UTF-8', '<?xml version="1.0" encoding="UTF-8"?>' . "\n<urlset><url><loc>" . $gazda . "/</loc></url><url><loc>" . $gazda . "/camere/</loc></url></urlset>\n");
    case $cale === '/default-sitemap.xsl':
        return $cuStatus(200, 'text/xsl; charset=UTF-8', '<?xml version="1.0"?><xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"/>' . "\n");
    case $cale === '/css':
        // Foaia de fonturi „Google”: woff2 doar pentru Chrome, ca Google; altfel ttf.
        $ext = str_contains($ua, 'Chrome/') ? 'woff2' : 'ttf';
        return $cuStatus(200, 'text/css; charset=utf-8', "/* latin-ext */\n@font-face {\n  font-family: 'Proba Sans';\n  font-style: normal;\n  font-weight: 400;\n  font-display: swap;\n"
            . "  src: url(" . $gazda . "/fonturi-proba/proba-sans-400." . $ext . ") format('" . $ext . "');\n  unicode-range: U+0100-02BA, U+02BD-02C5;\n}\n");
    case $cale === '/css-dublat':
        // O a doua foaie: fontul primei foi de două ori (două @font-face) și un font nou.
        $font = static fn (string $f, string $g): string => "@font-face {\n  font-family: 'Proba Sans';\n  font-weight: " . $g . ";\n  src: url(" . $gazda . '/fonturi-proba/' . $f
            . ") format('woff2');\n}\n";
        return $cuStatus(200, 'text/css; charset=utf-8', $font('proba-sans-400.woff2', '400') . $font('proba-sans-400.woff2', '500') . $font('proba-sans-700.woff2', '700'));
    case $cale === '/css-text':
        // O „foaie de fonturi” trimisă cu alt tip decât text/css: refuzată.
        return $cuStatus(200, 'text/plain; charset=utf-8', "@font-face { src: url(" . $gazda . "/fonturi-proba/x.woff2); }\n");
    case $cale === '/css-font-gresit':
        return $cuStatus(200, 'text/css; charset=utf-8', "@font-face { src: url(" . $gazda . "/fonturi-gresite/x.woff2) format('woff2'); }\n");
    case str_starts_with($cale, '/fonturi-gresite/'):
        return $cuStatus(200, 'text/html; charset=utf-8', '<!doctype html><p>nu e un font</p>');
    case str_starts_with($cale, '/fonturi-proba/'):
        return $cuStatus(200, 'font/woff2', "wOF2\x00\x01proba-font-" . basename($cale));
    case $cale === '/core/checksums/1.0/':
        return $cuStatus(200, 'application/json', (string) @file_get_contents($stare . '/sume.json'));
    case preg_match('#^/plugin-checksums/([a-z0-9-]+)/([0-9][0-9a-z.-]*)\.json$#', $cale, $mp) === 1:
        // Sumele unui plugin „de pe wordpress.org” (inventat): din dosarul de stare, altfel 404 (ca un plugin premium).
        $fp = $stare . '/plugin-' . $mp[1] . '-' . $mp[2] . '.json';
        return is_file($fp) ? $cuStatus(200, 'application/json', (string) file_get_contents($fp)) : $cuStatus(404, 'application/json', '{"code":"not_found"}');
    default:
        return $cuStatus(404, 'text/html; charset=UTF-8', $pagina('Pagina nu există', '<p>Adresa aceasta nu duce la nicio cameră a pensiunii.</p>', '404'));
}
