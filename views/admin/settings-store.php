<?php
$section = (string) ($section ?? 'config');
$galleryImages = is_array($galleryImages ?? null) ? $galleryImages : [];
?>

<?php
admin_componenta('antet-pagina', [
    'titlu' => \App\Support\Plugins::vizibil('magazin') ? 'Setări magazin' : 'Setări site',
    'nivel' => 1,
    'subtitlu' => 'Cum funcționează site-ul: datele generale, ' . (\App\Support\Plugins::vizibil('catalog') ? 'TVA-ul, ' : '')
        . 'SEO-ul și Google, bannerul de cookie, viteza. Fiecare casetă își salvează doar setările ei.',
    'ajutor' => [
        'Tab-urile despart setările după ce fac. Adresa păstrează tab-ul ales, deci un link salvat deschide același tab.',
        'Fiecare casetă are butonul ei de salvare și salvează doar câmpurile din ea; restul paginii rămâne neatins.',
    ],
]);
?>
<div class="adm-setari">

    <?php if ($section === 'config'): ?>
        <?php
        $settings = is_array($settings ?? null) ? $settings : [];
        $quantityStyle = (string) ($settings['store_quantity_control_style'] ?? 'default');
        if (!in_array($quantityStyle, ['default', 'stepper'], true)) {
            $quantityStyle = 'default';
        }
        $applyProductTemplate = (string) ($settings['store_quantity_apply_product_template'] ?? '0') === '1';
        $applyFloatingCart = (string) ($settings['store_quantity_apply_floating_cart'] ?? '0') === '1';
        $applyCartPage = (string) ($settings['store_quantity_apply_cart_page'] ?? '0') === '1';
        $bbdSidebarEnabled = (string) ($settings['store_bbd_sidebar_enabled'] ?? '1') !== '0';
        $orderNumberMode = trim((string) ($settings['order_number_mode'] ?? \App\Support\OrderNumber::MOD_SECVENTIAL));
        if (!in_array($orderNumberMode, [\App\Support\OrderNumber::MOD_SECVENTIAL, \App\Support\OrderNumber::MOD_VECHI], true)) {
            $orderNumberMode = \App\Support\OrderNumber::MOD_SECVENTIAL;
        }
        $orderNumberNext = (int) ($orderNumberNext ?? \App\Support\OrderNumber::normalizeazaStart($settings['order_number_next'] ?? null));
        $vatSumar = is_array($vatSumar ?? null) ? $vatSumar : [];
        $vatImplicit = trim(\App\Support\Settings::cotaTvaImplicita($settings));
        // Cota cea mai folosită e cea de la care va vrea sa plece, cel mai probabil.
        $vatCotaDominanta = $vatImplicit;
        $vatMaxProduse = -1;
        foreach ($vatSumar as $vatRand) {
            if ((int) ($vatRand['total'] ?? 0) > $vatMaxProduse) {
                $vatMaxProduse = (int) ($vatRand['total'] ?? 0);
                $vatCotaDominanta = rtrim(rtrim(number_format((float) ($vatRand['cota'] ?? 0), 2, '.', ''), '0'), '.');
            }
        }
        $taburiPluginuri = [
            'chat' => \App\Support\Plugins::vizibil('chat_live'),
            'numerotare' => \App\Support\Plugins::vizibil('magazin'),
            'tva' => \App\Support\Plugins::vizibil('catalog'),
            'quantity' => \App\Support\Plugins::vizibil('magazin'),
            'widgets' => \App\Support\Plugins::vizibil('oferte_bbd'),
        ];
        $cuMagazin = \App\Support\Plugins::vizibil('magazin');
        $cuBlog = \App\Support\Plugins::vizibil('blog');
        $cookieBannerEnabled = (string) ($settings['cookie_banner_enabled'] ?? '1') === '1';
        $cookieBannerText = (string) ($settings['cookie_banner_text'] ?? '');
        $cookiePolicyUrl = (string) ($settings['cookie_banner_policy_url'] ?? '');
        $trackingActiv = (string) ($settings['google_analytics_enabled'] ?? '0') === '1'
            || (string) ($settings['google_tag_manager_enabled'] ?? '0') === '1'
            || (string) ($settings['google_ads_enabled'] ?? '0') === '1'
            || (string) ($settings['microsoft_clarity_enabled'] ?? '0') === '1';
        $popupEnabled = (string) ($settings['welcome_popup_enabled'] ?? '0') === '1';
        $popupTitle = (string) ($settings['welcome_popup_title'] ?? '');
        $popupBody = (string) ($settings['welcome_popup_body'] ?? '');
        // WP-35 (IA-03, IA-04): cele 14 părți de dinainte stau acum în șase tab-uri; pagina Google (verificarea, Analytics, Tag
        // Manager, Ads) e în „SEO și Google”. Adresele vechi (?tab=tva, ?tab=caching…, la care trimit și salvările) deschid
        // tab-ul nou și coboară la partea lor.
        $taburiSetari = array_values(array_filter([
            'general',
            ($taburiPluginuri['tva'] || $taburiPluginuri['numerotare'] || $taburiPluginuri['quantity'] || $taburiPluginuri['widgets']) ? 'magazin' : '',
            'seo', 'cookies', 'viteza',
            $taburiPluginuri['chat'] ? 'chat' : '',
        ]));
        $aliasSetari = [
            'pages' => ['general', 'setari-pages'], 'branding' => ['general', 'setari-branding'], 'maintenance' => ['general', 'setari-maintenance'],
            'tva' => ['magazin', 'setari-tva'], 'numerotare' => ['magazin', 'setari-numerotare'], 'quantity' => ['magazin', 'setari-quantity'],
            'widgets' => ['magazin', 'setari-widgets'], 'sitemap' => ['seo', 'setari-sitemap'], 'clarity' => ['seo', 'setari-clarity'],
            'google' => ['seo', 'setari-google'], 'caching' => ['viteza', ''], 'popup' => ['cookies', 'setari-popup'],
        ];
        [$tabSetari, $tintaSetari] = adm_tab_ales($taburiSetari, $aliasSetari);
        $eticheteSetari = ['general' => 'General', 'magazin' => $cuMagazin ? 'Magazin' : 'Produse', 'seo' => 'SEO și Google',
            'cookies' => 'Cookie și popup', 'viteza' => 'Viteză', 'chat' => 'Chat live'];
        $cachePagesEnabled = (string) ($settings['cache_pages_enabled'] ?? '0') === '1';
        $cacheRules = \App\Support\ResponseCache::parseRules((string) ($settings['cache_pages_rules_json'] ?? '[]'));
        $cacheCustomExclusions = trim((string) ($settings['cache_exclusions_custom'] ?? ''));
        $cacheAssetsEnabled = (string) ($settings['cache_assets_enabled'] ?? '0') === '1';
        $cacheAssetsTtl = \App\Support\ResponseCache::normalizeTtlSeconds($settings['cache_assets_ttl_seconds'] ?? 86400, 86400);
        $cacheUploadsTtl = \App\Support\ResponseCache::normalizeTtlSeconds($settings['cache_uploads_ttl_seconds'] ?? 604800, 604800);
        $cacheAssetsVersioningMode = \App\Support\ResponseCache::normalizeAssetVersioningMode((string) ($settings['cache_assets_versioning_mode'] ?? 'none'));
        $cacheAssetsEtagEnabled = (string) ($settings['cache_assets_etag_enabled'] ?? '1') === '1';
        $cacheAssetsVersionToken = trim((string) ($settings['cache_assets_version_token'] ?? ''));
        $cacheStats = is_array($cacheStats ?? null) ? $cacheStats : ['entries' => 0, 'fresh' => 0, 'expired' => 0];
        ?>
        <?php
        admin_componenta('tab-uri-cu-numar', [
            'eticheta' => 'Părțile setărilor',
            'panouri' => true,
            'tinta' => $tintaSetari,
            'taburi' => array_map(static fn (string $cheie): array => ['cheie' => $cheie, 'eticheta' => $eticheteSetari[$cheie],
                'href' => '/admin/settings/store?tab=' . $cheie, 'activ' => $cheie === $tabSetari], $taburiSetari),
        ]);
        ?>
        <div class="adm-tab-panou" data-adm-tab-panou="general"<?= $tabSetari !== 'general' ? ' hidden' : '' ?>>
            <?php $storeNameTitle = \App\Support\SeoMeta::numeMagazin($settings); ?>
        <section class="adm-sectiune" id="setari-nume" aria-labelledby="setari-nume-titlu">
            <header class="adm-sectiune__antet">
                <div>
                    <h2 class="adm-sectiune__titlu" id="setari-nume-titlu">Numele magazinului în titluri</h2>
                    <p class="adm-sectiune__descriere">
                Apare la finalul titlurilor din Google și din browser (ex. „Magazin | <?= htmlspecialchars($storeNameTitle, ENT_QUOTES) ?>”)
                și ca nume al firmei în datele pentru Google. Gol = „<?= htmlspecialchars(\App\Support\SeoMeta::numeMagazinImplicit(), ENT_QUOTES) ?>”.
                    </p>
                </div>
            </header>
            <div class="adm-sectiune__corp">
            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="save_store_name">
                <div class="field" style="max-width:680px;">
                    <label for="store_name">Nume magazin</label>
                    <input id="store_name" type="text" name="store_name" maxlength="60" value="<?= htmlspecialchars($storeNameTitle, ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars(\App\Support\SeoMeta::numeMagazinImplicit(), ENT_QUOTES) ?>">
                </div>
                <div style="margin-top:10px;">
                    <button class="btn" type="submit">Salvează numele</button>
                </div>
            </form>
            </div>
        </section>
        <section class="adm-sectiune" id="setari-pages" aria-labelledby="setari-pages-titlu">
            <header class="adm-sectiune__antet">
                <div>
                    <h2 class="adm-sectiune__titlu" id="setari-pages-titlu"><?= $cuMagazin ? 'Pagini magazin' : 'Pagini site' ?></h2>
                </div>
            </header>
            <div class="adm-sectiune__corp">
<?php if ($cuMagazin): ?>
            <p style="margin:0 0 10px;color:var(--ax-text-3);">
                Verifică și creează automat paginile necesare pentru fluxul de cumpărare:
                Acasă, Magazin, Coș, Checkout, Thank you (checkout/succes), <?= $cuBlog ? 'Blog, ' : '' ?>Contact, 404.
            </p>
<?php else: ?>
            <p style="margin:0 0 10px;color:var(--ax-text-3);">
                Verifică și creează automat paginile de bază ale site-ului: Acasă, <?= $cuBlog ? 'Blog, ' : '' ?>Contact, 404.
            </p>
<?php endif; ?>
            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="generate_pages">
                <button class="btn" type="submit">Generează pagini necesare</button>
            </form>
            </div>
        </section>
            <?php
            $faviconUrl = trim((string) ($settings['store_favicon_url'] ?? ''));
            $faviconPreview = $faviconUrl !== '' ? $faviconUrl : '/assets/img/product-placeholder.svg';
            ?>
        <section class="adm-sectiune" id="setari-branding" aria-labelledby="setari-branding-titlu">
            <header class="adm-sectiune__antet">
                <div>
                    <h2 class="adm-sectiune__titlu" id="setari-branding-titlu">Favicon site</h2>
                    <p class="adm-sectiune__descriere">
                Selectează o imagine din Galerie pentru favicon-ul site-ului.
                    </p>
                </div>
            </header>
            <div class="adm-sectiune__corp">
            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="save_favicon">
                <div class="field" style="grid-column:1/-1;">
                    <input type="hidden" name="store_favicon_url" id="store_favicon_url" value="<?= htmlspecialchars($faviconUrl, ENT_QUOTES) ?>">
                    <div class="product-image-picker-inline">
                        <img id="store_favicon_preview" src="<?= htmlspecialchars($faviconPreview, ENT_QUOTES) ?>" alt="Preview favicon" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                        <div>
                            <button class="btn btn-secondary" type="button" id="open-store-favicon-picker">Selectează imagine</button>
                            <button class="btn btn-secondary" type="button" id="clear-store-favicon-picker" style="margin-left:6px;">Resetează</button>
                            <p id="store_favicon_label" class="muted" style="margin:8px 0 0;font-size:12px;"><?= htmlspecialchars($faviconPreview, ENT_QUOTES) ?></p>
                        </div>
                    </div>
                </div>
                <div style="margin-top:10px;">
                    <button class="btn" type="submit">Salvează favicon</button>
                </div>
            </form>
            </div>
        </section>
            <?php // WP-30 (faza D): culoarea de accent a adminului acestui site (App\Support\AdminAspect); nu schimbă nimic pe site. ?>
            <?php $culoareAdmin = \App\Support\AdminAspect::accent($settings); ?>
        <section class="adm-sectiune" id="setari-accent" aria-labelledby="setari-accent-titlu">
            <header class="adm-sectiune__antet">
                <div>
                    <h2 class="adm-sectiune__titlu" id="setari-accent-titlu">Culoarea adminului</h2>
                    <p class="adm-sectiune__descriere">
                Culoarea butoanelor și a paginii alese din meniu, doar aici în admin (vizitatorii nu o văd). Ajută să știi pe ce
                site lucrezi când ai mai multe deschise. „Resetează” pune albastrul implicit.
                    </p>
                </div>
            </header>
            <div class="adm-sectiune__corp">
            <form method="post" action="/admin/settings/store" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="action" value="save_admin_accent">
                <label for="admin_culoare_accent" style="display:flex;gap:8px;align-items:center;">
                    <input type="color" id="admin_culoare_accent" name="admin_culoare_accent" value="<?= htmlspecialchars($culoareAdmin, ENT_QUOTES) ?>" style="width:56px;height:40px;padding:2px;">
                    <span class="muted"><?= htmlspecialchars($culoareAdmin, ENT_QUOTES) ?></span>
                </label>
                <button class="btn" type="submit">Salvează culoarea</button>
                <button class="btn btn-secondary" type="submit" name="reseteaza" value="1">Resetează</button>
            </form>
            </div>
        </section>
            <?php
            $maintenanceEnabled = (string) ($settings['maintenance_enabled'] ?? '0') === '1';
            $maintenanceKey = trim((string) ($settings['maintenance_key'] ?? ''));
            $maintenanceLink = \App\Support\Maintenance::linkPreview((string) ($appUrl ?? ''), $maintenanceKey);
            ?>
        <section class="adm-sectiune" id="setari-maintenance" aria-labelledby="setari-maintenance-titlu">
            <header class="adm-sectiune__antet">
                <div>
                    <h2 class="adm-sectiune__titlu" id="setari-maintenance-titlu">Mod mentenanță</h2>
                    <p class="adm-sectiune__descriere">
                Cât timp e activ, vizitatorii văd pagina „Revenim în curând", iar tu poți testa
                site-ul normal. <?= \App\Support\Plugins::oricareVizibil(['magazin', 'plata_stripe', 'plata_euplatesc', 'plata_bt'])
                    ? 'Administrarea, plățile' . (\App\Support\Plugins::vizibil('erp') ? ' și legătura cu ERP-ul rămân funcționale' : ' rămân funcționale')
                    : 'Administrarea rămâne funcțională' ?>.
                    </p>
                </div>
            </header>
            <div class="adm-sectiune__corp">

            <?php if ($maintenanceEnabled): ?>
                <div class="panel" style="margin:0 0 12px;background:var(--ax-atentie-fundal);border-color:var(--ax-atentie-linie);color:var(--ax-atentie);">
                    <strong>Site-ul este în mentenanță acum.</strong>
                    Vizitatorii nu pot cumpăra. Dezactivează bifa de mai jos când ești gata de lansare.
                </div>
            <?php endif; ?>

            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="save_maintenance">
                <div style="display:grid;gap:10px;max-width:760px;">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="maintenance_enabled" value="1" <?= $maintenanceEnabled ? 'checked' : '' ?>>
                        <strong>Activează modul mentenanță</strong>
                    </label>

                    <div class="field">
                        <label for="maintenance_title">Titlu</label>
                        <input id="maintenance_title" type="text" name="maintenance_title" maxlength="120"
                               value="<?= htmlspecialchars((string) ($settings['maintenance_title'] ?? ''), ENT_QUOTES) ?>"
                               placeholder="Revenim în curând">
                    </div>

                    <div class="field">
                        <label for="maintenance_message">Mesaj</label>
                        <textarea id="maintenance_message" name="maintenance_message" rows="3"
                                  placeholder="Lucrăm la magazin chiar acum."><?= htmlspecialchars((string) ($settings['maintenance_message'] ?? ''), ENT_QUOTES) ?></textarea>
                        <small style="color:var(--ax-text-3);">Se afișează sub titlu. Telefonul și emailul din setările de contact apar automat.</small>
                    </div>

                    <div class="field">
                        <label for="maintenance_allowed_ips">IP-uri cu acces (opțional)</label>
                        <input id="maintenance_allowed_ips" type="text" name="maintenance_allowed_ips"
                               value="<?= htmlspecialchars((string) ($settings['maintenance_allowed_ips'] ?? ''), ENT_QUOTES) ?>"
                               placeholder="ex: 86.120.10.5, 79.117.4.20">
                        <small style="color:var(--ax-text-3);">
                            Separate prin virgulă. IP-ul tău curent este
                            <strong><?= htmlspecialchars(\App\Support\IpClient::adresa() ?: '?', ENT_QUOTES) ?></strong>.
                        </small>
                    </div>

                    <div>
                        <button class="btn" type="submit">Salvează setările de mentenanță</button>
                    </div>
                </div>
            </form>

            <div class="panel" style="margin:12px 0 0;background:var(--ax-suprafata-2);border-color:var(--ax-linie);">
                <h4 style="margin:0 0 8px;">Cum testezi în timpul mentenanței</h4>
                <ol style="margin:0 0 10px;padding-left:18px;display:grid;gap:6px;color:var(--ax-text-2);">
                    <li>Ești logat în administrare → vezi site-ul normal, fără să faci nimic.</li>
                    <li>Pentru client sau colegi, trimite linkul de previzualizare de mai jos. La prima deschidere lasă un cookie valabil 30 de zile, deci linkul se dă o singură dată.</li>
                </ol>
                <?php if ($maintenanceLink !== ''): ?>
                    <div class="field" style="margin:0;">
                        <label for="maintenance_link">Link de previzualizare</label>
                        <input id="maintenance_link" type="text" readonly onfocus="this.select()"
                               value="<?= htmlspecialchars($maintenanceLink, ENT_QUOTES) ?>">
                    </div>
                <?php else: ?>
                    <p style="margin:0;color:var(--ax-text-3);">Cheia de previzualizare se generează la prima salvare.</p>
                <?php endif; ?>
                <form method="post" action="/admin/settings/store" style="margin-top:10px;"
                      onsubmit="return confirm('Se generează o cheie nouă. Linkurile trimise până acum nu vor mai funcționa. Continui?');">
                    <input type="hidden" name="action" value="regenerate_maintenance_key">
                    <button class="btn btn-secondary" type="submit">Generează o cheie nouă</button>
                </form>
            </div>
            </div>
        </section>
        </div>
<?php if (in_array('magazin', $taburiSetari, true)): ?>
        <div class="adm-tab-panou" data-adm-tab-panou="magazin"<?= $tabSetari !== 'magazin' ? ' hidden' : '' ?>>
<?php if ($taburiPluginuri['tva']): ?>
        <section class="adm-sectiune" id="setari-tva" aria-labelledby="setari-tva-titlu">
            <header class="adm-sectiune__antet">
                <div>
                    <h2 class="adm-sectiune__titlu" id="setari-tva-titlu">Cota de TVA</h2>
                </div>
            </header>
            <div class="adm-sectiune__corp">
            <?php if ($vatSumar !== []): ?>
                <p style="margin:0 0 10px;color:var(--ax-text-3);">
                    Acum în catalog:
                    <?php foreach ($vatSumar as $vatRand): ?>
                        <strong><?= htmlspecialchars(rtrim(rtrim(number_format((float) ($vatRand['cota'] ?? 0), 2, '.', ''), '0'), '.'), ENT_QUOTES) ?>%</strong>
                        — <?= (int) ($vatRand['total'] ?? 0) ?> produse&nbsp;&nbsp;
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
            <form method="post" action="/admin/settings/store" onsubmit="return confirm('Sigur schimbi cota de TVA pe toate produsele care o au pe cea veche? Operația nu se poate anula din interfață.');">
                <input type="hidden" name="action" value="save_vat_bulk">
                <div style="display:flex;gap:12px;flex-wrap:wrap;max-width:520px;">
                    <div class="field" style="flex:1;min-width:150px;">
                        <label for="vat_from">Schimbă produsele cu cota</label>
                        <input id="vat_from" name="vat_from" type="number" min="0" max="100" step="0.01" value="<?= htmlspecialchars($vatCotaDominanta, ENT_QUOTES) ?>" required>
                    </div>
                    <div class="field" style="flex:1;min-width:150px;">
                        <label for="vat_to">în cota</label>
                        <input id="vat_to" name="vat_to" type="number" min="0" max="100" step="0.01" value="21" required>
                    </div>
                </div>
                <div style="display:grid;gap:10px;margin-top:14px;max-width:640px;">
                    <label style="display:flex;gap:8px;align-items:flex-start;">
                        <input type="radio" name="vat_mode" value="pastreaza_pret" checked style="margin-top:3px;">
                        <span>
                            <strong>Păstrează prețurile afișate</strong><br>
                            <span style="color:var(--ax-text-3);font-size:13px;">
                                Clientul plătește exact cât plătea și ieri. Se schimbă doar cât din preț
                                este TVA, deci magazinul încasează mai puțin net. Nimic altceva nu se atinge.
                            </span>
                        </span>
                    </label>
                    <label style="display:flex;gap:8px;align-items:flex-start;">
                        <input type="radio" name="vat_mode" value="pastreaza_net" style="margin-top:3px;">
                        <span>
                            <strong>Păstrează suma fără TVA (crește prețurile afișate)</strong><br>
                            <span style="color:var(--ax-text-3);font-size:13px;">
                                Magazinul rămâne cu aceeași sumă după TVA, deci prețurile de raft cresc
                                (de la 19% la 21% înseamnă +1,68%). Se recalculează prețul de bază,
                                prețul redus<?= \App\Support\Plugins::vizibil('oferte_bbd') ? ', perioadele de reducere programate și prețurile BBD' : ' și perioadele de reducere programate' ?>.
                                Prețurile rezultate pot ieși nerotunde.
                            </span>
                        </span>
                    </label>
                </div>
                <label style="display:flex;align-items:center;gap:8px;margin-top:12px;">
                    <input type="checkbox" name="vat_set_default" value="1" checked>
                    Folosește cota nouă și pentru produsele adăugate de acum înainte
                </label>
                <p style="margin:12px 0 0;color:var(--ax-atentie);font-size:13px;max-width:640px;">
                    Comenzile deja plasate nu se ating: ele păstrează cota din momentul vânzării.
                    Fă o copie a bazei de date înainte, operația nu are buton de anulare.
                </p>
                <div style="margin-top:10px;">
                    <button class="btn" type="submit">Schimbă TVA pe produse</button>
                </div>
            </form>
            </div>
        </section>
<?php endif; ?>
<?php if ($taburiPluginuri['numerotare']): ?>
        <section class="adm-sectiune" id="setari-numerotare" aria-labelledby="setari-numerotare-titlu">
            <header class="adm-sectiune__antet">
                <div>
                    <h2 class="adm-sectiune__titlu" id="setari-numerotare-titlu">Numerotare comenzi</h2>
                    <p class="adm-sectiune__descriere">
                Numărul comenzii este cel văzut de client<?= implode('', array_keys(array_filter([', cel trimis în ERP' => \App\Support\Plugins::vizibil('erp'),
                    ', cel trimis la EuPlătesc' => \App\Support\Plugins::vizibil('plata_euplatesc')]))) ?>.
                Comenzile deja emise nu se renumerotează.
                    </p>
                </div>
            </header>
            <div class="adm-sectiune__corp">
            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="save_order_numbering">
                <div class="field" style="max-width:420px;">
                    <label for="order_number_mode">Format</label>
                    <select id="order_number_mode" name="order_number_mode">
                        <option value="<?= \App\Support\OrderNumber::MOD_SECVENTIAL ?>" <?= $orderNumberMode === \App\Support\OrderNumber::MOD_SECVENTIAL ? 'selected' : '' ?>>Numere consecutive (203800, 203801, …)</option>
                        <option value="<?= \App\Support\OrderNumber::MOD_VECHI ?>" <?= $orderNumberMode === \App\Support\OrderNumber::MOD_VECHI ? 'selected' : '' ?>>Format vechi (<?= htmlspecialchars(\App\Support\OrderNumber::prefixVechi($settings), ENT_QUOTES) ?> + dată + aleator)</option>
                    </select>
                </div>
                <div class="field" style="max-width:420px;margin-top:10px;">
                    <label for="order_number_next">Numărul următoarei comenzi</label>
                    <input id="order_number_next" name="order_number_next" type="number" min="1" step="1" value="<?= htmlspecialchars((string) $orderNumberNext, ENT_QUOTES) ?>">
                    <p style="margin:6px 0 0;color:var(--ax-text-3);font-size:13px;">
                        Următoarea comandă primește exact acest număr, apoi contorul crește singur.
                        Dacă pui un număr deja folosit, sistemul îl sare până găsește unul liber.
                    </p>
                </div>
                <div style="margin-top:10px;">
                    <button class="btn" type="submit">Salvează numerotarea</button>
                </div>
            </form>
            </div>
        </section>
<?php endif; ?>
<?php if ($taburiPluginuri['quantity']): ?>
        <section class="adm-sectiune" id="setari-quantity" aria-labelledby="setari-quantity-titlu">
            <header class="adm-sectiune__antet">
                <div>
                    <h2 class="adm-sectiune__titlu" id="setari-quantity-titlu">Control cantitate</h2>
                    <p class="adm-sectiune__descriere">
                Alege modelul de cantitate și unde să fie afișat.
                    </p>
                </div>
            </header>
            <div class="adm-sectiune__corp">
            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="save_quantity_controls">
                <div class="field" style="max-width:420px;">
                    <label for="store_quantity_control_style">Model cantitate</label>
                    <select id="store_quantity_control_style" name="store_quantity_control_style">
                        <option value="default" <?= $quantityStyle === 'default' ? 'selected' : '' ?>>Implicit (input numeric simplu)</option>
                        <option value="stepper" <?= $quantityStyle === 'stepper' ? 'selected' : '' ?>>Stepper (+ / - lângă câmp)</option>
                    </select>
                </div>
                <div style="display:grid;gap:8px;margin-top:10px;">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="store_quantity_apply_product_template" value="1" <?= $applyProductTemplate ? 'checked' : '' ?>>
                        Aplică în template-urile produselor
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="store_quantity_apply_floating_cart" value="1" <?= $applyFloatingCart ? 'checked' : '' ?>>
                        Aplică în coșul flotant
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="store_quantity_apply_cart_page" value="1" <?= $applyCartPage ? 'checked' : '' ?>>
                        Aplică în pagina coșului
                    </label>
                </div>
                <div style="margin-top:10px;">
                    <button class="btn" type="submit">Salvează control cantitate</button>
                </div>
            </form>
            </div>
        </section>
<?php endif; ?>
<?php if ($taburiPluginuri['widgets']): ?>
        <section class="adm-sectiune" id="setari-widgets" aria-labelledby="setari-widgets-titlu">
            <header class="adm-sectiune__antet">
                <div>
                    <h2 class="adm-sectiune__titlu" id="setari-widgets-titlu">Sertar lateral „Oferte”</h2>
                    <p class="adm-sectiune__descriere">
                Panoul care apare pe marginea din stânga a site-ului, cu produsele
                aflate la preț redus. Dezactivează-l dacă nu vrei să apară deloc.
                    </p>
                </div>
            </header>
            <div class="adm-sectiune__corp">
            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="save_bbd_sidebar">
                <div style="display:grid;gap:8px;">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="store_bbd_sidebar_enabled" value="1" <?= $bbdSidebarEnabled ? 'checked' : '' ?>>
                        Afișează sertarul „Oferte” pe site
                    </label>
                </div>
                <div style="margin-top:10px;">
                    <button class="btn" type="submit">Salvează</button>
                </div>
            </form>
            </div>
        </section>
<?php endif; ?>
        </div>
<?php endif; ?>
        <div class="adm-tab-panou" data-adm-tab-panou="seo"<?= $tabSetari !== 'seo' ? ' hidden' : '' ?>>
            <?php
            $seoHomeTitle = trim((string) ($settings['store_seo_home_title'] ?? ''));
            $seoHomeDescription = trim((string) ($settings['store_seo_home_description'] ?? ''));
            $seoDescription = trim((string) ($settings['store_seo_default_description'] ?? ''));
            $seoImageUrl = trim((string) ($settings['store_seo_default_image_url'] ?? ''));
            $seoImagePreview = $seoImageUrl !== '' ? $seoImageUrl : '/assets/img/product-placeholder.svg';
            ?>
        <section class="adm-sectiune" id="setari-seo" aria-labelledby="setari-seo-titlu">
            <header class="adm-sectiune__antet">
                <div>
                    <h2 class="adm-sectiune__titlu" id="setari-seo-titlu">SEO Google (global)</h2>
                    <p class="adm-sectiune__descriere">
                Aceste valori sunt folosite ca fallback pentru paginile care nu au SEO specific.
                Titlul și descrierea pentru Homepage au întâietate pe prima pagină.
                    </p>
                </div>
            </header>
            <div class="adm-sectiune__corp">
            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="save_seo_defaults">
                <div class="field" style="max-width:680px;">
                    <label for="seo_home_title">Titlu SEO pentru Homepage</label>
                    <input id="seo_home_title" type="text" name="seo_home_title" value="<?= htmlspecialchars($seoHomeTitle, ENT_QUOTES) ?>" maxlength="120" placeholder="Ex: Numele magazinului - descriere scurtă">
                </div>
                <div class="field" style="max-width:680px;">
                    <label for="seo_home_description">Descriere SEO pentru Homepage</label>
                    <textarea id="seo_home_description" name="seo_home_description" rows="3" maxlength="320" placeholder="Descriere care apare în Google pentru pagina principală..."><?= htmlspecialchars($seoHomeDescription, ENT_QUOTES) ?></textarea>
                </div>
                <div class="field" style="max-width:680px;">
                    <label for="seo_default_description">Descriere SEO fallback (alte pagini)</label>
                    <textarea id="seo_default_description" name="seo_default_description" rows="3" maxlength="320" placeholder="Descriere fallback pentru paginile fără descriere specifică..."><?= htmlspecialchars($seoDescription, ENT_QUOTES) ?></textarea>
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label>Imagine preview (Open Graph / social)</label>
                    <input type="hidden" name="seo_default_image_url" id="seo_default_image_url" value="<?= htmlspecialchars($seoImageUrl, ENT_QUOTES) ?>">
                    <div class="product-image-picker-inline">
                        <img id="seo_default_image_preview" src="<?= htmlspecialchars($seoImagePreview, ENT_QUOTES) ?>" alt="Preview SEO image" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                        <div>
                            <button class="btn btn-secondary" type="button" id="open-seo-image-picker">Selectează imagine</button>
                            <button class="btn btn-secondary" type="button" id="clear-seo-image-picker" style="margin-left:6px;">Resetează</button>
                            <p id="seo_default_image_label" class="muted" style="margin:8px 0 0;font-size:12px;"><?= htmlspecialchars($seoImagePreview, ENT_QUOTES) ?></p>
                        </div>
                    </div>
                </div>
                <div style="margin-top:10px;">
                    <button class="btn" type="submit">Salvează SEO global</button>
                </div>
            </form>
            </div>
        </section>
            <?php $sitemapUrl = (string) ($sitemapUrl ?? '/sitemap.xml'); ?>
            <?php $sitemapFilename = trim((string) ($sitemapFilename ?? 'sitemap.xml')); ?>
        <section class="adm-sectiune" id="setari-sitemap" aria-labelledby="setari-sitemap-titlu">
            <header class="adm-sectiune__antet">
                <div>
                    <h2 class="adm-sectiune__titlu" id="setari-sitemap-titlu">Sitemap XML</h2>
                    <p class="adm-sectiune__descriere">
                Sitemap-ul se construiește automat din baza de date, la fiecare cerere: <?= \App\Support\Plugins::vizibil('catalog') ? 'produsele active, categoriile, mărcile, etichetele, ' : '' ?>paginile publicate<?= $cuBlog ? ' și articolele' : '' ?>.
                Nu mai trebuie generat după modificări.
                Butonul de mai jos verifică lista și șterge un fișier sitemap.xml vechi rămas pe server
                (cu alt nume de fișier, scrie o copie statică, de exemplu pentru teste).
                    </p>
                </div>
            </header>
            <div class="adm-sectiune__corp">
            <p style="margin:0 0 10px;">
                URL sitemap: <a href="<?= htmlspecialchars($sitemapUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($sitemapUrl, ENT_QUOTES) ?></a>
            </p>
            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="generate_sitemap">
                <div class="field" style="max-width:360px;margin-bottom:10px;">
                    <label for="sitemap_filename">Nume fișier sitemap</label>
                    <input id="sitemap_filename" type="text" name="sitemap_filename" value="<?= htmlspecialchars($sitemapFilename, ENT_QUOTES) ?>" placeholder="sitemap.xml">
                    <small style="color:var(--ax-text-3);">Exemplu: <code>sitemap-nou.xml</code></small>
                </div>
                <button class="btn" type="submit">Generează sitemap la URL nou</button>
            </form>
            </div>
        </section>
            <?php
            $clarityEnabled = (string) ($settings['microsoft_clarity_enabled'] ?? '0') === '1';
            $clarityProjectId = trim((string) ($settings['microsoft_clarity_project_id'] ?? ''));
            ?>
        <section class="adm-sectiune" id="setari-clarity" aria-labelledby="setari-clarity-titlu">
            <header class="adm-sectiune__antet">
                <div>
                    <h2 class="adm-sectiune__titlu" id="setari-clarity-titlu">Microsoft Clarity</h2>
                    <p class="adm-sectiune__descriere">
                Activează codul Clarity pentru heatmaps, sesiuni înregistrate și click tracking.
                    </p>
                </div>
            </header>
            <div class="adm-sectiune__corp">
            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="save_clarity_settings">
                <div style="display:grid;gap:8px;max-width:760px;">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="microsoft_clarity_enabled" value="1" <?= $clarityEnabled ? 'checked' : '' ?>>
                        Activează Microsoft Clarity pe site
                    </label>
                    <div class="field" style="max-width:360px;">
                        <label for="microsoft_clarity_project_id">Project ID Clarity</label>
                        <input id="microsoft_clarity_project_id" type="text" name="microsoft_clarity_project_id" value="<?= htmlspecialchars($clarityProjectId, ENT_QUOTES) ?>" placeholder="ex: abc123xyz">
                        <small style="color:var(--ax-text-3);">Doar litere și cifre. Se găsește în scriptul furnizat de Clarity.</small>
                    </div>
                    <div>
                        <button class="btn" type="submit">Salvează setările Clarity</button>
                    </div>
                </div>
            </form>
            <div class="panel" style="margin:12px 0 0;background:var(--ax-suprafata-2);border-color:var(--ax-linie);">
                <h4 style="margin:0 0 8px;">Setup rapid (2 pași)</h4>
                <ol style="margin:0;padding-left:18px;display:grid;gap:6px;color:var(--ax-text-2);">
                    <li>Intră în <strong>clarity.microsoft.com</strong>, creează/alege proiectul site-ului tău.</li>
                    <li>Copiază <strong>Project ID</strong> din scriptul lor, pune-l aici, bifează activarea și salvează.</li>
                </ol>
                <p style="margin:10px 0 0;color:var(--ax-text-3);font-size:13px;">
                    După salvare, Clarity începe să colecteze date automat (de obicei apar în câteva minute).
                </p>
            </div>
            </div>
        </section>
        <?php // WP-35 (IA-04): fosta pagină „Google” (/admin/settings/google trimite aici), cu formularul ei neschimbat. ?>
        <?php require __DIR__ . '/settings-google.php'; ?>
        </div>
        <div class="adm-tab-panou" data-adm-tab-panou="cookies"<?= $tabSetari !== 'cookies' ? ' hidden' : '' ?>>
        <section class="adm-sectiune" id="setari-cookies" aria-labelledby="setari-cookies-titlu">
            <header class="adm-sectiune__antet">
                <div>
                    <h2 class="adm-sectiune__titlu" id="setari-cookies-titlu">Banner de cookies</h2>
                    <p class="adm-sectiune__descriere">
                Bannerul apare în partea de jos, până când vizitatorul alege „Accept toate" sau
                „Doar necesare". Alegerea se ține minte 6 luni.
                    </p>
                </div>
            </header>
            <div class="adm-sectiune__corp">
            <?php if ($trackingActiv): ?>
                <p style="margin:0 0 12px;padding:10px 12px;background:var(--ax-ok-fundal);border:1px solid var(--ax-ok-linie);border-radius:6px;color:var(--ax-ok);font-size:13px;max-width:680px;">
                    Ai activat instrumente de urmărire (Analytics / Tag Manager / Ads / Clarity).
                    Codurile lor <strong>nu se încarcă deloc</strong> până când vizitatorul apasă
                    „Accept toate" — exact cum cere legea. Ține bannerul pornit.
                </p>
            <?php else: ?>
                <p style="margin:0 0 12px;padding:10px 12px;background:var(--ax-suprafata-2);border:1px solid var(--ax-linie);border-radius:6px;color:var(--ax-text-3);font-size:13px;max-width:680px;">
                    Momentan nu ai activat niciun instrument de urmărire. Bannerul rămâne util
                    pentru transparență și devine obligatoriu în clipa în care pornești Analytics.
                </p>
            <?php endif; ?>
            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="save_cookie_banner">
                <label style="display:flex;align-items:center;gap:8px;margin:0 0 12px;">
                    <input type="checkbox" name="cookie_banner_enabled" value="1" <?= $cookieBannerEnabled ? 'checked' : '' ?>>
                    Afișează bannerul de cookies
                </label>
                <div class="field" style="max-width:680px;">
                    <label for="cookie_banner_text">Text</label>
                    <textarea id="cookie_banner_text" name="cookie_banner_text" rows="4" style="width:100%;"><?= htmlspecialchars($cookieBannerText, ENT_QUOTES) ?></textarea>
                </div>
                <div class="field" style="max-width:680px;margin-top:10px;">
                    <label for="cookie_banner_policy_url">Link către politica de confidențialitate</label>
                    <input id="cookie_banner_policy_url" name="cookie_banner_policy_url" type="text" value="<?= htmlspecialchars($cookiePolicyUrl, ENT_QUOTES) ?>" placeholder="/politica-de-confidentialitate">
                    <p style="margin:6px 0 0;color:var(--ax-text-3);font-size:13px;">
                        Lasă gol dacă nu vrei link. Pagina se creează din Pagini.
                    </p>
                </div>
                <div style="margin-top:12px;">
                    <button class="btn" type="submit">Salvează bannerul</button>
                </div>
            </form>
            </div>
        </section>
        <section class="adm-sectiune" id="setari-popup" aria-labelledby="setari-popup-titlu">
            <header class="adm-sectiune__antet">
                <div>
                    <h2 class="adm-sectiune__titlu" id="setari-popup-titlu">Popup anunț la intrarea pe site</h2>
                    <p class="adm-sectiune__descriere">
                Mesajul apare o singură dată, imediat ce un vizitator deschide site-ul.
                După ce apasă pe „Am înțeles" (sau pe X), nu îi mai apare.
                Dacă modifici textul, popup-ul reapare o dată pentru toată lumea, inclusiv
                pentru cei care l-au închis pe cel vechi.
                    </p>
                </div>
            </header>
            <div class="adm-sectiune__corp">
            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="save_welcome_popup">
                <label style="display:flex;align-items:center;gap:8px;margin:0 0 12px;">
                    <input type="checkbox" name="welcome_popup_enabled" value="1" <?= $popupEnabled ? 'checked' : '' ?>>
                    Afișează popup-ul pe site
                </label>
                <div class="field" style="max-width:640px;">
                    <label for="welcome_popup_title">Titlu</label>
                    <input id="welcome_popup_title" name="welcome_popup_title" type="text" value="<?= htmlspecialchars($popupTitle, ENT_QUOTES) ?>">
                </div>
                <div class="field" style="max-width:640px;margin-top:10px;">
                    <label for="welcome_popup_body">Mesaj</label>
                    <textarea id="welcome_popup_body" name="welcome_popup_body" rows="12" style="width:100%;"><?= htmlspecialchars($popupBody, ENT_QUOTES) ?></textarea>
                    <p style="margin:6px 0 0;color:var(--ax-text-3);font-size:13px;">
                        Text simplu; rândurile goale despart paragrafele.
                    </p>
                </div>
                <div style="margin-top:12px;">
                    <button class="btn" type="submit">Salvează popup-ul</button>
                </div>
            </form>
            </div>
        </section>
        </div>
        <div class="adm-tab-panou" data-adm-tab-panou="viteza"<?= $tabSetari !== 'viteza' ? ' hidden' : '' ?>>
        <section class="adm-sectiune" id="setari-caching" aria-labelledby="setari-caching-titlu">
            <header class="adm-sectiune__antet">
                <div>
                    <h2 class="adm-sectiune__titlu" id="setari-caching-titlu">Caching (file-based)</h2>
                    <p class="adm-sectiune__descriere">
                Cache public doar pentru pagini GET din whitelist. <?= implode('/', array_keys(array_filter(['Checkout' => \App\Support\Plugins::vizibil('magazin'),
                    'cont' => \App\Support\Plugins::vizibil('conturi_clienti'), 'admin' => true, 'api' => true, 'cos' => \App\Support\Plugins::vizibil('magazin')]))) ?> sunt excluse implicit.
                    </p>
                </div>
            </header>
            <div class="adm-sectiune__corp">

            <div class="panel" style="margin:12px 0;background:var(--ax-suprafata-2);border-color:var(--ax-linie);">
                <h4 style="margin:0 0 8px;">Status cache pagini</h4>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <span class="status-pill">Intrări: <?= (int) ($cacheStats['entries'] ?? 0) ?></span>
                    <span class="status-pill ok">Fresh: <?= (int) ($cacheStats['fresh'] ?? 0) ?></span>
                    <span class="status-pill off">Expirate: <?= (int) ($cacheStats['expired'] ?? 0) ?></span>
                </div>
            </div>

            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="save_caching_settings">
                <div class="panel" style="margin:12px 0;">
                    <h4 style="margin-top:0;">Pagini (whitelist + TTL)</h4>
                    <label style="display:flex;align-items:center;gap:8px;margin:0 0 10px;">
                        <input type="checkbox" name="cache_pages_enabled" value="1" <?= $cachePagesEnabled ? 'checked' : '' ?>>
                        Activează cache pentru paginile din whitelist
                    </label>
                    <div id="cache-rules-wrap" style="display:grid;gap:8px;">
                        <?php foreach ($cacheRules as $idx => $rule): ?>
                            <?php if (!is_array($rule)) {
                                continue;
                            } ?>
                            <div class="cache-rule-row" style="display:grid;grid-template-columns:minmax(0,1fr) 180px auto;gap:8px;align-items:end;">
                                <label style="display:grid;gap:4px;">
                                    <span>URL / pattern</span>
                                    <input type="text" name="cache_page_paths[]" value="<?= htmlspecialchars((string) ($rule['path_pattern'] ?? ''), ENT_QUOTES) ?>" placeholder="/magazin sau /blog/*">
                                </label>
                                <label style="display:grid;gap:4px;">
                                    <span>TTL</span>
                                    <select name="cache_page_ttls[]">
                                        <?php
                                        $ttlValue = (int) ($rule['ttl_seconds'] ?? 1800);
                                        $ttlOptions = [
                                            300 => '5 minute',
                                            1800 => '30 minute',
                                            7200 => '2 ore',
                                            21600 => '6 ore',
                                            86400 => '24 ore',
                                        ];
                                        foreach ($ttlOptions as $ttlSeconds => $ttlLabel):
                                            ?>
                                            <option value="<?= $ttlSeconds ?>" <?= $ttlValue === $ttlSeconds ? 'selected' : '' ?>><?= htmlspecialchars($ttlLabel, ENT_QUOTES) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button class="btn btn-secondary" type="button" data-remove-cache-rule>Elimină</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                        <button class="btn btn-secondary" type="button" id="add-cache-rule-btn">+ Adaugă pagină în whitelist</button>
                    </div>
                </div>

                <div class="panel" style="margin:12px 0;">
                    <h4 style="margin-top:0;">Imagini / assets</h4>
                    <label style="display:flex;align-items:center;gap:8px;margin:0 0 10px;">
                        <input type="checkbox" name="cache_assets_enabled" value="1" <?= $cacheAssetsEnabled ? 'checked' : '' ?>>
                        Activează cache headers pentru <code>/assets</code> și <code>/uploads</code>
                    </label>
                    <div class="field" style="max-width:360px;">
                        <label for="cache_assets_ttl_seconds">TTL assets</label>
                        <select id="cache_assets_ttl_seconds" name="cache_assets_ttl_seconds">
                            <?php
                            $assetTtlOptions = [
                                1800 => '30 minute',
                                7200 => '2 ore',
                                21600 => '6 ore',
                                86400 => '24 ore',
                                604800 => '7 zile',
                            ];
                            foreach ($assetTtlOptions as $ttlSeconds => $ttlLabel):
                                ?>
                                <option value="<?= $ttlSeconds ?>" <?= $cacheAssetsTtl === $ttlSeconds ? 'selected' : '' ?>><?= htmlspecialchars($ttlLabel, ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field" style="max-width:360px;">
                        <label for="cache_uploads_ttl_seconds">TTL uploads</label>
                        <select id="cache_uploads_ttl_seconds" name="cache_uploads_ttl_seconds">
                            <?php foreach ($assetTtlOptions as $ttlSeconds => $ttlLabel): ?>
                                <option value="<?= $ttlSeconds ?>" <?= $cacheUploadsTtl === $ttlSeconds ? 'selected' : '' ?>><?= htmlspecialchars($ttlLabel, ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field" style="max-width:360px;">
                        <label for="cache_assets_versioning_mode">Versioning assets</label>
                        <select id="cache_assets_versioning_mode" name="cache_assets_versioning_mode">
                            <option value="none" <?= $cacheAssetsVersioningMode === 'none' ? 'selected' : '' ?>>Dezactivat</option>
                            <option value="query" <?= $cacheAssetsVersioningMode === 'query' ? 'selected' : '' ?>>Query parameter (?v=token)</option>
                            <option value="hash" <?= $cacheAssetsVersioningMode === 'hash' ? 'selected' : '' ?>>Query hash (?h=token)</option>
                        </select>
                        <small style="color:var(--ax-text-3);">Folosit pentru cache busting după update.</small>
                    </div>
                    <label style="display:flex;align-items:center;gap:8px;margin:10px 0 0;">
                        <input type="checkbox" name="cache_assets_etag_enabled" value="1" <?= $cacheAssetsEtagEnabled ? 'checked' : '' ?>>
                        Activează ETag / Last-Modified pentru assets
                    </label>
                    <?php if ($cacheAssetsVersionToken !== ''): ?>
                        <small style="color:var(--ax-text-3);">Token curent versioning: <code><?= htmlspecialchars($cacheAssetsVersionToken, ENT_QUOTES) ?></code></small>
                    <?php endif; ?>
                    <label style="display:flex;align-items:center;gap:8px;margin:4px 0 0;">
                        <input type="checkbox" name="regenerate_assets_version_token" value="1">
                        Regenerează token-ul de versioning la salvare
                    </label>
                </div>

                <div class="panel" style="margin:12px 0;">
                    <h4 style="margin-top:0;">Excluderi custom</h4>
                    <p style="margin:0 0 8px;color:var(--ax-text-3);">
                        Excluderile implicite sunt active permanent: <?= implode(', ', array_keys(array_filter(['/checkout' => \App\Support\Plugins::vizibil('magazin'),
                            '/contul-meu' => \App\Support\Plugins::vizibil('conturi_clienti'), '/admin' => true, '/api/*' => true,
                            '/cos' => \App\Support\Plugins::vizibil('magazin'), '/auth/*' => \App\Support\Plugins::vizibil('conturi_clienti')]))) ?>.
                    </p>
                    <div class="field">
                        <label for="cache_exclusions_custom">Excluderi suplimentare (un pattern pe rând)</label>
                        <textarea id="cache_exclusions_custom" name="cache_exclusions_custom" rows="5" placeholder="/contact&#10;/blog/*/preview"><?= htmlspecialchars($cacheCustomExclusions, ENT_QUOTES) ?></textarea>
                    </div>
                </div>

                <div style="margin-top:10px;">
                    <button class="btn" type="submit">Salvează setările de caching</button>
                </div>
            </form>

            <div class="panel" style="margin:12px 0;">
                <h4 style="margin-top:0;">Purge / invalidare</h4>
                <div style="display:grid;gap:8px;">
                    <form method="post" action="/admin/settings/store" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
                        <input type="hidden" name="action" value="purge_cache_page">
                        <div class="field" style="min-width:320px;max-width:560px;">
                            <label for="purge_cache_path">Invalidate cache pagină (pattern)</label>
                            <input id="purge_cache_path" type="text" name="purge_cache_path" placeholder="/magazin sau /blog/*">
                        </div>
                        <button class="btn btn-secondary" type="submit">Invalidate cache pagină</button>
                    </form>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <form method="post" action="/admin/settings/store">
                            <input type="hidden" name="action" value="purge_cache_pages">
                            <button class="btn btn-secondary" type="submit">Golește doar pagini</button>
                        </form>
                        <form method="post" action="/admin/settings/store">
                            <input type="hidden" name="action" value="purge_cache_assets">
                            <button class="btn btn-secondary" type="submit">Golește doar assets</button>
                        </form>
                        <form method="post" action="/admin/settings/store" onsubmit="return confirm('Sigur vrei să golești TOT cache-ul?');">
                            <input type="hidden" name="action" value="purge_cache_all">
                            <button class="btn" type="submit">Golește tot cache-ul</button>
                        </form>
                    </div>
                </div>
            </div>
            </div>
        </section>
        </div>
<?php if ($taburiPluginuri['chat']): ?>
        <div class="adm-tab-panou" data-adm-tab-panou="chat"<?= $tabSetari !== 'chat' ? ' hidden' : '' ?>>
            <?php
            $chatEnabled = (string) ($settings['tawk_enabled'] ?? '0') === '1';
            $chatProperty = trim((string) ($settings['tawk_property_id'] ?? ''));
            $chatWidget = trim((string) ($settings['tawk_widget_id'] ?? 'default'));
            $chatPozitie = \App\Support\ChatLive::pozitie($settings);
            $chatOffsetY = \App\Support\ChatLive::offsetY($settings);
            $chatCereConsimtamant = (string) ($settings['tawk_requires_consent'] ?? '1') !== '0';
            $chatApiKey = trim((string) ($settings['tawk_api_key'] ?? ''));
            $chatParteCos = (string) ($settings['floating_cart_position'] ?? 'right') === 'left' ? 'bl' : 'br';
            ?>
        <section class="adm-sectiune" id="setari-chat" aria-labelledby="setari-chat-titlu">
            <header class="adm-sectiune__antet">
                <div>
                    <h2 class="adm-sectiune__titlu" id="setari-chat-titlu">Chat live (tawk.to)</h2>
                    <p class="adm-sectiune__descriere">
                Bulina de chat din colțul paginii. Contul și conversațiile stau la tawk.to;
                aici se configurează doar cum apare pe site.
                    </p>
                </div>
            </header>
            <div class="adm-sectiune__corp">
            <form method="post" action="/admin/settings/store">
                <input type="hidden" name="action" value="save_chat_settings">
                <div style="display:grid;gap:10px;max-width:760px;">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="tawk_enabled" value="1" <?= $chatEnabled ? 'checked' : '' ?>>
                        Activează chatul live pe site
                    </label>
                    <div class="field" style="max-width:420px;">
                        <label for="tawk_property_id">Property ID</label>
                        <input id="tawk_property_id" type="text" name="tawk_property_id" value="<?= htmlspecialchars($chatProperty, ENT_QUOTES) ?>" placeholder="ex: 66f1a2b3c4d5e6f708091a2b">
                        <small style="color:var(--ax-text-3);">Prima parte din linkul de instalare, după <code>embed.tawk.to/</code>.</small>
                    </div>
                    <div class="field" style="max-width:420px;">
                        <label for="tawk_widget_id">Widget ID</label>
                        <input id="tawk_widget_id" type="text" name="tawk_widget_id" value="<?= htmlspecialchars($chatWidget, ENT_QUOTES) ?>" placeholder="default">
                        <small style="color:var(--ax-text-3);">A doua parte din link. De obicei <code>default</code>.</small>
                    </div>
                    <div class="field" style="max-width:420px;">
                        <label for="tawk_position">Poziția bulinei</label>
                        <select id="tawk_position" name="tawk_position">
                            <option value="br" <?= $chatPozitie === 'br' ? 'selected' : '' ?>>Jos-dreapta</option>
                            <option value="bl" <?= $chatPozitie === 'bl' ? 'selected' : '' ?>>Jos-stânga</option>
                        </select>
                        <?php if ($chatParteCos === $chatPozitie): ?>
                            <small style="color:var(--ax-atentie);">
                                Coșul plutitor e în același colț. Ori muți unul din ele în partea
                                cealaltă, ori ridici bulina din câmpul de mai jos.
                            </small>
                        <?php else: ?>
                            <small style="color:var(--ax-text-3);">Coșul plutitor e în colțul opus, deci nu se suprapun.</small>
                        <?php endif; ?>
                    </div>
                    <div class="field" style="max-width:420px;">
                        <label for="tawk_offset_y">Ridică bulina cu (px de jos)</label>
                        <input id="tawk_offset_y" type="number" name="tawk_offset_y" min="0" max="400" step="1" value="<?= (int) $chatOffsetY ?>">
                        <small style="color:var(--ax-text-3);">
                            Distanța de la marginea de jos a ecranului. Implicit <strong>18</strong>.
                            Crește-o dacă bulina acoperă ceva — de la
                            <strong><?= (int) \App\Support\ChatLive::PRAG_DEGAJARE_COS ?></strong> în sus
                            trece deja peste coșul plutitor, iar coșul rămâne pe loc.
                            <?php if ($chatParteCos === $chatPozitie && $chatOffsetY < \App\Support\ChatLive::PRAG_DEGAJARE_COS): ?>
                                <br><span style="color:var(--ax-atentie);">Acum: bulina e sub coș, deci ridicăm coșul deasupra ei.</span>
                            <?php elseif ($chatParteCos === $chatPozitie): ?>
                                <br><span style="color:var(--ax-accent);">Acum: bulina e deasupra coșului, care rămâne unde e.</span>
                            <?php endif; ?>
                        </small>
                    </div>
                    <div class="field" style="max-width:520px;">
                        <label for="tawk_requires_consent">Când pornește chatul</label>
                        <select id="tawk_requires_consent" name="tawk_requires_consent">
                            <option value="1" <?= $chatCereConsimtamant ? 'selected' : '' ?>>Doar după „Accept toate" (recomandat)</option>
                            <option value="0" <?= !$chatCereConsimtamant ? 'selected' : '' ?>>Întotdeauna (chatul e considerat strict necesar)</option>
                        </select>
                        <small style="color:var(--ax-text-3);">
                            tawk.to e furnizor din Statele Unite, iar conversațiile pleacă acolo.
                            Pe varianta recomandată, codul nici nu ajunge în pagină înainte ca
                            vizitatorul să accepte — la fel ca la Analytics.
                        </small>
                    </div>
                    <div class="field" style="max-width:520px;">
                        <label for="tawk_api_key">Cheie Secure Mode (opțional)</label>
                        <input id="tawk_api_key" type="text" name="tawk_api_key" value="<?= htmlspecialchars($chatApiKey, ENT_QUOTES) ?>" placeholder="lasă gol dacă nu folosești">
                        <small style="color:var(--ax-text-3);">
                            Cu ea, emailul clientului logat pleacă semnat spre tawk.to, ca nimeni
                            să nu poată intra în chat pretinzând adresa altcuiva. Se ia din
                            tawk.to → Administration → Chat Widget → Secure Mode.
                        </small>
                    </div>
                    <div>
                        <button class="btn" type="submit">Salvează setările de chat</button>
                    </div>
                </div>
            </form>
            <div class="panel" style="margin:12px 0 0;background:var(--ax-suprafata-2);border-color:var(--ax-linie);">
                <h4 style="margin:0 0 8px;">Setup rapid (3 pași)</h4>
                <ol style="margin:0;padding-left:18px;display:grid;gap:6px;color:var(--ax-text-2);">
                    <li>Intră pe <strong>tawk.to</strong>, creează proprietatea pentru acest site.</li>
                    <li>La <strong>Administration → Chat Widget</strong> copiază linkul de instalare. Arată așa:
                        <code>https://embed.tawk.to/<em>PROPERTY_ID</em>/<em>WIDGET_ID</em></code></li>
                    <li>Pune cele două bucăți în câmpurile de mai sus, bifează activarea și salvează.</li>
                </ol>
                <p style="margin:10px 0 0;color:var(--ax-text-3);font-size:13px;">
                    De făcut și în contul tawk.to: programul de lucru cu mesaj de offline, textele
                    în română, formularul de pre-chat și oprirea înregistrării IP-ului.
                    Nu uita de politica de confidențialitate: tawk.to trebuie trecut acolo ca împuternicit.
                </p>
            </div>
            </div>
        </section>
        </div>
<?php endif; ?>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="store-favicon-modal">
    <div class="modal-card" style="max-width:900px;">
        <div class="modal-head">
            <h3>Selectează favicon din Galerie</h3>
            <button type="button" class="icon-btn" id="close-store-favicon-picker" aria-label="Închide">✕</button>
        </div>
        <div class="field" style="margin-bottom:10px;">
            <input type="text" id="store-favicon-search" placeholder="Caută imagine după titlu...">
        </div>
        <div class="product-picker-grid" id="store-favicon-grid">
            <?php foreach ($galleryImages as $image): ?>
                <button
                    type="button"
                    class="product-picker-item store-favicon-item"
                    data-image-url="<?= htmlspecialchars((string) ($image['image_url'] ?? ''), ENT_QUOTES) ?>"
                    data-search="<?= htmlspecialchars(strtolower((string) (($image['title'] ?? '') . ' ' . ($image['alt_text'] ?? '') . ' ' . ($image['image_url'] ?? ''))), ENT_QUOTES) ?>"
                >
                    <img loading="lazy" decoding="async" src="<?= htmlspecialchars((string) ($image['image_url'] ?? ''), ENT_QUOTES) ?>" alt="<?= htmlspecialchars((string) ($image['alt_text'] ?? $image['title'] ?? ''), ENT_QUOTES) ?>" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                    <strong><?= htmlspecialchars((string) (($image['title'] ?? '') !== '' ? $image['title'] : 'Imagine fără titlu'), ENT_QUOTES) ?></strong>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="modal-overlay" id="store-seo-image-modal">
    <div class="modal-card" style="max-width:900px;">
        <div class="modal-head">
            <h3>Selectează imagine SEO din Galerie</h3>
            <button type="button" class="icon-btn" id="close-seo-image-picker" aria-label="Închide">✕</button>
        </div>
        <div class="field" style="margin-bottom:10px;">
            <input type="text" id="seo-image-search" placeholder="Caută imagine după titlu...">
        </div>
        <div class="product-picker-grid" id="seo-image-grid">
            <?php foreach ($galleryImages as $image): ?>
                <button
                    type="button"
                    class="product-picker-item seo-image-item"
                    data-image-url="<?= htmlspecialchars((string) ($image['image_url'] ?? ''), ENT_QUOTES) ?>"
                    data-search="<?= htmlspecialchars(strtolower((string) (($image['title'] ?? '') . ' ' . ($image['alt_text'] ?? '') . ' ' . ($image['image_url'] ?? ''))), ENT_QUOTES) ?>"
                >
                    <img loading="lazy" decoding="async" src="<?= htmlspecialchars((string) ($image['image_url'] ?? ''), ENT_QUOTES) ?>" alt="<?= htmlspecialchars((string) ($image['alt_text'] ?? $image['title'] ?? ''), ENT_QUOTES) ?>" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                    <strong><?= htmlspecialchars((string) (($image['title'] ?? '') !== '' ? $image['title'] : 'Imagine fără titlu'), ENT_QUOTES) ?></strong>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
(() => {
    const modal = document.getElementById('store-favicon-modal');
    const openBtn = document.getElementById('open-store-favicon-picker');
    const closeBtn = document.getElementById('close-store-favicon-picker');
    const clearBtn = document.getElementById('clear-store-favicon-picker');
    const searchInput = document.getElementById('store-favicon-search');
    const items = Array.from(document.querySelectorAll('.store-favicon-item'));
    const input = document.getElementById('store_favicon_url');
    const preview = document.getElementById('store_favicon_preview');
    const label = document.getElementById('store_favicon_label');

    if (!(modal instanceof HTMLElement) || !(input instanceof HTMLInputElement) || !(preview instanceof HTMLImageElement) || !(label instanceof HTMLElement)) {
        return;
    }

    const fallback = '/assets/img/product-placeholder.svg';
    const setFavicon = (url) => {
        const safe = String(url || '').trim();
        input.value = safe;
        preview.src = safe !== '' ? safe : fallback;
        label.textContent = safe !== '' ? safe : fallback;
    };

    openBtn?.addEventListener('click', () => modal.classList.add('open'));
    closeBtn?.addEventListener('click', () => modal.classList.remove('open'));
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            modal.classList.remove('open');
        }
    });
    clearBtn?.addEventListener('click', () => setFavicon(''));
    searchInput?.addEventListener('input', () => {
        const query = (searchInput.value || '').trim().toLowerCase();
        items.forEach((item) => {
            const haystack = item.getAttribute('data-search') || '';
            item.style.display = haystack.includes(query) ? '' : 'none';
        });
    });
    items.forEach((item) => {
        item.addEventListener('click', () => {
            setFavicon(item.getAttribute('data-image-url') || '');
            modal.classList.remove('open');
        });
    });
})();

(() => {
    const modal = document.getElementById('store-seo-image-modal');
    const openBtn = document.getElementById('open-seo-image-picker');
    const closeBtn = document.getElementById('close-seo-image-picker');
    const clearBtn = document.getElementById('clear-seo-image-picker');
    const searchInput = document.getElementById('seo-image-search');
    const items = Array.from(document.querySelectorAll('.seo-image-item'));
    const input = document.getElementById('seo_default_image_url');
    const preview = document.getElementById('seo_default_image_preview');
    const label = document.getElementById('seo_default_image_label');

    if (!(modal instanceof HTMLElement) || !(input instanceof HTMLInputElement) || !(preview instanceof HTMLImageElement) || !(label instanceof HTMLElement)) {
        return;
    }

    const fallback = '/assets/img/product-placeholder.svg';
    const setImage = (url) => {
        const safe = String(url || '').trim();
        input.value = safe;
        preview.src = safe !== '' ? safe : fallback;
        label.textContent = safe !== '' ? safe : fallback;
    };

    openBtn?.addEventListener('click', () => modal.classList.add('open'));
    closeBtn?.addEventListener('click', () => modal.classList.remove('open'));
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            modal.classList.remove('open');
        }
    });
    clearBtn?.addEventListener('click', () => setImage(''));
    searchInput?.addEventListener('input', () => {
        const query = (searchInput.value || '').trim().toLowerCase();
        items.forEach((item) => {
            const haystack = item.getAttribute('data-search') || '';
            item.style.display = haystack.includes(query) ? '' : 'none';
        });
    });
    items.forEach((item) => {
        item.addEventListener('click', () => {
            setImage(item.getAttribute('data-image-url') || '');
            modal.classList.remove('open');
        });
    });
})();

(() => {
    const addRuleBtn = document.getElementById('add-cache-rule-btn');
    const wrap = document.getElementById('cache-rules-wrap');
    if (!(addRuleBtn instanceof HTMLElement) || !(wrap instanceof HTMLElement)) {
        return;
    }
    const ttlOptionsHtml = [
        { value: 300, label: '5 minute' },
        { value: 1800, label: '30 minute' },
        { value: 7200, label: '2 ore' },
        { value: 21600, label: '6 ore' },
        { value: 86400, label: '24 ore' },
    ].map((item) => `<option value="${item.value}">${item.label}</option>`).join('');
    addRuleBtn.addEventListener('click', () => {
        const row = document.createElement('div');
        row.className = 'cache-rule-row';
        row.style.display = 'grid';
        row.style.gridTemplateColumns = 'minmax(0,1fr) 180px auto';
        row.style.gap = '8px';
        row.style.alignItems = 'end';
        row.innerHTML = `
            <label style="display:grid;gap:4px;">
                <span>URL / pattern</span>
                <input type="text" name="cache_page_paths[]" value="" placeholder="/magazin sau /blog/*">
            </label>
            <label style="display:grid;gap:4px;">
                <span>TTL</span>
                <select name="cache_page_ttls[]">${ttlOptionsHtml}</select>
            </label>
            <button class="btn btn-secondary" type="button" data-remove-cache-rule>Elimină</button>
        `;
        wrap.appendChild(row);
    });
    wrap.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }
        const removeBtn = target.closest('[data-remove-cache-rule]');
        if (!(removeBtn instanceof HTMLElement)) {
            return;
        }
        const row = removeBtn.closest('.cache-rule-row');
        if (row instanceof HTMLElement) {
            row.remove();
        }
    });
})();
</script>
