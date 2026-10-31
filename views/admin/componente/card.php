<?php
/**
 * Un card (WP-31): o cifră a zilei (Acasă: de procesat, vânzări azi…), un rezumat sau o casetă cu conținut. Cu „href”,
 * tot cardul e un link.
 *
 * @var string $titlu
 * @var string $valoare    opțional: cifra mare (text; fontul cu cifre egale)
 * @var string $detaliu    opțional, text simplu sub cifră (de exemplu „+12 % față de joia trecută”)
 * @var string $continut   opțional, HTML
 * @var string $actiuni    opțional, HTML (linkuri, butoane) — doar fără „href”
 * @var string $href       opțional: cardul întreg e un link
 * @var string $ton        'neutru' (implicit) | 'ok' | 'atentie' | 'eroare' | 'info': culoarea benzii din stânga
 * @var string $id         opțional
 */
$ton = in_array($ton ?? '', ['ok', 'atentie', 'eroare', 'info'], true) ? $ton : 'neutru';
$tag = trim((string) ($href ?? '')) !== '' ? 'a' : 'section';
?>
<<?= $tag ?> class="adm-card adm-card--<?= $ton ?><?= $tag === 'a' ? ' adm-card--link' : '' ?>"<?= $tag === 'a' ? ' href="' . adm_e($href) . '"' : '' ?><?= isset($id) ? ' id="' . adm_e($id) . '"' : '' ?>>
    <span class="adm-card__titlu"><?= adm_e($titlu ?? '') ?></span>
    <?php if (trim((string) ($valoare ?? '')) !== ''): ?>
        <span class="adm-card__valoare"><?= adm_e($valoare) ?></span>
    <?php endif; ?>
    <?php if (trim((string) ($detaliu ?? '')) !== ''): ?>
        <span class="adm-card__detaliu"><?= adm_e($detaliu) ?></span>
    <?php endif; ?>
    <?php if (trim((string) ($continut ?? '')) !== ''): ?>
        <div class="adm-card__corp"><?= $continut ?></div>
    <?php endif; ?>
    <?php if ($tag !== 'a' && trim((string) ($actiuni ?? '')) !== ''): ?>
        <div class="adm-card__actiuni"><?= $actiuni ?></div>
    <?php endif; ?>
</<?= $tag ?>>
