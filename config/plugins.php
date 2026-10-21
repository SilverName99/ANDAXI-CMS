<?php

declare(strict_types=1);

/**
 * Catalogul pluginurilor: singurul loc în care scrie ce plugin are fiecare
 * bucată de site (rute, meniu, pagini, cronuri, tabele).
 *
 * Ce plugin e pornit pe un site NU se hotărăște aici, ci în licența semnată de
 * CRM (`storage/licenta/licenta.json`, vezi `App\Support\Licenta`). Aici e doar
 * harta: „ruta asta e a magazinului”, „intrarea asta din meniu e a blogului”.
 *
 * Câmpurile unui plugin:
 *  - nume, descriere: textele în română (pentru CRM și pentru oameni);
 *  - necesita: de ce alte pluginuri are nevoie; o listă în listă înseamnă
 *    „cel puțin unul dintre” (ex. linkul de plată merge cu oricare plată cu cardul);
 *  - bani: pluginul atinge bani (comenzi, plăți, donații). La CRM: trece în
 *    „oprit” cel mai devreme după 30 de zile de „se oprește”;
 *  - intern: unealtă doar pentru ANDAXI, nu se vinde niciodată;
 *  - client: funcție făcută pentru un singur client (devenită plugin ca oricare altul);
 *  - portat: false = pluginul există în alt site și codul lui nu e încă adus aici
 *    (sursa spune de unde vine); până atunci nu are nicio rută, meniu sau cron;
 *  - rute_admin, rute_publice: rutele pluginului. Un tipar fără „/*” la final e
 *    o rută exactă; cu „/*” acoperă ruta și tot ce e sub ea. Se poate pune metoda
 *    în față („POST /cale”). Parametrii se scriu ca în router („{id}”), numele lor
 *    nu contează. Câștigă declarația cea mai precisă (ex. `/admin/products/reviews/*`
 *    a recenziilor bate `/admin/products/*` a catalogului);
 *  - rute_mereu_deschise: adrese la care răspund procesatorii de plăți, ERP-ul sau
 *    linkurile din emailurile deja trimise. Răspund ORICE stare ar avea pluginul
 *    (cu pluginul oprit, cererea se notează în jurnal), altfel o plată sau o
 *    notificare deja pornită s-ar pierde;
 *  - pagini: pagini din baza de date (slug) care sunt ale pluginului;
 *  - tokenuri: codurile din paginile scrise în admin („{{shop_catalog}}”,
 *    „{{latest_posts:3}}” …) care afișează bucăți ale pluginului. Cu pluginul
 *    oprit, codul se scoate din pagină (Plugins::curataTokenuri);
 *  - meniu: intrările lui din meniul adminului (grup, ordine în grup, etichetă,
 *    adresă, iconiță, regula „activ”: listă de prefixe sau o funcție);
 *  - cron: scripturile lui programate și ce stare cer: „vizibil” (pornit sau se
 *    oprește: termină ce e în curs) sau „pornit” (pornește ceva nou);
 *  - tabele: unde stau datele lui (nu se șterg niciodată): „tabel” sau
 *    „tabel.coloană*” (coloanele unui plugin pe tabelul altuia, ex. `orders.fan_*`).
 *
 * Verificare: `tools/dev/verifica-rute.php` cere ca fiecare rută din router să
 * aibă aici un proprietar (nucleu sau plugin) și fiecare declarație să
 * corespundă unei rute. `tools/dev/verifica-migrari.php` cere ca fiecare tabel,
 * coloană și index pe care îl face o migrare să fie, după `tabele`, al
 * proprietarului ei (dosarul ei din database/migrari/).
 */

// Regula „activ” pentru intrările din meniu care nu sunt simple prefixe.
// Primește verificatorul de prefixe al meniului, calea curentă și tab-ul din URL (null dacă lipsește).
$activExact = static fn (string ...$cai): Closure => static fn (callable $prefix, string $cale, ?string $tab): bool => in_array($cale, $cai, true);
$activTab = static fn (string $tabCerut): Closure => static fn (callable $prefix, string $cale, ?string $tab): bool
    => $prefix(['/admin/emails/newsletters']) && ($tab ?? 'templates') === $tabCerut;

return [
    /**
     * Ordinea din meniul adminului. „link” = o singură intrare pe primul nivel;
     * „grup” = grup care se deschide, cu intrările pluginurilor în el. Un grup
     * fără nicio intrare vizibilă nu apare deloc.
     */
    'meniu_admin' => [
        ['cheie' => 'dashboard', 'tip' => 'link'],
        ['cheie' => 'utilizatori', 'tip' => 'grup', 'eticheta' => 'Utilizatori', 'icon' => 'users',
            'deschis' => ['/admin/users']],
        ['cheie' => 'magazin', 'tip' => 'grup', 'eticheta' => 'Magazin', 'icon' => 'store',
            'deschis' => ['/admin/products', '/admin/orders', '/admin/coupons', '/admin/categories', '/admin/promo-products',
                '/admin/settings/floating-cart', '/admin/products/fields', '/admin/products/templates', '/admin/products/reviews']],
        ['cheie' => 'configurare', 'tip' => 'grup', 'eticheta' => 'Configurare', 'icon' => 'settings',
            'deschis' => ['/admin/settings/store', '/admin/settings/shipping', '/admin/settings/payments', '/admin/settings/erp',
                '/admin/settings/mannequin', '/admin/settings/admins']],
        ['cheie' => 'jurnal', 'tip' => 'link'],
        ['cheie' => 'pagini', 'tip' => 'link'],
        ['cheie' => 'galerie', 'tip' => 'link'],
        ['cheie' => 'design', 'tip' => 'link'],
        ['cheie' => 'blog', 'tip' => 'grup', 'eticheta' => 'Blog', 'icon' => 'blog',
            'deschis' => ['/admin/blog/posts', '/admin/blog/categories', '/admin/blog/templates', '/admin/blog/authors']],
        ['cheie' => 'emailuri', 'tip' => 'grup', 'eticheta' => 'Setări email', 'icon' => 'emails',
            'deschis' => ['/admin/emails/sender', '/admin/emails/test']],
        ['cheie' => 'automatizari', 'tip' => 'link'],
        ['cheie' => 'newslettere', 'tip' => 'grup', 'eticheta' => 'Email-uri', 'icon' => 'newsletter',
            'deschis' => ['/admin/emails/newsletters']],
    ],

    /** Ce are orice site, indiferent de licență. */
    'nucleu' => [
        'nume' => 'Nucleu',
        'descriere' => 'Pagini cu editor și SEO, antet, subsol și meniu, galerie, harta site-ului, modul „Revenim în curând”, '
            . 'memoria rapidă, bannerul de cookie cu Google și Clarity după acord, trimiterea emailurilor cu istoric, '
            . 'formularul de contact, administratori cu roluri și jurnal, protecția anti-robot, popupul de anunț.',
        'rute_admin' => [
            '/admin', '/admin/login', '/admin/logout',
            '/admin/settings/store', '/admin/settings/admins', '/admin/settings/google',
            '/admin/activity-log',
            '/admin/pages/*', '/admin/gallery/*', '/admin/design/*',
            '/admin/emails', '/admin/emails/test', '/admin/emails/history/*', '/admin/emails/contact-recipients',
        ],
        // /_andaxi/*: adresele platformei (pingul monitorizării; în Etapa 2 și preluarea licenței).
        'rute_publice' => ['/', '/health', '/_andaxi/*', '/robots\.txt', '/sitemap\.xml', '/contact/*', '/{slug*}'],
        'meniu' => [
            ['grup' => 'dashboard', 'ordine' => 10, 'eticheta' => 'Dashboard', 'href' => '/admin', 'icon' => 'dashboard',
                'activ' => static fn (callable $prefix, string $cale, ?string $tab): bool => $prefix(['/admin'])
                    && !$prefix(['/admin/users', '/admin/products', '/admin/categories', '/admin/orders', '/admin/settings',
                        '/admin/pages', '/admin/gallery', '/admin/design', '/admin/emails'])],
            // Încercările de înregistrare (conturi de clienți) și de comandă (magazin), cu protecția anti-robot.
            ['grup' => 'utilizatori', 'ordine' => 40, 'eticheta' => 'Securitate formulare', 'href' => '/admin/users/security',
                'icon' => 'shield', 'activ' => ['/admin/users/security'], 'oricare_din' => ['magazin', 'conturi_clienti']],
            // Pagina e a nucleului (SEO, cache, mentenanță…); fără magazin pe site se numește „Setări site”.
            ['grup' => 'configurare', 'ordine' => 10, 'eticheta' => 'Setări magazin', 'href' => '/admin/settings/store',
                'icon' => 'settings', 'activ' => ['/admin/settings/store'], 'eticheta_fara' => ['magazin' => 'Setări site']],
            ['grup' => 'configurare', 'ordine' => 60, 'eticheta' => 'Google', 'href' => '/admin/settings/google',
                'icon' => 'settings', 'activ' => ['/admin/settings/google']],
            ['grup' => 'configurare', 'ordine' => 70, 'eticheta' => 'Administratori', 'href' => '/admin/settings/admins',
                'icon' => 'users', 'activ' => ['/admin/settings/admins']],
            ['grup' => 'jurnal', 'ordine' => 10, 'eticheta' => 'Jurnal activitate', 'href' => '/admin/activity-log',
                'icon' => 'shield', 'activ' => ['/admin/activity-log']],
            ['grup' => 'pagini', 'ordine' => 10, 'eticheta' => 'Pagini', 'href' => '/admin/pages', 'icon' => 'pages',
                'activ' => ['/admin/pages']],
            ['grup' => 'galerie', 'ordine' => 10, 'eticheta' => 'Galerie', 'href' => '/admin/gallery', 'icon' => 'gallery',
                'activ' => ['/admin/gallery']],
            ['grup' => 'design', 'ordine' => 10, 'eticheta' => 'Header/Footer/Meniu', 'href' => '/admin/design',
                'icon' => 'design', 'activ' => ['/admin/design']],
            ['grup' => 'emailuri', 'ordine' => 10, 'eticheta' => 'Setări trimitere', 'href' => '/admin/emails/sender',
                'icon' => 'emails', 'activ' => ['/admin/emails/sender']],
            ['grup' => 'emailuri', 'ordine' => 20, 'eticheta' => 'Trimite email test', 'href' => '/admin/emails/test',
                'icon' => 'orders', 'activ' => ['/admin/emails/test'], 'icon_fara' => ['magazin' => 'emails']],
            ['grup' => 'newslettere', 'ordine' => 50, 'eticheta' => 'Formulare contact',
                'href' => '/admin/emails/newsletters?tab=contact_forms', 'icon' => 'emails', 'activ' => $activTab('contact_forms')],
            ['grup' => 'newslettere', 'ordine' => 70, 'eticheta' => 'Istoric trimitere',
                'href' => '/admin/emails/newsletters?tab=history', 'icon' => 'emails', 'activ' => $activTab('history')],
        ],
        'tabele' => ['admins', 'settings', 'pages', 'seo_pages', 'gallery_images', 'gallery_folders', 'contact_form_messages',
            'email_send_history', 'admin_activity_log', 'checkout_submit_logs', 'register_submit_logs'],
    ],

    'pluginuri' => [
        'catalog' => [
            'nume' => 'Catalog produse',
            'descriere' => 'Produse, categorii, câmpuri suplimentare, șabloane de pagină de produs, mărci, etichete și căutare.',
            'necesita' => [],
            'rute_admin' => ['/admin/products/*', '/admin/categories/*'],
            'rute_publice' => ['/magazin', '/categorie/*', '/marca/*', '/eticheta/*', '/produs/*',
                '/api/products/*', '/api/search/*', '/api/shop/*'],
            'tokenuri' => ['shop_catalog', 'shop_category', 'shop_title', 'featured_products', 'product_categories'],
            'meniu' => [
                ['grup' => 'magazin', 'ordine' => 10, 'eticheta' => 'Produse', 'href' => '/admin/products', 'icon' => 'products',
                    'activ' => $activExact('/admin/products')],
                ['grup' => 'magazin', 'ordine' => 20, 'eticheta' => 'Categorii produse', 'href' => '/admin/categories',
                    'icon' => 'categories', 'activ' => ['/admin/categories']],
                ['grup' => 'magazin', 'ordine' => 30, 'eticheta' => 'Câmpuri suplimentare', 'href' => '/admin/products/fields',
                    'icon' => 'settings', 'activ' => ['/admin/products/fields']],
                ['grup' => 'magazin', 'ordine' => 40, 'eticheta' => 'Template-uri produse', 'href' => '/admin/products/templates',
                    'icon' => 'design', 'activ' => ['/admin/products/templates']],
            ],
            'tabele' => ['products', 'product_categories', 'product_category_links', 'product_extra_fields',
                'product_extra_field_values', 'product_templates', 'product_template_fields'],
        ],

        'magazin' => [
            'nume' => 'Magazin online',
            'descriere' => 'Coș, finalizarea comenzii (checkout), comenzi, plata ramburs și prin ordin de plată, emailurile de comandă, '
                . 'coșul abandonat și coșul flotant, avertismentul pe produs (ex. refrigerat) și fluxul de produse pentru Google Merchant Center.',
            'necesita' => ['catalog'],
            'bani' => true,
            'rute_admin' => ['/admin/orders/*', '/admin/settings/floating-cart'],
            // Fluxul Merchant Center are prețuri și disponibilitate: e al magazinului, nu al catalogului (WP-R).
            'rute_publice' => ['/cos/*', '/api/cart/*', '/api/checkout/*', '/checkout', '/checkout/succes/*', '/feed/google-merchant\.xml'],
            // EuPlătesc trimite clientul înapoi prin POST, cu rezultatul semnat al plății.
            'rute_mereu_deschise' => ['POST /checkout/succes/{orderNumber}'],
            'pagini' => ['checkout/succes'],
            'tokenuri' => ['cart_form', 'checkout_form', 'checkout_success_order_info', 'cart_count'],
            'meniu' => [
                ['grup' => 'magazin', 'ordine' => 50, 'eticheta' => 'Comenzi', 'href' => '/admin/orders', 'icon' => 'orders',
                    'activ' => $activExact('/admin/orders', '/admin/orders/trash')],
                ['grup' => 'magazin', 'ordine' => 100, 'eticheta' => 'Coș flotant', 'href' => '/admin/settings/floating-cart',
                    'icon' => 'store', 'activ' => ['/admin/settings/floating-cart']],
                // Pagina de plăți are și ordinul de plată (al magazinului), și procesatorii de card.
                ['grup' => 'configurare', 'ordine' => 30, 'eticheta' => 'Setări plăți', 'href' => '/admin/settings/payments',
                    'icon' => 'payments', 'activ' => ['/admin/settings/payments'],
                    'oricare_din' => ['magazin', 'plata_stripe', 'plata_euplatesc', 'plata_bt']],
                ['grup' => 'automatizari', 'ordine' => 10, 'eticheta' => 'Automatizări', 'href' => '/admin/emails/automation',
                    'icon' => 'automation', 'activ' => ['/admin/emails/automation']],
                ['grup' => 'newslettere', 'ordine' => 20, 'eticheta' => 'Email-uri ecommerce',
                    'href' => '/admin/emails/newsletters?tab=ecommerce', 'icon' => 'orders', 'activ' => $activTab('ecommerce')],
            ],
            // Emailurile de coș abandonat invită clientul să termine comanda: nu și cu checkout-ul închis.
            'cron' => ['scripts/abandoned-cart-emails.php' => 'pornit'],
            // Avertismentul pe produs (WP-R, din Bioscem 0582ce9): textele pe produs și ce a confirmat clientul pe comandă.
            'tabele' => ['orders', 'order_items', 'email_logs', 'cart_abandonments', 'product_warnings', 'order_product_warnings'],
        ],

        'livrare_fan' => [
            'nume' => 'Livrare FAN Courier',
            'descriere' => 'Tarif, AWB, urmărirea coletelor, FANbox și nomenclatorul de localități și străzi FAN.',
            'necesita' => ['magazin'],
            'rute_admin' => ['/admin/settings/shipping/*', '/admin/orders/{id}/fan-awb', '/admin/orders/{id}/fan-awb-preview',
                '/admin/orders/{id}/fan-tracking', '/admin/orders/{id}/fanbox'],
            'rute_publice' => ['/api/fan/*'],
            'meniu' => [
                ['grup' => 'configurare', 'ordine' => 20, 'eticheta' => 'Setări livrare', 'href' => '/admin/settings/shipping',
                    'icon' => 'shipping', 'activ' => ['/admin/settings/shipping']],
            ],
            'cron' => [
                'scripts/fan-tracking-sync.php' => 'vizibil',
                'scripts/fan-lockers-sync.php' => 'vizibil',
                'scripts/fan-nomenclator-sync.php' => 'vizibil',
            ],
            'tabele' => ['fan_lockers', 'fan_localities', 'fan_localities_extra_km', 'fan_streets', 'fan_nomenclator_sync', 'orders.fan_*',
                'orders.completed_awb_email_*'],
        ],

        'plata_stripe' => [
            'nume' => 'Plată cu cardul – Stripe',
            'descriere' => 'Plata cu cardul prin Stripe și notificările trimise de Stripe.',
            'necesita' => [],
            'bani' => true,
            'rute_mereu_deschise' => ['POST /webhook/stripe'],
            'tabele' => ['orders.stripe_*'],
        ],

        'plata_euplatesc' => [
            'nume' => 'Plată cu cardul – EuPlătesc',
            'descriere' => 'Plata cu cardul prin EuPlătesc și notificările (IPN) trimise de EuPlătesc.',
            'necesita' => ['magazin'],
            'bani' => true,
            'rute_mereu_deschise' => ['POST /webhook/euplatesc'],
            'tabele' => ['orders.euplatesc_*'],
        ],

        'plata_bt' => [
            'nume' => 'Plată Banca Transilvania',
            'descriere' => 'Plata prin Banca Transilvania iPay, cu rate și puncte STAR: suma se blochează pe card la comandă și se '
                . 'încasează la aprobarea comenzii (sau automat după 96 de ore).',
            'necesita' => ['magazin'],
            'bani' => true,
            'rute_admin' => ['/admin/settings/payments/bt/*', '/admin/orders/{id}/bt/*'],
            // Întoarcerea clientului de la bancă verifică plata la bancă; trebuie să meargă oricând.
            'rute_mereu_deschise' => ['POST /webhook/bt-ipay', 'GET /checkout/bt/retur'],
            'cron' => ['scripts/bt-ipay-sync.php' => 'vizibil'],
            'tabele' => ['bt_ipay_transactions', 'bt_ipay_log'],
        ],

        'link_plata' => [
            'nume' => 'Link de plată pentru diferențe',
            'descriere' => 'Clientul plătește cu cardul, dintr-un link primit pe email, diferența rămasă la o comandă.',
            'necesita' => ['magazin', ['plata_euplatesc', 'plata_bt', 'plata_stripe']],
            'bani' => true,
            'rute_admin' => ['/admin/orders/{id}/payment-link'],
            'rute_publice' => ['/plata/*'],
            'tabele' => ['order_payment_links'],
        ],

        'erp' => [
            'nume' => 'Legătura cu ERP-ul ANDAXI',
            'descriere' => 'Comenzile și stocul cu ERP-ul, retururile, aprobarea comenzilor din ERP, „client agresiv”.',
            'necesita' => ['magazin'],
            // Comenzile pleacă în ERP (facturi, încasări): la CRM, ca orice plugin cu bani (OD-36, D7).
            'bani' => true,
            'rute_admin' => ['/admin/settings/erp', '/admin/orders/{id}/erp-retry'],
            'rute_mereu_deschise' => ['POST /api/erp/notificare', 'POST /api/erp/adrese'],
            'meniu' => [
                ['grup' => 'configurare', 'ordine' => 40, 'eticheta' => 'ERP ANDAXI', 'href' => '/admin/settings/erp',
                    'icon' => 'settings', 'activ' => ['/admin/settings/erp']],
            ],
            'cron' => ['scripts/erp-sync.php' => 'vizibil'],
            'tabele' => ['orders.erp_*', 'order_items.erp_*'],
        ],

        'cupoane' => [
            'nume' => 'Cupoane',
            'descriere' => 'Cupoane de reducere și coduri unice încărcate din Excel.',
            'necesita' => ['magazin'],
            'rute_admin' => ['/admin/coupons/*'],
            'rute_publice' => ['/cos/cupon/*', '/api/cart/coupon/*'],
            'meniu' => [
                ['grup' => 'magazin', 'ordine' => 60, 'eticheta' => 'Cupoane', 'href' => '/admin/coupons', 'icon' => 'coupon',
                    'activ' => $activExact('/admin/coupons')],
                ['grup' => 'magazin', 'ordine' => 70, 'eticheta' => 'Cupoane unice', 'href' => '/admin/coupons/unique',
                    'icon' => 'coupon', 'activ' => ['/admin/coupons/unique']],
            ],
            'tabele' => ['coupons'],
        ],

        'fidelitate' => [
            'nume' => 'Puncte de fidelitate',
            'descriere' => 'Clienții câștigă puncte la comenzi și le folosesc la comenzile următoare.',
            'necesita' => ['magazin', 'conturi_clienti'],
            'rute_admin' => ['/admin/users/points/*'],
            'rute_publice' => ['/cos/puncte/*'],
            'meniu' => [
                ['grup' => 'utilizatori', 'ordine' => 30, 'eticheta' => 'Puncte fidelitate', 'href' => '/admin/users/points',
                    'icon' => 'stats',
                    'activ' => static fn (callable $prefix, string $cale, ?string $tab): bool => $prefix(['/admin/users/points'])
                        || ($prefix(['/admin/users/settings']) && ($tab ?? 'settings') === 'points')],
            ],
            'tabele' => ['loyalty_points_transactions', 'loyalty_points_rules', 'users.loyalty_points', 'orders.loyalty_*'],
        ],

        'recenzii' => [
            'nume' => 'Recenzii produse',
            'descriere' => 'Formular de recenzie, moderare și protecție anti-spam.',
            'necesita' => ['catalog'],
            'rute_admin' => ['/admin/products/reviews/*'],
            'rute_publice' => ['/produs/{slug}/review', '/review-form/*'],
            'tokenuri' => ['product_review_form'],
            'meniu' => [
                ['grup' => 'magazin', 'ordine' => 80, 'eticheta' => 'Recenzii', 'href' => '/admin/products/reviews',
                    'icon' => 'star', 'activ' => ['/admin/products/reviews']],
            ],
            'tabele' => ['product_reviews'],
        ],

        'precomenzi' => [
            'nume' => 'Precomenzi',
            'descriere' => 'Vânzare fără stoc, cu limite pe comandă și în total; comenzile așteaptă eliberarea spre ERP.',
            'necesita' => ['magazin'],
            'rute_admin' => ['/admin/orders/{id}/precomanda-elibereaza'],
            'tabele' => ['products.preorder_*', 'orders.preorder_*'],
        ],

        'oferte_bbd' => [
            'nume' => 'Oferte BBD',
            'descriere' => 'Loturi cu dată minimă de valabilitate și preț propriu, alese înainte de adăugarea în coș; panoul lateral „Oferte”.',
            'necesita' => ['magazin'],
            'tabele' => ['products.bbd_*', 'order_items.bbd_key'],
        ],

        'produse_promo' => [
            'nume' => 'Produse promoționale',
            'descriere' => 'Mostre și cadouri adăugate în comenzi, cu raport pe client.',
            'necesita' => ['magazin'],
            'rute_admin' => ['/admin/promo-products/*', '/admin/orders/{id}/promo', '/admin/orders/{id}/client-promo'],
            'meniu' => [
                ['grup' => 'magazin', 'ordine' => 90, 'eticheta' => 'Produse promoționale', 'href' => '/admin/promo-products',
                    'icon' => 'coupon', 'activ' => ['/admin/promo-products']],
            ],
            'tabele' => ['promotional_products', 'order_promo_items'],
        ],

        'pachete_produse' => [
            'nume' => 'Pachete de produse',
            'descriere' => 'Produse vândute împreună, ca pachet.',
            'necesita' => ['magazin'],
            'portat' => false,
            'sursa' => 'Biovitality',
            'tabele' => ['products.bundle_items_json'],
        ],

        'conturi_clienti' => [
            'nume' => 'Conturi clienți',
            'descriere' => 'Înregistrare, autentificare (și cu Google), adrese, resetarea parolei, lista clienților în admin.',
            'necesita' => [],
            'rute_admin' => ['/admin/users/*'],
            'rute_publice' => ['/contul-meu/*', '/login', '/register', '/auth/google/*'],
            'pagini' => ['resetare-parola'],
            'tokenuri' => ['account_section', 'auth_google_button'],
            'meniu' => [
                ['grup' => 'utilizatori', 'ordine' => 10, 'eticheta' => 'Listă utilizatori', 'href' => '/admin/users', 'icon' => 'users',
                    'activ' => static fn (callable $prefix, string $cale, ?string $tab): bool => $prefix(['/admin/users'])
                        && !$prefix(['/admin/users/settings', '/admin/users/points', '/admin/users/security', '/admin/users/gdpr-agreements'])],
                ['grup' => 'utilizatori', 'ordine' => 20, 'eticheta' => 'Setări utilizatori', 'href' => '/admin/users/settings',
                    'icon' => 'settings',
                    'activ' => static fn (callable $prefix, string $cale, ?string $tab): bool => $prefix(['/admin/users/settings'])
                        && ($tab ?? 'settings') !== 'points'],
            ],
            'tabele' => ['users', 'user_addresses', 'customer_password_resets'],
        ],

        'blog' => [
            'nume' => 'Blog',
            'descriere' => 'Articole, categorii, autori, șabloane de articol și evenimente.',
            'necesita' => [],
            'rute_admin' => ['/admin/blog/*'],
            'rute_publice' => ['/blog/*', '/api/blog/*'],
            'tokenuri' => ['blog_posts', 'latest_posts', 'category_posts', 'category_grid', 'category_events', 'next_event_image',
                'next_event_url', 'next_event_title', 'next_event_excerpt', 'next_event_period', 'next_event_video'],
            'meniu' => [
                ['grup' => 'blog', 'ordine' => 10, 'eticheta' => 'Postări blog', 'href' => '/admin/blog/posts', 'icon' => 'pages',
                    'activ' => ['/admin/blog/posts']],
                // Ca în Bioscem: „Categorii” apare cui are voie la postări.
                ['grup' => 'blog', 'ordine' => 20, 'eticheta' => 'Categorii', 'href' => '/admin/blog/categories', 'icon' => 'pages',
                    'activ' => ['/admin/blog/categories'], 'permisiune' => '/admin/blog/posts'],
                ['grup' => 'blog', 'ordine' => 30, 'eticheta' => 'Template blog', 'href' => '/admin/blog/templates', 'icon' => 'design',
                    'activ' => ['/admin/blog/templates']],
                ['grup' => 'blog', 'ordine' => 40, 'eticheta' => 'Autori blog', 'href' => '/admin/blog/authors', 'icon' => 'users',
                    'activ' => ['/admin/blog/authors']],
            ],
            'tabele' => ['blog_posts', 'blog_categories', 'blog_post_categories', 'blog_authors', 'blog_templates'],
        ],

        'newsletter' => [
            'nume' => 'Newsletter',
            'descriere' => 'Liste de abonați, formulare de abonare, campanii și statistici.',
            'necesita' => [],
            'rute_publice' => ['/newsletter/*'],
            // Linkurile din newsletterele deja trimise (dezabonare, urmărire) trebuie să meargă mereu.
            'rute_mereu_deschise' => [
                'GET /newsletter/unsubscribe/{token}',
                'GET /newsletter/track/open/{campaignId}/{subscriberId}/{token}',
                'GET /newsletter/track/click/{campaignId}/{subscriberId}/{token}',
            ],
            'meniu' => [
                ['grup' => 'newslettere', 'ordine' => 10, 'eticheta' => 'Template-uri',
                    'href' => '/admin/emails/newsletters?tab=templates', 'icon' => 'pages', 'activ' => $activTab('templates')],
                ['grup' => 'newslettere', 'ordine' => 30, 'eticheta' => 'Liste abonați',
                    'href' => '/admin/emails/newsletters?tab=subscribers', 'icon' => 'dashboard', 'activ' => $activTab('subscribers')],
                ['grup' => 'newslettere', 'ordine' => 40, 'eticheta' => 'Formulare opt-in',
                    'href' => '/admin/emails/newsletters?tab=optin', 'icon' => 'emails', 'activ' => $activTab('optin')],
                ['grup' => 'newslettere', 'ordine' => 60, 'eticheta' => 'Statistici',
                    'href' => '/admin/emails/newsletters?tab=stats', 'icon' => 'stats', 'activ' => $activTab('stats')],
            ],
            'cron' => ['scripts/newsletter-campaigns.php' => 'vizibil'],
            'tabele' => ['newsletter_templates', 'newsletter_lists', 'newsletter_subscribers', 'newsletter_list_subscribers',
                'newsletter_campaigns', 'newsletter_campaign_sends', 'newsletter_optin_forms', 'newsletter_campaign_opens',
                'newsletter_campaign_clicks', 'orders.nl_campaign_id'],
        ],

        'chat_live' => [
            'nume' => 'Chat live',
            'descriere' => 'Fereastra de chat tawk.to pe site, pornită doar după acordul pentru cookie-uri.',
            'necesita' => [],
            'tabele' => [],
        ],

        'donatii' => [
            'nume' => 'Donații',
            'descriere' => 'Donații o dată sau lunar, cu istoric în contul donatorului.',
            'necesita' => ['plata_stripe'],
            'bani' => true,
            'portat' => false,
            'sursa' => 'vertij-tinitus.ro',
            'tabele' => ['donations'],
        ],

        'membri' => [
            'nume' => 'Membri',
            'descriere' => 'Categorii de membri, documente, aprobare, cotizație pe 1–12 luni și pagini doar pentru membri.',
            'necesita' => ['conturi_clienti', 'plata_stripe'],
            'bani' => true,
            'portat' => false,
            'sursa' => 'vertij-tinitus.ro',
            'tabele' => ['professions', 'profession_fields', 'account_verifications', 'account_verification_documents',
                'account_activations'],
        ],

        'cereri_oferta' => [
            'nume' => 'Cereri de ofertă',
            'descriere' => 'Buton pe produs, formular de cerere de ofertă și lista cererilor în admin.',
            'necesita' => ['catalog'],
            'portat' => false,
            'sursa' => 'grafoanaytis.ro',
            'tabele' => ['quote_requests'],
        ],

        'fisiere_cloud' => [
            'nume' => 'Fișiere în cloud',
            'descriere' => 'Foldere în cloud, cu parolă și versiuni, plus aplicația Windows.',
            'necesita' => [],
            'client' => true,
            'portat' => false,
            'sursa' => 'Biovitality',
            'tabele' => ['admin_app_tokens', 'bunny_*', 'file_locks', 'file_versions'],
        ],

        'concurenta' => [
            'nume' => 'Analiza prețurilor concurenței',
            'descriere' => 'Prețurile concurenților, comparate cu ale magazinului.',
            'necesita' => [],
            'client' => true,
            'portat' => false,
            'sursa' => 'Biovitality',
            'tabele' => ['competition_partners', 'competition_prices'],
        ],

        'manechin' => [
            'nume' => 'Manechin',
            'descriere' => 'Clientul alege o zonă a corpului pe un manechin și vede produsele recomandate.',
            'necesita' => ['catalog'],
            'client' => true,
            'rute_admin' => ['/admin/settings/mannequin'],
            'tokenuri' => ['mannequin_section'],
            'meniu' => [
                ['grup' => 'configurare', 'ordine' => 50, 'eticheta' => 'Configurare manechin', 'href' => '/admin/settings/mannequin',
                    'icon' => 'design', 'activ' => ['/admin/settings/mannequin']],
            ],
            'tabele' => ['settings.mannequin_points_json'],
        ],

        'acorduri_gdpr' => [
            'nume' => 'Acorduri GDPR semnate',
            'descriere' => 'Formular de acord semnat de medici, cu export Excel.',
            'necesita' => [],
            'client' => true,
            'rute_admin' => ['/admin/users/gdpr-agreements/*', '/admin/pages/gdpr-agreements/*'],
            'rute_publice' => ['/gdpr-agreements/*'],
            'pagini' => ['acorduri-gdpr'],
            'tokenuri' => ['gdpr_agreements_form'],
            'meniu' => [
                ['grup' => 'utilizatori', 'ordine' => 50, 'eticheta' => 'Acorduri GDPR', 'href' => '/admin/users/gdpr-agreements',
                    'icon' => 'pages', 'activ' => ['/admin/users/gdpr-agreements']],
            ],
            'tabele' => ['gdpr_agreements'],
        ],

        'migrare_wordpress' => [
            'nume' => 'Migrare din WordPress',
            'descriere' => 'Unealtă internă ANDAXI: importul clienților și al abonaților dintr-o copie WordPress.',
            'necesita' => [],
            'intern' => true,
            'rute_admin' => ['POST /admin/users/import-backup'],
            'tabele' => [],
        ],
    ],

    /**
     * Rute folosite de mai multe pluginuri, al căror proprietar depinde de un
     * parametru al cererii (din rută, din adresă sau din formular). Regulile se
     * citesc în ordine; prima care se potrivește câștigă. O regulă fără condiții
     * se potrivește mereu. O listă de pluginuri înseamnă „oricare dintre ele”.
     * O valoare terminată în „*” e un prefix.
     */
    'rute_impartite' => [
        'GET /admin/emails/{section}' => [
            [['ruta.section' => ['automation']], 'magazin'],
            [['ruta.section' => ['newsletters'], 'get.tab' => ['ecommerce']], 'magazin'],
            [['ruta.section' => ['newsletters'], 'get.tab' => ['contact_forms', 'history']], 'nucleu'],
            [['ruta.section' => ['newsletters']], 'newsletter'],
            [[], 'nucleu'],
        ],
        'POST /admin/emails' => [
            [['post.section' => ['template-single', 'template-toggle', 'automation',
                'newsletter-ecommerce-save', 'newsletter-ecommerce-send-test']], 'magazin'],
            [['post.section' => ['newsletter-*']], 'newsletter'],
            [[], 'nucleu'],
        ],
        'GET /admin/emails/builder' => [
            [['get.type' => ['ecommerce']], 'magazin'],
            [[], 'newsletter'],
        ],
        'POST /admin/emails/builder' => [
            [['post.builder_type' => ['ecommerce']], 'magazin'],
            [[], 'newsletter'],
        ],
        'POST /admin/emails/builder/preview' => [
            [[], ['newsletter', 'magazin']],
        ],
        // Jurnalul anti-robot al formularelor: înregistrarea e a conturilor, checkout-ul al magazinului.
        'GET /admin/users/security' => [
            [[], ['magazin', 'conturi_clienti']],
        ],
        // Acțiunile în masă din comenzi: AWB-ul e al livrării FAN, retrimiterea în ERP a ERP-ului.
        'POST /admin/orders/bulk' => [
            [['post.bulk_action' => ['awb']], 'livrare_fan'],
            [['post.bulk_action' => ['erp_retry']], 'erp'],
            [[], 'magazin'],
        ],
        'GET /admin/settings/payments' => [
            [[], ['magazin', 'plata_stripe', 'plata_euplatesc', 'plata_bt']],
        ],
        'POST /admin/settings/payments' => [
            [[], ['magazin', 'plata_stripe', 'plata_euplatesc', 'plata_bt']],
        ],
    ],

    /**
     * Pachetele de abonament (propunere din analiză; sursa adevărului va fi
     * CRM-ul). Aici le folosesc doar uneltele de dezvoltare și testele.
     * „cel_putin_una_din”: pachetul cere cel puțin unul; uneltele le pun pe toate.
     */
    'pachete' => [
        'prezentare' => [
            'nume' => 'Prezentare',
            'pluginuri' => [],
            'optionale' => ['blog', 'newsletter', 'chat_live'],
        ],
        'catalog' => [
            'nume' => 'Catalog',
            'pluginuri' => ['catalog'],
            'optionale' => ['blog', 'newsletter', 'chat_live', 'recenzii', 'cereri_oferta'],
        ],
        'magazin' => [
            'nume' => 'Magazin',
            'pluginuri' => ['catalog', 'magazin', 'livrare_fan'],
            'cel_putin_una_din' => ['plata_stripe', 'plata_euplatesc', 'plata_bt'],
            'optionale' => ['blog', 'newsletter', 'chat_live', 'recenzii', 'cupoane', 'fidelitate', 'conturi_clienti', 'erp'],
        ],
        'asociatie' => [
            'nume' => 'Asociație',
            'pluginuri' => ['conturi_clienti', 'plata_stripe', 'donatii', 'membri'],
            'optionale' => ['blog', 'newsletter', 'chat_live'],
        ],
    ],

    /**
     * Licențele de dezvoltare ale celor patru site-uri existente, scrise explicit
     * (tools/dev/licenta.php <site>, compara-cu-baza.sh --licenta=bioscem,
     * instantaneu-rute.php). Doar pentru teste: ce funcții are codul vechi al
     * fiecărui site (din repo-ul lui), nu contractul clientului. Contractul îl
     * hotărăște CRM-ul și nu stă în acest repo.
     *
     * Scrise de mână, nu „tot ce e portat”: un plugin portat mai târziu (de exemplu
     * cereri_oferta din grafoanaytis) nu intră singur în licența Bioscem, deci
     * comparația cu Bioscem nu-l poate ascunde. Un plugin nou intră aici doar
     * explicit, cu motivul lui.
     */
    'licente_dev' => [
        // Ce avea codul Bioscem la 4915478 (tot ce era portat, fără unealta internă);
        // instantaneu-rute.php verifică lista. plata_stripe rămâne până la decizia OD-33.
        'bioscem' => ['catalog', 'magazin', 'livrare_fan', 'plata_stripe', 'plata_euplatesc', 'plata_bt', 'link_plata', 'erp',
            'cupoane', 'fidelitate', 'recenzii', 'precomenzi', 'oferte_bbd', 'produse_promo', 'conturi_clienti', 'blog',
            'newsletter', 'chat_live', 'manechin', 'acorduri_gdpr'],
        // Codul Biovitality: fără EuPlătesc, BT și precomenzi; cu pachete, fișiere în cloud și concurența (neportate încă).
        'biovitality' => ['catalog', 'magazin', 'livrare_fan', 'plata_stripe', 'link_plata', 'erp', 'cupoane', 'fidelitate',
            'recenzii', 'oferte_bbd', 'produse_promo', 'pachete_produse', 'conturi_clienti', 'blog', 'newsletter', 'chat_live',
            'fisiere_cloud', 'concurenta', 'manechin', 'acorduri_gdpr'],
        // vertij: asociația (conturi, Stripe, donații, membri), blogul și newsletterul; magazinul e adormit.
        'vertij' => ['conturi_clienti', 'plata_stripe', 'donatii', 'membri', 'blog', 'newsletter'],
        // grafoanaytis: catalog fără prețuri și cereri de ofertă în locul coșului (pachetul „Catalog”).
        'grafoanaytis' => ['catalog', 'cereri_oferta'],
    ],
];
