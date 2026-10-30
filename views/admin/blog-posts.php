<?php
$posts = is_array($posts ?? null) ? $posts : [];
$search = trim((string) ($search ?? ''));
$status = in_array((string) ($status ?? 'all'), ['all', 'published', 'draft', 'scheduled'], true) ? (string) $status : 'all';
$page = max(1, (int) ($page ?? 1));
$perPage = (int) ($perPage ?? 25);
$totalPages = max(1, (int) ($totalPages ?? 1));
$totalPosts = max(0, (int) ($totalPosts ?? 0));
$panel = in_array((string) ($panel ?? 'list'), ['list', 'import'], true) ? (string) $panel : 'list';
$perPageOptions = is_array($perPageOptions ?? null) ? $perPageOptions : [10, 25, 50, 100, 200, 500];
$buildPostsUrl = static function (array $params = []) use ($search, $status, $perPage, $panel): string {
    $query = [
        'status' => $status,
        'per_page' => $perPage,
        'panel' => $panel,
    ];
    if ($search !== '') {
        $query['q'] = $search;
    }
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') {
            unset($query[$k]);
            continue;
        }
        $query[$k] = $v;
    }
    return '/admin/blog/posts' . ($query !== [] ? '?' . http_build_query($query) : '');
};
// Fereastra din jurul paginii curente, plus prima și ultima, ca la 15 pagini
// să nu fie nevoie de 7 clicuri până la capăt. Golurile devin „…".
$paginationPages = [];
$numere = [1, $totalPages];
for ($i = $page - 2; $i <= $page + 2; $i++) {
    $numere[] = $i;
}
$numere = array_values(array_unique(array_filter(
    $numere,
    static fn (int $n): bool => $n >= 1 && $n <= $totalPages
)));
sort($numere, SORT_NUMERIC);
$anterior = null;
foreach ($numere as $numar) {
    if ($anterior !== null) {
        $gol = $numar - $anterior;
        if ($gol === 2) {
            // Un singur număr lipsă: îl arătăm, „…" ar ocupa același loc.
            $paginationPages[] = $anterior + 1;
        } elseif ($gol > 2) {
            $paginationPages[] = '…';
        }
    }
    $paginationPages[] = $numar;
    $anterior = $numar;
}
?>

<section class="panel">
    <div class="section-head">
        <div>
            <h1>Postări blog</h1>
            <p>Gestionează articolele pentru secțiunea Blog.</p>
        </div>
        <div style="display:flex;gap:8px;">
            <a class="btn btn-secondary" href="/admin/blog/posts/trash">Coș postări</a>
            <a class="btn" href="/admin/blog/posts/new">+ Postare nouă</a>
        </div>
    </div>

    <div class="subsection-tabs" style="margin-bottom:10px;">
        <a class="<?= $panel === 'list' ? 'active' : '' ?>" href="<?= htmlspecialchars($buildPostsUrl(['panel' => 'list', 'page' => 1]), ENT_QUOTES) ?>">Toate postările</a>
        <a class="<?= $panel === 'import' ? 'active' : '' ?>" href="<?= htmlspecialchars($buildPostsUrl(['panel' => 'import', 'page' => 1]), ENT_QUOTES) ?>">Import postări</a>
    </div>

    <?php if ($panel === 'import'): ?>
    <form method="post" action="/admin/blog/posts/import" enctype="multipart/form-data" class="users-search-row" style="margin-bottom:10px;">
        <input type="file" name="blog_posts_file" accept=".csv,.xlsx" required>
        <button class="btn" type="submit">Import postări (CSV/XLSX)</button>
        <small style="color:#64748b;">Coloane recomandate: Title, Content, Slug, Date, Status, Excerpt, Categories.</small>
    </form>
    <?php endif; ?>

    <?php if ($panel === 'list'): ?>
    <form method="get" action="/admin/blog/posts" class="admin-filters-inline" style="margin-bottom:10px;">
        <input type="hidden" name="panel" value="list">
        <div class="admin-filter-field">
            <select name="status">
            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Toate</option>
            <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Publicate</option>
            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
            <option value="scheduled" <?= $status === 'scheduled' ? 'selected' : '' ?>>Programate</option>
            </select>
        </div>
        <div class="admin-filter-field admin-filter-field--search">
            <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" placeholder="Caută după titlu, slug, autor...">
        </div>
        <div class="admin-filter-field">
            <select name="per_page">
            <?php foreach ($perPageOptions as $opt): ?>
                <?php $opt = (int) $opt; ?>
                <option value="<?= $opt ?>" <?= $opt === $perPage ? 'selected' : '' ?>><?= $opt ?>/pagină</option>
            <?php endforeach; ?>
            </select>
        </div>
        <div class="admin-filter-actions">
            <button class="btn" type="submit">Aplică</button>
            <a class="btn btn-secondary" href="/admin/blog/posts?panel=list">Reset</a>
        </div>
    </form>

<?= \App\Support\Dev::marcaj('bv:blog-lista-inceput') ?>    <!-- Tabelul și paginarea se schimbă între ele fără reîncărcarea paginii,
         ca poziția în listă să rămână unde era. -->
    <div id="blog-posts-list" data-blog-posts-list>
<?= \App\Support\Dev::marcaj('bv:blog-lista-inceput', true) ?>    <div class="users-table-wrap">
        <table class="table users-table">
            <thead>
            <tr>
                <th>Titlu</th>
                <th>Autor</th>
                <th>Categorie</th>
                <th>Template</th>
                <th>Publicare</th>
                <th>Status</th>
                <th style="width:130px;">Acțiuni</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($posts === []): ?>
                <tr><td colspan="7">Nu există postări blog.</td></tr>
            <?php else: ?>
                <?php foreach ($posts as $post): ?>
                    <?php
                    $id = (int) ($post['id'] ?? 0);
                    $slug = trim((string) ($post['slug'] ?? ''));
                    $isPublished = (int) ($post['is_published'] ?? 0) === 1;
                    $publishAt = (string) ($post['published_at'] ?? '');
                    $statusLabel = $isPublished ? 'Publicat' : 'Draft';
                    if ($isPublished && $publishAt !== '') {
                        $ts = strtotime($publishAt);
                        if ($ts !== false && $ts > time()) {
                            $statusLabel = 'Programat';
                        }
                    }
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars((string) ($post['title'] ?? 'Postare'), ENT_QUOTES) ?></strong><br>
                            <small><?= $slug !== '' ? '/blog/' . htmlspecialchars($slug, ENT_QUOTES) : '-' ?></small>
                        </td>
                        <td><?= htmlspecialchars((string) ($post['author_name'] ?? 'Fără autor'), ENT_QUOTES) ?></td>
                        <td><?php $catsLabel = trim((string) ($post['categories_label'] ?? '')); if ($catsLabel === '') { $catsLabel = trim((string) ($post['category'] ?? '')); } echo htmlspecialchars($catsLabel !== '' ? $catsLabel : '-', ENT_QUOTES); ?></td>
                        <td><?= htmlspecialchars((string) ($post['template_name'] ?? 'Implicit'), ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($publishAt !== '' ? date('d.m.Y H:i', strtotime($publishAt) ?: time()) : '-', ENT_QUOTES) ?></td>
                        <td><span class="status-pill <?= $isPublished ? 'ok' : 'off' ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES) ?></span></td>
                        <td style="display:flex;gap:8px;">
                            <a class="icon-btn" href="/admin/blog/posts/editor?post=<?= $id ?>" title="Editează">✎</a>
                            <form method="post" action="/admin/blog/posts" onsubmit="return confirm('Dorești să duplici această postare?');">
                                <input type="hidden" name="action" value="duplicate">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button class="icon-btn" type="submit" title="Duplică">⧉</button>
                            </form>
                            <form method="post" action="/admin/blog/posts" onsubmit="return confirm('Muți postarea în coș?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button class="icon-btn danger" type="submit" title="Șterge">🗑</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
        <div class="users-search-row blog-pager" style="margin-top:10px;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
            <small style="color:#64748b;">
                <?= $totalPosts ?> postări · pagina <?= $page ?> din <?= $totalPages ?>
            </small>
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                <?php if ($page > 1): ?>
                    <a class="btn btn-secondary" data-blog-page="<?= $page - 1 ?>"
                       href="<?= htmlspecialchars($buildPostsUrl(['page' => $page - 1]), ENT_QUOTES) ?>"
                       rel="prev">‹ Anterior</a>
                <?php else: ?>
                    <span class="btn btn-secondary is-disabled" aria-disabled="true">‹ Anterior</span>
                <?php endif; ?>

                <?php foreach ($paginationPages as $p): ?>
                    <?php if ($p === '…'): ?>
                        <span class="blog-pager__gap" aria-hidden="true">…</span>
                    <?php elseif ((int) $p === $page): ?>
                        <span class="btn is-current" aria-current="page"><?= (int) $p ?></span>
                    <?php else: ?>
                        <a class="btn btn-secondary" data-blog-page="<?= (int) $p ?>"
                           href="<?= htmlspecialchars($buildPostsUrl(['page' => (int) $p]), ENT_QUOTES) ?>"><?= (int) $p ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php if ($page < $totalPages): ?>
                    <a class="btn btn-secondary" data-blog-page="<?= $page + 1 ?>"
                       href="<?= htmlspecialchars($buildPostsUrl(['page' => $page + 1]), ENT_QUOTES) ?>"
                       rel="next">Următor ›</a>
                <?php else: ?>
                    <span class="btn btn-secondary is-disabled" aria-disabled="true">Următor ›</span>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
<?= \App\Support\Dev::marcaj('bv:blog-lista-sfarsit') ?>    </div><!-- /#blog-posts-list -->
<?= \App\Support\Dev::marcaj('bv:blog-lista-sfarsit', true) ?>    <?php endif; ?>
</section>
<?= \App\Support\Dev::marcaj('bv:blog-lista-script') ?>
<style>
.blog-pager__gap{padding:0 4px;color:#94a3b8;user-select:none;}
.blog-pager .btn.is-current{cursor:default;}
.blog-pager .btn.is-disabled{opacity:.45;pointer-events:none;}
/* Cât se încarcă pagina următoare, lista rămâne pe loc, doar se estompează —
   fără să sară nimic și fără să se schimbe înălțimea. */
#blog-posts-list[aria-busy="true"]{opacity:.55;transition:opacity .12s ease;}
#blog-posts-list[aria-busy="true"] a,
#blog-posts-list[aria-busy="true"] button{pointer-events:none;}
</style>

<script>
(() => {
    'use strict';

    // Paginarea schimbă doar tabelul, nu toată pagina: altfel browserul
    // reîncarcă și te trimite înapoi în capul listei de fiecare dată.
    const container = () => document.getElementById('blog-posts-list');
    let cerereInCurs = null;

    const incarca = async (url, adaugaInIstoric) => {
        const lista = container();
        if (!lista) { window.location.href = url; return; }

        // Un clic nou anulează căutarea începută înainte, ca răspunsurile să
        // nu ajungă în altă ordine decât clicurile.
        cerereInCurs?.abort();
        const control = new AbortController();
        cerereInCurs = control;
        lista.setAttribute('aria-busy', 'true');

        try {
            const raspuns = await fetch(url, {
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'fetch'},
                signal: control.signal,
            });
            if (!raspuns.ok) throw new Error('HTTP ' + raspuns.status);

            const doc = new DOMParser().parseFromString(await raspuns.text(), 'text/html');
            const noua = doc.getElementById('blog-posts-list');
            // Fără containerul așteptat (sesiune expirată, redirect la login)
            // lăsăm browserul să meargă normal la adresă.
            if (!noua) { window.location.href = url; return; }

            lista.innerHTML = noua.innerHTML;
            lista.removeAttribute('aria-busy');
            if (adaugaInIstoric) {
                window.history.pushState({blogPosts: true}, '', url);
            }
        } catch (err) {
            if (err.name === 'AbortError') return;
            // Orice altă problemă: navigare obișnuită, ca omul să ajungă
            // totuși pe pagina cerută.
            window.location.href = url;
        }
    };

    document.addEventListener('click', (e) => {
        const link = e.target instanceof Element ? e.target.closest('a[data-blog-page]') : null;
        if (!link) return;
        // Clic cu Ctrl / rotița mouse-ului = deschidere în filă nouă; nu ne băgăm.
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        incarca(link.getAttribute('href') || '', true);
    });

    // Săgeata „înapoi" a browserului trebuie să ducă la pagina anterioară din
    // listă, nu în afara ei.
    window.addEventListener('popstate', (e) => {
        if (e.state && e.state.blogPosts) {
            incarca(window.location.href, false);
        }
    });
})();
</script>
<?= \App\Support\Dev::marcaj('bv:blog-lista-script', true) ?>
