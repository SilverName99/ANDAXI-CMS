<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Datele unui singur site, ținute în afara nucleului: fișiere JSON (fără PHP
 * executabil) în dosarul site-ului, scrise de ANDAXI (sau, mai târziu, de CRM),
 * niciodată în codul comun.
 *
 * Dosarul: `SITE_DATE_DOSAR` din .env (cale absolută sau relativă la rădăcina
 * codului, `Paths::radacina()`: pachetele din `site-uri/` vin cu codul); gol =
 * `<rădăcină>/site` (ignorat de git, ca `storage/`). Un site fără dosar sau
 * fără fișiere merge cu valorile implicite ale nucleului. În așezarea din
 * Etapa 2 (o versiune de cod comună mai multor site-uri) `.env`-ul fiecărui
 * site îl scrie explicit.
 *
 * Fișierele citite la cerere (formatul pachetului: docs/PACHETE.md; SEO: docs/SEO.md):
 *  - `redirecturi.json`: adresele vechi ale site-ului (301 sau 410);
 *  - `seo.json`: regulile SEO ale site-ului (etichetele din sitemap, logo-ul
 *    și numele pentru Google, reguli robots.txt în plus);
 *  - `site.json`: constantele tehnice ale site-ului care nu se schimbă niciodată
 *    pe un site existent (numele cookie-ului de acord, cheia popup-ului; WP-16 și
 *    WP-17 le mută aici), citite cu `site()`;
 *  - `tema/tema.json`: tema site-ului (App\Support\Tema).
 *
 * Pachetul de date al unui site existent stă în `site-uri/<site>/` (de exemplu
 * `site-uri/bioscem/`) și se copiază în dosarul site-ului la instalare.
 *
 * Rulează și înainte de autoloader (din SeoRedirects), deci nu folosește alte
 * clase în afară de Env și Paths.
 */
final class DateSite
{
    /** @var array<string, array<mixed>> */
    private static array $json = [];
    private static ?string $dosar = null;

    /** Dosarul cu datele site-ului (poate să nu existe). */
    public static function dosar(): string
    {
        if (self::$dosar === null) {
            if (!class_exists(Env::class, false)) {
                require_once __DIR__ . '/Env.php';
            }
            if (!class_exists(Paths::class, false)) {
                require_once __DIR__ . '/Paths.php';
            }
            $radacina = Paths::radacina();
            $ales = trim((string) Env::get('SITE_DATE_DOSAR', ''));
            if ($ales === '') {
                $ales = $radacina . '/site';
            } elseif ($ales[0] !== '/') {
                $ales = $radacina . '/' . $ales;
            }
            self::$dosar = rtrim($ales, '/');
        }
        return self::$dosar;
    }

    /**
     * Conținutul unui fișier JSON din dosarul site-ului, ca array. Gol dacă
     * fișierul lipsește sau nu e JSON valid (atunci se notează o dată în
     * jurnalul PHP: un fișier stricat nu are voie să oprească site-ul).
     *
     * `$fisier` e calea din pachet („seo.json”, „tema/tema.json”): doar litere mici,
     * cifre, „-” și „_”, cu „/” între dosare, terminată în „.json”; orice altceva
     * (o cale absolută, „..”) se tratează ca un fișier care lipsește.
     *
     * @return array<mixed>
     */
    public static function json(string $fisier): array
    {
        if (array_key_exists($fisier, self::$json)) {
            return self::$json[$fisier];
        }
        $date = [];
        if (preg_match('#^[a-z0-9][a-z0-9_-]*(/[a-z0-9][a-z0-9_-]*)*\.json$#', $fisier) !== 1) {
            return self::$json[$fisier] = $date;
        }
        $cale = self::dosar() . '/' . $fisier;
        if (is_file($cale)) {
            $brut = @file_get_contents($cale);
            $decodat = is_string($brut) ? json_decode($brut, true) : null;
            if (is_array($decodat)) {
                $date = $decodat;
            } else {
                error_log('ANDAXI-CMS: ' . $cale . ' nu e JSON valid (' . json_last_error_msg() . '); îl ignor.');
            }
        }
        return self::$json[$fisier] = $date;
    }

    /** O valoare din `seo.json` după cale („sitemap.eticheta_minim_produse”), sau $implicit. */
    public static function seo(string $cheie, mixed $implicit = null): mixed
    {
        return self::valoare('seo.json', $cheie, $implicit);
    }

    /**
     * O constantă a site-ului din `site.json` după cale („cookie_consimtamant”), sau $implicit.
     * Aceleași reguli ca `seo()`: fișierul lipsă sau stricat dă $implicit (stricat: o linie în jurnalul PHP).
     */
    public static function site(string $cheie, mixed $implicit = null): mixed
    {
        return self::valoare('site.json', $cheie, $implicit);
    }

    private static function valoare(string $fisier, string $cheie, mixed $implicit): mixed
    {
        $valoare = self::json($fisier);
        foreach (explode('.', $cheie) as $parte) {
            if (!is_array($valoare) || !array_key_exists($parte, $valoare)) {
                return $implicit;
            }
            $valoare = $valoare[$parte];
        }
        return $valoare;
    }

    /** Doar pentru teste și unelte: uită ce s-a citit (alt dosar, fișiere schimbate). */
    public static function reseteaza(?string $dosar = null): void
    {
        self::$json = [];
        self::$dosar = $dosar !== null ? rtrim($dosar, '/') : null;
    }
}
