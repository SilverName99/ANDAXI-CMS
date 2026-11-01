<?php
$categoriesSeo = is_array($categoriesSeo ?? null) ? $categoriesSeo : [];
$brands = is_array($brands ?? null) ? $brands : [];
$brandsSeo = is_array($brandsSeo ?? null) ? $brandsSeo : [];
// WP-37 (B1): etichetele, lista tuturor produselor și textul de deasupra fiecărei categorii.
$tags = is_array($tags ?? null) ? $tags : [];
$tagsSeo = is_array($tagsSeo ?? null) ? $tagsSeo : [];
$listaSeo = is_array($listaSeo ?? null) ? $listaSeo : [];
$introCategorii = is_array($introCategorii ?? null) ? $introCategorii : [];
$urlCatalog = (string) ($urlCatalog ?? '/magazin');
$areSeo = static fn (array $seo): bool => trim((string) ($seo['title'] ?? '')) !== '' || trim((string) ($seo['description'] ?? '')) !== ''
    || trim((string) ($seo['robots'] ?? '')) !== '' || trim((string) ($seo['intro_html'] ?? '')) !== '';
?>
<?php
admin_componenta('antet-pagina', [
    'titlu' => 'Categorii produse',
    'nivel' => 1,
    'subtitlu' => 'Creează și administrează categoriile din magazin; SEO-ul categoriilor, mărcilor, etichetelor și al listei de produse.',
]);
?>
<section class="panel">

    <form method="post" action="/admin/categories" class="form-grid" style="margin-bottom:14px;">
        <div class="field">
            <label>Nume categorie *</label>
            <input type="text" name="name" required>
        </div>
        <div class="field">
            <label>Slug (opțional)</label>
            <input type="text" name="slug" placeholder="ex: suplimente">
        </div>
        <div style="grid-column:1/-1;">
            <button class="btn" type="submit">Adaugă categorie</button>
        </div>
    </form>

    <p class="muted" style="margin:0 0 10px;font-size:13px;">
        <strong>SEO Google</strong>: titlul și descrierea care apar în Google pentru pagina categoriei
        (/categorie/slug). Titlul: cel mult circa 60 de caractere. Descrierea: circa 120–155 de caractere.
        Gol = titlul „Nume | magazin” și o descriere făcută automat din produsele categoriei.
        Schimbarea slug-ului schimbă adresa paginii din Google; adresa veche trimite singură la cea nouă (Setări → Redirecționări și 404).
    </p>

    <table class="table">
        <thead>
        <tr>
            <th>ID</th>
            <th>Nume</th>
            <th>Slug</th>
            <th>Produse</th>
            <th>Acțiuni</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($categories as $category): ?>
            <?php
            $updateFormId = 'category-update-' . (int) $category['id'];
            $categorySeo = $categoriesSeo[(string) (int) $category['id']] ?? [];
            $categorySeo['intro_html'] = $introCategorii[(string) (int) $category['id']] ?? '';
            $categorieCuSeo = $areSeo($categorySeo);
            ?>
            <tr>
                <td><?= (int) $category['id'] ?></td>
                <td>
                    <input
                        type="text"
                        name="name"
                        value="<?= htmlspecialchars((string) $category['name'], ENT_QUOTES) ?>"
                        form="<?= htmlspecialchars($updateFormId, ENT_QUOTES) ?>"
                        required
                        style="min-width:220px;"
                    >
                    <details style="margin-top:6px;"<?= $categorieCuSeo ? ' open' : '' ?>>
                        <summary class="seo-google-summary">SEO Google<?= $categorieCuSeo ? ' (completat)' : '' ?></summary>
<?php admin_componenta('panou-seo', [
    'tip' => 'category', 'ref' => (string) (int) $category['id'], 'id' => 'categorie-seo-' . (int) $category['id'],
    'form' => $updateFormId, 'valori' => $categorySeo, 'campuri' => ['titlu', 'descriere', 'robots', 'intro'],
    'nume' => ['intro' => 'intro_html'], 'max_titlu' => 120, 'max_descriere' => 320,
    'adresa' => '/categorie/' . (string) $category['slug'], 'titlu_implicit' => (string) $category['name'],
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
                <td><?= (int) $category['products_count'] ?></td>
                <td>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <form id="<?= htmlspecialchars($updateFormId, ENT_QUOTES) ?>" method="post" action="/admin/categories/<?= (int) $category['id'] ?>/update">
                            <button class="btn btn-secondary" type="submit" title="Salvează modificările">Salvează</button>
                        </form>
                        <form method="post" action="/admin/categories/<?= (int) $category['id'] ?>/delete" onsubmit="return confirm('Ștergi categoria? Produsele vor rămâne fără categorie.');">
                            <button class="icon-btn danger" type="submit" title="Șterge categorie">🗑</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="panel" id="lista-produse" style="margin-top:16px;">
    <div class="section-head">
        <div>
            <h2>Lista tuturor produselor — SEO Google</h2>
            <p>Pagina <?= htmlspecialchars($urlCatalog, ENT_QUOTES) ?>. Dacă pagina ei din constructor are SEO scris (Pagini), acela are întâietate.
                Gol = titlul paginii și o descriere făcută automat din produse.</p>
        </div>
    </div>
    <form method="post" action="/admin/categories/brand-seo">
        <input type="hidden" name="seo_tip" value="listing_default">
<?php admin_componenta('panou-seo', [
    'tip' => 'listing_default', 'ref' => \App\Support\Seo::REF_LISTA, 'id' => 'lista-seo', 'valori' => $listaSeo,
    'campuri' => ['titlu', 'descriere', 'robots', 'og', 'intro'], 'max_titlu' => 120, 'max_descriere' => 320, 'adresa' => $urlCatalog,
]); ?>
        <div style="margin-top:8px;"><button class="btn btn-secondary" type="submit">Salvează</button></div>
    </form>
</section>

<?php if ($brands !== []): ?>
<section class="panel" id="marci" style="margin-top:16px;">
    <div class="section-head">
        <div>
            <h2>Mărci — SEO Google</h2>
            <p>Titlul și descrierea din Google pentru pagina fiecărei mărci (/marca/slug). Mărcile vin din câmpul „Marcă” al produselor.
                Gol = titlul „Marcă | magazin” și o descriere făcută automat din produsele mărcii.</p>
        </div>
    </div>
    <table class="table">
        <thead>
        <tr>
            <th>Marcă</th>
            <th>Pagina</th>
            <th>Produse active</th>
            <th>SEO Google</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($brands as $brand): ?>
            <?php
            $brandSlug = (string) ($brand['slug'] ?? '');
            $brandFormId = 'brand-seo-' . $brandSlug;
            $brandSeo = $brandsSeo[$brandSlug] ?? [];
            ?>
            <tr>
                <td><?= htmlspecialchars((string) ($brand['name'] ?? ''), ENT_QUOTES) ?></td>
                <td><a href="/marca/<?= rawurlencode($brandSlug) ?>" target="_blank" rel="noopener">/marca/<?= htmlspecialchars($brandSlug, ENT_QUOTES) ?></a></td>
                <td><?= (int) ($brand['products_count'] ?? 0) ?></td>
                <td>
                    <form id="<?= htmlspecialchars($brandFormId, ENT_QUOTES) ?>" method="post" action="/admin/categories/brand-seo">
                        <input type="hidden" name="brand_slug" value="<?= htmlspecialchars($brandSlug, ENT_QUOTES) ?>">
                        <details<?= $areSeo($brandSeo) ? ' open' : '' ?>>
                            <summary class="seo-google-summary">SEO Google<?= $areSeo($brandSeo) ? ' (completat)' : '' ?></summary>
<?php admin_componenta('panou-seo', [
    'tip' => 'brand', 'ref' => $brandSlug, 'id' => 'marca-seo-' . $brandSlug, 'valori' => $brandSeo,
    'campuri' => ['titlu', 'descriere', 'robots', 'intro'], 'max_titlu' => 120, 'max_descriere' => 320,
    'adresa' => '/marca/' . $brandSlug, 'titlu_implicit' => (string) ($brand['name'] ?? ''),
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

<?php if ($tags !== []): ?>
<section class="panel" id="etichete" style="margin-top:16px;">
    <div class="section-head">
        <div>
            <h2>Etichete — SEO Google</h2>
            <p>Titlul și descrierea din Google pentru pagina fiecărei etichete (/eticheta/slug). Etichetele vin din etichetele produselor.
                Gol = titlul „Produse: etichetă | magazin” și o descriere făcută automat din produsele etichetei.</p>
        </div>
    </div>
    <table class="table">
        <thead>
        <tr>
            <th>Etichetă</th>
            <th>Pagina</th>
            <th>Produse active</th>
            <th>SEO Google</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($tags as $tag): ?>
            <?php
            $tagSlug = (string) ($tag['slug'] ?? '');
            $tagSeo = $tagsSeo[$tagSlug] ?? [];
            ?>
            <tr>
                <td><?= htmlspecialchars((string) ($tag['name'] ?? ''), ENT_QUOTES) ?></td>
                <td><a href="/eticheta/<?= rawurlencode($tagSlug) ?>" target="_blank" rel="noopener">/eticheta/<?= htmlspecialchars($tagSlug, ENT_QUOTES) ?></a></td>
                <td><?= (int) ($tag['products_count'] ?? 0) ?></td>
                <td>
                    <form method="post" action="/admin/categories/brand-seo">
                        <input type="hidden" name="seo_tip" value="tag">
                        <input type="hidden" name="tag_slug" value="<?= htmlspecialchars($tagSlug, ENT_QUOTES) ?>">
                        <details<?= $areSeo($tagSeo) ? ' open' : '' ?>>
                            <summary class="seo-google-summary">SEO Google<?= $areSeo($tagSeo) ? ' (completat)' : '' ?></summary>
<?php admin_componenta('panou-seo', [
    'tip' => 'tag', 'ref' => $tagSlug, 'id' => 'eticheta-seo-' . $tagSlug, 'valori' => $tagSeo,
    'campuri' => ['titlu', 'descriere', 'robots', 'intro'], 'max_titlu' => 120, 'max_descriere' => 320,
    'adresa' => '/eticheta/' . $tagSlug, 'titlu_implicit' => 'Produse: ' . (string) ($tag['name'] ?? ''),
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
