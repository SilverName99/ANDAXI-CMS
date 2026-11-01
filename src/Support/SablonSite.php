<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CSS-ul și JavaScript-ul fix al șablonului paginilor publice (views/layout.php), în fișiere versionate (WP-40, SEO
 * partea B, B6): până acum fiecare pagină avea în ea ~19 KB de CSS și ~40 KB de JavaScript, aceleași peste tot, pe care
 * browserul le descărca din nou la fiecare pagină. Sursele stau în `views/sablon-site/` (`sablon-site.css`, `chat.js`,
 * `catalog.js`, `meniu-mobil.js`, `bbd.js`, `sus.js`); variantele minificate, servite, în `public/assets/css/` și
 * `public/assets/js/sablon/` (`*.min.css`, `*.min.js`), făcute de `tools/dev/minifica-sablon.php` (testul E4 cere ca ele
 * să fie la zi). Adresele au `?v=<data fișierului>`, deci se pot ține în browser un an (`immutable`, vezi
 * ResponseCache::applyAssetCacheHeaders și public/.htaccess).
 *
 * Comutatorul: setarea `css_js_in_fisiere` (implicit pornită; înghețată la „0” pe site-ul înghețat: atunci blocurile se pun în
 * pagină exact ca înainte, octet cu octet, din aceleași surse). Scripturile rămân sincrone (fără defer), în același loc:
 * rulează în aceeași ordine ca blocurile inline.
 */
final class SablonSite
{
    public const SURSE = ['sablon-site.css', 'chat.js', 'catalog.js', 'meniu-mobil.js', 'bbd.js', 'sus.js'];

    /** @var array<string, string> */
    private static array $continut = [];

    /** @param array<string, mixed> $setari */
    public static function inFisiere(array $setari): bool
    {
        return (string) ($setari['css_js_in_fisiere'] ?? '1') === '1';
    }

    /** Sursa unui bloc, exact textul care stătea între etichetele lui în views/layout.php. */
    public static function continut(string $nume): string
    {
        if (!in_array($nume, self::SURSE, true)) {
            return '';
        }
        return self::$continut[$nume] ??= (string) @file_get_contents(self::sursa($nume));
    }

    public static function sursa(string $nume): string
    {
        return Paths::radacina() . '/views/sablon-site/' . $nume;
    }

    /** Calea publică (fără `?v=`) a variantei minificate: /assets/css/sablon-site.min.css, /assets/js/sablon/chat.min.js. */
    public static function caleMinificata(string $nume): string
    {
        $baza = pathinfo($nume, PATHINFO_FILENAME);
        return str_ends_with($nume, '.css') ? '/assets/css/' . $baza . '.min.css' : '/assets/js/sablon/' . $baza . '.min.js';
    }

    /** Adresa servită, cu versiunea fișierului. */
    public static function url(string $nume): string
    {
        $cale = self::caleMinificata($nume);
        return $cale . '?v=' . (int) (@filemtime(Paths::public(ltrim($cale, '/'))) ?: 1);
    }

    /**
     * CSS minificat fără să-i schimbe sensul: comentariile scoase, spațiile strânse, fără spații în jurul acoladelor, al
     * „;” și după „,”, fără ultimul „;” dintr-un bloc. Textele dintre ghilimele rămân neatinse.
     */
    public static function minificaCss(string $css): string
    {
        $parti = preg_split('/("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')/s', $css, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$css];
        $rez = '';
        foreach ($parti as $i => $parte) {
            if ($i % 2 === 1) {
                $rez .= $parte;
                continue;
            }
            $parte = (string) preg_replace('#/\*.*?\*/#s', '', $parte);
            $parte = (string) preg_replace('/\s+/', ' ', $parte);
            $parte = (string) preg_replace('/\s*([{};])\s*/', '$1', $parte);
            $parte = (string) preg_replace('/,\s+/', ',', $parte);
            $rez .= $parte;
        }
        $rez = (string) preg_replace('/;}/', '}', $rez);
        return trim($rez) . "\n";
    }

    /**
     * JavaScript minificat prudent: rândurile goale și cele doar cu un comentariu „//” scoase, indentarea scoasă, doar în
     * afara textelor `…` pe mai multe rânduri (acolo spațiile sunt ale HTML-ului pus în pagină), iar comentariile /* … *\/
     * de pe rânduri întregi scoase. Nimic din interiorul unui rând de cod nu se schimbă.
     */
    public static function minificaJs(string $js): string
    {
        $rez = [];
        $inTemplate = false;
        $inComentariu = false;
        foreach (preg_split('/\r\n|\r|\n/', $js) ?: [] as $linie) {
            $curata = trim($linie);
            if (!$inTemplate) {
                if ($inComentariu) {
                    if (str_contains($curata, '*/')) {
                        $inComentariu = false;
                        $curata = trim(substr($curata, strpos($curata, '*/') + 2));
                        if ($curata === '') {
                            continue;
                        }
                    } else {
                        continue;
                    }
                }
                if ($curata === '' || str_starts_with($curata, '//')) {
                    continue;
                }
                if (str_starts_with($curata, '/*')) {
                    if (!str_contains($curata, '*/')) {
                        $inComentariu = true;
                        continue;
                    }
                    if (str_ends_with($curata, '*/')) {
                        continue;
                    }
                }
                $linie = $curata;
            }
            $rez[] = $linie;
            // Un număr impar de ` (nescăpate) pe rând deschide sau închide un text pe mai multe rânduri.
            if (substr_count(str_replace('\\`', '', $linie), '`') % 2 === 1) {
                $inTemplate = !$inTemplate;
            }
        }
        return implode("\n", $rez) . "\n";
    }
}
