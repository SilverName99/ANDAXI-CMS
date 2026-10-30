<?php

declare(strict_types=1);

/**
 * Testele reparațiilor de securitate ale nucleului (planul Etapei 1, WP-12b: G-M5, N15, G-m2), cu serverul de
 * dezvoltare pe baza cu datele demo și cu un al doilea server pe portul CMS_DEV_PORT + 20, pentru care 127.0.0.1 e un
 * proxy de încredere (`IP_PROXY_DE_INCREDERE=127.0.0.1`). Fiecare verificare încearcă să strice reparația pe care o
 * păzește: un atacator care știe parola salvată, unul care și-a fixat id-ul de sesiune, unul care scrie singur
 * `X-Forwarded-For`.
 *
 *   tools/dev/php.sh tools/dev/test-securitate.php
 *
 * A. Fără server: regula parolelor salvate (`App\Support\ParolaAdmin::fel`) pe fiecare fel de valoare (și pe cele la
 *    care s-ar putea greși: bcrypt de altă lungime, argon2, argon2 fără `$` în față, alte hash-uri ca `$6$`, `$1$`,
 *    `$2x$`, `$P$`, `$wp$`, un spațiu, texte cu `$`); orice algoritm al PHP-ului de acum dă un hash pe care regula nu-l
 *    ia drept text clar; refacerea după logare (`ParolaAdmin::deRefacut`: nu coboară costul); `Auth::attempt` refuză o
 *    parolă goală salvată (și cu parola goală) și nu atinge rândul la o parolă greșită; id-ul nou de sesiune nu face
 *    nimic în linia de comandă, iar pe fișiere de sesiune adevărate (un PHP separat) lasă în fișierul id-ului de
 *    dinainte coșul, fără nicio logare, însemnat ca înlocuit, și dă sesiunii noi logările și cheia coșului abandonat;
 *    o adresă IPv6 în `REMOTE_ADDR` (cu un `X-Forwarded-For` inventat) și un `X-Forwarded-For` IPv6 fără paranteze, care
 *    se termină în cifre, de la un proxy de încredere (predarea primei rulări pe Hostinger); condiția SQL a limitelor
 *    (`IpClient::conditieLimita`: IPv6 pe /64, IPv4 exact) numără la fel pe MariaDB și pe MySQL 8 (pornit și oprit aici
 *    dacă nu mergea), și pe valori scrise de codul vechi.
 *    Căutarea în cod: adresa vizitatorului (`REMOTE_ADDR`, `X-Forwarded-For`, `X-Real-IP`, `CF-Connecting-IP`,
 *    `Client-IP`, `Forwarded`) se citește doar în `IpClient` și în sonda copiei de verificare; cine s-a logat se scrie
 *    în sesiune doar în `Auth::attempt` și `CustomerAuth::login`, fiecare după id-ul nou. Căutarea pică pe un text
 *    pus anume (martorul), deci nu trece în gol.
 * B. Parolele adminilor (G-M5), cu serverul: o parolă veche necriptată (o parolă greșită nu schimbă nimic; cea bună
 *    intră, rândul devine bcrypt `$2y$`, un singur rând „parola_convertita” în Jurnal activitate, fără parolă în el și
 *    cu adresa reală, nu cu cea scrisă de vizitator în antet; a doua logare merge și nu mai scrie nimic; hash-ul nou
 *    scris ca parolă nu intră); argon2id, bcrypt `$2a$` și `$2b$` (hash-ul însuși, scris ca parolă, nu intră; parola
 *    da; rândul devine `$2y$`, fără rând în jurnal); un hash stricat și alte hash-uri (SHA-512 crypt, WordPress
 *    phpass: nici hash-ul scris ca parolă, nici parola nu intră; rândul neatins); bcrypt cu un cost mic (refăcut cu
 *    costul de acum), bcrypt-ul de acum și unul cu un cost mai mare (neatinse: costul nu coboară).
 * C. Id-ul sesiunii (N15): la logarea adminului, a clientului (parola) și la contul nou, cookie-ul primește alt id; cu
 *    id-ul de dinainte (ca un atacator care l-a fixat) nu e nimeni logat, dar coșul de dinainte e acolo; cu id-ul nou
 *    coșul a trecut și logarea e acolo; un id inventat nu e primit (strict mode); adminul logat peste un client logat
 *    păstrează și clientul, și coșul, iar clientul peste un admin la fel; în ambele cazuri id-ul de dinainte (un cookie
 *    copiat) nu mai are nicio logare, deci după ieșirea din cont nu mai deschide nici /admin, nici Contul meu. Coșul
 *    abandonat: musafirul care își scrie emailul, se loghează și pleacă are un singur rând (cheia de dinainte de logare)
 *    și primește un singur email de la cron (`scripts/abandoned-cart-emails.php`); cel care comandă după logare nu
 *    primește niciunul, nici pe emailul scris înainte; id-ul înlocuit nu mai scrie nimic. Jurnalele limitelor se golesc
 *    la început (încercările altor teste din ultimele 15 minute ar opri înregistrarea).
 * D. Adresa vizitatorului (G-m2): pe serverul obișnuit, un `X-Forwarded-For` (și `X-Real-IP`, `CF-Connecting-IP`)
 *    schimbat la fiecare cerere nu ocolește limita checkout-ului (10 în 15 minute) și nici pe a înregistrării (8),
 *    rândurile au adresa reală; pe serverul din spatele proxy-ului de încredere: adresa e prima din dreapta care nu e
 *    a proxy-ului (ce scrie vizitatorul în stânga nu contează), limita e pe vizitator (altul nu e oprit), o adresă
 *    IPv6 numără cu toată rețeaua ei /64 (alta, din alt /64, nu e oprită) și rândurile păstrează adresa întreagă;
 *    jurnalul adminului, mesajul de contact și IP-urile cu acces în mentenanță au aceeași adresă (în mentenanță, un
 *    antet scris de vizitator nu deschide site-ul; o adresă din listă scrisă altfel, de exemplu `::ffff:127.0.0.1` sau
 *    cu litere mari, e aceeași adresă; o rețea nu se potrivește).
 *
 * Toată baza (fiecare tabel) e pusă la loc la final, verificat cu CHECKSUM TABLE; la fel sesiunile și emailurile
 * capturate făcute aici. Serverul de dezvoltare se oprește la final dacă l-a pornit testul, al doilea server mereu.
 * Raportul: $CMS_DEV_DIR/test-securitate-raport.txt; cod de ieșire 0 doar dacă totul a trecut. Date inventate.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';
require_once __DIR__ . '/lib/salvari-admin.php';

use App\Support\Auth;
use App\Support\Database;
use App\Support\ParolaAdmin;
use App\Support\Paths;
use App\Support\Sesiune;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-securitate-raport.txt';
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
$scrie('Test securitate WP-12b ANDAXI-CMS — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

// =====================================================================
// A. Fără server
// =====================================================================
$scrie('');
$scrie('== A. Fără server: regula parolelor salvate, Auth::attempt în proces, căutarea în cod');

$bcrypt = password_hash('Parola-Demo-1', PASSWORD_BCRYPT);
$cazuriFel = [
    'bcrypt $2y$ de acum' => [$bcrypt, ParolaAdmin::HASH],
    'bcrypt $2y$ cu costul 4' => [password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]), ParolaAdmin::HASH],
    'argon2i' => [password_hash('x', PASSWORD_ARGON2I), ParolaAdmin::HASH],
    'argon2id' => [password_hash('x', PASSWORD_ARGON2ID), ParolaAdmin::HASH],
    'bcrypt $2a$' => ['$2a$' . substr($bcrypt, 4), ParolaAdmin::BCRYPT_VECHI],
    'bcrypt $2b$' => ['$2b$' . substr($bcrypt, 4), ParolaAdmin::BCRYPT_VECHI],
    '$2y$ scurt' => ['$2y$10$scurt', ParolaAdmin::HASH_STRICAT],
    '$2y$ cu un caracter în plus' => [$bcrypt . 'x', ParolaAdmin::HASH_STRICAT],
    '$2y$ de 60 de caractere, dar 61 de octeți' => [substr($bcrypt, 0, 59) . 'ă', ParolaAdmin::HASH_STRICAT],
    '$2a$ de altă lungime' => ['$2a$10$scurt', ParolaAdmin::HASH_STRICAT],
    // password_get_info se uită doar la începutul unui argon2: e un hash (password_verify nu-l potrivește cu nimic).
    '$argon2id$ cu restul stricat' => ['$argon2id$nimic', ParolaAdmin::HASH],
    // … și nici la primul lui caracter („xargon2id$…” îi pare argon2id): fără „$” în față e un text, comparat în clar
    // (și numărat ca text clar în SQL-ul WP-10), nu un hash la care nu intră nimeni.
    'xargon2id$… (argon2id fără „$” în față)' => ['xargon2id$v=19$m=65536,t=4,p=1$Parola', ParolaAdmin::TEXT_CLAR],
    'Xargon2i$… (argon2i fără „$” în față)' => ['Xargon2i$v=19$m=1', ParolaAdmin::TEXT_CLAR],
    'X2y$… de 60 de octeți (bcrypt fără „$” în față)' => ['X' . substr($bcrypt, 1), ParolaAdmin::TEXT_CLAR],
    // Alte hash-uri (password_verify știe unele, dar logarea adminului nu le verifică): niciodată în clar.
    '$2x$ (bcrypt-ul cu defect)' => ['$2x$' . substr($bcrypt, 4), ParolaAdmin::HASH_STRICAT],
    'SHA-512 crypt ($6$)' => [crypt('x', '$6$rounds=5000$saredemo$'), ParolaAdmin::HASH_STRICAT],
    'MD5 crypt ($1$)' => [crypt('x', '$1$saredemo$'), ParolaAdmin::HASH_STRICAT],
    'WordPress phpass ($P$)' => ['$P$B' . str_repeat('a', 30), ParolaAdmin::HASH_STRICAT],
    'WordPress 6.8 ($wp$2y$)' => ['$wp' . $bcrypt, ParolaAdmin::HASH_STRICAT],
    '$argon2d$ (PHP nu-l are)' => ['$argon2d$v=19$m=65536,t=4,p=1$sare$hash', ParolaAdmin::HASH_STRICAT],
    '$ARGON2ID$ (litere mari)' => ['$ARGON2ID$v=19$x', ParolaAdmin::HASH_STRICAT],
    '$2Y$ (litere mari)' => ['$2Y$' . substr($bcrypt, 4), ParolaAdmin::HASH_STRICAT],
    'gol' => ['', ParolaAdmin::GOL],
    'un spațiu' => [' ', ParolaAdmin::TEXT_CLAR],
    'text' => ['Parola-Veche-Demo', ParolaAdmin::TEXT_CLAR],
    'bcrypt cu spațiu în față' => [' ' . $bcrypt, ParolaAdmin::TEXT_CLAR],
    'text cu $ la început, fără al doilea $' => ['$ecret-Demo-1', ParolaAdmin::TEXT_CLAR],
    'text „$$…” (fără nume de algoritm)' => ['$$dublu', ParolaAdmin::TEXT_CLAR],
    'text „$ă$…” (nume care nu e ASCII)' => ['$ă$x', ParolaAdmin::TEXT_CLAR],
    'MD5 hex (nu se poate deosebi de o parolă)' => [md5('x'), ParolaAdmin::TEXT_CLAR],
];
$gresite = [];
foreach ($cazuriFel as $nume => [$valoare, $asteptat]) {
    if (ParolaAdmin::fel($valoare) !== $asteptat) {
        $gresite[] = $nume . ' → ' . ParolaAdmin::fel($valoare) . ' (așteptat ' . $asteptat . ')';
    }
}
$verifica('ParolaAdmin::fel pe ' . count($cazuriFel) . ' valori (fiecare fel, lungimea în octeți, argon2, $2a$/$2b$, alte hash-uri: $2x$, $6$, $1$, $P$, $wp$, un spațiu, texte cu $)',
    $gresite === [], implode('; ', $gresite));
$algoritmi = [];
foreach (password_algos() as $algoritm) {
    $h = password_hash('x', $algoritm);
    if (ParolaAdmin::fel($h) !== ParolaAdmin::HASH || !password_verify('x', $h)) {
        $algoritmi[] = (string) $algoritm;
    }
}
$verifica('fiecare algoritm al PHP-ului de acum (' . implode(', ', password_algos()) . ') dă un „hash”, deci un algoritm implicit nou nu ajunge la comparația în clar',
    $algoritmi === [], implode(', ', $algoritmi));
$verifica('fiecare hash recunoscut de password_get_info are forma TIPAR_HASH și începe cu PREFIX_BCRYPT sau unul dintre PREFIXE_ARGON (SQL-ul numărătorii WP-10 se bazează pe asta)',
    array_filter(array_map(static fn (string $a): string => password_hash('x', $a), password_algos()),
        static function (string $h): bool {
            if (preg_match('/' . ParolaAdmin::TIPAR_HASH . '/', $h) !== 1) {
                return true;
            }
            foreach (array_merge([ParolaAdmin::PREFIX_BCRYPT], ParolaAdmin::PREFIXE_ARGON) as $prefix) {
                if (str_starts_with($h, $prefix)) {
                    return false;
                }
            }
            return true;
        }) === []);
// Refacerea după o logare reușită (ParolaAdmin::deRefacut): bcrypt-ul vechi, argon2 și un cost mic da; costul de acum
// sau unul mai mare nu (nu coboară costul, de exemplu un bcrypt făcut cu PHP 8.4, costul 12, servit de PHP 8.3).
$costImplicit = PASSWORD_BCRYPT_DEFAULT_COST;
$cazuriRefacere = [
    'bcrypt cu costul implicit (' . $costImplicit . ')' => [password_hash('x', PASSWORD_BCRYPT), false],
    'bcrypt cu costul ' . ($costImplicit + 1) . ' (mai mare)' => [password_hash('x', PASSWORD_BCRYPT, ['cost' => $costImplicit + 1]), false],
    'bcrypt cu costul 4 (mai mic)' => [password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]), true],
    'bcrypt $2a$' => ['$2a$' . substr($bcrypt, 4), true],
    'argon2id' => [password_hash('x', PASSWORD_ARGON2ID), true],
    'text clar (se criptează în Auth::attempt, nu de aici)' => ['Parola-Veche-Demo', false],
    'hash stricat' => ['$2y$10$scurt', false],
    'gol' => ['', false],
];
$gresite = [];
foreach ($cazuriRefacere as $nume => [$valoare, $asteptat]) {
    if (ParolaAdmin::deRefacut($valoare) !== $asteptat) {
        $gresite[] = $nume . ' → ' . var_export(ParolaAdmin::deRefacut($valoare), true);
    }
}
$verifica('ParolaAdmin::deRefacut: bcrypt vechi, argon2 și un cost mic se refac; costul implicit sau unul mai mare nu (nu coboară), nici textul, hash-ul stricat sau golul',
    $gresite === [], implode('; ', $gresite));
$verifica('Sesiune::idNouLaLogare în linia de comandă (fără sesiune): nu face nimic', Sesiune::idNouLaLogare() === false && session_status() !== PHP_SESSION_ACTIVE);

// Sesiune::idNouLaLogare pe fișiere de sesiune adevărate, într-un PHP separat (aici antetele au plecat odată cu primul
// rând al raportului): ce rămâne în fișierul id-ului de dinainte și ce trece în cel nou. Așa se vede și ce nu se poate
// vedea prin server: o cerere care citește id-ul de dinainte după logare (heartbeat-ul care aștepta lacătul) îl găsește
// însemnat, deci nu atinge coșul abandonat, iar cheia coșului din sesiunea nouă e chiar id-ul de dinainte (cea pe care
// scrie un heartbeat care citise sesiunea chiar înainte de logare).
$dosarSesiuneTest = $dosarDev . '/test-securitate-sesiuni-' . getmypid();
@mkdir($dosarSesiuneTest, 0700, true);
$scriptSesiune = $dosarSesiuneTest . '/sesiune.php';
file_put_contents($scriptSesiune, <<<'PHP'
<?php
declare(strict_types=1);
ob_start();
require $argv[1] . '/bootstrap.php';
use App\Support\Sesiune;
ini_set('session.save_handler', 'files');
ini_set('session.save_path', $argv[2]);
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.cache_limiter', '');
$citeste = static function (string $id): array {
    session_id($id);
    session_start();
    $date = $_SESSION;
    session_write_close();
    return $date;
};
session_start();
$a = session_id();
$_SESSION = ['cart' => ['items' => [7 => 1]], 'checkout_form' => ['billing_email' => 'musafir@exemplu.ro'], 'admin_id' => 3,
    'admin_roles' => ['administrator_general'], 'admin_role' => 'administrator_general', 'admin_last_seen' => 1, 'customer_user_id' => 5];
session_write_close();
session_id($a);
session_start();
$cheieInainte = Sesiune::cheieCosAbandonat();
$schimbat = Sesiune::idNouLaLogare();
$_SESSION['customer_user_id'] = 9;
$b = session_id();
$cheieNoua = Sesiune::cheieCosAbandonat();
session_write_close();
$vechi = $citeste($a);
$nou = $citeste($b);
session_id($a);
session_start();
$cheieInlocuita = Sesiune::cheieCosAbandonat();
$schimbatPeInlocuita = Sesiune::idNouLaLogare();
$c = session_id();
$cheiePeInlocuita = Sesiune::cheieCosAbandonat();
session_write_close();
$dupaA = $citeste($a);
ob_end_clean();
fwrite(STDOUT, json_encode(compact('a', 'b', 'c', 'cheieInainte', 'schimbat', 'cheieNoua', 'vechi', 'nou', 'cheieInlocuita',
    'schimbatPeInlocuita', 'cheiePeInlocuita', 'dupaA')));
PHP);
$rezultatSesiune = json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scriptSesiune) . ' '
    . escapeshellarg($radacina) . ' ' . escapeshellarg($dosarSesiuneTest) . ' 2>&1'), true);
foreach (glob($dosarSesiuneTest . '/*') ?: [] as $fisierSesiune) {
    @unlink($fisierSesiune);
}
@rmdir($dosarSesiuneTest);
$rs = is_array($rezultatSesiune) ? $rezultatSesiune : [];
$fara = static fn (array $date): bool => array_intersect(Sesiune::CHEI_LOGARE, array_keys($date)) === [];
$verifica('Sesiune::idNouLaLogare pe fișiere: id nou; în fișierul id-ului de dinainte rămân coșul și formularul, fără nicio logare (admin_*, customer_user_id), cu însemnul „înlocuită” și cheia coșului = id-ul de dinainte',
    ($rs['schimbat'] ?? null) === true && ($rs['a'] ?? '') !== '' && ($rs['b'] ?? '') !== '' && $rs['a'] !== $rs['b'] && $fara($rs['vechi'] ?? ['admin_id' => 1])
    && ($rs['vechi']['cart'] ?? null) === ['items' => [7 => 1]] && ($rs['vechi']['checkout_form']['billing_email'] ?? '') === 'musafir@exemplu.ro'
    && isset($rs['vechi'][Sesiune::CHEIE_INLOCUITA]) && ($rs['vechi'][Sesiune::CHEIE_COS_ABANDONAT] ?? '') === $rs['a'],
    json_encode($rezultatSesiune));
$verifica('… în sesiunea nouă: coșul, logările de dinainte (adminul, clientul) și cea nouă, fără însemn; cheia coșului abandonat rămâne id-ul de dinainte (un singur rând pe browser)',
    ($rs['nou']['cart'] ?? null) === ['items' => [7 => 1]] && ($rs['nou']['admin_id'] ?? null) === 3 && ($rs['nou']['customer_user_id'] ?? null) === 9
    && !isset($rs['nou'][Sesiune::CHEIE_INLOCUITA]) && ($rs['cheieInainte'] ?? '') === ($rs['a'] ?? '-') && ($rs['cheieNoua'] ?? '') === ($rs['a'] ?? '-'),
    json_encode($rezultatSesiune));
$verifica('… cu id-ul de dinainte: cheia coșului e goală (nu atinge rândul); o logare pe el dă alt id, cu o cheie nouă (nu ia rândul celui care s-a logat întâi), iar fișierul lui rămâne însemnat',
    ($rs['cheieInlocuita'] ?? null) === '' && ($rs['schimbatPeInlocuita'] ?? null) === true && ($rs['c'] ?? '') !== '' && $rs['c'] !== $rs['a']
    && ($rs['cheiePeInlocuita'] ?? '') === $rs['c'] && isset($rs['dupaA'][Sesiune::CHEIE_INLOCUITA]) && $fara($rs['dupaA'] ?? ['admin_id' => 1]),
    json_encode($rezultatSesiune));

// Predarea primei rulări pe Hostinger (WP-08a): o adresă IPv6 în REMOTE_ADDR (cu un X-Forwarded-For inventat, fără proxy
// de încredere) și un X-Forwarded-For IPv6 fără paranteze care se termină în cifre, de la un proxy de încredere (nu e un
// port). Adresa IPv6 intră mai jos și în condiția limitelor, pe ambele servere (numărată cu rețeaua ei /64).
$serverInainte = $_SERVER;
$_SERVER['REMOTE_ADDR'] = '2001:DB8:1:2:0:0:0:1234';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '2001:db8:9::1';
$adresaV6 = \App\Support\IpClient::adresa();
$_SERVER = $serverInainte;
$dinProxyV6 = \App\Support\IpClient::dinCerere(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 2001:db8:1:2::1234'], '10.0.0.0/8');
$verifica('IPv6: REMOTE_ADDR 2001:DB8:1:2:0:0:0:1234 cu un X-Forwarded-For inventat dă 2001:db8:1:2::1234 (cheia limitei: 2001:db8:1:2::/64); de la un proxy de încredere, X-Forwarded-For „…, 2001:db8:1:2::1234” rămâne întreg',
    $adresaV6 === '2001:db8:1:2::1234' && \App\Support\IpClient::cheieLimita($adresaV6) === '2001:db8:1:2::/64' && $dinProxyV6 === '2001:db8:1:2::1234',
    $adresaV6 . ' / ' . $dinProxyV6);

// Condiția SQL a limitelor (IpClient::conditieLimita), pe MariaDB și pe MySQL 8 (pe Hostinger nu se știe încă ce
// server e): același număr de rânduri pe ambele, pe valori scrise de codul de acum și de cel vechi.
$sockMaria = (string) (getenv('CMS_DEV_SOCK') ?: '/tmp/claude-0/maria/sock');
$sockMy8 = (string) (getenv('CMS_DEV_SOCK_MYSQL8') ?: '/tmp/claude-0/my8/run/sock');
$conectare = static function (string $sock): ?PDO {
    try {
        return new PDO('mysql:unix_socket=' . $sock . ';charset=utf8mb4', (string) (getenv('CMS_DEV_DB_USER') ?: 'root'), (string) (getenv('CMS_DEV_DB_PAROLA') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable) {
        return null;
    }
};
$mysql8Pornit = false;
if ($conectare($sockMy8) === null) {
    exec(escapeshellarg($radacina . '/tools/dev/mysql8.sh') . ' porneste 2>&1', $iesireMy8, $codMy8);
    $mysql8Pornit = $codMy8 === 0;
}
$valoriSalvate = ['2001:db8:1:2::1', '2001:db8:1:2:ffff:ffff:ffff:ff', '2001:0db8:0001:0002::9', '2001:db8:1:3::1', '198.51.100.7', '198.51.100.70',
    '::ffff:198.51.100.7', '203.0.113.5, 198.51.100.7', 'unknown', '', null];
$tabelValori = '(' . implode(' UNION ALL ', array_map(static fn ($v): string => 'SELECT ' . ($v === null ? 'NULL' : "'" . $v . "'") . ' AS ip_address', $valoriSalvate)) . ') t';
$cazuriLimita = ['2001:db8:1:2::abc' => 3, '2001:DB8:1:2:0:0:0:1' => 3, '2001:db8:1:3::ffff' => 1, '2001:db8:9::1' => 0, '198.51.100.7' => 1, '198.51.100.8' => 0,
    $adresaV6 => 3];
$rezultateServere = [];
foreach (['MariaDB' => $sockMaria, 'MySQL 8' => $sockMy8] as $numeServer => $sock) {
    $pdo = $conectare($sock);
    if ($pdo === null) {
        $rezultateServere[$numeServer] = 'nu merge';
        continue;
    }
    foreach ($cazuriLimita as $ip => $asteptat) {
        [$conditie, $parametri] = \App\Support\IpClient::conditieLimita('ip_address', $ip);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $tabelValori . ' WHERE ' . $conditie);
        $stmt->execute($parametri);
        $rezultateServere[$numeServer][$ip] = (int) $stmt->fetchColumn();
    }
}
if ($mysql8Pornit) {
    exec(escapeshellarg($radacina . '/tools/dev/mysql8.sh') . ' opreste > /dev/null 2>&1');
}
$verifica('IpClient::conditieLimita pe MariaDB și pe MySQL 8: IPv6 pe /64 (și scrisă altfel), IPv4 exact, valorile vechi (antet întreg, „unknown”, gol, NULL) nu se potrivesc',
    ($rezultateServere['MariaDB'] ?? null) === $cazuriLimita && ($rezultateServere['MySQL 8'] ?? null) === $cazuriLimita, json_encode($rezultateServere));

// Căutarea în cod: adresa vizitatorului și scrierea logării în sesiune.
$fisiereCod = [];
foreach (['src', 'views', 'public', 'config', 'scripts'] as $dosar) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radacina . '/' . $dosar, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $fisier) {
        if ($fisier->isFile() && str_ends_with($fisier->getFilename(), '.php') && !str_contains($fisier->getPathname(), '/public/uploads/')) {
            $fisiereCod[] = $fisier->getPathname();
        }
    }
}
$fisiereCod[] = $radacina . '/bootstrap.php';
sort($fisiereCod);
$tiparAdresa = '/\b(REMOTE_ADDR|HTTP_X_FORWARDED_FOR|HTTP_X_REAL_IP|HTTP_CF_CONNECTING_IP|HTTP_CLIENT_IP|HTTP_TRUE_CLIENT_IP|HTTP_FORWARDED)\b/';
// Citirile permise: IpClient însuși și sonda copiei de verificare (spune doar ce sosește, ca DA/NU și clasa adresei).
$permise = [
    'src/Support/IpClient.php' => null,
    // Liniile exacte ale sondei (`Verificare::raspundeSonda`), și comentariul care numește REMOTE_ADDR.
    'src/Support/Verificare.php' => [
        "\$remote = (string) (\$_SERVER['REMOTE_ADDR'] ?? '');",
        "\$xff = trim((string) (\$_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));",
        "\$xRealIp = trim((string) (\$_SERVER['HTTP_X_REAL_IP'] ?? ''));",
        "'cf_connecting_ip' => trim((string) (\$_SERVER['HTTP_CF_CONNECTING_IP'] ?? '')) !== '',",
        "'forwarded' => trim((string) (\$_SERVER['HTTP_FORWARDED'] ?? '')) !== '',",
        '// Doar DA/NU, nicio adresă: ultima adresă din X-Forwarded-For și X-Real-IP sunt chiar REMOTE_ADDR? (pe Hostinger, prin',
        '// CDN-ul hcdn: spune dacă REMOTE_ADDR e vizitatorul; intrare pentru IpClient, WP-12b)',
    ],
    // WP-R din 30 oct (newsletterul Bioscem 739f58c): doar textul punctului „IP-ul tău” din verificarea dinaintea trimiterii, ca
    // în codul Bioscem (comparat de G-cmp); adresa vine din IpClient::adresa(), rândul de deasupra lui.
    'src/Support/NewsletterVerificare.php' => [
        "\$puncte[] = self::punct('ip', 'info', 'IP-ul tău, așa cum îl vede site-ul: ' . \$ipVazut . ' (REMOTE_ADDR)',",
    ],
];
$cautaAdresa = static function (string $relativ, string $text) use ($tiparAdresa, $permise): array {
    $gasite = [];
    foreach (preg_split('/\R/', $text) ?: [] as $i => $linie) {
        if (preg_match($tiparAdresa, $linie) !== 1) {
            continue;
        }
        if (array_key_exists($relativ, $permise) && ($permise[$relativ] === null || in_array(trim($linie), $permise[$relativ], true))) {
            continue;
        }
        $gasite[] = $relativ . ':' . ($i + 1) . ': ' . trim($linie);
    }
    return $gasite;
};
$gasiteAdresa = [];
foreach ($fisiereCod as $fisier) {
    $relativ = substr($fisier, strlen($radacina) + 1);
    $gasiteAdresa = array_merge($gasiteAdresa, $cautaAdresa($relativ, (string) file_get_contents($fisier)));
}
$verifica('căutarea: adresa vizitatorului se citește doar în IpClient și în sonda copiei de verificare (' . count($fisiereCod) . ' fișiere)',
    $gasiteAdresa === [], implode("\n", $gasiteAdresa));
$martori = [
    'src/Support/Martor.php' => "<?php \$ip = \$_SERVER['REMOTE_ADDR'];",
    'views/martor.php' => "<?= \$_SERVER[\"HTTP_X_FORWARDED_FOR\"] ?? '' ?>",
    'src/Support/Verificare.php' => "\$alt = \$_SERVER['HTTP_X_REAL_IP'] ?? '';",
    'src/Http/Controllers/SiteController.php' => "\$forwarded = \$_SERVER['HTTP_FORWARDED'] ?? getenv('HTTP_CLIENT_IP');",
];
$verifica('căutarea pică pe citiri puse anume (martorul: alt fișier, o vedere, o citire nouă în Verificare, Forwarded/Client-IP)',
    count(array_filter($martori, static fn (string $text, string $relativ): bool => $cautaAdresa($relativ, $text) !== [], ARRAY_FILTER_USE_BOTH)) === count($martori));

$scrieriLogare = [];
foreach ($fisiereCod as $fisier) {
    $relativ = substr($fisier, strlen($radacina) + 1);
    foreach (preg_split('/\R/', (string) file_get_contents($fisier)) ?: [] as $i => $linie) {
        if (preg_match('/\$_SESSION\[\s*(\'admin_id\'|"admin_id"|\'customer_user_id\'|"customer_user_id"|self::SESSION_KEY)\s*\]\s*=[^=]/', $linie) === 1) {
            $scrieriLogare[] = $relativ . ':' . ($i + 1);
        }
    }
}
$corpLogare = static function (string $fisier, string $functie, string $scriere) use ($radacina): bool {
    $text = (string) file_get_contents($radacina . '/' . $fisier);
    $inceput = strpos($text, 'public static function ' . $functie . '(');
    $sfarsit = $inceput === false ? false : strpos($text, $scriere, $inceput);
    return $inceput !== false && $sfarsit !== false && str_contains(substr($text, $inceput, $sfarsit - $inceput), 'Sesiune::idNouLaLogare();');
};
$verifica('cine s-a logat se scrie în sesiune doar în Auth::attempt și CustomerAuth::login, fiecare după Sesiune::idNouLaLogare()',
    count($scrieriLogare) === 2 && str_starts_with($scrieriLogare[0], 'src/Support/Auth.php:') && str_starts_with($scrieriLogare[1], 'src/Support/CustomerAuth.php:')
    && $corpLogare('src/Support/Auth.php', 'attempt', "\$_SESSION['admin_id'] =")
    && $corpLogare('src/Support/CustomerAuth.php', 'login', '$_SESSION[self::SESSION_KEY] ='),
    implode(', ', $scrieriLogare));

// =====================================================================
// Serverele și baza
// =====================================================================
$port = (int) (getenv('CMS_DEV_PORT') ?: '8701');
$baza = 'http://127.0.0.1:' . $port;
$portProxy = $port + 20;
$bazaProxy = 'http://127.0.0.1:' . $portProxy;

/**
 * O cerere spre unul dintre servere; `antete` = antetele în plus (de exemplu X-Forwarded-For); `json` = corp JSON.
 *
 * @return array{cod: int, location: string, corp: string, cookieuri: list<string>}
 */
$cerere = static function (string $metoda, string $cale, ?string $jar = null, array $date = [], array $antete = [], ?string $json = null, ?string $server = null) use ($baza): array {
    $c = curl_init(($server ?? $baza) . $cale);
    $cookieuri = [];
    $location = '';
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metoda,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOPROXY => '*',
        CURLOPT_PROXY => '',
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$cookieuri, &$location): int {
            if (stripos($linie, 'set-cookie:') === 0) {
                $cookieuri[] = trim(substr($linie, 11));
            } elseif (stripos($linie, 'location:') === 0) {
                $location = trim(substr($linie, 9));
            }
            return strlen($linie);
        },
    ]);
    $liniiAntet = [];
    foreach ($antete as $nume => $valoare) {
        $liniiAntet[] = $nume . ': ' . $valoare;
    }
    if ($json !== null) {
        $liniiAntet[] = 'Content-Type: application/json';
        curl_setopt($c, CURLOPT_POSTFIELDS, $json);
    } elseif ($metoda === 'POST') {
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($date));
    }
    if ($liniiAntet !== []) {
        curl_setopt($c, CURLOPT_HTTPHEADER, $liniiAntet);
    }
    if ($jar !== null) {
        curl_setopt($c, CURLOPT_COOKIEFILE, $jar);
        curl_setopt($c, CURLOPT_COOKIEJAR, $jar);
    }
    $corp = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $cod, 'location' => $location, 'corp' => $corp, 'cookieuri' => $cookieuri];
};
/** Id-ul sesiunii din borcanul de cookie-uri al lui curl (gol dacă nu e). */
$idSesiune = static function (string $jar): string {
    foreach (preg_split('/\R/', (string) @file_get_contents($jar)) ?: [] as $linie) {
        $parti = explode("\t", $linie);
        if (count($parti) >= 7 && $parti[5] === session_name()) {
            return trim($parti[6]);
        }
    }
    return '';
};
/** Un borcan care are doar cookie-ul de sesiune dat (id-ul fixat de un atacator). */
$borcanCuId = static function (string $jar, string $id): void {
    file_put_contents($jar, "# Netscape HTTP Cookie File\n#HttpOnly_127.0.0.1\tFALSE\t/\tFALSE\t0\t" . session_name() . "\t" . $id . "\n");
};

$amPornitServerul = false;
if ($cerere('GET', '/health')['cod'] !== 200) {
    exec(escapeshellarg($radacina . '/tools/dev/server.sh') . ' 2>&1', $iesire, $codServer);
    if ($codServer !== 0) {
        $scrie('EROARE: serverul nu pornește: ' . implode(' ', $iesire));
        exit(1);
    }
    $amPornitServerul = true;
}

$app = require $radacina . '/config/app.php';
$db = Database::connection((array) $app['db']);
if (!$db instanceof PDO || (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn() < 10) {
    $scrie('EROARE: baza de test nu are datele demo; rulează întâi tools/dev/instaleaza.sh');
    exit(1);
}
$unu = static function (string $sql, array $param = []) use ($db): string {
    $stmt = $db->prepare($sql);
    $stmt->execute($param);
    $valoare = $stmt->fetchColumn();
    return $valoare === false || $valoare === null ? '' : (string) $valoare;
};

// Instantaneul întregii baze (ca test-reparatii.php): pus la loc la final, verificat cu CHECKSUM TABLE.
$tabeleBaza = [];
foreach ($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
    ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) as $tabel) {
    $chei = $db->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND CONSTRAINT_NAME = 'PRIMARY'");
    $chei->execute(['t' => $tabel]);
    $coloaneCheie = $chei->fetchAll(PDO::FETCH_COLUMN);
    $tabeleBaza[] = ['tabel' => (string) $tabel, 'unde' => '1 = 1', 'cheie' => count($coloaneCheie) === 1 ? (string) $coloaneCheie[0] : null];
}
$sumeBaza = static function () use ($db, $tabeleBaza): array {
    $sume = [];
    $lista = implode(', ', array_map(static fn (array $t): string => '`' . $t['tabel'] . '`', $tabeleBaza));
    foreach ($db->query('CHECKSUM TABLE ' . $lista)->fetchAll(PDO::FETCH_NUM) as $rand) {
        $sume[(string) $rand[0]] = (string) $rand[1];
    }
    return $sume;
};
$instantaneu = salvari_instantaneu($db, $tabeleBaza);
$sumeInainte = $sumeBaza();
$sesiuniInainte = glob(Paths::stocare('sessions') . '/sess_*') ?: [];
$emailuriInainte = glob($dosarDev . '/mail/*.eml') ?: [];
$fisierErori = $dosarDev . '/php-erori.log';
$pozitieErori = is_file($fisierErori) ? (int) filesize($fisierErori) : 0;
$dosarJar = $dosarDev . '/test-securitate-' . getmypid();
@mkdir($dosarJar, 0700, true);
$jar = static fn (string $nume): string => $dosarJar . '/' . $nume . '.txt';

// Al doilea server: 127.0.0.1 e proxy de încredere (IpClient), deci X-Forwarded-For e crezut de la el.
$pidProxy = 0;
$pornesteProxy = static function () use ($radacina, $dosarDev, $portProxy, $bazaProxy, $cerere, &$pidProxy): bool {
    $ini = [];
    foreach (['pdo_mysql.default_socket', 'error_reporting', 'display_errors', 'log_errors', 'error_log', 'sendmail_path', 'memory_limit'] as $cheie) {
        $valoare = ini_get($cheie);
        if ($valoare !== false && $valoare !== '') {
            $ini[] = '-d ' . escapeshellarg($cheie . '=' . $valoare);
        }
    }
    $ini[] = '-d allow_url_fopen=0';
    $comanda = 'cd ' . escapeshellarg($radacina) . ' && env PHP_CLI_SERVER_WORKERS=2 IP_PROXY_DE_INCREDERE=127.0.0.1 APP_URL=' . escapeshellarg($bazaProxy)
        . ' nohup ' . escapeshellarg(PHP_BINARY) . ' ' . implode(' ', $ini) . ' -S 127.0.0.1:' . $portProxy . ' -t public tools/dev/router.php >> '
        . escapeshellarg($dosarDev . '/server-securitate-' . $portProxy . '.log') . ' 2>&1 & echo $!';
    $pidProxy = (int) trim((string) shell_exec($comanda));
    for ($i = 0; $i < 50; $i++) {
        if ($cerere('GET', '/health', null, [], [], null, $bazaProxy)['cod'] === 200) {
            return true;
        }
        usleep(200000);
    }
    return false;
};

$puneLaLoc = static function () use (&$pidProxy, $db, $tabeleBaza, $instantaneu, $radacina, $dosarJar, $sesiuniInainte, $emailuriInainte, $dosarDev, $amPornitServerul): void {
    if ($pidProxy > 0) {
        exec('pkill -TERM -P ' . $pidProxy . ' 2>/dev/null; kill ' . $pidProxy . ' 2>/dev/null');
        for ($i = 0; $i < 50 && trim((string) shell_exec('pgrep -P ' . $pidProxy . ' 2>/dev/null')) !== ''; $i++) {
            usleep(100000);
        }
        $pidProxy = 0;
    }
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    salvari_pune_la_loc($db, $tabeleBaza, $instantaneu);
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    foreach (array_diff(glob(Paths::stocare('sessions') . '/sess_*') ?: [], $sesiuniInainte) as $sesiune) {
        @unlink($sesiune);
    }
    foreach (array_diff(glob($dosarDev . '/mail/*.eml') ?: [], $emailuriInainte) as $email) {
        @unlink($email);
    }
    foreach (glob($dosarJar . '/*') ?: [] as $fisier) {
        @unlink($fisier);
    }
    @rmdir($dosarJar);
    if ($amPornitServerul) {
        exec(escapeshellarg($radacina . '/tools/dev/server.sh') . ' --opreste >/dev/null 2>&1');
    }
};
register_shutdown_function(static function () use ($puneLaLoc): void {
    $puneLaLoc();
});

if (!$pornesteProxy()) {
    $scrie('EROARE: al doilea server (proxy de încredere) nu pornește pe portul ' . $portProxy);
    exit(1);
}

$parolaDemo = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));
$adminNou = static function (string $email, string $parolaSalvata) use ($db): int {
    $db->prepare('INSERT INTO admins (email, password_hash, role, roles_json) VALUES (:e, :h, :r, :j)')
        ->execute(['e' => $email, 'h' => $parolaSalvata, 'r' => Auth::ROLE_GENERAL, 'j' => json_encode([Auth::ROLE_GENERAL])]);
    return (int) $db->lastInsertId();
};
$parolaSalvata = static fn (int $id): string => $unu('SELECT password_hash FROM admins WHERE id = :id', ['id' => $id]);
$randuriJurnal = static fn (int $id): array => $db->query('SELECT * FROM admin_activity_log WHERE admin_id = ' . $id . " AND action = '"
    . Auth::JURNAL_PAROLA_CONVERTITA . "' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$logare = static function (string $email, string $parola, ?string $jarul = null, array $antete = [], ?string $server = null) use ($cerere): array {
    return $cerere('POST', '/admin/login', $jarul, ['email' => $email, 'password' => $parola], $antete, null, $server);
};
$aIntrat = static fn (array $r): bool => $r['cod'] === 302 && str_starts_with($r['location'], '/admin');

// A, în proces: Auth::attempt pe rânduri ale testului (linia de comandă: $_SESSION e un array).
$idGol = $adminNou('gol.wp12b@exemplu.ro', '');
$idClarProces = $adminNou('clar-proces.wp12b@exemplu.ro', 'Parola-Proces-Demo');
$verifica('Auth::attempt: o parolă goală salvată nu se loghează, nici cu parola goală',
    !Auth::attempt($db, 'gol.wp12b@exemplu.ro', '') && !Auth::attempt($db, 'gol.wp12b@exemplu.ro', ' ') && !isset($_SESSION['admin_id']));
$verifica('Auth::attempt: o parolă greșită pe o parolă necriptată nu schimbă rândul și nu scrie în jurnal',
    !Auth::attempt($db, 'clar-proces.wp12b@exemplu.ro', 'parola-proces-demo') && $parolaSalvata($idClarProces) === 'Parola-Proces-Demo'
    && $randuriJurnal($idClarProces) === [] && !isset($_SESSION['admin_id']));

// =====================================================================
// B. Parolele adminilor (G-M5)
// =====================================================================
$scrie('');
$scrie('== B. Parolele adminilor: necriptată, argon2id, $2a$/$2b$, hash stricat, alt cost');

$clar = 'Parola-Veche-Demo-12b';
$idClar = $adminNou('clar.wp12b@exemplu.ro', $clar);
$antetFals = ['X-Forwarded-For' => '203.0.113.66', 'X-Real-IP' => '203.0.113.66', 'CF-Connecting-IP' => '203.0.113.66'];
$r = $logare('clar.wp12b@exemplu.ro', 'parola-veche-demo-12b', $jar('clar-gresit'), $antetFals);
$verifica('parolă necriptată, parolă greșită (alte litere mari): refuzată, rândul neatins, nimic în jurnal',
    !$aIntrat($r) && $parolaSalvata($idClar) === $clar && $randuriJurnal($idClar) === []);
$r = $logare('clar.wp12b@exemplu.ro', $clar, $jar('clar'), $antetFals);
$dupa = $parolaSalvata($idClar);
$jurnal = $randuriJurnal($idClar);
// Costul implicit al PHP-ului serverului (poate fi altul decât al testului, dacă serverul mergea deja): al hash-ului nou
// (dacă rândul n-a devenit un bcrypt, verificarea de mai jos pică, iar restul testului merge cu costul PHP-ului de aici).
$costServer = (int) (password_get_info($dupa)['options']['cost'] ?? 0);
$verifica('parola bună intră, iar rândul devine bcrypt $2y$ (password_get_info: 2y, costul ' . $costServer . ', cel puțin 10) care verifică aceeași parolă',
    $aIntrat($r) && str_starts_with($dupa, '$2y$') && strlen($dupa) === 60 && password_get_info($dupa)['algo'] === '2y' && password_verify($clar, $dupa)
    && ParolaAdmin::fel($dupa) === ParolaAdmin::HASH && $costServer >= 10, $r['cod'] . ' ' . $r['location'] . ' ' . substr($dupa, 0, 7));
if ($costServer < 10) {
    $costServer = PASSWORD_BCRYPT_DEFAULT_COST;
}
$textJurnal = json_encode($jurnal, JSON_UNESCAPED_UNICODE) ?: '';
$verifica('… un singur rând „' . Auth::JURNAL_PAROLA_CONVERTITA . '” în Jurnal activitate, al acestui admin, fără parolă (nici veche, nici hash), cu adresa reală (127.0.0.1), nu cu cea din antet',
    count($jurnal) === 1 && (string) ($jurnal[0]['admin_email'] ?? '') === 'clar.wp12b@exemplu.ro' && (string) ($jurnal[0]['ip'] ?? '') === '127.0.0.1'
    && !str_contains($textJurnal, $clar) && !str_contains($textJurnal, substr($dupa, 7)) && !str_contains($textJurnal, '203.0.113.66'), $textJurnal);
$pagina = $cerere('GET', '/admin/activity-log', $jar('clar'));
$verifica('… Jurnal activitate îl arată cu eticheta lui', $pagina['cod'] === 200 && str_contains($pagina['corp'], 'Parolă veche convertită'), (string) $pagina['cod']);
$r = $logare('clar.wp12b@exemplu.ro', $clar, $jar('clar-2'));
$verifica('a doua logare cu aceeași parolă merge (acum prin hash) și nu mai scrie nimic: rândul și jurnalul neatinse',
    $aIntrat($r) && $parolaSalvata($idClar) === $dupa && count($randuriJurnal($idClar)) === 1);
$verifica('hash-ul nou, scris ca parolă, nu intră', !$aIntrat($logare('clar.wp12b@exemplu.ro', $dupa, $jar('clar-hash'))));

$argon = password_hash('Parola-Argon-Demo', PASSWORD_ARGON2ID);
$idArgon = $adminNou('argon.wp12b@exemplu.ro', $argon);
$verifica('argon2id: hash-ul însuși, scris ca parolă, nu intră (codul vechi îl compara în clar și intra) și rândul rămâne',
    !$aIntrat($logare('argon.wp12b@exemplu.ro', $argon, $jar('argon-hash'))) && $parolaSalvata($idArgon) === $argon);
$r = $logare('argon.wp12b@exemplu.ro', 'Parola-Argon-Demo', $jar('argon'));
$dupa = $parolaSalvata($idArgon);
$verifica('argon2id: parola intră; rândul e refăcut cu PASSWORD_DEFAULT (bcrypt $2y$), fără rând în jurnal',
    $aIntrat($r) && str_starts_with($dupa, '$2y$') && password_verify('Parola-Argon-Demo', $dupa) && $randuriJurnal($idArgon) === [], $r['cod'] . ' ' . substr($dupa, 0, 7));

foreach (['$2a$', '$2b$'] as $prefix) {
    $vechi = $prefix . substr(password_hash('Parola-Bcrypt-Demo', PASSWORD_BCRYPT, ['cost' => 5]), 4);
    $email = 'bcrypt' . substr($prefix, 2, 1) . '.wp12b@exemplu.ro';
    $idVechi = $adminNou($email, $vechi);
    $verifica('bcrypt ' . $prefix . ': ParolaAdmin îl vede „bcrypt_vechi” (nu text clar), iar hash-ul scris ca parolă nu intră',
        ParolaAdmin::fel($vechi) === ParolaAdmin::BCRYPT_VECHI && !$aIntrat($logare($email, $vechi, $jar('vechi-hash'))) && $parolaSalvata($idVechi) === $vechi);
    $r = $logare($email, 'Parola-Bcrypt-Demo', $jar('vechi'));
    $dupa = $parolaSalvata($idVechi);
    $verifica('bcrypt ' . $prefix . ': parola intră (ca la codul vechi) și rândul devine $2y$, fără rând în jurnal',
        $aIntrat($r) && str_starts_with($dupa, '$2y$') && password_verify('Parola-Bcrypt-Demo', $dupa) && $randuriJurnal($idVechi) === [],
        $r['cod'] . ' ' . substr($dupa, 0, 7));
}

$stricat = '$2y$10$scurt';
$idStricat = $adminNou('stricat.wp12b@exemplu.ro', $stricat);
$verifica('hash stricat ($2y$ scurt): nici valoarea lui, nici altă parolă nu intră; rândul neatins',
    !$aIntrat($logare('stricat.wp12b@exemplu.ro', $stricat, $jar('stricat'))) && !$aIntrat($logare('stricat.wp12b@exemplu.ro', 'scurt', $jar('stricat')))
    && $parolaSalvata($idStricat) === $stricat);
// Alte hash-uri (un admin pus de mână din alt sistem): codul vechi le compara în clar, deci intra cine scria hash-ul.
foreach ([
    'SHA-512 crypt ($6$)' => ['sha512', crypt('Parola-Sha-Demo', '$6$rounds=5000$saredemo$'), 'Parola-Sha-Demo'],
    'WordPress phpass ($P$)' => ['phpass', '$P$BsaredemoAbCdEfGhIjKlMnOpQrStUv', 'Parola-Wp-Demo'],
] as $nume => [$cheie, $hashAlt, $parolaAlt]) {
    $idAlt = $adminNou($cheie . '.wp12b@exemplu.ro', $hashAlt);
    $verifica($nume . ': hash-ul însuși, scris ca parolă, nu intră (codul vechi îl compara în clar), nici parola; rândul neatins, nimic în jurnal',
        ParolaAdmin::fel($hashAlt) === ParolaAdmin::HASH_STRICAT && !$aIntrat($logare($cheie . '.wp12b@exemplu.ro', $hashAlt, $jar('alt-hash')))
        && !$aIntrat($logare($cheie . '.wp12b@exemplu.ro', $parolaAlt, $jar('alt-parola'))) && $parolaSalvata($idAlt) === $hashAlt && $randuriJurnal($idAlt) === []);
}

$cost4 = password_hash('Parola-Cost-Demo', PASSWORD_BCRYPT, ['cost' => 4]);
$idCost = $adminNou('cost.wp12b@exemplu.ro', $cost4);
$r = $logare('cost.wp12b@exemplu.ro', 'Parola-Cost-Demo', $jar('cost'));
$dupa = $parolaSalvata($idCost);
$verifica('bcrypt cu costul 4: la logare e refăcut cu costul implicit al serverului (' . $costServer . '), fără rând în jurnal',
    $aIntrat($r) && $dupa !== $cost4 && (int) (password_get_info($dupa)['options']['cost'] ?? 0) === $costServer
    && password_verify('Parola-Cost-Demo', $dupa) && $randuriJurnal($idCost) === []);
$deAcum = password_hash('Parola-Acum-Demo', PASSWORD_BCRYPT, ['cost' => $costServer]);
$idAcum = $adminNou('acum.wp12b@exemplu.ro', $deAcum);
$verifica('bcrypt-ul de acum (costul implicit al serverului): logarea nu rescrie rândul',
    $aIntrat($logare('acum.wp12b@exemplu.ro', 'Parola-Acum-Demo', $jar('acum'))) && $parolaSalvata($idAcum) === $deAcum);
// Un cost mai mare decât cel implicit al PHP-ului serverului (o bază făcută cu PHP 8.4, servită de PHP 8.3) nu coboară.
$costMare = password_hash('Parola-Cost-Mare-Demo', PASSWORD_BCRYPT, ['cost' => $costServer + 1]);
$idCostMare = $adminNou('cost-mare.wp12b@exemplu.ro', $costMare);
$verifica('bcrypt cu costul ' . ($costServer + 1) . ' (peste cel implicit al serverului): logarea intră și nu coboară costul (rândul neatins)',
    $aIntrat($logare('cost-mare.wp12b@exemplu.ro', 'Parola-Cost-Mare-Demo', $jar('cost-mare'))) && $parolaSalvata($idCostMare) === $costMare);

// =====================================================================
// C. Id-ul sesiunii la logare (N15)
// =====================================================================
$scrie('');
$scrie('== C. Id-ul sesiunii: nou la fiecare logare; cu id-ul vechi nu e nimeni logat; coșul trece');

// Jurnalele limitelor (checkout 10, înregistrare 8 în 15 minute, de pe 127.0.0.1) se golesc înainte de C: încercările
// lăsate de alte teste în ultimele 15 minute ar opri înregistrarea de mai jos (rândurile se pun la loc la final).
$golesteJurnale = static function () use ($db): void {
    $db->exec('DELETE FROM checkout_submit_logs');
    $db->exec('DELETE FROM register_submit_logs');
};
$golesteJurnale();

$idMenta = (int) $unu("SELECT id FROM products WHERE slug = 'ceai-de-menta'");
$numeMenta = htmlspecialchars($unu('SELECT name FROM products WHERE id = :id', ['id' => $idMenta]), ENT_QUOTES);
$areCos = static fn (string $jarul): bool => str_contains($cerere('GET', '/cos', $jarul)['corp'], $numeMenta);
$eAdmin = static fn (string $jarul): bool => $cerere('GET', '/admin', $jarul)['cod'] === 200;
$eClient = static fn (string $jarul): bool => $cerere('GET', '/contul-meu', $jarul)['cod'] === 200;

// Adminul.
$cerere('GET', '/admin/login', $jar('adm'));
$cerere('POST', '/cos/adauga/' . $idMenta, $jar('adm'), ['quantity' => '1']);
$idInainte = $idSesiune($jar('adm'));
copy($jar('adm'), $jar('adm-atacator'));
$r = $logare('acum.wp12b@exemplu.ro', 'Parola-Acum-Demo', $jar('adm'));
$idDupa = $idSesiune($jar('adm'));
$verifica('adminul: logarea trimite alt id de sesiune (cookie nou), nu pe cel de dinainte',
    $aIntrat($r) && $idInainte !== '' && $idDupa !== '' && $idDupa !== $idInainte && count($r['cookieuri']) >= 1, $idInainte . ' → ' . $idDupa);
$verifica('… cu id-ul nou adminul e logat și coșul de dinainte e acolo', $eAdmin($jar('adm')) && $areCos($jar('adm')));
$verifica('… cu id-ul de dinainte (un atacator care l-a fixat) nu e nimeni logat în admin, dar coșul de dinainte e acolo',
    !$eAdmin($jar('adm-atacator')) && $areCos($jar('adm-atacator')) && $idSesiune($jar('adm-atacator')) === $idInainte);

// Clientul, cu parola.
$emailClient = 'andrei.exemplu@exemplu.ro';
$cerere('POST', '/cos/adauga/' . $idMenta, $jar('cli'), ['quantity' => '2']);
$idInainte = $idSesiune($jar('cli'));
copy($jar('cli'), $jar('cli-atacator'));
$r = $cerere('POST', '/contul-meu/login', $jar('cli'), ['email' => $emailClient, 'password' => $parolaDemo]);
$idDupa = $idSesiune($jar('cli'));
$verifica('clientul (parola): logarea trimite alt id de sesiune', $r['cod'] === 302 && $idInainte !== '' && $idDupa !== '' && $idDupa !== $idInainte,
    $r['cod'] . ' ' . $r['location'] . ' ' . $idInainte . ' → ' . $idDupa);
$verifica('… cu id-ul nou clientul e logat și coșul e acolo', $eClient($jar('cli')) && $areCos($jar('cli')));
$verifica('… cu id-ul de dinainte nu e logat, coșul de dinainte e acolo', !$eClient($jar('cli-atacator')) && $areCos($jar('cli-atacator')));

// Adminul peste clientul logat (același browser): ambele rămân, în sesiunea nouă; id-ul de dinainte nu mai are nicio
// logare (nici clientul), deci ieșirea din cont în sesiunea nouă închide tot ce era logat în browser.
copy($jar('cli'), $jar('cli-inainte-de-admin'));
$r = $logare('acum.wp12b@exemplu.ro', 'Parola-Acum-Demo', $jar('cli'));
$verifica('adminul logat peste un client logat: alt id, cu clientul, adminul și coșul în el; id-ul de dinainte nu mai are nicio logare (nici clientul), doar coșul',
    $aIntrat($r) && $idSesiune($jar('cli')) !== $idDupa && $eAdmin($jar('cli')) && $eClient($jar('cli')) && $areCos($jar('cli'))
    && !$eAdmin($jar('cli-inainte-de-admin')) && !$eClient($jar('cli-inainte-de-admin')) && $areCos($jar('cli-inainte-de-admin')));
$cerere('POST', '/contul-meu/logout', $jar('cli'));
$verifica('… clientul iese din cont (POST /contul-meu/logout): nici sesiunea nouă, nici id-ul de dinainte nu mai deschid Contul meu; adminul rămâne logat',
    !$eClient($jar('cli')) && !$eClient($jar('cli-inainte-de-admin')) && $eAdmin($jar('cli')));

// Clientul peste adminul logat (un cookie copiat cât era logat adminul: un calculator comun, un cookie furat). Codul de
// dinainte de această reparație lăsa logarea adminului pe id-ul copiat, iar ieșirea adminului (în sesiunea nouă) n-o
// mai închidea: /admin rămânea deschis cu el, iar fiecare cerere îi împrospăta ceasul de 12 ore.
$cerere('GET', '/admin/login', $jar('adm-cli'));
$r = $logare('acum.wp12b@exemplu.ro', 'Parola-Acum-Demo', $jar('adm-cli'));
copy($jar('adm-cli'), $jar('adm-cli-copiat'));
$idAdmin = $idSesiune($jar('adm-cli'));
$rClient = $cerere('POST', '/contul-meu/login', $jar('adm-cli'), ['email' => $emailClient, 'password' => $parolaDemo]);
$verifica('clientul logat peste un admin logat: alt id, cu amândoi; cu id-ul copiat cât era logat adminul nu se mai deschide /admin, nici /admin/orders',
    $aIntrat($r) && $rClient['cod'] === 302 && $idSesiune($jar('adm-cli')) !== $idAdmin && $eAdmin($jar('adm-cli')) && $eClient($jar('adm-cli'))
    && !$eAdmin($jar('adm-cli-copiat')) && $cerere('GET', '/admin/orders', $jar('adm-cli-copiat'))['cod'] !== 200 && !$eClient($jar('adm-cli-copiat')),
    $idAdmin . ' → ' . $idSesiune($jar('adm-cli')));
$cerere('POST', '/admin/logout', $jar('adm-cli'));
$verifica('… adminul iese din cont (POST /admin/logout): /admin și /admin/orders nu se mai deschid nici cu sesiunea nouă, nici cu id-ul copiat; clientul rămâne logat',
    !$eAdmin($jar('adm-cli')) && !$eAdmin($jar('adm-cli-copiat')) && $cerere('GET', '/admin/orders', $jar('adm-cli-copiat'))['cod'] !== 200
    && $eClient($jar('adm-cli')));

// Un id inventat de atacator nu e primit (session.use_strict_mode), deci nu poate fi „fixat” dinainte.
$inventat = str_repeat('a1', 16);
$borcanCuId($jar('inventat'), $inventat);
$cerere('POST', '/cos/adauga/' . $idMenta, $jar('inventat'), ['quantity' => '1']);
$primit = $idSesiune($jar('inventat'));
$cerere('POST', '/contul-meu/login', $jar('inventat'), ['email' => $emailClient, 'password' => $parolaDemo]);
$borcanCuId($jar('inventat-atacator'), $inventat);
$verifica('un id de sesiune inventat nu e primit (serverul dă altul), iar după logare id-ul inventat nu e logat',
    $primit !== '' && $primit !== $inventat && !$eClient($jar('inventat-atacator')) && $eClient($jar('inventat')), $primit);

// Contul nou (înregistrarea): CustomerAuth::login, deci tot id nou.
$cerere('POST', '/cos/adauga/' . $idMenta, $jar('reg'), ['quantity' => '1']);
$pagina = $cerere('GET', '/register', $jar('reg'));
preg_match('/name="register_form_token" value="([^"]+)"/', $pagina['corp'], $tok);
preg_match('/name="register_form_rendered_at" value="(\d+)"/', $pagina['corp'], $randat);
$idInainte = $idSesiune($jar('reg'));
copy($jar('reg'), $jar('reg-atacator'));
sleep(3);
$parolaNoua = 'Parola-Cont-Nou-12b';
$r = $cerere('POST', '/register', $jar('reg'), ['first_name' => 'Demo', 'last_name' => 'Securitate', 'email' => 'cont-nou.wp12b@exemplu.ro',
    'password' => $parolaNoua, 'password_confirm' => $parolaNoua, 'register_form_token' => $tok[1] ?? '', 'register_form_rendered_at' => $randat[1] ?? '',
    'register_hp_website' => '']);
$verifica('contul nou: înregistrarea (cu protecția anti-robot) loghează pe alt id; coșul trece; id-ul de dinainte nu e logat',
    $r['cod'] === 302 && $unu("SELECT COUNT(*) FROM users WHERE email = 'cont-nou.wp12b@exemplu.ro'") === '1' && $idSesiune($jar('reg')) !== $idInainte
    && $eClient($jar('reg')) && $areCos($jar('reg')) && !$eClient($jar('reg-atacator')) && $areCos($jar('reg-atacator')),
    $r['cod'] . ' ' . $r['location'] . ' token ' . (($tok[1] ?? '') !== '' ? 'da' : 'nu'));

// Coșul abandonat („Ai uitat produse în coș”, cart_abandonments, cheia: sesiunea): un singur rând și un singur email pe
// browser, ca în codul de dinainte, care nu schimba id-ul la logare. Fără cheia păstrată (Sesiune::cheieCosAbandonat),
// rândul de dinainte de logare și cel de după ar fi trimis două emailuri, iar o comandă după logare ar fi lăsat rândul
// de dinainte „abandonat”, cu email cu tot.
$parolaCos = 'Parola-Cos-Demo-12b';
$clientCos = static function (string $email) use ($db, $parolaCos): void {
    $db->prepare('INSERT INTO users (first_name, last_name, email, password_hash) VALUES (:f, :l, :e, :h)')
        ->execute(['f' => 'Demo', 'l' => 'Cos', 'e' => $email, 'h' => password_hash($parolaCos, PASSWORD_BCRYPT)]);
};
$batai = static fn (string $jarul, array $date): array => $cerere('POST', '/api/cart/heartbeat', $jarul, [], [],
    json_encode($date, JSON_UNESCAPED_UNICODE) ?: '{}');
/** Rândurile coșului abandonat cu unul dintre emailuri sau pe una dintre chei (id-uri de sesiune). */
$randuriCos = static function (array $emailuri, array $sesiuni) use ($db): array {
    $param = [];
    foreach (array_values($emailuri) as $i => $e) {
        $param['e' . $i] = $e;
    }
    foreach (array_values(array_filter($sesiuni, static fn (string $sid): bool => $sid !== '')) as $i => $sid) {
        $param['s' . $i] = $sid;
    }
    $locuri = static fn (string $litera): string => implode(', ', array_map(static fn (string $k): string => ':' . $k,
        array_filter(array_keys($param), static fn (string $k): bool => $k[0] === $litera))) ?: 'NULL';
    $stmt = $db->prepare('SELECT id, session_id, email, converted_at FROM cart_abandonments WHERE email IN (' . $locuri('e') . ')
        OR session_id IN (' . $locuri('s') . ') ORDER BY id');
    $stmt->execute($param);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

// Musafirul își scrie emailul în checkout, se loghează, apoi coșul bate din /cos; cineva cu id-ul de dinainte bate și el.
$emailCos = 'cos-abandonat.wp12b@exemplu.ro';
$emailAlt = 'cos-alt.wp12b@exemplu.ro';
$clientCos($emailCos);
$cerere('POST', '/cos/adauga/' . $idMenta, $jar('cos'), ['quantity' => '1']);
$batai($jar('cos'), ['page' => 'checkout', 'email' => $emailCos, 'customer_name' => 'Demo Cos']);
$idCosInainte = $idSesiune($jar('cos'));
$randInainte = $randuriCos([$emailCos], [$idCosInainte]);
copy($jar('cos'), $jar('cos-vechi'));
$r = $cerere('POST', '/contul-meu/login', $jar('cos'), ['email' => $emailCos, 'password' => $parolaCos]);
$idCosDupa = $idSesiune($jar('cos'));
$batai($jar('cos'), ['page' => 'cart']);
$batai($jar('cos-vechi'), ['page' => 'checkout', 'email' => $emailAlt, 'customer_name' => 'Alt Demo']);
$randDupa = $randuriCos([$emailCos, $emailAlt], [$idCosInainte, $idCosDupa]);
$verifica('coșul abandonat: musafirul cu emailul scris, logat apoi (alt id) și cu coșul bătând din /cos are tot un singur rând, același, pe cheia de dinainte de logare; id-ul de dinainte (înlocuit) nu mai scrie nimic',
    $r['cod'] === 302 && $idCosDupa !== '' && $idCosDupa !== $idCosInainte && count($randInainte) === 1 && count($randDupa) === 1
    && (int) $randDupa[0]['id'] === (int) $randInainte[0]['id'] && $randDupa[0]['session_id'] === $idCosInainte && $randDupa[0]['email'] === $emailCos
    && $randDupa[0]['converted_at'] === null, json_encode(['inainte' => $randInainte, 'dupa' => $randDupa]));

// Alt musafir scrie un email, se loghează cu alt cont și comandă după logare: rândul (cu emailul de dinainte) se închide.
$emailComanda = 'cos-comanda.wp12b@exemplu.ro';
$emailScris = 'cos-scris-inainte.wp12b@exemplu.ro';
$clientCos($emailComanda);
$cerere('POST', '/cos/adauga/' . $idMenta, $jar('cos2'), ['quantity' => '1']);
$batai($jar('cos2'), ['page' => 'checkout', 'email' => $emailScris, 'customer_name' => 'Demo Scris']);
$idCos2 = $idSesiune($jar('cos2'));
$cerere('POST', '/contul-meu/login', $jar('cos2'), ['email' => $emailComanda, 'password' => $parolaCos]);
$pagina = $cerere('GET', '/checkout', $jar('cos2'));
preg_match('/name="checkout_form_token" value="([^"]*)"/', $pagina['corp'], $tokCos);
preg_match('/name="checkout_form_rendered_at" value="([^"]*)"/', $pagina['corp'], $randatCos);
sleep(3); // protecția anti-robot refuză comenzile trimise la mai puțin de 2 secunde
$comanda = $cerere('POST', '/checkout', $jar('cos2'), [
    'checkout_form_token' => $tokCos[1] ?? '', 'checkout_form_rendered_at' => $randatCos[1] ?? '',
    'billing_first_name' => 'Demo', 'billing_last_name' => 'Cos', 'billing_phone' => '0700000712', 'billing_email' => $emailComanda,
    'billing_street' => 'Strada Exemplului', 'billing_street_no' => '12', 'billing_city' => 'Cluj-Napoca', 'billing_county' => 'Cluj',
    'billing_postcode' => '400000', 'shipping_same_as_billing' => '1', 'payment_method' => 'cod', 'accept_terms' => '1',
]);
$randComanda = $randuriCos([$emailScris, $emailComanda], [$idCos2, $idSesiune($jar('cos2'))]);
$verifica('… musafirul cu alt email scris înainte, logat și comandând după logare: comanda trece, iar singurul lui rând (cel cu emailul de dinainte) e închis ca făcut comandă',
    str_contains($comanda['location'], '/checkout/succes/') && count($randComanda) === 1 && $randComanda[0]['session_id'] === $idCos2
    && $randComanda[0]['email'] === $emailScris && $randComanda[0]['converted_at'] !== null,
    $comanda['cod'] . ' ' . $comanda['location'] . ' ' . json_encode($randComanda));

// Cronul emailurilor (scripts/abandoned-cart-emails.php), cu rândurile de mai sus mai vechi decât pragul.
$db->prepare('UPDATE cart_abandonments SET last_seen_at = NOW() - INTERVAL 1 DAY WHERE email IN (:a, :b, :c)')
    ->execute(['a' => $emailCos, 'b' => $emailAlt, 'c' => $emailScris]);
$emailuriCronInainte = glob($dosarDev . '/mail/*.eml') ?: [];
exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/scripts/abandoned-cart-emails.php') . ' 2>&1', $iesireCron, $codCron);
$catre = static function (string $email) use ($dosarDev, $emailuriCronInainte): int {
    $n = 0;
    foreach (array_diff(glob($dosarDev . '/mail/*.eml') ?: [], $emailuriCronInainte) as $fisierEml) {
        if (preg_match('/^To:.*' . preg_quote($email, '/') . '/mi', (string) file_get_contents($fisierEml)) === 1) {
            $n++;
        }
    }
    return $n;
};
$verifica('… cronul „Ai uitat produse în coș” trimite un singur email musafirului logat, niciunul celui care a comandat după logare, niciunul adresei scrise pe id-ul înlocuit',
    $codCron === 0 && $catre($emailCos) === 1 && $catre($emailScris) === 0 && $catre($emailComanda) === 0 && $catre($emailAlt) === 0,
    $codCron . ' ' . implode(' ', $iesireCron) . ' → ' . json_encode([$emailCos => $catre($emailCos), $emailScris => $catre($emailScris),
        $emailComanda => $catre($emailComanda), $emailAlt => $catre($emailAlt)]));

// =====================================================================
// D. Adresa vizitatorului (G-m2)
// =====================================================================
$scrie('');
$scrie('== D. Adresa vizitatorului: X-Forwarded-For scris de vizitator nu ocolește limitele; de la un proxy de încredere, vizitatorul real');

$stariCheckout = static fn (): array => $db->query('SELECT ip_address, status FROM checkout_submit_logs ORDER BY id')->fetchAll(PDO::FETCH_NUM);
$stariInregistrare = static fn (): array => $db->query('SELECT ip_address, status FROM register_submit_logs ORDER BY id')->fetchAll(PDO::FETCH_NUM);
$ultima = static fn (array $randuri): array => $randuri === [] ? ['', ''] : $randuri[count($randuri) - 1];
$falsuri = static fn (int $i): array => ['X-Forwarded-For' => '203.0.113.' . $i, 'X-Real-IP' => '198.51.100.' . $i, 'CF-Connecting-IP' => '192.0.2.' . $i];

// Serverul obișnuit (fără proxy de încredere): antetele vizitatorului nu contează.
$golesteJurnale();
for ($i = 1; $i <= 10; $i++) {
    $cerere('POST', '/checkout', null, [], $falsuri($i));
}
$randuri = $stariCheckout();
$verifica('checkout: 10 încercări, fiecare cu alt X-Forwarded-For / X-Real-IP / CF-Connecting-IP, toate scrise cu adresa reală (127.0.0.1)',
    count($randuri) === 10 && array_unique(array_column($randuri, 0)) === ['127.0.0.1'], json_encode($randuri));
$cerere('POST', '/checkout', null, [], $falsuri(99));
$verifica('… a 11-a, cu alt antet încă, e oprită de limită (rate_limited): antetul nu ocolește limita', $ultima($stariCheckout()) === ['127.0.0.1', 'rate_limited'],
    json_encode($ultima($stariCheckout())));
for ($i = 1; $i <= 8; $i++) {
    $cerere('POST', '/register', null, [], $falsuri($i));
}
$randuri = $stariInregistrare();
$cerere('POST', '/register', null, [], $falsuri(99));
$verifica('înregistrarea: 8 încercări cu antete schimbate, scrise cu adresa reală; a 9-a e oprită de limită',
    count($randuri) === 8 && array_unique(array_column($randuri, 0)) === ['127.0.0.1'] && $ultima($stariInregistrare()) === ['127.0.0.1', 'rate_limited'],
    json_encode($stariInregistrare()));

// Serverul din spatele proxy-ului de încredere (127.0.0.1): X-Forwarded-For e crezut, de la dreapta.
$golesteJurnale();
$cerere('POST', '/checkout', null, [], ['X-Forwarded-For' => '203.0.113.50, 198.51.100.7'], null, $bazaProxy);
$verifica('prin proxy-ul de încredere: adresa scrisă e prima din dreapta care nu e a proxy-ului (198.51.100.7), nu ce a scris vizitatorul în stânga',
    $ultima($stariCheckout())[0] === '198.51.100.7', json_encode($stariCheckout()));
for ($i = 1; $i <= 9; $i++) {
    $cerere('POST', '/checkout', null, [], ['X-Forwarded-For' => '203.0.113.' . (100 + $i) . ', 198.51.100.7'], null, $bazaProxy);
}
$cerere('POST', '/checkout', null, [], ['X-Forwarded-For' => '10.9.8.7, 198.51.100.7'], null, $bazaProxy);
$verifica('… a 11-a încercare a aceluiași vizitator, cu altă adresă inventată în stânga, e oprită de limită', $ultima($stariCheckout()) === ['198.51.100.7', 'rate_limited'],
    json_encode($ultima($stariCheckout())));
$cerere('POST', '/checkout', null, [], ['X-Forwarded-For' => '198.51.100.8'], null, $bazaProxy);
$verifica('… alt vizitator (198.51.100.8) nu e oprit', $ultima($stariCheckout()) === ['198.51.100.8', 'invalid_payload'], json_encode($ultima($stariCheckout())));

$golesteJurnale();
for ($i = 1; $i <= 10; $i++) {
    $cerere('POST', '/checkout', null, [], ['X-Forwarded-For' => '2001:db8:1:2::' . dechex($i)], null, $bazaProxy);
}
$randuri = $stariCheckout();
$cerere('POST', '/checkout', null, [], ['X-Forwarded-For' => '2001:db8:1:2:ffff:ffff:ffff:ff'], null, $bazaProxy);
$verifica('IPv6: 10 încercări din același /64, de pe 10 adrese, apoi a 11-a de pe încă una: oprită (limita numără rețeaua /64)',
    $ultima($stariCheckout()) === ['2001:db8:1:2:ffff:ffff:ffff:ff', 'rate_limited'], json_encode($ultima($stariCheckout())));
$verifica('… rândurile păstrează adresa întreagă (2001:db8:1:2::1 … ::a), nu rețeaua',
    array_column($randuri, 0) === array_map(static fn (int $i): string => '2001:db8:1:2::' . dechex($i), range(1, 10)), json_encode(array_column($randuri, 0)));
$cerere('POST', '/checkout', null, [], ['X-Forwarded-For' => '2001:db8:1:3::1'], null, $bazaProxy);
$verifica('… o adresă din alt /64 (2001:db8:1:3::1) nu e oprită', $ultima($stariCheckout()) === ['2001:db8:1:3::1', 'invalid_payload'],
    json_encode($ultima($stariCheckout())));

$clarProxy = 'Parola-Proxy-Demo-12b';
$idClarProxy = $adminNou('clar-proxy.wp12b@exemplu.ro', $clarProxy);
$r = $logare('clar-proxy.wp12b@exemplu.ro', $clarProxy, $jar('clar-proxy'), ['X-Forwarded-For' => '203.0.113.1, 198.51.100.20'], $bazaProxy);
$jurnal = $randuriJurnal($idClarProxy);
$verifica('jurnalul adminului prin proxy-ul de încredere: adresa vizitatorului real (198.51.100.20)',
    $aIntrat($r) && count($jurnal) === 1 && (string) ($jurnal[0]['ip'] ?? '') === '198.51.100.20', json_encode($jurnal));

$subiect = 'Subiect demo WP-12b ' . bin2hex(random_bytes(3));
$mesaj = json_encode(['name' => 'Demo Securitate', 'email' => 'contact.wp12b@exemplu.ro', 'subject' => $subiect, 'message' => 'Mesaj demo.'], JSON_UNESCAPED_UNICODE) ?: '';
$cerere('POST', '/contact/send', null, [], ['X-Forwarded-For' => '203.0.113.2, 198.51.100.21'], $mesaj, $bazaProxy);
$cerere('POST', '/contact/send', null, [], ['X-Forwarded-For' => '198.51.100.22'], str_replace($subiect, $subiect . ' fals', $mesaj));
$verifica('mesajul de contact: prin proxy, adresa vizitatorului real; pe serverul obișnuit, adresa reală, nu antetul',
    $unu('SELECT ip_address FROM contact_form_messages WHERE subject = :s', ['s' => $subiect]) === '198.51.100.21'
    && $unu('SELECT ip_address FROM contact_form_messages WHERE subject = :s', ['s' => $subiect . ' fals']) === '127.0.0.1');

// Mentenanța: IP-urile cu acces se compară cu adresa vizitatorului după aceeași regulă.
$setare = static function (string $cheie, string $valoare) use ($db): void {
    $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (:k, :v) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute(['k' => $cheie, 'v' => $valoare]);
};
$setare('maintenance_enabled', '1');
$setare('maintenance_allowed_ips', '198.51.100.30');
$coduri = [
    'fals' => $cerere('GET', '/contact', null, [], ['X-Forwarded-For' => '198.51.100.30'])['cod'],
    'proxy' => $cerere('GET', '/contact', null, [], ['X-Forwarded-For' => '198.51.100.30'], null, $bazaProxy)['cod'],
    'proxy-stanga' => $cerere('GET', '/contact', null, [], ['X-Forwarded-For' => '198.51.100.30, 198.51.100.31'], null, $bazaProxy)['cod'],
];
$setare('maintenance_allowed_ips', '127.0.0.1');
$coduri['local'] = $cerere('GET', '/contact')['cod'];
// O adresă din listă scrisă altfel decât forma canonică pe care o dă IpClient (IPv4 în formă IPv6, litere mari, zerouri)
// e tot aceeași adresă; o rețea nu se potrivește (lista e de adrese, ca înainte).
$setare('maintenance_allowed_ips', '::ffff:127.0.0.1');
$coduri['local-ca-ipv6'] = $cerere('GET', '/contact')['cod'];
$setare('maintenance_allowed_ips', '127.0.0.0/8');
$coduri['retea'] = $cerere('GET', '/contact')['cod'];
$setare('maintenance_allowed_ips', '198.51.100.9, 2001:DB8:0:0::30');
$coduri['proxy-ipv6'] = $cerere('GET', '/contact', null, [], ['X-Forwarded-For' => '2001:db8::30'], null, $bazaProxy)['cod'];
$coduri['proxy-ipv6-alta'] = $cerere('GET', '/contact', null, [], ['X-Forwarded-For' => '2001:db8::31'], null, $bazaProxy)['cod'];
$setare('maintenance_enabled', '0');
$verifica('mentenanța: un X-Forwarded-For cu IP-ul permis, scris de vizitator, nu deschide site-ul (503); de la proxy-ul de încredere da (200), dar nu când IP-ul permis e doar în stânga (503); REMOTE_ADDR permis, ca înainte (200), și scris ca IPv6 (200); o rețea nu (503); IPv6 cu litere mari și zerouri da (200), alta nu (503)',
    $coduri === ['fals' => 503, 'proxy' => 200, 'proxy-stanga' => 503, 'local' => 200, 'local-ca-ipv6' => 200, 'retea' => 503, 'proxy-ipv6' => 200,
        'proxy-ipv6-alta' => 503], json_encode($coduri));

// =====================================================================
// Final: totul la loc
// =====================================================================
$scrie('');
$scrie('== Final: baza, sesiunile și emailurile la loc');
$puneLaLoc();
$sumeDupa = $sumeBaza();
$diferite = array_keys(array_diff_assoc($sumeDupa, $sumeInainte) + array_diff_assoc($sumeInainte, $sumeDupa));
$verifica('baza e exact cum era (CHECKSUM TABLE pe toate cele ' . count($sumeInainte) . ' tabele)', $diferite === [], implode(', ', $diferite));
$verifica('al doilea server e oprit', $cerere('GET', '/health', null, [], [], null, $bazaProxy)['cod'] === 0);
$eroriNoi = is_file($fisierErori) ? (string) @file_get_contents($fisierErori, false, null, $pozitieErori) : '';
$verifica('nicio eroare PHP nouă în ' . basename($fisierErori), trim($eroriNoi) === '', mb_substr($eroriNoi, 0, 800));

$scrie('');
$scrie(sprintf('Rezultat: %d trecute, %d eșuate. Raport: %s', $trecute, $esuate, $fisierRaport));
exit($esuate === 0 ? 0 : 1);
