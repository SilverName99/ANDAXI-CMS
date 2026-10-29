<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class Settings
{
    private const DEFAULTS = [
        'shipping_include_coupons' => '1',
        'shipping_free_bucharest' => '200',
        'shipping_free_province' => '200',
        'shipping_cost_bucharest' => '15',
        'shipping_cost_province' => '15',
        'shipping_max_cost' => '40',
        // Prețuri fixe de transport (înlocuiesc tariful curierului când sunt active).
        'shipping_fixed_enabled' => '0',
        'shipping_fixed_base' => '0',
        'shipping_fixed_extra_km' => '0',
        'shipping_fixed_fanbox' => '0',
        'shipping_fixed_fanbox_enabled' => '0',
        'fan_live_tariff_enabled' => '0',
        'fan_awb_auto' => '0',
        // Un singur AWB per comandă, chiar dacă marfa vine din mai multe
        // depozite. Coletele se consolidează înainte de expediere.
        'fan_awb_single' => '1',
        'fan_service_type' => 'Standard',
        // Serviciul pentru coletele cu ramburs. La FAN, încasarea se virează în
        // cont doar pe serviciile de „Cont Colector"; pe serviciul obișnuit
        // AWB-ul pleacă, dar banii n-au drum spre bancă. Gol = se folosește
        // serviciul obișnuit, fără deosebire.
        'fan_service_type_ramburs' => 'Cont Colector',
        'fan_service_type_fanbox_ramburs' => 'FANbox Cont Colector',
        'fan_shipping_payer' => 'recipient',
        'fan_cod_payer' => 'sender',
        // Contul în care FAN virează rambursul încasat. Lăsate goale, FAN
        // decide singur cum întoarce banii — inclusiv cash, prin curier.
        'fan_cod_bank' => '',
        'fan_cod_iban' => '',
        'fan_shipment_type' => 'parcel',
        'fan_parcel_count' => '1',
        'fan_envelope_count' => '0',
        // WP-16 (DB-06): fără adresa unui client; gol = Brand::emailContact (contact_email, apoi primul administrator
        // general). Un site existent își păstrează adresa prin înghețare (site-uri/<site>/setari-inghetate.json).
        'contact_form_recipients' => '',
        'fan_option_codes' => '',
        'fan_service_type_fanbox' => 'FANbox',
        'fan_declared_value_mode' => 'order_total',
        'fan_pickup_point' => '',
        'fan_client_id' => '',
        'fan_api_username' => '',
        'fan_api_password' => '',
        'fan_sender_name' => '',
        'fan_sender_phone' => '',
        'fan_sender_email' => '',
        'fan_sender_county' => '',
        'fan_sender_locality' => '',
        'fan_sender_street' => '',
        'fan_sender_street_no' => '',
        'fan_sender_zip_code' => '',
        'fan_default_weight_kg' => '1',
        'fan_parcel_length_cm' => '',
        'fan_parcel_width_cm' => '',
        'fan_parcel_height_cm' => '',
        // WP-17 (DB-18, DB-19, X11; deciziile OD-07, OD-08): pe un site nou TVA-ul implicit 21 (cota standard de azi),
        // numerotarea de la 1001 și formatul vechi cu prefixul „CMD”. Cele patru site-uri existente rămân, prin înghețare,
        // la 19, la contorul lor și la „BV”; TVA-ul se schimbă doar explicit, cu unealta din Setări magazin.
        'default_vat_percent' => '21',
        'order_number_mode' => 'sequential',
        'order_number_next' => '1001',
        'order_number_legacy_prefix' => 'CMD',
        'cookie_banner_enabled' => '1',
        'cookie_banner_text' => 'Folosim cookies pentru funcționarea site-ului și, cu acordul tău, pentru statistici de trafic. Poți accepta toate cookie-urile sau doar pe cele strict necesare.',
        'cookie_banner_policy_url' => '',
        // WP-16 (DB-07): fereastra de bun venit oprită și goală pe un site nou; un site existent își păstrează fereastra (titlul
        // și textul de la lansarea site-ului) prin înghețare.
        'welcome_popup_enabled' => '0',
        'welcome_popup_title' => '',
        'welcome_popup_body' => '',
        'welcome_popup_version' => '1',
        // WP-16 (VT-C6, DB-05, DB-09, DB-14): identitatea site-ului, fără niciun nume de client. Numele site-ului
        // (Setări magazin → SEO Google; gol = seo.json → organizatie.nume, APP_NAME, gazda: App\Support\Brand), adresa
        // și telefonul de contact (datele structurate, pagina de mentenanță, Brand::emailContact), eticheta contului din
        // sertarul de meniu, numele, logo-ul și adresa din emailurile conturilor (BrandedEmail, WP-17), logo-ul
        // sertarului de meniu (gol = tema.json → logo_meniu_mobil, apoi numele site-ului ca text) și descrierea unui
        // produs fără descriere scurtă în catalog (gol = niciun text).
        'store_name' => '',
        'contact_email' => '',
        'contact_phone' => '',
        'header_account_label' => 'Contul meu',
        'email_brand_name' => '',
        'email_logo_url' => '',
        'email_site_url' => '',
        // WP-17 (VT-C7): culorile emailurilor conturilor și ale pluginurilor (App\Support\BrandedEmail), neutre.
        'email_brand_color' => '#0f766e',
        'email_brand_color_2' => '#334155',
        'store_logo_url' => '',
        'catalog_descriere_implicita' => '',
        // WP-19 (C8, C14b + GR-08, C14d, GR-09, GR-16): catalogul (App\Support\Catalog). Un produs inactiv nu mai apare în
        // liste și nu are pagină (doar linkul lui de ciornă); sortarea implicită a catalogului (alpha_asc, featured,
        // price_asc, price_desc sau catalog = „Ordinea din catalog”, după products.sort_position); un câmp suplimentar de
        // tip „html” se pune în pagină ca markup; pagina catalogului ({{shop_catalog}}; altă pagină decât „magazin” →
        // /magazin face 301 acolo); butonul sortării vizibil; titlul și subtitlul benzii {{product_produse_similare}}.
        // Un site existent își păstrează comportamentul prin înghețare (site-uri/<site>/setari-inghetate.json).
        'catalog_ascunde_inactive' => '1',
        'catalog_sortare_implicita' => 'alpha_asc',
        'catalog_camp_html_brut' => '1',
        'catalog_pagina' => 'magazin',
        'catalog_sortare_vizibila' => '1',
        'catalog_similare_titlu' => 'Produse asemănătoare',
        'catalog_similare_subtitlu' => '',
        // WP-21 (C10, din Biovitality 84e29a9): bulina cu numărul recenziilor care așteaptă aprobare, lângă „Recenzii” în
        // meniul adminului (pluginul recenzii). Un site existent își păstrează meniul prin înghețare.
        'recenzii_insigna_meniu' => '1',
        // WP-21 (C10, OD-15): câte zile se păstrează adresa de la care a venit o recenzie (product_reviews.ip_address,
        // pentru limita anti-spam și pentru admin); după aceea se golește. 0 = se păstrează cât recenzia.
        'recenzii_ip_zile' => '90',
        // WP-18 (GR-03, DB-15): datele operatorului și scopul din textul acordului GDPR (pluginul acorduri_gdpr; Admin →
        // Utilizatori → Acorduri GDPR). Goale: formularul le arată ca spații de completat. Varianta textului: „general”
        // (un scop oarecare) sau „medical” (înscrierea la un eveniment medical); adresa de retragere goală =
        // „<denumire> - <sediu>”. Un site existent își păstrează textul prin înghețare.
        'gdpr_formular_varianta' => 'general',
        'gdpr_scop' => '',
        'gdpr_operator_nume' => '',
        'gdpr_operator_sediu' => '',
        'gdpr_operator_telefon' => '',
        'gdpr_operator_email' => '',
        'gdpr_operator_regcom' => '',
        'gdpr_operator_cui' => '',
        'gdpr_operator_marca' => '',
        'gdpr_operator_reprezentant' => '',
        'gdpr_operator_adresa_retragere' => '',
        'email_delivery_method' => 'smtp',
        // WP-16 (DB-05): gol = numele site-ului (Brand::numeExpeditor); un site existent își păstrează numele prin înghețare.
        'order_email_from_name' => '',
        'order_email_from_address' => 'no-reply@localhost',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_encryption' => 'tls',
        'smtp_username' => '',
        'smtp_password' => '',
        'sendgrid_api_key' => '',
        'email_template_new_order_active' => '1',
        'email_template_new_order_recipient_mode' => 'client',
        'email_template_new_order_admin_recipients' => '',
        'email_template_new_order_subject' => 'Comanda noua {{order_number}}',
        'email_template_new_order_body' => '<p>Buna {{customer_name}},</p><p>Comanda ta {{order_number}} a fost inregistrata cu succes.</p>',
        'email_template_processing_active' => '1',
        'email_template_processing_recipient_mode' => 'client',
        'email_template_processing_admin_recipients' => '',
        'email_template_processing_subject' => 'Comanda {{order_number}} este in procesare',
        'email_template_processing_body' => '<p>Buna {{customer_name}},</p><p>Comanda ta {{order_number}} este in procesare.</p>',
        'email_template_shipped_active' => '1',
        'email_template_shipped_recipient_mode' => 'client',
        'email_template_shipped_admin_recipients' => '',
        'email_template_shipped_subject' => 'Comanda {{order_number}} a fost expediata',
        'email_template_shipped_body' => '<p>Buna {{customer_name}},</p><p>Comanda ta {{order_number}} a fost expediata.</p><p>AWB: <strong>{{awb}}</strong></p><p>{{tracking_link}}</p>',
        'email_template_delivered_active' => '1',
        'email_template_delivered_recipient_mode' => 'client',
        'email_template_delivered_admin_recipients' => '',
        'email_template_delivered_subject' => 'Comandă livrată {{order_number}}',
        'email_template_delivered_body' => '<div style="text-align:center;padding-top:8px;"><div style="margin:0 auto 12px;color:#2f8d5b;font-size:56px;line-height:1;font-weight:700;">✅</div><h2 style="margin:0;font-family:Georgia,\'Times New Roman\',serif;font-size:42px;line-height:1.05;color:#0f172a;font-weight:700;">{{store_name}}</h2></div><p style="margin:28px 0 10px;font-size:38px;line-height:1.08;color:#0f172a;font-weight:700;">Bună, {{customer_name}}! 🎉</p><p style="margin:0 0 20px;color:#4f6b66;font-size:16px;line-height:1.55;">Comanda ta a fost livrată cu succes! Sperăm că produsele noastre naturale îți vor aduce bucurie.</p><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0;background:#f4f7f4;border-radius:14px;padding:18px 18px 14px;margin:0 0 22px;"><tr><td style="padding:7px 8px;color:#536f69;font-size:17px;line-height:1.35;">Nr. comandă</td><td style="padding:7px 8px;color:#0f172a;font-size:34px;line-height:1.05;font-weight:700;text-align:right;">#{{order_number}}</td></tr><tr><td style="padding:7px 8px;color:#536f69;font-size:17px;line-height:1.35;">Livrat pe</td><td style="padding:7px 8px;color:#0f172a;font-size:17px;line-height:1.35;font-weight:600;text-align:right;">{{order_date}}</td></tr></table><p style="margin:0 0 24px;text-align:center;"><a href="{{order_action_url}}" style="display:inline-block;min-width:260px;padding:14px 22px;border-radius:14px;background:#2f8d5b;color:#ffffff;text-decoration:none;font-weight:700;font-size:18px;line-height:1.2;">Lasă o recenzie →</a></p><hr style="border:none;border-top:1px solid #e5e7eb;margin:18px 0 16px;"><p style="margin:0 0 6px;text-align:center;color:#5b6f69;font-size:14px;line-height:1.5;">© {{year}} {{store_name}}. Toate drepturile rezervate.</p><p style="margin:0;text-align:center;color:#7b8b86;font-size:14px;line-height:1.5;">Acest email a fost trimis la {{customer_email}}</p>',
        'email_template_cancelled_active' => '1',
        'email_template_cancelled_recipient_mode' => 'client',
        'email_template_cancelled_admin_recipients' => '',
        'email_template_cancelled_subject' => 'Comandă anulată {{order_number}}',
        'email_template_cancelled_body' => '<div style="text-align:center;padding-top:8px;"><div style="margin:0 auto 14px;width:92px;height:92px;border-radius:999px;border:3px solid #ef4444;color:#ef4444;font-size:56px;line-height:86px;font-weight:700;">✕</div><h2 style="margin:0;font-family:Georgia,\'Times New Roman\',serif;font-size:42px;line-height:1.05;color:#0f172a;font-weight:700;">{{store_name}}</h2></div><p style="margin:28px 0 10px;font-size:38px;line-height:1.08;color:#0f172a;font-weight:700;">Bună, {{customer_name}},</p><p style="margin:0 0 20px;color:#4f6b66;font-size:16px;line-height:1.55;">Comanda ta a fost anulată conform solicitării. Dacă ai plătit online, suma va fi returnată în 3-5 zile lucrătoare.</p><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0;background:#f4f7f4;border-radius:14px;padding:18px 18px 14px;margin:0 0 22px;"><tr><td style="padding:7px 8px;color:#536f69;font-size:17px;line-height:1.35;">Nr. comandă</td><td style="padding:7px 8px;color:#0f172a;font-size:34px;line-height:1.05;font-weight:700;text-align:right;">#{{order_number}}</td></tr><tr><td style="padding:7px 8px;color:#536f69;font-size:17px;line-height:1.35;">Sumă returnată</td><td style="padding:7px 8px;color:#0f172a;font-size:17px;line-height:1.35;font-weight:600;text-align:right;">{{order_total}}</td></tr></table><p style="margin:0 0 24px;text-align:center;"><a href="{{order_action_url}}" style="display:inline-block;min-width:260px;padding:14px 22px;border-radius:14px;background:#ef4444;color:#ffffff;text-decoration:none;font-weight:700;font-size:18px;line-height:1.2;">Contactează-ne →</a></p><hr style="border:none;border-top:1px solid #e5e7eb;margin:18px 0 16px;"><p style="margin:0 0 6px;text-align:center;color:#5b6f69;font-size:14px;line-height:1.5;">© {{year}} {{store_name}}. Toate drepturile rezervate.</p><p style="margin:0;text-align:center;color:#7b8b86;font-size:14px;line-height:1.5;">Acest email a fost trimis la {{customer_email}}</p>',
        'email_template_abandoned_cart_active' => '1',
        'email_template_abandoned_cart_recipient_mode' => 'client',
        'email_template_abandoned_cart_admin_recipients' => '',
        'email_template_abandoned_cart_subject' => 'Ai uitat produse în coș',
        'email_template_abandoned_cart_body' => '<div style="text-align:center;padding-top:8px;"><div style="margin:0 auto 12px;color:#eaa325;font-size:56px;line-height:1;font-weight:700;">🛒</div><h2 style="margin:0;font-family:Georgia,\'Times New Roman\',serif;font-size:42px;line-height:1.05;color:#0f172a;font-weight:700;">{{store_name}}</h2></div><p style="margin:28px 0 10px;font-size:38px;line-height:1.08;color:#0f172a;font-weight:700;">Bună, {{customer_name}}! 👋</p><p style="margin:0 0 20px;color:#4f6b66;font-size:16px;line-height:1.55;">Am observat că ai lăsat câteva produse în coș. Le păstrăm pentru tine, dar nu pentru mult timp!</p><p style="margin:0 0 8px;color:#0f172a;font-size:32px;line-height:1.1;font-weight:700;">Produse:</p><div style="margin:0 0 18px;">{{cart_items_html}}</div><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 20px;"><tr><td style="padding:12px 0;border-top:1px solid #e5e7eb;color:#0f172a;font-size:36px;line-height:1.1;font-weight:700;">Total</td><td style="padding:12px 0;border-top:1px solid #e5e7eb;color:#0f172a;font-size:36px;line-height:1.1;font-weight:700;text-align:right;">{{cart_total}}</td></tr></table><p style="margin:0 0 24px;text-align:center;"><a href="{{cart_action_url}}" style="display:inline-block;min-width:260px;padding:14px 22px;border-radius:14px;background:#eaa325;color:#ffffff;text-decoration:none;font-weight:700;font-size:18px;line-height:1.2;">Finalizează comanda →</a></p><hr style="border:none;border-top:1px solid #e5e7eb;margin:18px 0 16px;"><p style="margin:0 0 6px;text-align:center;color:#5b6f69;font-size:14px;line-height:1.5;">© {{year}} {{store_name}}. Toate drepturile rezervate.</p><p style="margin:0;text-align:center;color:#7b8b86;font-size:14px;line-height:1.5;">Acest email a fost trimis la {{customer_email}}</p>',
        'email_abandoned_after_minutes' => '60',
        'stripe_publishable_key' => '',
        'stripe_secret_key' => '',
        'stripe_webhook_secret' => '',
        'stripe_currency' => 'ron',
        // EuPlătesc — plata cu cardul prin redirect (procesatorul implicit).
        'euplatesc_enabled' => '0',
        'euplatesc_merchant_id' => '',
        'euplatesc_secret_key' => '',
        'euplatesc_currency' => 'RON',
        // Stripe rămâne disponibil ca alternativă, dar ascuns implicit.
        'stripe_enabled' => '0',
        // Banca Transilvania iPay: oprit până îl pornește proprietarul. Datele de
        // acces stau în .env (BT_IPAY_*), nu aici.
        'bt_ipay_enabled' => '0',
        'bt_ipay_admin_only' => '0',
        'bt_ipay_deposit_on_erp' => '1',
        'bt_ipay_reminder_hours' => '72',
        'bt_ipay_autodeposit_hours' => '96',
        'bt_ipay_expire_minutes' => '60',
        'bt_ipay_notify_emails' => '',
        // WP-17 (DB-16): numele magazinului din textul plății trimis băncii („Comanda 1001 exemplu.ro”); gol = gazda site-ului.
        'bt_ipay_descriere_magazin' => '',
        // Mod mentenanță: vizitatorii văd „Revenim în curând", testerii văd site-ul.
        'maintenance_enabled' => '0',
        'maintenance_title' => 'Revenim în curând',
        'maintenance_message' => 'Lucrăm la magazin chiar acum. Ne întoarcem cât de repede putem.',
        'maintenance_key' => '',
        'maintenance_allowed_ips' => '',
        'customer_google_auth_enabled' => '0',
        'customer_google_client_id' => '',
        'customer_google_client_secret' => '',
        'loyalty_points_enabled' => '1',
        'loyalty_points_earn_rate' => '1',
        'loyalty_points_redeem_value' => '0.10',
        'loyalty_points_min_redeem' => '100',
        'loyalty_points_max_redeem_percent' => '50',
        'loyalty_points_promo_active' => '0',
        'loyalty_points_promo_multiplier' => '1',
        'loyalty_points_weekend_multiplier' => '1',
        'design_header_html' => '',
        'design_header_css' => '',
        'design_header_js' => '',
        'design_footer_html' => '',
        'design_footer_css' => '',
        'design_footer_js' => '',
        'design_menu_html' => '<a href="/">Acasă</a><a href="/magazin">Magazin</a><a href="/contact">Contact</a>',
        'design_menu_css' => '',
        'design_menu_js' => '',
        'floating_cart_enabled' => '1',
        'floating_cart_show_desktop' => '1',
        'floating_cart_show_mobile' => '1',
        'floating_cart_auto_open_on_add' => '1',
        'floating_cart_position' => 'right',
        'floating_cart_trigger_label' => 'Coș',
        'floating_cart_title' => 'Coșul tău',
        'floating_cart_view_cart_label' => 'Vezi coșul',
        'floating_cart_checkout_label' => 'Checkout',
        'floating_cart_accent_color' => '#0f766e',
        'floating_cart_badge_bg' => '#ffffff',
        'floating_cart_badge_text' => '#0f766e',
        'floating_cart_panel_width' => '420',
        'floating_cart_offset_x' => '18',
        'floating_cart_offset_y' => '18',
        'floating_cart_show_product_images' => '1',
        'floating_cart_show_view_cart_button' => '1',
        'floating_cart_show_checkout_button' => '1',
        'floating_cart_show_subtotal' => '1',
        'floating_cart_show_discount' => '1',
        'floating_cart_show_points_discount' => '1',
        'floating_cart_show_shipping' => '1',
        'floating_cart_show_vat' => '1',
        'floating_cart_show_points_earned' => '1',
        'floating_cart_points_position' => 'before_total',
        'floating_cart_points_text' => 'Primești {points} puncte la această comandă.',
        'floating_cart_free_shipping_threshold' => '200',
        'floating_cart_excluded_urls' => '',
        'mannequin_enabled' => '1',
        'mannequin_title' => 'Recomandări pe zone',
        'mannequin_subtitle' => 'Alege un punct de pe manechin pentru a vedea produsele recomandate.',
        'mannequin_empty_text' => 'Nu sunt produse pentru această categorie.',
        'mannequin_code' => '{{mannequin_section}}',
        'store_quantity_control_style' => 'default',
        'store_quantity_apply_product_template' => '0',
        'store_quantity_apply_floating_cart' => '0',
        'store_quantity_apply_cart_page' => '0',
        // Sertarul lateral „Oferte” (produse cu preț redus / dată de expirare).
        'store_bbd_sidebar_enabled' => '1',
        // Integrare ERP ANDAXI (comenzi + stoc).
        'erp_enabled' => '0',
        'erp_url' => '',
        'erp_api_key' => '',
        'erp_timeout' => '20',
        'erp_stock_enabled' => '0',
        // Adrese FAN per depozit (JSON: gestiuneId → adresă), pentru gestiuni pe județe.
        'fan_depozite_json' => '',
        'store_favicon_url' => '',
        'store_seo_home_title' => '',
        'store_seo_home_description' => '',
        'store_seo_default_description' => '',
        'store_seo_default_image_url' => '',
        'tawk_enabled' => '0',
        'tawk_property_id' => '',
        'tawk_widget_id' => 'default',
        'tawk_position' => 'br',
        'tawk_offset_y' => '18',
        'tawk_requires_consent' => '1',
        'tawk_api_key' => '',
        'microsoft_clarity_enabled' => '0',
        'microsoft_clarity_project_id' => '',
        'google_site_verification' => '',
        'google_analytics_enabled' => '0',
        'google_analytics_id' => '',
        'google_tag_manager_enabled' => '0',
        'google_tag_manager_id' => '',
        'google_tag_manager_head_code' => '',
        'google_tag_manager_body_code' => '',
        'google_analytics_code' => '',
        'google_ads_enabled' => '0',
        'google_ads_conversion_id' => '',
        'google_ads_conversion_label' => '',
        'cache_pages_enabled' => '0',
        'cache_pages_rules_json' => '[]',
        'cache_exclusions_custom' => '',
        'cache_assets_enabled' => '0',
        'cache_assets_ttl_seconds' => '2592000',
        'cache_uploads_ttl_seconds' => '2592000',
        'cache_assets_versioning_mode' => 'query',
        'cache_assets_etag_enabled' => '1',
        'cache_assets_version_token' => '',
    ];

    /**
     * Cota de TVA implicită a site-ului, ca text (de exemplu „21”): setarea `default_vat_percent`, altfel implicitul
     * nucleului (WP-17, DB-19). Toate locurile care foloseau „19” scris în cod o citesc de aici.
     *
     * @param array<string, mixed> $setari
     */
    public static function cotaTvaImplicita(array $setari): string
    {
        return (string) ($setari['default_vat_percent'] ?? self::DEFAULTS['default_vat_percent']);
    }

    public static function all(?PDO $db): array
    {
        if (!$db instanceof PDO) {
            return self::DEFAULTS;
        }

        $settings = self::DEFAULTS;
        $rows = $db->query('SELECT `key`, `value` FROM settings')->fetchAll();

        foreach ($rows as $row) {
            $settings[(string) $row['key']] = (string) $row['value'];
        }

        return $settings;
    }

    public static function save(?PDO $db, array $values): void
    {
        if (!$db instanceof PDO) {
            return;
        }

        $stmt = $db->prepare(
            'INSERT INTO settings (`key`, `value`) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );

        foreach ($values as $key => $value) {
            $stmt->execute([
                'key' => (string) $key,
                'value' => (string) $value,
            ]);
        }
    }
}
