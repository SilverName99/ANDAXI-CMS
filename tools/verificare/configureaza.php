<?php

declare(strict_types=1);

/**
 * Configurarea site-ului de verificare, o dată, pe server, prin SSH (docs/VERIFICARE.md):
 * scrie `.env`-ul site-ului, doar pentru proprietar (0600), cu baza de date, poarta cu
 * parolă și regulile copiei, și face perechea de chei a licenței de verificare (cheia
 * privată în `storage/licenta/verificare-cheie-privata.txt`, 0600; cea publică în .env,
 * `LICENTA_CHEIE_PUBLICA`; cu `--rescrie` rămâne cea de dinainte). Nu creează și nu
 * schimbă niciun tabel din bază.
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
 * - PHP-ul e mai vechi decât `php_min` (config/app.php) sau lipsesc pdo_mysql/sodium (cu modulul pe server, mesajul dă
 *   reparația exactă: `PHP="<PHP_BINARY> -d extension=sodium.so"` și aceeași comandă cu `$PHP`; lib/php-cli.php);
 * - codul nu e exact în `~/domains/<domeniu>/public_html` (un site separat în hPanel;
 *   nu `~/public_html` și nici un subdosar din `public_html`-ul altui site);
 * - `.env` există (cu `--rescrie` doar dacă e deja al unei copii de verificare,
 *   `SITE_COPIE_VERIFICARE=1`: niciodată peste `.env`-ul unui site adevărat);
 * - baza nu se deschide cu datele scrise, sau are tabele fără să fie o copie de
 *   verificare (tabelul `_verificare`): pare baza unui site; sau e scrisă în `.env`-ul
 *   altui site din cont (`~/public_html`, `~/domains/<d>/public_html`), chiar goală;
 * - o valoare nu s-ar citi înapoi la fel din .env (o parolă care începe sau se termină cu
 *   ghilimele sau spațiu: `App\Support\Env` le taie);
 * - parolele nu se pot ascunde (terminal fără stty).
 *
 * Fișierul folosește doar sintaxa PHP 7.0, ca un PHP prea vechi să primească mesajul de
 * mai sus, nu o eroare de sintaxă. Licența (pachetul de pluginuri al copiei) o pune apoi
 * tools/verificare/licenta.php (sau încărcarea copiei, WP-09); până atunci site-ul are doar
 * nucleul (și oricum nu arată nimic fără o copie).
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
    return preg_match("/'php_min'\\s*=>\\s*'([0-9.]+)'/", $text, $m) === 1 ? $m[1] : '8.3';
}

$phpMin = verificare_php_min($radacina);
if (version_compare(PHP_VERSION, $phpMin, '<')) {
    verificare_opreste('PHP ' . PHP_VERSION . ' e mai vechi decât minimul codului (' . $phpMin . '). Rulează cu un PHP mai nou, '
        . 'de exemplu /opt/alt/php83/usr/bin/php (versiunile: ls /opt/alt | grep php).');
}
// O extensie lipsă cu modulul pe server (pe Hostinger: sodium în /opt/alt/php83) primește reparația exactă, -d extension=….
require_once $radacina . '/tools/verificare/lib/php-cli.php';
$lipsaExtensii = \Unelte\Verificare\PhpCli::lipsa(array('pdo_mysql', 'sodium'), 'tools/verificare/configureaza.php');
if ($lipsaExtensii !== null) {
    verificare_opreste($lipsaExtensii . '. Nu am scris nimic.');
}

require_once $radacina . '/src/Support/Env.php';
require_once $radacina . '/src/Support/Verificare.php';
require_once $radacina . '/tools/verificare/lib/cont.php';
// Întrebările din terminal (un rând, o parolă fără ecou, Ctrl-C care pune terminalul înapoi): comune cu incarca.php.
require_once $radacina . '/tools/verificare/lib/intrebari.php';

use App\Support\Verificare;

$rescrie = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--rescrie') {
        $rescrie = true;
        continue;
    }
    verificare_opreste('argument necunoscut: ' . $argument . ' (vezi capul fișierului)');
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
            . \Unelte\Verificare\PhpCli::comanda('tools/verificare/configureaza.php --rescrie'));
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
// Contul SSH e comun cu site-urile live: o bază pe care o folosește alt site (din .env-ul lui) nu se primește, nici goală.
$altSite = \Unelte\Verificare\Cont::bazaAltuiSite($acasa, $dbNume);
if ($altSite !== null) {
    verificare_spune('  NU    baza nu e a altui site din cont (o folosește ' . $altSite . ')');
    verificare_opreste('baza e scrisă în .env-ul altui site. Creează în hPanel → Databases o bază nouă, doar pentru verificare. Nu am scris nimic.');
}
verificare_spune('  DA    baza nu e a altui site din cont');

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

// Cel puțin 10 caractere (decizia proprietarului, 21 oct 2026), fără alte reguli (nu se cer cifre sau semne).
$poarta = verificare_intreaba('Parola porții (cel puțin 10 caractere; o scrii în browser la intrarea pe site; nu se vede): ', true);
if (mb_strlen($poarta, 'UTF-8') < 10) {
    verificare_opreste('parola porții are sub 10 caractere. Nu am scris nimic.');
}
if (hash_equals($poarta, $dbParola) || ($casutaParola !== '' && hash_equals($poarta, $casutaParola))) {
    verificare_opreste('parola porții trebuie să fie alta decât parola bazei și a căsuței. Nu am scris nimic.');
}
$poartaDinNou = verificare_intreaba('Parola porții, încă o dată: ', true);
if (!hash_equals($poarta, $poartaDinNou)) {
    verificare_opreste('cele două parole ale porții nu sunt la fel. Nu am scris nimic.');
}

// ── Cheia licenței de verificare ──
// Perechea de chei Ed25519 a licenței, făcută aici, pe server: cheia privată rămâne în storage/licenta/ (0600, niciodată
// în git), cheia publică intră în .env (LICENTA_CHEIE_PUBLICA). Cu ea semnează tools/verificare/licenta.php pachetele de
// pluginuri ale copiei; niciun site adevărat nu are această cheie publică. La --rescrie rămâne cheia de dinainte (o
// licență pusă cu ea rămâne bună).
$fisierCheie = Verificare::fisierCheieLicenta();
if (is_link($fisierCheie) || (file_exists($fisierCheie) && !is_file($fisierCheie))) {
    verificare_opreste('storage/licenta/' . Verificare::FISIER_CHEIE_LICENTA . ' nu e un fișier obișnuit; nu-l ating. Nu am scris nimic.');
}
$cheiePublica = null;
$cheiePrivata = Verificare::cheiePrivataLicenta($cheiePublica);
$cheieNoua = $cheiePrivata === null;
if ($cheieNoua) {
    $cheiePrivata = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $cheiePublica = base64_encode(sodium_crypto_sign_publickey_from_secretkey($cheiePrivata));
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
        'LICENTA_CHEIE_PUBLICA' => $cheiePublica,
        'SITE_DATE_DOSAR' => $radacinaReala . '/site-uri/bioscem',
        // Pachetul are înghețarea setărilor: migreaza.php cere site-ul (docs/MIGRARI.md, „Înghețarea setărilor”).
        'SITE_SLUG' => 'bioscem',
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

// Întâi cheia privată nouă (dacă e cazul), tot printr-un fișier temporar 0600 și rename, apoi .env. Dacă .env nu se
// poate scrie, cheia nouă se șterge la loc: nu rămâne nimic scris.
if ($cheieNoua) {
    \App\Support\Paths::stocareSite('licenta');
    $temporarCheie = dirname($fisierCheie) . '/.' . Verificare::FISIER_CHEIE_LICENTA . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $continutCheie = base64_encode($cheiePrivata) . "\n";
    $umaskVechi = umask(0077);
    $scris = @file_put_contents($temporarCheie, $continutCheie, LOCK_EX);
    umask($umaskVechi);
    if ($scris !== strlen($continutCheie) || !@chmod($temporarCheie, 0600) || !@rename($temporarCheie, $fisierCheie)) {
        @unlink($temporarCheie);
        verificare_opreste('nu pot scrie cheia licenței în storage/licenta. Nu am scris nimic.');
    }
}

// Lângă .env (în același dosar, ca rename să fie atomic): azi rădăcina codului (Paths::env()).
$temporar = dirname($caleEnv) . '/.env.' . bin2hex(random_bytes(6)) . '.tmp';
$umaskVechi = umask(0077);
$scris = @file_put_contents($temporar, $text, LOCK_EX);
umask($umaskVechi);
if ($scris !== strlen($text) || !@chmod($temporar, 0600) || !@rename($temporar, $caleEnv)) {
    @unlink($temporar);
    if ($cheieNoua) {
        @unlink($fisierCheie);
    }
    verificare_opreste('nu pot scrie .env în dosarul codului. Nu am scris nimic.');
}
clearstatcache();
$doarTu = (fileperms($caleEnv) & 0777) === 0600;
verificare_spune(($doarTu ? '  DA' : '  NU') . '    .env scris, doar pentru tine (0600)');
$cheieVerificata = null;
$cheieBuna = Verificare::cheiePrivataLicenta($cheieVerificata) !== null && $cheieVerificata === $cheiePublica
    && (fileperms($fisierCheie) & 0777) === 0600;
verificare_spune(($cheieBuna ? '  DA' : '  NU') . '    cheia licenței de verificare ' . ($cheieNoua ? 'făcută acum' : 'de dinainte, păstrată')
    . ' (cheia privată doar pe server, storage/licenta, 0600)');
$dosarBun = Verificare::pregatesteDosar();
verificare_spune(($dosarBun ? '  DA' : '  NU') . '    dosarul de lucru storage/verificare');
verificare_spune('  INFO  adresa site-ului: https://' . $gazda . '; utilizatorul porții: ' . Verificare::UTILIZATOR_IMPLICIT);
verificare_spune('  INFO  emailurile: ' . ($casuta !== '' ? 'prin căsuța ta, doar spre adresa ta' : 'doar fișiere pe server (fără căsuță)'));
verificare_spune('');
if (!$doarTu || !$dosarBun || !$cheieBuna) {
    verificare_opreste('.env e scris, dar ceva de mai sus a ieșit NU. Trimite-ne textul acesta (nu conține parole).');
}
verificare_spune('Gata. Pasul următor: ' . \Unelte\Verificare\PhpCli::comanda('tools/verificare/stare.php'));
