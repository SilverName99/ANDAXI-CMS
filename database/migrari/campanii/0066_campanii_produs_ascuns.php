<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Produsul ascuns din lista magazinului, arătat totuși pe pagina campaniei (pluginul `campanii`, cerut pentru Biovitality,
 * 12 noiembrie 2026; docs/PLUGINURI.md, „Campanii pe site”):
 *
 *  - `products.campanie_arata_ascuns`: 1 = produsul bifat „Ascuns din lista magazinului” apare totuși în grila
 *    `{{campanie_produse}}` a paginii campaniei (dacă e ales în campanie). 0 (implicit) = ca înainte, nu apare.
 *
 * Coloană la coadă, cu implicitul care dă rândurile de azi: codul de dinainte merge pe schema nouă. Fără date.
 */
return [
    'descriere' => 'Campaniile: produsul ascuns din lista magazinului arătat totuși pe pagina campaniei (campanii)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('products', 'campanie_arata_ascuns', 'TINYINT(1) NOT NULL DEFAULT 0');
    },
];
