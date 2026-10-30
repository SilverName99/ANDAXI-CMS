<?php

declare(strict_types=1);

/**
 * Partea PHP a lui tools/dev/compara-cu-baza.sh (nu se rulează singur):
 * aceleași cereri spre serverul curent și spre copia de bază, apoi compararea
 * răspunsurilor normalizate.
 *
 *   php compara-cu-baza.php <port-baza> <eticheta-bazei> <dosarul-copiei-de-baza> <repo|commit> [<port-curent>] [<site-ul-bazei>]
 *   php compara-cu-baza.php --autotest     # doar verificarea mecanismului de diferențe (fără servere)
 *
 * Se compară, pentru fiecare cerere: codul de stare, Location, anteturile răspunsului
 * (diferente_antete: tot ce trimite codul, fără Date, Content-Length și antetul gărzii
 * de dezvoltare; valoarea cookie-urilor, ETag și Last-Modified mascate) și corpul.
 *
 * Ce se scoate din corp înainte de comparare (diferă la fiecare cerere, nu ține de cod):
 * tokenurile formularelor (checkout, înregistrare, CSRF), momentul randării,
 * versiunea fișierelor CSS/JS (`?v=` = data fișierului pe disc), identificatorii
 * aleși la întâmplare ai formularelor, ora curentă.
 *
 * Baza „repo” (implicit: codul Bioscem însuși, la ultimul commit revizuit): nimic
 * altceva; livrarea 1 SEO și porturile din Bioscem sunt deja în codul de bază, deci
 * trebuie să iasă octet cu octet. Tot doar în acest mod: harta site-ului, adresa unică,
 * fiecare regulă din harta adreselor vechi (din pachetul site-ului și din harta scrisă
 * în codul de bază, care trebuie să fie aceeași listă) și, față de Bioscem, salvările
 * din admin (aceeași cerere pe ambele servere, pe rând, cu baza pusă la loc între ele;
 * tools/dev/lib/salvari-admin.php).
 *
 * Baza „commit” (un commit din acest repo, de exemplu f5ae5ea, dinaintea pluginurilor;
 * doar pentru istoric): în plus, doar din pagina codului curent, diferențele SEO ale
 * livrării 1 (docs/SEO.md), scrise mai jos una câte una ($faraSeo): ce s-a scos se
 * afișează pe pagină, iar datele structurate trebuie să apară exact unde sunt
 * așteptate ($jsonLdAsteptat). La fel textele schimbărilor portate din Bioscem după
 * acel commit ($porturiBioscem, pe commit-uri Bioscem): fiecare de exact câte ori e
 * declarat (implicit o dată) pe pagina (sau paginile) ei și pe nicio alta.
 *
 * În ambele moduri, tot doar din pagina codului curent: diferențele declarate
 * explicit, cu pagina și motivul lor ($deBioscem, $porturiBiovitality,
 * $porturiVertij, $porturiGrafo) și blocurile marcate cu <!--port:…--> ($marcajePort).
 * Plus paginile care în codul de bază cad și aici merg ($coduriDeBioscem): trec doar cu
 * exact codurile declarate și cu textul declarat pe pagina curentă.
 * Contractul lor e în tools/dev/lib/diferente-asteptate.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/lib/diferente-asteptate.php';
require_once __DIR__ . '/lib/normalizeaza-html.php';
if (in_array('--autotest', $argv, true)) {
    $esecuri = diferente_autotest();
    foreach ($esecuri as $esec) {
        echo '  EȘEC  ', $esec, "\n";
    }
    echo $esecuri === [] ? "Mecanismul diferențelor așteptate (texte, marcaje de port, coduri de stare, anteturi): OK\n" : count($esecuri) . " cazuri greșite.\n";
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
// Al cărui site e codul de bază (doar în modul „repo”: bioscem, biovitality …; gol altfel).
$siteBaza = (string) ($argv[6] ?? '');
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

/**
 * Baza în forma Bioscem pe durata comparației (tools/dev/lib/forma-bioscem.php): setările înghețate ale Bioscem
 * (`site-uri/bioscem/setari-inghetate.json`, cu valorile private din `privat/`, scris local de generator) și un antet ca
 * al Bioscem, cu sertarul de meniu. Codul Bioscem le are scrise în cod (sau în implicitele lui), codul curent le ia din
 * bază și din pachet (regula R1 din port-biovitality.md; din WP-14, baza cms_dev_bioscem le are de la instalare). Pe baza
 * de dezvoltare pasul de activare al ERP-ului a scris data instalării, deci fără înghețare Setări → ERP ANDAXI ar număra
 * separat comenzile demo de dinainte de prag. La final, și la o oprire, fiecare setare revine cum era (și o cheie lipsă).
 */
require_once __DIR__ . '/lib/forma-bioscem.php';
try {
    $setariDePusLaLoc = forma_bioscem_pune($db, $radacina);
} catch (Throwable $e) {
    fwrite(STDERR, 'Baza în forma Bioscem nu se poate pune: ' . $e->getMessage() . "\n");
    exit(1);
}
register_shutdown_function(static function () use ($db, $setariDePusLaLoc): void {
    forma_bioscem_restaureaza($db, $setariDePusLaLoc);
});
echo '  INFO  baza în forma Bioscem, pe durata comparației (tools/dev/lib/forma-bioscem.php): ', count($setariDePusLaLoc),
    ' setări (înghețarea și antetul cu sertarul de meniu)', "\n";
// Un produs fără descriere scurtă printre produsele similare ale unui produs fără șablon (/produs/ceai-de-musetel) și în
// liste: cardurile scriu atunci descrierea implicită (Bioscem: „Supliment natural”, din înghețare; WP-16, DB-14). Datele
// demo au descrieri peste tot, deci una se golește pe durata comparației și revine la final (și la o oprire).
$slugFaraDescriere = 'ceai-de-ghimbir';
$descriereScurtaInainte = $unu('SELECT short_description FROM products WHERE slug = ' . $db->quote($slugFaraDescriere));
$db->prepare("UPDATE products SET short_description = '' WHERE slug = ?")->execute([$slugFaraDescriere]);
register_shutdown_function(static function () use ($db, $slugFaraDescriere, $descriereScurtaInainte): void {
    $db->prepare('UPDATE products SET short_description = ? WHERE slug = ?')->execute([$descriereScurtaInainte, $slugFaraDescriere]);
});

// Numele site-ului din titlurile adminului (WP-16, $deBioscem): cel pe care îl vede codul curent în baza de acum.
$numeSiteAdmin = \App\Support\Brand::numeSite(\App\Support\Settings::all($db));
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
    if ($metoda === 'HEAD') {
        // Fără corp: altfel curl așteaptă corpul anunțat de Content-Length.
        curl_setopt($c, CURLOPT_NOBODY, true);
    }
    $raspuns = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $marimeAntet = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    $antet = substr($raspuns, 0, $marimeAntet);
    $location = preg_match('/^Location:\s*(\S+)/mi', $antet, $m) === 1 ? $m[1] : '';
    return ['cod' => $cod, 'corp' => substr($raspuns, $marimeAntet), 'location' => $location, 'antete' => $antet];
};

// Anteturile răspunsului se compară și ele (tools/dev/lib/diferente-asteptate.php → diferente_antete).
$normalizeazaAntete = static fn (string $antet): array => diferente_antete($antet, $portBaza, $portCurent);

/**
 * Ce diferă la fiecare cerere, indiferent de cod (tools/dev/lib/normalizeaza-html.php, aceeași normalizare ca amprenta
 * paginilor din tools/verificare/amprenta-site.php): portul codului de bază și, în instrucțiunile de cron din admin,
 * dosarul copiei lui devin ale codului curent.
 */
$inlocuiriBaza = ['127.0.0.1:' . $portBaza => '127.0.0.1:' . $portCurent];
if ($dosarBaza !== '') {
    $inlocuiriBaza[$dosarBaza] = $radacina;
}
$normalizeaza = static fn (string $html): string => normalizeaza_html($html, [
    'fara_import_wordpress' => $faraUnealtaInterna,
    'inlocuiri' => $inlocuiriBaza,
]);

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
// Datele demo pe care le cer paginile comparate și declarațiile de mai jos (slugurile, id-urile).
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
// WP-12 (C6): al patrulea editor de cod, al șabloanelor de blog. Datele demo n-au niciun șablon de blog, deci cazul de
// salvare „editorul șabloanelor de blog” face unul, cu acest id (același pe ambele servere), și îl scoate după.
$idSablonBlogEditor = (int) $unu('SELECT COALESCE(MAX(id), 0) FROM blog_templates') + 9000;
// Pagina din bază cu liste goale (o face scriptul mai jos, la cereri).
$paginaListe = 'test-liste-goale-' . getmypid();
// WP-12 (X1b): postarea demo importată cu categoria doar ca text (tools/dev/date-demo.php) și o a doua postare, căreia
// cazul de salvare îi pune drept categorie doar numele unei categorii din listă (declarațiile din $deBioscem).
$idPostareDoarText = (int) $unu("SELECT id FROM blog_posts WHERE slug = 'sfaturi-vechi-importate' AND deleted_at IS NULL");
$categorieDoarTextDemo = $unu('SELECT category FROM blog_posts WHERE id = ' . $idPostareDoarText);
$idPostareTextCaNume = (int) $unu("SELECT id FROM blog_posts WHERE deleted_at IS NULL AND slug <> 'sfaturi-vechi-importate' ORDER BY id LIMIT 1 OFFSET 1");
// O categorie nouă doar pentru cazul acela (id fix, ștearsă la final): bifa ei apare doar în editorul postării lui, nu și
// în cazul „blog: editorul bifează toate categoriile postării”, unde sunt bifate toate categoriile demo.
$idMaxCategoriiBlog = (int) $unu('SELECT COALESCE(MAX(id), 0) FROM blog_categories');
$idCategorieDupaNume = $idMaxCategoriiBlog + 1;
$numeCategorieDupaNume = 'Categorie importată (compara)';

/**
 * Doar față de un commit din acest repo (baza „commit”, istoricul: --baza=f5ae5ea): schimbările din
 * Bioscem portate după acel commit, altele decât livrarea 1 SEO ($faraSeo), fiecare cu commit-ul
 * Bioscem din care vine. Textul nou, exact cum îl scrie codul, pagina (sau paginile) pe care trebuie
 * să apară, de câte ori („ori”, implicit o dată) și ce era în locul lui. Față de Bioscem (baza „repo”,
 * poarta G-cmp) nu se aplică: codul Bioscem le are deja, deci acolo un port nu adaugă nimic aici (se
 * mută doar „ultimul commit revizuit” în docs/de-portat.md). Ca istoricul să rămână o dovadă (poarta
 * G-cmp-istoric, și la WP-R), fiecare port Bioscem își trece aici diferențele față de f5ae5ea, pe
 * commit-uri, în același commit cu portul; nimic nu se scoate cu un tipar general.
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
// WP-R (20 oct 2026, CMS 43bdccc): livrarea 2 SEO și avertismentul pe produs, Bioscem 6c8c789 … 4254ca4. Declarate pentru
// istoric la WP-R din 21 oct (Restanțe), pe paginile datelor demo (servite cu pachetul inventat, tools/dev/site-demo/).
$porturiBioscemLivrarea2 = (static function () use ($slugBbd, $slugPre, $slugBlog, $paginaListe, $nrComanda, $idPagina, $idSablonProdus, $unu): array {
    $intrari = [];
    $produse = ['/produs/ceai-de-menta', '/produs/ceai-de-musetel', '/produs/' . $slugBbd, '/produs/' . $slugPre];
    $listeCatalog = ['/magazin', '/categorie/ceaiuri', '/marca/livada-demo'];
    $editoarePagini = ['/admin/pages/new', '/admin/pages/' . $idPagina . '/edit'];
    $h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
    // Un text cum apare în previzualizarea din editorul de pagini (șir JSON într-un <script>, aceleași opțiuni ca $faraSeo).
    $inJson = static fn (string $text): string => substr((string) json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG
        | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), 1, -1);
    // Categoriile demo: [nume, slug] (filtrul catalogului le arată pe toate, în ordinea numelor).
    $categorii = [];
    foreach (explode("\n", $unu("SELECT GROUP_CONCAT(CONCAT(name, '\t', slug) ORDER BY name SEPARATOR '\n') FROM product_categories")) as $rand) {
        if ($rand !== '') {
            $categorii[] = explode("\t", $rand, 2);
        }
    }
    $produs = static function (string $cale) use ($unu): array {
        $slug = substr($cale, strlen('/produs/'));
        $stmt = $unu("SELECT CONCAT(p.name, '\t', COALESCE(p.brand, ''), '\t', COALESCE(c.name, ''), '\t', COALESCE(c.slug, ''), '\t',
            IF(p.product_template_id IS NULL, 0, 1)) FROM products p LEFT JOIN product_categories c ON c.id = p.category_id
            WHERE p.slug = " . var_export($slug, true));
        return explode("\t", $stmt);
    };

    // Bioscem 7455b77 (A7): numele site-ului și limba pentru Facebook și WhatsApp, pe fiecare pagină publică cu antet.
    $intrari[] = [
        'nume' => 'og:site_name și og:locale în <head>',
        'cale' => array_merge(['/', '/blog', '/blog/' . $slugBlog, '/contact', '/termeni-si-conditii', '/pagina-care-nu-exista', '/' . $paginaListe,
            '/acorduri-gdpr'],
            $listeCatalog, $produse),
        'nou' => "    <meta property=\"og:site_name\" content=\"Firma Demo\">\n    <meta property=\"og:locale\" content=\"ro_RO\">\n",
        'vechi' => '',
        'motiv' => 'Bioscem 7455b77 (livrarea 2 SEO, A7): numele site-ului (numele magazinului) și limba, pentru previzualizarea '
            . 'linkului pe Facebook și WhatsApp (ImagineDistribuire, views/layout.php)',
    ];

    // Bioscem 93d803f (A6): o singură regulă pentru titluri și descrieri (SeoMeta). Descrierea listelor și a produselor (în
    // <head>, og:description, twitter:description) și marca în titlul produsului (<title>, og:title, twitter:title).
    $descrieri = [
        '/magazin' => 'Magazinul Firma Demo: 39 de produse în 7 categorii, printre care Ceaiuri, Suplimente, Cosmetice naturale și altele, '
            . 'cu prețuri de la 9,90 lei.',
        '/categorie/ceaiuri' => 'Ceaiuri: 7 produse de la Livada Demo și Natura Exemplu, cu prețuri de la 13,90 lei. Comandă online pe Firma Demo.',
        '/marca/livada-demo' => 'Livada Demo: 11 produse din categoriile Ceaiuri, Uleiuri, Superalimente și altele, cu prețuri de la 9,90 lei. '
            . 'Comandă online pe Firma Demo.',
    ];
    foreach ($produse as $cale) {
        [$nume, $marca] = $produs($cale);
        // Din primul paragraf al descrierii produsului (datele demo: „<nume> este un produs fictiv …”).
        $descrieri[$cale] = $nume . ' este un produs fictiv din catalogul demo al Firma Demo SRL. Textul servește doar la verificarea paginilor.';
        $intrari[] = [
            'nume' => 'titlul produsului cu marca (' . $cale . ')',
            'cale' => $cale,
            'nou' => $h($nume . ' – ' . $marca . ' | Firma Demo'),
            'vechi' => $h($nume . ' | Firma Demo'),
            'motiv' => 'Bioscem 93d803f (livrarea 2 SEO, A6): titlul produsului are și marca, în <title>, og:title și twitter:title',
            'ori' => 3,
        ];
    }
    foreach ($descrieri as $cale => $descriere) {
        foreach (['meta name="description"', 'meta property="og:description"', 'meta name="twitter:description"'] as $eticheta) {
            $intrari[] = [
                'nume' => '<' . $eticheta . '> (' . $cale . ')',
                'cale' => $cale,
                'nou' => '            <' . $eticheta . ' content="' . $h($descriere) . "\">\n",
                'vechi' => '',
                'motiv' => 'Bioscem 93d803f (livrarea 2 SEO, A6): descrierea paginii, din aceeași regulă pentru toate listele și produsele',
            ];
        }
    }
    $intrari[] = [
        'nume' => 'Setări magazin → SEO: numele magazinului în titluri',
        'cale' => '/admin/settings/store',
        'nou' => <<<'HTML'
                                    <h3 style="margin-top:0;">Numele magazinului în titluri</h3>
            <p style="margin:0 0 10px;color:#64748b;">
                Apare la finalul titlurilor din Google și din browser (ex. „Magazin | Firma Demo”)
                și ca nume al firmei în datele pentru Google. Gol = „Firma Demo din pachet”.
            </p>
            <form method="post" action="/admin/settings/store" style="margin-bottom:22px;">
                <input type="hidden" name="action" value="save_store_name">
                <div class="field" style="max-width:680px;">
                    <label for="store_name">Nume magazin</label>
                    <input id="store_name" type="text" name="store_name" maxlength="60" value="Firma Demo" placeholder="Firma Demo din pachet">
                </div>
                <div style="margin-top:10px;">
                    <button class="btn" type="submit">Salvează numele</button>
                </div>
            </form>

            <h3 style="margin-top:0;">SEO Google (global)</h3>
HTML,
        'vechi' => '                        <h3 style="margin-top:0;">SEO Google (global)</h3>',
        'motiv' => 'Bioscem 93d803f (livrarea 2 SEO, A6): numele magazinului din titluri se scrie în admin (store_name)',
    ];
    $intrari[] = [
        'nume' => 'Setări magazin → SEO: prima pagină are întâietate',
        'cale' => '/admin/settings/store',
        'nou' => "SEO specific.\n                Titlul și descrierea pentru Homepage au întâietate pe prima pagină.\n",
        'vechi' => "SEO specific.\n",
        'motiv' => 'Bioscem 93d803f (livrarea 2 SEO, A6): explicația regulii unice (titlul și descrierea primei pagini câștigă)',
    ];

    // Bioscem 6c8c789 (A3): categoriile pe /categorie/{slug}. Linkurile din paginile din bază (LinkuriCategorii), din
    // pagina produsului și din editorul șablonului, sortarea paginilor de categorie și de marcă, filtrul catalogului.
    $legaturiAcasa = [['Suplimente', 'suplimente'], ['Ceaiuri', 'ceaiuri'], ['Miere', 'miere'], ['Superalimente', 'superalimente'],
        ['Cosmetice naturale', 'cosmetice-naturale'], ['Uleiuri', 'uleiuri']];
    foreach ($legaturiAcasa as [$numeCategorie, $slugCategorie]) {
        $intrari[] = [
            'nume' => 'prima pagină: linkul spre categoria ' . $numeCategorie,
            'cale' => '/',
            'nou' => '<a class="bs-need" href="/categorie/' . $slugCategorie . '">',
            'vechi' => '<a class="bs-need" href="/magazin?category=' . rawurlencode($numeCategorie) . '">',
            'motiv' => 'Bioscem 6c8c789 (livrarea 2 SEO, A3): un link vechi /magazin?category= din conținutul unei pagini duce direct la '
                . 'pagina categoriei (LinkuriCategorii)',
        ];
    }
    foreach ($produse as $cale) {
        [, , $numeCategorie, $slugCategorie, $cuSablon] = $produs($cale);
        if ($cuSablon !== '1') {
            continue; // fără șablon (product.php): fără linkul categoriei
        }
        $intrari[] = [
            'nume' => 'pagina produsului: linkul categoriei (' . $cale . ')',
            'cale' => $cale,
            'nou' => '<p class="demo-produs__categorie"><a href="/categorie/' . $slugCategorie . '">',
            'vechi' => '<p class="demo-produs__categorie"><a href="/magazin?category=' . rawurlencode($numeCategorie) . '">',
            'motiv' => 'Bioscem 6c8c789 (livrarea 2 SEO, A3): {{product_category_url}} e pagina categoriei',
        ];
    }
    $intrari[] = [
        'nume' => 'editorul șablonului de produs: exemplul pentru {{product_category_url}}',
        'cale' => '/admin/products/templates/' . $idSablonProdus . '/builder',
        'nou' => "'{{product_category_url}}': '/categorie/suplimente',",
        'vechi' => "'{{product_category_url}}': '/magazin?category=Suplimente',",
        'motiv' => 'Bioscem 6c8c789 (livrarea 2 SEO, A3): previzualizarea șablonului (views/admin/product-template-editor.php) arată '
            . 'adresa nouă a categoriei',
    ];
    foreach (['alpha_asc', 'featured', 'price_asc', 'price_desc'] as $sortare) {
        $intrari[] = [
            'nume' => 'pagina categoriei: sortarea ' . $sortare,
            'cale' => '/categorie/ceaiuri',
            'nou' => 'href="/categorie/ceaiuri?sort=' . $sortare . '"',
            'vechi' => 'href="/magazin?sort=' . $sortare . '&categorie=Ceaiuri"',
            'motiv' => 'Bioscem 6c8c789 (livrarea 2 SEO, A3): sortarea rămâne pe pagina categoriei',
        ];
        $intrari[] = [
            'nume' => 'pagina mărcii: sortarea ' . $sortare,
            'cale' => '/marca/livada-demo',
            'nou' => 'href="/marca/livada-demo?sort=' . $sortare . '"',
            'vechi' => 'href="/magazin?sort=' . $sortare . '"',
            'motiv' => 'Bioscem 6c8c789 (livrarea 2 SEO, A3): sortarea rămâne pe pagina mărcii',
        ];
    }
    foreach (array_merge([['', '/magazin']], array_map(static fn (array $c): array => [$c[0], '/categorie/' . $c[1]], $categorii)) as [$valoare, $adresa]) {
        $intrari[] = [
            'nume' => 'filtrul catalogului: adresa categoriei „' . ($valoare !== '' ? $valoare : 'Toate') . '”',
            'cale' => $listeCatalog,
            'nou' => 'data-filter-value="' . $h($valoare) . '" data-filter-url="' . $adresa . '" ',
            'vechi' => 'data-filter-value="' . $h($valoare) . '" ',
            'motiv' => 'Bioscem 6c8c789 (livrarea 2 SEO, A3): o categorie aleasă în filtru deschide pagina ei',
        ];
    }
    $jsFiltru = <<<'JS'
      // Categoria are pagina ei (/categorie/…); se păstrează doar sortarea aleasă.
      const target = String(input.getAttribute('data-filter-url') || '');
      if (target !== '') {
        const next = new URL(target, window.location.origin);
        const sort = new URL(window.location.href).searchParams.get('sort');
        if (sort) {
          next.searchParams.set('sort', sort);
        }
        window.location.href = next.toString();
        return;
      }

JS;
    $intrari[] = [
        'nume' => 'filtrul catalogului: scriptul care deschide pagina categoriei',
        'cale' => $listeCatalog,
        'nou' => $jsFiltru,
        'vechi' => '',
        'motiv' => 'Bioscem 6c8c789 (livrarea 2 SEO, A3): pagina categoriei în locul parametrului categorie, cu sortarea aleasă',
    ];
    $intrari[] = [
        'nume' => 'editorul de pagini: previzualizarea catalogului, adresa „Toate”',
        'cale' => $editoarePagini,
        'nou' => $inJson('data-filter-value="" data-filter-url="/magazin" '),
        'vechi' => $inJson('data-filter-value="" '),
        'motiv' => 'Bioscem 6c8c789 (livrarea 2 SEO, A3): aceeași secțiune a catalogului, în previzualizarea editorului',
    ];
    $intrari[] = [
        'nume' => 'editorul de pagini: previzualizarea catalogului, scriptul filtrului',
        'cale' => $editoarePagini,
        'nou' => $inJson($jsFiltru),
        'vechi' => '',
        'motiv' => 'Bioscem 6c8c789 (livrarea 2 SEO, A3): aceeași secțiune a catalogului, în previzualizarea editorului',
    ];

    // Bioscem d277727 (A13): fluxul Google Merchant Center, deschis roboților.
    $intrari[] = [
        'nume' => 'robots.txt: fluxul Merchant Center',
        'cale' => '/robots.txt',
        'nou' => "User-agent: *\nAllow: /feed/google-merchant.xml\n",
        'vechi' => "User-agent: *\n",
        'motiv' => 'Bioscem d277727 (livrarea 2 SEO, A13): /feed/google-merchant.xml (FluxGoogleMerchant), permis în robots.txt',
    ];

    // Avertismentul pe produs, Bioscem 0582ce9 … 4254ca4: câmpurile din Admin → Produse (333ba9e), fereastra la adăugarea în
    // coș (b874cf0), confirmarea pe comandă (5f2e483, 233cc7d), blocul din email (fa76c4f). Nicio dată demo nu are avertisment.
    $intrari[] = [
        'nume' => 'Admin → Produse: cele trei câmpuri ale avertismentului',
        'cale' => '/admin/products',
        'nou' => <<<'HTML'
                    <section class="panel product-editor-card">
                        <div class="product-editor-card__head">
                            <h4>Avertisment la coș și la finalizarea comenzii</h4>
                            <p>De ex. pentru produsele refrigerate. Activ doar dacă „Text avertisment” e completat.</p>
                        </div>
                        <div class="product-editor-grid">
                            <div class="field full">
                                <label for="p_avertisment_titlu">Titlu avertisment</label>
                                <input type="text" name="avertisment_titlu" id="p_avertisment_titlu" maxlength="200" placeholder="ex. ATENȚIE – PRODUS REFRIGERAT">
                            </div>
                            <div class="field full">
                                <label for="p_avertisment_text">Text avertisment</label>
                                <textarea name="avertisment_text" id="p_avertisment_text" rows="6" maxlength="8000" placeholder="Textul pe care îl vede clientul. Un rând gol = paragraf nou."></textarea>
                            </div>
                            <div class="field full">
                                <label for="p_avertisment_eticheta">Text bifă la finalizarea comenzii</label>
                                <input type="text" name="avertisment_eticheta" id="p_avertisment_eticheta" maxlength="300" placeholder="ex. Am citit și am înțeles condițiile pentru produsul refrigerat">
                                <small class="muted">
                                    Text simplu, fără HTML (un rând gol în text = paragraf nou). Cu text completat: la adăugarea în coș
                                    apare o fereastră cu titlul și textul, iar la finalizarea comenzii o casetă deasupra butonului și
                                    bifa obligatorie cu acest text (gol = „Am citit și am înțeles avertismentul de mai sus.”).
                                    Confirmarea rămâne pe comandă și apare în emailul de confirmare.
                                </small>
                            </div>
                        </div>
                    </section>


HTML,
        'vechi' => '',
        'motiv' => 'Bioscem 333ba9e (avertismentul pe produs): titlul, textul și eticheta bifei, în editorul produsului',
    ];
    $intrari[] = [
        'nume' => 'Admin → Produse: câmpurile avertismentului completate la deschiderea produsului',
        'cale' => '/admin/products',
        'nou' => <<<'JS'
            // Avertismentul (fereastra din coș + bifa de la comandă).
            [['p_avertisment_titlu', 'avertisment_titlu'], ['p_avertisment_text', 'avertisment_text'], ['p_avertisment_eticheta', 'avertisment_eticheta']]
                .forEach(([idCamp, cheie]) => {
                    const camp = document.getElementById(idCamp);
                    if (camp instanceof HTMLInputElement || camp instanceof HTMLTextAreaElement) {
                        camp.value = String(product[cheie] || '');
                    }
                });

JS,
        'vechi' => '',
        'motiv' => 'Bioscem 333ba9e (avertismentul pe produs): editorul pune în câmpuri avertismentul salvat',
    ];
    $intrari[] = [
        'nume' => 'Admin → Produse: avertismentul (gol) în datele fiecărui produs din listă',
        'cale' => '/admin/products',
        'nou' => 'avertisment_titlu&quot;:&quot;&quot;,&quot;avertisment_text&quot;:&quot;&quot;,&quot;avertisment_eticheta&quot;:&quot;&quot;,&quot;',
        'vechi' => '',
        'motiv' => 'Bioscem 333ba9e (avertismentul pe produs): lista dă editorului și cele trei câmpuri (goale: niciun produs demo nu are '
            . 'avertisment); o dată pe fiecare produs din listă',
        'ori' => (int) $unu('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL'),
    ];
    $intrari[] = [
        'nume' => 'pagina produsului: fereastra avertismentului după adăugarea în coș',
        'cale' => $produse,
        'nou' => <<<'JS'
    // Produsul cu avertisment (ex. refrigerat): fereastra vine în răspunsul
    // adăugării; scriptul ei se încarcă doar când e nevoie.
    const arataAvertismentProdus = (avertisment, revino) => {
        if (!avertisment || typeof avertisment !== 'object') {
            return;
        }
        if (window.bvAvertismentProdus) {
            window.bvAvertismentProdus.arata(avertisment, revino);
            return;
        }
        const src = String(avertisment.script || '');
        if (!src.startsWith('/assets/')) {
            return;
        }
        const script = document.createElement('script');
        script.src = src;
        script.onload = () => window.bvAvertismentProdus?.arata(avertisment, revino);
        document.head.appendChild(script);
    };


JS,
        'vechi' => '',
        'motiv' => 'Bioscem b874cf0 (avertismentul pe produs): butonul de coș al paginii produsului arată fereastra avertismentului '
            . '(scriptul ei se încarcă doar pentru un produs cu avertisment)',
    ];
    $intrari[] = [
        'nume' => 'pagina produsului: fereastra avertismentului, chemată după adăugare',
        'cale' => $produse,
        'nou' => "                arataAvertismentProdus(payload?.avertisment, button);\n",
        'vechi' => '',
        'motiv' => 'Bioscem b874cf0 (avertismentul pe produs): perechea rândului de mai sus',
    ];
    $comenzi = ['/admin/orders', '/admin/orders?search=' . rawurlencode($nrComanda)];
    $intrari[] = [
        'nume' => 'Admin → Comenzi: avertismentele confirmate, în fereastra comenzii',
        'cale' => $comenzi,
        'nou' => <<<'JS'
            // Avertismentele confirmate la plasare (ex. produs refrigerat): ce a
            // văzut și a bifat clientul, exact cum era textul atunci.
            const avertismenteConfirmate = Array.isArray(order.avertismente_confirmate) ? order.avertismente_confirmate : [];
            const avertismenteHtml = avertismenteConfirmate.map((a) => `<div data-avertisment-confirmat style="margin:0 0 10px;padding:10px 12px;border:1px solid #fca5a5;border-left:4px solid #dc2626;border-radius:8px;background:#fef2f2;color:#7f1d1d;font-size:13px;">
                    <strong>${esc(a.linie || '')}</strong>
                    <details style="margin-top:6px;">
                        <summary style="cursor:pointer;color:#991b1b;">Textul confirmat</summary>
                        <div style="margin-top:6px;white-space:pre-line;color:#1f2937;overflow-wrap:anywhere;">${esc(a.text || '')}</div>
                        <p style="margin:8px 0 0;color:#475569;">${(Array.isArray(a.etichete) && a.etichete.length > 1) ? 'Bifele' : 'Bifa'}: ${(Array.isArray(a.etichete) ? a.etichete : []).map((e) => `„${esc(e)}”`).join(', ')}</p>
                    </details>
                </div>`).join('');


JS,
        'vechi' => '',
        'motiv' => 'Bioscem 5f2e483 și 233cc7d (avertismentul pe produs): confirmarea rămâne pe comandă și se vede în admin',
    ];
    $intrari[] = [
        'nume' => 'Admin → Comenzi: avertismentele confirmate, puse în fereastră',
        'cale' => $comenzi,
        'nou' => '\'\'}${avertismenteHtml}',
        'vechi' => '\'\'}',
        'motiv' => 'Bioscem 5f2e483 (avertismentul pe produs): perechea rândului de mai sus',
    ];
    $intrari[] = [
        'nume' => 'Emailuri: codul {{avertismente_produse}} în lista codurilor',
        'cale' => '/admin/emails/newsletters*',
        'nou' => <<<'HTML'
                                            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#f8fafc;">
                            <div>
                                <code style="font-weight:700;color:#0f172a;">{{avertismente_produse}}</code>
                                <div style="margin-top:4px;color:#64748b;font-size:13px;line-height:1.45;">
                                    Avertismentele confirmate la comandă (ex. produs refrigerat); fără cod, apar singure sub produse                                </div>
                            </div>
                        </div>

HTML,
        'vechi' => '',
        'motiv' => 'Bioscem fa76c4f (avertismentul pe produs): blocul avertismentelor confirmate din emailul de confirmare a comenzii',
    ];
    return $intrari;
})();
$porturiBioscem = array_merge($porturiBioscem, $porturiBioscemLivrarea2);
// WP-R (28 oct 2026): livrarea 4 SEO, Bioscem 5359ec6 … 070e1af, declarată pentru istoric pe paginile datelor demo (pachetul
// inventat, tools/dev/site-demo/, n-are livrare_retur și nici poze-optimizate.json, deci aici nu apar termenul de livrare și
// pozele WebP; pe acelea, și pe toate cele de mai jos, le dovedește G-cmp, față de codul Bioscem însuși, cu pachetul Bioscem).
$diferiteInainte = [];
$porturiBioscemLivrarea4 = (static function () use ($fataDeCommit, $paginaListe, $slugBlog, $slugBbd, $slugPre, $portCurent, $radacina,
    $cerere, $dosarDiff, $db, $unu, $faraSeo, &$diferiteInainte): array {
    $h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
    $intrari = [];
    // Bioscem 9fe175d (A11): o pagină din constructor fără niciun <h1> primește titlul ei ca H1, cu stilul lui, înaintea textului.
    $intrari[] = [
        'nume' => 'H1-ul pus de șablon pe o pagină fără <h1> (Liste goale)',
        'cale' => '/' . $paginaListe,
        'nou' => "    <style>\n        /* Titlul adăugat de șablon: fără specificitate (:where), ca stilurile paginii\n"
            . "           (de exemplu șablonul paginilor de text) să aibă întâietate. */\n"
            . '        :where(.custom-page-content) > :where(.custom-page-h1){max-width:1180px;margin:24px auto 14px;font-family:Georgia,'
            . '"Times New Roman",serif;font-size:31px;font-weight:500;line-height:1.3;color:#064b4f;}' . "\n"
            . '        @media (max-width:768px){:where(.custom-page-content) > :where(.custom-page-h1){font-size:25px;}}' . "\n"
            . "    </style>\n<section class=\"custom-page-content\">\n    <h1 class=\"custom-page-h1\">Liste goale</h1>\n",
        'vechi' => "<section class=\"custom-page-content\">\n    ",
        'motiv' => 'Bioscem 9fe175d (livrarea 4 SEO, A11): titlul paginii ca H1 când textul ei n-are unul (SeoIndexare, views/site/custom-page.php)',
    ];
    // La fel pe pagina acordului GDPR (WP-18 o adaugă la comparație), al cărei formular n-are <h1>.
    $intrari[] = ['nume' => 'H1-ul pus de șablon pe o pagină fără <h1> (Acorduri GDPR)', 'cale' => '/acorduri-gdpr',
        'nou' => str_replace('>Liste goale</h1>', '>Acorduri GDPR</h1>', $intrari[0]['nou'])] + $intrari[0];
    // Bioscem ef22b23 (A10): pagina „nu există” fără canonical și fără og:url.
    // Canonicalul codului de bază vine din APP_URL (portul serverului de dezvoltare), nu din portul serverului care răspunde
    // (din WP-16 codul curent rulează și aici pe un server doar al comparației, +4).
    $adresa404 = rtrim((string) (getenv('APP_URL') ?: 'http://127.0.0.1:' . $portCurent), '/') . '/pagina-care-nu-exista';
    $intrari[] = [
        'nume' => 'pagina 404 fără canonical',
        'cale' => '/pagina-care-nu-exista',
        'nou' => "</title>\n            <meta property=\"og:type\" content=\"website\">",
        'vechi' => "</title>\n                <link rel=\"canonical\" href=\"" . $adresa404 . "\">\n        <meta property=\"og:type\" content=\"website\">",
        'motiv' => 'Bioscem ef22b23 (livrarea 4 SEO, A10): o adresă care nu există nu are canonical (SeoIndexare::faraCanonicalPentruCod)',
    ];
    $intrari[] = [
        'nume' => 'pagina 404 fără og:url',
        'cale' => '/pagina-care-nu-exista',
        'nou' => "\">\n                    <meta name=\"twitter:card\" content=\"summary\">",
        'vechi' => "\">\n                <meta property=\"og:url\" content=\"" . $adresa404 . "\">\n                <meta name=\"twitter:card\" content=\"summary\">",
        'motiv' => 'Bioscem ef22b23 (livrarea 4 SEO, A10): perechea rândului de mai sus (og:url = canonicalul)',
    ];
    // Bioscem e2c97dc (A8): poza articolului din șablonul implicit cu width/height.
    $postare = $db->query('SELECT title, featured_image_url FROM blog_posts WHERE slug = ' . $db->quote($slugBlog))->fetch(PDO::FETCH_ASSOC) ?: [];
    $copertaPostare = trim((string) ($postare['featured_image_url'] ?? ''));
    $dimensiuni = $copertaPostare !== '' ? @getimagesize($radacina . '/public' . $copertaPostare) : false;
    if (is_array($dimensiuni)) {
        $intrari[] = [
            'nume' => 'poza articolului cu width/height',
            'cale' => '/blog/' . $slugBlog,
            'nou' => '<img src="' . $h($copertaPostare) . '" alt="' . $h((string) $postare['title']) . '" width="' . (int) $dimensiuni[0]
                . '" height="' . (int) $dimensiuni[1] . '">',
            'vechi' => '<img src="' . $h($copertaPostare) . '" alt="' . $h((string) $postare['title']) . '">',
            'motiv' => 'Bioscem e2c97dc (livrarea 4 SEO, A8): width/height pe poza articolului (PozeOptimizate::dimensiuni)',
        ];
    } elseif ($fataDeCommit) {
        $diferiteInainte[] = 'istoric       poza articolului /blog/' . $slugBlog;
        echo '  DIFERĂ istoric: poza articolului /blog/', $slugBlog, ' nu se poate citi (', $copertaPostare, ")\n";
    }
    // Bioscem 2d43171 (A14): „Produse similare” din aceeași categorie, cu cardurile scrise în HTML. Pe paginile datelor demo
    // nicio alegere din admin, deci în f5ae5ea secțiunea lipsea. Textul secțiunii se ia din pagina codului curent, dar doar
    // dacă produsele ei sunt exact cele ale regulii, calculate aici din bază, independent de cod (aceeași categorie, active,
    // fără cele cu ofertă BBD de ales, întâi cele care nu sunt epuizate, apoi pe rând după produsul curent, cel mult 8) și
    // dacă fiecare card duce la produsul lui; marcajul cardurilor e dovedit de G-cmp (aceleași pagini ca Bioscem 070e1af).
    if (!$fataDeCommit) {
        return $intrari;
    }
    $jar = $dosarDiff . '/jar-istoric-similare.txt';
    foreach (['/produs/ceai-de-menta', '/produs/ceai-de-musetel', '/produs/' . $slugBbd, '/produs/' . $slugPre] as $cale) {
        $slug = substr($cale, strlen('/produs/'));
        $curent = $db->query('SELECT id, category_id, similar_products_json FROM products WHERE slug = ' . $db->quote($slug))->fetch(PDO::FETCH_ASSOC) ?: [];
        $idCurent = (int) ($curent['id'] ?? 0);
        $toate = $db->query('SELECT * FROM products WHERE deleted_at IS NULL AND is_active = 1 AND category_id = '
            . (int) ($curent['category_id'] ?? 0) . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $toate);
        $pozitie = array_flip($ids);
        $n = max(1, count($ids));
        $candidati = array_values(array_filter($toate, static fn (array $r): bool => (int) $r['id'] !== $idCurent
            && !\App\Support\BbdOferte::cereAlegere($r)));
        usort($candidati, static fn (array $a, array $b): int => [(int) $a['out_of_stock'] === 1 ? 1 : 0,
            ((($pozitie[(int) $a['id']] - ($pozitie[$idCurent] ?? 0)) + $n) % $n)]
            <=> [(int) $b['out_of_stock'] === 1 ? 1 : 0, ((($pozitie[(int) $b['id']] - ($pozitie[$idCurent] ?? 0)) + $n) % $n)]);
        $asteptate = array_slice($candidati, 0, 8);
        @unlink($jar);
        // Ca la compararea paginii: întâi diferențele livrării 1 SEO ($faraSeo; între ele width/height pe pozele cardurilor).
        $gasiteSeo = [];
        $corp = $faraSeo($cale, (string) $cerere($portCurent, 'GET', $cale, $jar)['corp'], $gasiteSeo);
        @unlink($jar);
        $probleme = [];
        if ($idCurent === 0 || trim((string) ($curent['similar_products_json'] ?? '')) !== '') {
            $probleme[] = 'produsul lipsește sau are produse similare alese în admin (în f5ae5ea secțiunea n-ar lipsi)';
        }
        $sectiuni = preg_match_all('#<section class="similar-products" data-similar-products="1">.*?</section>#s', $corp, $m) === 1 ? $m[0][0] : '';
        preg_match('#<script type="application/json" class="similar-products__data">(.*?)</script>#s', $sectiuni, $json);
        $iduriPagina = array_map(static fn ($p): int => (int) ($p['id'] ?? 0), (array) json_decode((string) ($json[1] ?? ''), true));
        preg_match_all('#<a class="similar-product-card__media" href="(/[^"]*)"#', $sectiuni, $linkuri);
        if ($sectiuni === '' || $iduriPagina !== array_map(static fn (array $r): int => (int) $r['id'], $asteptate)
            || $linkuri[1] !== array_map(static fn (array $r): string => '/produs/' . rawurlencode((string) $r['slug']), $asteptate)) {
            $probleme[] = 'produsele secțiunii (' . json_encode($iduriPagina) . ', ' . json_encode($linkuri[1]) . ') nu sunt cele ale regulii ('
                . json_encode(array_map(static fn (array $r): int => (int) $r['id'], $asteptate)) . ')';
        }
        if ($probleme !== []) {
            $diferiteInainte[] = 'istoric       produse similare ' . $cale;
            echo '  DIFERĂ istoric: produse similare ', $cale, ': ', implode('; ', $probleme), "\n";
            continue;
        }
        $intrari[] = [
            'nume' => 'produse similare din aceeași categorie (' . $cale . ')',
            'cale' => $cale,
            'nou' => $sectiuni,
            'vechi' => '',
            'motiv' => 'Bioscem 2d43171 (livrarea 4 SEO, A14): 4–8 produse din aceeași categorie, cu cardurile în HTML (ProduseSimilare); '
                . 'în f5ae5ea doar cele alese în admin (aici niciunul, deci fără secțiune)',
        ];
    }
    return $intrari;
})();
$porturiBioscem = array_merge($porturiBioscem, $porturiBioscemLivrarea4);

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
    // WP-07 (infra, punctul A): adresele platformei (/_andaxi/ping, App\Support\Ping) nu sunt pagini. Și față de
    // f5ae5ea (ambele moduri); site-uri/bioscem/ce-se-schimba.md.
    [
        'nume' => 'robots.txt: adresele platformei (/_andaxi/)',
        'cale' => '/robots.txt',
        'nou' => "Disallow: /webhook/\nDisallow: /_andaxi/\n",
        'vechi' => "Disallow: /webhook/\n",
        'motiv' => 'pingul monitorizării (GET /_andaxi/ping) e o adresă tehnică, ca /webhook/: robots.txt o închide '
            . 'motoarelor de căutare. Celelalte reguli și ordinea lor rămân.',
    ],
    // WP-16 (DB-21, OD-29): textele doar din admin cu numele Bioscem, scrise neutru. Și față de f5ae5ea (ambele moduri);
    // site-uri/bioscem/ce-se-schimba.md. Titlul filelor adminului ia numele site-ului (App\Support\Brand::numeSite: Setări
    // magazin → SEO Google, altfel seo.json → organizatie.nume, la Bioscem „Bioscem Shop”), aici numele din baza demo.
    [
        'nume' => 'titlul filei din admin: numele site-ului în locul cuvântului „Bioscem”',
        'cale' => '/admin*',
        'nou' => ' | Admin ' . htmlspecialchars($numeSiteAdmin, ENT_QUOTES) . "</title>\n",
        'vechi' => " | Admin Bioscem</title>\n",
        'motiv' => 'WP-16 (A3): „Admin Bioscem” era scris în views/admin/layout.php; acum „Admin <numele site-ului>” (pe Bioscem live '
            . '„Admin Bioscem Shop”, din seo.json, sau numele scris în Setări magazin). Doar în fila browserului adminului.',
    ],
    [
        'nume' => 'Setări magazin → SEO Google: exemplul titlului primei pagini',
        'cale' => '/admin/settings/store',
        'nou' => 'placeholder="Ex: Numele magazinului - descriere scurtă"',
        'vechi' => 'placeholder="Ex: Bioscem - Produse naturale pentru sănătate"',
        'motiv' => 'WP-16 (H1d): exemplul din câmpul gol nu mai poartă numele unui client.',
    ],
    [
        'nume' => 'Setări → ERP ANDAXI: exemplul adresei',
        'cale' => '/admin/settings/erp',
        'nou' => 'placeholder="https://firma.erp.andaxi.ro"',
        'vechi' => 'placeholder="https://bioscem.erp.andaxi.ro"',
        'motiv' => 'WP-16 (H1a): exemplul din câmpul gol nu mai poartă numele unui client.',
    ],
    [
        'nume' => 'Setări plăți → ordin de plată: exemplul instrucțiunilor',
        'cale' => '/admin/settings/payments',
        'nou' => 'placeholder="Beneficiar: FIRMA S.R.L.&#10;',
        'vechi' => 'placeholder="Beneficiar: BIOSCEM S.R.L.&#10;',
        'motiv' => 'WP-16 (H1c): exemplul din câmpul gol nu mai poartă numele unui client.',
    ],
    [
        'nume' => 'Pagini → pagină nouă: exemplul titlului SEO',
        'cale' => ['/admin/pages/new', '/admin/pages/' . $idPagina . '/edit'],
        'nou' => 'placeholder="Ex: Numele site-ului - Titlu pagină"',
        'vechi' => 'placeholder="Ex: Bioscem - Titlu pagină"',
        'motiv' => 'WP-16 (H1e): exemplul din câmpul gol nu mai poartă numele unui client.',
    ],
    [
        'nume' => 'Coș flotant: exemplul adreselor excluse',
        'cale' => '/admin/settings/floating-cart',
        'nou' => 'https://domeniul-tau.ro/contact"',
        'vechi' => 'https://bioscem.ro/contact"',
        'motiv' => 'WP-16 (H1b): exemplul din câmpul gol nu mai poartă numele unui client.',
    ],
    [
        'nume' => 'Email-uri → formulare de contact: exemplul destinatarilor',
        'cale' => '/admin/emails/newsletters?tab=contact_forms',
        'nou' => 'placeholder="contact@domeniul-tau.ro, comenzi@domeniul-tau.ro"',
        'vechi' => 'placeholder="contact@bioscem.ro, comenzi@bioscem.ro"',
        'motiv' => 'WP-16 (A7): exemplul din câmpul gol nu mai poartă adresele unui client.',
    ],
    // WP-22 (DB-13, D7): autorul articolelor de exemplu din previzualizarea listei de articole a editorului de pagini (când
    // baza n-are articole de arătat acolo) nu mai poartă numele unui client. Pe site, /blog e identic.
    [
        'nume' => 'Pagini → previzualizarea {{blog_posts}}: poza autorului articolelor de exemplu',
        'cale' => ['/admin/pages/new', '/admin/pages/' . $idPagina . '/edit'],
        'nou' => 'alt=\\u0022Autor demo\\u0022',
        'vechi' => 'alt=\\u0022Echipa Bioscem\\u0022',
        'motiv' => 'WP-22 (DB-13, D7): articolul de exemplu din previzualizarea editorului de pagini avea autorul „Echipa Bioscem”; acum '
            . '„Autor demo” (textul alternativ al pozei autorului, în JSON-ul previzualizării). Doar în admin.',
    ],
    [
        'nume' => 'Pagini → previzualizarea {{blog_posts}}: numele autorului articolelor de exemplu',
        'cale' => ['/admin/pages/new', '/admin/pages/' . $idPagina . '/edit'],
        'nou' => '\\u003Cspan\\u003EAutor demo\\u003C/span\\u003E',
        'vechi' => '\\u003Cspan\\u003EEchipa Bioscem\\u003C/span\\u003E',
        'motiv' => 'WP-22 (DB-13, D7): perechea rândului de mai sus (numele scris sub poză).',
    ],
    [
        'nume' => 'editorul șabloanelor de blog: autorul din datele de exemplu ale previzualizării',
        'cale' => '/admin/blog/templates/' . $idSablonBlogEditor . '/builder',
        'nou' => "'{{blog_author_name}}': 'Autor demo',",
        'vechi' => "'{{blog_author_name}}': 'Echipa Bioscem',",
        'motiv' => 'WP-22 (DB-13, D7): datele de exemplu cu care editorul arată un șablon nu mai poartă numele unui client.',
        'mod' => 'repo',
    ],
    [
        'nume' => 'editorul șabloanelor de blog: linkul autorului din datele de exemplu ale previzualizării',
        'cale' => '/admin/blog/templates/' . $idSablonBlogEditor . '/builder',
        'nou' => "'{{blog_author_url}}': '/blog?autor=autor-demo',",
        'vechi' => "'{{blog_author_url}}': '/blog?autor=echipa-bioscem',",
        'motiv' => 'WP-22 (DB-13, D7): perechea rândului de mai sus.',
        'mod' => 'repo',
    ],
    // WP-12 (X1b): o postare importată cu categoria doar ca text. Paginile se cer în cazurile de salvare „blog: …
    // categoria doar ca text …” (mai jos), deci doar față de codul Bioscem (ca editorul postării, cu diferențele portului
    // vertij); site-uri/bioscem/ce-se-schimba.md.
    [
        'nume' => 'editorul postării: categoria doar ca text, fără o categorie cu același nume, se arată și se trimite la salvare',
        'cale' => '/admin/blog/posts/editor?post=' . $idPostareDoarText,
        'nou' => "\n            <small class=\"muted\" data-categorie-doar-text>Categoria postării, „" . $categorieDoarTextDemo
            . "”, a venit doar ca text (de exemplu de la un import) și nu e în lista de mai sus: rămâne la salvare. Bifează o "
            . "categorie dacă vrei s-o schimbi.</small>\n            <input type=\"hidden\" name=\"category\" value=\""
            . $categorieDoarTextDemo . '">',
        'vechi' => '',
        'motiv' => 'X1b (WP-12): editorul Bioscem nu bifează nimic la o postare cu categoria doar ca text și nu trimite niciun câmp de '
            . 'categorie, deci o re-salvare o ștergea; acum textul se arată și se trimite (câmp ascuns), iar salvarea îl păstrează.',
        'mod' => 'repo',
    ],
    [
        'nume' => 'editorul postării: categoria doar ca text, cu numele unei categorii din listă, e bifată',
        'cale' => '/admin/blog/posts/editor?post=' . $idPostareTextCaNume,
        'nou' => 'name="category_ids[]" value="' . $idCategorieDupaNume . '" checked>',
        'vechi' => 'name="category_ids[]" value="' . $idCategorieDupaNume . '" >',
        'motiv' => 'X1b (WP-12): categoria din listă cu același nume (sau aceeași adresă) ca textul postării se bifează, deci re-salvarea '
            . 'leagă postarea de ea; în Bioscem nicio bifă, iar re-salvarea ștergea categoria.',
        'mod' => 'repo',
    ],
];
/**
 * Tot în ambele moduri: paginile la care codul de stare diferă voit de cel al Bioscem (o
 * eroare a Bioscem reparată în nucleu; contractul: diferente_coduri). Pagina se cere ca
 * oricare alta și trece doar cu exact codurile declarate și cu textul ei pe pagina curentă
 * (lipsă din răspunsul Bioscem). Fiecare intră și în site-uri/bioscem/ce-se-schimba.md.
 */
$coduriDeBioscem = [
    // WP-R (20 oct): găsită din nou la revizuirea Bioscem 9f59e79 (DEZVOLTARE, „Erori moștenite” 7).
    [
        'nume' => '/checkout/succes deschis direct: pagina, nu eroarea 500',
        'cale' => '/checkout/succes',
        'cod_baza' => 500,
        'cod_curent' => 200,
        'text' => '<h1>Mulțumim pentru comandă!</h1>',
        'motiv' => 'Bioscem trimite starea comenzii (text) pe locul totalului (număr) și pagina din bază cu tokenul '
            . '{{checkout_success_order_info}} cade cu TypeError; reparat în nucleu în 5d808ea. Pagina de mulțumire de după '
            . 'comandă, /checkout/succes/<număr>, are alt drum și e aceeași.',
    ],
    // WP-07 (infra, punctul A): adresă nouă a nucleului; în Bioscem nu există (404, pagina de eroare a site-ului).
    [
        'nume' => '/_andaxi/ping: pingul monitorizării, nou',
        'cale' => '/_andaxi/ping',
        'cod_baza' => 404,
        'cod_curent' => 200,
        'text' => '{"ok":true,"db":true,"cod":null,"schema":"la_zi"}',
        'motiv' => 'App\\Support\\Ping (planul Etapei 1, WP-07): monitorul CRM-ului și verificarea de după un deploy întreabă '
            . 'dacă baza răspunde (SELECT 1 în cel mult 2 secunde) și dacă schema e la zi; JSON, Cache-Control: no-store, '
            . 'fără sesiune. Pe Bioscem adresa dă azi pagina 404; nicio pagină a site-ului nu se schimbă.',
    ],
    // Aceeași adresă cu „/” la sfârșit (revizuirea WP-07): routerul o duce la ruta pingului, deci public/index.php o
    // servește tot ca ping, fără sesiune (Ping::eCererea). În Bioscem tot 404 (pe gazda corectă stratul SEO lasă 404-ul).
    [
        'nume' => '/_andaxi/ping/: pingul și cu „/” la sfârșit, nou',
        'cale' => '/_andaxi/ping/',
        'cod_baza' => 404,
        'cod_curent' => 200,
        'text' => '{"ok":true,"db":true,"cod":null,"schema":"la_zi"}',
        'motiv' => 'routerul scoate „/”-ul final, iar /_andaxi e scos din redirecționările SEO (SeoRedirects::CAI_EXCLUSE), '
            . 'deci adresa cu „/” e tot pingul: același JSON, fără sesiune și fără cookie, cu aceeași limită de 2 secunde '
            . '(altfel ar fi trecut prin toată aplicația, cu un fișier de sesiune nou la fiecare cerere). Pe Bioscem dă '
            . 'azi pagina 404, ca /_andaxi/ping.',
    ],
];
// WP-12 (Biovitality c64fc2d, 4f65fd9): editoarele de cod doar pe cele patru pagini care le folosesc (C6) și pozele
// selectoarelor de imagini încărcate abia la deschiderea lor (C7). Câte poze are fiecare selector: galeria demo, cu
// filtrul și limita controllerului (produse și Setări magazin: doar „image”, 300; emailuri, editorul postării și
// autorii: „image” sau fără tip, 500).
$cdnEditoare = '';
foreach (['codemirror@5.65.18/lib/codemirror.min.css', 'codemirror@5.65.18/theme/material-darker.min.css',
    'codemirror@5.65.18/addon/dialog/dialog.min.css'] as $fisierCdn) {
    $cdnEditoare .= '    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/' . $fisierCdn . "\">\n";
}
foreach (['codemirror@5.65.18/lib/codemirror.min.js', 'codemirror@5.65.18/mode/javascript/javascript.min.js',
    'codemirror@5.65.18/mode/css/css.min.js', 'codemirror@5.65.18/mode/xml/xml.min.js', 'codemirror@5.65.18/mode/htmlmixed/htmlmixed.min.js',
    'codemirror@5.65.18/addon/dialog/dialog.min.js', 'codemirror@5.65.18/addon/search/searchcursor.min.js',
    'codemirror@5.65.18/addon/search/search.min.js', 'codemirror@5.65.18/addon/edit/closebrackets.min.js',
    'codemirror@5.65.18/addon/edit/matchbrackets.min.js', 'codemirror@5.65.18/addon/selection/active-line.min.js',
    'js-beautify@1.15.1/js/lib/beautify.min.js', 'js-beautify@1.15.1/js/lib/beautify-css.min.js',
    'js-beautify@1.15.1/js/lib/beautify-html.min.js'] as $fisierCdn) {
    $cdnEditoare .= '    <script src="https://cdn.jsdelivr.net/npm/' . $fisierCdn . "\"></script>\n";
}
$pozeSelectorImagine = min(300, (int) $unu("SELECT COUNT(*) FROM gallery_images WHERE media_type = 'image'"));
$pozeSelectorSauFaraTip = min(500, (int) $unu("SELECT COUNT(*) FROM gallery_images WHERE media_type = 'image' OR media_type IS NULL"));
$porturiBiovitality = [
    [
        'nume' => 'adminul: CodeMirror și js-beautify doar în cele patru editoare de cod',
        'cale' => '/admin*',
        // Editoarele (design-site, page-editor, product-template-editor și blog-template-editor, acesta cerut de cazul de
        // salvare „editorul șabloanelor de blog”, pe un șablon făcut acolo) le încarcă în continuare, deci acolo pagina e
        // cea a Bioscem.
        'fara_pagini' => ['/admin/pages/new', '/admin/pages/' . $idPagina . '/edit',
            '/admin/products/templates/' . $idSablonProdus . '/builder', '/admin/design',
            '/admin/blog/templates/' . $idSablonBlogEditor . '/builder'],
        'nou' => "\">\n</head>",
        'vechi' => "\">\n" . $cdnEditoare . '</head>',
        'motiv' => 'Biovitality c64fc2d (WP-12 C6): cele 15 fișiere de pe CDN se încărcau, fără defer, pe fiecare pagină de admin, '
            . 'deși doar editoarele de cod le folosesc („Erori moștenite” 6 din docs/DEZVOLTARE.md); paginile arată la fel.',
    ],
    [
        'nume' => 'selectoarele de imagini din galerie: pozele se încarcă abia când se deschide selectorul',
        'cale' => ['/admin/products', '/admin/settings/store', '/admin/emails/newsletters*', '/admin/blog/posts/editor*', '/admin/blog/authors'],
        'ori' => max(1, $pozeSelectorImagine),
        'ori_pe_pagina' => [
            // Setări magazin are două selectoare (favicon și imaginea SEO implicită).
            '/admin/settings/store' => max(1, 2 * $pozeSelectorImagine),
            '/admin/emails/newsletters*' => max(1, $pozeSelectorSauFaraTip),
            '/admin/blog/posts/editor*' => max(1, $pozeSelectorSauFaraTip),
            '/admin/blog/authors' => max(1, $pozeSelectorSauFaraTip),
        ],
        'nou' => '<img loading="lazy" decoding="async" src="',
        'vechi' => '<img src="',
        'motiv' => 'Biovitality 4f65fd9 (WP-12 C7): selectorul stă într-o fereastră ascunsă, dar browserul descărca toate pozele '
            . 'galeriei (până la 500) la fiecare încărcare a paginii (pe Email-uri, pe orice tab); acum doar la deschiderea lui.',
    ],
    // WP-21 (C13, Biovitality 657c0cf, 9cca65b): fereastra „Cui a fost dat” arată și când / de cine a fost dat produsul,
    // iar totalul rămâne lipit sus când lista derulează.
    [
        'nume' => 'produsele promoționale: „dat la / dat de” în fereastra „Cui a fost dat”',
        'cale' => '/admin/promo-products',
        'nou' => "'<br><span style=\"color:#94a3b8;\">' + esc(x.order_number) + ' · ' + esc(x.created_at) + '</span>' +\n"
            . "                            '<br><span style=\"color:#94a3b8;\">' + (x.given_at ? 'Dat la ' + esc(x.given_at) + (x.given_by ? ' de ' + "
            . "esc(x.given_by) : '') : 'Dat odată cu comanda') + '</span>' +",
        'vechi' => "'<br><span style=\"color:#94a3b8;\">' + esc(x.order_number) + ' · ' + esc(x.created_at) + '</span>' +",
        'motiv' => 'Biovitality 657c0cf (WP-21 C13): momentul acordării și adminul care a dat produsul (order_promo_items.given_at, '
            . 'given_by, produse_promo/0031), păstrate peste re-salvări.',
    ],
    [
        'nume' => 'produsele promoționale: totalul lipit sus în fereastra care derulează',
        'cale' => '/admin/promo-products',
        'nou' => "'<p style=\"position:sticky;top:0;z-index:1;margin:0 0 10px;padding:6px 0;background:#fff;font-weight:700;\">Total dat: '",
        'vechi' => "'<p style=\"margin:0 0 10px;font-weight:700;\">Total dat: '",
        'motiv' => 'Biovitality 9cca65b (WP-21 C13): la sute de rânduri, fereastra „Cui a fost dat” curgea peste pagină; acum derulează '
            . 'doar lista, cu totalul sus.',
    ],
];
// WP-22 (B2, Biovitality 9c549c7): lista tokenurilor din editorul șabloanelor de blog are cele patru tokenuri noi ale
// articolului (pagina cerută de cazul de salvare „editorul șabloanelor de blog”, pe un șablon făcut acolo).
$tokenuriBlogNoi = '';
foreach (['Cuprins (din titlurile articolului)' => '{{blog_toc}}', 'Referințe (bibliografie)' => '{{blog_references}}',
    'Etichete' => '{{blog_tags}}', 'Articole similare' => '{{blog_related_posts}}'] as $etichetaToken => $tokenNou) {
    $tokenuriBlogNoi .= "                                    <div style=\"padding:8px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;\">\n"
        . "                        <strong style=\"display:block;font-size:13px;\">" . $etichetaToken . "</strong>\n"
        . "                        <code>" . $tokenNou . "</code>\n                    </div>\n";
}
$porturiBiovitality[] = [
    'nume' => 'editorul șabloanelor de blog: tokenurile cuprinsului, bibliografiei, etichetelor și articolelor similare',
    'cale' => '/admin/blog/templates/' . $idSablonBlogEditor . '/builder',
    'nou' => $tokenuriBlogNoi,
    'vechi' => '',
    'motiv' => 'Biovitality 9c549c7 (WP-22 B2): {{blog_toc}}, {{blog_references}}, {{blog_tags}} și {{blog_related_posts}} se pot pune în '
        . 'șablonul articolului; pe site, un șablon fără ele arată ca înainte.',
    'mod' => 'repo',
];
// WP-23 (B4; Biovitality c078bd5, 9c549c7, 49c4f5b, 3ea6a31): editorul postării, rescris în cea mai mare parte; textele
// (codul Bioscem → codul de acum) în tools/dev/lib/declaratii-wp23-editor.php, pe paginile editorului cerute de cazurile de
// salvare ale blogului (aceleași pe fiecare postare).
foreach (require __DIR__ . '/lib/declaratii-wp23-editor.php' as $nrEditor => $schimbareEditor) {
    $porturiBiovitality[] = [
        'nume' => 'editorul postării de blog: blogul avansat (' . ($nrEditor + 1) . ')',
        'cale' => '/admin/blog/posts/editor*',
        // Adresa site-ului din cardul Google e cea a serverului de dezvoltare (CMS_DEV_PORT); textele sunt scrise cu 8701.
        'nou' => str_replace('http://127.0.0.1:8701', 'http://127.0.0.1:' . (getenv('CMS_DEV_PORT') ?: '8701'), $schimbareEditor['nou']),
        'vechi' => $schimbareEditor['vechi'],
        'motiv' => 'Biovitality c078bd5, 9c549c7, 49c4f5b, 3ea6a31 (WP-23 B4): editorul blogului avansat (H5/H6, aliniere, chenarul '
            . 'pozelor, lățimea copertei, bibliografia, etichetele, dialogul de link, textul alternativ, cardul Google, previzualizarea, '
            . '„Salvează ca draft”); se reface în Faza D.',
        'mod' => 'repo',
    ];
}
// WP-21 (C11c, C11d; Biovitality b9fcaf8, 84e29a9): constructorul de emailuri, blocul „Video / TikTok” și fundalul blocurilor
// din coloane; textele (codul Bioscem → codul de acum) în tools/dev/lib/declaratii-wp21-constructor.php, pe pagina
// constructorului unui template nou.
foreach (require __DIR__ . '/lib/declaratii-wp21-constructor.php' as $nrConstructor => $schimbareConstructor) {
    $porturiBiovitality[] = [
        'nume' => 'constructorul de emailuri: blocul video și fundalul blocurilor din coloane (' . ($nrConstructor + 1) . ')',
        'cale' => '/admin/emails/builder',
        'nou' => $schimbareConstructor['nou'],
        'vechi' => $schimbareConstructor['vechi'],
        'motiv' => 'Biovitality 84e29a9 și b9fcaf8 (WP-21 C11c, C11d): blocul video al newsletterelor (copertă cu buton, nu player: '
            . 'clienții de email nu redau video) și blocurile din coloane fără fundal propriu, cu aliniere.',
    ];
}
// Postările cazului „blog: un link YouTube (fără acord) și unul Vimeo (cu acord)” din salvări (mai jos): doar pe
// paginile lor apar playerul și caseta „Pornește videoclipul” (declarate aici, în $porturiVertij).
$slugVideoYoutube = 'video-youtube-de-test-' . getmypid();
$slugVideoVimeo = 'video-vimeo-de-test-' . getmypid();
$videoNeagra = static fn (string $url): string => '<video controls preload="metadata" style="width:100%;height:auto;border-radius:14px;display:block;">'
    . '<source src="' . $url . '" type="video/mp4">Browserul tău nu suportă redarea video.</video>';
$porturiVertij = [
    // WP-R (20 oct 2026): vertij 0fc2b2c, câmpul de video al postării acceptă și linkuri YouTube și Vimeo
    // (App\Support\VideoEmbed); textul din editor spune asta (site-uri/bioscem/ce-se-schimba.md).
    [
        'nume' => 'editorul postării: câmpul de video, eticheta și exemplul',
        'cale' => '/admin/blog/posts/editor*',
        'nou' => "<label>Video</label>\n            <input type=\"text\" name=\"video_url\" placeholder=\"https://www.youtube.com/watch?v=...\"",
        'vechi' => "<label>Video (fișier MP4)</label>\n            <input type=\"text\" name=\"video_url\" placeholder=\"/uploads/gallery/nume-video.mp4\"",
        'motiv' => 'vertij 0fc2b2c: un link YouTube sau Vimeo devine playerul lor (înainte, o casetă video neagră); '
            . 'fișierele .mp4 urcate în Galerie rămân <video>, ca înainte.',
        'mod' => 'repo',
    ],
    [
        'nume' => 'editorul postării: câmpul de video, explicația',
        'cale' => '/admin/blog/posts/editor*',
        'nou' => "<small class=\"muted\">\n                Lipește linkul de pe YouTube sau Vimeo, exact cum îl copiezi din bara browserului.\n"
            . "                Merge și un fișier urcat în Galerie (<code>/uploads/gallery/nume.mp4</code>).\n            </small>",
        'vechi' => '<small class="muted">Încarcă fișierul .mp4 în Galerie, apoi pune aici calea (ex: <code>/uploads/gallery/nume.mp4</code>).</small>',
        'motiv' => 'perechea rândului de mai sus (vertij 0fc2b2c).',
        'mod' => 'repo',
    ],
    // WP-R, revizuirea portului (20 oct 2026): playerul altui site doar cu acordul pentru cookie-uri (App\Support\VideoEmbed,
    // CookieConsent), Vimeo cu dnt=1; site-uri/bioscem/ce-se-schimba.md și site-uri/vertij/ce-se-schimba.md.
    [
        'nume' => 'postarea cu un link YouTube, la un vizitator fără acordul pentru cookie-uri: caseta „Pornește videoclipul”',
        'cale' => '/blog/' . $slugVideoYoutube,
        'nou' => '<div style="position:relative;width:100%;padding-top:56.25%;border-radius:14px;overflow:hidden;background:#15202b;">'
            . '<a href="https://www.youtube.com/watch?v=AbCdEf12345&amp;t=65s" target="_blank" rel="noopener"'
            . ' data-video-player="https://www.youtube-nocookie.com/embed/AbCdEf12345?start=65&amp;autoplay=1" data-video-titlu="Videoclip YouTube"'
            . ' onclick="var f=document.createElement(&#039;iframe&#039;);f.src=this.getAttribute(&#039;data-video-player&#039;);'
            . 'f.title=this.getAttribute(&#039;data-video-titlu&#039;);'
            . 'f.setAttribute(&#039;style&#039;,&#039;position:absolute;inset:0;width:100%;height:100%;border:0;&#039;);'
            . 'f.setAttribute(&#039;allow&#039;,&#039;accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share&#039;);'
            . 'f.setAttribute(&#039;referrerpolicy&#039;,&#039;strict-origin-when-cross-origin&#039;);f.setAttribute(&#039;allowfullscreen&#039;,&#039;&#039;);'
            . 'this.parentNode.replaceChild(f,this);return false;"'
            . ' style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;'
            . 'padding:18px;color:#ffffff;text-decoration:none;text-align:center;font-size:15px;line-height:1.5;">'
            . '<span aria-hidden="true" style="display:flex;align-items:center;justify-content:center;width:64px;height:64px;'
            . 'border-radius:50%;background:rgba(255,255,255,.18);font-size:26px;">&#9654;</span>'
            . '<span><strong>Pornește videoclipul</strong><br>Playerul YouTube se încarcă doar când apeși; YouTube poate folosi cookie-uri.</span>'
            . '</a></div>',
        'vechi' => $videoNeagra('https://youtu.be/AbCdEf12345?t=1m5s'),
        'motiv' => 'vertij 0fc2b2c: un link YouTube devine playerul lui (în Bioscem, caseta video neagră); fără acordul vizitatorului '
            . 'pentru toate cookie-urile, playerul se încarcă abia la apăsarea casetei (diferență față de vertij, la revizuirea portului).',
        'mod' => 'repo',
    ],
    [
        'nume' => 'postarea cu un link Vimeo, la un vizitator care a acceptat toate cookie-urile: playerul, cu dnt=1',
        'cale' => '/blog/' . $slugVideoVimeo,
        'nou' => '<div style="position:relative;width:100%;padding-top:56.25%;border-radius:14px;overflow:hidden;background:#15202b;">'
            . '<iframe src="https://player.vimeo.com/video/123456789?dnt=1" title="Videoclip Vimeo"'
            . ' style="position:absolute;inset:0;width:100%;height:100%;border:0;"'
            . ' allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"'
            . ' referrerpolicy="strict-origin-when-cross-origin" allowfullscreen loading="lazy"></iframe></div>',
        'vechi' => $videoNeagra('https://vimeo.com/123456789'),
        'motiv' => 'vertij 0fc2b2c: un link Vimeo devine playerul lui (în Bioscem, caseta video neagră); cu acordul vizitatorului se '
            . 'încarcă singur, mereu cu dnt=1 (Vimeo fără cookie-uri de urmărire; diferență față de vertij, la revizuirea portului).',
        'mod' => 'repo',
    ],
];
$porturiGrafo = [
    // WP-19 (GR-16, din grafoanaytis 84c9f6b): codul benzii simple a produselor din aceeași categorie, în lista codurilor
    // editorului de șabloane de produs (tabul „General” și tabul „Toate”).
    [
        'nume' => 'editorul șabloanelor de produs: codul {{product_produse_similare}} în tabul „General”',
        'cale' => '/admin/products/templates/' . $idSablonProdus . '/builder',
        'nou' => "                    <div style=\"padding:8px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;\">\n"
            . "                        <strong style=\"display:block;font-size:13px;\">Produse din aceeași categorie (bandă simplă)</strong>\n"
            . "                        <code>{{product_produse_similare}}</code>\n                    </div>\n                ",
        'vechi' => '',
        'motiv' => 'grafoanaytis 84c9f6b (WP-19, GR-16): banda produselor din aceeași categorie fără preț, stele și coș, pentru '
            . 'șabloanele unui catalog de prezentare; un cod nou, nefolosit de șabloanele Bioscem.',
    ],
    [
        'nume' => 'editorul șabloanelor de produs: codul {{product_produse_similare}} în tabul „Toate”',
        'cale' => '/admin/products/templates/' . $idSablonProdus . '/builder',
        'nou' => "                    <div style=\"padding:8px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;\">\n"
            . "                        <small style=\"display:block;color:#64748b;font-weight:700;\">General</small>\n"
            . "                        <strong style=\"display:block;font-size:13px;\">Produse din aceeași categorie (bandă simplă)</strong>\n"
            . "                        <code>{{product_produse_similare}}</code>\n                    </div>\n                ",
        'vechi' => '',
        'motiv' => 'perechea rândului de mai sus (grafoanaytis 84c9f6b, WP-19, GR-16).',
    ],
];
/**
 * Marcajele de port (App\Support\Dev::marcaj): nume „<sursa>:<NUME>” => pagina (sau
 * „/admin*”) și motivul. Blocul marcat se scoate din pagina codului curent; fiecare
 * nume trebuie găsit exact o dată pe pagina lui și pe nicio altă pagină.
 */
$marcajePort = [
    // WP-19 (C8, C14b, C14c, C14d, C14e, GR-17; Biovitality 2b8a618, b9e3ddc, 90afd46, 57447e0, 1d4bee2; grafoanaytis 8f51fe4):
    // câmpurile noi ale catalogului în editorul produsului, mesajul câmpurilor suplimentare ascunse după șablon,
    // scriptul lor (plus linkul de ciornă din listă) și codurile noi din editorul paginilor, cu previzualizarea lor.
    'bv:produs-catalog' => ['cale' => '/admin/products', 'motiv' => 'WP-19: poziția în liste, „ascuns din lista magazinului” și linkul '
        . 'de ciornă al unui produs inactiv, în editorul produsului (portate din Biovitality)'],
    'bv:campuri-sablon' => ['cale' => '/admin/products', 'motiv' => 'WP-19 (C14d): mesajele câmpurilor suplimentare ascunse fiindcă '
        . 'șablonul ales nu le folosește'],
    'bv:produse-catalog-js' => ['cale' => '/admin/products', 'motiv' => 'WP-19: datele și scriptul câmpurilor catalogului (editor, '
        . 'filtrul câmpurilor după șablon, câmpurile „Imagine”, linkul de ciornă din listă), separat de scriptul editorului'],
    'bv:token-produse-sablon' => ['cale' => ['/admin/pages/new', '/admin/pages/' . $idPagina . '/edit'], 'motiv' => 'WP-19 (C14e, '
        . 'GR-17): codurile {{products_by_template:…}} și {{produse_serviciu}} în lista codurilor editorului de pagini'],
    'bv:previzualizare-produse-sablon' => ['cale' => ['/admin/pages/new', '/admin/pages/' . $idPagina . '/edit'], 'motiv' => 'WP-19 '
        . '(C14e, GR-17): chenarul lor în previzualizarea editorului de pagini'],
    // WP-18 (GR-03, DB-15): blocul „Datele operatorului” (din grafoanaytis 2c11526, d1bffca) deasupra listei acordurilor
    // GDPR; textul formularului public citește aceste setări (Bioscem: din înghețare, /acorduri-gdpr rămâne identică).
    'gr:gdpr-setari' => ['cale' => '/admin/users/gdpr-agreements', 'motiv' => 'WP-18: setările operatorului acordului GDPR, portate din grafoanaytis'],
    // WP-21 (C10, Biovitality 8ba8ddd, 0c682b3): capcana anti-spam a formularului de recenzie (câmpul ascuns review_website și
    // momentul deschiderii, review_opened_at, care se schimbă la fiecare cerere) și, în admin, filtrul „Spam”, butoanele lui
    // și marcajul pe rând (scriptul cu datele recenziilor din pagină).
    // Paginile de produs cerute (lista de pagini și cazurile de salvare); nu și produsul lipsă (404, fără formular).
    'bv:recenzie-capcana' => ['cale' => ['/produs/ceai-de-menta', '/produs/ceai-de-musetel', '/produs/' . $slugBbd, '/produs/' . $slugPre,
        '/produs/omega-3-din-alge', '/produs/miere-de-manuka-mgo-400', '/produs/cos-cadou-cu-miere', '/produs/proteina-harrison-de-test'], 'motiv' => 'WP-21 (C10): capcana și momentul deschiderii '
        . 'formularului de recenzie'],
    'bv:recenzii-filtru-spam' => ['cale' => '/admin/products/reviews', 'motiv' => 'WP-21 (C10): filtrul „Spam” al recenziilor'],
    'bv:recenzii-spam-actiuni' => ['cale' => '/admin/products/reviews', 'motiv' => 'WP-21 (C10): „Detectează spamul” și „Golește spamul”'],
    'bv:recenzii-spam-randuri' => ['cale' => '/admin/products/reviews', 'motiv' => 'WP-21 (C10): insigna SPAM, adresa și butonul '
        . '„Spam” / „Nu e spam” pe fiecare rând'],
    // WP-21 (C12, Biovitality 222ed71): rândul „Produse” al editării în masă a cupoanelor unice.
    'bv:cupoane-unice-produse' => ['cale' => '/admin/coupons/unique', 'motiv' => 'WP-21 (C12): produsele cupoanelor unice, schimbate în masă'],
    // WP-21 (C13, Biovitality 2ebc0cb, 9cca65b): exportul Excel al produselor promoționale și fereastra care derulează.
    'bv:promo-export' => ['cale' => '/admin/promo-products', 'motiv' => 'WP-21 (C13): butonul „Export Excel” și stilul ferestrei „Cui a fost dat”'],
    'bv:promo-export-filtru' => ['cale' => '/admin/promo-products', 'motiv' => 'WP-21 (C13): „Export Excel” lângă filtrul de date'],
    // WP-23 (B4, Biovitality e2c33d3): lista postărilor de blog din admin își schimbă paginile fără reîncărcare (containerul
    // listei, stilul și scriptul; paginarea însăși apare doar peste o pagină de postări, 25 implicit, deci nu în datele demo).
    'bv:blog-lista-inceput' => ['cale' => '/admin/blog/posts', 'motiv' => 'WP-23 (B4): începutul containerului listei postărilor'],
    'bv:blog-lista-sfarsit' => ['cale' => '/admin/blog/posts', 'motiv' => 'WP-23 (B4): sfârșitul containerului listei postărilor'],
    'bv:blog-lista-script' => ['cale' => '/admin/blog/posts', 'motiv' => 'WP-23 (B4): stilul și scriptul paginării fără reîncărcare'],
];
$listeDeclarate = ['$deBioscem' => $deBioscem, '$porturiBiovitality' => $porturiBiovitality, '$porturiVertij' => $porturiVertij,
    '$porturiGrafo' => $porturiGrafo];
if ($fataDeCommit) {
    $listeDeclarate = ['$porturiBioscem' => $porturiBioscem] + $listeDeclarate;
}
// Intrările legate de un singur mod ('mod' => 'repo' sau 'commit') se verifică doar în el.
$doarInModulBazei = static function (array $liste) use ($modBaza): array {
    foreach ($liste as $numeLista => $lista) {
        $liste[$numeLista] = array_values(array_filter($lista,
            static fn (array $intrare): bool => !isset($intrare['mod']) || $intrare['mod'] === $modBaza));
    }
    return $liste;
};
$listeDeclarate = $doarInModulBazei($listeDeclarate);
$listeCoduri = $doarInModulBazei(['$coduriDeBioscem' => $coduriDeBioscem]);
// Eticheta fiecărei declarații de cod => de câte ori i s-a cerut pagina (trebuie exact o dată).
$coduriGasite = [];
/** Scoate din pagina curentă tot ce e declarat (marcaje, apoi texte); problemele în $probleme. */
// Fiecare declarație găsită măcar o dată în toată rularea (WP-12): una pe care n-o atinge nicio pagină cerută (o pagină
// scoasă din listă, un caz de salvare care nu mai rulează) n-ar dovedi nimic, deci pică la final.
$gasiteInRulare = [];
$faraDeclarate = static function (string $cale, string $html, array &$gasite, array &$probleme) use ($listeDeclarate, $marcajePort, &$gasiteInRulare): string {
    // Jetonul de sesiune al unui formular de admin (_csrf) e la întâmplare: o declarație care îl conține îl scrie <TOKEN>,
    // cum îl lasă oricum normalizeaza_html() după (revizuirea WP-23: editorul postării are acum jetonul în formular).
    $html = (string) preg_replace('/(name="_csrf" value=")[0-9a-f]{64}"/', '$1<TOKEN>"', $html);
    $html = diferente_marcaje($marcajePort, $cale, $html, $gasite, $probleme);
    $html = diferente_texte($listeDeclarate, $cale, $html, $gasite, $probleme);
    foreach ($gasite as $etichetaGasita => $deCate) {
        $gasiteInRulare[$etichetaGasita] = ($gasiteInRulare[$etichetaGasita] ?? 0) + (int) $deCate;
    }
    return $html;
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

$dupaLogin = [];
// Unde a dus fiecare logare: o parolă greșită (de exemplu admin-parola.txt rescris de altă unealtă) duce înapoi la
// pagina de login pe ambele servere, iar paginile adminului și ale clientului ar ieși „identice” fără să fi fost văzute.
$logariGresite = [];

foreach (['curent' => $portCurent, 'baza' => $portBaza] as $eticheta => $port) {
    $cerere($port, 'POST', '/cos/adauga/' . $idMenta, $jaruri[$eticheta]['viz'], ['quantity' => '2']);
    $logari = [
        'admin@… → /admin' => [$cerere($port, 'POST', '/admin/login', $jaruri[$eticheta]['adm'], ['email' => $adminEmail, 'password' => $parola])['location'], '/admin'],
        'clientul → /contul-meu' => [$cerere($port, 'POST', '/login', $jaruri[$eticheta]['cli'],
            ['email' => 'andrei.exemplu@exemplu.ro', 'password' => $parola])['location'], '/contul-meu'],
    ];
    // Adminii cu rol parțial: meniul lor are alte intrări, iar după login ajung pe prima pagină a rolului.
    foreach (['magazin', 'blog', 'emailuri'] as $rolAdmin) {
        $dupaLogin[$eticheta][$rolAdmin] = $cerere($port, 'POST', '/admin/login', $jaruri[$eticheta]['adm-' . $rolAdmin],
            ['email' => $rolAdmin . '@exemplu.ro', 'password' => $parola])['location'];
        $logari[$rolAdmin . '@exemplu.ro → /admin…'] = [$dupaLogin[$eticheta][$rolAdmin], null];
    }
    foreach ($logari as $ce => [$locatie, $asteptata]) {
        $cale = (string) parse_url($locatie, PHP_URL_PATH);
        if ($asteptata !== null ? $cale !== $asteptata : (!str_starts_with($cale, '/admin') || $cale === '/admin/login')) {
            $logariGresite[] = $eticheta . ': ' . $ce . ' a dus la „' . $locatie . '”';
        }
    }
    $cerere($port, 'POST', '/cos/adauga/' . $idMenta, $jaruri[$eticheta]['cli'], ['quantity' => '1']);
}
if ($logariGresite !== []) {
    fwrite(STDERR, 'Logarea a eșuat (parola din ' . $dosarDev . '/admin-parola.txt nu e cea a bazei? refă-o cu tools/dev/instaleaza.sh): '
        . implode('; ', $logariGresite) . "\n");
    exit(1);
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
    // WP-12 (C7): al șaselea selector de imagini, avatarul autorilor blogului.
    ['adm', '/admin/blog/authors'],
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
    // WP-21 (C10, C12): recenziile (filtrul Spam) și cupoanele unice (produsele în masă).
    ['adm', '/admin/products/reviews'],
    ['adm', '/admin/coupons/unique'],
    // WP-21 (C11c, C11d): constructorul de emailuri, un template nou (?type=newsletter de mai jos răspunde cu 302).
    ['adm', '/admin/emails/builder'],
    // Paginile atinse de verificarea din Etapa 1, pasul 4 (editoare, texte ale nucleului, robots, API-uri).
    ['adm', '/admin/pages/new'],
    ['adm', '/admin/products/templates/' . $idSablonProdus . '/builder'],
    ['adm', '/admin/users/security'],
    ['adm', '/admin/emails/builder?type=newsletter'],
    ['viz', '/robots.txt'],
    ['viz', '/api/products/best-sellers?limit=3'],
    // Pagina din bază „checkout/succes” deschisă direct (cod de stare declarat în $coduriDeBioscem).
    ['anon', '/checkout/succes'],
    // Pingul monitorizării (WP-07, nou; declarat în $coduriDeBioscem), și cu „/” la sfârșit.
    ['anon', '/_andaxi/ping'],
    ['anon', '/_andaxi/ping/'],
    // WP-18 (GR-03, DB-15): acordul GDPR, cu datele operatorului din setări (Bioscem: din înghețare, cu valorile private)
    // și lista acordurilor din admin, cu blocul setărilor marcat.
    ['viz', '/acorduri-gdpr'],
    ['adm', '/admin/users/gdpr-agreements'],
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

$db->prepare('INSERT INTO pages (title, slug, html_content, css_content, js_content, is_published, created_at, updated_at)
    VALUES (:title, :slug, :html, \'\', \'\', 1, NOW(), NOW())')->execute([
    'title' => 'Liste goale',
    'slug' => $paginaListe,
    'html' => '<div class="slider"><ul class="slider-dots" data-dots></ul><ol id="pasi"></ol><ul class="lista">'
        . '<li class="placeholder"></li><li class="divider"></li><li><a href="/contact">Contact</a></li></ul></div>',
]);
$pagini[] = ['viz', '/' . $paginaListe];

// Doar față de codul Bioscem (care are deja livrarea 1 SEO): harta site-ului, adresa unică
// și fiecare regulă din harta adreselor vechi a site-ului, cerute de un vizitator nou, fără
// sesiune. Rezultatul lor se rezumă într-o linie, dacă e identic. Regulile vin din pachetul
// site-ului (site-uri/<site>/redirecturi.json) și din harta scrisă în codul de bază
// (Bioscem: config/redirecturi.php): o regulă nouă a Bioscem pe care pachetul n-o are se
// cere și ea, deci se vede (404 față de 301). Mai jos, după pagini, se verifică și că cele
// două liste sunt aceleași.
$hartaPachet = [];
$hartaBaza = null;
if (!$fataDeCommit) {
    $pagini[] = ['anon', '/sitemap.xml'];
    foreach (['/Magazin', '/produs/ceai-de-menta/', '/categorie/Ceaiuri', '/produs/ceai-de-ment', '/blog/', '/magazin?utm_source=test'] as $cale) {
        $pagini[] = ['anon', $cale, 'adresa unică'];
    }
    // Livrarea 2 SEO (Bioscem 6c8c789, 919357a): adresele vechi ale categoriilor duc printr-un
    // singur 301 la /categorie/{slug} (cu sortarea și căutarea păstrate, fără parametrii de
    // reclamă); o categorie care nu există, două categorii sau o adresă WordPress veche rămân
    // cum erau.
    foreach ([
        '/magazin?category=Ceaiuri',
        '/magazin?categorie=ceaiuri&sort=price_asc&gclid=test',
        '/magazin?category=Ceaiuri&q=menta',
        '/magazin/?category=Ceaiuri',
        '/Magazin?category=Ceaiuri',
        '/MAGAZIN/?categorie=Ceaiuri',
        '/magazin?category=Nu-exista',
        '/magazin?category=Ceaiuri&categorie=altceva',
        '/magazin?category[]=Ceaiuri',
        '/produse?category=Ceaiuri',
        '/categorie/ceaiuri?category=Ceaiuri',
    ] as $cale) {
        $pagini[] = ['anon', $cale, 'adresa unică'];
    }
    // Sortarea și filtrul pe pagina categoriei, catalogul din JavaScript, câmpurile SEO ale
    // categoriilor și mărcilor din admin (6c8c789, 93d803f); fluxul Merchant Center (d277727,
    // 1e46f9f); avertismentele coșului (b874cf0, 865630f: coșul vizitatorului nu are niciunul).
    array_push($pagini,
        ['viz', '/categorie/ceaiuri?sort=price_asc'],
        ['viz', '/categorie/ceaiuri?sort=name_desc&q=ceai'],
        ['viz', '/api/shop/catalog?categorie=ceaiuri'],
        ['adm', '/admin/categories'],
        ['anon', '/feed/google-merchant.xml'],
        ['anon', '/feed/google-merchant.xml/'],
        ['viz', '/api/cart/avertismente'],
    );
    // Poza de distribuire (7455b77): varianta 1200×630 a pozei implicite (logo-ul din antet,
    // pus de compara-cu-baza.sh), făcută de codul fiecărui server; o adresă cu altă versiune
    // sau în afara încărcărilor nu există.
    $pozaImplicita = \App\Support\ImagineDistribuire::implicita([], 'http://127.0.0.1:' . $portCurent);
    $caleOg = is_array($pozaImplicita) ? (string) parse_url((string) $pozaImplicita['url'], PHP_URL_PATH) : '';
    if (str_starts_with($caleOg, '/og/')) {
        $pagini[] = ['anon', $caleOg];
        $pagini[] = ['anon', (string) preg_replace('#^/og/[0-9a-f]{12}/#', '/og/000000000000/', $caleOg)];
    } elseif (is_array(\App\Support\DateSite::seo('logo_antet', null))) {
        $diferiteInainte[] = 'pagini        poza de distribuire implicită';
        echo '  DIFERĂ poza de distribuire implicită: nu are adresa /og/… (', json_encode($pozaImplicita), "); "
            . "logo-ul de test lipsește? (compara-cu-baza.sh)\n";
    }
    $pagini[] = ['anon', '/og/0123456789ab/etc/passwd'];
    $hartaPachet = (array) \App\Support\DateSite::json('redirecturi.json');
    $fisierHartaBaza = $dosarBaza . '/config/redirecturi.php';
    if (is_file($fisierHartaBaza)) {
        $hartaBaza = (array) (require $fisierHartaBaza);
    }
    $cereriHarta = [];
    foreach ([$hartaPachet, (array) $hartaBaza] as $harta) {
        foreach ((array) ($harta['exacte'] ?? []) as $regula) {
            $cereriHarta[(string) ($regula[0] ?? '/')] = true;
        }
        foreach ((array) ($harta['prefixe'] ?? []) as $regula) {
            $cereriHarta[rtrim((string) ($regula[0] ?? '/'), '/') . '/test'] = true;
        }
    }
    foreach (array_keys($cereriHarta) as $cale) {
        $pagini[] = ['anon', (string) $cale, 'harta adreselor vechi'];
    }
}

$identice = 0;
// Anteturile (normalizate) în capul fișierelor comparate, ca diff-ul să le arate și pe ele.
$cuAntete = static fn (array $antete, string $corp): string => "ANTETE\n" . implode("\n", $antete) . "\n\nCORP\n" . $corp;
$diferite = $diferiteInainte ?? [];
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
    // O pagină cu codul de stare declarat ($coduriDeBioscem): doar codurile și textul ei.
    $problemeCod = [];
    $declaratieCod = diferente_coduri($listeCoduri, $cale, $r['baza']['cod'], $r['baza']['corp'], $r['curent']['cod'],
        $r['curent']['corp'], $problemeCod);
    if ($declaratieCod !== null) {
        $coduriGasite[$declaratieCod] = ($coduriGasite[$declaratieCod] ?? 0) + 1;
        $eticheta = str_pad($rol, 13) . $cale;
        if ($problemeCod === []) {
            $identice++;
            $diferentePorturi[$eticheta] = $declaratieCod;
            echo '  OK    ', $r['baza']['cod'], '→', $r['curent']['cod'], '  ', $eticheta, '  (', $declaratieCod, ")\n";
        } else {
            $diferite[] = $eticheta;
            echo '  DIFERĂ ', $r['baza']['cod'], '→', $r['curent']['cod'], '  ', $eticheta, ': ', implode('; ', $problemeCod), "\n";
        }
        continue;
    }
    $gasite = [];
    $porturiGasite = [];
    $problemePorturi = [];
    $a = $normalizeaza($r['baza']['corp']);
    $corpCurent = $fataDeCommit ? $faraSeo($cale, $r['curent']['corp'], $gasite) : $r['curent']['corp'];
    // O redirecționare (3xx) nu e o pagină: declarațiile de texte nu se aplică pe ea (de exemplu „/admin” al unui rol
    // parțial, care duce la pagina rolului); corpul ei se compară neatins.
    $esteRedirectionare = $r['curent']['cod'] >= 300 && $r['curent']['cod'] < 400;
    $b = $normalizeaza($esteRedirectionare ? $corpCurent : $faraDeclarate($cale, $corpCurent, $porturiGasite, $problemePorturi));
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
    $anteteBaza = $normalizeazaAntete($r['baza']['antete']);
    $anteteCurente = $normalizeazaAntete($r['curent']['antete']);
    if ($problemeSeo === [] && $problemePorturi === [] && $r['baza']['cod'] === $r['curent']['cod']
        && $locatieBaza === $r['curent']['location'] && $anteteBaza === $anteteCurente && $a === $b) {
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
    file_put_contents($dosarDiff . '/' . $nume . '.baza.html', $cuAntete($anteteBaza, $a));
    file_put_contents($dosarDiff . '/' . $nume . '.curent.html', $cuAntete($anteteCurente, $b));
    $diff = (string) shell_exec('diff -u ' . escapeshellarg($dosarDiff . '/' . $nume . '.baza.html') . ' '
        . escapeshellarg($dosarDiff . '/' . $nume . '.curent.html') . ' 2>&1');
    file_put_contents($dosarDiff . '/' . $nume . '.diff', $diff);
    $linii = substr_count($diff, "\n- ") + substr_count($diff, "\n+ ") + preg_match_all('/^[-+](?![-+])/m', $diff);
    $diferite[] = $eticheta;
    $anteteDiferite = $anteteBaza === $anteteCurente ? '' : '  (antete: doar în bază ['
        . implode('; ', array_diff($anteteBaza, $anteteCurente)) . '], doar acum [' . implode('; ', array_diff($anteteCurente, $anteteBaza))
        . ']' . (array_diff($anteteBaza, $anteteCurente) === [] && array_diff($anteteCurente, $anteteBaza) === [] ? ', altă ordine' : '') . ')';
    echo '  DIFERĂ ', $r['baza']['cod'], '→', $r['curent']['cod'], '  ', $eticheta,
        $locatieBaza !== $r['curent']['location'] ? '  (Location: ' . $locatieBaza . ' → ' . $r['curent']['location'] . ')' : '',
        $anteteDiferite, '  (', $dosarDiff, '/', $nume, '.diff)', "\n";
}
foreach ($grupuriIdentice as $grup => $coduri) {
    ksort($coduri);
    echo '  OK    ', str_pad('anon', 13), $grup, ': ', array_sum($coduri), ' adrese identice (',
        implode(', ', array_map(static fn ($cod, $n) => $n . ' × ' . $cod, array_keys($coduri), $coduri)), ")\n";
}
// Fiecare cod de stare declarat: pagina lui cerută exact o dată (altfel declarația nu dovedește nimic).
foreach ($listeCoduri as $numeLista => $lista) {
    foreach ($lista as $intrare) {
        $eticheta = $numeLista . ' „' . (string) ($intrare['nume'] ?? '') . '”';
        if (($coduriGasite[$eticheta] ?? 0) !== 1) {
            $diferite[] = $eticheta;
            echo '  DIFERĂ ', $eticheta, ': pagina ', (string) ($intrare['cale'] ?? ''), ' cerută de ', $coduriGasite[$eticheta] ?? 0,
                " ori, așteptat o dată (adaug-o în lista de pagini)\n";
        }
    }
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
// Harta adreselor vechi scrisă în codul de bază și cea din pachetul site-ului: aceeași listă
// (adresa veche, ținta, codul; motivul e doar o notă). Cererile de mai sus arată ce face
// fiecare regulă; aici se vede și o regulă care ar lipsi dintr-o parte fără să se vadă în
// răspunsuri (de exemplu una spre o pagină care între timp există).
if (is_array($hartaBaza)) {
    $triplete = static function (array $harta, string $tip): array {
        $rezultat = [];
        foreach ((array) ($harta[$tip] ?? []) as $regula) {
            $regula = array_values((array) $regula);
            $rezultat[] = json_encode([(string) ($regula[0] ?? ''), $regula[1] ?? null, (int) ($regula[2] ?? 0)],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        sort($rezultat);
        return $rezultat;
    };
    foreach (['exacte', 'prefixe'] as $tip) {
        $inBaza = $triplete($hartaBaza, $tip);
        $inPachet = $triplete($hartaPachet, $tip);
        $eticheta = str_pad('harta', 13) . 'reguli ' . $tip . ': config/redirecturi.php din bază = redirecturi.json din pachet';
        if ($inBaza === $inPachet) {
            $identice++;
            echo '  OK    ', $eticheta, ' (', count($inBaza), ")\n";
            continue;
        }
        $diferite[] = $eticheta;
        echo '  DIFERĂ ', $eticheta, ': doar în bază [', implode(', ', array_diff($inBaza, $inPachet)), '], doar în pachet [',
            implode(', ', array_diff($inPachet, $inBaza)), ']', count($inBaza) !== count(array_unique($inBaza))
            || count($inPachet) !== count(array_unique($inPachet)) ? ' (reguli scrise de două ori)' : '', "\n";
    }
}

/**
 * Doar față de codul Bioscem: salvările din admin (tools/dev/lib/salvari-admin.php). Paginile de
 * mai sus sunt doar GET-uri, deci o schimbare care se vede abia la salvare (ce rămâne în bază
 * după un POST) nu le atinge. Fiecare caz pregătește câteva rânduri, apoi trimite aceeași
 * cerere întâi codului de bază, apoi codului curent, cu baza pusă la loc înainte de fiecare și
 * la final; se compară răspunsurile (cod, Location, pagina) și rândurile atinse. „verifica” se
 * uită la rezultatul codului de bază, ca un caz să nu poată trece în gol:
 *  - fiecare salvare trebuie să se vadă în bază: POST-ul schimbă și un câmp pe care altfel nu-l
 *    atinge nimic (rezumatul postării; ofertele noi și cheile lor), deci o salvare care nu scrie
 *    nimic (aceeași redirecționare, rândurile pregătite neatinse) pică;
 *  - un caz de tip „schimbare” (implicit) trebuie să arate schimbarea commit-ului „din”: fără
 *    acel commit, rezultatul codului de bază ar fi altul;
 *  - un caz de tip „regresie” cere rezultatul de pe o ramură pe care commit-ul „din” nu o
 *    schimbă (același înainte și după el); o ramură atinsă greșit la portare o prinde
 *    comparația cu codul curent, o schimbare a ei în Bioscem o prinde „verifica”.
 *  - un caz al unui port din alt site („sursa”, de exemplu vertij), de tip „schimbare”: Bioscem nu are
 *    commit-ul, deci „verifica” cere pe codul Bioscem ce dădea el înaintea portului, iar pagina codului
 *    curent diferă doar prin textele declarate pentru paginile cazului ($porturiVertij).
 *  - un caz de tip „vederi” nu salvează nimic: pune baza într-o stare pe care lista de pagini nu o are (de
 *    exemplu paginile din bază „404” și „checkout/succes” nepublicate, ca să se vadă vederile de rezervă ale
 *    nucleului) și cere pagini; „verifica” cere pe codul de bază semnele vederilor (altfel cazul ar trece în gol),
 *    iar „din” spune ce pachet de lucru l-a adus.
 * O cerere e [metodă, cale, date, rol, 'pauza' => secunde]: rolul „adm” (implicit, logat la
 * începutul cazului), „viz” (un vizitator nou, de exemplu pentru coș și comandă) sau „viz-acord” (un
 * vizitator nou care a acceptat toate cookie-urile, cookie-ul lui CookieConsent pus dinainte); „pauza”
 * așteaptă înainte (garda anti-robot a checkout-ului). Ea poate fi și o funcție care o face din
 * răspunsurile de până atunci (tokenul formularului, numărul comenzii). „curata” scoate din
 * răspunsuri ce diferă de la o rulare la alta (ora unei comenzi), „la_final” curăță după caz
 * (de exemplu cache-ul fluxului), „sursa” spune al cui e commit-ul (implicit Bioscem; vertij
 * pentru un port din vertij, cu rezultatul cerut tot pe codul Bioscem).
 */
if (!$fataDeCommit && $siteBaza === 'bioscem') {
    require_once __DIR__ . '/lib/salvari-admin.php';
    $citeste = static function (string $sql, array $param = []) use ($db): array {
        $stmt = $db->prepare($sql);
        $stmt->execute($param);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    $tokenCache = ['tabel' => 'settings', 'unde' => '`key` = \'cache_assets_version_token\'', 'cheie' => 'key'];

    // Oferte BBD (Bioscem b632294): cheia primită se păstrează; o ofertă nouă care ar primi cheia
    // păstrată a alteia (sau o cheie dublată) primește alta; o cheie care nu e text nu strică nimic.
    $idProdusBbd = (int) $unu('SELECT id FROM products WHERE bbd_enabled = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');
    $produsBbd = $citeste('SELECT * FROM products WHERE id = :id', ['id' => $idProdusBbd])[0] ?? [];
    $cheieCalculata = static fn (string $data, string $lot, string $pret): string => substr(sha1($data . '|' . $lot . '|' . $pret), 0, 20);
    $cheie1 = $cheieCalculata('2027-03-01', 'LDEMO1', '11.90');
    $cheie2 = $cheieCalculata('2027-05-01', 'LDEMO2', '14.00');
    $oferteSalvate = [
        ['key' => $cheie1, 'date' => '2027-03-01', 'lot' => 'LDEMO1', 'label' => 'Expiră la data de: 01.03.2027', 'reduced_price' => 11.9, 'stock' => 8],
        ['key' => $cheie2, 'date' => '2027-05-01', 'lot' => 'LDEMO2', 'label' => 'Expiră la data de: 01.05.2027', 'reduced_price' => 14.0, 'stock' => 15],
    ];
    $oferteTrimise = [
        // Prețul redus schimbat (11,90 → 12,50): cheia rămâne, deci și rezervările comenzilor.
        ['key' => $cheie1, 'date' => '2027-03-01', 'lot' => 'LDEMO1', 'reduced_price' => '12.50', 'stock' => '8'],
        ['key' => $cheie2, 'date' => '2027-05-01', 'lot' => 'LDEMO2', 'reduced_price' => '14.00', 'stock' => '15'],
        // Ofertă nouă cu datele vechi ale primei: cheia calculată ar fi exact cea păstrată a primei.
        ['date' => '2027-03-01', 'lot' => 'LDEMO1', 'reduced_price' => '11.90', 'stock' => '3'],
        ['key' => $cheie2, 'date' => '2027-06-01', 'lot' => 'LDEMO3', 'reduced_price' => '9.00', 'stock' => '2'],
        ['key' => ['nu', 'e', 'text'], 'date' => '2027-07-01', 'lot' => 'LDEMO4', 'reduced_price' => '8.00', 'stock' => '1'],
        ['key' => 'ABCdef123', 'date' => '2027-08-01', 'lot' => 'LDEMO5', 'reduced_price' => '7.00', 'stock' => '1'],
        ['key' => 'nu-e-buna', 'date' => '2027-09-01', 'lot' => 'LDEMO6', 'reduced_price' => '6.00', 'stock' => '1'],
    ];
    $corpProdus = [
        'name' => (string) ($produsBbd['name'] ?? ''),
        'slug' => (string) ($produsBbd['slug'] ?? ''),
        'sku' => (string) ($produsBbd['sku'] ?? ''),
        'category_id' => (string) ($produsBbd['category_id'] ?? ''),
        'product_template_id' => (string) ($produsBbd['product_template_id'] ?? ''),
        'price' => (string) ($produsBbd['price'] ?? '0'),
        'stock' => (string) ($produsBbd['stock'] ?? '0'),
        'vat_percent' => (string) ($produsBbd['vat_percent'] ?? '19'),
        'vat_included' => (string) ($produsBbd['vat_included'] ?? '1'),
        'weight_grams' => (string) ($produsBbd['weight_grams'] ?? ''),
        'brand' => (string) ($produsBbd['brand'] ?? ''),
        'short_description' => (string) ($produsBbd['short_description'] ?? ''),
        'description' => (string) ($produsBbd['description'] ?? ''),
        'image_url' => (string) ($produsBbd['image_url'] ?? ''),
        'is_active' => '1',
        'bbd_enabled' => '1',
        'bbd_entries_json' => (string) json_encode($oferteTrimise, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];

    // Blog (Bioscem 89dcc4e, b3781a4): o postare în trei categorii, cu principala a doua din listă.
    $idPostare = (int) $unu('SELECT id FROM blog_posts WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
    $categoriiBlog = array_map('intval', array_column($citeste('SELECT id FROM blog_categories ORDER BY id LIMIT 3'), 'id'));
    [$cat1, $cat2, $cat3] = array_pad($categoriiBlog, 3, 0);
    $numeCat2 = $unu('SELECT name FROM blog_categories WHERE id = ' . (int) $cat2);
    $idMaxPostari = (int) $unu('SELECT COALESCE(MAX(id), 0) FROM blog_posts');
    $postare = $citeste('SELECT * FROM blog_posts WHERE id = :id', ['id' => $idPostare])[0] ?? [];
    $tabelePostare = [
        ['tabel' => 'blog_posts', 'unde' => 'id = :p1 OR id > :m1', 'param' => ['p1' => $idPostare, 'm1' => $idMaxPostari], 'cheie' => 'id'],
        ['tabel' => 'blog_post_categories', 'unde' => 'post_id = :p2 OR post_id > :m2', 'param' => ['p2' => $idPostare, 'm2' => $idMaxPostari]],
        ['tabel' => 'seo_pages', 'unde' => 'page_type = \'blog_post\' AND (page_ref = :p3 OR CAST(page_ref AS UNSIGNED) > :m3)',
            'param' => ['p3' => (string) $idPostare, 'm3' => $idMaxPostari], 'cheie' => 'id'],
        $tokenCache,
    ];
    $pregatestePostarea = static function (PDO $db) use ($idPostare, $cat1, $cat2, $cat3, $numeCat2): void {
        $db->prepare('UPDATE blog_posts SET category_id = :c, category = :n WHERE id = :id')
            ->execute(['c' => $cat2, 'n' => $numeCat2, 'id' => $idPostare]);
        $db->prepare('DELETE FROM blog_post_categories WHERE post_id = :id')->execute(['id' => $idPostare]);
        foreach ([$cat1, $cat2, $cat3] as $cid) {
            $db->prepare('INSERT INTO blog_post_categories (post_id, category_id) VALUES (:p, :c)')->execute(['p' => $idPostare, 'c' => $cid]);
        }
    };
    $starePostare = static function (PDO $db, int $id) use ($citeste): array {
        $rand = $citeste('SELECT * FROM blog_posts WHERE id = :id', ['id' => $id])[0] ?? [];
        return [
            'postare' => salvari_fara_coloane($rand),
            'categorii' => array_map('intval', array_column(
                $citeste('SELECT category_id FROM blog_post_categories WHERE post_id = :id ORDER BY category_id', ['id' => $id]), 'category_id')),
        ];
    };
    // Re-salvarea schimbă și rezumatul: așa se vede că salvarea a ajuns în bază (handler-ul
    // redirecționează la fel și când UPDATE-ul nu reușește, iar categoriile pregătite pot fi
    // exact cele așteptate și fără nicio salvare).
    $rezumatSalvat = 'Rezumat schimbat la re-salvare (compara-cu-baza)';
    $salvata = static fn (array $stare): array => ($stare['postare']['excerpt'] ?? null) === $rezumatSalvat
        ? [] : ['salvarea nu a ajuns în bază: rezumatul postării nu e cel trimis ('
            . json_encode($stare['postare']['excerpt'] ?? null, JSON_UNESCAPED_UNICODE) . ')'];
    $corpPostare = static fn (array $categorii, array $extra = []): array => [
        'action' => 'save',
        'id' => (string) $idPostare,
        'title' => (string) ($postare['title'] ?? ''),
        'slug' => (string) ($postare['slug'] ?? ''),
        'excerpt' => $rezumatSalvat,
        'content' => (string) ($postare['content'] ?? ''),
        'reading_minutes' => (string) ($postare['reading_minutes'] ?? '1'),
        'published_at' => (string) ($postare['published_at'] ?? ''),
        'author_id' => (string) ($postare['author_id'] ?? ''),
        'template_id' => (string) ($postare['template_id'] ?? ''),
        'featured_image_url' => (string) ($postare['featured_image_url'] ?? ''),
        'category_ids' => array_map('strval', $categorii),
    ] + ((int) ($postare['is_published'] ?? 0) === 1 ? ['is_published' => '1'] : []) + $extra;

    $salvari = [
        [
            'nume' => 'oferte BBD: cheia ofertei rămâne la salvarea produsului',
            'din' => 'b632294',
            'tabele' => [
                ['tabel' => 'products', 'unde' => 'id = :p1', 'param' => ['p1' => $idProdusBbd], 'cheie' => 'id'],
                ['tabel' => 'product_category_links', 'unde' => 'product_id = :p2', 'param' => ['p2' => $idProdusBbd], 'cheie' => 'id'],
                ['tabel' => 'product_extra_field_values', 'unde' => 'product_id = :p3', 'param' => ['p3' => $idProdusBbd], 'cheie' => 'id'],
                ['tabel' => 'seo_pages', 'unde' => 'page_type = \'product\' AND page_ref = :p4', 'param' => ['p4' => (string) $idProdusBbd], 'cheie' => 'id'],
                $tokenCache,
            ],
            'pregateste' => static function (PDO $db) use ($idProdusBbd, $oferteSalvate): void {
                $db->prepare('UPDATE products SET bbd_enabled = 1, bbd_entries_json = :j WHERE id = :id')->execute([
                    'j' => json_encode($oferteSalvate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id' => $idProdusBbd]);
            },
            'cereri' => [['POST', '/admin/products/' . $idProdusBbd . '/update', $corpProdus]],
            'stare' => static function (PDO $db) use ($citeste, $idProdusBbd): array {
                $rand = $citeste('SELECT * FROM products WHERE id = :id', ['id' => $idProdusBbd])[0] ?? [];
                return [
                    'produs' => salvari_fara_coloane($rand),
                    'categorii' => array_map('intval', array_column(
                        $citeste('SELECT category_id FROM product_category_links WHERE product_id = :id ORDER BY category_id', ['id' => $idProdusBbd]), 'category_id')),
                ];
            },
            'verifica' => static function (array $stare) use ($cheie1): array {
                $oferte = json_decode((string) ($stare['produs']['bbd_entries_json'] ?? ''), true);
                if (!is_array($oferte)) {
                    return ['bbd_entries_json nu e JSON'];
                }
                $cheie = static function (string $lot, float $pret) use ($oferte): ?string {
                    foreach ($oferte as $oferta) {
                        if (($oferta['lot'] ?? '') === $lot && abs((float) ($oferta['reduced_price'] ?? 0) - $pret) < 0.001) {
                            return (string) ($oferta['key'] ?? '');
                        }
                    }
                    return null;
                };
                $chei = array_map(static fn ($o): string => (string) ($o['key'] ?? ''), $oferte);
                $probleme = [];
                if ($cheie('LDEMO1', 12.5) !== $cheie1) {
                    $probleme[] = 'oferta cu prețul schimbat nu și-a păstrat cheia';
                }
                if (in_array($cheie('LDEMO1', 11.9), [null, '', $cheie1], true)) {
                    $probleme[] = 'oferta nouă cu datele vechi are cheia celei păstrate (sau niciuna)';
                }
                if (count($oferte) !== 7 || count(array_unique($chei)) !== 7 || in_array('', $chei, true)) {
                    $probleme[] = 'așteptam 7 oferte cu 7 chei diferite: ' . json_encode($chei);
                }
                if ($cheie('LDEMO5', 7.0) !== 'abcdef123') {
                    $probleme[] = 'cheia „ABCdef123” nu a devenit „abcdef123”';
                }
                return $probleme;
            },
        ],
        [
            'nume' => 'blog: editorul bifează toate categoriile postării',
            'din' => '89dcc4e',
            'tabele' => $tabelePostare,
            'pregateste' => $pregatestePostarea,
            'cereri' => [['GET', '/admin/blog/posts/editor?post=' . $idPostare, []]],
            'stare' => static fn (PDO $db): array => [],
            'verifica' => static function (array $stare, array $raspunsuri): array {
                $bifate = preg_match_all('#name="category_ids\[\]" value="\d+" checked#', (string) ($raspunsuri[0]['corp'] ?? ''));
                return $bifate === 3 ? [] : ['editorul bifează ' . $bifate . ' categorii din 3'];
            },
        ],
        [
            // Fără b3781a4, principala ar deveni prima bifată ($cat1).
            'nume' => 'blog: re-salvarea păstrează categoriile și categoria principală',
            'din' => 'b3781a4',
            'tabele' => $tabelePostare,
            'pregateste' => $pregatestePostarea,
            'cereri' => [['POST', '/admin/blog/posts', $corpPostare([$cat1, $cat2, $cat3])]],
            'stare' => static fn (PDO $db): array => $starePostare($db, $idPostare),
            'verifica' => static fn (array $stare): array => array_merge($salvata($stare),
                (int) ($stare['postare']['category_id'] ?? 0) === $cat2 && $stare['categorii'] === [$cat1, $cat2, $cat3]
                    ? [] : ['principala sau categoriile s-au schimbat: ' . json_encode($stare)]),
        ],
        [
            // Ramură pe care b3781a4 nu o schimbă: principala nu mai e bifată.
            'nume' => 'blog: principala debifată, principala devine prima bifată',
            'din' => 'b3781a4',
            'tip' => 'regresie',
            'tabele' => $tabelePostare,
            'pregateste' => $pregatestePostarea,
            'cereri' => [['POST', '/admin/blog/posts', $corpPostare([$cat1, $cat3])]],
            'stare' => static fn (PDO $db): array => $starePostare($db, $idPostare),
            'verifica' => static fn (array $stare): array => array_merge($salvata($stare),
                (int) ($stare['postare']['category_id'] ?? 0) === $cat1 && $stare['categorii'] === [$cat1, $cat3]
                    ? [] : ['așteptam principala ' . $cat1 . ': ' . json_encode($stare)]),
        ],
        [
            // Ramură pe care b3781a4 nu o schimbă: formularul trimite category_id (principala păstrată ar fi $cat2).
            'nume' => 'blog: categoria trimisă explicit (category_id) devine principala',
            'din' => 'b3781a4',
            'tip' => 'regresie',
            'tabele' => $tabelePostare,
            'pregateste' => $pregatestePostarea,
            'cereri' => [['POST', '/admin/blog/posts', $corpPostare([$cat1, $cat2, $cat3], ['category_id' => (string) $cat3])]],
            'stare' => static fn (PDO $db): array => $starePostare($db, $idPostare),
            'verifica' => static fn (array $stare): array => array_merge($salvata($stare),
                (int) ($stare['postare']['category_id'] ?? 0) === $cat3 ? [] : ['așteptam principala ' . $cat3 . ': ' . json_encode($stare)]),
        ],
        [
            'nume' => 'blog: duplicarea copiază categoriile și categoria principală',
            'din' => '89dcc4e',
            'tabele' => $tabelePostare,
            'pregateste' => $pregatestePostarea,
            'cereri' => [['POST', '/admin/blog/posts', ['action' => 'duplicate', 'id' => (string) $idPostare]]],
            'stare' => static function (PDO $db) use ($idMaxPostari, $starePostare, $unu): array {
                // Id-ul copiei crește la fiecare rulare: se compară fără el (și fără ora copierii).
                $idCopie = (int) $unu('SELECT COALESCE(MAX(id), 0) FROM blog_posts WHERE id > ' . $idMaxPostari);
                $stare = $starePostare($db, $idCopie);
                $stare['postare'] = salvari_fara_coloane($stare['postare'], ['id', 'published_at']);
                $stare['_inlocuiri'] = $idCopie > 0 ? ['post=' . $idCopie => 'post=<COPIA>'] : [];
                return $stare;
            },
            'verifica' => static fn (array $stare): array => (int) ($stare['postare']['category_id'] ?? 0) === $cat2
                && $stare['categorii'] === [$cat1, $cat2, $cat3] ? [] : ['copia nu are categoriile originalului: ' . json_encode($stare)],
        ],
    ];

    // ── Livrarea 2 SEO și avertismentul pe produs (WP-R, 20 oct 2026: Bioscem 6c8c789 … 4254ca4) ──
    // Paginile de mai sus le văd cu datele demo; aici, cu rândurile pregătite: un antet cu
    // logo-ul și linkuri vechi spre categorii, câmpurile SEO ale categoriilor și mărcilor,
    // numele magazinului, livrarea cu prețuri fixe, avertismentul unui produs, o comandă.
    $idMentaInt = (int) $idMenta;
    $produsMenta = $citeste('SELECT * FROM products WHERE id = :id', ['id' => $idMentaInt])[0] ?? [];
    $corpPentru = static fn (array $produs, array $extra = []): array => $extra + [
        'name' => (string) ($produs['name'] ?? ''),
        'slug' => (string) ($produs['slug'] ?? ''),
        'sku' => (string) ($produs['sku'] ?? ''),
        'category_id' => (string) ($produs['category_id'] ?? ''),
        'product_template_id' => (string) ($produs['product_template_id'] ?? ''),
        'price' => (string) ($produs['price'] ?? '0'),
        'stock' => (string) ($produs['stock'] ?? '0'),
        'vat_percent' => (string) ($produs['vat_percent'] ?? '19'),
        'vat_included' => (string) ($produs['vat_included'] ?? '1'),
        'weight_grams' => (string) ($produs['weight_grams'] ?? ''),
        'brand' => (string) ($produs['brand'] ?? ''),
        'short_description' => (string) ($produs['short_description'] ?? ''),
        'description' => (string) ($produs['description'] ?? ''),
        'image_url' => (string) ($produs['image_url'] ?? ''),
        'is_active' => '1',
    ];
    $tabeleProdus = static fn (int $id): array => [
        ['tabel' => 'products', 'unde' => 'id = :p1', 'param' => ['p1' => $id], 'cheie' => 'id'],
        ['tabel' => 'product_category_links', 'unde' => 'product_id = :p2', 'param' => ['p2' => $id], 'cheie' => 'id'],
        ['tabel' => 'product_extra_field_values', 'unde' => 'product_id = :p3', 'param' => ['p3' => $id], 'cheie' => 'id'],
        ['tabel' => 'seo_pages', 'unde' => 'page_type = \'product\' AND page_ref = :p4', 'param' => ['p4' => (string) $id], 'cheie' => 'id'],
        ['tabel' => 'product_warnings', 'unde' => 'product_id = :p5', 'param' => ['p5' => $id], 'cheie' => 'product_id'],
    ];
    $titluAvertisment = 'ATENȚIE – PRODUS DE TEST';
    $etichetaAvertisment = 'Am citit avertismentul de test';
    $punAvertisment = static function (PDO $db, int $id) use ($titluAvertisment, $etichetaAvertisment): void {
        $db->prepare('DELETE FROM product_warnings WHERE product_id = ?')->execute([$id]);
        $db->prepare('INSERT INTO product_warnings (product_id, title, body, checkbox_label, updated_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, $titluAvertisment, "Primul paragraf al avertismentului de test.\n\nAl doilea paragraf: produsul se păstrează la rece.",
                $etichetaAvertisment, '2026-10-20 10:00:00']);
    };
    $starePrimuluiAvertisment = static function (PDO $db, int $id) use ($citeste): array {
        $rand = $citeste('SELECT * FROM product_warnings WHERE product_id = :id', ['id' => $id])[0] ?? null;
        return is_array($rand) ? salvari_fara_coloane($rand) : [];
    };

    // Antetul, meniul și subsolul (HTML salvat în setări): linkurile vechi spre categorii și logo-ul.
    $setariDesign = ['design_header_html', 'design_menu_html', 'design_footer_html'];
    $tabeleDesign = [
        ['tabel' => 'settings', 'unde' => '`key` IN (\'' . implode('\', \'', $setariDesign) . '\')', 'cheie' => 'key'],
        $tokenCache,
    ];
    $logoAntet = (array) \App\Support\DateSite::seo('logo_antet', []);
    $caleLogo = (string) ($logoAntet['png'] ?? '/uploads/gallery/logo.png');
    $antetDeTest = '<header class="antet-test"><a href="/"><img src="' . $caleLogo . '" alt="Logo" class="logo-antet"></a>'
        . '<nav><a href="/magazin?category=Ceaiuri">Ceaiuri</a> <a href="https://' . (string) parse_url((string) \App\Support\Env::get('APP_URL', ''), PHP_URL_HOST)
        . '/magazin?categorie=miere&amp;sort=price_asc">Miere</a> <a href="/magazin?category=Nu-exista">Altceva</a></nav>{{menu}}</header>';
    $meniuDeTest = '<ul class="meniu-test"><li><a href="/magazin?category=Suplimente">Suplimente</a></li><li><a href="/magazin">Magazin</a></li></ul>';
    $subsolDeTest = '<footer class="subsol-test"><a href="/magazin/?category=Cadouri">Cadouri</a> <a href="/magazin?category=Ceaiuri&amp;q=menta">Căutare</a></footer>';

    // Livrarea cu prețuri fixe (Setări livrare): tariful la adresă și pragul de gratuitate.
    $setariLivrare = ['shipping_fixed_enabled' => '1', 'shipping_fixed_base' => '19.99', 'shipping_fixed_fanbox' => '12.50',
        'shipping_fixed_fanbox_enabled' => '1', 'shipping_free_bucharest' => '80', 'shipping_free_province' => '80'];
    $tabeleLivrare = [
        ['tabel' => 'settings', 'unde' => '`key` IN (\'' . implode('\', \'', array_keys($setariLivrare)) . '\')', 'cheie' => 'key'],
        $tokenCache,
    ];
    $stergeFluxul = static function () use ($radacina, $dosarBaza): void {
        foreach ([$radacina, $dosarBaza] as $dosar) {
            @unlink($dosar . '/storage/cache/feed-google-merchant.xml');
        }
    };

    // Comanda: tot ce scrie plasarea ei (setările cu numărul comenzii, comanda, liniile, confirmările,
    // emailurile, jurnalul anti-robot, coșul abandonat), pus la loc după fiecare server.
    $idMaxComenzi = (int) $unu('SELECT COALESCE(MAX(id), 0) FROM orders');
    $idMaxEmailuri = (int) $unu('SELECT COALESCE(MAX(id), 0) FROM email_logs');
    $idMaxCosuri = (int) $unu('SELECT COALESCE(MAX(id), 0) FROM cart_abandonments');
    $idMaxJurnal = (int) $unu('SELECT COALESCE(MAX(id), 0) FROM checkout_submit_logs');
    // Încercările din ultimele minute (scrise de testele de dinainte) ar putea atinge limita
    // anti-robot (aceeași adresă IP): se scot pe durata cazului și se pun la loc la final.
    $deLaJurnal = date('Y-m-d H:i:s', time() - 30 * 60);
    $tabeleComanda = array_merge($tabeleProdus($idMentaInt), [
        ['tabel' => 'settings', 'unde' => '1 = 1', 'cheie' => 'key'],
        ['tabel' => 'orders', 'unde' => 'id > :o1', 'param' => ['o1' => $idMaxComenzi], 'cheie' => 'id'],
        ['tabel' => 'order_items', 'unde' => 'order_id > :o2', 'param' => ['o2' => $idMaxComenzi], 'cheie' => 'id'],
        ['tabel' => 'order_product_warnings', 'unde' => 'order_id > :o3', 'param' => ['o3' => $idMaxComenzi], 'cheie' => 'id'],
        ['tabel' => 'email_logs', 'unde' => 'id > :e1', 'param' => ['e1' => $idMaxEmailuri], 'cheie' => 'id'],
        ['tabel' => 'cart_abandonments', 'unde' => 'id > :c1', 'param' => ['c1' => $idMaxCosuri], 'cheie' => 'id'],
        ['tabel' => 'checkout_submit_logs', 'unde' => 'id > :j1 OR created_at >= :j2', 'param' => ['j1' => $idMaxJurnal, 'j2' => $deLaJurnal], 'cheie' => 'id'],
    ]);
    $dosarMail = $dosarDev . '/mail';
    $mailInainte = [];
    $pregatesteComanda = static function (PDO $db) use ($punAvertisment, $idMentaInt, $deLaJurnal, $dosarMail, &$mailInainte): void {
        $punAvertisment($db, $idMentaInt);
        $db->prepare('DELETE FROM checkout_submit_logs WHERE created_at >= ?')->execute([$deLaJurnal]);
        $mailInainte = glob($dosarMail . '/*.eml') ?: [];
    };
    $tokenDin = static function (string $corp): array {
        preg_match('/name="checkout_form_token"\s+value="([^"]*)"/', $corp, $t);
        preg_match('/name="checkout_form_rendered_at"\s+value="([^"]*)"/', $corp, $m);
        return ['checkout_form_token' => (string) ($t[1] ?? ''), 'checkout_form_rendered_at' => (string) ($m[1] ?? '')];
    };
    $dateComanda = [
        'billing_first_name' => 'Test', 'billing_last_name' => 'Avertisment', 'billing_phone' => '0700000777',
        'billing_email' => 'avertisment@exemplu.ro', 'billing_street' => 'Strada Exemplului', 'billing_street_no' => '7',
        'billing_city' => 'Cluj-Napoca', 'billing_county' => 'Cluj', 'billing_postcode' => '400000',
        'shipping_same_as_billing' => '1', 'payment_method' => 'cod', 'accept_terms' => '1',
    ];
    // Ora apare în pagini și emailuri (comanda se plasează pe rând, pe cele două servere).
    $faraOra = static fn (string $text): string => (string) preg_replace('/\b([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?\b/', '<ORA>', $text);
    $stareComanda = static function (PDO $db) use ($citeste, $idMaxComenzi, $idMaxEmailuri, $idMaxJurnal, $dosarMail, &$mailInainte, $faraOra): array {
        $comenzi = $citeste('SELECT * FROM orders WHERE id > :id ORDER BY id', ['id' => $idMaxComenzi]);
        $inlocuiri = [];
        foreach ($comenzi as $comanda) {
            $id = (int) $comanda['id'];
            // Id-ul comenzii crește la fiecare plasare (codul de bază, apoi cel curent): se compară fără el.
            foreach (['"id":' . $id . ',', '"order_id":' . $id . ',', '&quot;id&quot;:' . $id . ',', '&quot;order_id&quot;:' . $id . ',',
                'data-order-id="' . $id . '"', 'data-awb-comanda="' . $id . '"', '#' . $id . ' /', '/admin/orders/' . $id . '/',
                'order=' . $id . '"', 'order=' . $id . '&', 'comanda=' . $id . '"'] as $text) {
                $inlocuiri[$text] = str_replace((string) $id, '<COMANDA>', $text);
            }
        }
        // Fără id-uri și cu momentele (ora plasării, acceptarea termenilor …) doar ca „<MOMENT>”.
        $fara = static fn (array $randuri, array $coloane): array => array_map(
            static fn (array $r): array => array_map(
                static fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v) === 1 ? '<MOMENT>' : $v,
                salvari_fara_coloane($r, $coloane)), $randuri);
        $emailuri = [];
        foreach (array_diff(glob($dosarMail . '/*.eml') ?: [], $mailInainte) as $fisier) {
            $text = (string) @file_get_contents($fisier);
            @unlink($fisier);
            $emailuri[] = $faraOra(strtr($text, $inlocuiri));
        }
        sort($emailuri);
        return [
            'comenzi' => $fara($comenzi, ['id', 'placed_at', 'paid_at']),
            'linii' => $fara($citeste('SELECT * FROM order_items WHERE order_id > :id ORDER BY id', ['id' => $idMaxComenzi]), ['id', 'order_id']),
            'confirmari' => $fara($citeste('SELECT * FROM order_product_warnings WHERE order_id > :id ORDER BY id', ['id' => $idMaxComenzi]),
                ['id', 'order_id', 'confirmed_at']),
            'jurnal_emailuri' => $fara($citeste('SELECT * FROM email_logs WHERE id > :id ORDER BY id', ['id' => $idMaxEmailuri]),
                ['id', 'order_id', 'sent_at']),
            'anti_robot' => array_column($citeste('SELECT status FROM checkout_submit_logs WHERE id > :id ORDER BY id', ['id' => $idMaxJurnal]), 'status'),
            'emailuri' => $emailuri,
            '_inlocuiri' => $inlocuiri,
        ];
    };
    $cerereComanda = static fn (bool $cuBifa) => static function (array $raspunsuri) use ($tokenDin, $dateComanda, $cuBifa): array {
        $corp = (string) ($raspunsuri[1]['corp'] ?? '');
        $bife = [];
        if ($cuBifa && preg_match_all('/name="' . preg_quote(\App\Support\AvertismentProduse::CAMP_BIFA, '/') . '\[([0-9a-f]{16})\]"/', $corp, $m) > 0) {
            foreach (array_unique($m[1]) as $cheie) {
                $bife[$cheie] = '1';
            }
        }
        return ['POST', '/checkout', $tokenDin($corp) + $dateComanda + ($bife !== [] ? [\App\Support\AvertismentProduse::CAMP_BIFA => $bife] : []),
            'viz', 'pauza' => 3];
    };
    $contine = static fn (array $raspuns, string $text): bool => str_contains((string) ($raspuns['corp'] ?? ''), $text);

    // Livrarea 4 SEO: cele patru setări ale termenului, produsele și pagina cazurilor, mărimea unei poze vechi de test.
    $setariTermen = array_merge(...array_values(\App\Support\PoliticaLivrare::SETARI_TERMEN));
    $idPeSlug = static fn (string $slug): int => (int) $unu('SELECT id FROM products WHERE slug = ' . $db->quote($slug));
    $idMusetel = $idPeSlug('ceai-de-musetel');
    $idCosCadou = $idPeSlug('cos-cadou-cu-miere');
    $paginaPoze = 'test-poze-refacute-' . getmypid();
    // Poza „/uploads/gallery/plasma-quinton.png” (pusă de compara-cu-baza.sh la mărimea din pachet) cu $plus octeți în plus, în
    // ambele coduri (codul de bază are copia lui a încărcărilor): altă poză încărcată cu același nume.
    $schimbaMarimeaPozei = static function (int $plus) use ($radacina, $dosarBaza): void {
        $poze = (array) (\App\Support\DateSite::json('poze-optimizate.json')['poze'] ?? []);
        $octeti = (int) ($poze['/uploads/gallery/plasma-quinton.png']['octeti'] ?? 0);
        // Aceeași dată a fișierului în ambele copii (versiunea pozei de distribuire /og/… depinde de ea).
        $data = (int) @filemtime($radacina . '/public/uploads/gallery/plasma-quinton.png');
        foreach ([$radacina, $dosarBaza] as $dosar) {
            $fisier = $dosar . '/public/uploads/gallery/plasma-quinton.png';
            if ($octeti > 0 && $data > 0 && is_file($fisier)) {
                $fp = fopen($fisier, 'r+');
                ftruncate($fp, $octeti + $plus);
                fclose($fp);
                touch($fisier, $data);
                clearstatcache(true, $fisier);
            }
        }
    };
    // Adresele cardurilor „Produse similare” scrise în HTML, în ordine.
    $linkuriSimilare = static function (array $raspuns): array {
        preg_match_all('/<a class="similar-product-card__media" href="(\/[^"]*)"/', (string) ($raspuns['corp'] ?? ''), $m);
        return $m[1];
    };

    array_push($salvari,
        [
            'nume' => 'antet, meniu și subsol: linkurile vechi duc la /categorie/{slug}, logo-ul ca WebP',
            'din' => '6c8c789, a14e803',
            'tabele' => $tabeleDesign,
            'pregateste' => static function (PDO $db) use ($antetDeTest, $meniuDeTest, $subsolDeTest): void {
                $stmt = $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
                $stmt->execute(['design_header_html', $antetDeTest]);
                $stmt->execute(['design_menu_html', $meniuDeTest]);
                $stmt->execute(['design_footer_html', $subsolDeTest]);
            },
            'cereri' => [
                ['GET', '/', [], 'viz'],
                ['GET', '/produs/ceai-de-menta', [], 'viz'],
                ['GET', '/categorie/ceaiuri', [], 'viz'],
                // Coșul și contul rămân exact ca înainte (paginile închise pentru Google).
                ['GET', '/cos', [], 'viz'],
                ['GET', '/login', [], 'viz'],
            ],
            'stare' => static fn (PDO $db): array => [],
            'verifica' => static function (array $stare, array $raspunsuri) use ($contine): array {
                $probleme = [];
                foreach ([0, 1, 2] as $n) {
                    if (!$contine($raspunsuri[$n], 'href="/categorie/ceaiuri"') || !$contine($raspunsuri[$n], 'href="/categorie/suplimente"')) {
                        $probleme[] = 'cererea ' . ($n + 1) . ': linkurile spre categorii nu sunt /categorie/{slug}';
                    }
                    if (!$contine($raspunsuri[$n], '<picture style="display:contents"><source type="image/webp"')) {
                        $probleme[] = 'cererea ' . ($n + 1) . ': logo-ul nu are varianta WebP';
                    }
                }
                if (!$contine($raspunsuri[3], 'href="/magazin?category=Ceaiuri"')) {
                    $probleme[] = 'coșul nu mai are linkurile exact ca înainte';
                }
                return $probleme;
            },
        ],
        [
            'nume' => 'categorie: titlul și descrierea pentru Google se salvează și apar pe pagina categoriei',
            'din' => '93d803f',
            'tabele' => [
                ['tabel' => 'product_categories', 'unde' => 'id = 7', 'cheie' => 'id'],
                ['tabel' => 'products', 'unde' => 'category_id = 7', 'cheie' => 'id'],
                ['tabel' => 'seo_pages', 'unde' => 'page_type = \'category\' AND page_ref = \'7\'', 'cheie' => 'id'],
                $tokenCache,
            ],
            'pregateste' => static function (PDO $db): void {
                $db->exec("DELETE FROM seo_pages WHERE page_type = 'category' AND page_ref = '7'");
            },
            'cereri' => [
                ['POST', '/admin/categories/7/update', ['name' => 'Ceaiuri', 'slug' => 'ceaiuri',
                    'seo_title' => '  Ceaiuri   din plante, de test ', 'seo_description' => "Descrierea\nde test a categoriei de ceaiuri."]],
                ['GET', '/categorie/ceaiuri', [], 'viz'],
                ['GET', '/admin/categories', []],
            ],
            'stare' => static fn (PDO $db): array => ['seo' => array_map(static fn (array $r): array => salvari_fara_coloane($r, ['id']),
                $citeste("SELECT * FROM seo_pages WHERE page_type = 'category' AND page_ref = '7'"))],
            'verifica' => static fn (array $stare, array $raspunsuri): array => array_merge(
                ($stare['seo'][0]['title'] ?? null) === 'Ceaiuri din plante, de test' ? [] : ['titlul salvat: ' . json_encode($stare['seo'], JSON_UNESCAPED_UNICODE)],
                $contine($raspunsuri[1], '<title>Ceaiuri din plante, de test') ? [] : ['pagina categoriei nu are titlul salvat']),
        ],
        [
            'nume' => 'marcă: titlul și descrierea pentru Google se salvează și apar pe pagina mărcii',
            'din' => '93d803f',
            'tabele' => [
                ['tabel' => 'seo_pages', 'unde' => 'page_type = \'brand\'', 'cheie' => 'id'],
                $tokenCache,
            ],
            'pregateste' => static function (PDO $db): void {
                $db->exec("DELETE FROM seo_pages WHERE page_type = 'brand'");
            },
            'cereri' => [
                ['POST', '/admin/categories/brand-seo', ['brand_slug' => 'Livada-Demo', 'seo_title' => 'Livada Demo: ceaiuri și uleiuri de test',
                    'seo_description' => 'Descrierea de test a mărcii.']],
                ['POST', '/admin/categories/brand-seo', ['brand_slug' => '', 'seo_title' => 'Fără marcă']],
                ['GET', '/marca/livada-demo', [], 'viz'],
            ],
            'stare' => static fn (PDO $db): array => ['seo' => array_map(static fn (array $r): array => salvari_fara_coloane($r, ['id']),
                $citeste("SELECT * FROM seo_pages WHERE page_type = 'brand' ORDER BY page_ref"))],
            'verifica' => static fn (array $stare, array $raspunsuri): array => array_merge(
                count($stare['seo']) === 1 && ($stare['seo'][0]['page_ref'] ?? '') === 'livada-demo'
                    && ($stare['seo'][0]['title'] ?? '') === 'Livada Demo: ceaiuri și uleiuri de test'
                    ? [] : ['SEO-ul mărcii: ' . json_encode($stare['seo'], JSON_UNESCAPED_UNICODE)],
                $contine($raspunsuri[2], '<title>Livada Demo: ceaiuri și uleiuri de test') ? [] : ['pagina mărcii nu are titlul salvat']),
        ],
        [
            'nume' => 'numele magazinului din titluri: se salvează din Setări magazin → SEO',
            'din' => '93d803f, 0250480',
            'tabele' => [
                ['tabel' => 'settings', 'unde' => '`key` = \'store_name\'', 'cheie' => 'key'],
                $tokenCache,
            ],
            'pregateste' => static function (PDO $db): void {
                $db->exec("DELETE FROM settings WHERE `key` = 'store_name'");
            },
            'cereri' => [
                ['POST', '/admin/settings/store', ['action' => 'save_store_name', 'store_name' => '  Magazinul   de Test ']],
                ['GET', '/', [], 'viz'],
                ['GET', '/produs/ceai-de-menta', [], 'viz'],
                ['GET', '/admin/settings/store', []],
            ],
            'stare' => static fn (PDO $db): array => ['nume' => $citeste("SELECT `value` FROM settings WHERE `key` = 'store_name'")],
            // WP-16: titlul filei din admin ia numele site-ului, deci aici numele salvat chiar de acest caz; adus la numele
            // din baza demo, declarația „titlul filei din admin” din $deBioscem îl scoate ca pe orice pagină de admin. Pe
            // codul Bioscem titlul e „Admin Bioscem”, deci înlocuirea nu-l atinge.
            'curata' => static fn (string $text): string => str_replace(' | Admin Magazinul de Test</title>',
                ' | Admin ' . htmlspecialchars($numeSiteAdmin, ENT_QUOTES) . '</title>', $text),
            'verifica' => static fn (array $stare, array $raspunsuri): array => array_merge(
                ($stare['nume'][0]['value'] ?? null) === 'Magazinul de Test' ? [] : ['numele salvat: ' . json_encode($stare['nume'], JSON_UNESCAPED_UNICODE)],
                $contine($raspunsuri[2], 'Magazinul de Test</title>') ? [] : ['titlul produsului nu are numele magazinului']),
        ],
        [
            'nume' => 'livrarea cu prețuri fixe: în datele structurate ale produselor și în fluxul Merchant Center',
            'din' => 'dfcf6bd, 1e46f9f, 5359ec6, 196fa46',
            'tabele' => $tabeleLivrare,
            'pregateste' => static function (PDO $db) use ($setariLivrare, $stergeFluxul): void {
                $stmt = $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
                foreach ($setariLivrare as $cheie => $valoare) {
                    $stmt->execute([$cheie, $valoare]);
                }
                $stergeFluxul();
            },
            'la_final' => static fn () => $stergeFluxul(),
            'cereri' => [
                // Sub prag: tariful la adresă; peste prag, la preț întreg: gratuit; la reducere peste prag: tot gratuit, ca în
                // coș (regula D, 196fa46; înainte: nimic); fiecare cu termenul de livrare implicit (5359ec6).
                ['GET', '/produs/ceai-de-menta', [], 'viz'],
                ['GET', '/produs/omega-3-din-alge', [], 'viz'],
                ['GET', '/produs/miere-de-manuka-mgo-400', [], 'viz'],
                ['GET', '/feed/google-merchant.xml', [], 'viz'],
                ['GET', '/', [], 'viz'],
            ],
            'stare' => static fn (PDO $db): array => [],
            'verifica' => static function (array $stare, array $raspunsuri) use ($contine): array {
                $probleme = [];
                if (!$contine($raspunsuri[0], '"shippingDetails"') || !$contine($raspunsuri[0], '19.99')) {
                    $probleme[] = 'produsul sub prag nu are livrarea de 19,99 lei în datele structurate';
                }
                if (!$contine($raspunsuri[1], '"shippingDetails"')) {
                    $probleme[] = 'produsul peste prag nu are livrarea gratuită în datele structurate';
                }
                if (!$contine($raspunsuri[2], '"shippingDetails"') || !$contine($raspunsuri[2], '"value":"0.00"')) {
                    $probleme[] = 'produsul la reducere peste prag nu are livrarea gratuită (regula D, ca în coș)';
                }
                $termenImplicit = '"deliveryTime":{"@type":"ShippingDeliveryTime","handlingTime":{"@type":"QuantitativeValue","minValue":0,'
                    . '"maxValue":1,"unitCode":"DAY"},"transitTime":{"@type":"QuantitativeValue","minValue":1,"maxValue":2,"unitCode":"DAY"},'
                    . '"businessDays":{"@type":"OpeningHoursSpecification","dayOfWeek":["https://schema.org/Monday","https://schema.org/Tuesday",'
                    . '"https://schema.org/Wednesday","https://schema.org/Thursday","https://schema.org/Friday"]}}';
                foreach ([0, 1, 2] as $n) {
                    if (!$contine($raspunsuri[$n], $termenImplicit)) {
                        $probleme[] = 'produsul ' . ($n + 1) . ' nu are termenul de livrare implicit (0–1 + 1–2 zile lucrătoare, luni–vineri)';
                    }
                }
                if (!$contine($raspunsuri[3], '<g:min_handling_time>0</g:min_handling_time><g:max_handling_time>1</g:max_handling_time>'
                        . '<g:min_transit_time>1</g:min_transit_time><g:max_transit_time>2</g:max_transit_time>')
                    || !$contine($raspunsuri[3], '<g:shipping_handling_business_days>M;T;W;R;F</g:shipping_handling_business_days>')
                    || !$contine($raspunsuri[3], '<g:shipping_transit_business_days>M;T;W;R;F</g:shipping_transit_business_days>')) {
                    $probleme[] = 'fluxul nu are termenul de livrare și zilele lucrătoare';
                }
                if (!$contine($raspunsuri[0], '"hasMerchantReturnPolicy"') || !$contine($raspunsuri[4], '"hasMerchantReturnPolicy"')) {
                    $probleme[] = 'returul lipsește din datele structurate (produs sau firmă)';
                }
                if (!$contine($raspunsuri[3], '<g:shipping>') || !$contine($raspunsuri[3], '<g:service>FAN Courier</g:service>')) {
                    $probleme[] = 'fluxul nu are livrarea';
                }
                return $probleme;
            },
        ],
        // Livrarea 4 SEO (Bioscem 5359ec6): termenul de livrare din cele patru setări, peste valorile implicite din seo.json; o
        // valoare greșită ia valoarea implicită (pregătirea „x”–2 → 0–2), o valoare peste 30 la fel (transportul 31–3 → 1–3).
        [
            'nume' => 'livrarea 4: termenul de livrare din setări (o valoare greșită ia valoarea implicită)',
            'din' => '5359ec6',
            'tabele' => [
                ['tabel' => 'settings', 'unde' => '`key` IN (\'' . implode('\', \'', array_merge(array_keys($setariLivrare), $setariTermen)) . '\')',
                    'cheie' => 'key'],
                $tokenCache,
            ],
            'pregateste' => static function (PDO $db) use ($setariLivrare, $stergeFluxul): void {
                $stmt = $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
                foreach ($setariLivrare + ['seo_livrare_pregatire_min' => 'x', 'seo_livrare_pregatire_max' => '2',
                    'seo_livrare_transport_min' => '31', 'seo_livrare_transport_max' => '3'] as $cheie => $valoare) {
                    $stmt->execute([$cheie, $valoare]);
                }
                $stergeFluxul();
            },
            'la_final' => static fn () => $stergeFluxul(),
            'cereri' => [
                ['GET', '/produs/ceai-de-menta', [], 'viz'],
                ['GET', '/feed/google-merchant.xml', [], 'viz'],
            ],
            'stare' => static fn (PDO $db): array => [],
            'verifica' => static fn (array $stare, array $raspunsuri): array => array_merge(
                $contine($raspunsuri[0], '"handlingTime":{"@type":"QuantitativeValue","minValue":0,"maxValue":2,"unitCode":"DAY"},'
                    . '"transitTime":{"@type":"QuantitativeValue","minValue":1,"maxValue":3,"unitCode":"DAY"}')
                    ? [] : ['datele structurate nu au termenul 0–2 + 1–3 din setări'],
                $contine($raspunsuri[1], '<g:min_handling_time>0</g:min_handling_time><g:max_handling_time>2</g:max_handling_time>'
                    . '<g:min_transit_time>1</g:min_transit_time><g:max_transit_time>3</g:max_transit_time>')
                    ? [] : ['fluxul nu are termenul 0–2 + 1–3 din setări']),
        ],
        // Livrarea 4 SEO (Bioscem ef22b23, 682843c, dd41c59, a64afb6, bb11ae0): căutarea internă cu noindex și fără canonical;
        // o categorie și un articol care nu există primesc pagina 404 a magazinului (fără canonical); pe o cale exclusă scrisă
        // cu „//” răspunsul de dinainte; un link de plată greșit își păstrează canonicalul și pe 404; HEAD pe o adresă cu
        // majuscule primește 301-ul de la GET (înainte 404). Nimic de salvat: doar cererile, pe ambele servere.
        [
            'nume' => 'livrarea 4: căutarea cu noindex, paginile 404 noi, căile excluse, HEAD cu majuscule',
            'din' => 'ef22b23, 682843c, dd41c59, a64afb6, bb11ae0',
            'tabele' => [$tokenCache],
            'pregateste' => static function (PDO $db): void {
            },
            'cereri' => [
                ['GET', '/magazin?q=ceai', [], 'viz'],
                ['GET', '/categorie/categorie-care-nu-exista', [], 'viz'],
                ['GET', '/blog/articol-care-nu-exista', [], 'viz'],
                ['GET', '//api/blog/posts', [], 'viz'],
                ['GET', '/plata/token-care-nu-exista', [], 'viz'],
                ['GET', '/pagina-care-nu-exista', [], 'viz'],
                ['HEAD', '/produs/CEAI-DE-MENTA', [], 'viz'],
                ['HEAD', '/produs/ceai-de-menta', [], 'viz'],
            ],
            'stare' => static fn (PDO $db): array => [],
            'verifica' => static function (array $stare, array $raspunsuri) use ($contine): array {
                $probleme = [];
                $noindex = '<meta name="robots" content="noindex,follow">';
                if (!$contine($raspunsuri[0], $noindex) || $contine($raspunsuri[0], 'rel="canonical"')) {
                    $probleme[] = '/magazin?q=ceai: fără noindex sau cu canonical';
                }
                foreach ([1 => 'Categoria căutată nu există', 2 => 'Articolul nu a fost găsit'] as $n => $titlu) {
                    if ((int) ($raspunsuri[$n]['cod'] ?? 0) !== 404 || !$contine($raspunsuri[$n], $titlu)
                        || $contine($raspunsuri[$n], 'rel="canonical"') || !$contine($raspunsuri[$n], '</html>')) {
                        $probleme[] = 'cererea ' . ($n + 1) . ': nu e pagina 404 a magazinului, fără canonical („' . $titlu . '”)';
                    }
                }
                if ((int) ($raspunsuri[3]['cod'] ?? 0) !== 404 || trim((string) ($raspunsuri[3]['corp'] ?? '')) !== 'Articolul nu a fost găsit.') {
                    $probleme[] = '//api/blog/posts nu mai dă textul simplu de dinainte';
                }
                if ((int) ($raspunsuri[4]['cod'] ?? 0) !== 404 || !$contine($raspunsuri[4], 'rel="canonical"')) {
                    $probleme[] = 'linkul de plată greșit și-a pierdut canonicalul (căile de plată rămân ca înainte)';
                }
                if ((int) ($raspunsuri[5]['cod'] ?? 0) !== 404 || $contine($raspunsuri[5], 'rel="canonical"')) {
                    $probleme[] = 'pagina 404 are canonical';
                }
                if ((int) ($raspunsuri[6]['cod'] ?? 0) !== 301 || !str_ends_with((string) ($raspunsuri[6]['location'] ?? ''), '/produs/ceai-de-menta')) {
                    $probleme[] = 'HEAD pe adresa cu majuscule nu dă 301 spre adresa cu litere mici (cod ' . (int) ($raspunsuri[6]['cod'] ?? 0) . ')';
                }
                // Pe adresa corectă HEAD rămâne cum era (routerul n-are rute HEAD): doar adresa cu majuscule merge ca GET.
                if ((int) ($raspunsuri[7]['cod'] ?? 0) !== 404) {
                    $probleme[] = 'HEAD pe adresa corectă nu mai dă 404-ul de dinainte (cod ' . (int) ($raspunsuri[7]['cod'] ?? 0) . ')';
                }
                return $probleme;
            },
        ],
        // Livrarea 4 SEO (Bioscem ef22b23, D7; dd41c59, A11): /blog fără niciun articol publicat are noindex, iar când textul
        // paginii „blog” n-are <h1> (pagina demo are unul, deci aici e scos), titlul listei („Blog & Resurse”) e H1-ul paginii,
        // fără alt H1 pus de șablon; un articol nepublicat dă pagina 404 a magazinului.
        [
            'nume' => 'livrarea 4: /blog fără articole publicate (noindex, „Blog & Resurse” ca H1)',
            'din' => 'ef22b23, dd41c59',
            'tabele' => [['tabel' => 'blog_posts', 'unde' => '1 = 1', 'cheie' => 'id'],
                ['tabel' => 'pages', 'unde' => 'slug = \'blog\'', 'cheie' => 'id'], $tokenCache],
            'pregateste' => static function (PDO $db): void {
                $db->exec('UPDATE blog_posts SET is_published = 0');
                $db->exec("UPDATE pages SET html_content = '<section>{{blog_posts}}</section>' WHERE slug = 'blog'");
            },
            'cereri' => [
                ['GET', '/blog', [], 'viz'],
                ['GET', '/blog/' . $slugBlog, [], 'viz'],
                ['GET', '/sitemap.xml', [], 'viz'],
            ],
            'stare' => static fn (PDO $db): array => [],
            'verifica' => static fn (array $stare, array $raspunsuri): array => array_merge(
                $contine($raspunsuri[0], '<meta name="robots" content="noindex,follow">') ? [] : ['/blog gol fără noindex'],
                $contine($raspunsuri[0], '<h1>Blog &amp; Resurse</h1>') && !$contine($raspunsuri[0], 'class="custom-page-h1"')
                    ? [] : ['/blog fără H1-ul „Blog & Resurse” (sau cu încă un H1 din șablon)'],
                (int) ($raspunsuri[1]['cod'] ?? 0) === 404 && $contine($raspunsuri[1], 'Articolul nu a fost găsit')
                    && $contine($raspunsuri[1], '</html>') ? [] : ['articolul nepublicat nu dă pagina 404 a magazinului']),
        ],
        // Livrarea 4 SEO (Bioscem e2c97dc, 070e1af, A8): pozele mari ale site-ului (poze-optimizate.json) se afișează din WebP-ul
        // pachetului doar cu poza veche de exact mărimea scrisă (cea de pe disc; compara-cu-baza.sh pune pozele de test); una cu
        // altă mărime (altă poză încărcată cu același nume) rămâne cea veche; fluxul și datele structurate rămân pe poza veche.
        // O pagină din constructor fără <h1> primește titlul ei ca H1 (9fe175d, A11).
        [
            'nume' => 'livrarea 4: pozele refăcute (WebP doar cu mărimea de pe disc), H1-ul paginii fără titlu',
            'din' => 'e2c97dc, 070e1af, 9fe175d',
            'tabele' => array_merge($tabeleProdus($idMentaInt), $tabeleProdus($idMusetel), [
                ['tabel' => 'pages', 'unde' => 'slug = :pz', 'param' => ['pz' => $paginaPoze], 'cheie' => 'id'],
                $tokenCache,
            ]),
            'pregateste' => static function (PDO $db) use ($idMentaInt, $idMusetel, $paginaPoze, $schimbaMarimeaPozei, $stergeFluxul): void {
                $db->prepare('UPDATE products SET image_url = ? WHERE id = ?')->execute(['/uploads/gallery/vivomixx.png', $idMentaInt]);
                $db->prepare('UPDATE products SET image_url = ? WHERE id = ?')->execute(['/uploads/gallery/plasma-quinton.png', $idMusetel]);
                $db->prepare('INSERT INTO pages (title, slug, html_content, css_content, js_content, is_published, created_at, updated_at)
                    VALUES (?, ?, ?, \'\', \'\', 1, NOW(), NOW())')->execute(['Poze refăcute', $paginaPoze,
                    '<p><img src="/uploads/gallery/hero-homepage.png" alt="Erou"></p><p><img alt="Al doilea" src=\'/uploads/gallery/siliciu-organic.png\'></p>'
                    . '<p><img src="/uploads/gallery/plasma-quinton.png" alt="Altă mărime"></p>']);
                $schimbaMarimeaPozei(1);
                $stergeFluxul();
            },
            'la_final' => static function () use ($schimbaMarimeaPozei, $stergeFluxul): void {
                $schimbaMarimeaPozei(0);
                $stergeFluxul();
            },
            'cereri' => [
                ['GET', '/produs/ceai-de-menta', [], 'viz'],
                ['GET', '/produs/ceai-de-musetel', [], 'viz'],
                ['GET', '/categorie/ceaiuri', [], 'viz'],
                ['GET', '/' . $paginaPoze, [], 'viz'],
                ['GET', '/feed/google-merchant.xml', [], 'viz'],
            ],
            'stare' => static fn (PDO $db): array => [],
            'verifica' => static fn (array $stare, array $raspunsuri): array => array_merge(
                $contine($raspunsuri[0], 'src="/assets/img/optimizate/vivomixx.webp"') ? [] : ['produsul cu poza refăcută nu arată WebP-ul'],
                $contine($raspunsuri[0], '"image":["http://127.0.0.1:') && $contine($raspunsuri[0], '/uploads/gallery/vivomixx.png"]')
                    ? [] : ['datele structurate nu mai au poza veche'],
                $contine($raspunsuri[1], 'src="/uploads/gallery/plasma-quinton.png"') && !$contine($raspunsuri[1], 'plasma-quinton.webp')
                    && $contine($raspunsuri[1], '/assets/img/optimizate/vivomixx.webp') ? [] : ['poza cu altă mărime a trecut pe WebP (sau cardul similar nu)'],
                $contine($raspunsuri[2], '/assets/img/optimizate/vivomixx.webp') ? [] : ['cardul din categorie nu arată WebP-ul'],
                $contine($raspunsuri[3], 'src="/assets/img/optimizate/hero-homepage.webp"')
                    && $contine($raspunsuri[3], 'src=\'/assets/img/optimizate/siliciu-organic.webp\'')
                    && $contine($raspunsuri[3], 'src="/uploads/gallery/plasma-quinton.png"') ? [] : ['pozele din textul paginii nu sunt cele așteptate'],
                $contine($raspunsuri[3], '<h1 class="custom-page-h1">Poze refăcute</h1>') ? [] : ['pagina fără <h1> nu primește titlul ca H1'],
                $contine($raspunsuri[4], '/uploads/gallery/vivomixx.png') && !$contine($raspunsuri[4], 'optimizate') ? [] : ['fluxul nu mai are poza veche']),
        ],
        // Livrarea 4 SEO (Bioscem 2d43171, A14): „Produse similare” din aceeași categorie, scrise în HTML: cele alese în admin din
        // categorie primele, apoi celelalte după produsul curent, epuizatele la urmă; o categorie cu mai puțin de 4 se
        // completează cu cele alese în admin din alte categorii (și cu oferte BBD).
        [
            'nume' => 'livrarea 4: produsele similare (aceeași categorie, alegerea din admin, completarea până la 4)',
            'din' => '2d43171',
            'tabele' => array_merge($tabeleProdus($idMentaInt), $tabeleProdus($idCosCadou), [$tokenCache]),
            'pregateste' => static function (PDO $db) use ($idMentaInt, $idCosCadou, $idPeSlug): void {
                $db->prepare('UPDATE products SET similar_products_json = ? WHERE id = ?')->execute([json_encode([
                    $idPeSlug('omega-3-din-alge'), $idPeSlug('ceai-de-tei'), $idPeSlug('ceai-de-fructe-de-padure')]), $idMentaInt]);
                $db->prepare('UPDATE products SET similar_products_json = ? WHERE id = ?')->execute([json_encode([
                    $idPeSlug('omega-3-din-alge'), $idPeSlug('miere-de-tei'), $idPeSlug('seminte-de-chia')]), $idCosCadou]);
            },
            'cereri' => [
                ['GET', '/produs/ceai-de-menta', [], 'viz'],
                ['GET', '/produs/cos-cadou-cu-miere', [], 'viz'],
            ],
            'stare' => static fn (PDO $db): array => [],
            'verifica' => static fn (array $stare, array $raspunsuri): array => array_merge(
                $linkuriSimilare($raspunsuri[0]) === ['/produs/ceai-de-tei', '/produs/ceai-de-musetel', '/produs/ceai-verde-sencha',
                    '/produs/ceai-de-ghimbir', '/produs/ceai-de-fructe-de-padure'] ? [] : ['ceai de mentă: ' . json_encode($linkuriSimilare($raspunsuri[0]))],
                $linkuriSimilare($raspunsuri[1]) === ['/produs/set-cadou-ceaiuri', '/produs/omega-3-din-alge', '/produs/miere-de-tei',
                    '/produs/seminte-de-chia'] ? [] : ['coș cadou: ' . json_encode($linkuriSimilare($raspunsuri[1]))]),
        ],
        [
            'nume' => 'avertisment: cele trei câmpuri se salvează din Admin → Produse și apar în editor',
            'din' => '333ba9e',
            'tabele' => array_merge($tabeleProdus($idMentaInt), [$tokenCache]),
            'pregateste' => static function (PDO $db) use ($idMentaInt): void {
                $db->prepare('DELETE FROM product_warnings WHERE product_id = ?')->execute([$idMentaInt]);
            },
            'cereri' => [
                ['POST', '/admin/products/' . $idMentaInt . '/update', $corpPentru($produsMenta, [
                    'avertisment_titlu' => '  ATENȚIE –   PRODUS DE TEST  ',
                    'avertisment_text' => "Primul rând <b>fără HTML</b>.\r\n\r\n\r\n\r\nAl doilea paragraf.   ",
                    'avertisment_eticheta' => ' Am citit ',
                ])],
                ['GET', '/admin/products', []],
            ],
            'stare' => static fn (PDO $db): array => ['avertisment' => $starePrimuluiAvertisment($db, $idMentaInt)],
            'verifica' => static fn (array $stare): array => ($stare['avertisment']['title'] ?? null) !== null
                && str_contains((string) ($stare['avertisment']['body'] ?? ''), 'Al doilea paragraf')
                ? [] : ['avertismentul nu s-a salvat: ' . json_encode($stare, JSON_UNESCAPED_UNICODE)],
        ],
        [
            // Alt formular care salvează produsul (fără cele trei câmpuri) nu atinge avertismentul.
            'nume' => 'avertisment: o salvare a produsului fără câmpurile avertismentului îl lasă neatins',
            'din' => '333ba9e',
            'tabele' => array_merge($tabeleProdus($idMentaInt), [$tokenCache]),
            'pregateste' => static fn (PDO $db) => $punAvertisment($db, $idMentaInt),
            'cereri' => [
                ['POST', '/admin/products/' . $idMentaInt . '/update', $corpPentru($produsMenta, ['short_description' => 'Rezumat schimbat (compara-cu-baza)'])],
                ['POST', '/api/cart/items/' . $idMentaInt . '/add', ['quantity' => '1'], 'viz'],
            ],
            'stare' => static fn (PDO $db): array => [
                'avertisment' => $starePrimuluiAvertisment($db, $idMentaInt),
                'rezumat' => $citeste('SELECT short_description FROM products WHERE id = :id', ['id' => $idMentaInt])[0]['short_description'] ?? null,
            ],
            'verifica' => static fn (array $stare, array $raspunsuri): array => array_merge(
                ($stare['avertisment']['title'] ?? null) === $titluAvertisment && str_contains((string) ($stare['avertisment']['body'] ?? ''), 'la rece')
                    ? [] : ['avertismentul s-a schimbat: ' . json_encode($stare['avertisment'], JSON_UNESCAPED_UNICODE)],
                str_contains((string) $stare['rezumat'], 'Rezumat schimbat (compara-cu-baza)') ? [] : ['salvarea produsului nu a ajuns în bază'],
                $contine($raspunsuri[1], '"avertisment"') ? [] : ['adăugarea în coș nu mai are fereastra avertismentului']),
        ],
        [
            // Doar textul gol: rândul rămâne, fără fereastră; toate trei goale: rândul dispare.
            'nume' => 'avertisment: fără text nu mai apare; cu toate câmpurile goale se șterge',
            'din' => '333ba9e',
            'tabele' => array_merge($tabeleProdus($idMentaInt), [$tokenCache]),
            'pregateste' => static fn (PDO $db) => $punAvertisment($db, $idMentaInt),
            'cereri' => [
                ['POST', '/admin/products/' . $idMentaInt . '/update', $corpPentru($produsMenta, [
                    'avertisment_titlu' => $titluAvertisment, 'avertisment_text' => " \r\n ", 'avertisment_eticheta' => $etichetaAvertisment])],
                ['POST', '/api/cart/items/' . $idMentaInt . '/add', ['quantity' => '1'], 'viz'],
                ['GET', '/admin/products', []],
                ['POST', '/admin/products/' . $idMentaInt . '/update', $corpPentru($produsMenta, [
                    'short_description' => 'Rezumat schimbat (compara-cu-baza)',
                    'avertisment_titlu' => ' ', 'avertisment_text' => '', 'avertisment_eticheta' => ''])],
            ],
            'stare' => static fn (PDO $db): array => [
                'avertisment' => $starePrimuluiAvertisment($db, $idMentaInt),
                'rezumat' => $citeste('SELECT short_description FROM products WHERE id = :id', ['id' => $idMentaInt])[0]['short_description'] ?? null,
            ],
            'verifica' => static fn (array $stare, array $raspunsuri): array => array_merge(
                $stare['avertisment'] === [] ? [] : ['avertismentul cu toate câmpurile goale a rămas: ' . json_encode($stare['avertisment'], JSON_UNESCAPED_UNICODE)],
                str_contains((string) $stare['rezumat'], 'Rezumat schimbat (compara-cu-baza)') ? [] : ['salvarea produsului nu a ajuns în bază'],
                $contine($raspunsuri[1], '"avertisment"') ? ['un avertisment fără text are fereastră la adăugarea în coș'] : []),
        ],
        [
            'nume' => 'avertisment: fereastra la adăugarea în coș, caseta din coș și de la finalizarea comenzii',
            'din' => 'b874cf0, 233cc7d, 865630f, 4254ca4',
            'tabele' => array_merge($tabeleProdus($idMentaInt), [
                ['tabel' => 'cart_abandonments', 'unde' => 'id > :c1', 'param' => ['c1' => $idMaxCosuri], 'cheie' => 'id'],
                $tokenCache,
            ]),
            'pregateste' => static fn (PDO $db) => $punAvertisment($db, $idMentaInt),
            'cereri' => [
                ['GET', '/produs/ceai-de-menta', [], 'viz'],
                ['POST', '/api/cart/items/' . $idMentaInt . '/add', ['quantity' => '1'], 'viz'],
                ['GET', '/api/cart/avertismente?instanta=checkout-live', [], 'viz'],
                ['GET', '/api/cart/avertismente?instanta=..%2Fnu', [], 'viz'],
                // Adăugarea clasică (fără JavaScript): fereastra apare o dată, pe pagina coșului.
                ['POST', '/cos/adauga/' . $idMentaInt, ['quantity' => '1'], 'viz'],
                ['GET', '/cos', [], 'viz'],
                ['GET', '/cos', [], 'viz'],
                ['GET', '/checkout', [], 'viz'],
                ['GET', '/api/cart/summary', [], 'viz'],
            ],
            'stare' => static fn (PDO $db): array => [],
            'verifica' => static function (array $stare, array $raspunsuri) use ($contine, $titluAvertisment, $etichetaAvertisment): array {
                $probleme = [];
                $adaugat = json_decode((string) ($raspunsuri[1]['corp'] ?? ''), true);
                if (!is_array($adaugat) || !is_array($adaugat['avertisment'] ?? null)) {
                    $probleme[] = 'adăugarea în coș nu întoarce avertismentul: ' . mb_substr((string) ($raspunsuri[1]['corp'] ?? ''), 0, 200);
                }
                $lista = json_decode((string) ($raspunsuri[2]['corp'] ?? ''), true);
                if (!is_array($lista) || count((array) ($lista['chei'] ?? [])) !== 1 || !str_contains((string) ($lista['html'] ?? ''), $etichetaAvertisment)) {
                    $probleme[] = 'avertismentele coșului: ' . mb_substr((string) ($raspunsuri[2]['corp'] ?? ''), 0, 200);
                }
                if (!$contine($raspunsuri[5], $titluAvertisment)) {
                    $probleme[] = 'coșul nu arată fereastra după adăugarea clasică';
                }
                if (!$contine($raspunsuri[7], 'name="' . \App\Support\AvertismentProduse::CAMP_BIFA . '[')) {
                    $probleme[] = 'finalizarea comenzii nu are bifa avertismentului';
                }
                return $probleme;
            },
        ],
        [
            'nume' => 'avertisment: comanda fără bifă e refuzată, cu mesaj, și nu se creează',
            'din' => '5f2e483, 233cc7d',
            'tabele' => $tabeleComanda,
            'pregateste' => $pregatesteComanda,
            'cereri' => [
                ['POST', '/cos/adauga/' . $idMentaInt, ['quantity' => '1'], 'viz'],
                ['GET', '/checkout', [], 'viz'],
                $cerereComanda(false),
                ['GET', '/checkout', [], 'viz'],
            ],
            'curata' => $faraOra,
            'stare' => $stareComanda,
            'verifica' => static fn (array $stare, array $raspunsuri): array => array_merge(
                $stare['comenzi'] === [] && $stare['confirmari'] === [] ? [] : ['s-a creat o comandă fără bifă'],
                $stare['anti_robot'] === ['allowed'] ? [] : ['comanda nu a trecut de filtrul anti-robot: ' . json_encode($stare['anti_robot'])],
                str_ends_with((string) ($raspunsuri[2]['location'] ?? ''), '/checkout') ? [] : ['refuzul nu duce înapoi la /checkout'],
                $contine($raspunsuri[3], 'bifează „' . $etichetaAvertisment . '”') ? [] : ['mesajul refuzului lipsește de pe /checkout']),
        ],
        [
            'nume' => 'avertisment: comanda cu bifă ține minte confirmarea; emailul și Admin → Comenzi o arată',
            'din' => '5f2e483, fa76c4f, 233cc7d',
            'tabele' => $tabeleComanda,
            'pregateste' => $pregatesteComanda,
            'cereri' => [
                ['POST', '/cos/adauga/' . $idMentaInt, ['quantity' => '2'], 'viz'],
                ['GET', '/checkout', [], 'viz'],
                $cerereComanda(true),
                static fn (array $raspunsuri): array => ['GET', '/admin/orders?search='
                    . rawurlencode((string) preg_replace('#^.*/checkout/succes/#', '', (string) ($raspunsuri[2]['location'] ?? ''))), []],
            ],
            'curata' => $faraOra,
            'stare' => $stareComanda,
            'verifica' => static function (array $stare, array $raspunsuri) use ($titluAvertisment, $etichetaAvertisment, $contine): array {
                $probleme = [];
                if (count($stare['comenzi']) !== 1) {
                    $probleme[] = 'așteptam o comandă nouă, sunt ' . count($stare['comenzi']);
                }
                $confirmare = $stare['confirmari'][0] ?? [];
                if (count($stare['confirmari']) !== 1 || ($confirmare['title'] ?? '') !== $titluAvertisment
                    || (int) ($confirmare['checkbox_ticked'] ?? 0) !== 1 || !str_contains((string) ($confirmare['checkbox_label'] ?? ''), $etichetaAvertisment)
                    || !str_contains((string) ($confirmare['product_names_json'] ?? ''), 'Ceai de ment')) {
                    $probleme[] = 'confirmarea de pe comandă: ' . json_encode($stare['confirmari'], JSON_UNESCAPED_UNICODE);
                }
                $emailCuAvertisment = array_filter($stare['emailuri'], static fn (string $e): bool => str_contains(
                    quoted_printable_decode($e), $titluAvertisment) || str_contains($e, $titluAvertisment));
                if ($emailCuAvertisment === []) {
                    $probleme[] = 'niciun email al comenzii nu are avertismentul (' . count($stare['emailuri']) . ' emailuri)';
                }
                if (!$contine($raspunsuri[3], 'avertismente_confirmate') || !$contine($raspunsuri[3], 'ATEN')) {
                    $probleme[] = 'Admin → Comenzi nu are confirmarea comenzii';
                }
                return $probleme;
            },
        ],
        [
            // Port din vertij (0fc2b2c, App\Support\VideoEmbed): ramura pe care portul nu o schimbă pentru Bioscem,
            // un fișier .mp4 urcat în Galerie rămâne <video> exact ca înainte. Linkurile YouTube și Vimeo (playerul
            // lor în loc de caseta neagră) sunt schimbarea declarată în site-uri/bioscem/ce-se-schimba.md.
            'nume' => 'blog: videoul postării dintr-un fișier .mp4 rămâne <video>, ca înainte',
            'sursa' => 'vertij',
            'din' => '0fc2b2c',
            'tip' => 'regresie',
            'tabele' => [
                ['tabel' => 'blog_posts', 'unde' => 'id = :p1', 'param' => ['p1' => $idPostare], 'cheie' => 'id'],
                ['tabel' => 'blog_templates', 'unde' => 'id > :t1', 'param' => ['t1' => (int) $unu('SELECT COALESCE(MAX(id), 0) FROM blog_templates')],
                    'cheie' => 'id'],
            ],
            'pregateste' => static function (PDO $db) use ($idPostare): void {
                $db->prepare('INSERT INTO blog_templates (name, slug, description, html_content, css_content, js_content, is_active)
                    VALUES (?, ?, ?, ?, \'\', \'\', 1)')->execute(['Articol cu video (test)', 'articol-cu-video-test-' . getmypid(), '',
                    '<article class="video-test"><h1>{{blog_title}}</h1><div class="video-test__player">{{blog_video}}</div>'
                    . '<p class="video-test__link">{{blog_video_url}}</p>{{blog_content}}</article>']);
                $db->prepare('UPDATE blog_posts SET template_id = :t, video_url = :v WHERE id = :id')->execute([
                    't' => (int) $db->lastInsertId(), 'v' => '/uploads/gallery/film-de-test.mp4', 'id' => $idPostare]);
            },
            'cereri' => [['GET', '/blog/' . (string) ($postare['slug'] ?? ''), [], 'viz']],
            'stare' => static fn (PDO $db): array => [],
            'verifica' => static fn (array $stare, array $raspunsuri): array => $contine($raspunsuri[0],
                '<div class="video-test__player"><video controls preload="metadata" style="width:100%;height:auto;border-radius:14px;display:block;">'
                . '<source src="/uploads/gallery/film-de-test.mp4" type="video/mp4">Browserul tău nu suportă redarea video.</video></div>')
                ? [] : ['postarea nu are videoul .mp4 în <video>'],
        ],
        [
            // WP-22 (Biovitality 9c549c7, 263b520; DB-13): cu blogul avansat oprit (blog_avansat = 0, înghețarea Bioscem)
            // articolul rămâne cel de dinainte și cu un șablon care are secțiunea veche „Articole interesante” (umplută din
            // JavaScript, data-blog-related-list): titlurile fără ancore, fără cuprins, secțiunea neatinsă; categoria și
            // inițiala autorului unui articol fără ele vin din înghețare („Jurnal Bioscem”, „B”); /blog ignoră ?q=,
            // ?categorie= și ?eticheta= (lista întreagă, fără căutare).
            'nume' => 'blog: blogul avansat oprit lasă articolul (șablon cu „Articole interesante”) și /blog cu filtre ca înainte',
            'sursa' => 'Biovitality',
            'din' => '9c549c7',
            'tip' => 'regresie',
            'tabele' => [
                ['tabel' => 'blog_posts', 'unde' => 'id = :p1', 'param' => ['p1' => $idPostare], 'cheie' => 'id'],
                ['tabel' => 'blog_templates', 'unde' => 'id > :t1', 'param' => ['t1' => (int) $unu('SELECT COALESCE(MAX(id), 0) FROM blog_templates')],
                    'cheie' => 'id'],
            ],
            'pregateste' => static function (PDO $db) use ($idPostare): void {
                $db->prepare('INSERT INTO blog_templates (name, slug, description, html_content, css_content, js_content, is_active)
                    VALUES (?, ?, ?, ?, \'\', \'\', 1)')->execute(['Articol cu articole interesante (test)', 'articol-interesante-test-' . getmypid(), '',
                    '<article class="leg"><span class="leg__cat">{{blog_category}}</span><span class="leg__ini">{{blog_author_initials}}</span>'
                    . '<h1>{{blog_title}}</h1><div class="leg__content">{{blog_content_html}}</div><section class="leg__related" data-blog-related>'
                    . '<h2>Articole interesante</h2><div class="leg__related-grid" data-blog-related-list></div></section></article>']);
                $db->prepare('UPDATE blog_posts SET template_id = :t, category = NULL, author_id = NULL, content = :c WHERE id = :id')->execute([
                    't' => (int) $db->lastInsertId(), 'id' => $idPostare,
                    'c' => '<h2>Primul titlu</h2><p>Unu [1].</p><h3>Al doilea titlu</h3><p>Doi.</p><h2>Al treilea</h2><p>Trei.</p>']);
            },
            'cereri' => [
                ['GET', '/blog/' . (string) ($postare['slug'] ?? ''), [], 'viz'],
                ['GET', '/blog?q=ceai&categorie=noutati&eticheta=nu-exista', [], 'viz'],
            ],
            'stare' => static fn (PDO $db): array => [],
            'verifica' => static fn (array $stare, array $raspunsuri): array => array_merge(
                $contine($raspunsuri[0], '<h2>Primul titlu</h2><p>Unu [1].</p><h3>Al doilea titlu</h3>') ? [] : ['titlurile articolului nu mai sunt cele scrise'],
                $contine($raspunsuri[0], '<h2>Articole interesante</h2><div class="leg__related-grid" data-blog-related-list></div>')
                    ? [] : ['secțiunea „Articole interesante” nu mai e cea din șablon'],
                $contine($raspunsuri[0], '<span class="leg__cat">Jurnal Bioscem</span><span class="leg__ini">B</span>')
                    ? [] : ['categoria sau inițiala unui articol fără ele nu e cea a Bioscem'],
                $contine($raspunsuri[1], 'Articol recent') && !$contine($raspunsuri[1], 'blog-search') ? [] : ['/blog cu filtre nu e lista întreagă'],
            ),
        ],
        [
            // Port din vertij (0fc2b2c, App\Support\VideoEmbed), cu acordul pentru cookie-uri de la revizuirea portului: un
            // link YouTube, la un vizitator fără acord, e caseta „Pornește videoclipul”; un link Vimeo, la unul care a acceptat
            // toate cookie-urile (rolul „viz-acord”), e playerul, cu dnt=1. Pe codul Bioscem ambele sunt caseta video neagră
            // de azi; diferența e declarată în $porturiVertij, doar pe paginile acestor două postări.
            'nume' => 'blog: un link YouTube (vizitator fără acord) și unul Vimeo (cu acord) în câmpul de video al postării',
            'sursa' => 'vertij',
            'din' => '0fc2b2c',
            'tabele' => [
                ['tabel' => 'blog_posts', 'unde' => 'id > :m1', 'param' => ['m1' => $idMaxPostari], 'cheie' => 'id'],
                ['tabel' => 'blog_templates', 'unde' => 'id > :t1', 'param' => ['t1' => (int) $unu('SELECT COALESCE(MAX(id), 0) FROM blog_templates')],
                    'cheie' => 'id'],
            ],
            'pregateste' => static function (PDO $db) use ($idPostare, $slugVideoYoutube, $slugVideoVimeo): void {
                $db->prepare('INSERT INTO blog_templates (name, slug, description, html_content, css_content, js_content, is_active)
                    VALUES (?, ?, ?, ?, \'\', \'\', 1)')->execute(['Articol cu video extern (test)', 'articol-cu-video-extern-test-' . getmypid(), '',
                    '<article class="video-test"><h1>{{blog_title}}</h1><div class="video-test__player">{{blog_video}}</div>'
                    . '<p class="video-test__link">{{blog_video_url}}</p>{{blog_content}}</article>']);
                $sablon = (int) $db->lastInsertId();
                // Două copii ale primei postări, cu adresa, titlul, șablonul și linkul lor de video.
                $coloane = array_values(array_diff($db->query('SHOW COLUMNS FROM blog_posts')->fetchAll(PDO::FETCH_COLUMN),
                    ['id', 'slug', 'title', 'video_url', 'template_id']));
                $lista = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $coloane));
                $copie = $db->prepare('INSERT INTO blog_posts (' . $lista . ', slug, title, video_url, template_id) SELECT ' . $lista
                    . ', ?, ?, ?, ? FROM blog_posts WHERE id = ?');
                $copie->execute([$slugVideoYoutube, 'Postare cu video YouTube (test)', 'https://youtu.be/AbCdEf12345?t=1m5s', $sablon, $idPostare]);
                $copie->execute([$slugVideoVimeo, 'Postare cu video Vimeo (test)', 'https://vimeo.com/123456789', $sablon, $idPostare]);
            },
            'cereri' => [
                ['GET', '/blog/' . $slugVideoYoutube, [], 'viz'],
                ['GET', '/blog/' . $slugVideoVimeo, [], 'viz-acord'],
            ],
            'stare' => static fn (PDO $db): array => [],
            'verifica' => static fn (array $stare, array $raspunsuri): array => array_merge(
                $contine($raspunsuri[0], '<div class="video-test__player">' . $videoNeagra('https://youtu.be/AbCdEf12345?t=1m5s') . '</div>')
                    ? [] : ['postarea cu linkul YouTube nu are caseta video de azi'],
                $contine($raspunsuri[1], '<div class="video-test__player">' . $videoNeagra('https://vimeo.com/123456789') . '</div>')
                    && !$contine($raspunsuri[1], 'id="cookie-banner"')
                    ? [] : ['postarea cu linkul Vimeo nu are caseta video de azi, sau vizitatorul cu acord vede bannerul de cookie-uri']),
        ],
    );

    // Constantele Bioscem mutate în site-uri/bioscem/seo.json (WP-R): cuvântul generic „harrison” din mărci
    // (titluri.cuvinte_generice_marca, SeoMeta::marcaInNume) și prefixul „BS-” al id-urilor din fluxul Merchant
    // (flux_merchant.prefix_id) pentru un produs fără cod unic. Datele demo nu ating nicio ramură (nicio marcă cu
    // „Harrison”, fiecare produs cu codul lui), deci cazul face trei copii ale ceaiului de mentă, cu id-uri fixe
    // (aceleași pe ambele servere, deci aceleași id-uri în flux): o marcă „… Harrison …” cu un nume de produs care îl
    // conține, un cod gol și un cod dublat (cu litere diferite).
    $idMaxProduse = (int) $unu('SELECT COALESCE(MAX(id), 0) FROM products');
    $marcaHarrison = 'HDN - Harrison Demo Nutrition';
    $produseFaraCod = [
        ['id' => $idMaxProduse + 1, 'nume' => 'Proteină Harrison de test', 'slug' => 'proteina-harrison-de-test', 'sku' => ''],
        ['id' => $idMaxProduse + 2, 'nume' => 'Baton proteic de test', 'slug' => 'baton-proteic-de-test', 'sku' => 'DUBLU-TEST'],
        ['id' => $idMaxProduse + 3, 'nume' => 'Baton proteic de test, cutie', 'slug' => 'baton-proteic-de-test-cutie', 'sku' => 'dublu-test'],
    ];
    $salvari[] = [
        'nume' => 'titlul unui produs cu un cuvânt generic al mărcii (Harrison) și id-urile din flux ale produselor fără cod unic',
        'din' => '93d803f, d277727',
        'tabele' => [
            ['tabel' => 'products', 'unde' => 'id > :p1', 'param' => ['p1' => $idMaxProduse], 'cheie' => 'id'],
            ['tabel' => 'product_category_links', 'unde' => 'product_id > :p2', 'param' => ['p2' => $idMaxProduse], 'cheie' => 'id'],
        ],
        'pregateste' => static function (PDO $db) use ($idMentaInt, $produseFaraCod, $marcaHarrison, $stergeFluxul): void {
            $coloane = array_values(array_diff($db->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN),
                ['id', 'name', 'slug', 'sku', 'brand']));
            $lista = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $coloane));
            $copie = $db->prepare('INSERT INTO products (id, name, slug, sku, brand, ' . $lista . ') SELECT ?, ?, ?, ?, ?, ' . $lista
                . ' FROM products WHERE id = ?');
            $legaturi = $db->prepare('INSERT INTO product_category_links (product_id, category_id)
                SELECT ?, category_id FROM product_category_links WHERE product_id = ?');
            foreach ($produseFaraCod as $produs) {
                $copie->execute([$produs['id'], $produs['nume'], $produs['slug'], $produs['sku'], $marcaHarrison, $idMentaInt]);
                $legaturi->execute([$produs['id'], $idMentaInt]);
            }
            $stergeFluxul();
        },
        'la_final' => static fn () => $stergeFluxul(),
        'cereri' => [
            ['GET', '/produs/proteina-harrison-de-test', [], 'viz'],
            ['GET', '/feed/google-merchant.xml', [], 'viz'],
        ],
        'stare' => static fn (PDO $db): array => [],
        'verifica' => static function (array $stare, array $raspunsuri) use ($contine, $produseFaraCod, $marcaHarrison): array {
            $probleme = [];
            // „harrison” e generic: numele produsului nu conține marca, deci titlul o adaugă.
            if (!$contine($raspunsuri[0], '<title>Proteină Harrison de test – ' . $marcaHarrison)) {
                $probleme[] = 'titlul produsului nu adaugă marca (cuvântul „harrison” nu e generic?)';
            }
            foreach ($produseFaraCod as $produs) {
                if (!$contine($raspunsuri[1], '<g:id>BS-' . $produs['id'] . '</g:id>')) {
                    $probleme[] = 'fluxul nu are id-ul BS-' . $produs['id'] . ' (' . ($produs['sku'] === '' ? 'cod gol' : 'cod dublat') . ')';
                }
            }
            return $probleme;
        },
    ];

    // WP-12 (C6, Biovitality c64fc2d): ramura pe care portul nu o schimbă. Al patrulea editor de cod, al șabloanelor de
    // blog, încarcă CodeMirror și js-beautify ca înainte; datele demo n-au niciun șablon de blog, deci cazul face unul (cu
    // același id pe ambele servere, scos după) și cere editorul lui. Pagina e în „fara_pagini” a declarației C6, deci pe
    // codul curent trebuie să fie exact cea a Bioscem.
    $salvari[] = [
        'nume' => 'adminul: editorul șabloanelor de blog încarcă în continuare CodeMirror și js-beautify',
        'sursa' => 'Biovitality',
        'din' => 'c64fc2d',
        'tip' => 'regresie',
        'tabele' => [
            ['tabel' => 'blog_templates', 'unde' => 'id = :t1', 'param' => ['t1' => $idSablonBlogEditor], 'cheie' => 'id'],
        ],
        'pregateste' => static function (PDO $db) use ($idSablonBlogEditor): void {
            $db->prepare('INSERT INTO blog_templates (id, name, slug, description, html_content, css_content, js_content, is_active)
                VALUES (?, ?, ?, ?, ?, \'\', \'\', 1)')->execute([$idSablonBlogEditor, 'Șablon de blog (compara-cu-baza)',
                'sablon-blog-compara-' . getmypid(), '', '<article><h1>{{blog_title}}</h1>{{blog_content}}</article>']);
        },
        'cereri' => [['GET', '/admin/blog/templates/' . $idSablonBlogEditor . '/builder', []]],
        'stare' => static fn (PDO $db): array => [],
        'verifica' => static fn (array $stare, array $raspunsuri): array => ($raspunsuri[0]['cod'] ?? 0) === 200
            && $contine($raspunsuri[0], 'https://cdn.jsdelivr.net/npm/codemirror@5.65.18/lib/codemirror.min.js')
            && $contine($raspunsuri[0], 'https://cdn.jsdelivr.net/npm/js-beautify@1.15.1/js/lib/beautify-html.min.js')
            ? [] : ['editorul șablonului de blog nu încarcă CodeMirror și js-beautify (cod ' . (int) ($raspunsuri[0]['cod'] ?? 0) . ')'],
    ];

    // ── Reparațiile WP-12 (tip „reparatie”): un defect al Bioscem pe care codul comun îl repară voit. „verifica” cere
    // defectul pe rezultatul codului Bioscem (cazul ajunge chiar pe ramura reparată), „verifica_curent” reparația pe
    // rezultatul codului curent, iar starea diferă exact prin „diferente” (rândurile lui salvari_diferente, în orice
    // ordine): nici mai mult, nici altceva. Răspunsurile rămân identice (textele declarate scoase din paginile GET).
    // Fiecare intră și în site-uri/bioscem/ce-se-schimba.md.
    $corpPostareDin = static fn (array $rand, string $rezumat, array $extra = []): array => [
        'action' => 'save',
        'id' => (string) ($rand['id'] ?? ''),
        'title' => (string) ($rand['title'] ?? ''),
        'slug' => (string) ($rand['slug'] ?? ''),
        'excerpt' => $rezumat,
        'content' => (string) ($rand['content'] ?? ''),
        'reading_minutes' => (string) ($rand['reading_minutes'] ?? '1'),
        'published_at' => (string) ($rand['published_at'] ?? ''),
        'author_id' => (string) ($rand['author_id'] ?? ''),
        'template_id' => (string) ($rand['template_id'] ?? ''),
        'featured_image_url' => (string) ($rand['featured_image_url'] ?? ''),
    ] + ((int) ($rand['is_published'] ?? 0) === 1 ? ['is_published' => '1'] : []) + $extra;
    // Ce trimite editorul, din pagina lui (ca un browser): bifele categoriilor și câmpul ascuns „category”, dacă există.
    $campuriCategorieDinEditor = static function (string $html): array {
        $campuri = [];
        if (preg_match_all('#name="category_ids\[\]" value="(\d+)" checked>#', $html, $m) > 0) {
            $campuri['category_ids'] = array_values($m[1]);
        }
        if (preg_match('#<input type="hidden" name="category" value="([^"]*)">#', $html, $m) === 1) {
            $campuri['category'] = html_entity_decode($m[1], ENT_QUOTES);
        }
        return $campuri;
    };
    $tabeleAlePostarii = static fn (int $id): array => [
        ['tabel' => 'blog_posts', 'unde' => 'id = :p1', 'param' => ['p1' => $id], 'cheie' => 'id'],
        ['tabel' => 'blog_post_categories', 'unde' => 'post_id = :p2', 'param' => ['p2' => $id]],
        ['tabel' => 'seo_pages', 'unde' => 'page_type = \'blog_post\' AND page_ref = :p3', 'param' => ['p3' => (string) $id], 'cheie' => 'id'],
        $tokenCache,
    ];
    $postareDoarText = $citeste('SELECT * FROM blog_posts WHERE id = :id', ['id' => $idPostareDoarText])[0] ?? [];
    $postareTextCaNume = $citeste('SELECT * FROM blog_posts WHERE id = :id', ['id' => $idPostareTextCaNume])[0] ?? [];
    $rezumatReparatie = 'Rezumat schimbat la re-salvare (compara-cu-baza, WP-12)';
    $salvataReparatia = static fn (array $stare): array => ($stare['postare']['excerpt'] ?? null) === $rezumatReparatie
        ? [] : ['salvarea nu a ajuns în bază: rezumatul postării nu e cel trimis'];
    $tipIdCategorie = $citeste('SELECT category_id FROM blog_posts WHERE category_id IS NOT NULL LIMIT 1')[0]['category_id'] ?? null;
    $idCategorieCaInBaza = is_int($tipIdCategorie) ? $idCategorieDupaNume : (string) $idCategorieDupaNume;
    $oferteSalvateJson = (string) json_encode($oferteSalvate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $descriereScurtaReparatie = 'Descriere scurtă schimbată (compara-cu-baza, WP-12)';
    $salvari[] = [
        // Formularul din Produse trimite mereu câmpul (ascuns); îl omite doar o salvare din alt formular sau dintr-o unealtă.
        'nume' => 'oferte BBD: o salvare care nu trimite deloc câmpul ofertelor (alt formular, o unealtă) le păstrează',
        'tip' => 'reparatie',
        'reparatie' => 'WP-12 C2, din Biovitality c64fc2d',
        'tabele' => [
            ['tabel' => 'products', 'unde' => 'id = :p1', 'param' => ['p1' => $idProdusBbd], 'cheie' => 'id'],
            ['tabel' => 'product_category_links', 'unde' => 'product_id = :p2', 'param' => ['p2' => $idProdusBbd], 'cheie' => 'id'],
            ['tabel' => 'product_extra_field_values', 'unde' => 'product_id = :p3', 'param' => ['p3' => $idProdusBbd], 'cheie' => 'id'],
            ['tabel' => 'seo_pages', 'unde' => 'page_type = \'product\' AND page_ref = :p4', 'param' => ['p4' => (string) $idProdusBbd], 'cheie' => 'id'],
            $tokenCache,
        ],
        'pregateste' => static function (PDO $db) use ($idProdusBbd, $oferteSalvateJson): void {
            $db->prepare('UPDATE products SET bbd_enabled = 1, bbd_entries_json = :j WHERE id = :id')->execute([
                'j' => $oferteSalvateJson, 'id' => $idProdusBbd]);
        },
        // Formularul fără bbd_entries_json; descrierea scurtă schimbată arată că salvarea a ajuns în bază.
        'cereri' => [['POST', '/admin/products/' . $idProdusBbd . '/update',
            array_diff_key(['short_description' => $descriereScurtaReparatie] + $corpProdus, ['bbd_entries_json' => true])]],
        'stare' => static fn (PDO $db): array => [
            'produs' => salvari_fara_coloane($citeste('SELECT * FROM products WHERE id = :id', ['id' => $idProdusBbd])[0] ?? []),
        ],
        'verifica' => static fn (array $stare): array => array_merge(
            ($stare['produs']['short_description'] ?? null) === $descriereScurtaReparatie ? [] : ['salvarea nu a ajuns în bază'],
            ($stare['produs']['bbd_entries_json'] ?? null) === '[]' ? [] : ['ofertele nu s-au șters: '
                . json_encode($stare['produs']['bbd_entries_json'] ?? null, JSON_UNESCAPED_UNICODE)]),
        'verifica_curent' => static fn (array $stare): array => array_merge(
            ($stare['produs']['short_description'] ?? null) === $descriereScurtaReparatie ? [] : ['salvarea nu a ajuns în bază'],
            ($stare['produs']['bbd_entries_json'] ?? null) === $oferteSalvateJson ? [] : ['ofertele nu au rămas cele din bază: '
                . json_encode($stare['produs']['bbd_entries_json'] ?? null, JSON_UNESCAPED_UNICODE)]),
        'diferente' => ['bază: produs.bbd_entries_json: "[]" → ' . json_encode($oferteSalvateJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
    ];
    $salvari[] = [
        'nume' => 'blog: o postare cu categoria doar ca text (importată) o păstrează la re-salvare',
        'tip' => 'reparatie',
        'reparatie' => 'WP-12 X1b',
        'tabele' => $tabeleAlePostarii($idPostareDoarText),
        'pregateste' => static function (PDO $db) use ($idPostareDoarText, $categorieDoarTextDemo): void {
            $db->prepare('UPDATE blog_posts SET category = :c, category_id = NULL WHERE id = :id')
                ->execute(['c' => $categorieDoarTextDemo, 'id' => $idPostareDoarText]);
            $db->prepare('DELETE FROM blog_post_categories WHERE post_id = :id')->execute(['id' => $idPostareDoarText]);
        },
        'cereri' => [
            ['GET', '/admin/blog/posts/editor?post=' . $idPostareDoarText, []],
            static fn (array $raspunsuri): array => ['POST', '/admin/blog/posts',
                $corpPostareDin($postareDoarText, $rezumatReparatie, $campuriCategorieDinEditor((string) ($raspunsuri[0]['corp'] ?? '')))],
        ],
        'stare' => static fn (PDO $db): array => $starePostare($db, $idPostareDoarText),
        'verifica' => static fn (array $stare): array => array_merge($salvataReparatia($stare),
            ($stare['postare']['category'] ?? null) === null && ($stare['postare']['category_id'] ?? null) === null && $stare['categorii'] === []
                ? [] : ['categoria nu s-a pierdut la re-salvare: ' . json_encode($stare, JSON_UNESCAPED_UNICODE)]),
        'verifica_curent' => static fn (array $stare, array $raspunsuri): array => array_merge($salvataReparatia($stare),
            ($stare['postare']['category'] ?? null) === $categorieDoarTextDemo && ($stare['postare']['category_id'] ?? null) === null
                && $stare['categorii'] === [] ? [] : ['categoria doar ca text nu a rămas: ' . json_encode($stare, JSON_UNESCAPED_UNICODE)],
            str_contains((string) ($raspunsuri[0]['corp_brut'] ?? ''), 'data-categorie-doar-text') ? [] : ['editorul nu arată categoria']),
        'diferente' => ['bază: postare.category: null → ' . json_encode($categorieDoarTextDemo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
    ];
    $salvari[] = [
        'nume' => 'blog: categoria doar ca text, cu numele unei categorii din listă, e bifată și se leagă la re-salvare',
        'tip' => 'reparatie',
        'reparatie' => 'WP-12 X1b',
        'tabele' => array_merge($tabeleAlePostarii($idPostareTextCaNume), [
            ['tabel' => 'blog_categories', 'unde' => 'id > :c1', 'param' => ['c1' => $idMaxCategoriiBlog], 'cheie' => 'id'],
        ]),
        'pregateste' => static function (PDO $db) use ($idPostareTextCaNume, $numeCategorieDupaNume, $idCategorieDupaNume): void {
            $db->prepare('INSERT INTO blog_categories (id, name, slug, sort_order, is_active) VALUES (:id, :n, :s, 90, 1)')
                ->execute(['id' => $idCategorieDupaNume, 'n' => $numeCategorieDupaNume, 's' => 'categorie-importata-compara']);
            $db->prepare('UPDATE blog_posts SET category = :c, category_id = NULL WHERE id = :id')
                ->execute(['c' => $numeCategorieDupaNume, 'id' => $idPostareTextCaNume]);
            $db->prepare('DELETE FROM blog_post_categories WHERE post_id = :id')->execute(['id' => $idPostareTextCaNume]);
        },
        'cereri' => [
            ['GET', '/admin/blog/posts/editor?post=' . $idPostareTextCaNume, []],
            static fn (array $raspunsuri): array => ['POST', '/admin/blog/posts',
                $corpPostareDin($postareTextCaNume, $rezumatReparatie, $campuriCategorieDinEditor((string) ($raspunsuri[0]['corp'] ?? '')))],
        ],
        'stare' => static fn (PDO $db): array => $starePostare($db, $idPostareTextCaNume),
        'verifica' => static fn (array $stare): array => array_merge($salvataReparatia($stare),
            ($stare['postare']['category'] ?? null) === null && ($stare['postare']['category_id'] ?? null) === null && $stare['categorii'] === []
                ? [] : ['categoria nu s-a pierdut la re-salvare: ' . json_encode($stare, JSON_UNESCAPED_UNICODE)]),
        'verifica_curent' => static fn (array $stare): array => array_merge($salvataReparatia($stare),
            ($stare['postare']['category'] ?? null) === $numeCategorieDupaNume
                && (int) ($stare['postare']['category_id'] ?? 0) === $idCategorieDupaNume && $stare['categorii'] === [$idCategorieDupaNume]
                ? [] : ['postarea nu e legată de categoria cu același nume: ' . json_encode($stare, JSON_UNESCAPED_UNICODE)]),
        'diferente' => [
            'bază: postare.category: null → ' . json_encode($numeCategorieDupaNume, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'bază: postare.category_id: null → ' . json_encode($idCategorieCaInBaza),
            'bază: categorii.0: null → ' . json_encode($idCategorieDupaNume),
        ],
    ];
    // WP-13 (revizuirea): vederile de rezervă ale nucleului, pe care tema le atinge (views/site/not-found.php,
    // checkout-success.php, checkout-bt-verificare.php), și sertarul de meniu {{mobile_menu}} din layout (logo-ul lui).
    // Lista de pagini nu le vede: baza demo are paginile ei „404” și „checkout/succes” (publicate, deci acelea se
    // afișează), nimic nu cere /checkout/bt/retur sau un produs care nu există, iar antetul demo n-are sertarul. Aici,
    // pe durata cazului, cele două pagini din bază sunt nepublicate și antetul are {{cart_count}} și {{mobile_menu}};
    // un vizitator nou cere paginile pe ambele servere. Fără tema.json trebuie să iasă octet cu octet ca la Bioscem.
    $antetRezerva = '<header class="antet-test-rezerva"><a href="/">Firma Demo</a> <span class="cos">{{cart_count}}</span>{{mobile_menu}}</header>';
    $salvari[] = [
        'nume' => 'paginile de rezervă (404, produs lipsă, verificarea plății BT, mulțumirea) și sertarul de meniu, fără paginile din bază',
        'tip' => 'vederi',
        'din' => 'WP-13',
        'tabele' => [
            ['tabel' => 'pages', 'unde' => 'slug IN (\'404\', \'checkout/succes\')', 'cheie' => 'id'],
            ['tabel' => 'settings', 'unde' => '`key` = \'design_header_html\'', 'cheie' => 'key'],
        ],
        'pregateste' => static function (PDO $db) use ($antetRezerva): void {
            $db->exec("UPDATE pages SET is_published = 0 WHERE slug IN ('404', 'checkout/succes')");
            $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
                ->execute(['design_header_html', $antetRezerva]);
        },
        // Mulțumirea ultima: ține minte în sesiune conversia comenzii (pentru pagina următoare).
        'cereri' => [
            ['GET', '/', [], 'viz'],
            ['GET', '/pagina-care-nu-exista', [], 'viz'],
            ['GET', '/produs/nimic-de-gasit-aici-zzz', [], 'viz'],
            ['GET', '/checkout/bt/retur', [], 'viz'],
            ['GET', '/checkout/succes/' . rawurlencode($nrComanda), [], 'viz'],
        ],
        'stare' => static fn (PDO $db): array => [],
        // Pe codul de bază: fiecare cerere a dat vederea nucleului (nu pagina din bază, nu o redirecționare).
        'verifica' => static function (array $stare, array $raspunsuri) use ($contine): array {
            $asteptat = [
                ['/ (sertarul)', 200, ['data-bv-mobile-menu-drawer', 'alt="Bioscem" loading="lazy">', 'class="antet-test-rezerva"']],
                ['404', 404, ['Este posibil ca linkul să fie vechi. Caută produsul dorit sau vezi tot magazinul.', 'background:#2f8d5b;']],
                ['produsul lipsă', 404, ['Produsul căutat nu mai există', 'background:#2f8d5b;']],
                ['verificarea BT', 200, ['Înapoi în magazin</a>', 'background:#2f8d5b;']],
                ['mulțumirea', 200, ['--bs-succes-accent:#075d61;', 'class="bs-succes__titlu"']],
            ];
            $probleme = [];
            foreach ($asteptat as $n => [$ce, $cod, $texte]) {
                if ((int) ($raspunsuri[$n]['cod'] ?? 0) !== $cod) {
                    $probleme[] = $ce . ': codul ' . (int) ($raspunsuri[$n]['cod'] ?? 0) . ', aștept ' . $cod;
                }
                foreach ($texte as $text) {
                    if (!$contine($raspunsuri[$n] ?? [], $text)) {
                        $probleme[] = $ce . ': lipsește „' . $text . '”';
                    }
                }
            }
            return $probleme;
        },
    ];

    foreach ($salvari as $caz) {
        $regresie = ($caz['tip'] ?? 'schimbare') === 'regresie';
        $reparatie = ($caz['tip'] ?? 'schimbare') === 'reparatie';
        $vederi = ($caz['tip'] ?? 'schimbare') === 'vederi';
        // „sursa”: al cui commit e cazul (implicit Bioscem; un port din alt site vechi, de exemplu vertij).
        $eticheta = str_pad($vederi ? 'vederi' : 'salvare', 13) . $caz['nume'] . ' (' . ($reparatie ? 'reparație ' . (string) ($caz['reparatie'] ?? '?')
            : ($vederi ? 'nucleul, ' . $caz['din'] : ($caz['sursa'] ?? 'Bioscem') . ' ' . $caz['din'] . ($regresie ? ', regresie' : ''))) . ')';
        $original = salvari_instantaneu($db, $caz['tabele']);
        $rezultate = [];
        $probleme = [];
        $cereriFacute = [];
        try {
            foreach (['baza' => $portBaza, 'curent' => $portCurent] as $server => $port) {
                salvari_pune_la_loc($db, $caz['tabele'], $original);
                ($caz['pregateste'])($db, $server);
                // Două sesiuni pe server: administratorul (logat aici) și un vizitator nou.
                $jaruriCaz = [
                    'adm' => $dosarDiff . '/jar-' . $server . '-salvare.txt',
                    'viz' => $dosarDiff . '/jar-' . $server . '-salvare-viz.txt',
                    'viz-acord' => $dosarDiff . '/jar-' . $server . '-salvare-viz-acord.txt',
                ];
                foreach ($jaruriCaz as $jarCaz) {
                    @unlink($jarCaz);
                }
                // Un vizitator nou care a apăsat „Accept toate” în bannerul de cookie-uri (cookie-ul lui CookieConsent).
                file_put_contents($jaruriCaz['viz-acord'], "127.0.0.1\tFALSE\t/\tFALSE\t0\t" . \App\Support\CookieConsent::nume() . "\t"
                    . \App\Support\CookieConsent::TOATE . "\n");
                $cerere($port, 'POST', '/admin/login', $jaruriCaz['adm'], ['email' => $adminEmail, 'password' => $parola]);
                // O cerere e [metodă, cale, date, rol = 'adm' | 'viz', 'pauza' => secunde înainte] sau o
                // funcție care o face din răspunsurile de până atunci (de exemplu tokenul formularului).
                $raspunsuriBrute = [];
                $cereriFacute[$server] = [];
                foreach ($caz['cereri'] as $cerereCaz) {
                    if (is_callable($cerereCaz)) {
                        $cerereCaz = $cerereCaz($raspunsuriBrute, $server);
                    }
                    [$metoda, $cale, $date] = $cerereCaz;
                    if ((float) ($cerereCaz['pauza'] ?? 0) > 0) {
                        usleep((int) ((float) $cerereCaz['pauza'] * 1000000));
                    }
                    $cereriFacute[$server][] = [$metoda, $cale];
                    $raspunsuriBrute[] = $cerere($port, $metoda, $cale, $jaruriCaz[(string) ($cerereCaz[3] ?? 'adm')], $date);
                }
                $stare = ($caz['stare'])($db, $server);
                $inlocuiri = (array) ($stare['_inlocuiri'] ?? []);
                unset($stare['_inlocuiri']);
                $curata = $caz['curata'] ?? static fn (string $text): string => $text;
                $raspunsuri = [];
                foreach ($raspunsuriBrute as $n => $r) {
                    // O salvare din admin face un token nou, la întâmplare, pentru versiunea fișierelor
                    // statice (ResponseCache::generateAssetVersionToken): diferă de la un server la altul.
                    $corp = (string) preg_replace('/(&(?:amp;)?v=)[0-9a-f]{12}\b/', '$1<TOKEN-CACHE>', $curata(strtr($r['corp'], $inlocuiri)));
                    // Diferențele declarate se scot, ca în lista de pagini, doar din paginile cerute cu GET (nu din redirecționări).
                    if ($server === 'curent' && $cereriFacute[$server][$n][0] === 'GET' && ($r['cod'] < 300 || $r['cod'] >= 400)) {
                        $gasiteSalvare = [];
                        $corp = $faraDeclarate((string) $cereriFacute[$server][$n][1], $corp, $gasiteSalvare, $probleme);
                    }
                    $raspunsuri[$n] = [
                        // Corpul primit, neatins (doar pentru „verifica_curent” al unei reparații).
                        'corp_brut' => $r['corp'],
                        'cod' => $r['cod'],
                        'location' => $curata(strtr(str_replace('127.0.0.1:' . $portBaza, '127.0.0.1:' . $portCurent, $r['location']), $inlocuiri)),
                        'antete' => $normalizeazaAntete($r['antete']),
                        'corp' => $normalizeaza($corp),
                    ];
                }
                $rezultate[$server] = ['raspunsuri' => $raspunsuri, 'stare' => $stare];
            }
        } finally {
            salvari_pune_la_loc($db, $caz['tabele'], $original);
            if (isset($caz['la_final'])) {
                ($caz['la_final'])($db);
            }
            foreach (glob($dosarDiff . '/jar-*-salvare*.txt') ?: [] as $jarCaz) {
                @unlink($jarCaz);
            }
        }
        if (salvari_instantaneu($db, $caz['tabele']) !== $original) {
            $probleme[] = 'baza nu a putut fi pusă la loc exact (tools/dev/lib/salvari-admin.php)';
        }
        foreach (($caz['verifica'])($rezultate['baza']['stare'], $rezultate['baza']['raspunsuri']) as $problema) {
            $probleme[] = ($reparatie
                ? 'codul Bioscem nu arată defectul pe care îl repară ' . (string) ($caz['reparatie'] ?? '?') . ': '
                : ($vederi ? 'codul de bază nu arată vederile cazului (paginile din bază încă publicate?): ' : ($regresie
                    ? 'codul de bază nu dă rezultatul de pe ramura pe care ' . $caz['din'] . ' nu o schimbă: '
                    : (isset($caz['sursa'])
                        ? 'codul Bioscem nu dă ce dădea înaintea portului din ' . $caz['sursa'] . ' ' . $caz['din'] . ': '
                        : 'codul de bază nu arată schimbarea cazului (are commit-ul ' . $caz['din'] . '?): ')))) . $problema;
        }
        $diferente = salvari_diferente($rezultate['baza']['stare'], $rezultate['curent']['stare'], 'bază: ');
        if ($reparatie) {
            // O reparație: codul curent trebuie să arate reparația, iar starea să difere exact prin ce e declarat.
            if (trim((string) ($caz['reparatie'] ?? '')) === '' || !is_array($caz['diferente'] ?? null) || !isset($caz['verifica_curent'])) {
                $probleme[] = 'reparația e declarată incomplet (fiecare cu „reparatie”, „verifica_curent” și „diferente”)';
            } else {
                foreach (($caz['verifica_curent'])($rezultate['curent']['stare'], $rezultate['curent']['raspunsuri']) as $problema) {
                    $probleme[] = 'codul curent nu arată reparația ' . $caz['reparatie'] . ': ' . $problema;
                }
                $reale = $diferente;
                $declarateStare = array_map('strval', $caz['diferente']);
                sort($reale);
                sort($declarateStare);
                $diferente = $reale === $declarateStare ? [] : array_merge(['starea diferă altfel decât e declarat; declarat: '
                    . implode(' | ', $declarateStare)], $reale === [] ? ['(starea e aceeași pe ambele servere)'] : $reale);
            }
        }
        if (count($rezultate['baza']['raspunsuri'] ?? []) !== count($rezultate['curent']['raspunsuri'] ?? [])) {
            $diferente[] = 'alt număr de cereri pe cele două servere';
        }
        foreach ($rezultate['baza']['raspunsuri'] as $n => $rBaza) {
            $rCurent = $rezultate['curent']['raspunsuri'][$n] ?? ['cod' => 0, 'location' => '', 'antete' => [], 'corp' => ''];
            foreach (['cod', 'location', 'antete', 'corp'] as $camp) {
                if ($rBaza[$camp] !== $rCurent[$camp]) {
                    $diferente[] = 'cererea ' . ($n + 1) . ': ' . $camp . ' diferă';
                }
            }
            if ($rBaza['corp'] !== $rCurent['corp'] || $rBaza['antete'] !== $rCurent['antete']) {
                $nume = 'salvare_' . (preg_replace('/[^a-z0-9]+/i', '_', $caz['nume']) ?: 'caz') . '_' . ($n + 1);
                file_put_contents($dosarDiff . '/' . $nume . '.baza.html', $cuAntete($rBaza['antete'], $rBaza['corp']));
                file_put_contents($dosarDiff . '/' . $nume . '.curent.html', $cuAntete($rCurent['antete'], $rCurent['corp']));
                file_put_contents($dosarDiff . '/' . $nume . '.diff', (string) shell_exec('diff -u '
                    . escapeshellarg($dosarDiff . '/' . $nume . '.baza.html') . ' ' . escapeshellarg($dosarDiff . '/' . $nume . '.curent.html') . ' 2>&1'));
            }
        }
        if ($probleme === [] && $diferente === []) {
            $identice++;
            echo '  OK    ', $rezultate['curent']['raspunsuri'][0]['cod'], '  ', $eticheta, "\n";
            continue;
        }
        $diferite[] = $eticheta;
        echo '  DIFERĂ ', $eticheta, "\n";
        foreach (array_merge($probleme, array_slice($diferente, 0, 12)) as $rand) {
            echo '          ', mb_substr($rand, 0, 400), "\n";
        }
    }
}

// Fiecare declarație (text sau marcaj) găsită măcar o dată în toată rularea, pe paginile sau în cazurile de salvare.
foreach ($listeDeclarate as $numeLista => $lista) {
    foreach ($lista as $intrare) {
        $etichetaDeclaratiei = $numeLista . ' „' . (string) ($intrare['nume'] ?? '') . '”';
        if (($gasiteInRulare[$etichetaDeclaratiei] ?? 0) === 0) {
            $diferite[] = $etichetaDeclaratiei;
            echo '  DIFERĂ ', $etichetaDeclaratiei, ": declarată, dar negăsită pe nicio pagină cerută (pagina ei nu se mai cere?)\n";
        }
    }
}
foreach ($marcajePort as $numeMarcaj => $_) {
    if (($gasiteInRulare['marcaj ' . $numeMarcaj] ?? 0) === 0) {
        $diferite[] = 'marcaj ' . $numeMarcaj;
        echo '  DIFERĂ marcajul ', $numeMarcaj, ": declarat, dar negăsit pe nicio pagină cerută\n";
    }
}

foreach (glob($dosarDiff . '/jar-*') ?: [] as $jar) {
    @unlink($jar);
}
$db->prepare('DELETE FROM pages WHERE slug = :slug')->execute(['slug' => $paginaListe]);
$declarate = [];
foreach ($listeDeclarate + $listeCoduri as $numeLista => $lista) {
    if ($lista !== []) {
        $declarate[] = $numeLista . ' ' . count($lista);
    }
}
if ($marcajePort !== []) {
    $declarate[] = count($marcajePort) . ' marcaje de port';
}
echo "\n", 'Față de ', $commitBaza, ': ', $identice, ' pagini identice (cod, Location, anteturi, corp), ', count($diferite), ' diferite; ',
    $fataDeCommit ? count($diferenteSeoAsteptate) . ' pagini cu diferențele SEO așteptate (livrarea 1 SEO), ' : '',
    count($diferentePorturi), ' pagini cu diferențe declarate (', $declarate === [] ? 'nicio declarație' : implode(', ', $declarate),
    "), scoase explicit înainte de comparare.\n";
exit($diferite === [] ? 0 : 1);
