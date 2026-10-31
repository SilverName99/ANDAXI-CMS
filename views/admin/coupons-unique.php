<?php
$coupons = is_array($coupons ?? null) ? $coupons : [];
$products = is_array($products ?? null) ? $products : [];
$categories = is_array($categories ?? null) ? $categories : [];
$users = is_array($users ?? null) ? $users : [];
$view = ($view ?? 'active') === 'used' ? 'used' : 'active';
$q = (string) ($q ?? '');
$batch = (string) ($batch ?? '');
$batches = is_array($batches ?? null) ? $batches : [];
$page = max(1, (int) ($page ?? 1));
$perPage = (int) ($perPage ?? 50);
if (!in_array($perPage, [50, 100, 250, 500], true)) {
    $perPage = 50;
}
$totalFiltered = max(0, (int) ($totalFiltered ?? 0));
$activeCount = max(0, (int) ($activeCount ?? 0));
$usedCount = max(0, (int) ($usedCount ?? 0));
$totalPages = (int) max(1, (int) ceil($totalFiltered / $perPage));

$pageUrl = static function (int $p) use ($view, $q, $batch, $perPage): string {
    $params = ['view' => $view, 'p' => $p];
    if ($q !== '') {
        $params['q'] = $q;
    }
    if ($batch !== '') {
        $params['batch'] = $batch;
    }
    if ($perPage !== 50) {
        $params['per'] = $perPage;
    }
    return '/admin/coupons/unique?' . http_build_query($params);
};
?>

<?php
// WP-35 (IA-06): Cupoanele și codurile unice sunt o singură pagină, „Cupoane”, cu două tab-uri; ambele adrese rămân
// (/admin/coupons și /admin/coupons/unique), fiecare cu tab-ul ei ales.
admin_componenta('antet-pagina', [
    'titlu' => 'Cupoane',
    'nivel' => 1,
    'subtitlu' => 'Codurile de reducere de la casă: cupoanele cu reguli și codurile unice, de o singură folosire.',
    'ajutor' => [
        'Codurile unice se importă pe loturi (fișier sau lipite). Când unul e folosit într-o comandă trece la „Coduri folosite”, de unde îl poți reactiva pentru teste.',
    ],
]);
admin_componenta('tab-uri-cu-numar', ['eticheta' => 'Felurile de cupoane', 'taburi' => [
    ['eticheta' => 'Cupoane', 'href' => '/admin/coupons', 'activ' => false],
    ['eticheta' => 'Coduri unice', 'href' => '/admin/coupons/unique', 'activ' => true],
]]);
?>
<section class="panel">
    <?php
    admin_componenta('tab-uri-cu-numar', ['eticheta' => 'Codurile unice, după stare', 'taburi' => [
        ['eticheta' => 'Coduri active', 'href' => '/admin/coupons/unique?view=active', 'numar' => $activeCount, 'activ' => $view === 'active'],
        ['eticheta' => 'Coduri folosite', 'href' => '/admin/coupons/unique?view=used', 'numar' => $usedCount, 'activ' => $view === 'used'],
    ]]);
    ?>

    <?php if ($view === 'active'): ?>
        <details class="vuc-import" <?= $activeCount === 0 ? 'open' : '' ?>>
            <summary>➕ Importă cupoane (fișier .xlsx / .csv / .txt sau lipește codurile)</summary>
            <form method="post" action="/admin/coupons/unique/import" enctype="multipart/form-data" class="vuc-import-form">
                <div class="vuc-grid">
                    <div class="field">
                        <label>Fișier cu coduri (.xlsx, .csv, .txt)</label>
                        <input type="file" name="codes_file" accept=".xlsx,.csv,.txt">
                        <small>O coloană / un cod pe linie. Poți combina cu lipirea de mai jos.</small>
                    </div>
                    <div class="field">
                        <label>Etichetă lot (opțional)</label>
                        <input type="text" name="batch_label" placeholder="ex: Serii PMC iulie">
                    </div>
                    <div class="field vuc-span-full">
                        <label>Sau lipește codurile aici (opțional)</label>
                        <textarea name="codes" rows="3" placeholder="PMC832301, PMC832302, ..."></textarea>
                    </div>
                </div>

                <h4 class="vuc-sub">Setări comune (se aplică tuturor codurilor importate)</h4>
                <div class="vuc-grid">
                    <div class="field">
                        <label>Prefix denumire (opțional)</label>
                        <input type="text" name="name_prefix" placeholder="ex: Voucher">
                        <small>Denumirea devine „Prefix COD". Gol = doar codul.</small>
                    </div>
                    <div class="field">
                        <label>Tip reducere</label>
                        <select name="type">
                            <option value="fixed">Valoare fixă</option>
                            <option value="percent">Procentuală</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Valoare reducere * (lei / %)</label>
                        <input type="number" step="0.01" min="0.01" name="value" required placeholder="ex: 15.00">
                    </div>
                    <div class="field">
                        <label>Valabil de la</label>
                        <input type="datetime-local" name="starts_at">
                    </div>
                    <div class="field">
                        <label>Valabil până la</label>
                        <input type="datetime-local" name="ends_at">
                    </div>
                    <div class="field">
                        <label>Minim produse în coș</label>
                        <input type="number" min="0" step="1" name="min_items_count">
                    </div>
                    <div class="field">
                        <label>Maxim produse în coș</label>
                        <input type="number" min="0" step="1" name="max_items_count">
                    </div>
                </div>

                <div class="vuc-grid">
                    <div class="field vuc-span-full">
                        <label>Doar pentru produse (opțional)</label>
                        <div class="coupon-admin-checklist">
                            <?php foreach ($products as $product): ?>
                                <label class="coupon-admin-checkline">
                                    <input type="checkbox" name="product_ids[]" value="<?= (int) ($product['id'] ?? 0) ?>">
                                    <span><?= htmlspecialchars((string) ($product['name'] ?? ''), ENT_QUOTES) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <label class="coupon-admin-active-check">
                            <input type="checkbox" name="apply_only_selected_products" value="1">
                            <span>Se aplică doar la produsele selectate, dar merge și cu alte produse în coș.</span>
                        </label>
                    </div>
                    <div class="field vuc-span-full">
                        <label>Doar pentru categorii (opțional)</label>
                        <div class="coupon-admin-checklist">
                            <?php foreach ($categories as $category): ?>
                                <label class="coupon-admin-checkline">
                                    <input type="checkbox" name="category_ids[]" value="<?= (int) ($category['id'] ?? 0) ?>">
                                    <span><?= htmlspecialchars((string) ($category['name'] ?? ''), ENT_QUOTES) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <label class="coupon-admin-active-check">
                    <input type="checkbox" name="stacks_with_discounts" value="1" checked>
                    <span>Se cumulează cu alte reduceri / promoții</span>
                </label>
                <small style="display:block;margin:-4px 0 10px;color:var(--ax-text-3);">Debifat: cupoanele reduc doar produsele la preț întreg (produsele deja la promoție sunt ignorate).</small>

                <label class="coupon-admin-active-check">
                    <input type="checkbox" name="is_active" value="1" checked>
                    <span>Cupoane active la import</span>
                </label>

                <div class="vuc-import-actions">
                    <button class="btn" type="submit">Importă cupoanele</button>
                </div>
            </form>
        </details>
    <?php endif; ?>

    <form method="get" action="/admin/coupons/unique" class="vuc-search">
        <input type="hidden" name="view" value="<?= htmlspecialchars($view, ENT_QUOTES) ?>">
        <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>" placeholder="Caută după cod sau denumire...">
        <select name="batch" class="vuc-batch-select">
            <option value="">Toate loturile</option>
            <?php foreach ($batches as $b): ?>
                <option value="<?= htmlspecialchars((string) $b, ENT_QUOTES) ?>" <?= $batch === (string) $b ? 'selected' : '' ?>><?= htmlspecialchars((string) $b, ENT_QUOTES) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="per" class="vuc-batch-select" title="Cupoane pe pagină" style="min-width:120px;">
            <?php foreach ([50, 100, 250, 500] as $pp): ?>
                <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?> / pagină</option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-secondary" type="submit">Filtrează</button>
        <?php if ($q !== '' || $batch !== '' || $perPage !== 50): ?>
            <a class="btn btn-secondary" href="/admin/coupons/unique?view=<?= htmlspecialchars($view, ENT_QUOTES) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <?php if ($view === 'used' && $usedCount > 0): ?>
        <div class="vuc-bulk">
            <form method="post" action="/admin/coupons/unique/action" onsubmit="return confirm('Reactivezi TOATE cupoanele folosite?');">
                <input type="hidden" name="action" value="reactivate_all">
                <button class="btn btn-secondary" type="submit">↺ Reactivează toate</button>
            </form>
            <form method="post" action="/admin/coupons/unique/action" onsubmit="return confirm('Ștergi TOATE cupoanele folosite? Ireversibil.');">
                <input type="hidden" name="action" value="delete_all_used">
                <button class="btn btn-secondary" type="submit">🗑 Șterge toate folosite</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($coupons === []): ?>
        <p class="vuc-empty">
            <?= $view === 'used'
                ? 'Niciun cupon unic folosit încă.'
                : ($q !== '' ? 'Niciun cupon unic pentru căutarea introdusă.' : 'Niciun cupon unic. Importă un lot mai sus.') ?>
        </p>
    <?php else: ?>
        <!-- Formular gol pentru acțiunile în masă (bifele din tabel se leagă prin form="vuc-bulk") -->
        <form id="vuc-bulk" method="post" action="/admin/coupons/unique/action">
            <input type="hidden" name="view" value="<?= htmlspecialchars($view, ENT_QUOTES) ?>">
            <input type="hidden" name="batch" value="<?= htmlspecialchars($batch, ENT_QUOTES) ?>">
            <input type="hidden" name="scope" id="vuc-scope" value="selected">
        </form>

        <div class="vuc-bulkbar">
            <span class="vuc-selinfo"><strong id="vuc-selcount">0</strong> selectate</span>
            <button class="btn btn-secondary btn-sm" type="button" id="vuc-edit-toggle">✎ Editează</button>
            <?php if ($view === 'used'): ?>
                <button class="btn btn-secondary btn-sm" form="vuc-bulk" type="submit" name="action" value="bulk_reactivate" onclick="return vucBulkGuard('Reactivezi cupoanele selectate?')">↺ Reactivează selectate</button>
            <?php endif; ?>
            <button class="btn btn-secondary btn-sm" form="vuc-bulk" type="submit" name="action" value="bulk_delete" onclick="return vucBulkGuard('Ștergi cupoanele selectate? Ireversibil.')">🗑 Șterge selectate</button>
        </div>

        <?php if ($batch !== ''): ?>
            <div class="vuc-batchbar">
                <?php if ($view === 'used'): ?>
                    <button class="btn btn-secondary btn-sm" form="vuc-bulk" type="submit" name="action" value="bulk_reactivate" onclick="return vucBatchGuard('Reactivezi TOATE cupoanele din acest lot?')">↺ Reactivează tot lotul</button>
                <?php endif; ?>
                <button class="btn btn-secondary btn-sm" form="vuc-bulk" type="submit" name="action" value="bulk_delete" onclick="return vucBatchGuard('Ștergi TOATE cupoanele din acest lot? Ireversibil.')">🗑 Șterge tot lotul</button>
            </div>
        <?php endif; ?>

        <div class="vuc-editpanel" id="vuc-editpanel" hidden>
            <p class="vuc-editpanel__hint">Bifează doar câmpurile pe care vrei să le schimbi. Se aplică cupoanelor selectate.</p>
            <div class="vuc-editgrid">
                <div class="vuc-editrow">
                    <label class="vuc-editchk"><input type="checkbox" name="edit_value" value="1" form="vuc-bulk"> Valoare + tip</label>
                    <select name="type" form="vuc-bulk"><option value="fixed">Valoare fixă (lei)</option><option value="percent">Procentuală (%)</option></select>
                    <input type="number" step="0.01" min="0.01" name="value" placeholder="ex: 15.00" form="vuc-bulk">
                </div>
                <div class="vuc-editrow">
                    <label class="vuc-editchk"><input type="checkbox" name="edit_period" value="1" form="vuc-bulk"> Perioadă</label>
                    <label class="vuc-editsub">De la<input type="datetime-local" name="starts_at" form="vuc-bulk"></label>
                    <label class="vuc-editsub">Până la<input type="datetime-local" name="ends_at" form="vuc-bulk"></label>
                </div>
                <div class="vuc-editrow">
                    <label class="vuc-editchk"><input type="checkbox" name="edit_active" value="1" form="vuc-bulk"> Status</label>
                    <select name="is_active_value" form="vuc-bulk"><option value="1">Activ</option><option value="0">Inactiv</option></select>
                </div>
                <div class="vuc-editrow">
                    <label class="vuc-editchk"><input type="checkbox" name="edit_stacks" value="1" form="vuc-bulk"> Cumulare cu reduceri</label>
                    <select name="stacks_value" form="vuc-bulk"><option value="1">Se cumulează</option><option value="0">Nu se cumulează</option></select>
                </div>
<?= \App\Support\Dev::marcaj('bv:cupoane-unice-produse') ?>
<?php
/*
 * WP-21 (C12, din Biovitality 222ed71): rândul „Produse” al editării în masă. Lista bifată înlocuiește produsele fiecărui
 * cupon ales (selectate sau tot lotul); fără niciun produs bifat, cupoanele se aplică la toate produsele. Lista se
 * precompletează din cupoanele alese când au aceleași produse (sau, fără selecție, din lotul afișat); altfel spune că
 * sunt diferite. Produsele cupoanelor vin din datele de mai jos (id => produse, doar la selectate).
 */
$dateProduseCupoane = ['cupoane' => (object) (is_array($produseCupoane ?? null) ? $produseCupoane : []), 'lot' => $batch !== '' ? 1 : 0];
?>
                <div class="vuc-editrow vuc-editrow--top">
                    <label class="vuc-editchk"><input type="checkbox" name="edit_products" value="1" form="vuc-bulk"> Produse</label>
                    <div class="vuc-products">
                        <input type="text" class="coupon-admin-check-search" placeholder="Caută produs..." data-vuc-product-search autocomplete="off">
                        <div class="coupon-admin-checklist" role="group" aria-label="Produsele la care se aplică cupoanele">
                            <?php foreach ($products as $product): ?>
                                <label class="coupon-admin-checkline" data-vuc-product-item data-search="<?= htmlspecialchars((string) ($product['name'] ?? ''), ENT_QUOTES) ?>">
                                    <input type="checkbox" name="product_ids[]" value="<?= (int) ($product['id'] ?? 0) ?>" form="vuc-bulk">
                                    <span><?= htmlspecialchars((string) ($product['name'] ?? ''), ENT_QUOTES) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="coupon-admin-check-empty" data-vuc-product-empty hidden>Niciun produs pentru căutarea introdusă.</p>
                        <label class="coupon-admin-active-check">
                            <input type="checkbox" name="apply_only_selected_products" value="1" form="vuc-bulk">
                            <span>Se aplică doar la produsele selectate, dar merge și cu alte produse în coș.</span>
                        </label>
                        <small class="vuc-products-note">Produsele bifate înlocuiesc lista fiecărui cupon. Dacă nu bifezi niciun produs, cupoanele se aplică la toate produsele. <span data-vuc-products-state></span></small>
                    </div>
                </div>
                <script type="application/json" id="vuc-produse-date"><?= json_encode($dateProduseCupoane, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
                <style>
                .vuc-editrow--top{align-items:flex-start;}
                .vuc-editrow--top .vuc-editchk{padding-top:8px;}
                .vuc-products{flex:1;min-width:260px;max-width:640px;display:flex;flex-direction:column;gap:6px;}
                .vuc-products .coupon-admin-check-search{padding:8px 10px;border:1px solid var(--ax-linie-tare);border-radius:8px;font-size:13.5px;margin-bottom:0;}
                .vuc-products .coupon-admin-checklist{max-height:220px;}
                .vuc-products .coupon-admin-active-check{margin:2px 0 0;font-weight:600;font-size:13.5px;color:var(--ax-text-2);align-items:center;gap:8px;}
                .vuc-products .coupon-admin-active-check input[type=checkbox]{width:16px;height:16px;min-width:16px;min-height:16px;margin:0;}
                .vuc-products-note{color:var(--ax-text-3);font-size:12.5px;line-height:1.45;}
                </style>
                <script>
                // După încărcarea paginii: cupoanele (.vuc-rowcheck) sunt în tabelul de sub panou.
                document.addEventListener('DOMContentLoaded', function(){
                  var date = {};
                  try { date = JSON.parse((document.getElementById('vuc-produse-date') || {}).textContent || '{}') || {}; } catch (e) { return; }
                  var cupoane = date.cupoane || {};
                  var panel = document.getElementById('vuc-editpanel');
                  if(!panel){ return; }
                  var checks = Array.prototype.slice.call(document.querySelectorAll('.vuc-rowcheck'));
                  var selAll = document.getElementById('vuc-selall');
                  var editProducts = panel.querySelector('input[name="edit_products"]');
                  var onlySelected = panel.querySelector('input[name="apply_only_selected_products"]');
                  var productSearch = panel.querySelector('[data-vuc-product-search]');
                  var productItems = Array.prototype.slice.call(panel.querySelectorAll('[data-vuc-product-item]'));
                  var productBoxes = productItems.map(function(item){ return item.querySelector('input[type=checkbox]'); });
                  var productEmpty = panel.querySelector('[data-vuc-product-empty]');
                  var productState = panel.querySelector('[data-vuc-products-state]');
                  // După ce omul a bifat ceva în listă, nu-i mai schimbăm noi bifele.
                  var productsTouched = false;
                  function plain(s){ return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
                  function produse(n){
                    var de = n >= 20 && (n % 100 === 0 || n % 100 >= 20);
                    return n + (de ? ' de' : '') + (n === 1 ? ' produs' : ' produse');
                  }
                  function alCuponului(c){
                    var info = cupoane[c.value] || {p: [], o: 0};
                    return {lista: (info.p || []).join(','), doar: Number(info.o) === 1 ? '1' : '0'};
                  }
                  function bifeazaProduse(ids, doar){
                    if(productsTouched){ return; }
                    var pe = {};
                    ids.forEach(function(id){ pe[id] = true; });
                    productBoxes.forEach(function(b){ b.checked = !!pe[b.value]; });
                    if(onlySelected){ onlySelected.checked = doar; }
                  }
                  function syncProducts(){
                    if(!productState){ return; }
                    // Cupoanele bifate; fără bife, într-un lot filtrat, cele afișate.
                    var surse = checks.filter(function(c){ return c.checked; });
                    var eticheta = 'Cupoanele selectate';
                    if(surse.length === 0 && Number(date.lot) === 1){ surse = checks.slice(); eticheta = 'Cupoanele din lot afișate'; }
                    if(surse.length === 0){ bifeazaProduse([], false); productState.textContent = ''; return; }
                    var primul = alCuponului(surse[0]);
                    var aceleasi = surse.every(function(c){ var x = alCuponului(c); return x.lista === primul.lista && x.doar === primul.doar; });
                    if(!aceleasi){
                      bifeazaProduse([], false);
                      productState.textContent = eticheta + ' au produse diferite; ce bifezi aici le înlocuiește pe toate.';
                      return;
                    }
                    var ids = primul.lista === '' ? [] : primul.lista.split(',');
                    bifeazaProduse(ids, primul.doar === '1');
                    productState.textContent = ids.length === 0
                      ? eticheta + ' se aplică acum la toate produsele.'
                      : eticheta + ' se aplică acum la ' + produse(ids.length) + (productsTouched ? '.' : ', bifate mai sus.');
                  }
                  function touchProducts(){
                    productsTouched = true;
                    if(editProducts){ editProducts.checked = true; }
                    syncProducts();
                  }
                  productBoxes.forEach(function(b){ b.addEventListener('change', touchProducts); });
                  if(onlySelected){ onlySelected.addEventListener('change', touchProducts); }
                  if(productSearch){
                    productSearch.addEventListener('input', function(){
                      var q = plain(productSearch.value.trim());
                      var vizibile = 0;
                      productItems.forEach(function(item){
                        var ok = q === '' || plain(item.getAttribute('data-search')).indexOf(q) >= 0;
                        item.style.display = ok ? '' : 'none';
                        if(ok){ vizibile++; }
                      });
                      if(productEmpty){ productEmpty.hidden = vizibile > 0; }
                    });
                  }
                  // Selecția cupoanelor (rândurile și „Selectează tot”) schimbă lista; după scriptul paginii, care le numără.
                  checks.forEach(function(c){ c.addEventListener('change', syncProducts); });
                  if(selAll){ selAll.addEventListener('change', syncProducts); }
                  syncProducts();
                });
                </script>
<?= \App\Support\Dev::marcaj('bv:cupoane-unice-produse', true) ?>
            </div>
            <div class="vuc-editpanel__actions">
                <button class="btn" form="vuc-bulk" type="submit" name="action" value="bulk_edit" onclick="return vucBulkGuard('Aplici modificările la cupoanele selectate?')">Aplică la selectate</button>
                <?php if ($batch !== ''): ?>
                    <button class="btn" form="vuc-bulk" type="submit" name="action" value="bulk_edit" onclick="return vucBatchGuard('Aplici modificările la TOATE cupoanele din lotul „<?= htmlspecialchars($batch, ENT_QUOTES) ?>”?')">Aplică la tot lotul „<?= htmlspecialchars($batch, ENT_QUOTES) ?>”</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="vuc-table-wrap">
            <table class="vuc-table">
                <thead>
                    <tr>
                        <th class="vuc-check-col"><input type="checkbox" id="vuc-selall" title="Selectează tot"></th>
                        <th>Cod</th>
                        <th>Denumire</th>
                        <th>Reducere</th>
                        <th>Status</th>
                        <th><?= $view === 'used' ? 'Folosit la' : 'Lot' ?></th>
                        <?php if ($view === 'used'): ?><th>Comandă</th><?php endif; ?>
                        <th class="vuc-actions-col">Acțiuni</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($coupons as $c): ?>
                    <?php
                    $cid = (int) ($c['id'] ?? 0);
                    $isActive = ((int) ($c['is_active'] ?? 0)) === 1;
                    $type = (string) ($c['type'] ?? 'fixed');
                    $value = (float) ($c['value'] ?? 0);
                    $reduce = $type === 'percent'
                        ? ('-' . rtrim(rtrim(number_format($value, 2), '0'), '.') . '%')
                        : ('-' . number_format($value, 2) . ' lei');
                    ?>
                    <tr>
                        <td class="vuc-check-col"><input type="checkbox" class="vuc-rowcheck" name="ids[]" value="<?= $cid ?>" form="vuc-bulk"></td>
                        <td><code><?= htmlspecialchars((string) ($c['code'] ?? ''), ENT_QUOTES) ?></code></td>
                        <td><?= htmlspecialchars((string) ($c['name'] ?? ''), ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($reduce, ENT_QUOTES) ?></td>
                        <td>
                            <?php if ($view === 'used'): ?>
                                <span class="vuc-pill vuc-pill--used">Folosit</span>
                            <?php else: ?>
                                <span class="vuc-pill <?= $isActive ? 'vuc-pill--on' : 'vuc-pill--off' ?>"><?= $isActive ? 'Activ' : 'Inactiv' ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $view === 'used'
                                ? htmlspecialchars((string) ($c['used_at_local'] ?? ($c['used_at'] ?? '')), ENT_QUOTES)
                                : htmlspecialchars((string) ($c['import_batch'] ?? ''), ENT_QUOTES) ?>
                        </td>
                        <?php if ($view === 'used'): ?>
                            <?php $ordNo = trim((string) ($c['order_number'] ?? '')); ?>
                            <td>
                                <?php if ($ordNo !== ''): ?>
                                    <a class="btn btn-secondary btn-sm" href="/admin/orders?q=<?= rawurlencode($ordNo) ?>" title="Vezi comanda">#<?= htmlspecialchars($ordNo, ENT_QUOTES) ?></a>
                                <?php else: ?>
                                    <span class="vuc-muted">—</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td class="vuc-actions-col">
                            <a class="btn btn-secondary btn-sm" href="/admin/coupons?coupon=<?= $cid ?>">Editează</a>
                            <?php if ($view === 'used'): ?>
                                <form method="post" action="/admin/coupons/unique/action" style="display:inline">
                                    <input type="hidden" name="action" value="reactivate">
                                    <input type="hidden" name="id" value="<?= $cid ?>">
                                    <input type="hidden" name="view" value="used">
                                    <button class="btn btn-sm" type="submit">Reactivează</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="/admin/coupons/unique/action" style="display:inline" onsubmit="return confirm('Ștergi acest cupon?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $cid ?>">
                                <input type="hidden" name="view" value="<?= htmlspecialchars($view, ENT_QUOTES) ?>">
                                <button class="btn btn-secondary btn-sm" type="submit">Șterge</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="vuc-pagination">
                <?php if ($page > 1): ?>
                    <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($pageUrl($page - 1), ENT_QUOTES) ?>">‹ Anterior</a>
                <?php endif; ?>
                <span class="vuc-page-info">Pagina <?= $page ?> din <?= $totalPages ?> (<?= $totalFiltered ?> cupoane)</span>
                <?php if ($page < $totalPages): ?>
                    <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($pageUrl($page + 1), ENT_QUOTES) ?>">Următor ›</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<script>
(function(){
  var selAll = document.getElementById('vuc-selall');
  var checks = Array.prototype.slice.call(document.querySelectorAll('.vuc-rowcheck'));
  var countEl = document.getElementById('vuc-selcount');
  var editToggle = document.getElementById('vuc-edit-toggle');
  var panel = document.getElementById('vuc-editpanel');
  function updateCount(){
    var n = checks.filter(function(c){return c.checked;}).length;
    if(countEl){ countEl.textContent = n; }
    if(selAll){ selAll.checked = n>0 && n===checks.length; selAll.indeterminate = n>0 && n<checks.length; }
  }
  if(selAll){ selAll.addEventListener('change', function(){ checks.forEach(function(c){ c.checked = selAll.checked; }); updateCount(); }); }
  checks.forEach(function(c){ c.addEventListener('change', updateCount); });
  if(editToggle && panel){ editToggle.addEventListener('click', function(){ panel.hidden = !panel.hidden; }); }
  var scopeEl = document.getElementById('vuc-scope');
  window.vucBulkGuard = function(msg){
    var n = checks.filter(function(c){return c.checked;}).length;
    if(n===0){ alert('Selectează cel puțin un cupon.'); return false; }
    if(scopeEl){ scopeEl.value = 'selected'; }
    return confirm(msg);
  };
  window.vucBatchGuard = function(msg){
    if(scopeEl){ scopeEl.value = 'batch'; }
    return confirm(msg);
  };
  updateCount();
})();
</script>

<style>

.vuc-import{border:1px solid var(--ax-linie);border-radius:12px;padding:6px 16px;margin-bottom:18px;background:var(--ax-suprafata-2);}
.vuc-import>summary{cursor:pointer;font-weight:700;padding:10px 0;color:var(--ax-text);}
.vuc-import-form{padding:8px 0 14px;}
.vuc-sub{margin:16px 0 8px;font-size:14px;color:var(--ax-text-2);}
.vuc-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:10px;}
.vuc-grid .field{display:flex;flex-direction:column;gap:5px;}
.vuc-grid .field label{font-size:13px;font-weight:600;color:var(--ax-text-2);}
.vuc-grid .field small{color:var(--ax-text-3);font-size:12px;}
.vuc-span-full{grid-column:1 / -1;}
.vuc-import-actions{margin-top:14px;}

.vuc-search{display:flex;gap:8px;margin:6px 0 14px;flex-wrap:wrap;}
.vuc-search input[type=text]{flex:1;min-width:220px;padding:9px 12px;border:1px solid var(--ax-linie-tare);border-radius:8px;}
.vuc-batch-select{padding:9px 12px;border:1px solid var(--ax-linie-tare);border-radius:8px;background:var(--ax-suprafata);min-width:180px;}

.vuc-bulkbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:4px 0 12px;padding:10px 12px;background:var(--ax-suprafata-2);border:1px solid #eef0f2;border-radius:10px;}
.vuc-selinfo{color:var(--ax-text-2);font-size:13px;}
.vuc-batchbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 12px;padding:10px 12px;background:#fdf2f6;border:1px solid #f6d6e0;border-radius:10px;}
.vuc-batchbar__label{color:#8a2148;font-size:13px;}
.vuc-editpanel__actions{display:flex;gap:8px;flex-wrap:wrap;}
.vuc-muted{color:var(--ax-text-3);}
.vuc-editpanel{border:1px solid var(--ax-linie);border-radius:12px;padding:14px 16px;margin:0 0 14px;background:var(--ax-suprafata);}
.vuc-editpanel__hint{margin:0 0 12px;font-size:13px;color:var(--ax-text-3);}
.vuc-editgrid{display:flex;flex-direction:column;gap:12px;margin-bottom:12px;}
.vuc-editrow{display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.vuc-editchk{min-width:190px;font-weight:600;font-size:13.5px;color:var(--ax-text-2);display:inline-flex;align-items:center;gap:8px;}
.vuc-editrow select,.vuc-editrow input[type=number],.vuc-editrow input[type=datetime-local]{padding:8px 10px;border:1px solid var(--ax-linie-tare);border-radius:8px;font-size:13.5px;}
.vuc-editsub{display:inline-flex;flex-direction:column;gap:3px;font-size:12px;color:var(--ax-text-3);}
.vuc-check-col{width:34px;text-align:center;}

.vuc-bulk{display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;}
.vuc-bulk form{display:inline;}

.vuc-empty{color:var(--ax-text-3);font-style:italic;padding:16px 0;}

.vuc-table-wrap{overflow-x:auto;}
.vuc-table{width:100%;border-collapse:collapse;font-size:14px;}
.vuc-table th,.vuc-table td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--ax-linie);vertical-align:middle;}
.vuc-table th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--ax-text-3);}
.vuc-table code{background:var(--ax-suprafata-2);padding:2px 7px;border-radius:6px;font-size:13px;}
.vuc-actions-col{white-space:nowrap;display:flex;gap:6px;align-items:center;}
.btn-sm{padding:6px 12px;font-size:13px;}

.vuc-pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;}
.vuc-pill--on{background:var(--ax-ok-fundal);color:var(--ax-accent);}
.vuc-pill--off{background:var(--ax-suprafata-2);color:var(--ax-text-3);}
.vuc-pill--used{background:var(--ax-atentie-fundal);color:var(--ax-atentie);}

.vuc-pagination{display:flex;align-items:center;gap:14px;margin-top:16px;}
.vuc-page-info{color:var(--ax-text-3);font-size:13px;}

@media(max-width:720px){.vuc-grid{grid-template-columns:1fr;}}
</style>
