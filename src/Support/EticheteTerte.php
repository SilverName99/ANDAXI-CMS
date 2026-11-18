<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Scripturile terților în paginile site-ului (revizuirea de securitate din 18 noiembrie 2026, N3; docs/SECURITATE.md,
 * „Scripturile terților”): Google Tag Manager (și codul lui scris în Setări → Google), Google Analytics și Google Ads
 * (gtag și codul scris de mână), Microsoft Clarity și chatul tawk.to rulează pe aceeași origine ca adminul. Cine poate
 * publica în containerul GTM (sau în contul tawk.to, Clarity…) ar rula cod cu sesiunea oricărui admin logat care
 * deschide site-ul. Deci ele nu se pun în pagină:
 *  - cât timp sesiunea are un admin (`admin_id`, chiar dacă ceasul lui de inactivitate a expirat: aceeași condiție ca
 *    pentru cache-ul de pagini, `ResponseCache`, deci o pagină fără ele nu ajunge niciodată în cache și una din cache
 *    nu ajunge niciodată la un admin);
 *  - pe orice adresă a adminului (o previzualizare randată cu aspectul site-ului);
 *  - pe site-ul de verificare (`Verificare::eticheteTertePermise`, ca până acum).
 * Pentru un vizitator fără admin în sesiune nu se schimbă nimic: pagina e octet cu octet aceeași.
 */
final class EticheteTerte
{
    public static function permise(): bool
    {
        return Verificare::eticheteTertePermise() && !self::sesiuneAdmin() && !self::paginaAdmin();
    }

    /** Sesiunea de acum are un admin (logat sau cu ceasul de inactivitate expirat)? */
    public static function sesiuneAdmin(): bool
    {
        return isset($_SESSION) && is_array($_SESSION) && (int) ($_SESSION['admin_id'] ?? 0) > 0;
    }

    private static function paginaAdmin(): bool
    {
        $cale = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
        return GardaOrigine::caleAdmin($cale);
    }
}
