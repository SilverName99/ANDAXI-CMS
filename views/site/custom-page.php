<?php
$pageCss = (string) ($page['css_content'] ?? '');
$pageJs = (string) ($page['js_content'] ?? '');
$pageJs = str_replace('</script>', '<\/script>', $pageJs);
$registrationFieldVisibility = is_array($registrationFieldVisibility ?? null) ? $registrationFieldVisibility : [];
$socialAuthConfig = is_array($socialAuthConfig ?? null) ? $socialAuthConfig : [];
$registerAntiBot = is_array($registerAntiBot ?? null) ? $registerAntiBot : [];
?>
<?php
// Tokenurile pluginurilor pe care site-ul nu le are se scot din pagină.
$pageHtml = \App\Support\Plugins::curataTokenuri((string) ($page['html_content'] ?? ''));
// Pozele mari refăcute (A8, de exemplu cea din capul primei pagini) se afișează
// din fișierul nou, mai mic; doar src-ul lor, în textul paginii din baza de date.
$pageHtml = \App\Support\PozeOptimizate::inHtml($pageHtml);
$mannequinSectionHtml = (string) ($mannequinSectionHtml ?? '');
$shopCatalogHtml = (string) ($shopCatalogHtml ?? '');
$blogPostsHtml = (string) ($blogPostsHtml ?? '');
$cartFormHtml = (string) ($cartFormHtml ?? '');
$checkoutFormHtml = (string) ($checkoutFormHtml ?? '');
$accountSectionHtml = (string) ($accountSectionHtml ?? '');
// WP-25 (VT-C5): {{auth_form}}, doar pe paginile „login” și „register” (altfel gol).
$authFormHtml = (string) ($authFormHtml ?? '');
$productReviewFormHtml = (string) ($productReviewFormHtml ?? '');
$gdprAgreementsFormHtml = (string) ($gdprAgreementsFormHtml ?? '');
$checkoutSuccessOrderInfoHtml = (string) ($checkoutSuccessOrderInfoHtml ?? '');
$authGoogleButtonHtml = '';
if (!empty($socialAuthConfig['google_enabled'])) {
    $googleAuthUrl = htmlspecialchars((string) ($socialAuthConfig['google_auth_url'] ?? '/auth/google'), ENT_QUOTES);
    $googleIconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="22" height="22" aria-hidden="true" focusable="false">'
        . '<path fill="#FFC107" d="M43.611 20.083H42V20H24v8h11.303C33.659 32.657 29.233 36 24 36c-6.627 0-12-5.373-12-12s5.373-12 12-12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 12.955 4 4 12.955 4 24s8.955 20 20 20 20-8.955 20-20c0-1.341-.138-2.65-.389-3.917z"/>'
        . '<path fill="#FF3D00" d="M6.306 14.691l6.571 4.819C14.655 16.108 18.961 13 24 13c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4c-7.682 0-14.318 4.337-17.694 10.691z"/>'
        . '<path fill="#4CAF50" d="M24 44c5.166 0 9.86-1.977 13.409-5.192l-6.19-5.238C29.143 35.091 26.715 36 24 36c-5.211 0-9.617-3.338-11.283-7.946l-6.52 5.025C9.539 39.556 16.745 44 24 44z"/>'
        . '<path fill="#1976D2" d="M43.611 20.083H42V20H24v8h11.303a12.06 12.06 0 0 1-4.084 5.571l.003-.002 6.19 5.238C36.971 39.205 44 34 44 24c0-1.341-.138-2.65-.389-3.917z"/>'
        . '</svg>';
    $authGoogleButtonHtml = '<div class="bv-google-auth-inline" style="margin-top:14px;">'
        . '<a href="' . $googleAuthUrl . '"'
        . ' style="display:flex;align-items:center;justify-content:center;gap:12px;width:100%;padding:12px 16px;border:1px solid #c8ced8;border-radius:20px;background:#fff;color:#1f2937;font-size:16px;font-weight:600;line-height:1.2;text-decoration:none;box-sizing:border-box;">'
        . $googleIconSvg
        . '<span>Continuă cu Google</span>'
        . '</a>'
        . '</div>';
}
$pageHtml = str_replace('{{mannequin_section}}', $mannequinSectionHtml, $pageHtml);
$pageHtml = str_replace('{{shop_catalog}}', $shopCatalogHtml, $pageHtml);
// Titlul categoriei curente din magazin ({{shop_category}} este gol cand
// nu exista filtru, ca elementul care il contine sa se poata ascunde din CSS).
$shopCategoryLabel = trim((string) ($shopCategoryLabel ?? ''));
$shopPageTitle = trim((string) ($shopPageTitle ?? 'Magazin')) ?: 'Magazin';
$pageHtml = str_replace(
    ['{{shop_category}}', '{{shop_title}}'],
    [
        htmlspecialchars($shopCategoryLabel, ENT_QUOTES),
        htmlspecialchars($shopCategoryLabel !== '' ? $shopCategoryLabel : $shopPageTitle, ENT_QUOTES),
    ],
    $pageHtml
);
$pageHtml = str_replace('{{blog_posts}}', $blogPostsHtml, $pageHtml);
$pageHtml = str_replace('{{cart_form}}', $cartFormHtml, $pageHtml);
$pageHtml = str_replace('{{checkout_form}}', $checkoutFormHtml, $pageHtml);
$pageHtml = str_replace('{{account_section}}', $accountSectionHtml, $pageHtml);
$pageHtml = str_replace('{{auth_google_button}}', $authGoogleButtonHtml, $pageHtml);
$pageHtml = str_replace('{{auth_form}}', $authFormHtml, $pageHtml);
$pageHtml = str_replace('{{product_review_form}}', $productReviewFormHtml, $pageHtml);
$pageHtml = str_replace('{{gdpr_agreements_form}}', $gdprAgreementsFormHtml, $pageHtml);
$pageHtml = str_replace('{{checkout_success_order_info}}', $checkoutSuccessOrderInfoHtml, $pageHtml);
$nextEventConfig = require __DIR__ . '/../../config/app.php';
$nextEventDb = \App\Support\Database::connection((array) ($nextEventConfig['db'] ?? []));
foreach (\App\Support\NextEvent::tokens($nextEventDb) as $nextEventNeedle => $nextEventValue) {
    $pageHtml = str_replace($nextEventNeedle, $nextEventValue, $pageHtml);
}
$hasCategorySlider = false;
$pageHtml = (string) preg_replace_callback(
    '/\{\{\s*category_posts\s*:\s*([a-z0-9\-]+)\s*\}\}/i',
    static function (array $m) use ($nextEventDb, &$hasCategorySlider): string {
        $hasCategorySlider = true;
        return \App\Support\CategoryPosts::renderSlider($nextEventDb, (string) ($m[1] ?? ''));
    },
    $pageHtml
);
$hasCategoryGrid = false;
$pageHtml = (string) preg_replace_callback(
    '/\{\{\s*category_grid\s*:\s*([a-z0-9\-]+)\s*\}\}/i',
    static function (array $m) use ($nextEventDb, &$hasCategoryGrid): string {
        $hasCategoryGrid = true;
        return \App\Support\CategoryPosts::renderGrid($nextEventDb, (string) ($m[1] ?? ''));
    },
    $pageHtml
);
// WP-24 (VT-C4, din vertij 265db83, 67319f7, ff3c46e): rândurile de evenimente ale unei categorii
// ({{category_event_rows:slug}}, câte 3 pe pagină) și grila paginată din mai multe categorii
// ({{category_grid_paged:slug1,slug2}}, câte 6), cu paginarea .vter de mai jos.
$hasEventRows = false;
$pageHtml = (string) preg_replace_callback(
    '/\{\{\s*category_event_rows\s*:\s*([a-z0-9\-]+)\s*\}\}/i',
    static function (array $m) use ($nextEventDb, &$hasEventRows): string {
        $hasEventRows = true;
        return \App\Support\CategoryPosts::renderEventRows($nextEventDb, (string) ($m[1] ?? ''));
    },
    $pageHtml
);
$pageHtml = (string) preg_replace_callback(
    '/\{\{\s*category_grid_paged\s*:\s*([a-z0-9,\-]+)\s*\}\}/i',
    static function (array $m) use ($nextEventDb, &$hasCategoryGrid, &$hasEventRows): string {
        $hasCategoryGrid = true; // stilurile de card .vtcg
        $hasEventRows = true;    // paginarea (CSS + JS) .vter
        return \App\Support\CategoryPosts::renderCategoryGridPaged($nextEventDb, (string) ($m[1] ?? ''));
    },
    $pageHtml
);
// Secțiuni dinamice pentru pagina de acasă / pagini de prezentare.
// Numărul de elemente este opțional: {{featured_products}} sau {{featured_products:8}}.
$pageHtml = (string) preg_replace_callback(
    '/\{\{\s*featured_products\s*(?::\s*(\d+)\s*)?\}\}/i',
    static fn(array $m): string => \App\Support\HomeSections::renderFeaturedProducts(
        $nextEventDb,
        (int) ($m[1] ?? 4) ?: 4
    ),
    $pageHtml
);
$pageHtml = (string) preg_replace_callback(
    '/\{\{\s*latest_posts\s*(?::\s*(\d+)\s*)?\}\}/i',
    static fn(array $m): string => \App\Support\HomeSections::renderLatestPosts(
        $nextEventDb,
        (int) ($m[1] ?? 3) ?: 3
    ),
    $pageHtml
);
$pageHtml = (string) preg_replace_callback(
    '/\{\{\s*product_categories\s*(?::\s*(\d+)\s*)?\}\}/i',
    static fn(array $m): string => \App\Support\HomeSections::renderCategoryTiles(
        $nextEventDb,
        (int) ($m[1] ?? 6) ?: 6
    ),
    $pageHtml
);
$hasEventsPage = false;
$pageHtml = (string) preg_replace_callback(
    '/\{\{\s*category_events\s*:\s*([a-z0-9\-]+)\s*\}\}/i',
    static function (array $m) use ($nextEventDb, &$hasCategoryGrid, &$hasEventsPage): string {
        $hasCategoryGrid = true; // reutilizează stilurile de card .vtcg
        $hasEventsPage = true;   // + stilurile de hero/secțiuni .vte
        return \App\Support\CategoryPosts::renderEventsPage($nextEventDb, (string) ($m[1] ?? ''));
    },
    $pageHtml
);
// Linkurile de categorie scrise în pagină (/magazin?category=…) duc la
// /categorie/{slug}; textul paginii din baza de date rămâne neatins. Doar cu
// catalogul pe site (fără el, filtrul pluginurilor scoate oricum linkurile).
if (\App\Support\Plugins::vizibil('catalog')) {
    $pageHtml = \App\Support\LinkuriCategorii::rescrieInPagina($pageHtml, $nextEventDb instanceof \PDO ? $nextEventDb : null);
}
// Pagina de conținut fără niciun <h1> primește titlul ei ca H1 (A11; controllerul
// trimite titlul doar pentru paginile de conținut, vezi App\Support\SeoIndexare).
$pageHtmlCuH1 = \App\Support\SeoIndexare::adaugaH1($pageHtml, (string) ($h1Implicit ?? ''));
$h1Adaugat = $pageHtmlCuH1 !== $pageHtml;
$pageHtml = $pageHtmlCuH1;
?>
<?php if (trim($pageCss) !== ''): ?>
    <style>
        <?= $pageCss ?>
    </style>
<?php endif; ?>
<?php if ($h1Adaugat): ?>
    <style>
        /* Titlul adăugat de șablon: fără specificitate (:where), ca stilurile paginii
           (de exemplu șablonul paginilor de text) să aibă întâietate. */
        :where(.custom-page-content) > :where(.custom-page-h1){max-width:1180px;margin:24px auto 14px;font-family:Georgia,"Times New Roman",serif;font-size:31px;font-weight:500;line-height:1.3;color:#064b4f;}
        @media (max-width:768px){:where(.custom-page-content) > :where(.custom-page-h1){font-size:25px;}}
    </style>
<?php endif; ?>
<?php
$narrowSlugs = [
    'returnare-si-garantie',
    'termeni-si-conditii',
    'politica-cookie',
    'politica-de-confidentialitate',
];
$pageSlug = trim((string) ($page['slug'] ?? ''), '/');
$customPageClass = 'custom-page-content' . (in_array($pageSlug, $narrowSlugs, true) ? ' custom-page--narrow' : '');
?>
<section class="<?= htmlspecialchars($customPageClass, ENT_QUOTES) ?>">
    <?= $pageHtml ?>
</section>
<?php if (trim($pageJs) !== ''): ?>
    <script>
        <?= $pageJs ?>
    </script>
<?php endif; ?>
<script>
    (() => {
        const initReviewRatings = (scope) => {
            const roots = Array.from(scope.querySelectorAll('[data-review-rating]'));
            roots.forEach((root) => {
                if (!(root instanceof HTMLElement) || root.dataset.reviewRatingInitialized === '1') {
                    return;
                }
                const input = root.querySelector('[data-review-rating-input]');
                const stars = Array.from(root.querySelectorAll('[data-rating-value]'));
                if (!(input instanceof HTMLInputElement) || stars.length === 0) {
                    return;
                }
                const apply = (nextValue) => {
                    const value = Number.isFinite(nextValue) ? Math.max(1, Math.min(5, Math.round(nextValue))) : 5;
                    input.value = String(value);
                    stars.forEach((star, index) => {
                        if (!(star instanceof HTMLElement)) {
                            return;
                        }
                        const isActive = index < value;
                        star.classList.toggle('is-active', isActive);
                        star.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                    });
                };
                root.addEventListener('click', (event) => {
                    const target = event.target;
                    if (!(target instanceof Element)) {
                        return;
                    }
                    const star = target.closest('[data-rating-value]');
                    if (!(star instanceof HTMLElement) || !root.contains(star)) {
                        return;
                    }
                    event.preventDefault();
                    apply(Number(star.getAttribute('data-rating-value') || '5'));
                });
                root.addEventListener('keydown', (event) => {
                    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
                        return;
                    }
                    event.preventDefault();
                    const current = Number(input.value || '5');
                    const delta = event.key === 'ArrowRight' ? 1 : -1;
                    apply(current + delta);
                });
                apply(Number(input.value || '5'));
                root.dataset.reviewRatingInitialized = '1';
            });
        };
        initReviewRatings(document);
    })();
</script>
<?php if ($registrationFieldVisibility !== []): ?>
    <script>
        (() => {
            const visibility = <?= json_encode($registrationFieldVisibility, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const hideFieldByName = (name) => {
                const controls = document.querySelectorAll(`[name="${name}"]`);
                controls.forEach((control) => {
                    if (!(control instanceof HTMLElement)) return;
                    control.removeAttribute('required');
                    if (control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement) {
                        control.disabled = true;
                    }
                    const wrapper = control.closest('.field, .form-field, .form-group, .input-group, .bv-field');
                    if (wrapper instanceof HTMLElement) {
                        wrapper.style.display = 'none';
                        return;
                    }
                    const parent = control.parentElement;
                    if (parent instanceof HTMLElement) {
                        parent.style.display = 'none';
                    } else {
                        control.style.display = 'none';
                    }
                });
            };

            Object.entries(visibility).forEach(([fieldName, enabled]) => {
                if (enabled) return;
                hideFieldByName(fieldName);
            });
        })();
    </script>
<?php endif; ?>
<?php if ($registerAntiBot !== []): ?>
    <script>
        (() => {
            const token = String(<?= json_encode((string) ($registerAntiBot['token'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || '');
            const renderedAt = Number(<?= json_encode((int) ($registerAntiBot['rendered_at'] ?? 0), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || 0);
            if (!token || renderedAt <= 0) {
                return;
            }
            const registerForms = Array.from(document.querySelectorAll('form[action="/register"], form[action="/contul-meu/inregistrare"]'));
            registerForms.forEach((form) => {
                if (!(form instanceof HTMLFormElement)) {
                    return;
                }
                const ensureHidden = (name, value) => {
                    let input = form.querySelector(`input[name="${name}"]`);
                    if (!(input instanceof HTMLInputElement)) {
                        input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = name;
                        form.appendChild(input);
                    }
                    input.value = value;
                };
                ensureHidden('register_form_token', token);
                ensureHidden('register_form_rendered_at', String(renderedAt));
                if (!(form.querySelector('input[name="register_hp_website"]') instanceof HTMLInputElement)) {
                    // display:none, fără <label> — un câmp doar poziționat în
                    // afara ecranului rămâne „vizibil" pentru completarea
                    // automată din browser și blochează utilizatori reali.
                    const trapWrap = document.createElement('div');
                    trapWrap.style.display = 'none';
                    trapWrap.setAttribute('aria-hidden', 'true');
                    const trapInput = document.createElement('input');
                    trapInput.type = 'text';
                    trapInput.name = 'register_hp_website';
                    trapInput.id = 'register-hp-website-dynamic';
                    trapInput.autocomplete = 'off';
                    trapInput.tabIndex = -1;
                    trapInput.setAttribute('data-lpignore', 'true');
                    trapInput.setAttribute('data-1p-ignore', '');
                    trapWrap.appendChild(trapInput);
                    form.appendChild(trapWrap);
                }
            });
        })();
    </script>
<?php endif; ?>
<?php if (!empty($hasCategorySlider)): ?>
    <style>
        .vtcs{position:relative;}
        .vtcs__viewport{display:flex;gap:18px;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;-webkit-overflow-scrolling:touch;padding:6px 2px 16px;scrollbar-width:none;overscroll-behavior-x:contain;}
        .vtcs__viewport::-webkit-scrollbar{display:none;}
        .vtcs__card{flex:0 0 calc((100% - 36px)/3);scroll-snap-align:start;scroll-snap-stop:always;background:#fff;border:1px solid #f0d7de;border-radius:18px;overflow:hidden;display:flex;flex-direction:column;}
        .vtcs__media{display:block;aspect-ratio:16/10;background:#fdeef2;overflow:hidden;border-radius:18px 18px 0 0;}
        .vtcs__media img{width:100%;height:100%;object-fit:cover;display:block;border-radius:18px 18px 0 0;}
        /* WP-24 (VT-C4, din vertij b0aa083): cardul de eveniment, cu data în colț și locația */
        .vtcs__card--event .vtcs__media{position:relative;}
        .vtcs__date{position:absolute;top:12px;left:12px;display:flex;flex-direction:column;align-items:center;justify-content:center;min-width:52px;padding:8px 10px;background:linear-gradient(160deg,#ea4968,#c13c93);color:#fff;border-radius:14px;box-shadow:0 8px 18px rgba(193,60,147,.35);line-height:1;}
        .vtcs__date-day{font-size:22px;font-weight:800;font-family:"Playfair Display","DM Sans",serif;}
        .vtcs__date-mon{font-size:11px;font-weight:700;letter-spacing:.1em;margin-top:3px;}
        .vtcs__metarow{display:flex;flex-wrap:wrap;gap:12px;margin-top:2px;}
        .vtcs__meta{display:inline-flex;align-items:center;gap:6px;color:#6b7280;font-size:13px;font-weight:600;}
        .vtcs__meta svg{color:#ea4968;flex:0 0 auto;}
        .vtcs__card--event .vtcs__title{font-size:19px;}
        .vtcs .vtcs__card{border-radius:18px !important;}
        .vtcs .vtcs__media,.vtcs .vtcs__media img{border-radius:18px 18px 0 0 !important;}
        .vtcs__body{padding:16px 18px 18px;display:flex;flex-direction:column;gap:8px;flex:1;}
        .vtcs__cat{align-self:flex-start;background:#fdeef2;color:#ea4968;font-weight:700;font-size:11px;letter-spacing:.08em;text-transform:uppercase;padding:4px 10px;border-radius:999px;}
        .vtcs__title{margin:0;font-size:18px;font-weight:800;color:#15202b;font-family:"Playfair Display","DM Sans",serif;line-height:1.25;}
        .vtcs__title a{color:inherit;text-decoration:none;}
        .vtcs__title a:hover{color:#ea4968;}
        .vtcs__excerpt{margin:0;color:#6b7280;font-size:14px;line-height:1.6;flex:1;}
        .vtcs__link{color:#ea4968;font-weight:700;text-decoration:none;font-size:14px;margin-top:4px;}
        .vtcs__nav{position:absolute;top:34%;transform:translateY(-50%);width:42px;height:42px;border-radius:50%;border:1px solid #f0d7de;background:#fff;color:#15202b;font-size:22px;line-height:1;cursor:pointer;box-shadow:0 8px 20px rgba(21,32,43,.12);z-index:2;display:flex;align-items:center;justify-content:center;}
        .vtcs__nav:hover{background:#ea4968;color:#fff;border-color:#ea4968;}
        .vtcs__nav:disabled{opacity:.35;cursor:default;}
        .vtcs__nav--prev{left:-8px;}
        .vtcs__nav--next{right:-8px;}
        .vtcs__dots{display:flex;gap:8px;justify-content:center;margin-top:6px;}
        .vtcs__dot{width:9px;height:9px;border-radius:50%;border:0;background:#f0d7de;cursor:pointer;padding:0;transition:width .15s ease,background .15s ease;}
        .vtcs__dot.is-active{background:#ea4968;width:22px;border-radius:999px;}
        .vtcs__empty{color:#6b7280;font-style:italic;padding:10px 0;}
        @media(max-width:900px){.vtcs__card{flex-basis:calc((100% - 18px)/2);}.vtcs__nav{display:none;}}
        @media(max-width:600px){.vtcs__card{flex-basis:86%;}}
    </style>
<?php endif; ?>
<?php if (!empty($hasCategoryGrid)): ?>
    <style>
        .vtcg{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:24px;}
        .vtcg__card{display:flex;flex-direction:column;background:#fff;border:1px solid #f0d7de;border-radius:18px !important;overflow:hidden;box-shadow:0 14px 34px rgba(21,32,43,.06);transition:transform .2s ease,box-shadow .2s ease;}
        .vtcg__card:hover{transform:translateY(-5px);box-shadow:0 22px 48px rgba(234,73,104,.14);}
        .vtcg__media{position:relative;display:block;aspect-ratio:16/10;background:#fdeef2;overflow:hidden;border-radius:18px 18px 0 0 !important;}
        .vtcg__media img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .35s ease;border-radius:18px 18px 0 0 !important;}
        .vtcg__card:hover .vtcg__media img{transform:scale(1.05);}
        .vtcg__badge{position:absolute;left:12px;bottom:12px;display:inline-flex;align-items:center;gap:6px;background:rgba(234,73,104,.96);color:#fff;font-weight:700;font-size:12px;padding:6px 12px;border-radius:999px;box-shadow:0 6px 16px rgba(234,73,104,.35);}
        .vtcg__badge svg{flex:0 0 auto;}
        .vtcg__past-tag{position:absolute;right:12px;top:12px;background:rgba(21,32,43,.78);color:#fff;font-weight:700;font-size:11px;letter-spacing:.06em;text-transform:uppercase;padding:5px 10px;border-radius:999px;}
        .vtcg__card.is-past .vtcg__media img{filter:grayscale(.55);opacity:.92;}
        .vtcg__body{padding:18px 20px 20px;display:flex;flex-direction:column;gap:10px;flex:1;}
        .vtcg__title{margin:0;font-family:"Playfair Display","DM Sans",serif;font-size:19px;font-weight:800;color:#15202b;line-height:1.28;}
        .vtcg__title a{color:inherit;text-decoration:none;}
        .vtcg__title a:hover{color:#ea4968;}
        .vtcg__excerpt{margin:0;color:#6b7280;font-size:14.5px;line-height:1.6;flex:1;}
        .vtcg__link{align-self:flex-start;margin-top:4px;color:#ea4968 !important;font-weight:800;text-decoration:none;font-size:14px;}
        .vtcg__link:hover{text-decoration:underline;}
        .vtcg__empty{color:#6b7280;font-style:italic;padding:10px 0;}
        @media(max-width:600px){.vtcg{grid-template-columns:1fr;}}
    </style>
<?php endif; ?>
<?php if (!empty($hasEventsPage)): ?>
    <style>
        .vte__section{margin:42px 0 0;}
        .vte__h2{font-family:"Playfair Display",Georgia,serif;color:#15202b;font-weight:800;font-size:clamp(22px,3.4vw,30px);margin:0 0 22px;padding-bottom:10px;border-bottom:2px solid #fdeef2;}
        .vte__hero{display:grid;grid-template-columns:1.05fr .95fr;background:#fff;border:1px solid #f0d7de;border-radius:24px !important;overflow:hidden;box-shadow:0 22px 54px rgba(21,32,43,.08);}
        .vte__hero-media{display:block;position:relative;min-height:280px;background:#fdeef2;overflow:hidden;}
        .vte__hero-media img{width:100%;height:100%;object-fit:cover;display:block;}
        .vte__hero-body{padding:36px 40px;display:flex;flex-direction:column;justify-content:center;gap:14px;}
        .vte__hero-flag{align-self:flex-start;background:#ea4968;color:#fff;font-weight:700;font-size:12px;letter-spacing:.1em;text-transform:uppercase;padding:6px 14px;border-radius:999px;}
        .vte__hero-title{margin:0;font-family:"Playfair Display",Georgia,serif;font-size:clamp(24px,3.4vw,34px);font-weight:800;color:#15202b;line-height:1.15;}
        .vte__hero-title a{color:inherit;text-decoration:none;}
        .vte__hero-title a:hover{color:#ea4968;}
        .vte__hero-metarow{display:flex;flex-wrap:wrap;gap:10px 18px;}
        .vte__hero-meta{display:inline-flex;align-items:center;gap:7px;color:#ea4968;font-weight:700;font-size:14px;}
        .vte__hero-meta svg{flex:0 0 auto;}
        .vte__hero-excerpt{margin:0;color:#6b7280;font-size:16px;line-height:1.7;}
        .vte__hero-btn{align-self:flex-start;margin-top:6px;background:linear-gradient(135deg,#ea4968,#f4677f);color:#fff !important;font-weight:800;font-size:15px;text-decoration:none;padding:13px 26px;border-radius:14px;box-shadow:0 14px 30px rgba(234,73,104,.30);transition:transform .15s ease;}
        .vte__hero-btn:hover{transform:translateY(-2px);}
        @media(max-width:820px){
            .vte__hero{grid-template-columns:1fr;}
            .vte__hero-media{min-height:0;aspect-ratio:16/9;}
            .vte__hero-body{padding:26px 24px;}
        }
    </style>
<?php endif; ?>
<?php if (!empty($hasEventRows)): ?>
    <style>
        .vter{display:flex;flex-direction:column;gap:12px;}
        .vter__row{display:flex;align-items:center;gap:14px;background:#fff;border:1px solid #f0dbe3;border-radius:12px !important;padding:12px 16px;}
        .vter__media{flex:0 0 auto;width:64px;height:64px;border-radius:10px !important;background:#fdeef2;background-size:cover;background-position:center;overflow:hidden;display:block;}
        .vter__media--ico{display:flex;align-items:center;justify-content:center;color:#ea4968;}
        .vter__main{flex:1;min-width:0;}
        .vter__title{margin:0 0 3px;font-family:"Playfair Display","DM Sans",serif;font-size:16px;font-weight:800;color:#15202b;line-height:1.25;}
        .vter__title a{color:inherit;text-decoration:none;}
        .vter__title a:hover{color:#ea4968;}
        .vter__desc{margin:0;font-size:13.5px;line-height:1.55;color:#6b7280;}
        .vter__meta{display:flex;flex-direction:column;gap:2px;font-size:12.5px;color:#7a7a7a;text-align:right;white-space:nowrap;}
        .vter__date{display:inline-flex;align-items:center;gap:6px;justify-content:flex-end;color:#7a7a7a;}
        .vter__date svg{flex:0 0 auto;}
        .vter__link{flex:0 0 auto;color:#ea4968 !important;font-weight:800;font-size:14px;text-decoration:none;white-space:nowrap;}
        .vter__link:hover{text-decoration:underline;}
        .vter__row.is-past{opacity:.75;}
        .vter__empty{color:#6b7280;font-style:italic;padding:10px 0;}
        .vter__list{display:flex;flex-direction:column;gap:12px;}
        /* varianta grilă paginată (category_grid_paged): 3 pe rând; colțurile rotunjite și peste o temă care le taie
           (vertij c1abee4: .site-shell article{border-radius:0}), doar în grila paginată */
        .custom-page-content .vter .vtcg__card{border-radius:18px !important;}
        .custom-page-content .vter .vtcg__media,.custom-page-content .vter .vtcg__media img{border-radius:18px 18px 0 0 !important;}
        .vter .vtcg{grid-template-columns:repeat(3,1fr);}
        @media(max-width:900px){.vter .vtcg{grid-template-columns:repeat(2,1fr);}}
        @media(max-width:600px){.vter .vtcg{grid-template-columns:1fr;}}
        .vter__pager{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;margin-top:14px;}
        .vter__pager:empty{display:none;}
        .vter__pg{min-width:32px;height:32px;padding:0 10px;border:1px solid #f0dbe3;background:#fff;color:#15202b;border-radius:8px !important;font-weight:700;font-size:13px;cursor:pointer;transition:background .15s ease,border-color .15s ease,color .15s ease;}
        .vter__pg:hover{border-color:#ea4968;color:#ea4968;}
        .vter__pg.is-active{background:#ea4968;border-color:#ea4968;color:#fff;}
        .vter__pg[disabled]{opacity:.4;cursor:default;}
        @media(max-width:600px){
            .vter__row{flex-wrap:wrap;}
            .vter__meta{text-align:left;flex-direction:row;gap:12px;width:100%;}
        }
    </style>
    <script>
    (() => {
        if (window.__vterInit) { return; }
        window.__vterInit = true;
        const build = (box) => {
            const list = box.querySelector('[data-vter-list]');
            const pager = box.querySelector('[data-vter-pager]');
            if (!list || !pager) { return; }
            const rows = Array.from(list.children).filter((el) => el.nodeType === 1);
            const size = Math.max(1, parseInt(box.dataset.vterPageSize || '3', 10) || 3);
            const pages = Math.ceil(rows.length / size);
            if (pages <= 1) { pager.innerHTML = ''; return; }
            let current = 1;
            const show = (pg) => {
                current = Math.min(pages, Math.max(1, pg));
                rows.forEach((r, i) => {
                    const on = i >= (current - 1) * size && i < current * size;
                    r.style.display = on ? '' : 'none';
                });
                Array.from(pager.querySelectorAll('.vter__pg')).forEach((b) => {
                    const p = b.dataset.pg;
                    if (p === 'prev') { b.disabled = current === 1; }
                    else if (p === 'next') { b.disabled = current === pages; }
                    else { b.classList.toggle('is-active', Number(p) === current); }
                });
            };
            const mk = (label, pg, cls) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'vter__pg' + (cls ? ' ' + cls : '');
                b.dataset.pg = pg;
                b.textContent = label;
                b.addEventListener('click', () => show(pg === 'prev' ? current - 1 : (pg === 'next' ? current + 1 : Number(pg))));
                return b;
            };
            pager.innerHTML = '';
            pager.appendChild(mk('‹', 'prev'));
            for (let i = 1; i <= pages; i++) { pager.appendChild(mk(String(i), String(i))); }
            pager.appendChild(mk('›', 'next'));
            show(1);
        };
        document.querySelectorAll('[data-vter]').forEach(build);
    })();
    </script>
<?php endif; ?>
<?php if (!empty($hasCategorySlider)): ?>
    <script>
    (() => {
        if (window.__vtcsInit) { return; }
        window.__vtcsInit = true;
        const init = (sl) => {
            const vp = sl.querySelector('[data-vt-vp]');
            if (!vp) { return; }
            const dotsWrap = sl.querySelector('[data-vt-dots]');
            const prev = sl.querySelector('[data-vt-prev]');
            const next = sl.querySelector('[data-vt-next]');
            // WP-24 (VT-C4, din vertij e577b94, 01e2773, 76dd45e): navigarea card cu card.
            const cards = () => Array.from(vp.children);
            // pasul = un card (lățime + gap), citit din distanța dintre primele două carduri
            const step = () => {
                const cs = cards();
                if (cs.length >= 2) { return Math.max(1, cs[1].offsetLeft - cs[0].offsetLeft); }
                if (cs.length === 1) { return Math.max(1, cs[0].offsetWidth); }
                return vp.clientWidth || 1;
            };
            const count = () => cards().length;
            const maxScroll = () => Math.max(0, vp.scrollWidth - vp.clientWidth);
            // ultima poziție de start care poate fi atinsă (cardurile din coadă
            // nu pot deveni primul-din-stânga când slider-ul ajunge la capăt)
            const lastStart = () => {
                const cs = cards();
                const max = maxScroll();
                let idx = 0;
                for (let i = 0; i < cs.length; i++) {
                    if (cs[i].offsetLeft <= max + 1) { idx = i; }
                }
                return idx;
            };
            const dotCount = () => lastStart() + 1;
            const current = () => Math.min(lastStart(), Math.round(vp.scrollLeft / step()));
            const update = () => {
                const c = current();
                if (dotsWrap) {
                    Array.from(dotsWrap.children).forEach((d, i) => d.classList.toggle('is-active', i === c));
                }
                if (prev) { prev.disabled = vp.scrollLeft <= 1; }
                if (next) { next.disabled = vp.scrollLeft >= maxScroll() - 1; }
            };
            // scroll programatic care ignoră temporar snap-ul (scroll-snap-stop:always
            // ar opri altfel scroll-ul la primul card, blocând salturile mai lungi)
            const jumpTo = (left) => {
                vp.style.scrollSnapType = 'none';
                vp.scrollTo({ left: left, behavior: 'smooth' });
                clearTimeout(vp.__vtSnap);
                vp.__vtSnap = setTimeout(() => { vp.style.scrollSnapType = ''; }, 600);
            };
            const goTo = (i) => {
                const ls = lastStart();
                const idx = Math.max(0, Math.min(ls, i));
                // ultima poziție merge până la capăt, ca să se vadă complet ultimul card
                const target = idx >= ls ? maxScroll() : Math.min(idx * step(), maxScroll());
                jumpTo(target);
            };
            const buildDots = () => {
                if (!dotsWrap) { return; }
                const n = dotCount();
                dotsWrap.innerHTML = '';
                if (n <= 1) { update(); return; }
                for (let i = 0; i < n; i++) {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'vtcs__dot';
                    b.setAttribute('aria-label', 'Cardul ' + (i + 1));
                    b.addEventListener('click', () => goTo(i));
                    dotsWrap.appendChild(b);
                }
                update();
            };
            if (prev) { prev.addEventListener('click', () => goTo(current() - 1)); }
            if (next) { next.addEventListener('click', () => goTo(current() + 1)); }
            let st;
            vp.addEventListener('scroll', () => { clearTimeout(st); st = setTimeout(update, 80); });
            let rt;
            window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(buildDots, 150); });
            buildDots();
        };
        const boot = () => document.querySelectorAll('[data-vt-slider]').forEach(init);
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot);
        } else {
            boot();
        }
    })();
    </script>
<?php endif; ?>
