<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Căutarea din pagina de blog (WP-22: B3, din Biovitality 263b520, f5295b5): potrivire fără grijă la diacritice și
 * evidențierea termenului în rezultate.
 *
 * Cine caută „sanatate" trebuie să găsească și „sănătate": pe tastatura de
 * telefon diacriticele se scriu rar.
 */
final class BlogSearch
{
    /** Forma de comparat: litere mici, fără diacritice. */
    public static function normalize(string $value): string
    {
        return str_replace('-', ' ', Slug::make($value, ''));
    }

    /**
     * Termenii după care se caută: cuvintele din întrebare, fără cele prea
     * scurte, care ar potrivi orice.
     *
     * @return string[]
     */
    public static function terms(string $query): array
    {
        $normalizat = self::normalize($query);
        if ($normalizat === '') {
            return [];
        }
        $cuvinte = preg_split('/\s+/', $normalizat, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $termeni = array_values(array_filter(
            array_unique($cuvinte),
            static fn (string $cuvant): bool => mb_strlen($cuvant) >= 2
        ));

        // Dacă tot ce s-a scris e scurt („cui", „ac"), căutăm după întreg —
        // mai bine puține rezultate decât niciunul.
        return $termeni !== [] ? $termeni : [$normalizat];
    }

    /**
     * Îmbracă în `<mark>` bucățile care se potrivesc cu ce s-a căutat.
     * Textul primit e text simplu; iese HTML gata de pus în pagină.
     */
    public static function highlight(string $text, string $query): string
    {
        $termeni = self::terms($query);
        if ($termeni === [] || trim($text) === '') {
            return htmlspecialchars($text, ENT_QUOTES);
        }

        $intervale = [];
        foreach ($termeni as $termen) {
            foreach (self::pozitii($text, $termen) as $interval) {
                $intervale[] = $interval;
            }
        }
        if ($intervale === []) {
            return htmlspecialchars($text, ENT_QUOTES);
        }

        // Termeni care se suprapun („san" și „sanatate") ar da `<mark>` unul în
        // altul; le unim înainte de a tăia textul.
        usort($intervale, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $unite = [];
        foreach ($intervale as $interval) {
            $ultim = count($unite) - 1;
            if ($ultim >= 0 && $interval[0] <= $unite[$ultim][1]) {
                $unite[$ultim][1] = max($unite[$ultim][1], $interval[1]);
                continue;
            }
            $unite[] = $interval;
        }

        $rezultat = '';
        $cursor = 0;
        foreach ($unite as [$inceput, $sfarsit]) {
            $rezultat .= htmlspecialchars(mb_substr($text, $cursor, $inceput - $cursor), ENT_QUOTES);
            $rezultat .= '<mark class="blog-hit">'
                . htmlspecialchars(mb_substr($text, $inceput, $sfarsit - $inceput), ENT_QUOTES)
                . '</mark>';
            $cursor = $sfarsit;
        }

        return $rezultat . htmlspecialchars(mb_substr($text, $cursor), ENT_QUOTES);
    }

    /**
     * O bucată de text din jurul primei potriviri, ca omul să vadă în ce
     * context apare cuvântul căutat, nu doar că apare.
     */
    public static function snippet(string $html, string $query, int $lungime = 220): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
        if ($text === '') {
            return '';
        }

        $inceput = 0;
        foreach (self::terms($query) as $termen) {
            $pozitii = self::pozitii($text, $termen);
            if ($pozitii !== []) {
                // Puțin context înainte de cuvânt, ca fraza să aibă sens.
                $inceput = max(0, $pozitii[0][0] - 60);
                break;
            }
        }

        $bucata = mb_substr($text, $inceput, $lungime);
        if ($inceput > 0) {
            // Nu tăiem în mijlocul unui cuvânt.
            $primulSpatiu = mb_strpos($bucata, ' ');
            if ($primulSpatiu !== false && $primulSpatiu < 25) {
                $bucata = mb_substr($bucata, $primulSpatiu + 1);
            }
            $bucata = '…' . $bucata;
        }
        if (mb_strlen($text) > $inceput + $lungime) {
            $bucata = rtrim($bucata) . '…';
        }

        return $bucata;
    }

    /**
     * Fragmentul care merită arătat sub un rezultat de căutare: rezumatul
     * articolului, dacă termenul apare chiar în el, altfel bucata din conținut
     * unde apare.
     */
    public static function bestSnippet(string $excerpt, string $contentHtml, string $query): string
    {
        $excerpt = trim($excerpt);
        if ($excerpt !== '' && self::contains($excerpt, $query)) {
            return self::snippet($excerpt, $query);
        }
        if (trim($contentHtml) !== '' && self::contains($contentHtml, $query)) {
            return self::snippet($contentHtml, $query);
        }

        return self::snippet($excerpt !== '' ? $excerpt : $contentHtml, $query);
    }

    /** Textul conține vreunul dintre termenii căutați? */
    public static function contains(string $text, string $query): bool
    {
        $normalizat = self::normalize(strip_tags($text));
        foreach (self::terms($query) as $termen) {
            if ($termen !== '' && str_contains($normalizat, $termen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pozițiile termenului în text, măsurate în caractere ale textului
     * original (nu ale celui normalizat).
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private static function pozitii(string $text, string $termen): array
    {
        $termen = self::normalize($termen);
        if ($termen === '') {
            return [];
        }

        // Normalizarea trebuie să păstreze numărul de caractere, altfel n-am
        // mai ști unde să punem `<mark>` în textul original. De aceea
        // înlocuim doar caracter cu caracter, nu cu `Slug::make`, care
        // comasează spații și taie capetele.
        $lungime = mb_strlen($text);
        $normalizat = mb_strtolower($text, 'UTF-8');
        $normalizat = strtr($normalizat, Slug::transliterariUnuLaUnu());
        $normalizat = (string) preg_replace('/[^a-z0-9]/u', ' ', $normalizat);

        if (mb_strlen($normalizat) !== $lungime) {
            // Un text cu litere care își schimbă lungimea la scrierea cu literă
            // mică (turcă, greacă) ar strica potrivirea pozițiilor. Rar, dar
            // atunci mai bine nu evidențiem nimic decât să tăiem aiurea.
            return [];
        }

        $gasite = [];
        $de = 0;
        while (($pozitie = mb_strpos($normalizat, $termen, $de)) !== false) {
            $gasite[] = [$pozitie, $pozitie + mb_strlen($termen)];
            $de = $pozitie + 1;
            if (count($gasite) >= 30) {
                break;
            }
        }

        return $gasite;
    }
}
