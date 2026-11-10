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
 *    interiorul site-ului; la fiecare intrare din afară, și cu un cookie vechi), culorile, linkul bannerului, starea unei campanii, cheia cache-ului fără campanie.
 * B. Adminul (administratorul general): lista goală, o campanie nouă salvată din formular (cu jetonul formularului), refuzurile
 *    (fără jeton, sfârșit înainte de început, suprapunerea cu altă campanie pornită, pornită fără pagină), jurnalul adminilor;
 *    un produs cu o reducere proprie se poate alege (cu nota reducerii) și se salvează.
 * C. Pe site, cu campania activă: prima intrare (302 la /campanie/<slug>?inapoi=…, fără cookie, fără cache; și la a doua intrare din afară),
 *    pagina campaniei (butonul spre adresa cerută, noindex, fără cache), nicio trimitere pentru roboți, POST, coș, checkout,
 *    cont, API, /_andaxi/, fișiere, admin, navigarea din site; trimiterea și fără Referer și de pe alt site
 *    (decizia proprietarului din 5 nov: la fiecare intrare nouă); insigna doar pe produsele alese (pagina produsului,
 *    magazinul, categoria, {{featured_products}}, produsele similare, {{product_produse_similare}}), bannerul (lipit sau nu,
 *    cu linkul lui; fără link, spre pagina campaniei cu întoarcerea la pagina curentă, și clicul pe el); în afara ferestrei și oprită: nimic (pagina 404, fără banner, fără insignă), afară de
 *    previzualizarea administratorului; cu pluginul oprit: garda (404 pe site, 403 în admin), nicio trimitere, nicio insignă;
 *    fără nicio campanie, paginile fără niciun semn al pluginului.
 * C1. Pagina campaniei fără antetul și subsolul site-ului (campanii/0056): implicit bifată în editor; fără bifă, ca înainte; cu
 *    bifa, fără antet, subsol, sertarul „Oferte” și butonul coșului plutitor, cu bara „Înapoi” (închisă), bannerul de cookie-uri și
 *    GTM-ul; codul {{campanie_produse}} (cardurile, prețul redus, „Text sub preț”, „Adaugă în coș” = formularul cardului din
 *    magazin, epuizat, ascuns, cardurile închise), gol la adresa proprie a paginii și fără produse.
 * A2. Oferta campaniei fără server: perechile, grupele, procentul, prețul fix, rotunjirea la cent, invariante pe coșuri inventate.
 * F. Oferta campaniei: editorul (salvarea, refuzurile), coșul (îndemnul, perechile, cantitatea, TVA-ul, cuponul doar pe celelalte
 *    produse, un produs redus între timp rămas în ofertă, celelalte tipuri, fără ofertă = campania oprită), comanda și liniile ei,
 *    emailul, Admin → Comenzi, payload-ul ERP.
 * F2. Reducerile adunate (decizia proprietarului din 10 nov): oferta peste prețul redus, peste o perioadă de reducere în curs și
 *    peste lotul BBD ales (−20%, rotunjirea la cent, 1+1 −50% și 3 la preț de 2 cu prețuri reduse și întregi amestecate),
 *    cuponul tot doar pe produsele din afara ofertei, prețul de campanie (la salvare sub prețul de acum; în coș doar sub prețul
 *    liniei), cardurile {{campanie_produse}} și pagina produsului (de la prețul redus; cu loturi BBD de ales, doar eticheta),
 *    comanda (prețul de dinainte de ofertă = cel redus) și ERP-ul.
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
use App\Support\OfertaCampanie;
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
    // Decizia proprietarului (5 nov, a doua): la fiecare intrare din afară, și după ce a văzut-o (un cookie vechi nu contează).
    'de pe Google, cu un cookie rămas de la versiunea veche' => [$om, [Campanii::COOKIE => '7'], [], true],
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

// ── A2. Oferta campaniei: calculul, fără server (OfertaCampanie) ───────────────────────────────────────────────────────
$scrie('');
$scrie('== A2. Oferta campaniei: perechile și rotunjirea, fără server');
$oferta = static fn (string $tip, int $n = 2, int $m = 1, float $procent = 50, array $preturi = [1 => null, 2 => null, 3 => null, 4 => null])
    => OfertaCampanie::dinCampanie(['id' => 7, 'nume' => 'Black Weekends', 'oferta_tip' => $tip, 'oferta_n' => $n, 'oferta_m' => $m,
        'oferta_procent' => $procent], $preturi);
$linie = static fn (string $cheie, int $pret, int $cantitate, int $produs = 1): array => ['cheie' => $cheie, 'produs' => $produs, 'pret' => $pret, 'cantitate' => $cantitate];
$calc = static fn (?array $o, array $linii): array => OfertaCampanie::calculeaza((array) $o, $linii);
$unuPlusUnu = $oferta('al_n');
$cazuriOferta = [
    // [eticheta, oferta, linii, reducerile așteptate (cheie → cenți), unități, lipsesc]
    ['1+1 −50%: o bucată, nicio reducere, îndemnul', $unuPlusUnu, [$linie('a', 1000, 1)], [], 1, 1],
    ['1+1 −50%: 2 × 10,00 → 5,00', $unuPlusUnu, [$linie('a', 1000, 2)], ['a' => 500], 2, 0],
    ['1+1 −50%: 3 × 10,00 (impar) → 5,00, a treia la preț întreg', $unuPlusUnu, [$linie('a', 1000, 3)], ['a' => 500], 3, 0],
    ['1+1 −50%: 4 × 10,00 → 10,00', $unuPlusUnu, [$linie('a', 1000, 4)], ['a' => 1000], 4, 0],
    ['1+1 −50%: prețuri amestecate, cel mai ieftin din pereche (19,99 + 10,00 → 5,00 pe 10,00)', $unuPlusUnu,
        [$linie('a', 1999, 1), $linie('b', 1000, 1, 2)], ['b' => 500], 2, 0],
    ['1+1 −50%: 45,99 + 79,50 + 14,50 → perechea (79,50, 45,99): 23,00 (22,995 rotunjit în sus); 14,50 rămâne', $unuPlusUnu,
        [$linie('a', 4599, 1), $linie('b', 7950, 1, 2), $linie('c', 1450, 1, 3)], ['a' => 2300], 3, 0],
    ['1+1 −50%: ordinea prețurilor, nu a coșului (30, 10, 20, 40 → perechile (40, 30) și (20, 10))', $unuPlusUnu,
        [$linie('a', 3000, 1), $linie('b', 1000, 1, 2), $linie('c', 2000, 1, 3), $linie('d', 4000, 1, 4)], ['a' => 1500, 'b' => 500], 4, 0],
    ['1+1 −50%: la preț egal, a doua bucată din pereche e a liniei de mai jos din coș', $unuPlusUnu,
        [$linie('a', 1000, 1), $linie('b', 1000, 1, 2)], ['b' => 500], 2, 0],
    ['1+1 −50%: două linii ale aceluiași produs (2 + 1 bucăți, 12,00) → o pereche', $unuPlusUnu,
        [$linie('a', 1200, 2), $linie('b', 1200, 1)], ['a' => 600], 3, 0],
    ['1+1 −50%: 9,99 la jumătate = 4,995 → 5,00 (jumătatea în sus)', $unuPlusUnu, [$linie('a', 999, 2)], ['a' => 500], 2, 0],
    ['Al 3-lea la −30%: 5 × 10,00 → o grupă, 3,00; două bucăți rămân', $oferta('al_n', 3, 1, 30), [$linie('a', 1000, 5)], ['a' => 300], 5, 0],
    ['Al 3-lea la −30%: 2 bucăți, mai lipsește una', $oferta('al_n', 3, 1, 30), [$linie('a', 1000, 2)], [], 2, 1],
    ['Al 2-lea la −33,33%: 10,00 → 3,33', $oferta('al_n', 2, 1, 33.33), [$linie('a', 1000, 2)], ['a' => 333], 2, 0],
    ['3 la preț de 2: 30 + 20 + 10 → cea de 10 gratuită', $oferta('n_la_m', 3, 2), [$linie('a', 3000, 1), $linie('b', 2000, 1, 2), $linie('c', 1000, 1, 3)],
        ['c' => 1000], 3, 0],
    ['3 la preț de 2: 6 × 7,49 → două gratuite (14,98)', $oferta('n_la_m', 3, 2), [$linie('a', 749, 6)], ['a' => 1498], 6, 0],
    ['4 la preț de 2: 40, 30, 20, 10, 5 → 20 și 10 gratuite, 5 rămâne', $oferta('n_la_m', 4, 2),
        [$linie('a', 4000, 1), $linie('b', 3000, 1, 2), $linie('c', 2000, 1, 3), $linie('d', 1000, 1, 4), $linie('e', 500, 1, 1)], ['c' => 2000, 'd' => 1000], 5, 0],
    ['−20%: 3 × 9,99 = 29,97 → 5,99 (5,994 rotunjit)', $oferta('procent', 2, 1, 20), [$linie('a', 999, 3)], ['a' => 599], 3, 0],
    ['−20%: pe fiecare linie, fără îndemn', $oferta('procent', 2, 1, 20), [$linie('a', 1000, 1), $linie('b', 2550, 2, 2)], ['a' => 200, 'b' => 1020], 3, 0],
    ['preț de campanie: 10,00 → 7,99 pe 2 bucăți (4,02); fără preț sau cu un preț nu mai mic, nimic', $oferta('pret_fix', 2, 1, 0,
        [1 => 799, 2 => 1500, 3 => null]), [$linie('a', 1000, 2, 1), $linie('b', 1500, 1, 2), $linie('c', 1000, 3, 3)], ['a' => 402], 6, 0],
];
$gresiteOferta = [];
foreach ($cazuriOferta as [$eticheta, $o, $linii, $reduceri, $unitati, $lipsesc]) {
    $r = $calc($o, $linii);
    if ($r['reduceri'] !== $reduceri || $r['unitati'] !== $unitati || $r['lipsesc'] !== $lipsesc || $r['total'] !== array_sum($reduceri)) {
        $gresiteOferta[] = $eticheta . ': ' . json_encode($r);
    }
}
$verifica('calculul ofertei pe ' . count($cazuriOferta) . ' cazuri (1+1, al N-lea, N la preț de M, procent, preț de campanie; impare, linii ale aceluiași produs, rotunjirea)',
    $gresiteOferta === [], implode(' | ', $gresiteOferta));
// Invariante pe coșuri inventate (aceeași sămânță la fiecare rulare): reducerea unei linii nu trece de linie, totalul e suma
// liniilor, ordinea liniilor nu schimbă nimic când prețurile diferă, iar o bucată în plus nu scade reducerea (cantitatea crește).
mt_srand(20261105);
$invariante = [];
foreach ([$unuPlusUnu, $oferta('al_n', 3, 1, 25), $oferta('n_la_m', 3, 2), $oferta('procent', 2, 1, 15), $oferta('pret_fix', 2, 1, 0, [1 => 500, 2 => 900, 3 => 100, 4 => 2000])] as $o) {
    for ($k = 0; $k < 300; $k++) {
        $linii = [];
        $preturiFolosite = [];
        foreach (range(1, mt_rand(1, 4)) as $nr) {
            do {
                $pret = mt_rand(100, 30000);
            } while (isset($preturiFolosite[$pret]));
            $preturiFolosite[$pret] = true;
            $linii[] = $linie('l' . $nr, $pret, mt_rand(1, 5), $nr);
        }
        $r = $calc($o, $linii);
        foreach ($linii as $l) {
            if (($r['reduceri'][$l['cheie']] ?? 0) > $l['pret'] * $l['cantitate']) {
                $invariante[] = 'reducere peste linie ' . json_encode($linii);
            }
        }
        $amestecate = $linii;
        shuffle($amestecate);
        $r2 = $calc($o, $amestecate);
        ksort($r['reduceri']);
        ksort($r2['reduceri']);
        if ($r2['reduceri'] !== $r['reduceri'] || $r['total'] !== array_sum($r['reduceri'])) {
            $invariante[] = 'ordinea liniilor ' . json_encode($linii);
        }
        $maiMult = $linii;
        $maiMult[0]['cantitate']++;
        if ($calc($o, $maiMult)['total'] < $r['total']) {
            $invariante[] = 'o bucată în plus a scăzut reducerea ' . json_encode($linii);
        }
    }
}
$verifica('invariantele pe 1.500 de coșuri inventate: reducerea nu trece de linie, totalul = suma liniilor, ordinea liniilor nu contează, o bucată în plus nu scade reducerea',
    $invariante === [], implode(' | ', array_slice($invariante, 0, 3)));
$verifica('etichetele: „1+1 −50%”, „Al 3-lea la −30%”, „3 la preț de 2”, „−20%”, „Preț de campanie”; textul sumarului',
    $unuPlusUnu['eticheta'] === '1+1 −50%' && $oferta('al_n', 3, 1, 30)['eticheta'] === 'Al 3-lea la −30%'
    && $oferta('n_la_m', 3, 2)['eticheta'] === '3 la preț de 2' && $oferta('procent', 2, 1, 20)['eticheta'] === '−20%'
    && $oferta('pret_fix')['eticheta'] === 'Preț de campanie' && OfertaCampanie::textSumar($unuPlusUnu) === 'Black Weekends: 1+1 −50%'
    && OfertaCampanie::indiciu(1) === 'Mai adaugă un produs din ofertă ca să primești reducerea' && OfertaCampanie::indiciu(0) === '');
$verifica('setările greșite nu fac o ofertă (N 1, M ≥ N, 0%, 100% la procent, tip necunoscut, fără produse)',
    $oferta('al_n', 1) === null && $oferta('n_la_m', 3, 3) === null && $oferta('al_n', 2, 1, 0) === null && $oferta('procent', 2, 1, 100) === null
    && $oferta('altceva') === null && OfertaCampanie::dinCampanie(['oferta_tip' => 'al_n', 'oferta_n' => 2, 'oferta_procent' => 50], []) === null);

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
// Produse fără reducere proprie (prețul redus, perioadele, BBD), ca sumele de bază să pornească de la prețul întreg; reducerile
// adunate (un produs redus, o perioadă, un lot BBD, cu oferta peste ele) au cazurile lor în F.
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
// Un produs care are deja o reducere proprie (prețul redus din datele demo) se poate alege (decizia proprietarului din 10 nov:
// oferta campaniei se adaugă peste ea): bifa lui e ca a oricărui produs, cu nota reducerii; salvarea îl primește.
$pretRedusDemo = number_format((float) $unu('SELECT sale_price FROM products WHERE id = :id', ['id' => $idCuReducere]), 2);
$pretDemo = number_format((float) $unu('SELECT price FROM products WHERE id = :id', ['id' => $idCuReducere]), 2);
$verifica('editorul: un produs cu preț redus se poate alege (bifa nu e dezactivată), cu „are deja o reducere: preț redus (…); oferta campaniei se adaugă peste ea”',
    $idCuReducere > 0 && str_contains($formular['corp'], 'name="produse[]" value="' . $idCuReducere . '">')
    && preg_match('#name="produse\[\]" value="\d+"[^>]*disabled#', $formular['corp']) !== 1
    && str_contains($formular['corp'], 'are deja o reducere: preț redus (' . $pretRedusDemo . ' lei în loc de ' . $pretDemo
        . ' lei); oferta campaniei se adaugă peste ea') && !str_contains($formular['corp'], 'scos din campanie la salvare')
    && str_contains($formular['corp'], $pretRedusDemo . ' lei →'), (string) $idCuReducere);
$r = $cerereCorp('/admin/campanii/salveaza', http_build_query(['nume' => 'Cu reducere', 'slug' => 'cu-reducere', 'pornita' => '0'] + $campDate)
    . '&' . rawurlencode('produse[]') . '=' . $idCuReducere);
$idCuReducereCampanie = (int) $unu("SELECT id FROM campanii WHERE slug = 'cu-reducere'");
$verifica('salvarea cu un produs care are deja o reducere: salvată (302), produsul în campanie', $r['cod'] === 302 && $idCuReducereCampanie > 0
    && $unu('SELECT GROUP_CONCAT(product_id) FROM campanii_produse WHERE campanie_id = :id', ['id' => $idCuReducereCampanie]) === (string) $idCuReducere
    && str_contains($cerere('GET', '/admin/campanii/' . $idCuReducereCampanie, $jarAdmin)['corp'], 'name="produse[]" value="' . $idCuReducere . '" checked>'),
    $r['cod'] . ' ' . $r['location']);
$db->prepare('DELETE FROM campanii_produse WHERE campanie_id = :id')->execute(['id' => $idCuReducereCampanie]);
$db->prepare('DELETE FROM campanii WHERE id = :id')->execute(['id' => $idCuReducereCampanie]);
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
$verifica('trimiterea nu mai pune niciun cookie al campaniei; răspunsul fără cache', !str_contains($cookie, Campanii::COOKIE)
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
$r = $cerere('GET', $tinta, null, [], ['Referer: ' . $baza . '/']);
$verifica('din site (Referer al site-ului): pagina cerută, cu insigna produsului și bannerul (lipit, cu linkul lui, fontul Rubik)', $r['cod'] === 200
    && str_contains($r['corp'], 'campanie-insigna--pagina') && str_contains($r['corp'], 'data-campanie-banner data-lipit="1"')
    && str_contains($r['corp'], 'href="/magazin" style="color:inherit') && str_contains($r['corp'], '/assets/vendor/fonturi/rubik/rubik.css')
    && str_contains($r['corp'], 'Reduceri demo de -20% până duminică'), (string) $r['cod']);
// Bannerul fără link scris: duce la pagina campaniei, cu întoarcerea la pagina curentă (singurul drum spre ea în aceeași vizită).
$db->prepare("UPDATE campanii SET banner_link = '' WHERE id = :id")->execute(['id' => $idCampanie]);
ResponseCache::purgePageCache();
$r = $cerere('GET', '/magazin?sort=alpha_asc', null, [], ['Referer: ' . $baza . '/']);
$linkBanner = '/campanie/saptamana-demo?inapoi=' . rawurlencode('/magazin?sort=alpha_asc');
$verifica('bannerul fără link scris: duce la pagina campaniei, cu „inapoi” = pagina curentă', $r['cod'] === 200
    && str_contains($r['corp'], 'href="' . htmlspecialchars($linkBanner, ENT_QUOTES) . '" style="color:inherit'), (string) $r['cod']);
$r = $cerere('GET', $linkBanner, null, [], ['Referer: ' . $baza . '/magazin?sort=alpha_asc']);
$verifica('clicul pe banner (vizitatorul a văzut deja campania): pagina campaniei, cu butonul înapoi la pagina de pe care a venit', $r['cod'] === 200
    && str_contains($r['corp'], 'class="campanie-inapoi__buton" href="/magazin?sort=alpha_asc"'), (string) $r['cod']);
$db->prepare("UPDATE campanii SET pagina_la_intrare = 0 WHERE id = :id")->execute(['id' => $idCampanie]);
ResponseCache::purgePageCache();
$r = $cerere('GET', '/magazin', null, [], ['Referer: ' . $baza . '/']);
$verifica('bannerul fără link, cu pagina campaniei oprită: fără link', $r['cod'] === 200 && str_contains($r['corp'], 'data-campanie-banner')
    && !preg_match('#campanie-banner__text[^>]*><a #', $r['corp']), (string) $r['cod']);
$db->prepare("UPDATE campanii SET pagina_la_intrare = 1, banner_link = '/magazin' WHERE id = :id")->execute(['id' => $idCampanie]);
ResponseCache::purgePageCache();
$r = $cerere('GET', '/produs/' . $slugInafara, null, [], ['Referer: ' . $baza . '/']);
$verifica('un produs care nu e în campanie: fără insigna produsului (bannerul da)', $r['cod'] === 200 && !str_contains($r['corp'], 'campanie-insigna--pagina')
    && str_contains($r['corp'], 'data-campanie-banner'), (string) $r['cod']);
$r = $cerere('GET', '/magazin', null, [], ['Referer: ' . $baza . '/']);
$verifica('magazinul: insigna pe cardurile produselor din campanie', $r['cod'] === 200 && substr_count($r['corp'], 'campanie-insigna--card') >= 1
    && substr_count($r['corp'], 'campanie-insigna--card') <= count($produseCampanie), (string) substr_count($r['corp'], 'campanie-insigna--card'));
$acasa = $cerere('GET', '/', null, [], ['Referer: ' . $baza . '/']);
$verifica('prima pagină: insigna în grila {{featured_products}} (cel mai nou produs e în campanie)', $acasa['cod'] === 200
    && str_contains($acasa['corp'], 'campanie-insigna--acasa'), (string) $acasa['cod']);
$similare = 0;
$similareJson = 0;
$slugCuSimilare = '';
foreach ($produseCampanie as $idProdus) {
    $slugProdus = $unu('SELECT slug FROM products WHERE id = :id', ['id' => $idProdus]);
    $corp = $cerere('GET', '/produs/' . rawurlencode($slugProdus), null, [], ['Referer: ' . $baza . '/'])['corp'];
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
    $r = $cerere('GET', '/categorie/' . rawurlencode($categorie), null, [], ['Referer: ' . $baza . '/']);
    $verifica('pagina categoriei: insigna pe card', $r['cod'] === 200 && str_contains($r['corp'], 'campanie-insigna--card'), $categorie . ' ' . $r['cod']);
}

$faraTrimitere = [];
$cazuriSite = [
    'Googlebot pe un produs' => ['GET', $tinta, ['User-Agent: Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']],
    'navigarea din site' => ['GET', '/magazin', ['Referer: ' . $baza . '/']],
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
// La fiecare intrare din afară (decizia proprietarului din 5 nov, a doua): după ce a văzut pagina și a închis fila, un om care
// vine iar de pe Google (sau fără Referer) e trimis din nou; un clic din site (Referer de pe site) nu.
$din1 = $cerere('GET', $tinta, null, [], ['Referer: https://www.google.com/', 'Cookie: ' . Campanii::COOKIE . '=' . $idCampanie]);
$din2 = $cerere('GET', '/blog', null, [], ['Cookie: ' . Campanii::COOKIE . '=' . $idCampanie]);
$dinSite = $cerere('GET', $tinta, null, [], ['Referer: ' . $baza . '/campanie/saptamana-demo', 'Cookie: ' . Campanii::COOKIE . '=' . $idCampanie]);
$verifica('a doua intrare din afară (Google, fără Referer), și cu cookie-ul vechi: trimis din nou; din site (Referer al site-ului): nu',
    $din1['cod'] === 302 && str_starts_with((string) ($din1['antete']['location'] ?? ''), '/campanie/saptamana-demo')
    && $din2['cod'] === 302 && $dinSite['cod'] === 200 && !isset($dinSite['antete']['location']),
    $din1['cod'] . ' / ' . $din2['cod'] . ' / ' . $dinSite['cod']);

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
$cuCookie = ['Referer: ' . $baza . '/'];
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
$cuAcord = $cerere('GET', $adresaCampanie, null, [], ['Cookie: ' . \App\Support\CookieConsent::nume() . '=all']);
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

// ── F. Oferta campaniei: editorul, coșul, comanda, emailul, adminul, ERP-ul ───────────────────────────────────────────
$scrie('');
$scrie('== F. Oferta campaniei: editorul, coșul, comanda, emailul, adminul și ERP-ul');
$campDateF = ['_csrf' => $csrf, 'id' => (string) $idCampanie] + $campDate;
$corpProduse = '';
foreach ($produseCampanie as $idProdus) {
    $corpProduse .= '&' . rawurlencode('produse[]') . '=' . $idProdus;
}
$editorF = $cerere('GET', '/admin/campanii/' . $idCampanie, $jarAdmin);
$verifica('editorul: secțiunea „Oferta campaniei” cu cele patru tipuri și „nicio ofertă” aleasă', str_contains($editorF['corp'], 'name="oferta_tip"')
    && str_contains($editorF['corp'], '<option value="" selected>— nicio ofertă (doar insigna) —</option>')
    && substr_count($editorF['corp'], 'name="pret_campanie[') >= count($produseCampanie));
$r = $cerereCorp('/admin/campanii/salveaza', http_build_query(['oferta_tip' => 'n_la_m', 'oferta_n' => '3', 'oferta_m' => '3'] + $campDateF) . $corpProduse);
$r2 = $cerereCorp('/admin/campanii/salveaza', http_build_query(['oferta_tip' => 'pret_fix', 'pret_campanie' => [$produseCampanie[0] => '99999']] + $campDateF) . $corpProduse);
$verifica('oferta greșită refuzată la salvare: „3 la preț de 3” și un preț de campanie nu mai mic decât prețul (422)', $r['cod'] === 422 && $r2['cod'] === 422
    && $unu('SELECT COALESCE(oferta_tip, "") FROM campanii WHERE id = :id', ['id' => $idCampanie]) === '', $r['cod'] . ' / ' . $r2['cod']);
$r = $cerereCorp('/admin/campanii/salveaza', http_build_query(['oferta_tip' => 'al_n', 'oferta_n' => '2', 'oferta_procent' => '50'] + $campDateF) . $corpProduse);
$verifica('oferta „1+1 −50%” salvată din editor', $r['cod'] === 302
    && $unu('SELECT CONCAT(oferta_tip, ":", oferta_n, ":", oferta_procent) FROM campanii WHERE id = :id', ['id' => $idCampanie]) === 'al_n:2:50.00', $r['cod'] . ' ' . $r['location']);
$numeCampanie = $unu('SELECT nume FROM campanii WHERE id = :id', ['id' => $idCampanie]);
$centi = static fn (int $id): int => (int) round((float) $unu('SELECT price FROM products WHERE id = :id', ['id' => $id]) * 100);
[$pA, $pB, $pC] = [$produseCampanie[0], $produseCampanie[1], $produseCampanie[2]];
$idAfara = (int) $unu('SELECT id FROM products WHERE deleted_at IS NULL AND is_active = 1 AND slug <> ""' . $faraReducere . ' AND id NOT IN ('
    . implode(',', $produseCampanie) . ') ORDER BY id LIMIT 1');
// Prețul ofertei pe cardurile {{campanie_produse}} și pe pagina produsului (aceeași rotunjire ca în coș); insigna deasupra titlului.
$pretA = $centi($pA);
$bani = static fn (int $c): string => number_format($c / 100, 2);
$redusA = $pretA - OfertaCampanie::procentDin($pretA, 5000);
$db->prepare('UPDATE pages SET html_content = :h WHERE id = :id')->execute(['id' => $idPagina, 'h' => '<section>{{campanie_produse}}</section>']);
$paginaOferta = $cerere('GET', '/campanie/saptamana-demo?inapoi=%2F')['corp'];
$verifica('cardul din {{campanie_produse}}: „P lei + ~~P~~ P/2 lei” pentru 1+1 −50% (jumătatea rotunjită ca în coș)',
    str_contains($paginaOferta, '<p class="campanie-grila__oferta">' . $bani($pretA) . ' lei + <del>' . $bani($pretA) . '</del> <strong>' . $bani($redusA) . ' lei</strong></p>')
    && str_contains($paginaOferta, '.campanie-grila__oferta del{'), $bani($pretA) . ' → ' . $bani($redusA));
$db->prepare('UPDATE pages SET html_content = :h WHERE id = :id')->execute(['id' => $idPagina,
    'h' => '<section style="padding:32px 16px;text-align:center"><h1>Săptămâna demo</h1><p>Reduceri inventate la produsele demo.</p></section>']);
$slugA = $unu('SELECT slug FROM products WHERE id = :id', ['id' => $pA]);
$paginaProdus = $cerere('GET', '/produs/' . rawurlencode($slugA), null, [], ['Referer: ' . $baza . '/'])['corp'];
$cuSablon = $unu('SELECT COALESCE(product_template_id, 0) FROM products WHERE id = :id', ['id' => $pA]) !== '0';
$verifica('pagina produsului: rândul ofertei (eticheta, ~~P~~ P/2 cu <s>/<b>), insigna chiar deasupra titlului' . ($cuSablon ? ' (în șablon)' : ''),
    str_contains($paginaProdus, 'data-campanie-oferta-pret') && str_contains($paginaProdus, $bani($pretA) . ' lei + <s style="color:#c0392b;font-weight:500;">'
        . $bani($pretA) . '</s> <b style="font-weight:800;">' . $bani($redusA) . ' lei</b>')
    && preg_match('#campanie-insigna--pagina"[^>]*>[^<]*</span></p>\s*<h1#u', $paginaProdus) === 1, ($cuSablon ? 'cu șablon' : 'fără șablon')
    . (str_contains($paginaProdus, 'data-campanie-oferta-pret') ? ', rândul da' : ', rândul nu'));
$slugAfara = $unu('SELECT slug FROM products WHERE id = :id', ['id' => $idCuReducere]);
$paginaRedusa = $cerere('GET', '/produs/' . rawurlencode($slugAfara), null, [], ['Referer: ' . $baza . '/'])['corp'];
$verifica('pagina unui produs din afara ofertei (cu reducere proprie): fără rândul ofertei', !str_contains($paginaRedusa, 'data-campanie-oferta-pret'));
$ofertaDin = static fn (string $tip, int $n, int $m, float $p, array $preturi = []): array => (array) OfertaCampanie::dinCampanie(['id' => 1,
    'nume' => 'X', 'oferta_tip' => $tip, 'oferta_n' => $n, 'oferta_m' => $m, 'oferta_procent' => $p], $preturi + [5 => null]);
$verifica('rândul ofertei pe tipuri (7167 cenți): Al 3-lea, 3 la preț de 2, −10%, preț de campanie (doar sub preț)',
    Campanii::randOfertaCard($ofertaDin('al_n', 3, 1, 30), 5, 7167) === 'Al 3-lea: <del>71.67</del> <strong>50.17 lei</strong>'
    && Campanii::randOfertaCard($ofertaDin('n_la_m', 3, 2, 0), 5, 7167) === '3 la preț de 2: <del>215.01</del> <strong>143.34 lei</strong>'
    && Campanii::randOfertaCard($ofertaDin('procent', 2, 1, 10), 5, 7167) === '<del>71.67</del> <strong>64.50 lei</strong>'
    && Campanii::randOfertaCard($ofertaDin('pret_fix', 2, 1, 0, [5 => 5999]), 5, 7167) === '<del>71.67</del> <strong>59.99 lei</strong>'
    && Campanii::randOfertaCard($ofertaDin('pret_fix', 2, 1, 0, [5 => 9999]), 5, 7167) === ''
    && Campanii::randOfertaCard($ofertaDin('al_n', 2, 1, 50), 5, 7167) === '71.67 lei + <del>71.67</del> <strong>35.83 lei</strong>'
    && Campanii::randOfertaCard($ofertaDin('al_n', 2, 1, 50), 6, 7167) === '');
$jarF = $dosarDev . '/test-campanii-oferta-' . getmypid() . '.txt';
$sumar = static function () use ($cerere, $jarF): array {
    return (array) json_decode($cerere('GET', '/api/cart/summary', $jarF, [], ['Accept: application/json'])['corp'], true);
};
$liniePe = static function (array $s, int $idProdus): array {
    foreach ((array) ($s['lines'] ?? []) as $l) {
        if ((int) ($l['product_id'] ?? 0) === $idProdus) {
            return $l;
        }
    }
    return [];
};
// Oracolul de aici, scris altfel: bucățile în ordinea prețului, a doua din fiecare pereche la jumătate (în sus, la cent).
$asteptat11 = static function (array $bucati): array {
    rsort($bucati);
    $reduceri = [];
    foreach ($bucati as $i => $pret) {
        if ($i % 2 === 1) {
            $reduceri[] = intdiv($pret + 1, 2);
        }
    }
    return $reduceri;
};
@unlink($jarF);
$cerere('POST', '/cos/adauga/' . $pA, $jarF, ['quantity' => '1']);
$s = $sumar();
$verifica('o singură bucată din ofertă: nicio reducere, îndemnul „Mai adaugă un produs din ofertă ca să primești reducerea”',
    (float) ($s['campanie_oferta']['reducere'] ?? -1) === 0.0 && ($s['campanie_oferta']['indiciu'] ?? '') === OfertaCampanie::INDICIU
    && !isset($liniePe($s, $pA)['campanie_reducere']), json_encode($s['campanie_oferta'] ?? null, JSON_UNESCAPED_UNICODE));
$cerere('POST', '/cos/adauga/' . $pB, $jarF, ['quantity' => '1']);
$s = $sumar();
$redAB = array_sum($asteptat11([$centi($pA), $centi($pB)]));
$ieftin = $centi($pA) <= $centi($pB) ? $pA : $pB;
$liniiSuma = array_sum(array_map(static fn (array $l): float => (float) $l['line_total'], (array) $s['lines']));
$verifica('1+1 −50% pe două produse: jumătate din cel mai ieftin, pe linia lui (prețul de dinainte păstrat), subtotalul = liniile reduse',
    (int) round((float) ($s['campanie_oferta']['reducere'] ?? 0) * 100) === $redAB
    && (int) round((float) ($liniePe($s, $ieftin)['campanie_reducere'] ?? 0) * 100) === $redAB
    && (int) round((float) ($liniePe($s, $ieftin)['campanie_total_inainte'] ?? 0) * 100) === $centi($ieftin)
    && abs($liniiSuma - (float) $s['subtotal']) < 0.001 && ($s['campanie_oferta']['indiciu'] ?? 'x') === ''
    && ($s['campanie_oferta']['text'] ?? '') === $numeCampanie . ': 1+1 −50%', json_encode($s, JSON_UNESCAPED_UNICODE));
$cerere('POST', '/cos/adauga/' . $pA, $jarF, ['quantity' => '2']);
$s = $sumar();
$red3 = array_sum($asteptat11([$centi($pA), $centi($pA), $centi($pA), $centi($pB)]));
$verifica('cantitatea crește (3 × A + 1 × B): două perechi, aceleași reguli; totalul = subtotal + transport', (int) round((float) $s['campanie_oferta']['reducere'] * 100) === $red3
    && abs((float) $s['total'] - ((float) $s['subtotal'] + (float) $s['shipping'] - (float) $s['discount'] - (float) $s['points_discount'])) < 0.001,
    json_encode($s['campanie_oferta'], JSON_UNESCAPED_UNICODE) . ' așteptat ' . $red3);
// TVA-ul liniilor din prețul redus: subtotalul fără TVA + TVA = subtotalul (prețuri cu TVA inclus în datele demo).
$verifica('TVA-ul pe prețurile reduse: subtotal fără TVA + TVA = subtotal', abs((float) $s['subtotal_without_vat'] + (float) $s['vat_total'] - (float) $s['subtotal']) < 0.01,
    $s['subtotal_without_vat'] . ' + ' . $s['vat_total'] . ' vs ' . $s['subtotal']);
// Un cupon nu se aplică liniilor reduse de ofertă (doar celorlalte, ca la un cupon „fără cumulare”), iar coșul spune asta.
$cerere('POST', '/cos/adauga/' . $idAfara, $jarF, ['quantity' => '1']);
$cerere('POST', '/cos/cupon', $jarF, ['coupon_code' => 'DEMO10']);
$s = $sumar();
$baseCupon = (float) ($liniePe($s, $idAfara)['line_total'] ?? 0) + (float) ($liniePe($s, $pC)['line_total'] ?? 0);
$verifica('cuponul DEMO10 (10%) doar pe produsele din afara ofertei; „Cuponul nu se aplică produselor din oferta campaniei”',
    ($s['coupon']['code'] ?? '') === 'DEMO10' && abs((float) $s['discount'] - round($baseCupon * 0.10, 2)) < 0.006
    && !empty($s['campanie_oferta']['cupon_exclus']) && (float) ($liniePe($s, $ieftin)['coupon_discount'] ?? 0) === 0.0,
    $s['discount'] . ' vs ' . round($baseCupon * 0.10, 2));
$cos = $cerere('GET', '/cos', $jarF);
$verifica('pagina coșului: rândul ofertei, reducerea pe linie și nota cuponului', str_contains($cos['corp'], 'data-campanie-sumar')
    && str_contains($cos['corp'], htmlspecialchars($numeCampanie . ': 1+1 −50%', ENT_QUOTES)) && str_contains($cos['corp'], 'data-campanie-linie')
    && str_contains($cos['corp'], 'data-campanie-cupon'));
// Comanda: oferta înghețată pe comandă și pe linii; totalul e al sumarului; emailul, adminul și ERP-ul o au.
$sumarComanda = $s;
$pagCheckout = $cerere('GET', '/checkout', $jarF)['corp'];
$verifica('checkout-ul: rândul ofertei și reducerea pe linie', str_contains($pagCheckout, 'data-campanie-sumar') && str_contains($pagCheckout, 'data-campanie-linie'));
preg_match('/name="checkout_form_token" value="([^"]*)"/', $pagCheckout, $tokenF);
preg_match('/name="checkout_form_rendered_at" value="([^"]*)"/', $pagCheckout, $randatF);
sleep(3);
$ultimaComanda = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM orders')->fetchColumn();
$mailuriInainte = glob($dosarDev . '/mail/*') ?: [];
$trimis = $cerere('POST', '/checkout', $jarF, [
    'checkout_form_token' => $tokenF[1] ?? '', 'checkout_form_rendered_at' => $randatF[1] ?? '',
    'billing_first_name' => 'Test', 'billing_last_name' => 'Oferta', 'billing_phone' => '0700000781',
    'billing_email' => 'oferta-campanie@exemplu.ro', 'billing_street' => 'Strada Exemplului', 'billing_street_no' => '11',
    'billing_city' => 'Cluj-Napoca', 'billing_county' => 'Cluj', 'billing_postcode' => '400001',
    'shipping_same_as_billing' => '1', 'payment_method' => 'cod', 'accept_terms' => '1',
]);
$comanda = $db->query('SELECT * FROM orders WHERE id > ' . $ultimaComanda . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$verifica('comanda plasată cu oferta: campania, textul ofertei și reducerea pe comandă; totalul și reducerea cuponului ale sumarului',
    $trimis['cod'] === 302 && is_array($comanda) && (int) $comanda['campanie_id'] === $idCampanie
    && $comanda['campanie_oferta'] === $numeCampanie . ': 1+1 −50%'
    && abs((float) $comanda['campanie_reducere'] - (float) $sumarComanda['campanie_oferta']['reducere']) < 0.001
    && abs((float) $comanda['total'] - (float) $sumarComanda['total']) < 0.001 && abs((float) $comanda['discount_total'] - (float) $sumarComanda['discount']) < 0.001
    && abs((float) $comanda['subtotal'] - (float) $sumarComanda['subtotal']) < 0.001,
    $trimis['cod'] . ' ' . ($trimis['antete']['location'] ?? '') . ' ' . json_encode($comanda ? array_intersect_key($comanda, array_flip(['campanie_id', 'campanie_oferta', 'campanie_reducere', 'total', 'discount_total'])) : null, JSON_UNESCAPED_UNICODE));
$idComanda = is_array($comanda) ? (int) $comanda['id'] : 0;
$liniiComanda = $db->query('SELECT * FROM order_items WHERE order_id = ' . $idComanda . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$sumaReduceriLinii = array_sum(array_map(static fn (array $l): float => (float) ($l['campanie_reducere'] ?? 0), $liniiComanda));
$liniaA = array_values(array_filter($liniiComanda, static fn (array $l): bool => (int) $l['product_id'] === $pA))[0] ?? [];
$verifica('liniile comenzii: reducerea pe linie (suma = reducerea comenzii), totalul liniei redus, prețul de dinainte; celelalte linii fără ofertă',
    $liniiComanda !== [] && abs($sumaReduceriLinii - (float) ($comanda['campanie_reducere'] ?? 0)) < 0.001
    && abs((float) ($liniaA['line_total'] ?? 0) - (float) $liniePe($sumarComanda, $pA)['line_total']) < 0.001
    && (int) round((float) ($liniaA['campanie_pret_initial'] ?? 0) * 100) === $centi($pA)
    && array_filter($liniiComanda, static fn (array $l): bool => (int) $l['product_id'] === $idAfara && $l['campanie_reducere'] !== null) === [],
    json_encode($liniiComanda, JSON_UNESCAPED_UNICODE));
$payload = is_array($comanda) ? \App\Support\ErpSync::buildPayload($db, $comanda) : [];
$sumaErp = 0.0;
$pretLiniaA = null;
foreach ((array) ($payload['linii'] ?? []) as $i => $l) {
    $sumaErp += round((float) $l['pretUnitar'] * (float) $l['cantitate'], 2);
    if ((int) ($liniiComanda[$i]['product_id'] ?? 0) === $pA) {
        $pretLiniaA = (float) $l['pretUnitar'];
    }
}
$verifica('ERP: prețul unitar al liniei reduse = totalul ei / cantitate (4 zecimale), suma liniilor = subtotalul comenzii, cuponul și totalul ale comenzii',
    $payload !== [] && abs($sumaErp - (float) $comanda['subtotal']) < 0.001
    && $pretLiniaA !== null && abs($pretLiniaA - round((float) $liniaA['line_total'] / (int) $liniaA['quantity'], 4)) < 0.00001
    && abs((float) $payload['discountCupon'] - (float) $comanda['discount_total']) < 0.001 && abs((float) $payload['total'] - (float) $comanda['total']) < 0.001
    && abs($sumaErp + (float) $payload['transport'] - (float) $payload['discountCupon'] - (float) $payload['discountPuncte'] - (float) $payload['total']) < 0.011,
    json_encode($payload['linii'] ?? null, JSON_UNESCAPED_UNICODE) . ' suma ' . $sumaErp);
$mailuriNoi = array_diff(glob($dosarDev . '/mail/*') ?: [], $mailuriInainte);
$textMailuri = '';
foreach ($mailuriNoi as $fisierMail) {
    $brut = (string) file_get_contents($fisierMail);
    $textMailuri .= quoted_printable_decode($brut) . base64_decode((string) preg_replace('/[^A-Za-z0-9+\/=]/', '', $brut), false);
}
$verifica('emailul comenzii: rândul ofertei („inclusă în prețuri”) și reducerea pe linie', $mailuriNoi !== []
    && str_contains($textMailuri, 'inclusă în prețuri') && str_contains($textMailuri, 'ofertă: −'), count($mailuriNoi) . ' emailuri: '
    . (str_contains($textMailuri, 'inclusă în prețuri') ? 'rândul da' : 'rândul nu') . ', ' . (str_contains($textMailuri, 'ofertă: −') ? 'linia da' : 'linia nu')
    . ' | ' . mb_substr((string) preg_replace('/\s+/', ' ', strip_tags($textMailuri)), 0, 900));
$adminComenzi = $cerere('GET', '/admin/orders', $jarAdmin);
$verifica('Admin → Comenzi: oferta în datele comenzii și ale liniei', $adminComenzi['cod'] === 200
    && str_contains($adminComenzi['corp'], 'campanie_reducere') && str_contains($adminComenzi['corp'], 'data-campanie-sumar'), $adminComenzi['cod'] . ' '
    . (str_contains($adminComenzi['corp'], 'campanie_reducere') ? 'date da' : 'date nu') . ', ' . (str_contains($adminComenzi['corp'], 'data-campanie-sumar') ? 'js da' : 'js nu'));
// Produsele unei comenzi cu oferta nu se editează din Admin → Comenzi (serverul refuză, editorul nu le arată).
$liniiInainteEditare = json_encode($liniiComanda);
$editare = $cerere('POST', '/admin/orders/' . $idComanda . '/items', $jarAdmin, ['_csrf' => $jeton($adminComenzi['corp']) ?: $csrf,
    'product_id' => [$pA], 'quantity' => ['1']]);
$raspunsEditare = (array) json_decode($editare['corp'], true);
$verifica('Admin → Comenzi: editarea produselor unei comenzi cu oferta e refuzată, liniile și comanda rămân; editorul arată de ce',
    ($raspunsEditare['ok'] ?? null) === false && ($raspunsEditare['error'] ?? '') === \App\Http\Controllers\AdminController::MESAJ_OFERTA_PRODUSE
    && json_encode($db->query('SELECT * FROM order_items WHERE order_id = ' . $idComanda . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: []) === $liniiInainteEditare
    && abs((float) $unu('SELECT total FROM orders WHERE id = :id', ['id' => $idComanda]) - (float) $comanda['total']) < 0.001
    && str_contains($adminComenzi['corp'], 'data-campanie-blocata'), $editare['cod'] . ' ' . mb_substr($editare['corp'], 0, 300));
if ($idComanda > 0) {
    foreach (['order_items', 'email_logs'] as $tabel) {
        $db->exec('DELETE FROM ' . $tabel . ' WHERE order_id = ' . $idComanda);
    }
    $db->exec('DELETE FROM orders WHERE id = ' . $idComanda);
}
array_map('unlink', $mailuriNoi);
// Un produs redus după ce a fost ales rămâne în ofertă, iar reducerile se adună: perechea pornește de la prețul lui redus.
@unlink($jarF);
$cerere('POST', '/cos/adauga/' . $pA, $jarF, ['quantity' => '1']);
$cerere('POST', '/cos/adauga/' . $pB, $jarF, ['quantity' => '1']);
$db->prepare('UPDATE products SET sale_price = ROUND(price * 0.9, 2) WHERE id = :id')->execute(['id' => $pB]);
$redusB = (int) round((float) $unu('SELECT sale_price FROM products WHERE id = :id', ['id' => $pB]) * 100);
$s = $sumar();
$redABRedus = array_sum($asteptat11([$centi($pA), $redusB]));
$ieftinRedus = $centi($pA) <= $redusB ? $pA : $pB;
$verifica('un produs al campaniei redus între timp rămâne în ofertă: 1+1 −50% de la prețul lui redus (' . $bani($redusB) . ' lei), pe cel mai ieftin',
    (int) round((float) ($s['campanie_oferta']['reducere'] ?? -1) * 100) === $redABRedus && ($s['campanie_oferta']['indiciu'] ?? 'x') === ''
    && (int) round((float) ($liniePe($s, $ieftinRedus)['campanie_reducere'] ?? 0) * 100) === $redABRedus
    && (int) round((float) ($liniePe($s, $pB)['campanie_total_inainte'] ?? $liniePe($s, $pB)['line_total'] ?? 0) * 100) === $redusB,
    json_encode($s['campanie_oferta'] ?? null, JSON_UNESCAPED_UNICODE) . ' așteptat ' . $redABRedus);
$db->prepare('UPDATE products SET sale_price = NULL WHERE id = :id')->execute(['id' => $pB]);
// Cuponul nu se aplică niciunei linii eligibile pentru ofertă, nici celei rămase fără pereche (A + B + C, C singură).
@unlink($jarF);
foreach ([$pA, $pB, $pC, $idAfara] as $idProdus) {
    $cerere('POST', '/cos/adauga/' . $idProdus, $jarF, ['quantity' => '1']);
}
$cerere('POST', '/cos/cupon', $jarF, ['coupon_code' => 'DEMO10']);
$s = $sumar();
$faraPereche = array_values(array_filter([$pA, $pB, $pC], static fn (int $id): bool => !isset($liniePe($s, $id)['campanie_reducere'])));
$faraCupon = array_filter([$pA, $pB, $pC], static fn (int $id): bool => (float) ($liniePe($s, $id)['coupon_discount'] ?? 0) === 0.0);
$verifica('cuponul nu se aplică niciunei linii a ofertei, nici celor nereduse în perechi: doar produsului din afara campaniei', count($faraPereche) >= 1
    && abs((float) $s['discount'] - round((float) $liniePe($s, $idAfara)['line_total'] * 0.10, 2)) < 0.006
    && count($faraCupon) === 3 && !empty($s['campanie_oferta']['cupon_exclus']),
    $s['discount'] . ' ' . json_encode($faraPereche));
// Fără alte produse decât cele ale ofertei, cuponul e refuzat cu mesajul lui (nu se atașează, deci nu se consumă).
$cerere('POST', '/cos/sterge/' . $idAfara, $jarF, []);
$s = $sumar();
$verifica('doar produse din ofertă în coș: cuponul nu se mai aplică (fără reducere, cu mesajul)', ($s['coupon'] ?? null) === null
    && (float) $s['discount'] === 0.0 && ($s['coupon_error'] ?? '') === \App\Support\CheckoutCalculator::MESAJ_CUPON_OFERTA,
    json_encode(['coupon' => $s['coupon'] ?? null, 'err' => $s['coupon_error'] ?? null], JSON_UNESCAPED_UNICODE));
$pagCheckout = $cerere('GET', '/checkout', $jarF)['corp'];
preg_match('/name="checkout_form_token" value="([^"]*)"/', $pagCheckout, $tokenF);
preg_match('/name="checkout_form_rendered_at" value="([^"]*)"/', $pagCheckout, $randatF);
// Pagina checkout-ului a scos deja cuponul (ca pentru orice cupon invalid); îl punem la loc direct în sesiune, ca la o
// trimitere venită fără ea (alt tab), și trimitem comanda.
$cerere('POST', '/cos/adauga/' . $idAfara, $jarF, ['quantity' => '1']);
$cerere('POST', '/cos/cupon', $jarF, ['coupon_code' => 'DEMO10']);
$cerere('POST', '/cos/sterge/' . $idAfara, $jarF, []);
sleep(3);
$ultimaComanda = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM orders')->fetchColumn();
$trimis = $cerere('POST', '/checkout', $jarF, [
    'checkout_form_token' => $tokenF[1] ?? '', 'checkout_form_rendered_at' => $randatF[1] ?? '',
    'billing_first_name' => 'Test', 'billing_last_name' => 'Oferta', 'billing_phone' => '0700000781',
    'billing_email' => 'oferta-campanie@exemplu.ro', 'billing_street' => 'Strada Exemplului', 'billing_street_no' => '11',
    'billing_city' => 'Cluj-Napoca', 'billing_county' => 'Cluj', 'billing_postcode' => '400001',
    'shipping_same_as_billing' => '1', 'payment_method' => 'cod', 'accept_terms' => '1',
]);
$comenziNoi = (int) $db->query('SELECT COUNT(*) FROM orders WHERE id > ' . $ultimaComanda)->fetchColumn();
$cosDupa = $cerere('GET', '/cos', $jarF)['corp'];
$verifica('checkout cu un cupon care se aplica doar produselor ofertei: comanda nu se plasează, înapoi în coș cu mesajul, cuponul scos',
    $trimis['cod'] === 302 && ($trimis['antete']['location'] ?? '') === '/cos' && $comenziNoi === 0
    && str_contains($cosDupa, htmlspecialchars(\App\Support\CheckoutCalculator::MESAJ_CUPON_OFERTA, ENT_QUOTES)) && ($sumar()['coupon'] ?? null) === null,
    $trimis['cod'] . ' ' . ($trimis['antete']['location'] ?? '') . ' comenzi noi: ' . $comenziNoi);
if ($comenziNoi > 0) {
    foreach ($db->query('SELECT id FROM orders WHERE id > ' . $ultimaComanda)->fetchAll(PDO::FETCH_COLUMN) as $idNou) {
        $db->exec('DELETE FROM order_items WHERE order_id = ' . (int) $idNou);
        $db->exec('DELETE FROM email_logs WHERE order_id = ' . (int) $idNou);
        $db->exec('DELETE FROM orders WHERE id = ' . (int) $idNou);
    }
}
array_map('unlink', array_diff(glob($dosarDev . '/mail/*') ?: [], $mailuriInainte));
@unlink($jarF);
$cerere('POST', '/cos/adauga/' . $pA, $jarF, ['quantity' => '1']);
$cerere('POST', '/cos/adauga/' . $pB, $jarF, ['quantity' => '1']);
// Emailul cu ambele coduri ale tabelului produselor ({{order_items_html}} și {{order_summary_rows}}): rândul ofertei o dată.
$aplicaSablon = new ReflectionMethod(\App\Support\OrderMailer::class, 'applyTemplate');
$randOferta = \App\Support\EmailAutomation::SEMN_OFERTA . '<tr><td>Oferta</td></tr>' . \App\Support\EmailAutomation::SEMN_OFERTA_SFARSIT;
$tabel = '<table><tr><td>Produs</td></tr>' . $randOferta . '</table>';
$dublu = $aplicaSablon->invoke(null, '<p>{{order_items_html}}</p><p>{{order_summary_rows}}</p>', ['{{order_items_html}}' => $tabel, '{{order_summary_rows}}' => $tabel]);
$simplu = $aplicaSablon->invoke(null, '<p>{{order_items_html}}</p>', ['{{order_items_html}}' => $tabel]);
$verifica('emailul: un șablon cu {{order_items_html}} și {{order_summary_rows}} arată rândul ofertei o singură dată; cu unul singur, rămâne',
    substr_count($dublu, '<td>Oferta</td>') === 1 && substr_count($dublu, '<td>Produs</td>') === 2 && substr_count($simplu, '<td>Oferta</td>') === 1
    && $aplicaSablon->invoke(null, 'a {{x}} b', ['{{x}}' => 'y']) === 'a y b');
// Celelalte tipuri, pe același coș (A + B + C, câte una).
$cerere('POST', '/cos/adauga/' . $pC, $jarF, ['quantity' => '1']);
$preturiABC = [$centi($pA), $centi($pB), $centi($pC)];
$tipuri = [
    ['n_la_m', 3, 2, 0, min($preturiABC), '3 la preț de 2'],
    ['procent', 0, 0, 10, array_sum(array_map(static fn (int $c): int => intdiv($c * 1000 + 5000, 10000), $preturiABC)), '−10%'],
];
foreach ($tipuri as [$tip, $n, $m, $procent, $asteptatCenti, $eticheta]) {
    $db->prepare('UPDATE campanii SET oferta_tip = :t, oferta_n = :n, oferta_m = :m, oferta_procent = :p WHERE id = :id')
        ->execute(['t' => $tip, 'n' => $n, 'm' => $m, 'p' => $procent, 'id' => $idCampanie]);
    $s = $sumar();
    $verifica('„' . $eticheta . '” pe A + B + C: ' . number_format($asteptatCenti / 100, 2) . ' lei', (int) round((float) ($s['campanie_oferta']['reducere'] ?? 0) * 100) === $asteptatCenti
        && ($s['campanie_oferta']['text'] ?? '') === $numeCampanie . ': ' . $eticheta, json_encode($s['campanie_oferta'] ?? null, JSON_UNESCAPED_UNICODE));
}
$db->prepare("UPDATE campanii SET oferta_tip = 'pret_fix' WHERE id = :id")->execute(['id' => $idCampanie]);
$db->prepare('UPDATE campanii_produse SET pret_campanie = ROUND((SELECT price FROM products WHERE id = :p) - 1, 2) WHERE campanie_id = :c AND product_id = :p2')
    ->execute(['p' => $pA, 'c' => $idCampanie, 'p2' => $pA]);
$s = $sumar();
$verifica('„Preț de campanie” (A cu 1 leu mai ieftin, B și C fără preț): 1,00 lei, doar pe A', (int) round((float) ($s['campanie_oferta']['reducere'] ?? 0) * 100) === 100
    && (int) round((float) ($liniePe($s, $pA)['campanie_reducere'] ?? 0) * 100) === 100 && !isset($liniePe($s, $pB)['campanie_reducere']),
    json_encode($s['campanie_oferta'] ?? null, JSON_UNESCAPED_UNICODE));
// Fără ofertă (doar insigna): sumarul coșului e exact cel cu campania oprită.
$db->prepare('UPDATE campanii SET oferta_tip = NULL WHERE id = :id')->execute(['id' => $idCampanie]);
$faraOferta = $cerere('GET', '/api/cart/summary', $jarF, [], ['Accept: application/json'])['corp'];
$db->prepare('UPDATE campanii SET pornita = 0 WHERE id = :id')->execute(['id' => $idCampanie]);
$oprita = $cerere('GET', '/api/cart/summary', $jarF, [], ['Accept: application/json'])['corp'];
$db->prepare('UPDATE campanii SET pornita = 1 WHERE id = :id')->execute(['id' => $idCampanie]);
$verifica('campania fără ofertă: sumarul coșului octet cu octet cel cu campania oprită (fără cheia ofertei)', $faraOferta === $oprita
    && !str_contains($faraOferta, 'campanie_oferta') && !str_contains($faraOferta, 'campanie_reducere'));
@unlink($jarF);
$db->prepare('UPDATE campanii_produse SET pret_campanie = NULL WHERE campanie_id = :c')->execute(['c' => $idCampanie]);
ResponseCache::purgePageCache();

// ── F2. Reducerile adunate (decizia proprietarului din 10 nov): oferta campaniei peste reducerea proprie a produsului ────────
$scrie('');
$scrie('== F2. Reducerile adunate: oferta campaniei peste prețul redus, perioada de reducere și lotul BBD');
// A: 100 lei redus la 90 (prețul redus); B: 100 lei, cu o perioadă de reducere în curs la 85 (și una încheiată, la 50, care nu
// contează); C: 100 lei, cu două loturi BBD de ales: „lotr” la 70 și „lotp” fără preț propriu (deci prețul produsului).
$perioadaB = json_encode([
    ['start_date' => date('Y-m-d H:i:s', time() - 86400 * 2), 'end_date' => date('Y-m-d H:i:s', time() + 86400 * 2), 'sale_price' => 85],
    ['start_date' => date('Y-m-d H:i:s', time() - 86400 * 20), 'end_date' => date('Y-m-d H:i:s', time() - 86400 * 10), 'sale_price' => 50],
]);
$loturiC = json_encode([
    ['key' => 'lotr', 'date' => date('Y-m-d', time() + 86400 * 60), 'lot' => 'LR1', 'reduced_price' => '70.00', 'stock' => 20],
    ['key' => 'lotp', 'date' => date('Y-m-d', time() + 86400 * 90), 'lot' => 'LP1', 'reduced_price' => '', 'stock' => 20],
]);
$produsF2 = $db->prepare('UPDATE products SET price = :pret, sale_price = :redus, sale_price_periods_json = :perioade, bbd_enabled = :bbd,
    bbd_entries_json = :loturi, out_of_stock = 0, preorder_enabled = 0, hidden_in_catalog = 0, is_active = 1 WHERE id = :id');
$puneProdusF2 = static function (int $id, float $pret, ?float $redus, ?string $perioade = null, ?string $loturi = null) use ($produsF2): void {
    $produsF2->execute(['id' => $id, 'pret' => $pret, 'redus' => $redus, 'perioade' => $perioade, 'bbd' => $loturi !== null ? 1 : 0, 'loturi' => $loturi]);
};
$puneProdusF2($pA, 100, 90);
$puneProdusF2($pB, 100, null, $perioadaB);
$puneProdusF2($pC, 100, null, null, $loturiC);
$ofertaF2 = $db->prepare('UPDATE campanii SET oferta_tip = :t, oferta_n = :n, oferta_m = :m, oferta_procent = :p WHERE id = :id');
$puneOfertaF2 = static function (string $tip, int $n, int $m, float $procent) use ($ofertaF2, $idCampanie): void {
    $ofertaF2->execute(['t' => $tip, 'n' => $n, 'm' => $m, 'p' => $procent, 'id' => $idCampanie]);
    ResponseCache::purgePageCache();
};
$liniaCheie = static function (array $s, string $cheie): array {
    foreach ((array) ($s['lines'] ?? []) as $l) {
        if ((string) ($l['item_key'] ?? '') === $cheie) {
            return $l;
        }
    }
    return [];
};
$centiDin = static fn (mixed $v): int => (int) round((float) $v * 100);
// [reducerea, totalul liniei, totalul de dinainte de ofertă] al unei linii, în cenți (reducerea 0 = fără ofertă pe linie).
$sumeLinie = static fn (array $l): array => [$centiDin($l['campanie_reducere'] ?? 0), $centiDin($l['line_total'] ?? -1),
    $centiDin($l['campanie_total_inainte'] ?? $l['line_total'] ?? -1)];
$cheieLotR = \App\Support\Cart::itemKey($pC, 'lotr');
$cheieLotP = \App\Support\Cart::itemKey($pC, 'lotp');
$umpleCos = static function (array $linii) use ($cerere, $jarF): void {
    @unlink($jarF);
    foreach ($linii as [$id, $cantitate, $lot]) {
        $cerere('POST', '/cos/adauga/' . $id, $jarF, ['quantity' => (string) $cantitate] + ($lot !== '' ? ['bbd_key' => $lot] : []));
    }
};

// −20% pe A (redus), B (perioada), C (două loturi): fiecare linie pornește de la prețul ei de dinainte de ofertă.
$puneOfertaF2('procent', 2, 1, 20);
$umpleCos([[$pA, 1, ''], [$pB, 1, ''], [$pC, 2, 'lotr'], [$pC, 1, 'lotp']]);
$s = $sumar();
$verifica('−20% pe un produs redus (100 → 90): 90 → 72,00 lei (reducerea 18,00 pe linie, prețul de dinainte 90,00)',
    $sumeLinie($liniePe($s, $pA)) === [1800, 7200, 9000], json_encode($liniePe($s, $pA), JSON_UNESCAPED_UNICODE));
$verifica('−20% pe un produs cu o perioadă de reducere în curs (100 → 85; cea încheiată, la 50, nu contează): 85 → 68,00 lei',
    $sumeLinie($liniePe($s, $pB)) === [1700, 6800, 8500], json_encode($liniePe($s, $pB), JSON_UNESCAPED_UNICODE));
$verifica('−20% pe un lot BBD ales (2 × 70,00 = 140,00): 112,00 lei; lotul fără preț propriu (prețul produsului, 100): 80,00 lei',
    $sumeLinie($liniaCheie($s, $cheieLotR)) === [2800, 11200, 14000] && $sumeLinie($liniaCheie($s, $cheieLotP)) === [2000, 8000, 10000]
    && str_contains((string) ($liniaCheie($s, $cheieLotR)['bbd_label'] ?? ''), date('d.m.Y', time() + 86400 * 60)),
    json_encode([$liniaCheie($s, $cheieLotR), $liniaCheie($s, $cheieLotP)], JSON_UNESCAPED_UNICODE));
$verifica('sumarul: oferta 83,00 lei (18 + 17 + 28 + 20), subtotalul 332,00 = liniile după ofertă',
    $centiDin($s['campanie_oferta']['reducere'] ?? 0) === 8300 && $centiDin($s['subtotal'] ?? 0) === 33200
    && ($s['campanie_oferta']['text'] ?? '') === $numeCampanie . ': −20%', json_encode($s['campanie_oferta'] ?? null, JSON_UNESCAPED_UNICODE));
// Cuponul rămâne doar pe produsele din afara ofertei: liniile reduse ale campaniei (acum eligibile) nu îl primesc.
$cerere('POST', '/cos/adauga/' . $idAfara, $jarF, ['quantity' => '1']);
$cerere('POST', '/cos/cupon', $jarF, ['coupon_code' => 'DEMO10']);
$s = $sumar();
$verifica('cuponul DEMO10 doar pe produsul din afara campaniei, nu și pe liniile reduse ale ofertei (prețul redus, perioada, loturile BBD)',
    ($s['coupon']['code'] ?? '') === 'DEMO10' && abs((float) $s['discount'] - round((float) $liniePe($s, $idAfara)['line_total'] * 0.10, 2)) < 0.006
    && array_filter([$liniePe($s, $pA), $liniePe($s, $pB), $liniaCheie($s, $cheieLotR), $liniaCheie($s, $cheieLotP)],
        static fn (array $l): bool => (float) ($l['coupon_discount'] ?? -1) !== 0.0) === []
    && $centiDin($s['campanie_oferta']['reducere'] ?? 0) === 8300 && !empty($s['campanie_oferta']['cupon_exclus']),
    $s['discount'] . ' ' . json_encode(array_map(static fn (array $l): mixed => $l['coupon_discount'] ?? null, (array) $s['lines'])));
// Rotunjirea pe linie, la cent: 3 × 33,33 (redus) = 99,99, −20% = 19,998 → 20,00, deci 79,99.
$puneProdusF2($pA, 100, 33.33);
$umpleCos([[$pA, 3, '']]);
$s = $sumar();
$verifica('rotunjirea: 3 × 33,33 (redus de la 100) = 99,99, −20% = 19,998 → 20,00 lei, linia 79,99',
    $sumeLinie($liniePe($s, $pA)) === [2000, 7999, 9999] && $centiDin($s['subtotal'] ?? 0) === 7999, json_encode($liniePe($s, $pA), JSON_UNESCAPED_UNICODE));

// 1+1 −50% cu o bucată redusă și una la preț întreg: perechea se face după prețul de acum (cel redus), nu după prețul de bază.
// A: 100 redus la 70, B: 80 fără reducere → ordinea B (80), A (70): reducerea pe A, 35,00 (după prețul de bază ar fi fost pe B).
$puneOfertaF2('al_n', 2, 1, 50);
$puneProdusF2($pA, 100, 70);
$puneProdusF2($pB, 80, null);
$umpleCos([[$pA, 1, ''], [$pB, 1, '']]);
$s = $sumar();
$verifica('1+1 −50%: A redus (100 → 70) + B la preț întreg (80): jumătate din A, cel mai ieftin după prețul de acum (35,00), B rămâne 80,00',
    $sumeLinie($liniePe($s, $pA)) === [3500, 3500, 7000] && $sumeLinie($liniePe($s, $pB)) === [0, 8000, 8000]
    && $centiDin($s['campanie_oferta']['reducere'] ?? 0) === 3500, json_encode($s['lines'] ?? null, JSON_UNESCAPED_UNICODE));
// A redus la 90 + B la preț întreg 100 + lotul BBD de 70 (3 bucăți, o pereche): (100, 90) → 45,00 pe A; lotul rămâne întreg.
$puneProdusF2($pA, 100, 90);
$puneProdusF2($pB, 100, null);
$umpleCos([[$pA, 1, ''], [$pB, 1, ''], [$pC, 1, 'lotr']]);
$s = $sumar();
$verifica('1+1 −50% pe A (redus la 90), B (100) și lotul BBD (70): perechea (100, 90), 45,00 pe A; lotul fără pereche, la 70,00',
    $sumeLinie($liniePe($s, $pA)) === [4500, 4500, 9000] && $sumeLinie($liniePe($s, $pB)) === [0, 10000, 10000]
    && $sumeLinie($liniaCheie($s, $cheieLotR)) === [0, 7000, 7000] && $centiDin($s['campanie_oferta']['reducere'] ?? 0) === 4500,
    json_encode($s['lines'] ?? null, JSON_UNESCAPED_UNICODE));
// 3 la preț de 2 pe aceleași trei bucăți: cea mai ieftină (lotul de 70) e gratuită.
$puneOfertaF2('n_la_m', 3, 2, 0);
$s = $sumar();
$verifica('3 la preț de 2 pe B (100), A (redus la 90) și lotul BBD (70): lotul gratuit (70,00), subtotalul 190,00',
    $sumeLinie($liniaCheie($s, $cheieLotR)) === [7000, 0, 7000] && $sumeLinie($liniePe($s, $pA)) === [0, 9000, 9000]
    && $centiDin($s['campanie_oferta']['reducere'] ?? 0) === 7000 && $centiDin($s['subtotal'] ?? 0) === 19000,
    json_encode($s['lines'] ?? null, JSON_UNESCAPED_UNICODE));

// Prețul de campanie: la salvare, sub prețul de acum al produsului (cel redus, al perioadei; la un produs cu loturi BBD, prețul
// fără lot); în coș, doar cât e sub prețul liniei (prețul mai mic câștigă).
$puneProdusF2($pB, 100, null, $perioadaB);
$preturiF2 = [];
foreach ($produseCampanie as $idProdus) {
    $preturiF2[$idProdus] = number_format((float) $unu('SELECT price FROM products WHERE id = :id', ['id' => $idProdus]) - 1, 2, '.', '');
}
$r = $cerere('POST', '/admin/campanii/salveaza', $jarAdmin, ['oferta_tip' => 'pret_fix',
    'pret_campanie' => [$pA => '95', $pB => '90', $pC => '75'] + $preturiF2, 'produse' => $produseCampanie] + $campDateF);
$verifica('prețul de campanie peste prețul redus refuzat la salvare (422): A 95 > 90 (redus de la 100), B 90 > 85 (perioada); C 75 (loturi BBD) trece',
    $r['cod'] === 422 && str_contains($r['corp'], 'un preț mai mic decât prețul lui de acum (90.00 lei, redus de la 100.00 lei)')
    && str_contains($r['corp'], 'un preț mai mic decât prețul lui de acum (85.00 lei, redus de la 100.00 lei)')
    && substr_count($r['corp'], 'un preț mai mic decât prețul lui de acum') === 2
    && $unu('SELECT COALESCE(oferta_tip, "") FROM campanii WHERE id = :id', ['id' => $idCampanie]) === 'n_la_m', $r['cod'] . ' ' . mb_substr(strip_tags($r['corp']), 0, 300));
$r = $cerere('POST', '/admin/campanii/salveaza', $jarAdmin, ['oferta_tip' => 'pret_fix',
    'pret_campanie' => [$pA => '85', $pB => '80', $pC => '75'] + $preturiF2, 'produse' => $produseCampanie] + $campDateF);
$verifica('prețul de campanie sub prețul de acum (A 85 < 90, B 80 < 85, C 75 < 100): salvat',
    $r['cod'] === 302 && $unu('SELECT oferta_tip FROM campanii WHERE id = :id', ['id' => $idCampanie]) === 'pret_fix'
    && $unu('SELECT GROUP_CONCAT(pret_campanie ORDER BY FIELD(product_id, :a, :b, :c)) FROM campanii_produse WHERE campanie_id = :id AND product_id IN (:a2, :b2, :c2)',
        ['id' => $idCampanie, 'a' => $pA, 'b' => $pB, 'c' => $pC, 'a2' => $pA, 'b2' => $pB, 'c2' => $pC]) === '85.00,80.00,75.00', (string) $r['cod']);
ResponseCache::purgePageCache();
$umpleCos([[$pA, 1, ''], [$pB, 1, ''], [$pC, 1, 'lotr'], [$pC, 1, 'lotp']]);
$s = $sumar();
$verifica('„Preț de campanie” în coș: A 90 → 85, B 85 → 80, lotul de 70 rămâne 70 (75 nu e mai mic), lotul fără preț 100 → 75; oferta 35,00',
    $sumeLinie($liniePe($s, $pA)) === [500, 8500, 9000] && $sumeLinie($liniePe($s, $pB)) === [500, 8000, 8500]
    && $sumeLinie($liniaCheie($s, $cheieLotR)) === [0, 7000, 7000] && $sumeLinie($liniaCheie($s, $cheieLotP)) === [2500, 7500, 10000]
    && $centiDin($s['campanie_oferta']['reducere'] ?? 0) === 3500, json_encode($s['lines'] ?? null, JSON_UNESCAPED_UNICODE));
// O reducere pusă după salvare, sub prețul de campanie: prețul mai mic rămâne, oferta nu schimbă nimic pe linie.
$puneProdusF2($pA, 100, 80);
$s = $sumar();
$verifica('o reducere pusă după salvare sub prețul de campanie (A redus la 80, prețul de campanie 85): A rămâne 80,00, fără ofertă pe linie',
    $sumeLinie($liniePe($s, $pA)) === [0, 8000, 8000] && !isset($liniePe($s, $pA)['campanie_reducere'])
    && $centiDin($s['campanie_oferta']['reducere'] ?? 0) === 3000, json_encode($liniePe($s, $pA), JSON_UNESCAPED_UNICODE));
$puneProdusF2($pA, 100, 90);

// Cardurile {{campanie_produse}} și pagina produsului, cu −20%: de la prețul de acum (cel redus); cu loturi BBD, doar eticheta.
$puneOfertaF2('procent', 2, 1, 20);
$db->prepare('UPDATE pages SET html_content = :h WHERE id = :id')->execute(['id' => $idPagina, 'h' => '<section>{{campanie_produse}}</section>']);
ResponseCache::purgePageCache();
$paginaF2 = $cerere('GET', '/campanie/saptamana-demo?inapoi=%2F')['corp'];
$cardDe = static function (string $html, int $id): string {
    foreach (explode('<article class="campanie-grila__card">', $html) as $bucata) {
        if (str_contains($bucata, 'action="/cos/adauga/' . $id . '"')) {
            return $bucata;
        }
    }
    return '';
};
$slugC = $unu('SELECT slug FROM products WHERE id = :id', ['id' => $pC]);
$cardC = '';
foreach (explode('<article class="campanie-grila__card">', $paginaF2) as $bucata) {
    if (str_contains($bucata, 'href="/produs/' . rawurlencode($slugC) . '"')) {
        $cardC = $bucata;
    }
}
$verifica('cardul unui produs redus (100 → 90), −20%: prețul redus cu cel vechi tăiat, apoi rândul ofertei „~~90.00~~ 72.00 lei”',
    str_contains($cardDe($paginaF2, $pA), '90.00 lei <del class="campanie-grila__vechi">100.00 lei</del>')
    && str_contains($cardDe($paginaF2, $pA), '<p class="campanie-grila__oferta"><del>90.00</del> <strong>72.00 lei</strong></p>'),
    mb_substr(strip_tags($cardDe($paginaF2, $pA)), 0, 300));
$verifica('cardul unui produs cu o perioadă de reducere (85), −20%: „~~85.00~~ 68.00 lei”',
    str_contains($cardDe($paginaF2, $pB), '<p class="campanie-grila__oferta"><del>85.00</del> <strong>68.00 lei</strong></p>'),
    mb_substr(strip_tags($cardDe($paginaF2, $pB)), 0, 300));
$verifica('cardul unui produs cu loturi BBD de ales: „Alege oferta”, rândul ofertei doar cu eticheta („−20% în coș, la oferta aleasă”), fără sume',
    str_contains($cardC, '>Alege oferta</a>') && str_contains($cardC, '<p class="campanie-grila__oferta"><strong>−20%</strong> în coș, la oferta aleasă</p>')
    && !str_contains($cardC, '<p class="campanie-grila__oferta"><del>'), mb_substr(strip_tags($cardC), 0, 300));
$paginaA = $cerere('GET', '/produs/' . rawurlencode($slugA), null, [], ['Referer: ' . $baza . '/'])['corp'];
$verifica('pagina unui produs redus (100 → 90), −20%: rândul ofertei de la prețul redus (~~90.00~~ 72.00 lei)',
    str_contains($paginaA, 'data-campanie-oferta-pret') && str_contains($paginaA, '−20%</span><s style="color:#c0392b;font-weight:500;">90.00</s> <b style="font-weight:800;">72.00 lei</b>'),
    str_contains($paginaA, 'data-campanie-oferta-pret') ? 'rândul da' : 'rândul nu');
$paginaC = $cerere('GET', '/produs/' . rawurlencode($slugC), null, [], ['Referer: ' . $baza . '/'])['corp'];
$verifica('pagina unui produs cu loturi BBD de ales: eticheta ofertei și „în coș, la oferta aleasă”, fără sume',
    str_contains($paginaC, 'data-campanie-oferta-pret') && str_contains($paginaC, '−20%</span>în coș, la oferta aleasă</p>')
    && !str_contains($paginaC, '<s style="color:#c0392b;font-weight:500;">'), str_contains($paginaC, 'data-campanie-oferta-pret') ? 'rândul da' : 'rândul nu');
$verifica('rândul ofertei de la un preț redus (90,00): 1+1, al 3-lea, 3 la preț de 2, prețul de campanie sub și peste; eticheta pentru loturile BBD',
    Campanii::randOfertaCard($ofertaDin('al_n', 2, 1, 50), 5, 9000) === '90.00 lei + <del>90.00</del> <strong>45.00 lei</strong>'
    && Campanii::randOfertaCard($ofertaDin('al_n', 3, 1, 30), 5, 9000) === 'Al 3-lea: <del>90.00</del> <strong>63.00 lei</strong>'
    && Campanii::randOfertaCard($ofertaDin('n_la_m', 3, 2, 0), 5, 9000) === '3 la preț de 2: <del>270.00</del> <strong>180.00 lei</strong>'
    && Campanii::randOfertaCard($ofertaDin('pret_fix', 2, 1, 0, [5 => 8500]), 5, 9000) === '<del>90.00</del> <strong>85.00 lei</strong>'
    && Campanii::randOfertaCard($ofertaDin('pret_fix', 2, 1, 0, [5 => 9500]), 5, 9000) === ''
    && Campanii::randOfertaLot($ofertaDin('procent', 2, 1, 20), 5, 10000) === '<strong>−20%</strong> în coș, la oferta aleasă'
    && Campanii::randOfertaLot($ofertaDin('al_n', 2, 1, 50), 5, 10000, '') === 'în coș, la oferta aleasă'
    && Campanii::randOfertaLot($ofertaDin('pret_fix', 2, 1, 0, [5 => 7500]), 5, 10000) === '<strong>Preț de campanie</strong> în coș, la oferta aleasă'
    && Campanii::randOfertaLot($ofertaDin('pret_fix', 2, 1, 0, [5 => 10000]), 5, 10000) === ''
    && Campanii::randOfertaLot($ofertaDin('procent', 2, 1, 20), 6, 10000) === '');

// Comanda cu reducerile adunate: prețul de dinainte de ofertă pe linie e cel redus (al lotului BBD), ERP-ul ia prețul plătit.
$umpleCos([[$pA, 1, ''], [$pC, 2, 'lotr']]);
$pagCheckout = $cerere('GET', '/checkout', $jarF)['corp'];
preg_match('/name="checkout_form_token" value="([^"]*)"/', $pagCheckout, $tokenF);
preg_match('/name="checkout_form_rendered_at" value="([^"]*)"/', $pagCheckout, $randatF);
sleep(3);
$ultimaComanda = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM orders')->fetchColumn();
$mailuriInainte = glob($dosarDev . '/mail/*') ?: [];
$trimis = $cerere('POST', '/checkout', $jarF, [
    'checkout_form_token' => $tokenF[1] ?? '', 'checkout_form_rendered_at' => $randatF[1] ?? '',
    'billing_first_name' => 'Test', 'billing_last_name' => 'Oferta', 'billing_phone' => '0700000781',
    'billing_email' => 'oferta-campanie@exemplu.ro', 'billing_street' => 'Strada Exemplului', 'billing_street_no' => '11',
    'billing_city' => 'Cluj-Napoca', 'billing_county' => 'Cluj', 'billing_postcode' => '400001',
    'shipping_same_as_billing' => '1', 'payment_method' => 'cod', 'accept_terms' => '1',
]);
$comanda = $db->query('SELECT * FROM orders WHERE id > ' . $ultimaComanda . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$idComanda = is_array($comanda) ? (int) $comanda['id'] : 0;
$liniiComanda = $db->query('SELECT * FROM order_items WHERE order_id = ' . $idComanda . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$liniaComanda = static function (int $id, string $lot) use ($liniiComanda): array {
    foreach ($liniiComanda as $l) {
        if ((int) $l['product_id'] === $id && (string) ($l['bbd_key'] ?? '') === $lot) {
            return $l;
        }
    }
    return [];
};
$sumeComanda = static fn (array $l): array => [$centiDin($l['unit_price'] ?? -1), $centiDin($l['line_total'] ?? -1), $centiDin($l['campanie_reducere'] ?? -1),
    $centiDin($l['campanie_pret_initial'] ?? -1)];
$verifica('comanda cu −20% peste reduceri: A [72,00 × 1, 72,00, oferta 18,00, înainte 90,00], lotul BBD [56,00 × 2, 112,00, oferta 28,00, înainte 70,00], reducerea comenzii 46,00',
    $trimis['cod'] === 302 && is_array($comanda) && $centiDin($comanda['campanie_reducere'] ?? 0) === 4600
    && $sumeComanda($liniaComanda($pA, '')) === [7200, 7200, 1800, 9000] && $sumeComanda($liniaComanda($pC, 'lotr')) === [5600, 11200, 2800, 7000]
    && str_contains((string) ($liniaComanda($pC, 'lotr')['product_name'] ?? ''), '[Ofertă: '),
    $trimis['cod'] . ' ' . ($trimis['antete']['location'] ?? '') . ' ' . json_encode($liniiComanda, JSON_UNESCAPED_UNICODE));
$payload = is_array($comanda) ? \App\Support\ErpSync::buildPayload($db, $comanda) : [];
$preturiErp = array_map(static fn (array $l): float => (float) $l['pretUnitar'], (array) ($payload['linii'] ?? []));
$verifica('ERP: liniile cu prețul plătit (72,0000 și 56,0000), după ambele reduceri', $preturiErp === [72.0, 56.0]
    || $preturiErp === [56.0, 72.0], json_encode($payload['linii'] ?? null, JSON_UNESCAPED_UNICODE));
if ($idComanda > 0) {
    foreach (['order_items', 'email_logs'] as $tabel) {
        $db->exec('DELETE FROM ' . $tabel . ' WHERE order_id = ' . $idComanda);
    }
    $db->exec('DELETE FROM orders WHERE id = ' . $idComanda);
}
array_map('unlink', array_diff(glob($dosarDev . '/mail/*') ?: [], $mailuriInainte));
@unlink($jarF);
// Înapoi: produsele, pagina, fără ofertă (D și C2 pornesc de la datele de dinainte).
$inapoiC1();
$db->prepare('UPDATE campanii SET oferta_tip = NULL WHERE id = :id')->execute(['id' => $idCampanie]);
$db->prepare('UPDATE campanii_produse SET pret_campanie = NULL WHERE campanie_id = :c')->execute(['c' => $idCampanie]);
ResponseCache::purgePageCache();

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
    // Oferta campaniei „1+1 −50%” în coș, coșul plutitor și checkout.
    $db->prepare("UPDATE campanii SET oferta_tip = 'al_n', oferta_n = 2, oferta_procent = 50 WHERE id = :id")->execute(['id' => $idCampanie]);
    $ruleazaBrowser(array_merge($env, ['CMS_DEV_COS=1', 'CMS_DEV_PRODUSE=' . $produseCampanie[0] . ',' . $produseCampanie[1]]));
    $db->prepare('UPDATE campanii SET oferta_tip = NULL WHERE id = :id')->execute(['id' => $idCampanie]);
}

// ── C. În afara ferestrei, oprită, previzualizarea, pluginul oprit ─────────────────────────────────────────────────────
$scrie('');
$scrie('== C2. În afara ferestrei, oprită, pluginul oprit');
$verificaNimic = static function (string $eticheta) use ($cerere, $verifica, $tinta, $slugInCampanie, $idCampanie, $baza): void {
    $r1 = $cerere('GET', $tinta, null, [], ['Referer: https://www.google.com/']);
    $r2 = $cerere('GET', '/campanie/saptamana-demo?inapoi=%2F');
    $r3 = $cerere('GET', '/magazin', null, [], ['Referer: ' . $baza . '/']);
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
