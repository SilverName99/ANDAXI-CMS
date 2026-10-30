<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Cuprinsul unui articol, făcut din titlurile din text (WP-22: B2, din Biovitality 9c549c7).
 *
 * Trece o dată prin conținut: pune câte un id pe fiecare H2/H3 (dacă n-are
 * deja unul) și strânge lista. Titlurile mari rămân îngroșate în cuprins, cele
 * mici stau dedesubt, mai discret — ca articolul lung să se parcurgă din ochi.
 */
final class BlogToc
{
    /**
     * @return array{html: string, toc: string, count: int}
     *               html — conținutul cu ancore pe titluri
     *               toc  — cuprinsul, gol dacă n-are rost să apară
     */
    public static function build(string $contentHtml, string $titlu = 'Cuprins', int $minim = 2): array
    {
        if (trim($contentHtml) === '') {
            return ['html' => $contentHtml, 'toc' => '', 'count' => 0];
        }

        $intrari = [];
        $folosite = [];

        $htmlNou = (string) preg_replace_callback(
            '#<h([23])\b([^>]*)>(.*?)</h\1>#is',
            static function (array $m) use (&$intrari, &$folosite): string {
                $nivel = (int) $m[1];
                $atribute = (string) $m[2];
                $interior = (string) $m[3];

                $text = trim(html_entity_decode(strip_tags($interior), ENT_QUOTES, 'UTF-8'));
                $text = trim((string) preg_replace('/\s+/u', ' ', $text));
                if ($text === '') {
                    return $m[0];
                }

                // Un id pus de mână în editor rămâne al lui: poate are deja
                // linkuri către el din altă parte.
                if (preg_match('/\bid\s*=\s*(["\'])(.*?)\1/i', $atribute, $idExistent) === 1
                    && trim($idExistent[2]) !== '') {
                    $id = trim($idExistent[2]);
                    $folosite[$id] = true;
                    $intrari[] = ['nivel' => $nivel, 'id' => $id, 'text' => $text];

                    return $m[0];
                }

                $id = Slug::unique(
                    $text,
                    static fn (string $candidat): bool => isset($folosite[$candidat]),
                    'sectiune'
                );
                $folosite[$id] = true;
                $intrari[] = ['nivel' => $nivel, 'id' => $id, 'text' => $text];

                return '<h' . $nivel . ' id="' . htmlspecialchars($id, ENT_QUOTES) . '"' . $atribute . '>'
                    . $interior . '</h' . $nivel . '>';
            },
            $contentHtml
        ) ?: $contentHtml;

        // Un cuprins de un singur rând nu ajută pe nimeni.
        if (count($intrari) < max(1, $minim)) {
            return ['html' => $htmlNou, 'toc' => '', 'count' => count($intrari)];
        }

        $randuri = '';
        foreach ($intrari as $intrare) {
            $randuri .= '<li class="blog-toc__item blog-toc__item--h' . $intrare['nivel'] . '">'
                . '<a class="blog-toc__link" href="#' . htmlspecialchars($intrare['id'], ENT_QUOTES) . '">'
                . htmlspecialchars($intrare['text'], ENT_QUOTES)
                . '</a></li>';
        }

        $toc = '<nav class="blog-toc" aria-label="' . htmlspecialchars($titlu, ENT_QUOTES) . '">'
            . '<details class="blog-toc__box" open>'
            . '<summary class="blog-toc__summary">' . htmlspecialchars($titlu, ENT_QUOTES) . '</summary>'
            . '<ol class="blog-toc__list">' . $randuri . '</ol>'
            . '</details></nav>';

        return ['html' => $htmlNou, 'toc' => $toc, 'count' => count($intrari)];
    }
}
