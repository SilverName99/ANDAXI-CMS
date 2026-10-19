<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Datele unui singur site, ținute în afara nucleului: fișiere JSON (fără PHP
 * executabil) în dosarul site-ului, scrise de ANDAXI (sau, mai târziu, de CRM),
 * niciodată în codul comun.
 *
 * Dosarul: `SITE_DATE_DOSAR` din .env (cale absolută sau relativă la rădăcina
 * codului); gol = `<rădăcină>/site` (ignorat de git, ca `storage/`). Un site
 * fără dosar sau fără fișiere merge cu valorile implicite ale nucleului.
 *
 * Fișierele de azi (vezi docs/SEO.md):
 *  - `redirecturi.json`: adresele vechi ale site-ului (301 sau 410);
 *  - `seo.json`: regulile SEO ale site-ului (etichetele din sitemap, logo-ul
 *    și numele pentru Google, reguli robots.txt în plus).
 *
 * Pachetul de date al unui site existent stă în `site-uri/<site>/` (de exemplu
 * `site-uri/bioscem/`) și se copiază în dosarul site-ului la instalare.
 *
 * Rulează și înainte de autoloader (din SeoRedirects), deci nu folosește alte
 * clase în afară de Env.
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
            $radacina = dirname(__DIR__, 2);
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
     * @return array<mixed>
     */
    public static function json(string $fisier): array
    {
        if (array_key_exists($fisier, self::$json)) {
            return self::$json[$fisier];
        }
        $cale = self::dosar() . '/' . basename($fisier);
        $date = [];
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
        $valoare = self::json('seo.json');
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
