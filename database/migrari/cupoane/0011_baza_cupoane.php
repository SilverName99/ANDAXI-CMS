<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Cupoanele.
 *
 * Surse: database/schema.sql, AdminController::ensureCouponsSchema,
 * CheckoutCalculator::ensureCouponsSchema. Coloanele vechi (min_items, max_items) și numele gol le
 * convertește o dată 0022.
 */
return [
    'descriere' => 'Cupoanele',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('coupons', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name' => 'VARCHAR(190) NOT NULL',
            'code' => 'VARCHAR(100) NOT NULL',
            'type' => "ENUM('fixed','percent') NOT NULL DEFAULT 'fixed'",
            'value' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
            'product_ids_json' => 'LONGTEXT NULL',
            'category_ids_json' => 'LONGTEXT NULL',
            'min_items_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'max_items_count' => 'INT UNSIGNED NULL',
            'max_uses_total' => 'INT UNSIGNED NULL',
            'allowed_user_ids_json' => 'LONGTEXT NULL',
            'apply_only_selected_products' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'is_unique' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'used_at' => 'DATETIME NULL',
            'import_batch' => 'VARCHAR(190) NULL',
            'stacks_with_discounts' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'starts_at' => 'DATETIME NULL',
            'ends_at' => 'DATETIME NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY code (code)',
        ]);
    },
];
