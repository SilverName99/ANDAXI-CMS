<?php
/**
 * Marketing → Campanii pe site (pluginul campanii; App\Http\Controllers\CampaniiController, App\Support\Campanii): lista
 * campaniilor, cu starea fiecăreia acum (activă, programată, încheiată, oprită).
 *
 * @var bool $disponibil
 * @var list<array<string, mixed>> $campanii
 */
$campanii = is_array($campanii ?? null) ? $campanii : [];
admin_componenta('antet-pagina', [
    'titlu' => 'Campanii pe site',
    'nivel' => 1,
    'subtitlu' => 'Pagina campaniei la prima intrare pe site, insigna pe produsele alese și bannerul de sus, doar între început și sfârșit.',
    'ajutor' => [
        'Pe site e activă cel mult o campanie odată: două campanii pornite nu pot avea date care se suprapun.',
        'Pagina campaniei apare o dată pe vizită, doar oamenilor care intră pe site din afara lui (de pe Google, dintr-un email, '
            . 'de pe o rețea socială); butonul din stânga sus îi duce la pagina pe care o ceruseră. Roboții (Google) văd site-ul ca de obicei.',
        'Conținutul paginii se scrie în Conținut → Pagini (o pagină nepublicată, ciornă, se vede doar prin campanie).',
    ],
    'actiuni' => $disponibil ? '<a class="btn" href="/admin/campanii/noua">Campanie nouă</a>' : '',
]);
?>
<?php if (!$disponibil): ?>
<?php admin_componenta('mesaj', ['tip' => 'avertisment', 'titlu' => 'Tabelele campaniilor lipsesc',
    'text' => 'Rulează migrările (scripts/migreaza.php --aplica). Până atunci nicio campanie nu se arată pe site.']); ?>
<?php elseif ($campanii === []): ?>
<?php admin_componenta('stare-goala', ['titlu' => 'Nicio campanie încă',
    'text' => 'O campanie are o pagină de deschidere, produse cu insignă și un banner, între o dată de început și una de sfârșit.',
    'actiune' => '<a class="btn" href="/admin/campanii/noua">Campanie nouă</a>']); ?>
<?php else: ?>
    <div class="panel" style="overflow-x:auto;">
        <table class="table">
            <thead>
                <tr>
                    <th>Campania</th>
                    <th>Perioada</th>
                    <th>Stare</th>
                    <th>Pe site</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($campanii as $c): ?>
                <?php
                $stare = \App\Support\Campanii::stare($c);
                $parti = [];
                if ((int) $c['pagina_la_intrare'] === 1 && (int) ($c['pagina_id'] ?? 0) > 0) {
                    $parti[] = 'pagina la intrare';
                }
                if ((int) $c['numar_produse'] > 0) {
                    $parti[] = (int) $c['numar_produse'] === 1 ? '1 produs cu insignă' : (int) $c['numar_produse'] . ' produse cu insignă';
                }
                if ((int) $c['banner_pornit'] === 1) {
                    $parti[] = 'banner';
                }
                ?>
                <tr>
                    <td>
                        <a href="/admin/campanii/<?= (int) $c['id'] ?>"><strong><?= adm_e((string) $c['nume']) ?></strong></a><br>
                        <small class="muted adm-mono">/campanie/<?= adm_e((string) $c['slug']) ?></small>
                    </td>
                    <td style="white-space:nowrap;"><?= adm_e(\App\Support\Campanii::interval($c)) ?></td>
                    <td><span class="adm-stare adm-stare--<?= $stare === 'activa' ? 'ok' : ($stare === 'programata' ? 'programat' : ($stare === 'oprita' ? 'oprit' : 'neutru')) ?>"><?= adm_e(\App\Support\Campanii::STARI[$stare]) ?></span></td>
                    <td><?= adm_e($parti !== [] ? implode(', ', $parti) : '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
