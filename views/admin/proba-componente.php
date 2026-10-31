<?php
/**
 * Pagina de probă a componentelor (faza D, WP-31; App\Http\Controllers\ComponenteAdminController): fiecare componentă din
 * views/admin/componente/, cu date inventate. Filtrele și sortarea stau în adresă, ca pe o listă adevărată.
 *
 * @var list<array<string, mixed>> $randuri
 */
require_once __DIR__ . '/componente/_incarca.php';

$etichetaStare = ['noua' => 'Nouă', 'in_lucru' => 'În lucru', 'expediata' => 'Expediată', 'livrata' => 'Livrată'];
$culoareStare = ['noua' => '#1e40af', 'in_lucru' => '#fbbf24', 'expediata' => '#a78bfa', 'livrata' => '#15803d'];
$etichetaPlata = ['card' => 'Card', 'ramburs' => 'Ramburs', 'ordin' => 'Ordin de plată'];
$stare = is_string($_GET['stare'] ?? null) && isset($etichetaStare[$_GET['stare']]) ? $_GET['stare'] : '';
$plata = is_string($_GET['plata'] ?? null) && isset($etichetaPlata[$_GET['plata']]) ? $_GET['plata'] : '';
$cautare = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$deLa = is_string($_GET['de_la'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['de_la']) === 1 ? $_GET['de_la'] : '';
$sort = in_array($_GET['sort'] ?? '', ['numar', 'client', 'total', 'data'], true) ? (string) $_GET['sort'] : 'data';
$dir = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : 'desc';

$numarPeStari = array_fill_keys(array_keys($etichetaStare), 0);
foreach ($randuri as $r) {
    $numarPeStari[$r['stare']]++;
}
$alese = array_values(array_filter($randuri, static function (array $r) use ($stare, $plata, $cautare, $deLa): bool {
    return ($stare === '' || $r['stare'] === $stare) && ($plata === '' || $r['plata'] === $plata) && ($deLa === '' || $r['data'] >= $deLa)
        && ($cautare === '' || mb_stripos($r['numar'] . ' ' . $r['client'], $cautare) !== false);
}));
usort($alese, static fn (array $a, array $b): int => ($dir === 'asc' ? 1 : -1) * ($a[$sort] <=> $b[$sort]));

$parametri = array_filter(['plata' => $plata, 'q' => $cautare, 'de_la' => $deLa]);
$taburi = [['eticheta' => 'Toate', 'href' => '/admin/componente' . ($parametri ? '?' . http_build_query($parametri) : ''), 'numar' => count($randuri), 'activ' => $stare === '']];
foreach ($etichetaStare as $cheie => $eticheta) {
    $taburi[] = ['eticheta' => $eticheta, 'href' => '/admin/componente?' . http_build_query(['stare' => $cheie] + $parametri),
        'numar' => $numarPeStari[$cheie], 'activ' => $stare === $cheie, 'culoare' => $culoareStare[$cheie]];
}
$randuriTabel = [];
foreach ($alese as $r) {
    $randuriTabel[] = [
        'id' => $r['id'],
        'celule' => [
            '<span class="adm-mono">' . adm_e($r['numar']) . '</span>',
            adm_e($r['client']),
            '<span class="adm-stare">' . adm_e($etichetaStare[$r['stare']]) . '</span>',
            adm_e($etichetaPlata[$r['plata']]),
            number_format((float) $r['total'], 2, ',', '.') . ' lei',
            '<span class="adm-mono">' . adm_e(date('d.m.Y', (int) strtotime((string) $r['data']))) . '</span>',
            '<button type="button" class="btn btn-secondary" data-adm-panou="panou-proba" data-adm-panou-sursa="/admin/componente?panou='
                . (int) $r['id'] . '">Detalii</button>',
        ],
    ];
}
?>
<?php admin_componenta('antet-pagina', [
    'id' => 'antet-proba',
    'nivel' => 1,
    'titlu' => 'Componentele adminului',
    'subtitlu' => 'Pagina de probă a fazei D: fiecare piesă din care se refac paginile, cu date inventate. Nu salvează nimic.',
    'ajutor' => [
        'Ajutorul fiecărei pagini stă aici, în spatele lui „?” de lângă titlu: ce faci pe pagină, în cuvinte simple. Esc îl închide.',
        'Filtrele și sortarea se păstrează în adresă: deschide o comandă, apasă Înapoi și lista e tot cea filtrată.',
    ],
    'actiuni' => '<a class="btn btn-secondary" href="/admin">Înapoi la Acasă</a>'
        . '<button type="button" class="btn" data-adm-panou="panou-proba">Deschide panoul</button>',
]); ?>

<div class="adm-carduri">
    <?php admin_componenta('card', ['titlu' => 'De procesat', 'valoare' => (string) $numarPeStari['noua'], 'detaliu' => 'comenzi noi, neconfirmate', 'ton' => 'info',
        'href' => '/admin/componente?stare=noua']); ?>
    <?php admin_componenta('card', ['titlu' => 'Vânzări azi', 'valoare' => '1.284,50 lei', 'detaliu' => '+12 % față de aceeași zi săptămâna trecută', 'ton' => 'ok']); ?>
    <?php admin_componenta('card', ['titlu' => 'Coșuri abandonate', 'valoare' => '3', 'detaliu' => 'în ultimele 24 de ore', 'ton' => 'atentie']); ?>
    <?php admin_componenta('card', ['titlu' => 'Stoc scăzut', 'valoare' => '2', 'detaliu' => 'produse sub pragul de alertă', 'ton' => 'eroare',
        'actiuni' => '<a href="/admin/componente#tabel-proba">Vezi lista</a>']); ?>
</div>

<?php admin_componenta('tab-uri-cu-numar', ['id' => 'taburi-proba', 'eticheta' => 'Filtrează după status', 'taburi' => $taburi]); ?>

<?php admin_componenta('bara-filtre', [
    'id' => 'filtre-proba',
    'actiune' => '/admin/componente',
    'automat' => true,
    'pastreaza' => array_filter(['stare' => $stare]),
    'reseteaza' => '/admin/componente' . ($stare !== '' ? '?stare=' . rawurlencode($stare) : ''),
    'campuri' => [
        ['tip' => 'cautare', 'nume' => 'q', 'eticheta' => 'Caută în comenzi', 'valoare' => $cautare, 'placeholder' => 'Nr. comandă sau client', 'tasta' => '/'],
        ['tip' => 'select', 'nume' => 'plata', 'eticheta' => 'Plată', 'valoare' => $plata, 'optiuni' => ['' => 'Toate'] + $etichetaPlata],
        ['tip' => 'data', 'nume' => 'de_la', 'eticheta' => 'De la', 'valoare' => $deLa],
    ],
]); ?>

<?php admin_componenta('bara-actiuni-masa', [
    'id' => 'masa-proba',
    'tabel' => 'tabel-proba',
    'unitate' => 'comenzi selectate',
    'actiuni' => [
        ['eticheta' => 'Confirmă', 'actiune' => '/admin/componente', 'tip' => 'principal', 'tasta' => 'C'],
        ['eticheta' => 'Tipărește', 'actiune' => '/admin/componente', 'tasta' => 'P'],
        ['eticheta' => 'Șterge', 'actiune' => '/admin/componente', 'tip' => 'pericol', 'confirmare' => 'Ștergi comenzile alese? (doar o probă)'],
    ],
]); ?>
<div id="masa-proba-rezultat" aria-live="polite"></div>

<?php admin_componenta('tabel', [
    'id' => 'tabel-proba',
    'legenda' => 'Comenzile de probă',
    'coloane' => [
        ['eticheta' => 'Comanda', 'cheie' => 'numar'],
        ['eticheta' => 'Client', 'cheie' => 'client'],
        'Status',
        'Plată',
        ['eticheta' => 'Total', 'cheie' => 'total', 'aliniere' => 'dreapta'],
        ['eticheta' => 'Data', 'cheie' => 'data'],
        'Acțiuni',
    ],
    'randuri' => $randuriTabel,
    'sortare' => ['cheie' => $sort, 'dir' => $dir],
    'selectie' => ['nume' => 'ids[]', 'formular' => 'masa-proba-formular', 'eticheta' => 'Alege toate comenzile din listă'],
    'antet_fix' => true,
    'gol' => (static function (): string {
        ob_start();
        admin_componenta('stare-goala', ['titlu' => 'Nicio comandă nu se potrivește filtrelor', 'text' => 'Schimbă filtrele sau resetează-le.',
            'actiune' => '<a class="btn btn-secondary" href="/admin/componente">Resetează filtrele</a>']);
        return (string) ob_get_clean();
    })(),
]); ?>

<?php admin_componenta('panou-lateral', [
    'id' => 'panou-proba',
    'mod' => 'sertar',
    'titlu' => 'Detaliile comenzii',
    'continut' => '<p>Alege „Detalii” pe un rând: corpul panoului se încarcă de la adresa rândului. Esc sau × închide panoul.</p>',
    'subsol' => '<button type="button" class="btn btn-secondary adm-sertar__inchide-text">Închide</button>',
]); ?>

<h2 class="adm-proba-titlu">Formulare</h2>
<?php
ob_start(); ?>
<div class="form-grid">
    <div class="field"><label for="proba-nume">Denumire</label><input id="proba-nume" type="text" value="Ceai de mentă (probă)"></div>
    <div class="field"><label for="proba-sku">SKU</label><input id="proba-sku" type="text" value="DEMO-001" class="adm-mono"></div>
    <div class="field"><label for="proba-tva">TVA</label><select id="proba-tva"><option>21 %</option><option>11 %</option></select></div>
    <div class="field"><label for="proba-descriere">Descriere scurtă</label><textarea id="proba-descriere" rows="2">Text de probă.</textarea></div>
</div>
<?php $campuriProba = (string) ob_get_clean();
admin_componenta('sectiune-formular', ['id' => 'sectiune-baza', 'titlu' => 'Informații de bază', 'descriere' => 'Câmpurile pe care le completezi la fiecare produs.',
    'continut' => $campuriProba]);
admin_componenta('sectiune-formular', ['id' => 'sectiune-rar', 'titlu' => 'Setări folosite rar', 'descriere' => 'O secțiune strânsă: se deschide cu un clic sau cu Enter.',
    'restransa' => true, 'continut' => '<label><input type="checkbox"> O bifă de probă</label>']);
?>

<h2 class="adm-proba-titlu">Mesaje, pași, stări</h2>
<?php admin_componenta('mesaj', ['tip' => 'info', 'titlu' => 'Informare', 'text' => 'Un mesaj neutru, de exemplu ce se întâmplă după salvare.']); ?>
<?php admin_componenta('mesaj', ['tip' => 'succes', 'titlu' => 'Salvat', 'text' => 'Modificările au fost salvate.']); ?>
<?php admin_componenta('mesaj', ['tip' => 'avertisment', 'titlu' => 'Atenție', 'text' => 'Două produse au stocul sub prag.',
    'actiuni' => '<a class="btn btn-secondary" href="/admin/componente">Vezi produsele</a>']); ?>
<?php admin_componenta('mesaj', ['tip' => 'eroare', 'titlu' => 'Nu s-a putut salva', 'text' => 'Verifică câmpurile marcate și încearcă din nou.']); ?>
<?php admin_componenta('bara-pasi', ['id' => 'pasi-proba', 'curent' => 2, 'pasi' => [
    ['nr' => 1, 'eticheta' => 'Conținut'], ['nr' => 2, 'eticheta' => 'Destinatari'], ['nr' => 3, 'eticheta' => 'Verificare'], ['nr' => 4, 'eticheta' => 'Trimite'],
]]); ?>
<div class="adm-proba-coloane">
    <?php admin_componenta('stare-goala', ['titlu' => 'Nu ai încă nicio listă', 'text' => 'O stare goală spune ce poți face mai departe.',
        'actiune' => '<a class="btn btn-secondary" href="/admin/componente">Adaugă o listă</a>']); ?>
    <?php admin_componenta('panou-lateral', ['id' => 'panou-coloana', 'titlu' => 'Rezumat (panou în coloană)',
        'continut' => '<p>Stările: <span class="adm-stare">Ciornă</span> <span class="adm-stare adm-stare--programat">Programat</span> '
            . '<span class="adm-stare adm-stare--trimis">Trimis</span> <span class="adm-stare adm-stare--oprit">Oprit</span></p>']); ?>
</div>

<script>
/* Doar pe pagina de probă: bara acțiunilor în masă arată ce ar trimite, fără să trimită. */
(function () {
  var formular = document.getElementById('masa-proba-formular');
  var rezultat = document.getElementById('masa-proba-rezultat');
  if (!formular || !rezultat) return;
  formular.addEventListener('submit', function (e) {
    e.preventDefault();
    var ids = Array.prototype.slice.call(document.querySelectorAll('input.adm-tabel__rand:checked')).map(function (b) { return b.value; });
    var buton = e.submitter ? e.submitter.textContent.trim().split(/\s+/)[0] : 'acțiunea';
    rezultat.innerHTML = '';
    var p = document.createElement('div');
    p.className = 'adm-mesaj adm-mesaj--info';
    p.setAttribute('role', 'status');
    p.textContent = 'Probă: „' + buton + '” ar trimite comenzile ' + ids.join(', ') + '. Nimic nu s-a salvat.';
    rezultat.appendChild(p);
  });
  document.querySelectorAll('.adm-sertar__inchide-text').forEach(function (b) {
    b.addEventListener('click', function () { if (window.admPanou) window.admPanou.inchide(); });
  });
})();
</script>
