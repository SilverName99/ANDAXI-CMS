<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Conturile clienților: utilizatorii, adresele, resetările de parolă.
 *
 * Fără punctele de fidelitate (0012). Surse: database/schema.sql, scripts/install.php,
 * SiteController::ensureCustomerSchema, AdminController::ensureOptionalSchema (data nașterii, genul). Stă
 * înaintea magazinului (0004): orders are o cheie străină spre users, iar cheile străine se fac doar odată
 * cu tabelul.
 */
return [
    'descriere' => 'Conturile clienților: utilizatorii, adresele, resetările de parolă',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('users', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'first_name' => 'VARCHAR(100) NOT NULL',
            'last_name' => 'VARCHAR(100) NOT NULL',
            'email' => 'VARCHAR(190) NOT NULL',
            'phone' => 'VARCHAR(30) NULL',
            'birth_date' => 'DATE NULL',
            'gender' => 'VARCHAR(20) NULL',
            'password_hash' => 'VARCHAR(255) NOT NULL',
            'google_id' => 'VARCHAR(120) NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY email (email)',
            'UNIQUE KEY uniq_users_google_id (google_id)',
        ]);

        $s->tabelComplet('user_addresses', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'user_id' => 'INT UNSIGNED NOT NULL',
            'label' => 'VARCHAR(100) NULL',
            'full_name' => 'VARCHAR(190) NOT NULL',
            'phone' => 'VARCHAR(30) NULL',
            'street' => 'VARCHAR(190) NULL',
            'street_no' => 'VARCHAR(40) NULL',
            'address_line1' => 'VARCHAR(255) NOT NULL',
            'address_line2' => 'VARCHAR(255) NULL',
            'city' => 'VARCHAR(120) NOT NULL',
            'county' => 'VARCHAR(120) NOT NULL',
            'postcode' => 'VARCHAR(20) NULL',
            'is_default' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'KEY fk_user_addresses_user (user_id)',
            'CONSTRAINT fk_user_addresses_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE',
        ]);

        $s->tabelComplet('customer_password_resets', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'email' => 'VARCHAR(190) NOT NULL',
            'token_hash' => 'VARCHAR(128) NOT NULL',
            'expires_at' => 'DATETIME NOT NULL',
            'used_at' => 'DATETIME NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY token_hash (token_hash)',
        ]);
    },
];
