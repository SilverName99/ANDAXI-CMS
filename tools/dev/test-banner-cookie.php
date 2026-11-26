<?php

declare(strict_types=1);

/**
 * Testele bannerului de cookie-uri pe categorii (docs/PLUGINURI.md, „Bannerul de cookie-uri”): App\Support\CookieConsent
 * (formatul `2.<oferite>.<acceptate>`, valorile vechi = fără răspuns, categoria nouă cerută din nou, cheia cache-ului),
 * App\Support\ServiciiCookie (fiecare serviciu într-o singură categorie, categoriile active, amprenta, politica generată) și,
 * cu serverul de dezvoltare pe baza cu datele demo (îl pornește dacă nu merge), paginile pe fiecare stare a acordului.
 *
 *   tools/dev/php.sh tools/dev/test-banner-cookie.php [--fara-browser]
 *
 *  A. Fără server: citirea și scrierea valorii, permite/aRaspuns/cheieCache, maparea serviciilor pe categorii (cu fiecare
 *     tracker pornit în setări), amprenta, culorile butoanelor, HTML-ul politicii (escapat).
 *  B. Cu serverul, cu GA, GTM, Google Ads, Clarity, chatul tawk.to și un articol cu un videoclip YouTube (identificatori
 *     inventați): fără acord și cu valorile vechi (all, necessary) nimic din ele în pagină și bannerul arătat; fiecare categorie
 *     pornește doar serviciile ei; modul de consimțământ Google înaintea etichetelor; o categorie nouă cere bannerul din nou;
 *     cookie-urile de atribuire șterse fără acord; cache-ul de pagini separat pe starea acordului; textul implicit, culoarea,
 *     bannerul oprit (și avertismentul roșu din admin); generarea paginii „Politica de cookie-uri” și avertismentul „veche”.
 *  C. În Chromium (tools/dev/banner-cookie-browser.cjs; --fara-browser îl sare): nicio cerere spre terți înainte de acord,
 *     butoanele de aceeași mărime (1366 și 390 px), tastatura, setările pe categorii, butonul din colț, retragerea, linkul din
 *     politică, capturile (date demo).
 *
 * Setările, paginile și articolul scrise de test se pun la loc la final. Raportul: $CMS_DEV_DIR/test-banner-cookie-raport.txt;
 * cod de ieșire 0 doar dacă totul a trecut. Date inventate.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';

use App\Support\CookieConsent;
use App\Support\Database;
use App\Support\ServiciiCookie;

$faraBrowser = in_array('--fara-browser', $argv, true);
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-banner-cookie-raport.txt';
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
$scrie('Test banner cookie-uri — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

// =====================================================================
// A. Fără server
// =====================================================================
$scrie('');
$scrie('== A. Fără server: CookieConsent, ServiciiCookie');

$cazuri = [
    '2.sme.s' => ['oferite' => ['statistici', 'marketing', 'extern'], 'acceptate' => ['statistici']],
    '2.sme.-' => ['oferite' => ['statistici', 'marketing', 'extern'], 'acceptate' => []],
    '2.es.se' => ['oferite' => ['statistici', 'extern'], 'acceptate' => ['statistici', 'extern']],
    '2.s.s' => ['oferite' => ['statistici'], 'acceptate' => ['statistici']],
];
$gresite = [];
foreach ($cazuri as $valoare => $asteptat) {
    if (CookieConsent::citeste($valoare) !== $asteptat) {
        $gresite[] = $valoare . ' → ' . json_encode(CookieConsent::citeste($valoare));
    }
}
foreach (['all', 'necessary', '', '1.sme.s', '2.sme', '2.s.m', '2.ss.s', '2.sx.s', '2.sme.ss', '2..-', '2.sme.s;x', ' all'] as $rea) {
    if (CookieConsent::citeste($rea) !== null) {
        $gresite[] = '„' . $rea . '” citit ca alegere';
    }
}
$verifica('A1 citeste: valorile formatului 2 (ordinea normalizată), iar cele vechi (all, necessary) și cele stricate = fără alegere',
    $gresite === [], implode('; ', $gresite));
$verifica('A2 valoare(): „Accept toate” = 2.sme.sme, refuzul = 2.sme.-, doar categoriile oferite; citeste(valoare(x)) = x',
    CookieConsent::valoareToate() === '2.sme.sme' && CookieConsent::valoare([]) === '2.sme.-'
    && CookieConsent::valoare(['extern', 'statistici'], ['statistici', 'extern']) === '2.se.se'
    && CookieConsent::valoare(['marketing'], ['statistici']) === '2.s.-'
    && CookieConsent::citeste(CookieConsent::valoare(['marketing'])) === ['oferite' => ['statistici', 'marketing', 'extern'], 'acceptate' => ['marketing']]);

$cuCookie = static function (?string $valoare): void {
    if ($valoare === null) {
        unset($_COOKIE[CookieConsent::nume()]);
    } else {
        $_COOKIE[CookieConsent::nume()] = $valoare;
    }
};
$stari = [];
foreach ([null, 'all', 'necessary', '2.sme.-', '2.sme.s', '2.sme.m', '2.sme.e', '2.sme.sme', '2.sm.sm', '2.ms.ms'] as $v) {
    $cuCookie($v);
    $stari[$v ?? 'fara'] = [
        'p' => array_map(static fn (string $c): int => (int) CookieConsent::permite($c), ['necesare' => 'necesare', 'statistici' => 'statistici',
            'marketing' => 'marketing', 'extern' => 'extern']),
        'r3' => CookieConsent::aRaspuns(['statistici', 'marketing', 'extern']),
        'r2' => CookieConsent::aRaspuns(['statistici', 'marketing']),
        'k' => CookieConsent::cheieCache(),
    ];
}
$cuCookie(null);
$verifica('A3 fără cookie și cu valorile vechi: doar „necesare”, fără răspuns, cheia cache-ului „nou”',
    $stari['fara']['p'] === ['necesare' => 1, 'statistici' => 0, 'marketing' => 0, 'extern' => 0] && $stari['all']['p'] === $stari['fara']['p']
    && $stari['necessary']['p'] === $stari['fara']['p'] && !$stari['all']['r2'] && !$stari['necessary']['r2'] && !$stari['fara']['r2']
    && $stari['all']['k'] === 'nou' && $stari['necessary']['k'] === 'nou' && $stari['fara']['k'] === 'nou', json_encode($stari));
$verifica('A4 fiecare categorie doar cu acordul ei (s, m, e, toate, refuz)',
    $stari['2.sme.s']['p'] === ['necesare' => 1, 'statistici' => 1, 'marketing' => 0, 'extern' => 0]
    && $stari['2.sme.m']['p'] === ['necesare' => 1, 'statistici' => 0, 'marketing' => 1, 'extern' => 0]
    && $stari['2.sme.e']['p'] === ['necesare' => 1, 'statistici' => 0, 'marketing' => 0, 'extern' => 1]
    && $stari['2.sme.sme']['p'] === ['necesare' => 1, 'statistici' => 1, 'marketing' => 1, 'extern' => 1]
    && $stari['2.sme.-']['p'] === $stari['fara']['p'] && $stari['2.sme.-']['r3'], json_encode($stari));
$verifica('A5 o categorie apărută după alegere cere bannerul din nou, iar cele acceptate atunci rămân în vigoare',
    !$stari['2.sm.sm']['r3'] && $stari['2.sm.sm']['r2'] && $stari['2.sm.sm']['p']['statistici'] === 1 && $stari['2.sm.sm']['p']['extern'] === 0);
$chei = array_map(static fn (array $s): string => $s['k'], $stari);
$verifica('A6 cheia cache-ului: alta pentru fiecare alegere, aceeași pentru aceeași alegere scrisă în altă ordine (2.sm.sm = 2.ms.ms)',
    count(array_unique([$chei['fara'], $chei['2.sme.-'], $chei['2.sme.s'], $chei['2.sme.m'], $chei['2.sme.e'], $chei['2.sme.sme'], $chei['2.sm.sm']])) === 7
    && $chei['2.sm.sm'] === $chei['2.ms.ms'], json_encode($chei));

$toatePornite = [
    'google_analytics_enabled' => '1', 'google_analytics_id' => 'G-TESTCC01', 'google_tag_manager_enabled' => '1', 'google_tag_manager_id' => 'GTM-TESTCC1',
    'google_ads_enabled' => '1', 'google_ads_conversion_id' => 'AW-1234567', 'microsoft_clarity_enabled' => '1', 'microsoft_clarity_project_id' => 'claritytcc',
    'tawk_enabled' => '1', 'tawk_property_id' => 'tawktestcc1', 'tawk_requires_consent' => '1', 'welcome_popup_enabled' => '1',
];
$serviciiToate = ServiciiCookie::servicii(array_merge(\App\Support\Settings::all(null), $toatePornite), null);
$categorieDe = [];
foreach ($serviciiToate as $s) {
    $categorieDe[$s['id']][] = $s['categorie'];
}
$asteptate = ['sesiune' => 'necesare', 'consimtamant' => 'necesare', 'anunt' => 'necesare', 'google_analytics' => 'statistici',
    'google_tag_manager' => 'statistici', 'clarity' => 'statistici', 'google_ads' => 'marketing', 'tawk' => 'extern'];
if (\App\Support\Plugins::vizibil('magazin')) {
    $asteptate['sursa_comenzii'] = 'statistici';
}
if (\App\Support\Plugins::vizibil('newsletter')) {
    $asteptate['newsletter_campanie'] = 'statistici';
}
$gresite = [];
foreach ($asteptate as $id => $cat) {
    if (($categorieDe[$id] ?? []) !== [$cat]) {
        $gresite[] = $id . ' → ' . json_encode($categorieDe[$id] ?? null) . ' (așteptat ' . $cat . ')';
    }
}
foreach ($categorieDe as $id => $cat) {
    if (count($cat) !== 1 || !isset(ServiciiCookie::CATEGORII[$cat[0]])) {
        $gresite[] = $id . ' în ' . json_encode($cat);
    }
}
$verifica('A7 fiecare serviciu într-o singură categorie: GA, GTM, Clarity, sursa comenzii, campania de newsletter = Statistici; '
    . 'Google Ads = Marketing; chatul cu acord = Conținut extern; sesiunea, acordul, anunțul = Necesare', $gresite === [], implode('; ', $gresite));
$faraNimic = ServiciiCookie::servicii(array_merge(\App\Support\Settings::all(null), ['sursa_comenzii' => '0']), null);
$verifica('A8 categoriile active: toate trei cu totul pornit; fără trackeri doar ce au pluginurile (fără Marketing, fără Conținut extern)',
    ServiciiCookie::categoriiActive([], null, $serviciiToate) === ['statistici', 'marketing', 'extern']
    && !in_array('marketing', ServiciiCookie::categoriiActive([], null, $faraNimic), true)
    && !in_array('extern', ServiciiCookie::categoriiActive([], null, $faraNimic), true),
    json_encode(ServiciiCookie::categoriiActive([], null, $faraNimic)));
$chatOptional = ServiciiCookie::servicii(array_merge(\App\Support\Settings::all(null), $toatePornite, ['tawk_requires_consent' => '0']), null);
$verifica('A9 chatul pornit „Întotdeauna” (considerat strict necesar) e în Necesare, nu în Conținut extern',
    in_array(['tawk', 'necesare'], array_map(static fn (array $s): array => [$s['id'], $s['categorie']], $chatOptional), true)
    && !in_array('extern', ServiciiCookie::categoriiActive([], null, $chatOptional), true));
$verifica('A10 amprenta: aceeași pentru aceleași servicii (altă ordine), alta când se schimbă un serviciu',
    ServiciiCookie::amprenta($serviciiToate) === ServiciiCookie::amprenta(array_reverse($serviciiToate))
    && ServiciiCookie::amprenta($serviciiToate) !== ServiciiCookie::amprenta(array_slice($serviciiToate, 1)));
$verifica('A11 culoarea butoanelor: #rrggbb din setări, altfel culoarea temei; textul alb pe verde închis, închis pe galben',
    ServiciiCookie::culoareButoane(['cookie_banner_culoare' => '#1A2B3C']) === '#1a2b3c'
    && ServiciiCookie::culoareButoane(['cookie_banner_culoare' => 'red;}body{']) === \App\Support\Tema::culoarePrincipala()
    && ServiciiCookie::culoareButoane([]) === \App\Support\Tema::culoarePrincipala()
    && ServiciiCookie::culoareTextPe('#2f8d5b') === '#ffffff' && ServiciiCookie::culoareTextPe('#ffd400') === '#111827');
$html = ServiciiCookie::htmlPolitica(['gdpr_operator_nume' => 'Firma Demo <script>x()</script> SRL', 'gdpr_operator_cui' => 'RO000',
    'gdpr_operator_email' => 'contact@exemplu.ro'], $serviciiToate, '01.12.2026');
$verifica('A12 politica: fiecare cookie (nume, furnizor, durată, categorie), datele firmei escapate, linkul care redeschide setările',
    str_contains($html, '<code>_ga</code>') && str_contains($html, '<code>_clck</code>') && str_contains($html, '<code>_gcl_au, _gcl_aw</code>')
    && str_contains($html, 'tawk.to inc. (SUA)') && str_contains($html, '<td>2 ani</td>') && str_contains($html, '<td>Marketing</td>')
    && str_contains($html, '<h3>Conținut extern</h3>') && str_contains($html, 'Firma Demo &lt;script&gt;x()&lt;/script&gt; SRL')
    && !str_contains($html, '<script>') && str_contains($html, 'href="#setari-cookie" data-cookie-setari')
    && str_contains($html, 'mailto:contact@exemplu.ro'));

// =====================================================================
// B. Cu serverul
// =====================================================================
$scrie('');
$scrie('== B. Cu serverul de dezvoltare');

$port = (string) (getenv('CMS_DEV_PORT') ?: '8701');
$baza = 'http://127.0.0.1:' . $port;
$cerere = static function (string $metoda, string $cale, ?string $jar = null, array $date = [], ?string $cookie = null) use ($baza): array {
    $c = curl_init($baza . $cale);
    $antete = [];
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => array_values(array_filter(['Origin: ' . $baza, 'Sec-Fetch-Site: same-origin', $cookie !== null ? 'Cookie: ' . $cookie : ''])),
        CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$antete): int {
            $antete[] = trim($linie);
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
    $antet = static function (string $nume) use ($antete): string {
        foreach ($antete as $linie) {
            if (stripos($linie, $nume . ':') === 0) {
                return trim(substr($linie, strlen($nume) + 1));
            }
        }
        return '';
    };
    $cookies = array_values(array_map(static fn (string $l): string => trim(substr($l, 11)),
        array_filter($antete, static fn (string $l): bool => stripos($l, 'set-cookie:') === 0)));
    return ['cod' => $cod, 'corp' => $corp, 'cache' => $antet('X-Page-Cache'), 'cookies' => $cookies];
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
if (!$db instanceof PDO) {
    $scrie('EROARE: baza de test nu răspunde.');
    exit(1);
}
$dosarJar = $dosarDev . '/jar-banner-cookie-' . getmypid();
@mkdir($dosarJar, 0700, true);
$parola = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));

$cheiAtinse = array_merge(array_keys($toatePornite), ['cookie_banner_enabled', 'cookie_banner_text', 'cookie_banner_culoare', 'cookie_banner_policy_url',
    'cookie_politica_pagina_id', 'cookie_politica_url', 'cookie_politica_amprenta', 'cache_pagini_extins', 'sursa_comenzii']);
$setariInainte = [];
foreach ($cheiAtinse as $cheie) {
    $stmt = $db->prepare('SELECT value FROM settings WHERE `key` = ?');
    $stmt->execute([$cheie]);
    $v = $stmt->fetchColumn();
    $setariInainte[$cheie] = $v === false ? null : (string) $v;
}
$maxPagina = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM pages')->fetchColumn();
$maxPost = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM blog_posts')->fetchColumn();
$puneLaLoc = static function () use ($db, $setariInainte, $maxPagina, $maxPost, $dosarJar, $amPornitServerul, $radacina): void {
    foreach ($setariInainte as $cheie => $valoare) {
        if ($valoare === null) {
            $db->prepare('DELETE FROM settings WHERE `key` = ?')->execute([$cheie]);
        } else {
            \App\Support\Settings::save($db, [$cheie => $valoare]);
        }
    }
    $db->prepare('DELETE FROM pages WHERE id > ?')->execute([$maxPagina]);
    $db->prepare('DELETE FROM blog_posts WHERE id > ?')->execute([$maxPost]);
    \App\Support\ResponseCache::purgePageCache();
    foreach (glob($dosarJar . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dosarJar);
    if ($amPornitServerul) {
        exec(escapeshellarg($radacina . '/tools/dev/server.sh') . ' --opreste >/dev/null 2>&1');
    }
};
register_shutdown_function($puneLaLoc);
$seteaza = static function (array $valori) use ($db): void {
    \App\Support\Settings::save($db, $valori);
    \App\Support\ResponseCache::purgePageCache();
};

$seteaza(['cookie_banner_enabled' => '1', 'cookie_banner_text' => 'Folosim cookie-uri (text de test).', 'cookie_banner_culoare' => '',
    'cookie_politica_url' => '', 'cookie_politica_pagina_id' => '', 'cookie_politica_amprenta' => '', 'cache_pagini_extins' => '0'] + array_merge($toatePornite, ['welcome_popup_enabled' => '0']));
$slugPost = 'test-banner-cookie-video';
$db->prepare("INSERT INTO blog_posts (title, slug, excerpt, content, published_at, is_published, video_url) VALUES (?, ?, '', ?, NOW(), 1, ?)")
    ->execute(['Videoclip demo pentru bannerul de cookie-uri', $slugPost, '<p>Text demo.</p>', 'https://www.youtube.com/watch?v=AbCdEf12345']);
$nume = CookieConsent::nume();
$cu = static fn (string $valoare): string => $nume . '=' . $valoare;

$etichete = [
    'gtm' => 'googletagmanager.com/gtm.js',
    'ga' => "gtag('config', 'G-TESTCC01')",
    'ads' => "gtag('config', 'AW-1234567')",
    'gtag' => 'googletagmanager.com/gtag/js',
    'clarity' => 'clarity.ms/tag/',
    'tawk' => 'embed.tawk.to/tawktestcc1',
    'consent' => "gtag('consent', 'default'",
];
$gasite = static function (string $corp) use ($etichete): array {
    return array_keys(array_filter($etichete, static fn (string $t): bool => str_contains($corp, $t)));
};
$bannerVizibil = static fn (string $corp): bool => preg_match('/<div id="cookie-banner"[^>]*data-ax-cc-banner>/', $corp) === 1;
$tabVizibil = static fn (string $corp): bool => preg_match('/<button type="button" class="ax-cc-tab[^"]*"[^>]*title="Setări cookie-uri">/', $corp) === 1;

$fara = $cerere('GET', '/');
$verifica('B1 fără acord: niciun serviciu (GTM, gtag, GA, Ads, Clarity, tawk.to, nici modul de consimțământ), bannerul arătat cu '
    . 'cele trei categorii, butonul din colț ascuns', $fara['cod'] === 200 && $gasite($fara['corp']) === [] && $bannerVizibil($fara['corp'])
    && substr_count($fara['corp'], 'data-ax-cc-categorie="') === 3 && !$tabVizibil($fara['corp'])
    && !preg_match('/data-ax-cc-categorie="[a-z]+" aria-describedby="[^"]+" checked/', $fara['corp']),
    json_encode($gasite($fara['corp'])));
$verifica('B2 cele trei butoane, în ordine: „Accept toate”, „Refuz” (aceeași clasă, plină) și „Setări”',
    preg_match('/data-ax-cc="toate">Accept toate<\/button>\s*<button type="button" class="ax-cc__buton ax-cc__buton--plin" data-ax-cc="refuz">Refuz<\/button>\s*'
        . '<button type="button" class="ax-cc__buton ax-cc__buton--contur" data-ax-cc="setari"/', $fara['corp']) === 1
    && str_contains($fara['corp'], 'class="ax-cc__buton ax-cc__buton--plin" data-ax-cc="toate"'));
foreach (['all', 'necessary'] as $vechi) {
    $r = $cerere('GET', '/', null, [], $cu($vechi));
    $verifica('B3 valoarea veche „' . $vechi . '” = fără răspuns: bannerul nou, niciun serviciu', $gasite($r['corp']) === [] && $bannerVizibil($r['corp'])
        && !$tabVizibil($r['corp']), json_encode($gasite($r['corp'])));
}
$asteptat = [
    '2.sme.s' => ['gtm', 'ga', 'gtag', 'clarity', 'consent'],
    '2.sme.m' => ['ads', 'gtag', 'consent'],
    '2.sme.e' => ['tawk'],
    '2.sme.sme' => array_keys($etichete),
    '2.sme.-' => [],
];
foreach ($asteptat as $valoare => $lista) {
    $r = $cerere('GET', '/', null, [], $cu($valoare));
    $g = $gasite($r['corp']);
    sort($g);
    sort($lista);
    $verifica('B4 acordul ' . $valoare . ': exact ' . ($lista === [] ? 'nimic' : implode(', ', $lista)) . '; bannerul ascuns, butonul din colț arătat',
        $g === $lista && !$bannerVizibil($r['corp']) && $tabVizibil($r['corp']), json_encode($g));
}
$s = $cerere('GET', '/', null, [], $cu('2.sme.s'))['corp'];
$pozConsent = strpos($s, "gtag('consent', 'default'");
$pozGtm = strpos($s, 'googletagmanager.com/gtm.js');
$verifica('B5 modul de consimțământ Google v2: implicit totul refuzat, apoi analytics granted și ad_* denied, înaintea lui GTM și gtag',
    $pozConsent !== false && $pozGtm !== false && $pozConsent < $pozGtm && $pozConsent < (int) strpos($s, 'googletagmanager.com/gtag/js')
    && str_contains($s, "ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied'")
    && str_contains($s, "gtag('consent', 'update', {analytics_storage: 'granted', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied'})"));
$verifica('B6 paza cookie-ului (o pagină de dinaintea unei retrageri se reîncarcă) e în <head>, înaintea etichetelor, doar cu ceva acceptat',
    ($p = strpos($s, "sessionStorage.setItem('ax_cc_paza',v)")) !== false && $p < $pozConsent && str_contains($s, "'2.sme.s');</script>")
    && !str_contains($fara['corp'], 'ax_cc_paza') && !str_contains($cerere('GET', '/', null, [], $cu('2.sme.-'))['corp'], 'ax_cc_paza'));
// Playerul (VideoEmbed, în articole și în evenimentul următor, cu {{blog_video}} în șablon): după acordul cererii.
$player = static function (?string $valoare) use ($cuCookie): string {
    $cuCookie($valoare);
    $html = \App\Support\VideoEmbed::render('https://www.youtube.com/watch?v=AbCdEf12345');
    $cuCookie(null);
    return $html;
};
ServiciiCookie::uitaCitirile();
$video = ServiciiCookie::videoExtern($db);
$verifica('B7 videoclipul YouTube: articolul publicat face activ „Conținut extern”; fără acordul lui (și cu doar Statistici) caseta '
    . '„Pornește videoclipul”, cu el playerul', $video['youtube'] && !$video['vimeo']
    && str_contains($player(null), 'Pornește videoclipul') && str_contains($player('2.sme.s'), 'Pornește videoclipul')
    && str_contains($player('2.sme.e'), '<iframe') && str_contains($player('2.sme.e'), 'youtube-nocookie.com/embed/AbCdEf12345')
    && !str_contains($player('2.sme.e'), 'Pornește videoclipul'));
$noua = $cerere('GET', '/', null, [], $cu('2.sm.sm'));
$verifica('B8 categorie nouă (Conținut extern apărută după alegere): bannerul din nou, cu Statistici și Marketing bifate, Conținut extern nu; '
    . 'până la alegere, Statistici și Marketing rămân, tawk.to nu', $bannerVizibil($noua['corp'])
    && str_contains($noua['corp'], 'data-ax-cc-categorie="statistici" aria-describedby="ax-cc-d-statistici" checked')
    && str_contains($noua['corp'], 'data-ax-cc-categorie="marketing" aria-describedby="ax-cc-d-marketing" checked')
    && str_contains($noua['corp'], 'data-ax-cc-categorie="extern" aria-describedby="ax-cc-d-extern">')
    && in_array('ga', $gasite($noua['corp']), true) && !in_array('tawk', $gasite($noua['corp']), true));
$curat = $cerere('GET', '/', null, [], $cu('2.sme.-') . '; ax_sursa=google; bv_gclid=TestCC1; nl_cid=3');
$sterse = array_filter($curat['cookies'], static fn (string $c): bool => preg_match('/^(ax_sursa|bv_gclid|nl_cid)=(deleted)?;/', $c) === 1);
$verifica('B9 fără acordul pentru Statistici: cookie-urile de atribuire rămase (ax_sursa, bv_gclid, nl_cid) se șterg', count($sterse) === 3,
    implode(' | ', $curat['cookies']));
$gclidFara = $cerere('GET', '/?gclid=TestCC2', null, [], $cu('2.sme.m'));
$gclidCu = $cerere('GET', '/?gclid=TestCC3', null, [], $cu('2.sme.s'));
$verifica('B10 bv_gclid (atribuirea comenzii unei reclame) doar cu acordul pentru Statistici',
    array_filter($gclidFara['cookies'], static fn (string $c): bool => str_starts_with($c, 'bv_gclid=TestCC2')) === []
    && array_filter($gclidCu['cookies'], static fn (string $c): bool => str_starts_with($c, 'bv_gclid=TestCC3')) !== []);

// Cache-ul de pagini (extins: prima pagină intră în cache): fiecare stare a acordului are pagina ei.
$seteaza(['cache_pagini_extins' => '1']);
$c1 = $cerere('GET', '/', null, [], $cu('2.sme.sme'));
$c2 = $cerere('GET', '/', null, [], $cu('2.sme.sme'));
$c3 = $cerere('GET', '/', null, [], $cu('2.sme.-'));
$c4 = $cerere('GET', '/', null, [], $cu('2.sme.-'));
$c5 = $cerere('GET', '/');
$c6 = $cerere('GET', '/', null, [], $cu('all'));
$c7 = $cerere('GET', '/', null, [], $cu('2.sme.s'));
$verifica('B11 cache-ul de pagini: „Accept toate” MISS apoi HIT (cu etichete); refuzul are intrarea lui (MISS, apoi HIT, fără etichete); '
    . 'fără cookie și „all” vechi au aceeași pagină (cu bannerul, fără etichete); doar Statistici, alta',
    [$c1['cache'], $c2['cache'], $c3['cache'], $c4['cache'], $c5['cache'], $c6['cache'], $c7['cache']] === ['MISS', 'HIT', 'MISS', 'HIT', 'MISS', 'HIT', 'MISS']
    && count($gasite($c2['corp'])) === count($etichete) && $gasite($c4['corp']) === [] && $gasite($c6['corp']) === [] && $bannerVizibil($c6['corp'])
    && !in_array('ads', $gasite($c7['corp']), true),
    json_encode([$c1['cache'], $c2['cache'], $c3['cache'], $c4['cache'], $c5['cache'], $c6['cache'], $c7['cache']]));
$seteaza(['cache_pagini_extins' => '0']);

$seteaza(['cookie_banner_text' => '', 'cookie_banner_culoare' => '#123abc']);
$r = $cerere('GET', '/');
$verifica('B12 textul gol: bannerul arată textul implicit (nu se ascunde); culoarea din setări pe ambele butoane mari',
    $bannerVizibil($r['corp']) && str_contains($r['corp'], htmlspecialchars(ServiciiCookie::TEXT_IMPLICIT, ENT_QUOTES))
    && str_contains($r['corp'], '.ax-cc__buton--plin{background:#123abc;color:#ffffff;}'));
$seteaza(['cookie_banner_enabled' => '0']);
$r = $cerere('GET', '/');
$verifica('B13 bannerul oprit: nici banner, nici butonul din colț, și tot niciun serviciu fără acord', !str_contains($r['corp'], 'id="cookie-banner"')
    && !str_contains($r['corp'], 'ax-cc-tab') && $gasite($r['corp']) === []);

$jar = $dosarJar . '/admin.txt';
$login = $cerere('POST', '/admin/login', $jar, ['email' => 'admin@exemplu.ro', 'password' => $parola]);
$adminCookies = $cerere('GET', '/admin/settings/store?tab=cookies', $jar);
$verifica('B14 adminul, bannerul oprit cu servicii care cer acord: avertismentul roșu (role=alert) și lista serviciilor pe categorii',
    $login['cod'] === 302 && str_contains($adminCookies['corp'], 'id="cookie-banner-oprit" role="alert"')
    && str_contains($adminCookies['corp'], '<li><strong>Statistici</strong>: Google Analytics, Google Tag Manager, Microsoft Clarity')
    && str_contains($adminCookies['corp'], '<li><strong>Marketing</strong>: Google Ads</li>')
    && str_contains($adminCookies['corp'], '<li><strong>Conținut extern</strong>: Videoclipuri YouTube, Chatul live (tawk.to)</li>'),
    mb_substr(strip_tags($adminCookies['corp']), 0, 300));
$salvare = $cerere('POST', '/admin/settings/store', $jar, ['action' => 'save_cookie_banner', 'cookie_banner_enabled' => '1', 'cookie_banner_text' => '',
    'cookie_banner_policy_url' => '/confidentialitate-demo', 'cookie_banner_culoare' => '#ABCDEF']);
$dupa = \App\Support\Settings::all($db);
$salvareTema = $cerere('POST', '/admin/settings/store', $jar, ['action' => 'save_cookie_banner', 'cookie_banner_enabled' => '1', 'cookie_banner_text' => '',
    'cookie_banner_policy_url' => '/confidentialitate-demo', 'cookie_banner_culoare' => '#ABCDEF', 'cookie_banner_culoare_tema' => '1']);
$dupaTema = \App\Support\Settings::all($db);
$verifica('B15 salvarea bannerului: culoarea (#abcdef), iar cu bifa „culoarea principală” golită; bannerul pornit; fără avertisment',
    $salvare['cod'] === 302 && $dupa['cookie_banner_culoare'] === '#abcdef' && $salvareTema['cod'] === 302 && $dupaTema['cookie_banner_culoare'] === ''
    && $dupaTema['cookie_banner_enabled'] === '1' && !str_contains($cerere('GET', '/admin/settings/store?tab=cookies', $jar)['corp'], 'id="cookie-banner-oprit"'));
$gen = $cerere('POST', '/admin/settings/store', $jar, ['action' => 'genereaza_politica_cookie']);
$dupaGen = \App\Support\Settings::all($db);
$idPagina = (int) ($dupaGen['cookie_politica_pagina_id'] ?? 0);
$politica = $cerere('GET', (string) ($dupaGen['cookie_politica_url'] ?? '/x'));
$verifica('B16 „Generează pagina Politica de cookie-uri”: pagina publicată, cu fiecare serviciu activ (_ga, _clck, _gcl_au, tawk.to, YouTube)',
    $gen['cod'] === 302 && $idPagina > $maxPagina && ($dupaGen['cookie_politica_url'] ?? '') === '/' . ServiciiCookie::SLUG_POLITICA && $politica['cod'] === 200
    && str_contains($politica['corp'], '<code>_ga</code>') && str_contains($politica['corp'], '<code>_clck</code>')
    && str_contains($politica['corp'], '<code>_gcl_au, _gcl_aw</code>') && str_contains($politica['corp'], 'Videoclipuri YouTube')
    && str_contains($politica['corp'], 'tawk.to inc. (SUA)'), (string) $politica['cod']);
$prima = $cerere('GET', '/')['corp'];
$verifica('B17 bannerul are linkurile „Politica de cookie-uri” și „Politica de confidențialitate”',
    str_contains($prima, '<a href="/' . ServiciiCookie::SLUG_POLITICA . '">Politica de cookie-uri</a>')
    && str_contains($prima, '<a href="/confidentialitate-demo">Politica de confidențialitate</a>'));
$verifica('B18 fără schimbări de servicii, adminul nu spune că politica e veche', !str_contains($cerere('GET', '/admin/settings/store?tab=cookies', $jar)['corp'],
    'id="politica-cookie-veche"'));
$seteaza(['microsoft_clarity_enabled' => '0']);
$veche = $cerere('GET', '/admin/settings/store?tab=cookies', $jar)['corp'];
$cerere('POST', '/admin/settings/store', $jar, ['action' => 'genereaza_politica_cookie']);
$dupaGen2 = \App\Support\Settings::all($db);
$politica2 = $cerere('GET', '/' . ServiciiCookie::SLUG_POLITICA);
$verifica('B19 Clarity oprit: „Politica de cookie-uri e veche — generează-o din nou”; generată din nou, aceeași pagină, fără _clck, fără avertisment',
    str_contains($veche, 'id="politica-cookie-veche"') && str_contains($veche, 'Politica de cookie-uri e veche — generează-o din nou')
    && (int) $dupaGen2['cookie_politica_pagina_id'] === $idPagina && (int) $db->query('SELECT COUNT(*) FROM pages WHERE id > ' . $maxPagina)->fetchColumn() === 1
    && !str_contains($politica2['corp'], '<code>_clck</code>') && str_contains($politica2['corp'], '<code>_ga</code>')
    && !str_contains($cerere('GET', '/admin/settings/store?tab=cookies', $jar)['corp'], 'id="politica-cookie-veche"'));
$adminCuAcord = $cerere('GET', '/', $jar, [], null);
$verifica('B20 cu un admin în sesiune (N3), nimic de la terți, chiar dacă bannerul se arată', $gasite($adminCuAcord['corp']) === []);

// =====================================================================
// C. În Chromium
// =====================================================================
if (!$faraBrowser) {
    $scrie('');
    $scrie('== C. În Chromium (1366 și 390 px)');
    $seteaza(['microsoft_clarity_enabled' => '1', 'cookie_banner_text' => 'Folosim cookie-uri (text de test).']);
    $dosarCapturi = (string) (getenv('CMS_DEV_CAPTURI') ?: $dosarDev . '/capturi') . '/banner-cookie';
    @mkdir($dosarCapturi, 0775, true);
    $env = ['CMS_DEV_URL=' . $baza, 'CMS_DEV_COOKIE=' . $nume, 'CMS_DEV_POST=/blog/' . $slugPost, 'CMS_DEV_POLITICA=/' . ServiciiCookie::SLUG_POLITICA,
        'CMS_DEV_CAPTURI=' . $dosarCapturi, 'NO_PROXY=*', 'no_proxy=*'];
    $iesire = [];
    exec('env -u http_proxy -u https_proxy -u HTTP_PROXY -u HTTPS_PROXY -u ALL_PROXY -u all_proxy ' . implode(' ', array_map('escapeshellarg', $env))
        . ' node ' . escapeshellarg(__DIR__ . '/banner-cookie-browser.cjs') . ' 2>&1', $iesire, $codNode);
    $rezultate = json_decode((string) end($iesire), true);
    if (!is_array($rezultate) || $rezultate === []) {
        $verifica('Chromium a rulat', false, 'cod ' . $codNode . ': ' . implode("\n", array_slice($iesire, -15)));
    } else {
        foreach ($rezultate as $rezultat) {
            $verifica('C ' . (string) ($rezultat['eticheta'] ?? '?'), ($rezultat['ok'] ?? false) === true, (string) ($rezultat['detalii'] ?? ''));
        }
    }
}

$scrie('');
$scrie('Rezumat: ' . $trecute . ' trecute, ' . $esuate . ' eșuate.');
exit($esuate === 0 ? 0 : 1);
