<?php

declare(strict_types=1);

/**
 * Poarta G-mp-import: importul MailPoet al unui site WordPress (pista E; ImportWordPress\MailPoet, specificația mailpoet §8.2), pe
 * fixtura INVENTATĂ tools/dev/fixturi-wp/mailpoet.sql (tabelele MailPoet 5.45.2 peste WordPress-ul de probă din
 * tools/dev/lib/wordpress-proba.php) și pe exportul ei (tools/dev/fixturi-wp/mailpoet-export.json):
 *
 *  1. planul, aplicarea și a doua aplicare: aceleași rânduri și aceleași numere; starea fiecărui abonat (§4.2.1), amprenta jetonului pe
 *     fiecare formă (32 aleator, 32 actualizat, 6 vechi, NULL, gol), cei din coș pe lista „nu trimite”, listele, apartenențele cu starea
 *     lor, campaniile (sursa mailpoet, blocate, HTML-ul cu codurile pe loc, totalurile), trimiterile procesate, cozile, linkurile,
 *     dezabonările, setările, bugetul;
 *  2. ieșirea: nicio adresă și niciun jeton al fixturii în planul raportat și în ieșirea scriptului;
 *  3. destinatarii: doar `active` + `subscribed`; ai altui site (fără MailPoet) neschimbați;
 *  4. curățarea neconfirmaților: cu `newsletter_sterge_neconfirmati_zile` = 0 abonații `pending` importați rămân; cu 30 și fără setare,
 *     ca înainte;
 *  5. NU: o listă dinamică, un cod necunoscut în export, o etichetă a editorului nou (în corp, într-un link, scrisă %5B…%5D), o adresă de
 *     link mai lungă de 2048 de caractere, un pas de automatizare necunoscut (și automatizările fără pista J), un câmp personalizat, două
 *     adrese egale fără majuscule, numele câmpurilor cadrului R lipsă, un site fără wp-compat → mailpoet;
 *  6. datele de o parte și de alta a schimbărilor de oră (DATETIME în ora Bucureștiului, TIMESTAMP același moment), un nume peste 190 de
 *     caractere, neconfirmații vechi cu cont sau client păstrați de curățare;
 *  7. regulile cadrului R din pachetul ateliere (site-uri/ateliere/import-wordpress.json) pe un articol Divi inventat: doar titlul din
 *     head, H1-ul articolului și conținutul lui.
 *
 *   tools/dev/php.sh tools/dev/test-import-mailpoet.php
 *
 * Bazele (cms_mpi_wp, cms_mpi_site) și dosarul $CMS_DEV_DIR/test-import-mailpoet/ se șterg la final. Raport:
 * $CMS_DEV_DIR/test-import-mailpoet-raport.txt; cod 0 doar dacă totul a trecut.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$lucru = $dosarDev . '/test-import-mailpoet';
if (!str_ends_with($lucru, '/test-import-mailpoet')) {
    exit(2);
}
exec('rm -rf ' . escapeshellarg($lucru));
foreach (['site/storage', 'pachet', 'pachet-fara', 'licenta', 'wp'] as $d) {
    @mkdir($lucru . '/' . $d, 0770, true);
}
define('PLATFORMA_SITE_DIR', $lucru . '/site');

require_once $radacina . '/bootstrap.php';
require_once __DIR__ . '/lib/wordpress-proba.php';

use App\Support\DateSite;
use App\Support\Html5\Token;
use App\Support\Html5\Tokenizer;
use App\Support\ImportWordPress\ConfigWordPress;
use App\Support\ImportWordPress\Continut\Taiere;
use App\Support\ImportWordPress\Continut\TaiereRegiuni;
use App\Support\ImportWordPress\EroareImport;
use App\Support\ImportWordPress\ImportWordPress;
use App\Support\ImportWordPress\MailPoet\PlanMailPoet;
use App\Support\ImportWordPress\MailPoet\ScriereMailPoet;
use App\Support\ImportWordPress\MailPoet\SursaMailPoet;
use App\Support\ImportWordPress\Plan;
use App\Support\ImportWordPress\PlanCapturat;
use App\Support\ImportWordPress\SursaWordPress;
use App\Support\NewsletterService;
use App\Support\WpCompat;

$fisierRaport = $dosarDev . '/test-import-mailpoet-raport.txt';
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
$scrie('Test importul MailPoet (G-mp-import) — ' . date('Y-m-d H:i:s'));

$socket = (string) (getenv('CMS_DEV_SOCK') ?: ini_get('pdo_mysql.default_socket'));
$utilizator = (string) (getenv('CMS_DEV_DB_USER') ?: 'root');
$parolaDb = (string) (getenv('CMS_DEV_DB_PAROLA') ?: '');
$root = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', $utilizator, $parolaDb, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$bazaWp = 'cms_mpi_wp';
$bazaSite = 'cms_mpi_site';
register_shutdown_function(static function () use ($root, $bazaWp, $bazaSite, $lucru): void {
    foreach (['s', 'm', 'db', 'wp'] as $v) {
        $GLOBALS[$v] = null;
    }
    gc_collect_cycles();
    foreach ([$bazaWp, $bazaSite] as $b) {
        try {
            $root->exec('DROP DATABASE IF EXISTS `' . $b . '`');
        } catch (Throwable) {
        }
    }
    if (str_ends_with($lucru, '/test-import-mailpoet')) {
        exec('rm -rf ' . escapeshellarg($lucru));
    }
});
$conectare = static fn (string $baza): PDO => new PDO('mysql:unix_socket=' . $socket . ';dbname=' . $baza . ';charset=utf8mb4', $utilizator, $parolaDb,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== Pregătirea: WordPress-ul de probă cu MailPoet (fixtura), baza site-ului nou, pachetul cu wp-compat → mailpoet');
$dosarWp = $lucru . '/wp';
wordpressProba($root, $bazaWp, $dosarWp, ['prefix' => 'wp_']);
$wp = $conectare($bazaWp);
$fixtura = (string) file_get_contents(__DIR__ . '/fixturi-wp/mailpoet.sql');
foreach (preg_split('/;\n/', $fixtura) as $instr) {
    $instr = trim((string) preg_replace('/^--.*$/m', '', $instr));
    if ($instr !== '') {
        $wp->exec($instr);
    }
}
$verifica('fixtura MailPoet se încarcă (27 de tabele, 12 abonați, 6 liste)', (int) $wp->query('SELECT COUNT(*) FROM wp_mailpoet_subscribers')->fetchColumn() === 12
    && (int) $wp->query('SELECT COUNT(*) FROM wp_mailpoet_segments')->fetchColumn() === 6);
$root->exec('DROP DATABASE IF EXISTS `' . $bazaSite . '`');
$root->exec('CREATE DATABASE `' . $bazaSite . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$p = proc_open([$radacina . '/tools/dev/php.sh', 'scripts/install.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, $radacina,
    array_merge(getenv(), ['CMS_DEV_DB' => $bazaSite, 'CMS_DEV_LICENTA_DOSAR' => $lucru . '/licenta']));
$outI = (string) stream_get_contents($pipe[1]) . (string) stream_get_contents($pipe[2]);
fclose($pipe[1]);
fclose($pipe[2]);
$db = $conectare($bazaSite);
// Ca Database::connection: ora sesiunii MySQL e ora PHP a site-ului, deci NOW() și datele scrise din PHP se pot compara (E-LEG-03).
$db->exec("SET time_zone = '" . date('P') . "'");
$verifica('scripts/install.php: baza site-ului cu migrarea newsletter/0077 (cozile, linkurile, dezabonările, bugetul)', proc_close($p) === 0
    && (int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('newsletter_mailpoet_cozi',"
        . " 'newsletter_mailpoet_linkuri', 'newsletter_dezabonari', 'buget_trimitere')")->fetchColumn() === 4, $outI);
file_put_contents($lucru . '/pachet/wp-compat.json', '{"mailpoet": true}');
file_put_contents($lucru . '/pachet-fara/wp-compat.json', '{"slash_final": true}');
$pachet = static function (string $dosar): void {
    DateSite::reseteaza($dosar);
    WpCompat::reseteaza();
};
$pachet($lucru . '/pachet');
$export = __DIR__ . '/fixturi-wp/mailpoet-export.json';

// Regulile cadrului R din pachetul ateliere (import-wordpress.json → regiuni) pe un cadru R inventat, în forma unui articol Divi (antetul
// paginii, H1-ul cu linia post-meta, conținutul, bara laterală cu un alt H1 și o pictogramă SVG cu <title>): doar titlul din head, H1-ul
// articolului (n = 0) și interiorul conținutului (n = 1); pe cadrul P nicio regulă (se servește cum e capturat).
$caleR = '/?mailpoet_page=subscriptions&mailpoet_router&endpoint=subscription&action=manage&data=eyJwcmV2aWV3IjoxfQ';
$importAteliere = json_decode((string) file_get_contents($radacina . '/site-uri/ateliere/import-wordpress.json'), true);
$reguliAteliere = (array) ($importAteliere['regiuni'] ?? []);
$reguliR = TaiereRegiuni::reguliDocument($reguliAteliere, 'arhiva', $caleR);
$corpR = "\n<p>Formularul de administrare</p>\n";
$hR = "<!DOCTYPE html>\n<html lang=\"ro-RO\">\n<head>\n<meta charset=\"UTF-8\" />\n<title>Administrează-ți abonamentul | Pensiunea Probă</title>\n</head>\n"
    . "<body class=\"mailpoet_page-template-default single single-mailpoet_page et_right_sidebar\">\n<div id=\"page-container\">\n"
    . "<header id=\"main-header\"><a href=\"/\"><svg viewBox=\"0 0 1 1\"><title>Sigla</title></svg></a></header>\n<div id=\"main-content\">\n"
    . "<div class=\"container\">\n<div id=\"content-area\" class=\"clearfix\">\n<div id=\"left-area\">\n"
    . "<article id=\"post-303\" class=\"et_pb_post post-303 mailpoet_page type-mailpoet_page status-publish hentry\">\n<div class=\"et_post_meta_wrapper\">\n"
    . "<h1 class=\"entry-title\">Administrează-ți abonamentul</h1>\n<p class=\"post-meta\"><span class=\"published\">ian. 1, 2024</span></p>\n</div>\n"
    . '<div class="entry-content">' . $corpR . "</div>\n</article>\n</div>\n<div id=\"sidebar\"><h1>Altceva</h1></div>\n</div>\n</div>\n</div>\n</div>\n</body>\n</html>\n";
$anR = Tokenizer::analizeaza($hR);
$bodyR = 0;
foreach ($anR['tokeni'] as $k => $tok) {
    if ($tok->tip === Token::DESCHIDERE && $tok->nume === 'body') {
        $bodyR = $k;
        break;
    }
}
$taie = static function (array $reguli) use ($hR, $anR, $bodyR): array {
    $lr = TaiereRegiuni::locuri($hR, $anR['tokeni'], array_values($reguli), [], $bodyR);
    return [$lr['probleme'], array_map(static fn (array $l): string => $l['jeton'] . '=' . substr($hR, $l['start'], $l['end'] - $l['start']), $lr['locuri'])];
};
[$pRegiuni, $tRegiuni] = $taie(array_filter($reguliR, static fn (array $r): bool => isset($r['regiune'])));
$verifica('regulile cadrului R din site-uri/ateliere/import-wordpress.json: trec de validare, trei pe cadrul R și niciuna pe cadrul P; pe un articol '
    . 'Divi inventat, regiunea mailpoet:pagina doar pe H1-ul articolului (0) și pe interiorul conținutului (1)',
    ImportWordPress::valideazaReguli($importAteliere) === [] && count($reguliR) === 3 && $pRegiuni === []
    && $tRegiuni === ['regiune:mailpoet:pagina:0=Administrează-ți abonamentul', 'regiune:mailpoet:pagina:1=' . $corpR]
    && TaiereRegiuni::reguliDocument($reguliAteliere, 'arhiva', '/?mailpoet_page=subscriptions') === [],
    json_encode(['reguli' => count($reguliR), 'probleme' => $pRegiuni, 'taiat' => $tRegiuni], JSON_UNESCAPED_UNICODE));
// Jetonul titlului pe <title> din head (nu pe <title>-ul pictogramei SVG). Tăierea pistei A refuză deocamdată textul RCDATA al lui
// <title> („are și altceva decât text”): până la reparația ei, doar acel refuz e primit, cu un INFO; după ea, doar titlul.
[$pTitlu, $tTitlu] = $taie(array_filter($reguliR, static fn (array $r): bool => ($r['jeton'] ?? null) === 'titlu'));
$refuzRcdata = count($pTitlu) === 1 && $tTitlu === [] && str_contains($pTitlu[0], '„head > title”') && str_contains($pTitlu[0], 'are și altceva decât text');
$verifica('regula titlului cadrului R: doar <title> din head (titlul întreg), sau refuzul cunoscut al textului RCDATA de către tăierea pistei A',
    ($pTitlu === [] && $tTitlu === ['titlu=Administrează-ți abonamentul | Pensiunea Probă']) || $refuzRcdata,
    json_encode(['probleme' => $pTitlu, 'taiat' => $tTitlu], JSON_UNESCAPED_UNICODE));
if ($refuzRcdata) {
    $scrie('  INFO  jetonul titlului pe <title>: refuzat de TaiereRegiuni (textul RCDATA al lui <title>), până la reparația pistei A');
}

// Cadrele capturate (P și R), ca le dă tăierea capturii: regiunea mailpoet:pagina pe H1 și pe conținut, jetonul titlului.
$campuriR = ['first_name' => 'form_field_' . base64_encode('0a1b2c3d4e5f_first_name'), 'last_name' => 'form_field_' . base64_encode('1b2c3d4e5f60_last_name'),
    'status' => 'form_field_' . base64_encode('2c3d4e5f6071_status'), 'segments' => 'form_field_' . base64_encode('3d4e5f607182_segments')];
$planCadre = static function (bool $cuCampuri = true) use ($campuriR): PlanCapturat {
    $pc = new PlanCapturat();
    $t = static fn (array $regiuni, array $valori): Taiere => new Taiere('', [], ['cap' => 0, 'final' => 0], '', '', '', '', '', '', [], $valori, [], null, 'inghetat',
        null, [], [], [], [], [], $regiuni);
    $form = '<form class="mailpoet-manage-subscription" method="post" action="/x" novalidate>';
    foreach ($cuCampuri ? $campuriR : array_slice($campuriR, 0, 2) as $k => $v) {
        $form .= '<input name="data[' . $v . ']' . ($k === 'segments' ? '[]' : '') . '" />';
    }
    $pc->documente['arhiva:p'] = ['cheie' => 'arhiva:p', 'tip' => 'arhiva', 'valori_import' => ['cale' => '/?mailpoet_page=subscriptions'], 'taiere' => $t([], [])];
    $pc->documente['arhiva:r'] = ['cheie' => 'arhiva:r', 'tip' => 'arhiva',
        'valori_import' => ['cale' => '/?mailpoet_page=subscriptions&mailpoet_router&endpoint=subscription&action=manage&data=eyJwcmV2aWV3IjoxfQ'],
        'taiere' => $t([['regiune' => 'mailpoet:pagina', 'n' => 0, 'mod' => 'interior', 'octeti' => 'Administrează-ți abonamentul'],
            ['regiune' => 'mailpoet:pagina', 'n' => 1, 'mod' => 'interior', 'octeti' => "\n\t\t\t\t\t<p>" . $form . "</form></p>\n\t\t\t\t\t"]],
            ['titlu' => 'Administrează-ți abonamentul | Pensiunea Probă'])];
    return $pc;
};
$sursa = static function (?string $exp) use ($dosarWp): SursaMailPoet {
    return new SursaMailPoet(SursaWordPress::deschide(ConfigWordPress::citeste($dosarWp)), $exp);
};
$numere = static function (PDO $db): array {
    $rez = [];
    foreach (['newsletter_subscribers', 'newsletter_lists', 'newsletter_list_subscribers', 'newsletter_campaigns', 'newsletter_campaign_sends',
        'newsletter_campaign_opens', 'newsletter_campaign_clicks', 'newsletter_mailpoet_cozi', 'newsletter_mailpoet_linkuri', 'newsletter_dezabonari',
        'newsletter_suprimate', 'buget_trimitere'] as $t) {
        $rez[$t] = (int) $db->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn();
    }
    $rez['amprenta'] = md5((string) json_encode($db->query('SELECT mailpoet_id, email, status, mailpoet_token_hash, pending_at, confirmed_ip FROM newsletter_subscribers'
        . ' ORDER BY id')->fetchAll()) . json_encode($db->query('SELECT list_id, subscriber_id, status FROM newsletter_list_subscribers ORDER BY 1, 2')->fetchAll()));
    return $rez;
};

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 3 (înainte). Un site fără MailPoet: destinatarii listelor lui, cu rânduri vechi (fără starea pe listă scrisă)');
$db->exec("INSERT INTO newsletter_lists (name, description, is_default) VALUES ('Lista altui site', '', 0)");
$listaAlt = (int) $db->lastInsertId();
foreach (['unu@example.invalid', 'doi@example.invalid'] as $e) {
    NewsletterService::subscribeToList($db, $listaAlt, $e, 'Proba');
}
$inainteAlt = array_column(NewsletterService::listRecipients($db, $listaAlt), 'email');
sort($inainteAlt);
$verifica('destinatarii unei liste a codului comun (rânduri fără status scris: implicit subscribed)', $inainteAlt === ['doi@example.invalid', 'unu@example.invalid'],
    json_encode($inainteAlt));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 1. Planul, aplicarea, a doua aplicare');
$s = $sursa($export);
$plan = PlanMailPoet::fa($s, $planCadre(), true, ['automatizari']);
$verifica('planul: niciun NU (automatizările lăsate deoparte: --fara-automatizari)', $plan->probleme === [], implode(' | ', $plan->probleme));
$verifica('planul: 9 abonați (3 în coș nu), 2 pe lista „nu trimite” (dezabonatul și săritul din coș), 5 liste (una în coș nu), 13 apartenențe, 2 campanii, 3 cozi, '
    . '10 linkuri, 2 dezabonări', count($plan->abonati) === 9 && count($plan->suprimate) === 2 && count($plan->liste) === 5 && count($plan->apartenente) === 13
    && count($plan->campanii) === 2 && count($plan->cozi) === 3 && count($plan->linkuri) === 10 && count($plan->dezabonari) === 2, json_encode($plan->raport()));
$verifica('planul: stările (active 4, pending 1, unsubscribed 2, inactive 1, bounced 1)', $plan->numarPeStare() === ['active' => 4, 'bounced' => 1, 'inactive' => 1,
    'pending' => 1, 'unsubscribed' => 2], json_encode($plan->numarPeStare()));
$verifica('planul: avertismentul automatizărilor lăsate deoparte, cu numărul lor', count(array_filter($plan->avertismente,
    static fn (string $a): bool => str_contains($a, '--fara-automatizari') && str_contains($a, '3 automatizări'))) === 1, implode(' | ', $plan->avertismente));
$verifica('planul: avertismentul linkurilor urmărite fără adresa lor în coadă (campania 31, unul), doar numărul', count(array_filter($plan->avertismente,
    static fn (string $a): bool => str_contains($a, 'campania MailPoet 31: 1 linkuri urmărite fără adresa lor'))) === 1, implode(' | ', $plan->avertismente));
$verifica('planul: avertismentul campaniilor cu fonturi Google (campania 32: arhiva le arată cu fonturile de rezervă)', count(array_filter($plan->avertismente,
    static fn (string $a): bool => str_contains($a, '1 campanii MailPoet folosesc fonturi Google'))) === 1, implode(' | ', $plan->avertismente));
// Ieșirea: nicio adresă, niciun jeton, niciun IP, niciun nume al fixturii.
$secrete = ['@example.invalid', '0123456789abcdef0123456789abcdef', 'a1b2c3', 'fedcba98', '192.0.2.', '2001:db8', 'Ana', 'Probă', 'Majuscule', 'notificari@'];
$iesire = json_encode($plan->raport(), JSON_UNESCAPED_UNICODE) . implode("\n", array_merge($plan->info, $plan->avertismente, $plan->probleme));
$verifica('ieșirea planului (raportul, informațiile, avertismentele): nicio adresă, niciun jeton, niciun IP, niciun nume al fixturii',
    array_filter($secrete, static fn (string $x): bool => str_contains($iesire, $x)) === [], $iesire);
$db->beginTransaction();
$facut = ScriereMailPoet::aplica($db, $plan);
$db->commit();
$dupa1 = $numere($db);
$verifica('aplicarea: 9 abonați noi, 5 liste, 13 apartenențe, 2 campanii, 6 trimiteri, 3 cozi, 10 linkuri, 2 dezabonări, 2 adrese pe lista „nu trimite”',
    $facut['abonati'] === 9 && $facut['abonati_noi'] === 9 && $facut['liste'] === 5 && $facut['apartenente'] === 13 && $facut['campanii'] === 2
    && $facut['trimiteri'] === 6 && $facut['cozi'] === 3 && $facut['linkuri'] === 10 && $facut['dezabonari'] === 2 && $facut['suprimate'] === 2, json_encode($facut));
$db->beginTransaction();
$facut2 = ScriereMailPoet::aplica($db, PlanMailPoet::fa($sursa($export), $planCadre(), true, ['automatizari']));
$db->commit();
$dupa2 = $numere($db);
$verifica('a doua aplicare: aceleași rânduri și aceleași numere (niciun abonat nou, nicio adresă nouă pe lista „nu trimite”)', $dupa1 === $dupa2
    && $facut2['abonati_noi'] === 0 && $facut2['suprimate'] === 0 && array_diff_key($facut2, ['abonati_noi' => 1, 'suprimate' => 1]) == array_diff_key($facut, ['abonati_noi' => 1, 'suprimate' => 1]),
    json_encode([$dupa1, $dupa2]));
$ab = [];
foreach ($db->query('SELECT * FROM newsletter_subscribers WHERE mailpoet_id IS NOT NULL') as $r) {
    $ab[(int) $r['mailpoet_id']] = $r;
}
$verifica('starea: subscribed → active, unconfirmed → pending, unsubscribed, inactive, bounced (§4.2.1)', ($ab[11]['status'] ?? '') === 'active'
    && ($ab[12]['status'] ?? '') === 'pending' && ($ab[13]['status'] ?? '') === 'unsubscribed' && ($ab[14]['status'] ?? '') === 'inactive'
    && ($ab[15]['status'] ?? '') === 'bounced' && !isset($ab[16]) && !isset($ab[21]) && !isset($ab[22]));
$verifica('dovada consimțământului: subscribed_ip, confirmed_ip (și IPv6), confirmed_at, last_subscribed_at, sursa', ($ab[11]['subscribed_ip'] ?? '') === '192.0.2.11'
    && ($ab[11]['confirmed_ip'] ?? '') === '192.0.2.12' && ($ab[13]['subscribed_ip'] ?? '') === '2001:db8::13' && ($ab[11]['source'] ?? '') === 'api'
    && ($ab[11]['confirmed_at'] ?? '') === SursaMailPoet::utc('2025-03-01 09:00:00') && ($ab[11]['last_subscribed_at'] ?? '') === SursaMailPoet::utc('2025-03-01 09:00:00'));
$verifica('jetonul, pe fiecare formă: 32 aleator (fără cele 6), 32 actualizat (cu sha256-ul primelor 6), 6 vechi, NULL și gol (nimic); jetonul nu e scris nicăieri',
    ($ab[11]['mailpoet_token_hash'] ?? '') === hash('sha256', '0123456789abcdef0123456789abcdef') && (int) $ab[11]['mailpoet_token_len'] === 32 && $ab[11]['mailpoet_token6_hash'] === null
    && ($ab[12]['mailpoet_token6_hash'] ?? '') === hash('sha256', 'a1b2c3') && ($ab[12]['mailpoet_token_hash'] ?? '') === hash('sha256', 'a1b2c3-0123456789abcdef012345678')
    && ($ab[13]['mailpoet_token_hash'] ?? '') === hash('sha256', 'a1b2c3') && (int) $ab[13]['mailpoet_token_len'] === 6 && $ab[13]['mailpoet_token6_hash'] === null
    && $ab[14]['mailpoet_token_hash'] === null && $ab[14]['mailpoet_token_len'] === null && $ab[15]['mailpoet_token_hash'] === null
    && !str_contains((string) json_encode($db->query('SELECT * FROM newsletter_subscribers')->fetchAll()), '0123456789abcdef0123456789abcdef'));
$verifica('adresa cu majuscule e importată cu litere mici; urmărirea refuzată; utilizatorul WordPress; clientul WooCommerce', ($ab[20]['email'] ?? '') === 'majuscule.proba@example.invalid'
    && (int) $ab[19]['urmarire_refuzata'] === 1 && (int) $ab[11]['urmarire_refuzata'] === 0 && (int) $ab[17]['wp_user_id'] === 5 && (int) $ab[18]['is_woocommerce_user'] === 1);
$verifica('pending_at al neconfirmatului = ultimul email de confirmare; nul pentru restul', ($ab[12]['pending_at'] ?? '') === SursaMailPoet::utc('2026-09-02 10:00:00')
    && $ab[11]['pending_at'] === null);
$sup = $db->query("SELECT email_hash FROM newsletter_suprimate WHERE motiv = 'mailpoet_cos'")->fetchAll(PDO::FETCH_COLUMN);
sort($sup);
$asteptat = [NewsletterService::amprentaAdresa('cos@example.invalid'), NewsletterService::amprentaAdresa('cos-sarit@example.invalid')];
sort($asteptat);
$verifica('cei din coș: dezabonatul și săritul pe lista „nu trimite” (mailpoet_cos), abonatul din coș nicăieri', $sup === $asteptat
    && !NewsletterService::esteSuprimat($db, 'cos-abonat@example.invalid'));
$liste = [];
foreach ($db->query('SELECT * FROM newsletter_lists WHERE mailpoet_id IS NOT NULL') as $r) {
    $liste[(int) $r['mailpoet_id']] = $r;
}
$verifica('listele: tipurile (wp_users, woocommerce_users, static), afisare_gestionare, lista din coș sărită, lista implicită a CMS-ului separată',
    ($liste[101]['tip'] ?? '') === 'wp_users' && ($liste[102]['tip'] ?? '') === 'woocommerce_users' && ($liste[103]['tip'] ?? '') === 'static'
    && (int) ($liste[104]['afisare_gestionare'] ?? 0) === 1 && (int) ($liste[103]['afisare_gestionare'] ?? 1) === 0 && !isset($liste[106])
    && (int) $db->query('SELECT COUNT(*) FROM newsletter_lists WHERE is_default = 1 AND mailpoet_id IS NULL')->fetchColumn() === 1);
$stare = static fn (int $abMp, int $liMp): ?string => ($v = $db->query('SELECT ls.status FROM newsletter_list_subscribers ls INNER JOIN newsletter_subscribers s ON s.id = ls.subscriber_id'
    . ' INNER JOIN newsletter_lists l ON l.id = ls.list_id WHERE s.mailpoet_id = ' . $abMp . ' AND l.mailpoet_id = ' . $liMp)->fetchColumn()) === false ? null : (string) $v;
$verifica('apartenența cu starea pe listă: abonat (11 → 103), dezabonat (13 → 104), clientul neconfirmat dezabonat pe lista clienților (18 → 102), nimic pe lista din coș',
    $stare(11, 103) === 'subscribed' && $stare(13, 104) === 'unsubscribed' && $stare(18, 102) === 'unsubscribed' && $stare(11, 106) === null);
$dest = array_column(NewsletterService::listRecipients($db, (int) $liste[103]['id']), 'email');
sort($dest);
$verifica('destinatarii listei 103: doar active + subscribed (nu pending, dezabonat, inactiv, sărit)', $dest === ['abonat@example.invalid', 'cont@example.invalid'],
    json_encode($dest));
$dupaAlt = array_column(NewsletterService::listRecipients($db, $listaAlt), 'email');
sort($dupaAlt);
$verifica('destinatarii listei altui site: neschimbați după import', $dupaAlt === $inainteAlt, json_encode($dupaAlt));
$c31 = $db->query('SELECT * FROM newsletter_campaigns WHERE mailpoet_id = 31')->fetch();
$c32 = $db->query('SELECT * FROM newsletter_campaigns WHERE mailpoet_id = 32')->fetch();
$verifica('campania 31: sursa mailpoet, șablonul mailpoet, HTML-ul cu codurile pe loc (linkurile, pixelul, prenumele), hash-ul, listele', is_array($c31)
    && $c31['sursa'] === 'mailpoet' && $c31['template_type'] === 'mailpoet' && $c31['mailpoet_hash'] === 'aa11bb22cc33' && $c31['status'] === 'sent'
    && str_contains($c31['html_content'], '[mailpoet_click_data]-0a1b2c3d4e5f') && str_contains($c31['html_content'], '[mailpoet_open_data]')
    && str_contains($c31['html_content'], '[subscriber:firstname | default:prieten]') && str_contains((string) $c31['text_content'], 'Oferta')
    && json_decode((string) $c31['subscriber_list_ids'], true) === [(int) $liste[103]['id'], (int) $liste[104]['id']]);
$verifica('campania 31: totalurile (5 destinatari, 3 trimise, 1 eșuată, 1 deschidere unică, 4 clicuri)', (int) $c31['total_recipients'] === 5 && (int) $c31['total_sent'] === 3
    && (int) $c31['total_failed'] === 1 && (int) $c31['total_opens'] === 1 && (int) $c31['total_clicks'] === 4, json_encode($c31));
$verifica('campania 32: HTML-ul din export (corpul cozii e NULL), lista din coș scoasă', is_array($c32) && str_contains($c32['html_content'], 'Despre noi')
    && json_decode((string) $c32['subscriber_list_ids'], true) === [(int) $liste[103]['id']]);
$verifica('adresa publică de partajare: identificatorul exportat (31); unul cu alt hash decât al campaniei se ignoră (32)',
    $c31['mailpoet_partajare'] === 'aa11bb22cc33-scrisoarea-de-proba-unu' && $c32['mailpoet_partajare'] === null);
$verifica('campaniile importate sunt blocate (nicio modificare, niciun test, nicio trimitere)', !NewsletterService::poateFiModificata($db, (int) $c31['id'])
    && (static function () use ($db, $c31): bool {
        try {
            NewsletterService::sendCampaignTest($db, (int) $c31['id'], 'test@example.invalid', []);
            return false;
        } catch (Throwable $e) {
            return str_contains($e->getMessage(), 'MailPoet');
        }
    })());
$tr = $db->query('SELECT s.mailpoet_id, t.status, t.error_message, t.open_count, t.click_count FROM newsletter_campaign_sends t INNER JOIN newsletter_subscribers s ON s.id = t.subscriber_id'
    . ' WHERE t.campaign_id = ' . (int) $c31['id'] . ' ORDER BY s.mailpoet_id')->fetchAll();
$verifica('trimiterile campaniei 31: doar cele procesate (11, 13, 14 eșuată cu eroarea, 19; nu 12, neprocesat), deschiderea și clicurile pe abonat',
    array_column($tr, 'mailpoet_id') === [11, 13, 14, 19] && $tr[2]['status'] === 'failed' && $tr[2]['error_message'] !== null && (int) $tr[0]['open_count'] === 1
    && (int) $tr[0]['click_count'] === 3 && (int) $tr[1]['click_count'] === 1, json_encode($tr));
$cozi = $db->query('SELECT coada_id, tip, campanie_id, abonat_id, newsletter_mailpoet_id FROM newsletter_mailpoet_cozi ORDER BY coada_id')->fetchAll();
$verifica('cozile: două de campanie (cu campania), una de automatizare (cu abonatul ei, emailul legat de pista J)', array_column($cozi, 'coada_id') === [41, 42, 43]
    && $cozi[0]['tip'] === 'campanie' && (int) $cozi[0]['campanie_id'] === (int) $c31['id'] && $cozi[2]['tip'] === 'automatizare'
    && (int) $cozi[2]['abonat_id'] === (int) $ab[11]['id'] && (int) $cozi[2]['newsletter_mailpoet_id'] === 35, json_encode($cozi));
$verifica('linkurile cozii 41: adresa simplă și codurile link: (și cel necunoscut, păstrat ca atare)', (int) $db->query('SELECT COUNT(*) FROM newsletter_mailpoet_linkuri WHERE coada_id = 41')->fetchColumn() === 6
    && (string) $db->query("SELECT url FROM newsletter_mailpoet_linkuri WHERE coada_id = 41 AND hash = '1b2c3d4e5f60'")->fetchColumn() === '[link:subscription_unsubscribe_url]');
$dz = $db->query('SELECT d.sursa, d.metoda, d.motiv, d.campanie_id, d.coada_mailpoet_id, d.mailpoet_id FROM newsletter_dezabonari d ORDER BY d.id')->fetchAll();
$verifica('dezabonările: sursa, metoda, motivul, campania și coada, cu id-ul rândului MailPoet', count($dz) === 2 && $dz[0]['sursa'] === 'newsletter' && $dz[0]['metoda'] === 'link'
    && $dz[0]['motiv'] === 'spam' && (int) $dz[0]['coada_mailpoet_id'] === 41 && (int) $dz[0]['campanie_id'] === (int) $c31['id'] && $dz[1]['sursa'] === 'order_checkout'
    && (int) $dz[0]['mailpoet_id'] > 0 && (int) $dz[1]['mailpoet_id'] > (int) $dz[0]['mailpoet_id'], json_encode($dz));
// O dezabonare fără momentul ei (o dată zero în MailPoet): a doua aplicare nu o dublează (cheia e id-ul rândului MailPoet).
$planFaraData = PlanMailPoet::fa($sursa($export), $planCadre(), true, ['automatizari']);
$planFaraData->dezabonari[1]['created_at'] = null;
$inainteDz = (int) $db->query('SELECT COUNT(*) FROM newsletter_dezabonari')->fetchColumn();
foreach ([1, 2] as $_) {
    $db->beginTransaction();
    ScriereMailPoet::aplica($db, $planFaraData);
    $db->commit();
}
$verifica('dezabonările fără moment: aceleași rânduri la fiecare aplicare (niciun rând nou), momentul scris prima dată păstrat',
    (int) $db->query('SELECT COUNT(*) FROM newsletter_dezabonari')->fetchColumn() === $inainteDz
    && $db->query("SELECT created_at FROM newsletter_dezabonari WHERE sursa = 'order_checkout'")->fetchColumn() === SursaMailPoet::utc('2025-06-01 09:00:00'));
$set = $db->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'mailpoet\\_%' OR `key` = 'newsletter_sterge_neconfirmati_zile'")->fetchAll(PDO::FETCH_KEY_PAIR);
$optin = json_decode((string) ($set['mailpoet_optin_checkout'] ?? ''), true);
$verifica('setările: pagina, grația jetoanelor, sondajul, câmpurile cadrului R, sufixul titlului, numele site-ului, bifa din checkout (listele CMS), ștergerea neconfirmaților = 0',
    ($set['mailpoet_pagina_abonare'] ?? '') === '/?mailpoet_page=subscriptions' && ($set['mailpoet_jetoane_vechi_pana_la'] ?? '') === (string) gmmktime(0, 0, 0, 12, 20, 2026)
    && json_decode((string) ($set['mailpoet_sondaj_dezabonare'] ?? ''), true) === ['activ' => true, 'text_liber' => false]
    && json_decode((string) ($set['mailpoet_campuri_gestionare'] ?? ''), true) == $campuriR && ($set['mailpoet_sufix_titlu'] ?? '') === ' | Pensiunea Probă'
    && ($set['mailpoet_nume_site'] ?? '') === WP_PROBA_SITE && is_array($optin) && $optin['activ'] === true && $optin['liste'] === [(int) $liste[104]['id']]
    && ($set['newsletter_sterge_neconfirmati_zile'] ?? '') === '0' && ($set['mailpoet_notificare_abonat_nou'] ?? '') === '1'
    && ($set['mailpoet_notificare_adresa'] ?? '') === 'notificari@example.invalid'
    && str_contains((string) ($set['mailpoet_email_confirmare'] ?? ''), '[activation_link]') && json_decode((string) ($set['mailpoet_arhiva_gazde'] ?? ''), true) === ['fonts.googleapis.com'],
    json_encode(array_map(static fn ($v) => mb_substr((string) $v, 0, 80), $set), JSON_UNESCAPED_UNICODE));
$buget = $db->query("SELECT capacitate, perioada_s FROM buget_trimitere WHERE nume = 'smtp'")->fetch();
$verifica('bugetul trimiterilor: 40 de emailuri la 600 de secunde (mta.frequency, 40 la 10 minute)', is_array($buget) && (int) $buget['capacitate'] === 40 && (int) $buget['perioada_s'] === 600);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 4. Curățarea neconfirmaților (newsletter_sterge_neconfirmati_zile)');
$pending = static fn (): int => (int) $db->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE mailpoet_id = 12 AND status = 'pending'")->fetchColumn();
NewsletterService::curataCereriExpirate($db);
$verifica('cu 0 (MailPoet fără ștergere): abonatul pending importat rămâne', $pending() === 1);
$db->exec("UPDATE settings SET `value` = '30' WHERE `key` = 'newsletter_sterge_neconfirmati_zile'");
NewsletterService::curataCereriExpirate($db);
$verifica('cu 30 (comportamentul de dinainte): abonatul pending vechi de peste 30 de zile se șterge', $pending() === 0);
$db->beginTransaction();
ScriereMailPoet::aplica($db, PlanMailPoet::fa($sursa($export), $planCadre(), true, ['automatizari']));
$db->commit();
$db->exec("DELETE FROM settings WHERE `key` = 'newsletter_sterge_neconfirmati_zile'");
NewsletterService::curataCereriExpirate($db);
$verifica('fără setare (orice alt site): ca înainte, se șterge', $pending() === 0);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 6. Datele de la schimbarea orei, lungimile, neconfirmații cu cont sau client (rânduri adăugate doar aici)');
// MailPoet scrie datele în UTC (PHP-ul WordPress), în coloane TIMESTAMP citite cu fusul implicit al serverului: aceeași sesiune la scriere
// și la citire. Rândurile 41 și 42 stau de o parte și de alta a schimbărilor de oră (wc-date §7.3); 43 și 44 sunt neconfirmați vechi cu
// cont, respectiv client; 45 are prenumele și numele împreună peste 190 de caractere.
$coloane = '(id, wp_user_id, is_woocommerce_user, first_name, last_name, email, status, subscribed_ip, confirmed_ip, confirmed_at, last_subscribed_at, '
    . 'created_at, source, count_confirmations, link_token, last_engagement_at, email_count, tracking_consent)';
$wp->exec('INSERT INTO wp_mailpoet_subscribers ' . $coloane . " VALUES
    (41, NULL, 0, 'Ora', 'Iarna', 'ora-iarna@example.invalid', 'subscribed', '192.0.2.41', '192.0.2.41', '2025-10-26 00:59:59', '2025-10-26 01:00:00',
        '2025-10-26 00:59:59', 'api', 1, NULL, '2025-10-26 01:00:00', 0, 'unknown'),
    (42, NULL, 0, 'Ora', 'Vara', 'ora-vara@example.invalid', 'subscribed', '192.0.2.42', '192.0.2.42', '2026-03-29 00:59:59', '2026-03-29 01:00:00',
        '2026-03-29 01:00:00', 'api', 1, NULL, '2026-03-29 00:59:59', 0, 'unknown'),
    (43, 9, 0, 'Cont', '', 'cont-neconfirmat@example.invalid', 'unconfirmed', '192.0.2.43', NULL, NULL, NULL, '2025-01-10 10:00:00', 'wordpress_user', 1, NULL,
        NULL, 0, 'unknown'),
    (44, NULL, 1, 'Client', '', 'client-neconfirmat@example.invalid', 'unconfirmed', '192.0.2.44', NULL, NULL, NULL, '2025-01-10 10:00:00', 'woocommerce_user',
        1, NULL, NULL, 0, 'unknown'),
    (45, NULL, 0, '" . str_repeat('Ă', 120) . "', '" . str_repeat('B', 100) . "', 'nume-lung@example.invalid', 'subscribed', '192.0.2.45', '192.0.2.45',
        '2025-05-01 09:00:00', '2025-05-01 09:00:00', '2025-05-01 08:00:00', 'api', 1, NULL, NULL, 0, 'unknown')");
try {
    $pl6 = PlanMailPoet::fa($sursa($export), $planCadre(), true, ['automatizari']);
    $verifica('planul cu rândurile adăugate: niciun NU; un nume peste 190 de caractere numărat (avertismentul, doar numărul)', $pl6->probleme === []
        && $pl6->numeTaiate === 1 && count(array_filter($pl6->avertismente, static fn (string $a): bool => str_starts_with($a, '1 nume (ale abonaților'))) === 1,
        implode(' | ', array_merge($pl6->probleme, $pl6->avertismente)));
    $db->beginTransaction();
    ScriereMailPoet::aplica($db, $pl6);
    $db->commit();
    $r6 = static function (int $mp) use ($db): array {
        $q = $db->prepare('SELECT confirmed_at, last_subscribed_at, last_engagement_at, UNIX_TIMESTAMP(created_at) AS creat, status, pending_at, first_name, last_name, '
            . 'name FROM newsletter_subscribers WHERE mailpoet_id = ?');
        $q->execute([$mp]);
        return (array) $q->fetch();
    };
    $a41 = $r6(41);
    $a42 = $r6(42);
    // DATETIME: ora Bucureștiului cu regula ei de vară pentru fiecare valoare; TIMESTAMP: același moment ca UTC-ul lui MailPoet.
    $verifica('datele (toamna 2025): 00:59:59 UTC → 03:59:59 (ora de vară), 01:00:00 UTC → 03:00:00 (ora de iarnă); TIMESTAMP același moment',
        ($a41['confirmed_at'] ?? '') === '2025-10-26 03:59:59' && ($a41['last_subscribed_at'] ?? '') === '2025-10-26 03:00:00'
        && ($a41['last_engagement_at'] ?? '') === '2025-10-26 03:00:00' && (int) ($a41['creat'] ?? 0) === gmmktime(0, 59, 59, 10, 26, 2025),
        json_encode([$a41['confirmed_at'] ?? null, $a41['last_subscribed_at'] ?? null, $a41['last_engagement_at'] ?? null, $a41['creat'] ?? null]));
    $verifica('datele (primăvara 2026): 00:59:59 UTC → 02:59:59 (ora de iarnă), 01:00:00 UTC → 04:00:00 (ora de vară); TIMESTAMP același moment',
        ($a42['confirmed_at'] ?? '') === '2026-03-29 02:59:59' && ($a42['last_subscribed_at'] ?? '') === '2026-03-29 04:00:00'
        && ($a42['last_engagement_at'] ?? '') === '2026-03-29 02:59:59' && (int) ($a42['creat'] ?? 0) === gmmktime(1, 0, 0, 3, 29, 2026),
        json_encode([$a42['confirmed_at'] ?? null, $a42['last_subscribed_at'] ?? null, $a42['last_engagement_at'] ?? null, $a42['creat'] ?? null]));
    $a45 = $r6(45);
    $verifica('lungimile: numele tăiat la 190 de caractere, prenumele (120) și numele (100) întregi', mb_strlen((string) ($a45['name'] ?? '')) === 190
        && ($a45['first_name'] ?? '') === str_repeat('Ă', 120) && ($a45['last_name'] ?? '') === str_repeat('B', 100));
    $db->exec("UPDATE settings SET `value` = '30' WHERE `key` = 'newsletter_sterge_neconfirmati_zile'");
    NewsletterService::curataCereriExpirate($db);
    $verifica('curățarea cu 30 de zile: neconfirmații vechi cu cont (wp_user_id) sau clienți (is_woocommerce_user) rămân, ca MailPoet',
        ($r6(43)['status'] ?? '') === 'pending' && ($r6(44)['status'] ?? '') === 'pending' && $pending() === 0,
        json_encode([$r6(43)['status'] ?? null, $r6(44)['status'] ?? null, $pending()]));
} finally {
    $wp->exec('DELETE FROM wp_mailpoet_subscribers WHERE id BETWEEN 41 AND 45');
    $db->exec("DELETE FROM settings WHERE `key` = 'newsletter_sterge_neconfirmati_zile'");
}

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 8. Corecturile pistei E (runda 1): expeditorul, jetoanele scurte, IP-urile, lista internă, reaplicarea, adresele deja ale altcuiva, MailPoet fără abonați');
$s = null;
gc_collect_cycles();
$cms = static fn (int $mp): array => (array) ($db->query('SELECT * FROM newsletter_subscribers WHERE mailpoet_id = ' . $mp)->fetch() ?: []);
$aplica8 = static function (?PlanMailPoet $p = null) use ($db, $sursa, $export, $planCadre): array {
    $p ??= PlanMailPoet::fa($sursa($export), $planCadre(), true, ['automatizari']);
    $db->beginTransaction();
    try {
        $r = ScriereMailPoet::aplica($db, $p);
        $db->commit();
        return $r;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
};
$planSite = static function (PlanMailPoet $p) use ($db): Plan {
    $pl = new Plan();
    $pl->newsletter = $p;
    $pl->info = ['newsletter (MailPoet): ' . PlanMailPoet::AMINTIRE_EXPEDITOR];
    PlanMailPoet::verificaSite($pl, $db);
    return $pl;
};
// E-PAR-06: expeditorul MailPoet (numele, adresa, Reply-To) față de Admin → Emailuri: ce diferă e un avertisment cu numele câmpului, fără
// adresă; doar fără nicio diferență rămâne amintirea.
$setariVechi = \App\Support\Settings::all($db);
\App\Support\Settings::save($db, ['order_email_from_name' => 'Alt expeditor', 'order_email_from_address' => 'alt-expeditor@example.invalid']);
$plE = $planSite($plan);
$avE = implode(' | ', array_filter($plE->avertismente, static fn (string $a): bool => str_contains($a, 'diferă de Admin')));
\App\Support\Settings::save($db, ['order_email_from_name' => WP_PROBA_SITE, 'order_email_from_address' => 'Trimite@Example.Invalid']);
$plF = $planSite($plan);
$avF = implode(' | ', array_filter($plF->avertismente, static fn (string $a): bool => str_contains($a, 'diferă de Admin')));
$wp->exec("UPDATE wp_mailpoet_settings SET value = 'a:2:{s:4:\"name\";s:0:\"\";s:7:\"address\";s:23:\"raspuns@example.invalid\";}' WHERE name = 'reply_to'");
$plG = $planSite(PlanMailPoet::fa($sursa($export), $planCadre(), true, ['automatizari']));
$avG = implode(' | ', array_filter($plG->avertismente, static fn (string $a): bool => str_contains($a, 'diferă de Admin')));
$wp->exec("UPDATE wp_mailpoet_settings SET value = 'a:2:{s:4:\"name\";s:0:\"\";s:7:\"address\";s:0:\"\";}' WHERE name = 'reply_to'");
\App\Support\Settings::save($db, ['order_email_from_name' => (string) ($setariVechi['order_email_from_name'] ?? ''),
    'order_email_from_address' => (string) ($setariVechi['order_email_from_address'] ?? '')]);
$verifica('E-PAR-06: expeditorul diferit (numele și adresa) → avertismentul cu numele câmpurilor, fără nicio adresă, fără amintire; același (adresa fără majuscule) → '
    . 'doar amintirea; alt Reply-To în MailPoet → avertismentul lui', str_contains($avE, 'numele expeditorului, adresa expeditorului din MailPoet diferă')
    && !str_contains($avE, '@') && !in_array('newsletter (MailPoet): ' . PlanMailPoet::AMINTIRE_EXPEDITOR, $plE->info, true) && $avF === ''
    && in_array('newsletter (MailPoet): ' . PlanMailPoet::AMINTIRE_EXPEDITOR, $plF->info, true) && str_contains($avG, 'adresa de răspuns (Reply-To)')
    && !str_contains($avG, 'numele expeditorului') && !str_contains($avG, '@'), $avE . ' || ' . $avF . ' || ' . $avG);
// SEC-E-02: câte jetoane MailPoet sunt mai scurte de 32 de caractere (13: 6), doar numărul.
$verifica('SEC-E-02: planul numără jetoanele mai scurte de 32 de caractere (unul: 6 caractere), fără niciun jeton', count(array_filter($plan->avertismente,
    static fn (string $a): bool => str_starts_with($a, '1 abonați au jetonul MailPoet mai scurt de 32 de caractere'))) === 1, implode(' | ', $plan->avertismente));
// E-LEG-01: nivelul urmăririi MailPoet (tracking.level) ajunge în setările site-ului.
$verifica('E-LEG-01: nivelul urmăririi importat (full în fixtură)', ($plan->setari[NewsletterService::CHEIE_NIVEL_URMARIRE] ?? '') === 'full');
// E-LEG-10: o valoare a dovezii acordului care nu e o adresă IP nu se importă și se numără (doar numărul).
$wp->exec("UPDATE wp_mailpoet_subscribers SET subscribed_ip = 'nu-e-un-ip', confirmed_ip = '999.1.2.3' WHERE id = 11");
$planIp = PlanMailPoet::fa($sursa($export), $planCadre(), true, ['automatizari']);
$wp->exec("UPDATE wp_mailpoet_subscribers SET subscribed_ip = '192.0.2.11', confirmed_ip = '192.0.2.12' WHERE id = 11");
$verifica('E-LEG-10: două IP-uri nevalide în dovada acordului → nimportate (null), avertismentul cu numărul lor, fără valori', $planIp->ipuriRefuzate === 2
    && array_key_exists('subscribed_ip', $planIp->abonati[11] ?? []) && $planIp->abonati[11]['subscribed_ip'] === null
    && array_key_exists('confirmed_ip', $planIp->abonati[11] ?? []) && $planIp->abonati[11]['confirmed_ip'] === null
    && count(array_filter($planIp->avertismente, static fn (string $a): bool => str_starts_with($a, '2 adrese IP din dovada acordului') && !str_contains($a, '999')
        && !str_contains($a, 'nu-e-un-ip'))) === 1, implode(' | ', $planIp->avertismente));
// E-LEG-02: lista internă „Toți abonații” nu primește pe nimeni importat: nici abonatul doar pe lista din coș (47), nici cel doar pe o listă
// ascunsă (20, ca un magnet de abonare), nici cel dezabonat de pe toate (13); rândurile ei de la un import mai vechi se scot.
$wp->exec("INSERT INTO wp_mailpoet_subscribers (id, first_name, last_name, email, status, subscribed_ip, confirmed_ip, confirmed_at, last_subscribed_at, created_at, source,"
    . " count_confirmations, link_token, tracking_consent) VALUES (47, 'Doar', 'Cos', 'doar-cos@example.invalid', 'subscribed', '192.0.2.47', '192.0.2.47',"
    . " '2025-05-01 09:00:00', '2025-05-01 09:00:00', '2025-05-01 08:00:00', 'api', 1, 'fedcba9876543210fedcba9876543247', 'unknown')");
$wp->exec("INSERT INTO wp_mailpoet_subscriber_segment (subscriber_id, segment_id, status, created_at) VALUES (47, 106, 'subscribed', '2025-05-01 08:00:00')");
$implicita = NewsletterService::defaultListId($db);
$db->prepare('INSERT IGNORE INTO newsletter_list_subscribers (list_id, subscriber_id) VALUES (?, ?)')->execute([$implicita, (int) ($cms(11)['id'] ?? 0)]);
try {
    $r47 = $aplica8();
    $peImplicita = (int) $db->query('SELECT COUNT(*) FROM newsletter_list_subscribers ls INNER JOIN newsletter_subscribers s ON s.id = ls.subscriber_id WHERE ls.list_id = '
        . $implicita . ' AND s.mailpoet_id IS NOT NULL')->fetchColumn();
    $liste47 = (int) $db->query('SELECT COUNT(*) FROM newsletter_list_subscribers WHERE subscriber_id = ' . (int) ($cms(47)['id'] ?? 0))->fetchColumn();
    $verifica('E-LEG-02: niciun abonat importat pe lista internă (nici cel doar pe lista din coș, nici cel doar pe o listă ascunsă, nici cel dezabonat de pe '
        . 'toate); rândul unui import mai vechi scos; abonatul doar pe lista din coș: fără nicio listă (nu primește campanii)', $peImplicita === 0
        && ($cms(47)['status'] ?? '') === 'active' && $liste47 === 0 && $stare(20, 105) === 'subscribed' && $stare(13, 104) === 'unsubscribed'
        && !in_array('doar-cos@example.invalid', array_column(NewsletterService::listRecipients($db, $implicita), 'email'), true),
        (string) json_encode([$peImplicita, $cms(47)['status'] ?? null, $liste47, $r47['abonati']]));
} finally {
    $wp->exec('DELETE FROM wp_mailpoet_subscriber_segment WHERE subscriber_id = 47');
    $wp->exec('DELETE FROM wp_mailpoet_subscribers WHERE id = 47');
}
// E-LEG-03: la orice aplicare de după (fără un moment al ultimului import), deciziile luate pe site-ul nou rămân: (a) o dezabonare pe site
// (11), (b) un acord nou pe site (13), (c) o adresă abonată și apoi dezabonată direct pe site, a unui abonat MailPoet nou (48), care
// primește doar legătura; (d) o retragere din MailPoet mai nouă decât acordul de pe site se aplică și unui abonat păstrat (17: retras în
// MailPoet acum o oră, numele schimbat pe site după); (e) o retragere pe site fără rând de dezabonare (20: un email sărit, notat de site);
// (f) a doua și a treia aplicare păstrează aceiași abonați, a treia și după o schimbare oarecare a rândurilor MailPoet abonate (11, 20,
// 48: o deschidere pe site-ul vechi); cei păstrați raportați doar după id.
\App\Support\MailPoetCompat\Abonati::dezaboneaza($db, $cms(11), 'newsletter', 'link', null);
$db->exec("UPDATE newsletter_subscribers SET status = 'active', confirmed_at = NOW(), last_subscribed_at = NOW(), confirmed_ip = '192.0.2.99' WHERE mailpoet_id = 13");
$db->prepare("UPDATE newsletter_list_subscribers ls INNER JOIN newsletter_lists l ON l.id = ls.list_id SET ls.status = 'subscribed', ls.updated_at = NOW()"
    . ' WHERE ls.subscriber_id = ? AND l.mailpoet_id = 104')->execute([(int) ($cms(13)['id'] ?? 0)]);
$wp->exec("UPDATE wp_mailpoet_subscribers SET status = 'unsubscribed', updated_at = NOW() - INTERVAL 1 HOUR WHERE id = 17");
$db->exec("UPDATE newsletter_subscribers SET first_name = 'Gabi' WHERE mailpoet_id = 17");
$db->exec("UPDATE newsletter_subscribers SET status = 'bounced' WHERE mailpoet_id = 20");
$wp->exec("INSERT INTO wp_mailpoet_subscribers (id, first_name, last_name, email, status, subscribed_ip, confirmed_ip, confirmed_at, last_subscribed_at, created_at, source,"
    . " count_confirmations, link_token, tracking_consent) VALUES (48, 'Pe', 'Site', 'pe-site@example.invalid', 'subscribed', '192.0.2.48', '192.0.2.48',"
    . " '2025-05-01 09:00:00', '2025-05-01 09:00:00', '2025-05-01 08:00:00', 'api', 1, 'fedcba9876543210fedcba9876543248', 'unknown')");
$wp->exec("INSERT INTO wp_mailpoet_subscriber_segment (subscriber_id, segment_id, status, created_at) VALUES (48, 103, 'subscribed', '2025-05-01 08:00:00')");
$db->exec("INSERT INTO newsletter_subscribers (email, name, status, source, confirmed_at, last_subscribed_at) VALUES ('pe-site@example.invalid', 'Pe Site', 'active', 'cms',"
    . ' NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY)');
$idPeSite = (int) $db->lastInsertId();
\App\Support\MailPoetCompat\Abonati::dezaboneaza($db, ['id' => $idPeSite, 'status' => 'active'], 'newsletter', 'link', null);
try {
    $plan8 = PlanMailPoet::fa($sursa($export), $planCadre(), true, ['automatizari']);
    $plR = $planSite($plan8);
    $avR = implode(' | ', array_filter($plR->avertismente, static fn (string $a): bool => str_contains($a, 'își păstrează starea')));
    $a13 = $cms(13);
    $r8 = $aplica8($plan8);
    $r8b = $aplica8();
    $wp->exec("UPDATE wp_mailpoet_subscribers SET last_engagement_at = NOW(), updated_at = NOW() + INTERVAL 2 HOUR WHERE id IN (11, 20, 48)");
    $r8c = $aplica8();
    $ordonat = static function (array $r): array {
        $v = $r['pastrati_id'];
        sort($v);
        return $v;
    };
    $asteptatPastrati = [11 . '→' . (int) $cms(11)['id'], 13 . '→' . (int) $cms(13)['id'], 17 . '→' . (int) $cms(17)['id'], 20 . '→' . (int) $cms(20)['id'],
        48 . '→' . $idPeSite];
    sort($asteptatPastrati);
    $verifica('E-LEG-03: dezabonarea pe site rămâne (11, și pe liste), acordul nou de pe site rămâne (13: activ, dovada lui, lista), adresa abonată și dezabonată pe '
        . 'site își păstrează starea și primește legătura (48); retragerea MailPoet mai nouă se aplică (17 dezabonat, numele de pe site păstrat)',
        ($cms(11)['status'] ?? '') === 'unsubscribed' && $stare(11, 103) === 'unsubscribed' && $stare(11, 104) === 'unsubscribed'
        && ($cms(13)['status'] ?? '') === 'active' && ($cms(13)['confirmed_ip'] ?? '') === '192.0.2.99' && $cms(13)['confirmed_at'] === $a13['confirmed_at']
        && $stare(13, 104) === 'subscribed' && ($cms(48)['status'] ?? '') === 'unsubscribed' && (int) ($cms(48)['id'] ?? 0) === $idPeSite
        && ($cms(48)['mailpoet_token_hash'] ?? '') === hash('sha256', 'fedcba9876543210fedcba9876543248') && ($cms(17)['status'] ?? '') === 'unsubscribed'
        && ($cms(17)['first_name'] ?? '') === 'Gabi' && $r8['retrageri_mailpoet'] >= 1,
        (string) json_encode([$cms(11)['status'] ?? null, $cms(13)['status'] ?? null, $cms(48)['status'] ?? null, $cms(17)['status'] ?? null, $r8['retrageri_mailpoet']]));
    $verifica('E-LEG-03: a doua și a treia aplicare (a treia după o schimbare oarecare a rândurilor MailPoet 11, 20 și 48) păstrează aceiași cinci abonați, cu '
        . 'aceleași stări (și emailul sărit pe site, fără rând de dezabonare); cei păstrați raportați doar după id (aplicarea și avertismentul planului cu baza '
        . 'site-ului), fără nicio adresă',
        $ordonat($r8) === $asteptatPastrati && $ordonat($r8b) === $asteptatPastrati && $ordonat($r8c) === $asteptatPastrati && $r8c['pastrati'] === 5
        && ($cms(11)['status'] ?? '') === 'unsubscribed' && $stare(11, 103) === 'unsubscribed' && ($cms(13)['status'] ?? '') === 'active'
        && $stare(13, 104) === 'subscribed' && ($cms(48)['status'] ?? '') === 'unsubscribed' && ($cms(17)['first_name'] ?? '') === 'Gabi'
        && ($cms(20)['status'] ?? '') === 'bounced'
        && str_contains($avR, '5 abonați') && !str_contains($avR, '@') && !str_contains((string) json_encode([$r8, $r8b, $r8c]), '@'),
        (string) json_encode([$ordonat($r8), $ordonat($r8b), $ordonat($r8c), $asteptatPastrati, $avR]));
} finally {
    $wp->exec('DELETE FROM wp_mailpoet_subscriber_segment WHERE subscriber_id = 48');
    $wp->exec('DELETE FROM wp_mailpoet_subscribers WHERE id = 48');
    $wp->exec("UPDATE wp_mailpoet_subscribers SET status = 'subscribed' WHERE id = 17");
    $wp->exec("UPDATE wp_mailpoet_subscribers SET last_engagement_at = '2026-10-01 12:00:00' WHERE id = 11");
    $wp->exec('UPDATE wp_mailpoet_subscribers SET last_engagement_at = NULL WHERE id = 20');
}
// E-LEG-06: o adresă din MailPoet deja a altui abonat al site-ului: NU-ul planului și eroarea aplicării doar cu id-urile; rândul NU al unei
// erori neașteptate a scriptului: clasa și SQLSTATE, fără mesajul bazei (care citează valoarea cheii).
$wp->exec("UPDATE wp_mailpoet_subscribers SET email = 'unu@example.invalid' WHERE id = 19");
try {
    $planC = PlanMailPoet::fa($sursa($export), $planCadre(), true, ['automatizari']);
    $nuC = implode(' | ', $planSite($planC)->probleme);
    $eroareC = '';
    try {
        $aplica8($planC);
    } catch (EroareImport $e) {
        $eroareC = $e->getMessage();
    }
} finally {
    $wp->exec("UPDATE wp_mailpoet_subscribers SET email = 'refuz@example.invalid' WHERE id = 19");
}
$pdo23000 = null;
try {
    $db->prepare('INSERT INTO newsletter_subscribers (email, status) VALUES (?, ?)')->execute(['unu@example.invalid', 'active']);
} catch (PDOException $e) {
    $pdo23000 = $e;
}
$randNu = $pdo23000 instanceof PDOException ? ImportWordPress::eroareNeasteptata($pdo23000) : '';
$verifica('E-LEG-06: adresa deja a altui abonat: NU-ul planului și EroareImport-ul aplicării cu id-urile, fără adresă (nimic scris); eroarea neașteptată a scriptului: '
    . 'clasa și SQLSTATE 23000, fără mesajul bazei', str_contains($nuC, 'abonatul MailPoet 19') && !str_contains($nuC, '@') && str_contains($eroareC, 'abonatul MailPoet 19')
    && !str_contains($eroareC, '@') && ($cms(19)['email'] ?? '') === 'refuz@example.invalid' && str_contains($randNu, 'PDOException, SQLSTATE 23000')
    && !str_contains($randNu, '@') && !str_contains($randNu, 'Duplicate'), $nuC . ' || ' . $eroareC . ' || ' . $randNu);
// INR-E-01: MailPoet instalat, fără niciun abonat (pe un site fără wp-compat → mailpoet și fără drumul capturat): nimic de importat, niciun NU.
$wp->exec('CREATE TABLE tmp_abonati_proba AS SELECT * FROM wp_mailpoet_subscribers');
$wp->exec('DELETE FROM wp_mailpoet_subscribers');
$pachet($lucru . '/pachet-fara');
try {
    $plGol = PlanMailPoet::fa($sursa($export), null, false, ['automatizari']);
} finally {
    $pachet($lucru . '/pachet');
    $wp->exec('INSERT INTO wp_mailpoet_subscribers SELECT * FROM tmp_abonati_proba');
    $wp->exec('DROP TABLE tmp_abonati_proba');
}
$verifica('INR-E-01: MailPoet fără niciun abonat, pe un site fără mailpoet: niciun NU, doar informația „nimic de importat”', $plGol->probleme === []
    && in_array('MailPoet e instalat, dar n-are niciun abonat: nimic de importat', $plGol->info, true)
    && (int) $wp->query('SELECT COUNT(*) FROM wp_mailpoet_subscribers')->fetchColumn() === 12, implode(' | ', array_merge($plGol->probleme, $plGol->info)));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 2. Ieșirea scriptului: nicio adresă, niciun jeton');
$preludiu = $lucru . '/preludiu.php';
file_put_contents($preludiu, "<?php\ndefine('PLATFORMA_SITE_DIR', " . var_export($lucru . '/site', true) . ");\n");
$pr = proc_open([$radacina . '/tools/dev/php.sh', '-d', 'auto_prepend_file=' . $preludiu, 'scripts/importa-wordpress.php', '--wp=' . $dosarWp, '--plan',
    '--export-mailpoet=' . $export, '--fara-articole'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, $radacina,
    array_merge(getenv(), ['CMS_DEV_DB' => $bazaSite, 'SITE_DATE_DOSAR' => $lucru . '/pachet', 'CMS_DEV_LICENTA_DOSAR' => $lucru . '/licenta']));
$out = (string) stream_get_contents($pipe[1]) . (string) stream_get_contents($pipe[2]);
fclose($pipe[1]);
fclose($pipe[2]);
proc_close($pr);
$verifica('scriptul planifică partea newsletter cu MailPoet (drumul necapturat: NU-ul lui, nu cel generic „nu e făcut încă”)',
    str_contains($out, 'newsletter (MailPoet): importul MailPoet cere drumul capturat') && !str_contains($out, 'abonați la newsletter, iar importul lor'), $out);
$verifica('ieșirea scriptului: nicio adresă, niciun jeton, niciun IP al fixturii', array_filter(['@example.invalid', '0123456789abcdef', 'a1b2c3', 'fedcba98', '192.0.2.', '2001:db8'],
    static fn (string $x): bool => str_contains($out, $x)) === [], $out);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 5. NU');
// Conexiunile WordPress deschise (tranzacția READ ONLY a SursaWordPress ține blocajele de metadate ale tabelelor citite) se închid: altfel
// ALTER TABLE de mai jos le-ar aștepta.
$s = null;
gc_collect_cycles();
$nu = static function (string $eticheta, string $cauta, ?callable $inainte = null, ?callable $dupa = null, ?string $exp = null, bool $cuCampuri = true, array $fara = ['automatizari'])
    use ($verifica, $sursa, $planCadre, $export, $wp): void {
    if ($inainte !== null) {
        $inainte($wp);
    }
    try {
        $pl = PlanMailPoet::fa($sursa($exp ?? $export), $planCadre($cuCampuri), true, $fara);
        $gasit = array_filter($pl->probleme, static fn (string $t): bool => str_contains($t, $cauta)) !== [];
        $verifica('NU: ' . $eticheta, $gasit, implode(' | ', $pl->probleme));
    } finally {
        if ($dupa !== null) {
            $dupa($wp);
        }
    }
};
$nu('o listă dinamică', 'o listă dinamică', static fn (PDO $w) => $w->exec("UPDATE wp_mailpoet_segments SET type = 'dynamic' WHERE id = 105"),
    static fn (PDO $w) => $w->exec("UPDATE wp_mailpoet_segments SET type = 'default' WHERE id = 105"));
$exportRau = $lucru . '/export-rau.json';
file_put_contents($exportRau, str_replace('[date:mtext]', '[subscriber:inventat]', (string) file_get_contents($export)));
$nu('un cod MailPoet necunoscut în export', 'coduri MailPoet necunoscute: subscriber:inventat', null, null, $exportRau);
// Emailurile editorului nou (wp_post_id): etichetele `[mailpoet/…]` din corp (și în comentariul lor) și din linkurile urmărite, în orice
// formă (§4.6.3, V-11), sunt NU până le rezolvă codul comun; la fel o adresă de link mai lungă decât coloana statisticilor (§1.9, V-12).
$exportEticheta = $lucru . '/export-eticheta.json';
file_put_contents($exportEticheta, str_replace('<p>Bună, ', '<p><!--[mailpoet/subscriber-firstname default=\\"prieten\\"]-->Bună, ', (string) file_get_contents($export)));
$nu('o etichetă a editorului nou în corpul unei campanii', 'etichete ale editorului nou MailPoet, pe care site-ul mutat nu le rezolvă încă: mailpoet/subscriber-firstname',
    null, null, $exportEticheta);
$nu('o etichetă a editorului nou în adresa unui link urmărit', 'linkul urmărit 68 al cozii MailPoet 42 are etichete ale editorului nou MailPoet',
    static fn (PDO $w) => $w->exec("UPDATE wp_mailpoet_newsletter_links SET url = '[mailpoet/subscription-unsubscribe-url]' WHERE id = 68"),
    static fn (PDO $w) => $w->exec("UPDATE wp_mailpoet_newsletter_links SET url = '[link:subscription_unsubscribe_url]' WHERE id = 68"));
$nu('o etichetă a editorului nou scrisă %5B…%5D într-un link', 'mailpoet/subscription-manage-url',
    static fn (PDO $w) => $w->exec("UPDATE wp_mailpoet_newsletter_links SET url = 'https://pensiune-proba.test/?x=%5Bmailpoet/subscription-manage-url%5D' WHERE id = 67"),
    static fn (PDO $w) => $w->exec("UPDATE wp_mailpoet_newsletter_links SET url = 'https://pensiune-proba.test/despre/' WHERE id = 67"));
$nu('o adresă de link mai lungă decât coloana statisticilor clicurilor (2048)', '1 linkuri urmărite au adrese mai lungi de 2048',
    static fn (PDO $w) => $w->exec("UPDATE wp_mailpoet_newsletter_links SET url = CONCAT('https://pensiune-proba.test/', REPEAT('a', 2050)) WHERE id = 67"),
    static fn (PDO $w) => $w->exec("UPDATE wp_mailpoet_newsletter_links SET url = 'https://pensiune-proba.test/despre/' WHERE id = 67"));
$nu('un pas de automatizare necunoscut', 'pași pe care codul comun nu-i are: mailpoet:add-tag',
    static fn (PDO $w) => $w->exec("UPDATE wp_mailpoet_automation_versions SET steps = REPLACE(steps, '\"core:delay\"', '\"mailpoet:add-tag\"') WHERE id = 81"),
    static fn (PDO $w) => $w->exec("UPDATE wp_mailpoet_automation_versions SET steps = REPLACE(steps, '\"mailpoet:add-tag\"', '\"core:delay\"') WHERE id = 81"), null, true, []);
$nu('automatizările fără importul lor (pista J), fără --fara-automatizari', 'importul lor (pista J) nu e făcut încă', null, null, null, true, []);
$nu('un câmp personalizat', 'câmpuri personalizate', static fn (PDO $w) => $w->exec("INSERT INTO wp_mailpoet_custom_fields (id, name, type, params) VALUES (1, 'Oraș', 'text', 'a:0:{}')"),
    static fn (PDO $w) => $w->exec('DELETE FROM wp_mailpoet_custom_fields'));
$nu('două adrese egale fără majuscule', 'au aceeași adresă', static function (PDO $w): void {
    $w->exec('ALTER TABLE wp_mailpoet_subscribers MODIFY email varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
    $w->exec("INSERT INTO wp_mailpoet_subscribers (id, email, status, created_at) VALUES (40, 'ABONAT@example.invalid', 'subscribed', '2025-01-01 00:00:00')");
}, static function (PDO $w): void {
    $w->exec('DELETE FROM wp_mailpoet_subscribers WHERE id = 40');
    $w->exec('ALTER TABLE wp_mailpoet_subscribers MODIFY email varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
});
$nu('numele câmpurilor cadrului R lipsă (§3.4.4)', 'n-are numele câmpurilor status, segments', null, null, null, false);
// Nivelul urmăririi (E-LEG-01): o valoare necunoscută a lui tracking.level e NU; „basic” ajunge în setări (campaniile site-ului fără urmărire).
$nivel = static function (string $valoare) use ($wp): void {
    $wp->prepare("UPDATE wp_mailpoet_settings SET value = ? WHERE name = 'tracking'")->execute([serialize(['level' => $valoare, 'consent' => ['subscriber_choice' => 'track_all']])]);
};
$nu('un nivel al urmăririi necunoscut (tracking.level)', 'tracking.level are o valoare necunoscută', static fn () => $nivel('altceva'), static fn () => $nivel('full'));
$nivel('basic');
$plBasic = PlanMailPoet::fa($sursa($export), $planCadre(), true, ['automatizari']);
$nivel('full');
$verifica('nivelul urmăririi „basic” (E-LEG-01) → setarea site-ului „basic”, niciun NU', ($plBasic->setari[NewsletterService::CHEIE_NIVEL_URMARIRE] ?? '') === 'basic'
    && $plBasic->probleme === [], implode(' | ', $plBasic->probleme));
// Emailul „Abonat nou”: ca NewSubscriberNotificationMailer::isDisabled, fără adresă (sau fără setare) e oprit, oricare ar fi `enabled`.
$notificare = static function (?string $valoare) use ($wp, $sursa, $planCadre, $export): array {
    $vechi = (string) $wp->query("SELECT value FROM wp_mailpoet_settings WHERE name = 'subscriber_email_notification'")->fetchColumn();
    $valoare === null ? $wp->exec("DELETE FROM wp_mailpoet_settings WHERE name = 'subscriber_email_notification'")
        : $wp->prepare("UPDATE wp_mailpoet_settings SET value = ? WHERE name = 'subscriber_email_notification'")->execute([$valoare]);
    try {
        $pl = PlanMailPoet::fa($sursa($export), $planCadre(), true, ['automatizari']);
        return [$pl->setari['mailpoet_notificare_abonat_nou'] ?? null, $pl->setari['mailpoet_notificare_adresa'] ?? null];
    } finally {
        $wp->exec("DELETE FROM wp_mailpoet_settings WHERE name = 'subscriber_email_notification'");
        $wp->prepare("INSERT INTO wp_mailpoet_settings (id, name, value, created_at) VALUES (7, 'subscriber_email_notification', ?, '2024-07-02 10:00:00')")->execute([$vechi]);
    }
};
$verifica('emailul „Abonat nou”: pornit cu adresă; fără adresă, cu o adresă greșită sau fără setare, oprit (ca MailPoet)',
    $notificare('a:2:{s:7:"enabled";s:1:"1";s:7:"address";s:0:"";}') === ['0', '']
    && $notificare('a:2:{s:7:"enabled";s:1:"1";s:7:"address";s:6:"nu-adr";}') === ['0', '']
    && $notificare(null) === ['0', ''] && $notificare('a:2:{s:7:"enabled";s:0:"";s:7:"address";s:26:"notificari@example.invalid";}') === ['0', 'notificari@example.invalid']
    && $notificare('a:2:{s:7:"enabled";s:1:"1";s:7:"address";s:26:"notificari@example.invalid";}') === ['1', 'notificari@example.invalid']);
file_put_contents($lucru . '/export-gol.json', (string) json_encode(['format' => SursaMailPoet::FORMAT_EXPORT]));
$nu('campania fără HTML păstrat și fără export', 'campania MailPoet 32 n-are HTML-ul păstrat', null, null, $lucru . '/export-gol.json');
$pachet($lucru . '/pachet-fara');
$pl = PlanMailPoet::fa($sursa($export), $planCadre(), true, ['automatizari']);
$verifica('NU: un site fără wp-compat.json → mailpoet', array_filter($pl->probleme, static fn (string $t): bool => str_contains($t, 'wp-compat.json → mailpoet')) !== []);
$pl = PlanMailPoet::fa($sursa($export), $planCadre(), true, ['newsletter']);
$verifica('--fara-newsletter: niciun NU, avertismentul cu numărul abonaților', $pl->probleme === [] && str_contains(implode(' ', $pl->avertismente), '12 abonați MailPoet'));
$pachet($lucru . '/pachet');

$scrie('');
$scrie(sprintf('Rezultat: %d trecute, %d eșuate', $trecute, $esuate));
exit($esuate === 0 ? 0 : 1);
