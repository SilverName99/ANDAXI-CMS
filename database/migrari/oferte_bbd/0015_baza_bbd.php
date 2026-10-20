<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Ofertele BBD: ofertele pe produse, lotul pe liniile comenzilor.
 *
 * Surse: AdminController::ensureOptionalSchema, SiteController::ensureProductCustomSchema.
 */
return [
    'descriere' => 'Ofertele BBD: ofertele pe produse, lotul pe liniile comenzilor',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        foreach ([
            'bbd_key' => 'VARCHAR(64) NULL',
        ] as $coloana => $definitie) {
            $s->coloana('order_items', $coloana, $definitie);
        }

        foreach ([
            'bbd_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'bbd_entries_json' => 'LONGTEXT NULL',
        ] as $coloana => $definitie) {
            $s->coloana('products', $coloana, $definitie);
        }
    },
];
