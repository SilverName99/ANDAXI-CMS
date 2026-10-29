<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Consimțământul pentru cookies (GDPR / ePrivacy).
 *
 * Regula legală: scripturile de urmărire (Analytics, Tag Manager, Ads,
 * Clarity) nu au voie să pornească înainte ca vizitatorul să accepte. De
 * aceea decizia se ia pe server: dacă nu există consimțământ, codurile nici
 * nu ajung în pagină. După click pe „Accept", pagina se reîncarcă și abia
 * atunci sunt trimise.
 */
final class CookieConsent
{
    /**
     * Numele cookie-ului pe un site fără altul în datele lui (WP-16, DB-08, decizia OD-27). Un site existent își
     * păstrează numele din site.json → cookie_consimtamant (pachetul lui, site-uri/<site>/site.json): altfel fiecare
     * vizitator care a ales deja ar vedea din nou bannerul.
     */
    public const IMPLICIT = 'cookie_consent';

    /** Toate cookie-urile, inclusiv cele de analiză și marketing. */
    public const TOATE = 'all';

    /** Doar cele strict necesare funcționării site-ului. */
    public const NECESARE = 'necessary';

    /** Cât timp ținem minte alegerea (6 luni, recomandarea autorităților). */
    public const DURATA = 180 * 24 * 60 * 60;

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

    /** Alegerea vizitatorului, sau '' dacă încă nu a ales. */
    public static function alegere(): string
    {
        $valoare = trim((string) ($_COOKIE[self::nume()] ?? ''));
        return in_array($valoare, [self::TOATE, self::NECESARE], true) ? $valoare : '';
    }

    /** A ales deja ceva? (dacă nu, se afișează bannerul) */
    public static function aRaspuns(): bool
    {
        return self::alegere() !== '';
    }

    /** Are voie site-ul să încarce scripturi de analiză și marketing? */
    public static function permiteUrmarire(): bool
    {
        return self::alegere() === self::TOATE;
    }

    /**
     * Eticheta pusă în cheia de cache a paginii. Fără ea, o pagină salvată
     * pentru un vizitator care a acceptat ar fi servită mai departe unuia
     * care a refuzat — cu tot cu scripturile de urmărire.
     */
    public static function cheieCache(): string
    {
        $alegere = self::alegere();
        return $alegere === '' ? 'nou' : $alegere;
    }
}
