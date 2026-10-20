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
    echo $esecuri === [] ? "Mecanismul diferențelor așteptate (texte, marcaje de port, anteturi): OK\n" : count($esecuri) . " cazuri greșite.\n";
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
 * uită la rezultatul codului de bază: cazul trebuie să arate schimbarea pentru care există
 * (commit-ul „din”), altfel pică, în loc să treacă în gol.
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
    $corpPostare = static fn (array $categorii, array $extra = []): array => [
        'action' => 'save',
        'id' => (string) $idPostare,
        'title' => (string) ($postare['title'] ?? ''),
        'slug' => (string) ($postare['slug'] ?? ''),
        'excerpt' => (string) ($postare['excerpt'] ?? ''),
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
            'nume' => 'blog: re-salvarea păstrează categoriile și categoria principală',
            'din' => 'b3781a4',
            'tabele' => $tabelePostare,
            'pregateste' => $pregatestePostarea,
            'cereri' => [['POST', '/admin/blog/posts', $corpPostare([$cat1, $cat2, $cat3])]],
            'stare' => static fn (PDO $db): array => $starePostare($db, $idPostare),
            'verifica' => static fn (array $stare): array => (int) ($stare['postare']['category_id'] ?? 0) === $cat2
                && $stare['categorii'] === [$cat1, $cat2, $cat3] ? [] : ['principala sau categoriile s-au schimbat: ' . json_encode($stare)],
        ],
        [
            'nume' => 'blog: principala debifată, principala devine prima bifată',
            'din' => 'b3781a4',
            'tabele' => $tabelePostare,
            'pregateste' => $pregatestePostarea,
            'cereri' => [['POST', '/admin/blog/posts', $corpPostare([$cat1, $cat3])]],
            'stare' => static fn (PDO $db): array => $starePostare($db, $idPostare),
            'verifica' => static fn (array $stare): array => (int) ($stare['postare']['category_id'] ?? 0) === $cat1
                && $stare['categorii'] === [$cat1, $cat3] ? [] : ['așteptam principala ' . $cat1 . ': ' . json_encode($stare)],
        ],
        [
            'nume' => 'blog: categoria trimisă explicit (category_id) devine principala',
            'din' => 'b3781a4',
            'tabele' => $tabelePostare,
            'pregateste' => $pregatestePostarea,
            'cereri' => [['POST', '/admin/blog/posts', $corpPostare([$cat1, $cat2, $cat3], ['category_id' => (string) $cat3])]],
            'stare' => static fn (PDO $db): array => $starePostare($db, $idPostare),
            'verifica' => static fn (array $stare): array => (int) ($stare['postare']['category_id'] ?? 0) === $cat3
                ? [] : ['așteptam principala ' . $cat3 . ': ' . json_encode($stare)],
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

    foreach ($salvari as $caz) {
        $eticheta = str_pad('salvare', 13) . $caz['nume'] . ' (Bioscem ' . $caz['din'] . ')';
        $original = salvari_instantaneu($db, $caz['tabele']);
        $rezultate = [];
        $probleme = [];
        try {
            foreach (['baza' => $portBaza, 'curent' => $portCurent] as $server => $port) {
                salvari_pune_la_loc($db, $caz['tabele'], $original);
                ($caz['pregateste'])($db);
                $jar = $dosarDiff . '/jar-' . $server . '-salvare.txt';
                @unlink($jar);
                $cerere($port, 'POST', '/admin/login', $jar, ['email' => $adminEmail, 'password' => $parola]);
                $raspunsuri = [];
                foreach ($caz['cereri'] as [$metoda, $cale, $date]) {
                    $raspunsuri[] = $cerere($port, $metoda, $cale, $jar, $date);
                }
                $stare = ($caz['stare'])($db);
                $inlocuiri = (array) ($stare['_inlocuiri'] ?? []);
                unset($stare['_inlocuiri']);
                foreach ($raspunsuri as $n => $r) {
                    $corp = strtr($r['corp'], $inlocuiri);
                    // Diferențele declarate se scot, ca în lista de pagini, doar din paginile cerute cu GET.
                    if ($server === 'curent' && $caz['cereri'][$n][0] === 'GET') {
                        $gasiteSalvare = [];
                        $corp = $faraDeclarate((string) $caz['cereri'][$n][1], $corp, $gasiteSalvare, $probleme);
                    }
                    $raspunsuri[$n] = [
                        'cod' => $r['cod'],
                        'location' => strtr(str_replace('127.0.0.1:' . $portBaza, '127.0.0.1:' . $portCurent, $r['location']), $inlocuiri),
                        'antete' => $normalizeazaAntete($r['antete']),
                        'corp' => $normalizeaza($corp),
                    ];
                }
                $rezultate[$server] = ['raspunsuri' => $raspunsuri, 'stare' => $stare];
            }
        } finally {
            salvari_pune_la_loc($db, $caz['tabele'], $original);
            @unlink($dosarDiff . '/jar-baza-salvare.txt');
            @unlink($dosarDiff . '/jar-curent-salvare.txt');
        }
        if (salvari_instantaneu($db, $caz['tabele']) !== $original) {
            $probleme[] = 'baza nu a putut fi pusă la loc exact (tools/dev/lib/salvari-admin.php)';
        }
        foreach (($caz['verifica'])($rezultate['baza']['stare'], $rezultate['baza']['raspunsuri']) as $problema) {
            $probleme[] = 'codul de bază nu arată schimbarea cazului (are commit-ul ' . $caz['din'] . '?): ' . $problema;
        }
        $diferente = salvari_diferente($rezultate['baza']['stare'], $rezultate['curent']['stare'], 'bază: ');
        foreach ($rezultate['baza']['raspunsuri'] as $n => $rBaza) {
            $rCurent = $rezultate['curent']['raspunsuri'][$n];
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
foreach ($listeDeclarate as $numeLista => $lista) {
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
