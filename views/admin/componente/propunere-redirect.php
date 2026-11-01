<?php
/**
 * După ștergerea unei pagini (WP-38, B2): adresa ei veche dă de acum „pagina nu există”. Trei alegeri: 301 spre pagina
 * propusă (categoria produsului, lista produselor, blogul, prima pagină; ținta se poate schimba), 410 (dispărută
 * definitiv) sau nimic (404, ca până acum). Formularele postează la Redirecționări (App\Support\Redirectionari: aceleași
 * verificări ca o regulă scrisă de mână) și se întorc la pagina curentă.
 *
 * @var string $ce    „produsul”, „categoria”, „pagina”, „articolul”
 * @var string $din   adresa veche
 * @var string $spre  ținta propusă
 */
$inapoi = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/admin'), PHP_URL_PATH) ?: '/admin');
$campuri = '<input type="hidden" name="_csrf" value="' . adm_e(\App\Support\AdminCsrf::token()) . '">'
    . '<input type="hidden" name="din" value="' . adm_e($din ?? '') . '">'
    . '<input type="hidden" name="sursa" value="stergere">'
    . '<input type="hidden" name="inapoi" value="' . adm_e($inapoi) . '">';
ob_start();
?>
<form method="post" action="/admin/seo/redirectionari/adauga" class="adm-propunere-redirect">
    <?= $campuri ?>
    <input type="hidden" name="cod" value="301">
    <label for="propunere-redirect-spre">Trimite spre</label>
    <input type="text" id="propunere-redirect-spre" name="spre" value="<?= adm_e($spre ?? '') ?>" spellcheck="false">
    <button class="btn btn-secondary" type="submit">Redirecționează (301)</button>
</form>
<form method="post" action="/admin/seo/redirectionari/adauga" class="adm-propunere-redirect">
    <?= $campuri ?>
    <input type="hidden" name="cod" value="410">
    <button class="btn btn-light" type="submit">Dispărută definitiv (410)</button>
</form>
<?php
$actiuni = (string) ob_get_clean();
admin_componenta('mesaj', [
    'tip' => 'info',
    'id' => 'ax-propunere-redirect',
    'titlu' => 'Adresa veche a ' . str_replace(['produsul', 'categoria', 'pagina', 'articolul'], ['produsului', 'categoriei', 'paginii', 'articolului'], (string) ($ce ?? 'paginii'))
        . ' (' . (string) ($din ?? '') . ') dă acum „pagina nu există”.',
    'text' => 'Dacă are linkuri sau e în Google, trimite-o spre pagina cea mai apropiată; altfel spune-i lui Google că a dispărut. Fără nicio alegere rămâne „nu există” (404).',
    'actiuni' => $actiuni,
]);
