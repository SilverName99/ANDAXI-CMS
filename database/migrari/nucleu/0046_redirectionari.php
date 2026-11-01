<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Managerul redirecționărilor (WP-38, SEO partea B, B2; App\Support\Redirectionari, docs/SEO.md §2):
 *
 *  - `seo_redirects`: harta adreselor vechi ale site-ului, una pe rând: `from_path` (cheia hărții: calea decodată, cu
 *    litere mici, fără „/” final; la un prefix, începutul căii), `is_prefix`, `to_url` (o cale a site-ului; NULL la 410),
 *    `code` (301 sau 410), `is_active`, `source` (pachet, manual, import, slug, 404, stergere), `note`, `hits` și
 *    `last_hit_at` (de câte ori și când s-a aplicat), `created_by` (adminul), `created_at`, `updated_at`. Unicitatea e
 *    pe `from_hash` (sha1 al felului și al cheii): o coloană de text cu colația tabelului ar face „ă” = „a”, iar adresele
 *    vechi cu diacriticele tăiate trebuie să fie altă regulă decât cele cu diacritice.
 *
 * Tabelul pornește gol: regulile din `redirecturi.json` al site-ului intră în el la prima cerere a site-ului (rânduri
 * „pachet”, exact cum sunt), deci harta rămâne cea de azi. Pe niciun site nu se schimbă nimic din ce se vede.
 */
return [
    'descriere' => 'Redirecționările site-ului în baza de date (managerul din admin)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('seo_redirects', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'from_hash' => 'CHAR(40) NOT NULL',
            'from_path' => 'VARCHAR(500) NOT NULL',
            'is_prefix' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'to_url' => 'VARCHAR(500) NULL',
            'code' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 301',
            'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'source' => "VARCHAR(20) NOT NULL DEFAULT 'manual'",
            'note' => 'VARCHAR(255) NULL',
            'hits' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'last_hit_at' => 'DATETIME NULL',
            'created_by' => 'VARCHAR(190) NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_seo_redirects_from (from_hash)',
            'KEY idx_seo_redirects_activ (is_active, is_prefix)',
        ]);
    },
];
