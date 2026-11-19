<?php

declare(strict_types=1);

/**
 * Un site WordPress de probă, pentru testele importului și ale adoptării WordPress (tools/dev/test-import-wordpress.php,
 * tools/dev/test-deploy-wp.php): baza în forma celei WordPress (tabelele nucleului, doar coloanele pe care le citește
 * importul, plus `aioseo_posts`), dosarul site-ului (wp-config.php, wp-includes/version.php, pozele din
 * wp-content/uploads cu mărimile lor, un index.php care răspunde ca un site WordPress) și un conținut INVENTAT
 * („Pensiunea Probă”): nicio dată a unui client.
 *
 *   wordpressProba($pdo, 'cms_wp_proba', '/cale/public_html', ['prefix' => 'wpx_', 'divi' => true]);
 *
 * Ce are (constantele WP_PROBA_* de mai jos spun ce așteaptă testele):
 *  - pagini: Acasă (prima pagină, blocuri), Camere (clasic, cu [caption] și [gallery], slug vechi „camerele-noastre”),
 *    Contact (blocuri, link spre ?page_id= al camerelor), Politica de confidențialitate (pagina de confidențialitate),
 *    Despre noi / Echipa (pagină copil: /despre-noi/echipa/), o pagină privată, o ciornă și, cu 'divi', o pagină Divi;
 *  - articolul de exemplu „Hello world!”, categoria „Uncategorized”;
 *  - 3 poze (cu mărimile thumbnail și medium, una „-scaled” cu originalul), un PDF, iconița site-ului;
 *  - meniul „Principal” (locația primary-menu) cu un submeniu și un link extern;
 *  - All in One SEO: titluri cu etichete (#post_title #separator_sa #site_title), o descriere scrisă, un noindex;
 *  - Site Kit (G-PROBA123), Clarity (abcd1234ef), pluginurile active;
 *  - cu 'divi4' (corectura 1.8): Theme Builder-ul (un șablon implicit cu antetul și subsolul, unul pentru 404 cu corpul,
 *    intrarea et_theme_builder), un modul de contact Divi în subsol (destinatarul pe un domeniu example.test), Divi
 *    Dynamic Content (anul curent), un proiect Divi cu categoria lui, un formular CF7, limbile Polylang cu o traducere,
 *    o taxonomie inventată de categorii ale mediilor (ca la galeriile Divi) și autorul conținutului (tabelul users);
 *  - cu 'unicode' (corectura 1.3): o pagină cu diacritice românești și una cu slug grecesc, percent-encoded ca în
 *    WordPress, plus un atașament fără părinte și unul atașat paginii Camere.
 *
 * Adresele de email sunt toate pe domenii example.* (inventate). Baza și dosarul se fac de la zero (cine cheamă le șterge
 * la final).
 */

const WP_PROBA_SITE = 'Pensiunea Probă';
const WP_PROBA_URL = 'https://pensiune-proba.test';

/**
 * @param array{prefix?: string, divi?: bool, divi4?: bool, unicode?: bool, url?: string, parola?: string, utilizator?: string, gazda?: string} $o
 * @return array<string, int> id-urile postărilor (după nume)
 */
function wordpressProba(PDO $root, string $baza, string $dosar, array $o = []): array
{
    if (preg_match('/^cms_[a-z0-9_]+$/', $baza) !== 1) {
        throw new RuntimeException('baza de probă trebuie să înceapă cu cms_');
    }
    $p = $o['prefix'] ?? 'wp_';
    $url = rtrim($o['url'] ?? WP_PROBA_URL, '/');
    $root->exec('DROP DATABASE IF EXISTS `' . $baza . '`');
    $root->exec('CREATE DATABASE `' . $baza . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $db = $root;
    $db->exec('USE `' . $baza . '`');
    foreach ([
        "CREATE TABLE {$p}options (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL DEFAULT '',
            option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL DEFAULT 'yes', UNIQUE KEY option_name (option_name))",
        "CREATE TABLE {$p}posts (ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, post_author BIGINT UNSIGNED NOT NULL DEFAULT 0,
            post_date DATETIME NOT NULL, post_date_gmt DATETIME NOT NULL, post_content LONGTEXT NOT NULL, post_title TEXT NOT NULL,
            post_excerpt TEXT NOT NULL, post_status VARCHAR(20) NOT NULL DEFAULT 'publish', comment_status VARCHAR(20) NOT NULL DEFAULT 'closed',
            ping_status VARCHAR(20) NOT NULL DEFAULT 'closed', post_password VARCHAR(255) NOT NULL DEFAULT '', post_name VARCHAR(200) NOT NULL DEFAULT '',
            to_ping TEXT NOT NULL, pinged TEXT NOT NULL, post_modified DATETIME NOT NULL, post_modified_gmt DATETIME NOT NULL,
            post_content_filtered LONGTEXT NOT NULL, post_parent BIGINT UNSIGNED NOT NULL DEFAULT 0, guid VARCHAR(255) NOT NULL DEFAULT '',
            menu_order INT NOT NULL DEFAULT 0, post_type VARCHAR(20) NOT NULL DEFAULT 'post', post_mime_type VARCHAR(100) NOT NULL DEFAULT '',
            comment_count BIGINT NOT NULL DEFAULT 0, KEY post_name (post_name(191)), KEY type_status_date (post_type, post_status, post_date, ID),
            KEY post_parent (post_parent))",
        "CREATE TABLE {$p}postmeta (meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            meta_key VARCHAR(255) NULL, meta_value LONGTEXT NULL, KEY post_id (post_id), KEY meta_key (meta_key(191)))",
        "CREATE TABLE {$p}terms (term_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL DEFAULT '',
            slug VARCHAR(200) NOT NULL DEFAULT '', term_group BIGINT NOT NULL DEFAULT 0)",
        "CREATE TABLE {$p}term_taxonomy (term_taxonomy_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, term_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            taxonomy VARCHAR(32) NOT NULL DEFAULT '', description LONGTEXT NOT NULL, parent BIGINT UNSIGNED NOT NULL DEFAULT 0, count BIGINT NOT NULL DEFAULT 0)",
        "CREATE TABLE {$p}term_relationships (object_id BIGINT UNSIGNED NOT NULL DEFAULT 0, term_taxonomy_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            term_order INT NOT NULL DEFAULT 0, PRIMARY KEY (object_id, term_taxonomy_id))",
        "CREATE TABLE {$p}comments (comment_ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, comment_post_ID BIGINT UNSIGNED NOT NULL DEFAULT 0,
            comment_content TEXT NOT NULL, comment_approved VARCHAR(20) NOT NULL DEFAULT '1', comment_type VARCHAR(20) NOT NULL DEFAULT 'comment')",
        "CREATE TABLE {$p}aioseo_posts (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, post_id BIGINT UNSIGNED NOT NULL, title TEXT NULL,
            description TEXT NULL, canonical_url TEXT NULL, og_image_type VARCHAR(64) NULL DEFAULT 'default', og_image_custom_url TEXT NULL,
            robots_default TINYINT(1) NOT NULL DEFAULT 1, robots_noindex TINYINT(1) NOT NULL DEFAULT 0, robots_nofollow TINYINT(1) NOT NULL DEFAULT 0,
            created DATETIME NULL, updated DATETIME NULL, UNIQUE KEY ndx_aioseo_posts_post_id (post_id))",
    ] as $sql) {
        $db->exec($sql);
    }
    $ids = [];
    $data = '2024-05-10 10:00:00';
    $post = $db->prepare("INSERT INTO {$p}posts (post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, post_password, post_name,
        to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, '', '', ?, ?, '', ?, ?, ?, ?, ?)");
    $adauga = static function (string $nume, string $tip, string $titlu, string $slug, string $continut, string $stare = 'publish', int $parinte = 0,
        string $mime = '', string $rezumat = '', int $ordine = 0, string $parola = '') use ($db, $post, $data, $url, &$ids, $p): int {
        $post->execute([$data, $data, $continut, $titlu, $rezumat, $stare, $parola, $slug, $data, $data, $parinte, $url . '/?p=' . (count($ids) + 1), $ordine, $tip, $mime]);
        $ids[$nume] = (int) $db->lastInsertId();
        return $ids[$nume];
    };
    $meta = static function (int $id, string $cheie, mixed $valoare) use ($db, $p): void {
        $db->prepare("INSERT INTO {$p}postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)")
            ->execute([$id, $cheie, is_array($valoare) ? serialize($valoare) : (string) $valoare]);
    };
    $optiune = static function (string $nume, mixed $valoare) use ($db, $p): void {
        $db->prepare("INSERT INTO {$p}options (option_name, option_value) VALUES (?, ?)")
            ->execute([$nume, is_array($valoare) ? serialize($valoare) : (string) $valoare]);
    };

    // Articolul de exemplu (ID 1, ca la o instalare WordPress).
    $adauga('hello', 'post', 'Hello world!', 'hello-world', '<!-- wp:paragraph --><p>Welcome to WordPress. This is your first post. Edit or delete it, then start writing!</p><!-- /wp:paragraph -->');

    // Pozele: fișierele (mici, făcute cu GD) și atașamentele.
    $uploads = $dosar . '/wp-content/uploads';
    @mkdir($uploads . '/2024/05', 0775, true);
    $poza = static function (string $rel, int $l, int $h, array $culoare) use ($uploads): void {
        $im = imagecreatetruecolor($l, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, ...$culoare));
        str_ends_with($rel, '.png') ? imagepng($im, $uploads . '/' . $rel) : imagejpeg($im, $uploads . '/' . $rel, 80);
        imagedestroy($im);
    };
    $atasamente = [
        'poza_camera' => ['2024/05/camera-dubla.jpg', 'Camera dublă', 'Camera dublă cu vedere la râu', 1200, 800, [180, 120, 80]],
        'poza_curte' => ['2024/05/curtea-mare-scaled.jpg', 'Curtea mare', 'Curtea pensiunii', 2560, 1700, [60, 140, 70]],
        'logo' => ['2024/05/logo-proba.png', 'Logo Pensiunea Probă', '', 300, 120, [200, 150, 20]],
        'iconita' => ['2024/05/cropped-iconita.png', 'Iconița', '', 512, 512, [200, 150, 20]],
    ];
    foreach ($atasamente as $nume => [$rel, $titlu, $alt, $l, $h, $c]) {
        $poza($rel, min($l, 400), min($h, 300), $c);
        $id = $adauga($nume, 'attachment', $titlu, pathinfo($rel, PATHINFO_FILENAME), '', 'inherit', 0, 'image/' . (str_ends_with($rel, '.png') ? 'png' : 'jpeg'));
        $meta($id, '_wp_attached_file', $rel);
        if ($alt !== '') {
            $meta($id, '_wp_attachment_image_alt', $alt);
        }
        $marimi = [];
        foreach (['thumbnail' => [150, 150], 'medium' => [300, 200]] as $m => [$ml, $mh]) {
            // Mărimile unei poze „-scaled” au numele originalului (ca în WordPress).
            $f = preg_replace('/\.(jpg|png)$/', '-' . $ml . 'x' . $mh . '.$1', str_replace('-scaled', '', basename($rel)));
            $poza('2024/05/' . $f, $ml, $mh, $c);
            $marimi[$m] = ['file' => $f, 'width' => $ml, 'height' => $mh, 'mime-type' => 'image/jpeg'];
        }
        $md = ['width' => $l, 'height' => $h, 'file' => $rel, 'sizes' => $marimi];
        if (str_contains($rel, '-scaled')) {
            $original = str_replace('-scaled', '', basename($rel));
            $poza('2024/05/' . $original, 400, 300, $c);
            $md['original_image'] = $original;
        }
        $meta($id, '_wp_attachment_metadata', $md);
    }
    file_put_contents($uploads . '/2024/05/meniu-restaurant.pdf', "%PDF-1.4\n% proba\n%%EOF\n");
    $pdf = $adauga('pdf', 'attachment', 'Meniul restaurantului', 'meniu-restaurant', '', 'inherit', 0, 'application/pdf');
    $meta($pdf, '_wp_attached_file', '2024/05/meniu-restaurant.pdf');
    // Un fișier al unui plugin, pomenit nicăieri: nu se copiază.
    @mkdir($uploads . '/complianz/css', 0775, true);
    file_put_contents($uploads . '/complianz/css/banner.css', "body{}\n");

    // Paginile.
    $u = $url . '/wp-content/uploads/2024/05/';
    $acasa = $adauga('acasa', 'page', 'Acasă', 'acasa', "<!-- wp:heading {\"level\":1} --><h1>Bine ați venit la Pensiunea Probă</h1><!-- /wp:heading -->\n"
        . "<!-- wp:image {\"id\":2} --><figure class=\"wp-block-image\"><img src=\"{$u}curtea-mare-scaled.jpg\" alt=\"Curtea\" srcset=\"{$u}curtea-mare-scaled.jpg 2560w, {$u}curtea-mare-300x200.jpg 300w\"/></figure><!-- /wp:image -->\n"
        . "<!-- wp:paragraph --><p>Vezi <a href=\"{$url}/camere/\">camerele</a> și <a href=\"/contact/\">contactul</a>. Meniul: <a href=\"{$u}meniu-restaurant.pdf\">PDF</a>.</p><!-- /wp:paragraph -->");
    $camere = $adauga('camere', 'page', 'Camere &amp; prețuri', 'camere', "Avem camere duble și triple.\n\nFiecare are baie proprie.\n"
        . "[caption id=\"attachment_2\" align=\"aligncenter\" width=\"300\"]<img src=\"{$u}camera-dubla-300x200.jpg\" alt=\"Camera dublă\" width=\"300\" height=\"200\" /> Camera dublă[/caption]\n\n"
        . '[gallery ids="' . $ids['poza_camera'] . ',' . $ids['poza_curte'] . '" columns="2"]', 'publish', 0, '', 'Camere confortabile la munte.', 2);
    $meta($camere, '_wp_old_slug', 'camerele-noastre');
    $adauga('contact', 'page', 'Contact', 'contact', "<!-- wp:paragraph --><p>Ne găsiți în satul Probă. Rezervări: vezi <a href=\"{$url}/?page_id={$camere}\">camerele</a>.</p><!-- /wp:paragraph -->"
        . "<!-- wp:html --><iframe src=\"https://www.google.com/maps/embed?pb=proba\" width=\"600\" height=\"300\"></iframe><!-- /wp:html -->", 'publish', 0, '', '', 5);
    $conf = $adauga('confidentialitate', 'page', 'Politica de confidențialitate', 'politica-de-confidentialitate',
        "<!-- wp:paragraph --><p>Datele tale sunt în siguranță.</p><!-- /wp:paragraph -->", 'publish', 0, '', '', 9);
    $despre = $adauga('despre', 'page', 'Despre noi', 'despre-noi', "<p>Suntem o pensiune de familie.</p>\n<script>alert('vechi')</script>", 'publish', 0, '', '', 3);
    $adauga('echipa', 'page', 'Echipa', 'echipa', "Echipa noastră.", 'publish', $despre, '', '', 1);
    $adauga('privata', 'page', 'Pagină privată', 'pagina-privata', 'Doar pentru noi.', 'private');
    $adauga('ciorna', 'page', 'Ciornă', 'ciorna', 'Neterminată.', 'draft');
    if ($o['divi'] ?? false) {
        $divi = $adauga('divi', 'page', 'Evenimente', 'evenimente', '[et_pb_section fb_built="1"][et_pb_row][et_pb_column type="4_4"][et_pb_text]<p>Foc de tabără</p>'
            . '[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]', 'publish', 0, '', '', 4);
        $meta($divi, '_et_pb_use_builder', 'on');
    }
    $meta($camere, '_thumbnail_id', (string) $ids['poza_camera']);
    if ($o['unicode'] ?? false) {
        // Slug-urile ca în WordPress: post_name percent-encoded, cu hexa mic (sanitize_title / utf8_uri_encode).
        $adauga('unicode_ro', 'page', 'Camere și prețuri speciale', 'camere-%c8%99i-pre%c8%9buri', '<p>Oferte.</p>', 'publish', 0, '', '', 6);
        $adauga('unicode_el', 'page', 'Δωμάτια', '%ce%b4%cf%89%ce%bc%ce%ac%cf%84%ce%b9%ce%b1', '<p>Δωμάτια.</p>', 'publish', 0, '', '', 7);
        @mkdir($uploads . '/2024/06', 0775, true);
        foreach (['harta' => ['2024/06/harta-proba.png', 0], 'scara' => ['2024/06/scara-proba.png', $camere]] as $nume => [$rel, $parinte]) {
            $poza($rel, 40, 30, [10, 20, 30]);
            $at = $adauga('atasament_' . $nume, 'attachment', ucfirst($nume), pathinfo($rel, PATHINFO_FILENAME), '', 'inherit', $parinte, 'image/png');
            $meta($at, '_wp_attached_file', $rel);
            $meta($at, '_wp_attachment_metadata', ['width' => 40, 'height' => 30, 'file' => $rel, 'sizes' => []]);
        }
    }

    // SEO (All in One SEO): titluri cu etichete, o descriere scrisă, un noindex.
    $seo = $db->prepare("INSERT INTO {$p}aioseo_posts (post_id, title, description, robots_default, robots_noindex, og_image_type) VALUES (?, ?, ?, ?, ?, ?)");
    $seo->execute([$acasa, 'Pensiunea Probă: cazare la munte', 'Cazare liniștită la munte, cu mic dejun.', 1, 0, 'default']);
    $seo->execute([$camere, '#post_title #separator_sa #site_title', 'Camerele pensiunii: duble și triple.', 1, 0, 'featured']);
    $seo->execute([$conf, '', '', 0, 1, 'default']);

    // Meniul „Principal”.
    $db->exec("INSERT INTO {$p}terms (name, slug) VALUES ('Principal', 'principal'), ('Uncategorized', 'uncategorized')");
    $db->exec("INSERT INTO {$p}term_taxonomy (term_id, taxonomy, description, parent, count) VALUES (1, 'nav_menu', '', 0, 5), (2, 'category', '', 0, 1)");
    $db->exec("INSERT INTO {$p}term_relationships (object_id, term_taxonomy_id) VALUES ({$ids['hello']}, 2)");
    $element = static function (string $titlu, string $tip, string $obiect, int $obiectId, string $link, int $parinte, int $ordine) use ($adauga, $meta, $db, $p): int {
        $id = $adauga('meniu_' . $ordine, 'nav_menu_item', $titlu, (string) $ordine, '', 'publish', 0, '', '', $ordine);
        foreach (['_menu_item_type' => $tip, '_menu_item_object' => $obiect, '_menu_item_object_id' => (string) $obiectId, '_menu_item_url' => $link,
            '_menu_item_menu_item_parent' => (string) $parinte, '_menu_item_target' => '', '_menu_item_classes' => ['']] as $k => $v) {
            $meta($id, $k, $v);
        }
        $db->exec("INSERT INTO {$p}term_relationships (object_id, term_taxonomy_id) VALUES ({$id}, 1)");
        return $id;
    };
    $element('', 'post_type', 'page', $acasa, '', 0, 1);
    $element('Camere', 'post_type', 'page', $camere, '', 0, 2);
    $elDespre = $element('', 'post_type', 'page', $despre, '', 0, 3);
    $element('Echipa noastră', 'post_type', 'page', $ids['echipa'], '', $elDespre, 4);
    $element('Rezervări online', 'custom', 'custom', 0, 'https://rezervari.exemplu.test/proba', 0, 5);

    // Datele Divi 4 după meniu și categorii (termenii de mai sus au id-urile 1 și 2 scrise de mână).
    if ($o['divi4'] ?? false) {
        wordpressProbaDivi4($db, $p, $adauga, $meta, $ids);
    }

    // Opțiunile.
    foreach ([
        'siteurl' => $url, 'home' => $url, 'blogname' => WP_PROBA_SITE, 'blogdescription' => 'Cazare la munte', 'admin_email' => 'agentia@example.test',
        'permalink_structure' => '/%postname%/', 'show_on_front' => 'page', 'page_on_front' => (string) $acasa, 'page_for_posts' => '0',
        'site_icon' => (string) $ids['iconita'], 'template' => 'Divi', 'stylesheet' => 'Divi', 'wp_page_for_privacy_policy' => (string) $conf,
        'active_plugins' => ['all-in-one-seo-pack/all_in_one_seo_pack.php', 'complianz-gdpr/complianz-gpdr.php', 'google-site-kit/google-site-kit.php',
            'microsoft-clarity/clarity.php', 'un-plugin-necunoscut/plugin.php'],
        'theme_mods_Divi' => ['nav_menu_locations' => ['primary-menu' => 1], 'custom_logo' => $ids['logo']],
        'googlesitekit_analytics-4_settings' => ['measurementID' => 'G-PROBA123', 'googleTagID' => 'GT-PROBA'],
        'clarity_project_id' => 'abcd1234ef',
        'aioseo_options' => json_encode(['searchAppearance' => ['global' => ['separator' => '&#45;']]]),
        'aioseo_options_dynamic' => json_encode(['searchAppearance' => ['postTypes' => ['page' => ['title' => '#post_title #separator_sa #site_title',
            'metaDescription' => '#post_excerpt']]]]),
    ] as $k => $v) {
        $optiune($k, $v);
    }

    // Dosarul WordPress: wp-config.php (citit de import fără să fie rulat), versiunea, un index care răspunde ca WordPress.
    @mkdir($dosar . '/wp-includes', 0775, true);
    @mkdir($dosar . '/wp-content/themes/Divi', 0775, true);
    $gazda = $o['gazda'] ?? 'localhost';
    file_put_contents($dosar . '/wp-config.php', "<?php\n/** Configurația WordPress de probă (tools/dev/lib/wordpress-proba.php). */\n"
        . "define( 'DB_NAME', '" . $baza . "' );\n// define( 'DB_NAME', 'alta_baza' );\ndefine( 'DB_USER', '" . addslashes($o['utilizator'] ?? 'root') . "' );\n"
        . "define( 'DB_PASSWORD', '" . addslashes($o['parola'] ?? '') . "' );\ndefine( 'DB_HOST', '" . $gazda . "' );\ndefine( 'DB_CHARSET', 'utf8mb4' );\n"
        . "define( 'AUTH_KEY', 'proba' . time() );\n\$table_prefix = '" . $p . "';\ndefine( 'WP_DEBUG', false );\n"
        . "if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }\nrequire_once ABSPATH . 'wp-settings.php';\n");
    file_put_contents($dosar . '/wp-includes/version.php', "<?php\n\$wp_version = '6.8.1';\n\$wp_db_version = 60421;\n");
    file_put_contents($dosar . '/wp-settings.php', "<?php\n// Proba: WordPress nu rulează aici.\n");
    file_put_contents($dosar . '/index.php', "<?php\nheader('Content-Type: text/html; charset=utf-8');\n"
        . "echo '<!doctype html><html><head><meta name=\"generator\" content=\"WordPress 6.8.1\"><link rel=\"stylesheet\" href=\"/wp-content/themes/Divi/style.css\"></head>'\n"
        . "    . '<body>WORDPRESS PROBA ' . htmlspecialchars((string) (\$_SERVER['REQUEST_URI'] ?? '/')) . '</body></html>';\n");
    file_put_contents($dosar . '/.htaccess', "# BEGIN WordPress\nRewriteEngine On\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule . /index.php [L]\n# END WordPress\n");
    file_put_contents($dosar . '/wp-content/themes/Divi/style.css', "body{color:#666}\n");
    file_put_contents($dosar . '/google1234567890abcdef.html', "google-site-verification: google1234567890abcdef.html\n");
    @mkdir($dosar . '/.well-known', 0775, true);
    file_put_contents($dosar . '/.well-known/security.txt', "Contact: mailto:securitate@example.test\n");
    return $ids;
}

/**
 * Datele Divi 4 ale probei (corectura 1.8): Theme Builder-ul, modulul de contact, Dynamic Content, proiectele, CF7,
 * Polylang, categoriile mediilor și autorul. Totul inventat.
 *
 * @param array<string, int> $ids
 */
function wordpressProbaDivi4(PDO $db, string $p, callable $adauga, callable $meta, array &$ids): void
{
    $sectiune = static fn (string $module): string => '[et_pb_section fb_built="1"][et_pb_row][et_pb_column type="4_4"]' . $module
        . '[/et_pb_column][/et_pb_row][/et_pb_section]';
    $an = base64_encode((string) json_encode(['dynamic' => true, 'content' => 'current_date',
        'settings' => ['before' => '© ', 'after' => ' ' . WP_PROBA_SITE, 'date_format' => 'custom', 'custom_date_format' => 'Y']]));
    $antet = $adauga('tb_antet', 'et_header_layout', 'Antetul probei', 'antetul-probei', $sectiune('[et_pb_menu menu_id="1"][/et_pb_menu]'));
    $subsol = $adauga('tb_subsol', 'et_footer_layout', 'Subsolul probei', 'subsolul-probei', $sectiune('[et_pb_text]<p>@ET-DC@' . $an . '@</p>[/et_pb_text]'
        . '[et_pb_contact_form email="rezervari@example.test" title="Scrie-ne" success_message="Mulțumim, revenim curând." use_redirect="off" '
        . 'captcha="on" _builder_version="4.27.4"][et_pb_contact_field field_id="Name" field_title="Nume"][/et_pb_contact_field]'
        . '[/et_pb_contact_form]'));
    $corp404 = $adauga('tb_corp404', 'et_body_layout', 'Corpul paginii 404', 'corpul-paginii-404', $sectiune('[et_pb_text]<p>Pagina nu există.</p>[/et_pb_text]'));
    $sablon = $adauga('tb_sablon', 'et_template', 'Șablonul implicit', 'sablonul-implicit', '');
    foreach (['_et_default' => '1', '_et_enabled' => '1', '_et_header_layout_id' => (string) $antet, '_et_header_layout_enabled' => '1',
        '_et_body_layout_id' => '0', '_et_body_layout_enabled' => '1', '_et_footer_layout_id' => (string) $subsol, '_et_footer_layout_enabled' => '1'] as $k => $v) {
        $meta($sablon, $k, $v);
    }
    $sablon404 = $adauga('tb_sablon404', 'et_template', 'Pagina 404', 'pagina-404', '');
    foreach (['_et_default' => '0', '_et_enabled' => '1', '_et_use_on' => '404', '_et_header_layout_id' => (string) $antet,
        '_et_body_layout_id' => (string) $corp404, '_et_footer_layout_id' => (string) $subsol] as $k => $v) {
        $meta($sablon404, $k, $v);
    }
    $meta($sablon404, '_et_exclude_from', 'singular:post_type:page:id:' . $ids['contact']);
    $meta($sablon404, '_et_exclude_from', 'singular:post_type:post:all');
    $tb = $adauga('tb', 'et_theme_builder', 'Theme Builder', 'theme-builder', '');
    $meta($tb, '_et_template', (string) $sablon);
    $meta($tb, '_et_template', (string) $sablon404);
    // Un proiect Divi, cu categoria lui.
    $proiect = $adauga('proiect', 'project', 'Drumeție pe munte', 'drumetie-pe-munte', '<p>Traseul nostru de o zi.</p>');
    $db->exec("INSERT INTO {$p}terms (name, slug) VALUES ('Ieșiri', 'iesiri')");
    $termen = (int) $db->lastInsertId();
    $db->exec("INSERT INTO {$p}term_taxonomy (term_id, taxonomy, description, parent, count) VALUES ({$termen}, 'project_category', '', 0, 1)");
    $db->exec("INSERT INTO {$p}term_relationships (object_id, term_taxonomy_id) VALUES ({$proiect}, " . (int) $db->lastInsertId() . ')');
    // Contact Form 7.
    $adauga('cf7', 'wpcf7_contact_form', 'Formularul de contact', 'formularul-de-contact', '[text* nume] [email* email] [submit "Trimite"]');
    // Polylang: română și engleză, cu o pereche de traduceri (Acasă ↔ Contact, doar ca date).
    foreach ([['Română', 'ro', 'ro_RO'], ['English', 'en', 'en_US']] as [$nume, $slug, $locale]) {
        $db->prepare("INSERT INTO {$p}terms (name, slug) VALUES (?, ?)")->execute([$nume, $slug]);
        $db->prepare("INSERT INTO {$p}term_taxonomy (term_id, taxonomy, description, parent, count) VALUES (?, 'language', ?, 0, 1)")
            ->execute([(int) $db->lastInsertId(), serialize(['locale' => $locale, 'rtl' => 0, 'flag_code' => $slug])]);
    }
    $db->exec("INSERT INTO {$p}terms (name, slug) VALUES ('pll_proba', 'pll_proba')");
    $db->prepare("INSERT INTO {$p}term_taxonomy (term_id, taxonomy, description, parent, count) VALUES (?, 'post_translations', ?, 0, 2)")
        ->execute([(int) $db->lastInsertId(), serialize(['ro' => $ids['acasa'], 'en' => $ids['contact']])]);
    // Categoriile mediilor (o taxonomie inventată, ca la galeriile Divi): „Camere” și „Curte”.
    foreach ([['Camere', 'camere-foto', 'poza_camera'], ['Curte', 'curte-foto', 'poza_curte']] as [$nume, $slug, $poza]) {
        $db->prepare("INSERT INTO {$p}terms (name, slug) VALUES (?, ?)")->execute([$nume, $slug]);
        $db->prepare("INSERT INTO {$p}term_taxonomy (term_id, taxonomy, description, parent, count) VALUES (?, 'categorie_media_proba', '', 0, 1)")
            ->execute([(int) $db->lastInsertId()]);
        $db->prepare("INSERT INTO {$p}term_relationships (object_id, term_taxonomy_id) VALUES (?, ?)")->execute([$ids[$poza], (int) $db->lastInsertId()]);
    }
    // Autorul conținutului (doar ID-ul și user_nicename contează pentru import).
    $db->exec("CREATE TABLE {$p}users (ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_login VARCHAR(60) NOT NULL DEFAULT '',
        user_nicename VARCHAR(50) NOT NULL DEFAULT '', user_email VARCHAR(100) NOT NULL DEFAULT '', display_name VARCHAR(250) NOT NULL DEFAULT '')");
    $db->exec("INSERT INTO {$p}users (user_login, user_nicename, user_email, display_name) VALUES ('autor', 'autorul-probei', 'autor@example.test', 'Autorul probei')");
    $db->exec("UPDATE {$p}posts SET post_author = 1 WHERE post_type IN ('post', 'page', 'project') AND post_status = 'publish'");
}
