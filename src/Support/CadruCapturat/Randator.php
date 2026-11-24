<?php

declare(strict_types=1);

namespace App\Support\CadruCapturat;

/**
 * Randatorul unei regiuni dinamice a unui document capturat (Regiuni; pista A a mutării unui site WooCommerce, docs/MAGAZIN-WC.md):
 * dă interiorul regiunii (sau valoarea atributului ei) în markupul site-ului capturat, din datele CMS-ului, după contextul cererii.
 *
 * Contractul:
 *  - ieșirea e HTML gata de pus în pagină: randatorul își scapă singur datele (Regiuni::html, Regiuni::attr, Regiuni::jsonAttr), iar
 *    cadrul n-o mai trece prin jetoane (un `{{ax:` din date rămâne text). Politica HTML-ului capturat (HtmlCapturat) o verifică pe
 *    ieșire dovada de la import și G-regiuni, nu fiecare cerere;
 *  - pe o regiune cu `cache` = `pagina` randatorul nu citește nimic din sesiune, deci documentul poate sta în cache: ContextRegiune::sesiune
 *    refuză, iar cât randează, Regiuni::randeaza îi dă un `$_SESSION` gol, desprins de sesiune (o scriere în el, o sesiune pornită sau
 *    închisă = EroareJeton, 500); pe una cu `sesiune`, documentul pleacă cu `Cache-Control: no-store, private`, ca orice document cu
 *    regiuni randat cu o sesiune pornită (CadruCapturat::raspunde);
 *  - dovada (la import și în teste): cu starea capturii (ContextRegiune::stare), ieșirea normalizată (normalizeaza) trebuie să fie
 *    octeții capturați normalizați la fel; altfel NU, cu primul octet diferit.
 */
interface Randator
{
    /** Interiorul regiunii (modul `interior`) sau valoarea atributului ei (modul `atribut`, fără ghilimele). */
    public function randeaza(ContextRegiune $ctx): string;

    /**
     * Normalizarea declarată a câmpurilor volatile ale regiunii (nonce-urile, cheile rândurilor coșului WooCommerce, id-urile
     * `quantity_<uniqid>`), aplicată la dovadă ambelor părți, după Captura\Normalizare. Fără câmpuri volatile: textul neschimbat.
     */
    public function normalizeaza(string $html): string;
}
