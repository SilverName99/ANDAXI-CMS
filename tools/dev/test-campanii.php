<?php

declare(strict_types=1);

/**
 * Testele pluginului `campanii` (cerut pentru Biovitality pe 5 nov 2026; docs/PLUGINURI.md, „Campanii pe site”), cu serverul de
 * dezvoltare, pe baza de test cu datele demo și o campanie inventată:
 *
 *   tools/dev/php.sh tools/dev/test-campanii.php                 # A–E
 *   tools/dev/php.sh tools/dev/test-campanii.php --fara-browser  # fără partea D (Chromium)
 *
 * A. Fără server: adresa de întoarcere (doar o cale a site-ului: nicio redirecționare deschisă), roboții, adresele care nu se
 *    opresc niciodată (coș, checkout, cont, plăți, API, platforma, fișiere, admin), regula cererii (doar GET de document, nu din
 *    interiorul site-ului, nu cu cookie-ul), culorile, linkul bannerului, starea unei campanii, cheia cache-ului fără campanie.
 * B. Adminul (administratorul general): lista goală, o campanie nouă salvată din formular (cu jetonul formularului), refuzurile
 *    (fără jeton, sfârșit înainte de început, suprapunerea cu altă campanie pornită, pornită fără pagină), jurnalul adminilor.
 * C. Pe site, cu campania activă: prima intrare (302 la /campanie/<slug>?inapoi=…, cookie de sesiune HttpOnly, fără cache),
 *    pagina campaniei (butonul spre adresa cerută, noindex, fără cache), nicio trimitere pentru roboți, POST, coș, checkout,
 *    cont, API, /_andaxi/, fișiere, admin, navigarea din site, cu cookie-ul; trimiterea și fără Referer și de pe alt site
 *    (decizia proprietarului din 5 nov: la fiecare intrare nouă); insigna doar pe produsele alese (pagina produsului,
 *    magazinul, categoria, {{featured_products}}, produsele similare, {{product_produse_similare}}), bannerul (lipit sau nu,
 *    cu linkul lui; fără link, spre pagina campaniei cu întoarcerea la pagina curentă, și clicul pe el); în afara ferestrei și oprită: nimic (pagina 404, fără banner, fără insignă), afară de
 *    previzualizarea administratorului; cu pluginul oprit: garda (404 pe site, 403 în admin), nicio trimitere, nicio insignă;
 *    fără nicio campanie, paginile fără niciun semn al pluginului.
 * C1. Pagina campaniei fără antetul și subsolul site-ului (campanii/0056): implicit bifată în editor; fără bifă, ca înainte; cu
 *    bifa, fără antet, subsol, sertarul „Oferte” și butonul coșului plutitor, cu bara „Înapoi” (închisă), bannerul de cookie-uri și
 *    GTM-ul; codul {{campanie_produse}} (cardurile, prețul redus, „Text sub preț”, „Adaugă în coș” = formularul cardului din
 *    magazin, epuizat, ascuns, cardurile închise), gol la adresa proprie a paginii și fără produse.
 * D. În Chromium (tools/dev/campanii-browser.cjs), desktop 1366 px și telefon 390 px: vizitatorul nou, butonul în stânga sus,
 *    întoarcerea, insigna, bannerul lipit, fără derulare laterală; editorul cu previzualizarea bannerului și a insignei.
 * E. Curățenia: toată baza (fiecare tabel) e pusă la loc, verificat cu CHECKSUM TABLE; la fel licența „toate”, cache-ul
 *    paginilor și sesiunile; jurnalul PHP fără avertismente noi.
 *
 * Raportul: $CMS_DEV_DIR/test-campanii-raport.txt; capturile: $CMS_DEV_DIR/campanii/. Cod de ieșire 0 doar dacă totul a trecut.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';
require_once __DIR__ . '/lib/salvari-admin.php';

use App\Support\Campanii;
use App\Support\Database;
use App\Support\Paths;
use App\Support\Plugins;
use App\Support\ResponseCache;

$faraBrowser = in_array('--fara-browser', $argv, true);
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-campanii-raport.txt';
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
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . mb_substr($detalii, 0, 1500) : ''));
    }
};
$scrie('Test pluginul campanii ANDAXI-CMS — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

// ── A. Fără server ───────────────────────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== A. Regulile, fără server');
$cazuriInapoi = [
    '/' => '/', '/produs/ceai-de-menta?utm_source=x&gclid=1' => '/produs/ceai-de-menta?utm_source=x&gclid=1',
    '/blog/articol#sus' => '/blog/articol#sus', '/categorie/ceaiuri' => '/categorie/ceaiuri',
    'https://altsite.exemplu/' => '/', '//altsite.exemplu/x' => '/', '/\\altsite.exemplu' => '/', '\\\\altsite.exemplu' => '/',
    '/%2Faltsite.exemplu' => '/', '/%5Caltsite.exemplu' => '/', 'javascript:alert(1)' => '/', "/x\r\nLocation: https://rau.exemplu" => '/',
    ' https://altsite.exemplu' => '/', '' => '/', 'produs/x' => '/', '/campanie/alta?inapoi=/' => '/', '/%0d%0aSet-Cookie:x' => '/',
    '/' . str_repeat('a', 2100) => '/',
];
$gresiteInapoi = [];
foreach ($cazuriInapoi as $intrare => $asteptat) {
    if (Campanii::inapoiSigur((string) $intrare) !== $asteptat) {
        $gresiteInapoi[] = json_encode((string) $intrare) . ' → ' . json_encode(Campanii::inapoiSigur((string) $intrare));
    }
}
$verifica('adresa de întoarcere: doar o cale a site-ului (' . count($cazuriInapoi) . ' cazuri, inclusiv //, \\, %2F, scheme, CRLF)',
    $gresiteInapoi === [], implode('; ', $gresiteInapoi));

$roboti = ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'Mozilla/5.0 (compatible; bingbot/2.0)',
    'facebookexternalhit/1.1', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.0)', 'WhatsApp/2.23',
    'curl/8.5.0', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/124.0 Safari/537.36',
    'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Mobile Safari/537.36 (compatible; Googlebot/2.1)',
    'Chrome-Lighthouse', 'AdsBot-Google (+http://www.google.com/adsbot.html)', ''];
$oameni = ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0'];
$verifica('roboții (Google, Bing, rețelele sociale, unelte, HeadlessChrome, fără User-Agent) nu sunt trimiși la campanie',
    array_filter($roboti, static fn (string $ua): bool => !Campanii::eRobot($ua)) === [],
    implode(' | ', array_filter($roboti, static fn (string $ua): bool => !Campanii::eRobot($ua))));
$verifica('browserele oamenilor (Chrome, Safari pe iPhone, Firefox) da', array_filter($oameni, static fn (string $ua): bool => Campanii::eRobot($ua)) === []);

$neinterceptate = ['/admin', '/admin/orders', '/api/cart/count', '/api/shop/catalog', '/_andaxi/ping', '/cos', '/cos/adauga/3', '/checkout',
    '/checkout/succes/203801', '/contul-meu', '/contul-meu/comenzi', '/login', '/register', '/plata/abc', '/webhook/stripe', '/checkout/bt/retur',
    '/newsletter/unsubscribe/x', '/cont/confirmare/x', '/campanie/alta', '/uploads/demo/x.png', '/assets/css/app.css', '/tema/css/a.css',
    '/robots.txt', '/sitemap.xml', '/sitemap-produse.xml', '/feed/google-merchant.xml', '/og/1/uploads/x.png', '/health', '/favicon.ico',
    '/review-form/x', '/gdpr-agreements/submit', '/acorduri-gdpr', '/contact/send', '/fisier.pdf'];
$verifica('nu se opresc niciodată: admin, API, platforma, coș, checkout, cont, plăți și confirmările lor, fișiere, harta site-ului',
    array_filter($neinterceptate, static fn (string $c): bool => Campanii::caleDeInterceptat($c)) === [],
    implode(', ', array_filter($neinterceptate, static fn (string $c): bool => Campanii::caleDeInterceptat($c))));
$interceptate = ['/', '/magazin', '/produs/ceai-de-menta', '/categorie/ceaiuri', '/blog', '/blog/un-articol', '/despre-noi', '/contact'];
$verifica('se opresc: prima pagină, magazinul, produsele, categoriile, blogul, paginile de conținut',
    array_filter($interceptate, static fn (string $c): bool => !Campanii::caleDeInterceptat($c)) === [],
    implode(', ', array_filter($interceptate, static fn (string $c): bool => !Campanii::caleDeInterceptat($c))));
$verifica('linkul de ciornă al unui produs și o adresă cu „inapoi” nu se opresc', !Campanii::caleDeInterceptat('/produs/x', 'draft=abc')
    && !Campanii::caleDeInterceptat('/', 'inapoi=/'));

$om = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/produs/ceai-de-menta?utm_source=x', 'HTTP_HOST' => '127.0.0.1:8701',
    'HTTP_USER_AGENT' => $oameni[0], 'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,*/*;q=0.8', 'HTTP_SEC_FETCH_DEST' => 'document',
    'HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_REFERER' => 'https://www.google.com/'];
$campanieProba = ['id' => 7, 'slug' => 'proba', 'pagina_id' => 3, 'pagina_la_intrare' => 1];
$cazuriCerere = [
    'un om venit de pe Google' => [$om, [], [], true],
    'fără Referer (link tastat, aplicație, email)' => [array_diff_key($om, ['HTTP_REFERER' => 1]), [], [], true],
    'cu cookie-ul acestei campanii' => [$om, [Campanii::COOKIE => '7'], [], false],
    'cu cookie-ul altei campanii (o campanie nouă se vede din nou)' => [$om, [Campanii::COOKIE => '6'], [], true],
    'navigarea din interiorul site-ului' => [['HTTP_REFERER' => 'http://127.0.0.1:8701/magazin'] + $om, [], [], false],
    'POST' => [['REQUEST_METHOD' => 'POST'] + $om, [], [], false],
    'HEAD' => [['REQUEST_METHOD' => 'HEAD'] + $om, [], [], false],
    'Accept fără text/html (fetch, API)' => [['HTTP_ACCEPT' => 'application/json'] + $om, [], [], false],
    'Sec-Fetch-Dest: iframe' => [['HTTP_SEC_FETCH_DEST' => 'iframe'] + $om, [], [], false],
    'Sec-Fetch-Mode: cors' => [['HTTP_SEC_FETCH_MODE' => 'cors'] + $om, [], [], false],
    'preîncărcare (Sec-Purpose: prefetch)' => [['HTTP_SEC_PURPOSE' => 'prefetch'] + $om, [], [], false],
    'AJAX (X-Requested-With)' => [['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'] + $om, [], [], false],
    'Googlebot' => [['HTTP_USER_AGENT' => $roboti[0]] + $om, [], [], false],
    'administrator logat' => [$om, [], ['admin_id' => 1], false],
    'coșul' => [['REQUEST_URI' => '/cos'] + $om, [], [], false],
];
$gresiteCerere = [];
foreach ($cazuriCerere as $nume => [$server, $cookie, $sesiune, $asteptat]) {
    if (Campanii::deInterceptat($server, $cookie, $sesiune, $campanieProba) !== $asteptat) {
        $gresiteCerere[] = $nume;
    }
}
$verifica('regula cererii (' . count($cazuriCerere) . ' cazuri: om, cookie, din site, POST, HEAD, fetch, iframe, preîncărcare, AJAX, robot, admin, coș)',
    $gresiteCerere === [], implode('; ', $gresiteCerere));
$verifica('o campanie fără pagină sau cu pagina la intrare debifată nu trimite pe nimeni',
    !Campanii::deInterceptat($om, [], [], ['pagina_id' => null] + $campanieProba)
    && !Campanii::deInterceptat($om, [], [], ['pagina_la_intrare' => 0] + $campanieProba));
$verifica('culorile: doar #rrggbb (și #rgb scris lung), altfel implicitul', Campanii::culoare('#ABCDEF', '#000000') === '#abcdef'
    && Campanii::culoare('#abc', '#000000') === '#aabbcc' && Campanii::culoare('red;background:url(x)', '#123456') === '#123456'
    && Campanii::culoare('', '#123456') === '#123456');
$verifica('linkul bannerului: o cale a site-ului sau http(s), nimic altceva', Campanii::linkBanner('/magazin') === '/magazin'
    && Campanii::linkBanner('https://exemplu.ro/x') === 'https://exemplu.ro/x' && Campanii::linkBanner('javascript:alert(1)') === ''
    && Campanii::linkBanner('//rau.exemplu') === '' && Campanii::linkBanner('data:text/html,x') === '' && Campanii::linkBanner('/a b') === '');
$verifica('mărimea textului: între ' . Campanii::MARIME_MIN . ' și ' . Campanii::MARIME_MAX . ' px', Campanii::marime('8') === Campanii::MARIME_MIN
    && Campanii::marime('99') === Campanii::MARIME_MAX && Campanii::marime('18') === 18 && Campanii::marime('x') === 15);
$acum = time();
$fereastra = ['pornita' => 1, 'incepe_la' => date('Y-m-d H:i:s', $acum - 3600), 'se_termina_la' => date('Y-m-d H:i:s', $acum + 3600)];
$verifica('starea: activă în fereastră, programată înainte, încheiată după, oprită nebifată sau fără date',
    Campanii::stare($fereastra, $acum) === 'activa' && Campanii::stare($fereastra, $acum - 7200) === 'programata'
    && Campanii::stare($fereastra, $acum + 7200) === 'incheiata' && Campanii::stare(['pornita' => 0] + $fereastra, $acum) === 'oprita'
    && Campanii::stare(['incepe_la' => null] + $fereastra, $acum) === 'oprita');
$verifica('fără campanie activă, cheia cache-ului paginilor e exact cea de dinainte (partea campaniei goală)', Campanii::cheieCache() === '');

// ── Serverul și baza ─────────────────────────────────────────────────────────────────────────────────────────────────
$port = (int) (getenv('CMS_DEV_PORT') ?: '8701');
$baza = 'http://127.0.0.1:' . $port;
$browser = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
$cerere = static function (string $metoda, string $cale, ?string $jar = null, array $date = [], array $antete = []) use ($baza, $browser): array {
    $primite = [];
    $c = curl_init($baza . $cale);
    // Antetele unui browser obișnuit, afară de cele date de caz (un User-Agent sau un Accept dat le înlocuiește).
    $date0 = ['user-agent' => 'User-Agent: ' . $browser, 'accept' => 'Accept: text/html,application/xhtml+xml,*/*;q=0.8'];
    foreach ($antete as $antet) {
        unset($date0[strtolower(trim(explode(':', $antet, 2)[0]))]);
    }
    $antete = array_merge(array_values($date0), $antete);
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOPROXY => '*',
        CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $antete,
        CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$primite): int {
            if (str_contains($linie, ':')) {
                [$nume, $valoare] = explode(':', $linie, 2);
                $nume = strtolower(trim($nume));
                $primite[$nume] = isset($primite[$nume]) ? $primite[$nume] . "\n" . trim($valoare) : trim($valoare);
            }
            return strlen($linie);
        },
    ]);
    if ($metoda === 'POST') {
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($date));
    }
    if ($jar !== null) {
        curl_setopt($c, CURLOPT_COOKIEFILE, $jar);
        curl_setopt($c, CURLOPT_COOKIEJAR, $jar);
    }
    $corp = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $cod, 'antete' => $primite, 'corp' => $corp];
};
$amPornitServerul = false;
if ($cerere('GET', '/health')['cod'] !== 200) {
    exec(escapeshellarg($radacina . '/tools/dev/server.sh') . ' 2>&1', $iesire, $codServer);
    if ($codServer !== 0) {
        $scrie('EROARE: serverul nu pornește: ' . implode(' ', $iesire));
        exit(1);
    }
    $amPornitServerul = true;
}
$app = require $radacina . '/config/app.php';
$db = Database::connection((array) $app['db']);
if (!$db instanceof PDO || (int) $db->query('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND is_active = 1')->fetchColumn() < 10) {
    $scrie('EROARE: baza de test nu are datele demo; rulează întâi tools/dev/instaleaza.sh');
    exit(1);
}
if (!Campanii::disponibil($db)) {
    $scrie('EROARE: baza nu are tabelele migrării campanii/0054 (tools/dev/php.sh scripts/migreaza.php --aplica)');
    exit(1);
}
$unu = static function (string $sql, array $param = []) use ($db): string {
    $stmt = $db->prepare($sql);
    $stmt->execute($param);
    $valoare = $stmt->fetchColumn();
    return $valoare === false || $valoare === null ? '' : (string) $valoare;
};

// Instantaneul întregii baze: fiecare tabel, pus la loc la final și verificat cu CHECKSUM TABLE.
$tabeleBaza = [];
foreach ($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
    ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) as $tabel) {
    $chei = $db->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND CONSTRAINT_NAME = 'PRIMARY'");
    $chei->execute(['t' => $tabel]);
    $coloaneCheie = $chei->fetchAll(PDO::FETCH_COLUMN);
    $tabeleBaza[] = ['tabel' => (string) $tabel, 'unde' => '1 = 1', 'cheie' => count($coloaneCheie) === 1 ? (string) $coloaneCheie[0] : null];
}
$sumeBaza = static function () use ($db, $tabeleBaza): array {
    $sume = [];
    $lista = implode(', ', array_map(static fn (array $t): string => '`' . $t['tabel'] . '`', $tabeleBaza));
    foreach ($db->query('CHECKSUM TABLE ' . $lista)->fetchAll(PDO::FETCH_NUM) as $rand) {
        $sume[(string) $rand[0]] = (string) $rand[1];
    }
    return $sume;
};
$instantaneu = salvari_instantaneu($db, $tabeleBaza);
$sumeInainte = $sumeBaza();
$sesiuniInainte = glob(Paths::stocare('sessions') . '/sess_*') ?: [];
$fisierErori = $dosarDev . '/php-erori.log';
$pozitieErori = is_file($fisierErori) ? (int) filesize($fisierErori) : 0;
$parolaAdmin = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));
$adminEmail = (string) (getenv('CMS_DEV_ADMIN_EMAIL') ?: 'admin@exemplu.ro');
$jarAdmin = $dosarDev . '/test-campanii-admin-' . getmypid() . '.txt';
$licentaSchimbata = false;

$puneLaLoc = static function () use (&$licentaSchimbata, $db, $tabeleBaza, $instantaneu, $radacina, $jarAdmin, $sesiuniInainte, $amPornitServerul): void {
    if ($licentaSchimbata) {
        exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/tools/dev/licenta.php') . ' toate >/dev/null 2>&1');
        $licentaSchimbata = false;
    }
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    salvari_pune_la_loc($db, $tabeleBaza, $instantaneu);
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    ResponseCache::purgePageCache();
    foreach (array_diff(glob(Paths::stocare('sessions') . '/sess_*') ?: [], $sesiuniInainte) as $sesiune) {
        @unlink($sesiune);
    }
    @unlink($jarAdmin);
    if ($amPornitServerul) {
        exec(escapeshellarg($radacina . '/tools/dev/server.sh') . ' --opreste >/dev/null 2>&1');
    }
};
register_shutdown_function(static function () use ($puneLaLoc): void {
    $puneLaLoc();
});
$licenta = static function (string ...$argumente) use ($radacina, &$licentaSchimbata): bool {
    exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/tools/dev/licenta.php') . ' '
        . implode(' ', array_map('escapeshellarg', $argumente)) . ' 2>&1', $iesire, $cod);
    $licentaSchimbata = $argumente !== ['toate'];
    ResponseCache::purgePageCache();
    return $cod === 0;
};
$flash = static function (string $html): string {
    return preg_match('#<div class="adm-mesaj [^"]*" id="ax-mesaj-(?:succes|eroare)"[^>]*>(.*?)</div>\s*</div>#s', $html, $m) === 1
        ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')) : '';
};
$jeton = static fn (string $html): string => preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';

if (!$licenta('toate')) {
    $scrie('EROARE: licența „toate” nu s-a pus');
    exit(1);
}
$licentaSchimbata = false;

// Datele inventate ale campaniei: o pagină-ciornă și cinci produse active, din cele demo.
$db->prepare("INSERT INTO pages (title, slug, html_content, is_published) VALUES (:t, :s, :h, 0)")->execute([
    't' => 'Săptămâna demo', 's' => 'test-campanie-saptamana-demo',
    'h' => '<section style="padding:32px 16px;text-align:center"><h1>Săptămâna demo</h1><p>Reduceri inventate la produsele demo.</p></section>',
]);
$idPagina = (int) $db->lastInsertId();
// Primul produs activ, primul lui produs similar (banda de pe pagina lui), încă două și cel mai nou (grila {{featured_products}} de
// pe prima pagină arată cele mai noi produse).
// Doar produse fără reducere proprie (prețul redus, perioadele, BBD): pe celelalte campania le refuză (Campanii::motivReducere).
$faraReducere = ' AND (sale_price IS NULL OR sale_price <= 0 OR sale_price >= price) AND COALESCE(bbd_enabled, 0) = 0'
    . " AND (sale_price_periods_json IS NULL OR sale_price_periods_json IN ('', '[]'))";
$primele = array_map('intval', $db->query('SELECT id FROM products WHERE deleted_at IS NULL AND is_active = 1 AND slug <> ""' . $faraReducere . '
    ORDER BY id LIMIT 3')->fetchAll(PDO::FETCH_COLUMN));
$paginaPrimului = $cerere('GET', '/produs/' . rawurlencode($unu('SELECT slug FROM products WHERE id = :id', ['id' => $primele[0]])),
    null, [], ['User-Agent: Googlebot/2.1'])['corp'];
$similareDate = preg_match('#<script type="application/json" class="similar-products__data">(.*?)</script>#s', $paginaPrimului, $m) === 1
    ? (array) json_decode($m[1], true) : [];
$similarFaraReducere = (int) $unu('SELECT id FROM products WHERE id = :id' . $faraReducere, ['id' => (int) ($similareDate[0]['id'] ?? 0)]);
$produseCampanie = array_values(array_unique(array_filter([$primele[0], $similarFaraReducere, $primele[1], $primele[2],
    (int) $db->query('SELECT MAX(id) FROM products WHERE deleted_at IS NULL AND is_active = 1 AND slug <> ""' . $faraReducere)->fetchColumn()])));
// Cel mai nou produs fără reducere: în grila {{featured_products}} (cele mai noi 4) dacă printre ultimele 4 e unul fără reducere.
$verifica('datele testului: produsele campaniei fără reducere proprie', array_filter($produseCampanie, static fn (int $id): bool
    => $unu('SELECT COUNT(*) FROM products WHERE id = :id' . $faraReducere, ['id' => $id]) !== '1') === [], implode(',', $produseCampanie));
$idCuReducere = (int) $unu('SELECT id FROM products WHERE deleted_at IS NULL AND is_active = 1 AND sale_price > 0 AND sale_price < price ORDER BY id LIMIT 1');
$slugInCampanie = $unu('SELECT slug FROM products WHERE id = :id', ['id' => $produseCampanie[0]]);
$slugInafara = $unu('SELECT slug FROM products WHERE deleted_at IS NULL AND is_active = 1 AND slug <> "" AND id NOT IN ('
    . implode(',', $produseCampanie) . ') ORDER BY id LIMIT 1');

// ── Fără nicio campanie ────────────────────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== C0. Fără nicio campanie: site-ul ca înainte');
$semne = '/campanie-banner|campanie-insigna|campanie-inapoi|Înapoi în site-ul principal/u';
$faraSemne = [];
foreach (['/', '/magazin', '/produs/' . $slugInCampanie, '/blog'] as $cale) {
    $r = $cerere('GET', $cale);
    if ($r['cod'] !== 200 || preg_match($semne, $r['corp']) === 1 || isset($r['antete']['location'])) {
        $faraSemne[] = $cale . ' (' . $r['cod'] . ')';
    }
}
$verifica('prima pagină, magazinul, un produs și blogul: 200, fără trimitere, fără banner, insignă sau buton', $faraSemne === [],
    implode(', ', $faraSemne));

// ── B. Adminul ─────────────────────────────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== B. Adminul');
$r = $cerere('POST', '/admin/login', $jarAdmin, ['email' => $adminEmail, 'password' => $parolaAdmin]);
$verifica('logarea administratorului general', $r['cod'] === 302, (string) $r['cod']);
$lista = $cerere('GET', '/admin/campanii', $jarAdmin);
$verifica('Marketing → Campanii pe site: lista goală, cu „Campanie nouă”', $lista['cod'] === 200 && str_contains($lista['corp'], 'Nicio campanie încă')
    && str_contains($lista['corp'], 'href="/admin/campanii/noua"') && str_contains($lista['corp'], 'href="/admin/campanii"'), (string) $lista['cod']);
$formular = $cerere('GET', '/admin/campanii/noua', $jarAdmin);
$csrf = $jeton($formular['corp']);
$verifica('formularul unei campanii noi: jetonul, pagina-ciornă în listă, produsele, previzualizarea bannerului', $formular['cod'] === 200 && $csrf !== ''
    && str_contains($formular['corp'], 'value="' . $idPagina . '"') && str_contains($formular['corp'], 'name="produse[]"')
    && str_contains($formular['corp'], 'data-campanie-previzualizare'), (string) $formular['cod']);

$inceput = date('Y-m-d\TH:i', time() - 3600);
$sfarsit = date('Y-m-d\TH:i', time() + 86400);
$campDate = ['_csrf' => $csrf, 'id' => '0', 'nume' => 'Săptămâna demo', 'slug' => '', 'pornita' => '1', 'incepe_la' => $inceput,
    'se_termina_la' => $sfarsit, 'pagina_id' => (string) $idPagina, 'pagina_la_intrare' => '1', 'text_inapoi' => '',
    'insigna_text' => 'Campanie', 'insigna_fundal' => '#e31c23', 'insigna_culoare' => '#ffffff', 'banner_pornit' => '1',
    'banner_text' => 'Reduceri demo de -20% până duminică', 'banner_link' => '/magazin', 'banner_fundal' => '#1f2937',
    'banner_culoare' => '#ffffff', 'banner_font' => 'rubik', 'banner_marime' => '16', 'banner_lipit' => '1'];
$r = $cerere('POST', '/admin/campanii/salveaza', $jarAdmin, ['_csrf' => ''] + $campDate);
$verifica('salvarea fără jetonul formularului: refuzată, nimic scris', $r['cod'] === 302 && $unu('SELECT COUNT(*) FROM campanii') === '0',
    $r['cod'] . ' ' . ($r['antete']['location'] ?? ''));
$r = $cerere('POST', '/admin/campanii/salveaza', $jarAdmin, ['se_termina_la' => date('Y-m-d\TH:i', time() - 7200)] + $campDate);
$verifica('sfârșitul înainte de început: 422, mesajul, nimic scris', $r['cod'] === 422 && str_contains($r['corp'], 'Sfârșitul campaniei trebuie să fie după început')
    && $unu('SELECT COUNT(*) FROM campanii') === '0', (string) $r['cod']);
$r = $cerere('POST', '/admin/campanii/salveaza', $jarAdmin, ['pagina_id' => '0'] + $campDate);
$verifica('pornită, cu pagina la intrare, fără pagină: 422', $r['cod'] === 422 && str_contains($r['corp'], 'Alege pagina campaniei'), (string) $r['cod']);

$corp = http_build_query($campDate);
foreach ($produseCampanie as $idProdus) {
    $corp .= '&' . rawurlencode('produse[]') . '=' . $idProdus;
}
$cerereCorp = static function (string $cale, string $corp) use ($baza, $browser, $jarAdmin): array {
    $c = curl_init($baza . $cale);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $corp, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_COOKIEFILE => $jarAdmin, CURLOPT_COOKIEJAR => $jarAdmin,
        CURLOPT_HTTPHEADER => ['User-Agent: ' . $browser]]);
    $raspuns = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $cod, 'location' => preg_match('/^Location:\s*(\S+)/mi', $raspuns, $m) === 1 ? $m[1] : ''];
};
$r = $cerereCorp('/admin/campanii/salveaza', $corp);
$idCampanie = (int) $unu("SELECT id FROM campanii WHERE slug = 'saptamana-demo'");
$verifica('campania nouă salvată (adresa din nume, fără diacritice) cu cele ' . count($produseCampanie) . ' produse', $r['cod'] === 302 && $idCampanie > 0
    && $r['location'] === '/admin/campanii/' . $idCampanie
    && $unu('SELECT COUNT(*) FROM campanii_produse WHERE campanie_id = :id', ['id' => $idCampanie]) === (string) count($produseCampanie), $r['cod'] . ' ' . $r['location']);
$editor = $cerere('GET', '/admin/campanii/' . $idCampanie, $jarAdmin);
$verifica('după salvare: „Campania a fost salvată.”, starea „Activă acum”, produsele bifate', $flash($editor['corp']) === 'Campania a fost salvată.'
    && str_contains($editor['corp'], 'Activă acum') && substr_count($editor['corp'], '" checked>') >= count($produseCampanie), $flash($editor['corp']));
$verifica('Jurnal activitate: „campanie_salvata”', $unu("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'campanie_salvata'") === '1');
$r = $cerere('POST', '/admin/campanii/salveaza', $jarAdmin, ['nume' => 'Altă campanie', 'slug' => 'alta-campanie',
    'incepe_la' => date('Y-m-d\TH:i', time() + 3600), 'se_termina_la' => date('Y-m-d\TH:i', time() + 172800)] + $campDate);
$verifica('o a doua campanie pornită care se suprapune: 422, „Se suprapune”, nimic scris', $r['cod'] === 422 && str_contains($r['corp'], 'Se suprapune')
    && $unu('SELECT COUNT(*) FROM campanii') === '1', (string) $r['cod']);
$r = $cerere('POST', '/admin/campanii/salveaza', $jarAdmin, ['nume' => 'Altă campanie', 'slug' => 'saptamana-demo', 'pornita' => '0'] + $campDate);
$verifica('aceeași adresă la altă campanie: 422', $r['cod'] === 422 && str_contains($r['corp'], 'e deja a campaniei'), (string) $r['cod']);
// Un produs care are deja o reducere proprie (prețul redus din datele demo): dezactivat în listă, refuzat la salvare.
$verifica('editorul: un produs cu preț redus e dezactivat, cu „are deja o reducere: preț redus”', $idCuReducere > 0
    && str_contains($formular['corp'], 'name="produse[]" value="' . $idCuReducere . '" disabled>')
    && str_contains($formular['corp'], 'are deja o reducere: preț redus'), (string) $idCuReducere);
$r = $cerereCorp('/admin/campanii/salveaza', http_build_query(['nume' => 'Cu reducere', 'slug' => 'cu-reducere', 'pornita' => '0'] + $campDate)
    . '&' . rawurlencode('produse[]') . '=' . $idCuReducere);
$verifica('salvarea cu un produs care are deja o reducere: 422, nimic scris (serverul aplică aceeași regulă)', $r['cod'] === 422
    && $unu("SELECT COUNT(*) FROM campanii WHERE slug = 'cu-reducere'") === '0', (string) $r['cod']);
$r = $cerere('POST', '/admin/campanii/salveaza', $jarAdmin, ['nume' => 'Campania de iarnă', 'slug' => '', 'pornita' => '1',
    'incepe_la' => date('Y-m-d\TH:i', time() + 86400 * 30), 'se_termina_la' => date('Y-m-d\TH:i', time() + 86400 * 37)] + $campDate);
$idProgramata = (int) $unu("SELECT id FROM campanii WHERE slug = 'campania-de-iarna'");
$verifica('o campanie pornită mai târziu, fără suprapunere: salvată, „Programată” în listă', $r['cod'] === 302 && $idProgramata > 0
    && str_contains($cerere('GET', '/admin/campanii', $jarAdmin)['corp'], 'Programată'), (string) $r['cod']);

// ── C. Pe site, cu campania activă ─────────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== C. Pe site, cu campania activă');
ResponseCache::purgePageCache();
$tinta = '/produs/' . $slugInCampanie . '?utm_source=test&gclid=demo';
$r = $cerere('GET', $tinta, null, [], ['Referer: https://www.google.com/']);
$asteptat = '/campanie/saptamana-demo?inapoi=' . rawurlencode($tinta);
// Decizia proprietarului (5 nov): la fiecare intrare nouă, și fără Referer (adresa scrisă, semn de carte, o aplicație).
$faraRef = $cerere('GET', $tinta);
$deLaFacebook = $cerere('GET', '/blog', null, [], ['Referer: https://m.facebook.com/']);
$deLaWww = $cerere('GET', '/magazin', null, [], ['Referer: http://www.127.0.0.1:' . $port . '/']);
$verifica('intrarea fără Referer (adresa scrisă, semn de carte, aplicație) și de pe alt site: trimise la campanie; navigarea din site (și www.) nu',
    $faraRef['cod'] === 302 && ($faraRef['antete']['location'] ?? '') === $asteptat && $deLaFacebook['cod'] === 302
    && str_starts_with((string) ($deLaFacebook['antete']['location'] ?? ''), '/campanie/saptamana-demo?inapoi=%2Fblog')
    && !isset($deLaWww['antete']['location']), $faraRef['cod'] . ' / ' . $deLaFacebook['cod'] . ' / ' . $deLaWww['cod']);
$verifica('prima intrare (de pe Google): 302 la pagina campaniei, cu adresa cerută în „inapoi”', $r['cod'] === 302
    && ($r['antete']['location'] ?? '') === $asteptat, $r['cod'] . ' ' . ($r['antete']['location'] ?? ''));
$cookie = (string) ($r['antete']['set-cookie'] ?? '');
$verifica('cookie-ul de sesiune: doar id-ul campaniei, fără Expires, HttpOnly, SameSite=Lax; răspunsul fără cache',
    str_starts_with($cookie, Campanii::COOKIE . '=' . $idCampanie . ';') && stripos($cookie, 'expires') === false
    && stripos($cookie, 'httponly') !== false && stripos($cookie, 'samesite=lax') !== false
    && str_contains((string) ($r['antete']['cache-control'] ?? ''), 'no-store'), $cookie . ' | ' . ($r['antete']['cache-control'] ?? ''));
$pagina = $cerere('GET', $asteptat);
$verifica('pagina campaniei: 200, conținutul paginii-ciornă, butonul „← Înapoi în site-ul principal” spre adresa cerută', $pagina['cod'] === 200
    && str_contains($pagina['corp'], 'Reduceri inventate la produsele demo')
    && str_contains($pagina['corp'], 'class="campanie-inapoi__buton" href="' . htmlspecialchars($tinta, ENT_QUOTES) . '"')
    && str_contains($pagina['corp'], '← Înapoi în site-ul principal'), (string) $pagina['cod']);
$verifica('pagina campaniei: noindex (meta și X-Robots-Tag), fără canonical, fără cache, fără bannerul de sus', str_contains($pagina['corp'], '<meta name="robots" content="noindex, follow">')
    && ($pagina['antete']['x-robots-tag'] ?? '') === 'noindex, follow' && !str_contains($pagina['corp'], 'rel="canonical"')
    && str_contains((string) ($pagina['antete']['cache-control'] ?? ''), 'no-store') && !str_contains($pagina['corp'], 'data-campanie-banner'),
    ($pagina['antete']['x-robots-tag'] ?? '') . ' ' . ($pagina['antete']['cache-control'] ?? ''));
$rau = $cerere('GET', '/campanie/saptamana-demo?inapoi=' . rawurlencode('//rau.exemplu/x'));
$verifica('pagina campaniei cu „inapoi” spre alt site: butonul duce la prima pagină', str_contains($rau['corp'], 'class="campanie-inapoi__buton" href="/"'));
$r = $cerere('GET', $tinta, null, [], ['Cookie: ' . Campanii::COOKIE . '=' . $idCampanie]);
$verifica('cu cookie-ul: pagina cerută, cu insigna produsului și bannerul (lipit, cu linkul lui, fontul Rubik)', $r['cod'] === 200
    && str_contains($r['corp'], 'campanie-insigna--pagina') && str_contains($r['corp'], 'data-campanie-banner data-lipit="1"')
    && str_contains($r['corp'], 'href="/magazin" style="color:inherit') && str_contains($r['corp'], '/assets/vendor/fonturi/rubik/rubik.css')
    && str_contains($r['corp'], 'Reduceri demo de -20% până duminică'), (string) $r['cod']);
// Bannerul fără link scris: duce la pagina campaniei, cu întoarcerea la pagina curentă (singurul drum spre ea în aceeași vizită).
$db->prepare("UPDATE campanii SET banner_link = '' WHERE id = :id")->execute(['id' => $idCampanie]);
ResponseCache::purgePageCache();
$r = $cerere('GET', '/magazin?sort=alpha_asc', null, [], ['Cookie: ' . Campanii::COOKIE . '=' . $idCampanie]);
$linkBanner = '/campanie/saptamana-demo?inapoi=' . rawurlencode('/magazin?sort=alpha_asc');
$verifica('bannerul fără link scris: duce la pagina campaniei, cu „inapoi” = pagina curentă', $r['cod'] === 200
    && str_contains($r['corp'], 'href="' . htmlspecialchars($linkBanner, ENT_QUOTES) . '" style="color:inherit'), (string) $r['cod']);
$r = $cerere('GET', $linkBanner, null, [], ['Cookie: ' . Campanii::COOKIE . '=' . $idCampanie, 'Referer: ' . $baza . '/magazin?sort=alpha_asc']);
$verifica('clicul pe banner (vizitatorul a văzut deja campania): pagina campaniei, cu butonul înapoi la pagina de pe care a venit', $r['cod'] === 200
    && str_contains($r['corp'], 'class="campanie-inapoi__buton" href="/magazin?sort=alpha_asc"'), (string) $r['cod']);
$db->prepare("UPDATE campanii SET pagina_la_intrare = 0 WHERE id = :id")->execute(['id' => $idCampanie]);
ResponseCache::purgePageCache();
$r = $cerere('GET', '/magazin', null, [], ['Cookie: ' . Campanii::COOKIE . '=' . $idCampanie]);
$verifica('bannerul fără link, cu pagina campaniei oprită: fără link', $r['cod'] === 200 && str_contains($r['corp'], 'data-campanie-banner')
    && !preg_match('#campanie-banner__text[^>]*><a #', $r['corp']), (string) $r['cod']);
$db->prepare("UPDATE campanii SET pagina_la_intrare = 1, banner_link = '/magazin' WHERE id = :id")->execute(['id' => $idCampanie]);
ResponseCache::purgePageCache();
$r = $cerere('GET', '/produs/' . $slugInafara, null, [], ['Cookie: ' . Campanii::COOKIE . '=' . $idCampanie]);
$verifica('un produs care nu e în campanie: fără insigna produsului (bannerul da)', $r['cod'] === 200 && !str_contains($r['corp'], 'campanie-insigna--pagina')
    && str_contains($r['corp'], 'data-campanie-banner'), (string) $r['cod']);
$r = $cerere('GET', '/magazin', null, [], ['Cookie: ' . Campanii::COOKIE . '=' . $idCampanie]);
$verifica('magazinul: insigna pe cardurile produselor din campanie', $r['cod'] === 200 && substr_count($r['corp'], 'campanie-insigna--card') >= 1
    && substr_count($r['corp'], 'campanie-insigna--card') <= count($produseCampanie), (string) substr_count($r['corp'], 'campanie-insigna--card'));
$acasa = $cerere('GET', '/', null, [], ['Cookie: ' . Campanii::COOKIE . '=' . $idCampanie]);
$verifica('prima pagină: insigna în grila {{featured_products}} (cel mai nou produs e în campanie)', $acasa['cod'] === 200
    && str_contains($acasa['corp'], 'campanie-insigna--acasa'), (string) $acasa['cod']);
$similare = 0;
$similareJson = 0;
$slugCuSimilare = '';
foreach ($produseCampanie as $idProdus) {
    $slugProdus = $unu('SELECT slug FROM products WHERE id = :id', ['id' => $idProdus]);
    $corp = $cerere('GET', '/produs/' . rawurlencode($slugProdus), null, [], ['Cookie: ' . Campanii::COOKIE . '=' . $idCampanie])['corp'];
    // Cardurile scrise de server (nu șablonul din JavaScript, care are aceeași clasă).
    $n = preg_match_all('#class="campanie-insigna campanie-insigna--similar" style="[^"]*">Campanie</span>#', $corp);
    $similare += $n;
    $similareJson += substr_count($corp, '"campanie_insigna":"Campanie"');
    if ($n > 0 && $slugCuSimilare === '') {
        $slugCuSimilare = $slugProdus;
    }
}
$verifica('produsele similare de pe pagina produsului: insigna pe carduri (HTML-ul serverului și datele din care le reface JavaScript-ul)',
    $similare >= 1 && $similareJson >= 1, $similare . ' carduri, ' . $similareJson . ' în date');
// Banda {{product_produse_similare}} (views/site/components/produse-categorie.php), randată direct: insigna doar pe produsul din campanie.
Campanii::reseteaza();
ob_start();
$produse = [['id' => $produseCampanie[0], 'name' => 'În campanie', 'slug' => 'in-campanie'], ['id' => 999999, 'name' => 'În afara ei', 'slug' => 'altul']];
$titlu = 'Produse asemănătoare';
include $radacina . '/views/site/components/produse-categorie.php';
$banda = (string) ob_get_clean();
$verifica('banda {{product_produse_similare}}: insigna doar pe cardul produsului din campanie', substr_count($banda, 'campanie-insigna--banda') === 1);
$categorie = $unu('SELECT c.slug FROM product_categories c JOIN product_category_links l ON l.category_id = c.id
    WHERE l.product_id = :p AND c.slug <> "" LIMIT 1', ['p' => $produseCampanie[0]]);
if ($categorie !== '') {
    $r = $cerere('GET', '/categorie/' . rawurlencode($categorie), null, [], ['Cookie: ' . Campanii::COOKIE . '=' . $idCampanie]);
    $verifica('pagina categoriei: insigna pe card', $r['cod'] === 200 && str_contains($r['corp'], 'campanie-insigna--card'), $categorie . ' ' . $r['cod']);
}

$faraTrimitere = [];
$cazuriSite = [
    'Googlebot pe un produs' => ['GET', $tinta, ['User-Agent: Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']],
    'navigarea din site' => ['GET', '/magazin', ['Referer: ' . $baza . '/']],
    'cu cookie-ul' => ['GET', '/', ['Cookie: ' . Campanii::COOKIE . '=' . $idCampanie]],
    'coșul' => ['GET', '/cos', []], 'checkout' => ['GET', '/checkout', []], 'contul clientului' => ['GET', '/contul-meu', []],
    'login' => ['GET', '/login', []], 'API' => ['GET', '/api/cart/count', ['Accept: application/json']],
    'API cerut ca pagină' => ['GET', '/api/shop/catalog', []], 'pingul platformei' => ['GET', '/_andaxi/ping', []],
    'harta site-ului' => ['GET', '/sitemap.xml', []], 'robots.txt' => ['GET', '/robots.txt', []],
    'o foaie de stil' => ['GET', '/assets/css/app.css', []], 'adminul' => ['GET', '/admin', []],
    'fetch (Accept JSON)' => ['GET', '/', ['Accept: application/json']], 'iframe' => ['GET', '/', ['Sec-Fetch-Dest: iframe']],
    'pagina campaniei însăși' => ['GET', '/campanie/saptamana-demo', []],
];
foreach ($cazuriSite as $nume => [$metoda, $cale, $antete]) {
    $r = $cerere($metoda, $cale, null, [], $antete);
    if (str_starts_with((string) ($r['antete']['location'] ?? ''), '/campanie/')) {
        $faraTrimitere[] = $nume;
    }
}
$r = $cerere('POST', '/cos/adauga/' . $produseCampanie[0], null, ['quantity' => '1']);
if (str_starts_with((string) ($r['antete']['location'] ?? ''), '/campanie/')) {
    $faraTrimitere[] = 'POST în coș';
}
$verifica('nicio trimitere la campanie pentru: ' . implode(', ', array_keys($cazuriSite)) . ', POST în coș', $faraTrimitere === [],
    implode(', ', $faraTrimitere));
$r = $cerere('GET', $tinta, null, [], ['User-Agent: Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']);
$verifica('Googlebot vede pagina produsului (200), nu pagina campaniei', $r['cod'] === 200 && !str_contains($r['corp'], 'campanie-inapoi'), (string) $r['cod']);
$r = $cerere('GET', '/', null, [], ['Cookie: ' . Campanii::COOKIE . '=' . ($idCampanie + 1000)]);
$verifica('cookie-ul altei campanii: trimis din nou (o campanie nouă se vede o dată)', $r['cod'] === 302
    && str_starts_with((string) ($r['antete']['location'] ?? ''), '/campanie/saptamana-demo'), (string) $r['cod']);

// ── C1. Pagina fără antet și subsol, {{campanie_produse}} (campanii/0056) ──────────────────────────────────────────────────
$scrie('');
$scrie('== C1. Pagina campaniei fără antetul și subsolul site-ului, codul {{campanie_produse}}');
$verifica('editorul unei campanii noi: „Pagina campaniei fără antetul și subsolul site-ului” bifată, bara și cardurile deschise, „Text sub preț”',
    str_contains($formular['corp'], 'name="fara_antet_subsol" value="1" checked') && str_contains($formular['corp'], '<option value="0" selected>Deschisă (albă)</option>')
    && str_contains($formular['corp'], '<option value="0" selected>Deschise (albe)</option>') && str_contains($formular['corp'], 'name="text_sub_pret"'));
$verifica('campania salvată fără bifa (formularul de mai sus): fara_antet_subsol = 0, bara și cardurile deschise',
    $unu('SELECT CONCAT(fara_antet_subsol, bara_inchisa, carduri_inchise) FROM campanii WHERE id = :id', ['id' => $idCampanie]) === '000');
// Un antet și un subsol scrise în admin (semne fără „campanie” în ele: C2 caută „campanie-” în pagini), produsele: unul ascuns din
// catalog, unul epuizat, unul cu preț redus cunoscut.
$setare = $db->prepare('INSERT INTO settings (`key`, value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE value = VALUES(value)');
$setare->execute(['k' => 'design_header_html', 'v' => '<div class="antet-proba-bf">ANTET-PROBA</div>']);
$setare->execute(['k' => 'design_footer_html', 'v' => '<footer class="subsol-proba-bf">SUBSOL-PROBA</footer>']);
[$pRedus, $pAscuns, $pEpuizat] = [$produseCampanie[0], $produseCampanie[1], $produseCampanie[2]];
$db->prepare('UPDATE products SET price = 100, sale_price = 80, sale_price_periods_json = NULL, bbd_enabled = 0, preorder_enabled = 0,
    out_of_stock = 0, hidden_in_catalog = 0 WHERE id = :id')->execute(['id' => $pRedus]);
$db->prepare('UPDATE products SET hidden_in_catalog = 1 WHERE id = :id')->execute(['id' => $pAscuns]);
$db->prepare('UPDATE products SET out_of_stock = 1, preorder_enabled = 0 WHERE id = :id')->execute(['id' => $pEpuizat]);
$db->prepare('UPDATE pages SET html_content = :h WHERE id = :id')->execute(['id' => $idPagina,
    'h' => '<section><h1>Săptămâna demo</h1><p>Reduceri inventate la produsele demo.</p>{{campanie_produse}}</section>']);
ResponseCache::purgePageCache();
$cuCookie = ['Cookie: ' . Campanii::COOKIE . '=' . $idCampanie];
$adresaCampanie = '/campanie/saptamana-demo?inapoi=%2Fmagazin';
$r = $cerere('GET', $adresaCampanie, null, [], $cuCookie);
$verifica('fără bifă: pagina campaniei cu antetul, subsolul și coșul plutitor ale site-ului (ca înainte), bara deschisă',
    $r['cod'] === 200 && str_contains($r['corp'], 'ANTET-PROBA') && str_contains($r['corp'], 'SUBSOL-PROBA')
    && str_contains($r['corp'], '/assets/js/floating-cart.js') && !str_contains($r['corp'], '.floating-cart__trigger{display:none')
    && str_contains($r['corp'], 'data-campanie-inapoi') && !str_contains($r['corp'], 'data-bara-inchisa'), (string) $r['cod']);
$numarCarduri = substr_count($r['corp'], '<article class="campanie-grila__card">');
$verifica('{{campanie_produse}}: un card pe produs vizibil (fără cel ascuns din catalog), codul înlocuit', $numarCarduri === count($produseCampanie) - 1
    && !str_contains($r['corp'], '{{campanie_produse}}') && str_contains($r['corp'], 'campanie-grila--deschise'), $numarCarduri . ' carduri');
$slugRedus = $unu('SELECT slug FROM products WHERE id = :id', ['id' => $pRedus]);
$verifica('cardul: poza, numele, insigna campaniei, prețul redus și cel vechi tăiat, „Vezi produsul” spre produs',
    str_contains($r['corp'], '80.00 lei <del class="campanie-grila__vechi">100.00 lei</del>')
    && str_contains($r['corp'], '<a class="campanie-grila__buton" href="/produs/' . rawurlencode($slugRedus) . '">Vezi produsul</a>')
    && str_contains($r['corp'], '<span class="campanie-grila__insigna" style="background:#e31c23;color:#ffffff;">Campanie</span>')
    && !str_contains($r['corp'], '/produs/' . rawurlencode($unu('SELECT slug FROM products WHERE id = :id', ['id' => $pAscuns])) . '"'));
// „Adaugă în coș”: același formular ca pe cardul din magazin (views/site/components/product-card.php): POST /cos/adauga/<id>.
$magazin = $cerere('GET', '/magazin', null, [], $cuCookie);
$verifica('„Adaugă în coș” pe card: formularul cardului din magazin (POST /cos/adauga/<id>, același drum ca în /magazin); epuizat: „Stoc epuizat”, fără formular',
    str_contains($r['corp'], '<form class="campanie-grila__formular" method="post" action="/cos/adauga/' . $pRedus . '">')
    && str_contains($magazin['corp'], '<form method="post" action="/cos/adauga/')
    && preg_match('#action="/cos/adauga/' . $pEpuizat . '"#', $r['corp']) !== 1
    && str_contains($r['corp'], '<span class="campanie-grila__stoc bv-popular-card__stock-out">Stoc epuizat</span>'));
$jarCos = $dosarDev . '/test-campanii-cos-' . getmypid() . '.txt';
$cerere('GET', '/', $jarCos, [], $cuCookie);
$adaugat = $cerere('POST', '/cos/adauga/' . $pRedus, $jarCos, []);
$cos = $cerere('GET', '/cos', $jarCos);
@unlink($jarCos);
$verifica('formularul de pe card pune produsul în coș (o bucată), ca în magazin', in_array($adaugat['cod'], [302, 303], true)
    && $cos['cod'] === 200 && str_contains($cos['corp'], '/produs/' . rawurlencode($slugRedus)), $adaugat['cod'] . ' ' . ($adaugat['antete']['location'] ?? '') . ' / ' . $cos['cod']);
$verifica('fără „Text sub preț”: niciun rând sub preț', !str_contains($r['corp'], '<p class="campanie-grila__sub">'));

$db->prepare("UPDATE campanii SET fara_antet_subsol = 1, bara_inchisa = 1, carduri_inchise = 1, text_sub_pret = :t WHERE id = :id")
    ->execute(['id' => $idCampanie, 't' => '2 bucăți: {pret*1.5} <b>'] );
$setare->execute(['k' => 'google_tag_manager_enabled', 'v' => '1']);
$setare->execute(['k' => 'google_tag_manager_id', 'v' => 'GTM-PROBA1']);
ResponseCache::purgePageCache();
$r = $cerere('GET', $adresaCampanie, null, [], $cuCookie);
$verifica('cu bifa: fără antetul, subsolul, sertarul „Oferte” și butonul coșului plutitor (scriptul coșului rămâne, pentru „Adaugă în coș”)',
    $r['cod'] === 200 && !str_contains($r['corp'], 'ANTET-PROBA') && !str_contains($r['corp'], 'SUBSOL-PROBA')
    && !str_contains($r['corp'], '<header class="header">') && !str_contains($r['corp'], 'data-bv-bbd-sidebar>')
    && str_contains($r['corp'], '/assets/js/floating-cart.js') && str_contains($r['corp'], '.floating-cart__trigger{display:none!important;}'),
    (string) $r['cod']);
$verifica('cu bifa: bara „Înapoi” (închisă), bannerul de cookie-uri, conținutul paginii și sfârșitul paginii rămân',
    str_contains($r['corp'], 'data-campanie-inapoi') && str_contains($r['corp'], 'data-bara-inchisa="1"') && str_contains($r['corp'], 'background:#000000')
    && str_contains($r['corp'], 'id="cookie-banner"') && str_contains($r['corp'], 'Reduceri inventate la produsele demo')
    && str_contains($r['corp'], '</main>') && str_contains($r['corp'], '</body>'));
$cuAcord = $cerere('GET', $adresaCampanie, null, [], ['Cookie: ' . Campanii::COOKIE . '=' . $idCampanie . '; ' . \App\Support\CookieConsent::nume() . '=all']);
$verifica('cu bifa și acordul pentru cookie-uri: Google Tag Manager (și noscript-ul lui) rămâne', str_contains($cuAcord['corp'], 'googletagmanager.com/ns.html?id=GTM-PROBA1')
    && !str_contains($cuAcord['corp'], 'ANTET-PROBA'));
$pret15 = number_format(80 * 1.5, 2);
$verifica('cardurile închise (#161616 pe negru, text alb) și „Text sub preț” cu {pret*1.5} (escapat)', str_contains($r['corp'], 'campanie-grila--inchise')
    && str_contains($r['corp'], 'background:#161616') && str_contains($r['corp'], 'background:#000000;}')
    && str_contains($r['corp'], '<p class="campanie-grila__sub">2 bucăți: ' . $pret15 . ' lei &lt;b&gt;</p>'));
$verifica('colțurile cardurilor implicit rotunjite (16px, butoanele 999px), cu o regulă mai tare decât `.site-shell article` a temei',
    str_contains($r['corp'], '.site-shell .campanie-grila article.campanie-grila__card,.campanie-grila article.campanie-grila__card{border-radius:16px!important;overflow:hidden!important;}')
    && str_contains($r['corp'], '.site-shell .campanie-grila .campanie-grila__stoc{border-radius:999px!important;}')
    && !str_contains($r['corp'], 'campanie-grila--drepte'));
$db->prepare('UPDATE campanii SET carduri_drepte = 1 WHERE id = :id')->execute(['id' => $idCampanie]);
$drepte = $cerere('GET', $adresaCampanie, null, [], $cuCookie);
$verifica('„Colțurile cardurilor: drepte”: cardul, poza și butoanele cu border-radius 0', str_contains($drepte['corp'], 'campanie-grila--drepte')
    && str_contains($drepte['corp'], 'article.campanie-grila__card{border-radius:0!important;overflow:hidden!important;}') && !str_contains($drepte['corp'], 'border-radius:16px')
    && preg_match('/\.campanie-grila__cos,\.campanie-grila__buton\{[^}]*border-radius:0;/', $drepte['corp']) === 1
    && str_contains($drepte['corp'], '.campanie-grila__stoc{border-radius:0!important;}')
    && str_contains($drepte['corp'], 'a.campanie-grila__poza{border-radius:0!important;}'));
$db->prepare('UPDATE campanii SET carduri_drepte = 0 WHERE id = :id')->execute(['id' => $idCampanie]);
$verifica('editorul: „Colțurile cardurilor” cu „Rotunjite” implicit', str_contains($formular['corp'], 'name="carduri_drepte"')
    && str_contains($formular['corp'], '<option value="0" selected>Rotunjite</option>'));
$verifica('Campanii::textSubPret: {pret}, {pret*1.5}, {pret x 2}, {pret×1,5}, text gol',
    Campanii::textSubPret('{pret}', 10.0) === '10.00 lei' && Campanii::textSubPret('2 buc: {pret*1.5}', 10.0) === '2 buc: 15.00 lei'
    && Campanii::textSubPret('{pret x 2}', 10.0) === '20.00 lei' && Campanii::textSubPret('{pret×1,5}', 10.0) === '15.00 lei'
    && Campanii::textSubPret('  ', 10.0) === '');
// La adresa proprie a paginii (publicată, pentru test): site-ul ca de obicei, codul gol.
$db->prepare('UPDATE pages SET is_published = 1 WHERE id = :id')->execute(['id' => $idPagina]);
ResponseCache::purgePageCache();
$proprie = $cerere('GET', '/test-campanie-saptamana-demo', null, [], $cuCookie);
$verifica('la adresa proprie a paginii: antetul și subsolul site-ului, codul {{campanie_produse}} gol (nici grila, nici textul codului)',
    $proprie['cod'] === 200 && str_contains($proprie['corp'], 'ANTET-PROBA') && str_contains($proprie['corp'], 'SUBSOL-PROBA')
    && !str_contains($proprie['corp'], 'campanie-grila') && !str_contains($proprie['corp'], 'campanie_produse')
    && !str_contains($proprie['corp'], 'data-campanie-inapoi') && !str_contains($proprie['corp'], '.floating-cart__trigger{display:none'), (string) $proprie['cod']);
$db->prepare('UPDATE pages SET is_published = 0 WHERE id = :id')->execute(['id' => $idPagina]);
// Fără produse: codul gol.
$db->prepare('UPDATE products SET is_active = 0 WHERE id IN (' . implode(',', $produseCampanie) . ')')->execute();
ResponseCache::purgePageCache();
$r = $cerere('GET', $adresaCampanie, null, [], $cuCookie);
$verifica('niciun produs vizibil al campaniei: codul gol, fără grilă', $r['cod'] === 200 && !str_contains($r['corp'], 'campanie-grila')
    && !str_contains($r['corp'], 'campanie_produse'), (string) $r['cod']);
// Înapoi la datele de dinainte (C2 le folosește; baza toată se pune la loc oricum la final).
$inapoiC1 = static function () use ($db, $tabeleBaza, $instantaneu, $idPagina, $idCampanie): void {
    foreach ($tabeleBaza as $i => $t) {
        if (in_array($t['tabel'], ['products', 'settings'], true)) {
            salvari_pune_la_loc($db, [$t], [$instantaneu[$i]]);
        }
    }
    $db->prepare('UPDATE pages SET html_content = :h WHERE id = :id')->execute(['id' => $idPagina,
        'h' => '<section style="padding:32px 16px;text-align:center"><h1>Săptămâna demo</h1><p>Reduceri inventate la produsele demo.</p></section>']);
    $db->prepare('UPDATE campanii SET fara_antet_subsol = 0, bara_inchisa = 0, carduri_inchise = 0, text_sub_pret = NULL WHERE id = :id')
        ->execute(['id' => $idCampanie]);
    ResponseCache::purgePageCache();
};
$inapoiC1();

// ── D. Chromium ───────────────────────────────────────────────────────────────────────────────────────────────────────
if (!$faraBrowser) {
    $scrie('');
    $scrie('== D. În Chromium (desktop 1366 px, telefon 390 px)');
    $dosarCapturi = $dosarDev . '/campanii';
    @mkdir($dosarCapturi, 0775, true);
    $env = ['CMS_DEV_URL=' . $baza, 'CMS_DEV_PRODUS=' . $slugInCampanie, 'CMS_DEV_PRODUS_SIMILARE=' . $slugCuSimilare, 'CMS_DEV_CAMPANIE=' . $idCampanie, 'CMS_DEV_ADMIN_EMAIL=' . $adminEmail,
        'CMS_DEV_PAROLA=' . $parolaAdmin, 'CMS_DEV_CAPTURI=' . $dosarCapturi, 'NO_PROXY=*', 'no_proxy=*'];
    $ruleazaBrowser = static function (array $env) use ($verifica, $scrie, $dosarCapturi): void {
        $iesire = [];
        exec('env -u http_proxy -u https_proxy -u HTTP_PROXY -u HTTPS_PROXY -u ALL_PROXY -u all_proxy ' . implode(' ', array_map('escapeshellarg', $env))
            . ' node ' . escapeshellarg(__DIR__ . '/campanii-browser.cjs') . ' 2>&1', $iesire, $codNode);
        $rezultate = json_decode((string) end($iesire), true);
        if (!is_array($rezultate) || $rezultate === []) {
            $verifica('Chromium a rulat', false, 'cod ' . $codNode . ': ' . implode("\n", array_slice($iesire, -15)));
        } else {
            foreach ($rezultate as $rezultat) {
                $verifica((string) $rezultat['eticheta'], (bool) $rezultat['ok'], (string) $rezultat['detalii']);
            }
            $scrie('  INFO  capturile: ' . $dosarCapturi);
        }
    };
    $ruleazaBrowser($env);
    // Pagina campaniei fără antet și subsol, cu bara și cardurile închise și grila {{campanie_produse}}.
    $setare->execute(['k' => 'design_header_html', 'v' => '<div class="antet-proba-bf">ANTET-PROBA</div>']);
    $setare->execute(['k' => 'design_footer_html', 'v' => '<footer class="subsol-proba-bf">SUBSOL-PROBA</footer>']);
    $db->prepare('UPDATE pages SET html_content = :h WHERE id = :id')->execute(['id' => $idPagina,
        'h' => '<section style="background:#000;color:#fff;padding:24px 0"><h1 style="text-align:center">Săptămâna demo</h1>{{campanie_produse}}</section>']);
    $db->prepare("UPDATE campanii SET fara_antet_subsol = 1, bara_inchisa = 1, carduri_inchise = 1, text_sub_pret = '2 bucăți: {pret*1.5}' WHERE id = :id")
        ->execute(['id' => $idCampanie]);
    ResponseCache::purgePageCache();
    $ruleazaBrowser(array_merge($env, ['CMS_DEV_GRILA=1']));
    $inapoiC1();
}

// ── C. În afara ferestrei, oprită, previzualizarea, pluginul oprit ─────────────────────────────────────────────────────
$scrie('');
$scrie('== C2. În afara ferestrei, oprită, pluginul oprit');
$verificaNimic = static function (string $eticheta) use ($cerere, $verifica, $tinta, $slugInCampanie, $idCampanie): void {
    $r1 = $cerere('GET', $tinta, null, [], ['Referer: https://www.google.com/']);
    $r2 = $cerere('GET', '/campanie/saptamana-demo?inapoi=%2F');
    $r3 = $cerere('GET', '/magazin', null, [], ['Cookie: ' . Campanii::COOKIE . '=' . $idCampanie]);
    $verifica($eticheta . ': nicio trimitere, pagina campaniei 404, fără banner și fără insigne', $r1['cod'] === 200
        && !isset($r1['antete']['location']) && !str_contains($r1['corp'], 'campanie-') && $r2['cod'] === 404
        && $r3['cod'] === 200 && !str_contains($r3['corp'], 'campanie-insigna') && !str_contains($r3['corp'], 'data-campanie-banner'),
        $r1['cod'] . ' ' . ($r1['antete']['location'] ?? '') . ' / ' . $r2['cod'] . ' / ' . $r3['cod']);
};
$db->prepare('UPDATE campanii SET incepe_la = :i, se_termina_la = :s WHERE id = :id')->execute(['i' => date('Y-m-d H:i:s', time() + 3600),
    's' => date('Y-m-d H:i:s', time() + 7200), 'id' => $idCampanie]);
$verificaNimic('înainte de început');
$r = $cerere('GET', '/campanie/saptamana-demo', $jarAdmin);
$verifica('administratorul logat vede pagina campaniei ca previzualizare (cu nota), fără cookie-ul vizitatorului', $r['cod'] === 200
    && str_contains($r['corp'], 'Previzualizare: campania nu e activă acum') && !str_contains((string) ($r['antete']['set-cookie'] ?? ''), Campanii::COOKIE),
    (string) $r['cod']);
$db->prepare('UPDATE campanii SET incepe_la = :i, se_termina_la = :s WHERE id = :id')->execute(['i' => date('Y-m-d H:i:s', time() - 7200),
    's' => date('Y-m-d H:i:s', time() - 60), 'id' => $idCampanie]);
$verificaNimic('după sfârșit');
$db->prepare('UPDATE campanii SET pornita = 0, incepe_la = :i, se_termina_la = :s WHERE id = :id')->execute([
    'i' => date('Y-m-d H:i:s', time() - 3600), 's' => date('Y-m-d H:i:s', time() + 3600), 'id' => $idCampanie]);
$verificaNimic('oprită (în fereastră, nebifată)');
$db->prepare('UPDATE campanii SET pornita = 1, banner_lipit = 0, pagina_la_intrare = 0 WHERE id = :id')->execute(['id' => $idCampanie]);
$r = $cerere('GET', $tinta, null, [], ['Referer: https://www.google.com/']);
$verifica('pagina la intrare debifată: nicio trimitere; bannerul nelipit (position:relative), insigna rămâne', $r['cod'] === 200
    && !isset($r['antete']['location']) && str_contains($r['corp'], 'data-campanie-banner style="position:relative')
    && str_contains($r['corp'], 'campanie-insigna--pagina'), (string) $r['cod']);
$db->prepare('UPDATE campanii SET pagina_la_intrare = 1 WHERE id = :id')->execute(['id' => $idCampanie]);

$licenta('toate', 'campanii=oprit');
$verificaNimic('pluginul oprit în licență');
$r = $cerere('GET', '/campanie/saptamana-demo');
$verifica('pluginul oprit: /campanie/… dă 404 de la gardă', $r['cod'] === 404 && isset($r['antete']['x-andaxi-garda']), (string) $r['cod']);
$r = $cerere('GET', '/admin/campanii', $jarAdmin);
$verifica('pluginul oprit: adminul dă „Modulul nu este inclus în abonament” (403), meniul fără „Campanii pe site”',
    $r['cod'] === 403 && !str_contains($cerere('GET', '/admin/pages', $jarAdmin)['corp'], 'href="/admin/campanii"'), (string) $r['cod']);
$licenta('toate');
$r = $cerere('GET', $tinta, null, [], ['Referer: https://www.google.com/']);
$verifica('pluginul pornit din nou: campania se vede iar (datele au rămas)', $r['cod'] === 302, (string) $r['cod']);

// Ștergerea din admin (cu jetonul): campania și produsele ei pleacă, pagina din Conținut → Pagini rămâne.
$editor = $cerere('GET', '/admin/campanii/' . $idProgramata, $jarAdmin);
$r = $cerere('POST', '/admin/campanii/' . $idProgramata . '/sterge', $jarAdmin, ['_csrf' => $jeton($editor['corp'])]);
$verifica('ștergerea unei campanii: campania și produsele ei, nu și pagina', $r['cod'] === 302 && $unu('SELECT COUNT(*) FROM campanii WHERE id = :id',
    ['id' => $idProgramata]) === '0' && $unu('SELECT COUNT(*) FROM campanii_produse WHERE campanie_id = :id', ['id' => $idProgramata]) === '0'
    && $unu('SELECT COUNT(*) FROM pages WHERE id = :id', ['id' => $idPagina]) === '1', (string) $r['cod']);

// ── E. Curățenia ──────────────────────────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== E. Curățenia');
$puneLaLoc();
$verifica('baza pusă la loc: CHECKSUM TABLE identic pe fiecare tabel', $sumeBaza() === $sumeInainte,
    implode(', ', array_keys(array_diff_assoc($sumeBaza(), $sumeInainte))));
$erori = is_file($fisierErori) ? (string) file_get_contents($fisierErori, false, null, $pozitieErori) : '';
$verifica('jurnalul PHP: niciun Warning, Notice sau Fatal nou', preg_match('/PHP (Warning|Notice|Fatal|Parse)/', $erori) !== 1, mb_substr($erori, 0, 1200));

$scrie('');
$scrie('Rezultat: ' . $trecute . ' trecute, ' . $esuate . ' eșuate. Raport: ' . $fisierRaport);
exit($esuate === 0 ? 0 : 1);
