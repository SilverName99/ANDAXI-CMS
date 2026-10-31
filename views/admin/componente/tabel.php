<?php
/**
 * Tabelul listelor (WP-31). Celulele vin deja ca HTML (escapat de cine cheamă), ca să poată conține butoane sau bife.
 * Fără opțiunile de mai jos e tabelul simplu de până acum (pașii newsletterului).
 *
 * @var list<string|array{eticheta: string, cheie?: string, aliniere?: string, clasa?: string}> $coloane
 *      eticheta (text simplu); „cheie” = coloana se poate sorta (link care pune ?sort=<cheie>&dir=asc|desc în adresă,
 *      păstrând restul parametrilor: filtrele, pagina); „aliniere” = 'dreapta' (sume, numere) sau 'centru'; „clasa” = o clasă
 *      în plus pe celulele coloanei (WP-33: lățimile coloanelor listei de comenzi)
 * @var list<array{celule: list<string>, atribute?: string, id?: string|int, bifa_atribute?: string}> $randuri
 *      „id” = valoarea bifei rândului (cu $selectie); „bifa_atribute” = atribute în plus pe bifa rândului, deja escapate
 *      (WP-33: `disabled` pe o comandă anulată, `data-order-id`)
 * @var string $gol               HTML arătat când nu sunt rânduri (de obicei o stare goală)
 * @var string $id                opțional (obligatoriu cu $selectie)
 * @var string $legenda           opțional, pentru cititoarele de ecran
 * @var array{cheie?: string, dir?: string} $sortare   coloana sortată acum (din adresă) și direcția
 * @var array{nume: string, formular: string, eticheta?: string} $selectie
 *      bife pe fiecare rând (name = „nume”, de exemplu „ids[]”, în formularul „formular” al barei acțiunilor în masă,
 *      prin atributul form=), plus „toate” în antet; Shift+clic alege un șir, Esc golește alegerea (tabel.js)
 * @var bool $antet_fix           antetul rămâne sus când lista se derulează
 * @var bool $dens                rânduri de 36 px în loc de 44 px
 * @var string $clasa             opțional (WP-33): clase în plus pe tabel (stilurile celulelor unei liste, de exemplu „orders-table”)
 */
$randuri = (array) ($randuri ?? []);
$sortare = (array) ($sortare ?? []);
$selectie = isset($selectie['nume'], $selectie['formular']) ? (array) $selectie : null;
$linkSortare = static function (string $cheie, string $dir): string {
    $parametri = $_GET;
    unset($parametri['pagina'], $parametri['page']);
    $parametri['sort'] = $cheie;
    $parametri['dir'] = $dir;
    return '?' . http_build_query($parametri);
};
$clase = 'adm-tabel' . (!empty($antet_fix) ? ' adm-tabel--antet-fix' : '') . (!empty($dens) ? ' adm-tabel--dens' : '')
    . ($selectie !== null ? ' adm-tabel--selectie' : '')
    . (preg_match('/^[a-z0-9_ -]+$/', (string) ($clasa ?? '')) === 1 ? ' ' . $clasa : '');
?>
<?php if ($randuri === []): ?>
    <?= $gol ?? '' ?>
<?php else: ?>
    <div class="adm-tabel__cadru">
        <table class="<?= $clase ?>" <?= isset($id) ? 'id="' . adm_e($id) . '"' : '' ?>>
            <?php if (trim((string) ($legenda ?? '')) !== ''): ?>
                <caption class="adm-ascuns"><?= adm_e($legenda) ?></caption>
            <?php endif; ?>
            <thead>
                <tr>
                    <?php if ($selectie !== null): ?>
                        <th scope="col" class="adm-tabel__bifa">
                            <input type="checkbox" class="adm-tabel__toate" aria-label="<?= adm_e($selectie['eticheta'] ?? 'Alege toate rândurile') ?>">
                        </th>
                    <?php endif; ?>
                    <?php foreach ((array) ($coloane ?? []) as $coloana): ?>
                        <?php
                        $coloana = is_array($coloana) ? $coloana : ['eticheta' => (string) $coloana];
                        $cheie = (string) ($coloana['cheie'] ?? '');
                        $aliniere = in_array($coloana['aliniere'] ?? '', ['dreapta', 'centru'], true) ? ' adm-tabel--' . $coloana['aliniere'] : '';
                        $aliniere .= preg_match('/^[a-z0-9_ -]+$/', (string) ($coloana['clasa'] ?? '')) === 1 ? ' ' . $coloana['clasa'] : '';
                        $esteSortata = $cheie !== '' && ($sortare['cheie'] ?? '') === $cheie;
                        $dir = $esteSortata && strtolower((string) ($sortare['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
                        ?>
                        <th scope="col" class="<?= trim($aliniere) ?>"<?= $esteSortata ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>>
                            <?php if ($cheie !== ''): ?>
                                <a class="adm-tabel__sortare" href="<?= adm_e($linkSortare($cheie, $esteSortata && $dir === 'asc' ? 'desc' : 'asc')) ?>">
                                    <?= adm_e($coloana['eticheta'] ?? '') ?><span class="adm-tabel__sageata" aria-hidden="true"><?= $esteSortata ? ($dir === 'asc' ? '▲' : '▼') : '↕' ?></span>
                                </a>
                            <?php else: ?>
                                <?= adm_e($coloana['eticheta'] ?? '') ?>
                            <?php endif; ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($randuri as $rand): ?>
                    <tr <?= (string) ($rand['atribute'] ?? '') ?>>
                        <?php if ($selectie !== null): ?>
                            <td class="adm-tabel__bifa">
                                <input type="checkbox" class="adm-tabel__rand" name="<?= adm_e($selectie['nume']) ?>" value="<?= adm_e($rand['id'] ?? '') ?>"
                                       form="<?= adm_e($selectie['formular']) ?>" aria-label="Alege rândul <?= adm_e($rand['id'] ?? '') ?>"<?= isset($rand['bifa_atribute']) ? ' ' . $rand['bifa_atribute'] : '' ?>>
                            </td>
                        <?php endif; ?>
                        <?php foreach ((array) ($rand['celule'] ?? []) as $n => $celula): ?>
                            <?php
                            $col = ((array) ($coloane ?? []))[$n] ?? [];
                            $claseCelula = trim((is_array($col) && in_array($col['aliniere'] ?? '', ['dreapta', 'centru'], true) ? 'adm-tabel--' . $col['aliniere'] : '')
                                . (is_array($col) && preg_match('/^[a-z0-9_ -]+$/', (string) ($col['clasa'] ?? '')) === 1 ? ' ' . $col['clasa'] : ''));
                            $al = $claseCelula !== '' ? ' class="' . $claseCelula . '"' : '';
                            ?>
                            <td<?= $al ?>><?= $celula ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
