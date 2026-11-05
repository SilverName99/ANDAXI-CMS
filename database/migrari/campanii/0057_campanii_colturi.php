<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * „Colțurile cardurilor: rotunjite / drepte” (pluginul `campanii`, codul `{{campanie_produse}}`, App\Support\Campanii::
 * htmlProduse; docs/PLUGINURI.md, „Campanii pe site”): `campanii.carduri_drepte`, 1 = cardul, cutia pozei și butoanele fără
 * rotunjire. 0 implicit = rotunjite, aspectul de până acum. Coloană la coadă: codul de dinainte merge pe schema nouă.
 */
return [
    'descriere' => 'Campaniile: colțurile cardurilor produselor (campanii)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('campanii', 'carduri_drepte', 'TINYINT(1) NOT NULL DEFAULT 0');
    },
];
