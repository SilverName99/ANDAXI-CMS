<?php

declare(strict_types=1);

/**
 * Regulile încărcării anonimizate pentru Biovitality (tools/verificare/incarca.php --din=biovitality; docs/VERIFICARE.md,
 * „Încărcarea copiei”; planul Etapei 1, WP-11b). Sunt **regulile Bioscem** (tools/verificare/reguli/bioscem.php, cu toate
 * modurile, transformările și explicațiile din capul lui), plus ce are în plus schema codului Biovitality la `d95598e`
 * (tools/dev/migrari/vechi/bv.sql; migrari.md §2.4): o regulă a Bioscem schimbată se aplică deci și aici, iar versiunea
 * de aici o urmează (mai jos).
 *
 * În plus față de Bioscem (fail-closed: un tabel nou fără rânduri, o coloană nouă oprește încărcarea până la regula ei):
 * - **doar structura, niciun rând:** `admin_app_tokens`, `bunny_protected_folders`, `bunny_folder_unlocks`, `file_locks` și
 *   `file_versions` (pluginul `fisiere_cloud`): din regulile Bioscem 22 sunt acolo (codul comun le face pe orice bază, migrarea
 *   fisiere_cloud/0059), cu aceleași reguli ca până atunci aici; copia nu le folosește (bunny.net oprit, mai jos);
 * - **copiate cum sunt:** `competition_partners`, `competition_prices` (concurenții și prețurile lor: date de business ale
 *   produselor, publice; din regulile Bioscem 23 sunt acolo, aceleași: codul comun le face pe orice bază, concurenta/0060). Conținutul pachetelor (`products.bundle_items_json`) îl are din pluginul pachete_produse și codul comun
 *   (migrarea pachete_produse/0055), deci regula lui e cea Bioscem (copiat cum e). Etichetele blogului (`blog_tags`, `blog_post_tags`) și coloanele `blog_posts.references_html`,
 *   `featured_image_width`, `wp_post_id` le are din WP-22 și codul comun (migrarea blog/0033), deci regulile lor sunt cele
 *   Bioscem (`wp_post_id` o pune importul `scripts/import-wordpress-posts.php` al codului Biovitality, nu instalarea, deci nu
 *   e în bv.sql, dar e pe site: setul tools/verificare/seturi/biovitality.php o numără);
 * - **ca la Bioscem:** id-urile Stripe ale comenzilor (`orders.stripe_session_id`, `stripe_payment_intent_id`) devin
 *   `anon-<id>` (regula `ref`; nu există o coloană a clientului Stripe în codul Biovitality), procesatorul linkurilor de
 *   plată (`order_payment_links.procesator`) rămâne, clienții importați din WordPress au parola înlocuită (`users`, ca toate
 *   parolele), iar „dat de” al produselor promoționale (`order_promo_items.given_by`, aici o coloană a codului vechi) are
 *   pseudonimul emailului;
 * - **setările:** golite toate cheile `bunny_*` ale fișierelor din cloud care pot deschide sau arăta spațiul de stocare (zona,
 *   parola ei, regiunea, adresa CDN, dosarul de bază, cheia API și orice cheie `bunny_*` nouă), iar `bunny_enabled` = 0, ca o
 *   copie să nu atingă niciodată fișierele adevărate (port-biovitality.md §11): din regulile Bioscem 22, regula e acolo (aceeași);
 *   lista albă a cheilor (`cunoscute`) e a codului comun și a codului Biovitality (tools/verificare/reguli/setari-cunoscute.php),
 *   nu a celui Bioscem.
 */

$reguli = require __DIR__ . '/bioscem.php';
if (!is_array($reguli) || ($reguli['site'] ?? null) !== 'bioscem' || !is_int($reguli['versiune'] ?? null)) {
    throw new RuntimeException('regulile Bioscem (baza celor Biovitality) nu au forma cerută');
}

$reguli['site'] = 'biovitality';
// Versiunea Biovitality urmează versiunea regulilor Bioscem (sutele) și pe a ei (unitățile): o schimbare în oricare face o
// încărcare oprită să nu mai fie continuată cu --reia. 1 (WP-11b): prima. 2 (revizuirea WP-11b): blog_posts.wp_post_id și
// lista albă a setărilor (cea Biovitality, mai jos). 3, 4: product_templates.builder_json, tipografia temei (typo_*),
// găsite la prima încărcare de pe server. 5 (WP-22): etichetele și coloanele blogului avansat trec în regulile Bioscem
// (codul comun le are acum, blog/0033). 6 (portul 0e54737, 5 nov): schema codului Biovitality de la d95598e, cu confirmarea
// adresei (users.email_confirmed_at și session_version, păstrate ca la Bioscem; customer_email_confirmations, cu coloanele BV
// email, reason, ip_address, expires_at, doar structura, ca la Bioscem). Din regulile Bioscem 17: products.bundle_items_json e a
// codului comun (pachete_produse/0055), regula ei a trecut acolo (aceeași: copiată cum e). Din regulile Bioscem 18: campaniile de pe site
// (campanii, campanii_produse; campanii/0054, codul comun), copiate cum sunt; baza biovitality.ro de pe codul vechi nu le are. Din
// regulile Bioscem 19, 20 și 21: coloanele campanii/0056, campanii/0057 și campanii/0058, copiate cum sunt. Din regulile Bioscem 22: tabelele
// și setările fișierelor din cloud (fisiere_cloud/0059) au trecut acolo, aceleași reguli. Din regulile Bioscem 23: concurenții și prețurile
// lor (concurenta/0060) la fel, în grupul catalogului.
$reguli['versiune'] = $reguli['versiune'] * 100 + 6;
$reguli['schema'] = 'Mutare-Site-Biovitality@d95598e (tools/dev/migrari/vechi/bv.sql) + database/migrari/asteptat.json';
$reguli['licenta'] = 'biovitality';
$reguli['pachet'] = 'biovitality';

// Găsită la prima încărcare de pe server (30 oct 2026): coloana există în baza biovitality.ro, dar niciun cod din
// depozite n-o scrie sau citește. E machetarea unui șablon de pagină de produs, făcută de admin: conținut public.
$reguli['tabele']['product_templates']['pastreaza'][] = 'builder_json';

// Setările fișierelor din cloud (bunny.net oprit, fără nicio cheie de acces) sunt în regulile Bioscem (22); restul ca la Bioscem.
// Lista albă a setărilor: cheile codului comun și ale codului Biovitality (Settings::DEFAULTS și restul celor folosite), nu
// ale celui Bioscem; o altă cheie a sursei oprește încărcarea.
$cunoscute = require __DIR__ . '/setari-cunoscute.php';
$reguli['setari']['cunoscute'] = array_values(array_unique(array_merge($cunoscute['nucleu'], $cunoscute['biovitality'])));
// Găsite la prima încărcare de pe server (30 oct 2026): tipografia temei (culoarea, fontul și mărimea textului și a
// titlurilor), rămasă în baza biovitality.ro de la un editor de temă pe care codul de azi nu-l mai are. Doar aspect, nimic
// secret sau personal: se copiază ca atare.
foreach (['body', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $element) {
    foreach (['color', 'font_family', 'font_size'] as $proprietate) {
        $reguli['setari']['cunoscute'][] = 'typo_' . $element . '_' . $proprietate;
    }
}

return $reguli;
