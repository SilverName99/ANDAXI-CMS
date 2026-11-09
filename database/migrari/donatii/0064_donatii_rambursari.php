<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Rambursările donațiilor (pluginul `donatii`, decizia proprietarului din 9 noiembrie 2026, după revizuirea de securitate a
 * portului vertij): evenimentul Stripe `charge.refunded` notează pe rândul donației cât s-a dat înapoi.
 *
 *  - `donations.refunded_cents`: suma rambursată, în bani (0 = nimic; egală cu `amount_cents` = rambursată întreagă, iar
 *    starea devine `refunded`; mai mică = rambursare parțială, starea rămâne `paid`);
 *  - `donations.refunded_at`: prima rambursare.
 *
 * Coloane noi la coada tabelului (instant), cu implicite care dau rândurile de azi (nicio rambursare); codul vertij nu le are.
 * Fără date în migrare.
 */
return [
    'descriere' => 'Donațiile: suma rambursată și momentul primei rambursări (charge.refunded)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('donations', 'refunded_cents', 'INT UNSIGNED NOT NULL DEFAULT 0');
        $s->coloana('donations', 'refunded_at', 'DATETIME DEFAULT NULL');
    },
];
