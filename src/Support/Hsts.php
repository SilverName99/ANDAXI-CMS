<?php

declare(strict_types=1);

namespace App\Support;

/**
 * HSTS (WP-40, SEO partea B, B6; revizuirea WP-39/40): browserul ține minte că site-ul se cere doar pe https. Setarea `hsts`:
 *  - `oprit`: nu se trimite nimic (ca înainte; înghețată așa pe site-urile existente: oprit la trecere, decizia
 *    proprietarului din 5 nov 2026, apoi urcat în trepte cu scripts/comutatoare-seo.php --hsts=…);
 *  - un număr de secunde: `Strict-Transport-Security: max-age=<N>`, fără subdomenii și fără preload. Se urcă în trepte,
 *    câte una după ce site-ul a mers fără probleme pe https: 300 (implicit în nucleu) → 86400 → 31536000
 *    (`php scripts/comutatoare-seo.php --hsts=86400`);
 *  - `0`: oprirea de urgență, `max-age=0`, care șterge din browsere ce au ținut minte (nu „nimic”: un browser care a primit
 *    deja HSTS l-ar păstra până expiră). `HSTS=0` în `.env` face același lucru, peste setare.
 * Doar pe https (pe http browserul ignoră antetul); dezvoltarea și un site fără certificat nu-l primesc.
 */
final class Hsts
{
    public const TREPTE = [300, 86400, 31536000];
    public const MAXIM = 63072000;

    /** Valoarea `max-age` pentru setări, sau null = nimic de trimis. */
    public static function maxAge(array $setari): ?int
    {
        $env = strtolower(trim((string) Env::get('HSTS', '')));
        if (in_array($env, ['0', 'false', 'off', 'nu'], true)) {
            return 0;
        }
        $valoare = strtolower(trim((string) ($setari['hsts'] ?? '300')));
        if ($valoare === 'oprit' || $valoare === '' || preg_match('/^\d{1,9}$/', $valoare) !== 1) {
            return null;
        }
        return min((int) $valoare, self::MAXIM);
    }

    public static function peHttps(): bool
    {
        return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /** Antetul cererii de acum (public/index.php). */
    public static function trimite(array $setari): void
    {
        $maxAge = self::maxAge($setari);
        if ($maxAge !== null && self::peHttps() && !headers_sent()) {
            header('Strict-Transport-Security: max-age=' . $maxAge);
        }
    }
}
