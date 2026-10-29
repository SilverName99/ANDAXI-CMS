<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Regulile catalogului care țin de setări (WP-19: C8, C14b + GR-08, C14d, GR-09, GR-16), într-un singur loc, ca
 * listele, paginile și harta site-ului să le aplice la fel. Implicitul nucleului e comportamentul nou; un site
 * existent își păstrează comportamentul de dinainte prin înghețarea setărilor (docs/PLUGINURI.md, `catalog`).
 *
 *  - `catalog_ascunde_inactive` = '1': un produs inactiv nu apare în nicio listă (magazin, categorii și numărătoarea
 *    lor, prima pagină, cele mai vândute, manechinul) și nu are pagină; se vede doar pe linkul lui de ciornă
 *    (`/produs/<slug>?draft=<cheie>`, products.preview_token). '0' = ca înainte (produsul inactiv apare în liste;
 *    linkul de ciornă merge oricum);
 *  - `catalog_pagina`: slug-ul paginii din constructor care ține catalogul ({{shop_catalog}}); implicit „magazin”.
 *    Altul (de exemplu „produse”): /magazin face 301 acolo, cu parametrii păstrați, iar adresele de rezervă ale
 *    catalogului (linkurile „vezi toate produsele”, harta site-ului) duc acolo;
 *  - `catalog_sortare_implicita` / `catalog_sortare_vizibila`: sortarea de la prima deschidere și butonul ei;
 *    „catalog” (Ordinea din catalog) există doar când e chiar sortarea implicită;
 *  - `catalog_camp_html_brut` = '1': un câmp suplimentar de tip „html” intră în pagină ca markup ('0' = escapat, ca
 *    textul);
 *  - `catalog_similare_titlu` / `catalog_similare_subtitlu`: textele benzii {{product_produse_similare}}.
 */
final class Catalog
{
    /** Sortările catalogului, în ordinea din meniul sortării. */
    public const SORTARI = ['alpha_asc', 'featured', 'price_asc', 'price_desc'];

    /** Sortarea „Ordinea din catalog” (products.sort_position, apoi numele). */
    public const SORTARE_CATALOG = 'catalog';

    /** @param array<string, mixed> $setari */
    public static function ascundeInactive(array $setari): bool
    {
        return (string) ($setari['catalog_ascunde_inactive'] ?? '1') === '1';
    }

    /**
     * Condiția SQL a produselor care apar în liste: cu setarea pornită, doar cele active. $alias = alias-ul tabelului
     * `products` din interogare (gol = fără alias).
     *
     * @param array<string, mixed> $setari
     */
    public static function conditieActive(array $setari, string $alias = ''): string
    {
        if (!self::ascundeInactive($setari)) {
            return '';
        }
        return ' AND ' . ($alias !== '' ? $alias . '.' : '') . 'is_active = 1';
    }

    /**
     * Slug-ul paginii catalogului: setarea, dacă e un slug simplu (litere mici, cifre, „-”, cel mult două niveluri
     * despărțite de „/”), altfel „magazin”.
     *
     * @param array<string, mixed> $setari
     */
    public static function pagina(array $setari): string
    {
        $slug = trim(mb_strtolower(trim((string) ($setari['catalog_pagina'] ?? 'magazin'))), '/');
        if ($slug === '' || preg_match('#^[a-z0-9][a-z0-9-]{0,80}(/[a-z0-9][a-z0-9-]{0,80})?$#', $slug) !== 1) {
            return 'magazin';
        }
        return $slug;
    }

    /** @param array<string, mixed> $setari Adresa paginii catalogului („/magazin” implicit). */
    public static function url(array $setari): string
    {
        return '/' . self::pagina($setari);
    }

    /** Adresa catalogului citită din baza site-ului (pentru clasele fără setările la îndemână); „/magazin” fără bază. */
    public static function urlDinBaza(?PDO $db): string
    {
        static $memorat = [];
        if (!$db instanceof PDO) {
            return '/magazin';
        }
        $cheie = spl_object_id($db);
        if (!isset($memorat[$cheie])) {
            try {
                $stmt = $db->prepare("SELECT `value` FROM settings WHERE `key` = 'catalog_pagina' LIMIT 1");
                $stmt->execute();
                $valoare = $stmt->fetchColumn();
                $memorat[$cheie] = self::url($valoare === false ? [] : ['catalog_pagina' => (string) $valoare]);
            } catch (Throwable) {
                $memorat[$cheie] = '/magazin';
            }
        }
        return $memorat[$cheie];
    }

    /**
     * Adresa catalogului pentru vederi și clasele fără baza la îndemână (linkurile „vezi produsele”, formularul de
     * căutare al paginii 404): conexiunea site-ului (aceeași pe toată cererea), altfel „/magazin”.
     */
    public static function urlSite(): string
    {
        static $config = null;
        try {
            $config ??= require dirname(__DIR__, 2) . '/config/app.php';
            return self::urlDinBaza(Database::connection((array) ($config['db'] ?? [])));
        } catch (Throwable) {
            return '/magazin';
        }
    }

    /** @param array<string, mixed> $setari Sortarea de la prima deschidere a catalogului. */
    public static function sortareImplicita(array $setari): string
    {
        $sortare = trim(strtolower((string) ($setari['catalog_sortare_implicita'] ?? 'alpha_asc')));
        return in_array($sortare, [...self::SORTARI, self::SORTARE_CATALOG], true) ? $sortare : 'alpha_asc';
    }

    /** @param array<string, mixed> $setari Butonul sortării se vede? */
    public static function sortareVizibila(array $setari): bool
    {
        return (string) ($setari['catalog_sortare_vizibila'] ?? '1') === '1';
    }

    /** @param array<string, mixed> $setari Un câmp suplimentar „html” intră în pagină ca markup? */
    public static function campHtmlBrut(array $setari): bool
    {
        return (string) ($setari['catalog_camp_html_brut'] ?? '1') === '1';
    }

    /**
     * Textul legăturii unui card din benzile simple de produse ({{product_produse_similare}}, {{produse_serviciu}}):
     * „Cere ofertă” doar cu pluginul cererilor de ofertă pe site, altfel „Vezi produsul” (GR-16). Pluginul vine în WP-26
     * (GR-15); până e portat (`portat` = false în catalog), textul rămâne „Vezi produsul”, oricare ar fi licența.
     */
    public static function textLegaturaProdus(): string
    {
        $definitie = Plugins::catalog()['pluginuri']['cereri_oferta'] ?? null;
        return is_array($definitie) && ($definitie['portat'] ?? true) !== false && Plugins::vizibil('cereri_oferta')
            ? 'Cere ofertă'
            : 'Vezi produsul';
    }

    /** Cheia nouă a linkului de ciornă al unui produs (32 de caractere hexazecimale). */
    public static function cheieCiorna(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Cheia de ciornă cerută în adresă se potrivește cu a produsului? (comparație în timp constant; o cheie goală nu se
     * potrivește niciodată).
     *
     * @param array<string, mixed> $produs rândul produsului, cu `preview_token`
     */
    public static function cheiePotrivita(array $produs, string $ceruta): bool
    {
        $cheieProdus = trim((string) ($produs['preview_token'] ?? ''));
        $ceruta = trim($ceruta);
        return $cheieProdus !== '' && $ceruta !== '' && hash_equals($cheieProdus, $ceruta);
    }
}
