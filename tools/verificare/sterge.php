<?php

declare(strict_types=1);

/**
 * Ștergerea copiei de pe site-ul de verificare, pe server, prin SSH (docs/VERIFICARE.md), după fiecare sesiune de
 * verificare (decizia D1; oricum copia se închide singură după cel mult 7 zile):
 *
 *   $PHP tools/verificare/sterge.php          # arată ce șterge și întreabă „da”
 *   $PHP tools/verificare/sterge.php --da     # fără întrebare (de exemplu dintr-un script)
 *
 * Șterge, doar în site-ul de verificare:
 * - din baza copiei: toate tabelele, vederile, rutinele și evenimentele (declanșatoarele pleacă odată cu tabelele),
 *   doar dacă baza are tabelul `_verificare` (e o copie încărcată, sau o încărcare oprită la jumătate: încărcarea,
 *   WP-09, scrie `_verificare` cu `anonimizat = 0` înaintea oricărui tabel cu date și abia la final `anonimizat = 1`
 *   și `incarcat-la`) sau e deja goală;
 * - `storage/verificare/incarcat-la` (poarta arată apoi „Copia de verificare lipsește”), emailurile scrise ca fișiere
 *   (`storage/verificare/mail/`) și dosarul temporar (`storage/verificare/tmp/`);
 * - cache-urile copiei (`storage/cache/`: paginile, fluxul de produse, pozele de distribuire) și sesiunile
 *   (`storage/sessions/`: coșurile și logările de pe copie);
 * - pozele inventate ale copiei demonstrative (`public/uploads/demo/`, `incarca.php --demo`);
 * - copia codului vechi de lângă site (`~/domains/<domeniul-verificare>/cod-vechi/`, WP-09).
 *
 * Dosarul temporar are și sarea unei încărcări neterminate (`incarca.php`, pentru `--reia`): pleacă și ea.
 *
 * Rămân: `.env`, licența și cheia ei, contorul emailurilor zilei, jurnalele și rezultatele verificărilor pe site-urile
 * live (`storage/verificare/sonde/`: le ștergi tu, după ce ni le trimiți) și valorile private ale înghețării
 * (`site-uri/<site>/privat/`; la final un rând ATENȚIE pentru fiecare, ca să le ștergi când nu mai încarci site-ul). Tipărește doar numere și DA/NU.
 *
 * Refuză (cod 1, fără să șteargă nimic) dacă:
 * - PHP-ul e mai vechi decât `php_min` (config/app.php);
 * - codul nu e exact în `~/domains/<domeniu>/public_html` sau `.env`-ul de aici nu are `SITE_COPIE_VERIFICARE=1`
 *   (deci niciodată în dosarul unui site live: contul SSH e comun);
 * - `.env` nu are `DB_DATABASE`; baza nu se deschide, are tabele fără `_verificare` (pare baza unui site) sau e scrisă
 *   în `.env`-ul altui site din cont;
 * - fără `--da`, răspunsul nu e „da”.
 *
 * Fișierul folosește doar sintaxa PHP 7.0, ca un PHP prea vechi să primească mesajul, nu o eroare de sintaxă.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);

function sterge_opreste($mesaj)
{
    fwrite(STDERR, 'EROARE: ' . $mesaj . "\n");
    exit(1);
}

function sterge_spune($text)
{
    fwrite(STDOUT, $text . "\n");
}

$textConfig = (string) @file_get_contents($radacina . '/config/app.php');
$phpMin = preg_match("/'php_min'\\s*=>\\s*'([0-9.]+)'/", $textConfig, $m) === 1 ? $m[1] : '8.3';
if (version_compare(PHP_VERSION, $phpMin, '<')) {
    sterge_opreste('PHP ' . PHP_VERSION . ' e mai vechi decât minimul codului (' . $phpMin . '). Rulează cu un PHP mai nou, '
        . 'de exemplu /opt/alt/php83/usr/bin/php (versiunile: ls /opt/alt | grep php). Nu am șters nimic.');
}
require_once $radacina . '/tools/verificare/lib/php-cli.php';
$lipsaExtensii = \Unelte\Verificare\PhpCli::lipsa(array('pdo_mysql'), 'tools/verificare/sterge.php');
if ($lipsaExtensii !== null) {
    sterge_opreste($lipsaExtensii . '. Nu am șters nimic.');
}

$faraIntrebare = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--da') {
        $faraIntrebare = true;
        continue;
    }
    sterge_opreste('argument necunoscut: ' . $argument . ' (vezi capul fișierului)');
}

require_once $radacina . '/src/Support/Env.php';
require_once $radacina . '/src/Support/Verificare.php';
require_once $radacina . '/tools/verificare/lib/cont.php';

use App\Support\Env;
use App\Support\Verificare;

/**
 * Șterge ce e într-un dosar (nu dosarul), fără să urmeze legăturile (o legătură se șterge ea însăși), păstrând la
 * orice nivel fișierele numite în $pastreaza (`.gitkeep` și gardul `.htaccess`: sunt ale codului) și dosarele care
 * rămân astfel negoale. Întoarce câte fișiere a șters.
 */
function sterge_continut($dosar, $pastreaza, $tipar = null)
{
    if (is_link($dosar) || !is_dir($dosar)) {
        return 0;
    }
    $intrari = @scandir($dosar);
    if (!is_array($intrari)) {
        return 0;
    }
    $sterse = 0;
    foreach ($intrari as $nume) {
        if ($nume === '.' || $nume === '..' || in_array($nume, $pastreaza, true)) {
            continue;
        }
        $cale = $dosar . '/' . $nume;
        if (is_link($cale) || is_file($cale)) {
            if (($tipar === null || preg_match($tipar, $nume) === 1) && @unlink($cale)) {
                $sterse++;
            }
            continue;
        }
        if (is_dir($cale)) {
            $sterse += sterge_continut($cale, $pastreaza, $tipar);
            @rmdir($cale);
        }
    }
    return $sterse;
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
    sterge_opreste('codul nu e în ~/domains/<domeniu>/public_html: sterge.php rulează doar din dosarul site-ului de verificare. '
        . 'Nu am șters nimic.');
}
$caleEnv = Verificare::caleEnv();
if (is_link($caleEnv) || !is_file($caleEnv)) {
    sterge_opreste('.env lipsește aici: site-ul de verificare nu e configurat (configureaza.php). Nu am șters nimic.');
}
$cheiEnv = Verificare::citesteEnv((string) @file_get_contents($caleEnv));
if (trim(isset($cheiEnv['SITE_COPIE_VERIFICARE']) ? $cheiEnv['SITE_COPIE_VERIFICARE'] : '') !== '1') {
    sterge_opreste('.env-ul de aici nu are SITE_COPIE_VERIFICARE=1: nu e site-ul de verificare (pare un site adevărat). Nu am șters nimic.');
}
Env::load($caleEnv);

// ── Baza ──

$dbNume = trim((string) Env::get('DB_DATABASE', ''));
$dbGazda = (string) Env::get('DB_HOST', 'localhost');
if ($dbNume === '') {
    sterge_opreste('DB_DATABASE lipsește din .env: nu știu ce bază e a copiei. Nu am șters nimic.');
}
$altSite = \Unelte\Verificare\Cont::bazaAltuiSite($acasa, $dbNume, $dbGazda);
if ($altSite !== null) {
    sterge_opreste('baza din .env e scrisă și în .env-ul altui site din cont (' . $altSite . '); nu o ating. Nu am șters nimic.');
}
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbGazda, (int) Env::get('DB_PORT', 3306), $dbNume),
        (string) Env::get('DB_USERNAME', ''),
        (string) Env::get('DB_PASSWORD', ''),
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5)
    );
} catch (PDOException $e) {
    $codDb = preg_match('/\[(\d{4})\]/', $e->getMessage(), $m) === 1 ? (int) $m[1] : (int) $e->getCode();
    sterge_opreste('baza nu se deschide (eroarea ' . $codDb . '). Nu am șters nimic.');
}
$numara = function ($sql) use ($pdo) {
    return (int) $pdo->query($sql)->fetchColumn();
};
$tabele = $pdo->query("SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_NUM);
$marcaj = false;
$deSters = array('BASE TABLE' => array(), 'VIEW' => array());
foreach ($tabele as $rand) {
    $marcaj = $marcaj || (string) $rand[0] === '_verificare';
    $deSters[(string) $rand[1] === 'VIEW' ? 'VIEW' : 'BASE TABLE'][] = (string) $rand[0];
}
if ($tabele !== array() && !$marcaj) {
    sterge_opreste('baza are ' . count($tabele) . ' tabele și nu e o copie de verificare (lipsește tabelul _verificare): pare baza unui site. '
        . 'Nu am șters nimic.');
}
$rutine = $pdo->query('SELECT ROUTINE_TYPE, ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_NUM);
$evenimente = $pdo->query('SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);

$dosarCopie = Verificare::dosar();
$dosarCod = dirname($radacinaReala);
$codVechi = $dosarCod . '/cod-vechi';
sterge_spune('Ștergerea copiei de pe site-ul de verificare https://' . $gazda);
sterge_spune('  baza: ' . count($deSters['BASE TABLE']) . ' tabele, ' . count($deSters['VIEW']) . ' vederi, ' . count($rutine) . ' rutine, '
    . count($evenimente) . ' evenimente' . ($marcaj ? ' (copie de verificare, cu _verificare)' : ' (goală)'));
$pozeDemo = \App\Support\Paths::uploads('demo');
sterge_spune('  fișierele copiei: incarcat-la, emailurile ca fișiere, temporarele, cache-ul, sesiunile'
    . (is_dir($pozeDemo) || is_link($pozeDemo) ? ', pozele copiei demonstrative (public/uploads/demo/)' : '')
    . (is_dir($codVechi) && !is_link($codVechi) ? ', codul vechi (cod-vechi/)' : ''));
if (!$faraIntrebare) {
    fwrite(STDOUT, 'Scrie „da” ca să ștergi (orice altceva: nu se șterge nimic): ');
    fflush(STDOUT);
    $raspuns = fgets(STDIN);
    if ($raspuns === false || trim($raspuns) !== 'da') {
        sterge_opreste('n-ai scris „da”. Nu am șters nimic.');
    }
}

// ── Ștergerea ──

$cita = function ($nume) {
    return '`' . str_replace('`', '``', $nume) . '`';
};
$sterse = array('tabele' => 0, 'vederi' => 0, 'rutine' => 0, 'evenimente' => 0);
// `_verificare` ultimul: dacă ceva se oprește la jumătate, baza rămâne o copie de verificare (sterge.php o termină).
usort($deSters['BASE TABLE'], function ($a, $b) {
    return ($a === '_verificare' ? 1 : 0) - ($b === '_verificare' ? 1 : 0);
});
try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($deSters['VIEW'] as $nume) {
        $pdo->exec('DROP VIEW IF EXISTS ' . $cita($nume));
        $sterse['vederi']++;
    }
    foreach ($deSters['BASE TABLE'] as $nume) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $cita($nume));
        $sterse['tabele']++;
    }
    foreach ($rutine as $rand) {
        $pdo->exec('DROP ' . ((string) $rand[0] === 'FUNCTION' ? 'FUNCTION' : 'PROCEDURE') . ' IF EXISTS ' . $cita((string) $rand[1]));
        $sterse['rutine']++;
    }
    foreach ($evenimente as $nume) {
        $pdo->exec('DROP EVENT IF EXISTS ' . $cita((string) $nume));
        $sterse['evenimente']++;
    }
    $ramase = $numara('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
} catch (PDOException $e) {
    // Mesajul serverului poate avea utilizatorul bazei: doar codul.
    $codDb = preg_match('/\[(\d{4})\]/', $e->getMessage(), $m) === 1 ? (int) $m[1] : (int) $e->getCode();
    sterge_opreste('baza nu s-a putut goli (eroarea ' . $codDb . ' după ' . $sterse['tabele'] . ' tabele; 1142 = utilizatorul bazei '
        . 'n-are dreptul DROP, hPanel → Databases). Rulează din nou după ce repari; fișierele n-au fost atinse.');
}
$pdo = null;

$pastreaza = array('.gitkeep', '.gitignore', '.htaccess');
$incarcat = $dosarCopie . '/incarcat-la';
$incarcareStearsa = !file_exists($incarcat) || @unlink($incarcat);
$fisiere = array(
    'emailuri' => sterge_continut($dosarCopie . '/mail', $pastreaza),
    'temporare' => sterge_continut($dosarCopie . '/tmp', $pastreaza),
    'cache' => sterge_continut(\App\Support\Paths::stocare('cache'), $pastreaza),
    'sesiuni' => sterge_continut(\App\Support\Paths::stocare('sessions'), $pastreaza, '/^sess_/'),
    'poze_demo' => 0,
);
// Pozele inventate ale copiei demonstrative: tot dosarul (nu sunt ale codului); o legătură se șterge ea însăși.
if (is_link($pozeDemo)) {
    @unlink($pozeDemo);
} elseif (is_dir($pozeDemo)) {
    $fisiere['poze_demo'] = sterge_continut($pozeDemo, array());
    @rmdir($pozeDemo);
}
$codVechiSters = 0;
$codVechiBun = true;
if (is_link($codVechi)) {
    $codVechiBun = @unlink($codVechi);
} elseif (is_dir($codVechi)) {
    $codVechiSters = sterge_continut($codVechi, array());
    $codVechiBun = @rmdir($codVechi);
}
clearstatcache();

sterge_spune('');
sterge_spune('  ' . str_pad($ramase === 0 ? 'DA' : 'NU', 6) . 'baza e goală: ' . $sterse['tabele'] . ' tabele, ' . $sterse['vederi'] . ' vederi, '
    . $sterse['rutine'] . ' rutine, ' . $sterse['evenimente'] . ' evenimente șterse' . ($ramase > 0 ? ' (au rămas ' . $ramase . ')' : ''));
sterge_spune('  ' . str_pad($incarcareStearsa && !file_exists($incarcat) ? 'DA' : 'NU', 6) . 'incarcat-la șters (site-ul arată „Copia de verificare lipsește”)');
sterge_spune('  INFO  fișiere șterse: ' . $fisiere['emailuri'] . ' emailuri, ' . $fisiere['temporare'] . ' temporare, ' . $fisiere['cache']
    . ' din cache, ' . $fisiere['sesiuni'] . ' sesiuni' . ($fisiere['poze_demo'] > 0 ? ', ' . $fisiere['poze_demo'] . ' poze ale copiei demonstrative' : ''));
sterge_spune('  ' . str_pad($codVechiBun && !file_exists($codVechi) ? 'DA' : 'NU', 6) . 'codul vechi de lângă site (cod-vechi/): '
    . ($codVechiSters > 0 ? $codVechiSters . ' fișiere șterse' : 'nu era'));
$gasite = glob($dosarCopie . '/sonde/*');
$sonde = is_array($gasite) ? count($gasite) : 0;
if ($sonde > 0) {
    sterge_spune('  INFO  în storage/verificare/sonde/ mai sunt ' . $sonde . ' fișiere ale verificărilor pe site-urile live: '
        . 'după ce ni le trimiți, rm storage/verificare/sonde/*');
}
// Valorile private ale înghețării (site-uri/<site>/privat/, puse lângă pachet pentru încărcare): nu sunt ale copiei, deci nu
// le șterge sterge.php, dar spune că sunt încă aici (adresele și telefonul operatorului, numele reprezentantului).
$privat = glob($radacina . '/site-uri/*/privat', GLOB_ONLYDIR);
foreach (is_array($privat) ? $privat : array() as $dosarPrivat) {
    $sitePrivat = basename(dirname($dosarPrivat));
    sterge_spune('  ATENȚIE site-uri/' . $sitePrivat . '/privat/ e încă aici (valorile private ale înghețării ' . $sitePrivat . ': adresele de email, '
        . 'telefonul și numele operatorului): dacă nu mai încarci ' . $sitePrivat . ' curând, rm -r site-uri/' . $sitePrivat . '/privat');
}
sterge_spune('');
if ($ramase > 0 || file_exists($incarcat) || file_exists($codVechi) || file_exists($pozeDemo) || is_link($pozeDemo)) {
    sterge_opreste('ceva nu s-a șters (rândurile NU de mai sus). Rulează din nou; dacă rămâne așa, trimite-ne textul.');
}
sterge_spune('GATA: copia a fost ștearsă');
exit(0);
