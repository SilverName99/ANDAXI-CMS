<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Conținutul unui pachet de produse (pluginul `pachete_produse`, din Biovitality 90afd46 și 83067d3; App\Support\PacheteProduse;
 * docs/PLUGINURI.md, „Pachete de produse”): `products.bundle_items_json`, lista produselor din magazin care intră în pachet, în
 * ordinea din editor: `[{"id": <produsul>, "qty": "<cantitatea, text>", "desc": "<descrierea proprie, opțională>"}]`. Numele,
 * poza și descrierea scurtă se citesc din produsul ales la fiecare afișare.
 *
 * Adoptivă: codul Biovitality face aceeași coloană, cu aceeași definiție (AdminController::ensureOptionalSchema,
 * scripts/install.php), deci pe baza lui migrarea o găsește și nu face nimic; pachetele de acolo rămân. Pe celelalte site-uri
 * coloana e goală (NULL): niciun produs nu e pachet, deci nimic nu se schimbă. Codul de dinainte merge pe schema nouă (o coloană
 * la coadă, cu NULL implicit).
 */
return [
    'descriere' => 'Produsele: conținutul pachetelor (pachete_produse)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('products', 'bundle_items_json', 'LONGTEXT NULL');
    },
];
