<?php
/**
 * Panoul lateral (WP-31), în două feluri:
 *  - „coloana” (implicit): un rezumat sau o acțiune secundară lângă conținutul principal (pașii newsletterului);
 *  - „sertar”: se deschide din dreapta, peste pagină, cu un buton care are data-adm-panou="<id>" (opțional
 *    data-adm-panou-sursa="<adresă>": corpul se încarcă de acolo la deschidere, un GET spre o adresă de detalii care există
 *    deja, cu răspuns HTML). Se închide cu ×, cu un clic alături sau cu Esc; focusul revine pe butonul care l-a deschis
 *    (public/assets/js/admin/panou.js).
 *
 * @var string $titlu
 * @var string $continut  HTML deja pregătit (în sertar: ce se vede până se încarcă sursa, sau tot corpul fără sursă)
 * @var string $id        opțional (obligatoriu pentru sertar)
 * @var string $mod       'coloana' | 'sertar'
 * @var string $sursa     opțional (sertar): adresa implicită a corpului
 * @var string $subsol    opțional (sertar): HTML (butoanele de jos)
 */
$mod = ($mod ?? 'coloana') === 'sertar' ? 'sertar' : 'coloana';
?>
<?php if ($mod === 'coloana'): ?>
<aside class="adm-panou" <?= isset($id) ? 'id="' . adm_e($id) . '"' : '' ?> aria-label="<?= adm_e($titlu ?? '') ?>">
    <?php if (trim((string) ($titlu ?? '')) !== ''): ?>
        <h3 class="adm-panou__titlu"><?= adm_e($titlu) ?></h3>
    <?php endif; ?>
    <div class="adm-panou__corp"><?= $continut ?? '' ?></div>
</aside>
<?php else: ?>
<?php $id = (string) ($id ?? 'adm-sertar'); ?>
<div class="adm-sertar" id="<?= adm_e($id) ?>" hidden<?= trim((string) ($sursa ?? '')) !== '' ? ' data-adm-panou-sursa="' . adm_e($sursa) . '"' : '' ?>>
    <div class="adm-sertar__fundal"></div>
    <aside class="adm-panou adm-panou--sertar" role="dialog" aria-modal="true" aria-labelledby="<?= adm_e($id) ?>-titlu" tabindex="-1">
        <header class="adm-sertar__antet">
            <h2 class="adm-panou__titlu" id="<?= adm_e($id) ?>-titlu"><?= adm_e($titlu ?? '') ?></h2>
            <button type="button" class="adm-sertar__inchide" title="Închide (Esc)">×<span class="adm-ascuns">Închide panoul</span></button>
        </header>
        <div class="adm-panou__corp adm-sertar__corp" aria-live="polite"><?= $continut ?? '' ?></div>
        <?php if (trim((string) ($subsol ?? '')) !== ''): ?>
            <footer class="adm-sertar__subsol"><?= $subsol ?></footer>
        <?php endif; ?>
    </aside>
</div>
<?php endif; ?>
