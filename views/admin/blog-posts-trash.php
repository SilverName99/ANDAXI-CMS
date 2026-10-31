<?php ob_start(); ?>
<a class="btn btn-secondary" href="/admin/blog/posts">Înapoi la postări</a>
<?php $antetActiuni = (string) ob_get_clean(); ?>
<?php
admin_componenta('antet-pagina', [
    'titlu' => 'Coș postări blog',
    'nivel' => 1,
    'subtitlu' => 'Restaurează postări șterse sau elimină-le definitiv.',
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
        <?php foreach (($posts ?? []) as $post): ?>
            <tr>
                <td><?= (int) ($post['id'] ?? 0) ?></td>
                <td><?= htmlspecialchars((string) ($post['title'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($post['slug'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($post['deleted_at'] ?? ''), ENT_QUOTES) ?></td>
                <td>
                    <div style="display:flex;gap:8px;">
                        <form method="post" action="/admin/blog/posts">
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="id" value="<?= (int) ($post['id'] ?? 0) ?>">
                            <button class="btn btn-secondary" type="submit">Refacere</button>
                        </form>
                        <form method="post" action="/admin/blog/posts" onsubmit="return confirm('Ștergere definitivă?');">
                            <input type="hidden" name="action" value="force_delete">
                            <input type="hidden" name="id" value="<?= (int) ($post['id'] ?? 0) ?>">
                            <button class="icon-btn danger" type="submit" title="Ștergere definitivă">🗑</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
