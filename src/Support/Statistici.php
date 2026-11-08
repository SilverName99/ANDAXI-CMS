<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Statisticile site-ului (pluginul `statistici`; docs/PLUGINURI.md, „Statistici site”): o analiză proprie a vizitelor, fără
 * cookie-uri, fără nimic ținut în browser (nici localStorage, nici sessionStorage) și fără amprentarea browserului.
 *
 * **Vizitatorul** e, pe o zi, `sha256(sarea zilei + site + IP + User-Agent)` (primii 16 octeți). Sarea e aleatoare, ținută într-un
 * fișier din `storage/statistici/` și ștearsă a doua zi, deci a doua zi nimeni (nici noi) nu mai poate lega hash-ul de un IP.
 * Adresa IP și User-Agent-ul nu se scriu nicăieri: doar familia browserului, a sistemului și felul dispozitivului.
 *
 * **Colectarea**: un script mic (`scriptPagina`, sub 2 KB, agățat de `layout.final_body`) trimite, cu `navigator.sendBeacon` (sau
 * `fetch` cu `keepalive`), pagina văzută și, la ieșire, timpul activ, la `POST /api/statistici` (`intercepteaza`, din
 * public/index.php, înaintea sesiunii: niciun cookie, niciun fișier de sesiune). Scriptul e în HTML, deci merge și pe paginile
 * servite din cache sau dintr-un CDN. Coșul, începutul comenzii, comanda și căutările pe site se notează pe server
 * (`laCos`, `laCheckout`, `laComanda`, `cautare`), cu același hash, calculat din cererea lor.
 *
 * **Nu se numără**: administratorii logați, roboții (`Campanii::eRobot`), preîncărcările (scriptul așteaptă ca pagina să fie
 * văzută), browserele cu „Do Not Track” sau „Global Privacy Control” (setarea `statistici_respecta_dnt`, implicit da).
 *
 * Agregarea: App\Support\StatisticiAgregare; rapoartele: App\Support\StatisticiRaport.
 */
final class Statistici
{
    public const CALE = '/api/statistici';

    public const TIP_VIZUALIZARE = 1;
    public const TIP_PLECARE = 2;
    public const TIP_COS = 3;
    public const TIP_CHECKOUT = 4;
    public const TIP_COMANDA = 5;
    public const TIP_CAUTARE = 6;

    /** Sursele, în ordinea din rapoarte. */
    public const SURSE = [
        'direct' => 'Direct',
        'google' => 'Google (căutare)',
        'google_ads' => 'Google Ads',
        'bing' => 'Bing',
        'cautare' => 'Alte motoare de căutare',
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
        'pinterest' => 'Pinterest',
        'linkedin' => 'LinkedIn',
        'x' => 'X / Twitter',
        'whatsapp' => 'WhatsApp',
        'email' => 'Email / newsletter',
        'campanie' => 'Campanie (utm)',
        'alt_site' => 'Alte site-uri',
    ];

    public const TIPURI_PAGINA = [
        'acasa' => 'Prima pagină', 'produs' => 'Produs', 'categorie' => 'Categorie', 'magazin' => 'Magazin', 'blog' => 'Blog',
        'articol' => 'Articol', 'cos' => 'Coș', 'checkout' => 'Checkout', 'comanda' => 'Comandă plasată', 'campanie' => 'Pagina campaniei',
        'cautare' => 'Căutare', 'pagina' => 'Pagină',
    ];

    /** Câte evenimente primește un vizitator într-un minut (peste: 429). */
    public const LIMITA_MINUT = 60;
    /** Cel mai mare corp primit (octeți). */
    public const MAX_CORP = 2048;

    /** Rețelele sociale: domeniul care trimite (sau se termină cu el) → sursa. */
    private const DOMENII_SOCIALE = [
        'facebook.com' => 'facebook', 'fb.com' => 'facebook', 'fb.me' => 'facebook', 'messenger.com' => 'facebook',
        'instagram.com' => 'instagram',
        'tiktok.com' => 'tiktok',
        'youtube.com' => 'youtube', 'youtu.be' => 'youtube',
        'pinterest.com' => 'pinterest', 'pin.it' => 'pinterest',
        'linkedin.com' => 'linkedin', 'lnkd.in' => 'linkedin',
        't.co' => 'x', 'twitter.com' => 'x', 'x.com' => 'x',
        'whatsapp.com' => 'whatsapp', 'wa.me' => 'whatsapp',
    ];
    /** utm_source (litere mici) → sursa. */
    private const UTM_SOCIALE = [
        'facebook' => 'facebook', 'fb' => 'facebook', 'meta' => 'facebook', 'instagram' => 'instagram', 'ig' => 'instagram',
        'tiktok' => 'tiktok', 'tt' => 'tiktok', 'youtube' => 'youtube', 'yt' => 'youtube', 'pinterest' => 'pinterest',
        'linkedin' => 'linkedin', 'twitter' => 'x', 'x' => 'x', 'whatsapp' => 'whatsapp', 'wa' => 'whatsapp',
        'bing' => 'bing',
    ];
    private const MOTOARE = ['duckduckgo.com', 'yahoo.com', 'yandex.ru', 'yandex.com', 'ecosia.org', 'search.brave.com', 'baidu.com',
        'qwant.com', 'startpage.com', 'seznam.cz', 'naver.com', 'ask.com', 'aol.com'];
    private const POSTA = ['mail.google.com', 'outlook.live.com', 'outlook.office.com', 'outlook.office365.com', 'mail.yahoo.com',
        'mail.yandex.ru', 'mail.aol.com', 'webmail', 'mail.'];
    private const MEDII_PLATITE = ['cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'sem'];

    /** Produsul paginii de acum (SiteController::product), pentru scriptul paginii. */
    public static ?int $produsPagina = null;
    /** @var array<string, mixed>|null */
    private static ?array $setari = null;
    /** @var array{0: string, 1: string}|null [zi, sare], pe cerere. */
    private static ?array $sareCurenta = null;

    // ── Setările ──────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $setari */
    public static function folosesteSetarile(array $setari): void
    {
        self::$setari = $setari;
    }

    /** @return array<string, mixed> */
    private static function setari(?PDO $db = null): array
    {
        if (self::$setari === null) {
            try {
                self::$setari = Settings::all($db ?? self::db());
            } catch (Throwable) {
                self::$setari = [];
            }
        }
        return self::$setari;
    }

    /** @param array<string, mixed> $setari */
    public static function respectaDnt(array $setari): bool
    {
        return (string) ($setari['statistici_respecta_dnt'] ?? '1') !== '0';
    }

    /** @param array<string, mixed> $setari */
    public static function zileBrute(array $setari): int
    {
        return max(7, min(400, (int) ($setari['statistici_zile_brute'] ?? 90) ?: 90));
    }

    private static function db(): ?PDO
    {
        $config = require __DIR__ . '/../../config/app.php';
        return Database::connection((array) ($config['db'] ?? []));
    }

    // ── Vizitatorul: sarea zilei și hash-ul ──────────────────────────────────────────────────────────────────────────

    /** Dosarul sărurilor (storage/statistici). */
    public static function dosarSare(): string
    {
        return Paths::stocareSite('statistici');
    }

    /**
     * Sarea zilei `$zi` (Ymd): 32 de octeți aleatori, făcută la prima cerere a zilei (atomic: `link`, deci două cereri în aceeași
     * clipă primesc aceeași sare); sărurile altor zile se șterg atunci. Null dacă dosarul nu se poate scrie (nimic nu se numără).
     */
    public static function sare(?int $acum = null, ?string $dosar = null): ?string
    {
        $zi = date('Ymd', $acum ?? time());
        if ($dosar === null && self::$sareCurenta !== null && self::$sareCurenta[0] === $zi) {
            return self::$sareCurenta[1];
        }
        try {
            $dosar ??= self::dosarSare();
            $fisier = $dosar . '/sare-' . $zi . '.bin';
            $sare = @file_get_contents($fisier);
            if (!is_string($sare) || strlen($sare) !== 32) {
                $temporar = $dosar . '/.sare-' . bin2hex(random_bytes(6)) . '.tmp';
                if (@file_put_contents($temporar, random_bytes(32), LOCK_EX) === 32) {
                    @chmod($temporar, 0600);
                    @link($temporar, $fisier);
                }
                @unlink($temporar);
                $sare = @file_get_contents($fisier);
                self::stergeSaruriVechi($dosar, $zi);
            }
            if (!is_string($sare) || strlen($sare) !== 32) {
                return null;
            }
            if (func_num_args() < 2) {
                self::$sareCurenta = [$zi, $sare];
            }
            return $sare;
        } catch (Throwable) {
            return null;
        }
    }

    /** Șterge sărurile oricărei alte zile decât `$zi` (Ymd). */
    public static function stergeSaruriVechi(string $dosar, string $zi): int
    {
        $sterse = 0;
        foreach (glob($dosar . '/sare-*.bin') ?: [] as $fisier) {
            if (basename($fisier) !== 'sare-' . $zi . '.bin' && @unlink($fisier)) {
                $sterse++;
            }
        }
        foreach (glob($dosar . '/.sare-*.tmp') ?: [] as $fisier) {
            if ((int) @filemtime($fisier) < time() - 60) {
                @unlink($fisier);
            }
        }
        return $sterse;
    }

    /** Identificatorul site-ului din hash (slug-ul licenței sau gazda). */
    public static function site(): string
    {
        $site = trim((string) Env::get('LICENTA_SITE', ''));
        return $site !== '' ? $site : strtolower((string) parse_url(AppUrl::base(), PHP_URL_HOST));
    }

    /** Hash-ul vizitatorului: 16 octeți, aceiași toată ziua pentru același IP și browser, alții a doua zi. */
    public static function hashVizitator(string $sare, string $site, string $ip, string $userAgent): string
    {
        return substr(hash('sha256', $sare . "\n" . $site . "\n" . $ip . "\n" . $userAgent, true), 0, 16);
    }

    /** Hash-ul vizitatorului cererii de acum (null: fără sare). */
    public static function vizitatorCerere(?int $acum = null): ?string
    {
        $sare = self::sare($acum);
        if ($sare === null) {
            return null;
        }
        return self::hashVizitator($sare, self::site(), IpClient::adresa(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    // ── Cine nu se numără ────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * De ce nu se numără cererea (null = se numără): robot, „Do Not Track” / „Global Privacy Control” (cu setarea), preîncărcare.
     *
     * @param array<string, mixed> $server
     */
    public static function motivExcludere(array $server, bool $respectaDnt): ?string
    {
        $ua = (string) ($server['HTTP_USER_AGENT'] ?? '');
        if (Campanii::eRobot($ua) || strlen($ua) > 1000) {
            return 'robot';
        }
        if ($respectaDnt && (trim((string) ($server['HTTP_DNT'] ?? '')) === '1' || trim((string) ($server['HTTP_SEC_GPC'] ?? '')) === '1')) {
            return 'dnt';
        }
        $scop = strtolower((string) ($server['HTTP_SEC_PURPOSE'] ?? $server['HTTP_PURPOSE'] ?? $server['HTTP_X_MOZ'] ?? ''));
        if (str_contains($scop, 'prefetch') || str_contains($scop, 'prerender')) {
            return 'preincarcare';
        }
        return null;
    }

    /** Un administrator logat? Citește sesiunea doar dacă browserul are deja cookie-ul ei (fără sesiune nouă, fără cookie nou). */
    public static function eAdmin(): bool
    {
        try {
            if (session_status() === PHP_SESSION_ACTIVE || (is_array($_SESSION ?? null) && isset($_SESSION['admin_id']))) {
                return Auth::esteLogatFaraReimprospatare();
            }
            if (PHP_SAPI === 'cli' || headers_sent() || !isset($_COOKIE[session_name()]) || (string) $_COOKIE[session_name()] === '') {
                return false;
            }
            $id = (string) $_COOKIE[session_name()];
            // Doar o sesiune care există: altfel PHP (use_strict_mode) ar face una nouă, cu cookie nou.
            if (preg_match('/^[A-Za-z0-9,-]{16,128}$/', $id) !== 1 || !is_file(Paths::stocareSite('sessions') . '/sess_' . $id)) {
                return false;
            }
            SesiuneLaNevoie::porneste();
            $admin = Auth::esteLogatFaraReimprospatare();
            // Doar citire: nimic schimbat, nimic scris înapoi.
            session_abort();
            return $admin;
        } catch (Throwable) {
            return false;
        }
    }

    // ── Clasificări: sursa, browserul, pagina ───────────────────────────────────────────────────────────────────────────

    private static function faraWww(string $gazda): string
    {
        $gazda = strtolower(trim($gazda, ". \t"));
        return str_starts_with($gazda, 'www.') ? substr($gazda, 4) : $gazda;
    }

    private static function domeniuDin(string $gazda, string $domeniu): bool
    {
        return $gazda === $domeniu || str_ends_with($gazda, '.' . $domeniu);
    }

    /**
     * Sursa unei vizite din primul ei eveniment: parametrii utm, semnele unui clic de reclamă (g = gclid/gbraid/wbraid, f = fbclid,
     * t = ttclid), gazda din Referer și browserul din aplicațiile sociale (TikTok, Instagram, Facebook nu trimit mereu Referer).
     * `intern` = o pagină cerută din site (nu începe o vizită nouă).
     *
     * @param array{sursa?: string, mediu?: string, campanie?: string} $utm
     * @return array{sursa: string, detaliu: string}
     */
    public static function clasificaSursa(array $utm, string $clicuri, string $referer, string $userAgent, string $gazdaSite): array
    {
        $utmSursa = strtolower(trim((string) ($utm['sursa'] ?? '')));
        $utmMediu = strtolower(trim((string) ($utm['mediu'] ?? '')));
        $gazda = self::faraWww((string) (parse_url($referer, PHP_URL_HOST) ?? ''));
        $schema = strtolower((string) (parse_url($referer, PHP_URL_SCHEME) ?? ''));
        // Aplicațiile Android trimit android-app://<pachet>.
        if ($schema === 'android-app') {
            $gazda = match (true) {
                str_contains($gazda, 'googlequicksearchbox') => 'google.com',
                str_contains($gazda, 'com.google.android.gm') => 'mail.google.com',
                str_contains($gazda, 'facebook') => 'facebook.com',
                str_contains($gazda, 'instagram') => 'instagram.com',
                str_contains($gazda, 'musical') || str_contains($gazda, 'tiktok') => 'tiktok.com',
                default => $gazda,
            };
        }
        $siteul = self::faraWww($gazdaSite);
        $detaliuGazda = $gazda !== '' && $gazda !== $siteul ? $gazda : '';
        $detaliuUtm = $utmSursa !== '' ? $utmSursa . ($utmMediu !== '' ? ' / ' . $utmMediu : '') : '';
        $rez = static fn (string $sursa, string $detaliu): array => ['sursa' => $sursa, 'detaliu' => mb_substr($detaliu, 0, 100)];

        // 1. Clicurile de reclamă.
        if (str_contains($clicuri, 'g')) {
            return $rez('google_ads', $detaliuUtm !== '' ? $detaliuUtm : 'gclid');
        }
        if (str_contains($clicuri, 't')) {
            return $rez('tiktok', $detaliuUtm !== '' ? $detaliuUtm : 'ttclid');
        }
        if (str_contains($clicuri, 'f')) {
            $insta = in_array($utmSursa, ['instagram', 'ig'], true) || ($gazda !== '' && self::domeniuDin($gazda, 'instagram.com'))
                || preg_match('/Instagram/', $userAgent) === 1;
            return $rez($insta ? 'instagram' : 'facebook', $detaliuUtm !== '' ? $detaliuUtm : ($detaliuGazda !== '' ? $detaliuGazda : 'fbclid'));
        }
        // 2. Parametrii utm.
        if ($utmSursa !== '' || $utmMediu !== '') {
            if (in_array($utmMediu, self::MEDII_PLATITE, true) && str_contains($utmSursa, 'google')) {
                return $rez('google_ads', $detaliuUtm);
            }
            if (in_array($utmMediu, ['email', 'e-mail', 'newsletter'], true) || in_array($utmSursa, ['newsletter', 'email'], true)) {
                return $rez('email', $detaliuUtm);
            }
            if (isset(self::UTM_SOCIALE[$utmSursa])) {
                return $rez(self::UTM_SOCIALE[$utmSursa], $detaliuUtm);
            }
            if ($utmSursa === 'google') {
                return $rez('google', $detaliuUtm);
            }
            return $rez('campanie', $detaliuUtm !== '' ? $detaliuUtm : 'utm');
        }
        // 3. Referer.
        if ($gazda !== '') {
            if ($gazda === $siteul) {
                return $rez('intern', '');
            }
            foreach (self::POSTA as $posta) {
                if (str_ends_with($posta, '.') ? str_starts_with($gazda, $posta) : ($gazda === $posta || str_starts_with($gazda, $posta))) {
                    return $rez('email', $gazda);
                }
            }
            foreach (self::DOMENII_SOCIALE as $domeniu => $sursa) {
                if (self::domeniuDin($gazda, $domeniu)) {
                    return $rez($sursa, $gazda);
                }
            }
            if (preg_match('/(^|\.)google\.[a-z.]{2,8}$/', $gazda) === 1) {
                return $rez('google', $gazda);
            }
            if (self::domeniuDin($gazda, 'bing.com')) {
                return $rez('bing', $gazda);
            }
            foreach (self::MOTOARE as $motor) {
                if (self::domeniuDin($gazda, $motor)) {
                    return $rez('cautare', $gazda);
                }
            }
            return $rez('alt_site', $gazda);
        }
        // 4. Browserul unei aplicații sociale, fără Referer.
        $aplicatie = self::aplicatieSociala($userAgent);
        if ($aplicatie !== null) {
            return $rez($aplicatie, 'aplicația ' . self::SURSE[$aplicatie]);
        }
        return $rez('direct', '');
    }

    /** Browserul din aplicația TikTok, Instagram sau Facebook (după User-Agent), sau null. */
    public static function aplicatieSociala(string $userAgent): ?string
    {
        return match (true) {
            preg_match('/musical_ly|BytedanceWebview|TikTok/i', $userAgent) === 1 => 'tiktok',
            preg_match('/Instagram/', $userAgent) === 1 => 'instagram',
            preg_match('/FBAN|FBAV|FB_IAB|FBIOS|\[FB/', $userAgent) === 1 => 'facebook',
            default => null,
        };
    }

    /**
     * Browserul, sistemul și dispozitivul din User-Agent (doar familia; User-Agent-ul nu se păstrează).
     *
     * @return array{browser: string, os: string, dispozitiv: string}
     */
    public static function parseazaUa(string $ua): array
    {
        $browser = match (true) {
            preg_match('/musical_ly|BytedanceWebview|TikTok/i', $ua) === 1 => 'TikTok (aplicație)',
            preg_match('/Instagram/', $ua) === 1 => 'Instagram (aplicație)',
            preg_match('/FBAN|FBAV|FB_IAB|FBIOS/', $ua) === 1 => 'Facebook (aplicație)',
            preg_match('/Edg(e|A|iOS)?\//', $ua) === 1 => 'Edge',
            preg_match('/OPR\/|Opera/', $ua) === 1 => 'Opera',
            preg_match('/SamsungBrowser/', $ua) === 1 => 'Samsung Internet',
            preg_match('/Firefox\/|FxiOS/', $ua) === 1 => 'Firefox',
            preg_match('/Chrome\/|CriOS/', $ua) === 1 => 'Chrome',
            preg_match('/Safari\//', $ua) === 1 => 'Safari',
            default => 'Altul',
        };
        $os = match (true) {
            preg_match('/iPhone|iPad|iPod/', $ua) === 1 => 'iOS',
            preg_match('/Android/', $ua) === 1 => 'Android',
            preg_match('/Windows/', $ua) === 1 => 'Windows',
            preg_match('/CrOS/', $ua) === 1 => 'ChromeOS',
            preg_match('/Mac OS X|Macintosh/', $ua) === 1 => 'macOS',
            preg_match('/Linux/', $ua) === 1 => 'Linux',
            default => 'Altul',
        };
        $dispozitiv = match (true) {
            preg_match('/iPad|Tablet/i', $ua) === 1 || (str_contains($ua, 'Android') && !str_contains($ua, 'Mobile')) => 'tableta',
            preg_match('/Mobi|iPhone|iPod|Android/', $ua) === 1 => 'mobil',
            default => 'desktop',
        };
        return ['browser' => $browser, 'os' => $os, 'dispozitiv' => $dispozitiv];
    }

    /** Lățimea ecranului pe trepte (1 = sub 576 px … 5 = peste 1440 px; 0 = necunoscută). */
    public static function treaptaLatime(int $latime): int
    {
        return match (true) {
            $latime <= 0 => 0,
            $latime < 576 => 1,
            $latime < 768 => 2,
            $latime < 1024 => 3,
            $latime < 1440 => 4,
            default => 5,
        };
    }

    /** Felul paginii după adresă (și `?q=` = o căutare). */
    public static function tipPagina(string $cale, bool $cuCautare = false): string
    {
        $cale = strtolower($cale);
        if ($cuCautare) {
            return 'cautare';
        }
        return match (true) {
            $cale === '/' || $cale === '' => 'acasa',
            str_starts_with($cale, '/produs/') => 'produs',
            str_starts_with($cale, '/categorie/') => 'categorie',
            $cale === '/magazin' || str_starts_with($cale, '/marca/') || str_starts_with($cale, '/eticheta/') => 'magazin',
            $cale === '/blog' || str_starts_with($cale, '/blog/categorie/') || str_starts_with($cale, '/blog/eticheta/') => 'blog',
            str_starts_with($cale, '/blog/') => 'articol',
            $cale === '/cos' || str_starts_with($cale, '/cos/') || $cale === '/cos-cumparaturi' => 'cos',
            str_starts_with($cale, '/checkout/succes') => 'comanda',
            $cale === '/checkout' || str_starts_with($cale, '/checkout/') => 'checkout',
            str_starts_with($cale, '/campanie/') => 'campanie',
            default => 'pagina',
        };
    }

    /** Țara, doar dintr-un antet pus de CDN-ul găzduirii (două litere); altfel gol. Fără bază GeoIP. */
    public static function tara(array $server): string
    {
        foreach (['HTTP_CF_IPCOUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_VERCEL_IP_COUNTRY', 'HTTP_X_GEO_COUNTRY'] as $antet) {
            $valoare = strtoupper(trim((string) ($server[$antet] ?? '')));
            if (preg_match('/^[A-Z]{2}$/', $valoare) === 1 && !in_array($valoare, ['XX', 'T1', 'ZZ'], true)) {
                return $valoare;
            }
        }
        return '';
    }

    /** O cale curată: doar calea (fără parametri), cel mult 255 de caractere, fără caractere de control. */
    public static function caleCurata(string $cale): ?string
    {
        if ($cale === '' || $cale[0] !== '/' || str_starts_with($cale, '//') || str_contains($cale, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $cale) === 1) {
            return null;
        }
        $cale = (string) preg_replace('/[?#].*$/s', '', $cale);
        if ($cale === '') {
            return null;
        }
        $decodata = rawurldecode($cale);
        if (mb_check_encoding($decodata, 'UTF-8') && preg_match('/[\x00-\x1f\x7f]/', $decodata) !== 1) {
            $cale = $decodata;
        }
        return mb_substr($cale, 0, 255);
    }

    private static function textScurt(mixed $valoare, int $max = 100): string
    {
        if (!is_string($valoare)) {
            return '';
        }
        $valoare = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $valoare));
        if (!mb_check_encoding($valoare, 'UTF-8')) {
            return '';
        }
        return mb_substr($valoare, 0, $max);
    }

    // ── Scriptul paginilor ─────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Agățarea de `layout.final_body`: scriptul care trimite pagina văzută (sub 2 KB, fără cookie, fără stocare în browser).
     * Gol pentru un administrator logat.
     */
    public static function scriptPagina(): string
    {
        try {
            if (self::eAdminInPagina()) {
                return '';
            }
            $cale = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
            $tip = self::tipPagina($cale);
            $produs = $tip === 'produs' && self::$produsPagina !== null && self::$produsPagina > 0 ? self::$produsPagina : 0;
            return '<script data-ax-st>' . self::codScript(self::respectaDnt(self::setari()), $tip, $produs) . '</script>';
        } catch (Throwable) {
            return '';
        }
    }

    /** Administratorul logat, fără să pornească o sesiune (în pagină sesiunea, dacă există, e deja pornită). */
    private static function eAdminInPagina(): bool
    {
        return is_array($_SESSION ?? null) && isset($_SESSION['admin_id']) && Auth::esteLogatFaraReimprospatare();
    }

    /** Codul scriptului (minificat de mână; testul cere sub 2048 de octeți). */
    public static function codScript(bool $respectaDnt, string $tip, int $produs): string
    {
        $dnt = $respectaDnt ? 'if(n.doNotTrack=="1"||w.doNotTrack=="1"||n.globalPrivacyControl)return;' : '';
        return '(function(w,d,n){try{' . $dnt . 'if(n.webdriver)return;'
            . 'var l=w.location,u=' . json_encode(self::CALE, JSON_UNESCAPED_SLASHES) . ',t=' . json_encode($tip) . ',i=' . $produs . ',v=0,a=0;'
            . 'function b(e){var x=JSON.stringify(e);try{if(n.sendBeacon&&n.sendBeacon(u,new Blob([x],{type:"text/plain"})))return}catch(_){}'
            . 'try{fetch(u,{method:"POST",body:x,keepalive:!0,credentials:"same-origin",headers:{"Content-Type":"text/plain"}})}catch(_){}}'
            . 'function g(){if(v||d.visibilityState=="hidden")return;v=1;a=Date.now();'
            . 'var p=new URLSearchParams(l.search),o={},c="";'
            . '["source","medium","campaign","content","term"].forEach(function(k){var y=p.get("utm_"+k);if(y)o[k]=y.slice(0,100)});'
            . 'if(p.get("gclid")||p.get("gbraid")||p.get("wbraid"))c+="g";if(p.get("fbclid"))c+="f";if(p.get("ttclid"))c+="t";'
            . 'b({e:"v",p:l.pathname.slice(0,300),r:d.referrer.slice(0,500),w:w.innerWidth||0,u:o,c:c,q:p.has("q")?1:0,t:t,i:i})}'
            . 'function h(){if(!v)return;if(d.visibilityState=="hidden"){if(a){var s=Math.round((Date.now()-a)/1e3);a=0;'
            . 'if(s>0)b({e:"l",p:l.pathname.slice(0,300),d:s})}}else if(!a)a=Date.now()}'
            . 'd.addEventListener("visibilitychange",function(){v?h():g()});'
            . 'w.addEventListener("pagehide",h);'
            . 'w.addEventListener("pageshow",function(e){if(e.persisted){v=0;g()}});'
            . 'if(d.prerendering)d.addEventListener("prerenderingchange",g,{once:!0});else g()'
            . '}catch(_){}})(window,document,navigator);';
    }

    // ── Punctul de primire ────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Din public/index.php, după citirea setărilor și înaintea sesiunii: `POST /api/statistici` cu pluginul pornit se tratează
     * aici (204 și nimic altceva: fără sesiune, fără cookie, fără cache). Altfel nu face nimic (cu pluginul oprit, cererea merge
     * mai departe la router, iar garda răspunde cu 404).
     *
     * @param array<string, mixed> $setari
     */
    public static function intercepteaza(?PDO $db, array $setari): bool
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST'
            || rtrim(strtolower((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '')), '/') !== self::CALE) {
            return false;
        }
        try {
            Plugins::incarca();
            if (!Plugins::vizibil('statistici')) {
                return false;
            }
            self::folosesteSetarile($setari);
            $corp = (string) @file_get_contents('php://input', false, null, 0, self::MAX_CORP + 1);
            $cod = $db instanceof PDO ? self::primeste($db, $_SERVER, $corp, $setari) : 503;
        } catch (Throwable) {
            $cod = 500;
        }
        if (!headers_sent()) {
            // Niciun cookie, orice s-ar fi întâmplat mai sus (de exemplu la citirea sesiunii unui administrator).
            header_remove('Set-Cookie');
            http_response_code($cod);
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex');
        }
        return true;
    }

    /**
     * Evenimentul trimis de scriptul paginii. Întoarce codul HTTP: 204 (primit sau ignorat: robot, administrator, DNT), 400 (corp
     * greșit), 403 (alt site), 413 (prea mare), 429 (prea multe), 503 (fără bază sau fără sare).
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $setari
     */
    public static function primeste(PDO $db, array $server, string $corp, array $setari, ?int $acum = null): int
    {
        $acum ??= time();
        if (!self::origineaPermisa($server)) {
            return 403;
        }
        if (strlen($corp) > self::MAX_CORP) {
            return 413;
        }
        $date = self::valideaza($corp);
        if ($date === null) {
            return 400;
        }
        if (self::motivExcludere($server, self::respectaDnt($setari)) !== null || self::eAdmin()) {
            return 204;
        }
        $sare = self::sare($acum);
        if ($sare === null) {
            return 503;
        }
        $ua = (string) ($server['HTTP_USER_AGENT'] ?? '');
        $vizitator = self::hashVizitator($sare, self::site(), IpClient::dinCerere($server, (string) Env::get('IP_PROXY_DE_INCREDERE', '')), $ua);
        if (self::preaMulte($db, $vizitator, $acum)) {
            return 429;
        }
        $ua3 = self::parseazaUa($ua);
        $rand = [
            'creat_la' => date('Y-m-d H:i:s', $acum),
            'vizitator' => $vizitator,
            'tip' => $date['e'] === 'l' ? self::TIP_PLECARE : self::TIP_VIZUALIZARE,
            'cale' => $date['p'],
            'tip_pagina' => self::tipPagina($date['p'], $date['q']),
            'produs_id' => null,
            'sursa' => '', 'detaliu' => '',
            'utm_sursa' => '', 'utm_mediu' => '', 'utm_campanie' => '', 'utm_continut' => '', 'utm_termen' => '',
            'dispozitiv' => $ua3['dispozitiv'], 'browser' => $ua3['browser'], 'os' => $ua3['os'],
            'latime' => self::treaptaLatime($date['w']),
            'tara' => self::tara($server),
            'durata' => $date['d'],
        ];
        if ($rand['tip'] === self::TIP_VIZUALIZARE) {
            if ($rand['tip_pagina'] === 'produs' && $date['i'] > 0) {
                $rand['produs_id'] = $date['i'];
            }
            $sursa = self::clasificaSursa(['sursa' => $date['u']['source'], 'mediu' => $date['u']['medium']], $date['c'], $date['r'], $ua,
                (string) parse_url(AppUrl::base(), PHP_URL_HOST));
            $rand['sursa'] = $sursa['sursa'];
            $rand['detaliu'] = $sursa['detaliu'];
            $rand['utm_sursa'] = $date['u']['source'];
            $rand['utm_mediu'] = $date['u']['medium'];
            $rand['utm_campanie'] = $date['u']['campaign'];
            $rand['utm_continut'] = $date['u']['content'];
            $rand['utm_termen'] = $date['u']['term'];
        }
        self::scrie($db, $rand);
        return 204;
    }

    /**
     * Doar de pe același site: `Origin` (pe care browserul îl pune la orice POST) trebuie să fie gazda site-ului; fără el,
     * `Sec-Fetch-Site: same-origin` sau un Referer al site-ului.
     *
     * @param array<string, mixed> $server
     */
    public static function origineaPermisa(array $server): bool
    {
        $gazde = array_values(array_filter(array_unique([
            self::faraWww((string) parse_url(AppUrl::base(), PHP_URL_HOST)),
            self::faraWww((string) preg_replace('/:\d+$/', '', (string) ($server['HTTP_HOST'] ?? ''))),
        ])));
        $gazdaDin = static fn (string $url): string => self::faraWww((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $origine = trim((string) ($server['HTTP_ORIGIN'] ?? ''));
        if ($origine !== '') {
            return $origine !== 'null' && in_array($gazdaDin($origine), $gazde, true);
        }
        $site = strtolower(trim((string) ($server['HTTP_SEC_FETCH_SITE'] ?? '')));
        if ($site !== '') {
            return $site === 'same-origin';
        }
        $referer = trim((string) ($server['HTTP_REFERER'] ?? ''));
        return $referer !== '' && in_array($gazdaDin($referer), $gazde, true);
    }

    /**
     * Corpul evenimentului (JSON): `e` v|l, `p` calea, `r` Referer-ul paginii, `w` lățimea, `u` parametrii utm, `c` clicurile
     * (g, f, t), `q` căutare, `i` produsul, `d` secundele active (la plecare). Null = greșit.
     *
     * @return array{e: string, p: string, r: string, w: int, u: array{source: string, medium: string, campaign: string, content: string, term: string}, c: string, q: bool, i: int, d: int}|null
     */
    public static function valideaza(string $corp): ?array
    {
        if ($corp === '' || strlen($corp) > self::MAX_CORP) {
            return null;
        }
        $json = json_decode($corp, true, 4);
        if (!is_array($json) || !in_array($json['e'] ?? null, ['v', 'l'], true) || !is_string($json['p'] ?? null)) {
            return null;
        }
        $cale = self::caleCurata((string) $json['p']);
        if ($cale === null) {
            return null;
        }
        $utm = ['source' => '', 'medium' => '', 'campaign' => '', 'content' => '', 'term' => ''];
        if (isset($json['u'])) {
            if (!is_array($json['u'])) {
                return null;
            }
            foreach (array_keys($utm) as $cheie) {
                $utm[$cheie] = self::textScurt($json['u'][$cheie] ?? '', 100);
            }
        }
        $clicuri = is_string($json['c'] ?? null) ? implode('', array_intersect(['g', 'f', 't'], str_split((string) $json['c']))) : '';
        $referer = is_string($json['r'] ?? null) ? substr((string) $json['r'], 0, 500) : '';
        if ($referer !== '' && preg_match('#^(https?|android-app)://#i', $referer) !== 1) {
            $referer = '';
        }
        $durata = is_int($json['d'] ?? null) || is_float($json['d'] ?? null) ? (int) $json['d'] : 0;
        if ($json['e'] === 'l' && ($durata < 0 || $durata > 86400)) {
            return null;
        }
        $latime = is_int($json['w'] ?? null) || is_float($json['w'] ?? null) ? (int) $json['w'] : 0;
        $produs = is_int($json['i'] ?? null) ? (int) $json['i'] : 0;
        return [
            'e' => (string) $json['e'],
            'p' => $cale,
            'r' => $referer,
            'w' => max(0, min(20000, $latime)),
            'u' => $utm,
            'c' => $clicuri,
            'q' => !empty($json['q']),
            'i' => $produs > 0 && $produs < 4294967295 ? $produs : 0,
            'd' => max(0, min(86400, $durata)),
        ];
    }

    /** Peste LIMITA_MINUT evenimente ale vizitatorului în ultimul minut? */
    private static function preaMulte(PDO $db, string $vizitator, int $acum): bool
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM statistici_evenimente WHERE vizitator = ? AND creat_la >= ?');
        $stmt->execute([$vizitator, date('Y-m-d H:i:s', $acum - 60)]);
        return (int) $stmt->fetchColumn() >= self::LIMITA_MINUT;
    }

    /** @param array<string, mixed> $rand */
    private static function scrie(PDO $db, array $rand): void
    {
        $coloane = array_keys($rand);
        $stmt = $db->prepare('INSERT INTO statistici_evenimente (' . implode(', ', $coloane) . ') VALUES ('
            . implode(', ', array_fill(0, count($coloane), '?')) . ')');
        $i = 1;
        foreach ($rand as $cheie => $valoare) {
            $stmt->bindValue($i++, $valoare, $valoare === null ? PDO::PARAM_NULL : ($cheie === 'vizitator' ? PDO::PARAM_LOB : PDO::PARAM_STR));
        }
        $stmt->execute();
    }

    // ── Evenimentele de pe server: coșul, comanda, căutarea ──────────────────────────────────────────────────────────────

    /**
     * Vizitatorul cererii de acum, dacă se numără (pluginul pornit, nu robot, nu administrator, fără DNT cu setarea): hash-ul și
     * baza; altfel null. Nu aruncă niciodată.
     *
     * @return array{0: PDO, 1: string}|null
     */
    private static function pentruServer(?PDO $db, ?int $acum = null): ?array
    {
        try {
            if (!Plugins::vizibil('statistici')) {
                return null;
            }
            $db ??= self::db();
            if (!$db instanceof PDO || self::motivExcludere($_SERVER, self::respectaDnt(self::setari($db))) !== null || self::eAdmin()) {
                return null;
            }
            $vizitator = self::vizitatorCerere($acum);
            return $vizitator === null ? null : [$db, $vizitator];
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $extra */
    private static function evenimentServer(?PDO $db, int $tip, array $extra = [], ?int $acum = null): bool
    {
        $context = self::pentruServer($db, $acum);
        if ($context === null) {
            return false;
        }
        [$db, $vizitator] = $context;
        try {
            $ua = self::parseazaUa((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            self::scrie($db, array_merge([
                'creat_la' => date('Y-m-d H:i:s', $acum ?? time()),
                'vizitator' => $vizitator,
                'tip' => $tip,
                'dispozitiv' => $ua['dispozitiv'], 'browser' => $ua['browser'], 'os' => $ua['os'],
                'tara' => self::tara($_SERVER),
            ], $extra));
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Un produs adăugat în coș (SiteController::cartAdd, cartItemAddApi; pachetele). */
    public static function laCos(int $produsId, ?PDO $db = null): void
    {
        self::evenimentServer($db, self::TIP_COS, ['produs_id' => $produsId > 0 ? $produsId : null, 'cale' => '/cos', 'tip_pagina' => 'cos']);
    }

    /** Pagina checkout deschisă (SiteController::checkout). */
    public static function laCheckout(?PDO $db = null): void
    {
        self::evenimentServer($db, self::TIP_CHECKOUT, ['cale' => '/checkout', 'tip_pagina' => 'checkout']);
    }

    /**
     * O comandă plasată (SiteController::checkoutSubmit, lângă sursa comenzii): evenimentul și hash-ul zilnic pe comandă
     * (`orders.statistici_vizitator`), ca agregarea să lege comanda de vizita din aceeași zi. Fără coloană: doar evenimentul.
     */
    public static function laComanda(PDO $db, int $comandaId, ?int $acum = null): void
    {
        if ($comandaId <= 0) {
            return;
        }
        $context = self::pentruServer($db, $acum);
        if ($context === null) {
            return;
        }
        try {
            $stmt = $db->prepare('UPDATE orders SET statistici_vizitator = ? WHERE id = ?');
            $stmt->bindValue(1, $context[1], PDO::PARAM_LOB);
            $stmt->bindValue(2, $comandaId, PDO::PARAM_INT);
            $stmt->execute();
        } catch (Throwable) {
            // Fără statistici/0061 încă: comanda e salvată, legătura vine după migrare (doar pentru comenzile de după).
        }
        self::evenimentServer($db, self::TIP_COMANDA, ['comanda_id' => $comandaId, 'cale' => '/checkout', 'tip_pagina' => 'comanda'], $acum);
    }

    /** O căutare normalizată: litere mici, spațiile strânse, cel mult 100 de caractere; sub 2 caractere = nimic. */
    public static function normalizeazaCautare(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1f\x7f]/u', ' ', $text)));
        if (!mb_check_encoding($text, 'UTF-8')) {
            return '';
        }
        $text = mb_substr(mb_strtolower($text, 'UTF-8'), 0, 100);
        return mb_strlen($text) < 2 ? '' : $text;
    }

    /**
     * O căutare pe site (căutarea de produse din antet, `/api/search/products`, chemată pe măsură ce clientul scrie): ca să nu
     * se numere fiecare literă, o căutare a aceluiași vizitator în ultimele 90 de secunde care e începutul celei noi (sau invers)
     * se înlocuiește cu cea nouă.
     */
    public static function cautare(string $text, int $rezultate, ?PDO $db = null, ?int $acum = null): void
    {
        $text = self::normalizeazaCautare($text);
        if ($text === '') {
            return;
        }
        $context = self::pentruServer($db, $acum);
        if ($context === null) {
            return;
        }
        [$db, $vizitator] = $context;
        $acum ??= time();
        try {
            $stmt = $db->prepare('SELECT id, cautare FROM statistici_evenimente WHERE vizitator = ? AND tip = ? AND creat_la >= ?
                ORDER BY id DESC LIMIT 1');
            $stmt->bindValue(1, $vizitator, PDO::PARAM_LOB);
            $stmt->bindValue(2, self::TIP_CAUTARE, PDO::PARAM_INT);
            $stmt->bindValue(3, date('Y-m-d H:i:s', $acum - 90));
            $stmt->execute();
            $ultima = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($ultima)) {
                $veche = (string) $ultima['cautare'];
                if (str_starts_with($text, $veche) || str_starts_with($veche, $text)) {
                    $db->prepare('UPDATE statistici_evenimente SET cautare = ?, rezultate = ?, creat_la = ? WHERE id = ?')
                        ->execute([$text, max(0, $rezultate), date('Y-m-d H:i:s', $acum), (int) $ultima['id']]);
                    return;
                }
            }
        } catch (Throwable) {
            return;
        }
        self::evenimentServer($db, self::TIP_CAUTARE, ['cautare' => $text, 'rezultate' => max(0, $rezultate), 'cale' => '/cautare',
            'tip_pagina' => 'cautare'], $acum);
    }
}
