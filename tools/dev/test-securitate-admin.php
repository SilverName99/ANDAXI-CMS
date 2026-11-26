<?php

declare(strict_types=1);

/**
 * Testele reparațiilor de securitate ale adminului (revizuirea din 18 noiembrie 2026; docs/SECURITATE.md): codul activ
 * salvat de un rol parțial (N1b), garda originii cererilor din admin (N2), scripturile terților fără admin în sesiune
 * (N3), cheia cache-ului cu gazda (N4) și anteturile fișierelor încărcate (N5). Cu serverul de dezvoltare pe baza cu
 * datele demo (îl pornește dacă nu merge).
 *
 *   tools/dev/php.sh tools/dev/test-securitate-admin.php
 *
 *  A. Fără server: App\Support\CodActiv (părțile care pot rula dintr-un text: script, on…, javascript:, srcdoc, base,
 *     câmp de JavaScript; entități, etichetă ascunsă într-un atribut, JSON cu blocuri, câmp _b64; „noi” numără ce e nou
 *     față de ce era salvat); App\Support\GardaOrigine::decide pe fiecare combinație de antete și metode;
 *     App\Support\ResponseCache::gazdaCunoscuta (gazda proprie, www, alias, străină); EticheteTerte pe sesiune.
 *  B. Cu serverul: N2 (POST cross-site și cu Origin străin refuzat 403, same-origin și fără antete acceptat); N1b (un
 *     rol parțial e refuzat la o salvare cu script, acceptat la una fără și la una neschimbată cu scriptul generalului,
 *     refuzat când schimbă scriptul; administratorul general salvează orice); N3 (cu admin în sesiune, paginile publice
 *     n-au GTM/Analytics/Clarity/chat; fără admin, cu consimțământ, le au; HTML identic altfel); N5 (nosniff pe
 *     /uploads, plus CSP sandbox pe un SVG încărcat).
 *
 * Rândurile și setările scrise de test se pun la loc la final (șterse sau readuse la valoarea de dinainte), verificat.
 * Raportul: $CMS_DEV_DIR/test-securitate-admin-raport.txt; cod de ieșire 0 doar dacă totul a trecut. Date inventate.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';

use App\Support\CodActiv;
use App\Support\Database;
use App\Support\GardaOrigine;
use App\Support\ResponseCache;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-securitate-admin-raport.txt';
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
$scrie('Test securitate admin (N1–N5) — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

// Numele martor al scriptului de probă: inventat, ușor de căutat, niciodată dintr-un client.
$MARCA = 'axSondaSecuritate';

// =====================================================================
// A. Fără server
// =====================================================================
$scrie('');
$scrie('== A. Fără server: CodActiv, GardaOrigine, ResponseCache::gazdaCunoscuta, EticheteTerte');

// CodActiv::parti — fiecare fel de cod într-un text; proza nu are părți.
$cazuriCod = [
    'text simplu' => ['<p>Ceai de mentă, 100 g.</p>', 0],
    'cod arătat ca exemplu (entități)' => ['<pre>&lt;script&gt;' . $MARCA . '()&lt;/script&gt;</pre>', 0],
    'element script' => ['<div>x</div><script>' . $MARCA . '();</script>', 1],
    'script cu majuscule și atribut' => ['<SCRIPT TYPE="text/javascript">' . $MARCA . '();</SCRIPT>', 1],
    'atribut on…' => ['<button onclick="' . $MARCA . '()">x</button>', 1],
    'atribut on… lipit (fără spațiu)' => ['<button/onmouseover=' . $MARCA . '()>x</button>', 1],
    'adresă javascript:' => ['<a href="javascript:' . $MARCA . '()">x</a>', 1],
    'adresă javascript: cu entitate numerică fără ;' => ['<a href="&#106avascript:' . $MARCA . '()">x</a>', 1],
    'adresă javascript: cu entitate denumită în mijloc' => ['<a href="java&Tab;script:' . $MARCA . '()">x</a>', 1],
    'element base' => ['<base href="https://exemplu.invalid/">', 1],
    'srcdoc' => ['<iframe srcdoc="&lt;script&gt;' . $MARCA . '()&lt;/script&gt;"></iframe>', 1],
    'etichetă ascunsă într-un atribut title' => ['<span title="&lt;img src=x onerror=' . $MARCA . '()&gt;">x</span>', 1],
];
$gresite = [];
foreach ($cazuriCod as $nume => [$html, $asteptat]) {
    $gasit = count(CodActiv::parti($html));
    if (($asteptat === 0) !== ($gasit === 0)) {
        $gresite[] = $nume . ' → ' . $gasit . ' părți (așteptat ' . ($asteptat === 0 ? '0' : '≥1') . ')';
    }
}
$verifica('CodActiv::parti pe ' . count($cazuriCod) . ' texte (script, on…, javascript:, base, srcdoc, entități, etichetă în atribut; proza n-are părți)',
    $gresite === [], implode('; ', $gresite));

// Câmp de JavaScript, câmp _b64, blocuri JSON, și cheile unui obiect JSON.
$dinFormular = CodActiv::partiDin([
    'js_content' => $MARCA . '();',
    'descriere' => 'Text curat, fără cod.',
    'blocks_json' => (string) json_encode([['type' => 'text', 'content' => '<b onclick="' . $MARCA . '()">salut</b>']]),
    'continut_b64' => base64_encode('<script>' . $MARCA . '()</script>'),
]);
$verifica('CodActiv::partiDin vede câmpul JS, blocurile JSON și câmpul _b64 (3 părți cu martorul)',
    count(array_filter($dinFormular, static fn (string $p): bool => str_contains($p, $MARCA))) === 3,
    json_encode($dinFormular, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

// „noi”: o parte deja salvată nu e nouă; o parte schimbată e nouă; una salvată o dată nu acoperă două.
$vechi = CodActiv::parti('<script>' . $MARCA . '(1);</script>');
$verifica('CodActiv::noi — scriptul neschimbat nu e nou',
    CodActiv::noi(CodActiv::parti('<p>text nou</p><script>' . $MARCA . '(1);</script>'), $vechi) === []);
$verifica('CodActiv::noi — scriptul schimbat e nou',
    CodActiv::noi(CodActiv::parti('<script>' . $MARCA . '(2);</script>'), $vechi) !== []);
$verifica('CodActiv::noi — două apariții, una salvată: una rămâne nouă',
    count(CodActiv::noi(['on:onclick=' . $MARCA, 'on:onclick=' . $MARCA], ['on:onclick=' . $MARCA])) === 1);

// GardaOrigine::decide (null = trece; altfel motivul).
$appUrl = 'https://exemplu.ro';
$cazuriOrigine = [
    'GET /admin trece (nu e stare-schimbătoare)' => ['GET', '/admin/settings/store', [], null],
    'POST /admin cu Sec-Fetch-Site same-origin trece' => ['POST', '/admin/settings/store', ['HTTP_SEC_FETCH_SITE' => 'same-origin'], null],
    'POST /admin cu Sec-Fetch-Site none trece' => ['POST', '/admin/settings/store', ['HTTP_SEC_FETCH_SITE' => 'none'], null],
    'POST /admin cu Sec-Fetch-Site cross-site refuzat' => ['POST', '/admin/settings/store', ['HTTP_SEC_FETCH_SITE' => 'cross-site'], 'sec-fetch-site'],
    'POST /admin cu Sec-Fetch-Site same-site refuzat' => ['POST', '/admin/settings/store', ['HTTP_SEC_FETCH_SITE' => 'same-site'], 'sec-fetch-site'],
    'POST /admin cu Origin propriu trece' => ['POST', '/admin/settings/store', ['HTTP_ORIGIN' => 'https://exemplu.ro'], null],
    'POST /admin cu Origin străin refuzat' => ['POST', '/admin/settings/store', ['HTTP_ORIGIN' => 'https://alt.invalid'], 'origin'],
    'POST /admin cu Origin null refuzat' => ['POST', '/admin/settings/store', ['HTTP_ORIGIN' => 'null'], 'origin'],
    'POST /admin fără antete trece (comportamentul de azi)' => ['POST', '/admin/settings/store', [], null],
    'POST public (nu /admin) nu e atins' => ['POST', '/contact/send', ['HTTP_SEC_FETCH_SITE' => 'cross-site'], null],
    'POST /administer (nu sub /admin/) nu e atins' => ['POST', '/administer', ['HTTP_SEC_FETCH_SITE' => 'cross-site'], null],
];
$gresite = [];
foreach ($cazuriOrigine as $nume => [$metoda, $cale, $server, $asteptat]) {
    $server['HTTP_HOST'] = 'exemplu.ro';
    $rezultat = GardaOrigine::decide($metoda, $cale, $server, $appUrl);
    if ($rezultat !== $asteptat) {
        $gresite[] = $nume . ' → ' . var_export($rezultat, true);
    }
}
$verifica('GardaOrigine::decide pe ' . count($cazuriOrigine) . ' cazuri (Sec-Fetch-Site, Origin, fără antete, în afara /admin)',
    $gresite === [], implode('; ', $gresite));
// Gazda cererii (din Host) ca origine proprie, oricare ar fi APP_URL.
$verifica('GardaOrigine::decide — Origin = gazda cererii (fără APP_URL) trece',
    GardaOrigine::decide('POST', '/admin/x', ['HTTP_HOST' => 'alt.exemplu.ro', 'HTTP_ORIGIN' => 'https://alt.exemplu.ro'], '') === null);

// ResponseCache::gazdaCunoscuta.
$cazuriGazda = [
    'gazda proprie' => ['exemplu.ro', 'https://exemplu.ro', '', true],
    'www proprie' => ['www.exemplu.ro', 'https://exemplu.ro', '', true],
    'fără www când APP_URL are www' => ['exemplu.ro', 'https://www.exemplu.ro', '', true],
    'alias configurat' => ['exemplu.com', 'https://exemplu.ro', 'exemplu.com', true],
    'gazda cu port (dev)' => ['127.0.0.1:9501', 'http://127.0.0.1:9501', '', true],
    'gazdă străină' => ['alt-site.invalid', 'https://exemplu.ro', '', false],
    'gazdă goală' => ['', 'https://exemplu.ro', '', false],
    'fără APP_URL: orice gazdă (cheia o ține separat)' => ['orice.invalid', '', '', true],
];
$gresite = [];
foreach ($cazuriGazda as $nume => [$gazda, $app, $alias, $asteptat]) {
    if (ResponseCache::gazdaCunoscuta($gazda, $app, $alias) !== $asteptat) {
        $gresite[] = $nume;
    }
}
$verifica('ResponseCache::gazdaCunoscuta pe ' . count($cazuriGazda) . ' cazuri (proprie, www, alias, port, străină, goală, fără APP_URL)',
    $gresite === [], implode('; ', $gresite));

// EticheteTerte pe sesiune (fără server: doar logica sesiunii, verificarea e oprită aici).
$_SESSION = ['admin_id' => 5];
$cuAdmin = \App\Support\EticheteTerte::sesiuneAdmin();
$_SESSION = [];
$faraAdmin = \App\Support\EticheteTerte::sesiuneAdmin();
$verifica('EticheteTerte::sesiuneAdmin — true cu admin_id în sesiune, false fără', $cuAdmin && !$faraAdmin);

// =====================================================================
// B. Cu serverul
// =====================================================================
$scrie('');
$scrie('== B. Cu serverul de dezvoltare');

$port = (string) (getenv('CMS_DEV_PORT') ?: '8701');
$baza = 'http://127.0.0.1:' . $port;
$origine = $baza;

$cerere = static function (string $metoda, string $cale, ?string $jar = null, array $date = [], array $antete = [], bool $siteOrigin = true, ?string $corpBrut = null) use ($baza, $origine): array {
    $c = curl_init($baza . $cale);
    $raspAntete = [];
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60,
        CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$raspAntete): int {
            $raspAntete[] = trim($linie);
            return strlen($linie);
        },
    ]);
    $linii = [];
    foreach ($antete as $n => $v) {
        $linii[] = $n . ': ' . $v;
    }
    if ($siteOrigin && !isset($antete['Origin']) && !isset($antete['Sec-Fetch-Site'])) {
        $linii[] = 'Origin: ' . $origine;
        $linii[] = 'Sec-Fetch-Site: same-origin';
    }
    if ($corpBrut !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, $corpBrut);
    } elseif ($metoda === 'POST') {
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($date));
    }
    if ($linii !== []) {
        curl_setopt($c, CURLOPT_HTTPHEADER, $linii);
    }
    if ($jar !== null) {
        curl_setopt($c, CURLOPT_COOKIEFILE, $jar);
        curl_setopt($c, CURLOPT_COOKIEJAR, $jar);
    }
    $corp = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $loc = '';
    foreach ($raspAntete as $linie) {
        if (stripos($linie, 'location:') === 0) {
            $loc = trim(substr($linie, 9));
        }
    }
    curl_close($c);
    return ['cod' => $cod, 'corp' => $corp, 'antete' => $raspAntete, 'location' => $loc];
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

$dosarJar = $dosarDev . '/jar-sec-admin-' . getmypid();
@mkdir($dosarJar, 0700, true);
$parola = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));

// Rândurile și setările scrise de test, puse la loc la final.
$sabloaneInainte = $db->query('SELECT id FROM blog_templates')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$setariGtmInainte = [];
foreach (['google_tag_manager_enabled', 'google_tag_manager_id'] as $cheie) {
    $stmt = $db->prepare('SELECT value FROM settings WHERE `key` = :k');
    $stmt->execute(['k' => $cheie]);
    $v = $stmt->fetchColumn();
    $setariGtmInainte[$cheie] = $v === false ? null : (string) $v;
}
$svgProba = \App\Support\Paths::uploads() . '/gallery/' . $MARCA . '.svg';
$jurnalInainte = (int) $db->query("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'cod_activ_refuzat'")->fetchColumn();
// Rândurile din tabelele pe care le ating testele N1b (newsletter): le șterg la final pe cele apărute acum.
$maxInainte = [];
foreach (['blog_templates', 'newsletter_templates', 'newsletter_subscribers'] as $tabel) {
    $maxInainte[$tabel] = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM ' . $tabel)->fetchColumn();
}

$puneLaLoc = static function () use ($db, $sabloaneInainte, $setariGtmInainte, $svgProba, $jurnalInainte, $maxInainte, $dosarJar, $amPornitServerul, $radacina): void {
    $pastrate = $sabloaneInainte === [] ? ['0'] : array_map('strval', $sabloaneInainte);
    $locuri = implode(',', array_fill(0, count($pastrate), '?'));
    $db->prepare('DELETE FROM blog_templates WHERE id NOT IN (' . $locuri . ')')->execute($pastrate);
    foreach (['newsletter_templates', 'newsletter_subscribers'] as $tabel) {
        $db->prepare('DELETE FROM ' . $tabel . ' WHERE id > :m')->execute(['m' => $maxInainte[$tabel]]);
    }
    foreach ($setariGtmInainte as $cheie => $valoare) {
        if ($valoare === null) {
            $db->prepare('DELETE FROM settings WHERE `key` = :k')->execute(['k' => $cheie]);
        } else {
            $db->prepare('INSERT INTO settings (`key`, value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE value = :v2')
                ->execute(['k' => $cheie, 'v' => $valoare, 'v2' => $valoare]);
        }
    }
    $db->prepare('DELETE FROM admin_activity_log WHERE action = :a AND id > :pana')
        ->execute(['a' => 'cod_activ_refuzat', 'pana' => 0]);
    if ($jurnalInainte === 0) {
        $db->exec("DELETE FROM admin_activity_log WHERE action = 'cod_activ_refuzat'");
    }
    @unlink($svgProba);
    foreach (glob($dosarJar . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dosarJar);
    if ($amPornitServerul) {
        exec(escapeshellarg($radacina . '/tools/dev/server.sh') . ' --opreste >/dev/null 2>&1');
    }
};
register_shutdown_function($puneLaLoc);

$login = static function (string $email) use ($cerere, $dosarJar, $parola): string {
    $jar = $dosarJar . '/' . preg_replace('/[^a-z]/', '', $email) . '.txt';
    @unlink($jar);
    $r = $cerere('POST', '/admin/login', $jar, ['email' => $email, 'password' => $parola]);
    return $r['cod'] >= 300 && $r['cod'] < 400 ? $jar : '';
};

$jarGeneral = $login('admin@exemplu.ro');
$jarBlog = $login('blog@exemplu.ro');
$jarEmailuri = $login('emailuri@exemplu.ro');
$jarMagazin = $login('magazin@exemplu.ro');
$verifica('logare administrator general, de blog, de emailuri și de magazin',
    $jarGeneral !== '' && $jarBlog !== '' && $jarEmailuri !== '' && $jarMagazin !== '');

// ---- N2: garda originii pe server ----
$store = static fn (array $antete): int => $cerere('POST', '/admin/settings/store', null, ['a' => '1'], $antete, false)['cod'];
$verifica('N2: POST /admin cross-site → 403', $store(['Sec-Fetch-Site' => 'cross-site']) === 403);
$verifica('N2: POST /admin cu Origin străin → 403', $store(['Origin' => 'https://alt.invalid']) === 403);
$verifica('N2: POST /admin same-origin → nu 403 (redirectat la login, fără sesiune)', $store(['Sec-Fetch-Site' => 'same-origin']) !== 403);
$verifica('N2: POST /admin fără antete → nu 403 (comportamentul de azi)', $store([]) !== 403);
$verifica('N2: POST public cross-site nu e atins de gardă', $cerere('POST', '/contact/send', null, ['x' => '1'], ['Sec-Fetch-Site' => 'cross-site'], false)['cod'] !== 403);

// ---- N1b: codul activ salvat de un rol parțial ----
$salveazaSablon = static function (string $jar, array $extra) use ($cerere): array {
    return $cerere('POST', '/admin/blog/templates', $jar,
        array_merge(['action' => 'save', 'name' => 'Proba sec', 'slug' => 'proba-sec'], $extra));
};
// General salvează un șablon cu JS.
$salveazaSablon($jarGeneral, ['id' => '0', 'html_content' => '<div>{{blog_content_html}}</div>', 'js_content' => $MARCA . '(1);']);
$idSablon = (int) $db->query("SELECT id FROM blog_templates WHERE slug = 'proba-sec' ORDER BY id DESC LIMIT 1")->fetchColumn();
$jsSalvat = (string) $db->query('SELECT js_content FROM blog_templates WHERE id = ' . $idSablon)->fetchColumn();
$verifica('N1b: administratorul general salvează un șablon cu JS', $idSablon > 0 && str_contains($jsSalvat, $MARCA));

// Blog salvează un șablon nou cu JS → refuzat, nimic creat.
$inainte = (int) $db->query('SELECT COUNT(*) FROM blog_templates')->fetchColumn();
$salveazaSablon($jarBlog, ['id' => '0', 'slug' => 'proba-sec-blog', 'html_content' => '<div>x</div>', 'js_content' => $MARCA . '(9);']);
$dupa = (int) $db->query('SELECT COUNT(*) FROM blog_templates')->fetchColumn();
$verifica('N1b: rol parțial, salvare cu JS nou → refuzată, niciun rând creat', $dupa === $inainte,
    'înainte ' . $inainte . ', după ' . $dupa);
$refuzuri = (int) $db->query("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'cod_activ_refuzat'")->fetchColumn();
$verifica('N1b: refuzul e notat în Jurnal activitate', $refuzuri > $jurnalInainte);

// Blog salvează fără cod → acceptat.
$inainte = (int) $db->query('SELECT COUNT(*) FROM blog_templates')->fetchColumn();
$salveazaSablon($jarBlog, ['id' => '0', 'slug' => 'proba-sec-curat', 'name' => 'Proba curata', 'html_content' => '<div>{{blog_content_html}}</div>']);
$dupa = (int) $db->query('SELECT COUNT(*) FROM blog_templates')->fetchColumn();
$verifica('N1b: rol parțial, salvare fără cod → acceptată', $dupa === $inainte + 1);

// Blog re-salvează șablonul generalului neschimbat (același JS) → acceptat.
$r = $salveazaSablon($jarBlog, ['id' => (string) $idSablon, 'html_content' => '<div>{{blog_content_html}}</div>', 'js_content' => $MARCA . '(1);']);
$jsAcum = (string) $db->query('SELECT js_content FROM blog_templates WHERE id = ' . $idSablon)->fetchColumn();
$verifica('N1b: rol parțial, re-salvare neschimbată cu scriptul generalului → acceptată', $jsAcum === $MARCA . '(1);');

// Blog schimbă JS-ul → refuzat, rămâne cel vechi.
$salveazaSablon($jarBlog, ['id' => (string) $idSablon, 'html_content' => '<div>{{blog_content_html}}</div>', 'js_content' => $MARCA . '(2);']);
$jsAcum = (string) $db->query('SELECT js_content FROM blog_templates WHERE id = ' . $idSablon)->fetchColumn();
$verifica('N1b: rol parțial, schimbarea scriptului → refuzată, scriptul rămâne cel vechi', $jsAcum === $MARCA . '(1);');

// ---- N1b: baza e exact entitatea scrisă, nu o uniune de rânduri nelegate (revizuirea din 18 noiembrie) ----
// Un cod pus de formularul public de opt-in în numele unui abonat (newsletter_subscribers.name, scris brut) nu mai
// poate servi drept „cod deja salvat” pentru o altă entitate. Simulez abonatul venit din opt-in printr-un rând direct.
$codExploit = '<img src=x on' . 'error="' . $MARCA . 'Exploit()">';
$db->prepare('INSERT INTO newsletter_subscribers (email, name) VALUES (:e, :n)')
    ->execute(['e' => $MARCA . '-abonat@exemplu.ro', 'n' => $codExploit]);
$idAbonat = (int) $db->lastInsertId();
// Generalul creează un șablon de newsletter cu cod (ca să existe o entitate proprie cu cod salvat).
$cerere('POST', '/admin/emails', $jarGeneral, ['section' => 'newsletter-save', 'newsletter_id' => '0',
    'newsletter_name' => 'Proba NL', 'newsletter_subject' => 'Subiect', 'newsletter_html' => '<p>ok</p>' . $codExploit]);
$idNlGeneral = (int) $db->query("SELECT id FROM newsletter_templates WHERE name = 'Proba NL' ORDER BY id DESC LIMIT 1")->fetchColumn();
$verifica('N1b/exploit: administratorul general a creat un șablon de newsletter cu cod', $idNlGeneral > 0);

$nlInainte = (int) $db->query('SELECT COUNT(*) FROM newsletter_templates')->fetchColumn();
$refuzuriInainteExploit = (int) $db->query("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'cod_activ_refuzat'")->fetchColumn();
// Rolul emailuri: șablon NOU cu același cod, plus subscriber_id = abonatul cu cod în nume. Înainte trecea (baza
// folosea rândul abonatului); acum trebuie refuzat și niciun șablon creat.
$cerere('POST', '/admin/emails', $jarEmailuri, ['section' => 'newsletter-save', 'newsletter_id' => '0',
    'newsletter_name' => 'Proba NL Exploit', 'newsletter_subject' => 'S', 'newsletter_html' => '<p>hi</p>' . $codExploit,
    'subscriber_id' => (string) $idAbonat]);
$nlDupa = (int) $db->query('SELECT COUNT(*) FROM newsletter_templates')->fetchColumn();
$refuzuriDupaExploit = (int) $db->query("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'cod_activ_refuzat'")->fetchColumn();
$verifica('N1b/exploit: rol parțial nu mai poate „acoperi” cod cu numele unui abonat (subscriber_id) → refuzat, niciun șablon creat',
    $nlDupa === $nlInainte && $refuzuriDupaExploit > $refuzuriInainteExploit, 'șabloane ' . $nlInainte . '→' . $nlDupa);
$existaExploit = (int) $db->query("SELECT COUNT(*) FROM newsletter_templates WHERE name = 'Proba NL Exploit'")->fetchColumn();
$verifica('N1b/exploit: niciun șablon „Proba NL Exploit” în bază', $existaExploit === 0);

// Rolul emailuri re-salvează șablonul generalului NESCHIMBAT (codul lui rămâne) → acceptat (baza e chiar acel rând).
$cerere('POST', '/admin/emails', $jarEmailuri, ['section' => 'newsletter-save', 'newsletter_id' => (string) $idNlGeneral,
    'newsletter_name' => 'Proba NL', 'newsletter_subject' => 'Subiect', 'newsletter_html' => '<p>ok</p>' . $codExploit]);
$htmlNl = (string) $db->query('SELECT html_content FROM newsletter_templates WHERE id = ' . $idNlGeneral)->fetchColumn();
$verifica('N1b/exploit: re-salvarea neschimbată a șablonului propriu (cu codul generalului) → acceptată',
    str_contains($htmlNl, $MARCA . 'Exploit'));

// ---- N1b: corpul JSON text/plain e citit de gardă (ConcurentaController citește php://input oricare ar fi Content-Type) ----
$refuzInainteJson = (int) $db->query("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'cod_activ_refuzat'")->fetchColumn();
$corpJsonCod = json_encode(['product_id' => 1, 'partner_id' => 1, 'value' => '<scr' . 'ipt>' . $MARCA . 'Json()</scr' . 'ipt>'], JSON_UNESCAPED_SLASHES);
$cerere('POST', '/admin/competition/cell', $jarMagazin, [], ['Content-Type' => 'text/plain'], true, $corpJsonCod);
$refuzDupaJson = (int) $db->query("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'cod_activ_refuzat'")->fetchColumn();
$verifica('N1b: corp JSON cu Content-Type: text/plain e citit și codul din el e refuzat (rol parțial)',
    $refuzDupaJson > $refuzInainteJson);
// Un corp JSON text/plain FĂRĂ cod nu produce un refuz de cod activ (garda nu se aprinde în gol).
$corpJsonCurat = json_encode(['product_id' => 1, 'partner_id' => 1, 'value' => '12,50 lei'], JSON_UNESCAPED_SLASHES);
$cerere('POST', '/admin/competition/cell', $jarMagazin, [], ['Content-Type' => 'text/plain'], true, $corpJsonCurat);
$refuzFaraCod = (int) $db->query("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'cod_activ_refuzat'")->fetchColumn();
$verifica('N1b: corp JSON text/plain fără cod nu produce un refuz de cod activ', $refuzFaraCod === $refuzDupaJson);

// ---- N3: scripturile terților fără admin în sesiune ----
foreach (['google_tag_manager_enabled' => '1', 'google_tag_manager_id' => 'GTM-SONDA00'] as $k => $v) {
    $db->prepare('INSERT INTO settings (`key`, value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE value = :v2')->execute(['k' => $k, 'v' => $v, 'v2' => $v]);
}
$anonCuAcord = $cerere('GET', '/', null, [], ['Cookie' => \App\Support\CookieConsent::nume() . '=' . \App\Support\CookieConsent::valoareToate()]);
$adminPeSite = $cerere('GET', '/', $jarGeneral, [], ['Cookie' => \App\Support\CookieConsent::nume() . '=' . \App\Support\CookieConsent::valoareToate()]);
$verifica('N3: vizitator anonim cu consimțământ primește GTM', str_contains($anonCuAcord['corp'], 'googletagmanager.com'));
$verifica('N3: cu un admin în sesiune, pagina publică NU are GTM', !str_contains($adminPeSite['corp'], 'googletagmanager.com'));
// HTML identic pentru doi vizitatori anonimi (fără admin): byte cu byte.
$anon2 = $cerere('GET', '/', null, [], ['Cookie' => \App\Support\CookieConsent::nume() . '=' . \App\Support\CookieConsent::valoareToate()]);
$verifica('N3: HTML identic pentru doi vizitatori anonimi cu același consimțământ', $anonCuAcord['corp'] === $anon2['corp']);

// ---- N5: anteturile fișierelor încărcate ----
$poza = glob(\App\Support\Paths::uploads() . '/demo/*.png') ?: glob(\App\Support\Paths::uploads() . '/*/*.png');
if ($poza !== []) {
    $calePoza = '/uploads/' . ltrim(str_replace(\App\Support\Paths::uploads(), '', $poza[0]), '/');
    $rp = $cerere('GET', $calePoza);
    $areNosniff = (bool) array_filter($rp['antete'], static fn (string $a): bool => stripos($a, 'x-content-type-options:') === 0 && stripos($a, 'nosniff') !== false);
    $verifica('N5: /uploads trimite X-Content-Type-Options: nosniff', $areNosniff, $calePoza);
} else {
    $verifica('N5: /uploads trimite nosniff', false, 'nicio poză demo găsită');
}
// Un SVG încărcat: nosniff + CSP sandbox.
@mkdir(dirname($svgProba), 0775, true);
@file_put_contents($svgProba, '<svg xmlns="http://www.w3.org/2000/svg"><text>probă</text></svg>');
$rs = $cerere('GET', '/uploads/gallery/' . $MARCA . '.svg');
$areNosniffSvg = (bool) array_filter($rs['antete'], static fn (string $a): bool => stripos($a, 'x-content-type-options:') === 0);
$areCsp = (bool) array_filter($rs['antete'], static fn (string $a): bool => stripos($a, 'content-security-policy:') === 0 && stripos($a, 'sandbox') !== false);
$verifica('N5: un SVG încărcat primește nosniff și Content-Security-Policy: sandbox', $rs['cod'] === 200 && $areNosniffSvg && $areCsp,
    'cod ' . $rs['cod'] . ' nosniff=' . ($areNosniffSvg ? 1 : 0) . ' csp=' . ($areCsp ? 1 : 0));

$scrie('');
$scrie('Rezultat: ' . $trecute . ' verificări trecute, ' . $esuate . ' eșuate.');
exit($esuate === 0 ? 0 : 1);
