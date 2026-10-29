<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Produsele promoționale date pe comenzi (WP-21: C13, din Biovitality 657c0cf): când și de cine a fost dat fiecare
 * (`given_at`, `given_by`, emailul adminului). `created_at` nu poate răspunde: selecția se rescrie la fiecare salvare a
 * comenzii, deci s-ar reseta la prima corectură; `given_at` se păstrează peste re-salvări (AdminController::orderPromoSave).
 *
 * Adoptivă: codul Biovitality face aceleași coloane, cu aceleași definiții, deci pe baza lui migrarea le găsește și nu
 * face nimic. Acordările de dinainte rămân fără valoare (exportul arată acolo data comenzii, marcată ca atare). Codul de
 * dinainte merge pe schema nouă (coloane la coadă, NULL); codul nou le scrie doar după migrare.
 */
return [
    'descriere' => 'Produsele promoționale date pe comenzi: când și de cine',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('order_promo_items', 'given_at', 'DATETIME NULL');
        $s->coloana('order_promo_items', 'given_by', 'VARCHAR(190) NULL');
    },
];
