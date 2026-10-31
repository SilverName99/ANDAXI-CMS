<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Indecșii stocului (WP-32, MIG-19 din migrari.md): pagina Acasă arată produsele active epuizate (bifa „Stoc epuizat”) și
 * pe cele cu mai puțin de 5 bucăți, câte o interogare pe fiecare index.
 *
 * Numărul 0025 era rezervat (conținutul lui a intrat în 0005) și n-a fost folosit; e sub cel mai mare număr publicat, ceea ce
 * garda migrărilor acceptă (docs/MIGRARI.md, „Garda”): pe o bază nouă rulează în ordinea numerelor, pe una existentă după
 * cele deja rulate, și nu depinde de nicio migrare mai nouă decât catalog/0002.
 *
 * Doar indecși, fără date schimbate: codul de dinainte merge la fel pe schema nouă.
 */
return [
    'descriere' => 'Produsele: indecșii stocului (pagina Acasă)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->index('products', 'idx_products_stoc', ['is_active', 'stock']);
        $s->index('products', 'idx_products_epuizat', ['out_of_stock']);
    },
];
