<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Produsele promoționale și cele date pe comenzi.
 *
 * Sursa: AdminController::ensurePromoSchema.
 */
return [
    'descriere' => 'Produsele promoționale și cele date pe comenzi',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('promotional_products', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name' => 'VARCHAR(190) NOT NULL',
            'notes' => 'VARCHAR(500) NULL',
            'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'sort_order' => 'INT NOT NULL DEFAULT 0',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
        ]);

        $s->tabelComplet('order_promo_items', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'order_id' => 'INT UNSIGNED NOT NULL',
            'promo_product_id' => 'INT UNSIGNED NULL',
            'name' => 'VARCHAR(190) NOT NULL',
            'quantity' => 'INT UNSIGNED NOT NULL DEFAULT 1',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_opi_order (order_id)',
            'KEY idx_opi_promo (promo_product_id)',
        ]);
    },
];
