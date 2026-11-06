<?php
$partners = is_array($partners ?? null) ? $partners : [];
// În tabel intră doar partenerii activi; cardurile de mai sus îi arată pe toți,
// ca un partener scos temporar din comparație să poată fi repus.
$partnersInTable = is_array($partnersInTable ?? null) ? $partnersInTable : $partners;
$rows = is_array($rows ?? null) ? $rows : [];
$editing = is_array($editing ?? null) ? $editing : null;
$today = (string) ($today ?? date('Y-m-d'));
$editId = (int) ($editing['id'] ?? 0);

$esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$bani = static fn (float $v): string => number_format($v, 2);
// Valoarea „brută”, fără separator de mii: intră în `value` de input number
// și în atributele citite de JS, unde „1,234.56” ar fi ilizibil.
$brut = static fn (float $v): string => number_format($v, 2, '.', '');
$zi = static function (?string $v): string {
    $v = trim((string) $v);
    if ($v === '') {
        return '';
    }
    $ts = strtotime($v);
    return $ts !== false ? date('d.m.Y', $ts) : $v;
};
$perioada = static function (?string $de, ?string $pana) use ($zi): string {
    $d = $zi($de);
    $p = $zi($pana);
    if ($d === '' && $p === '') {
        return 'fără perioadă';
    }
    if ($d === '') {
        return 'până la ' . $p;
    }
    if ($p === '') {
        return 'din ' . $d;
    }
    return $d . ' – ' . $p;
};

// Rândul de linkuri se întinde peste toate coloanele în afară de cea fixă.
$coloaneRamase = 1 + (count($partnersInTable) * 2);
?>
<style>
/* Regulile de mai jos dau `display` explicit unor elemente pe care le ascundem
   cu atributul `hidden`; fără asta ele ar bate regula implicită a browserului
   și etichetele goale ar rămâne vizibile. */
.panel [hidden] { display:none !important; }
/* Tabelul e lat cât numărul de parteneri: derulare orizontală, produsul rămâne fix. */
.cmp-box { background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;margin:0 0 20px; }
.cmp-box h3 { margin:0 0 4px; }
.cmp-hint { margin:0 0 14px;color:#6b7280;font-size:13px; }
.cmp-partner-form { display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end; }
.cmp-partner-form .field { margin:0; }
.cmp-checks { display:flex;gap:16px;align-items:center;padding-bottom:8px; }
.cmp-checks label { display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#374151;margin:0; }
.cmp-form-actions { display:flex;gap:8px;align-items:center; }
/* Partenerii stau pe un singur rând care se derulează. Înainte se rupeau pe
   trei-patru rânduri și mâncau jumătate de ecran înainte să ajungi la tabel,
   care e de fapt lucrul pentru care ai deschis pagina. */
.cmp-partner-strip { position:relative;margin-top:16px; }
.cmp-partner-list { display:flex;flex-wrap:nowrap;gap:10px;overflow-x:auto;
    padding:2px 2px 10px;scroll-snap-type:x proximity;overscroll-behavior-x:contain; }
.cmp-partner-list::-webkit-scrollbar { height:8px; }
.cmp-partner-list::-webkit-scrollbar-thumb { background:#d1d5db;border-radius:999px; }
.cmp-partner-list::-webkit-scrollbar-track { background:transparent; }
/* `min-width:0` nu e de decor: fara el, un card cu adresa lunga isi impune
   latimea continutului si iese de trei ori mai lat decat vecinii lui. */
.cmp-partner-card { flex:0 0 220px;min-width:0;max-width:220px;display:flex;flex-direction:column;
    scroll-snap-align:start;border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;background:#f9fafb; }
.cmp-partner-card strong { display:block;font-size:14px;line-height:1.3; }
/* Adresa se taie cu „…": una cu parametri de campanie ar umple singură cardul. */
.cmp-partner-card a.cmp-partner-site { display:block;font-size:12px;color:#2563eb;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px; }
.cmp-partner-card .cmp-chip { white-space:normal; }
.cmp-partner-card-actions { display:flex;gap:6px;margin-top:auto;padding-top:8px; }
.cmp-partner-card-actions .btn { padding:4px 10px;font-size:12px; }
/* Săgețile apar doar cât mai e unde derula. */
.cmp-slide { position:absolute;top:calc(50% - 5px);transform:translateY(-50%);z-index:2;
    width:32px;height:32px;border-radius:999px;border:1px solid #e5e7eb;background:#fff;color:#374151;
    font-size:15px;line-height:1;cursor:pointer;box-shadow:0 4px 12px rgba(15,23,42,.14); }
.cmp-slide:hover { background:#f8fafc;border-color:#cbd5e1; }
.cmp-slide--stanga { left:-8px; }
.cmp-slide--dreapta { right:-8px; }

.cmp-toolbar { display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:0 0 14px; }
.cmp-toolbar input[type="search"] { min-width:230px;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px; }
.cmp-toolbar select { padding:9px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;max-width:280px; }
.cmp-sim { display:flex;align-items:center;gap:8px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:7px 12px;font-size:13px;font-weight:600;color:#1e3a8a; }
.cmp-sim input { width:74px;padding:6px 8px;border:1px solid #93c5fd;border-radius:6px; }
.cmp-sim small { font-weight:500;color:#3b82f6; }
.cmp-count { font-size:13px;color:#6b7280; }
.cmp-reset { font-size:12px;color:#2563eb;background:none;border:0;cursor:pointer;text-decoration:underline;padding:0; }

/* Alegerea partenerilor stă într-un `details`: se deschide fără o linie de JS
   și se închide singur la clic în afară, dacă browserul o susține. */
.cmp-pick { position:relative; }
.cmp-pick summary { list-style:none;cursor:pointer;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;background:#fff;font-size:13px;font-weight:600;color:#374151; }
.cmp-pick summary::-webkit-details-marker { display:none; }
.cmp-pick summary::after { content:' ▾';color:#9ca3af; }
.cmp-pick-menu { position:absolute;z-index:20;top:calc(100% + 6px);left:0;min-width:240px;max-height:320px;overflow:auto;background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 12px 28px rgba(15,23,42,.14);padding:10px; }
.cmp-pick-menu label { display:flex;align-items:center;gap:8px;padding:6px 4px;font-size:13px;font-weight:500;color:#374151;cursor:pointer;margin:0; }
.cmp-pick-menu label:hover { background:#f8fafc;border-radius:6px; }
.cmp-pick-actions { display:flex;gap:10px;margin-top:8px;padding-top:8px;border-top:1px solid #f1f5f9; }

/* Cutia tabelului se derulează în ambele direcții și are înălțimea ecranului:
   așa antetul rămâne sub ochi oricât de jos ai coborî, iar bara de derulare
   orizontală stă mereu la marginea de jos a ferestrei, la îndemână de la orice
   produs — nu tocmai sub ultimul rând, la capătul unei liste de sute. */
.cmp-table-wrap { overflow:auto;border:1px solid #e5e7eb;border-radius:12px;background:#fff;max-height:calc(100vh - 210px);min-height:320px;overscroll-behavior:contain; }
.cmp-table { border-collapse:separate;border-spacing:0;font-size:13px;width:max-content;min-width:100%; }
.cmp-table th, .cmp-table td { border-bottom:1px solid #eef0f3;padding:10px 12px;vertical-align:top;text-align:left; }
/* Antetul e lipit de marginea de sus a cutiei. Al doilea rând coboară exact cât
   e de înalt primul — înălțimea o măsoară JS-ul, fiindcă depinde de fonturi. */
.cmp-table thead th { position:sticky;top:0;z-index:4;background:#f8fafc;color:#374151;font-size:12px;text-transform:uppercase;letter-spacing:.02em;box-shadow:inset 0 -1px 0 #e5e7eb; }
.cmp-table thead tr:nth-child(2) th { top:var(--cmp-head-h, 46px); }
.cmp-table thead th.cmp-sub { text-transform:none;font-size:11px;color:#6b7280;background:#f1f5f9;min-width:210px; }
/* Colțul din stânga-sus e lipit în ambele direcții, deci trebuie să treacă
   peste amândouă. */
.cmp-table thead th.cmp-sticky, .cmp-table thead th.cmp-our { z-index:6; }
.cmp-partner-head { border-left:2px solid #e2e8f0; }
.cmp-partner-head small { display:block;font-weight:500;text-transform:none;color:#6b7280;font-size:11px;margin-top:2px; }
/* Produsul și prețul nostru rămân pe loc: sunt reperul oricărei comparații,
   iar tabelul se derulează orizontal pe sub ele. Lățimile sunt fixe pentru că
   a doua coloană lipită are nevoie de un `left` exact. */
.cmp-sticky { position:sticky;left:0;z-index:3;background:#fff;width:260px;min-width:260px;max-width:260px; }
.cmp-our, .cmp-our-cell { position:sticky;left:260px;z-index:3;background:#fff;width:172px;min-width:172px;box-shadow:1px 0 0 #e5e7eb; }
.cmp-table thead th.cmp-sticky, .cmp-table thead th.cmp-our { background:#f8fafc; }
.cmp-row:hover td { background:#fcfdff; }
.cmp-row:hover td.cmp-sticky, .cmp-row:hover td.cmp-our-cell { background:#fcfdff; }

.cmp-prod-toggle { display:block;width:100%;text-align:left;background:none;border:0;padding:0;cursor:pointer;font:inherit;color:inherit; }
.cmp-prod-name { display:block;font-weight:600;color:#111827;line-height:1.3; }
.cmp-prod-meta { display:block;color:#9ca3af;font-size:11px;margin-top:2px; }
.cmp-prod-hint { display:inline-block;margin-top:5px;font-size:11px;color:#2563eb; }
.cmp-our-price { display:block;font-weight:700;font-size:14px;color:#111827; }
.cmp-our-sim { display:block;margin-top:3px;font-size:11px;font-weight:700;color:#1d4ed8; }
/* Simulare doar pe rândul ăsta, peste cea generală din bara de sus. */
.cmp-row-sim { display:flex;align-items:center;gap:4px;margin-top:6px;font-size:11px;color:#6b7280; }
.cmp-row-sim input { width:52px;padding:3px 5px;border:1px solid #bfdbfe;border-radius:5px;font-size:11px;text-align:right; }
.cmp-row-sim input:not(:placeholder-shown) { border-color:#2563eb;background:#eff6ff;font-weight:700;color:#1d4ed8; }

.cmp-price-line { display:flex;align-items:center;gap:6px; }
.cmp-input { width:96px;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px; }
.cmp-unit { color:#9ca3af;font-size:11px; }
.cmp-saved { font-size:11px;font-weight:700;color:#16a34a;opacity:0;transition:opacity .2s; }
.cmp-saved.on { opacity:1; }
.cmp-saved.err { color:#dc2626; }
.cmp-oos { display:inline-flex;align-items:center;gap:6px;margin-top:8px;padding:4px 9px;border:1px solid #e5e7eb;border-radius:999px;font-size:11px;font-weight:600;color:#6b7280;cursor:pointer;background:#fff; }
.cmp-oos.on { border-color:#fecaca;background:#fef2f2;color:#b91c1c; }
/* Bifa de campanie stă la fiecare produs, nu la partener: o promoție la un
   singur produs nu trebuie să facă tot partenerul să pară în campanie. */
.cmp-camp-toggle { display:inline-flex;align-items:center;gap:6px;margin-top:8px;margin-left:6px;padding:4px 9px;border:1px solid #e5e7eb;border-radius:999px;font-size:11px;font-weight:600;color:#6b7280;cursor:pointer;background:#fff; }
.cmp-camp-toggle.on { border-color:#d8b4fe;background:#faf5ff;color:#7e22ce; }
.cmp-campaign-fields { margin-top:8px;padding:8px;border:1px dashed #d8b4fe;border-radius:8px;background:#faf5ff;display:flex;flex-wrap:wrap;gap:6px;align-items:center; }
.cmp-mini-label { font-size:11px;font-weight:700;color:#7e22ce;text-transform:uppercase;width:100%; }
.cmp-campaign-fields input { padding:5px 7px;border:1px solid #d8b4fe;border-radius:6px;font-size:12px; }
.cmp-campaign-fields input[type="number"] { width:92px; }

.cmp-diff-cell { min-width:190px; }
.cmp-diff-ron { font-weight:700;font-size:14px; }
.cmp-diff-pct { display:block;font-size:12px;margin-top:1px; }
.cmp-diff--good .cmp-diff-ron, .cmp-diff--good .cmp-diff-pct { color:#15803d; }
.cmp-diff--bad .cmp-diff-ron, .cmp-diff--bad .cmp-diff-pct { color:#b91c1c; }
.cmp-diff--none .cmp-diff-ron { color:#9ca3af;font-weight:500;font-size:13px; }
.cmp-chip { display:inline-block;margin-top:6px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;line-height:1.5; }
.cmp-chip--campaign { background:#f3e8ff;color:#6b21a8;max-width:260px;white-space:normal; }
.cmp-chip--oos { background:#fee2e2;color:#b91c1c; }
.cmp-chip--sale { background:#dcfce7;color:#166534;font-size:10px;margin-top:4px; }

.cmp-links-row td { background:#f8fafc;border-bottom:2px solid #e5e7eb; }
.cmp-links-grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px; }
.cmp-link-item { display:flex;flex-direction:column;gap:4px; }
.cmp-link-item label { font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase; }
.cmp-link-row { display:flex;gap:6px;align-items:center; }
.cmp-link-row input { flex:1;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:12px; }
.cmp-link-open { padding:5px 10px;font-size:12px;white-space:nowrap; }

.cmp-footer { display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:16px; }
.cmp-footer p { margin:0;color:#6b7280;font-size:12px; }
.cmp-empty { padding:24px;color:#6b7280;font-style:italic; }
/* ── Rânduri alternate ─────────────────────────────────────────────────
   Tabelul e lat și se derulează orizontal; fără dungi, ochiul pierde
   rândul între prima coloană și partenerul din dreapta. Culoarea se pune
   pe fiecare celulă, nu pe `tr`: coloanele lipite (sticky) au fundalul
   lor și ar rămâne albe. */
.cmp-table tbody tr.cmp-row > td { background:#ffffff; }
.cmp-table tbody tr.cmp-row--alt > td { background:#f7f9fc; }
.cmp-row:hover > td { background:#eef4ff !important; }

/* „Doar în magazin": nu e pe site-ul partenerului, dar îl are fizic.
   Stă lângă „Out of stock", în celula partenerului. */
.cmp-only-store {
    display:flex;align-items:center;gap:5px;margin-top:4px;
    font-size:11px;color:#6b7280;cursor:pointer;user-select:none;
}
.cmp-only-store input { margin:0;cursor:pointer; }
.cmp-only-store.on { color:#b45309;font-weight:700; }
.cmp-chip--store { background:#fef3c7;color:#92400e;border-color:#fcd34d; }

.btn-danger { background:#dc2626;border-color:#dc2626;color:#fff; }
.btn-danger:hover { background:#b91c1c;border-color:#b91c1c; }
</style>

<?php ob_start(); ?>
    <a class="btn btn-secondary" href="/admin/competition/export"
       title="Descarcă toată situația curentă: produse, parteneri, prețuri, diferențe, campanii, stoc și linkuri">⭳ Export Excel</a>
    <!-- Golirea șterge definitiv, de asta întreabă întâi și trimite omul
         la export. Partenerii rămân: ei sunt coloanele, iar ștergerea
         lor are butonul ei, la fiecare card. -->
    <form method="post" action="/admin/competition/clear" id="cmp-clear-form" style="margin:0;">
        <?= \App\Support\AdminCsrf::camp() . "\n" ?>
        <button type="submit" class="btn btn-danger" id="cmp-clear-btn"
                title="Șterge toate prețurile, campaniile și bifele din tabel. Partenerii rămân.">🗑 Golește tabelul</button>
    </form>
<?php $antetActiuni = (string) ob_get_clean(); ?>
<?php
admin_componenta('antet-pagina', [
    'titlu' => 'Analiza competiție',
    'nivel' => 1,
    'subtitlu' => 'Prețurile noastre față de cele ale partenerilor, produs cu produs. Prețurile se salvează singure, pe măsură ce le completezi.',
    'actiuni' => $antetActiuni,
]);
?>
<section class="panel">
    <!-- Parteneri: fiecare adaugă în tabel o pereche de coloane -->
    <div class="cmp-box">
        <h3><?= $editId > 0 ? 'Editează partenerul' : 'Adaugă partener' ?></h3>
        <p class="cmp-hint">Fiecare partener primește în tabel două coloane: prețul lui și diferența față de prețul nostru. Campania se bifează în tabel, la fiecare produs în parte; „Campanie” de aici doar stabilește perioada implicită, pentru produsele la care n-o completezi separat.</p>
        <form method="post" action="/admin/competition/partners" class="cmp-partner-form">
            <?= \App\Support\AdminCsrf::camp() . "\n" ?>
            <input type="hidden" name="id" value="<?= $editId ?>">
            <div class="field">
                <label>Nume partener *</label>
                <input type="text" name="name" required maxlength="190" value="<?= $esc((string) ($editing['name'] ?? '')) ?>" placeholder="ex: Farmacia X">
            </div>
            <div class="field">
                <label>Site (opțional)</label>
                <input type="text" name="website" maxlength="255" value="<?= $esc((string) ($editing['website'] ?? '')) ?>" placeholder="ex: farmacia-x.ro">
            </div>
            <div class="field">
                <label>Campanie de la</label>
                <input type="date" name="campaign_start" value="<?= $esc((string) ($editing['campaign_start'] ?? '')) ?>">
            </div>
            <div class="field">
                <label>Campanie până la</label>
                <input type="date" name="campaign_end" value="<?= $esc((string) ($editing['campaign_end'] ?? '')) ?>">
            </div>
            <div class="field">
                <label>Ordine afișare</label>
                <input type="number" step="1" name="sort_order" value="<?= (int) ($editing['sort_order'] ?? 0) ?>">
            </div>
            <div class="field cmp-checks">
                <label title="Perioada folosită la produsele unde nu completezi alte date">
                    <input type="checkbox" name="has_campaign" value="1" <?= ((int) ($editing['has_campaign'] ?? 0)) === 1 ? 'checked' : '' ?>>
                    Campanie generală
                </label>
                <label>
                    <input type="checkbox" name="is_active" value="1" <?= ((int) ($editing['is_active'] ?? 1)) === 1 ? 'checked' : '' ?>>
                    Activ
                </label>
            </div>
            <div class="cmp-form-actions">
                <button class="btn" type="submit"><?= $editId > 0 ? 'Salvează' : 'Adaugă' ?></button>
                <?php if ($editId > 0): ?>
                    <a class="btn btn-secondary" href="/admin/competition">Anulează</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($partners !== []): ?>
            <div class="cmp-partner-strip">
                <button type="button" class="cmp-slide cmp-slide--stanga" id="cmp-slide-stanga"
                        aria-label="Partenerii dinainte" hidden>‹</button>
                <button type="button" class="cmp-slide cmp-slide--dreapta" id="cmp-slide-dreapta"
                        aria-label="Partenerii următori" hidden>›</button>
            <div class="cmp-partner-list" id="cmp-partner-list" tabindex="0" role="group" aria-label="Parteneri">
                <?php foreach ($partners as $partner): ?>
                    <?php $pid = (int) $partner['id']; ?>
                    <div class="cmp-partner-card">
                        <strong><?= $esc((string) $partner['name']) ?></strong>
                        <?php if ((string) $partner['website'] !== ''): ?>
                            <a class="cmp-partner-site" href="<?= $esc((string) $partner['website']) ?>"
                               target="_blank" rel="noreferrer noopener"
                               title="<?= $esc((string) $partner['website']) ?>"><?= $esc((string) $partner['website']) ?></a>
                        <?php endif; ?>
                        <div style="margin-top:6px;">
                            <?php if ($partner['has_campaign'] === 1): ?>
                                <span class="cmp-chip cmp-chip--campaign">Perioadă implicită · <?= $esc($perioada($partner['campaign_start'], $partner['campaign_end'])) ?></span>
                            <?php endif; ?>
                            <?php if ($partner['is_active'] !== 1): ?>
                                <span class="cmp-chip cmp-chip--oos">Inactiv</span>
                            <?php endif; ?>
                        </div>
                        <div class="cmp-partner-card-actions">
                            <a class="btn btn-secondary" href="/admin/competition?edit=<?= $pid ?>">Editează</a>
                            <form method="post" action="/admin/competition/partners/<?= $pid ?>/delete" onsubmit="return confirm(<?= $esc((string) json_encode('Ștergi partenerul „' . (string) $partner['name'] . '” și toate prețurile lui?', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)) ?>);">
                                <?= \App\Support\AdminCsrf::camp() . "\n" ?>
                                <button class="btn btn-secondary" type="submit">Șterge</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($partners === []): ?>
        <p class="cmp-empty">Adaugă primul partener ca să apară coloanele de comparație.</p>
    <?php elseif ($rows === []): ?>
        <p class="cmp-empty">Nu există produse în catalog.</p>
    <?php else: ?>

    <div class="cmp-toolbar">
        <input type="search" id="cmp-search" placeholder="Caută produs după denumire, SKU sau categorie…" autocomplete="off" aria-label="Caută produs">

        <select id="cmp-filter-product" aria-label="Filtrează după produs">
            <option value="">Toate produsele</option>
            <?php foreach ($rows as $row): ?>
                <?php $p = $row['product']; ?>
                <option value="<?= (int) $p['id'] ?>">
                    <?= $esc((string) $p['name']) ?><?= (string) $p['sku'] !== '' ? ' · ' . $esc((string) $p['sku']) : '' ?>
                </option>
            <?php endforeach; ?>
        </select>

        <!-- Partenerii afișați. Ascunderea e doar în pagină: prețurile rămân salvate. -->
        <details class="cmp-pick" id="cmp-pick-partners">
            <summary id="cmp-pick-summary">Parteneri: toți (<?= count($partnersInTable) ?>)</summary>
            <div class="cmp-pick-menu">
                <?php foreach ($partnersInTable as $partner): ?>
                    <label>
                        <input type="checkbox" class="cmp-pick-partner" value="<?= (int) $partner['id'] ?>" checked>
                        <?= $esc((string) $partner['name']) ?>
                    </label>
                <?php endforeach; ?>
                <div class="cmp-pick-actions">
                    <button type="button" class="cmp-reset" id="cmp-pick-all">Toți</button>
                    <button type="button" class="cmp-reset" id="cmp-pick-none">Niciunul</button>
                </div>
            </div>
        </details>

        <span class="cmp-count" id="cmp-count"><?= count($rows) ?> produse</span>
        <button type="button" class="cmp-reset" id="cmp-reset" hidden>Șterge filtrele</button>

        <label class="cmp-sim">
            Simulează o reducere la toate prețurile noastre
            <input type="number" id="cmp-discount" min="0" max="95" step="0.5" placeholder="0"> %
            <small>doar în tabel; la fiecare produs poți pune alt procent</small>
        </label>
    </div>

    <form method="post" action="/admin/competition/save-all" id="cmp-form">
        <?= \App\Support\AdminCsrf::camp() . "\n" ?>
        <div class="cmp-table-wrap">
            <table class="cmp-table" id="cmp-table">
                <thead>
                    <tr>
                        <th class="cmp-sticky" rowspan="2">Produs</th>
                        <th class="cmp-our" rowspan="2">Prețul nostru</th>
                        <?php foreach ($partnersInTable as $partner): ?>
                            <th class="cmp-partner-head" colspan="2" data-partner-col="<?= (int) $partner['id'] ?>">
                                <?= $esc((string) $partner['name']) ?>
                                <?php if ($partner['has_campaign'] === 1): ?>
                                    <small>perioadă implicită: <?= $esc($perioada($partner['campaign_start'], $partner['campaign_end'])) ?></small>
                                <?php endif; ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <?php foreach ($partnersInTable as $partner): ?>
                            <th class="cmp-sub" data-partner-col="<?= (int) $partner['id'] ?>">Preț la partener (RON)</th>
                            <th class="cmp-sub" data-partner-col="<?= (int) $partner['id'] ?>">Diferență preț</th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php $indexRand = 0; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $product = $row['product'];
                    $productId = (int) $product['id'];
                    $pretulNostru = (float) $product['price'];
                    $cautare = implode(' ', array_filter([
                        (string) $product['name'],
                        (string) $product['sku'],
                        (string) $product['category_name'],
                    ], static fn (string $bucata): bool => trim($bucata) !== ''));
                    ?>
                    <?php
                    // Dunga se pune din PHP, nu din `nth-of-type`: între rândurile
                    // de produse mai stă câte un rând cu linkuri, iar CSS-ul l-ar
                    // număra și pe el, așa că toate ar ieși de aceeași culoare.
                    $randAlternat = ($indexRand % 2) === 1;
                    $indexRand++;
                    ?>
                    <tr class="cmp-row <?= $randAlternat ? 'cmp-row--alt' : '' ?>" data-search="<?= $esc($cautare) ?>" data-product="<?= $productId ?>">
                        <td class="cmp-sticky">
                            <button type="button" class="cmp-prod-toggle" data-toggle-links="<?= $productId ?>"
                                    aria-expanded="false" title="Deschide câmpurile de link către pagina produsului la fiecare partener">
                                <span class="cmp-prod-name"><?= $esc((string) $product['name']) ?></span>
                                <span class="cmp-prod-meta">
                                    <?= $esc((string) $product['sku']) !== '' ? $esc((string) $product['sku']) : 'fără SKU' ?>
                                    <?= (string) $product['category_name'] !== '' ? ' · ' . $esc((string) $product['category_name']) : '' ?>
                                </span>
                                <span class="cmp-prod-hint">🔗 Linkuri la parteneri</span>
                            </button>
                        </td>
                        <td class="cmp-our-cell">
                            <strong class="cmp-our-price" data-our-base="<?= $brut($pretulNostru) ?>"><?= $bani($pretulNostru) ?> RON</strong>
                            <span class="cmp-our-sim" hidden></span>
                            <?php if (($product['has_sale_price'] ?? false) === true): ?>
                                <span class="cmp-chip cmp-chip--sale">redus de la <?= $bani((float) $product['base_price']) ?></span>
                            <?php endif; ?>
                            <!-- Procentul de aici bate procentul general din bara de sus, doar pe rândul ăsta. -->
                            <label class="cmp-row-sim" title="Simulează o reducere doar la produsul ăsta">
                                −<input type="number" class="cmp-row-discount" min="0" max="95" step="0.5" placeholder="0"
                                        aria-label="Reducere simulată la <?= $esc((string) $product['name']) ?>">%
                            </label>

                        </td>

                        <?php foreach ($partnersInTable as $partner): ?>
                            <?php
                            $partnerId = (int) $partner['id'];
                            $date = $row['cells'][$partnerId] ?? ['cell' => [], 'effective' => []];
                            $cell = is_array($date['cell'] ?? null) ? $date['cell'] : [];
                            $efectiv = is_array($date['effective'] ?? null) ? $date['effective'] : [];
                            $numeCamp = 'cells[' . $partnerId . '][' . $productId . ']';
                            $pretPartener = $efectiv['price'] ?? null;
                            $outOfStock = ((int) ($cell['out_of_stock'] ?? 0)) === 1;
                            $doarInMagazin = ((int) ($cell['only_in_store'] ?? 0)) === 1;
                            $campanieBifata = ((int) ($cell['campaign_active'] ?? 0)) === 1;
                            $pretCampanie = $cell['campaign_price'] ?? null;

                            $clasaDiff = 'cmp-diff--none';
                            $textRon = 'fără preț';
                            $textPct = '';
                            if ($pretPartener !== null && $pretPartener > 0.0) {
                                $diferenta = $pretulNostru - (float) $pretPartener;
                                $procent = ($diferenta / (float) $pretPartener) * 100;
                                $clasaDiff = $diferenta < 0 ? 'cmp-diff--good' : ($diferenta > 0 ? 'cmp-diff--bad' : 'cmp-diff--none');
                                $semn = $diferenta > 0 ? '+' : ($diferenta < 0 ? '−' : '');
                                $textRon = $semn . $bani(abs($diferenta)) . ' RON';
                                $textPct = $semn . number_format(abs($procent), 1, '.', '') . '%' . ($diferenta < 0 ? ' mai ieftin' : ($diferenta > 0 ? ' mai scump' : ''));
                            }

                            $textCampanie = '';
                            if ($campanieBifata && $pretCampanie !== null && (float) $pretCampanie > 0.0) {
                                $vechi = $cell['price'] ?? null;
                                $textCampanie = 'Campanie: '
                                    . ($vechi !== null ? $bani((float) $vechi) . ' → ' : '')
                                    . $bani((float) $pretCampanie) . ' RON'
                                    . (($efectiv['discount'] ?? null) !== null ? ' (−' . number_format((float) $efectiv['discount'], 1, '.', '') . '%)' : '')
                                    . ' · ' . $perioada($efectiv['start'] ?? null, $efectiv['end'] ?? null)
                                    . (($efectiv['is_campaign'] ?? false) ? '' : ' · inactivă azi');
                            }
                            ?>
                            <td class="cmp-price-cell" data-partner-col="<?= $partnerId ?>"
                                data-partner-start="<?= $esc((string) ($partner['campaign_start'] ?? '')) ?>"
                                data-partner-end="<?= $esc((string) ($partner['campaign_end'] ?? '')) ?>"
                                data-has-campaign="<?= $partner['has_campaign'] ?>">
                                <div class="cmp-price-line">
                                    <input type="number" step="0.01" min="0" class="cmp-input" placeholder="—"
                                           name="<?= $numeCamp ?>[price]"
                                           value="<?= isset($cell['price']) && $cell['price'] !== null ? $brut((float) $cell['price']) : '' ?>"
                                           data-cell="price" data-partner="<?= $partnerId ?>" data-product="<?= $productId ?>"
                                           aria-label="Preț la <?= $esc((string) $partner['name']) ?>">
                                    <span class="cmp-unit">RON</span>
                                    <span class="cmp-saved" aria-live="polite"></span>
                                </div>

                                <!-- Comutator informativ: marchează produsul ca indisponibil la partenerul ăsta. -->
                                <label class="cmp-oos <?= $outOfStock ? 'on' : '' ?>">
                                    <input type="hidden" name="<?= $numeCamp ?>[out_of_stock]" value="0">
                                    <input type="checkbox" name="<?= $numeCamp ?>[out_of_stock]" value="1" <?= $outOfStock ? 'checked' : '' ?>
                                           data-cell="out_of_stock" data-partner="<?= $partnerId ?>" data-product="<?= $productId ?>">
                                    <span>Out of stock</span>
                                </label>

                                <!-- Nu-l are pe site, dar îl are în magazinul fizic.
                                     E altceva decât „nu-l are deloc". -->
                                <label class="cmp-only-store <?= $doarInMagazin ? 'on' : '' ?>"
                                       title="Nu e pe site-ul partenerului, dar există în magazinul lui fizic">
                                    <input type="hidden" name="<?= $numeCamp ?>[only_in_store]" value="0">
                                    <input type="checkbox" name="<?= $numeCamp ?>[only_in_store]" value="1" <?= $doarInMagazin ? 'checked' : '' ?>
                                           data-cell="only_in_store" data-partner="<?= $partnerId ?>" data-product="<?= $productId ?>">
                                    <span>Doar în magazin</span>
                                </label>

                                <!-- Campania se bifează la fiecare produs în parte, nu pe tot partenerul. -->
                                <label class="cmp-camp-toggle <?= $campanieBifata ? 'on' : '' ?>">
                                    <input type="hidden" name="<?= $numeCamp ?>[campaign_active]" value="0">
                                    <input type="checkbox" name="<?= $numeCamp ?>[campaign_active]" value="1" <?= $campanieBifata ? 'checked' : '' ?>
                                           data-cell="campaign_active" data-partner="<?= $partnerId ?>" data-product="<?= $productId ?>"
                                           aria-label="Campanie la <?= $esc((string) $product['name']) ?>, la <?= $esc((string) $partner['name']) ?>">
                                    <span>Campanie</span>
                                </label>

                                <div class="cmp-campaign-fields" <?= $campanieBifata ? '' : 'hidden' ?>>
                                    <span class="cmp-mini-label">Campanie la produsul ăsta</span>
                                    <input type="number" step="0.01" min="0" placeholder="preț nou"
                                           name="<?= $numeCamp ?>[campaign_price]"
                                           value="<?= $pretCampanie !== null ? $brut((float) $pretCampanie) : '' ?>"
                                           data-cell="campaign_price" data-partner="<?= $partnerId ?>" data-product="<?= $productId ?>"
                                           aria-label="Preț de campanie la <?= $esc((string) $partner['name']) ?>">
                                    <input type="date" name="<?= $numeCamp ?>[campaign_start]"
                                           value="<?= $esc((string) ($cell['campaign_start'] ?? '')) ?>"
                                           data-cell="campaign_start" data-partner="<?= $partnerId ?>" data-product="<?= $productId ?>"
                                           aria-label="Campania începe">
                                    <input type="date" name="<?= $numeCamp ?>[campaign_end]"
                                           value="<?= $esc((string) ($cell['campaign_end'] ?? '')) ?>"
                                           data-cell="campaign_end" data-partner="<?= $partnerId ?>" data-product="<?= $productId ?>"
                                           aria-label="Campania se termină">
                                </div>
                            </td>

                            <td class="cmp-diff-cell <?= $clasaDiff ?>" data-partner-col="<?= $partnerId ?>">
                                <span class="cmp-diff-ron"><?= $esc($textRon) ?></span>
                                <span class="cmp-diff-pct"><?= $esc($textPct) ?></span>
                                <span class="cmp-chip cmp-chip--campaign" <?= $textCampanie === '' ? 'hidden' : '' ?>><?= $esc($textCampanie) ?></span>
                                <span class="cmp-chip cmp-chip--oos" <?= $outOfStock ? '' : 'hidden' ?>>Indisponibil la partener</span>
                                <span class="cmp-chip cmp-chip--store" <?= $doarInMagazin ? '' : 'hidden' ?>>Doar în magazinul fizic</span>
                            </td>
                        <?php endforeach; ?>
                    </tr>

                    <!-- Linkurile către pagina produsului la fiecare partener, ascunse până la click pe produs. -->
                    <tr class="cmp-links-row" data-links-for="<?= $productId ?>" hidden>
                        <td class="cmp-sticky"><strong style="font-size:12px;color:#6b7280;">Linkuri către produs</strong></td>
                        <td colspan="<?= $coloaneRamase ?>">
                            <div class="cmp-links-grid">
                                <?php foreach ($partnersInTable as $partner): ?>
                                    <?php
                                    $partnerId = (int) $partner['id'];
                                    $cell = $row['cells'][$partnerId]['cell'] ?? [];
                                    $link = (string) ($cell['product_url'] ?? '');
                                    $numeCamp = 'cells[' . $partnerId . '][' . $productId . ']';
                                    ?>
                                    <div class="cmp-link-item" data-partner-col="<?= $partnerId ?>">
                                        <label for="cmp-url-<?= $partnerId ?>-<?= $productId ?>"><?= $esc((string) $partner['name']) ?></label>
                                        <div class="cmp-link-row">
                                            <input type="url" id="cmp-url-<?= $partnerId ?>-<?= $productId ?>"
                                                   name="<?= $numeCamp ?>[product_url]" value="<?= $esc($link) ?>"
                                                   placeholder="https://…"
                                                   data-cell="product_url" data-partner="<?= $partnerId ?>" data-product="<?= $productId ?>">
                                            <a class="btn btn-secondary cmp-link-open" href="<?= $esc($link !== '' ? $link : '#') ?>"
                                               target="_blank" rel="noreferrer noopener" <?= $link === '' ? 'hidden' : '' ?>>Deschide</a>
                                            <span class="cmp-saved" aria-live="polite"></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="cmp-footer">
            <button class="btn btn-secondary" type="submit">Salvează tot</button>
            <a class="btn" href="/admin/competition/export">⭳ Descarcă Excel cu situația curentă</a>
            <p>Fiecare câmp se salvează singur când ieși din el; „Salvează tot” e doar plasa de siguranță.</p>
        </div>
    </form>
    <?php endif; ?>
</section>

<script>
// Banda de parteneri. Stă separat de scriptul tabelului fiindcă acela se
// oprește când nu există încă produse, iar partenerii tot trebuie derulați.
(() => {
    const banda = document.getElementById('cmp-partner-list');
    const stanga = document.getElementById('cmp-slide-stanga');
    const dreapta = document.getElementById('cmp-slide-dreapta');
    if (!banda || !stanga || !dreapta) {
        return;
    }

    // Săgețile se văd doar cât mai e unde derula. Câțiva pixeli de toleranță:
    // derularea lină lasă fracțiuni în urmă, iar alinierea la card oprește
    // banda la marginea interioară, nu la zero fix — fără slack, săgeata ar
    // rămâne aprinsă degeaba la capăt.
    const SLACK = 6;
    const actualizeaza = () => {
        const maxim = banda.scrollWidth - banda.clientWidth;
        stanga.hidden = banda.scrollLeft <= SLACK;
        dreapta.hidden = banda.scrollLeft >= maxim - SLACK;
    };

    // Alunecarea o facem cu mâna, dintr-un ceas care mută `scrollLeft` pas cu
    // pas. Nu cu `behavior: 'smooth'`: browserele care nu-l cunosc îl ignoră cu
    // totul, iar săgeata n-ar face absolut nimic — un buton mort e mai rău
    // decât unul care sare direct. Așa, oriunde ar fi apăsat, banda se mișcă.
    const linistit = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let alunecare = null;
    const deruleazaSpre = (tinta) => {
        const maxim = banda.scrollWidth - banda.clientWidth;
        const capat = Math.max(0, Math.min(maxim, tinta));
        const dela = banda.scrollLeft;
        const drum = capat - dela;
        if (Math.abs(drum) < 1) {
            return;
        }
        window.clearInterval(alunecare);
        if (linistit) {
            banda.scrollLeft = capat;
            return;
        }
        const inceput = Date.now();
        alunecare = window.setInterval(() => {
            const t = Math.min(1, (Date.now() - inceput) / 260);
            // Încetinire spre final, ca să nu se oprească brusc.
            banda.scrollLeft = dela + drum * (1 - Math.pow(1 - t, 3));
            if (t >= 1) {
                window.clearInterval(alunecare);
            }
        }, 16);
    };

    const pas = () => Math.max(230, banda.clientWidth * 0.8);
    stanga.addEventListener('click', () => deruleazaSpre(banda.scrollLeft - pas()));
    dreapta.addEventListener('click', () => deruleazaSpre(banda.scrollLeft + pas()));
    banda.addEventListener('scroll', actualizeaza);
    window.addEventListener('resize', actualizeaza);
    actualizeaza();
})();
</script>

<script>
(() => {
    const tabel = document.getElementById('cmp-table');
    if (!tabel) {
        return;
    }
    const azi = <?= json_encode($today, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

    // Al doilea rând de antet trebuie să stea exact sub primul. Înălțimea
    // primului depinde de font și de numele partenerilor, deci se măsoară.
    const asazaAntetul = () => {
        const primulRand = tabel.querySelector('thead tr');
        if (primulRand) {
            tabel.style.setProperty('--cmp-head-h', primulRand.getBoundingClientRect().height + 'px');
        }
    };
    asazaAntetul();
    window.addEventListener('resize', asazaAntetul);

    const numar = (valoare) => {
        const text = String(valoare ?? '').trim().replace(',', '.');
        if (text === '') {
            return null;
        }
        const n = Number.parseFloat(text);
        return Number.isFinite(n) && n > 0 ? n : null;
    };
    // Aceeași formă ca `number_format($v, 2)` din PHP, ca valorile recalculate
    // în browser să arate identic cu cele venite de la server.
    const lei = (n) => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const ziRo = (iso) => {
        if (!iso) {
            return '';
        }
        const [a, l, z] = iso.split('-');
        return `${z}.${l}.${a}`;
    };
    const perioadaText = (de, pana) => {
        if (!de && !pana) {
            return 'fără perioadă';
        }
        if (!de) {
            return 'până la ' + ziRo(pana);
        }
        if (!pana) {
            return 'din ' + ziRo(de);
        }
        return ziRo(de) + ' – ' + ziRo(pana);
    };

    // ── Recalcul diferențe ────────────────────────────────────
    // Diferența = prețul nostru − prețul de la partener. Procentul se
    // raportează la prețul partenerului: „cu cât suntem sub/peste el”.
    const inputReducere = document.getElementById('cmp-discount');
    const reducereSimulata = () => {
        const n = Number.parseFloat(String(inputReducere?.value ?? '').replace(',', '.'));
        return Number.isFinite(n) && n > 0 ? Math.min(n, 95) : 0;
    };

    const recalculeazaCelula = (celulaDiff, pretulNostru) => {
        const celulaPret = celulaDiff.previousElementSibling;
        if (!celulaPret) {
            return;
        }
        const camp = (nume) => celulaPret.querySelector('[data-cell="' + nume + '"]');
        const pretNormal = numar(camp('price')?.value);
        const pretCampanie = numar(camp('campaign_price')?.value);
        // Perioada lipsă cade pe cea a partenerului, dar numai dacă el chiar
        // are una generală — altfel campania e deschisă, fără capete.
        const perioadaPartener = celulaPret.dataset.hasCampaign === '1';
        const start = camp('campaign_start')?.value || (perioadaPartener ? celulaPret.dataset.partnerStart : '') || '';
        const final = camp('campaign_end')?.value || (perioadaPartener ? celulaPret.dataset.partnerEnd : '') || '';
        // Campania e a produsului, nu a partenerului: contează bifa din celulă.
        const bifata = camp('campaign_active')?.checked === true;

        const campanieActiva = bifata && pretCampanie !== null
            && (!start || start <= azi) && (!final || final >= azi);
        const pretPartener = campanieActiva ? pretCampanie : pretNormal;

        const textRon = celulaDiff.querySelector('.cmp-diff-ron');
        const textPct = celulaDiff.querySelector('.cmp-diff-pct');
        celulaDiff.classList.remove('cmp-diff--good', 'cmp-diff--bad', 'cmp-diff--none');

        if (pretPartener === null || pretulNostru <= 0) {
            celulaDiff.classList.add('cmp-diff--none');
            textRon.textContent = 'fără preț';
            textPct.textContent = '';
        } else {
            const diferenta = pretulNostru - pretPartener;
            const procent = (diferenta / pretPartener) * 100;
            const semn = diferenta > 0 ? '+' : (diferenta < 0 ? '−' : '');
            celulaDiff.classList.add(diferenta < 0 ? 'cmp-diff--good' : (diferenta > 0 ? 'cmp-diff--bad' : 'cmp-diff--none'));
            textRon.textContent = semn + lei(Math.abs(diferenta)) + ' RON';
            textPct.textContent = semn + Math.abs(procent).toFixed(1) + '%'
                + (diferenta < 0 ? ' mai ieftin' : (diferenta > 0 ? ' mai scump' : ''));
        }

        // Eticheta de campanie se rescrie aici ca să nu ceară reîncărcarea paginii.
        const eticheta = celulaDiff.querySelector('.cmp-chip--campaign');
        if (eticheta) {
            if (bifata && pretCampanie !== null) {
                const discount = pretNormal !== null && pretCampanie < pretNormal
                    ? ' (−' + (((pretNormal - pretCampanie) / pretNormal) * 100).toFixed(1) + '%)'
                    : '';
                eticheta.textContent = 'Campanie: '
                    + (pretNormal !== null ? lei(pretNormal) + ' → ' : '')
                    + lei(pretCampanie) + ' RON' + discount
                    + ' · ' + perioadaText(start, final)
                    + (campanieActiva ? '' : ' · inactivă azi');
                eticheta.hidden = false;
            } else {
                eticheta.hidden = true;
            }
        }
    };

    // Procentul scris pe rând are întâietate: e cazul obișnuit de „ce s-ar
    // întâmpla dacă las produsul ăsta mai ieftin", fără să atingem restul.
    const reducereRand = (rand) => {
        const camp = rand.querySelector('.cmp-row-discount');
        const n = Number.parseFloat(String(camp?.value ?? '').replace(',', '.'));
        return Number.isFinite(n) && n > 0 ? Math.min(n, 95) : null;
    };

    const recalculeazaRand = (rand) => {
        const bazaText = rand.querySelector('[data-our-base]')?.dataset.ourBase ?? '0';
        const baza = Number.parseFloat(bazaText) || 0;
        const proprie = reducereRand(rand);
        const reducere = proprie !== null ? proprie : reducereSimulata();
        const pretulNostru = reducere > 0 ? baza * (1 - reducere / 100) : baza;

        const simulat = rand.querySelector('.cmp-our-sim');
        if (simulat) {
            simulat.hidden = reducere <= 0;
            simulat.textContent = reducere > 0
                ? '−' + reducere + '% → ' + lei(pretulNostru) + ' RON' + (proprie !== null ? ' (doar aici)' : '')
                : '';
        }
        rand.querySelectorAll('.cmp-diff-cell').forEach((celula) => recalculeazaCelula(celula, pretulNostru));
    };

    const randuri = Array.from(tabel.querySelectorAll('tr.cmp-row'));
    const recalculeazaTot = () => randuri.forEach(recalculeazaRand);
    inputReducere?.addEventListener('input', recalculeazaTot);
    tabel.addEventListener('input', (eveniment) => {
        if (eveniment.target.classList?.contains('cmp-row-discount')) {
            const rand = eveniment.target.closest('tr.cmp-row');
            if (rand) {
                recalculeazaRand(rand);
            }
        }
    });

    // ── Rotița mouse-ului nu mai schimbă prețul ───────────────
    // Pe un tabel care se derulează mult, un `input type=number` sub cursor
    // fura rotița și schimba cifra fără să-ți dai seama. Îi luăm focusul:
    // pagina se derulează normal, iar valoarea rămâne cea scrisă.
    document.addEventListener('wheel', (eveniment) => {
        const element = document.activeElement;
        if (element instanceof HTMLInputElement
            && element.type === 'number'
            && element === eveniment.target) {
            element.blur();
        }
    }, { passive: true });

    // ── Golirea tabelului ─────────────────────────────────────
    document.getElementById('cmp-clear-form')?.addEventListener('submit', (eveniment) => {
        const completate = document.querySelectorAll('.cmp-input').length;
        const mesaj = 'Golești tot tabelul?\n\n'
            + 'Se șterg definitiv toate prețurile la parteneri, campaniile, bifele de stoc '
            + 'și linkurile către produse.\n'
            + 'Partenerii rămân — se șterg doar datele din tabel.\n\n'
            + 'Dacă vrei să păstrezi situația de acum, renunță și descarcă întâi Export Excel.';
        if (!window.confirm(mesaj)) {
            eveniment.preventDefault();
            return;
        }
        // A doua întrebare, scurtă: prima se apasă din reflex.
        if (!window.confirm('Sigur? Datele nu se mai pot recupera.')) {
            eveniment.preventDefault();
        }
    });

    // ── Salvare pe celulă ─────────────────────────────────────
    const arataStare = (input, text, eroare) => {
        const indicator = input.closest('.cmp-price-cell, .cmp-link-row')?.querySelector('.cmp-saved');
        if (!indicator) {
            return;
        }
        indicator.textContent = text;
        indicator.classList.toggle('err', Boolean(eroare));
        indicator.classList.add('on');
        window.setTimeout(() => indicator.classList.remove('on'), 2000);
    };

    const salveaza = async (input) => {
        const valoare = input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value;
        try {
            const raspuns = await fetch('/admin/competition/cell', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': <?= json_encode(\App\Support\AdminCsrf::token()) ?> },
                body: JSON.stringify({
                    partner_id: Number(input.dataset.partner),
                    product_id: Number(input.dataset.product),
                    field: input.dataset.cell,
                    value: valoare,
                }),
            });
            const date = await raspuns.json();
            if (date && date.ok) {
                arataStare(input, '✓ salvat', false);
            } else {
                arataStare(input, '✕ eroare', true);
            }
        } catch (e) {
            arataStare(input, '✕ offline', true);
        }
    };

    tabel.addEventListener('change', (eveniment) => {
        const input = eveniment.target;
        if (!(input instanceof HTMLElement) || !input.dataset.cell) {
            return;
        }
        salveaza(input);

        if (input.dataset.cell === 'campaign_active') {
            input.closest('.cmp-camp-toggle')?.classList.toggle('on', input.checked);
            const campuri = input.closest('.cmp-price-cell')?.querySelector('.cmp-campaign-fields');
            if (campuri) {
                campuri.hidden = !input.checked;
            }
            const rand = input.closest('tr.cmp-row');
            if (rand) {
                recalculeazaRand(rand);
            }
            return;
        }

        if (input.dataset.cell === 'out_of_stock') {
            input.closest('.cmp-oos')?.classList.toggle('on', input.checked);
            const celulaDiff = input.closest('.cmp-price-cell')?.nextElementSibling;
            const eticheta = celulaDiff?.querySelector('.cmp-chip--oos');
            if (eticheta) {
                eticheta.hidden = !input.checked;
            }
            return;
        }

        if (input.dataset.cell === 'only_in_store') {
            input.closest('.cmp-only-store')?.classList.toggle('on', input.checked);
            const celulaDiff = input.closest('.cmp-price-cell')?.nextElementSibling;
            const eticheta = celulaDiff?.querySelector('.cmp-chip--store');
            if (eticheta) {
                eticheta.hidden = !input.checked;
            }
            return;
        }

        if (input.dataset.cell === 'product_url') {
            const buton = input.parentElement?.querySelector('.cmp-link-open');
            if (buton) {
                buton.href = input.value.trim() !== '' ? input.value.trim() : '#';
                buton.hidden = input.value.trim() === '';
            }
            return;
        }

        const rand = input.closest('tr.cmp-row');
        if (rand) {
            recalculeazaRand(rand);
        }
    });

    // ── Click pe produs: câmpurile de link ────────────────────
    tabel.addEventListener('click', (eveniment) => {
        const buton = eveniment.target.closest('[data-toggle-links]');
        if (!buton) {
            return;
        }
        const randLinkuri = tabel.querySelector('tr[data-links-for="' + buton.dataset.toggleLinks + '"]');
        if (!randLinkuri) {
            return;
        }
        randLinkuri.hidden = !randLinkuri.hidden;
        buton.setAttribute('aria-expanded', randLinkuri.hidden ? 'false' : 'true');
    });

    // ── Filtre: căutare, un anume produs, anumiți parteneri ───
    const cautare = document.getElementById('cmp-search');
    const alegereProdus = document.getElementById('cmp-filter-product');
    const contor = document.getElementById('cmp-count');
    const butonReset = document.getElementById('cmp-reset');
    const bifeParteneri = Array.from(document.querySelectorAll('.cmp-pick-partner'));
    const rezumatParteneri = document.getElementById('cmp-pick-summary');
    const meniuParteneri = document.getElementById('cmp-pick-partners');
    const CHEIE = 'cmp-parteneri-ascunsi';

    // „Căști” trebuie găsit și scriind „casti”.
    const faraDiacritice = (text) => text
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim();

    const indexate = randuri.map((rand) => ({
        rand,
        id: rand.dataset.product || '',
        randLinkuri: tabel.querySelector('tr[data-links-for="' + rand.dataset.product + '"]'),
        text: faraDiacritice(rand.dataset.search || ''),
    }));

    const filtreActive = () => (cautare?.value.trim() ?? '') !== ''
        || (alegereProdus?.value ?? '') !== ''
        || bifeParteneri.some((bifa) => !bifa.checked);

    const aplicaFiltruProduse = () => {
        const cuvinte = faraDiacritice(cautare?.value ?? '').split(/\s+/).filter(Boolean);
        const doarProdusul = alegereProdus?.value ?? '';
        let vizibile = 0;

        indexate.forEach(({ rand, id, randLinkuri, text }) => {
            const potrivit = (doarProdusul === '' || doarProdusul === id)
                && cuvinte.every((cuvant) => text.includes(cuvant));
            rand.hidden = !potrivit;
            if (randLinkuri && !potrivit) {
                randLinkuri.hidden = true;
            }
            if (potrivit) {
                vizibile++;
            }
        });

        if (contor) {
            contor.textContent = vizibile === indexate.length
                ? `${indexate.length} produse`
                : `${vizibile} din ${indexate.length} produse`;
        }
        if (butonReset) {
            butonReset.hidden = !filtreActive();
        }
    };

    // Partenerii ascunși dispar cu tot cu coloanele lor. E doar o ascundere în
    // pagină: prețurile rămân salvate și se văd din nou la o bifă.
    const aplicaFiltruParteneri = () => {
        const ascunsi = new Set(bifeParteneri.filter((bifa) => !bifa.checked).map((bifa) => bifa.value));
        tabel.querySelectorAll('[data-partner-col]').forEach((element) => {
            element.hidden = ascunsi.has(element.dataset.partnerCol);
        });
        // Câmpurile de link stau într-o grilă, nu în celule de tabel.
        document.querySelectorAll('.cmp-link-item[data-partner-col]').forEach((element) => {
            element.hidden = ascunsi.has(element.dataset.partnerCol);
        });

        if (rezumatParteneri) {
            const alesi = bifeParteneri.length - ascunsi.size;
            rezumatParteneri.textContent = ascunsi.size === 0
                ? `Parteneri: toți (${bifeParteneri.length})`
                : `Parteneri: ${alesi} din ${bifeParteneri.length}`;
        }
        if (butonReset) {
            butonReset.hidden = !filtreActive();
        }
        try {
            window.localStorage.setItem(CHEIE, JSON.stringify(Array.from(ascunsi)));
        } catch (e) {
            // Modul privat al browserului refuză scrierea; filtrul merge oricum.
        }
        asazaAntetul();
    };

    // Alegerea de data trecută, ca o salvare de partener să nu strice vederea.
    try {
        const salvati = new Set(JSON.parse(window.localStorage.getItem(CHEIE) || '[]'));
        bifeParteneri.forEach((bifa) => { bifa.checked = !salvati.has(bifa.value); });
    } catch (e) {
        // fără preferință salvată: rămân toți bifați
    }

    cautare?.addEventListener('input', aplicaFiltruProduse);
    alegereProdus?.addEventListener('change', aplicaFiltruProduse);
    bifeParteneri.forEach((bifa) => bifa.addEventListener('change', aplicaFiltruParteneri));

    document.getElementById('cmp-pick-all')?.addEventListener('click', () => {
        bifeParteneri.forEach((bifa) => { bifa.checked = true; });
        aplicaFiltruParteneri();
    });
    document.getElementById('cmp-pick-none')?.addEventListener('click', () => {
        bifeParteneri.forEach((bifa) => { bifa.checked = false; });
        aplicaFiltruParteneri();
    });
    butonReset?.addEventListener('click', () => {
        if (cautare) { cautare.value = ''; }
        if (alegereProdus) { alegereProdus.value = ''; }
        bifeParteneri.forEach((bifa) => { bifa.checked = true; });
        aplicaFiltruProduse();
        aplicaFiltruParteneri();
    });

    // Meniul de parteneri se închide la un clic în altă parte.
    document.addEventListener('click', (eveniment) => {
        if (meniuParteneri?.open && !meniuParteneri.contains(eveniment.target)) {
            meniuParteneri.open = false;
        }
    });

    aplicaFiltruProduse();
    aplicaFiltruParteneri();
    recalculeazaTot();
})();
</script>
