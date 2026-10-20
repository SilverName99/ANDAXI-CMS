<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Avertismentul pe produs (ex. „ATENȚIE – PRODUS REFRIGERAT”): cele trei câmpuri ale fiecărui produs și ce a
 * confirmat clientul pe comandă; versiunea schemei lor în setări.
 *
 * Sursa: Bioscem `0582ce9` (AvertismentProduse::ensureSchema, 20 oct 2026), unde tabelele se făceau din mers, la
 * prima salvare a unui avertisment sau la prima comandă cu el. Adoptivă: pe Bioscem live tabelele există de la prima
 * salvare (de exemplu scriptul celor trei produse refrigerate, scripts/avertisment-produse.php), deci migrarea le
 * găsește coloană cu coloană și nu face nimic; unde lipsesc, le creează. Coloanele sunt exact cele din codul Bioscem.
 * Codul Bioscem le crea cu
 * DEFAULT CHARSET=utf8mb4 fără colație, deci cu colația serverului; o instalare nouă le are, ca pe toate, cu
 * utf8mb4_unicode_ci (pe o bază veche colația rămâne cum e, ca la tabelele BT din 0008).
 *
 * La final scrie product_warnings_schema_version = '1' (versiunea din codul Bioscem), dacă nu e deja: codul vechi,
 * readus după o revenire, nu mai trimite DDL.
 */
return [
    'descriere' => 'Avertismentul pe produs: textele pe produs și confirmările de pe comenzi; versiunea schemei lor în setări',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('product_warnings', [
            'product_id' => 'INT UNSIGNED NOT NULL',
            'title' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'body' => 'TEXT NULL',
            'checkbox_label' => "VARCHAR(500) NOT NULL DEFAULT ''",
            'updated_at' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (product_id)',
        ]);

        $s->tabelComplet('order_product_warnings', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'order_id' => 'INT UNSIGNED NOT NULL',
            'title' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'body' => 'TEXT NOT NULL',
            'checkbox_label' => 'TEXT NOT NULL',
            'checkbox_ticked' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'product_ids_json' => 'TEXT NOT NULL',
            'product_names_json' => 'TEXT NOT NULL',
            'confirmed_at' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_order_product_warnings_order (order_id)',
        ]);

        // Versiunea schemei avertismentelor (AvertismentProduse::VERSIUNE_SCHEMA din codul Bioscem): cu ea,
        // AvertismentProduse::ensureSchema al codului Bioscem nu mai trimite DDL, dacă un site e readus la el.
        if (($s->citeste("SELECT `value` FROM settings WHERE `key` = 'product_warnings_schema_version'")[0]['value'] ?? null) !== '1') {
            $s->date('Versiunea schemei avertismentelor în setări (product_warnings_schema_version = 1)',
                "INSERT INTO settings (`key`, `value`) VALUES ('product_warnings_schema_version', '1') ON DUPLICATE KEY UPDATE `value` = '1'");
        }
    },
];
