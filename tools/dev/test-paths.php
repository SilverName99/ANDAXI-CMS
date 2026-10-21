<?php

declare(strict_types=1);

/**
 * Testele căilor site-ului (App\Support\Paths, planul Etapei 1, WP-07; docs/DEZVOLTARE.md, „Unde scrie codul”):
 * .env, storage/ (sesiunile, jurnalele, cache-ul, licența), docroot-ul cu uploads/ și sitemap-ul, stub-ul din
 * deploy/stub/ cu MENTENANTA și pingul /_andaxi/ping.
 *
 *   tools/dev/php.sh tools/dev/test-paths.php
 *   tools/dev/php.sh tools/dev/test-paths.php --doar-cai          # doar A (fără bază și fără server; în testul de fum)
 *   CMS_DEV_PHP=php8.3 tools/dev/php.sh tools/dev/test-paths.php  # cu PHP-ul minim (poarta G-paths le rulează pe amândouă)
 *
 * A. Fără server.
 *    A1 Așezarea de azi: fiecare cale e cea de până la WP-07 (rădăcina codului: .env, storage/, public/, uploads/,
 *       sitemap-ul); „..”, NUL, un dosar în numele sitemap-ului și un dosar gol pentru stocareSite sunt refuzate.
 *    A2 Așezarea din Etapa 2, într-un proces cu constantele stub-ului: .env și storage/ din dosarul site-ului,
 *       docroot-ul din PLATFORMA_PUBLIC_DIR (fără el: public_html/ de lângă site); stocareSite face dosarul cu
 *       gardul .htaccess și nu-l rescrie; jurnalul PHP al site-ului ales chiar dacă serverul are altul.
 *    A3 Căutarea din acceptanța planului: în bootstrap.php, src/, views/, public/, scripts/ și config/ nicio cale
 *       storage/, uploads/, sessions/, .env sau public/ construită în afara lui Paths (din __DIR__/dirname() sau
 *       lipită la o altă cale); comentariile nu contează.
 *    A4 Linia de comandă, cu setările php.ini-ului obișnuit (fără error_log, display_errors oprit): un avertisment și
 *       o eroare fatală de după bootstrap.php apar pe stderr, ca până acum, și nu doar într-un fișier; în Etapa 2 și
 *       în site/storage/logs/php.log.
 * B. Găzduirea simulată din Etapa 2 ($CMS_DEV_DIR/paths/acasa): o versiune de cod comună
 *    (platforma/releases/<versiune>/: fișierele din git cu modificările necomise, VERSION, plus un .env și o poză
 *    „capcană” pe care nu are voie să le folosească nimeni) și două site-uri (domains/a.exemplu.test,
 *    domains/b.exemplu.test: site/ cu .env, RELEASE și storage/; public_html/ cu stub-ul, assets/ copiat și
 *    uploads/), fiecare cu baza lui (cms_paths_a, cms_paths_b), instalate din linia de comandă ca
 *    `bin/platforma --site=` (Etapa 2) și servite de `php -S` pe CMS_DEV_PORT+9 și +10, cu un mediu gol:
 *    B1 instalarea: jurnalul migrărilor în site/storage/logs, versiunea în _platforma_migrari, nicio sesiune;
 *    B2 pingul: 200, JSON, versiunea din VERSION, „la_zi”, no-store, fără cookie și fără sesiune, și cu „/” la
 *       sfârșit (routerul îl duce tot la ping); .env-ul versiunii ignorat;
 *    B3 o pagină pe fiecare site: numele magazinului din baza lui; un sess_* în site/storage/sessions al lui (cu
 *       gardul), nimic în versiune; cookie-ul unui site nu deschide o sesiune pe celălalt;
 *    B4 pozele din uploads/ al public_html (servite de index.php, ca pe un server care trimite fișierele lipsă la PHP),
 *       nu din versiune; poza de distribuire (/og/…) cu cache-ul în site/storage/cache/og;
 *    B5 sitemap-ul scris de admin în public_html al site-ului, servit de acolo, nu și pe celălalt site;
 *    B6 jurnalul PHP al site-ului: o eroare scrisă de aplicație (seo.json stricat, citit și înaintea bootstrap-ului)
 *       doar în site/storage/logs/php.log al acelui site, nimic în jurnalul serverului; pluginuri.log tot acolo;
 *    B7 MENTENANTA: 503, Retry-After: 120, mentenanta.html (HEAD fără corp); /_andaxi/, /webhook/ și /api/erp/ trec;
 *       celălalt site neatins; fără fișier, din nou 200;
 *    B8 RELEASE lipsă, goală, de altă formă (și una care, fără verificarea formei, ar ajunge la versiunea care există:
 *       „../releases/<v>”, „./<v>”) sau spre o versiune care lipsește → 503; versiunea din site/releases/ găsită;
 *    B9 pingul: baza lipsă → 503 repede; un server care acceptă conexiunea și tace → 503 în aproximativ 2 secunde;
 *       baza goală → „fara_registru”; fără ultima_ord → „in_urma”;
 *    B10 linia de comandă: diagnostic.php arată .env-ul și storage/ ale site-ului; migreaza.php --stare; nicio sesiune;
 *    B11 versiunea de cod neatinsă de tot ce a rulat (aceleași fișiere, aceleași mărimi și date).
 * Bazele cms_paths_* se fac și se șterg aici; serverele se opresc și dosarul se șterge la final. Raport:
 * $CMS_DEV_DIR/test-paths-raport.txt; cod de ieșire 0 doar dacă totul a trecut.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/src/Support/Paths.php';
require_once $radacina . '/src/Support/Ping.php';

use App\Support\Paths;
use App\Support\Ping;

$doarCai = in_array('--doar-cai', array_slice($argv, 1), true);
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
@mkdir($dosarDev, 0770, true);
$fisierRaport = $dosarDev . '/test-paths-raport.txt';
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
$refuza = static function (callable $f): bool {
    try {
        $f();
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
};

$scrie('Test căi ANDAXI-CMS (Paths, stub, ping) — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

// =====================================================================
// A. Fără server
// =====================================================================
$scrie('');
$scrie('A1. Așezarea de azi (codul și site-ul în același dosar)');
$verifica('fără stub: rădăcina e codul, .env și storage/ în ea (ca bootstrap.php până acum)', !Paths::cuStub()
    && Paths::radacina() === $radacina && Paths::env() === $radacina . '/.env' && Paths::stocare() === $radacina . '/storage'
    && Paths::stocare('sessions') === $radacina . '/storage/sessions' && Paths::stocare('/cache/pages/') === $radacina . '/storage/cache/pages');
$verifica('docroot-ul e public/, pozele în public/uploads/, sitemap-ul în public/', Paths::public() === $radacina . '/public'
    && Paths::public('assets/css/app.css') === $radacina . '/public/assets/css/app.css' && Paths::uploads() === $radacina . '/public/uploads'
    && Paths::uploads('gallery') === $radacina . '/public/uploads/gallery' && Paths::sitemap() === $radacina . '/public/sitemap.xml'
    && Paths::sitemap('sitemap-nou.xml') === $radacina . '/public/sitemap-nou.xml');
$verifica('aceleași fișiere ca vechile construcții (realpath): storage/sessions, public/assets/css/app.css',
    realpath(Paths::stocare('sessions')) === realpath($radacina . '/src/Support/../../storage/sessions')
    && realpath(Paths::public('assets/css/app.css')) === realpath($radacina . '/views/../public/assets/css/app.css'));
$verifica('refuzate: „..”, NUL, un dosar în numele sitemap-ului, stocareSite fără dosar', $refuza(static fn () => Paths::stocare('../x'))
    && $refuza(static fn () => Paths::stocare("a\0b")) && $refuza(static fn () => Paths::public('assets/../../x'))
    && $refuza(static fn () => Paths::uploads('..')) && $refuza(static fn () => Paths::sitemap('a/b.xml'))
    && $refuza(static fn () => Paths::sitemap('..')) && $refuza(static fn () => Paths::sitemap(''))
    && $refuza(static fn () => Paths::stocareSite('/')));

$scrie('');
$scrie('A2. Așezarea din Etapa 2 (constantele stub-ului, într-un proces separat)');
$temp = sys_get_temp_dir() . '/cms-test-paths-' . getmypid();
@mkdir($temp . '/domains/x.exemplu.test/site', 0770, true);
$script = $temp . '/proba.php';
file_put_contents($script, '<?php
declare(strict_types=1);
[$_, $paths, $site, $public] = $argv + [null, null, null, null];
define("PLATFORMA_SITE_DIR", $site);
if ($public !== "-") {
    define("PLATFORMA_PUBLIC_DIR", $public);
}
require $paths;
ini_set("error_log", "/dev/null");
$sesiuni = \App\Support\Paths::stocareSite("sessions");
file_put_contents($sesiuni . "/.htaccess", "gardul meu\n");
\App\Support\Paths::stocareSite("sessions");
\App\Support\Paths::alegeJurnalulPhp();
echo json_encode([
    "stub" => \App\Support\Paths::cuStub(), "radacina" => \App\Support\Paths::radacina(), "env" => \App\Support\Paths::env(),
    "stocare" => \App\Support\Paths::stocare("cache/og"), "public" => \App\Support\Paths::public(),
    "uploads" => \App\Support\Paths::uploads("gallery"), "sitemap" => \App\Support\Paths::sitemap("sitemap-x.xml"),
    "verificare" => \App\Support\Paths::stocareSite("verificare"), "jurnal" => ini_get("error_log"),
    "gard_sesiuni" => file_get_contents($sesiuni . "/.htaccess"),
    "gard_verificare" => (string) @file_get_contents(\App\Support\Paths::stocare("verificare/.htaccess")),
]);
');
$ruleazaProba = static function (string $public) use ($script, $radacina, $temp): array {
    $iesire = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($radacina . '/src/Support/Paths.php')
        . ' ' . escapeshellarg($temp . '/domains/x.exemplu.test/site') . ' ' . escapeshellarg($public) . ' 2>&1');
    $date = json_decode($iesire, true);
    return is_array($date) ? $date : ['eroare' => $iesire];
};
$site = $temp . '/domains/x.exemplu.test/site';
$p = $ruleazaProba($temp . '/docroot');
$verifica('cu stub: .env și storage/ în dosarul site-ului, codul rămâne versiunea, docroot-ul e PLATFORMA_PUBLIC_DIR',
    ($p['stub'] ?? null) === true && ($p['radacina'] ?? '') === $radacina && ($p['env'] ?? '') === $site . '/.env'
    && ($p['stocare'] ?? '') === $site . '/storage/cache/og' && ($p['public'] ?? '') === $temp . '/docroot'
    && ($p['uploads'] ?? '') === $temp . '/docroot/uploads/gallery' && ($p['sitemap'] ?? '') === $temp . '/docroot/sitemap-x.xml',
    json_encode($p, JSON_UNESCAPED_SLASHES) ?: '');
$verifica('stocareSite: dosarul site-ului făcut, cu gardul .htaccess, un gard existent nerescris',
    is_dir($site . '/storage/sessions') && ($p['gard_sesiuni'] ?? '') === "gardul meu\n" && ($p['verificare'] ?? '') === $site . '/storage/verificare'
    && ($p['gard_verificare'] ?? '') === Paths::GARD, json_encode($p, JSON_UNESCAPED_SLASHES) ?: '');
$verifica('jurnalul PHP: site/storage/logs/php.log, chiar dacă serverul are altul (în Etapa 2 mereu al site-ului)',
    ($p['jurnal'] ?? '') === $site . '/storage/logs/php.log' && is_file($site . '/storage/logs/.htaccess'), (string) ($p['jurnal'] ?? ''));
$p = $ruleazaProba('-');
$verifica('fără PLATFORMA_PUBLIC_DIR (o comandă din linia de comandă): docroot-ul e public_html/ de lângă dosarul site-ului',
    ($p['public'] ?? '') === $temp . '/domains/x.exemplu.test/public_html', (string) ($p['public'] ?? json_encode($p)));
exec('rm -rf ' . escapeshellarg($temp));

$scrie('');
$scrie('A3. Nicio cale a site-ului construită în afara lui Paths (acceptanța WP-07)');
/** @return array<string, string> „fișier:rând” => textul, pentru fiecare rând cu o cale construită în afara lui Paths */
$cautaCai = static function (string $radacina): array {
    $fisiere = [$radacina . '/bootstrap.php'];
    foreach (['src', 'views', 'public', 'scripts', 'config'] as $dosar) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radacina . '/' . $dosar, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $fisiere[] = $f->getPathname();
            }
        }
    }
    sort($fisiere);
    $gasite = [];
    foreach ($fisiere as $fisier) {
        $relativ = substr($fisier, strlen($radacina) + 1);
        if ($relativ === 'src/Support/Paths.php') {
            continue;
        }
        $tokenuri = token_get_all((string) file_get_contents($fisier));
        $linii = [];
        $anterior = null;
        foreach ($tokenuri as $token) {
            [$tip, $text, $rand] = is_array($token) ? $token : [null, $token, null];
            if ($rand !== null) {
                $randCurent = $rand;
            }
            $randCurent ??= 1;
            if (in_array($tip, [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                $randCurent += substr_count($text, "\n");
                continue;
            }
            // (b) un text-cale („/storage…”, „/../public…”, „/.env”) lipit după altă expresie: o cale construită.
            if ($tip === T_CONSTANT_ENCAPSED_STRING && $anterior === '.'
                && preg_match('#^/(\.\./)*(storage|public|uploads|sessions)(/|$)|^/\.env$#', substr($text, 1, -1)) === 1) {
                $gasite[$relativ . ':' . $randCurent] ??= $text;
            }
            $linii[$randCurent] = ($linii[$randCurent] ?? '') . $text;
            $randCurent += substr_count($text, "\n");
            if ($tip !== T_WHITESPACE) {
                $anterior = $text;
            }
        }
        // (a) căutarea din plan: __DIR__ sau dirname() pe un rând de cod care pomenește storage, uploads, sessions, .env, public.
        foreach ($linii as $rand => $text) {
            if (preg_match('/__DIR__|dirname\(/', $text) === 1 && preg_match('#storage|uploads|sessions|\.env|/public\b|[\'"]public[\'"/]#', $text) === 1) {
                $gasite[$relativ . ':' . $rand] ??= trim($text);
            }
        }
    }
    ksort($gasite);
    return $gasite;
};
$text = static fn (array $gasite): string => implode("\n          ", array_map(static fn (string $k, string $v): string => $k . ': ' . $v,
    array_keys($gasite), $gasite));
$gasite = $cautaCai($radacina);
$verifica('bootstrap.php, src/, views/, public/, scripts/, config/: nicio cale storage/uploads/sessions/.env/public construită în afara Paths',
    $gasite === [], $text(array_slice($gasite, 0, 20, true)));
// Căutarea însăși trebuie să prindă construcțiile de dinainte de WP-07 (altfel ar trece în gol).
$temp = sys_get_temp_dir() . '/cms-test-paths-cautare-' . getmypid();
foreach (['src', 'views', 'public', 'scripts', 'config'] as $dosar) {
    @mkdir($temp . '/' . $dosar, 0770, true);
}
file_put_contents($temp . '/bootstrap.php', "<?php\n// __DIR__ . '/storage/sessions' doar într-un comentariu\n\$a = __DIR__ . '/storage/sessions';\n");
file_put_contents($temp . '/src/A.php', "<?php\n/** dirname(__DIR__, 2) . '/.env' */\n\$b = \$radacina . '/storage/cache/og';\n\$c = \$x\n    . '/.env';\n\$d = '/uploads/gallery/' . \$nume;\n");
file_put_contents($temp . '/views/v.php', "<p>__DIR__ storage</p>\n<?php \$v = @filemtime(__DIR__ . '/../public/assets/css/app.css'); ?>\n");
$prinse = $cautaCai($temp);
exec('rm -rf ' . escapeshellarg($temp));
$verifica('căutarea prinde construcțiile vechi (sesiunile din __DIR__, o cale lipită de o variabilă, și pe rândul următor, assets din views) '
    . 'și sare comentariile, HTML-ul și adresele de pagină', array_keys($prinse) === ['bootstrap.php:3', 'src/A.php:3', 'src/A.php:5', 'views/v.php:2'],
    $text($prinse));

$scrie('');
$scrie('A4. Erorile PHP ale liniei de comandă (cronurile, migreaza.php, install.php): pe ecran, ca până acum');
// Un script care trece prin bootstrap.php, apoi scrie un avertisment și cade cu o excepție neprinsă, rulat cu setările
// php.ini-ului obișnuit al liniei de comandă (Debian, alt-php: display_errors oprit, log_errors pornit, fără error_log,
// deci PHP scrie erorile pe stderr). Uneltele de dezvoltare dau mereu un error_log (php.sh), așa că doar aici se vede.
$temp = sys_get_temp_dir() . '/cms-test-paths-cli-' . getmypid();
@mkdir($temp . '/site', 0770, true);
$semn = 'proba-cli-' . getmypid();
$scriptCli = $temp . '/eroare.php';
file_put_contents($scriptCli, '<?php
declare(strict_types=1);
[$_, $bootstrap, $site] = $argv + [null, null, null];
if ($site !== "-") {
    define("PLATFORMA_SITE_DIR", $site);
}
require $bootstrap;
trigger_error("' . $semn . '-avertisment", E_USER_WARNING);
throw new RuntimeException("' . $semn . '-fatala");
');
/** @return array{0: int, 1: string} codul de ieșire și stderr */
$ruleazaCuEroare = static function (string $site) use ($scriptCli, $radacina): array {
    $proces = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=', '-d', 'error_reporting=E_ALL',
        $scriptCli, $radacina . '/bootstrap.php', $site], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tevi);
    if (!is_resource($proces)) {
        return [-1, 'nu pornește'];
    }
    stream_get_contents($tevi[1]);
    $erori = (string) stream_get_contents($tevi[2]);
    fclose($tevi[1]);
    fclose($tevi[2]);
    return [proc_close($proces), $erori];
};
$jurnalCod = Paths::stocare('logs/php.log');
[$c, $erori] = $ruleazaCuEroare('-');
$verifica('așezarea de azi: avertismentul și eroarea fatală pe stderr (terminalul, ieșirea cronului), cod 255, nimic în '
    . 'storage/logs/php.log', $c === 255 && str_contains($erori, $semn . '-avertisment') && str_contains($erori, 'Uncaught RuntimeException: ' . $semn . '-fatala')
    && !str_contains((string) @file_get_contents($jurnalCod), $semn), 'cod ' . $c . ', stderr: ' . mb_substr(trim($erori), 0, 300));
[$c, $erori] = $ruleazaCuEroare($temp . '/site');
$jurnalSite = (string) @file_get_contents($temp . '/site/storage/logs/php.log');
$verifica('Etapa 2 (bin/platforma --site=): tot pe stderr și, în plus, în site/storage/logs/php.log al site-ului, cod 255',
    $c === 255 && str_contains($erori, $semn . '-avertisment') && str_contains($erori, 'Uncaught RuntimeException: ' . $semn . '-fatala')
    && str_contains($jurnalSite, $semn . '-avertisment') && str_contains($jurnalSite, $semn . '-fatala')
    && !str_contains((string) @file_get_contents($jurnalCod), $semn), 'cod ' . $c . ', stderr: ' . mb_substr(trim($erori), 0, 300)
    . ' | jurnal: ' . mb_substr(trim($jurnalSite), 0, 300));
exec('rm -rf ' . escapeshellarg($temp));

if ($doarCai) {
    $scrie('');
    $scrie('Rezultat: ' . $trecute . ' verificări trecute, ' . $esuate . ' eșuate (doar A, --doar-cai).');
    exit($esuate === 0 ? 0 : 1);
}

// =====================================================================
// B. Găzduirea simulată din Etapa 2
// =====================================================================
$portBaza = (int) (getenv('CMS_DEV_PORT') ?: 8701);
$porturi = ['a' => $portBaza + 9, 'b' => $portBaza + 10];
$portTacut = $portBaza + 11;
$dosarTest = $dosarDev . '/paths';
$acasa = $dosarTest . '/acasa';
$versiune = '2026.10.1-abcdef0';
$versiuneLocala = '2026.10.2-1234567';
$cod = $acasa . '/platforma/releases/' . $versiune;
$ruleazaCli = $acasa . '/platforma/bin/ruleaza.php';
$siteuri = [];
foreach (['a', 'b'] as $x) {
    $domeniu = $acasa . '/domains/' . $x . '.exemplu.test';
    $siteuri[$x] = ['site' => $domeniu . '/site', 'public' => $domeniu . '/public_html', 'baza' => 'cms_paths_' . $x,
        'nume' => 'Site ' . strtoupper($x) . ' Demo', 'jurnal_server' => $dosarTest . '/server-' . $x . '-erori.log'];
}
$gauraNeagra = 'http://127.0.0.1:9';
$sock = (string) ini_get('pdo_mysql.default_socket');
$parolaAdmin = 'Admin-' . bin2hex(random_bytes(8));
$pidServere = [];
$pidTacut = 0;

$root = new PDO('mysql:host=localhost', (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$opreste = static function (int $pid): void {
    if ($pid > 0) {
        exec('pkill -TERM -P ' . $pid . ' 2>/dev/null; kill ' . $pid . ' 2>/dev/null');
        for ($i = 0; $i < 50 && trim((string) shell_exec('pgrep -P ' . $pid . ' 2>/dev/null; kill -0 ' . $pid . ' 2>/dev/null && echo x')) !== ''; $i++) {
            usleep(100000);
        }
    }
};
$curata = static function () use (&$pidServere, &$pidTacut, $opreste, $root, $dosarTest, $dosarDev): void {
    foreach ($pidServere as $pid) {
        $opreste($pid);
    }
    $pidServere = [];
    $opreste($pidTacut);
    $pidTacut = 0;
    foreach (['cms_paths_a', 'cms_paths_b', 'cms_paths_gol'] as $baza) {
        try {
            $root->exec('DROP DATABASE IF EXISTS ' . $baza);
        } catch (Throwable) {
        }
    }
    if (str_starts_with($dosarTest, $dosarDev . '/') && is_dir($dosarTest)) {
        exec('rm -rf ' . escapeshellarg($dosarTest));
    }
};
/** Mediul proceselor: gol, ca pe Hostinger (totul din .env), plus plasa de siguranță spre internet. */
$mediu = static function () use ($gauraNeagra, $acasa, $dosarTest): array {
    return [
        'PATH' => (string) getenv('PATH'), 'HOME' => $acasa, 'LANG' => 'C.UTF-8',
        'http_proxy' => $gauraNeagra, 'https_proxy' => $gauraNeagra, 'HTTP_PROXY' => $gauraNeagra,
        'HTTPS_PROXY' => $gauraNeagra, 'ALL_PROXY' => $gauraNeagra, 'all_proxy' => $gauraNeagra,
        'NO_PROXY' => '127.0.0.1,localhost', 'no_proxy' => '127.0.0.1,localhost',
        // Doar pentru mail-capturat.sh (n-ar trebui să plece niciun email).
        'CMS_DEV_DIR' => $dosarTest,
    ];
};
/** Un script al versiunii, din linia de comandă, pentru un site (ca `bin/platforma --site=`): [cod, ieșire, erori]. */
$cli = static function (string $x, string $scriptCod, array $argumente = []) use (&$siteuri, $ruleazaCli, $cod, $sock, $mediu): array {
    $comanda = array_merge([PHP_BINARY, '-d', 'pdo_mysql.default_socket=' . $sock, '-d', 'display_errors=stderr', '-d', 'log_errors=1',
        '-d', 'error_reporting=E_ALL', '-d', 'allow_url_fopen=0', $ruleazaCli, $siteuri[$x]['site'],
        str_starts_with($scriptCod, '/') ? $scriptCod : $cod . '/' . $scriptCod], $argumente);
    $proces = proc_open($comanda, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tevi, $cod, $mediu());
    if (!is_resource($proces)) {
        return [-1, '', 'nu pornește'];
    }
    $iesire = (string) stream_get_contents($tevi[1]);
    $erori = (string) stream_get_contents($tevi[2]);
    fclose($tevi[1]);
    fclose($tevi[2]);
    return [proc_close($proces), $iesire, $erori];
};
$cerere = static function (string $x, string $metoda, string $cale, array $optiuni = []) use (&$porturi): array {
    $c = curl_init('http://127.0.0.1:' . $porturi[$x] . $cale);
    $antete = [];
    $cookies = [];
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_NOBODY => $metoda === 'HEAD', CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60,
        CURLOPT_PATH_AS_IS => true,
        CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$antete, &$cookies): int {
            if (str_contains($linie, ':')) {
                [$nume, $valoare] = explode(':', $linie, 2);
                $nume = strtolower(trim($nume));
                $antete[$nume] = trim($valoare);
                if ($nume === 'set-cookie') {
                    $cookies[] = trim($valoare);
                }
            }
            return strlen($linie);
        },
    ]);
    if (isset($optiuni['cookie'])) {
        curl_setopt($c, CURLOPT_COOKIE, $optiuni['cookie']);
    }
    if (isset($optiuni['post'])) {
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($optiuni['post']));
    }
    $inceput = microtime(true);
    $corp = (string) curl_exec($c);
    $durata = microtime(true) - $inceput;
    $codHttp = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $codHttp, 'antete' => $antete, 'cookies' => $cookies, 'corp' => $corp, 'durata' => $durata];
};
$pornesteServerul = static function (string $x) use (&$pidServere, &$porturi, &$siteuri, $cod, $sock, $mediu, $cerere, $dosarTest): bool {
    $env = '';
    foreach ($mediu() + ['PHP_CLI_SERVER_WORKERS' => '2'] as $nume => $valoare) {
        $env .= ' ' . $nume . '=' . escapeshellarg($valoare);
    }
    $ini = ['-d', 'pdo_mysql.default_socket=' . $sock, '-d', 'error_reporting=E_ALL', '-d', 'display_errors=0', '-d', 'log_errors=1',
        '-d', 'error_log=' . $siteuri[$x]['jurnal_server'], '-d', 'sendmail_path=' . $cod . '/tools/dev/mail-capturat.sh',
        '-d', 'memory_limit=512M', '-d', 'allow_url_fopen=0'];
    // Docroot-ul e public_html/ al site-ului (stub-ul); routerul de dezvoltare face ce face .htaccess-ul de acolo.
    $comanda = 'cd ' . escapeshellarg($siteuri[$x]['public']) . ' && env -i' . $env . ' nohup ' . escapeshellarg(PHP_BINARY) . ' '
        . implode(' ', array_map('escapeshellarg', $ini)) . ' -S 127.0.0.1:' . $porturi[$x] . ' -t ' . escapeshellarg($siteuri[$x]['public'])
        . ' ' . escapeshellarg($cod . '/tools/dev/router.php') . ' >> ' . escapeshellarg($dosarTest . '/server-' . $x . '.log') . ' 2>&1 & echo $!';
    $pidServere[$x] = (int) trim((string) shell_exec($comanda));
    for ($i = 0; $i < 50; $i++) {
        if ($cerere($x, 'GET', '/_andaxi/ping')['cod'] !== 0) {
            return true;
        }
        usleep(200000);
    }
    return false;
};
$scrieEnv = static function (string $x, array $schimbari) use (&$siteuri): void {
    $fisier = $siteuri[$x]['site'] . '/.env';
    $text = (string) file_get_contents($fisier);
    foreach ($schimbari as $cheie => $valoare) {
        $text = preg_match('/^' . preg_quote($cheie, '/') . '=/m', $text) === 1
            ? (string) preg_replace('/^' . preg_quote($cheie, '/') . '=.*$/m', $cheie . '=' . str_replace('$', '\\$', $valoare), $text)
            : $text . $cheie . '=' . $valoare . "\n";
    }
    file_put_contents($fisier, $text);
};
$sesiuni = static fn (string $dosar): array => array_map('basename', glob($dosar . '/sess_*') ?: []);
/** Fiecare fișier de sub $dosar, cu mărimea și data lui (pentru „versiunea neatinsă”). */
$amprentaDosar = static function (string $dosar): array {
    $rez = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dosar, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rez[substr($f->getPathname(), strlen($dosar))] = $f->isDir() ? 'dosar' : $f->getSize() . '|' . $f->getMTime();
    }
    ksort($rez);
    return $rez;
};
$copiazaDosar = static function (string $din, string $in): void {
    exec('mkdir -p ' . escapeshellarg($in) . ' && cp -a ' . escapeshellarg($din) . '/. ' . escapeshellarg($in) . '/');
};
$json = static fn (array $r): ?array => is_array($d = json_decode($r['corp'], true)) ? $d : null;
$faraCookie = static fn (array $r): bool => $r['cookies'] === [];

try {
    $curata();
    foreach (array_keys($porturi) as $x) {
        if ($cerere($x, 'GET', '/')['cod'] !== 0) {
            throw new RuntimeException('pe portul ' . $porturi[$x] . ' răspunde deja un server; oprește-l întâi');
        }
    }

    // ── Versiunea de cod comună ──
    $scrie('');
    $scrie('B. Găzduirea simulată: ' . $acasa);
    mkdir($cod, 0770, true);
    $lista = shell_exec('git -C ' . escapeshellarg($radacina) . ' ls-files -z --cached --others --exclude-standard');
    $copiate = 0;
    foreach (array_filter(explode("\0", (string) $lista), static fn (string $f): bool => $f !== '') as $fisier) {
        if (!is_file($radacina . '/' . $fisier) || str_starts_with($fisier, '.env')
            || (str_starts_with($fisier, 'storage/') && !in_array(basename($fisier), ['.gitkeep', '.gitignore'], true))) {
            continue;
        }
        @mkdir(dirname($cod . '/' . $fisier), 0770, true);
        copy($radacina . '/' . $fisier, $cod . '/' . $fisier);
        @chmod($cod . '/' . $fisier, fileperms($radacina . '/' . $fisier) & 0777);
        $copiate++;
    }
    file_put_contents($cod . '/VERSION', $versiune . "\n");
    // Capcanele: dacă le-ar folosi ceva, s-ar vedea mai jos (pingul ar da 503, poza ar ieși 200).
    file_put_contents($cod . '/.env', "DB_DATABASE=cms_paths_capcana\nAPP_NAME=Capcana\n");
    @mkdir($cod . '/public/uploads/gallery', 0770, true);
    copy($cod . '/public/assets/img/product-placeholder.svg', $cod . '/public/uploads/gallery/capcana.svg');
    // Ca `bin/platforma --site=<dosar>` din Etapa 2: constantele stub-ului (fără docroot: public_html de lângă), apoi scriptul.
    mkdir(dirname($ruleazaCli), 0770, true);
    file_put_contents($ruleazaCli, "<?php\n\ndeclare(strict_types=1);\n\n// Ca bin/platforma --site=<dosar> (Etapa 2): constanta stub-ului, apoi scriptul versiunii (tools/dev/test-paths.php).\n"
        . "define('PLATFORMA_SITE_DIR', \$argv[1]);\n\$script = \$argv[2];\n\$argv = array_merge([\$script], array_slice(\$argv, 3));\n"
        . "\$argc = count(\$argv);\n\$_SERVER['argv'] = \$argv;\n\$_SERVER['argc'] = \$argc;\nrequire \$script;\n");
    $verifica('versiunea de cod (' . $copiate . ' fișiere, fără storage/ și fără .env) cu VERSION, stub-ul în deploy/stub/',
        $copiate > 100 && is_file($cod . '/deploy/stub/index.php') && is_file($cod . '/deploy/stub/mentenanta.html') && is_file($cod . '/deploy/stub/.htaccess'));

    // ── Cele două site-uri ──
    foreach ($siteuri as $x => $s) {
        foreach (['backups', 'cache/pages', 'import', 'licenta'] as $dosar) {
            // Ce face „site nou” (Etapa 2) din scheletul storage/ al versiunii; sessions/ și logs/ le face Paths.
            mkdir($s['site'] . '/storage/' . $dosar, 0770, true);
        }
        file_put_contents($s['site'] . '/RELEASE', $versiune . "\n");
        file_put_contents($s['site'] . '/.env', implode("\n", [
            'APP_NAME=' . $s['nume'], 'APP_ENV=production', 'APP_DEBUG=false', 'APP_URL=http://127.0.0.1:' . $porturi[$x],
            'DB_HOST=localhost', 'DB_PORT=3306', 'DB_DATABASE=' . $s['baza'], 'DB_USERNAME=' . (string) getenv('DB_USERNAME'),
            'DB_PASSWORD=' . (string) getenv('DB_PASSWORD'), 'ADMIN_DEFAULT_EMAIL=admin@exemplu.ro', 'ADMIN_DEFAULT_PASSWORD=' . $parolaAdmin,
            'FAN_API_BASE=' . $gauraNeagra, 'BT_IPAY_MODE=test', 'BT_IPAY_BASE_URL_OVERRIDE=' . $gauraNeagra, '',
        ]));
        mkdir($s['public'] . '/uploads/gallery', 0770, true);
        foreach (['index.php', '.htaccess', 'mentenanta.html'] as $fisier) {
            copy($cod . '/deploy/stub/' . $fisier, $s['public'] . '/' . $fisier);
        }
        // Ca la comutarea pe o versiune (Etapa 2): o copie a assets/ versiunii în public_html.
        $copiazaDosar($cod . '/public/assets', $s['public'] . '/assets');
        $root->exec('CREATE DATABASE ' . $s['baza'] . ' CHARACTER SET utf8mb4');
    }
    $root->exec('CREATE DATABASE cms_paths_gol CHARACTER SET utf8mb4');
    // O poză doar în uploads/ al site-ului A (un PNG mic, făcut aici).
    $poza = imagecreatetruecolor(300, 200);
    imagefill($poza, 0, 0, imagecolorallocate($poza, 30, 120, 200));
    imagepng($poza, $siteuri['a']['public'] . '/uploads/gallery/proba.png');
    $amprentaCod = $amprentaDosar($cod);

    // ── B1. Instalarea, din linia de comandă ──
    $scrie('');
    $scrie('B1. Instalarea fiecărui site (scripts/install.php, ca bin/platforma --site=)');
    foreach ($siteuri as $x => $s) {
        [$c, $iesire, $erori] = $cli($x, 'scripts/install.php');
        $verifica('site ' . $x . ': install.php → 0, „Install finalizat”, pe baza lui', $c === 0 && str_contains($iesire, 'Install finalizat')
            && (int) $root->query('SELECT COUNT(*) FROM ' . $s['baza'] . '.admins')->fetchColumn() === 1, 'cod ' . $c . ' | ' . trim($erori) . ' | ' . trim($iesire));
        $root->prepare('INSERT INTO ' . $s['baza'] . '.settings (`key`, `value`) VALUES (\'store_name\', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
            ->execute([$s['nume']]);
    }
    $verifica('jurnalul migrărilor în site/storage/logs/migrari.log al fiecăruia, nimic în versiune', is_file($siteuri['a']['site'] . '/storage/logs/migrari.log')
        && is_file($siteuri['b']['site'] . '/storage/logs/migrari.log') && !is_file($cod . '/storage/logs/migrari.log'));
    $versiuniInregistrate = $root->query("SELECT COUNT(*) FROM cms_paths_a._platforma_migrari WHERE cod_versiune = '" . $versiune . "'")->fetchColumn();
    $verifica('migrările au versiunea din VERSION (_platforma_migrari.cod_versiune)', (int) $versiuniInregistrate > 0, (string) $versiuniInregistrate);
    $verifica('linia de comandă nu lasă nicio sesiune (nici în site-uri, nici în versiune)', $sesiuni($siteuri['a']['site'] . '/storage/sessions') === []
        && $sesiuni($siteuri['b']['site'] . '/storage/sessions') === [] && $sesiuni($cod . '/storage/sessions') === []);

    foreach (array_keys($siteuri) as $x) {
        $verifica('serverul site-ului ' . $x . ' pornește (docroot public_html cu stub-ul, port ' . $porturi[$x] . ', mediu gol)', $pornesteServerul($x));
    }

    // ── B2. Pingul ──
    $scrie('');
    $scrie('B2. GET /_andaxi/ping');
    foreach (array_keys($siteuri) as $x) {
        $r = $cerere($x, 'GET', '/_andaxi/ping');
        $verifica('site ' . $x . ': 200 {"ok":true,"db":true,"cod":"' . $versiune . '","schema":"la_zi"}, JSON, no-store, fără cookie',
            $r['cod'] === 200 && $r['corp'] === '{"ok":true,"db":true,"cod":"' . $versiune . '","schema":"la_zi"}'
            && str_starts_with($r['antete']['content-type'] ?? '', 'application/json') && ($r['antete']['cache-control'] ?? '') === 'no-store'
            && $faraCookie($r), $r['cod'] . ' ' . $r['corp']);
    }
    $r = $cerere('a', 'HEAD', '/_andaxi/ping');
    $verifica('HEAD: 200, fără corp', $r['cod'] === 200 && $r['corp'] === '', (string) $r['cod']);
    $r = $cerere('a', 'GET', '/_andaxi/ping?gclid=x&utm_source=google');
    $verifica('cu parametri: tot pingul, fără cookie (nici cel al reclamelor)', $r['cod'] === 200 && $faraCookie($r), implode(' | ', $r['cookies']));
    // Routerul scoate „/”-ul final, deci și /_andaxi/ping/ ajunge la ping: trebuie servit tot de calea rapidă, nu după sesiune.
    $r = $cerere('a', 'GET', '/_andaxi/ping/');
    $rHead = $cerere('a', 'HEAD', '/_andaxi/ping/?x=1');
    $verifica('cu „/” la sfârșit (GET și HEAD cu parametri): tot pingul, același JSON, no-store, fără cookie', $r['cod'] === 200
        && $r['corp'] === '{"ok":true,"db":true,"cod":"' . $versiune . '","schema":"la_zi"}' && ($r['antete']['cache-control'] ?? '') === 'no-store'
        && $faraCookie($r) && $rHead['cod'] === 200 && $rHead['corp'] === '' && $faraCookie($rHead),
        $r['cod'] . ' ' . $r['corp'] . ' | ' . implode(' | ', array_merge($r['cookies'], $rHead['cookies'])));
    $verifica('.env-ul din versiune ignorat, niciun fișier de sesiune', $sesiuni($siteuri['a']['site'] . '/storage/sessions') === []
        && $sesiuni($siteuri['b']['site'] . '/storage/sessions') === [] && $sesiuni($cod . '/storage/sessions') === []);

    // ── B3. O pagină și sesiunea ei ──
    $scrie('');
    $scrie('B3. Paginile și sesiunile');
    $pagini = [];
    foreach ($siteuri as $x => $s) {
        $pagini[$x] = $r = $cerere($x, 'GET', '/');
        $altul = $x === 'a' ? 'b' : 'a';
        $verifica('site ' . $x . ': prima pagină 200, cu numele din baza lui („' . $s['nume'] . '”), nu al celuilalt', $r['cod'] === 200
            && str_contains($r['corp'], $s['nume']) && !str_contains($r['corp'], $siteuri[$altul]['nume']) && !str_contains($r['corp'], 'Capcana'),
            (string) $r['cod']);
    }
    $idSesiune = static function (array $r): string {
        foreach ($r['cookies'] as $cookie) {
            if (preg_match('/^PHPSESSID=([^;]+)/', $cookie, $m) === 1) {
                return $m[1];
            }
        }
        return '';
    };
    $idA = $idSesiune($pagini['a']);
    $idB = $idSesiune($pagini['b']);
    $verifica('fiecare site: sesiunea în site/storage/sessions al lui (cu gardul .htaccess), nicio sesiune în versiune',
        $idA !== '' && $idB !== '' && $sesiuni($siteuri['a']['site'] . '/storage/sessions') === ['sess_' . $idA]
        && $sesiuni($siteuri['b']['site'] . '/storage/sessions') === ['sess_' . $idB] && $sesiuni($cod . '/storage/sessions') === []
        && (string) @file_get_contents($siteuri['a']['site'] . '/storage/sessions/.htaccess') === Paths::GARD
        && (string) @file_get_contents($siteuri['b']['site'] . '/storage/sessions/.htaccess') === Paths::GARD,
        'a=' . implode(',', $sesiuni($siteuri['a']['site'] . '/storage/sessions')) . ' b=' . implode(',', $sesiuni($siteuri['b']['site'] . '/storage/sessions')));
    $r = $cerere('b', 'GET', '/', ['cookie' => 'PHPSESSID=' . $idA]);
    $idNou = $idSesiune($r);
    $verifica('cookie-ul site-ului A pe site-ul B: B nu-l găsește (alt dosar), dă altă sesiune, A rămâne neatinsă', $r['cod'] === 200
        && $idNou !== '' && $idNou !== $idA && !is_file($siteuri['b']['site'] . '/storage/sessions/sess_' . $idA)
        && is_file($siteuri['a']['site'] . '/storage/sessions/sess_' . $idA), $idNou);
    $r = $cerere('a', 'GET', '/', ['cookie' => 'PHPSESSID=' . $idA]);
    $verifica('pe site-ul A, sesiunea lui continuă (același cookie, niciun cookie nou)', $r['cod'] === 200 && $idSesiune($r) === ''
        && $sesiuni($siteuri['a']['site'] . '/storage/sessions') === ['sess_' . $idA], implode(' | ', $r['cookies']));

    // ── B4. Pozele ──
    $scrie('');
    $scrie('B4. uploads/ și poza de distribuire');
    $r = $cerere('a', 'GET', '/uploads/gallery/proba.png');
    $verifica('site A: /uploads/gallery/proba.png din public_html/uploads (prin index.php), aceeași poză', $r['cod'] === 200
        && ($r['antete']['content-type'] ?? '') === 'image/png' && $r['corp'] === file_get_contents($siteuri['a']['public'] . '/uploads/gallery/proba.png'),
        (string) $r['cod']);
    $r = $cerere('b', 'GET', '/uploads/gallery/proba.png');
    $r2 = $cerere('a', 'GET', '/uploads/gallery/capcana.svg');
    $verifica('site B n-o are (404); poza din public/uploads al versiunii nu se servește nicăieri (404)', $r['cod'] === 404 && $r2['cod'] === 404,
        $r['cod'] . ' ' . $r2['cod']);
    $fisierOg = $dosarTest . '/og.php';
    file_put_contents($fisierOg, "<?php\nrequire '" . $cod . "/bootstrap.php';\n"
        . "echo (string) (\\App\\Support\\ImagineDistribuire::pentru('/uploads/gallery/proba.png', 'http://127.0.0.1:" . $porturi['a'] . "')['url'] ?? '');\n");
    [$c, $urlOg, $erori] = $cli('a', $fisierOg);
    $caleOg = (string) parse_url(trim($urlOg), PHP_URL_PATH);
    $r = $cerere('a', 'GET', $caleOg);
    $verifica('poza de distribuire (' . $caleOg . '): 200 JPEG, cache-ul în site/storage/cache/og, nimic în versiune', $c === 0
        && str_starts_with($caleOg, '/og/') && $r['cod'] === 200 && ($r['antete']['content-type'] ?? '') === 'image/jpeg'
        && count(glob($siteuri['a']['site'] . '/storage/cache/og/*.jpg') ?: []) === 1 && !is_dir($cod . '/storage/cache/og'),
        $c . ' ' . trim($urlOg) . ' ' . trim($erori) . ' ' . $r['cod']);

    // ── B5. Sitemap-ul scris de admin ──
    $scrie('');
    $scrie('B5. Sitemap-ul cu alt nume, scris de admin');
    $fisierSitemap = $dosarTest . '/sitemap.php';
    file_put_contents($fisierSitemap, "<?php\nrequire '" . $cod . "/bootstrap.php';\n"
        . "\$db = \\App\\Support\\Database::connection((array) (\$config['db'] ?? []));\n"
        . "\$m = new ReflectionMethod(\\App\\Http\\Controllers\\AdminController::class, 'generateSitemap');\n"
        . "\$m->setAccessible(true);\necho json_encode(\$m->invoke(new \\App\\Http\\Controllers\\AdminController(), \$db, 'sitemap-proba.xml'), JSON_UNESCAPED_UNICODE);\n");
    [$c, $iesire, $erori] = $cli('a', $fisierSitemap);
    $rezultat = json_decode($iesire, true);
    $verifica('generateSitemap(„sitemap-proba.xml”): fișierul în public_html/ al site-ului A, nu în versiune și nu la B', $c === 0
        && ($rezultat['ok'] ?? false) === true && is_file($siteuri['a']['public'] . '/sitemap-proba.xml')
        && !is_file($cod . '/public/sitemap-proba.xml') && !is_file($siteuri['b']['public'] . '/sitemap-proba.xml'), $c . ' ' . $iesire . ' ' . trim($erori));
    $r = $cerere('a', 'GET', '/sitemap-proba.xml');
    $r2 = $cerere('b', 'GET', '/sitemap-proba.xml');
    $verifica('servit de pe site-ul A (fișierul din docroot), 404 pe B', $r['cod'] === 200 && str_contains($r['corp'], '<urlset') && $r2['cod'] === 404,
        $r['cod'] . ' ' . $r2['cod']);

    // ── B6. Jurnalele site-ului ──
    $scrie('');
    $scrie('B6. Jurnalul erorilor PHP și jurnalul pluginurilor, ale fiecărui site');
    mkdir($siteuri['a']['site'] . '/date', 0770, true);
    file_put_contents($siteuri['a']['site'] . '/date/seo.json', '{"stricat":');
    $scrieEnv('a', ['SITE_DATE_DOSAR' => $siteuri['a']['site'] . '/date']);
    $r = $cerere('a', 'GET', '/contact');
    $r2 = $cerere('b', 'GET', '/contact');
    clearstatcache();
    $jurnalA = (string) @file_get_contents($siteuri['a']['site'] . '/storage/logs/php.log');
    $jurnalB = (string) @file_get_contents($siteuri['b']['site'] . '/storage/logs/php.log');
    $verifica('seo.json stricat pe site-ul A: eroarea în site/storage/logs/php.log al lui A, nu la B; jurnalele serverelor goale; '
        . 'nimic în versiune', $r['cod'] === 200 && $r2['cod'] === 200 && str_contains($jurnalA, 'nu e JSON valid') && !str_contains($jurnalB, 'nu e JSON valid')
        && trim((string) @file_get_contents($siteuri['a']['jurnal_server'])) === '' && trim((string) @file_get_contents($siteuri['b']['jurnal_server'])) === ''
        && !is_file($cod . '/storage/logs/php.log'), mb_substr($jurnalA, 0, 300));
    $scrieEnv('a', ['SITE_DATE_DOSAR' => '']);
    $verifica('jurnalul pluginurilor (fără licență: „doar nucleul”) în site/storage/logs al fiecăruia, cu gardul; nimic în versiune',
        is_file($siteuri['a']['site'] . '/storage/logs/pluginuri.log') && is_file($siteuri['b']['site'] . '/storage/logs/pluginuri.log')
        && (string) @file_get_contents($siteuri['a']['site'] . '/storage/logs/.htaccess') === Paths::GARD && !is_file($cod . '/storage/logs/pluginuri.log'));

    // ── B7. Mentenanța deploy-ului ──
    $scrie('');
    $scrie('B7. MENTENANTA (deploy/stub/index.php)');
    touch($siteuri['a']['site'] . '/MENTENANTA');
    $r = $cerere('a', 'GET', '/');
    $verifica('GET / → 503, Retry-After: 120, no-store, pagina mentenanta.html', $r['cod'] === 503 && ($r['antete']['retry-after'] ?? '') === '120'
        && ($r['antete']['cache-control'] ?? '') === 'no-store' && $r['corp'] === file_get_contents($cod . '/deploy/stub/mentenanta.html') && $faraCookie($r),
        $r['cod'] . ' ' . ($r['antete']['retry-after'] ?? '-'));
    $rHead = $cerere('a', 'HEAD', '/produs/oricare');
    $rAdmin = $cerere('a', 'POST', '/admin/login', ['post' => ['email' => 'x@exemplu.ro']]);
    $verifica('HEAD → 503 fără corp; adminul (și POST) tot 503', $rHead['cod'] === 503 && $rHead['corp'] === '' && $rAdmin['cod'] === 503,
        $rHead['cod'] . ' ' . $rAdmin['cod']);
    $rPing = $cerere('a', 'GET', '/_andaxi/ping');
    $rStripe = $cerere('a', 'POST', '/webhook/stripe');
    $rErp = $cerere('a', 'POST', '/api/erp/notificare');
    $verifica('trec: /_andaxi/ping (200), /webhook/stripe și /api/erp/notificare (răspunsul aplicației, nu 503)', $rPing['cod'] === 200
        && !in_array($rStripe['cod'], [0, 503], true) && !in_array($rErp['cod'], [0, 503], true), $rPing['cod'] . ' ' . $rStripe['cod'] . ' ' . $rErp['cod']);
    $r = $cerere('b', 'GET', '/');
    $verifica('site-ul B (aceeași versiune) neatins: 200', $r['cod'] === 200, (string) $r['cod']);
    unlink($siteuri['a']['site'] . '/MENTENANTA');
    $r = $cerere('a', 'GET', '/');
    $verifica('fără MENTENANTA, site-ul A din nou 200', $r['cod'] === 200, (string) $r['cod']);

    // ── B8. RELEASE ──
    $scrie('');
    $scrie('B8. RELEASE și versiunea');
    $rezultate = [];
    // „de altă formă, spre versiunea care există”: fără verificarea formei, ~/platforma/releases/../releases/<v> și
    // ~/platforma/releases/./<v> ar încărca versiunea, deci doar verificarea formei le poate opri.
    foreach (['lipsă' => null, 'de altă formă' => "../../platforma/releases/x\n", 'goală' => "\n", 'spre o versiune care lipsește' => "2026.10.9-0000000\n",
        'de altă formă, spre versiunea care există (../releases/<v>)' => '../releases/' . $versiune . "\n",
        'de altă formă, spre versiunea care există (./<v>)' => './' . $versiune . "\n"] as $caz => $continut) {
        @unlink($siteuri['a']['site'] . '/RELEASE');
        if ($continut !== null) {
            file_put_contents($siteuri['a']['site'] . '/RELEASE', $continut);
        }
        $r = $cerere('a', 'GET', '/_andaxi/ping');
        $rezultate[$caz] = $r['cod'] === 503 && ($r['antete']['retry-after'] ?? '') === '300' && str_contains($r['corp'], 'Revenim');
    }
    $verifica('RELEASE ' . implode(', ', array_keys($rezultate)) . ' → 503 static (Retry-After: 300), și pentru ping', !in_array(false, $rezultate, true),
        json_encode($rezultate, JSON_UNESCAPED_UNICODE) ?: '');
    mkdir($siteuri['a']['site'] . '/releases', 0770, true);
    symlink($cod, $siteuri['a']['site'] . '/releases/' . $versiuneLocala);
    file_put_contents($siteuri['a']['site'] . '/RELEASE', $versiuneLocala . "\n");
    $r = $cerere('a', 'GET', '/_andaxi/ping');
    $verifica('o versiune doar în site/releases/ (găzduire cu open_basedir pe site) e găsită: 200', $r['cod'] === 200 && ($json($r)['ok'] ?? false) === true,
        $r['cod'] . ' ' . $r['corp']);
    file_put_contents($siteuri['a']['site'] . '/RELEASE', $versiune . "\n");

    // ── B9. Pingul și baza ──
    $scrie('');
    $scrie('B9. Pingul fără bază, cu o bază care tace, goală sau în urma codului');
    $scrieEnv('a', ['DB_DATABASE' => 'cms_paths_lipsa']);
    $r = $cerere('a', 'GET', '/_andaxi/ping');
    $verifica('baza lipsă → 503 {"ok":false,"db":false}, no-store, fără cookie, repede', $r['cod'] === 503 && $r['corp'] === '{"ok":false,"db":false}'
        && ($r['antete']['cache-control'] ?? '') === 'no-store' && $faraCookie($r) && $r['durata'] < 2.0, $r['cod'] . ' ' . $r['corp'] . ' ' . round($r['durata'], 2) . ' s');
    // Un „server” care acceptă conexiunea și nu trimite nimic: fără limita de citire, cererea ar aștepta minute.
    $scriptTacut = $dosarTest . '/tacut.php';
    file_put_contents($scriptTacut, '<?php $s = stream_socket_server("tcp://127.0.0.1:' . $portTacut . '"); $c = []; '
        . 'while (true) { $x = @stream_socket_accept($s, 60); if ($x) { $c[] = $x; } }');
    $pidTacut = (int) trim((string) shell_exec('nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scriptTacut) . ' >/dev/null 2>&1 & echo $!'));
    usleep(300000);
    $scrieEnv('a', ['DB_DATABASE' => 'cms_paths_a', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) $portTacut]);
    $r = $cerere('a', 'GET', '/_andaxi/ping');
    $verifica('o bază care tace → 503 în aproximativ ' . Ping::LIMITA_BAZA . ' secunde (' . round($r['durata'], 2) . ' s)',
        $r['cod'] === 503 && $r['corp'] === '{"ok":false,"db":false}' && $r['durata'] >= 1.5 && $r['durata'] < 6.0, $r['cod'] . ' ' . round($r['durata'], 2) . ' s');
    $opreste($pidTacut);
    $pidTacut = 0;
    $scrieEnv('a', ['DB_HOST' => 'localhost', 'DB_PORT' => '3306', 'DB_DATABASE' => 'cms_paths_gol']);
    $r = $cerere('a', 'GET', '/_andaxi/ping');
    $verifica('o bază goală (n-a văzut runner-ul) → 200, „fara_registru”, niciun tabel făcut', $r['cod'] === 200
        && ($json($r)['schema'] ?? '') === 'fara_registru'
        && (int) $root->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'cms_paths_gol'")->fetchColumn() === 0, $r['corp']);
    $scrieEnv('a', ['DB_DATABASE' => 'cms_paths_a']);
    // O migrare din cod neaplicată pe bază: ultima_ord coborât o clipă (ca în test-verificare.php), apoi pus la loc.
    $ultima = (string) $root->query("SELECT valoare FROM cms_paths_a._platforma_stare WHERE cheie = 'ultima_ord'")->fetchColumn();
    try {
        $root->prepare("UPDATE cms_paths_a._platforma_stare SET valoare = ? WHERE cheie = 'ultima_ord'")->execute([(string) ((int) $ultima - 1)]);
        $r = $cerere('a', 'GET', '/_andaxi/ping');
    } finally {
        $root->prepare("UPDATE cms_paths_a._platforma_stare SET valoare = ? WHERE cheie = 'ultima_ord'")->execute([$ultima]);
    }
    $verifica('baza în urma codului (o migrare neaplicată) → 200, „in_urma” (site-ul merge; /health dă atunci 503)', $r['cod'] === 200
        && ($json($r)['schema'] ?? '') === 'in_urma', $r['corp']);
    $r = $cerere('a', 'GET', '/_andaxi/ping');
    $verifica('pusă la loc: „la_zi”', ($json($r)['schema'] ?? '') === 'la_zi', $r['corp']);

    // ── B10. Linia de comandă ──
    $scrie('');
    $scrie('B10. Scripturile din linia de comandă, pentru un site');
    $sesiuniInainte = [$sesiuni($siteuri['a']['site'] . '/storage/sessions'), $sesiuni($siteuri['b']['site'] . '/storage/sessions')];
    [$c, $iesire, $erori] = $cli('a', 'scripts/diagnostic.php');
    // Codul 1: site-ul simulat n-are licență („doar nucleul”), deci diagnostic.php o spune; aici contează căile.
    $verifica('diagnostic.php: .env și storage/ ale site-ului A, jurnalul PHP al lui, baza lui (fără licență: cod 1)', $c === 1
        && str_contains($iesire, 'licenta      : NU SE VERIFICA') && trim($erori) === ''
        && str_contains($iesire, 'fisier .env         : ' . $siteuri['a']['site'] . '/.env')
        && str_contains($iesire, 'dosarul storage     : ' . $siteuri['a']['site'] . '/storage')
        && str_contains($iesire, 'jurnal erori PHP    : ' . $siteuri['a']['site'] . '/storage/logs/php.log')
        && str_contains($iesire, 'baza      : cms_paths_a'), 'cod ' . $c . ' ' . trim($erori) . ' | ' . mb_substr($iesire, 0, 1500));
    [$c, $iesire, $erori] = $cli('b', 'scripts/migreaza.php', ['--stare']);
    $verifica('migreaza.php --stare pe site-ul B → 0', $c === 0, 'cod ' . $c . ' ' . trim($erori));
    $verifica('nicio sesiune nouă din linia de comandă', $sesiuniInainte === [$sesiuni($siteuri['a']['site'] . '/storage/sessions'),
        $sesiuni($siteuri['b']['site'] . '/storage/sessions')] && $sesiuni($cod . '/storage/sessions') === []);

    // ── B11. Versiunea neatinsă ──
    $scrie('');
    $scrie('B11. Versiunea de cod comună');
    clearstatcache();
    $acum = $amprentaDosar($cod);
    $schimbate = [];
    foreach (array_unique(array_merge(array_keys($amprentaCod), array_keys($acum))) as $cale) {
        if (($amprentaCod[$cale] ?? null) !== ($acum[$cale] ?? null)) {
            $schimbate[] = $cale;
        }
    }
    $verifica('nimic scris, schimbat sau șters în versiune de instalări, pagini, ping, poze, sitemap, jurnale și scripturi (' . count($acum) . ' intrări)',
        $schimbate === [], implode(', ', array_slice($schimbate, 0, 15)));
    foreach ($siteuri as $x => $s) {
        $jurnal = (string) @file_get_contents($s['site'] . '/storage/logs/php.log');
        $grave = array_values(array_filter(explode("\n", $jurnal), static fn (string $l): bool
            => preg_match('/PHP (Warning|Notice|Fatal error|Parse error|Recoverable fatal error|Deprecated)/', $l) === 1));
        $verifica('site ' . $x . ': jurnalul PHP fără avertismente sau erori PHP', $grave === [], implode("\n          ", array_slice($grave, 0, 5)));
    }
} catch (Throwable $e) {
    $verifica('testul a mers până la capăt', false, $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $curata();
    $verifica('curățenia: bazele cms_paths_* șterse, serverele oprite, găzduirea simulată ștearsă', !is_dir($dosarTest)
        && (int) $root->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'cms\\_paths\\_%'")->fetchColumn() === 0);
}

$scrie('');
$scrie('Rezultat: ' . $trecute . ' verificări trecute, ' . $esuate . ' eșuate.');
exit($esuate === 0 ? 0 : 1);
