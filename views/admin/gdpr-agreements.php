<?php
$items = is_array($items ?? null) ? $items : [];
$search = trim((string) ($search ?? ''));
$page = max(1, (int) ($page ?? 1));
$perPage = (int) ($perPage ?? 25);
$totalItems = max(0, (int) ($totalItems ?? count($items)));
$totalPages = max(1, (int) ($totalPages ?? 1));
$perPageOptions = is_array($perPageOptions ?? null) ? $perPageOptions : [10, 25, 50, 100, 200, 500];
$buildUrl = static function (array $params) use ($search, $perPage): string {
    $query = [
        'per_page' => $perPage,
    ];
    if ($search !== '') {
        $query['q'] = $search;
    }
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
            continue;
        }
        $query[$key] = $value;
    }
    return '/admin/users/gdpr-agreements?' . http_build_query($query);
};
$buildExportUrl = static function () use ($search): string {
    $query = [];
    if ($search !== '') {
        $query['q'] = $search;
    }
    return '/admin/users/gdpr-agreements/export' . ($query !== [] ? ('?' . http_build_query($query)) : '');
};
$paginationStart = max(1, $page - 2);
$paginationEnd = min($totalPages, $page + 2);
// Datele operatorului din textul acordului (WP-18, GR-03, portat din grafoanaytis 2c11526, d1bffca): formularul public
// le folosește direct; un câmp gol apare acolo ca spațiu de completat.
$setari = is_array($setari ?? null) ? $setari : [];
$valGdpr = static fn (string $cheie): string => htmlspecialchars((string) ($setari[$cheie] ?? ''), ENT_QUOTES);
$variantaGdpr = (string) ($setari['gdpr_formular_varianta'] ?? '') === 'medical' ? 'medical' : 'general';
$campuriOperator = [
    ['gdpr_operator_nume', 'Denumire societate', 'ex: SC Firma Demo SRL', true],
    ['gdpr_operator_reprezentant', 'Reprezentant legal', 'ex: Administrator Nume Prenume', true],
    ['gdpr_operator_regcom', 'Nr. Registrul Comerțului', 'ex: J40/123/2020', true],
    ['gdpr_operator_cui', 'CUI / Cod TVA', 'ex: RO12345678', true],
    ['gdpr_operator_sediu', 'Sediu social', 'Strada, număr, oraș', false],
    ['gdpr_operator_telefon', 'Telefon', '', false],
    ['gdpr_operator_email', 'Email pentru retragerea acordului', 'ex: gdpr@domeniul-tau.ro', false],
    ['gdpr_operator_marca', 'Marca sub care este cunoscută firma', '', false],
    ['gdpr_operator_adresa_retragere', 'Adresa poștală pentru retragerea acordului', 'gol = „denumire - sediu”', false],
];
?>

<?php ob_start(); ?>
    <a class="btn btn-secondary" href="<?= htmlspecialchars($buildExportUrl(), ENT_QUOTES) ?>">Export Excel (cu semnături)</a>
    <a class="btn btn-secondary" href="/admin/users">Înapoi la clienți</a>
<?php $antetActiuni = (string) ob_get_clean(); ?>
<?php
admin_componenta('antet-pagina', [
    'titlu' => 'Acorduri GDPR',
    'nivel' => 1,
    'subtitlu' => 'Înregistrări salvate din formularul public de acord GDPR.',
    'actiuni' => $antetActiuni,
]);
?>
<?= \App\Support\Dev::marcaj('gr:gdpr-setari') ?>
<section class="adm-sectiune" id="gdpr-operator" aria-labelledby="gdpr-operator-titlu">
    <div class="adm-sectiune__antet"><div>
        <h2 class="adm-sectiune__titlu" id="gdpr-operator-titlu">Datele operatorului</h2>
        <p class="adm-sectiune__descriere">
            Apar în textul acordului semnat de vizitatori. Câmpurile marcate cu * sunt date juridice: cât timp lipsesc,
            formularul le arată ca spații de completat.
        </p>
    </div></div>
    <div class="adm-sectiune__corp">
    <form method="post" action="/admin/pages/gdpr-agreements/settings" class="form-grid">
        <div class="field">
            <label for="gdpr-varianta">Textul formularului</label>
            <select id="gdpr-varianta" name="gdpr_formular_varianta">
                <option value="general" <?= $variantaGdpr === 'general' ? 'selected' : '' ?>>General (un scop: oferte, comunicări …; CUI, companie, funcție)</option>
                <option value="medical" <?= $variantaGdpr === 'medical' ? 'selected' : '' ?>>Eveniment medical (CUIM, instituție medicală, tip medic, specializare)</option>
            </select>
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label for="gdpr-scop">Scopul prelucrării (sau evenimentul)</label>
            <input id="gdpr-scop" type="text" name="gdpr_scop" value="<?= $valGdpr('gdpr_scop') ?>"
                   placeholder="ex: primirii de oferte și comunicări comerciale">
        </div>
<?php foreach ($campuriOperator as [$cheie, $eticheta, $exemplu, $obligatoriu]): ?>
        <div class="field">
            <label for="<?= $cheie ?>"><?= htmlspecialchars($eticheta, ENT_QUOTES) ?><?= $obligatoriu ? ' *' : '' ?></label>
            <input id="<?= $cheie ?>" type="text" name="<?= $cheie ?>" value="<?= $valGdpr($cheie) ?>" placeholder="<?= htmlspecialchars($exemplu, ENT_QUOTES) ?>">
        </div>
<?php endforeach; ?>
        <div style="grid-column:1/-1;display:flex;justify-content:flex-end;">
            <button class="btn" type="submit">Salvează datele operatorului</button>
        </div>
    </form>
    </div>
</section>
<?= \App\Support\Dev::marcaj('gr:gdpr-setari', true) ?>
<div class="adm-pagina">

    <form method="get" action="/admin/users/gdpr-agreements" class="admin-filters-inline" style="margin:12px 0;">
        <div class="admin-filter-field admin-filter-field--search">
            <span>Căutare</span>
            <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" placeholder="nume, prenume, email, telefon, instituție">
        </div>
        <div class="admin-filter-field">
            <span>Pe pagină</span>
            <select name="per_page">
                <?php foreach ($perPageOptions as $opt): ?>
                    <?php $optValue = (int) $opt; ?>
                    <option value="<?= $optValue ?>" <?= $perPage === $optValue ? 'selected' : '' ?>><?= $optValue ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="admin-filter-actions">
            <button class="btn" type="submit">Aplică filtre</button>
            <a class="btn btn-secondary" href="/admin/users/gdpr-agreements?per_page=<?= $perPage ?>">Reset</a>
        </div>
    </form>

    <p style="margin:0 0 10px;color:var(--ax-text-3);">Afișare <?= count($items) ?> din <?= $totalItems ?> acorduri.</p>

    <table class="table">
        <thead>
        <tr>
            <th style="width:80px;">ID</th>
            <th style="width:180px;">Subsemnatul</th>
            <th style="width:170px;">Date personale</th>
            <th style="width:220px;">Contact</th>
            <th>Instituție / Medic</th>
            <th style="width:130px;">Data</th>
            <th style="width:140px;">Semnătură</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($items === []): ?>
            <tr>
                <td colspan="7">Nu există acorduri GDPR pentru filtrul selectat.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($items as $item): ?>
                <?php
                $signatureDataUrl = trim((string) ($item['signature_data_url'] ?? ''));
                $hasSignature = $signatureDataUrl !== '' && str_starts_with($signatureDataUrl, 'data:image/');
                ?>
                <tr>
                    <td>#<?= (int) ($item['id'] ?? 0) ?></td>
                    <td>
                        <strong><?= htmlspecialchars((string) ($item['subiect_nume_complet'] ?? ''), ENT_QUOTES) ?></strong><br>
                        <small>CI <?= htmlspecialchars((string) ($item['ci_serie'] ?? ''), ENT_QUOTES) ?> <?= htmlspecialchars((string) ($item['ci_numar'] ?? ''), ENT_QUOTES) ?></small>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars((string) ($item['nume'] ?? ''), ENT_QUOTES) ?> <?= htmlspecialchars((string) ($item['prenume'] ?? ''), ENT_QUOTES) ?></strong><br>
                        <small>CNP: <?= htmlspecialchars((string) ($item['cnp'] ?? ''), ENT_QUOTES) ?></small><br>
                        <small>CUIM: <?= htmlspecialchars((string) ($item['cuim'] ?? ''), ENT_QUOTES) ?></small>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars((string) ($item['telefon'] ?? ''), ENT_QUOTES) ?></strong><br>
                        <small><?= htmlspecialchars((string) ($item['email'] ?? ''), ENT_QUOTES) ?></small><br>
                        <small><?= htmlspecialchars((string) ($item['adresa_corespondenta'] ?? ''), ENT_QUOTES) ?></small>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars((string) ($item['institutie_medicala'] ?? ''), ENT_QUOTES) ?></strong><br>
                        <small><?= htmlspecialchars((string) ($item['institutie_activitate'] ?? ''), ENT_QUOTES) ?></small><br>
                        <small><?= htmlspecialchars((string) ($item['institutie_adresa'] ?? ''), ENT_QUOTES) ?></small><br>
                        <small><?= htmlspecialchars((string) ($item['tip_medic'] ?? ''), ENT_QUOTES) ?> · <?= htmlspecialchars((string) ($item['specializare'] ?? ''), ENT_QUOTES) ?></small>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars((string) ($item['data_semnare'] ?? ''), ENT_QUOTES) ?></strong><br>
                        <small><?= htmlspecialchars((string) ($item['created_at'] ?? ''), ENT_QUOTES) ?></small>
                    </td>
                    <td>
                        <?php if ($hasSignature): ?>
                            <img src="<?= htmlspecialchars($signatureDataUrl, ENT_QUOTES) ?>" alt="Semnătură" style="max-width:120px;max-height:56px;border:1px solid var(--ax-linie-tare);border-radius:6px;background:var(--ax-suprafata);">
                        <?php else: ?>
                            <span style="color:var(--ax-eroare);">Lipsă</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
        <nav class="product-reviews-pagination" aria-label="Paginare acorduri GDPR">
            <a class="product-reviews-pagination__btn" href="<?= htmlspecialchars($buildUrl(['page' => 1]), ENT_QUOTES) ?>" <?= $page <= 1 ? 'style="pointer-events:none;opacity:.5;"' : '' ?>>« Prima</a>
            <a class="product-reviews-pagination__btn" href="<?= htmlspecialchars($buildUrl(['page' => max(1, $page - 1)]), ENT_QUOTES) ?>" <?= $page <= 1 ? 'style="pointer-events:none;opacity:.5;"' : '' ?>>‹</a>
            <?php for ($p = $paginationStart; $p <= $paginationEnd; $p++): ?>
                <a class="product-reviews-pagination__btn <?= $p === $page ? 'is-active' : '' ?>" href="<?= htmlspecialchars($buildUrl(['page' => $p]), ENT_QUOTES) ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a class="product-reviews-pagination__btn" href="<?= htmlspecialchars($buildUrl(['page' => min($totalPages, $page + 1)]), ENT_QUOTES) ?>" <?= $page >= $totalPages ? 'style="pointer-events:none;opacity:.5;"' : '' ?>>›</a>
            <a class="product-reviews-pagination__btn" href="<?= htmlspecialchars($buildUrl(['page' => $totalPages]), ENT_QUOTES) ?>" <?= $page >= $totalPages ? 'style="pointer-events:none;opacity:.5;"' : '' ?>>Ultima »</a>
            <span class="product-reviews-pagination__label">Pagina <?= $page ?> / <?= $totalPages ?></span>
        </nav>
    <?php endif; ?>
</div>
