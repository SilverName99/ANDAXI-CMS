<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\Continut;

use App\Support\ImportWordPress\Context;

/**
 * Cum devine conținutul unei pagini WordPress pagina codului comun (`pages.html_content`, `css_content`, `js_content`).
 * Fiecare constructor de pagini are convertorul lui (Context::convertor alege după `Inventar::constructor`); unul care
 * lipsește face planul să spună NU, deci un import nu pornește niciodată cu pagini pe care nu le știe converti.
 */
interface Convertor
{
    /** Numele convertorului, pentru plan și raport. */
    public function nume(): string;

    /** Poate converti pagini făcute cu acest constructor („divi”, „gutenberg”, „clasic”…)? */
    public function stie(string $constructor): bool;

    /**
     * Conținutul paginii pe codul comun. Adresele vechi se rescriu cu $ctx->adrese, fișierele din uploads/ pomenite se
     * spun cu $ctx->fisier() (ca să fie copiate), iar ce nu se poate converti se spune în `probleme` (oprește importul)
     * sau `avertismente` (doar arătate).
     *
     * @param array<string, mixed> $pagina o pagină din Inventar::$pagini
     * @return array{html: string, css: string, js: string, probleme: list<string>, avertismente: list<string>}
     */
    public function converteste(array $pagina, Context $ctx): array;

    /**
     * Antetul, meniul și subsolul site-ului (setările design_header_html, design_menu_html, design_footer_html și
     * foile/scripturile lor), dacă acest convertor le face; [] = le face importul din meniurile WordPress (Importator).
     *
     * @return array<string, string>
     */
    public function design(Context $ctx): array;
}
