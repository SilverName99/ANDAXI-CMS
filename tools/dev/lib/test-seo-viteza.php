<?php

declare(strict_types=1);

/**
 * tools/dev/test-seo.php, secțiunea E4 (WP-40, SEO partea B, B5/B6/B8/B9): pozele în mărimile ecranului, sesiunea doar la
 * nevoie, cache-ul de pagini extins, CSS-ul și JavaScript-ul șablonului în fișiere, fonturile din nucleu, HSTS, fluxul
 * Merchant pornit/oprit și sursa comenzilor. Inclus de test-seo.php după E3, cu variabilele lui ($verifica, $scrie,
 * $cerere, $port, $db, $radacina, $jarNou, $adminEmail, $parola, $locatie). Fiecare comutator se pune la loc la final.
 *
 *  1. B6 sesiunea: o pagină publică (prima pagină, magazinul, un produs, o categorie, blogul, contactul, sumarul coșului)
 *     cerută fără cookie nu face niciun fișier de sesiune și nu trimite cookie; un produs pus în coș (POST) pornește
 *     sesiunea, iar coșul rămâne la pagina următoare; cu comutatorul oprit, fiecare pagină pornește sesiunea, ca înainte.
 *  2. B6 cache-ul de pagini extins: a doua cerere a unei pagini publice e din cache (X-Page-Cache: HIT); cine are coș nu
 *     primește pagina din cache; oprit, nimic din cache.
 *  3. B6 CSS/JS: cu comutatorul, pagina leagă fișierele minificate (la zi față de sursele lor; fiecare răspunde 200, cu
 *     Cache-Control-ul lui) și nu mai are blocurile în ea; oprit, blocurile sunt în pagină octet cu octet ca sursele lor.
 *  4. B6 fonturile din nucleu: aceleași familii și greutăți ca cererea spre Google Fonts; nicio legătură spre Google pe un
 *     site fără temă; HSTS doar pe https; regula „immutable” pentru /assets/…?v= în .htaccess.
 *  5. B5 pozele: după trecerea o dată prin poze, fiecare poză demo are variantele ei; cu comutatorul, srcset pe liste și pe
 *     produs; oprit, pagina e octet cu octet cea fără srcset.
 *  6. B8 fluxul: fiecare produs are câmpurile cerute de Merchant Center (id, title, link, image_link, price, availability;
 *     gtin când are EAN); oprit, 404.
 *  7. B9 sursa comenzii: felul vizitei (reclamă, email, campanie, Google, alt site, direct) și cookie-ul lui; sursa comenzii
 *     din cookie-uri; o comandă plasată de un vizitator venit din Google are sursa „google”; raportul numără comenzile demo
 *     pe surse, fără nicio dată a unui client.
 */

use App\Support\FluxGoogleMerchant;
use App\Support\SablonSite;
use App\Support\Settings;
use App\Support\SursaComenzii;
use App\Support\VarianteImagini;

$scrie('');
$scrie('== E4. Viteza, pozele, fluxul Merchant, sursa comenzilor (WP-40)');

$cheiViteza = ['sesiune_doar_la_nevoie', 'cache_pagini_extins', 'css_js_in_fisiere', 'imagini_srcset', 'merchant_feed', 'sursa_comenzii',
    'cache_pages_enabled'];
$vitezaInainte = array_intersect_key(Settings::all($db), array_flip($cheiViteza));
$seteaza = static function (array $valori) use ($db): void {
    Settings::save($db, $valori);
    \App\Support\ResponseCache::purgePageCache();
};
$dosarSesiuni = \App\Support\Paths::stocareSite('sessions');
$numarSesiuni = static fn (): int => count(glob($dosarSesiuni . '/sess_*') ?: []);
$paginiPublice = ['/', '/magazin', '/produs/ceai-de-menta', '/categorie/ceaiuri', '/blog', '/contact', '/api/cart/summary'];

try {
    // -----------------------------------------------------------------------------------------------------------------
    // 1. Sesiunea doar la nevoie.
    // -----------------------------------------------------------------------------------------------------------------
    $seteaza(['sesiune_doar_la_nevoie' => '1', 'cache_pagini_extins' => '0', 'cache_pages_enabled' => '0']);
    $inainte = $numarSesiuni();
    $cuCookie = [];
    foreach ($paginiPublice as $cale) {
        $r = $cerere($port, 'GET', $cale);
        if ($r['cod'] !== 200 || array_filter($r['cookies'], static fn (string $c): bool => str_starts_with($c, session_name() . '=')) !== []) {
            $cuCookie[] = $cale . ' (' . $r['cod'] . ')';
        }
    }
    $verifica('sesiunea doar la nevoie: ' . count($paginiPublice) . ' pagini publice cerute fără cookie (și sumarul coșului) nu fac niciun fișier '
        . 'de sesiune și nu trimit cookie-ul ei', $cuCookie === [] && $numarSesiuni() === $inainte, implode(', ', $cuCookie) . ' sesiuni '
        . $inainte . ' → ' . $numarSesiuni());
    $jarCos = $jarNou('viteza-cos');
    $idMenta = (int) $db->query("SELECT id FROM products WHERE slug = 'ceai-de-menta'")->fetchColumn();
    $adauga = $cerere($port, 'POST', '/cos/adauga/' . $idMenta, [], $jarCos, ['quantity' => '1']);
    $sumar = json_decode($cerere($port, 'GET', '/api/cart/summary', [], $jarCos)['corp'], true);
    $verifica('un produs pus în coș (POST) pornește sesiunea: un fișier nou, iar coșul are produsul la cererea următoare',
        $numarSesiuni() === $inainte + 1 && (int) ($sumar['count'] ?? $sumar['cart_count'] ?? $sumar['items_count'] ?? 0) >= 1,
        $adauga['cod'] . ' ' . json_encode($sumar));
    $seteaza(['sesiune_doar_la_nevoie' => '0']);
    $inainte = $numarSesiuni();
    $r = $cerere($port, 'GET', '/magazin');
    $verifica('comutatorul oprit (Bioscem, înghețat): fiecare pagină pornește sesiunea, ca înainte', $numarSesiuni() === $inainte + 1
        && array_filter($r['cookies'], static fn (string $c): bool => str_starts_with($c, session_name() . '=')) !== []);
    $seteaza(['sesiune_doar_la_nevoie' => '1']);

    // -----------------------------------------------------------------------------------------------------------------
    // 2. Cache-ul de pagini extins.
    // -----------------------------------------------------------------------------------------------------------------
    $seteaza(['cache_pagini_extins' => '1']);
    $prima = $cerere($port, 'GET', '/categorie/ceaiuri');
    $aDoua = $cerere($port, 'GET', '/categorie/ceaiuri');
    $cuCos = $cerere($port, 'GET', '/categorie/ceaiuri', [], $jarCos);
    $altaPagina = $cerere($port, 'GET', '/contact');
    $cerere($port, 'GET', '/contact');
    $contact2 = $cerere($port, 'GET', '/contact');
    $verifica('cache-ul de pagini extins: a doua cerere a unei categorii e din cache (HIT), cine are coș o primește proaspătă, /contact nu e în listă',
        ($prima['antete']['x-page-cache'] ?? '') !== 'HIT' && ($aDoua['antete']['x-page-cache'] ?? '') === 'HIT' && $aDoua['corp'] === $prima['corp']
        && ($cuCos['antete']['x-page-cache'] ?? '') !== 'HIT' && ($contact2['antete']['x-page-cache'] ?? '') !== 'HIT' && $altaPagina['cod'] === 200,
        json_encode([$prima['antete']['x-page-cache'] ?? '', $aDoua['antete']['x-page-cache'] ?? '', $cuCos['antete']['x-page-cache'] ?? '']));
    $seteaza(['cache_pagini_extins' => '0']);
    $cerere($port, 'GET', '/categorie/ceaiuri');
    $verifica('cache-ul extins oprit (Bioscem): nicio pagină din cache fără regulile din admin',
        ($cerere($port, 'GET', '/categorie/ceaiuri')['antete']['x-page-cache'] ?? '') !== 'HIT');

    // -----------------------------------------------------------------------------------------------------------------
    // 3. CSS-ul și JavaScript-ul șablonului.
    // -----------------------------------------------------------------------------------------------------------------
    $laZi = [];
    foreach (SablonSite::SURSE as $nume) {
        $sursa = (string) file_get_contents(SablonSite::sursa($nume));
        $min = str_ends_with($nume, '.css') ? SablonSite::minificaCss($sursa) : SablonSite::minificaJs($sursa);
        if ((string) @file_get_contents($radacina . '/public' . SablonSite::caleMinificata($nume)) !== $min) {
            $laZi[] = $nume;
        }
    }
    $verifica('fișierele minificate ale șablonului sunt la zi față de sursele lor (tools/dev/minifica-sablon.php)', $laZi === [], implode(', ', $laZi));
    $seteaza(['css_js_in_fisiere' => '1']);
    $paginaNoua = $cerere($port, 'GET', '/magazin')['corp'];
    preg_match_all('#(?:href|src)="(/assets/(?:css/sablon-site|js/sablon/[a-z-]+)\.min\.(?:css|js)\?v=\d+)"#', $paginaNoua, $mFis);
    $raspunsuriFis = array_map(static fn (string $u): int => $cerere($port, 'GET', $u)['cod'], $mFis[1]);
    $verifica('cu comutatorul: pagina leagă CSS-ul și scripturile șablonului (' . count($mFis[1]) . ' fișiere, fiecare 200) și nu le mai are în ea',
        count($mFis[1]) >= 4 && array_unique($raspunsuriFis) === [200] && !str_contains($paginaNoua, '.bv-header-search-shell{')
        && !str_contains($paginaNoua, SablonSite::continut('bbd.js')), json_encode($raspunsuriFis));
    $seteaza(['css_js_in_fisiere' => '0']);
    $paginaVeche = $cerere($port, 'GET', '/magazin')['corp'];
    $verifica('oprit (Bioscem): CSS-ul și scripturile șablonului sunt în pagină, octet cu octet ca sursele lor, în etichetele de dinainte',
        str_contains($paginaVeche, '    <style>' . SablonSite::continut('sablon-site.css') . '</style>')
        && str_contains($paginaVeche, '    <script>' . SablonSite::continut('bbd.js') . '</script>') && !str_contains($paginaVeche, 'sablon-site.min.css'));
    $seteaza(['css_js_in_fisiere' => '1']);

    // -----------------------------------------------------------------------------------------------------------------
    // 4. Fonturile, HSTS, fișierele versionate.
    // -----------------------------------------------------------------------------------------------------------------
    $familii = [];
    foreach (['dm-sans', 'playfair'] as $font) {
        $css = (string) file_get_contents($radacina . '/public/assets/vendor/fonturi/' . $font . '/' . $font . '.css');
        preg_match_all("#font-family:\s*'([^']+)';\s*font-style:\s*normal;\s*font-weight:\s*(\d+)#", $css, $mf, PREG_SET_ORDER);
        foreach ($mf as $f) {
            $familii[$f[1]][(int) $f[2]] = true;
        }
    }
    ksort($familii['DM Sans']);
    ksort($familii['Playfair Display']);
    $acasa = $cerere($port, 'GET', '/');
    $verifica('fonturile din nucleu: aceleași familii și greutăți ca cererea spre Google Fonts (DM Sans 400–700, Playfair Display 600, 700); '
        . 'pe un site fără temă nicio legătură spre Google', array_keys($familii['DM Sans'] ?? []) === [400, 500, 600, 700]
        && array_keys($familii['Playfair Display'] ?? []) === [600, 700] && !str_contains($acasa['corp'], 'fonts.googleapis.com')
        && str_contains($acasa['corp'], '/assets/vendor/fonturi/dm-sans/dm-sans.css'), json_encode($familii));
    $https = $cerere($port, 'GET', '/', ['X-Forwarded-Proto: https']);
    $htaccess = (string) file_get_contents($radacina . '/public/.htaccess') . (string) file_get_contents($radacina . '/deploy/stub/.htaccess');
    $verifica('HSTS doar pe https (un an, fără subdomenii); fișierele din /assets/ cu ?v= „immutable” un an (.htaccess și stub-ul)',
        ($https['antete']['strict-transport-security'] ?? '') === 'max-age=31536000' && !isset($acasa['antete']['strict-transport-security'])
        && substr_count($htaccess, 'Header always set Cache-Control "public, max-age=31536000, immutable" env=AX_VERSIONAT') === 2);

    // -----------------------------------------------------------------------------------------------------------------
    // 5. Pozele în mărimile ecranului.
    // -----------------------------------------------------------------------------------------------------------------
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($radacina . '/scripts/optimizeaza-imagini.php') . ' --aplica 2>&1', $iesirePoze, $codPoze);
    $faraVariante = [];
    foreach ($db->query("SELECT image_url FROM products WHERE deleted_at IS NULL AND image_url LIKE '/uploads/%'")->fetchAll(PDO::FETCH_COLUMN) as $poza) {
        $rel = VarianteImagini::caleUploads((string) $poza);
        $dim = $rel !== null ? @getimagesize(\App\Support\Paths::uploads() . '/' . $rel) : false;
        if ($rel === null || $dim === false) {
            continue;
        }
        foreach (VarianteImagini::MARIMI as $m) {
            if ($m < (int) $dim[0] && !is_file(\App\Support\Paths::uploads() . '/' . VarianteImagini::caleVarianta($rel, $m))) {
                $faraVariante[] = $rel . ' ' . $m;
            }
        }
    }
    $verifica('după trecerea o dată prin poze (scripts/optimizeaza-imagini.php): fiecare poză de produs demo are variantele WebP mai mici decât ea',
        $codPoze === 0 && $faraVariante === [], implode(', ', array_slice($faraVariante, 0, 5)) . ' ' . implode(' ', $iesirePoze));
    $seteaza(['imagini_srcset' => '1']);
    $cuSrcset = $cerere($port, 'GET', '/magazin')['corp'];
    $seteaza(['imagini_srcset' => '0']);
    $faraSrcset = $cerere($port, 'GET', '/magazin')['corp'];
    $faraSrcsetCurat = (string) preg_replace('# srcset="[^"]*" sizes="[^"]*"#', '', $cuSrcset);
    $verifica('srcset pe listă cu comutatorul (variantele de pe disc + poza întreagă); oprit, pagina e exact cea fără srcset',
        preg_match('#srcset="/uploads/_variante/[^"]+ 160w, [^"]+ 400w, /uploads/[^"]+ \d+w" sizes="#', $cuSrcset) === 1
        && !str_contains($faraSrcset, 'srcset=') && $faraSrcsetCurat === $faraSrcset);
    $seteaza(['imagini_srcset' => '1']);

    // -----------------------------------------------------------------------------------------------------------------
    // 6. Fluxul Merchant.
    // -----------------------------------------------------------------------------------------------------------------
    FluxGoogleMerchant::sterge();
    $flux = $cerere($port, 'GET', '/feed/google-merchant.xml');
    $doc = new DOMDocument();
    $incarcat = @$doc->loadXML($flux['corp']);
    $lipsuri = [];
    $produseFlux = 0;
    if ($incarcat) {
        foreach ($doc->getElementsByTagName('item') as $item) {
            $produseFlux++;
            foreach (['id', 'title', 'link', 'image_link', 'price', 'availability'] as $camp) {
                $nod = $item->getElementsByTagNameNS('http://base.google.com/ns/1.0', $camp)->item(0) ?? $item->getElementsByTagName($camp)->item(0);
                if ($nod === null || trim($nod->textContent) === '') {
                    $lipsuri[] = $camp;
                }
            }
        }
    }
    $verifica('fluxul Merchant: ' . $produseFlux . ' produse, fiecare cu id, title, link, image_link, price, availability', $flux['cod'] === 200 && $incarcat
        && $produseFlux > 0 && $lipsuri === [], implode(', ', array_unique($lipsuri)));
    $seteaza(['merchant_feed' => '0']);
    $oprit = $cerere($port, 'GET', '/feed/google-merchant.xml');
    $verifica('fluxul oprit din Setări magazin → SEO Google: 404 (și nu din cache)', $oprit['cod'] === 404);
    $seteaza(['merchant_feed' => '1']);
    FluxGoogleMerchant::sterge();

    // -----------------------------------------------------------------------------------------------------------------
    // 7. Sursa comenzii.
    // -----------------------------------------------------------------------------------------------------------------
    $gazda = '127.0.0.1';
    $cazuri = [
        [['gclid' => 'abc'], '', 'google_ads'],
        [['utm_source' => 'google', 'utm_medium' => 'cpc'], '', 'google_ads'],
        [['utm_source' => 'newsletter', 'utm_medium' => 'email'], '', 'email'],
        [['utm_source' => 'facebook', 'utm_medium' => 'social'], '', 'campanie'],
        [[], 'https://www.google.ro/', 'google'],
        [[], 'https://www.google.com/search?q=x', 'google'],
        [[], 'https://www.bing.com/', 'alt_site'],
        [[], 'http://127.0.0.1/magazin', null],
        [[], '', null],
    ];
    $gresite = [];
    foreach ($cazuri as [$get, $referer, $asteptat]) {
        if (SursaComenzii::dinCerere($get, $referer, $gazda) !== $asteptat) {
            $gresite[] = json_encode($get) . ' ' . $referer . ' → ' . var_export(SursaComenzii::dinCerere($get, $referer, $gazda), true);
        }
    }
    $verifica('felul vizitei: reclamă Google (gclid, cpc), email, campanie, căutarea Google, alt site; din site sau direct nimic',
        $gresite === [] && SursaComenzii::pentruComanda([]) === 'direct' && SursaComenzii::pentruComanda(['bv_gclid' => 'x']) === 'google_ads'
        && SursaComenzii::pentruComanda(['ax_sursa' => 'google']) === 'google' && SursaComenzii::pentruComanda(['ax_sursa' => 'inventat']) === 'direct',
        implode('; ', $gresite));
    $cuGoogle = $cerere($port, 'GET', '/produs/ceai-de-menta', ['Referer: https://www.google.ro/']);
    $dinSite = $cerere($port, 'GET', '/magazin', ['Referer: http://127.0.0.1:' . $port . '/']);
    $verifica('la intrare, cookie-ul ax_sursa doar cu felul (venit din Google: „google”), fără nimic pentru o pagină cerută din site',
        array_filter($cuGoogle['cookies'], static fn (string $c): bool => str_starts_with($c, 'ax_sursa=google;')) !== []
        && array_filter($dinSite['cookies'], static fn (string $c): bool => str_starts_with($c, 'ax_sursa=')) === []);
    // O comandă adevărată, de la un vizitator venit din Google: comanda are sursa „google”.
    $jarComanda = $jarNou('viteza-comanda');
    $cerere($port, 'GET', '/produs/ceai-de-menta', ['Referer: https://www.google.ro/'], $jarComanda);
    $cerere($port, 'POST', '/cos/adauga/' . $idMenta, [], $jarComanda, ['quantity' => '1']);
    $pagCheckout = $cerere($port, 'GET', '/checkout', [], $jarComanda)['corp'];
    preg_match('/name="checkout_form_token" value="([^"]*)"/', $pagCheckout, $tokenComanda);
    preg_match('/name="checkout_form_rendered_at" value="([^"]*)"/', $pagCheckout, $randatComanda);
    sleep(3); // garda anti-bot cere câteva secunde între afișare și trimitere
    // Limita comenzilor pe adresă (15 minute) numără și comenzile altor teste rulate chiar înainte, de pe aceeași adresă:
    // pe baza de dezvoltare, încercările adresei locale din ultimele 15 minute se scot, ca refuzul să nu fie al lor.
    $db->exec("DELETE FROM checkout_submit_logs WHERE ip_address = '127.0.0.1' AND created_at >= (NOW() - INTERVAL 16 MINUTE)");
    $ultimaComanda = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM orders')->fetchColumn();
    $trimisa = $cerere($port, 'POST', '/checkout', [], $jarComanda, [
        'checkout_form_token' => $tokenComanda[1] ?? '', 'checkout_form_rendered_at' => $randatComanda[1] ?? '',
        'billing_first_name' => 'Test', 'billing_last_name' => 'Sursa E4', 'billing_phone' => '0700000779',
        'billing_email' => 'sursa-e4@exemplu.ro', 'billing_street' => 'Strada Exemplului', 'billing_street_no' => '9',
        'billing_city' => 'Cluj-Napoca', 'billing_county' => 'Cluj', 'billing_postcode' => '400001',
        'shipping_same_as_billing' => '1', 'payment_method' => 'cod', 'accept_terms' => '1',
    ]);
    $comandaNoua = $db->query('SELECT id, sursa FROM orders WHERE id > ' . $ultimaComanda . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $verifica('o comandă plasată de un vizitator venit din Google primește sursa „google”', is_array($comandaNoua)
        && ($comandaNoua['sursa'] ?? '') === 'google', $trimisa['cod'] . ' ' . json_encode($comandaNoua));
    if (is_array($comandaNoua)) {
        // Comanda de test pleacă de tot: și rândurile legate de ea (emailurile trimise, liniile, istoricul…), din fiecare tabel
        // cu `order_id`, ca baza de dezvoltare (din care se fac copiile testelor) să nu păstreze rânduri fără comandă.
        $legate = $db->query("SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND COLUMN_NAME = 'order_id' AND TABLE_NAME <> 'orders'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($legate as $tabel) {
            $db->prepare('DELETE FROM `' . str_replace('`', '', (string) $tabel) . '` WHERE order_id = ?')->execute([(int) $comandaNoua['id']]);
        }
        $db->prepare('DELETE FROM orders WHERE id = ?')->execute([(int) $comandaNoua['id']]);
    }
    // Raportul: sursele puse pe comenzile demo, apoi numărate.
    $surseInainte = $db->query('SELECT id, sursa FROM orders')->fetchAll(PDO::FETCH_KEY_PAIR);
    try {
        $chei = array_keys(SursaComenzii::SURSE);
        $pune = $db->prepare('UPDATE orders SET sursa = ? WHERE id = ?');
        $asteptate = array_fill_keys(array_merge($chei, ['necunoscuta']), 0);
        foreach ($db->query('SELECT id, deleted_at FROM orders ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $i => $o) {
            $sursa = $i % 7 === 6 ? null : $chei[$i % count($chei)];
            $pune->execute([$sursa, (int) $o['id']]);
            if ($o['deleted_at'] === null) {
                $asteptate[$sursa ?? 'necunoscuta']++;
            }
        }
        $raport = SursaComenzii::raport($db, 52);
        $numarate = array_map(static fn (array $t): int => $t['numar'], $raport['totaluri']);
        $totalNesterse = (int) $db->query('SELECT COUNT(*) FROM orders WHERE deleted_at IS NULL')->fetchColumn();
        $jarRaport = $jarNou('viteza-raport');
        $cerere($port, 'POST', '/admin/login', [], $jarRaport, ['email' => $adminEmail, 'password' => $parola]);
        $pagina = $cerere($port, 'GET', '/admin/orders/sursa-comenzilor?saptamani=52', [], $jarRaport);
        // Emailurile, telefoanele și numele întregi ale clienților (numele de familie singur, „Demo”, apare și în numele firmei).
        $personale = $db->query("SELECT billing_email, billing_phone, CONCAT(billing_first_name, ' ', billing_last_name), order_number
            FROM orders WHERE deleted_at IS NULL LIMIT 20")->fetchAll(PDO::FETCH_NUM);
        $scapate = [];
        foreach ($personale as $rand) {
            foreach ($rand as $valoare) {
                if (trim((string) $valoare) !== '' && mb_strlen((string) $valoare) > 3 && str_contains($pagina['corp'], (string) $valoare)) {
                    $scapate[] = (string) $valoare;
                }
            }
        }
        $verifica('raportul „Sursa comenzilor”: cele ' . $totalNesterse . ' comenzi demo (neșterse) numărate pe surse, exact; pagina din admin (Rapoarte) '
            . 'le arată fără niciun email, telefon, nume sau număr de comandă al unui client', $raport['total'] === $totalNesterse && $numarate === $asteptate
            && $pagina['cod'] === 200 && str_contains($pagina['corp'], 'id="sursa-comenzilor-tabel"') && $scapate === [],
            json_encode([$numarate, $asteptate]) . ' ' . implode(', ', array_slice($scapate, 0, 3)));
    } finally {
        $pune = $db->prepare('UPDATE orders SET sursa = ? WHERE id = ?');
        foreach ($surseInainte as $id => $sursa) {
            $pune->execute([$sursa, (int) $id]);
        }
    }

    // -----------------------------------------------------------------------------------------------------------------
    // 8. scripts/comutatoare-seo.php (comutatoarele din linia de comandă, pentru site-ul de verificare și adoptare).
    // -----------------------------------------------------------------------------------------------------------------
    $ruleaza = static function (string $argumente) use ($radacina): array {
        exec(escapeshellarg(PHP_BINARY) . ' -d ' . escapeshellarg('pdo_mysql.default_socket=' . (string) ini_get('pdo_mysql.default_socket'))
            . ' ' . escapeshellarg($radacina . '/scripts/comutatoare-seo.php') . ' ' . $argumente . ' 2>&1', $iesire, $cod);
        return [$cod, implode("\n", $iesire)];
    };
    $seteaza(['css_js_in_fisiere' => '1', 'imagini_srcset' => '1', 'sursa_comenzii' => '1']);
    [$codOprit, $iesireOprit] = $ruleaza('--opreste=imagini_srcset,sursa_comenzii');
    $opriteBaza = Settings::all($db);
    [$codPornit, $iesirePornit] = $ruleaza('--porneste');
    $pornitBaza = Settings::all($db);
    [$codGresit] = $ruleaza('--porneste=inventat');
    [$codArata, $iesireArata] = $ruleaza('--arata');
    $verifica('scripts/comutatoare-seo.php: --opreste=a,b scrie „0” doar pentru ele, --porneste pe toate „1”, --arata nu scrie, o cheie necunoscută refuzată (2)',
        $codOprit === 0 && $opriteBaza['imagini_srcset'] === '0' && $opriteBaza['sursa_comenzii'] === '0'
        && $opriteBaza['css_js_in_fisiere'] === '1' && $codPornit === 0
        && array_filter(array_slice($cheiViteza, 0, 6), static fn (string $c): bool => ($pornitBaza[$c] ?? '') !== '1') === []
        && $codGresit === 2 && $codArata === 0 && substr_count($iesireArata, 'PORNIT ') === 6,
        $iesireOprit . ' | ' . $iesirePornit);
} finally {
    Settings::save($db, array_merge(array_fill_keys($cheiViteza, ''), $vitezaInainte));
    \App\Support\ResponseCache::purgePageCache();
    FluxGoogleMerchant::sterge();
}
