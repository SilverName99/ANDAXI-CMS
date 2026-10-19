<?php

declare(strict_types=1);

/**
 * Partea PHP a lui tools/dev/compara-cu-baza.sh (nu se rulează singur):
 * aceleași cereri spre serverul curent și spre copia de bază, apoi compararea
 * răspunsurilor normalizate.
 *
 *   php compara-cu-baza.php <port-baza> <eticheta-bazei> <dosarul-copiei-de-baza> <repo|commit> [<port-curent>]
 *   php compara-cu-baza.php --autotest     # doar verificarea mecanismului de diferențe (fără servere)
 *
 * Ce se scoate înainte de comparare (diferă la fiecare cerere, nu ține de cod):
 * tokenurile formularelor (checkout, înregistrare, CSRF), momentul randării,
 * versiunea fișierelor CSS/JS (`?v=` = data fișierului pe disc), identificatorii
 * aleși la întâmplare ai formularelor, ora curentă.
 *
 * Baza „repo” (implicit: codul Bioscem însuși, la ultimul commit revizuit): nimic
 * altceva; livrarea 1 SEO și porturile din Bioscem sunt deja în codul de bază, deci
 * trebuie să iasă octet cu octet.
 *
 * Baza „commit” (un commit din acest repo, de exemplu f5ae5ea, dinaintea pluginurilor;
 * doar pentru istoric): în plus, doar din pagina codului curent, diferențele SEO ale
 * livrării 1 (docs/SEO.md), scrise mai jos una câte una ($faraSeo): ce s-a scos se
 * afișează pe pagină, iar datele structurate trebuie să apară exact unde sunt
 * așteptate ($jsonLdAsteptat). La fel textele schimbărilor portate din Bioscem după
 * acel commit ($porturiBioscem): fiecare exact o dată, pe pagina ei.
 *
 * În ambele moduri, tot doar din pagina codului curent: diferențele declarate
 * explicit, cu pagina și motivul lor ($deBioscem, $porturiBiovitality,
 * $porturiVertij, $porturiGrafo) și blocurile marcate cu <!--port:…--> ($marcajePort).
 * Contractul lor e în tools/dev/lib/diferente-asteptate.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/lib/diferente-asteptate.php';
if (in_array('--autotest', $argv, true)) {
    $esecuri = diferente_autotest();
    foreach ($esecuri as $esec) {
        echo '  EȘEC  ', $esec, "\n";
    }
    echo $esecuri === [] ? "Mecanismul diferențelor așteptate (texte, marcaje de port): OK\n" : count($esecuri) . " cazuri greșite.\n";
    exit($esecuri === [] ? 0 : 1);
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';

use App\Support\Database;
use App\Support\Plugins;

$portBaza = (string) ($argv[1] ?? '');
$commitBaza = (string) ($argv[2] ?? '?');
$dosarBaza = rtrim((string) ($argv[3] ?? ''), '/');
// „repo”: codul unui site vechi (Bioscem); „commit”: un commit din acest repo (istoric).
$modBaza = (string) ($argv[4] ?? 'commit');
$fataDeCommit = $modBaza === 'commit';
if ($portBaza === '' || !in_array($modBaza, ['repo', 'commit'], true)) {
    fwrite(STDERR, "Folosește tools/dev/compara-cu-baza.sh\n");
    exit(1);
}
// Serverul codului curent: cel de dezvoltare sau, față de un repo vechi, unul pornit doar
// pentru comparație (cu pachetul de date al site-ului).
$portCurent = (string) (($argv[5] ?? '') !== '' ? $argv[5] : (getenv('CMS_DEV_PORT') ?: '8701'));
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$dosarDiff = $dosarDev . '/compara';
@mkdir($dosarDiff, 0770, true);
foreach (glob($dosarDiff . '/*') ?: [] as $vechi) {
    @unlink($vechi);
}

$autotest = diferente_autotest();
if ($autotest !== []) {
    fwrite(STDERR, "Mecanismul diferențelor așteptate nu merge (tools/dev/lib/diferente-asteptate.php):\n  "
        . implode("\n  ", $autotest) . "\n");
    exit(1);
}

// Comparația are sens doar cu pluginurile Bioscem pornite: licența „toate” sau
// licența de dezvoltare „bioscem” (config/plugins.php → licente_dev; fără unealta internă).
$neoprite = array_values(array_filter(array_map('strval', (array) (Plugins::catalog()['licente_dev']['bioscem'] ?? [])),
    static fn (string $cheie): bool => Plugins::stare($cheie) !== 'pornit'));
if ($neoprite !== [] || (Plugins::catalog()['licente_dev']['bioscem'] ?? []) === []) {
    fwrite(STDERR, 'Comparația are sens doar cu licența „toate” sau „bioscem” (pune-o cu: tools/dev/php.sh tools/dev/licenta.php toate). '
        . 'Acum nu sunt pornite: ' . implode(', ', $neoprite) . "\n");
    exit(1);
}
$faraUnealtaInterna = !Plugins::vizibil('migrare_wordpress');
if ($faraUnealtaInterna) {
    echo '  INFO  licență fără unealta internă migrare_wordpress (ca Bioscem): panoul „Import din backup WordPress” din '
        . "Utilizatori lipsește intenționat și nu se compară; restul paginilor, octet cu octet.\n";
}

$app = require $radacina . '/config/app.php';
$db = Database::connection((array) $app['db']);
if (!$db instanceof PDO) {
    fwrite(STDERR, "Baza de test nu răspunde.\n");
    exit(1);
}
$unu = static fn (string $sql): string => (string) ($db->query($sql)->fetchColumn() ?: '');
$parola = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));
$adminEmail = (string) (getenv('CMS_DEV_ADMIN_EMAIL') ?: 'admin@exemplu.ro');

$cerere = static function (string $port, string $metoda, string $cale, string $jar, array $date = []): array {
    $c = curl_init('http://127.0.0.1:' . $port . $cale);
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metoda,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOPROXY => '*',
        CURLOPT_PROXY => '',
        CURLOPT_TIMEOUT => 60,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_HEADER => true,
    ]);
    if ($metoda === 'POST') {
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($date));
    }
    $raspuns = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $marimeAntet = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    $antet = substr($raspuns, 0, $marimeAntet);
    $location = preg_match('/^Location:\s*(\S+)/mi', $antet, $m) === 1 ? $m[1] : '';
    return ['cod' => $cod, 'corp' => substr($raspuns, $marimeAntet), 'location' => $location];
};

/** Ce diferă la fiecare cerere, indiferent de cod. */
$normalizeaza = static function (string $html) use ($portBaza, $portCurent, $dosarBaza, $radacina, $faraUnealtaInterna): string {
    if ($faraUnealtaInterna) {
        // Panoul importului din backup WordPress (unealta internă), din fereastra de import a utilizatorilor.
        $html = (string) preg_replace('#(data-admin-simple-panel="import"[^>]*>).*?(<h3[^>]*>Import din fișier CSV/XLSX</h3>)#s',
            '$1<IMPORT-WORDPRESS>$2', $html);
    }
    $html = str_replace('127.0.0.1:' . $portBaza, '127.0.0.1:' . $portCurent, $html);
    // Căile de pe disc apar în instrucțiunile de cron din admin.
    if ($dosarBaza !== '') {
        $html = str_replace($dosarBaza, $radacina, $html);
    }
    $inlocuiri = [
        // Tokenurile formularelor și momentul randării lor.
        '/(name="(?:checkout_form_token|register_form_token|_csrf|csrf_token|admin_csrf)"\s+value=")[^"]*"/' => '$1<TOKEN>"',
        '/(name="(?:checkout_form_rendered_at|register_form_rendered_at)"\s+value=")[^"]*"/' => '$1<MOMENT>"',
        '/("(?:token|rendered_at)"\s*:\s*)("[^"]*"|\d+)/' => '$1<TOKEN>',
        // Aceleași câmpuri în HTML-ul de previzualizare pus în JSON (editorul de pagini: \u0022 în loc de ghilimele).
        '/(name=\\\\u0022(?:checkout_form_token|register_form_token|_csrf|csrf_token|admin_csrf)\\\\u0022\s+value=\\\\u0022)[^\\\\]*/' => '$1<TOKEN>',
        '/(name=\\\\u0022(?:checkout_form_rendered_at|register_form_rendered_at)\\\\u0022\s+value=\\\\u0022)\d+/' => '$1<MOMENT>',
        '/(String\()"[0-9a-f]{16,}"/' => '$1"<TOKEN>"',
        '/(Number\()\d{9,}/' => '$1<MOMENT>',
        '/(window\.BT_CSRF\s*=\s*)"[^"]*"/' => '$1"<TOKEN>"',
        // Versiunea fișierelor statice = data fișierului pe disc.
        '/(\.(?:css|js)\?v=)\d+/' => '$1<V>',
        // Identificatori aleși la întâmplare (formularele de checkout/cont).
        '/\b(bv-checkout-[a-z0-9-]*?)[0-9a-f]{6,}\b/' => '$1<ID>',
        '/\b([a-z]+-)[0-9a-f]{12,}\b/' => '$1<ID>',
    ];
    foreach ($inlocuiri as $tipar => $cu) {
        $html = (string) preg_replace($tipar, $cu, $html);
    }
    return $html;
};

/**
 * Diferențele SEO ale livrării 1 (docs/SEO.md), scrise aici una câte una și
 * scoase DOAR din pagina codului curent, ca restul paginii să se compare octet
 * cu octet cu codul de bază. Fiecare transformare caută exact textul pus de
 * cod; ce găsește se numără pe pagină, iar la final se verifică unde trebuie
 * să apară (de exemplu datele structurate doar pe prima pagină, pe produse și
 * pe categorie). O schimbare SEO nouă trebuie adăugată aici, explicit.
 *
 *  1. blocurile <script type="application/ld+json"> din <head>;
 *  2. robots.txt: „/cos” scris exact (/cos$, /cos?, /cos/) și regulile din
 *     seo.json-ul site-ului (robots_disallow);
 *  3. width/height pe pozele produsului și ale cardurilor din catalog;
 *  4. poza principală a produsului: loading="eager" fetchpriority="high"
 *     (în loc de loading="lazy") și height:auto în stilul ei;
 *  5. stilurile cardurilor din catalog, mutate înaintea cardurilor;
 *  6. aceleași (3, 5) și app.css (height:auto) în previzualizările editorului de pagini;
 *  7. textul secțiunii „Sitemap XML” din Setări magazin.
 */
/** preg_replace_callback care nu întoarce niciodată în tăcere o pagină goală (eroare PCRE = test oprit). */
$pr = static function (string $tipar, callable $cb, string $html): string {
    $rezultat = preg_replace_callback($tipar, $cb, $html);
    if ($rezultat === null) {
        throw new RuntimeException('PCRE: ' . preg_last_error_msg() . ' la ' . $tipar);
    }
    return $rezultat;
};
$textCssSeo = "\n    /* Poza are width/height în HTML ca locul ei să fie rezervat înainte să se\n"
    . "       încarce; înălțimea afișată rămâne cea proporțională, ca până acum. */\n    height: auto;";
$reguliRobotsSite = array_map('strval', (array) \App\Support\DateSite::seo('robots_disallow', []));
$faraSeoHtml = static function (string $html, array &$gasite) use ($pr): string {
    // 5. Stilurile cardurilor, înaintea secțiunii → la loc după ea.
    $html = $pr(
        '#<style>\n(\.shop-catalog-v2\{background:\#fff;.*?)</style>\n(<section class="shop-catalog-v2" data-shop-catalog-token="1">.*?</section>\n\n)#s',
        static function (array $m) use (&$gasite): string {
            $gasite['stiluri catalog mutate'] = ($gasite['stiluri catalog mutate'] ?? 0) + 1;
            return $m[2] . "<style>\n" . $m[1] . "</style>\n\n";
        },
        $html
    );
    // 3. width/height puse imediat după alt, înaintea lui loading.
    $html = $pr(
        '#(<img\b[^>]*? alt="[^"]*")( width="\d+" height="\d+")(\s+loading=")#',
        static function (array $m) use (&$gasite): string {
            $gasite['poze cu width/height'] = ($gasite['poze cu width/height'] ?? 0) + 1;
            return $m[1] . $m[3];
        },
        $html
    );
    // 4. Poza principală: încărcată imediat, cu prioritate.
    $html = $pr('#loading="eager"\s+fetchpriority="high"#', static function () use (&$gasite): string {
        $gasite['poza principală eager'] = ($gasite['poza principală eager'] ?? 0) + 1;
        return 'loading="lazy"';
    }, $html);
    $html = str_replace('style="max-width:420px;width:100%;height:auto;border-radius:10px;"',
        'style="max-width:420px;width:100%;border-radius:10px;"', $html, $inlocuite);
    if ($inlocuite > 0) {
        $gasite['height:auto pe poza principală'] = ($gasite['height:auto pe poza principală'] ?? 0) + $inlocuite;
    }
    return $html;
};
$faraSeo = static function (string $cale, string $html, array &$gasite) use ($pr, $faraSeoHtml, $textCssSeo, $reguliRobotsSite): string {
    if ($cale === '/robots.txt') {
        $html = str_replace("Disallow: /cos\$\nDisallow: /cos?\nDisallow: /cos/\n", "Disallow: /cos\n", $html, $n);
        $gasite['robots: /cos exact'] = $n;
        foreach ($reguliRobotsSite as $regula) {
            $html = str_replace('Disallow: ' . $regula . "\n", '', $html, $n);
            $gasite['robots: regula site-ului ' . $regula] = $n;
        }
        return $html;
    }
    // 1. Datele structurate: câte un bloc pe linie, în <head>.
    $html = $pr(
        '#    <script type="application/ld\+json">(\{[^\n]*?\})</script>\n#',
        static function (array $m) use (&$gasite): string {
            $nod = json_decode(str_replace(['<', '>', '&'], ['<', '>', '&'], $m[1]), true);
            $gasite['JSON-LD'][] = is_array($nod) ? (string) ($nod['@type'] ?? '?') : 'JSON invalid';
            return '';
        },
        $html
    );
    $html = $faraSeoHtml($html, $gasite);
    // 6. Previzualizările din editorul de pagini (șiruri JSON în <script>, scrise cu
    //    JSON_HEX_QUOT: în interior nu apare niciun " neprotejat, deci șirul se
    //    termină la primul ghilimel; fără expresii regulate pe sute de KB).
    $flaguri = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    foreach (['const shopCatalogSectionHtml = "', 'const previewAppCssContent = "'] as $inceput) {
        $poz = strpos($html, $inceput);
        if ($poz === false) {
            continue;
        }
        $deschis = $poz + strlen($inceput) - 1;
        $inchis = strpos($html, '"', $deschis + 1);
        $sir = $inchis !== false ? json_decode(substr($html, $deschis, $inchis - $deschis + 1), true) : null;
        if (!is_string($sir)) {
            continue;
        }
        $nou = $faraSeoHtml($sir, $gasite);
        $nou = str_replace($textCssSeo, '', $nou, $n);
        if ($n > 0) {
            $gasite['app.css: height:auto'] = ($gasite['app.css: height:auto'] ?? 0) + $n;
        }
        $html = substr($html, 0, $deschis) . (string) json_encode($nou, $flaguri) . substr($html, (int) $inchis + 1);
    }
    // 7. Setări magazin → Sitemap XML.
    $html = $pr(
        '#Sitemap-ul se construiește automat din baza de date, la fiecare cerere: [^\n]*\n\s*Nu mai trebuie generat după modificări\.\n'
            . '\s*Butonul de mai jos verifică lista și șterge un fișier sitemap\.xml vechi rămas pe server\n'
            . '\s*\(cu alt nume de fișier, scrie o copie statică, de exemplu pentru teste\)\.#',
        static function () use (&$gasite): string {
            $gasite['text Sitemap XML'] = 1;
            return 'Generează sitemap-ul pentru indexare în Google Search Console.';
        },
        $html
    );
    return $html;
};
/**
 * Doar față de un commit din acest repo (baza „commit”): schimbările din Bioscem
 * portate după acel commit, altele decât SEO. Textul nou, exact cum îl scrie codul,
 * pagina pe care trebuie să apară și ce era în locul lui. Față de Bioscem (baza
 * „repo”) nu se aplică: codul Bioscem le are deja. Un port din Bioscem nu se mai
 * adaugă aici (se mută „ultimul commit revizuit” în docs/de-portat.md).
 */
$porturiBioscem = [
    [
        'nume' => 'colete alese în ERP',
        'cale' => '/admin/settings/shipping',
        'nou' => "0 = fără împărțire automată. Numărul calculat se poate schimba pe\n"
            . "            fiecare comandă din ERP, înainte de aprobare.</small>",
        'vechi' => '0 = fără împărțire automată.</small>',
        'motiv' => 'Bioscem de5c2b0 „AWB: numărul de colete poate fi ales în ERP la aprobare” (CMS 4915478; doar cu ERP-ul pe site)',
    ],
];

/**
 * În ambele moduri: diferențele față de codul de bază declarate explicit, fiecare cu
 * pagina și motivul ei (contractul: tools/dev/lib/diferente-asteptate.php). Câte o
 * listă pe sursă, câte un sub-bloc pe WP (`// WP-xx: <id-uri>`).
 *
 *  - $deBioscem: ce scrie nucleul altfel decât Bioscem, intenționat (texte Bioscem
 *    scoase din nucleu, generalizări); fiecare rând intră și în
 *    site-uri/bioscem/ce-se-schimba.md dacă îl vede clientul;
 *  - $porturiBiovitality, $porturiVertij, $porturiGrafo: textele mici aduse din
 *    celelalte site-uri (o bucată mai mare se scrie între marcaje, mai jos).
 *
 * O intrare cu 'mod' => 'repo' descrie o diferență față de implementarea Bioscem
 * însăși (de exemplu cum a generalizat nucleul livrarea 1 SEO) și se verifică doar
 * față de codul Bioscem: față de un commit dinaintea SEO, $faraSeo scoate oricum
 * toată bucata.
 */
$deBioscem = [
    // WP-01: diferențele găsite la prima comparație cu Bioscem f5eafa3 (G-M2), toate din
    // portarea generalizată a livrării 1 SEO (c2b9331). Nu schimbă ce vede un vizitator.
    [
        'nume' => 'robots.txt: regula coșului din constructor, din seo.json',
        'cale' => '/robots.txt',
        'nou' => "Disallow: /gdpr-agreements\nDisallow: /cos-cumparaturi\n",
        'vechi' => "Disallow: /gdpr-agreements\n",
        'motiv' => 'Bioscem are /cos-cumparaturi scris în cod (Robots::DISALLOW), după /cos/; nucleul îl ia din '
            . 'site-uri/bioscem/seo.json (robots_disallow) și îl pune după regulile pluginurilor. Aceleași reguli, '
            . 'altă ordine: pentru roboți ordinea nu contează (câștigă regula cea mai lungă).',
        'mod' => 'repo',
    ],
    [
        'nume' => 'robots.txt: locul regulii coșului din constructor',
        'cale' => '/robots.txt',
        'nou' => "Disallow: /cos/\nDisallow: /checkout\n",
        'vechi' => "Disallow: /cos/\nDisallow: /cos-cumparaturi\nDisallow: /checkout\n",
        'motiv' => 'perechea rândului de mai sus: în Bioscem regula stă imediat după regulile coșului.',
        'mod' => 'repo',
    ],
    [
        'nume' => 'Setări magazin → Sitemap XML: lista din pluginurile de pe site',
        'cale' => '/admin/settings/store',
        'nou' => "Sitemap-ul se construiește automat din baza de date, la fiecare cerere: produsele active, categoriile, "
            . "mărcile, etichetele, paginile publicate și articolele.\n                Nu mai trebuie generat după modificări.",
        'vechi' => "Sitemap-ul se construiește automat din baza de date, la fiecare cerere: produsele active,\n"
            . "                categoriile, mărcile, etichetele și paginile publicate. Nu mai trebuie generat după modificări.",
        'motiv' => 'explicația din admin își ia lista din pluginurile de pe site (fără blog nu mai pomenește articolele); '
            . 'pomenește și articolele, pe care sitemap-ul Bioscem le are deja. Sitemap-ul e același (comparat mai jos).',
        'mod' => 'repo',
    ],
];
$porturiBiovitality = [
];
$porturiVertij = [
];
$porturiGrafo = [
];
/**
 * Marcajele de port (App\Support\Dev::marcaj): nume „<sursa>:<NUME>” => pagina (sau
 * „/admin*”) și motivul. Blocul marcat se scoate din pagina codului curent; fiecare
 * nume trebuie găsit exact o dată pe pagina lui și pe nicio altă pagină.
 */
$marcajePort = [
];
$listeDeclarate = ['$deBioscem' => $deBioscem, '$porturiBiovitality' => $porturiBiovitality, '$porturiVertij' => $porturiVertij,
    '$porturiGrafo' => $porturiGrafo];
if ($fataDeCommit) {
    $listeDeclarate = ['$porturiBioscem' => $porturiBioscem] + $listeDeclarate;
}
// Intrările legate de un singur mod ('mod' => 'repo' sau 'commit') se verifică doar în el.
foreach ($listeDeclarate as $numeLista => $lista) {
    $listeDeclarate[$numeLista] = array_values(array_filter($lista,
        static fn (array $intrare): bool => !isset($intrare['mod']) || $intrare['mod'] === $modBaza));
}
/** Scoate din pagina curentă tot ce e declarat (marcaje, apoi texte); problemele în $probleme. */
$faraDeclarate = static function (string $cale, string $html, array &$gasite, array &$probleme) use ($listeDeclarate, $marcajePort): string {
    $html = diferente_marcaje($marcajePort, $cale, $html, $gasite, $probleme);
    return diferente_texte($listeDeclarate, $cale, $html, $gasite, $probleme);
};
/** [cale => tipurile JSON-LD așteptate]; orice altă pagină nu are date structurate. */
$jsonLdAsteptat = static function (string $cale): array {
    return match (true) {
        $cale === '/' => ['Organization', 'WebSite'],
        str_starts_with($cale, '/produs/') => ['Product', 'BreadcrumbList'],
        str_starts_with($cale, '/categorie/') => ['BreadcrumbList'],
        default => [],
    };
};

// Două sesiuni pe fiecare server: vizitator (cu coș) și admin; plus clientul logat.
$jaruri = [];
foreach (['curent' => $portCurent, 'baza' => $portBaza] as $eticheta => $port) {
    foreach (['viz', 'adm', 'cli', 'adm-magazin', 'adm-blog', 'adm-emailuri', 'anon'] as $rol) {
        $jaruri[$eticheta][$rol] = $dosarDiff . '/jar-' . $eticheta . '-' . $rol . '.txt';
        @unlink($jaruri[$eticheta][$rol]);
    }
}

$idMenta = $unu("SELECT id FROM products WHERE slug = 'ceai-de-menta'");
$slugBbd = $unu('SELECT slug FROM products WHERE bbd_enabled = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');
$slugPre = $unu('SELECT slug FROM products WHERE preorder_enabled = 1 ORDER BY id LIMIT 1');
$slugBlog = $unu('SELECT slug FROM blog_posts WHERE is_published = 1 ORDER BY id LIMIT 1');
$idClient = $unu("SELECT id FROM users WHERE email = 'andrei.exemplu@exemplu.ro'");
$nrComanda = $unu('SELECT order_number FROM orders WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');
$idPagina = $unu("SELECT id FROM pages WHERE slug = '' LIMIT 1");
$tokenLink = $unu('SELECT token FROM order_payment_links ORDER BY id LIMIT 1');
$idCupon = $unu('SELECT id FROM coupons ORDER BY id LIMIT 1');
$idSablonProdus = $unu('SELECT id FROM product_templates ORDER BY id LIMIT 1');
$dupaLogin = [];

foreach (['curent' => $portCurent, 'baza' => $portBaza] as $eticheta => $port) {
    $cerere($port, 'POST', '/cos/adauga/' . $idMenta, $jaruri[$eticheta]['viz'], ['quantity' => '2']);
    $cerere($port, 'POST', '/admin/login', $jaruri[$eticheta]['adm'], ['email' => $adminEmail, 'password' => $parola]);
    $cerere($port, 'POST', '/login', $jaruri[$eticheta]['cli'], ['email' => 'andrei.exemplu@exemplu.ro', 'password' => $parola]);
    // Adminii cu rol parțial: meniul lor are alte intrări, iar după login ajung pe prima pagină a rolului.
    foreach (['magazin', 'blog', 'emailuri'] as $rolAdmin) {
        $dupaLogin[$eticheta][$rolAdmin] = $cerere($port, 'POST', '/admin/login', $jaruri[$eticheta]['adm-' . $rolAdmin],
            ['email' => $rolAdmin . '@exemplu.ro', 'password' => $parola])['location'];
    }
    $cerere($port, 'POST', '/cos/adauga/' . $idMenta, $jaruri[$eticheta]['cli'], ['quantity' => '1']);
}

// [rol, cale]: paginile-cheie (cerute de sarcină) și vecinii lor apropiați.
$pagini = [
    ['viz', '/'],
    ['viz', '/magazin'],
    ['viz', '/categorie/ceaiuri'],
    ['viz', '/marca/livada-demo'],
    ['viz', '/produs/ceai-de-menta'],
    ['viz', '/produs/ceai-de-musetel'],
    ['viz', '/produs/' . $slugBbd],
    ['viz', '/produs/' . $slugPre],
    ['viz', '/cos'],
    ['viz', '/checkout'],
    ['viz', '/blog'],
    ['viz', '/blog/' . $slugBlog],
    ['viz', '/contact'],
    ['viz', '/termeni-si-conditii'],
    ['viz', '/pagina-care-nu-exista'],
    ['viz', '/login'],
    ['viz', '/register'],
    ['viz', '/api/cart/summary'],
    ['viz', '/api/search/products?q=ceai'],
    ['viz', '/api/shop/catalog'],
    ['cli', '/contul-meu'],
    ['cli', '/checkout'],
    ['cli', '/cos'],
    ['adm', '/admin'],
    ['adm', '/admin/orders'],
    ['adm', '/admin/orders?search=' . rawurlencode($nrComanda)],
    ['adm', '/admin/orders/trash'],
    ['adm', '/admin/products'],
    ['adm', '/admin/users'],
    ['adm', '/admin/users/' . $idClient],
    ['adm', '/admin/users/settings'],
    ['adm', '/admin/users/points'],
    ['adm', '/admin/coupons'],
    ['adm', '/admin/settings/store'],
    ['adm', '/admin/settings/payments'],
    ['adm', '/admin/settings/shipping'],
    ['adm', '/admin/settings/erp'],
    ['adm', '/admin/emails/automation'],
    ['adm', '/admin/emails/newsletters?tab=ecommerce'],
    ['adm', '/admin/emails/newsletters?tab=templates'],
    ['adm', '/admin/pages'],
    ['adm', '/admin/pages/' . $idPagina . '/edit'],
    ['adm', '/admin/emails/newsletters?tab=campaigns'],
    ['adm', '/admin/design'],
    ['adm', '/admin/settings/google'],
    ['adm', '/admin/blog/posts'],
    ['adm-magazin', '/admin'],
    ['adm-magazin', '/admin/orders'],
    ['adm-magazin', '/admin/settings/payments'],
    ['adm-blog', '/admin/blog/posts'],
    ['adm-blog', '/admin/blog/categories'],
    ['adm-emailuri', '/admin/emails/newsletters?tab=subscribers'],
    ['adm-emailuri', '/admin/emails/sender'],
    // Paginile atinse de ascunderea textelor pluginurilor (Etapa 1, pasul 3).
    ['viz', '/plata/' . $tokenLink],
    ['adm', '/admin/settings/floating-cart'],
    ['adm', '/admin/settings/admins'],
    ['adm', '/admin/settings/payments?tab=bt'],
    ['adm', '/admin/emails/test'],
    ['adm', '/admin/emails/newsletters?tab=history'],
    ['adm', '/admin/emails/newsletters?tab=contact_forms'],
    ['adm', '/admin/users?panel=import'],
    ['adm', '/admin/products/new'],
    ['adm', '/admin/coupons?coupon=' . $idCupon],
    ['adm', '/admin/promo-products'],
    // Paginile atinse de verificarea din Etapa 1, pasul 4 (editoare, texte ale nucleului, robots, API-uri).
    ['adm', '/admin/pages/new'],
    ['adm', '/admin/products/templates/' . $idSablonProdus . '/builder'],
    ['adm', '/admin/users/security'],
    ['adm', '/admin/emails/builder?type=newsletter'],
    ['viz', '/robots.txt'],
    ['viz', '/api/products/best-sellers?limit=3'],
];

// O pagină a site-ului (din bază, ca orice pagină scrisă de client) cu liste goale,
// de umplut din JavaScript, și un separator de meniu: trebuie să iasă neatinsă.
// Securitate formulare (/admin/users/security) ordonează jurnalele de înregistrare și de
// checkout, unite, după (created_at, id): două rânduri din tabele diferite cu aceeași secundă
// și același id (le scriu testele anterioare, câte o cerere în fiecare, în aceeași secundă)
// nu au o ordine fixă, nici în Bioscem, nici aici. Rândurile de checkout care se suprapun așa
// se scot din baza de test înainte de comparare, ca pagina să se compare tot octet cu octet.
$suprapuse = (int) $db->exec('DELETE c FROM checkout_submit_logs c
    JOIN register_submit_logs r ON r.id = c.id AND r.created_at = c.created_at');
if ($suprapuse > 0) {
    echo '  INFO  ', $suprapuse, ' rânduri de checkout din jurnalul anti-robot (scrise de teste) aveau aceeași secundă și '
        . "același id ca o înregistrare; scoase din baza de test (ordinea lor nu e fixă nici în Bioscem).\n";
}

$paginaListe = 'test-liste-goale-' . getmypid();
$db->prepare('INSERT INTO pages (title, slug, html_content, css_content, js_content, is_published, created_at, updated_at)
    VALUES (:title, :slug, :html, \'\', \'\', 1, NOW(), NOW())')->execute([
    'title' => 'Liste goale',
    'slug' => $paginaListe,
    'html' => '<div class="slider"><ul class="slider-dots" data-dots></ul><ol id="pasi"></ol><ul class="lista">'
        . '<li class="placeholder"></li><li class="divider"></li><li><a href="/contact">Contact</a></li></ul></div>',
]);
$pagini[] = ['viz', '/' . $paginaListe];

// Doar față de codul Bioscem (care are deja livrarea 1 SEO): harta site-ului, adresa unică
// și fiecare regulă din harta adreselor vechi a site-ului (pachetul lui din site-uri/), cerute
// de un vizitator nou, fără sesiune. Rezultatul lor se rezumă într-o linie, dacă e identic.
if (!$fataDeCommit) {
    $pagini[] = ['anon', '/sitemap.xml'];
    foreach (['/Magazin', '/produs/ceai-de-menta/', '/categorie/Ceaiuri', '/produs/ceai-de-ment', '/blog/', '/magazin?utm_source=test'] as $cale) {
        $pagini[] = ['anon', $cale, 'adresa unică'];
    }
    $harta = \App\Support\DateSite::json('redirecturi.json');
    foreach ((array) ($harta['exacte'] ?? []) as $regula) {
        $pagini[] = ['anon', (string) ($regula[0] ?? '/'), 'harta adreselor vechi'];
    }
    foreach ((array) ($harta['prefixe'] ?? []) as $regula) {
        $pagini[] = ['anon', rtrim((string) ($regula[0] ?? '/'), '/') . '/test', 'harta adreselor vechi'];
    }
}

$identice = 0;
$diferite = [];
$diferenteSeoAsteptate = [];
$diferentePorturi = [];
$grupuriIdentice = [];
foreach ($pagini as $pagina) {
    [$rol, $cale] = $pagina;
    $grup = (string) ($pagina[2] ?? '');
    $r = [];
    foreach (['curent' => $portCurent, 'baza' => $portBaza] as $eticheta => $port) {
        $r[$eticheta] = $cerere($port, 'GET', $cale, $jaruri[$eticheta][$rol]);
    }
    $gasite = [];
    $porturiGasite = [];
    $problemePorturi = [];
    $a = $normalizeaza($r['baza']['corp']);
    $corpCurent = $fataDeCommit ? $faraSeo($cale, $r['curent']['corp'], $gasite) : $r['curent']['corp'];
    $b = $normalizeaza($faraDeclarate($cale, $corpCurent, $porturiGasite, $problemePorturi));
    $eticheta = str_pad($rol, 13) . $cale;
    // Diferențele SEO așteptate (doar față de un commit dinaintea SEO): datele structurate
    // exact unde trebuie, robots.txt cu regulile noi.
    $tipuriJsonLd = (array) ($gasite['JSON-LD'] ?? []);
    unset($gasite['JSON-LD']);
    $problemeSeo = [];
    if ($fataDeCommit && $rol === 'viz' && $tipuriJsonLd !== $jsonLdAsteptat($cale)) {
        $problemeSeo[] = 'JSON-LD ' . json_encode($tipuriJsonLd) . ', așteptat ' . json_encode($jsonLdAsteptat($cale));
    } elseif ($fataDeCommit && $rol !== 'viz' && $tipuriJsonLd !== []) {
        $problemeSeo[] = 'JSON-LD în admin: ' . json_encode($tipuriJsonLd);
    }
    if ($fataDeCommit && $cale === '/robots.txt' && ($gasite['robots: /cos exact'] ?? 0) !== 1) {
        $problemeSeo[] = 'robots.txt fără regulile exacte pentru /cos';
    }
    $seoGasit = array_filter($gasite);
    $rezumatSeo = ($tipuriJsonLd !== [] ? 'JSON-LD ' . implode('+', $tipuriJsonLd) . ($seoGasit !== [] ? '; ' : '') : '')
        . implode('; ', array_map(static fn ($k, $v) => $k . ' ' . $v, array_keys($seoGasit), $seoGasit));
    if ($rezumatSeo !== '') {
        $diferenteSeoAsteptate[$eticheta] = $rezumatSeo;
    }
    $rezumatPorturi = implode('; ', array_keys($porturiGasite));
    if ($rezumatPorturi !== '') {
        $diferentePorturi[$eticheta] = $rezumatPorturi;
    }
    // Redirecționările absolute (adresa unică) poartă portul serverului.
    $locatieBaza = str_replace('127.0.0.1:' . $portBaza, '127.0.0.1:' . $portCurent, $r['baza']['location']);
    if ($problemeSeo === [] && $problemePorturi === [] && $r['baza']['cod'] === $r['curent']['cod']
        && $locatieBaza === $r['curent']['location'] && $a === $b) {
        $identice++;
        if ($grup !== '') {
            $grupuriIdentice[$grup][$r['curent']['cod']] = ($grupuriIdentice[$grup][$r['curent']['cod']] ?? 0) + 1;
            continue;
        }
        echo '  OK    ', $r['curent']['cod'], '  ', $eticheta, $rezumatSeo !== '' ? '  (SEO: ' . $rezumatSeo . ')' : '',
            $rezumatPorturi !== '' ? '  (' . $rezumatPorturi . ')' : '', "\n";
        continue;
    }
    if ($problemeSeo !== []) {
        echo '  SEO   ', $eticheta, ': ', implode('; ', $problemeSeo), "\n";
    }
    if ($problemePorturi !== []) {
        echo '  PORT  ', $eticheta, ': ', implode('; ', $problemePorturi), "\n";
    }
    $nume = preg_replace('/[^a-z0-9]+/i', '_', trim($rol . $cale, '/')) ?: 'pagina';
    file_put_contents($dosarDiff . '/' . $nume . '.baza.html', $a);
    file_put_contents($dosarDiff . '/' . $nume . '.curent.html', $b);
    $diff = (string) shell_exec('diff -u ' . escapeshellarg($dosarDiff . '/' . $nume . '.baza.html') . ' '
        . escapeshellarg($dosarDiff . '/' . $nume . '.curent.html') . ' 2>&1');
    file_put_contents($dosarDiff . '/' . $nume . '.diff', $diff);
    $linii = substr_count($diff, "\n- ") + substr_count($diff, "\n+ ") + preg_match_all('/^[-+](?![-+])/m', $diff);
    $diferite[] = $eticheta;
    echo '  DIFERĂ ', $r['baza']['cod'], '→', $r['curent']['cod'], '  ', $eticheta,
        $locatieBaza !== $r['curent']['location'] ? '  (Location: ' . $locatieBaza . ' → ' . $r['curent']['location'] . ')' : '',
        '  (', $dosarDiff, '/', $nume, '.diff)', "\n";
}
foreach ($grupuriIdentice as $grup => $coduri) {
    ksort($coduri);
    echo '  OK    ', str_pad('anon', 13), $grup, ': ', array_sum($coduri), ' adrese identice (',
        implode(', ', array_map(static fn ($cod, $n) => $n . ' × ' . $cod, array_keys($coduri), $coduri)), ")\n";
}
// Unde ajunge fiecare administrator cu rol parțial după login.
foreach ($dupaLogin['baza'] ?? [] as $rolAdmin => $locatieBaza) {
    $locatieCurenta = $dupaLogin['curent'][$rolAdmin] ?? '';
    $eticheta = str_pad('login', 13) . $rolAdmin . '@exemplu.ro → ' . $locatieCurenta;
    if ($locatieBaza === $locatieCurenta) {
        $identice++;
        echo '  OK    302  ', $eticheta, "\n";
    } else {
        $diferite[] = $eticheta;
        echo '  DIFERĂ 302  ', $eticheta, ' (baza: ', $locatieBaza, ")\n";
    }
}
foreach (glob($dosarDiff . '/jar-*') ?: [] as $jar) {
    @unlink($jar);
}
$db->prepare('DELETE FROM pages WHERE slug = :slug')->execute(['slug' => $paginaListe]);
$declarate = [];
foreach ($listeDeclarate as $numeLista => $lista) {
    if ($lista !== []) {
        $declarate[] = $numeLista . ' ' . count($lista);
    }
}
if ($marcajePort !== []) {
    $declarate[] = count($marcajePort) . ' marcaje de port';
}
echo "\n", 'Față de ', $commitBaza, ': ', $identice, ' pagini identice, ', count($diferite), ' diferite; ',
    $fataDeCommit ? count($diferenteSeoAsteptate) . ' pagini cu diferențele SEO așteptate (livrarea 1 SEO), ' : '',
    count($diferentePorturi), ' pagini cu diferențe declarate (', $declarate === [] ? 'nicio declarație' : implode(', ', $declarate),
    "), scoase explicit înainte de comparare.\n";
exit($diferite === [] ? 0 : 1);
