<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Articolele înrudite cu cel deschis (WP-22: B2, din Biovitality 9c549c7, f5295b5): din aceleași categorii și cu aceleași
 * etichete, nu la întâmplare. Dacă nu se strâng destule, completăm cu cele mai
 * recente, ca secțiunea să nu rămână pe jumătate goală.
 */
final class BlogRelatedPosts
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function find(?PDO $db, int $postId, int $limit = 3): array
    {
        if (!$db instanceof PDO || $postId <= 0) {
            return [];
        }
        $limit = max(1, min(12, $limit));

        $idCategorii = self::idLegate($db, 'blog_post_categories', 'category_id', $postId);
        $idEtichete = self::idLegate($db, 'blog_post_tags', 'tag_id', $postId);

        $gasite = [];
        if ($idCategorii !== [] || $idEtichete !== []) {
            $gasite = self::dupaAsemanare($db, $postId, $idCategorii, $idEtichete, $limit);
        }
        if (count($gasite) < $limit) {
            $deSarit = array_map(static fn (array $p): int => (int) $p['id'], $gasite);
            $deSarit[] = $postId;
            foreach (self::celeRecente($db, $deSarit, $limit - count($gasite)) as $recenta) {
                $gasite[] = $recenta;
            }
        }

        return array_map(self::decoreaza(...), array_slice($gasite, 0, $limit));
    }

    /**
     * Secțiunea gata făcută, pentru token-ul `{{blog_related_posts}}`.
     *
     * @param array<int, array<string, mixed>> $posts
     */
    public static function renderSection(array $posts, string $titlu = 'Articole similare'): string
    {
        if ($posts === []) {
            return '';
        }

        return '<section class="blog-related">'
            . ($titlu !== '' ? '<h2 class="blog-related__title">' . htmlspecialchars($titlu, ENT_QUOTES) . '</h2>' : '')
            . '<div class="blog-related__grid">' . self::carduri($posts, 'blog-related') . '</div>'
            . '</section>';
    }

    /**
     * Umple secțiunea „articole interesante" din template-urile existente.
     *
     * Acolo lista se completa din JavaScript, cu articole luate la întâmplare
     * din ultimele publicate. O scriem de pe server, cu articole înrudite:
     * scoatem `data-blog-related-list`, iar scriptul vechi se oprește singur
     * când nu-l mai găsește, deci nu ne suprascrie.
     *
     * @param array<int, array<string, mixed>> $posts
     */
    public static function upgradeLegacySection(string $html, array $posts): string
    {
        if (!str_contains($html, 'data-blog-related-list')) {
            return $html;
        }

        if ($posts === []) {
            // Fără articole înrudite, secțiunea n-are ce arăta.
            return (string) preg_replace(
                '#<section\b[^>]*\bdata-blog-related\b[^>]*>.*?</section>#is',
                '',
                $html
            ) ?: $html;
        }

        // Doar containerul gol, cel pe care îl umplea scriptul. Dacă cineva a
        // pus deja ceva acolo de mână, îl lăsăm în pace.
        return (string) preg_replace_callback(
            '#(<div\b[^>]*\bdata-blog-related-list\b[^>]*>)(\s*)(</div>)#is',
            static function (array $m) use ($posts): string {
                $deschidere = (string) $m[1];
                // Prefixul de clase al template-ului („…__related-grid" →
                // „…__related"), ca să nimerim CSS-ul lui, nu unul inventat.
                $prefix = 'blog-related';
                if (preg_match('/class="([^"]*)"/i', $deschidere, $cls) === 1
                    && preg_match('/([A-Za-z0-9_-]+)-grid\b/', $cls[1], $pref) === 1) {
                    $prefix = $pref[1];
                }
                $deschidere = str_replace('data-blog-related-list', 'data-blog-related-ready', $deschidere);

                return $deschidere . self::carduri($posts, $prefix) . $m[3];
            },
            $html,
            1
        ) ?: $html;
    }

    /**
     * Titlul secțiunii vechi spunea „articole interesante"; acum chiar sunt
     * înrudite, deci merită numite așa.
     */
    public static function renameLegacyHeading(string $html): string
    {
        return (string) preg_replace(
            '#(<section\b[^>]*\bdata-blog-related\b[^>]*>\s*<h[23]\b[^>]*>)\s*Articole interesante\s*(</h[23]>)#is',
            '$1Articole similare$2',
            $html
        ) ?: $html;
    }

    /**
     * @param array<int, array<string, mixed>> $posts
     */
    private static function carduri(array $posts, string $prefix): string
    {
        $iconCalendar = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 2a1 1 0 0 1 1 1v1h8V3a1 1 0 1 1 2 0v1h1a3 3 0 0 1 3 3v2H2V7a3 3 0 0 1 3-3h1V3a1 1 0 0 1 1-1Zm15 9v8a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3v-8h20ZM7 14a1 1 0 0 0 0 2h5a1 1 0 1 0 0-2H7Z" fill="currentColor"></path></svg>';
        $iconCeas = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2a10 10 0 1 1 0 20 10 10 0 0 1 0-20Zm0 4a1 1 0 0 0-1 1v5c0 .28.12.55.33.74l3.5 3a1 1 0 1 0 1.3-1.52L13 11.67V7a1 1 0 0 0-1-1Z" fill="currentColor"></path></svg>';

        $carduri = '';
        foreach ($posts as $post) {
            $titlu = htmlspecialchars((string) ($post['title'] ?? 'Articol'), ENT_QUOTES);
            $url = htmlspecialchars((string) ($post['url'] ?? '/blog'), ENT_QUOTES);
            $imagine = htmlspecialchars((string) ($post['cover_image_url'] ?? ''), ENT_QUOTES);
            $categorie = htmlspecialchars((string) ($post['category'] ?? 'Articol'), ENT_QUOTES);
            $data = htmlspecialchars((string) ($post['published_at_label'] ?? ''), ENT_QUOTES);
            $minute = max(1, (int) ($post['reading_minutes'] ?? 1)) . ' min';

            $carduri .= '<a class="' . $prefix . '-card" href="' . $url . '">'
                . '<span class="' . $prefix . '-media">'
                . '<img src="' . $imagine . '" alt="' . $titlu . '" loading="lazy" decoding="async"'
                . ' onerror="this.onerror=null;this.src=\'/assets/img/product-placeholder.svg\';">'
                . '</span>'
                . '<span class="' . $prefix . '-body">'
                . '<span class="' . $prefix . '-category">' . $categorie . '</span>'
                . '<h3 class="' . $prefix . '-title">' . $titlu . '</h3>'
                . '<span class="' . $prefix . '-meta">'
                . ($data !== ''
                    ? '<span class="' . $prefix . '-meta-item">' . $iconCalendar . '<span>' . $data . '</span></span>'
                    : '')
                . '<span class="' . $prefix . '-meta-item">' . $iconCeas . '<span>' . $minute . '</span></span>'
                . '</span>'
                . '</span>'
                . '</a>';
        }

        return $carduri;
    }

    /**
     * @return int[]
     */
    private static function idLegate(PDO $db, string $tabela, string $coloana, int $postId): array
    {
        try {
            $stmt = $db->prepare("SELECT {$coloana} FROM {$tabela} WHERE post_id = :id");
            $stmt->execute(['id' => $postId]);
            $randuri = $stmt->fetchAll() ?: [];

            return array_values(array_filter(array_map(
                static fn (array $r): int => (int) ($r[$coloana] ?? 0),
                $randuri
            ), static fn (int $id): bool => $id > 0));
        } catch (Throwable) {
            // Tabela poate lipsi pe o bază mai veche; atunci pur și simplu nu
            // avem pe ce potrivi.
            return [];
        }
    }

    /**
     * @param int[] $idCategorii
     * @param int[] $idEtichete
     *
     * @return array<int, array<string, mixed>>
     */
    private static function dupaAsemanare(PDO $db, int $postId, array $idCategorii, array $idEtichete, int $limit): array
    {
        $parametri = ['id' => $postId];
        $selectCat = '0';
        $joinCat = '';
        if ($idCategorii !== []) {
            $locuri = [];
            foreach ($idCategorii as $index => $idCategorie) {
                $cheie = 'cat' . $index;
                $locuri[] = ':' . $cheie;
                $parametri[$cheie] = $idCategorie;
            }
            $joinCat = 'LEFT JOIN blog_post_categories pc ON pc.post_id = p.id AND pc.category_id IN ('
                . implode(',', $locuri) . ')';
            $selectCat = 'COUNT(DISTINCT pc.category_id)';
        }

        $selectTag = '0';
        $joinTag = '';
        if ($idEtichete !== []) {
            $locuri = [];
            foreach ($idEtichete as $index => $idEticheta) {
                $cheie = 'tag' . $index;
                $locuri[] = ':' . $cheie;
                $parametri[$cheie] = $idEticheta;
            }
            $joinTag = 'LEFT JOIN blog_post_tags pt ON pt.post_id = p.id AND pt.tag_id IN ('
                . implode(',', $locuri) . ')';
            $selectTag = 'COUNT(DISTINCT pt.tag_id)';
        }

        try {
            $stmt = $db->prepare(
                "SELECT p.id, p.title, p.slug, p.category, p.featured_image_url, p.reading_minutes, p.published_at,
                        {$selectCat} AS comune_categorii, {$selectTag} AS comune_etichete
                 FROM blog_posts p
                 {$joinCat}
                 {$joinTag}
                 WHERE p.id <> :id
                   AND p.deleted_at IS NULL
                   AND p.is_published = 1
                   AND p.published_at <= NOW()
                 GROUP BY p.id, p.title, p.slug, p.category, p.featured_image_url, p.reading_minutes, p.published_at
                 HAVING comune_categorii > 0 OR comune_etichete > 0
                 ORDER BY (comune_categorii * 2 + comune_etichete) DESC, p.published_at DESC
                 LIMIT " . (int) $limit
            );
            $stmt->execute($parametri);

            return $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param int[] $deSarit
     *
     * @return array<int, array<string, mixed>>
     */
    private static function celeRecente(PDO $db, array $deSarit, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        $deSarit = array_values(array_unique(array_filter($deSarit, static fn (int $id): bool => $id > 0)));
        $conditie = '';
        $parametri = [];
        if ($deSarit !== []) {
            $locuri = [];
            foreach ($deSarit as $index => $id) {
                $cheie = 'skip' . $index;
                $locuri[] = ':' . $cheie;
                $parametri[$cheie] = $id;
            }
            $conditie = 'AND p.id NOT IN (' . implode(',', $locuri) . ')';
        }

        try {
            $stmt = $db->prepare(
                "SELECT p.id, p.title, p.slug, p.category, p.featured_image_url, p.reading_minutes, p.published_at
                 FROM blog_posts p
                 WHERE p.deleted_at IS NULL
                   AND p.is_published = 1
                   AND p.published_at <= NOW()
                   {$conditie}
                 ORDER BY p.published_at DESC, p.id DESC
                 LIMIT " . (int) $limit
            );
            $stmt->execute($parametri);

            return $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $post
     *
     * @return array<string, mixed>
     */
    private static function decoreaza(array $post): array
    {
        $slug = trim((string) ($post['slug'] ?? ''));
        $imagine = trim((string) ($post['featured_image_url'] ?? ''));
        $publicat = trim((string) ($post['published_at'] ?? ''));

        $post['url'] = $slug !== '' ? '/blog/' . rawurlencode($slug) : '/blog';
        $post['cover_image_url'] = $imagine !== '' ? $imagine : '/assets/img/product-placeholder.svg';
        $post['published_at_label'] = $publicat !== '' ? date('d.m.Y', strtotime($publicat) ?: time()) : '';
        $post['category'] = trim((string) ($post['category'] ?? '')) ?: 'Articol';

        return $post;
    }
}
