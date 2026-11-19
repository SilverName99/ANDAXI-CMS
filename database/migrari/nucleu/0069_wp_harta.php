<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Harta unui site mutat de pe WordPress, pe ID-uri (stratul de adrese WordPress, App\Support\WpCompat\Harta): o citesc
 * `?page_id=`, `?p=`, `?cat=`, `?author=`, `?attachment_id=`, adresele frumoase ale atașamentelor și ghicirea adresei
 * greșite, pe site-urile cu wp-compat.json. O scrie importul WordPress (Harta::scrie).
 *
 *  - `wp_harta`: `wp_id` (ID-ul WordPress), `tip` (page, post, project, attachment, category, author), `slug_wp`
 *    (post_name-ul, în forma WordPress, percent-encoded), `cale` (adresa pe care o scria WordPress), `stare` (publish,
 *    private, inherit), `ordine` (ordinea creării), `fisier` (la atașamente: adresa fișierului din wp-content/uploads/),
 *    `pagina_id` (pagina din constructor în care a devenit postarea, dacă a devenit una). Unic pe `tip` + `wp_id`.
 *
 * Pornește gol și rămâne gol pe site-urile care nu vin din WordPress; nu se vede nimic pe site.
 */
return [
    'descriere' => 'Harta WordPress pe ID-uri (wp_harta): adresele vechi ale unui site mutat de pe WordPress, pentru stratul de adrese',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('wp_harta', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'wp_id' => 'BIGINT UNSIGNED NOT NULL',
            'tip' => 'VARCHAR(20) NOT NULL',
            'slug_wp' => 'VARCHAR(200) NOT NULL',
            'cale' => 'VARCHAR(760) NOT NULL',
            'stare' => 'VARCHAR(20) NOT NULL',
            'ordine' => 'INT UNSIGNED NOT NULL',
            'fisier' => 'VARCHAR(760) NOT NULL',
            'pagina_id' => 'INT UNSIGNED NULL',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY uniq_wp_harta (tip, wp_id)',
            'KEY idx_wp_harta_cale (cale)',
            'KEY idx_wp_harta_wp_id (wp_id)',
        ]);
    },
];
