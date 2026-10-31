<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Aspectul adminului refăcut („B — Compact”, faza D, WP-30): culoarea de accent a fiecărui site și ce se scrie în
 * blocul utilizatorului din meniu. Nu atinge nimic din paginile publice.
 *
 * Culoarea de accent stă în setarea `admin_culoare_accent` (Setări magazin / Setări site → „Culoarea adminului”), un
 * cod hex de 6 cifre; goală sau greșită = accentul neutru al machetelor (#2563eb). Setarea nu are valoare implicită în
 * Settings::DEFAULTS și nicio rezervă scrisă în cod: e doar a adminului (nu schimbă nimic pe site), deci nu are ce
 * îngheța la adoptarea unui site (docs/MIGRARI.md, „Înghețarea setărilor”).
 */
final class AdminAspect
{
    public const CHEIE_ACCENT = 'admin_culoare_accent';
    public const ACCENT_IMPLICIT = '#2563eb';

    /** Accentul site-ului (#rrggbb, litere mici). */
    public static function accent(array $setari): string
    {
        $valoare = strtolower(trim((string) ($setari[self::CHEIE_ACCENT] ?? '')));
        return self::esteCuloare($valoare) ? $valoare : self::ACCENT_IMPLICIT;
    }

    public static function esteCuloare(string $valoare): bool
    {
        return preg_match('/^#[0-9a-f]{6}$/i', $valoare) === 1;
    }

    /**
     * Culoarea textului pe accent: alb sau aproape negru, cea cu contrastul mai mare (WCAG), ca un accent deschis (galben,
     * verde deschis) să rămână lizibil pe butoane și pe intrarea activă din meniu.
     */
    public static function textPeAccent(string $accent): string
    {
        $l = self::luminanta($accent);
        $contrastAlb = 1.05 / ($l + 0.05);
        $contrastNegru = ($l + 0.05) / (self::luminanta('#111827') + 0.05);
        return $contrastAlb >= $contrastNegru ? '#ffffff' : '#111827';
    }

    /** Inițialele din blocul utilizatorului: din partea dinaintea lui @ a emailului („ana.pop@…” → „AP”). */
    public static function initiale(string $email): string
    {
        $nume = (string) strstr($email, '@', true) ?: $email;
        $parti = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $nume) ?: [], static fn (string $p): bool => $p !== ''));
        if ($parti === []) {
            return 'A';
        }
        $initiale = mb_substr($parti[0], 0, 1) . (isset($parti[1]) ? mb_substr($parti[1], 0, 1) : '');
        return mb_strtoupper($initiale);
    }

    private static function luminanta(string $hex): float
    {
        $canale = [];
        foreach ([1, 3, 5] as $poz) {
            $c = hexdec(substr($hex, $poz, 2)) / 255;
            $canale[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $canale[0] + 0.7152 * $canale[1] + 0.0722 * $canale[2];
    }
}
