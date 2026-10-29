<?php

declare(strict_types=1);

/**
 * Date demo INVENTATE pentru baza de dezvoltare (cms_dev).
 *
 * Nicio informație de aici nu vine de la un client real: firma e „Firma Demo
 * SRL”, emailurile sunt toate @exemplu.ro, telefoanele 07xx 000 xxx, AWB-urile
 * încep cu DEMO. Se rulează după scripts/install.php și scripts/seed.php, din
 * tools/dev/instaleaza.sh, cu mediul pregătit de tools/dev/comun.sh.
 *
 * Schema o fac migrările (scripts/install.php, docs/MIGRARI.md) sau, pentru o
 * bază „ca înainte”, tools/dev/migrari/pe-vechi.php: aici nu se mai cheamă
 * funcțiile ensure* (pe o bază la zi ele nu mai fac nimic).
 *
 * Ce face, în ordine:
 *  1. pune setările de dezvoltare: plățile, ERP-ul și FAN-ul în mod test sau
 *     oprite, cu date de acces inventate, emailurile doar spre @exemplu.ro;
 *  2. scrie datele demo: categorii, ~40 de produse, clienți, ~60 de comenzi,
 *     cupoane, puncte de fidelitate, recenzii, blog, newsletter, pagini.
 *
 * Refuză să ruleze pe o bază al cărei nume nu începe cu „cms_”.
 *
 * Singura excepție: copia demonstrativă de pe site-ul de verificare (tools/verificare/incarca.php --demo, decizia
 * OD-39; docs/VERIFICARE.md), care încarcă fișierul după ce definește ANDAXI_DEMO_PE_VERIFICARE. Atunci garda e alta:
 * site-ul e copia de verificare (SITE_COPIE_VERIFICARE=1) și baza e o copie demonstrativă în lucru (tabelul _verificare cu
 * sursa „demo” și anonimizat = 0), deci nu poate fi baza unui site. Un refuz e atunci o excepție (încărcarea șterge copia).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../../bootstrap.php';

use App\Support\Database;
use App\Support\FanNomenclator;
use App\Support\LoyaltyService;
use App\Support\OrderNumber;
use App\Support\Precomanda;
use App\Support\Settings;

$peVerificare = defined('ANDAXI_DEMO_PE_VERIFICARE');
$refuza = static function (string $mesaj) use ($peVerificare): never {
    if ($peVerificare) {
        throw new RuntimeException('date-demo.php: ' . $mesaj);
    }
    fwrite(STDERR, $mesaj . "\n");
    exit(1);
};

$config = require __DIR__ . '/../../config/app.php';
$numeBaza = (string) ($config['db']['database'] ?? '');
if (!$peVerificare && preg_match('/^cms_[a-z0-9_]+$/', $numeBaza) !== 1) {
    $refuza("Baza '{$numeBaza}' nu începe cu cms_; date-demo.php rulează doar pe baze de test.");
}
if (!$peVerificare && !str_starts_with((string) ($config['url'] ?? ''), 'http://127.0.0.1:')) {
    $refuza('APP_URL trebuie să fie http://127.0.0.1:<port> (vezi tools/dev/comun.sh).');
}

$db = Database::connection($config['db']);
if (!$db instanceof PDO) {
    $refuza($peVerificare ? 'nu mă pot conecta la baza copiei.' : "Nu mă pot conecta la baza {$numeBaza}.");
}
if ($peVerificare) {
    $marcaj = \App\Support\Verificare::activ()
        && (int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '_verificare'")->fetchColumn() === 1
        ? $db->query('SELECT sursa, anonimizat FROM _verificare LIMIT 1')->fetch(PDO::FETCH_ASSOC) : false;
    if (!is_array($marcaj) || (string) $marcaj['sursa'] !== 'demo' || (int) $marcaj['anonimizat'] !== 0) {
        $refuza('rulează pe un site doar pe o copie demonstrativă de verificare în lucru (_verificare: sursa demo, anonimizat 0).');
    }
}

$produseExistente = (int) $db->query('SELECT COUNT(*) FROM products WHERE sku LIKE "DEMO-%"')->fetchColumn();
if ($produseExistente > 0) {
    $refuza('Baza are deja date demo. Refă-o cu tools/dev/instaleaza.sh.');
}

mt_srand(20261018); // aceleași date la fiecare rulare

// ---------------------------------------------------------------------------
// 1. Setări de dezvoltare
// ---------------------------------------------------------------------------

echo "Setări de dezvoltare (plăți/ERP/FAN în test sau oprite)...\n";
Settings::save($db, [
    // Numele și adresele de email: doar @exemplu.ro, ca nimic să nu plece spre un client real.
    'store_name' => 'Firma Demo',
    'contact_form_recipients' => 'contact@exemplu.ro',
    'order_email_from_name' => 'Firma Demo',
    'order_email_from_address' => 'comenzi@exemplu.ro',
    'email_delivery_method' => 'smtp',
    'smtp_host' => '', // fără SMTP => mail(), pe care tools/dev îl scrie în fișiere
    'smtp_username' => '',
    'smtp_password' => '',
    'sendgrid_api_key' => '',
    'welcome_popup_enabled' => '1',
    'welcome_popup_title' => 'Bine ați venit pe site-ul demo!',
    'welcome_popup_body' => "Acesta este un site de test al Firma Demo SRL.\n\nToate produsele, comenzile și persoanele sunt inventate.",
    'welcome_popup_version' => '1',
    'cookie_banner_policy_url' => '/politica-cookie',

    // EuPlătesc nu are o adresă de test separată în cod: rămâne oprit.
    'euplatesc_enabled' => '0',
    'euplatesc_merchant_id' => 'DEMO-MERCHANT',
    'euplatesc_secret_key' => 'demo-cheie-euplatesc',
    // Stripe: chei de test inventate, oprit.
    'stripe_enabled' => '0',
    'stripe_publishable_key' => 'pk_test_demo',
    'stripe_secret_key' => 'sk_test_demo',
    'stripe_webhook_secret' => 'whsec_demo',
    // BT iPay: pornit în modul test (BT_IPAY_MODE=test), cu adresa băncii pe
    // gaura neagră din tools/dev/comun.sh: opțiunea apare în checkout, dar
    // orice încercare de plată se oprește local.
    'bt_ipay_enabled' => '1',
    'bt_ipay_notify_emails' => 'plati@exemplu.ro',
    'bank_transfer_enabled' => '1',
    'bank_transfer_instructiuni' => "Plata prin ordin de plată în contul Firma Demo SRL, IBAN RO00 DEMO 0000 0000 0000 0000 (cont inventat), cu numărul comenzii la detalii.",

    // ERP: oprit, adresă inexistentă, cheie inventată.
    'erp_enabled' => '0',
    'erp_url' => 'http://127.0.0.1:9',
    'erp_api_key' => 'demo-cheie-erp',
    'erp_stock_enabled' => '0',

    // FAN Courier: date de acces inventate, fără tarif live și fără AWB automat.
    'fan_live_tariff_enabled' => '0',
    'fan_awb_auto' => '0',
    'fan_client_id' => '1000001',
    'fan_api_username' => 'demo',
    'fan_api_password' => 'demo-parola-fan',
    'fan_sender_name' => 'Firma Demo SRL',
    'fan_sender_phone' => '0700000000',
    'fan_sender_email' => 'livrari@exemplu.ro',
    'fan_sender_county' => 'Bucuresti',
    'fan_sender_locality' => 'Bucuresti',
    'fan_sender_street' => 'Strada Exemplului',
    'fan_sender_street_no' => '1',
    'fan_sender_zip_code' => '010000',

    // Servicii externe în pagină: toate oprite.
    'customer_google_auth_enabled' => '0',
    'google_analytics_enabled' => '0',
    'google_tag_manager_enabled' => '0',
    'google_ads_enabled' => '0',
    'microsoft_clarity_enabled' => '0',
    'tawk_enabled' => '0',

    'store_seo_home_title' => 'Firma Demo — magazin de test',
    'store_seo_home_description' => 'Site de dezvoltare ANDAXI-CMS cu date inventate.',
]);

// ---------------------------------------------------------------------------
// 2. Date demo
// ---------------------------------------------------------------------------

$acum = time();
$zi = 86400;
$dataSql = static fn (int $ts): string => date('Y-m-d H:i:s', $ts);
$alege = static function (array $lista): mixed {
    return $lista[mt_rand(0, count($lista) - 1)];
};
$parolaClienti = (string) ($config['admin']['default_password'] ?? '');
if ($parolaClienti === '') {
    $parolaClienti = bin2hex(random_bytes(9));
}
$hashClienti = password_hash($parolaClienti, PASSWORD_BCRYPT);

/** Imagine PNG inventată (fundal colorat + numele produsului), în public/uploads/demo/. */
$imagine = static function (string $nume, string $fisier, array $culoare): string {
    $dir = __DIR__ . '/../../public/uploads/demo';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $cale = $dir . '/' . $fisier . '.png';
    if (!function_exists('imagecreatetruecolor')) {
        return '/assets/img/product-placeholder.svg';
    }
    // Text mic desenat cu fontul încorporat, apoi mărit: fără fonturi externe.
    $mic = imagecreatetruecolor(150, 150);
    imagefill($mic, 0, 0, imagecolorallocate($mic, $culoare[0], $culoare[1], $culoare[2]));
    $alb = imagecolorallocate($mic, 255, 255, 255);
    $cuvinte = explode(' ', strtr($nume, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ț' => 't', 'Ă' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ț' => 'T']));
    $randuri = [];
    $rand = '';
    foreach ($cuvinte as $cuvant) {
        if (strlen($rand . ' ' . $cuvant) > 16 && $rand !== '') {
            $randuri[] = $rand;
            $rand = $cuvant;
        } else {
            $rand = trim($rand . ' ' . $cuvant);
        }
    }
    $randuri[] = $rand;
    $y = (int) (75 - count($randuri) * 8);
    foreach (array_slice($randuri, 0, 5) as $text) {
        imagestring($mic, 3, (int) max(4, (150 - strlen($text) * 7) / 2), $y, $text, $alb);
        $y += 16;
    }
    imagestring($mic, 2, 50, 130, 'DEMO', $alb);
    $mare = imagecreatetruecolor(600, 600);
    imagecopyresampled($mare, $mic, 0, 0, 0, 0, 600, 600, 150, 150);
    imagepng($mare, $cale, 6);
    return '/uploads/demo/' . $fisier . '.png';
};

// --- Categorii ---------------------------------------------------------------
echo "Categorii și produse...\n";
$categorii = [
    'Uleiuri' => [[46, 125, 50]],
    'Miere' => [[230, 145, 30]],
    'Suplimente' => [[40, 90, 160]],
    'Ceaiuri' => [[90, 140, 60]],
    'Cosmetice naturale' => [[170, 80, 140]],
    'Superalimente' => [[150, 60, 40]],
    'Cadouri' => [[200, 50, 70]],
];
$idCategorie = [];
$insCategorie = $db->prepare('INSERT IGNORE INTO product_categories (name, slug) VALUES (:name, :slug)');
foreach (array_keys($categorii) as $numeCategorie) {
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(strtr($numeCategorie, ['ă' => 'a', 'î' => 'i', 'ș' => 's', 'ț' => 't', 'â' => 'a']))), '-');
    $insCategorie->execute(['name' => $numeCategorie, 'slug' => $slug]);
    $idCategorie[$numeCategorie] = (int) $db->query('SELECT id FROM product_categories WHERE name = ' . $db->quote($numeCategorie))->fetchColumn();
}

// --- Șablon de pagină de produs (tokenurile pe care le folosește un site real) ---
$db->prepare(
    'INSERT INTO product_templates (name, slug, description, html_content, css_content, js_content, is_active)
     VALUES (:name, :slug, :description, :html, :css, "", 1)'
)->execute([
    'name' => 'Șablon demo produs',
    'slug' => 'sablon-demo-produs',
    'description' => 'Șablon de test cu tokenurile principale.',
    'html' => '<section class="demo-produs"><div class="demo-produs__galerie">{{product_image_carousel}}</div>'
        . '<div class="demo-produs__info"><p class="demo-produs__categorie"><a href="{{product_category_url}}">{{product_category}}</a></p>'
        . '<h1>{{product_name}}</h1><p class="demo-produs__sku">Cod: {{product_sku}}</p>'
        . '<p class="demo-produs__pret">{{product_old_price_display}} {{product_price_display}}</p>'
        . '<div>{{product_short_description}}</div><div>{{product_highlights}}</div>'
        . '{{product_bbd_selector}}<div class="demo-produs__cos">{{product_quantity_input}} {{product_add_to_cart_button}}</div></div></section>'
        . '<section>{{product_tabs_section}}</section><section>{{product_description}}</section>{{product_similar_products_section}}',
    'css' => '.demo-produs{display:grid;grid-template-columns:1fr 1fr;gap:32px;padding:24px 0}@media(max-width:800px){.demo-produs{grid-template-columns:1fr}}',
]);
$idSablon = (int) $db->lastInsertId();

// --- Produse -------------------------------------------------------------------
$produseDemo = [
    // [nume, categorie, preț, preț redus|null, stoc, marca]
    ['Ceai de mentă', 'Ceaiuri', 14.50, null, 120, 'Livada Demo'],
    ['Ceai de mușețel', 'Ceaiuri', 13.90, null, 80, 'Livada Demo'],
    ['Ceai verde sencha', 'Ceaiuri', 24.00, 19.90, 60, 'Natura Exemplu'],
    ['Ceai de tei', 'Ceaiuri', 15.00, null, 45, 'Livada Demo'],
    ['Ceai rooibos', 'Ceaiuri', 21.00, null, 30, 'Natura Exemplu'],
    ['Ceai de fructe de pădure', 'Ceaiuri', 17.50, null, 0, 'Livada Demo'],
    ['Ceai de ghimbir', 'Ceaiuri', 18.00, null, 25, 'Natura Exemplu'],
    ['Săpun cu lavandă', 'Cosmetice naturale', 19.00, null, 70, 'Atelier Exemplu'],
    ['Cremă de mâini cu gălbenele', 'Cosmetice naturale', 29.00, 24.00, 40, 'Atelier Exemplu'],
    ['Balsam de buze cu ceară de albine', 'Cosmetice naturale', 12.00, null, 90, 'Apicola Test'],
    ['Ulei de corp cu migdale', 'Cosmetice naturale', 39.00, null, 20, 'Atelier Exemplu'],
    ['Șampon solid cu urzică', 'Cosmetice naturale', 34.00, null, 15, 'Atelier Exemplu'],
    ['Unt de shea pur', 'Cosmetice naturale', 31.00, null, 0, 'Natura Exemplu'],
    ['Semințe de chia', 'Superalimente', 22.00, null, 100, 'Natura Exemplu'],
    ['Pudră de cacao crudă', 'Superalimente', 27.50, null, 55, 'Natura Exemplu'],
    ['Fulgi de ovăz integral', 'Superalimente', 9.90, null, 200, 'Livada Demo'],
    ['Fructe goji uscate', 'Superalimente', 36.00, 29.90, 35, 'Natura Exemplu'],
    ['Semințe de cânepă decorticate', 'Superalimente', 33.00, null, 25, 'Natura Exemplu'],
    ['Pudră de baobab', 'Superalimente', 41.00, null, 12, 'Natura Exemplu'],
    ['Curcuma pudră', 'Superalimente', 16.00, null, 65, 'Livada Demo'],
    ['Miere de salcâm', 'Miere', 38.00, null, 50, 'Apicola Test'],
    ['Miere polifloră', 'Miere', 29.00, null, 75, 'Apicola Test'],
    ['Miere de tei', 'Miere', 35.00, null, 40, 'Apicola Test'],
    ['Polen crud', 'Miere', 45.00, 39.00, 18, 'Apicola Test'],
    ['Tinctură de propolis', 'Miere', 26.00, null, 60, 'Apicola Test'],
    ['Ulei de măsline extravirgin', 'Uleiuri', 54.00, null, 40, 'Livada Demo'],
    ['Ulei de in presat la rece', 'Uleiuri', 23.00, null, 30, 'Livada Demo'],
    ['Ulei de nucă', 'Uleiuri', 42.00, null, 10, 'Livada Demo'],
    ['Ulei de dovleac', 'Uleiuri', 37.00, null, 22, 'Livada Demo'],
    ['Vitamina C 500 mg', 'Suplimente', 32.00, null, 150, 'Vital Exemplu'],
    ['Magneziu bisglicinat', 'Suplimente', 58.00, 49.00, 80, 'Vital Exemplu'],
    ['Vitamina D3 2000 UI', 'Suplimente', 44.00, null, 90, 'Vital Exemplu'],
    ['Zinc 15 mg', 'Suplimente', 27.00, null, 0, 'Vital Exemplu'],
    ['Omega-3 din alge', 'Suplimente', 89.00, null, 25, 'Vital Exemplu'],
    ['Probiotic 10 tulpini', 'Suplimente', 72.00, null, 0, 'Vital Exemplu'],
    ['Set cadou ceaiuri', 'Cadouri', 79.00, null, 12, 'Livada Demo'],
    ['Coș cadou cu miere', 'Cadouri', 149.00, 129.00, 6, 'Apicola Test'],
];
// Câteva roluri speciale, după poziția în listă (indice de la 0).
$inactive = [4, 12, 27];           // ascunse din magazin (is_active = 0)
$inCos = [18];                      // în coșul de gunoi (deleted_at)
$bbd = [20, 25, 30, 13];            // oferte BBD (loturi cu dată)
$precomanda = [32, 34];             // stoc 0, vânzare prin precomandă
$epuizate = [5];                    // stoc 0, marcat epuizat
$faraSablon = [1, 21];              // pagina de produs pe varianta fără șablon (product.php)

$insProdus = $db->prepare(
    'INSERT INTO products (name, sku, category, category_id, product_template_id, slug, short_description, description,
        product_highlights, price, sale_price, bbd_enabled, bbd_entries_json, stock, out_of_stock, preorder_enabled,
        preorder_max_per_order, preorder_max_total, weight_grams, image_url, badge_popular, badge_best_seller,
        badge_seasonal, is_active, deleted_at, brand, tags_json)
     VALUES (:name, :sku, :category, :category_id, :tpl, :slug, :short, :descr, :highlights, :price, :sale, :bbd, :bbd_json,
        :stock, :oos, :pre, :pre_max, :pre_total, :weight, :image, :popular, :best, :seasonal, :active, :deleted, :brand, :tags)'
);
$insLegatura = $db->prepare('INSERT IGNORE INTO product_category_links (product_id, category_id) VALUES (:p, :c)');
$produse = [];
foreach ($produseDemo as $i => [$nume, $categorie, $pret, $pretRedus, $stoc, $marca]) {
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(strtr($nume, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ț' => 't', 'Ș' => 's']))), '-');
    $sku = sprintf('DEMO-%03d', $i + 1);
    $bbdJson = null;
    if (in_array($i, $bbd, true)) {
        $bbdJson = json_encode([
            ['date' => date('Y-m-d', $acum + 45 * $zi), 'lot' => 'L' . (100 + $i), 'reduced_price' => round($pret * 0.6, 2), 'stock' => 8],
            ['date' => date('Y-m-d', $acum + 120 * $zi), 'lot' => 'L' . (200 + $i), 'reduced_price' => round($pret * 0.8, 2), 'stock' => 15],
        ], JSON_UNESCAPED_UNICODE);
    }
    $estePrecomanda = in_array($i, $precomanda, true);
    $insProdus->execute([
        'name' => $nume,
        'sku' => $sku,
        'category' => $categorie,
        'category_id' => $idCategorie[$categorie],
        'tpl' => in_array($i, $faraSablon, true) ? null : $idSablon,
        'slug' => $slug,
        'short' => 'Produs inventat pentru testare: ' . mb_strtolower($nume) . '.',
        'descr' => '<p>' . $nume . ' este un produs <strong>fictiv</strong> din catalogul demo al Firma Demo SRL.</p><p>Textul servește doar la verificarea paginilor.</p>',
        'highlights' => "Produs demo\nIngrediente inventate\nAmbalaj de test",
        'price' => $pret,
        'sale' => $pretRedus,
        'bbd' => $bbdJson !== null ? 1 : 0,
        'bbd_json' => $bbdJson,
        'stock' => $estePrecomanda ? 0 : $stoc,
        'oos' => in_array($i, $epuizate, true) ? 1 : 0,
        'pre' => $estePrecomanda ? 1 : 0,
        'pre_max' => $estePrecomanda ? 2 : null,
        'pre_total' => $estePrecomanda ? 20 : null,
        'weight' => mt_rand(1, 10) * 100,
        'image' => $imagine($nume, $slug, $categorii[$categorie][0]),
        'popular' => $i % 7 === 0 ? 1 : 0,
        'best' => $i % 9 === 0 ? 1 : 0,
        'seasonal' => $i % 11 === 0 ? 1 : 0,
        'active' => in_array($i, $inactive, true) ? 0 : 1,
        'deleted' => in_array($i, $inCos, true) ? $dataSql($acum - 5 * $zi) : null,
        'brand' => $marca,
        'tags' => json_encode(array_values(array_filter([$pretRedus !== null ? 'reducere' : null, $estePrecomanda ? 'precomanda' : null, 'demo'])), JSON_UNESCAPED_UNICODE),
    ]);
    $id = (int) $db->lastInsertId();
    $insLegatura->execute(['p' => $id, 'c' => $idCategorie[$categorie]]);
    $produse[$i] = [
        'id' => $id,
        'name' => $nume,
        'price' => $pretRedus ?? $pret,
        'activ' => !in_array($i, $inactive, true) && !in_array($i, $inCos, true),
        'bbd' => $bbdJson !== null ? json_decode($bbdJson, true) : null,
        'precomanda' => $estePrecomanda,
    ];
}
// Produsele din seed.sql (3) primesc și ele legătura de categorie.
$db->exec('INSERT IGNORE INTO product_category_links (product_id, category_id) SELECT id, category_id FROM products WHERE category_id IS NOT NULL');
// WP-19 (C8): fiecare produs are cheia linkului de ciornă, ca după migrarea catalog/0026 pe un site (un produs fără cheie o
// primește la prima salvare din admin, ceea ce ar face salvările comparate cu Bioscem să difere). Pe o bază făcută ca
// înainte de migrări (instaleaza.sh --pe-vechi) coloana nu există încă: o face și o completează migrarea.
try {
    $puneCheie = $db->prepare("UPDATE products SET preview_token = :cheie WHERE id = :id AND (preview_token IS NULL OR preview_token = '')");
    foreach ($db->query('SELECT id FROM products')->fetchAll(PDO::FETCH_COLUMN) as $idProdus) {
        $puneCheie->execute(['cheie' => bin2hex(random_bytes(16)), 'id' => (int) $idProdus]);
    }
} catch (Throwable) {
}

// --- Nomenclator FAN minim (localități reale, publice; lockere inventate) ------
$localitati = [
    ['Bucuresti', 'Bucuresti'], ['Bucuresti', 'Sector 1'], ['Bucuresti', 'Sector 3'], ['Bucuresti', 'Sector 6'],
    ['Cluj', 'Cluj-Napoca'], ['Cluj', 'Turda'], ['Iasi', 'Iasi'], ['Iasi', 'Pascani'], ['Timis', 'Timisoara'],
    ['Brasov', 'Brasov'], ['Constanta', 'Constanta'], ['Ilfov', 'Voluntari'], ['Prahova', 'Ploiesti'], ['Dolj', 'Craiova'],
];
$insLocalitate = $db->prepare('INSERT IGNORE INTO fan_localities (county, locality, county_norm, locality_norm, created_at, updated_at) VALUES (:c, :l, :cn, :ln, NOW(), NOW())');
foreach ($localitati as [$judet, $oras]) {
    $insLocalitate->execute(['c' => $judet, 'l' => $oras, 'cn' => FanNomenclator::normalizeaza($judet), 'ln' => FanNomenclator::normalizeaza($oras)]);
}
$insLocker = $db->prepare(
    'INSERT INTO fan_lockers (code, fan_id, name, county, locality, address, postcode, county_norm, locality_norm, active, created_at, updated_at)
     VALUES (:code, :fan_id, :name, :county, :locality, :address, :postcode, :cn, :ln, 1, NOW(), NOW())'
);
foreach ([
    ['DEMO01', 'FANbox Demo Centru', 'Bucuresti', 'Bucuresti', 'Strada Exemplului 10'],
    ['DEMO02', 'FANbox Demo Nord', 'Cluj', 'Cluj-Napoca', 'Bulevardul Test 5'],
    ['DEMO03', 'FANbox Demo Gară', 'Iasi', 'Iasi', 'Strada Probei 2'],
] as $nr => [$cod, $numeLocker, $judet, $oras, $adresa]) {
    $insLocker->execute([
        'code' => $cod, 'fan_id' => (string) (900000 + $nr), 'name' => $numeLocker, 'county' => $judet, 'locality' => $oras,
        'address' => $adresa, 'postcode' => '000000', 'cn' => FanNomenclator::normalizeaza($judet), 'ln' => FanNomenclator::normalizeaza($oras),
    ]);
}

$idLockerDemo = (int) $db->query("SELECT id FROM fan_lockers WHERE code = 'DEMO01'")->fetchColumn();

// --- Administratori în plus (rolurile parțiale) --------------------------------
echo "Clienți și administratori...\n";
$insAdmin = $db->prepare('INSERT IGNORE INTO admins (email, password_hash, role, roles_json) VALUES (:e, :h, :r, :j)');
foreach ([['magazin@exemplu.ro', 'administrator_magazin'], ['blog@exemplu.ro', 'administrator_blog'], ['emailuri@exemplu.ro', 'administrator_emailuri']] as [$email, $rol]) {
    $insAdmin->execute(['e' => $email, 'h' => $hashClienti, 'r' => $rol, 'j' => json_encode([$rol])]);
}

// --- Clienți ---------------------------------------------------------------------
$prenume = ['Andrei', 'Elena', 'Mihai', 'Ioana', 'Radu', 'Maria', 'Victor', 'Ana', 'Cristian', 'Daria', 'Paul', 'Irina', 'Sorin', 'Bianca', 'Tudor'];
$nume = ['Exemplu', 'Demo', 'Test', 'Proba', 'Model'];
$clienti = [];
$insUser = $db->prepare(
    'INSERT INTO users (first_name, last_name, email, phone, birth_date, gender, password_hash, loyalty_points, created_at)
     VALUES (:fn, :ln, :email, :phone, :birth, :gender, :hash, 0, :created)'
);
$insAdresa = $db->prepare(
    'INSERT INTO user_addresses (user_id, label, full_name, phone, street, street_no, address_line1, city, county, postcode, is_default)
     VALUES (:uid, "Acasă", :full, :phone, :street, :no, :line1, :city, :county, :postcode, 1)'
);
foreach ($prenume as $i => $fn) {
    $ln = $nume[$i % count($nume)];
    $email = strtolower($fn) . '.' . strtolower($ln) . '@exemplu.ro';
    [$judet, $oras] = $localitati[$i % count($localitati)];
    $telefon = sprintf('0700000%03d', $i + 1);
    $insUser->execute([
        'fn' => $fn, 'ln' => $ln, 'email' => $email, 'phone' => $telefon,
        'birth' => sprintf('19%02d-%02d-%02d', 70 + $i, ($i % 12) + 1, ($i % 27) + 1),
        'gender' => $i % 2 === 0 ? 'male' : 'female',
        'hash' => $hashClienti,
        'created' => $dataSql($acum - (200 - $i * 5) * $zi),
    ]);
    $uid = (int) $db->lastInsertId();
    $strada = 'Strada Exemplului';
    $insAdresa->execute([
        'uid' => $uid, 'full' => $fn . ' ' . $ln, 'phone' => $telefon, 'street' => $strada, 'no' => (string) ($i + 1),
        'line1' => $strada . ' nr. ' . ($i + 1), 'city' => $oras, 'county' => $judet, 'postcode' => '000000',
    ]);
    $clienti[] = ['id' => $uid, 'fn' => $fn, 'ln' => $ln, 'email' => $email, 'phone' => $telefon, 'city' => $oras, 'county' => $judet, 'line1' => $strada . ' nr. ' . ($i + 1)];
}

// --- Cupoane ---------------------------------------------------------------------
echo "Cupoane...\n";
$insCupon = $db->prepare(
    'INSERT INTO coupons (name, code, type, value, max_uses_total, is_active, starts_at, ends_at, is_unique, import_batch, used_at)
     VALUES (:name, :code, :type, :value, :max, :active, :starts, :ends, :unique, :batch, :used)'
);
foreach ([
    ['Reducere demo 10%', 'DEMO10', 'percent', 10, null, 1, null, null],
    ['Livrare demo 15 lei', 'LIVRARE15', 'fixed', 15, null, 1, null, null],
    ['Vara trecută (expirat)', 'VARA2026', 'percent', 15, null, 1, $dataSql($acum - 120 * $zi), $dataSql($acum - 30 * $zi)],
    ['Bun venit 5%', 'BUNVENIT', 'percent', 5, 100, 1, null, null],
    ['Cupon oprit', 'OPRIT20', 'percent', 20, null, 0, null, null],
] as [$numeCupon, $cod, $tip, $valoare, $max, $activ, $start, $sfarsit]) {
    $insCupon->execute(['name' => $numeCupon, 'code' => $cod, 'type' => $tip, 'value' => $valoare, 'max' => $max, 'active' => $activ,
        'starts' => $start, 'ends' => $sfarsit, 'unique' => 0, 'batch' => null, 'used' => null]);
}
for ($k = 1; $k <= 5; $k++) {
    $insCupon->execute(['name' => 'Cod unic demo', 'code' => sprintf('UNIC-DEMO-%04d', $k), 'type' => 'fixed', 'value' => 20, 'max' => 1,
        'active' => 1, 'starts' => null, 'ends' => null, 'unique' => 1, 'batch' => 'lot-demo-1', 'used' => $k === 1 ? $dataSql($acum - 3 * $zi) : null]);
}

// --- Produse promoționale ----------------------------------------------------------
$idPromo = [];
$insPromoProdus = $db->prepare('INSERT INTO promotional_products (name, notes, is_active, sort_order) VALUES (:n, :note, 1, :s)');
foreach (['Pliant de prezentare', 'Mostră cremă de mâini', 'Pix cu logo demo'] as $s => $numePromo) {
    $insPromoProdus->execute(['n' => $numePromo, 'note' => 'Obiect promoțional inventat.', 's' => $s + 1]);
    $idPromo[] = (int) $db->lastInsertId();
}

// --- Comenzi ---------------------------------------------------------------------
echo "Comenzi...\n";
$setari = Settings::all($db);
$statusuri = array_merge(
    array_fill(0, 25, 'completed'), array_fill(0, 10, 'processing'), array_fill(0, 6, 'pending'),
    array_fill(0, 4, 'pending_payment'), array_fill(0, 6, 'cancelled'), array_fill(0, 2, 'refunded'),
    array_fill(0, 3, 'returned'), array_fill(0, 2, 'failed'), array_fill(0, 2, 'completed')
); // 60; ultimele două ajung în coșul de gunoi
$metode = ['cod', 'cod', 'cod', 'euplatesc', 'btipay', 'cod', 'btipay', 'bank_transfer', 'euplatesc', 'cod', 'stripe', 'btipay'];
$produseActive = array_values(array_filter($produse, static fn (array $p): bool => $p['activ'] && !$p['precomanda'] && $p['bbd'] === null));
$produsePrecomanda = array_values(array_filter($produse, static fn (array $p): bool => $p['precomanda']));
$produseBbd = array_values(array_filter($produse, static fn (array $p): bool => $p['bbd'] !== null && $p['activ']));

$coloaneComanda = [
    'order_number', 'user_id', 'status', 'preorder_status', 'payment_method', 'payment_status', 'paid_at', 'paid_amount',
    'shipping_method', 'shipping_cost', 'discount_total', 'coupon_code', 'subtotal', 'total',
    'loyalty_points_awarded', 'loyalty_points_awarded_at',
    'billing_first_name', 'billing_last_name', 'billing_phone', 'billing_email', 'billing_address_line1', 'billing_city',
    'billing_county', 'billing_postcode', 'billing_is_company', 'billing_company_name', 'billing_company_tax_id',
    'billing_company_registration_no', 'shipping_same_as_billing',
    'fan_locker_id', 'fan_locker_name', 'fan_locker_address', 'fan_locker_city', 'fan_locker_county',
    'fan_awb', 'fan_tracking_url', 'fan_tracking_status', 'euplatesc_ep_id', 'stripe_session_id',
    'ad_source', 'ad_click_id', 'notes', 'admin_notes', 'terms_accepted_at', 'deleted_at', 'created_at', 'updated_at',
];
$insComanda = $db->prepare(
    'INSERT INTO orders (' . implode(', ', $coloaneComanda) . ') VALUES (:' . implode(', :', $coloaneComanda) . ')'
);
$insLinie = $db->prepare(
    'INSERT INTO order_items (order_id, product_id, product_name, bbd_key, quantity, unit_price, line_total, created_at)
     VALUES (:o, :p, :n, :bbd, :q, :u, :t, :c)'
);
$insPuncte = $db->prepare(
    'INSERT INTO loyalty_points_transactions (user_id, order_id, tx_type, points_delta, balance_after, reason, created_at)
     VALUES (:u, :o, :t, :d, :b, :r, :c)'
);
$insBt = $db->prepare(
    'INSERT INTO bt_ipay_transactions (order_id, kind, mode, bt_order_number, bt_order_id, amount_minor, currency,
        approved_minor, deposited_minor, state, authorized_at, deposited_at, created_at, updated_at)
     VALUES (:o, "order", "test", :nr, :btid, :suma, :moneda, :aprobat, :depus, :stare, :aut, :dep, :c, :c2)'
);
$insPromoComanda = $db->prepare('INSERT INTO order_promo_items (order_id, promo_product_id, name, quantity, created_at) VALUES (:o, :p, :n, 1, :c)');
$puncteClient = [];
$comenzi = [];
foreach ($statusuri as $n => $status) {
    $vechime = $n < 50 ? (int) round((59 - $n) * 2 + mt_rand(0, 23) / 24) : mt_rand(1, 60);
    if ($status === 'processing' || $status === 'pending' || $status === 'pending_payment') {
        $vechime = mt_rand(0, 2); // comenzile în lucru sunt recente
    }
    $creata = $acum - $vechime * $zi - mt_rand(0, 20000);
    $metoda = $metode[$n % count($metode)];
    if ($status === 'pending_payment' || $status === 'failed') {
        $metoda = $alege(['euplatesc', 'btipay']);
    }
    $client = $n % 3 === 2 ? null : $clienti[$n % count($clienti)];
    $fn = $client['fn'] ?? 'Vizitator';
    $ln = $client['ln'] ?? ('Demo ' . ($n + 1));
    $email = $client['email'] ?? sprintf('vizitator%02d@exemplu.ro', $n + 1);
    [$judet, $oras] = $client !== null ? [$client['county'], $client['city']] : $localitati[$n % count($localitati)];

    // Liniile comenzii.
    $linii = [];
    $nrLinii = mt_rand(1, 4);
    for ($l = 0; $l < $nrLinii; $l++) {
        $p = $produseActive[mt_rand(0, count($produseActive) - 1)];
        $linii[$p['id']] = ['p' => $p, 'q' => mt_rand(1, 3), 'bbd' => null, 'pret' => $p['price']];
    }
    $preorderStatus = null;
    if ($n === 8 || $n === 9) { // două precomenzi care așteaptă butonul din admin
        $p = $produsePrecomanda[$n - 8];
        $linii[$p['id']] = ['p' => $p, 'q' => 1, 'bbd' => null, 'pret' => $p['price']];
        $preorderStatus = Precomanda::ASTEPTARE;
    }
    if (in_array($n, [3, 14, 27], true) && $produseBbd !== []) { // oferte BBD
        $p = $produseBbd[$n % count($produseBbd)];
        $lot = $p['bbd'][0];
        $cheie = substr(sha1($lot['date'] . '|' . $lot['lot'] . '|' . number_format((float) $lot['reduced_price'], 2, '.', '')), 0, 20);
        $linii['bbd' . $p['id']] = ['p' => $p, 'q' => 1, 'bbd' => $cheie, 'pret' => (float) $lot['reduced_price']];
    }
    $subtotal = 0.0;
    foreach ($linii as $linie) {
        $subtotal += round($linie['pret'] * $linie['q'], 2);
    }
    $cupon = in_array($n, [2, 11, 20, 33], true) ? 'DEMO10' : null;
    $reducere = $cupon !== null ? round($subtotal * 0.10, 2) : 0.0;
    $transport = $subtotal - $reducere >= 200 ? 0.0 : 15.0;
    $total = round($subtotal - $reducere + $transport, 2);
    $fanbox = $n % 5 === 4;
    $platita = in_array($status, ['completed', 'returned'], true)
        || ($status === 'processing' && $metoda !== 'cod' && $metoda !== 'bank_transfer')
        || $status === 'refunded';
    $platitStatus = match (true) {
        $status === 'refunded' => 'refunded',
        $status === 'failed' => 'failed',
        $status === 'pending_payment' => 'pending',
        $platita => 'paid',
        default => 'unpaid',
    };
    $awb = in_array($status, ['completed', 'returned'], true) || ($status === 'processing' && $n % 2 === 0);
    // Cu numerotarea veche (order_number_mode „legacy”, de exemplu la pachetul vertij pe copia demonstrativă) numărul are
    // data, secunda și 3 cifre la întâmplare: zeci de comenzi scrise în aceeași secundă se pot nimeri pe același număr.
    do {
        $numar = OrderNumber::urmatorul($db, $setari);
    } while (in_array($numar, array_column($comenzi, 'numar'), true));
    $firma = $n % 10 === 6;
    $insComanda->execute([
        'order_number' => $numar,
        'user_id' => $client['id'] ?? null,
        'status' => $status,
        'preorder_status' => $preorderStatus,
        'payment_method' => $metoda,
        'payment_status' => $platitStatus,
        'paid_at' => $platita ? $dataSql($creata + 600) : null,
        'paid_amount' => $platita ? $total : 0,
        'shipping_method' => $fanbox ? 'fan_box' : 'fan_courier',
        'shipping_cost' => $transport,
        'discount_total' => $reducere,
        'coupon_code' => $cupon,
        'subtotal' => round($subtotal, 2),
        'total' => $total,
        'loyalty_points_awarded' => 0,
        'loyalty_points_awarded_at' => null,
        'billing_first_name' => $fn,
        'billing_last_name' => $ln,
        'billing_phone' => $client['phone'] ?? sprintf('0700001%03d', $n + 1),
        'billing_email' => $email,
        'billing_address_line1' => $client['line1'] ?? ('Strada Probei nr. ' . ($n + 1)),
        'billing_city' => $oras,
        'billing_county' => $judet,
        'billing_postcode' => '000000',
        'billing_is_company' => $firma ? 1 : 0,
        'billing_company_name' => $firma ? 'Firma Demo SRL' : null,
        'billing_company_tax_id' => $firma ? 'RO00000000' : null,
        'billing_company_registration_no' => $firma ? 'J00/000/2026' : null,
        'shipping_same_as_billing' => 1,
        'fan_locker_id' => $fanbox ? $idLockerDemo : null,
        'fan_locker_name' => $fanbox ? 'FANbox Demo Centru' : null,
        'fan_locker_address' => $fanbox ? 'Strada Exemplului 10' : null,
        'fan_locker_city' => $fanbox ? 'Bucuresti' : null,
        'fan_locker_county' => $fanbox ? 'Bucuresti' : null,
        'fan_awb' => $awb ? sprintf('DEMO%08d', 1000 + $n) : null,
        'fan_tracking_url' => null,
        'fan_tracking_status' => $awb ? ($status === 'processing' ? 'În tranzit (demo)' : 'Livrat (demo)') : null,
        'euplatesc_ep_id' => $metoda === 'euplatesc' && $platita ? sprintf('DEMOEP%06d', $n) : null,
        'stripe_session_id' => $metoda === 'stripe' && $platita ? sprintf('cs_test_demo%06d', $n) : null,
        'ad_source' => $n % 8 === 1 ? 'google' : null,
        'ad_click_id' => $n % 8 === 1 ? 'demo-gclid-' . $n : null,
        'notes' => $n % 6 === 0 ? 'Vă rog sunați înainte de livrare (notă demo).' : null,
        'admin_notes' => $n % 9 === 0 ? 'Notă internă demo.' : null,
        'terms_accepted_at' => $dataSql($creata),
        'deleted_at' => $n >= 58 ? $dataSql($acum - $zi) : null,
        'created_at' => $dataSql($creata),
        'updated_at' => $dataSql($creata + 3600),
    ]);
    $idComanda = (int) $db->lastInsertId();
    foreach ($linii as $linie) {
        $insLinie->execute([
            'o' => $idComanda, 'p' => $linie['p']['id'], 'n' => $linie['p']['name'], 'bbd' => $linie['bbd'], 'q' => $linie['q'],
            'u' => $linie['pret'], 't' => round($linie['pret'] * $linie['q'], 2), 'c' => $dataSql($creata),
        ]);
    }
    if ($n % 7 === 3) {
        $insPromoComanda->execute(['o' => $idComanda, 'p' => $idPromo[$n % count($idPromo)], 'n' => 'Pliant de prezentare', 'c' => $dataSql($creata)]);
    }
    if ($metoda === 'btipay' && in_array($status, ['completed', 'processing', 'returned'], true)) {
        $suma = (int) round($total * 100);
        $depusa = $status !== 'processing';
        $insBt->execute([
            'o' => $idComanda, 'nr' => $numar, 'btid' => 'demo-bt-' . $idComanda, 'suma' => $suma, 'moneda' => 946,
            'aprobat' => $suma, 'depus' => $depusa ? $suma : 0, 'stare' => $depusa ? 'deposited' : 'authorized',
            'aut' => $dataSql($creata + 300), 'dep' => $depusa ? $dataSql($creata + 2 * $zi) : null,
            'c' => $dataSql($creata), 'c2' => $dataSql($creata + 300),
        ]);
    }
    // Puncte de fidelitate pentru comenzile finalizate ale clienților cu cont.
    if ($status === 'completed' && $client !== null && $n < 58) {
        $puncte = (int) floor($total);
        $sold = ($puncteClient[$client['id']] ?? 0) + $puncte;
        $puncteClient[$client['id']] = $sold;
        $db->prepare('UPDATE orders SET loyalty_points_awarded = :p, loyalty_points_awarded_at = :c WHERE id = :id')
            ->execute(['p' => $puncte, 'c' => $dataSql($creata + 3 * $zi), 'id' => $idComanda]);
        $insPuncte->execute(['u' => $client['id'], 'o' => $idComanda, 't' => LoyaltyService::TX_ORDER_EARN, 'd' => $puncte,
            'b' => $sold, 'r' => 'Puncte pentru comanda ' . $numar, 'c' => $dataSql($creata + 3 * $zi)]);
    }
    $comenzi[] = ['id' => $idComanda, 'numar' => $numar, 'status' => $status, 'total' => $total];
}
// O ajustare manuală și soldul final de puncte pe fiecare client.
if ($clienti !== []) {
    $c0 = $clienti[0]['id'];
    $sold = ($puncteClient[$c0] ?? 0) + 50;
    $puncteClient[$c0] = $sold;
    $insPuncte->execute(['u' => $c0, 'o' => null, 't' => LoyaltyService::TX_ADMIN_ADJUST, 'd' => 50, 'b' => $sold,
        'r' => 'Bonus demo acordat din admin', 'c' => $dataSql($acum - 2 * $zi)]);
}
$actPuncte = $db->prepare('UPDATE users SET loyalty_points = :p WHERE id = :id');
foreach ($puncteClient as $uid => $sold) {
    $actPuncte->execute(['p' => $sold, 'id' => $uid]);
}
$db->prepare(
    'INSERT INTO loyalty_points_rules (name, description, multiplier, min_order_total, category_id, is_active)
     VALUES ("Dublu la miere (demo)", "Regulă inventată pentru test.", 2.00, NULL, :c, 1)'
)->execute(['c' => $idCategorie['Miere']]);

// Un link de plată deschis pentru o comandă în procesare.
foreach ($comenzi as $comanda) {
    if ($comanda['status'] === 'processing') {
        $db->prepare(
            'INSERT INTO order_payment_links (order_id, referinta, token, amount, status, procesator, created_at, expires_at)
             VALUES (:o, :r, :t, 25.00, "pending", "euplatesc", :c, :e)'
        )->execute(['o' => $comanda['id'], 'r' => $comanda['numar'] . '-D1', 't' => bin2hex(random_bytes(16)),
            'c' => $dataSql($acum - 3600), 'e' => $dataSql($acum + 30 * $zi)]);
        break;
    }
}

// --- Coșuri abandonate ----------------------------------------------------------
$insCos = $db->prepare(
    'INSERT INTO cart_abandonments (session_id, email, customer_name, cart_snapshot, last_seen_at, converted_at, abandoned_email_sent_at)
     VALUES (:s, :e, :n, :snap, :seen, NULL, :sent)'
);
foreach ([1, 2, 3] as $k) {
    $p = $produseActive[$k];
    $insCos->execute([
        's' => 'demo-sesiune-' . $k, 'e' => sprintf('cos%02d@exemplu.ro', $k), 'n' => 'Vizitator Coș ' . $k,
        'snap' => json_encode(['items' => [['product_id' => $p['id'], 'name' => $p['name'], 'quantity' => 1, 'price' => $p['price']]], 'total' => $p['price']], JSON_UNESCAPED_UNICODE),
        'seen' => $dataSql($acum - $k * 7200), 'sent' => $k === 3 ? $dataSql($acum - 3600) : null,
    ]);
}

// --- Recenzii ----------------------------------------------------------------------
echo "Recenzii, blog, newsletter, pagini...\n";
$insRecenzie = $db->prepare(
    // Cu sursa scrisă, ca formularul de pe produs (SiteController): pe o bază la zi nimeni n-o mai completează după.
    'INSERT INTO product_reviews (product_id, user_name, user_email, rating, review_text, source, is_approved, created_at)
     VALUES (:p, :n, :e, :r, :t, \'product_page\', :a, :c)'
);
$texte = ['Foarte bun, recomand (recenzie demo).', 'Exact cum era descris.', 'Ambalaj frumos, livrare rapidă.', 'Bun, dar aș vrea un gramaj mai mare.', 'Al doilea cumpărat, la fel de bun.'];
for ($k = 0; $k < 25; $k++) {
    $p = $produseActive[$k % count($produseActive)];
    $insRecenzie->execute([
        'p' => $p['id'], 'n' => 'Client Exemplu ' . ($k + 1), 'e' => sprintf('recenzie%02d@exemplu.ro', $k + 1),
        'r' => [5, 4, 5, 3, 5][$k % 5], 't' => $texte[$k % count($texte)], 'a' => $k < 20 ? 1 : 0,
        'c' => $dataSql($acum - (60 - $k * 2) * $zi),
    ]);
}

// --- Blog --------------------------------------------------------------------------
$db->exec("INSERT INTO blog_authors (name, slug, bio, is_active) VALUES
    ('Echipa Demo', 'echipa-demo', 'Autor inventat pentru test.', 1),
    ('Ana Exemplu', 'ana-exemplu', 'Autoare inventată pentru test.', 1)");
$idAutor = (int) $db->query("SELECT id FROM blog_authors WHERE slug = 'echipa-demo'")->fetchColumn();
// Categoriile articolelor: „Noutăți” și „Evenimente” din seed-ul neutru al instalării (dacă lipsesc, de
// exemplu pe o bază făcută doar de migrări, se pun aici) și una a datelor demo.
$db->exec("INSERT IGNORE INTO blog_categories (name, slug, sort_order) VALUES
    ('Noutăți', 'noutati', 1), ('Evenimente', 'evenimente', 2), ('Ghiduri demo', 'ghiduri-demo', 5)");
$catBlog = [];
foreach ($db->query('SELECT id, slug FROM blog_categories')->fetchAll() as $rand) {
    $catBlog[(string) $rand['slug']] = (int) $rand['id'];
}
$insArticol = $db->prepare(
    'INSERT INTO blog_posts (title, slug, excerpt, category, category_id, event_start_date, event_end_date, event_price,
        event_location, content, reading_minutes, published_at, is_published, author_id, featured_image_url)
     VALUES (:t, :s, :ex, :cat, :cat_id, :es, :ee, :ep, :el, :c, 3, :pub, :is_pub, :a, :img)'
);
$insArtCat = $db->prepare('INSERT IGNORE INTO blog_post_categories (post_id, category_id) VALUES (:p, :c)');
foreach ([
    ['Cum alegi un ceai pentru seară', 'noutati', 1, null],
    ['Cinci rețete simple cu semințe de chia', 'noutati', 1, null],
    ['Ce înseamnă „presat la rece”', 'ghiduri-demo', 1, null],
    ['Ghid demo: păstrarea mierii', 'noutati', 1, null],
    ['Întrebări frecvente despre livrare', 'ghiduri-demo', 1, null],
    ['Noutăți în catalogul demo', 'noutati', 1, null],
    ['Atelier demo de degustare', 'evenimente', 1, [$acum + 20 * $zi, $acum + 20 * $zi, 'Gratuit', 'București, Sala Exemplu']],
    ['Articol în lucru (ciornă)', 'noutati', 0, null],
] as $k => [$titlu, $catSlug, $publicat, $eveniment]) {
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(strtr($titlu, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ț' => 't', 'Î' => 'i', 'Ș' => 's', 'Ă' => 'a', '„' => '', '”' => '']))), '-');
    $insArticol->execute([
        't' => $titlu, 's' => $slug, 'ex' => 'Rezumat inventat pentru articolul „' . $titlu . '”.',
        'cat' => $catSlug, 'cat_id' => $catBlog[$catSlug] ?? null,
        'es' => $eveniment !== null ? date('Y-m-d', $eveniment[0]) : null,
        'ee' => $eveniment !== null ? date('Y-m-d', $eveniment[1]) : null,
        'ep' => $eveniment[2] ?? null, 'el' => $eveniment[3] ?? null,
        'c' => '<p>Text demo pentru „' . $titlu . '”. Conținutul este inventat și servește doar la verificarea blogului.</p><h2>Secțiune</h2><p>Încă un paragraf de test.</p>',
        'pub' => $dataSql($acum - (40 - $k * 5) * $zi), 'is_pub' => $publicat, 'a' => $idAutor,
        'img' => $imagine($titlu, 'blog-' . $slug, [60 + $k * 20, 110, 150 - $k * 10]),
    ]);
    $idArticol = (int) $db->lastInsertId();
    if (isset($catBlog[$catSlug])) {
        $insArtCat->execute(['p' => $idArticol, 'c' => $catBlog[$catSlug]]);
    }
}
// Un articol importat cu categoria doar ca text (fără category_id și fără rânduri în blog_post_categories), cu
// un nume care nu e al niciunei categorii: editorul îl arată și îl păstrează la salvare (WP-12, X1b; compara-cu-baza.php).
$insArticol->execute([
    't' => 'Sfaturi vechi, importate', 's' => 'sfaturi-vechi-importate', 'ex' => 'Rezumat inventat pentru un articol importat.',
    'cat' => 'Sfaturi din arhivă', 'cat_id' => null, 'es' => null, 'ee' => null, 'ep' => null, 'el' => null,
    'c' => '<p>Text demo al unui articol importat: categoria lui a venit doar ca text.</p>',
    'pub' => $dataSql($acum - 60 * $zi), 'is_pub' => 1, 'a' => $idAutor,
    'img' => $imagine('Sfaturi vechi', 'blog-sfaturi-vechi-importate', [90, 120, 110]),
]);

// --- Newsletter --------------------------------------------------------------------
$db->exec("INSERT INTO newsletter_lists (name, description, is_default) VALUES
    ('Abonați site', 'Listă demo', 1), ('Clienți fideli', 'Listă demo', 0)");
$liste = $db->query('SELECT id FROM newsletter_lists ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$insAbonat = $db->prepare('INSERT INTO newsletter_subscribers (email, name, status, created_at) VALUES (:e, :n, :s, :c)');
$insListaAbonat = $db->prepare('INSERT IGNORE INTO newsletter_list_subscribers (list_id, subscriber_id) VALUES (:l, :s)');
for ($k = 1; $k <= 30; $k++) {
    $insAbonat->execute(['e' => sprintf('abonat%02d@exemplu.ro', $k), 'n' => 'Abonat Demo ' . $k,
        's' => $k > 27 ? 'unsubscribed' : 'active', 'c' => $dataSql($acum - (90 - $k) * $zi)]);
    $idAbonat = (int) $db->lastInsertId();
    $insListaAbonat->execute(['l' => (int) $liste[0], 's' => $idAbonat]);
    if ($k % 3 === 0) {
        $insListaAbonat->execute(['l' => (int) $liste[1], 's' => $idAbonat]);
    }
}
$db->prepare(
    'INSERT INTO newsletter_campaigns (name, template_type, subscriber_list_id, subject, html_content, status, total_recipients, total_sent, sent_at, created_at)
     VALUES (:n, "newsletter", :l, :s, :h, :st, :tr, :ts, :sent, :c)'
)->execute(['n' => 'Campanie demo trimisă', 'l' => (int) $liste[0], 's' => 'Noutăți demo', 'h' => '<p>Newsletter inventat.</p>',
    'st' => 'sent', 'tr' => 27, 'ts' => 27, 'sent' => $dataSql($acum - 10 * $zi), 'c' => $dataSql($acum - 11 * $zi)]);
$db->prepare(
    'INSERT INTO newsletter_campaigns (name, template_type, subscriber_list_id, subject, html_content, status, created_at)
     VALUES ("Campanie demo (ciornă)", "newsletter", :l, "Ciornă demo", "<p>Ciornă.</p>", "draft", :c)'
)->execute(['l' => (int) $liste[0], 'c' => $dataSql($acum - $zi)]);

// --- Mesaje din formularul de contact ----------------------------------------------
$insMesaj = $db->prepare(
    'INSERT INTO contact_form_messages (name, email, phone, subject, message, status, source_url, ip_address, user_agent, created_at)
     VALUES (:n, :e, :p, :s, :m, :st, "/contact", "127.0.0.1", "demo", :c)'
);
foreach ([['Mesaj Demo', 'Întrebare despre livrare'], ['Exemplu Client', 'Disponibilitate produs'], ['Test Persoană', 'Colaborare']] as $k => [$numeMesaj, $subiect]) {
    $insMesaj->execute(['n' => $numeMesaj, 'e' => sprintf('mesaj%02d@exemplu.ro', $k + 1), 'p' => '0700000999', 's' => $subiect,
        'm' => 'Mesaj inventat pentru test.', 'st' => $k === 0 ? 'new' : 'read', 'c' => $dataSql($acum - $k * $zi)]);
}

// --- Pagini (cu tokenurile folosite de un site real) --------------------------------
$pagini = [
    ['Acasă', '', '<section class="demo-acasa"><h1>Firma Demo</h1><p>Site de dezvoltare cu date inventate.</p>'
        . '<h2>Produse recomandate</h2>{{featured_products:8}}<h2>Categorii</h2>{{product_categories:6}}<h2>Din blog</h2>{{latest_posts:3}}</section>'],
    ['Magazin', 'magazin', '<section class="demo-magazin"><h1>{{shop_title}}</h1>{{shop_category}}{{shop_catalog}}</section>'],
    ['Coș', 'cos', '<section class="demo-cos"><h1>Coșul tău</h1>{{cart_form}}</section>'],
    ['Checkout', 'checkout', '<div class="checkout-page-dynamic">{{checkout_form}}</div>'],
    ['Comandă plasată', 'checkout/succes', '<section><h1>Mulțumim pentru comandă!</h1>{{checkout_success_order_info}}</section>'],
    ['Contul meu', 'contul-meu', '<div class="account-page-dynamic">{{account_section}}</div>'],
    ['Blog', 'blog', '<section><h1>Blog demo</h1>{{blog_posts}}</section>'],
    ['Pagina 404', '404', '<section style="text-align:center;padding:40px 20px;"><h1>404</h1><p>Pagina nu există.</p><p><a href="/">Înapoi la Acasă</a></p></section>'],
    ['Termeni și condiții', 'termeni-si-conditii', '<h1>Termeni și condiții</h1><p>Text inventat al Firma Demo SRL.</p>'],
    ['Politica de confidențialitate', 'politica-de-confidentialitate', '<h1>Politica de confidențialitate</h1><p>Text inventat al Firma Demo SRL.</p>'],
    ['Politica cookie', 'politica-cookie', '<h1>Politica cookie</h1><p>Text inventat.</p>'],
    ['Returnare și garanție', 'returnare-si-garantie', '<h1>Returnare și garanție</h1><p>Text inventat.</p>'],
];
$insPagina = $db->prepare(
    'INSERT INTO pages (title, slug, html_content, css_content, js_content, is_published)
     VALUES (:t, :s, :h, "", "", 1)
     ON DUPLICATE KEY UPDATE title = VALUES(title), html_content = VALUES(html_content), is_published = 1, deleted_at = NULL'
);
foreach ($pagini as [$titlu, $slug, $html]) {
    $insPagina->execute(['t' => $titlu, 's' => $slug, 'h' => $html]);
}

$sumar = [
    'produse' => (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn(),
    'categorii' => (int) $db->query('SELECT COUNT(*) FROM product_categories')->fetchColumn(),
    'clienți' => (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'comenzi' => (int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    'cupoane' => (int) $db->query('SELECT COUNT(*) FROM coupons')->fetchColumn(),
    'recenzii' => (int) $db->query('SELECT COUNT(*) FROM product_reviews')->fetchColumn(),
    'articole' => (int) $db->query('SELECT COUNT(*) FROM blog_posts')->fetchColumn(),
    'abonați' => (int) $db->query('SELECT COUNT(*) FROM newsletter_subscribers')->fetchColumn(),
    'tranzacții puncte' => (int) $db->query('SELECT COUNT(*) FROM loyalty_points_transactions')->fetchColumn(),
];
foreach ($sumar as $ce => $cate) {
    // Ca mb_str_pad (abia din PHP 8.3); scris când PHP-ul minim era 8.0.
    echo '  ' . $ce . str_repeat(' ', max(0, 20 - mb_strlen($ce))) . $cate . "\n";
}
echo "Date demo gata. Clienții demo au aceeași parolă ca adminul de test.\n";
