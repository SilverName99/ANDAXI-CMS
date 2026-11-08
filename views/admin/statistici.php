<?php
/**
 * Rapoarte → Statistici (pluginul `statistici`; App\Http\Controllers\StatisticiController, App\Support\StatisticiRaport): perioada,
 * comparația, „Acum pe site”, cifrele, graficul pe ore sau pe zile, tabelele (sortabile, cu filtrul la clic) și exportul CSV.
 * Doar numere: nicio adresă IP, niciun client. Graficele sunt SVG în pagină (fără bibliotecă, fără CDN).
 *
 * @var string|null $eroare
 * @var array<string, mixed> $perioada
 * @var array<string, string> $filtre
 * @var bool $compara
 * @var array<string, float|int> $kpi
 * @var array<string, float|int>|null $kpiInainte
 * @var array{comenzi: int, venit: float}|null $comenziToate
 * @var list<array<string, mixed>> $serie
 * @var list<array<string, mixed>>|null $serieInainte
 * @var array<string, array<string, mixed>> $tabele
 * @var array<string, mixed> $acum
 * @var string $ultimaAgregare
 * @var bool $respectaDnt
 * @var bool $seCalculeaza
 */
use App\Support\StatisticiRaport;

$e = static fn (mixed $v): string => adm_e($v);
$nr = static fn (float|int $v): string => number_format((float) $v, 0, ',', '.');
$lei = static fn (float $v): string => number_format($v, 2, ',', '.') . ' lei';
$procent = static fn (float $v): string => number_format($v * 100, 1, ',', '.') . ' %';
$durata = static function (float $s): string {
    $s = (int) round($s);
    return $s >= 60 ? intdiv($s, 60) . ' min ' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) . ' s' : $s . ' s';
};
$actiuni = '<a class="btn btn-secondary" href="/admin/statistici/impact">Impactul unei campanii / postări</a> '
    . '<a class="btn btn-secondary" href="/admin/statistici/setari">Setări</a>';
admin_componenta('antet-pagina', [
    'titlu' => 'Statistici',
    'nivel' => 1,
    'subtitlu' => 'Câți oameni intră pe site, de unde vin, ce pagini și produse văd, ce caută și ce comandă.',
    'actiuni' => $actiuni,
    'ajutor' => [
        'Statisticile sunt ale site-ului, fără cookie-uri și fără nimic ținut în browserul vizitatorului: un vizitator e recunoscut doar în aceeași zi, după un cod calculat din adresa lui și browser, care nu se mai poate reface a doua zi. Nu se păstrează adrese IP.',
        'Pe mai multe zile, „Vizitatori unici (pe zile)” e suma vizitatorilor unici ai fiecărei zile: fără cookie-uri, același om venit în două zile se numără de două ori.',
        'Nu se numără administratorii logați, roboții și browserele care cer „nu urmări” (Do Not Track / Global Privacy Control; vezi Setări).',
        'O vizită ține cât vizitatorul nu stă mai mult de 30 de minute fără nicio pagină. Sursa vizitei e a primei pagini: un link cu utm, o reclamă (gclid, fbclid, ttclid), site-ul de pe care a venit sau aplicația (TikTok, Instagram, Facebook); fără nimic, „Direct”.',
        'O comandă se atribuie ultimei vizite din aceeași zi venite de undeva (nu „Direct”). Veniturile sunt totalurile comenzilor de acum, fără cele anulate, eșuate, rambursate sau neplătite încă.',
        'Cuvintele căutate pe Google nu se pot vedea: Google nu le trimite site-urilor. Aici apar doar căutările făcute în căutarea site-ului.',
    ],
]);
?>
<?php if (isset($eroare)): ?>
<?php admin_componenta('mesaj', ['tip' => 'avertisment', 'titlu' => 'Statisticile nu se pot arăta', 'text' => (string) $eroare]); ?>
<?php else: ?>
<style>
.st-filtre { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: flex-end; margin: 0 0 12px; }
.st-filtre label { display: flex; flex-direction: column; gap: 4px; font-size: 13px; color: var(--adm-text-secundar); }
.st-filtre label[hidden] { display: none; }
.st-filtre label.st-bifa { flex-direction: row; align-items: center; gap: 6px; padding-bottom: 8px; color: var(--adm-text); }
.st-filtre select, .st-filtre input[type=date] { min-height: 36px; }
.st-active { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 12px; align-items: center; font-size: 13px; }
.st-chip { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; background: var(--adm-accent-fundal); color: var(--adm-text); text-decoration: none; }
.st-chip span { color: var(--adm-text-secundar); }
.st-kpi { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin: 0 0 12px; }
.st-kpi .adm-card { margin: 0; }
.st-delta { font-size: 12px; color: var(--adm-text-secundar); }
.st-delta--sus { color: var(--adm-ok); }
.st-delta--jos { color: var(--adm-eroare); }
.st-acum { display: grid; grid-template-columns: auto 1fr 1fr; gap: 12px 24px; align-items: start; }
.st-acum__cifra { font-size: 40px; font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; }
.st-acum ul { margin: 0; padding: 0; list-style: none; font-size: 13px; }
.st-acum li { display: flex; justify-content: space-between; gap: 8px; padding: 2px 0; border-bottom: 1px solid var(--adm-linie); }
.st-grafic { position: relative; margin: 8px 0 0; }
.st-grafic svg { display: block; width: 100%; height: 200px; }
.st-grafic__grila { stroke: var(--adm-linie); stroke-width: 1; }
.st-grafic__linie { fill: none; stroke: var(--adm-accent); stroke-width: 2.25; }
.st-grafic__linie--inainte { stroke: var(--adm-text-secundar); stroke-dasharray: 5 4; stroke-width: 1.5; }
.st-grafic__arie { fill: var(--adm-accent); opacity: .08; }
.st-grafic__bara { fill: var(--adm-accent); opacity: .75; }
.st-grafic__bara--inainte { fill: var(--adm-text-secundar); opacity: .35; }
.st-grafic__tinta { fill: transparent; }
.st-grafic__tinta:hover { fill: var(--adm-accent); opacity: .08; }
.st-grafic__max { position: absolute; top: 0; left: 0; font-size: 11px; color: var(--adm-text-secundar); background: var(--adm-fundal); padding: 0 4px; }
.st-grafic__axa { display: flex; justify-content: space-between; font-size: 11px; color: var(--adm-text-secundar); margin-top: 4px; font-variant-numeric: tabular-nums; }
.st-legenda { display: flex; gap: 16px; font-size: 12px; color: var(--adm-text-secundar); margin: 4px 0 0; flex-wrap: wrap; }
.st-legenda i { display: inline-block; width: 16px; height: 0; border-top: 2px solid var(--adm-accent); vertical-align: middle; margin-right: 4px; }
.st-legenda i.st-inainte { border-top: 2px dashed var(--adm-text-secundar); }
.st-tabele { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 520px), 1fr)); gap: 12px; }
.st-tabele > .adm-card { margin: 0; min-width: 0; }
.st-tabele > .st-lat { grid-column: 1 / -1; }
.st-cadru { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.st-tabel { width: 100%; border-collapse: collapse; font-size: 13px; }
.st-tabel th, .st-tabel td { padding: 6px 8px; border-bottom: 1px solid var(--adm-linie); text-align: right; white-space: nowrap; }
.st-tabel th:first-child, .st-tabel td:first-child { text-align: left; white-space: normal; min-width: 140px; word-break: break-word; }
.st-tabel th button { all: unset; cursor: pointer; font-weight: 600; color: var(--adm-text); }
.st-tabel th button:focus-visible { outline: 2px solid var(--adm-focus); outline-offset: 2px; }
.st-tabel th[aria-sort=ascending] button::after { content: " ▲"; font-size: 10px; }
.st-tabel th[aria-sort=descending] button::after { content: " ▼"; font-size: 10px; }
.st-tabel tr.st-copil td:first-child { padding-left: 24px; color: var(--adm-text-secundar); }
.st-tabel tr.st-copil[hidden] { display: none; }
.st-tabel td a { color: var(--adm-accent); text-decoration: none; }
.st-tabel td a:hover { text-decoration: underline; }
.st-extinde { all: unset; cursor: pointer; display: inline-block; width: 18px; text-align: center; color: var(--adm-text-secundar); }
.st-extinde:focus-visible { outline: 2px solid var(--adm-focus); }
.st-card-antet { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; margin: 0 0 6px; }
.st-card-antet h2 { font-size: 15px; margin: 0; }
.st-card-antet a { font-size: 12px; white-space: nowrap; }
.st-nota { font-size: 12px; color: var(--adm-text-secundar); margin: 6px 0 0; }
@media (max-width: 600px) {
    .st-acum { grid-template-columns: 1fr; }
    .st-grafic svg { height: 160px; }
    .st-kpi { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
<?php
$parametriBaza = array_filter(['perioada' => $perioada['cheie'], 'de_la' => $perioada['cheie'] === 'custom' ? $perioada['de_la'] : null,
    'pana_la' => $perioada['cheie'] === 'custom' ? $perioada['pana_la'] : null, 'compara' => $compara ? '1' : null], static fn ($v): bool => $v !== null);
$adresa = static function (array $schimbari) use ($parametriBaza, $filtre): string {
    $p = array_merge($parametriBaza, $filtre, $schimbari);
    $p = array_filter($p, static fn ($v): bool => $v !== null && $v !== '');
    return '/admin/statistici' . ($p === [] ? '' : '?' . http_build_query($p));
};
$adresaExport = static function (string $tabel) use ($parametriBaza, $filtre): string {
    return '/admin/statistici/export?' . http_build_query(array_merge($parametriBaza, $filtre, ['tabel' => $tabel]));
};
?>
<form method="get" action="/admin/statistici" class="st-filtre" id="st-perioada">
    <label>Perioada
        <select name="perioada" id="st-perioada-alegere">
<?php foreach (StatisticiRaport::PERIOADE as $cheie => $eticheta): ?>
            <option value="<?= $e($cheie) ?>"<?= $cheie === $perioada['cheie'] ? ' selected' : '' ?>><?= $e($eticheta) ?></option>
<?php endforeach; ?>
        </select>
    </label>
    <label class="st-custom">De la <input type="date" name="de_la" value="<?= $e($perioada['de_la']) ?>"></label>
    <label class="st-custom">Până la <input type="date" name="pana_la" value="<?= $e($perioada['pana_la']) ?>"></label>
    <label class="st-bifa"><input type="checkbox" name="compara" value="1"<?= $compara ? ' checked' : '' ?>> Compară cu perioada dinainte</label>
<?php foreach ($filtre as $cheie => $valoare): ?>
    <input type="hidden" name="<?= $e($cheie) ?>" value="<?= $e($valoare) ?>">
<?php endforeach; ?>
    <button class="btn btn-primary" type="submit">Arată</button>
</form>
<p class="st-nota" style="margin-top:-6px">Perioada: <strong><?= $e($perioada['eticheta']) ?></strong><?= $compara ? ' · comparată cu ' . $e(date('d.m.Y', (int) strtotime($perioada['inainte_de_la'])) . ' – ' . date('d.m.Y', (int) strtotime($perioada['inainte_pana_la']))) : '' ?><?= $ultimaAgregare !== '' ? ' · date la zi la ' . $e(date('d.m.Y H:i', (int) strtotime($ultimaAgregare))) : '' ?><?= !$respectaDnt ? ' · „Do Not Track” nu se respectă (Setări)' : '' ?></p>
<?php if (!empty($seCalculeaza)): ?>
<?php admin_componenta('mesaj', ['tip' => 'info', 'titlu' => 'Statisticile se calculează', 'text' => 'Se arată ultimele date agregate; reîncarcă pagina peste un minut.']); ?>
<?php endif; ?>
<?php if ($filtre !== []): ?>
<div class="st-active">Filtrat după:
<?php foreach ($filtre as $cheie => $valoare): ?>
    <a class="st-chip" href="<?= $e($adresa([$cheie => null] + ($cheie === 'sursa' ? ['detaliu' => null] : []))) ?>" title="Scoate filtrul"><span><?= $e(['sursa' => 'Sursa', 'detaliu' => 'Detaliu', 'campanie' => 'Campania', 'dispozitiv' => 'Dispozitivul'][$cheie]) ?>:</span> <?= $e($cheie === 'sursa' ? StatisticiRaport::numeSursa($valoare) : $valoare) ?> ✕</a>
<?php endforeach; ?>
    <a href="<?= $e($adresa(array_fill_keys(StatisticiRaport::FILTRE, null))) ?>">Toate</a>
</div>
<?php endif; ?>
<?php
ob_start();
?>
<div class="st-acum" id="st-acum" data-url="/admin/statistici/acum">
    <div><div class="st-acum__cifra" data-st-acum="vizitatori"><?= $nr((int) $acum['vizitatori']) ?></div><div class="st-nota">vizitatori în ultimele 5 minute · <span data-st-acum="la"><?= $e($acum['la']) ?></span></div></div>
    <div><strong style="font-size:13px">Pagini</strong><ul data-st-acum="pagini">
<?php foreach ($acum['pagini'] as $p): ?>
        <li><span><?= $e($p['cale']) ?></span><span><?= (int) $p['vizitatori'] ?></span></li>
<?php endforeach; ?>
    </ul></div>
    <div><strong style="font-size:13px">Surse</strong><ul data-st-acum="surse">
<?php foreach ($acum['surse'] as $s): ?>
        <li><span><?= $e(StatisticiRaport::numeSursa($s['sursa'])) ?></span><span><?= (int) $s['vizitatori'] ?></span></li>
<?php endforeach; ?>
    </ul></div>
</div>
<?php
admin_componenta('card', ['titlu' => 'Acum pe site', 'continut' => (string) ob_get_clean(), 'id' => 'st-card-acum', 'ton' => 'info']);

$tile = static function (string $titlu, string $valoare, ?float $acumV, ?float $inainteV, bool $maiMicEBine = false) use ($procent): string {
    $detaliu = '';
    if ($inainteV !== null && $acumV !== null) {
        if ($inainteV == 0.0) {
            $detaliu = $acumV > 0 ? 'nou față de perioada dinainte' : 'la fel ca perioada dinainte';
            $clasa = '';
        } else {
            $dif = ($acumV - $inainteV) / abs($inainteV);
            $clasa = abs($dif) < 0.005 ? '' : (($dif > 0) !== $maiMicEBine ? ' st-delta--sus' : ' st-delta--jos');
            $detaliu = ($dif >= 0 ? '+' : '−') . number_format(abs($dif) * 100, 1, ',', '.') . ' % față de perioada dinainte';
        }
        ob_start();
        admin_componenta('card', ['titlu' => $titlu, 'valoare' => $valoare]);
        $html = (string) ob_get_clean();
        return str_replace('</section>', '<span class="st-delta' . $clasa . '">' . adm_e($detaliu) . '</span></section>', $html);
    }
    ob_start();
    admin_componenta('card', ['titlu' => $titlu, 'valoare' => $valoare]);
    return (string) ob_get_clean();
};
$k = $kpi;
$ki = $kpiInainte;
$v = static fn (string $cheie) => $ki !== null ? (float) $ki[$cheie] : null;
?>
<div class="st-kpi" id="st-kpi">
<?= $tile($perioada['zile'] > 1 ? 'Vizitatori unici (pe zile)' : 'Vizitatori unici', $nr($k['vizitatori']), (float) $k['vizitatori'], $v('vizitatori')) ?>
<?= $tile('Vizite', $nr($k['vizite']), (float) $k['vizite'], $v('vizite')) ?>
<?= $tile('Pagini vizualizate', $nr($k['pagini']), (float) $k['pagini'], $v('pagini')) ?>
<?= $tile('Pagini / vizită', number_format((float) $k['pagini_vizita'], 1, ',', '.'), (float) $k['pagini_vizita'], $v('pagini_vizita')) ?>
<?= $tile('Durata medie', $durata((float) $k['durata']), (float) $k['durata'], $v('durata')) ?>
<?= $tile('Rata de respingere', $procent((float) $k['respingere']), (float) $k['respingere'], $v('respingere'), true) ?>
<?= $tile('Adăugări în coș', $nr($k['adaugari']), (float) $k['adaugari'], $v('adaugari')) ?>
<?= $tile('Comenzi', $nr($k['comenzi']), (float) $k['comenzi'], $v('comenzi')) ?>
<?= $tile('Rata de conversie', $procent((float) $k['conversie']), (float) $k['conversie'], $v('conversie')) ?>
<?= $tile('Venituri', $lei((float) $k['venit']), (float) $k['venit'], $v('venit')) ?>
</div>
<?php if (($comenziToate ?? null) !== null && $comenziToate['comenzi'] > $k['comenzi']): ?>
<p class="st-nota" style="margin-top:-4px">Comenzile și veniturile de mai sus sunt cele legate de o vizită numărată: <?= $nr($k['comenzi']) ?> din <?= $nr($comenziToate['comenzi']) ?> comenzi ale perioadei (<?= $e($lei((float) $k['venit'])) ?> din <?= $e($lei((float) $comenziToate['venit'])) ?>). Restul sunt plasate de administratori, din browsere care cer „nu urmări”, dintr-o vizită din altă zi sau dinainte de pornirea statisticilor.</p>
<?php endif; ?>
<?php
/**
 * Un grafic: linie (cu linia perioadei dinainte, punctată) sau bare. Doar formele sunt în SVG (se întind pe lățime); eticheta
 * maximului și axa timpului sunt HTML, deci se citesc și pe telefon.
 */
$grafic = static function (string $id, string $titlu, array $puncte, string $cheie, ?array $inainte, bool $bare, callable $format): string {
    $n = count($puncte);
    $max = 0.0;
    foreach ($puncte as $p) {
        $max = max($max, (float) $p[$cheie]);
    }
    foreach ((array) $inainte as $p) {
        $max = max($max, (float) $p[$cheie]);
    }
    $varf = $max > 0 ? $max * 1.1 : 1.0;
    $L = 1000;
    $H = 200;
    $x = static fn (int $i): float => $n <= 1 ? $L / 2 : $i * $L / ($n - 1);
    $y = static fn (float $val): float => round($H - ($val / $varf) * ($H - 6), 1);
    $o = '<div class="st-grafic"><svg id="' . adm_e($id) . '" viewBox="0 0 ' . $L . ' ' . $H . '" preserveAspectRatio="none" role="img" aria-label="'
        . adm_e($titlu) . '">';
    foreach ([0.25, 0.5, 0.75] as $f) {
        $o .= '<line class="st-grafic__grila" x1="0" x2="' . $L . '" y1="' . round($H * $f, 1) . '" y2="' . round($H * $f, 1) . '" vector-effect="non-scaling-stroke"/>';
    }
    $pas = $L / max(1, $n);
    if ($bare) {
        foreach ($puncte as $i => $p) {
            $val = (float) $p[$cheie];
            $h = $H - $y($val);
            $latime = max(1.0, $pas * ($inainte !== null ? 0.4 : 0.7));
            $x0 = $i * $pas + $pas * 0.15;
            if ($inainte !== null) {
                $vi = (float) ($inainte[$i][$cheie] ?? 0);
                $o .= '<rect class="st-grafic__bara--inainte" x="' . round($x0, 1) . '" y="' . $y($vi) . '" width="' . round($latime, 1) . '" height="' . round($H - $y($vi), 1) . '"/>';
                $x0 += $latime;
            }
            $o .= '<rect class="st-grafic__bara" x="' . round($x0, 1) . '" y="' . $y($val) . '" width="' . round($latime, 1) . '" height="' . round($h, 1) . '"/>';
        }
    } else {
        $linie = static function (array $serie) use ($cheie, $x, $y): string {
            $d = '';
            foreach (array_values($serie) as $i => $p) {
                $d .= ($i === 0 ? 'M' : 'L') . round($x($i), 1) . ' ' . $y((float) $p[$cheie]);
            }
            return $d;
        };
        if ($inainte !== null) {
            $o .= '<path class="st-grafic__linie st-grafic__linie--inainte" d="' . $linie($inainte) . '" vector-effect="non-scaling-stroke"/>';
        }
        $d = $linie($puncte);
        if ($d !== '') {
            $o .= '<path class="st-grafic__arie" d="' . $d . 'L' . round($x($n - 1), 1) . ' ' . $H . 'L0 ' . $H . 'Z"/>';
            $o .= '<path class="st-grafic__linie" d="' . $d . '" vector-effect="non-scaling-stroke"/>';
        }
    }
    foreach ($puncte as $i => $p) {
        $text = $p['cheie'] . ': ' . $format((float) $p[$cheie]) . ($inainte !== null && isset($inainte[$i]) ? ' (înainte: ' . $format((float) $inainte[$i][$cheie]) . ')' : '');
        $o .= '<rect class="st-grafic__tinta" x="' . round($i * $pas, 1) . '" y="0" width="' . round($pas, 1) . '" height="' . $H . '"><title>' . adm_e($text) . '</title></rect>';
    }
    $o .= '</svg><span class="st-grafic__max">' . adm_e($format($max)) . '</span>';
    $axa = [];
    $pasEticheta = max(1, (int) ceil($n / 8));
    foreach ($puncte as $i => $p) {
        if ($i % $pasEticheta === 0 || $i === $n - 1) {
            $axa[] = '<span>' . adm_e($p['eticheta']) . '</span>';
        }
    }
    return $o . '<div class="st-grafic__axa">' . implode('', $axa) . '</div></div>';
};
$fmtNr = static fn (float $v): string => number_format($v, 0, ',', '.');
$legenda = '<div class="st-legenda"><span><i></i>' . ($perioada['pe_ora'] ? 'pe ore' : 'pe zile') . ', perioada aleasă</span>'
    . ($serieInainte !== null ? '<span><i class="st-inainte"></i>perioada dinainte</span>' : '') . '</div>';
ob_start();
echo $grafic('st-grafic-vizite', 'Vizitele ' . ($perioada['pe_ora'] ? 'pe ore' : 'pe zile'), $serie, 'vizite', $serieInainte, false, $fmtNr);
echo $legenda;
echo '<h3 style="font-size:14px;margin:16px 0 0">Venituri</h3>';
echo $grafic('st-grafic-venit', 'Veniturile ' . ($perioada['pe_ora'] ? 'pe ore' : 'pe zile'), $serie, 'venit', $serieInainte, true, static fn (float $v): string => number_format($v, 0, ',', '.') . ' lei');
echo '<p class="st-nota"><a href="' . $e($adresaExport('serie')) . '">Descarcă seria (CSV)</a></p>';
admin_componenta('card', ['titlu' => 'Vizite și venituri ' . ($perioada['pe_ora'] ? 'pe ore' : 'pe zile'), 'continut' => (string) ob_get_clean(), 'id' => 'st-card-grafic']);

$celula = static function (array $coloana, mixed $valoare) use ($nr, $lei, $procent, $durata): array {
    if ($valoare === '' || $valoare === null) {
        return ['—', ''];
    }
    return match ($coloana['tip']) {
        'numar' => [$nr((int) $valoare), (string) (int) $valoare],
        'lei' => [$lei((float) $valoare), (string) (float) $valoare],
        'procent' => [$procent((float) $valoare), (string) (float) $valoare],
        'zecimal' => [number_format((float) $valoare, 1, ',', '.'), (string) (float) $valoare],
        'durata' => [$durata((float) $valoare), (string) (float) $valoare],
        default => [adm_e((string) $valoare), (string) $valoare],
    };
};
?>
<div class="st-tabele" id="st-tabele">
<?php foreach ($tabele as $cheieTabel => $tabel): ?>
<?php
if ($cheieTabel === 'tari' && $tabel['randuri'] === []) {
    continue; // Fără antet de țară de la găzduire, nicio țară: tabelul nu apare.
}
?>
    <section class="adm-card<?= in_array($cheieTabel, ['surse', 'campanii'], true) ? ' st-lat' : '' ?>" id="st-<?= $e($cheieTabel) ?>">
        <div class="st-card-antet"><h2><?= $e($tabel['titlu']) ?></h2><?php if ($tabel['randuri'] !== []): ?><a href="<?= $e($adresaExport($cheieTabel)) ?>">CSV</a><?php endif; ?></div>
<?php if ($tabel['randuri'] === []): ?>
        <p class="st-nota">Nimic în perioada aleasă<?= $cheieTabel === 'cautari' || $cheieTabel === 'fara_rezultat' ? ' (căutările din căutarea de produse a site-ului)' : '' ?>.</p>
<?php else: ?>
        <div class="st-cadru"><table class="st-tabel" data-st-sortabil>
            <thead><tr>
<?php foreach ($tabel['coloane'] as $coloana): ?>
                <th scope="col" data-tip="<?= $e($coloana['tip']) ?>"><button type="button"><?= $e($coloana['eticheta']) ?></button></th>
<?php endforeach; ?>
            </tr></thead>
            <tbody>
<?php foreach ($tabel['randuri'] as $indice => $rand): ?>
<?php $copii = (array) ($rand['copii'] ?? []); ?>
                <tr data-grup="<?= (int) $indice ?>">
<?php foreach ($tabel['coloane'] as $i => $coloana): ?>
<?php [$afisat, $sortare] = $celula($coloana, $rand['valori'][$coloana['cheie']] ?? ''); ?>
<?php if ($i === 0): ?>
<?php
$text = $afisat;
if (($rand['filtru'] ?? []) !== []) {
    $text = '<a href="' . $e($adresa($rand['filtru'])) . '" title="Arată tot tabloul doar pentru „' . $e((string) $rand['valori']['eticheta']) . '”">' . $afisat . '</a>';
} elseif (($rand['link'] ?? '') !== '') {
    $text = '<a href="' . $e($rand['link']) . '" target="_blank" rel="noopener">' . $afisat . '</a>';
}
?>
                    <td data-v="<?= $e($sortare) ?>"><?php if ($copii !== []): ?><button type="button" class="st-extinde" aria-expanded="false" aria-label="Arată detaliile">▸</button><?php endif; ?><?= $text ?></td>
<?php else: ?>
                    <td data-v="<?= $e($sortare) ?>"><?= $afisat ?></td>
<?php endif; ?>
<?php endforeach; ?>
                </tr>
<?php foreach ($copii as $copil): ?>
                <tr class="st-copil" data-parinte="<?= (int) $indice ?>" hidden>
<?php foreach ($tabel['coloane'] as $i => $coloana): ?>
<?php [$afisat, $sortare] = $celula($coloana, $copil['valori'][$coloana['cheie']] ?? ''); ?>
                    <td data-v="<?= $e($sortare) ?>"><?= $i === 0 && ($copil['filtru'] ?? []) !== [] ? '<a href="' . $e($adresa($copil['filtru'])) . '">' . $afisat . '</a>' : $afisat ?></td>
<?php endforeach; ?>
                </tr>
<?php endforeach; ?>
<?php endforeach; ?>
            </tbody>
        </table></div>
<?php endif; ?>
<?php if (($tabel['nota'] ?? '') !== ''): ?>
        <p class="st-nota"><?= $e($tabel['nota']) ?></p>
<?php endif; ?>
<?php if ($cheieTabel === 'cautari'): ?>
        <p class="st-nota">Doar căutările a cel puțin <?= \App\Support\StatisticiRaport::MIN_VIZITATORI_CAUTARE ?> vizitatori; cele cu „@” sau cu 6 cifre la rând (adrese, telefoane, numere) nu se păstrează deloc.</p>
        <p class="st-nota">Cuvintele căutate pe Google nu se pot vedea aici: Google nu le mai trimite site-urilor. (Se pot vedea în Google Search Console; legătura cu el poate veni mai târziu.)</p>
<?php endif; ?>
    </section>
<?php endforeach; ?>
</div>
<script>
(function () {
    // Perioada „Altă perioadă”: datele apar doar la ea.
    var alegere = document.getElementById('st-perioada-alegere');
    var custom = document.querySelectorAll('#st-perioada .st-custom');
    function arata() { custom.forEach(function (el) { el.hidden = alegere.value !== 'custom'; }); }
    if (alegere) { alegere.addEventListener('change', arata); arata(); }

    // Sortarea tabelelor la clic pe antet (rândurile de detaliu rămân sub rândul lor).
    document.querySelectorAll('table[data-st-sortabil]').forEach(function (tabel) {
        var antete = tabel.querySelectorAll('thead th');
        antete.forEach(function (th, col) {
            th.querySelector('button').addEventListener('click', function () {
                var dir = th.getAttribute('aria-sort') === 'descending' ? 'ascending' : 'descending';
                antete.forEach(function (a) { a.removeAttribute('aria-sort'); });
                th.setAttribute('aria-sort', dir);
                var text = th.getAttribute('data-tip') === 'text';
                var corp = tabel.tBodies[0];
                var grupuri = Array.prototype.filter.call(corp.rows, function (r) { return !r.classList.contains('st-copil'); });
                grupuri.sort(function (a, b) {
                    var x = a.cells[col].getAttribute('data-v'), y = b.cells[col].getAttribute('data-v');
                    var r = text ? x.localeCompare(y, 'ro') : (parseFloat(x || '0') - parseFloat(y || '0'));
                    return dir === 'ascending' ? r : -r;
                });
                grupuri.forEach(function (r) {
                    corp.appendChild(r);
                    corp.querySelectorAll('tr.st-copil[data-parinte="' + r.getAttribute('data-grup') + '"]').forEach(function (c) { corp.appendChild(c); });
                });
            });
        });
        tabel.querySelectorAll('.st-extinde').forEach(function (b) {
            b.addEventListener('click', function () {
                var rand = b.closest('tr'), deschis = b.getAttribute('aria-expanded') === 'true';
                b.setAttribute('aria-expanded', deschis ? 'false' : 'true');
                b.textContent = deschis ? '▸' : '▾';
                tabel.querySelectorAll('tr.st-copil[data-parinte="' + rand.getAttribute('data-grup') + '"]').forEach(function (c) { c.hidden = deschis; });
            });
        });
    });

    // „Acum pe site”, la 30 de secunde, cât pagina e deschisă și vizibilă.
    var acum = document.getElementById('st-acum');
    if (!acum || !window.fetch) { return; }
    function lista(ul, randuri, eticheta) {
        ul.textContent = '';
        randuri.forEach(function (r) {
            var li = document.createElement('li'), a = document.createElement('span'), b = document.createElement('span');
            a.textContent = eticheta(r); b.textContent = String(r.vizitatori);
            li.appendChild(a); li.appendChild(b); ul.appendChild(li);
        });
    }
    function reimprospateaza() {
        if (document.visibilityState !== 'visible') { return; }
        fetch(acum.getAttribute('data-url'), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || !d.ok) { return; }
                acum.querySelector('[data-st-acum=vizitatori]').textContent = Number(d.vizitatori).toLocaleString('ro-RO');
                acum.querySelector('[data-st-acum=la]').textContent = d.la;
                lista(acum.querySelector('[data-st-acum=pagini]'), d.pagini, function (r) { return r.cale; });
                lista(acum.querySelector('[data-st-acum=surse]'), d.surse, function (r) { return r.nume; });
            })
            .catch(function () {});
    }
    setInterval(reimprospateaza, 30000);
})();
</script>
<?php endif; ?>
