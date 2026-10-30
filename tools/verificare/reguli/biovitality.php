<?php

declare(strict_types=1);

/**
 * Regulile încărcării anonimizate pentru Biovitality (tools/verificare/incarca.php --din=biovitality; docs/VERIFICARE.md,
 * „Încărcarea copiei”; planul Etapei 1, WP-11b). Sunt **regulile Bioscem** (tools/verificare/reguli/bioscem.php, cu toate
 * modurile, transformările și explicațiile din capul lui), plus ce are în plus schema codului Biovitality la `779e0d8`
 * (tools/dev/migrari/vechi/bv.sql; migrari.md §2.4): o regulă a Bioscem schimbată se aplică deci și aici, iar versiunea
 * de aici o urmează (mai jos).
 *
 * În plus față de Bioscem (fail-closed: un tabel nou fără rânduri, o coloană nouă oprește încărcarea până la regula ei):
 * - **doar structura, niciun rând:** `admin_app_tokens` (jetoanele aplicației Windows a adminilor: hash-uri, IP-ul ultimei
 *   folosiri), `bunny_protected_folders` (hash-urile parolelor dosarelor din cloud, emailul celui care le-a pus),
 *   `bunny_folder_unlocks` (cine a deschis un dosar: adresa lui, încercările greșite) și, în plus față de plan,
 *   `file_locks` și `file_versions` (jurnalele fișierelor din cloud: emailul adminului, nota lui și căile documentelor
 *   firmei, care pot avea nume de oameni; pluginul `fisiere_cloud` nu e portat, deci copia nu le folosește);
 * - **copiate cum sunt:** `competition_partners`, `competition_prices` (concurenții și prețurile lor: date de business ale
 *   produselor, publice), `blog_tags`, `blog_post_tags` (etichetele blogului); coloanele Biovitality ale tabelelor comune:
 *   `products.bundle_items_json` (din ce produse e făcut un pachet), `blog_posts.references_html` și
 *   `blog_posts.featured_image_width` (conținutul public al articolelor);
 * - **ca la Bioscem:** id-urile Stripe ale comenzilor (`orders.stripe_session_id`, `stripe_payment_intent_id`) devin
 *   `anon-<id>` (regula `ref`; nu există o coloană a clientului Stripe în codul Biovitality), procesatorul linkurilor de
 *   plată (`order_payment_links.procesator`) rămâne, clienții importați din WordPress au parola înlocuită (`users`, ca toate
 *   parolele), iar „dat de” al produselor promoționale (`order_promo_items.given_by`, aici o coloană a codului vechi) are
 *   pseudonimul emailului;
 * - **setările:** în plus golite toate cheile `bunny_*` ale fișierelor din cloud care pot deschide sau arăta spațiul de
 *   stocare (zona, parola ei, regiunea, adresa CDN, dosarul de bază, cheia API și orice cheie `bunny_*` nouă: tiparul de
 *   mai jos le golește pe toate în afara celor de configurare știute), iar `bunny_enabled` = 0, ca o copie să nu atingă
 *   niciodată fișierele adevărate (port-biovitality.md §11).
 */

$reguli = require __DIR__ . '/bioscem.php';
if (!is_array($reguli) || ($reguli['site'] ?? null) !== 'bioscem' || !is_int($reguli['versiune'] ?? null)) {
    throw new RuntimeException('regulile Bioscem (baza celor Biovitality) nu au forma cerută');
}

$reguli['site'] = 'biovitality';
// Versiunea Biovitality urmează versiunea regulilor Bioscem (sutele) și pe a ei (unitățile): o schimbare în oricare face o
// încărcare oprită să nu mai fie continuată cu --reia. 1 (WP-11b): prima.
$reguli['versiune'] = $reguli['versiune'] * 100 + 1;
$reguli['schema'] = 'Mutare-Site-Biovitality@779e0d8 (tools/dev/migrari/vechi/bv.sql) + database/migrari/asteptat.json';
$reguli['licenta'] = 'biovitality';
$reguli['pachet'] = 'biovitality';

$reguli['grupuri']['catalog'][] = 'competition_partners';
$reguli['grupuri']['catalog'][] = 'competition_prices';
$reguli['grupuri']['blog'][] = 'blog_tags';
$reguli['grupuri']['blog'][] = 'blog_post_tags';

$reguli['tabele'] += [
    // ── Doar structura (Biovitality) ──
    'admin_app_tokens' => ['mod' => 'doar_structura', 'motiv' => 'jetoanele aplicației Windows a adminilor: hash-ul jetonului, IP-ul ultimei folosiri'],
    'bunny_protected_folders' => ['mod' => 'doar_structura', 'motiv' => 'parolele dosarelor din cloud (hash) și emailul celui care le-a pus'],
    'bunny_folder_unlocks' => ['mod' => 'doar_structura', 'motiv' => 'cine a deschis un dosar protejat din cloud (adresa lui) și încercările greșite'],
    'file_locks' => ['mod' => 'doar_structura', 'motiv' => 'blocările fișierelor din cloud: emailul adminului, nota lui, căile documentelor'],
    'file_versions' => ['mod' => 'doar_structura', 'motiv' => 'versiunile fișierelor din cloud: emailul adminului, căile documentelor'],

    // ── Concurența (prețurile concurenților, date de business publice) ──
    'competition_partners' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'name', 'website', 'has_campaign', 'campaign_start', 'campaign_end', 'is_active',
        'sort_order', 'created_at'], 'motive' => ['name' => 'numele unui magazin concurent (o firmă, public)']],
    'competition_prices' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'partner_id', 'product_id', 'price', 'campaign_active', 'campaign_price', 'campaign_start',
        'campaign_end', 'out_of_stock', 'only_in_store', 'product_url', 'updated_at']],

    // ── Etichetele blogului (publice) ──
    'blog_tags' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'name', 'slug', 'created_at'], 'motive' => ['name' => 'numele public al unei etichete de blog']],
    'blog_post_tags' => ['mod' => 'copiaza', 'pastreaza' => ['post_id', 'tag_id']],
];

// Coloanele Biovitality ale tabelelor comune (conținut public).
$reguli['tabele']['products']['pastreaza'][] = 'bundle_items_json';
$reguli['tabele']['blog_posts']['pastreaza'][] = 'references_html';
$reguli['tabele']['blog_posts']['pastreaza'][] = 'featured_image_width';

// Setările: fișierele din cloud (bunny.net) oprite și fără nicio cheie de acces; restul ca la Bioscem.
$reguli['setari']['goale_tipar'] = '/(password|secret|api_key|_token$|client_secret)|^bunny_(?!(?:enabled|timeout|upload_target|gallery_folder|lock_expire_hours'
    . '|versions_keep|work_folder|app_download_path)$)/';
$reguli['setari']['goale'] = array_merge($reguli['setari']['goale'], ['bunny_storage_zone', 'bunny_storage_password', 'bunny_storage_region', 'bunny_cdn_url',
    'bunny_base_folder', 'bunny_api_key']);
$reguli['setari']['zero'][] = 'bunny_enabled';

return $reguli;
