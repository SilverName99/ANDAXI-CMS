<?php

declare(strict_types=1);

/**
 * Setul Bioscem (WP-10): verificările V1–V5 ale portului Biovitality (X9) și BS-V1, BS-V2 ale
 * portului grafoanaytis (GR-24) din planul Etapei 1 (BS-V3 = galeria, în cele comune), plus cele comune
 * (seturi/comun.php; OD-33 = comutatoarele și activitatea pluginurilor). Rulează pe baza
 * Bioscem live, strict în citire; tipărește doar numere și id-uri de produse.
 *
 * Ce decide fiecare: V1 cât de des s-au pierdut cheile ofertelor BBD (C1, deja portat);
 * V2 lista produselor inactive comandate în 90 de zile (poarta C8 pe Bioscem, confirmată de
 * client); V3 granularitatea setărilor blogului avansat (WP-22) și X1b (WP-12); V4 poarta
 * C14d (câmpuri html cu marcaj); V5 siguranța C9 (coduri cu spații); BS-V1 poarta GR-09
 * ({{shop_catalog}} în altă pagină decât /magazin); BS-V2 GR-06 ({{an}} scris în antet sau
 * subsol).
 */

use Unelte\Verificare\Context;

/** Slug::make din Biovitality (src/Support/Slug.php la cc231e4), pentru V3 a. */
$slugBv = static function (string $valoare): string {
    static $transliterari = [
        'ă' => 'a', 'â' => 'a', 'á' => 'a', 'à' => 'a', 'ä' => 'a', 'å' => 'a', 'ã' => 'a',
        'î' => 'i', 'í' => 'i', 'ì' => 'i', 'ï' => 'i',
        'ș' => 's', 'ş' => 's', 'š' => 's',
        'ț' => 't', 'ţ' => 't',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c',
        'ñ' => 'n', 'ß' => 'ss', 'ý' => 'y', 'ž' => 'z', 'ź' => 'z',
        'æ' => 'ae', 'œ' => 'oe', 'đ' => 'd', 'ł' => 'l',
    ];
    $valoare = mb_strtolower(trim($valoare), 'UTF-8');
    $valoare = strtr($valoare, $transliterari);
    $valoare = (string) preg_replace('/[^a-z0-9]+/', '-', $valoare);
    return trim($valoare, '-');
};

/** Comenzile care țin stoc dintr-o ofertă BBD (Admin::computeBbdReservedMap). */
$rezerva = "o.status NOT IN ('cancelled', 'failed', 'refunded', 'returned', 'pending_payment')";

/**
 * Cheia cu care checkout-ul recunoaște o ofertă BBD (CheckoutCalculator, ca la Bioscem 9f59e79):
 * cheia salvată, dacă e validă, altfel sha1(data|lot|preț) tăiat la 20; null pentru o ofertă
 * fără dată (checkout-ul o sare). A doua valoare: cheia era salvată?
 *
 * @return array{0: ?string, 1: bool}
 */
$cheieOferta = static function (array $oferta): array {
    $dataText = trim((string) ($oferta['date'] ?? $oferta['bbd_date'] ?? ''));
    $moment = strtotime($dataText);
    if ($dataText === '' || $moment === false) {
        return [null, false];
    }
    $salvata = is_scalar($oferta['key'] ?? null) ? strtolower(trim((string) $oferta['key'])) : '';
    if (preg_match('/^[a-z0-9]{1,64}$/', $salvata) === 1) {
        return [$salvata, true];
    }
    $pret = null;
    $pretText = str_replace(',', '.', trim((string) ($oferta['reduced_price'] ?? $oferta['price'] ?? '')));
    if ($pretText !== '' && is_numeric($pretText) && max(0.0, (float) $pretText) > 0.0) {
        $pret = (float) number_format(max(0.0, (float) $pretText), 2, '.', '');
    }
    $lot = (string) (preg_replace('/[^A-Z0-9\-_.\/]/', '', strtoupper(trim((string) ($oferta['lot'] ?? $oferta['bbd_lot'] ?? '')))) ?? '');
    $lot = substr($lot, 0, 40);
    return [substr(sha1(date('Y-m-d', $moment) . '|' . $lot . '|' . ($pret === null ? '-' : number_format($pret, 2, '.', ''))), 0, 20), false];
};

$comun = require __DIR__ . '/comun.php';

return [
    'site' => 'bioscem',
    'verificari' => array_merge($comun['grupuri'], [
        [
            'cheie' => 'v1',
            'titlu' => 'V1: cheile ofertelor BBD (C1): oferte fără cheie salvată, rezervări pe chei care nu mai există',
            'ruleaza' => static function (Context $c) use ($rezerva, $cheieOferta): void {
                if (!$c->cere('v1', ['products.bbd_entries_json', 'order_items.bbd_key', 'order_items.product_id', 'orders.status'])) {
                    return;
                }
                $cheiProdus = [];
                $faraCheie = 0;
                $invalid = 0;
                $conditie = "bbd_entries_json IS NOT NULL AND bbd_entries_json <> '' AND bbd_entries_json <> '[]'"
                    . ($c->are(['products.deleted_at']) ? ' AND deleted_at IS NULL' : '');
                $parcurs = $c->citire->parcurge('products', ['bbd_entries_json'], $conditie, [],
                    static function (array $r, int $id) use (&$cheiProdus, &$faraCheie, &$invalid, $cheieOferta): void {
                        $oferte = json_decode((string) $r['bbd_entries_json'], true);
                        if (!is_array($oferte)) {
                            $invalid++;
                            return;
                        }
                        $cheiProdus[$id] = [];
                        $lipsa = false;
                        foreach ($oferte as $oferta) {
                            if (!is_array($oferta)) {
                                continue;
                            }
                            [$cheie, $salvata] = $cheieOferta($oferta);
                            if ($cheie !== null) {
                                $cheiProdus[$id][$cheie] = true;
                                $lipsa = $lipsa || !$salvata;
                            }
                        }
                        if ($lipsa) {
                            $faraCheie++;
                        }
                    });
                $c->raport->numar('v1.produse_cu_oferte_bbd', count($cheiProdus) + $invalid);
                $c->raport->numar('v1.produse_cu_o_oferta_fara_cheie', $faraCheie,
                    'cheia vine din dată, lot și preț, deci se schimbă cu prețul (C1 o păstrează de la prima salvare)');
                $c->raport->numar('v1.produse_cu_json_stricat', $invalid);
                $o = $c->are(['orders.deleted_at']) ? ' AND o.deleted_at IS NULL' : '';
                $randuri = $c->citire->randuri("SELECT oi.product_id AS pid, oi.bbd_key AS bk, COUNT(*) AS n
                    FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id
                    WHERE oi.bbd_key IS NOT NULL AND oi.bbd_key <> '' AND " . $rezerva . $o . '
                    GROUP BY oi.product_id, oi.bbd_key LIMIT 20000');
                $perechi = 0;
                $linii = 0;
                foreach ($randuri as $r) {
                    if (!isset($cheiProdus[(int) $r['pid']][strtolower((string) $r['bk'])])) {
                        $perechi++;
                        $linii += (int) $r['n'];
                    }
                }
                $c->raport->numar('v1.chei_rezervate_fara_oferta_curenta', $perechi, 'chei din comenzi active care nu mai sunt în ofertele produsului');
                $c->raport->numar('v1.linii_de_comanda_pe_chei_disparute', $linii);
                if (count($randuri) >= 20000 || $parcurs['partial'] || $parcurs['prea_mari'] > 0) {
                    $c->raport->daNu('v1.partial', true, 'doar primele rânduri (limita de citire) sau un JSON prea mare (peste 8 MB sau peste memoria PHP), necitit', false);
                }
            },
        ],
        [
            'cheie' => 'v2',
            'titlu' => 'V2: produsele inactive comandate în ultimele 90 de zile (C8; lista se confirmă cu clientul)',
            'ruleaza' => static function (Context $c): void {
                if (!$c->cere('v2', ['products.is_active', 'order_items.product_id', 'orders.created_at'])) {
                    return;
                }
                $p = $c->are(['products.deleted_at']) ? ' AND p.deleted_at IS NULL' : '';
                $o = $c->are(['orders.deleted_at']) ? ' AND o.deleted_at IS NULL' : '';
                $id = array_map(static fn (array $r): int => (int) $r['pid'], $c->citire->randuri('SELECT DISTINCT oi.product_id AS pid
                    FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id INNER JOIN products p ON p.id = oi.product_id
                    WHERE p.is_active = 0' . $p . $o . ' AND o.created_at >= NOW() - INTERVAL 90 DAY ORDER BY oi.product_id LIMIT 1000'));
                $c->raport->numar('v2.produse_inactive_comandate_90_zile', count($id));
                $c->raport->lista('v2.id_produse', $id, 'id', 'id-urile din admin → Produse (date de catalog, nu personale)');
            },
        ],
        [
            'cheie' => 'v3',
            'titlu' => 'V3: blogul (a slug-uri care s-ar schimba, b cuprins, c articole înrudite, d mai multe categorii, e categorie doar ca text)',
            'ruleaza' => static function (Context $c) use ($slugBv): void {
                if (!$c->cere('v3', ['blog_posts.slug', 'blog_posts.content'])) {
                    return;
                }
                $b = $c->are(['blog_posts.deleted_at']) ? 'deleted_at IS NULL' : '';
                $diferite = 0;
                $c->citire->parcurge('blog_posts', ['slug'], $b, [], static function (array $r) use ($slugBv, &$diferite): void {
                    $slug = (string) $r['slug'];
                    if ($slugBv($slug) !== $slug) {
                        $diferite++;
                    }
                }, 500);
                $c->raport->numar('v3a.sluguri_care_s_ar_schimba', $diferite, 'Slug::make(slug) din BV dă alt slug');
                $w = $b !== '' ? $b . ' AND ' : '';
                $c->numar('v3b.articole_cu_2_titluri_h2_h3', [], 'SELECT COUNT(*) FROM blog_posts WHERE ' . $w
                    . "((CHAR_LENGTH(LOWER(content)) - CHAR_LENGTH(REPLACE(LOWER(content), '<h2', ''))) / 3
                      + (CHAR_LENGTH(LOWER(content)) - CHAR_LENGTH(REPLACE(LOWER(content), '<h3', ''))) / 3) >= 2 LIMIT 1", [], 'ar primi cuprins (blogul avansat)');
                $c->numar('v3c.sabloane_cu_articole_inrudite', ['blog_templates.html_content'],
                    'SELECT COUNT(*) FROM blog_templates WHERE INSTR(html_content, ?) > 0 LIMIT 1', ['data-blog-related-list']);
                $c->numar('v3d.articole_cu_mai_multe_categorii', ['blog_post_categories.post_id'],
                    'SELECT COUNT(*) FROM (SELECT post_id FROM blog_post_categories GROUP BY post_id HAVING COUNT(*) > 1 LIMIT 50000) x LIMIT 1');
                if ($c->cere('v3e', ['blog_posts.category', 'blog_posts.category_id', 'blog_post_categories.post_id'])) {
                    $doarText = 'p.' . ($b !== '' ? 'deleted_at IS NULL AND p.' : '') . "category IS NOT NULL AND TRIM(p.category) <> '' AND COALESCE(p.category_id, 0) = 0
                        AND NOT EXISTS (SELECT 1 FROM blog_post_categories bpc WHERE bpc.post_id = p.id)";
                    $c->numar('v3e.articole_cu_categoria_doar_text', [], 'SELECT COUNT(*) FROM blog_posts p WHERE ' . $doarText . ' LIMIT 1', [], 'X1b');
                    $c->numar('v3e.din_ele_cu_nume_de_categorie_existent', ['blog_categories.name'], 'SELECT COUNT(*) FROM blog_posts p WHERE ' . $doarText . '
                        AND EXISTS (SELECT 1 FROM blog_categories bc WHERE bc.name = p.category) LIMIT 1', [], 'X1b le poate bifa după nume');
                }
            },
        ],
        [
            'cheie' => 'v4',
            'titlu' => 'V4: câmpurile de produs de tip html cu marcaj (C14d)',
            'ruleaza' => static function (Context $c): void {
                if (!$c->cere('v4', ['product_extra_fields.field_type', 'product_extra_field_values.value', 'product_extra_field_values.field_id'])) {
                    return;
                }
                $c->numar('v4.campuri_html', [], "SELECT COUNT(*) FROM product_extra_fields WHERE field_type = 'html' LIMIT 1");
                $r = $c->citire->randuri("SELECT COUNT(DISTINCT f.id) AS campuri, COUNT(DISTINCT v.product_id) AS produse, COUNT(*) AS valori
                    FROM product_extra_fields f INNER JOIN product_extra_field_values v ON v.field_id = f.id
                    WHERE f.field_type = 'html' AND INSTR(v.value, '<') > 0 LIMIT 1")[0];
                $c->raport->numar('v4.campuri_html_cu_marcaj', (int) $r['campuri']);
                $c->raport->numar('v4.produse_cu_marcaj_in_campuri_html', (int) $r['produse'], 'peste 0: C14d are nevoie de poarta catalog_camp_html_brut');
                $c->raport->numar('v4.valori_cu_marcaj', (int) $r['valori']);
            },
        ],
        [
            'cheie' => 'v5',
            'titlu' => 'V5: codurile de cupon cu spații (C9)',
            'ruleaza' => static function (Context $c): void {
                if (!$c->cere('v5', ['coupons.code', 'coupons.is_active'])) {
                    return;
                }
                $c->numar('v5.coduri_cu_spatii', [], "SELECT COUNT(*) FROM coupons WHERE code REGEXP '[[:space:]]' LIMIT 1");
                $c->numar('v5.coduri_active_cu_spatii_egale_fara_spatii_cu_alt_cod', [], "SELECT COUNT(*) FROM coupons a
                    WHERE a.is_active = 1 AND a.code REGEXP '[[:space:]]' AND EXISTS (SELECT 1 FROM coupons b WHERE b.is_active = 1 AND b.id <> a.id
                    AND b.code = UPPER(REPLACE(REPLACE(REPLACE(REPLACE(a.code, ' ', ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), ''))) LIMIT 1",
                    [], 'C9 l-ar găsi pe celălalt cod; trebuie 0', 0);
            },
        ],
        [
            'cheie' => 'bs',
            'titlu' => 'BS-V1, BS-V2: {{shop_catalog}} în alte pagini decât /magazin (GR-09), {{an}} în antet sau subsol (GR-06)',
            'ruleaza' => static function (Context $c): void {
                if ($c->cere('bs_v1', ['pages.slug', 'pages.html_content', 'pages.is_published'])) {
                    $n = $c->are(['pages.deleted_at']) ? 'deleted_at IS NULL AND ' : '';
                    $conditie = 'SELECT %s FROM pages WHERE ' . $n . "INSTR(html_content, '{{shop_catalog}}') > 0 AND slug <> 'magazin' AND %s";
                    $c->numar('bs_v1.pagini_publicate', [], sprintf($conditie, 'COUNT(*)', 'is_published = 1') . ' LIMIT 1');
                    $c->numar('bs_v1.pagini_nepublicate', [], sprintf($conditie, 'COUNT(*)', 'is_published <> 1') . ' LIMIT 1');
                    $sluguri = [];
                    foreach ($c->citire->randuri(sprintf($conditie, 'slug AS s', 'is_published = 1') . ' ORDER BY slug LIMIT 200') as $r) {
                        if (\Unelte\Verificare\Raport::potrivit('slug', (string) $r['s'])) {
                            $sluguri[] = (string) $r['s'];
                        }
                    }
                    $c->raport->lista('bs_v1.sluguri', $sluguri, 'slug', 'pagini publicate (adrese publice)');
                }
                if ($c->cere('bs_v2', ['settings.key', 'settings.value'])) {
                    $are = [];
                    foreach ($c->citire->randuri("SELECT `key` AS k, (INSTR(COALESCE(`value`, ''), '{{an}}') > 0) AS are FROM settings
                        WHERE `key` IN ('design_header_html', 'design_footer_html') LIMIT 10") as $r) {
                        $are[(string) $r['k']] = (int) $r['are'] === 1;
                    }
                    $c->raport->daNu('bs_v2.antet_are_an', $are['design_header_html'] ?? false, 'textul {{an}} scris în design_header_html');
                    $c->raport->daNu('bs_v2.subsol_are_an', $are['design_footer_html'] ?? false, 'textul {{an}} scris în design_footer_html');
                }
            },
        ],
    ], $comun['la_final']),
];
