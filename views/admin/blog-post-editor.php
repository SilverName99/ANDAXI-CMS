<?php
$editingPost = is_array($editingPost ?? null) ? $editingPost : null;
$templates = is_array($templates ?? null) ? $templates : [];
$categories = is_array($categories ?? null) ? $categories : [];
$authors = is_array($authors ?? null) ? $authors : [];
$galleryImages = is_array($galleryImages ?? null) ? $galleryImages : [];
$tagSuggestions = is_array($tagSuggestions ?? null) ? $tagSuggestions : [];
$fieldMap = is_array($fieldMap ?? null) ? $fieldMap : ['0' => []];
$selectedTemplateId = (int) ($selectedTemplateId ?? 0);
$selectedCategoryId = (int) ($selectedCategoryId ?? 0);
// Categoria venită doar ca text la import, fără o categorie din listă cu același nume (WP-12, X1b).
$categorieDoarText = trim((string) ($categorieDoarText ?? ''));
$editingPostSeo = is_array($editingPostSeo ?? null) ? $editingPostSeo : [
    'title' => '', 'description' => '', 'canonical_url' => '', 'image_url' => '',
];
$postId = (int) ($editingPost['id'] ?? 0);
$activeFields = $fieldMap[(string) $selectedTemplateId] ?? ($fieldMap['0'] ?? []);
// Referințele și etichetele se văd la orice template: chiar dacă template-ul
// n-are token-ul lor, site-ul le pune oricum la sfârșitul articolului.
$alwaysVisible = ['title', 'content', 'references', 'tags'];
$showField = static function (string $key) use ($activeFields, $alwaysVisible): bool {
    return in_array($key, $alwaysVisible, true) || in_array($key, $activeFields, true);
};
$featuredPreview = trim((string) ($editingPost['featured_image_url'] ?? '')) ?: '/assets/img/product-placeholder.svg';
$featuredWidth = (int) ($editingPost['featured_image_width'] ?? 100);
$featuredWidth = max(10, min(100, $featuredWidth > 0 ? $featuredWidth : 100));
$seoImagePreview = trim((string) (($editingPostSeo['image_url'] ?? '') ?: '')) ?: '/assets/img/product-placeholder.svg';
$publishedAt = trim((string) ($editingPost['published_at'] ?? ''));
if ($publishedAt === '') {
    $publishedAt = date('Y-m-d H:i:s');
}
$publishedAtInput = date('Y-m-d\TH:i', strtotime($publishedAt) ?: time());
$returnParam = $postId > 0 ? (string) $postId : 'new';
?>
<section class="panel">
    <div class="section-head">
        <div>
            <h1><?= $postId > 0 ? 'Editează postare' : 'Postare nouă — Pasul 2' ?></h1>
            <p>Completează câmpurile cerute de template. Schimbând template-ul, câmpurile se actualizează.</p>
        </div>
        <a class="btn btn-secondary" href="/admin/blog/posts">&larr; Înapoi la listă</a>
    </div>

    <form method="post" action="/admin/blog/posts" class="form-grid" id="blog-post-form">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="timezone_offset_minutes" id="blog-post-timezone-offset" value="">
        <?php if ($postId > 0): ?>
            <input type="hidden" name="id" value="<?= $postId ?>">
        <?php endif; ?>

        <div class="field">
            <label>Template (layout)</label>
            <select name="template_id" id="blog-post-template-select">
                <option value="0" <?= $selectedTemplateId === 0 ? 'selected' : '' ?>>Template implicit (articol standard)</option>
                <?php foreach ($templates as $template): ?>
                    <?php $tid = (int) ($template['id'] ?? 0); ?>
                    <option value="<?= $tid ?>" <?= $tid === $selectedTemplateId ? 'selected' : '' ?>><?= htmlspecialchars((string) ($template['name'] ?? ('Template #' . $tid)), ENT_QUOTES) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Categorii (zone) — poți alege oricâte</label>
            <?php $selectedCategoryIds = is_array($selectedCategoryIds ?? null) ? array_map('intval', $selectedCategoryIds) : []; ?>
            <div class="blog-cat-checks" id="blog-post-category-checks">
                <?php if ($categories === []): ?>
                    <small class="muted">Nu există categorii. Creează în <a href="/admin/blog/categories">Blog → Categorii</a>.</small>
                <?php else: ?>
                    <?php foreach ($categories as $category): ?>
                        <?php $cid = (int) ($category['id'] ?? 0); ?>
                        <label class="blog-cat-check">
                            <input type="checkbox" name="category_ids[]" value="<?= $cid ?>" <?= in_array($cid, $selectedCategoryIds, true) ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars((string) ($category['name'] ?? ''), ENT_QUOTES) ?></span>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
<?php if ($categorieDoarText !== ''): ?>
            <small class="muted" data-categorie-doar-text>Categoria postării, „<?= htmlspecialchars($categorieDoarText, ENT_QUOTES) ?>”, a venit doar ca text (de exemplu de la un import) și nu e în lista de mai sus: rămâne la salvare. Bifează o categorie dacă vrei s-o schimbi.</small>
            <input type="hidden" name="category" value="<?= htmlspecialchars($categorieDoarText, ENT_QUOTES) ?>">
<?php endif; ?>
        </div>

        <div class="field" data-field="title">
            <label>Titlu *</label>
            <input type="text" name="title" required value="<?= htmlspecialchars((string) ($editingPost['title'] ?? ''), ENT_QUOTES) ?>">
        </div>

        <div class="field" data-field="author" style="<?= $showField('author') ? '' : 'display:none;' ?>">
            <label>Autor</label>
            <select name="author_id">
                <option value="">Fără autor</option>
                <?php foreach ($authors as $author): ?>
                    <?php $aid = (int) ($author['id'] ?? 0); ?>
                    <option value="<?= $aid ?>" <?= $aid === (int) ($editingPost['author_id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($author['name'] ?? ('Autor #' . $aid)), ENT_QUOTES) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field" data-field="reading_minutes" style="<?= $showField('reading_minutes') ? '' : 'display:none;' ?>">
            <label>Timp citire (minute)</label>
            <input type="number" name="reading_minutes" min="1" max="300" value="<?= (int) ($editingPost['reading_minutes'] ?? 5) ?>">
        </div>

        <div class="field" data-field="featured_image" style="grid-column:1/-1;<?= $showField('featured_image') ? '' : 'display:none;' ?>">
            <label>Imagine principală</label>
            <input type="hidden" name="featured_image_url" id="blog-post-featured-image-url" value="<?= htmlspecialchars((string) ($editingPost['featured_image_url'] ?? ''), ENT_QUOTES) ?>">
            <div class="product-image-picker-inline">
                <img id="blog-post-featured-image-preview" src="<?= htmlspecialchars($featuredPreview, ENT_QUOTES) ?>" alt="Preview" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                <div>
                    <button class="btn btn-secondary" type="button" id="open-blog-featured-image-picker">Selectează imagine</button>
                    <p id="blog-post-featured-image-label" class="muted" style="margin:8px 0 0;font-size:12px;"><?= htmlspecialchars($featuredPreview, ENT_QUOTES) ?></p>

                    <div class="blog-featured-width">
                        <label for="blog-post-featured-width">Cât de lată se vede pe site</label>
                        <div class="blog-featured-width-row">
                            <input type="range" id="blog-post-featured-width-range" min="10" max="100" step="5" value="<?= $featuredWidth ?>" aria-label="Lățimea imaginii principale">
                            <input type="number" name="featured_image_width" id="blog-post-featured-width" min="10" max="100" step="5" value="<?= $featuredWidth ?>">
                            <span class="blog-featured-width-unit">%</span>
                        </div>
                        <!-- Rigla arată cât din lățimea articolului ocupă imaginea: un
                             procent singur nu-ți spune mare lucru până nu-l vezi. -->
                        <div class="blog-featured-ruler" aria-hidden="true"><span id="blog-post-featured-bar" style="width:<?= $featuredWidth ?>%;"></span></div>
                        <small class="muted">100% = cât toată lățimea articolului. Mai puțin o așază la mijloc, mai mică.</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="field" data-field="excerpt" style="grid-column:1/-1;<?= $showField('excerpt') ? '' : 'display:none;' ?>">
            <label>Excerpt (rezumat scurt)</label>
            <textarea name="excerpt" rows="3"><?= htmlspecialchars((string) ($editingPost['excerpt'] ?? ''), ENT_QUOTES) ?></textarea>
        </div>

        <div class="field" data-field="video" style="grid-column:1/-1;<?= $showField('video') ? '' : 'display:none;' ?>">
            <label>Video</label>
            <input type="text" name="video_url" placeholder="https://www.youtube.com/watch?v=..." value="<?= htmlspecialchars((string) ($editingPost['video_url'] ?? ''), ENT_QUOTES) ?>">
            <small class="muted">
                Lipește linkul de pe YouTube sau Vimeo, exact cum îl copiezi din bara browserului.
                Merge și un fișier urcat în Galerie (<code>/uploads/gallery/nume.mp4</code>).
            </small>
        </div>

        <div class="field" data-field="event_dates" style="grid-column:1/-1;<?= $showField('event_dates') ? '' : 'display:none;' ?>">
            <label>Perioadă eveniment</label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <input type="date" name="event_start_date" value="<?= htmlspecialchars((string) ($editingPost['event_start_date'] ?? ''), ENT_QUOTES) ?>" aria-label="Data început">
                <input type="date" name="event_end_date" value="<?= htmlspecialchars((string) ($editingPost['event_end_date'] ?? ''), ENT_QUOTES) ?>" aria-label="Data sfârșit">
            </div>
        </div>

        <div class="field" data-field="event_price" style="<?= $showField('event_price') ? '' : 'display:none;' ?>">
            <label>Preț eveniment</label>
            <input type="text" name="event_price" placeholder="ex: 350 RON sau Gratuit" value="<?= htmlspecialchars((string) ($editingPost['event_price'] ?? ''), ENT_QUOTES) ?>">
        </div>

        <div class="field" data-field="event_location" style="<?= $showField('event_location') ? '' : 'display:none;' ?>">
            <label>Locație eveniment</label>
            <input type="text" name="event_location" placeholder="ex: București, Hotel X" value="<?= htmlspecialchars((string) ($editingPost['event_location'] ?? ''), ENT_QUOTES) ?>">
        </div>

        <div class="field" data-field="event_ticket_url" style="grid-column:1/-1;<?= $showField('event_ticket_url') ? '' : 'display:none;' ?>">
            <label>Link bilete / înscriere (site extern)</label>
            <input type="url" name="event_ticket_url" placeholder="https://..." value="<?= htmlspecialchars((string) ($editingPost['event_ticket_url'] ?? ''), ENT_QUOTES) ?>">
        </div>

        <div class="field" data-field="content" style="grid-column:1/-1;">
            <label>Conținut *</label>
            <div class="page-toolbar" style="margin:6px 0 8px;gap:6px;flex-wrap:wrap;">
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="formatBlock" data-value="H2">H2</button>
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="formatBlock" data-value="H3">H3</button>
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="formatBlock" data-value="H4">H4</button>
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="formatBlock" data-value="H5">H5</button>
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="formatBlock" data-value="H6">H6</button>
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="formatBlock" data-value="P">Text normal</button>
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="bold">B</button>
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="italic"><em>I</em></button>
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="insertUnorderedList">• Listă</button>
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="insertOrderedList">1. Listă</button>
                <span class="blog-toolbar-sep" aria-hidden="true"></span>
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="justifyLeft" title="Aliniat la stânga">⇤</button>
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="justifyCenter" title="Centrat">↔</button>
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="justifyRight" title="Aliniat la dreapta">⇥</button>
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="justifyFull" title="Aliniat pe ambele margini">≡</button>
                <span class="blog-toolbar-sep" aria-hidden="true"></span>
                <button type="button" class="btn btn-secondary" id="blog-editor-link-btn" title="Ctrl+K">Link <kbd class="blog-kbd">Ctrl K</kbd></button>
                <button type="button" class="btn btn-secondary blog-editor-cmd" data-cmd="unlink">Unlink</button>
                <button type="button" class="btn btn-secondary" id="blog-editor-image-btn">🖼 Imagine din galerie</button>
            </div>
            <div class="blog-editor-shell">
                <div id="blog-post-content-editor" contenteditable="true" class="blog-rich-editor" data-placeholder="Scrie conținutul articolului..."></div>
                <!-- Chenarul apare peste imaginea aleasă. Browserele nu mai dau
                     mânere de redimensionare într-un `contenteditable`, așa că
                     ni-l desenăm singuri. -->
                <div class="blog-img-tool" id="blog-img-tool" hidden>
                    <div class="blog-img-tool-bar">
                        <span class="blog-img-tool-size" id="blog-img-tool-size">100%</span>
                        <button type="button" data-img-width="25">25%</button>
                        <button type="button" data-img-width="50">50%</button>
                        <button type="button" data-img-width="75">75%</button>
                        <button type="button" data-img-width="100">100%</button>
                        <span class="blog-img-tool-sep"></span>
                        <button type="button" data-img-align="left" title="Aliniat la stânga">⇤</button>
                        <button type="button" data-img-align="center" title="La mijloc">↔</button>
                        <button type="button" data-img-align="right" title="Aliniat la dreapta">⇥</button>
                        <span class="blog-img-tool-sep"></span>
                        <!-- Textul alternativ: ce citește Google și ce aude
                             cineva care folosește un cititor de ecran. -->
                        <button type="button" id="blog-img-tool-alt" title="Text alternativ (pentru SEO și cititoare de ecran)">Alt</button>
                    </div>
                    <span class="blog-img-tool-handle" id="blog-img-tool-handle" title="Trage de colț ca s-o faci mai mică"></span>
                </div>
            </div>
            <small class="muted" style="display:block;margin-top:6px;">
                Dă clic pe o imagine din text ca s-o poți micșora — trage de colțul din dreapta-jos
                sau alege un procent. Lățimea se ține în procente, deci imaginea rămâne potrivită și pe telefon.
            </small>
            <textarea name="content" id="blog-post-content-input" rows="12" style="display:none;"><?= htmlspecialchars((string) ($editingPost['content'] ?? ''), ENT_QUOTES) ?></textarea>
        </div>

        <!-- Bibliografia stă separat de text. În articol se scriu doar
             trimiterile „[1]", „[2]"; site-ul le leagă singur de referința
             potrivită de aici. -->
        <div class="field" data-field="references" style="grid-column:1/-1;">
            <label>Referințe (bibliografie)</label>
            <div class="page-toolbar" style="margin:6px 0 8px;gap:6px;flex-wrap:wrap;">
                <button type="button" class="btn btn-secondary blog-refs-cmd" data-cmd="bold">B</button>
                <button type="button" class="btn btn-secondary blog-refs-cmd" data-cmd="italic"><em>I</em></button>
                <button type="button" class="btn btn-secondary" id="blog-refs-link-btn" title="Ctrl+K">Link <kbd class="blog-kbd">Ctrl K</kbd></button>
                <button type="button" class="btn btn-secondary blog-refs-cmd" data-cmd="unlink">Unlink</button>
                <button type="button" class="btn btn-secondary" id="blog-refs-add">+ Referință nouă</button>
                <button type="button" class="btn btn-secondary" id="blog-refs-renumber" title="Renumerotează referințele de la 1 în jos">↕ Renumerotează</button>
            </div>
            <div id="blog-post-references-editor" contenteditable="true" class="blog-rich-editor blog-rich-editor--refs"
                 data-placeholder="[1] Autor, A. (2024). Titlul studiului. Revista, 12(3), 45–67. https://doi.org/..."></div>
            <textarea name="references_html" id="blog-post-references-input" rows="6" style="display:none;"><?= htmlspecialchars((string) ($editingPost['references_html'] ?? ''), ENT_QUOTES) ?></textarea>
            <small class="muted" style="display:block;margin-top:6px;">
                Fiecare referință pe rândul ei, începând cu <code>[1]</code>, <code>[2]</code> …
                În articol, trimiterile <code>[1]</code> devin linkuri către referința potrivită,
                iar referința are o săgeată de întoarcere la locul din text.
                Cu <kbd>Ctrl</kbd>+<kbd>K</kbd> în conținut poți insera direct o trimitere.
            </small>
        </div>

        <div class="field" data-field="tags" style="grid-column:1/-1;">
            <label for="blog-post-tags">Etichete</label>
            <input type="text" name="tags" id="blog-post-tags" list="blog-tag-suggestions" autocomplete="off"
                   placeholder="ayurveda, plante medicinale, sănătate naturală"
                   value="<?= htmlspecialchars((string) ($editingPost['tags_text'] ?? ''), ENT_QUOTES) ?>">
            <datalist id="blog-tag-suggestions">
                <?php foreach ($tagSuggestions as $tagSuggestion): ?>
                    <option value="<?= htmlspecialchars((string) $tagSuggestion, ENT_QUOTES) ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <div class="blog-tag-chips" id="blog-post-tag-chips" aria-live="polite"></div>
            <?php if ($tagSuggestions !== []): ?>
                <details style="margin-top:8px;">
                    <summary style="cursor:pointer;font-size:12px;color:#64748b;">Etichete folosite până acum (<?= count($tagSuggestions) ?>)</summary>
                    <div class="blog-tag-chips blog-tag-chips--pick" style="margin-top:8px;">
                        <?php foreach ($tagSuggestions as $tagSuggestion): ?>
                            <button type="button" class="blog-tag-chip blog-tag-chip--add" data-tag-add="<?= htmlspecialchars((string) $tagSuggestion, ENT_QUOTES) ?>">
                                + <?= htmlspecialchars((string) $tagSuggestion, ENT_QUOTES) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
            <small class="muted" style="display:block;margin-top:6px;">
                Despărțite prin virgulă. Cele care nu există încă se creează singure la salvare.
            </small>
        </div>

        <details class="panel" style="grid-column:1/-1;margin:0;">
            <summary style="cursor:pointer;font-weight:700;">Setări postare <span style="color:#64748b;font-weight:400;font-size:.9em;">(slug, status, dată, SEO)</span></summary>
            <div class="form-grid" style="margin-top:12px;">
                <div class="field">
                    <label>Slug (opțional)</label>
                    <input type="text" name="slug" value="<?= htmlspecialchars((string) ($editingPost['slug'] ?? ''), ENT_QUOTES) ?>" placeholder="slug-postare">
                </div>
                <div class="field">
                    <label>Data publicării</label>
                    <input type="datetime-local" name="published_at" value="<?= htmlspecialchars($publishedAtInput, ENT_QUOTES) ?>">
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="is_published" <?= ((int) ($editingPost['is_published'] ?? 1) === 1) ? 'checked' : '' ?>>
                        Publicat
                    </label>
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label>Meta title</label>
                    <input type="text" name="seo_title" value="<?= htmlspecialchars((string) (($editingPostSeo['title'] ?? '') ?: ''), ENT_QUOTES) ?>">
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label>Meta description</label>
                    <textarea name="seo_description" rows="3"><?= htmlspecialchars((string) (($editingPostSeo['description'] ?? '') ?: ''), ENT_QUOTES) ?></textarea>
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label>Canonical URL (opțional)</label>
                    <input type="url" name="seo_canonical_url" value="<?= htmlspecialchars((string) (($editingPostSeo['canonical_url'] ?? '') ?: ''), ENT_QUOTES) ?>">
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label>Imagine social preview (OG/Twitter)</label>
                    <input type="hidden" name="seo_image_url" id="blog-post-seo-image-url" value="<?= htmlspecialchars((string) (($editingPostSeo['image_url'] ?? '') ?: ''), ENT_QUOTES) ?>">
                    <div class="product-image-picker-inline">
                        <img id="blog-post-seo-image-preview" src="<?= htmlspecialchars($seoImagePreview, ENT_QUOTES) ?>" alt="Preview SEO" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                        <div>
                            <button class="btn btn-secondary" type="button" id="open-blog-seo-image-picker">Selectează imagine</button>
                            <p id="blog-post-seo-image-label" class="muted" style="margin:8px 0 0;font-size:12px;"><?= htmlspecialchars($seoImagePreview, ENT_QUOTES) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </details>

        <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:8px;">
            <a class="btn btn-secondary" href="/admin/blog/posts">Anulează</a>
            <button class="btn btn-secondary" type="button" id="blog-preview-btn"
                    title="Vezi articolul cum va arăta pe site, fără să-l salvezi">👁 Previzualizare</button>
            <!-- Butonul de draft nu se atinge de bifa „Publicat" din setări: o
                 forțează pe nepublicat pentru salvarea asta, atât. -->
            <button class="btn btn-secondary" type="submit" name="save_as_draft" value="1"
                    title="Salvează fără s-o publice pe site">Salvează ca draft</button>
            <button class="btn" type="submit">Salvează postarea</button>
        </div>
    </form>
</section>

<!-- Previzualizarea: articolul randat cu template-ul lui, la lățime de desktop
     sau de telefon, plus cum arată în rezultatele Google. -->
<div class="modal-overlay" id="blog-preview-modal">
    <div class="modal-card blog-preview-card">
        <div class="modal-head">
            <h3>Previzualizare articol</h3>
            <div class="blog-preview-switch" role="group" aria-label="Lățime previzualizare">
                <button type="button" class="is-active" data-preview-width="1280">🖥 Desktop</button>
                <button type="button" data-preview-width="390">📱 Telefon</button>
            </div>
            <button type="button" class="icon-btn" id="blog-preview-close" aria-label="Închide">✕</button>
        </div>

        <!-- Cardul Google: exact ce vede omul în rezultatele căutării, cu
             avertizare când titlul sau descrierea se taie. -->
        <div class="blog-serp">
            <div class="blog-serp__label">Cum arată în Google</div>
            <div class="blog-serp__card">
                <div class="blog-serp__url" id="blog-serp-url"><?= htmlspecialchars(\App\Support\Brand::gazda(), ENT_QUOTES) ?> › blog › articol</div>
                <div class="blog-serp__title" id="blog-serp-title">Titlul articolului</div>
                <div class="blog-serp__desc" id="blog-serp-desc">Descrierea care apare sub titlu în rezultatele căutării.</div>
            </div>
            <div class="blog-serp__counters">
                <span id="blog-serp-title-count">Titlu: 0/60</span>
                <span id="blog-serp-desc-count">Descriere: 0/155</span>
            </div>
            <small class="muted">
                Titlul și descrierea se iau din „Setări postare → Meta title / Meta description”.
                Lăsate goale, Google folosește titlul articolului și rezumatul.
            </small>
        </div>

        <div class="blog-preview-stage">
            <div class="blog-preview-frame" id="blog-preview-frame">
                <p class="blog-preview-loading" id="blog-preview-loading">Se pregătește previzualizarea…</p>
                <iframe id="blog-preview-iframe" title="Previzualizare articol" hidden></iframe>
            </div>
        </div>
    </div>
</div>

<!-- Dialogul de link (Ctrl+K). Are două file: una pentru linkuri obișnuite,
     alta pentru trimiterile către bibliografie, ca să nu mai fie nevoie să
     ținem minte ce număr are fiecare referință. -->
<div class="modal-overlay" id="blog-link-modal">
    <div class="modal-card" style="max-width:560px;">
        <div class="modal-head">
            <h3 id="blog-link-title">Inserează link</h3>
            <button type="button" class="icon-btn" id="blog-link-close" aria-label="Închide">✕</button>
        </div>
        <div class="blog-link-tabs" role="tablist">
            <button type="button" class="blog-link-tab is-active" data-link-tab="url" role="tab" aria-selected="true">Link web</button>
            <button type="button" class="blog-link-tab" data-link-tab="ref" role="tab" aria-selected="false">Trimitere la referință</button>
        </div>

        <div data-link-pane="url">
            <div class="field">
                <label for="blog-link-text">Text afișat</label>
                <input type="text" id="blog-link-text" placeholder="Textul pe care se dă clic">
            </div>
            <div class="field">
                <label for="blog-link-url">Adresă</label>
                <input type="text" id="blog-link-url" placeholder="https://..." autocomplete="off">
            </div>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;">
                <input type="checkbox" id="blog-link-blank" checked> Deschide într-o filă nouă
            </label>
        </div>

        <div data-link-pane="ref" hidden>
            <div class="field">
                <input type="text" id="blog-link-ref-search" placeholder="Caută printre referințe (autor, titlu, an)..." autocomplete="off">
            </div>
            <div class="blog-link-ref-list" id="blog-link-ref-list"></div>
            <small class="muted" id="blog-link-ref-empty" hidden>
                Nu ai încă referințe. Scrie-le în câmpul „Referințe”, sub conținut, apoi revino aici.
            </small>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">
            <button type="button" class="btn btn-secondary" id="blog-link-cancel">Renunță</button>
            <button type="button" class="btn" id="blog-link-apply">Inserează</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="blog-post-image-modal">
    <div class="modal-card" style="max-width:900px;">
        <div class="modal-head">
            <h3>Selectează imagine din Galerie</h3>
            <button type="button" class="icon-btn" id="close-blog-featured-image-picker" aria-label="Închide">✕</button>
        </div>
        <div class="field" style="margin-bottom:10px;">
            <input type="text" id="blog-post-image-search" placeholder="Caută imagine după titlu...">
        </div>
        <form method="post" action="/admin/blog/posts/image-upload" enctype="multipart/form-data" class="field" style="margin-bottom:10px;">
            <label style="margin-bottom:6px;">Încarcă imagine nouă</label>
            <div class="product-image-upload-inline">
                <input type="file" name="image_file" accept=".jpg,.jpeg,.png,.webp,.gif" required>
                <input type="hidden" name="redirect_url" value="/admin/blog/posts/editor?post=<?= htmlspecialchars($returnParam, ENT_QUOTES) ?>">
                <button type="submit" class="btn">Încarcă</button>
            </div>
            <small class="muted">Imaginea se adaugă în galerie și poate fi selectată imediat.</small>
        </form>
        <div class="product-picker-grid" id="blog-post-image-grid">
            <?php foreach ($galleryImages as $image): ?>
                <button
                    type="button"
                    class="product-picker-item blog-post-image-item"
                    data-image-url="<?= htmlspecialchars((string) ($image['image_url'] ?? ''), ENT_QUOTES) ?>"
                    data-search="<?= htmlspecialchars(strtolower((string) (($image['title'] ?? '') . ' ' . ($image['alt_text'] ?? '') . ' ' . ($image['image_url'] ?? ''))), ENT_QUOTES) ?>"
                >
                    <img loading="lazy" decoding="async" src="<?= htmlspecialchars((string) ($image['image_url'] ?? ''), ENT_QUOTES) ?>" alt="" onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                    <strong><?= htmlspecialchars((string) (($image['title'] ?? '') !== '' ? $image['title'] : 'Imagine fără titlu'), ENT_QUOTES) ?></strong>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
(() => {
    const fieldMap = <?= json_encode($fieldMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const alwaysVisible = <?= json_encode($alwaysVisible) ?>;
    const templateSelect = document.getElementById('blog-post-template-select');

    const applyFields = () => {
        const key = String((templateSelect && templateSelect.value !== '') ? templateSelect.value : '0');
        const active = Array.isArray(fieldMap[key]) ? fieldMap[key] : (fieldMap['0'] || []);
        document.querySelectorAll('[data-field]').forEach((el) => {
            const f = el.getAttribute('data-field') || '';
            const visible = alwaysVisible.includes(f) || active.includes(f);
            el.style.display = visible ? '' : 'none';
        });
    };
    if (templateSelect) {
        templateSelect.addEventListener('change', applyFields);
    }
    applyFields();

    // Image pickers
    const imageModal = document.getElementById('blog-post-image-modal');
    const imageItems = Array.from(document.querySelectorAll('.blog-post-image-item'));
    const imageSearchInput = document.getElementById('blog-post-image-search');
    const imageInput = document.getElementById('blog-post-featured-image-url');
    const imagePreview = document.getElementById('blog-post-featured-image-preview');
    const imageLabel = document.getElementById('blog-post-featured-image-label');
    const seoImageInput = document.getElementById('blog-post-seo-image-url');
    const seoImagePreview = document.getElementById('blog-post-seo-image-preview');
    const seoImageLabel = document.getElementById('blog-post-seo-image-label');
    let pickerMode = 'featured';
    const setFeatured = (url) => {
        const safe = (url || '').trim() || '/assets/img/product-placeholder.svg';
        if (imageInput) imageInput.value = safe === '/assets/img/product-placeholder.svg' ? '' : safe;
        if (imagePreview) imagePreview.src = safe;
        if (imageLabel) imageLabel.textContent = safe;
    };
    const setSeo = (url) => {
        const safe = (url || '').trim() || '/assets/img/product-placeholder.svg';
        if (seoImageInput) seoImageInput.value = safe === '/assets/img/product-placeholder.svg' ? '' : safe;
        if (seoImagePreview) seoImagePreview.src = safe;
        if (seoImageLabel) seoImageLabel.textContent = safe;
    };
    const openModal = () => imageModal && imageModal.classList.add('open');
    const closeModal = () => imageModal && imageModal.classList.remove('open');
    document.getElementById('open-blog-featured-image-picker')?.addEventListener('click', () => { pickerMode = 'featured'; openModal(); });
    document.getElementById('open-blog-seo-image-picker')?.addEventListener('click', () => { pickerMode = 'seo'; openModal(); });
    document.getElementById('close-blog-featured-image-picker')?.addEventListener('click', closeModal);
    imageModal?.addEventListener('click', (e) => { if (e.target === imageModal) closeModal(); });
    imageSearchInput?.addEventListener('input', () => {
        const q = (imageSearchInput.value || '').trim().toLowerCase();
        imageItems.forEach((item) => {
            const hay = item.getAttribute('data-search') || '';
            item.style.display = hay.includes(q) ? '' : 'none';
        });
    });
    imageItems.forEach((item) => {
        item.addEventListener('click', () => {
            const url = item.getAttribute('data-image-url') || '';
            if (pickerMode === 'content') {
                const alt = (item.querySelector('strong')?.textContent || item.getAttribute('data-title') || '').trim();
                insertImage(url, alt);
                closeModal();
                return;
            }
            pickerMode === 'seo' ? setSeo(url) : setFeatured(url);
            closeModal();
        });
    });

    // Rich content editor
    const editor = document.getElementById('blog-post-content-editor');
    const editorInput = document.getElementById('blog-post-content-input');
    const tzInput = document.getElementById('blog-post-timezone-offset');
    const form = document.getElementById('blog-post-form');
    if (tzInput instanceof HTMLInputElement) {
        tzInput.value = String(new Date().getTimezoneOffset());
    }
    if (editor instanceof HTMLElement && editorInput instanceof HTMLTextAreaElement) {
        editor.innerHTML = editorInput.value || '';
        editor.addEventListener('input', () => { editorInput.value = editor.innerHTML.trim(); });
    }
    const runCommand = (cmd, value = null) => {
        if (!(editor instanceof HTMLElement)) return;
        editor.focus();
        document.execCommand(cmd, false, value);
    };
    document.querySelectorAll('.blog-editor-cmd').forEach((button) => {
        button.addEventListener('click', () => {
            const cmd = button.getAttribute('data-cmd') || '';
            const value = button.getAttribute('data-value');
            if (cmd === 'formatBlock' && value) { runCommand(cmd, value); return; }
            if (cmd !== '') runCommand(cmd);
        });
    });
    // ── Referințe (bibliografia) ─────────────────────────────
    // Un al doilea editor, mai simplu: fiecare referință e un paragraf care
    // începe cu [1], [2] … Numerele de aici sunt cele pe care le caută
    // trimiterile din articol.
    const refsEditor = document.getElementById('blog-post-references-editor');
    const refsInput = document.getElementById('blog-post-references-input');
    const sincronizeazaReferintele = () => {
        if (refsEditor instanceof HTMLElement && refsInput instanceof HTMLTextAreaElement) {
            const gol = refsEditor.textContent.trim() === '' && !refsEditor.querySelector('img');
            refsInput.value = gol ? '' : refsEditor.innerHTML.trim();
        }
    };
    if (refsEditor instanceof HTMLElement && refsInput instanceof HTMLTextAreaElement) {
        refsEditor.innerHTML = refsInput.value || '';
        refsEditor.addEventListener('input', sincronizeazaReferintele);
    }

    /** Referințele scrise până acum: [{numar, text}], în ordinea din pagină. */
    const citesteReferintele = () => {
        if (!(refsEditor instanceof HTMLElement)) return [];
        const blocuri = refsEditor.querySelectorAll('p, li, div');
        const surse = blocuri.length > 0 ? Array.from(blocuri) : [refsEditor];
        const gasite = [];
        surse.forEach((bloc) => {
            const text = (bloc.textContent || '').trim();
            const m = text.match(/^\[\s*(\d{1,3})\s*\]\s*(.+)$/s);
            if (m) gasite.push({ numar: Number(m[1]), text: m[2].trim() });
        });
        return gasite;
    };

    document.querySelectorAll('.blog-refs-cmd').forEach((button) => {
        button.addEventListener('click', () => {
            const cmd = button.getAttribute('data-cmd') || '';
            if (cmd === '' || !(refsEditor instanceof HTMLElement)) return;
            refsEditor.focus();
            document.execCommand(cmd, false, null);
            sincronizeazaReferintele();
        });
    });

    document.getElementById('blog-refs-add')?.addEventListener('click', () => {
        if (!(refsEditor instanceof HTMLElement)) return;
        const urmatorul = citesteReferintele().reduce((max, r) => Math.max(max, r.numar), 0) + 1;
        const p = document.createElement('p');
        p.textContent = '[' + urmatorul + '] ';
        refsEditor.appendChild(p);
        sincronizeazaReferintele();
        // Cursorul rămâne la capătul rândului nou, gata de scris.
        const range = document.createRange();
        range.selectNodeContents(p);
        range.collapse(false);
        const sel = window.getSelection();
        sel?.removeAllRanges();
        sel?.addRange(range);
        refsEditor.focus();
    });

    // Renumerotarea atinge și trimiterile din articol, ca [3] din text să
    // ajungă tot la referința care s-a mutat pe poziția 3.
    document.getElementById('blog-refs-renumber')?.addEventListener('click', () => {
        if (!(refsEditor instanceof HTMLElement)) return;
        const blocuri = Array.from(refsEditor.querySelectorAll('p, li, div'))
            .filter((b) => /^\[\s*\d{1,3}\s*\]/.test((b.textContent || '').trim()));
        if (blocuri.length === 0) {
            window.alert('Nu am găsit referințe numerotate („[1] …”).');
            return;
        }
        const traducere = new Map();
        blocuri.forEach((bloc, i) => {
            const text = (bloc.textContent || '').trim();
            const vechi = Number((text.match(/^\[\s*(\d{1,3})\s*\]/) || [])[1] || 0);
            const nou = i + 1;
            if (vechi > 0 && vechi !== nou) traducere.set(vechi, nou);
            bloc.innerHTML = bloc.innerHTML.replace(/^(\s*(?:<[^>]+>\s*)*)\[\s*\d{1,3}\s*\]/, '$1[' + nou + ']');
        });
        if (traducere.size > 0 && editor instanceof HTMLElement) {
            // Într-un singur pas, altfel 1→2 și 2→3 s-ar suprascrie.
            editor.innerHTML = editor.innerHTML.replace(/\[(\d{1,3})\]/g, (tot, n) => {
                const nou = traducere.get(Number(n));
                return nou ? '[' + nou + ']' : tot;
            });
            if (editorInput instanceof HTMLTextAreaElement) editorInput.value = editor.innerHTML.trim();
        }
        sincronizeazaReferintele();
        window.alert('Gata: ' + blocuri.length + ' referințe renumerotate de la 1.'
            + (traducere.size > 0 ? ' Trimiterile din articol au fost actualizate.' : ''));
    });

    // ── Dialogul de link (Ctrl+K) ────────────────────────────
    const linkModal = document.getElementById('blog-link-modal');
    const linkText = document.getElementById('blog-link-text');
    const linkUrl = document.getElementById('blog-link-url');
    const linkBlank = document.getElementById('blog-link-blank');
    const refSearch = document.getElementById('blog-link-ref-search');
    const refList = document.getElementById('blog-link-ref-list');
    const refEmpty = document.getElementById('blog-link-ref-empty');
    let linkTinta = null;   // editorul în care inserăm
    let linkRange = null;   // selecția de dinaintea deschiderii dialogului
    let refAleasa = null;

    const arataFila = (fila) => {
        document.querySelectorAll('[data-link-tab]').forEach((b) => {
            const activ = b.getAttribute('data-link-tab') === fila;
            b.classList.toggle('is-active', activ);
            b.setAttribute('aria-selected', activ ? 'true' : 'false');
        });
        document.querySelectorAll('[data-link-pane]').forEach((p) => {
            p.hidden = p.getAttribute('data-link-pane') !== fila;
        });
    };

    const deseneazaReferintele = (filtru = '') => {
        if (!(refList instanceof HTMLElement)) return;
        const cautat = filtru.trim().toLowerCase();
        const toate = citesteReferintele();
        const potrivite = cautat === ''
            ? toate
            : toate.filter((r) => (r.numar + ' ' + r.text).toLowerCase().includes(cautat));
        if (refEmpty instanceof HTMLElement) refEmpty.hidden = toate.length > 0;
        refList.innerHTML = potrivite.map((r) => (
            '<button type="button" class="blog-link-ref" data-ref-nr="' + r.numar + '">'
            + '<span class="blog-link-ref__nr">[' + r.numar + ']</span>'
            + '<span class="blog-link-ref__text"></span></button>'
        )).join('');
        // Textul referinței intră prin textContent, nu prin HTML: e text scris
        // de om, poate conține <, & sau ghilimele.
        refList.querySelectorAll('.blog-link-ref').forEach((b, i) => {
            const el = b.querySelector('.blog-link-ref__text');
            if (el) el.textContent = potrivite[i].text;
            b.addEventListener('click', () => {
                refAleasa = Number(b.getAttribute('data-ref-nr'));
                refList.querySelectorAll('.blog-link-ref').forEach((x) => x.classList.remove('is-active'));
                b.classList.add('is-active');
            });
            b.addEventListener('dblclick', () => { refAleasa = Number(b.getAttribute('data-ref-nr')); aplicaLink(); });
        });
    };

    const deschideDialogulDeLink = (tinta) => {
        if (!(linkModal instanceof HTMLElement) || !(tinta instanceof HTMLElement)) return;
        linkTinta = tinta;
        refAleasa = null;

        const sel = window.getSelection();
        linkRange = (sel && sel.rangeCount > 0 && tinta.contains(sel.anchorNode)) ? sel.getRangeAt(0) : null;
        const selectat = linkRange ? linkRange.toString().trim() : '';

        // Dacă suntem chiar pe un link, dialogul îl editează în loc să facă altul.
        const ancora = linkRange && linkRange.startContainer.parentElement
            ? linkRange.startContainer.parentElement.closest('a')
            : null;
        if (linkText instanceof HTMLInputElement) linkText.value = selectat || (ancora ? ancora.textContent.trim() : '');
        if (linkUrl instanceof HTMLInputElement) linkUrl.value = ancora ? (ancora.getAttribute('href') || '') : '';
        if (linkBlank instanceof HTMLInputElement) {
            linkBlank.checked = ancora ? ancora.getAttribute('target') === '_blank' : true;
        }
        if (refSearch instanceof HTMLInputElement) refSearch.value = '';

        // Din bibliografie nu are sens să trimiți tot la bibliografie.
        const filaReferinte = document.querySelector('[data-link-tab="ref"]');
        const dinContinut = tinta === editor;
        if (filaReferinte instanceof HTMLElement) filaReferinte.hidden = !dinContinut;
        arataFila('url');
        if (dinContinut) deseneazaReferintele();

        linkModal.classList.add('open');
        window.setTimeout(() => {
            if (selectat !== '' && linkUrl instanceof HTMLInputElement) linkUrl.focus();
            else if (linkText instanceof HTMLInputElement) linkText.focus();
        }, 30);
    };

    const inchideDialogulDeLink = () => {
        linkModal?.classList.remove('open');
        linkTinta = null;
        linkRange = null;
        refAleasa = null;
    };

    /** Pune HTML la locul selecției salvate, în editorul din care s-a plecat. */
    const inserreazaInEditor = (html) => {
        if (!(linkTinta instanceof HTMLElement)) return;
        linkTinta.focus();
        const sel = window.getSelection();
        if (linkRange && sel) {
            sel.removeAllRanges();
            sel.addRange(linkRange);
        }
        document.execCommand('insertHTML', false, html);
        if (linkTinta === editor && editorInput instanceof HTMLTextAreaElement) {
            editorInput.value = editor.innerHTML.trim();
        }
        if (linkTinta === refsEditor) sincronizeazaReferintele();
    };

    const scapa = (v) => String(v ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    const aplicaLink = () => {
        const filaActiva = document.querySelector('.blog-link-tab.is-active')?.getAttribute('data-link-tab') || 'url';

        if (filaActiva === 'ref') {
            if (!refAleasa) { window.alert('Alege o referință din listă.'); return; }
            // Trimiterea rămâne text simplu „[3]"; site-ul o transformă în link
            // când randează articolul, ca renumerotarea să nu strice nimic.
            inserreazaInEditor('[' + refAleasa + ']');
            inchideDialogulDeLink();
            return;
        }

        let url = (linkUrl instanceof HTMLInputElement ? linkUrl.value : '').trim();
        if (url === '') { window.alert('Scrie adresa linkului.'); return; }
        // O adresă fără schemă („exemplu.ro") ar ajunge link relativ și ar
        // duce în gol. Ancorele și adresele interne rămân cum sunt.
        if (!/^(https?:|mailto:|tel:|\/|#)/i.test(url)) url = 'https://' + url;

        const text = (linkText instanceof HTMLInputElement ? linkText.value : '').trim()
            || (linkRange ? linkRange.toString().trim() : '')
            || url;
        const nouaFila = linkBlank instanceof HTMLInputElement && linkBlank.checked;
        const atribute = nouaFila ? ' target="_blank" rel="noopener noreferrer"' : '';

        inserreazaInEditor('<a href="' + scapa(url) + '"' + atribute + '>' + scapa(text) + '</a>');
        inchideDialogulDeLink();
    };

    document.querySelectorAll('[data-link-tab]').forEach((b) => {
        b.addEventListener('click', () => {
            const fila = b.getAttribute('data-link-tab') || 'url';
            arataFila(fila);
            if (fila === 'ref') {
                deseneazaReferintele(refSearch instanceof HTMLInputElement ? refSearch.value : '');
                if (refSearch instanceof HTMLInputElement) refSearch.focus();
            }
        });
    });
    refSearch?.addEventListener('input', () => deseneazaReferintele(refSearch.value));
    document.getElementById('blog-link-apply')?.addEventListener('click', aplicaLink);
    document.getElementById('blog-link-cancel')?.addEventListener('click', inchideDialogulDeLink);
    document.getElementById('blog-link-close')?.addEventListener('click', inchideDialogulDeLink);
    linkModal?.addEventListener('click', (e) => { if (e.target === linkModal) inchideDialogulDeLink(); });
    linkModal?.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { e.preventDefault(); inchideDialogulDeLink(); }
        if (e.key === 'Enter' && !(e.target instanceof HTMLTextAreaElement)) { e.preventDefault(); aplicaLink(); }
    });

    document.getElementById('blog-editor-link-btn')?.addEventListener('click', () => deschideDialogulDeLink(editor));
    document.getElementById('blog-refs-link-btn')?.addEventListener('click', () => deschideDialogulDeLink(refsEditor));

    // Ctrl+K / Cmd+K, din oricare dintre cele două editoare.
    [editor, refsEditor].forEach((zona) => {
        zona?.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
                e.preventDefault();
                deschideDialogulDeLink(zona);
            }
        });
    });

    // ── Previzualizare (desktop / telefon + Google) ──────────
    const previewModal = document.getElementById('blog-preview-modal');
    const previewFrame = document.getElementById('blog-preview-frame');
    const previewIframe = document.getElementById('blog-preview-iframe');
    const previewLoading = document.getElementById('blog-preview-loading');
    const siteBase = <?= json_encode(rtrim(\App\Support\AppUrl::base(), '/'), JSON_UNESCAPED_SLASHES) ?>;
    const siteName = <?= json_encode((string) ($siteName ?? ''), JSON_UNESCAPED_UNICODE) ?>;

    const scurteaza = (text, limita) => (text.length > limita ? text.slice(0, limita - 1).trimEnd() + '…' : text);

    const desenareaSerp = () => {
        const titluArticol = (form?.querySelector('[name="title"]')?.value || '').trim();
        const slug = (form?.querySelector('[name="slug"]')?.value || '').trim();
        const seoTitlu = (form?.querySelector('[name="seo_title"]')?.value || '').trim();
        const seoDesc = (form?.querySelector('[name="seo_description"]')?.value || '').trim();
        const rezumat = (form?.querySelector('[name="excerpt"]')?.value || '').trim();

        // Aceleași reguli ca în layout-ul site-ului: fără meta title, Google
        // vede „Titlu | Numele magazinului".
        const titluFinal = seoTitlu !== ''
            ? seoTitlu
            : (titluArticol !== '' ? titluArticol + ' | ' + siteName : siteName);
        const descriereFinala = seoDesc !== '' ? seoDesc : rezumat;

        const url = document.getElementById('blog-serp-url');
        const titlu = document.getElementById('blog-serp-title');
        const desc = document.getElementById('blog-serp-desc');
        const gazda = (siteBase || <?= json_encode('https://' . \App\Support\Brand::gazda(), JSON_UNESCAPED_SLASHES) ?>).replace(/^https?:\/\//, '').replace(/\/$/, '');
        if (url) url.textContent = gazda + ' › blog › ' + (slug || 'articol');
        if (titlu) titlu.textContent = scurteaza(titluFinal, 60);
        if (desc) {
            desc.textContent = descriereFinala !== ''
                ? scurteaza(descriereFinala, 155)
                : 'Fără meta description și fără rezumat, Google alege singur o bucată din articol.';
            desc.classList.toggle('is-empty', descriereFinala === '');
        }

        const arataContor = (elementId, eticheta, lungime, limita) => {
            const el = document.getElementById(elementId);
            if (!el) return;
            el.textContent = eticheta + ': ' + lungime + '/' + limita;
            el.classList.toggle('is-over', lungime > limita);
            el.classList.toggle('is-short', lungime > 0 && lungime < limita * 0.4);
        };
        arataContor('blog-serp-title-count', 'Titlu', titluFinal.length, 60);
        arataContor('blog-serp-desc-count', 'Descriere', descriereFinala.length, 155);
    };

    const setLatimePreview = (latime) => {
        if (!(previewFrame instanceof HTMLElement)) return;
        previewFrame.style.width = latime + 'px';
        previewFrame.classList.toggle('is-phone', latime <= 480);
        document.querySelectorAll('[data-preview-width]').forEach((b) => {
            b.classList.toggle('is-active', b.getAttribute('data-preview-width') === String(latime));
        });
    };
    document.querySelectorAll('[data-preview-width]').forEach((b) => {
        b.addEventListener('click', () => setLatimePreview(Number(b.getAttribute('data-preview-width')) || 1280));
    });

    const inchidePreview = () => previewModal?.classList.remove('open');
    document.getElementById('blog-preview-close')?.addEventListener('click', inchidePreview);
    previewModal?.addEventListener('click', (e) => { if (e.target === previewModal) inchidePreview(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && previewModal?.classList.contains('open')) inchidePreview();
    });

    document.getElementById('blog-preview-btn')?.addEventListener('click', async () => {
        if (!(form instanceof HTMLFormElement) || !(previewModal instanceof HTMLElement)) return;

        // Editoarele scriu în `textarea` doar la `input`; forțăm sincronizarea
        // ca previzualizarea să prindă și ultima literă scrisă.
        if (editor instanceof HTMLElement && editorInput instanceof HTMLTextAreaElement) {
            editorInput.value = editor.innerHTML.trim();
        }
        sincronizeazaReferintele();
        desenareaSerp();

        previewModal.classList.add('open');
        setLatimePreview(1280);
        if (previewIframe instanceof HTMLIFrameElement) previewIframe.hidden = true;
        if (previewLoading instanceof HTMLElement) {
            previewLoading.hidden = false;
            previewLoading.textContent = 'Se pregătește previzualizarea…';
        }

        try {
            const raspuns = await fetch('/admin/blog/posts/preview', {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
            });
            if (!raspuns.ok) throw new Error('HTTP ' + raspuns.status);
            const html = await raspuns.text();
            if (previewIframe instanceof HTMLIFrameElement) {
                previewIframe.srcdoc = html;
                previewIframe.hidden = false;
            }
            if (previewLoading instanceof HTMLElement) previewLoading.hidden = true;
        } catch (err) {
            if (previewLoading instanceof HTMLElement) {
                previewLoading.hidden = false;
                previewLoading.textContent = 'Nu am putut încărca previzualizarea. Încearcă din nou.';
            }
        }
    });

    // Cardul Google se reface pe măsură ce se scrie în câmpurile care-l compun.
    ['title', 'slug', 'excerpt', 'seo_title', 'seo_description'].forEach((camp) => {
        form?.querySelector('[name="' + camp + '"]')?.addEventListener('input', desenareaSerp);
    });
    desenareaSerp();

    // ── Etichete ─────────────────────────────────────────────
    const tagInput = document.getElementById('blog-post-tags');
    const tagChips = document.getElementById('blog-post-tag-chips');
    const listaEtichete = () => (tagInput instanceof HTMLInputElement ? tagInput.value : '')
        .split(',').map((t) => t.trim()).filter((t) => t !== '');
    const scrieEtichetele = (etichete) => {
        if (!(tagInput instanceof HTMLInputElement)) return;
        const unice = [];
        etichete.forEach((t) => {
            if (!unice.some((u) => u.toLowerCase() === t.toLowerCase())) unice.push(t);
        });
        tagInput.value = unice.join(', ');
        deseneazaEtichetele();
    };
    const deseneazaEtichetele = () => {
        if (!(tagChips instanceof HTMLElement)) return;
        const etichete = listaEtichete();
        tagChips.innerHTML = '';
        etichete.forEach((eticheta) => {
            const chip = document.createElement('span');
            chip.className = 'blog-tag-chip';
            chip.textContent = eticheta;
            const sterge = document.createElement('button');
            sterge.type = 'button';
            sterge.className = 'blog-tag-chip__x';
            sterge.setAttribute('aria-label', 'Șterge eticheta ' + eticheta);
            sterge.textContent = '×';
            sterge.addEventListener('click', () => {
                scrieEtichetele(listaEtichete().filter((t) => t !== eticheta));
            });
            chip.appendChild(sterge);
            tagChips.appendChild(chip);
        });
    };
    tagInput?.addEventListener('input', deseneazaEtichetele);
    tagInput?.addEventListener('keydown', (e) => {
        // Enter în câmpul de etichete adaugă eticheta, nu trimite formularul.
        if (e.key === 'Enter') {
            e.preventDefault();
            scrieEtichetele(listaEtichete());
        }
    });
    document.querySelectorAll('[data-tag-add]').forEach((b) => {
        b.addEventListener('click', () => {
            const eticheta = b.getAttribute('data-tag-add') || '';
            if (eticheta !== '') scrieEtichetele(listaEtichete().concat([eticheta]));
        });
    });
    deseneazaEtichetele();

    // Inserare imagine în conținut (alegere din Galerie + insert la cursor)
    const imageBtn = document.getElementById('blog-editor-image-btn');
    let savedRange = null;
    const saveRange = () => {
        const sel = window.getSelection();
        if (sel && sel.rangeCount > 0 && editor instanceof HTMLElement && editor.contains(sel.anchorNode)) {
            savedRange = sel.getRangeAt(0);
        }
    };
    if (editor instanceof HTMLElement) {
        ['keyup', 'mouseup', 'blur'].forEach((ev) => editor.addEventListener(ev, saveRange));
    }
    const insertImage = (url, alt) => {
        if (!(editor instanceof HTMLElement)) return;
        editor.focus();
        const sel = window.getSelection();
        if (savedRange && sel) {
            sel.removeAllRanges();
            sel.addRange(savedRange);
        }
        const img = document.createElement('img');
        img.src = url;
        img.alt = alt || '';
        img.style.maxWidth = '100%';
        img.style.height = 'auto';
        if (sel && sel.rangeCount > 0 && editor.contains(sel.anchorNode)) {
            const range = sel.getRangeAt(0);
            range.deleteContents();
            range.insertNode(img);
            // mută cursorul după imagine
            range.setStartAfter(img);
            range.collapse(true);
            sel.removeAllRanges();
            sel.addRange(range);
        } else {
            editor.appendChild(img);
        }
        editorInput.value = editor.innerHTML.trim();
    };
    imageBtn?.addEventListener('click', () => {
        saveRange();
        pickerMode = 'content';
        openModal();
    });
    // ── Lățimea imaginii principale ──────────────────────────
    // Glisorul și căsuța sunt aceeași valoare, scrisă în două feluri; rigla o arată.
    const latimeRange = document.getElementById('blog-post-featured-width-range');
    const latimeNumar = document.getElementById('blog-post-featured-width');
    const latimeBara = document.getElementById('blog-post-featured-bar');
    const araLatimea = (valoare, sursa) => {
        const p = Math.min(100, Math.max(10, Math.round(Number(valoare) || 100)));
        if (latimeRange instanceof HTMLInputElement && sursa !== 'range') latimeRange.value = String(p);
        if (latimeNumar instanceof HTMLInputElement && sursa !== 'numar') latimeNumar.value = String(p);
        if (latimeBara instanceof HTMLElement) latimeBara.style.width = p + '%';
    };
    latimeRange?.addEventListener('input', () => araLatimea(latimeRange.value, 'range'));
    latimeNumar?.addEventListener('input', () => araLatimea(latimeNumar.value, 'numar'));
    // La ieșirea din căsuță readucem valoarea în limite, ca „5" scris de mână
    // să nu plece spre server.
    latimeNumar?.addEventListener('change', () => araLatimea(latimeNumar.value));

    // ── Redimensionarea imaginilor din text ──────────────────
    // Chrome nu mai dă mânere la imaginile dintr-un `contenteditable`, iar
    // `enableObjectResizing` e ignorat. Așa că desenăm singuri un chenar peste
    // imaginea aleasă, cu un mâner de tras în colț.
    const shell = document.querySelector('.blog-editor-shell');
    const tool = document.getElementById('blog-img-tool');
    const toolHandle = document.getElementById('blog-img-tool-handle');
    const toolSize = document.getElementById('blog-img-tool-size');
    let alesa = null;
    let tragere = null;

    // Lățimea pe care o are chiar textul, fără marginile interioare: procentele
    // se raportează la ea, ca 50% în editor să însemne 50% și pe site.
    const latimeUtila = () => {
        if (!(editor instanceof HTMLElement)) return 1;
        const stil = window.getComputedStyle(editor);
        const utila = editor.clientWidth
            - parseFloat(stil.paddingLeft || '0')
            - parseFloat(stil.paddingRight || '0');

        return utila > 1 ? utila : 1;
    };

    const procentul = (img) => Math.max(1, Math.round(img.getBoundingClientRect().width / latimeUtila() * 100));

    const asazaUnealta = () => {
        if (!alesa || !alesa.isConnected || !(tool instanceof HTMLElement) || !(shell instanceof HTMLElement)) {
            if (tool instanceof HTMLElement) tool.hidden = true;
            return;
        }
        const i = alesa.getBoundingClientRect();
        const s = shell.getBoundingClientRect();
        tool.style.left = (i.left - s.left) + 'px';
        tool.style.top = (i.top - s.top) + 'px';
        tool.style.width = i.width + 'px';
        tool.style.height = i.height + 'px';
        if (toolSize) toolSize.textContent = Math.min(100, procentul(alesa)) + '%';
        tool.hidden = false;
    };

    const alegeImaginea = (img) => { alesa = img; asazaUnealta(); };
    const lasaImaginea = () => { alesa = null; if (tool instanceof HTMLElement) tool.hidden = true; };

    const sincronizeaza = () => {
        if (editorInput instanceof HTMLTextAreaElement && editor instanceof HTMLElement) {
            editorInput.value = editor.innerHTML.trim();
        }
    };

    const setLatime = (procent) => {
        if (!alesa) return;
        const p = Math.min(100, Math.max(5, Math.round(procent)));
        alesa.style.width = p + '%';
        alesa.style.height = 'auto';
        // `max-width` rămâne, ca imaginea să nu depășească niciodată coloana.
        alesa.style.maxWidth = '100%';
        asazaUnealta();
        sincronizeaza();
    };

    const setAliniere = (unde) => {
        if (!alesa) return;
        alesa.style.display = 'block';
        alesa.style.marginLeft = unde === 'left' ? '0' : 'auto';
        alesa.style.marginRight = unde === 'right' ? '0' : 'auto';
        asazaUnealta();
        sincronizeaza();
    };

    if (editor instanceof HTMLElement) {
        editor.addEventListener('click', (e) => {
            const tinta = e.target instanceof Element ? e.target.closest('img') : null;
            if (tinta && editor.contains(tinta)) {
                alegeImaginea(tinta);
            } else {
                lasaImaginea();
            }
        });
        // Textul care se rescrie mută imaginea; chenarul o urmează sau dispare.
        editor.addEventListener('input', () => { sincronizeaza(); asazaUnealta(); });
        editor.addEventListener('scroll', asazaUnealta);
    }
    window.addEventListener('resize', asazaUnealta);
    window.addEventListener('scroll', asazaUnealta, { passive: true });

    // Ștergerea imaginii alese scoate și chenarul.
    document.addEventListener('keydown', (e) => {
        if (!alesa) return;
        if (e.key === 'Delete' || e.key === 'Backspace') {
            if (document.activeElement === editor) {
                window.setTimeout(() => { if (!alesa || !alesa.isConnected) lasaImaginea(); else asazaUnealta(); }, 0);
            }
        }
        if (e.key === 'Escape') lasaImaginea();
    });

    tool?.querySelectorAll('[data-img-width]').forEach((b) => {
        b.addEventListener('click', () => setLatime(Number(b.getAttribute('data-img-width')) || 100));
    });
    tool?.querySelectorAll('[data-img-align]').forEach((b) => {
        b.addEventListener('click', () => setAliniere(b.getAttribute('data-img-align') || 'center'));
    });

    // Textul alternativ al imaginii alese. Vine completat din Galerie, dar în
    // articol are voie să spună altceva: aici imaginea are alt rol.
    document.getElementById('blog-img-tool-alt')?.addEventListener('click', () => {
        if (!alesa) return;
        const nou = window.prompt(
            'Text alternativ — descrie pe scurt ce se vede în imagine.\n'
            + 'Îl citește Google și îl aud cei care folosesc un cititor de ecran.',
            alesa.getAttribute('alt') || ''
        );
        if (nou === null) return;
        alesa.setAttribute('alt', nou.trim());
        sincronizeaza();
        asazaUnealta();
    });

    // Tragerea ascultă pe document, nu pe mâner: dacă mouse-ul iese de pe el
    // (și iese, când tragi repede), evenimentele tot ajung unde trebuie.
    // Captura de pointer e doar un ajutor în plus, de aceea nu se bazează nimic
    // pe ea — pe unele browsere aruncă și ar opri tragerea de tot.
    const trageMai = (e) => {
        if (!tragere || !alesa) return;
        // Minimul de 40px oprește imaginea să ajungă un punct din care nu se
        // mai poate trage înapoi.
        const noua = Math.max(40, tragere.latime + (e.clientX - tragere.x));
        setLatime(noua / latimeUtila() * 100);
    };
    const opresteTragerea = () => {
        if (!tragere) return;
        tragere = null;
        document.removeEventListener('pointermove', trageMai);
        document.removeEventListener('pointerup', opresteTragerea);
        document.removeEventListener('pointercancel', opresteTragerea);
        sincronizeaza();
    };
    toolHandle?.addEventListener('pointerdown', (e) => {
        if (!alesa) return;
        e.preventDefault();
        try { toolHandle.setPointerCapture(e.pointerId); } catch (err) { /* merge și fără */ }
        tragere = { x: e.clientX, latime: alesa.getBoundingClientRect().width };
        document.addEventListener('pointermove', trageMai);
        document.addEventListener('pointerup', opresteTragerea);
        document.addEventListener('pointercancel', opresteTragerea);
    });

    if (form instanceof HTMLFormElement && editor instanceof HTMLElement && editorInput instanceof HTMLTextAreaElement) {
        form.addEventListener('submit', (e) => {
            editorInput.value = editor.innerHTML.trim();
            sincronizeazaReferintele();

            // Imaginile fără text alternativ nu opresc salvarea, dar merită
            // spus o dată: fără el, Google nu știe ce e în poză.
            const faraAlt = Array.from(editor.querySelectorAll('img'))
                .filter((img) => (img.getAttribute('alt') || '').trim() === '');
            if (faraAlt.length > 0 && form.dataset.altConfirmat !== '1') {
                const mesaj = faraAlt.length === 1
                    ? 'O imagine din articol nu are text alternativ (alt).'
                    : faraAlt.length + ' imagini din articol nu au text alternativ (alt).';
                if (!window.confirm(mesaj + '\n\nSalvezi oricum?\n\n'
                    + 'Ca să-l completezi: dă clic pe imagine în editor, apoi pe butonul „Alt”.')) {
                    e.preventDefault();
                    faraAlt[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
                    alegeImaginea(faraAlt[0]);
                    return;
                }
                form.dataset.altConfirmat = '1';
            }
        });
    }
})();
</script>

<style>
.blog-rich-editor{
    min-height:260px;
    border:1px solid #cbd5e1;
    border-radius:12px;
    background:#fff;
    padding:12px;
    line-height:1.6;
}
.blog-rich-editor h1,.blog-rich-editor h2,.blog-rich-editor h3,
.blog-rich-editor h4,.blog-rich-editor h5,.blog-rich-editor h6{margin:0.65em 0 0.4em;}
/* Titlurile mici au nevoie de mărimi scrise anume: implicit browserul face H5
   și H6 mai mici decât textul normal, iar în editor n-ai mai ști ce ai apăsat. */
.blog-rich-editor h4{font-size:1.05em;}
.blog-rich-editor h5{font-size:0.95em;}
.blog-rich-editor h6{font-size:0.88em;color:#475569;}
.blog-rich-editor p{margin:0.5em 0;}
.blog-rich-editor ul,.blog-rich-editor ol{margin:0.5em 0 0.6em 1.2em;}
.blog-rich-editor a{color:#0f7b53;}
.blog-rich-editor img{max-width:100%;height:auto;border-radius:8px;margin:6px 0;}
.blog-cat-checks{display:flex;flex-wrap:wrap;gap:8px 14px;border:1px solid #cbd5e1;border-radius:12px;padding:12px 14px;background:#fff;}
.blog-cat-check{display:inline-flex;align-items:center;gap:7px;font-size:14px;color:#1f2937;cursor:pointer;}
.blog-cat-check input{width:16px;height:16px;cursor:pointer;}
.blog-rich-editor:empty:before{
    content:attr(data-placeholder);
    color:#94a3b8;
}

.blog-editor-shell{position:relative;}

.blog-featured-width{margin-top:12px;max-width:340px;}
.blog-featured-width label{display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:4px;}
.blog-featured-width-row{display:flex;align-items:center;gap:8px;}
.blog-featured-width-row input[type="range"]{flex:1;min-width:0;accent-color:#0f7b53;}
.blog-featured-width-row input[type="number"]{width:72px;}
.blog-featured-width-unit{font-size:12px;color:#64748b;}
.blog-featured-ruler{height:8px;margin-top:8px;border-radius:999px;background:#e2e8f0;overflow:hidden;}
.blog-featured-ruler span{display:block;height:100%;background:#0f7b53;border-radius:999px;transition:width .12s ease;}

/* Chenarul nu prinde clicurile — altfel n-ai mai putea scrie sub el. Doar bara
   și mânerul răspund la mouse. */
.blog-img-tool{
    position:absolute;
    pointer-events:none;
    border:2px solid #0f7b53;
    border-radius:8px;
    box-shadow:0 0 0 3px rgba(15,123,83,.14);
    z-index:5;
}
.blog-img-tool[hidden]{display:none;}

.blog-img-tool-bar{
    position:absolute;
    left:0;
    bottom:calc(100% + 6px);
    display:flex;
    align-items:center;
    gap:4px;
    padding:4px 6px;
    border-radius:8px;
    background:#0f172a;
    color:#fff;
    white-space:nowrap;
    pointer-events:auto;
    box-shadow:0 6px 16px rgba(15,23,42,.28);
}
.blog-img-tool-bar button{
    border:0;
    background:rgba(255,255,255,.12);
    color:#fff;
    font:inherit;
    font-size:11px;
    line-height:1;
    padding:5px 7px;
    border-radius:5px;
    cursor:pointer;
}
.blog-img-tool-bar button:hover{background:rgba(255,255,255,.26);}
.blog-img-tool-size{font-size:11px;font-weight:700;padding-right:4px;min-width:34px;}
.blog-img-tool-sep{width:1px;align-self:stretch;background:rgba(255,255,255,.25);margin:0 2px;}

/* Mânerul stă în colțul din dreapta-jos, ca peste tot. */
.blog-img-tool-handle{
    position:absolute;
    right:-7px;
    bottom:-7px;
    width:14px;
    height:14px;
    border:2px solid #fff;
    border-radius:3px;
    background:#0f7b53;
    cursor:nwse-resize;
    pointer-events:auto;
    touch-action:none;
}
/* ── Bară de unelte: separatoare și scurtături ─────────────────────────── */
.blog-toolbar-sep{width:1px;align-self:stretch;margin:0 2px;background:#e2e8f0;}
.blog-kbd{
    display:inline-block;margin-left:6px;padding:1px 5px;border-radius:4px;
    border:1px solid #cbd5e1;background:#f8fafc;color:#64748b;
    font:600 10px/1.4 ui-monospace,SFMono-Regular,Menlo,monospace;
}

/* ── Editorul de referințe ─────────────────────────────────────────────── */
/* Mai scund decât cel de conținut: aici se scriu rânduri, nu articole. */
.blog-rich-editor--refs{min-height:150px;font-size:14px;}
.blog-rich-editor--refs p{margin:0 0 8px;}

/* ── Etichete ──────────────────────────────────────────────────────────── */
.blog-tag-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
.blog-tag-chip{
    display:inline-flex;align-items:center;gap:6px;padding:4px 10px;
    border-radius:999px;border:1px solid #e2ece7;background:#f7fbf9;
    font-size:13px;color:#35564a;
}
.blog-tag-chip__x{
    border:0;background:none;padding:0;cursor:pointer;line-height:1;
    font-size:16px;color:#94a3b8;
}
.blog-tag-chip__x:hover{color:#dc2626;}
.blog-tag-chip--add{cursor:pointer;transition:background .15s ease,border-color .15s ease;}
.blog-tag-chip--add:hover{background:#e8f7f1;border-color:#bfe0d2;}
.blog-tag-chips--pick{max-height:180px;overflow:auto;}

/* ── Dialogul de link (Ctrl+K) ─────────────────────────────────────────── */
.blog-link-tabs{display:flex;gap:4px;margin:0 0 14px;border-bottom:1px solid #e2e8f0;}
.blog-link-tab{
    border:0;background:none;padding:8px 12px;cursor:pointer;
    font:600 13px/1 "DM Sans",Arial,sans-serif;color:#64748b;
    border-bottom:2px solid transparent;margin-bottom:-1px;
}
.blog-link-tab.is-active{color:#0f7b53;border-bottom-color:#0f7b53;}
.blog-link-tab[hidden]{display:none;}
.blog-link-ref-list{max-height:280px;overflow:auto;display:flex;flex-direction:column;gap:4px;}
.blog-link-ref{
    display:flex;gap:8px;align-items:flex-start;text-align:left;width:100%;
    padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;
    cursor:pointer;font-size:13px;line-height:1.45;color:#334155;
}
.blog-link-ref:hover{background:#f8fafc;border-color:#cbd5e1;}
.blog-link-ref.is-active{background:#eefaf4;border-color:#0f7b53;}
.blog-link-ref__nr{flex:0 0 auto;font-weight:700;color:#0f7b53;}
.blog-link-ref__text{
    flex:1 1 auto;min-width:0;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
}
/* ── Previzualizare (desktop / telefon + Google) ───────────────────────── */
.blog-preview-card{max-width:min(1400px,96vw);width:100%;max-height:94vh;display:flex;flex-direction:column;}
.blog-preview-card .modal-head{gap:12px;align-items:center;}
.blog-preview-switch{display:flex;gap:4px;margin-left:auto;padding:3px;border-radius:999px;background:#f1f5f9;}
.blog-preview-switch button{
    border:0;background:none;padding:6px 14px;border-radius:999px;cursor:pointer;
    font:600 13px/1 "DM Sans",Arial,sans-serif;color:#64748b;transition:background .15s ease,color .15s ease;
}
.blog-preview-switch button.is-active{background:#fff;color:#0f172a;box-shadow:0 1px 3px rgba(15,23,42,.12);}

/* Cardul Google */
.blog-serp{margin:0 0 14px;padding:14px 16px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;}
.blog-serp__label{
    font:700 11px/1 "DM Sans",Arial,sans-serif;letter-spacing:.08em;text-transform:uppercase;
    color:#94a3b8;margin-bottom:10px;
}
.blog-serp__card{padding:12px 14px;border-radius:10px;background:#fff;border:1px solid #e8eef5;max-width:600px;}
.blog-serp__url{font:400 12px/1.4 Arial,sans-serif;color:#4d5156;margin-bottom:3px;}
/* Culorile sunt cele din pagina de rezultate Google, ca previzualizarea să
   semene cu ce vede omul acolo, nu cu restul adminului. */
.blog-serp__title{font:400 20px/1.3 Arial,sans-serif;color:#1a0dab;margin-bottom:3px;cursor:default;}
.blog-serp__desc{font:400 14px/1.58 Arial,sans-serif;color:#4d5156;}
.blog-serp__desc.is-empty{color:#9aa0a6;font-style:italic;}
.blog-serp__counters{display:flex;gap:16px;margin-top:10px;}
.blog-serp__counters span{font:500 12px/1 "DM Sans",Arial,sans-serif;color:#64748b;}
.blog-serp__counters span.is-over{color:#dc2626;font-weight:700;}
.blog-serp__counters span.is-short{color:#d97706;}

/* Scena: fundal gri, ca „pagina" să se vadă ca o foaie separată. */
.blog-preview-stage{
    flex:1 1 auto;min-height:0;overflow:auto;padding:16px;border-radius:12px;background:#eef2f6;
    display:flex;justify-content:center;align-items:flex-start;
}
.blog-preview-frame{
    width:1280px;max-width:100%;background:#fff;border-radius:10px;overflow:hidden;
    box-shadow:0 6px 26px rgba(15,23,42,.12);transition:width .25s ease;
}
.blog-preview-frame.is-phone{border-radius:26px;box-shadow:0 8px 30px rgba(15,23,42,.2);}
.blog-preview-frame iframe{display:block;width:100%;height:min(72vh,900px);border:0;background:#fff;}
.blog-preview-loading{margin:0;padding:60px 20px;text-align:center;color:#64748b;font-size:14px;}
</style>
