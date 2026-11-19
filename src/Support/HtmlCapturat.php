<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Html5\Token;
use App\Support\Html5\Tokenizer;
use App\Support\HtmlCapturat\ContextCapturat;
use App\Support\HtmlCapturat\Problema;
use App\Support\HtmlCapturat\SchemaDivi4;
use InvalidArgumentException;

/**
 * Politica HTML-ului capturat (adoptarea unui site WordPress, pasul 3; DECIZIE §2.9, INSTRUCTIUNI-BUILDER-C2 §3): o LISTĂ
 * ALBĂ peste tokenii tokenizerului nostru (App\Support\Html5\Tokenizer, pe octeți, fără DOMDocument). HTML-ul capturat e de
 * neîncredere; pe site-ul nou NU rulează niciun script capturat, comportamentul vine doar din runtime-ul nostru, pe același
 * DOM. Ce nu e explicit permis e REFUZAT, cu motivul (o `Problema`): nimic nu se scoate în tăcere, lista se lărgește doar
 * după o revizie de om. Rulează la import și la fiecare salvare a unei pagini capturate (`$mod`: `import` / `salvare`); un
 * document cu măcar o Problema nu se importă și nu se salvează (în plan: „NU” pe pagină).
 *
 * Ce permite (restul e refuzat):
 *  1. **Document în regulă:** `Tokenizer::analizeaza()` dă `nesigure` gol (altfel „document nesigur: …”, fail-closed:
 *     tokenii n-ar fi siguri). Fiindcă politica umblă pe acești tokeni, nicio altă regulă nu rulează pe un document nesigur.
 *  2. **Scripturi — numai date inerte scrise de noi:** `application/ld+json` (JSON valid), `speculationrules` (schemă
 *     închisă: doar `prefetch`/`prerender` cu adrese relative ale site-ului) și `<script type="application/json"
 *     id="ax-date">` (JSON valid). Orice alt `<script>` (inline, `src`, `module`, cu categorie de consimțământ) e refuzat.
 *  3. **Atribute de eveniment:** orice `on…`, oriunde (inclusiv în SVG), e refuzat. Cele din lista închisă
 *     (SchemaDivi4::handlerRecunoscut: `onload` de preload → `data-ax-tarziu`, `onload="media='all'"` → `media`) le
 *     transformă dialectul la import, determinist; politica vede HTML-ul de după și nu lasă niciun handler rămas.
 *  4. **Documente purtate de adrese:** `<iframe>` doar din lista albă (ContextCapturat, implicit harta Google Maps),
 *     inclusiv forma `data-src-cmplz`; `data:` doar în `<img src>` și `srcset`, doar imagini rastru, base64 corect, ≤ 2 KB
 *     decodați, cu octeții de început potriviți cu tipul; `<link>` doar cu `rel` dintr-o listă închisă; `href`/`src`/`srcset`/
 *     `poster`/`action`/`formaction` doar http(s), relative, `mailto`, `tel`, `#`. Refuzate: `object`/`embed`/`applet`/
 *     `portal`, `<base>`, `meta http-equiv=refresh`, formularele spre alt server, schemele de script în ORICE atribut
 *     (inclusiv `style` și JSON-ul `data-*`), `data:` oriunde în afara imaginilor rastru. `<frame>`/`<frameset>`,
 *     `<template>`, `srcdoc` le prinde deja tokenizerul (document nesigur).
 *  5. **Atribute și stiluri:** `class`/`id` fără metacaractere de marcaj; `data-*` doar din schema dialectului
 *     (SchemaDivi4), cu JSON-ul validat recursiv (cheile de poluare a prototipului refuzate, variantele HTML multi-view
 *     prin aceeași politică); `style` declarație cu declarație (fără `expression`/`behavior`/`-moz-binding`, `url()` doar
 *     spre căi relative/http(s) ale site-ului sau imagini `data:`); SVG prin lista albă SvgSigur; `<noscript>` doar cu
 *     conținut inert verificat recursiv (un `<link rel=stylesheet>` din manifest, un `<img>` local, text inert).
 *  6. **Necunoscutele:** un tag sau un atribut care nu e în nicio listă e refuzat.
 *
 * Verificarea pe datele reale (întregul site capturat) cere ca dialectul (pasul 5a) să fi scos întâi scripturile în
 * `ax-date` / pe lista aruncată și să fi transformat handlerele; abia atunci politica acceptă paginile, cu transformările
 * așteptate. Poarta G-html-capturat o încearcă pe încărcături plantate inventate (tools/dev/test-html-capturat.php), iar
 * proba end-to-end cu Chromium, pe documente adversariale, în afara repo-ului.
 */
final class HtmlCapturat
{
    /** Elementele HTML permise. Ce nu e aici (în spațiul HTML) e refuzat. Lipsesc, voit: object, embed, applet, portal,
     * base, frame, frameset, template, keygen, param, marquee, noembed, noframes, xmp, plaintext, listing, canvas, math. */
    public const TAGURI_HTML = ['a', 'abbr', 'address', 'area', 'article', 'aside', 'audio', 'b', 'bdi', 'bdo', 'blockquote',
        'body', 'br', 'button', 'caption', 'cite', 'code', 'col', 'colgroup', 'data', 'datalist', 'dd', 'del', 'details',
        'dfn', 'dialog', 'div', 'dl', 'dt', 'em', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3',
        'h4', 'h5', 'h6', 'head', 'header', 'hgroup', 'hr', 'html', 'i', 'iframe', 'img', 'input', 'ins', 'kbd', 'label',
        'legend', 'li', 'link', 'main', 'map', 'mark', 'menu', 'meta', 'meter', 'nav', 'noscript', 'ol', 'optgroup', 'option',
        'output', 'p', 'picture', 'pre', 'progress', 'q', 'rp', 'rt', 'ruby', 's', 'samp', 'script', 'section', 'select',
        'small', 'source', 'span', 'strong', 'style', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'textarea', 'tfoot',
        'th', 'thead', 'time', 'title', 'tr', 'track', 'u', 'ul', 'var', 'video', 'wbr'];

    /** Elementele SVG permise (lista albă SvgSigur a fișierului, desenul). Lipsesc: foreignObject, script, a, image, style. */
    public const TAGURI_SVG = ['svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'metadata', 'switch', 'path', 'circle',
        'ellipse', 'line', 'polyline', 'polygon', 'rect', 'text', 'tspan', 'textpath', 'tref', 'lineargradient',
        'radialgradient', 'stop', 'clippath', 'mask', 'pattern', 'marker', 'view', 'mpath'];

    /** Atributele permise pe orice element (în afara lui `data-*` — schema — și `aria-*` — prefix). */
    public const ATRIBUTE = ['class', 'id', 'title', 'dir', 'lang', 'hidden', 'tabindex', 'role', 'style', 'translate',
        'draggable', 'spellcheck', 'itemscope', 'itemtype', 'itemprop', 'itemref', 'itemid', 'slot', 'part', 'nonce', 'xmlns',
        'prefix', 'name', 'value', 'contenteditable', 'inert', 'enterkeyhint', 'autocapitalize', 'accesskey', 'popover'];

    /** Atribute în plus, pe elementul lor. */
    public const ATRIBUTE_ELEMENT = [
        'a' => ['href', 'target', 'rel', 'hreflang', 'type', 'download', 'ping', 'referrerpolicy', 'name'],
        'area' => ['href', 'alt', 'coords', 'shape', 'target', 'rel', 'download', 'referrerpolicy'],
        'img' => ['src', 'srcset', 'sizes', 'alt', 'width', 'height', 'loading', 'decoding', 'fetchpriority', 'usemap',
            'ismap', 'crossorigin', 'referrerpolicy'],
        'link' => ['rel', 'href', 'media', 'type', 'as', 'sizes', 'hreflang', 'crossorigin', 'referrerpolicy', 'integrity',
            'color', 'imagesrcset', 'imagesizes', 'fetchpriority', 'disabled'],
        'meta' => ['name', 'content', 'property', 'http-equiv', 'charset', 'media', 'scheme'],
        'style' => ['type', 'media', 'title', 'blocking'],
        'form' => ['action', 'method', 'name', 'enctype', 'accept-charset', 'autocomplete', 'novalidate', 'target', 'rel'],
        'input' => ['type', 'name', 'value', 'placeholder', 'required', 'readonly', 'disabled', 'checked', 'maxlength',
            'minlength', 'min', 'max', 'step', 'pattern', 'size', 'autocomplete', 'multiple', 'accept', 'list', 'for',
            'inputmode', 'autofocus', 'form'],
        'textarea' => ['name', 'placeholder', 'required', 'readonly', 'disabled', 'maxlength', 'minlength', 'rows', 'cols',
            'wrap', 'autocomplete', 'inputmode', 'autofocus', 'form'],
        'select' => ['name', 'required', 'disabled', 'multiple', 'size', 'autocomplete', 'autofocus', 'form'],
        'option' => ['value', 'selected', 'disabled', 'label'],
        'optgroup' => ['label', 'disabled'],
        'button' => ['type', 'name', 'value', 'disabled', 'form', 'formaction', 'formmethod', 'formtarget', 'formnovalidate',
            'formenctype', 'popovertarget', 'popovertargetaction', 'aria-controls', 'aria-expanded'],
        'label' => ['for', 'form'],
        'output' => ['for', 'name', 'form'],
        'video' => ['src', 'poster', 'width', 'height', 'controls', 'autoplay', 'loop', 'muted', 'preload', 'playsinline',
            'crossorigin', 'controlslist', 'disablepictureinpicture'],
        'audio' => ['src', 'controls', 'autoplay', 'loop', 'muted', 'preload', 'crossorigin', 'controlslist'],
        'source' => ['src', 'srcset', 'sizes', 'type', 'media', 'width', 'height'],
        'track' => ['src', 'kind', 'srclang', 'label', 'default'],
        'iframe' => ['src', 'width', 'height', 'allowfullscreen', 'allow', 'referrerpolicy', 'loading', 'name', 'sandbox',
            'frameborder', 'marginwidth', 'marginheight', 'scrolling', 'allowtransparency'],
        'th' => ['colspan', 'rowspan', 'scope', 'abbr', 'headers'],
        'td' => ['colspan', 'rowspan', 'headers'],
        'table' => ['border', 'cellspacing', 'cellpadding', 'width', 'summary'],
        'col' => ['span', 'width'],
        'colgroup' => ['span'],
        'ol' => ['start', 'reversed', 'type'],
        'li' => ['value'],
        'time' => ['datetime'],
        'del' => ['datetime', 'cite'],
        'ins' => ['datetime', 'cite'],
        'blockquote' => ['cite'],
        'q' => ['cite'],
        'details' => ['open', 'name'],
        'dialog' => ['open'],
        'map' => ['name'],
        // scriptul e refuzat dacă nu e date inerte (vezi script()); atributele lui sunt permise ca forma inertă (ld+json,
        // ax-date, speculationrules) să treacă validarea atributelor, iar „src” să fie refuzat cu mesajul potrivit, nu ca „atribut necunoscut”.
        'script' => ['type', 'src', 'async', 'defer', 'nonce', 'crossorigin', 'integrity', 'referrerpolicy', 'fetchpriority', 'charset'],
    ];

    /** Valorile `rel` permise pe `<link>` (lista închisă §3.4, plus iconițele și paginarea inerte din head-ul capturat). */
    public const LINK_REL = ['stylesheet', 'preload', 'icon', 'canonical', 'alternate', 'shortlink', 'dns-prefetch',
        'apple-touch-icon', 'prev', 'next', 'preconnect', 'modulepreload', 'prefetch'];

    /**
     * Indiciile de resurse (resource hints): permise doar spre adrese relative ale site-ului. Spre o gazdă străină importul le scoate
     * (Divi4, `indiciu-tert`); unul rămas, cu o adresă absolută sau protocol-relativă, e NU (`link-tert`).
     */
    public const LINK_INDICII = ['dns-prefetch', 'preconnect', 'prefetch', 'preload', 'modulepreload'];

    /** Atributele SVG permise (lista albă SvgSigur a fișierului; `on…` oriunde e refuzat de regula generală). */
    private const ATRIBUTE_SVG = ['id', 'class', 'style', 'viewbox', 'version', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2',
        'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'dx', 'dy', 'd', 'points', 'fill', 'fill-opacity', 'fill-rule',
        'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray',
        'stroke-dashoffset', 'stroke-opacity', 'opacity', 'transform', 'preserveaspectratio', 'gradientunits',
        'gradienttransform', 'spreadmethod', 'offset', 'stop-color', 'stop-opacity', 'clip-path', 'clip-rule',
        'clippathunits', 'mask', 'maskunits', 'maskcontentunits', 'patternunits', 'patterncontentunits', 'markerwidth',
        'markerheight', 'markerunits', 'orient', 'refx', 'refy', 'font-family', 'font-size', 'font-weight', 'font-style',
        'text-anchor', 'dominant-baseline', 'letter-spacing', 'textlength', 'lengthadjust', 'rotate', 'href', 'xlink:href',
        'aria-hidden', 'aria-label', 'aria-labelledby', 'role', 'focusable', 'overflow', 'visibility', 'display', 'color',
        'xml:space', 'vector-effect', 'xmlns', 'xmlns:xlink', 'enable-background', 'pointer-events'];

    private const SCHEME_SIGURE = ['http', 'https', 'mailto', 'tel'];
    private const MAGIE = [
        'png' => "\x89PNG\r\n\x1a\n",
        'gif' => 'GIF8',
        'jpeg' => "\xFF\xD8\xFF",
        'jpg' => "\xFF\xD8\xFF",
        'webp' => 'RIFF',
        'bmp' => 'BM',
    ];
    private const DATA_IMG_MAX = 2048;
    private const ADANCIME_MAXIMA = 8;

    /**
     * Verifică un document HTML capturat. Întoarce lista problemelor (gol = acceptat). `$mod`: `import` sau `salvare`
     * (aceeași listă albă; tabul JS e închis pe paginile capturate, deci la salvare orice script executabil e refuzat
     * oricui). Nu modifică nimic și nu reserializează; umblă doar pe tokeni.
     *
     * @return list<Problema>
     */
    public static function verifica(string $html, ?ContextCapturat $ctx = null, string $mod = 'import'): array
    {
        if (!in_array($mod, ['import', 'salvare'], true)) {
            throw new InvalidArgumentException('mod necunoscut: ' . $mod . ' (import|salvare)');
        }
        $ctx ??= new ContextCapturat();
        return (new self($html, $ctx))->ruleaza(0);
    }

    /** @var list<Problema> */
    private array $probleme = [];

    /** Câte blocuri `ax-date` am văzut (cel mult unul: runtime-ul îl caută pe singurul, recenzia M2). */
    private int $axDate = 0;

    private function __construct(private readonly string $html, private readonly ContextCapturat $ctx)
    {
    }

    /** @return list<Problema> */
    private function ruleaza(int $adancime): array
    {
        $a = Tokenizer::analizeaza($this->html);
        if ($a['nesigure'] !== []) {
            // Fail-closed: pe un document nesigur tokenii n-ar fi siguri, deci nicio altă regulă nu rulează.
            return [new Problema('document-nesigur', 'document nesigur (tokenizare care poate diferi între browsere): ' . $a['nesigure'][0])];
        }
        $tokeni = $a['tokeni'];
        foreach ($tokeni as $k => $t) {
            if ($t->tip === Token::DESCHIDERE) {
                $this->element($t, $tokeni, $k, $adancime);
            }
            // Comentariile, DOCTYPE, CDATA (în conținut străin), comentariile false și textul sunt inerte; textul brut
            // (script/style/noscript) e verificat la tagul lui de deschidere, nu aici.
        }
        return $this->probleme;
    }

    /** @param list<Token> $tokeni */
    private function element(Token $t, array $tokeni, int $k, int $adancime): void
    {
        $nume = $t->nume;
        $ns = $t->spatiu;
        if ($ns === 'math') {
            $this->nu('tag-necunoscut', 'element MathML <' . $nume . '> nepermis', $t->start);
            return;
        }
        if ($ns === 'svg') {
            if (!in_array($nume, self::TAGURI_SVG, true)) {
                $this->nu('tag-necunoscut', 'element SVG <' . $nume . '> nepermis (în afara listei albe)', $t->start);
            }
        } elseif (!in_array($nume, self::TAGURI_HTML, true)) {
            $this->nu('tag-necunoscut', 'element <' . $nume . '> nepermis (în afara listei albe)', $t->start);
            // nu ne oprim: îi verificăm totuși atributele, ca raportul să fie complet
        }
        $this->atribute($t, $ns);
        match ($nume) {
            'script' => $this->script($t, $tokeni, $k),
            'style' => $this->style($this->textBrut($tokeni, $k), $t->start),
            'noscript' => $this->noscript($this->textBrut($tokeni, $k), $t->start, $adancime),
            'iframe' => $this->iframe($t),
            'link' => $this->link($t),
            'meta' => $this->meta($t),
            'form' => $this->formular($t),
            default => null,
        };
    }

    /** Textul brut care urmează tagului de deschidere de la `$k` (script/style/noscript/…), sau ''. */
    private function textBrut(array $tokeni, int $k): string
    {
        $urm = $tokeni[$k + 1] ?? null;
        return $urm !== null && $urm->tip === Token::TEXT && $urm->brut !== '' ? $urm->octeti($this->html) : '';
    }

    private function atribute(Token $t, string $ns): void
    {
        foreach ($t->atribute as $a) {
            if ($a->duplicat) {
                continue;
            }
            $n = $a->nume;
            $v = $a->valoare($this->html);
            // Atributele de eveniment, oriunde (inclusiv în SVG): refuzate. Lista închisă (preload, embed) o transformă
            // dialectul înainte de politică (SchemaDivi4::handlerRecunoscut).
            if (str_starts_with($n, 'on')) {
                $href = (string) $t->valoare($this->html, 'href');
                $rec = SchemaDivi4::handlerRecunoscut($t->nume, $n, $v, ['rel' => $t->valoare($this->html, 'rel'), 'as' => $t->valoare($this->html, 'as'),
                    'href' => $href, 'hrefPropriu' => str_starts_with($href, '/') && !str_starts_with($href, '//'),
                    'dubluOn' => count(array_filter($t->atribute, static fn ($x): bool => str_starts_with($x->nume, 'on'))) > 1]);
                $this->nu('handler', 'atribut de eveniment „' . $n . '” la <' . $t->nume . '>'
                    . ($rec !== null ? ' (din lista închisă; trebuia transformat la import în „' . $rec[0] . '”)' : ' (niciun handler nu rulează pe site-ul nou)'), $a->start);
                continue;
            }
            if ($n === 'id' || $n === 'name') {
                // Id-urile și numele „ax-…” sunt ale importului: doar blocul nostru `<script type="application/json" id="ax-date">`
                // (unul singur) poate purta unul; altul, oricum scris (și cu entități), l-ar umbri în DOM (recenzia M2).
                $dec = strtolower(trim(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                $alNostru = $n === 'id' && $v === SchemaDivi4::AX_DATE_ID && $t->nume === 'script'
                    && strtolower(trim((string) $t->valoare($this->html, 'type'))) === 'application/json';
                if (str_starts_with($dec, 'ax-') && !$alNostru) {
                    $this->nu('ax-id', $n . '="' . mb_substr($v, 0, 40) . '" la <' . $t->nume . '>: id-urile și numele „ax-…” sunt rezervate importului', $a->start);
                    continue;
                }
            }
            if (str_starts_with($n, 'data-')) {
                $this->dataAtribut($t->nume, $n, $v, $a->start);
                continue;
            }
            if (str_starts_with($n, 'aria-')) {
                $this->valoareFaraScript($n, $v, $a->start);
                continue;
            }
            if (!$this->atributPermis($t->nume, $n, $ns)) {
                // Zgomot de marcaj inert, păstrat octet cu octet: un atribut fără valoare al cărui nume n-are nicio literă
                // (nu poate fi un `on…` și nu poate purta o adresă) — de pildă virgula rătăcită din șablonul DGE al grilei
                // galeriei (`data-link_icon="…",`). Nu e un comportament; lista albă rămâne închisă pentru numele cu litere.
                if (!$a->areValoare() && preg_match('/[a-z]/i', $n) !== 1) {
                    continue;
                }
                $this->nu('atribut-necunoscut', 'atribut „' . $n . '” la <' . $t->nume . '> nepermis (în afara listei albe)', $a->start);
                continue;
            }
            $this->valoareAtribut($t->nume, $n, $v, $a->start);
        }
    }

    private function atributPermis(string $element, string $nume, string $ns): bool
    {
        if ($ns === 'svg') {
            return in_array($nume, self::ATRIBUTE_SVG, true) || str_starts_with($nume, 'aria-');
        }
        if (in_array($nume, self::ATRIBUTE, true)) {
            return true;
        }
        return in_array($nume, self::ATRIBUTE_ELEMENT[$element] ?? [], true);
    }

    /** Valoarea unui atribut obișnuit: scheme de script nicăieri; adresele pe atributele care poartă adrese. */
    private function valoareAtribut(string $element, string $nume, string $valoare, int $octet): void
    {
        $this->valoareFaraScript($nume, $valoare, $octet);
        if ($nume === 'style') {
            $this->style(html_entity_decode($valoare, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $octet, true);
            return;
        }
        if (in_array($nume, ['class', 'id'], true)) {
            $dec = html_entity_decode($valoare, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/[<>"\'`\x00-\x1F]/', $dec) === 1) {
                $this->nu('class-id', 'atributul „' . $nume . '” are un metacaracter de marcaj (< > " \' ` sau un caracter de control)', $octet);
            }
            return;
        }
        if (in_array($nume, ['title', 'alt'], true)) {
            // Citite de lightbox-uri în HTML: fără „<” (I-C §3).
            if (str_contains(html_entity_decode($valoare, ENT_QUOTES | ENT_HTML5, 'UTF-8'), '<')) {
                $this->nu('title', 'atributul „' . $nume . '” la <' . $element . '> conține „<” (ar putea deveni marcaj într-un lightbox)', $octet);
            }
            return;
        }
        $poartaAdresa = in_array($nume, ['href', 'src', 'poster', 'action', 'formaction', 'cite', 'ping', 'background',
            'usemap', 'data', 'list', 'longdesc', 'xlink:href', 'imagesrcset'], true);
        if ($nume === 'srcset' || $nume === 'imagesrcset') {
            $this->srcset($element, $valoare, $octet);
            return;
        }
        if ($element === 'iframe' && $nume === 'src') {
            return; // adresa unui <iframe> o verifică iframe() (lista albă), nu regula schemelor
        }
        if ($poartaAdresa && trim($valoare) !== '') {
            $esteImg = in_array($element, ['img', 'source'], true) && in_array($nume, ['src'], true);
            $this->adresa($nume, $valoare, $octet, $esteImg, false);
        }
    }

    /** Orice scheme de script (javascript:/vbscript:) sau `data:` nepermis în orice atribut (inclusiv aria-*, title). */
    private function valoareFaraScript(string $nume, string $valoare, int $octet): void
    {
        $sch = self::schema($valoare);
        if (in_array($sch, ['javascript', 'vbscript', 'livescript', 'mocha', 'data-script'], true)) {
            $this->nu('schema-script', 'schemă de script („' . $sch . ':”) în atributul „' . $nume . '”', $octet);
        }
    }

    private function script(Token $t, array $tokeni, int $k): void
    {
        $type = $t->valoare($this->html, 'type');
        $id = $t->valoare($this->html, 'id');
        if (!SchemaDivi4::scriptInert($type, $id)) {
            $this->nu('script', 'script executabil refuzat (<script'
                . ($type !== null ? ' type="' . $type . '"' : '') . ($t->valoare($this->html, 'src') !== null ? ' src' : ' inline')
                . '>): pe site-ul nou nu rulează niciun script capturat', $t->start);
            return;
        }
        if ($t->valoare($this->html, 'src') !== null) {
            $this->nu('script', 'blocul de date inerte (<script type="' . $type . '">) nu poate avea „src”', $t->start);
            return;
        }
        $continut = $this->textBrut($tokeni, $k);
        $tl = strtolower((string) $type);
        if ($tl === 'application/json') {
            $this->axDate($continut, $t->start);
        } elseif ($tl === 'application/ld+json') {
            if (json_decode($continut) === null && trim($continut) !== 'null' && trim($continut) !== '') {
                $this->nu('script-json', 'blocul „' . ($id !== null ? $id : $type) . '” nu e JSON valid', $t->start);
            }
        } elseif ($tl === 'speculationrules') {
            $this->speculationrules($continut, $t->start);
        }
    }

    /**
     * Blocul `ax-date` (pasul 5a, recenzia M2): unul singur; JSON valid, trecut prin jsonSigur (poluarea prototipului, schemele de
     * script); fiecare adresă din el doar https sau relativă la site (nu `//gazdă`, nu `javascript:`/`data:`/`vbscript:`/…; un
     * text fără spații cu o schemă e o adresă); foile încărcătorului „late” (`file`) doar căi `.css` ale site-ului; cheile de sus
     * doar SchemaDivi4::CHEI_AX_DATE, iar `ax_structura` din gramatica ei închisă (SchemaDivi4::structura).
     */
    private function axDate(string $continut, int $octet): void
    {
        if (++$this->axDate > 1) {
            $this->nu('ax-date', 'documentul are mai multe blocuri ax-date (runtime-ul îl caută pe singurul)', $octet);
            return;
        }
        try {
            $j = json_decode($continut, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->nu('script-json', 'blocul „ax-date” nu e JSON valid', $octet);
            return;
        }
        if (!is_array($j) || ($j !== [] && array_is_list($j))) {
            $this->nu('ax-date', 'blocul „ax-date” trebuie să fie un obiect JSON', $octet);
            return;
        }
        $this->jsonSigur('ax-date', $j, $octet, 0);
        $this->axAdrese($j, $octet, 0);
        // Cheile de sus sunt închise: doar ce pune importul (configurațiile știute, literalele șabloanelor, ax_structura).
        foreach (array_keys($j) as $cheie) {
            if (!in_array((string) $cheie, SchemaDivi4::CHEI_AX_DATE, true)) {
                $this->nu('ax-cheie', 'cheia „' . mb_substr((string) $cheie, 0, 40) . '” în ax-date: doar configurațiile știute, „file” și „'
                    . SchemaDivi4::AX_STRUCTURA . '”', $octet);
            }
        }
        if (array_key_exists(SchemaDivi4::AX_STRUCTURA, $j)) {
            foreach (SchemaDivi4::structura($j[SchemaDivi4::AX_STRUCTURA]) as $pb) {
                $this->nu('ax-structura', $pb, $octet);
            }
        }
        if (array_key_exists('file', $j)) {
            foreach (is_array($j['file']) ? $j['file'] : [$j['file']] as $f) {
                $cale = is_string($f) ? (string) preg_replace('/[?#].*$/s', '', $f) : '';
                if (!is_string($f) || !str_starts_with($f, '/') || str_starts_with($f, '//') || str_contains($f, '\\') || str_contains('/' . $cale . '/', '/../')
                    || !str_ends_with(strtolower($cale), '.css')) {
                    $this->nu('ax-file', 'o foaie „late” din ax-date nu e o cale .css a site-ului: „' . mb_substr(is_string($f) ? $f : gettype($f), 0, 60) . '”', $octet);
                }
            }
        }
    }

    /** Adresele din datele lui `ax-date`: doar https sau relative la site. */
    private function axAdrese(mixed $v, int $octet, int $adancime): void
    {
        if ($adancime > 128) {
            return;
        }
        if (is_array($v)) {
            foreach ($v as $x) {
                $this->axAdrese($x, $octet, $adancime + 1);
            }
            return;
        }
        if (!is_string($v)) {
            return;
        }
        $sch = self::schema($v);
        $pareAdresa = preg_match('/[\x00-\x20\x7F]/', trim($v)) !== 1;
        if ($sch !== '' && $sch !== 'https' && ($pareAdresa || in_array($sch, ['javascript', 'vbscript', 'livescript', 'mocha', 'data', 'blob', 'file', 'filesystem'], true))) {
            $this->nu('ax-adresa', 'adresă „' . $sch . ':” în ax-date (doar https sau relativă la site)', $octet);
            return;
        }
        $t = ltrim($v);
        if (str_starts_with($t, '//') || str_starts_with($t, '\\\\') || str_starts_with($t, '/\\') || str_starts_with($t, '\\/')) {
            $this->nu('ax-adresa', 'adresă spre altă gazdă („' . mb_substr($t, 0, 30) . '”) în ax-date', $octet);
        }
    }

    /** `speculationrules`: doar `prefetch`/`prerender` cu adrese relative ale site-ului (schemă închisă). */
    private function speculationrules(string $json, int $octet): void
    {
        $date = json_decode($json, true);
        if (!is_array($date)) {
            $this->nu('spec', 'speculationrules nu e JSON valid', $octet);
            return;
        }
        foreach ($date as $cheie => $reguli) {
            if (!in_array($cheie, ['prefetch', 'prerender'], true)) {
                $this->nu('spec', 'speculationrules: cheia „' . $cheie . '” nepermisă (doar prefetch/prerender)', $octet);
                continue;
            }
            foreach ((array) $reguli as $regula) {
                foreach ((array) ($regula['urls'] ?? []) as $u) {
                    if (self::schema((string) $u) !== '' || str_starts_with((string) $u, '//')) {
                        $this->nu('spec', 'speculationrules: adresa „' . $u . '” nu e relativă site-ului', $octet);
                    }
                }
            }
        }
    }

    private function iframe(Token $t): void
    {
        $src = trim((string) $t->valoare($this->html, 'src'));
        $cmplz = $t->valoare($this->html, 'data-src-cmplz');
        $srcInert = $src === '' || $src === 'about:blank' || $src === '#' || str_starts_with($src, '#');
        $cmplzOk = $cmplz !== null && $this->ctx->iframePermis(html_entity_decode($cmplz, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $srcOk = $src !== '' && $this->ctx->iframePermis(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($srcOk || ($srcInert && $cmplzOk)) {
            return;
        }
        $this->nu('iframe', '<iframe> din afara listei albe (permis doar din sursele din ContextCapturat, implicit harta Google Maps): src="'
            . $src . '"' . ($cmplz !== null ? ', data-src-cmplz prezent' : ''), $t->start);
    }

    private function link(Token $t): void
    {
        $rel = strtolower(trim((string) $t->valoare($this->html, 'rel')));
        if ($rel === '' || array_filter(preg_split('/\s+/', $rel) ?: [], static fn (string $r): bool => $r !== '' && !in_array($r, self::LINK_REL, true)) !== []) {
            $this->nu('link-rel', '<link rel="' . $rel . '"> în afara listei închise (' . implode(', ', self::LINK_REL) . ')', $t->start);
        }
        if (array_intersect(preg_split('/\s+/', $rel) ?: [], self::LINK_INDICII) !== []) {
            $href = trim(html_entity_decode((string) $t->valoare($this->html, 'href'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (self::schema($href) !== '' || preg_match('#^[/\\\\]{2}#', $href) === 1) {
                $this->nu('link-tert', '<link rel="' . $rel . '"> spre o adresă absolută („' . mb_substr($href, 0, 60) . '”): indiciile de resurse spre alte gazde se scot la import', $t->start);
            }
        }
    }

    private function meta(Token $t): void
    {
        $he = strtolower(trim((string) $t->valoare($this->html, 'http-equiv')));
        if ($he === 'refresh') {
            $this->nu('meta-refresh', '<meta http-equiv="refresh"> refuzat (ar putea duce documentul în altă parte)', $t->start);
        } elseif ($he !== '' && !in_array($he, ['content-type', 'x-ua-compatible', 'content-language'], true)) {
            // Nu lăsăm un CSP din conținut (politica site-ului vine din antetul serverului, pasul 6) și nici alt http-equiv.
            $this->nu('meta', '<meta http-equiv="' . $he . '"> nepermis', $t->start);
        }
    }

    private function formular(Token $t): void
    {
        $action = trim((string) $t->valoare($this->html, 'action'));
        if ($action === '') {
            return;
        }
        $sch = self::schema($action);
        if (!in_array($sch, ['', 'http', 'https'], true) || str_starts_with($action, '//')) {
            // „alt server” îl mărginește CSP form-action 'self' la rulare; aici refuzăm doar schemele nesigure și host-ul
            // de protocol-relativ (`//alt`), care ar putea fi un alt server fără scheme.
            $this->nu('form-action', '<form action="' . $action . '"> cu schemă/țintă nepermisă (doar http(s) sau relativ la site)', $t->start);
        }
    }

    private function dataAtribut(string $element, string $nume, string $valoare, int $octet): void
    {
        $fel = SchemaDivi4::felData($nume);
        if ($fel === null) {
            $this->nu('data-necunoscut', 'atributul „' . $nume . '” la <' . $element . '> nu e în schema dialectului', $octet);
            return;
        }
        $dec = html_entity_decode($valoare, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->valoareFaraScript($nume, $valoare, $octet);
        if ($fel === 'scalar') {
            if (str_contains($dec, '<') && in_array($nume, ['data-title', 'data-caption', 'data-name-en'], true)) {
                $this->nu('data-title', 'atributul „' . $nume . '” conține „<” (citit de un lightbox)', $octet);
            }
            return;
        }
        if ($fel === 'url') {
            if (trim($dec) === '' || str_starts_with($dec, '#')) {
                return;
            }
            if ($nume === 'data-src-cmplz') {
                if (!$this->ctx->iframePermis($dec)) {
                    $this->nu('data-uri', 'data-src-cmplz „' . substr($dec, 0, 40) . '…” în afara listei albe de iframe-uri', $octet);
                }
                return;
            }
            $this->adresa($nume, $valoare, $octet, false, false);
            return;
        }
        // JSON (data-props, data-settings, data-et-multi-view): decodat din entități, validat recursiv.
        $json = json_decode($dec, true);
        if ($json === null && trim($dec) !== 'null') {
            $this->nu('data-json', 'atributul „' . $nume . '” nu e JSON valid', $octet);
            return;
        }
        $this->jsonSigur($nume, $json, $octet, 0);
        if ($nume === 'data-props') {
            $this->cheiInchise($nume, $json, SchemaDivi4::PROPS, $octet);
        } elseif ($nume === 'data-settings') {
            $this->cheiInchise($nume, $json, SchemaDivi4::SETTINGS, $octet);
        } elseif ($nume === 'data-et-multi-view') {
            $this->multiView($json, $octet);
        }
    }

    /** JSON recursiv: cheile de poluare a prototipului refuzate, schemele de script în orice șir refuzate. */
    private function jsonSigur(string $nume, mixed $json, int $octet, int $adancime): void
    {
        if ($adancime > 24) {
            $this->nu('data-json', 'JSON-ul din „' . $nume . '” e prea adânc', $octet);
            return;
        }
        if (is_array($json)) {
            foreach ($json as $cheie => $val) {
                if (is_string($cheie) && in_array(strtolower($cheie), SchemaDivi4::CHEI_INTERZISE, true)) {
                    $this->nu('data-proto', 'cheie de poluare a prototipului „' . $cheie . '” în „' . $nume . '”', $octet);
                }
                $this->jsonSigur($nume, $val, $octet, $adancime + 1);
            }
            return;
        }
        if (is_string($json)) {
            $sch = self::schema($json);
            if (in_array($sch, ['javascript', 'vbscript', 'livescript'], true)) {
                $this->nu('data-script', 'schemă de script în JSON-ul din „' . $nume . '”', $octet);
            }
        }
    }

    /** @param list<string> $permise */
    private function cheiInchise(string $nume, mixed $json, array $permise, int $octet): void
    {
        if (!is_array($json)) {
            $this->nu('data-json', '„' . $nume . '” trebuie să fie un obiect JSON', $octet);
            return;
        }
        foreach (array_keys($json) as $cheie) {
            if (!in_array((string) $cheie, $permise, true)) {
                $this->nu('data-cheie', 'cheia „' . $cheie . '” nepermisă în „' . $nume . '” (lista se lărgește după o revizie)', $octet);
            }
        }
    }

    /** `data-et-multi-view`: variantele HTML din `schema.content` trec prin aceeași politică. */
    private function multiView(mixed $json, int $octet): void
    {
        $continut = $json['schema']['content'] ?? null;
        if (is_array($continut)) {
            foreach ($continut as $varianta => $html) {
                if (!is_string($html)) {
                    continue;
                }
                foreach (self::verifica($html, $this->ctx, 'import') as $p) {
                    $this->nu('multi-view', 'varianta „' . $varianta . '” din data-et-multi-view: ' . $p->mesaj, $octet);
                }
            }
        }
        foreach ((array) ($json['schema']['attrs'] ?? []) as $dispozitiv => $attrs) {
            foreach ((array) $attrs as $cheie => $val) {
                if (in_array($cheie, ['src', 'srcset'], true) && is_string($val) && trim($val) !== '') {
                    if ($cheie === 'srcset') {
                        $this->srcset('img', $val, $octet);
                    } else {
                        $this->adresa('data-et-multi-view.' . $cheie, $val, $octet, true, false);
                    }
                }
            }
        }
    }

    /** `srcset`: fiecare candidat „url [descriptor]”; `data:` doar rastru mic. */
    private function srcset(string $element, string $valoare, int $octet): void
    {
        foreach (explode(',', $valoare) as $cand) {
            $cand = trim($cand);
            if ($cand === '') {
                continue;
            }
            $url = preg_split('/\s+/', $cand)[0] ?? '';
            if ($url !== '') {
                $this->adresa($element . ' srcset', $url, $octet, in_array($element, ['img', 'source'], true), false);
            }
        }
    }

    /** O adresă: http(s)/relativă/mailto/tel/#; `data:` doar rastru mic în imagini; iframe doar din lista albă. */
    private function adresa(string $unde, string $valoare, int $octet, bool $imagine, bool $iframe): void
    {
        $dec = trim(html_entity_decode($valoare, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($dec === '' || str_starts_with($dec, '#')) {
            return;
        }
        $sch = self::schema($dec);
        if ($sch === 'data') {
            if ($imagine) {
                $this->dataImagine($unde, $dec, $octet);
            } else {
                $this->nu('data-uri', 'URL „data:” în „' . $unde . '” (permis doar ca imagine rastru în <img>/srcset)', $octet);
            }
            return;
        }
        if (in_array($sch, ['javascript', 'vbscript', 'livescript', 'mocha'], true)) {
            $this->nu('schema-script', 'schemă de script („' . $sch . ':”) în „' . $unde . '”', $octet);
            return;
        }
        if ($sch === '') {
            return; // relativă (inclusiv protocol-relativ „//host” — host-ul îl mărginește CSP; nu e o schemă de script)
        }
        if (!in_array($sch, self::SCHEME_SIGURE, true)) {
            $this->nu('schema', 'schemă „' . $sch . ':” nepermisă în „' . $unde . '” (doar http, https, mailto, tel, #, relativ)', $octet);
        }
    }

    /** `data:` imagine: tip rastru, base64 corect, ≤ 2 KB decodați, octeții de început potriviți cu tipul. */
    private function dataImagine(string $unde, string $dec, int $octet): void
    {
        if (preg_match('#^data:image/([a-z0-9.+-]+)\s*;\s*base64\s*,(.*)$#is', $dec, $m) !== 1) {
            $this->nu('data-uri', 'URL „data:” în „' . $unde . '” nu e o imagine rastru base64', $octet);
            return;
        }
        $tip = strtolower($m[1]);
        if (!isset(self::MAGIE[$tip]) && $tip !== 'avif') {
            $this->nu('data-uri', 'tip de imagine „data:” nepermis în „' . $unde . '”: ' . $tip . ' (doar rastru: ' . implode(', ', array_keys(self::MAGIE)) . ', avif)', $octet);
            return;
        }
        $brut = base64_decode(preg_replace('/\s+/', '', $m[2]) ?? '', true);
        if ($brut === false) {
            $this->nu('data-uri', 'base64 stricat în imaginea „data:” din „' . $unde . '”', $octet);
            return;
        }
        if (strlen($brut) > self::DATA_IMG_MAX) {
            $this->nu('data-uri', 'imaginea „data:” din „' . $unde . '” are ' . strlen($brut) . ' octeți (peste ' . self::DATA_IMG_MAX . ')', $octet);
            return;
        }
        $potrivit = $tip === 'avif'
            ? (strlen($brut) >= 12 && substr($brut, 4, 4) === 'ftyp' && str_contains(substr($brut, 8, 8), 'avi'))
            : ($tip === 'webp' ? (str_starts_with($brut, 'RIFF') && substr($brut, 8, 4) === 'WEBP') : str_starts_with($brut, self::MAGIE[$tip]));
        if (!$potrivit) {
            $this->nu('data-uri', 'octeții imaginii „data:” din „' . $unde . '” nu se potrivesc cu tipul ' . $tip, $octet);
        }
    }

    /** CSS (element `<style>` sau atribut `style`): fără scheme de script, `expression`, `behavior`, `-moz-binding`; url() sigur. */
    private function style(string $css, int $octet, bool $atribut = false): void
    {
        if ($css === '') {
            return;
        }
        $jos = strtolower($css);
        foreach (['expression(' => 'expression()', 'javascript:' => 'javascript:', 'vbscript:' => 'vbscript:',
            '-moz-binding' => '-moz-binding', '</style' => '</style'] as $semn => $ce) {
            if (str_contains($jos, $semn)) {
                $this->nu('style', ($atribut ? 'atributul style' : 'elementul <style>') . ' conține „' . $ce . '”', $octet);
            }
        }
        if (preg_match('/(?<![\w-])behavior\s*:/i', $css) === 1) {
            $this->nu('style', ($atribut ? 'atributul style' : 'elementul <style>') . ' conține „behavior:” (IE)', $octet);
        }
        // url() cu conținut între ghilimele (poate avea „)” înăuntru) sau fără ghilimele (până la primul „)”).
        if (preg_match_all('/url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)\'"]*))\s*\)/i', $css, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $set) {
                $u = trim($set[1] !== '' ? $set[1] : ($set[2] !== '' ? $set[2] : ($set[3] ?? '')));
                $sch = self::schema($u);
                if (in_array($sch, ['javascript', 'vbscript', 'livescript'], true)) {
                    $this->nu('style-url', 'url() cu schemă de script în CSS: ' . substr($u, 0, 40), $octet);
                } elseif ($sch === 'data' && preg_match('#^data:image/#i', $u) !== 1) {
                    $this->nu('style-url', 'url(data:) non-imagine în CSS: ' . substr($u, 0, 40), $octet);
                }
                // http(s), relativ și data:image/* (inert ca imagine CSS) sunt permise; host-ul extern îl mărginește CSP img-src.
            }
        }
    }

    /** `<noscript>`: conținut inert, verificat recursiv prin aceeași politică (un `<link rel=stylesheet>`, un `<img>`, text). */
    private function noscript(string $continut, int $octet, int $adancime): void
    {
        if (trim($continut) === '') {
            return;
        }
        if ($adancime >= self::ADANCIME_MAXIMA) {
            $this->nu('noscript', '<noscript> cuibărit prea adânc', $octet);
            return;
        }
        $sub = (new self($continut, $this->ctx))->ruleaza($adancime + 1);
        foreach ($sub as $p) {
            $this->nu('noscript', 'în <noscript>: ' . $p->mesaj, $octet);
        }
        // În plus, un `<link>` din <noscript> e doar `rel=stylesheet` spre o foaie a site-ului din manifestul capturii (recenzia m7;
        // fără manifest, niciunul: ce spunea deja ContextCapturat).
        foreach (Tokenizer::tokenizeaza($continut) as $t) {
            if ($t->tip === Token::DESCHIDERE && $t->nume === 'link') {
                $href = trim(html_entity_decode((string) $t->valoare($continut, 'href'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $rel = strtolower(trim((string) $t->valoare($continut, 'rel')));
                if ($rel !== 'stylesheet' || $href === '' || self::schema($href) !== '' || preg_match('#^[/\\\\]{2}#', $href) === 1 || !$this->ctx->foaieInManifest($href)) {
                    $this->nu('noscript', 'în <noscript>: <link rel="' . $rel . '" href="' . mb_substr($href, 0, 80) . '"> nu e o foaie din manifestul capturii', $octet);
                }
            }
        }
    }

    private function nu(string $cod, string $mesaj, int $octet = -1): void
    {
        $this->probleme[] = new Problema($cod, $mesaj, $octet);
    }

    /**
     * Schema unei adrese, cum o vede browserul: entitățile decodate, spațiile și caracterele de control de la început și
     * dinaintea lui „:” scoase, litere mici. '' = relativă (fără schemă) sau un fragment/cale. Așa, un „java&#9;script:”,
     * „ja\tvascript:” sau „JAVASCRIPT:” se vede tot „javascript”.
     */
    private static function schema(string $valoare): string
    {
        $v = html_entity_decode($valoare, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Scoatem TOATE spațiile și caracterele de control (NUL inclus): o schemă nu le poate conține, dar atacurile le pun.
        $v = preg_replace('/[\x00-\x20\x7F]/', '', $v) ?? $v;
        if ($v === '' || $v[0] === '#' || $v[0] === '/' || $v[0] === '?') {
            return '';
        }
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):/', $v, $m) !== 1) {
            return '';
        }
        $pozitia = strpos($v, ':');
        // Dacă înaintea lui „:” apare „/”, „?” sau „#”, nu e o schemă (e o cale cu „:” în ea).
        foreach (['/', '?', '#'] as $c) {
            $p = strpos($v, $c);
            if ($p !== false && $p < $pozitia) {
                return '';
            }
        }
        return strtolower($m[1]);
    }
}
