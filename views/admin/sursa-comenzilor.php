<?php
/**
 * Rapoarte → Sursa comenzilor (WP-40, B9; App\Http\Controllers\SursaComenziiController, App\Support\SursaComenzii): comenzile
 * pe săptămâni și pe surse. Doar numere, nicio dată a unui client.
 *
 * @var array{saptamani: list<array<string, mixed>>, totaluri: array<string, array{numar: int, valoare: float}>, total: int, coloana: bool}|null $raport
 * @var int $saptamani
 * @var bool $pornita
 */
$etichete = \App\Support\SursaComenzii::SURSE + ['necunoscuta' => 'Necunoscută'];
$lei = static fn (float $v): string => number_format($v, 2, ',', '.') . ' lei';
admin_componenta('antet-pagina', [
    'titlu' => 'Sursa comenzilor',
    'nivel' => 1,
    'subtitlu' => 'De unde au venit clienții care au comandat: Google, reclamă Google, email, campanie, alt site sau direct, pe săptămâni.',
    'ajutor' => [
        'Sursa se ia din vizita care a adus clientul pe site (ultima venită de undeva în 30 de zile): un link de reclamă Google, un link din email, căutarea Google, alt site. Fără nimic de acestea, „Direct”.',
        'Se numără toate comenzile (și cele ale vizitatorilor care n-au acceptat cookie-urile de analiză), fără nicio dată a clientului. Comenzile de dinainte de pornire au sursa „Necunoscută”.',
    ],
]);
?>
<?php if (!$pornita): ?>
<?php admin_componenta('mesaj', ['tip' => 'info', 'titlu' => 'Sursa comenzilor nu se notează pe acest site',
    'text' => 'Comutatorul sursa_comenzii e oprit: comenzile noi nu primesc sursa. Se pornește după ce vezi raportul pe site-ul de verificare.']); ?>
<?php endif; ?>
<?php if ($raport === null || !$raport['coloana']): ?>
<?php admin_componenta('mesaj', ['tip' => 'avertisment', 'titlu' => 'Coloana sursei lipsește', 'text' => 'Rulează migrările (scripts/migreaza.php --aplica).']); ?>
<?php else: ?>
<form method="get" action="/admin/orders/sursa-comenzilor" class="adm-sursa-filtru">
    <label for="sursa-saptamani">Ultimele</label>
    <select id="sursa-saptamani" name="saptamani">
<?php foreach ([4, 8, 12, 26, 52] as $n): ?>
        <option value="<?= $n ?>"<?= $n === $saptamani ? ' selected' : '' ?>><?= $n ?> săptămâni</option>
<?php endforeach; ?>
    </select>
    <button class="btn btn-secondary" type="submit">Arată</button>
</form>
<?php
$coloane = [['eticheta' => 'Săptămâna', 'cheie' => 'saptamana']];
foreach ($etichete as $eticheta) {
    $coloane[] = ['eticheta' => $eticheta, 'aliniere' => 'dreapta'];
}
$coloane[] = ['eticheta' => 'Total', 'aliniere' => 'dreapta'];
$randuri = [];
foreach ($raport['saptamani'] as $s) {
    $celule = ['<span class="adm-numar">' . adm_e(date('d.m', (int) strtotime($s['de_la'])) . ' – ' . date('d.m.Y', (int) strtotime($s['pana_la']))) . '</span>'];
    foreach (array_keys($etichete) as $cheie) {
        $c = $s['surse'][$cheie] ?? ['numar' => 0, 'valoare' => 0.0];
        $celule[] = $c['numar'] > 0 ? '<span class="adm-numar" title="' . adm_e($lei((float) $c['valoare'])) . '">' . (int) $c['numar'] . '</span>' : '<span class="adm-numar">—</span>';
    }
    $celule[] = '<span class="adm-numar"><strong>' . (int) $s['total'] . '</strong></span>';
    $randuri[] = ['celule' => $celule];
}
$total = ['<strong>Total</strong>'];
foreach (array_keys($etichete) as $cheie) {
    $c = $raport['totaluri'][$cheie];
    $total[] = '<span class="adm-numar"><strong>' . (int) $c['numar'] . '</strong><br><small>' . adm_e($lei((float) $c['valoare'])) . '</small></span>';
}
$total[] = '<span class="adm-numar"><strong>' . (int) $raport['total'] . '</strong></span>';
$randuri[] = ['celule' => $total];
admin_componenta('tabel', [
    'id' => 'sursa-comenzilor-tabel',
    'legenda' => 'Comenzile pe săptămâni și pe surse (numărul; valoarea la trecerea cu mouse-ul și pe rândul „Total”)',
    'antet_fix' => true,
    'dens' => true,
    'coloane' => $coloane,
    'randuri' => $randuri,
]);
?>
<?php endif; ?>
