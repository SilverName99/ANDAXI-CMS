<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Transportul gratuit pe produs (WP-20: C14a, din Biovitality 2b8a618 și a14df64): `products.free_shipping`. Un singur
 * produs bifat în coș face toată comanda fără transport, peste pragul de valoare și peste tariful curierului (doar
 * transportul pus de mână pe comandă, `orders.shipping_manual = 1`, îl bate, în recalculările din admin).
 *
 * Adoptivă: codul Biovitality face aceeași coloană, cu aceeași definiție (AdminController::ensureOptionalSchema,
 * SiteController::ensureProductCustomSchema), deci pe baza lui migrarea o găsește și nu face nimic. Implicit 0: pe o
 * bază fără produse bifate calculele rămân exact cele de dinainte. Codul de dinainte merge pe schema nouă
 * (coloană la coadă, cu valoare implicită); codul nou o citește într-o interogare separată, care tace cât timp
 * migrarea n-a rulat.
 */
return [
    'descriere' => 'Produsele: transportul gratuit pe produs',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('products', 'free_shipping', 'TINYINT(1) NOT NULL DEFAULT 0');
    },
];
