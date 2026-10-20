<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Magazinul: comenzile, liniile comenzilor, emailurile comenzilor, coșurile abandonate.
 *
 * Fără coloanele livrării FAN, ale plăților, ERP-ului, fidelității, precomenzii și newsletterului: le
 * adaugă migrările pluginurilor lor. Surse: database/schema.sql, scripts/install.php,
 * CheckoutCalculator::ensureOrderShippingSchema, AdminController::ensureOptionalSchema (firmă, reducerea
 * manuală, sursa reclamei), ErpSync::ensureSchema (suma plătită, notele, deblocarea corecturii),
 * BtIpayPayments::migreaza (paid_at, payment_error), EmailAutomation::ensureSchema.
 */
return [
    'descriere' => 'Magazinul: comenzile, liniile comenzilor, emailurile comenzilor, coșurile abandonate',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('orders', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'order_number' => 'VARCHAR(50) NOT NULL',
            'user_id' => 'INT UNSIGNED NULL',
            'status' => "VARCHAR(50) NOT NULL DEFAULT 'pending'",
            'payment_method' => "VARCHAR(50) NOT NULL DEFAULT 'euplatesc'",
            'payment_status' => "VARCHAR(50) NOT NULL DEFAULT 'unpaid'",
            'paid_at' => 'DATETIME NULL',
            'payment_error' => 'TEXT NULL',
            'shipping_method' => "VARCHAR(50) NOT NULL DEFAULT 'fan_courier'",
            'shipping_cost' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
            'shipping_manual' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'discount_total' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
            'coupon_code' => 'VARCHAR(100) NULL',
            'subtotal' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
            'total' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
            'billing_first_name' => 'VARCHAR(100) NOT NULL',
            'billing_last_name' => 'VARCHAR(100) NOT NULL',
            'billing_phone' => 'VARCHAR(30) NOT NULL',
            'billing_email' => 'VARCHAR(190) NOT NULL',
            'billing_address_line1' => 'VARCHAR(255) NOT NULL',
            'billing_address_line2' => 'VARCHAR(255) NULL',
            'billing_city' => 'VARCHAR(120) NOT NULL',
            'billing_county' => 'VARCHAR(120) NOT NULL',
            'billing_postcode' => 'VARCHAR(20) NOT NULL',
            'billing_is_company' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'billing_company_name' => 'VARCHAR(255) NULL',
            'billing_company_tax_id' => 'VARCHAR(120) NULL',
            'billing_company_registration_no' => 'VARCHAR(120) NULL',
            'shipping_same_as_billing' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'shipping_first_name' => 'VARCHAR(190) NULL',
            'shipping_last_name' => 'VARCHAR(190) NULL',
            'shipping_phone' => 'VARCHAR(50) NULL',
            'shipping_address_line1' => 'VARCHAR(255) NULL',
            'shipping_city' => 'VARCHAR(190) NULL',
            'shipping_county' => 'VARCHAR(190) NULL',
            'shipping_postcode' => 'VARCHAR(20) NULL',
            'shipping_address_line2' => 'VARCHAR(255) NULL',
            'deleted_at' => 'DATETIME NULL',
            'notes' => 'TEXT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            'ad_source' => 'VARCHAR(50) NULL',
            'ad_click_id' => 'VARCHAR(255) NULL',
            'paid_amount' => 'DECIMAL(10,2) NULL',
            'edit_unlocked_at' => 'DATETIME NULL',
            'edit_unlock_reason' => 'VARCHAR(500) NULL',
            'edit_unlocked_by' => 'VARCHAR(190) NULL',
            'admin_notes' => 'TEXT NULL',
            'manual_discount' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
            'manual_discount_percent' => 'DECIMAL(5,2) NULL',
            'manual_discount_reason' => 'VARCHAR(190) NULL',
            'terms_accepted_at' => 'DATETIME NULL',
        ], [
            'PRIMARY KEY (id)',
            'KEY fk_orders_user (user_id)',
            'UNIQUE KEY order_number (order_number)',
            'CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL',
        ]);

        $s->tabelComplet('order_items', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'order_id' => 'INT UNSIGNED NOT NULL',
            'product_id' => 'INT UNSIGNED NULL',
            'product_name' => 'VARCHAR(190) NOT NULL',
            'quantity' => 'INT NOT NULL DEFAULT 1',
            'unit_price' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
            'line_total' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'KEY fk_order_items_order (order_id)',
            'KEY fk_order_items_product (product_id)',
            'CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE',
            'CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL',
        ]);

        $s->tabelComplet('email_logs', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'order_id' => 'INT UNSIGNED NULL',
            'email_type' => 'VARCHAR(80) NOT NULL',
            'recipient' => 'VARCHAR(190) NOT NULL',
            'subject' => 'VARCHAR(255) NOT NULL',
            'status' => "VARCHAR(20) NOT NULL DEFAULT 'sent'",
            'error_message' => 'TEXT NULL',
            'sent_at' => 'DATETIME NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_order_email_type (order_id, email_type)',
        ]);

        $s->tabelComplet('cart_abandonments', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'session_id' => 'VARCHAR(190) NOT NULL',
            'email' => 'VARCHAR(190) NULL',
            'customer_name' => 'VARCHAR(190) NULL',
            'cart_snapshot' => 'LONGTEXT NULL',
            'last_seen_at' => 'DATETIME NOT NULL',
            'converted_at' => 'DATETIME NULL',
            'abandoned_email_sent_at' => 'DATETIME NULL',
            'abandoned_email_error' => 'TEXT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY session_id (session_id)',
        ]);
    },
];
