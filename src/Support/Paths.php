<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Căile unui site, într-un singur loc: fișierul `.env`, dosarul `storage/` (sesiunile, cache-ul, jurnalele, licența,
 * importurile, copiile scripturilor, dosarul de lucru al site-ului de verificare) și ce scrie aplicația în docroot
 * (pozele încărcate, un sitemap cu alt nume, `.htaccess`-ul de cache din `assets/` și `uploads/`). Codul nu mai
 * construiește singur aceste căi din `__DIR__`; le cere de aici (docs/DEZVOLTARE.md, „Unde scrie codul”).
 *
 * Două așezări:
 *
 *  - **cea de azi** (și cea a dezvoltării): un site = un dosar de cod. `.env` și `storage/` stau în rădăcina codului,
 *    docroot-ul e `public/` (`.htaccess`-ul din rădăcină trimite totul acolo). Aici fiecare metodă dă exact calea
 *    de până la WP-07, deci un site existent nu observă nimic.
 *  - **Etapa 2** (analiza infra, §4.2): codul e o versiune care nu se mai schimbă după urcare, comună site-urilor
 *    contului (`~/platforma/releases/<versiune>/`); fiecare site își ține starea în `~/domains/<d>/site/` (`.env`,
 *    `storage/`, `RELEASE`, `MENTENANTA`) și docroot-ul în `~/domains/<d>/public_html/` (stub-ul
 *    `deploy/stub/index.php`, `uploads/`, `assets/`, sitemap-ul). Stub-ul definește `PLATFORMA_SITE_DIR` și
 *    `PLATFORMA_PUBLIC_DIR` înainte să încarce `public/index.php` al versiunii. O comandă din linia de comandă le
 *    definește la fel înaintea scriptului (`bin/platforma --site=<dosar>`, Etapa 2); fără `PLATFORMA_PUBLIC_DIR`,
 *    docroot-ul e `public_html/` de lângă dosarul site-ului.
 *
 * Așa, două site-uri pe aceeași versiune nu împart niciodată sesiunile, cache-ul sau jurnalele, iar trecerea la o
 * versiune nouă nu pierde nimic din ele (tools/dev/test-paths.php le verifică pe o găzduire simulată).
 *
 * `stocare()` dă doar calea; `stocareSite()` dă un dosar privat care trebuie să existe (îl face, cu gardul lui).
 * Fișierele codului (`config/`, `views/`, `database/migrari/`, `site-uri/`, `scripts/`) rămân față de
 * `radacina()`: ele vin cu versiunea.
 *
 * Rulează și înainte de autoloader (poarta din `public/index.php`, fișierele încărcate, poza de distribuire, stratul
 * SEO), deci nu folosește alte clase.
 */
final class Paths
{
    /**
     * Gardul pus în fiecare dosar privat: `.htaccess`-ul din rădăcină trimite deja totul în `public/` (iar în Etapa 2
     * `site/` e în afara docroot-ului), dar un gard în plus peste sesiuni și jurnale nu strică nimănui.
     */
    public const GARD = "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";

    /** Rădăcina codului: dosarul cu `src/`, `views/`, `config/` (azi, și dosarul site-ului; în Etapa 2, versiunea). */
    public static function radacina(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Cererea a pornit din stub-ul din Etapa 2 (sau dintr-o comandă care i-a definit constantele)? */
    public static function cuStub(): bool
    {
        return defined('PLATFORMA_SITE_DIR');
    }

    /** Fișierul `.env` al site-ului. */
    public static function env(): string
    {
        return self::dosarSite() . '/.env';
    }

    /** O cale din `storage/` al site-ului („cache/pages”, „logs/migrari.log”); fără argument, dosarul însuși. Nu face nimic pe disc. */
    public static function stocare(string $sub = ''): string
    {
        return self::adauga(self::dosarSite() . '/storage', $sub);
    }

    /**
     * Un dosar privat al site-ului din `storage/` („sessions”, „verificare”, „logs”): îl face dacă lipsește (0770, cu
     * dosarele de deasupra) și pune în el gardul `.htaccess` (`GARD`), o singură dată. Întoarce calea și când dosarul
     * nu se poate face sau scrie: cine scrie acolo verifică întâi `is_writable()`.
     */
    public static function stocareSite(string $sub): string
    {
        if (trim($sub, '/') === '') {
            throw new \InvalidArgumentException('Paths::stocareSite: lipsește dosarul din storage/.');
        }
        $dosar = self::stocare($sub);
        if (!is_dir($dosar)) {
            @mkdir($dosar, 0770, true);
        }
        if (is_dir($dosar) && is_writable($dosar) && !is_file($dosar . '/.htaccess')) {
            @file_put_contents($dosar . '/.htaccess', self::GARD);
        }
        return $dosar;
    }

    /**
     * Docroot-ul site-ului (ce servește serverul web) sau o cale din el („assets/css/app.css”). Azi `public/` din
     * codul site-ului; în Etapa 2 `public_html/` al site-ului, unde stau stub-ul, o copie a `assets/` versiunii,
     * `uploads/` și sitemap-urile scrise de admin.
     */
    public static function public(string $sub = ''): string
    {
        if (defined('PLATFORMA_PUBLIC_DIR')) {
            $baza = rtrim((string) constant('PLATFORMA_PUBLIC_DIR'), '/');
        } elseif (self::cuStub()) {
            $baza = dirname(self::dosarSite()) . '/public_html';
        } else {
            $baza = self::radacina() . '/public';
        }
        return self::adauga($baza, $sub);
    }

    /** Pozele și fișierele încărcate de admin (`uploads/` din docroot) sau o cale din ele („gallery”). */
    public static function uploads(string $sub = ''): string
    {
        return self::adauga(self::public('uploads'), $sub);
    }

    /** Un sitemap scris de admin în docroot (doar un nume de fișier, fără dosar). */
    public static function sitemap(string $fisier = 'sitemap.xml'): string
    {
        if ($fisier === '' || $fisier !== basename($fisier) || $fisier === '.' || $fisier === '..') {
            throw new \InvalidArgumentException('Paths::sitemap: aștept doar un nume de fișier.');
        }
        return self::public() . '/' . $fisier;
    }

    /**
     * Jurnalul erorilor PHP al site-ului: `storage/logs/php.log` (în Etapa 2 `site/storage/logs/php.log`, pe care îl
     * citește verificarea de după un deploy: nicio eroare „PHP Fatal” nouă). O cheamă `public/index.php` înaintea
     * oricărui alt cod (și a porții) și `bootstrap.php` (linia de comandă); a doua oară nu mai schimbă nimic.
     *
     *  - **Paginile:** în Etapa 2 mereu; în așezarea de azi doar când serverul nu a ales deja un jurnal (`error_log`
     *    gol), deci un jurnal pus de găzduire sau de uneltele de dezvoltare rămâne cel de până acum.
     *  - **Linia de comandă** (cronurile, `scripts/migreaza.php`, `install.php`): în așezarea de azi neatinsă. Fără
     *    `error_log`, PHP scrie erorile pe stderr, deci în terminal și în ieșirea cronului; un `error_log` gol nu
     *    înseamnă aici „fără jurnal”, iar un fișier ales de noi le-ar ascunde (o eroare fatală la adoptare s-ar
     *    termina fără niciun mesaj). În Etapa 2 (`bin/platforma --site=`) și în jurnalul site-ului, dar tot pe
     *    ecran: dacă PHP nu le afișează (`display_errors` oprit, ca în php.ini-ul obișnuit), le afișează pe stderr.
     */
    public static function alegeJurnalulPhp(): void
    {
        $cli = PHP_SAPI === 'cli';
        if (!self::cuStub() && ($cli || trim((string) ini_get('error_log')) !== '')) {
            return;
        }
        $dosar = self::stocareSite('logs');
        if (!is_dir($dosar) || !is_writable($dosar)) {
            return;
        }
        if ($cli && in_array(strtolower(trim((string) ini_get('display_errors'))), ['', '0', 'off', 'no', 'false', 'none'], true)) {
            ini_set('display_errors', 'stderr');
        }
        ini_set('error_log', $dosar . '/php.log');
    }

    /** Dosarul cu `.env` și `storage/`: `PLATFORMA_SITE_DIR` în Etapa 2, altfel rădăcina codului. */
    private static function dosarSite(): string
    {
        return self::cuStub() ? rtrim((string) constant('PLATFORMA_SITE_DIR'), '/') : self::radacina();
    }

    /** `$baza/$sub`, cu „/” de la capete scos; „..” și NUL sunt refuzate (căile vin din cod, nu de la vizitatori). */
    private static function adauga(string $baza, string $sub): string
    {
        $sub = trim($sub, '/');
        if ($sub === '') {
            return $baza;
        }
        if (str_contains($sub, "\0") || in_array('..', explode('/', $sub), true)) {
            throw new \InvalidArgumentException('Paths: cale nepermisă.');
        }
        return $baza . '/' . $sub;
    }
}
