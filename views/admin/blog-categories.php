<?php
$categories = is_array($categories ?? null) ? $categories : [];
// WP-37 (B1): SEO-ul categoriilor și etichetelor blogului.
$categoriiSeo = is_array($categoriiSeo ?? null) ? $categoriiSeo : [];
$eticheteBlog = is_array($eticheteBlog ?? null) ? $eticheteBlog : [];
$eticheteBlogSeo = is_array($eticheteBlogSeo ?? null) ? $eticheteBlogSeo : [];
$areSeoBlog = static fn (array $seo): bool => trim((string) ($seo['title'] ?? '')) !== '' || trim((string) ($seo['description'] ?? '')) !== ''
    || trim((string) ($seo['robots'] ?? '')) !== '' || trim((string) ($seo['intro_html'] ?? '')) !== '';
?>
<?php
admin_componenta('antet-pagina', [
    'titlu' => 'Categorii blog',
    'nivel' => 1,
    'subtitlu' => 'Zonele de conținut (ex: Noutăți, Evenimente).',
]);
?>
<section class="panel">

    <form method="post" action="/admin/blog/categories" class="form-grid" style="margin-bottom:14px;">
        <div class="field">
            <label>Nume categorie *</label>
            <input type="text" name="name" required>
        </div>
        <div class="field">
            <label>Slug (opțional)</label>
            <input type="text" name="slug" placeholder="ex: noutati">
        </div>
        <div class="field">
            <label>Ordine</label>
            <input type="number" name="sort_order" value="0">
        </div>
        <div style="grid-column:1/-1;">
            <button class="btn" type="submit">Adaugă categorie</button>
        </div>
    </form>

    <table class="table">
        <thead>
        <tr>
            <th>ID</th>
            <th>Nume</th>
            <th>Slug</th>
            <th>Ordine</th>
            <th>Postări</th>
            <th>Acțiuni</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($categories === []): ?>
            <tr><td colspan="6">Nu există categorii.</td></tr>
        <?php else: ?>
            <?php foreach ($categories as $category): ?>
                <?php $updateFormId = 'blog-category-update-' . (int) $category['id']; ?>
                <tr>
                    <td><?= (int) $category['id'] ?></td>
                    <td>
                        <input
                            type="text"
                            name="name"
                            value="<?= htmlspecialchars((string) $category['name'], ENT_QUOTES) ?>"
                            form="<?= htmlspecialchars($updateFormId, ENT_QUOTES) ?>"
                            required
                            style="min-width:200px;"
                        >
                        <?php $seoCategorieBlog = $categoriiSeo[(string) (int) $category['id']] ?? []; ?>
                        <details style="margin-top:6px;"<?= $areSeoBlog($seoCategorieBlog) ? ' open' : '' ?>>
                            <summary class="seo-google-summary">SEO Google<?= $areSeoBlog($seoCategorieBlog) ? ' (completat)' : '' ?></summary>
<?php admin_componenta('panou-seo', [
    'tip' => 'blog_category', 'ref' => (string) (int) $category['id'], 'id' => 'blog-categorie-seo-' . (int) $category['id'],
    'form' => $updateFormId, 'valori' => $seoCategorieBlog, 'campuri' => ['titlu', 'descriere', 'robots', 'intro'],
    'max_titlu' => 120, 'max_descriere' => 320, 'adresa' => '/blog/categorie/' . (string) $category['slug'],
    'titlu_implicit' => (string) $category['name'],
]); ?>
                        </details>
                    </td>
                    <td>
                        <input
                            type="text"
                            name="slug"
                            value="<?= htmlspecialchars((string) $category['slug'], ENT_QUOTES) ?>"
                            form="<?= htmlspecialchars($updateFormId, ENT_QUOTES) ?>"
                            placeholder="slug-categorie"
                        >
                    </td>
                    <td>
                        <input
                            type="number"
                            name="sort_order"
                            value="<?= (int) ($category['sort_order'] ?? 0) ?>"
                            form="<?= htmlspecialchars($updateFormId, ENT_QUOTES) ?>"
                            style="width:80px;"
                        >
                    </td>
                    <td><?= (int) ($category['posts_count'] ?? 0) ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <form id="<?= htmlspecialchars($updateFormId, ENT_QUOTES) ?>" method="post" action="/admin/blog/categories/<?= (int) $category['id'] ?>/update">
                                <button class="btn btn-secondary" type="submit" title="Salvează modificările">Salvează</button>
                            </form>
                            <form method="post" action="/admin/blog/categories/<?= (int) $category['id'] ?>/delete" onsubmit="return confirm('Ștergi categoria? Postările vor rămâne fără categorie.');">
                                <button class="icon-btn danger" type="submit" title="Șterge categorie">🗑</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<?php if ($eticheteBlog !== []): ?>
<section class="panel" id="etichete-blog" style="margin-top:16px;">
    <div class="section-head">
        <div>
            <h2>Etichete blog — SEO Google</h2>
            <p>Titlul și descrierea din Google pentru pagina fiecărei etichete (/blog/eticheta/slug). Gol = „Eticheta: nume — Blog” și
                textul ales de Google.</p>
        </div>
    </div>
    <table class="table">
        <thead>
        <tr>
            <th>Etichetă</th>
            <th>Pagina</th>
            <th>Postări</th>
            <th>SEO Google</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($eticheteBlog as $etichetaBlog): ?>
            <?php $seoEticheta = $eticheteBlogSeo[(string) (int) $etichetaBlog['id']] ?? []; ?>
            <tr>
                <td><?= htmlspecialchars((string) $etichetaBlog['name'], ENT_QUOTES) ?></td>
                <td><a href="/blog/eticheta/<?= rawurlencode((string) $etichetaBlog['slug']) ?>" target="_blank" rel="noopener">/blog/eticheta/<?= htmlspecialchars((string) $etichetaBlog['slug'], ENT_QUOTES) ?></a></td>
                <td><?= (int) ($etichetaBlog['posts_count'] ?? 0) ?></td>
                <td>
                    <form method="post" action="/admin/blog/categories/eticheta-seo">
                        <input type="hidden" name="tag_id" value="<?= (int) $etichetaBlog['id'] ?>">
                        <details<?= $areSeoBlog($seoEticheta) ? ' open' : '' ?>>
                            <summary class="seo-google-summary">SEO Google<?= $areSeoBlog($seoEticheta) ? ' (completat)' : '' ?></summary>
<?php admin_componenta('panou-seo', [
    'tip' => 'blog_tag', 'ref' => (string) (int) $etichetaBlog['id'], 'id' => 'blog-eticheta-seo-' . (int) $etichetaBlog['id'],
    'valori' => $seoEticheta, 'campuri' => ['titlu', 'descriere', 'robots', 'intro'], 'max_titlu' => 120, 'max_descriere' => 320,
    'adresa' => '/blog/eticheta/' . (string) $etichetaBlog['slug'], 'titlu_implicit' => 'Eticheta: ' . (string) $etichetaBlog['name'],
]); ?>
                            <div style="margin-top:8px;"><button class="btn btn-secondary" type="submit">Salvează</button></div>
                        </details>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>
<style>
.seo-google-summary{cursor:pointer;color:var(--ax-accent);font-size:13px;}
</style>
