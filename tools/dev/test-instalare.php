<?php

declare(strict_types=1);

/**
 * Testele trecerii pe migrări (WP-05; docs/MIGRARI.md, „Instalarea”, „Garda”, „Pașii de activare”; poarta G-inst
 * din tools/dev/porti.sh, și cu PHP-ul minim):
 *
 *  A. Garda (App\Support\Migrari\Garda::stare): o bază fără tabele sau ca înainte → fara_registru; după migrări →
 *     la_zi (și cu ultima_ord mai mare decât în cod: o bază înaintea codului); ultima_ord mai mic sau _platforma_stare
 *     fără rând → in_urma; fără conexiune sau cu conexiunea închisă de server → eroare; o singură citire pe conexiune.
 *  B. scripts/install.php: fără server (socket inexistent) 1, cu mesajul pe stderr (la fel seed.php); pe o bază goală 0:
 *     schema = asteptat.json, garda la_zi, seed-ul neutru (doar noutati, evenimente), setările implicite, adminul cu
 *     rolul în forma de azi (role + roles_json), pașii de activare ai licenței; a doua oară 1, „baza există”, nimic scris;
 *     oprit în mijlocul migrărilor și rulat din nou: termină; pe o bază străină (un WordPress) 1, neatinsă.
 *  C. /health și bannerul din admin: la_zi 200 „ok” + „schema: la_zi”; in_urma 503; fara_registru 200; bannerul roșu
 *     doar pentru adminul general și doar când baza nu e la zi.
 *  D. Pașii de activare (config/activari.php): fără licență nicio pagină /acorduri-gdpr și niciun prag ERP; licența
 *     pornește pluginurile → la prima cerere pagina (200 pe site) și pragul, câte un rând în jurnalul adminului, o singură
 *     dată (a doua cerere nu schimbă nimic); erp oprit și pornit din nou cu cheia prezentă și goală → neatinsă; o pagină
 *     ștearsă de admin rămâne ștearsă (și după o oprire și o repornire a pluginului); cu baza în urma codului (in_urma)
 *     nici cererea, nici cronul ERP nu rulează pașii, iar după aducerea la zi îi rulează prima cerere.
 *  E. Contorul de instrucțiuni (CMS_DEV_NUMARA_SQL=1, App\Support\ContorSql): pe o bază făcută de install.php, cu date
 *     demo, 0 instrucțiuni de schemă pe dashboard, lista de comenzi, pagina de produs și trimiterea unei comenzi; pe una
 *     făcută ca înainte (tools/dev/migrari/pe-vechi.php), dashboard-ul trimite peste 100, iar după baseline 0. Pe aceeași
 *     bază, înainte de baseline (fara_registru, licența „toate”), cererile și cronul ERP nu rulează pașii de activare
 *     (fără erp_trimite_din, fără înregistrare); după baseline îi rulează prima cerere.
 *  F. Înghețarea setărilor (WP-06), cu un pachet de site inventat: install.php cu SITE_SLUG o aplică înaintea setărilor
 *     implicite (valorile ei rămân, cea privată din privat/, pragul ERP gol neatins de pasul de activare); cu pachetul și fără
 *     SITE_SLUG, cu pachetul altui site sau cu un fișier de înghețare stricat 1, cu motivul pe stderr, baza neatinsă; o adoptare
 *     oprită între ultima migrare și înghețare lasă garda in_urma (pasi_site), deci cererile și cronul ERP nu scriu pragul;
 *     --aplica --site cu dosarul pachetului lipsă sau fără înghețare e refuzat (2), cheia rămâne, iar licenta.php activari tot
 *     nu scrie pragul; după --aplica cu pachetul înghețarea scrie doar ce lipsește, iar pasul ERP găsește pragul gol.
 *     În A și garda cu pasi_site.
 *
 * Doar date inventate (@exemplu.ro), pe baze cms_inst_* (făcute și șterse aici), cu licența într-un dosar al testului
 * (CMS_DEV_LICENTA_DOSAR: licența locală nu se atinge) și un server pe CMS_DEV_PORT+8, oprit la final. Doar MariaDB
 * (runner-ul e testat pe ambele servere de test-migrari.php). Raport: $CMS_DEV_DIR/test-instalare-raport.txt.
 *
 *   tools/dev/php.sh tools/dev/test-instalare.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';

use App\Support\Activari;
use App\Support\ContorSql;
use App\Support\Migrari\Amprenta;
use App\Support\Migrari\Garda;
use App\Support\Migrari\Server;
use App\Support\Settings;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-instalare-raport.txt';
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
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . mb_substr(trim($detalii), 0, 1500) : ''));
    }
};
$scrie('Testele instalării prin migrări ANDAXI-CMS — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

$sock = (string) (getenv('CMS_DEV_SOCK') ?: ini_get('pdo_mysql.default_socket') ?: '/tmp/claude-0/maria/sock');
$utilizator = (string) (getenv('CMS_DEV_DB_USER') ?: 'root');
$parolaDb = (string) (getenv('CMS_DEV_DB_PAROLA') ?: '');
$conectare = static function (?string $baza = null) use ($sock, $utilizator, $parolaDb): PDO {
    return new PDO('mysql:unix_socket=' . $sock . ($baza !== null ? ';dbname=' . $baza : '') . ';charset=utf8mb4', $utilizator, $parolaDb,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
};
$root = $conectare();
$bazeFacute = [];
$bazaNoua = static function (string $nume) use ($root, &$bazeFacute, $conectare): PDO {
    if (preg_match('/^cms_inst_[a-z0-9_]+$/', $nume) !== 1) {
        throw new RuntimeException('nume de bază greșit: ' . $nume);
    }
    $root->exec('DROP DATABASE IF EXISTS `' . $nume . '`');
    $root->exec('CREATE DATABASE `' . $nume . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $bazeFacute[$nume] = true;
    return $conectare($nume);
};

// Dosarul de lucru al testului: licențele lui, jurnalul contorului, cookie-urile.
$lucru = $dosarDev . '/test-instalare';
$sterge = static function (string $cale) use (&$sterge): void {
    if (is_file($cale) || is_link($cale)) {
        @unlink($cale);
    } elseif (is_dir($cale)) {
        foreach (array_diff(scandir($cale) ?: [], ['.', '..']) as $x) {
            $sterge($cale . '/' . $x);
        }
        @rmdir($cale);
    }
};
$sterge($lucru);
@mkdir($lucru, 0770, true);
$dosarLicenta = $lucru . '/licenta';
@mkdir($dosarLicenta, 0770, true);

$port = (int) (getenv('CMS_DEV_PORT') ?: 8701) + 8;
$pidServer = 0;
$opresteServerul = static function () use (&$pidServer): void {
    if ($pidServer > 0) {
        exec('pkill -TERM -P ' . $pidServer . ' 2>/dev/null; kill ' . $pidServer . ' 2>/dev/null');
        for ($i = 0; $i < 50 && (trim((string) shell_exec('pgrep -P ' . $pidServer . ' 2>/dev/null')) !== ''
            || posix_kill($pidServer, 0)); $i++) {
            usleep(100000);
        }
        $pidServer = 0;
    }
};
register_shutdown_function(static function () use (&$bazeFacute, $root, $opresteServerul): void {
    $opresteServerul();
    foreach (array_keys($bazeFacute) as $baza) {
        try {
            $root->exec('DROP DATABASE IF EXISTS `' . $baza . '`');
        } catch (Throwable) {
        }
    }
});

/** Un script al proiectului prin tools/dev/php.sh, pe baza dată, cu licența testului: [cod, stdout, stderr]. */
$php = static function (string $baza, array $script, array $mediu = []) use ($radacina, $dosarLicenta): array {
    $env = array_merge(getenv(), ['CMS_DEV_DB' => $baza, 'CMS_DEV_LICENTA_DOSAR' => $dosarLicenta], $mediu);
    $env = array_filter($env, static fn ($v): bool => $v !== null);
    $p = proc_open(array_merge([$radacina . '/tools/dev/php.sh'], $script), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, $radacina, $env);
    $out = (string) stream_get_contents($pipe[1]);
    $err = (string) stream_get_contents($pipe[2]);
    fclose($pipe[1]);
    fclose($pipe[2]);
    return [proc_close($p), $out, $err];
};
/** Licența testului (tools/dev/licenta.php în dosarul testului); $baza = unde rulează pașii de activare (null: nicăieri). */
$licenta = static function (array $argumente, ?string $baza = null) use ($php): string {
    [$cod, $out, $err] = $php($baza ?? 'cms_inst_nu_exista', array_merge(['tools/dev/licenta.php'], $argumente));
    if ($cod !== 0) {
        throw new RuntimeException('licența nu s-a pus: ' . $out . $err);
    }
    return $out;
};
$faraLicenta = static function () use ($dosarLicenta): void {
    foreach (glob($dosarLicenta . '/*') ?: [] as $f) {
        @unlink($f);
    }
};

/** Serverul testului pe baza dată (php -S, ca tools/dev/server.sh), cu contorul de instrucțiuni. */
$fisierContor = $lucru . '/numara-sql.jsonl';
$pornesteServerul = static function (string $baza) use (&$pidServer, $opresteServerul, $radacina, $dosarDev, $dosarLicenta, $port, $lucru): bool {
    $opresteServerul();
    $ini = [];
    foreach (['pdo_mysql.default_socket', 'error_reporting', 'display_errors', 'log_errors', 'error_log', 'sendmail_path', 'memory_limit',
        'allow_url_fopen'] as $cheie) {
        $valoare = ini_get($cheie);
        if ($valoare !== false && $valoare !== '') {
            $ini[] = '-d ' . escapeshellarg($cheie . '=' . $valoare);
        }
    }
    $mediu = ['PHP_CLI_SERVER_WORKERS' => '2', 'DB_DATABASE' => $baza, 'APP_URL' => 'http://127.0.0.1:' . $port,
        'LICENTA_DOSAR' => $dosarLicenta, 'CMS_DEV_NUMARA_SQL' => '1', 'CMS_DEV_DIR' => $lucru];
    $env = '';
    foreach ($mediu as $nume => $valoare) {
        $env .= ' ' . $nume . '=' . escapeshellarg($valoare);
    }
    $comanda = 'cd ' . escapeshellarg($radacina) . ' && env' . $env . ' nohup ' . escapeshellarg(PHP_BINARY) . ' ' . implode(' ', $ini)
        . ' -S 127.0.0.1:' . $port . ' -t public tools/dev/router.php >> ' . escapeshellarg($dosarDev . '/server-instalare.log') . ' 2>&1 & echo $!';
    $pidServer = (int) trim((string) shell_exec($comanda));
    for ($i = 0; $i < 50; $i++) {
        $c = curl_init('http://127.0.0.1:' . $port . '/health');
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 2]);
        curl_exec($c);
        $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        curl_close($c);
        if ($cod === 200 || $cod === 503) {
            return true;
        }
        usleep(200000);
    }
    return false;
};
$cerere = static function (string $metoda, string $cale, ?string $jar = null, array $date = []) use ($port): array {
    $c = curl_init('http://127.0.0.1:' . $port . $cale);
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60, CURLOPT_HEADER => true]);
    if ($jar !== null) {
        curl_setopt($c, CURLOPT_COOKIEFILE, $jar);
        curl_setopt($c, CURLOPT_COOKIEJAR, $jar);
    }
    if ($metoda === 'POST') {
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($date));
    }
    $raspuns = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $marime = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    $antet = substr($raspuns, 0, $marime);
    return ['cod' => $cod, 'corp' => substr($raspuns, $marime), 'location' => preg_match('/^Location:\s*(\S+)/mi', $antet, $m) === 1 ? $m[1] : ''];
};
$parolaAdmin = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));
$login = static function (string $email, string $jar) use ($cerere, $parolaAdmin): bool {
    @unlink($jar);
    $cerere('GET', '/admin/login', $jar);
    return $cerere('POST', '/admin/login', $jar, ['email' => $email, 'password' => $parolaAdmin])['cod'] === 302;
};
/** Rândurile contorului (o cerere = un rând), apoi golește fișierul. */
$citesteContorul = static function () use ($fisierContor): array {
    $randuri = [];
    foreach (file($fisierContor, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linie) {
        $r = json_decode($linie, true);
        if (is_array($r)) {
            $randuri[] = $r;
        }
    }
    @unlink($fisierContor);
    return $randuri;
};
$instantaneu = static function (PDO $db): array {
    $r = [];
    foreach ($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
        ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $r[$t] = $db->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn() . ':' . ($db->query('CHECKSUM TABLE `' . $t . '`')->fetch(PDO::FETCH_NUM)[1] ?? '');
    }
    return $r;
};
$schimbate = static fn (array $a, array $b): array => array_keys(array_diff_assoc($b, $a) + array_diff_assoc($a, $b));
/** Ce lasă pașii de activare într-o bază: pragul ERP (null = cheia lipsește), înregistrarea, rezultatele din jurnal. */
$urmeActivare = static function (PDO $db): array {
    $prag = $db->query("SELECT `value` FROM settings WHERE `key` = 'erp_trimite_din'")->fetchColumn();
    $inregistrare = $db->query("SELECT `value` FROM settings WHERE `key` = '" . Activari::CHEIE_SETARE . "'")->fetchColumn();
    $jurnal = [];
    foreach ($db->query("SELECT context_json FROM admin_activity_log WHERE action = '" . Activari::ACTIUNE_JURNAL . "' ORDER BY id")
        ->fetchAll(PDO::FETCH_COLUMN) as $context) {
        $jurnal[] = (string) (((array) json_decode((string) $context, true))['rezultat'] ?? '');
    }
    return ['prag' => $prag === false ? null : (string) $prag,
        'inregistrare' => array_keys((array) json_decode($inregistrare === false ? '' : (string) $inregistrare, true)), 'jurnal' => $jurnal];
};
$asteptat = json_decode((string) file_get_contents($radacina . '/database/migrari/asteptat.json'), true);
$asteptatJson = Amprenta::json(is_array($asteptat) ? $asteptat : []);
$ultimaOrd = (require $radacina . '/database/migrari/ultima.php')['ord'];
/** Prima valoare a unei citiri, ca text („0” rămâne „0”; niciun rând: gol). */
$unu = static function (PDO $db, string $sql): string {
    $valoare = $db->query($sql)->fetchColumn();
    return $valoare === false || $valoare === null ? '' : (string) $valoare;
};

// =====================================================================
// B. scripts/install.php
// =====================================================================
$scrie('');
$scrie('== B. scripts/install.php (și seed.php)');
/** install.php / seed.php direct cu PHP-ul acesta, cu un socket care nu există (fără tools/dev/php.sh, care l-ar verifica). */
$faraServer = static function (string $script) use ($radacina, $dosarLicenta): array {
    $env = array_merge(getenv(), ['DB_HOST' => 'localhost', 'DB_DATABASE' => 'cms_inst_nu_exista', 'LICENTA_DOSAR' => $dosarLicenta]);
    $p = proc_open([PHP_BINARY, '-d', 'pdo_mysql.default_socket=/tmp/cms-inst-nu-exista.sock', '-d', 'display_errors=stderr', '-d', 'log_errors=0',
        $radacina . '/' . $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, $radacina, $env);
    $out = (string) stream_get_contents($pipe[1]);
    $err = (string) stream_get_contents($pipe[2]);
    fclose($pipe[1]);
    fclose($pipe[2]);
    return [proc_close($p), $out, $err];
};
[$c, $o, $e] = $faraServer('scripts/install.php');
$verifica('install.php fără server (socket inexistent): 1, mesajul pe stderr, nimic pe stdout',
    $c === 1 && str_contains($e, 'Nu mă pot conecta') && trim($o) === '' && !str_contains($e, 'Warning'), 'cod ' . $c . ' | ' . $o . ' | ' . $e);
[$c, $o, $e] = $faraServer('scripts/seed.php');
$verifica('seed.php fără server: 1, mesajul pe stderr', $c === 1 && str_contains($e, 'nu este disponibilă') && trim($o) === '', 'cod ' . $c . ' | ' . $o . ' | ' . $e);

$licenta(['toate']);
$dbB = $bazaNoua('cms_inst_b');
[$c, $o, $e] = $php('cms_inst_b', ['scripts/install.php']);
Garda::uita();
$categorii = implode(',', $dbB->query('SELECT slug FROM blog_categories ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN));
$admini = $dbB->query('SELECT email, role, roles_json FROM admins')->fetchAll(PDO::FETCH_NUM);
$setari = (int) $unu($dbB, 'SELECT COUNT(*) FROM settings');
$cheiImplicite = count(Settings::all(null));
$verifica('install.php pe o bază goală: 0; schema = asteptat.json; garda la_zi; categoriile de blog doar noutati, evenimente (seed-ul neutru, '
    . 'nimic Bioscem); setările implicite (' . $cheiImplicite . ') în bază; adminul cu role + roles_json; fără lista implicită de newsletter',
    $c === 0 && str_contains($o, 'Install finalizat') && Amprenta::json(Amprenta::citeste($dbB, Server::detecteaza($dbB))) === $asteptatJson
    && Garda::stare($dbB) === Garda::LA_ZI && $categorii === 'noutati,evenimente' && $setari >= $cheiImplicite
    && $admini === [['admin@exemplu.ro', 'administrator_general', '["administrator_general"]']]
    && $unu($dbB, 'SELECT COUNT(*) FROM newsletter_lists') === '0',
    'cod ' . $c . ', categorii ' . $categorii . ', setări ' . $setari . ', admini ' . json_encode($admini) . ' | ' . $o . $e);
$verifica('install.php cu licența „toate”: pașii de activare (pagina /acorduri-gdpr, pragul ERP), înregistrați în ' . Activari::CHEIE_SETARE,
    str_contains($o, 'Activare acorduri_gdpr: pagina /acorduri-gdpr creată') && str_contains($o, 'Activare erp: erp_trimite_din scris')
    && $unu($dbB, "SELECT COUNT(*) FROM pages WHERE slug = 'acorduri-gdpr' AND deleted_at IS NULL") === '1'
    && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $unu($dbB, "SELECT `value` FROM settings WHERE `key` = 'erp_trimite_din'")) === 1
    && array_keys((array) json_decode($unu($dbB, "SELECT `value` FROM settings WHERE `key` = '" . Activari::CHEIE_SETARE . "'"), true))
        === ['acorduri_gdpr', 'erp'],
    $o);
$inainte = $instantaneu($dbB);
[$c, $o, $e] = $php('cms_inst_b', ['scripts/install.php']);
$verifica('install.php a doua oară (baza are administratori): 1, „Baza există”, pe stderr, nimic scris (DB-23)',
    $c === 1 && str_contains($e, 'Baza există') && $instantaneu($dbB) === $inainte, 'cod ' . $c . ' | ' . $o . $e);

// Oprit la jumătatea migrărilor (ca un proces omorât), apoi din nou: termină, fără administrator dublat.
$dbI = $bazaNoua('cms_inst_intrerupt');
[$c1, , $e1] = $php('cms_inst_intrerupt', ['scripts/install.php'], ['MIGRARI_TEST_OPRESTE_DUPA' => '30', 'MIGRARI_TEST_PAUZA_BUCATI_MS' => '0']);
$adminiDupaOprire = $unu($dbI, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins'") === '1'
    ? $unu($dbI, 'SELECT COUNT(*) FROM admins') : '0';
[$c2, $o2, $e2] = $php('cms_inst_intrerupt', ['scripts/install.php']);
Garda::uita();
$verifica('install.php oprit după 30 de operații ale migrărilor (1, fără administrator), apoi din nou: 0, schema = asteptat.json, un administrator',
    $c1 === 1 && str_contains($e1, 'oprire de test') && $adminiDupaOprire === '0' && $c2 === 0
    && Amprenta::json(Amprenta::citeste($dbI, Server::detecteaza($dbI))) === $asteptatJson && $unu($dbI, 'SELECT COUNT(*) FROM admins') === '1'
    && Garda::stare($dbI) === Garda::LA_ZI, 'coduri ' . $c1 . '/' . $c2 . ', admini după oprire ' . $adminiDupaOprire . ' | ' . $e1 . ' | ' . $o2 . $e2);

// O bază străină (de exemplu un WordPress): refuzată de runner, neatinsă.
$dbS = $bazaNoua('cms_inst_straina');
$dbS->exec('CREATE TABLE wp_posts (ID INT PRIMARY KEY, post_title VARCHAR(20))');
$dbS->exec("INSERT INTO wp_posts VALUES (1, 'Articol inventat')");
$inainte = $instantaneu($dbS);
[$c, $o, $e] = $php('cms_inst_straina', ['scripts/install.php']);
$verifica('install.php pe o bază străină (un tabel wp_posts): 1, mesajul runner-ului (codul 4), baza neatinsă',
    $c === 1 && str_contains($e, 'nu e a unui site ANDAXI') && $instantaneu($dbS) === $inainte, 'cod ' . $c . ' | ' . $o . $e);

// Fără licență (doar nucleul): nicio pagină a unui plugin, niciun prag.
$faraLicenta();
$dbF = $bazaNoua('cms_inst_fara');
[$c, $o, $e] = $php('cms_inst_fara', ['scripts/install.php']);
$verifica('install.php fără licență (doar nucleul): 0, fără pagina /acorduri-gdpr, fără erp_trimite_din, fără niciun pas de activare',
    $c === 0 && $unu($dbF, 'SELECT COUNT(*) FROM pages') === '0' && $unu($dbF, "SELECT COUNT(*) FROM settings WHERE `key` = 'erp_trimite_din'") === '0'
    && !str_contains($o, 'Activare'), 'cod ' . $c . ' | ' . $o . $e);

// =====================================================================
// A. Garda
// =====================================================================
$scrie('');
$scrie('== A. Garda (Garda::stare)');
$dbG = $bazaNoua('cms_inst_garda');
Garda::uita();
$verifica('o bază fără tabele: fara_registru', Garda::stare($dbG) === Garda::FARA_REGISTRU, Garda::stare($dbG));
Garda::uita();
$verifica('o bază făcută de install.php: la_zi', Garda::stare($conectare('cms_inst_b')) === Garda::LA_ZI);
$dbB->exec("UPDATE _platforma_stare SET valoare = '" . ($ultimaOrd - 1) . "' WHERE cheie = 'ultima_ord'");
Garda::uita();
$verifica('ultima_ord mai mic decât în cod (o migrare nouă n-a rulat): in_urma', Garda::stare($conectare('cms_inst_b')) === Garda::IN_URMA);
$dbB->exec("UPDATE _platforma_stare SET valoare = '" . ($ultimaOrd + 5) . "' WHERE cheie = 'ultima_ord'");
Garda::uita();
$verifica('ultima_ord mai mare decât în cod (codul întors la o versiune de dinainte): la_zi', Garda::stare($conectare('cms_inst_b')) === Garda::LA_ZI);
$dbB->exec("UPDATE _platforma_stare SET valoare = '" . $ultimaOrd . "' WHERE cheie = 'ultima_ord'");
$dbI->exec("DELETE FROM _platforma_stare WHERE cheie = 'ultima_ord'");
Garda::uita();
$verifica('_platforma_stare fără rândul ultima_ord: in_urma', Garda::stare($conectare('cms_inst_intrerupt')) === Garda::IN_URMA);
$dbB->exec("INSERT INTO _platforma_stare (cheie, valoare, actualizata_la) VALUES ('pasi_site', 'proba', NOW())");
Garda::uita();
$cuPasi = Garda::stare($conectare('cms_inst_b'));
$dbB->exec("DELETE FROM _platforma_stare WHERE cheie = 'pasi_site'");
Garda::uita();
$verifica('pașii site-ului neterminați (pasi_site, WP-06), cu ultima_ord la zi: in_urma; fără rândul lor: la_zi',
    $cuPasi === Garda::IN_URMA && Garda::stare($conectare('cms_inst_b')) === Garda::LA_ZI, $cuPasi);
Garda::uita();
$verifica('fără conexiune: eroare', Garda::stare(null) === Garda::EROARE);
$dbK = $conectare('cms_inst_b');
$id = (int) $dbK->query('SELECT CONNECTION_ID()')->fetchColumn();
$root->exec('KILL CONNECTION ' . $id);
usleep(100000);
Garda::uita();
$verifica('conexiunea închisă de server: eroare (nu fara_registru)', Garda::stare($dbK) === Garda::EROARE);
// O singură citire, ținută minte pe conexiune.
Garda::uita();
$contor = new ContorSql('mysql:unix_socket=' . $sock . ';dbname=cms_inst_b;charset=utf8mb4', $utilizator, $parolaDb,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$inainte = ContorSql::$total;
$stari = [Garda::stare($contor), Garda::stare($contor), Garda::stare($contor)];
$verifica('o singură citire pe conexiune (trei întrebări, o instrucțiune: SELECT după cheia primară)',
    $stari === [Garda::LA_ZI, Garda::LA_ZI, Garda::LA_ZI] && ContorSql::$total - $inainte === 1, (string) (ContorSql::$total - $inainte));
unset($contor);

// =====================================================================
// D. Pașii de activare (prin cererile site-ului) și C. /health, bannerul
// =====================================================================
$scrie('');
$scrie('== D. Pașii de activare (config/activari.php)');
$dbD = $conectare('cms_inst_fara');
[$cs] = $php('cms_inst_fara', ['scripts/seed.php']);
[$cd, $od] = $php('cms_inst_fara', ['tools/dev/date-demo.php']);
$verifica('seed.php și date-demo.php pe baza instalată fără licență', $cs === 0 && $cd === 0 && str_contains($od, 'Date demo gata'), $od);
// Licența „toate”, scrisă fără să ruleze pașii (ca o licență pusă de altcineva): îi rulează prima cerere.
$licenta(['toate']);
$jurnalInainte = (int) $unu($dbD, "SELECT COUNT(*) FROM admin_activity_log WHERE action = 'activare_plugin'");
$verifica('înainte de prima cerere: nicio pagină /acorduri-gdpr, niciun erp_trimite_din',
    $unu($dbD, "SELECT COUNT(*) FROM pages WHERE slug = 'acorduri-gdpr'") === '0'
    && $unu($dbD, "SELECT COUNT(*) FROM settings WHERE `key` = 'erp_trimite_din'") === '0');
// Prima cerere e verificarea /health de la pornirea serverului.
$verifica('serverul testului pornește (CMS_DEV_PORT+8, cu contorul de instrucțiuni)', $pornesteServerul('cms_inst_fara'));
$r = $cerere('GET', '/');
$dupaPrima = $instantaneu($dbD);
$verifica('prima cerere după licență: pagina /acorduri-gdpr creată, erp_trimite_din scris, două rânduri „activare_plugin” în jurnal (fără administrator)',
    $r['cod'] === 200 && $unu($dbD, "SELECT COUNT(*) FROM pages WHERE slug = 'acorduri-gdpr'") === '1'
    && $unu($dbD, "SELECT COUNT(*) FROM settings WHERE `key` = 'erp_trimite_din'") === '1'
    && (int) $unu($dbD, "SELECT COUNT(*) FROM admin_activity_log WHERE action = 'activare_plugin'") === $jurnalInainte + 2
    && $unu($dbD, "SELECT COUNT(*) FROM admin_activity_log WHERE action = 'activare_plugin' AND admin_id IS NOT NULL") === '0',
    'cod ' . $r['cod']);
$r = $cerere('GET', '/acorduri-gdpr');
$verifica('/acorduri-gdpr: 200, cu formularul acordurilor', $r['cod'] === 200 && str_contains($r['corp'], 'gdpr'), 'cod ' . $r['cod']);
$cerere('GET', '/');
$cerere('GET', '/magazin');
$verifica('cererile de după nu mai schimbă nimic (pașii rulează o singură dată)', $instantaneu($dbD) === $dupaPrima,
    implode(', ', $schimbate($dupaPrima, $instantaneu($dbD))));
// Adminul șterge pagina: rămâne ștearsă (codul vechi o readucea la fiecare pagină de admin).
$jarAdmin = $lucru . '/admin.txt';
$dbD->exec("UPDATE pages SET deleted_at = NOW(), is_published = 0 WHERE slug = 'acorduri-gdpr'");
$okLogin = $login('admin@exemplu.ro', $jarAdmin);
$cerere('GET', '/admin', $jarAdmin);
$cerere('GET', '/admin/pages', $jarAdmin);
$verifica('o pagină /acorduri-gdpr ștearsă de admin rămâne ștearsă după paginile din admin', $okLogin
    && $unu($dbD, "SELECT COUNT(*) FROM pages WHERE slug = 'acorduri-gdpr' AND deleted_at IS NOT NULL") === '1');
// Pluginurile oprite, apoi pornite din nou; ERP cu cheia prezentă și goală.
$licenta(['lista', 'blog=pornit']);
$cerere('GET', '/');
$inregistrare = (array) json_decode($unu($dbD, "SELECT `value` FROM settings WHERE `key` = '" . Activari::CHEIE_SETARE . "'"), true);
$verifica('acorduri_gdpr și erp oprite: ies din ' . Activari::CHEIE_SETARE . ' (la repornire pașii rulează din nou)', $inregistrare === [],
    json_encode($inregistrare));
$dbD->exec("UPDATE settings SET `value` = '' WHERE `key` = 'erp_trimite_din'");
$licenta(['toate']);
$cerere('GET', '/');
$verifica('repornite: erp_trimite_din prezent și gol rămâne gol (fără prag, ca înghețarea Bioscem); pagina ștearsă rămâne ștearsă (există)',
    $unu($dbD, "SELECT `value` FROM settings WHERE `key` = 'erp_trimite_din'") === ''
    && $unu($dbD, "SELECT COUNT(*) FROM pages WHERE slug = 'acorduri-gdpr' AND deleted_at IS NOT NULL") === '1'
    && $unu($dbD, "SELECT COUNT(*) FROM pages WHERE slug = 'acorduri-gdpr'") === '1'
    && array_keys((array) json_decode($unu($dbD, "SELECT `value` FROM settings WHERE `key` = '" . Activari::CHEIE_SETARE . "'"), true))
        === ['acorduri_gdpr', 'erp']);
// Cheia lipsă cu totul, cu ERP-ul oprit și apoi pornit: scrisă.
$licenta(['lista', 'blog=pornit']);
$cerere('GET', '/');
$dbD->exec("DELETE FROM settings WHERE `key` = 'erp_trimite_din'");
$licenta(['toate']);
$cerere('GET', '/');
$verifica('erp pornit din nou cu cheia lipsă: erp_trimite_din scris (data de acum)',
    preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $unu($dbD, "SELECT `value` FROM settings WHERE `key` = 'erp_trimite_din'")) === 1);
$inainte = $instantaneu($dbD);
$rulate = Activari::ruleaza($dbD);
$verifica('Activari::ruleaza() a doua oară: nimic de rulat, nimic schimbat', $rulate === [] && $instantaneu($dbD) === $inainte, json_encode($rulate));
// Baza în urma codului (un release urcat înainte de --aplica, o adoptare oprită la jumătate): pașii așteaptă și nu scriu
// nimic, nici la o cerere, nici în cronul ERP (pragul de acum ar lua-o înaintea înghețării setărilor); după --aplica rulează.
$licenta(['lista', 'blog=pornit']);
$cerere('GET', '/');
$dbD->exec("DELETE FROM settings WHERE `key` = 'erp_trimite_din'");
$dbD->exec("UPDATE _platforma_stare SET valoare = '" . ($ultimaOrd - 1) . "' WHERE cheie = 'ultima_ord'");
$licenta(['toate']);
$urmeInainte = $urmeActivare($dbD);
$r = $cerere('GET', '/');
[$codErp, $outErp, $errErp] = $php('cms_inst_fara', ['scripts/erp-sync.php']);
$verifica('baza în urma codului (in_urma), acorduri_gdpr și erp pornite din nou: o cerere și cronul ERP nu rulează pașii '
    . '(fără erp_trimite_din, înregistrarea și jurnalul neschimbate)',
    $r['cod'] === 200 && $codErp === 0 && $urmeInainte['prag'] === null && $urmeInainte['inregistrare'] === []
    && $urmeActivare($dbD) === $urmeInainte, 'cod ' . $r['cod'] . '/' . $codErp . ' ' . json_encode([$urmeInainte, $urmeActivare($dbD)]) . ' ' . $outErp . $errErp);
$dbD->exec("UPDATE _platforma_stare SET valoare = '" . $ultimaOrd . "' WHERE cheie = 'ultima_ord'");
$cerere('GET', '/');
$urme = $urmeActivare($dbD);
$verifica('după --aplica (baza din nou la zi): prima cerere rulează pașii (erp_trimite_din scris, acorduri_gdpr și erp în înregistrare, '
    . 'două rânduri noi în jurnal)',
    preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $urme['prag']) === 1 && $urme['inregistrare'] === ['acorduri_gdpr', 'erp']
    && count($urme['jurnal']) === count($urmeInainte['jurnal']) + 2, json_encode($urme, JSON_UNESCAPED_UNICODE));

$scrie('');
$scrie('== C. /health și bannerul din admin');
$r = $cerere('GET', '/health');
$verifica('/health pe o bază la zi: 200, „ok” și „schema: la_zi”', $r['cod'] === 200 && $r['corp'] === "ok\nschema: la_zi", $r['cod'] . ' ' . $r['corp']);
$r = $cerere('GET', '/admin', $jarAdmin);
$verifica('bannerul schemei: lipsește pe o bază la zi', $r['cod'] === 200 && !str_contains($r['corp'], 'data-schema='), (string) $r['cod']);
$dbD->exec("UPDATE _platforma_stare SET valoare = '" . ($ultimaOrd - 1) . "' WHERE cheie = 'ultima_ord'");
$r = $cerere('GET', '/health');
$verifica('/health cu o migrare din cod neaplicată: 503, „schema: in_urma”', $r['cod'] === 503 && str_contains($r['corp'], 'schema: in_urma'),
    $r['cod'] . ' ' . $r['corp']);
$r = $cerere('GET', '/admin', $jarAdmin);
$verifica('bannerul schemei: roșu, pentru adminul general, cu ce e de făcut (in_urma)', $r['cod'] === 200
    && str_contains($r['corp'], 'data-schema="in_urma"') && str_contains($r['corp'], 'php scripts/migreaza.php --aplica'), (string) $r['cod']);
$jarBlog = $lucru . '/blog.txt';
$okBlog = $login('blog@exemplu.ro', $jarBlog);
$r = $cerere('GET', '/admin/blog/posts', $jarBlog);
$verifica('bannerul schemei: nu apare pentru un admin cu rol parțial (administrator blog)', $okBlog && $r['cod'] === 200
    && !str_contains($r['corp'], 'data-schema='), (string) $r['cod']);
$dbD->exec("UPDATE _platforma_stare SET valoare = '" . $ultimaOrd . "' WHERE cheie = 'ultima_ord'");
$r = $cerere('GET', '/health');
$verifica('/health după aducerea la zi: 200 din nou', $r['cod'] === 200 && $r['corp'] === "ok\nschema: la_zi", $r['cod'] . ' ' . $r['corp']);

// =====================================================================
// E. Contorul de instrucțiuni
// =====================================================================
$scrie('');
$scrie('== E. Instrucțiunile de schemă pe cerere (CMS_DEV_NUMARA_SQL=1)');
$citesteContorul();
$ok = $login('admin@exemplu.ro', $jarAdmin);
$cerere('GET', '/admin', $jarAdmin);
$cerere('GET', '/admin/orders', $jarAdmin);
$cerere('GET', '/produs/ceai-de-menta');
$jarClient = $lucru . '/client.txt';
@unlink($jarClient);
$idMenta = $unu($dbD, "SELECT id FROM products WHERE slug = 'ceai-de-menta'");
$cerere('POST', '/cos/adauga/' . $idMenta, $jarClient, ['quantity' => '1']);
$pagina = $cerere('GET', '/checkout', $jarClient)['corp'];
$token = preg_match('/name="checkout_form_token" value="([^"]*)"/', $pagina, $m) === 1 ? $m[1] : '';
$randat = preg_match('/name="checkout_form_rendered_at" value="([^"]*)"/', $pagina, $m) === 1 ? $m[1] : '';
sleep(3); // protecția anti-robot refuză comenzile trimise la mai puțin de 2 secunde
$comanda = $cerere('POST', '/checkout', $jarClient, [
    'checkout_form_token' => $token, 'checkout_form_rendered_at' => $randat,
    'billing_first_name' => 'Test', 'billing_last_name' => 'Instalare', 'billing_phone' => '0700000777', 'billing_email' => 'instalare@exemplu.ro',
    'billing_street' => 'Strada Exemplului', 'billing_street_no' => '7', 'billing_city' => 'Cluj-Napoca', 'billing_county' => 'Cluj',
    'billing_postcode' => '400000', 'shipping_same_as_billing' => '1', 'payment_method' => 'cod', 'accept_terms' => '1',
]);
$randuri = $citesteContorul();
$gaseste = static function (array $randuri, string $metoda, string $cale): ?array {
    foreach ($randuri as $r) {
        if ($r['metoda'] === $metoda && $r['cale'] === $cale) {
            return $r;
        }
    }
    return null;
};
$masurate = [
    'dashboard' => $gaseste($randuri, 'GET', '/admin'),
    'lista de comenzi' => $gaseste($randuri, 'GET', '/admin/orders'),
    'pagina de produs' => $gaseste($randuri, 'GET', '/produs/ceai-de-menta'),
    'trimiterea comenzii' => $gaseste($randuri, 'POST', '/checkout'),
];
$descriere = implode('; ', array_map(static fn (string $k, ?array $r): string => $k . ' ' . ($r === null ? 'nemăsurat'
    : $r['instructiuni'] . ' instrucțiuni, ' . $r['ddl'] . ' DDL'), array_keys($masurate), $masurate));
$verifica('pe o bază făcută de install.php: 0 instrucțiuni de schemă (' . $descriere . ')',
    $ok && str_contains($comanda['location'], '/checkout/succes/')
    && array_filter($masurate, static fn (?array $r): bool => $r === null || $r['ddl'] !== 0) === [],
    $descriere . ', comanda → ' . $comanda['cod'] . ' ' . $comanda['location'] . ' ' . json_encode(array_column(array_filter($masurate), 'ddl_texte')));
$cuDdl = array_filter($randuri, static fn (array $r): bool => $r['ddl'] > 0);
$verifica('nicio altă cerere din test nu trimite DDL (' . count($randuri) . ' cereri)', $cuDdl === [], json_encode(array_values($cuDdl)));

// Pe o bază ca înainte: funcțiile ensure* lucrează (fara_registru), apoi baseline-ul le oprește.
$opresteServerul();
$dbV = $bazaNoua('cms_inst_vechi');
[$cv, $ov] = $php('cms_inst_vechi', ['tools/dev/migrari/pe-vechi.php']);
[$cs] = $php('cms_inst_vechi', ['scripts/seed.php']);
[$cd] = $php('cms_inst_vechi', ['tools/dev/date-demo.php']);
$verifica('o bază ca înainte: pe-vechi.php, seed.php, date-demo.php', $cv === 0 && $cs === 0 && $cd === 0 && str_contains($ov, 'garda: fara_registru'), $ov);
$verifica('serverul testului pe baza ca înainte', $pornesteServerul('cms_inst_vechi'));
$r = $cerere('GET', '/health');
$verifica('/health pe baza ca înainte: 200, „schema: fara_registru”', $r['cod'] === 200 && $r['corp'] === "ok\nschema: fara_registru", $r['corp']);
$ok = $login('admin@exemplu.ro', $jarAdmin);
$citesteContorul();
$r = $cerere('GET', '/admin', $jarAdmin);
$inainteDeBaseline = $gaseste($citesteContorul(), 'GET', '/admin');
$verifica('bannerul schemei pe baza ca înainte: fara_registru, cu --plan și --aplica', $r['cod'] === 200
    && str_contains($r['corp'], 'data-schema="fara_registru"') && str_contains($r['corp'], 'php scripts/migreaza.php --plan'));
// Codul comun pe o bază de dinainte de adoptare (o copie restaurată fără runner, un release urcat înainte de --aplica),
// cu ERP și acorduri_gdpr pornite: pașii de activare nu scriu nimic până la baseline, ca pragul ERP să nu ia locul
// înghețării setărilor (care scrie doar o cheie lipsă).
$cerere('GET', '/');
[$codErpV, $outErpV, $errErpV] = $php('cms_inst_vechi', ['scripts/erp-sync.php']);
$urmeVechi = $urmeActivare($dbV);
$verifica('pe baza ca înainte (fara_registru), licența „toate”: cererile și cronul ERP nu rulează pașii de activare (fără erp_trimite_din, fără '
    . Activari::CHEIE_SETARE . ', niciun rând în jurnal)',
    $codErpV === 0 && $urmeVechi === ['prag' => null, 'inregistrare' => [], 'jurnal' => []],
    'cod ' . $codErpV . ' ' . json_encode($urmeVechi, JSON_UNESCAPED_UNICODE) . ' ' . $outErpV . $errErpV);
[$ca, $oa] = $php('cms_inst_vechi', ['scripts/migreaza.php', '--aplica']);
$cerere('GET', '/admin', $jarAdmin);
$dupaBaseline = $gaseste($citesteContorul(), 'GET', '/admin');
$urmeVechi = $urmeActivare($dbV);
$verifica('după baseline (--aplica), prima cerere rulează pașii: erp_trimite_din scris, acorduri_gdpr și erp în înregistrare, pagina '
    . '/acorduri-gdpr „exista (neatinsă)” (o făcuseră funcțiile ensure*)',
    preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $urmeVechi['prag']) === 1 && $urmeVechi['inregistrare'] === ['acorduri_gdpr', 'erp']
    && $urmeVechi['jurnal'] === ['pagina /acorduri-gdpr exista (neatinsă)', 'erp_trimite_din scris (de acum)'],
    json_encode($urmeVechi, JSON_UNESCAPED_UNICODE));
$verifica('dashboard-ul pe baza ca înainte: ' . ($inainteDeBaseline['ddl'] ?? '?') . ' instrucțiuni de schemă (din '
    . ($inainteDeBaseline['instructiuni'] ?? '?') . '), după baseline (--aplica, cod ' . $ca . '): ' . ($dupaBaseline['ddl'] ?? '?') . ' (din '
    . ($dupaBaseline['instructiuni'] ?? '?') . ')',
    $ok && $ca === 0 && ($inainteDeBaseline['ddl'] ?? 0) > 100 && ($dupaBaseline['ddl'] ?? -1) === 0, $oa);
$opresteServerul();

// =====================================================================
// F. Înghețarea setărilor la adoptare (WP-06)
// =====================================================================
$scrie('');
$scrie('== F. Înghețarea setărilor (pasul site/<site>/setari-inghetate, WP-06)');
// Un pachet de site inventat („proba”): pragul ERP gol (ca Bioscem), un nume, o adresă privată (domeniul rezervat .test).
$pachetF = $lucru . '/pachet-proba';
@mkdir($pachetF . '/privat', 0770, true);
$textF = json_encode(['_despre' => 'Test', 'format' => 2, 'site' => 'proba', 'sursa' => 'cod-vechi-proba@abc1234', 'setari' => [
    'contact_form_recipients' => ['valoare' => 'adresa1@exemplu.ro', 'mod' => 'lipsa_sau_gol', 'din' => 'implicit', 'privat' => true, 'sursa' => 'test'],
    'erp_trimite_din' => ['valoare' => '', 'mod' => 'lipsa', 'din' => 'manual', 'sursa' => 'test: fără prag, ca Bioscem'],
    'store_name' => ['valoare' => 'Magazin Proba', 'mod' => 'lipsa_sau_gol', 'din' => 'manual', 'sursa' => 'test'],
    'welcome_popup_enabled' => ['valoare' => '0', 'mod' => 'lipsa', 'din' => 'manual', 'sursa' => 'test'],
], 'fara_inghetare' => new stdClass()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
file_put_contents($pachetF . '/setari-inghetate.json', $textF);
$adresaF = 'birou.proba@client-inventat.test';
file_put_contents($pachetF . '/privat/setari-inghetate.json', json_encode(['site' => 'proba', 'sursa' => 'cod-vechi-proba@abc1234',
    'pentru' => hash('sha256', $textF), 'setari' => ['contact_form_recipients' => $adresaF]]));
$cuPachetF = ['SITE_DATE_DOSAR' => $pachetF];
$setareF = static fn (PDO $db, string $cheie): ?string => ($v = $db->query("SELECT `value` FROM settings WHERE `key` = " . $db->quote($cheie))->fetchColumn()) === false
    ? null : (string) $v;
$licenta(['toate']);

// Un site nou instalat cu pachetul lui: înghețarea rulează în migrări, înaintea setărilor implicite, deci valorile ei rămân.
$dbN = $bazaNoua('cms_inst_pachet');
[$cn, $on, $en] = $php('cms_inst_pachet', ['scripts/install.php'], $cuPachetF + ['SITE_SLUG' => 'proba']);
Garda::uita();
$verifica('install.php cu pachetul site-ului (SITE_SLUG=proba): 0; înghețarea aplicată înaintea setărilor implicite (contact_form_recipients '
    . 'are valoarea privată, nu implicitul nucleului; welcome_popup_enabled 0, nu 1); pragul ERP gol rămas gol la pașii de activare; garda la_zi; '
    . 'valoarea privată nicăieri în ieșire',
    $cn === 0 && $setareF($dbN, 'contact_form_recipients') === $adresaF && $setareF($dbN, 'welcome_popup_enabled') === '0'
    && $setareF($dbN, 'store_name') === 'Magazin Proba' && $setareF($dbN, 'erp_trimite_din') === ''
    && str_contains($on, 'Activare erp: erp_trimite_din exista (neatins)')
    && $unu($dbN, "SELECT rezultat FROM _platforma_migrari WHERE id = 'site/proba/setari-inghetate'") === 'aplicata'
    && Garda::stare($dbN) === Garda::LA_ZI && !str_contains($on . $en, 'client-inventat'), 'cod ' . $cn . ' | ' . $on . $en);
$dbN2 = $bazaNoua('cms_inst_pachet_fara_site');
[$cn2, $on2, $en2] = $php('cms_inst_pachet_fara_site', ['scripts/install.php'], $cuPachetF + ['SITE_SLUG' => null]);
$verifica('install.php cu pachetul unui site dar fără SITE_SLUG: 1 (înghețarea n-ar rula), baza neatinsă',
    $cn2 === 1 && str_contains($on2 . $en2, '--site=proba') && $unu($dbN2, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') === '0',
    'cod ' . $cn2 . ' | ' . $on2 . $en2);
// Pachetul altui site și o corectură scrisă de mână cu o greșeală de JSON: refuzul runner-ului (codul 2) iese pe stderr, cu 1
// (nu 255 fără niciun cuvânt), înainte să se atingă baza.
$pachetStricat = $lucru . '/pachet-proba-stricat';
@mkdir($pachetStricat, 0770, true);
copy($pachetF . '/setari-inghetate.json', $pachetStricat . '/setari-inghetate.json');
file_put_contents($pachetStricat . '/setari-inghetate-2.json', '{"format": 2, "site": "proba", "setari": {"store_name": "x",}}');
$tabeleBazei = static fn (PDO $db): string => $unu($db, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
$dbN3 = $bazaNoua('cms_inst_pachet_alt_site');
[$cn3, $on3, $en3] = $php('cms_inst_pachet_alt_site', ['scripts/install.php'], $cuPachetF + ['SITE_SLUG' => 'altul']);
[$cn4, $on4, $en4] = $php('cms_inst_pachet_alt_site', ['scripts/install.php'], ['SITE_DATE_DOSAR' => $pachetStricat, 'SITE_SLUG' => 'proba']);
$verifica('install.php cu pachetul altui site (SITE_SLUG=altul) și cu o corectură setari-inghetate-2.json stricată: 1 de fiecare dată, '
    . 'cu motivul pe stderr, baza neatinsă',
    $cn3 === 1 && str_contains($en3, 'altui site') && $cn4 === 1 && str_contains($en4, 'setari-inghetate-2.json')
    && $tabeleBazei($dbN3) === '0', 'cod ' . $cn3 . '/' . $cn4 . ' | ' . $on3 . $en3 . $on4 . $en4);

// Adoptarea unui site vechi: baseline-ul oprit între ultima migrare și înghețare (aici --pana-la; pe server bugetul de timp sau
// un proces omorât). Garda spune in_urma până termină pașii site-ului, deci pasul ERP nu scrie pragul înaintea înghețării.
// Ultima migrare din cod, după număr (de la WP-R, una de după baseline: magazin/0032), ca --pana-la să le ia pe toate.
$ultimaMigrare = (static function () use ($radacina): string {
    $ultima = '';
    $ord = -1;
    foreach (glob($radacina . '/database/migrari/*/[0-9][0-9][0-9][0-9]_*.php') ?: [] as $fisier) {
        $numar = (int) substr(basename($fisier), 0, 4);
        if ($numar > $ord) {
            $ord = $numar;
            $ultima = basename(dirname($fisier)) . '/' . basename($fisier, '.php');
        }
    }
    return $ultima;
})();
$dbA = $bazaNoua('cms_inst_adoptare');
[$cpv, $opv] = $php('cms_inst_adoptare', ['tools/dev/migrari/pe-vechi.php']);
[$ca1, $oa1] = $php('cms_inst_adoptare', ['scripts/migreaza.php', '--aplica', '--site=proba', '--pana-la=' . $ultimaMigrare], $cuPachetF);
Garda::uita();
$stareA1 = Garda::stare($dbA);
$verifica('baza ca înainte, baseline cu --site=proba oprit înaintea înghețării: 0, ultima_ord la zi, dar pasi_site rămâne și garda spune in_urma',
    $cpv === 0 && $ca1 === 0 && $unu($dbA, "SELECT valoare FROM _platforma_stare WHERE cheie = 'ultima_ord'") === (string) $ultimaOrd
    && json_decode($unu($dbA, "SELECT valoare FROM _platforma_stare WHERE cheie = 'pasi_site'"), true) === ['site' => 'proba',
        'pasi' => ['site/proba/setari-inghetate']] && $stareA1 === Garda::IN_URMA,
    $stareA1 . ' ' . $opv . $oa1);
$verifica('serverul testului pe baza în adoptare', $pornesteServerul('cms_inst_adoptare'));
$cerere('GET', '/');
[$codErpA, $outErpA, $errErpA] = $php('cms_inst_adoptare', ['scripts/erp-sync.php']);
$urmeA = $urmeActivare($dbA);
$verifica('cât pașii site-ului n-au terminat, o cerere și cronul ERP nu rulează pașii de activare (fără erp_trimite_din, fără înregistrare)',
    $codErpA === 0 && $urmeA === ['prag' => null, 'inregistrare' => [], 'jurnal' => []], json_encode($urmeA) . $outErpA . $errErpA);
// Rulat din nou cu --site, dar fără pachet (o cale greșită, un pachet mutat) sau cu un dosar fără înghețare: refuzat, cheia rămâne,
// deci garda tot in_urma și pragul ERP tot nescris (altfel rularea ar ajunge la capăt fără înghețare și ar șterge cheia).
$faraInghetare = $lucru . '/pachet-fara-inghetare';
@mkdir($faraInghetare, 0770, true);
[$cr1, $or1] = $php('cms_inst_adoptare', ['scripts/migreaza.php', '--aplica', '--site=proba'], ['SITE_DATE_DOSAR' => $lucru . '/pachet-care-nu-exista']);
[$cr2, $or2] = $php('cms_inst_adoptare', ['scripts/migreaza.php', '--aplica', '--site=proba'], ['SITE_DATE_DOSAR' => $faraInghetare]);
$activariR = $licenta(['activari'], 'cms_inst_adoptare');
Garda::uita();
$urmeR = $urmeActivare($dbA);
$verifica('--aplica --site=proba cu dosarul pachetului lipsă și cu un dosar fără înghețare: 2 (baza așteaptă pasul), pasi_site rămâne, garda '
    . 'in_urma; licenta.php activari (ca prima cerere sau cronul ERP) tot nu scrie pragul',
    $cr1 === 2 && str_contains($or1, 'nu există') && $cr2 === 2 && str_contains($or2, 'baza așteaptă site/proba/setari-inghetate')
    && $unu($dbA, "SELECT COUNT(*) FROM _platforma_stare WHERE cheie = 'pasi_site'") === '1' && Garda::stare($dbA) === Garda::IN_URMA
    && str_contains($activariR, 'așteaptă migrările') && $urmeR === ['prag' => null, 'inregistrare' => [], 'jurnal' => []],
    $or1 . $or2 . $activariR . json_encode($urmeR));
$contactInainte = $setareF($dbA, 'contact_form_recipients');
$popupInainte = $setareF($dbA, 'welcome_popup_enabled');
[$ca2, $oa2, $ea2] = $php('cms_inst_adoptare', ['scripts/migreaza.php', '--aplica', '--site=proba'], $cuPachetF);
Garda::uita();
$verifica('--aplica --site=proba din nou: înghețarea aplicată doar unde lipsește (erp_trimite_din gol, store_name), setările deja în baza '
    . 'veche (contact_form_recipients, welcome_popup_enabled) neatinse; pasi_site ștearsă, garda la_zi',
    $ca2 === 0 && $setareF($dbA, 'erp_trimite_din') === '' && $setareF($dbA, 'store_name') === 'Magazin Proba'
    && $contactInainte !== null && $setareF($dbA, 'contact_form_recipients') === $contactInainte && $setareF($dbA, 'welcome_popup_enabled') === $popupInainte
    && $unu($dbA, "SELECT COUNT(*) FROM _platforma_stare WHERE cheie = 'pasi_site'") === '0' && Garda::stare($dbA) === Garda::LA_ZI
    && !str_contains($oa2 . $ea2, 'client-inventat'), $oa2 . $ea2);
$cerere('GET', '/');
$urmeA = $urmeActivare($dbA);
$verifica('prima cerere de după: pașii de activare rulează, pasul ERP găsește pragul gol al înghețării și nu-l atinge (fără prag, ca Bioscem)',
    $urmeA['prag'] === '' && $urmeA['inregistrare'] === ['acorduri_gdpr', 'erp'] && in_array('erp_trimite_din exista (neatins)', $urmeA['jurnal'], true),
    json_encode($urmeA, JSON_UNESCAPED_UNICODE));
$opresteServerul();

$scrie('');
$scrie('Testele instalării: ' . $trecute . ' trecute, ' . $esuate . ' eșuate');
exit($esuate === 0 ? 0 : 1);
