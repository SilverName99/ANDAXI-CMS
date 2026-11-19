<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Politica CSP a unui site cu cadrul capturat (adoptarea WordPress, pasul 6; INSTRUCTIUNI-BUILDER-C2 §6, lecțiile CB-02/N4,
 * CB-05, CB-09, CB-11, CB-12): o singură politică pe tot site-ul, trimisă din `public/index.php` chiar înaintea cache-ului de
 * pagini (ResponseCache::beginRequest), deci aceeași la MISS, la HIT și la 304. Nu depinde de nimic din cerere (nici de gazdă:
 * adresele sunt relative sau fixe), doar de cale, pentru varianta cardurilor embed (fără `frame-ancestors`: le încadrează alte
 * site-uri).
 *
 *  - `script-src 'self' 'inline-speculation-rules'`: niciun script inline (C nu lasă să ruleze niciun script capturat; datele
 *    stau în `ax-date`, inert), fără hash-uri, fără `'unsafe-inline'` și fără `'unsafe-eval'`; cuvântul pentru blocul
 *    `speculationrules`, care rămâne în pagini ca date (D3) și căruia Chromium îi aplică `script-src`;
 *  - `style-src 'self' 'unsafe-inline'` (Divi are stiluri inline), `img-src 'self' data:`, `font-src 'self'`, `connect-src
 *    'self'`, `frame-src` doar harta Google, `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `worker-src 'none'`,
 *    `frame-ancestors 'self'`, `upgrade-insecure-requests`, `report-uri /_andaxi/raport`; plus sursele exacte din `tema.json` →
 *    `csp` (Tema::CSP_DIRECTIVE), iar `csp.mod` = `raport` o trimite ca `Content-Security-Policy-Report-Only` (copia de
 *    verificare, înainte de comutare);
 *  - `X-Content-Type-Options: nosniff` pe tot.
 *
 * Fără `tema.json` → `cadru` = `capturat` nu trimite nimic: site-urile de azi ies octet cu octet ca înainte (G-cmp).
 */
final class CspSite
{
    /** Harta Google Maps, singura sursă de iframe a cadrului capturat (ca lista albă a politicii HTML-ului capturat). */
    public const HARTA = 'https://www.google.com/maps/embed';

    /** Adresa rapoartelor CSP (RaportCsp, pasul 9a). */
    public const RAPORT = '/_andaxi/raport';

    /**
     * Politica, ca text de antet. `$embed`: varianta cardurilor embed (fără `frame-ancestors`).
     *
     * @param array<string, mixed> $extra sursele în plus, pe directivă (Tema::csp())
     */
    public static function politica(bool $embed = false, array $extra = []): string
    {
        $d = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'inline-speculation-rules'"],
            'style-src' => ["'self'", "'unsafe-inline'"],
            'img-src' => ["'self'", 'data:'],
            'font-src' => ["'self'"],
            'connect-src' => ["'self'"],
            'media-src' => ["'self'"],
            'frame-src' => [self::HARTA],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'worker-src' => ["'none'"],
        ];
        if (!$embed) {
            $d['frame-ancestors'] = ["'self'"];
        }
        foreach (Tema::CSP_DIRECTIVE as $dir) {
            foreach ((array) ($extra[$dir] ?? []) as $sursa) {
                if (is_string($sursa) && preg_match('#^https://[a-z0-9.-]+(?::[0-9]{2,5})?(?:/[A-Za-z0-9._~/-]*)?$#D', $sursa) === 1
                    && !in_array($sursa, $d[$dir], true)) {
                    $d[$dir][] = $sursa;
                }
            }
        }
        $parti = [];
        foreach ($d as $dir => $surse) {
            $parti[] = $dir . ' ' . implode(' ', $surse);
        }
        $parti[] = 'upgrade-insecure-requests';
        $parti[] = 'report-uri ' . self::RAPORT;
        return implode('; ', $parti);
    }

    /** O cale de card embed (`/<pagină>/embed/`, cu sau fără query). */
    public static function caleEmbed(string $uri): bool
    {
        $cale = (string) (parse_url($uri, PHP_URL_PATH) ?? '');
        return preg_match('#/embed/?$#', $cale) === 1 && $cale !== '/embed/' && $cale !== '/embed';
    }

    /**
     * Trimite antetele (pe cadrul capturat): politica, ca antet HTTP, și `nosniff`. Întoarce dacă a trimis ceva.
     */
    public static function trimite(string $uri, bool $raspunsCapturat = false): bool
    {
        if (Tema::cadru() !== 'capturat' || headers_sent()) {
            return false;
        }
        header('X-Content-Type-Options: nosniff');
        // Doar pe cadrul capturat (recenzia 2, R2-2): pe paginile CMS-ului însuși (adminul, contul, logarea, API-ul, paginile
        // pluginurilor) scripturile lor inline trebuie să ruleze, deci politica site-ului nu se trimite acolo. Cadrul răspunde
        // pe prima pagină, pe pagina generală, pe /contact și pe 404 (nicio rută); răspunsurile înghețate o cer singure.
        if (!$raspunsCapturat && !self::rutaCadru($uri)) {
            return false;
        }
        $csp = Tema::csp();
        $antet = ($csp['mod'] ?? 'aplica') === 'raport' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        header($antet . ': ' . self::politica(self::caleEmbed($uri), $csp));
        return true;
    }

    /** Tiparele de rută pe care răspunde cadrul capturat (SiteController: home, customPage, contact). */
    public const RUTE_CADRU = ['/', '/{slug*}', '/contact'];

    /** Cererea ajunge la cadrul capturat (o rută a lui sau nicio rută, deci 404-ul capturat)? */
    public static function rutaCadru(string $uri): bool
    {
        $cale = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        try {
            $t = \App\Support\ImportWordPress\Captura\CopiereStatica::rutaCms($cale);
        } catch (\Throwable) {
            return true; // la îndoială, politica strictă
        }
        return $t === null || in_array($t, self::RUTE_CADRU, true);
    }
}
