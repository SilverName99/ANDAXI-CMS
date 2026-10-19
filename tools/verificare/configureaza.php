<?php

declare(strict_types=1);

/**
 * Configurarea site-ului de verificare, o dată, pe server, prin SSH (docs/VERIFICARE.md):
 * scrie `.env`-ul site-ului, doar pentru proprietar (0600), cu baza de date, poarta cu
 * parolă și regulile copiei. Nu creează și nu schimbă niciun tabel din bază.
 *
 *   $PHP tools/verificare/configureaza.php            # prima dată
 *   $PHP tools/verificare/configureaza.php --rescrie  # din nou (altă parolă a porții, altă bază)
 *
 * Întreabă: numele bazei, utilizatorul ei, parola ei, emailul proprietarului, căsuța de
 * trimitere și parola ei (opțional), parola porții (de două ori). Parolele nu se văd când
 * le scrii (stty); pe ecran apar doar DA/NU, niciodată o parolă sau o valoare din .env.
 * Ctrl-C la o parolă pune terminalul înapoi (cu ecou) și oprește scriptul fără să scrie nimic.
 *
 * Refuză (cod 1, fără să scrie nimic) dacă:
 * - PHP-ul e mai vechi decât `php_min` (config/app.php) sau lipsesc pdo_mysql/sodium;
 * - codul nu e exact în `~/domains/<domeniu>/public_html` (un site separat în hPanel;
 *   nu `~/public_html` și nici un subdosar din `public_html`-ul altui site);
 * - `.env` există (cu `--rescrie` doar dacă e deja al unei copii de verificare,
 *   `SITE_COPIE_VERIFICARE=1`: niciodată peste `.env`-ul unui site adevărat);
 * - baza nu se deschide cu datele scrise, sau are tabele fără să fie o copie de
 *   verificare (tabelul `_verificare`): pare baza unui site;
 * - o valoare nu s-ar citi înapoi la fel din .env (o parolă care începe sau se termină cu
 *   ghilimele sau spațiu: `App\Support\Env` le taie);
 * - parolele nu se pot ascunde (terminal fără stty).
 *
 * Fișierul folosește doar sintaxa PHP 7.0, ca un PHP prea vechi să primească mesajul de
 * mai sus, nu o eroare de sintaxă. Cheia licenței de verificare o adaugă configurarea din
 * WP-08; până atunci site-ul are doar nucleul (și oricum nu arată nimic fără o copie).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);

function verificare_opreste($mesaj)
{
    fwrite(STDERR, 'EROARE: ' . $mesaj . "\n");
    exit(1);
}

function verificare_spune($text)
{
    fwrite(STDOUT, $text . "\n");
}

/** php_min din config/app.php, citit ca text (fără să încarce codul aplicației). */
function verificare_php_min($radacina)
{
    $text = (string) @file_get_contents($radacina . '/config/app.php');
    return preg_match("/'php_min'\\s*=>\\s*'([0-9.]+)'/", $text, $m) === 1 ? $m[1] : '8.0';
}

$phpMin = verificare_php_min($radacina);
if (version_compare(PHP_VERSION, $phpMin, '<')) {
    verificare_opreste('PHP ' . PHP_VERSION . ' e mai vechi decât minimul codului (' . $phpMin . '). Rulează cu un PHP mai nou, '
        . 'de exemplu /opt/alt/php83/usr/bin/php (versiunile: ls /opt/alt | grep php).');
}
foreach (array('pdo_mysql', 'sodium') as $extensie) {
    if (!extension_loaded($extensie)) {
        verificare_opreste('lipsește extensia PHP ' . $extensie . ' (hPanel → PHP Configuration → PHP extensions).');
    }
}

require_once $radacina . '/src/Support/Env.php';
require_once $radacina . '/src/Support/Verificare.php';

use App\Support\Verificare;

$rescrie = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--rescrie') {
        $rescrie = true;
        continue;
    }
    verificare_opreste('argument necunoscut: ' . $argument . ' (vezi capul fișierului)');
}

/** Rulează o comandă de shell cu ce funcție e pornită pe server; null dacă niciuna. */
function verificare_comanda($comanda)
{
    $oprite = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (function_exists('shell_exec') && !in_array('shell_exec', $oprite, true)) {
        return (string) shell_exec($comanda);
    }
    if (function_exists('exec') && !in_array('exec', $oprite, true)) {
        $linii = array();
        exec($comanda, $linii);
        return implode("\n", $linii);
    }
    return null;
}

function verificare_e_terminal()
{
    if (function_exists('stream_isatty')) {
        return @stream_isatty(STDIN);
    }
    if (function_exists('posix_isatty')) {
        return @posix_isatty(STDIN);
    }
    return false;
}

// Starea terminalului dinaintea unei parole (`stty -g`), cât timp parola se scrie.
$GLOBALS['verificare_stty'] = '';

/** Terminalul înapoi cum era înaintea parolei (dacă e cazul). */
function verificare_reda_terminalul()
{
    if ($GLOBALS['verificare_stty'] !== '') {
        verificare_comanda('stty ' . escapeshellarg($GLOBALS['verificare_stty']) . ' 2>/dev/null');
        $GLOBALS['verificare_stty'] = '';
    }
}
// Și dacă scriptul se oprește altfel în mijlocul unei parole (o eroare, un exit).
register_shutdown_function('verificare_reda_terminalul');

function verificare_semnal($semnal)
{
    verificare_reda_terminalul();
    fwrite(STDOUT, "\n");
    exit(128 + (int) $semnal);
}

/**
 * Cât se scrie o parolă: SIGTERM, SIGHUP (de exemplu găzduirea care oprește un proces lung)
 * și un SIGINT trimis din altă parte pun terminalul înapoi înainte de oprire (dacă există
 * pcntl). În rest semnalele rămân cum sunt: Ctrl-C la o întrebare obișnuită oprește scriptul
 * pe loc, ecoul fiind oricum pornit acolo.
 */
function verificare_prinde_semnale($da)
{
    if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals') || !defined('SIGTERM')) {
        return;
    }
    pcntl_async_signals(true);
    foreach (array(SIGTERM, SIGHUP, SIGINT) as $semnal) {
        pcntl_signal($semnal, $da ? 'verificare_semnal' : SIG_DFL, false);
    }
}

function verificare_nu_pot_ascunde()
{
    fwrite(STDOUT, "\n");
    verificare_opreste('nu pot ascunde ce scrii (comanda stty nu merge pe acest server), deci nu cer parola. Trimite-ne textul acesta.');
}

/**
 * O parolă scrisă în terminal, fără ecou. Cât se scrie, terminalul dă scriptului fiecare tastă
 * (`-icanon`), iar Ctrl-C nu mai e un semnal (`-isig`): scriptul îl citește ca pe o tastă, pune
 * singur terminalul înapoi și abia apoi se oprește (cod 130). Oprit de semnal în mijlocul
 * parolei, PHP-ul ar lăsa terminalul fără ecou: nimic din ce scrie proprietarul după aceea nu
 * s-ar mai vedea. Backspace șterge ultima literă, Ctrl-U tot ce s-a scris, Enter încheie.
 *
 * @return string|false false dacă intrarea s-a încheiat (Ctrl-D la început)
 */
function verificare_citeste_ascuns()
{
    $stare = trim((string) verificare_comanda('stty -g 2>/dev/null'));
    if ($stare === '') {
        verificare_nu_pot_ascunde();
    }
    $GLOBALS['verificare_stty'] = $stare;
    verificare_prinde_semnale(true);
    if (trim((string) verificare_comanda('stty -echo -icanon -isig min 1 time 0 2>/dev/null && echo DA')) !== 'DA') {
        verificare_reda_terminalul();
        verificare_prinde_semnale(false);
        verificare_nu_pot_ascunde();
    }
    $text = '';
    $ctrlC = false;
    $sfarsit = false;
    $erori = 0;
    while (true) {
        $citire = array(STDIN);
        $scriere = null;
        $exceptii = null;
        // Așteptări de o secundă, ca un semnal prins mai sus să fie tratat pe loc.
        $gata = @stream_select($citire, $scriere, $exceptii, 1);
        if ($gata === false && ++$erori > 30) {
            $sfarsit = true;
            break;
        }
        if (!$gata) {
            continue;
        }
        $c = fread(STDIN, 1);
        if ($c === false || $c === '') {
            $sfarsit = true;
            break;
        }
        if ($c === "\n" || $c === "\r") {
            break;
        }
        if ($c === "\x03") {
            $ctrlC = true;
            break;
        }
        if ($c === "\x04") {
            if ($text === '') {
                $sfarsit = true;
                break;
            }
            continue;
        }
        if ($c === "\x7f" || $c === "\x08") {
            // Ultima literă, cu toți octeții ei (UTF-8).
            $i = strlen($text) - 1;
            while ($i > 0 && (ord($text[$i]) & 0xC0) === 0x80) {
                $i--;
            }
            $text = $i > 0 ? substr($text, 0, $i) : '';
            continue;
        }
        if ($c === "\x15") {
            $text = '';
            continue;
        }
        // Alt caracter de control (o săgeată, Tab) rămâne în text: după Enter, verificare_intreaba
        // oprește scriptul, ca la un rând obișnuit.
        $text .= $c;
    }
    verificare_reda_terminalul();
    verificare_prinde_semnale(false);
    if ($ctrlC) {
        fwrite(STDOUT, "\n");
        fwrite(STDERR, "Oprit (Ctrl-C). Nu am scris nimic.\n");
        exit(130);
    }
    return $sfarsit ? false : $text;
}

/** O întrebare; cu $ascuns, ce scrii nu apare pe ecran. Fără intrare: oprire. */
function verificare_intreaba($intrebare, $ascuns)
{
    fwrite(STDOUT, $intrebare);
    fflush(STDOUT);
    if ($ascuns && verificare_e_terminal()) {
        $rand = verificare_citeste_ascuns();
        fwrite(STDOUT, "\n");
    } else {
        $rand = fgets(STDIN);
    }
    if ($rand === false) {
        verificare_opreste('intrarea s-a încheiat înainte de răspuns; nu am scris nimic.');
    }
    $rand = rtrim($rand, "\r\n");
    if (preg_match('/[\x00-\x1f\x7f]/', $rand) === 1) {
        verificare_opreste('răspunsul are caractere de control; nu am scris nimic.');
    }
    return $rand;
}

/** O cale arătată cu „~” în locul dosarului de acasă (fără alte date). */
function verificare_scurt($cale, $acasa)
{
    $real = realpath($acasa);
    if ($real !== false && $real !== '/' && strpos($cale, $real . '/') === 0) {
        return '~' . substr($cale, strlen($real));
    }
    return $cale;
}

// ── Unde suntem ──

$acasa = (string) getenv('HOME');
if ($acasa === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
    $utilizatorSistem = posix_getpwuid(posix_geteuid());
    $acasa = is_array($utilizatorSistem) ? (string) $utilizatorSistem['dir'] : '';
}
$radacinaReala = (string) realpath($radacina);
$gazda = $acasa === '' ? null : Verificare::dosarSite($radacinaReala, $acasa);
if ($gazda === null) {
    verificare_opreste('codul nu e în ~/domains/<domeniu>/public_html (e în ' . verificare_scurt($radacinaReala, $acasa) . '). '
        . 'Site-ul de verificare trebuie să fie un site separat în hPanel (Websites → Add website), cu dosarul lui; '
        . 'nu ~/public_html și nici un subdosar din public_html-ul altui site. Nu am scris nimic.');
}

$caleEnv = Verificare::caleEnv();
if (file_exists($caleEnv) || is_link($caleEnv)) {
    if (!$rescrie) {
        verificare_opreste('.env există deja: site-ul e configurat. Ca să-l configurezi din nou (altă parolă a porții, altă bază): '
            . PHP_BINARY . ' tools/verificare/configureaza.php --rescrie');
    }
    $vechi = Verificare::citesteEnv((string) @file_get_contents($caleEnv));
    if (is_link($caleEnv) || trim((string) (isset($vechi['SITE_COPIE_VERIFICARE']) ? $vechi['SITE_COPIE_VERIFICARE'] : '')) !== '1') {
        verificare_opreste('.env-ul de aici nu e al unei copii de verificare (nu are SITE_COPIE_VERIFICARE=1), deci nu îl rescriu: '
            . 'pare .env-ul unui site adevărat. Verifică dosarul în care ești.');
    }
}

verificare_spune('Configurarea site-ului de verificare https://' . $gazda);
verificare_spune('Parolele nu se văd când le scrii. Pe ecran apar doar DA/NU, nicio valoare.');
verificare_spune('');

// ── Baza de date ──

$dbNume = verificare_intreaba('Numele bazei de date (hPanel → Databases, de forma u123456789_nume): ', false);
$dbUtilizator = verificare_intreaba('Utilizatorul bazei: ', false);
foreach (array($dbNume, $dbUtilizator) as $nume) {
    if (preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $nume) !== 1) {
        verificare_opreste('numele bazei și al utilizatorului au doar litere, cifre și _ (cum le arată hPanel). Nu am scris nimic.');
    }
}
$dbParola = verificare_intreaba('Parola bazei (nu se vede când scrii): ', true);

$motiveDb = array(
    1044 => 'utilizatorul nu are drept pe această bază (hPanel → Databases: leagă utilizatorul de bază)',
    1045 => 'acces refuzat: utilizatorul sau parola nu sunt bune',
    1049 => 'baza nu există (verifică numele în hPanel → Databases)',
    2002 => 'serverul bazei nu răspunde',
    2006 => 'serverul bazei a închis legătura',
);
try {
    $pdo = new PDO('mysql:host=localhost;port=3306;dbname=' . $dbNume . ';charset=utf8mb4', $dbUtilizator, $dbParola, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ));
} catch (PDOException $e) {
    // Mesajul lui PDO conține utilizatorul; arătăm doar ce înseamnă codul.
    $codDb = preg_match('/\[(\d{4})\]/', $e->getMessage(), $m) === 1 ? (int) $m[1] : (int) $e->getCode();
    verificare_spune('  NU    legătura cu baza: ' . (isset($motiveDb[$codDb]) ? $motiveDb[$codDb] : 'eroarea ' . $codDb));
    verificare_opreste('baza nu se deschide; nu am scris nimic.');
}
verificare_spune('  DA    legătura cu baza');
$tabele = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
$copieVeche = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '_verificare'")->fetchColumn() > 0;
$pdo = null;
if ($tabele > 0 && !$copieVeche) {
    verificare_spune('  NU    baza e goală: are ' . $tabele . ' tabele și nu e o copie de verificare (lipsește tabelul _verificare)');
    verificare_opreste('pare baza unui site. Creează în hPanel → Databases o bază nouă, goală, doar pentru verificare. Nu am scris nimic.');
}
verificare_spune($tabele === 0 ? '  DA    baza e goală' : '  DA    baza are o copie de verificare de dinainte (tabelul _verificare)');

// ── Emailuri ──

$email = verificare_intreaba('Emailul tău (aici ajung emailurile site-ului de verificare): ', false);
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    verificare_opreste('adresa de email nu e validă. Nu am scris nimic.');
}
$casuta = verificare_intreaba('Căsuța din care pleacă emailurile (de exemplu verificare@' . $gazda
    . '; Enter = fără: emailurile rămân fișiere pe server): ', false);
$casutaParola = '';
if ($casuta !== '') {
    if (filter_var($casuta, FILTER_VALIDATE_EMAIL) === false) {
        verificare_opreste('adresa căsuței nu e validă. Nu am scris nimic.');
    }
    $casutaParola = verificare_intreaba('Parola căsuței (nu se vede când scrii): ', true);
    if ($casutaParola === '') {
        verificare_opreste('căsuța are nevoie de parola ei. Nu am scris nimic.');
    }
}

// ── Poarta ──

$poarta = verificare_intreaba('Parola porții (cel puțin 12 caractere; o scrii în browser la intrarea pe site; nu se vede): ', true);
if (strlen($poarta) < 12) {
    verificare_opreste('parola porții are sub 12 caractere. Nu am scris nimic.');
}
if (hash_equals($poarta, $dbParola) || ($casutaParola !== '' && hash_equals($poarta, $casutaParola))) {
    verificare_opreste('parola porții trebuie să fie alta decât parola bazei și a căsuței. Nu am scris nimic.');
}
$poartaDinNou = verificare_intreaba('Parola porții, încă o dată: ', true);
if (!hash_equals($poarta, $poartaDinNou)) {
    verificare_opreste('cele două parole ale porții nu sunt la fel. Nu am scris nimic.');
}

// ── .env ──

$sectiuni = array(
    'Site-ul' => array(
        'APP_NAME' => 'VERIFICARE',
        'APP_ENV' => 'verificare',
        'APP_DEBUG' => 'false',
        'APP_URL' => 'https://' . $gazda,
        'APP_TIMEZONE' => 'Europe/Bucharest',
    ),
    'Baza de date (goală până la prima încărcare)' => array(
        'DB_HOST' => 'localhost',
        'DB_PORT' => '3306',
        'DB_DATABASE' => $dbNume,
        'DB_USERNAME' => $dbUtilizator,
        'DB_PASSWORD' => $dbParola,
    ),
    'Copia de verificare și poarta (docs/VERIFICARE.md)' => array(
        'SITE_COPIE_VERIFICARE' => '1',
        'VERIFICARE_SURSA' => 'bioscem',
        'VERIFICARE_ZILE_PASTRARE' => (string) Verificare::ZILE_MAXIM,
        'VERIFICARE_POARTA_UTILIZATOR' => Verificare::UTILIZATOR_IMPLICIT,
        'VERIFICARE_POARTA_HASH' => password_hash($poarta, PASSWORD_DEFAULT),
        'VERIFICARE_POARTA_MOD' => 'basic',
        'IP_PROXY_DE_INCREDERE' => '',
        'VERIFICARE_UPLOADS_DIN' => 'https://bioscem.ro',
        'VERIFICARE_WEBHOOKURI_DESCHISE' => '0',
    ),
    'Emailurile copiei: doar spre proprietar' => array(
        'VERIFICARE_EMAIL' => $email,
        'VERIFICARE_SMTP_HOST' => $casuta !== '' ? 'smtp.hostinger.com' : '',
        'VERIFICARE_SMTP_PORT' => '465',
        'VERIFICARE_SMTP_UTILIZATOR' => $casuta,
        'VERIFICARE_SMTP_PAROLA' => $casutaParola,
        'VERIFICARE_SMTP_CRIPTARE' => 'ssl',
    ),
    'Plăți: doar modul de test, fără date live' => array(
        'BT_IPAY_MODE' => 'test',
        'BT_IPAY_TEST_USER' => '',
        'BT_IPAY_TEST_PASS' => '',
        'BT_IPAY_TEST_CALLBACK_KEY' => '',
        'BT_IPAY_LIVE_USER' => '',
        'BT_IPAY_LIVE_PASS' => '',
        'BT_IPAY_LIVE_CALLBACK_KEY' => '',
    ),
    'Licența și datele site-ului' => array(
        'LICENTA_SITE' => 'verificare',
        'LICENTA_CHEIE_PUBLICA' => '',
        'SITE_DATE_DOSAR' => $radacinaReala . '/site-uri/bioscem',
        'ADMIN_DEFAULT_PASSWORD' => '',
    ),
);
$text = '# Site-ul de verificare, scris de tools/verificare/configureaza.php la ' . date('Y-m-d H:i') . ".\n"
    . "# Doar pentru tine (0600). Nu-l copia nicăieri și nu-l trimite nimănui.\n";
$valori = array();
foreach ($sectiuni as $titlu => $chei) {
    $text .= "\n# " . $titlu . "\n";
    foreach ($chei as $cheie => $valoare) {
        $text .= $cheie . '=' . $valoare . "\n";
        $valori[$cheie] = $valoare;
    }
}
// Fiecare valoare trebuie să se citească înapoi exact (Env::load taie spațiile și ghilimelele de la capete).
$citite = Verificare::citesteEnv($text);
foreach ($valori as $cheie => $valoare) {
    if (!isset($citite[$cheie]) || $citite[$cheie] !== $valoare) {
        verificare_opreste('valoarea pentru ' . $cheie . ' nu s-ar citi la fel din .env (începe sau se termină cu ghilimele sau spațiu). '
            . 'Schimb-o (de exemplu parola, în hPanel) și rulează din nou. Nu am scris nimic.');
    }
}

$temporar = $radacina . '/.env.' . bin2hex(random_bytes(6)) . '.tmp';
$umaskVechi = umask(0077);
$scris = @file_put_contents($temporar, $text, LOCK_EX);
umask($umaskVechi);
if ($scris !== strlen($text) || !@chmod($temporar, 0600) || !@rename($temporar, $caleEnv)) {
    @unlink($temporar);
    verificare_opreste('nu pot scrie .env în dosarul codului. Nu am scris nimic.');
}
clearstatcache();
$doarTu = (fileperms($caleEnv) & 0777) === 0600;
verificare_spune(($doarTu ? '  DA' : '  NU') . '    .env scris, doar pentru tine (0600)');
$dosarBun = Verificare::pregatesteDosar();
verificare_spune(($dosarBun ? '  DA' : '  NU') . '    dosarul de lucru storage/verificare');
verificare_spune('  INFO  adresa site-ului: https://' . $gazda . '; utilizatorul porții: ' . Verificare::UTILIZATOR_IMPLICIT);
verificare_spune('  INFO  emailurile: ' . ($casuta !== '' ? 'prin căsuța ta, doar spre adresa ta' : 'doar fișiere pe server (fără căsuță)'));
verificare_spune('');
if (!$doarTu || !$dosarBun) {
    verificare_opreste('.env e scris, dar ceva de mai sus a ieșit NU. Trimite-ne textul acesta (nu conține parole).');
}
verificare_spune('Gata. Pasul următor: ' . PHP_BINARY . ' tools/verificare/stare.php');
