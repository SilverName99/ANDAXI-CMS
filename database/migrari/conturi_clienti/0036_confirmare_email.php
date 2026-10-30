<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Confirmarea adresei de email a conturilor de client (WP-25: VT-C9, decizia OD-19; regula comenzilor fără cont, G-M4):
 *
 *  - `users.email_confirmed_at`: când a dovedit clientul că adresa e a lui (linkul de confirmare, un link de resetare a
 *    parolei folosit, o logare cu Google); NULL = nedovedită. Comenzile plasate fără cont cu aceeași adresă se văd în
 *    cont (și îi completează adresa de facturare) doar după confirmare;
 *  - `customer_email_confirmations`: linkurile trimise (doar hash-ul sha256 al tokenului, valabile 7 zile, o singură
 *    folosire); `scop` = „inregistrare” (contul creat cât confirmarea e cerută la înregistrare; doar el oprește logarea
 *    până la confirmare) sau „comenzi” (cerut din cont, ca să apară comenzile fără cont).
 *
 * Pe un site vechi coloana vine NULL la toate conturile: nimeni nu e blocat (cererea confirmării la logare e o setare,
 * `customer_email_confirmation_required`, oprită implicit), dar comenzile vechi fără cont se văd după o confirmare
 * (OD-47, în ce-se-schimba.md). Codul de dinainte merge pe schema nouă (coloană la coadă, NULL; tabel nou).
 */
return [
    'descriere' => 'Confirmarea emailului clienților (users.email_confirmed_at, customer_email_confirmations)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('users', 'email_confirmed_at', 'DATETIME NULL');
        $s->tabelComplet('customer_email_confirmations', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'user_id' => 'INT UNSIGNED NOT NULL',
            'token_hash' => 'CHAR(64) NOT NULL',
            'scop' => "VARCHAR(20) NOT NULL DEFAULT 'inregistrare'",
            'created_at' => 'DATETIME NOT NULL',
            'used_at' => 'DATETIME NULL',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_customer_email_confirmations_token (token_hash)',
            'KEY idx_customer_email_confirmations_user (user_id)',
        ]);
    },
];
