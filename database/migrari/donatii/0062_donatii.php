<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Donațiile (pluginul `donatii`, din vertij 32db865 … 8412906 și dc52498 … ca1120c; docs/PLUGINURI.md, „Donații”):
 *
 *  - `donations`: o donație unică sau o lună dintr-o donație lunară (abonamentul Stripe), cu donatorul, suma în bani (cu
 *    comisionul acoperit de donator), destinația, starea plății, legăturile Stripe (PaymentIntent, abonament, client, factura
 *    lunii: indexul unic pe `stripe_invoice_id` oprește dublurile când Stripe retrimite o factură), chitanța, contul
 *    donatorului (`user_id`) și momentul opririi abonamentului (`cancelled_at`).
 *
 * Adoptivă: definițiile sunt exact cele pe care le face codul vertij la 9ab7999 (`DonationService::ensureSchema`, cu
 * coloanele și indexurile adăugate după crearea tabelului; tools/dev/migrari/vechi/vt.sql), deci pe baza vertij-tinitus.ro
 * tabelul există cu tot cu rânduri și migrarea îl găsește („detectata”) fără să-l atingă. Pe celelalte site-uri tabelul apare
 * gol și nu-l folosește nimic cât pluginul nu e în licență. Fără date în migrare.
 */
return [
    'descriere' => 'Donațiile: tabelul donations, cu abonamentele lunare și facturile lor (donatii)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('donations', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'reference' => 'VARCHAR(40) NOT NULL',
            'donor_name' => 'VARCHAR(190) DEFAULT NULL',
            'donor_email' => 'VARCHAR(190) DEFAULT NULL',
            'amount_cents' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'fee_cents' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'currency' => "VARCHAR(10) NOT NULL DEFAULT 'ron'",
            'frequency' => "ENUM('once','monthly') NOT NULL DEFAULT 'once'",
            'designation' => 'VARCHAR(190) DEFAULT NULL',
            'cover_fees' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'newsletter_optin' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'consent' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'status' => "VARCHAR(30) NOT NULL DEFAULT 'pending'",
            'stripe_payment_intent_id' => 'VARCHAR(255) DEFAULT NULL',
            'stripe_subscription_id' => 'VARCHAR(255) DEFAULT NULL',
            'stripe_customer_id' => 'VARCHAR(255) DEFAULT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'paid_at' => 'DATETIME DEFAULT NULL',
            'user_id' => 'INT UNSIGNED DEFAULT NULL',
            'stripe_invoice_id' => 'VARCHAR(255) DEFAULT NULL',
            'cancelled_at' => 'DATETIME DEFAULT NULL',
            'receipt_url' => 'VARCHAR(500) DEFAULT NULL',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY reference (reference)',
            'UNIQUE KEY idx_donations_invoice (stripe_invoice_id)',
            'KEY idx_donations_pi (stripe_payment_intent_id)',
            'KEY idx_donations_status (status)',
            'KEY idx_donations_user (user_id)',
            'KEY idx_donations_sub (stripe_subscription_id)',
            'KEY idx_donations_email (donor_email)',
        ]);
    },
];
