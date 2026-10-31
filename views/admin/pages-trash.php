<?php ob_start(); ?>
<a class="btn btn-secondary" href="/admin/pages">Înapoi la pagini</a>
<?php $antetActiuni = (string) ob_get_clean(); ?>
<?php
admin_componenta('antet-pagina', [
    'titlu' => 'Coș pagini',
    'nivel' => 1,
    'subtitlu' => 'Restaurează paginile șterse sau elimină-le definitiv.',
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
            <th>Ștearsă la</th>
            <th>Acțiuni</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($pages as $page): ?>
            <tr>
                <td><?= (int) $page['id'] ?></td>
                <td><?= htmlspecialchars((string) $page['title'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) $page['slug'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($page['deleted_at'] ?? ''), ENT_QUOTES) ?></td>
                <td>
                    <div style="display:flex;gap:8px;">
                        <form method="post" action="/admin/pages/<?= (int) $page['id'] ?>/restore">
                            <button class="btn btn-secondary" type="submit">Refacere</button>
                        </form>
                        <form method="post" action="/admin/pages/<?= (int) $page['id'] ?>/force-delete" onsubmit="return confirm('Ștergere definitivă?');">
                            <button class="icon-btn danger" type="submit" title="Ștergere definitivă">🗑</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
