<?php
/**
 * Formularul de înregistrare când există tipuri de cont configurate.
 *
 * Vizitatorul vede întâi doar selectorul de tip de cont. După alegere apar
 * datele comune (nume, prenume, email, telefon, parolă) și, sub ele, câmpurile
 * specifice categoriei alese.
 * Când nu e configurat niciun tip de cont, componenta nu randează nimic și
 * formularul rămâne cel clasic.
 *
 * @var array<int, array<string, mixed>> $professions  Meserii active, cu cheia `fields`.
 * @var array<string, mixed>             $professionConfig
 */
$professions = is_array($professions ?? null) ? $professions : [];
$professionConfig = is_array($professionConfig ?? null) ? $professionConfig : [];
$intro = trim((string) ($professionConfig['intro'] ?? ''));
$gdprLabel = trim((string) ($professionConfig['gdpr_label'] ?? ''));
$gdprUrl = trim((string) ($professionConfig['gdpr_url'] ?? ''));
$maxUploadMb = (float) ($professionConfig['max_upload_mb'] ?? 8);
$allowedExtensions = is_array($professionConfig['allowed_extensions'] ?? null)
    ? $professionConfig['allowed_extensions']
    : ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
$selectedProfessionId = (int) ($professionConfig['selected_profession_id'] ?? 0);
$acceptAttribute = implode(',', array_map(static fn (string $ext): string => '.' . $ext, $allowedExtensions));
$hasSelection = $selectedProfessionId > 0;
// La re-trimiterea unei cereri dintr-un cont existent nu mai cerem email/parolă.
$hideAccountFields = !empty($professionConfig['hide_account_fields']);
?>
<?php if ($professions !== []): ?>
<?php /* Stilurile câmpurilor de pe pagina de înregistrare (vertij, components/auth-form.php). */ ?>
<?php if (!empty($cuStil)): ?>
<style>
.profession-intro{margin:14px 0 0;color:#6b7280;font-size:13.5px;line-height:1.5;}
.profession-fieldset{margin:14px 0 0;padding:14px 16px 16px;border:1px solid #e5d9ec;border-radius:12px !important;background:#fdfbfe;}
.profession-fieldset legend{padding:0 8px;font-size:13px;font-weight:700;color:#6f2b98;}
.profession-account-fields{margin-top:16px;}
.profession-fieldset input[type=email],
.profession-fieldset input[type=password],
.profession-fieldset input[type=text],
.profession-fieldset input[type=date],
.profession-fieldset input[type=number],
.profession-fieldset select{width:100%;box-sizing:border-box;padding:11px 13px;border:1px solid #e5d9ec;border-radius:10px !important;font-size:15px;background:#fff;}
.profession-description{margin:0;color:#6b7280;font-size:13px;line-height:1.5;}
.profession-price{margin:8px 0 0;color:#4b5563;font-size:13px;line-height:1.5;}
.profession-fieldset textarea{width:100%;box-sizing:border-box;padding:11px 13px;border:1px solid #e5d9ec;border-radius:10px !important;font-size:15px;background:#fff;font-family:inherit;}
.profession-fieldset input[type=file]{width:100%;box-sizing:border-box;padding:9px 11px;border:1px dashed #d9c2e6;border-radius:10px !important;font-size:13.5px;background:#fff;}
.profession-hint{display:block;margin-top:5px;color:#8b8b9c;font-size:12px;line-height:1.45;}
.profession-gdpr{margin-top:16px;}
.profession-consent{display:flex;align-items:flex-start;gap:9px;margin:0;font-weight:500;color:#4b5563;font-size:13px;line-height:1.5;}
.profession-consent input[type=checkbox]{margin-top:2px;flex:0 0 auto;}
</style>
<?php endif; ?>
<div class="profession-block" style="grid-column:1/-1;">
    <?php if ($intro !== ''): ?>
        <p class="profession-intro"><?= htmlspecialchars($intro, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <div class="field">
        <label for="register-profession">Tip de cont <span aria-hidden="true">*</span></label>
        <select id="register-profession" name="profession_id" required data-profession-select>
            <option value="">Selectează...</option>
            <?php foreach ($professions as $profession): ?>
                <?php $professionId = (int) ($profession['id'] ?? 0); ?>
                <option value="<?= $professionId ?>" <?= $professionId === $selectedProfessionId ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) ($profession['name'] ?? ''), ENT_QUOTES) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php /* Datele de cont, comune tuturor tipurilor, apar imediat după alegere. */ ?>
    <?php if (!$hideAccountFields): ?>
    <fieldset
        class="profession-fieldset profession-account-fields"
        data-profession-account-fields
        <?= $hasSelection ? '' : 'disabled hidden' ?>
        style="<?= $hasSelection ? '' : 'display:none;' ?>"
    >
        <legend>Datele contului</legend>
        <div class="field">
            <label for="register-first-name">Prenume <span aria-hidden="true">*</span></label>
            <input id="register-first-name" type="text" name="first_name" autocomplete="given-name" required>
        </div>
        <div class="field">
            <label for="register-last-name">Nume <span aria-hidden="true">*</span></label>
            <input id="register-last-name" type="text" name="last_name" autocomplete="family-name" required>
        </div>
        <div class="field">
            <label for="register-email">Email <span aria-hidden="true">*</span></label>
            <input id="register-email" type="email" name="email" autocomplete="email" required>
        </div>
        <div class="field">
            <label for="register-phone">Telefon <span aria-hidden="true">*</span></label>
            <input id="register-phone" type="tel" name="phone" autocomplete="tel" required>
        </div>
        <div class="field">
            <label for="register-password">Parolă <span aria-hidden="true">*</span></label>
            <input id="register-password" type="password" name="password" minlength="8"
                   autocomplete="new-password" required>
            <small class="profession-hint">Minimum 8 caractere.</small>
        </div>
        <div class="field">
            <label for="register-password-confirm">Confirmă parola <span aria-hidden="true">*</span></label>
            <input id="register-password-confirm" type="password" name="password_confirm" minlength="8"
                   autocomplete="new-password" required>
        </div>
    </fieldset>
    <?php endif; ?>

    <?php foreach ($professions as $profession): ?>
        <?php
        $professionId = (int) ($profession['id'] ?? 0);
        $fields = is_array($profession['fields'] ?? null) ? $profession['fields'] : [];
        $description = trim((string) ($profession['description'] ?? ''));
        $price = (float) ($profession['activation_price'] ?? 0);
        $isSelected = $professionId === $selectedProfessionId;
        ?>
        <fieldset
            class="profession-fieldset"
            data-profession-fields="<?= $professionId ?>"
            <?= $isSelected ? '' : 'disabled hidden' ?>
            style="<?= $isSelected ? '' : 'display:none;' ?>"
        >
            <legend><?= htmlspecialchars((string) ($profession['name'] ?? ''), ENT_QUOTES) ?></legend>

            <?php if ($description !== ''): ?>
                <p class="profession-description"><?= htmlspecialchars($description, ENT_QUOTES) ?></p>
            <?php endif; ?>

            <?php if ($price > 0): ?>
                <?php $priceMonths = (int) ($profession['activation_months'] ?? 12); ?>
                <p class="profession-price">
                    Cotizația este de
                    <strong><?= number_format($price, 2, ',', '.') ?> lei /
                    <?= htmlspecialchars(\App\Support\ProfessionAccounts::durationLabel($priceMonths > 0 ? $priceMonths : 12), ENT_QUOTES) ?></strong>.
                    Se achită după aprobarea cererii și poți alege atunci perioada.
                </p>
            <?php endif; ?>

            <?php if ($fields === []): ?>
                <p class="profession-description">Pentru acest tip de cont nu sunt necesare date suplimentare.</p>
            <?php endif; ?>

            <?php foreach ($fields as $field): ?>
                <?php
                $fieldKey = (string) ($field['field_key'] ?? '');
                if ($fieldKey === '') {
                    continue;
                }
                $inputName = 'profession_field[' . $professionId . '][' . $fieldKey . ']';
                $fileName = 'profession_file_' . $professionId . '_' . $fieldKey;
                $fieldType = (string) ($field['field_type'] ?? 'text');
                $isRequired = (int) ($field['is_required'] ?? 0) === 1;
                $help = trim((string) ($field['help_text'] ?? ''));
                $options = is_array($field['options'] ?? null) ? $field['options'] : [];
                $inputId = 'pf-' . $professionId . '-' . $fieldKey;
                ?>
                <div class="field">
                    <?php if ($fieldType !== 'checkbox'): ?>
                        <label for="<?= htmlspecialchars($inputId, ENT_QUOTES) ?>">
                            <?= htmlspecialchars((string) ($field['label'] ?? ''), ENT_QUOTES) ?>
                            <?php if ($isRequired): ?><span aria-hidden="true">*</span><?php endif; ?>
                        </label>
                    <?php endif; ?>

                    <?php if ($fieldType === 'textarea'): ?>
                        <textarea id="<?= htmlspecialchars($inputId, ENT_QUOTES) ?>"
                                  name="<?= htmlspecialchars($inputName, ENT_QUOTES) ?>"
                                  rows="3" <?= $isRequired ? 'required' : '' ?>></textarea>
                    <?php elseif ($fieldType === 'select'): ?>
                        <select id="<?= htmlspecialchars($inputId, ENT_QUOTES) ?>"
                                name="<?= htmlspecialchars($inputName, ENT_QUOTES) ?>"
                                <?= $isRequired ? 'required' : '' ?>>
                            <option value="">Selectează...</option>
                            <?php foreach ($options as $option): ?>
                                <option value="<?= htmlspecialchars((string) $option, ENT_QUOTES) ?>">
                                    <?= htmlspecialchars((string) $option, ENT_QUOTES) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($fieldType === 'checkbox'): ?>
                        <label class="profession-consent" for="<?= htmlspecialchars($inputId, ENT_QUOTES) ?>">
                            <input id="<?= htmlspecialchars($inputId, ENT_QUOTES) ?>" type="checkbox" value="1"
                                   name="<?= htmlspecialchars($inputName, ENT_QUOTES) ?>"
                                   <?= $isRequired ? 'required' : '' ?>>
                            <span><?= htmlspecialchars((string) ($field['label'] ?? ''), ENT_QUOTES) ?></span>
                        </label>
                    <?php elseif ($fieldType === 'file'): ?>
                        <input id="<?= htmlspecialchars($inputId, ENT_QUOTES) ?>"
                               type="file"
                               name="<?= htmlspecialchars($fileName, ENT_QUOTES) ?>"
                               accept="<?= htmlspecialchars($acceptAttribute, ENT_QUOTES) ?>"
                               <?= $isRequired ? 'required' : '' ?>>
                        <small class="profession-hint">
                            Acceptăm <?= htmlspecialchars(strtoupper(implode(', ', $allowedExtensions)), ENT_QUOTES) ?>,
                            maximum <?= htmlspecialchars(rtrim(rtrim(number_format($maxUploadMb, 1, '.', ''), '0'), '.'), ENT_QUOTES) ?> MB per fișier.
                        </small>
                    <?php elseif ($fieldType === 'date'): ?>
                        <input id="<?= htmlspecialchars($inputId, ENT_QUOTES) ?>" type="date"
                               name="<?= htmlspecialchars($inputName, ENT_QUOTES) ?>"
                               <?= $isRequired ? 'required' : '' ?>>
                    <?php elseif ($fieldType === 'number'): ?>
                        <input id="<?= htmlspecialchars($inputId, ENT_QUOTES) ?>" type="number" step="any"
                               name="<?= htmlspecialchars($inputName, ENT_QUOTES) ?>"
                               <?= $isRequired ? 'required' : '' ?>>
                    <?php else: ?>
                        <input id="<?= htmlspecialchars($inputId, ENT_QUOTES) ?>" type="text"
                               name="<?= htmlspecialchars($inputName, ENT_QUOTES) ?>"
                               <?= $isRequired ? 'required' : '' ?>>
                    <?php endif; ?>

                    <?php if ($help !== ''): ?>
                        <small class="profession-hint"><?= htmlspecialchars($help, ENT_QUOTES) ?></small>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </fieldset>
    <?php endforeach; ?>

    <?php if ($gdprLabel !== ''): ?>
        <div class="field profession-gdpr" data-profession-gdpr
             <?= $hasSelection ? '' : 'hidden' ?>
             style="<?= $hasSelection ? '' : 'display:none;' ?>">
            <label class="profession-consent">
                <input type="checkbox" name="account_gdpr_accept" value="1" <?= $hasSelection ? 'required' : '' ?>>
                <span>
                    <?= htmlspecialchars($gdprLabel, ENT_QUOTES) ?>
                    <?php if ($gdprUrl !== ''): ?>
                        <a href="<?= htmlspecialchars($gdprUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener">Vezi detalii</a>
                    <?php endif; ?>
                </span>
            </label>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var select = document.querySelector('[data-profession-select]');
    if (!select) {
        return;
    }
    var fieldsets = Array.prototype.slice.call(document.querySelectorAll('[data-profession-fields]'));
    var accountFields = document.querySelector('[data-profession-account-fields]');
    var gdpr = document.querySelector('[data-profession-gdpr]');

    function toggle(el, isVisible) {
        if (!el) {
            return;
        }
        el.hidden = !isVisible;
        el.style.display = isVisible ? '' : 'none';
    }

    function syncFields() {
        var selected = select.value;
        var hasSelection = selected !== '';

        // `disabled` scoate campurile ascunse din submit si din validarea HTML5.
        if (accountFields) {
            accountFields.disabled = !hasSelection;
            toggle(accountFields, hasSelection);
        }

        fieldsets.forEach(function (fieldset) {
            var isActive = fieldset.getAttribute('data-profession-fields') === selected;
            fieldset.disabled = !isActive;
            toggle(fieldset, isActive);
        });

        if (gdpr) {
            toggle(gdpr, hasSelection);
            var consent = gdpr.querySelector('input[type="checkbox"]');
            if (consent) {
                consent.required = hasSelection;
                if (!hasSelection) {
                    consent.checked = false;
                }
            }
        }
    }

    select.addEventListener('change', syncFields);
    syncFields();
})();
</script>
<?php endif; ?>
