<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Cupoanele: numele gol și limitele din coloanele vechi, aduse o singură dată la forma de azi.
 *
 * Înlocuiește UPDATE-urile din AdminController::ensureCouponsSchema și
 * CheckoutCalculator::ensureCouponsSchema (la fiecare pagină de cupoane, la fiecare validare de cupon),
 * cu aceleași condiții: name = code unde numele e gol; min_items → min_items_count și max_items →
 * max_items_count, doar pe o bază care are coloanele vechi (o instalare nouă nu le are).
 */
return [
    'descriere' => 'Cupoanele: numele gol = codul; limitele din coloanele vechi min_items, max_items',
    'tip' => 'date',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->date('Numele gol al cupoanelor = codul lor', "UPDATE coupons SET name = code WHERE name IS NULL OR name = ''");
        $s->dacaColoana('coupons', 'min_items', static function (Schema $s): void {
            $s->date('Numărul minim de produse din coloana veche min_items',
                'UPDATE coupons SET min_items_count = COALESCE(min_items, 0) WHERE min_items_count = 0 AND min_items IS NOT NULL');
        });
        $s->dacaColoana('coupons', 'max_items', static function (Schema $s): void {
            $s->date('Numărul maxim de produse din coloana veche max_items',
                'UPDATE coupons SET max_items_count = max_items WHERE max_items_count IS NULL AND max_items IS NOT NULL');
        });
    },
];
