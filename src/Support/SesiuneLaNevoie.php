<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Sesiunea PHP doar unde e nevoie (WP-40, SEO partea B, B6; PLAN-SEO §8.12): un vizitator nou (fără cookie-ul sesiunii)
 * care deschide o pagină publică (GET sau HEAD) nu primește sesiune: niciun fișier în storage/sessions, niciun cookie,
 * deci pagina poate veni din cache-ul de pagini și din CDN. Sesiunea pornește, ca înainte:
 *  - la orice POST (coșul, comanda, formularele);
 *  - când browserul are deja cookie-ul sesiunii (un coș, o logare, un mesaj de după un formular);
 *  - pe căile care o folosesc din prima cerere: adminul, coșul și comanda, plata, contul, logarea, înregistrarea,
 *    Google, API-ul (coșul, livrarea), confirmările, newsletterul, adresele „mereu deschise” ale pluginurilor
 *    (`SeoRedirects::caiExcluse()`), plus `CAI_CU_SESIUNE`.
 *
 * Plasa de siguranță: dacă o pagină fără sesiune scrie totuși ceva în `$_SESSION` (un mesaj, o alegere), pagina e
 * ținută în buffer și, la final, sesiunea pornește și primește ce s-a scris (cookie-ul pleacă odată cu pagina), deci
 * nimic nu se pierde. Doar dacă antetele au plecat deja (o descărcare trimisă bucată cu bucată) scrierea nu se poate
 * păstra: un rând în jurnalul PHP.
 *
 * Comutatorul: setarea `sesiune_doar_la_nevoie` (implicit pornită; înghețată la „0” pe site-ul înghețat: fiecare pagină pornește
 * sesiunea, ca azi). public/index.php amână sesiunea (ANDAXI_SESIUNE_AMANATA) până citește setările; celelalte intrări
 * (liniile de comandă cu ANDAXI_CU_SESIUNE) o pornesc din bootstrap.php, ca înainte.
 */
final class SesiuneLaNevoie
{
    /** Căi (prefixe) care pornesc sesiunea chiar la GET, pe lângă căile excluse ale hărții SEO. */
    public const CAI_CU_SESIUNE = ['/cos', '/checkout', '/plata', '/contul-meu', '/cont', '/login', '/register', '/logout', '/auth',
        '/api', '/newsletter', '/gdpr-agreements', '/review-form', '/_andaxi', '/admin'];

    /**
     * Adresele API citite la fiecare pagină (sumarul coșului flotant, căutarea, catalogul, blogul, lista lockerelor): pentru
     * un vizitator nou doar citesc (un coș gol), deci nici ele nu pornesc sesiunea (plasa de siguranță rămâne).
     */
    public const API_FARA_SESIUNE = ['/api/cart/summary', '/api/cart/avertismente', '/api/products/best-sellers', '/api/search/products',
        '/api/shop/catalog', '/api/blog', '/api/fan/localities', '/api/fan/lockers'];

    private static bool $amanata = false;
    private static bool $terminat = false;

    /** Pornește sesiunea cu setările ei (30 de zile, dosarul site-ului, cookie Lax, HttpOnly; vezi bootstrap.php). */
    public static function porneste(): void
    {
        if (session_status() !== PHP_SESSION_NONE || headers_sent()) {
            return;
        }
        $viataSesiune = 60 * 60 * 24 * 30; // 30 de zile
        // Dosarul îl face Paths dacă lipsește, cu gardul .htaccess în el.
        $dosarSesiuni = Paths::stocareSite('sessions');
        if (is_dir($dosarSesiuni) && is_writable($dosarSesiuni)) {
            ini_set('session.save_path', $dosarSesiuni);
            // Curățenia o facem noi, pe dosarul nostru: altfel fișierele s-ar strânge la nesfârșit, fiindcă gc-ul
            // serverului nu se uită aici.
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

    /** Cererea are nevoie de sesiune de la început? */
    public static function trebuie(string $metoda, string $uri, array $cookie): bool
    {
        if (!in_array(strtoupper($metoda), ['GET', 'HEAD'], true)) {
            return true;
        }
        if (isset($cookie[session_name()]) && (string) $cookie[session_name()] !== '') {
            return true;
        }
        $cale = strtolower((string) (parse_url($uri, PHP_URL_PATH) ?? '/'));
        $cale = '/' . ltrim($cale, '/');
        foreach (self::API_FARA_SESIUNE as $prefix) {
            if ($cale === $prefix || str_starts_with($cale, $prefix . '/')) {
                return false;
            }
        }
        foreach (self::CAI_CU_SESIUNE as $prefix) {
            if ($cale === $prefix || str_starts_with($cale, $prefix . '/') || str_starts_with($cale, $prefix . '?')) {
                return true;
            }
        }
        return SeoRedirects::eExclusa($cale);
    }

    /**
     * Pentru public/index.php, după citirea setărilor: pornește sesiunea sau, pentru o pagină publică a unui vizitator nou
     * (cu comutatorul pornit), o lasă oprită, cu plasa de siguranță.
     *
     * @param array<string, mixed> $setari
     */
    public static function decide(array $setari): void
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_NONE) {
            return;
        }
        $pornit = (string) ($setari['sesiune_doar_la_nevoie'] ?? '1') === '1';
        if (!$pornit || self::trebuie((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), (string) ($_SERVER['REQUEST_URI'] ?? '/'), $_COOKIE)) {
            self::porneste();
            return;
        }
        $_SESSION = [];
        self::$amanata = true;
        // Toată pagina în buffer, ca, dacă ceva scrie în sesiune, cookie-ul ei să mai poată pleca la final.
        ob_start();
        register_shutdown_function([self::class, 'laFinal']);
    }

    /** Pagina de acum a rulat fără sesiune (pagină publică, vizitator nou)? */
    public static function amanata(): bool
    {
        return self::$amanata && session_status() !== PHP_SESSION_ACTIVE;
    }

    /** La finalul paginii: ce s-a scris în `$_SESSION` fără sesiune ajunge într-o sesiune nouă (o dată). */
    public static function laFinal(): void
    {
        if (!self::$amanata || self::$terminat) {
            return;
        }
        self::$terminat = true;
        $date = is_array($_SESSION ?? null) ? $_SESSION : [];
        // Coșul gol pe care Cart îl face la prima citire (Cart::boot) nu e o scriere: fără el, nimic de păstrat.
        if (isset($date['cart']) && $date['cart'] === ['items' => [], 'coupon' => null, 'points_requested' => 0, 'county' => 'Bucuresti']) {
            unset($date['cart']);
        }
        if ($date === [] || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (headers_sent()) {
            error_log('ANDAXI SesiuneLaNevoie: o pagină publică a scris în sesiune după ce antetele au plecat ('
                . (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/') . ', cheile '
                . implode(', ', array_slice(array_map('strval', array_keys($date)), 0, 10)) . '); scrierea nu s-a păstrat.');
            return;
        }
        self::porneste();
        foreach ($date as $cheie => $valoare) {
            $_SESSION[$cheie] = $valoare;
        }
        session_write_close();
    }
}
