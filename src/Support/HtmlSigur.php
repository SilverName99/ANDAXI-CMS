<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Curățarea unui fragment HTML scris în admin, după o listă albă (revizuirea WP-37/38): textul de deasupra listelor
 * (`intro_html`). Fragmentul se citește cu DOMDocument (deci și entitățile, `&#106;avascript:`, sunt decodate înainte de
 * verificare), apoi rămân doar:
 *
 *  - etichetele din `ETICHETE` (paragrafe, titluri h2–h6, liste, linkuri, poze, tabele simple, citate, sublinieri); orice
 *    altă etichetă se scoate, iar textul ei rămâne, în afară de cele din `CU_TOT_CU_CONTINUT` (script, style, iframe, svg,
 *    math, formulare…), care pleacă împreună cu ce au înăuntru;
 *  - atributele din `ATRIBUTE` (pe etichetă) și `class` (doar litere, cifre, „-”, „_”, spații); niciun `on…`, niciun `style`;
 *  - adresele (`href`, `src`) relative sau cu schema `http`, `https`, `mailto`, `tel` (`src` doar http, https sau relativă);
 *    orice altă schemă (`javascript:`, `data:`, `vbscript:`…) scoate atributul; un link `target="_blank"` primește
 *    `rel="noopener noreferrer"`.
 *
 * Fără dependențe noi (ext-dom e în PHP-ul serverelor). Folosită la scriere (Seo::curataIntro) și la afișare
 * (SiteController::introLista), deci și un rând scris altfel (o unealtă, o copie) iese curățat.
 */
final class HtmlSigur
{
    private const ETICHETE = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'small', 'sub', 'sup', 'span', 'mark', 'blockquote',
        'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'a', 'img', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tr',
        'th', 'td', 'caption', 'div'];

    private const CU_TOT_CU_CONTINUT = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'svg', 'math',
        'template', 'noscript', 'form', 'input', 'button', 'select', 'option', 'textarea', 'link', 'meta', 'base', 'title', 'head',
        'audio', 'video', 'source', 'track', 'canvas', 'portal', 'xml', 'param'];

    /** @var array<string, list<string>> */
    private const ATRIBUTE = [
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height', 'loading', 'title'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan', 'scope'],
        'ol' => ['start'],
    ];

    private const SCHEME_LINK = ['http', 'https', 'mailto', 'tel'];
    private const SCHEME_POZA = ['http', 'https'];

    public static function curata(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $vechi = libxml_use_internal_errors(true);
        $doc->loadHTML('<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="ax-radacina">'
            . $html . '</div></body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($vechi);
        $radacina = $doc->getElementById('ax-radacina');
        if (!$radacina instanceof DOMElement) {
            return '';
        }
        self::curataCopiii($radacina);
        $rez = '';
        foreach (iterator_to_array($radacina->childNodes) as $copil) {
            $rez .= (string) $doc->saveHTML($copil);
        }
        return trim($rez);
    }

    private static function curataCopiii(DOMNode $parinte): void
    {
        foreach (iterator_to_array($parinte->childNodes) as $nod) {
            if ($nod->nodeType === XML_COMMENT_NODE || $nod->nodeType === XML_PI_NODE || $nod->nodeType === XML_CDATA_SECTION_NODE) {
                $parinte->removeChild($nod);
                continue;
            }
            if (!$nod instanceof DOMElement) {
                continue;
            }
            $eticheta = strtolower($nod->nodeName);
            if (in_array($eticheta, self::CU_TOT_CU_CONTINUT, true) || str_contains($eticheta, ':')) {
                $parinte->removeChild($nod);
                continue;
            }
            self::curataCopiii($nod);
            if (!in_array($eticheta, self::ETICHETE, true)) {
                // O etichetă necunoscută pleacă, textul și copiii ei (deja curățați) rămân în locul ei.
                while ($nod->firstChild !== null) {
                    $parinte->insertBefore($nod->firstChild, $nod);
                }
                $parinte->removeChild($nod);
                continue;
            }
            self::curataAtribute($nod, $eticheta);
        }
    }

    private static function curataAtribute(DOMElement $el, string $eticheta): void
    {
        $permise = self::ATRIBUTE[$eticheta] ?? [];
        foreach (iterator_to_array($el->attributes) as $atribut) {
            $nume = strtolower($atribut->nodeName);
            $valoare = (string) $atribut->nodeValue;
            $pastreaza = match (true) {
                $nume === 'class' => preg_match('/^[A-Za-z0-9 _-]{0,200}$/', $valoare) === 1,
                !in_array($nume, $permise, true) => false,
                $nume === 'href' => self::adresaBuna($valoare, self::SCHEME_LINK),
                $nume === 'src' => self::adresaBuna($valoare, self::SCHEME_POZA),
                $nume === 'target' => $valoare === '_blank',
                in_array($nume, ['width', 'height', 'colspan', 'rowspan', 'start'], true) => preg_match('/^\d{1,4}$/', $valoare) === 1,
                $nume === 'loading' => in_array($valoare, ['lazy', 'eager'], true),
                $nume === 'scope' => in_array($valoare, ['row', 'col'], true),
                default => true,
            };
            if (!$pastreaza) {
                $el->removeAttribute($atribut->nodeName);
            }
        }
        if ($eticheta === 'a' && $el->getAttribute('target') === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /**
     * O adresă dintr-un atribut (deja decodată de DOM): relativă sau cu o schemă din listă. Spațiile și caracterele de
     * control dinaintea și dinăuntrul schemei nu contează pentru browser („java\tscript:”), deci nici aici.
     *
     * @param list<string> $scheme
     */
    public static function adresaBuna(string $adresa, array $scheme): bool
    {
        $fara = (string) preg_replace('/[\x00-\x20\x7f]+/', '', $adresa);
        if ($fara === '') {
            return false;
        }
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $fara, $m) === 1) {
            return in_array(strtolower($m[1]), $scheme, true);
        }
        // Fără schemă: o cale, un fragment, o interogare (sau „//gazdă”, tot http/https). Un „:” înainte de primul „/”,
        // „?” sau „#” ar fi o schemă scrisă ciudat: refuzată.
        $pana = strcspn($fara, '/?#');
        return !str_contains(substr($fara, 0, $pana), ':');
    }
}
