<?php

declare(strict_types=1);

/**
 * Setul Biovitality (WP-10; X9 și înghețarea setărilor BV din planul Etapei 1): V7 = numele cheilor din `settings`
 * (doar numele, pentru înghețarea setărilor BV, WP-06), plus ce folosește BV din funcțiile
 * lui proprii (pachete de produse, transport gratuit, produse ascunse, fișiere în cloud,
 * concurență, etichete de blog, articole importate din WordPress), doar ca numere. Comenzile
 * cu pachete din ultimul an sunt cele pe care golden master-ul BV (WP-11b) le numără apart.
 * OD-50 (podeaua ERP a BV) o decid rândurile „erp.de_trimis.*” din cele comune.
 * V6 (golden master-ul BV) e WP-11b, nu aici.
 */

use Unelte\Verificare\Context;
use Unelte\Verificare\Raport;

$comun = require __DIR__ . '/comun.php';

return [
    'site' => 'biovitality',
    'verificari' => array_merge($comun['grupuri'], [
        [
            'cheie' => 'v7',
            'titlu' => 'V7: numele cheilor din settings (fără valori), pentru înghețarea setărilor BV',
            'ruleaza' => static function (Context $c): void {
                if (!$c->cere('v7', ['settings.key'])) {
                    return;
                }
                $chei = [];
                $altfel = 0;
                foreach ($c->citire->randuri('SELECT `key` AS k FROM settings ORDER BY `key` LIMIT 5000') as $r) {
                    if (Raport::potrivit('nume', (string) $r['k'])) {
                        $chei[] = (string) $r['k'];
                    } else {
                        $altfel++;
                    }
                }
                $c->raport->numar('v7.chei', count($chei) + $altfel);
                $c->raport->lista('v7.chei_setari', $chei, 'nume', 'numele cheilor, în ordine', 5000);
                $c->raport->numar('v7.chei_cu_alte_caractere', $altfel, 'doar numărate');
            },
        ],
        [
            'cheie' => 'bv',
            'titlu' => 'Funcțiile proprii ale BV, doar ca numere (pachete, transport gratuit, fișiere în cloud, concurență, blog)',
            'ruleaza' => static function (Context $c): void {
                $p = $c->are(['products.deleted_at']) ? 'deleted_at IS NULL AND ' : '';
                $c->numar('bv.produse_pachet', ['products.bundle_items_json'], 'SELECT COUNT(*) FROM products WHERE ' . $p
                    . "bundle_items_json IS NOT NULL AND bundle_items_json NOT IN ('', '[]') LIMIT 1", [], 'pachete_produse (Etapa 4)');
                $o = $c->are(['orders.deleted_at']) ? 'o.deleted_at IS NULL AND ' : '';
                $c->numar('bv.comenzi_365_zile_cu_pachet', ['products.bundle_items_json', 'order_items.product_id', 'orders.created_at'],
                    'SELECT COUNT(DISTINCT oi.order_id) FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id INNER JOIN products p ON p.id = oi.product_id
                    WHERE ' . $o . "o.created_at >= NOW() - INTERVAL 365 DAY AND p.bundle_items_json IS NOT NULL AND p.bundle_items_json NOT IN ('', '[]') LIMIT 1",
                    [], 'excluse din golden master-ul BV (WP-11b)');
                $c->numar('bv.produse_transport_gratuit', ['products.free_shipping'], 'SELECT COUNT(*) FROM products WHERE ' . $p . 'free_shipping = 1 LIMIT 1', [], 'C14a');
                $c->numar('bv.produse_ascunse_din_catalog', ['products.hidden_in_catalog'], 'SELECT COUNT(*) FROM products WHERE ' . $p . 'hidden_in_catalog = 1 LIMIT 1');
                $c->numar('bv.produse_cu_ordine_manuala', ['products.sort_position'], 'SELECT COUNT(*) FROM products WHERE ' . $p . 'sort_position IS NOT NULL LIMIT 1');
                $c->numar('bv.campuri_doar_sablon', ['product_extra_fields.template_only'], 'SELECT COUNT(*) FROM product_extra_fields WHERE template_only = 1 LIMIT 1');
                foreach (['bunny_protected_folders' => 'dosare protejate cu parolă', 'bunny_folder_unlocks' => 'deblocări de dosare',
                    'file_versions' => 'versiuni de fișiere', 'file_locks' => 'fișiere blocate', 'admin_app_tokens' => 'chei ale aplicației de birou (doar numărul)',
                    'competition_partners' => 'concurenți', 'competition_prices' => 'prețuri ale concurenței', 'blog_tags' => 'etichete de blog',
                    'blog_post_tags' => 'legături articol–etichetă'] as $tabel => $descriere) {
                    $c->numar('bv.randuri.' . $tabel, [$tabel], 'SELECT COUNT(*) FROM `' . $tabel . '` LIMIT 1', [], $descriere);
                }
                $c->numar('bv.articole_importate_wordpress', ['blog_posts.wp_post_id'], 'SELECT COUNT(*) FROM blog_posts WHERE wp_post_id IS NOT NULL LIMIT 1');
            },
        ],
    ], $comun['la_final']),
];
