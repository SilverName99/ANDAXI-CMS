<?php

declare(strict_types=1);

/**
 * Starea site-ului de verificare, pe server, prin SSH (docs/VERIFICARE.md): ce e
 * configurat, ce știe găzduirea și dacă site-ul e închis publicului.
 *
 *   $PHP tools/verificare/stare.php
 *   $PHP tools/verificare/stare.php --adresa=http://127.0.0.1:8708   # cererile spre altă adresă decât APP_URL (testele)
 *
 * Tipărește doar DA/NU, versiuni, setări PHP și clase de adrese („privată (10.0.0.0/8)”,
 * „publică”): niciodată o parolă, numele bazei, o adresă de email sau date din bază.
 * Textul se poate trimite ca atare. Nu schimbă nimic; scrie doar jetonul de unică
 * folosință al sondei (`storage/verificare/sonda.json`, 2 minute), cu care pagina
 * `/_verificare/sonda` a site-ului spune ce vede PHP-ul web (versiunea, dacă antetul HTTP
 * Basic și cookie-urile ajung la el, ce adresă are vizitatorul, ce funcții sunt oprite).
 *
 * Ce verifică: PHP-ul liniei de comandă (și celelalte din /opt/alt), dosarul site-ului,
 * .env (0600), poarta, baza (serverul, dacă e goală), apoi, cu cereri spre site: fără
 * parolă 401, noindex și no-store pe răspuns, robots.txt „Disallow: /”, .env, .git și
 * tools/ de neatins din browser, sonda.
 *
 * Ultimul rând:
 * - „PREGĂTIT PENTRU CONFIGURARE, NU PENTRU DATE” (cod 0): poarta și configurarea merg;
 *   gărzile pentru date (emailuri, plăți, ERP, FAN) vin în WP-08, deci nu se încarcă încă
 *   nicio copie;
 * - „NECONFIGURAT: …” (cod 1): încă nu s-a rulat configureaza.php; site-ul trebuie să
 *   răspundă 503 tuturor;
 * - „NU E GATA: …” (cod 1): ceva de mai sus a ieșit NU.
 *
 * Fișierul folosește doar sintaxa PHP 7.0, ca un PHP prea vechi să-și vadă versiunea.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
$adresaData = '';
foreach (array_slice($argv, 1) as $argument) {
    if (strpos($argument, '--adresa=') === 0) {
        $adresaData = rtrim(substr($argument, strlen('--adresa=')), '/');
        continue;
    }
    fwrite(STDERR, 'EROARE: argument necunoscut: ' . $argument . " (vezi capul fișierului)\n");
    exit(1);
}

$GLOBALS['stare_nu'] = 0;

/** Un rând DA/NU; $esential = intră în verdict. */
function stare_rand($ok, $text, $esential = true)
{
    if (!$ok && $esential) {
        $GLOBALS['stare_nu']++;
    }
    echo '  ' . str_pad($ok ? 'DA' : 'NU', 6) . $text . "\n";
}

function stare_info($text)
{
    echo '  INFO  ' . $text . "\n";
}

function stare_titlu($text)
{
    echo "\n" . $text . "\n";
}

function stare_oprita($functie)
{
    $oprite = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !function_exists($functie) || in_array($functie, $oprite, true);
}

/** O cerere HTTP fără redirecționări; doar codul, antetele (litere mici) și primii 64 KB. */
function stare_cerere($url, $antete)
{
    if (!function_exists('curl_init')) {
        return array('cod' => 0, 'antete' => array(), 'corp' => '', 'eroare' => 'PHP-ul liniei de comandă nu are curl');
    }
    $primite = array();
    $c = curl_init($url);
    curl_setopt_array($c, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $antete,
        CURLOPT_USERAGENT => 'andaxi-verificare-stare',
        CURLOPT_HEADERFUNCTION => function ($c, $linie) use (&$primite) {
            if (strpos($linie, ':') !== false) {
                $parti = explode(':', $linie, 2);
                $primite[strtolower(trim($parti[0]))] = trim($parti[1]);
            }
            return strlen($linie);
        },
    ));
    $corp = curl_exec($c);
    $rezultat = array(
        'cod' => (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE),
        'antete' => $primite,
        'corp' => is_string($corp) ? substr($corp, 0, 65536) : '',
        'eroare' => $corp === false ? curl_error($c) : '',
    );
    curl_close($c);
    return $rezultat;
}

echo 'Starea site-ului de verificare — ' . date('Y-m-d H:i') . "\n";

// ── PHP-ul liniei de comandă ──

$textConfig = (string) @file_get_contents($radacina . '/config/app.php');
$phpMin = preg_match("/'php_min'\\s*=>\\s*'([0-9.]+)'/", $textConfig, $m) === 1 ? $m[1] : '8.3';
stare_titlu('PHP (linia de comandă)');
stare_info('PHP CLI: ' . PHP_VERSION . ' (' . PHP_BINARY . ')');
$phpBun = version_compare(PHP_VERSION, $phpMin, '>=');
stare_rand($phpBun, 'PHP CLI ≥ minimul codului (php_min ' . $phpMin . ')');
$alte = array();
$gasite = glob('/opt/alt/php*/usr/bin/php');
foreach (is_array($gasite) ? $gasite : array() as $binar) {
    $alte[] = basename(dirname(dirname(dirname($binar))));
}
stare_info('PHP-uri pe server (/opt/alt): ' . ($alte !== array() ? implode(', ', $alte) : 'niciunul'));
if (!$phpBun) {
    echo "\nNU E GATA: PHP-ul liniei de comandă (" . PHP_VERSION . ') e mai vechi decât minimul (' . $phpMin
        . '). Rulează cu unul din /opt/alt, de exemplu /opt/alt/php83/usr/bin/php' . "\n";
    exit(1);
}

require_once $radacina . '/src/Support/Env.php';
require_once $radacina . '/src/Support/IpClient.php';
require_once $radacina . '/src/Support/Verificare.php';

use App\Support\Env;
use App\Support\IpClient;
use App\Support\Verificare;

stare_info('max_execution_time (CLI): ' . ini_get('max_execution_time') . '; memory_limit (CLI): ' . ini_get('memory_limit'));
stare_rand(!stare_oprita('proc_open'), 'proc_open pornit (CLI)', false);
stare_rand(!stare_oprita('exec') && !stare_oprita('shell_exec'), 'exec și shell_exec pornite (CLI)', false);
stare_rand(!stare_oprita('symlink'), 'symlink() pornit (CLI)', false);
foreach (array('pdo_mysql', 'sodium') as $extensie) {
    stare_rand(extension_loaded($extensie), 'extensia ' . $extensie . ' (CLI)');
}
$lipsa = array();
foreach (array('curl', 'mbstring', 'gd', 'intl', 'openssl', 'zip') as $extensie) {
    if (!extension_loaded($extensie)) {
        $lipsa[] = $extensie;
    }
}
stare_rand($lipsa === array(), 'extensiile curl, mbstring, gd, intl, openssl, zip (CLI)' . ($lipsa !== array() ? ': lipsesc ' . implode(', ', $lipsa) : ''), false);
stare_rand(function_exists('imagewebp'), 'GD cu WebP (imagewebp, CLI)', false);
$git = stare_oprita('shell_exec') ? '' : trim((string) shell_exec('command -v git 2>/dev/null'));
stare_rand($git !== '', 'git pe server (pentru git pull prin SSH)', false);

// ── Dosarul și .env ──

stare_titlu('Dosarul site-ului');
$acasa = (string) getenv('HOME');
if ($acasa === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
    $utilizatorSistem = posix_getpwuid(posix_geteuid());
    $acasa = is_array($utilizatorSistem) ? (string) $utilizatorSistem['dir'] : '';
}
$gazda = $acasa === '' ? null : Verificare::dosarSite((string) realpath($radacina), $acasa);
stare_rand($gazda !== null, 'dosarul e ~/domains/<domeniu>/public_html' . ($gazda !== null ? ' (domeniul ' . $gazda . ')' : ''));
$cap = @file_get_contents($radacina . '/.git/HEAD');
if (is_string($cap)) {
    $cap = trim($cap);
    $ramura = strpos($cap, 'ref: ') === 0 ? substr($cap, 5) : '';
    $commit = $ramura === '' ? $cap : trim((string) @file_get_contents($radacina . '/.git/' . $ramura));
    if ($commit === '' && $ramura !== '' && is_file($radacina . '/.git/packed-refs')) {
        foreach (file($radacina . '/.git/packed-refs') as $rand) {
            $parti = explode(' ', trim($rand));
            if (count($parti) === 2 && $parti[1] === $ramura) {
                $commit = $parti[0];
            }
        }
    }
    stare_info('codul din git: ' . ($ramura !== '' ? preg_replace('#^refs/heads/#', '', $ramura) . ' ' : '') . substr($commit, 0, 7));
} else {
    stare_info('codul nu are .git (nu e un deploy din hPanel → GIT)');
}

$caleEnv = Verificare::caleEnv();
$envExista = is_file($caleEnv);
stare_rand($envExista, '.env există (îl scrie configureaza.php)');
if ($envExista) {
    stare_rand((fileperms($caleEnv) & 0777) === 0600, '.env doar pentru tine (0600)');
    Env::load($caleEnv);
    $activ = Verificare::activ();
    stare_rand($activ, 'SITE_COPIE_VERIFICARE=1 (poarta e pornită)');
    $appUrl = rtrim(trim((string) Env::get('APP_URL', '')), '/');
    stare_rand($gazda !== null && $appUrl === 'https://' . $gazda, 'APP_URL e https://<domeniul dosarului>');
    $problema = Verificare::problemaPoarta();
    stare_rand($problema === null, 'poarta are utilizator și parolă' . ($problema !== null ? ' (' . $problema . ')' : ''));
    stare_rand(trim((string) Env::get('VERIFICARE_EMAIL', '')) !== '', 'emailul proprietarului e scris (VERIFICARE_EMAIL)', false);
    $smtp = trim((string) Env::get('VERIFICARE_SMTP_UTILIZATOR', '')) !== '';
    stare_info('căsuța de trimitere: ' . ($smtp ? 'configurată' : 'fără (emailurile vor rămâne fișiere pe server)'));
    $modPoarta = strtolower(trim((string) Env::get('VERIFICARE_POARTA_MOD', 'basic')));
    stare_info('modul porții: ' . ($modPoarta === 'formular' ? 'formular (fără fereastra browserului)' : 'basic (fereastra browserului, plus formularul)'));
    $dosarBun = Verificare::pregatesteDosar();
    stare_rand($dosarBun, 'dosarul de lucru storage/verificare se poate scrie');
    $copie = Verificare::stareCopie();
    $textCopie = array(
        'lipsa' => 'lipsește (normal până la prima încărcare)',
        'expirata' => 'expirată (mai veche de ' . $copie['zile'] . ' zile): se șterge și se încarcă din nou',
        'ok' => 'încărcată acum ' . (int) floor((time() - (int) $copie['incarcat_la']) / 3600) . ' ore (se păstrează ' . $copie['zile'] . ' zile)',
    );
    stare_info('copia de verificare: ' . $textCopie[$copie['stare']]);

    // ── Baza ──
    stare_titlu('Baza de date');
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', (string) Env::get('DB_HOST', 'localhost'), (int) Env::get('DB_PORT', 3306), (string) Env::get('DB_DATABASE', '')),
            (string) Env::get('DB_USERNAME', ''),
            (string) Env::get('DB_PASSWORD', ''),
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5)
        );
        stare_rand(true, 'legătura cu baza');
        $versiune = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $familie = stripos($versiune, 'mariadb') !== false ? 'MariaDB' : 'MySQL';
        stare_info('serverul bazei: ' . $familie . ' ' . (preg_match('/^\d+\.\d+\.\d+/', $versiune, $m) === 1 ? $m[0] : '?'));
        stare_info('sql_mode: ' . (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn());
        stare_info('max_allowed_packet: ' . (int) $pdo->query('SELECT @@max_allowed_packet')->fetchColumn() . ' octeți');
        $tabele = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
        $marcaj = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '_verificare'")->fetchColumn() > 0;
        stare_rand($tabele === 0 || $marcaj, 'baza e goală sau e o copie de verificare (' . $tabele . ' tabele' . ($marcaj ? ', cu _verificare' : '') . ')');
        $pdo = null;
    } catch (PDOException $e) {
        $codDb = preg_match('/\[(\d{4})\]/', $e->getMessage(), $m) === 1 ? (int) $m[1] : (int) $e->getCode();
        stare_rand(false, 'legătura cu baza (eroarea ' . $codDb . '; configureaza.php --rescrie dacă s-a schimbat parola)');
    }
} else {
    $activ = false;
}

// ── Site-ul văzut din afară ──

$baza = $adresaData !== '' ? $adresaData : ($envExista && isset($appUrl) && $appUrl !== '' ? $appUrl : ($gazda !== null ? 'https://' . $gazda : ''));
stare_titlu('Site-ul, văzut din afară' . ($baza !== '' ? ' (' . $baza . ')' : ''));
$inchis = false;
if ($baza === '') {
    stare_rand(false, 'nu știu adresa site-ului (nici APP_URL, nici dosarul ~/domains/<domeniu>)');
} else {
    $acasaR = stare_cerere($baza . '/', array());
    $robotiPe = (string) ($acasaR['antete']['x-robots-tag'] ?? '');
    $cachePe = (string) ($acasaR['antete']['cache-control'] ?? '');
    if ($acasaR['cod'] === 0) {
        stare_rand(false, 'site-ul răspunde (' . ($acasaR['eroare'] !== '' ? 'eroare de legătură: ' . $acasaR['eroare'] : 'fără răspuns') . ')');
    } elseif (!$envExista) {
        $inchis = $acasaR['cod'] === 503;
        stare_rand($inchis, 'fără configurare, / răspunde 503 „neconfigurat” (închis publicului; acum: ' . $acasaR['cod'] . ')');
    } else {
        $inchis = $acasaR['cod'] === 401;
        stare_rand($inchis, 'fără parolă, / răspunde 401 (cere parola porții; acum: ' . $acasaR['cod'] . ')'
            . ($acasaR['cod'] === 200 ? ' ATENȚIE: SITE-UL SE VEDE PUBLIC' : ''));
    }
    if ($acasaR['cod'] !== 0) {
        stare_rand(stripos($robotiPe, 'noindex') !== false, 'X-Robots-Tag: noindex pe răspuns');
        stare_rand(stripos($cachePe, 'no-store') !== false, 'Cache-Control: no-store pe răspuns');
        $roboti = stare_cerere($baza . '/robots.txt', array());
        if ($envExista) {
            stare_rand($roboti['cod'] === 200 && preg_match('/^Disallow: \/\s*$/m', $roboti['corp']) === 1, '/robots.txt: „Disallow: /”');
        } else {
            stare_rand($roboti['cod'] === 503, '/robots.txt răspunde 503 (Google nu citește nimic cât e așa)');
        }
        $env = stare_cerere($baza . '/.env', array());
        stare_rand(!($env['cod'] === 200 && strpos($env['corp'], 'DB_') !== false), '/.env nu se vede din browser');
        $gitConfig = stare_cerere($baza . '/.git/config', array());
        stare_rand(!($gitConfig['cod'] === 200 && strpos($gitConfig['corp'], '[core]') !== false), '/.git/config nu se vede din browser');
        $unealta = stare_cerere($baza . '/tools/verificare/stare.php', array());
        stare_rand(!($unealta['cod'] === 200 && strpos($unealta['corp'], 'Starea site-ului') !== false) && strpos($unealta['corp'], '<?php') === false,
            '/tools/verificare/stare.php nu rulează și nu se vede din browser');
    }

    // Sonda: ce vede PHP-ul web (doar cu poarta pornită și configurată).
    if ($activ && $acasaR['cod'] !== 0 && isset($dosarBun) && $dosarBun && Verificare::problemaPoarta() === null) {
        $jeton = bin2hex(random_bytes(32));
        $fisierSonda = Verificare::dosar() . '/sonda.json';
        $umaskVechi = umask(0077);
        @file_put_contents($fisierSonda, json_encode(array('amprenta' => hash('sha256', $jeton), 'expira' => time() + Verificare::DURATA_SONDA)));
        umask($umaskVechi);
        $sonda = stare_cerere($baza . Verificare::CALE_SONDA . '?sonda=' . $jeton, array(
            'Authorization: Basic ' . base64_encode('sonda:' . $jeton),
            'Cookie: ' . Verificare::COOKIE_SONDA . '=' . $jeton,
            'X-Verificare-Sonda: ' . $jeton,
        ));
        @unlink($fisierSonda);
        $fapte = $sonda['cod'] === 200 ? json_decode($sonda['corp'], true) : null;
        stare_rand(is_array($fapte), 'sonda răspunde (ce vede PHP-ul site-ului)' . (is_array($fapte) ? '' : ': cod ' . $sonda['cod']));
        if (is_array($fapte)) {
            stare_info('PHP web: ' . (string) $fapte['php'] . ' (' . (string) $fapte['sapi'] . ($fapte['server'] !== '' ? ', ' . (string) $fapte['server'] : '') . ')');
            stare_rand(version_compare((string) $fapte['php'], $phpMin, '>='), 'PHP web ≥ minimul codului (php_min ' . $phpMin . ')');
            $basic = (string) $fapte['basic'];
            stare_rand($basic !== '', 'antetul HTTP Basic ajunge la PHP' . ($basic !== '' ? ' (prin ' . $basic . ')' : ''), false);
            stare_rand((bool) $fapte['cookie'], 'cookie-urile ajung la PHP (formularul porții)', false);
            stare_rand($basic !== '' || (bool) $fapte['cookie'], 'poarta se poate trece (Basic sau formular)');
            if ($basic === '' && (bool) $fapte['cookie']) {
                stare_info('Basic nu ajunge: pune VERIFICARE_POARTA_MOD=formular în .env (intri din formularul paginii)');
            }
            stare_rand((bool) $fapte['antet'], 'un antet propriu (X-Verificare-Sonda) ajunge la PHP', false);
            stare_info('REMOTE_ADDR: ' . (string) $fapte['remote_addr'] . '; e adresa serverului (SERVER_ADDR): ' . ($fapte['remote_addr_e_server'] ? 'DA' : 'NU'));
            stare_info('X-Forwarded-For: ' . ((int) $fapte['xff'] === 0 ? 'nu sosește' : (int) $fapte['xff'] . ' adrese, ultima ' . (string) $fapte['xff_ultima'])
                . '; X-Real-IP: ' . ($fapte['x_real_ip'] ? 'DA' : 'NU') . '; CF-Connecting-IP: ' . ($fapte['cf_connecting_ip'] ? 'DA' : 'NU')
                . '; Forwarded: ' . ($fapte['forwarded'] ? 'DA' : 'NU'));
            stare_info('HTTPS văzut de PHP: ' . ($fapte['https'] ? 'DA' : 'NU') . '; X-Forwarded-Proto: ' . ((string) $fapte['x_forwarded_proto'] !== '' ? (string) $fapte['x_forwarded_proto'] : 'nu sosește'));
            stare_info('max_execution_time (web): ' . (string) $fapte['max_execution_time'] . '; memory_limit (web): ' . (string) $fapte['memory_limit']);
            foreach ((array) $fapte['functii'] as $functie => $pornita) {
                stare_rand((bool) $pornita, $functie . ' pornit (web)', false);
            }
            foreach ((array) $fapte['extensii'] as $extensie => $are) {
                stare_rand((bool) $are, 'extensia ' . $extensie . ' (web)', in_array($extensie, array('pdo_mysql', 'sodium'), true));
            }
            stare_rand((bool) $fapte['imagewebp'], 'GD cu WebP (imagewebp, web)', false);
        }
    }
}

echo "\n";
if (!$envExista) {
    if ($inchis && $GLOBALS['stare_nu'] === 1) {
        // Singurul NU e chiar lipsa .env.
        echo "Trimite-ne tot textul de mai sus (nu conține parole).\n";
        echo 'NECONFIGURAT: site-ul e închis publicului (503). Pasul următor: ' . PHP_BINARY . " tools/verificare/configureaza.php\n";
        exit(1);
    }
    echo 'NU E GATA: ' . $GLOBALS['stare_nu'] . " verificări au ieșit NU (mai sus). Trimite-ne tot textul (nu conține parole).\n";
    exit(1);
}
if ($GLOBALS['stare_nu'] > 0) {
    echo 'NU E GATA: ' . $GLOBALS['stare_nu'] . " verificări au ieșit NU (mai sus). Trimite-ne tot textul (nu conține parole).\n";
    exit(1);
}
echo "Poarta, configurarea și faptele găzduirii sunt în regulă. Gărzile pentru date (emailuri, plăți, ERP, FAN) vin\n";
echo "în versiunea următoare a codului; până atunci nu se încarcă nicio copie. Trimite-ne tot textul (nu conține parole).\n";
echo "PREGĂTIT PENTRU CONFIGURARE, NU PENTRU DATE\n";
exit(0);
