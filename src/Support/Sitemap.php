<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Sitemap-ul, construit din baza de date la fiecare cerere a lui /sitemap.xml.
 *
 * Conține doar pagini care răspund 200 și pot intra în Google, și doar ale
 * pluginurilor pe care site-ul le are:
 *  - nucleul: prima pagină, paginile publicate (fără cele de sistem: coș,
 *    checkout, cont, login, înregistrare, 404, acorduri GDPR, nici paginile
 *    pluginurilor care nu sunt pe site), /contact;
 *  - catalogul: /magazin, categoriile și mărcile care au cel puțin un produs
 *    activ, etichetele după regula site-ului (vezi etichetaInSitemap), produsele
 *    active;
 *  - blogul: /blog (doar dacă are articole) și articolele publicate.
 * Nimic din ce blochează robots.txt. <lastmod> vine din data ultimei
 * modificări, unde există.
 *
 * Regulile etichetelor sunt ale fiecărui site (seo.json → sitemap):
 *  - eticheta_minim_produse: de la câte produse active intră o etichetă (implicit 1);
 *  - etichete_pastrate: etichete care intră și sub acest minim (dacă au produse);
 *  - etichete_excluse: etichete care nu intră niciodată (de exemplu nume de boli).
 * Paginile etichetelor rămân neschimbate; lipsa din sitemap nu le scoate din site.
 */
final class Sitemap
{
    /** Pagini de sistem: există ca rânduri în `pages`, dar n-au ce căuta în Google. */
    public const PAGINI_SISTEM = [
        '', 'acasa', 'home', '404', 'cos', 'checkout', 'contul-meu',
        'login', 'register', 'acorduri-gdpr',
    ];

    /**
     * Eticheta e scoasă de site din Google (seo.json → sitemap.etichete_excluse,
     * de exemplu etichetele cu nume de boli ale Bioscem, decizia D3)? Paginile lor
     * nu primesc nici descriere automată (SiteController::seoListaFiltrata).
     */
    public static function etichetaExclusa(string $slug): bool
    {
        return in_array($slug, array_map('strval', (array) DateSite::seo('sitemap.etichete_excluse', [])), true);
    }

    /** Eticheta merge în sitemap? (după numărul de produse active și regulile site-ului) */
    public static function etichetaInSitemap(string $slug, int $produseActive): bool
    {
        if ($slug === '' || $produseActive < 1 || self::etichetaExclusa($slug)) {
            return false;
        }
        $minim = max(1, (int) DateSite::seo('sitemap.eticheta_minim_produse', 1));
        $pastrate = array_map('strval', (array) DateSite::seo('sitemap.etichete_pastrate', []));
        return $produseActive >= $minim || in_array($slug, $pastrate, true);
    }

    /**
     * Toate adresele, în ordinea de până acum (prima pagină, paginile, catalogul, contactul, categoriile, mărcile,
     * etichetele, produsele, blogul, articolele, apoi categoriile și etichetele blogului), fiecare cu tipul sitemap-ului
     * ei (`TIPURI`), data ultimei modificări (doar unde există; altfel goală) și pozele (produsele, articolele).
     *
     * @return list<array{loc: string, lastmod: string, tip: string, imagini: list<string>}>
     */
    public static function urls(PDO $db, string $baseUrl): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $urls = [];
        $vazute = [];
        $adauga = static function (string $cale, string $lastmod = '', string $tip = 'pagini', array $imagini = [])
            use (&$urls, &$vazute, $baseUrl): void {
            if ($cale !== '/' && Robots::blocheaza($cale)) {
                return;
            }
            // Adresele pluginurilor pe care site-ul nu le are nu intră (ar da 404).
            if (!Plugins::adresaVizibila($cale)) {
                return;
            }
            $loc = $baseUrl . $cale;
            if (isset($vazute[$loc])) {
                return;
            }
            $vazute[$loc] = true;
            $urls[] = ['loc' => $loc, 'lastmod' => $lastmod, 'tip' => $tip, 'imagini' => $imagini];
        };

        $cuCatalog = Plugins::vizibil('catalog');
        // WP-19 (GR-09): pagina catalogului (catalog_pagina; implicit „magazin”), o singură dată, cu data celui mai nou
        // produs; /magazin, care atunci face 301 spre ea, nu intră.
        $paginaCatalog = ltrim(Catalog::urlDinBaza($db), '/');
        $cuBlog = Plugins::vizibil('blog');
        $articole = $cuBlog ? self::articolePublicate($db) : [];
        $produse = $cuCatalog ? self::produseActive($db) : [];
        // Revizuirea WP-37/38: paginile pe care omul le-a scos din Google (robots „noindex”, panoul SEO) nu intră.
        $noindex = Seo::refuriNoindex($db);
        $scoasa = static fn (string $tip, string $ref): bool => isset($noindex[$tip][$ref]);
        $catalogScos = $scoasa('listing_default', Seo::REF_LISTA);

        // Prima pagină, cu data paginii „Acasă” din constructor.
        $acasa = self::interogare($db,
            "SELECT updated_at, created_at FROM pages
             WHERE (slug = '' OR LOWER(slug) IN ('acasa', 'home')) AND is_published = 1 AND deleted_at IS NULL
             ORDER BY CASE WHEN slug = '' THEN 0 WHEN LOWER(slug) = 'acasa' THEN 1 ELSE 2 END, id DESC LIMIT 1");
        $adauga('/', self::lastmod($acasa[0] ?? []));

        // Paginile din constructor.
        // WP-24 (VT-X2): paginile doar pentru membri nu intră niciodată (403 sau 404, după pluginul `membri`).
        foreach (self::interogare($db, 'SELECT id, slug, updated_at, created_at FROM pages WHERE is_published = 1 AND deleted_at IS NULL'
            . AccesMembri::conditieSql($db, 'pages', 'pages', true) . ' ORDER BY id ASC') as $pagina) {
            $slug = trim(trim((string) ($pagina['slug'] ?? '')), '/');
            $slugMic = mb_strtolower($slug);
            if (in_array($slugMic, self::PAGINI_SISTEM, true)
                || str_starts_with($slugMic, 'checkout/') || str_starts_with($slugMic, 'contul-meu/')
                || preg_match('/[\s{}<>"]/', $slug) === 1) {
                continue;
            }
            // Pagina unui plugin pe care site-ul nu-l are (magazin, blog, coș…), după slug.
            $proprietar = Plugins::proprietarPagina($slug);
            if ($proprietar !== null && !Plugins::vizibil($proprietar)) {
                continue;
            }
            // Blogul gol nu se trimite lui Google (o pagină fără conținut).
            if ($slugMic === 'blog' && $articole === []) {
                continue;
            }
            // Cu catalogul pe altă pagină (WP-19, GR-09), pagina „magazin” face 301 acolo: nu intră.
            if ($slugMic === 'magazin' && $paginaCatalog !== 'magazin' && $cuCatalog) {
                continue;
            }
            if ($scoasa('custom_page', (string) ($pagina['id'] ?? '')) || ($slugMic === $paginaCatalog && $cuCatalog && $catalogScos)) {
                continue;
            }
            $lastmod = self::lastmod($pagina);
            if ($slugMic === $paginaCatalog) {
                $lastmod = self::celMaiNou([$lastmod, ...array_column($produse, 'lastmod')]);
            }
            $adauga('/' . self::codeazaCale($slug), $lastmod);
        }

        if ($cuCatalog && !$catalogScos) {
            // Rutele fixe ale catalogului (există și fără pagină în constructor).
            $adauga('/' . self::codeazaCale($paginaCatalog), self::celMaiNou(array_column($produse, 'lastmod')));
        }
        // Contactul: o rută fixă, fără dată proprie, deci fără <lastmod> (revizuirea WP-39/40: nicio dată inventată). Dacă e o
        // pagină din constructor, a intrat mai sus, cu data ei.
        $adauga('/contact');

        if ($cuCatalog) {
            // Categoriile cu cel puțin un produs activ (principală sau suplimentară).
            foreach (self::categorii($db, $produse, array_keys($noindex['category'] ?? [])) as $categorie) {
                $adauga('/categorie/' . rawurlencode($categorie['slug']), $categorie['lastmod'], 'categorii');
            }

            // Mărcile cu cel puțin un produs activ; etichetele după regulile site-ului (sus).
            $marci = [];
            $etichete = [];
            $produsePeEticheta = [];
            foreach ($produse as $produs) {
                $marca = Slug::simplu((string) ($produs['brand'] ?? ''));
                if ($marca !== '') {
                    $marci[$marca] = self::celMaiNou([$marci[$marca] ?? '', $produs['lastmod']]);
                }
                foreach (self::etichete($produs['tags_json'] ?? null) as $eticheta) {
                    $slugEticheta = Slug::simplu($eticheta);
                    if ($slugEticheta !== '') {
                        $etichete[$slugEticheta] = self::celMaiNou([$etichete[$slugEticheta] ?? '', $produs['lastmod']]);
                        // Un produs se numără o singură dată, chiar dacă are eticheta scrisă de două ori.
                        $produsePeEticheta[$slugEticheta][$produs['id'] . '|' . $produs['slug']] = true;
                    }
                }
            }
            ksort($marci);
            ksort($etichete);
            foreach ($marci as $slug => $lastmod) {
                if ($scoasa('brand', mb_strtolower((string) $slug))) {
                    continue;
                }
                $adauga('/marca/' . rawurlencode((string) $slug), $lastmod, 'marci');
            }
            foreach ($etichete as $slug => $lastmod) {
                if (self::etichetaInSitemap((string) $slug, count($produsePeEticheta[$slug] ?? [])) && !$scoasa('tag', mb_strtolower((string) $slug))) {
                    $adauga('/eticheta/' . rawurlencode((string) $slug), $lastmod, 'etichete');
                }
            }

            foreach ($produse as $produs) {
                if ($scoasa('product', (string) $produs['id'])) {
                    continue;
                }
                $adauga('/produs/' . rawurlencode((string) $produs['slug']), $produs['lastmod'], 'produse',
                    self::imaginiAbsolute($baseUrl, $produs['imagini']));
            }
        }

        if ($articole !== []) {
            $adauga('/blog', self::celMaiNou(array_column($articole, 'lastmod')));
        }
        foreach ($articole as $articol) {
            if ($scoasa('blog_post', (string) $articol['id'])) {
                continue;
            }
            $adauga('/blog/' . rawurlencode((string) $articol['slug']), $articol['lastmod'], 'articole',
                self::imaginiAbsolute($baseUrl, [$articol['imagine']]));
        }

        // WP-39 (B3): categoriile blogului (active, cu cel puțin un articol publicat) și etichetele lui (cu cel puțin
        // `seo.json → blog.eticheta_minim_articole` articole, implicit 1), fără cele scoase din Google.
        if ($articole !== []) {
            foreach (self::termeniBlog($db, 'categorii', $articole) as $termen) {
                if (!$scoasa('blog_category', (string) $termen['id'])) {
                    $adauga('/blog/categorie/' . rawurlencode($termen['slug']), $termen['lastmod'], 'blog-categorii');
                }
            }
            foreach (self::termeniBlog($db, 'etichete', $articole) as $termen) {
                if ($termen['articole'] >= self::minimArticoleEticheta() && !$scoasa('blog_tag', (string) $termen['id'])) {
                    $adauga('/blog/eticheta/' . rawurlencode($termen['slug']), $termen['lastmod'], 'blog-etichete');
                }
            }
        }

        // <lastmod> doar din data adresei (WP-39; revizuirea WP-39/40: o adresă fără dată proprie, o rută fixă sau o pagină
        // fără date, nu primește una inventată). Niciodată `seo_pages.updated_at`: rândurile de dinainte de nucleu/0041 au acolo
        // momentul migrării, nu al unei schimbări a paginii.
        return $urls;
    }

    /** Sitemap-urile pe tipuri, în ordinea din index, cu numele adresei lor (/sitemap-{tip}.xml). */
    public const TIPURI = ['pagini', 'produse', 'categorii', 'marci', 'etichete', 'articole', 'blog-categorii', 'blog-etichete'];

    /** Cel mult atâtea adrese într-un sitemap (limita Google: 50.000 și 50 MB); peste, tipul se împarte în -2, -3 … */
    public const MAX_PE_FISIER = 50000;
    /** Cât stau adresele calculate în storage/cache/sitemap/ (golite odată cu cache-ul de pagini, la orice schimbare). */
    public const TTL_CACHE = 900;

    /**
     * Adresele pe tipuri (doar tipurile cu cel puțin o adresă), în ordinea din `TIPURI`. Ținute TTL_CACHE secunde în
     * storage/cache/sitemap/ (cheia: adresa site-ului și pluginurile), ca roboții care cer indexul și fiecare sitemap să nu
     * refacă toate interogările la fiecare cerere.
     *
     * @return array<string, list<array{loc: string, lastmod: string, tip: string, imagini: list<string>}>>
     */
    public static function grupuri(PDO $db, string $baseUrl): array
    {
        $fisier = self::dosarCache() . '/grupuri-' . substr(sha1($baseUrl . '|' . Plugins::amprenta()), 0, 16) . '.json';
        $citit = is_file($fisier) && (int) @filemtime($fisier) > time() - self::TTL_CACHE ? json_decode((string) @file_get_contents($fisier), true) : null;
        if (is_array($citit)) {
            return $citit;
        }
        $grupuri = array_fill_keys(self::TIPURI, []);
        foreach (self::urls($db, $baseUrl) as $url) {
            $grupuri[$url['tip']][] = $url;
        }
        $grupuri = array_filter($grupuri, static fn (array $lista): bool => $lista !== []);
        $json = json_encode($grupuri, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($json) && (is_dir(dirname($fisier)) || @mkdir(dirname($fisier), 0775, true))) {
            $temporar = $fisier . '.' . getmypid() . '.tmp';
            if (@file_put_contents($temporar, $json) === strlen($json)) {
                @rename($temporar, $fisier);
            }
            @unlink($temporar);
        }
        return $grupuri;
    }

    public static function dosarCache(): string
    {
        return Paths::stocare('cache/sitemap');
    }

    /** Golește adresele ținute (ResponseCache::purgePageCache, la orice schimbare de conținut). */
    public static function golesteCache(): void
    {
        foreach (glob(self::dosarCache() . '/*.json') ?: [] as $fisier) {
            @unlink($fisier);
        }
    }

    /**
     * Sitemap-urile de servit: numele (fără /sitemap- și .xml) → adresele. Un tip cu peste $max adrese se împarte:
     * `produse`, `produse-2`, `produse-3` …
     *
     * @param array<string, list<array<string, mixed>>> $grupuri
     * @return array<string, list<array<string, mixed>>>
     */
    public static function fisiere(array $grupuri, int $max = self::MAX_PE_FISIER): array
    {
        $rez = [];
        foreach ($grupuri as $tip => $lista) {
            foreach (array_chunk($lista, max(1, $max)) as $i => $bucata) {
                $rez[$i === 0 ? $tip : $tip . '-' . ($i + 1)] = $bucata;
            }
        }
        return $rez;
    }

    /**
     * /sitemap.xml (WP-39, B3): indexul sitemap-urilor pe tipuri (împărțite peste MAX_PE_FISIER), fiecare cu data celei mai
     * noi adrese din el (fără, când nicio adresă n-are dată).
     *
     * @param array<string, list<array{loc: string, lastmod: string}>> $grupuri
     */
    public static function index(array $grupuri, string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach (self::fisiere($grupuri) as $tip => $lista) {
            $xml[] = '  <sitemap>';
            $xml[] = '    <loc>' . htmlspecialchars($baseUrl . '/sitemap-' . $tip . '.xml', ENT_QUOTES | ENT_XML1, 'UTF-8') . '</loc>';
            $lastmod = self::celMaiNou(array_column($lista, 'lastmod'));
            if ($lastmod !== '') {
                $xml[] = '    <lastmod>' . htmlspecialchars($lastmod, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</lastmod>';
            }
            $xml[] = '  </sitemap>';
        }
        $xml[] = '</sitemapindex>';
        return implode("\n", $xml) . "\n";
    }

    /** Câte articole publicate trebuie să aibă o etichetă de blog ca să intre în Google (seo.json → blog.eticheta_minim_articole). */
    public static function minimArticoleEticheta(): int
    {
        return max(1, (int) DateSite::seo('blog.eticheta_minim_articole', 1));
    }

    /**
     * Pozele unei adrese, absolute și codate, fără poza goală (product-placeholder), cel mult 10.
     *
     * @param list<string> $imagini
     * @return list<string>
     */
    private static function imaginiAbsolute(string $baseUrl, array $imagini): array
    {
        $rez = [];
        foreach ($imagini as $imagine) {
            $imagine = trim((string) $imagine);
            if ($imagine === '' || str_contains($imagine, 'product-placeholder') || str_starts_with($imagine, 'data:')) {
                continue;
            }
            if (str_starts_with($imagine, '//')) {
                $imagine = 'https:' . $imagine;
            } elseif (preg_match('#^https?://#i', $imagine) !== 1) {
                $imagine = $baseUrl . implode('/', array_map(
                    static fn (string $bucata): string => rawurlencode(rawurldecode($bucata)),
                    explode('/', '/' . ltrim($imagine, '/'))
                ));
            }
            $rez[$imagine] = true;
        }
        return array_slice(array_keys($rez), 0, 10);
    }

    /**
     * Categoriile (active) sau etichetele blogului care au articole publicate (din lista sitemap-ului, deci fără cele
     * doar pentru membri), cu numărul lor și data celui mai nou.
     *
     * @param list<array{id: int, slug: string, lastmod: string}> $articole
     * @return list<array{id: int, slug: string, articole: int, lastmod: string}>
     */
    private static function termeniBlog(PDO $db, string $fel, array $articole): array
    {
        $dupaArticol = [];
        foreach ($articole as $articol) {
            $dupaArticol[(int) $articol['id']] = $articol['lastmod'];
        }
        $legaturi = $fel === 'categorii'
            ? self::interogare($db, 'SELECT c.id, c.slug, x.post_id FROM blog_categories c
                JOIN (SELECT post_id, category_id FROM blog_post_categories UNION SELECT id, category_id FROM blog_posts WHERE category_id IS NOT NULL) x
                  ON x.category_id = c.id
                WHERE c.is_active = 1 AND TRIM(COALESCE(c.slug, "")) <> "" ORDER BY c.sort_order, c.name, c.id')
            : self::interogare($db, 'SELECT t.id, t.slug, l.post_id FROM blog_tags t JOIN blog_post_tags l ON l.tag_id = t.id
                WHERE TRIM(COALESCE(t.slug, "")) <> "" ORDER BY t.name, t.id');
        $termeni = [];
        foreach ($legaturi as $rand) {
            $idArticol = (int) ($rand['post_id'] ?? 0);
            if (!isset($dupaArticol[$idArticol])) {
                continue;
            }
            $id = (int) $rand['id'];
            $termeni[$id] ??= ['id' => $id, 'slug' => trim((string) $rand['slug']), 'articole' => [], 'lastmod' => ''];
            $termeni[$id]['articole'][$idArticol] = true;
            $termeni[$id]['lastmod'] = self::celMaiNou([$termeni[$id]['lastmod'], $dupaArticol[$idArticol]]);
        }
        return array_values(array_map(static fn (array $t): array => ['id' => $t['id'], 'slug' => $t['slug'],
            'articole' => count($t['articole']), 'lastmod' => $t['lastmod']], $termeni));
    }

    /**
     * Un `<urlset>`; cu pozele adreselor (`<image:image>`, WP-39) doar când $cuPoze (sitemap-urile pe tipuri).
     *
     * @param list<array{loc: string, lastmod: string, imagini?: list<string>}> $urls
     */
    public static function xml(array $urls, bool $cuPoze = false): string
    {
        $arePoze = $cuPoze && array_filter($urls, static fn (array $u): bool => ($u['imagini'] ?? []) !== []) !== [];
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ($arePoze ? ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"' : '') . '>'];
        foreach ($urls as $intrare) {
            $loc = trim((string) ($intrare['loc'] ?? ''));
            if ($loc === '') {
                continue;
            }
            $xml[] = '  <url>';
            $xml[] = '    <loc>' . htmlspecialchars($loc, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</loc>';
            $lastmod = trim((string) ($intrare['lastmod'] ?? ''));
            if ($lastmod !== '') {
                $xml[] = '    <lastmod>' . htmlspecialchars($lastmod, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</lastmod>';
            }
            foreach ($arePoze ? (array) ($intrare['imagini'] ?? []) : [] as $imagine) {
                $xml[] = '    <image:image><image:loc>' . htmlspecialchars((string) $imagine, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</image:loc></image:image>';
            }
            $xml[] = '  </url>';
        }
        $xml[] = '</urlset>';

        return implode("\n", $xml) . "\n";
    }

    // ───────────────────────────────────────────────────────────

    /** @return list<array{id: int, slug: string, brand: string, category: string, tags_json: mixed, lastmod: string}> */
    private static function produseActive(PDO $db): array
    {
        $randuri = self::interogare($db,
            'SELECT id, slug, brand, category, tags_json, image_url, gallery_images_json, updated_at, created_at FROM products
             WHERE deleted_at IS NULL AND is_active = 1 AND TRIM(COALESCE(slug, "")) <> "" ORDER BY id DESC');
        if ($randuri === []) {
            // Schema veche, fără mărci și etichete.
            $randuri = self::interogare($db,
                'SELECT id, slug, category, updated_at, created_at FROM products
                 WHERE deleted_at IS NULL AND is_active = 1 AND TRIM(COALESCE(slug, "")) <> "" ORDER BY id DESC');
        }
        $produse = [];
        foreach ($randuri as $rand) {
            $produse[] = [
                'id' => (int) ($rand['id'] ?? 0),
                'slug' => trim((string) ($rand['slug'] ?? '')),
                'brand' => trim((string) ($rand['brand'] ?? '')),
                'category' => trim((string) ($rand['category'] ?? '')),
                'tags_json' => $rand['tags_json'] ?? null,
                'lastmod' => self::lastmod($rand),
                'imagini' => self::pozeProdus($rand),
            ];
        }
        return $produse;
    }

    /**
     * Poza principală și galeria unui produs (căile, cum sunt în bază).
     *
     * @return list<string>
     */
    private static function pozeProdus(array $rand): array
    {
        $poze = [trim((string) ($rand['image_url'] ?? ''))];
        $galerie = json_decode((string) ($rand['gallery_images_json'] ?? ''), true);
        foreach (is_array($galerie) ? $galerie : [] as $poza) {
            $poze[] = is_array($poza) ? trim((string) ($poza['url'] ?? $poza['src'] ?? '')) : trim((string) $poza);
        }
        return array_values(array_filter($poze, static fn (string $p): bool => $p !== ''));
    }

    /** @return list<array{id: int, slug: string, lastmod: string, imagine: string}> */
    private static function articolePublicate(PDO $db): array
    {
        $articole = [];
        foreach (self::interogare($db,
            'SELECT id, slug, featured_image_url, updated_at, created_at, published_at FROM blog_posts
             WHERE deleted_at IS NULL AND is_published = 1 AND published_at <= NOW() AND TRIM(COALESCE(slug, "")) <> ""'
             . AccesMembri::conditieSql($db, 'blog_posts', 'blog_posts', true) . '
             ORDER BY published_at DESC, id DESC') as $rand) {
            $articole[] = ['id' => (int) ($rand['id'] ?? 0), 'slug' => trim((string) $rand['slug']), 'lastmod' => self::lastmod($rand),
                'imagine' => trim((string) ($rand['featured_image_url'] ?? ''))];
        }
        return $articole;
    }

    /**
     * Categoriile cu produse active și slug-ul pe care /categorie/{slug} îl
     * recunoaște (cel salvat; altfel cel generat din nume, ca în magazin).
     *
     * @param list<array{id: int, category: string, lastmod: string}> $produse
     * @param list<string|int> $scoase id-urile categoriilor scoase din Google (robots „noindex”)
     * @return list<array{slug: string, lastmod: string}>
     */
    private static function categorii(PDO $db, array $produse, array $scoase = []): array
    {
        $dupaNume = [];
        foreach ($produse as $produs) {
            $nume = mb_strtolower(trim($produs['category']));
            if ($nume !== '') {
                $dupaNume[$nume] = self::celMaiNou([$dupaNume[$nume] ?? '', $produs['lastmod']]);
            }
        }
        $lastmodProdus = [];
        foreach ($produse as $produs) {
            $lastmodProdus[$produs['id']] = $produs['lastmod'];
        }
        foreach (self::interogare($db,
            'SELECT l.product_id, pc.name FROM product_category_links l INNER JOIN product_categories pc ON pc.id = l.category_id') as $legatura) {
            $id = (int) ($legatura['product_id'] ?? 0);
            $nume = mb_strtolower(trim((string) ($legatura['name'] ?? '')));
            if ($nume !== '' && isset($lastmodProdus[$id])) {
                $dupaNume[$nume] = self::celMaiNou([$dupaNume[$nume] ?? '', $lastmodProdus[$id]]);
            }
        }

        $slugSalvat = [];
        $numeScoase = [];
        $scoase = array_map('strval', $scoase);
        foreach (self::interogare($db, 'SELECT id, name, slug FROM product_categories ORDER BY id ASC') as $rand) {
            $nume = mb_strtolower(trim((string) ($rand['name'] ?? '')));
            if ($nume !== '' && in_array((string) ($rand['id'] ?? ''), $scoase, true)) {
                $numeScoase[$nume] = true;
            }
            $slug = trim((string) ($rand['slug'] ?? ''));
            if ($nume !== '' && $slug !== '' && !isset($slugSalvat[$nume])) {
                // Exact cum e salvat: e forma la care /categorie/{slug} nu mai redirecționează.
                $slugSalvat[$nume] = $slug;
            }
        }

        $rezultat = [];
        $slugVazut = [];
        ksort($dupaNume);
        foreach ($dupaNume as $nume => $lastmod) {
            $slug = $slugSalvat[$nume] ?? Slug::categorie((string) $nume);
            if ($slug === '' || isset($numeScoase[$nume]) || isset($slugVazut[mb_strtolower($slug)])) {
                continue;
            }
            $slugVazut[mb_strtolower($slug)] = true;
            $rezultat[] = ['slug' => $slug, 'lastmod' => $lastmod];
        }
        return $rezultat;
    }

    /** @return list<string> */
    private static function etichete(mixed $brut): array
    {
        if (is_array($brut)) {
            $lista = $brut;
        } else {
            $lista = json_decode((string) $brut, true);
            if (!is_array($lista)) {
                return [];
            }
        }
        return array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $lista), static fn (string $v): bool => $v !== ''));
    }

    /** @return list<array<string, mixed>> */
    private static function interogare(PDO $db, string $sql): array
    {
        try {
            $stmt = $db->query($sql);
            $randuri = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            return array_values(array_filter($randuri, 'is_array'));
        } catch (Throwable) {
            return [];
        }
    }

    private static function lastmod(array $rand): string
    {
        $brut = trim((string) ($rand['updated_at'] ?? ''));
        if ($brut === '' || str_starts_with($brut, '0000')) {
            $brut = trim((string) ($rand['created_at'] ?? ''));
        }
        if ($brut === '' || str_starts_with($brut, '0000')) {
            return '';
        }
        $ts = strtotime($brut);
        return $ts === false ? '' : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /** @param list<string> $valori */
    private static function celMaiNou(array $valori): string
    {
        $valori = array_filter($valori, static fn ($v): bool => is_string($v) && $v !== '');
        if ($valori === []) {
            return '';
        }
        rsort($valori);
        return (string) $valori[0];
    }

    private static function codeazaCale(string $slug): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $slug)));
    }
}
