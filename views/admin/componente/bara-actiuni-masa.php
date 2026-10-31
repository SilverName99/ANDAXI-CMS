<?php
/**
 * Bara acțiunilor în masă (WP-31): apare când sunt bifate rânduri în tabelul ei (componenta tabel cu „selectie”, al cărei
 * „formular” e „<id>-formular”), spune câte, și are butoanele acțiunilor. Fiecare buton trimite bifele, prin POST, la
 * adresa lui (formaction): doar adrese care există deja în admin (contractul adminului, G-admin). Esc golește alegerea și
 * ascunde bara (public/assets/js/admin/tabel.js).
 *
 * @var string $id         id-ul barei; formularul e „<id>-formular”
 * @var string $tabel      id-ul tabelului cu bifele
 * @var string $csrf       jetonul formularelor de admin (_csrf), dacă adresele îl cer
 * @var string $unitate    ce se numără, la plural (implicit „rânduri selectate”; de exemplu „comenzi selectate”)
 * @var list<array{eticheta: string, actiune: string, nume?: string, valoare?: string, tip?: string, confirmare?: string,
 *                 tasta?: string}> $actiuni
 *      tip: 'principal' | 'secundar' (implicit) | 'pericol'; „confirmare” = întrebarea dinaintea trimiterii;
 *      „tasta” = scurtătura, arătată pe buton (o leagă pagina, WP-33)
 */
$id = (string) ($id ?? 'adm-masa');
?>
<div class="adm-masa" id="<?= adm_e($id) ?>" role="region" aria-label="Acțiuni pentru rândurile selectate" data-adm-masa-tabel="<?= adm_e($tabel ?? '') ?>" hidden>
    <form id="<?= adm_e($id) ?>-formular" method="post" class="adm-masa__formular">
        <?php if (trim((string) ($csrf ?? '')) !== ''): ?>
            <input type="hidden" name="_csrf" value="<?= adm_e($csrf) ?>">
        <?php endif; ?>
        <span class="adm-masa__numar" aria-live="polite"><span class="adm-masa__cate">0</span> <?= adm_e($unitate ?? 'rânduri selectate') ?></span>
        <?php foreach ((array) ($actiuni ?? []) as $actiune): ?>
            <?php $tip = in_array($actiune['tip'] ?? '', ['principal', 'pericol'], true) ? $actiune['tip'] : 'secundar'; ?>
            <button type="submit" class="adm-masa__buton adm-masa__buton--<?= $tip ?>" formaction="<?= adm_e($actiune['actiune'] ?? '') ?>"
                    <?= isset($actiune['nume']) ? 'name="' . adm_e($actiune['nume']) . '" value="' . adm_e($actiune['valoare'] ?? '') . '"' : '' ?>
                    <?= isset($actiune['confirmare']) ? 'data-adm-confirmare="' . adm_e($actiune['confirmare']) . '"' : '' ?>>
                <?= adm_e($actiune['eticheta'] ?? '') ?><?php if (isset($actiune['tasta'])): ?> <kbd aria-hidden="true"><?= adm_e($actiune['tasta']) ?></kbd><?php endif; ?>
            </button>
        <?php endforeach; ?>
        <button type="button" class="adm-masa__goleste">Renunță <kbd aria-hidden="true">Esc</kbd></button>
    </form>
</div>
