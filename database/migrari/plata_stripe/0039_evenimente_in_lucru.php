<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Revizuirea WP-25: `stripe_events.in_lucru_la`, când a luat o cerere evenimentul în lucru. Două livrări ale aceluiași
 * eveniment în aceeași clipă: doar cea care îl ia (UPDATE … WHERE in_lucru_la IS NULL sau mai vechi de 5 minute) îl
 * procesează (App\Support\StripeEvenimente::proceseaza); una oprită la jumătate se reia după 5 minute.
 */
return [
    'descriere' => 'Evenimentele Stripe luate în lucru (stripe_events.in_lucru_la)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('stripe_events', 'in_lucru_la', 'DATETIME NULL');
    },
];
