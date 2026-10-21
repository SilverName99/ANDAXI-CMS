<?php

declare(strict_types=1);

/**
 * Starea site-ului de verificare, pe server, prin SSH (docs/VERIFICARE.md): ce e
 * configurat, ce știe găzduirea, dacă site-ul e închis publicului și dacă fiecare
 * dintre cele 14 gărzi ale copiei e la locul ei.
 *
 *   $PHP tools/verificare/stare.php
 *   $PHP tools/verificare/stare.php --adresa=http://127.0.0.1:8708   # cererile spre altă adresă decât APP_URL (testele)
 *
 * Tipărește doar DA/NU, versiuni, setări PHP și clase de adrese („privată (10.0.0.0/8)”,
 * „publică”): niciodată o parolă, numele bazei, o adresă de email sau date din bază.
 * Textul se poate trimite ca atare. Nu schimbă nimic; scrie doar jetonul de unică
 * folosință al sondei (`storage/verificare/sonda.json`, 2 minute), cu care pagina
 * `/_verificare/sonda` a site-ului spune ce vede PHP-ul web (versiunea, dacă antetul HTTP
 * Basic și cookie-urile ajung la el, ce adresă are vizitatorul, ce funcții sunt oprite,
 * gărzile copiei).
 *
 * Ce verifică: PHP-ul liniei de comandă (și celelalte din /opt/alt; sub `php_min` se
 * oprește aici, cu versiunea, cod 1), dosarul site-ului, .env (0600), poarta, cheia
 * licenței de verificare, baza (serverul, dacă e goală, că nu e a altui site din cont),
 * apoi, cu cereri spre site: fără parolă 401, noindex și no-store pe răspuns, robots.txt
 * „Disallow: /”, .env, .git și tools/ de neatins din browser, sonda; la urmă cele 14 gărzi
 * (docs/VERIFICARE.md, „Gărzile”): fiecare cum o vede linia de comandă, cum o vede PHP-ul
 * site-ului (sonda) și la locul ei din cod, chemat cu date inventate (un email simulat,
 * o cheie Stripe live inventată, un AWB, ERP-ul la o adresă inventată …). Cât le cheamă,
 * procesul are proxy-ul spre o adresă pe care nu ascultă nimic (127.0.0.1:9): chiar o
 * gardă lipsă nu poate trimite nimic în afară.
 *
 * Ultimul rând:
 * - „GATA: site-ul de verificare e pregătit (toate verificările DA)” (cod 0): se poate
 *   încărca o copie (WP-09);
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

/**
 * O cerere HTTP fără redirecționări; doar codul, antetele (litere mici) și primii 64 KB. Mereu direct, fără proxy
 * (nici unul din mediu: la gărzi procesul își pune proxy-ul spre nicăieri).
 */
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
        CURLOPT_PROXY => '',
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

/** Locul din cod refuză? (aruncă o excepție al cărei mesaj are textul gărzii) */
function stare_refuza($functie, $text)
{
    try {
        call_user_func($functie);
    } catch (Throwable $e) {
        return strpos($e->getMessage(), $text) !== false;
    }
    return false;
}

/** Fișierul de cod are chemarea gărzii? (codul de pe server e cel din git; aici se vede că e și cel deployat) */
function stare_cheama($radacina, $fisier, $apel)
{
    $text = @file_get_contents($radacina . '/' . $fisier);
    return is_string($text) && strpos($text, $apel) !== false;
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
require_once $radacina . '/tools/verificare/lib/cont.php';

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
    stare_rand(filter_var(trim((string) Env::get('VERIFICARE_EMAIL', '')), FILTER_VALIDATE_EMAIL) !== false,
        'emailul proprietarului e scris (VERIFICARE_EMAIL)');
    $smtp = trim((string) Env::get('VERIFICARE_SMTP_UTILIZATOR', '')) !== '';
    stare_info('căsuța de trimitere: ' . ($smtp ? 'configurată' : 'fără (emailurile rămân fișiere pe server, storage/verificare/mail)'));
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
    $emailuriAzi = Verificare::emailuriAzi();
    stare_info('emailuri azi: ' . $emailuriAzi['trimise'] . ' plecate din ' . Verificare::EMAILURI_PE_ZI . ' pe zi'
        . ($emailuriAzi['sarite'] > 0 ? ', ' . $emailuriAzi['sarite'] . ' sărite (peste limită)' : ''));

    // ── Licența de verificare ──
    stare_titlu('Licența de verificare');
    stare_rand(strpos(trim((string) Env::get('LICENTA_SITE', '')), 'verificare') === 0, 'LICENTA_SITE începe cu „verificare”');
    $cheiePublicaFisier = null;
    $cheieBuna = Verificare::cheiePrivataLicenta($cheiePublicaFisier) !== null;
    $fisierCheie = Verificare::fisierCheieLicenta();
    stare_rand($cheieBuna && (fileperms($fisierCheie) & 0777) === 0600,
        'cheia privată a licenței de verificare, doar pe server (storage/licenta, 0600)'
        . ($cheieBuna ? '' : ': lipsește (configureaza.php --rescrie o face)'));
    stare_rand($cheieBuna && trim((string) Env::get('LICENTA_CHEIE_PUBLICA', '')) === $cheiePublicaFisier,
        'LICENTA_CHEIE_PUBLICA din .env e cheia ei publică');
    $licentaInstalata = json_decode((string) @file_get_contents(dirname($fisierCheie) . '/licenta.json'), true);
    stare_info('licența copiei: ' . (is_array($licentaInstalata)
        ? 'versiunea ' . (int) ($licentaInstalata['versiune'] ?? 0) . ', ' . count((array) ($licentaInstalata['pluginuri'] ?? array())) . ' pluginuri'
        : 'încă niciuna (o pune încărcarea copiei sau tools/verificare/licenta.php <pachet>)'));

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
        // Copia încărcată (tools/verificare/incarca.php): _verificare scris primul, cu anonimizat = 0; abia după verificările
        // A1–A9, anonimizat = 1 și storage/verificare/incarcat-la. O încărcare oprită la jumătate rămâne închisă (fără incarcat-la).
        $randMarcaj = $marcaj ? $pdo->query('SELECT * FROM _verificare LIMIT 1')->fetch(PDO::FETCH_ASSOC) : false;
        $anonimizat = is_array($randMarcaj) && (int) (isset($randMarcaj['anonimizat']) ? $randMarcaj['anonimizat'] : 0) === 1;
        if ($marcaj) {
            $sursaCopiei = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) (isset($randMarcaj['sursa']) ? $randMarcaj['sursa'] : '')));
            $pasCopiei = preg_replace('/[^a-z_]/', '', (string) (isset($randMarcaj['pas']) ? $randMarcaj['pas'] : ''));
            stare_rand($anonimizat, 'copia e încheiată și anonimizată (_verificare: anonimizat = ' . ($anonimizat ? '1' : '0')
                . ($sursaCopiei !== '' ? ', sursa ' . $sursaCopiei : '') . ')'
                . ($anonimizat ? '' : ': încărcare neterminată' . ($pasCopiei !== '' ? ' (pasul ' . $pasCopiei . ')' : '')
                    . '; tools/verificare/incarca.php … --reia o continuă, sterge.php o șterge'));
        }
        stare_rand($copie['stare'] === 'lipsa' || $anonimizat, 'incarcat-la doar cu o copie încheiată'
            . ($copie['stare'] !== 'lipsa' && !$anonimizat ? ': incarcat-la există, dar baza nu are o copie încheiată (sterge.php, apoi încarcă din nou)' : ''));
        $pdo = null;
    } catch (PDOException $e) {
        $codDb = preg_match('/\[(\d{4})\]/', $e->getMessage(), $m) === 1 ? (int) $m[1] : (int) $e->getCode();
        stare_rand(false, 'legătura cu baza (eroarea ' . $codDb . '; configureaza.php --rescrie dacă s-a schimbat parola)');
    }
    $altSite = \Unelte\Verificare\Cont::bazaAltuiSite($acasa, (string) Env::get('DB_DATABASE', ''), (string) Env::get('DB_HOST', 'localhost'));
    stare_rand($altSite === null, 'baza nu e a altui site din cont' . ($altSite !== null ? ' (o folosește ' . $altSite . ': alege altă bază, configureaza.php --rescrie)' : ''));
} else {
    $activ = false;
}

// ── Site-ul văzut din afară ──

$baza = $adresaData !== '' ? $adresaData : ($envExista && isset($appUrl) && $appUrl !== '' ? $appUrl : ($gazda !== null ? 'https://' . $gazda : ''));
stare_titlu('Site-ul, văzut din afară' . ($baza !== '' ? ' (' . $baza . ')' : ''));
$inchis = false;
$noindexPe = false;
$robotiPe = false;
$fapte = null;
if ($baza === '') {
    stare_rand(false, 'nu știu adresa site-ului (nici APP_URL, nici dosarul ~/domains/<domeniu>)');
} else {
    $acasaR = stare_cerere($baza . '/', array());
    $antetRoboti = (string) ($acasaR['antete']['x-robots-tag'] ?? '');
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
        $noindexPe = stripos($antetRoboti, 'noindex') !== false && stripos($cachePe, 'no-store') !== false;
        stare_rand(stripos($antetRoboti, 'noindex') !== false, 'X-Robots-Tag: noindex pe răspuns');
        stare_rand(stripos($cachePe, 'no-store') !== false, 'Cache-Control: no-store pe răspuns');
        $roboti = stare_cerere($baza . '/robots.txt', array());
        if ($envExista) {
            $robotiPe = $roboti['cod'] === 200 && preg_match('/^Disallow: \/\s*$/m', $roboti['corp']) === 1
                && preg_match('/^(Allow|Sitemap):/mi', $roboti['corp']) !== 1;
            stare_rand($robotiPe, '/robots.txt: „Disallow: /”');
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
        $cheieWeb = stare_cerere($baza . '/storage/licenta/' . Verificare::FISIER_CHEIE_LICENTA, array());
        stare_rand(!($cheieWeb['cod'] === 200 && preg_match('/^[A-Za-z0-9+\/=]{80,}\s*$/', $cheieWeb['corp']) === 1),
            '/storage/licenta/ (cheia licenței) nu se vede din browser');
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

// ── Gărzile copiei ──
//
// Fiecare gardă de trei ori: cum o vede linia de comandă (Verificare::garzi(), cu .env-ul site-ului), cum o vede
// PHP-ul site-ului (sonda) și la locul ei din cod, chemat aici cu date inventate. Din acest punct procesul are
// proxy-ul spre o adresă pe care nu ascultă nimic: o gardă lipsă tot nu poate trimite nimic în afară.

if ($activ) {
    stare_titlu('Gărzile copiei (fiecare: în linia de comandă, în PHP-ul site-ului și la locul ei din cod, cu date inventate)');
    foreach (array('http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'all_proxy') as $variabila) {
        putenv($variabila . '=http://127.0.0.1:9');
    }
    putenv('NO_PROXY=');
    putenv('no_proxy=');
    spl_autoload_register(function ($clasa) use ($radacina) {
        if (strpos($clasa, 'App\\') === 0) {
            $fisier = $radacina . '/src/' . str_replace('\\', '/', substr($clasa, 4)) . '.php';
            if (is_file($fisier)) {
                require_once $fisier;
            }
        }
    });
    $garziCli = Verificare::garzi();
    $garziWeb = is_array($fapte) && isset($fapte['garzi']) && is_array($fapte['garzi']) ? $fapte['garzi'] : null;
    $proprietar = trim((string) Env::get('VERIFICARE_EMAIL', ''));
    $fanTest = trim((string) Env::get('VERIFICARE_FAN_TEST', '')) === '1';
    $euTest = trim((string) Env::get('VERIFICARE_EUPLATESC_TEST', '')) === '1';

    // Locul din cod al fiecărei gărzi: true = refuză (sau trimite unde trebuie), altfel textul problemei.
    $locuri = array();
    $locuri['poarta'] = $inchis ? true : 'fără parolă, / nu răspunde 401 (mai sus)';
    $locuri['noindex'] = $noindexPe ? true : 'răspunsul n-are noindex și no-store (mai sus)';
    $locuri['expirare'] = stare_cheama($radacina, 'src/Support/Verificare.php', "\$copie['stare'] === 'expirata'")
        ? true : 'poarta nu mai închide o copie expirată';
    try {
        $textRoboti = \App\Support\Robots::text('https://proba.invalid');
    } catch (Throwable $e) {
        $textRoboti = '';
    }
    $locuri['robots'] = $textRoboti === "User-agent: *\nDisallow: /\n" && $robotiPe ? true : 'Robots::text sau /robots.txt nu dau „Disallow: /”';
    // Un email „trimis” prin drumul obișnuit al aplicației, cu SendGrid și un SMTP inventat în setări: pe copie trebuie
    // să ajungă la garda emailurilor (simulată: nu pleacă, nu se scrie, nu se numără).
    Verificare::simuleazaEmailuri(true);
    $eroareEmail = '';
    try {
        \App\Support\OrderMailer::sendCustom('client.de.proba@exemplu.ro', 'Proba gărzii', '<p>Proba gărzii emailurilor.</p>',
            array('email_delivery_method' => 'sendgrid', 'sendgrid_api_key' => '', 'smtp_host' => 'smtp.proba.invalid'), null);
    } catch (Throwable $e) {
        $eroareEmail = $e->getMessage();
    }
    $notate = Verificare::simuleazaEmailuri(false);
    $locuri['emailuri'] = $eroareEmail === '' && count($notate) === 1 && $notate[0]['catre'] === $proprietar
        && strpos($notate[0]['subiect'], Verificare::PREFIX_SUBIECT) === 0 && strpos($notate[0]['html'], 'SITE DE VERIFICARE') !== false
        ? true : 'un email de probă nu ajunge la garda emailurilor (OrderMailer)';
    $locuri['erp'] = stare_refuza(function () {
        (new \App\Support\ErpClient('https://erp.proba.invalid', 'cheie-de-proba'))->ping();
    }, 'ERP-ul e oprit pe site-ul de verificare') && (Verificare::erp() !== null
        || \App\Support\ErpClient::fromSettings(array('erp_url' => 'https://erp.proba.invalid', 'erp_api_key' => 'proba', 'erp_enabled' => '1')) === null)
        ? true : 'ErpClient nu refuză un ERP inventat';
    $credentialeFan = array('client_id' => 1, 'username' => 'proba', 'password' => 'proba');
    $locuri['fan'] = $fanTest || (stare_refuza(function () use ($credentialeFan) {
        \App\Support\FanCourierGateway::createInternalAwb($credentialeFan, array());
    }, 'AWB-urile sunt oprite') && stare_refuza(function () use ($credentialeFan) {
        \App\Support\FanCourierGateway::quoteInternalTariff($credentialeFan, array('clientId' => 1));
    }, 'FAN Courier e oprit')) ? true : 'FanCourierGateway nu refuză un AWB sau un tarif';
    $locuri['stripe'] = stare_refuza(function () {
        \App\Support\StripeGateway::createCheckoutSession('sk_live_proba', array());
    }, 'doar o cheie de test') ? true : 'StripeGateway nu refuză o cheie live';
    $modVechi = getenv('BT_IPAY_MODE');
    $modVechiEnv = array_key_exists('BT_IPAY_MODE', $_ENV) ? $_ENV['BT_IPAY_MODE'] : null;
    putenv('BT_IPAY_MODE=live');
    $_ENV['BT_IPAY_MODE'] = 'live';
    $modBt = \App\Support\BtIpayGateway::mod();
    $modVechi === false ? putenv('BT_IPAY_MODE') : putenv('BT_IPAY_MODE=' . $modVechi);
    if ($modVechiEnv === null) {
        unset($_ENV['BT_IPAY_MODE']);
    } else {
        $_ENV['BT_IPAY_MODE'] = $modVechiEnv;
    }
    $apelBt = \App\Support\BtIpayGateway::apel('live', 'getOrderStatusExtended', array('orderId' => 'proba'));
    $locuri['bt'] = $modBt === 'test' && strpos((string) $apelBt['eroare'], 'oprite pe site-ul de verificare') !== false
        && !\App\Support\BtIpayGateway::esteConfigurat('live') ? true : 'BtIpayGateway nu rămâne în modul de test';
    $setariEu = array('euplatesc_enabled' => '1', 'euplatesc_merchant_id' => 'proba', 'euplatesc_secret_key' => 'proba');
    $locuri['euplatesc'] = $euTest || (!\App\Support\EuPlatescGateway::isEnabled($setariEu) && stare_refuza(function () use ($setariEu) {
        \App\Support\EuPlatescGateway::buildRequest($setariEu, array('order_number' => 'proba', 'amount' => 1));
    }, 'EuPlătesc e oprit')) ? true : 'EuPlatescGateway nu e oprit';
    $locuri['etichete'] = !\App\Support\ChatLive::activ(array('tawk_enabled' => '1', 'tawk_property_id' => 'proba123', 'tawk_requires_consent' => '0'))
        && stare_cheama($radacina, 'views/layout.php', '\App\Support\CookieConsent::permiteUrmarire() && \App\Support\Verificare::eticheteTertePermise()')
        ? true : 'layout-ul sau ChatLive mai pun etichetele';
    $locuri['google'] = stare_cheama($radacina, 'src/Http/Controllers/SiteController.php', '\App\Support\Verificare::loginGooglePermis()')
        ? true : 'SiteController nu oprește login-ul cu Google';
    $locuri['uploads'] = stare_cheama($radacina, 'public/index.php', '\App\Support\Verificare::redirectUploadLipsa(')
        && stare_cheama($radacina, 'src/Support/ImagineDistribuire.php', 'Verificare::redirectUploadLipsa(')
        ? true : 'public/index.php nu trimite pozele lipsă spre VERIFICARE_UPLOADS_DIN';
    $locuri['banner'] = stare_cheama($radacina, 'views/layout.php', "\App\Support\Verificare::banner('site')")
        && stare_cheama($radacina, 'views/admin/layout.php', "\$adminLogat = \App\Support\Auth::check(); echo \App\Support\Verificare::banner(\$adminLogat ? 'admin' : 'site');")
        ? true : 'layout-urile nu au bannerul';

    $numar = 0;
    foreach ($garziCli as $cheie => $garda) {
        $numar++;
        $probleme = array();
        if (!$garda['ok']) {
            $probleme[] = 'în linia de comandă: NU';
        }
        if ($garziWeb !== null && empty($garziWeb[$cheie])) {
            $probleme[] = 'în PHP-ul site-ului: NU';
        }
        if ($garziWeb === null) {
            $probleme[] = 'PHP-ul site-ului nu s-a putut întreba (sonda)';
        }
        $loc = isset($locuri[$cheie]) ? $locuri[$cheie] : 'locul din cod nu e verificat';
        if ($loc !== true) {
            $probleme[] = (string) $loc;
        }
        stare_rand($probleme === array(), $numar . '. ' . $garda['text'] . ($probleme !== array() ? ' — ' . implode('; ', $probleme) : ''));
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
echo "Poarta, configurarea, licența de verificare, faptele găzduirii și cele 14 gărzi sunt în regulă: se poate\n";
echo "încărca o copie. Trimite-ne tot textul (nu conține parole).\n";
echo "GATA: site-ul de verificare e pregătit (toate verificările DA)\n";
exit(0);
