<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Blogul avansat (WP-22: B1, din Biovitality c078bd5, 14d19dc, 9c549c7):
 *
 *  - `blog_posts.featured_image_width`: cât din lățimea articolului ocupă imaginea principală, în procente (100 = ca
 *    până acum, deci articolele existente arată la fel);
 *  - `blog_posts.references_html`: bibliografia articolului, separat de text (NULL = fără, ca până acum);
 *  - `blog_posts.wp_post_id` și indexul `idx_blog_posts_wp`: id-ul articolului în WordPress-ul din care a fost importat
 *    (tools/migrare-wordpress/import-wordpress-posts.php, WP-23), ca un import reluat să nu dubleze articolele;
 *  - etichetele: `blog_tags` (nume și slug, fiecare unic) și legătura `blog_post_tags` (articol–etichetă).
 *
 * Adoptivă: codul Biovitality face aceleași coloane și tabele cu aceleași definiții (AdminController::
 * ensureOptionalSchema; `wp_post_id` și indexul lui le face scriptul lui de import), deci pe baza lui migrarea le
 * găsește și nu face nimic. Pe celelalte site-uri nu se schimbă nimic din ce se vede: coloanele noi au valori care dau
 * pagina de azi, iar tabelele noi pornesc goale. Codul de dinainte merge pe schema nouă (coloane la coadă, cu valori
 * implicite sau NULL).
 */
return [
    'descriere' => 'Blogul avansat: lățimea copertei, bibliografia, id-ul din WordPress, etichetele',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('blog_posts', 'featured_image_width', 'TINYINT UNSIGNED NOT NULL DEFAULT 100');
        $s->coloana('blog_posts', 'references_html', 'LONGTEXT NULL');
        $s->coloana('blog_posts', 'wp_post_id', 'INT UNSIGNED NULL');
        $s->index('blog_posts', 'idx_blog_posts_wp', ['wp_post_id']);

        $s->tabelComplet('blog_tags', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name' => 'VARCHAR(150) NOT NULL',
            'slug' => 'VARCHAR(170) NOT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY name (name)',
            'UNIQUE KEY slug (slug)',
        ]);

        $s->tabelComplet('blog_post_tags', [
            'post_id' => 'INT UNSIGNED NOT NULL',
            'tag_id' => 'INT UNSIGNED NOT NULL',
        ], [
            'PRIMARY KEY (post_id, tag_id)',
            'KEY idx_bpt_tag (tag_id)',
        ]);
    },
];
