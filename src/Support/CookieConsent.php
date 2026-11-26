<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Consimțământul pentru cookie-uri (GDPR / ePrivacy), pe categorii (docs/PLUGINURI.md, „Bannerul de cookie-uri”).
 *
 * Regula: un script sau un cookie care nu e strict necesar nu ajunge în pagină și nu se scrie înainte ca vizitatorul să
 * accepte categoria lui. Decizia se ia pe server, la fiecare cerere, din cookie-ul acordului: fără acord, codurile nici nu
 * sunt trimise. Categoriile (ServiciiCookie le arată doar pe cele cu ceva activ pe site):
 *  - `necesare`: mereu, nu se pot refuza (sesiunea, cookie-ul acordului însuși);
 *  - `statistici`: Google Analytics, Google Tag Manager, Microsoft Clarity, sursa comenzii, campania de newsletter;
 *  - `marketing`: Google Ads (eticheta și id-ul clicului pe reclamă);
 *  - `extern`: conținutul altor site-uri (playerul YouTube/Vimeo, chatul tawk.to).
 *
 * Cookie-ul (numele din site.json → cookie_consimtamant, ca până acum) are valoarea `2.<oferite>.<acceptate>`: versiunea
 * formatului, categoriile arătate vizitatorului când a ales și cele acceptate (literele din LITERE; „-” = niciuna), de
 * exemplu `2.sme.s`. Valorile de dinainte (`all`, `necessary`) nu mai sunt un răspuns: fiecare vizitator vede bannerul nou
 * o dată. Când pe site apare o categorie pe care vizitatorul n-a văzut-o (nu e în „oferite”), bannerul se arată din nou;
 * până la noua alegere rămân în vigoare doar categoriile acceptate atunci.
 */
final class CookieConsent
{
    /**
     * Numele cookie-ului pe un site fără altul în datele lui (WP-16, DB-08, decizia OD-27). Un site existent își
     * păstrează numele din site.json → cookie_consimtamant (pachetul lui, site-uri/<site>/site.json).
     */
    public const IMPLICIT = 'cookie_consent';

    /** Versiunea formatului valorii; o valoare a altei versiuni (sau cele vechi, all / necessary) = fără răspuns. */
    public const VERSIUNE = '2';

    /** Categoriile care se pot accepta sau refuza, cu litera lor din valoarea cookie-ului (ordinea e cea din banner). */
    public const LITERE = ['statistici' => 's', 'marketing' => 'm', 'extern' => 'e'];

    /** Categoria care nu se poate refuza. */
    public const NECESARE = 'necesare';

    /** Cât timp ținem minte alegerea (6 luni, recomandarea autorităților). */
    public const DURATA = 180 * 24 * 60 * 60;

    /** @var array{oferite: list<string>, acceptate: list<string>}|false|null alegerea citită (false = fără una validă) */
    private static array|false|null $citita = null;

    private static ?string $valoareCitita = null;

    /**
     * Numele cookie-ului acordului: site.json → cookie_consimtamant, altfel IMPLICIT. Un nume care nu poate fi al unui
     * cookie (gol, spații, „;”, „=”, alt tip) se ignoră, cu un rând în jurnalul PHP: un site.json greșit nu are voie
     * să strice bannerul.
     */
    public static function nume(): string
    {
        $nume = DateSite::site('cookie_consimtamant', self::IMPLICIT);
        if (is_string($nume) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $nume) === 1) {
            return $nume;
        }
        static $anuntat = false;
        if (!$anuntat) {
            $anuntat = true;
            error_log('ANDAXI-CMS: site.json → cookie_consimtamant nu e un nume de cookie valid; folosesc „' . self::IMPLICIT . '”.');
        }
        return self::IMPLICIT;
    }

    /**
     * Citește o valoare a cookie-ului: categoriile oferite și cele acceptate, sau null (alt format, o valoare veche, o
     * categorie acceptată care n-a fost oferită, o literă repetată).
     *
     * @return array{oferite: list<string>, acceptate: list<string>}|null
     */
    public static function citeste(string $valoare): ?array
    {
        if (preg_match('/^' . self::VERSIUNE . '\.([a-z]{1,8})\.([a-z]{1,8}|-)$/D', trim($valoare), $m) !== 1) {
            return null;
        }
        $dupaLitera = array_flip(self::LITERE);
        $oferite = [];
        foreach (str_split($m[1]) as $litera) {
            if (!isset($dupaLitera[$litera]) || in_array($dupaLitera[$litera], $oferite, true)) {
                return null;
            }
            $oferite[] = $dupaLitera[$litera];
        }
        $acceptate = [];
        foreach ($m[2] === '-' ? [] : str_split($m[2]) as $litera) {
            $categorie = $dupaLitera[$litera] ?? null;
            if ($categorie === null || !in_array($categorie, $oferite, true) || in_array($categorie, $acceptate, true)) {
                return null;
            }
            $acceptate[] = $categorie;
        }
        return ['oferite' => self::inOrdine($oferite), 'acceptate' => self::inOrdine($acceptate)];
    }

    /**
     * Valoarea cookie-ului pentru o alegere (același format ca bannerul; uneltele de test o folosesc pentru „a acceptat”).
     *
     * @param list<string> $acceptate
     * @param list<string>|null $oferite null = toate categoriile
     */
    public static function valoare(array $acceptate, ?array $oferite = null): string
    {
        $oferite = self::inOrdine($oferite ?? array_keys(self::LITERE));
        $acceptate = array_values(array_intersect(self::inOrdine($acceptate), $oferite));
        $litere = static fn (array $categorii): string => implode('', array_map(static fn (string $c): string => self::LITERE[$c], $categorii));
        return self::VERSIUNE . '.' . $litere($oferite) . '.' . ($acceptate === [] ? '-' : $litere($acceptate));
    }

    /** Valoarea „Accept toate” (toate categoriile oferite și acceptate). */
    public static function valoareToate(): string
    {
        return self::valoare(array_keys(self::LITERE));
    }

    /** @return array{oferite: list<string>, acceptate: list<string>}|null alegerea din cookie-ul cererii */
    public static function alegere(): ?array
    {
        $valoare = (string) ($_COOKIE[self::nume()] ?? '');
        if (self::$citita === null || self::$valoareCitita !== $valoare) {
            self::$valoareCitita = $valoare;
            self::$citita = self::citeste($valoare) ?? false;
        }
        return self::$citita === false ? null : self::$citita;
    }

    /**
     * Are voie site-ul să încarce ce ține de o categorie? Necesarele mereu; celelalte doar acceptate explicit, cu o valoare
     * a formatului de acum. O categorie apărută după alegere nu e acceptată (bannerul o cere din nou).
     */
    public static function permite(string $categorie): bool
    {
        if ($categorie === self::NECESARE) {
            return true;
        }
        $alegere = self::alegere();
        return $alegere !== null && in_array($categorie, $alegere['acceptate'], true);
    }

    /**
     * A răspuns vizitatorul pentru toate categoriile active acum pe site? Dacă nu, se arată bannerul.
     *
     * @param list<string> $active categoriile cu ceva activ (ServiciiCookie::categoriiActive)
     */
    public static function aRaspuns(array $active): bool
    {
        $alegere = self::alegere();
        return $alegere !== null && array_diff(array_intersect($active, array_keys(self::LITERE)), $alegere['oferite']) === [];
    }

    /**
     * Eticheta pusă în cheia de cache a paginii: o pagină salvată pentru un vizitator care a acceptat o categorie nu se dă
     * niciodată unuia care n-a acceptat-o (scripturile ei sunt în pagină). `nou` = fără o alegere validă (inclusiv valorile
     * vechi); altfel valoarea normalizată (oferite și acceptate), deci și bannerul cerut din nou are pagina lui.
     */
    public static function cheieCache(): string
    {
        $alegere = self::alegere();
        return $alegere === null ? 'nou' : self::valoare($alegere['acceptate'], $alegere['oferite']);
    }

    /**
     * @param list<string> $categorii
     * @return list<string> categoriile cunoscute, în ordinea din LITERE
     */
    private static function inOrdine(array $categorii): array
    {
        return array_values(array_filter(array_keys(self::LITERE), static fn (string $c): bool => in_array($c, $categorii, true)));
    }
}
