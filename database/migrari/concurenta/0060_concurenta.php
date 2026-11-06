<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Analiza prețurilor concurenței (pluginul `concurenta`, din Biovitality 649c837, 7ee1a86, 729d6fa, ea927aa; docs/PLUGINURI.md,
 * „Analiza prețurilor concurenței”):
 *
 *  - `competition_partners`: concurenții urmăriți (numele, site-ul, perioada implicită a unei campanii generale, activ, ordinea);
 *  - `competition_prices`: o celulă pe pereche partener × produs (prețul, campania bifată la produs cu prețul și perioada ei, „stoc
 *    epuizat”, „doar în magazin”, linkul produsului), cu cheia unică (partner_id, product_id) a salvării pe celulă.
 *
 * Adoptivă: definițiile sunt exact cele pe care le face codul Biovitality la d95598e (`ensureCompetitionSchema` din AdminController,
 * cu coloanele adăugate de ALTER-urile lui; tools/dev/migrari/vechi/bv.sql), deci pe baza biovitality.ro tabelele există cu rânduri
 * și migrarea le găsește („detectata”) fără să le atingă. Pe celelalte site-uri (decizia OD-02) apar goale. Fără date în migrare
 * (preluarea campaniei din `ensureCompetitionSchema`, o singură dată, s-a făcut demult pe Biovitality, la vremea ei).
 */
return [
    'descriere' => 'Analiza prețurilor concurenței: partenerii și prețurile lor (concurenta)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('competition_partners', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name' => 'VARCHAR(190) NOT NULL',
            'website' => 'VARCHAR(255) DEFAULT NULL',
            'has_campaign' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'campaign_start' => 'DATE DEFAULT NULL',
            'campaign_end' => 'DATE DEFAULT NULL',
            'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'sort_order' => 'INT NOT NULL DEFAULT 0',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
        ]);
        $s->tabelComplet('competition_prices', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'partner_id' => 'INT UNSIGNED NOT NULL',
            'product_id' => 'INT UNSIGNED NOT NULL',
            'price' => 'DECIMAL(10,2) DEFAULT NULL',
            'campaign_active' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'campaign_price' => 'DECIMAL(10,2) DEFAULT NULL',
            'campaign_start' => 'DATE DEFAULT NULL',
            'campaign_end' => 'DATE DEFAULT NULL',
            'out_of_stock' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'only_in_store' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'product_url' => 'VARCHAR(500) DEFAULT NULL',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_partner_product (partner_id, product_id)',
            'KEY idx_cprices_product (product_id)',
        ]);
    },
];
