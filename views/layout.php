<!doctype html>
<?php
// Pluginurile site-ului: bucățile pluginurilor pe care site-ul nu le are nu
// apar în pagină, iar linkurile spre paginile lor se scot din antet, meniu,
// subsol și conținut (Plugins::filtreazaLinkuri). Cu toate pluginurile pe
// site nu se schimbă nimic în HTML.
$filtruLinkuriPluginuri = !\App\Support\Plugins::nimicAscuns();
if ($filtruLinkuriPluginuri) {
    ob_start();
}
$areMagazin = \App\Support\Plugins::vizibil('magazin');
$magazinPrimesteComenzi = \App\Support\Plugins::permiteInceput('magazin');
$areCatalog = \App\Support\Plugins::vizibil('catalog');
$areFidelitate = \App\Support\Plugins::vizibil('fidelitate');
$appConfig = require __DIR__ . '/../config/app.php';
$db = \App\Support\Database::connection($appConfig['db']);
$currentCustomer = $db instanceof \PDO ? \App\Support\CustomerAuth::user($db) : null;
$designSettings = \App\Support\Settings::all($db);
$assetVersionToken = \App\Support\ResponseCache::assetVersionToken($designSettings);
$assetVersionQuery = $assetVersionToken !== '' ? '&v=' . urlencode($assetVersionToken) : '';
$designHeader = (string) ($designSettings['design_header_html'] ?? '');
$designFooter = (string) ($designSettings['design_footer_html'] ?? '');
$designMenu = (string) ($designSettings['design_menu_html'] ?? '');
$designHeaderCss = (string) ($designSettings['design_header_css'] ?? '');
$designHeaderJs = (string) ($designSettings['design_header_js'] ?? '');
$designFooterCss = (string) ($designSettings['design_footer_css'] ?? '');
$designFooterJs = (string) ($designSettings['design_footer_js'] ?? '');
$designMenuCss = (string) ($designSettings['design_menu_css'] ?? '');
$designMenuJs = (string) ($designSettings['design_menu_js'] ?? '');
// Antetul, meniul și subsolul sunt HTML salvat în baza de date. La afișare,
// linkurile de categorie /magazin?category=… devin /categorie/{slug} (adresele
// din Google și din sitemap), iar logo-ul din antet vine ca WebP mic, cu PNG-ul
// ca rezervă. Baza de date rămâne neatinsă. Paginile de coș, comandă, plată și
// cont rămân exact ca înainte (vezi LinkuriCategorii::paginaCurentaExclusa).
// Paginile categoriilor sunt ale catalogului: fără el pe site, linkurile spre
// magazin le scoate oricum filtrul pluginurilor, deci nu se mai citesc categoriile.
if (!\App\Support\LinkuriCategorii::paginaCurentaExclusa()) {
    if ($areCatalog) {
        $designHeader = \App\Support\LinkuriCategorii::rescrie($designHeader, $db instanceof \PDO ? $db : null);
        $designMenu = \App\Support\LinkuriCategorii::rescrie($designMenu, $db instanceof \PDO ? $db : null);
        $designFooter = \App\Support\LinkuriCategorii::rescrie($designFooter, $db instanceof \PDO ? $db : null);
    }
    $designHeader = \App\Support\LogoAntet::webp($designHeader);
}
// Fără consimțământ, niciun script de analiză/marketing nu ajunge în pagină; pe site-ul de verificare
// niciodată (docs/VERIFICARE.md), orice ar scrie în setări.
$acceptaUrmarire = \App\Support\CookieConsent::permiteUrmarire() && \App\Support\Verificare::eticheteTertePermise();
$chatActiv = \App\Support\ChatLive::activ($designSettings);
$chatUrcaCosul = \App\Support\ChatLive::suprapunePestecos($designSettings);
$clarityEnabled = $acceptaUrmarire && (string) ($designSettings['microsoft_clarity_enabled'] ?? '0') === '1';
$clarityProjectId = preg_replace('/[^a-zA-Z0-9]/', '', trim((string) ($designSettings['microsoft_clarity_project_id'] ?? ''))) ?? '';
$googleSiteVerification = \App\Support\Verificare::eticheteTertePermise()
    ? (preg_replace('/[^A-Za-z0-9_\-]/', '', trim((string) ($designSettings['google_site_verification'] ?? ''))) ?? '') : '';
$gaEnabled = $acceptaUrmarire && (string) ($designSettings['google_analytics_enabled'] ?? '0') === '1';
$gaId = preg_replace('/[^A-Za-z0-9\-]/', '', trim((string) ($designSettings['google_analytics_id'] ?? ''))) ?? '';
$gtmEnabled = $acceptaUrmarire && (string) ($designSettings['google_tag_manager_enabled'] ?? '0') === '1';
$gtmId = preg_replace('/[^A-Za-z0-9\-]/', '', trim((string) ($designSettings['google_tag_manager_id'] ?? ''))) ?? '';
$gtmHeadCode = trim((string) ($designSettings['google_tag_manager_head_code'] ?? ''));
$gtmBodyCode = trim((string) ($designSettings['google_tag_manager_body_code'] ?? ''));
$gaCode = trim((string) ($designSettings['google_analytics_code'] ?? ''));
$adsEnabled = $acceptaUrmarire && (string) ($designSettings['google_ads_enabled'] ?? '0') === '1';
$adsConversionId = preg_replace('/[^A-Za-z0-9\-]/', '', trim((string) ($designSettings['google_ads_conversion_id'] ?? ''))) ?? '';
$headerCartCount = \App\Support\Cart::countItems();
$headerCustomerLoggedIn = is_array($currentCustomer);
$headerCustomerPoints = $headerCustomerLoggedIn ? max(0, (int) ($currentCustomer['loyalty_points'] ?? 0)) : 0;
// Antetul și subsolul scrise în admin pot avea {{cart_count}}; fără magazin, codul dispare.
$designHeader = \App\Support\Plugins::curataTokenuri($designHeader);
$designFooter = \App\Support\Plugins::curataTokenuri($designFooter);
$designMenu = \App\Support\Plugins::curataTokenuri($designMenu);
$cartCountTokens = ['{{cart_count}}', '{{ cart_count }}'];
$mobileMenuToken = '{{mobile_menu}}';
$mobileMenuTokenPattern = '/\{\{\s*mobile_menu\s*\}\}/i';
// Logo pentru sertarul de meniu mobil: cel al temei site-ului (tema.json → logo_meniu_mobil: primul candidat care
// există, altfel cel implicit al ei); fără el, ca până acum, primul fișier existent din galerie.
// Logo pentru sertarul de meniu mobil (WP-16, DB-09): Setări → `store_logo_url`, altfel cel al temei site-ului (tema.json
// → logo_meniu_mobil: primul candidat care există, altfel cel implicit al ei), altfel
// numele site-ului ca text. Textul alternativ: al temei, altfel numele site-ului.
$numeSiteAntet = \App\Support\Brand::numeSite($designSettings);
$mobileMenuLogoPath = trim((string) ($designSettings['store_logo_url'] ?? ''));
$mobileMenuLogoTema = \App\Support\Tema::logoMeniuMobil();
$mobileMenuLogoAlt = $mobileMenuLogoTema !== null && $mobileMenuLogoTema['alt'] !== '' ? $mobileMenuLogoTema['alt'] : $numeSiteAntet;
if ($mobileMenuLogoPath === '' && $mobileMenuLogoTema !== null) {
    $mobileMenuLogoPath = $mobileMenuLogoTema['cale'];
}
// Culoarea principală a bannerelor de mai jos (tema.json → culoare_principala; implicit #2f8d5b, ca până acum).
$culoarePrincipala = \App\Support\Tema::culoarePrincipala();
$mobileMenuFallbackHtml = '<ul class="menu-root"><li><a href="/">Acasă</a></li><li><a href="' . htmlspecialchars(\App\Support\Catalog::url($designSettings), ENT_QUOTES) . '">Magazin</a></li><li><a href="/blog">Blog</a></li><li><a href="/contact">Contact</a></li></ul>';
$mobileMenuTokenAssetsEnabled = false;
// Coșul flotant e al magazinului; când magazinul se oprește nu mai primește produse noi.
$floatingCartEnabled = $magazinPrimesteComenzi && (string) ($designSettings['floating_cart_enabled'] ?? '1') === '1';
$floatingCartExcludedRaw = (string) ($designSettings['floating_cart_excluded_urls'] ?? '');
$floatingCartExcludedPaths = [];
foreach (preg_split('/\r\n|\r|\n/', $floatingCartExcludedRaw) ?: [] as $rawPath) {
    $candidatePath = trim((string) $rawPath);
    if ($candidatePath === '') {
        continue;
    }
    if (preg_match('/^https?:\/\//i', $candidatePath) === 1) {
        $parsedPath = parse_url($candidatePath, PHP_URL_PATH);
        if (is_string($parsedPath) && trim($parsedPath) !== '') {
            $candidatePath = trim($parsedPath);
        } else {
            continue;
        }
    }
    if (!str_starts_with($candidatePath, '/')) {
        $candidatePath = '/' . ltrim($candidatePath, '/');
    }
    $candidatePath = rtrim($candidatePath, '/');
    if ($candidatePath === '') {
        $candidatePath = '/';
    }
    $floatingCartExcludedPaths[] = $candidatePath;
}
$floatingCartExcludedPaths = array_values(array_unique($floatingCartExcludedPaths));
$currentPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$currentPath = is_string($currentPath) ? $currentPath : '/';
$currentPath = rtrim($currentPath, '/');
if ($currentPath === '') {
    $currentPath = '/';
}
if (in_array($currentPath, $floatingCartExcludedPaths, true)) {
    $floatingCartEnabled = false;
}
$pointsTextTemplate = trim((string) ($designSettings['floating_cart_points_text'] ?? ''));
if ($pointsTextTemplate === '') {
    $legacyPrefix = trim((string) ($designSettings['floating_cart_points_label_prefix'] ?? 'Primești'));
    $legacySuffix = trim((string) ($designSettings['floating_cart_points_label_suffix'] ?? 'puncte la această comandă'));
    $pointsTextTemplate = trim($legacyPrefix . ' {points} ' . $legacySuffix);
    if ($pointsTextTemplate === '') {
        $pointsTextTemplate = 'Primești {points} puncte la această comandă.';
    }
}
$floatingCartConfig = [
    'enabled' => $floatingCartEnabled ? 1 : 0,
    'label' => (string) ($designSettings['floating_cart_trigger_label'] ?? 'Coș'),
    'position' => in_array((string) ($designSettings['floating_cart_position'] ?? 'right'), ['left', 'right'], true)
        ? (string) ($designSettings['floating_cart_position'] ?? 'right')
        : 'right',
    'panelTitle' => (string) ($designSettings['floating_cart_title'] ?? 'Coșul tău'),
    'showSku' => 0,
    'showPrice' => 1,
    'showSubtotal' => ((string) ($designSettings['floating_cart_show_subtotal'] ?? '1') === '1') ? 1 : 0,
    'showDiscount' => ((string) ($designSettings['floating_cart_show_discount'] ?? '1') === '1') ? 1 : 0,
    'showPointsDiscount' => ((string) ($designSettings['floating_cart_show_points_discount'] ?? '1') === '1') ? 1 : 0,
    'showShipping' => ((string) ($designSettings['floating_cart_show_shipping'] ?? '1') === '1') ? 1 : 0,
    'showVat' => ((string) ($designSettings['floating_cart_show_vat'] ?? '1') === '1') ? 1 : 0,
    'showEstimatedEarnedPoints' => ((string) ($designSettings['floating_cart_show_points_earned'] ?? '1') === '1') ? 1 : 0,
    'pointsPosition' => in_array((string) ($designSettings['floating_cart_points_position'] ?? 'before_total'), ['before_total', 'after_total'], true)
        ? (string) ($designSettings['floating_cart_points_position'] ?? 'before_total')
        : 'before_total',
    'pointsTextTemplate' => $pointsTextTemplate,
    'buttonBg' => (string) ($designSettings['floating_cart_accent_color'] ?? '#0f766e'),
    'buttonText' => '#ffffff',
    'buttonCounterBg' => (string) ($designSettings['floating_cart_badge_bg'] ?? '#ffffff'),
    'buttonCounterText' => (string) ($designSettings['floating_cart_badge_text'] ?? '#0f766e'),
    'showOnDesktop' => ((string) ($designSettings['floating_cart_show_desktop'] ?? '1') === '1') ? 1 : 0,
    'showOnMobile' => ((string) ($designSettings['floating_cart_show_mobile'] ?? '1') === '1') ? 1 : 0,
    // Keep both keys for backward compatibility across script revisions.
    'autoOpenOnAdd' => ((string) ($designSettings['floating_cart_auto_open_on_add'] ?? '1') === '1') ? 1 : 0,
    'openOnAdd' => ((string) ($designSettings['floating_cart_auto_open_on_add'] ?? '1') === '1') ? 1 : 0,
    'showViewCartButton' => ((string) ($designSettings['floating_cart_show_view_cart_button'] ?? '1') === '1') ? 1 : 0,
    'showCheckoutButton' => ((string) ($designSettings['floating_cart_show_checkout_button'] ?? '1') === '1') ? 1 : 0,
    'viewCartLabel' => (string) ($designSettings['floating_cart_view_cart_label'] ?? 'Vezi coșul'),
    'checkoutLabel' => (string) ($designSettings['floating_cart_checkout_label'] ?? 'Finalizează comanda'),
    'continueShoppingLabel' => (string) ($designSettings['floating_cart_continue_shopping_label'] ?? 'Continuă cumpărăturile'),
    'emptyCtaLabel' => (string) ($designSettings['floating_cart_empty_cta_label'] ?? 'Vezi produsele'),
    'panelWidth' => (int) ($designSettings['floating_cart_panel_width'] ?? 420),
    'offsetX' => (int) ($designSettings['floating_cart_offset_x'] ?? 18),
    'offsetY' => (int) ($designSettings['floating_cart_offset_y'] ?? 18),
    'triggerIconSvg' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l1.7 8.1a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 1.9-1.4l1.5-5.2H7.4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><circle cx="8.7" cy="19" r="1.4" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="17.5" cy="19" r="1.4" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>',
    'showImages' => ((string) ($designSettings['floating_cart_show_product_images'] ?? '1') === '1') ? 1 : 0,
    'triggerIcon' => (string) ($designSettings['floating_cart_trigger_icon'] ?? ''),
    'quantityControlStyle' => in_array((string) ($designSettings['store_quantity_control_style'] ?? 'default'), ['default', 'stepper'], true)
        ? (string) ($designSettings['store_quantity_control_style'] ?? 'default')
        : 'default',
    'quantityApplyFloatingCart' => (
        ((string) ($designSettings['store_quantity_apply_floating_cart'] ?? '0') === '1')
        && ((string) ($designSettings['store_quantity_control_style'] ?? 'default') === 'stepper')
    ) ? 1 : 0,
    'quantityControlEnabled' => (
        ((string) ($designSettings['store_quantity_apply_floating_cart'] ?? '0') === '1')
        && ((string) ($designSettings['store_quantity_control_style'] ?? 'default') === 'stepper')
    ) ? 1 : 0,
];
// Pluginul „Cupoane”: câmpul de cupon din coșul flotant doar cât pluginul e pornit.
// Cu cupoanele pe site cheia nu se pune deloc (configurația rămâne cea de dinainte).
if (!\App\Support\Plugins::permiteInceput('cupoane')) {
    $floatingCartConfig['faraCupon'] = 1;
}
// Sertarul lateral „Oferte” se poate dezactiva din Admin -> Setări magazin.
// Pluginul „Oferte BBD”: ofertele noi se arată doar cât pluginul e pornit.
$bbdSidebarEnabled = \App\Support\Plugins::permiteInceput('oferte_bbd') && (string) ($designSettings['store_bbd_sidebar_enabled'] ?? '1') !== '0';
$bbdSidebarOffers = $bbdSidebarEnabled ? \App\Support\BbdSidebarOffers::load($db, 10) : [];
$siteFaviconUrl = trim((string) ($designSettings['store_favicon_url'] ?? ''));
if ($siteFaviconUrl === '') {
    $siteFaviconUrl = '/assets/img/product-placeholder.svg';
}
$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$requestPath = is_string($requestPath) ? $requestPath : '/';
$requestPath = rtrim($requestPath, '/');
if ($requestPath === '') {
    $requestPath = '/';
}
// Titlul, descrierea, canonicalul, robots și og:type ale paginii: App\Support\Seo::rezolva (WP-37), aceeași regulă ca
// până acum. Numele magazinului din titluri: Admin → Setări magazin → SEO Google; gol = seo.json → organizatie.nume
// al site-ului, altfel APP_NAME (SeoMeta::numeMagazin). Fără canonical (și fără og:url) pe pagina „nu există”
// (404/410) și unde controllerul o cere (căutarea internă cu noindex); paginile de coș, comandă, plată și cont
// rămân exact ca înainte, și pe 404 (SeoIndexare).
$appUrl = rtrim((string) ($appConfig['url'] ?? ''), '/');
if ($appUrl === '') {
    $https = (string) ($_SERVER['HTTPS'] ?? '');
    $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $appUrl = $scheme . '://' . $host;
}
$seoFinal = \App\Support\Seo::rezolva([
    'metaTitle' => $metaTitle ?? '',
    'metaDescription' => $metaDescription ?? '',
    'metaCanonicalUrl' => $metaCanonicalUrl ?? '',
    'metaRobots' => $metaRobots ?? '',
    'metaOgType' => $metaOgType ?? '',
    'faraCanonical' => !empty($faraCanonical),
    'title' => $title ?? '',
    'cale' => $requestPath,
    'cerere' => (string) ($_SERVER['REQUEST_URI'] ?? '/'),
    'cod' => (int) http_response_code(),
    'appUrl' => $appUrl,
    'setari' => $designSettings,
]);
$siteBaseTitle = $seoFinal['nume_magazin'];
$pageTitle = $seoFinal['titlu_pagina'];
$metaTitleFinal = $seoFinal['titlu'];
$metaDescriptionFinal = $seoFinal['descriere'];
$canonicalUrlFinal = $seoFinal['canonical'];
$metaImageUrlFinal = trim((string) ($metaImageUrl ?? ''));
// Poza de distribuire (Facebook, WhatsApp, X): varianta 1200×630 a pozei
// paginii (produsul: poza lui principală), altfel cea implicită din Setări
// magazin → SEO Google, altfel logo-ul. Vezi App\Support\ImagineDistribuire.
// Paginile de coș, comandă, plată și cont rămân exact ca înainte.
$distribuireNoua = !\App\Support\LinkuriCategorii::paginaCurentaExclusa();
$imagineDistribuire = null;
if ($distribuireNoua) {
    if ($metaImageUrlFinal !== '') {
        $imagineDistribuire = \App\Support\ImagineDistribuire::pentru($metaImageUrlFinal, $appUrl);
    }
    if ($imagineDistribuire !== null) {
        // Poza paginii (produsul: numele lui; altfel titlul paginii).
        $imagineDistribuireAlt = trim((string) ($metaImageAlt ?? ''));
        if ($imagineDistribuireAlt === '') {
            $imagineDistribuireAlt = $pageTitle !== '' ? $pageTitle : $siteBaseTitle;
        }
    } else {
        // Poza implicită (logo-ul sau cea din setări) nu arată pagina: textul
        // alternativ o descrie pe ea („Logo Bioscem”), nu titlul paginii.
        $imagineDistribuire = \App\Support\ImagineDistribuire::implicita($designSettings, $appUrl);
        $imagineDistribuireAlt = is_array($imagineDistribuire)
            ? \App\Support\ImagineDistribuire::altImplicit($imagineDistribuire, $siteBaseTitle)
            : $siteBaseTitle;
    }
    $metaImageUrlFinal = (string) ($imagineDistribuire['url'] ?? '');
}
if ($metaImageUrlFinal === '') {
    $metaImageUrlFinal = trim((string) ($designSettings['store_seo_default_image_url'] ?? ''));
}
if ($metaImageUrlFinal !== '' && !preg_match('/^https?:\/\//i', $metaImageUrlFinal)) {
    $metaImageUrlFinal = str_starts_with($metaImageUrlFinal, '/')
        ? ($appUrl . $metaImageUrlFinal)
        : ($appUrl . '/' . ltrim($metaImageUrlFinal, '/'));
}
$siteFaviconUrlEscaped = htmlspecialchars($siteFaviconUrl, ENT_QUOTES);
$designHeaderOutput = $designHeader;
// GR-06 (din grafoanaytis): {{an}} = anul curent, ca nota de copyright să nu rămână pe un an vechi. Înaintea meniului,
// deci doar în HTML-ul scris în Design site. O pagină din cache păstrează anul până expiră (cel mult o zi).
$designHeaderOutput = str_replace('{{an}}', date('Y'), $designHeaderOutput);
if (trim($designHeaderOutput) !== '' && trim($designMenu) !== '') {
    $designHeaderOutput = str_replace(['{{menu}}', '{{ menu }}'], $designMenu, $designHeaderOutput);
}
if (trim($designHeaderOutput) !== '') {
    $designHeaderOutput = str_replace($cartCountTokens, (string) $headerCartCount, $designHeaderOutput);
}
if (trim($designHeaderOutput) !== '' && preg_match($mobileMenuTokenPattern, $designHeaderOutput) === 1) {
    $mobileMenuTokenAssetsEnabled = true;
    $mobileMenuHtml = trim($designMenu) !== '' ? $designMenu : $mobileMenuFallbackHtml;
    $mobileMenuTokenMarkup = '<div class="bv-mobile-menu-token" data-bv-mobile-menu>'
        . '<button class="bv-mobile-menu-token__toggle" type="button" aria-label="Deschide meniul" aria-expanded="false" data-bv-mobile-menu-toggle>'
        . '<span></span><span></span><span></span>'
        . '</button>'
        . '<div class="bv-mobile-menu-token__overlay" data-bv-mobile-menu-overlay hidden></div>'
        . '<aside class="bv-mobile-menu-token__drawer" data-bv-mobile-menu-drawer aria-hidden="true">'
        . '<div class="bv-mobile-menu-token__drawer-head">'
        . '<a href="/" class="bv-mobile-menu-token__brand" aria-label="Acasă">'
        . ($mobileMenuLogoPath !== ''
            ? '<img src="' . htmlspecialchars($mobileMenuLogoPath, ENT_QUOTES) . '" alt="' . htmlspecialchars($mobileMenuLogoAlt, ENT_QUOTES) . '"' . \App\Support\PozeOptimizate::dimensiuni($mobileMenuLogoPath) . ' loading="lazy">'
            : '<span class="bv-mobile-menu-token__brand-text">' . htmlspecialchars($numeSiteAntet, ENT_QUOTES) . '</span>')
        . '</a>'
        . '<button class="bv-mobile-menu-token__close" type="button" aria-label="Închide meniul" data-bv-mobile-menu-close>&times;</button>'
        . '</div>'
        . '<nav class="bv-mobile-menu-token__nav" aria-label="Meniu mobil">' . $mobileMenuHtml . '</nav>'
        . '<div class="bv-mobile-menu-token__links">'
        . '<a href="/login">' . htmlspecialchars(\App\Support\Brand::etichetaCont($designSettings), ENT_QUOTES) . '</a>'
        . '</div>'
        . '</aside>'
        . '</div>';
    $designHeaderOutput = (string) preg_replace($mobileMenuTokenPattern, $mobileMenuTokenMarkup, $designHeaderOutput);
}
?>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($googleSiteVerification !== ''): ?>
    <meta name="google-site-verification" content="<?= htmlspecialchars($googleSiteVerification, ENT_QUOTES) ?>">
    <?php endif; ?>
    <?php if ($gtmEnabled && $gtmHeadCode !== ''): ?>
    <?= $gtmHeadCode ?>
    <?php elseif ($gtmEnabled && $gtmId !== ''): ?>
    <!-- Google Tag Manager -->
    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
    new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
    j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
    'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
    })(window,document,'script','dataLayer','<?= htmlspecialchars($gtmId, ENT_QUOTES) ?>');</script>
    <!-- End Google Tag Manager -->
    <?php endif; ?>
    <?php if ($gaEnabled && $gaCode !== ''): ?>
    <?= $gaCode ?>
        <?php if ($adsEnabled && $adsConversionId !== ''): ?>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('config', '<?= htmlspecialchars($adsConversionId, ENT_QUOTES) ?>');
        </script>
        <?php endif; ?>
    <?php elseif (($gaEnabled && $gaId !== '') || ($adsEnabled && $adsConversionId !== '')): ?>
    <?php $gtagBoot = $gaEnabled && $gaId !== '' ? $gaId : $adsConversionId; ?>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars($gtagBoot, ENT_QUOTES) ?>"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        <?php if ($gaEnabled && $gaId !== ''): ?>
        gtag('config', '<?= htmlspecialchars($gaId, ENT_QUOTES) ?>');
        <?php endif; ?>
        <?php if ($adsEnabled && $adsConversionId !== ''): ?>
        gtag('config', '<?= htmlspecialchars($adsConversionId, ENT_QUOTES) ?>');
        <?php endif; ?>
    </script>
    <?php endif; ?>
    <title><?= htmlspecialchars($metaTitleFinal, ENT_QUOTES) ?></title>
    <?php if ($metaDescriptionFinal !== ''): ?>
        <meta name="description" content="<?= htmlspecialchars($metaDescriptionFinal, ENT_QUOTES) ?>">
    <?php endif; ?>
    <?php if ($canonicalUrlFinal !== ''): ?>
        <link rel="canonical" href="<?= htmlspecialchars($canonicalUrlFinal, ENT_QUOTES) ?>">
    <?php endif; ?>
<?php /* „noindex,follow” trimis de controller în $metaRobots: căutarea internă (/magazin?q=…)
și /blog fără articole publicate (vezi App\Support\SeoIndexare), sau robots-ul scris pentru pagină în panoul SEO (WP-37).
Celelalte pagini nu au etichetă. */
if ($seoFinal['robots'] !== '') {
    echo '    <meta name="robots" content="', htmlspecialchars($seoFinal['robots'], ENT_QUOTES), '">', "\n";
} ?>
<?php /* Date structurate (produs, firimituri, firmă, site): un bloc <script> pentru fiecare tip. */
foreach ((isset($jsonLd) && is_array($jsonLd) ? $jsonLd : []) as $jsonLdNod) {
    $jsonLdScript = is_array($jsonLdNod) ? \App\Support\JsonLd::script($jsonLdNod) : '';
    if ($jsonLdScript !== '') {
        echo '    ', $jsonLdScript, "\n";
    }
} ?>
    <meta property="og:type" content="<?= htmlspecialchars($seoFinal['og_type'], ENT_QUOTES) ?>">
<?php if ($distribuireNoua): ?>
    <meta property="og:site_name" content="<?= htmlspecialchars($siteBaseTitle, ENT_QUOTES) ?>">
    <meta property="og:locale" content="ro_RO">
<?php endif; ?>
    <meta property="og:title" content="<?= htmlspecialchars($metaTitleFinal, ENT_QUOTES) ?>">
    <?php if ($metaDescriptionFinal !== ''): ?>
        <meta property="og:description" content="<?= htmlspecialchars($metaDescriptionFinal, ENT_QUOTES) ?>">
    <?php endif; ?>
    <?php if ($canonicalUrlFinal !== ''): ?>
        <meta property="og:url" content="<?= htmlspecialchars($canonicalUrlFinal, ENT_QUOTES) ?>">
    <?php endif; ?>
    <?php if ($metaImageUrlFinal !== ''): ?>
        <meta property="og:image" content="<?= htmlspecialchars($metaImageUrlFinal, ENT_QUOTES) ?>">
<?php if ($distribuireNoua && is_array($imagineDistribuire)): ?>
<?php if ((string) ($imagineDistribuire['tip'] ?? '') !== ''): ?>
        <meta property="og:image:type" content="<?= htmlspecialchars((string) $imagineDistribuire['tip'], ENT_QUOTES) ?>">
<?php endif; ?>
<?php if ((int) ($imagineDistribuire['latime'] ?? 0) > 0 && (int) ($imagineDistribuire['inaltime'] ?? 0) > 0): ?>
        <meta property="og:image:width" content="<?= (int) $imagineDistribuire['latime'] ?>">
        <meta property="og:image:height" content="<?= (int) $imagineDistribuire['inaltime'] ?>">
<?php endif; ?>
        <meta property="og:image:alt" content="<?= htmlspecialchars($imagineDistribuireAlt, ENT_QUOTES) ?>">
<?php endif; ?>
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="<?= htmlspecialchars($metaImageUrlFinal, ENT_QUOTES) ?>">
<?php if ($distribuireNoua && is_array($imagineDistribuire)): ?>
        <meta name="twitter:image:alt" content="<?= htmlspecialchars($imagineDistribuireAlt, ENT_QUOTES) ?>">
<?php endif; ?>
    <?php else: ?>
        <meta name="twitter:card" content="summary">
    <?php endif; ?>
    <meta name="twitter:title" content="<?= htmlspecialchars($metaTitleFinal, ENT_QUOTES) ?>">
    <?php if ($metaDescriptionFinal !== ''): ?>
        <meta name="twitter:description" content="<?= htmlspecialchars($metaDescriptionFinal, ENT_QUOTES) ?>">
    <?php endif; ?>
    <link rel="icon" href="<?= $siteFaviconUrlEscaped ?>">
    <link rel="shortcut icon" href="<?= $siteFaviconUrlEscaped ?>">
    <link rel="apple-touch-icon" href="<?= $siteFaviconUrlEscaped ?>">
<?php
// Tema site-ului (App\Support\Tema, tema/tema.json din dosarul site-ului; docs/PACHETE.md): fonturile, Bootstrap-ul,
// app.css și foile de stil ale temei, în această ordine; CSS-ul din Design vine după ele. Fără temă: fonturile din nucleu
// (WP-40, B6; DM Sans și Playfair, cum le dădea Google Fonts) și app.css. Blocurile stau pe coloana 0, deci nu lasă nimic în pagină când nu se
// afișează.
?>
<?php if (\App\Support\Tema::fonturiGoogle()): ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
<?php endif; ?>
<?php foreach (\App\Support\Tema::fonturi() as $temaFont): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(\App\Support\Tema::url($temaFont), ENT_QUOTES) ?>">
<?php endforeach; ?>
<?php if (\App\Support\Tema::bootstrap()): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(\App\Support\Tema::url(\App\Support\Tema::BOOTSTRAP_CSS), ENT_QUOTES) ?>">
<?php endif; ?>
<?php if (\App\Support\Tema::incarcaAppCss()): ?>
    <?php $cssVersion = @filemtime(\App\Support\Paths::public('assets/css/app.css')) ?: time(); ?>
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= $cssVersion ?><?= $assetVersionQuery ?>">
<?php endif; ?>
<?php foreach (\App\Support\Tema::css() as $temaCss): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(\App\Support\Tema::url($temaCss), ENT_QUOTES) ?>">
<?php endforeach; ?>
    <?php
    $designCss = trim(implode("\n", array_filter([
        $designHeaderCss,
        $designMenuCss,
        $designFooterCss,
    ], static fn (string $part): bool => trim($part) !== '')));
    ?>
    <?php if ($designCss !== ''): ?>
        <style><?= $designCss ?></style>
    <?php endif; ?>
<?php if (!\App\Support\SablonSite::inFisiere($designSettings)): ?>
    <style><?= \App\Support\SablonSite::continut('sablon-site.css') ?></style>
<?php else: ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(\App\Support\SablonSite::url('sablon-site.css'), ENT_QUOTES) ?>">
<?php endif; ?>
</head>
<body class="site-shell">
<?= \App\Support\Hooks::html('layout.inceput_body') /* bannerul campaniei active (pluginul campanii); gol în rest */ ?>
<?php if (\App\Support\Tema::butonSus()): ?>
<?php /* Ținta butonului „Înapoi sus” (tema.json → buton_sus, GR b4159bd): fără ea, ancora n-ar avea unde duce focusul. */ ?>
<span id="inceput-pagina"></span>
<?php endif; ?>
    <?php echo \App\Support\Verificare::banner('site'); /* bannerul copiei de verificare; gol în rest */ if ($gtmEnabled && $gtmBodyCode !== ''): ?>
    <?= $gtmBodyCode ?>
    <?php elseif ($gtmEnabled && $gtmId !== ''): ?>
    <!-- Google Tag Manager (noscript) -->
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?= htmlspecialchars($gtmId, ENT_QUOTES) ?>"
    height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    <!-- End Google Tag Manager (noscript) -->
    <?php endif; ?>
    <?php if (trim($designHeaderOutput) !== ''): ?>
        <?= $designHeaderOutput ?>
    <?php else: ?>
        <header class="header">
            <div class="container site-container header-inner">
                <a href="/" class="logo"><?= htmlspecialchars($numeSiteAntet, ENT_QUOTES) ?></a>
                <nav class="nav">
                    <?php if (trim($designMenu) !== ''): ?>
                        <?= $designMenu ?>
                    <?php else: ?>
                        <a href="/">Acasă</a>
                        <a href="<?= htmlspecialchars(\App\Support\Catalog::url($designSettings), ENT_QUOTES) ?>">Magazin</a>
                        <a href="/cos">Coș (<?= \App\Support\Cart::countItems() ?>)</a>
                        <a href="/checkout">Checkout</a>
                        <a href="/blog">Blog</a>
                        <?php if (is_array($currentCustomer)): ?>
                            <a href="/contul-meu">Cont (<?= htmlspecialchars(\App\Support\CustomerDisplay::nume((string) ($currentCustomer['first_name'] ?? ''), (string) ($currentCustomer['last_name'] ?? ''), (string) ($currentCustomer['email'] ?? '')), ENT_QUOTES) ?>)</a>
                        <?php else: ?>
                            <a href="/login">Contul meu</a>
                        <?php endif; ?>
                        <a href="/contact">Contact</a>
                    <?php endif; ?>
                </nav>
            </div>
        </header>
    <?php endif; ?>

    <?php if ($bbdSidebarEnabled): ?>
    <aside class="bv-bbd-sidebar" data-bv-bbd-sidebar>
        <button
            class="bv-bbd-sidebar__handle"
            type="button"
            aria-label="Deschide ofertele BBD"
            aria-expanded="false"
            aria-controls="bv-bbd-sidebar-panel"
            data-bv-bbd-sidebar-handle
        ><span>Oferte</span></button>
        <div class="bv-bbd-sidebar__panel" id="bv-bbd-sidebar-panel">
            <div class="bv-bbd-sidebar__head">
                <div>
                    <h3>Produse cu preț redus</h3>
                </div>
                <button type="button" class="bv-bbd-sidebar__close" data-bv-bbd-sidebar-close aria-label="Închide panoul">×</button>
            </div>
            <ul class="bv-bbd-sidebar__list">
                <?php if ($bbdSidebarOffers === []): ?>
                    <li>
                        <a class="bv-bbd-sidebar__item" href="<?= htmlspecialchars(\App\Support\Catalog::url($designSettings), ENT_QUOTES) ?>">
                            <img src="/assets/img/product-placeholder.svg" alt="Produse" loading="lazy">
                            <div>
                                <h4 class="bv-bbd-sidebar__item-name">Momentan nu sunt oferte disponibile</h4>
                                <p class="bv-bbd-sidebar__item-label">Verifică produsele din magazin pentru oferte active.</p>
                            </div>
                        </a>
                    </li>
                <?php else: ?>
                    <?php foreach ($bbdSidebarOffers as $offer): ?>
                        <?php
                        $offerName = trim((string) ($offer['name'] ?? 'Produs'));
                        $offerUrl = trim((string) ($offer['url'] ?? \App\Support\Catalog::url($designSettings)));
                        $offerImage = \App\Support\PozeOptimizate::src(trim((string) ($offer['image_url'] ?? '/assets/img/product-placeholder.svg')));
                        $offerCount = max(1, (int) ($offer['offer_count'] ?? 1));
                        $offerPrice = max(0.0, (float) ($offer['price'] ?? 0.0));
                        $offerComparePrice = isset($offer['compare_at_price']) ? max(0.0, (float) ($offer['compare_at_price'] ?? 0.0)) : 0.0;
                        ?>
                        <li>
                            <a class="bv-bbd-sidebar__item" href="<?= htmlspecialchars($offerUrl, ENT_QUOTES) ?>">
                                <img src="<?= htmlspecialchars($offerImage, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($offerName, ENT_QUOTES) ?>"<?= \App\Support\PozeOptimizate::dimensiuni($offerImage) ?> loading="lazy">
                                <div>
                                    <h4 class="bv-bbd-sidebar__item-name"><?= htmlspecialchars($offerName, ENT_QUOTES) ?></h4>
                                    <p class="bv-bbd-sidebar__item-label">
                                        <?= htmlspecialchars($offerCount === 1 ? '1 ofertă activă' : ($offerCount . ' oferte active'), ENT_QUOTES) ?>
                                    </p>
                                    <div class="bv-bbd-sidebar__item-prices">
                                        <strong><?= htmlspecialchars(number_format($offerPrice, 2, ',', '.'), ENT_QUOTES) ?> lei</strong>
                                        <?php if ($offerComparePrice > $offerPrice): ?>
                                            <del><?= htmlspecialchars(number_format($offerComparePrice, 2, ',', '.'), ENT_QUOTES) ?> lei</del>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </a>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </aside>
    <?php endif; ?>

<?php /* tema.json → main „lat” (GR 74e4b25): <main> pe toată lățimea, fără .container; secțiunile paginilor își pun
             singure containerul, iar mesajele de mai jos îl primesc pe al lor (flash „bootstrap”). */ ?>
    <main class="<?= \App\Support\Tema::clasaMain() ?>">
<?php if (\App\Support\Tema::flash() === 'bootstrap'): ?>
        <?php if (($message = \App\Support\Flash::get('success')) !== null): ?>
            <div class="container mt-3">
                <div class="alert alert-success mb-0"><?= htmlspecialchars($message, ENT_QUOTES) ?></div>
            </div>
        <?php endif; ?>
        <?php if (($message = \App\Support\Flash::get('error')) !== null): ?>
            <div class="container mt-3">
                <div class="alert alert-danger mb-0"><?= htmlspecialchars($message, ENT_QUOTES) ?></div>
            </div>
        <?php endif; ?>
<?php else: ?>
        <?php if (($message = \App\Support\Flash::get('success')) !== null): ?>
            <section class="panel" style="border-color:#34d399;background:#ecfdf5;color:#065f46;">
                <?= htmlspecialchars($message, ENT_QUOTES) ?>
            </section>
        <?php endif; ?>
        <?php if (($message = \App\Support\Flash::get('error')) !== null): ?>
            <section class="panel" style="border-color:#fca5a5;background:#fef2f2;color:#991b1b;">
                <?= htmlspecialchars($message, ENT_QUOTES) ?>
            </section>
        <?php endif; ?>
<?php endif; ?>
        <?= $content ?>
    </main>

    <?php if (trim($designFooter) !== ''): ?>
        <?php
        // Aceleasi token-uri ca in header: meniul si numarul de produse din cos.
        $designFooterOutput = str_replace('{{an}}', date('Y'), $designFooter);
        if (trim($designMenu) !== '') {
            $designFooterOutput = str_replace(['{{menu}}', '{{ menu }}'], $designMenu, $designFooterOutput);
        }
        $designFooterOutput = str_replace($cartCountTokens, (string) $headerCartCount, $designFooterOutput);
        ?>
        <?= $designFooterOutput ?>
    <?php endif; ?>
    <?php
    $designJs = trim(implode("\n", array_filter([
        $designHeaderJs,
        $designMenuJs,
        $designFooterJs,
    ], static fn (string $part): bool => trim($part) !== '')));
    $designJs = str_replace('</script>', '<\/script>', $designJs);
    $floatingCartJsVersion = @filemtime(\App\Support\Paths::public('assets/js/floating-cart.js')) ?: time();
    $qtyStocJsVersion = @filemtime(\App\Support\Paths::public('assets/js/qty-stoc.js')) ?: time();
    ?>
    <?php
    // Banner de cookies (Admin → Setări magazin → Cookies). Apare cât timp
    // vizitatorul nu a ales nimic; alegerea se ține 6 luni într-un cookie.
    $bannerCookiesActiv = (string) ($designSettings['cookie_banner_enabled'] ?? '1') === '1'
        && !\App\Support\CookieConsent::aRaspuns();
    $textCookies = trim((string) ($designSettings['cookie_banner_text'] ?? ''));
    $linkPolitica = trim((string) ($designSettings['cookie_banner_policy_url'] ?? ''));
    ?>
    <?php if ($bannerCookiesActiv && $textCookies !== ''): ?>
        <div id="cookie-banner" role="region" aria-label="Setări cookies"
             style="position:fixed;left:0;right:0;bottom:0;z-index:99998;background:#ffffff;border-top:1px solid #e2e8f0;box-shadow:0 -8px 32px rgba(15,23,42,.16);padding:16px 20px;">
            <div style="max-width:1160px;margin:0 auto;display:flex;flex-wrap:wrap;gap:14px;align-items:center;justify-content:space-between;">
                <p style="margin:0;flex:1 1 320px;min-width:260px;color:#334155;font-size:14px;line-height:1.55;">
                    <?= htmlspecialchars($textCookies, ENT_QUOTES) ?>
                    <?php if ($linkPolitica !== ''): ?>
                        <a href="<?= htmlspecialchars($linkPolitica, ENT_QUOTES) ?>" style="color:<?= $culoarePrincipala ?>;text-decoration:underline;">Politica de confidențialitate</a>
                    <?php endif; ?>
                </p>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button type="button" data-cookie-choice="necessary"
                            style="padding:11px 18px;border:1px solid #cbd5e1;border-radius:10px;background:#ffffff;color:#334155;font-size:14px;font-weight:600;cursor:pointer;">Doar necesare</button>
                    <button type="button" data-cookie-choice="all"
                            style="padding:11px 22px;border:none;border-radius:10px;background:<?= $culoarePrincipala ?>;color:#ffffff;font-size:14px;font-weight:700;cursor:pointer;">Accept toate</button>
                </div>
            </div>
        </div>
        <script>
            (function () {
                'use strict';
                var banner = document.getElementById('cookie-banner');
                if (!banner) { return; }
                var DURATA_ZILE = <?= (int) round(\App\Support\CookieConsent::DURATA / 86400) ?>;
                banner.addEventListener('click', function (e) {
                    var buton = e.target.closest('[data-cookie-choice]');
                    if (!buton) { return; }
                    var alegere = buton.getAttribute('data-cookie-choice');
                    var expira = new Date(Date.now() + DURATA_ZILE * 864e5).toUTCString();
                    var securizat = location.protocol === 'https:' ? ';Secure' : '';
                    document.cookie = <?= json_encode(\App\Support\CookieConsent::nume()) ?> + '=' + alegere +
                        ';path=/;expires=' + expira + ';SameSite=Lax' + securizat;
                    banner.style.display = 'none';
                    // Reîncărcăm: abia acum serverul are voie să trimită
                    // scripturile de analiză (dacă au fost acceptate).
                    if (alegere === 'all') { location.reload(); }
                });
            })();
        </script>
    <?php endif; ?>
    <?php
    // Popup de anunț la intrarea pe site (Admin → Setări magazin → Popup anunț).
    // Stilurile stau inline pentru că CSS-ul site-ului poate fi servit din cache.
    // Pe pagina campaniei (pluginul campanii) nu: ea e deja prima impresie, iar popup-ul ar acoperi butonul „Înapoi”; apare pe
    // pagina următoare.
    $popupActiv = (string) ($designSettings['welcome_popup_enabled'] ?? '0') === '1' && !\App\Support\Campanii::ePaginaCampaniei();
    $popupTitlu = trim((string) ($designSettings['welcome_popup_title'] ?? ''));
    $popupText = trim((string) ($designSettings['welcome_popup_body'] ?? ''));
    $popupVersiune = trim((string) ($designSettings['welcome_popup_version'] ?? '1'));
    ?>
    <?php if ($popupActiv && $popupText !== ''): ?>
        <?php /* display:none inline — „hidden" singur nu ar ajunge, fiindcă un
                 display din atributul style bate regula [hidden] din browser. */ ?>
        <div id="welcome-popup"
             style="position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(15,23,42,.55);">
            <div role="dialog" aria-modal="true" aria-labelledby="welcome-popup-title"
                 style="position:relative;max-width:560px;width:100%;max-height:85vh;overflow-y:auto;background:#ffffff;border-radius:16px;box-shadow:0 24px 64px rgba(15,23,42,.35);padding:28px 26px 24px;">
                <button type="button" data-welcome-popup-close aria-label="Închide"
                        style="position:absolute;top:12px;right:12px;width:34px;height:34px;border:none;border-radius:50%;background:#f1f5f9;color:#334155;font-size:18px;line-height:1;cursor:pointer;">&times;</button>
                <?php if ($popupTitlu !== ''): ?>
                    <h2 id="welcome-popup-title" style="margin:0 0 14px;padding-right:36px;font-size:22px;line-height:1.25;color:#0f172a;"><?= htmlspecialchars($popupTitlu, ENT_QUOTES) ?></h2>
                <?php endif; ?>
                <div style="color:#334155;font-size:15px;line-height:1.6;">
                    <?php foreach (preg_split('/\n{2,}/', str_replace("\r\n", "\n", $popupText)) ?: [] as $paragraf): ?>
                        <p style="margin:0 0 12px;"><?= nl2br(htmlspecialchars(trim($paragraf), ENT_QUOTES)) ?></p>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:18px;text-align:center;">
                    <button type="button" data-welcome-popup-close
                            style="display:inline-block;min-width:180px;padding:12px 22px;border:none;border-radius:12px;background:<?= $culoarePrincipala ?>;color:#ffffff;font-size:15px;font-weight:700;cursor:pointer;">Am înțeles</button>
                </div>
            </div>
        </div>
        <script>
            (function () {
                'use strict';
                var CHEIE = <?= \App\Support\View::sirJs(\App\Support\Brand::cheiePopupInchis()) ?>;
                var versiune = <?= json_encode($popupVersiune) ?>;
                var popup = document.getElementById('welcome-popup');
                if (!popup) { return; }
                try {
                    if (window.localStorage.getItem(CHEIE) === versiune) { return; }
                } catch (e) { /* localStorage indisponibil → arătăm popup-ul */ }
                popup.style.display = 'flex';
                function inchide() {
                    popup.style.display = 'none';
                    try { window.localStorage.setItem(CHEIE, versiune); } catch (e) { /* ignorăm */ }
                }
                // Delegare: prinde click-ul și dacă se dă pe conținutul butonului.
                popup.addEventListener('click', function (e) {
                    if (e.target.closest('[data-welcome-popup-close]')) {
                        e.preventDefault();
                        inchide();
                    }
                });
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape' && popup.style.display !== 'none') {
                        inchide();
                    }
                });
            })();
        </script>
    <?php endif; ?>
<?php if ($areMagazin): ?>
    <?php
    // Avertismentul produsului abia adăugat pe drumul clasic (POST → /cos).
    // Doar pe /cos, care nu intră în cache; în rest fereastra vine din JSON.
    // „endif” lipit de <script>: fără avertisment, pagina rămâne octet cu octet aceeași.
    $avertismenteDeAratat = is_array($avertismenteDeAratat ?? null) ? $avertismenteDeAratat : [];
    if ($avertismenteDeAratat !== []): ?>
        <script type="application/json" id="avertisment-produs-date"><?= json_encode($avertismenteDeAratat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        <script src="<?= htmlspecialchars(\App\Support\AvertismentProduse::urlScript(), ENT_QUOTES) ?>" defer></script>
    <?php endif; ?><script src="/assets/js/qty-stoc.js?v=<?= $qtyStocJsVersion ?><?= $assetVersionQuery ?>" defer></script>
<?php endif; ?>
    <?php if ($floatingCartEnabled): ?>
        <script>
            window.floatingCartConfig = <?= json_encode($floatingCartConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        </script>
        <script src="/assets/js/floating-cart.js?v=<?= $floatingCartJsVersion ?><?= $assetVersionQuery ?>" defer></script>
    <?php endif; ?>
    <?php if ($designJs !== ''): ?>
        <script><?= $designJs ?></script>
    <?php endif; ?>
    <?php if ($chatUrcaCosul): ?>
        <style>
            /* Bulina de chat stă în același colț cu coșul plutitor. Ridicăm
               coșul deasupra ei — widgetul e al lor, coșul e al nostru.
               Variantele de sus ale coșului nu se ating de ea. */
            .floating-cart:not(.floating-cart--top-left):not(.floating-cart--top-right) {
                bottom: 92px;
            }
            @media (max-width: 640px) {
                .floating-cart:not(.floating-cart--top-left):not(.floating-cart--top-right) {
                    bottom: 82px;
                }
            }
        </style>
    <?php endif; ?>

<?php if (!\App\Support\SablonSite::inFisiere($designSettings)): ?>
    <script><?= \App\Support\SablonSite::continut('chat.js') ?></script>
<?php else: ?>
    <script src="<?= htmlspecialchars(\App\Support\SablonSite::url('chat.js'), ENT_QUOTES) ?>"></script>
<?php endif; ?>

    <?php if ($chatActiv): ?>
        <?php
        $chatProperty = \App\Support\ChatLive::curataId((string) ($designSettings['tawk_property_id'] ?? ''));
        $chatWidget = \App\Support\ChatLive::curataId((string) ($designSettings['tawk_widget_id'] ?? 'default')) ?: 'default';
        $chatPozitie = \App\Support\ChatLive::pozitie($designSettings);
        $chatOffsetY = \App\Support\ChatLive::offsetY($designSettings);
        // Clientul logat intră în chat cu numele completat, ca operatorul să
        // nu mai întrebe cine e. Numele trece prin CustomerDisplay: conturile
        // făcute doar cu email poartă în bază „Client Nou", iar operatorul are
        // nevoie de adresă, nu de eticheta aia. Emailul merge semnat, dacă e
        // configurată cheia — altfel oricine ar putea pretinde orice adresă.
        $chatNume = '';
        $chatEmail = '';
        if (is_array($currentCustomer)) {
            $chatEmail = trim((string) ($currentCustomer['email'] ?? ''));
            $chatNume = \App\Support\CustomerDisplay::nume(
                (string) ($currentCustomer['first_name'] ?? ''),
                (string) ($currentCustomer['last_name'] ?? ''),
                $chatEmail,
            );
        }
        $chatHash = \App\Support\ChatLive::semnatura($designSettings, $chatEmail);
        ?>
        <script>
            var Tawk_API = Tawk_API || {}, Tawk_LoadStart = new Date();
            Tawk_API.customStyle = {
                visibility: {
                    desktop: { position: <?= json_encode($chatPozitie) ?>, xOffset: 18, yOffset: <?= $chatOffsetY ?> },
                    mobile: { position: <?= json_encode($chatPozitie) ?>, xOffset: 12, yOffset: <?= $chatOffsetY ?> }
                }
            };
            <?php if ($chatNume !== '' || $chatEmail !== ''): ?>
            Tawk_API.visitor = <?= json_encode(array_filter([
                'name' => $chatNume,
                'email' => $chatEmail,
                'hash' => $chatHash,
            ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            <?php endif; ?>
            (function () {
                var s1 = document.createElement("script"), s0 = document.getElementsByTagName("script")[0];
                s1.async = true;
                s1.src = "https://embed.tawk.to/<?= htmlspecialchars($chatProperty, ENT_QUOTES) ?>/<?= htmlspecialchars($chatWidget, ENT_QUOTES) ?>";
                s1.charset = "UTF-8";
                s1.setAttribute("crossorigin", "*");
                s0.parentNode.insertBefore(s1, s0);
            })();
        </script>
    <?php endif; ?>
    <?php if ($clarityEnabled && $clarityProjectId !== ''): ?>
        <script>
            (function(c,l,a,r,i,t,y){
                c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
                t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
                y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
            })(window, document, "clarity", "script", "<?= htmlspecialchars($clarityProjectId, ENT_QUOTES) ?>");
        </script>
    <?php endif; ?>
    <?php
    // Google Ads conversion: fire once on the checkout success page, then clear the flag.
    $adsConversion = $_SESSION['google_ads_conversion'] ?? null;
    if (is_array($adsConversion)) {
        unset($_SESSION['google_ads_conversion']);
    }
    $adsConversionLabel = preg_replace('/[^A-Za-z0-9_\-]/', '', trim((string) ($designSettings['google_ads_conversion_label'] ?? ''))) ?? '';
    if ($adsEnabled && $adsConversionId !== '' && $adsConversionLabel !== '' && is_array($adsConversion)):
    ?>
        <script>
            if (typeof gtag === 'function') {
                gtag('event', 'conversion', {
                    'send_to': '<?= htmlspecialchars($adsConversionId . '/' . $adsConversionLabel, ENT_QUOTES) ?>',
                    'value': <?= json_encode((float) ($adsConversion['value'] ?? 0)) ?>,
                    'currency': 'RON',
                    'transaction_id': '<?= htmlspecialchars((string) ($adsConversion['order'] ?? ''), ENT_QUOTES) ?>'
                });
            }
        </script>
    <?php endif; ?>
    <?php
    // GA4 ecommerce "purchase" event — fired once per order, captured by GTM.
    $ga4Purchase = $_SESSION['ga4_purchase'] ?? null;
    if (is_array($ga4Purchase)) {
        unset($_SESSION['ga4_purchase']);
    }
    if (is_array($ga4Purchase) && trim((string) ($ga4Purchase['transaction_id'] ?? '')) !== ''):
        $ga4PurchaseJson = json_encode([
            'event' => 'purchase',
            'ecommerce' => [
                'transaction_id' => (string) ($ga4Purchase['transaction_id'] ?? ''),
                'value' => (float) ($ga4Purchase['value'] ?? 0),
                'currency' => (string) ($ga4Purchase['currency'] ?? 'RON'),
                'items' => array_values((array) ($ga4Purchase['items'] ?? [])),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $ga4PurchaseTxnKey = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($ga4Purchase['transaction_id'] ?? '')) ?? '';
    ?>
        <script>
            (function () {
                var key = 'purchase_tracked_<?= $ga4PurchaseTxnKey ?>';
                try {
                    if (window.sessionStorage && sessionStorage.getItem(key)) { return; }
                } catch (e) {}
                window.dataLayer = window.dataLayer || [];
                window.dataLayer.push(<?= $ga4PurchaseJson ?>);
                try { if (window.sessionStorage) { sessionStorage.setItem(key, '1'); } } catch (e) {}
            })();
        </script>
    <?php endif; ?>
<?php if ($areCatalog): ?>
<?php if (!\App\Support\SablonSite::inFisiere($designSettings)): ?>
    <script><?= \App\Support\SablonSite::continut('catalog.js') ?></script>
<?php else: ?>
    <script src="<?= htmlspecialchars(\App\Support\SablonSite::url('catalog.js'), ENT_QUOTES) ?>"></script>
<?php endif; ?>
<?php endif; ?>
<?php if ($areFidelitate): ?>
    <script>
        (() => {
            const isLoggedIn = <?= $headerCustomerLoggedIn ? 'true' : 'false' ?>;
            if (!isLoggedIn) {
                return;
            }
            const points = <?= (int) $headerCustomerPoints ?>;
            const normalizePath = (href) => {
                try {
                    const url = new URL(String(href || ''), window.location.origin);
                    const path = url.pathname.replace(/\/+$/, '');
                    return path === '' ? '/' : path;
                } catch (_error) {
                    return '';
                }
            };
            const isInsideMobileDrawer = (node) => node instanceof HTMLElement
                && node.closest('.bv-mobile-menu-token__drawer') !== null;
            const isIconLikeLink = (link) => {
                if (!(link instanceof HTMLAnchorElement)) {
                    return false;
                }
                if (link.querySelector('svg, i, .icon, [class*="icon"]')) {
                    return true;
                }
                const text = String(link.textContent || '').replace(/\s+/g, '').trim();
                return text.length > 0 && text.length <= 3;
            };
            const attachBadge = (link) => {
                if (!(link instanceof HTMLAnchorElement)) {
                    return;
                }
                if (link.querySelector('[data-header-account-points]')) {
                    return;
                }
                link.classList.add('bv-header-account-points-anchor');
                if (isIconLikeLink(link)) {
                    link.classList.add('bv-header-account-points-anchor--icon');
                }
                if (normalizePath(link.getAttribute('href') || '') === '/login') {
                    link.setAttribute('href', '/contul-meu');
                }
                const badge = document.createElement('span');
                badge.className = 'bv-header-account-points-badge';
                badge.setAttribute('data-header-account-points', '1');
                badge.textContent = String(points);
                badge.title = `${points} puncte fidelitate`;
                link.appendChild(badge);
            };

            const allLinks = Array.from(document.querySelectorAll('header a[href], .header a[href]'))
                .filter((node) => node instanceof HTMLAnchorElement)
                .filter((node) => !isInsideMobileDrawer(node));
            const candidates = allLinks.filter((link) => {
                const path = normalizePath(link.getAttribute('href') || '');
                return path === '/contul-meu' || path === '/login';
            });
            if (candidates.length === 0) {
                return;
            }

            const iconLike = candidates.filter(isIconLikeLink);
            const targets = iconLike.length > 0 ? iconLike : [candidates[0]];
            targets.forEach(attachBadge);
        })();
    </script>
<?php endif; ?>
    <?php if ($mobileMenuTokenAssetsEnabled): ?>
<?php if (!\App\Support\SablonSite::inFisiere($designSettings)): ?>
        <script><?= \App\Support\SablonSite::continut('meniu-mobil.js') ?></script>
<?php else: ?>
        <script src="<?= htmlspecialchars(\App\Support\SablonSite::url('meniu-mobil.js'), ENT_QUOTES) ?>"></script>
<?php endif; ?>
    <?php endif; ?>
<?php if (!\App\Support\SablonSite::inFisiere($designSettings)): ?>
    <script><?= \App\Support\SablonSite::continut('bbd.js') ?></script>
<?php else: ?>
    <script src="<?= htmlspecialchars(\App\Support\SablonSite::url('bbd.js'), ENT_QUOTES) ?>"></script>
<?php endif; ?>
<?php if ($areMagazin): ?>
    <script>
        (() => {
            const initialCount = <?= (int) $headerCartCount ?>;
            const normalizePath = (href) => {
                try {
                    const url = new URL(String(href || ''), window.location.origin);
                    const path = url.pathname.replace(/\/+$/, '');
                    return path === '' ? '/' : path;
                } catch (_error) {
                    return '';
                }
            };
            const isCartLink = (node) => (
                node instanceof HTMLAnchorElement
                && normalizePath(node.getAttribute('href') || '') === '/cos'
            );
            const cartTargets = new Set();
            const cartTextLinks = new Set();

            const registerTargets = () => {
                document.querySelectorAll('[data-cart-count], [data-floating-cart-count]').forEach((node) => {
                    if (node instanceof HTMLElement) {
                        cartTargets.add(node);
                    }
                });
                document.querySelectorAll('a[href]').forEach((node) => {
                    if (!isCartLink(node)) {
                        return;
                    }
                    const badge = node.querySelector('[data-cart-count], em, sup, .count, .badge, [class*="count"], [class*="badge"]');
                    if (badge instanceof HTMLElement) {
                        badge.setAttribute('data-cart-count', '1');
                        cartTargets.add(badge);
                        return;
                    }
                    if (node.children.length === 0) {
                        cartTextLinks.add(node);
                    }
                });
            };

            const applyCount = (count) => {
                const safeCount = Math.max(0, Number.parseInt(String(count), 10) || 0);
                registerTargets();
                cartTargets.forEach((node) => {
                    node.textContent = String(safeCount);
                });
                cartTextLinks.forEach((node) => {
                    if (!(node instanceof HTMLAnchorElement)) {
                        return;
                    }
                    const existingTemplate = node.getAttribute('data-cart-count-template');
                    if (!existingTemplate) {
                        const source = String(node.textContent || '').trim();
                        const template = /\(\s*\d+\s*\)/.test(source)
                            ? source.replace(/\(\s*\d+\s*\)/, '({{count}})')
                            : (source !== '' ? source + ' ({{count}})' : 'Coș ({{count}})');
                        node.setAttribute('data-cart-count-template', template);
                    }
                    const template = node.getAttribute('data-cart-count-template') || 'Coș ({{count}})';
                    node.textContent = template.replace('{{count}}', String(safeCount));
                });
            };

            const refreshFromApi = () => {
                fetch('/api/cart/summary', {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                })
                    .then((response) => response.ok ? response.json() : Promise.reject(new Error('Cart summary failed')))
                    .then((payload) => {
                        if (payload && payload.ok === true) {
                            applyCount(payload.items_count ?? initialCount);
                        }
                    })
                    .catch(() => {});
            };

            const init = () => {
                applyCount(initialCount);
                refreshFromApi();
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }

            window.addEventListener('floating-cart:added', (event) => {
                const detailCount = event && event.detail
                    ? (
                        event.detail.items_count
                        ?? event.detail.count
                    )
                    : null;
                if (detailCount === null || typeof detailCount === 'undefined') {
                    refreshFromApi();
                    return;
                }
                applyCount(detailCount);
            });
            window.addEventListener('cart:count-updated', (event) => {
                const detailCount = event && event.detail
                    ? (
                        event.detail.items_count
                        ?? event.detail.count
                    )
                    : null;
                if (detailCount === null || typeof detailCount === 'undefined') {
                    return;
                }
                applyCount(detailCount);
            });

            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') {
                    refreshFromApi();
                }
            });
        })();
    </script>
<?php endif; ?>
<?php /* Tema site-ului: JavaScript-ul Bootstrap, scripturile temei (tema.json → js_final) și butonul „Înapoi sus”. */ ?>
<?php if (\App\Support\Tema::bootstrap()): ?>
<script src="<?= htmlspecialchars(\App\Support\Tema::url(\App\Support\Tema::BOOTSTRAP_JS), ENT_QUOTES) ?>" defer></script>
<?php endif; ?>
<?php foreach (\App\Support\Tema::jsFinal() as $temaJs): ?>
<script src="<?= htmlspecialchars(\App\Support\Tema::url($temaJs), ENT_QUOTES) ?>" defer></script>
<?php endforeach; ?>
<?php if (\App\Support\Tema::butonSus()): ?>
<?php
/*
 * Butonul „Înapoi sus” (GR b4159bd). Stă în șablon, nu în subsolul din Design: subsolul se poate rescrie din admin, iar
 * butonul ar dispărea odată cu el. E ancoră, nu buton: dus sus, focusul trebuie să ajungă la începutul paginii (ținta
 * #inceput-pagina, imediat după <body>), altfel următorul Tab ar continua din subsol. Apare abia după ce pagina a fost
 * derulată o înălțime de ecran; înfățișarea (.sus) o dă CSS-ul temei.
 */
?>
<a class="sus" href="#inceput-pagina" aria-label="Înapoi sus" hidden>
  <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
       stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <path d="M12 19V5M5 12l7-7 7 7"/>
  </svg>
</a>
<?php if (!\App\Support\SablonSite::inFisiere($designSettings)): ?>
<script><?= \App\Support\SablonSite::continut('sus.js') ?></script>
<?php else: ?>
<script src="<?= htmlspecialchars(\App\Support\SablonSite::url('sus.js'), ENT_QUOTES) ?>"></script>
<?php endif; ?>
<?php endif; ?>
<?= \App\Support\Hooks::html('layout.final_body') ?>
</body>
</html>
<?php
if ($filtruLinkuriPluginuri) {
    echo \App\Support\Plugins::filtreazaLinkuri((string) ob_get_clean());
}
?>
