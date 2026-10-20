<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Legăturile articol–categorie pentru articolele care au doar categoria principală
 * (blog_posts.category_id), făcute o singură dată.
 *
 * Înlocuiește INSERT IGNORE-ul din AdminController::ensureOptionalSchema (la fiecare pagină de admin):
 * aceleași legături, doar cele care lipsesc. Editorul de azi scrie singur legăturile la salvare.
 */
return [
    'descriere' => 'Legăturile articol–categorie din categoria principală a articolelor vechi',
    'tip' => 'date',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->date('Legăturile articol–categorie care lipsesc, din blog_posts.category_id',
            'INSERT INTO blog_post_categories (post_id, category_id)
             SELECT p.id, p.category_id
             FROM blog_posts p
             LEFT JOIN blog_post_categories l ON l.post_id = p.id AND l.category_id = p.category_id
             WHERE p.category_id IS NOT NULL AND p.category_id > 0 AND l.post_id IS NULL');
    },
];
