<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Nucleul: administratorii, setările, paginile, adresele SEO, galeria, mesajele de contact, istoricul
 * emailurilor, jurnalul adminului, jurnalele formularelor.
 *
 * Surse: database/schema.sql, AdminController::ensureOptionalSchema (rolurile adminilor, seo_pages,
 * galeria, paginile), ::ensureFormSecurityLogsSchema, SiteController::ensureOptionalPageSchema,
 * ::ensureContactFormsSchema, ::ensureCheckoutAntiBotSchema, ::ensureRegisterAntiBotSchema,
 * OrderMailer::ensureEmailSendHistorySchema, AdminActivityLog::ensureSchema, Auth::ensureSchema. Rolurile
 * adminilor de pe o bază veche le aduce la forma de azi 0020.
 */
return [
    'descriere' => 'Nucleul: adminii, setările, paginile, SEO, galeria, mesajele de contact, emailurile, jurnalele',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('admins', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'email' => 'VARCHAR(190) NOT NULL',
            'password_hash' => 'VARCHAR(255) NOT NULL',
            'role' => "VARCHAR(50) NOT NULL DEFAULT 'administrator_general'",
            'roles_json' => 'LONGTEXT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY email (email)',
        ]);

        $s->tabelComplet('settings', [
            'key' => 'VARCHAR(120) NOT NULL',
            'value' => 'LONGTEXT NOT NULL',
        ], [
            'PRIMARY KEY (`key`)',
        ]);

        $s->tabelComplet('pages', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'title' => 'VARCHAR(190) NOT NULL',
            'slug' => 'VARCHAR(190) NOT NULL',
            'html_content' => 'LONGTEXT NOT NULL',
            'css_content' => 'LONGTEXT NULL',
            'js_content' => 'LONGTEXT NULL',
            'is_published' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'deleted_at' => 'DATETIME NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY slug (slug)',
        ]);

        $s->tabelComplet('seo_pages', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'page_type' => 'VARCHAR(50) NOT NULL',
            'page_ref' => 'VARCHAR(190) NOT NULL',
            'title' => 'VARCHAR(255) NULL',
            'description' => 'TEXT NULL',
            'canonical_url' => 'VARCHAR(255) NULL',
            'image_url' => 'VARCHAR(255) NULL',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_page_ref (page_type, page_ref)',
        ]);

        $s->tabelComplet('gallery_images', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'title' => 'VARCHAR(190) NOT NULL',
            'media_type' => "VARCHAR(20) NOT NULL DEFAULT 'image'",
            'image_url' => 'TEXT NOT NULL',
            'folder_id' => 'INT UNSIGNED NULL',
            'alt_text' => 'VARCHAR(255) NULL',
            'sort_order' => 'INT NOT NULL DEFAULT 0',
            'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
        ]);

        $s->tabelComplet('gallery_folders', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name' => 'VARCHAR(120) NOT NULL',
            'slug' => 'VARCHAR(140) NOT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY name (name)',
            'UNIQUE KEY slug (slug)',
        ]);

        $s->tabelComplet('contact_form_messages', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name' => 'VARCHAR(190) NOT NULL',
            'email' => 'VARCHAR(190) NOT NULL',
            'phone' => 'VARCHAR(60) NULL',
            'subject' => 'VARCHAR(255) NOT NULL',
            'message' => 'LONGTEXT NOT NULL',
            'status' => "VARCHAR(20) NOT NULL DEFAULT 'new'",
            'source_url' => 'VARCHAR(500) NULL',
            'ip_address' => 'VARCHAR(64) NULL',
            'user_agent' => 'VARCHAR(255) NULL',
            'created_at' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_contact_form_messages_created (created_at)',
            'KEY idx_contact_form_messages_status (status)',
        ]);

        $s->tabelComplet('email_send_history', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'order_id' => 'INT UNSIGNED NULL',
            'email_type' => 'VARCHAR(80) NOT NULL',
            'source' => "VARCHAR(80) NOT NULL DEFAULT 'system'",
            'recipient' => 'VARCHAR(190) NOT NULL',
            'subject' => 'VARCHAR(255) NOT NULL',
            'status' => "VARCHAR(20) NOT NULL DEFAULT 'sent'",
            'error_message' => 'TEXT NULL',
            'provider' => 'VARCHAR(30) NULL',
            'meta_json' => 'LONGTEXT NULL',
            'sent_at' => 'DATETIME NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_email_send_history_created (created_at)',
            'KEY idx_email_send_history_order (order_id)',
            'KEY idx_email_send_history_status (status)',
            'KEY idx_email_send_history_type (email_type)',
        ]);

        $s->tabelComplet('admin_activity_log', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'admin_id' => 'INT UNSIGNED NULL',
            'admin_email' => 'VARCHAR(190) NULL',
            'action' => 'VARCHAR(100) NOT NULL',
            'context_json' => 'LONGTEXT NULL',
            'ip' => 'VARCHAR(45) NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_action (action)',
            'KEY idx_created_at (created_at)',
        ]);

        $s->tabelComplet('checkout_submit_logs', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'ip_address' => 'VARCHAR(64) NULL',
            'user_agent' => 'VARCHAR(255) NULL',
            'status' => "VARCHAR(20) NOT NULL DEFAULT 'allowed'",
            'created_at' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_checkout_submit_logs_created (created_at)',
            'KEY idx_checkout_submit_logs_ip_created (ip_address, created_at)',
        ]);

        $s->tabelComplet('register_submit_logs', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'ip_address' => 'VARCHAR(64) NULL',
            'user_agent' => 'VARCHAR(255) NULL',
            'status' => "VARCHAR(20) NOT NULL DEFAULT 'allowed'",
            'created_at' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_register_submit_logs_created (created_at)',
            'KEY idx_register_submit_logs_ip_created (ip_address, created_at)',
        ]);
    },
];
