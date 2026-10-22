<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Tema site-ului: `tema/tema.json` din dosarul site-ului (DateSite::dosar(); formatul pachetului: docs/PACHETE.md).
 * Doar date (JSON și fișiere CSS, imagini, fonturi, scripturile declarate), niciun PHP: tot ce face o temă trece pe
 * aici, prin cheile de mai jos, în punctele lor din `views/layout.php` și din câteva vederi de rezervă (GR-18, DB-01).
 *
 * **Fără `tema.json` (sau cu fiecare cheie la valoarea ei implicită) paginile ies octet cu octet ca înainte**: fiecare
 * cheie are ca implicit exact ce făcea nucleul (comparația cu Bioscem, G-cmp; tools/dev/test-pluginuri.php, T).
 *
 * | Cheie | Implicit | Ce face |
 * |---|---|---|
 * | `css` | `[]` | foi de stil ale temei (`/tema/…css`), după `app.css`, înaintea CSS-ului din Design |
 * | `incarca_app_css` | `true` | `false`: paginile publice nu mai încarcă `/assets/css/app.css` (adminul da, din layoutul lui) |
 * | `fonturi` | `[]` | fonturile din nucleu (`/assets/vendor/fonturi/<familie>/<familie>.css`) |
 * | `fonturi_google` | `true` | `false`: fără legătura spre Google Fonts (DM Sans, Playfair) |
 * | `bootstrap` | `false` | `true`: Bootstrap 5.3.8 din nucleu (CSS în head, JS cu defer la final) |
 * | `js_final` | `[]` | scripturile temei (`/tema/…js`), cu defer, înainte de `</body>` |
 * | `main` | `container` | `lat`: `<main class="site-container">`, fără `.container` (secțiunile își pun singure containerul) |
 * | `flash` | `panou` | `bootstrap`: mesajele ca `.alert` într-un `.container` propriu |
 * | `buton_sus` | `false` | `true`: butonul „Înapoi sus” (ancora `#inceput-pagina`, apare după un ecran derulat) |
 * | `culoare_principala` | `#2f8d5b` | culoarea butoanelor paginilor de rezervă (404, linkul de plată, verificarea BT) și a bannerelor |
 * | `logo_meniu_mobil` | (lipsă) | `{candidati, implicit, alt}`: logo-ul sertarului `{{mobile_menu}}` (primul fișier care există) |
 * | `iconite_categorii` | (lipsă) | `[[cuvânt, svg], …]`: iconițele `{{product_categories}}`, încercate în ordine înaintea celei implicite |
 * | `css_inline` | `{}` | `{"pagina_succes": "pagina-succes.css"}`: un bloc `<style>` al nucleului înlocuit cu fișierul din `tema/` |
 *
 * Fișierele publice ale temei stau în pachet în `tema/public/` și se servesc la `/tema/…`: pe server din
 * `public/tema/` (le copiază acolo instalarea și fiecare actualizare a pachetului), în dezvoltare direct din pachet
 * (tools/dev/router.php). Adresele primesc `?v=<data fișierului>`.
 *
 * O valoare greșită nu oprește site-ul: cheia rămâne la implicit (un element greșit dintr-o listă se scoate) și se scrie
 * un rând în jurnalul PHP. `tools/dev/verifica-pachet.php` refuză un pachet cu astfel de probleme (`valideaza()`).
 *
 * Rulează în pagini, după autoloader; folosește doar DateSite, Paths și SvgSigur.
 */
final class Tema
{
    public const FISIER = 'tema/tema.json';
    public const CULOARE_IMPLICITA = '#2f8d5b';
    public const BOOTSTRAP_CSS = '/assets/vendor/bootstrap/css/bootstrap.min.css';
    public const BOOTSTRAP_JS = '/assets/vendor/bootstrap/js/bootstrap.bundle.min.js';

    /** Valorile implicite: exact ce face nucleul fără temă. */
    public const IMPLICIT = [
        'css' => [],
        'incarca_app_css' => true,
        'fonturi' => [],
        'fonturi_google' => true,
        'bootstrap' => false,
        'js_final' => [],
        'main' => 'container',
        'flash' => 'panou',
        'buton_sus' => false,
        'culoare_principala' => self::CULOARE_IMPLICITA,
        'logo_meniu_mobil' => null,
        'iconite_categorii' => null,
        'css_inline' => [],
    ];

    /** Blocurile `<style>` ale nucleului pe care o temă le poate înlocui (`css_inline`). */
    public const BLOCURI_INLINE = ['pagina_succes'];

    /** @var array<string, mixed>|null */
    private static ?array $valori = null;
    /** @var list<string> */
    private static array $probleme = [];
    /** @var array<string, string> */
    private static array $continutInline = [];

    // -----------------------------------------------------------------
    // Cheile, pentru vederi
    // -----------------------------------------------------------------

    /** @return list<string> adresele foilor de stil ale temei (`/tema/…css`) */
    public static function css(): array
    {
        return self::existente((array) self::valori()['css']);
    }

    public static function incarcaAppCss(): bool
    {
        return (bool) self::valori()['incarca_app_css'];
    }

    /** @return list<string> foile de stil ale fonturilor din nucleu */
    public static function fonturi(): array
    {
        return self::existente((array) self::valori()['fonturi']);
    }

    public static function fonturiGoogle(): bool
    {
        return (bool) self::valori()['fonturi_google'];
    }

    public static function bootstrap(): bool
    {
        return (bool) self::valori()['bootstrap'];
    }

    /** @return list<string> scripturile temei (`/tema/…js`), în ordine */
    public static function jsFinal(): array
    {
        return self::existente((array) self::valori()['js_final']);
    }

    /** `container` (implicit) sau `lat`. */
    public static function main(): string
    {
        return (string) self::valori()['main'];
    }

    /** Clasa lui `<main>` după cheia `main`. */
    public static function clasaMain(): string
    {
        return self::main() === 'lat' ? 'site-container' : 'container site-container';
    }

    /** `panou` (implicit) sau `bootstrap`. */
    public static function flash(): string
    {
        return (string) self::valori()['flash'];
    }

    public static function butonSus(): bool
    {
        return (bool) self::valori()['buton_sus'];
    }

    /** Culoarea principală a paginilor de rezervă (`#rgb` sau `#rrggbb`, deja verificată). */
    public static function culoarePrincipala(): string
    {
        return (string) self::valori()['culoare_principala'];
    }

    /**
     * Logo-ul sertarului de meniu mobil al temei: primul candidat care există ca fișier, altfel cel implicit, cu textul
     * lui alternativ. Null fără cheia în temă (atunci layoutul face ce făcea înainte).
     *
     * @return array{cale: string, alt: string}|null
     */
    public static function logoMeniuMobil(): ?array
    {
        $logo = self::valori()['logo_meniu_mobil'];
        if (!is_array($logo)) {
            return null;
        }
        // Un fișier al temei (/tema/…) primește versiunea, ca orice fișier al temei; pozele din galerie rămân ca azi.
        $adresa = static fn (string $cale): string => str_starts_with($cale, '/tema/') ? self::url($cale) : $cale;
        foreach ((array) $logo['candidati'] as $candidat) {
            $fisier = self::fisierPublic((string) $candidat);
            if ($fisier !== null && is_file($fisier)) {
                return ['cale' => $adresa((string) $candidat), 'alt' => (string) $logo['alt']];
            }
        }
        return ['cale' => $adresa((string) $logo['implicit']), 'alt' => (string) $logo['alt']];
    }

    /**
     * Iconițele categoriilor ale temei, `[cuvânt, svg]` în ordine (fiecare svg trecut prin SvgSigur::fragment). Null
     * fără cheia în temă (atunci HomeSections folosește lista lui).
     *
     * @return list<array{0: string, 1: string}>|null
     */
    public static function iconiteCategorii(): ?array
    {
        $perechi = self::valori()['iconite_categorii'];
        return is_array($perechi) ? $perechi : null;
    }

    /**
     * Conținutul unui bloc `<style>` al nucleului: fișierul temei (`css_inline.<nume>`, din `tema/`), altfel $implicit.
     * Un fișier care lipsește sau conține `</style` nu se folosește (rândul din jurnal îl scrie valori()).
     */
    public static function cssInline(string $nume, string $implicit): string
    {
        $harta = (array) self::valori()['css_inline'];
        if (!isset($harta[$nume])) {
            return $implicit;
        }
        return self::$continutInline[$nume] ?? $implicit;
    }

    /**
     * Adresa unui fișier public al temei sau al nucleului, cu `?v=<data fișierului>` (o temă schimbată ajunge imediat
     * la vizitatori, fără cache vechi). Fără fișier, adresa rămâne fără versiune.
     */
    public static function url(string $cale): string
    {
        $fisier = self::fisierPublic($cale);
        $data = $fisier !== null ? @filemtime($fisier) : false;
        return $cale . ($data !== false ? '?v=' . $data : '');
    }

    /**
     * Fișierul de pe disc din spatele unei adrese publice (`/tema/…`, `/assets/…`, `/uploads/…`), sau null pentru o
     * adresă care nu e una dintre acestea (sau are „..”, „//”). `/tema/…`: întâi `public/tema/` (pe server), apoi
     * `tema/public/` din pachet (dezvoltarea, sau un pachet încă necopiat).
     */
    public static function fisierPublic(string $cale): ?string
    {
        if (preg_match('#^/(tema|assets|uploads)/((?:[A-Za-z0-9_][A-Za-z0-9._-]*/)*[A-Za-z0-9_][A-Za-z0-9._-]*)$#', $cale, $m) !== 1
            || str_contains($m[2], '..')) {
            return null;
        }
        if ($m[1] === 'uploads') {
            return Paths::uploads($m[2]);
        }
        if ($m[1] === 'assets') {
            return Paths::public('assets/' . $m[2]);
        }
        $dinDocroot = Paths::public('tema/' . $m[2]);
        if (is_file($dinDocroot)) {
            return $dinDocroot;
        }
        return DateSite::dosar() . '/tema/public/' . $m[2];
    }

    // -----------------------------------------------------------------
    // Citirea și verificarea
    // -----------------------------------------------------------------

    /**
     * Valorile temei site-ului, verificate și completate cu cele implicite (o dată pe cerere).
     *
     * @return array<string, mixed>
     */
    public static function valori(): array
    {
        if (self::$valori !== null) {
            return self::$valori;
        }
        $dosar = DateSite::dosar();
        $brut = is_file($dosar . '/' . self::FISIER) ? DateSite::json(self::FISIER) : [];
        [$valori, $probleme] = self::valideaza($brut, $dosar, false);
        self::$probleme = $probleme;
        self::$continutInline = [];
        foreach ((array) $valori['css_inline'] as $nume => $fisier) {
            $continut = @file_get_contents($dosar . '/tema/' . $fisier);
            if (is_string($continut)) {
                self::$continutInline[(string) $nume] = $continut;
            }
        }
        if ($probleme !== []) {
            error_log('ANDAXI-CMS: ' . $dosar . '/' . self::FISIER . ': ' . implode('; ', $probleme) . ' (se folosesc valorile implicite).');
        }
        return self::$valori = $valori;
    }

    /** @return list<string> ce nu e în regulă în tema site-ului (gol = nimic) */
    public static function probleme(): array
    {
        self::valori();
        return self::$probleme;
    }

    /**
     * Verifică un `tema.json` citit (`$brut`) față de pachetul din `$dosarPachet`. Întoarce valorile de folosit (fiecare
     * cheie greșită la implicit, elementele greșite ale listelor scoase) și problemele găsite. `$strict` (unealta de
     * verificare): fișierele temei se caută doar în pachet (`tema/public/`), nu și în `public/tema/`.
     *
     * @param array<mixed> $brut
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function valideaza(array $brut, string $dosarPachet, bool $strict = true): array
    {
        $valori = self::IMPLICIT;
        $probleme = [];
        if ($brut !== [] && array_is_list($brut)) {
            return [$valori, ['tema.json trebuie să fie un obiect JSON, nu o listă']];
        }
        $dosarPachet = rtrim($dosarPachet, '/');
        $existaInTema = static function (string $relativ) use ($dosarPachet, $strict): bool {
            return is_file($dosarPachet . '/tema/public/' . $relativ) || (!$strict && is_file(Paths::public('tema/' . $relativ)));
        };
        foreach ($brut as $cheie => $valoare) {
            $cheie = (string) $cheie;
            if (str_starts_with($cheie, '_')) {
                continue;
            }
            if (!array_key_exists($cheie, self::IMPLICIT)) {
                $probleme[] = 'cheie necunoscută „' . $cheie . '”';
                continue;
            }
            switch ($cheie) {
                case 'incarca_app_css':
                case 'fonturi_google':
                case 'bootstrap':
                case 'buton_sus':
                    if (is_bool($valoare)) {
                        $valori[$cheie] = $valoare;
                    } else {
                        $probleme[] = $cheie . ' trebuie să fie true sau false';
                    }
                    break;
                case 'main':
                case 'flash':
                    $permise = $cheie === 'main' ? ['container', 'lat'] : ['panou', 'bootstrap'];
                    if (is_string($valoare) && in_array($valoare, $permise, true)) {
                        $valori[$cheie] = $valoare;
                    } else {
                        $probleme[] = $cheie . ' trebuie să fie una dintre: ' . implode(', ', $permise);
                    }
                    break;
                case 'culoare_principala':
                    if (is_string($valoare) && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $valoare) === 1) {
                        $valori[$cheie] = $valoare;
                    } else {
                        $probleme[] = 'culoare_principala trebuie să fie o culoare #rgb sau #rrggbb';
                    }
                    break;
                case 'css':
                case 'js_final':
                    $extensie = $cheie === 'css' ? 'css' : 'js';
                    $lista = [];
                    foreach (self::lista($valoare, $cheie, $probleme) as $adresa) {
                        $relativ = self::relativTema($adresa, $extensie);
                        if ($relativ === null) {
                            $probleme[] = $cheie . ': „' . $adresa . '” nu e o adresă /tema/….' . $extensie;
                        } elseif (!$existaInTema($relativ)) {
                            $probleme[] = $cheie . ': „' . $adresa . '” lipsește din tema/public/';
                        } else {
                            $lista[] = $adresa;
                        }
                    }
                    $valori[$cheie] = $lista;
                    break;
                case 'fonturi':
                    $lista = [];
                    foreach (self::lista($valoare, $cheie, $probleme) as $adresa) {
                        if (preg_match('#^/assets/vendor/fonturi/([a-z0-9-]+)/\1\.css$#', $adresa) !== 1) {
                            $probleme[] = 'fonturi: „' . $adresa . '” nu e un font din nucleu (/assets/vendor/fonturi/<familie>/<familie>.css)';
                        } elseif (!is_file(Paths::public(ltrim($adresa, '/')))) {
                            $probleme[] = 'fonturi: „' . $adresa . '” nu există în nucleu';
                        } else {
                            $lista[] = $adresa;
                        }
                    }
                    $valori[$cheie] = $lista;
                    break;
                case 'logo_meniu_mobil':
                    $logo = self::logoValid($valoare);
                    if (is_string($logo)) {
                        $probleme[] = 'logo_meniu_mobil: ' . $logo;
                    } else {
                        $valori[$cheie] = $logo;
                    }
                    break;
                case 'iconite_categorii':
                    if (!is_array($valoare) || ($valoare !== [] && !array_is_list($valoare))) {
                        $probleme[] = 'iconite_categorii trebuie să fie o listă de perechi [cuvânt, svg]';
                        break;
                    }
                    $perechi = [];
                    foreach ($valoare as $i => $pereche) {
                        if (!is_array($pereche) || count($pereche) !== 2 || !array_is_list($pereche)
                            || !is_string($pereche[0]) || !is_string($pereche[1])) {
                            $probleme[] = 'iconite_categorii[' . $i . '] nu e o pereche [cuvânt, svg]';
                            continue;
                        }
                        if (preg_match('/^[a-z0-9][a-z0-9 -]*$/', $pereche[0]) !== 1) {
                            $probleme[] = 'iconite_categorii[' . $i . ']: cuvântul „' . $pereche[0] . '” trebuie scris cu litere mici, fără diacritice';
                            continue;
                        }
                        $problema = SvgSigur::problemaFragment($pereche[1]);
                        if ($problema !== null) {
                            $probleme[] = 'iconite_categorii[' . $i . '] („' . $pereche[0] . '”): ' . $problema;
                            continue;
                        }
                        $perechi[] = [$pereche[0], $pereche[1]];
                    }
                    $valori[$cheie] = $perechi;
                    break;
                case 'css_inline':
                    if (!is_array($valoare) || ($valoare !== [] && array_is_list($valoare))) {
                        $probleme[] = 'css_inline trebuie să fie un obiect {"bloc": "fișier.css"}';
                        break;
                    }
                    $harta = [];
                    foreach ($valoare as $bloc => $fisier) {
                        $bloc = (string) $bloc;
                        if (!in_array($bloc, self::BLOCURI_INLINE, true)) {
                            $probleme[] = 'css_inline: blocul necunoscut „' . $bloc . '” (se pot înlocui: ' . implode(', ', self::BLOCURI_INLINE) . ')';
                        } elseif (!is_string($fisier) || preg_match('/^[a-z0-9][a-z0-9_-]*\.css$/', $fisier) !== 1) {
                            $probleme[] = 'css_inline.' . $bloc . ' trebuie să fie numele unui fișier .css din tema/';
                        } elseif (!is_file($dosarPachet . '/tema/' . $fisier)) {
                            $probleme[] = 'css_inline.' . $bloc . ': tema/' . $fisier . ' lipsește';
                        } elseif (preg_match('#</style#i', (string) file_get_contents($dosarPachet . '/tema/' . $fisier)) === 1) {
                            $probleme[] = 'css_inline.' . $bloc . ': tema/' . $fisier . ' conține „</style”';
                        } else {
                            $harta[$bloc] = $fisier;
                        }
                    }
                    $valori[$cheie] = $harta;
                    break;
            }
        }
        return [$valori, $probleme];
    }

    /** Doar pentru teste și unelte: uită tema citită (alt dosar, fișiere schimbate). */
    public static function reseteaza(): void
    {
        self::$valori = null;
        self::$probleme = [];
        self::$continutInline = [];
    }

    // -----------------------------------------------------------------

    /** Calea din `tema/public/` a unei adrese `/tema/<cale>.<extensie>`, sau null. */
    public static function relativTema(string $adresa, string $extensie): ?string
    {
        if (preg_match('#^/tema/((?:[A-Za-z0-9_][A-Za-z0-9._-]*/)*[A-Za-z0-9_][A-Za-z0-9._-]*\.' . preg_quote($extensie, '#') . ')$#', $adresa, $m) !== 1
            || str_contains($m[1], '..')) {
            return null;
        }
        return $m[1];
    }

    /**
     * @param list<string> $probleme
     * @return list<string>
     */
    private static function lista(mixed $valoare, string $cheie, array &$probleme): array
    {
        if (!is_array($valoare) || ($valoare !== [] && !array_is_list($valoare))) {
            $probleme[] = $cheie . ' trebuie să fie o listă de adrese';
            return [];
        }
        $lista = [];
        foreach ($valoare as $element) {
            if (!is_string($element)) {
                $probleme[] = $cheie . ': un element nu e text';
                continue;
            }
            $lista[] = $element;
        }
        return $lista;
    }

    /** @return array{candidati: list<string>, implicit: string, alt: string}|string valoarea bună sau problema */
    private static function logoValid(mixed $valoare): array|string
    {
        if (!is_array($valoare) || array_is_list($valoare)) {
            return 'trebuie să fie un obiect {candidati, implicit, alt}';
        }
        $necunoscute = array_diff(array_filter(array_keys($valoare), static fn ($k): bool => !str_starts_with((string) $k, '_')),
            ['candidati', 'implicit', 'alt']);
        if ($necunoscute !== []) {
            return 'chei necunoscute: ' . implode(', ', $necunoscute);
        }
        $candidati = $valoare['candidati'] ?? null;
        if (!is_array($candidati) || ($candidati !== [] && !array_is_list($candidati))) {
            return 'candidati trebuie să fie o listă de adrese';
        }
        foreach (array_merge($candidati, [$valoare['implicit'] ?? null]) as $adresa) {
            if (!is_string($adresa) || self::fisierPublic($adresa) === null) {
                return 'adresa „' . (is_string($adresa) ? $adresa : gettype($adresa)) . '” nu e o adresă /uploads/…, /tema/… sau /assets/…';
            }
        }
        if (!is_string($valoare['alt'] ?? null)) {
            return 'alt (textul alternativ) lipsește';
        }
        return ['candidati' => array_values(array_map('strval', $candidati)), 'implicit' => (string) $valoare['implicit'],
            'alt' => (string) $valoare['alt']];
    }

    /**
     * Doar adresele cu fișier (o temă copiată pe jumătate pe server nu pune în pagină legături spre fișiere lipsă).
     *
     * @param array<mixed> $adrese
     * @return list<string>
     */
    private static function existente(array $adrese): array
    {
        $bune = [];
        foreach ($adrese as $adresa) {
            $fisier = self::fisierPublic((string) $adresa);
            if ($fisier !== null && is_file($fisier)) {
                $bune[] = (string) $adresa;
            } else {
                error_log('ANDAXI-CMS: tema: ' . $adresa . ' lipsește; nu se pune în pagină.');
            }
        }
        return $bune;
    }
}
