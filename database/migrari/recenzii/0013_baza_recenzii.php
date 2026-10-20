<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Recenziile produselor.
 *
 * Surse: database/schema.sql, scripts/install.php, AdminController::ensureOptionalSchema,
 * SiteController::ensureProductCustomSchema. O instalare nouă nu primește tabelul vechi reviews; pe un
 * site care îl are, rămâne, iar 0023 îl copiază o singură dată în product_reviews.
 */
return [
    'descriere' => 'Recenziile produselor',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('product_reviews', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'product_id' => 'INT UNSIGNED NOT NULL',
            'user_name' => 'VARCHAR(120) NOT NULL',
            'user_email' => 'VARCHAR(190) NULL',
            'rating' => 'TINYINT UNSIGNED NOT NULL DEFAULT 5',
            'review_text' => 'TEXT NULL',
            'is_approved' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'source' => 'VARCHAR(50) NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_product_reviews_approved (is_approved)',
            'KEY idx_product_reviews_product (product_id)',
            'CONSTRAINT fk_product_reviews_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE',
        ]);
    },
];
