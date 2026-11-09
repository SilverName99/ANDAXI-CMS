<?php
/**
 * Rapoarte → Statistici → Impactul unei campanii sau postări (pluginul `statistici`): dintr-o sursă (de exemplu TikTok) sau dintr-o
 * campanie utm, de la un moment ales, vizitele, comenzile și veniturile pe ore, lângă aceleași ore cu o săptămână înainte.
 *
 * @var string|null $eroare
 * @var array{sursa: string, campanie: string, start: int, ore: int, start_text: string} $parametri
 * @var list<array<string, mixed>>|null $serie
 * @var list<string> $campanii
 */
use App\Support\Statistici;

$e = static fn (mixed $v): string => adm_e($v);
$tab = 'impact';
?>
<div class="st-pagina">
<?php require __DIR__ . '/statistici-cap.php'; ?>
<section class="st-sectiune">
    <div class="st-sectiune__antet"><h2>Impactul unei campanii sau postări</h2></div>
    <p class="st-nota" style="margin:0 0 12px">Alege sursa (de exemplu TikTok) sau campania și momentul postării: vezi pe ore vizitele, comenzile și veniturile venite de acolo, față de aceleași ore de acum o săptămână. Vizitele se numără după ora la care au început, comenzile după ora plasării; o postare fără link (doar un video) aduce de obicei vizite din aplicație (TikTok și Instagram se recunosc și fără Referer).</p>
<?php if (isset($eroare)): ?>
<?php admin_componenta('mesaj', ['tip' => 'avertisment', 'titlu' => 'Impactul nu se poate arăta', 'text' => (string) $eroare]); ?>
<?php else: ?>
<style>
.st-impact-form { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: flex-end; margin: 0 0 12px; }
.st-impact-form label { display: flex; flex-direction: column; gap: 4px; font-size: 13px; color: var(--adm-text-secundar); }
.st-impact-form select, .st-impact-form input { min-height: 36px; }
.st-cadru { overflow-x: auto; }
.st-tabel { width: 100%; border-collapse: collapse; font-size: 13px; }
.st-tabel th, .st-tabel td { padding: 6px 8px; border-bottom: 1px solid var(--adm-linie); text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.st-tabel th:first-child, .st-tabel td:first-child { text-align: left; }
.st-tabel tfoot td { font-weight: 700; }
.st-bara { display: inline-block; height: 8px; background: var(--adm-accent); border-radius: 2px; vertical-align: middle; margin-right: 6px; }
.st-bara--inainte { background: var(--adm-text-secundar); opacity: .4; }
.st-nota { font-size: 12px; color: var(--adm-text-secundar); }
</style>
<form method="get" action="/admin/statistici" class="st-impact-form">
    <input type="hidden" name="tab" value="impact">
    <label>Sursa
        <select name="sursa">
<?php foreach (Statistici::SURSE as $cheie => $nume): ?>
            <option value="<?= $e($cheie) ?>"<?= $cheie === $parametri['sursa'] ? ' selected' : '' ?>><?= $e($nume) ?></option>
<?php endforeach; ?>
        </select>
    </label>
    <label>sau campania (utm_campaign)
        <input type="text" name="campanie" list="st-campanii" value="<?= $e($parametri['campanie']) ?>" placeholder="opțional" maxlength="100">
        <datalist id="st-campanii">
<?php foreach ($campanii as $c): ?>
            <option value="<?= $e($c) ?>"></option>
<?php endforeach; ?>
        </datalist>
    </label>
    <label>De la (momentul postării)
        <input type="datetime-local" name="start" value="<?= $e($parametri['start_text']) ?>" step="3600">
    </label>
    <label>Câte ore
        <select name="ore">
<?php foreach ([12, 24, 48, 72, 168] as $ore): ?>
            <option value="<?= $ore ?>"<?= $ore === $parametri['ore'] ? ' selected' : '' ?>><?= $ore ?> de ore</option>
<?php endforeach; ?>
        </select>
    </label>
    <button class="btn btn-primary" type="submit">Arată</button>
</form>
<?php if ($serie === null): ?>
<?php admin_componenta('stare-goala', ['titlu' => 'Alege sursa și momentul, apoi „Arată”.']); ?>
<?php else: ?>
<?php
$total = ['vizite' => 0, 'comenzi' => 0, 'venit' => 0.0, 'vizite_inainte' => 0, 'comenzi_inainte' => 0, 'venit_inainte' => 0.0];
$max = 1;
foreach ($serie as $r) {
    foreach (array_keys($total) as $k) {
        $total[$k] += $r[$k];
    }
    $max = max($max, (int) $r['vizite'], (int) $r['vizite_inainte']);
}
$lei = static fn (float $v): string => number_format($v, 2, ',', '.');
$despre = $parametri['campanie'] !== '' ? 'campania „' . $parametri['campanie'] . '”' : Statistici::SURSE[$parametri['sursa']];
$exportParam = http_build_query(['tabel' => 'impact', 'sursa' => $parametri['sursa'], 'campanie' => $parametri['campanie'],
    'start' => $parametri['start_text'], 'ore' => $parametri['ore']]);
ob_start();
?>
<p class="st-nota">Din <?= $e($despre) ?>, de la <?= $e(date('d.m.Y H:i', $parametri['start'])) ?>, <?= (int) $parametri['ore'] ?> de ore: <strong><?= (int) $total['vizite'] ?> vizite</strong>, <strong><?= (int) $total['comenzi'] ?> comenzi</strong>, <strong><?= $e($lei($total['venit'])) ?> lei</strong> (săptămâna dinainte: <?= (int) $total['vizite_inainte'] ?> vizite, <?= (int) $total['comenzi_inainte'] ?> comenzi, <?= $e($lei($total['venit_inainte'])) ?> lei). <a href="/admin/statistici/export?<?= $e($exportParam) ?>">CSV</a></p>
<div class="st-cadru"><table class="st-tabel" id="st-impact-tabel">
    <thead><tr><th scope="col">Ora</th><th scope="col">Vizite</th><th scope="col">Comenzi</th><th scope="col">Venituri (lei)</th><th scope="col">Vizite, cu o săptămână înainte</th><th scope="col">Comenzi înainte</th><th scope="col">Venituri înainte</th></tr></thead>
    <tbody>
<?php foreach ($serie as $r): ?>
        <tr>
            <td><?= $e($r['eticheta']) ?></td>
            <td><span class="st-bara" style="width:<?= (int) round(60 * $r['vizite'] / $max) ?>px"></span><?= (int) $r['vizite'] ?></td>
            <td><?= (int) $r['comenzi'] ?></td>
            <td><?= $e($lei((float) $r['venit'])) ?></td>
            <td><span class="st-bara st-bara--inainte" style="width:<?= (int) round(60 * $r['vizite_inainte'] / $max) ?>px"></span><?= (int) $r['vizite_inainte'] ?></td>
            <td><?= (int) $r['comenzi_inainte'] ?></td>
            <td><?= $e($lei((float) $r['venit_inainte'])) ?></td>
        </tr>
<?php endforeach; ?>
    </tbody>
    <tfoot><tr><td>Total</td><td><?= (int) $total['vizite'] ?></td><td><?= (int) $total['comenzi'] ?></td><td><?= $e($lei($total['venit'])) ?></td><td><?= (int) $total['vizite_inainte'] ?></td><td><?= (int) $total['comenzi_inainte'] ?></td><td><?= $e($lei($total['venit_inainte'])) ?></td></tr></tfoot>
</table></div>
<?php
admin_componenta('card', ['titlu' => 'Pe ore', 'continut' => (string) ob_get_clean(), 'id' => 'st-impact']);
?>
<?php endif; ?>
<?php endif; ?>
</section>
</div>
