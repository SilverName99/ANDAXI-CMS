<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Plata BT iPay: tranzacțiile și jurnalul băncii; versiunea schemei BT în setări.
 *
 * Sursa: BtIpayPayments::migreaza (cu cele 5 coloane și indexul adăugate după prima versiune). Codul le
 * făcea cu DEFAULT CHARSET=utf8mb4 fără colație, deci cu colația serverului; o instalare nouă le are, ca
 * pe toate, cu utf8mb4_unicode_ci (pe o bază veche colația rămâne cum e).
 *
 * La final scrie bt_ipay_schema_version = '3' (versiunea lui BtIpayPayments::VERSIUNE_SCHEMA), dacă nu e
 * deja: codul vechi, readus după o revenire, nu mai trimite DDL.
 */
return [
    'descriere' => 'Plata BT iPay: tranzacțiile și jurnalul băncii; versiunea schemei BT în setări',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('bt_ipay_transactions', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'order_id' => 'INT UNSIGNED NULL',
            'link_id' => 'INT UNSIGNED NULL',
            'kind' => "VARCHAR(10) NOT NULL DEFAULT 'order'",
            'mode' => "VARCHAR(4) NOT NULL DEFAULT 'test'",
            'bt_order_number' => 'VARCHAR(40) NOT NULL',
            'bt_order_id' => 'VARCHAR(64) NULL',
            'form_url' => 'VARCHAR(1000) NULL',
            'loy_order_id' => 'VARCHAR(64) NULL',
            'amount_minor' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'currency' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 946',
            'approved_minor' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'loy_minor' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'deposited_minor' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'loy_deposited_minor' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'refunded_minor' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'loy_refunded_minor' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'state' => "VARCHAR(20) NOT NULL DEFAULT 'registered'",
            'bt_status' => 'TINYINT NULL',
            'loy_status' => 'TINYINT NULL',
            'in_comanda' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'action_code' => 'INT NULL',
            'action_desc' => 'VARCHAR(255) NULL',
            'approval_code' => 'VARCHAR(20) NULL',
            'refund_reference' => 'VARCHAR(64) NULL',
            'last_error' => 'TEXT NULL',
            'op_pending' => 'TEXT NULL',
            'alerts_json' => 'TEXT NULL',
            'deposit_requested_at' => 'DATETIME NULL',
            'deposit_requested_minor' => 'INT UNSIGNED NULL',
            'deposit_attempts' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'reminder_sent_at' => 'DATETIME NULL',
            'authorized_at' => 'DATETIME NULL',
            'deposited_at' => 'DATETIME NULL',
            'reversed_at' => 'DATETIME NULL',
            'refunded_at' => 'DATETIME NULL',
            'last_checked_at' => 'DATETIME NULL',
            'public_checked_at' => 'DATETIME NULL',
            'callback_checked_at' => 'DATETIME NULL',
            'last_status_json' => 'MEDIUMTEXT NULL',
            'created_at' => 'DATETIME NOT NULL',
            'updated_at' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_bt_ipay_tx_link (link_id)',
            'KEY idx_bt_ipay_tx_order (order_id)',
            'KEY idx_bt_ipay_tx_state (state, created_at)',
            'UNIQUE KEY uq_bt_ipay_tx_bt_id (bt_order_id)',
            'UNIQUE KEY uq_bt_ipay_tx_number (bt_order_number)',
        ]);

        $s->tabelComplet('bt_ipay_log', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'tx_id' => 'INT UNSIGNED NULL',
            'order_id' => 'INT UNSIGNED NULL',
            'mode' => "VARCHAR(4) NOT NULL DEFAULT ''",
            'action' => 'VARCHAR(40) NOT NULL',
            'source' => "VARCHAR(30) NOT NULL DEFAULT ''",
            'http_code' => 'SMALLINT NULL',
            'error_code' => 'VARCHAR(20) NULL',
            'duration_ms' => 'INT UNSIGNED NULL',
            'request_json' => 'MEDIUMTEXT NULL',
            'response_json' => 'MEDIUMTEXT NULL',
            'message' => 'VARCHAR(500) NULL',
            'created_at' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_bt_ipay_log_created (created_at)',
            'KEY idx_bt_ipay_log_tx (tx_id)',
        ]);

        // Versiunea schemei BT (BtIpayPayments::VERSIUNE_SCHEMA): cu ea, BtIpayPayments::ensureSchema nu mai
        // trimite DDL, nici în codul vechi, dacă un site e readus la el.
        if (($s->citeste("SELECT `value` FROM settings WHERE `key` = 'bt_ipay_schema_version'")[0]['value'] ?? null) !== '3') {
            $s->date('Versiunea schemei BT în setări (bt_ipay_schema_version = 3)',
                "INSERT INTO settings (`key`, `value`) VALUES ('bt_ipay_schema_version', '3') ON DUPLICATE KEY UPDATE `value` = '3'");
        }
    },
];
