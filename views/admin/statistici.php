<?php
/**
 * Rapoarte → Statistici (pluginul `statistici`; App\Http\Controllers\StatisticiController, App\Support\StatisticiRaport): tab-urile
 * Prezentare, Surse, Pagini și produse, Căutări, Vânzări, Dispozitive (Impact postare și Setări au paginile lor, în același cap:
 * statistici-cap.php). Cifrele cu culoarea și iconița lor, „Acum pe site”, graficul pe ore sau pe zile (SVG în pagină, cu
 * explicația la trecerea cu mouse-ul), tabelele cu bara cotei, sortabile, cu filtrul la clic și CSV. Doar numere: nicio adresă IP,
 * niciun client. Fără bibliotecă și fără CDN.
 *
 * @var string $tab
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
use App\Http\Controllers\StatisticiController;
use App\Support\StatisticiRaport;

$e = static fn (mixed $v): string => adm_e($v);
$nr = static fn (float|int $v): string => number_format((float) $v, 0, ',', '.');
$lei = static fn (float $v): string => number_format($v, 2, ',', '.') . ' lei';
$procent = static fn (float $v): string => number_format($v * 100, 1, ',', '.') . ' %';
$durata = static function (float $s): string {
    $s = (int) round($s);
    return $s >= 60 ? intdiv($s, 60) . ' min ' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) . ' s' : $s . ' s';
};
$tab = (string) ($tab ?? 'prezentare');
?>
<div class="st-pagina">
<?php require __DIR__ . '/statistici-cap.php'; ?>
<?php if (isset($eroare)): ?>
<?php admin_componenta('mesaj', ['tip' => 'avertisment', 'titlu' => 'Statisticile nu se pot arăta', 'text' => (string) $eroare]); ?>
<?php else: ?>
<style>
.st-kpi { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; }
.st-tile { --st-c: #2563eb; position: relative; display: flex; flex-direction: column; gap: 6px; padding: 16px 16px 14px; border-radius: var(--adm-raza);
    border: 1px solid color-mix(in srgb, var(--st-c) 22%, var(--adm-linie)); background: color-mix(in srgb, var(--st-c) 7%, var(--adm-fundal)); min-width: 0; }
.st-tile__cap { display: flex; align-items: center; gap: 8px; color: var(--adm-text-secundar); font-size: 13px; font-weight: 600; }
.st-tile__icon { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 9px; background: var(--st-c); color: #fff; flex: 0 0 auto; }
.st-tile__valoare { font-size: 28px; font-weight: 700; line-height: 1.1; font-variant-numeric: tabular-nums; color: var(--adm-text); overflow-wrap: anywhere; }
.st-delta { align-self: flex-start; display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 600;
    background: color-mix(in srgb, var(--adm-text-secundar) 12%, transparent); color: var(--adm-text-secundar); }
.st-delta--bine { background: color-mix(in srgb, #16a34a 14%, transparent); color: #15803d; }
.st-delta--rau { background: color-mix(in srgb, #dc2626 14%, transparent); color: #b91c1c; }
.st-acum { display: grid; grid-template-columns: minmax(160px, auto) 1fr; gap: 14px 28px; align-items: start; }
.st-acum__cifra { display: flex; align-items: center; gap: 12px; }
.st-acum__numar { font-size: 44px; font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; }
.st-puls { position: relative; width: 12px; height: 12px; border-radius: 50%; background: #16a34a; flex: 0 0 auto; }
.st-puls::after { content: ""; position: absolute; inset: -6px; border-radius: 50%; border: 2px solid #16a34a; opacity: 0; animation: st-puls 2s ease-out infinite; }
@keyframes st-puls { 0% { transform: scale(.5); opacity: .8; } 100% { transform: scale(1.4); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .st-puls::after { animation: none; } }
.st-chipuri { display: flex; flex-wrap: wrap; gap: 6px; margin: 4px 0 10px; }
.st-chipuri .st-chip { background: var(--adm-fundal-pagina); border: 1px solid var(--adm-linie); }
.st-chipuri strong { font-variant-numeric: tabular-nums; }
.st-grafic { position: relative; margin: 6px 0 0; touch-action: pan-y; }
.st-grafic svg { display: block; width: 100%; height: 220px; }
.st-grafic__grila { stroke: var(--adm-linie); stroke-width: 1; }
.st-grafic__linie { fill: none; stroke: var(--adm-accent); stroke-width: 2.5; }
.st-grafic__linie--inainte { stroke: var(--adm-text-secundar); stroke-dasharray: 5 4; stroke-width: 1.5; }
.st-grafic__bara { fill: #15803d; opacity: .8; }
.st-grafic__bara--inainte { fill: var(--adm-text-secundar); opacity: .3; }
.st-grafic__ghid { stroke: var(--adm-text-secundar); stroke-width: 1; stroke-dasharray: 3 3; }
.st-grafic__max { position: absolute; top: 0; left: 0; font-size: 11px; color: var(--adm-text-secundar); background: var(--adm-fundal); padding: 0 4px; }
.st-grafic__axa { display: flex; justify-content: space-between; font-size: 11px; color: var(--adm-text-secundar); margin-top: 6px; font-variant-numeric: tabular-nums; }
.st-grafic__tip { position: absolute; top: 8px; z-index: 5; pointer-events: none; padding: 6px 10px; border-radius: 8px; background: var(--adm-text); color: var(--adm-fundal);
    font-size: 12px; line-height: 1.4; white-space: nowrap; box-shadow: 0 6px 18px rgb(0 0 0 / .18); transform: translateX(-50%); }
.st-grafic__tip[hidden] { display: none; }
.st-legenda { display: flex; gap: 16px; font-size: 12px; color: var(--adm-text-secundar); margin: 8px 0 0; flex-wrap: wrap; }
.st-legenda i { display: inline-block; width: 16px; height: 0; border-top: 2px solid var(--adm-accent); vertical-align: middle; margin-right: 4px; }
.st-legenda i.st-inainte { border-top: 2px dashed var(--adm-text-secundar); }
.st-cadru { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.st-tabel { width: 100%; border-collapse: collapse; font-size: 13px; }
.st-tabel th, .st-tabel td { padding: 8px 10px; border-bottom: 1px solid var(--adm-linie); text-align: right; white-space: nowrap; }
.st-tabel thead th { font-size: 12px; text-transform: none; color: var(--adm-text-secundar); border-bottom-width: 2px; }
.st-tabel th:first-child, .st-tabel td:first-child { text-align: left; white-space: normal; min-width: 150px; word-break: break-word; }
.st-tabel tbody tr:hover td { background: color-mix(in srgb, var(--adm-accent) 4%, transparent); }
.st-tabel th button { all: unset; cursor: pointer; font-weight: 600; color: var(--adm-text-secundar); }
.st-tabel th button:focus-visible { outline: 2px solid var(--adm-focus); outline-offset: 2px; }
.st-tabel th[aria-sort=ascending] button::after { content: " ▲"; font-size: 10px; }
.st-tabel th[aria-sort=descending] button::after { content: " ▼"; font-size: 10px; }
.st-tabel tr.st-copil td:first-child { padding-left: 34px; color: var(--adm-text-secundar); }
.st-tabel tr.st-copil[hidden] { display: none; }
.st-tabel td a { color: var(--adm-text); text-decoration: none; }
.st-tabel td a:hover { color: var(--adm-accent); text-decoration: underline; }
.st-eticheta { display: inline-flex; align-items: center; gap: 8px; }
.st-tabel td.st-cota { background-image: linear-gradient(to right, color-mix(in srgb, var(--st-c, var(--adm-accent)) 16%, transparent) calc(var(--st-p, 0) * 1%), transparent 0);
    background-repeat: no-repeat; background-size: 100% 76%; background-position: center; }
.st-tabel tbody tr:hover td.st-cota { background-color: color-mix(in srgb, var(--adm-accent) 4%, transparent); }
.st-extinde { all: unset; cursor: pointer; display: inline-block; width: 18px; text-align: center; color: var(--adm-text-secundar); }
.st-extinde:focus-visible { outline: 2px solid var(--adm-focus); }
@media (max-width: 600px) {
    .st-kpi { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .st-tile { padding: 12px; }
    .st-tile__valoare { font-size: 22px; }
    .st-acum { grid-template-columns: 1fr; }
    .st-grafic svg { height: 170px; }
}
</style>
<?php if (!empty($seCalculeaza)): ?>
<?php admin_componenta('mesaj', ['tip' => 'info', 'titlu' => 'Statisticile se calculează', 'text' => 'Se arată ultimele date agregate; reîncarcă pagina peste un minut.']); ?>
<?php endif; ?>
<?php
$fara = (int) $kpi['vizite'] === 0 && (int) $kpi['comenzi'] === 0;
$golHtml = static function (string $text) use ($respectaDnt): string {
    return '<div class="st-gol" role="status"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" '
        . 'stroke-linejoin="round" aria-hidden="true"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg><div><p>' . adm_e($text) . '</p>'
        . '<small>Administratorii logați nu se numără: ca să te vezi în „Acum pe site”, deschide site-ul într-o fereastră privată.'
        . ($respectaDnt ? ' Nici browserele care cer „nu urmări” (Do Not Track).' : '') . '</small></div></div>';
};
$mesajGol = 'Încă nu sunt vizite înregistrate în perioada asta. Datele apar în câteva minute după primele vizite.';

// „Acum pe site” (Prezentare).
if ($tab === 'prezentare'):
    ob_start();
?>
<div class="st-acum" id="st-acum" data-url="/admin/statistici/acum">
    <div>
        <div class="st-acum__cifra"><span class="st-puls" aria-hidden="true"></span><span class="st-acum__numar" data-st-acum="vizitatori"><?= $nr((int) $acum['vizitatori']) ?></span></div>
        <p class="st-nota">vizitatori în ultimele 5 minute · la <span data-st-acum="la"><?= $e($acum['la']) ?></span></p>
    </div>
    <div>
        <strong style="font-size:13px">Pagini acum</strong>
        <div class="st-chipuri" data-st-acum="pagini">
<?php foreach ($acum['pagini'] as $p): ?>
            <span class="st-chip"><?= $e($p['cale']) ?> <strong><?= (int) $p['vizitatori'] ?></strong></span>
<?php endforeach; ?>
<?php if ($acum['pagini'] === []): ?>
            <span class="st-nota">Nimeni pe site acum (administratorii logați nu se numără).</span>
<?php endif; ?>
        </div>
        <strong style="font-size:13px">Surse acum</strong>
        <div class="st-chipuri" data-st-acum="surse">
<?php foreach ($acum['surse'] as $s): ?>
            <span class="st-chip"><?= $stInsigna((string) $s['sursa']) ?><?= $e(StatisticiRaport::numeSursa((string) $s['sursa'])) ?> <strong><?= (int) $s['vizitatori'] ?></strong></span>
<?php endforeach; ?>
        </div>
    </div>
</div>
<?php
    echo '<section class="st-sectiune" id="st-card-acum"><div class="st-sectiune__antet"><h2>Acum pe site</h2><span class="st-nota">se reîmprospătează la 30 de secunde</span></div>'
        . ob_get_clean() . '</section>';
endif;

// Cifrele: culoarea, iconița, numărul mare, eticheta și diferența față de perioada dinainte.
$icon = static fn (string $d): string => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" '
    . 'stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
$iconite = [
    'vizitatori' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18.5 14.8c1.7.8 2.7 2.5 3 5.2"/>',
    'vizite' => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v4h4"/><path d="M12 7v5l3 2"/>',
    'pagini' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/>',
    'pagini_vizita' => '<rect x="4" y="4" width="10" height="13" rx="2"/><path d="M10 20h8a2 2 0 0 0 2-2V8"/>',
    'durata' => '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2"/><path d="M9.5 2h5"/>',
    'respingere' => '<path d="M9 14l-5-5 5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
    'adaugari' => '<circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2 3h3l2.6 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 1.9-1.4L21 8H6.2"/>',
    'comenzi' => '<path d="M6 7h12l-1 13H7z"/><path d="M9 7a3 3 0 0 1 6 0"/><path d="M9.5 13.5l2 2 3.5-4"/>',
    'conversie' => '<path d="M19 5L5 19"/><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/>',
    'venit' => '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.8"/><path d="M6 9.5v5M18 9.5v5"/>',
];
$culori = ['vizitatori' => '#2563eb', 'vizite' => '#0d9488', 'pagini' => '#7c3aed', 'pagini_vizita' => '#7c3aed', 'durata' => '#0891b2',
    'respingere' => '#64748b', 'adaugari' => '#ea580c', 'comenzi' => '#16a34a', 'conversie' => '#16a34a', 'venit' => '#166534'];
$tile = static function (string $cheie, string $titlu, string $valoare, bool $maiMicEBine = false) use ($kpi, $kpiInainte, $icon, $iconite, $culori): string {
    $acumV = (float) $kpi[$cheie];
    $pastila = '';
    if ($kpiInainte !== null) {
        $inainteV = (float) $kpiInainte[$cheie];
        if ($inainteV == 0.0) {
            $pastila = '<span class="st-delta">' . ($acumV > 0 ? 'nou' : 'la fel') . '</span>';
        } else {
            $dif = ($acumV - $inainteV) / abs($inainteV);
            $clasa = abs($dif) < 0.005 ? '' : ((($dif > 0) !== $maiMicEBine) ? ' st-delta--bine' : ' st-delta--rau');
            $pastila = '<span class="st-delta' . $clasa . '" title="față de perioada dinainte">' . ($dif >= 0 ? '▲ ' : '▼ ')
                . adm_e(number_format(abs($dif) * 100, 1, ',', '.')) . ' %</span>';
        }
    }
    return '<div class="st-tile" style="--st-c:' . $culori[$cheie] . '"><div class="st-tile__cap"><span class="st-tile__icon">' . $icon($iconite[$cheie])
        . '</span>' . adm_e($titlu) . '</div><div class="st-tile__valoare">' . adm_e($valoare) . '</div>' . $pastila . '</div>';
};
$k = $kpi;
$etichetaVizitatori = $perioada['zile'] > 1 ? 'Vizitatori unici (pe zile)' : 'Vizitatori unici';
$tileuri = [
    'vizitatori' => $tile('vizitatori', $etichetaVizitatori, $nr($k['vizitatori'])),
    'vizite' => $tile('vizite', 'Vizite', $nr($k['vizite'])),
    'pagini' => $tile('pagini', 'Pagini vizualizate', $nr($k['pagini'])),
    'pagini_vizita' => $tile('pagini_vizita', 'Pagini / vizită', number_format((float) $k['pagini_vizita'], 1, ',', '.')),
    'durata' => $tile('durata', 'Durata medie', $durata((float) $k['durata'])),
    'respingere' => $tile('respingere', 'Rata de respingere', $procent((float) $k['respingere']), true),
    'adaugari' => $tile('adaugari', 'Adăugări în coș', $nr($k['adaugari'])),
    'comenzi' => $tile('comenzi', 'Comenzi', $nr($k['comenzi'])),
    'conversie' => $tile('conversie', 'Rata de conversie', $procent((float) $k['conversie'])),
    'venit' => $tile('venit', 'Venituri', $lei((float) $k['venit'])),
];
$alese = match ($tab) {
    'prezentare' => array_keys($tileuri),
    'surse' => ['vizitatori', 'vizite', 'pagini_vizita', 'respingere'],
    'pagini' => ['pagini', 'pagini_vizita', 'durata', 'adaugari'],
    'vanzari' => ['adaugari', 'comenzi', 'conversie', 'venit'],
    'dispozitive' => ['vizitatori', 'vizite', 'respingere', 'conversie'],
    default => [],
};
if ($alese !== [] && !$fara) {
    echo '<div class="st-kpi" id="st-kpi">' . implode('', array_map(static fn (string $c): string => $tileuri[$c], $alese)) . '</div>';
    if (in_array($tab, ['prezentare', 'vanzari'], true) && ($comenziToate ?? null) !== null && $comenziToate['comenzi'] > $k['comenzi']) {
        echo '<p class="st-nota" style="margin-top:-8px">Comenzile și veniturile de mai sus sunt cele legate de o vizită numărată: ' . $nr($k['comenzi']) . ' din '
            . $nr($comenziToate['comenzi']) . ' comenzi ale perioadei (' . $e($lei((float) $k['venit'])) . ' din ' . $e($lei((float) $comenziToate['venit']))
            . '). Restul sunt plasate de administratori, din browsere care cer „nu urmări”, dintr-o vizită din altă zi sau dinainte de pornirea statisticilor.</p>';
    }
} elseif ($alese !== [] && $fara) {
    echo $golHtml($mesajGol);
}

// Graficul: linie cu arie (vizitele) sau bare (veniturile), explicația la trecerea cu mouse-ul (scriptul de la final).
$grafic = static function (string $id, string $titlu, array $puncte, string $cheie, ?array $inainte, bool $bare, string $format) use ($nr): string {
    $n = count($puncte);
    $max = 0.0;
    foreach ($puncte as $p) {
        $max = max($max, (float) $p[$cheie]);
    }
    foreach ((array) $inainte as $p) {
        $max = max($max, (float) $p[$cheie]);
    }
    $fmt = static fn (float $v): string => $format === 'lei' ? number_format($v, 0, ',', '.') . ' lei' : $nr($v);
    $varf = $max > 0 ? $max * 1.12 : 1.0;
    $L = 1000;
    $H = 220;
    $x = static fn (int $i): float => $n <= 1 ? $L / 2 : $i * $L / ($n - 1);
    $y = static fn (float $val): float => round($H - ($val / $varf) * ($H - 8), 1);
    $date = [];
    foreach ($puncte as $i => $p) {
        $date[] = ['e' => (string) $p['cheie'], 'v' => $fmt((float) $p[$cheie]), 'i' => $inainte !== null && isset($inainte[$i]) ? $fmt((float) $inainte[$i][$cheie]) : null];
    }
    $o = '<div class="st-grafic" data-st-grafic data-bare="' . ($bare ? '1' : '0') . '" data-puncte="' . adm_e((string) json_encode($date, JSON_UNESCAPED_UNICODE)) . '">'
        . '<svg id="' . adm_e($id) . '" viewBox="0 0 ' . $L . ' ' . $H . '" preserveAspectRatio="none" role="img" aria-label="' . adm_e($titlu) . '">'
        . ($bare ? '' : '<defs><linearGradient id="' . adm_e($id) . '-degrade" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="var(--adm-accent)" stop-opacity=".28"/>'
            . '<stop offset="1" stop-color="var(--adm-accent)" stop-opacity="0"/></linearGradient></defs>');
    foreach ([0.25, 0.5, 0.75] as $f) {
        $o .= '<line class="st-grafic__grila" x1="0" x2="' . $L . '" y1="' . round($H * $f, 1) . '" y2="' . round($H * $f, 1) . '" vector-effect="non-scaling-stroke"/>';
    }
    $pas = $L / max(1, $n);
    if ($bare) {
        foreach ($puncte as $i => $p) {
            $val = (float) $p[$cheie];
            $latime = max(1.0, $pas * ($inainte !== null ? 0.38 : 0.66));
            $x0 = $i * $pas + $pas * 0.17;
            if ($inainte !== null) {
                $vi = (float) ($inainte[$i][$cheie] ?? 0);
                $o .= '<rect class="st-grafic__bara--inainte" x="' . round($x0, 1) . '" y="' . $y($vi) . '" width="' . round($latime, 1) . '" height="' . round($H - $y($vi), 1) . '" rx="2"/>';
                $x0 += $latime;
            }
            $o .= '<rect class="st-grafic__bara" x="' . round($x0, 1) . '" y="' . $y($val) . '" width="' . round($latime, 1) . '" height="' . round($H - $y($val), 1) . '" rx="2"/>';
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
            $o .= '<path class="st-grafic__arie" fill="url(#' . adm_e($id) . '-degrade)" d="' . $d . 'L' . round($x($n - 1), 1) . ' ' . $H . 'L0 ' . $H . 'Z"/>';
            $o .= '<path class="st-grafic__linie" d="' . $d . '" vector-effect="non-scaling-stroke"/>';
        }
    }
    $o .= '<line class="st-grafic__ghid" x1="0" x2="0" y1="0" y2="' . $H . '" vector-effect="non-scaling-stroke" visibility="hidden"/>';
    $o .= '<title>' . adm_e($titlu) . '</title></svg><span class="st-grafic__max">' . adm_e($fmt($max)) . '</span><div class="st-grafic__tip" hidden></div>';
    $axa = [];
    $pasEticheta = max(1, (int) ceil($n / 8));
    foreach ($puncte as $i => $p) {
        if ($i % $pasEticheta === 0 || $i === $n - 1) {
            $axa[] = '<span>' . adm_e($p['eticheta']) . '</span>';
        }
    }
    return $o . '<div class="st-grafic__axa">' . implode('', $axa) . '</div></div>';
};
$cuGrafic = in_array($tab, ['prezentare', 'vanzari'], true) && !$fara;
if ($cuGrafic) {
    $peCe = $perioada['pe_ora'] ? 'pe ore' : 'pe zile';
    $legenda = '<div class="st-legenda"><span><i></i>' . $peCe . ', perioada aleasă</span>' . ($serieInainte !== null ? '<span><i class="st-inainte"></i>perioada dinainte</span>' : '') . '</div>';
    echo '<div class="st-grila">';
    if ($tab === 'prezentare') {
        echo '<section class="st-sectiune" id="st-card-grafic"><div class="st-sectiune__antet"><h2>Vizite ' . $peCe . '</h2><a href="' . $e('/admin/statistici/export?'
            . http_build_query(array_filter(['perioada' => $perioada['cheie'], 'de_la' => $perioada['cheie'] === 'custom' ? $perioada['de_la'] : null,
                'pana_la' => $perioada['cheie'] === 'custom' ? $perioada['pana_la'] : null]) + $filtre + ['tabel' => 'serie'])) . '">CSV</a></div>'
            . $grafic('st-grafic-vizite', 'Vizitele ' . $peCe, $serie, 'vizite', $serieInainte, false, 'nr') . $legenda . '</section>';
    }
    echo '<section class="st-sectiune" id="st-card-venit"><div class="st-sectiune__antet"><h2>Venituri ' . $peCe . '</h2></div>'
        . $grafic('st-grafic-venit', 'Veniturile ' . $peCe, $serie, 'venit', $serieInainte, true, 'lei')
        . '</section>';
    if ($tab === 'vanzari') {
        echo '<section class="st-sectiune"><div class="st-sectiune__antet"><h2>Comenzi ' . $peCe . '</h2></div>'
            . $grafic('st-grafic-comenzi', 'Comenzile ' . $peCe, $serie, 'comenzi', $serieInainte, true, 'nr') . '</section>';
    }
    echo '</div>';
}

// Tabelele tab-ului.
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
$adresaExport = static function (string $tabel) use ($perioada, $filtre): string {
    return '/admin/statistici/export?' . http_build_query(array_filter(['perioada' => $perioada['cheie'], 'de_la' => $perioada['cheie'] === 'custom' ? $perioada['de_la'] : null,
        'pana_la' => $perioada['cheie'] === 'custom' ? $perioada['pana_la'] : null]) + $filtre + ['tabel' => $tabel]);
};
// Coloana cu bara cotei din total, pe fiecare tabel.
$bara = ['surse' => 'vizite', 'campanii' => 'vizite', 'pagini' => 'vizualizari', 'categorii' => 'vizualizari', 'intrari' => 'vizite', 'produse' => 'vizualizari',
    'cautari' => 'cautari', 'fara_rezultat' => 'fara_rezultat', 'dispozitive' => 'vizite', 'browsere' => 'vizite', 'sisteme' => 'vizite', 'tari' => 'vizite',
    'comenzi_cookie' => 'venit'];
$coloaneVanzari = ['eticheta', 'vizite', 'adaugari', 'comenzi', 'conversie', 'venit'];
$goluri = [
    'cautari' => 'Încă nu sunt căutări în perioada asta (căutarea de produse a site-ului; apar doar termenii căutați de cel puțin 2 vizitatori).',
    'fara_rezultat' => 'Nicio căutare fără rezultat (de cel puțin 2 vizitatori) în perioada asta.',
    'campanii' => 'Niciun link cu utm_campaign în perioada asta. Pune ?utm_source=…&utm_campaign=… pe linkurile din postări și reclame.',
    'tari' => 'Găzduirea nu trimite țara vizitatorului, deci tabelul rămâne gol.',
    'comenzi_cookie' => 'Nicio comandă în perioada asta.',
];
$sursaRandului = static function (string $cheieTabel, array $rand): ?string {
    if (in_array($cheieTabel, ['surse'], true)) {
        return (string) ($rand['filtru']['sursa'] ?? 'necunoscuta');
    }
    return null;
};
$limita = $tab === 'prezentare' ? 5 : 0;
$taburiTabel = ['surse' => 'surse', 'pagini' => 'pagini', 'produse' => 'pagini', 'cautari' => 'cautari'];
echo '<div class="st-grila" id="st-tabele">';
foreach ($tabele as $cheieTabel => $tabel) {
    $coloane = $tabel['coloane'];
    if ($tab === 'vanzari' && in_array($cheieTabel, ['surse', 'campanii'], true)) {
        $coloane = array_values(array_filter($coloane, static fn (array $c): bool => in_array($c['cheie'], $coloaneVanzari, true)));
    }
    $randuri = $tabel['randuri'];
    $maiMulte = $limita > 0 && count($randuri) > $limita;
    if ($limita > 0) {
        $randuri = array_slice($randuri, 0, $limita);
    }
    $cheieBara = $tab === 'vanzari' && in_array($cheieTabel, ['surse', 'campanii'], true) ? 'venit' : ($bara[$cheieTabel] ?? '');
    $total = 0.0;
    foreach ($tabel['randuri'] as $r) {
        $total += is_numeric($r['valori'][$cheieBara] ?? null) ? (float) $r['valori'][$cheieBara] : 0.0;
    }
    $titlu = $tabel['titlu'] . ($tab === 'vanzari' && $cheieTabel === 'surse' ? ': vânzări pe sursă' : '');
    $lat = in_array($cheieTabel, ['surse', 'campanii'], true) && $tab !== 'prezentare';
    echo '<section class="st-sectiune' . ($lat ? ' st-lat' : '') . '" id="st-' . $e($cheieTabel) . '"><div class="st-sectiune__antet"><h2>' . $e($titlu) . '</h2>';
    if ($maiMulte && isset($taburiTabel[$cheieTabel])) {
        echo '<a href="' . $e($stAdresa(['tab' => $taburiTabel[$cheieTabel]])) . '">Vezi tot →</a>';
    } elseif ($randuri !== []) {
        echo '<a href="' . $e($adresaExport($cheieTabel)) . '">CSV</a>';
    }
    echo '</div>';
    if ($randuri === []) {
        echo $fara && !in_array($cheieTabel, ['tari', 'comenzi_cookie'], true) ? '<p class="st-nota">' . $e($mesajGol) . '</p>'
            : '<p class="st-nota">' . $e($goluri[$cheieTabel] ?? 'Nimic în perioada aleasă.') . '</p>';
    } else {
        echo '<div class="st-cadru"><table class="st-tabel" data-st-sortabil><thead><tr>';
        foreach ($coloane as $coloana) {
            echo '<th scope="col" data-tip="' . $e($coloana['tip']) . '"><button type="button">' . $e($coloana['eticheta']) . '</button></th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($randuri as $indice => $rand) {
            $copii = $tab === 'prezentare' ? [] : (array) ($rand['copii'] ?? []);
            $sursa = $sursaRandului($cheieTabel, $rand);
            // Bara cotei din total, în spatele etichetei rândului (coloana cea mai lată), în culoarea sursei.
            $cota = $total > 0 && is_numeric($rand['valori'][$cheieBara] ?? null) ? round(100 * (float) $rand['valori'][$cheieBara] / $total, 1) : null;
            echo '<tr data-grup="' . (int) $indice . '">';
            foreach ($coloane as $i => $coloana) {
                [$afisat, $sortare] = $celula($coloana, $rand['valori'][$coloana['cheie']] ?? '');
                if ($i === 0) {
                    $text = $afisat;
                    if (($rand['filtru'] ?? []) !== []) {
                        $text = '<a href="' . $e($stAdresa($rand['filtru'] + ($tab === 'prezentare' ? ['tab' => null] : []))) . '" title="Arată tot tabloul doar pentru „'
                            . $e((string) $rand['valori']['eticheta']) . '”">' . $afisat . '</a>';
                    } elseif (($rand['link'] ?? '') !== '') {
                        $text = '<a href="' . $e($rand['link']) . '" target="_blank" rel="noopener">' . $afisat . '</a>';
                    }
                    $atributCota = $cota !== null ? ' class="st-cota" style="--st-p:' . $cota . ($sursa !== null ? ';--st-c:' . $e($stSursa($sursa)[0]) : '')
                        . '" title="' . $e(number_format($cota, 1, ',', '.') . ' % din total') . '"' : '';
                    echo '<td' . $atributCota . ' data-v="' . $e($sortare) . '"><span class="st-eticheta">'
                        . ($copii !== [] ? '<button type="button" class="st-extinde" aria-expanded="false" aria-label="Arată detaliile">▸</button>' : '')
                        . ($sursa !== null ? $stInsigna($sursa) : '') . $text . '</span></td>';
                } else {
                    echo '<td data-v="' . $e($sortare) . '">' . $afisat . '</td>';
                }
            }
            echo '</tr>';
            foreach ($copii as $copil) {
                echo '<tr class="st-copil" data-parinte="' . (int) $indice . '" hidden>';
                foreach ($coloane as $i => $coloana) {
                    [$afisat, $sortare] = $celula($coloana, $copil['valori'][$coloana['cheie']] ?? '');
                    echo '<td data-v="' . $e($sortare) . '">' . ($i === 0 && ($copil['filtru'] ?? []) !== [] ? '<a href="' . $e($stAdresa($copil['filtru'])) . '">' . $afisat . '</a>' : $afisat) . '</td>';
                }
                echo '</tr>';
            }
        }
        echo '</tbody></table></div>';
    }
    if (($tabel['nota'] ?? '') !== '' && $tab !== 'prezentare') {
        echo '<p class="st-nota">' . $e($tabel['nota']) . '</p>';
    }
    if ($cheieTabel === 'cautari' && $tab === 'cautari') {
        echo '<p class="st-nota">Doar căutările a cel puțin ' . StatisticiRaport::MIN_VIZITATORI_CAUTARE . ' vizitatori; cele cu „@” sau cu 6 cifre la rând (adrese, telefoane, numere) nu se păstrează deloc.</p>'
            . '<p class="st-nota">Cuvintele căutate pe Google nu se pot vedea aici: Google nu le mai trimite site-urilor. (Se pot vedea în Google Search Console; legătura cu el poate veni mai târziu.)</p>';
    }
    echo '</section>';
}
echo '</div>';
?>
<p class="st-nota"><?= $ultimaAgregare !== '' ? 'Date la zi la ' . $e(date('d.m.Y H:i', (int) strtotime($ultimaAgregare))) . '. ' : '' ?>Administratorii logați nu se numără<?= $respectaDnt ? ', nici browserele care cer „nu urmări”' : '; „Do Not Track” nu se respectă (Setări)' ?>.</p>
<script>
(function () {
    // Sortarea tabelelor la clic pe antet (rândurile de detaliu rămân sub rândul lor) și desfacerea unei surse.
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

    // Explicația graficului la trecerea cu mouse-ul sau cu degetul: momentul, valoarea și, cu comparația, perioada dinainte.
    document.querySelectorAll('[data-st-grafic]').forEach(function (g) {
        var puncte = []; try { puncte = JSON.parse(g.getAttribute('data-puncte') || '[]'); } catch (e) {}
        var svg = g.querySelector('svg'), tip = g.querySelector('.st-grafic__tip'), ghid = g.querySelector('.st-grafic__ghid');
        if (!svg || !tip || !puncte.length) { return; }
        var bare = g.getAttribute('data-bare') === '1';
        function arata(ev) {
            var r = svg.getBoundingClientRect(), x = (ev.clientX - r.left) / r.width;
            if (x < 0 || x > 1) { return ascunde(); }
            var n = puncte.length, i = bare ? Math.min(n - 1, Math.floor(x * n)) : Math.round(x * (n - 1));
            var p = puncte[i], px = bare ? (i + 0.5) / n : (n > 1 ? i / (n - 1) : 0.5);
            tip.textContent = '';
            var b = document.createElement('strong'); b.textContent = p.e; tip.appendChild(b);
            tip.appendChild(document.createElement('br')); tip.appendChild(document.createTextNode(p.v));
            if (p.i !== null) { tip.appendChild(document.createElement('br')); tip.appendChild(document.createTextNode('înainte: ' + p.i)); }
            tip.hidden = false;
            tip.style.left = Math.max(60, Math.min(r.width - 60, px * r.width)) + 'px';
            ghid.setAttribute('x1', String(px * 1000)); ghid.setAttribute('x2', String(px * 1000)); ghid.setAttribute('visibility', 'visible');
        }
        function ascunde() { tip.hidden = true; ghid.setAttribute('visibility', 'hidden'); }
        g.addEventListener('pointermove', arata);
        g.addEventListener('pointerdown', arata);
        g.addEventListener('pointerleave', ascunde);
    });

    // „Acum pe site”, la 30 de secunde, cât pagina e deschisă și vizibilă.
    var acum = document.getElementById('st-acum');
    if (!acum || !window.fetch) { return; }
    function chipuri(loc, randuri, eticheta) {
        loc.textContent = '';
        randuri.forEach(function (r) {
            var c = document.createElement('span'), n = document.createElement('strong');
            c.className = 'st-chip'; c.appendChild(document.createTextNode(eticheta(r) + ' ')); n.textContent = String(r.vizitatori);
            c.appendChild(n); loc.appendChild(c);
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
                chipuri(acum.querySelector('[data-st-acum=pagini]'), d.pagini, function (r) { return r.cale; });
                chipuri(acum.querySelector('[data-st-acum=surse]'), d.surse, function (r) { return r.nume; });
            })
            .catch(function () {});
    }
    setInterval(reimprospateaza, 30000);
})();
</script>
<?php endif; ?>
</div>
