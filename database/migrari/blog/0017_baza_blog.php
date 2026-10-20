<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Blogul: articolele, categoriile, legăturile articol–categorie, autorii, șabloanele.
 *
 * Sursa: AdminController::ensureOptionalSchema. Fără categoriile pe care le punea codul la prima creare
 * (Noutăți, Evenimente, Info pacienți, Info medici: conținutul Bioscem, nu schema); legăturile articolelor
 * vechi cu categoria lor le face o dată 0024.
 */
return [
    'descriere' => 'Blogul: articolele, categoriile, legăturile articol–categorie, autorii, șabloanele',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->tabelComplet('blog_posts', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'title' => 'VARCHAR(255) NOT NULL',
            'slug' => 'VARCHAR(255) NOT NULL',
            'excerpt' => 'TEXT NULL',
            'category' => 'VARCHAR(190) NULL',
            'category_id' => 'INT UNSIGNED NULL',
            'event_start_date' => 'DATE NULL',
            'event_end_date' => 'DATE NULL',
            'event_price' => 'VARCHAR(120) NULL',
            'event_ticket_url' => 'VARCHAR(500) NULL',
            'event_location' => 'VARCHAR(190) NULL',
            'content' => 'LONGTEXT NOT NULL',
            'reading_minutes' => 'INT UNSIGNED NOT NULL DEFAULT 1',
            'published_at' => 'DATETIME NOT NULL',
            'is_published' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'template_id' => 'INT UNSIGNED NULL',
            'author_id' => 'INT UNSIGNED NULL',
            'featured_image_url' => 'VARCHAR(255) NULL',
            'video_url' => 'VARCHAR(500) NULL',
            'deleted_at' => 'DATETIME NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_blog_posts_deleted (deleted_at)',
            'KEY idx_blog_posts_published (is_published, published_at)',
            'UNIQUE KEY slug (slug)',
        ]);

        $s->tabelComplet('blog_categories', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name' => 'VARCHAR(150) NOT NULL',
            'slug' => 'VARCHAR(170) NOT NULL',
            'sort_order' => 'INT NOT NULL DEFAULT 0',
            'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY name (name)',
            'UNIQUE KEY slug (slug)',
        ]);

        $s->tabelComplet('blog_post_categories', [
            'post_id' => 'INT UNSIGNED NOT NULL',
            'category_id' => 'INT UNSIGNED NOT NULL',
        ], [
            'PRIMARY KEY (post_id, category_id)',
            'KEY idx_bpc_category (category_id)',
        ]);

        $s->tabelComplet('blog_authors', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name' => 'VARCHAR(190) NOT NULL',
            'slug' => 'VARCHAR(190) NOT NULL',
            'bio' => 'TEXT NULL',
            'avatar_url' => 'VARCHAR(255) NULL',
            'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY slug (slug)',
        ]);

        $s->tabelComplet('blog_templates', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name' => 'VARCHAR(190) NOT NULL',
            'slug' => 'VARCHAR(190) NOT NULL',
            'description' => 'VARCHAR(255) NULL',
            'html_content' => 'LONGTEXT NOT NULL',
            'css_content' => 'LONGTEXT NULL',
            'js_content' => 'LONGTEXT NULL',
            'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY slug (slug)',
        ]);
    },
];
