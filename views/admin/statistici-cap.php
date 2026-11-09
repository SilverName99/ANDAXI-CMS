<?php
/**
 * Capul paginilor Rapoarte → Statistici (pluginul `statistici`), inclus de statistici.php, statistici-impact.php și
 * statistici-setari.php: titlul, tab-urile (`?tab=`, cu perioada și filtrele păstrate), butoanele perioadei, comparația și
 * filtrele active, plus stilurile comune (culorile surselor, cardurile, tabelele cu bare). Fără bibliotecă și fără CDN.
 *
 * @var string $tab
 */
use App\Http\Controllers\StatisticiController;
use App\Support\StatisticiRaport;

$tab = isset(StatisticiController::TABURI[$tab ?? '']) ? (string) $tab : 'prezentare';
$capPerioada = StatisticiRaport::perioada($_GET);
$capFiltre = StatisticiRaport::filtre($_GET);
$capCompara = ($_GET['compara'] ?? '') === '1';
$capParametri = array_filter(['perioada' => $capPerioada['cheie'], 'de_la' => $capPerioada['cheie'] === 'custom' ? $capPerioada['de_la'] : null,
    'pana_la' => $capPerioada['cheie'] === 'custom' ? $capPerioada['pana_la'] : null, 'compara' => $capCompara ? '1' : null] + $capFiltre,
    static fn ($v): bool => $v !== null && $v !== '');
/** Adresa tabloului cu perioada, filtrele și tab-ul de acum, plus schimbările date (null = scos). */
$stAdresa = static function (array $schimbari = []) use ($capParametri, $tab): string {
    $p = array_merge(['tab' => $tab] + $capParametri, $schimbari);
    if (($p['tab'] ?? '') === 'prezentare') {
        unset($p['tab']);
    }
    $p = array_filter($p, static fn ($v): bool => $v !== null && $v !== '');
    return '/admin/statistici' . ($p === [] ? '' : '?' . http_build_query($p));
};
/** Culoarea și semnul fiecărei surse (insigna din tabele și din „Acum pe site”). */
$stSursa = static function (string $sursa): array {
    return match ($sursa) {
        'tiktok' => ['#111827', 'TT'], 'instagram' => ['#d62976', 'IG'], 'facebook' => ['#1877f2', 'f'], 'google' => ['#4285f4', 'G'],
        'google_ads' => ['#e8a400', 'Ad'], 'bing' => ['#00809d', 'b'], 'cautare' => ['#0ea5e9', 'S'], 'youtube' => ['#e11d1d', '▶'],
        'pinterest' => ['#e60023', 'P'], 'linkedin' => ['#0a66c2', 'in'], 'x' => ['#111827', 'X'], 'whatsapp' => ['#25a35a', 'W'],
        'email' => ['#8b5cf6', '@'], 'campanie' => ['#f97316', 'utm'], 'alt_site' => ['#64748b', '↗'], 'direct' => ['#475569', '→'],
        default => ['#94a3b8', '?'],
    };
};
$stInsigna = static function (string $sursa) use ($stSursa): string {
    [$culoare, $semn] = $stSursa($sursa);
    return '<span class="st-insigna" style="--st-c:' . adm_e($culoare) . '" aria-hidden="true">' . adm_e($semn) . '</span>';
};
$perioadeRapide = ['azi' => 'Azi', 'ieri' => 'Ieri', '7' => '7 zile', '30' => '30 zile', '90' => '90 zile'];
$etichetaFiltru = ['sursa' => 'Sursa', 'detaliu' => 'Detaliu', 'campanie' => 'Campania', 'dispozitiv' => 'Dispozitivul'];
?>
<style>
.st-pagina { --st-spatiu: 20px; display: flex; flex-direction: column; gap: var(--st-spatiu); }
.st-pagina h2 { font-size: 16px; margin: 0; }
.st-taburi { display: flex; gap: 4px; overflow-x: auto; -webkit-overflow-scrolling: touch; border-bottom: 1px solid var(--adm-linie); scrollbar-width: thin; }
.st-tab { flex: 0 0 auto; padding: 10px 14px; text-decoration: none; color: var(--adm-text-secundar); font-weight: 600; font-size: 14px; border-bottom: 3px solid transparent; margin-bottom: -1px; white-space: nowrap; }
.st-tab:hover { color: var(--adm-text); }
.st-tab[aria-current="page"] { color: var(--adm-accent); border-bottom-color: var(--adm-accent); }
.st-tab:focus-visible, .st-buton:focus-visible { outline: 2px solid var(--adm-focus); outline-offset: 2px; }
.st-bara-perioada { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; }
.st-butoane { display: inline-flex; flex-wrap: wrap; gap: 6px; }
.st-buton { display: inline-flex; align-items: center; gap: 6px; min-height: 34px; padding: 0 12px; border-radius: 999px; border: 1px solid var(--adm-linie); background: var(--adm-fundal); color: var(--adm-text); text-decoration: none; font-size: 13px; font-weight: 600; cursor: pointer; }
.st-buton:hover { border-color: var(--adm-accent); }
.st-pagina .st-buton[aria-current="true"], .st-pagina .st-buton.st-buton--activ { background: var(--adm-accent); border-color: var(--adm-accent); color: #fff; }
.st-personalizat { position: relative; }
.st-personalizat > summary { list-style: none; }
.st-personalizat > summary::-webkit-details-marker { display: none; }
.st-personalizat form { position: absolute; z-index: 20; top: calc(100% + 6px); left: 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; padding: 12px; background: var(--adm-fundal); border: 1px solid var(--adm-linie); border-radius: var(--adm-raza); box-shadow: 0 8px 24px rgb(0 0 0 / .12); min-width: 260px; }
.st-personalizat label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: var(--adm-text-secundar); }
.st-personalizat input { min-height: 34px; }
.st-perioada-text { font-size: 13px; color: var(--adm-text-secundar); }
.st-perioada-text strong { color: var(--adm-text); }
.st-active { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; font-size: 13px; }
.st-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; background: var(--adm-accent-fundal); color: var(--adm-text); text-decoration: none; font-size: 13px; }
.st-chip span { color: var(--adm-text-secundar); }
.st-insigna { --st-c: #94a3b8; display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; padding: 0 4px; border-radius: 6px; background: var(--st-c); color: #fff; font-size: 10px; font-weight: 700; line-height: 1; letter-spacing: -.02em; flex: 0 0 auto; }
.st-sectiune { background: var(--adm-fundal); border: 1px solid var(--adm-linie); border-radius: var(--adm-raza); padding: 18px 20px; min-width: 0; }
.st-sectiune__antet { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; margin: 0 0 12px; flex-wrap: wrap; }
.st-sectiune__antet a { font-size: 13px; white-space: nowrap; color: var(--adm-accent); text-decoration: none; }
.st-sectiune__antet a:hover { text-decoration: underline; }
.st-grila { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 460px), 1fr)); gap: var(--st-spatiu); }
.st-grila > .st-lat { grid-column: 1 / -1; }
.st-nota { font-size: 12px; color: var(--adm-text-secundar); margin: 8px 0 0; }
.st-gol { display: flex; gap: 14px; align-items: flex-start; padding: 22px; border-radius: var(--adm-raza); background: var(--adm-accent-fundal); color: var(--adm-text); }
.st-gol svg { flex: 0 0 auto; color: var(--adm-accent); }
.st-gol p { margin: 0 0 4px; }
.st-gol small { color: var(--adm-text-secundar); }
@media (max-width: 600px) {
    .st-pagina { --st-spatiu: 14px; }
    .st-sectiune { padding: 14px; }
}
</style>
<?php
admin_componenta('antet-pagina', [
    'titlu' => 'Statistici',
    'nivel' => 1,
    'subtitlu' => 'Câți oameni intră pe site, de unde vin, ce văd, ce caută și ce comandă. Fără cookie-uri.',
    'ajutor' => [
        'Statisticile sunt ale site-ului, fără cookie-uri și fără nimic ținut în browserul vizitatorului: un vizitator e recunoscut doar în aceeași zi, după un cod calculat din adresa lui și browser, care nu se mai poate reface a doua zi. Nu se păstrează adrese IP.',
        'Nu se numără administratorii logați (deci nici tu, cât ești logat aici), roboții și browserele care cer „nu urmări” (Do Not Track / Global Privacy Control; vezi Setări).',
        'O vizită ține cât vizitatorul nu stă mai mult de 30 de minute fără nicio pagină. Sursa vizitei e a primei pagini: un link cu utm, o reclamă (gclid, fbclid, ttclid), site-ul de pe care a venit sau aplicația (TikTok, Instagram, Facebook); fără nimic, „Direct”.',
        'Pe mai multe zile, „Vizitatori unici (pe zile)” e suma vizitatorilor unici ai fiecărei zile: fără cookie-uri, același om venit în două zile se numără de două ori.',
        'O comandă se atribuie ultimei vizite din aceeași zi venite de undeva (nu „Direct”). Veniturile sunt totalurile comenzilor de acum, fără cele anulate, eșuate, rambursate sau neplătite încă.',
        'Cuvintele căutate pe Google nu se pot vedea: Google nu le trimite site-urilor. Aici apar doar căutările făcute în căutarea site-ului.',
    ],
]);
?>
<nav class="st-taburi" aria-label="Secțiunile statisticilor">
<?php foreach (StatisticiController::TABURI as $cheieTab => $definitieTab): ?>
    <a class="st-tab" href="<?= adm_e($stAdresa(['tab' => $cheieTab])) ?>"<?= $cheieTab === $tab ? ' aria-current="page"' : '' ?>><?= adm_e($definitieTab['eticheta']) ?></a>
<?php endforeach; ?>
</nav>
<?php if (!in_array($tab, ['impact', 'setari'], true)): ?>
<div class="st-bara-perioada" id="st-perioada">
    <div class="st-butoane" role="group" aria-label="Perioada">
<?php foreach ($perioadeRapide as $cheiePerioada => $etichetaPerioada): ?>
        <a class="st-buton" href="<?= adm_e($stAdresa(['perioada' => (string) $cheiePerioada, 'de_la' => null, 'pana_la' => null])) ?>"<?= (string) $cheiePerioada === $capPerioada['cheie'] ? ' aria-current="true"' : '' ?>><?= adm_e($etichetaPerioada) ?></a>
<?php endforeach; ?>
        <details class="st-personalizat"<?= $capPerioada['cheie'] === 'custom' ? ' open' : '' ?>>
            <summary class="st-buton<?= $capPerioada['cheie'] === 'custom' ? ' st-buton--activ' : '' ?>">Personalizat</summary>
            <form method="get" action="/admin/statistici">
<?php if ($tab !== 'prezentare'): ?>
                <input type="hidden" name="tab" value="<?= adm_e($tab) ?>">
<?php endif; ?>
                <input type="hidden" name="perioada" value="custom">
<?php foreach ($capFiltre + ($capCompara ? ['compara' => '1'] : []) as $cheieAscuns => $valoareAscuns): ?>
                <input type="hidden" name="<?= adm_e($cheieAscuns) ?>" value="<?= adm_e($valoareAscuns) ?>">
<?php endforeach; ?>
                <label>De la <input type="date" name="de_la" value="<?= adm_e($capPerioada['de_la']) ?>"></label>
                <label>Până la <input type="date" name="pana_la" value="<?= adm_e($capPerioada['pana_la']) ?>"></label>
                <button class="btn btn-primary" type="submit">Arată</button>
            </form>
        </details>
    </div>
    <a class="st-buton<?= $capCompara ? ' st-buton--activ' : '' ?>" href="<?= adm_e($stAdresa(['compara' => $capCompara ? null : '1'])) ?>" aria-pressed="<?= $capCompara ? 'true' : 'false' ?>"><?= $capCompara ? '✓ ' : '' ?>Compară cu perioada dinainte</a>
    <span class="st-perioada-text">Perioada: <strong><?= adm_e($capPerioada['eticheta']) ?></strong><?= $capCompara ? ' · față de ' . adm_e(date('d.m.Y', (int) strtotime($capPerioada['inainte_de_la'])) . ($capPerioada['zile'] > 1 ? ' – ' . date('d.m.Y', (int) strtotime($capPerioada['inainte_pana_la'])) : '')) : '' ?></span>
</div>
<?php if ($capFiltre !== []): ?>
<div class="st-active">Filtrat după:
<?php foreach ($capFiltre as $cheieFiltru => $valoareFiltru): ?>
    <a class="st-chip" href="<?= adm_e($stAdresa([$cheieFiltru => null] + ($cheieFiltru === 'sursa' ? ['detaliu' => null] : []))) ?>" title="Scoate filtrul"><?= $cheieFiltru === 'sursa' ? $stInsigna($valoareFiltru) : '' ?><span><?= adm_e($etichetaFiltru[$cheieFiltru]) ?>:</span> <?= adm_e($cheieFiltru === 'sursa' ? StatisticiRaport::numeSursa($valoareFiltru) : $valoareFiltru) ?> ✕</a>
<?php endforeach; ?>
    <a href="<?= adm_e($stAdresa(array_fill_keys(StatisticiRaport::FILTRE, null))) ?>">Scoate toate</a>
</div>
<?php endif; ?>
<?php endif; ?>
