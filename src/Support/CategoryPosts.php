<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Randeaza un slider responsive cu postarile dintr-o categorie de blog.
 * Folosit prin token-ul de pagina {{category_posts:slug-categorie}}.
 *
 * Din vertij (WP-24, VT-C4: b0aa083, e577b94, 01e2773, 76dd45e, 265db83, 67319f7, ff3c46e, c1abee4): cardurile de
 * eveniment în slider (doar pentru postările cu `event_start_date`), rândurile de evenimente
 * ({{category_event_rows:slug}}) și grila paginată din mai multe categorii ({{category_grid_paged:slug1,slug2}}).
 * Clasele `vtcs__`, `vtcg__`, `vter__` rămân (conținutul din baza vertij depinde de ele). Postările doar pentru membri
 * trec prin poarta nucleului (App\Support\AccesMembri), nu prin pluginul `membri`. Pozele au mărimile lor
 * (PozeOptimizate::dimensiuni), ca în restul codului comun.
 */
final class CategoryPosts
{
    public static function renderSlider(?PDO $db, string $slug, int $limit = 12): string
    {
        if (!$db instanceof PDO) {
            return '';
        }
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }

        $catId = 0;
        $catName = '';
        try {
            $stmt = $db->prepare('SELECT id, name FROM blog_categories WHERE slug = :slug LIMIT 1');
            $stmt->execute(['slug' => $slug]);
            $cat = $stmt->fetch() ?: null;
            if (is_array($cat)) {
                $catId = (int) ($cat['id'] ?? 0);
                $catName = (string) ($cat['name'] ?? '');
            }
        } catch (Throwable) {
        }

        $limit = max(1, min(50, $limit));
        $rows = [];
        try {
            $stmt = $db->prepare(
                "SELECT p.title, p.slug, p.excerpt, p.content, p.featured_image_url" . AccesMembri::coloanaSql($db, 'p') . ",
                        p.event_start_date, p.event_location,
                        COALESCE(c.name, p.category) AS cat_name
                 FROM blog_posts p
                 LEFT JOIN blog_categories c ON c.id = p.category_id
                 WHERE p.deleted_at IS NULL
                   AND p.is_published = 1
                   AND p.published_at <= NOW()" . AccesMembri::conditieSql($db, 'p') . "
                   AND (p.category_id = :catId OR p.category = :catName
                        OR EXISTS (SELECT 1 FROM blog_post_categories pc WHERE pc.post_id = p.id AND pc.category_id = :catIdJoin))
                 ORDER BY p.published_at DESC
                 LIMIT " . $limit
            );
            $stmt->execute(['catId' => $catId, 'catName' => $catName, 'catIdJoin' => $catId]);
            $rows = AccesMembri::filtreazaLista($db, $stmt->fetchAll() ?: []);
        } catch (Throwable) {
            $rows = [];
        }

        if ($rows === []) {
            return '<div class="vtcs"><p class="vtcs__empty">Momentan nu există articole în această categorie.</p></div>';
        }

        $cards = '';
        foreach ($rows as $p) {
            $postSlug = trim((string) ($p['slug'] ?? ''));
            $url = $postSlug !== '' ? '/blog/' . rawurlencode($postSlug) : '/blog';
            $img = trim((string) ($p['featured_image_url'] ?? '')) ?: '/assets/img/product-placeholder.svg';
            $title = trim((string) ($p['title'] ?? ''));
            $catLabel = trim((string) ($p['cat_name'] ?? ''));
            $start = trim((string) ($p['event_start_date'] ?? ''));

            $urlEsc = htmlspecialchars($url, ENT_QUOTES);
            $imgTag = '<img src="' . htmlspecialchars($img, ENT_QUOTES) . '" alt="' . htmlspecialchars($title, ENT_QUOTES) . '"' . PozeOptimizate::dimensiuni($img) . ' loading="lazy">';
            $titleEsc = htmlspecialchars($title, ENT_QUOTES);

            if ($start !== '') {
                // Card eveniment: badge dată colț + locație (fără excerpt)
                $badge = self::dateBadge($start);
                $location = trim((string) ($p['event_location'] ?? ''));
                $pin = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>';
                $metaRow = $location !== ''
                    ? '<span class="vtcs__meta">' . $pin . htmlspecialchars($location, ENT_QUOTES) . '</span>'
                    : '';

                $cards .= '<article class="vtcs__card vtcs__card--event">'
                    . '<a class="vtcs__media" href="' . $urlEsc . '">'
                    . $imgTag
                    . $badge
                    . '</a>'
                    . '<div class="vtcs__body">'
                    . '<h3 class="vtcs__title"><a href="' . $urlEsc . '">' . $titleEsc . '</a></h3>'
                    . ($metaRow !== '' ? '<div class="vtcs__metarow">' . $metaRow . '</div>' : '')
                    . '<a class="vtcs__link" href="' . $urlEsc . '">Vezi detalii →</a>'
                    . '</div>'
                    . '</article>';
            } else {
                // Card articol: categorie + excerpt + link citește
                $excerpt = self::excerptOf($p, 120);
                $cards .= '<article class="vtcs__card">'
                    . '<a class="vtcs__media" href="' . $urlEsc . '">'
                    . $imgTag
                    . '</a>'
                    . '<div class="vtcs__body">'
                    . ($catLabel !== '' ? '<span class="vtcs__cat">' . htmlspecialchars($catLabel, ENT_QUOTES) . '</span>' : '')
                    . '<h3 class="vtcs__title"><a href="' . $urlEsc . '">' . $titleEsc . '</a></h3>'
                    . ($excerpt !== '' ? '<p class="vtcs__excerpt">' . htmlspecialchars($excerpt, ENT_QUOTES) . '</p>' : '')
                    . '<a class="vtcs__link" href="' . $urlEsc . '">Citește →</a>'
                    . '</div>'
                    . '</article>';
            }
        }

        return '<div class="vtcs" data-vt-slider>'
            . '<button class="vtcs__nav vtcs__nav--prev" type="button" data-vt-prev aria-label="Anterior">‹</button>'
            . '<div class="vtcs__viewport" data-vt-vp>' . $cards . '</div>'
            . '<button class="vtcs__nav vtcs__nav--next" type="button" data-vt-next aria-label="Următor">›</button>'
            . '<div class="vtcs__dots" data-vt-dots></div>'
            . '</div>';
    }

    /**
     * Grilă responsivă cu postările dintr-o categorie. Pentru evenimente
     * (postări cu event_start_date) ordonează: viitoarele primele (cele mai
     * apropiate sus), apoi cele trecute, marcate discret ca „Trecut".
     * Folosit prin token-ul de pagină {{category_grid:slug-categorie}}.
     */
    public static function renderGrid(?PDO $db, string $slug, int $limit = 60): string
    {
        $rows = self::fetchPosts($db, $slug, $limit);
        if ($rows === null) {
            return '';
        }
        if ($rows === []) {
            return '<div class="vtcg"><p class="vtcg__empty">Momentan nu există articole în această categorie.</p></div>';
        }

        $today = date('Y-m-d');
        $cards = '';
        foreach ($rows as $p) {
            $cards .= self::gridCard($p, $today);
        }

        return '<div class="vtcg">' . $cards . '</div>';
    }

    /**
     * Randează postările unei categorii ca rânduri compacte: imagine mică în
     * stânga (sau iconiță calendar dacă nu are imagine), titlu, descriere, dată +
     * locație și link „Detalii →".
     * Folosit prin token-ul de pagină {{category_event_rows:slug-categorie}}.
     */
    public static function renderEventRows(?PDO $db, string $slug, int $limit = 20): string
    {
        $rows = self::fetchPosts($db, $slug, $limit);
        if ($rows === null) {
            return '';
        }
        if ($rows === []) {
            return '<div class="vter"><p class="vter__empty">Momentan nu există evenimente în această categorie.</p></div>';
        }

        $today = date('Y-m-d');
        $out = '';
        foreach ($rows as $p) {
            $out .= self::eventRow($p, $today);
        }

        return '<div class="vter" data-vter data-vter-page-size="3">'
            . '<div class="vter__list" data-vter-list>' . $out . '</div>'
            . '<div class="vter__pager" data-vter-pager></div>'
            . '</div>';
    }

    /**
     * Grilă cu postările din una sau mai multe categorii (slug-uri separate prin
     * virgulă), paginată. Folosit prin {{category_grid_paged:slug1,slug2,...}}.
     */
    public static function renderCategoryGridPaged(?PDO $db, string $slugsCsv, int $perPage = 6): string
    {
        $slugs = array_map('trim', explode(',', $slugsCsv));
        $rows = self::fetchPostsMulti($db, $slugs, 200);
        if ($rows === null) {
            return '';
        }
        if ($rows === []) {
            return '<div class="vtcg"><p class="vtcg__empty">Momentan nu există articole în aceste categorii.</p></div>';
        }

        $today = date('Y-m-d');
        $cards = '';
        foreach ($rows as $p) {
            $cards .= self::gridCard($p, $today);
        }
        $size = max(1, min(48, $perPage));

        return '<div class="vter" data-vter data-vter-page-size="' . $size . '">'
            . '<div class="vtcg" data-vter-list>' . $cards . '</div>'
            . '<div class="vter__pager" data-vter-pager></div>'
            . '</div>';
    }

    /**
     * Aduce postările publicate din mai multe categorii (după slug), deduplicate,
     * cu evenimentele viitoare primele.
     * @param array<int,string> $slugs
     * @return array<int,array<string,mixed>>|null
     */
    private static function fetchPostsMulti(?PDO $db, array $slugs, int $limit): ?array
    {
        if (!$db instanceof PDO) {
            return null;
        }
        $slugs = array_values(array_unique(array_filter(
            array_map(static fn ($s): string => trim((string) $s), $slugs),
            static fn (string $s): bool => $s !== ''
        )));
        if ($slugs === []) {
            return null;
        }

        $ids = [];
        $names = [];
        try {
            $ph = implode(',', array_fill(0, count($slugs), '?'));
            $stmt = $db->prepare('SELECT id, name FROM blog_categories WHERE slug IN (' . $ph . ')');
            $stmt->execute($slugs);
            foreach ($stmt->fetchAll() ?: [] as $c) {
                $cid = (int) ($c['id'] ?? 0);
                if ($cid > 0) {
                    $ids[] = $cid;
                }
                $nm = trim((string) ($c['name'] ?? ''));
                if ($nm !== '') {
                    $names[] = $nm;
                }
            }
        } catch (Throwable) {
        }
        if ($ids === [] && $names === []) {
            $names = $slugs;
        }

        $conds = [];
        $params = [];
        if ($ids !== []) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $conds[] = 'p.category_id IN (' . $ph . ')';
            foreach ($ids as $v) {
                $params[] = $v;
            }
            $conds[] = 'EXISTS (SELECT 1 FROM blog_post_categories pc WHERE pc.post_id = p.id AND pc.category_id IN (' . $ph . '))';
            foreach ($ids as $v) {
                $params[] = $v;
            }
        }
        if ($names !== []) {
            $ph = implode(',', array_fill(0, count($names), '?'));
            $conds[] = 'p.category IN (' . $ph . ')';
            foreach ($names as $v) {
                $params[] = $v;
            }
        }
        if ($conds === []) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        try {
            $sql = "SELECT p.id, p.title, p.slug, p.excerpt, p.content, p.featured_image_url" . AccesMembri::coloanaSql($db, 'p') . ",
                           p.event_start_date, p.event_end_date, p.event_location
                    FROM blog_posts p
                    WHERE p.deleted_at IS NULL AND p.is_published = 1 AND p.published_at <= NOW()" . AccesMembri::conditieSql($db, 'p') . "
                      AND (" . implode(' OR ', $conds) . ")
                    GROUP BY p.id
                    ORDER BY
                      (p.event_start_date IS NOT NULL AND p.event_start_date >= CURDATE()) DESC,
                      CASE WHEN p.event_start_date >= CURDATE() THEN p.event_start_date END ASC,
                      COALESCE(p.event_start_date, p.published_at) DESC
                    LIMIT " . $limit;
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return AccesMembri::filtreazaLista($db, $stmt->fetchAll() ?: []);
        } catch (Throwable) {
            return [];
        }
    }

    private const CAL_ICON_LG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="26" height="26"><rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M3 9h18M8 2.5v4M16 2.5v4"/></svg>';

    /**
     * @param array<string,mixed> $p
     */
    private static function eventRow(array $p, string $today): string
    {
        $postSlug = trim((string) ($p['slug'] ?? ''));
        $url = $postSlug !== '' ? '/blog/' . rawurlencode($postSlug) : '/blog';
        $title = trim((string) ($p['title'] ?? ''));
        $excerpt = self::excerptOf($p, 120);
        $img = trim((string) ($p['featured_image_url'] ?? ''));
        $meta = self::eventMeta($p, $today);
        $location = trim((string) ($p['event_location'] ?? ''));

        $mediaStyle = $img !== '' ? ' style="background-image:url(\'' . htmlspecialchars($img, ENT_QUOTES) . '\')"' : '';
        $mediaInner = $img !== '' ? '' : self::CAL_ICON_LG;
        $mediaClass = 'vter__media' . ($img === '' ? ' vter__media--ico' : '');

        $metaBits = '';
        if ($meta['period'] !== '') {
            $metaBits .= '<span class="vter__date">' . self::CAL_ICON . htmlspecialchars($meta['period'], ENT_QUOTES) . '</span>';
        }
        if ($location !== '') {
            $metaBits .= '<span class="vter__loc">' . htmlspecialchars($location, ENT_QUOTES) . '</span>';
        }

        return '<div class="vter__row' . ($meta['isPast'] ? ' is-past' : '') . '">'
            . '<a class="' . $mediaClass . '" href="' . htmlspecialchars($url, ENT_QUOTES) . '"' . $mediaStyle . '>' . $mediaInner . '</a>'
            . '<div class="vter__main">'
            . '<h3 class="vter__title"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($title, ENT_QUOTES) . '</a></h3>'
            . ($excerpt !== '' ? '<p class="vter__desc">' . htmlspecialchars($excerpt, ENT_QUOTES) . '</p>' : '')
            . '</div>'
            . ($metaBits !== '' ? '<div class="vter__meta">' . $metaBits . '</div>' : '')
            . '<a class="vter__link" href="' . htmlspecialchars($url, ENT_QUOTES) . '">Detalii →</a>'
            . '</div>';
    }

    /**
     * Pagină de evenimente: hero cu următorul eveniment + două secțiuni
     * („Evenimente viitoare" și „Evenimente trecute").
     * Folosit prin token-ul de pagină {{category_events:slug-categorie}}.
     */
    public static function renderEventsPage(?PDO $db, string $slug, int $limit = 200): string
    {
        $rows = self::fetchPosts($db, $slug, $limit);
        if ($rows === null) {
            return '';
        }
        if ($rows === []) {
            return '<div class="vte"><p class="vtcg__empty">Momentan nu există evenimente programate.</p></div>';
        }

        $today = date('Y-m-d');
        $upcoming = [];
        $past = [];
        foreach ($rows as $p) {
            $start = trim((string) ($p['event_start_date'] ?? ''));
            $end = trim((string) ($p['event_end_date'] ?? ''));
            $refDate = $end !== '' ? $end : $start;
            // fără dată → tratăm ca „viitor" (ține-l vizibil sus, nu îl ascunde în „trecut")
            if ($refDate === '' || $refDate >= $today) {
                $upcoming[] = $p;
            } else {
                $past[] = $p;
            }
        }

        $html = '<div class="vte">';

        // Hero = primul eveniment viitor (fetchPosts e deja ordonat cu cele mai apropiate primele)
        $heroRendered = false;
        if ($upcoming !== []) {
            $html .= self::heroCard($upcoming[0], $today);
            $heroRendered = true;
        }

        // Restul evenimentelor viitoare (fără cel din hero)
        $restUpcoming = $heroRendered ? array_slice($upcoming, 1) : $upcoming;
        if ($restUpcoming !== []) {
            $cards = '';
            foreach ($restUpcoming as $p) {
                $cards .= self::gridCard($p, $today);
            }
            $html .= '<section class="vte__section">'
                . '<h2 class="vte__h2">Evenimente viitoare</h2>'
                . '<div class="vtcg">' . $cards . '</div>'
                . '</section>';
        } elseif (!$heroRendered) {
            $html .= '<section class="vte__section"><p class="vtcg__empty">Niciun eveniment viitor programat momentan.</p></section>';
        }

        // Evenimente trecute
        if ($past !== []) {
            $cards = '';
            foreach ($past as $p) {
                $cards .= self::gridCard($p, $today);
            }
            $html .= '<section class="vte__section">'
                . '<h2 class="vte__h2">Evenimente trecute</h2>'
                . '<div class="vtcg">' . $cards . '</div>'
                . '</section>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Aduce postările publicate dintr-o categorie, ordonate cu evenimentele
     * viitoare primele (cele mai apropiate), apoi cele trecute.
     * @return array<int,array<string,mixed>>|null null dacă nu se poate interoga
     */
    private static function fetchPosts(?PDO $db, string $slug, int $limit): ?array
    {
        if (!$db instanceof PDO) {
            return null;
        }
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $catId = 0;
        $catName = '';
        try {
            $stmt = $db->prepare('SELECT id, name FROM blog_categories WHERE slug = :slug LIMIT 1');
            $stmt->execute(['slug' => $slug]);
            $cat = $stmt->fetch() ?: null;
            if (is_array($cat)) {
                $catId = (int) ($cat['id'] ?? 0);
                $catName = (string) ($cat['name'] ?? '');
            }
        } catch (Throwable) {
        }

        $limit = max(1, min(200, $limit));
        try {
            $stmt = $db->prepare(
                "SELECT p.title, p.slug, p.excerpt, p.content, p.featured_image_url" . AccesMembri::coloanaSql($db, 'p') . ",
                        p.event_start_date, p.event_end_date, p.event_location,
                        COALESCE(c.name, p.category) AS cat_name
                 FROM blog_posts p
                 LEFT JOIN blog_categories c ON c.id = p.category_id
                 WHERE p.deleted_at IS NULL
                   AND p.is_published = 1
                   AND p.published_at <= NOW()" . AccesMembri::conditieSql($db, 'p') . "
                   AND (p.category_id = :catId OR p.category = :catName
                        OR EXISTS (SELECT 1 FROM blog_post_categories pc WHERE pc.post_id = p.id AND pc.category_id = :catIdJoin))
                 ORDER BY
                   (p.event_start_date IS NOT NULL AND p.event_start_date >= CURDATE()) DESC,
                   CASE WHEN p.event_start_date >= CURDATE() THEN p.event_start_date END ASC,
                   COALESCE(p.event_start_date, p.published_at) DESC
                 LIMIT " . $limit
            );
            $stmt->execute(['catId' => $catId, 'catName' => $catName, 'catIdJoin' => $catId]);
            return AccesMembri::filtreazaLista($db, $stmt->fetchAll() ?: []);
        } catch (Throwable) {
            return [];
        }
    }

    private static function fmtDate(string $d): string
    {
        $d = trim($d);
        return $d !== '' ? date('d.m.Y', strtotime($d) ?: time()) : '';
    }

    private const MONTHS_ABBR = [
        1 => 'IAN', 2 => 'FEB', 3 => 'MAR', 4 => 'APR', 5 => 'MAI', 6 => 'IUN',
        7 => 'IUL', 8 => 'AUG', 9 => 'SEP', 10 => 'OCT', 11 => 'NOI', 12 => 'DEC',
    ];

    /**
     * Badge de dată în colț (ziua mare + luna abreviată în română).
     */
    private static function dateBadge(string $date): string
    {
        $ts = strtotime(trim($date));
        if ($ts === false) {
            return '';
        }
        $day = date('j', $ts);
        $month = self::MONTHS_ABBR[(int) date('n', $ts)] ?? '';
        return '<span class="vtcs__date">'
            . '<span class="vtcs__date-day">' . htmlspecialchars($day, ENT_QUOTES) . '</span>'
            . '<span class="vtcs__date-mon">' . htmlspecialchars($month, ENT_QUOTES) . '</span>'
            . '</span>';
    }

    /**
     * @param array<string,mixed> $p
     * @return array{period:string,refDate:string,isPast:bool}
     */
    private static function eventMeta(array $p, string $today): array
    {
        $start = trim((string) ($p['event_start_date'] ?? ''));
        $end = trim((string) ($p['event_end_date'] ?? ''));
        $startLabel = self::fmtDate($start);
        $endLabel = self::fmtDate($end);
        if ($startLabel !== '' && $endLabel !== '' && $startLabel !== $endLabel) {
            $period = $startLabel . ' – ' . $endLabel;
        } else {
            $period = $startLabel;
        }
        $refDate = $end !== '' ? $end : $start;
        return [
            'period' => $period,
            'refDate' => $refDate,
            'isPast' => $refDate !== '' && $refDate < $today,
        ];
    }

    private static function excerptOf(array $p, int $len): string
    {
        $excerpt = trim((string) ($p['excerpt'] ?? ''));
        if ($excerpt === '') {
            $plain = trim(strip_tags((string) ($p['content'] ?? '')));
            $excerpt = mb_substr($plain, 0, $len) . (mb_strlen($plain) > $len ? '…' : '');
        }
        return $excerpt;
    }

    private const CAL_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="13" height="13"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>';

    /**
     * @param array<string,mixed> $p
     */
    private static function gridCard(array $p, string $today): string
    {
        $postSlug = trim((string) ($p['slug'] ?? ''));
        $url = $postSlug !== '' ? '/blog/' . rawurlencode($postSlug) : '/blog';
        $img = trim((string) ($p['featured_image_url'] ?? '')) ?: '/assets/img/product-placeholder.svg';
        $title = trim((string) ($p['title'] ?? ''));
        $excerpt = self::excerptOf($p, 130);
        $meta = self::eventMeta($p, $today);

        $badge = $meta['period'] !== ''
            ? '<span class="vtcg__badge">' . self::CAL_ICON . htmlspecialchars($meta['period'], ENT_QUOTES) . '</span>'
            : '';
        $cardClass = 'vtcg__card' . ($meta['isPast'] ? ' is-past' : '');
        $pastTag = $meta['isPast'] ? '<span class="vtcg__past-tag">Trecut</span>' : '';

        return '<article class="' . $cardClass . '">'
            . '<a class="vtcg__media" href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
            . '<img src="' . htmlspecialchars($img, ENT_QUOTES) . '" alt="' . htmlspecialchars($title, ENT_QUOTES) . '"' . PozeOptimizate::dimensiuni($img) . ' loading="lazy">'
            . $badge . $pastTag
            . '</a>'
            . '<div class="vtcg__body">'
            . '<h3 class="vtcg__title"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($title, ENT_QUOTES) . '</a></h3>'
            . ($excerpt !== '' ? '<p class="vtcg__excerpt">' . htmlspecialchars($excerpt, ENT_QUOTES) . '</p>' : '')
            . '<a class="vtcg__link" href="' . htmlspecialchars($url, ENT_QUOTES) . '">» Află mai mult</a>'
            . '</div>'
            . '</article>';
    }

    /**
     * @param array<string,mixed> $p
     */
    private static function heroCard(array $p, string $today): string
    {
        $postSlug = trim((string) ($p['slug'] ?? ''));
        $url = $postSlug !== '' ? '/blog/' . rawurlencode($postSlug) : '/blog';
        $img = trim((string) ($p['featured_image_url'] ?? '')) ?: '/assets/img/product-placeholder.svg';
        $title = trim((string) ($p['title'] ?? ''));
        $excerpt = self::excerptOf($p, 220);
        $meta = self::eventMeta($p, $today);
        $location = trim((string) ($p['event_location'] ?? ''));

        $metaRow = '';
        if ($meta['period'] !== '') {
            $metaRow .= '<span class="vte__hero-meta">' . self::CAL_ICON . htmlspecialchars($meta['period'], ENT_QUOTES) . '</span>';
        }
        if ($location !== '') {
            $pin = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="13" height="13"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>';
            $metaRow .= '<span class="vte__hero-meta">' . $pin . htmlspecialchars($location, ENT_QUOTES) . '</span>';
        }

        return '<section class="vte__hero">'
            . '<a class="vte__hero-media" href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
            . '<img src="' . htmlspecialchars($img, ENT_QUOTES) . '" alt="' . htmlspecialchars($title, ENT_QUOTES) . '"' . PozeOptimizate::dimensiuni($img) . '>'
            . '</a>'
            . '<div class="vte__hero-body">'
            . '<span class="vte__hero-flag">Următorul eveniment</span>'
            . '<h2 class="vte__hero-title"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($title, ENT_QUOTES) . '</a></h2>'
            . ($metaRow !== '' ? '<div class="vte__hero-metarow">' . $metaRow . '</div>' : '')
            . ($excerpt !== '' ? '<p class="vte__hero-excerpt">' . htmlspecialchars($excerpt, ENT_QUOTES) . '</p>' : '')
            . '<a class="vte__hero-btn" href="' . htmlspecialchars($url, ENT_QUOTES) . '">Află mai mult</a>'
            . '</div>'
            . '</section>';
    }
}
