<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Logo-ul din antet ca WebP mic, cu PNG-ul original ca rezervă.
 *
 * Antetul e HTML salvat în baza de date. Un logo mare (la Bioscem
 * /uploads/gallery/bioscem-logo-2.png, 977×240, circa 154 KB, afișat la 52 px
 * înălțime) se poate da browserului ca două variante WebP făcute o singură dată
 * din acel PNG: la afișare, <img>-ul lui e învelit într-un <picture> cu ele (una
 * pentru ecrane obișnuite, una pentru ecrane dense, câțiva KB fiecare).
 * Browserele fără WebP și roboții care nu citesc <picture> primesc PNG-ul de până
 * acum, care rămâne neatins pe server. Nimic nu se generează la cerere.
 *
 * Ce logo și ce variante sunt ale fiecărui site (seo.json → logo_antet, vezi
 * docs/SEO.md):
 *
 *   "logo_antet": {"png": "/uploads/gallery/logo.png", "dimensiuni": [977, 240],
 *                  "webp_1x": "/assets/img/logo-212.webp", "webp_2x": "/assets/img/logo-423.webp"}
 *
 * Variantele sunt fișiere ale pachetului site-ului (`public/` din pachet, copiat
 * peste `public/` la adoptare; vezi site-uri/README.md). Fără configurare, fără
 * unul dintre fișiere, sau dacă PNG-ul din galerie a fost înlocuit cu alt logo
 * (alte dimensiuni decât cele din care s-au făcut variantele), antetul rămâne
 * exact cum e.
 *
 * Din Bioscem `a14e803` (livrarea 2 SEO, A8), cu căile și dimensiunile mutate din
 * constante în datele site-ului.
 */
final class LogoAntet
{
    public static function webp(string $html, ?string $radacinaPublic = null): string
    {
        $config = self::configurare();
        if ($config === null || $html === '' || !str_contains($html, $config['png']) || stripos($html, '<picture') !== false) {
            return $html;
        }
        $radacinaPublic ??= dirname(__DIR__, 2) . '/public';
        $png = $radacinaPublic . $config['png'];
        if (!is_file($radacinaPublic . $config['webp_1x']) || !is_file($radacinaPublic . $config['webp_2x']) || !is_file($png)) {
            return $html;
        }
        $dimensiuni = @getimagesize($png);
        if (!is_array($dimensiuni) || [(int) $dimensiuni[0], (int) $dimensiuni[1]] !== $config['dimensiuni']) {
            return $html;
        }
        $versiune = (string) @filemtime($radacinaPublic . $config['webp_2x']);
        $sursa = '<source type="image/webp" srcset="'
            . $config['webp_1x'] . '?v=' . $versiune . ' 1x, '
            . $config['webp_2x'] . '?v=' . $versiune . ' 2x">';
        return (string) preg_replace_callback(
            '#<img\b[^>]*\bsrc\s*=\s*(["\'])' . preg_quote($config['png'], '#') . '\1[^>]*>#i',
            // display:contents: <picture> nu face o cutie proprie, deci <img>-ul se
            // așază exact ca înainte (aceleași reguli CSS din antet îl prind).
            static fn (array $m): string => '<picture style="display:contents">' . $sursa . $m[0] . '</picture>',
            $html,
            1
        );
    }

    /**
     * Configurarea din seo.json, verificată: căi din site („/…”, fără „..”, fără
     * ghilimele), două dimensiuni pozitive. Null dacă lipsește sau e greșită.
     *
     * @return array{png: string, dimensiuni: array{0: int, 1: int}, webp_1x: string, webp_2x: string}|null
     */
    private static function configurare(): ?array
    {
        $config = DateSite::seo('logo_antet', null);
        if (!is_array($config)) {
            return null;
        }
        $cai = [];
        foreach (['png', 'webp_1x', 'webp_2x'] as $cheie) {
            $cale = trim((string) ($config[$cheie] ?? ''));
            if ($cale === '' || $cale[0] !== '/' || str_starts_with($cale, '//') || str_contains($cale, '..')
                || preg_match('/["\'<>\s\\\\?#]/', $cale) === 1) {
                return null;
            }
            $cai[$cheie] = $cale;
        }
        $dimensiuni = $config['dimensiuni'] ?? null;
        if (!is_array($dimensiuni) || count($dimensiuni) !== 2
            || !is_int($dimensiuni[0] ?? null) || !is_int($dimensiuni[1] ?? null)
            || $dimensiuni[0] <= 0 || $dimensiuni[1] <= 0) {
            return null;
        }
        return $cai + ['dimensiuni' => [$dimensiuni[0], $dimensiuni[1]]];
    }
}
