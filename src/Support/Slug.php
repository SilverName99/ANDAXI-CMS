<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Slug-urile din adresele de marcă, etichetă și categorie, într-un singur loc,
 * ca paginile, linkurile și sitemap-ul să ajungă la aceeași formă.
 *
 * `simplu()` și `categorie()` dau adresele de azi ale magazinului: nu se schimbă (SEO).
 * `make()`, `unique()` și `transliterariUnuLaUnu()` vin din Biovitality (WP-22: X12, 14d19dc, f5295b5), pentru blog:
 * slug-uri care nu pierd litere pe diacritice. `slugify()` din controllere taie orice nu e ASCII, așa că „Sănătatea
 * sistemului respirator” ajungea „sntatea-sistemului-respirator”; aici diacriticele se transliterează întâi, apoi se taie
 * restul.
 *
 * `unicode()` e slug-ul site-urilor care păstrează literele oricărei limbi (WpCompat::slugUnicode: azi cele cu
 * wp-compat.json, mutate de pe WordPress, unde slug-urile românești și grecești sunt adrese publice).
 */
final class Slug
{
    /** @var array<string, string> */
    private const TRANSLITERARI = [
        'ă' => 'a', 'â' => 'a', 'á' => 'a', 'à' => 'a', 'ä' => 'a', 'å' => 'a', 'ã' => 'a',
        'î' => 'i', 'í' => 'i', 'ì' => 'i', 'ï' => 'i',
        'ș' => 's', 'ş' => 's', 'š' => 's',
        'ț' => 't', 'ţ' => 't',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c',
        'ñ' => 'n', 'ß' => 'ss', 'ý' => 'y', 'ž' => 'z', 'ź' => 'z',
        'æ' => 'ae', 'œ' => 'oe', 'đ' => 'd', 'ł' => 'l',
        // Ghilimelele și apostrofurile cad oricum la pasul următor, care taie
        // tot ce nu e literă sau cifră.
    ];

    /**
     * Transliterările care schimbă un caracter în exact un caracter.
     *
     * Folositoare acolo unde textul trebuie normalizat fără să i se schimbe
     * lungimea — de exemplu la evidențierea rezultatelor căutării, unde
     * pozițiile din textul normalizat trebuie să corespundă cu cele din
     * textul original.
     *
     * @return array<string, string>
     */
    public static function transliterariUnuLaUnu(): array
    {
        return array_filter(
            self::TRANSLITERARI,
            static fn (string $inlocuitor): bool => mb_strlen($inlocuitor) === 1
        );
    }

    public static function make(string $value, string $fallback = ''): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, self::TRANSLITERARI);
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim($value, '-');

        return $value !== '' ? $value : $fallback;
    }

    /**
     * Slug liber, verificat cu un callback care spune dacă e deja luat.
     * La coliziune adaugă -2, -3 … până găsește unul liber.
     *
     * $lungimeMaxima: cât încape în coloana slug-ului, cu tot cu sufix (implicit 200: VARCHAR(255) și VARCHAR(190) ale
     * articolelor și autorilor; 170 pentru blog_tags.slug și blog_categories.slug, revizuirea WP-23).
     *
     * @param callable(string): bool $esteLuat
     */
    public static function unique(string $value, callable $esteLuat, string $fallback = 'articol', int $lungimeMaxima = 200): string
    {
        $baza = self::make($value, $fallback);
        // Lăsăm loc și pentru sufix („-2” … „-500” sau „-” + 8 hex).
        $loc = max(10, $lungimeMaxima - 10);
        if (mb_strlen($baza) > $loc) {
            $baza = rtrim(mb_substr($baza, 0, $loc), '-');
        }
        if ($baza === '') {
            $baza = $fallback;
        }

        $candidat = $baza;
        $index = 2;
        while ($esteLuat($candidat)) {
            $candidat = $baza . '-' . $index;
            $index++;
            if ($index > 500) {
                return $baza . '-' . substr(md5($value . microtime(true)), 0, 8);
            }
        }

        return $candidat;
    }

    /**
     * Slug-ul care păstrează literele oricărei limbi: litere (`\p{L}` cu semnele lor, `\p{M}`), cifre și cratimă; literele mici;
     * spațiile, „_” și cratimele la rând devin o cratimă, fără cratimă la capete; restul (punctuația, simbolurile) pleacă.
     * Forma NFC când există `Normalizer` (ș scris ca s + virgulă devine un singur caracter, ca în adresă).
     * „Camere și Prețuri!” → „camere-și-prețuri”; „Δωμάτια 2026” → „δωμάτια-2026”.
     */
    public static function unicode(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $nfc = \Normalizer::normalize($value, \Normalizer::FORM_C);
            if (is_string($nfc)) {
                $value = $nfc;
            }
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = (string) preg_replace('/[\s_\-]+/u', '-', $value);
        $value = (string) preg_replace('/[^\p{L}\p{M}\p{N}\-]/u', '', $value);
        $value = (string) preg_replace('/-{2,}/', '-', $value);

        return trim($value, '-');
    }

    /** Marca și eticheta: „Vitamina D” → „vitamina-d” (ca pe site, de la început). */
    public static function simplu(string $valoare): string
    {
        $valoare = mb_strtolower(trim($valoare));
        $valoare = strtr($valoare, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);
        $valoare = (string) preg_replace('/[^a-z0-9]+/', '-', $valoare);
        return trim($valoare, '-');
    }

    /** Categoria, când nu are slug salvat: „Tiroidă și Menopauză” → „tiroida-si-menopauza”. */
    public static function categorie(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') {
            $value = $ascii;
        }
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);

        return trim($value, '-');
    }
}
