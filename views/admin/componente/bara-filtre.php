<?php
/**
 * Bara de filtre a unei liste (WP-31): un formular GET, deci filtrele stau în adresă: Înapoi, reîncărcarea și un link
 * trimis cuiva duc la aceeași listă filtrată. Cu „automat”, o listă aleasă se aplică imediat, iar căutarea după o scurtă
 * pauză de scris (public/assets/js/admin/filtre.js); fără JavaScript rămâne butonul „Aplică”.
 *
 * @var string $actiune    adresa listei (implicit pagina curentă, fără parametri)
 * @var list<array{tip: string, nume: string, eticheta: string, valoare?: string, optiuni?: array<string, string>,
 *                 placeholder?: string, tasta?: string}> $campuri
 *      tip: 'cautare' | 'select' | 'data'; „optiuni” (select): valoare => eticheta, prima de obicei '' => 'Toate';
 *      „tasta” (căutare): o tastă care mută focusul în câmp, de exemplu „/” (apare în dreapta câmpului)
 * @var array<string, string> $pastreaza   parametri păstrați (ascunși), de exemplu tab-ul ales
 * @var string $reseteaza  adresa fără filtre (implicit $actiune)
 * @var bool   $automat
 * @var string $id         opțional
 * @var string $eticheta   pentru cititoarele de ecran (implicit „Filtre”)
 */
$actiune = (string) ($actiune ?? (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''));
$id = (string) ($id ?? 'adm-filtre');
$areFiltre = false;
foreach ((array) ($campuri ?? []) as $camp) {
    $areFiltre = $areFiltre || trim((string) ($camp['valoare'] ?? '')) !== '';
}
?>
<form class="adm-filtre" id="<?= adm_e($id) ?>" method="get" action="<?= adm_e($actiune) ?>" role="search" aria-label="<?= adm_e($eticheta ?? 'Filtre') ?>"<?= !empty($automat) ? ' data-adm-filtre-automat' : '' ?>>
    <?php foreach ((array) ($pastreaza ?? []) as $numeAscuns => $valoareAscunsa): ?>
        <input type="hidden" name="<?= adm_e($numeAscuns) ?>" value="<?= adm_e($valoareAscunsa) ?>">
    <?php endforeach; ?>
    <?php foreach ((array) ($campuri ?? []) as $n => $camp): ?>
        <?php $idCamp = $id . '-' . $n; $tip = (string) ($camp['tip'] ?? 'cautare'); ?>
        <?php if ($tip === 'cautare'): ?>
            <div class="adm-filtre__cautare">
                <label class="adm-ascuns" for="<?= adm_e($idCamp) ?>"><?= adm_e($camp['eticheta'] ?? 'Caută') ?></label>
                <input type="search" id="<?= adm_e($idCamp) ?>" name="<?= adm_e($camp['nume'] ?? 'q') ?>" value="<?= adm_e($camp['valoare'] ?? '') ?>"
                       placeholder="<?= adm_e($camp['placeholder'] ?? '') ?>"<?= isset($camp['tasta']) ? ' data-adm-tasta="' . adm_e($camp['tasta']) . '" aria-keyshortcuts="' . adm_e($camp['tasta']) . '"' : '' ?>>
                <?php if (isset($camp['tasta'])): ?><kbd aria-hidden="true"><?= adm_e($camp['tasta']) ?></kbd><?php endif; ?>
            </div>
        <?php elseif ($tip === 'select'): ?>
            <label class="adm-filtre__camp" for="<?= adm_e($idCamp) ?>">
                <span><?= adm_e($camp['eticheta'] ?? '') ?></span>
                <select id="<?= adm_e($idCamp) ?>" name="<?= adm_e($camp['nume'] ?? '') ?>">
                    <?php foreach ((array) ($camp['optiuni'] ?? []) as $valoare => $text): ?>
                        <option value="<?= adm_e($valoare) ?>"<?= (string) $valoare === (string) ($camp['valoare'] ?? '') ? ' selected' : '' ?>><?= adm_e($text) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php else: ?>
            <label class="adm-filtre__camp" for="<?= adm_e($idCamp) ?>">
                <span><?= adm_e($camp['eticheta'] ?? '') ?></span>
                <input type="date" id="<?= adm_e($idCamp) ?>" name="<?= adm_e($camp['nume'] ?? '') ?>" value="<?= adm_e($camp['valoare'] ?? '') ?>">
            </label>
        <?php endif; ?>
    <?php endforeach; ?>
    <button type="submit" class="btn btn-secondary adm-filtre__aplica">Aplică</button>
    <?php if ($areFiltre): ?>
        <a class="adm-filtre__reseteaza" href="<?= adm_e($reseteaza ?? $actiune) ?>">Resetează</a>
    <?php endif; ?>
</form>
