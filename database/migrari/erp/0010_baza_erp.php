<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * ERP: starea trimiterii comenzilor în ERP, factura, clientul agresiv; prețurile ERP pe linii.
 *
 * Sursa: ErpSync::ensureSchema (cele 11 coloane erp_* ale comenzilor, cele 2 ale liniilor, indexul cozii
 * de trimitere).
 */
return [
    'descriere' => 'ERP: starea trimiterii comenzilor în ERP, factura, clientul agresiv; prețurile ERP pe linii',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        foreach ([
            'erp_discount_percent' => 'DECIMAL(5,2) NULL',
            'erp_price_before' => 'DECIMAL(10,2) NULL',
        ] as $coloana => $definitie) {
            $s->coloana('order_items', $coloana, $definitie);
        }

        foreach ([
            'erp_status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
            'erp_order_id' => 'VARCHAR(32) NULL',
            'erp_attempts' => 'INT NOT NULL DEFAULT 0',
            'erp_last_error' => 'TEXT NULL',
            'erp_last_attempt_at' => 'DATETIME NULL',
            'erp_next_retry_at' => 'DATETIME NULL',
            'erp_synced_at' => 'DATETIME NULL',
            'erp_problems' => 'TEXT NULL',
            'erp_factura_numar' => 'VARCHAR(40) NULL',
            'erp_client_agresiv' => 'TINYINT(1) NULL',
            'erp_client_agresiv_versiune' => 'BIGINT NULL',
        ] as $coloana => $definitie) {
            $s->coloana('orders', $coloana, $definitie);
        }

        $s->index('orders', 'idx_orders_erp_status', ['erp_status', 'erp_next_retry_at']);
    },
];
