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
 * acel commit ($porturiBioscem): fiecare exact o dată, pe pagina ei.
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
    return ['cod' => $cod, 'corp' => substr($raspuns, $marimeAntet), 'location' => $location, 'antete' => $antet];
};

// Anteturile răspunsului se compară și ele (tools/dev/lib/diferente-asteptate.php → diferente_antete).
$normalizeazaAntete = static fn (string $antet): array => diferente_antete($antet, $portBaza, $portCurent);

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
        // Versiunea fișierelor statice = data fișierului pe disc (și variantele WebP ale logo-ului din antet).
        '/(\.(?:css|js|webp)\?v=)\d+/' => '$1<V>',
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
$porturiBiovitality = [
];
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
    // Pagina din bază „checkout/succes” deschisă direct (cod de stare declarat în $coduriDeBioscem).
    ['anon', '/checkout/succes'],
    // Pingul monitorizării (WP-07, nou; declarat în $coduriDeBioscem), și cu „/” la sfârșit.
    ['anon', '/_andaxi/ping'],
    ['anon', '/_andaxi/ping/'],
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
            'verifica' => static fn (array $stare, array $raspunsuri): array => array_merge(
                ($stare['nume'][0]['value'] ?? null) === 'Magazinul de Test' ? [] : ['numele salvat: ' . json_encode($stare['nume'], JSON_UNESCAPED_UNICODE)],
                $contine($raspunsuri[2], 'Magazinul de Test</title>') ? [] : ['titlul produsului nu are numele magazinului']),
        ],
        [
            'nume' => 'livrarea cu prețuri fixe: în datele structurate ale produselor și în fluxul Merchant Center',
            'din' => 'dfcf6bd, 1e46f9f',
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
                // Sub prag: tariful la adresă; peste prag, la preț întreg: gratuit; la reducere: nimic.
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
                if ($contine($raspunsuri[2], '"shippingDetails"')) {
                    $probleme[] = 'produsul la reducere are livrare în datele structurate';
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

    foreach ($salvari as $caz) {
        $regresie = ($caz['tip'] ?? 'schimbare') === 'regresie';
        // „sursa”: al cui commit e cazul (implicit Bioscem; un port din alt site vechi, de exemplu vertij).
        $eticheta = str_pad('salvare', 13) . $caz['nume'] . ' (' . ($caz['sursa'] ?? 'Bioscem') . ' ' . $caz['din']
            . ($regresie ? ', regresie' : '') . ')';
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
                file_put_contents($jaruriCaz['viz-acord'], "127.0.0.1\tFALSE\t/\tFALSE\t0\t" . \App\Support\CookieConsent::COOKIE . "\t"
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
                    // Diferențele declarate se scot, ca în lista de pagini, doar din paginile cerute cu GET.
                    if ($server === 'curent' && $cereriFacute[$server][$n][0] === 'GET') {
                        $gasiteSalvare = [];
                        $corp = $faraDeclarate((string) $cereriFacute[$server][$n][1], $corp, $gasiteSalvare, $probleme);
                    }
                    $raspunsuri[$n] = [
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
            $probleme[] = ($regresie
                ? 'codul de bază nu dă rezultatul de pe ramura pe care ' . $caz['din'] . ' nu o schimbă: '
                : (isset($caz['sursa'])
                    ? 'codul Bioscem nu dă ce dădea înaintea portului din ' . $caz['sursa'] . ' ' . $caz['din'] . ': '
                    : 'codul de bază nu arată schimbarea cazului (are commit-ul ' . $caz['din'] . '?): ')) . $problema;
        }
        $diferente = salvari_diferente($rezultate['baza']['stare'], $rezultate['curent']['stare'], 'bază: ');
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
