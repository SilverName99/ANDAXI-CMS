<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Secțiuni dinamice folosite în paginile custom din admin.
 *
 * Sunt disponibile ca placeholdere în HTML-ul paginii:
 *   {{featured_products:4}}   - grilă cu ultimele N produse
 *   {{latest_posts:3}}        - ultimele N articole de blog
 *   {{product_categories:6}}  - primele N categorii, cu iconițe
 *
 * Markup-ul generat folosește clasele `bs-*`, stilizate din CSS-ul paginii.
 */
final class HomeSections
{
    /**
     * Iconița unei categorii fără iconiță în tema site-ului (WP-16, DB-10). Iconițele alese după numele categoriei
     * sunt ale temei site-ului: tema.json → iconite_categorii.
     */
    private const ICONITA_IMPLICITA = '<path d="M19.1 5.1c-6 .2-10.4 3.9-10.4 6.5 0 2.9 2 4.9 4.5 4.9 4.2 0 7.7-4 8.2-9.6.1-.9-.6-1.8-2.3-1.8Z"/><path d="M6.6 20.4c2.1-4.7 6.1-8 11.3-10.2"/>';

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }

    private static function iconFor(string $label): string
    {
        $normalized = mb_strtolower($label);
        $normalized = strtr($normalized, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);
        // Tema site-ului își aduce iconițele (tema.json → iconite_categorii, perechi [cuvânt, svg] încercate în ordine,
        // fiecare svg trecut prin App\Support\SvgSigur); o categorie fără potrivire primește iconița implicită.
        foreach (Tema::iconiteCategorii() ?? [] as [$cuvant, $svg]) {
            if (str_contains($normalized, $cuvant)) {
                return $svg;
            }
        }
        return self::ICONITA_IMPLICITA;
    }

    /** Linkul categoriei: /categorie/{slug} (vezi LinkuriCategorii). */
    private static function categoryUrl(string $name, ?PDO $db = null): string
    {
        return LinkuriCategorii::url($db, $name);
    }

    // -----------------------------------------------------------------
    // Produse
    // -----------------------------------------------------------------

    public static function renderFeaturedProducts(?PDO $db, int $limit = 4): string
    {
        if (!$db instanceof PDO) {
            return '';
        }

        $limit = max(1, min(24, $limit));

        try {
            $stmt = $db->prepare(
                'SELECT id, name, slug, price, sale_price, image_url, out_of_stock
                 FROM products
                 WHERE deleted_at IS NULL AND is_active = 1
                 ORDER BY id DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return '';
        }

        // Disponibilitatea reală, când site-ul e legat de ERP.
        ErpStock::applyToProducts($db, $rows);

        $cards = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $slug = trim((string) ($row['slug'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($slug === '' || $name === '') {
                continue;
            }

            $basePrice = max(0.0, (float) ($row['price'] ?? 0));
            $salePrice = (float) ($row['sale_price'] ?? 0);
            $hasSale = $salePrice > 0 && $salePrice < $basePrice;
            $price = $hasSale ? $salePrice : $basePrice;

            $url = '/produs/' . rawurlencode($slug);
            $image = trim((string) ($row['image_url'] ?? '')) ?: '/assets/img/product-placeholder.svg';
            // Pozele mari refăcute (A8) se afișează din fișierul nou, mai mic. Fără
            // width/height aici: stilul cardului vine din pagina din constructor
            // (azi doar max-width/max-height într-o cutie de înălțime fixă), iar
            // dimensiunile ar schimba cutia pozei.
            $image = PozeOptimizate::src($image);
            $outOfStock = (int) ($row['out_of_stock'] ?? 0) === 1;

            $action = $outOfStock
                ? '<span class="bs-product-card__out">Stoc epuizat</span>'
                : '<form method="post" action="/cos/adauga/' . (int) ($row['id'] ?? 0) . '">'
                    . '<button class="bs-product-card__cart" type="submit">'
                    . '<svg viewBox="0 0 24 24" aria-hidden="true">'
                    . '<path d="M3 4h2l1.7 8.1a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 1.9-1.4l1.5-5.2H7.4"/>'
                    . '<circle cx="8.7" cy="19" r="1.4"/><circle cx="17.5" cy="19" r="1.4"/>'
                    . '</svg>Adauga in cos</button></form>';

            $priceHtml = ($hasSale
                    ? '<span class="bs-product-card__price-old">' . number_format($basePrice, 2, ',', '.') . ' lei</span>'
                    : '')
                . '<span>' . number_format($price, 2, ',', '.') . ' lei</span>';

            // Fără magazin (catalog de prezentare): fără preț și fără coș; când
            // magazinul se oprește, prețul rămâne, dar nu se mai adaugă în coș.
            if (!Plugins::permiteInceput('magazin')) {
                $action = '';
            }
            if (!Plugins::vizibil('magazin')) {
                $priceHtml = '';
            }

            $cards[] = '<article class="bs-product-card">'
                . '<a class="bs-product-card__media" href="' . self::e($url) . '">'
                . '<img src="' . self::e($image) . '" alt="' . self::e($name) . '" loading="lazy" decoding="async"'
                . ' onerror="this.onerror=null;this.src=\'/assets/img/product-placeholder.svg\';"></a>'
                . '<div class="bs-product-card__body">'
                . '<a class="bs-product-card__name" href="' . self::e($url) . '">' . self::e($name) . '</a>'
                . ($priceHtml !== '' ? '<p class="bs-product-card__price">' . $priceHtml . '</p>' : '')
                . $action
                . '</div></article>';
        }

        return $cards === [] ? '' : '<div class="bs-products-grid">' . implode('', $cards) . '</div>';
    }

    // -----------------------------------------------------------------
    // Articole
    // -----------------------------------------------------------------

    public static function renderLatestPosts(?PDO $db, int $limit = 3): string
    {
        if (!$db instanceof PDO) {
            return '';
        }

        $limit = max(1, min(12, $limit));

        try {
            $stmt = $db->prepare(
                'SELECT p.title, p.slug, p.excerpt, p.content, p.featured_image_url,
                        COALESCE(a.name, "") AS author_name
                 FROM blog_posts p
                 LEFT JOIN blog_authors a ON a.id = p.author_id
                 WHERE p.deleted_at IS NULL
                   AND p.is_published = 1
                   AND p.published_at <= NOW()
                 ORDER BY p.published_at DESC, p.id DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return '';
        }

        $cards = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? ''));
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($title === '') {
                continue;
            }

            $url = $slug !== '' ? '/blog/' . rawurlencode($slug) : '/blog';
            $image = trim((string) ($row['featured_image_url'] ?? '')) ?: '/assets/img/product-placeholder.svg';

            $excerpt = trim((string) ($row['excerpt'] ?? ''));
            if ($excerpt === '') {
                $excerpt = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) ($row['content'] ?? ''))));
                $excerpt = mb_substr($excerpt, 0, 140);
            }

            $author = trim((string) ($row['author_name'] ?? ''));

            $cards[] = '<article class="bs-post">'
                . '<a class="bs-post__media" href="' . self::e($url) . '">'
                . '<img src="' . self::e($image) . '" alt="' . self::e($title) . '" loading="lazy" decoding="async"'
                . ' onerror="this.onerror=null;this.src=\'/assets/img/product-placeholder.svg\';"></a>'
                . '<div class="bs-post__body">'
                . ($author !== '' ? '<span class="bs-post__tag">' . self::e($author) . '</span>' : '')
                . '<a class="bs-post__title" href="' . self::e($url) . '">' . self::e($title) . '</a>'
                . ($excerpt !== '' ? '<p class="bs-post__text">' . self::e($excerpt) . '</p>' : '')
                . '<a class="bs-link bs-link--small" href="' . self::e($url) . '">Citeste articolul <span aria-hidden="true">&rarr;</span></a>'
                . '</div></article>';
        }

        return $cards === [] ? '' : '<div class="bs-posts-grid">' . implode('', $cards) . '</div>';
    }

    // -----------------------------------------------------------------
    // Categorii
    // -----------------------------------------------------------------

    public static function renderCategoryTiles(?PDO $db, int $limit = 6): string
    {
        if (!$db instanceof PDO) {
            return '';
        }

        $limit = max(1, min(12, $limit));

        try {
            $stmt = $db->prepare(
                'SELECT TRIM(category) AS name, COUNT(*) AS products_count
                 FROM products
                 WHERE deleted_at IS NULL
                   AND is_active = 1
                   AND TRIM(COALESCE(category, "")) <> ""
                 GROUP BY TRIM(category)
                 ORDER BY products_count DESC, name ASC
                 LIMIT :limit'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return '';
        }

        $tiles = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $tiles[] = '<a class="bs-need" href="' . self::e(self::categoryUrl($name, $db)) . '">'
                . '<span class="bs-need__icon"><svg viewBox="0 0 24 24" aria-hidden="true">'
                . self::iconFor($name)
                . '</svg></span>'
                . '<span class="bs-need__title">' . self::e($name) . '</span>'
                . '<span class="bs-need__arrow" aria-hidden="true">&rarr;</span>'
                . '</a>';
        }

        return $tiles === [] ? '' : '<div class="bs-needs-grid">' . implode('', $tiles) . '</div>';
    }
}
