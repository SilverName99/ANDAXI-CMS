<?php

declare(strict_types=1);

/**
 * Testele amprentei paginilor (WP-11, G3; docs/VERIFICARE.md, „Amprenta paginilor”): tools/verificare/amprenta-site.php,
 * lib/amprenta.php și normalizarea comună tools/dev/lib/normalizeaza-html.php. Îl rulează tools/dev/compara-calcule-local.sh
 * (poarta G-calc), cu același cod de bază ca G-cmp:
 *
 *   tools/dev/php.sh tools/dev/test-amprenta-site.php --cod-vechi=<copia codului Bioscem>
 *
 * A. Fără server: normalizarea (bannerul copiei, gazda în adrese, cu și fără www., în JSON și codată, nu în adresa de email,
 *    textele aleatoare, tokenurile, versiunea pozei de distribuire); câmpurile unei pagini inventate (titlul, robots,
 *    canonical, H1, tipurile JSON-LD de la orice adâncime, linkurile interne, formularele, poza de distribuire, copia);
 *    gazda sursă (aceeași pagină, cu linkuri absolute spre site-ul copiat, pe gazda copiei: alte linkuri și alt canonical
 *    fără ea, aceleași câmpuri și același corp cu ea; amprenta gazdei); versiunea pozei de distribuire (în calea rândului,
 *    la alegerea celor 20 de poze: aceleași poze oricare ar fi data fișierelor); adresele pe care unealta nu le cere
 *    niciodată; fișierul (scris 0600, citit înapoi, cu amprenta gazdei sursă, stricat sau de altă versiune → refuz);
 *    comparația pe amprente inventate: aceeași → „LA FEL”, câte o schimbare pe fiecare câmp structural → „DIFERĂ” cu
 *    câmpul și adresa, corpul doar cu --strict, o adresă doar într-una, doar versiunea pozei alta → „LA FEL”, alte gazde
 *    sursă → refuz, o copie de verificare față de un site (câmpurile gărzilor necomparate și spuse; --strict refuzat);
 *    refuzurile din linia de comandă (fără nicio cerere: parola în linia de comandă, parola pe http, pauza sub 1 s pe un
 *    site din afară, --gazda-sursa greșită, argumentele).
 * B. Cu servere (pe cms_amprenta_t, o copie a bazei de dezvoltare, cu o poză de produs pentru poza de distribuire și, în
 *    conținut, linkuri absolute spre serverul Bioscem, ca linkurile spre bioscem.ro din descrierile și paginile importate
 *    din WordPress: în prima pagină, în descrierea unui produs, un canonical pus de mână în seo_pages): codul Bioscem pe
 *    CMS_DEV_PORT+16, codul comun cu pachetul Bioscem pe +17, o copie de verificare a codului comun (poarta, gărzile) pe +18,
 *    toate trei pe aceeași bază:
 *    B1 codul comun de două ori (cu --gazda-sursa a serverului Bioscem): --strict „LA FEL” (normalizarea scoate tot ce
 *       diferă de la o cerere la alta);
 *    B2 codul Bioscem față de codul comun, cu poza de produs a codului Bioscem cu altă dată (altă versiune /og/): câmpurile
 *       structurale „LA FEL”, adresele din linkurile absolute cerute pe ambele; cu --strict „DIFERĂ” doar la corpul lui
 *       /robots.txt (diferența declarată în $deBioscem din compara-cu-baza.php); fără --gazda-sursa refuzul comparației, iar
 *       paginile codului comun citite fără ea au alte linkuri și alt canonical decât cele Bioscem (cu ea, aceleași);
 *       politețea (doar GET, nicio adresă interzisă, User-Agent, pauza);
 *    B3 licența fără blog pe codul comun: „DIFERĂ” (/blog 200 → 404, articolele doar în amprenta Bioscem);
 *    B4 copia de verificare: fără parola porții „OPRIT” după o singură cerere, cu parola greșită la fel (o încercare, nu
 *       zece), cu parola dar fără --gazda-sursa tot „OPRIT” după o cerere, cu ambele amprenta copiei („copie de
 *       verificare: DA”, bannerul scos, X-Robots-Tag al porții); față de codul Bioscem „LA FEL” (și pe paginile cu linkuri
 *       absolute), cu X-Robots-Tag, poza de distribuire și /og/ spuse ca necomparate; --strict refuzat.
 *
 * Își face și își șterge baza cms_amprenta_t, serverele și dosarul $CMS_DEV_DIR/amprenta; poza de test din
 * public/uploads/products/ și cache-ul ei se șterg la final. Raportul: $CMS_DEV_DIR/test-amprenta-raport.txt; cod de ieșire 0
 * doar dacă totul a trecut. Toate datele sunt inventate.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/tools/verificare/lib/amprenta.php';

use Unelte\Verificare\AmprentaSite;
use Unelte\Verificare\ComparatieAmprente;
use Unelte\Verificare\FisierAmprenta;
use Unelte\Verificare\RefuzAmprenta;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-amprenta-raport.txt';
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
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . mb_substr($detalii, 0, 3000) : ''));
    }
};
$codVechi = '';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--cod-vechi=')) {
        $codVechi = (string) realpath(substr($argument, 12));
    } else {
        fwrite(STDERR, "argument necunoscut: $argument\n");
        exit(1);
    }
}
if ($codVechi === '' || !is_file($codVechi . '/public/index.php')) {
    fwrite(STDERR, "Lipsește --cod-vechi=<copia codului Bioscem> (o face tools/dev/compara-calcule-local.sh).\n");
    exit(1);
}
$scrie('Test amprenta paginilor ANDAXI-CMS — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION . ', codul vechi ' . basename($codVechi));

$dosarTest = $dosarDev . '/amprenta';
$port = (int) (getenv('CMS_DEV_PORT') ?: 8701);
$portVechi = $port + 16;
$portNou = $port + 17;
$portCopie = $port + 18;
$sock = (string) (getenv('CMS_DEV_SOCK') ?: '/tmp/claude-0/maria/sock');
$bazaDev = (string) getenv('DB_DATABASE');
$baza = 'cms_amprenta_t';
$pozaTest = '/uploads/products/amprenta-test-' . getmypid() . '.png';
$servere = [];
$conectare = static function (?string $db = null) use ($sock): PDO {
    return new PDO('mysql:unix_socket=' . $sock . ($db !== null ? ';dbname=' . $db : '') . ';charset=utf8mb4', (string) (getenv('CMS_DEV_DB_USER') ?: 'root'),
        (string) (getenv('CMS_DEV_DB_PAROLA') ?: ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
};
$sesiuniRadacina = array_map('basename', glob($radacina . '/storage/sessions/sess_*') ?: []);
$ogRadacina = array_map('basename', glob($radacina . '/storage/cache/og/*') ?: []);
$opreste = static function (int $pid): void {
    if ($pid > 0) {
        exec('pkill -TERM -P ' . $pid . ' 2>/dev/null; kill ' . $pid . ' 2>/dev/null');
        for ($i = 0; $i < 50 && trim((string) shell_exec('pgrep -P ' . $pid . ' 2>/dev/null; kill -0 ' . $pid . ' 2>/dev/null && echo x')) !== ''; $i++) {
            usleep(100000);
        }
    }
};
$curata = static function () use (&$servere, $opreste, $conectare, $baza, $dosarTest, $dosarDev, $radacina, $codVechi, $pozaTest, $sesiuniRadacina, $ogRadacina): void {
    foreach ($servere as $pid) {
        $opreste((int) $pid);
    }
    $servere = [];
    try {
        $conectare()->exec('DROP DATABASE IF EXISTS ' . $baza);
    } catch (Throwable) {
    }
    foreach ([$radacina, $codVechi] as $cod) {
        @unlink($cod . '/public' . $pozaTest);
    }
    foreach (array_diff(array_map('basename', glob($radacina . '/storage/sessions/sess_*') ?: []), $sesiuniRadacina) as $f) {
        @unlink($radacina . '/storage/sessions/' . $f);
    }
    foreach (array_diff(array_map('basename', glob($radacina . '/storage/cache/og/*') ?: []), $ogRadacina) as $f) {
        @unlink($radacina . '/storage/cache/og/' . $f);
    }
    // Fluxul Merchant ținut 15 minute în cache (cu pachetul Bioscem): o unealtă de după nu primește fluxul de aici. În copia
    // codului de bază, ca la compara-cu-baza.sh: cache-ul și sesiunile rulării trecute (fluxul ei ar fi altul decât al bazei de acum).
    @unlink($radacina . '/storage/cache/feed-google-merchant.xml');
    exec('find ' . escapeshellarg($codVechi . '/storage/cache') . ' -mindepth 1 -type f ! -name .gitkeep -delete 2>/dev/null; find '
        . escapeshellarg($codVechi . '/storage/sessions') . ' -maxdepth 1 -type f -name ' . escapeshellarg('sess_*') . ' -delete 2>/dev/null');
    if (str_starts_with($dosarTest, $dosarDev . '/') && is_dir($dosarTest)) {
        exec('chmod -R u+rwX ' . escapeshellarg($dosarTest) . ' 2>/dev/null; rm -rf ' . escapeshellarg($dosarTest));
    }
};
register_shutdown_function($curata);
$curata();
mkdir($dosarTest, 0770, true);

/** amprenta-site.php, cu mediul dat (fără parola porții, dacă nu e dată). */
$amprenta = static function (array $argumente, array $mediuInPlus = []) use ($radacina): array {
    $mediu = getenv();
    unset($mediu['VERIFICARE_POARTA_PAROLA']);
    $p = proc_open(array_merge([PHP_BINARY, '-d', 'display_errors=1', $radacina . '/tools/verificare/amprenta-site.php'], $argumente), [1 => ['pipe', 'w'],
        2 => ['pipe', 'w']], $tevi, $radacina, $mediuInPlus + $mediu);
    $iesire = (string) stream_get_contents($tevi[1]);
    $erori = (string) stream_get_contents($tevi[2]);
    fclose($tevi[1]);
    fclose($tevi[2]);
    $cod = proc_close($p);
    return ['cod' => $cod, 'iesire' => $iesire, 'erori' => $erori, 'tot' => $iesire . $erori];
};
/** Comparația a două amprente scrise de test, în proces (aceeași funcție ca --compara), cu ieșirea prinsă. */
$compara = static function (string $a, string $b, bool $strict = false): array {
    ob_start();
    try {
        $cod = ComparatieAmprente::ruleaza($a, $b, $strict);
    } catch (RefuzAmprenta $e) {
        echo 'REFUZAT: ', $e->getMessage(), "\n";
        $cod = 2;
    }
    return ['cod' => $cod, 'iesire' => (string) ob_get_clean()];
};

try {
    // =====================================================================
    // A. Fără server
    // =====================================================================
    $scrie('');
    $scrie('A. Fără server: normalizarea, câmpurile, politețea, fișierul, comparația, refuzurile');
    $gazda = 'magazin.exemplu.test';
    $banner = '<div id="verificare-banner" role="status" style="x">SITE DE VERIFICARE — date anonimizate, plăți de test, emailurile ajung doar la pr…@ex…ro</div>';
    $pagina = '<!doctype html><html><head><title>Ceai  de mentă | Magazin</title><meta name="robots" content="index, follow">'
        . '<link rel="canonical" href="https://www.magazin.exemplu.test/produs/ceai?x=1"><meta property="og:image" content="https://magazin.exemplu.test/og/0123456789ab/uploads/products/p.png">'
        . '<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization"},{"@type":"Product","offers":{"@type":"Offer"}}]}</script>'
        . '<script type="application/ld+json">{"@type":["BreadcrumbList"]}</script><link rel="stylesheet" href="/assets/app.css?v=1700000000"></head><body>%BANNER%'
        . '<h1>Ceai de mentă</h1><a href="/magazin">M</a><a href="https://magazin.exemplu.test/cos">C</a><a href="//www.magazin.exemplu.test/blog#sus">B</a>'
        . '<a href="https://alt-site.ro/x">X</a><a href="mailto:contact@magazin.exemplu.test">E</a><a href="#top">T</a><a href="tel:0700">T</a>'
        . '<form method="post" action="/cos/adauga"><input type="hidden" name="_csrf" value="%TOKEN%"></form><form action="https://magazin.exemplu.test/cautare"></form>'
        . '<script>var u = "https:\/\/magazin.exemplu.test\/api\/x"; var s = "https%3A%2F%2Fmagazin.exemplu.test%2Fprodus";</script>'
        . '<p>Scrie-ne la contact@magazin.exemplu.test sau pe magazin.exemplu.test.alt-site.ro</p></body></html>';
    $faraBanner = str_replace(['%BANNER%', '%TOKEN%'], ['', 'aaaa'], $pagina);
    $cuBanner = str_replace(['%BANNER%', '%TOKEN%'], [$banner, 'bbbb'], $pagina);
    $n1 = AmprentaSite::normalizeazaCorp($faraBanner, [$gazda], []);
    $n2 = AmprentaSite::normalizeazaCorp($cuBanner, [$gazda], []);
    $verifica('normalizarea: aceeași pagină cu banner și alt token = aceeași pagină fără banner', $n1 === $n2 && !str_contains($n2, 'verificare-banner'));
    $verifica('normalizarea: gazda devine {GAZDA} în adrese (https://, //www., JSON, codată), versiunea pozei {V}, ?v= <V>', str_contains($n1, 'href="{GAZDA}/cos"')
        && str_contains($n1, 'href="{GAZDA}/blog#sus"') && str_contains($n1, '"{GAZDA}\/api\/x"') && str_contains($n1, '{GAZDA}%2Fprodus')
        && str_contains($n1, '{GAZDA}/og/{V}/uploads/') && str_contains($n1, 'app.css?v=<V>') && str_contains($n1, 'name="_csrf" value="<TOKEN>"'), $n1);
    $verifica('normalizarea: adresa de email a magazinului și o altă gazdă care doar începe la fel rămân', str_contains($n1, 'contact@magazin.exemplu.test')
        && str_contains($n1, 'magazin.exemplu.test.alt-site.ro') && str_contains($n1, 'https://alt-site.ro/x'));
    $verifica('normalizarea: un text aleator ales de amprentă devine {ALEATOR}', AmprentaSite::normalizeazaCorp('<p>/pagina-care-nu-exista-ab12</p>', [$gazda], ['ab12'])
        === '<p>/pagina-care-nu-exista-{ALEATOR}</p>');
    $c = AmprentaSite::campuri($cuBanner, 'text/html;charset=utf-8', [$gazda]);
    $verifica('câmpurile: titlul (spațiile strânse), robots, canonical cu {GAZDA}, H1 1:<amprentă>, JSON-LD de la orice adâncime, sortat',
        $c['titlu'] === 'Ceai de mentă | Magazin' && $c['robots'] === 'index, follow' && $c['canonical'] === '{GAZDA}/produs/ceai?x=1'
        && str_starts_with($c['h1'], '1:') && $c['jsonld'] === 'BreadcrumbList,Offer,Organization,Product', json_encode($c, JSON_UNESCAPED_UNICODE) ?: '');
    $verifica('câmpurile: 3 linkuri interne (nu altă gazdă, mailto, tel, #), 2 formulare, poza de distribuire, copia de verificare (bannerul)',
        $c['linkuri'] === '3' && $c['linkuri_cai'] === ['/magazin', '/cos', '/blog'] && str_starts_with($c['formulare'], '2:')
        && $c['og_image'] === '{GAZDA}/og/{V}/uploads/products/p.png' && $c['og_cale'] === '/og/0123456789ab/uploads/products/p.png' && $c['copie'] === true
        && AmprentaSite::campuri($faraBanner, 'text/html', [$gazda])['copie'] === false, json_encode($c, JSON_UNESCAPED_UNICODE) ?: '');
    $verifica('câmpurile unui răspuns care nu e HTML: toate „-”', AmprentaSite::campuri('{"ok":true}', 'application/json', [$gazda])['titlu'] === '-'
        && AmprentaSite::campuri($pagina, 'text/xml', [$gazda])['h1'] === '-');

    // Gazda sursă: aceeași pagină (conținutul copiat, cu linkurile lui absolute spre site-ul copiat) servită de copie, pe altă gazdă.
    // Linkul spre /contact e al codului (scris cu gazda site-ului, APP_URL); canonical-ul, /cos, /blog și poza sunt ale conținutului.
    $gazdaCopie = 'copie.exemplu.test';
    $paginaSite = str_replace('</h1>', '</h1><a href="https://' . $gazda . '/contact">K</a>', $faraBanner);
    $peCopie = str_replace('</h1>', '</h1><a href="https://' . $gazdaCopie . '/contact">K</a>', $faraBanner);
    $campuriFara = AmprentaSite::campuri($peCopie, 'text/html', [$gazdaCopie]);
    $campuriCu = AmprentaSite::campuri($peCopie, 'text/html', [$gazdaCopie, $gazda]);
    $campuriSite = AmprentaSite::campuri($paginaSite, 'text/html', [$gazda]);
    $comparabile = static fn (array $x): array => array_intersect_key($x, array_flip(['titlu', 'robots', 'canonical', 'h1', 'jsonld', 'linkuri', 'linkuri_cai',
        'formulare', 'og_image']));
    $verifica('gazda sursă: pe gazda copiei, fără ea, linkurile absolute spre site-ul copiat sunt străine (2 linkuri interne în loc de 4, canonical-ul întreg)',
        $campuriSite['linkuri'] === '4' && $campuriFara['linkuri'] === '2' && $campuriFara['linkuri_cai'] === ['/contact', '/magazin']
        && $campuriFara['canonical'] === 'https://www.magazin.exemplu.test/produs/ceai?x=1' && $comparabile($campuriFara) !== $comparabile($campuriSite),
        json_encode([$campuriFara, $campuriSite], JSON_UNESCAPED_UNICODE) ?: '');
    $verifica('gazda sursă: cu ea, aceleași câmpuri ca pe site-ul copiat (linkurile, căile lor, canonical, formularele, poza) și același corp normalizat',
        $comparabile($campuriCu) === $comparabile($campuriSite)
        && AmprentaSite::normalizeazaCorp($peCopie, [$gazdaCopie, $gazda], []) === AmprentaSite::normalizeazaCorp($paginaSite, [$gazda], [])
        && AmprentaSite::normalizeazaCorp($peCopie, [$gazdaCopie], []) !== AmprentaSite::normalizeazaCorp($paginaSite, [$gazda], []),
        json_encode([$campuriCu, $campuriSite], JSON_UNESCAPED_UNICODE) ?: '');
    $verifica('amprenta gazdei sursă: 12 hex, aceeași cu și fără www. și cu majuscule, alta pentru altă gazdă sau alt port',
        preg_match('/^[0-9a-f]{12}$/', AmprentaSite::amprentaGazdei($gazda)) === 1 && AmprentaSite::amprentaGazdei('WWW.' . $gazda) === AmprentaSite::amprentaGazdei($gazda)
        && AmprentaSite::amprentaGazdei($gazdaCopie) !== AmprentaSite::amprentaGazdei($gazda)
        && AmprentaSite::amprentaGazdei($gazda . ':8080') !== AmprentaSite::amprentaGazdei($gazda));

    // Versiunea pozei de distribuire (data și mărimea fișierului): nu face din aceeași poză alt rând.
    $raspunsPoza = ['cod' => 200, 'location' => '', 'tip' => 'image/jpeg', 'x_robots' => '', 'corp' => 'JPEG'];
    $rPozaA = AmprentaSite::randDin('/og/aaaaaaaaaaaa/uploads/products/p.png', 'og', $raspunsPoza, [$gazda], []);
    $rPozaB = AmprentaSite::randDin('/og/bbbbbbbbbbbb/uploads/products/p.png', 'og', $raspunsPoza, [$gazda], []);
    $rRedirect = AmprentaSite::randDin('/og/cccccccccccc/uploads/products/p.png', 'og', ['cod' => 302, 'location' => 'https://bioscem.ro/og/cccccccccccc/uploads/products/p.png',
        'tip' => 'text/html', 'x_robots' => '', 'corp' => ''], [$gazda], []);
    $verifica('versiunea pozei: calea rândului /og/{V}/… (și Location), o cale care doar conține /og/ rămâne', $rPozaA['cale'] === '/og/{V}/uploads/products/p.png'
        && $rPozaA === $rPozaB && $rRedirect['location'] === 'https://bioscem.ro/og/{V}/uploads/products/p.png'
        && AmprentaSite::randDin('/blog/og/aaaaaaaaaaaa/x', 'link', $raspunsPoza, [$gazda], [])['cale'] === '/blog/og/aaaaaaaaaaaa/x',
        json_encode([$rPozaA, $rRedirect], JSON_UNESCAPED_UNICODE) ?: '');
    // Cele 20 de poze: alese după calea fără versiune. Pe site-ul A versiunile ar alege, după calea întreagă, alte poze decât pe B.
    $pozeSite = static function (callable $versiune): array {
        $randuri = [];
        for ($k = 1; $k <= 25; $k++) {
            $randuri[] = ['og_cale' => '/og/' . $versiune($k) . '/uploads/products/p' . str_pad((string) $k, 2, '0', STR_PAD_LEFT) . '.png'];
        }
        $randuri[] = ['og_cale' => '/og/' . str_repeat('e', 12) . '/uploads/products/p01.png'];
        $randuri[] = ['og_cale' => ''];
        $randuri[] = ['og_cale' => 'https://alt-site.ro/og/x.png'];
        return $randuri;
    };
    $pozeA = AmprentaSite::pozeDeCerut($pozeSite(static fn (int $k): string => substr(sha1('a' . (26 - $k)), 0, 12)));
    $pozeB = AmprentaSite::pozeDeCerut($pozeSite(static fn (int $k): string => str_repeat('b', 12)));
    $faraV = static fn (array $l): array => array_map(static fn (string $c): string => (string) preg_replace('#^/og/[0-9a-f]{12}/#', '/og/{V}/', $c), $l);
    $asteptatePoze = array_map(static fn (int $k): string => '/og/{V}/uploads/products/p' . str_pad((string) $k, 2, '0', STR_PAD_LEFT) . '.png', range(1, 20));
    $verifica('versiunea pozei: aceleași 20 de poze pe două site-uri cu alte date ale fișierelor (primele după calea fără versiune), fiecare o dată',
        $faraV($pozeA) === $asteptatePoze && $faraV($pozeB) === $asteptatePoze, json_encode([$pozeA, $pozeB]) ?: '');
    $interzise = ['/admin', '/admin/login', '/administrare', '/api/cart/summary', '/api/cart/avertismente?x=1', '/webhook/stripe', '/plata/abc', '/contul-meu',
        '/contul-meu/comenzi', '/newsletter/unsubscribe/x', '/auth/google', '/auth', '/checkout/succes/1', '/checkout/bt/retur', '/gdpr-agreements/success',
        '/_verificare/intrare', '/_andaxi/ping', '/uploads/products/x.png', '/logout', '//alt-site.ro/x', 'relativ', '/a/../admin', "/a\nb"];
    $permise = ['/', '/magazin', '/checkout', '/cos', '/blog/articol', '/api/cart/avertismente', '/magazin?category=ceaiuri', '/robots.txt', '/feed/google-merchant.xml',
        '/og/0123456789ab/uploads/products/p.png', '/produs/ceai-de-menta'];
    $gresite = array_values(array_merge(array_filter($interzise, static fn (string $c): bool => AmprentaSite::permisa($c)),
        array_filter($permise, static fn (string $c): bool => !AmprentaSite::permisa($c))));
    $verifica('politețea: niciodată admin, API (afară de casetele avertismentelor), webhook, plăți, contul, newsletter, auth, /checkout/…, GDPR, '
        . '/_verificare, /_andaxi, /uploads/, logout; paginile publice da', $gresite === [], implode(', ', $gresite));

    // Fișierul și comparația, pe amprente inventate.
    $randuri = [];
    foreach (['/', '/magazin', '/produs/ceai', '/sitemap.xml', '/og/{V}/uploads/products/p.png'] as $k => $cale) {
        $randuri[] = ['cale' => $cale, 'de_unde' => 'fix', 'cod' => 200, 'location' => '-', 'tip' => 'text/html;charset=utf-8', 'corp' => sha1($cale),
            'titlu' => 'Titlu ' . $k, 'robots' => '-', 'canonical' => '{GAZDA}' . $cale, 'h1' => '1:abc', 'jsonld' => 'Organization', 'linkuri' => '12',
            'formulare' => '1:def', 'x_robots' => '-', 'og_image' => '{GAZDA}/og/{V}/uploads/products/p.png'];
    }
    $fa = $dosarTest . '/a.tsv';
    $fb = $dosarTest . '/b.tsv';
    $sursa = AmprentaSite::amprentaGazdei($gazda);
    FisierAmprenta::scrie($fa, $randuri, false, $sursa);
    FisierAmprenta::scrie($fb, $randuri, false, $sursa);
    $citit = FisierAmprenta::citeste($fa);
    $verifica('fișierul: scris 0600, citit înapoi (5 adrese, nu e copie, amprenta gazdei sursă), fără gazdă', (fileperms($fa) & 0777) === 0600
        && $citit['adrese'] === 5 && $citit['copie'] === false && $citit['sursa'] === $sursa && !str_contains((string) file_get_contents($fa), $gazda)
        && str_starts_with((string) file_get_contents($fa), '# amprenta-site 2 | copie de verificare: NU | adrese: 5 | sursa: ' . $sursa . ' | '));
    $r = $compara($fa, $fb, true);
    $verifica('comparația: aceeași amprentă → cod 0, „LA FEL” (și cu --strict)', $r['cod'] === 0 && str_contains($r['iesire'], 'REZULTAT: LA FEL'), $r['iesire']);
    foreach (array_merge(AmprentaSite::STRUCTURALE, ['corp']) as $camp) {
        $schimbate = $randuri;
        $schimbate[2][$camp] = $camp === 'cod' ? 404 : 'altceva-' . $camp;
        FisierAmprenta::scrie($fb, $schimbate, false, $sursa);
        $r = $compara($fa, $fb);
        $rs = $compara($fa, $fb, true);
        if ($camp === 'corp') {
            $verifica('comparația: doar corpul diferit → „LA FEL” fără --strict, „DIFERĂ” cu --strict, cu adresa', $r['cod'] === 0 && $rs['cod'] === 1
                && str_contains($rs['iesire'], "diferențe pe câmp: corp 1\n  corp: /produs/ceai\n"), $r['iesire'] . $rs['iesire']);
        } else {
            $verifica('comparația: ' . $camp . ' schimbat → cod 1, „DIFERĂ”, câmpul și adresa numite', $r['cod'] === 1 && str_contains($r['iesire'], 'REZULTAT: DIFERĂ')
                && str_contains($r['iesire'], 'diferențe pe câmp: ' . $camp . ' 1') && str_contains($r['iesire'], '  ' . $camp . ': /produs/ceai'), $r['iesire']);
        }
    }
    FisierAmprenta::scrie($fb, array_slice($randuri, 0, 4), false, $sursa);
    $r = $compara($fa, $fb);
    $verifica('comparația: o adresă doar în A → „DIFERĂ”, adresa numită', $r['cod'] === 1 && str_contains($r['iesire'], 'doar în A: 1')
        && str_contains($r['iesire'], '  doar în A: /og/{V}/uploads/products/p.png'), $r['iesire']);
    // Același site înainte și după, cu pozele copiate fără datele lor (alta versiune /og/): rândurile scrise de randDin sunt aceleași.
    $paginaCuPoza = static fn (string $v): array => ['cod' => 200, 'location' => '', 'tip' => 'text/html', 'x_robots' => '',
        'corp' => '<html><head><title>T</title><meta property="og:image" content="https://' . $gazda . '/og/' . $v . '/uploads/products/p.png"></head><body></body></html>'];
    FisierAmprenta::scrie($fa, [AmprentaSite::randDin('/', 'fix', $paginaCuPoza('aaaaaaaaaaaa'), [$gazda], []), $rPozaA], false, $sursa);
    FisierAmprenta::scrie($fb, [AmprentaSite::randDin('/', 'fix', $paginaCuPoza('bbbbbbbbbbbb'), [$gazda], []), $rPozaB], false, $sursa);
    $r = $compara($fa, $fb, true);
    $verifica('comparația: doar versiunea pozei de distribuire alta (data fișierului) → „LA FEL”, și cu --strict', $r['cod'] === 0
        && str_contains($r['iesire'], 'adrese în ambele: 2 | doar în A: 0 | doar în B: 0') && str_contains($r['iesire'], 'REZULTAT: LA FEL'), $r['iesire']);
    FisierAmprenta::scrie($fa, $randuri, false, $sursa);
    FisierAmprenta::scrie($fb, $randuri, false, AmprentaSite::amprentaGazdei($gazdaCopie));
    $r = $compara($fa, $fb);
    $verifica('comparația: alte gazde sursă (o copie amprentată fără --gazda-sursa) → refuz, cod 2, cu --gazda-sursa în mesaj', $r['cod'] === 2
        && str_contains($r['iesire'], 'REFUZAT: amprentele au alte gazde sursă') && str_contains($r['iesire'], '--gazda-sursa=bioscem.ro'), $r['iesire']);
    $verifica('fișierul: o amprentă a gazdei sursă stricată nu se scrie', (static function () use ($fb, $randuri): bool {
        try {
            FisierAmprenta::scrie($fb, $randuri, false, 'bioscem.ro');
            return false;
        } catch (RefuzAmprenta) {
            return true;
        }
    })());
    $copie = $randuri;
    foreach ($copie as &$rand) {
        $rand['x_robots'] = 'noindex, nofollow, noarchive';
        $rand['og_image'] = '-';
    }
    unset($rand);
    $copie[4]['cod'] = 302;
    $copie[4]['location'] = 'https://bioscem.ro/og/{V}/uploads/products/p.png';
    FisierAmprenta::scrie($fb, $copie, true, $sursa);
    $r = $compara($fa, $fb);
    $rs = $compara($fa, $fb, true);
    $verifica('comparația cu o copie de verificare: X-Robots-Tag, poza de distribuire și /og/ necomparate și spuse, restul „LA FEL”; --strict refuzat (cod 2)',
        $r['cod'] === 0 && str_contains($r['iesire'], 'necomparate (doar B e copia de verificare): x_robots (poarta îl pune pe fiecare răspuns), og_image (garda 13')
        && str_contains($r['iesire'], 'adresele /og/ și /uploads/: 1 (garda 13)') && $rs['cod'] === 2 && str_contains($rs['iesire'], '--strict compară doar'),
        $r['iesire'] . $rs['iesire']);
    $copie[1]['titlu'] = 'Alt titlu';
    FisierAmprenta::scrie($fb, $copie, true, $sursa);
    $r = $compara($fa, $fb);
    $verifica('comparația cu o copie de verificare: un titlu schimbat tot „DIFERĂ”', $r['cod'] === 1 && str_contains($r['iesire'], 'titlu: /magazin (Titlu 1 → Alt titlu)'),
        $r['iesire']);
    file_put_contents($fb, "# amprenta-site 2 | copie de verificare: NU | adrese: 2 | sursa: " . $sursa . " | x\n" . implode("\t", AmprentaSite::COLOANE) . "\n/\tx\n");
    $r1 = $compara($fa, $fb);
    file_put_contents($fb, "nu e o amprentă\n");
    $r2 = $compara($fa, $fb);
    file_put_contents($fb, str_replace('# amprenta-site 2 ', '# amprenta-site 9 ', (string) file_get_contents($fa)));
    $r3 = $compara($fa, $fb);
    // Amprenta de dinainte de revizie (versiunea 1: fără gazda sursă, cu versiunea pozei în cale) nu se mai compară.
    file_put_contents($fb, "# amprenta-site 1 | copie de verificare: NU | adrese: 0 | x\n" . implode("\t", AmprentaSite::COLOANE) . "\n");
    $r4 = $compara($fa, $fb);
    file_put_contents($fb, str_replace(' | sursa: ' . $sursa . ' |', ' |', (string) file_get_contents($fa)));
    $r5 = $compara($fa, $fb);
    $verifica('fișiere stricate (un rând stricat, altceva, altă versiune, versiunea 1, fără gazda sursă) → refuz, cod 2', $r1['cod'] === 2 && $r2['cod'] === 2
        && $r3['cod'] === 2 && str_contains($r3['iesire'], 'altă versiune') && $r4['cod'] === 2 && str_contains($r4['iesire'], 'altă versiune a amprentei (1)')
        && $r5['cod'] === 2 && str_contains($r1['iesire'], 'rând stricat'), $r1['iesire'] . $r2['iesire'] . $r3['iesire'] . $r4['iesire'] . $r5['iesire']);

    // Refuzurile liniei de comandă: înaintea oricărei cereri (gazda .invalid nu se poate rezolva; nimic nu pleacă oricum).
    $iesireProba = $dosarTest . '/proba.tsv';
    $refuzuri = [
        'parola în linia de comandă' => [['--baza=https://site.exemplu.invalid', '--iesire=' . $iesireProba, '--parola=secret'], [], 'VERIFICARE_POARTA_PAROLA'],
        '--poarta fără VERIFICARE_POARTA_PAROLA' => [['--baza=https://site.exemplu.invalid', '--iesire=' . $iesireProba, '--poarta=verificare'], [], 'VERIFICARE_POARTA_PAROLA'],
        'parola porții pe http' => [['--baza=http://site.exemplu.invalid', '--iesire=' . $iesireProba, '--poarta=verificare'], ['VERIFICARE_POARTA_PAROLA' => 'x'], 'https'],
        'pauza sub 1 s pe un site din afară' => [['--baza=https://site.exemplu.invalid', '--iesire=' . $iesireProba, '--pauza=0.2'], [], 'cel puțin 1 secundă'],
        '--baza cu o cale' => [['--baza=https://site.exemplu.invalid/magazin', '--iesire=' . $iesireProba], [], 'fără cale'],
        '--baza alt protocol' => [['--baza=ftp://site.exemplu.invalid', '--iesire=' . $iesireProba], [], '--baza'],
        'fără --iesire' => [['--baza=https://site.exemplu.invalid'], [], '--iesire'],
        '--iesire într-un dosar inexistent' => [['--baza=https://site.exemplu.invalid', '--iesire=' . $dosarTest . '/nu/exista.tsv'], [], 'nu există'],
        '--max=0' => [['--baza=https://site.exemplu.invalid', '--iesire=' . $iesireProba, '--max=0'], [], '--max'],
        '--compara cu un singur fișier' => [['--compara', $fa], [], 'două fișiere'],
        '--gazda-sursa cu o cale' => [['--baza=https://site.exemplu.invalid', '--iesire=' . $iesireProba, '--gazda-sursa=https://bioscem.ro/magazin'], [], '--gazda-sursa'],
        '--gazda-sursa cu spațiu' => [['--baza=https://site.exemplu.invalid', '--iesire=' . $iesireProba, '--gazda-sursa=bio scem.ro'], [], '--gazda-sursa'],
        '--gazda-sursa goală' => [['--baza=https://site.exemplu.invalid', '--iesire=' . $iesireProba, '--gazda-sursa='], [], '--gazda-sursa'],
        '--gazda-sursa la --compara' => [['--compara', $fa, $fb, '--gazda-sursa=bioscem.ro'], [], 'două fișiere'],
    ];
    foreach ($refuzuri as $nume => [$argumente, $mediu, $text]) {
        $r = $amprenta($argumente, $mediu);
        $verifica('refuz: ' . $nume . ' → cod 2, „REFUZAT … ' . $text . '”, nicio amprentă scrisă', $r['cod'] === 2 && str_contains($r['tot'], 'REFUZAT')
            && str_contains($r['tot'], $text) && !is_file($iesireProba) && !str_contains($r['tot'], 'secret'), 'cod ' . $r['cod'] . ': ' . $r['tot']);
    }
    $phpMinim = preg_match("/'php_min'\\s*=>\\s*'([0-9.]+)'/", (string) file_get_contents($radacina . '/config/app.php'), $p) === 1 ? $p[1] : '';
    foreach (['php7.0', 'php8.0', 'php8.2'] as $binar) {
        $cale = trim((string) shell_exec('command -v ' . $binar . ' 2>/dev/null'));
        if ($cale === '' || version_compare(substr($binar, 3), $phpMinim, '>=')) {
            continue;
        }
        exec(escapeshellarg($cale) . ' ' . escapeshellarg($radacina . '/tools/verificare/amprenta-site.php') . ' --compara a b 2>&1', $iesire, $cod);
        $verifica($binar . ': amprenta-site.php spune că PHP-ul e prea vechi (cod 1, fără eroare de sintaxă)', $cod === 1
            && str_contains(implode("\n", $iesire), 'mai vechi decât minimul') && !str_contains(implode("\n", $iesire), 'Parse error'), implode(' ', $iesire));
        $iesire = [];
    }

    // =====================================================================
    // B. Cu servere
    // =====================================================================
    $scrie('');
    $scrie('B. Cu servere: codul Bioscem (+16), codul comun cu pachetul Bioscem (+17), o copie de verificare a codului comun (+18)');
    $root = $conectare();
    if (preg_match('/^cms_[a-z0-9_]+$/', $bazaDev) !== 1
        || (int) $root->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' . $root->quote($bazaDev) . " AND TABLE_NAME IN ('products', '_platforma_stare')")
            ->fetchColumn() !== 2) {
        throw new RuntimeException('baza de dezvoltare (DB_DATABASE) lipsește sau nu e făcută prin migrări: rulează întâi tools/dev/instaleaza.sh');
    }
    $root->exec('CREATE DATABASE ' . $baza . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    foreach ($root->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' . $root->quote($bazaDev) . " AND TABLE_TYPE = 'BASE TABLE'")
        ->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $root->exec('CREATE TABLE `' . $baza . '`.`' . $t . '` LIKE `' . $bazaDev . '`.`' . $t . '`');
        $root->exec('INSERT INTO `' . $baza . '`.`' . $t . '` SELECT * FROM `' . $bazaDev . '`.`' . $t . '`');
    }
    // O poză de produs adevărată (800×800), ca prima pagină și produsul să aibă o poză de distribuire /og/….
    @mkdir(dirname($radacina . '/public' . $pozaTest), 0775, true);
    $imagine = imagecreatetruecolor(800, 800);
    imagefill($imagine, 0, 0, imagecolorallocate($imagine, 40, 120, 60));
    imagepng($imagine, $radacina . '/public' . $pozaTest);
    $dbT = $conectare($baza);
    $dbT->prepare("UPDATE products SET image_url = ? WHERE slug = 'ceai-de-menta'")->execute([$pozaTest]);
    // Conținutul copiat cu linkurile lui absolute spre site-ul de origine (aici serverul Bioscem), ca descrierile și paginile
    // importate din WordPress pe bioscem.ro: în prima pagină (și spre o adresă la care duce doar linkul absolut), în
    // descrierea unui produs și un canonical pus de mână (seo_pages). Pe serverul Bioscem sunt ale lui; pe celelalte doar cu
    // --gazda-sursa.
    $gazdaVechi = '127.0.0.1:' . $portVechi;
    $dbT->prepare("UPDATE pages SET html_content = CONCAT(html_content, ?) WHERE slug = '' AND deleted_at IS NULL")->execute([
        '<p><a href="http://' . $gazdaVechi . '/produs/ceai-de-musetel">Mușețel</a> <a href="http://' . $gazdaVechi . '/doar-din-link-absolut">Doar de aici</a> '
        . '<a href="http://' . $gazdaVechi . '/wp-content/uploads/2020/01/ceai.jpg">Poza veche</a></p>']);
    $dbT->prepare("UPDATE products SET description = CONCAT(description, ?) WHERE slug = 'ceai-de-menta'")->execute([
        '<p>Vezi și <a href="http://' . $gazdaVechi . '/produs/ceai-de-tei">ceaiul de tei</a>.</p>']);
    $idMusetel = (int) $dbT->query("SELECT id FROM products WHERE slug = 'ceai-de-musetel'")->fetchColumn();
    $dbT->prepare("INSERT INTO seo_pages (page_type, page_ref, canonical_url) VALUES ('product', ?, ?)")->execute([(string) $idMusetel,
        'http://' . $gazdaVechi . '/produs/ceai-de-musetel']);
    // Poza copiată în codul Bioscem cu altă dată decât a codului comun: altă versiune /og/ (ca pozele copiate fără datele lor).
    exec('rm -rf ' . escapeshellarg($codVechi . '/public/uploads') . ' && cp -a ' . escapeshellarg($radacina . '/public/uploads') . ' '
        . escapeshellarg($codVechi . '/public/uploads') . ' && touch -d ' . escapeshellarg('2020-01-02 03:04:05') . ' ' . escapeshellarg($codVechi . '/public' . $pozaTest));
    // Licența Bioscem a codului comun, într-un dosar al testului.
    $licenta = static function (string $dosar, array $argumente) use ($radacina): int {
        @mkdir($dosar, 0770, true);
        exec('CMS_DEV_LICENTA_DOSAR=' . escapeshellarg($dosar) . ' ' . escapeshellarg($radacina . '/tools/dev/php.sh') . ' tools/dev/licenta.php '
            . implode(' ', array_map('escapeshellarg', $argumente)) . ' > /dev/null 2>&1', $iesire, $cod);
        return $cod;
    };
    $dosarLicenta = $dosarTest . '/licenta';
    $dosarLicentaCopie = $dosarTest . '/licenta-copie';
    $verifica('licența Bioscem a codului comun (tools/dev/licenta.php, în dosarul testului)', $licenta($dosarLicenta, ['bioscem']) === 0
        && $licenta($dosarLicentaCopie, ['bioscem']) === 0);
    // Copia de verificare a codului comun: fișierele din git, fără .env (mediul o face copie: poarta, gărzile), încărcată acum.
    $copieCod = $dosarTest . '/copie';
    $nCopie = 0;
    foreach (array_filter(explode("\0", (string) shell_exec('git -C ' . escapeshellarg($radacina) . ' ls-files -z --cached --others --exclude-standard'))) as $f) {
        if (!is_file($radacina . '/' . $f) || str_starts_with($f, '.env') || (str_starts_with($f, 'storage/') && !in_array(basename($f), ['.gitkeep', '.gitignore'], true))) {
            continue;
        }
        @mkdir(dirname($copieCod . '/' . $f), 0770, true);
        copy($radacina . '/' . $f, $copieCod . '/' . $f);
        $nCopie++;
    }
    @mkdir($copieCod . '/storage/verificare', 0770, true);
    file_put_contents($copieCod . '/storage/verificare/incarcat-la', date('c') . "\n");
    $parolaPoarta = 'Poarta-' . bin2hex(random_bytes(8));

    // Fiecare server notează fiecare cerere (metoda, adresa, User-Agent, dacă a venit un cookie sau o parolă), apoi face ce face
    // routerul uneltelor (tools/dev/router.php): așa se vede din partea site-ului ce cere amprenta.
    $routerJurnal = $dosarTest . '/router-jurnal.php';
    file_put_contents($routerJurnal, "<?php\nfile_put_contents((string) getenv('AMPRENTA_JURNAL_CERERI'), json_encode(['m' => \$_SERVER['REQUEST_METHOD'] ?? '', "
        . "'u' => \$_SERVER['REQUEST_URI'] ?? '', 'ua' => \$_SERVER['HTTP_USER_AGENT'] ?? '', 'cookie' => isset(\$_SERVER['HTTP_COOKIE']), "
        . "'parola' => isset(\$_SERVER['HTTP_AUTHORIZATION']) || isset(\$_SERVER['PHP_AUTH_USER'])]) . \"\\n\", FILE_APPEND | LOCK_EX);\n"
        . 'return require ' . var_export($radacina . '/tools/dev/router.php', true) . ";\n");
    /** Cererile notate de un server: listă de [m, u, ua, cookie, parola]. */
    $cereri = static function (string $jurnal): array {
        return array_values(array_filter(array_map(static fn (string $l): ?array => json_decode($l, true),
            explode("\n", trim((string) @file_get_contents($jurnal . '.cereri'))))));
    };
    $porneste = static function (string $cod, int $portServer, array $mediuInPlus, string $jurnal) use (&$servere, $sock, $routerJurnal): bool {
        $mediu = array_merge(getenv(), ['APP_URL' => 'http://127.0.0.1:' . $portServer, 'DB_DATABASE' => 'cms_amprenta_t', 'PHP_CLI_SERVER_WORKERS' => '4',
            'AMPRENTA_JURNAL_CERERI' => $jurnal . '.cereri'], $mediuInPlus);
        foreach ($mediu as $k => $v) {
            if ($v === null) {
                unset($mediu[$k]);
            }
        }
        $p = proc_open([PHP_BINARY, '-d', 'pdo_mysql.default_socket=' . $sock, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $jurnal . '.erori',
            '-d', 'allow_url_fopen=0', '-d', 'memory_limit=512M', '-S', '127.0.0.1:' . $portServer, '-t', $cod . '/public', $routerJurnal],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $jurnal, 'a'], 2 => ['file', $jurnal, 'a']], $tevi, $cod, $mediu);
        $servere[] = (int) proc_get_status($p)['pid'];
        for ($i = 0; $i < 100; $i++) {
            $c = curl_init('http://127.0.0.1:' . $portServer . '/robots.txt');
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
            curl_exec($c);
            $raspuns = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
            curl_close($c);
            if ($raspuns > 0) {
                return true;
            }
            usleep(100000);
        }
        return false;
    };
    $jurnalVechi = $dosarTest . '/server-vechi.log';
    $jurnalNou = $dosarTest . '/server-nou.log';
    $jurnalCopie = $dosarTest . '/server-copie.log';
    $pornite = $porneste($codVechi, $portVechi, ['CMS_DEV_MARCAJE_PORT' => null], $jurnalVechi)
        && $porneste($radacina, $portNou, ['SITE_DATE_DOSAR' => $radacina . '/site-uri/bioscem', 'LICENTA_DOSAR' => $dosarLicenta], $jurnalNou)
        && $porneste($copieCod, $portCopie, ['SITE_DATE_DOSAR' => $copieCod . '/site-uri/bioscem', 'LICENTA_DOSAR' => $dosarLicentaCopie, 'SITE_COPIE_VERIFICARE' => '1',
            'VERIFICARE_POARTA_UTILIZATOR' => 'verificare', 'VERIFICARE_POARTA_HASH' => password_hash($parolaPoarta, PASSWORD_DEFAULT),
            'VERIFICARE_EMAIL' => 'proprietar.amprenta@exemplu.ro', 'VERIFICARE_UPLOADS_DIN' => 'https://bioscem.ro', 'VERIFICARE_ZILE_PASTRARE' => '7'], $jurnalCopie);
    $verifica('cele trei servere pornite (copia: ' . $nCopie . ' fișiere din git)', $pornite && $nCopie > 200);
    if (!$pornite) {
        throw new RuntimeException('serverele nu pornesc; vezi ' . $dosarTest . '/server-*.log');
    }

    // ── B1 ──
    $scrie('');
    $scrie('B1. Codul comun de două ori (conținutul cu linkurile absolute ale serverului Bioscem: --gazda-sursa)');
    $tsvNou = $dosarTest . '/nou.tsv';
    $tsvNou2 = $dosarTest . '/nou-2.tsv';
    $sursaVechi = '--gazda-sursa=' . $gazdaVechi;
    $r = $amprenta(['--baza=http://127.0.0.1:' . $portNou, '--iesire=' . $tsvNou, '--pauza=0', $sursaVechi]);
    foreach (explode("\n", trim($r['iesire'])) as $rand) {
        $scrie('        | ' . $rand);
    }
    $r2 = $amprenta(['--baza=http://127.0.0.1:' . $portNou, '--iesire=' . $tsvNou2, '--pauza=0', $sursaVechi]);
    $an = FisierAmprenta::citeste($tsvNou);
    $verifica('amprenta codului comun: cod 0, „GATA”, fișierul 0600, nicio gazdă în el, nu e copie, gazda sursă a serverului Bioscem', $r['cod'] === 0
        && $r2['cod'] === 0 && str_contains($r['iesire'], 'GATA: amprenta e scrisă') && (fileperms($tsvNou) & 0777) === 0600
        && !str_contains((string) file_get_contents($tsvNou), '127.0.0.1') && $an['copie'] === false && $an['sursa'] === AmprentaSite::amprentaGazdei($gazdaVechi)
        && str_contains($r['iesire'], 'conținutul copiat de pe ' . $gazdaVechi), $r['tot']);
    $cai = array_keys($an['randuri']);
    $categoriiVechi = array_values(array_filter($cai, static fn (string $c): bool => str_starts_with($c, '/magazin?category=')));
    $verifica('ce s-a cerut: lista fixă (și fluxul, casetele avertismentelor, adresa inexistentă), harta, categoriile vechi (301 spre /categorie/…), o poză '
        . 'de distribuire /og/ (200, image/jpeg)', count(array_intersect(AmprentaSite::FIXE, $cai)) === count(AmprentaSite::FIXE)
        && isset($an['randuri']['/pagina-care-nu-exista-{ALEATOR}']) && $an['randuri']['/pagina-care-nu-exista-{ALEATOR}']['cod'] === '404'
        && count($categoriiVechi) >= 2 && $an['randuri'][$categoriiVechi[0]]['cod'] === '301'
        && str_starts_with($an['randuri'][$categoriiVechi[0]]['location'], '{GAZDA}/categorie/')
        && count(array_filter($an['randuri'], static fn (array $x): bool => str_starts_with($x['cale'], '/og/') && $x['cod'] === '200'
            && $x['tip'] === 'image/jpeg')) >= 1
        && count(array_filter($cai, static fn (string $c): bool => str_starts_with($c, '/produs/'))) >= 30, implode(' ', $cai));
    $r = $compara($tsvNou, $tsvNou2, true);
    $verifica('codul comun de două ori: --strict „LA FEL” (tokenurile, momentele, versiunile scoase)', $r['cod'] === 0 && str_contains($r['iesire'], 'REZULTAT: LA FEL'),
        $r['iesire']);

    // ── B2 ──
    $scrie('');
    $scrie('B2. Codul Bioscem față de codul comun, pe aceeași bază');
    $tsvVechi = $dosarTest . '/vechi.tsv';
    file_put_contents($jurnalVechi . '.cereri', '');
    $inceput = microtime(true);
    $r = $amprenta(['--baza=http://127.0.0.1:' . $portVechi, '--iesire=' . $tsvVechi, '--pauza=0.02']);
    $durata = microtime(true) - $inceput;
    // Cererile amprentei, văzute de serverul Bioscem (înaintea celor de mai jos ale testului, cu alt User-Agent).
    $notate = $cereri($jurnalVechi);
    $av = FisierAmprenta::citeste($tsvVechi);
    $verifica('amprenta codului Bioscem: cod 0, aceleași adrese ca a codului comun', $r['cod'] === 0 && array_keys($av['randuri']) == array_keys($an['randuri']),
        $r['tot']);
    // Linkurile absolute ale conținutului: interne pe ambele (cerute și numărate), canonical-ul pus de mână cu {GAZDA}.
    $dinLink = static fn (array $amp): bool => ($amp['randuri']['/doar-din-link-absolut']['de_unde'] ?? '') === 'link'
        && ($amp['randuri']['/wp-content/uploads/2020/01/ceai.jpg']['de_unde'] ?? '') === 'link'
        && ($amp['randuri']['/produs/ceai-de-musetel']['canonical'] ?? '') === '{GAZDA}/produs/ceai-de-musetel';
    $verifica('linkurile absolute ale conținutului: adresele la care duc doar ele cerute pe ambele, canonical-ul pus de mână {GAZDA}/… pe ambele',
        $dinLink($av) && $dinLink($an), json_encode([$av['randuri']['/produs/ceai-de-musetel'] ?? null, $an['randuri']['/produs/ceai-de-musetel'] ?? null]) ?: '');
    // Poza produsului are în codul Bioscem altă dată a fișierului, deci altă versiune /og/; rândurile ei sunt totuși aceleași.
    $cerePagina = static function (int $portServer, string $cale): array {
        $c = curl_init('http://127.0.0.1:' . $portServer . $cale);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_USERAGENT => 'test-amprenta']);
        $corp = (string) curl_exec($c);
        $tip = (string) curl_getinfo($c, CURLINFO_CONTENT_TYPE);
        curl_close($c);
        return ['corp' => $corp, 'tip' => strtolower(str_replace(' ', '', $tip))];
    };
    $versiuneOg = static fn (string $corp): string => preg_match('#property="og:image" content="[^"]*/og/([0-9a-f]{12})/#', $corp, $m) === 1 ? $m[1] : '';
    $vOgVechi = $versiuneOg($cerePagina($portVechi, '/produs/ceai-de-menta')['corp']);
    $vOgNou = $versiuneOg($cerePagina($portNou, '/produs/ceai-de-menta')['corp']);
    $pozeAv = array_values(array_filter(array_keys($av['randuri']), static fn (string $c): bool => str_starts_with($c, '/og/')));
    $verifica('poza de distribuire: altă versiune în codul Bioscem (' . $vOgVechi . ') decât în codul comun (' . $vOgNou . '), aceleași rânduri /og/{V}/… în ambele amprente',
        $vOgVechi !== '' && $vOgNou !== '' && $vOgVechi !== $vOgNou && $pozeAv !== [] && str_starts_with($pozeAv[0], '/og/{V}/')
        && $pozeAv === array_values(array_filter(array_keys($an['randuri']), static fn (string $c): bool => str_starts_with($c, '/og/'))), implode(' ', $pozeAv));
    $r = $compara($tsvVechi, $tsvNou);
    foreach (explode("\n", trim($r['iesire'])) as $rand) {
        $scrie('        | ' . $rand);
    }
    $verifica('Bioscem față de codul comun: câmpurile structurale „LA FEL”', $r['cod'] === 0 && str_contains($r['iesire'], 'diferențe pe câmp: —'), $r['iesire']);
    $rs = $compara($tsvVechi, $tsvNou, true);
    $verifica('cu --strict: „DIFERĂ” doar la corpul lui /robots.txt (regulile declarate în $deBioscem din compara-cu-baza.php)', $rs['cod'] === 1
        && str_contains($rs['iesire'], "diferențe pe câmp: corp 1\n  corp: /robots.txt\n"), $rs['iesire']);
    // Fără gazda sursă: comparația refuză (alte gazde sursă), iar paginile codului comun citite fără ea chiar diferă de cele Bioscem.
    $tsvFaraSursa = $dosarTest . '/nou-fara-sursa.tsv';
    $r = $amprenta(['--baza=http://127.0.0.1:' . $portNou, '--iesire=' . $tsvFaraSursa, '--pauza=0']);
    $rf = $compara($tsvVechi, $tsvFaraSursa);
    $verifica('codul comun amprentat fără --gazda-sursa: amprenta se face, comparația cu Bioscem refuză (alte gazde sursă, cod 2)', $r['cod'] === 0 && $rf['cod'] === 2
        && str_contains($rf['iesire'], 'amprentele au alte gazde sursă'), $r['tot'] . $rf['iesire']);
    $diferaFara = [];
    $laFelCu = [];
    foreach (['/' => 'linkuri', '/produs/ceai-de-menta' => 'linkuri', '/produs/ceai-de-musetel' => 'canonical'] as $cale => $camp) {
        $p = $cerePagina($portNou, $cale);
        $fara = AmprentaSite::campuri($p['corp'], $p['tip'], ['127.0.0.1:' . $portNou]);
        $cu = AmprentaSite::campuri($p['corp'], $p['tip'], ['127.0.0.1:' . $portNou, $gazdaVechi]);
        $diferaFara[$cale] = $fara[$camp] !== ($av['randuri'][$cale][$camp] ?? null);
        $laFelCu[$cale] = $cu[$camp] === ($av['randuri'][$cale][$camp] ?? null) && $cu['linkuri'] === ($av['randuri'][$cale]['linkuri'] ?? null);
    }
    $faraPrima = AmprentaSite::campuri($cerePagina($portNou, '/')['corp'], 'text/html', ['127.0.0.1:' . $portNou]);
    $verifica('fără gazda sursă, paginile codului comun diferă de cele Bioscem (linkurile primei pagini și ale produsului, canonical-ul pus de mână; '
        . '/doar-din-link-absolut nici nu s-ar cere); cu ea sunt la fel', !in_array(false, $diferaFara, true) && !in_array(false, $laFelCu, true)
        && !in_array('/doar-din-link-absolut', $faraPrima['linkuri_cai'], true), json_encode([$diferaFara, $laFelCu]) ?: '');
    // Politețea, din cererile notate de serverul Bioscem: doar GET, User-Agent-ul uneltei, fără cookie și fără parolă, nicio
    // adresă interzisă, câte una pe adresă, cu pauza dintre ele.
    $metode = array_values(array_unique(array_column($notate, 'm')));
    $agenti = array_values(array_unique(array_column($notate, 'ua')));
    $cerute = array_column($notate, 'u');
    $interziseCerute = array_values(array_filter($cerute, static fn (string $c): bool => !AmprentaSite::permisa($c)));
    $verifica('politețea pe server: ' . count($cerute) . ' cereri, toate GET, User-Agent ' . AmprentaSite::USER_AGENT . ', fără cookie și fără parolă, nicio '
        . 'adresă interzisă, câte una pe adresă, pauza ținută', $metode === ['GET'] && $agenti === [AmprentaSite::USER_AGENT]
        && !in_array(true, array_column($notate, 'cookie'), true) && !in_array(true, array_column($notate, 'parola'), true) && $interziseCerute === []
        && count($cerute) === count($av['randuri']) && count(array_unique($cerute)) === count($cerute) && $durata >= 0.02 * (count($cerute) - 1),
        implode(' ', $metode) . ' | ' . implode(' ', $agenti) . ' | ' . implode(' ', $interziseCerute) . ' | ' . count($cerute) . '/' . count($av['randuri']));

    // ── B3 ──
    $scrie('');
    $scrie('B3. Licența fără blog pe codul comun');
    $tsvFaraBlog = $dosarTest . '/fara-blog.tsv';
    $licenta($dosarLicenta, ['bioscem', 'blog=oprit']);
    $r = $amprenta(['--baza=http://127.0.0.1:' . $portNou, '--iesire=' . $tsvFaraBlog, '--pauza=0', $sursaVechi]);
    $licenta($dosarLicenta, ['bioscem']);
    $rc = $compara($tsvVechi, $tsvFaraBlog);
    $verifica('fără blog: „DIFERĂ”, /blog 200 → 404, articolele doar în amprenta Bioscem', $r['cod'] === 0 && $rc['cod'] === 1
        && str_contains($rc['iesire'], '  cod: /blog (200 → 404)') && preg_match('/doar în A: [1-9]/', $rc['iesire']) === 1
        && str_contains($rc['iesire'], '  doar în A: /blog/'), $rc['iesire']);

    // ── B4 ──
    $scrie('');
    $scrie('B4. Copia de verificare (poarta, gărzile)');
    $tsvCopie = $dosarTest . '/copie.tsv';
    file_put_contents($jurnalCopie . '.cereri', '');
    $r = $amprenta(['--baza=http://127.0.0.1:' . $portCopie, '--iesire=' . $tsvCopie, '--pauza=0']);
    $cereriFara = count($cereri($jurnalCopie));
    $verifica('fără --poarta: cod 1, „OPRIT: site-ul cere parola porții”, o singură cerere, nicio amprentă', $r['cod'] === 1
        && str_contains($r['iesire'], 'OPRIT: site-ul cere parola porții') && $cereriFara === 1 && !is_file($tsvCopie), $r['tot'] . ' (' . $cereriFara . ' cereri)');
    file_put_contents($jurnalCopie . '.cereri', '');
    $r = $amprenta(['--baza=http://127.0.0.1:' . $portCopie, '--iesire=' . $tsvCopie, '--pauza=0', '--poarta=verificare'], ['VERIFICARE_POARTA_PAROLA' => 'gresita']);
    $cereriGresita = count($cereri($jurnalCopie));
    file_put_contents($jurnalCopie . '.cereri', '');
    $verifica('parola greșită: cod 1, „parola porții nu e bună”, o singură încercare (nu zece), nicio amprentă', $r['cod'] === 1
        && str_contains($r['iesire'], 'parola porții nu e bună') && $cereriGresita === 1 && !is_file($tsvCopie), $r['tot'] . ' (' . $cereriGresita . ' cereri)');
    $r = $amprenta(['--baza=http://127.0.0.1:' . $portCopie, '--iesire=' . $tsvCopie, '--pauza=0', '--poarta=verificare'], ['VERIFICARE_POARTA_PAROLA' => $parolaPoarta]);
    $cereriFaraSursa = count($cereri($jurnalCopie));
    file_put_contents($jurnalCopie . '.cereri', '');
    $verifica('cu parola, fără --gazda-sursa: cod 1, „OPRIT: e o copie de verificare … --gazda-sursa=bioscem.ro”, o singură cerere, nicio amprentă',
        $r['cod'] === 1 && str_contains($r['iesire'], 'OPRIT: e o copie de verificare') && str_contains($r['iesire'], '--gazda-sursa=bioscem.ro') && $cereriFaraSursa === 1
        && !is_file($tsvCopie), $r['tot'] . ' (' . $cereriFaraSursa . ' cereri)');
    $r = $amprenta(['--baza=http://127.0.0.1:' . $portCopie, '--iesire=' . $tsvCopie, '--pauza=0', '--poarta=verificare', $sursaVechi],
        ['VERIFICARE_POARTA_PAROLA' => $parolaPoarta]);
    $ac = is_file($tsvCopie) ? FisierAmprenta::citeste($tsvCopie) : ['copie' => false, 'randuri' => []];
    $xRobots = array_values(array_unique(array_column($ac['randuri'], 'x_robots')));
    $cuParola = array_values(array_unique(array_column($cereri($jurnalCopie), 'parola')));
    $verifica('cu parola: „copie de verificare: DA”, X-Robots-Tag al porții pe fiecare adresă, bannerul nu intră în amprentă, parola nicăieri', $r['cod'] === 0
        && $ac['copie'] === true && $xRobots === ['noindex, nofollow, noarchive'] && $cuParola === [true] && !str_contains((string) @file_get_contents($tsvCopie), 'SITE DE VERIFICARE')
        && !str_contains($r['tot'], $parolaPoarta) && !str_contains((string) @file_get_contents($tsvCopie), $parolaPoarta), $r['tot']);
    $r = $compara($tsvVechi, $tsvCopie);
    foreach (explode("\n", trim($r['iesire'])) as $rand) {
        $scrie('        | ' . $rand);
    }
    $verifica('Bioscem față de copia de verificare a codului comun: „LA FEL”, cu X-Robots-Tag, poza de distribuire și /og/ spuse ca necomparate',
        $r['cod'] === 0 && str_contains($r['iesire'], 'necomparate (doar B e copia de verificare)') && preg_match('#adresele /og/ și /uploads/: [1-9]\d* \(garda 13\)#', $r['iesire']) === 1
        && str_contains($r['iesire'], 'diferențe pe câmp: —'), $r['iesire']);
    $verifica('pe copie, linkurile absolute ale conținutului copiat sunt ale copiei (--gazda-sursa): aceleași linkuri și canonical ca pe Bioscem, adresele lor cerute',
        $dinLink($ac) && ($ac['randuri']['/']['linkuri'] ?? '') === ($av['randuri']['/']['linkuri'] ?? '-')
        && ($ac['randuri']['/produs/ceai-de-menta']['linkuri'] ?? '') === ($av['randuri']['/produs/ceai-de-menta']['linkuri'] ?? '-'),
        json_encode([$ac['randuri']['/'] ?? null, $av['randuri']['/'] ?? null]) ?: '');
    $rs = $compara($tsvVechi, $tsvCopie, true);
    $verifica('--strict între Bioscem și copie: refuzat (cod 2)', $rs['cod'] === 2, $rs['iesire']);
    $pozeCopie = array_filter($ac['randuri'], static fn (array $x): bool => str_starts_with($x['cale'], '/og/'));
    $verifica('pe copie, poza de distribuire lipsă trimite 302 spre site-ul sursă (garda 13), fără să fie urmată', $pozeCopie === []
        || array_values(array_unique(array_column($pozeCopie, 'cod'))) === ['302'], json_encode(array_values($pozeCopie)) ?: '');
} catch (Throwable $e) {
    $verifica('testul a rulat până la capăt', false, get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

$scrie('');
$scrie('Rezultat: ' . $trecute . ' trecute, ' . $esuate . ' eșuate');
exit($esuate === 0 ? 0 : 1);
