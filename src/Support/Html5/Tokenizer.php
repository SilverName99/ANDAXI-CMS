<?php

declare(strict_types=1);

namespace App\Support\Html5;

/**
 * Tokenizerul HTML5 al HTML-ului capturat (adoptarea unui site WordPress, pasul 3): stările de tokenizare WHATWG de care e
 * nevoie, pe octeți, fără nicio reserializare (HTML-ul capturat nu trece niciodată prin DOMDocument).
 *
 *  - textul (starea „data”), deschiderea tagului, numele lui, atributele (numele, valoarea cu ghilimele duble, simple sau
 *    fără), `/>`; un tag neterminat la sfârșitul textului rămâne un tag „incomplet”, cu octeții lui;
 *  - comentariile (`<!-- -->`, `<!-->`, `<!--->`, `--!>`), doctype, comentariile „false” (`<?…>`, `<!…>`, `</ …>`, `</>`);
 *  - textul brut al elementelor `script` (cu stările „escaped” și „double escaped”: `<!--` și `<script>` în script),
 *    `style`, `xmp`, `iframe`, `noembed`, `noframes`, `noscript` (scriptarea pornită, ca în browser), `plaintext`, și
 *    RCDATA (`title`, `textarea`), până la tagul lor de închidere (`</script` urmat de spațiu, „/” sau „>”);
 *  - conținutul străin (`svg`, `math`): acolo nu există text brut (un `<style>` din SVG are tokeni obișnuiți), iar
 *    `<![CDATA[ … ]]>` e o secțiune CDATA.
 *
 * Când un tag pornește textul brut, și dacă `<![CDATA[` e CDATA, o hotărăște în browser constructorul arborelui: aici
 * Constructie, constructorul WHATWG fără arbore (stiva elementelor deschise cu spațiul lor de nume, formatarea, modurile
 * de inserare, adopția, tabelele, punctele de integrare ale SVG și MathML). Tot el spune, pe fiecare tag, ce element face
 * (`Token::$spatiu`, `$ignorat`), cine îl închide (`$inchidere`, `$inchidereProprie`) și dacă parserul i-a mutat noduri
 * (`$mutat`): pereche() și blocuri() le folosesc, deci un `<div/>` (HTML ignoră „/>” la un element care nu e gol) e un div
 * deschis, iar un `<p>` închis de un `<div>` nu are pereche.
 *
 * analizeaza() dă, pe lângă tokeni, motivele pentru care un document poate fi tokenizat altfel în alt browser
 * (Constructie::$nesigure: conținutul unui `<select>`, `<search>`, o stivă prea adâncă). Politica HTML-ului capturat
 * refuză un asemenea document: tokenii lui sunt ai parserelor WHATWG de până la „customizable select”, nu ai tuturor.
 *
 * Referințele de caractere rămân netraduse, ca octeți. Fiecare token ține `[start, end)` în octeți, iar fiecare atribut
 * pozițiile numelui și ale valorii, plus ghilimelele (Token, Atribut). Tokenii se ating cap la cap și acoperă tot textul:
 * `recompune()` dă înapoi exact octeții de intrare (tools/dev/test-html5.php, poarta G-html5). Timpul e liniar în lungimea
 * textului (și în adâncimea stivei, cel mult Constructie::ADANCIME_MAXIMA; plus un buget de lucru proporțional cu lungimea).
 *
 * CONTRACTUL (vezi și Constructie): pentru orice intrare, ori `nesigure` e nevidă, ori deciziile de aici (ce octeți sunt
 * text brut / RCDATA / script / PLAINTEXT / CDATA / comentariu și ce taguri fac elemente vii, în ce spațiu de nume) se
 * potrivesc cu Chromium de azi. Octeții se citesc ca UTF-8: paginile capturate le servește CMS-ul cu antetul HTTP
 * `Content-Type: text/html; charset=utf-8` (ResponseCache îl scrie explicit, iar o pagină randată prin View primește
 * `default_charset=UTF-8` al PHP-ului; singura cale statică, `/uploads/`, n-are tip HTML), iar antetul HTTP bate
 * `<meta charset>`, pe care browserul îl ignoră — deci o codare `<meta>` ne-UTF-8 (ISO-2022-JP) din conținut nu schimbă
 * decodarea paginii servite și nu cere un marcaj nesigur. Un BOM UTF-16 la început bate însă antetul și e deja nesigur.
 *
 * Pentru garda de structură (`blocuri()`) ajunge stiva elementelor bloc (`div`, `section`, `article`), cu clasele lor, după
 * cum le deschide și le închide constructorul.
 */
final class Tokenizer
{
    /** Elementele cu text brut (RAWTEXT, script data, PLAINTEXT) în conținutul HTML. */
    public const TEXT_BRUT = ['script', 'style', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript', 'plaintext'];

    /** Elementele RCDATA: text în care contează doar referințele de caractere și tagul de închidere. */
    public const RCDATA = ['title', 'textarea'];

    /** Elementele fără conținut și fără tag de închidere. */
    public const GOALE = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr',
        'param', 'keygen', 'frame', 'basefont', 'bgsound'];

    private const SPATII = "\t\n\f\r ";

    /**
     * Tokenii unui text HTML, în ordine, acoperindu-l întreg (fără motivele de nesiguranță ale lui analizeaza()).
     *
     * @return list<Token>
     */
    public static function tokenizeaza(string $html): array
    {
        return self::analizeaza($html)['tokeni'];
    }

    /**
     * Tokenii unui document HTML, cu ce face constructorul arborelui cu fiecare tag (Token::$spatiu, $ignorat, $inchidere,
     * $inchidereProprie, $mutat), și motivele pentru care alt browser l-ar putea tokeniza altfel (`nesigure`; gol = niciunul).
     *
     * @return array{tokeni: list<Token>, nesigure: list<string>}
     */
    public static function analizeaza(string $html): array
    {
        if (!class_exists(Constructie::class, false)) {
            require_once __DIR__ . '/Constructie.php'; // și fără autoloader (unelte de sine stătătoare)
        }
        $n = strlen($html);
        $tokeni = [];
        $i = 0;
        $text = 0;
        $arbore = new Constructie($html);
        while ($i < $n) {
            $lt = strpos($html, '<', $i);
            if ($lt === false) {
                break;
            }
            $urm = $lt + 1 < $n ? $html[$lt + 1] : '';
            $token = null;
            if ($urm === '!') {
                $token = self::declaratie($html, $lt, substr($html, $lt + 2, 7) === '[CDATA[' && $arbore->cdata($lt));
            } elseif ($urm === '/') {
                $c = $lt + 2 < $n ? $html[$lt + 2] : '';
                if (self::litera($c)) {
                    $token = self::tag($html, $lt, Token::INCHIDERE);
                } elseif ($c === '>') {
                    $token = new Token(Token::FALS, $lt, $lt + 3);
                } elseif ($c !== '') {
                    $token = self::comentariuFals($html, $lt, $lt + 2);
                }
            } elseif (self::litera($urm)) {
                $token = self::tag($html, $lt, Token::DESCHIDERE);
            } elseif ($urm === '?') {
                $token = self::comentariuFals($html, $lt, $lt + 1);
            }
            if ($token === null) {
                // Un „<” care nu începe nimic („< 3”, „<=”, „</” la sfârșit) e text.
                $i = $lt + 1;
                continue;
            }
            if ($text < $lt) {
                $arbore->text(substr($html, $text, $lt - $text), count($tokeni));
                $tokeni[] = new Token(Token::TEXT, $text, $lt);
            }
            $k = count($tokeni);
            $tokeni[] = $token;
            $i = $token->end;
            $text = $i;
            if (!$token->complet) {
                // Un tag neterminat la sfârșit nu ajunge la constructor (browserul îl aruncă).
                continue;
            }
            if ($token->tip === Token::INCHIDERE) {
                $arbore->inchidere($token, $k);
            } elseif ($token->tip === Token::DOCTYPE) {
                $arbore->doctype($token->octeti($html));
            } elseif ($token->tip === Token::DESCHIDERE) {
                $stare = $arbore->deschidere($token, $k);
                if ($stare !== '') {
                    $sfarsit = $stare === 'plaintext' ? $n : self::sfarsitTextBrut($html, $token->end, $token->nume);
                    if ($sfarsit > $token->end) {
                        $tokeni[] = new Token(Token::TEXT, $token->end, $sfarsit, brut: $token->nume);
                    }
                    $i = $sfarsit;
                    $text = $sfarsit;
                }
            }
        }
        if ($text < $n) {
            $arbore->text(substr($html, $text), count($tokeni));
            $tokeni[] = new Token(Token::TEXT, $text, $n);
        }
        foreach ($tokeni as $k => $t) {
            if ($t->tip === Token::DESCHIDERE) {
                $mutat = isset($arbore->mutat[$k]);
                $tokeni[$k] = $t->cuConstructie($arbore->spatiu[$k] ?? '', $arbore->ignorat[$k] ?? !$t->complet, $arbore->inchis[$k] ?? -1,
                    ($arbore->propriu[$k] ?? false) && !$mutat, $mutat);
            } elseif ($t->tip === Token::INCHIDERE) {
                $tokeni[$k] = $t->cuConstructie('', $arbore->ignorat[$k] ?? !$t->complet, -1, false, false);
            }
        }
        return ['tokeni' => $tokeni, 'nesigure' => $arbore->nesigure];
    }

    /**
     * Textul recompus din tokeni (octeții fiecăruia, în ordine): pentru un text tokenizat, exact textul de intrare.
     *
     * @param list<Token> $tokeni
     */
    public static function recompune(string $html, array $tokeni): string
    {
        $rez = '';
        foreach ($tokeni as $t) {
            $rez .= substr($html, $t->start, $t->end - $t->start);
        }
        return $rez;
    }

    /**
     * Tokenii acoperă textul întreg, cap la cap, fără goluri și fără suprapuneri? (Verificarea din teste.)
     *
     * @param list<Token> $tokeni
     */
    public static function acopera(string $html, array $tokeni): bool
    {
        $poz = 0;
        foreach ($tokeni as $t) {
            if ($t->start !== $poz || $t->end <= $t->start) {
                return false;
            }
            $poz = $t->end;
        }
        return $poz === strlen($html);
    }

    /**
     * Indexul tagului de închidere al elementului deschis la `$index`: cel care îl închide în browser (Constructie), dacă e
     * chiar tagul lui de închidere și parserul nu i-a mutat nimic; altfel null: un element gol, unul închis de altceva (`<p>`
     * închis de un `<div>`, un `<span>` de `</div>`), unul neînchis, unul mutat de algoritmul de adopție sau de un tabel.
     * Un element HTML care nu e gol rămâne deschis și scris cu `/>` (`<div/>`, `<iframe/>`): perechea lui e `</div>`.
     *
     * @param list<Token> $tokeni
     */
    public static function pereche(array $tokeni, int $index): ?int
    {
        $d = $tokeni[$index] ?? null;
        if ($d === null || $d->tip !== Token::DESCHIDERE || !$d->inchidereProprie || $d->inchidere <= $index) {
            return null;
        }
        return $d->inchidere;
    }

    /**
     * Garda de structură: elementele bloc HTML (`$etichete`), cu clasele lor, în ordinea documentului, cu adâncimea în stiva
     * blocurilor și perechea lor (indexul tagului de închidere), după constructorul arborelui; plus problemele: un bloc
     * închis implicit (de alt tag), unul neînchis, unul mutat de parser, o închidere ignorată (fără bloc deschis în domeniu).
     * Cu `$prefixClasa`, în listă intră doar blocurile cu o clasă care începe așa (stiva le numără pe toate).
     *
     * @param list<Token> $tokeni
     * @param list<string> $etichete
     * @return array{blocuri: list<array{eticheta: string, clase: list<string>, adancime: int, deschidere: int, inchidere: ?int}>, probleme: list<string>}
     */
    public static function blocuri(string $html, array $tokeni, string $prefixClasa = '', array $etichete = ['div', 'section', 'article']): array
    {
        $blocuri = [];
        $deschise = [];
        $probleme = [];
        foreach ($tokeni as $k => $t) {
            if (!$t->esteTag() || !in_array($t->nume, $etichete, true)) {
                continue;
            }
            if ($t->tip === Token::INCHIDERE) {
                if ($t->ignorat) {
                    $probleme[$t->start][] = '</' . $t->nume . '> fără deschidere, la octetul ' . $t->start;
                }
                continue;
            }
            if ($t->ignorat || $t->spatiu !== 'html') {
                continue;
            }
            // Blocurile încă deschise când începe acesta (închise de un token de după el).
            $deschise = array_values(array_filter($deschise, static fn (int $d): bool => $tokeni[$d]->inchidere < 0 || $tokeni[$d]->inchidere > $k));
            $clase = $t->clase($html);
            $pereche = self::pereche($tokeni, $k);
            if ($prefixClasa === '' || array_filter($clase, static fn (string $c): bool => str_starts_with($c, $prefixClasa)) !== []) {
                $blocuri[] = ['eticheta' => $t->nume, 'clase' => $clase, 'adancime' => count($deschise), 'deschidere' => $k, 'inchidere' => $pereche];
            }
            $deschise[] = $k;
            if ($pereche !== null) {
                continue;
            }
            if ($t->mutat) {
                $probleme[$t->start][] = '<' . $t->nume . '> de la octetul ' . $t->start . ' e mutat de parser (formatarea sau un tabel îi mută nodurile)';
            } elseif ($t->inchidere < 0) {
                $probleme[strlen($html)][] = '<' . $t->nume . '> de la octetul ' . $t->start . ' nu se închide';
            } else {
                $inchide = $tokeni[$t->inchidere];
                $probleme[$inchide->start][] = '<' . $t->nume . '> de la octetul ' . $t->start . ' se închide implicit, la '
                    . ($inchide->tip === Token::INCHIDERE ? '</' . $inchide->nume . '>' : '<' . $inchide->nume . '>') . ' de la octetul ' . $inchide->start;
            }
        }
        ksort($probleme);
        return ['blocuri' => $blocuri, 'probleme' => $probleme === [] ? [] : array_merge(...array_values($probleme))];
    }

    // ───────────────────────────────────────────────────────────
    // Stările
    // ───────────────────────────────────────────────────────────

    /** O literă ASCII (fără locale: un octet din afara ASCII-ului nu e literă, ca WHATWG). */
    private static function litera(string $c): bool
    {
        return $c !== '' && (($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z'));
    }

    private static function spatiu(string $c): bool
    {
        return $c !== '' && strpbrk($c, self::SPATII) !== false;
    }

    /** `<!`: comentariu, doctype, CDATA (doar în conținutul străin) sau comentariu fals. */
    private static function declaratie(string $html, int $lt, bool $strain): Token
    {
        $n = strlen($html);
        if (substr($html, $lt + 2, 2) === '--') {
            return self::comentariu($html, $lt);
        }
        if (strncasecmp(substr($html, $lt + 2, 7), 'doctype', 7) === 0) {
            $gt = strpos($html, '>', $lt + 9);
            return new Token(Token::DOCTYPE, $lt, $gt === false ? $n : $gt + 1, complet: $gt !== false);
        }
        if ($strain && substr($html, $lt + 2, 7) === '[CDATA[') {
            $sf = strpos($html, ']]>', $lt + 9);
            return new Token(Token::CDATA, $lt, $sf === false ? $n : $sf + 3, complet: $sf !== false, continutStart: $lt + 9,
                continutEnd: $sf === false ? $n : $sf);
        }
        return self::comentariuFals($html, $lt, $lt + 2);
    }

    /** `<!--`: până la `-->` sau `--!>`; `<!-->` și `<!--->` se închid imediat; fără închidere, până la sfârșit. */
    private static function comentariu(string $html, int $lt): Token
    {
        $n = strlen($html);
        $c = $lt + 4;
        if (substr($html, $c, 1) === '>') {
            return new Token(Token::COMENTARIU, $lt, $c + 1, continutStart: $c, continutEnd: $c);
        }
        if (substr($html, $c, 2) === '->') {
            return new Token(Token::COMENTARIU, $lt, $c + 2, continutStart: $c, continutEnd: $c);
        }
        $poz = $c;
        while (true) {
            $liniute = strpos($html, '--', $poz);
            if ($liniute === false) {
                return new Token(Token::COMENTARIU, $lt, $n, complet: false, continutStart: $c, continutEnd: $n);
            }
            // „--” urmat de alte „-”: închiderea poate fi mai încolo („--->”).
            $k = $liniute + 2;
            while ($k < $n && $html[$k] === '-') {
                $k++;
            }
            if ($k < $n && $html[$k] === '>') {
                return new Token(Token::COMENTARIU, $lt, $k + 1, continutStart: $c, continutEnd: $k - 2);
            }
            if ($k + 1 < $n && $html[$k] === '!' && $html[$k + 1] === '>') {
                return new Token(Token::COMENTARIU, $lt, $k + 2, continutStart: $c, continutEnd: $k - 2);
            }
            // Reluăm căutarea după toată seria de „-” (la `$k`, un octet care nu e „-”), nu de la `$liniute + 1`: altfel o serie
            // lungă de „-” s-ar rescana de la fiecare „--”, în timp pătratic (docblock-ul cere timp liniar).
            $poz = $k;
        }
    }

    /** Comentariul fals: de la `<` până la primul `>` (sau sfârșitul textului). */
    private static function comentariuFals(string $html, int $lt, int $de): Token
    {
        $gt = strpos($html, '>', $de);
        $sf = $gt === false ? strlen($html) : $gt + 1;
        return new Token(Token::FALS, $lt, $sf, complet: $gt !== false, continutStart: $de, continutEnd: $gt === false ? $sf : $gt);
    }

    /** Un tag de deschidere sau de închidere, de la `<`: numele, atributele, `/>`, `>`. */
    private static function tag(string $html, int $lt, string $tip): Token
    {
        $n = strlen($html);
        $numeStart = $tip === Token::INCHIDERE ? $lt + 2 : $lt + 1;
        $i = $numeStart;
        while ($i < $n && strpbrk($html[$i], self::SPATII . '/>') === false) {
            $i++;
        }
        $nume = strtolower(substr($html, $numeStart, $i - $numeStart));
        $numeEnd = $i;
        $atribute = [];
        $vazute = [];
        $autoInchis = false;
        while (true) {
            // Înaintea numelui unui atribut: spațiile și „/” care nu închid tagul.
            while ($i < $n && (self::spatiu($html[$i]) || ($html[$i] === '/' && ($html[$i + 1] ?? '') !== '>'))) {
                $i++;
            }
            if ($i >= $n) {
                return new Token($tip, $lt, $n, $nume, $numeStart, $numeEnd, $atribute, false, false);
            }
            if ($html[$i] === '>') {
                return new Token($tip, $lt, $i + 1, $nume, $numeStart, $numeEnd, $atribute, $autoInchis, true);
            }
            if ($html[$i] === '/') {
                // „/>”
                $autoInchis = true;
                $i++;
                continue;
            }
            // Numele atributului (un „=” la început face parte din nume).
            $as = $i;
            $i++;
            while ($i < $n && strpbrk($html[$i], self::SPATII . '/>=') === false) {
                $i++;
            }
            $ae = $i;
            $numeAtribut = strtolower(substr($html, $as, $ae - $as));
            // După nume: spațiile, apoi poate „=”.
            $j = $i;
            while ($j < $n && self::spatiu($html[$j])) {
                $j++;
            }
            $duplicat = isset($vazute[$numeAtribut]);
            $vazute[$numeAtribut] = true;
            if ($j >= $n || $html[$j] !== '=') {
                $atribute[] = new Atribut($numeAtribut, $as, $ae, $as, $ae, duplicat: $duplicat);
                $i = $j < $n && $html[$j] !== '=' ? $j : $i;
                continue;
            }
            $j++;
            while ($j < $n && self::spatiu($html[$j])) {
                $j++;
            }
            if ($j >= $n) {
                $atribute[] = new Atribut($numeAtribut, $as, $n, $as, $ae, $n, $n, '', $duplicat);
                return new Token($tip, $lt, $n, $nume, $numeStart, $numeEnd, $atribute, false, false);
            }
            $g = $html[$j];
            if ($g === '"' || $g === "'") {
                $inchis = strpos($html, $g, $j + 1);
                if ($inchis === false) {
                    $atribute[] = new Atribut($numeAtribut, $as, $n, $as, $ae, $j + 1, $n, $g, $duplicat);
                    return new Token($tip, $lt, $n, $nume, $numeStart, $numeEnd, $atribute, false, false);
                }
                $atribute[] = new Atribut($numeAtribut, $as, $inchis + 1, $as, $ae, $j + 1, $inchis, $g, $duplicat);
                $i = $inchis + 1;
                continue;
            }
            if ($g === '>') {
                // `a=>`: valoare lipsă (goală), tagul se închide.
                $atribute[] = new Atribut($numeAtribut, $as, $j, $as, $ae, $j, $j, '', $duplicat);
                $i = $j;
                continue;
            }
            $vs = $j;
            while ($j < $n && strpbrk($html[$j], self::SPATII . '>') === false) {
                $j++;
            }
            $atribute[] = new Atribut($numeAtribut, $as, $j, $as, $ae, $vs, $j, '', $duplicat);
            $i = $j;
        }
    }

    /**
     * Sfârșitul textului brut sau RCDATA al unui element (poziția lui `</nume` care îl închide; sfârșitul textului dacă nu
     * se închide). În `script`, stările „escaped” (după `<!--`) și „double escaped” (după `<script` în escaped): acolo
     * `</script>` nu închide scriptul până nu se termină comentariul.
     */
    private static function sfarsitTextBrut(string $html, int $de, string $nume): int
    {
        $n = strlen($html);
        $lungime = strlen($nume);
        // „</nume” urmat de spațiu, „/” sau „>” (la sfârșitul textului, „</nume” rămâne text, ca în browser).
        $inchidere = static function (int $p) use ($html, $nume, $lungime, $n): bool {
            return substr($html, $p, 2) === '</' && strncasecmp(substr($html, $p + 2, $lungime), $nume, $lungime) === 0
                && $p + 2 + $lungime < $n && strpbrk($html[$p + 2 + $lungime], self::SPATII . '/>') !== false;
        };
        if ($nume !== 'script') {
            $p = $de;
            while (($p = strpos($html, '</', $p)) !== false) {
                if ($inchidere($p)) {
                    return $p;
                }
                $p++;
            }
            return $n;
        }
        $stare = 'date';
        $p = $de;
        // Următorul „-->” de la $p încolo, ținut minte: se caută din nou doar după ce $p trece de el (altfel fiecare „<” din
        // starea „escaped” ar căuta până la sfârșitul textului: timp pătratic pe un text făcut anume).
        $sf = -1;
        while ($p < $n) {
            if ($stare === 'date') {
                $lt = strpos($html, '<', $p);
                if ($lt === false) {
                    return $n;
                }
                if ($inchidere($lt)) {
                    return $lt;
                }
                if (substr($html, $lt, 4) === '<!--') {
                    $stare = 'escaped';
                    // „<!--” poate închide imediat („<!-->”): liniuțele lui se socotesc pentru „-->”.
                    $p = $lt + 2;
                    continue;
                }
                $p = $lt + 1;
                continue;
            }
            // escaped / double escaped: căutăm „-->”, „</script” și, în escaped, „<script”.
            if ($sf !== false && $sf < $p) {
                $sf = strpos($html, '-->', $p);
            }
            $lt = strpos($html, '<', $p);
            if ($sf !== false && ($lt === false || $sf < $lt)) {
                $stare = 'date';
                $p = $sf + 3;
                continue;
            }
            if ($lt === false) {
                return $n;
            }
            if ($inchidere($lt)) {
                if ($stare === 'escaped') {
                    return $lt;
                }
                $stare = 'escaped';
                $p = $lt + 2 + $lungime;
                continue;
            }
            if ($stare === 'escaped' && strncasecmp(substr($html, $lt + 1, $lungime), $nume, $lungime) === 0
                && $lt + 1 + $lungime < $n && strpbrk($html[$lt + 1 + $lungime], self::SPATII . '/>') !== false) {
                $stare = 'dublu';
                $p = $lt + 1 + $lungime;
                continue;
            }
            $p = $lt + 1;
        }
        return $n;
    }
}
