<?php

declare(strict_types=1);

namespace App\Support\Html5;

/**
 * Constructorul arborelui HTML5 (WHATWG, „tree construction”), FĂRĂ arbore, pentru Tokenizer: stiva elementelor deschise (cu
 * spațiul de nume al fiecăruia), lista elementelor de formatare active (cu markerii și regula „Noah's Ark”), modurile de
 * inserare (inițial, cap, corp, text, tabel, rând, celulă, select, șablon, frameset…), algoritmul de adopție al formatării,
 * plasarea în afara tabelului (foster parenting) și regulile conținutului străin (SVG, MathML, punctele de integrare).
 *
 * De ce: în browser constructorul hotărăște starea tokenizerului (textul brut al lui `<style>` există doar în HTML; în SVG
 * `<style>` are tokeni obișnuiți, iar `<![CDATA[` e o secțiune CDATA doar acolo), deci un tokenizer care urmează doar o
 * stivă a elementelor străine greșește tocmai unde contează: o închidere fără pereche în SVG (`<div><svg></span>`) e
 * ignorată, nu scoate parserul din SVG; `<mglyph>` din `<mi>` rămâne MathML; `</svg>` dintr-un `foreignObject` cu un element
 * HTML deschis e ignorat; `</td>` scoate parserul din SVG prin regula tabelului; formatarea reconstruită pune la loc
 * elemente HTML sub SVG. Un tag văzut greșit înseamnă un `<img onerror>` ascuns de politica HTML-ului capturat.
 *
 * Fără DOM: ce mută DOM-ul (copiii mutați de adopție, nodurile scoase în fața tabelului) nu se construiește, doar se ține
 * minte pe elementele atinse (`mutat`): intervalul lor de octeți nu mai e conținutul lor. Documentul e unul întreg (modul
 * inițial, ca o pagină capturată), cu scriptarea pornită (`<noscript>` e text brut), ca în browser.
 *
 * Regulile sunt cele ale parserelor WHATWG de până la „customizable select” (2025), adică ale lui parse5 7 (G-html5 le
 * verifică pe cazuri scrise de mână, iar diferențial, din afara repo-ului, pe texte făcute la întâmplare). Unde browserele
 * de azi diferă între ele sau față de regulile de aici, documentul se marchează nesigur (`nesigure`), iar politica
 * HTML-ului capturat îl refuză: conținutul unui `<select>` (parserele vechi ignoră acolo `<style>`, `<xmp>`, `<svg>`…, cele
 * noi le construiesc), elementul `<search>` (închide `<p>` doar în parserele noi), o stivă mai adâncă de ADANCIME_MAXIMA.
 *
 * CONTRACTUL (pe care îl impun Constructie și Tokenizer): pentru ORICE intrare, ori `nesigure` e nevidă, ori deciziile
 * tokenizerului — ce octeți sunt text brut / RCDATA / script / PLAINTEXT / CDATA / comentariu, și ce taguri de deschidere
 * fac elemente vii, în ce spațiu de nume — se potrivesc cu Chromium de azi. Unde browserele se abat de la WHATWG sau
 * unele de altele, NU imităm fiecare abatere, ci prindem construcția care o declanșează și marcăm documentul nesigur.
 * Preferăm declanșatori largi și simpli, pe care conținutul real nu-i conține niciodată (cele 60 de documente reale din
 * cache), în locul simulării exacte a fiecărui capriciu: de aceea orice „&” în valoarea unui atribut care dirijează
 * construcția (`encoding` la annotation-xml, `type` la input) e nesigur, o închidere în conținut străin cu un nume SVG
 * camelCase care nu închide chiar nodul curent e nesigură, iar un buget de lucru depășit oprește analiza.
 */
final class Constructie
{
    /** Peste atâtea elemente deschise documentul e nesigur (o pagină obișnuită are sub 100); ține și timpul liniar. */
    public const ADANCIME_MAXIMA = 512;

    /**
     * Bugetul de lucru (elemente create — reconstruirea formatării inclusă — plus pași de plimbare a stivei), proporțional
     * cu lungimea documentului, cu un prag generos: `BUGET_PER_OCTET * lungime`, dar cel puțin `BUGET_MINIM`. Peste el,
     * documentul e nesigur și analiza se oprește (ca garda ADANCIME_MAXIMA): altfel 500 de elemente de formatare
     * reconstruite de fiecare text (memoria) sau 100 000 de închideri fără pereche peste o stivă adâncă (timpul) ar scăpa de
     * sub control. Mărginește memoria și timpul liniar în lungimea documentului. Paginile reale folosesc sub 0,2% din el
     * (maximul măsurat pe cele 60 de documente reale: 0,135%), mult sub 5%.
     */
    public const BUGET_PER_OCTET = 12;
    public const BUGET_MINIM = 1_000_000;

    private const SPECIALE_HTML = ['address', 'applet', 'area', 'article', 'aside', 'base', 'basefont', 'bgsound', 'blockquote', 'body', 'br',
        'button', 'caption', 'center', 'col', 'colgroup', 'dd', 'details', 'dir', 'div', 'dl', 'dt', 'embed', 'fieldset', 'figcaption', 'figure',
        'footer', 'form', 'frame', 'frameset', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'header', 'hgroup', 'hr', 'html', 'iframe', 'img',
        'input', 'keygen', 'li', 'link', 'listing', 'main', 'marquee', 'menu', 'meta', 'nav', 'noembed', 'noframes', 'noscript', 'object', 'ol',
        'p', 'param', 'plaintext', 'pre', 'script', 'section', 'select', 'source', 'style', 'summary', 'table', 'tbody', 'td', 'template',
        'textarea', 'tfoot', 'th', 'thead', 'title', 'tr', 'track', 'ul', 'wbr', 'xmp'];
    private const SPECIALE_MATH = ['mi', 'mo', 'mn', 'ms', 'mtext', 'annotation-xml'];
    private const SPECIALE_SVG = ['foreignobject', 'desc', 'title'];
    private const FORMATARE = ['a', 'b', 'big', 'code', 'em', 'font', 'i', 'nobr', 's', 'small', 'strike', 'strong', 'tt', 'u'];
    private const LIMITE_HTML = ['applet', 'caption', 'html', 'table', 'td', 'th', 'marquee', 'object', 'template'];
    private const IMPLICITE = ['dd', 'dt', 'li', 'optgroup', 'option', 'p', 'rb', 'rp', 'rt', 'rtc'];
    private const IMPLICITE_TOATE = ['dd', 'dt', 'li', 'optgroup', 'option', 'p', 'rb', 'rp', 'rt', 'rtc', 'caption', 'colgroup', 'tbody', 'td',
        'tfoot', 'th', 'thead', 'tr'];
    private const TEXT_MATH = ['mi', 'mo', 'mn', 'ms', 'mtext'];
    /**
     * Elementele SVG cu nume în camelCase (forma pe care parserul o dă numelui, „adjust SVG tag names”), cu litere mici. Blink
     * se abate aici de la WHATWG: la o închidere din conținut străin, rescrie majusculele numelui și apoi n-o mai potrivește în
     * conținutul HTML, deci ignoră tagul, în loc să scoată parserul din SVG ca WHATWG. Un document unde se vede abaterea e nesigur.
     */
    private const SVG_CAMEL = ['altglyph', 'altglyphdef', 'altglyphitem', 'animatecolor', 'animatemotion', 'animatetransform', 'clippath',
        'feblend', 'fecolormatrix', 'fecomponenttransfer', 'fecomposite', 'feconvolvematrix', 'fediffuselighting', 'fedisplacementmap',
        'fedistantlight', 'fedropshadow', 'feflood', 'fefunca', 'fefuncb', 'fefuncg', 'fefuncr', 'fegaussianblur', 'feimage', 'femerge',
        'femergenode', 'femorphology', 'feoffset', 'fepointlight', 'fespecularlighting', 'fespotlight', 'fetile', 'feturbulence',
        'foreignobject', 'glyphref', 'lineargradient', 'radialgradient', 'textpath'];
    /** Deschiderile care, în conținutul străin, scot parserul înapoi în HTML (`font` doar cu `color`, `face` sau `size`). */
    private const IESIRI_STRAINE = ['b', 'big', 'blockquote', 'body', 'br', 'center', 'code', 'dd', 'div', 'dl', 'dt', 'em', 'embed', 'h1', 'h2',
        'h3', 'h4', 'h5', 'h6', 'head', 'hr', 'i', 'img', 'li', 'listing', 'menu', 'meta', 'nobr', 'ol', 'p', 'pre', 'ruby', 's', 'small', 'span',
        'strong', 'strike', 'sub', 'sup', 'table', 'tt', 'u', 'ul', 'var'];
    private const BLOCURI_P = ['address', 'article', 'aside', 'blockquote', 'center', 'details', 'dialog', 'dir', 'div', 'dl', 'fieldset',
        'figcaption', 'figure', 'footer', 'header', 'hgroup', 'main', 'menu', 'nav', 'ol', 'p', 'section', 'summary', 'ul'];
    private const BLOCURI_SFARSIT = ['address', 'article', 'aside', 'blockquote', 'button', 'center', 'details', 'dialog', 'dir', 'div', 'dl',
        'fieldset', 'figcaption', 'figure', 'footer', 'header', 'hgroup', 'listing', 'main', 'menu', 'nav', 'ol', 'pre', 'section', 'summary', 'ul'];
    private const TITLURI = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
    private const IN_CAP = ['base', 'basefont', 'bgsound', 'link', 'meta', 'noframes', 'script', 'style', 'template', 'title'];
    private const TABEL_SECTIUNI = ['tbody', 'tfoot', 'thead'];
    private const TABEL_TINTE = ['table', 'tbody', 'tfoot', 'thead', 'tr'];
    private const MODURI_TABEL = ['in table', 'in caption', 'in table body', 'in row', 'in cell'];
    private const SPATII = "\t\n\f\r ";

    /** @var array<int, array{ns: string, nume: string, tok: int, atr: array<string, string>}> elementele, după id */
    private array $el = [];
    /** @var list<int> stiva elementelor deschise (id-uri), de la rădăcină la elementul curent */
    private array $stiva = [];
    /** @var list<int|null> lista elementelor de formatare active (null = marker) */
    private array $fmt = [];
    /** @var array<string, int> câte elemente cu acest nume (`ns:nume`) sunt pe stivă (verificările „în domeniu” scurtate) */
    private array $peStiva = [];
    private string $mod = 'initial';
    private string $modOriginal = 'in body';
    /** @var list<string> */
    private array $moduriSablon = [];
    private ?int $formular = null;
    private ?int $cap = null;
    private bool $framesetOk = true;
    private bool $quirks = false;
    private bool $foster = false;
    private int $urmatorul = 0;
    /** Tokenul de la care începe corpul (un `<frameset>` îl scoate din document, cu tot ce e în el). */
    private int $tokCorp = -1;
    private int $tok = -1;
    private ?string $sfarsit = null;
    private int $efecte = 0;
    private string $stareCeruta = '';
    private bool $oprit = false;
    /** Lucrul făcut până acum (elemente create + pași de stivă) și bugetul lui (proporțional cu lungimea documentului). */
    public int $lucru = 0;
    public readonly int $bugetLucru;

    /** @var array<int, string> tokenul deschiderii → spațiul de nume al elementului făcut */
    public array $spatiu = [];
    /** @var array<int, bool> tokenii ignorați de constructor */
    public array $ignorat = [];
    /** @var array<int, int> tokenul deschiderii → tokenul care a scos elementul de pe stivă */
    public array $inchis = [];
    /** @var array<int, bool> tokenul deschiderii → elementul l-a închis chiar tagul lui de închidere */
    public array $propriu = [];
    /** @var array<int, bool> tokenul deschiderii → parserul a mutat noduri ale lui (adopția, plasarea în afara tabelului) */
    public array $mutat = [];
    /** @var list<string> de ce tokenizarea poate diferi între browsere (politica refuză un document nesigur) */
    public array $nesigure = [];

    /** @var array<string, array<string, true>> seturile de nume pentru verificările „în domeniu”, calculate o dată (timp liniar) */
    private static array $seturi = [];

    public function __construct(private readonly string $html)
    {
        $this->bugetLucru = max(self::BUGET_MINIM, self::BUGET_PER_OCTET * strlen($html));
        // Un BOM UTF-16 la început: browserul decodează documentul ca UTF-16 (sniffingul BOM-ului bate `charset=utf-8`), deci
        // l-ar tokeniza cu totul altfel decât tokenizerul pe octeți de aici. Documentul e nesigur (BOM-ul UTF-8, EF BB BF, e bun).
        if (str_starts_with($html, "\xFE\xFF") || str_starts_with($html, "\xFF\xFE")) {
            $this->nesigur('BOM UTF-16 la începutul documentului: browserul îl citește ca UTF-16, nu pe octeți ca aici');
        }
    }

    /** Un set de nume (nume → true), calculat o dată: apartenența în O(1), nu un `in_array` pe fiecare token (adâncimea stivei). */
    private static function set(string $cheie, array $lista): array
    {
        return self::$seturi[$cheie] ??= array_fill_keys($lista, true);
    }

    // ───────────────────────────────────────────────────────────
    // Ce primește de la tokenizer
    // ───────────────────────────────────────────────────────────

    /**
     * Un tag de deschidere (complet): îl construiește și întoarce starea în care trece tokenizerul după el: '' (date),
     * 'rawtext', 'rcdata', 'script' sau 'plaintext'.
     */
    public function deschidere(Token $t, int $k): string
    {
        if ($this->oprit) {
            return $this->stareFaraArbore($t);
        }
        $this->tok = $k;
        $this->sfarsit = null;
        $this->stareCeruta = '';
        $this->efecte = 0;
        if ($t->nume === 'search') {
            $this->nesigur('<search> de la octetul ' . $t->start . ': închide un <p> deschis doar în parserele noi');
        }
        $this->verificaConstructe($t);
        $this->proceseaza(['tip' => 'start', 'nume' => $t->nume, 'atr' => $this->atribute($t), 'auto' => $t->autoInchis, 'k' => $k, 'start' => $t->start]);
        if (!isset($this->spatiu[$k])) {
            $this->ignorat[$k] = true;
        }
        $this->verificaAdancimea($t->start);
        $this->verificaLucru($t->start);
        return $this->stareCeruta;
    }

    public function inchidere(Token $t, int $k): void
    {
        if ($this->oprit) {
            return;
        }
        $this->tok = $k;
        $this->sfarsit = $t->nume;
        $this->efecte = 0;
        if ($t->nume === 'search') {
            $this->nesigur('</search> de la octetul ' . $t->start . ': elementul e cunoscut doar parserelor noi');
        }
        $this->proceseaza(['tip' => 'end', 'nume' => $t->nume, 'atr' => [], 'auto' => false, 'k' => $k, 'start' => $t->start]);
        if ($this->efecte === 0) {
            $this->ignorat[$k] = true;
        }
        $this->sfarsit = null;
        $this->verificaLucru($t->start);
    }

    /** Textul din starea „date” (octeții lui, cu referințele de caractere nedecodate). */
    public function text(string $octeti, int $k): void
    {
        if ($this->oprit || $octeti === '') {
            return;
        }
        $this->tok = $k;
        $this->sfarsit = null;
        $this->proceseaza(['tip' => 'text', 'c' => self::caractere($octeti), 'k' => $k]);
        $this->verificaAdancimea(0);
        $this->verificaLucru(0);
    }

    /** Un comentariu (și unul fals, `<?…>`, `<!…>`): nu schimbă stiva; doar încheie textul în așteptare al unui tabel. */
    public function comentariu(int $k): void
    {
    }

    /** `<!DOCTYPE …>`: în modul inițial hotărăște modul „quirks” (în care `<table>` nu închide un `<p>`). */
    public function doctype(string $octeti): void
    {
        if ($this->oprit || $this->mod !== 'initial') {
            return;
        }
        $this->quirks = self::quirks($octeti);
        $this->mod = 'before html';
    }

    /** Nodul curent ajustat e în afara HTML-ului (SVG, MathML)? */
    public function strain(): bool
    {
        $c = $this->curent();
        return $c !== null && $this->el[$c]['ns'] !== 'html';
    }

    /**
     * `<![CDATA[` de la octetul dat e o secțiune CDATA? Doar în conținutul străin, ca Blink și parse5: nu și la un punct de
     * integrare (`foreignObject`, `desc`, `title`, `mi`…, `annotation-xml` HTML), unde WHATWG spune CDATA și Gecko la fel.
     * Acolo documentul e nesigur, iar tokenul e un comentariu fals.
     */
    public function cdata(int $octet): bool
    {
        $c = $this->curent();
        if ($this->oprit || $c === null || $this->el[$c]['ns'] === 'html') {
            return false;
        }
        if ($this->integrareHtml($c) || ($this->el[$c]['ns'] === 'math' && in_array($this->el[$c]['nume'], self::TEXT_MATH, true))) {
            $this->nesigur('<![CDATA[ la octetul ' . $octet . ', într-un punct de integrare: CDATA după WHATWG și Gecko, comentariu fals după Blink');
            return false;
        }
        return true;
    }

    // ───────────────────────────────────────────────────────────
    // Despărțirea pe moduri
    // ───────────────────────────────────────────────────────────

    /** @param array<string, mixed> $tk */
    private function proceseaza(array $tk): void
    {
        $c = $this->curent();
        if ($c === null || $this->el[$c]['ns'] === 'html' || $this->htmlPentru($c, $tk)) {
            $this->dupaMod($tk);
            return;
        }
        $this->strainToken($tk);
    }

    /** Regulile HTML se aplică și în conținutul străin, la punctele de integrare (WHATWG, „tree construction dispatcher”). */
    private function htmlPentru(int $c, array $tk): bool
    {
        $e = $this->el[$c];
        if ($tk['tip'] !== 'start' && $tk['tip'] !== 'text') {
            return false;
        }
        if ($e['ns'] === 'math' && in_array($e['nume'], self::TEXT_MATH, true)) {
            return $tk['tip'] === 'text' || !in_array($tk['nume'], ['mglyph', 'malignmark'], true);
        }
        if ($e['ns'] === 'math' && $e['nume'] === 'annotation-xml' && $tk['tip'] === 'start' && $tk['nume'] === 'svg') {
            return true;
        }
        return $this->integrareHtml($c);
    }

    private function integrareHtml(int $id): bool
    {
        $e = $this->el[$id];
        if ($e['ns'] === 'svg') {
            return in_array($e['nume'], self::SPECIALE_SVG, true);
        }
        if ($e['ns'] === 'math' && $e['nume'] === 'annotation-xml') {
            $codare = strtolower($this->valoareDirector($e['atr']['encoding'] ?? '', 'encoding de la <annotation-xml>'));
            return in_array($codare, ['text/html', 'application/xhtml+xml'], true);
        }
        return false;
    }

    /** @param array<string, mixed> $tk */
    private function dupaMod(array $tk): void
    {
        match ($this->mod) {
            'initial' => $this->modInitial($tk),
            'before html' => $this->modInainteDeHtml($tk),
            'before head' => $this->modInainteDeCap($tk),
            'in head' => $this->modInCap($tk),
            'after head' => $this->modDupaCap($tk),
            'in body' => $this->modInCorp($tk),
            'text' => $this->modText($tk),
            'in table' => $this->modInTabel($tk),
            'in caption' => $this->modInLegenda($tk),
            'in column group' => $this->modInGrupColoane($tk),
            'in table body' => $this->modInCorpTabel($tk),
            'in row' => $this->modInRand($tk),
            'in cell' => $this->modInCelula($tk),
            'in select' => $this->modInSelect($tk),
            'in select in table' => $this->modInSelectInTabel($tk),
            'in template' => $this->modInSablon($tk),
            'after body' => $this->modDupaCorp($tk),
            'in frameset' => $this->modInFrameset($tk),
            'after frameset' => $this->modDupaFrameset($tk),
            'after after body' => $this->modDupaDupaCorp($tk),
            'after after frameset' => $this->modDupaDupaFrameset($tk),
            default => null,
        };
    }

    /** Spațiile de la începutul unui text (le consumă modul) și restul lui. @return array{0: string, 1: string} */
    private static function spatiiInainte(string $c): array
    {
        $n = strspn($c, self::SPATII);
        return [substr($c, 0, $n), substr($c, $n)];
    }

    // ───────────────────────────────────────────────────────────
    // Modurile de dinaintea corpului
    // ───────────────────────────────────────────────────────────

    private function modInitial(array $tk): void
    {
        if ($tk['tip'] === 'text') {
            [, $rest] = self::spatiiInainte($tk['c']);
            if ($rest === '') {
                return;
            }
            $tk['c'] = $rest;
        }
        // Fără doctype: modul „quirks”.
        $this->quirks = true;
        $this->mod = 'before html';
        $this->proceseaza($tk);
    }

    private function modInainteDeHtml(array $tk): void
    {
        if ($tk['tip'] === 'text') {
            [, $rest] = self::spatiiInainte($tk['c']);
            if ($rest === '') {
                return;
            }
            $tk['c'] = $rest;
        } elseif ($tk['tip'] === 'start' && $tk['nume'] === 'html') {
            $this->insereaza($tk);
            $this->mod = 'before head';
            return;
        } elseif ($tk['tip'] === 'end' && !in_array($tk['nume'], ['head', 'body', 'html', 'br'], true)) {
            return;
        }
        $this->insereazaImplicit('html');
        $this->mod = 'before head';
        $this->proceseaza($tk);
    }

    private function modInainteDeCap(array $tk): void
    {
        if ($tk['tip'] === 'text') {
            [, $rest] = self::spatiiInainte($tk['c']);
            if ($rest === '') {
                return;
            }
            $tk['c'] = $rest;
        } elseif ($tk['tip'] === 'start' && $tk['nume'] === 'html') {
            $this->modInCorp($tk);
            return;
        } elseif ($tk['tip'] === 'start' && $tk['nume'] === 'head') {
            $this->cap = $this->insereaza($tk);
            $this->mod = 'in head';
            return;
        } elseif ($tk['tip'] === 'end' && !in_array($tk['nume'], ['head', 'body', 'html', 'br'], true)) {
            return;
        }
        $this->cap = $this->insereazaImplicit('head');
        $this->mod = 'in head';
        $this->proceseaza($tk);
    }

    private function modInCap(array $tk): void
    {
        if ($tk['tip'] === 'text') {
            [, $rest] = self::spatiiInainte($tk['c']);
            if ($rest === '') {
                return;
            }
            $tk['c'] = $rest;
        } elseif ($tk['tip'] === 'start') {
            $n = $tk['nume'];
            if ($n === 'html') {
                $this->modInCorp($tk);
                return;
            }
            if (in_array($n, ['base', 'basefont', 'bgsound', 'link', 'meta'], true)) {
                $this->insereaza($tk);
                $this->scoate(false);
                return;
            }
            if ($n === 'title') {
                $this->textBrut($tk, 'rcdata');
                return;
            }
            if (in_array($n, ['noscript', 'noframes', 'style'], true)) {
                $this->textBrut($tk, 'rawtext');
                return;
            }
            if ($n === 'script') {
                $this->textBrut($tk, 'script');
                return;
            }
            if ($n === 'template') {
                $this->insereaza($tk);
                $this->fmt[] = null;
                $this->framesetOk = false;
                $this->mod = 'in template';
                $this->moduriSablon[] = 'in template';
                return;
            }
            if ($n === 'head') {
                return;
            }
        } elseif ($tk['tip'] === 'end') {
            $n = $tk['nume'];
            if ($n === 'head') {
                $this->scoate(true);
                $this->mod = 'after head';
                return;
            }
            if ($n === 'template') {
                $this->sfarsitSablon();
                return;
            }
            if (!in_array($n, ['body', 'html', 'br'], true)) {
                return;
            }
        }
        // Altceva: capul se închide, apoi modul „după cap”.
        $this->scoate(false);
        $this->mod = 'after head';
        $this->proceseaza($tk);
    }

    private function modDupaCap(array $tk): void
    {
        if ($tk['tip'] === 'text') {
            [, $rest] = self::spatiiInainte($tk['c']);
            if ($rest === '') {
                return;
            }
            $tk['c'] = $rest;
        } elseif ($tk['tip'] === 'start') {
            $n = $tk['nume'];
            if ($n === 'html') {
                $this->modInCorp($tk);
                return;
            }
            if ($n === 'body') {
                $this->tokCorp = $this->tok;
                $this->insereaza($tk);
                $this->framesetOk = false;
                $this->mod = 'in body';
                return;
            }
            if ($n === 'frameset') {
                $this->insereaza($tk);
                $this->mod = 'in frameset';
                return;
            }
            if (in_array($n, self::IN_CAP, true)) {
                // Capul se pune iar pe stivă cât ține tagul, apoi se scoate de oriunde ar fi.
                if ($this->cap !== null) {
                    $this->stiva[] = $this->cap;
                    $this->numara($this->cap, 1);
                }
                $this->modInCap($tk);
                if ($this->cap !== null) {
                    $this->scoateDinStiva($this->cap);
                }
                return;
            }
            if ($n === 'head') {
                return;
            }
        } elseif ($tk['tip'] === 'end') {
            if ($tk['nume'] === 'template') {
                $this->modInCap($tk);
                return;
            }
            if (!in_array($tk['nume'], ['body', 'html', 'br'], true)) {
                return;
            }
        }
        $this->tokCorp = $this->tok;
        $this->insereazaImplicit('body');
        $this->mod = 'in body';
        $this->proceseaza($tk);
    }

    // ───────────────────────────────────────────────────────────
    // Corpul
    // ───────────────────────────────────────────────────────────

    private function modInCorp(array $tk): void
    {
        if ($tk['tip'] === 'text') {
            $this->textInCorp($tk['c']);
            return;
        }
        $n = $tk['nume'];
        if ($tk['tip'] === 'start') {
            $this->deschidereInCorp($tk, $n);
            return;
        }
        $this->inchidereInCorp($tk, $n);
    }

    /** Caracterele în corp: fiecare (afară de NUL) reconstruiește formatarea; unul care nu e spațiu oprește frameset-ul. */
    private function textInCorp(string $c): void
    {
        $fara = str_replace("\0", '', $c);
        if ($fara === '') {
            return;
        }
        $this->reconstruieste();
        if (strspn($fara, self::SPATII) !== strlen($fara)) {
            $this->framesetOk = false;
        }
        if ($this->foster && $this->tintaTabel()) {
            $this->marcheazaFoster();
        }
    }

    private function deschidereInCorp(array $tk, string $n): void
    {
        if ($n === 'html') {
            return; // atributele trec pe <html>, dacă nu e un șablon pe stivă; niciun element
        }
        if (in_array($n, self::IN_CAP, true)) {
            $this->modInCap($tk);
            return;
        }
        if ($n === 'body') {
            if (count($this->stiva) > 1 && $this->numeLa(1) === 'body' && !$this->areSablon()) {
                $this->framesetOk = false;
            }
            return;
        }
        if ($n === 'frameset') {
            if (count($this->stiva) < 2 || $this->numeLa(1) !== 'body' || !$this->framesetOk) {
                return;
            }
            // Corpul iese din document, cu tot ce e în el (și elementele lui închise deja): nu mai există în DOM.
            foreach (array_keys($this->spatiu) as $tok) {
                if ($tok >= $this->tokCorp) {
                    $this->mutat[$tok] = true;
                }
            }
            while (count($this->stiva) > 1) {
                $this->scoate(false);
            }
            $this->insereaza($tk);
            $this->mod = 'in frameset';
            return;
        }
        if (in_array($n, self::BLOCURI_P, true)) {
            $this->inchidePDacaE();
            $this->insereaza($tk);
            return;
        }
        if (in_array($n, self::TITLURI, true)) {
            $this->inchidePDacaE();
            $c = $this->curent();
            if ($c !== null && $this->eHtml($c, self::TITLURI)) {
                $this->scoate(false);
            }
            $this->insereaza($tk);
            return;
        }
        if ($n === 'pre' || $n === 'listing') {
            $this->inchidePDacaE();
            $this->insereaza($tk);
            $this->framesetOk = false;
            return;
        }
        if ($n === 'form') {
            if ($this->formular !== null && !$this->areSablon()) {
                return;
            }
            $this->inchidePDacaE();
            $id = $this->insereaza($tk);
            if (!$this->areSablon()) {
                $this->formular = $id;
            }
            return;
        }
        if ($n === 'li' || $n === 'dd' || $n === 'dt') {
            $this->framesetOk = false;
            $cauta = $n === 'li' ? ['li'] : ['dd', 'dt'];
            for ($i = count($this->stiva) - 1; $i >= 0; $i--) {
                $id = $this->stiva[$i];
                if ($this->eHtml($id, $cauta)) {
                    $nume = $this->el[$id]['nume'];
                    $this->genereazaImplicite($nume);
                    $this->scoatePanaLa($id, false);
                    break;
                }
                if ($this->special($id) && !$this->eHtml($id, ['address', 'div', 'p'])) {
                    break;
                }
            }
            $this->inchidePDacaE();
            $this->insereaza($tk);
            return;
        }
        if ($n === 'plaintext') {
            $this->inchidePDacaE();
            $this->insereaza($tk);
            $this->stareCeruta = 'plaintext';
            return;
        }
        if ($n === 'button') {
            if ($this->inDomeniu(['button'])) {
                $this->genereazaImplicite();
                $this->scoatePanaLaNume(['button'], false);
            }
            $this->reconstruieste();
            $this->insereaza($tk);
            $this->framesetOk = false;
            return;
        }
        if ($n === 'a') {
            $a = $this->ultimaFormatare('a');
            if ($a !== null) {
                $this->adoptie('a');
                $this->scoateDinFormatare($a);
                if ($this->indexInStiva($a) !== null) {
                    $this->scoateDinStiva($a);
                }
            }
            $this->reconstruieste();
            $this->puneInFormatare($this->insereaza($tk));
            return;
        }
        if ($n === 'nobr') {
            $this->reconstruieste();
            if ($this->inDomeniu(['nobr'])) {
                $this->adoptie('nobr');
                $this->reconstruieste();
            }
            $this->puneInFormatare($this->insereaza($tk));
            return;
        }
        if (in_array($n, self::FORMATARE, true)) {
            $this->reconstruieste();
            $this->puneInFormatare($this->insereaza($tk));
            return;
        }
        if (in_array($n, ['applet', 'marquee', 'object'], true)) {
            $this->reconstruieste();
            $this->insereaza($tk);
            $this->fmt[] = null;
            $this->framesetOk = false;
            return;
        }
        if ($n === 'table') {
            if (!$this->quirks) {
                $this->inchidePDacaE();
            }
            $this->insereaza($tk);
            $this->framesetOk = false;
            $this->mod = 'in table';
            return;
        }
        if (in_array($n, ['area', 'br', 'embed', 'img', 'keygen', 'wbr', 'image'], true)) {
            $this->reconstruieste();
            $this->insereaza($tk);
            $this->scoate(false);
            $this->framesetOk = false;
            return;
        }
        if ($n === 'input') {
            $this->reconstruieste();
            $this->insereaza($tk);
            $this->scoate(false);
            if (!$this->ascuns($tk)) {
                $this->framesetOk = false;
            }
            return;
        }
        if (in_array($n, ['param', 'source', 'track'], true)) {
            $this->insereaza($tk);
            $this->scoate(false);
            return;
        }
        if ($n === 'hr') {
            $this->inchidePDacaE();
            $this->insereaza($tk);
            $this->scoate(false);
            $this->framesetOk = false;
            return;
        }
        if ($n === 'textarea') {
            $this->framesetOk = false;
            $this->textBrut($tk, 'rcdata');
            return;
        }
        if ($n === 'xmp') {
            $this->inchidePDacaE();
            $this->reconstruieste();
            $this->framesetOk = false;
            $this->textBrut($tk, 'rawtext');
            return;
        }
        if ($n === 'iframe') {
            $this->framesetOk = false;
            $this->textBrut($tk, 'rawtext');
            return;
        }
        if ($n === 'noembed' || $n === 'noscript') {
            $this->textBrut($tk, 'rawtext');
            return;
        }
        if ($n === 'select') {
            $this->reconstruieste();
            $this->insereaza($tk);
            $this->framesetOk = false;
            $this->mod = in_array($this->mod, self::MODURI_TABEL, true) ? 'in select in table' : 'in select';
            return;
        }
        if ($n === 'optgroup' || $n === 'option') {
            $c = $this->curent();
            if ($c !== null && $this->eHtml($c, ['option'])) {
                $this->scoate(false);
            }
            $this->reconstruieste();
            $this->insereaza($tk);
            return;
        }
        if ($n === 'rb' || $n === 'rtc') {
            if ($this->inDomeniu(['ruby'])) {
                $this->genereazaImplicite();
            }
            $this->insereaza($tk);
            return;
        }
        if ($n === 'rp' || $n === 'rt') {
            if ($this->inDomeniu(['ruby'])) {
                $this->genereazaImplicite('rtc');
            }
            $this->insereaza($tk);
            return;
        }
        if ($n === 'math' || $n === 'svg') {
            $this->reconstruieste();
            $this->insereaza($tk, $n);
            if ($tk['auto']) {
                $this->scoate(false);
            }
            return;
        }
        if (in_array($n, ['caption', 'col', 'colgroup', 'frame', 'head', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr'], true)) {
            return;
        }
        $this->reconstruieste();
        $this->insereaza($tk);
    }

    private function inchidereInCorp(array $tk, string $n): void
    {
        if ($n === 'template') {
            $this->modInCap($tk);
            return;
        }
        if ($n === 'body') {
            if ($this->inDomeniu(['body'])) {
                $this->mod = 'after body';
            }
            return;
        }
        if ($n === 'html') {
            if ($this->inDomeniu(['body'])) {
                $this->mod = 'after body';
                $this->proceseaza($tk);
            }
            return;
        }
        if (in_array($n, self::BLOCURI_SFARSIT, true)) {
            if (!$this->inDomeniu([$n])) {
                return;
            }
            $this->genereazaImplicite();
            $this->scoatePanaLaNume([$n], true);
            return;
        }
        if ($n === 'form') {
            if (!$this->areSablon()) {
                $nod = $this->formular;
                $this->formular = null;
                if ($nod === null || !$this->elementInDomeniu($nod)) {
                    return;
                }
                $this->genereazaImplicite();
                if ($this->curent() === $nod) {
                    $this->scoate(true);
                } else {
                    // Scos din mijlocul stivei: elementele de deasupra lui rămân deschise, deci conținutul lui merge mai departe.
                    $this->marcheazaMutat($nod);
                    $this->scoateDinStiva($nod);
                }
                return;
            }
            if (!$this->inDomeniu(['form'])) {
                return;
            }
            $this->genereazaImplicite();
            $this->scoatePanaLaNume(['form'], true);
            return;
        }
        if ($n === 'p') {
            if (!$this->inDomeniu(['p'], 'buton')) {
                $this->insereazaImplicit('p');
            }
            $this->inchideP(true);
            return;
        }
        if ($n === 'li') {
            if (!$this->inDomeniu(['li'], 'lista')) {
                return;
            }
            $this->genereazaImplicite('li');
            $this->scoatePanaLaNume(['li'], true);
            return;
        }
        if ($n === 'dd' || $n === 'dt') {
            if (!$this->inDomeniu([$n])) {
                return;
            }
            $this->genereazaImplicite($n);
            $this->scoatePanaLaNume([$n], true);
            return;
        }
        if (in_array($n, self::TITLURI, true)) {
            if (!$this->inDomeniu(self::TITLURI)) {
                return;
            }
            $this->genereazaImplicite();
            $this->scoatePanaLaNume(self::TITLURI, true);
            return;
        }
        if (in_array($n, self::FORMATARE, true)) {
            $this->adoptie($n);
            return;
        }
        if (in_array($n, ['applet', 'marquee', 'object'], true)) {
            if (!$this->inDomeniu([$n])) {
                return;
            }
            $this->genereazaImplicite();
            $this->scoatePanaLaNume([$n], true);
            $this->curataFormatarea();
            return;
        }
        if ($n === 'br') {
            // `</br>` e un `<br>`.
            $this->reconstruieste();
            $this->insereazaImplicit('br');
            $this->scoate(false);
            $this->framesetOk = false;
            return;
        }
        $this->altaInchidere($n);
    }

    /** „Any other end tag”: cel mai de sus element HTML cu același nume, dacă nu e un element special mai sus de el. */
    private function altaInchidere(string $n): void
    {
        // Niciun element HTML cu acest nume pe stivă: nimic de închis, oricât de adâncă ar fi stiva (fără plimbarea ei întreagă).
        if (($this->peStiva['html:' . $n] ?? 0) === 0) {
            return;
        }
        for ($i = count($this->stiva) - 1; $i >= 0; $i--) {
            $this->lucru++;
            $id = $this->stiva[$i];
            if ($this->el[$id]['ns'] === 'html' && $this->el[$id]['nume'] === $n) {
                $this->genereazaImplicite($n);
                $this->scoatePanaLa($id, true);
                return;
            }
            if ($this->special($id)) {
                return;
            }
        }
    }

    // ───────────────────────────────────────────────────────────
    // Textul brut, tabelele, select-ul, șabloanele, frameset-ul
    // ───────────────────────────────────────────────────────────

    /** Elementul cu text brut sau RCDATA (și scriptul): tokenizerul trece în starea lui; modul „text” până la închidere. */
    private function textBrut(array $tk, string $stare): void
    {
        $this->insereaza($tk);
        $this->stareCeruta = $stare;
        $this->modOriginal = $this->mod;
        $this->mod = 'text';
    }

    private function modText(array $tk): void
    {
        if ($tk['tip'] === 'end') {
            $this->scoate(true);
            $this->mod = $this->modOriginal;
        }
    }

    private function modInTabel(array $tk): void
    {
        if ($tk['tip'] === 'text') {
            $c = $this->curent();
            if ($c !== null && $this->eHtml($c, ['table', 'tbody', 'template', 'tfoot', 'thead', 'tr'])) {
                // „In table text”: un text doar cu spații rămâne în tabel; altfel trece, întreg, în fața tabelului.
                $fara = str_replace("\0", '', $tk['c']);
                if ($fara !== '' && strspn($fara, self::SPATII) !== strlen($fara)) {
                    $this->cuFoster(fn () => $this->textInCorp($fara));
                }
                return;
            }
            $this->cuFoster(fn () => $this->modInCorp($tk));
            return;
        }
        $n = $tk['nume'];
        if ($tk['tip'] === 'start') {
            if ($n === 'caption') {
                $this->golesteInapoiLa(['table', 'template', 'html']);
                $this->fmt[] = null;
                $this->insereaza($tk);
                $this->mod = 'in caption';
                return;
            }
            if ($n === 'colgroup') {
                $this->golesteInapoiLa(['table', 'template', 'html']);
                $this->insereaza($tk);
                $this->mod = 'in column group';
                return;
            }
            if ($n === 'col') {
                $this->golesteInapoiLa(['table', 'template', 'html']);
                $this->insereazaImplicit('colgroup');
                $this->mod = 'in column group';
                $this->proceseaza($tk);
                return;
            }
            if (in_array($n, self::TABEL_SECTIUNI, true)) {
                $this->golesteInapoiLa(['table', 'template', 'html']);
                $this->insereaza($tk);
                $this->mod = 'in table body';
                return;
            }
            if (in_array($n, ['td', 'th', 'tr'], true)) {
                $this->golesteInapoiLa(['table', 'template', 'html']);
                $this->insereazaImplicit('tbody');
                $this->mod = 'in table body';
                $this->proceseaza($tk);
                return;
            }
            if ($n === 'table') {
                if (!$this->inDomeniu(['table'], 'tabel')) {
                    return;
                }
                $this->scoatePanaLaNume(['table'], false);
                $this->reseteazaModul();
                $this->proceseaza($tk);
                return;
            }
            if (in_array($n, ['style', 'script', 'template'], true)) {
                $this->modInCap($tk);
                return;
            }
            if ($n === 'input' && $this->ascuns($tk)) {
                $this->insereaza($tk);
                $this->scoate(false);
                return;
            }
            if ($n === 'form') {
                if ($this->areSablon() || $this->formular !== null) {
                    return;
                }
                $this->formular = $this->insereaza($tk);
                $this->scoate(false);
                return;
            }
        } else {
            if ($n === 'table') {
                if (!$this->inDomeniu(['table'], 'tabel')) {
                    return;
                }
                $this->scoatePanaLaNume(['table'], true);
                $this->reseteazaModul();
                return;
            }
            if (in_array($n, ['body', 'caption', 'col', 'colgroup', 'html', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr'], true)) {
                return;
            }
            if ($n === 'template') {
                $this->modInCap($tk);
                return;
            }
        }
        // Altceva: regulile corpului, cu plasarea în fața tabelului.
        $this->cuFoster(fn () => $this->modInCorp($tk));
    }

    private function modInLegenda(array $tk): void
    {
        $n = $tk['nume'] ?? '';
        if ($tk['tip'] === 'end' && $n === 'caption') {
            if (!$this->inDomeniu(['caption'], 'tabel')) {
                return;
            }
            $this->genereazaImplicite();
            $this->scoatePanaLaNume(['caption'], true);
            $this->curataFormatarea();
            $this->mod = 'in table';
            return;
        }
        if (($tk['tip'] === 'start' && in_array($n, ['caption', 'col', 'colgroup', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr'], true))
            || ($tk['tip'] === 'end' && $n === 'table')) {
            if (!$this->inDomeniu(['caption'], 'tabel')) {
                return;
            }
            $this->genereazaImplicite();
            $this->scoatePanaLaNume(['caption'], false);
            $this->curataFormatarea();
            $this->mod = 'in table';
            $this->proceseaza($tk);
            return;
        }
        if ($tk['tip'] === 'end' && in_array($n, ['body', 'col', 'colgroup', 'html', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr'], true)) {
            return;
        }
        $this->modInCorp($tk);
    }

    private function modInGrupColoane(array $tk): void
    {
        if ($tk['tip'] === 'text') {
            [, $rest] = self::spatiiInainte($tk['c']);
            if ($rest === '') {
                return;
            }
            $tk['c'] = $rest;
        } else {
            $n = $tk['nume'];
            if ($tk['tip'] === 'start' && $n === 'html') {
                $this->modInCorp($tk);
                return;
            }
            if ($tk['tip'] === 'start' && $n === 'col') {
                $this->insereaza($tk);
                $this->scoate(false);
                return;
            }
            if ($tk['tip'] === 'end' && $n === 'colgroup') {
                $c = $this->curent();
                if ($c !== null && $this->eHtml($c, ['colgroup'])) {
                    $this->scoate(true);
                    $this->mod = 'in table';
                }
                return;
            }
            if ($tk['tip'] === 'end' && $n === 'col') {
                return;
            }
            if ($n === 'template') {
                $this->modInCap($tk);
                return;
            }
        }
        $c = $this->curent();
        if ($c === null || !$this->eHtml($c, ['colgroup'])) {
            return;
        }
        $this->scoate(false);
        $this->mod = 'in table';
        $this->proceseaza($tk);
    }

    private function modInCorpTabel(array $tk): void
    {
        $n = $tk['nume'] ?? '';
        if ($tk['tip'] === 'start' && $n === 'tr') {
            $this->golesteInapoiLa(['tbody', 'tfoot', 'thead', 'template', 'html']);
            $this->insereaza($tk);
            $this->mod = 'in row';
            return;
        }
        if ($tk['tip'] === 'start' && ($n === 'th' || $n === 'td')) {
            $this->golesteInapoiLa(['tbody', 'tfoot', 'thead', 'template', 'html']);
            $this->insereazaImplicit('tr');
            $this->mod = 'in row';
            $this->proceseaza($tk);
            return;
        }
        if ($tk['tip'] === 'end' && in_array($n, self::TABEL_SECTIUNI, true)) {
            if (!$this->inDomeniu([$n], 'tabel')) {
                return;
            }
            $this->golesteInapoiLa(['tbody', 'tfoot', 'thead', 'template', 'html']);
            $this->scoate(true);
            $this->mod = 'in table';
            return;
        }
        if (($tk['tip'] === 'start' && in_array($n, ['caption', 'col', 'colgroup', 'tbody', 'tfoot', 'thead'], true))
            || ($tk['tip'] === 'end' && $n === 'table')) {
            if (!$this->inDomeniu(self::TABEL_SECTIUNI, 'tabel')) {
                return;
            }
            $this->golesteInapoiLa(['tbody', 'tfoot', 'thead', 'template', 'html']);
            $this->scoate(false);
            $this->mod = 'in table';
            $this->proceseaza($tk);
            return;
        }
        if ($tk['tip'] === 'end' && in_array($n, ['body', 'caption', 'col', 'colgroup', 'html', 'td', 'th', 'tr'], true)) {
            return;
        }
        $this->modInTabel($tk);
    }

    private function modInRand(array $tk): void
    {
        $n = $tk['nume'] ?? '';
        if ($tk['tip'] === 'start' && ($n === 'th' || $n === 'td')) {
            $this->golesteInapoiLa(['tr', 'template', 'html']);
            $this->insereaza($tk);
            $this->mod = 'in cell';
            $this->fmt[] = null;
            return;
        }
        if ($tk['tip'] === 'end' && $n === 'tr') {
            if (!$this->inDomeniu(['tr'], 'tabel')) {
                return;
            }
            $this->golesteInapoiLa(['tr', 'template', 'html']);
            $this->scoate(true);
            $this->mod = 'in table body';
            return;
        }
        if (($tk['tip'] === 'start' && in_array($n, ['caption', 'col', 'colgroup', 'tbody', 'tfoot', 'thead', 'tr'], true))
            || ($tk['tip'] === 'end' && $n === 'table')) {
            if (!$this->inDomeniu(['tr'], 'tabel')) {
                return;
            }
            $this->golesteInapoiLa(['tr', 'template', 'html']);
            $this->scoate(false);
            $this->mod = 'in table body';
            $this->proceseaza($tk);
            return;
        }
        if ($tk['tip'] === 'end' && in_array($n, self::TABEL_SECTIUNI, true)) {
            if (!$this->inDomeniu([$n], 'tabel') || !$this->inDomeniu(['tr'], 'tabel')) {
                return;
            }
            $this->golesteInapoiLa(['tr', 'template', 'html']);
            $this->scoate(false);
            $this->mod = 'in table body';
            $this->proceseaza($tk);
            return;
        }
        if ($tk['tip'] === 'end' && in_array($n, ['body', 'caption', 'col', 'colgroup', 'html', 'td', 'th'], true)) {
            return;
        }
        $this->modInTabel($tk);
    }

    private function modInCelula(array $tk): void
    {
        $n = $tk['nume'] ?? '';
        if ($tk['tip'] === 'end' && ($n === 'td' || $n === 'th')) {
            if (!$this->inDomeniu([$n], 'tabel')) {
                return;
            }
            $this->genereazaImplicite();
            $this->scoatePanaLaNume([$n], true);
            $this->curataFormatarea();
            $this->mod = 'in row';
            return;
        }
        if ($tk['tip'] === 'start' && in_array($n, ['caption', 'col', 'colgroup', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr'], true)) {
            if (!$this->inDomeniu(['td', 'th'], 'tabel')) {
                return;
            }
            $this->inchideCelula();
            $this->proceseaza($tk);
            return;
        }
        if ($tk['tip'] === 'end' && in_array($n, ['body', 'caption', 'col', 'colgroup', 'html'], true)) {
            return;
        }
        if ($tk['tip'] === 'end' && in_array($n, ['table', 'tbody', 'tfoot', 'thead', 'tr'], true)) {
            if (!$this->inDomeniu([$n], 'tabel')) {
                return;
            }
            $this->inchideCelula();
            $this->proceseaza($tk);
            return;
        }
        $this->modInCorp($tk);
    }

    private function inchideCelula(): void
    {
        $this->genereazaImplicite();
        $this->scoatePanaLaNume(['td', 'th'], false);
        $this->curataFormatarea();
        $this->mod = 'in row';
    }

    private function modInSelect(array $tk): void
    {
        if ($tk['tip'] === 'text') {
            return;
        }
        $n = $tk['nume'];
        $c = $this->curent();
        if ($tk['tip'] === 'start') {
            if ($n === 'html') {
                $this->modInCorp($tk);
                return;
            }
            if ($n === 'option') {
                if ($c !== null && $this->eHtml($c, ['option'])) {
                    $this->scoate(false);
                }
                $this->insereaza($tk);
                return;
            }
            if ($n === 'optgroup') {
                if ($c !== null && $this->eHtml($c, ['option'])) {
                    $this->scoate(false);
                }
                $c = $this->curent();
                if ($c !== null && $this->eHtml($c, ['optgroup'])) {
                    $this->scoate(false);
                }
                $this->insereaza($tk);
                return;
            }
            if ($n === 'select') {
                if ($this->inDomeniu(['select'], 'select')) {
                    $this->scoatePanaLaNume(['select'], false);
                    $this->reseteazaModul();
                }
                return;
            }
            if (in_array($n, ['input', 'keygen', 'textarea'], true)) {
                if (!$this->inDomeniu(['select'], 'select')) {
                    return;
                }
                // F4: parserele vechi (și acest model) închid aici `<select>` și pun elementul lângă el; Chromium de azi
                // („customizable select”) îl ține ÎNĂUNTRU. Diferă unde ajunge, iar la select-uri imbricate asta ascunde un
                // `<select onchange>` viu de mai târziu. Documentul e nesigur, ca restul conținutului non-option din select.
                $this->nesigur('<' . $n . '> în <select>, la octetul ' . $tk['start'] . ': parserele vechi închid select-ul, '
                    . 'Chromium îl ține înăuntru (poate ascunde un element viu de mai târziu)');
                $this->scoatePanaLaNume(['select'], false);
                $this->reseteazaModul();
                $this->proceseaza($tk);
                return;
            }
            if ($n === 'script' || $n === 'template') {
                $this->modInCap($tk);
                return;
            }
            // Parserele vechi (și parse5) ignoră aici orice alt tag; cele noi („customizable select”) îl construiesc.
            $this->nesigur('<' . $n . '> în <select>, la octetul ' . $tk['start'] . ': parserele vechi îl ignoră, cele noi îl construiesc');
            return;
        }
        if ($n === 'optgroup') {
            if ($c !== null && $this->eHtml($c, ['option']) && count($this->stiva) > 1
                && $this->eHtml($this->stiva[count($this->stiva) - 2], ['optgroup'])) {
                $this->scoate(false);
            }
            $c = $this->curent();
            if ($c !== null && $this->eHtml($c, ['optgroup'])) {
                $this->scoate(true);
            }
            return;
        }
        if ($n === 'option') {
            if ($c !== null && $this->eHtml($c, ['option'])) {
                $this->scoate(true);
            }
            return;
        }
        if ($n === 'select') {
            if (!$this->inDomeniu(['select'], 'select')) {
                return;
            }
            $this->scoatePanaLaNume(['select'], true);
            $this->reseteazaModul();
            return;
        }
        if ($n === 'template') {
            $this->modInCap($tk);
            return;
        }
        $this->nesigur('</' . $n . '> în <select>, la octetul ' . $tk['start'] . ': parserele vechi îl ignoră, cele noi nu');
    }

    private function modInSelectInTabel(array $tk): void
    {
        $n = $tk['nume'] ?? '';
        $tabel = ['caption', 'table', 'tbody', 'tfoot', 'thead', 'tr', 'td', 'th'];
        if ($tk['tip'] === 'start' && in_array($n, $tabel, true)) {
            $this->scoatePanaLaNume(['select'], false);
            $this->reseteazaModul();
            $this->proceseaza($tk);
            return;
        }
        if ($tk['tip'] === 'end' && in_array($n, $tabel, true)) {
            if (!$this->inDomeniu([$n], 'tabel')) {
                return;
            }
            $this->scoatePanaLaNume(['select'], false);
            $this->reseteazaModul();
            $this->proceseaza($tk);
            return;
        }
        $this->modInSelect($tk);
    }

    private function modInSablon(array $tk): void
    {
        if ($tk['tip'] === 'text') {
            $this->modInCorp($tk);
            return;
        }
        $n = $tk['nume'];
        if ($tk['tip'] === 'end') {
            if ($n === 'template') {
                $this->modInCap($tk);
            }
            return;
        }
        if (in_array($n, self::IN_CAP, true)) {
            $this->modInCap($tk);
            return;
        }
        $nou = match (true) {
            in_array($n, ['caption', 'colgroup', 'tbody', 'tfoot', 'thead'], true) => 'in table',
            $n === 'col' => 'in column group',
            $n === 'tr' => 'in table body',
            $n === 'td' || $n === 'th' => 'in row',
            default => 'in body',
        };
        array_pop($this->moduriSablon);
        $this->moduriSablon[] = $nou;
        $this->mod = $nou;
        $this->proceseaza($tk);
    }

    private function sfarsitSablon(): void
    {
        if (!$this->areSablon()) {
            return;
        }
        $this->genereazaImplicite(null, true);
        $this->scoatePanaLaNume(['template'], true);
        $this->curataFormatarea();
        array_pop($this->moduriSablon);
        $this->reseteazaModul();
    }

    private function modDupaCorp(array $tk): void
    {
        if ($tk['tip'] === 'text') {
            [$spatii, $rest] = self::spatiiInainte($tk['c']);
            if ($spatii !== '') {
                $this->modInCorp(['tip' => 'text', 'c' => $spatii, 'k' => $tk['k']]);
            }
            if ($rest === '') {
                return;
            }
            $tk['c'] = $rest;
        } elseif ($tk['tip'] === 'start' && $tk['nume'] === 'html') {
            $this->modInCorp($tk);
            return;
        } elseif ($tk['tip'] === 'end' && $tk['nume'] === 'html') {
            $this->mod = 'after after body';
            return;
        }
        $this->mod = 'in body';
        $this->proceseaza($tk);
    }

    private function modInFrameset(array $tk): void
    {
        if ($tk['tip'] === 'text') {
            return;
        }
        $n = $tk['nume'];
        if ($tk['tip'] === 'start') {
            if ($n === 'html') {
                $this->modInCorp($tk);
            } elseif ($n === 'frameset') {
                $this->insereaza($tk);
            } elseif ($n === 'frame') {
                $this->insereaza($tk);
                $this->scoate(false);
            } elseif ($n === 'noframes') {
                $this->modInCap($tk);
            }
            return;
        }
        if ($n === 'frameset' && count($this->stiva) > 1) {
            $this->scoate(true);
            $c = $this->curent();
            if ($c === null || !$this->eHtml($c, ['frameset'])) {
                $this->mod = 'after frameset';
            }
        }
    }

    private function modDupaFrameset(array $tk): void
    {
        if ($tk['tip'] === 'start' && $tk['nume'] === 'html') {
            $this->modInCorp($tk);
        } elseif ($tk['tip'] === 'end' && $tk['nume'] === 'html') {
            $this->mod = 'after after frameset';
        } elseif ($tk['tip'] === 'start' && $tk['nume'] === 'noframes') {
            $this->modInCap($tk);
        }
    }

    private function modDupaDupaCorp(array $tk): void
    {
        if ($tk['tip'] === 'text') {
            [$spatii, $rest] = self::spatiiInainte($tk['c']);
            if ($spatii !== '') {
                $this->modInCorp(['tip' => 'text', 'c' => $spatii, 'k' => $tk['k']]);
            }
            if ($rest === '') {
                return;
            }
            $tk['c'] = $rest;
        } elseif ($tk['tip'] === 'start' && $tk['nume'] === 'html') {
            $this->modInCorp($tk);
            return;
        }
        $this->mod = 'in body';
        $this->proceseaza($tk);
    }

    private function modDupaDupaFrameset(array $tk): void
    {
        if ($tk['tip'] === 'start' && $tk['nume'] === 'html') {
            $this->modInCorp($tk);
        } elseif ($tk['tip'] === 'start' && $tk['nume'] === 'noframes') {
            $this->modInCap($tk);
        }
    }

    /** „Reset the insertion mode appropriately”, după stivă. */
    private function reseteazaModul(): void
    {
        for ($i = count($this->stiva) - 1; $i >= 0; $i--) {
            $this->lucru++;
            $ultimul = $i === 0;
            $e = $this->el[$this->stiva[$i]];
            if ($e['ns'] !== 'html') {
                if ($ultimul) {
                    $this->mod = 'in body';
                    return;
                }
                continue;
            }
            $n = $e['nume'];
            if ($n === 'select') {
                if (!$ultimul) {
                    for ($j = $i - 1; $j > 0; $j--) {
                        $a = $this->el[$this->stiva[$j]];
                        if ($a['ns'] === 'html' && $a['nume'] === 'template') {
                            break;
                        }
                        if ($a['ns'] === 'html' && $a['nume'] === 'table') {
                            $this->mod = 'in select in table';
                            return;
                        }
                    }
                }
                $this->mod = 'in select';
                return;
            }
            $mod = match (true) {
                ($n === 'td' || $n === 'th') && !$ultimul => 'in cell',
                $n === 'tr' => 'in row',
                in_array($n, self::TABEL_SECTIUNI, true) => 'in table body',
                $n === 'caption' => 'in caption',
                $n === 'colgroup' => 'in column group',
                $n === 'table' => 'in table',
                $n === 'template' => (string) end($this->moduriSablon),
                $n === 'head' && !$ultimul => 'in head',
                $n === 'body' => 'in body',
                $n === 'frameset' => 'in frameset',
                $n === 'html' => $this->cap === null ? 'before head' : 'after head',
                default => '',
            };
            if ($mod !== '') {
                $this->mod = $mod;
                return;
            }
            if ($ultimul) {
                $this->mod = 'in body';
                return;
            }
        }
        $this->mod = 'in body';
    }

    // ───────────────────────────────────────────────────────────
    // Conținutul străin (SVG, MathML)
    // ───────────────────────────────────────────────────────────

    private function strainToken(array $tk): void
    {
        if ($tk['tip'] === 'text') {
            $fara = str_replace("\0", '', $tk['c']);
            if ($fara !== '' && strspn($fara, self::SPATII) !== strlen($fara)) {
                $this->framesetOk = false;
            }
            return;
        }
        $n = $tk['nume'];
        if ($tk['tip'] === 'start') {
            if (in_array($n, self::IESIRI_STRAINE, true)
                || ($n === 'font' && (isset($tk['atr']['color']) || isset($tk['atr']['face']) || isset($tk['atr']['size'])))) {
                $this->iesiDinStrain();
                $this->dupaMod($tk);
                return;
            }
            $c = (int) $this->curent();
            $this->insereaza($tk, $this->el[$c]['ns']);
            if ($tk['auto']) {
                $this->scoate(false);
            }
            return;
        }
        // Abaterea Blink (SPEC-R3-01): o închidere al cărei nume e un nume SVG camelCase (clippath, lineargradient, textpath,
        // foreignobject…) și care NU închide chiar nodul curent (numele lui ≠ numele închiderii). În conținut străin Blink
        // ajustează majusculele numelui după nodul curent (când e SVG) și apoi potrivește cu litere exacte, deci nu dă peste un
        // element MathML sau HTML cu numele mic (rămâne în SVG), iar WHATWG (aici) potrivește cu litere mici și iese. Un
        // `<img onerror>` de după ar fi text brut aici, dar element viu în Blink: documentul e nesigur. Dacă închiderea închide
        // chiar nodul curent (același nume), ambele fac la fel (Blink ajustează și numele nodului, deci se potrivesc). Cuprinde
        // și vechiul caz îngust (un element HTML cu acel nume pe stivă): acolo nodul curent e străin, cu alt nume, deci intră aici.
        $curentNume = ($c = $this->curent()) !== null ? $this->el[$c]['nume'] : '';
        if (isset(self::set('svgCamel', self::SVG_CAMEL)[$n]) && $curentNume !== $n) {
            $this->nesigur('</' . $n . '> în conținut străin, la octetul ' . ($tk['start'] ?? 0)
                . ': nume SVG camelCase care nu închide chiar nodul curent; Blink ajustează majusculele și potrivește altfel decât WHATWG, deci rămâne în SVG');
        }
        if ($n === 'br' || $n === 'p') {
            $this->iesiDinStrain();
            $this->dupaMod($tk);
            return;
        }
        // Orice altă închidere: cel mai de sus element străin cu același nume (prin elementele străine de deasupra lui); la
        // primul element HTML, regulile modului de inserare. Doar dacă există un element străin cu acest nume pe stivă
        // (altfel sărim plimbarea stivei, oricât de adâncă — timpul rămâne liniar pe documente străine adânci).
        if ((($this->peStiva['svg:' . $n] ?? 0) + ($this->peStiva['math:' . $n] ?? 0)) > 0) {
            for ($i = count($this->stiva) - 1; $i > 0; $i--) {
                $this->lucru++;
                $id = $this->stiva[$i];
                if ($this->el[$id]['nume'] === $n) {
                    $this->scoatePanaLa($id, true);
                    return;
                }
                if ($this->el[$this->stiva[$i - 1]]['ns'] === 'html') {
                    break;
                }
            }
        }
        $this->dupaMod($tk);
    }

    /** Scoate elementele străine de pe stivă până la un punct de integrare sau la un element HTML. */
    private function iesiDinStrain(): void
    {
        while (($c = $this->curent()) !== null && $this->el[$c]['ns'] !== 'html' && !$this->integrareHtml($c)
            && !($this->el[$c]['ns'] === 'math' && in_array($this->el[$c]['nume'], self::TEXT_MATH, true))) {
            $this->scoate(false);
        }
    }

    // ───────────────────────────────────────────────────────────
    // Stiva
    // ───────────────────────────────────────────────────────────

    private function curent(): ?int
    {
        return $this->stiva === [] ? null : $this->stiva[count($this->stiva) - 1];
    }

    private function numeLa(int $i): string
    {
        return isset($this->stiva[$i]) && $this->el[$this->stiva[$i]]['ns'] === 'html' ? $this->el[$this->stiva[$i]]['nume'] : '';
    }

    /** @param list<string> $nume */
    private function eHtml(int $id, array $nume): bool
    {
        return $this->el[$id]['ns'] === 'html' && in_array($this->el[$id]['nume'], $nume, true);
    }

    private function special(int $id): bool
    {
        $e = $this->el[$id];
        return match ($e['ns']) {
            'html' => isset(self::set('specHtml', self::SPECIALE_HTML)[$e['nume']]),
            'math' => isset(self::set('specMath', self::SPECIALE_MATH)[$e['nume']]),
            default => isset(self::set('specSvg', self::SPECIALE_SVG)[$e['nume']]),
        };
    }

    private function limita(int $id, string $tip): bool
    {
        $e = $this->el[$id];
        if ($tip === 'select') {
            return !($e['ns'] === 'html' && in_array($e['nume'], ['optgroup', 'option'], true));
        }
        if ($tip === 'tabel') {
            return $e['ns'] === 'html' && in_array($e['nume'], ['html', 'table', 'template'], true);
        }
        $limita = match ($e['ns']) {
            'html' => isset(self::set('limiteHtml', self::LIMITE_HTML)[$e['nume']]),
            'math' => isset(self::set('specMath', self::SPECIALE_MATH)[$e['nume']]),
            default => isset(self::set('specSvg', self::SPECIALE_SVG)[$e['nume']]),
        };
        if ($limita) {
            return true;
        }
        return $e['ns'] === 'html' && (($tip === 'lista' && in_array($e['nume'], ['ol', 'ul'], true)) || ($tip === 'buton' && $e['nume'] === 'button'));
    }

    /** „Has an element in scope”: un element HTML cu unul dintre nume, mai sus decât orice limită a domeniului. @param list<string> $nume */
    private function inDomeniu(array $nume, string $tip = 'implicit'): bool
    {
        $exista = false;
        foreach ($nume as $x) {
            if (($this->peStiva['html:' . $x] ?? 0) > 0) {
                $exista = true;
                break;
            }
        }
        if (!$exista) {
            return false;
        }
        // Bucla e desfășurată cu testele inline (fără apeluri de metodă pe fiecare element al stivei): pe un document cu o stivă
        // adâncă și multe închideri fără pereche, altfel costul pe token ar fi mare (timpul rămâne liniar, dar cu o constantă mică).
        $tinte = array_fill_keys($nume, true);
        $limiteHtml = self::set('limiteHtml', self::LIMITE_HTML);
        $specMath = self::set('specMath', self::SPECIALE_MATH);
        $specSvg = self::set('specSvg', self::SPECIALE_SVG);
        for ($i = count($this->stiva) - 1; $i >= 0; $i--) {
            $this->lucru++;
            $e = $this->el[$this->stiva[$i]];
            $ns = $e['ns'];
            $nm = $e['nume'];
            if ($ns === 'html' && isset($tinte[$nm])) {
                return true;
            }
            if ($tip === 'select') {
                if (!($ns === 'html' && ($nm === 'optgroup' || $nm === 'option'))) {
                    return false;
                }
                continue;
            }
            if ($tip === 'tabel') {
                if ($ns === 'html' && ($nm === 'html' || $nm === 'table' || $nm === 'template')) {
                    return false;
                }
                continue;
            }
            if ($ns === 'html' ? isset($limiteHtml[$nm]) : ($ns === 'math' ? isset($specMath[$nm]) : isset($specSvg[$nm]))) {
                return false;
            }
            if ($ns === 'html' && (($tip === 'lista' && ($nm === 'ol' || $nm === 'ul')) || ($tip === 'buton' && $nm === 'button'))) {
                return false;
            }
        }
        return false;
    }

    private function elementInDomeniu(int $tinta): bool
    {
        for ($i = count($this->stiva) - 1; $i >= 0; $i--) {
            $this->lucru++;
            $id = $this->stiva[$i];
            if ($id === $tinta) {
                return true;
            }
            if ($this->limita($id, 'implicit')) {
                return false;
            }
        }
        return false;
    }

    private function areSablon(): bool
    {
        return ($this->peStiva['html:template'] ?? 0) > 0;
    }

    private function indexInStiva(int $id): ?int
    {
        $this->lucru++;
        $i = array_search($id, $this->stiva, true);
        return $i === false ? null : (int) $i;
    }

    private function numara(int $id, int $cu): void
    {
        $cheie = $this->el[$id]['ns'] . ':' . $this->el[$id]['nume'];
        $this->peStiva[$cheie] = ($this->peStiva[$cheie] ?? 0) + $cu;
    }

    /** Un element nou pe stivă (din tokenul dat), în spațiul de nume dat; plasat în fața tabelului, dacă e cazul. */
    private function insereaza(array $tk, string $ns = 'html'): int
    {
        if ($this->foster && $this->tintaTabel()) {
            $this->marcheazaFoster();
        }
        $id = $this->element($ns, $tk['nume'] === 'image' && $ns === 'html' ? 'img' : $tk['nume'], (int) $tk['k'], $tk['atr']);
        if (!isset($this->spatiu[$tk['k']])) {
            $this->spatiu[$tk['k']] = $ns;
        }
        $this->stiva[] = $id;
        $this->numara($id, 1);
        $this->efecte++;
        return $id;
    }

    /** Un element pe care parserul îl face singur (html, head, body, tbody, tr, colgroup, un `<p>` gol, `</br>`). */
    private function insereazaImplicit(string $nume): int
    {
        if ($this->foster && $this->tintaTabel()) {
            $this->marcheazaFoster();
        }
        $id = $this->element('html', $nume, -1, []);
        $this->stiva[] = $id;
        $this->numara($id, 1);
        $this->efecte++;
        return $id;
    }

    /** @param array<string, string> $atr */
    private function element(string $ns, string $nume, int $tok, array $atr): int
    {
        $this->lucru++;
        $id = $this->urmatorul++;
        $this->el[$id] = ['ns' => $ns, 'nume' => $nume, 'tok' => $tok, 'atr' => $atr];
        return $id;
    }

    /** Scoate elementul curent; `$tinta`: el e elementul pe care îl închide tagul de închidere procesat acum. */
    private function scoate(bool $tinta): void
    {
        $this->lucru++;
        $id = array_pop($this->stiva);
        if ($id === null) {
            return;
        }
        $this->numara($id, -1);
        $this->efecte++;
        $this->inchideElementul($id, $tinta);
    }

    private function inchideElementul(int $id, bool $tinta): void
    {
        $tok = $this->el[$id]['tok'];
        if ($tok < 0 || isset($this->inchis[$tok])) {
            return;
        }
        $this->inchis[$tok] = $this->tok;
        $this->propriu[$tok] = $tinta && $this->sfarsit !== null && $this->sfarsit === $this->el[$id]['nume'] && $tok !== $this->tok;
    }

    /** Scoate elementele de pe stivă până la (și cu) elementul dat. */
    private function scoatePanaLa(int $tinta, bool $propriu): void
    {
        while (($c = $this->curent()) !== null) {
            $this->scoate($propriu && $c === $tinta);
            if ($c === $tinta) {
                return;
            }
        }
    }

    /** Scoate până la cel mai de sus element HTML cu unul dintre nume (inclusiv). @param list<string> $nume */
    private function scoatePanaLaNume(array $nume, bool $propriu): void
    {
        for ($i = count($this->stiva) - 1; $i >= 0; $i--) {
            if ($this->eHtml($this->stiva[$i], $nume)) {
                $this->scoatePanaLa($this->stiva[$i], $propriu);
                return;
            }
        }
    }

    /** Scoate un element de oriunde din stivă (nu neapărat cel curent). */
    private function scoateDinStiva(int $id): void
    {
        $i = $this->indexInStiva($id);
        if ($i === null) {
            return;
        }
        array_splice($this->stiva, $i, 1);
        $this->numara($id, -1);
        $this->efecte++;
        $this->inchideElementul($id, false);
    }

    /** @param list<string> $nume */
    private function golesteInapoiLa(array $nume): void
    {
        while (($c = $this->curent()) !== null && !$this->eHtml($c, $nume)) {
            $this->scoate(false);
        }
    }

    private function genereazaImplicite(?string $afara = null, bool $toate = false): void
    {
        $lista = $toate ? self::IMPLICITE_TOATE : self::IMPLICITE;
        while (($c = $this->curent()) !== null && $this->el[$c]['ns'] === 'html' && in_array($this->el[$c]['nume'], $lista, true)
            && $this->el[$c]['nume'] !== $afara) {
            $this->scoate(false);
        }
    }

    private function inchidePDacaE(): void
    {
        if ($this->inDomeniu(['p'], 'buton')) {
            $this->inchideP(false);
        }
    }

    private function inchideP(bool $propriu): void
    {
        $this->genereazaImplicite('p');
        $this->scoatePanaLaNume(['p'], $propriu);
    }

    // ───────────────────────────────────────────────────────────
    // Formatarea: lista, reconstruirea, adopția
    // ───────────────────────────────────────────────────────────

    /** Pune un element în lista formatării, cu regula „Noah's Ark” (cel mult trei la fel după ultimul marker). */
    private function puneInFormatare(int $id): void
    {
        $e = $this->el[$id];
        $la = [];
        for ($i = count($this->fmt) - 1; $i >= 0 && $this->fmt[$i] !== null; $i--) {
            $this->lucru++;
            $a = $this->el[$this->fmt[$i]];
            if ($a['nume'] === $e['nume'] && $a['ns'] === $e['ns'] && $a['atr'] == $e['atr']) {
                $la[] = $i;
            }
        }
        if (count($la) >= 3) {
            array_splice($this->fmt, (int) end($la), 1);
        }
        $this->fmt[] = $id;
    }

    private function ultimaFormatare(string $nume): ?int
    {
        for ($i = count($this->fmt) - 1; $i >= 0 && $this->fmt[$i] !== null; $i--) {
            $this->lucru++;
            if ($this->el[$this->fmt[$i]]['nume'] === $nume) {
                return $this->fmt[$i];
            }
        }
        return null;
    }

    private function scoateDinFormatare(int $id): void
    {
        $i = array_search($id, $this->fmt, true);
        if ($i !== false) {
            array_splice($this->fmt, (int) $i, 1);
        }
    }

    private function curataFormatarea(): void
    {
        while ($this->fmt !== []) {
            if (array_pop($this->fmt) === null) {
                return;
            }
        }
    }

    /** „Reconstruct the active formatting elements”: formatarea închisă implicit se deschide iar (copii fără token). */
    private function reconstruieste(): void
    {
        $n = count($this->fmt);
        if ($n === 0) {
            return;
        }
        $ultim = $this->fmt[$n - 1];
        if ($ultim === null || $this->indexInStiva($ultim) !== null) {
            return;
        }
        $i = $n - 1;
        while ($i > 0) {
            $this->lucru++;
            $anterior = $this->fmt[$i - 1];
            if ($anterior === null || $this->indexInStiva($anterior) !== null) {
                break;
            }
            $i--;
        }
        for (; $i < $n; $i++) {
            $vechi = $this->el[(int) $this->fmt[$i]];
            if ($this->foster && $this->tintaTabel()) {
                $this->marcheazaFoster();
            }
            $id = $this->element($vechi['ns'], $vechi['nume'], -1, $vechi['atr']);
            $this->stiva[] = $id;
            $this->numara($id, 1);
            $this->efecte++;
            $this->fmt[$i] = $id;
        }
    }

    /** Algoritmul de adopție (WHATWG, „adoption agency algorithm”), pe stivă și pe lista formatării, fără DOM. */
    private function adoptie(string $subiect): void
    {
        $c = $this->curent();
        if ($c !== null && $this->eHtml($c, [$subiect]) && !in_array($c, $this->fmt, true)) {
            $this->scoate(true);
            return;
        }
        for ($exterior = 0; $exterior < 8; $exterior++) {
            $formatare = $this->ultimaFormatare($subiect);
            if ($formatare === null) {
                $this->altaInchidere($subiect);
                return;
            }
            $iStiva = $this->indexInStiva($formatare);
            if ($iStiva === null) {
                $this->scoateDinFormatare($formatare);
                return;
            }
            if (!$this->elementInDomeniu($formatare)) {
                return;
            }
            $bloc = null;
            for ($i = $iStiva + 1; $i < count($this->stiva); $i++) {
                if ($this->special($this->stiva[$i])) {
                    $bloc = $this->stiva[$i];
                    break;
                }
            }
            if ($bloc === null) {
                while (($x = $this->curent()) !== null) {
                    $this->scoate($x === $formatare);
                    if ($x === $formatare) {
                        break;
                    }
                }
                $this->scoateDinFormatare($formatare);
                return;
            }
            $stramos = $this->stiva[$iStiva - 1] ?? null;
            $semn = (int) array_search($formatare, $this->fmt, true);
            $this->marcheazaMutat($formatare);
            $this->marcheazaMutat($bloc);
            $nod = $bloc;
            $ultimNod = $bloc;
            $iNod = (int) $this->indexInStiva($bloc);
            for ($interior = 1; ; $interior++) {
                $this->lucru++;
                $iNod--;
                $nod = $this->stiva[$iNod];
                if ($nod === $formatare) {
                    break;
                }
                $iFmt = array_search($nod, $this->fmt, true);
                if ($interior > 3 && $iFmt !== false) {
                    array_splice($this->fmt, (int) $iFmt, 1);
                    if ((int) $iFmt < $semn) {
                        $semn--;
                    }
                    $iFmt = false;
                }
                if ($iFmt === false) {
                    $this->marcheazaMutat($nod);
                    array_splice($this->stiva, $iNod, 1);
                    $this->numara($nod, -1);
                    $this->inchideElementul($nod, false);
                    continue;
                }
                $vechi = $this->el[$nod];
                $this->marcheazaMutat($nod);
                $nou = $this->element($vechi['ns'], $vechi['nume'], -1, $vechi['atr']);
                $this->fmt[(int) $iFmt] = $nou;
                $this->stiva[$iNod] = $nou;
                $this->inchideElementul($nod, false);
                if ($ultimNod === $bloc) {
                    $semn = (int) $iFmt + 1;
                }
                $nod = $nou;
                $ultimNod = $nod;
            }
            if ($stramos !== null && $this->foster && $this->eHtml($stramos, self::TABEL_TINTE)) {
                $this->marcheazaFoster();
            }
            $vechi = $this->el[$formatare];
            $nou = $this->element($vechi['ns'], $vechi['nume'], -1, $vechi['atr']);
            $iVechi = (int) array_search($formatare, $this->fmt, true);
            array_splice($this->fmt, $iVechi, 1);
            if ($iVechi < $semn) {
                $semn--;
            }
            array_splice($this->fmt, max(0, min($semn, count($this->fmt))), 0, [$nou]);
            $iF = (int) $this->indexInStiva($formatare);
            array_splice($this->stiva, $iF, 1);
            $this->numara($formatare, -1);
            $this->inchideElementul($formatare, false);
            $iB = (int) $this->indexInStiva($bloc);
            array_splice($this->stiva, $iB + 1, 0, [$nou]);
            $this->numara($nou, 1);
            $this->efecte++;
        }
    }

    // ───────────────────────────────────────────────────────────
    // Plasarea în fața tabelului
    // ───────────────────────────────────────────────────────────

    private function cuFoster(callable $f): void
    {
        $inainte = $this->foster;
        $this->foster = true;
        try {
            $f();
        } finally {
            $this->foster = $inainte;
        }
    }

    /** Nodul curent e un tabel sau o parte a lui (aici un nod nou ar ajunge în fața tabelului)? */
    private function tintaTabel(): bool
    {
        $c = $this->curent();
        return $c !== null && $this->eHtml($c, self::TABEL_TINTE);
    }

    /** Un nod pus în fața ultimului tabel: tabelul și ce e deschis în el nu-și mai conțin octeții. */
    private function marcheazaFoster(): void
    {
        for ($i = count($this->stiva) - 1; $i >= 0; $i--) {
            $this->lucru++;
            $id = $this->stiva[$i];
            if ($this->eHtml($id, ['template'])) {
                return; // un șablon mai jos decât ultimul tabel: nodul intră în conținutul șablonului
            }
            $this->marcheazaMutat($id);
            if ($this->eHtml($id, ['table'])) {
                return;
            }
        }
    }

    private function marcheazaMutat(int $id): void
    {
        $tok = $this->el[$id]['tok'];
        if ($tok >= 0) {
            $this->mutat[$tok] = true;
        }
    }

    // ───────────────────────────────────────────────────────────
    // Ajutoare
    // ───────────────────────────────────────────────────────────

    private function nesigur(string $motiv): void
    {
        if (count($this->nesigure) < 50 && !in_array($motiv, $this->nesigure, true)) {
            $this->nesigure[] = $motiv;
        }
    }

    private function verificaAdancimea(int $octet): void
    {
        if (count($this->stiva) > self::ADANCIME_MAXIMA) {
            $this->nesigur('peste ' . self::ADANCIME_MAXIMA . ' elemente deschise (pe la octetul ' . $octet . '): documentul nu se mai analizează');
            $this->oprit = true;
        }
    }

    /**
     * Construcții ale unui tag de deschidere pe care politica HTML-ului capturat le refuză fail-closed, fiindcă duc la conținut
     * viu pe care tokenizerul (pe octeți, fără DOM și fără subdocumente) nu-l vede ca Chromium. Toate apar de 0 ori în
     * documentele reale capturate (întregul site al clientului și alte site-uri WordPress reale; verificat), deci declanșatorii
     * sunt largi și siguri:
     *  - `<frameset>`/`<frame>` (F1): frameset-ok nu se resetează la fel între browsere (U+FFFD/NUL), deci noi putem ignora un
     *    `<frameset>` pe care Chromium îl construiește cu un `<frame onload>` viu;
     *  - `<template>` (F2): în Chromium conținutul lui poate fi VIU — shadow DOM declarativ (`shadowrootmode`/`shadowroot`) sau
     *    clonat în DOM de JS-ul site-ului — deci „conținutul unui template e inert” nu e o garanție; oricum conținutul de template
     *    unde ne abatem de la Chromium (form/caption) devine astfel nesigur;
     *  - orice atribut `srcdoc` (F3): `<iframe srcdoc>` construiește un subdocument viu pe care tokenizerul nu-l parsează. Tot
     *    aici ar intra, la politica viitoare, documentele purtate de o adresă (`data:`, `javascript:`, `data` la object/embed).
     */
    private function verificaConstructe(Token $t): void
    {
        if ($t->nume === 'frameset' || $t->nume === 'frame') {
            $this->nesigur('<' . $t->nume . '> de la octetul ' . $t->start . ': frameset-ul nu se resetează la fel între browsere '
                . '(U+FFFD/NUL), deci un `<frame onload>` viu poate fi ascuns');
            return;
        }
        if ($t->nume === 'template') {
            $this->nesigur('<template> de la octetul ' . $t->start . ': conținutul lui poate fi viu în browser (shadow DOM '
                . 'declarativ sau clonat de JS în DOM), nu inert');
            return;
        }
        if ($t->atribut('srcdoc') !== null) {
            $this->nesigur('atributul srcdoc la <' . $t->nume . '> de la octetul ' . $t->start . ': construiește un subdocument '
                . 'viu pe care tokenizerul nu-l parsează');
        }
    }

    /** Peste bugetul de lucru (proporțional cu lungimea documentului) analiza se oprește și documentul e nesigur. */
    private function verificaLucru(int $octet): void
    {
        if (!$this->oprit && $this->lucru > $this->bugetLucru) {
            $this->nesigur('bugetul de lucru depășit (peste ' . $this->bugetLucru . ', elemente create și pași de stivă, pe la octetul '
                . $octet . '): documentul nu se mai analizează');
            $this->oprit = true;
        }
    }

    /** După o oprire (prea adânc), tokenizerul merge mai departe ca în HTML, doar ca să acopere octeții. */
    private function stareFaraArbore(Token $t): string
    {
        return match ($t->nume) {
            'script' => 'script',
            'style', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript' => 'rawtext',
            'title', 'textarea' => 'rcdata',
            'plaintext' => 'plaintext',
            default => '',
        };
    }

    /** @return array<string, string> atributele tagului (fără duplicate), cu valorile ca octeți */
    private function atribute(Token $t): array
    {
        $rez = [];
        foreach ($t->atribute as $a) {
            if (!$a->duplicat) {
                $rez[$a->nume] = $a->valoare($this->html);
            }
        }
        ksort($rez);
        return $rez;
    }

    /** `<input type=hidden>` (fără deosebire de litere mari); „&” în type face documentul nesigur (valoarea decide construcția). */
    private function ascuns(array $tk): bool
    {
        return isset($tk['atr']['type']) && strtolower($this->valoareDirector($tk['atr']['type'], 'type de la <input>')) === 'hidden';
    }

    /**
     * Valoarea unui atribut a cărui VALOARE o citește construcția arborelui (singurele: `encoding` la annotation-xml și
     * `type` la input), decodată ca în browser. Orice „&” în ea face documentul nesigur (SPEC-R3-02/T1c): referințele din
     * valoarea unui atribut se decodează o singură dată, stânga-dreapta, și ce iese dintr-o referință NU se mai rescanează
     * (un „&#38;sol;” e „&” + „sol;”, nu „/”); la capete (o referință numerică fără „;”, o referință cu nume necunoscută)
     * browserele se abat una de alta, deci decizia n-ar fi aceeași peste tot. Fără „&” valoarea sunt chiar octeții.
     */
    private function valoareDirector(string $valoare, string $unde): string
    {
        if ($valoare === '' || !str_contains($valoare, '&')) {
            return $valoare;
        }
        $this->nesigur('„&” în valoarea atributului ' . $unde . ' (decide construcția): referințele din ea se decodează '
            . 'altfel între browsere, deci documentul e nesigur');
        return self::decodeAtribut($valoare);
    }

    /**
     * Valoarea unui atribut, decodată într-o SINGURĂ trecere stânga-dreapta (html_entity_decode, ca browserul): ce iese
     * dintr-o referință nu se mai rescanează (un „&#38;” rămâne „&”, nu începutul altui „&sol;”). NU se mai face o trecere
     * numerică separată înainte — acolo era dubla decodare din 648d4e9 (T1c), care citea „text&#38;sol;html" ca „text/html".
     * Orice valoare cu „&” care decide construcția e deja nesigură (valoareDirector), deci referințele numerice fără „;” (pe
     * care html_entity_decode le lasă) nu mai schimbă nicio decizie care contează.
     */
    private static function decodeAtribut(string $valoare): string
    {
        return $valoare === '' || !str_contains($valoare, '&') ? $valoare
            : html_entity_decode($valoare, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Caracterele unui text pentru constructor: referințele care dau un spațiu HTML (`&#32;`, `&#x9;`, `&Tab;`, `&NewLine;`…)
     * devin spații, celelalte un caracter oarecare; restul octeților rămân (NUL inclus).
     */
    private static function caractere(string $octeti): string
    {
        if (!str_contains($octeti, '&')) {
            return $octeti;
        }
        return (string) preg_replace_callback('/&(?:#[xX]([0-9a-fA-F]+);?|#([0-9]+);?|(Tab|NewLine);)/', static function (array $m): string {
            if (($m[3] ?? '') !== '') {
                return ' ';
            }
            $hex = (string) ($m[1] ?? '');
            $cod = $hex !== '' ? (strlen(ltrim($hex, '0')) > 6 ? -1 : (int) hexdec($hex)) : (strlen(ltrim((string) $m[2], '0')) > 7 ? -1 : (int) $m[2]);
            return in_array($cod, [9, 10, 12, 13, 32], true) ? ' ' : 'x';
        }, $octeti);
    }

    /**
     * Modul „quirks” al unui doctype (WHATWG, „the initial insertion mode”): fără numele „html”, cu un identificator public
     * vechi, un doctype stricat. `<!DOCTYPE html>` nu e „quirks”.
     */
    public static function quirks(string $octeti): bool
    {
        $n = strlen($octeti);
        $i = 9; // după „<!DOCTYPE”
        $sari = static function () use ($octeti, $n, &$i): void {
            while ($i < $n && strpbrk($octeti[$i], self::SPATII) !== false) {
                $i++;
            }
        };
        if (!str_ends_with($octeti, '>')) {
            return true;
        }
        $sari();
        $nume = '';
        while ($i < $n && strpbrk($octeti[$i], self::SPATII . '>') === false) {
            $nume .= $octeti[$i++];
        }
        if ($nume === '') {
            return true;
        }
        $sari();
        $public = null;
        $sistem = null;
        $citesteId = static function () use ($octeti, $n, &$i): ?string {
            $g = $octeti[$i] ?? '';
            if ($g !== '"' && $g !== "'") {
                return null;
            }
            $sf = strpos($octeti, $g, $i + 1);
            $gt = strpos($octeti, '>', $i + 1);
            if ($sf === false || ($gt !== false && $gt < $sf)) {
                return null;
            }
            $id = substr($octeti, $i + 1, $sf - $i - 1);
            $i = $sf + 1;
            return $id;
        };
        $cuvant = strtoupper(substr($octeti, $i, 6));
        if ($octeti[$i] !== '>') {
            if ($cuvant !== 'PUBLIC' && $cuvant !== 'SYSTEM') {
                return true;
            }
            $i += 6;
            $sari();
            $id = $citesteId();
            if ($id === null) {
                return true;
            }
            if ($cuvant === 'PUBLIC') {
                $public = $id;
                $sari();
                if (($octeti[$i] ?? '') !== '>') {
                    $sistem = $citesteId();
                    if ($sistem === null) {
                        return true;
                    }
                    $sari();
                }
            } else {
                $sistem = $id;
                $sari();
            }
            if (($octeti[$i] ?? '') !== '>') {
                return true;
            }
        }
        if (strtolower($nume) !== 'html') {
            return true;
        }
        $p = strtolower((string) $public);
        if ($public !== null && in_array($p, ['-//w3o//dtd w3 html strict 3.0//en//', '-/w3c/dtd html 4.0 transitional/en', 'html'], true)) {
            return true;
        }
        if ($sistem !== null && strtolower($sistem) === 'http://www.ibm.com/data/dtd/v11/ibmxhtml1-transitional.dtd') {
            return true;
        }
        if ($public === null) {
            return false;
        }
        foreach (self::PREFIXE_QUIRKS as $prefix) {
            if (str_starts_with($p, $prefix)) {
                return true;
            }
        }
        return $sistem === null && (str_starts_with($p, '-//w3c//dtd html 4.01 frameset//') || str_starts_with($p, '-//w3c//dtd html 4.01 transitional//'));
    }

    private const PREFIXE_QUIRKS = ['+//silmaril//dtd html pro v0r11 19970101//', '-//as//dtd html 3.0 aswedit + extensions//',
        '-//advasoft ltd//dtd html 3.0 aswedit + extensions//', '-//ietf//dtd html 2.0 level 1//', '-//ietf//dtd html 2.0 level 2//',
        '-//ietf//dtd html 2.0 strict level 1//', '-//ietf//dtd html 2.0 strict level 2//', '-//ietf//dtd html 2.0 strict//', '-//ietf//dtd html 2.0//',
        '-//ietf//dtd html 2.1e//', '-//ietf//dtd html 3.0//', '-//ietf//dtd html 3.2 final//', '-//ietf//dtd html 3.2//', '-//ietf//dtd html 3//',
        '-//ietf//dtd html level 0//', '-//ietf//dtd html level 1//', '-//ietf//dtd html level 2//', '-//ietf//dtd html level 3//',
        '-//ietf//dtd html strict level 0//', '-//ietf//dtd html strict level 1//', '-//ietf//dtd html strict level 2//',
        '-//ietf//dtd html strict level 3//', '-//ietf//dtd html strict//', '-//ietf//dtd html//', '-//metrius//dtd metrius presentational//',
        '-//microsoft//dtd internet explorer 2.0 html strict//', '-//microsoft//dtd internet explorer 2.0 html//',
        '-//microsoft//dtd internet explorer 2.0 tables//', '-//microsoft//dtd internet explorer 3.0 html strict//',
        '-//microsoft//dtd internet explorer 3.0 html//', '-//microsoft//dtd internet explorer 3.0 tables//', '-//netscape comm. corp.//dtd html//',
        '-//netscape comm. corp.//dtd strict html//', "-//o'reilly and associates//dtd html 2.0//", "-//o'reilly and associates//dtd html extended 1.0//",
        "-//o'reilly and associates//dtd html extended relaxed 1.0//", '-//sq//dtd html 2.0 hotmetal + extensions//',
        '-//softquad software//dtd hotmetal pro 6.0::19990601::extensions to html 4.0//',
        '-//softquad//dtd hotmetal pro 4.0::19971010::extensions to html 4.0//', '-//spyglass//dtd html 2.0 extended//',
        '-//sun microsystems corp.//dtd hotjava html//', '-//sun microsystems corp.//dtd hotjava strict html//', '-//w3c//dtd html 3 1995-03-24//',
        '-//w3c//dtd html 3.2 draft//', '-//w3c//dtd html 3.2 final//', '-//w3c//dtd html 3.2//', '-//w3c//dtd html 3.2s draft//',
        '-//w3c//dtd html 4.0 frameset//', '-//w3c//dtd html 4.0 transitional//', '-//w3c//dtd html experimental 19960712//',
        '-//w3c//dtd html experimental 970421//', '-//w3c//dtd w3 html//', '-//w3o//dtd w3 html 3.0//', '-//webtechs//dtd mozilla html 2.0//',
        '-//webtechs//dtd mozilla html//'];
}
