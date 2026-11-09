<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Rambursările cotizațiilor (pluginul `membri`, decizia proprietarului din 9 noiembrie 2026): o plată a cotizației rambursată
 * din Stripe (`charge.refunded`) se vede în admin (Cereri conturi, cererea, lista de utilizatori); contul nu se dezactivează
 * singur, decide administratorul.
 *
 *  - `account_activations.refunded_cents`: suma rambursată, în bani (0 = nimic);
 *  - `account_activations.refunded_at`: prima rambursare.
 *
 * Coloane noi la coada tabelului (instant), cu implicite care dau rândurile de azi; codul vertij nu le are. Fără date.
 */
return [
    'descriere' => 'Cotizațiile: suma rambursată și momentul primei rambursări (charge.refunded)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('account_activations', 'refunded_cents', 'INT UNSIGNED NOT NULL DEFAULT 0');
        $s->coloana('account_activations', 'refunded_at', 'DATETIME DEFAULT NULL');
    },
];
