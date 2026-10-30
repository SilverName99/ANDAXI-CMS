<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Cererile de ofertă (WP-26: GR-15, pluginul `cereri_oferta`, App\Support\CereriOferta):
 *
 *  - `quote_requests`: cererile trimise din sertarul de pe produs, cu starea lor (`noua`, `in_lucru`, `trimisa`,
 *    `inchisa`) și ștergerea „moale” (`deleted_at`). Aceeași definiție ca în grafoanaytis (`CereriOferta::pregatesteSchema`,
 *    `d3c4b79`), unde tabelul îl făcea prima cerere: pe baza grafoanaytis migrarea îl adoptă cum e, cu rândurile lui;
 *  - `quote_submit_logs`: trimiterile, pentru limita de 5 la 10 minute de la același vizitator. Doar hash-ul (HMAC) al
 *    adresei, nu adresa, și data; rândurile mai vechi de o zi se șterg din mers.
 *
 * Tabele noi pe toate site-urile (decizia OD-02), goale în afară de grafoanaytis; nu schimbă nimic din ce se vede.
 */
return [
    'descriere' => 'Cererile de ofertă (quote_requests, quote_submit_logs)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('quote_requests', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'product_id' => 'INT UNSIGNED NULL',
            'product_name' => 'VARCHAR(255) NULL',
            'product_slug' => 'VARCHAR(255) NULL',
            'name' => 'VARCHAR(160) NOT NULL',
            'company' => 'VARCHAR(160) NULL',
            'email' => 'VARCHAR(190) NOT NULL',
            'phone' => 'VARCHAR(60) NULL',
            'message' => 'TEXT NULL',
            'status' => "VARCHAR(20) NOT NULL DEFAULT 'noua'",
            'admin_note' => 'TEXT NULL',
            'consent_at' => 'DATETIME NULL',
            'source_url' => 'VARCHAR(500) NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            'deleted_at' => 'DATETIME NULL',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_quote_status (status)',
            'KEY idx_quote_created (created_at)',
        ]);
        $s->tabelComplet('quote_submit_logs', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'ip_hash' => 'CHAR(64) NOT NULL',
            'created_at' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_quote_submit_logs_ip (ip_hash, created_at)',
        ]);
    },
];
