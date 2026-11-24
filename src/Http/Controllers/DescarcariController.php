<?php

declare(strict_types=1);

namespace App\Http\Controllers;

/**
 * Descărcarea unui fișier cumpărat (pluginul `descarcari`; docs/MAGAZIN-WC.md, pista I): adresa WooCommerce
 * `/?download_file=<produs>&order=<cheia comenzii>&uid=…|email=…&key=<id-ul descărcării>`, primită de CompatWp\Interogari.
 * Până la pista I răspunde 404, ca orice potrivire greșită (fără oracol).
 */
final class DescarcariController
{
    use PistaNeconstruita;

    public function descarca(): void
    {
        $this->paginaNeconstruita();
    }
}
