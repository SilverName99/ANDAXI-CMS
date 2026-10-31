<?php
/**
 * Tab-urile unei liste, fiecare cu numărul lui (WP-31; de exemplu statusurile comenzilor: Toate 199 · Noi 7 · …). Sunt
 * linkuri (filtrul stă în adresă, deci Înapoi duce la același tab), nu butoane.
 *
 * @var list<array{eticheta: string, href: string, numar?: int|string|null, activ?: bool, culoare?: string}> $taburi
 *      „culoare” = un pătrățel colorat înaintea etichetei (culoarea statusului, un cod #rrggbb)
 * @var string $eticheta   ce sunt tab-urile, pentru cititoarele de ecran (de exemplu „Filtrează după status”)
 * @var string $id         opțional
 * @var bool   $panouri    opțional (WP-35): tab-urile unei pagini de setări; fiecare tab are „cheie”, iar partea lui din pagină
 *                         are data-adm-tab-panou="<cheie>" (adm_tab_ales() alege tab-ul, taburi.js le schimbă fără reîncărcare)
 * @var string $tinta      opțional (cu $panouri): id-ul părții la care coboară pagina (o adresă veche, de exemplu ?tab=tva)
 */
$panouri = !empty($panouri);
?>
<nav class="adm-taburi" <?= isset($id) ? 'id="' . adm_e($id) . '"' : '' ?> aria-label="<?= adm_e($eticheta ?? 'Tab-uri') ?>"<?= $panouri ? ' data-adm-taburi-panouri' : '' ?><?= $panouri && trim((string) ($tinta ?? '')) !== '' ? ' data-adm-tab-tinta="' . adm_e($tinta) . '"' : '' ?>>
    <ul class="adm-taburi__lista">
        <?php foreach ((array) ($taburi ?? []) as $tab): ?>
            <?php $activ = !empty($tab['activ']); $culoare = (string) ($tab['culoare'] ?? ''); ?>
            <li>
                <a class="adm-taburi__tab<?= $activ ? ' este-activ' : '' ?>" href="<?= adm_e($tab['href'] ?? '#') ?>"<?= $activ ? ' aria-current="page"' : '' ?><?= $panouri && isset($tab['cheie']) ? ' data-adm-tab="' . adm_e($tab['cheie']) . '"' : '' ?>>
                    <?php if (preg_match('/^#[0-9a-fA-F]{6}$/', $culoare) === 1): ?>
                        <span class="adm-taburi__punct" style="background:<?= adm_e($culoare) ?>" aria-hidden="true"></span>
                    <?php endif; ?>
                    <span><?= adm_e($tab['eticheta'] ?? '') ?></span>
                    <?php if (isset($tab['numar']) && $tab['numar'] !== ''): ?>
                        <span class="adm-numar"><?= adm_e($tab['numar']) ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
