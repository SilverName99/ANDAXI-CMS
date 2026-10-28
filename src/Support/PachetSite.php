<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Manifestul pachetului de date al unui site, `pachet.json` (docs/PACHETE.md): formatul, al cui site e, fiecare fișier
 * cu sha256-ul lui și fișierele fără de care site-ul s-ar schimba fără să spună nimic (`obligatorii`).
 *
 *   {"format": 1, "site": "bioscem", "fisiere": {"seo.json": "<sha256>", …}, "obligatorii": ["redirecturi.json", "seo.json"]}
 *
 * Îl scrie `tools/dev/verifica-pachet.php --actualizeaza-manifest` (determinist: fișierele în ordine, aceleași octeți la
 * fiecare rulare). `verifica()` spune dacă un dosar e exact pachetul din manifest: îl folosesc unealta de verificare
 * (fiecare pachet din repo, în testul de fum), `scripts/diagnostic.php --pachet` (pe server, înaintea unei actualizări)
 * și, mai târziu, sonda pachetului (WP-14, V12) și scripturile de actualizare din Etapa 2.
 *
 * `privat/` (valorile private ale înghețării, ignorate de git, decizia OD-49) nu intră în manifest: nu sunt în repo,
 * deci nu au un sha256 de referință.
 *
 * Fără dependențe în afara PHP-ului (rulează și pe un server, din linia de comandă).
 */
final class PachetSite
{
    public const FORMAT = 1;
    public const MANIFEST = 'pachet.json';
    public const DOSAR_PRIVAT = 'privat';

    /**
     * Fișierele pachetului (căi relative, „/” între dosare, în ordine), fără manifest și fără `privat/`. Legăturile
     * simbolice apar și ele (ca fișiere), ca verificarea să le poată refuza.
     *
     * @return list<string>
     */
    public static function fisiere(string $dosar): array
    {
        $dosar = rtrim($dosar, '/');
        $lista = [];
        $parcurge = static function (string $relativ) use (&$parcurge, &$lista, $dosar): void {
            $cale = $dosar . ($relativ !== '' ? '/' . $relativ : '');
            $intrari = @scandir($cale);
            if (!is_array($intrari)) {
                return;
            }
            foreach ($intrari as $nume) {
                if ($nume === '.' || $nume === '..') {
                    continue;
                }
                $rel = $relativ !== '' ? $relativ . '/' . $nume : $nume;
                if ($rel === self::MANIFEST || $rel === self::DOSAR_PRIVAT) {
                    continue;
                }
                $complet = $dosar . '/' . $rel;
                if (is_dir($complet) && !is_link($complet)) {
                    $parcurge($rel);
                } else {
                    $lista[] = $rel;
                }
            }
        };
        $parcurge('');
        sort($lista, SORT_STRING);
        return $lista;
    }

    /**
     * Manifestul citit, sau null dacă lipsește ori nu e un obiect JSON.
     *
     * @return array<string, mixed>|null
     */
    public static function manifest(string $dosar): ?array
    {
        $brut = @file_get_contents(rtrim($dosar, '/') . '/' . self::MANIFEST);
        if (!is_string($brut)) {
            return null;
        }
        $date = json_decode($brut, true);
        return is_array($date) && !array_is_list($date) ? $date : null;
    }

    /**
     * Manifestul pe care l-ar scrie `--actualizeaza-manifest` pentru dosarul de acum: `site` și `obligatorii` din
     * manifestul existent (sau cele date), `fisiere` recalculate.
     *
     * @param list<string>|null $obligatorii null = cele din manifestul existent, altfel fișierele citite la cerere
     * @return array{format: int, site: string, fisiere: array<string, string>, obligatorii: list<string>}
     */
    public static function calculeaza(string $dosar, string $site, ?array $obligatorii = null): array
    {
        $dosar = rtrim($dosar, '/');
        $fisiere = [];
        foreach (self::fisiere($dosar) as $relativ) {
            $fisiere[$relativ] = is_link($dosar . '/' . $relativ) ? 'legatura' : (string) hash_file('sha256', $dosar . '/' . $relativ);
        }
        if ($obligatorii === null) {
            $existent = self::manifest($dosar);
            $obligatorii = is_array($existent['obligatorii'] ?? null)
                ? array_values(array_map('strval', $existent['obligatorii']))
                : array_values(array_intersect(['redirecturi.json', 'seo.json', 'site.json', Tema::FISIER, PozeOptimizate::FISIER], array_keys($fisiere)));
        }
        return ['format' => self::FORMAT, 'site' => $site, 'fisiere' => $fisiere, 'obligatorii' => $obligatorii];
    }

    /** Textul fișierului `pachet.json` pentru un manifest (determinist). */
    public static function text(array $manifest): string
    {
        return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * Ce nu se potrivește între dosar și manifestul lui (gol = pachetul e exact cel din manifest): manifestul lipsă sau
     * stricat, alt format, alt site decât `$site` (dacă e dat), un fișier lipsă, în plus sau cu alt sha256, un fișier
     * obligatoriu nelistat sau lipsă.
     *
     * @return list<string>
     */
    public static function verifica(string $dosar, ?string $site = null): array
    {
        $dosar = rtrim($dosar, '/');
        if (!is_dir($dosar)) {
            return ['dosarul pachetului lipsește: ' . $dosar];
        }
        $manifest = self::manifest($dosar);
        if ($manifest === null) {
            return [self::MANIFEST . ' lipsește sau nu e un obiect JSON'];
        }
        $probleme = [];
        if (($manifest['format'] ?? null) !== self::FORMAT) {
            $probleme[] = self::MANIFEST . ': formatul ' . json_encode($manifest['format'] ?? null) . ', aștept ' . self::FORMAT;
        }
        $siteManifest = $manifest['site'] ?? null;
        if (!is_string($siteManifest) || preg_match('/^[a-z0-9][a-z0-9-]*$/', $siteManifest) !== 1) {
            $probleme[] = self::MANIFEST . ': „site” lipsește sau nu e un nume de site';
        } elseif ($site !== null && $siteManifest !== $site) {
            $probleme[] = self::MANIFEST . ': pachetul e al site-ului „' . $siteManifest . '”, nu al lui „' . $site . '”';
        }
        $declarate = $manifest['fisiere'] ?? null;
        if (!is_array($declarate) || ($declarate !== [] && array_is_list($declarate))) {
            $probleme[] = self::MANIFEST . ': „fisiere” lipsește sau nu e un obiect';
            $declarate = [];
        }
        $reale = self::fisiere($dosar);
        foreach ($reale as $relativ) {
            if (!array_key_exists($relativ, $declarate)) {
                $probleme[] = 'fișier care nu e în ' . self::MANIFEST . ': ' . $relativ;
            } elseif (is_link($dosar . '/' . $relativ) || !hash_equals((string) $declarate[$relativ], (string) hash_file('sha256', $dosar . '/' . $relativ))) {
                $probleme[] = 'fișier schimbat față de ' . self::MANIFEST . ' (sha256): ' . $relativ;
            }
        }
        foreach (array_diff(array_map('strval', array_keys($declarate)), $reale) as $lipsa) {
            $probleme[] = 'fișier din ' . self::MANIFEST . ' care lipsește: ' . $lipsa;
        }
        $obligatorii = $manifest['obligatorii'] ?? [];
        if (!is_array($obligatorii) || ($obligatorii !== [] && !array_is_list($obligatorii))) {
            $probleme[] = self::MANIFEST . ': „obligatorii” nu e o listă';
            $obligatorii = [];
        }
        foreach ($obligatorii as $obligatoriu) {
            if (!is_string($obligatoriu) || !array_key_exists($obligatoriu, $declarate)) {
                $probleme[] = self::MANIFEST . ': fișierul obligatoriu „' . (is_string($obligatoriu) ? $obligatoriu : '?') . '” nu e în „fisiere”';
            } elseif (!is_file($dosar . '/' . $obligatoriu)) {
                $probleme[] = 'fișierul obligatoriu lipsește: ' . $obligatoriu;
            }
        }
        return $probleme;
    }

    /** Amprenta pachetului: sha256-ul manifestului (primele 12 caractere), sau null fără manifest. */
    public static function amprenta(string $dosar): ?string
    {
        $cale = rtrim($dosar, '/') . '/' . self::MANIFEST;
        return is_file($cale) ? substr((string) hash_file('sha256', $cale), 0, 12) : null;
    }
}
