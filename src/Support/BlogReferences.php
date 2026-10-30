<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Bibliografia unui articol: o secțiune proprie, sub text, cu ancore (WP-22: B2, din Biovitality 14d19dc, 9c549c7).
 *
 * În text citările se scriu „[1]", „[2]". Aici le transformăm în linkuri către
 * referința potrivită și punem pe fiecare referință un link de întoarcere la
 * locul din care s-a plecat. Postările aduse din site-ul vechi au deja mii de
 * citări care duceau la ancora `#surse`; secțiunea păstrează acel id, ca
 * linkurile vechi (și cele din Google) să nu se rupă.
 */
final class BlogReferences
{
    /** Ancora pe care o foloseau articolele din site-ul vechi. */
    public const ANCORA_SECTIUNE = 'surse';

    /**
     * Numerele referințelor găsite în HTML-ul bibliografiei, în ordine.
     *
     * @return int[]
     */
    public static function numere(string $referencesHtml): array
    {
        if (trim($referencesHtml) === '') {
            return [];
        }
        $numere = [];
        foreach (self::itemi($referencesHtml) as $item) {
            $numere[] = $item['numar'];
        }

        return $numere;
    }

    /**
     * Secțiunea de referințe, gata de pus în pagină.
     *
     * @param string $titlu titlul secțiunii; gol înseamnă fără titlu
     */
    public static function render(string $referencesHtml, string $titlu = 'Referințe'): string
    {
        $itemi = self::itemi($referencesHtml);
        if ($itemi === []) {
            $curat = trim($referencesHtml);

            // Bibliografie scrisă liber, fără „[1]" la început de rând: o punem
            // ca atare, dar tot într-o secțiune cu ancoră.
            return $curat === '' ? '' : '<section class="blog-references" id="' . self::ANCORA_SECTIUNE . '">'
                . ($titlu !== '' ? '<h2 class="blog-references__title">' . htmlspecialchars($titlu, ENT_QUOTES) . '</h2>' : '')
                . '<div class="blog-references__free">' . $curat . '</div>'
                . '</section>';
        }

        $randuri = '';
        foreach ($itemi as $item) {
            $numar = $item['numar'];
            $randuri .= '<li class="blog-references__item" id="ref-' . $numar . '" value="' . $numar . '">'
                . '<span class="blog-references__body">' . $item['html'] . '</span>'
                . '<a class="blog-references__back" href="#cit-' . $numar . '"'
                . ' aria-label="Înapoi la locul din text unde apare referința ' . $numar . '" title="Înapoi în text">↩</a>'
                . '</li>';
        }

        return '<section class="blog-references" id="' . self::ANCORA_SECTIUNE . '">'
            . ($titlu !== '' ? '<h2 class="blog-references__title">' . htmlspecialchars($titlu, ENT_QUOTES) . '</h2>' : '')
            . '<ol class="blog-references__list">' . $randuri . '</ol>'
            . '</section>';
    }

    /**
     * Leagă citările din text de referințele de jos.
     *
     * Prinde și „[1]" scris ca text, și forma veche `[<a href="#surse">1</a>]`.
     * Prima apariție a fiecărui număr primește un id, ca săgeata de întoarcere
     * din bibliografie să aibă unde ateriza.
     *
     * @param int[] $numereDisponibile numai citările cu referință primesc link
     */
    public static function linkCitations(string $contentHtml, array $numereDisponibile): string
    {
        if (trim($contentHtml) === '' || $numereDisponibile === []) {
            return $contentHtml;
        }
        $disponibile = array_flip(array_map('intval', $numereDisponibile));
        $vazute = [];

        $eticheta = static function (int $numar) use (&$vazute): string {
            $id = isset($vazute[$numar]) ? '' : ' id="cit-' . $numar . '"';
            $vazute[$numar] = true;

            return '<a class="blog-cite" href="#ref-' . $numar . '"' . $id . '>[' . $numar . ']</a>';
        };

        // Forma veche: paranteza stă în afara linkului, iar linkul duce la
        // secțiune, nu la referința anume.
        $contentHtml = (string) preg_replace_callback(
            '/\[\s*<a\b[^>]*href="#surse[a-z]*"[^>]*>\s*(\d{1,3})\s*<\/a>\s*\]/i',
            static function (array $m) use ($disponibile, $eticheta): string {
                $numar = (int) $m[1];

                return isset($disponibile[$numar]) ? $eticheta($numar) : $m[0];
            },
            $contentHtml
        ) ?: $contentHtml;

        // „[1]" scris ca text simplu. Umblăm doar între taguri, ca să nu stricăm
        // atribute care conțin paranteze drepte.
        $bucati = preg_split('/(<[^>]*>)/', $contentHtml, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($bucati)) {
            return $contentHtml;
        }
        $inAncora = 0;
        foreach ($bucati as $index => $bucata) {
            if ($bucata === '' || str_starts_with($bucata, '<')) {
                if (preg_match('/^<a\b/i', $bucata) === 1) {
                    $inAncora++;
                } elseif (preg_match('/^<\/a\s*>/i', $bucata) === 1) {
                    $inAncora = max(0, $inAncora - 1);
                }
                continue;
            }
            // Un „[1]" care e deja înăuntrul unui link rămâne cum e.
            if ($inAncora > 0) {
                continue;
            }
            $bucati[$index] = (string) preg_replace_callback(
                '/\[(\d{1,3})\]/',
                static function (array $m) use ($disponibile, $eticheta): string {
                    $numar = (int) $m[1];

                    return isset($disponibile[$numar]) ? $eticheta($numar) : $m[0];
                },
                $bucata
            );
        }

        return implode('', $bucati);
    }

    /**
     * Sparge bibliografia în referințe numerotate.
     *
     * @return array<int, array{numar: int, html: string}>
     */
    private static function itemi(string $referencesHtml): array
    {
        $html = trim($referencesHtml);
        if ($html === '') {
            return [];
        }

        // Forma obișnuită: fiecare referință e un paragraf care începe cu „[n]".
        // (Și lista `<ol><li>` scrisă de mână intră tot pe aici.)
        if (preg_match_all('#<(p|li|div)\b[^>]*>(.*?)</\1>#is', $html, $potriviri, PREG_SET_ORDER) === false) {
            return [];
        }

        $itemi = [];
        foreach ($potriviri as $potrivire) {
            $interior = trim((string) ($potrivire[2] ?? ''));
            if ($interior === '') {
                continue;
            }
            if (preg_match('/^\[\s*(\d{1,3})\s*\]\s*(.*)$/s', $interior, $m) !== 1) {
                continue;
            }
            $numar = (int) $m[1];
            $rest = trim((string) $m[2]);
            if ($numar <= 0 || $rest === '') {
                continue;
            }
            $itemi[] = ['numar' => $numar, 'html' => $rest];
        }

        return $itemi;
    }
}
