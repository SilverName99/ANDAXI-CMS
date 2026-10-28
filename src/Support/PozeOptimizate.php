<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pozele din pagini, partea din livrarea 4 SEO (A8 din plan):
 *
 *  - width/height pe pozele afișate de șabloane (pe lângă cele din livrările 1
 *    și 2), ca browserul să le rezerve locul înainte să le descarce; doar unde
 *    stilul șablonului dă deja pozei lățimea și înălțimea (sau înălțimea
 *    proporțională), deci pagina arată la fel. Dimensiunile vin din
 *    ImageDimensions (getimagesize, cu cache în storage/cache);
 *  - pozele mari ale unui site (la Bioscem cele 10 din plan, PNG de 0,6–2,4 MB)
 *    se afișează din fișiere noi, WebP sub 150 KB. Pozele vechi rămân pe server
 *    și în baza de date: datele structurate, fluxul Merchant Center și poza de
 *    distribuire le folosesc în continuare.
 *
 * Ce poze și cu ce fișiere noi sunt ale fiecărui site, deci stau în pachetul lui
 * (docs/PACHETE.md, docs/SEO.md „Pozele refăcute”): `poze-optimizate.json`
 *
 *   {"marimi_de_pe": "disc",
 *    "poze": {"/uploads/gallery/poza.png": {"noua": "/assets/img/optimizate/poza.webp", "octeti": 592991}, …}}
 *
 * iar fișierele noi în `public/` al pachetului (copiate peste `public/` la
 * adoptare). „octeti” e mărimea pozei vechi, o siguranță: dacă cineva încarcă în
 * galerie altă poză cu același nume, mărimea diferă și site-ul afișează poza
 * încărcată, nu varianta veche. Mărimea se ia de pe discul serverului (`stat -c %s`),
 * niciodată dintr-o descărcare: CDN-ul Hostinger trimite pozele refăcute, cu alți
 * octeți (lecția Bioscem `070e1af`: cu mărimile luate prin CDN nicio poză nu trecea
 * pe WebP). De aceea fișierul spune `"marimi_de_pe": "disc"` (tools/dev/verifica-pachet.php
 * nu-l primește altfel), iar `scripts/diagnostic.php --pachet` compară pe server fiecare
 * mărime cu fișierul de pe disc (PozeOptimizate::verificaPeDisc). Un pachet fără fișier
 * nu schimbă nicio poză (dimensiunile de mai sus rămân).
 *
 * Coșul, comanda, plata și contul nu se schimbă: șabloanele lor nu folosesc
 * clasa, iar în paginile din constructor se înlocuiește doar textul paginii,
 * înainte să intre formularele de coș sau de comandă.
 *
 * Oprire de urgență: SEO_POZE=0 în .env (fără dimensiunile noi și fără pozele
 * noi; dimensiunile din livrările 1 și 2 rămân).
 *
 * Din Bioscem `e2c97dc` și `070e1af` (livrarea 4 SEO, A8), cu lista pozelor mutată
 * din config/poze-optimizate.php în pachetul site-ului și căile prin Paths.
 */
final class PozeOptimizate
{
    /** Fișierul pachetului site-ului cu lista pozelor refăcute. */
    public const FISIER = 'poze-optimizate.json';

    /** @var array<string, array{0: string, 1: int}>|null poza veche => [poza nouă, octeții pozei vechi] */
    private static ?array $harta = null;
    /** @var array<string, string> poza veche => poza afișată (memorat pe cerere) */
    private static array $alese = [];

    /**
     * Pornit (SEO_POZE nu e 0) și pagina nu e de coș, comandă, plată, cont sau
     * admin (SeoRedirects::CAI_EXCLUSE): acolo antetul și pozele rămân la octet
     * ca înainte.
     */
    public static function activ(): bool
    {
        $valoare = strtolower(trim((string) Env::get('SEO_POZE', '1')));
        if (in_array($valoare, ['0', 'false', 'off', 'nu'], true)) {
            return false;
        }
        $cale = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];
        return !SeoRedirects::eExclusa($cale === '' ? '/' : $cale);
    }

    /**
     * Adresa de afișat pentru o poză: varianta nouă, dacă poza e una dintre cele
     * refăcute și fișierul vechi e tot cel refăcut (aceeași mărime); altfel
     * adresa primită, neschimbată.
     */
    public static function src(string $url): string
    {
        if ($url === '' || !self::activ()) {
            return $url;
        }
        try {
            $cale = self::caleLocala($url);
            if ($cale === null) {
                return $url;
            }
            if (!array_key_exists($cale, self::$alese)) {
                self::$alese[$cale] = self::alege($cale);
            }
            return self::$alese[$cale] !== '' ? self::$alese[$cale] : $url;
        } catch (\Throwable) {
            return $url;
        }
    }

    /**
     * ` width="…" height="…"` pentru o etichetă <img> nouă din livrarea 4 (gol
     * cu SEO_POZE=0, pentru SVG, pentru pozele altor site-uri sau dacă fișierul
     * nu se poate citi).
     */
    public static function dimensiuni(string $url): string
    {
        if ($url === '' || !self::activ()) {
            return '';
        }
        return ImageDimensions::atribute($url);
    }

    /**
     * Textul unei pagini din constructor (HTML din baza de date), cu pozele
     * refăcute luate din fișierele noi: se schimbă doar src-ul etichetelor <img>
     * care arată exact una dintre pozele din listă. Restul rămâne la octet.
     */
    public static function inHtml(string $html): string
    {
        if ($html === '' || !self::activ() || stripos($html, '<img') === false) {
            return $html;
        }
        try {
            $harta = self::harta();
            $areVreuna = false;
            foreach (array_keys($harta) as $veche) {
                if (str_contains($html, $veche)) {
                    $areVreuna = true;
                    break;
                }
            }
            if (!$areVreuna) {
                return $html;
            }
            $rezultat = preg_replace_callback(
                '/<img\b[^>]*>/i',
                static function (array $m): string {
                    return (string) preg_replace_callback(
                        '/(\ssrc\s*=\s*)(["\'])([^"\']*)\2/i',
                        static function (array $a): string {
                            $brut = html_entity_decode($a[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            $nou = self::src($brut);
                            if ($nou === $brut) {
                                return $a[0];
                            }
                            return $a[1] . $a[2] . htmlspecialchars($nou, ENT_QUOTES, 'UTF-8') . $a[2];
                        },
                        $m[0],
                        1
                    );
                },
                $html
            );
            return is_string($rezultat) ? $rezultat : $html;
        } catch (\Throwable) {
            return $html;
        }
    }

    /** Poza nouă pentru o cale din listă, sau '' dacă nu se folosește. */
    private static function alege(string $cale): string
    {
        $harta = self::harta();
        if (!isset($harta[$cale])) {
            return '';
        }
        [$noua, $octeti] = $harta[$cale];
        $public = Paths::public();
        $veche = $public . $cale;
        if (!is_file($public . $noua) || !is_file($veche) || (int) @filesize($veche) !== (int) $octeti) {
            return '';
        }
        return $noua;
    }

    /**
     * Pe server (scripts/diagnostic.php --pachet): pentru fiecare poză din pachet,
     * mărimea de pe disc a pozei vechi față de cea scrisă în pachet și dacă fișierul
     * nou există în public/. Un rând „diferă” înseamnă că acea poză nu trece pe WebP
     * (altă poză încărcată cu același nume, sau o mărime greșită în pachet: luată
     * prin CDN, nu cu stat de pe disc).
     *
     * @return list<array{veche: string, noua: string, octeti: int, pe_disc: ?int, noua_exista: bool}>
     */
    public static function verificaPeDisc(?string $radacinaPublic = null): array
    {
        $public = $radacinaPublic ?? Paths::public();
        $randuri = [];
        foreach (self::harta() as $veche => [$noua, $octeti]) {
            $peDisc = is_file($public . $veche) ? (int) @filesize($public . $veche) : null;
            $randuri[] = ['veche' => $veche, 'noua' => $noua, 'octeti' => $octeti, 'pe_disc' => $peDisc,
                'noua_exista' => is_file($public . $noua)];
        }
        return $randuri;
    }

    /**
     * Lista din pachet (poze-optimizate.json), verificată: căi din site („/…”, fără
     * „..”, ghilimele sau parametri), mărimi întregi pozitive, și doar cu
     * „marimi_de_pe”: „disc”. Altfel goală: nicio poză nu se schimbă.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    private static function harta(): array
    {
        if (self::$harta !== null) {
            return self::$harta;
        }
        self::$harta = [];
        $date = DateSite::json(self::FISIER);
        if (($date['marimi_de_pe'] ?? null) !== 'disc' || !is_array($date['poze'] ?? null)) {
            return self::$harta;
        }
        foreach ($date['poze'] as $veche => $rand) {
            $noua = is_array($rand) ? ($rand['noua'] ?? null) : null;
            $octeti = is_array($rand) ? ($rand['octeti'] ?? null) : null;
            if (is_string($veche) && self::caleBuna($veche) && is_string($noua) && self::caleBuna($noua)
                && is_int($octeti) && $octeti > 0) {
                self::$harta[$veche] = [$noua, $octeti];
            }
        }
        return self::$harta;
    }

    /** O cale din site: „/…”, fără „//”, „..”, ghilimele, spații sau parametri. */
    public static function caleBuna(string $cale): bool
    {
        return $cale !== '' && $cale[0] === '/' && !str_starts_with($cale, '//') && !str_contains($cale, '..')
            && preg_match('/["\'<>\s\\\\?#]/', $cale) !== 1;
    }

    /** Pentru teste: uită lista citită și alegerile (după DateSite::reseteaza). */
    public static function reseteaza(): void
    {
        self::$harta = null;
        self::$alese = [];
    }

    /**
     * Calea unei poze de pe acest site (relativă sau cu adresa site-ului, cu sau
     * fără www), fără parametri; null pentru orice altceva.
     */
    private static function caleLocala(string $url): ?string
    {
        $url = trim($url);
        if (preg_match('#^(https?:)?//([^/?\#]+)(/[^?\#]*)?#i', $url, $m) === 1) {
            $gazda = strtolower((string) preg_replace('/:\d+$/', '', $m[2]));
            $gazdaSite = strtolower((string) parse_url((string) (Env::get('APP_URL', '') ?? ''), PHP_URL_HOST));
            $gazdaCerere = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
            $gazde = array_filter([$gazdaSite, $gazdaCerere, $gazdaSite !== '' ? 'www.' . $gazdaSite : '']);
            if (!in_array($gazda, $gazde, true)) {
                return null;
            }
            return $m[3] ?? null;
        }
        if (!str_starts_with($url, '/')) {
            return null;
        }
        return explode('#', explode('?', $url, 2)[0], 2)[0];
    }
}
