<?php

declare(strict_types=1);

/**
 * Testele tokenizerului HTML5 (App\Support\Html5\Tokenizer, Token, Atribut, Editare), poarta G-html5, pe un corpus
 * INVENTAT de HTML5 murdar („Pensiunea Probă”; nicio pagină, niciun DOM și niciun CSS al unui client):
 *
 *  1. tokenizarea + recompunerea = octeții de intrare, pe fiecare fragment (ghilimele lipsă, simple, duble, atribute fără
 *     valoare și duplicate, entități, NBSP, ş/ș, U+2026, comentarii condiționale, `</p>` implicit, majuscule, `/>`,
 *     script cu `<!--` și `<script>` înăuntru, style, textarea, title, iframe, noscript, SVG cu style și CDATA, doctype,
 *     comentarii false, `</>`, „<” rătăciți, CRLF, BOM, octeți care nu sunt UTF-8, un tag și un comentariu neterminate,
 *     ieșirile din SVG și MathML, punctele de integrare), plus 3000 de texte făcute la întâmplare din bucăți de HTML;
 *     tokenii se ating cap la cap și acoperă tot textul;
 *  2. tokenii înșiși: numele, atributele (pozițiile numelui și ale valorii, ghilimelele, duplicatele), textul brut și
 *     RCDATA până la tagul lor de închidere, comentariile, conținutul străin (CDATA, ieșirile din el, punctele de
 *     integrare), perechile elementelor, garda de structură (blocurile);
 *  3. fiecare Editare (valoarea unui atribut cu și fără ghilimele, un atribut fără valoare, interiorul unui element,
 *     ștergerea unui nod și a unui atribut, inserarea unui atribut, mai multe deodată) schimbă doar intervalul ei: octeții
 *     din afară rămân exact cei de la început; două înlocuiri care se suprapun sunt refuzate;
 *  4. constructorul arborelui (Constructie, fără arbore), pe cazuri verificate față de constructorul WHATWG: o închidere
 *     fără pereche ignorată în SVG și MathML, `<mglyph>`/`<malignmark>` în `<mi>`, `</svg>` ignorat într-un
 *     `foreignObject` cu un element HTML deschis, formatarea reconstruită și `</td>` care scot parserul din SVG, un atribut
 *     care ascunde „</style>”, `<noframes>` în corp, modul quirks, adopția, tabelele, `<form>`, `<frameset>`; spațiul de nume
 *     al fiecărui element, tagurile ignorate, perechile (și `<div/>`, `<iframe/>`: HTML ignoră „/>”), garda de structură,
 *     documentele nesigure (`<select>`, `<search>`, CDATA într-un punct de integrare, o stivă prea adâncă), ștergerea unui nod
 *     cu text brut, ștergerea unui atribut fără `/>` nou, timpul liniar al scriptului „escaped”.
 *
 *   tools/dev/php.sh tools/dev/test-html5.php
 *
 * Fără bază și fără server. Raport: $CMS_DEV_DIR/test-html5-raport.txt; cod 0 doar dacă totul a trecut.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';

use App\Support\Html5\Editare;
use App\Support\Html5\Token;
use App\Support\Html5\Tokenizer;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-html5-raport.txt';
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
$scrie('Test tokenizerul HTML5 — ' . date('Y-m-d H:i:s'));

// ---------------------------------------------------------------------------------------------------------------------
// Corpusul inventat
// ---------------------------------------------------------------------------------------------------------------------
$nbsp = "\u{A0}";
$corpus = [
    'pagina' => "<!DOCTYPE html>\n<html lang=ro><head><meta charset=utf-8><title>Pensiunea Probă &amp; Grădina</title>\n"
        . "<link rel=stylesheet href='/tema/stil.css?v=3'><style>.antet>a{color:#123}</style></head>\n"
        . "<body class=\"pagina acasa\"><div class=\"et_pb_section sectiune-1\"><div class='et_pb_row'><p>Camere ș{$nbsp}prețuri…"
        . "<p>Al doilea paragraf, fără închidere<br/><img src=/wp-content/uploads/2026/01/camera.jpg alt= width=640 height=480>"
        . "</div></div></body></html>\n",
    'ghilimele' => '<a href=/camere/ title="Camere" data-x=\'{"a":1}\' data-gol="" data-fara-valoare target=_blank>Vezi</a>'
        . '<a href="x"title="lipit">lipit</a><a =x href=y>egal</a><a href="a" href="b" HREF=c>dublu</a>',
    'entitati' => '<p>&amp; &nbsp; &#x219; &#537; &copy fără punct și virgulă &notanentity; ş vechi și ș nou … ”ghilimele”</p>',
    'conditionale' => '<!--[if lt IE 9]><script src="/vechi.js"></script><![endif]--><!--[if !IE]><!--><p>modern</p><!--<![endif]-->',
    'comentarii' => '<!----><!--><!---><!-- a -- b --><!-- x --!><!-- y --->dupa',
    'script' => "<script>var s = '</scr' + 'ipt>'; if (a < b && c > d) {}</script><script type=\"application/ld+json\">{\"@context\":\"x\"}</script>"
        . "<script><!-- document.write('<script>x()</script>'); --></script><p>după scripturi</p>",
    'brut' => "<style>p::before{content:'</p>'}</style ><textarea name=mesaj><b>nu e tag</b> &amp; </textarea><title>A </b> B</title>"
        . "<iframe src=\"https://www.example.test/harta\"><p>în iframe</p></iframe><noscript><img src=x></noscript><xmp><a></xmp>",
    'svg' => '<svg viewBox="0 0 10 10"><style>.c{fill:red}</style><title>ic</title><![CDATA[ a < b ]]><path d="M0 0h10"/></svg>'
        . '<p><![CDATA[ în HTML e comentariu fals ]]></p>',
    'majuscule' => '<DIV CLASS="Bloc"><P>Text<BR></P></DIV><Div Id=x></dIV>',
    'false' => "<?xml version=\"1.0\"?><!ELEMENT x><!doctype html public \"-//W3C//DTD\" 'a>b'></ p>text</><p>< 3 și <= 4 și x<3</p><i>a<b</i>",
    'crlf' => "<p>\r\nlinia 1\r\n</p>\r\n<div\r\nclass=\"a\"\r\n>x</div>",
    'bom-octeti' => "\xEF\xBB\xBF<p>octeți răi: \xFF\xFE \xC3( \x00 NUL</p>",
    'neterminat-tag' => '<p>text <div class="x',
    'neterminat-comentariu' => '<p>a</p><!-- fără sfârșit',
    'neterminat-script' => '<script>var x = 1; </scrip',
    'la-sfarsit' => 'text <a href=x',
    'gol' => '',
    'plaintext' => '<p>a</p><plaintext><b>tot text</b></plaintext>',
    'atribute-ciudate' => '<input type=checkbox checked disabled = "" value = da ><img src="a.jpg" / ><br/ ><hr / >',
    'strain' => '<svg><p><![CDATA[ > <img src=x> ]]><svg><foreignObject><style>a<b</style></foreignObject><style>c<d</style></svg>'
        . '<math><mi><style>e<f</style></mi><annotation-xml encoding="text/html"><style>g<h</style></annotation-xml></math>'
        . '<div><svg><g><path d="M0"></g><font><style>i<j</style></font></div><style>k<l</style><svg/><style>m<n</style>',
    'litere' => "<ș>nu e tag</ș> <\xC3\xA9a> <1a> <_a> <a\xC3\xA9>da</a\xC3\xA9>",
];
$corpus['mare'] = str_repeat($corpus['pagina'] . $corpus['ghilimele'] . $corpus['script'], 40);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 1. Tokenizarea și recompunerea: octeții de intrare');
$gresite = [];
foreach ($corpus as $nume => $html) {
    $tokeni = Tokenizer::tokenizeaza($html);
    if (Tokenizer::recompune($html, $tokeni) !== $html || !Tokenizer::acopera($html, $tokeni)) {
        $gresite[] = $nume;
    }
}
$verifica('pe fiecare dintre cele ' . count($corpus) . ' fragmente, tokenii se ating cap la cap, acoperă tot textul și îl recompun octet cu octet',
    $gresite === [], implode(', ', $gresite));
// Texte făcute la întâmplare din bucăți de HTML (sămânță fixă, deci aceleași la fiecare rulare): orice amestec se recompune.
mt_srand(20261117);
$bucati = ['<', '>', '/', '"', "'", '=', ' ', "\n", '!', '-', '--', '<!--', '-->', '?', 'a', 'p', 'div', 'script', 'style', 'svg', 'math',
    'title', 'textarea', 'plaintext', '<![CDATA[', ']]>', '&amp;', '&', ';', 'ș', "\xC2\xA0", "\xFF", "\x00", 'DOCTYPE', '</', 'class',
    '<script>', '</script>', '<svg>', '</svg>', '<!doctype html>', 'x', '1', '<foreignObject>', '</foreignObject>', '<mi>', '<p>', '</p>',
    '<font color=x>', '<annotation-xml encoding=text/html>', '<style>', '</style>', '<title>', '</g>', '<svg/>'];
$gresiteAleator = 0;
for ($k = 0; $k < 3000; $k++) {
    $text = '';
    for ($b = mt_rand(1, 40); $b > 0; $b--) {
        $text .= $bucati[mt_rand(0, count($bucati) - 1)];
    }
    $tokeni = Tokenizer::tokenizeaza($text);
    if (Tokenizer::recompune($text, $tokeni) !== $text || !Tokenizer::acopera($text, $tokeni)) {
        $gresiteAleator++;
    }
}
$verifica('3000 de texte făcute la întâmplare din bucăți de HTML: fiecare se recompune octet cu octet', $gresiteAleator === 0, $gresiteAleator . ' greșite');

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 2. Tokenii');
$descrie = static function (string $html): array {
    $rez = [];
    foreach (Tokenizer::tokenizeaza($html) as $t) {
        $rez[] = $t->tip . ($t->nume !== '' ? ':' . $t->nume : '') . ($t->brut !== '' ? '[' . $t->brut . ']' : '') . ($t->complet ? '' : '!');
    }
    return $rez;
};
$h = $corpus['ghilimele'];
$t = Tokenizer::tokenizeaza($h);
$a = $t[0];
$atr = static fn (Token $tok, string $n): array => ($x = $tok->atribut($n)) === null ? [] : [$x->ghilimele, $x->valoare($h), substr($h, $x->numeStart, $x->numeEnd - $x->numeStart)];
$verifica('atributele: ghilimele duble, simple, fără; valoarea goală; fără valoare (pozițiile numelui și ale valorii)',
    $a->nume === 'a' && $atr($a, 'href') === ['', '/camere/', 'href'] && $atr($a, 'title') === ['"', 'Camere', 'title']
    && $atr($a, 'data-x') === ["'", '{"a":1}', 'data-x'] && $atr($a, 'data-gol') === ['"', '', 'data-gol']
    && $atr($a, 'data-fara-valoare') === [null, '', 'data-fara-valoare'] && $atr($a, 'target') === ['', '_blank', 'target']
    && substr($h, $a->atribute[2]->start, $a->atribute[2]->end - $a->atribute[2]->start) === "data-x='{\"a\":1}'",
    json_encode(array_map(static fn ($x) => [$x->nume, $x->ghilimele, $x->valoare($h)], $a->atribute)));
$lipit = $t[3];
$egal = $t[6];
$dublu = $t[9];
$verifica('atribute lipite („"x"title”), un „=” la începutul numelui (ca WHATWG), duplicatele (primul contează, octeții rămân), numele cu litere mici',
    $lipit->valoare($h, 'title') === 'lipit' && $egal->atribute[0]->nume === '=x' && $egal->valoare($h, 'href') === 'y'
    && count($dublu->atribute) === 3 && $dublu->valoare($h, 'href') === 'a' && $dublu->atribute[1]->duplicat && $dublu->atribute[2]->duplicat
    && $dublu->atribute[2]->nume === 'href', json_encode(array_map(static fn ($x) => [$x->nume, $x->duplicat], $dublu->atribute)));
$verifica('textul brut și RCDATA până la tagul lor de închidere (`</scr`+`ipt>` în șir, `</p>` în CSS, `<b>` în textarea, `</b>` în title)',
    $descrie($corpus['brut']) === ['deschidere:style', 'text[style]', 'inchidere:style', 'deschidere:textarea', 'text[textarea]', 'inchidere:textarea',
        'deschidere:title', 'text[title]', 'inchidere:title', 'deschidere:iframe', 'text[iframe]', 'inchidere:iframe', 'deschidere:noscript',
        'text[noscript]', 'inchidere:noscript', 'deschidere:xmp', 'text[xmp]', 'inchidere:xmp'],
    implode(' ', $descrie($corpus['brut'])));
$s = Tokenizer::tokenizeaza($corpus['script']);
$texteScript = array_values(array_map(static fn (Token $x): string => $x->octeti($corpus['script']), array_filter($s, static fn (Token $x): bool => $x->brut === 'script')));
$verifica('scriptul: `</scr`+`ipt>` nu-l închide; în „escaped” cu `<script>` înăuntru („double escaped”) `</script>` din comentariu nu-l închide',
    count($texteScript) === 3 && str_contains($texteScript[0], "'</scr' + 'ipt>'") && $texteScript[1] === '{"@context":"x"}'
    && $texteScript[2] === "<!-- document.write('<script>x()</script>'); -->" && end($s)->tip === Token::INCHIDERE,
    json_encode($texteScript, JSON_UNESCAPED_UNICODE));
$verifica('comentariile: `<!---->`, `<!-->`, `<!--->`, `-- `înăuntru, `--!>`, `--->`; conținutul fiecăruia',
    array_map(static fn (Token $x): string => substr($corpus['comentarii'], $x->continutStart, max(0, $x->continutEnd - $x->continutStart)),
        array_values(array_filter(Tokenizer::tokenizeaza($corpus['comentarii']), static fn (Token $x): bool => $x->tip === Token::COMENTARIU)))
    === ['', '', '', ' a -- b ', ' x ', ' y -'], implode(' ', $descrie($corpus['comentarii'])));
$verifica('comentariile condiționale rămân comentarii (scriptul din ele nu e un tag)',
    $descrie($corpus['conditionale']) === ['comentariu', 'comentariu', 'deschidere:p', 'text', 'inchidere:p', 'comentariu']);
$verifica('SVG: `<style>` și `<title>` cu tokeni obișnuiți, `<![CDATA[`…`]]>` secțiune CDATA; în HTML, CDATA e un comentariu fals',
    $descrie($corpus['svg']) === ['deschidere:svg', 'deschidere:style', 'text', 'inchidere:style', 'deschidere:title', 'text', 'inchidere:title', 'cdata',
        'deschidere:path', 'inchidere:svg', 'deschidere:p', 'fals', 'inchidere:p'], implode(' ', $descrie($corpus['svg'])));
// Conținutul străin: ce scoate parserul din SVG/MathML și punctele de integrare (WHATWG, construcția arborelui), fără arbore.
$strain = [
    '<svg><p><![CDATA[ > <img src=x> ]]>' => 'deschidere:svg deschidere:p fals text deschidere:img text',
    '<svg><foreignObject><style>a<b</style></foreignObject><style>c<d</style></svg>' =>
        'deschidere:svg deschidere:foreignobject deschidere:style text[style] inchidere:style inchidere:foreignobject deschidere:style text deschidere:d< inchidere:svg',
    '<math><mi><style>a<b</style></mi><style>c<d</style></math>' =>
        'deschidere:math deschidere:mi deschidere:style text[style] inchidere:style inchidere:mi deschidere:style text deschidere:d< inchidere:math',
    '<svg><font color=red><style>a<b</style></svg>' => 'deschidere:svg deschidere:font deschidere:style text[style] inchidere:style inchidere:svg',
    '<svg><font><style>a<b</style></font></svg><style>e<f</style>' =>
        'deschidere:svg deschidere:font deschidere:style text deschidere:b< inchidere:font inchidere:svg deschidere:style text[style] inchidere:style',
    '<svg/><style>a<b</style>' => 'deschidere:svg deschidere:style text[style] inchidere:style',
    '<svg></p><style>a<b</style>' => 'deschidere:svg inchidere:p deschidere:style text[style] inchidere:style',
    '<math><annotation-xml encoding="text/html"><style>a<b</style></annotation-xml></math>' =>
        'deschidere:math deschidere:annotation-xml deschidere:style text[style] inchidere:style inchidere:annotation-xml inchidere:math',
    '<math><annotation-xml><style>a<b</style></annotation-xml></math>' =>
        'deschidere:math deschidere:annotation-xml deschidere:style text deschidere:b< inchidere:annotation-xml inchidere:math',
    '<div><svg></div><style>a<b</style>' => 'deschidere:div deschidere:svg inchidere:div deschidere:style text[style] inchidere:style',
    '<svg><g><path d="1"></g><style>a<b</style></svg><style>c<d</style>' =>
        'deschidere:svg deschidere:g deschidere:path inchidere:g deschidere:style text deschidere:b< inchidere:svg deschidere:style text[style] inchidere:style',
    '<svg><math><mi><style>a<b</style></mi></math></svg>' =>
        'deschidere:svg deschidere:math deschidere:mi deschidere:style text deschidere:b< inchidere:mi inchidere:math inchidere:svg',
    '<svg><title><svg><style>a<b</style></svg><style>c<d</style></title></svg>' =>
        'deschidere:svg deschidere:title deschidere:svg deschidere:style text deschidere:b< inchidere:svg deschidere:style text[style] inchidere:style inchidere:title inchidere:svg',
];
$gresite = [];
foreach ($strain as $html => $asteptat) {
    if (implode(' ', $descrie((string) $html)) !== $asteptat) {
        $gresite[] = $html . ' → ' . implode(' ', $descrie((string) $html));
    }
}
$verifica('conținutul străin (' . count($strain) . ' cazuri): `<p>`, `<font color>`, `</p>` și o închidere HTML scot parserul din SVG (CDATA '
    . 'devine comentariu fals, textul brut pornește iar); în `foreignObject`, `mi` și `annotation-xml` HTML textul brut există; '
    . '`<font>` fără atribute, `<svg/>`, `math` din SVG și `annotation-xml` fără `encoding` nu schimbă nimic', $gresite === [], implode('; ', $gresite));
$verifica('după „<” doar o literă ASCII începe un tag (`<ș>`, `<é`, `<1`, `<_` rămân text, `</ș>` e un comentariu fals; un nume poate continua '
    . 'cu octeți din afara ASCII-ului)',
    $descrie($corpus['litere']) === ['text', 'fals', 'text', 'deschidere:a' . "\xC3\xA9", 'text', 'inchidere:a' . "\xC3\xA9"], implode(' ', $descrie($corpus['litere'])));
$verifica('doctype (și cel vechi, cu „>” în identificator), `<?xml…?>`, `<!ELEMENT>`, `</ p>`, `</>`: tokeni falși; „< 3”, „<=”, „x<3”: text; '
    . '„a<b</i>”: tagul „b<”, ca WHATWG',
    $descrie($corpus['false']) === ['fals', 'fals', 'doctype', 'text', 'fals', 'text', 'fals', 'deschidere:p', 'text', 'inchidere:p', 'deschidere:i', 'text',
        'deschidere:b<'],
    implode(' ', $descrie($corpus['false'])));
$verifica('majusculele: numele tagurilor și ale atributelor cu litere mici, octeții neatinși',
    $descrie($corpus['majuscule']) === ['deschidere:div', 'deschidere:p', 'text', 'deschidere:br', 'inchidere:p', 'inchidere:div', 'deschidere:div', 'inchidere:div']
    && Tokenizer::tokenizeaza($corpus['majuscule'])[0]->atribut('class')?->valoare($corpus['majuscule']) === 'Bloc');
$verifica('neterminate: un tag la sfârșitul textului (incomplet, cu octeții lui), un comentariu fără sfârșit, `</scrip` (scriptul rămâne deschis)',
    $descrie($corpus['neterminat-tag']) === ['deschidere:p', 'text', 'deschidere:div!'] && $descrie($corpus['neterminat-comentariu']) === ['deschidere:p', 'text', 'inchidere:p', 'comentariu!']
    && $descrie($corpus['neterminat-script']) === ['deschidere:script', 'text[script]'] && $descrie($corpus['la-sfarsit']) === ['text', 'deschidere:a!']
    && $descrie($corpus['plaintext']) === ['deschidere:p', 'text', 'inchidere:p', 'deschidere:plaintext', 'text[plaintext]']);
$ac = Tokenizer::tokenizeaza($corpus['atribute-ciudate']);
$verifica('`/>` (și `/ >`, `<br/ >`: nu e auto-închidere), spații în jurul lui „=”, atribute fără valoare',
    $ac[0]->valoare($corpus['atribute-ciudate'], 'value') === 'da' && $ac[0]->atribut('checked')?->areValoare() === false
    && $ac[0]->valoare($corpus['atribute-ciudate'], 'disabled') === '' && !$ac[1]->autoInchis && !$ac[2]->autoInchis && !$ac[3]->autoInchis
    && Tokenizer::tokenizeaza('<br/>')[0]->autoInchis, json_encode([$ac[1]->autoInchis, $ac[2]->autoInchis]));
$p = $corpus['pagina'];
$tp = Tokenizer::tokenizeaza($p);
$structura = Tokenizer::blocuri($p, $tp);
$verifica('garda de structură: stiva blocurilor div/section/article, cu clasele, adâncimea și perechea; filtrul pe prefixul clasei',
    count($structura['blocuri']) === 2 && $structura['blocuri'][0]['clase'] === ['et_pb_section', 'sectiune-1'] && $structura['blocuri'][1]['adancime'] === 1
    && $structura['blocuri'][0]['inchidere'] !== null && $structura['probleme'] === []
    && count(Tokenizer::blocuri($p, $tp, 'et_pb_row')['blocuri']) === 1, json_encode($structura));
$rupt = '<div class=a><section><div>x</section></div></section><article>';
$probleme = Tokenizer::blocuri($rupt, Tokenizer::tokenizeaza($rupt))['probleme'];
$verifica('garda de structură vede un bloc închis implicit, o închidere fără deschidere și un bloc neînchis',
    count($probleme) === 3, implode('; ', $probleme));
$imbricat = '<div id=a><div id=b><p>x<p>y</div></div><br><p>z';
$ti = Tokenizer::tokenizeaza($imbricat);
$verifica('perechea unui element (aceleași nume numărate); fără pereche: un element gol și `<p>` închis implicit',
    Tokenizer::pereche($ti, 0) === 7 && Tokenizer::pereche($ti, 1) === 6 && Tokenizer::pereche($ti, 8) === null && Tokenizer::pereche($ti, 2) === null,
    implode(' ', array_map(static fn (int $k): string => var_export(Tokenizer::pereche($ti, $k), true), [0, 1, 2, 8])));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 3. Editarea: doar intervalul ei');
/** Octeții din afara intervalelor schimbate sunt exact cei de la început (și textul nou e cel cerut, în locul lui). */
$doarIntervalul = static function (string $vechi, Editare $e): array {
    $nou = $e->aplica();
    $inlocuiri = $e->inlocuiri();
    $poz = 0;
    $pozNou = 0;
    foreach ($inlocuiri as [$start, $end, $text]) {
        $bucata = substr($vechi, $poz, $start - $poz);
        if (substr($nou, $pozNou, strlen($bucata)) !== $bucata) {
            return [false, 'octeții dinaintea lui ' . $start . ' s-au schimbat'];
        }
        $pozNou += strlen($bucata);
        if (substr($nou, $pozNou, strlen($text)) !== $text) {
            return [false, 'textul nou lipsește la ' . $start];
        }
        $pozNou += strlen($text);
        $poz = $end;
    }
    $rest = substr($vechi, $poz);
    return [substr($nou, $pozNou) === $rest && strlen($nou) === $pozNou + strlen($rest), $nou];
};
$sursa = "<div class=\"et_pb_section\" id=sus>\n  <a href=/vechi/ title='Titlu' data-activ>Link</a>\n  <img src=\"/a.jpg\" alt=\"\" />\n"
    . "  <div class=\"et_pb_text\"><p>Text vechi, cu ș și …</p></div>\n  <span data-gol=>x</span>\n</div>\n<p>după</p><em a=\"x\"b=\"y\">lipit</em>";
$cazuri = [
    'valoarea unui atribut cu ghilimele duble' => [static fn (Editare $e) => $e->valoareAtribut((int) $e->primul('img'), 'src', '/b.webp'),
        "<img src=\"/b.webp\" alt=\"\" />"],
    'valoarea unui atribut fără ghilimele (rămâne fără)' => [static fn (Editare $e) => $e->valoareAtribut((int) $e->primul('a'), 'href', '/nou/'),
        '<a href=/nou/ title'],
    'valoarea fără ghilimele care are nevoie de ele' => [static fn (Editare $e) => $e->valoareAtribut((int) $e->primul('a'), 'href', '/a b/'),
        '<a href="/a b/" title'],
    'valoarea cu ghilimele simple' => [static fn (Editare $e) => $e->valoareAtribut((int) $e->primul('a'), 'title', 'Nou'), "title='Nou'"],
    'un atribut fără valoare primește valoare' => [static fn (Editare $e) => $e->valoareAtribut((int) $e->primul('a'), 'data-activ', '1'),
        'data-activ="1">Link'],
    'valoarea goală fără ghilimele (`a=`)' => [static fn (Editare $e) => $e->valoareAtribut((int) $e->primul('span'), 'data-gol', 'da'),
        '<span data-gol=da>x'],
    'interiorul unui element' => [static fn (Editare $e) => $e->interior((int) $e->primul('div', 'class', 'et_pb_text'), '<p>Text nou</p>'),
        '<div class="et_pb_text"><p>Text nou</p></div>'],
    'ștergerea unui nod întreg' => [static fn (Editare $e) => $e->stergeNod((int) $e->primul('div', 'class', 'et_pb_text')), "\n  \n  <span"],
    'ștergerea unui element gol' => [static fn (Editare $e) => $e->stergeNod((int) $e->primul('img')), "</a>\n  \n  <div"],
    'ștergerea unui atribut (cu spațiul dinainte)' => [static fn (Editare $e) => $e->stergeAtribut((int) $e->primul('a'), 'title'), '<a href=/vechi/ data-activ>'],
    'ștergerea unui atribut lipit de următorul (separatorul rămâne)' => [static fn (Editare $e) => $e->stergeAtribut((int) $e->primul('em'), 'a'),
        '<em b="y">'],
    'inserarea unui atribut (înainte de „ />”)' => [static fn (Editare $e) => $e->insereazaAtribut((int) $e->primul('img'), 'loading', 'lazy'),
        '<img src="/a.jpg" alt="" loading="lazy" />'],
    'inserarea unui atribut fără valoare' => [static fn (Editare $e) => $e->insereazaAtribut((int) $e->primul('div'), 'data-ax', null),
        '<div class="et_pb_section" id=sus data-ax>'],
    'mai multe deodată' => [static fn (Editare $e) => $e->valoareAtribut((int) $e->primul('div'), 'id', 'jos')
        ->interior((int) $e->primul('p'), 'Alt text')->stergeAtribut((int) $e->primul('span'), 'data-gol')->insereazaAtribut((int) $e->primul('a'), 'rel', 'x'),
        '<p>Alt text</p>'],
];
foreach ($cazuri as $eticheta => [$fa, $asteptat]) {
    $e = new Editare($sursa);
    try {
        $fa($e);
        [$ok, $nou] = $doarIntervalul($sursa, $e);
        $verifica('editarea: ' . $eticheta . ' (doar intervalul ei)', $ok && str_contains((string) $nou, $asteptat), $ok ? (string) $nou : (string) $nou);
    } catch (Throwable $ex) {
        $verifica('editarea: ' . $eticheta, false, $ex->getMessage());
    }
}
$refuzuri = [];
foreach ([
    'două înlocuiri suprapuse' => static fn (Editare $e) => $e->interior((int) $e->primul('div', 'class', 'et_pb_text'), 'a')->stergeNod((int) $e->primul('p')),
    'două inserări în același punct' => static fn (Editare $e) => $e->insereazaAtribut((int) $e->primul('img'), 'a', '1')->insereazaAtribut((int) $e->primul('img'), 'b', '2'),
    'interiorul unui element gol' => static fn (Editare $e) => $e->interior((int) $e->primul('img'), 'x'),
    'o valoare cu ghilimeaua atributului' => static fn (Editare $e) => $e->valoareAtribut((int) $e->primul('img'), 'src', 'a"b'),
    'un nume de atribut nevalid' => static fn (Editare $e) => $e->insereazaAtribut((int) $e->primul('img'), 'on load', 'x'),
    'un atribut care lipsește, la ștergere' => static fn (Editare $e) => $e->stergeAtribut((int) $e->primul('img'), 'nu-exista'),
] as $eticheta => $fa) {
    try {
        $fa(new Editare($sursa));
        $refuzuri[] = $eticheta . ': primit';
    } catch (InvalidArgumentException) {
    }
}
$verifica('refuzate (InvalidArgumentException): înlocuiri suprapuse, două inserări în același punct, interiorul unui element gol, ghilimeaua '
    . 'atributului în valoare, un nume nevalid, un atribut care lipsește', $refuzuri === [], implode('; ', $refuzuri));
$e = new Editare($sursa);
$e->interior((int) $e->primul('div', 'class', 'et_pb_text'), '<p>Alt</p>');
$dupa = $e->aplica();
$blocuriInainte = array_map(static fn (array $b): array => [$b['eticheta'], $b['clase'], $b['adancime']], Tokenizer::blocuri($sursa, Tokenizer::tokenizeaza($sursa))['blocuri']);
$blocuriDupa = array_map(static fn (array $b): array => [$b['eticheta'], $b['clase'], $b['adancime']], Tokenizer::blocuri($dupa, Tokenizer::tokenizeaza($dupa))['blocuri']);
$verifica('după o editare a interiorului, structura blocurilor (garda) e aceeași', $blocuriInainte === $blocuriDupa && $blocuriInainte !== []);
$verifica('Editare::scapa: &, ", <, > scăpate, UTF-8 neatins', Editare::scapa('a & "b" <c> ș…') === 'a &amp; &quot;b&quot; &lt;c&gt; ș…');
$fara = new Editare($corpus['pagina']);
$verifica('fără nicio editare, aplica() dă exact octeții de intrare', $fara->aplica() === $corpus['pagina']);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 4. Constructorul arborelui: spațiul de nume, tagurile ignorate, perechile, documentele nesigure');
/** Tokenii ca text: felul:numele[brut]@spațiul (SVG, MathML), !ign (ignorat), !mut (mutat de parser), →perechea. */
$arbore = static function (string $html): string {
    $a = Tokenizer::analizeaza($html);
    $d = [];
    foreach ($a['tokeni'] as $k => $t) {
        $p = Tokenizer::pereche($a['tokeni'], $k);
        $d[] = $t->tip . ($t->nume !== '' ? ':' . $t->nume : '') . ($t->brut !== '' ? '[' . $t->brut . ']' : '')
            . ($t->spatiu !== '' && $t->spatiu !== 'html' ? '@' . $t->spatiu : '') . ($t->ignorat ? '!ign' : '') . ($t->mutat ? '!mut' : '')
            . ($p !== null ? '→' . $p : '');
    }
    return implode(' ', $d);
};
// Fiecare caz e verificat față de constructorul WHATWG (parse5, cu cele două abateri ale lui de la WHATWG îndreptate; din
// afara repo-ului). Unde browserul vede un <img onerror>, tokenizerul îl vede tagul lui; unde e text, e text.
$constructor = [
    'o închidere fără pereche în SVG e ignorată (`div` e special): parserul rămâne în SVG, `<style>` are tokeni, `<img>` iese în HTML'
        => ['<div><svg></span><style><img src=x onerror=1></style></svg></div>',
            'deschidere:div→7 deschidere:svg@svg inchidere:span!ign deschidere:style@svg deschidere:img inchidere:style!ign inchidere:svg!ign inchidere:div'],
    'la fel în MathML' => ['<div><math></b><style><img src=x onerror=1></style></math></div>',
        'deschidere:div→7 deschidere:math@math inchidere:b!ign deschidere:style@math deschidere:img inchidere:style!ign inchidere:math!ign inchidere:div'],
    '`<mglyph>` în `<mi>` rămâne MathML (și `<style>` din el)' => ['<math><mi><mglyph><style><img src=x onerror=1></style></mglyph></mi></math>',
        'deschidere:math@math→8 deschidere:mi@math→7 deschidere:mglyph@math deschidere:style@math deschidere:img inchidere:style!ign inchidere:mglyph!ign inchidere:mi inchidere:math'],
    '`<malignmark>` în `<mi>` la fel' => ['<math><mi><malignmark><style>a<b</style></malignmark></mi></math>',
        'deschidere:math@math→8 deschidere:mi@math→7 deschidere:malignmark@math→6 deschidere:style@math text deschidere:b<@math inchidere:malignmark inchidere:mi inchidere:math'],
    '`</svg>` ignorat într-un `foreignObject` cu un `<div>` deschis; după `</foreignObject>`, `<style>` e iar SVG'
        => ['<svg><foreignObject><div></svg></div></foreignObject><style><img src=x onerror=1></style></svg>',
            'deschidere:svg@svg deschidere:foreignobject@svg→5 deschidere:div→4 inchidere:svg!ign inchidere:div inchidere:foreignobject deschidere:style@svg deschidere:img inchidere:style!ign inchidere:svg!ign'],
    'formatarea reconstruită sub SVG: `</b>` (adopția) scoate parserul din SVG, `<style>` are text brut'
        => ['<p><b></p><svg></b><style>a<b</style>', 'deschidere:p→2 deschidere:b inchidere:p deschidere:svg@svg inchidere:b deschidere:style→7 text[style] inchidere:style'],
    '`</td>` închide celula și din SVG (domeniul tabelului trece de `desc`)'
        => ['<table><tr><td><svg><desc><p></td><style>a<b</style>',
            'deschidere:table deschidere:tr deschidere:td→6 deschidere:svg@svg deschidere:desc@svg deschidere:p inchidere:td deschidere:style→9 text[style] inchidere:style'],
    'un atribut cu „</style>” într-un `<a>` din SVG rămâne un atribut (`<img>` din el nu e un element)'
        => ['<svg></span><style><a title="</style><img src=x onerror=1>">', 'deschidere:svg@svg inchidere:span!ign deschidere:style@svg deschidere:a@svg'],
    '`<noframes>` în corp are text brut (ca în cap)' => ['<p>x<noframes><b>y</b></noframes>', 'deschidere:p text deschidere:noframes→4 text[noframes] inchidere:noframes'],
    'cu doctype, `<table>` închide `<p>` (fără pereche); fără doctype (quirks), nu' => ['<!DOCTYPE html><p>a<table></table></p>',
        'doctype deschidere:p text deschidere:table→4 inchidere:table inchidere:p'],
    'modul quirks' => ['<p>a<table></table></p>', 'deschidere:p→4 text deschidere:table→3 inchidere:table inchidere:p'],
    '`</form>` scos din mijlocul stivei: formularul e mutat, fără pereche' => ['<form><div></form>x</div>',
        'deschidere:form!mut deschidere:div→4 inchidere:form text inchidere:div'],
    'adopția: `<b>` și `<p>` mutate, fără pereche' => ['<b>x<p>y</b>z</p>', 'deschidere:b!mut text deschidere:p!mut text inchidere:b text inchidere:p'],
    'un `<b>` scos în fața tabelului: tabelul e mutat, `<b>` își păstrează perechea' => ['<table><b>x</b></table>',
        'deschidere:table!mut deschidere:b→3 text inchidere:b inchidere:table'],
    'un `<span/>` e un span deschis: `</span>` de după el e al lui' => ['<div class="a"><span>x<span/>y</span>z</span></div>',
        'deschidere:div→8 deschidere:span→7 text deschidere:span→5 text inchidere:span text inchidere:span inchidere:div'],
    'un `<iframe/>` are text brut până la `</iframe>`, care e perechea lui' => ['<iframe/><img src=x onerror=1></iframe>',
        'deschidere:iframe→2 text[iframe] inchidere:iframe'],
    'un `<frameset>` după text e ignorat (și `<frame>`, `</frameset>`)' => ['<b>x</b><frameset><frame></frameset>',
        'deschidere:b→2 text inchidere:b deschidere:frameset!ign deschidere:frame!ign inchidere:frameset!ign'],
    '`<frameset>` înaintea textului (și a unui `<br>`): corpul iese din document, cu elementele lui' => ['<i><link><frameset><frame></frameset>',
        'deschidere:i!mut deschidere:link!mut deschidere:frameset→4 deschidere:frame inchidere:frameset'],
    'tabelul: `<td>` fără `<tr>` primește rândul implicit; `<col>` în afara tabelului e ignorat' => ['<col><table><td>a</td></table>',
        'deschidere:col!ign deschidere:table→5 deschidere:td→4 text inchidere:td inchidere:table'],
];
$gresite = [];
foreach ($constructor as $eticheta => [$html, $asteptat]) {
    if ($arbore($html) !== $asteptat) {
        $gresite[] = $eticheta . ': ' . $html . ' → ' . $arbore($html);
    }
}
$verifica('constructorul arborelui (' . count($constructor) . ' cazuri): închiderile fără pereche, punctele de integrare, adopția, tabelele, '
    . 'quirks, frameset, perechile', $gresite === [], implode('; ', $gresite));
$nesigure = [
    'conținutul unui `<select>`' => '<select><style>a</style><xmp>b</xmp></select>',
    '`<search>`' => '<p><search>x</search>',
    'CDATA într-un punct de integrare' => '<math><mi><![CDATA[x]]></mi></math>',
    'o stivă peste ' . \App\Support\Html5\Constructie::ADANCIME_MAXIMA . ' de elemente' => str_repeat('<div>', \App\Support\Html5\Constructie::ADANCIME_MAXIMA + 5),
    'F1: `<frameset>` cu `<frame onload>` (frameset-ok diferă pe U+FFFD/NUL)' => '<frameset><frame onload=x></frameset>',
    'F1: un `<frame>` singur' => '<div><frame onload=x>',
    'F2: `<template>` (conținutul poate fi viu, clonat de JS)' => '<template><img src=x onerror=1></template>',
    'F2: `<template shadowrootmode>` (shadow DOM declarativ)' => '<div><template shadowrootmode=open><img src=x onerror=1></template></div>',
    'F2: `<template shadowroot>` (atributul vechi)' => '<div><template shadowroot=open><b>x</b></template></div>',
    'F3: `<iframe srcdoc>` (subdocument viu neparcurs)' => '<iframe srcdoc="<img src=x onerror=1>"></iframe>',
    'F4: `<input>` în `<select>` (Chromium îl ține înăuntru)' => '<select><input><option>x</option></select>',
    'F4: `<textarea>` în `<select>`' => '<select><textarea>x</textarea></select>',
    'F4: `<keygen>` în `<select>`' => '<select><keygen></select>',
];
$gresite = [];
foreach ($nesigure as $eticheta => $html) {
    if (Tokenizer::analizeaza($html)['nesigure'] === []) {
        $gresite[] = $eticheta;
    }
}
$curate = [];
foreach (['pagina', 'ghilimele', 'script', 'brut', 'svg', 'strain', 'mare'] as $nume) {
    if (Tokenizer::analizeaza($corpus[$nume])['nesigure'] !== []) {
        $curate[] = $nume . ': ' . implode(' | ', Tokenizer::analizeaza($corpus[$nume])['nesigure']);
    }
}
$verifica('documentele nesigure (alt browser le-ar putea tokeniza altfel): ' . implode(', ', array_keys($nesigure)) . '; corpusul obișnuit nu e nesigur',
    $gresite === [] && $curate === [], 'neprinse: ' . implode(', ', $gresite) . ' / nesigure: ' . implode('; ', $curate));
// Declanșatoarele F1-F4 sunt înguste: construcțiile obișnuite (un `<select>` cu opțiuni, un `<iframe src>`, un `<template>` lipsă)
// nu trebuie marcate nesigure.
$curateF14 = [];
foreach ([
    'select cu opțiuni' => '<div><select name=x><option>a</option><option>b</option></select></div>',
    'select cu optgroup' => '<select><optgroup label=g><option>a</option></optgroup></select>',
    'iframe cu src (nu srcdoc)' => '<iframe src="/pagina" title=x></iframe>',
    'frame în numele unui atribut/text' => '<div data-srcdoc=1>srcdoc frameset template</div>',
] as $eticheta => $html) {
    if (Tokenizer::analizeaza($html)['nesigure'] !== []) {
        $curateF14[] = $eticheta . ': ' . implode(' | ', Tokenizer::analizeaza($html)['nesigure']);
    }
}
$verifica('F1-F4: declanșatoarele sunt înguste — un `<select>` cu opțiuni, un `<iframe src>` și text care conține cuvintele nu sunt nesigure',
    $curateF14 === [], implode('; ', $curateF14));
$tCdata = Tokenizer::tokenizeaza('<math><mi><![CDATA[x]]></mi><mo>y</mo><ms><![CDATA[ ]]></ms></math><svg><![CDATA[ a ]]></svg>');
$verifica('CDATA: în SVG o secțiune CDATA; într-un punct de integrare MathML un comentariu fals (ca Blink), marcat nesigur',
    $tCdata[2]->tip === Token::FALS && end($tCdata)->tip === Token::INCHIDERE && count(array_filter($tCdata, static fn (Token $t): bool => $t->tip === Token::CDATA)) === 1);
$structuraSlash = Tokenizer::blocuri('<div class="et_pb_section"><div/><p>a</p></div><div class="et_pb_row">b</div>',
    Tokenizer::tokenizeaza('<div class="et_pb_section"><div/><p>a</p></div><div class="et_pb_row">b</div>'), 'et_pb_');
$verifica('garda de structură cu `<div/>` (un div deschis, ca în browser): et_pb_row stă în et_pb_section, care nu se închide',
    array_column($structuraSlash['blocuri'], 'adancime') === [0, 1] && count($structuraSlash['probleme']) === 1
    && str_contains($structuraSlash['probleme'][0], 'nu se închide'), json_encode($structuraSlash, JSON_UNESCAPED_UNICODE));
$structuraTabel = Tokenizer::blocuri('<div class=a><table><td></div></td></table></div>', Tokenizer::tokenizeaza('<div class=a><table><td></div></td></table></div>'));
$verifica('garda de structură: `</div>` dintr-o celulă e ignorat (tabelul e limita domeniului), iar div-ul se închide la `</div>` de după tabel',
    $structuraTabel['probleme'] === ['</div> fără deschidere, la octetul 24'] && $structuraTabel['blocuri'][0]['inchidere'] !== null,
    json_encode($structuraTabel, JSON_UNESCAPED_UNICODE));

$scrie('');
$scrie('== 5. Editarea, cu perechile constructorului');
$editeaza = static function (string $html, callable $fa): string {
    try {
        $e = new Editare($html);
        $fa($e);
        return $e->aplica();
    } catch (InvalidArgumentException $ex) {
        return 'REFUZ: ' . $ex->getMessage();
    }
};
$sterse = [];
foreach (['iframe', 'noscript', 'style', 'textarea', 'title', 'script', 'xmp', 'noembed', 'div'] as $nume) {
    $html = '<' . $nume . '/><img src=x onerror=alert(1)></' . $nume . '><p>după</p>';
    $rez = $editeaza($html, static fn (Editare $e) => $e->stergeNod(0));
    if ($rez !== '<p>după</p>') {
        $sterse[] = $nume . ' → ' . $rez;
    }
}
$verifica('stergeNod pe `<iframe/>`, `<noscript/>`, `<style/>`, `<textarea/>`, `<title/>`, `<script/>`, `<xmp/>`, `<noembed/>`, `<div/>`: '
    . 'nodul întreg, până la tagul lui de închidere (textul brut nu rămâne marcaj)', $sterse === [], implode('; ', $sterse));
$verifica('interior() pe un span cu un `<span/>` înăuntru: până la închiderea lui adevărată (a doua `</span>`)',
    $editeaza('<div class="a"><span>x<span/>y</span>z</span></div>', static fn (Editare $e) => $e->interior((int) $e->primul('span'), 'NOU'))
    === '<div class="a"><span>NOU</span></div>');
$refuzate = [];
foreach ([
    '`<p>` închis de `<div>`' => ['<p>a<div>b</div>', 0],
    '`<span>` închis de `</div>`' => ['<div><span>x</div>', 1],
    'un element neînchis' => ['<div>x', 0],
    'un `<iframe/>` neînchis (textul brut până la sfârșit)' => ['<iframe/><img src=x onerror=1>', 0],
    'un `<b>` mutat de adopție' => ['<b>x<p>y</b>z</p>', 0],
    'un tag ignorat (`<head>` în corp)' => ['<p>a<head>b', 2],
] as $eticheta => [$html, $k]) {
    if (!str_starts_with($editeaza($html, static fn (Editare $e) => $e->stergeNod($k)), 'REFUZ')) {
        $refuzate[] = $eticheta;
    }
}
$verifica('stergeNod refuză un nod fără sfârșitul lui (închis implicit, neînchis, mutat, ignorat): ar rămâne conținutul fără tag',
    $refuzate === [], 'neprinse: ' . implode(', ', $refuzate));
$verifica('stergeNod pe un element gol și pe un `<path/>` din SVG: doar tagul', $editeaza('<p>a<br/>b</p>', static fn (Editare $e) => $e->stergeNod(2)) === '<p>ab</p>'
    && $editeaza('<svg><path d="1"/><g></g></svg>', static fn (Editare $e) => $e->stergeNod(1)) === '<svg><g></g></svg>');
$cazuriAtribut = [
    '`<g/x=1>` nu devine `<g/>` (în SVG ar fi auto-închis): `<g>`' => ['<svg><g/x=1><circle r=1></circle></g></svg>', 1, 'x', '<svg><g><circle r=1></circle></g></svg>'],
    '`<a href=y x/>` nu devine `<a href=y/>` (valoarea ar primi „/”)' => ['<a href=y x/>z</a>', 0, 'x', '<a href=y />z</a>'],
    'un atribut lipit de următorul' => ['<em a="x"b="y">', 0, 'a', '<em b="y">'],
    'ultimul atribut, înainte de `/>`' => ['<img src="a.jpg" alt="" />', 0, 'alt', '<img src="a.jpg" />'],
];
$gresite = [];
foreach ($cazuriAtribut as $eticheta => [$html, $k, $nume, $asteptat]) {
    $rez = $editeaza($html, static fn (Editare $e) => $e->stergeAtribut($k, $nume));
    if ($rez !== $asteptat) {
        $gresite[] = $eticheta . ': ' . $rez;
    }
}
$verifica('stergeAtribut nu schimbă tokenizarea tagului (`/>` nou, o valoare fără ghilimele lungită): ' . count($cazuriAtribut) . ' cazuri',
    $gresite === [], implode('; ', $gresite));
$verifica('valoareAtribut pe un atribut fără valoare refuză o ghilimea dublă nescăpată (ca la cel cu ghilimele duble)',
    str_starts_with($editeaza('<input disabled>', static fn (Editare $e) => $e->valoareAtribut(0, 'disabled', 'a"b')), 'REFUZ')
    && $editeaza('<input disabled>', static fn (Editare $e) => $e->valoareAtribut(0, 'disabled', Editare::scapa('a"b'))) === '<input disabled="a&quot;b">');
$lung = '<script><!--' . str_repeat('-<', 100000);
$t0 = microtime(true);
$tokeniLung = Tokenizer::tokenizeaza($lung);
$durata = microtime(true) - $t0;
$verifica('scriptul „escaped” cu 100 000 de „-<” (200 KB) se tokenizează în timp liniar (sub o secundă; înainte, ~30 s)',
    $durata < 1.0 && count($tokeniLung) === 2 && Tokenizer::recompune($lung, $tokeniLung) === $lung, sprintf('%.2f s', $durata));
$comLung = '<!--' . str_repeat('-', 200000);
$t0 = microtime(true);
$tComLung = Tokenizer::tokenizeaza($comLung);
$durataCom = microtime(true) - $t0;
$comTerminat = '<p>a</p><!--' . str_repeat('-', 60000) . ' --><p>b</p>';
$t1 = microtime(true);
Tokenizer::tokenizeaza($comTerminat);
$durataComT = microtime(true) - $t1;
$verifica('un comentariu cu o serie lungă de „-” (200 000, neterminat; și unul terminat) se tokenizează în timp liniar (sub o secundă; înainte, pătratic)',
    $durataCom < 1.0 && $durataComT < 1.0 && count($tComLung) === 1, sprintf('neterminat %.2f s, terminat %.2f s', $durataCom, $durataComT));

$scrie('');
$scrie('== 6. Reparațiile rundei 2: abaterea Blink, codarea atributelor, contextul editării (singur, combinat, nesiguranța), BOM-ul UTF-16');
// SPEC-R2-01: o închidere din conținut străin cu numele unui element SVG camelCase (clippath, textpath…) peste un element HTML
// cu același nume: Blink rescrie majusculele și ignoră tagul (rămâne în SVG, `<style>` are tokeni, `<img>` e viu), WHATWG iese
// din SVG (`<style>` e text brut). Documentul e nesigur; un nume care nu e SVG camelCase (`<foo>`) nu.
$blink = [
    '<p><clippath><svg><path></clippath><style><img src=x onerror=1></style></p>' => true,
    '<p><clippath><svg><path></CLIPPATH><style><img src=x onerror=1></style></p>' => true,
    '<div><textpath><svg><g></textpath><iframe><img src=x onerror=1></iframe></div>' => true,
    '<p><foo><svg><path></foo><style><img src=x onerror=1></style></p>' => false,
];
$gresite = [];
foreach ($blink as $html => $eNesigur) {
    $are = Tokenizer::analizeaza($html)['nesigure'] !== [];
    if ($are !== $eNesigur) {
        $gresite[] = $html . ' → ' . ($are ? 'nesigur' : 'curat');
    }
}
$verifica('SPEC-R2-01: o închidere SVG camelCase (clippath/textpath, și cu majuscule) peste un element HTML e nesigură; `<foo>` (nu e SVG camelCase) nu',
    $gresite === [], implode('; ', $gresite));
// SPEC-R3-02 / T1c (înlocuiește vechea T1b): orice „&” în valoarea unui atribut care decide construcția (`encoding` la
// annotation-xml, `type` la input) face documentul nesigur — referințele din valoarea unui atribut se decodează altfel între
// browsere la capete (un „&#38;sol;” e „&” + „sol;” o dată, nu „/”), iar vechiul fix decoda de două ori (648d4e9). O valoare
// clară, fără „&” (`text/html`, `hidden`), rămâne nesigură doar dacă trebuie, și dă aceeași decizie ca înainte.
$hDublu = '<math><annotation-xml encoding="text&#38;sol;html"><style><img src=x onerror=1></style></annotation-xml></math>';
$hDublu47 = '<math><annotation-xml encoding="text&#38;#47;html"><xmp><img src=x onerror=1></xmp></annotation-xml></math>';
$hRefSimplu = '<math><annotation-xml encoding="text&#47html"><x-foo>a</x-foo></annotation-xml></math>';
$hClar = '<math><annotation-xml encoding="text/html"><x-foo><![CDATA[ x ]]></x-foo></annotation-xml></math>';
$nes = static fn (string $html): bool => Tokenizer::analizeaza($html)['nesigure'] !== [];
// x-foo fără sufix de spațiu de nume (`@math`/`@svg`) = HTML; `fals` (CDATA devenit comentariu fals), fără token `cdata`.
$t1cClarArbore = $arbore($hClar);
$xFooHtml = static fn (string $a): bool => str_contains($a, 'deschidere:x-foo') && !str_contains($a, 'deschidere:x-foo@');
$verifica('SPEC-R3-02/T1c: orice „&” în `encoding` (chiar `&#38;sol;` dublu-codat, care înainte trecea ca „text/html") face documentul nesigur; '
    . '`text/html" clar rămâne punct de integrare HTML (x-foo e HTML, CDATA e comentariu fals), nu nesigur',
    $nes($hDublu) && $nes($hDublu47) && $nes($hRefSimplu) && !$nes($hClar) && $xFooHtml($t1cClarArbore)
    && str_contains($t1cClarArbore, 'fals') && !str_contains($t1cClarArbore, 'cdata'),
    $t1cClarArbore . ' / dublu nes=' . var_export($nes($hDublu), true) . ' clar nes=' . var_export($nes($hClar), true));
$verifica('SPEC-R3-02/T1c: „&” în `type` la <input> e nesigur (decizia foster/frameset ar diferi); `type=hidden` clar nu e nesigur',
    $nes('<table><input type="hid&#38;#100;en"><tr><td>x</td></tr></table>') && !$nes('<table><input type=hidden><tr><td>x</td></tr></table>'),
    '');
// T4: un BOM UTF-16 (FE FF / FF FE) la început → browserul decodează ca UTF-16, tokenizerul pe octeți nu: nesigur. BOM-ul UTF-8 nu.
$verifica('T4: un BOM UTF-16 la începutul documentului e nesigur; BOM-ul UTF-8 (EF BB BF) nu',
    Tokenizer::analizeaza("\xFF\xFE<p>x</p>")['nesigure'] !== [] && Tokenizer::analizeaza("\xFE\xFF<p>x</p>")['nesigure'] !== []
    && Tokenizer::analizeaza("\xEF\xBB\xBF<p>x</p>")['nesigure'] === []);
// SPEC-R2-03: o ștergere și o valoare pe același atribut fără valoare se suprapun (în orice ordine): refuzate, nu `<input="x">`.
$spec03 = [
    static fn (Editare $e) => $e->stergeAtribut((int) $e->primul('input'), 'disabled')->valoareAtribut((int) $e->primul('input'), 'disabled', 'x'),
    static fn (Editare $e) => $e->valoareAtribut((int) $e->primul('input'), 'disabled', 'x')->stergeAtribut((int) $e->primul('input'), 'disabled'),
];
$spec03ok = true;
foreach ($spec03 as $fa) {
    if (!str_starts_with($editeaza('<input disabled><p>x</p>', $fa), 'REFUZ')) {
        $spec03ok = false;
    }
}
$verifica('SPEC-R2-03: stergeAtribut + valoareAtribut pe același atribut fără valoare (ambele ordini) se refuză ca suprapunere (nu `<input="x">`)',
    $spec03ok && $editeaza('<input disabled><p>x</p>', static fn (Editare $e) => $e->valoareAtribut((int) $e->primul('input'), 'disabled', 'x')) === '<input disabled="x"><p>x</p>');
// E1b: o editare al cărei efect în arbore trece dincolo de intervalul ei, făcând dintr-un text brut inert un element viu, se
// refuză: ștergerea unui element care scotea parserul din SVG/MathML, scoaterea lui color/encoding, eliberarea pointerului
// formularului. O editare inofensivă (echilibrată) trece.
$viu = '<img src=x onerror=1>';
$e1b = [
    'stergeNod(p) care scotea din SVG' => ['<svg><circle r=1></circle><p>x</p><noscript>' . $viu . '</noscript></svg>', static fn (Editare $e) => $e->stergeNod((int) $e->primul('p')), true],
    'stergeAtribut(font,color) în SVG' => ['<svg><font color=red>x</font><style>' . $viu . '</style></svg>', static fn (Editare $e) => $e->stergeAtribut((int) $e->primul('font'), 'color'), true],
    'stergeAtribut(annotation-xml,encoding)' => ['<math><annotation-xml encoding="text/html"><style>' . $viu . '</style></annotation-xml></math>', static fn (Editare $e) => $e->stergeAtribut((int) $e->primul('annotation-xml'), 'encoding'), true],
    'valoareAtribut(annotation-xml,encoding)' => ['<math><annotation-xml encoding="text/html"><style>' . $viu . '</style></annotation-xml></math>', static fn (Editare $e) => $e->valoareAtribut((int) $e->primul('annotation-xml'), 'encoding', 'application/x'), true],
    'insereazaAtribut(annotation-xml,encoding)' => ['<math><annotation-xml><style>' . $viu . '</style></annotation-xml></math>', static fn (Editare $e) => $e->insereazaAtribut((int) $e->primul('annotation-xml'), 'encoding', 'text/html'), true],
    'stergeNod(div) eliberează pointerul formularului' => ['<div><form action=/a></div><form action=//evil.example/x><input name=pw></form>', static fn (Editare $e) => $e->stergeNod((int) $e->primul('div')), true],
    // Combinat: fiecare ștergere e bună în parte (un `<font color face>` din SVG rămâne breakout cât timp mai are un atribut),
    // dar amândouă scot breakout-ul și `<style>` de după devine viu. Verificarea pe editare trece, cea combinată (aplica) refuză.
    'combinat: stergeAtribut(font,color) ȘI (font,face) scot breakout-ul' => ['<svg><font color=red face=a>x</font><style>' . $viu . '</style></svg>',
        static fn (Editare $e) => $e->stergeAtribut((int) $e->primul('font'), 'color')->stergeAtribut((int) $e->primul('font'), 'face'), true],
    // Ștergerea unui breakout face documentul nesigur (abaterea Blink pe `</foreignobject>`): browserele l-ar tokeniza altfel,
    // deci editarea se refuză chiar dacă semnătura modelului de aici (WHATWG) nu se schimbă (SPEC-R2-01 introdus de o editare).
    'stergeNod(b) face documentul nesigur (abaterea Blink)' => ['<foreignobject><svg><b>b</b></foreignobject><style>' . $viu . '</style>',
        static fn (Editare $e) => $e->stergeNod((int) $e->primul('b')), true],
    'stergeNod echilibrat (inofensiv)' => ['<div class=a><p>x</p></div><noscript>' . $viu . '</noscript>', static fn (Editare $e) => $e->stergeNod((int) $e->primul('div', 'class', 'a')), false],
    'stergeAtribut(class) în corp (inofensiv)' => ['<div class=a><p>x</p></div>', static fn (Editare $e) => $e->stergeAtribut((int) $e->primul('div'), 'class'), false],
    // Combinat inofensiv: două valori de atribut în corp, departe de textul brut; `<style>` rămâne brut, deci trece.
    'combinat inofensiv: două valori de atribut, textul brut rămâne' => ['<div class=a>x</div><p id=u>y</p><style>' . $viu . '</style>',
        static fn (Editare $e) => $e->valoareAtribut((int) $e->primul('div'), 'class', 'b')->valoareAtribut((int) $e->primul('p'), 'id', 'v'), false],
];
$gresite = [];
foreach ($e1b as $eticheta => [$html, $fa, $eRefuz]) {
    $refuzat = str_starts_with($editeaza($html, $fa), 'REFUZ');
    if ($refuzat !== $eRefuz) {
        $gresite[] = $eticheta . ($refuzat ? ' (refuzat, trebuia primit)' : ' (primit, trebuia refuzat)');
    }
}
$verifica('E1b: o editare care, prin arbore, face un text brut inert viu (ieșire din SVG, color/encoding, pointerul formularului) e refuzată; una echilibrată trece; și efectul combinat al mai multor editări bune în parte',
    $gresite === [], implode('; ', $gresite));
// T3: o stivă adâncă urmată de închideri fără pereche se tokenizează în timp liniar (constanta pe token mică), nu în zeci de
// secunde. Sub bugetul de lucru (1000 de închideri peste o stivă de ~500): rapid ȘI nesigure gol (cazul extrem, peste buget, îl
// prinde garda rundei 3 — secțiunea 7). Înainte, varianta asta era pătratică.
$adanc = str_repeat('<li><ul>', 1) . str_repeat('<span>', 500) . str_repeat('</li>', 1000);
$t0 = microtime(true);
$aAdanc = Tokenizer::analizeaza($adanc);
$durataAdanc = microtime(true) - $t0;
$verifica('T3: o stivă de ~500 cu 1000 de închideri fără pereche (sub buget) se tokenizează repede, în timp liniar, și nu e marcată nesigur',
    $durataAdanc < 6.0 && $aAdanc['nesigure'] === [], sprintf('%.2f s, nes=%d', $durataAdanc, count($aAdanc['nesigure'])));

$scrie('');
$scrie('== 7. Reparațiile rundei 3: abaterea Blink pe un nume MathML, bugetul de lucru, ocolirile verificării combinate');
// SPEC-R3-01: o închidere din conținut străin cu un nume SVG camelCase (clippath, lineargradient, textpath…) care NU închide
// chiar nodul curent e nesigură — acoperă și cazul când numele mic e al unui element MathML (nu doar HTML) peste un subarbore
// SVG. Blink ajustează majusculele după nodul curent SVG și nu-l mai potrivește, rămâne în SVG; WHATWG iese. Un nume care
// închide chiar nodul curent (`</clippath>` peste un `clipPath` SVG) nu e nesigur; un nume care nu e camelCase (`<foo>`) nu.
$r3blink = [
    '<math><clippath><mi><svg><path></clippath><mi><style><img src=x onerror=1></style></mi></math>' => true,
    '<math><lineargradient><mtext><svg><g></lineargradient><mtext><iframe><img src=x onerror=1></iframe></mtext></math>' => true,
    '<p><clippath><svg><path></clippath><style><img src=x onerror=1></style></p>' => true, // vechiul caz (element HTML), tot nesigur
    '<svg><clippath></clippath><style>a<b</style></svg>' => false, // închide chiar nodul curent (SVG clipPath): la fel în ambele
    '<math><foo><svg><path></foo><style>a<b</style></math>' => false, // `foo` nu e camelCase SVG
];
$gresite = [];
foreach ($r3blink as $html => $eNesigur) {
    $are = Tokenizer::analizeaza($html)['nesigure'] !== [];
    if ($are !== $eNesigur) {
        $gresite[] = $html . ' → ' . ($are ? 'nesigur' : 'curat');
    }
}
$verifica('SPEC-R3-01: o închidere SVG camelCase care nu închide chiar nodul curent (inclusiv peste un element MathML cu numele mic) e nesigură; '
    . 'una care închide chiar nodul curent sau un nume non-camelCase nu', $gresite === [], implode('; ', $gresite));
// T5 + SPEC-R3-06: bugetul de lucru (proporțional cu lungimea, cu prag) oprește analiza și marchează nesigur reconstruirea a 500
// de elemente de formatare pe fiecare text (memoria) și 100 000 de închideri fără pereche peste o stivă adâncă (timpul); o pagină
// obișnuită (chiar mare) folosește o fracțiune mică. Intrări INVENTATE.
$t5 = '<div>' . implode('', array_map(static fn (int $i): string => '<b a' . $i . '>', range(0, 499))) . '</div>' . str_repeat('<div>x</div>', 4000);
$m5 = memory_get_peak_usage();
$t0 = microtime(true);
$a5 = Tokenizer::analizeaza($t5);
$dt5 = microtime(true) - $t0;
$varf5 = (memory_get_peak_usage() - $m5) / 1048576;
$r6li = '<li><ul>' . str_repeat('<span>', 500) . str_repeat('</li>', 100000);
$t0 = microtime(true);
$a6 = Tokenizer::analizeaza($r6li);
$dt6 = microtime(true) - $t0;
$verifica('T5/SPEC-R3-06: reconstruirea a 500 de elemente pe 4000 de texte (memoria) și 100 000 de „</li>” peste o stivă adâncă (timpul) depășesc '
    . 'bugetul de lucru → nesigure, repede și cu memorie mărginită', $a5['nesigure'] !== [] && $a6['nesigure'] !== [] && $dt5 < 3.0 && $dt6 < 3.0 && $varf5 < 160.0,
    sprintf('reconstr %.2fs varf %.0fMB nes=%d; </li> %.2fs nes=%d', $dt5, $varf5, count($a5['nesigure']), $dt6, count($a6['nesigure'])));
$paginaMica = $corpus['pagina'] . $corpus['ghilimele'];
$verifica('T5/SPEC-R3-06: o pagină obișnuită e mult sub buget (nu e marcată nesigur din buget)', Tokenizer::analizeaza($paginaMica)['nesigure'] === []
    && Tokenizer::analizeaza($corpus['mare'])['nesigure'] === []);
// SPEC-R3-03: ocolirile verificării combinate a lui Editare. Pornind de la cazul combinat (un `<font color face>` din SVG rămâne
// breakout cât are un atribut): (B) aplica() între editări, apoi încă o editare și aplica() din nou; (C) o a doua aplica() după una
// refuzată; (D) un stergeNod(div) refuzat (suprapus) nu mai lărgește uniunea. Toate trebuie refuzate (nu întorc HTML cu `<img onerror>` viu).
$htmlFont = '<svg><font color=red face=a>x</font><style><img src=x onerror=alert(1)></style></svg><p id=u>y</p>';
$htmlFontDiv = '<div id=x><svg><font color=red face=a>x</font><style><img src=x onerror=alert(1)></style></svg></div><p>z</p>';
$celRefuza = static function (callable $fa): bool {
    try {
        $fa();
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
};
$bypassB = $celRefuza(static function () use ($htmlFont): void {
    $e = new Editare($htmlFont);
    $f = (int) $e->primul('font');
    $e->stergeAtribut($f, 'color')->valoareAtribut((int) $e->primul('p'), 'id', 'v');
    $e->aplica();
    $e->stergeAtribut($f, 'face');
    $e->aplica();
});
$bypassC = $celRefuza(static function () use ($htmlFont): void {
    $e = new Editare($htmlFont);
    $f = (int) $e->primul('font');
    $e->stergeAtribut($f, 'color')->stergeAtribut($f, 'face');
    try {
        $e->aplica();
    } catch (InvalidArgumentException) {
    }
    $e->aplica(); // a doua aplica() după una refuzată nu mai întoarce HTML neverificat
});
$bypassD = $celRefuza(static function () use ($htmlFontDiv): void {
    $e = new Editare($htmlFontDiv);
    $f = (int) $e->primul('font');
    $e->stergeAtribut($f, 'color')->stergeAtribut($f, 'face');
    try {
        $e->stergeNod((int) $e->primul('div'));
    } catch (InvalidArgumentException) {
    }
    $e->aplica();
});
$verifica('SPEC-R3-03: cele trei ocoliri ale verificării combinate (aplica() între editări; a doua aplica() după una refuzată; stergeNod refuzat '
    . 'care lărgea uniunea) sunt toate refuzate', $bypassB && $bypassC && $bypassD,
    'B=' . var_export($bypassB, true) . ' C=' . var_export($bypassC, true) . ' D=' . var_export($bypassD, true));
// E2: o valoare cu ghilimele, scăpată, a unui atribut care nu dirijează construcția (href/src) trece pe calea rapidă — fără
// retokenizarea întregului document pe fiecare editare — dar schimbă doar intervalul ei, iar efectul combinat rămâne verificat la aplica().
$sursaRapid = '<a href="/vechi/">x</a><img src="/a.jpg"><a href="/alt/">y</a>';
$er = new Editare($sursaRapid);
$er->valoareAtribut((int) $er->primul('a'), 'href', '/nou/')->valoareAtribut((int) $er->primul('img'), 'src', '/b.webp');
$outRapid = $er->aplica();
$verifica('E2: editările pe calea rapidă (href/src cu ghilimele) schimbă doar intervalul lor, iar verificarea combinată rulează la aplica()',
    $outRapid === '<a href="/nou/">x</a><img src="/b.webp"><a href="/alt/">y</a>'
    && Tokenizer::recompune($outRapid, Tokenizer::tokenizeaza($outRapid)) === $outRapid, $outRapid);

$scrie('');
$scrie('Rezultat: ' . $trecute . ' verificări trecute, ' . $esuate . ' eșuate.');
exit($esuate === 0 ? 0 : 1);
