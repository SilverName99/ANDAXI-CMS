<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Unealta internă `migrare_wordpress` (WP-23: B7, din Biovitality 14d19dc; o încarcă doar
 * tools/migrare-wordpress/import-wordpress-posts.php).
 *
 * Curăță conținutul HTML venit din WordPress ca să arate ca articolele scrise
 * în editorul nostru: fără atribute rămase de la editorul vechi, fără
 * shortcode-uri și cu imaginile aliniate cum le aliniază editorul.
 */
final class WordPressContent
{
    /**
     * Extensii de fișier pe care le aducem la noi în /uploads. Fără svg: un SVG poate avea scripturi și s-ar servi de pe
     * domeniul site-ului (revizuirea WP-23); unul din export rămâne la adresa veche, ca imaginile străine.
     */
    public const EXTENSII_IMAGINE = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

    public static function curata(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $html = self::rezolvaCaptions($html);
        // Urme de la editoarele prin care a trecut textul: `data-start`,
        // `data-end`, `data-section-id`, `data-turn-id` … peste 12.000 de
        // bucăți în export, niciuna cu vreun efect pe site.
        $html = (string) preg_replace('/\s+data-[a-z0-9-]+="[^"]*"/i', '', $html);
        $html = self::aliniazaImaginile($html);
        // Postările vechi deschid linkurile cu `target="_new"`, care nu e valid.
        $html = str_replace('target="_new"', 'target="_blank"', $html);
        $html = (string) preg_replace(
            '/(<a\b(?![^>]*\brel=)[^>]*\btarget="_blank"[^>]*)>/i',
            '$1 rel="noopener noreferrer">',
            $html
        );
        // Paragrafele goale lăsate de conversie nu au ce căuta în articol.
        $html = (string) preg_replace('#<p>(?:\s|&nbsp;|\xC2\xA0)*</p>#u', '', $html);

        return trim($html);
    }

    /**
     * Adresele imaginilor din conținut, în ordinea apariției, fără duplicate.
     *
     * @return string[]
     */
    public static function adreseImagini(string $html): array
    {
        if (preg_match_all('/<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/i', $html, $potriviri) !== false) {
            $adrese = array_map(
                static fn (string $url): string => html_entity_decode(trim($url), ENT_QUOTES, 'UTF-8'),
                $potriviri[2] ?? []
            );

            return array_values(array_unique(array_filter($adrese, static fn (string $u): bool => $u !== '')));
        }

        return [];
    }

    /**
     * Înlocuiește adresele vechi cu cele locale, în `src` și în `srcset`.
     *
     * @param array<string, string> $harta adresă veche => adresă nouă
     */
    public static function rescrieImagini(string $html, array $harta): string
    {
        if ($harta === []) {
            return $html;
        }

        foreach ($harta as $vechi => $nou) {
            if ($vechi === '' || $nou === '') {
                continue;
            }
            $html = str_replace(
                [$vechi, htmlspecialchars($vechi, ENT_QUOTES)],
                [$nou, htmlspecialchars($nou, ENT_QUOTES)],
                $html
            );
        }

        // `srcset` și `sizes` trimit tot la fișierele vechi, în variante pe care
        // nu le-am adus. Scoase, browserul folosește `src`, care e local.
        $html = (string) preg_replace('/\s+srcset="[^"]*"/i', '', $html);
        $html = (string) preg_replace('/\s+sizes="[^"]*"/i', '', $html);

        return $html;
    }

    /** Adresa arată a fișier imagine pe care merită să-l aducem la noi? */
    public static function esteImagine(string $url): bool
    {
        $cale = (string) parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo($cale, PATHINFO_EXTENSION));

        return in_array($ext, self::EXTENSII_IMAGINE, true);
    }

    /** Timpul de citire, la ~200 de cuvinte pe minut. */
    public static function minuteCitire(string $html): int
    {
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
        if ($text === '') {
            return 1;
        }
        $cuvinte = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return max(1, (int) ceil(count(is_array($cuvinte) ? $cuvinte : []) / 200));
    }

    /** Rezumatul scurt: din excerpt dacă există, altfel din primul paragraf. */
    public static function rezumat(string $excerpt, string $html, int $limita = 200): string
    {
        $text = trim(html_entity_decode(strip_tags($excerpt), ENT_QUOTES, 'UTF-8'));
        if ($text === '') {
            $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
        }
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) <= $limita) {
            return $text;
        }

        $taiat = mb_substr($text, 0, $limita);
        $ultimSpatiu = mb_strrpos($taiat, ' ');
        if ($ultimSpatiu !== false && $ultimSpatiu > $limita / 2) {
            $taiat = mb_substr($taiat, 0, $ultimSpatiu);
        }

        return rtrim($taiat, " ,.;:-") . '…';
    }

    /**
     * `[caption ...]<img …/> Text[/caption]` devine `<figure>` cu `<figcaption>`.
     * Fără asta, shortcode-ul rămâne text pe pagină.
     */
    private static function rezolvaCaptions(string $html): string
    {
        return (string) preg_replace_callback(
            '/\[caption\b[^\]]*\](.*?)\[\/caption\]/is',
            static function (array $m): string {
                $interior = trim((string) ($m[1] ?? ''));
                if ($interior === '') {
                    return '';
                }
                if (preg_match('/<img\b[^>]*>/i', $interior, $imgMatch) !== 1) {
                    return $interior;
                }
                $img = $imgMatch[0];
                $legenda = trim(str_replace($img, '', $interior));
                $legenda = trim(html_entity_decode($legenda, ENT_QUOTES, 'UTF-8'));
                $legenda = trim((string) preg_replace('/^\x{00A0}+|\x{00A0}+$/u', '', $legenda));
                if ($legenda === '') {
                    return $img;
                }

                return '<figure>' . $img . '<figcaption>' . $legenda . '</figcaption></figure>';
            },
            $html
        ) ?: $html;
    }

    /**
     * Clasele `alignleft` / `aligncenter` / `alignright` din WordPress nu există
     * în CSS-ul nostru. Le traducem în stilul pe care îl scrie chiar editorul
     * de postări, ca alinierea să fie aceeași și după ce cineva o schimbă din admin.
     */
    private static function aliniazaImaginile(string $html): string
    {
        return (string) preg_replace_callback(
            '/<img\b[^>]*>/i',
            static function (array $m): string {
                $img = $m[0];
                if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $img, $cls) !== 1) {
                    return $img;
                }
                $clase = ' ' . strtolower($cls[2]) . ' ';
                $stil = match (true) {
                    str_contains($clase, ' alignleft ') => 'display:block;margin-left:0;margin-right:auto;',
                    str_contains($clase, ' alignright ') => 'display:block;margin-left:auto;margin-right:0;',
                    str_contains($clase, ' aligncenter ') => 'display:block;margin-left:auto;margin-right:auto;',
                    default => '',
                };

                // Clasele WordPress (`wp-image-123`, `size-medium`) n-au CSS la
                // noi; le scoatem ca să nu rămână gunoi în conținut.
                $curatate = trim((string) preg_replace('/\b(?:align\w+|size-\w+|wp-image-\d+)\b/i', '', $cls[2]));
                $curatate = trim((string) preg_replace('/\s+/', ' ', $curatate));
                $img = $curatate !== ''
                    ? str_replace($cls[0], 'class=' . $cls[1] . $curatate . $cls[1], $img)
                    : str_replace($cls[0], '', $img);

                if ($stil === '') {
                    return $img;
                }
                if (preg_match('/\bstyle\s*=\s*(["\'])(.*?)\1/i', $img, $st) === 1) {
                    return str_replace($st[0], 'style=' . $st[1] . rtrim($st[2], '; ') . ';' . $stil . $st[1], $img);
                }

                return (string) preg_replace('/^<img\b/i', '<img style="' . $stil . '"', $img, 1);
            },
            $html
        ) ?: $html;
    }
}
