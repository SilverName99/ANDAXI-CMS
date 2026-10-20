<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Newsletterul: șabloanele, listele, abonații, campaniile, trimiterile, deschiderile, clicurile,
 * formularele; campania pe comenzi.
 *
 * Sursa: NewsletterService::ensureSchema (plus database/schema.sql, scripts/install.php). Fără lista
 * implicită „Toți abonații”: o face NewsletterService::defaultListId când e nevoie de ea.
 */
return [
    'descriere' => 'Newsletterul: șabloanele, listele, abonații, campaniile și urmărirea lor, formularele; campania pe comenzi',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('newsletter_templates', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name' => 'VARCHAR(190) NOT NULL',
            'subject' => 'VARCHAR(255) NOT NULL',
            'blocks_json' => 'LONGTEXT NOT NULL',
            'html_content' => 'LONGTEXT NOT NULL',
            'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
        ]);

        $s->tabelComplet('newsletter_lists', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name' => 'VARCHAR(190) NOT NULL',
            'description' => 'VARCHAR(255) NULL',
            'is_default' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY name (name)',
        ]);

        $s->tabelComplet('newsletter_subscribers', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'email' => 'VARCHAR(190) NOT NULL',
            'name' => 'VARCHAR(190) NULL',
            'status' => "VARCHAR(20) NOT NULL DEFAULT 'active'",
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY email (email)',
        ]);

        $s->tabelComplet('newsletter_list_subscribers', [
            'list_id' => 'INT UNSIGNED NOT NULL',
            'subscriber_id' => 'INT UNSIGNED NOT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (list_id, subscriber_id)',
        ]);

        $s->tabelComplet('newsletter_campaigns', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name' => 'VARCHAR(190) NOT NULL',
            'template_type' => "VARCHAR(20) NOT NULL DEFAULT 'newsletter'",
            'template_ref' => 'VARCHAR(190) NULL',
            'subscriber_list_id' => 'INT UNSIGNED NULL',
            'subscriber_list_ids' => 'TEXT NULL',
            'subject' => 'VARCHAR(255) NOT NULL',
            'blocks_json' => 'LONGTEXT NULL',
            'html_content' => 'LONGTEXT NOT NULL',
            'status' => "VARCHAR(20) NOT NULL DEFAULT 'draft'",
            'scheduled_at' => 'DATETIME NULL',
            'sent_at' => 'DATETIME NULL',
            'total_recipients' => 'INT NOT NULL DEFAULT 0',
            'total_sent' => 'INT NOT NULL DEFAULT 0',
            'total_failed' => 'INT NOT NULL DEFAULT 0',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            'total_opens' => 'INT UNSIGNED NULL DEFAULT 0',
            'total_clicks' => 'INT UNSIGNED NULL DEFAULT 0',
        ], [
            'PRIMARY KEY (id)',
        ]);

        $s->tabelComplet('newsletter_campaign_sends', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'campaign_id' => 'INT UNSIGNED NOT NULL',
            'subscriber_id' => 'INT UNSIGNED NULL',
            'email' => 'VARCHAR(190) NOT NULL',
            'status' => "VARCHAR(20) NOT NULL DEFAULT 'sent'",
            'error_message' => 'TEXT NULL',
            'sent_at' => 'DATETIME NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'opened_at' => 'DATETIME NULL',
            'open_count' => 'INT UNSIGNED NULL DEFAULT 0',
            'clicked_at' => 'DATETIME NULL',
            'click_count' => 'INT UNSIGNED NULL DEFAULT 0',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_campaign_subscriber (campaign_id, subscriber_id)',
        ]);

        $s->tabelComplet('newsletter_optin_forms', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name' => 'VARCHAR(190) NOT NULL',
            'slug' => 'VARCHAR(190) NOT NULL',
            'list_id' => 'INT UNSIGNED NOT NULL',
            'button_label' => "VARCHAR(120) NOT NULL DEFAULT 'Ma abonez'",
            'success_message' => "VARCHAR(255) NOT NULL DEFAULT 'Te-ai abonat cu succes.'",
            'fields_json' => 'LONGTEXT NOT NULL',
            'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY slug (slug)',
        ]);

        $s->tabelComplet('newsletter_campaign_opens', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'campaign_id' => 'INT UNSIGNED NOT NULL',
            'send_id' => 'INT UNSIGNED NULL',
            'opened_at' => 'DATETIME NOT NULL',
            'ip' => 'VARCHAR(64) NULL',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_campaign_id (campaign_id)',
        ]);

        $s->tabelComplet('newsletter_campaign_clicks', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'campaign_id' => 'INT UNSIGNED NOT NULL',
            'subscriber_id' => 'INT UNSIGNED NULL',
            'email' => 'VARCHAR(190) NOT NULL',
            'url' => 'VARCHAR(2048) NOT NULL',
            'clicked_at' => 'DATETIME NOT NULL',
            'ip' => 'VARCHAR(64) NULL',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_campaign_id (campaign_id)',
        ]);

        foreach ([
            'nl_campaign_id' => 'INT UNSIGNED NULL',
        ] as $coloana => $definitie) {
            $s->coloana('orders', $coloana, $definitie);
        }

        $s->index('orders', 'idx_nl_campaign_id', ['nl_campaign_id']);
    },
];
