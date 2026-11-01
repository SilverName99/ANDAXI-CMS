<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Jurnalul paginilor negăsite (WP-38, SEO partea B, B2; App\Support\Jurnal404):
 *
 *  - `seo_404_log`: fiecare adresă publică la care site-ul a răspuns 404: `path` (cheia hărții: decodată, cu litere
 *    mici, fără „/” final, fără parametri), `path_hash` (unicitatea, ca la `seo_redirects`), `hits`, `first_seen_at`,
 *    `last_seen_at`, `referrer_host` (doar gazda altui site de pe care a venit omul). Fără IP, fără browser, fără
 *    parametri; rândurile mai vechi de 90 de zile și cele peste 5.000 pleacă singure.
 *
 * Pornește gol; nu se vede nimic pe site.
 */
return [
    'descriere' => 'Jurnalul paginilor negăsite (404), fără date personale, cu păstrare limitată',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('seo_404_log', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'path_hash' => 'CHAR(40) NOT NULL',
            'path' => 'VARCHAR(500) NOT NULL',
            'hits' => 'INT UNSIGNED NOT NULL DEFAULT 1',
            'first_seen_at' => 'DATETIME NOT NULL',
            'last_seen_at' => 'DATETIME NOT NULL',
            'referrer_host' => 'VARCHAR(190) NULL',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_seo_404_path (path_hash)',
            'KEY idx_seo_404_vazut (last_seen_at)',
        ]);
    },
];
