<?php

declare(strict_types=1);

// Întâi autoloader-ul (nu scrie nimic și nu pornește nimic): căile site-ului (.env, sesiunile, jurnalele) le dă
// App\Support\Paths, aceleași azi ca până acum, iar în așezarea din Etapa 2 ale dosarului site-ului (stub-ul din
// deploy/stub/), niciodată ale versiunii de cod comune.
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/src/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

// Jurnalul erorilor PHP al site-ului: storage/logs/php.log (în Etapa 2, site/storage/logs/php.log), dacă serverul nu
// are deja unul (Paths::alegeJurnalulPhp; o pagină l-a ales deja în public/index.php, aici contează linia de comandă).
App\Support\Paths::alegeJurnalulPhp();

/**
 * Sesiunea ține coșul, așa că trebuie să trăiască.
 *
 * Până acum se pornea fără nicio setare, deci lua ce zicea serverul: de obicei
 * 24 de minute de nemișcare și un cookie care moare la închiderea browserului.
 * Pe găzduire comună e și mai rău — curățenia altui site de pe aceeași mașină
 * poate mătura fișierele noastre mult mai devreme. De aici „am ieșit 5 minute
 * și mi s-a golit coșul".
 *
 * Acum: fișierele stau la noi, nu în folderul comun, iar viața lor e de 30 de
 * zile. Coșul rămâne întreg peste pauze și peste închiderea browserului.
 * Partea de administrare are limita ei de nemișcare, în `Auth` — altfel o
 * sesiune de 30 de zile ar ține un admin logat o lună. Dosarul e al site-ului
 * (`Paths::stocareSite('sessions')`: azi storage/sessions, ca înainte; în Etapa 2
 * site/storage/sessions), deci două site-uri pe aceeași versiune de cod nu-și
 * văd și nu-și curăță niciodată sesiunile unul altuia.
 *
 * Linia de comandă (cronurile, scripts/migreaza.php, uneltele) n-are cookie, deci
 * n-are ce sesiune să continue: până acum fiecare rulare lăsa un fișier de sesiune
 * gol în storage/sessions („Erori moștenite” 5 din docs/DEZVOLTARE.md), care doar
 * ocupa loc până la curățenia de 30 de zile. Acum, în CLI, `$_SESSION` e doar un
 * array gol (codul care citește sau scrie în el merge la fel), fără fișier.
 * Un script CLI care chiar are nevoie de o sesiune PHP definește `ANDAXI_CU_SESIUNE`
 * înainte de require. Serverul de dezvoltare (`php -S`, SAPI „cli-server”) și
 * site-urile nu sunt atinse.
 */
// Fluxul de produse pentru Google (/feed/google-merchant.xml) e doar citire și
// îl cere un robot o dată pe zi: fără sesiune, deci fără cookie și fără un
// fișier de sesiune nou la fiecare preluare. La fel pingul monitorizării
// (/_andaxi/ping, la 5 minute; App\Support\Ping). Doar căile exacte, doar GET/HEAD.
$faraSesiune = PHP_SAPI !== 'cli'
    && in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')), ['GET', 'HEAD'], true)
    && in_array((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? ''),
        ['/feed/google-merchant.xml', App\Support\Ping::CALE], true);

if (PHP_SAPI === 'cli' && !defined('ANDAXI_CU_SESIUNE')) {
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        $_SESSION = [];
    }
} elseif (session_status() === PHP_SESSION_NONE && !$faraSesiune) {
    $viataSesiune = 60 * 60 * 24 * 30; // 30 de zile

    // Dosarul îl face Paths dacă lipsește, cu gardul .htaccess în el.
    $dosarSesiuni = App\Support\Paths::stocareSite('sessions');
    if (is_dir($dosarSesiuni) && is_writable($dosarSesiuni)) {
        ini_set('session.save_path', $dosarSesiuni);
        // Curățenia o facem noi, pe dosarul nostru: altfel fișierele s-ar
        // strânge la nesfârșit, fiindcă gc-ul serverului nu se uită aici.
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '200');
    }
    ini_set('session.gc_maxlifetime', (string) $viataSesiune);
    ini_set('session.use_strict_mode', '1');

    $peHttps = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params([
        'lifetime' => $viataSesiune,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $peHttps,
    ]);

    session_start();
}

App\Support\Env::load(App\Support\Paths::env());

$config = require __DIR__ . '/config/app.php';
date_default_timezone_set($config['timezone']);
