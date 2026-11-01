<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Modelul SEO al fiecărei pagini (WP-37, B1): ce scrie omul pentru Google pe o pagină (rândul ei din `seo_pages`) și
 * ce ajunge în `<head>` (titlul, descrierea, canonicalul, robots și tipul la distribuire).
 *
 * Un rând din `seo_pages` e al unei pagini de un anumit tip (`TIPURI`): `page_type` + `page_ref` (id-ul sau slug-ul
 * ei). Câmpurile: titlul și descrierea pentru Google, canonicalul (doar o adresă http/https întreagă), poza de
 * distribuire, robots (`ROBOTS`: gol = indexată, ca până acum), tipul la distribuire (`OG_TIPURI`: gol = „website”, ca
 * până acum), textul de deasupra listei (`intro_html`, la liste: marcă, etichetă, magazin, categoria și eticheta
 * blogului; categoria de produse îl are în `product_categories.intro_html`) și momentul ultimei schimbări. Un câmp gol
 * nu schimbă nimic: fără rând, pagina iese exact ca înainte (G-cmp, comparația cu codul site-ului de referință).
 *
 * `rezolva()` face valorile finale din `<head>` (aceeași regulă pe care o avea `views/layout.php`, mutată aici, ca
 * testele să o poată verifica fără server); `verificari()` dă avertismentele panoului SEO din admin (lipsă, prea lung,
 * același titlu sau aceeași descriere pe altă pagină).
 *
 * Coloanele noi (robots, og_type, intro_html, updated_at) vin cu migrarea nucleu/0041; cât ea n-a rulat, citirea merge
 * cu coloanele vechi, iar scrierea câmpurilor noi se sare (nimic nu pică).
 */
final class Seo
{
    /** Tipurile de pagină ale unui rând `seo_pages` (page_type) și ce e page_ref la fiecare. */
    public const TIPURI = [
        'custom_page' => 'pagina din constructor (id)',
        'product' => 'produsul (id)',
        'blog_post' => 'articolul (id)',
        'category' => 'categoria de produse (id)',
        'brand' => 'marca (slug, /marca/{slug})',
        'tag' => 'eticheta de produse (slug, /eticheta/{slug})',
        'listing_default' => 'lista tuturor produselor (magazin, /magazin sau pagina catalogului)',
        'blog_category' => 'categoria blogului (id, /blog/categorie/{slug})',
        'blog_tag' => 'eticheta blogului (id, /blog/eticheta/{slug})',
    ];

    /** page_ref al singurului rând `listing_default`. */
    public const REF_LISTA = 'magazin';

    /** Valorile robots pe care le poate alege omul (gol = fără etichetă, deci indexată și urmată, ca până acum). */
    public const ROBOTS = [
        '' => 'Implicit: în Google',
        'noindex,follow' => 'Scoasă din Google, linkurile urmate (noindex, follow)',
        'noindex,nofollow' => 'Scoasă din Google, linkurile neurmate (noindex, nofollow)',
    ];

    /** Tipul paginii la distribuire (og:type; gol = „website”, ca până acum). */
    public const OG_TIPURI = [
        '' => 'Implicit (website)',
        'website' => 'website',
        'article' => 'article (articol)',
        'product' => 'product (produs)',
    ];

    /** Lungimile după care Google taie de obicei (orientativ: Google taie după lățime). */
    public const LIMITA_TITLU = 60;
    public const LIMITA_DESCRIERE = 155;

    /** @var array<string, bool> coloanele noi există? (o dată pe conexiune) */
    private static array $coloaneNoi = [];

    public static function tipValid(string $tip): string
    {
        $tip = trim($tip);
        return isset(self::TIPURI[$tip]) ? $tip : '';
    }

    /** @return array{title: string, description: string, canonical_url: string, image_url: string, robots: string, og_type: string, intro_html: string} */
    public static function gol(): array
    {
        return ['title' => '', 'description' => '', 'canonical_url' => '', 'image_url' => '', 'robots' => '', 'og_type' => '', 'intro_html' => ''];
    }

    /**
     * Câmpurile curățate: canonical doar http(s), poza ca cale „/…” sau adresă http(s), robots și og_type doar din
     * listele de mai sus, textul listei curățat de scripturi și de atributele de eveniment.
     *
     * @param array<string, mixed> $seo
     * @return array{title: string, description: string, canonical_url: string, image_url: string, robots: string, og_type: string, intro_html: string}
     */
    public static function normalizeaza(array $seo): array
    {
        $rez = self::gol();
        // Titlul, descrierea, canonicalul și poza: exact regula de până acum (AdminController::normalizeSeoSettings).
        $rez['title'] = trim((string) ($seo['title'] ?? ''));
        $rez['description'] = trim((string) ($seo['description'] ?? ''));
        $canonical = trim((string) ($seo['canonical_url'] ?? ''));
        $rez['canonical_url'] = preg_match('/^https?:\/\//i', $canonical) === 1 ? $canonical : '';
        $imagine = trim((string) ($seo['image_url'] ?? ''));
        if ($imagine !== '' && preg_match('/^https?:\/\//i', $imagine) !== 1 && !str_starts_with($imagine, '/')) {
            $imagine = '/' . ltrim($imagine, '/');
        }
        $rez['image_url'] = $imagine;
        $robots = strtolower(str_replace(' ', '', trim((string) ($seo['robots'] ?? ''))));
        $rez['robots'] = isset(self::ROBOTS[$robots]) ? $robots : '';
        $og = strtolower(trim((string) ($seo['og_type'] ?? '')));
        $rez['og_type'] = isset(self::OG_TIPURI[$og]) ? $og : '';
        $rez['intro_html'] = self::curataIntro((string) ($seo['intro_html'] ?? ''));
        return $rez;
    }

    /**
     * Textul de deasupra unei liste (marcă, etichetă, categorie): HTML-ul scris de admin, fără `<script>`, `<style>`,
     * `<iframe>`, atribute `on…` și linkuri `javascript:` (același fel de curățare ca descrierea produsului).
     */
    public static function curataIntro(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        return trim(RichText::sanitize($html));
    }

    /**
     * Rândul unei pagini, cu toate câmpurile (gol unde nu e nimic). Fără rând (sau fără bază): `gol()`.
     *
     * @return array{title: string, description: string, canonical_url: string, image_url: string, robots: string, og_type: string, intro_html: string}
     */
    public static function citeste(?PDO $db, string $tip, string $ref): array
    {
        $harta = self::citesteHarta($db, $tip, [$ref]);
        return $harta[trim($ref)] ?? self::gol();
    }

    /**
     * Există un rând pentru pagină? (Controllerele site-ului se poartă diferit fără rând: SEO-ul paginii „Magazin” etc.)
     */
    public static function areRand(?PDO $db, string $tip, string $ref): bool
    {
        return array_key_exists(trim($ref), self::citesteHarta($db, $tip, [$ref]));
    }

    /**
     * Rândurile mai multor pagini de același tip, după page_ref (doar cele care au rând).
     *
     * @param list<string|int> $refs
     * @return array<string, array{title: string, description: string, canonical_url: string, image_url: string, robots: string, og_type: string, intro_html: string}>
     */
    public static function citesteHarta(?PDO $db, string $tip, array $refs): array
    {
        $tip = self::tipValid($tip);
        if (!$db instanceof PDO || $tip === '') {
            return [];
        }
        $curate = [];
        foreach ($refs as $ref) {
            $ref = trim((string) $ref);
            if ($ref !== '' && !in_array($ref, $curate, true)) {
                $curate[] = $ref;
            }
        }
        if ($curate === []) {
            return [];
        }
        $coloane = 'page_ref, title, description, canonical_url, image_url' . (self::coloaneNoi($db) ? ', robots, og_type, intro_html' : '');
        $rez = [];
        try {
            foreach (array_chunk($curate, 500) as $bucata) {
                $stmt = $db->prepare('SELECT ' . $coloane . ' FROM seo_pages WHERE page_type = ? AND page_ref IN ('
                    . implode(',', array_fill(0, count($bucata), '?')) . ')');
                $stmt->execute(array_merge([$tip], $bucata));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rand) {
                    $rez[trim((string) $rand['page_ref'])] = self::dinRand($rand);
                }
            }
        } catch (Throwable) {
            return [];
        }
        return $rez;
    }

    /**
     * Scrie câmpurile date ale unei pagini. Doar cheile prezente în $campuri se schimbă (un formular vechi, fără robots,
     * nu șterge robots-ul pus din alt loc); rândul dispare când toate câmpurile lui sunt goale.
     *
     * @param array<string, mixed> $campuri
     */
    public static function salveaza(PDO $db, string $tip, string $ref, array $campuri): void
    {
        $tip = self::tipValid($tip);
        $ref = trim($ref);
        if ($tip === '' || $ref === '') {
            return;
        }
        $noi = self::coloaneNoi($db);
        $existent = self::citeste($db, $tip, $ref);
        $date = self::normalizeaza(array_merge($existent, array_intersect_key($campuri, self::gol())));
        if (!$noi) {
            // Fără migrarea nucleu/0041: câmpurile noi nu au unde sta.
            $date['robots'] = $date['og_type'] = $date['intro_html'] = '';
        }
        if (implode('', $date) === '') {
            try {
                $db->prepare('DELETE FROM seo_pages WHERE page_type = ? AND page_ref = ?')->execute([$tip, $ref]);
            } catch (Throwable) {
            }
            return;
        }
        $coloane = ['title', 'description', 'canonical_url', 'image_url'];
        if ($noi) {
            $coloane = array_merge($coloane, ['robots', 'og_type', 'intro_html']);
        }
        $valori = [$tip, $ref];
        foreach ($coloane as $coloana) {
            $valori[] = $date[$coloana] !== '' ? $date[$coloana] : null;
        }
        try {
            $db->prepare('INSERT INTO seo_pages (page_type, page_ref, ' . implode(', ', $coloane) . ') VALUES ('
                . implode(', ', array_fill(0, count($coloane) + 2, '?')) . ') ON DUPLICATE KEY UPDATE '
                . implode(', ', array_map(static fn (string $c): string => $c . ' = VALUES(' . $c . ')', $coloane)))
                ->execute($valori);
        } catch (Throwable) {
            // Ca până acum: o valoare care nu încape (titlu peste 255 de caractere) nu strică salvarea paginii.
        }
    }

    /** Șterge rândul unei pagini (pagina însăși a fost ștearsă). */
    public static function sterge(PDO $db, string $tip, string $ref): void
    {
        $tip = self::tipValid($tip);
        if ($tip === '' || trim($ref) === '') {
            return;
        }
        try {
            $db->prepare('DELETE FROM seo_pages WHERE page_type = ? AND page_ref = ?')->execute([$tip, trim($ref)]);
        } catch (Throwable) {
        }
    }

    /** Mută rândul unei pagini la alt page_ref (marca sau eticheta redenumită: slug-ul e cheia). */
    public static function mutaRef(PDO $db, string $tip, string $dinRef, string $inRef): void
    {
        $tip = self::tipValid($tip);
        if ($tip === '' || trim($dinRef) === '' || trim($inRef) === '' || $dinRef === $inRef) {
            return;
        }
        try {
            $db->prepare('UPDATE IGNORE seo_pages SET page_ref = ? WHERE page_type = ? AND page_ref = ?')->execute([$inRef, $tip, $dinRef]);
        } catch (Throwable) {
        }
    }

    /**
     * Rândul ca valorile pe care le dau controllerele site-ului vederii (`metaTitle`, `metaDescription`,
     * `metaCanonicalUrl`, `metaImageUrl`, plus `metaRobots` și `metaOgType` doar când sunt puse, ca o pagină fără ele
     * să iasă exact ca înainte). Fără rând: [].
     *
     * @param array<string, string>|null $rand
     * @return array<string, string>
     */
    public static function meta(?array $rand): array
    {
        if ($rand === null) {
            return [];
        }
        $meta = [
            'metaTitle' => (string) ($rand['title'] ?? ''),
            'metaDescription' => (string) ($rand['description'] ?? ''),
            'metaCanonicalUrl' => (string) ($rand['canonical_url'] ?? ''),
            'metaImageUrl' => (string) ($rand['image_url'] ?? ''),
        ];
        if ((string) ($rand['robots'] ?? '') !== '') {
            $meta['metaRobots'] = (string) $rand['robots'];
        }
        if ((string) ($rand['og_type'] ?? '') !== '') {
            $meta['metaOgType'] = (string) $rand['og_type'];
        }
        return $meta;
    }

    /**
     * Tabloul `seo` pe care îl poate da un controller vederii, în locul cheilor `meta*` (View::render îl desface):
     * `titlu`, `descriere`, `canonical`, `imagine`, `imagine_alt`, `robots`, `og_type`, `fara_canonical`.
     *
     * @param array<string, mixed> $seo
     * @return array<string, mixed>
     */
    public static function dateVedere(array $seo): array
    {
        $chei = [
            'titlu' => 'metaTitle', 'descriere' => 'metaDescription', 'canonical' => 'metaCanonicalUrl', 'imagine' => 'metaImageUrl',
            'imagine_alt' => 'metaImageAlt', 'robots' => 'metaRobots', 'og_type' => 'metaOgType', 'fara_canonical' => 'faraCanonical',
        ];
        $rez = [];
        foreach ($chei as $cheie => $variabila) {
            if (array_key_exists($cheie, $seo)) {
                $rez[$variabila] = $seo[$cheie];
            }
        }
        return $rez;
    }

    /**
     * Valorile finale din `<head>` ale paginii publice (regula de până acum din views/layout.php, aceeași la octet):
     *
     *  - titlul: pe prima pagină, cel din Setări magazin → SEO Google are întâietate; apoi cel scris pentru pagină, altfel
     *    „{titlul paginii} | {magazin}” (SeoMeta::titluPagina; cu SEO_TITLURI=0, regula de dinaintea livrării 2);
     *  - descrierea: cea scrisă, apoi (prima pagină) cea din setări, apoi descrierea implicită din setări;
     *  - canonicalul: cel scris (o adresă întreagă), altfel adresa paginii; niciunul pe 404/410 (în afara căilor de
     *    coș, comandă, cont, admin) și unde controllerul cere (căutarea internă);
     *  - robots: cel dat de controller (căutarea internă, /blog gol) sau scris pentru pagină, doar litere, „,”, „-”;
     *  - og:type: cel scris pentru pagină, altfel „website”.
     *
     * @param array<string, mixed> $ctx metaTitle, metaDescription, metaCanonicalUrl, metaRobots, metaOgType, faraCanonical,
     *                                  title (titlul paginii), cale (calea cererii, fără „/” final), cerere (REQUEST_URI),
     *                                  cod (codul HTTP), appUrl, setari
     * @return array{titlu: string, descriere: string, canonical: string, robots: string, og_type: string, nume_magazin: string, titlu_pagina: string}
     */
    public static function rezolva(array $ctx): array
    {
        $setari = is_array($ctx['setari'] ?? null) ? $ctx['setari'] : [];
        $cale = (string) ($ctx['cale'] ?? '/');
        $numeMagazin = SeoMeta::numeMagazin($setari);
        $titluPagina = trim((string) ($ctx['title'] ?? ''));
        $titluAcasa = trim((string) ($setari['store_seo_home_title'] ?? ''));
        $descriereAcasa = trim((string) ($setari['store_seo_home_description'] ?? ''));
        $titlu = trim((string) ($ctx['metaTitle'] ?? ''));
        $descriere = trim((string) ($ctx['metaDescription'] ?? ''));
        if (SeoMeta::activ()) {
            // Prima pagină: titlul și descrierea din Setări magazin → SEO Google au
            // întâietate (acolo le caută proprietarul); apoi cele din pagina „Acasă”.
            if ($cale === '/' && $titluAcasa !== '') {
                $titlu = $titluAcasa;
            }
            if ($cale === '/' && $descriereAcasa !== '') {
                $descriere = $descriereAcasa;
            }
            // Aceeași regulă peste tot: titlul scris, altfel „{titlul paginii} | {magazin}”,
            // fără „|”, „–” sau „-” rămase la final.
            $titlu = SeoMeta::titluPagina($titlu, $titluPagina, $numeMagazin);
        } elseif ($titlu === '') {
            if ($cale === '/' && $titluAcasa !== '') {
                $titlu = $titluAcasa;
            } elseif ($titluPagina === '' || strcasecmp($titluPagina, $numeMagazin) === 0) {
                $titlu = $numeMagazin;
            } else {
                $titlu = $titluPagina . ' | ' . $numeMagazin;
            }
        }
        if ($descriere === '') {
            if ($cale === '/') {
                $descriere = $descriereAcasa;
            }
            if ($descriere === '') {
                $descriere = trim((string) ($setari['store_seo_default_description'] ?? ''));
            }
        }
        $appUrl = rtrim((string) ($ctx['appUrl'] ?? ''), '/');
        $canonical = trim((string) ($ctx['metaCanonicalUrl'] ?? ''));
        if ($canonical === '') {
            $canonical = $appUrl . ($cale === '/' ? '/' : $cale);
        }
        // Fără canonical (și fără og:url) pe pagina „nu există” (404/410) și unde
        // controllerul o cere (căutarea internă cu noindex). Vezi SeoIndexare.
        // Paginile de coș, comandă, plată și cont rămân exact ca înainte, și pe 404.
        if (!empty($ctx['faraCanonical'])
            || SeoIndexare::faraCanonicalPentruCod((int) ($ctx['cod'] ?? 200), (string) ($ctx['cerere'] ?? '/'))) {
            $canonical = '';
        }
        $robots = trim((string) ($ctx['metaRobots'] ?? ''));
        if (preg_match('/^[a-z ,-]+$/i', $robots) !== 1) {
            $robots = '';
        }
        $og = strtolower(trim((string) ($ctx['metaOgType'] ?? '')));
        if (!isset(self::OG_TIPURI[$og]) || $og === '') {
            $og = 'website';
        }
        return ['titlu' => $titlu, 'descriere' => $descriere, 'canonical' => $canonical, 'robots' => $robots, 'og_type' => $og,
            'nume_magazin' => $numeMagazin, 'titlu_pagina' => $titluPagina];
    }

    /**
     * Avertismentele panoului SEO din admin pentru titlul și descrierea scrise ale unei pagini:
     * lipsă (Google primește titlul sau descrierea făcute automat), prea lung (peste 60 / 155 de caractere) și același
     * titlu sau aceeași descriere scrise pe altă pagină (Google le vede ca duplicate). Doar citire.
     *
     * @return list<array{cod: string, camp: string, text: string, alte?: list<array{tip: string, ref: string, nume: string, link: string}>}>
     */
    public static function verificari(?PDO $db, string $tip, string $ref, string $titlu, string $descriere): array
    {
        $titlu = trim((string) preg_replace('/\s+/u', ' ', $titlu));
        $descriere = trim((string) preg_replace('/\s+/u', ' ', $descriere));
        $rez = [];
        if ($titlu === '') {
            $rez[] = ['cod' => 'lipsa', 'camp' => 'titlu', 'text' => 'Fără titlu pentru Google: se folosește titlul paginii urmat de numele magazinului.'];
        } elseif (mb_strlen($titlu) > self::LIMITA_TITLU) {
            $rez[] = ['cod' => 'lung', 'camp' => 'titlu', 'text' => 'Titlul are ' . mb_strlen($titlu) . ' de caractere; Google arată de obicei cel mult '
                . self::LIMITA_TITLU . '.'];
        }
        if ($descriere === '') {
            $rez[] = ['cod' => 'lipsa', 'camp' => 'descriere', 'text' => 'Fără descriere pentru Google: Google alege singur un text din pagină (sau se folosește descrierea făcută automat).'];
        } elseif (mb_strlen($descriere) > self::LIMITA_DESCRIERE) {
            $rez[] = ['cod' => 'lung', 'camp' => 'descriere', 'text' => 'Descrierea are ' . mb_strlen($descriere) . ' de caractere; Google arată de obicei cel mult '
                . self::LIMITA_DESCRIERE . '.'];
        }
        foreach (['titlu' => ['title', $titlu], 'descriere' => ['description', $descriere]] as $camp => [$coloana, $valoare]) {
            if ($valoare === '') {
                continue;
            }
            $alte = self::duplicate($db, $tip, $ref, $coloana, $valoare);
            if ($alte !== []) {
                $rez[] = ['cod' => 'duplicat', 'camp' => $camp, 'text' => ($camp === 'titlu' ? 'Același titlu' : 'Aceeași descriere')
                    . ' și pe ' . (count($alte) === 1 ? 'altă pagină' : count($alte) . ' alte pagini') . ': Google le vede ca duplicate.', 'alte' => $alte];
            }
        }
        return $rez;
    }

    /**
     * Celelalte pagini (alt tip sau alt ref) cu exact același titlu / aceeași descriere scrise (fără diferență între
     * litere mari și mici și spații în plus), cel mult 5, cu numele și linkul din admin.
     *
     * @return list<array{tip: string, ref: string, nume: string, link: string}>
     */
    public static function duplicate(?PDO $db, string $tip, string $ref, string $coloana, string $valoare): array
    {
        if (!$db instanceof PDO || !in_array($coloana, ['title', 'description'], true) || trim($valoare) === '') {
            return [];
        }
        try {
            $stmt = $db->prepare('SELECT page_type, page_ref FROM seo_pages
                WHERE LOWER(TRIM(' . $coloana . ')) = LOWER(?) AND NOT (page_type = ? AND page_ref = ?)
                ORDER BY page_type, page_ref LIMIT 5');
            $stmt->execute([trim((string) preg_replace('/\s+/u', ' ', $valoare)), $tip, trim($ref)]);
            $randuri = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
        $rez = [];
        foreach ($randuri as $rand) {
            $t = (string) $rand['page_type'];
            $r = (string) $rand['page_ref'];
            $rez[] = ['tip' => $t, 'ref' => $r, 'nume' => self::numePagina($db, $t, $r), 'link' => self::linkAdmin($t, $r)];
        }
        return $rez;
    }

    /** Numele unei pagini din `seo_pages`, pentru oameni („produsul Ceai de mentă”). */
    public static function numePagina(?PDO $db, string $tip, string $ref): string
    {
        $tabel = ['custom_page' => ['pages', 'title', 'pagina'], 'product' => ['products', 'name', 'produsul'],
            'blog_post' => ['blog_posts', 'title', 'articolul'], 'category' => ['product_categories', 'name', 'categoria'],
            'blog_category' => ['blog_categories', 'name', 'categoria blogului'], 'blog_tag' => ['blog_tags', 'name', 'eticheta blogului']][$tip] ?? null;
        if ($tabel !== null && $db instanceof PDO && ctype_digit($ref)) {
            try {
                $stmt = $db->prepare('SELECT ' . $tabel[1] . ' FROM ' . $tabel[0] . ' WHERE id = ? LIMIT 1');
                $stmt->execute([(int) $ref]);
                $nume = trim((string) ($stmt->fetchColumn() ?: ''));
                if ($nume !== '') {
                    return $tabel[2] . ' „' . $nume . '”';
                }
            } catch (Throwable) {
            }
            return $tabel[2] . ' #' . $ref;
        }
        return match ($tip) {
            'brand' => 'marca /marca/' . $ref,
            'tag' => 'eticheta /eticheta/' . $ref,
            'listing_default' => 'lista tuturor produselor',
            default => $tip . ' ' . $ref,
        };
    }

    /** Unde se editează pagina în admin (pentru linkurile din avertismente). */
    public static function linkAdmin(string $tip, string $ref): string
    {
        return match ($tip) {
            'custom_page' => '/admin/pages/' . rawurlencode($ref) . '/edit',
            'product' => '/admin/products#editeaza-' . rawurlencode($ref),
            'blog_post' => '/admin/blog/posts/editor?id=' . rawurlencode($ref),
            'category', 'listing_default' => '/admin/categories',
            'brand' => '/admin/categories#marci',
            'tag' => '/admin/categories#etichete',
            'blog_category', 'blog_tag' => '/admin/blog/categories',
            default => '/admin',
        };
    }

    /**
     * Adresa unde se poate cere verificarea panoului, după tipul paginii: fiecare rol o cere sub adresele pe care le
     * are deja (rolul magazinului la produse și categorii, rolul blogului la articole), fără drepturi noi.
     */
    public static function adresaVerificare(string $tip): string
    {
        return match ($tip) {
            'product', 'category', 'brand', 'tag', 'listing_default' => '/admin/products/seo-verifica',
            'blog_post' => '/admin/blog/posts/seo-verifica',
            default => '/admin/seo/verifica',
        };
    }

    /** Numele magazinului din titluri, pentru previzualizarea panoului SEO (conexiunea cererii, dacă e deschisă). */
    public static function numeMagazinCurent(): string
    {
        $db = Database::connection([]);
        return SeoMeta::numeMagazin($db instanceof PDO ? Settings::all($db) : []);
    }

    /** @param array<string, mixed> $rand */
    private static function dinRand(array $rand): array
    {
        $rez = self::gol();
        foreach (array_keys($rez) as $cheie) {
            $rez[$cheie] = trim((string) ($rand[$cheie] ?? ''));
        }
        return $rez;
    }

    /** Coloanele migrării nucleu/0041 există (robots, og_type, intro_html)? O dată pe conexiune. */
    public static function coloaneNoi(PDO $db): bool
    {
        $cheie = (string) spl_object_id($db);
        if (!isset(self::$coloaneNoi[$cheie])) {
            try {
                $db->query('SELECT robots, og_type, intro_html FROM seo_pages LIMIT 0');
                self::$coloaneNoi[$cheie] = true;
            } catch (Throwable) {
                self::$coloaneNoi[$cheie] = false;
            }
        }
        return self::$coloaneNoi[$cheie];
    }

    /** Doar pentru teste: uită ce s-a aflat despre coloane. */
    public static function reseteaza(): void
    {
        self::$coloaneNoi = [];
    }
}
