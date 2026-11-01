<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Modelul SEO al paginilor (WP-37, SEO partea B, B1; App\Support\Seo):
 *
 *  - `seo_pages.robots`: ce cere pagina robotului Google („noindex,follow”, „noindex,nofollow”; NULL = fără etichetă,
 *    deci în Google, ca până acum);
 *  - `seo_pages.og_type`: tipul paginii la distribuire (og:type; NULL = „website”, ca până acum);
 *  - `seo_pages.intro_html`: textul de deasupra unei liste (marcă, etichetă, lista tuturor produselor, categoria și
 *    eticheta blogului; NULL = lista ca până acum);
 *  - `seo_pages.updated_at`: momentul ultimei schimbări a rândului (data din harta site-ului, WP-39).
 *
 * Tipurile noi de rând (`page_type`: `tag`, `listing_default`, `blog_category`, `blog_tag`) nu cer schemă: coloana e
 * VARCHAR(50). Pe niciun site nu se schimbă nimic din ce se vede: coloanele noi pornesc goale (NULL), iar fără ele
 * pagina iese ca înainte. Codul de dinainte merge pe schema nouă (coloane la coadă, NULL sau cu valoare implicită).
 */
return [
    'descriere' => 'Paginile SEO: robots, tipul la distribuire, textul de deasupra listelor, data ultimei schimbări',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('seo_pages', 'robots', 'VARCHAR(40) NULL');
        $s->coloana('seo_pages', 'og_type', 'VARCHAR(20) NULL');
        $s->coloana('seo_pages', 'intro_html', 'MEDIUMTEXT NULL');
        $s->coloana('seo_pages', 'updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    },
];
