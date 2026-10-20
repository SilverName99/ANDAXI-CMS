<?php

declare(strict_types=1);

/**
 * Verificările comune tuturor seturilor (WP-10; planul Etapei 1, §5 WP-10 „Added to every
 * site set”): codul de pe site (G1), parolele adminilor (G-M5, G4), comenzile netrimise în
 * ERP pe vârste (G-M8, decide OD-50), fișierele din storage/import (G-m9), conturile pe al
 * căror email stau comenzi fără cont (decide câți clienți atinge OD-47), cheile secrete și
 * .env-ul (doar DA/NU; G3), ce pluginuri folosește site-ul (OD-23, OD-33), paginile și
 * tokenurile lor, galeria (BS-V3), emailurile eșuate (G6) și, la urmă, clasele CSS din
 * conținutul bazei (V-CSS, G-M3).
 *
 * Întoarce `grupuri` (puse de fiecare set înaintea celor proprii) și `la_final` (după ele).
 * Fiecare grup: cheie (prefixul rândurilor lui), titlu și funcția care scrie rândurile prin
 * Context (doar numere, DA/NU, valori din liste fixe, nume de structură). Un tabel sau o
 * coloană care lipsește pe site dă „LIPSĂ”, nu o eroare. Descrierile sunt texte fixe.
 */

use Unelte\Verificare\ClaseCss;
use Unelte\Verificare\Context;

/** Interogările cu „în ultimele N zile” (created_at), pentru coloanele care există. */
$inUltimele = static fn (int $zile, string $coloana = 'created_at'): string => $coloana . ' >= NOW() - INTERVAL ' . $zile . ' DAY';

/** Rândurile de comenzi / produse / pagini „nesterse”, dacă tabelul are deleted_at. */
$nesters = static fn (Context $c, string $tabel): string => $c->are([$tabel . '.deleted_at']) ? 'deleted_at IS NULL' : '1 = 1';

return [
    'grupuri' => [
    [
        'cheie' => 'cod',
        'titlu' => 'Codul de pe site (G1): ce commit rulează și amprenta câtorva fișiere de cod',
        'ruleaza' => static function (Context $c): void {
            $cod = $c->site->cod();
            $c->raport->daNu('cod.git', $cod['git'], 'deploy din git (hPanel → GIT)');
            if ($cod['ramura'] !== null) {
                $c->raport->nume('cod.ramura', $cod['ramura'], 'ref');
            }
            if ($cod['commit'] !== null) {
                $c->raport->nume('cod.commit', $cod['commit'], 'hex', 'îl comparăm cu repo-ul');
            }
            foreach ($cod['fisiere'] as $fisier => $amprenta) {
                if ($amprenta === null) {
                    $c->raport->alegere('cod.sha256.' . $fisier, 'lipsește', ['lipsește']);
                } else {
                    $c->raport->nume('cod.sha256.' . $fisier, $amprenta, 'hex', 'primele 16 cifre hex ale sha256');
                }
            }
        },
    ],
    [
        'cheie' => 'admini',
        'titlu' => 'Parolele adminilor (G-M5, G4): ce fel de valoare e în coloana parolei (numărate în SQL, necitite)',
        'ruleaza' => static function (Context $c): void {
            if (!$c->cere('admini', ['admins'])) {
                return;
            }
            $coloana = $c->are(['admins.password_hash']) ? 'password_hash' : ($c->are(['admins.password']) ? 'password' : null);
            if ($coloana === null) {
                $c->raport->lipsa('admini', 'coloana admins.password_hash');
                return;
            }
            $p = '`' . $coloana . '`';
            $bcrypt = '(CAST(LEFT(' . $p . ', 4) AS BINARY) = CAST(\'$2y$\' AS BINARY) AND CHAR_LENGTH(' . $p . ') = 60)';
            $argon = '(CAST(LEFT(' . $p . ', 9) AS BINARY) = CAST(\'$argon2i$\' AS BINARY) OR CAST(LEFT(' . $p . ', 10) AS BINARY) = CAST(\'$argon2id$\' AS BINARY))';
            $vechi = '(CAST(LEFT(' . $p . ', 4) AS BINARY) IN (CAST(\'$2a$\' AS BINARY), CAST(\'$2b$\' AS BINARY)) AND CHAR_LENGTH(' . $p . ') = 60)';
            $r = $c->citire->randuri('SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN ' . $bcrypt . ' OR ' . $argon . ' THEN 1 ELSE 0 END), 0) AS cunoscut,
                    COALESCE(SUM(CASE WHEN ' . $vechi . ' THEN 1 ELSE 0 END), 0) AS vechi,
                    COALESCE(SUM(CASE WHEN COALESCE(' . $p . ', \'\') = \'\' THEN 1 ELSE 0 END), 0) AS gol
                FROM admins LIMIT 1')[0];
            $total = (int) $r['total'];
            $c->raport->numar('admini.total', $total);
            $c->raport->numar('admini.parola_hash_cunoscut', (int) $r['cunoscut'], 'bcrypt $2y$ sau argon2: password_get_info îl recunoaște');
            $c->raport->numar('admini.parola_bcrypt_2a_2b', (int) $r['vechi'], 'bcrypt $2a$/$2b$: password_verify merge, password_get_info nu (de știut la WP-12b)');
            $c->raport->numar('admini.parola_goala', (int) $r['gol'], 'fără parolă');
            $c->raport->numar('admini.parola_necunoscuta', max(0, $total - (int) $r['cunoscut'] - (int) $r['vechi'] - (int) $r['gol']),
                'probabil text clar (Auth::attempt îl compară în clar); trebuie 0 înainte de MIG-11', 0);
        },
    ],
    [
        'cheie' => 'erp',
        'titlu' => 'Comenzile netrimise în ERP, pe vârste (G-M8; decide OD-50)',
        'ruleaza' => static function (Context $c): void {
            $s = $c->setari(['erp_enabled', 'erp_url', 'erp_trimite_din']);
            $c->raport->daNu('erp.pornit', $s['erp_enabled']['unu'], 'setarea erp_enabled = 1');
            $c->raport->daNu('erp.adresa_setata', $s['erp_url']['nevida'], 'setarea erp_url are o valoare');
            $c->raport->daNu('erp.podea_setata', $s['erp_trimite_din']['nevida'], 'erp_trimite_din (WP-12 E1; lipsește pe codul vechi)');
            if (!$c->cere('erp', ['orders.erp_status', 'orders.created_at', 'orders.status'])) {
                return;
            }
            $baza = $c->are(['orders.deleted_at']) ? 'deleted_at IS NULL' : '1 = 1';
            // Ce ar trimite cronul (ErpSync::retryPending), fără termenul reîncercării.
            $conditii = $baza . " AND erp_status IN ('pending', 'failed') AND status NOT IN ('cancelled', 'refunded', 'failed', 'returned')";
            if ($c->are(['orders.preorder_status'])) {
                $conditii .= " AND (preorder_status IS NULL OR preorder_status <> 'asteptare')";
            }
            if ($c->are(['orders.payment_method', 'orders.payment_status'])) {
                $conditii .= " AND (payment_method NOT IN ('stripe', 'euplatesc', 'btipay') OR payment_status = 'paid')";
            }
            if ($c->are(['orders.erp_attempts'])) {
                $conditii .= ' AND erp_attempts < 12';
            }
            $r = $c->citire->randuri('SELECT
                    COALESCE(SUM(CASE WHEN created_at >= NOW() - INTERVAL 7 DAY THEN 1 ELSE 0 END), 0) AS sub7,
                    COALESCE(SUM(CASE WHEN created_at < NOW() - INTERVAL 7 DAY AND created_at >= NOW() - INTERVAL 30 DAY THEN 1 ELSE 0 END), 0) AS d7,
                    COALESCE(SUM(CASE WHEN created_at < NOW() - INTERVAL 30 DAY AND created_at >= NOW() - INTERVAL 365 DAY THEN 1 ELSE 0 END), 0) AS d30,
                    COALESCE(SUM(CASE WHEN created_at < NOW() - INTERVAL 365 DAY THEN 1 ELSE 0 END), 0) AS d365
                FROM orders WHERE ' . $conditii . ' LIMIT 1')[0];
            $c->raport->numar('erp.de_trimis.sub_7_zile', (int) $r['sub7'], 'ar pleca la ERP la pornirea cronului');
            $c->raport->numar('erp.de_trimis.7_30_zile', (int) $r['d7']);
            $c->raport->numar('erp.de_trimis.30_365_zile', (int) $r['d30']);
            $c->raport->numar('erp.de_trimis.peste_1_an', (int) $r['d365']);
            $c->grupuri('erp.status', ['orders.erp_status'], 'SELECT erp_status AS g, COUNT(*) AS n FROM orders WHERE ' . $baza . ' GROUP BY erp_status LIMIT 50',
                [], 'comenzi pe starea ERP');
            $luna = $c->citire->valoare("SELECT DATE_FORMAT(MIN(created_at), '%Y-%m') FROM orders WHERE " . $baza . " AND erp_status = 'sent' LIMIT 1");
            $luna === null ? $c->raport->alegere('erp.trimisa_cea_mai_veche_comanda_luna', 'niciuna', ['niciuna'])
                : $c->raport->nume('erp.trimisa_cea_mai_veche_comanda_luna', (string) $luna, 'luna', 'luna celei mai vechi comenzi trimise');
            if ($c->are(['orders.erp_synced_at'])) {
                $luna = $c->citire->valoare("SELECT DATE_FORMAT(MIN(erp_synced_at), '%Y-%m') FROM orders WHERE erp_synced_at IS NOT NULL LIMIT 1");
                $luna === null ? $c->raport->alegere('erp.prima_sincronizare_luna', 'niciuna', ['niciuna'])
                    : $c->raport->nume('erp.prima_sincronizare_luna', (string) $luna, 'luna', 'de când trimite site-ul în ERP');
            }
        },
    ],
    [
        'cheie' => 'import',
        'titlu' => 'Fișierele din storage/import (G-m9): doar câte sunt, fără nume',
        'ruleaza' => static function (Context $c): void {
            $n = $c->site->numaraFisiere('storage/import');
            $n === null ? $c->raport->alegere('import.fisiere', 'dosarul lipsește', ['dosarul lipsește'])
                : $c->raport->numar('import.fisiere', $n, 'copii WordPress rămase (WP-23 le șterge după import)');
        },
    ],
    [
        'cheie' => 'comenzi_fara_cont',
        'titlu' => 'Comenzile fără cont pe emailul unui cont (decide câți clienți atinge OD-47)',
        'ruleaza' => static function (Context $c): void {
            if (!$c->cere('comenzi_fara_cont', ['orders.user_id', 'orders.billing_email', 'users.email'])) {
                return;
            }
            $o = $c->are(['orders.deleted_at']) ? 'o.deleted_at IS NULL' : '1 = 1';
            $c->numar('comenzi_fara_cont.total', [], 'SELECT COUNT(*) FROM orders o WHERE o.user_id IS NULL AND ' . $o . ' LIMIT 1');
            // IN (subinterogare), nu EXISTS corelat: ambele servere caută atunci după indexul unic users.email.
            $c->numar('comenzi_fara_cont.conturi_cu_acelasi_email', [], 'SELECT COUNT(*) FROM users u WHERE u.email IN (SELECT o.billing_email FROM orders o
                WHERE o.user_id IS NULL AND ' . $o . ') LIMIT 1', [], 'conturi care văd azi și comenzi fără cont');
            $c->numar('comenzi_fara_cont.pe_emailul_unui_cont', [], 'SELECT COUNT(*) FROM orders o WHERE o.user_id IS NULL AND ' . $o . '
                AND o.billing_email IN (SELECT u.email FROM users u) LIMIT 1', [], 'comenzi fără cont arătate unui cont');
        },
    ],
    [
        'cheie' => 'secrete',
        'titlu' => 'Cheile secrete și .env (G3): doar dacă au o valoare (DA/NU), niciodată valoarea',
        'ruleaza' => static function (Context $c): void {
            $chei = ['stripe_secret_key', 'stripe_webhook_secret', 'stripe_publishable_key', 'erp_api_key', 'euplatesc_secret_key',
                'smtp_password', 'sendgrid_api_key', 'fan_api_password', 'customer_google_client_secret', 'tawk_api_key', 'bunny_api_key',
                'bunny_storage_password'];
            foreach ($c->setari($chei) as $cheie => $s) {
                $c->raport->daNu('setare_cu_valoare.' . $cheie, $s['nevida']);
            }
            $nevide = $c->site->cheiEnvNevide();
            foreach (['BT_IPAY_TEST_USER', 'BT_IPAY_TEST_PASS', 'BT_IPAY_TEST_CALLBACK_KEY', 'BT_IPAY_LIVE_USER', 'BT_IPAY_LIVE_PASS',
                'BT_IPAY_LIVE_CALLBACK_KEY'] as $cheie) {
                $c->raport->daNu('env_cu_valoare.' . $cheie, in_array($cheie, $nevide, true));
            }
            foreach (['ADMIN_DEFAULT_PASSWORD' => 'parola de instalare rămasă în .env', 'FAN_API_BASE' => 'adresa FAN schimbată (doar în dezvoltare)',
                'BT_IPAY_BASE_URL_OVERRIDE' => 'adresa băncii schimbată (doar în dezvoltare)'] as $cheie => $descriere) {
                $c->raport->daNu('env_cu_valoare.' . $cheie, in_array($cheie, $nevide, true), $descriere, false);
            }
            $c->raport->alegere('env.BT_IPAY_MODE', $c->site->alegereEnv('BT_IPAY_MODE'), ['gol', 'alta', 'test', 'live']);
            $c->raport->alegere('env.APP_ENV', $c->site->alegereEnv('APP_ENV'), ['gol', 'alta', 'production', 'local', 'verificare', 'staging']);
            $c->raport->alegere('env.APP_DEBUG', $c->site->alegereEnv('APP_DEBUG'), ['gol', 'alta', 'true', 'false', '1', '0']);
            $c->raport->lista('env.chei_cu_valoare', $nevide, 'nume', 'doar numele cheilor din .env care au o valoare');
        },
    ],
    [
        'cheie' => 'pluginuri',
        'titlu' => 'Ce folosește site-ul (OD-23, OD-33): comutatoarele și activitatea pe 365 de zile, pe pluginuri',
        'ruleaza' => static function (Context $c) use ($inUltimele, $nesters): void {
            $comutatoare = ['stripe_enabled', 'euplatesc_enabled', 'bt_ipay_enabled', 'erp_stock_enabled', 'fan_live_tariff_enabled',
                'loyalty_points_enabled', 'mannequin_enabled', 'tawk_enabled', 'customer_google_auth_enabled', 'welcome_popup_enabled',
                'maintenance_enabled', 'presentation_mode_enabled', 'cookie_banner_enabled', 'cache_pages_enabled', 'google_analytics_enabled',
                'google_tag_manager_enabled', 'google_ads_enabled', 'microsoft_clarity_enabled', 'floating_cart_enabled', 'store_bbd_sidebar_enabled',
                'bunny_enabled'];
            foreach ($c->setari($comutatoare) as $cheie => $s) {
                $c->raport->alegere('comutator.' . $cheie, !$s['exista'] ? 'lipsește' : ($s['unu'] ? 'DA' : 'NU'), ['DA', 'NU', 'lipsește']);
            }
            $alte = $c->setari(['tawk_property_id', 'mannequin_points_json']);
            $c->raport->daNu('chat_live.cod_tawk_setat', $alte['tawk_property_id']['nevida']);
            $c->raport->daNu('manechin.puncte_in_setari', $alte['mannequin_points_json']['nevida']);
            $o = $nesters($c, 'orders');
            $c->numar('magazin.comenzi_total', ['orders'], 'SELECT COUNT(*) FROM orders WHERE ' . $o . ' LIMIT 1');
            $c->numar('magazin.comenzi_365_zile', ['orders.created_at'], 'SELECT COUNT(*) FROM orders WHERE ' . $o . ' AND ' . $inUltimele(365) . ' LIMIT 1');
            $c->grupuri('plati.comenzi_365_zile', ['orders.payment_method', 'orders.created_at'], 'SELECT payment_method AS g, COUNT(*) AS n FROM orders
                WHERE ' . $o . ' AND ' . $inUltimele(365) . ' GROUP BY payment_method LIMIT 100', [], 'comenzi pe metoda de plată');
            $c->numar('livrare_fan.awb_365_zile', ['orders.fan_awb', 'orders.created_at'], "SELECT COUNT(*) FROM orders WHERE " . $o . " AND " . $inUltimele(365)
                . " AND fan_awb IS NOT NULL AND fan_awb <> '' LIMIT 1");
            $c->numar('livrare_fan.fanbox_365_zile', ['orders.fan_locker_id', 'orders.created_at'], 'SELECT COUNT(*) FROM orders WHERE ' . $o . ' AND ' . $inUltimele(365)
                . ' AND fan_locker_id IS NOT NULL LIMIT 1');
            $c->numar('plata_bt.tranzactii_365_zile', ['bt_ipay_transactions.created_at'], 'SELECT COUNT(*) FROM bt_ipay_transactions WHERE ' . $inUltimele(365) . ' LIMIT 1');
            if ($c->are(['order_payment_links.procesator', 'order_payment_links.created_at'])) {
                $c->grupuri('link_plata.linkuri_365_zile', [], 'SELECT procesator AS g, COUNT(*) AS n FROM order_payment_links WHERE ' . $inUltimele(365)
                    . ' GROUP BY procesator LIMIT 50', [], 'linkuri de plată pe procesator');
            } else {
                $c->numar('link_plata.linkuri_365_zile', ['order_payment_links.created_at'], 'SELECT COUNT(*) FROM order_payment_links WHERE ' . $inUltimele(365) . ' LIMIT 1');
            }
            $c->numar('cupoane.active', ['coupons.is_active'], 'SELECT COUNT(*) FROM coupons WHERE is_active = 1 LIMIT 1');
            $c->numar('cupoane.unice', ['coupons.is_unique'], 'SELECT COUNT(*) FROM coupons WHERE is_unique = 1 LIMIT 1');
            $c->numar('cupoane.comenzi_365_zile', ['orders.coupon_code', 'orders.created_at'], "SELECT COUNT(*) FROM orders WHERE " . $o . " AND " . $inUltimele(365)
                . " AND coupon_code IS NOT NULL AND coupon_code <> '' LIMIT 1");
            $c->numar('fidelitate.miscari_365_zile', ['loyalty_points_transactions.created_at'], 'SELECT COUNT(*) FROM loyalty_points_transactions WHERE '
                . $inUltimele(365) . ' LIMIT 1');
            $c->numar('recenzii.total', ['product_reviews'], 'SELECT COUNT(*) FROM product_reviews LIMIT 1');
            $c->numar('recenzii.aprobate', ['product_reviews.is_approved'], 'SELECT COUNT(*) FROM product_reviews WHERE is_approved = 1 LIMIT 1');
            $c->numar('recenzii.365_zile', ['product_reviews.created_at'], 'SELECT COUNT(*) FROM product_reviews WHERE ' . $inUltimele(365) . ' LIMIT 1');
            $p = $nesters($c, 'products');
            $c->numar('precomenzi.produse', ['products.preorder_enabled'], 'SELECT COUNT(*) FROM products WHERE ' . $p . ' AND preorder_enabled = 1 LIMIT 1');
            $c->numar('oferte_bbd.produse', ['products.bbd_enabled'], 'SELECT COUNT(*) FROM products WHERE ' . $p . ' AND bbd_enabled = 1 LIMIT 1');
            $c->numar('produse_promo.active', ['promotional_products.is_active'], 'SELECT COUNT(*) FROM promotional_products WHERE is_active = 1 LIMIT 1');
            $c->numar('produse_promo.date_365_zile', ['order_promo_items.created_at'], 'SELECT COUNT(*) FROM order_promo_items WHERE ' . $inUltimele(365) . ' LIMIT 1');
            $c->numar('conturi_clienti.total', ['users'], 'SELECT COUNT(*) FROM users LIMIT 1');
            $c->numar('conturi_clienti.noi_365_zile', ['users.created_at'], 'SELECT COUNT(*) FROM users WHERE ' . $inUltimele(365) . ' LIMIT 1');
            $b = $nesters($c, 'blog_posts');
            $c->numar('blog.articole_publicate', ['blog_posts.is_published'], 'SELECT COUNT(*) FROM blog_posts WHERE ' . $b . ' AND is_published = 1 LIMIT 1');
            $c->numar('blog.articole_total', ['blog_posts'], 'SELECT COUNT(*) FROM blog_posts WHERE ' . $b . ' LIMIT 1');
            $c->grupuri('newsletter.abonati', ['newsletter_subscribers.status'], 'SELECT status AS g, COUNT(*) AS n FROM newsletter_subscribers GROUP BY status LIMIT 50',
                [], 'abonați pe stare');
            $c->numar('newsletter.campanii_trimise_365_zile', ['newsletter_campaigns.status', 'newsletter_campaigns.sent_at'],
                "SELECT COUNT(*) FROM newsletter_campaigns WHERE status = 'sent' AND " . $inUltimele(365, 'sent_at') . ' LIMIT 1');
            $c->numar('manechin.puncte_vechi', ['mannequin_points'], 'SELECT COUNT(*) FROM mannequin_points LIMIT 1', [], 'tabelul vechi mannequin_points');
            $c->numar('acorduri_gdpr.total', ['gdpr_agreements'], 'SELECT COUNT(*) FROM gdpr_agreements LIMIT 1', [], 'acorduri semnate (doar numărul)');
            $c->numar('acorduri_gdpr.365_zile', ['gdpr_agreements.created_at'], 'SELECT COUNT(*) FROM gdpr_agreements WHERE ' . $inUltimele(365) . ' LIMIT 1');
            $c->numar('acorduri_gdpr.pagina_publicata', ['pages.slug', 'pages.is_published'], "SELECT COUNT(*) FROM pages WHERE " . $nesters($c, 'pages')
                . " AND is_published = 1 AND slug = 'acorduri-gdpr' LIMIT 1");
            $c->numar('contact.mesaje_total', ['contact_form_messages'], 'SELECT COUNT(*) FROM contact_form_messages LIMIT 1');
            $c->numar('contact.mesaje_365_zile', ['contact_form_messages.created_at'], 'SELECT COUNT(*) FROM contact_form_messages WHERE ' . $inUltimele(365) . ' LIMIT 1');
        },
    ],
    [
        'cheie' => 'catalog',
        'titlu' => 'Produsele și galeria (BS-V3, G2)',
        'ruleaza' => static function (Context $c): void {
            if ($c->cere('produse', ['products'])) {
                $sters = $c->are(['products.deleted_at']);
                $r = $c->citire->randuri('SELECT COUNT(*) AS total,
                        COALESCE(SUM(CASE WHEN ' . ($sters ? 'deleted_at IS NULL AND ' : '') . 'is_active = 1 THEN 1 ELSE 0 END), 0) AS active,
                        COALESCE(SUM(CASE WHEN ' . ($sters ? 'deleted_at IS NULL AND ' : '') . 'is_active = 0 THEN 1 ELSE 0 END), 0) AS inactive,
                        ' . ($sters ? 'COALESCE(SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END), 0)' : '0') . ' AS sterse
                    FROM products LIMIT 1')[0];
                $c->raport->numar('produse.active', (int) $r['active']);
                $c->raport->numar('produse.inactive', (int) $r['inactive'], 'inactive, nu în coșul de gunoi (C8)');
                $c->raport->numar('produse.in_cosul_de_gunoi', (int) $r['sterse']);
            }
            $c->grupuri('galerie', ['gallery_images.media_type'], 'SELECT media_type AS g, COUNT(*) AS n FROM gallery_images GROUP BY media_type LIMIT 50',
                [], 'elemente din galerie pe fel (BS-V3, GR-04)');
        },
    ],
    [
        'cheie' => 'pagini',
        'titlu' => 'Paginile din bază: câte, slug-urile celor publicate, tokenurile folosite (G2, BS-V1)',
        'ruleaza' => static function (Context $c) use ($nesters): void {
            if (!$c->cere('pagini', ['pages.slug', 'pages.is_published', 'pages.html_content'])) {
                return;
            }
            $n = $nesters($c, 'pages');
            $c->numar('pagini.publicate', [], 'SELECT COUNT(*) FROM pages WHERE ' . $n . ' AND is_published = 1 LIMIT 1');
            $c->numar('pagini.nepublicate', [], 'SELECT COUNT(*) FROM pages WHERE ' . $n . ' AND is_published <> 1 LIMIT 1');
            if ($c->are(['pages.deleted_at'])) {
                $c->numar('pagini.in_cosul_de_gunoi', [], 'SELECT COUNT(*) FROM pages WHERE deleted_at IS NOT NULL LIMIT 1');
            }
            $sluguri = [];
            $nestandard = 0;
            foreach ($c->citire->randuri('SELECT slug AS s FROM pages WHERE ' . $n . ' AND is_published = 1 ORDER BY slug LIMIT 2000') as $r) {
                $slug = (string) $r['s'];
                if (\Unelte\Verificare\Raport::potrivit('slug', $slug)) {
                    $sluguri[] = $slug;
                } else {
                    $nestandard++;
                }
            }
            $c->raport->lista('pagini.sluguri_publicate', $sluguri, 'slug', 'adresele publice ale paginilor publicate');
            $c->raport->numar('pagini.sluguri_publicate_altfel', $nestandard, 'slug gol (pagina de start) sau cu alte caractere: doar numărate');
            foreach (['{{shop_catalog}}' => 'shop_catalog', '{{produse_serviciu}}' => 'produse_serviciu', '{{gdpr_agreements_form}}' => 'gdpr_agreements_form',
                '{{an}}' => 'an', '/assets/img/' => 'assets_img', '/documente/' => 'documente', 'data-bs-' => 'data_bs'] as $text => $nume) {
                $c->numar('pagini.cu_token.' . $nume, [], 'SELECT COUNT(*) FROM pages WHERE ' . $n . ' AND INSTR(html_content, ?) > 0 LIMIT 1', [$text]);
            }
        },
    ],
    [
        'cheie' => 'emailuri',
        'titlu' => 'Emailurile din ultimele 30 de zile (G6): câte au eșuat, fără destinatari',
        'ruleaza' => static function (Context $c) use ($inUltimele): void {
            $c->grupuri('emailuri.30_zile', ['email_send_history.status', 'email_send_history.created_at'], 'SELECT status AS g, COUNT(*) AS n FROM email_send_history
                WHERE ' . $inUltimele(30) . ' GROUP BY status LIMIT 50', [], 'emailuri pe stare');
        },
    ],
    ],
    // V-CSS la urmă: e lung (un rând pe clasă).
    'la_final' => [
    [
        'cheie' => 'css',
        'titlu' => 'Clasele CSS folosite de conținutul din bază (V-CSS, G-M3): o clasă folosită aici nu se scoate din foile de stil (WP-30, WP-40)',
        'ruleaza' => static function (Context $c): void {
            $etichete = ['pagini' => 'pagini', 'blog' => 'articole', 'produse' => 'produse', 'sabloane_produs' => 'șabloane de produs',
                'sabloane_blog' => 'șabloane de blog', 'design' => 'design'];
            /** @var array<string, array<string, int>> $folosite clasa → sursa → rânduri */
            $folosite = [];
            $noteaza = static function (string $sursa, array $clase) use (&$folosite): void {
                foreach (array_unique($clase) as $clasa) {
                    $folosite[$clasa][$sursa] = ($folosite[$clasa][$sursa] ?? 0) + 1;
                }
            };
            // Fiecare coloană cu felul ei: HTML (atributele class și blocurile <style>), CSS (selectorii)
            // sau JavaScript (clasele puse sau căutate de cod, ClaseCss::dinJs). Coloanele vechi ale
            // șabloanelor de produs (*_template) se citesc și ele, dacă au rămas pe site.
            $surse = [
                ['pagini', 'pages', ['html_content' => 'html', 'css_content' => 'css', 'js_content' => 'js']],
                ['blog', 'blog_posts', ['content' => 'html']],
                ['produse', 'products', ['description' => 'html']],
                ['sabloane_produs', 'product_templates', ['html_content' => 'html', 'css_content' => 'css', 'js_content' => 'js',
                    'html_template' => 'html', 'css_template' => 'css', 'js_template' => 'js']],
                ['sabloane_blog', 'blog_templates', ['html_content' => 'html', 'css_content' => 'css', 'js_content' => 'js']],
            ];
            $clase = static fn (string $fel, string $text): array => match ($fel) {
                'css' => ClaseCss::dinCss($text),
                'js' => ClaseCss::dinJs($text),
                default => ClaseCss::dinHtml($text),
            };
            foreach ($surse as [$sursa, $tabel, $coloane]) {
                $coloane = array_filter($coloane, static fn (string $fel, string $coloana): bool => $c->are([$tabel . '.' . $coloana]), ARRAY_FILTER_USE_BOTH);
                if ($coloane === [] || !$c->are([$tabel . '.id'])) {
                    $c->raport->lipsa('css.randuri_citite.' . $sursa, 'tabelul ' . $tabel);
                    continue;
                }
                $conditie = $c->are([$tabel . '.deleted_at']) ? 'deleted_at IS NULL' : '';
                $rezultat = $c->citire->parcurge($tabel, array_keys($coloane), $conditie, [], static function (array $r) use ($coloane, $sursa, $noteaza, $clase): void {
                    $gasite = [];
                    foreach ($coloane as $coloana => $fel) {
                        $text = (string) ($r[$coloana] ?? '');
                        if ($text !== '') {
                            $gasite = array_merge($gasite, $clase($fel, $text));
                        }
                    }
                    $noteaza($sursa, $gasite);
                });
                $c->raport->numar('css.randuri_citite.' . $sursa, $rezultat['randuri']);
                if ($rezultat['partial']) {
                    $c->raport->daNu('css.partial.' . $sursa, true, 'doar primele rânduri (limita de citire)', false);
                }
                if ($rezultat['prea_mari'] > 0) {
                    $c->raport->numar('css.randuri_prea_mari.' . $sursa, $rezultat['prea_mari'], 'peste 8 MB sau peste memoria PHP rămasă, necitite: clasele lor lipsesc din listă', 0);
                }
            }
            if ($c->are(['settings.key', 'settings.value'])) {
                // settings nu are id: întâi cheile și mărimile, apoi fiecare valoare care încape (CitireLive::octetiCareIncap).
                $citite = 0;
                $preaMari = 0;
                foreach ($c->citire->randuri('SELECT `key` AS k, LENGTH(`value`) AS marime FROM settings WHERE `key` LIKE ? ORDER BY `key` LIMIT 100', ['design\\_%']) as $r) {
                    $cheie = (string) $r['k'];
                    if ((int) $r['marime'] === 0) {
                        continue;
                    }
                    if ((int) $r['marime'] > \Unelte\Verificare\CitireLive::octetiCareIncap()) {
                        $preaMari++;
                        continue;
                    }
                    $text = (string) $c->citire->valoare('SELECT `value` AS v FROM settings WHERE `key` = ? LIMIT 1', [$cheie]);
                    $citite++;
                    $noteaza('design', $clase(str_ends_with($cheie, '_css') ? 'css' : (str_ends_with($cheie, '_js') ? 'js' : 'html'), $text));
                }
                $c->raport->numar('css.randuri_citite.design', $citite, 'setările design_* cu valoare (HTML, CSS și JS)');
                if ($preaMari > 0) {
                    $c->raport->numar('css.randuri_prea_mari.design', $preaMari, 'peste 8 MB sau peste memoria PHP rămasă, necitite: clasele lor lipsesc din listă', 0);
                }
            }
            ksort($folosite, SORT_STRING);
            $c->raport->numar('css.clase_distincte', count($folosite));
            $scrise = 0;
            foreach ($folosite as $clasa => $pe) {
                if (++$scrise > 4000) {
                    $c->raport->numar('css.clase_nescrise', count($folosite) - 4000, 'peste primele 4000 (în ordinea numelui)');
                    break;
                }
                $descriere = [];
                foreach ($etichete as $sursa => $eticheta) {
                    if (isset($pe[$sursa])) {
                        $descriere[] = $eticheta . ' ' . $pe[$sursa];
                    }
                }
                $c->raport->numar('css.' . $clasa, array_sum($pe), implode(', ', $descriere));
            }
        },
    ],
    ],
];
