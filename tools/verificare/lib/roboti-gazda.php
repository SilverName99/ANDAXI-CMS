<?php

declare(strict_types=1);

/**
 * robots.txt-ul site-ului de verificare, văzut din afară, când nu-l dă site-ul (docs/VERIFICARE.md, „robots.txt pe domeniul
 * temporar”). Pe un domeniu temporar Hostinger (`<nume>.hostingersite.com`) CDN-ul găzduirii (antetul `server: hcdn`)
 * răspunde singur la `/robots.txt`, fără să ajungă la PHP, cu textul `TEXT_HCDN` (văzut pe 21 oct 2026), iar pe un domeniu
 * temporar CDN-ul nu se poate opri. `stare.php` primește atunci acel text în locul celui al porții („Disallow: /”).
 *
 * `cdnHostinger()` spune dacă e exact cazul acesta, cu tot ce ține site-ul închis: răspunsul nu e al site-ului (n-are
 * niciunul dintre antetele pe care poarta le pune pe orice răspuns, `X-Robots-Tag` și `Cache-Control: no-store`), e al
 * CDN-ului Hostinger (`server: hcdn`, codul 200, exact `TEXT_HCDN`), domeniul e unul temporar Hostinger, poarta închide
 * site-ul (fără parolă 401; neconfigurat 503), răspunsurile site-ului au noindex și no-store, iar în cod robots.txt-ul
 * rămâne „Disallow: /”: al porții (`textPoarta()`, citit din src/Support/Verificare.php, fiindcă nicio cerere spre
 * /robots.txt nu ajunge la ea) și al aplicației (`Robots::text()`, garda 4). Atunci `stare.php` tipărește `INFO` (Googlebot
 * e oprit de găzduire, ceilalți roboți primesc 401 la poartă) și nu numără rândul ca NU. Orice altceva rămâne NU, cu motivul:
 * site-ul care răspunde cu alt robots.txt, alt text al CDN-ului, alt server, un domeniu propriu (acolo CDN-ul se poate opri),
 * robots.txt-ul porții sau al aplicației schimbat în cod.
 *
 * Pur (fără cereri): testele îl cheamă cu răspunsuri simulate (tools/dev/test-verificare.php, A). Sintaxa PHP 8.3; se
 * încarcă după verificarea versiunii.
 */

namespace Unelte\Verificare;

final class RobotiGazda
{
    /** Ce răspunde CDN-ul Hostinger la /robots.txt pe un domeniu temporar (fără spațiile de la capătul rândurilor). */
    public const TEXT_HCDN = "User-agent: Googlebot\nDisallow: /\n\nUser-agent: *\nAllow: /";

    /** Un domeniu temporar Hostinger: un singur nume înaintea lui `hostingersite.com`. */
    public const DOMENIU_TEMPORAR = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.hostingersite\.com$/';

    /**
     * Ramura porții pentru /robots.txt, cum e scrisă în src/Support/Verificare.php (spațiile și rândurile nu contează): un GET
     * sau HEAD pe /robots.txt primește „User-agent: * / Disallow: /”, înaintea parolei și a aplicației.
     */
    public const COD_POARTA = 'if ($citire && $cale === \'/robots.txt\') { self::raspundeText(200, "User-agent: *\nDisallow: /\n", $metoda); }';

    /** Codul porții (textul lui src/Support/Verificare.php) are ramura de mai sus, neschimbată? */
    public static function textPoarta(string $cod): bool
    {
        return str_contains((string) preg_replace('/\s+/', ' ', $cod), self::COD_POARTA);
    }

    /** Rândul INFO al lui stare.php când e cazul de mai sus (configurat: poarta răspunde 401; neconfigurat: 503). */
    public static function textInfo(bool $configurat): string
    {
        return '/robots.txt îl dă CDN-ul Hostinger (server: hcdn), nu site-ul: pe domeniul temporar CDN-ul nu se poate opri și răspunde '
            . 'singur „User-agent: Googlebot / Disallow: /” și „User-agent: * / Allow: /”. Googlebot e oprit de găzduire; ceilalți roboți '
            . 'primesc ' . ($configurat ? '401 la poartă (fără parolă nu văd nimic)' : '503 „neconfigurat”') . ', cu noindex și no-store; '
            . ($configurat ? 'în cod robots.txt-ul porții și al aplicației (Robots::text) rămân „Disallow: /”' : 'robots.txt-ul porții rămâne 503') . '. Nu e NU.';
    }

    /**
     * Cazul CDN-ului Hostinger pe domeniul temporar? Null: da (stare.php tipărește `textInfo()`); altfel de ce nu (rândul
     * rămâne NU, cu motivul în paranteză).
     *
     * @param array{cod: int, antete: array<string, string>, corp: string} $roboti răspunsul la /robots.txt (antetele cu litere mici)
     * @param ?string $gazda domeniul site-ului (dosarul ~/domains/<gazda>/public_html, același cu APP_URL)
     * @param bool $poartaInchisa fără parolă, / răspunde 401 (configurat) sau 503 (neconfigurat)
     * @param bool $noindexPeSite răspunsul site-ului la / are X-Robots-Tag: noindex și Cache-Control: no-store
     * @param bool $robotsDinCod `Robots::text()` dă „Disallow: /” (neconfigurat: răspunde poarta cu 503, deci true)
     * @param bool $robotsPoarta codul porții răspunde la /robots.txt cu „Disallow: /” (`textPoarta()`; neconfigurat: true)
     */
    public static function cdnHostinger(array $roboti, ?string $gazda, bool $poartaInchisa, bool $noindexPeSite, bool $robotsDinCod,
        bool $robotsPoarta): ?string
    {
        $antete = $roboti['antete'];
        if (isset($antete['x-robots-tag']) || stripos((string) ($antete['cache-control'] ?? ''), 'no-store') !== false) {
            return 'răspunsul vine de la site (are antetele porții), dar nu e cel așteptat';
        }
        $server = strtolower(trim((string) ($antete['server'] ?? '')));
        if ($server !== 'hcdn') {
            $arata = preg_replace('/[^A-Za-z0-9 .\/_()-]/', '', substr($server, 0, 40));
            return 'răspunsul nu vine de la site (n-are antetele porții) și nici de la CDN-ul Hostinger (server: '
                . ($arata !== '' ? $arata : 'lipsă') . ')';
        }
        $text = trim((string) preg_replace('/[ \t]+$/m', '', str_replace("\r", '', $roboti['corp'])));
        if ($roboti['cod'] !== 200 || $text !== self::TEXT_HCDN) {
            return 'CDN-ul Hostinger (hcdn) răspunde altceva decât textul lui știut (codul ' . $roboti['cod'] . ')';
        }
        if ($gazda === null || preg_match(self::DOMENIU_TEMPORAR, strtolower($gazda)) !== 1) {
            return 'îl dă CDN-ul Hostinger (hcdn), dar domeniul nu e unul temporar (….hostingersite.com): pe un domeniu propriu '
                . 'CDN-ul se oprește din hPanel, apoi stare.php din nou';
        }
        if (!$poartaInchisa) {
            return 'îl dă CDN-ul Hostinger (hcdn), dar poarta nu închide site-ul (mai sus)';
        }
        if (!$noindexPeSite) {
            return 'îl dă CDN-ul Hostinger (hcdn), dar răspunsurile site-ului n-au noindex și no-store (mai sus)';
        }
        if (!$robotsPoarta) {
            return 'îl dă CDN-ul Hostinger (hcdn), dar nici poarta (src/Support/Verificare.php) nu mai răspunde la /robots.txt cu „Disallow: /”';
        }
        if (!$robotsDinCod) {
            return 'îl dă CDN-ul Hostinger (hcdn), dar nici Robots::text() nu dă „Disallow: /”';
        }
        return null;
    }
}
