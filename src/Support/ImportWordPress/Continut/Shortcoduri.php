<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\Continut;

/**
 * Shortcodurile WordPress (`[nume atribut="x"]conținut[/nume]`, `[nume /]`), desfăcute ca un arbore, după aceleași
 * reguli ca WordPress (get_shortcode_regex, shortcode_parse_atts), fără să ruleze vreun cod: un nod e textul dintre
 * shortcoduri sau un shortcode cu numele, atributele, conținutul brut și copiii lui (shortcodurile din conținut).
 * Un `[[nume]]` dublat e text (așa îl scrie WordPress când vrea să arate un shortcode).
 */
final class Shortcoduri
{
    /**
     * Desface textul în arbore. Același rezultat ca get_shortcode_regex din WordPress, dar scris de mână, ca scaner peste octeți
     * (vezi potrivesteLa), nu cu regexul lui: caută, de la stânga, poziții unde începe un shortcode și le ia pe rând, fără
     * suprapunere; între ele, textul e nod de text. Adâncimea peste 60 oprește recursia (ca vechiul prag).
     *
     * @return list<string|array{nume: string, atribute: array<string, string>, continut: ?string, copii: list<mixed>, brut: string}>
     */
    public static function desface(string $text, int $adancime = 0): array
    {
        if ($adancime > 60) {
            return [$text];
        }
        $noduri = [];
        $n = strlen($text);
        $poz = 0; // începutul textului neconsumat încă
        $p = 0;
        while ($p < $n) {
            if ($text[$p] !== '[') {
                $p++;
                continue;
            }
            $m = self::potrivesteLa($text, $n, $p);
            if ($m === null) {
                $p++;
                continue;
            }
            $tot = substr($text, $p, $m['sfarsit'] - $p);
            if ($p > $poz) {
                $noduri[] = substr($text, $poz, $p - $poz);
            }
            $poz = $m['sfarsit'];
            $p = $m['sfarsit'];
            if ($m['desch2'] && $m['inch2']) {
                $noduri[] = substr($tot, 1, -1); // [[x]] → textul [x]
                continue;
            }
            $continut = $m['areContinut'] ? $m['continut'] : null;
            $noduri[] = [
                'nume' => $m['nume'],
                'atribute' => self::atribute($m['atribute']),
                'continut' => $continut,
                'copii' => $continut !== null ? self::desface($continut, $adancime + 1) : [],
                'brut' => $tot,
            ];
        }
        if ($poz < $n) {
            $noduri[] = substr($text, $poz);
        }
        return $noduri;
    }

    /**
     * Încearcă un shortcode chiar la poziția $p (unde $text[$p] === '['), întocmai ca get_shortcode_regex, dar fără regex:
     *  - un „[” de dublare opțional (pentru `[[…]]`);
     *  - numele `[a-zA-Z_][a-zA-Z0-9_-]*` (maxim, deci lookahead-ul `(?![\w-])” e mereu îndeplinit);
     *  - atributele: orice până la primul „]” sau „/]” (un „/” nu intră în atribute decât dacă nu-i urmat de „]”);
     *  - apoi fie „/]” (închis în sine), fie „]” cu un conținut opțional până la primul `[/nume]` (restul, inclusiv alte „[…]”,
     *    intră în conținut), fie doar „]” dacă nu există `[/nume]`;
     *  - un „]” de dublare opțional la sfârșit.
     * Întoarce părțile și poziția de după, sau null dacă nu se potrivește nimic la $p.
     *
     * @return array{sfarsit:int,desch2:bool,inch2:bool,nume:string,atribute:string,areContinut:bool,continut:string}|null
     */
    private static function potrivesteLa(string $text, int $n, int $p): ?array
    {
        $i = $p + 1; // după „[”
        $desch2 = false;
        if ($i < $n && $text[$i] === '[') {
            $desch2 = true;
            $i++;
        }
        if ($i >= $n) {
            return null;
        }
        $c = $text[$i];
        if (!(($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || $c === '_')) {
            return null; // numele trebuie să înceapă cu o literă ASCII sau „_”
        }
        $numeInc = $i;
        for ($i++; $i < $n; $i++) {
            $c = $text[$i];
            if (!(($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || ($c >= '0' && $c <= '9') || $c === '_' || $c === '-')) {
                break;
            }
        }
        $nume = substr($text, $numeInc, $i - $numeInc);
        $atribInc = $i;
        for (; $i < $n; $i++) {
            $c = $text[$i];
            if ($c === ']') {
                break;
            }
            if ($c === '/' && $i + 1 < $n && $text[$i + 1] === ']') {
                break;
            }
        }
        if ($i >= $n) {
            return null; // nici „]”, nici „/]”: fără potrivire
        }
        $atribute = substr($text, $atribInc, $i - $atribInc);
        if ($text[$i] === '/') { // „/]”: închis în sine
            $i += 2;
            $inch2 = $i < $n && $text[$i] === ']';
            return ['sfarsit' => $inch2 ? $i + 1 : $i, 'desch2' => $desch2, 'inch2' => $inch2, 'nume' => $nume,
                'atribute' => $atribute, 'areContinut' => false, 'continut' => ''];
        }
        $i++; // peste „]”
        $areContinut = false;
        $continut = '';
        $idx = strpos($text, '[/' . $nume . ']', $i);
        if ($idx !== false) {
            $areContinut = true;
            $continut = substr($text, $i, $idx - $i);
            $i = $idx + strlen($nume) + 3; // „[/” + nume + „]”
        }
        $inch2 = $i < $n && $text[$i] === ']';
        return ['sfarsit' => $inch2 ? $i + 1 : $i, 'desch2' => $desch2, 'inch2' => $inch2, 'nume' => $nume,
            'atribute' => $atribute, 'areContinut' => $areContinut, 'continut' => $continut];
    }

    /**
     * Atributele unui shortcode: numele în litere mici, cele fără nume numerotate (0, 1, 2…), ghilimelele scoase și
     * `stripcslashes` pe valoare. Rezultatul e același cu al lui shortcode_parse_atts din WordPress, dar codul e scris
     * independent, cu un mic scaner propriu (nu cu regexul lui): la fiecare poziție, în ordine, caută „nume=valoare” (cu
     * ghilimele duble, simple sau fără) ori o valoare pozițională (cu ghilimele sau un cuvânt întreg), fiecare urmată de un
     * spațiu sau de sfârșit; dacă nimic nu se potrivește, înaintează un octet (ca o căutare globală). NBSP și ZWSP devin
     * întâi spațiu. Echivalența e dovedită de testele G-wp, de cazuri-limită noi și de oracolul pe datele reale (fix-r3).
     *
     * @return array<string, string>
     */
    public static function atribute(string $text): array
    {
        $text = self::inlocuiesteSpatiileSpeciale($text);
        $n = strlen($text);
        $rez = [];
        $contor = 0;
        $i = 0;
        while ($i < $n) {
            $m = self::citesteAtribut($text, $n, $i);
            if ($m === null) {
                $i++; // nicio formă la poziția asta: înaintează un octet, ca o căutare globală
                continue;
            }
            [$nume, $valoare, $i] = $m;
            if ($nume !== null) {
                $rez[strtolower($nume)] = stripcslashes($valoare); // atribut cu nume: se ține și cu valoarea goală
            } elseif ($valoare !== '') {
                // valoare pozițională: una goală (`""`, `''`) se consumă, dar nu se ține și nu numără (ca shortcode_parse_atts)
                $rez[(string) $contor++] = stripcslashes($valoare);
            }
        }
        return $rez;
    }

    /**
     * NBSP (U+00A0) și ZWSP (U+200B), în șiruri, devin un singur spațiu — scris de mână, ca scaner peste octeți (același rezultat
     * ca `preg_replace("/[\x{00a0}\x{200b}]+/u", ' ', …)` din shortcode_parse_atts pe text UTF-8 valid, dar fără regexul lui).
     */
    private static function inlocuiesteSpatiileSpeciale(string $text): string
    {
        $n = strlen($text);
        $r = '';
        for ($i = 0; $i < $n;) {
            if (self::spatiuSpecial($text, $n, $i) > 0) {
                while (($lung = self::spatiuSpecial($text, $n, $i)) > 0) {
                    $i += $lung;
                }
                $r .= ' ';
                continue;
            }
            $r .= $text[$i];
            $i++;
        }
        return $r;
    }

    /** Lungimea în octeți a unui NBSP (0xC2 0xA0) sau ZWSP (0xE2 0x80 0x8B) la poziția $i, altfel 0. */
    private static function spatiuSpecial(string $text, int $n, int $i): int
    {
        if ($i + 1 < $n && $text[$i] === "\xc2" && $text[$i + 1] === "\xa0") {
            return 2;
        }
        if ($i + 2 < $n && $text[$i] === "\xe2" && $text[$i + 1] === "\x80" && $text[$i + 2] === "\x8b") {
            return 3;
        }
        return 0;
    }

    /** `\s` din PCRE (fără /u): HT, LF, VT, FF, CR, spațiu. */
    private static function spatiu(string $c): bool
    {
        return $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\f" || $c === "\v";
    }

    /** Un octet din `[\w-]` (nume de atribut, fără /u): litere ASCII, cifre, „_”, „-”. */
    private static function octetNume(string $c): bool
    {
        return $c === '-' || $c === '_' || ($c >= '0' && $c <= '9') || ($c >= 'A' && $c <= 'Z') || ($c >= 'a' && $c <= 'z');
    }

    /**
     * Citește un atribut de la poziția `$i`, în ordinea formelor lui shortcode_parse_atts (numele cu valoare, apoi valoarea
     * pozițională), fiecare urmată de un spațiu sau de sfârșit. Întoarce `[nume|null, valoare, poziția de după]` sau null dacă
     * niciuna nu se potrivește chiar la `$i`. @return array{0: ?string, 1: string, 2: int}|null
     */
    private static function citesteAtribut(string $text, int $n, int $i): ?array
    {
        // Granița „(?:\s|$)”: după un token trebuie să urmeze un spațiu (consumat) sau sfârșitul. Întoarce poziția de după
        // token (cu spațiul consumat) sau null dacă nu e graniță.
        $granita = static function (int $p) use ($text, $n): ?int {
            if ($p >= $n) {
                return $p;
            }
            return self::spatiu($text[$p]) ? $p + 1 : null;
        };
        // Forme 1-3: „nume” din `[\w-]+`, apoi `\s*=\s*`, apoi valoarea.
        $j = $i;
        while ($j < $n && self::octetNume($text[$j])) {
            $j++;
        }
        if ($j > $i) {
            $nume = substr($text, $i, $j - $i);
            $k = $j;
            while ($k < $n && self::spatiu($text[$k])) {
                $k++;
            }
            if ($k < $n && $text[$k] === '=') {
                $k++;
                while ($k < $n && self::spatiu($text[$k])) {
                    $k++;
                }
                foreach (['"', "'"] as $g) { // formele 1 și 2: nume="valoare" / nume='valoare'
                    if (($k < $n ? $text[$k] : '') === $g) {
                        $sf = strpos($text, $g, $k + 1);
                        if ($sf !== false && ($dupa = $granita($sf + 1)) !== null) {
                            return [$nume, substr($text, $k + 1, $sf - $k - 1), $dupa];
                        }
                    }
                }
                // forma 3: nume=valoare (valoarea `[^\s'"]+`, cel puțin un octet, urmată de spațiu sau sfârșit)
                $v = $k;
                while ($v < $n && !self::spatiu($text[$v]) && $text[$v] !== '"' && $text[$v] !== "'") {
                    $v++;
                }
                if ($v > $k && ($dupa = $granita($v)) !== null) {
                    return [$nume, substr($text, $k, $v - $k), $dupa];
                }
            }
        }
        // Forme 4-5: valoare pozițională cu ghilimele, "valoare" / 'valoare'.
        foreach (['"', "'"] as $g) {
            if ($text[$i] === $g) {
                $sf = strpos($text, $g, $i + 1);
                if ($sf !== false && ($dupa = $granita($sf + 1)) !== null) {
                    return [null, substr($text, $i + 1, $sf - $i - 1), $dupa];
                }
            }
        }
        // Forma 6: un cuvânt întreg `\S+`, urmat de spațiu sau sfârșit.
        if (!self::spatiu($text[$i])) {
            $v = $i;
            while ($v < $n && !self::spatiu($text[$v])) {
                $v++;
            }
            if (($dupa = $granita($v)) !== null) {
                return [null, substr($text, $i, $v - $i), $dupa];
            }
        }
        return null;
    }

    /**
     * Toate shortcodurile (orice adâncime) cu numele dat, în ordinea din text.
     *
     * @param list<mixed> $noduri
     * @return list<array<string, mixed>>
     */
    public static function cauta(array $noduri, string $nume): array
    {
        $rez = [];
        foreach ($noduri as $n) {
            if (!is_array($n)) {
                continue;
            }
            if ($n['nume'] === $nume) {
                $rez[] = $n;
            }
            $rez = array_merge($rez, self::cauta($n['copii'], $nume));
        }
        return $rez;
    }

    /**
     * Numele tuturor shortcodurilor dintr-un text, cu de câte ori apar.
     *
     * @param list<mixed> $noduri
     * @return array<string, int>
     */
    public static function nume(array $noduri): array
    {
        $rez = [];
        foreach ($noduri as $n) {
            if (!is_array($n)) {
                continue;
            }
            $rez[$n['nume']] = ($rez[$n['nume']] ?? 0) + 1;
            foreach (self::nume($n['copii']) as $k => $v) {
                $rez[$k] = ($rez[$k] ?? 0) + $v;
            }
        }
        ksort($rez);
        return $rez;
    }
}
