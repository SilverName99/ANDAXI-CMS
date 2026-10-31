<?php ob_start(); ?>
    <a class="btn btn-secondary" href="/admin/pages/trash">Coș pagini</a>
    <a class="btn" href="/admin/pages/new">Pagină nouă</a>
<?php $antetActiuni = (string) ob_get_clean(); ?>
<?php
admin_componenta('antet-pagina', [
    'titlu' => 'Pagini',
    'nivel' => 1,
    'subtitlu' => 'Administrezi pagini HTML custom, cu URL controlat prin slug.',
    'ajutor' => [
        'Paginile se scriu în HTML, cu editorul de cod; adresa lor e slug-ul.',
        'O pagină ștearsă stă în coș până o ștergi definitiv.',
    ],
    'actiuni' => $antetActiuni,
]);
?>
<section class="panel">

    <table class="table">
        <thead>
        <tr>
            <th>ID</th>
            <th>Titlu</th>
            <th>Slug</th>
            <th>Status</th>
            <th>URL</th>
            <th>Acțiune</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($pages as $page): ?>
            <tr>
                <td><?= (int) $page['id'] ?></td>
                <td><?= htmlspecialchars((string) $page['title'], ENT_QUOTES) ?></td>
                <?php $isHomePage = trim((string) ($page['slug'] ?? '')) === ''; ?>
                <td><?= $isHomePage ? 'home (root /)' : htmlspecialchars((string) $page['slug'], ENT_QUOTES) ?></td>
                <td><?= ((int) $page['is_published'] === 1) ? 'Publicată' : 'Draft' ?></td>
                <?php $pageUrl = $isHomePage ? '/' : ('/' . ltrim((string) $page['slug'], '/')); ?>
                <td><a href="<?= $pageUrl ?>" target="_blank"><?= $isHomePage ? '/' : ('/' . htmlspecialchars((string) $page['slug'], ENT_QUOTES)) ?></a></td>
                <td>
                    <div style="display:flex;gap:8px;">
                        <a class="btn btn-secondary" href="/admin/pages/<?= (int) $page['id'] ?>/edit">Editează</a>
                        <form method="post" action="/admin/pages/<?= (int) $page['id'] ?>/delete" onsubmit="return confirm('Muți pagina în coș?');">
                            <button class="icon-btn danger" type="submit" title="Șterge">🗑</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
