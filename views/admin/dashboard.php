<?php
require_once __DIR__ . '/componente/_incarca.php';
// Acasă, „B — Compact” (faza D, WP-32; macheta docs/ui/B-Acasa.dc.html). Datele: AdminController::dashboard +
// App\Support\AcasaAdmin. Ce bucăți are pagina depinde de pluginurile site-ului: cifrele zilei, graficul, comenzile
// recente și stocul doar cu magazinul; „De făcut” și rândul de sănătate vin de la pluginurile vizibile (config/hooks.php).
use App\Support\AcasaAdmin;

$widgeturi = is_array($widgeturi ?? null) ? $widgeturi : [];
$areProduse = (bool) ($widgeturi['produse'] ?? true);
$areComenzi = (bool) ($widgeturi['comenzi'] ?? true);
$areUtilizatori = (bool) ($widgeturi['utilizatori'] ?? true);
$areErp = \App\Support\Plugins::vizibil('erp');
$acasa = is_array($acasa ?? null) ? $acasa : [];
$acum = ($acasa['acum'] ?? null) instanceof DateTimeImmutable ? $acasa['acum'] : new DateTimeImmutable();
$recentUsers = is_array($recentUsers ?? null) ? $recentUsers : [];
$deFacut = (array) ($acasa['de_facut'] ?? []);
$sanatate = (array) ($acasa['sanatate'] ?? []);
$e = static fn (mixed $text): string => htmlspecialchars((string) $text, ENT_QUOTES);
$etichetaStare = ['pending' => 'În așteptare', 'pending_payment' => 'Plată în așteptare', 'processing' => 'În procesare',
    'completed' => 'Finalizată', 'cancelled' => 'Anulată', 'refunded' => 'Rambursată', 'returned' => 'Returnată', 'failed' => 'Eșuată'];
$etichetaPlata = ['cod' => 'Ramburs', 'euplatesc' => 'Card', 'stripe' => 'Card', 'btipay' => 'Card BT', 'card' => 'Card', 'bank_transfer' => 'Ordin de plată'];
$etichetaErp = ['sent' => 'Trimisă', 'pending' => 'În așteptare', 'failed' => 'Eșuată', 'skipped' => 'Netrimisă', 'cancelled' => 'Anulată',
    'cancel_pending' => 'Anulare în curs', 'retur_pending' => 'Retur în curs'];

$actiuniAntet = '';
if ($areComenzi) {
    $actiuniAntet .= '<a class="btn btn-secondary" href="/admin/orders">Toate comenzile</a>';
    if ((int) ($acasa['de_procesat']['noi'] ?? 0) > 0) {
        $actiuniAntet .= '<a class="btn" href="/admin/orders?status=pending">Procesează comenzile noi</a>';
    }
}
$actiuniAntet .= '<a class="btn btn-secondary" href="/admin/abonament">Abonamentul tău</a>';
admin_componenta('antet-pagina', [
    'titlu' => 'Acasă',
    'nivel' => 1,
    'id' => 'acasa-antet',
    'subtitlu' => AcasaAdmin::dataLunga($acum) . ' · actualizat la ' . $acum->format('H:i'),
    'actiuni' => $actiuniAntet,
    'ajutor' => [
        $areComenzi ? 'Aici vezi dintr-o privire ziua magazinului: comenzile de lucrat, vânzările de azi față de aceeași zi de săptămâna trecută, '
            . 'coșurile abandonate și produsele pe terminate. Cifrele se recalculează la fiecare deschidere a paginii.'
            : 'Aici vezi dintr-o privire ce e nou pe site și ce e de făcut.',
        'Lista „De făcut” strânge ce așteaptă o decizie de la tine; fiecare rând duce direct la lista potrivită.',
    ],
]);
?>
<div class="adm-acasa">
<?php if ($areComenzi): ?>
<?php
$deProcesat = (array) ($acasa['de_procesat'] ?? []);
$vanzari = (array) ($acasa['vanzari'] ?? []);
$cosuri = (array) ($acasa['cosuri'] ?? []);
$stoc = (array) ($acasa['stoc'] ?? []);
$grafic = (array) ($acasa['grafic'] ?? []);
$azi = (array) ($vanzari['azi'] ?? []);
$trecut = (array) ($vanzari['saptamana_trecuta'] ?? []);
$sumaAzi = (float) ($azi['suma'] ?? 0);
$sumaTrecut = (float) ($trecut['suma'] ?? 0);
$detaliuProcesat = (int) ($deProcesat['noi'] ?? 0) . ' noi · ' . (int) ($deProcesat['in_procesare'] ?? 0) . ' în procesare'
    . (!empty($deProcesat['cea_mai_veche']) ? ' · cea mai veche: ' . AcasaAdmin::cand((string) $deProcesat['cea_mai_veche'], $acum) : '');
$cosMediu = (int) ($azi['comenzi'] ?? 0) > 0 ? $sumaAzi / (int) $azi['comenzi'] : 0.0;
$detaliuVanzari = (int) ($azi['comenzi'] ?? 0) . ((int) ($azi['comenzi'] ?? 0) === 1 ? ' comandă' : ' comenzi')
    . ((int) ($azi['comenzi'] ?? 0) > 0 ? ' · coș mediu ' . AcasaAdmin::lei($cosMediu) . ' lei' : '')
    . ' · ' . (string) ($vanzari['ziua_trecuta'] ?? '') . ' trecută, la aceeași oră: ' . AcasaAdmin::lei($sumaTrecut) . ' lei';
$tonVanzari = $sumaAzi > $sumaTrecut ? 'ok' : ($sumaAzi < $sumaTrecut ? 'atentie' : 'neutru');
?>
    <div class="adm-carduri adm-acasa__cifre">
        <?php admin_componenta('card', ['titlu' => 'Comenzi de procesat', 'valoare' => (string) (int) ($deProcesat['total'] ?? 0),
            'detaliu' => $detaliuProcesat, 'href' => '/admin/orders?status=pending', 'ton' => (int) ($deProcesat['total'] ?? 0) > 0 ? 'info' : 'neutru',
            'id' => 'acasa-de-procesat']); ?>
        <?php admin_componenta('card', ['titlu' => 'Vânzări azi', 'valoare' => AcasaAdmin::lei($sumaAzi) . ' lei', 'detaliu' => $detaliuVanzari,
            'ton' => $tonVanzari, 'id' => 'acasa-vanzari']); ?>
        <?php admin_componenta('card', ['titlu' => 'Coșuri abandonate azi', 'valoare' => (string) (int) ($cosuri['abandonate'] ?? 0),
            'detaliu' => (int) ($cosuri['recuperate'] ?? 0) . ((int) ($cosuri['recuperate'] ?? 0) === 1 ? ' coș terminat cu o comandă' : ' coșuri terminate cu o comandă'),
            'id' => 'acasa-cosuri']); ?>
        <?php admin_componenta('card', ['titlu' => 'Stoc scăzut', 'valoare' => (string) (int) ($stoc['total'] ?? 0),
            'detaliu' => (int) ($stoc['epuizate'] ?? 0) . ' epuizate · ' . (int) ($stoc['putine'] ?? 0) . ' cu mai puțin de ' . AcasaAdmin::PRAG_STOC . ' bucăți',
            'href' => '#acasa-stoc', 'ton' => (int) ($stoc['epuizate'] ?? 0) > 0 ? 'atentie' : 'neutru', 'id' => 'acasa-stoc-card']); ?>
    </div>
<?php endif; ?>

    <p class="adm-acasa__scurt">
<?php if ($areProduse): ?>
        <a href="/admin/products"><span class="adm-mono"><?= (int) ($metrics['products'] ?? 0) ?></span> Produse</a>
<?php endif; ?>
<?php if ($areComenzi): ?>
        <a href="/admin/orders"><span class="adm-mono"><?= (int) ($metrics['orders'] ?? 0) ?></span> Comenzi</a>
<?php endif; ?>
<?php if ($areUtilizatori): ?>
        <a href="/admin/users"><span class="adm-mono"><?= (int) ($metrics['users'] ?? 0) ?></span> Utilizatori</a>
<?php endif; ?>
    </p>

    <div class="adm-acasa__grila">
        <div class="adm-acasa__principal">
<?php if ($areComenzi): ?>
<?php
$zile = (array) ($grafic['zile'] ?? []);
$maxim = 0.0;
foreach ($zile as $zi) {
    $maxim = max($maxim, (float) $zi['suma']);
}
// Axa: un pas rotund (1, 2, 5 × 10ⁿ) cu 3–4 linii.
$pas = 1.0;
if ($maxim > 0) {
    $brut = $maxim / 3;
    $ordin = 10 ** floor(log10($brut));
    foreach ([1, 2, 5, 10] as $m) {
        if ($m * $ordin >= $brut) {
            $pas = $m * $ordin;
            break;
        }
    }
}
$varf = $maxim > 0 ? $pas * ceil($maxim / $pas) : 1.0;
$latime = 700;
$inaltime = 220;
$stanga = 52;
$jos = 34;
$sus = 14;
$zonaH = $inaltime - $jos - $sus;
$coloana = ($latime - $stanga - 8) / max(1, count($zile));
$totalInainte = (float) ($grafic['total_inainte'] ?? 0);
$totalGrafic = (float) ($grafic['total'] ?? 0);
$comparatie = $totalInainte > 0
    ? ($totalGrafic >= $totalInainte ? 'cu ' . number_format(($totalGrafic / $totalInainte - 1) * 100, 1, ',', '.') . ' % mai mult'
        : 'cu ' . number_format((1 - $totalGrafic / $totalInainte) * 100, 1, ',', '.') . ' % mai puțin') . ' decât în cele 14 zile dinainte'
    : 'în cele 14 zile dinainte nu sunt vânzări';
ob_start();
?>
            <p class="adm-acasa__total"><span class="adm-mono"><?= $e(AcasaAdmin::lei($totalGrafic)) ?> lei</span> · <?= (int) ($grafic['comenzi'] ?? 0) ?> comenzi · <?= $e($comparatie) ?></p>
            <svg class="adm-grafic" viewBox="0 0 <?= $latime ?> <?= $inaltime ?>" role="img" aria-labelledby="acasa-grafic-titlu acasa-grafic-desc" preserveAspectRatio="none">
                <title id="acasa-grafic-titlu">Vânzările pe zile, ultimele 14 zile</title>
                <desc id="acasa-grafic-desc"><?php foreach ($zile as $zi): ?><?= $e($zi['data'] . ': ' . AcasaAdmin::lei((float) $zi['suma']) . ' lei, ' . (int) $zi['comenzi'] . ' comenzi. ') ?><?php endforeach; ?></desc>
<?php for ($v = 0.0; $v <= $varf + 0.001; $v += $pas): $y = $sus + $zonaH - ($v / $varf) * $zonaH; ?>
                <line class="adm-grafic__linie" x1="<?= $stanga ?>" x2="<?= $latime ?>" y1="<?= round($y, 1) ?>" y2="<?= round($y, 1) ?>"/>
                <text class="adm-grafic__axa" x="<?= $stanga - 8 ?>" y="<?= round($y + 4, 1) ?>" text-anchor="end"><?= $e(number_format($v, 0, ',', '.')) ?></text>
<?php endfor; ?>
<?php foreach ($zile as $i => $zi): $h = $varf > 0 ? ((float) $zi['suma'] / $varf) * $zonaH : 0; $x = $stanga + $i * $coloana + $coloana * 0.18; $l = $coloana * 0.64; $esteAzi = $i === count($zile) - 1; ?>
                <rect class="adm-grafic__bara<?= $esteAzi ? ' adm-grafic__bara--azi' : '' ?>" x="<?= round($x, 1) ?>" y="<?= round($sus + $zonaH - $h, 1) ?>" width="<?= round($l, 1) ?>" height="<?= round(max($h, (float) $zi['suma'] > 0 ? 1 : 0), 1) ?>" rx="2"><title><?= $e($zi['data'] . ': ' . AcasaAdmin::lei((float) $zi['suma']) . ' lei · ' . (int) $zi['comenzi'] . ' comenzi') ?></title></rect>
                <text class="adm-grafic__zi" x="<?= round($x + $l / 2, 1) ?>" y="<?= $inaltime - 18 ?>" text-anchor="middle"><?= $e($zi['eticheta']) ?></text>
                <text class="adm-grafic__zi adm-grafic__zi--nume<?= $esteAzi ? ' adm-grafic__zi--azi' : '' ?>" x="<?= round($x + $l / 2, 1) ?>" y="<?= $inaltime - 4 ?>" text-anchor="middle"><?= $e($zi['zi']) ?></text>
<?php endforeach; ?>
            </svg>
            <p class="adm-acasa__nota">Sumele sunt în lei, cu TVA și transport, fără comenzile anulate, eșuate, rambursate sau neplătite încă. Pentru azi, datele sunt până la ora <?= $e($acum->format('H:i')) ?>.</p>
<?php
admin_componenta('card', ['titlu' => 'Vânzări în ultimele 14 zile', 'continut' => (string) ob_get_clean(), 'id' => 'acasa-grafic']);

$recente = (array) ($acasa['recente'] ?? []);
$coloaneRecente = [['eticheta' => 'Comanda'], ['eticheta' => 'Ora'], ['eticheta' => 'Client'], ['eticheta' => 'Total', 'aliniere' => 'dreapta'],
    ['eticheta' => 'Plată'], ['eticheta' => 'Status']];
if ($areErp) {
    $coloaneRecente[] = ['eticheta' => 'ERP'];
}
$randuriRecente = [];
foreach ($recente as $comanda) {
    $nume = \App\Support\CustomerDisplay::nume((string) ($comanda['billing_first_name'] ?? ''), (string) ($comanda['billing_last_name'] ?? ''),
        (string) ($comanda['billing_email'] ?? ''));
    $stare = strtolower((string) ($comanda['status'] ?? ''));
    $agresiv = (string) ($comanda['erp_client_agresiv'] ?? '') === '1';
    $celule = [
        '<a class="adm-mono" href="/admin/orders?q=' . $e(rawurlencode((string) ($comanda['order_number'] ?? ''))) . '">' . $e($comanda['order_number'] ?? '') . '</a>',
        '<span class="adm-mono">' . $e(AcasaAdmin::cand((string) ($comanda['created_at'] ?? ''), $acum)) . '</span>',
        $e($nume !== '' ? $nume : 'Client') . ($agresiv ? ' <span class="client-agresiv-badge" title="În ERP, clientul acestei comenzi are semnul „Client agresiv” pe fișă.">Client agresiv</span>' : ''),
        $e(AcasaAdmin::lei((float) ($comanda['total'] ?? 0))) . ' lei',
        $e($etichetaPlata[strtolower((string) ($comanda['payment_method'] ?? ''))] ?? (string) ($comanda['payment_method'] ?? '')),
        '<span class="adm-stare adm-stare--comanda-' . $e(preg_replace('/[^a-z_]/', '', $stare)) . '">' . $e($etichetaStare[$stare] ?? $stare) . '</span>',
    ];
    if ($areErp) {
        $celule[] = $e($etichetaErp[strtolower((string) ($comanda['erp_status'] ?? ''))] ?? (string) ($comanda['erp_status'] ?? ''));
    }
    $randuriRecente[] = ['celule' => $celule, 'atribute' => $agresiv ? 'class="is-client-agresiv"' : ''];
}
ob_start();
admin_componenta('tabel', ['coloane' => $coloaneRecente, 'randuri' => $randuriRecente, 'dens' => true, 'id' => 'acasa-recente-tabel',
    'legenda' => 'Ultimele comenzi',
    'gol' => (static function (): string { ob_start(); admin_componenta('stare-goala', ['titlu' => 'Nu există comenzi încă.']); return (string) ob_get_clean(); })()]);
$tabelRecente = (string) ob_get_clean();
?>
            <section class="adm-acasa__bloc" aria-labelledby="acasa-recente-titlu">
                <header class="adm-acasa__bloc-antet">
                    <h2 id="acasa-recente-titlu">Comenzi recente</h2>
                    <a href="/admin/orders">Vezi toate</a>
                </header>
                <?= $tabelRecente ?>
            </section>
<?php endif; ?>
<?php if ($areUtilizatori): ?>
            <section class="adm-acasa__bloc" aria-labelledby="acasa-utilizatori-titlu">
                <header class="adm-acasa__bloc-antet">
                    <h2 id="acasa-utilizatori-titlu">Utilizatori recenți</h2>
                    <a href="/admin/users">Vezi toți</a>
                </header>
                <?php if ($recentUsers !== []): ?>
                    <ul class="adm-acasa__lista">
                        <?php foreach ($recentUsers as $user): ?>
                            <li>
                                <a href="/admin/users/<?= (int) ($user['id'] ?? 0) ?>">
                                    <strong><?= $e(trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''))) ?></strong>
                                    <span><?= $e($user['email'] ?? '') ?></span>
                                </a>
<?php if ($areComenzi): ?>
                                <span class="adm-mono adm-acasa__dreapta"><?= (int) ($user['orders_count'] ?? 0) ?> comenzi</span>
<?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <?php admin_componenta('stare-goala', ['titlu' => 'Nu există utilizatori încă.']); ?>
                <?php endif; ?>
            </section>
<?php endif; ?>
        </div>

        <aside class="adm-acasa__lateral" aria-label="De făcut">
            <section class="adm-acasa__bloc adm-acasa__de-facut" aria-labelledby="acasa-de-facut-titlu">
                <header class="adm-acasa__bloc-antet">
                    <h2 id="acasa-de-facut-titlu">De făcut <span class="adm-numar"><?= count($deFacut) ?></span></h2>
                </header>
                <?php if ($deFacut === []): ?>
                    <p class="adm-acasa__nimic">Nimic de făcut acum. Când apare ceva care așteaptă o decizie, îl vezi aici.</p>
                <?php else: ?>
                    <ul class="adm-acasa__sarcini">
                        <?php foreach ($deFacut as $sarcina): ?>
                            <li class="adm-acasa__sarcina adm-acasa__sarcina--<?= $e($sarcina['ton']) ?>">
                                <span class="adm-acasa__sarcina-numar adm-mono"><?= (int) $sarcina['numar'] ?></span>
                                <span class="adm-acasa__sarcina-text"><?= $e($sarcina['text']) ?></span>
                                <a class="btn btn-secondary" href="<?= $e($sarcina['href']) ?>"><?= $e($sarcina['actiune']) ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
<?php if ($areComenzi): ?>
            <section class="adm-acasa__bloc" id="acasa-stoc" aria-labelledby="acasa-stoc-titlu">
                <header class="adm-acasa__bloc-antet">
                    <h2 id="acasa-stoc-titlu">Stoc scăzut</h2>
                    <a href="/admin/products">Toate produsele</a>
                </header>
                <?php if ((array) ($stoc['produse'] ?? []) === []): ?>
                    <p class="adm-acasa__nimic">Toate produsele active au cel puțin <?= AcasaAdmin::PRAG_STOC ?> bucăți.</p>
                <?php else: ?>
                    <ul class="adm-acasa__lista">
                        <?php foreach ((array) $stoc['produse'] as $produs): ?>
                            <li>
                                <span><?= $e($produs['nume']) ?></span>
                                <span class="adm-acasa__dreapta <?= $produs['epuizat'] ? 'adm-stare adm-stare--oprit' : 'adm-mono' ?>"><?= $produs['epuizat'] ? 'Epuizat' : (int) $produs['stoc'] . ' buc.' ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ((int) ($stoc['total'] ?? 0) > count((array) $stoc['produse'])): ?>
                        <p class="adm-acasa__nota">Și încă <?= (int) $stoc['total'] - count((array) $stoc['produse']) ?>.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
<?php endif; ?>
        </aside>
    </div>

    <?php if ($sanatate !== []): ?>
        <ul class="adm-acasa__sanatate" aria-label="Starea legăturilor site-ului">
            <?php foreach ($sanatate as $bucata): ?>
                <li class="adm-acasa__sanatate-bucata adm-acasa__sanatate-bucata--<?= $e($bucata['ton']) ?>">
                    <?php if ($bucata['href'] !== ''): ?>
                        <a href="<?= $e($bucata['href']) ?>"><?= $e($bucata['text']) ?></a>
                    <?php else: ?>
                        <?= $e($bucata['text']) ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
