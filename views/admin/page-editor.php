<?php
$pageId = (int) ($page['id'] ?? 0);
$pageTitle = (string) ($page['title'] ?? '');
$pageSlug = (string) ($page['slug'] ?? '');
$pageHtml = (string) ($page['html_content'] ?? '');
$pageCss = (string) ($page['css_content'] ?? '');
$pageJs = (string) ($page['js_content'] ?? '');
$isPublished = (int) ($page['is_published'] ?? 1) === 1;
$pageSeo = is_array($pageSeo ?? null) ? $pageSeo : [];
$pageSeoTitle = (string) ($pageSeo['title'] ?? '');
$pageSeoDescription = (string) ($pageSeo['description'] ?? '');
$pageSeoCanonicalUrl = (string) ($pageSeo['canonical_url'] ?? '');
$pageSeoImageUrl = (string) ($pageSeo['image_url'] ?? '');
$mannequinPreviewHtml = (string) ($mannequinPreviewHtml ?? '');
$shopCatalogCodeToken = (string) ($shopCatalogCode ?? '{{shop_catalog}}');
$shopCatalogPreviewHtml = (string) ($shopCatalogPreviewHtml ?? '');
$blogPostsCodeToken = (string) ($blogPostsCode ?? '{{blog_posts}}');
$blogPostsPreviewHtml = (string) ($blogPostsPreviewHtml ?? '');
$cartFormCodeToken = (string) ($cartFormCode ?? '{{cart_form}}');
$cartFormPreviewHtml = (string) ($cartFormPreviewHtml ?? '');
$checkoutFormCodeToken = (string) ($checkoutFormCode ?? '{{checkout_form}}');
$checkoutFormPreviewHtml = (string) ($checkoutFormPreviewHtml ?? '');
$accountSectionCodeToken = (string) ($accountSectionCode ?? '{{account_section}}');
$accountSectionPreviewHtml = (string) ($accountSectionPreviewHtml ?? '');
$authGoogleButtonCodeToken = (string) ($authGoogleButtonCode ?? '{{auth_google_button}}');
$authGoogleButtonPreviewHtml = (string) ($authGoogleButtonPreviewHtml ?? '');
$productReviewFormCodeToken = (string) ($productReviewFormCode ?? '{{product_review_form}}');
$productReviewFormPreviewHtml = (string) ($productReviewFormPreviewHtml ?? '');
$gdprAgreementsFormCodeToken = (string) ($gdprAgreementsFormCode ?? '{{gdpr_agreements_form}}');
$gdprAgreementsFormPreviewHtml = (string) ($gdprAgreementsFormPreviewHtml ?? '');
$checkoutSuccessOrderInfoCodeToken = (string) ($checkoutSuccessOrderInfoCode ?? '{{checkout_success_order_info}}');
$checkoutSuccessOrderInfoPreviewHtml = (string) ($checkoutSuccessOrderInfoPreviewHtml ?? '');
// Pe cadrul capturat, o pagină adusă din WordPress (AdminController::randeazaEditorPagina): previzualizarea e documentul paginii de
// pe site, randat de server (POST /admin/pages/{id}/previzualizare), iar taburile JS și CSS lipsesc (nu se folosesc pe site).
$paginaCapturata = ($paginaCapturata ?? false) === true;
$jetonPrevizualizare = (string) ($jetonPrevizualizare ?? '');
$previewAppCssVersion = @filemtime(\App\Support\Paths::public('assets/css/app.css')) ?: time();
$previewAppCssHref = '/assets/css/app.css?v=' . $previewAppCssVersion;
$previewAppCssContent = @file_get_contents(\App\Support\Paths::public('assets/css/app.css'));
if (!is_string($previewAppCssContent)) {
    $previewAppCssContent = '';
}
?>

<section class="panel">
    <?php $mannequinCodeToken = (string) ($mannequinCode ?? '{{mannequin_section}}'); ?>
    <form method="post" action="/admin/pages/save" id="page-editor-form">
        <input type="hidden" name="id" value="<?= $pageId ?>">

        <div class="page-toolbar">
            <a href="/admin/pages" class="btn btn-secondary">&larr; Înapoi</a>
            <button type="button" class="btn btn-secondary code-toggle-arrow" id="toggle-code-pane" title="Ascunde/arată cod">⟵</button>
            <div class="field" style="min-width:260px;flex:1;">
                <input type="text" id="page-title" name="title" value="<?= htmlspecialchars($pageTitle, ENT_QUOTES) ?>" placeholder="Titlu paginii" required>
            </div>
            <div class="field" style="width:220px;">
                <input type="text" id="page-slug" name="slug" value="<?= htmlspecialchars($pageSlug, ENT_QUOTES) ?>" placeholder="slug-url (gol pentru pagina /)">
            </div>
            <label style="display:flex;align-items:center;gap:8px;white-space:nowrap;">
                <input type="checkbox" name="is_published" value="1" <?= $isPublished ? 'checked' : '' ?>>
                Publicată
            </label>
<?= \App\Support\Hooks::html('editor.pagina.campuri', is_array($page ?? null) ? $page : []) ?>
            <div style="display:flex;gap:8px;align-items:center;">
                <button type="button" class="btn btn-secondary device-switch active" data-device="desktop" title="Desktop">
                    <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8"/><path d="M12 16v4"/></svg>
                </button>
                <button type="button" class="btn btn-secondary device-switch" data-device="tablet" title="Tabletă">
                    <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="3" width="12" height="18" rx="2"/><circle cx="12" cy="17.5" r="0.8"/></svg>
                </button>
                <button type="button" class="btn btn-secondary device-switch" data-device="mobile" title="Telefon">
                    <svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="2" width="8" height="20" rx="2"/><circle cx="12" cy="18.5" r="0.8"/></svg>
                </button>
            </div>
            <button class="btn" type="submit">Salvează</button>
        </div>

        <details class="panel" style="margin-top:12px;background:var(--ax-suprafata-2);border-color:var(--ax-linie-tare);">
            <summary style="cursor:pointer;font-weight:700;color:var(--ax-text-2);">Coduri disponibile pentru pagină <span style="color:var(--ax-text-3);font-weight:400;font-size:.9em;">(click pentru arată/ascunde)</span></summary>
            <p style="margin:10px 0;color:var(--ax-text-3);">
                Pentru secțiuni dinamice, poți folosi token-urile de mai jos în HTML:
            </p>
            <div style="display:grid;gap:6px;">
<?php if (\App\Support\Plugins::tokenVizibil('mannequin_section')): ?>
                <div><code><?= htmlspecialchars($mannequinCodeToken, ENT_QUOTES) ?></code> <span style="color:var(--ax-text-3);">→ secțiune manechin</span></div>
<?php endif; ?>
<?php if (\App\Support\Plugins::tokenVizibil('shop_catalog')): ?>
                <div><code><?= htmlspecialchars($shopCatalogCodeToken, ENT_QUOTES) ?></code> <span style="color:var(--ax-text-3);">→ catalog magazin (filtre + produse)</span></div>
<?php endif; ?>
<?= \App\Support\Dev::marcaj('bv:token-produse-sablon') ?>
<?php if (\App\Support\Plugins::tokenVizibil('products_by_template')): ?>
                <div><code>{{products_by_template:slug-template}}</code> <span style="color:var(--ax-text-3);">→ produsele care folosesc un template de produs, în cardurile catalogului. Opțional, oricâte și în orice ordine, despărțite prin <code>|</code>: <code>limit=4</code>, <code>link=/pachete</code>, <code>text=Vezi toate pachetele</code>, <code>titlu=...</code>, <code>subtitlu=...</code> (ex: <code>{{products_by_template:template-pachete|limit=4|link=/pachete|text=Vezi toate pachetele}}</code>)</span></div>
<?php endif; ?>
<?php if (\App\Support\Plugins::tokenVizibil('produse_serviciu')): ?>
                <div><code>{{produse_serviciu}}</code> <span style="color:var(--ax-text-3);">→ pe o pagină de serviciu: produsele categoriei care are slug-ul ultimului segment al paginii (ex: pagina <code>servicii/lipire-cutii</code> → categoria <code>lipire-cutii</code>), fără preț și coș</span></div>
<?php endif; ?>
<?= \App\Support\Dev::marcaj('bv:token-produse-sablon', true) ?>
<?php if (\App\Support\Plugins::tokenVizibil('blog_posts')): ?>
                <div><code><?= htmlspecialchars($blogPostsCodeToken, ENT_QUOTES) ?></code> <span style="color:var(--ax-text-3);">→ listare postări blog</span></div>
<?php endif; ?>
<?php if (\App\Support\Plugins::tokenVizibil('cart_form')): ?>
                <div><code><?= htmlspecialchars($cartFormCodeToken, ENT_QUOTES) ?></code> <span style="color:var(--ax-text-3);">→ coș de cumpărături (produse + rezumat)</span></div>
<?php endif; ?>
<?php if (\App\Support\Plugins::tokenVizibil('checkout_form')): ?>
                <div><code><?= htmlspecialchars($checkoutFormCodeToken, ENT_QUOTES) ?></code> <span style="color:var(--ax-text-3);">→ formular checkout modern (sumar + plată)</span></div>
<?php endif; ?>
<?php if (\App\Support\Plugins::tokenVizibil('checkout_success_order_info')): ?>
                <div><code><?= htmlspecialchars($checkoutSuccessOrderInfoCodeToken, ENT_QUOTES) ?></code> <span style="color:var(--ax-text-3);">→ sumar comandă pentru pagina checkout/succes (număr + status)</span></div>
<?php endif; ?>
<?php if (\App\Support\Plugins::tokenVizibil('account_section')): ?>
                <div><code><?= htmlspecialchars($accountSectionCodeToken, ENT_QUOTES) ?></code> <span style="color:var(--ax-text-3);">→ secțiune cont client (profil, comenzi, adrese, puncte)</span></div>
<?php endif; ?>
<?php if (\App\Support\Plugins::tokenVizibil('auth_google_button')): ?>
                <div><code><?= htmlspecialchars($authGoogleButtonCodeToken, ENT_QUOTES) ?></code> <span style="color:var(--ax-text-3);">→ buton Google Login/Register (manual în builder)</span></div>
<?php endif; ?>
<?php if (\App\Support\Plugins::tokenVizibil('product_review_form')): ?>
                <div><code><?= htmlspecialchars($productReviewFormCodeToken, ENT_QUOTES) ?></code> <span style="color:var(--ax-text-3);">→ formular recenzii produse (util pentru pagini QR)</span></div>
<?php endif; ?>
<?php if (\App\Support\Plugins::tokenVizibil('gdpr_agreements_form')): ?>
                <div><code><?= htmlspecialchars($gdprAgreementsFormCodeToken, ENT_QUOTES) ?></code> <span style="color:var(--ax-text-3);">→ formular acorduri GDPR (semnătură tactilă + salvare backend)</span></div>
<?php endif; ?>
<?php if (\App\Support\Plugins::tokenVizibil('next_event_image')): ?>
                <div><code>{{next_event_image}}</code> <span style="color:var(--ax-text-3);">→ imaginea principală a următorului eveniment</span></div>
<?php endif; ?>
<?php if (\App\Support\Plugins::tokenVizibil('next_event_url')): ?>
                <div><code>{{next_event_url}}</code> <span style="color:var(--ax-text-3);">→ link către pagina următorului eveniment</span></div>
<?php endif; ?>
<?php if (\App\Support\Plugins::tokenVizibil('next_event_title')): ?>
                <div><code>{{next_event_title}}</code> <span style="color:var(--ax-text-3);">→ titlul următorului eveniment</span></div>
<?php endif; ?>
<?php if (\App\Support\Plugins::tokenVizibil('next_event_excerpt')): ?>
                <div><code>{{next_event_excerpt}}</code> <span style="color:var(--ax-text-3);">→ descrierea succintă a următorului eveniment</span></div>
<?php endif; ?>
<?php if (\App\Support\Plugins::tokenVizibil('next_event_period')): ?>
                <div><code>{{next_event_period}}</code> <span style="color:var(--ax-text-3);">→ perioada următorului eveniment</span></div>
<?php endif; ?>
<?php if (\App\Support\Plugins::tokenVizibil('next_event_video')): ?>
                <div><code>{{next_event_video}}</code> <span style="color:var(--ax-text-3);">→ playerul video al următorului eveniment</span></div>
<?php endif; ?>
<?php if (\App\Support\Plugins::tokenVizibil('category_posts')): ?>
                <div><code>{{category_posts:slug-categorie}}</code> <span style="color:var(--ax-text-3);">→ slider cu postările dintr-o categorie (ex: <code>{{category_posts:noutati}}</code>)</span></div>
<?php endif; ?>
<?php if (\App\Support\Plugins::tokenVizibil('category_grid')): ?>
                <div><code>{{category_grid:slug-categorie}}</code> <span style="color:var(--ax-text-3);">→ grilă cu postările dintr-o categorie; pentru evenimente, viitoarele primele, apoi cele trecute (ex: <code>{{category_grid:evenimente}}</code>)</span></div>
<?php endif; ?>
<?php if (\App\Support\Plugins::tokenVizibil('category_events')): ?>
                <div><code>{{category_events:slug-categorie}}</code> <span style="color:var(--ax-text-3);">→ pagină de evenimente: hero cu următorul eveniment + secțiunile „viitoare" și „trecute" (ex: <code>{{category_events:evenimente}}</code>)</span></div>
<?php endif; ?>
<?= \App\Support\Dev::marcaj('vt:token-evenimente') ?>
<?php if (\App\Support\Plugins::tokenVizibil('category_event_rows')): ?>
                <div><code>{{category_event_rows:slug-categorie}}</code> <span style="color:var(--ax-text-3);">→ listă compactă cu postările dintr-o categorie (imagine mică, titlu, dată, „Detalii"), paginată câte 3 (ex: <code>{{category_event_rows:evenimente}}</code>)</span></div>
<?php endif; ?>
<?php if (\App\Support\Plugins::tokenVizibil('category_grid_paged')): ?>
                <div><code>{{category_grid_paged:slug1,slug2,...}}</code> <span style="color:var(--ax-text-3);">→ grilă cu postările din mai multe categorii (separate prin virgulă), paginată câte 6 (ex: <code>{{category_grid_paged:evenimente,noutati}}</code>)</span></div>
<?php endif; ?>
<?= \App\Support\Dev::marcaj('vt:token-evenimente', true) ?>
            </div>
        </details>

        <div class="editor-grid" id="page-editor-grid" style="margin-top:14px;">
            <div class="code-column" id="code-column">
<?php if ($paginaCapturata): ?>
                <div class="code-type-tabs">
                    <button type="button" class="code-type-tab active" data-code-type="html">HTML</button>
                </div>
                <p class="ax-nota-capturata" id="nota-pagina-capturata" style="margin:6px 0 10px;color:var(--ax-text-3);font-size:.9em;">
                    Pagină adusă din WordPress: previzualizarea e pagina de pe site (antetul, subsolul și foile ei), cu codul de aici în locul
                    conținutului. Codul JavaScript și CSS al paginii nu se folosește pe site: comportamentul îl dă runtime-ul, iar la salvare
                    codul trece prin aceeași verificare ca la import (niciun script, niciun atribut <code>on…</code>).
                </p>
<?php else: ?>
                <div class="code-type-tabs">
                    <button type="button" class="code-type-tab active" data-code-type="html">HTML</button>
                    <button type="button" class="code-type-tab" data-code-type="js">Java Script</button>
                    <button type="button" class="code-type-tab" data-code-type="css">CSS</button>
                </div>
<?php endif; ?>
                <div class="code-editor-toolbar">
                    <div class="code-editor-toolbar-right">
                        <button type="button" class="btn btn-secondary" id="code-search-btn">Search/Replace</button>
                        <button type="button" class="btn btn-secondary" id="code-beautify-btn">Beautify</button>
                        <button type="button" class="btn btn-secondary" id="code-fullscreen-btn">Fullscreen</button>
                    </div>
                </div>
                <textarea id="html-code" name="html_content" class="code-editor code-editor-pane" data-code-pane="html" required><?= htmlspecialchars($pageHtml, ENT_NOQUOTES) ?></textarea>
<?php if (!$paginaCapturata): ?>
                <textarea id="js-code" name="js_content" class="code-editor code-editor-pane is-hidden" data-code-pane="js"><?= htmlspecialchars($pageJs, ENT_NOQUOTES) ?></textarea>
                <textarea id="css-code" name="css_content" class="code-editor code-editor-pane is-hidden" data-code-pane="css"><?= htmlspecialchars($pageCss, ENT_NOQUOTES) ?></textarea>
<?php endif; ?>
            </div>
            <div class="preview-column">
                <h3 style="margin-top:0;">Preview — <span id="preview-mode-label">Desktop</span></h3>
                <div class="preview-shell desktop" id="preview-shell">
<?php if ($paginaCapturata): ?>
                    <iframe id="page-preview" name="page-preview-capturata" title="Preview pagină" sandbox="allow-scripts"></iframe>
<?php else: ?>
                    <iframe id="page-preview" title="Preview pagină" sandbox="allow-scripts"></iframe>
<?php endif; ?>
                </div>
            </div>
        </div>

        <article class="panel" style="margin-top:12px;">
            <h3 style="margin-top:0;">SEO pentru această pagină</h3>
            <p style="margin:0 0 10px;color:var(--ax-text-3);">
                Aceste valori suprascriu setările SEO globale doar pentru pagina curentă.
            </p>
<?php
// WP-37: panoul SEO comun (previzualizarea, numărătoarea, verificările; câmpurile cu aceleași nume și id-uri, plus robots
// și og:type).
admin_componenta('panou-seo', [
    'tip' => 'custom_page', 'ref' => $pageId > 0 ? (string) $pageId : '', 'id' => 'pagina-seo',
    'valori' => $pageSeo,
    'iduri' => ['titlu' => 'seo_title', 'descriere' => 'seo_description', 'canonical' => 'seo_canonical_url', 'robots' => 'seo_robots', 'og' => 'seo_og_type'],
    'max_titlu' => 120, 'max_descriere' => 320,
    'prefix_adresa' => '/', 'sursa_slug' => '#page-slug', 'sursa_titlu' => '#page-title',
    'verificari' => $pageId > 0 ? \App\Support\Seo::verificari(\App\Support\Database::connection([]), 'custom_page', (string) $pageId, $pageSeoTitle, $pageSeoDescription,
        static fn (string $link): bool => \App\Support\Auth::canAccessPath($link)) : [],
]);
?>
            <div class="field" style="max-width:720px;">
                <label for="seo_image_url">Imagine social preview (OG/Twitter)</label>
                <input id="seo_image_url" type="text" name="seo_image_url" value="<?= htmlspecialchars($pageSeoImageUrl, ENT_QUOTES) ?>" placeholder="/uploads/gallery/imagine-seo.jpg sau https://...">
            </div>
        </article>
    </form>
<?php if ($paginaCapturata): ?>
    <?php // Previzualizarea paginii capturate: codul din editor, trimis spre cadrul izolat (target), cu jetonul sesiunii. ?>
    <form method="post" action="/admin/pages/<?= $pageId ?>/previzualizare" target="page-preview-capturata" id="previzualizare-capturata-form" hidden>
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($jetonPrevizualizare, ENT_QUOTES) ?>">
        <input type="hidden" name="html_content_b64" value="">
    </form>
<?php endif; ?>

    <div style="margin-top:12px;">
<?php if (\App\Support\WpCompat::activ()): ?>
        URL public: <strong id="page-url-preview" data-slash-final="<?= \App\Support\WpCompat::slashFinal() ? '1' : '0' ?>"><?= $pageSlug !== '' ? ('/' . htmlspecialchars($pageSlug, ENT_QUOTES) . (\App\Support\WpCompat::slashFinal() ? '/' : '')) : '/' ?></strong>
<?php else: ?>
        URL public: <strong id="page-url-preview"><?= $pageSlug !== '' ? ('/' . htmlspecialchars($pageSlug, ENT_QUOTES)) : '/' ?></strong>
<?php endif; ?>
    </div>
</section>

<script>
(() => {
    const htmlCode = document.getElementById('html-code');
    const jsCode = document.getElementById('js-code');
    const cssCode = document.getElementById('css-code');
    const codeTypeTabs = document.querySelectorAll('.code-type-tab');
    const codePanes = document.querySelectorAll('.code-editor-pane');
    const iframe = document.getElementById('page-preview');
    const switches = document.querySelectorAll('.device-switch');
    const previewShell = document.getElementById('preview-shell');
    const editorGrid = document.getElementById('page-editor-grid');
    const codeColumn = document.getElementById('code-column');
    const toggleCodePane = document.getElementById('toggle-code-pane');
    const codeSearchBtn = document.getElementById('code-search-btn');
    const codeBeautifyBtn = document.getElementById('code-beautify-btn');
    const codeFullscreenBtn = document.getElementById('code-fullscreen-btn');
    const slugInput = document.getElementById('page-slug');
    const titleInput = document.getElementById('page-title');
    const pageUrlPreview = document.getElementById('page-url-preview');
    const previewModeLabel = document.getElementById('preview-mode-label');
    const mannequinCodeToken = <?= json_encode((string) ($mannequinCodeToken ?? '{{mannequin_section}}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const mannequinSectionHtml = <?= json_encode($mannequinPreviewHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const shopCatalogCodeToken = <?= json_encode((string) ($shopCatalogCodeToken ?? '{{shop_catalog}}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const shopCatalogSectionHtml = <?= json_encode($shopCatalogPreviewHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const blogPostsCodeToken = <?= json_encode((string) ($blogPostsCodeToken ?? '{{blog_posts}}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const blogPostsSectionHtml = <?= json_encode($blogPostsPreviewHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const cartFormCodeToken = <?= json_encode((string) ($cartFormCodeToken ?? '{{cart_form}}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const cartFormSectionHtml = <?= json_encode($cartFormPreviewHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const checkoutFormCodeToken = <?= json_encode((string) ($checkoutFormCodeToken ?? '{{checkout_form}}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const checkoutFormSectionHtml = <?= json_encode($checkoutFormPreviewHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const checkoutSuccessOrderInfoCodeToken = <?= json_encode((string) ($checkoutSuccessOrderInfoCodeToken ?? '{{checkout_success_order_info}}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const checkoutSuccessOrderInfoPreviewHtml = <?= json_encode($checkoutSuccessOrderInfoPreviewHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const accountSectionCodeToken = <?= json_encode((string) ($accountSectionCodeToken ?? '{{account_section}}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const accountSectionPreviewHtml = <?= json_encode($accountSectionPreviewHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const authGoogleButtonCodeToken = <?= json_encode((string) ($authGoogleButtonCodeToken ?? '{{auth_google_button}}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const authGoogleButtonPreviewHtml = <?= json_encode($authGoogleButtonPreviewHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const productReviewFormCodeToken = <?= json_encode((string) ($productReviewFormCodeToken ?? '{{product_review_form}}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const productReviewFormPreviewHtml = <?= json_encode($productReviewFormPreviewHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const gdprAgreementsFormCodeToken = <?= json_encode((string) ($gdprAgreementsFormCodeToken ?? '{{gdpr_agreements_form}}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const gdprAgreementsFormPreviewHtml = <?= json_encode($gdprAgreementsFormPreviewHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const previewAppCssHref = <?= json_encode($previewAppCssHref, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const previewAppCssContent = <?= json_encode($previewAppCssContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let slugTouched = slugInput.value.trim() !== '';
    const editors = {};

    const createEditor = (textarea, mode, type) => {
        if (!textarea) return null;
        if (typeof window.CodeMirror !== 'function') {
            return {
                getValue: () => textarea.value,
                setValue: (value) => { textarea.value = String(value ?? ''); },
                save: () => {},
                setVisible: (visible) => textarea.classList.toggle('is-hidden', !visible),
                refresh: () => {},
                onChange: (handler) => textarea.addEventListener('input', handler),
                openSearch: () => {},
                closeSearch: () => {},
                isSearchOpen: () => false,
                focus: () => textarea.focus(),
                type,
                getSelection: () => {
                    const start = textarea.selectionStart ?? 0;
                    const end = textarea.selectionEnd ?? start;
                    return textarea.value.slice(start, end);
                },
            };
        }

        const cm = window.CodeMirror.fromTextArea(textarea, {
            mode,
            theme: 'material-darker',
            lineNumbers: true,
            lineWrapping: true,
            styleActiveLine: true,
            matchBrackets: true,
            autoCloseBrackets: true,
            indentUnit: 2,
            tabSize: 2,
            viewportMargin: Infinity,
        });
        cm.setSize('100%', 560);
        return {
            getValue: () => cm.getValue(),
            setValue: (value) => cm.setValue(String(value ?? '')),
            save: () => cm.save(),
            setVisible: (visible) => {
                const wrapper = cm.getWrapperElement();
                wrapper.style.display = visible ? '' : 'none';
                textarea.classList.toggle('is-hidden', !visible);
                if (visible) cm.refresh();
            },
            refresh: () => cm.refresh(),
            onChange: (handler) => cm.on('change', handler),
            openSearch: () => {
                cm.execCommand('find');
                cm.execCommand('replace');
            },
            closeSearch: () => cm.execCommand('clearSearch'),
            isSearchOpen: () => cm.getWrapperElement().classList.contains('CodeMirror-dialog-open'),
            focus: () => cm.focus(),
            type,
            getSelection: () => cm.getSelection(),
        };
    };

    editors.html = createEditor(htmlCode, 'text/html', 'html');
    editors.js = createEditor(jsCode, 'javascript', 'js');
    editors.css = createEditor(cssCode, 'css', 'css');

    const getEditorValue = (type) => editors[type]?.getValue?.() ?? '';
    const getActiveCodeType = () => document.querySelector('.code-type-tab.active')?.dataset.codeType || 'html';
    const getActiveEditor = () => editors[getActiveCodeType()] || null;
    const beautifyContent = (editor) => {
        if (!editor) return false;
        const value = editor.getValue();
        try {
            let formatted = value;
            if (editor.type === 'html' && typeof window.html_beautify === 'function') {
                formatted = window.html_beautify(value, { indent_size: 2, wrap_line_length: 120, preserve_newlines: true });
            } else if (editor.type === 'css' && typeof window.css_beautify === 'function') {
                formatted = window.css_beautify(value, { indent_size: 2 });
            } else if (editor.type === 'js' && typeof window.js_beautify === 'function') {
                formatted = window.js_beautify(value, { indent_size: 2, preserve_newlines: true });
            } else {
                return false;
            }
            editor.setValue(formatted);
            return true;
        } catch {
            return false;
        }
    };
    const setActiveCodeType = (type) => {
        codeTypeTabs.forEach((tab) => tab.classList.toggle('active', tab.dataset.codeType === type));
        codePanes.forEach((pane) => {
            const paneType = pane.dataset.codePane;
            const visible = paneType === type;
            pane.classList.toggle('is-hidden', !visible);
            editors[paneType]?.setVisible?.(visible);
        });
    };

    const applyPreviewTokens = (rawHtml) => {
        let html = String(rawHtml || '');
        if (mannequinCodeToken !== '') {
            html = html.split(mannequinCodeToken).join(mannequinSectionHtml);
        }
        if (shopCatalogCodeToken !== '') {
            html = html.split(shopCatalogCodeToken).join(shopCatalogSectionHtml);
        }
        if (blogPostsCodeToken !== '') {
            html = html.split(blogPostsCodeToken).join(blogPostsSectionHtml);
        }
        if (cartFormCodeToken !== '') {
            html = html.split(cartFormCodeToken).join(cartFormSectionHtml);
        }
        if (checkoutFormCodeToken !== '') {
            html = html.split(checkoutFormCodeToken).join(checkoutFormSectionHtml);
        }
        if (checkoutSuccessOrderInfoCodeToken !== '') {
            html = html.split(checkoutSuccessOrderInfoCodeToken).join(checkoutSuccessOrderInfoPreviewHtml);
        }
        if (accountSectionCodeToken !== '') {
            html = html.split(accountSectionCodeToken).join(accountSectionPreviewHtml);
        }
        if (authGoogleButtonCodeToken !== '') {
            html = html.split(authGoogleButtonCodeToken).join(authGoogleButtonPreviewHtml);
        }
        if (productReviewFormCodeToken !== '') {
            html = html.split(productReviewFormCodeToken).join(productReviewFormPreviewHtml);
        }
        if (gdprAgreementsFormCodeToken !== '') {
            html = html.split(gdprAgreementsFormCodeToken).join(gdprAgreementsFormPreviewHtml);
        }
        return html;
    };

    // Puntea din previzualizare (N1a, docs/SECURITATE.md): cadrul e izolat (sandbox="allow-scripts", fără allow-same-origin,
    // deci cu o origine opacă) și pagina nu-i mai poate citi documentul. Scripturile de mai jos (chenarele codurilor cu
    // parametri) îi cer, printr-un mesaj, nodurile de text cu un cod și îi trimit înapoi HTML-ul lor; cadrul ascultă doar
    // de fereastra și de originea adminului.
    const puntePrevizualizare = function (originePagina) {
        const noduri = {};
        window.addEventListener('message', (e) => {
            const d = e.data;
            if (e.source !== window.parent || e.origin !== originePagina || !d || typeof d !== 'object' || typeof d.id !== 'string') {
                return;
            }
            if (d.ax === 'cere-noduri' && typeof d.test === 'string') {
                let test;
                try {
                    test = new RegExp(d.test, 'i');
                } catch (_) {
                    return;
                }
                const gasite = [];
                const parcurgere = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
                while (parcurgere.nextNode()) {
                    if (test.test(parcurgere.currentNode.nodeValue || '')) {
                        gasite.push(parcurgere.currentNode);
                    }
                }
                noduri[d.id] = gasite;
                window.parent.postMessage({ ax: 'noduri', id: d.id, texte: gasite.map((nod) => nod.nodeValue || '') }, originePagina);
            } else if (d.ax === 'inlocuieste-noduri' && Array.isArray(d.html) && Array.isArray(noduri[d.id])) {
                noduri[d.id].forEach((nod, i) => {
                    if (typeof d.html[i] !== 'string' || !nod.parentNode) {
                        return;
                    }
                    const loc = document.createElement('span');
                    loc.innerHTML = d.html[i];
                    nod.replaceWith(...Array.from(loc.childNodes));
                });
                delete noduri[d.id];
            }
        });
    };
    const scriptPunte = '<script>(' + puntePrevizualizare.toString() + ')(' + JSON.stringify(window.location.origin) + ');<\/script>';

<?php if ($paginaCapturata): ?>
    // Pagina capturată: serverul randează documentul paginii de pe site cu codul din editor (după o pauză de scris), în același
    // cadru izolat (sandbox="allow-scripts", fără allow-same-origin): scripturile runtime-ului rulează ca pe site, cu politica lui.
    const formularPrevizualizare = document.getElementById('previzualizare-capturata-form');
    let temporizarePrevizualizare = 0;
    const trimitePrevizualizare = () => {
        if (!(formularPrevizualizare instanceof HTMLFormElement)) {
            return;
        }
        formularPrevizualizare.elements.namedItem('html_content_b64').value = encodeBase64Utf8(getEditorValue('html'));
        formularPrevizualizare.submit();
    };
    const renderPreview = () => {
        window.clearTimeout(temporizarePrevizualizare);
        temporizarePrevizualizare = window.setTimeout(trimitePrevizualizare, 400);
    };
    editors.html?.onChange?.(renderPreview);
<?php else: ?>
    const renderPreview = () => {
        const bodyRaw = getEditorValue('html') || '<p>Scrie codul paginii pentru preview...</p>';
        const body = applyPreviewTokens(bodyRaw);
        const css = getEditorValue('css') || '';
        const js = (getEditorValue('js') || '').replace(/<\/script>/gi, '<\\/script>');
        iframe.srcdoc = '<!doctype html>'
            + '<html lang="ro"><head>'
            + '<meta charset="utf-8" />'
            + '<meta name="viewport" content="width=device-width, initial-scale=1" />'
            + '<link rel="stylesheet" href="' + previewAppCssHref + '">'
            + '<style>' + previewAppCssContent + '</style>'
            + '<style>body{margin:0;padding:24px;line-height:1.6;background:#fff;}img{max-width:100%;height:auto;}</style>'
            + '<style>' + css + '</style>'
            + '</head><body>' + body + '<script>' + js + '<\/script>' + scriptPunte + '</body></html>';
    };

    editors.html?.onChange?.(renderPreview);
    editors.js?.onChange?.(renderPreview);
    editors.css?.onChange?.(renderPreview);
<?php endif; ?>
    renderPreview();

    codeTypeTabs.forEach((button) => {
        button.addEventListener('click', () => {
            const type = button.dataset.codeType || 'html';
            setActiveCodeType(type);
        });
    });
    setActiveCodeType('html');

    switches.forEach((button) => {
        button.addEventListener('click', () => {
            switches.forEach((item) => item.classList.remove('active'));
            button.classList.add('active');
            previewShell.classList.remove('desktop', 'tablet', 'mobile');
            previewShell.classList.add(button.dataset.device);
            const labelMap = { desktop: 'Desktop', tablet: 'Tabletă', mobile: 'Telefon' };
            previewModeLabel.textContent = labelMap[button.dataset.device] || 'Desktop';
        });
    });

    toggleCodePane.addEventListener('click', () => {
        const nowHidden = !codeColumn.classList.contains('is-hidden');
        codeColumn.classList.toggle('is-hidden', nowHidden);
        editorGrid.classList.toggle('code-hidden', nowHidden);
        toggleCodePane.textContent = nowHidden ? '⟶' : '⟵';
        if (!nowHidden) {
            const active = document.querySelector('.code-type-tab.active')?.dataset.codeType || 'html';
            editors[active]?.refresh?.();
        }
    });
    codeSearchBtn?.addEventListener('click', () => {
        const editor = getActiveEditor();
        if (editor?.isSearchOpen?.()) {
            editor?.closeSearch?.();
            return;
        }
        editor?.openSearch?.();
        editor?.focus?.();
    });
    codeBeautifyBtn?.addEventListener('click', () => {
        const editor = getActiveEditor();
        const ok = beautifyContent(editor);
        if (ok) {
            renderPreview();
            codeBeautifyBtn.classList.add('active');
            window.setTimeout(() => codeBeautifyBtn.classList.remove('active'), 700);
        }
    });
    codeFullscreenBtn?.addEventListener('click', () => {
        codeColumn.classList.toggle('is-fullscreen');
        const active = getActiveCodeType();
        editors[active]?.refresh?.();
        codeFullscreenBtn.textContent = codeColumn.classList.contains('is-fullscreen') ? 'Exit fullscreen' : 'Fullscreen';
    });

<?php if (\App\Support\WpCompat::slugUnicode()): ?>
    // Modul WordPress (wp-compat.json): adresa cu „/” final și slug-urile cu literele oricărei limbi (ca Slug::unicode).
    const slashFinal = pageUrlPreview?.dataset.slashFinal === '1' ? '/' : '';
    slugInput.addEventListener('input', () => {
        slugTouched = true;
        const safe = slugInput.value.trim().replace(/^\/+|\/+$/g, '');
        pageUrlPreview.textContent = safe !== '' ? '/' + safe + slashFinal : '/';
    });

    const slugify = (value) => value
        .normalize('NFC')
        .toLowerCase()
        .replace(/[\s_-]+/gu, '-')
        .replace(/[^\p{L}\p{M}\p{N}-]/gu, '')
        .replace(/-{2,}/g, '-')
        .replace(/^-+|-+$/g, '');

    titleInput.addEventListener('input', () => {
        if (!slugTouched) {
            const generated = slugify(titleInput.value);
            slugInput.value = generated;
            pageUrlPreview.textContent = generated !== '' ? '/' + generated + slashFinal : '/';
        }
    });
<?php else: ?>
    slugInput.addEventListener('input', () => {
        slugTouched = true;
        const safe = slugInput.value.trim();
        pageUrlPreview.textContent = safe !== '' ? '/' + safe : '/';
    });

    const slugify = (value) => value
        .toLowerCase()
        .replace(/[^a-z0-9\\s-]/g, '')
        .trim()
        .replace(/[\\s-]+/g, '-');

    titleInput.addEventListener('input', () => {
        if (!slugTouched) {
            const generated = slugify(titleInput.value);
            slugInput.value = generated;
            pageUrlPreview.textContent = generated !== '' ? '/' + generated : '/';
        }
    });
<?php endif; ?>

    const encodeBase64Utf8 = (value) => {
        try {
            if (typeof TextEncoder !== 'undefined') {
                const bytes = new TextEncoder().encode(String(value ?? ''));
                let binary = '';
                bytes.forEach((byte) => {
                    binary += String.fromCharCode(byte);
                });
                return btoa(binary);
            }
        } catch (_) {
            // Fallback below.
        }
        return btoa(unescape(encodeURIComponent(String(value ?? ''))));
    };

    const addHidden = (form, name, value) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    };

    document.getElementById('page-editor-form')?.addEventListener('submit', (event) => {
        const form = event.currentTarget;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        Object.values(editors).forEach((editor) => editor?.save?.());

        addHidden(form, 'html_content_b64', encodeBase64Utf8(getEditorValue('html')));
<?php if (!$paginaCapturata): ?>
        addHidden(form, 'css_content_b64', encodeBase64Utf8(getEditorValue('css')));
        addHidden(form, 'js_content_b64', encodeBase64Utf8(getEditorValue('js')));
<?php endif; ?>

        // Avoid sending raw HTML/CSS/JS fields that can trigger strict WAF rules.
        [htmlCode, cssCode, jsCode].forEach((textarea) => {
            if (textarea instanceof HTMLTextAreaElement) {
                textarea.disabled = true;
            }
        });
    });
})();
</script>
<?= \App\Support\Dev::marcaj('bv:previzualizare-produse-sablon') ?>
<?php
/*
 * WP-19 (C14e, GR-17): în previzualizare, {{products_by_template:…}} și {{produse_serviciu}} devin un chenar cu setările
 * cerute (secțiunile cu parametri nu se pot pregăti dinainte). Un script separat: după fiecare încărcare a previzualizării
 * înlocuiește doar nodurile de text cu aceste coduri.
 */
?>
<script>
(() => {
    const iframe = document.getElementById('page-preview');
    const chenarSablon = <?= json_encode((string) ($produseDupaSablonPreviewHtml ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const chenarServiciu = <?= json_encode((string) ($produseServiciuPreviewHtml ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    if (!(iframe instanceof HTMLIFrameElement) || (chenarSablon === '' && chenarServiciu === '')) {
        return;
    }
    const escapa = (text) => String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]));
    const tipar = /\{\{\s*(?:products_by_template\s*:\s*([^}]*)|produse_serviciu\s*)\}\}/gi;
    // Cadrul e izolat (N1a): nodurile de text cu un cod le dă puntea din previzualizare, prin mesaje; HTML-ul lor pleacă înapoi.
    let cerere = 0;
    iframe.addEventListener('load', () => {
        cerere++;
        iframe.contentWindow?.postMessage({ ax: 'cere-noduri', id: 'bv:' + cerere, test: '\\{\\{\\s*(products_by_template|produse_serviciu)' }, '*');
    });
    window.addEventListener('message', (e) => {
        const d = e.data;
        if (e.source !== iframe.contentWindow || e.origin !== 'null' || !d || d.ax !== 'noduri' || d.id !== 'bv:' + cerere
            || !Array.isArray(d.texte)) {
            return;
        }
        const html = d.texte.map((text) => escapa(String(text)).replace(tipar, (_, argumente) => (argumente !== undefined
            ? (chenarSablon !== '' ? chenarSablon.replace('%s', escapa(argumente)) : '')
            : chenarServiciu)));
        iframe.contentWindow?.postMessage({ ax: 'inlocuieste-noduri', id: d.id, html }, '*');
    });
})();
</script>
<?= \App\Support\Dev::marcaj('bv:previzualizare-produse-sablon', true) ?>
<?= \App\Support\Dev::marcaj('vt:previzualizare-evenimente') ?>
<?php
/*
 * WP-24 (VT-C4): în previzualizare, {{category_event_rows:…}} și {{category_grid_paged:…}} devin un chenar cu categoriile
 * cerute (ca mai sus: un script separat, doar nodurile de text cu aceste coduri).
 */
?>
<script>
(() => {
    const iframe = document.getElementById('page-preview');
    const chenarRanduri = <?= json_encode((string) ($evenimenteRanduriPreviewHtml ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const chenarGrila = <?= json_encode((string) ($grilaPaginataPreviewHtml ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    if (!(iframe instanceof HTMLIFrameElement) || (chenarRanduri === '' && chenarGrila === '')) {
        return;
    }
    const escapa = (text) => String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]));
    const tipar = /\{\{\s*(category_event_rows|category_grid_paged)\s*:\s*([^}]*)\}\}/gi;
    // Cadrul e izolat (N1a): nodurile de text cu un cod le dă puntea din previzualizare, prin mesaje; HTML-ul lor pleacă înapoi.
    let cerere = 0;
    iframe.addEventListener('load', () => {
        cerere++;
        iframe.contentWindow?.postMessage({ ax: 'cere-noduri', id: 'vt:' + cerere, test: '\\{\\{\\s*(category_event_rows|category_grid_paged)' }, '*');
    });
    window.addEventListener('message', (e) => {
        const d = e.data;
        if (e.source !== iframe.contentWindow || e.origin !== 'null' || !d || d.ax !== 'noduri' || d.id !== 'vt:' + cerere
            || !Array.isArray(d.texte)) {
            return;
        }
        const html = d.texte.map((text) => escapa(String(text)).replace(tipar, (_, cod, argumente) => {
            const chenar = cod.toLowerCase() === 'category_event_rows' ? chenarRanduri : chenarGrila;
            return chenar !== '' ? chenar.replace('%s', escapa(argumente)) : '';
        }));
        iframe.contentWindow?.postMessage({ ax: 'inlocuieste-noduri', id: d.id, html }, '*');
    });
})();
</script>
<?= \App\Support\Dev::marcaj('vt:previzualizare-evenimente', true) ?>
