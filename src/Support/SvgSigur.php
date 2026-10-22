<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * SVG care intră în pagini din pachetul unui site (docs/PACHETE.md): o listă de elemente și atribute permise,
 * nimic altceva. Pachetele le scrie ANDAXI, dar ce ajunge în HTML fără nicio prelucrare se verifică oricum, la
 * rulare (iconițele categoriilor) și în tools/dev/verifica-pachet.php (fișierele .svg ale pachetului).
 *
 *  - `fragment()`: o iconiță din `tema.json` → `iconite_categorii` (se scrie așa cum e în `<svg>`-ul categoriei,
 *    HomeSections): doar `path`, `circle`, `rect`, `line`, `polyline`, `polygon`, `ellipse`, cu atributele de geometrie
 *    (`d cx cy r rx ry x y x1 y1 x2 y2 width height points`), valori doar din cifre, litere, spațiu, „.”, „,”, „-”, „%”;
 *    fără text, comentarii, entități sau CDATA.
 *  - `fisier()`: un fișier .svg al pachetului (servit ca imagine): rădăcina `svg`, elementele de desen, grupuri,
 *    gradienți și text; atributele de prezentare; `href` doar spre `#id`, `url()` doar spre `#id`; fără `script`,
 *    `foreignObject`, `image`, `a`, `style`, atribute `on…`, DOCTYPE sau entități.
 *
 * Fără dependențe în afara extensiei dom; nu încarcă nimic din rețea (LIBXML_NONET).
 */
final class SvgSigur
{
    public const ELEMENTE_ICONITA = ['path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse'];
    public const ATRIBUTE_ICONITA = ['d', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'width', 'height', 'points'];

    public const ELEMENTE_FISIER = ['svg', 'g', 'defs', 'title', 'desc', 'symbol', 'use', 'path', 'circle', 'ellipse', 'line',
        'polyline', 'polygon', 'rect', 'text', 'tspan', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask'];
    public const ATRIBUTE_FISIER = ['id', 'class', 'style', 'viewbox', 'version', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2',
        'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'dx', 'dy', 'd', 'points', 'fill', 'fill-opacity', 'fill-rule', 'stroke',
        'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset',
        'stroke-opacity', 'opacity', 'transform', 'preserveaspectratio', 'gradientunits', 'gradienttransform', 'spreadmethod',
        'offset', 'stop-color', 'stop-opacity', 'clip-path', 'clip-rule', 'clippathunits', 'mask', 'maskunits',
        'maskcontentunits', 'font-family', 'font-size', 'font-weight', 'font-style', 'text-anchor', 'dominant-baseline',
        'letter-spacing', 'textlength', 'lengthadjust', 'rotate', 'href', 'aria-hidden', 'aria-label', 'role', 'focusable',
        'overflow', 'visibility', 'display', 'color', 'space'];

    /** O iconiță (fragmentul din interiorul `<svg>`) e sigură? */
    public static function fragment(string $svg): bool
    {
        return self::problemaFragment($svg) === null;
    }

    /** De ce nu e sigură o iconiță (null = e sigură). */
    public static function problemaFragment(string $svg): ?string
    {
        if (trim($svg) === '') {
            return 'iconița e goală';
        }
        if (preg_match('/[&]|<!|<\?|\]\]>/', $svg) === 1) {
            return 'iconița are entități, comentarii, CDATA sau instrucțiuni';
        }
        $doc = self::document('<svg xmlns="http://www.w3.org/2000/svg">' . $svg . '</svg>');
        if ($doc === null || !$doc->documentElement instanceof DOMElement) {
            return 'iconița nu e XML corect';
        }
        foreach ($doc->documentElement->childNodes as $nod) {
            $problema = self::verificaNodIconita($nod);
            if ($problema !== null) {
                return $problema;
            }
        }
        return null;
    }

    private static function verificaNodIconita(DOMNode $nod): ?string
    {
        if ($nod->nodeType === XML_TEXT_NODE) {
            return trim((string) $nod->nodeValue) === '' ? null : 'iconița are text';
        }
        if (!$nod instanceof DOMElement) {
            return 'iconița are un nod nepermis (' . $nod->nodeName . ')';
        }
        if (!in_array($nod->localName, self::ELEMENTE_ICONITA, true) || $nod->prefix !== '') {
            return 'element nepermis în iconiță: <' . $nod->nodeName . '>';
        }
        foreach ($nod->attributes ?? [] as $atribut) {
            if ($atribut->prefix !== '' || !in_array($atribut->localName, self::ATRIBUTE_ICONITA, true)) {
                return 'atribut nepermis în iconiță: ' . $atribut->nodeName;
            }
            if (preg_match('/^[0-9A-Za-z .,%-]*$/', (string) $atribut->value) !== 1) {
                return 'valoare nepermisă în iconiță: ' . $atribut->nodeName . '="' . $atribut->value . '"';
            }
        }
        foreach ($nod->childNodes as $copil) {
            $problema = self::verificaNodIconita($copil);
            if ($problema !== null) {
                return $problema;
            }
        }
        return null;
    }

    /** De ce nu e sigur un fișier .svg al unui pachet (null = e sigur). */
    public static function problemaFisier(string $continut): ?string
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $continut) === 1) {
            return 'are DOCTYPE sau entități';
        }
        if (preg_match('/javascript:|data:|<\?(?!xml\s)/i', $continut) === 1) {
            return 'are „javascript:”, „data:” sau o instrucțiune de procesare';
        }
        $doc = self::document($continut);
        if ($doc === null || !$doc->documentElement instanceof DOMElement) {
            return 'nu e XML corect';
        }
        $radacina = $doc->documentElement;
        if ($radacina->localName !== 'svg' || $radacina->namespaceURI !== 'http://www.w3.org/2000/svg') {
            return 'rădăcina nu e <svg> (cu xmlns-ul SVG)';
        }
        return self::verificaNodFisier($radacina);
    }

    private static function verificaNodFisier(DOMNode $nod): ?string
    {
        if ($nod->nodeType === XML_TEXT_NODE || $nod->nodeType === XML_COMMENT_NODE) {
            return null;
        }
        if ($nod->nodeType === XML_CDATA_SECTION_NODE) {
            return 'are o secțiune CDATA';
        }
        if (!$nod instanceof DOMElement) {
            return 'are un nod nepermis (' . $nod->nodeName . ')';
        }
        $element = strtolower((string) $nod->localName);
        if ($nod->namespaceURI !== 'http://www.w3.org/2000/svg' || !in_array($element, self::ELEMENTE_FISIER, true)) {
            return 'element nepermis: <' . $nod->nodeName . '>';
        }
        foreach ($nod->attributes ?? [] as $atribut) {
            $nume = strtolower((string) $atribut->localName);
            $valoare = (string) $atribut->value;
            $spatiu = (string) $atribut->namespaceURI;
            if ($spatiu !== '' && !in_array($spatiu, ['http://www.w3.org/1999/xlink', 'http://www.w3.org/XML/1998/namespace'], true)) {
                return 'atribut nepermis: ' . $atribut->nodeName;
            }
            if (str_starts_with($nume, 'on') || !in_array($nume, self::ATRIBUTE_FISIER, true)) {
                return 'atribut nepermis: ' . $atribut->nodeName;
            }
            if ($nume === 'href' && !str_starts_with($valoare, '#')) {
                return 'href în afara fișierului: ' . $valoare;
            }
            if (preg_match_all('/url\(\s*[\'"]?([^\'")]*)/i', $valoare, $m) > 0) {
                foreach ($m[1] as $tinta) {
                    if (!str_starts_with(trim($tinta), '#')) {
                        return 'url() în afara fișierului: ' . $valoare;
                    }
                }
            }
            if ($nume === 'style' && preg_match('/@import|expression\s*\(|behavior\s*:/i', $valoare) === 1) {
                return 'style nepermis: ' . $valoare;
            }
        }
        foreach ($nod->childNodes as $copil) {
            $problema = self::verificaNodFisier($copil);
            if ($problema !== null) {
                return $problema;
            }
        }
        return null;
    }

    private static function document(string $xml): ?DOMDocument
    {
        if (!class_exists(DOMDocument::class)) {
            return null;
        }
        $doc = new DOMDocument();
        $vechi = libxml_use_internal_errors(true);
        try {
            $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($vechi);
        }
        return $ok ? $doc : null;
    }
}
