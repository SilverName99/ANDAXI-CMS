<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Precomenzile: limitele pe produse, starea precomenzii pe comenzi.
 *
 * Sursa: Precomanda::ensureSchema.
 */
return [
    'descriere' => 'Precomenzile: limitele pe produse, starea precomenzii pe comenzi',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        foreach ([
            'preorder_status' => 'VARCHAR(12) NULL',
            'preorder_released_at' => 'DATETIME NULL',
        ] as $coloana => $definitie) {
            $s->coloana('orders', $coloana, $definitie);
        }

        foreach ([
            'preorder_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'preorder_max_per_order' => 'INT UNSIGNED NULL',
            'preorder_max_total' => 'INT UNSIGNED NULL',
        ] as $coloana => $definitie) {
            $s->coloana('products', $coloana, $definitie);
        }
    },
];
