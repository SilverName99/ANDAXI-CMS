<?php
/**
 * „Oferte BBD” (WP-35, IA-05): toate ofertele cu dată minimă de valabilitate din toate produsele (OferteBbdController). Doar
 * citire; fiecare rând duce la editorul produsului (/admin/products#editeaza-<id>), unde se schimbă oferta.
 *
 * @var list<array<string, mixed>> $oferte
 * @var array<string, int> $numar
 * @var string $cautare
 * @var string $stare
 */
$oferte = is_array($oferte ?? null) ? $oferte : [];
$numar = is_array($numar ?? null) ? $numar : ['' => 0, 'valabile' => 0, 'expirate' => 0, 'oprite' => 0];
$cautare = (string) ($cautare ?? '');
$stare = (string) ($stare ?? '');
$bani = static fn (?float $suma): string => $suma === null ? '—' : number_format($suma, 2, ',', '.') . ' lei';
$adresa = static function (string $stareNoua) use ($cautare): string {
    $parametri = array_filter(['stare' => $stareNoua, 'q' => $cautare], static fn (string $v): bool => $v !== '');
    return '/admin/products/oferte-bbd' . ($parametri !== [] ? '?' . http_build_query($parametri) : '');
};

admin_componenta('antet-pagina', [
    'titlu' => 'Oferte BBD',
    'nivel' => 1,
    'subtitlu' => 'Toate loturile cu dată minimă de valabilitate și preț propriu, din toate produsele, în ordinea datei.',
    'ajutor' => [
        'O ofertă BBD e un lot al unui produs, cu data lui de valabilitate și un preț redus; clientul o alege înainte de coș.',
        'Lista doar arată ofertele. Le schimbi din editorul produsului (secțiunea „Oferte BBD”): butonul „Deschide produsul” de pe fiecare rând.',
        '„Oprite” sunt ofertele unui produs la care bifa ofertelor BBD e scoasă: nu apar pe site.',
    ],
]);
admin_componenta('tab-uri-cu-numar', ['eticheta' => 'Ofertele, după stare', 'taburi' => [
    ['eticheta' => 'Toate', 'href' => $adresa(''), 'numar' => $numar[''], 'activ' => $stare === ''],
    ['eticheta' => 'Valabile', 'href' => $adresa('valabile'), 'numar' => $numar['valabile'], 'activ' => $stare === 'valabile'],
    ['eticheta' => 'Oprite', 'href' => $adresa('oprite'), 'numar' => $numar['oprite'], 'activ' => $stare === 'oprite'],
    ['eticheta' => 'Expirate', 'href' => $adresa('expirate'), 'numar' => $numar['expirate'], 'activ' => $stare === 'expirate'],
]]);
admin_componenta('bara-filtre', [
    'actiune' => '/admin/products/oferte-bbd',
    'pastreaza' => $stare !== '' ? ['stare' => $stare] : [],
    'reseteaza' => $adresa(''),
    'automat' => true,
    'campuri' => [['tip' => 'cautare', 'nume' => 'q', 'eticheta' => 'Caută produsul, codul sau lotul', 'valoare' => $cautare,
        'placeholder' => 'Caută produsul, codul sau lotul', 'tasta' => '/']],
]);
$randuri = [];
foreach ($oferte as $o) {
    $stareOferta = $o['expirata'] ? ['Expirată', 'adm-stare--eroare'] : ($o['bbd_pornit'] ? ['Valabilă', 'adm-stare--ok'] : ['Oprită', 'adm-stare--neutru']);
    $randuri[] = ['celule' => [
        '<span class="adm-mono">' . adm_e(date('d.m.Y', (int) strtotime($o['data']))) . '</span>',
        '<strong>' . adm_e($o['produs']) . '</strong>' . ($o['sku'] !== '' ? '<br><span class="adm-text-slab adm-mono">' . adm_e($o['sku']) . '</span>' : '')
            . (!$o['activ_produs'] ? ' <span class="adm-stare adm-stare--neutru">produs inactiv</span>' : ''),
        adm_e($o['lot'] !== '' ? $o['lot'] : '—') . ($o['eticheta'] !== '' ? '<br><span class="adm-text-slab">' . adm_e($o['eticheta']) . '</span>' : ''),
        '<span class="adm-mono">' . adm_e($bani($o['pret_redus'])) . '</span><br><span class="adm-text-slab adm-mono">din ' . adm_e($bani($o['pret'])) . '</span>',
        '<span class="adm-mono">' . adm_e($o['stoc'] === null ? '—' : (string) $o['stoc']) . '</span>',
        '<span class="adm-stare ' . $stareOferta[1] . '">' . adm_e($stareOferta[0]) . '</span>',
        '<a class="btn btn-secondary" href="/admin/products#editeaza-' . (int) $o['produs_id'] . '">Deschide produsul</a>',
    ]];
}
admin_componenta('tabel', [
    'id' => 'oferte-bbd',
    'legenda' => 'Ofertele BBD ale produselor',
    'antet_fix' => true,
    'coloane' => ['Valabil până la', 'Produs', 'Lot', ['eticheta' => 'Preț redus', 'aliniere' => 'dreapta'],
        ['eticheta' => 'Stoc lot', 'aliniere' => 'dreapta'], 'Stare', ''],
    'randuri' => $randuri,
    'gol' => (static function () use ($cautare, $stare): string {
        ob_start();
        admin_componenta('stare-goala', [
            'titlu' => $cautare !== '' || $stare !== '' ? 'Nicio ofertă nu se potrivește filtrului.' : 'Niciun produs nu are încă oferte BBD.',
            'text' => 'O ofertă se adaugă din editorul produsului, secțiunea „Oferte BBD”.',
            'actiune' => '<a class="btn btn-secondary" href="/admin/products">Mergi la produse</a>',
        ]);
        return (string) ob_get_clean();
    })(),
]);
