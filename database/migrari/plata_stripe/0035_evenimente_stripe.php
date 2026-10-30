<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Evenimentele Stripe primite (WP-25: VT-X1, App\Support\StripeEvenimente): un rând pe eveniment, cheia fiind id-ul
 * lui Stripe. Un eveniment retrimis de Stripe (o întârziere, un 500 de dinainte) nu se mai procesează a doua oară;
 * Setări plăți arată ultimul eveniment primit pe fiecare tip. Fără date personale: doar id-urile Stripe, tipul,
 * versiunea API, proprietarul (pluginul) și rezultatul.
 *
 * Tabel nou pe toate site-urile (decizia OD-02); gol la început, deci nu schimbă nimic din ce se vede. Codul de
 * dinainte nu-l folosește.
 */
return [
    'descriere' => 'Evenimentele webhook-ului Stripe (stripe_events)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('stripe_events', [
            'event_id' => 'VARCHAR(255) NOT NULL',
            'type' => 'VARCHAR(100) NOT NULL',
            'api_version' => 'VARCHAR(40) NULL',
            'livemode' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'object_id' => 'VARCHAR(255) NULL',
            'proprietar' => 'VARCHAR(40) NULL',
            'primit_la' => 'DATETIME NOT NULL',
            'procesat_la' => 'DATETIME NULL',
            'rezultat' => 'VARCHAR(255) NULL',
        ], [
            'PRIMARY KEY (event_id)',
            'KEY idx_stripe_events_tip (type, primit_la)',
        ]);
    },
];
