<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Campaniile de pe site (pluginul `campanii`, App\Support\Campanii; docs/PLUGINURI.md, „Campanii pe site”):
 *
 *  - `campanii`: o campanie, cu fereastra ei (`incepe_la`, `se_termina_la`, ora României), pagina de deschidere (o pagină
 *    din Conținut → Pagini, `pagina_id`), insigna produselor și bannerul de sus (textul, linkul, culorile, fontul, mărimea,
 *    lipit la derulare). Cel mult o campanie pornită pe un interval (verificat la salvare);
 *  - `campanii_produse`: produsele campaniei (cele care primesc insigna).
 *
 * Tabele noi pe toate site-urile (decizia OD-02), goale; cât nu e nicio campanie pornită în fereastra ei, site-ul nu se
 * schimbă cu nimic.
 */
return [
    'descriere' => 'Campaniile de pe site (campanii, campanii_produse)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('campanii', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'nume' => 'VARCHAR(160) NOT NULL',
            'slug' => 'VARCHAR(160) NOT NULL',
            'pornita' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'incepe_la' => 'DATETIME NULL',
            'se_termina_la' => 'DATETIME NULL',
            'pagina_id' => 'INT UNSIGNED NULL',
            'pagina_la_intrare' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'text_inapoi' => 'VARCHAR(120) NULL',
            'insigna_text' => 'VARCHAR(40) NULL',
            'insigna_fundal' => 'VARCHAR(7) NULL',
            'insigna_culoare' => 'VARCHAR(7) NULL',
            'banner_pornit' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'banner_text' => 'VARCHAR(300) NULL',
            'banner_link' => 'VARCHAR(500) NULL',
            'banner_fundal' => 'VARCHAR(7) NULL',
            'banner_culoare' => 'VARCHAR(7) NULL',
            'banner_font' => 'VARCHAR(30) NULL',
            'banner_marime' => 'SMALLINT UNSIGNED NULL',
            'banner_lipit' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_campanii_slug (slug)',
            'KEY idx_campanii_fereastra (pornita, incepe_la, se_termina_la)',
        ]);
        $s->tabelComplet('campanii_produse', [
            'campanie_id' => 'INT UNSIGNED NOT NULL',
            'product_id' => 'INT UNSIGNED NOT NULL',
        ], [
            'PRIMARY KEY (campanie_id, product_id)',
            'KEY idx_campanii_produse_produs (product_id)',
        ]);
    },
];
