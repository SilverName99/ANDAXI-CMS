<?php

declare(strict_types=1);

/**
 * Poarta G-mailpoet-compat: adresele vechi ale MailPoet pe un site mutat (pista E; App\Support\MailPoetCompat, specificația mailpoet
 * §8.3), abonarea din formularele Divi (App\Support\AbonareCapturata), bugetul trimiterilor, listele automate și bifa din checkout, pe
 * datele INVENTATE ale fixturii tools/dev/fixturi-wp/mailpoet.sql, importate cu ImportWordPress\MailPoet:
 *
 *  1. în proces: codificarea `data`, regulile jetoanelor (ceasul înghețat), stările paginilor față de textele specificației (§3.4.2),
 *     dovada regiunii `mailpoet:pagina` (Regiuni::dovada, previzualizarea cadrului R), bugetul, listele automate, bifa din checkout,
 *     momentul „abonare confirmată”;
 *  2. pe un server cu `wp-compat.json` → `mailpoet` (licența de dezvoltare „ateliere”), DE DOUĂ ORI: cu pluginul newsletter pornit și
 *     oprit (routerul răspunde mereu): routerul și antetele lui, jetoanele, paginile, dezabonarea și un-clic, administrarea, confirmarea,
 *     sondajul, clicul, deschiderea, „vezi în browser”, pagina simplă, limita de 60 pe minut, formularul de abonare Divi.
 *
 *   tools/dev/php.sh tools/dev/test-mailpoet-compat.php
 *
 * Bazele (cms_mpc_wp, cms_mpc), serverul (CMS_DEV_PORT+40) și dosarul $CMS_DEV_DIR/test-mailpoet-compat/ se șterg la final. Raport:
 * $CMS_DEV_DIR/test-mailpoet-compat-raport.txt; cod 0 doar dacă totul a trecut.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$lucru = $dosarDev . '/test-mailpoet-compat';
if (!str_ends_with($lucru, '/test-mailpoet-compat')) {
    exit(2);
}
exec('rm -rf ' . escapeshellarg($lucru));
foreach (['site/storage', 'pachet/tema', 'licenta', 'wp'] as $d) {
    @mkdir($lucru . '/' . $d, 0770, true);
}
define('PLATFORMA_SITE_DIR', $lucru . '/site');
define('PLATFORMA_PUBLIC_DIR', $radacina . '/public');
// Baza aplicației din acest proces = baza testului (cheia newsletterului din setări, ca a serverului de probă).
$_ENV['DB_DATABASE'] = 'cms_mpc';
putenv('DB_DATABASE=cms_mpc');
$_ENV['LICENTA_DOSAR'] = $lucru . '/licenta';
putenv('LICENTA_DOSAR=' . $lucru . '/licenta');

require_once $radacina . '/bootstrap.php';
require_once __DIR__ . '/lib/wordpress-proba.php';
require_once __DIR__ . '/lib/captura-divi-proba.php';
// Aceeași cheie ca serverul de probă: jetoanele CMS (ax1.) și nonce-urile făcute aici trec acolo.
$_ENV['APP_KEY'] = 'cheie-de-proba-mailpoet';
putenv('APP_KEY=cheie-de-proba-mailpoet');

use App\Support\AbonareCapturata;
use App\Support\BugetTrimitere;
use App\Support\CadruCapturat;
use App\Support\CadruCapturat\ContextRegiune;
use App\Support\CadruCapturat\Regiuni;
use App\Support\DateSite;
use App\Support\Hooks;
use App\Support\ImportWordPress\Captura\DosarCapturi;
use App\Support\ImportWordPress\Captura\Normalizare;
use App\Support\ImportWordPress\ConfigWordPress;
use App\Support\ImportWordPress\Continut\Divi4;
use App\Support\ImportWordPress\Continut\Divi4Taiere;
use App\Support\ImportWordPress\Continut\Taiere;
use App\Support\ImportWordPress\MailPoet\CadreMailPoet;
use App\Support\ImportWordPress\MailPoet\PlanMailPoet;
use App\Support\ImportWordPress\MailPoet\ScriereMailPoet;
use App\Support\ImportWordPress\MailPoet\SursaMailPoet;
use App\Support\ImportWordPress\PlanCapturat;
use App\Support\ImportWordPress\ScriereCapturata;
use App\Support\ImportWordPress\SursaWordPress;
use App\Support\MailPoetCompat\Abonati;
use App\Support\MailPoetCompat\Arhiva;
use App\Support\MailPoetCompat\BugetRouter;
use App\Support\MailPoetCompat\Date;
use App\Support\MailPoetCompat\Jetoane;
use App\Support\Licenta;
use App\Support\MailPoetCompat\Pagini;
use App\Support\MailPoetCompat\RandarePagina;
use App\Support\MailPoetCompat\Shortcoduri;
use App\Support\MailPoetCompat\Sincronizare;
use App\Support\MailPoetCompat\StarePagina;
use App\Support\MailPoetCompat\Url;
use App\Support\NewsletterService;
use App\Support\Plugins;
use App\Support\Tema;
use App\Support\WpCompat;

$fisierRaport = $dosarDev . '/test-mailpoet-compat-raport.txt';
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
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . mb_substr($detalii, 0, 1200) : ''));
    }
};
$scrie('Test adresele vechi ale MailPoet și abonarea (G-mailpoet-compat) — ' . date('Y-m-d H:i:s'));

$socket = (string) (getenv('CMS_DEV_SOCK') ?: ini_get('pdo_mysql.default_socket'));
$utilizator = (string) (getenv('CMS_DEV_DB_USER') ?: 'root');
$parolaDb = (string) (getenv('CMS_DEV_DB_PAROLA') ?: '');
$root = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', $utilizator, $parolaDb, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$bazaWp = 'cms_mpc_wp';
$baza = 'cms_mpc';
$serverePornite = [];
register_shutdown_function(static function () use (&$serverePornite, $root, $bazaWp, $baza, $lucru): void {
    foreach ($serverePornite as $pid) {
        if ($pid > 0) {
            exec('pkill -TERM -P ' . (int) $pid . ' 2>/dev/null; kill ' . (int) $pid . ' 2>/dev/null');
        }
    }
    foreach (['db', 'sursa'] as $v) {
        $GLOBALS[$v] = null;
    }
    gc_collect_cycles();
    foreach ([$bazaWp, $baza] as $b) {
        try {
            $root->exec('DROP DATABASE IF EXISTS `' . $b . '`');
        } catch (Throwable) {
        }
    }
    if (str_ends_with($lucru, '/test-mailpoet-compat')) {
        exec('rm -rf ' . escapeshellarg($lucru));
    }
});
$conectare = static fn (string $b): PDO => new PDO('mysql:unix_socket=' . $socket . ';dbname=' . $b . ';charset=utf8mb4', $utilizator, $parolaDb,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== Pregătirea: baza site-ului, fixtura MailPoet importată, captura (prima pagină și o pagină cu formularul de abonare, cadrul P)');
$dosarWp = $lucru . '/wp';
wordpressProba($root, $bazaWp, $dosarWp, ['prefix' => 'wp_']);
$wp = $conectare($bazaWp);
foreach (preg_split('/;\n/', (string) file_get_contents(__DIR__ . '/fixturi-wp/mailpoet.sql')) as $instr) {
    $instr = trim((string) preg_replace('/^--.*$/m', '', $instr));
    if ($instr !== '') {
        $wp->exec($instr);
    }
}
$root->exec('DROP DATABASE IF EXISTS `' . $baza . '`');
$root->exec('CREATE DATABASE `' . $baza . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$p = proc_open([$radacina . '/tools/dev/php.sh', 'scripts/install.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, $radacina,
    array_merge(getenv(), ['CMS_DEV_DB' => $baza, 'CMS_DEV_LICENTA_DOSAR' => $lucru . '/licenta']));
$outI = (string) stream_get_contents($pipe[1]) . (string) stream_get_contents($pipe[2]);
fclose($pipe[1]);
fclose($pipe[2]);
$verifica('scripts/install.php pe baza nouă', proc_close($p) === 0, $outI);
$db = $conectare($baza);
// Ca Database::connection: ora sesiunii MySQL e ora PHP a site-ului, deci NOW() și datele scrise din PHP se pot compara (E-LEG-03).
$db->exec("SET time_zone = '" . date('P') . "'");
$url = 'https://pensiune-proba.example.test';
file_put_contents($lucru . '/pachet/wp-compat.json', '{"slash_final": true, "majuscule": "200", "antete_404": "wordpress", "mailpoet": true}');
file_put_contents($lucru . '/pachet/redirecturi.json', '{"exacte": [], "prefixe": []}');
file_put_contents($lucru . '/pachet/seo.json', '{}');
// Tema pachetului ateliere, cu un tracker inventat (declarația în tema.json, identificatorul în privat/urmarire.json): paginile
// capturate îl primesc în ax-date (`ax_urmarire`), paginile routerului nu (E-LEG-07, VC-MP-23).
$temaProba = (array) json_decode((string) file_get_contents($radacina . '/site-uri/ateliere/tema/tema.json'), true);
$temaProba['urmarire'] = [['fel' => 'gtag', 'categorie' => 'statistics', 'src' => 'https://www.googletagmanager.com/gtag/js?id={id}',
    'csp' => ['script-src' => ['https://www.googletagmanager.com']], 'cookie_retragere' => ['_ga', '_ga_*']]];
file_put_contents($lucru . '/pachet/tema/tema.json', (string) json_encode($temaProba, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
@mkdir($lucru . '/pachet/privat', 0770, true);
file_put_contents($lucru . '/pachet/privat/urmarire.json', '{"gtag": "GT-PROBA0001"}');
DateSite::reseteaza($lucru . '/pachet');
WpCompat::reseteaza();
Tema::reseteaza();
$campuriR = ['first_name' => 'form_field_' . base64_encode('0a1b2c3d4e5f_first_name'), 'last_name' => 'form_field_' . base64_encode('1b2c3d4e5f60_last_name'),
    'status' => 'form_field_' . base64_encode('2c3d4e5f6071_status'), 'segments' => 'form_field_' . base64_encode('3d4e5f607182_segments')];
$pcCadre = new PlanCapturat();
$tCadru = static fn (array $regiuni, array $valori): Taiere => new Taiere('', [], ['cap' => 0, 'final' => 0], '', '', '', '', '', '', [], $valori, [], null,
    'inghetat', null, [], [], [], [], [], $regiuni);
$formR = '';
foreach ($campuriR as $k => $v) {
    $formR .= '<input name="data[' . $v . ']' . ($k === 'segments' ? '[]' : '') . '" />';
}
$pcCadre->documente['p'] = ['valori_import' => ['cale' => '/?mailpoet_page=subscriptions'], 'taiere' => $tCadru([], [])];
$pcCadre->documente['r'] = ['valori_import' => ['cale' => '/?mailpoet_page=subscriptions&mailpoet_router&endpoint=subscription&action=manage&data=eyJwcmV2aWV3IjoxfQ'],
    'taiere' => $tCadru([['regiune' => 'mailpoet:pagina', 'n' => 0, 'octeti' => 'Administrează-ți abonamentul'], ['regiune' => 'mailpoet:pagina', 'n' => 1,
        'octeti' => '<form>' . $formR . '</form>']], ['titlu' => 'Administrează-ți abonamentul | Pensiunea Probă'])];
$sursa = new SursaMailPoet(SursaWordPress::deschide(ConfigWordPress::citeste($dosarWp)), __DIR__ . '/fixturi-wp/mailpoet-export.json');
$planMp = PlanMailPoet::fa($sursa, $pcCadre, true, ['automatizari']);
$sursa = null;
$db->beginTransaction();
ScriereMailPoet::aplica($db, $planMp);
$db->commit();
$verifica('fixtura MailPoet importată (9 abonați, 5 liste, 2 campanii)', $planMp->probleme === [] && (int) $db->query('SELECT COUNT(*) FROM newsletter_subscribers'
    . ' WHERE mailpoet_id IS NOT NULL')->fetchColumn() === 9, implode(' | ', $planMp->probleme));
$id = static fn (int $mp): int => (int) $db->query('SELECT id FROM newsletter_subscribers WHERE mailpoet_id = ' . $mp)->fetchColumn();
$ab = static fn (int $mp): array => (array) $db->query('SELECT * FROM newsletter_subscribers WHERE mailpoet_id = ' . $mp)->fetch();
$lista = static fn (int $mp): int => (int) $db->query('SELECT id FROM newsletter_lists WHERE mailpoet_id = ' . $mp)->fetchColumn();
$jeton11 = '0123456789abcdef0123456789abcdef';
$jeton17 = 'fedcba9876543210fedcba9876543217';
$jeton19 = 'fedcba9876543210fedcba9876543219';
$jeton20 = 'fedcba9876543210fedcba9876543220';

// Captura: prima pagină și o pagină de atelier, fiecare cu un formular de abonare Divi (MailPoet, listele 103 și 104), plus cadrul P.
$signup = static fn (int $lista, string $succes): string => '<div class="et_pb_module et_pb_signup_0 et_pb_newsletter_layout_left_right et_pb_newsletter et_pb_subscribe clearfix">'
    . '<div class="et_pb_newsletter_description"><h2 class="et_pb_module_header">Abonează-te</h2></div><div class="et_pb_newsletter_form"><form method="post">'
    . '<div class="et_pb_newsletter_result et_pb_newsletter_error"></div><div class="et_pb_newsletter_result et_pb_newsletter_success"><h2>' . $succes . '</h2></div>'
    . '<div class="et_pb_newsletter_fields"><p class="et_pb_newsletter_field et_pb_contact_field_last"><label class="et_pb_contact_form_label" for="et_pb_signup_email">Email</label>'
    . '<input id="et_pb_signup_email" class="input" type="text" placeholder="Email" name="et_pb_signup_email" /></p><p class="et_pb_newsletter_button_wrap">'
    . '<a class="et_pb_newsletter_button et_pb_button" href="#"><span class="et_subscribe_loader"></span><span class="et_pb_newsletter_button_text">Abonează-te</span></a></p></div>'
    . '<input type="hidden" value="mailpoet" name="et_pb_signup_provider" /><input type="hidden" value="' . $lista . '" name="et_pb_signup_list_id" />'
    . '<input type="hidden" value="Proba" name="et_pb_signup_account_name" /><input type="hidden" value="true" name="et_pb_signup_ip_address" />'
    . '<input type="hidden" value="0a1b2c3d" name="et_pb_signup_checksum" /></form></div></div>' . "\n";
$pagini = [['cale' => '/', 'titlu' => 'Acasă', 'id' => 301, 'inCorp' => $signup(103, 'Mulțumim!')],
    ['cale' => '/atelier-proba/', 'titlu' => 'Atelier', 'id' => 302, 'inCorp' => $signup(104, 'Te-ai înscris la atelier.')]];
$optiuni = ['url' => $url, 'nonce' => 'dddd333333', 'ua' => 'windows', 'an' => date('Y'), 'antet' => 31, 'subsol' => 32, 'corp404' => 33,
    'meniu' => [['/', 'Acasă']], 'cale404' => '/x3-inexistent/'];
capturaDiviProba($lucru . '/captura', $pagini, $optiuni);
// Cadrele P (pagina MailPoet) și R (previzualizarea administrării), în forma unui articol Divi (tipul `mailpoet_page`): titlul în H1-ul
// articolului, sub el linia `p.post-meta` a temei cu autorul paginii (numele afișat al contului care a instalat MailPoet, aici o adresă
// de email, cu legătura spre pagina lui de autor) și data, apoi conținutul. Pe cadrul R, regiunea mailpoet:pagina pe H1 și pe conținut
// (regulile pachetului ateliere, fără jetonul titlului: tăierea pistei A refuză încă textul lui <title>), conținutul fiind
// previzualizarea randată a administrării (dovada regiunii, la import). Importul scoate autorul din amândouă (CadreMailPoet, E-LEG-08).
$caleRCaptura = '/?mailpoet_page=subscriptions&mailpoet_router&endpoint=subscription&action=manage&data=eyJwcmV2aWV3IjoxfQ';
$liniaAutorului = '<p class="post-meta"> de <span class="author vcard"><a href="' . $url . '/author/autor-proba/" title="Articole de autor-proba@example.invalid"'
    . ' rel="author">autor-proba@example.invalid</a></span> | <span class="published">ian. 1, 2024</span></p>';
$cadruMailPoet = static function (string $cale, string $h1, string $continut) use ($optiuni, $liniaAutorului): string {
    $html = paginaDiviProba(['cale' => $cale, 'titlu' => 'MailPoet Page', 'id' => 303], $optiuni);
    $articol = "<article id=\"post-303\" class=\"et_pb_post post-303 mailpoet_page type-mailpoet_page status-publish hentry\">\n<div class=\"et_post_meta_wrapper\">\n"
        . '<h1 class="entry-title">' . $h1 . "</h1>\n" . $liniaAutorului . "\n</div>\n<div class=\"entry-content\">" . $continut . "</div>\n</article>";
    return (string) preg_replace('#<article id="post-303" .*?</article>#s', str_replace(['\\', '$'], ['\\\\', '\\$'], $articol), $html, 1);
};
$gR = Pagini::gestionare($db, null, Abonati::DEMO_JETON, ['mailpoet_page' => 'subscriptions'], $caleRCaptura);
$adaugaInCaptura = static function (string $cale, string $corp) use ($lucru, $url): void {
    $sha = hash('sha256', $url . $cale);
    @mkdir($lucru . '/captura/brut/' . $sha, 0770, true);
    file_put_contents($lucru . '/captura/brut/' . $sha . '/chrome-win-1.corp', $corp);
    file_put_contents($lucru . '/captura/brut/' . $sha . '/chrome-win-1.antete', "content-type: text/html; charset=UTF-8\r\n");
    $index = (array) json_decode((string) file_get_contents($lucru . '/captura/index.json'), true);
    $index['adrese'][] = ['fel' => 'pagina', 'cale' => $cale, 'url' => $url . $cale, 'referinta' => 'brut/' . $sha . '/chrome-win-1.corp', 'status' => 200];
    file_put_contents($lucru . '/captura/index.json', (string) json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
};
$adaugaInCaptura('/?mailpoet_page=subscriptions', $cadruMailPoet('/?mailpoet_page=subscriptions', 'MailPoet Page', "\n<p>[mailpoet_page]</p>\n"));
$adaugaInCaptura($caleRCaptura, $cadruMailPoet($caleRCaptura, 'Administrează-ți abonamentul', $gR->interiorContinut()));
sigileazaCapturaProba($lucru . '/captura', $url);
$dosarC = DosarCapturi::deschide($lucru . '/captura');
$aruncate = [];
foreach ($dosarC->toate() as $x) {
    $r = $dosarC->raspuns($x['url']);
    foreach ($r !== null && str_contains($r->corp, '<!DOCTYPE html>') ? (new Divi4())->transforma($r->corp, $url, [])->necunoscute : [] as $n) {
        if ($n['fel'] !== 'noscript' || str_contains($n['inceput'], 'googletagmanager')) {
            $aruncate[$n['sha256']] = ['sha256' => $n['sha256'], 'motiv' => 'proba: ' . ($n['id'] ?? $n['inceput'])];
        }
    }
}
$reguli = ['continut' => 'capturat', 'dialect' => 'divi4', 'scripturi_aruncate' => array_values($aruncate), 'regiuni' => []];
// Regulile cadrului R (ale pachetului ateliere, fără jetonul titlului), doar pentru tăierea lui de mai jos.
$reguliR = array_replace($reguli, ['regiuni' => [['document' => $caleRCaptura, 'selector' => 'h1.entry-title', 'regiune' => 'mailpoet:pagina'],
    ['document' => $caleRCaptura, 'selector' => 'div.entry-content', 'regiune' => 'mailpoet:pagina']]]);
$pc = new PlanCapturat();
$paginiDoc = [];
$taieri = [];
foreach ($pagini as $pg) {
    $r = $dosarC->dupaCale($pg['cale']);
    $rd = (new Divi4($dosarC))->transforma((string) $r?->corp, $url, $reguli, $pg['cale']);
    $t = Divi4Taiere::taie($rd, ['tip' => 'pagina', 'cale' => $pg['cale'], 'seo' => null, 'an' => ['modul' => 'et_pb_text_0_tb_footer', 'an' => date('Y')],
        'regiuni' => [], 'stare' => $pc->stareCaptura($dosarC, $pg['cale'])]);
    $pc->adaugaDocument('pagina:' . $pg['id'], 'pagina', $t, ['titlu' => $pg['titlu'], 'wp_id' => $pg['id'], 'status' => 200, 'sha_captura' => (string) $r?->sha256(),
        'antete' => PlanCapturat::antete((array) $r?->antete, $url), 'valori_import' => ['cale' => $pg['cale']]]);
    $paginiDoc[$pg['cale']] = 'pagina:' . $pg['id'];
    $taieri[$pg['cale']] = $t;
}
$pc->dinCaptura($dosarC, $reguli, $paginiDoc, [$caleRCaptura], ['modul' => 'et_pb_text_0_tb_footer', 'an' => date('Y')]);
// Cadrul R, tăiat aici ca paginile (cu regulile lui), fiindcă dinCaptura îl refuză azi: schema dialectului (pista A, SchemaDivi4)
// nu știe atributele formularului de administrare al MailPoet (data-automation-id, data-parsley-*), pe care le are și captura reală.
// Până la lărgirea ei (sau la scoaterea interiorului regiunilor din verificare), importul real al cadrului R e NU; aici se verifică
// doar că acelea sunt singurele probleme, iar restul drumului (tăierea, autorul, P1, dovada regiunii, servirea) merge.
$rR = $dosarC->dupaCale($caleRCaptura);
$rdR = (new Divi4($dosarC))->transforma((string) $rR?->corp, $url, $reguliR, $caleRCaptura);
$problemeR = array_map(static fn ($pb): string => $pb->linie(), \App\Support\HtmlCapturat::verifica($rdR->html, $pc->politica, 'import'));
$doarMailPoet = array_filter($problemeR, static fn (string $l): bool => preg_match('/atributul „data-(automation-id|parsley-[a-z-]+)” la </u', $l) !== 1) === [];
$scrie('  INFO  cadrul R capturat față de schema dialectului (pista A, SchemaDivi4): ' . ($problemeR === [] ? 'primit'
    : count($problemeR) . ' atribute ale formularului MailPoet refuzate (data-automation-id, data-parsley-*): importul real al cadrului R e NU până la lărgirea schemei'));
$verifica('cadrul R: singurele probleme HtmlCapturat ale capturii sunt atributele formularului MailPoet (dependența de pista A), sau niciuna',
    $rdR->probleme === [] && $doarMailPoet, implode(' | ', array_merge($rdR->probleme, array_slice($problemeR, 0, 3))));
$tR = Divi4Taiere::taie($rdR, ['tip' => 'arhiva', 'cale' => $caleRCaptura, 'an' => ['modul' => 'et_pb_text_0_tb_footer', 'an' => date('Y')],
    'regiuni' => PlanCapturat::regiuniDocument($reguliR, 'arhiva', $caleRCaptura), 'stare' => $pc->stareCaptura($dosarC, $caleRCaptura)]);
$pc->adaugaDocument('arhiva:' . substr(hash('sha256', $caleRCaptura), 0, 16), 'arhiva', $tR, ['titlu' => $caleRCaptura, 'cale' => $caleRCaptura, 'status' => 200,
    'sha_captura' => (string) $rR?->sha256(), 'antete' => PlanCapturat::antete((array) $rR?->antete, $url), 'valori_import' => ['cale' => $caleRCaptura]]);
$pc->grupeaza();
$verifica('captura de probă: tăierea fără NU (formularele de abonare Divi trec de politica HtmlCapturat)', $pc->probleme === [], implode(' | ', $pc->probleme));
// Autorul paginilor MailPoet (E-LEG-08), ca PlanMailPoet::adauga la import: o editare „autor-ascuns” pe fiecare cadru, data rămâne.
$autorAscuns = CadreMailPoet::ascundeAutorul($pc);
$cadreTaiate = [];
foreach ($pc->documente as $docC) {
    $caleC = (string) ($docC['valori_import']['cale'] ?? '');
    if (str_starts_with($caleC, CadreMailPoet::PREFIX_CALE) && ($docC['taiere'] ?? null) instanceof Taiere) {
        $cadreTaiate[$caleC] = [($docC['taiere']->numarPeFel()[CadreMailPoet::FEL_EDITARE] ?? 0), str_contains($docC['taiere']->html, '/author/'),
            str_contains($docC['taiere']->html, '<p class="post-meta"><span class="published">ian. 1, 2024</span></p>'), count($docC['taiere']->regiuni)];
    }
}
ksort($cadreTaiate);
$verifica('E-LEG-08: la import, linia autorului iese din cadrele P și R (o editare „autor-ascuns” pe fiecare, nicio legătură /author/, data rămâne); '
    . 'cadrul R cu cele două regiuni mailpoet:pagina', $autorAscuns === [] && $cadreTaiate === ['/?mailpoet_page=subscriptions' => [1, false, true, 0],
        $caleRCaptura => [1, false, true, 2]], json_encode([$autorAscuns, $cadreTaiate], JSON_UNESCAPED_UNICODE));
// O formă necunoscută a autorului (alt cuvânt înainte, o legătură spre autor în afara liniei) e un NU, nu o pagină cu adresa lui.
$tP = null;
foreach ($pc->documente as $docC) {
    if (($docC['valori_import']['cale'] ?? '') === '/?mailpoet_page=subscriptions') {
        $tP = $docC['taiere'];
    }
}
$refuzAutor = $tP instanceof Taiere ? CadreMailPoet::dinTaiere(new Taiere($tP->html . '<a href="/author/altul/">altul</a>', $tP->editari, $tP->ancore, $tP->htmlAtribute,
    $tP->cap, $tP->bodyAtribute, $tP->inainte, $tP->continut, $tP->dupa, $tP->fragmente, $tP->valori, $tP->meniu, $tP->date, $tP->seo, $tP->seoMotiv, $tP->sectiuni,
    $tP->formulare, $tP->galerii, $tP->probleme, $tP->avertismente, $tP->regiuni)) : null;
$verifica('E-LEG-08: o legătură spre pagina unui autor în afara liniei p.post-meta → refuzul (motivul, fără adresă), nu un document scris', is_string($refuzAutor)
    && str_contains($refuzAutor, 'în afara liniei p.post-meta') && !str_contains($refuzAutor, '@'), (string) json_encode($refuzAutor, JSON_UNESCAPED_UNICODE));
$db->beginTransaction();
try {
    $paginiId = [];
    foreach ($pagini as $pg) {
        $db->prepare('INSERT INTO pages (title, slug, html_content, is_published) VALUES (?, ?, ?, 1)')->execute([$pg['titlu'], trim($pg['cale'], '/') ?: 'acasa',
            $taieri[$pg['cale']]->continut]);
        $paginiId['pagina:' . $pg['id']] = (int) $db->lastInsertId();
    }
    $scris = ScriereCapturata::scrie($db, $pc, $paginiId, CadruCapturat::scripturiRuntime('divi4@1'));
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    $scris = ['eroare' => $e->getMessage()];
}
$verifica('captura scrisă (P1 la import și dovada regiunii mailpoet:pagina a cadrului R), cu cadrele P și R ca documente capturate', !isset($scris['eroare'])
    && CadruCapturat::document($db, cale: '/?mailpoet_page=subscriptions') !== null && CadruCapturat::document($db, cale: $caleRCaptura) !== null,
    json_encode($scris, JSON_UNESCAPED_UNICODE));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 1. În proces: data, jetoanele, paginile, dovada regiunii, bugetul, listele automate, bifa din checkout, momentul confirmării');
$b64 = static fn (mixed $v): string => rtrim(base64_encode((string) json_encode($v)), '=');
$verifica('data: un tablou, base64 greșit, JSON stricat, JSON care nu e tablou → []', Date::decodeaza(['x' => 1]) === [] && Date::decodeaza('%%%') === []
    && Date::decodeaza(base64_encode('{nu')) === [] && Date::decodeaza(base64_encode('"text"')) === [] && Date::decodeaza(null) === []);
$cuPlus = Date::codifica(['token' => '>>>?', 'email' => 'x@example.invalid']);
$verifica('data: un „+” devenit spațiu se decodează (VC-MP-07); codificarea fără „=”', str_contains($cuPlus, '+')
    && Date::decodeaza(str_replace('+', ' ', $cuPlus)) === ['token' => '>>>?', 'email' => 'x@example.invalid'] && !str_ends_with(Date::codifica(['a' => 1]), '='));
Jetoane::$acum = gmmktime(12, 0, 0, 11, 20, 2026);
$v = static function (int $mp, mixed $jeton) use ($db, $ab): ?string {
    Jetoane::uitaCererea();
    return Jetoane::verifica($db, $ab($mp), $jeton);
};
$verifica('jetonul de 32: trece; cu un sufix în plus: trece (prefixul păstrat); NULL, gol, un număr, un tablou: nu', $v(11, $jeton11) === $jeton11
    && $v(11, $jeton11 . 'zz') === $jeton11 && $v(14, 'orice') === null && $v(15, '') === null && $v(11, 12345) === null && $v(11, [$jeton11]) === null);
$verifica('jetonul vechi de 6: trece; primele 6 ale jetonului actualizat: trec cât ține grația (ceasul înghețat înainte de termen)',
    $v(13, 'a1b2c3') === 'a1b2c3' && $v(12, 'a1b2c3') !== null && str_starts_with((string) $v(12, 'a1b2c3'), Jetoane::PREFIX));
Jetoane::$acum = gmmktime(0, 0, 1, 12, 20, 2026);
$verifica('după termenul grației: primele 6 ale jetonului actualizat nu mai trec; jetonul întreg trece', $v(12, 'a1b2c3') === null
    && $v(12, 'a1b2c3-0123456789abcdef012345678') !== null);
Jetoane::$acum = gmmktime(12, 0, 0, 11, 20, 2026);
for ($i = 0; $i < 10; $i++) {
    $v(12, sprintf('zz%04d', $i));
}
$verifica('a 11-a încercare de 6 caractere în 24 de ore: refuzată chiar și cea bună', $v(12, 'a1b2c3') === null);
$db->exec('DELETE FROM newsletter_mailpoet_nepotriviri');
$ax1 = Jetoane::ax1($id(11), 'abonat@example.invalid');
$verifica('jetonul CMS (ax1.) trece doar pentru abonatul lui', $v(11, $ax1) === $ax1 && $v(17, $ax1) === null);
$tC = Jetoane::$acum;
$verifica('varianta de confirmare: valabilă 30 de zile, nu din viitor', Jetoane::verificaConfirmare($ab(12), Jetoane::ax1Confirmare($id(12), 'neconfirmat@example.invalid', $tC), $tC)
    && !Jetoane::verificaConfirmare($ab(12), Jetoane::ax1Confirmare($id(12), 'neconfirmat@example.invalid', $tC - 31 * 86400), $tC - 31 * 86400));
Jetoane::$acum = null;
$verifica('pagina A (confirmat): H1 și corpul exact (§3.4.2)', Pagini::confirmat('Pensiunea Probă')->titlu === 'Te-ai abonat la Pensiunea Probă'
    && Pagini::confirmat('Pensiunea Probă')->corp === "<p>Dap, te-am adăugat în lista noastră de emailuri. Vei auzi vești de la noi în curând.</p>\n"
    && Pagini::confirmat('')->titlu === 'Acum ești abonat!');
$f = Pagini::necunoscut();
$verifica('pagina F (necunoscut): H1, corpul fără <p>, statusul 404 (§3.9)', $f->titlu === 'Hmmm... nu avem o înregistrare despre tine.' && $f->status === 404
    && $f->corp === 'Adresa ta de email nu mai apare în listele noastre. Înregistrează-te din nou sau contactează-ne dacă acest lucru pare o greșeală.');
$c = Pagini::dezabonat(null, '');
$verifica('pagina C (dezabonat, previzualizare): corpul exact', $c->titlu === 'Acum ești dezabonat.' && $c->corp === '<p><p class="mailpoet_unsubscribed_content">Te-ai dezabonat'
    . ' din greșeală? <strong>Legătura către pagina de administrare a abonării este disponibilă doar abonaților din listele de corespondență.</strong></p></p>' . "\n");
$verifica('pagina B (reangajare): H1 și corpul', Pagini::reangajare()->titlu === 'Mulțumim că ne-ai anunțat!' && str_starts_with(Pagini::reangajare()->corp, '<p><p>Îți apreciem'));
$d = Pagini::confirmaDezabonare($db, $ab(11), $jeton11, Abonati::coadaDupaId($db, 41));
$verifica('pagina D: formularul cu type=confirmation, blocul adresei, butonul în locul legăturii cu onclick (VC-MP-02), fără comentariu',
    $d->titlu === 'Confirmă că vrei să te dezabonezi' && str_contains($d->corp, '<input type="hidden" name="type" value="confirmation">')
    && str_contains($d->corp, '<strong>abonat@example.invalid</strong>') && str_contains($d->corp, Pagini::butonDezabonare()) && !str_contains($d->corp, 'onclick')
    && !str_contains($d->corp, '<!--') && str_contains($d->corp, '&amp;request_method=POST'));
$g = Pagini::gestionare($db, null, Abonati::DEMO_JETON, ['mailpoet_page' => 'subscriptions'], '/?mailpoet_page=subscriptions&mailpoet_router&endpoint=subscription&action=manage&data=eyJwcmV2aWV3IjoxfQ');
$verifica('pagina G (administrare, previzualizare): adresa și jetonul demo, numele câmpurilor din cadrul R, lista vizibilă, nicio opțiune selectată',
    $g->titlu === 'Administrează-ți abonamentul' && str_contains($g->corp, '<strong>demo@mailpoet.com</strong>') && str_contains($g->corp, 'value="' . Abonati::DEMO_JETON . '"')
    && str_contains($g->corp, 'name="data[' . $campuriR['first_name'] . ']"') && str_contains($g->corp, 'name="data[' . $campuriR['segments'] . '][]" value="104"')
    && !str_contains($g->corp, 'selected="selected"') && str_contains($g->corp, '<input type="hidden" name="_ax" value="'));
// Sondajul cu „Altele” (setarea allow_other_text): câmpul de text fără `hidden`, fără scriptul lui MailPoet (VC-MP-02b); normalizarea
// dovezii pe contractul de la 02c85ac aduce o captură MailPoet (cu `hidden` și scriptul dinaintea lui </form>) la aceiași octeți.
$vechiSondaj = (string) $db->query("SELECT `value` FROM settings WHERE `key` = 'mailpoet_sondaj_dezabonare'")->fetchColumn();
$db->exec("UPDATE settings SET `value` = '{\"activ\":true,\"text_liber\":true}' WHERE `key` = 'mailpoet_sondaj_dezabonare'");
$sondajCms = Pagini::sondaj($db, $ab(13), Jetoane::ax1($id(13), 'dezabonat@example.invalid'), null, []);
$db->prepare("UPDATE settings SET `value` = ? WHERE `key` = 'mailpoet_sondaj_dezabonare'")->execute([$vechiSondaj]);
$sondajMp = str_replace(['<p class="mailpoet-unsubscribe-reason-text">', "\n  </p>\n  </form>"], ['<p class="mailpoet-unsubscribe-reason-text" hidden>',
    "\n  </p>\n      <script>\n        (function () { /* scriptul MailPoet */ })();\n    </script>\n  </form>"], $sondajCms);
$rp = new RandarePagina();
$verifica('sondajul cu „Altele”: câmpul de text vizibil, fără script; normalizarea dovezii scoate `hidden` și scriptul unei capturi MailPoet',
    str_contains($sondajCms, "      </p>\n              <p class=\"mailpoet-unsubscribe-reason-text\">\n") && !str_contains($sondajCms, '<script')
    && $sondajMp !== $sondajCms && $rp->normalizeaza($sondajMp) === $rp->normalizeaza($sondajCms), $sondajCms);
// Dovada regiunii mailpoet:pagina pe previzualizarea cadrului R: H1 (n=0) și conținutul (n=1), cu câmpurile volatile normalizate.
$caleR = '/?mailpoet_page=subscriptions&mailpoet_router&endpoint=subscription&action=manage&data=eyJwcmV2aWV3IjoxfQ';
$norm = new Normalizare($url);
$ctx = new ContextRegiune($db, 'arhiva:r', $caleR, [], 'GET', []);
StarePagina::seteaza(null);
// Pe contractul de la 02c85ac (Randator::normalizeaza) captura poate avea alte id-uri ale blocurilor de eroare și alt nonce `_ax`
// (mascate); pe contractul reparat al pistei A (lista închisă, octeții capturați în ContextRegiune::capturat()) id-urile se iau din
// captură și originea din mailpoet_redirect e a capturii, iar acțiunea formularului și `_ax` (VC-MP-03) trebuie să fie ale noastre.
$contractReparat = method_exists(ContextRegiune::class, 'capturat');
preg_match_all('/mailpoet_error_([0-9a-z]+)/', $g->interiorContinut(), $mr);
$idR = array_values(array_unique($mr[1]));
$capturatCorp = (string) preg_replace_callback('/mailpoet_error_([0-9a-z]+)/', static fn (array $m): string => 'mailpoet_error_q' . array_search($m[1], $idR, true) . 'w8e',
    $g->interiorContinut());
if (!$contractReparat) {
    $capturatCorp = str_replace('value="' . (string) preg_replace('/^.*name="_ax" value="([^"]+)".*$/s', '$1', $g->corp) . '"', 'value="altul0123"', $capturatCorp);
}
$capturatCorp = str_replace('name="mailpoet_redirect" value="' . NewsletterService::appBaseUrl(), 'name="mailpoet_redirect" value="https://site-capturat.example.invalid',
    $capturatCorp);
$pentru = static fn (ContextRegiune $c, int $n, string $octeti): ContextRegiune => $contractReparat
    ? $c->pentru('mailpoet:pagina', $n, 'sesiune', 'interior', null, $octeti) : $c->pentru('mailpoet:pagina', $n, 'sesiune');
$p0 = Regiuni::dovada('mailpoet:pagina', 'Administrează-ți abonamentul', $pentru($ctx, 0, 'Administrează-ți abonamentul'), $norm);
$p1 = Regiuni::dovada('mailpoet:pagina', $capturatCorp, $pentru($ctx, 1, $capturatCorp), $norm);
$p2 = Regiuni::dovada('mailpoet:pagina', str_replace('Prenume', 'Prenumele', $capturatCorp), $pentru($ctx, 1, str_replace('Prenume', 'Prenumele', $capturatCorp)), $norm);
$verifica('dovada regiunii mailpoet:pagina (cadrul R, previzualizarea; contractul ' . ($contractReparat ? 'reparat al pistei A' : 'de la 02c85ac')
    . '): H1 și conținutul trec cu alte id-uri ale blocurilor de eroare și altă origine în mailpoet_redirect, un text schimbat = NU',
    $p0 === null && $p1 === null && is_string($p2) && str_contains($p2, 'diferă'), (string) ($p0 ?? $p1 ?? $p2));
if ($contractReparat) {
    // Forma lui MailPoet (acțiunea admin-post.php, fără `_ax`): diferența declarată VC-MP-03 cere normalizarea ei în lista închisă a
    // pistei A; până atunci dovada cadrului R al unei capturi reale e NU acolo (doar raportat).
    $formaMp = (string) preg_replace('#\n  <input type="hidden" name="_ax" value="[^"]*" />#', '', (string) preg_replace(
        '#(<form class="mailpoet-manage-subscription" method="post" action=")[^"]*#', '$1/wp-admin/admin-post.php', $capturatCorp));
    $p3 = Regiuni::dovada('mailpoet:pagina', $formaMp, $pentru($ctx, 1, $formaMp), $norm);
    $scrie('  INFO  forma MailPoet a formularului de gestionare (VC-MP-03) pe contractul reparat: ' . ($p3 === null ? 'dovedită'
        : 'NU până la normalizarea pistei A (' . mb_substr($p3, 0, 160) . ')'));
}
// Contractul reparat al pistei A (lista închisă a normalizărilor, octeții capturați ai regiunii): normalizari() și refolosirea id-urilor
// capturate ale blocurilor de eroare, în ordine, plus originea capturii în mailpoet_redirect la dovadă.
$randator = new RandarePagina();
preg_match_all('/mailpoet_error_([0-9a-z]+)/', $g->interiorContinut(), $mg);
$idCapturate = ['k1a2b', 'k3c4d', 'k5e6f', 'k7g8h'];
$capturatIduri = (string) preg_replace_callback('/mailpoet_error_([0-9a-z]+)/', static fn (array $m): string => 'mailpoet_error_'
    . ($idCapturate[array_search($m[1], array_values(array_unique($mg[1])), true)] ?? 'x'), $g->interiorContinut());
$g2 = Pagini::gestionare($db, null, Abonati::DEMO_JETON, ['mailpoet_page' => 'subscriptions'], $caleR);
$iduri = (new ReflectionMethod(RandarePagina::class, 'iduriCapturate'))->invoke(null, $g2->interiorContinut(), $capturatIduri);
$origine = (new ReflectionMethod(RandarePagina::class, 'origineCapturata'))->invoke(null, $g->interiorContinut(),
    '<input type="hidden" name="mailpoet_redirect" value="https://site-capturat.example.invalid/?mailpoet_page=subscriptions"/>');
preg_match_all('/mailpoet_error_([0-9a-z]+)/', (string) $iduri, $mi);
$verifica('randatorul pe contractul reparat: normalizari() = [nonce:_wpnonce]; id-urile blocurilor de eroare luate din captură (aceeași ordine); la dovadă, '
    . 'originea din mailpoet_redirect e a capturii', $randator->normalizari() === ['nonce:_wpnonce'] && count(array_unique($mg[1])) === 4
    && array_values(array_unique($mi[1])) === $idCapturate && preg_replace('/mailpoet_error_[0-9a-z]+/', '', (string) $iduri)
        === preg_replace('/mailpoet_error_[0-9a-z]+/', '', $g2->interiorContinut())
    && str_contains((string) $origine, 'name="mailpoet_redirect" value="https://site-capturat.example.invalid/?mailpoet_page=subscriptions')
    && (new ReflectionMethod(RandarePagina::class, 'iduriCapturate'))->invoke(null, $g->interiorContinut(), '<span class="mailpoet_error_a1"></span>') === $g->interiorContinut());
$nonceG = Jetoane::nonce('gestionare', $id(20));
$verifica('nonce-urile paginilor: 10 hexa (forma unui nonce WordPress), al zilei și al abonatului', preg_match('/^[0-9a-f]{10}$/D', $nonceG) === 1
    && Jetoane::nonceBun('gestionare', $id(20), $nonceG) && !Jetoane::nonceBun('gestionare', $id(11), $nonceG) && !Jetoane::nonceBun('motiv', $id(20), $nonceG));
$verifica('codurile newsletter: fără campanie, subiectul e gol (ca MailPoet fără newsletter); total = articolele din conținut',
    Shortcoduri::inlocuieste($db, 'a[newsletter:subject]b', ['text' => true]) === 'ab'
    && Shortcoduri::inlocuieste($db, 'x[newsletter:subject]', ['campanie' => ['subject' => 'S & T']]) === 'xS &amp; T'
    && Shortcoduri::inlocuieste($db, '<p data-post-id="1"></p><p data-post-id="2"></p>[newsletter:total]', []) === '<p data-post-id="1"></p><p data-post-id="2"></p>2');
$mc = NewsletterService::emailConfirmareMailPoet($db, $id(12), 'neconfirmat@example.invalid', time(), [$lista(103)]);
$verifica('emailul de confirmare: [newsletter:subject] din corp = subiectul lui randat; linkul de confirmare în forma MailPoet; niciun cod rămas', is_array($mc)
    && str_contains($mc['html'], '<p>' . htmlspecialchars($mc['subiect'], ENT_QUOTES | ENT_HTML401, 'UTF-8') . '</p>') && !str_contains($mc['subiect'], '[')
    && !str_contains($mc['html'], '[newsletter:') && !str_contains($mc['html'], '[site:') && !str_contains($mc['html'], '[activation_link]')
    && str_contains($mc['html'], 'action=confirm'), json_encode($mc, JSON_UNESCAPED_UNICODE));
// Bugetul: 3 pe 300 de secunde; ia() trei, a patra așteaptă; consuma() trece mereu și coboară sub 0.
BugetTrimitere::configureaza($db, 3, 300, 'proba');
BugetTrimitere::uita();
$luate = [BugetTrimitere::ia($db, 'proba'), BugetTrimitere::ia($db, 'proba'), BugetTrimitere::ia($db, 'proba'), BugetTrimitere::ia($db, 'proba')];
BugetTrimitere::consuma($db, 'proba');
$verifica('bugetul comun: capacitatea se ia, apoi lotul așteaptă; confirmările nu așteaptă (jetoanele sub 0); fără rândul bugetului, nicio limită',
    $luate === [true, true, true, false] && (BugetTrimitere::stare($db, 'proba')['jetoane'] ?? 0) < 0 && BugetTrimitere::ia($db, 'lipsa-proba'));
$db->exec("UPDATE buget_trimitere SET jetoane = 0, actualizat_la = NOW(6) - INTERVAL 200 SECOND WHERE nume = 'proba'");
$verifica('bugetul se umple în timp (200 s din 300 → 2 jetoane)', BugetTrimitere::ia($db, 'proba') && BugetTrimitere::ia($db, 'proba') && !BugetTrimitere::ia($db, 'proba'));
// Listele automate (contul, comanda) și bifa din checkout.
Sincronizare::contCreat($db, 9, 'cont-nou@example.invalid', 'Paul', 'Nou', '192.0.2.50');
$cn = Abonati::dupaAdresa($db, ['email' => 'cont-nou@example.invalid']);
$verifica('cont nou → lista utilizatorilor WordPress (pending, fără email de confirmare: setarea „abonare la cont” e oprită), wp_user_id',
    is_array($cn) && $cn['status'] === 'pending' && (int) $cn['wp_user_id'] === 9 && (string) $db->query('SELECT status FROM newsletter_list_subscribers WHERE list_id = '
        . $lista(101) . ' AND subscriber_id = ' . (int) $cn['id'])->fetchColumn() === 'subscribed');
Sincronizare::comanda($db, 'oaspete@example.invalid', 'Radu', '');
$oa = Abonati::dupaAdresa($db, ['email' => 'oaspete@example.invalid']);
$verifica('comandă a unui oaspete → client WooCommerce dezabonat (neconfirmat), lista clienților dezabonată (ca MailPoet)', is_array($oa) && $oa['status'] === 'unsubscribed'
    && (int) $oa['is_woocommerce_user'] === 1 && (string) $db->query('SELECT status FROM newsletter_list_subscribers WHERE list_id = ' . $lista(102)
        . ' AND subscriber_id = ' . (int) $oa['id'])->fetchColumn() === 'unsubscribed');
$stareOpt = Sincronizare::optinCheckout($db, 'bifa@example.invalid', 'Sara', '', true, '192.0.2.60', \App\Support\Settings::all($db));
$bi = Abonati::dupaAdresa($db, ['email' => 'bifa@example.invalid']);
// Un client nou e întâi dezabonat (comanda, neconfirmat); cererea de abonare îl lasă așa, cu pending_at, până la confirmare (codul comun).
$verifica('bifa din checkout → dubla confirmare (cererea, pending_at) pe lista clienților și pe lista din setări, sursa woocommerce_checkout', $stareOpt === 'trimis'
    && is_array($bi) && in_array($bi['status'], ['pending', 'unsubscribed'], true) && $bi['pending_at'] !== null && $bi['source'] === 'woocommerce_checkout' && (string) $db->query('SELECT status FROM newsletter_list_subscribers WHERE list_id = '
        . $lista(104) . ' AND subscriber_id = ' . (int) $bi['id'])->fetchColumn() === 'subscribed', (string) $stareOpt . ' ' . json_encode([$bi['status'] ?? null,
        $bi['source'] ?? null, $db->query('SELECT list_id, status FROM newsletter_list_subscribers WHERE subscriber_id = ' . (int) ($bi['id'] ?? 0))->fetchAll(PDO::FETCH_KEY_PAIR),
        $lista(102), $lista(104)]));
$verifica('fără bifă: doar clientul, nicio cerere de abonare', Sincronizare::optinCheckout($db, 'fara-bifa@example.invalid', '', '', false, '192.0.2.61', []) === null);
// Numele din facturare la fiecare comandă (WooCommerce::synchronizeGuestCustomer): peste cele vechi, fiecare doar nevid și fără `<`/`>`.
Sincronizare::comanda($db, 'oaspete@example.invalid', 'Radu Nou', '<b>x</b>');
$oa2 = Abonati::dupaAdresa($db, ['email' => 'oaspete@example.invalid']);
Sincronizare::comanda($db, 'oaspete@example.invalid', '', 'Popa');
$oa3 = Abonati::dupaAdresa($db, ['email' => 'oaspete@example.invalid']);
$verifica('comanda: prenumele și numele din facturare peste cele vechi, doar nevide și fără < sau > (ca sincronizarea oaspeților MailPoet)',
    ($oa2['first_name'] ?? '') === 'Radu Nou' && ($oa2['last_name'] ?? '') === '' && ($oa3['first_name'] ?? '') === 'Radu Nou' && ($oa3['last_name'] ?? '') === 'Popa'
    && ($oa3['name'] ?? '') === 'Radu Nou Popa', json_encode([$oa2['first_name'] ?? null, $oa2['last_name'] ?? null, $oa3['first_name'] ?? null, $oa3['last_name'] ?? null]));
// Un client deja abonat bifează: dovada acordului (IP-ul, momentul) doar dacă n-avea niciuna (Subscription::subscribe).
$db->prepare("UPDATE newsletter_subscribers SET confirmed_ip = NULL, confirmed_at = NULL WHERE id = ?")->execute([$id(17)]);
$st17 = Sincronizare::optinCheckout($db, 'cont@example.invalid', 'Gabriela', 'Cont', true, '192.0.2.62', \App\Support\Settings::all($db));
$a17 = $ab(17);
$st11 = Sincronizare::optinCheckout($db, 'abonat@example.invalid', 'Ana', 'Probă', true, '192.0.2.63', \App\Support\Settings::all($db));
$a11 = $ab(11);
$verifica('bifa unui abonat activ: deja_activ; fără dovadă → IP-ul și momentul de acum; cu dovadă → neschimbată', $st17 === 'deja_activ'
    && ($a17['confirmed_ip'] ?? '') === '192.0.2.62' && ($a17['confirmed_at'] ?? null) !== null && $st11 === 'deja_activ' && ($a11['confirmed_ip'] ?? '') === '192.0.2.12',
    json_encode([$st17, $a17['confirmed_ip'] ?? null, $st11, $a11['confirmed_ip'] ?? null]));
// O adresă deja abonată care se abonează din nou dintr-un formular: emailul „Abonat nou la <listele cerute>” (API::subscribeToLists),
// și pentru o listă la care era deja abonată; fără listă MailPoet cerută, niciunul.
$numarAbonatNou = static fn (): int => (int) $db->query("SELECT COUNT(*) FROM email_send_history WHERE email_type = 'newsletter_abonat_nou'")->fetchColumn();
$inainteNou2 = $numarAbonatNou();
$rDeja = NewsletterService::cerereAbonare($db, $lista(103), 'abonat@example.invalid', '', '192.0.2.64', \App\Support\Settings::all($db), ['sursa' => 'api']);
$subiectDeja = (string) $db->query("SELECT subject FROM email_send_history WHERE email_type = 'newsletter_abonat_nou' ORDER BY id DESC LIMIT 1")->fetchColumn();
$rDejaCms = NewsletterService::cerereAbonare($db, 0, 'abonat@example.invalid', '', '192.0.2.64', \App\Support\Settings::all($db));
$verifica('o adresă activă din nou în formular: deja_activ, emailul „Abonat nou” cu lista cerută (și deja abonată); lista internă singură → niciun email',
    $rDeja['stare'] === 'deja_activ' && $rDejaCms['stare'] === 'deja_activ' && $numarAbonatNou() === $inainteNou2 + 1
    && $subiectDeja === sprintf(\App\Support\MailPoetCompat\Texte::ABONAT_NOU, 'Lista principală de probă'), json_encode([$rDeja, $rDejaCms, $numarAbonatNou() - $inainteNou2]));
// Modul simplu al emailului de confirmare (fără editor): ca ConfirmationEmailMailer::getMailBody (nl2br, listele în <strong>, legătura
// cu target="_blank", spațiile strânse); `[lists_to_confirm]` din subiect rămâne.
$vechiSablon = (string) $db->query("SELECT `value` FROM settings WHERE `key` = 'mailpoet_email_confirmare'")->fetchColumn();
$db->prepare("UPDATE settings SET `value` = ? WHERE `key` = 'mailpoet_email_confirmare'")->execute([(string) json_encode(['mod' => 'simplu',
    'subiect' => 'Confirmă [lists_to_confirm] pe [site:title]', 'html' => "Salut [subscriber:firstname | default:prieten],\n\nListele: [lists_to_confirm]\n"
    . '[activation_link]Confirmă abonamentul[/activation_link]', 'text' => ''], JSON_UNESCAPED_UNICODE)]);
$ms = NewsletterService::emailConfirmareMailPoet($db, $id(12), 'neconfirmat@example.invalid', time(), [$lista(103)]);
$db->prepare("UPDATE settings SET `value` = ? WHERE `key` = 'mailpoet_email_confirmare'")->execute([$vechiSablon]);
$verifica('emailul de confirmare în modul simplu: nl2br, listele în <strong>, legătura cu target="_blank", spațiile strânse, subiectul cu [lists_to_confirm]',
    is_array($ms) && str_starts_with($ms['html'], 'Salut Bogdan,<br /> <br /> Listele: <strong>Lista principală de probă</strong><br /> <a target="_blank" href="')
    && str_contains($ms['html'], 'action=confirm') && str_ends_with($ms['html'], '">Confirmă abonamentul</a>') && str_starts_with($ms['subiect'], 'Confirmă [lists_to_confirm] pe '),
    json_encode($ms, JSON_UNESCAPED_UNICODE));
// ── Corecturile pistei E (runda 1 a recenziei), în proces.
// SEC-E-06: [site:title] scăpat pentru HTML, ca valoarea oricărui cod; în modul text, cum e.
$numeVechi = Pagini::setare($db, Pagini::CHEIE_NUME_SITE);
\App\Support\Settings::save($db, [Pagini::CHEIE_NUME_SITE => 'Pensiunea <b>Probă</b> & Co']);
$titluHtml = Shortcoduri::inlocuieste($db, 'x[site:title]', []);
$titluText = Shortcoduri::inlocuieste($db, 'x[site:title]', ['text' => true]);
$numeVechi === null ? $db->prepare('DELETE FROM settings WHERE `key` = ?')->execute([Pagini::CHEIE_NUME_SITE])
    : \App\Support\Settings::save($db, [Pagini::CHEIE_NUME_SITE => $numeVechi]);
$verifica('SEC-E-06: [site:title] scăpat în HTML (ca valoarea oricărui cod), neschimbat în modul text', $titluHtml === 'xPensiunea &lt;b&gt;Probă&lt;/b&gt; &amp; Co'
    && $titluText === 'xPensiunea <b>Probă</b> & Co', (string) json_encode([$titluHtml, $titluText], JSON_UNESCAPED_UNICODE));
// E-PAR-02: „vezi în browser”, anonim: adresele păstrate de MailPoet sunt cele din href, deja codate HTML; o entitate nu se codează a doua oară.
$db->exec("INSERT INTO newsletter_mailpoet_linkuri (coada_id, hash, url) VALUES (41, 'e1e2e3e4e5e6', 'https://pensiune-proba.test/cauta/?a=1&amp;b=2&c=3')");
$arhivat = Arhiva::randeaza($db, ['id' => 0, 'html_content' => '<p><a href="[mailpoet_click_data]-e1e2e3e4e5e6">Caută</a></p>'], null, '', ['coada_id' => 41], false);
$db->exec("DELETE FROM newsletter_mailpoet_linkuri WHERE coada_id = 41 AND hash = 'e1e2e3e4e5e6'");
$verifica('E-PAR-02: „vezi în browser”, anonim: adresa păstrată (cu &amp;) nu se codează a doua oară, un & necodat devine &amp;',
    str_contains($arhivat, 'href="https://pensiune-proba.test/cauta/?a=1&amp;b=2&amp;c=3"') && !str_contains($arhivat, '&amp;amp;'), $arhivat);
// E-PAR-05: regula paginii C a dezabonării, doar pe `data` (ca Subscription::unsubscribe): `preview` adevărat și niciun `token`.
$caleU = '/?mailpoet_page=subscriptions&mailpoet_router&endpoint=subscription&action=unsubscribe';
$titluStare = static fn (array $date, array $get): string => Pagini::stare($db, 'unsubscribe', $date, $get, $caleU, null, '', null)->titlu;
$verifica('E-PAR-05: dezabonarea în previzualizare: &preview=1 fără data → D; {"preview":0} → D; {"preview":1} și {"preview":1,"token":null} → C; '
    . '{"preview":1,"token":"x"} → D', $titluStare([], ['preview' => '1']) === 'Confirmă că vrei să te dezabonezi'
    && $titluStare(['preview' => 0], []) === 'Confirmă că vrei să te dezabonezi' && $titluStare(['preview' => 1], []) === 'Acum ești dezabonat.'
    && $titluStare(['preview' => 1, 'token' => null], []) === 'Acum ești dezabonat.' && $titluStare(['preview' => 1, 'token' => 'x'], []) === 'Confirmă că vrei să te dezabonezi',
    (string) json_encode([$titluStare([], ['preview' => '1']), $titluStare(['preview' => 0], []), $titluStare(['preview' => 1, 'token' => null], [])], JSON_UNESCAPED_UNICODE));
// SEC-E-02: un jeton MailPoet păstrat mai scurt de 32 de caractere (13: 6) trece doar cât abonatul are sub 10 nepotriviri în 24 de ore; o
// cerere fără potrivire se numără; peste limită nici cel bun nu mai trece, jetonul CMS (ax1.) da. Unul de 32 nu se numără.
$db->exec('DELETE FROM newsletter_mailpoet_nepotriviri');
$bun13 = $v(13, 'a1b2c3');
for ($i = 0; $i < 10; $i++) {
    $v(13, sprintf('zz%04d', $i));
}
$blocat13 = $v(13, 'a1b2c3');
$ax13 = $v(13, Jetoane::ax1($id(13), 'dezabonat@example.invalid'));
for ($i = 0; $i < 12; $i++) {
    $v(11, sprintf('%032d', $i));
}
$nepotriviri11 = (int) $db->query('SELECT COUNT(*) FROM newsletter_mailpoet_nepotriviri WHERE abonat_id = ' . $id(11))->fetchColumn();
$bun11 = $v(11, $jeton11);
$db->exec('DELETE FROM newsletter_mailpoet_nepotriviri');
$verifica('SEC-E-02: jetonul scurt (6 caractere) trece; după 10 jetoane greșite în 24 de ore nici cel bun, cel CMS (ax1.) da; jetonul de 32: '
    . 'nepotrivirile nu se numără, cel bun trece', $bun13 === 'a1b2c3' && $blocat13 === null && $ax13 !== null && $nepotriviri11 === 0 && $bun11 === $jeton11,
    (string) json_encode([$bun13, $blocat13, $ax13 !== null, $nepotriviri11, $bun11]));
// SEC-E-02-R1: peste limita nepotrivirilor, retragerea „cu un clic” (al patrulea argument) primește jetonul scurt întreg, ca MailPoet:
// oricine poate număra nepotriviri pentru un abonat, nimeni nu-l poate opri să se dezaboneze. Un jeton greșit tot nu trece (și nu se
// mai numără peste limită), iar paginile rămân blocate; sub limită, o nepotrivire a retragerii se numără ca oricare alta.
$retragere = static function (int $mp, mixed $jeton) use ($db, $ab): ?string {
    Jetoane::uitaCererea();
    return Jetoane::verifica($db, $ab($mp), $jeton, true);
};
$numarNepotriviri = static fn (int $mp): int => (int) $db->query('SELECT COALESCE(SUM(numar), 0) FROM newsletter_mailpoet_nepotriviri WHERE abonat_id = '
    . $id($mp))->fetchColumn();
$db->exec('DELETE FROM newsletter_mailpoet_nepotriviri');
$retragereGresitSub = $retragere(13, 'zz0000');
$numaratSub = $numarNepotriviri(13);
for ($i = 1; $i < 10; $i++) {
    $v(13, sprintf('zz%04d', $i));
}
$retragereBun = $retragere(13, 'a1b2c3');
$retragereGresit = $retragere(13, 'zz9999');
$paginaBlocata = $v(13, 'a1b2c3');
$numaratPeste = $numarNepotriviri(13);
$retragere32 = $retragere(11, $jeton11);
$db->exec('DELETE FROM newsletter_mailpoet_nepotriviri');
$verifica('SEC-E-02-R1: retragerea „cu un clic”: o nepotrivire sub limită se numără; peste limita jetonului scurt, jetonul bun trece, unul greșit nu '
    . '(nenumărat), paginile tot nu; jetonul de 32 trece ca înainte', $retragereGresitSub === null && $numaratSub === 1 && $retragereBun === 'a1b2c3'
    && $retragereGresit === null && $paginaBlocata === null && $numaratPeste === 10 && $retragere32 === $jeton11,
    (string) json_encode([$retragereGresitSub, $numaratSub, $retragereBun, $retragereGresit, $paginaBlocata, $numaratPeste, $retragere32 === $jeton11]));
// SEC-E-01: emailul „Abonat nou” cel mult o dată pe zi pentru o pereche (abonat, listă), cel mult LIMITA_NOTIFICARI_ORA pe oră pe site și
// doar cu un jeton din bugetul comun (BugetTrimitere::ia: bugetul nu coboară sub zero pentru el).
$db->exec("DELETE FROM newsletter_cereri_confirmare WHERE tip = 'notificare'");
$n0 = $numarAbonatNou();
NewsletterService::notificareAbonatNou($db, $id(11), [$lista(103)]);
NewsletterService::notificareAbonatNou($db, $id(11), [$lista(103)]);
NewsletterService::notificareAbonatNou($db, $id(11), [$lista(103), $lista(104)]);
$subiectNou = (string) $db->query("SELECT subject FROM email_send_history WHERE email_type = 'newsletter_abonat_nou' ORDER BY id DESC LIMIT 1")->fetchColumn();
$dupaPereche = $numarAbonatNou() - $n0;
$plin = $db->prepare("INSERT INTO newsletter_cereri_confirmare (email_hash, ip, tip, created_at) VALUES (?, NULL, 'notificare', NOW())");
for ($i = 0; $i < NewsletterService::LIMITA_NOTIFICARI_ORA; $i++) {
    $plin->execute([hash('sha256', 'umplutura-proba|' . $i)]);
}
NewsletterService::notificareAbonatNou($db, $id(17), [$lista(103)]);
$dupaOra = $numarAbonatNou() - $n0;
$db->exec("DELETE FROM newsletter_cereri_confirmare WHERE tip = 'notificare'");
$bugetInainte = BugetTrimitere::stare($db);
$db->exec("UPDATE buget_trimitere SET jetoane = 0, actualizat_la = NOW(6) WHERE nume = '" . BugetTrimitere::IMPLICIT . "'");
BugetTrimitere::uita();
NewsletterService::notificareAbonatNou($db, $id(17), [$lista(103)]);
$dupaBuget = $numarAbonatNou() - $n0;
$jetoaneDupa = (float) (BugetTrimitere::stare($db)['jetoane'] ?? -1);
$db->prepare('UPDATE buget_trimitere SET jetoane = ?, actualizat_la = NOW(6) WHERE nume = ?')->execute([(float) ($bugetInainte['capacitate'] ?? 0), BugetTrimitere::IMPLICIT]);
BugetTrimitere::uita();
$db->exec("DELETE FROM newsletter_cereri_confirmare WHERE tip = 'notificare'");
$verifica('SEC-E-01: „Abonat nou”: aceeași pereche (abonat, listă) a doua oară în 24 de ore → niciun email, o listă nouă → emailul doar cu ea; peste '
    . NewsletterService::LIMITA_NOTIFICARI_ORA . ' pe oră → niciunul; fără jeton în bugetul comun → niciunul, iar bugetul nu coboară sub zero',
    $dupaPereche === 2 && $subiectNou === sprintf(\App\Support\MailPoetCompat\Texte::ABONAT_NOU, 'Lista vizibilă de probă') && $dupaOra === 2 && $dupaBuget === 2
    && $jetoaneDupa >= 0.0, (string) json_encode([$dupaPereche, $subiectNou, $dupaOra, $dupaBuget, $jetoaneDupa], JSON_UNESCAPED_UNICODE));
// E-LEG-09: bifa din checkout oprită în setările MailPoet (`activ` = false) → nicio abonare, nicio cerere de confirmare (comanda se
// sincronizează ca înainte: clientul există, dezabonat).
$vechiOptin = Pagini::setare($db, 'mailpoet_optin_checkout');
\App\Support\Settings::save($db, ['mailpoet_optin_checkout' => (string) json_encode(['activ' => false, 'eticheta' => 'Da', 'liste' => [$lista(104)]])]);
$stareOprita = Sincronizare::optinCheckout($db, 'bifa-oprita@example.invalid', 'Oana', '', true, '192.0.2.66', \App\Support\Settings::all($db));
\App\Support\Settings::save($db, ['mailpoet_optin_checkout' => (string) $vechiOptin]);
$bo = Abonati::dupaAdresa($db, ['email' => 'bifa-oprita@example.invalid']);
$cereriBo = (int) $db->query("SELECT COUNT(*) FROM newsletter_cereri_confirmare WHERE email_hash = '" . NewsletterService::amprentaAdresa('bifa-oprita@example.invalid') . "'")->fetchColumn();
$verifica('E-LEG-09: bifa din checkout oprită în MailPoet → null, nicio cerere de confirmare, nicio listă abonată (doar clientul, dezabonat)', $stareOprita === null
    && $cereriBo === 0 && is_array($bo) && $bo['pending_at'] === null && $bo['status'] === 'unsubscribed' && (int) $db->query("SELECT COUNT(*) FROM newsletter_list_subscribers"
        . " WHERE subscriber_id = " . (int) $bo['id'] . " AND status = 'subscribed'")->fetchColumn() === 0, (string) json_encode([$stareOprita, $cereriBo, $bo['status'] ?? null]));
// E-LEG-02: pe un site cu MailPoet, lista internă „Toți abonații” nu primește pe nimeni (importul, conturile, comenzile, abonările) și nu e
// o țintă a campaniilor.
$implicita = NewsletterService::defaultListId($db);
$peImplicita = (int) $db->query('SELECT COUNT(*) FROM newsletter_list_subscribers WHERE list_id = ' . $implicita)->fetchColumn();
$db->prepare("INSERT INTO newsletter_campaigns (name, subject, html_content, status, subscriber_list_id, subscriber_list_ids) VALUES ('Proba implicita', 'Proba', '<p>x</p>', 'draft', ?, ?)")
    ->execute([$implicita, (string) json_encode([$implicita])]);
$campImplicita = (int) $db->lastInsertId();
try {
    NewsletterService::sendCampaignNow($db, $campImplicita, \App\Support\Settings::all($db), 10);
    $refuzImplicita = '';
} catch (Throwable $e) {
    $refuzImplicita = $e->getMessage();
}
$db->prepare('DELETE FROM newsletter_campaigns WHERE id = ?')->execute([$campImplicita]);
$verifica('E-LEG-02: lista internă „Toți abonații” fără niciun rând (importul, contul nou, comenzile, abonările de mai sus); o campanie doar pe ea → refuzată',
    $peImplicita === 0 && $refuzImplicita === 'Campania nu are listă de abonați.', (string) json_encode([$peImplicita, $refuzImplicita], JSON_UNESCAPED_UNICODE));
// E-PAR-03: POST-urile routerului MailPoet și ale formularelor de abonare capturate nu pornesc sesiunea, doar pe un site cu MailPoet;
// orice alt POST, și acestea pe un site fără MailPoet, o pornesc ca înainte (SesiuneLaNevoie::trebuie).
$faraSesiune = [\App\Support\SesiuneLaNevoie::trebuie('POST', '/?mailpoet_router&endpoint=subscription&action=unsubscribe&data=x', []),
    \App\Support\SesiuneLaNevoie::trebuie('POST', '/?mailpoet_page=subscriptions&mailpoet_router&endpoint=subscription&action=manage', []),
    \App\Support\SesiuneLaNevoie::trebuie('POST', '/_andaxi/abonare/0', []), \App\Support\SesiuneLaNevoie::trebuie('POST', '/contact/send', []),
    \App\Support\SesiuneLaNevoie::trebuie('POST', '/_andaxi/abonare/0/x', [])];
@mkdir($lucru . '/pachet-fara', 0770, true);
file_put_contents($lucru . '/pachet-fara/wp-compat.json', '{"slash_final": true}');
DateSite::reseteaza($lucru . '/pachet-fara');
WpCompat::reseteaza();
$faraMailPoet = [\App\Support\SesiuneLaNevoie::trebuie('POST', '/?mailpoet_router&endpoint=subscription&action=unsubscribe&data=x', []),
    \App\Support\SesiuneLaNevoie::trebuie('POST', '/_andaxi/abonare/0', [])];
DateSite::reseteaza($lucru . '/pachet');
WpCompat::reseteaza();
Tema::reseteaza();
$verifica('E-PAR-03: POST-urile routerului și ale formularelor de abonare fără sesiune doar cu wp-compat.json → mailpoet; alte POST-uri și un site fără MailPoet: '
    . 'sesiunea pornește, ca înainte', $faraSesiune === [false, false, false, true, true] && $faraMailPoet === [true, true] && WpCompat::mailpoet(),
    (string) json_encode([$faraSesiune, $faraMailPoet]));
// E-LEG-01: pe un site cu MailPoet, cine a refuzat urmărirea (urmarire_refuzata) primește campaniile site-ului fără pixel și fără linkuri
// urmărite, iar cu nivelul MailPoet „basic” nimeni; deschiderea și clicul lui pe adresele urmărite ale CMS-ului nu se notează.
$dosarMail = $dosarDev . '/mail';
@mkdir($dosarMail, 0775, true);
$campanieUrm = static function (string $nume) use ($db, $lista): int {
    $db->prepare("INSERT INTO newsletter_campaigns (name, subject, html_content, status, subscriber_list_id, subscriber_list_ids) VALUES (?, ?, ?, 'draft', ?, ?)")
        ->execute([$nume, $nume, '<p>Salut</p><p><a href="https://pensiune-proba.test/oferta/">Oferta</a></p>', $lista(104), (string) json_encode([$lista(104)])]);
    return (int) $db->lastInsertId();
};
$decodeazaEml = static function (string $eml): string {
    $text = '';
    foreach (preg_split('/\r?\n--[^\r\n]+(?:\r?\n|$)/', $eml) ?: [] as $parte) {
        [$cap, $corp] = array_pad(preg_split("/\r?\n\r?\n/", $parte, 2) ?: [], 2, '');
        $text .= stripos($cap, 'base64') !== false ? base64_decode((string) preg_replace('/\s+/', '', $corp), false)
            : (stripos($cap, 'quoted-printable') !== false ? quoted_printable_decode($corp) : $corp);
    }
    return $text;
};
$trimiteCampania = static function (int $campanie) use ($db, $dosarMail, $decodeazaEml): array {
    $inainte = glob($dosarMail . '/*.eml') ?: [];
    NewsletterService::sendCampaignNow($db, $campanie, \App\Support\Settings::all($db), 50);
    $rez = [];
    foreach (array_diff(glob($dosarMail . '/*.eml') ?: [], $inainte) as $fisier) {
        $eml = (string) file_get_contents($fisier);
        @unlink($fisier);
        $cap = (string) (preg_split("/\r?\n\r?\n/", $eml, 2)[0] ?? '');
        foreach (['abonat@example.invalid', 'refuz@example.invalid'] as $adresa) {
            if (stripos($cap, $adresa) !== false) {
                $rez[$adresa] = $decodeazaEml($eml);
            }
        }
    }
    return $rez;
};
$urmarit = static fn (string $html): array => [str_contains($html, '/newsletter/track/open/'), str_contains($html, '/newsletter/track/click/')];
$cUrm = $campanieUrm('Proba urmarire');
$mUrm = $trimiteCampania($cUrm);
\App\Support\Settings::save($db, [NewsletterService::CHEIE_NIVEL_URMARIRE => 'basic']);
$cBasic = $campanieUrm('Proba basic');
$mBasic = $trimiteCampania($cBasic);
\App\Support\Settings::save($db, [NewsletterService::CHEIE_NIVEL_URMARIRE => 'full']);
$verifica('E-LEG-01: campania site-ului: abonatul cu urmărirea refuzată fără pixel și fără linkuri urmărite, ceilalți cu ele; cu nivelul MailPoet „basic”, '
    . 'nimeni urmărit (emailurile trimise, citite din captura de dezvoltare)', array_keys($mUrm) == ['abonat@example.invalid', 'refuz@example.invalid']
    && $urmarit($mUrm['abonat@example.invalid'] ?? '') === [true, true] && $urmarit($mUrm['refuz@example.invalid'] ?? '') === [false, false]
    && str_contains($mUrm['refuz@example.invalid'] ?? '', 'href="https://pensiune-proba.test/oferta/"') && count($mBasic) === 2
    && $urmarit($mBasic['abonat@example.invalid'] ?? '') === [false, false] && $urmarit($mBasic['refuz@example.invalid'] ?? '') === [false, false],
    (string) json_encode([array_keys($mUrm), array_map($urmarit, $mUrm), array_map($urmarit, $mBasic)]));
// Adresele urmărite ale CMS-ului (un link vechi, semnat): pentru cel care a refuzat, nici deschiderea, nici clicul nu se notează.
preg_match('#/newsletter/track/open/\d+/\d+/([A-Za-z0-9_-]+)#', NewsletterService::injectTrackingPixel('<p>x</p>', $cUrm, $id(19), 'http://x'), $mo19);
preg_match('#/newsletter/track/click/\d+/\d+/([A-Za-z0-9_-]+)\?url=([^"]+)#', html_entity_decode(NewsletterService::injectClickTracking('<a href="https://pensiune-proba.test/oferta/">o</a>',
    $cUrm, $id(19), 'http://x'), ENT_QUOTES | ENT_HTML5), $mc19);
$deschideri = static fn (int $c): int => (int) $db->query('SELECT COUNT(*) FROM newsletter_campaign_opens WHERE campaign_id = ' . $c)->fetchColumn();
$clicuriC = static fn (int $c): int => (int) $db->query('SELECT COUNT(*) FROM newsletter_campaign_clicks WHERE campaign_id = ' . $c)->fetchColumn();
$d0 = $deschideri($cUrm);
$k0 = $clicuriC($cUrm);
$gif19 = NewsletterService::handleOpenTrack($db, $cUrm, $id(19), (string) ($mo19[1] ?? ''));
$tinta19 = NewsletterService::handleClickTrack($db, $cUrm, $id(19), (string) ($mc19[1] ?? ''), rawurldecode((string) ($mc19[2] ?? '')));
$verifica('E-LEG-01: deschiderea și clicul semnate ale abonatului cu urmărirea refuzată: GIF-ul și adresa, nimic notat', strlen($gif19) > 0
    && $tinta19 === 'https://pensiune-proba.test/oferta/' && $deschideri($cUrm) === $d0 && $clicuriC($cUrm) === $k0, (string) json_encode([$tinta19, $d0, $deschideri($cUrm), $k0, $clicuriC($cUrm)]));
// E-LEG-01-R1: clicul semnat spune și dacă a fost urmărit (NewsletterService::clicUrmarit), ca serverul să nu pună cookie-ul atribuirii
// (nl_cid) cuiva care a refuzat urmărirea: pe un site cu MailPoet, false pentru el și true pentru ceilalți; pe un site fără MailPoet
// (urmarire_refuzata e doar a MailPoet), true pentru oricine, ca înainte.
$clic19 = NewsletterService::clicUrmarit($db, $cUrm, $id(19), (string) ($mc19[1] ?? ''), rawurldecode((string) ($mc19[2] ?? '')));
preg_match('#/newsletter/track/click/\d+/\d+/([A-Za-z0-9_-]+)\?url=([^"]+)#', html_entity_decode(NewsletterService::injectClickTracking('<a href="https://pensiune-proba.test/oferta/">o</a>',
    $cUrm, $id(11), 'http://x'), ENT_QUOTES | ENT_HTML5), $mc11);
$clic11 = NewsletterService::clicUrmarit($db, $cUrm, $id(11), (string) ($mc11[1] ?? ''), rawurldecode((string) ($mc11[2] ?? '')));
DateSite::reseteaza($lucru . '/pachet-fara');
WpCompat::reseteaza();
$clic19Fara = NewsletterService::clicUrmarit($db, $cUrm, $id(19), (string) ($mc19[1] ?? ''), rawurldecode((string) ($mc19[2] ?? '')));
DateSite::reseteaza($lucru . '/pachet');
WpCompat::reseteaza();
Tema::reseteaza();
$clicGresit = NewsletterService::clicUrmarit($db, $cUrm, $id(19), 'gresit', 'https://pensiune-proba.test/oferta/');
$verifica('E-LEG-01-R1: clicul semnat: cu MailPoet, nemarcat „urmărit” pentru cine a refuzat urmărirea, marcat pentru ceilalți; fără MailPoet, marcat pentru '
    . 'oricine (ca înainte); o semnătură greșită → null', $clic19 === ['destinatie' => 'https://pensiune-proba.test/oferta/', 'urmarit' => false]
    && $clic11 === ['destinatie' => 'https://pensiune-proba.test/oferta/', 'urmarit' => true] && ($clic19Fara['urmarit'] ?? null) === true && $clicGresit === null
    && WpCompat::mailpoet(), (string) json_encode([$clic19, $clic11, $clic19Fara, $clicGresit]));
foreach ([$cUrm, $cBasic] as $cSters) {
    foreach (['newsletter_campaign_opens', 'newsletter_campaign_clicks', 'newsletter_campaign_sends'] as $tabel) {
        $db->prepare('DELETE FROM ' . $tabel . ' WHERE campaign_id = ?')->execute([$cSters]);
    }
    $db->prepare('DELETE FROM newsletter_campaigns WHERE id = ?')->execute([$cSters]);
}
$verifica('momentul „abonare confirmată”: punctul închis abonare.confirmata (o pistă se leagă fără să schimbe codul)', in_array(NewsletterService::PUNCT_ABONARE_CONFIRMATA, Hooks::PUNCTE, true));

// ---------------------------------------------------------------------------------------------------------------------
$port = (int) (getenv('CMS_DEV_PORT') ?: 8701) + 40;
// Limita routerului (60 pe minut pe rețea) se golește înaintea fiecărei cereri, afară de cazul limitei.
$golesteLimita = true;
$cerere = static function (string $cale, string $metoda = 'GET', ?array $campuri = null, array $antete = []) use (&$port, &$golesteLimita, $db): array {
    if ($golesteLimita) {
        $db->exec("DELETE FROM register_submit_logs WHERE status = 'l:mailpoet'");
    }
    $c = curl_init('http://127.0.0.1:' . $port . $cale);
    $primite = [];
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60,
        CURLOPT_PATH_AS_IS => true, CURLOPT_HTTPHEADER => $antete, CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_NOBODY => $metoda === 'HEAD',
        CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$primite): int {
            if (str_contains($linie, ':')) {
                [$n, $v] = explode(':', $linie, 2);
                $primite[strtolower(trim($n))] = trim($v);
            }
            return strlen($linie);
        }]);
    if ($metoda === 'POST') {
        curl_setopt($c, CURLOPT_POSTFIELDS, $campuri !== null ? http_build_query($campuri) : '');
    }
    $corp = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $cod, 'antete' => $primite, 'corp' => $corp];
};
$pornesteServer = static function () use ($radacina, $lucru, $dosarDev, $baza, &$port, $cerere, &$serverePornite): bool {
    $ini = [];
    // sendmail_path: emailurile serverului de probă ajung în captura de dezvoltare (tools/dev/mail-capturat.sh), ca în procesul testului.
    foreach (['pdo_mysql.default_socket', 'error_reporting', 'display_errors', 'log_errors', 'error_log', 'memory_limit', 'sendmail_path'] as $cheie) {
        $v = ini_get($cheie);
        if ($v !== false && $v !== '') {
            $ini[] = '-d ' . escapeshellarg($cheie . '=' . $v);
        }
    }
    $mediu = ['PHP_CLI_SERVER_WORKERS' => '2', 'DB_DATABASE' => $baza, 'SITE_DATE_DOSAR' => $lucru . '/pachet', 'LICENTA_DOSAR' => $lucru . '/licenta',
        'APP_URL' => 'http://127.0.0.1:' . $port, 'APP_KEY' => 'cheie-de-proba-mailpoet', 'CMS_DEV_SITE_DIR' => $lucru . '/site', 'CMS_DEV_PUBLIC_DIR' => $radacina . '/public'];
    $env = '';
    foreach ($mediu as $n => $v) {
        $env .= ' ' . $n . '=' . escapeshellarg($v);
    }
    $pid = (int) trim((string) shell_exec('cd ' . escapeshellarg($radacina) . ' && env' . $env . ' nohup ' . escapeshellarg(PHP_BINARY) . ' ' . implode(' ', $ini)
        . ' -S 127.0.0.1:' . $port . ' -t public tools/dev/lib/router-regiuni.php >> ' . escapeshellarg($dosarDev . '/server-mailpoet-' . $port . '.log') . ' 2>&1 & echo $!'));
    $serverePornite[$port] = $pid;
    for ($i = 0; $i < 60; $i++) {
        if ($cerere('/health')['cod'] === 200) {
            return true;
        }
        usleep(200000);
    }
    return false;
};
$licenta = static function (string ...$arg) use ($radacina, $lucru): bool {
    exec('env CMS_DEV_LICENTA_DOSAR=' . escapeshellarg($lucru . '/licenta') . ' ' . escapeshellarg($radacina . '/tools/dev/php.sh') . ' '
        . escapeshellarg($radacina . '/tools/dev/licenta.php') . ' ' . implode(' ', array_map('escapeshellarg', $arg)) . ' 2>&1', $o, $cod);
    return $cod === 0;
};
$verifica('licența de dezvoltare „ateliere” (newsletter pornit, statisticile oprite)', $licenta('ateliere', 'statistici=oprit'));
// Formularul de gestionare în proces (cu licența scrisă): evenimentele abonare.confirmata și emailul „Abonat nou” (setarea importată,
// pornită cu adresa ei), ca Manage::onSave al lui MailPoet. Starea se reface la începutul fiecărei runde (aduceLaZi).
Licenta::reseteaza(true);
Plugins::reseteaza();
$evenimente = [];
Hooks::adauga(NewsletterService::PUNCT_ABONARE_CONFIRMATA, 'newsletter', static function (PDO $d, int $abonat, int $listaCms, string $sursa) use (&$evenimente): void {
    $evenimente[] = [$abonat, $listaCms, $sursa];
});
$abonatNou = static fn (): int => (int) $db->query("SELECT COUNT(*) FROM email_send_history WHERE email_type = 'newsletter_abonat_nou'")->fetchColumn();
$inainteNou = $abonatNou();
$r13 = Abonati::actualizeaza($db, $ab(13), [$campuriR['status'] => 'subscribed', $campuriR['segments'] => ['104']], $campuriR);
$ev13 = $evenimente;
$evenimente = [];
$r20a = Abonati::actualizeaza($db, $ab(20), [$campuriR['status'] => 'subscribed', $campuriR['segments'] => ['104']], $campuriR);
$ev20a = $evenimente;
$evenimente = [];
$r20b = Abonati::actualizeaza($db, $ab(20), [$campuriR['status'] => 'subscribed', $campuriR['segments'] => ['104']], $campuriR);
$ev20b = $evenimente;
$evenimente = [];
// Un inactiv cu lista ascunsă 103 abonată bifează lista vizibilă 104: evenimentul doar pentru rândul nou (104), emailul cu toate listele abonate
// acum, în ordinea id-urilor MailPoet (103, apoi 104), ca Manage::updateSubscriptions la o reabonare globală.
$r14 = Abonati::actualizeaza($db, $ab(14), [$campuriR['status'] => 'subscribed', $campuriR['segments'] => ['104']], $campuriR);
$subiect14 = (string) $db->query("SELECT subject FROM email_send_history WHERE email_type = 'newsletter_abonat_nou' ORDER BY id DESC LIMIT 1")->fetchColumn();
$verifica('gestionarea: evenimentul doar pentru listele vizibile bifate al căror rând nu era abonat (și la o reabonare globală, nu pentru listele '
    . 'ascunse); aceeași salvare din nou → niciunul; emailul „Abonat nou”: la reabonarea globală toate listele abonate acum (și cele ascunse), '
    . 'altfel listele nou bifate', $r13 === 'succes'
    && $ev13 === [[$id(13), $lista(104), 'manage']] && $r20a === 'succes' && $ev20a === [[$id(20), $lista(104), 'manage']] && $r20b === 'succes' && $ev20b === []
    && $r14 === 'succes' && $evenimente === [[$id(14), $lista(104), 'manage']]
    && $subiect14 === sprintf(\App\Support\MailPoetCompat\Texte::ABONAT_NOU, 'Lista principală de probă, Lista vizibilă de probă')
    && $abonatNou() === $inainteNou + 3, json_encode([$ev13, $ev20a, $ev20b, $evenimente, $abonatNou() - $inainteNou, $subiect14 === '' ? '' : 'subiect', NewsletterService::defaultListId($db)]));
// Doar id-uri ale unor liste ascunse: reabonarea globală se face, dar listele, evenimentele și emailul nu (ca Manage::updateSubscriptions,
// care iese înaintea emailului).
$evenimente = [];
$inainteNou3 = $abonatNou();
$stari18 = Abonati::stariListe($db, $id(18));
$r18 = Abonati::actualizeaza($db, $ab(18), [$campuriR['status'] => 'subscribed', $campuriR['segments'] => ['105']], $campuriR);
$verifica('gestionarea cu doar o listă ascunsă bifată: abonat din nou, listele neatinse, niciun eveniment, niciun email', $r18 === 'succes'
    && $ab(18)['status'] === 'active' && Abonati::stariListe($db, $id(18)) === $stari18 && $evenimente === [] && $abonatNou() === $inainteNou3,
    json_encode([$r18, $ab(18)['status'], $evenimente, $abonatNou() - $inainteNou3]));
$verifica('serverul cu wp-compat → mailpoet pornește', $pornesteServer());
$baza0 = 'http://127.0.0.1:' . $port;

$aduceLaZi = static function () use ($db, $planMp): void {
    // Starea de pornire a fiecărei runde: abonații, listele și dezabonările din fixtură, fără limitele rundei de dinainte. O aplicare
    // nouă nu scrie peste deciziile luate pe site-ul nou (E-LEG-03: dezabonările, acordurile și schimbările rundei de dinainte), așa că
    // proba le uită întâi: dezabonările de pe site se șterg, iar rândurile importate par scrise înaintea datelor MailPoet, fără nicio
    // retragere sau acord de pe site (ca înaintea unui prim import); aplicarea le scrie apoi pe toate din plan.
    $db->exec('DELETE FROM newsletter_dezabonari WHERE mailpoet_id IS NULL');
    $db->exec("UPDATE newsletter_subscribers SET status = 'active', confirmed_at = NULL, last_subscribed_at = NULL, updated_at = '2000-01-01 00:00:00'"
        . ' WHERE mailpoet_id IS NOT NULL');
    $db->exec("UPDATE newsletter_list_subscribers ls INNER JOIN newsletter_lists l ON l.id = ls.list_id SET ls.created_at = '2000-01-01 00:00:00', ls.updated_at = NULL"
        . ' WHERE l.mailpoet_id IS NOT NULL');
    $db->beginTransaction();
    ScriereMailPoet::aplica($db, $planMp);
    $db->commit();
    $db->exec('DELETE FROM register_submit_logs');
    $db->exec('DELETE FROM newsletter_mailpoet_nepotriviri');
    $db->exec("DELETE FROM newsletter_cereri_confirmare WHERE tip = 'notificare'");
    $db->exec('DELETE FROM newsletter_campaign_clicks WHERE ip IS NOT NULL OR clicked_at > NOW() - INTERVAL 1 DAY');
};
$router = static fn (string $endpoint, string $actiune, ?array $date, string $extra = ''): string => '/?mailpoet_router=&endpoint=' . $endpoint . '&action=' . $actiune
    . ($date !== null ? '&data=' . Date::codifica($date) : '') . $extra;
$abonare = static fn (string $actiune, ?array $date, string $extra = ''): string => '/?mailpoet_page=subscriptions&mailpoet_router&endpoint=subscription&action='
    . $actiune . ($date !== null ? '&data=' . Date::codifica($date) : '') . $extra;
$antetePagina = static fn (array $r): bool => ($r['antete']['cache-control'] ?? '') === 'no-cache, must-revalidate, max-age=0, no-store, private'
    && ($r['antete']['expires'] ?? '') === 'Wed, 11 Jan 1984 05:00:00 GMT' && ($r['antete']['pragma'] ?? '') === 'no-cache';

foreach (['pornit', 'oprit'] as $runda) {
    $scrie('');
    $scrie('== 2. Pe server, cu pluginul newsletter ' . $runda . ' (routerul răspunde mereu)');
    if ($runda === 'oprit') {
        $verifica('licența cu newsletter oprit', $licenta('ateliere', 'statistici=oprit', 'newsletter=oprit'));
    }
    $aduceLaZi();
    $e = "[$runda] ";
    $r = $cerere($router('foo', 'bar', []));
    $verifica($e . 'capăt necunoscut → 404, corpul gol, antetele routerului', $r['cod'] === 404 && $r['corp'] === '' && $antetePagina($r), json_encode($r['antete']));
    $r = $cerere($router('subscription', 'Manage', []));
    $verifica($e . 'acțiunea cu altă majusculă → 404 gol (potrivire exactă)', $r['cod'] === 404 && $r['corp'] === '');
    $r = $cerere($abonare('manage', null, '&data=%25%25nu'));
    $verifica($e . 'data stricată = [] → pagina F (404, corpul ei), noindex', $r['cod'] === 404 && str_contains($r['corp'], 'Hmmm... nu avem o înregistrare despre tine.')
        && str_contains((string) ($r['antete']['x-robots-tag'] ?? ''), 'noindex'));
    $r = $cerere($abonare('manage', ['preview' => 1]));
    $verifica($e . 'previzualizarea administrării → 200, adresa demo', $r['cod'] === 200 && str_contains($r['corp'], 'demo@mailpoet.com') && $antetePagina($r));
    // E-PAR-05: butonul paginii D în previzualizare: pagina C a previzualizării, 200, fără să caute pe cineva și fără să scrie ceva.
    $dezabonariInainte = (int) $db->query('SELECT COUNT(*) FROM newsletter_dezabonari')->fetchColumn();
    $r = $cerere($abonare('unsubscribe', ['preview' => 1]), 'POST', ['type' => 'confirmation']);
    $verifica($e . 'E-PAR-05: POST type=confirmation în previzualizare → 200, pagina C a previzualizării, nimic scris', $r['cod'] === 200
        && str_contains($r['corp'], 'Acum ești dezabonat.') && str_contains($r['corp'], 'disponibilă doar abonaților')
        && (int) $db->query('SELECT COUNT(*) FROM newsletter_dezabonari')->fetchColumn() === $dezabonariInainte, $r['cod'] . ' ' . mb_substr(strip_tags($r['corp']), 0, 300));
    $r1 = $cerere($abonare('unsubscribe', null, '&preview=1'));
    $r2 = $cerere($abonare('unsubscribe', ['preview' => 0]));
    $r3 = $cerere($abonare('unsubscribe', ['preview' => 1, 'token' => null]));
    $verifica($e . 'E-PAR-05: &preview=1 fără data → D; {"preview":0} → D; {"preview":1,"token":null} → C', str_contains($r1['corp'], 'Confirmă că vrei să te dezabonezi')
        && str_contains($r2['corp'], 'Confirmă că vrei să te dezabonezi') && str_contains($r3['corp'], 'Acum ești dezabonat.'));
    // E-LEG-07 (VC-MP-23): paginile routerului (cadrul R, cu adresa de email și jetonul în adresa paginii) fără trackerele site-ului.
    $rAcasa = $cerere('/');
    $rManage = $cerere($abonare('manage', ['preview' => 1]));
    $rDezCadru = $cerere($abonare('unsubscribe', ['queueId' => 41, 'token' => $jeton20, 'email' => 'majuscule.proba@example.invalid']));
    $rP = $cerere('/?mailpoet_page=subscriptions');
    $verifica($e . 'E-LEG-07: trackerele (ax-date → ax_urmarire) pe prima pagină, nu pe paginile routerului (cadrul R servit, cu antetul capturat)',
        str_contains($rAcasa['corp'], '"ax_urmarire":[{"fel":"gtag"') && str_contains($rManage['corp'], 'id="menu-principal"') && str_contains($rManage['corp'], 'id="ax-date"')
        && !str_contains($rManage['corp'], 'ax_urmarire') && !str_contains($rManage['corp'], 'GT-PROBA0001') && str_contains($rDezCadru['corp'], 'id="menu-principal"')
        && !str_contains($rDezCadru['corp'], 'ax_urmarire'), json_encode([str_contains($rAcasa['corp'], 'ax_urmarire'), str_contains($rManage['corp'], 'menu-principal'),
            str_contains($rManage['corp'], 'ax_urmarire')]));
    // E-LEG-08 (VC-MP-25): cadrele P și R servite fără autor; data rămâne. Pe cadrul P nicio adresă de email.
    $faraAutor = static fn (string $c): bool => !str_contains($c, '/author/') && !str_contains($c, 'autor-proba') && !str_contains($c, 'author vcard')
        && str_contains($c, '<p class="post-meta"><span class="published">ian. 1, 2024</span></p>');
    $verifica($e . 'E-LEG-08: cadrele P și R fără autor (nicio legătură /author/, niciun nume sau adresă a lui, data rămâne); cadrul P fără niciun text de forma unei adrese',
        $faraAutor($rP['corp']) && $faraAutor($rManage['corp']) && $faraAutor($rDezCadru['corp']) && !str_contains($rP['corp'], '@example.invalid')
        && preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+/', strip_tags($rP['corp'])) !== 1 && !str_contains($rManage['corp'], '@example.invalid'),
        json_encode([$faraAutor($rP['corp']), $faraAutor($rManage['corp']), $faraAutor($rDezCadru['corp'])]));
    $r = $cerere($abonare('manage', ['token' => $jeton11 . 'zz', 'email' => 'Abonat@Example.Invalid']));
    $verifica($e . 'administrarea cu jetonul de 32 + sufix și adresa cu majuscule → 200, formularul cu adresa', $r['cod'] === 200
        && str_contains($r['corp'], '<strong>abonat@example.invalid</strong>'));
    $r = $cerere($abonare('manage', ['token' => 12345, 'email' => 'abonat@example.invalid']));
    $verifica($e . 'jetonul număr → pagina F', $r['cod'] === 404 && str_contains($r['corp'], 'Hmmm...'));
    $db->exec("UPDATE settings SET `value` = '" . (time() + 86400) . "' WHERE `key` = 'mailpoet_jetoane_vechi_pana_la'");
    $r = $cerere($abonare('manage', ['token' => 'a1b2c3', 'email' => 'neconfirmat@example.invalid']));
    $db->exec("UPDATE settings SET `value` = '" . (time() - 60) . "' WHERE `key` = 'mailpoet_jetoane_vechi_pana_la'");
    $r2 = $cerere($abonare('manage', ['token' => 'a1b2c3', 'email' => 'neconfirmat@example.invalid']));
    $verifica($e . 'primele 6 ale jetonului actualizat: trec în grație, nu după', $r['cod'] === 200 && $r2['cod'] === 404);
    // Dezabonarea.
    $date11 = ['queueId' => 41, 'token' => $jeton11, 'email' => 'abonat@example.invalid'];
    $r = $cerere($abonare('unsubscribe', $date11));
    $verifica($e . 'GET unsubscribe → pagina D cu adresa, nimic schimbat', $r['cod'] === 200 && str_contains($r['corp'], 'Confirmă că vrei să te dezabonezi')
        && str_contains($r['corp'], 'abonat@example.invalid') && $ab(11)['status'] === 'active');
    $r = $cerere($abonare('unsubscribe', $date11, '&request_method=POST'));
    $verifica($e . 'GET cu request_method=POST → tot pagina D, nimic schimbat (VC-MP-17)', $r['cod'] === 200 && $ab(11)['status'] === 'active');
    $r = $cerere($abonare('unsubscribe', $date11, '&request_method=POST'), 'POST', ['type' => 'confirmation']);
    $randuri11 = $db->query('SELECT l.mailpoet_id, ls.status FROM newsletter_list_subscribers ls INNER JOIN newsletter_lists l ON l.id = ls.list_id WHERE ls.subscriber_id = '
        . $id(11) . ' AND l.mailpoet_id IS NOT NULL ORDER BY 1')->fetchAll(PDO::FETCH_KEY_PAIR);
    $verifica($e . 'POST type=confirmation → 303 la adresa GET, dezabonat, listele dezabonate, un rând în newsletter_dezabonari (sursa newsletter, link)',
        $r['cod'] === 303 && str_contains((string) ($r['antete']['location'] ?? ''), 'action=unsubscribe') && $ab(11)['status'] === 'unsubscribed'
        && $randuri11 === [103 => 'unsubscribed', 104 => 'unsubscribed'] && (int) $db->query("SELECT COUNT(*) FROM newsletter_dezabonari WHERE abonat_id = " . $id(11)
            . " AND metoda = 'link' AND coada_mailpoet_id = 41 AND created_at > NOW() - INTERVAL 1 HOUR")->fetchColumn() === 1, json_encode([$r['cod'], $randuri11]));
    $r = $cerere($abonare('unsubscribe', $date11));
    $verifica($e . 'GET după dezabonare → pagina C, cu legătura de administrare și sondajul', $r['cod'] === 200 && str_contains($r['corp'], 'Acum ești dezabonat.')
        && str_contains($r['corp'], 'mailpoet_unsubscribed_content') && str_contains($r['corp'], 'name="reason"'));
    // Sondajul: nonce-ul din pagină, apoi motivul.
    preg_match('/name="_wpnonce" value="([0-9a-f]+)"/', $r['corp'], $mn);
    $rs = $cerere($router('subscription', 'unsubscribeReason', $date11), 'POST', ['_wpnonce' => $mn[1] ?? '', 'reason' => 'spam']);
    $verifica($e . 'sondajul: POST cu nonce-ul paginii → 302 la …&unsubscribe_reason_saved=1, motivul salvat', $rs['cod'] === 302
        && str_contains((string) ($rs['antete']['location'] ?? ''), 'unsubscribe_reason_saved=1') && (string) $db->query('SELECT motiv FROM newsletter_dezabonari WHERE abonat_id = '
            . $id(11) . ' ORDER BY id DESC LIMIT 1')->fetchColumn() === 'spam');
    $rs = $cerere($router('subscription', 'unsubscribeReason', $date11));
    $verifica($e . 'GET unsubscribeReason → 302 la prima pagină', $rs['cod'] === 302 && rtrim((string) ($rs['antete']['location'] ?? ''), '/') === $baza0);
    $rs = $cerere($router('subscription', 'unsubscribeReason', $date11), 'POST', ['_wpnonce' => 'gresit', 'reason' => 'spam']);
    $verifica($e . 'sondajul cu nonce greșit → 403', $rs['cod'] === 403);
    // E-PAR-01: după Referrer-Policy: no-referrer, un browser fără Sec-Fetch-Site trimite la propriul formular Origin: null; cererea
    // trece la nonce. Cu Sec-Fetch-Site: cross-site, sau de pe altă origine, tot 403.
    $rs = $cerere($router('subscription', 'unsubscribeReason', $date11), 'POST', ['_wpnonce' => $mn[1] ?? '', 'reason' => 'normal'], ['Origin: null']);
    $motiv = static fn (): string => (string) $db->query('SELECT motiv FROM newsletter_dezabonari WHERE abonat_id = ' . $id(11) . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
    $motivNull = $motiv();
    $rx = $cerere($router('subscription', 'unsubscribeReason', $date11), 'POST', ['_wpnonce' => $mn[1] ?? '', 'reason' => 'spam'], ['Origin: null', 'Sec-Fetch-Site: cross-site']);
    $ry = $cerere($router('subscription', 'unsubscribeReason', $date11), 'POST', ['_wpnonce' => $mn[1] ?? '', 'reason' => 'spam'], ['Origin: https://alt-site.example.invalid']);
    $verifica($e . 'E-PAR-01: sondajul cu Origin: null fără Sec-Fetch-Site → nonce-ul decide (302, motivul salvat); cu Sec-Fetch-Site: cross-site sau de pe altă origine → 403',
        $rs['cod'] === 302 && $motivNull === 'normal' && $rx['cod'] === 403 && $ry['cod'] === 403 && $motiv() === 'normal', json_encode([$rs['cod'], $motivNull, $rx['cod'], $ry['cod']]));
    $verifica($e . 'E-PAR-03/E-PAR-04: sondajul (302) și refuzul lui (403) fără cookie-ul sesiunii; refuzul cu X-Robots-Tag: noindex, nofollow și Referrer-Policy: no-referrer',
        !isset($rs['antete']['set-cookie']) && !isset($rx['antete']['set-cookie']) && ($rx['antete']['x-robots-tag'] ?? '') === 'noindex, nofollow'
        && ($rx['antete']['referrer-policy'] ?? '') === 'no-referrer', json_encode([$rs['antete'], $rx['antete']]));
    // Oprirea urmăririi, cu Origin: null (E-PAR-01): nonce-ul paginii decide.
    $date13 = ['token' => 'a1b2c3', 'email' => 'dezabonat@example.invalid'];
    $ru = $cerere($abonare('tracking_opt_out', $date13));
    preg_match('/name="_wpnonce" value="([0-9a-f]+)"/', $ru['corp'], $mu);
    $ru2 = $cerere($abonare('tracking_opt_out', $date13), 'POST', ['_wpnonce' => 'gresit'], ['Origin: null']);
    $refuzata = (int) $ab(13)['urmarire_refuzata'];
    $ru3 = $cerere($abonare('tracking_opt_out', $date13), 'POST', ['_wpnonce' => $mu[1] ?? ''], ['Origin: null']);
    $verifica($e . 'E-PAR-01: oprirea urmăririi cu Origin: null: nonce greșit → 403, nimic schimbat; nonce-ul paginii → urmărirea oprită, fără cookie-ul sesiunii',
        $ru['cod'] === 200 && $ru2['cod'] === 403 && $refuzata === 0 && in_array($ru3['cod'], [200, 302, 303], true) && (int) $ab(13)['urmarire_refuzata'] === 1
        && !isset($ru3['antete']['set-cookie']), json_encode([$ru['cod'], $ru2['cod'], $refuzata, $ru3['cod'], $ab(13)['urmarire_refuzata']]));
    // Un-clic (RFC 8058).
    $date17 = ['queueId' => 41, 'token' => $jeton17, 'email' => 'cont@example.invalid'];
    $r = $cerere($abonare('unsubscribe', $date17), 'POST', ['List-Unsubscribe' => 'One-Click']);
    $lista1 = (string) $db->query('SELECT status FROM newsletter_list_subscribers WHERE list_id = ' . $lista(101) . ' AND subscriber_id = ' . $id(17))->fetchColumn();
    $verifica($e . 'un-clic: 200 cu corpul gol, dezabonat, lista utilizatorilor WordPress rămâne', $r['cod'] === 200 && $r['corp'] === '' && $ab(17)['status'] === 'unsubscribed'
        && $lista1 === 'subscribed');
    $verifica($e . 'E-PAR-03/E-PAR-04: un-clicul (200 gol) fără cookie-ul sesiunii, cu X-Robots-Tag: noindex, nofollow și Referrer-Policy: no-referrer',
        !isset($r['antete']['set-cookie']) && ($r['antete']['x-robots-tag'] ?? '') === 'noindex, nofollow' && ($r['antete']['referrer-policy'] ?? '') === 'no-referrer',
        json_encode($r['antete']));
    $randuriInainte = (int) $db->query('SELECT COUNT(*) FROM newsletter_dezabonari')->fetchColumn();
    $cerere($abonare('unsubscribe', $date17), 'POST', ['List-Unsubscribe' => 'One-Click']);
    $verifica($e . 'a doua dezabonare: niciun rând nou', (int) $db->query('SELECT COUNT(*) FROM newsletter_dezabonari')->fetchColumn() === $randuriInainte);
    $r = $cerere($abonare('unsubscribe', ['token' => 'gresit-gresit', 'email' => 'refuz@example.invalid']), 'POST', ['List-Unsubscribe' => 'One-Click']);
    $verifica($e . 'un-clic cu jeton greșit: 200, gol, nimic schimbat', $r['cod'] === 200 && $r['corp'] === '' && $ab(19)['status'] === 'active');
    // Administrarea.
    $date20 = ['token' => $jeton20, 'email' => 'majuscule.proba@example.invalid'];
    $r = $cerere($abonare('manage', $date20));
    preg_match('/name="_ax" value="([^"]+)"/', $r['corp'], $mx);
    $verifica($e . 'GET manage: starea selectată (Abonat), lista vizibilă nebifată (abonatul e pe alta)', $r['cod'] === 200
        && str_contains($r['corp'], '<option value="subscribed" selected="selected">Abonat</option>') && str_contains($r['corp'], 'value="104"  data-parsley'));
    $post = ['action' => 'mailpoet_subscription_update', 'data' => ['email' => 'majuscule.proba@example.invalid', $campuriR['first_name'] => 'Liviu-Nou',
        $campuriR['last_name'] => 'Proba', $campuriR['status'] => 'subscribed', $campuriR['segments'] => ['104']], 'token' => $jeton20, '_ax' => $mx[1] ?? ''];
    $r = $cerere($abonare('manage', $date20), 'POST', $post);
    $verifica($e . 'POST manage: numele și lista vizibilă schimbate → 303 cu success=1', $r['cod'] === 303 && str_contains((string) ($r['antete']['location'] ?? ''), 'success=1')
        && $ab(20)['first_name'] === 'Liviu-Nou' && (string) $db->query('SELECT status FROM newsletter_list_subscribers WHERE list_id = ' . $lista(104) . ' AND subscriber_id = '
            . $id(20))->fetchColumn() === 'subscribed', json_encode([$r['cod'], $r['antete']['location'] ?? '']));
    $rn = $cerere($abonare('manage', $date20), 'POST', array_replace_recursive($post, ['data' => [$campuriR['last_name'] => 'Proba-Null']]), ['Origin: null']);
    $verifica($e . 'E-PAR-01/E-PAR-03: administrarea cu Origin: null fără Sec-Fetch-Site → nonce-ul decide (303 success=1, salvat), fără cookie-ul sesiunii',
        $rn['cod'] === 303 && str_contains((string) ($rn['antete']['location'] ?? ''), 'success=1') && $ab(20)['last_name'] === 'Proba-Null' && !isset($rn['antete']['set-cookie'])
        && !isset($r['antete']['set-cookie']), json_encode([$rn['cod'], $rn['antete']['location'] ?? '', $ab(20)['last_name']]));
    $r = $cerere($abonare('manage', $date20), 'POST', array_replace_recursive($post, ['data' => [$campuriR['first_name'] => '<b>x</b>']]));
    $verifica($e . 'POST manage cu < > în nume → error=1, nimic schimbat', str_contains((string) ($r['antete']['location'] ?? ''), 'error=1') && $ab(20)['first_name'] === 'Liviu-Nou');
    $r = $cerere($abonare('manage', $date20), 'POST', array_replace($post, ['_ax' => 'gresit']));
    $verifica($e . 'POST manage cu nonce greșit → error=1', str_contains((string) ($r['antete']['location'] ?? ''), 'error=1'));
    $r = $cerere($abonare('manage', $date20, '&error=1'));
    $r2 = $cerere($abonare('manage', $date20, '&success=1'));
    $verifica($e . 'error=1 nu arată nimic; success=1 arată „Preferințele tale au fost salvate.”', !str_contains($r['corp'], 'mailpoet-submit-success')
        && str_contains($r2['corp'], 'Preferințele tale au fost salvate.'));
    // Confirmarea. Întâi un HEAD (un scaner de linkuri, previzualizarea clientului de email): nu confirmă nimic (SEC-E-04, VC-MP-24).
    $t = time();
    $a12 = $ab(12);
    $urlConfirmare = Url::abonare($db, 'confirm', $a12, Jetoane::ax1Confirmare((int) $a12['id'], (string) $a12['email'], $t), null, false, ['t' => $t]);
    $rh = $cerere($urlConfirmare, 'HEAD');
    $verifica($e . 'SEC-E-04: HEAD pe linkul de confirmare → 200, nimic confirmat (pending, fără confirmed_at), fără cookie-ul sesiunii', $rh['cod'] === 200
        && $ab(12)['status'] === 'pending' && $ab(12)['confirmed_at'] === $a12['confirmed_at'] && !isset($rh['antete']['set-cookie']), json_encode([$rh['cod'], $ab(12)['status']]));
    // E-LEG-11: o adresă pusă între timp pe lista „nu trimite” iese de acolo la confirmare (un acord nou al omului), ca la linkul CMS-ului.
    NewsletterService::suprima($db, (string) $a12['email'], 'proba');
    $r = $cerere($urlConfirmare);
    $verifica($e . 'E-LEG-11: confirmarea prin router scoate adresa de pe lista „nu trimite”', NewsletterService::esteSuprimat($db, 'neconfirmat@example.invalid') === false
        && $ab(12)['status'] === 'active');
    $a12 = $ab(12);
    $verifica($e . 'confirmarea: pending → active, cu confirmed_ip, confirmed_at, last_subscribed_at; pagina A', $r['cod'] === 200 && $a12['status'] === 'active'
        && $a12['confirmed_ip'] === '127.0.0.1' && $a12['last_subscribed_at'] !== null && str_contains($r['corp'], 'Te-ai abonat la'),
        json_encode([$r['cod'], $a12['status'], $a12['confirmed_ip'], $a12['pending_at'], mb_substr(strip_tags($r['corp']), 0, 300)], JSON_UNESCAPED_UNICODE));
    $a13 = $ab(13);
    $r = $cerere($abonare('confirm', ['token' => 'a1b2c3', 'email' => 'dezabonat@example.invalid']));
    $verifica($e . 'confirmarea unui dezabonat cu un link vechi: pagina A, nicio schimbare (VC-MP-16)', $r['cod'] === 200 && $ab(13)['status'] === 'unsubscribed'
        && $ab(13)['confirmed_at'] === $a13['confirmed_at']);
    // Clicul și deschiderea.
    $r = $cerere($router('track', 'click', [(string) 20, $jeton20, '42', '6071829304b5', false]));
    $verifica($e . 'clic valid → 302 la adresa păstrată, notat', $r['cod'] === 302 && ($r['antete']['location'] ?? '') === 'https://pensiune-proba.test/despre/'
        && (int) $db->query('SELECT COUNT(*) FROM newsletter_campaign_clicks WHERE subscriber_id = ' . $id(20) . " AND url LIKE 'https://pensiune-proba.test/despre/%'"
            . ' AND clicked_at > NOW() - INTERVAL 1 HOUR')->fetchColumn() === 1);
    $r = $cerere($router('track', 'click', ['19', $jeton19, '41', '0a1b2c3d4e5f', false]));
    $verifica($e . 'clic cu urmărirea refuzată → 302, nenotat', $r['cod'] === 302 && (int) $db->query('SELECT COUNT(*) FROM newsletter_campaign_clicks WHERE subscriber_id = '
        . $id(19) . ' AND clicked_at > NOW() - INTERVAL 1 HOUR')->fetchColumn() === 0);
    $r = $cerere($router('track', 'click', ['20', $jeton20, '42', 'ffffffffffff', false]));
    $verifica($e . 'hash necunoscut → 302 la prima pagină', $r['cod'] === 302 && rtrim((string) ($r['antete']['location'] ?? ''), '/') === $baza0);
    $r = $cerere($router('track', 'click', ['20', 'gresit', '42', '6071829304b5', false]));
    $verifica($e . 'jeton greșit → 404', $r['cod'] === 404);
    $r = $cerere($router('track', 'click', ['12', 'a1b2c3-0123456789abcdef012345678', '41', '0a1b2c3d4e5f', false]));
    $verifica($e . 'neprocesat în coadă → 404', $r['cod'] === 404);
    $r = $cerere($router('track', 'click', ['13', 'a1b2c3', '41', '3d4e5f607182', false]));
    $verifica($e . 'codul link:subscription_manage_url → 302 la administrarea MailPoet, cu jetonul cererii', $r['cod'] === 302
        && str_contains((string) ($r['antete']['location'] ?? ''), 'action=manage') && str_contains(base64_decode(strtr((string) preg_replace('/^.*data=/', '',
            (string) ($r['antete']['location'] ?? '')), '-_', '+/')) ?: '', 'a1b2c3'));
    $r = $cerere($router('track', 'click', ['19', $jeton19, '41', '1b2c3d4e5f60', false]), 'POST', ['List-Unsubscribe' => 'One-Click']);
    $verifica($e . 'POST pe codul de dezabonare → 200 gol, dezabonat (VC-MP-01), cu antetele un-clicului (noindex, no-referrer; E-PAR-04), fără cookie-ul sesiunii',
        $r['cod'] === 200 && $r['corp'] === '' && $ab(19)['status'] === 'unsubscribed' && ($r['antete']['x-robots-tag'] ?? '') === 'noindex, nofollow'
        && ($r['antete']['referrer-policy'] ?? '') === 'no-referrer' && !isset($r['antete']['set-cookie']), json_encode($r['antete']));
    $r = $cerere($router('track', 'open', ['20', 'gresit', '42', null, false]));
    $r2 = $cerere($router('track', 'open', ['20', $jeton20, '42', null, false]));
    $verifica($e . 'deschiderea: mereu GIF-ul de 43 de octeți (și cu jeton greșit)', $r['cod'] === 200 && strlen($r['corp']) === 43 && $r2['cod'] === 200 && strlen($r2['corp']) === 43
        && str_starts_with($r['corp'], 'GIF89a'));
    $a14 = $ab(14);
    $ax14 = Jetoane::ax1($id(14), 'inactiv@example.invalid');
    // E-LEG-04: un clic pe un link al abonamentului (administrarea, dezabonarea) se notează ca implicare, dar nu reactivează un inactiv.
    $rc1 = $cerere($router('track', 'click', ['14', $ax14, '41', '3d4e5f607182', false]));
    $rc2 = $cerere($router('track', 'click', ['14', $ax14, '41', '1b2c3d4e5f60', false]));
    $verifica($e . 'E-LEG-04: clicul unui inactiv pe administrare și pe dezabonare → 302 la paginile lor, rămâne inactiv (implicarea notată)', $rc1['cod'] === 302
        && str_contains((string) ($rc1['antete']['location'] ?? ''), 'action=manage') && $rc2['cod'] === 302
        && str_contains((string) ($rc2['antete']['location'] ?? ''), 'action=confirm_unsubscribe') && $ab(14)['status'] === 'inactive'
        && $ab(14)['last_engagement_at'] !== $a14['last_engagement_at'], json_encode([$rc1['cod'], $rc2['cod'], $ab(14)['status']]));
    $db->prepare('UPDATE newsletter_subscribers SET last_engagement_at = ? WHERE id = ?')->execute([$a14['last_engagement_at'], $id(14)]);
    $r = $cerere($router('track', 'open', ['14', $ax14, '41', null, false]));
    $verifica($e . 'deschiderea unui abonat inactiv (jetonul CMS): redevine abonat, implicarea notată, last_subscribed_at neschimbat (ca UPDATE-ul lui MailPoet)',
        $r['cod'] === 200 && $a14['status'] === 'inactive' && $ab(14)['status'] === 'active' && $ab(14)['last_subscribed_at'] === $a14['last_subscribed_at']
        && $ab(14)['last_engagement_at'] !== $a14['last_engagement_at'], json_encode([$a14['status'], $ab(14)['status'], $a14['last_subscribed_at'], $ab(14)['last_subscribed_at']]));
    // E-LEG-01-R1: clicul pe un link urmărit al CMS-ului (o campanie trimisă de site înainte ca abonatul să refuze urmărirea): cine a
    // refuzat-o ajunge la adresă fără cookie-ul atribuirii comenzilor (nl_cid), ca la MailPoet, care nu pune niciun cookie fără acordul
    // urmăririi; ceilalți îl primesc ca înainte (spec. mailpoet, rândul 30: lista cookie-urilor, cu pista F).
    $db->exec("INSERT INTO newsletter_campaigns (name, subject, html_content, status) VALUES ('Proba nl_cid', 'Proba nl_cid', '<p>x</p>', 'sent')");
    $cNl = (int) $db->lastInsertId();
    $clicCms = static function (int $abonat) use ($cNl, $baza0): array {
        $html = NewsletterService::injectClickTracking('<a href="https://pensiune-proba.test/oferta/">o</a>', $cNl, $abonat, $baza0);
        preg_match('/href="([^"]+)"/', $html, $m);
        $c = curl_init(html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5));
        $cookie = [];
        $locatie = '';
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$cookie, &$locatie): int {
                if (stripos($linie, 'set-cookie:') === 0) {
                    $cookie[] = trim(substr($linie, 11));
                } elseif (stripos($linie, 'location:') === 0) {
                    $locatie = trim(substr($linie, 9));
                }
                return strlen($linie);
            }]);
        curl_exec($c);
        $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        curl_close($c);
        return ['cod' => $cod, 'locatie' => $locatie, 'nl_cid' => array_values(array_filter($cookie, static fn (string $x): bool => str_starts_with($x, 'nl_cid=')))];
    };
    $k19 = $clicCms($id(19));
    $k11 = $clicCms($id(11));
    foreach (['newsletter_campaign_opens', 'newsletter_campaign_clicks', 'newsletter_campaign_sends'] as $tabel) {
        $db->prepare('DELETE FROM ' . $tabel . ' WHERE campaign_id = ?')->execute([$cNl]);
    }
    $db->prepare('DELETE FROM newsletter_campaigns WHERE id = ?')->execute([$cNl]);
    $verifica($e . 'E-LEG-01-R1: clicul pe un link urmărit al CMS-ului: cine a refuzat urmărirea → 302 la adresă, fără cookie-ul nl_cid; un abonat care '
        . 'n-a refuzat-o → 302 și nl_cid, ca înainte', $k19['cod'] === 302 && $k19['locatie'] === 'https://pensiune-proba.test/oferta/' && $k19['nl_cid'] === []
        && $k11['cod'] === 302 && $k11['locatie'] === 'https://pensiune-proba.test/oferta/' && count($k11['nl_cid']) === 1
        && str_starts_with($k11['nl_cid'][0], 'nl_cid=' . $cNl . ';'), json_encode([$k19, $k11]));
    // Vezi în browser.
    $r = $cerere($router('view_in_browser', 'view', [31, 'aa11bb22cc33', 0, 0, 41, 0]));
    $verifica($e . 'vezi în browser, anonim: 200, linkurile adresele lor, pixelul scos, prenumele implicit, CSP-ul arhivei', $r['cod'] === 200
        && str_contains($r['corp'], 'https://pensiune-proba.test/oferta/') && !str_contains($r['corp'], 'mailpoet_open_data') && str_contains($r['corp'], 'Salut prieten')
        && str_contains($r['corp'], 'href="' . $baza0 . '/">Fără adresă</a>') && !str_contains($r['corp'], 'mailpoet_click_data')
        && str_starts_with((string) ($r['antete']['content-security-policy'] ?? ''), "default-src 'none'"), json_encode($r['antete']));
    $r = $cerere($router('view_in_browser', 'view', [31, 'aa11bb22cc3', 0, 0, 41, 0]));
    $verifica($e . 'hash-ul diferit → 404 gol', $r['cod'] === 404 && $r['corp'] === '');
    $r = $cerere($router('view_in_browser', 'view', [31, 'aa11bb22cc33', 20, 'gresit', 42, 0]));
    $verifica($e . 'jeton greșit al unui abonat găsit → 404 gol', $r['cod'] === 404 && $r['corp'] === '');
    $r = $cerere($router('view_in_browser', 'view', [31, 'aa11bb22cc33', 0, 0, 42, 0]));
    $verifica($e . 'coada altei campanii → 404', $r['cod'] === 404);
    $r = $cerere($router('view_in_browser', 'view', [32, 'dd44ee55ff66', 20, $jeton20, 42, 0]));
    $csp32 = (string) ($r['antete']['content-security-policy'] ?? '');
    $verifica($e . 'un abonat găsit: personalizat (prenumele lui) și urmărit (adresele de clic cu jetonul lui)', $r['cod'] === 200 && str_contains($r['corp'], 'Liviu')
        && str_contains($r['corp'], 'endpoint=track&amp;action=click'));
    $verifica($e . 'vezi în browser, un email cu font Google: legătura scoasă, CSP-ul fără fonts.googleapis.com și fonts.gstatic.com (nicio cerere la Google)',
        $r['cod'] === 200 && !str_contains($r['corp'], 'fonts.googleapis.com') && str_contains($r['corp'], '<title>Scrisoarea doi</title></head>')
        && $csp32 !== '' && !str_contains($csp32, 'googleapis') && !str_contains($csp32, 'gstatic'), $csp32);
    if ($runda === 'pornit') {
        // E-LEG-02 în admin: constructorul campaniei și pagina newsletterului fără lista internă „Toți abonații” (nu e o țintă, nu e aleasă
        // dinainte ca primă opțiune), cu listele MailPoet.
        $borcan = $lucru . '/admin-cookie.txt';
        $admin = static function (string $metoda, string $cale, array $campuri = []) use (&$port, $borcan): array {
            $c = curl_init('http://127.0.0.1:' . $port . $cale);
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60,
                CURLOPT_COOKIEJAR => $borcan, CURLOPT_COOKIEFILE => $borcan]);
            if ($metoda === 'POST') {
                curl_setopt($c, CURLOPT_POST, true);
                curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($campuri));
            }
            $corp = (string) curl_exec($c);
            $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
            curl_close($c);
            return ['cod' => $cod, 'corp' => $corp];
        };
        $admin('GET', '/admin/login');
        $logare = $admin('POST', '/admin/login', ['email' => (string) getenv('ADMIN_DEFAULT_EMAIL'), 'password' => (string) getenv('ADMIN_DEFAULT_PASSWORD')]);
        NewsletterService::defaultListId($db);
        $db->exec("INSERT INTO newsletter_campaigns (name, subject, html_content, status) VALUES ('Ciorna admin', 'Ciorna', '<p>x</p>', 'draft')");
        $ciornaAdmin = (int) $db->lastInsertId();
        $constructor = $admin('GET', '/admin/emails/builder?type=campaign&id=' . $ciornaAdmin);
        $campaniiAdmin = $admin('GET', '/admin/emails/newsletters?tab=campaigns');
        $db->prepare('DELETE FROM newsletter_campaigns WHERE id = ?')->execute([$ciornaAdmin]);
        $verifica($e . 'E-LEG-02: în admin, constructorul campaniei și lista campaniilor fără lista internă „Toți abonații” (nici aleasă dinainte), cu listele MailPoet',
            $logare['cod'] === 302 && $constructor['cod'] === 200 && $campaniiAdmin['cod'] === 200 && !str_contains($constructor['corp'], '(toată lumea)')
            && !str_contains($constructor['corp'], 'class="nlp-lista" value="' . NewsletterService::defaultListId($db) . '"')
            && !str_contains($campaniiAdmin['corp'], 'Toți abonații') && str_contains($campaniiAdmin['corp'], 'Lista vizibilă de probă')
            && str_contains($constructor['corp'], 'Lista vizibil'), json_encode([$logare['cod'], $constructor['cod'], $campaniiAdmin['cod'],
                str_contains($constructor['corp'], '(toată lumea)'), str_contains($campaniiAdmin['corp'], 'Toți abonații'), str_contains($campaniiAdmin['corp'], 'Lista vizibil'),
                str_contains($constructor['corp'], 'Lista vizibil')]));
    }
    // SEC-E-02-R1: un abonat cu jetonul MailPoet scurt (13: 6 caractere, niciodată lungit), făcut activ pentru probă. Oricine îi poate
    // aduce limita nepotrivirilor (10 în 24 de ore) cu clicuri urmărite cu jetoane greșite; atunci paginile și clicurile lui, care arată
    // dacă jetonul e bun, rămân blocate (pagina F), dar dezabonarea „cu un clic” cu jetonul bun dintr-un email vechi dezabonează pe toate
    // drumurile ei (un-clicul neurmărit, antetul urmărit sub buget și peste buget), cu același 200 gol pe care îl primește un jeton greșit
    // (care nu dezabonează pe nimeni).
    $faraData = static fn (array $x): array => [$x['cod'], $x['corp'], array_diff_key($x['antete'], ['date' => 1])];
    $activ13 = static function () use ($db): void {
        $db->exec("UPDATE newsletter_subscribers SET status = 'active' WHERE mailpoet_id = 13");
    };
    $date13Dez = ['queueId' => 41, 'token' => 'a1b2c3', 'email' => 'dezabonat@example.invalid'];
    $db->exec('DELETE FROM newsletter_mailpoet_nepotriviri');
    $activ13();
    $coduriGresite = [];
    for ($i = 0; $i < 10; $i++) {
        $coduriGresite[] = $cerere($router('track', 'click', ['13', sprintf('ff%04d', $i), '41', '3d4e5f607182', false]))['cod'];
    }
    $numar13 = (int) $db->query('SELECT COALESCE(SUM(numar), 0) FROM newsletter_mailpoet_nepotriviri WHERE abonat_id = ' . $id(13))->fetchColumn();
    $paginaManage = $cerere($abonare('manage', ['token' => 'a1b2c3', 'email' => 'dezabonat@example.invalid']));
    $paginaDez = $cerere($abonare('unsubscribe', $date13Dez));
    $clicBun = $cerere($router('track', 'click', ['13', 'a1b2c3', '41', '3d4e5f607182', false]));
    $verifica($e . 'SEC-E-02-R1: 10 clicuri urmărite cu jetoane greșite (404) aduc limita jetonului scurt: administrarea și pagina dezabonării cu jetonul bun → '
        . 'pagina F, clicul bun → 404', $coduriGresite === array_fill(0, 10, 404) && $numar13 === 10 && $paginaManage['cod'] === 404
        && str_contains($paginaManage['corp'], 'Hmmm...') && $paginaDez['cod'] === 404 && $clicBun['cod'] === 404,
        json_encode([$coduriGresite, $numar13, $paginaManage['cod'], $paginaDez['cod'], $clicBun['cod']]));
    $u1 = $cerere($abonare('unsubscribe', array_replace($date13Dez, ['token' => 'ff9999'])), 'POST', ['List-Unsubscribe' => 'One-Click']);
    $stare1 = (string) $ab(13)['status'];
    $u2 = $cerere($abonare('unsubscribe', $date13Dez), 'POST', ['List-Unsubscribe' => 'One-Click']);
    $stare2 = (string) $ab(13)['status'];
    $verifica($e . 'SEC-E-02-R1: peste limită, un-clicul cu un jeton greșit → 200 gol, nimic schimbat; cu jetonul bun → același 200 gol, dezabonat',
        $u1['cod'] === 200 && $u1['corp'] === '' && $stare1 === 'active' && $faraData($u1) === $faraData($u2) && $stare2 === 'unsubscribed',
        json_encode([$faraData($u1), $stare1, $faraData($u2), $stare2]));
    $activ13();
    $t1 = $cerere($router('track', 'click', ['13', 'ff9999', '41', '1b2c3d4e5f60', false]), 'POST', ['List-Unsubscribe' => 'One-Click']);
    $stareT1 = (string) $ab(13)['status'];
    $t2 = $cerere($router('track', 'click', ['13', 'a1b2c3', '41', '1b2c3d4e5f60', false]), 'POST', ['List-Unsubscribe' => 'One-Click']);
    $stareT2 = (string) $ab(13)['status'];
    $verifica($e . 'SEC-E-02-R1: peste limită, POST pe linkul urmărit de dezabonare: un jeton greșit → 200 gol (nu 404: nicio diferență față de cel bun), '
        . 'nimic schimbat; jetonul bun → același 200 gol, dezabonat', $t1['cod'] === 200 && $t1['corp'] === '' && $stareT1 === 'active'
        && $faraData($t1) === $faraData($t2) && $stareT2 === 'unsubscribed', json_encode([$faraData($t1), $stareT1, $faraData($t2), $stareT2]));
    // Și peste bugetul routerului (60 pe minut de pe rețea), tot cu limita jetonului scurt atinsă.
    $activ13();
    $golesteLimita = false;
    $db->exec("DELETE FROM register_submit_logs WHERE status = '" . BugetRouter::STARE . "'");
    for ($i = 0; $i < 61; $i++) {
        $cerere($router('track', 'open', ['20', $jeton20, '42', null, false]));
    }
    $b1 = $cerere($router('track', 'click', ['13', 'ff9999', '41', '1b2c3d4e5f60', false]), 'POST', ['List-Unsubscribe' => 'One-Click']);
    $stareB1 = (string) $ab(13)['status'];
    $b2 = $cerere($router('track', 'click', ['13', 'a1b2c3', '41', '1b2c3d4e5f60', false]), 'POST', ['List-Unsubscribe' => 'One-Click']);
    $stareB2 = (string) $ab(13)['status'];
    $activ13();
    $b3 = $cerere($abonare('unsubscribe', $date13Dez), 'POST', ['List-Unsubscribe' => 'One-Click']);
    $stareB3 = (string) $ab(13)['status'];
    $golesteLimita = true;
    $verifica($e . 'SEC-E-02-R1: peste buget și peste limita jetonului scurt: POST-ul urmărit greșit → 200 gol, nimic; cel bun → același 200, dezabonat; '
        . 'un-clicul neurmărit bun → 200, dezabonat', $faraData($b1) === $faraData($b2) && $b1['cod'] === 200 && $stareB1 === 'active' && $stareB2 === 'unsubscribed'
        && $b3['cod'] === 200 && $b3['corp'] === '' && $stareB3 === 'unsubscribed', json_encode([$faraData($b1), $stareB1, $stareB2, $b3['cod'], $stareB3]));
    // Sub limită, nepotrivirile un-clicului se numără ca oricare altele (nimic de ghicit pe acest drum, nici de ocolit pe celelalte).
    $db->exec('DELETE FROM newsletter_mailpoet_nepotriviri');
    $activ13();
    for ($i = 0; $i < 10; $i++) {
        $cerere($abonare('unsubscribe', array_replace($date13Dez, ['token' => sprintf('ee%04d', $i)])), 'POST', ['List-Unsubscribe' => 'One-Click']);
    }
    $dupaPost = (int) $db->query('SELECT COALESCE(SUM(numar), 0) FROM newsletter_mailpoet_nepotriviri WHERE abonat_id = ' . $id(13))->fetchColumn();
    $manageDupaPost = $cerere($abonare('manage', ['token' => 'a1b2c3', 'email' => 'dezabonat@example.invalid']));
    $verifica($e . 'SEC-E-02-R1: 10 un-clicuri cu jetoane greșite se numără (10) și blochează administrarea cu jetonul bun; abonatul rămâne activ',
        $dupaPost === 10 && $manageDupaPost['cod'] === 404 && (string) $ab(13)['status'] === 'active', json_encode([$dupaPost, $manageDupaPost['cod'],
            $ab(13)['status']]));
    $db->exec('DELETE FROM newsletter_mailpoet_nepotriviri');
    $db->exec("UPDATE newsletter_subscribers SET status = 'unsubscribed' WHERE mailpoet_id = 13");
    // Pagina simplă și limita.
    $r = $cerere('/?mailpoet_page=subscriptions');
    $verifica($e . 'pagina simplă ?mailpoet_page=subscriptions → cadrul P, cum e capturat', $r['cod'] === 200 && str_contains($r['corp'], '[mailpoet_page]'));
    $r = $cerere('/?mailpoet_page=alta-pagina-mailpoet');
    $r2 = $cerere('/?mailpoet_page=subscriptions&x=1');
    $verifica($e . 'o pagină MailPoet fără document capturat → 404; parametrii în plus nu schimbă pagina', $r['cod'] === 404
        && $r2['cod'] === 200 && str_contains($r2['corp'], '[mailpoet_page]'));
    // Formularul de abonare Divi.
    $pg = $cerere('/');
    preg_match('/"et_frontend_nonce":"([0-9a-f]{10})"/', $pg['corp'], $mnonce);
    $ra = $cerere('/_andaxi/abonare/0', 'POST', ['cale' => '/', 'et_frontend_nonce' => $mnonce[1] ?? '', 'et_pb_signup_email' => 'subsol-' . $runda . '@example.invalid']);
    $nou = Abonati::dupaAdresa($db, ['email' => 'subsol-' . $runda . '@example.invalid']);
    $verifica($e . 'E-PAR-03: trimiterea formularului de abonare (POST /_andaxi/abonare/0) nu pune cookie-ul sesiunii', $ra['cod'] === 200
        && !isset($ra['antete']['set-cookie']), json_encode($ra['antete']));
    if ($runda === 'oprit') {
        // Cu pluginul oprit nu se fac abonări noi (ca formularele opt-in ale codului comun): răspunsul `false`, textul Divi al eșecului.
        $verifica($e . 'abonarea cu pluginul oprit: false, nicio adresă scrisă', $ra['corp'] === 'false' && $nou === null, $ra['corp']);
    } else {
    $verifica($e . 'abonarea din subsol: {"success":true}, abonat pending pe lista formularului (103), sursa api, IP-ul cererii', $ra['corp'] === '{"success":true}'
        && is_array($nou) && $nou['status'] === 'pending' && $nou['subscribed_ip'] === '127.0.0.1' && (string) $db->query('SELECT status FROM newsletter_list_subscribers'
            . ' WHERE list_id = ' . $lista(103) . ' AND subscriber_id = ' . (int) $nou['id'])->fetchColumn() === 'subscribed', $ra['cod'] . ' ' . json_encode($ra['antete']) . ' ' . $ra['corp'] . ' ' . ($mnonce[1] ?? 'fără nonce')
        . ' ' . json_encode(AbonareCapturata::proceseaza($db, 0, ['cale' => '/', 'et_frontend_nonce' => CadruCapturat::nonceZi(), 'et_pb_signup_email' => 'dbg@example.invalid'], []))
        . ' ' . json_encode(AbonareCapturata::formular($db, '/', 0)) . ' ' . CadruCapturat::nonceZi());
    $rr = $cerere('/_andaxi/abonare/0', 'POST', ['cale' => '/', 'et_frontend_nonce' => $mnonce[1] ?? '', 'et_pb_signup_email' => 'subsol-' . $runda . '@example.invalid']);
    $verifica($e . 'aceeași adresă din nou, în 5 minute (limita pe adresă a confirmărilor): tot {"success":true}, fără al doilea email de confirmare',
        $rr['corp'] === '{"success":true}' && (int) $db->query("SELECT COUNT(*) FROM newsletter_cereri_confirmare WHERE email_hash = '"
            . NewsletterService::amprentaAdresa('subsol-' . $runda . '@example.invalid') . "'")->fetchColumn() === 1, $rr['corp']);
    $pa = $cerere('/atelier-proba/');
    preg_match('/"et_frontend_nonce":"([0-9a-f]{10})"/', $pa['corp'], $mnonce2);
    $ra = $cerere('/_andaxi/abonare/0', 'POST', ['cale' => '/atelier-proba/', 'et_frontend_nonce' => $mnonce2[1] ?? '', 'et_pb_signup_email' => 'atelier-' . $runda . '@example.invalid',
        'et_pb_signup_list_id' => '103']);
    $na = Abonati::dupaAdresa($db, ['email' => 'atelier-' . $runda . '@example.invalid']);
    $verifica($e . 'abonarea de pe pagina atelierului: lista din documentul capturat (104), nu din cerere', $ra['corp'] === '{"success":true}' && is_array($na)
        && (string) $db->query('SELECT status FROM newsletter_list_subscribers WHERE list_id = ' . $lista(104) . ' AND subscriber_id = ' . (int) $na['id'])->fetchColumn() === 'subscribed'
        && $db->query('SELECT status FROM newsletter_list_subscribers WHERE list_id = ' . $lista(103) . ' AND subscriber_id = ' . (int) $na['id'])->fetchColumn() === false);
    }
    $ra = $cerere('/_andaxi/abonare/0', 'POST', ['cale' => '/', 'et_frontend_nonce' => 'ffffffffff', 'et_pb_signup_email' => 'rau-' . $runda . '@example.invalid']);
    $rb = $cerere('/_andaxi/abonare/0', 'POST', ['cale' => '/', 'et_frontend_nonce' => $mnonce[1] ?? '', 'et_pb_signup_email' => 'x-' . $runda . '@example.invalid'],
        ['Origin: https://alt-site.example.invalid', 'Sec-Fetch-Site: cross-site']);
    $rc = $cerere('/_andaxi/abonare/7', 'POST', ['cale' => '/', 'et_frontend_nonce' => $mnonce[1] ?? '', 'et_pb_signup_email' => 'y-' . $runda . '@example.invalid']);
    $rd = $cerere('/_andaxi/abonare/0');
    $verifica($e . 'abonarea: nonce greșit → false; altă origine → 403; formular inexistent → false; GET → 404; nicio adresă scrisă', $ra['corp'] === 'false'
        && $rb['cod'] === 403 && $rc['corp'] === 'false' && $rd['cod'] === 404 && Abonati::dupaAdresa($db, ['email' => 'rau-' . $runda . '@example.invalid']) === null
        && Abonati::dupaAdresa($db, ['email' => 'x-' . $runda . '@example.invalid']) === null);
    // Limita: peste 60 pe minut, răspunsul unui jeton greșit (și pentru un jeton bun), afară de un-clic.
    $db->exec('DELETE FROM register_submit_logs');
    $golesteLimita = false;
    for ($i = 0; $i < 61; $i++) {
        $cerere($router('track', 'open', ['20', $jeton20, '42', null, false]));
    }
    $r = $cerere($abonare('manage', $date20));
    $r3 = $cerere($router('track', 'click', ['20', $jeton20, '42', '6071829304b5', false]));
    $r4 = $cerere($router('track', 'click', ['14', $ax14, '41', '1b2c3d4e5f60', false]), 'POST', ['List-Unsubscribe' => 'One-Click']);
    $r2 = $cerere($abonare('unsubscribe', $date20), 'POST', ['List-Unsubscribe' => 'One-Click']);
    // SEC-E-02: peste limită, orice POST pe clic primește același 200 gol (nimic aflat despre jeton): jetonul bun pe un link obișnuit, unul
    // greșit, un hash necunoscut; niciun clic notat.
    $clicuri = static fn (): int => (int) $db->query('SELECT COUNT(*) FROM newsletter_campaign_clicks WHERE clicked_at > NOW() - INTERVAL 1 HOUR')->fetchColumn();
    $clicuriInainte = $clicuri();
    $p1 = $cerere($router('track', 'click', ['20', $jeton20, '42', '6071829304b5', false]), 'POST', []);
    $p2 = $cerere($router('track', 'click', ['20', 'gresit', '42', '6071829304b5', false]), 'POST', []);
    $p3 = $cerere($router('track', 'click', ['20', $jeton20, '42', 'ffffffffffff', false]), 'POST', []);
    $faraData = static fn (array $x): array => [$x['cod'], $x['corp'], array_diff_key($x['antete'], ['date' => 1])];
    $verifica($e . 'SEC-E-02: peste limită, orice POST pe clic → același 200 gol (jetonul bun, unul greșit, un hash necunoscut), niciun clic notat',
        $p1['cod'] === 200 && $p1['corp'] === '' && $faraData($p1) === $faraData($p2) && $faraData($p1) === $faraData($p3) && $clicuri() === $clicuriInainte,
        json_encode([$faraData($p1), $faraData($p2), $faraData($p3)]));
    // E-LEG-05: peste limită, retragerile nu primesc niciodată pagina F: pagina dezabonării, butonul ei, confirmarea dezabonării, oprirea urmăririi.
    $date12 = ['token' => 'a1b2c3-0123456789abcdef012345678', 'email' => 'neconfirmat@example.invalid'];
    $g1 = $cerere($abonare('unsubscribe', $date12));
    $g2 = $cerere($abonare('confirm_unsubscribe', $date12));
    $g3 = $cerere($abonare('tracking_opt_out', $date12));
    $g4 = $cerere($abonare('unsubscribe', $date12, '&request_method=POST'), 'POST', ['type' => 'confirmation']);
    $verifica($e . 'E-LEG-05: peste limită, pagina dezabonării (D), confirmarea dezabonării și oprirea urmăririi → 200; butonul dezabonării → 303, dezabonat',
        $g1['cod'] === 200 && str_contains($g1['corp'], 'Confirmă că vrei să te dezabonezi') && $g2['cod'] === 200 && $g3['cod'] === 200 && $g4['cod'] === 303
        && $ab(12)['status'] === 'unsubscribed', json_encode([$g1['cod'], $g2['cod'], $g3['cod'], $g4['cod'], $ab(12)['status']]));
    // SEC-E-05: bugetul routerului ține doar amprenta rețelei (mp: și 32 de caractere hex, HMAC cu cheia cadrului), fără user agent; un rând
    // mai vechi de o oră se șterge la cererea următoare.
    $randuriBuget = $db->query("SELECT ip_address, user_agent FROM register_submit_logs WHERE status = '" . BugetRouter::STARE . "'")->fetchAll();
    $amprentaProba = BugetRouter::amprenta('127.0.0.1');
    $doarAmprenta = $randuriBuget !== [] && array_filter($randuriBuget, static fn (array $x): bool => $x['ip_address'] !== $amprentaProba || $x['user_agent'] !== null) === []
        && preg_match('/^mp:[0-9a-f]{32}$/D', (string) $amprentaProba) === 1;
    $db->prepare('INSERT INTO register_submit_logs (ip_address, user_agent, status, created_at) VALUES (?, NULL, ?, NOW() - INTERVAL 2 HOUR)')
        ->execute(['mp:' . str_repeat('0', 32), BugetRouter::STARE]);
    $cerere($router('track', 'open', ['20', $jeton20, '42', null, false]));
    $vechiRamas = (int) $db->query("SELECT COUNT(*) FROM register_submit_logs WHERE ip_address = 'mp:" . str_repeat('0', 32) . "'")->fetchColumn();
    $verifica($e . 'SEC-E-05: bugetul routerului: doar amprenta HMAC a rețelei (nu adresa IP), fără user agent; rândurile mai vechi de o oră se șterg',
        $doarAmprenta && $vechiRamas === 0 && (int) $db->query("SELECT COUNT(*) FROM register_submit_logs WHERE ip_address = '127.0.0.1'")->fetchColumn() === 0,
        json_encode([count($randuriBuget), $randuriBuget[0] ?? null, $vechiRamas]));
    $golesteLimita = true;
    $verifica($e . 'peste 60 pe minut: jetonul bun primește pagina F, un clic valid 404; un-clic valid (și cel prin antetul urmărit) tot dezabonează',
        $r['cod'] === 404 && $r3['cod'] === 404 && $r4['cod'] === 200 && $r4['corp'] === '' && $ab(14)['status'] === 'unsubscribed' && $r2['cod'] === 200
        && $ab(20)['status'] === 'unsubscribed', json_encode([$r['cod'], $r3['cod'], $r4['cod'], $ab(14)['status'], $r2['cod']]));
}
$scrie('');
$scrie(sprintf('Rezultat: %d trecute, %d eșuate', $trecute, $esuate));
exit($esuate === 0 ? 0 : 1);
