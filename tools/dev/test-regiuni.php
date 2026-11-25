<?php

declare(strict_types=1);

/**
 * Poarta G-regiuni: regiunile dinamice ale documentelor capturate și fundația mutării unui site WooCommerce (pista A; docs/MAGAZIN-WC.md,
 * docs/DEPLOY.md, „Regiunile dinamice”), pe o captură INVENTATĂ („Pensiunea Probă”, tools/dev/lib/captura-divi-proba.php, cu
 * regiuni puse de test) și pe randatorii de PROBĂ (tools/dev/lib/regiuni-proba.php), fără nicio dată a unui client:
 *
 *  1. regulile (`import-wordpress.json` → `regiuni`): gramatica închisă a selectorilor și lista închisă a randatorilor; un randator
 *     necunoscut și orice formă de selector din afara gramaticii sunt refuzate; potrivirea selectorilor pe arbore;
 *     Tot aici, în proces (runda 1 de reparații): potrivirea memoizată (SEC-A6, 16 compuși pe 30 de niveluri), nicio regiune într-un
 *     element cu text brut în afara JSON-LD-ului (SEC-A5) și niciuna pe <html>, <head>, <body> (SPEC-A-08), normalizările din lista
 *     închisă (SPEC-A-07), atributele data-* ale markupului WooCommerce, Stripe și MailPoet (SPEC-A-03), titlul cu `{{ax:` (SPEC-A-06),
 *     octeții capturați la randator (SPEC-A-09);
 *  2. tăierea și dovada, în proces, pe o bază nouă: jetoanele `{{ax:regiune:<id>:<n>}}`, `{{ax:clase_body}}`, `{{ax:titlu}}`, octeții
 *     capturați în `regiuni_capturate`, P1 la import, dovada fiecărui randator pe octeți (cu un câmp volatil declarat); un octet
 *     schimbat în captură = NU cu primul octet diferit, nimic scris; un randator necunoscut, unul neconstruit, o regulă fără
 *     document, un selector care nu găsește nimic, o regiune într-un fragment, două suprapuse, una pe <html> sau <body> = NU; o regiune
 *     nedeclarată în codul unei pagini e refuzată la salvare; importuri separate: dovada documentului (clasele corpului și titlul
 *     cerute de regiuni = cele capturate, SPEC-A-07), documentele `produs` și `sablon_comanda` (SPEC-A-02), baza fără nucleu/0072
 *     (INERT-2);
 *  3. un server cu `wp-compat.json` → `magazin_wc` (licența de dezvoltare „ateliere”): pagina = T cu octeții regiunilor (dus-întors
 *     octet cu octet), conținutul viu al unui randator, o regiune `sesiune` = `no-store, private` și nicio scriere în cache-ul de
 *     pagini (una `pagina` intră în cache), un randator `pagina` care citește sesiunea = 500 și nimic în cache, unul care citește
 *     direct `$_SESSION` vede un tablou gol, iar pagina lui randată cu o sesiune pornită pleacă no-store peste un Cache-Control public
 *     capturat (SEC-A3: secretul unui vizitator nu ajunge la altul), unul care scrie în `$_SESSION` = 500; adresele din care parse_url
 *     nu scoate o cale (`/x:1/?lista=1`, N6) = 404-ul capturat, no-store, nicio intrare, prima pagină din cache a ei; `{{ax:` din datele
 *     cererii rămâne text (și în titlul cerut de o regiune), valoarea unui atribut cu ghilimele = 500, un „<” într-o regiune JSON-LD
 *     = 500, clasele corpului și titlul cerute de o regiune; CSP-ul neschimbat
 *     pe căile din afara magazinului, sursele Stripe doar pe checkout și pe metodele de plată; fiecare rută nouă și fiecare cheie de pe
 *     `/` răspund 404 cât pista lor nu e construită; o cheie necunoscută trece mai departe; seturile runtime-ului din tema.json;
 *  4. același site fără `magazin_wc`: nicio rută nouă, nicio cheie (`/?add-to-cart=` = prima pagină), CSP fără Stripe, ca înainte;
 *  5. înregistrările scrise o dată (catalogul, licența „ateliere”, rutele condiționate, sloturile, schema): ce așteaptă pistele.
 *
 *   tools/dev/php.sh tools/dev/test-regiuni.php
 *
 * Baza (cms_regiuni), serverele (CMS_DEV_PORT+35, +36) și dosarul $CMS_DEV_DIR/test-regiuni/ se șterg la final. Raport:
 * $CMS_DEV_DIR/test-regiuni-raport.txt; cod 0 doar dacă totul a trecut.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$lucru = $dosarDev . '/test-regiuni';
if (!str_ends_with($lucru, '/test-regiuni') || strlen($lucru) < 20) {
    fwrite(STDERR, "dosarul de lucru nu e cel așteptat\n");
    exit(2);
}
exec('rm -rf ' . escapeshellarg($lucru));
foreach (['site/storage', 'site-fara/storage', 'pachet/tema', 'pachet-fara/tema', 'licenta'] as $d) {
    @mkdir($lucru . '/' . $d, 0770, true);
}

define('ANDAXI_DEV_REGIUNI_PROBA', __DIR__ . '/lib/regiuni-proba.php');
require_once $radacina . '/bootstrap.php';
require_once $radacina . '/tools/dev/lib/captura-divi-proba.php';

use App\Http\Router;
use App\Support\CadruCapturat;
use App\Support\CadruCapturat\ContextRegiune;
use App\Support\CadruCapturat\Regiuni;
use App\Support\CompatWp\Interogari;
use App\Support\CspSite;
use App\Support\DateSite;
use App\Support\HtmlCapturat;
use App\Support\HtmlCapturat\SchemaDivi4;
use App\Support\ImportWordPress\Captura\DosarCapturi;
use App\Support\ImportWordPress\Captura\Normalizare;
use App\Support\ImportWordPress\Continut\Divi4;
use App\Support\ImportWordPress\Continut\Divi4Taiere;
use App\Support\ImportWordPress\Continut\TaiereRegiuni;
use App\Support\ImportWordPress\EroareImport;
use App\Support\ImportWordPress\ImportWordPress;
use App\Support\ImportWordPress\PlanCapturat;
use App\Support\ImportWordPress\ScriereCapturata;
use App\Http\GardaPluginuri;
use App\Support\MagazinWc\DupaPlata;
use App\Support\MagazinWc\Mod;
use App\Support\Plugins;
use App\Support\StripeEvenimente;
use App\Support\Tema;
use App\Support\Urmarire;
use App\Support\WpCompat;

$fisierRaport = $dosarDev . '/test-regiuni-raport.txt';
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
$scrie('Test regiunile dinamice și fundația magazinului WooCommerce (G-regiuni) — ' . date('Y-m-d H:i:s'));

// ---------------------------------------------------------------------------------------------------------------------
// Baza, serverele, cererile
// ---------------------------------------------------------------------------------------------------------------------
$socket = (string) (getenv('CMS_DEV_SOCK') ?: ini_get('pdo_mysql.default_socket'));
$utilizator = (string) (getenv('CMS_DEV_DB_USER') ?: 'root');
$parolaDb = (string) (getenv('CMS_DEV_DB_PAROLA') ?: '');
$root = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', $utilizator, $parolaDb, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$baza = 'cms_regiuni';
$serverePornite = [];
register_shutdown_function(static function () use (&$serverePornite, $root, $baza, $lucru): void {
    foreach ($serverePornite as $pid) {
        if ($pid > 0) {
            // Doar procesele pornite aici (serverul și procesele lui de lucru), după PID.
            exec('pkill -TERM -P ' . (int) $pid . ' 2>/dev/null; kill ' . (int) $pid . ' 2>/dev/null');
            for ($i = 0; $i < 50 && trim((string) shell_exec('pgrep -P ' . (int) $pid . ' 2>/dev/null')) !== ''; $i++) {
                usleep(100000);
            }
        }
    }
    $GLOBALS['db'] = null;
    gc_collect_cycles();
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $baza . '`');
    } catch (Throwable) {
    }
    if (str_ends_with($lucru, '/test-regiuni')) {
        exec('rm -rf ' . escapeshellarg($lucru));
    }
});

/** @return array{cod: int, antete: array<string, string>, corp: string} */
$cerere = static function (int $port, string $cale, string $metoda = 'GET', array $antete = []): array {
    $c = curl_init('http://127.0.0.1:' . $port . $cale);
    $primite = [];
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60,
        CURLOPT_PATH_AS_IS => true, CURLOPT_HTTPHEADER => $antete, CURLOPT_CUSTOMREQUEST => $metoda,
        CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$primite): int {
            if (str_contains($linie, ':')) {
                [$n, $v] = explode(':', $linie, 2);
                $primite[strtolower(trim($n))] = trim($v);
            }
            return strlen($linie);
        }]);
    if ($metoda === 'POST') {
        curl_setopt($c, CURLOPT_POSTFIELDS, '');
    }
    $corp = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $cod, 'antete' => $primite, 'corp' => $corp];
};
$pornesteServer = static function (int $port, string $pachet, string $dosarSite) use ($radacina, $lucru, $dosarDev, $baza, $cerere, &$serverePornite): bool {
    // Dosarul site-ului (cache-ul de pagini, sesiunile, jurnalele) prin routerul de probă: `php -S` nu aplică auto_prepend_file
    // scriptului router (tools/dev/lib/router-regiuni.php); docroot-ul rămâne public/ al codului, ca în procesul testului.
    $ini = [];
    foreach (['pdo_mysql.default_socket', 'error_reporting', 'display_errors', 'log_errors', 'error_log', 'memory_limit'] as $cheie) {
        $v = ini_get($cheie);
        if ($v !== false && $v !== '') {
            $ini[] = '-d ' . escapeshellarg($cheie . '=' . $v);
        }
    }
    $mediu = ['PHP_CLI_SERVER_WORKERS' => '2', 'DB_DATABASE' => $baza, 'SITE_DATE_DOSAR' => $pachet, 'LICENTA_DOSAR' => $lucru . '/licenta',
        'APP_URL' => 'http://127.0.0.1:' . $port, 'APP_KEY' => 'cheie-de-proba-a-regiunilor', 'CMS_DEV_SITE_DIR' => $dosarSite,
        'CMS_DEV_PUBLIC_DIR' => $radacina . '/public'];
    $env = '';
    foreach ($mediu as $n => $v) {
        $env .= ' ' . $n . '=' . escapeshellarg($v);
    }
    $pid = (int) trim((string) shell_exec('cd ' . escapeshellarg($radacina) . ' && env' . $env . ' nohup ' . escapeshellarg(PHP_BINARY) . ' ' . implode(' ', $ini)
        . ' -S 127.0.0.1:' . $port . ' -t public tools/dev/lib/router-regiuni.php >> ' . escapeshellarg($dosarDev . '/server-regiuni-' . $port . '.log') . ' 2>&1 & echo $!'));
    $serverePornite[$port] = $pid;
    for ($i = 0; $i < 60; $i++) {
        if ($cerere($port, '/health')['cod'] === 200) {
            return true;
        }
        usleep(200000);
    }
    return false;
};

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 1. Regulile: gramatica închisă a selectorilor, lista închisă a randatorilor, potrivirea pe arbore');
$reguliBune = [
    ['document' => '/lista/', 'selector' => 'div.proba-regiune-lista', 'regiune' => 'proba:lista'],
    ['document' => '/sesiune-proba/', 'selector' => 'div.proba-regiune-cos', 'regiune' => 'proba:cos', 'mod' => 'interior'],
    // Pe același element și clasa lui (ca modulele coșului, care primesc et_pb_wc_cart_empty la coșul gol).
    ['document' => '/sesiune-proba/', 'selector' => 'div.proba-regiune-cos', 'regiune' => 'proba:cos', 'mod' => 'atribut', 'atribut' => 'class'],
    ['document' => '/calendar-proba/', 'selector' => '#proba-calendar', 'regiune' => 'proba:evenimente', 'mod' => 'atribut', 'atribut' => 'data-events'],
    ['document' => '/ecou/', 'selector' => '.et_pb_column > div.proba-regiune-ecou', 'regiune' => 'proba:ecou'],
    ['document' => '/cont-proba/', 'selector' => 'div.proba-regiune-cont', 'regiune' => 'proba:cont'],
    ['document' => '/cont-proba/', 'jeton' => 'clase_body'],
    ['document' => '/cont-proba/', 'selector' => 'h1.proba-titlu', 'jeton' => 'titlu'],
    ['document' => '/indiscret/', 'selector' => 'div.proba-regiune-indiscret', 'regiune' => 'proba:indiscret'],
    ['document' => '/seif-proba/', 'selector' => 'div.proba-regiune-seif', 'regiune' => 'proba:seif'],
    ['document' => '/direct-proba/', 'selector' => 'div.proba-regiune-direct', 'regiune' => 'proba:direct'],
    ['document' => '/scrie-proba/', 'selector' => 'div.proba-regiune-scrie', 'regiune' => 'proba:scrie'],
    // SEC-A5: singura regiune permisă într-un element cu text brut, un <script type="application/ld+json"> (Regiuni::jsonScript).
    ['document' => '/jsonld-proba/', 'selector' => 'script.proba-jsonld', 'regiune' => 'proba:jsonld'],
];
$verifica('regulile de probă trec de validare (regiuni, atributele data-events și class, interiorul și clasa aceluiași element, clase_body, titlu)', Regiuni::valideazaReguli($reguliBune) === [],
    implode('; ', Regiuni::valideazaReguli($reguliBune)));
$verifica('regulile reale ale pistelor (wc:*, wcpc:*, blog:*, articol:*, mailpoet:*, cautare:*), cu „produs:*” și șabloanele, trec de validare',
    Regiuni::valideazaReguli([
        ['document' => '/cos/', 'selector' => 'div.et_pb_wc_cart_products > div.et_pb_module_inner', 'regiune' => 'wc:cos_produse'],
        ['document' => '/cos/', 'selector' => 'div.et_pb_wc_cart_products', 'regiune' => 'wc:cos_produse', 'mod' => 'atribut', 'atribut' => 'class'],
        ['document' => 'produs:*', 'selector' => 'div.et_pb_wc_add_to_cart > div.et_pb_module_inner', 'regiune' => 'wc:adauga_in_cos'],
        ['document' => '/calendar/', 'selector' => 'div.wcpc-calendar', 'regiune' => 'wcpc:evenimente', 'mod' => 'atribut', 'atribut' => 'data-events'],
        ['document' => 'sablon:articol', 'selector' => 'div.et_pb_post_content', 'regiune' => 'articol:continut'],
        ['document' => 'sablon:cautare', 'selector' => 'div.et_pb_blog_grid', 'regiune' => 'blog:lista'],
        ['document' => '/my-account/', 'selector' => 'div.woocommerce', 'regiune' => 'wc:cont'],
    ]) === []);
$necunoscute = ['wc:inexistent', 'x:y', 'proba:nimic', 'WC:COS', 'wc:cos_produse:0', 'regiune', ''];
$scapateId = [];
foreach ($necunoscute as $id) {
    $p = Regiuni::valideazaReguli([['document' => '/x/', 'selector' => 'div.x', 'regiune' => $id]]);
    if ($p === [] || !str_contains(implode(' ', $p), 'lista închisă')) {
        $scapateId[] = $id;
    }
}
$verifica('un randator necunoscut e refuzat (' . count($necunoscute) . ' id-uri: wc:inexistent, x:y, proba:nimic, cu majuscule, cu număr, fără familie, gol)', $scapateId === [],
    implode(', ', $scapateId));
$formeRele = ['div[data-x]', 'div:first-child', '*', 'div, p', 'a + b', 'a ~ b', 'div::before', '#a\\:b', 'div.x:not(.y)', 'DIV.x', 'div >', ' div', 'div ',
    '.x .y .z .a .b .c .d .e .f .g .h .i .j .k .l .m .n', 'div."x"', 'div.é', 'html body', 'div#x[href]'];
$scapateSel = [];
foreach ($formeRele as $sel) {
    $p = Regiuni::valideazaReguli([['document' => '/x/', 'selector' => $sel, 'regiune' => 'wc:cos_produse']]);
    $aruncat = false;
    try {
        TaiereRegiuni::potriviri('<div class="x"></div>', \App\Support\Html5\Tokenizer::analizeaza('<div class="x"></div>')['tokeni'], $sel);
    } catch (InvalidArgumentException) {
        $aruncat = true;
    }
    if ($sel === 'html body') {
        // „html body” e în gramatică (taguri și spații): trece; e aici ca martor al formei bune.
        if ($p !== [] || $aruncat) {
            $scapateSel[] = $sel . ' (refuzat, dar e în gramatică)';
        }
        continue;
    }
    if ($p === [] || !$aruncat) {
        $scapateSel[] = $sel;
    }
}
$verifica('orice formă de selector din afara gramaticii închise e refuzată, la validare și la potrivire (' . (count($formeRele) - 1) . ' forme: atribute, pseudo-clase, *, virgula, +, ~, escapări, majuscule, spații la capăt, prea mulți compuși, ghilimele, non-ASCII)',
    $scapateSel === [], implode(' | ', $scapateSel));
$cazuriRele = [
    'cheie necunoscută' => [['document' => '/x/', 'selector' => 'div.x', 'regiune' => 'wc:cos_produse', 'cache' => 'pagina']],
    'mod necunoscut' => [['document' => '/x/', 'selector' => 'div.x', 'regiune' => 'wc:cos_produse', 'mod' => 'exterior']],
    'atribut fără mod atribut' => [['document' => '/x/', 'selector' => 'div.x', 'regiune' => 'wc:cos_produse', 'atribut' => 'data-events']],
    'modul atribut la un randator doar interior' => [['document' => '/x/', 'selector' => 'div.x', 'regiune' => 'wc:cos_notificari', 'mod' => 'atribut', 'atribut' => 'class']],
    'un atribut al listei, dar nu al randatorului' => [['document' => '/x/', 'selector' => 'div.x', 'regiune' => 'wc:cos_produse', 'mod' => 'atribut', 'atribut' => 'data-events']],
    'un atribut din afara listei' => [['document' => '/x/', 'selector' => 'div.x', 'regiune' => 'wcpc:evenimente', 'mod' => 'atribut', 'atribut' => 'onclick']],
    'modul interior la un randator doar atribut' => [['document' => '/x/', 'selector' => 'div.x', 'regiune' => 'wcpc:evenimente']],
    'fără selector' => [['document' => '/x/', 'regiune' => 'wc:cos_produse']],
    'document greșit' => [['document' => 'x', 'selector' => 'div.x', 'regiune' => 'wc:cos_produse']],
    'document cu ..' => [['document' => '/a/../b/', 'selector' => 'div.x', 'regiune' => 'wc:cos_produse']],
    'document //' => [['document' => '//alt/', 'selector' => 'div.x', 'regiune' => 'wc:cos_produse']],
    'șablon necunoscut' => [['document' => 'sablon:pagina', 'selector' => 'div.x', 'regiune' => 'wc:cos_produse']],
    'jeton necunoscut' => [['document' => '/x/', 'jeton' => 'an']],
    'clase_body cu selector' => [['document' => '/x/', 'selector' => 'body', 'jeton' => 'clase_body']],
    'titlu fără selector' => [['document' => '/x/', 'jeton' => 'titlu']],
    'jeton și regiune' => [['document' => '/x/', 'jeton' => 'titlu', 'selector' => 'h1', 'regiune' => 'wc:cont']],
    'aceeași regulă de două ori' => [['document' => '/x/', 'selector' => 'div.x', 'regiune' => 'wc:cos_produse'],
        ['document' => '/x/', 'selector' => 'div.x', 'regiune' => 'wc:cos_produse']],
    'nu e o listă' => ['a' => ['document' => '/x/']],
];
$scapateCaz = [];
foreach ($cazuriRele as $nume => $r) {
    if (Regiuni::valideazaReguli($r) === []) {
        $scapateCaz[] = $nume;
    }
}
$verifica('regulile greșite sunt refuzate (' . count($cazuriRele) . ' cazuri)', $scapateCaz === [], implode(', ', $scapateCaz));
$pi = ImportWordPress::valideazaReguli(['continut' => 'capturat', 'dialect' => 'divi4', 'regiuni' => [['document' => '/x/', 'selector' => 'div.x', 'regiune' => 'wc:nimic']]]);
$verifica('import-wordpress.json: „regiuni” trece prin aceeași validare (randatorul necunoscut = NU în verifica-pachet); doar pe drumul capturat',
    count($pi) === 1 && str_contains($pi[0], 'lista închisă')
    && ImportWordPress::valideazaReguli(['continut' => 'capturat', 'dialect' => 'divi4', 'regiuni' => $reguliBune]) === []
    && ImportWordPress::valideazaReguli(['regiuni' => []]) !== [], implode('; ', $pi));
$verifica('„sabloane” stă doar în privat/ (adresele unui articol, ale unei categorii, un termen de căutare și o comandă primită ale site-ului real); forma lui e verificată',
    ImportWordPress::doarPrivate(['sabloane' => []]) === ['sabloane']
    && ImportWordPress::valideazaReguli(['continut' => 'capturat', 'dialect' => 'divi4', 'sabloane' => ['articol' => '/un-articol/', 'cautare' => '/?s=proba',
        'comanda' => '/comanda-proba/']]) === []
    && ImportWordPress::valideazaReguli(['continut' => 'capturat', 'dialect' => 'divi4', 'sabloane' => ['pagina' => '/x/']]) !== []
    && ImportWordPress::valideazaReguli(['continut' => 'capturat', 'dialect' => 'divi4', 'sabloane' => ['articol' => 'https://alt.example.test/']]) !== []);
$h = '<div id="a" class="r"><div class="x"><p class="t">1</p><span><p class="t">2</p></span></div></div><p class="t">3</p>';
$tkh = \App\Support\Html5\Tokenizer::analizeaza($h)['tokeni'];
$text = static fn (array $k): string => implode(',', array_map(static fn (int $i): string => $tkh[$i + 1]->octeti($h), $k));
$verifica('potrivirea selectorilor pe arbore: clasa, id, descendentul, copilul direct, tagul',
    $text(TaiereRegiuni::potriviri($h, $tkh, 'p.t')) === '1,2,3' && $text(TaiereRegiuni::potriviri($h, $tkh, '#a p.t')) === '1,2'
    && $text(TaiereRegiuni::potriviri($h, $tkh, 'div.x > p')) === '1' && $text(TaiereRegiuni::potriviri($h, $tkh, 'div.r > div > span > .t')) === '2'
    && TaiereRegiuni::potriviri($h, $tkh, 'div.x > span > div') === [] && count(TaiereRegiuni::potriviri($h, $tkh, 'div')) === 2,
    $text(TaiereRegiuni::potriviri($h, $tkh, 'p.t')) . ' / ' . $text(TaiereRegiuni::potriviri($h, $tkh, '#a p.t')));
// SEC-A6: potrivirea e memoizată pe (element, compus): un lanț lung de descendenți pe elemente imbricate nu mai e exponențial.
$h6 = str_repeat('<div>', 30) . '<span>x</span>' . str_repeat('</div>', 30);
$tk6 = \App\Support\Html5\Tokenizer::analizeaza($h6)['tokeni'];
$t0 = hrtime(true);
$fara6 = TaiereRegiuni::potriviri($h6, $tk6, 'p ' . str_repeat('div ', 14) . 'span');
$cu6 = TaiereRegiuni::potriviri($h6, $tk6, str_repeat('div ', 15) . 'span');
$durata6 = (hrtime(true) - $t0) / 1e9;
$verifica('SEC-A6: selectori cu 16 compuși pe 30 de div-uri imbricate (p, 14 × div, span: nimic, p nu e strămoș; 15 × div, span: span-ul), sub o secundă',
    $fara6 === [] && count($cu6) === 1 && $durata6 < 1.0, sprintf('%.3f s', $durata6));
// SEC-A5 și SPEC-A-08, la tăiere (TaiereRegiuni::locuri): nicio regiune într-un element cu text brut sau RCDATA (în afara JSON-LD-ului) și
// nicio regiune pe <html>, <head> sau <body> (atributele și interiorul lor nu sunt bucăți ale documentului); jetonul clase_body rămâne.
$hb = "<!DOCTYPE html>\n<html class=\"h\" lang=\"ro\"><head><title class=\"t\">T</title><style class=\"s\">p{}</style></head><body class=\"b\">"
    . '<script class="sc">var a;</script><script type=" Application/LD+JSON " class="j">{}</script><textarea class="ta">x</textarea>'
    . '<noscript class="ns">x</noscript><xmp class="x">x</xmp><iframe class="if"></iframe><div class="d">x</div></body></html>';
$tkb = \App\Support\Html5\Tokenizer::analizeaza($hb)['tokeni'];
$iBody = 0;
foreach ($tkb as $k => $tt) {
    if ($tt->tip === \App\Support\Html5\Token::DESCHIDERE && $tt->nume === 'body') {
        $iBody = $k;
        break;
    }
}
$locuriB = static fn (array $reguli): array => TaiereRegiuni::locuri($hb, $tkb, $reguli, [], $iBody);
$scapateBrut = [];
foreach (['script.sc', 'style.s', 'textarea.ta', 'title.t', 'noscript.ns', 'xmp.x', 'iframe.if'] as $sel) {
    $l = $locuriB([['selector' => $sel, 'regiune' => 'proba:lista']]);
    if ($l['locuri'] !== [] || !str_contains(implode(' ', $l['probleme']), 'text brut')) {
        $scapateBrut[] = $sel;
    }
}
$lj = $locuriB([['selector' => 'script.j', 'regiune' => 'proba:jsonld']]);
$verifica('SEC-A5: o regiune „interior” în script, style, textarea, title, noscript, xmp sau iframe = NU; în <script type="application/ld+json"> (tipul fără '
    . 'diferență de litere, cu spații la margini) trece', $scapateBrut === [] && $lj['probleme'] === [] && count($lj['locuri']) === 1,
    implode(', ', $scapateBrut) . ' | ' . implode('; ', $lj['probleme']));
$scapateCadru = [];
foreach ([['selector' => 'html', 'regiune' => 'proba:cos', 'mod' => 'atribut', 'atribut' => 'class'], ['selector' => 'html', 'regiune' => 'proba:lista'],
    ['selector' => 'head', 'regiune' => 'proba:lista'], ['selector' => 'body', 'regiune' => 'proba:cos', 'mod' => 'atribut', 'atribut' => 'class'],
    ['selector' => 'body', 'regiune' => 'proba:lista']] as $r) {
    $l = $locuriB([$r]);
    if ($l['locuri'] !== [] || !str_contains(implode(' ', $l['probleme']), 'nu poate sta pe <' . $r['selector'] . '>')) {
        $scapateCadru[] = $r['selector'] . ':' . ($r['mod'] ?? 'interior');
    }
}
$lcb = $locuriB([['jeton' => 'clase_body'], ['selector' => 'div.d', 'regiune' => 'proba:lista']]);
$verifica('SPEC-A-08: o regiune pe <html> (class sau interiorul), pe <head> sau pe <body> (class sau interiorul) = NU; jetonul clase_body pe class-ul lui <body> '
    . 'și o regiune într-un div trec', $scapateCadru === [] && $lcb['probleme'] === [] && array_column($lcb['locuri'], 'jeton') === ['clase_body', 'regiune:proba:lista:0'],
    implode(', ', $scapateCadru) . ' | ' . implode('; ', $lcb['probleme']));
// SPEC-A-07: normalizările dovezii sunt o listă închisă (Regiuni::NORMALIZARI); un randator doar alege din ea.
$nz = static fn (array $l, string $h): string => Regiuni::normalizeaza($l, $h);
$hex32 = '0123456789abcdef0123456789abcdef';
$verifica('SPEC-A-07: normalizările din lista închisă (quantity_<uniqid>, nonce:<câmp> doar pe câmpul numit, cheie_cos, recaptcha, atribuire_comanda); orice altă '
    . 'normalizare e refuzată',
    Regiuni::normalizariGresite(['quantity_uniqid', 'nonce:woocommerce-cart-nonce', 'nonce:_wpnonce', 'cheie_cos', 'recaptcha', 'atribuire_comanda']) === null
    && Regiuni::normalizariGresite(['totul']) !== null && Regiuni::normalizariGresite(['nonce:*']) !== null && Regiuni::normalizariGresite('quantity_uniqid') !== null
    && Regiuni::normalizariGresite([1]) !== null && Regiuni::normalizariGresite(['nonce:a b']) !== null && Regiuni::normalizariGresite(['x' => 'cheie_cos']) !== null
    && $nz(['quantity_uniqid'], '<label for="quantity_6745a1b2c3d4e">') === '<label for="quantity_X">'
    && $nz(['nonce:woocommerce-cart-nonce'], '<input type="hidden" id="woocommerce-cart-nonce" name="woocommerce-cart-nonce" value="0123456789" />')
        === '<input type="hidden" id="woocommerce-cart-nonce" name="woocommerce-cart-nonce" value="{{nonce}}" />'
    && $nz(['nonce:_wpnonce'], '<a href="/cos/?remove_item=x&amp;_wpnonce=0123456789">') === '<a href="/cos/?remove_item=x&amp;_wpnonce={{nonce}}">'
    && $nz(['nonce:_wpnonce'], '<input name="alt" value="0123456789">') === '<input name="alt" value="0123456789">'
    && $nz(['cheie_cos'], '<input name="cart[' . $hex32 . '][qty]"><a href="?remove_item=' . $hex32 . '">') === '<input name="cart[{{cheie}}][qty]"><a href="?remove_item={{cheie}}">'
    && $nz(['recaptcha'], '<p>a</p><input type="hidden" name="g-recaptcha-response" value=""><script src="/r.js"></script><p>b</p>') === '<p>a</p><p>b</p>'
    && $nz(['atribuire_comanda'], '<p>a</p><wc-order-attribution-inputs></wc-order-attribution-inputs>') === '<p>a</p>'
    && $nz([], '<label for="quantity_6745a1b2c3d4e">') === '<label for="quantity_6745a1b2c3d4e">');
// SPEC-A-03: markupul WooCommerce, Stripe și MailPoet (fragmente inventate, cu atributele data-* ale capturii reale) trece de politica de import.
$fragWc = '<div class="et_pb_module et_pb_wc_cart_products"><form class="woocommerce-cart-form" action="/cos/" method="post">'
    . '<table class="shop_table cart"><tbody><tr class="woocommerce-cart-form__cart-item cart_item"><td class="product-remove">'
    . '<a href="/cos/?remove_item=' . $hex32 . '&amp;_wpnonce=0123456789" class="remove" aria-label="Elimină produsul" data-product_id="7" data-product_sku="P-7">&times;</a></td>'
    . '<td class="product-quantity" data-title="Cantitate"><div class="quantity"><label class="screen-reader-text" for="quantity_6745a1b2c3d4e">Cantitate</label>'
    . '<input type="number" id="quantity_6745a1b2c3d4e" class="input-text qty text" name="cart[' . $hex32 . '][qty]" value="1" min="0" step="1"></div></td>'
    . '</tr></tbody></table></form></div>'
    . '<div class="cos-plutitor"><div class="cos-plutitor-rand" data-cart-item-key="' . $hex32 . '"><a href="#" class="woocommerce-remove-coupon" data-coupon="proba10">[Elimină]</a></div></div>'
    . '<p class="form-row form-row-wide" id="billing_state_field" data-priority="80" data-input-classes="" data-label="Județ"><label for="billing_state">Județ</label>'
    . '<select name="billing_state" id="billing_state" class="state_select" data-placeholder="Alege o opțiune"><option value="">Alege</option></select></p>'
    . '<ul class="wc_payment_methods payment_methods methods"><li class="wc_payment_method payment_method_stripe">'
    . '<input id="payment_method_stripe" type="radio" class="input-radio" name="payment_method" value="stripe" checked="checked" data-order_button_text="">'
    . '<label for="payment_method_stripe">Card</label><div class="payment_box"><div class="wc-stripe-upe-element" data-payment-method-type="card"></div>'
    . '<ul class="woocommerce-SavedPaymentMethods wc-saved-payment-methods" data-count="0"></ul>'
    . '<div class="wc-stripe-proba" data-checkout-session="{&quot;id&quot;:&quot;cs_proba&quot;}"></div></div></li></ul>'
    . '<p class="form-row mailpoet-subscription-field" data-automation-id="checkout-optin"><input type="checkbox" name="mailpoet_proba" value="1"></p>'
    . '<button type="submit" class="button alt" name="woocommerce_checkout_place_order" id="place_order" value="Plasează comanda" data-value="Plasează comanda">Plasează comanda</button>'
    . '<wc-order-attribution-inputs></wc-order-attribution-inputs>'
    . '<form class="mailpoet_form" method="post" action="/" data-parsley-names="{&quot;proba&quot;:&quot;x&quot;}">'
    . '<input type="email" class="mailpoet_text" name="data[form_field_proba]" data-parsley-errors-container=".mailpoet_eroare_proba"></form>'
    . '<button type="button" class="navbar-toggler" data-toggle="collapse">Meniu</button>'
    . '<div class="et_pb_module et_pb_code" data-shortcode_index="0"><span data-installed="1"></span></div>'
    . '<abbr class="required" title="obligatoriu" data-mdb-original-title="obligatoriu" data-mdb-tooltip-init="" data-mdb-tooltip-initialized="true">*</abbr>'
    . '<p data-start="0" data-end="12">Text de probă<br data-start="12" data-end="12"></p>';
$docFrag = static fn (string $f): string => "<!DOCTYPE html>\n<html>\n<head>\n</head>\n<body>\n<div>" . $f . "</div>\n</body>\n</html>\n";
$pbWc = HtmlCapturat::verifica($docFrag($fragWc), new HtmlCapturat\ContextCapturat(), 'import');
$coduri = static fn (string $f): array => array_map(static fn ($p): string => $p->cod, HtmlCapturat::verifica($docFrag($f), new HtmlCapturat\ContextCapturat(), 'import'));
$verifica('SPEC-A-03: rândurile coșului, cuponul, județul, metodele și butonul plății, sesiunea Stripe (JSON), opțiunea MailPoet, formularul MailPoet, butoanele, '
    . 'codul scurt, tooltipurile de pe <abbr> și marcajele editorului trec de politica de import; un data-* necunoscut, un tooltip cu „<” și o sesiune Stripe '
    . 'care nu e JSON sunt tot refuzate',
    $pbWc === [] && in_array('data-necunoscut', $coduri('<p data-nimic-proba="1">x</p>'), true)
    && $coduri('<abbr data-mdb-original-title="&lt;b&gt;x">*</abbr>') !== [] && $coduri('<div data-checkout-session="nu e json"></div>') !== []
    && in_array('tag-necunoscut', $coduri('<wc-order-attribution-x></wc-order-attribution-x>'), true),
    implode(' | ', array_map(static fn ($p): string => $p->linie(), $pbWc)));
// SPEC-A-06 și SPEC-A-09, în proces: titlul cerut de o regiune rămâne text; randatorul are octeții capturați ai regiunii.
$ctxT = new ContextRegiune(null, 'x', '/');
$ctxT->titlu('{{ax:an}} {{AX:an}} <b>');
$verifica('SPEC-A-06: titlul cerut de o regiune e scăpat, cu „{” ca &#123;: un {{ax: din el (oricum ar fi scris) rămâne text, fără EroareJeton',
    $ctxT->titluFinal('x') === '&#123;&#123;ax:an}} &#123;&#123;AX:an}} &lt;b&gt;' && \App\Support\CadruCapturat\Jetoane::literal($ctxT->titluFinal('x')) === null,
    $ctxT->titluFinal('x'));
$claseCos = static fn (int $numar, string $capturat): string => Regiuni::randeaza('proba:cos', (new ContextRegiune(null, 'x', '/', [], 'GET', ['numar' => $numar]))
    ->pentru('proba:cos', 0, 'sesiune', 'atribut', 'class', $capturat));
$verifica('SPEC-A-09: ContextRegiune::capturat() dă randatorului octeții capturați ai regiunii (aici clasele modulului coșului, cu sau fără clasa coșului gol)',
    $claseCos(1, 'proba-regiune-cos et_pb_module proba-cos-gol') === 'proba-regiune-cos et_pb_module'
    && $claseCos(0, 'proba-regiune-cos et_pb_module') === 'proba-regiune-cos et_pb_module proba-cos-gol'
    && (new ContextRegiune(null, 'x', '/'))->pentru('proba:lista', 0, 'pagina', 'interior', null, 'octeți')->capturat() === 'octeți'
    && (new ContextRegiune(null, 'x', '/'))->pentru('proba:lista', 0, 'pagina')->capturat() === '', $claseCos(1, 'proba-regiune-cos et_pb_module proba-cos-gol'));
$verifica('documentele regulilor: calea (decodată), 404, „produs:*” doar pe tipul produs, șabloanele pe tipurile sablon_*',
    count(Regiuni::pentruDocument($reguliBune, 'pagina', '/cont-proba/')) === 3 && Regiuni::pentruDocument($reguliBune, 'pagina', '/nimic/') === []
    && count(Regiuni::pentruDocument([['document' => 'produs:*']], 'produs', '/product/x/')) === 1 && Regiuni::pentruDocument([['document' => 'produs:*']], 'pagina', '/x/') === []
    && count(Regiuni::pentruDocument([['document' => 'sablon:articol']], 'sablon_articol', '/a/')) === 1
    && count(Regiuni::pentruDocument([['document' => '/c%C4%83/']], 'pagina', '/că/')) === 1);
$verifica('catalogul randatorilor: fiecare id din forma <familie>:<nume>, cu cache pagina|sesiune, moduri, pista și clasa lui (App\\Support\\…); '
    . 'niciuna nu există încă, deci niciun randator construit',
    array_filter(array_keys(Regiuni::CATALOG), static fn (string $id): bool => preg_match(Regiuni::RE_ID, $id) !== 1) === []
    && array_filter(Regiuni::CATALOG, static fn (array $d): bool => !in_array($d['cache'], ['pagina', 'sesiune'], true) || $d['moduri'] === []
        || !str_starts_with($d['clasa'], 'App\\Support\\') || class_exists($d['clasa'])) === []
    && array_filter(array_keys(Regiuni::CATALOG), static fn (string $id): bool => Regiuni::randator($id) !== null) === []
    && Regiuni::cache('wc:cos_produse') === 'sesiune' && Regiuni::cache('wc:adauga_in_cos') === 'pagina' && Regiuni::cache('necunoscut:x') === 'sesiune');
$exceptie = null;
try {
    Regiuni::inregistreazaProba('wc:cos_produse', 'pagina', ['interior'], [], new RegiuneProbaLista());
} catch (LogicException $e) {
    $exceptie = $e;
}
$verifica('un randator de probă se înregistrează doar ca proba:* (nimic peste un id al catalogului)', $exceptie !== null);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 2. Tăierea și dovada, în proces, pe o bază nouă');
$root->exec('DROP DATABASE IF EXISTS `' . $baza . '`');
$root->exec('CREATE DATABASE `' . $baza . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$p = proc_open([$radacina . '/tools/dev/php.sh', 'scripts/install.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, $radacina,
    array_merge(getenv(), ['CMS_DEV_DB' => $baza, 'CMS_DEV_LICENTA_DOSAR' => $lucru . '/licenta']));
$outI = (string) stream_get_contents($pipe[1]) . (string) stream_get_contents($pipe[2]);
fclose($pipe[1]);
fclose($pipe[2]);
$db = new PDO('mysql:unix_socket=' . $socket . ';dbname=' . $baza . ';charset=utf8mb4', $utilizator, $parolaDb, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$verifica('scripts/install.php: baza cu tabelul regiuni_capturate (nucleu/0072)', proc_close($p) === 0 && ScriereCapturata::disponibil($db), $outI);
$url = 'https://pensiune-proba.example.test';
$an = date('Y');
$lista = "\n<ul class=\"proba-lista\"><li data-product_id=\"7\">Ceai de probă &amp; miere — 12,50 lei</li><li data-product_id=\"8\">Cană de probă — 30,00 lei</li></ul>\n";
$evenimente = '[{&quot;title&quot;:&quot;Atelier de proba&quot;,&quot;start&quot;:&quot;2026-12-05&quot;}]';
$pagini = [
    ['cale' => '/', 'titlu' => 'Acasă', 'id' => 201],
    ['cale' => '/lista/', 'titlu' => 'Lista', 'id' => 202, 'inCorp' => '<div class="proba-regiune-lista">' . $lista . "</div>\n"],
    ['cale' => '/sesiune-proba/', 'titlu' => 'Coș de probă', 'id' => 203,
        'inCorp' => '<div class="proba-regiune-cos et_pb_wc_cart_proba"><p class="proba-cos">Coșul are 1 produs.</p><input type="hidden" name="proba_cheie" value="0a1b2c3d4e"></div>'
            . "\n"],
    ['cale' => '/calendar-proba/', 'titlu' => 'Calendar', 'id' => 204, 'inCorp' => '<div id="proba-calendar" class="proba-calendar" data-events="' . $evenimente . '"></div>' . "\n"],
    ['cale' => '/ecou/', 'titlu' => 'Ecou', 'id' => 205, 'inCorp' => '<div class="proba-regiune-ecou"><p class="proba-ecou">nimic</p></div>' . "\n"],
    ['cale' => '/cont-proba/', 'titlu' => 'Cont', 'id' => 206,
        'inCorp' => '<h1 class="proba-titlu">Contul meu</h1><div class="proba-regiune-cont"><div class="proba-cont">Panou</div></div>' . "\n"],
    ['cale' => '/indiscret/', 'titlu' => 'Indiscret', 'id' => 207, 'inCorp' => '<div class="proba-regiune-indiscret"><p class="proba-indiscret">Nimic din sesiune.</p></div>' . "\n"],
    ['cale' => '/seif-proba/', 'titlu' => 'Seif', 'id' => 208, 'inCorp' => '<div class="proba-regiune-seif"><p class="proba-seif">Nimic.</p></div>' . "\n"],
    ['cale' => '/direct-proba/', 'titlu' => 'Direct', 'id' => 209, 'inCorp' => '<div class="proba-regiune-direct"><p class="proba-direct">Nimic.</p></div>' . "\n"],
    ['cale' => '/scrie-proba/', 'titlu' => 'Scrie', 'id' => 210, 'inCorp' => '<div class="proba-regiune-scrie"><p class="proba-scrie">Nimic.</p></div>' . "\n"],
    ['cale' => '/jsonld-proba/', 'titlu' => 'JSON-LD', 'id' => 211,
        'inCorp' => '<script type="application/ld+json" class="proba-jsonld">' . Regiuni::jsonScript(RegiuneProbaJsonLd::PRODUS) . "</script>\n"],
];
$optiuni = ['url' => $url, 'nonce' => 'dddd222222', 'ua' => 'windows', 'an' => $an, 'antet' => 21, 'subsol' => 22, 'corp404' => 23,
    'meniu' => [['/', 'Acasă'], ['/lista/', 'Lista']], 'cale404' => '/x2-inexistent/'];
$stari = ['/lista/' => ['produse' => RegiuneProbaLista::LISTA], '/sesiune-proba/' => ['numar' => 1], '/calendar-proba/' => ['evenimente' => RegiuneProbaEvenimente::EVENIMENTE],
    '/jsonld-proba/' => ['produs' => RegiuneProbaJsonLd::PRODUS],
    // Capturile separate de mai jos (SPEC-A-07, SPEC-A-02): ce cer randatorii la dovadă și starea produsului și a comenzii.
    '/corp-adauga/' => ['adauga' => 'woocommerce-cart'], '/corp-titlu/' => ['titlu' => 'Alt titlu'], '/corp-fara-jeton/' => ['adauga' => 'woocommerce-cart'],
    '/corp-bun/' => ['scoate' => 'woocommerce-cart', 'titlu' => 'Contul meu'], '/product/ceai-proba/' => ['produse' => RegiuneProbaLista::LISTA],
    '/comanda-proba/' => ['produse' => RegiuneProbaLista::LISTA]];
// Un Cache-Control public capturat (SEC-A3): pagina cu randatorul care citește direct din $_SESSION pleacă, fără sesiune, cu el.
$anteteCaptura = ['/direct-proba/' => "cache-control: max-age=0, public\r\n"];
$facaCaptura = static function (string $dosarCaptura, array $pagini) use ($optiuni, $stari, $url, $anteteCaptura): void {
    capturaDiviProba($dosarCaptura, $pagini, $optiuni);
    // Starea fiecărei capturi (ce arată pagina: lista, coșul cu un produs, evenimentele), cum o scrie unealta capturii.
    $ix = json_decode((string) file_get_contents($dosarCaptura . '/index.json'), true);
    foreach ($ix['adrese'] as $i => $a) {
        if (isset($stari[$a['cale']])) {
            $ix['adrese'][$i]['stare'] = $stari[$a['cale']];
        }
        if ($a['fel'] === 'pagina' && isset($anteteCaptura[$a['cale']]) && str_ends_with($a['referinta'], '.corp')) {
            file_put_contents($dosarCaptura . '/' . substr($a['referinta'], 0, -5) . '.antete', $anteteCaptura[$a['cale']], FILE_APPEND);
        }
    }
    file_put_contents($dosarCaptura . '/index.json', json_encode($ix, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    sigileazaCapturaProba($dosarCaptura, $url);
};
$facaCaptura($lucru . '/captura', $pagini);
$aruncateDin = static function (DosarCapturi $dosar) use ($url): array {
    $aruncate = [];
    foreach ($dosar->toate() as $x) {
        $r = $dosar->raspuns($x['url']);
        foreach ($r !== null && str_contains($r->corp, '<!DOCTYPE html>') ? (new Divi4())->transforma($r->corp, $url, [])->necunoscute : [] as $n) {
            if ($n['fel'] !== 'noscript' || str_contains($n['inceput'], 'googletagmanager')) {
                $aruncate[$n['sha256']] = ['sha256' => $n['sha256'], 'motiv' => 'proba: ' . ($n['id'] ?? $n['inceput'])];
            }
        }
    }
    return array_values($aruncate);
};
$runtime = CadruCapturat::scripturiRuntime('divi4@1');
/** Planul capturat al unei capturi, cu regulile date: tăierea fiecărei pagini (cu regiunile ei), apoi restul capturii. */
$planifica = static function (string $dosarCaptura, array $reguliRegiuni, array $pagini, array $alteReguli = []) use ($url, $an, $aruncateDin): array {
    $dosar = DosarCapturi::deschide($dosarCaptura);
    $reguli = ['continut' => 'capturat', 'dialect' => 'divi4', 'scripturi_aruncate' => $aruncateDin($dosar), 'regiuni' => $reguliRegiuni] + $alteReguli;
    $pc = new PlanCapturat();
    $taieri = [];
    $paginiDoc = [];
    foreach ($pagini as $pg) {
        $r = $dosar->dupaCale($pg['cale']);
        $rd = (new Divi4($dosar))->transforma((string) $r?->corp, $url, $reguli, $pg['cale']);
        $t = Divi4Taiere::taie($rd, ['tip' => 'pagina', 'cale' => $pg['cale'], 'seo' => null, 'an' => ['modul' => 'et_pb_text_0_tb_footer', 'an' => $an],
            'regiuni' => PlanCapturat::regiuniDocument($reguli, 'pagina', $pg['cale']), 'stare' => $pc->stareCaptura($dosar, $pg['cale'])]);
        $pc->adaugaDocument('pagina:' . $pg['id'], 'pagina', $t, ['titlu' => $pg['titlu'], 'wp_id' => $pg['id'], 'status' => 200, 'sha_captura' => (string) $r?->sha256(),
            'antete' => PlanCapturat::antete((array) $r?->antete, $url), 'valori_import' => ['cale' => $pg['cale']]]);
        $taieri[$pg['cale']] = $t;
        $paginiDoc[$pg['cale']] = 'pagina:' . $pg['id'];
    }
    $pc->dinCaptura($dosar, $reguli, $paginiDoc, [], ['modul' => 'et_pb_text_0_tb_footer', 'an' => $an]);
    $pc->grupeaza();
    return [$pc, $taieri];
};
[$pc, $taieri] = $planifica($lucru . '/captura', $reguliBune, $pagini);
$verifica('tăierea: niciun NU; 11 regiuni (lista, coșul cu interiorul și clasa, evenimentele, ecoul, contul, indiscretul, seiful, directul, scrisul, JSON-LD-ul) '
    . 'plus clase_body și titlu pe pagina contului',
    $pc->probleme === [] && array_sum(array_map(static fn ($t): int => count($t->regiuni), $taieri)) === 11, implode('; ', $pc->probleme));
$ts = $taieri['/sesiune-proba/'];
$verifica('interiorul și clasa aceluiași element: două regiuni, clasa întâi (proba:cos:0, valoarea lui class), apoi interiorul (proba:cos:1)',
    str_contains($ts->continut, '<div class="{{ax:regiune:proba:cos:0}}">{{ax:regiune:proba:cos:1}}</div>')
    && array_map(static fn (array $r): string => $r['mod'] . ':' . ($r['atribut'] ?? '-') . ':' . $r['n'], $ts->regiuni) === ['atribut:class:0', 'interior:-:1']
    && ($ts->regiuni[0]['octeti'] ?? '') === 'proba-regiune-cos et_pb_wc_cart_proba', substr($ts->continut, 0, 300));
$tl = $taieri['/lista/'];
$verifica('regiunea „interior”: jetonul {{ax:regiune:proba:lista:0}} în conținut, octeții capturați întocmai (nimic din pasul 5a în ei), starea capturii',
    str_contains($tl->continut, '<div class="proba-regiune-lista" data-ax-slot') === false && str_contains($tl->continut, '<div class="proba-regiune-lista">{{ax:regiune:proba:lista:0}}</div>')
    && ($tl->regiuni[0]['octeti'] ?? '') === $lista && ($tl->regiuni[0]['stare'] ?? null) === ['produse' => RegiuneProbaLista::LISTA]
    && !str_contains($tl->regiuni[0]['octeti'], 'data-ax-'), substr($tl->continut, 0, 600));
$tc = $taieri['/calendar-proba/'];
$verifica('regiunea „atribut”: jetonul în valoarea lui data-events, ghilimelele rămân, octeții capturați = valoarea atributului',
    str_contains($tc->continut, 'data-events="{{ax:regiune:proba:evenimente:0}}"') && ($tc->regiuni[0]['octeti'] ?? '') === $evenimente
    && ($tc->regiuni[0]['mod'] ?? '') === 'atribut', substr($tc->continut, 0, 400));
$tco = $taieri['/cont-proba/'];
$verifica('clase_body: {{ax:clase_body}} în valoarea lui class de pe <body> (restul atributelor întocmai); titlu: {{ax:titlu}} în h1; valorile capturate la import',
    str_contains($tco->bodyAtribute, 'class="{{ax:clase_body}}"') && str_contains($tco->continut, '<h1 class="proba-titlu">{{ax:titlu}}</h1>')
    && str_starts_with((string) ($tco->valori['clase_body'] ?? ''), 'page-template-default page page-id-206') && ($tco->valori['titlu'] ?? '') === 'Contul meu',
    $tco->bodyAtribute);
$verifica('fără reguli (ca toate site-urile de azi): aceeași tăiere ca înainte, fără regiuni (prima pagină)', $taieri['/']->regiuni === []
    && !str_contains($taieri['/']->bodyAtribute, '{{ax:') && !str_contains($taieri['/']->continut . $taieri['/']->inainte . $taieri['/']->dupa, '{{ax:regiune:'));
$db->beginTransaction();
$paginiId = [];
foreach ($pagini as $pg) {
    $db->prepare('INSERT INTO pages (title, slug, html_content, is_published) VALUES (?, ?, ?, 1)')->execute([$pg['titlu'], trim($pg['cale'], '/'), $taieri[$pg['cale']]->continut]);
    $paginiId['pagina:' . $pg['id']] = (int) $db->lastInsertId();
}
try {
    $scris = ScriereCapturata::scrie($db, $pc, $paginiId, $runtime);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    $scris = ['eroare' => $e->getMessage()];
}
$randuri = $db->query('SELECT document, regiune, n, fel, atribut, octeti, sha256, stare FROM regiuni_capturate ORDER BY document, regiune, n')->fetchAll();
$verifica('scrierea: P1 la import cu octeții regiunilor, dovada fiecărui randator (proba:cos cu câmpul volatil declarat și cu clasa), 11 rânduri în regiuni_capturate cu sha256-ul și starea lor',
    ($scris['regiuni'] ?? -1) === 11 && count($randuri) === 11 && array_filter($randuri, static fn (array $r): bool => hash('sha256', $r['octeti']) !== $r['sha256']) === []
    && json_decode((string) $randuri[1]['stare'], true) === ['numar' => 1], json_encode($scris, JSON_UNESCAPED_UNICODE));
$politica = new HtmlCapturat\ContextCapturat();
$iesiriRele = [];
foreach ([['proba:lista', ['produse' => RegiuneProbaLista::LISTA]], ['proba:cos', ['numar' => 3]], ['proba:cont', []], ['proba:ecou', []]] as [$id, $stare]) {
    $ctxP = (new ContextRegiune($db, 'proba', '/x/', ['t' => '<b>&{{ax:an}}'], 'GET', $stare))->pentru($id, 0, Regiuni::cache($id));
    $iesire = Regiuni::randeaza($id, $ctxP);
    $doc = "<!DOCTYPE html>\n<html>\n<head>\n</head>\n<body>\n<div>" . $iesire . "</div>\n</body>\n</html>\n";
    if (HtmlCapturat::verifica($doc, $politica, 'import') !== []) {
        $iesiriRele[] = $id;
    }
}
$verifica('politica HTML-ului capturat (HtmlCapturat) pe ieșirea fiecărui randator, cu stări inventate: nicio problemă', $iesiriRele === [], implode(', ', $iesiriRele));
$norm = new Normalizare($url);
$ctxD = static fn (string $id, array $stare): ContextRegiune => (new ContextRegiune($db, 'proba', '/x/', [], 'GET', $stare))->pentru($id, 0, Regiuni::cache($id));
$verifica('dovada pe octeți: proba:lista cu starea capturii = octeții capturați; proba:cos cu cheia volatilă diferită trece doar prin normalizarea ei declarată',
    Regiuni::dovada('proba:lista', $lista, $ctxD('proba:lista', ['produse' => RegiuneProbaLista::LISTA]), $norm) === null
    && Regiuni::dovada('proba:cos', '<p class="proba-cos">Coșul are 1 produs.</p><input type="hidden" name="proba_cheie" value="ffffffffff">', $ctxD('proba:cos', ['numar' => 1]), $norm) === null
    && Regiuni::dovada('proba:cos', '<p class="proba-cos">Coșul are 2 produse.</p><input type="hidden" name="proba_cheie" value="ffffffffff">', $ctxD('proba:cos', ['numar' => 1]), $norm) !== null
    && Regiuni::dovada('proba:cos', '<p class="proba-cos">Coșul are 1 produs.</p><input type="hidden" name="alta_cheie" value="ffffffffff">', $ctxD('proba:cos', ['numar' => 1]), $norm) !== null);
$verifica('SPEC-A-07: un randator cu o normalizare din afara listei închise: NU la dovadă, oricare i-ar fi ieșirea',
    str_contains((string) Regiuni::dovada('proba:normalizare', '<p class="proba-normalizare">Altceva.</p>', $ctxD('proba:normalizare', []), $norm), 'nu e în lista închisă'));
$jsonCapturat = Regiuni::jsonScript(RegiuneProbaJsonLd::PRODUS);
$verifica('SEC-A5: Regiuni::jsonScript codează „<”, „>”, „&”, ghilimelele și apostroful din șiruri (nici „</script>”, nici „<!--” nu închid elementul), JSON-ul rămâne același; '
    . 'regiunea JSON-LD importată are octeții lui',
    !str_contains($jsonCapturat, '<') && !str_contains($jsonCapturat, '>') && !str_contains($jsonCapturat, '&') && str_contains($jsonCapturat, '\\u003C/script\\u003E')
    && json_decode($jsonCapturat, true) === RegiuneProbaJsonLd::PRODUS && ($taieri['/jsonld-proba/']->regiuni[0]['octeti'] ?? '') === $jsonCapturat, $jsonCapturat);
$verifica('dovada unui randator neconstruit (wc:cos_produse, pista G) și a unuia necunoscut: NU, cu motivul',
    str_contains((string) Regiuni::dovada('wc:cos_produse', 'x', $ctxD('wc:cos_produse', []), $norm), 'nu e încă construit (pista G)')
    && str_contains((string) Regiuni::dovada('wc:nimic', 'x', $ctxD('wc:nimic', []), $norm), 'lista închisă'));

// Un singur octet schimbat în captură (12,50 → 12,51 în lista capturată): randatorul, cu starea capturii, nu mai dă aceiași octeți.
$paginiMut = $pagini;
foreach ($paginiMut as $i => $pg) {
    if ($pg['cale'] === '/lista/') {
        $paginiMut[$i]['inCorp'] = str_replace('12,50', '12,51', (string) $pg['inCorp']);
    }
}
$facaCaptura($lucru . '/captura-mut', $paginiMut);
[$pcMut, $taieriMut] = $planifica($lucru . '/captura-mut', $reguliBune, $paginiMut);
$inainte = $db->query('SELECT GROUP_CONCAT(sha256 ORDER BY id) FROM regiuni_capturate')->fetchColumn();
$db->beginTransaction();
$eroareMut = '';
try {
    ScriereCapturata::scrie($db, $pcMut, $paginiId, $runtime);
    $db->commit();
} catch (EroareImport $e) {
    $db->rollBack();
    $eroareMut = $e->getMessage();
}
$octetAsteptat = strpos($taieriMut['/lista/']->regiuni[0]['octeti'], '12,51') + 4;
$verifica('un octet schimbat în octeții capturați ai unei regiuni: NU la import, cu primul octet diferit (' . $octetAsteptat . '); nimic scris (tranzacția anulată)',
    str_contains($eroareMut, 'dovada regiunilor') && str_contains($eroareMut, 'proba:lista:0') && str_contains($eroareMut, 'prima diferență la octetul ' . $octetAsteptat . ' ')
    && $db->query('SELECT GROUP_CONCAT(sha256 ORDER BY id) FROM regiuni_capturate')->fetchColumn() === $inainte, $eroareMut);

// Fail-closed la tăiere: fiecare greșeală e NU, pe documentul ei.
$refuz = static function (array $reguli, string $cale = '/lista/') use ($planifica, $lucru, $pagini): string {
    [$pcX] = $planifica($lucru . '/captura', $reguli, $pagini);
    return implode(' | ', $pcX->probleme);
};
$verifica('un randator necunoscut în reguli: NU la import pe fiecare document cu reguli (regulile nu se ignoră în tăcere)',
    str_contains($refuz([['document' => '/lista/', 'selector' => 'div.proba-regiune-lista', 'regiune' => 'wc:inexistent']]), 'lista închisă'));
$verifica('un randator al catalogului încă neconstruit (wc:cos_produse): NU la import, cu pista lui',
    str_contains($refuz([['document' => '/lista/', 'selector' => 'div.proba-regiune-lista', 'regiune' => 'wc:cos_produse']]), 'n-are încă randatorul construit (pista G)'));
$verifica('o formă de selector necunoscută în reguli: NU la import',
    str_contains($refuz([['document' => '/lista/', 'selector' => 'div[class]', 'regiune' => 'proba:lista']]), 'selector nepermis'));
$verifica('un selector care nu găsește nimic: NU', str_contains($refuz([['document' => '/lista/', 'selector' => 'div.nu-exista', 'regiune' => 'proba:lista']]), 'nu găsește niciun element'));
$verifica('o regulă pe calea unui document care nu e în import: NU (pagina ar rămâne înghețată cu starea capturii)',
    str_contains($refuz([['document' => '/nu-e-importata/', 'selector' => 'div.x', 'regiune' => 'proba:lista']]), 'niciun document importat nu e al ei'));
$verifica('o regiune într-un fragment (antetul, comun mai multor documente): NU',
    str_contains($refuz([['document' => '/lista/', 'selector' => 'ul.et-menu', 'regiune' => 'proba:lista']]), 'într-un fragment'));
$verifica('două regiuni suprapuse (una în alta): NU', str_contains($refuz([['document' => '/lista/', 'selector' => 'div.proba-regiune-lista', 'regiune' => 'proba:lista'],
    ['document' => '/lista/', 'selector' => 'ul.proba-lista', 'regiune' => 'proba:lista']]), 'se suprapun'));
$verifica('regiunea „atribut” pe un element fără atribut: NU', str_contains($refuz([['document' => '/lista/', 'selector' => 'div.proba-regiune-lista', 'regiune' => 'proba:evenimente',
    'mod' => 'atribut', 'atribut' => 'data-events']]), 'n-are atributul data-events'));
$verifica('titlul cu taguri în el: NU', str_contains($refuz([['document' => '/lista/', 'selector' => 'div.proba-regiune-lista', 'jeton' => 'titlu']]), 'altceva decât text'));
$refuzHtml = $refuz([['document' => '/lista/', 'selector' => 'html', 'regiune' => 'proba:cos', 'mod' => 'atribut', 'atribut' => 'class']]);
$refuzBody = $refuz([['document' => '/lista/', 'selector' => 'body', 'regiune' => 'proba:cos', 'mod' => 'atribut', 'atribut' => 'class']]);
$verifica('SPEC-A-08 (B1, B2): o regulă pe un atribut al lui <html> sau pe class-ul lui <body> e NU la plan (regiunea ar rămâne înghețată, ori importul s-ar opri)',
    str_contains($refuzHtml, 'nu poate sta pe <html>') && str_contains($refuzBody, 'nu poate sta pe <body>'), $refuzHtml . ' || ' . $refuzBody);

// Importuri separate, fiecare pe captura lui și într-o tranzacție anulată la sfârșit: dovada documentului (SPEC-A-07) și documentele
// noi ale capturii (SPEC-A-02). `$planuri`: pagina cu documentul ei; `$doarCaptura`: adrese capturate fără pagină în plan (un produs,
// adresa unui șablon); `$statusuri`: statusul capturat al unei adrese.
$importa = static function (string $nume, array $planuri, array $reguliX, array $doarCaptura = [], array $alteReguli = [], array $statusuri = [])
    use ($facaCaptura, $planifica, $db, $lucru, $runtime, $url): array {
    $dosarX = $lucru . '/captura-' . $nume;
    $facaCaptura($dosarX, array_merge($planuri, $doarCaptura));
    if ($statusuri !== []) {
        $ix = json_decode((string) file_get_contents($dosarX . '/index.json'), true);
        foreach ($ix['adrese'] as $i => $a) {
            if (isset($statusuri[$a['cale']]) && $a['fel'] === 'pagina') {
                $ix['adrese'][$i]['status'] = $statusuri[$a['cale']];
            }
        }
        file_put_contents($dosarX . '/index.json', json_encode($ix, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        sigileazaCapturaProba($dosarX, $url);
    }
    [$pcX, $taieriX] = $planifica($dosarX, $reguliX, $planuri, $alteReguli);
    $rez = ['plan' => $pcX->probleme, 'avertismente' => $pcX->avertismente, 'documente' => array_map(static fn (array $d): string => (string) $d['tip'], $pcX->documente),
        'eroare' => null, 'randuri' => [], 'regiuni' => []];
    if ($pcX->probleme !== []) {
        return $rez;
    }
    $db->beginTransaction();
    try {
        $ids = [];
        foreach ($planuri as $pg) {
            $db->prepare('INSERT INTO pages (title, slug, html_content, is_published) VALUES (?, ?, ?, 1)')->execute([$pg['titlu'], 'x-' . $nume . '-' . trim($pg['cale'], '/'),
                $taieriX[$pg['cale']]->continut]);
            $ids['pagina:' . $pg['id']] = (int) $db->lastInsertId();
        }
        ScriereCapturata::scrie($db, $pcX, $ids, $runtime);
        $rez['randuri'] = $db->query('SELECT cheie, tip, cale FROM pagini_capturate ORDER BY cheie')->fetchAll();
        $rez['regiuni'] = $db->query('SELECT document, regiune, n FROM regiuni_capturate ORDER BY document, regiune, n')->fetchAll();
    } catch (Throwable $e) {
        $rez['eroare'] = $e->getMessage();
    }
    $db->rollBack();
    return $rez;
};
$corpProba = '<h1 class="proba-titlu">Contul meu</h1><div class="proba-regiune-corp"><p class="proba-corp">Corp.</p></div>' . "\n";
$reguliCorp = static fn (string $cale, bool $cuJetoane): array => array_merge([['document' => $cale, 'selector' => 'div.proba-regiune-corp', 'regiune' => 'proba:corp']],
    $cuJetoane ? [['document' => $cale, 'jeton' => 'clase_body'], ['document' => $cale, 'selector' => 'h1.proba-titlu', 'jeton' => 'titlu']] : []);
$jAdauga = $importa('corp-adauga', [['cale' => '/corp-adauga/', 'titlu' => 'Corp', 'id' => 221, 'inCorp' => $corpProba]], $reguliCorp('/corp-adauga/', true));
$jTitlu = $importa('corp-titlu', [['cale' => '/corp-titlu/', 'titlu' => 'Corp', 'id' => 222, 'inCorp' => $corpProba]], $reguliCorp('/corp-titlu/', true));
$jFaraJeton = $importa('corp-fara-jeton', [['cale' => '/corp-fara-jeton/', 'titlu' => 'Corp', 'id' => 223, 'inCorp' => $corpProba]], $reguliCorp('/corp-fara-jeton/', false));
$jBun = $importa('corp-bun', [['cale' => '/corp-bun/', 'titlu' => 'Corp', 'id' => 224, 'inCorp' => $corpProba]], $reguliCorp('/corp-bun/', true));
$verifica('SPEC-A-07: dovada documentului, cu un singur context pentru toate regiunile lui: o clasă a corpului cerută cu starea capturii și necapturată, un titlu '
    . 'diferit de cel capturat, o cerere fără jetonul ei în document = NU; cereri care dau exact ce s-a capturat trec',
    str_contains((string) $jAdauga['eroare'], 'în plus: woocommerce-cart') && str_contains((string) $jTitlu['eroare'], '„Alt titlu” în loc de „Contul meu”')
    && str_contains((string) $jFaraJeton['eroare'], 'n-are jetonul {{ax:clase_body}}') && $jBun['eroare'] === null && $jBun['plan'] === []
    && count($jBun['regiuni']) === 1,
    json_encode([$jAdauga['eroare'], $jTitlu['eroare'], $jFaraJeton['eroare'], $jBun['eroare'], $jBun['plan']], JSON_UNESCAPED_UNICODE));
$listaProba = '<div class="proba-regiune-lista">' . $lista . "</div>\n";
$reguliMagazin = [['document' => 'produs:*', 'selector' => 'div.proba-regiune-lista', 'regiune' => 'proba:lista'],
    ['document' => 'sablon:comanda', 'selector' => 'div.proba-regiune-lista', 'regiune' => 'proba:lista'],
    ['document' => 'sablon:articol', 'selector' => 'div.x', 'regiune' => 'proba:lista']];
$capturaMagazin = [['cale' => '/product/ceai-proba/', 'titlu' => 'Ceai de probă', 'id' => 231, 'inCorp' => $listaProba],
    ['cale' => '/comanda-proba/', 'titlu' => 'Comanda primită', 'id' => 232, 'inCorp' => $listaProba]];
$jMagazin = $importa('magazin', [], $reguliMagazin, $capturaMagazin, ['sabloane' => ['comanda' => '/comanda-proba/']]);
$cheieProdus = 'produs:' . substr(hash('sha256', '/product/ceai-proba/'), 0, 16);
$randMagazin = array_column($jMagazin['randuri'], null, 'cheie');
$regMagazin = array_map(static fn (array $r): string => $r['document'] . ' ' . $r['regiune'] . ':' . $r['n'], $jMagazin['regiuni']);
$verifica('SPEC-A-02: o pagină capturată sub /product/<slug>/ e documentul „produs” (cu regiunea regulii produs:*), iar sabloane.comanda dă documentul '
    . '„sablon_comanda” (cheia sablon:comanda, fără cale, cu regiunea regulii sablon:comanda); o regulă sablon:articol fără șablon e doar o atenționare',
    $jMagazin['eroare'] === null && $jMagazin['plan'] === [] && ($randMagazin[$cheieProdus]['tip'] ?? '') === 'produs'
    && ($randMagazin['sablon:comanda']['tip'] ?? '') === 'sablon_comanda' && array_key_exists('cale', $randMagazin['sablon:comanda'] ?? [])
    && $randMagazin['sablon:comanda']['cale'] === null
    && in_array($cheieProdus . ' proba:lista:0', $regMagazin, true) && in_array('sablon:comanda proba:lista:0', $regMagazin, true)
    && array_filter($jMagazin['avertismente'], static fn (string $a): bool => str_contains($a, 'sablon:articol')) !== [],
    json_encode([$jMagazin['eroare'], $jMagazin['plan'], $jMagazin['avertismente'], $jMagazin['randuri'], $regMagazin], JSON_UNESCAPED_UNICODE));
$jSablonLipsa = $importa('sablon-lipsa', [], $reguliMagazin, $capturaMagazin, ['sabloane' => ['comanda' => '/comanda-inexistenta/']]);
$jProdusRefuzat = $importa('produs-refuzat', [], [$reguliMagazin[0]], $capturaMagazin, [], ['/product/ceai-proba/' => 500]);
$jProdusFaraLoc = $importa('produs-fara-loc', [], [['document' => 'produs:*', 'selector' => 'div.nu-exista', 'regiune' => 'proba:lista']], $capturaMagazin);
$verifica('SPEC-A-02: o adresă din sabloane care nu e în captură = NU; o regulă produs:* când captura are pagini de produs, dar niciuna nu iese din import = NU '
    . '(regiunea ar rămâne înghețată); un selector produs:* care nu găsește nimic în produs = NU',
    str_contains(implode(' | ', $jSablonLipsa['plan']), 'sabloane.comanda: adresa „/comanda-inexistenta/” nu e în captură')
    && str_contains(implode(' | ', $jProdusRefuzat['plan']), '(„produs:*”): niciun document importat nu e al ei')
    && str_contains(implode(' | ', $jProdusFaraLoc['plan']), 'nu găsește niciun element'),
    json_encode([$jSablonLipsa['plan'], $jProdusRefuzat['plan'], $jProdusFaraLoc['plan']], JSON_UNESCAPED_UNICODE));
$docLista = CadruCapturat::document($db, pagina: $paginiId['pagina:202']);
$docAcasa = CadruCapturat::document($db, pagina: $paginiId['pagina:201']);
$pbSalvare = CadruCapturat::problemeContinut($db, (array) $docAcasa, '<p>x</p>{{ax:regiune:proba:lista:0}}');
$verifica('salvarea din editor: o regiune nedeclarată pentru pagină e refuzată (pagina ar da 500); pe pagina ei, codul cu regiunea trece',
    count($pbSalvare) === 1 && str_contains($pbSalvare[0], 'regiune nedeclarată') && CadruCapturat::problemeContinut($db, (array) $docLista, $tl->continut) === [],
    implode(' | ', $pbSalvare));
$p1 = CadruCapturat::randeazaRand($db, (array) $docLista, ScriereCapturata::cerereImport((array) $docLista), $runtime);
$verifica('P1 (fără context de cerere): octeții capturați ai regiunii, fără să ruleze randatorul', str_contains($p1, '<div class="proba-regiune-lista">' . $lista . '</div>'));
// SPEC-A-08: un rând cu jetonul unei regiuni în atributele lui <body> (de pildă scris înaintea regulii noi a tăierii) se randează din
// rândurile lui; înainte, randeazaRand nu căuta jetonul acolo, iar pagina dădea „regiune nedeclarată”.
$docBody = (array) $docLista;
$docBody['body_atribute'] = ' class="{{ax:regiune:proba:lista:0}}"';
$eroareBody = '';
try {
    $p1Body = CadruCapturat::randeazaRand($db, $docBody, ScriereCapturata::cerereImport($docBody), $runtime, '<p>gol</p>');
} catch (\App\Support\CadruCapturat\EroareJeton $e) {
    $p1Body = '';
    $eroareBody = $e->getMessage();
}
$verifica('SPEC-A-08: randeazaRand caută jetoanele regiunilor și în atributele lui <html> și <body> (rândurile lor se citesc, nicio „regiune nedeclarată”)',
    $eroareBody === '' && str_contains($p1Body, '<body class="' . $lista . '"'), $eroareBody);

// INERT-2: o bază cu tabelele capturii (nucleu/0070), dar fără regiuni_capturate (codul nou, baza încă nemigrată): capturile și
// conflictele lor rămân vizibile, iar mesajul numește nucleu/0072 și comanda.
$dupaOriginal = (string) $db->query("SELECT dupa FROM pagini_capturate WHERE cheie = 'pagina:201'")->fetchColumn();
$db->exec('RENAME TABLE regiuni_capturate TO regiuni_capturate_lipsa');
$disponibileFara = [ScriereCapturata::disponibil($db), ScriereCapturata::disponibilRegiuni($db)];
$lipsaFara = ScriereCapturata::lipsa($db);
$conflicteFara = ScriereCapturata::conflicte($db);
$db->prepare("UPDATE pagini_capturate SET dupa = ? WHERE cheie = 'pagina:201'")->execute([$dupaOriginal . '<!-- editat -->']);
$conflicteEditat = ScriereCapturata::conflicte($db);
$db->prepare("UPDATE pagini_capturate SET dupa = ? WHERE cheie = 'pagina:201'")->execute([$dupaOriginal]);
$db->exec('RENAME TABLE regiuni_capturate_lipsa TO regiuni_capturate');
$verifica('INERT-2: fără regiuni_capturate, tabelele capturii rămân disponibile (disponibil()), conflictele documentelor capturate se văd, iar lipsa() numește '
    . 'nucleu/0072 și „php scripts/migreaza.php --aplica”; cu tabelul, lipsa() = null',
    $disponibileFara === [true, false] && $lipsaFara !== null && str_contains($lipsaFara, 'nucleu/0072') && str_contains($lipsaFara, 'php scripts/migreaza.php --aplica')
    && $conflicteFara === [] && count($conflicteEditat) === 1 && ($conflicteEditat[0]['cheie'] ?? '') === 'pagina:201' && ScriereCapturata::lipsa($db) === null,
    json_encode([$disponibileFara, $lipsaFara, count($conflicteFara), $conflicteEditat], JSON_UNESCAPED_UNICODE));

// SEC-A3, în proces: cât randează un randator „pagina”, $_SESSION e un tablou gol desprins de sesiune; după, la loc întocmai (și lipsa lui).
$ctxS = static fn (string $id): ContextRegiune => (new ContextRegiune($db, 'proba', '/x/', [], 'GET'))->pentru($id, 0, Regiuni::cache($id));
$_SESSION = ['proba_seif' => 'in-proces', 'alt' => [1, 2]];
$vazutDirect = Regiuni::randeaza('proba:direct', $ctxS('proba:direct'));
$sesiuneDupa = $_SESSION;
$eroareScrie = '';
try {
    Regiuni::randeaza('proba:scrie', $ctxS('proba:scrie'));
} catch (\App\Support\CadruCapturat\EroareJeton $e) {
    $eroareScrie = $e->getMessage();
}
$sesiuneDupaScrie = $_SESSION;
unset($_SESSION);
$vazutFara = Regiuni::randeaza('proba:direct', $ctxS('proba:direct'));
$existaDupa = array_key_exists('_SESSION', $GLOBALS);
$verifica('SEC-A3: un randator „pagina” vede un $_SESSION gol (sesiunea pusă la loc întocmai după el, și lipsa ei); o scriere în el: EroareJeton, sesiunea neatinsă',
    $vazutDirect === '<p class="proba-direct">Nimic.</p>' && $sesiuneDupa === ['proba_seif' => 'in-proces', 'alt' => [1, 2]] && str_contains($eroareScrie, 'a scris în sesiune')
    && $sesiuneDupaScrie === $sesiuneDupa && $vazutFara === '<p class="proba-direct">Nimic.</p>' && !$existaDupa,
    json_encode([$vazutDirect, $sesiuneDupa, $eroareScrie, $sesiuneDupaScrie, $existaDupa], JSON_UNESCAPED_UNICODE));
// N6, în proces: calea și query-ul randatorilor dintr-o singură citire a adresei, ca cheia cache-ului de pagini.
$verifica('N6: CadruCapturat::cerereCitita dă calea și query-ul adresei; o adresă fără cale citită de parse_url (/x:1/…) sau cu o gazdă fără schemă (//cos/…): '
    . 'calea dată, niciun parametru',
    CadruCapturat::cerereCitita('/lista/?lista=1&b[]=2', '/x/') === ['/lista/', ['lista' => '1', 'b' => ['2']]]
    && CadruCapturat::cerereCitita('/x:1/?lista=1', '/') === ['/', []] && CadruCapturat::cerereCitita('/x:1/', '/') === ['/', []]
    && CadruCapturat::cerereCitita('//cos/?lista=1', '/') === ['/', []] && CadruCapturat::cerereCitita('http://127.0.0.1/lista/?a=1', '/x/') === ['/lista/', ['a' => '1']]
    && CadruCapturat::cerereCitita('/lista/', '/x/') === ['/lista/', []] && CadruCapturat::cerereCitita('', '/') === ['/', []]);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 3. Serverul cu magazin_wc: dus-întors, cache-ul, datele în pagină, CSP-ul, rutele și cheile noi');
$db->exec("INSERT INTO settings (`key`, `value`) VALUES ('cache_pages_enabled', '1'), ('cache_pages_rules_json', '[{\"path_pattern\":\"/*\",\"ttl_seconds\":600}]')
    ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
$temaWc = ['cadru' => 'capturat', 'runtime' => 'divi4@1', 'runtime_seturi' => ['comportament', 'magazin_wc', 'calendar']];
file_put_contents($lucru . '/pachet/tema/tema.json', json_encode($temaWc));
file_put_contents($lucru . '/pachet-fara/tema/tema.json', json_encode(['cadru' => 'capturat', 'runtime' => 'divi4@1']));
foreach (['pachet', 'pachet-fara'] as $pa) {
    file_put_contents($lucru . '/' . $pa . '/redirecturi.json', '{"exacte": [], "prefixe": []}');
    file_put_contents($lucru . '/' . $pa . '/seo.json', '{}');
}
file_put_contents($lucru . '/pachet/wp-compat.json', '{"slash_final": true, "majuscule": "200", "antete_404": "wordpress", "magazin_wc": true, "mailpoet": true}');
file_put_contents($lucru . '/pachet-fara/wp-compat.json', '{"slash_final": true, "majuscule": "200", "antete_404": "wordpress"}');
exec('env CMS_DEV_LICENTA_DOSAR=' . escapeshellarg($lucru . '/licenta') . ' ' . escapeshellarg($radacina . '/tools/dev/php.sh') . ' '
    . escapeshellarg($radacina . '/tools/dev/licenta.php') . ' ateliere statistici=oprit 2>&1', $outL, $codL);
// Statisticile oprite: cu ele, răspunsul web are în plus scriptul lor și ax_statistici (G-capturat, 13), deci dus-întorsul de mai jos
// s-ar compara cu altceva decât T.
$verifica('licența de dezvoltare „ateliere” (config/plugins.php → licente_dev) se scrie (aici cu statisticile oprite)', $codL === 0, implode(' ', $outL));
$port = (int) (getenv('CMS_DEV_PORT') ?: 8701) + 35;
$portFara = $port + 1;
$verifica('serverul cu magazin_wc pornește', $pornesteServer($port, $lucru . '/pachet', $lucru . '/site'));
DateSite::reseteaza($lucru . '/pachet');
Tema::reseteaza();
WpCompat::reseteaza();
$runtimeWc = CadruCapturat::runtimeSite();
$asteptat = static fn (string $cale): string => CadruCapturat::foiTarziiCaFoi(ScriereCapturata::asteptat($taieri[$cale], $runtimeWc));
$egale = static function (string $a, string $b) use ($norm): ?int {
    $d = Normalizare::primaDiferenta($norm->document($a), $norm->document($b));
    return $d === null ? null : $d['octet'];
};
$rL = $cerere($port, '/lista/');
$dL = $egale($rL['corp'], $asteptat('/lista/'));
$verifica('dus-întors: pagina cu regiunea „pagina” = T cu octeții capturați ai regiunii (randatorul viu dă aceiași octeți), cu nonce-urile normalizate',
    $rL['cod'] === 200 && $dL === null && str_contains($rL['corp'], '<div class="proba-regiune-lista">' . $lista . '</div>'),
    $rL['cod'] . ' diferența la ' . ($dL ?? '-') . ': „' . substr($norm->document($rL['corp']), max(0, (int) $dL - 60), 160) . '”');
$verifica('seturile runtime-ului din tema.json (runtime_seturi): modulele lor la sfârșitul slotului final, în ordinea manifestului',
    str_contains($rL['corp'], '<script src="/assets/compat/comun/v1/comutatoare.js?v=') && str_contains($rL['corp'], '<script src="/assets/compat/wc/v1/stripe-oc.js?v=')
    && strpos($rL['corp'], 'comun/v1/consimtamant.js') < strpos($rL['corp'], 'comun/v1/comutatoare.js')
    && strpos($rL['corp'], 'wc/v1/cos-plutitor.js') < strpos($rL['corp'], 'wcpc/v1/calendar.js'));
$rL1 = $cerere($port, '/lista/?lista=1');
$verifica('conținutul viu: cu ?lista=1 randatorul dă doar primul produs (regiunea nu e înghețată)', $rL1['cod'] === 200
    && str_contains($rL1['corp'], '<div class="proba-regiune-lista">' . "\n" . '<ul class="proba-lista"><li data-product_id="7">Ceai de probă &amp; miere — 12,50 lei</li></ul>' . "\n" . '</div>'));
$cacheDir = $lucru . '/site/storage/cache/pages';
$fisiereCache = static fn (): array => glob($cacheDir . '/*.html') ?: [];
$inainteCache = $fisiereCache();
$rC1 = $cerere($port, '/sesiune-proba/');
$rC2 = $cerere($port, '/sesiune-proba/');
$verifica('o regiune „sesiune”: răspunsul Cache-Control: no-store, private, X-Page-Cache: BYPASS de fiecare dată, niciun fișier nou în cache-ul de pagini',
    $rC1['cod'] === 200 && ($rC1['antete']['cache-control'] ?? '') === 'no-store, private' && ($rC1['antete']['x-page-cache'] ?? '') === 'BYPASS'
    && ($rC2['antete']['x-page-cache'] ?? '') === 'BYPASS' && $fisiereCache() === $inainteCache
    && str_contains($rC1['corp'], '<div class="proba-regiune-cos et_pb_wc_cart_proba proba-cos-gol"><p class="proba-cos">Coșul este gol.</p>'),
    json_encode([$rC1['antete'], count($fisiereCache()), count($inainteCache)]));
$rP1 = $cerere($port, '/calendar-proba/');
$rP2 = $cerere($port, '/calendar-proba/');
$verifica('o pagină doar cu regiuni „pagina” intră în cache-ul de pagini (MISS, apoi HIT), fără nimic din sesiune', ($rP1['antete']['x-page-cache'] ?? '') === 'MISS'
    && ($rP2['antete']['x-page-cache'] ?? '') === 'HIT' && count($fisiereCache()) === count($inainteCache) + 1 && $rP2['corp'] === $rP1['corp'],
    json_encode([$rP1['antete']['x-page-cache'] ?? null, $rP2['antete']['x-page-cache'] ?? null, count($fisiereCache()), count($inainteCache)]));
$verifica('regiunea „atribut”: valoarea lui data-events din randator, între ghilimelele capturate', str_contains($rP1['corp'], 'data-events="' . $evenimente . '"'));
$rRau = $cerere($port, '/calendar-proba/?rau=1');
$verifica('o valoare de atribut cu ghilimele de la randator: 500, fără nimic din pagină', $rRau['cod'] === 500 && !str_contains($rRau['corp'], 'proba-calendar'), (string) $rRau['cod']);
$numarInainte = count($fisiereCache());
$rI = $cerere($port, '/indiscret/');
$verifica('un randator „pagina” care citește sesiunea: 500, nimic din pagină, nimic în cache (niciun document din cache cu date de sesiune)',
    $rI['cod'] === 500 && !str_contains($rI['corp'], 'proba-indiscret') && count($fisiereCache()) === $numarInainte, $rI['cod'] . ' ' . substr($rI['corp'], 0, 200));
$rE = $cerere($port, '/ecou/?t=' . rawurlencode('{{ax:an}} și {{ax:regiune:proba:lista:0}}'));
$verifica('„{{ax:” din datele cererii rămâne text: ieșirea randatorului nu mai trece prin jetoane (nici anul, nici regiunea nu se pun), 200',
    $rE['cod'] === 200 && str_contains($rE['corp'], '<p class="proba-ecou">{{ax:an}} și {{ax:regiune:proba:lista:0}}</p>') && !str_contains($rE['corp'], '<p class="proba-ecou">' . $an),
    $rE['cod'] . ' ' . substr($rE['corp'], (int) strpos($rE['corp'], 'proba-ecou') - 20, 200));
$rK = $cerere($port, '/cont-proba/');
$rKo = $cerere($port, '/cont-proba/?endpoint=orders');
preg_match('/<body class="([^"]*)"/', $rK['corp'], $bk);
preg_match('/<body class="([^"]*)"/', $rKo['corp'], $bko);
$verifica('{{ax:clase_body}} și {{ax:titlu}}: capturate fără o cerere a regiunii; cu ea, clasele din lista închisă adăugate la sfârșit și titlul scăpat',
    str_starts_with($bk[1] ?? '', 'page-template-default page page-id-206') && !str_contains($bk[1] ?? '', 'logged-in')
    && str_contains($rK['corp'], '<h1 class="proba-titlu">Contul meu</h1>') && ($bko[1] ?? '') === ($bk[1] ?? '') . ' logged-in woocommerce-orders'
    && str_contains($rKo['corp'], '<h1 class="proba-titlu">Comenzi &lt;&amp;&gt;</h1>') && str_contains($rKo['corp'], '<div class="proba-cont">Comenzi</div>'),
    ($bk[1] ?? '?') . ' | ' . ($bko[1] ?? '?'));
$rKt = $cerere($port, '/cont-proba/?endpoint=orders&titlu=' . rawurlencode('{{ax:an}} {{AX:an}} {{ax:regiune:proba:lista:0}}'));
$verifica('SPEC-A-06: un titlu cerut de o regiune cu „{{ax:an}}”, „{{AX:an}}” și jetonul unei regiuni: 200, totul rămâne text (&#123;), nici anul, nici regiunea nu se pun',
    $rKt['cod'] === 200 && str_contains($rKt['corp'], '<h1 class="proba-titlu">&#123;&#123;ax:an}} &#123;&#123;AX:an}} &#123;&#123;ax:regiune:proba:lista:0}}</h1>'),
    $rKt['cod'] . ' ' . substr($rKt['corp'], max(0, (int) strpos($rKt['corp'], 'proba-titlu') - 20), 200));
// SEC-A5: regiunea din <script type="application/ld+json">: ieșirea lui Regiuni::jsonScript, fără „<”; un „<” de la randator = 500.
$rJ = $cerere($port, '/jsonld-proba/');
$rJRau = $cerere($port, '/jsonld-proba/?rau=1');
$verifica('SEC-A5: JSON-LD-ul din regiune, codat (\\u003C/script\\u003E), elementul închis o singură dată; un JSON cu „<” de la randator: 500, nimic din pagină',
    $rJ['cod'] === 200 && str_contains($rJ['corp'], '<script type="application/ld+json" class="proba-jsonld">' . Regiuni::jsonScript(RegiuneProbaJsonLd::PRODUS) . '</script>')
    && $rJRau['cod'] === 500 && !str_contains($rJRau['corp'], 'ieșit din script') && !str_contains($rJRau['corp'], 'proba-jsonld'),
    $rJ['cod'] . ' / ' . $rJRau['cod']);
$exceptieClasa = null;
try {
    (new ContextRegiune(null, 'x', '/'))->adaugaClasaBody('admin-bar');
} catch (InvalidArgumentException $e) {
    $exceptieClasa = $e;
}
$exceptieSesiune = null;
try {
    (new ContextRegiune(null, 'x', '/'))->pentru('proba:lista', 0, 'pagina')->sesiune();
} catch (LogicException $e) {
    $exceptieSesiune = $e;
}
$verifica('ContextRegiune: o clasă a corpului din afara listei închise e refuzată; sesiunea e refuzată unei regiuni „pagina”',
    $exceptieClasa !== null && $exceptieSesiune !== null && (new ContextRegiune(null, 'x', '/'))->pentru('proba:cos', 0, 'sesiune')->sesiune() === []);

// SEC-A3: un randator „pagina” care citește direct din $_SESSION (contractul încălcat, ca de un randator scris greșit) nu vede sesiunea,
// iar documentul cu regiuni randat lângă o sesiune pornită pleacă no-store, private, oricare ar fi Cache-Control-ul capturat (aici
// „max-age=0, public”): secretul unui vizitator nu ajunge nici în pagina lui, nici, prin cache-ul de pagini, la altcineva.
$rSeif = $cerere($port, '/seif-proba/?pune=1');
$cookieSesiune = preg_match('/^([A-Za-z0-9_-]+=[A-Za-z0-9,-]+)/', (string) ($rSeif['antete']['set-cookie'] ?? ''), $mc) === 1 ? $mc[1] : '';
$rSeifVazut = $cerere($port, '/seif-proba/', 'GET', ['Cookie: ' . $cookieSesiune]);
$numarInainte = count($fisiereCache());
$rVictima = $cerere($port, '/direct-proba/', 'GET', ['Cookie: ' . $cookieSesiune]);
$numarDupaVictima = count($fisiereCache());
$rAnonim = [$cerere($port, '/direct-proba/'), $cerere($port, '/direct-proba/')];
$verifica('SEC-A3: sesiunea vizitatorului are secretul (regiunea „sesiune” îl arată); randatorul „pagina” care citește direct $_SESSION vede un tablou gol; '
    . 'pagina lui, cu sesiunea pornită: Cache-Control: no-store, private (nu cel capturat), nicio intrare; fără cookie: MISS, apoi HIT, fără secret',
    $rSeif['cod'] === 200 && $cookieSesiune !== '' && str_contains($rSeif['corp'], '<p class="proba-seif">Pus.</p>')
    && str_contains($rSeifVazut['corp'], '<p class="proba-seif">' . RegiuneProbaSeif::SECRET . '</p>')
    && $rVictima['cod'] === 200 && str_contains($rVictima['corp'], '<p class="proba-direct">Nimic.</p>') && !str_contains($rVictima['corp'], RegiuneProbaSeif::SECRET)
    && ($rVictima['antete']['cache-control'] ?? '') === 'no-store, private' && $numarDupaVictima === $numarInainte
    && ($rAnonim[0]['antete']['x-page-cache'] ?? '') === 'MISS' && ($rAnonim[1]['antete']['x-page-cache'] ?? '') === 'HIT'
    && count($fisiereCache()) === $numarInainte + 1
    && !str_contains($rAnonim[0]['corp'] . $rAnonim[1]['corp'], RegiuneProbaSeif::SECRET),
    json_encode([$rSeif['cod'], $cookieSesiune !== '', $rVictima['cod'], $rVictima['antete']['cache-control'] ?? '-', $numarInainte, $numarDupaVictima,
        $rAnonim[0]['antete']['x-page-cache'] ?? '-', $rAnonim[1]['antete']['x-page-cache'] ?? '-', $rAnonim[0]['antete']['cache-control'] ?? '-', count($fisiereCache()),
        str_contains($rVictima['corp'] . $rAnonim[0]['corp'] . $rAnonim[1]['corp'], RegiuneProbaSeif::SECRET)]));
$numarInainte = count($fisiereCache());
$rScrie = $cerere($port, '/scrie-proba/');
$rScrieCuSesiune = $cerere($port, '/scrie-proba/', 'GET', ['Cookie: ' . $cookieSesiune]);
$rSeifDupa = $cerere($port, '/seif-proba/', 'GET', ['Cookie: ' . $cookieSesiune]);
$verifica('SEC-A3: un randator „pagina” care scrie în $_SESSION: 500, nimic din pagină, nimic în cache, nicio sesiune pornită (niciun cookie); cu o sesiune '
    . 'pornită, tot 500, iar sesiunea vizitatorului rămâne întocmai (secretul la locul lui)',
    $rScrie['cod'] === 500 && !str_contains($rScrie['corp'], 'proba-scrie') && !isset($rScrie['antete']['set-cookie']) && $rScrieCuSesiune['cod'] === 500
    && count($fisiereCache()) === $numarInainte && str_contains($rSeifDupa['corp'], '<p class="proba-seif">' . RegiuneProbaSeif::SECRET . '</p>'),
    json_encode([$rScrie['cod'], $rScrie['antete']['set-cookie'] ?? null, $rScrieCuSesiune['cod'], count($fisiereCache()), $numarInainte]));

// N6 (SEC-A1; docs/SECURITATE.md, „Adresele din care parse_url nu scoate o cale”): o adresă din care parse_url nu scoate o cale nu e
// prima pagină. Înainte, „/x:1/?lista=1” se randa ca prima pagină (cu $_GET-ul brut în randatori) și se scria sub cheia ei, iar
// „/x:1/?add-to-cart=7” trecea de cheia de pe „/”. Acum: 404-ul capturat, no-store, nicio intrare; prima pagină din cache rămâne a ei.
$rAcasa = [$cerere($port, '/'), $cerere($port, '/')];
$numarInainte = count($fisiereCache());
$reN6 = [];
$corp404 = '';
foreach (['/x:1/', '/x:1/?q=1', '/x:1/?lista=1', '/x:1/?add-to-cart=7', '/lista:1/?lista=1', '//cos?lista=1'] as $c) {
    $r = $cerere($port, $c);
    $corp404 = $c === '/x:1/' ? $r['corp'] : $corp404;
    if ($r['cod'] !== 404 || !str_contains((string) ($r['antete']['cache-control'] ?? ''), 'no-store') || isset($r['antete']['x-page-cache'])
        || !str_contains($r['corp'], 'Pagina nu a fost găsită') || str_contains($r['corp'], 'proba-lista') || count($fisiereCache()) !== $numarInainte) {
        $reN6[] = $c . ' → ' . $r['cod'] . ' ' . ($r['antete']['cache-control'] ?? '-') . ' ' . ($r['antete']['x-page-cache'] ?? '-') . ', ' . count($fisiereCache());
    }
}
$rAcasaDupa = $cerere($port, '/');
$nonceSubsol = static fn (string $corp): string => preg_match('/name="_wpnonce-et-pb-contact-form-submitted-0" value="([^"]*)"/', $corp, $mn) === 1 ? $mn[1] : '';
$verifica('N6: /x:1/, /x:1/?q=1, /x:1/?lista=1, /x:1/?add-to-cart=7, /lista:1/?lista=1, //cos?lista=1: 404-ul capturat, no-store, nicio intrare în cache; '
    . 'prima pagină de după: HIT, octet cu octet cea de dinainte; formularul subsolului din 404 spre „/”, cu nonce-ul căii „/” (al primei pagini)',
    $reN6 === [] && ($rAcasa[1]['antete']['x-page-cache'] ?? '') === 'HIT' && ($rAcasaDupa['antete']['x-page-cache'] ?? '') === 'HIT'
    && $rAcasaDupa['corp'] === $rAcasa[0]['corp'] && str_contains($corp404, 'method="post" action="/"')
    && $nonceSubsol($corp404) !== '' && $nonceSubsol($corp404) === $nonceSubsol($rAcasa[0]['corp']),
    implode('; ', $reN6) . ' | ' . json_encode([$rAcasa[1]['antete']['x-page-cache'] ?? '-', $rAcasaDupa['antete']['x-page-cache'] ?? '-',
        $nonceSubsol($corp404), $nonceSubsol($rAcasa[0]['corp'])]));

// CSP: neschimbat pe căile din afara magazinului; sursele Stripe doar pe checkout și pe metodele de plată.
$politicaSite = CspSite::politica(false, Tema::csp(), Urmarire::csp());
$politicaStripe = CspSite::politica(false, Tema::csp(), Urmarire::csp(), 'stripe');
$dif = [];
foreach (['/', '/lista/', '/sesiune-proba/', '/product/ceai/', '/cos/', '/my-account/', '/my-account/orders/', '/nu-exista/', '/checkoutx/'] as $c) {
    $r = $cerere($port, $c);
    if (($r['antete']['content-security-policy'] ?? '') !== $politicaSite) {
        $dif[] = $c . ' (' . $r['cod'] . ')';
    }
}
$verifica('CSP pe căile din afara checkout-ului și a metodelor de plată: politica site-ului, neschimbată (fără nicio sursă Stripe)', $dif === []
    && !str_contains($politicaSite, 'stripe'), implode(', ', $dif));
$difS = [];
foreach (['/checkout/', '/checkout/order-received/5/', '/my-account/payment-methods/', '/my-account/add-payment-method/', '/Checkout/'] as $c) {
    $r = $cerere($port, $c);
    if (($r['antete']['content-security-policy'] ?? '') !== $politicaStripe) {
        $difS[] = $c . ' (' . $r['cod'] . ': ' . ($r['antete']['content-security-policy'] ?? '-') . ')';
    }
}
$verifica('CSP pe checkout și pe metodele de plată: sursele documentate ale Stripe.js (script-src și frame-src js.stripe.com și *.js.stripe.com, frame-src hooks.stripe.com, '
    . 'connect-src api.stripe.com și merchant-ui-api.stripe.com), restul politicii la fel', $difS === []
    && str_contains($politicaStripe, "script-src 'self' 'inline-speculation-rules' https://js.stripe.com https://*.js.stripe.com;")
    && str_contains($politicaStripe, 'frame-src https://www.google.com/maps/embed https://js.stripe.com https://*.js.stripe.com https://hooks.stripe.com')
    && str_contains($politicaStripe, 'https://hooks.stripe.com') && str_contains($politicaStripe, "connect-src 'self' https://api.stripe.com https://merchant-ui-api.stripe.com;")
    && !str_contains($politicaStripe, 'unsafe-eval') && !preg_match("/script-src[^;]*'unsafe-inline'/", $politicaStripe)
    && str_replace([' https://js.stripe.com', ' https://*.js.stripe.com', ' https://hooks.stripe.com', ' https://api.stripe.com', ' https://merchant-ui-api.stripe.com'], '',
        $politicaStripe) === $politicaSite,
    implode(' | ', $difS));

// Fiecare rută nouă (condiția magazin_wc) răspunde 404 cât pista ei nu e construită; sub /api/ în JSON, fără gardă (proprietarii sunt pe licență).
// Cache-Control-ul JSON-ului scurt, exact, după pachet (IG-04): `no-store, private`, iar pe un pachet cu antete_404 = wordpress (ca
// ateliere și ca pachetul de aici) antetele nocache ale WordPress, puse de stratul de adrese peste el.
$wpCompatPachet = (array) json_decode((string) file_get_contents($lucru . '/pachet/wp-compat.json'), true);
$cacheJsonAsteptat = ($wpCompatPachet['antete_404'] ?? null) === 'wordpress' ? 'no-cache, must-revalidate, max-age=0, no-store, private' : 'no-store, private';
$ruter = new Router();
(require $radacina . '/src/Http/rute.php')($ruter);
$noi = array_values(array_filter($ruter->rute(), static fn (array $r): bool => $r['conditie'] === 'magazin_wc'));
// 404-ul capturat al unei adrese oarecare, cu adresa ei pusă în locul celei cerute (și în forma scăpată din JSON-LD, „\/”).
$r404 = $cerere($port, '/nu-exista/');
$ca404 = static fn (array $r404, string $cale): string => str_replace(['/nu-exista/', '\/nu-exista\/'], [$cale, str_replace('/', '\/', $cale)], $r404['corp']);
$rele = [];
foreach ($noi as $ruta) {
    $c = (string) preg_replace('/\{[^}]*\*\}/', 'orders', str_replace('\\.', '.', (string) $ruta['tipar']));
    $c = (string) preg_replace('/\{[^}]+\}/', 'proba', $c) . (str_ends_with($ruta['tipar'], '\\.js') ? '' : '/');
    $r = $cerere($port, $c, $ruta['metoda']);
    if (str_starts_with($c, '/api/') || str_starts_with($c, '/admin/')) {
        $j = json_decode($r['corp'], true);
        $bun = is_array($j) && ($j['ok'] ?? null) === false && str_starts_with($r['antete']['content-type'] ?? '', 'application/json')
            && ($r['antete']['cache-control'] ?? '') === $cacheJsonAsteptat;
    } else {
        $bun = $egale($r['corp'], $ca404($r404, $c)) === null;
    }
    if ($r['cod'] !== 404 || isset($r['antete']['x-andaxi-garda']) || !$bun) {
        $rele[] = $ruta['metoda'] . ' ' . $c . ' → ' . $r['cod'] . ' ' . ($r['antete']['content-type'] ?? '-') . ' ' . ($r['antete']['cache-control'] ?? '-') . ' ' . substr($r['corp'], 0, 120);
    }
}
$verifica(count($noi) . ' rute noi (doar cu magazin_wc): fiecare 404 cât pista ei nu e construită (pagina 404 capturată; sub /api/ și la rambursarea din admin JSON-ul scurt, '
    . 'cu Cache-Control exact „' . $cacheJsonAsteptat . '”), niciuna oprită de gardă', count($noi) === 21 && $rele === [], implode(', ', $rele));
// SPEC-A-04: ruta abonării (condiția mailpoet) și Stripe.js-ul fals al testelor (doar cu APP_ENV=local, doar cu fișierul lui).
$rAbonare = $cerere($port, '/_andaxi/abonare/1/', 'POST');
$fisierStripeFals = $radacina . '/tools/dev/lib/stripe-js-fals.js';
$rStripeFaraFisier = is_file($fisierStripeFals) ? null : $cerere($port, '/_dev/stripe-js-fals.js');
$stripeFalsDeProba = !is_file($fisierStripeFals) && file_put_contents($fisierStripeFals, "/* proba G-regiuni */\n") !== false;
$rStripeFals = $cerere($port, '/_dev/stripe-js-fals.js');
if ($stripeFalsDeProba && is_file($fisierStripeFals)) {
    unlink($fisierStripeFals);
}
$verifica('ruta abonării (doar cu mailpoet): 404 în JSON cât pista E nu e construită; /_dev/stripe-js-fals.js: 404 fără fișier, fișierul (text/javascript, no-store, nosniff) cu el și APP_ENV=local',
    count(array_filter($ruter->rute(), static fn (array $r): bool => $r['conditie'] === 'mailpoet')) === 1 && $rAbonare['cod'] === 404
    && str_starts_with($rAbonare['antete']['content-type'] ?? '', 'application/json') && ($rAbonare['antete']['cache-control'] ?? '') === $cacheJsonAsteptat
    && ($rStripeFaraFisier === null || $rStripeFaraFisier['cod'] === 404) && $rStripeFals['cod'] === 200
    && str_starts_with($rStripeFals['antete']['content-type'] ?? '', 'text/javascript') && ($rStripeFals['antete']['cache-control'] ?? '') === 'no-store'
    && ($rStripeFals['antete']['x-content-type-options'] ?? '') === 'nosniff' && (!$stripeFalsDeProba || $rStripeFals['corp'] === "/* proba G-regiuni */\n"),
    json_encode([$rAbonare['cod'], $rStripeFaraFisier['cod'] ?? null, $rStripeFals['cod'], $rStripeFals['antete']['content-type'] ?? null]));
// SPEC-A-01 (decizia D5): pe un site cu magazin_wc, rutele magazinului și ale conturilor CMS-ului nu există (condiția fara_magazin_wc):
// GET = 404-ul capturat al adresei (ca o adresă care nu există), POST = 404-ul routerului; niciuna nu ajunge la SiteController.
$inchise = array_values(array_filter($ruter->rute(), static fn (array $r): bool => $r['conditie'] === 'fara_magazin_wc'));
$releInchise = [];
foreach ($inchise as $ruta) {
    $c = (string) preg_replace('/\{[^}]*\*\}/', 'proba/7', (string) $ruta['tipar']);
    $c = (string) preg_replace('/\{[^}]+\}/', '7', $c) . '/';
    $r = $cerere($port, $c, $ruta['metoda']);
    $bun = $ruta['metoda'] === 'GET' ? $egale($r['corp'], $ca404($r404, $c)) === null : !str_contains($r['corp'], '<html') && !isset($r['antete']['location']);
    // Garda poate răspunde ea (adresa ajunsă la pagina generală e a unui plugin pe care licența „ateliere” nu-l are: /api/fan/…, /plata/…,
    // /contul-meu/cerere-noua), tot cu 404-ul capturat: ca o adresă oarecare a acelui plugin, ca înainte.
    if ($r['cod'] !== 404 || isset($r['antete']['location']) || !$bun) {
        $dI = $ruta['metoda'] === 'GET' ? (int) $egale($r['corp'], $ca404($r404, $c)) : 0;
        $releInchise[] = $ruta['metoda'] . ' ' . $c . ' → ' . $r['cod'] . ' ' . ($r['antete']['location'] ?? '') . ' la ' . $dI . ': „'
            . substr($norm->document($r['corp']), max(0, $dI - 60), 140) . '” față de „' . substr($norm->document($ca404($r404, $c)), max(0, $dI - 60), 140) . '”';
    }
}
$gasitaCu = [];
foreach ($inchise as $ruta) {
    $c = (string) preg_replace('/\{[^}]*\*\}/', 'proba/7', (string) $ruta['tipar']);
    $g = $ruter->gaseste($ruta['metoda'], (string) preg_replace('/\{[^}]+\}/', '7', $c));
    if ($g !== null && $g['handler'] === $ruta['handler']) {
        $gasitaCu[] = $ruta['metoda'] . ' ' . $ruta['tipar'];
    }
}
$verifica(count($inchise) . ' rute ale magazinului și conturilor CMS-ului (D5): cu magazin_wc niciuna nu se potrivește (GET = 404-ul capturat, POST = 404), '
    . 'fără redirecționare (POST /checkout, /login, /register, /cos/adauga, /api/cart/…, /contul-meu/…)', count($inchise) === 61 && $releInchise === []
    && $gasitaCu === [], implode(', ', array_merge($releInchise, $gasitaCu)));
$rProd = $cerere($port, '/product/ceai/');
$dProd = $egale($rProd['corp'], $ca404($r404, '/product/ceai/'));
$verifica('pagina unei rute noi = 404-ul capturat al site-ului (același document ca o adresă care nu există)', $rProd['cod'] === 404 && $dProd === null,
    $rProd['cod'] . ' diferența la ' . ($dProd ?? '-') . ': „' . substr($norm->document($rProd['corp']), max(0, (int) $dProd - 80), 200) . '”');
$chei = [
    '/?download_file=3&order=wc_order_proba&uid=abc&key=1' => [404, 'html'], '/?wc-api=wc_stripe' => [404, 'json'],
    '/?wc-ajax=get_refreshed_fragments' => [404, 'json'], '/?mailpoet_router&endpoint=subscription&action=unsubscribe&data=proba' => [404, 'html'],
    '/?mailpoet_page=subscriptions' => [404, 'html'],
];
$releChei = [];
foreach ($chei as $c => [$cod, $fel]) {
    $r = $cerere($port, $c);
    if ($r['cod'] !== $cod || ($fel === 'json') !== str_starts_with($r['antete']['content-type'] ?? '', 'application/json') || isset($r['antete']['x-andaxi-garda'])) {
        $releChei[] = $c . ' → ' . $r['cod'] . ' ' . ($r['antete']['content-type'] ?? '');
    }
}
$rPost = $cerere($port, '/?wc-api=wc_stripe', 'POST');
$verifica('cheile WordPress de pe / (CompatWp\\Interogari): fiecare 404 cât pista ei nu e construită (wc-api și wc-ajax în JSON), și ca POST', $releChei === []
    && $rPost['cod'] === 404, implode(', ', $releChei));
$rAcasa = $cerere($port, '/');
$rAltceva = $cerere($port, '/?altceva=1');
$rPeAltaCale = $cerere($port, '/lista/?add-to-cart=7');
$rAdaugaAcasa = $cerere($port, '/?add-to-cart=7');
$rAltaCheiePeCale = $cerere($port, '/lista/?wc-api=wc_stripe');
$verifica('o cheie necunoscută trece mai departe (prima pagină); add-to-cart pe orice cale ajunge la proprietar, care, până la pista G, lasă pagina cerută '
    . '(200, ca o adăugare refuzată pe live); celelalte chei, pe altă cale decât /, nu sunt ale dispecerului',
    $rAltceva['cod'] === 200 && $egale($rAltceva['corp'], $rAcasa['corp']) === null && $rPeAltaCale['cod'] === 200
    && str_contains($rPeAltaCale['corp'], 'proba-regiune-lista') && $rAdaugaAcasa['cod'] === 200 && $egale($rAdaugaAcasa['corp'], $rAcasa['corp']) === null
    && $rAltaCheiePeCale['cod'] === 200 && str_contains($rAltaCheiePeCale['corp'], 'proba-regiune-lista'),
    json_encode([$rAltceva['cod'], $rPeAltaCale['cod'], $rAdaugaAcasa['cod'], $rAltaCheiePeCale['cod']]));
$verifica('dispecerul, fără server: cheia după ordinea fixă, pe / (add-to-cart pe orice cale, doar cu un id numeric), doar cu grupul ei pornit',
    Interogari::cheie('/?a=1', ['add-to-cart' => '7', 'wc-api' => 'x']) === 'wc-api' && Interogari::cheie('/contact/', ['add-to-cart' => '7', 'wc-api' => 'x']) === 'add-to-cart'
    && Interogari::cheie('/x/', ['add-to-cart' => '7']) === 'add-to-cart' && Interogari::cheie('/x/', ['add-to-cart' => 'abc']) === null
    && Interogari::cheie('/', ['add-to-cart' => '0']) === null && Interogari::cheie('/x/', ['add-to-cart' => ['7']]) === null
    && Interogari::cheie('/x/', ['wc-api' => 'wc_stripe']) === null && Interogari::cheie('/x:1/', ['add-to-cart' => '7']) === null
    && Interogari::cheie('/', ['necunoscut' => '1']) === null && Mod::activ() && WpCompat::mailpoet());

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 4. Același site fără magazin_wc: ca înainte');
$verifica('serverul fără magazin_wc pornește', $pornesteServer($portFara, $lucru . '/pachet-fara', $lucru . '/site-fara'));
$f404 = $cerere($portFara, '/nu-exista/');
$difFara = [];
foreach (['/product/ceai/', '/my-account/', '/my-account/orders/', '/checkout/order-received/5/', '/api/wc/jeton'] as $c) {
    $r = $cerere($portFara, $c);
    if (isset($r['antete']['x-andaxi-garda']) || $r['cod'] !== 404 || (!str_starts_with($c, '/api/') && $egale($r['corp'], $ca404($f404, $c)) !== null)) {
        $difFara[] = $c . ' (' . $r['cod'] . ')';
    }
}
$verifica('rutele noi nu există: adresele lor ajung la rutele de dinainte (pagina generală, 404-ul capturat), fără gardă', $difFara === [], implode(', ', $difFara));
$fAcasa = $cerere($portFara, '/');
$difCheiFara = [];
foreach (['/?add-to-cart=7', '/?download_file=3', '/?wc-ajax=x', '/?mailpoet_page=subscriptions'] as $c) {
    $r = $cerere($portFara, $c);
    if ($r['cod'] !== 200 || $egale($r['corp'], $fAcasa['corp']) !== null) {
        $difCheiFara[] = $c . ' (' . $r['cod'] . ')';
    }
}
$verifica('cheile WordPress de pe / trec mai departe: prima pagină, ca înainte', $difCheiFara === [], implode(', ', $difCheiFara));
$rCo = $cerere($portFara, '/checkout/');
DateSite::reseteaza($lucru . '/pachet-fara');
Tema::reseteaza();
WpCompat::reseteaza();
$verifica('fără magazin_wc nicio extensie CSP (nici pe /checkout/), iar fără runtime_seturi nicio pagină nu primește modulele seturilor',
    !str_contains((string) ($rCo['antete']['content-security-policy'] ?? ''), 'stripe') && CspSite::extensie('/checkout/') === null && !Mod::activ()
    && !str_contains($fAcasa['corp'], 'comutatoare.js') && CadruCapturat::runtimeSite() === CadruCapturat::scripturiRuntime('divi4@1'));
$schimbateFara = [];
foreach ($inchise as $ruta) {
    $c = (string) preg_replace('/\{[^}]*\*\}/', 'proba/7', (string) $ruta['tipar']);
    $g = $ruter->gaseste($ruta['metoda'], (string) preg_replace('/\{[^}]+\}/', '7', $c));
    if ($g === null || $g['handler'] !== $ruta['handler'] || $g['tipar'] !== $ruta['tipar']) {
        $schimbateFara[] = $ruta['metoda'] . ' ' . $ruta['tipar'];
    }
}
$verifica('fără magazin_wc, fiecare dintre cele ' . count($inchise) . ' rute ale magazinului și conturilor CMS-ului se potrivește ca înainte (aceeași rută, același controller)',
    $schimbateFara === [], implode(', ', $schimbateFara));
DateSite::reseteaza(null);
Tema::reseteaza();
WpCompat::reseteaza();

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 5. Înregistrările scrise o dată pentru piste');
$catalog = Plugins::catalog();
$def = static fn (string $k): array => (array) ($catalog['pluginuri'][$k] ?? []);
$verifica('pluginurile noi: descarcari (cere magazin), automatizari (cere newsletter), date_evenimente (cere catalog), cu tabelele migrărilor rezervate',
    ($def('descarcari')['necesita'] ?? null) === ['magazin'] && ($def('automatizari')['necesita'] ?? null) === ['newsletter']
    && ($def('date_evenimente')['necesita'] ?? null) === ['catalog'] && ($def('descarcari')['tabele'] ?? null) === ['produse_fisiere', 'permisiuni_descarcare', 'jurnal_descarcari']
    && in_array('produse_date', (array) ($def('date_evenimente')['tabele'] ?? []), true) && count((array) ($def('automatizari')['tabele'] ?? [])) === 5
    && in_array('regiuni_capturate', (array) $catalog['nucleu']['tabele'], true) && in_array('order_notes', (array) $def('magazin')['tabele'], true)
    && in_array('parole_setare', (array) $def('conturi_clienti')['tabele'], true) && in_array('buget_trimitere', (array) $def('newsletter')['tabele'], true));
$verifica('licența de dezvoltare „ateliere”: lista din plan (§4), fără livrare_fan și fără migrare_wordpress',
    ($catalog['licente_dev']['ateliere'] ?? null) === ['catalog', 'magazin', 'plata_stripe', 'cupoane', 'conturi_clienti', 'blog', 'newsletter', 'statistici', 'descarcari',
        'automatizari', 'date_evenimente']);
$proprietari = [];
foreach (array_merge($noi, array_values(array_filter($ruter->rute(), static fn (array $r): bool => $r['conditie'] === 'mailpoet'))) as $ruta) {
    // Pe un site cu condiția rutei (declarațiile din rute_conditionate), ca la o cerere.
    $proprietari[$ruta['metoda'] . ' ' . $ruta['tipar']] = implode('|', Plugins::proprietarRuta($ruta['metoda'], $ruta['tipar'], [], [], [], [$ruta['conditie']])['chei']);
}
$verifica('proprietarii rutelor noi, pe un site cu condiția lor: produsul al catalogului (POST al magazinului), contul al conturilor, Stripe al plății Stripe, '
    . 'rambursarea al magazinului (comenzile), paginile blogului ale blogului, abonarea a newsletterului, restul al magazinului',
    ($proprietari['GET /product/{slug}'] ?? '') === 'catalog' && ($proprietari['POST /product/{slug}'] ?? '') === 'magazin'
    && ($proprietari['GET /my-account/{endpoint*}'] ?? '') === 'conturi_clienti' && ($proprietari['POST /api/wc/stripe/{actiune}'] ?? '') === 'plata_stripe'
    && ($proprietari['GET /checkout/order-received/{comanda}'] ?? '') === 'magazin' && ($proprietari['GET /api/wc/jeton'] ?? '') === 'magazin'
    && ($proprietari['POST /api/wc/stripe/metode/{actiune*}'] ?? '') === 'plata_stripe' && ($proprietari['POST /admin/orders/{id}/rambursare-stripe'] ?? '') === 'magazin'
    && ($proprietari['GET /_dev/stripe-js-fals\\.js'] ?? '') === 'plata_stripe' && ($proprietari['GET /blog/page/{n}'] ?? '') === 'blog'
    && ($proprietari['GET /category/{slug*}/page/{n}'] ?? '') === 'blog' && ($proprietari['POST /_andaxi/abonare/{n}'] ?? '') === 'newsletter',
    json_encode($proprietari));
// INERT-1: pe un site fără magazin_wc, declarațiile rutelor condiționate nu contează: adresele lor ajung la pagina generală și rămân ale
// nucleului, ca la de5c3c9 (dinaintea pistei A): același proprietar, același link (filtreazaLinkuri nu scoate nimic), aceeași gardă
// (controllerul, nu redirecționarea din licență), pe licențele vertij, grafoanaytis, prezentare (a pensiunii) și toate.
// (`/blog/page/2/` nu e aici: e sub `/blog/*` al blogului, fără condiție, deci a blogului și înainte, și acum.)
$adreseInert = ['/product/x/', '/my-account/', '/my-account/orders/', '/checkout/order-received/5/', '/checkout/order-pay/5/', '/api/wc/x', '/category/x/page/2/',
    '/_dev/stripe-js-fals.js'];
$licenteInert = ['vertij' => (array) $catalog['licente_dev']['vertij'], 'grafoanaytis' => (array) $catalog['licente_dev']['grafoanaytis'],
    'prezentare' => (array) $catalog['pachete']['prezentare']['pluginuri'], 'toate' => Plugins::chei()];
$redirectInert = array_fill_keys(['catalog', 'magazin', 'conturi_clienti', 'plata_stripe', 'blog', 'newsletter'], '/');
$htmlInert = '<ul class="meniu"><li><a href="/product/x/">P</a></li><li><a href="/my-account/">C</a></li><li><a href="/checkout/order-received/5/">R</a></li>'
    . '<li><a href="/category/x/page/2/">B</a></li></ul>';
$releInert = [];
foreach ($licenteInert as $numeL => $listaL) {
    Plugins::folosesteStari(array_fill_keys(array_map('strval', $listaL), Plugins::PORNIT), $redirectInert);
    foreach ($adreseInert as $a) {
        $g = $ruter->gaseste('GET', $a);
        $prop = $g === null ? null : Plugins::proprietarRuta('GET', $g['tipar'], $g['parametri']);
        ob_start();
        $garda = $g === null ? null : GardaPluginuri::permite('GET', $g['tipar'], $g['parametri']);
        ob_end_clean();
        if ($g === null || $g['tipar'] !== '/{slug*}' || ($prop['chei'] ?? null) !== ['nucleu'] || !Plugins::adresaVizibila($a) || $garda !== true) {
            $releInert[] = $numeL . ' ' . $a . ' → ' . ($g['tipar'] ?? '-') . ' ' . implode('|', $prop['chei'] ?? []) . ' garda=' . var_export($garda, true);
        }
    }
    if (Plugins::filtreazaLinkuri($htmlInert) !== $htmlInert) {
        $releInert[] = $numeL . ': filtreazaLinkuri a schimbat linkurile';
    }
}
Plugins::reseteaza();
$verifica('INERT-1: fără magazin_wc, ' . count($adreseInert) . ' adrese ale rutelor condiționate, pe 4 licențe (cu redirecționări): pagina generală, a nucleului, '
    . 'linkurile neatinse, garda lasă controllerul, ca la de5c3c9', $releInert === [], implode('; ', $releInert));
// Evenimentele Stripe ale magazinului WooCommerce (§5.9.2): doar pe un site cu magazin_wc; altfel lista de dinainte.
$evenimenteNoi = ['charge.dispute.closed', 'charge.dispute.created', 'charge.refund.updated', 'charge.refunded', 'payment_intent.processing'];
Plugins::folosesteStari(array_fill_keys((array) $catalog['licente_dev']['ateliere'], Plugins::PORNIT));
$evenimenteFara = StripeEvenimente::ceruteDePluginuri();
DateSite::reseteaza($lucru . '/pachet');
Tema::reseteaza();
WpCompat::reseteaza();
$evenimenteCu = StripeEvenimente::ceruteDePluginuri();
DateSite::reseteaza(null);
Tema::reseteaza();
WpCompat::reseteaza();
Plugins::reseteaza();
$verifica('evenimentele Stripe: cu magazin_wc, cele 5 ale magazinului WooCommerce în plus (plata în curs, rambursările, disputele); fără el, lista de dinainte',
    array_values(array_diff($evenimenteCu, $evenimenteFara)) === $evenimenteNoi && array_intersect($evenimenteNoi, $evenimenteFara) === []
    && in_array('payment_intent.succeeded', $evenimenteFara, true), json_encode([$evenimenteFara, $evenimenteCu]));
DupaPlata::ruleaza($db, 1, 'webhook');
$aspect = json_decode((string) @file_get_contents($radacina . '/site-uri/ateliere/stripe-aspect.json'), true);
$verifica('locurile scrise o dată pentru plata Stripe: MagazinWc\\DupaPlata::ruleaza (nu face nimic până la pistele L și I), stripe-aspect.json în pachet (scheletul, '
    . 'cunoscut de verifica-pachet)', method_exists(DupaPlata::class, 'ruleaza') && $aspect === ['format' => 1, 'appearance' => [], 'fonts' => []]);
$manifest = json_decode((string) file_get_contents($radacina . '/public/assets/compat/divi4/v1/sloturi.json'), true);
$verifica('sloturi.json: seturile comportament, magazin_wc și calendar, cu modulele rezervate (fișiere care nu fac nimic), niciunul în cap sau final',
    array_keys((array) ($manifest['seturi'] ?? [])) === ['comportament', 'magazin_wc', 'calendar']
    && array_filter(array_merge(...array_values($manifest['seturi'])), static fn (string $c): bool => !is_file($radacina . '/public/assets/compat/' . $c)
        || in_array($c, array_merge($manifest['cap'], $manifest['final']), true)) === []);
[$tv, $tp] = Tema::valideaza(['cadru' => 'capturat', 'runtime' => 'divi4@1', 'runtime_seturi' => ['magazin_wc', 'necunoscut']], $lucru . '/pachet');
[$tv2, $tp2] = Tema::valideaza(['runtime_seturi' => ['magazin_wc']], $lucru . '/pachet');
$verifica('tema.json → runtime_seturi: un set care nu e în manifest, sau fără runtime, e o problemă (și cheia rămâne goală)',
    $tp !== [] && $tv['runtime_seturi'] === [] && $tp2 !== [] && $tv2['runtime_seturi'] === []);
$verifica('schema dialectului: data-events (JSON), data-columns, data-priority, data-product_id, data-product_sku, data-quantity, data-placeholder; atributele regiunilor data-events și class; clasele corpului închise',
    SchemaDivi4::felData('data-events') === 'json' && SchemaDivi4::felData('data-columns') === 'scalar' && SchemaDivi4::felData('data-product_id') === 'scalar'
    && SchemaDivi4::REGIUNI_ATRIBUTE === ['data-events', 'class'] && in_array('logged-in', SchemaDivi4::CLASE_BODY, true)
    && preg_match(SchemaDivi4::RE_JETON_REGIUNE, '{{ax:regiune:wc:cos_produse:0}}') === 1);
$pachetAteliere = $radacina . '/site-uri/ateliere';
$importAteliere = json_decode((string) @file_get_contents($pachetAteliere . '/import-wordpress.json'), true);
$verifica('pachetul site-uri/ateliere/: regulile regiunilor trec de validare și au doar randatori din catalog; wp-compat.json cu magazin_wc; tema cu cadrul capturat și seturile',
    is_array($importAteliere) && ImportWordPress::valideazaReguli($importAteliere) === [] && is_array($importAteliere['regiuni'] ?? null)
    && array_filter($importAteliere['regiuni'], static fn (array $r): bool => isset($r['regiune']) && !isset(Regiuni::CATALOG[$r['regiune']])) === []
    && (json_decode((string) @file_get_contents($pachetAteliere . '/wp-compat.json'), true)['magazin_wc'] ?? null) === true
    && Tema::valideaza((array) json_decode((string) @file_get_contents($pachetAteliere . '/tema/tema.json'), true), $pachetAteliere)[1] === [],
    implode('; ', is_array($importAteliere) ? ImportWordPress::valideazaReguli($importAteliere) : ['fără import-wordpress.json']));

$scrie('');
$scrie('Rezultat: ' . $trecute . ' verificări trecute, ' . $esuate . ' eșuate.');
exit($esuate === 0 ? 0 : 1);
