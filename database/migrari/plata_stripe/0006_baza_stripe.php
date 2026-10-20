<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Plata Stripe: sesiunea și plata Stripe pe comenzi.
 *
 * Surse: scripts/install.php, SiteController::ensureStripeSchema.
 */
return [
    'descriere' => 'Plata Stripe: sesiunea și plata Stripe pe comenzi',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        foreach ([
            'stripe_session_id' => 'VARCHAR(255) NULL',
            'stripe_payment_intent_id' => 'VARCHAR(255) NULL',
        ] as $coloana => $definitie) {
            $s->coloana('orders', $coloana, $definitie);
        }
    },
];
