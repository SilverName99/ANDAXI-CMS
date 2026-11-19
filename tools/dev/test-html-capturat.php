<?php

declare(strict_types=1);

/**
 * Testele politicii HTML-ului capturat (App\Support\HtmlCapturat, HtmlCapturat\{ContextCapturat, SchemaDivi4, Problema}),
 * poarta G-html-capturat, pasul 3 al adoptării unui site WordPress (INSTRUCTIUNI-BUILDER-C2 §3, DECIZIE §2.9). Pe un
 * corpus INVENTAT („Pensiunea Probă”; nicio pagină, niciun DOM și niciun CSS al unui client):
 *
 *  1. un fixture curat, „ca Divi”, inventat, trece neschimbat (0 probleme) și, prin Editare fără nicio editare, octet cu
 *     octet;
 *  2. autotestul cu încărcături plantate, câte una pe rând: fiecare trebuie prinsă (politica dă cel puțin o Problema), cu
 *     codul așteptat. Acoperă: scripturile executabile (inline, `src`, `module`, cu tip), atributele de eveniment (oriunde,
 *     inclusiv în SVG), schemele de script ascunse cu entități, tab-uri și majuscule, în `href`, `style`, `url()` și în
 *     JSON-ul `data-*`; documentele purtate de adrese `data:` (greșite ca imagine: tip, mărime, octeți, base64),
 *     `object`/`embed`/`applet`/`portal`, `<base>`, `meta refresh`, iframe-urile străine, formularele spre alt server,
 *     construcțiile pe care le prinde tokenizerul (frameset, template, srcdoc, input în select, BOM UTF-16), JSON cu
 *     poluarea prototipului, un `speculationrules` spre alt server, un `ld+json` stricat, un tag și un atribut necunoscut,
 *     un `<link rel>` din afara listei, un `<noscript>` cu conținut viu; blocul `ax-date` (dublat, listă, adrese
 *     `javascript:`/`data:`/`http:`/`//gazdă`, poluarea prototipului, foi „late” care nu sunt căi `.css` ale site-ului) și
 *     id-urile/numele „ax-…” care l-ar umbri (`<style id="ax-date">`, `id="ax&#45;date"`); cheile de sus ale lui `ax-date` (doar
 *     ce pune importul) și `ax_structura` (clasele din lista închisă, selectorii din gramatica închisă: fără pseudo-clase,
 *     atribute, virgule, `*`, `+`, `~`, escapări);
 *  3. ce PERMITE politica: datele inerte (`ld+json`, `speculationrules` relativ, blocul `ax-date`), harta Google Maps din
 *     lista albă (și forma `data-src-cmplz`), imaginea `data:` rastru mică, `data-props`/`data-settings`/`data-et-multi-view`
 *     valide, un `<noscript>` cu text inert;
 *  4. transformarea deterministă a handlerelor din lista închisă (SchemaDivi4::handlerRecunoscut, strictă: doar formele
 *     exacte, pe tagul lor, orice abatere dă null), citirea strictă a JSON-ului configurațiilor (SchemaDivi4::jsonStrict:
 *     chei dublate sau goale, întregi în afara ±2^53, numere infinite = NU) și clasificarea scripturilor
 *     (SchemaDivi4::scriptInert/scriptConfig/scriptAruncat);
 *  5. ContextCapturat: lista albă a iframe-urilor și manifestul foilor de stil pentru `<noscript>`;
 *  6. plasa de verificare N1 (după îmbinare): fiecare încărcătură pe care CodActiv::parti() o prinde o prinde și
 *     HtmlCapturat (sărită cu o notă cât N1 nu e în nucleu).
 *
 *   tools/dev/php.sh tools/dev/test-html-capturat.php
 *
 * Fără bază și fără server. Raport: $CMS_DEV_DIR/test-html-capturat-raport.txt; cod 0 doar dacă totul a trecut.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';

use App\Support\Html5\Editare;
use App\Support\HtmlCapturat;
use App\Support\HtmlCapturat\ContextCapturat;
use App\Support\HtmlCapturat\SchemaDivi4;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
@mkdir($dosarDev, 0777, true);
$fisierRaport = $dosarDev . '/test-html-capturat-raport.txt';
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
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . $detalii : ''));
    }
};
/** @return list<string> codurile problemelor */
$coduri = static fn (array $probleme): array => array_map(static fn ($p): string => $p->cod, $probleme);
$scrie('Test politica HTML-ului capturat — ' . date('Y-m-d H:i:s'));

$ctx = new ContextCapturat();

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 1. Un fixture curat, „ca Divi”, inventat: acceptat, neschimbat octet cu octet');
// Doar structură și date inventate, scrise de noi (nimic de la un furnizor): secțiuni/rânduri Divi, poze cu srcset, o
// imagine data: rastru mică (un GIF 1×1 transparent inventat), harta din lista albă prin data-src-cmplz, un link de preload
// deja transformat (data-ax-tarziu), ld+json, speculationrules relativ, blocul ax-date, data-props, multi-view, un stil cu
// url() relativ, un noscript cu text inert și o iconiță SVG.
$gif = 'R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==';
$curat = "<!DOCTYPE html>\n<html lang=ro><head>\n"
    . "<meta charset=\"utf-8\"><title>Pensiunea Probă</title>\n"
    . "<link rel=\"canonical\" href=\"/\"><link rel=\"stylesheet\" id=\"stil\" href=\"/capturat/stil.css\" media=\"all\">\n"
    . "<link rel=\"preload\" as=\"style\" id=\"tarziu\" href=\"/capturat/tarziu.css\" data-ax-tarziu>\n"
    . "<link rel=\"apple-touch-icon\" href=\"/capturat/icon.png\"><link rel=\"prefetch\" href=\"/camere/\">\n"
    . "<style id=\"inline\">.et_pb_section{background:url(/capturat/fundal.jpg)}\n@media (max-width:980px){.et_pb_row{display:block}}</style>\n"
    . "<script type=\"application/ld+json\">{\"@context\":\"https://schema.org\",\"@type\":\"LodgingBusiness\",\"name\":\"Pensiunea Probă\"}</script>\n"
    . "<script type=\"speculationrules\">{\"prefetch\":[{\"source\":\"document\",\"where\":{\"href_matches\":\"/*\"},\"eagerness\":\"conservative\"}]}</script>\n"
    . "</head>\n<body class=\"pagina acasa\" data-cmplz=\"1\">\n"
    . "<header class=\"et-l--header\"><nav class=\"et_pb_menu\"><a href=\"/camere/\">Camere</a></nav></header>\n"
    . "<div class=\"et_pb_section sectiune-1\" data-ax-s=\"1\"><div class=\"et_pb_row\">\n"
    . "<div class=\"et_pb_module dica_divi_carousel\" data-props='{\"autoplay\":\"on\",\"speed\":\"300\",\"loop\":\"off\"}'>\n"
    . "<img src=\"/capturat/foto.jpg\" srcset=\"/capturat/foto-480.jpg 480w, /capturat/foto-980.jpg 980w\" sizes=\"100vw\" alt=\"curte\" width=\"980\" height=\"600\" loading=\"lazy\">\n"
    . "<img src=\"data:image/gif;base64,{$gif}\" alt=\"\" width=\"1\" height=\"1\">\n"
    . "<p data-ax-slot=\"text\">Camere ș prețuri bune.</p>\n"
    . "<noscript class=\"content_storage\"><p>Varianta fără JavaScript.</p></noscript>\n"
    . "</div></div></div>\n"
    . "<div class=\"et_pb_image\" data-et-multi-view='{\"schema\":{\"attrs\":{\"desktop\":{\"src\":\"/capturat/d.jpg\"},\"tablet\":{\"src\":\"/capturat/t.jpg\"}}},\"slug\":\"img\"}'></div>\n"
    . "<iframe src=\"about:blank\" data-src-cmplz=\"https://www.google.com/maps/embed?pb=1\" data-category=\"marketing\" class=\"cmplz-iframe\" width=\"600\" height=\"400\"></iframe>\n"
    . "<svg aria-hidden=\"true\" class=\"pin\" viewBox=\"0 0 24 24\" width=\"24\" height=\"24\" role=\"img\"><path d=\"M12 2l3 7h7\" fill=\"#123\"></path></svg>\n"
    . "<form class=\"et_pb_contact_form\" method=\"post\" action=\"/contact/\"><input type=\"text\" name=\"nume\" placeholder=\"Nume\" required><button type=\"submit\">Trimite</button></form>\n"
    . "<script type=\"application/json\" id=\"ax-date\">{\"et_pb_custom\":{\"page_id\":\"1\"},\"ax_structura\":{\"et-last-child\":[\".et_pb_row > .et_pb_column\"]}}</script>\n"
    . "</body></html>\n";
$pb = HtmlCapturat::verifica($curat, $ctx, 'import');
$verifica('fixture-ul curat „ca Divi” e acceptat (0 probleme)', $pb === [], implode(' | ', array_map(static fn ($p) => $p->cod . ':' . $p->mesaj, $pb)));
$verifica('fixture-ul curat e acceptat și la salvare', HtmlCapturat::verifica($curat, $ctx, 'salvare') === []);
$verifica('fixture-ul curat, prin Editare fără nicio editare, iese octet cu octet', (new Editare($curat))->aplica() === $curat);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 2. Încărcături plantate, câte una pe rând: fiecare trebuie prinsă');
// Fiecare caz: [HTML, codul așteptat (sau '' = orice cod)]. Toate sunt inventate.
$plantate = [
    // scripturi executabile
    'script inline' => ['<p>a</p><script>fetch("/fura")</script>', 'script'],
    'script src' => ['<script src="/x.js"></script>', 'script'],
    'script module' => ['<script type="module">import("x")</script>', 'script'],
    'script text/javascript' => ['<script type="text/javascript">x()</script>', 'script'],
    'script application/json fără ax-date' => ['<script type="application/json" id="alt">{"a":1}</script>', 'script'],
    'script ld+json cu src' => ['<script type="application/ld+json" src="/x.json"></script>', 'script'],
    // atribute de eveniment
    'onerror pe img' => ['<img src=x onerror="h()">', 'handler'],
    'onclick pe a' => ['<a href="/" onclick="h()">x</a>', 'handler'],
    'onload necunoscut' => ['<body onload="h()">x</body>', 'handler'],
    'onload pe link, dar altă valoare' => ['<link rel=preload as=style href="/a.css" onload="evil()">', 'handler'],
    'onpointerenter' => ['<div onpointerenter="h()">x</div>', 'handler'],
    'handler în SVG' => ['<svg><path d="M0 0" onload="h()"></path></svg>', 'handler'],
    'handler în SVG pe rădăcină' => ['<svg onload="h()"><path d="M0"></path></svg>', 'handler'],
    // scheme de script ascunse
    'javascript: în href' => ['<a href="javascript:alert(1)">x</a>', 'schema-script'],
    'javascript: cu entități' => ['<a href="javas&#99;ript:alert(1)">x</a>', 'schema-script'],
    'javascript: cu tab' => ["<a href=\"java\tscript:alert(1)\">x</a>", 'schema-script'],
    'javascript: cu spații la început' => ['<a href="   javascript:alert(1)">x</a>', 'schema-script'],
    'JAVASCRIPT majuscule' => ['<a href="JAVASCRIPT:alert(1)">x</a>', 'schema-script'],
    'vbscript: în href' => ['<a href="vbscript:msgbox(1)">x</a>', 'schema-script'],
    'javascript: în action' => ['<form action="javascript:x()"></form>', 'schema-script'],
    'javascript: în poster' => ['<video poster="javascript:x()"></video>', 'schema-script'],
    'javascript: în url() din style' => ['<div style="background:url(javascript:alert(1))">x</div>', 'style-url'],
    'javascript: în url() dintr-un element style' => ['<style>.a{background:url(\'javascript:alert(1)\')}</style>', 'style-url'],
    'expression() în style' => ['<div style="width:expression(alert(1))">x</div>', 'style'],
    'behavior: în style' => ['<div style="behavior:url(#x)">x</div>', 'style'],
    'javascript: în JSON multi-view' => ['<div data-et-multi-view=\'{"schema":{"content":{"desktop":"<a href=javascript:x()>link</a>"}}}\'></div>', 'multi-view'],
    'javascript: ca valoare în data-props' => ['<div data-props=\'{"order":"javascript:x()"}\'></div>', 'data-script'],
    // documente purtate de adrese data:
    'data:text/html în img' => ['<img src="data:text/html,<script>1</script>">', 'data-uri'],
    'data:image/svg+xml în img' => ['<img src="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=">', 'data-uri'],
    'data: în href' => ['<a href="data:image/png;base64,iVBORw0KAAAA">x</a>', 'data-uri'],
    'data:image prea mare' => ['<img src="data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('A', 3000)) . '">', 'data-uri'],
    'data:image octeți greșiți' => ['<img src="data:image/png;base64,' . base64_encode('nuepng') . '">', 'data-uri'],
    'data:image base64 stricat' => ['<img src="data:image/png;base64,@@@@nu-e-base64">', 'data-uri'],
    // elemente/atribute interzise
    'object' => ['<object data="x.swf"></object>', 'tag-necunoscut'],
    'embed' => ['<embed src="x.swf">', 'tag-necunoscut'],
    'applet' => ['<applet code="x.class"></applet>', 'tag-necunoscut'],
    'portal' => ['<portal src="https://x.test/"></portal>', 'tag-necunoscut'],
    'base' => ['<base href="https://alt.test/">', 'tag-necunoscut'],
    'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://alt.test/">', 'meta-refresh'],
    'tag necunoscut' => ['<blink>x</blink>', 'tag-necunoscut'],
    'atribut necunoscut' => ['<div ping-pong="1">x</div>', 'atribut-necunoscut'],
    'atribut necunoscut cu valoare, nume cu literă' => ['<div xfoo="bar">x</div>', 'atribut-necunoscut'],
    'element SVG nepermis (foreignObject)' => ['<svg><foreignObject><div>x</div></foreignObject></svg>', ''],
    'element MathML' => ['<p><math><mi>x</mi></math></p>', 'tag-necunoscut'],
    // iframe / form
    'iframe străin' => ['<iframe src="https://alt.test/x"></iframe>', 'iframe'],
    'iframe data-src-cmplz străin' => ['<iframe src="about:blank" data-src-cmplz="https://alt.test/x"></iframe>', 'data-uri'],
    'form spre alt server (protocol-relativ)' => ['<form action="//alt.test/x" method="post"></form>', 'form-action'],
    // link rel
    'link rel necunoscut' => ['<link rel="import" href="/x.html">', 'link-rel'],
    'link fără rel' => ['<link href="/x.css">', 'link-rel'],
    // indiciile de resurse spre alte gazde (importul le scoate; unul rămas e NU)
    'dns-prefetch spre altă gazdă' => ['<link rel="dns-prefetch" href="//alt.test">', 'link-tert'],
    'preconnect spre altă gazdă' => ['<link rel="preconnect" href="https://alt.test" crossorigin>', 'link-tert'],
    'preload de font spre altă gazdă' => ['<link rel="preload" as="font" href="https://alt.test/f.woff2" crossorigin>', 'link-tert'],
    'prefetch spre altă gazdă' => ['<link rel="prefetch" href="https://alt.test/x">', 'link-tert'],
    'modulepreload spre altă gazdă' => ['<link rel="modulepreload" href="https://alt.test/m.js">', 'link-tert'],
    'preload spre altă gazdă cu entități' => ['<link rel="preload" as="style" href="&#47;&#47;alt.test/x.css">', 'link-tert'],
    'preload spre altă gazdă cu \\\\' => ['<link rel="preload" as="style" href="\\\\alt.test/x.css">', 'link-tert'],
    'preload stylesheet spre altă gazdă' => ['<link rel="preload stylesheet" as="style" href="https://alt.test/x.css">', 'link-tert'],
    // JSON / schemă închisă
    '__proto__ în data-props' => ['<div data-props=\'{"__proto__":{"x":1}}\'></div>', 'data-proto'],
    'constructor în data-settings' => ['<div data-settings=\'{"constructor":{"x":1}}\'></div>', 'data-proto'],
    'cheie necunoscută în data-props' => ['<div data-props=\'{"evil_key":"1"}\'></div>', 'data-cheie'],
    'data-* necunoscut' => ['<div data-evil="1">x</div>', 'data-necunoscut'],
    'data-* JSON stricat' => ['<div data-props=\'{nu e json\'></div>', 'data-json'],
    // speculationrules / ld+json
    'speculationrules spre alt server' => ['<script type="speculationrules">{"prefetch":[{"urls":["https://alt.test/x"]}]}</script>', 'spec'],
    'speculationrules cheie nepermisă' => ['<script type="speculationrules">{"evil":[]}</script>', 'spec'],
    'speculationrules nu e JSON' => ['<script type="speculationrules">nu</script>', 'spec'],
    'ld+json stricat' => ['<script type="application/ld+json">{stricat</script>', 'script-json'],
    'ax-date stricat' => ['<script type="application/json" id="ax-date">{stricat</script>', 'script-json'],
    // blocul ax-date (recenzia M2): unul singur, un obiect, adrese doar https sau relative, foile „late” doar .css ale site-ului
    'două blocuri ax-date' => ['<script type="application/json" id="ax-date">{}</script><script type="application/json" id="ax-date">{}</script>', 'ax-date'],
    'ax-date listă' => ['<script type="application/json" id="ax-date">[1,2]</script>', 'ax-date'],
    'ax-date cu javascript:' => ['<script type="application/json" id="ax-date">{"u":"javascript:alert(1)"}</script>', 'ax-adresa'],
    'ax-date cu JavaScript: și tab' => ['<script type="application/json" id="ax-date">{"u":"Java\\tScript:alert(1)"}</script>', 'ax-adresa'],
    'ax-date cu data:' => ['<script type="application/json" id="ax-date">{"a":{"u":"data:text/html,x"}}</script>', 'ax-adresa'],
    'ax-date cu http:' => ['<script type="application/json" id="ax-date">{"u":"http://alt.test/x"}</script>', 'ax-adresa'],
    'ax-date protocol-relativ' => ['<script type="application/json" id="ax-date">{"u":["//alt.test/x"]}</script>', 'ax-adresa'],
    'ax-date cu „/\\”' => ['<script type="application/json" id="ax-date">{"u":"/\\\\alt.test/x"}</script>', 'ax-adresa'],
    'ax-date __proto__' => ['<script type="application/json" id="ax-date">{"__proto__":{"x":1}}</script>', 'data-proto'],
    'ax-date constructor adânc' => ['<script type="application/json" id="ax-date">{"a":[{"constructor":{"prototype":1}}]}</script>', 'data-proto'],
    'ax-date file străin' => ['<script type="application/json" id="ax-date">{"file":["https://alt.test/a.css"]}</script>', 'ax-file'],
    'ax-date file .js' => ['<script type="application/json" id="ax-date">{"file":["/capturat/a.js"]}</script>', 'ax-file'],
    'ax-date file cu ../' => ['<script type="application/json" id="ax-date">{"file":["/capturat/../a.css"]}</script>', 'ax-file'],
    'ax-date file protocol-relativ' => ['<script type="application/json" id="ax-date">{"file":["//alt.test/a.css"]}</script>', 'ax-file'],
    'ax-date file număr' => ['<script type="application/json" id="ax-date">{"file":[1]}</script>', 'ax-file'],
    // ax_structura (pista R, structura.js): clasele de structură pe pagină, din pachet; cheile și selectorii închiși
    'ax-date cu o cheie necunoscută' => ['<script type="application/json" id="ax-date">{"necunoscuta":1}</script>', 'ax-cheie'],
    'ax_structura listă' => ['<script type="application/json" id="ax-date">{"ax_structura":[".a"]}</script>', 'ax-structura'],
    'ax_structura obiect gol' => ['<script type="application/json" id="ax-date">{"ax_structura":{}}</script>', 'ax-structura'],
    'ax_structura clasă din afara listei' => ['<script type="application/json" id="ax-date">{"ax_structura":{"et_pb_column_single":[".a"]}}</script>', 'ax-structura'],
    'ax_structura selectori nu e listă' => ['<script type="application/json" id="ax-date">{"ax_structura":{"et-last-child":".a"}}</script>', 'ax-structura'],
    'ax_structura listă goală' => ['<script type="application/json" id="ax-date">{"ax_structura":{"et-last-child":[]}}</script>', 'ax-structura'],
    'ax_structura selector cu pseudo-clasă' => ['<script type="application/json" id="ax-date">{"ax_structura":{"et-last-child":[".a:last-child"]}}</script>', 'ax-structura'],
    'ax_structura selector cu atribut' => ['<script type="application/json" id="ax-date">{"ax_structura":{"et-last-child":["div[id=x]"]}}</script>', 'ax-structura'],
    'ax_structura selector cu virgulă' => ['<script type="application/json" id="ax-date">{"ax_structura":{"et-last-child":[".a, .b"]}}</script>', 'ax-structura'],
    'ax_structura selector cu *' => ['<script type="application/json" id="ax-date">{"ax_structura":{"et-last-child":["div > *"]}}</script>', 'ax-structura'],
    'ax_structura selector cu + și ~' => ['<script type="application/json" id="ax-date">{"ax_structura":{"et-last-child":[".a + .b", ".a ~ .b"]}}</script>', 'ax-structura'],
    'ax_structura selector cu „>” la capăt' => ['<script type="application/json" id="ax-date">{"ax_structura":{"et-last-child":[".a >"]}}</script>', 'ax-structura'],
    'ax_structura selector cu „> >”' => ['<script type="application/json" id="ax-date">{"ax_structura":{"et-last-child":[".a > > .b"]}}</script>', 'ax-structura'],
    'ax_structura selector cu spațiu la început' => ['<script type="application/json" id="ax-date">{"ax_structura":{"et-last-child":[" .a"]}}</script>', 'ax-structura'],
    'ax_structura selector cu linie nouă' => ['<script type="application/json" id="ax-date">{"ax_structura":{"et-last-child":[".a\\n.b"]}}</script>', 'ax-structura'],
    'ax_structura selector cu escapare' => ['<script type="application/json" id="ax-date">{"ax_structura":{"et-last-child":[".a\\\\:b"]}}</script>', 'ax-structura'],
    'ax_structura selector cu clasă de o cifră' => ['<script type="application/json" id="ax-date">{"ax_structura":{"et-last-child":[".1a"]}}</script>', 'ax-structura'],
    'ax_structura selector cu tag cu majuscule' => ['<script type="application/json" id="ax-date">{"ax_structura":{"et-last-child":["DIV.a"]}}</script>', 'ax-structura'],
    'ax_structura selector gol' => ['<script type="application/json" id="ax-date">{"ax_structura":{"et-last-child":[""]}}</script>', 'ax-structura'],
    'ax_structura selector număr' => ['<script type="application/json" id="ax-date">{"ax_structura":{"et-last-child":[1]}}</script>', 'ax-structura'],
    'ax_structura selector prea lung' => ['<script type="application/json" id="ax-date">{"ax_structura":{"et-last-child":["' . str_repeat('.a', 101) . '"]}}</script>', 'ax-structura'],
    'ax_structura __proto__' => ['<script type="application/json" id="ax-date">{"ax_structura":{"__proto__":[".a"]}}</script>', 'data-proto'],
    // id-urile și numele „ax-…” sunt ale importului (recenzia M2: umbrirea lui ax-date în DOM)
    '<style id=ax-date>' => ['<style id="ax-date">.a{}</style>', 'ax-id'],
    '<meta id=ax-date>' => ['<meta id="ax-date" content="x">', 'ax-id'],
    'id ax-date cu entitate' => ['<div id="ax&#45;date">x</div>', 'ax-id'],
    'id AX- cu spațiu' => ['<p id=" AX-date">x</p>', 'ax-id'],
    'name ax-' => ['<a name="ax-date">x</a>', 'ax-id'],
    'script json cu id AX-DATE' => ['<script type="application/json" id="AX-DATE">{}</script>', ''],
    // title cu „<” (citit de lightbox)
    'title cu <' => ['<a href="/" title="a <b> c">x</a>', 'title'],
    // noscript cu conținut viu
    'noscript cu script' => ['<noscript><script>evil()</script></noscript>', 'noscript'],
    'noscript cu iframe străin' => ['<noscript><iframe src="https://alt.test/"></iframe></noscript>', 'noscript'],
    'noscript cu onerror' => ['<noscript><img src=x onerror=1></noscript>', 'noscript'],
    'noscript cu o foaie, fără manifest' => ['<noscript><link rel="stylesheet" href="/capturat/stil.css"></noscript>', 'noscript'],
    'noscript cu o foaie de pe altă gazdă' => ['<noscript><link rel="stylesheet" href="https://alt.test/x.css"></noscript>', 'noscript'],
    // construcții prinse de tokenizer (document nesigur)
    'frameset' => ['<frameset><frame src="/x" onload="h()"></frameset>', 'document-nesigur'],
    'frame singur' => ['<div><frame onload="h()"></div>', 'document-nesigur'],
    'template' => ['<template><img src=x onerror=1></template>', 'document-nesigur'],
    'template shadowrootmode' => ['<div><template shadowrootmode=open><img src=x onerror=1></template></div>', 'document-nesigur'],
    'srcdoc' => ['<iframe srcdoc="<img src=x onerror=1>"></iframe>', 'document-nesigur'],
    'input în select' => ['<select><input><option>a</option></select>', 'document-nesigur'],
    'BOM UTF-16' => ["\xFF\xFE<p>x</p>", 'document-nesigur'],
    'CDATA într-un punct de integrare' => ['<math><mi><![CDATA[x]]></mi></math>', 'document-nesigur'],
];
$neprinse = [];
$codGresit = [];
foreach ($plantate as $eticheta => [$html, $codAsteptat]) {
    $probleme = HtmlCapturat::verifica($html, $ctx, 'import');
    if ($probleme === []) {
        $neprinse[] = $eticheta;
        continue;
    }
    if ($codAsteptat !== '' && !in_array($codAsteptat, $coduri($probleme), true)) {
        $codGresit[] = $eticheta . ' (aștept „' . $codAsteptat . '”, am „' . implode(',', $coduri($probleme)) . '”)';
    }
}
$verifica('fiecare dintre cele ' . count($plantate) . ' de încărcături plantate e prinsă (politica dă cel puțin o Problema)',
    $neprinse === [], 'neprinse: ' . implode(', ', $neprinse));
$verifica('fiecare încărcătură prinsă are codul așteptat', $codGresit === [], implode('; ', $codGresit));
// Fiecare încărcătură plantată INTR-un fixture curat (o singură injecție pe rând) e tot prinsă.
$neprinseInjectie = [];
foreach ($plantate as $eticheta => [$html, $codAsteptat]) {
    if ($eticheta === 'BOM UTF-16') {
        continue; // un BOM contează doar la ÎNCEPUTUL documentului; injectat în corp nu mai e un BOM (acoperit standalone)
    }
    // injectăm payload-ul în corpul fixture-ului curat, între două secțiuni
    $injectat = str_replace('</body>', $html . '</body>', $curat);
    if (HtmlCapturat::verifica($injectat, $ctx, 'import') === []) {
        $neprinseInjectie[] = $eticheta;
    }
}
$verifica('fiecare încărcătură injectată într-un fixture curat (o injecție pe rând) e tot prinsă', $neprinseInjectie === [],
    'neprinse: ' . implode(', ', $neprinseInjectie));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 3. Ce permite politica (date inerte, harta, imaginea rastru mică, data-*)');
$permise = [
    'ld+json valid' => '<script type="application/ld+json">{"@type":"x"}</script>',
    'speculationrules relativ' => '<script type="speculationrules">{"prefetch":[{"urls":["/a","/b"]}]}</script>',
    'ax-date json' => '<script type="application/json" id="ax-date">{"et_pb_box_shadow_elements":[1,2,3]}</script>',
    'ax-date cu adrese https și relative, foi .css, text cu „:”' => '<script type="application/json" id="ax-date">{"et_pb_custom":{"u":"https://exemplu.test/x",'
        . '"r":"/camere/","t":"Notă: text cu două puncte","n":1},"file":["/capturat/a.css?ver=1","/capturat/b.CSS"]}</script>',
    'ax_structura validă' => '<script type="application/json" id="ax-date">{"et_pb_custom":{"page_id":"1"},"ax_structura":{"et-last-child":'
        . '[".et_pb_row_1 > .et_pb_column_1_3","#main-content .et_pb_section_0 > div.et_pb_row","header.et-l--header .et_pb_menu"]}}</script>',
    'id și nume care doar conțin „ax-”' => '<div id="relax-1" class="ax-capcana"><a name="max-2">x</a></div>',
    'harta maps prin data-src-cmplz' => '<iframe src="about:blank" data-src-cmplz="https://www.google.com/maps/embed?pb=1" width="600" height="400"></iframe>',
    'harta maps direct în src' => '<iframe src="https://www.google.com/maps/embed?pb=1" width="600" height="400"></iframe>',
    'imagine data: rastru mică' => '<img src="data:image/gif;base64,' . $gif . '" alt="">',
    'data-props valid' => '<div data-props=\'{"autoplay":"on","speed":"300"}\'></div>',
    'data-settings valid' => '<div data-settings=\'{"status":"false","responsive":"off"}\'></div>',
    'multi-view valid' => '<div data-et-multi-view=\'{"schema":{"attrs":{"desktop":{"src":"/a.jpg"}}},"slug":"x"}\'></div>',
    'multi-view content inert' => '<div data-et-multi-view=\'{"schema":{"content":{"desktop":"<p>text <strong>bold</strong></p>"}}}\'></div>',
    'noscript cu text și link local' => '<noscript><p>fără JS</p><img src="/a.png"></noscript>',
    'link preload transformat' => '<link rel="preload" as="style" href="/a.css" data-ax-tarziu>',
    'indicii spre site (relative)' => '<link rel="prefetch" href="/camere/"><link rel="preload" as="image" href="/capturat/a.jpg"><link rel="dns-prefetch" href="/">',
    'title fără <' => '<a href="/" title="Camere » Vila">x</a>',
    'style cu url relativ și site-absolut' => '<style>.a{background:url(/x.jpg)}.b{background:url(https://exemplu.test/y.jpg)}</style>',
];
$respinseGresit = [];
foreach ($permise as $eticheta => $html) {
    $pb2 = HtmlCapturat::verifica($html, $ctx, 'import');
    if ($pb2 !== []) {
        $respinseGresit[] = $eticheta . ': ' . implode(',', array_map(static fn ($p) => $p->cod, $pb2));
    }
}
$verifica('ce e permis trece (0 probleme)', $respinseGresit === [], implode('; ', $respinseGresit));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 4. SchemaDivi4: clasificarea scripturilor și transformarea handlerelor');
$verifica('scriptInert: ld+json, speculationrules, ax-date da; alt application/json nu',
    SchemaDivi4::scriptInert('application/ld+json', null) && SchemaDivi4::scriptInert('speculationrules', null)
    && SchemaDivi4::scriptInert('application/json', 'ax-date') && !SchemaDivi4::scriptInert('application/json', 'alt')
    && !SchemaDivi4::scriptInert('text/javascript', null) && !SchemaDivi4::scriptInert(null, null));
$verifica('scriptConfig recunoaște „var X = JSON; var Y = JSON;” (și refuză cod)',
    SchemaDivi4::scriptConfig('var et_pb_sticky_elements = {"a":1};') === ['et_pb_sticky_elements']
    && SchemaDivi4::scriptConfig(' var a = [1,2]; var b = {"x":true}; ') === ['a', 'b']
    && SchemaDivi4::scriptConfig('alert(1)') === []
    && SchemaDivi4::scriptConfig('var x = 1; evil();') === []);
$tarziu = ['rel' => 'preload', 'as' => 'style', 'href' => '/capturat/tarziu.css', 'hrefPropriu' => true];
$verifica('handlerRecunoscut: preload onload → data-ax-tarziu (cu și fără „;”), doar pe <link rel~=preload as=style href> al site-ului',
    SchemaDivi4::handlerRecunoscut('link', 'onload', "this.onload=null;this.rel='stylesheet'", $tarziu) === ['data-ax-tarziu', '']
    && SchemaDivi4::handlerRecunoscut('link', 'onload', "this.onload=null;this.rel='stylesheet';", $tarziu) === ['data-ax-tarziu', '']
    && SchemaDivi4::handlerRecunoscut('link', 'onload', "this.onload=null;this.rel='stylesheet'", ['rel' => "PRELOAD\tx", 'as' => 'Style'] + $tarziu) === ['data-ax-tarziu', '']);
$verifica('handlerRecunoscut: embed onload="media=\'all\'" → media="all", doar pe <link rel=stylesheet>',
    SchemaDivi4::handlerRecunoscut('link', 'onload', "media='all'", ['rel' => 'stylesheet', 'href' => '/x.css']) === ['media', 'all']);
// Lista închisă e strictă (recenzia M3): orice abatere de la forma exactă dă null, deci rămâne handler și politica dă NU.
$strict = [
    'fără context (rel/as/href necunoscute)' => ['link', 'onload', "this.onload=null;this.rel='stylesheet'", []],
    'as=script' => ['link', 'onload', "this.onload=null;this.rel='stylesheet'", ['as' => 'script'] + $tarziu],
    'as=font' => ['link', 'onload', "this.onload=null;this.rel='stylesheet'", ['as' => 'font'] + $tarziu],
    'fără as' => ['link', 'onload', "this.onload=null;this.rel='stylesheet'", ['as' => null] + $tarziu],
    'rel fără preload' => ['link', 'onload', "this.onload=null;this.rel='stylesheet'", ['rel' => 'stylesheet'] + $tarziu],
    'fără href' => ['link', 'onload', "this.onload=null;this.rel='stylesheet'", ['href' => null, 'hrefPropriu' => false] + $tarziu],
    'href gol' => ['link', 'onload', "this.onload=null;this.rel='stylesheet'", ['href' => ''] + $tarziu],
    'href pe altă gazdă' => ['link', 'onload', "this.onload=null;this.rel='stylesheet'", ['href' => 'https://alt.test/a.css', 'hrefPropriu' => false] + $tarziu],
    'mai multe on… pe tag' => ['link', 'onload', "this.onload=null;this.rel='stylesheet'", ['dubluOn' => true] + $tarziu],
    'pe <img>' => ['img', 'onload', "this.onload=null;this.rel='stylesheet'", $tarziu],
    'pe <body>' => ['body', 'onload', "this.onload=null;this.rel='stylesheet'", $tarziu],
    'onerror' => ['link', 'onerror', "this.onload=null;this.rel='stylesheet'", $tarziu],
    'ONLOAD (nume nenormalizat)' => ['link', 'ONLOAD', "this.onload=null;this.rel='stylesheet'", $tarziu],
    '„style sheet”' => ['link', 'onload', "this.onload=null;this.rel='style sheet'", $tarziu],
    '„thi s.onload”' => ['link', 'onload', "thi s.onload=null;this.rel='stylesheet'", $tarziu],
    'spații în cod' => ['link', 'onload', "this.onload = null; this.rel = 'stylesheet'", $tarziu],
    'spațiu la început' => ['link', 'onload', " this.onload=null;this.rel='stylesheet'", $tarziu],
    'ghilimele duble' => ['link', 'onload', 'this.onload=null;this.rel="stylesheet"', $tarziu],
    '„;;” la sfârșit' => ['link', 'onload', "this.onload=null;this.rel='stylesheet';;", $tarziu],
    'cu entități' => ['link', 'onload', 'this.onload=null;this.rel=&#39;stylesheet&#39;', $tarziu],
    'cod adăugat' => ['link', 'onload', "this.onload=null;this.rel='stylesheet';alert(1)", $tarziu],
    'media=all fără ghilimele' => ['link', 'onload', 'media=all', ['rel' => 'stylesheet']],
    'media="all"' => ['link', 'onload', 'media="all"', ['rel' => 'stylesheet']],
    'media cu „;”' => ['link', 'onload', "media='all';", ['rel' => 'stylesheet']],
    'media pe rel=preload' => ['link', 'onload', "media='all'", ['rel' => 'preload', 'as' => 'style']],
    'media pe rel="stylesheet alternate"' => ['link', 'onload', "media='all'", ['rel' => 'stylesheet alternate']],
    'media pe <a>' => ['a', 'onload', "media='all'", ['rel' => 'stylesheet']],
    'media pe <source>' => ['source', 'onload', "media='all'", ['rel' => 'stylesheet']],
    'media cu două on…' => ['link', 'onload', "media='all'", ['rel' => 'stylesheet', 'dubluOn' => true]],
];
$transformate = [];
foreach ($strict as $eticheta => [$el, $at, $val, $tag]) {
    if (SchemaDivi4::handlerRecunoscut($el, $at, $val, $tag) !== null) {
        $transformate[] = $eticheta;
    }
}
$verifica('handlerRecunoscut strict: cele ' . count($strict) . ' de abateri de la formele exacte dau null (rămân handler, NU)',
    $transformate === [], 'transformate: ' . implode(', ', $transformate));
$verifica('selectorSigur: taguri, #id, .clasă, spații și „>”; nimic altceva',
    SchemaDivi4::selectorSigur('.et_pb_row_1 > .et_pb_column_1_3') && SchemaDivi4::selectorSigur('div') && SchemaDivi4::selectorSigur('#a.b.c > p span')
    && SchemaDivi4::selectorSigur('.a>.b') && SchemaDivi4::selectorSigur('.et-l--header .et_pb_menu')
    && !SchemaDivi4::selectorSigur('.a:first-child') && !SchemaDivi4::selectorSigur('.a .b, .c') && !SchemaDivi4::selectorSigur('.a\t.b')
    && !SchemaDivi4::selectorSigur('#1x') && !SchemaDivi4::selectorSigur('.a >') && !SchemaDivi4::selectorSigur('> .a') && !SchemaDivi4::selectorSigur('.a > > .b')
    && !SchemaDivi4::selectorSigur('.ă') && !SchemaDivi4::selectorSigur(implode(' ', array_fill(0, 17, '.a'))) && SchemaDivi4::selectorSigur(implode(' ', array_fill(0, 16, '.a'))));
$verifica('handlerRecunoscut: un onload necunoscut și un onclick dau null',
    SchemaDivi4::handlerRecunoscut('body', 'onload', 'init()') === null
    && SchemaDivi4::handlerRecunoscut('a', 'onclick', "media='all'") === null);
$verifica('jsonStrict: ca json_decode pe JSON curat (obiectele ies stdClass, întregii mari până la 2^53 rămân întregi)',
    SchemaDivi4::jsonStrict('{"a":[1,2.5,"x",null,true],"b":{"c":9007199254740992}}') == json_decode('{"a":[1,2.5,"x",null,true],"b":{"c":9007199254740992}}')
    && SchemaDivi4::jsonStrict(' [] ') === [] && SchemaDivi4::jsonStrict('"\\u0219"') === 'ș');
$respinseJson = [];
foreach ([
    'cheie dublată' => '{"a":1,"a":2}',
    'cheie dublată prin escapare' => '{"a":1,"\\u0061":2}',
    'cheie dublată adânc' => '{"x":[{"k":1,"k":1}]}',
    'cheie goală' => '{"":1}',
    'întreg peste 2^53' => '{"a":9007199254740993}',
    'întreg sub -2^53' => '[-9007199254740993]',
    'întreg pe 64 de biți' => '[18446744073709551616]',
    '1e400' => '{"x":1e400}',
    '-1e400' => '[-1e400]',
    'NaN' => '[NaN]',
    'virgulă la sfârșit' => '[1,]',
    'comentariu' => '{"a":1/*x*/}',
    'text după literal' => '{"a":1} x',
    'două literale' => '{} {}',
    'ghilimele simple' => "{'a':1}",
    'adâncime 300' => str_repeat('[', 300) . str_repeat(']', 300),
    'BOM' => "\xEF\xBB\xBF{}",
    'gol' => '',
] as $eticheta => $json) {
    try {
        SchemaDivi4::jsonStrict($json);
        $respinseJson[] = $eticheta;
    } catch (\InvalidArgumentException) {
    }
}
$verifica('jsonStrict: cheile dublate sau goale, întregii în afara ±2^53, numerele infinite, textul în plus și adâncimea dau NU',
    $respinseJson === [], 'acceptate: ' . implode(', ', $respinseJson));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 5. ContextCapturat: lista albă a iframe-urilor și manifestul pentru <noscript>');
$ctxAlt = new ContextCapturat(['https://player.exemplu.test/embed'], ['/capturat/stil.css']);
$verifica('o sursă nouă de iframe în lista albă e permisă, harta Maps implicită nu mai e',
    HtmlCapturat::verifica('<iframe src="https://player.exemplu.test/embed/1"></iframe>', $ctxAlt) === []
    && HtmlCapturat::verifica('<iframe src="https://www.google.com/maps/embed?pb=1"></iframe>', $ctxAlt) !== []);
$verifica('cu manifest, un <link> din <noscript> spre o foaie din manifest trece, altfel NU',
    HtmlCapturat::verifica('<noscript><link rel="stylesheet" href="/capturat/stil.css"></noscript>', $ctxAlt) === []
    && array_filter(HtmlCapturat::verifica('<noscript><link rel="stylesheet" href="/capturat/alta.css"></noscript>', $ctxAlt),
        static fn ($p) => $p->cod === 'noscript') !== []);
$noscriptNu = static fn (string $html, ContextCapturat $c): bool => array_filter(HtmlCapturat::verifica($html, $c), static fn ($p) => $p->cod === 'noscript') !== [];
$verifica('în <noscript>, un <link> e doar rel=stylesheet relativ spre o foaie din manifest (recenzia m7)',
    $noscriptNu('<noscript><link rel="preload" as="style" href="/capturat/stil.css"></noscript>', $ctxAlt)
    && $noscriptNu('<noscript><link rel="stylesheet" href="//alt.test/capturat/stil.css"></noscript>', $ctxAlt)
    && $noscriptNu('<noscript><link rel="stylesheet" href="https://alt.test/capturat/stil.css"></noscript>', $ctxAlt)
    && $noscriptNu('<noscript><link rel="stylesheet"></noscript>', $ctxAlt)
    && !$noscriptNu('<noscript><link rel="stylesheet" href="/capturat/stil.css?ver=2" media="all"></noscript>', $ctxAlt));
$fisierManifest = $dosarDev . '/test-html-capturat-manifest.json';
file_put_contents($fisierManifest, json_encode(['format' => 1, 'fisiere' => [['cale' => '/wp-content/proba/a.css', 'fel' => 'foaie'],
    ['cale' => '/wp-content/proba/b.CSS', 'fel' => 'alt'], ['cale' => '/wp-content/proba/c.png', 'fel' => 'imagine'], ['cale' => '//alt.test/d.css', 'fel' => 'foaie'],
    ['cale' => '/wp-content/../e.css', 'fel' => 'foaie'], ['fel' => 'foaie']]]));
$dinManifest = ContextCapturat::dinManifest($fisierManifest);
$verifica('ContextCapturat::dinManifest: foile proprii din manifest (fel „foaie” ori .css), nu imaginile, nu //gazdă, nu „..”; fără fișier, nicio foaie',
    $dinManifest->foaieInManifest('/wp-content/proba/a.css') && $dinManifest->foaieInManifest('/wp-content/proba/b.CSS')
    && !$dinManifest->foaieInManifest('/wp-content/proba/c.png') && !$dinManifest->foaieInManifest('//alt.test/d.css') && !$dinManifest->foaieInManifest('/wp-content/../e.css')
    && $dinManifest->iframePermis(ContextCapturat::MAPS . '?pb=1') && !ContextCapturat::dinManifest($dosarDev . '/nu-exista.json')->areManifest()
    && !ContextCapturat::dinManifest(null)->areManifest());
@unlink($fisierManifest);
$verifica('mod necunoscut aruncă', (static function () use ($ctx): bool {
    try {
        HtmlCapturat::verifica('<p>x</p>', $ctx, 'altceva');
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
})());

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 6. Plasa de verificare N1 (CodActiv): după îmbinare');
if (class_exists(\App\Support\CodActiv::class)) {
    // Fiecare încărcătură pe care CodActiv::parti() o prinde trebuie prinsă și de HtmlCapturat (cel puțin la fel de strictă).
    $slabe = [];
    foreach ($plantate as $eticheta => [$html]) {
        $codActivPrinde = \App\Support\CodActiv::parti($html) !== [];
        if ($codActivPrinde && HtmlCapturat::verifica($html, $ctx, 'salvare') === []) {
            $slabe[] = $eticheta;
        }
    }
    $verifica('HtmlCapturat prinde tot ce prinde CodActiv::parti()', $slabe === [], implode(', ', $slabe));
} else {
    $scrie('  (sărit) N1 (App\\Support\\CodActiv) nu e încă în nucleu; plasa se verifică după îmbinarea securității.');
}

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('Rezumat: ' . $trecute . ' trecute, ' . $esuate . ' eșuate.');
exit($esuate === 0 ? 0 : 1);
