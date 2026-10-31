<?php
/**
 * O secțiune a unui formular lung (WP-31; de exemplu editorul produsului: Informații de bază, Preț și TVA, Stoc…): titlul,
 * o propoziție, câmpurile. Cu „restransa”, secțiunea se poate strânge (<details>), pentru setări folosite rar.
 *
 * @var string $titlu
 * @var string $descriere  opțional, text simplu
 * @var string $continut   HTML deja pregătit (câmpurile)
 * @var string $actiuni    opțional, HTML (butoane în dreapta titlului)
 * @var string $id         opțional (ancora secțiunii, pentru cuprins)
 * @var bool   $restransa  opțional: strânsă la început (se deschide cu un clic)
 * @var bool   $pliabila   opțional: se poate strânge (implicit doar dacă e restransa)
 */
$id = (string) ($id ?? ('sectiune-' . substr(md5((string) ($titlu ?? '')), 0, 6)));
$pliabila = !empty($pliabila) || !empty($restransa);
?>
<?php if ($pliabila): ?>
<details class="adm-sectiune adm-sectiune--pliabila" id="<?= adm_e($id) ?>"<?= empty($restransa) ? ' open' : '' ?>>
    <summary class="adm-sectiune__antet">
        <span class="adm-sectiune__titlu" id="<?= adm_e($id) ?>-titlu"><?= adm_e($titlu ?? '') ?></span>
        <?php if (trim((string) ($descriere ?? '')) !== ''): ?>
            <span class="adm-sectiune__descriere"><?= adm_e($descriere) ?></span>
        <?php endif; ?>
    </summary>
    <div class="adm-sectiune__corp"><?= $continut ?? '' ?></div>
</details>
<?php else: ?>
<section class="adm-sectiune" id="<?= adm_e($id) ?>" aria-labelledby="<?= adm_e($id) ?>-titlu">
    <header class="adm-sectiune__antet">
        <div>
            <h2 class="adm-sectiune__titlu" id="<?= adm_e($id) ?>-titlu"><?= adm_e($titlu ?? '') ?></h2>
            <?php if (trim((string) ($descriere ?? '')) !== ''): ?>
                <p class="adm-sectiune__descriere"><?= adm_e($descriere) ?></p>
            <?php endif; ?>
        </div>
        <?php if (trim((string) ($actiuni ?? '')) !== ''): ?>
            <div class="adm-sectiune__actiuni"><?= $actiuni ?></div>
        <?php endif; ?>
    </header>
    <div class="adm-sectiune__corp"><?= $continut ?? '' ?></div>
</section>
<?php endif; ?>
