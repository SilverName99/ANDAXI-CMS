<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Plata EuPlătesc: tranzacția și aprobarea EuPlătesc pe comenzi.
 *
 * Surse: scripts/install.php, SiteController::ensureStripeSchema.
 */
return [
    'descriere' => 'Plata EuPlătesc: tranzacția și aprobarea EuPlătesc pe comenzi',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        foreach ([
            'euplatesc_ep_id' => 'VARCHAR(64) NULL',
            'euplatesc_approval' => 'VARCHAR(64) NULL',
        ] as $coloana => $definitie) {
            $s->coloana('orders', $coloana, $definitie);
        }
    },
];
