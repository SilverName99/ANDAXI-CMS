<?php

declare(strict_types=1);

/**
 * G-vizual (WP-13, G-M3), partea PHP: paginile, rolurile și declarațiile, configurația pentru
 * tools/dev/capturi-publice.cjs (Playwright), raportul. O pornește tools/dev/capturi-publice.sh (serverele sunt ale lui
 * tools/dev/compara-cu-baza.sh, sau, la autotest și la testul temei, ale celui care cheamă).
 *
 *   capturi-publice.php compara --baza=<url> --curent=<url> [--eticheta=<text>] [--pagini=toate|redus]
 *       [--latimi=390,768,1366,1920] [--declaratii=<fișier.json>|--fara-declaratii] [--fara-capturi] [--dosar=<nume>]
 *       [--parola-client=<text>]   # doar autotestul: o parolă greșită a clientului demo
 *   capturi-publice.php verificari --server=<url> --verificari=<fișier.json> [--dosar=<nume>]
 *
 * Clientul se loghează la începutul fiecărui rol, pe fiecare server; o logare care nu duce la /contul-meu (de exemplu
 * cu o parolă greșită) oprește unealta cu codul 2, ca paginile clientului să nu se compare, în gol, ca ale unui vizitator.
 *
 * **compara**: setul fix de pagini publice (prima pagină, o categorie, un produs cu șablon și unul fără, coșul, checkout-ul,
 * contul, un articol, lista blogului, o pagină legală, un 404 și pagina de mulțumire a unei comenzi demo), ca vizitator
 * (cu un produs în coș) și ca client logat, la 390, 768, 1366 și 1920 px. Pentru fiecare element vizibil se compară
 * proprietățile calculate din PROPRIETATI (după rotunjire). Trece doar fără nicio diferență nedeclarată și fără nicio
 * declarație nefolosită. O diferență voită se declară în $porturiVizual (mai jos): selectorul CSS al elementelor,
 * proprietatea (sau lista lor), pagina (sau lista de pagini, „*” = toate), opțional lățimile și rolurile, și motivul.
 * Pentru ochi (nu decid nimic): capturile PNG ale paginilor care diferă la pixel, în $CMS_DEV_DIR/vizual/<dosar>/, și
 * numărul de pixeli diferiți ai fiecărei pagini (raport.txt).
 *
 * **verificari**: un singur server; fiecare verificare e o expresie JavaScript evaluată în pagină, cu valoarea
 * așteptată (testul temei, tools/dev/test-pluginuri.php, T).
 *
 * Cod de ieșire: 0 = trecut; 1 = diferențe nedeclarate, declarații nefolosite sau verificări greșite; 2 = eroare.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';

use App\Support\Database;

/** Proprietățile calculate comparate (planul, WP-13): o listă fixă; „flex-*” = cele cinci proprietăți flex. */
const PROPRIETATI = [
    'display', 'position', 'top', 'left', 'width', 'height',
    'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
    'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
    'color', 'background-color', 'background-image',
    'font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'text-align', 'text-transform',
    'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
    'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style',
    'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
    'border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius',
    'box-shadow', 'opacity', 'visibility', 'z-index',
    'flex-direction', 'flex-wrap', 'flex-grow', 'flex-shrink', 'flex-basis',
    'grid-template-columns', 'grid-template-rows', 'grid-template-areas',
    'row-gap', 'column-gap',
    'transform',
];
const LATIMI = [390, 768, 1366, 1920];

/**
 * Diferențele voite ale stilurilor calculate, una pe rând, în blocul WP-ului care o aduce (planul, §4.3): fiecare cu
 * „nume”, „selector” (CSS, evaluat în pagina codului curent), „proprietate” (una sau o listă din PROPRIETATI),
 * „pagini” („*” sau o listă de căi), opțional „latimi” și „roluri” (viz, cli), și „motiv”. O declarație pe care nu o
 * folosește nicio diferență pică (nu dovedește nimic). WP-13 nu schimbă niciun CSS fără tema.json, deci lista e goală.
 */
$porturiVizual = [
];

$argumente = array_slice($argv, 1);
$mod = (string) array_shift($argumente);
$optiuni = [];
foreach ($argumente as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $m) !== 1) {
        fwrite(STDERR, "argument necunoscut: $argument\n");
        exit(2);
    }
    $optiuni[$m[1]] = $m[2] ?? true;
}
if (!in_array($mod, ['compara', 'verificari'], true)) {
    fwrite(STDERR, "folosire: capturi-publice.php compara|verificari … (vezi începutul fișierului)\n");
    exit(2);
}

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$dosar = $dosarDev . '/vizual/' . preg_replace('/[^a-z0-9-]+/i', '-', (string) ($optiuni['dosar'] ?? $mod));
exec('rm -rf ' . escapeshellarg($dosar));
@mkdir($dosar, 0770, true);
$fisierConfig = $dosar . '/config.json';
$fisierRezultat = $dosar . '/rezultat.json';
$fisierRaport = $dosar . '/raport.txt';
@file_put_contents($fisierRaport, '');
$scrie = static function (string $linie) use ($fisierRaport): void {
    echo $linie, "\n";
    file_put_contents($fisierRaport, $linie . "\n", FILE_APPEND);
};

$app = require $radacina . '/config/app.php';
$db = Database::connection((array) $app['db']);
if (!$db instanceof PDO) {
    fwrite(STDERR, "baza de test nu răspunde\n");
    exit(2);
}
$unu = static fn (string $sql): string => (string) ($db->query($sql)->fetchColumn() ?: '');
$parola = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));
// Doar pentru autotest (capturi-publice.sh --autotest): o parolă greșită a clientului, ca logarea refuzată să oprească unealta.
if (is_string($optiuni['parola-client'] ?? null)) {
    $parola = (string) $optiuni['parola-client'];
}
$idMenta = $unu("SELECT id FROM products WHERE slug = 'ceai-de-menta'");
$roluri = [
    'viz' => ['produs' => $idMenta, 'cantitate' => 2],
    'cli' => ['email' => 'andrei.exemplu@exemplu.ro', 'parola' => $parola, 'produs' => $idMenta, 'cantitate' => 1],
];

$ruleaza = static function (array $config) use ($fisierConfig, $fisierRezultat, $radacina): ?array {
    file_put_contents($fisierConfig, json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @unlink($fisierRezultat);
    // Playwright ca la celelalte unelte (docs/DEZVOLTARE.md, variabilele NODE_PATH și PLAYWRIGHT_BROWSERS_PATH).
    $comanda = 'NODE_PATH=' . escapeshellarg((string) (getenv('NODE_PATH') ?: '/opt/node22/lib/node_modules'))
        . ' PLAYWRIGHT_BROWSERS_PATH=' . escapeshellarg((string) (getenv('PLAYWRIGHT_BROWSERS_PATH') ?: '/opt/pw-browsers'))
        . ' node ' . escapeshellarg($radacina . '/tools/dev/capturi-publice.cjs') . ' ' . escapeshellarg($fisierConfig) . ' 2>&1';
    passthru($comanda, $cod);
    $rezultat = json_decode((string) @file_get_contents($fisierRezultat), true);
    return $cod === 0 && is_array($rezultat) ? $rezultat : null;
};

// ---------------------------------------------------------------------
// verificari
// ---------------------------------------------------------------------
if ($mod === 'verificari') {
    $verificari = json_decode((string) @file_get_contents((string) ($optiuni['verificari'] ?? '')), true);
    if (!is_array($verificari) || !isset($optiuni['server'])) {
        fwrite(STDERR, "verificari: lipsesc --server=<url> sau --verificari=<fișier.json>\n");
        exit(2);
    }
    $rezultat = $ruleaza(['mod' => 'verificari', 'server' => rtrim((string) $optiuni['server'], '/'), 'roluri' => $roluri,
        'verificari' => $verificari, 'rezultat' => $fisierRezultat]);
    if ($rezultat === null) {
        $scrie('EROARE: capturi-publice.cjs nu a dat un rezultat');
        exit(2);
    }
    $esuate = 0;
    foreach ($rezultat['rezultate'] as $r) {
        $eticheta = $r['eticheta'] . ' (' . $r['rol'] . ' ' . $r['cale'] . ' @' . $r['latime'] . ')';
        if ($r['ok']) {
            $scrie('  OK    ' . $eticheta);
        } else {
            $esuate++;
            $scrie('  EȘEC  ' . $eticheta . ': ' . json_encode($r['valoare'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . ', aștept ' . json_encode($r['asteptat'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . ($r['erori'] !== [] ? ' (erori: ' . implode(' | ', $r['erori']) . ')' : ''));
        }
    }
    $scrie('  INFO  cereri spre alte servere, blocate: ' . ($rezultat['blocate'] !== [] ? implode(', ', $rezultat['blocate']) : 'niciuna'));
    $scrie('capturi-publice verificari: ' . count($rezultat['rezultate']) . ' verificări, ' . $esuate . ' greșite');
    exit($esuate === 0 ? 0 : 1);
}

// ---------------------------------------------------------------------
// compara
// ---------------------------------------------------------------------
foreach (['baza', 'curent'] as $cheie) {
    if (!is_string($optiuni[$cheie] ?? null) || preg_match('#^http://127\.0\.0\.1:\d+$#', rtrim((string) $optiuni[$cheie], '/')) !== 1) {
        fwrite(STDERR, "compara: --$cheie=http://127.0.0.1:<port> lipsește\n");
        exit(2);
    }
}
$nrComanda = $unu('SELECT order_number FROM orders WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');
$slugBlog = $unu('SELECT slug FROM blog_posts WHERE is_published = 1 ORDER BY id LIMIT 1');
$slugCategorie = $unu("SELECT c.slug FROM product_categories c WHERE c.slug = 'ceaiuri'") ?: $unu('SELECT slug FROM product_categories ORDER BY id LIMIT 1');
$pagini = [
    '/',
    '/categorie/' . $slugCategorie,
    '/produs/ceai-de-menta',
    '/produs/ceai-de-musetel',
    '/cos',
    '/checkout',
    '/contul-meu',
    '/blog/' . $slugBlog,
    '/blog',
    '/termeni-si-conditii',
    '/pagina-care-nu-exista',
    '/checkout/succes/' . $nrComanda,
];
$latimi = LATIMI;
if (($optiuni['pagini'] ?? 'toate') === 'redus') {
    $pagini = ['/', '/produs/ceai-de-menta', '/pagina-care-nu-exista'];
}
if (is_string($optiuni['latimi'] ?? null)) {
    $latimi = array_map('intval', explode(',', (string) $optiuni['latimi']));
}
$declaratii = $porturiVizual;
if (isset($optiuni['fara-declaratii'])) {
    $declaratii = [];
} elseif (is_string($optiuni['declaratii'] ?? null)) {
    $declaratii = json_decode((string) file_get_contents((string) $optiuni['declaratii']), true);
    if (!is_array($declaratii)) {
        fwrite(STDERR, "--declaratii: nu e JSON\n");
        exit(2);
    }
}
foreach ($declaratii as $i => $d) {
    $proprietati = (array) ($d['proprietate'] ?? []);
    $necunoscute = array_diff($proprietati, PROPRIETATI);
    if (!is_string($d['selector'] ?? null) || $proprietati === [] || $necunoscute !== [] || !isset($d['pagini'])
        || trim((string) ($d['motiv'] ?? '')) === '') {
        fwrite(STDERR, 'declarația ' . $i . ' („' . ($d['nume'] ?? '?') . '”): trebuie selector, proprietate (din lista PROPRIETATI'
            . ($necunoscute !== [] ? '; necunoscute: ' . implode(', ', $necunoscute) : '') . '), pagini și motiv' . "\n");
        exit(2);
    }
}

$eticheta = (string) ($optiuni['eticheta'] ?? 'baza');
$scrie('G-vizual: ' . $eticheta . ' față de codul curent; ' . count($pagini) . ' pagini × ' . count($latimi) . ' lățimi ('
    . implode(', ', $latimi) . ') × 2 roluri (vizitator, client logat)');
$inceput = microtime(true);
$rezultat = $ruleaza([
    'mod' => 'compara',
    'servere' => ['baza' => rtrim((string) $optiuni['baza'], '/'), 'curent' => rtrim((string) $optiuni['curent'], '/')],
    'pagini' => $pagini,
    'latimi' => $latimi,
    'roluri' => $roluri,
    'proprietati' => PROPRIETATI,
    'declaratii' => array_values($declaratii),
    'capturi' => !isset($optiuni['fara-capturi']),
    'dosar' => $dosar,
    'rezultat' => $fisierRezultat,
]);
if ($rezultat === null) {
    $scrie('EROARE: capturi-publice.cjs nu a dat un rezultat');
    exit(2);
}
$afisate = 0;
foreach ($rezultat['diferente'] as $d) {
    if ($d['declarata'] || $afisate >= 40) {
        continue;
    }
    $afisate++;
    $scrie('  DIFERĂ  ' . $d['rol'] . ' ' . $d['cale'] . ' @' . $d['latime'] . ': ' . $d['cheie'] . ' ' . $d['proprietate'] . ': '
        . $d['baza'] . ' → ' . $d['curent']);
}
if ($rezultat['nedeclarate'] > $afisate) {
    $scrie('  … și încă ' . ($rezultat['nedeclarate'] - $afisate) . ' diferențe nedeclarate (toate în ' . $fisierRezultat . ')');
}
$declarate = count($rezultat['diferente']) - (int) $rezultat['nedeclarate'];
if ($declarate > 0) {
    $scrie('  INFO  ' . $declarate . ' diferențe declarate în $porturiVizual');
}
foreach ($rezultat['nefolosite'] as $nume) {
    $scrie('  EȘEC  declarația „' . $nume . '” nu se potrivește cu nicio diferență (nu dovedește nimic)');
}
$cuPixeli = array_values(array_filter($rezultat['pixeli'], static fn (array $p): bool => $p['diferiti'] !== 0));
$scrie('  INFO  pixeli diferiți (doar pentru ochi): ' . count($cuPixeli) . ' din ' . count($rezultat['pixeli']) . ' perechi de capturi'
    . ($cuPixeli !== [] ? '; capturile lor în ' . $dosar : ''));
foreach (array_slice($cuPixeli, 0, 10) as $p) {
    $scrie('        ' . $p['rol'] . ' ' . $p['cale'] . ' @' . $p['latime'] . ': ' . ($p['diferiti'] < 0 ? 'prea mare pentru numărare'
        : $p['diferiti'] . ' din ' . $p['total'] . ' pixeli') . ' (' . implode(', ', $p['capturi']) . ')');
}
$scrie('  INFO  cereri spre alte servere, blocate: ' . ($rezultat['blocate'] !== [] ? implode(', ', $rezultat['blocate']) : 'niciuna'));
$trecut = (int) $rezultat['nedeclarate'] === 0 && $rezultat['nefolosite'] === [];
$scrie('capturi-publice: ' . $rezultat['pagini'] . ' perechi de pagini, ' . $rezultat['elemente'] . ' elemente, '
    . $rezultat['nedeclarate'] . ' diferențe nedeclarate, ' . count($rezultat['nefolosite']) . ' declarații nefolosite, '
    . round(microtime(true) - $inceput) . ' s' . ($trecut ? '' : ' — EȘEC'));
exit($trecut ? 0 : 1);
