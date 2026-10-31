<?php ob_start(); ?>
<a class="btn btn-secondary" href="/admin/products">Înapoi la produse</a>
<?php $antetActiuni = (string) ob_get_clean(); ?>
<?php
admin_componenta('antet-pagina', [
    'titlu' => 'Coș produse',
    'nivel' => 1,
    'subtitlu' => 'Refaci produsele șterse sau le elimini definitiv.',
    'actiuni' => $antetActiuni,
]);
?>
<section class="panel">

    <table class="table">
        <thead>
        <tr>
            <th>ID</th>
            <th>Produs</th>
            <th>SKU</th>
            <th>Categorie</th>
            <th>Șters la</th>
            <th>Acțiuni</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($products as $product): ?>
            <tr>
                <td><?= (int) $product['id'] ?></td>
                <td><?= htmlspecialchars((string) $product['name'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($product['sku'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($product['category'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($product['deleted_at'] ?? ''), ENT_QUOTES) ?></td>
                <td>
                    <div style="display:flex;gap:8px;">
                        <form method="post" action="/admin/products/<?= (int) $product['id'] ?>/restore">
                            <button class="btn btn-secondary" type="submit">Refacere</button>
                        </form>
                        <form method="post" action="/admin/products/<?= (int) $product['id'] ?>/force-delete" onsubmit="return confirm('Ștergere definitivă produs?');">
                            <button class="icon-btn danger" type="submit" title="Ștergere definitivă">🗑</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
