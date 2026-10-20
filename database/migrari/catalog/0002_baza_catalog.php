<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Catalogul: produsele, categoriile, câmpurile extra și șabloanele paginii de produs.
 *
 * Fără coloanele precomenzii (0014) și ale ofertelor BBD (0015). Surse: database/schema.sql,
 * scripts/install.php, AdminController::ensureOptionalSchema, SiteController::ensureProductCustomSchema,
 * CheckoutCalculator::ensureProductVatSchema, ProductCategories::ensureSchema.
 *
 * O instalare nouă nu primește products.gallery_json (galeria veche, pe care install.php o crea la fiecare
 * instalare); pe un site care o are, rămâne, iar 0021 o copiază o dată în gallery_images_json. TVA-ul
 * implicit al produselor rămâne cel de azi (19.00): îl schimbă doar o acțiune explicită.
 */
return [
    'descriere' => 'Catalogul: produsele, categoriile, câmpurile extra și șabloanele paginii de produs',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('products', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name' => 'VARCHAR(190) NOT NULL',
            'sku' => 'VARCHAR(80) NULL',
            'category' => 'VARCHAR(120) NULL',
            'category_id' => 'INT UNSIGNED NULL',
            'product_template_id' => 'INT UNSIGNED NULL',
            'slug' => 'VARCHAR(190) NOT NULL',
            'short_description' => 'TEXT NULL',
            'description' => 'LONGTEXT NULL',
            'product_highlights' => 'TEXT NULL',
            'price' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
            'vat_percent' => 'DECIMAL(5,2) NOT NULL DEFAULT 19.00',
            'vat_included' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'sale_price' => 'DECIMAL(10,2) NULL',
            'sale_price_periods_json' => 'LONGTEXT NULL',
            'discount_badge_mode' => "VARCHAR(10) NOT NULL DEFAULT 'percent'",
            'post_cart_note_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'post_cart_note_text' => 'TEXT NULL',
            'stock' => 'INT NOT NULL DEFAULT 0',
            'out_of_stock' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'weight_grams' => 'INT NULL',
            'image_url' => 'TEXT NULL',
            'gallery_images_json' => 'LONGTEXT NULL',
            'similar_products_json' => 'LONGTEXT NULL',
            'badge_popular' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'badge_best_seller' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'badge_seasonal' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'deleted_at' => 'DATETIME NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            'brand' => 'VARCHAR(120) NULL',
            'tags_json' => 'TEXT NULL',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY slug (slug)',
        ]);

        $s->tabelComplet('product_categories', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name' => 'VARCHAR(120) NOT NULL',
            'slug' => 'VARCHAR(140) NOT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY name (name)',
            'UNIQUE KEY slug (slug)',
        ]);

        $s->tabelComplet('product_category_links', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'product_id' => 'INT UNSIGNED NOT NULL',
            'category_id' => 'INT UNSIGNED NOT NULL',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_pcl_category (category_id)',
            'UNIQUE KEY uniq_product_category (product_id, category_id)',
        ]);

        $s->tabelComplet('product_extra_fields', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name' => 'VARCHAR(190) NOT NULL',
            'field_key' => 'VARCHAR(120) NOT NULL',
            'field_type' => "VARCHAR(30) NOT NULL DEFAULT 'textarea'",
            'placeholder' => 'VARCHAR(255) NULL',
            'default_value' => 'LONGTEXT NULL',
            'is_required' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'sort_order' => 'INT NOT NULL DEFAULT 0',
            'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY field_key (field_key)',
        ]);

        $s->tabelComplet('product_extra_field_values', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'product_id' => 'INT UNSIGNED NOT NULL',
            'field_id' => 'INT UNSIGNED NOT NULL',
            'value' => 'LONGTEXT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_product_field (product_id, field_id)',
        ]);

        $s->tabelComplet('product_templates', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name' => 'VARCHAR(190) NOT NULL',
            'slug' => 'VARCHAR(190) NOT NULL',
            'description' => 'VARCHAR(255) NULL',
            'html_content' => 'LONGTEXT NOT NULL',
            'css_content' => 'LONGTEXT NULL',
            'js_content' => 'LONGTEXT NULL',
            'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY slug (slug)',
        ]);

        $s->tabelComplet('product_template_fields', [
            'template_id' => 'INT UNSIGNED NOT NULL',
            'field_id' => 'INT UNSIGNED NOT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (template_id, field_id)',
        ]);
    },
];
