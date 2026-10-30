<?php

declare(strict_types=1);

/**
 * Secțiunea T a tools/dev/test-pluginuri.php: tema site-ului (WP-13, GR-18, DB-01; App\Support\Tema,
 * App\Support\SvgSigur, DateSite::site; docs/PACHETE.md). Se include din test-pluginuri.php și folosește de acolo
 * $scrie, $verifica, $radacina și $dosarDev; sare cu --fara-tema.
 *
 *  T1, fără server: valorile implicite (scrise în test: comportamentul de azi), regulile lui tema.json (o cheie greșită
 *      rămâne la implicit, cu problema ei; un element greșit dintr-o listă se scoate), lista albă a iconițelor și a
 *      fișierelor SVG (cele 19 iconițe de azi ale nucleului trec), adresele publice ale temei, DateSite::site și căile
 *      din pachet, Tema la rulare (blocul inline, logo-ul, versiunea) în cele trei locuri: pe server cu tema necopiată
 *      în public/tema/ (nicio legătură spre ea), sub routerul de dezvoltare (din pachet) și pe o găzduire simulată după
 *      copiere (din public/tema/); diagnostic.php --pachet (manifestul, LICENTA_SITE și SITE_SLUG, copia de verificare).
 *  T2, un server al codului curent pe CMS_DEV_PORT+21 cu pachetul tools/dev/site-demo-tema: efectul fiecărei chei în HTML
 *      (fonturile, Bootstrap, app.css, foile și scripturile temei, <main>, mesajele, butonul „Înapoi sus”, culoarea
 *      bannerelor și a paginilor de rezervă, logo-ul sertarului, iconițele categoriilor, blocul paginii de mulțumire),
 *      fișierele /tema/… servite (cele din afara tema/public/ nu), niciun avertisment PHP.
 *  T3, același port: fără temă, cu tema.json gol și cu fiecare cheie scrisă la valoarea ei implicită, paginile (și
 *      vederile de rezervă: 404, mulțumirea, linkul de plată, verificarea BT, sertarul de meniu) ies octet cu octet la fel,
 *      și au semnele codului de dinainte (Google Fonts, app.css, .container, fără butonul „sus”, #2f8d5b) și implicitele neutre
 *      din WP-16 (sertarul fără logo arată numele site-ului ca text, mulțumirea are paleta neutră). Că vederile
 *      de rezervă și sertarul sunt chiar cele ale Bioscem o arată comparația cu codul Bioscem (G-cmp, cazul „vederi” din
 *      tools/dev/compara-cu-baza.php); T3 compară codul curent doar cu el însuși.
 *  T4, Playwright (tools/dev/capturi-publice.php verificari, instrumentul porții G-vizual): stilurile calculate ale temei
 *      demo (Rubik încărcat și folosit, Bootstrap aplicat și pornit, scriptul temei, <main> lat, butonul sus fix după
 *      derulare, culoarea butonului 404, fără app.css).
 *
 * Pe durata ei, în baza de dezvoltare: paginile din bază „checkout/succes” și „404” nepublicate (ca să se vadă vederile
 * de rezervă) și un antet de test cu {{mobile_menu}}; la final totul pus la loc și cache-ul paginilor golit.
 */

use App\Support\Database;
use App\Support\DateSite;
use App\Support\HomeSections;
use App\Support\Paths;
use App\Support\ResponseCache;
use App\Support\SvgSigur;
use App\Support\Tema;

$scrie('');
$scrie('== T. Tema site-ului (tema.json; tools/dev/site-demo-tema): fiecare cheie, iar fără temă paginile neschimbate');

$tTemp = sys_get_temp_dir() . '/cms-test-tema-' . getmypid();
$tPachet = $tTemp . '/pachet';
@mkdir($tPachet . '/tema/public/css', 0770, true);
@mkdir($tPachet . '/tema/public/img', 0770, true);
$tScrie = static function (string $relativ, string $continut) use ($tPachet): void {
    @mkdir(dirname($tPachet . '/' . $relativ), 0770, true);
    file_put_contents($tPachet . '/' . $relativ, $continut);
};
$tJson = static fn (array $date): string => (string) json_encode($date, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$tImplicitExplicit = array_filter(Tema::IMPLICIT, static fn ($v): bool => $v !== null);

// ---------------------------------------------------------------------
// T1. Fără server
// ---------------------------------------------------------------------
DateSite::reseteaza($tTemp . '/fara-pachet');
Tema::reseteaza();
$verifica('fără dosarul site-ului: fiecare cheie la implicit, nicio problemă', Tema::valori() === Tema::IMPLICIT && Tema::probleme() === []);
// Implicitul scris aici de mână, nu luat din Tema::IMPLICIT: e ce face codul de dinainte de WP-13 (docs/PACHETE.md).
$tImplicitAzi = ['css' => [], 'incarca_app_css' => true, 'fonturi' => [], 'fonturi_google' => true, 'bootstrap' => false, 'js_final' => [],
    'main' => 'container', 'flash' => 'panou', 'buton_sus' => false, 'culoare_principala' => '#2f8d5b', 'logo_meniu_mobil' => null,
    'iconite_categorii' => null, 'css_inline' => [], 'newsletter' => null];
$verifica('valorile implicite ale celor ' . count($tImplicitAzi) . ' chei sunt comportamentul de azi (Google Fonts, app.css, .container, '
    . 'panourile, fără buton „sus”, #2f8d5b …)', Tema::IMPLICIT === $tImplicitAzi,
    (string) json_encode(array_diff_assoc(array_map('json_encode', Tema::IMPLICIT), array_map('json_encode', $tImplicitAzi)), JSON_UNESCAPED_SLASHES));
[$v, $p] = Tema::valideaza([], $tPachet);
[$v2, $p2] = Tema::valideaza($tImplicitExplicit + ['_despre' => 'comentariu'], $tPachet);
$verifica('tema.json gol și tema.json cu fiecare cheie scrisă la implicit (și un _despre): aceleași valori, nicio problemă',
    $v === Tema::IMPLICIT && $p === [] && $v2 === Tema::IMPLICIT && $p2 === [], implode('; ', array_merge($p, $p2)));

$tGresite = [
    'incarca_app_css' => 'nu', 'fonturi_google' => 1, 'bootstrap' => 'da', 'buton_sus' => null,
    'main' => 'ingust', 'flash' => 'alerta', 'culoare_principala' => 'verde', 'css' => '/tema/css/a.css',
    'js_final' => ['/assets/js/qty-stoc.js'], 'fonturi' => ['/assets/vendor/fonturi/nu-exista/nu-exista.css'],
    'logo_meniu_mobil' => ['candidati' => ['https://exemplu.test/logo.png'], 'implicit' => '/uploads/x.png', 'alt' => 'x'],
    'iconite_categorii' => ['ceai' => '<path d="M0 0"/>'], 'css_inline' => ['antet' => 'a.css'], 'culoare' => '#000',
    // WP-R din 30 oct (newsletterul): o culoare greșită (și, mai jos, logo-ul WebP, o culoare necunoscută, o cheie necunoscută).
    'newsletter' => ['culori' => ['principala' => 'verde']],
];
$tGresiteNeprinse = [];
foreach ($tGresite as $cheie => $valoare) {
    [$v, $p] = Tema::valideaza([$cheie => $valoare], $tPachet);
    if ($v !== Tema::IMPLICIT || count($p) !== 1) {
        $tGresiteNeprinse[] = $cheie . ' (' . count($p) . ' probleme)';
    }
}
$verifica('fiecare cheie cu o valoare greșită (' . count($tGresite) . ', și o cheie necunoscută): o problemă, cheia rămâne la implicit',
    $tGresiteNeprinse === [], implode(', ', $tGresiteNeprinse));

// WP-R din 30 oct (newsletterul): înfățișarea șabloanelor și a paginilor publice ale newsletterului; fiecare greșeală e o problemă și
// cheia rămâne la implicit (fără logo, paleta neutră), o cheie bună dă logo-ul, textul lui și culorile date peste cele neutre.
$tNlGresite = ['logo WebP (Outlook nu-l arată)' => ['logo' => '/uploads/gallery/logo.webp'], 'logo din afara site-ului' => ['logo' => 'https://exemplu.test/l.png'],
    'culoare necunoscută' => ['culori' => ['accent' => '#000000']], 'cheie necunoscută' => ['titlu' => 'x'], 'listă' => ['/uploads/l.png'],
    'text alternativ prea lung' => ['logo_alt' => str_repeat('a', 121)]];
$tNlNeprinse = [];
foreach ($tNlGresite as $ce => $valoare) {
    [$v, $p] = Tema::valideaza(['newsletter' => $valoare], $tPachet);
    if ($v !== Tema::IMPLICIT || count($p) !== 1) {
        $tNlNeprinse[] = $ce;
    }
}
[$v, $p] = Tema::valideaza(['newsletter' => ['_despre' => 'x', 'logo' => '/uploads/gallery/logo.png', 'logo_alt' => 'Firma Demo',
    'culori' => ['principala' => '#123456', 'linie' => '#abc']]], $tPachet);
$verifica('newsletter: ' . count($tNlGresite) . ' greșeli prinse, fiecare o problemă; una bună dă logo-ul, textul și culorile peste cele neutre',
    $tNlNeprinse === [] && $p === [] && $v['newsletter'] === ['culori' => ['principala' => '#123456', 'linie' => '#abc'],
        'logo' => '/uploads/gallery/logo.png', 'logo_alt' => 'Firma Demo'], implode(', ', $tNlNeprinse) . implode('; ', $p));

$tScrie('tema/public/css/a.css', "body{color:#123}\n");
$tScrie('tema/public/js/a.js', "void 0;\n");
[$v, $p] = Tema::valideaza(['css' => ['/tema/css/a.css', '/tema/css/lipsa.css', '/assets/css/app.css', '/tema/../tema.json'],
    'js_final' => ['/tema/js/a.js', '/tema/js/b.js'], 'fonturi' => ['/assets/vendor/fonturi/rubik/rubik.css']], $tPachet);
$verifica('listele: rămân doar adresele bune care există (o foaie lipsă, alta din afara temei, „..”, un script lipsă scoase)',
    $v['css'] === ['/tema/css/a.css'] && $v['js_final'] === ['/tema/js/a.js'] && $v['fonturi'] === ['/assets/vendor/fonturi/rubik/rubik.css']
    && count($p) === 4, implode('; ', $p));

[$v, $p] = Tema::valideaza(['iconite_categorii' => [['ceai', '<path d="M0 0h4"/>'], ['rau', '<script>alert(1)</script>'],
    ['Mare', '<path d="M0 0"/>'], ['pereche', '<circle cx="1" cy="1" r="1"/>', 'al treilea'], ['bun', '<circle cx="1" cy="1" r="1"/>']]], $tPachet);
$verifica('iconițele: perechile bune rămân, în ordine; cea cu <script>, cuvântul cu majuscule și o „pereche” de trei se scot',
    $v['iconite_categorii'] === [['ceai', '<path d="M0 0h4"/>'], ['bun', '<circle cx="1" cy="1" r="1"/>']] && count($p) === 3, implode('; ', $p));

$tScrie('tema/bun.css', ".a{color:red}\n");
$tScrie('tema/rau.css', ".a{}</STYLE><script>alert(1)</script>\n");
[$v, $p] = Tema::valideaza(['css_inline' => ['pagina_succes' => 'rau.css']], $tPachet);
[$v2, $p2] = Tema::valideaza(['css_inline' => ['pagina_succes' => 'lipsa.css']], $tPachet);
[$v3, $p3] = Tema::valideaza(['css_inline' => ['pagina_succes' => 'bun.css']], $tPachet);
$verifica('css_inline: un bloc cu „</style” (și cu majuscule) sau un fișier lipsă nu se folosesc; unul bun da',
    $v['css_inline'] === [] && count($p) === 1 && $v2['css_inline'] === [] && count($p2) === 1
    && $v3['css_inline'] === ['pagina_succes' => 'bun.css'] && $p3 === [], implode('; ', array_merge($p, $p2, $p3)));

// SvgSigur: iconița implicită a nucleului și cele mutate în WP-16 în pachetul Bioscem (tema.json → iconite_categorii) trec
// toate; fragmentele periculoase nu.
$tIconiteNucleu = ['implicita' => (string) (new ReflectionClassConstant(HomeSections::class, 'ICONITA_IMPLICITA'))->getValue()];
foreach ((array) (json_decode((string) file_get_contents($radacina . '/site-uri/bioscem/tema/tema.json'), true)['iconite_categorii'] ?? []) as $tPereche) {
    $tIconiteNucleu[(string) ($tPereche[0] ?? '?')] = (string) ($tPereche[1] ?? '');
}
$tIconiteRele = array_filter($tIconiteNucleu, static fn (string $svg): bool => !SvgSigur::fragment($svg));
$verifica('cele ' . count($tIconiteNucleu) . ' iconițe (cea implicită a nucleului și cele ale pachetului Bioscem) trec de lista albă (SvgSigur::fragment)',
    count($tIconiteNucleu) === 19 && $tIconiteRele === [], implode(', ', array_keys($tIconiteRele)));
$tFragmenteRele = ['<script>alert(1)</script>', '<path d="M0 0" onload="alert(1)"/>', '<a href="javascript:alert(1)"><path d="M0 0"/></a>',
    '<path d="M0 0" style="fill:url(https://exemplu.test/x)"/>', '<image href="https://exemplu.test/x.png"/>', '<path d="&#x4d;0 0"/>',
    '<!-- x --><path d="M0 0"/>', '<![CDATA[<script>]]>', 'text', '<foreignObject><p>x</p></foreignObject>',
    '<path d="M0 0" fill="url(#x)"/>', '<PATH d="M0 0"/>', '<path d="M0 0"', '', '<path d="M0 0;alert(1)"/>',
    // Elemente goale, fără atribute și fără text: le oprește doar lista elementelor.
    '<script/>', '<animate/>', '<set/>', '<use/>', '<style/>'];
$tFragmenteTrecute = array_filter($tFragmenteRele, static fn (string $svg): bool => SvgSigur::fragment($svg));
$verifica('lista albă a iconițelor refuză ' . count($tFragmenteRele) . ' fragmente periculoase (script, on…, link, url(), imagine, '
    . 'entitate, comentariu, CDATA, text, foreignObject, alt atribut, majuscule, XML stricat, gol, alt semn, elemente goale nepermise)', $tFragmenteTrecute === [],
    implode(' | ', $tFragmenteTrecute));
$tFisiereRele = [
    '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>',
    '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="https://exemplu.test/a.svg#x"/></svg>',
    '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>',
    '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><p>x</p></foreignObject></svg>',
    '<svg xmlns="http://www.w3.org/2000/svg"><style>@import url(https://exemplu.test/a.css);</style></svg>',
    '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1" fill="url(https://exemplu.test/#g)"/></svg>',
    '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><rect width="1" height="1"/></a></svg>',
    '<html><body>nu e svg</body></html>',
];
$tFisiereTrecute = array_filter($tFisiereRele, static fn (string $svg): bool => SvgSigur::problemaFisier($svg) === null);
$verifica('lista albă a fișierelor .svg: logo-ul temei demo trece, ' . count($tFisiereRele) . ' fișiere periculoase nu',
    SvgSigur::problemaFisier((string) file_get_contents($radacina . '/tools/dev/site-demo-tema/tema/public/img/logo-demo.svg')) === null
    && $tFisiereTrecute === [], implode(' | ', $tFisiereTrecute));

$tAdreseRele = ['/tema/../tema.json', '/tema//css/a.css', '/etc/passwd', 'tema/css/a.css', '/tema/css/.ascuns', '/tema/', '/uploads/../.env',
    'https://exemplu.test/tema/a.css', '/tema/css/a.css?v=1'];
$tAdreseTrecute = array_filter($tAdreseRele, static fn (string $a): bool => Tema::fisierPublic($a) !== null);
$verifica('adresele publice ale temei: „..”, „//”, căi relative, ascunse, externe sau cu parametri nu duc la niciun fișier',
    $tAdreseTrecute === [] && Tema::fisierPublic('/tema/css/a.css') !== null, implode(' | ', $tAdreseTrecute));

// DateSite::site și căile din pachet.
$tScrie('site.json', $tJson(['cookie_consimtamant' => 'demo_consimtamant', 'grup' => ['cheie' => 2]]));
$tScrie('tema/public/img/doar-in-pachet.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><rect width="1" height="1"/></svg>');
$tScrie('tema/tema.json', $tJson(['culoare_principala' => '#123456', 'css' => ['/tema/css/a.css'], 'css_inline' => ['pagina_succes' => 'bun.css'],
    'logo_meniu_mobil' => ['candidati' => ['/tema/img/lipsa.svg', '/tema/img/doar-in-pachet.svg'], 'implicit' => '/tema/img/implicit.svg',
        'alt' => 'Logo demo']]));
DateSite::reseteaza($tPachet);
Tema::reseteaza();
$verifica('DateSite::site: valoarea, o cale „grup.cheie”, implicitul pentru o cheie lipsă; DateSite::json citește tema/tema.json, '
    . 'dar nu „../” și nici o cale absolută', DateSite::site('cookie_consimtamant', 'x') === 'demo_consimtamant'
    && DateSite::site('grup.cheie') === 2 && DateSite::site('lipsa', 'implicit') === 'implicit'
    && (DateSite::json('tema/tema.json')['culoare_principala'] ?? null) === '#123456'
    && DateSite::json('../pachet/site.json') === [] && DateSite::json($tPachet . '/site.json') === []);
// Aici (linia de comandă, fără tools/dev/router.php) e ca pe server: /tema/… se servește doar din public/tema/, unde
// fișierele acestui pachet nu sunt. Deci nicio legătură spre ele: foaia lipsește, logo-ul e cel implicit.
$tLogo = Tema::logoMeniuMobil();
$verifica('Tema la rulare, pe server cu tema necopiată în public/tema/: blocul inline și culoarea da; foaia nu intră în pagină, '
    . 'adresa ei nu primește ?v=, logo-ul e cel implicit (cel din pachet nu se servește)',
    Tema::cssInline('pagina_succes', 'IMPLICIT') === ".a{color:red}\n" && Tema::cssInline('alt_bloc', 'IMPLICIT') === 'IMPLICIT'
    && Tema::culoarePrincipala() === '#123456' && Tema::css() === [] && Tema::url('/tema/css/a.css') === '/tema/css/a.css'
    && Tema::fisierPublic('/tema/css/a.css') === Paths::public('tema/css/a.css')
    && $tLogo === ['cale' => '/tema/img/implicit.svg', 'alt' => 'Logo demo'], json_encode([Tema::css(), $tLogo], JSON_UNESCAPED_SLASHES));
// Aceeași temă într-un proces nou, cu constantele date: a routerului de dezvoltare (servește /tema/… din pachet) sau
// docroot-ul unei găzduiri simulate (PLATFORMA_PUBLIC_DIR, App\Support\Paths) în care tema a fost copiată.
$tTemaInProces = static function (array $constante) use ($radacina, $tPachet): array {
    $cod = 'foreach (json_decode($argv[1], true) as $k => $v) { define($k, $v); } require $argv[2] . "/bootstrap.php";'
        . ' echo json_encode(["css" => App\Support\Tema::css(), "url" => App\Support\Tema::url("/tema/css/a.css"),'
        . ' "logo" => App\Support\Tema::logoMeniuMobil()], JSON_UNESCAPED_SLASHES);';
    $iesire = (string) shell_exec('env SITE_DATE_DOSAR=' . escapeshellarg($tPachet) . ' ' . escapeshellarg(PHP_BINARY) . ' -r '
        . escapeshellarg($cod) . ' ' . escapeshellarg((string) json_encode($constante)) . ' ' . escapeshellarg($radacina) . ' 2>/dev/null');
    return (array) json_decode($iesire, true);
};
$tSubRouter = $tTemaInProces(['ANDAXI_DEV_TEMA_DIN_PACHET' => $tPachet . '/tema/public']);
$verifica('sub routerul de dezvoltare (ANDAXI_DEV_TEMA_DIN_PACHET): foaia și logo-ul din pachet, cu ?v=<data fișierului din pachet>',
    $tSubRouter === ['css' => ['/tema/css/a.css'], 'url' => '/tema/css/a.css?v=' . filemtime($tPachet . '/tema/public/css/a.css'),
        'logo' => ['cale' => '/tema/img/doar-in-pachet.svg?v=' . filemtime($tPachet . '/tema/public/img/doar-in-pachet.svg'), 'alt' => 'Logo demo']],
    json_encode($tSubRouter, JSON_UNESCAPED_SLASHES));
@mkdir($tTemp . '/docroot/tema/css', 0770, true);
@mkdir($tTemp . '/docroot/tema/img', 0770, true);
copy($tPachet . '/tema/public/css/a.css', $tTemp . '/docroot/tema/css/a.css');
copy($tPachet . '/tema/public/img/doar-in-pachet.svg', $tTemp . '/docroot/tema/img/doar-in-pachet.svg');
touch($tTemp . '/docroot/tema/css/a.css', 1700000000);
touch($tTemp . '/docroot/tema/img/doar-in-pachet.svg', 1700000001);
$tGazduire = $tTemaInProces(['PLATFORMA_PUBLIC_DIR' => $tTemp . '/docroot']);
$verifica('pe server, după copierea temei în public/tema/: foaia și logo-ul de acolo, cu ?v=<data copiei>',
    $tGazduire === ['css' => ['/tema/css/a.css'], 'url' => '/tema/css/a.css?v=1700000000',
        'logo' => ['cale' => '/tema/img/doar-in-pachet.svg?v=1700000001', 'alt' => 'Logo demo']],
    json_encode($tGazduire, JSON_UNESCAPED_SLASHES));
$tScrie('site.json', '{"stricat": ');
DateSite::reseteaza($tPachet);
$verifica('site.json stricat: valorile implicite (nu oprește site-ul)', DateSite::site('cookie_consimtamant', 'implicit') === 'implicit');
DateSite::reseteaza();
Tema::reseteaza();

// scripts/diagnostic.php --pachet (pe server, la adoptare): manifestul, site-ul din .env (LICENTA_SITE și SITE_SLUG, ambele
// ale pachetului; pe copia de verificare licența e a copiei), copiile publice.
$tDiagnostic = static function (string $pachet, string $site, ?string $slug = null, bool $copie = false) use ($radacina): array {
    exec('cd ' . escapeshellarg($radacina) . ' && env SITE_DATE_DOSAR=' . escapeshellarg($pachet) . ' LICENTA_SITE=' . escapeshellarg($site)
        . ' SITE_SLUG=' . escapeshellarg($slug ?? $site) . ' SITE_COPIE_VERIFICARE=' . ($copie ? '1' : '') . ' '
        . escapeshellarg(PHP_BINARY) . ' scripts/diagnostic.php --pachet 2>&1', $iesire, $cod);
    return [$cod, implode("\n", $iesire)];
};
exec('mkdir -p ' . escapeshellarg($tTemp . '/schimbat') . ' && cp ' . escapeshellarg($radacina . '/tools/dev/site-demo') . '/* ' . escapeshellarg($tTemp . '/schimbat/'));
file_put_contents($tTemp . '/schimbat/seo.json', "{}\n");
$tDemo = $radacina . '/tools/dev/site-demo';
$tCazuriDiagnostic = [
    'pachetul demo' => [$tDemo, 'demo', null, 0, 'rezultat     : ok'],
    'un fișier schimbat' => [$tTemp . '/schimbat', 'demo', null, 1, 'fișier schimbat față de pachet.json (sha256): seo.json'],
    'alt site' => [$tDemo, 'altul', null, 1, 'nu al lui „altul”'],
    'tema necopiată în public/tema/' => [$radacina . '/tools/dev/site-demo-tema', 'demo', null, 1, 'public/tema/css/tema-demo.css lipsește'],
    'dosarul lipsă' => [$tTemp . '/nu-exista', 'demo', null, 1, 'dosarul pachetului lipsește'],
    'fără LICENTA_SITE' => [$tDemo, '', 'demo', 1, 'LICENTA_SITE lipsește din .env'],
    'fără SITE_SLUG' => [$tDemo, 'demo', '', 1, 'SITE_SLUG lipsește din .env'],
    'SITE_SLUG al altui site' => [$tDemo, 'demo', 'altul', 1, 'SITE_SLUG („altul”) diferă de LICENTA_SITE („demo”)'],
    'doar SITE_SLUG, al altui site' => [$tDemo, '', 'altul', 1, 'nu al lui „altul”'],
    // Copia de verificare (docs/VERIFICARE.md): licența ei („verificare…”), pachetul site-ului copiat (SITE_SLUG).
    'copia de verificare' => [$tDemo, 'verificare', 'demo', 0, 'rezultat     : ok', true],
    'copia de verificare, cu pachetul altui site' => [$tDemo, 'verificare', 'altul', 1, 'nu al lui „altul”', true],
    'licența „verificare” fără SITE_COPIE_VERIFICARE' => [$tDemo, 'verificare', 'demo', 1, 'diferă de LICENTA_SITE („verificare”)'],
];
$tDiagnosticGresit = [];
foreach ($tCazuriDiagnostic as $nume => [$pachet, $site, $slug, $codAsteptat, $text]) {
    [$cod, $iesire] = $tDiagnostic($pachet, $site, $slug, (bool) ($tCazuriDiagnostic[$nume][5] ?? false));
    if ($cod !== $codAsteptat || !str_contains($iesire, $text)) {
        $tDiagnosticGresit[] = $nume . ': cod ' . $cod . ' (aștept ' . $codAsteptat . '), „' . $text . '” ' . (str_contains($iesire, $text) ? 'găsit' : 'negăsit');
    }
}
$verifica('diagnostic.php --pachet: pachetul demo ok (0); un fișier schimbat, alt site, fișierele temei necopiate în public/tema/, un '
    . 'dosar lipsă, LICENTA_SITE sau SITE_SLUG lipsă și SITE_SLUG al altui site ies cu 1, cu motivul; pe copia de verificare '
    . 'pachetul e al lui SITE_SLUG (' . count($tCazuriDiagnostic) . ' cazuri)',
    $tDiagnosticGresit === [], implode('; ', $tDiagnosticGresit));

// ---------------------------------------------------------------------
// T2–T4. Un server al codului curent cu un pachet ales (CMS_DEV_PORT+21)
// ---------------------------------------------------------------------
$tPort = (int) (getenv('CMS_DEV_PORT') ?: '8701') + 21;
$tBaza = 'http://127.0.0.1:' . $tPort;
$tPid = 0;
$tOpreste = static function () use (&$tPid): void {
    if ($tPid > 0) {
        exec('pkill -TERM -P ' . $tPid . ' 2>/dev/null; kill ' . $tPid . ' 2>/dev/null');
        for ($i = 0; $i < 50 && (trim((string) shell_exec('pgrep -P ' . $tPid . ' 2>/dev/null')) !== '' || is_dir('/proc/' . $tPid)); $i++) {
            usleep(100000);
        }
        $tPid = 0;
    }
};
$tCerere = static function (string $metoda, string $cale, ?string $jar = null, array $date = []) use ($tBaza): array {
    $c = curl_init($tBaza . $cale);
    $antete = [];
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metoda,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOPROXY => '*',
        CURLOPT_PROXY => '',
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$antete): int {
            if (str_contains($linie, ':')) {
                [$nume, $valoare] = explode(':', $linie, 2);
                $antete[strtolower(trim($nume))] = trim($valoare);
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
    return ['cod' => $cod, 'antete' => $antete, 'corp' => $corp];
};
$tPorneste = static function (string $pachet) use ($radacina, $dosarDev, $tPort, $tBaza, $tCerere, $tOpreste, &$tPid): bool {
    $tOpreste();
    $ini = [];
    foreach (['pdo_mysql.default_socket', 'error_reporting', 'display_errors', 'log_errors', 'error_log', 'sendmail_path', 'memory_limit'] as $cheie) {
        $valoare = ini_get($cheie);
        if ($valoare !== false && $valoare !== '') {
            $ini[] = '-d ' . escapeshellarg($cheie . '=' . $valoare);
        }
    }
    $ini[] = '-d allow_url_fopen=0';
    $comanda = 'cd ' . escapeshellarg($radacina) . ' && env PHP_CLI_SERVER_WORKERS=2 SITE_DATE_DOSAR=' . escapeshellarg($pachet)
        . ' APP_URL=' . escapeshellarg($tBaza) . ' nohup ' . escapeshellarg(PHP_BINARY) . ' ' . implode(' ', $ini) . ' -S 127.0.0.1:' . $tPort
        . ' -t public tools/dev/router.php >> ' . escapeshellarg($dosarDev . '/server-tema-' . $tPort . '.log') . ' 2>&1 & echo $!';
    $tPid = (int) trim((string) shell_exec($comanda));
    for ($i = 0; $i < 50; $i++) {
        if ($tCerere('GET', '/health')['cod'] === 200) {
            return true;
        }
        usleep(200000);
    }
    return false;
};

$tApp = require $radacina . '/config/app.php';
$tDb = Database::connection((array) $tApp['db']);
$tPaginiBaza = $tDb instanceof PDO
    ? $tDb->query("SELECT id, is_published FROM pages WHERE slug IN ('checkout/succes', '404')")->fetchAll(PDO::FETCH_KEY_PAIR) : [];
$tAntetVechi = $tDb instanceof PDO ? $tDb->query("SELECT value FROM settings WHERE `key` = 'design_header_html'")->fetchColumn() : false;
$tFisierErori = $dosarDev . '/php-erori.log';
clearstatcache(true, $tFisierErori);
$tPozitieErori = (int) @filesize($tFisierErori);
$tJar = $tTemp . '/jar.txt';
$tNr = $tDb instanceof PDO ? (string) $tDb->query('SELECT order_number FROM orders WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1')->fetchColumn() : '';
$tToken = $tDb instanceof PDO ? (string) $tDb->query('SELECT token FROM order_payment_links ORDER BY id LIMIT 1')->fetchColumn() : '';
$tIdProdus = $tDb instanceof PDO ? (string) $tDb->query("SELECT id FROM products WHERE slug = 'ceai-de-menta'")->fetchColumn() : '';
$tTema = $radacina . '/tools/dev/site-demo-tema';

try {
    if (!$tDb instanceof PDO || count($tPaginiBaza) !== 2 || $tNr === '' || $tToken === '' || $tIdProdus === '') {
        throw new RuntimeException('baza de dezvoltare nu are datele demo (paginile checkout/succes și 404, o comandă, un link de plată)');
    }
    // Vederile de rezervă (pagina 404 și cea de mulțumire ale nucleului) se văd doar fără paginile lor din bază; antetul
    // de test are sertarul de meniu ({{mobile_menu}}), al cărui logo îl alege tema.
    $tDb->exec("UPDATE pages SET is_published = 0 WHERE slug IN ('checkout/succes', '404')");
    $tDb->prepare('INSERT INTO settings (`key`, value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE value = VALUES(value)')
        ->execute(['k' => 'design_header_html', 'v' => '<header class="antet-test-tema"><a href="/">Firma Demo</a>{{mobile_menu}}</header>']);
    ResponseCache::purgePageCache();

    // T2. Tema demo: efectul fiecărei chei.
    if (!$tPorneste($tTema)) {
        throw new RuntimeException('serverul cu tema demo nu pornește pe portul ' . $tPort);
    }
    @unlink($tJar);
    $acasa = $tCerere('GET', '/', $tJar)['corp'];
    $pozitie = static fn (string $text): int => ($p = strpos($acasa, $text)) === false ? -1 : $p;
    $pRubik = $pozitie('<link rel="stylesheet" href="/assets/vendor/fonturi/rubik/rubik.css?v=');
    $pBootstrap = $pozitie('<link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css?v=');
    $pCssTema = $pozitie('<link rel="stylesheet" href="/tema/css/tema-demo.css?v=');
    $verifica('fonturi, fonturi_google, bootstrap, incarca_app_css, css: Rubik din nucleu, Bootstrap, foaia temei (în această ordine), '
        . 'fără Google Fonts și fără app.css', $pRubik > 0 && $pBootstrap > $pRubik && $pCssTema > $pBootstrap
        && !str_contains($acasa, 'fonts.googleapis.com') && !str_contains($acasa, 'fonts.gstatic.com') && !str_contains($acasa, '/assets/css/app.css'),
        "rubik $pRubik, bootstrap $pBootstrap, tema $pCssTema");
    $verifica('main „lat”: <main class="site-container">, fără .container', str_contains($acasa, '<main class="site-container">')
        && !str_contains($acasa, '<main class="container site-container">'));
    $verifica('buton_sus: ținta #inceput-pagina imediat după <body>, ancora și scriptul ei înainte de </body>; js_final și Bootstrap '
        . 'cu defer, în ordine', str_contains($acasa, "<body class=\"site-shell\">\n<span id=\"inceput-pagina\"></span>\n")
        && preg_match('#<script src="/assets/vendor/bootstrap/js/bootstrap\.bundle\.min\.js\?v=\d+" defer></script>\n'
            . '<script src="/tema/js/tema-demo\.js\?v=\d+" defer></script>\n<a class="sus" href="\#inceput-pagina" aria-label="Înapoi sus" hidden>#u', $acasa) === 1
        && preg_match('#inceput\.focus\(\{ preventScroll: true \}\);.*</script>\n</body>#s', $acasa) === 1);
    $verifica('iconite_categorii: „Ceaiuri” și „Miere” cu iconițele temei; celelalte categorii cu cea implicită a nucleului',
        str_contains($acasa, '<path d="M4 8h12v6a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4z"/><path d="M16 10h2a2 2 0 0 1 0 4h-2"/>')
        && str_contains($acasa, '<circle cx="12" cy="12" r="6"/><path d="M12 6v12"/>') && str_contains($acasa, 'M19.1 5.1c-6'));
    $verifica('logo_meniu_mobil: primul candidat care există (logo-ul temei, cu ?v=), cu textul alternativ al temei',
        preg_match('#<img src="/tema/img/logo-demo\.svg\?v=\d+" alt="Firma Demo \(tema\)" loading="lazy">#', $acasa) === 1);
    $verifica('culoare_principala în bannerul de cookie-uri (linkul și „Accept toate”) și în popup („Am înțeles”)',
        str_contains($acasa, 'style="color:#0f766e;text-decoration:underline;">Politica de confidențialitate</a>')
        && str_contains($acasa, 'background:#0f766e;color:#ffffff;font-size:14px;font-weight:700;cursor:pointer;">Accept toate</button>')
        && str_contains($acasa, 'background:#0f766e;color:#ffffff;font-size:15px;font-weight:700;cursor:pointer;">Am înțeles</button>'));

    $tCerere('POST', '/cos/adauga/' . $tIdProdus, $tJar, ['quantity' => '1']);
    $cos = $tCerere('GET', '/cos', $tJar)['corp'];
    $tCerere('POST', '/cos/adauga/999999999', $tJar, ['quantity' => '1']);
    $magazin = $tCerere('GET', '/magazin', $tJar)['corp'];
    $verifica('flash „bootstrap”: mesajele ca .alert într-un .container propriu (succesul pe /cos, eroarea pe /magazin)',
        str_contains($cos, "<div class=\"container mt-3\">\n                <div class=\"alert alert-success mb-0\">Produs adăugat în coș.</div>\n            </div>")
        && str_contains($magazin, '<div class="alert alert-danger mb-0">Produsul nu a fost găsit.</div>')
        && !str_contains($cos, 'style="border-color:#34d399;'));

    $pagina404 = $tCerere('GET', '/pagina-care-nu-exista', $tJar);
    $succes = $tCerere('GET', '/checkout/succes/' . rawurlencode($tNr), $tJar)['corp'];
    $plata = $tCerere('GET', '/plata/' . rawurlencode($tToken), $tJar)['corp'];
    $bt = $tCerere('GET', '/checkout/bt/retur', $tJar)['corp'];
    $verifica('culoare_principala pe paginile de rezervă: 404 (vederea nucleului), linkul de plată, verificarea plății BT',
        $pagina404['cod'] === 404 && substr_count($pagina404['corp'], 'background:#0f766e;color:#fff;') === 2
        && str_contains($plata, 'background:#0f766e;color:#fff;') && str_contains($bt, 'background:#0f766e;color:#fff;')
        && !str_contains($pagina404['corp'] . $plata . $bt, '#2f8d5b'));
    $verifica('css_inline.pagina_succes: între <style> și </style> exact tema/pagina-succes.css, fără blocul nucleului',
        str_contains($succes, "<style>\n" . file_get_contents($tTema . '/tema/pagina-succes.css') . '</style>')
        && !str_contains($succes, '--bs-succes-accent:#075d61'));

    $fisiereTema = [
        '/tema/css/tema-demo.css' => ['text/css; charset=utf-8', 'tema/public/css/tema-demo.css'],
        '/tema/js/tema-demo.js' => ['text/javascript; charset=utf-8', 'tema/public/js/tema-demo.js'],
        '/tema/img/logo-demo.svg' => ['image/svg+xml', 'tema/public/img/logo-demo.svg'],
    ];
    $servite = true;
    foreach ($fisiereTema as $adresa => [$tip, $fisier]) {
        $r = $tCerere('GET', $adresa . '?v=1');
        $servite = $servite && $r['cod'] === 200 && ($r['antete']['content-type'] ?? '') === $tip && $r['corp'] === file_get_contents($tTema . '/' . $fisier);
    }
    $refuzate = [];
    foreach (['/tema/pagina-succes.css', '/tema/tema.json', '/tema/../tema/tema.json', '/tema/%2e%2e/tema.json', '/tema/public/css/tema-demo.css'] as $adresa) {
        $r = $tCerere('GET', $adresa);
        if ($r['cod'] !== 404 || str_contains($r['corp'], 'font-family') || str_contains($r['corp'], '"css_inline"')) {
            $refuzate[] = $adresa . ' → ' . $r['cod'];
        }
    }
    $vendor = [];
    foreach (['/assets/vendor/fonturi/rubik/rubik.css', '/assets/vendor/fonturi/rubik/rubik-latin.woff2', '/assets/vendor/fonturi/dm-sans/dm-sans.css',
        '/assets/vendor/fonturi/playfair/playfair-latin-ext.woff2', '/assets/vendor/bootstrap/css/bootstrap.min.css', '/assets/vendor/bootstrap/js/bootstrap.bundle.min.js'] as $adresa) {
        $r = $tCerere('GET', $adresa);
        if ($r['cod'] !== 200 || $r['corp'] !== file_get_contents($radacina . '/public' . $adresa)) {
            $vendor[] = $adresa . ' → ' . $r['cod'];
        }
    }
    $verifica('fișierele temei la /tema/… (din tema/public/ a pachetului, cu tipul lor), nimic din afara tema/public/; fonturile și '
        . 'Bootstrap din nucleu', $servite && $refuzate === [] && $vendor === [], implode(', ', array_merge($refuzate, $vendor)));

    // T4. Stilurile calculate ale temei demo (Playwright, capturi-publice.php verificari).
    $tVerificari = [
        ['eticheta' => 'flash „bootstrap”: .alert-success cu fundalul Bootstrap', 'cale' => '/cos',
            'js' => "(() => { const a = document.querySelector('.alert.alert-success'); return a ? getComputedStyle(a).backgroundColor : null; })()",
            'asteptat' => 'rgb(209, 231, 221)'],
        ['eticheta' => 'fonturi: Rubik (din nucleu) încărcat', 'cale' => '/',
            'js' => "[...document.fonts].some((f) => f.family.replace(/[\"']/g, '') === 'Rubik' && f.status === 'loaded')", 'asteptat' => true],
        ['eticheta' => 'css: corpul paginii scrie cu Rubik (foaia temei)', 'cale' => '/',
            'js' => "getComputedStyle(document.body).fontFamily.split(',')[0].replace(/[\"']/g, '').trim()", 'asteptat' => 'Rubik'],
        ['eticheta' => 'incarca_app_css false: nicio foaie app.css', 'cale' => '/',
            'js' => "[...document.styleSheets].some((s) => (s.href || '').includes('/assets/css/app.css'))", 'asteptat' => false],
        ['eticheta' => 'bootstrap: JavaScript-ul Bootstrap pornit', 'cale' => '/',
            'js' => "typeof window.bootstrap === 'object' && typeof window.bootstrap.Modal === 'function'", 'asteptat' => true],
        ['eticheta' => 'js_final: scriptul temei a rulat', 'cale' => '/', 'js' => "document.documentElement.getAttribute('data-tema-demo')", 'asteptat' => 'da'],
        ['eticheta' => 'main „lat”: <main> pe toată lățimea ferestrei', 'cale' => '/',
            'js' => "Math.round(document.querySelector('main').getBoundingClientRect().width) === document.documentElement.clientWidth", 'asteptat' => true],
        ['eticheta' => 'buton_sus: ascuns cât pagina nu e derulată', 'cale' => '/magazin', 'js' => "document.querySelector('a.sus').hidden", 'asteptat' => true],
        ['eticheta' => 'buton_sus: fix și vizibil după derulare', 'cale' => '/magazin', 'deruleaza' => true,
            'js' => "(() => { const a = document.querySelector('a.sus'); const s = getComputedStyle(a); return !a.hidden && s.position === 'fixed' && s.display !== 'none'; })()",
            'asteptat' => true],
        ['eticheta' => 'culoare_principala: butonul „Caută” al paginii 404', 'cale' => '/pagina-care-nu-exista',
            'js' => "getComputedStyle(document.querySelector('form[action=\"/magazin\"] button')).backgroundColor", 'asteptat' => 'rgb(15, 118, 110)'],
        ['eticheta' => 'buton_sus la 390 px: ascuns cât pagina nu e derulată', 'cale' => '/', 'latime' => 390,
            'js' => "document.querySelector('a.sus').hidden", 'asteptat' => true],
    ];
    file_put_contents($tTemp . '/verificari.json', $tJson($tVerificari));
    exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' tools/dev/capturi-publice.php verificari --server=' . escapeshellarg($tBaza)
        . ' --verificari=' . escapeshellarg($tTemp . '/verificari.json') . ' --dosar=tema 2>&1', $iesireVizual, $codVizual);
    $gresite = array_values(array_filter($iesireVizual, static fn (string $l): bool => str_starts_with(trim($l), 'EȘEC') || str_starts_with(trim($l), 'EROARE')));
    $verifica('stilurile calculate ale temei demo (' . count($tVerificari) . ' verificări cu Playwright; '
        . trim((string) end($iesireVizual)) . ')', $codVizual === 0, implode("\n          ", $gresite !== [] ? $gresite : array_slice($iesireVizual, -5)));

    // T3. Fără temă, cu tema.json gol și cu fiecare cheie la implicit: aceleași pagini, octet cu octet.
    $paginiT3 = ['/', '/magazin', '/produs/ceai-de-menta', '/cos', '/blog', '/termeni-si-conditii', '/pagina-care-nu-exista',
        '/checkout/succes/' . $tNr, '/plata/' . $tToken, '/checkout/bt/retur'];
    $captura = static function () use ($paginiT3, $tCerere, $tTemp, $tIdProdus): array {
        $rezultat = [];
        foreach ($paginiT3 as $cale) {
            $jar = $tTemp . '/jar-t3.txt';
            @unlink($jar);
            $tCerere('POST', '/cos/adauga/' . $tIdProdus, $jar, ['quantity' => '2']);
            $tCerere('GET', '/cos', $jar);
            $r = $tCerere('GET', $cale, $jar);
            // Valorile care se schimbă la fiecare cerere: jetoanele formularelor și (WP-21) momentul deschiderii formularului
            // de recenzie (review_opened_at, secunda cererii).
            $rezultat[$cale] = $r['cod'] . "\n" . (string) preg_replace(['/name="(_token|checkout_form_token|register_form_token|review_opened_at)" value="[^"]*"/',
                '/data-csrf="[^"]*"/', '/checkout-[0-9a-f]{10}/'], ['name="$1" value="T"', 'data-csrf="T"', 'checkout-ID'], $r['corp']);
        }
        return $rezultat;
    };
    $pachetDemo = $radacina . '/tools/dev/site-demo';
    exec('rm -rf ' . escapeshellarg($tTemp . '/gol') . ' ' . escapeshellarg($tTemp . '/implicit') . ' && mkdir -p ' . escapeshellarg($tTemp . '/gol/tema')
        . ' ' . escapeshellarg($tTemp . '/implicit/tema') . ' && cp ' . escapeshellarg($pachetDemo) . '/*.json ' . escapeshellarg($tTemp . '/gol/')
        . ' && cp ' . escapeshellarg($pachetDemo) . '/*.json ' . escapeshellarg($tTemp . '/implicit/'));
    file_put_contents($tTemp . '/gol/tema/tema.json', '{}');
    file_put_contents($tTemp . '/implicit/tema/tema.json', $tJson($tImplicitExplicit));
    $variante = [];
    foreach (['fără temă (tools/dev/site-demo)' => $pachetDemo, 'tema.json gol' => $tTemp . '/gol', 'fiecare cheie scrisă la implicit' => $tTemp . '/implicit'] as $nume => $pachet) {
        if (!$tPorneste($pachet)) {
            throw new RuntimeException('serverul nu pornește cu pachetul ' . $pachet);
        }
        $variante[$nume] = $captura();
    }
    $referinta = reset($variante);
    $diferite = [];
    foreach (array_slice($variante, 1, null, true) as $nume => $pagini) {
        foreach ($pagini as $cale => $corp) {
            if ($corp !== $referinta[$cale]) {
                $diferite[] = $nume . ': ' . $cale;
            }
        }
    }
    $verifica('fără temă = tema.json gol = fiecare cheie scrisă la implicit: ' . count($paginiT3) . ' pagini (și vederile de rezervă, '
        . 'sertarul de meniu) octet cu octet la fel', $diferite === [] && count($referinta) === count($paginiT3)
        // Implicitele neutre ale nucleului (WP-16): sertarul fără logo arată numele site-ului ca text, mulțumirea are paleta neutră.
        && str_contains($referinta['/'], '<span class="bv-mobile-menu-token__brand-text">')
        && str_contains($referinta['/checkout/succes/' . $tNr], '--bs-succes-accent:#334155'),
        implode(', ', $diferite));
    // Implicitul însuși e comportamentul de azi (o valoare implicită schimbată ar schimba la fel toate cele trei variante).
    $acasaImplicit = $referinta['/'];
    $semneVechi = [
        'Google Fonts' => str_contains($acasaImplicit, 'https://fonts.googleapis.com/css2?'),
        'app.css' => preg_match('#<link rel="stylesheet" href="/assets/css/app\.css\?v=[^"]+">#', $acasaImplicit) === 1,
        '<main class="container site-container">' => str_contains($acasaImplicit, '<main class="container site-container">'),
        'fără ținta butonului „sus”' => !str_contains($acasaImplicit, 'inceput-pagina') && !str_contains($acasaImplicit, 'class="sus"'),
        'fără /assets/vendor/ și /tema/' => !str_contains($acasaImplicit, '/assets/vendor/') && !str_contains($acasaImplicit, '"/tema/'),
        'culoarea #2f8d5b pe 404' => str_contains($referinta['/pagina-care-nu-exista'], 'background:#2f8d5b;'),
    ];
    $verifica('fără temă paginile sunt cele de dinainte de WP-13: ' . implode(', ', array_keys($semneVechi)), !in_array(false, $semneVechi, true),
        implode(', ', array_keys(array_filter($semneVechi, static fn (bool $ok): bool => !$ok))));
} catch (Throwable $e) {
    $verifica('testul temei', false, $e->getMessage());
} finally {
    $tOpreste();
    if ($tDb instanceof PDO) {
        $pune = $tDb->prepare('UPDATE pages SET is_published = :p WHERE id = :id');
        foreach ($tPaginiBaza as $id => $publicata) {
            $pune->execute(['p' => $publicata, 'id' => $id]);
        }
        if ($tAntetVechi === false) {
            $tDb->exec("DELETE FROM settings WHERE `key` = 'design_header_html'");
        } else {
            $tDb->prepare("UPDATE settings SET value = :v WHERE `key` = 'design_header_html'")->execute(['v' => $tAntetVechi]);
        }
    }
    ResponseCache::purgePageCache();
    exec('rm -rf ' . escapeshellarg($tTemp));
    DateSite::reseteaza();
    Tema::reseteaza();
}
clearstatcache(true, $tFisierErori);
$tEroriNoi = array_values(array_filter(array_map('trim', explode("\n", (string) @file_get_contents($tFisierErori, false, null, $tPozitieErori))),
    static fn (string $l): bool => $l !== '' && preg_match('/PHP (Warning|Notice|Fatal|Parse)/', $l) === 1));
$verifica('jurnalul PHP: niciun avertisment sau eroare nouă în testul temei', $tEroriNoi === [], implode("\n          ", array_slice($tEroriNoi, 0, 10)));
