<?php

declare(strict_types=1);

/**
 * Testele pluginului `statistici` (cerut pentru Biovitality pe 8 nov 2026; docs/PLUGINURI.md, „Statistici site”), pe serverul de
 * dezvoltare, pe baza de test cu datele demo și evenimente inventate:
 *
 *   tools/dev/php.sh tools/dev/test-statistici.php                 # A–E
 *   tools/dev/php.sh tools/dev/test-statistici.php --fara-browser  # fără partea D (Chromium)
 *
 * A. Fără server: clasificarea surselor (rețelele sociale, Google organic și Ads, Bing, alte căutări, email, alte site-uri,
 *    direct, utm, aplicațiile TikTok/Instagram/Facebook fără Referer), browserul și dispozitivul, felul paginii, hash-ul
 *    vizitatorului și sarea zilei (fișierul, 0600, aceeași toată ziua, alta a doua zi, cea veche ștearsă), excluderile (roboți,
 *    DNT, GPC, preîncărcări), validarea corpului și a originii, scriptul (sub 2 KB, fără cookie și fără stocare în browser).
 * B. Agregarea, pe o zi inventată: vizitele (pauza de 30 de minute), sursa din prima pagină, paginile, intrările, produsele cu
 *    adăugările în coș, căutările fără rezultat, atribuirea comenzilor (ultima vizită venită de undeva, din aceeași zi; fără
 *    vizită = necunoscută), ziua încheiată (hash-ul golit de pe comenzi), rularea de două ori (aceleași rânduri), cifrele
 *    raportului (venitul fără comenzile anulate), impactul pe ore față de săptămâna dinainte, ștergerea evenimentelor vechi.
 * C. Pe server: punctul de primire (204, fără Set-Cookie, fără sesiune nouă; originea, corpul, mărimea, roboții, DNT, GPC,
 *    preîncărcarea, administratorul logat, limita pe minut), scriptul în pagină (nu pentru administrator), coșul și căutarea
 *    cu același hash ca pagina, pluginul oprit (fără script, 404 la punct, 403 în admin, fără meniu), tabloul (perioadele,
 *    filtrul, comparația, „Acum pe site”, CSV, impactul, setările cu jetonul formularului, rolurile).
 * D. În Chromium (tools/dev/statistici-browser.cjs): o pagină servită ca dintr-un CDN (un fișier HTML static, fără PHP) trimite
 *    evenimentul; tabloul la 1366 și 390 px fără derulare laterală (capturi, cu datele inventate).
 * E. Curățenia: toată baza pusă la loc (CHECKSUM TABLE), licența „toate”, cache-ul, sesiunile și fișierele testului.
 *
 * Raportul: $CMS_DEV_DIR/test-statistici-raport.txt; capturile: $CMS_DEV_DIR/statistici/. Cod de ieșire 0 doar dacă totul a trecut.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';
require_once __DIR__ . '/lib/salvari-admin.php';

use App\Support\Database;
use App\Support\Paths;
use App\Support\ResponseCache;
use App\Support\Statistici;
use App\Support\StatisticiAgregare;
use App\Support\StatisticiRaport;

$faraBrowser = in_array('--fara-browser', $argv, true);
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-statistici-raport.txt';
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
$scrie('Test pluginul statistici ANDAXI-CMS — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

// ── A. Fără server ───────────────────────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== A. Regulile, fără server');
$site = 'magazin.exemplu';
$chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
$tiktokApp = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 musical_ly_34.1.0 JsSdk/2.0 NetType/WIFI BytedanceWebview/d8a21c6';
$instaApp = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 330.0.0.0 (iPhone14,5)';
$fbApp = 'Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/124.0 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/460.0.0.0;]';
// [utm, clicuri, referer, user-agent] => [sursa, detaliu]
$cazuriSursa = [
    'Facebook (l.facebook.com)' => [[], '', 'https://l.facebook.com/', $chrome, 'facebook', 'l.facebook.com'],
    'Facebook mobil (m.facebook.com)' => [[], '', 'https://m.facebook.com/story.php', $chrome, 'facebook', 'm.facebook.com'],
    'Facebook după fbclid, fără Referer' => [[], 'f', '', $chrome, 'facebook', 'fbclid'],
    'fbclid din Instagram (Referer)' => [[], 'f', 'https://l.instagram.com/', $chrome, 'instagram', 'l.instagram.com'],
    'Instagram' => [[], '', 'https://www.instagram.com/', $chrome, 'instagram', 'instagram.com'],
    'TikTok (Referer)' => [[], '', 'https://www.tiktok.com/', $chrome, 'tiktok', 'tiktok.com'],
    'TikTok după ttclid' => [[], 't', '', $chrome, 'tiktok', 'ttclid'],
    'TikTok din aplicație, fără Referer' => [[], '', '', $tiktokApp, 'tiktok', 'aplicația TikTok'],
    'Instagram din aplicație, fără Referer' => [[], '', '', $instaApp, 'instagram', 'aplicația Instagram'],
    'Facebook din aplicație, fără Referer' => [[], '', '', $fbApp, 'facebook', 'aplicația Facebook'],
    'YouTube' => [[], '', 'https://m.youtube.com/', $chrome, 'youtube', 'm.youtube.com'],
    'YouTube scurt' => [[], '', 'https://youtu.be/x', $chrome, 'youtube', 'youtu.be'],
    'Pinterest' => [[], '', 'https://ro.pinterest.com/', $chrome, 'pinterest', 'ro.pinterest.com'],
    'LinkedIn' => [[], '', 'https://www.linkedin.com/', $chrome, 'linkedin', 'linkedin.com'],
    'X (t.co)' => [[], '', 'https://t.co/abc', $chrome, 'x', 't.co'],
    'WhatsApp' => [[], '', 'https://web.whatsapp.com/', $chrome, 'whatsapp', 'web.whatsapp.com'],
    'Google organic' => [[], '', 'https://www.google.ro/', $chrome, 'google', 'google.ro'],
    'Google Ads (gclid)' => [[], 'g', 'https://www.google.com/', $chrome, 'google_ads', 'gclid'],
    'Google Ads (utm cpc)' => [['sursa' => 'google', 'mediu' => 'cpc'], '', '', $chrome, 'google_ads', 'google / cpc'],
    'utm google fără mediu plătit' => [['sursa' => 'google', 'mediu' => 'organic'], '', '', $chrome, 'google', 'google / organic'],
    'Bing' => [[], '', 'https://www.bing.com/', $chrome, 'bing', 'bing.com'],
    'DuckDuckGo' => [[], '', 'https://duckduckgo.com/', $chrome, 'cautare', 'duckduckgo.com'],
    'Email (utm_medium=email)' => [['sursa' => 'newsletter', 'mediu' => 'email'], '', '', $chrome, 'email', 'newsletter / email'],
    'Email (Gmail web)' => [[], '', 'https://mail.google.com/', $chrome, 'email', 'mail.google.com'],
    'utm_source=tiktok' => [['sursa' => 'tiktok', 'mediu' => 'social'], '', '', $chrome, 'tiktok', 'tiktok / social'],
    'utm_source=ig' => [['sursa' => 'ig'], '', '', $chrome, 'instagram', 'ig'],
    'utm_source=facebook, reclamă plătită' => [['sursa' => 'facebook', 'mediu' => 'paid'], '', '', $chrome, 'facebook', 'facebook / paid'],
    'alt utm_source = campanie' => [['sursa' => 'partener-x', 'mediu' => 'banner'], '', '', $chrome, 'campanie', 'partener-x / banner'],
    'alt site' => [[], '', 'https://blog.exemplu.org/articol', $chrome, 'alt_site', 'blog.exemplu.org'],
    'aplicația Gmail (android-app)' => [[], '', 'android-app://com.google.android.gm/', $chrome, 'email', 'mail.google.com'],
    'aplicația Google (android-app)' => [[], '', 'android-app://com.google.android.googlequicksearchbox/', $chrome, 'google', 'google.com'],
    'din site (intern)' => [[], '', 'https://www.magazin.exemplu/produs/x', $chrome, 'intern', ''],
    'direct' => [[], '', '', $chrome, 'direct', ''],
];
$gresite = [];
foreach ($cazuriSursa as $nume => [$utm, $clic, $ref, $ua, $sursa, $detaliu]) {
    $r = Statistici::clasificaSursa($utm, $clic, $ref, $ua, $site);
    if ($r['sursa'] !== $sursa || $r['detaliu'] !== $detaliu) {
        $gresite[] = $nume . ' → ' . json_encode($r, JSON_UNESCAPED_UNICODE);
    }
}
$verifica('clasificarea surselor (' . count($cazuriSursa) . ' cazuri: rețelele sociale, aplicațiile fără Referer, Google organic și Ads, '
    . 'Bing, alte căutări, email, utm, alte site-uri, intern, direct)', $gresite === [], implode('; ', $gresite));

$ua = [
    [$chrome, 'Chrome', 'Windows', 'desktop'],
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1', 'Safari', 'iOS', 'mobil'],
    ['Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1', 'Safari', 'iOS', 'tableta'],
    ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36', 'Chrome', 'Android', 'mobil'],
    ['Mozilla/5.0 (Linux; Android 13; SM-X200) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36', 'Chrome', 'Android', 'tableta'],
    ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7; rv:125.0) Gecko/20100101 Firefox/125.0', 'Firefox', 'macOS', 'desktop'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 Edg/124.0', 'Edge', 'Windows', 'desktop'],
    ['Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/24.0 Chrome/117.0 Mobile Safari/537.36', 'Samsung Internet', 'Android', 'mobil'],
    [$tiktokApp, 'TikTok (aplicație)', 'iOS', 'mobil'],
    [$instaApp, 'Instagram (aplicație)', 'iOS', 'mobil'],
    [$fbApp, 'Facebook (aplicație)', 'Android', 'mobil'],
];
$gresite = [];
foreach ($ua as [$sir, $browser, $os, $disp]) {
    $r = Statistici::parseazaUa($sir);
    if ($r !== ['browser' => $browser, 'os' => $os, 'dispozitiv' => $disp]) {
        $gresite[] = $browser . '/' . $os . ' → ' . json_encode($r, JSON_UNESCAPED_UNICODE);
    }
}
$verifica('browserul, sistemul și dispozitivul din User-Agent (doar familia)', $gresite === [], implode('; ', $gresite));
$tipuri = ['/' => 'acasa', '/produs/ceai' => 'produs', '/categorie/ceaiuri' => 'categorie', '/magazin' => 'magazin', '/marca/x' => 'magazin',
    '/blog' => 'blog', '/blog/un-articol' => 'articol', '/blog/categorie/x' => 'blog', '/cos' => 'cos', '/checkout' => 'checkout',
    '/checkout/succes/123' => 'comanda', '/campanie/black-friday' => 'campanie', '/despre-noi' => 'pagina'];
$gresite = array_keys(array_filter($tipuri, static fn (string $tip, string $cale): bool => Statistici::tipPagina($cale) !== $tip, ARRAY_FILTER_USE_BOTH));
$verifica('felul paginii după adresă (' . count($tipuri) . ' cazuri) și căutarea (?q=)', $gresite === [] && Statistici::tipPagina('/magazin', true) === 'cautare',
    implode(', ', $gresite));

// Sarea zilei și hash-ul.
$dosarSare = sys_get_temp_dir() . '/andaxi-test-statistici-' . getmypid();
@mkdir($dosarSare, 0700, true);
$zi1 = (int) strtotime('2026-03-10 10:00:00');
$zi2 = (int) strtotime('2026-03-11 00:00:05');
$s1 = Statistici::sare($zi1, $dosarSare);
$s1b = Statistici::sare($zi1 + 3600 * 13, $dosarSare);
$fisier1 = $dosarSare . '/sare-20260310.bin';
$permisiuni = is_file($fisier1) ? (fileperms($fisier1) & 0777) : 0;
$s2 = Statistici::sare($zi2, $dosarSare);
$verifica('sarea zilei: 32 de octeți aleatori, aceeași toată ziua, în fișierul zilei cu drepturi 0600', is_string($s1) && strlen($s1) === 32
    && $s1 === $s1b && $permisiuni === 0600, 'drepturi ' . decoct($permisiuni));
$verifica('a doua zi: altă sare, iar cea de ieri e ștearsă (nimeni nu mai poate reface hash-urile de ieri)', is_string($s2) && $s2 !== $s1
    && !is_file($fisier1) && is_file($dosarSare . '/sare-20260311.bin'), implode(', ', array_map('basename', glob($dosarSare . '/*') ?: [])));
$h1 = Statistici::hashVizitator((string) $s1, $site, '203.0.113.7', $chrome);
$verifica('hash-ul: 16 octeți, același pentru același IP și browser în aceeași zi, altul cu alt IP, alt browser, alt site sau a doua zi',
    strlen($h1) === 16 && $h1 === Statistici::hashVizitator((string) $s1, $site, '203.0.113.7', $chrome)
    && $h1 !== Statistici::hashVizitator((string) $s1, $site, '203.0.113.8', $chrome)
    && $h1 !== Statistici::hashVizitator((string) $s1, $site, '203.0.113.7', $tiktokApp)
    && $h1 !== Statistici::hashVizitator((string) $s1, 'alt-site', '203.0.113.7', $chrome)
    && $h1 !== Statistici::hashVizitator((string) $s2, $site, '203.0.113.7', $chrome));
foreach (glob($dosarSare . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($dosarSare);

// Excluderile.
$cereri = [
    'Googlebot' => [['HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'], true, 'robot'],
    'HeadlessChrome' => [['HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/124.0 Safari/537.36'], true, 'robot'],
    'facebookexternalhit (previzualizarea linkului)' => [['HTTP_USER_AGENT' => 'facebookexternalhit/1.1'], true, 'robot'],
    'fără User-Agent' => [[], true, 'robot'],
    'DNT: 1' => [['HTTP_USER_AGENT' => $chrome, 'HTTP_DNT' => '1'], true, 'dnt'],
    'Sec-GPC: 1' => [['HTTP_USER_AGENT' => $chrome, 'HTTP_SEC_GPC' => '1'], true, 'dnt'],
    'DNT: 1 cu setarea oprită' => [['HTTP_USER_AGENT' => $chrome, 'HTTP_DNT' => '1'], false, null],
    'Sec-Purpose: prefetch' => [['HTTP_USER_AGENT' => $chrome, 'HTTP_SEC_PURPOSE' => 'prefetch;prerender'], true, 'preincarcare'],
    'un om' => [['HTTP_USER_AGENT' => $chrome], true, null],
];
$gresite = [];
foreach ($cereri as $nume => [$server, $dnt, $asteptat]) {
    if (Statistici::motivExcludere($server, $dnt) !== $asteptat) {
        $gresite[] = $nume . ' → ' . var_export(Statistici::motivExcludere($server, $dnt), true);
    }
}
$verifica('nu se numără: roboții, previzualizările de linkuri, DNT și GPC (cu setarea), preîncărcările; un om da', $gresite === [], implode('; ', $gresite));

$bun = json_encode(['e' => 'v', 'p' => '/produs/ceai', 'r' => 'https://www.tiktok.com/', 'w' => 390, 'u' => ['source' => 'tiktok'], 'c' => 'tx', 'q' => 0, 'i' => 3]);
$valid = Statistici::valideaza((string) $bun);
$verifica('corpul bun: calea, Referer-ul, lățimea, utm, clicurile (doar g, f, t), produsul', is_array($valid) && $valid['p'] === '/produs/ceai'
    && $valid['c'] === 't' && $valid['u']['source'] === 'tiktok' && $valid['i'] === 3 && $valid['w'] === 390, json_encode($valid));
$rele = ['' => '', 'nu e JSON' => 'nu json', 'fără e' => '{"p":"/"}', 'e necunoscut' => '{"e":"x","p":"/"}', 'cale fără /' => '{"e":"v","p":"x"}',
    'cale //' => '{"e":"v","p":"//rau.exemplu/"}', 'cale cu control' => '{"e":"v","p":"/a\u0000b"}', 'u nu e obiect' => '{"e":"v","p":"/","u":"x"}',
    'durata negativă' => '{"e":"l","p":"/","d":-5}', 'prea mare' => '{"e":"v","p":"/","r":"' . str_repeat('a', 2100) . '"}',
    'prea adânc' => '{"e":"v","p":"/","u":{"source":{"a":{"b":1}}}}'];
$trecuteRele = array_keys(array_filter($rele, static fn (string $corp): bool => Statistici::valideaza($corp) !== null));
$verifica('corpurile greșite sunt refuzate (' . count($rele) . ' cazuri)', $trecuteRele === [], implode(', ', $trecuteRele));
$verifica('Referer-ul care nu e http(s) se ignoră; parametrii căii se scot',
    (Statistici::valideaza('{"e":"v","p":"/x?email=a@b.ro","r":"javascript:alert(1)"}') ?? [])['r'] === ''
    && (Statistici::valideaza('{"e":"v","p":"/x?email=a@b.ro"}') ?? [])['p'] === '/x');
$appHost = (string) parse_url(\App\Support\AppUrl::base(), PHP_URL_HOST);
$origini = [
    'aceeași origine' => [['HTTP_ORIGIN' => \App\Support\AppUrl::base(), 'HTTP_HOST' => $appHost], true],
    'alt site' => [['HTTP_ORIGIN' => 'https://rau.exemplu', 'HTTP_HOST' => $appHost], false],
    'Origin: null' => [['HTTP_ORIGIN' => 'null', 'HTTP_HOST' => $appHost], false],
    'fără Origin, Sec-Fetch-Site: same-origin' => [['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_HOST' => $appHost], true],
    'fără Origin, Sec-Fetch-Site: cross-site' => [['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_HOST' => $appHost], false],
    'fără Origin, Referer al site-ului' => [['HTTP_REFERER' => \App\Support\AppUrl::base() . '/produs/x', 'HTTP_HOST' => $appHost], true],
    'fără nimic' => [['HTTP_HOST' => $appHost], false],
];
$gresite = array_keys(array_filter($origini, static fn (array $c): bool => Statistici::origineaPermisa($c[0]) !== $c[1]));
$verifica('originea: doar de pe același site (Origin; fără el, Sec-Fetch-Site sau Referer)', $gresite === [], implode(', ', $gresite));
$cod = Statistici::codScript(true, 'produs', 12);
$verifica('scriptul: sub 2 KB, fără cookie, localStorage, sessionStorage sau indexedDB, cu sendBeacon și fetch keepalive', strlen($cod) < 2048
    && preg_match('/cookie|localStorage|sessionStorage|indexedDB/i', $cod) !== 1 && str_contains($cod, 'sendBeacon') && str_contains($cod, 'keepalive'),
    strlen($cod) . ' octeți');
$verifica('scriptul respectă DNT/GPC doar cu setarea; așteaptă pagina văzută (prerender, fila ascunsă)', str_contains($cod, 'doNotTrack')
    && !str_contains(Statistici::codScript(false, 'acasa', 0), 'doNotTrack') && str_contains($cod, 'prerendering') && str_contains($cod, 'visibilityState'));
$verifica('căutarea normalizată: litere mici, spațiile strânse, cel mult 100 de caractere, sub 2 nimic',
    Statistici::normalizeazaCautare("  Ceai   de MENTĂ\t") === 'ceai de mentă' && mb_strlen(Statistici::normalizeazaCautare(str_repeat('ab', 80))) === 100
    && Statistici::normalizeazaCautare('a') === '');

// ── Baza și serverul ─────────────────────────────────────────────────────────────────────────────────────────────────
$port = (int) (getenv('CMS_DEV_PORT') ?: '8701');
$baza = 'http://127.0.0.1:' . $port;
$cerere = static function (string $metoda, string $cale, ?string $jar = null, array|string|null $date = null, array $antete = []) use ($baza, $chrome): array {
    $primite = [];
    $c = curl_init($baza . $cale);
    $date0 = ['user-agent' => 'User-Agent: ' . $chrome, 'accept' => 'Accept: text/html,application/xhtml+xml,*/*;q=0.8'];
    foreach ($antete as $antet) {
        unset($date0[strtolower(trim(explode(':', $antet, 2)[0]))]);
    }
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOPROXY => '*',
        CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => array_merge(array_values($date0), $antete),
        CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$primite): int {
            if (str_contains($linie, ':')) {
                [$nume, $valoare] = explode(':', $linie, 2);
                $nume = strtolower(trim($nume));
                $primite[$nume] = isset($primite[$nume]) ? $primite[$nume] . "\n" . trim($valoare) : trim($valoare);
            }
            return strlen($linie);
        },
    ]);
    if ($date !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, is_array($date) ? http_build_query($date) : $date);
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
if (!$db instanceof PDO || (int) $db->query('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND is_active = 1')->fetchColumn() < 5) {
    $scrie('EROARE: baza de test nu are datele demo; rulează întâi tools/dev/instaleaza.sh');
    exit(1);
}
try {
    $db->query('SELECT 1 FROM statistici_evenimente LIMIT 1');
} catch (Throwable) {
    $scrie('EROARE: baza nu are tabelele migrării statistici/0061 (tools/dev/php.sh scripts/migreaza.php --aplica)');
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
$parolaAdmin = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));
$adminEmail = (string) (getenv('CMS_DEV_ADMIN_EMAIL') ?: 'admin@exemplu.ro');
$jaruri = [];
$fisierStatic = $radacina . '/public/test-statistici-cdn-' . getmypid() . '.html';
$licentaSchimbata = false;
$puneLaLoc = static function () use (&$licentaSchimbata, &$jaruri, $db, $tabeleBaza, $instantaneu, $radacina, $sesiuniInainte, $amPornitServerul, $fisierStatic): void {
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
    foreach ($jaruri as $jar) {
        @unlink($jar);
    }
    @unlink($fisierStatic);
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
$jeton = static fn (string $html): string => preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
$logare = static function (string $email) use ($cerere, $parolaAdmin, $dosarDev, &$jaruri): string {
    $jar = $dosarDev . '/test-statistici-' . md5($email) . '-' . getmypid() . '.txt';
    $jaruri[] = $jar;
    @unlink($jar);
    $cerere('GET', '/admin/login', $jar);
    $cerere('POST', '/admin/login', $jar, ['email' => $email, 'password' => $parolaAdmin]);
    return $jar;
};
if (!$licenta('toate')) {
    $scrie('EROARE: licența „toate” nu s-a pus');
    exit(1);
}
$licentaSchimbata = false;
// Fiecare test pornește de la tabele goale (rândurile de dinainte revin la final).
foreach (['statistici_evenimente', 'statistici_vizite', 'statistici_pagini', 'statistici_produse', 'statistici_cautari', 'statistici_comenzi', 'statistici_zile'] as $t) {
    $db->exec('DELETE FROM ' . $t);
}

// O comandă inventată: copia unei comenzi demo, cu numărul, data, totalul, starea și hash-ul date.
$model = $db->query('SELECT * FROM orders ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$comanda = static function (string $creata, float $total, string $stare, ?string $vizitator) use ($db, $model): int {
    $rand = $model;
    unset($rand['id']);
    $rand['order_number'] = 'TST' . random_int(100000, 999999) . random_int(10, 99);
    $rand['created_at'] = $creata;
    $rand['total'] = $total;
    $rand['status'] = $stare;
    $rand['deleted_at'] = null;
    $rand['statistici_vizitator'] = $vizitator;
    foreach (['erp_status', 'sursa'] as $c) {
        if (array_key_exists($c, $rand)) {
            $rand[$c] = $c === 'sursa' ? 'direct' : $rand[$c];
        }
    }
    $coloane = array_keys($rand);
    $stmt = $db->prepare('INSERT INTO orders (`' . implode('`, `', $coloane) . '`) VALUES (' . implode(', ', array_fill(0, count($coloane), '?')) . ')');
    $i = 1;
    foreach ($rand as $c => $v) {
        $stmt->bindValue($i++, $v, $v === null ? PDO::PARAM_NULL : ($c === 'statistici_vizitator' ? PDO::PARAM_LOB : PDO::PARAM_STR));
    }
    $stmt->execute();
    return (int) $db->lastInsertId();
};
$eveniment = static function (string $cand, string $viz, int $tip, array $extra = []) use ($db): void {
    $rand = array_merge(['creat_la' => $cand, 'vizitator' => $viz, 'tip' => $tip, 'cale' => '/', 'tip_pagina' => 'acasa', 'dispozitiv' => 'mobil',
        'browser' => 'Chrome', 'os' => 'Android'], $extra);
    $coloane = array_keys($rand);
    $stmt = $db->prepare('INSERT INTO statistici_evenimente (' . implode(', ', $coloane) . ') VALUES (' . implode(', ', array_fill(0, count($coloane), '?')) . ')');
    $i = 1;
    foreach ($rand as $c => $v) {
        $stmt->bindValue($i++, $v, $v === null ? PDO::PARAM_NULL : ($c === 'vizitator' ? PDO::PARAM_LOB : PDO::PARAM_STR));
    }
    $stmt->execute();
};

try {
    // ── B. Agregarea ─────────────────────────────────────────────────────────────────────────────────────────────────
    $scrie('');
    $scrie('== B. Agregarea, pe o zi inventată (2026-02-10)');
    $zi = '2026-02-10';
    $A = str_repeat("\x01", 16);
    $B = str_repeat("\x02", 16);
    $C = str_repeat("\x03", 16);
    $D = str_repeat("\x04", 16);
    $produsDemo = (int) $db->query('SELECT id FROM products WHERE deleted_at IS NULL AND is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
    // A: vine din TikTok la 10:00, vede un produs, îl pune în coș, pleacă; revine direct la 11:00 (pauză > 30 min) și comandă la 11:05.
    $eveniment($zi . ' 10:00:00', $A, 1, ['cale' => '/', 'sursa' => 'tiktok', 'detaliu' => 'aplicația TikTok']);
    $eveniment($zi . ' 10:01:00', $A, 1, ['cale' => '/produs/x', 'tip_pagina' => 'produs', 'produs_id' => $produsDemo, 'sursa' => 'intern']);
    $eveniment($zi . ' 10:02:00', $A, 3, ['cale' => '/cos', 'tip_pagina' => 'cos', 'produs_id' => $produsDemo]);
    $eveniment($zi . ' 10:02:30', $A, 2, ['cale' => '/produs/x', 'durata' => 75]);
    $eveniment($zi . ' 11:00:00', $A, 1, ['cale' => '/cos', 'tip_pagina' => 'cos', 'sursa' => 'direct']);
    $eveniment($zi . ' 11:03:00', $A, 4, ['cale' => '/checkout', 'tip_pagina' => 'checkout']);
    $comandaA = $comanda($zi . ' 11:05:00', 150.0, 'processing', $A);
    $eveniment($zi . ' 11:05:00', $A, 5, ['cale' => '/checkout', 'tip_pagina' => 'comanda', 'comanda_id' => $comandaA]);
    // B: Google, o singură pagină, 3 secunde (respingere); caută „ceai” (cu rezultate) și „xyz” (fără).
    $eveniment($zi . ' 10:30:00', $B, 1, ['cale' => '/', 'sursa' => 'google', 'detaliu' => 'google.ro']);
    $eveniment($zi . ' 10:30:10', $B, 6, ['cale' => '/cautare', 'tip_pagina' => 'cautare', 'cautare' => 'ceai', 'rezultate' => 4]);
    $eveniment($zi . ' 10:30:20', $B, 6, ['cale' => '/cautare', 'tip_pagina' => 'cautare', 'cautare' => 'xyz', 'rezultate' => 0]);
    $eveniment($zi . ' 10:30:30', $B, 2, ['cale' => '/', 'durata' => 3]);
    // C: prima pagină cerută din site (intern) = direct; o comandă anulată (fără venit).
    $eveniment($zi . ' 12:00:00', $C, 1, ['cale' => '/despre', 'tip_pagina' => 'pagina', 'sursa' => 'intern']);
    $comandaC = $comanda($zi . ' 12:10:00', 99.0, 'cancelled', $C);
    // D: o comandă fără nicio pagină numărată (scriptul blocat): sursa necunoscută.
    $comandaD = $comanda($zi . ' 13:00:00', 40.0, 'processing', $D);
    $acumInchis = (int) strtotime('2026-02-11 01:00:00');
    $rez = StatisticiAgregare::agregaZiua($db, $zi, $acumInchis);
    $vizite = $db->query("SELECT * FROM statistici_vizite WHERE zi = '$zi' ORDER BY inceput")->fetchAll(PDO::FETCH_ASSOC);
    $verifica('vizitele: 4 (A de două ori, după o pauză de peste 30 de minute; B; C), din 12 evenimente', $rez['vizite'] === 4 && count($vizite) === 4
        && $rez['evenimente'] === 12, json_encode($rez));
    $sursele = array_map(static fn (array $v): string => $v['sursa'] . ':' . $v['pagini'], $vizite);
    $verifica('sursa vizitei din prima pagină (TikTok, Google, „intern” la început = direct), paginile pe vizită',
        $sursele === ['tiktok:2', 'google:1', 'direct:1', 'direct:1'], implode(', ', $sursele));
    $verifica('timpul activ și „angajată”: A 75 s (angajată: 2 pagini, coș), B 3 s cu o pagină (respingere)',
        (int) $vizite[0]['durata'] === 75 && (int) $vizite[0]['angajata'] === 1 && (int) $vizite[0]['adaugari_cos'] === 1
        && (int) $vizite[1]['angajata'] === 0 && (int) $vizite[2]['comenzi'] === 1, json_encode(array_map(static fn ($v) => [$v['durata'], $v['angajata']], $vizite)));
    $verifica('vizitatorul păstrat doar ca număr de ordine al zilei (fără hash în agregate)', count(array_unique(array_column($vizite, 'vizitator_nr'))) === 3
        && !in_array('vizitator', array_keys($vizite[0]), true));
    $atribuiri = $db->query("SELECT comanda_id, sursa, vizita_id FROM statistici_comenzi WHERE zi = '$zi'")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $verifica('comanda lui A atribuită TikTok-ului (ultima vizită venită de undeva din aceeași zi, nu revenirea directă)',
        ($atribuiri[$comandaA]['sursa'] ?? '') === 'tiktok' && (int) ($atribuiri[$comandaA]['vizita_id'] ?? 0) === (int) $vizite[0]['id'], json_encode($atribuiri));
    $verifica('comanda fără vizită numărată: „necunoscuta”; comanda lui C: direct', ($atribuiri[$comandaD]['sursa'] ?? '') === 'necunoscuta'
        && ($atribuiri[$comandaC]['sursa'] ?? '') === 'direct');
    $pagini = $db->query("SELECT CONCAT(sursa, ' ', cale) AS k, vizualizari, intrari FROM statistici_pagini WHERE zi = '$zi'")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $verifica('paginile pe sursa vizitei, cu intrările', ($pagini['tiktok /']['intrari'] ?? 0) == 1 && ($pagini['tiktok /produs/x']['vizualizari'] ?? 0) == 1
        && ($pagini['direct /cos']['intrari'] ?? 0) == 1, json_encode($pagini));
    $produs = $db->query("SELECT vizualizari, adaugari_cos FROM statistici_produse WHERE zi = '$zi' AND produs_id = $produsDemo")->fetch(PDO::FETCH_ASSOC);
    $verifica('produsul: o vizualizare și o adăugare în coș', is_array($produs) && (int) $produs['vizualizari'] === 1 && (int) $produs['adaugari_cos'] === 1);
    $cautari = $db->query("SELECT cautare, cautari, fara_rezultat FROM statistici_cautari WHERE zi = '$zi' ORDER BY cautare")->fetchAll(PDO::FETCH_ASSOC);
    $verifica('căutările: „ceai” cu rezultate, „xyz” fără', array_map(static fn ($c) => $c['cautare'] . ':' . $c['cautari'] . ':' . $c['fara_rezultat'], $cautari)
        === ['ceai:1:0', 'xyz:1:1'], json_encode($cautari));
    $verifica('ziua încheiată după 00:15: marcată, iar hash-ul zilnic golit de pe comenzile ei', $unu("SELECT incheiata FROM statistici_zile WHERE zi = '$zi'") === '1'
        && $unu('SELECT COUNT(*) FROM orders WHERE id IN (' . $comandaA . ',' . $comandaC . ',' . $comandaD . ') AND statistici_vizitator IS NOT NULL') === '0');
    // A doua rulare (cu hash-urile puse la loc, ca o zi încă deschisă): aceleași agregate.
    $sumaAgregate = static fn (): string => json_encode($db->query("SELECT sursa, pagini, durata, angajata, adaugari_cos, comenzi FROM statistici_vizite WHERE zi = '$zi' ORDER BY inceput")->fetchAll(PDO::FETCH_NUM))
        . json_encode($db->query("SELECT comanda_id, sursa FROM statistici_comenzi WHERE zi = '$zi' ORDER BY comanda_id")->fetchAll(PDO::FETCH_NUM));
    $inainte = $sumaAgregate();
    foreach ([[$comandaA, $A], [$comandaC, $C], [$comandaD, $D]] as [$id, $h]) {
        $st = $db->prepare('UPDATE orders SET statistici_vizitator = ? WHERE id = ?');
        $st->bindValue(1, $h, PDO::PARAM_LOB);
        $st->bindValue(2, $id, PDO::PARAM_INT);
        $st->execute();
    }
    StatisticiAgregare::agregaZiua($db, $zi, (int) strtotime($zi . ' 23:00:00'));
    $verifica('agregarea e idempotentă (a doua rulare: aceleași rânduri); o zi încă deschisă păstrează hash-ul pe comenzi', $sumaAgregate() === $inainte
        && $unu("SELECT incheiata FROM statistici_zile WHERE zi = '$zi'") === '0'
        && $unu('SELECT COUNT(*) FROM orders WHERE id = ' . $comandaA . ' AND statistici_vizitator IS NOT NULL') === '1');
    $kpi = StatisticiRaport::kpi($db, $zi, $zi, []);
    $verifica('cifrele: 3 vizitatori unici, 4 vizite, 5 pagini, 1 adăugare, 2 comenzi (fără cea anulată), venit 190 lei',
        $kpi['vizitatori'] === 3 && $kpi['vizite'] === 4 && $kpi['pagini'] === 5 && $kpi['adaugari'] === 1 && $kpi['comenzi'] === 2
        && abs($kpi['venit'] - 190.0) < 0.001, json_encode($kpi));
    $kpiTikTok = StatisticiRaport::kpi($db, $zi, $zi, ['sursa' => 'tiktok']);
    $verifica('filtrul pe sursă: TikTok = 1 vizită, 1 comandă, 150 lei, conversie 100 %', $kpiTikTok['vizite'] === 1 && $kpiTikTok['comenzi'] === 1
        && abs($kpiTikTok['venit'] - 150.0) < 0.001 && abs($kpiTikTok['conversie'] - 1.0) < 0.001, json_encode($kpiTikTok));
    $serie = StatisticiRaport::serie($db, $zi, $zi, [], true);
    $ora10 = array_values(array_filter($serie, static fn ($p) => $p['eticheta'] === '10:00'))[0] ?? [];
    $ora11 = array_values(array_filter($serie, static fn ($p) => $p['eticheta'] === '11:00'))[0] ?? [];
    $verifica('seria pe ore: 24 de puncte; la 10:00 două vizite, la 11:00 comanda de 150 lei', count($serie) === 24 && ($ora10['vizite'] ?? 0) === 2
        && ($ora11['comenzi'] ?? 0) === 1 && abs(($ora11['venit'] ?? 0) - 150.0) < 0.001);
    // Impactul: aceeași sursă cu o săptămână înainte.
    $E = str_repeat("\x05", 16);
    $eveniment('2026-02-03 10:15:00', $E, 1, ['cale' => '/', 'sursa' => 'tiktok', 'detaliu' => 'tiktok.com']);
    StatisticiAgregare::agregaZiua($db, '2026-02-03', $acumInchis);
    $impact = StatisticiRaport::impact($db, 'tiktok', '', (int) strtotime($zi . ' 09:00:00'), 6);
    $verifica('impactul pe ore: TikTok de la 09:00, 6 ore: o vizită la 10:00 și comanda la 11:00, față de o vizită la 10:00 cu o săptămână înainte',
        count($impact) === 6 && $impact[1]['vizite'] === 1 && $impact[2]['comenzi'] === 1 && $impact[1]['vizite_inainte'] === 1
        && abs($impact[2]['venit'] - 150.0) < 0.001, json_encode($impact));
    $surse = StatisticiController_tabel($db, 'surse', StatisticiRaport::perioada(['perioada' => 'custom', 'de_la' => $zi, 'pana_la' => $zi]), []);
    $etichete = array_map(static fn ($r) => $r['valori']['eticheta'], $surse['randuri']);
    $verifica('tabelul surselor: TikTok, Google, Direct și rândul comenzilor fără vizită; TikTok se desface pe detaliu',
        in_array('TikTok', $etichete, true) && in_array('Google (căutare)', $etichete, true) && in_array('Direct', $etichete, true)
        && in_array(StatisticiRaport::numeSursa('necunoscuta'), $etichete, true)
        && ($surse['randuri'][array_search('TikTok', $etichete, true)]['copii'][0]['valori']['eticheta'] ?? '') === 'aplicația TikTok', json_encode($etichete));
    // Ștergerea evenimentelor vechi.
    $sterse = StatisticiAgregare::stergeVechi($db, '2026-02-04');
    $verifica('evenimentele brute mai vechi de prag se șterg; agregatele rămân', $sterse === 1 && $unu("SELECT COUNT(*) FROM statistici_vizite WHERE zi = '2026-02-03'") === '1');
    foreach (['statistici_evenimente', 'statistici_vizite', 'statistici_pagini', 'statistici_produse', 'statistici_cautari', 'statistici_comenzi', 'statistici_zile'] as $t) {
        $db->exec('DELETE FROM ' . $t);
    }
    $db->exec('DELETE FROM orders WHERE id IN (' . $comandaA . ',' . $comandaC . ',' . $comandaD . ')');

    // ── C. Pe server ─────────────────────────────────────────────────────────────────────────────────────────────────
    $scrie('');
    $scrie('== C. Pe server (' . $baza . ')');
    $ultimeleEvenimente = static fn (int $n = 5): array => $db->query('SELECT id, HEX(vizitator) AS v, tip, cale, tip_pagina, produs_id, sursa, detaliu, dispozitiv,
        browser, cautare, rezultate FROM statistici_evenimente ORDER BY id DESC LIMIT ' . $n)->fetchAll(PDO::FETCH_ASSOC);
    $numar = static fn (): int => (int) $db->query('SELECT COUNT(*) FROM statistici_evenimente')->fetchColumn();
    $origine = ['Origin: ' . $baza, 'Content-Type: text/plain'];
    $acasa = $cerere('GET', '/');
    $verifica('scriptul e pe prima pagină (vizitator nou), înainte de </body>, fără cookie nou',
        preg_match('#<script data-ax-st>.{100,2000}</script>\s*</body>#s', $acasa['corp']) === 1 && !isset($acasa['antete']['set-cookie']));
    $sesiuni = count(glob(Paths::stocare('sessions') . '/sess_*') ?: []);
    $r = $cerere('POST', Statistici::CALE, null, '{"e":"v","p":"/produs/test","r":"https://www.tiktok.com/","w":390,"u":{},"c":"","q":0,"i":7}',
        array_merge($origine, ['User-Agent: ' . $tiktokApp]));
    $ev = $ultimeleEvenimente(1)[0] ?? [];
    $verifica('punctul de primire: 204, fără corp, fără Set-Cookie, fără sesiune nouă, Cache-Control: no-store', $r['cod'] === 204 && $r['corp'] === ''
        && !isset($r['antete']['set-cookie']) && count(glob(Paths::stocare('sessions') . '/sess_*') ?: []) === $sesiuni
        && ($r['antete']['cache-control'] ?? '') === 'no-store', json_encode($r['antete']));
    $verifica('rândul: pagina, felul, produsul, TikTok, telefonul, browserul aplicației; fără IP și fără User-Agent în tabel',
        ($ev['cale'] ?? '') === '/produs/test' && ($ev['tip_pagina'] ?? '') === 'produs' && (int) ($ev['produs_id'] ?? 0) === 7 && ($ev['sursa'] ?? '') === 'tiktok'
        && ($ev['dispozitiv'] ?? '') === 'mobil' && ($ev['browser'] ?? '') === 'TikTok (aplicație)'
        && $unu("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'statistici_evenimente'
            AND COLUMN_NAME IN ('ip', 'ip_address', 'adresa_ip', 'user_agent', 'ua', 'referer')") === '0', json_encode($ev));
    $inainte = $numar();
    $refuzuri = [
        'alt site (Origin)' => [403, '{"e":"v","p":"/"}', ['Origin: https://rau.exemplu']],
        'fără Origin și fără Referer' => [403, '{"e":"v","p":"/"}', []],
        'corp greșit' => [400, '{"e":"v"}', $origine],
        'corp prea mare' => [413, '{"e":"v","p":"/","r":"' . str_repeat('a', 3000) . '"}', $origine],
        'robot (Googlebot)' => [204, '{"e":"v","p":"/"}', array_merge($origine, ['User-Agent: Googlebot/2.1 (+http://www.google.com/bot.html)'])],
        'DNT: 1' => [204, '{"e":"v","p":"/"}', array_merge($origine, ['DNT: 1'])],
        'Sec-GPC: 1' => [204, '{"e":"v","p":"/"}', array_merge($origine, ['Sec-GPC: 1'])],
        'preîncărcare' => [204, '{"e":"v","p":"/"}', array_merge($origine, ['Sec-Purpose: prefetch'])],
    ];
    $gresite = [];
    foreach ($refuzuri as $nume => [$codAsteptat, $corp, $antete]) {
        $r = $cerere('POST', Statistici::CALE, null, $corp, $antete);
        if ($r['cod'] !== $codAsteptat) {
            $gresite[] = $nume . ': ' . $r['cod'];
        }
    }
    $verifica('refuzate sau ignorate fără niciun rând: alt site 403, fără origine 403, corp greșit 400, prea mare 413, robot, DNT, GPC, '
        . 'preîncărcare 204', $gresite === [] && $numar() === $inainte, implode('; ', $gresite) . ' rânduri noi: ' . ($numar() - $inainte));
    $jarAdmin = $logare($adminEmail);
    $paginaAdmin = $cerere('GET', '/', $jarAdmin);
    $r = $cerere('POST', Statistici::CALE, $jarAdmin, '{"e":"v","p":"/"}', $origine);
    $verifica('administratorul logat: pagina fără script, evenimentul ignorat (204, niciun rând)', !str_contains($paginaAdmin['corp'], 'data-ax-st')
        && $r['cod'] === 204 && $numar() === $inainte && str_contains($paginaAdmin['corp'], '</body>'));
    // Limita pe minut, cu un browser al lui.
    $uaLimita = $chrome . ' TestLimita/' . getmypid();
    $coduri = [];
    for ($i = 0; $i < Statistici::LIMITA_MINUT + 2; $i++) {
        $coduri[] = $cerere('POST', Statistici::CALE, null, '{"e":"v","p":"/limita"}', array_merge($origine, ['User-Agent: ' . $uaLimita]))['cod'];
    }
    $verifica('limita: ' . Statistici::LIMITA_MINUT . ' evenimente pe minut pentru un vizitator, apoi 429', count(array_filter($coduri, static fn ($c) => $c === 204))
        === Statistici::LIMITA_MINUT && end($coduri) === 429, implode(',', array_count_values(array_map('strval', $coduri)) === [] ? [] : array_map(
            static fn ($k, $v) => $k . '×' . $v, array_keys(array_count_values(array_map('strval', $coduri))), array_count_values(array_map('strval', $coduri)))));
    $db->exec("DELETE FROM statistici_evenimente WHERE cale = '/limita'");
    // Coșul și căutarea: același hash ca pagina văzută din același browser.
    $uaClient = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36 Client/' . getmypid();
    $jarClient = $dosarDev . '/test-statistici-client-' . getmypid() . '.txt';
    $jaruri[] = $jarClient;
    $cerere('POST', Statistici::CALE, $jarClient, '{"e":"v","p":"/produs/x","r":"https://l.instagram.com/"}', array_merge($origine, ['User-Agent: ' . $uaClient]));
    $hashPagina = (string) ($ultimeleEvenimente(1)[0]['v'] ?? '');
    $produsCos = (int) $db->query('SELECT id FROM products WHERE deleted_at IS NULL AND is_active = 1 AND COALESCE(out_of_stock, 0) = 0
        AND COALESCE(bbd_enabled, 0) = 0 ORDER BY id LIMIT 1')->fetchColumn();
    $r = $cerere('POST', '/api/cart/items/' . $produsCos . '/add', $jarClient, ['quantity' => 1], ['User-Agent: ' . $uaClient, 'Accept: application/json']);
    $cos = $ultimeleEvenimente(1)[0] ?? [];
    $verifica('adăugarea în coș (pe server): un eveniment cu produsul și același hash ca pagina văzută', in_array($r['cod'], [200, 201], true)
        && (int) ($cos['tip'] ?? 0) === Statistici::TIP_COS && (int) ($cos['produs_id'] ?? 0) === $produsCos && ($cos['v'] ?? '') === $hashPagina && $hashPagina !== '',
        $r['cod'] . ' ' . json_encode($cos));
    foreach (['ce', 'cea', 'ceai'] as $q) {
        $cerere('GET', '/api/search/products?q=' . rawurlencode($q), null, null, ['User-Agent: ' . $uaClient, 'Accept: application/json']);
    }
    $cerere('GET', '/api/search/products?q=' . rawurlencode('  ZZQQ   inexistent '), null, null, ['User-Agent: ' . $uaClient, 'Accept: application/json']);
    $cautari = $db->query('SELECT cautare, rezultate, HEX(vizitator) AS v FROM statistici_evenimente WHERE tip = 6 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $verifica('căutările: literele scrise una după alta = o căutare („ceai”, cu rezultatele ei); „zzqq inexistent” normalizată, 0 rezultate; același hash',
        array_map(static fn ($c) => $c['cautare'], $cautari) === ['ceai', 'zzqq inexistent'] && (int) $cautari[1]['rezultate'] === 0
        && $cautari[0]['v'] === $hashPagina, json_encode($cautari));
    $r = $cerere('GET', '/checkout', $jarClient, null, ['User-Agent: ' . $uaClient]);
    $verifica('pagina checkout: evenimentul „început de comandă”', (int) ($ultimeleEvenimente(1)[0]['tip'] ?? 0) === Statistici::TIP_CHECKOUT, (string) $r['cod']);
    $cerere('GET', '/api/search/products?q=ceai', null, null, ['User-Agent: Googlebot/2.1', 'Accept: application/json']);
    $verifica('căutarea unui robot nu se notează', (int) $db->query('SELECT COUNT(*) FROM statistici_evenimente WHERE tip = 6')->fetchColumn() === 2);
    // Laolaltă cu comanda: Statistici::laComanda în procesul testului, cu cererea clientului.
    $_SERVER['HTTP_USER_AGENT'] = $uaClient;
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $comandaNoua = $comanda(date('Y-m-d H:i:s'), 120.0, 'processing', null);
    Statistici::laComanda($db, $comandaNoua);
    $verifica('comanda: hash-ul zilnic pe comandă (orders.statistici_vizitator) = al paginii văzute, plus evenimentul comenzii',
        $unu('SELECT HEX(statistici_vizitator) FROM orders WHERE id = ?', [$comandaNoua]) === $hashPagina
        && (int) ($ultimeleEvenimente(1)[0]['tip'] ?? 0) === Statistici::TIP_COMANDA);

    // Tabloul.
    $tablou = $cerere('GET', '/admin/statistici?perioada=azi', $jarAdmin);
    $verifica('Rapoarte → Statistici: 200, „Acum pe site”, cifrele, graficul, tabelele, intrarea din meniu', $tablou['cod'] === 200
        && str_contains($tablou['corp'], 'Acum pe site') && str_contains($tablou['corp'], 'Vizitatori unici') && str_contains($tablou['corp'], 'id="st-grafic-vizite"')
        && str_contains($tablou['corp'], 'id="st-surse"') && str_contains($tablou['corp'], 'href="/admin/statistici"')
        && str_contains($tablou['corp'], 'Instagram'), substr(strip_tags($tablou['corp']), 0, 400));
    $verifica('agregarea la deschidere (fără cron): azi agregată, comanda atribuită Instagramului',
        $unu("SELECT COUNT(*) FROM statistici_zile WHERE zi = CURDATE()") === '1'
        && $unu('SELECT sursa FROM statistici_comenzi WHERE comanda_id = ?', [$comandaNoua]) === 'instagram');
    $gresite = [];
    foreach (['perioada=ieri', 'perioada=7&compara=1', 'perioada=30', 'perioada=90', 'perioada=custom&de_la=2026-01-01&pana_la=2026-01-31',
        'perioada=7&sursa=instagram', 'perioada=7&sursa=instagram&detaliu=l.instagram.com', 'perioada=7&dispozitiv=mobil', 'perioada=7&campanie=x',
        'perioada=zzz&de_la=<script>'] as $q) {
        $r = $cerere('GET', '/admin/statistici?' . $q, $jarAdmin);
        if ($r['cod'] !== 200 || !str_contains($r['corp'], 'Vizitatori unici') || str_contains($r['corp'], '<script>alert')) {
            $gresite[] = $q . ': ' . $r['cod'];
        }
    }
    $verifica('perioadele, comparația și filtrele (sursa, detaliul, dispozitivul, campania) se deschid', $gresite === [], implode('; ', $gresite));
    $filtrat = $cerere('GET', '/admin/statistici?perioada=7&sursa=instagram', $jarAdmin)['corp'];
    $verifica('filtrul la clic: legătura „Instagram” din Surse filtrează tot tabloul, cu eticheta filtrului', str_contains($tablou['corp'], 'sursa=instagram')
        && str_contains($filtrat, 'st-chip') && str_contains($filtrat, 'Filtrat după'));
    $acumJson = json_decode($cerere('GET', '/admin/statistici/acum', $jarAdmin, null, ['Accept: application/json'])['corp'], true);
    $verifica('„Acum pe site” (JSON): vizitatorii din ultimele 5 minute', is_array($acumJson) && ($acumJson['ok'] ?? false) === true
        && (int) $acumJson['vizitatori'] >= 2, json_encode($acumJson));
    $csv = $cerere('GET', '/admin/statistici/export?perioada=azi&tabel=surse', $jarAdmin);
    $verifica('exportul CSV al unui tabel: UTF-8 cu BOM, „;”, antetul și rândurile', $csv['cod'] === 200 && str_starts_with($csv['corp'], "\xEF\xBB\xBFSursa;")
        && str_contains($csv['corp'], 'Instagram') && str_contains((string) ($csv['antete']['content-disposition'] ?? ''), 'statistici-surse-'),
        substr($csv['corp'], 0, 200));
    $toateCsv = [];
    foreach (array_merge(array_keys(\App\Http\Controllers\StatisticiController::TABELE), ['serie']) as $t) {
        $r = $cerere('GET', '/admin/statistici/export?perioada=7&tabel=' . $t, $jarAdmin);
        if ($r['cod'] !== 200 || !str_starts_with($r['corp'], "\xEF\xBB\xBF")) {
            $toateCsv[] = $t . ': ' . $r['cod'];
        }
    }
    $verifica('exportul fiecărui tabel și al seriei', $toateCsv === [] && $cerere('GET', '/admin/statistici/export?tabel=nu', $jarAdmin)['cod'] === 404,
        implode('; ', $toateCsv));
    $impactPagina = $cerere('GET', '/admin/statistici/impact?sursa=instagram&start=' . date('Y-m-d\TH:00', time() - 3600) . '&ore=12', $jarAdmin);
    $verifica('impactul unei postări: tabelul pe ore, cu săptămâna dinainte', $impactPagina['cod'] === 200 && str_contains($impactPagina['corp'], 'id="st-impact-tabel"')
        && str_contains($impactPagina['corp'], 'cu o săptămână înainte'));
    $setari = $cerere('GET', '/admin/statistici/setari', $jarAdmin);
    $refuz = $cerere('POST', '/admin/statistici/setari', $jarAdmin, ['statistici_zile_brute' => '30']);
    $neschimbat = $unu("SELECT `value` FROM settings WHERE `key` = 'statistici_zile_brute'") === '';
    $cerere('POST', '/admin/statistici/setari', $jarAdmin, ['_csrf' => $jeton($setari['corp']), 'statistici_zile_brute' => '30', 'statistici_respecta_dnt' => '1']);
    $verifica('setările: fără jetonul formularului nu se schimbă nimic; cu el, salvate (și în Jurnal activitate)', $setari['cod'] === 200 && $refuz['cod'] === 302
        && $neschimbat && $unu("SELECT `value` FROM settings WHERE `key` = 'statistici_zile_brute'") === '30'
        && $unu("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'statistici_setari_salvate'") !== '0'
        && str_contains($setari['corp'], 'politica de confidențialitate'));
    $jarMagazin = $logare('magazin@exemplu.ro');
    $jarBlog = $logare('blog@exemplu.ro');
    $verifica('rolurile: „Administrator magazin” vede statisticile; „Administrator Blog” nu', $cerere('GET', '/admin/statistici', $jarMagazin)['cod'] === 200
        && $cerere('GET', '/admin/statistici', $jarBlog)['cod'] === 302);

    // Pluginul oprit.
    $licenta('toate', 'statistici=oprit');
    $paginaOprit = $cerere('GET', '/');
    $r = $cerere('POST', Statistici::CALE, null, '{"e":"v","p":"/"}', $origine);
    $adminOprit = $cerere('GET', '/admin/statistici', $jarAdmin);
    $acasaAdmin = $cerere('GET', '/admin', $jarAdmin);
    $inainte = $numar();
    $verifica('pluginul oprit: fără script în pagină, punctul de primire 404 (JSON-ul gărzii), adminul 403, fără intrare în meniu',
        !str_contains($paginaOprit['corp'], 'data-ax-st') && $r['cod'] === 404 && $adminOprit['cod'] === 403
        && !str_contains($acasaAdmin['corp'], 'href="/admin/statistici"') && $numar() === $inainte, $r['cod'] . ' ' . $adminOprit['cod']);
    $licenta('toate');
    $licentaSchimbata = false;
    ResponseCache::purgePageCache();

    // ── D. Chromium ──────────────────────────────────────────────────────────────────────────────────────────────────
    if (!$faraBrowser) {
        $scrie('');
        $scrie('== D. În Chromium');
        // O pagină „din CDN”: HTML-ul primei pagini, scris ca fișier static (serverul nu rulează PHP pentru ea).
        file_put_contents($fisierStatic, $cerere('GET', '/')['corp']);
        $dosarCapturi = $dosarDev . '/statistici';
        @mkdir($dosarCapturi, 0775, true);
        $inainte = $numar();
        $env = ['CMS_DEV_URL=' . $baza, 'CMS_DEV_PAGINA=/' . basename($fisierStatic), 'CMS_DEV_ADMIN_EMAIL=' . $adminEmail, 'CMS_DEV_PAROLA=' . $parolaAdmin,
            'CMS_DEV_CAPTURI=' . $dosarCapturi, 'NO_PROXY=*', 'no_proxy=*',
            'NODE_PATH=' . (getenv('NODE_PATH') ?: '/opt/node22/lib/node_modules')];
        $iesire = [];
        exec('env -u http_proxy -u https_proxy -u HTTP_PROXY -u HTTPS_PROXY -u ALL_PROXY -u all_proxy ' . implode(' ', array_map('escapeshellarg', $env))
            . ' node ' . escapeshellarg(__DIR__ . '/statistici-browser.cjs') . ' 2>&1', $iesire, $codNode);
        $rezultate = json_decode((string) end($iesire), true);
        if (!is_array($rezultate) || $rezultate === []) {
            $verifica('Chromium a rulat', false, 'cod ' . $codNode . ': ' . implode("\n", array_slice($iesire, -15)));
        } else {
            foreach ($rezultate as $rezultat) {
                $verifica((string) $rezultat['eticheta'], (bool) $rezultat['ok'], (string) $rezultat['detalii']);
            }
            $randCdn = $db->prepare('SELECT tip, cale, durata FROM statistici_evenimente WHERE cale = ? ORDER BY id');
            $randCdn->execute(['/' . basename($fisierStatic)]);
            $randuriCdn = $randCdn->fetchAll(PDO::FETCH_ASSOC);
            $verifica('pagina statică („din CDN”): evenimentul paginii văzute a ajuns în bază, iar la ascunderea filei, timpul activ',
                count(array_filter($randuriCdn, static fn ($r) => (int) $r['tip'] === 1)) === 1
                && count(array_filter($randuriCdn, static fn ($r) => (int) $r['tip'] === 2)) >= 1, json_encode($randuriCdn));
            $scrie('  INFO  capturile: ' . $dosarCapturi);
        }
    }
} catch (Throwable $e) {
    $verifica('testul a rulat până la capăt', false, get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

// ── E. Curățenia ─────────────────────────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== E. Curățenia');
$puneLaLoc();
$verifica('baza pusă la loc: CHECKSUM TABLE identic pe fiecare tabel', $sumeBaza() === $sumeInainte,
    implode(', ', array_keys(array_diff_assoc($sumeBaza(), $sumeInainte))));
$verifica('fișierul static al testului șters', !is_file($fisierStatic));
$scrie('');
$scrie('Rezultat: ' . $trecute . ' trecute, ' . $esuate . ' eșuate. Raport: ' . $fisierRaport);
exit($esuate === 0 ? 0 : 1);

/** Un tabel al tabloului, ca în controller (pentru partea B, fără server). */
function StatisticiController_tabel(PDO $db, string $cheie, array $perioada, array $filtre): array
{
    return \App\Http\Controllers\StatisticiController::tabel($db, $cheie, $perioada, $filtre);
}
