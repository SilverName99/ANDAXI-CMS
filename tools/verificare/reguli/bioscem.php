<?php

declare(strict_types=1);

/**
 * Regulile încărcării anonimizate pentru Bioscem (tools/verificare/incarca.php --din=bioscem; docs/VERIFICARE.md,
 * „Încărcarea copiei”; planul de verificare §3.5.2). Acoperă fiecare tabel și fiecare coloană a schemei pe care o face
 * codul Bioscem la `4254ca4` (tools/dev/migrari/vechi/bs.sql: și tabelele avertismentului pe produs, product_warnings și
 * order_product_warnings; câmpurile SEO ale categoriilor și ale mărcilor sunt rânduri în seo_pages, tipurile „category”
 * și „brand”), plus ce adaugă migrările codului comun (database/migrari/asteptat.json) și tabelele runner-ului.
 *
 * Fiecare tabel are un mod:
 * - `anonimizeaza`: rândurile se copiază, cu coloanele din `transforma` schimbate în PHP între citire și scriere (datele
 *   personale nu ajung niciodată în baza copiei);
 * - `copiaza`: conținut public sau date de business (catalogul, paginile, nomenclatorul FAN), copiat cum e;
 * - `setari`: tabelul settings, copiat cu cheile din `setari` (mai jos) schimbate tot în drum;
 * - `doar_structura`: doar tabelul, niciun rând (jurnale cu IP-uri și cereri, resetări de parolă, acordurile GDPR cu CNP și
 *   semnătură, coșurile abandonate cu emailul și conținutul coșului).
 * Un tabel care nu e aici primește doar structura și apare pe ecran cu numele lui.
 *
 * Fiecare coloană a unui tabel copiat e numită fie în `pastreaza` (copiată cum e), fie în `transforma` (cu regula ei).
 * O coloană necunoscută oprește încărcarea înaintea oricărui rând (doar numele ei pe ecran), oricare i-ar fi numele: o
 * coloană nouă pe Bioscem (de exemplu dintr-o livrare SEO) cere întâi un rând aici. O coloană al cărei nume arată a date
 * personale (tiparul `TIPAR_PERSONAL` din tools/verificare/lib/live.php: email, telefon, nume, adresă, IP, parolă, token…)
 * păstrată cum e cere și motivul, în `motive`. `emailuri_publice` (cu motivul) scoate tabelul din căutarea generică a
 * adreselor de email de la final (aserțiunea A1): paginile, articolele și descrierile pot avea adresa magazinului.
 *
 * Regulile din `transforma` (tools/verificare/lib/incarcare.php, Transformari; H(x) = HMAC-SHA256 cu o sare aleatoare a
 * încărcării, ștearsă la final): `email` (E: `u` + 12 hex din H(adresa cu litere mici) + `@verificare.invalid`, aceeași
 * adresă dă același pseudonim în toate tabelele), `emailuri` (E pe fiecare adresă dintr-o listă), `email_admin`
 * (`admin<id>@verificare.invalid`), `telefon` (`07` + 8 cifre din H), `prenume` (`Client`), `nume` (6 hex mari din
 * H(email): `'email' => coloana`), `nume_client` / `nume_abonat` (`Client …` / `Abonat …` + 6 hex din H(nume): același
 * nume, același pseudonim, și în tabele diferite), `adresa` (`Strada Verificare N`), `numar_strada` (`1`), `cod_postal`
 * (`000000`), `text` (`[text anonimizat, N caractere]`; cu `'daca' => [coloana => valoare]` doar pe acele rânduri, iar pe
 * celelalte textul, public, rămâne cu fiecare adresă de email din el schimbată cu pseudonimul ei E), `ref`
 * (`anon-<id>`), `parola` (bcrypt al unei parole aleatoare, pe orice rând), `nul` (NULL, sau gol pe o coloană NOT NULL),
 * `valoare` (`'valoare' => …`, cu `'daca_nenul' => coloana`), `firma`, `cui`, `reg_com`, `subiect` (`'din' => coloana`),
 * `token` (64 hex noi), `cod_cupon` / `nume_cupon` / `cupon_comanda` (codurile unice devin `VERIF-<id>`, și în comenzi;
 * numele unui cupon unic, „Cupon unic VERIF-<id>”), `awb` (`DEMO<id>`), `awb_lista` (JSON-ul coletelor, fiecare AWB
 * rescris), `url_urmarire` (adresa FAN a noului AWB), `url_fara_interogare`. Un text gol sau NULL rămâne cum e (afară de
 * `parola` și `token`). Orașul și județul rămân (regulile livrării depind de ele), la fel sumele, stările, datele și
 * id-urile.
 *
 * `versiune` crește la orice schimbare: o încărcare începută cu altă versiune nu se continuă cu --reia (--reincarca).
 * Biovitality, vertij și grafoanaytis primesc fișierele lor în porturile lor (WP-11b pentru Biovitality).
 */

return [
    'site' => 'bioscem',
    // 2 (revizuirea WP-09): user_addresses.label transformată; adresele de email din recenziile aprobate, schimbate cu pseudonimul.
    'versiune' => 2,
    'schema' => 'Nou-site-bioscem@4254ca4 (tools/dev/migrari/vechi/bs.sql) + database/migrari/asteptat.json',
    // Pachetul de pluginuri pus pe copie (config/plugins.php → licente_dev) și pachetul site-ului (site-uri/<pachet>/).
    'licenta' => 'bioscem',
    'pachet' => 'bioscem',

    // Grupurile, în ordinea copierii. Un grup se copiază într-un singur instantaneu al sursei (START TRANSACTION WITH
    // CONSISTENT SNAPSHOT, READ ONLY) și se scrie pe copie într-o singură tranzacție: un grup oprit la jumătate nu lasă
    // nimic, iar --reia îl ia de la capăt. „comenzi” ține împreună tot ce leagă o comandă (clientul, cuponul, liniile,
    // plățile, punctele): cupoanele întâi, pentru codurile unice rescrise și în comenzi.
    'grupuri' => [
        'setari' => ['settings'],
        'platforma' => ['_platforma_migrari', '_platforma_stare'],
        'continut' => ['pages', 'seo_pages', 'gallery_folders', 'gallery_images', 'mannequin_points', 'mannequin_point_products',
            'promotional_products', 'loyalty_points_rules'],
        'catalog' => ['product_categories', 'products', 'product_category_links', 'product_extra_fields', 'product_extra_field_values',
            'product_templates', 'product_template_fields', 'product_warnings'],
        'blog' => ['blog_authors', 'blog_categories', 'blog_posts', 'blog_post_categories', 'blog_templates'],
        'fan' => ['fan_localities', 'fan_localities_extra_km', 'fan_lockers', 'fan_nomenclator_sync', 'fan_streets'],
        'comenzi' => ['coupons', 'users', 'user_addresses', 'orders', 'order_items', 'order_payment_links', 'bt_ipay_transactions',
            'loyalty_points_transactions', 'order_promo_items', 'order_product_warnings'],
        'recenzii' => ['product_reviews', 'reviews'],
        'newsletter' => ['newsletter_lists', 'newsletter_subscribers', 'newsletter_list_subscribers', 'newsletter_templates',
            'newsletter_optin_forms', 'newsletter_campaigns', 'newsletter_campaign_sends', 'newsletter_campaign_opens',
            'newsletter_campaign_clicks'],
        'mesaje' => ['contact_form_messages', 'email_logs', 'email_send_history'],
        'admini' => ['admins'],
    ],

    // Tabelele de lucru ale sincronizării FAN (o copie în curs, `fan_streets__sync`, sau păstrată, `__vechi`): regulile
    // tabelului de bază, în grupul lui.
    'modele' => [
        '/^(fan_[a-z_]+)__(sync|vechi)$/' => '$1',
    ],

    'tabele' => [
        // ── Doar structura ──
        'admin_activity_log' => ['mod' => 'doar_structura', 'motiv' => 'jurnalul adminilor: emailul și IP-ul lor, cereri'],
        'bt_ipay_log' => ['mod' => 'doar_structura', 'motiv' => 'jurnalul băncii: cererile și răspunsurile, cu datele cardului'],
        'checkout_submit_logs' => ['mod' => 'doar_structura', 'motiv' => 'IP-uri și browsere'],
        'register_submit_logs' => ['mod' => 'doar_structura', 'motiv' => 'IP-uri și browsere'],
        'customer_password_resets' => ['mod' => 'doar_structura', 'motiv' => 'emailuri și jetoane de resetare a parolei'],
        'cart_abandonments' => ['mod' => 'doar_structura', 'motiv' => 'emailul, numele și conținutul coșului vizitatorilor'],
        'gdpr_agreements' => ['mod' => 'doar_structura', 'motiv' => 'acordurile semnate: CNP, buletin, semnătura (niciodată rânduri)'],

        // ── Setările ──
        'settings' => ['mod' => 'setari', 'pastreaza' => ['key', 'value'], 'emailuri_publice' => 'textele site-ului (antet, subsol, '
            . 'emailuri) au adresa magazinului; destinatarii și cheile secrete le schimbă lista „setari” de mai jos'],

        // ── Runner-ul migrărilor (o bază deja adoptată) ──
        '_platforma_migrari' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'ord', 'proprietar', 'tip', 'sha256', 'blocanta', 'rezultat',
            'operatii', 'cursor_json', 'cod_versiune', 'server_versiune', 'inceputa_la', 'terminata_la', 'durata_ms']],
        '_platforma_stare' => ['mod' => 'copiaza', 'pastreaza' => ['cheie', 'valoare', 'actualizata_la']],

        // ── Conținut public ──
        'pages' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'title', 'slug', 'html_content', 'css_content', 'js_content', 'is_published',
            'deleted_at', 'created_at', 'updated_at'], 'emailuri_publice' => 'paginile site-ului (contact, termeni) au adresa magazinului'],
        // Categoriile și mărcile au titlul și descrierea pentru Google aici (page_type „category” și „brand”, Bioscem 93d803f).
        'seo_pages' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'page_type', 'page_ref', 'title', 'description', 'canonical_url', 'image_url'],
            'emailuri_publice' => 'textele pentru Google ale paginilor sunt publice'],
        'gallery_folders' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'name', 'slug', 'created_at'],
            'motive' => ['name' => 'numele unui dosar al galeriei']],
        'gallery_images' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'title', 'media_type', 'image_url', 'folder_id', 'alt_text', 'sort_order',
            'is_active', 'created_at'], 'emailuri_publice' => 'titlurile pozelor publice'],
        'mannequin_points' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'point_key', 'label', 'x_percent', 'y_percent', 'sort_order', 'is_active',
            'created_at', 'updated_at'], 'emailuri_publice' => 'etichetele publice ale manechinului'],
        'mannequin_point_products' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'point_id', 'product_id', 'created_at']],
        'promotional_products' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'name', 'notes', 'is_active', 'sort_order', 'created_at'],
            'motive' => ['name' => 'numele unui produs promoțional'], 'emailuri_publice' => 'notele produselor promoționale ale magazinului'],
        'loyalty_points_rules' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'name', 'description', 'multiplier', 'min_order_total', 'category_id',
            'starts_at', 'ends_at', 'is_active', 'created_at', 'updated_at'], 'motive' => ['name' => 'numele unei reguli de puncte'],
            'emailuri_publice' => 'descrierile regulilor de puncte, publice'],

        // ── Catalogul ──
        'product_categories' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'name', 'slug', 'created_at'], 'motive' => ['name' => 'numele categoriei']],
        'products' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'name', 'sku', 'category', 'category_id', 'product_template_id', 'slug',
            'short_description', 'description', 'product_highlights', 'price', 'vat_percent', 'vat_included', 'sale_price', 'sale_price_periods_json',
            'discount_badge_mode', 'bbd_enabled', 'bbd_entries_json', 'post_cart_note_enabled', 'post_cart_note_text', 'stock', 'out_of_stock',
            'preorder_enabled', 'preorder_max_per_order', 'preorder_max_total', 'weight_grams', 'image_url', 'gallery_json', 'gallery_images_json',
            'similar_products_json', 'badge_popular', 'badge_best_seller', 'badge_seasonal', 'is_active', 'deleted_at', 'created_at', 'updated_at',
            'brand', 'tags_json'], 'motive' => ['name' => 'numele produsului'],
            'emailuri_publice' => 'descrierile produselor pot avea adresa magazinului'],
        'product_category_links' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'product_id', 'category_id']],
        'product_extra_fields' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'name', 'field_key', 'field_type', 'placeholder', 'default_value',
            'is_required', 'sort_order', 'is_active', 'created_at', 'updated_at'], 'motive' => ['name' => 'numele unui câmp al produselor']],
        'product_extra_field_values' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'product_id', 'field_id', 'value', 'created_at', 'updated_at'],
            'emailuri_publice' => 'valorile câmpurilor de produs sunt conținut public'],
        'product_templates' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'name', 'slug', 'description', 'html_content', 'css_content', 'js_content',
            'is_active', 'created_at', 'updated_at'], 'motive' => ['name' => 'numele unui șablon'],
            'emailuri_publice' => 'șabloanele paginilor de produs pot avea adresa magazinului'],
        'product_template_fields' => ['mod' => 'copiaza', 'pastreaza' => ['template_id', 'field_id', 'created_at']],
        // Avertismentul pe produs (Bioscem 0582ce9): textul lui e al produsului, nu al unui client.
        'product_warnings' => ['mod' => 'copiaza', 'pastreaza' => ['product_id', 'title', 'body', 'checkbox_label', 'updated_at'],
            'emailuri_publice' => 'textul avertismentului e public (fereastra din coș) și poate avea adresa magazinului'],

        // ── Blogul ──
        'blog_authors' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'name', 'slug', 'bio', 'avatar_url', 'is_active', 'created_at', 'updated_at'],
            'motive' => ['name' => 'autorii publici ai articolelor (semnătura de pe blog)'],
            'emailuri_publice' => 'prezentarea publică a autorului, de pe blog'],
        'blog_categories' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'name', 'slug', 'sort_order', 'is_active', 'created_at'],
            'motive' => ['name' => 'numele categoriei']],
        'blog_posts' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'title', 'slug', 'excerpt', 'category', 'category_id', 'event_start_date',
            'event_end_date', 'event_price', 'event_ticket_url', 'event_location', 'content', 'reading_minutes', 'published_at', 'is_published',
            'template_id', 'author_id', 'featured_image_url', 'video_url', 'deleted_at', 'created_at', 'updated_at'],
            'emailuri_publice' => 'articolele pot avea adresa magazinului sau a unui organizator de eveniment, publice'],
        'blog_post_categories' => ['mod' => 'copiaza', 'pastreaza' => ['post_id', 'category_id']],
        'blog_templates' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'name', 'slug', 'description', 'html_content', 'css_content', 'js_content',
            'is_active', 'created_at', 'updated_at'], 'motive' => ['name' => 'numele unui șablon'],
            'emailuri_publice' => 'șabloanele articolelor pot avea adresa magazinului'],

        // ── Nomenclatorul FAN (public) ──
        'fan_localities' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'county', 'locality', 'county_norm', 'locality_norm', 'created_at', 'updated_at']],
        'fan_localities_extra_km' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'county', 'locality', 'county_norm', 'locality_norm', 'exterior_km',
            'created_at', 'updated_at']],
        'fan_lockers' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'code', 'fan_id', 'name', 'county', 'locality', 'address', 'postcode', 'lat', 'lng',
            'county_norm', 'locality_norm', 'active', 'created_at', 'updated_at'], 'motive' => ['name' => 'numele public al unui FANbox',
            'address' => 'adresa publică a unui FANbox', 'postcode' => 'codul poștal public al unui FANbox']],
        'fan_nomenclator_sync' => ['mod' => 'copiaza', 'pastreaza' => ['lista', 'stare', 'sursa', 'cursor_json', 'pas', 'pasi_total', 'randuri',
            'pornit_la', 'activ_la', 'reusit_la', 'reusit_sursa', 'reusit_randuri', 'reusit_detalii', 'eroare', 'eroare_la']],
        'fan_streets' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'county', 'locality', 'street', 'street_id', 'range_from', 'range_to', 'parity',
            'postal_code', 'street_type', 'agency', 'county_norm', 'locality_norm', 'street_norm', 'row_key', 'created_at', 'updated_at'],
            'motive' => ['street' => 'nomenclatorul public al străzilor FAN', 'street_id' => 'nomenclatorul public al străzilor FAN',
                'street_type' => 'nomenclatorul public al străzilor FAN', 'street_norm' => 'nomenclatorul public al străzilor FAN']],

        // ── Comenzile și clienții ──
        'coupons' => ['mod' => 'anonimizeaza', 'pastreaza' => ['id', 'type', 'value', 'product_ids_json', 'category_ids_json', 'min_items_count',
            'max_items_count', 'max_uses_total', 'allowed_user_ids_json', 'apply_only_selected_products', 'is_unique', 'used_at', 'import_batch',
            'stacks_with_discounts', 'is_active', 'starts_at', 'ends_at', 'created_at'],
            'transforma' => [
                // Un cod unic nefolosit e un voucher la purtător care ar merge pe site-ul adevărat.
                'code' => ['cod_cupon'],
                'name' => ['nume_cupon'],
            ]],
        'users' => ['mod' => 'anonimizeaza', 'pastreaza' => ['id', 'loyalty_points', 'created_at'],
            'transforma' => [
                'first_name' => ['prenume'],
                'last_name' => ['nume', 'email' => 'email'],
                'email' => ['email'],
                'phone' => ['telefon'],
                'birth_date' => ['nul'],
                'gender' => ['nul'],
                // Auth::attempt compară în clar o parolă salvată necriptat (App\Support\ParolaAdmin): toate devin bcrypt aleatoare.
                'password_hash' => ['parola'],
                'google_id' => ['nul'],
            ]],
        'user_addresses' => ['mod' => 'anonimizeaza', 'pastreaza' => ['id', 'user_id', 'city', 'county', 'is_default', 'created_at'],
            'transforma' => [
                // Eticheta o scrie clientul în contul lui („Acasă”, „Birou”, dar și „La mama, Elena …”): un text liber al lui.
                'label' => ['text'],
                'full_name' => ['nume_client'],
                'phone' => ['telefon'],
                'street' => ['adresa'],
                'street_no' => ['numar_strada'],
                'address_line1' => ['adresa'],
                'address_line2' => ['nul'],
                'postcode' => ['cod_postal'],
            ]],
        'orders' => ['mod' => 'anonimizeaza', 'pastreaza' => ['id', 'order_number', 'user_id', 'status', 'preorder_status', 'preorder_released_at',
            'payment_method', 'payment_status', 'paid_at', 'shipping_method', 'shipping_cost', 'shipping_manual', 'discount_total', 'loyalty_points_used',
            'loyalty_points_discount', 'loyalty_points_awarded', 'loyalty_points_pending_claim', 'loyalty_points_awarded_at', 'subtotal', 'total',
            'billing_city', 'billing_county', 'billing_is_company', 'shipping_same_as_billing', 'shipping_city', 'shipping_county', 'fan_locker_id',
            'fan_locker_name', 'fan_locker_address', 'fan_locker_city', 'fan_locker_county', 'fan_locker_postcode', 'fan_tracking_status',
            'fan_tracking_last_event_at', 'fan_tracking_synced_at', 'completed_awb_email_sent_at', 'deleted_at', 'created_at', 'updated_at',
            'ad_source', 'nl_campaign_id', 'erp_status', 'erp_order_id', 'erp_attempts', 'erp_last_attempt_at', 'erp_next_retry_at', 'erp_synced_at',
            'erp_factura_numar', 'paid_amount', 'edit_unlocked_at', 'erp_client_agresiv', 'erp_client_agresiv_versiune', 'manual_discount',
            'manual_discount_percent', 'terms_accepted_at'],
            'motive' => ['fan_locker_name' => 'FANbox-ul ales: date publice ale lockerului', 'fan_locker_address' => 'adresa publică a FANbox-ului',
                'fan_locker_postcode' => 'codul poștal public al FANbox-ului', 'completed_awb_email_sent_at' => 'doar data trimiterii, fără adresă'],
            'transforma' => [
                'stripe_session_id' => ['ref'],
                'stripe_payment_intent_id' => ['ref'],
                'euplatesc_ep_id' => ['ref'],
                'euplatesc_approval' => ['ref'],
                'payment_error' => ['text'],
                'coupon_code' => ['cupon_comanda'],
                'billing_first_name' => ['prenume'],
                'billing_last_name' => ['nume', 'email' => 'billing_email'],
                'billing_phone' => ['telefon'],
                'billing_email' => ['email'],
                'billing_address_line1' => ['adresa'],
                'billing_address_line2' => ['nul'],
                'billing_postcode' => ['cod_postal'],
                'billing_company_name' => ['firma'],
                'billing_company_tax_id' => ['cui'],
                'billing_company_registration_no' => ['reg_com'],
                'shipping_first_name' => ['prenume'],
                'shipping_last_name' => ['nume', 'email' => 'billing_email'],
                'shipping_phone' => ['telefon'],
                'shipping_address_line1' => ['adresa'],
                'shipping_address_line2' => ['nul'],
                'shipping_postcode' => ['cod_postal'],
                'fan_awb' => ['awb'],
                'fan_awb_list' => ['awb_lista'],
                'fan_awb_inlocuite' => ['awb_lista'],
                'fan_tracking_url' => ['url_urmarire'],
                'completed_awb_email_error' => ['nul'],
                'notes' => ['text'],
                'admin_notes' => ['text'],
                'ad_click_id' => ['nul'],
                // Mesajele ERP-ului și motivele scrise în admin pot numi clientul.
                'erp_last_error' => ['text'],
                'erp_problems' => ['nul'],
                'edit_unlock_reason' => ['text'],
                'edit_unlocked_by' => ['email'],
                'manual_discount_reason' => ['text'],
            ]],
        'order_items' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'order_id', 'product_id', 'product_name', 'bbd_key', 'quantity', 'unit_price',
            'line_total', 'created_at', 'erp_discount_percent', 'erp_price_before'], 'motive' => ['product_name' => 'numele produsului cumpărat']],
        'order_payment_links' => ['mod' => 'anonimizeaza', 'pastreaza' => ['id', 'order_id', 'referinta', 'amount', 'status', 'procesator',
            'created_at', 'expires_at', 'paid_at'],
            'transforma' => [
                // Un link dintr-un email adevărat nu se deschide niciodată pe copie (aserțiunea A8).
                'token' => ['token'],
                'ep_id' => ['ref'],
                'approval' => ['ref'],
            ]],
        'bt_ipay_transactions' => ['mod' => 'anonimizeaza', 'pastreaza' => ['id', 'order_id', 'link_id', 'kind', 'mode', 'bt_order_number',
            'amount_minor', 'currency', 'approved_minor', 'loy_minor', 'deposited_minor', 'loy_deposited_minor', 'refunded_minor', 'loy_refunded_minor',
            'state', 'bt_status', 'loy_status', 'in_comanda', 'action_code', 'action_desc', 'op_pending', 'deposit_requested_at',
            'deposit_requested_minor', 'deposit_attempts', 'reminder_sent_at', 'authorized_at', 'deposited_at', 'reversed_at', 'refunded_at',
            'last_checked_at', 'public_checked_at', 'callback_checked_at', 'created_at', 'updated_at'],
            'transforma' => [
                'bt_order_id' => ['ref'],
                'loy_order_id' => ['ref'],
                'form_url' => ['nul'],
                'approval_code' => ['nul'],
                'refund_reference' => ['nul'],
                'last_error' => ['text'],
                'alerts_json' => ['nul'],
                // Răspunsul complet al băncii: are datele cardului și ale titularului.
                'last_status_json' => ['nul'],
            ]],
        'loyalty_points_transactions' => ['mod' => 'anonimizeaza', 'pastreaza' => ['id', 'user_id', 'order_id', 'admin_id', 'tx_type', 'points_delta',
            'balance_after', 'created_at'],
            'transforma' => [
                'reason' => ['valoare', 'valoare' => 'Ajustare manuală', 'daca_nenul' => 'admin_id'],
                'meta_json' => ['nul'],
            ]],
        'order_promo_items' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'order_id', 'promo_product_id', 'name', 'quantity', 'created_at'],
            'motive' => ['name' => 'numele produsului promoțional dat']],
        // Ce a confirmat clientul pe comandă (Bioscem 5f2e483): textele avertismentului produselor, nu ale clientului.
        'order_product_warnings' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'order_id', 'title', 'body', 'checkbox_label', 'checkbox_ticked',
            'product_ids_json', 'product_names_json', 'confirmed_at'],
            'emailuri_publice' => 'copia textului public al avertismentului, din momentul comenzii'],

        // ── Recenziile ──
        'product_reviews' => ['mod' => 'anonimizeaza', 'pastreaza' => ['id', 'product_id', 'rating', 'is_approved', 'source', 'created_at'],
            'transforma' => [
                'user_name' => ['nume_client'],
                'user_email' => ['email'],
                // Cele aprobate sunt deja publice pe site și rămân, dar cu adresele de email din text schimbate cu pseudonimul lor
                // (le-a scris clientul; A1 rămâne strictă și pe acest tabel).
                'review_text' => ['text', 'daca' => ['is_approved' => 0]],
            ]],
        'reviews' => ['mod' => 'anonimizeaza', 'pastreaza' => ['id', 'product_id', 'rating', 'is_approved', 'created_at'],
            'transforma' => [
                'user_name' => ['nume_client'],
                // Ca la product_reviews (același text, același rezultat: migrarea recenziilor vechi le potrivește și după text).
                'review_text' => ['text', 'daca' => ['is_approved' => 0]],
            ]],

        // ── Newsletterul ──
        'newsletter_lists' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'name', 'description', 'is_default', 'created_at'],
            'motive' => ['name' => 'numele listei'], 'emailuri_publice' => 'descrierile listelor, scrise de magazin'],
        'newsletter_subscribers' => ['mod' => 'anonimizeaza', 'pastreaza' => ['id', 'status', 'created_at', 'updated_at'],
            'transforma' => [
                'email' => ['email'],
                'name' => ['nume_abonat'],
            ]],
        'newsletter_list_subscribers' => ['mod' => 'copiaza', 'pastreaza' => ['list_id', 'subscriber_id', 'created_at']],
        'newsletter_templates' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'name', 'subject', 'blocks_json', 'html_content', 'is_active',
            'created_at', 'updated_at'], 'motive' => ['name' => 'numele șablonului'],
            'emailuri_publice' => 'șabloanele de newsletter au adresa magazinului'],
        'newsletter_optin_forms' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'name', 'slug', 'list_id', 'button_label', 'success_message',
            'fields_json', 'is_active', 'created_at', 'updated_at'], 'motive' => ['name' => 'numele formularului'],
            'emailuri_publice' => 'textele publice ale formularului de abonare'],
        'newsletter_campaigns' => ['mod' => 'copiaza', 'pastreaza' => ['id', 'name', 'template_type', 'template_ref', 'subscriber_list_id',
            'subscriber_list_ids', 'subject', 'blocks_json', 'html_content', 'status', 'scheduled_at', 'sent_at', 'total_recipients', 'total_sent',
            'total_failed', 'created_at', 'updated_at', 'total_opens', 'total_clicks'], 'motive' => ['name' => 'numele campaniei'],
            'emailuri_publice' => 'campaniile trimise au adresa magazinului'],
        'newsletter_campaign_sends' => ['mod' => 'anonimizeaza', 'pastreaza' => ['id', 'campaign_id', 'subscriber_id', 'status', 'sent_at',
            'created_at', 'opened_at', 'open_count', 'clicked_at', 'click_count'],
            'transforma' => [
                'email' => ['email'],
                // Răspunsul serverului de email poate avea adresa abonatului.
                'error_message' => ['text'],
            ]],
        'newsletter_campaign_opens' => ['mod' => 'anonimizeaza', 'pastreaza' => ['id', 'campaign_id', 'send_id', 'opened_at'],
            'transforma' => ['ip' => ['nul']]],
        // url = linkul din campanie (același pentru toți abonații: NewsletterService::handleClickTrack).
        'newsletter_campaign_clicks' => ['mod' => 'anonimizeaza', 'pastreaza' => ['id', 'campaign_id', 'subscriber_id', 'url', 'clicked_at'],
            'transforma' => [
                'email' => ['email'],
                'ip' => ['nul'],
            ]],

        // ── Mesajele și emailurile trimise ──
        'contact_form_messages' => ['mod' => 'anonimizeaza', 'pastreaza' => ['id', 'status', 'created_at'],
            'transforma' => [
                'name' => ['nume_client'],
                'email' => ['email'],
                'phone' => ['telefon'],
                'subject' => ['text'],
                'message' => ['text'],
                'source_url' => ['url_fara_interogare'],
                'ip_address' => ['nul'],
                'user_agent' => ['nul'],
            ]],
        'email_logs' => ['mod' => 'anonimizeaza', 'pastreaza' => ['id', 'order_id', 'email_type', 'status', 'sent_at', 'created_at'],
            'motive' => ['email_type' => 'felul emailului (de exemplu new_order), nu o adresă'],
            'transforma' => [
                'recipient' => ['emailuri'],
                'subject' => ['subiect', 'din' => 'email_type'],
                'error_message' => ['nul'],
            ]],
        'email_send_history' => ['mod' => 'anonimizeaza', 'pastreaza' => ['id', 'order_id', 'email_type', 'source', 'status', 'provider', 'sent_at',
            'created_at'], 'motive' => ['email_type' => 'felul emailului (de exemplu new_order), nu o adresă'],
            'transforma' => [
                'recipient' => ['emailuri'],
                'subject' => ['subiect', 'din' => 'email_type'],
                'error_message' => ['nul'],
                'meta_json' => ['nul'],
            ]],

        // ── Adminii: adrese inventate, parole aleatoare; contul proprietarului îl face încărcarea ──
        'admins' => ['mod' => 'anonimizeaza', 'pastreaza' => ['id', 'role', 'roles_json', 'created_at'],
            'transforma' => [
                'email' => ['email_admin'],
                'password_hash' => ['parola'],
            ]],
    ],

    // Neutralizarea setărilor (planul de verificare §3.5.2), în drum (valorile site-ului adevărat nu ajung în baza copiei)
    // și încă o dată după migrări; aserțiunea A6 le verifică pe toate. Cheile lipsă se scriu, ca aplicația să nu cadă pe
    // valorile din cod.
    'setari' => [
        // Golite: cheile, parolele și id-urile conturilor externe; plus orice cheie care se potrivește tiparului.
        'goale_tipar' => '/(password|secret|api_key|_token$|client_secret)/',
        'goale' => ['fan_client_id', 'fan_api_username', 'fan_api_password', 'smtp_host', 'smtp_username', 'smtp_password', 'sendgrid_api_key',
            'stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_secret', 'euplatesc_merchant_id', 'euplatesc_secret_key', 'erp_url',
            'erp_api_key', 'customer_google_client_id', 'customer_google_client_secret', 'tawk_property_id', 'tawk_api_key', 'google_analytics_id',
            'google_analytics_code', 'google_tag_manager_id', 'google_tag_manager_head_code', 'google_tag_manager_body_code', 'google_ads_conversion_id',
            'google_ads_conversion_label', 'microsoft_clarity_project_id', 'google_site_verification',
            // În plus față de plan (WP-09): adresele IP ale celor care văd site-ul în mentenanță, contul colector al rambursurilor,
            // persoana și telefonul expeditorului FAN (AWB-urile sunt oricum oprite pe copie).
            'maintenance_allowed_ips', 'fan_cod_iban', 'fan_sender_name', 'fan_sender_phone'],
        'zero' => ['erp_enabled', 'erp_stock_enabled', 'euplatesc_enabled', 'stripe_enabled', 'bt_ipay_enabled', 'fan_awb_auto', 'fan_live_tariff_enabled',
            'customer_google_auth_enabled', 'tawk_enabled', 'microsoft_clarity_enabled', 'google_analytics_enabled', 'google_tag_manager_enabled',
            'google_ads_enabled'],
        // Adresa proprietarului (VERIFICARE_EMAIL).
        'proprietar' => ['contact_form_recipients', 'bt_ipay_notify_emails', 'fan_sender_email'],
        'proprietar_tipar' => '/^email_template_[a-z0-9_]+_admin_recipients$/',
        // Căsuța de trimitere a copiei (VERIFICARE_SMTP_UTILIZATOR), altfel o adresă care nu primește nimic.
        'casuta' => ['order_email_from_address'],
        // „VERIFICARE ” + valoarea de dinainte.
        'prefix' => ['order_email_from_name'],
        // O cheie nouă, a copiei (din sarea încărcării; aceeași la --reia).
        'aleatoare' => ['maintenance_key'],
    ],
];
