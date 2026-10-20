<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Fidelitatea: punctele clienților, tranzacțiile și regulile de puncte, punctele pe comenzi.
 *
 * Sursa: LoyaltyService::ensureSchema (plus database/schema.sql, scripts/install.php).
 */
return [
    'descriere' => 'Fidelitatea: punctele clienților, tranzacțiile și regulile de puncte, punctele pe comenzi',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('loyalty_points_transactions', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'user_id' => 'INT UNSIGNED NOT NULL',
            'order_id' => 'INT UNSIGNED NULL',
            'admin_id' => 'INT UNSIGNED NULL',
            'tx_type' => 'VARCHAR(50) NOT NULL',
            'points_delta' => 'INT NOT NULL',
            'balance_after' => 'INT NOT NULL',
            'reason' => 'VARCHAR(255) NULL',
            'meta_json' => 'LONGTEXT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_order_type (order_id, tx_type)',
            'KEY idx_user_created (user_id, created_at)',
            'UNIQUE KEY uniq_user_order_type (user_id, order_id, tx_type)',
        ]);

        $s->tabelComplet('loyalty_points_rules', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name' => 'VARCHAR(190) NOT NULL',
            'description' => 'VARCHAR(255) NULL',
            'multiplier' => 'DECIMAL(6,2) NOT NULL DEFAULT 1.00',
            'min_order_total' => 'DECIMAL(10,2) NULL',
            'category_id' => 'INT UNSIGNED NULL',
            'starts_at' => 'DATETIME NULL',
            'ends_at' => 'DATETIME NULL',
            'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_rules_active_window (is_active, starts_at, ends_at)',
        ]);

        foreach ([
            'loyalty_points_used' => 'INT NOT NULL DEFAULT 0',
            'loyalty_points_discount' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
            'loyalty_points_awarded' => 'INT NOT NULL DEFAULT 0',
            'loyalty_points_pending_claim' => 'INT NOT NULL DEFAULT 0',
            'loyalty_points_awarded_at' => 'DATETIME NULL',
        ] as $coloana => $definitie) {
            $s->coloana('orders', $coloana, $definitie);
        }

        foreach ([
            'loyalty_points' => 'INT NOT NULL DEFAULT 0',
        ] as $coloana => $definitie) {
            $s->coloana('users', $coloana, $definitie);
        }
    },
];
