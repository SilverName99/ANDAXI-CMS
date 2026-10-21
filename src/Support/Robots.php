<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Regulile din robots.txt, într-un singur loc: le folosesc și robots.txt, și
 * sitemap-ul (care nu are voie să trimită lui Google adrese blocate).
 *
 * Regulile sunt exacte, nu prefixe largi: „Disallow: /cos” ar fi blocat și o
 * viitoare pagină /cosmetice. Coșul e blocat prin căile lui reale: /cos (doar
 * ea, cu „$”, pentru Google și Bing), /cos? (cu parametri) și /cos/ (acțiunile
 * coșului). Un robot care nu înțelege „$” o citește ca pe o cale care nu
 * există, deci nu blochează nimic în plus.
 *
 * Fiecare regulă are pluginul ei: robots.txt pomenește doar adresele
 * pluginurilor de pe site (celelalte dau oricum 404). Site-ul poate adăuga
 * reguli proprii în seo.json → robots_disallow (de exemplu pagina lui de coș
 * din constructor). robots.txt rămâne același și în modul mentenanță: paginile
 * răspund atunci 503 cu Retry-After, pe când un „Disallow: /” ar fi ținut minte
 * de Google până la o zi după ce mentenanța s-a terminat.
 */
final class Robots
{
    /**
     * Adrese spuse explicit „voie” (nimic nu le blochează, dar Merchant Center
     * verifică robots.txt înainte să preia fluxul de produse). Fluxul nu e
     * legat din nicio pagină și nu intră în sitemap. Ca la DISALLOW, fiecare cu
     * pluginul ei: fără magazin pe site, fluxul dă 404 și linia lipsește.
     *
     * @var array<string, string> adresa => al cui e
     */
    public const ALLOW = [
        '/feed/google-merchant.xml' => 'magazin',
    ];

    /** @var array<string, string> regula => al cui e („nucleu” sau cheia pluginului) */
    public const DISALLOW = [
        '/admin' => 'nucleu',
        '/api/' => 'nucleu',
        '/auth/' => 'nucleu',
        '/webhook/' => 'nucleu',
        // Adresele platformei (pingul monitorizării, App\Support\Ping): nu sunt pagini.
        '/_andaxi/' => 'nucleu',
        '/cos$' => 'magazin',
        '/cos?' => 'magazin',
        '/cos/' => 'magazin',
        '/checkout' => 'magazin',
        '/contul-meu' => 'conturi_clienti',
        '/login' => 'conturi_clienti',
        '/register' => 'conturi_clienti',
        '/plata/' => 'link_plata',
        '/newsletter/' => 'newsletter',
        '/gdpr-agreements' => 'acorduri_gdpr',
    ];

    /**
     * Regulile de pe acest site: ale nucleului, ale pluginurilor vizibile și
     * cele din seo.json, în ordine, fără dubluri.
     *
     * @return list<string>
     */
    public static function reguli(): array
    {
        $reguli = [];
        foreach (self::DISALLOW as $regula => $plugin) {
            if ($plugin === 'nucleu' || Plugins::vizibil($plugin)) {
                $reguli[] = $regula;
            }
        }
        foreach (self::reguliSite() as $regula) {
            $reguli[] = $regula;
        }
        return array_values(array_unique($reguli));
    }

    /** Textul complet al robots.txt. Același și în modul mentenanță. */
    public static function text(string $baseUrl): string
    {
        $linii = ['User-agent: *'];
        foreach (self::ALLOW as $regula => $plugin) {
            if ($plugin === 'nucleu' || Plugins::vizibil($plugin)) {
                $linii[] = 'Allow: ' . $regula;
            }
        }
        foreach (self::reguli() as $regula) {
            $linii[] = 'Disallow: ' . $regula;
        }
        $linii[] = '';
        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl !== '') {
            $linii[] = 'Sitemap: ' . $baseUrl . '/sitemap.xml';
        }

        return implode("\n", $linii) . "\n";
    }

    /**
     * Calea (cu query, dacă are) e blocată de una dintre reguli (toate, nu doar
     * cele pomenite acum)? Potrivire ca la Google: prefix, „$” = sfârșitul adresei.
     */
    public static function blocheaza(string $caleCuQuery): bool
    {
        if ($caleCuQuery === '' || $caleCuQuery[0] !== '/') {
            $caleCuQuery = '/' . $caleCuQuery;
        }
        foreach (array_merge(array_keys(self::DISALLOW), self::reguliSite()) as $regula) {
            if (str_ends_with($regula, '$')) {
                if ($caleCuQuery === substr($regula, 0, -1)) {
                    return true;
                }
                continue;
            }
            if (str_starts_with($caleCuQuery, $regula)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> regulile site-ului din seo.json (robots_disallow), doar căi „/…” */
    private static function reguliSite(): array
    {
        $reguli = [];
        foreach ((array) DateSite::seo('robots_disallow', []) as $regula) {
            $regula = trim((string) $regula);
            if ($regula !== '' && $regula[0] === '/' && preg_match('/[\x00-\x20\x7f]/', $regula) !== 1) {
                $reguli[] = $regula;
            }
        }
        return $reguli;
    }
}
