<?php
/**
 * Tipuri de cont (meserii) + câmpurile cerute la înregistrare pentru fiecare.
 * Totul e configurabil de aici, ca să poți adăuga meserii noi fără modificări de cod.
 */
$professions = is_array($professions ?? null) ? $professions : [];
$editingProfession = is_array($editingProfession ?? null) ? $editingProfession : null;
$fieldTypes = is_array($fieldTypes ?? null) ? $fieldTypes : ['text'];
$editingId = (int) ($editingProfession['id'] ?? 0);
$editingFields = is_array($editingProfession['fields'] ?? null) ? $editingProfession['fields'] : [];

$fieldTypeLabels = [
    'text' => 'Text scurt',
    'textarea' => 'Text lung',
    'number' => 'Număr',
    'date' => 'Dată',
    'select' => 'Listă de opțiuni',
    'checkbox' => 'Bifă (accept / de acord)',
    'file' => 'Fișier (PDF / imagine)',
];
?>
<section class="panel">
    <div class="section-head">
        <div>
            <h1>Tipuri de cont</h1>
            <p>
                Definește meseriile (ex: rezident, medic primar), prețul de activare pentru fiecare
                și ce date trebuie să completeze utilizatorul la înregistrare.
            </p>
        </div>
        <div>
            <a class="btn btn-secondary" href="/admin/accounts/verifications">Vezi cererile</a>
        </div>
    </div>

    <form method="post" action="/admin/accounts/professions" class="form-grid" style="margin-bottom:18px;">
        <?= \App\Support\AdminCsrf::camp() . "\n" ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="profession_id" value="<?= $editingId ?>">

        <div class="field">
            <label>Denumire *</label>
            <input type="text" name="name" required
                   value="<?= htmlspecialchars((string) ($editingProfession['name'] ?? ''), ENT_QUOTES) ?>"
                   placeholder="ex: Medic primar">
        </div>
        <div class="field">
            <label>Slug (opțional)</label>
            <input type="text" name="slug"
                   value="<?= htmlspecialchars((string) ($editingProfession['slug'] ?? ''), ENT_QUOTES) ?>"
                   placeholder="ex: medic-primar">
        </div>
        <div class="field">
            <label>Cotizație (lei) *</label>
            <input type="number" step="0.01" min="0" name="activation_price"
                   value="<?= htmlspecialchars((string) ($editingProfession['activation_price'] ?? '0'), ENT_QUOTES) ?>">
        </div>
        <div class="field">
            <label>Perioada acoperită de preț (luni)</label>
            <input type="number" min="1" name="activation_months"
                   value="<?= (int) ($editingProfession['activation_months'] ?? 12) ?>">
        </div>
        <div class="field">
            <label>Ordine afișare</label>
            <input type="number" name="sort_order" value="<?= (int) ($editingProfession['sort_order'] ?? 0) ?>">
        </div>
        <div class="field">
            <label style="display:flex;align-items:center;gap:8px;margin-top:26px;">
                <input type="checkbox" name="is_active" value="1"
                    <?= ($editingProfession === null || (int) ($editingProfession['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                Activ (apare în formularul de înregistrare)
            </label>
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Descriere (se vede la înregistrare)</label>
            <textarea name="description" rows="2"><?= htmlspecialchars((string) ($editingProfession['description'] ?? ''), ENT_QUOTES) ?></textarea>
        </div>
        <div style="grid-column:1/-1;display:flex;gap:10px;">
            <button class="btn" type="submit">
                <?= $editingId > 0 ? 'Salvează tipul de cont' : 'Adaugă tipul de cont' ?>
            </button>
            <?php if ($editingId > 0): ?>
                <a class="btn btn-secondary" href="/admin/accounts/professions">Renunță la editare</a>
            <?php endif; ?>
        </div>
    </form>

    <table class="table">
        <thead>
        <tr>
            <th>ID</th>
            <th>Denumire</th>
            <th>Cotizație</th>
            <th>Perioadă preț</th>
            <th>Câmpuri</th>
            <th>Stare</th>
            <th>Acțiuni</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($professions === []): ?>
            <tr><td colspan="7">Nu ai definit încă niciun tip de cont.</td></tr>
        <?php else: ?>
            <?php foreach ($professions as $profession): ?>
                <?php $professionId = (int) ($profession['id'] ?? 0); ?>
                <tr>
                    <td><?= $professionId ?></td>
                    <td><strong><?= htmlspecialchars((string) ($profession['name'] ?? ''), ENT_QUOTES) ?></strong></td>
                    <td><?= number_format((float) ($profession['activation_price'] ?? 0), 2, ',', '.') ?> lei</td>
                    <td><?= (int) ($profession['activation_months'] ?? 12) ?> luni</td>
                    <td><?= count(is_array($profession['fields'] ?? null) ? $profession['fields'] : []) ?></td>
                    <td>
                        <?= (int) ($profession['is_active'] ?? 0) === 1 ? 'Activ' : 'Inactiv' ?>
                    </td>
                    <td style="display:flex;gap:8px;">
                        <a class="btn btn-secondary" href="/admin/accounts/professions?edit=<?= $professionId ?>">Editează</a>
                        <form method="post" action="/admin/accounts/professions"
                              onsubmit="return confirm('Ștergi acest tip de cont și toate câmpurile lui?');">
                            <?= \App\Support\AdminCsrf::camp() . "\n" ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="profession_id" value="<?= $professionId ?>">
                            <button class="btn btn-secondary" type="submit">Șterge</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<?php if ($editingId > 0): ?>
<section class="panel">
    <div class="section-head">
        <div>
            <h1>Câmpuri pentru „<?= htmlspecialchars((string) ($editingProfession['name'] ?? ''), ENT_QUOTES) ?>”</h1>
            <p>
                Aceste câmpuri apar în formularul de înregistrare când utilizatorul alege acest tip de cont.
                Câmpurile de tip fișier permit încărcarea documentelor de verificare.
            </p>
        </div>
    </div>

    <form method="post" action="/admin/accounts/professions/fields" class="form-grid" style="margin-bottom:18px;">
        <?= \App\Support\AdminCsrf::camp() . "\n" ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="profession_id" value="<?= $editingId ?>">
        <input type="hidden" name="field_id" value="0">

        <div class="field">
            <label>Etichetă *</label>
            <input type="text" name="label" required placeholder="ex: Număr certificat CMR">
        </div>
        <div class="field">
            <label>Cheie internă (opțional)</label>
            <input type="text" name="field_key" placeholder="se generează din etichetă">
        </div>
        <div class="field">
            <label>Tip câmp</label>
            <select name="field_type">
                <?php foreach ($fieldTypes as $type): ?>
                    <option value="<?= htmlspecialchars((string) $type, ENT_QUOTES) ?>">
                        <?= htmlspecialchars($fieldTypeLabels[$type] ?? (string) $type, ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Ordine</label>
            <input type="number" name="sort_order" value="0">
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Text ajutător (opțional)</label>
            <input type="text" name="help_text" placeholder="ex: Încarcă o copie lizibilă, format PDF">
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Opțiuni (doar pentru „Listă de opțiuni”, câte una pe linie)</label>
            <textarea name="options" rows="3" placeholder="Anul I&#10;Anul II&#10;Anul III"></textarea>
        </div>
        <div class="field">
            <label style="display:flex;align-items:center;gap:8px;margin-top:26px;">
                <input type="checkbox" name="is_required" value="1" checked> Obligatoriu
            </label>
        </div>
        <div class="field">
            <label style="display:flex;align-items:center;gap:8px;margin-top:26px;">
                <input type="checkbox" name="is_active" value="1" checked> Activ
            </label>
        </div>
        <div style="grid-column:1/-1;">
            <button class="btn" type="submit">Adaugă câmp</button>
        </div>
    </form>

    <table class="table">
        <thead>
        <tr>
            <th>Ordine</th>
            <th>Etichetă</th>
            <th>Cheie</th>
            <th>Tip</th>
            <th>Obligatoriu</th>
            <th>Stare</th>
            <th>Acțiuni</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($editingFields === []): ?>
            <tr><td colspan="7">Niciun câmp definit. Utilizatorul va completa doar datele de bază.</td></tr>
        <?php else: ?>
            <?php foreach ($editingFields as $field): ?>
                <?php
                $fieldId = (int) ($field['id'] ?? 0);
                $formId = 'profession-field-' . $fieldId;
                $fieldType = (string) ($field['field_type'] ?? 'text');
                $optionsText = implode("\n", is_array($field['options'] ?? null) ? $field['options'] : []);
                ?>
                <tr>
                    <td>
                        <input type="number" name="sort_order" form="<?= $formId ?>" style="width:74px;"
                               value="<?= (int) ($field['sort_order'] ?? 0) ?>">
                    </td>
                    <td>
                        <input type="text" name="label" form="<?= $formId ?>" required style="min-width:180px;"
                               value="<?= htmlspecialchars((string) ($field['label'] ?? ''), ENT_QUOTES) ?>">
                    </td>
                    <td><code><?= htmlspecialchars((string) ($field['field_key'] ?? ''), ENT_QUOTES) ?></code></td>
                    <td>
                        <select name="field_type" form="<?= $formId ?>">
                            <?php foreach ($fieldTypes as $type): ?>
                                <option value="<?= htmlspecialchars((string) $type, ENT_QUOTES) ?>"
                                    <?= $fieldType === $type ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($fieldTypeLabels[$type] ?? (string) $type, ENT_QUOTES) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($fieldType === 'select'): ?>
                            <textarea name="options" form="<?= $formId ?>" rows="2" style="margin-top:6px;width:100%;"
                                      placeholder="Câte o opțiune pe linie"><?= htmlspecialchars($optionsText, ENT_QUOTES) ?></textarea>
                        <?php endif; ?>
                    </td>
                    <td>
                        <input type="checkbox" name="is_required" value="1" form="<?= $formId ?>"
                            <?= (int) ($field['is_required'] ?? 0) === 1 ? 'checked' : '' ?>>
                    </td>
                    <td>
                        <input type="checkbox" name="is_active" value="1" form="<?= $formId ?>"
                            <?= (int) ($field['is_active'] ?? 0) === 1 ? 'checked' : '' ?>>
                    </td>
                    <td style="display:flex;gap:8px;">
                        <form id="<?= $formId ?>" method="post" action="/admin/accounts/professions/fields">
                            <?= \App\Support\AdminCsrf::camp() . "\n" ?>
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="profession_id" value="<?= $editingId ?>">
                            <input type="hidden" name="field_id" value="<?= $fieldId ?>">
                            <input type="hidden" name="field_key"
                                   value="<?= htmlspecialchars((string) ($field['field_key'] ?? ''), ENT_QUOTES) ?>">
                            <input type="hidden" name="help_text"
                                   value="<?= htmlspecialchars((string) ($field['help_text'] ?? ''), ENT_QUOTES) ?>">
                            <button class="btn" type="submit">Salvează</button>
                        </form>
                        <form method="post" action="/admin/accounts/professions/fields"
                              onsubmit="return confirm('Ștergi acest câmp?');">
                            <?= \App\Support\AdminCsrf::camp() . "\n" ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="profession_id" value="<?= $editingId ?>">
                            <input type="hidden" name="field_id" value="<?= $fieldId ?>">
                            <button class="btn btn-secondary" type="submit">Șterge</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>
