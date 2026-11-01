<?php
/**
 * Setări → Redirecționări și 404 (WP-38; App\Http\Controllers\RedirectionariController, App\Support\Redirectionari).
 *
 * @var bool $disponibil
 * @var list<array<string, mixed>> $reguli
 * @var array<string, int> $numere
 * @var int $total
 * @var int $pagina
 * @var int $peLaPagina
 * @var array{sursa: string, stare: string, q: string} $filtru
 * @var list<array{din: string, spre: string, finala: ?string}> $lanturi
 * @var array<string, mixed>|null $editeaza
 * @var array{din: string, spre: string, cod: int, prefix: bool, nota: string, sursa: string} $precompletat
 * @var int $nr404
 * @var array{la_zi: bool, eroare: ?string} $stareCopie
 * @var int $propuneri
 * @var string $csrf
 */
$csrfCamp = '<input type="hidden" name="_csrf" value="' . adm_e($csrf) . '">';
$lista = '/admin/seo/redirectionari';
$linkFiltru = static function (array $schimbari) use ($filtru, $lista): string {
    $p = array_filter(array_merge(['sursa' => $filtru['sursa'], 'stare' => $filtru['stare'], 'q' => $filtru['q']], $schimbari), static fn ($v) => $v !== '');
    return $lista . ($p !== [] ? '?' . http_build_query($p) : '');
};
$adresaCurenta = $linkFiltru(['pagina' => $pagina > 1 ? (string) $pagina : '']);

admin_componenta('antet-pagina', [
    'titlu' => 'Redirecționări și 404',
    'nivel' => 1,
    'subtitlu' => 'Adresele vechi ale site-ului și unde trimit: o pagină mutată (301) sau dispărută definitiv (410).',
    'ajutor' => [
        'O regulă se aplică doar unde site-ul ar răspunde „pagina nu există”: o pagină care există nu e acoperită niciodată.',
        'Vizitatorul ajunge dintr-un singur salt la pagina finală, cu parametrii din adresă păstrați (gclid, utm_…). Coșul, plata, contul, adminul și confirmările de plată nu se redirecționează niciodată.',
        'Ținta e mereu o pagină a acestui site: o adresă a altui site nu se acceptă. O țintă care e la rândul ei redirecționată se refuză (pune direct ținta finală).',
        'Când schimbi adresa unui produs, a unei categorii, pagini sau articol, adresa veche trimite singură la cea nouă (sursa „Automată”).',
    ],
    'actiuni' => '<a class="btn btn-secondary" href="/admin/seo/404">Jurnalul 404' . ($nr404 > 0 ? ' (' . (int) $nr404 . ')' : '') . '</a>'
        . ($disponibil ? ' <a class="btn btn-secondary" href="/admin/seo/redirectionari/export">Exportă CSV</a>' : ''),
]);
?>
<?php if (!$disponibil): ?>
<?php admin_componenta('mesaj', ['tip' => 'avertisment', 'titlu' => 'Tabelul redirecționărilor lipsește',
    'text' => 'Rulează migrările (scripts/migreaza.php --aplica). Până atunci se aplică doar regulile din pachetul site-ului (redirecturi.json), ca înainte.']); ?>
<?php else: ?>
<?php if (($stareCopie['eroare'] ?? null) !== null): ?>
<?php admin_componenta('mesaj', ['tip' => 'eroare', 'titlu' => 'Regulile de aici nu ajung pe site', 'text' => (string) $stareCopie['eroare']]); ?>
<?php endif; ?>
<?php if (($propuneri ?? 0) > 0): ?>
<?php admin_componenta('mesaj', ['tip' => 'info', 'titlu' => (int) $propuneri . ' ' . ((int) $propuneri === 1 ? 'pagină ștearsă așteaptă' : 'pagini șterse așteaptă')
    . ' o alegere pentru adresa veche', 'text' => 'Propunerile apar pe rând, deasupra paginilor adminului (una la fiecare pagină deschisă).']); ?>
<?php endif; ?>
<?php if ($lanturi !== []): ?>
<?php admin_componenta('mesaj', ['tip' => 'info', 'titlu' => count($lanturi) . ' ' . (count($lanturi) === 1 ? 'regulă trimite' : 'reguli trimit') . ' la o adresă redirecționată la rândul ei',
    'text' => implode(' · ', array_map(static fn (array $l): string => $l['din'] . ' → ' . $l['spre'] . ($l['finala'] !== null ? ' (vizitatorul ajunge direct la ' . $l['finala'] . ')'
        : ' (buclă sau spre o adresă dispărută: rămâne 404)'), array_slice($lanturi, 0, 8))) . (count($lanturi) > 8 ? ' …' : '')
        . '. Harta le urmează într-un singur salt; ca să fie clar, pune ținta finală direct.']); ?>
<?php endif; ?>

<?php ob_start(); ?>
    <form method="post" action="/admin/seo/redirectionari/adauga" class="form-grid adm-redir-formular">
        <?= $csrfCamp ?>
        <input type="hidden" name="sursa" value="<?= adm_e($precompletat['sursa']) ?>">
        <div class="field">
            <label for="redir-din">Adresa veche</label>
            <input type="text" id="redir-din" name="din" required maxlength="600" value="<?= adm_e($precompletat['din']) ?>"
                   placeholder="/blog/articol-vechi sau https://site.ro/blog/articol-vechi" spellcheck="false">
        </div>
        <div class="field">
            <label for="redir-cod">Ce se întâmplă</label>
            <select id="redir-cod" name="cod">
                <?php foreach (\App\Support\Redirectionari::CODURI as $cod => $eticheta): ?>
                    <option value="<?= (int) $cod ?>"<?= $precompletat['cod'] === $cod ? ' selected' : '' ?>><?= adm_e($eticheta) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="redir-spre">Ținta (la 301)</label>
            <input type="text" id="redir-spre" name="spre" maxlength="600" value="<?= adm_e($precompletat['spre']) ?>"
                   placeholder="/blog/articol-nou" spellcheck="false">
        </div>
        <div class="field">
            <label for="redir-nota">Notă (opțional)</label>
            <input type="text" id="redir-nota" name="nota" maxlength="255" value="<?= adm_e($precompletat['nota']) ?>" placeholder="de ce există regula">
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label class="adm-redir-bifa"><input type="checkbox" name="prefix" value="1"<?= $precompletat['prefix'] ? ' checked' : '' ?>>
                Prefix: tot ce începe cu adresa veche (de exemplu <code>/wp-content/</code>)</label>
        </div>
        <div style="grid-column:1/-1;">
            <button class="btn" type="submit"><?= $editeaza !== null ? 'Salvează regula' : 'Adaugă redirecționarea' ?></button>
            <?php if ($editeaza !== null): ?><a class="btn btn-light" href="<?= adm_e($lista) ?>">Renunță</a><?php endif; ?>
        </div>
    </form>
<?php admin_componenta('sectiune-formular', ['id' => 'adauga', 'titlu' => $editeaza !== null ? 'Editează regula' : 'Adaugă o redirecționare',
    'descriere' => 'Adresa veche: o cale a acestui site. Ținta: o pagină a acestui site (o adresă a altui site nu se acceptă).', 'continut' => (string) ob_get_clean()]); ?>

<?php ob_start(); ?>
    <form method="post" action="/admin/seo/redirectionari/importa" enctype="multipart/form-data" class="form-grid">
        <?= $csrfCamp ?>
        <div class="field">
            <label for="redir-fisier">Fișier CSV</label>
            <input type="file" id="redir-fisier" name="fisier" accept=".csv,.txt,text/csv,text/plain">
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label for="redir-csv">sau lipește rândurile</label>
            <textarea id="redir-csv" name="csv" rows="5" spellcheck="false"
                      placeholder="/blog/vitamina-d-si-imunitate,/blog/vitamina-d-și-imunitate&#10;/produs-vechi,,410"></textarea>
            <small class="muted">Un rând pe adresă: <code>adresa veche, ținta[, cod][, notă]</code>, separate prin virgulă, punct și virgulă sau tab;
                ținta goală sau codul 410 = dispărută. Merge pentru o listă de adrese vechi (de exemplu articolele de blog cu diacriticele tăiate din
                adresă): fiecare rând trece prin aceleași verificări, iar cele refuzate se arată cu motivul.</small>
        </div>
        <div style="grid-column:1/-1;"><button class="btn btn-secondary" type="submit">Importă</button></div>
    </form>
<?php admin_componenta('sectiune-formular', ['id' => 'importa', 'titlu' => 'Importă din CSV', 'pliabila' => true, 'restransa' => true,
    'descriere' => 'Multe adrese vechi odată.', 'continut' => (string) ob_get_clean()]); ?>

<?php
admin_componenta('tab-uri-cu-numar', [
    'eticheta' => 'Filtrează regulile',
    'taburi' => [
        ['eticheta' => 'Toate', 'href' => $linkFiltru(['sursa' => '', 'stare' => '']), 'numar' => $numere['toate'], 'activ' => $filtru['sursa'] === '' && $filtru['stare'] === ''],
        ['eticheta' => 'Pornite', 'href' => $linkFiltru(['sursa' => '', 'stare' => 'pornite']), 'numar' => $numere['pornite'], 'activ' => $filtru['stare'] === 'pornite' && $filtru['sursa'] === ''],
        ['eticheta' => 'Oprite', 'href' => $linkFiltru(['sursa' => '', 'stare' => 'oprite']), 'numar' => $numere['oprite'], 'activ' => $filtru['stare'] === 'oprite' && $filtru['sursa'] === ''],
        ['eticheta' => 'Din pachet', 'href' => $linkFiltru(['sursa' => 'pachet', 'stare' => '']), 'numar' => $numere['pachet'], 'activ' => $filtru['sursa'] === 'pachet'],
        ['eticheta' => 'Automate', 'href' => $linkFiltru(['sursa' => 'slug', 'stare' => '']), 'numar' => $numere['slug'], 'activ' => $filtru['sursa'] === 'slug'],
        ['eticheta' => 'Adăugate', 'href' => $linkFiltru(['sursa' => 'adaugate', 'stare' => '']), 'numar' => $numere['adaugate'], 'activ' => $filtru['sursa'] === 'adaugate'],
    ],
]);
admin_componenta('bara-filtre', [
    'actiune' => $lista,
    'id' => 'redir-filtre',
    'pastreaza' => array_filter(['sursa' => $filtru['sursa'], 'stare' => $filtru['stare']], static fn ($v) => $v !== ''),
    'campuri' => [['tip' => 'cautare', 'nume' => 'q', 'eticheta' => 'Caută', 'valoare' => $filtru['q'], 'placeholder' => 'adresa veche, ținta sau nota', 'tasta' => '/']],
]);
$randuri = [];
foreach ($reguli as $r) {
    $id = (int) $r['id'];
    $pornita = (int) $r['is_active'] === 1;
    $tinta = (int) $r['code'] === 410 ? '<span class="adm-stare">410 dispărută</span>'
        : '<a class="adm-mono" href="' . adm_e((string) $r['to_url']) . '" target="_blank" rel="noopener">' . adm_e((string) $r['to_url']) . '</a>';
    $actiuni = '<div class="adm-redir-actiuni">'
        . '<a class="btn btn-light" href="' . adm_e($lista . '?editeaza=' . $id) . '#adauga">Editează</a>'
        . '<form method="post" action="/admin/seo/redirectionari/' . $id . '/stare">' . $csrfCamp
        . '<input type="hidden" name="inapoi" value="' . adm_e($adresaCurenta) . '">'
        . '<input type="hidden" name="pornita" value="' . ($pornita ? '0' : '1') . '">'
        . '<button class="btn btn-light" type="submit">' . ($pornita ? 'Oprește' : 'Pornește') . '</button></form>'
        . '<form method="post" action="/admin/seo/redirectionari/' . $id . '/sterge" onsubmit="return confirm(\'Ștergi regula? Adresa veche va răspunde din nou „pagina nu există”.\');">'
        . $csrfCamp . '<input type="hidden" name="inapoi" value="' . adm_e($adresaCurenta) . '">'
        . '<button class="btn btn-light" type="submit">Șterge</button></form></div>';
    $randuri[] = ['celule' => [
        '<span class="adm-mono">' . adm_e((string) $r['from_path']) . ((int) $r['is_prefix'] === 1 ? '…' : '') . '</span>'
            . (trim((string) ($r['note'] ?? '')) !== '' ? '<br><small class="muted">' . adm_e((string) $r['note']) . '</small>' : ''),
        $tinta,
        adm_e(\App\Support\Redirectionari::SURSE[(string) $r['source']] ?? (string) $r['source']),
        '<span class="adm-numar">' . (int) $r['hits'] . '</span>',
        $r['last_hit_at'] !== null ? adm_e(date('d.m.Y H:i', (int) strtotime((string) $r['last_hit_at']))) : '—',
        $pornita ? '<span class="adm-stare adm-stare--ok">Pornită</span>' : '<span class="adm-stare">Oprită</span>',
        $actiuni,
    ]];
}
ob_start();
admin_componenta('stare-goala', ['titlu' => $filtru['q'] !== '' || $filtru['sursa'] !== '' || $filtru['stare'] !== '' ? 'Nicio regulă nu se potrivește filtrului.' : 'Nicio redirecționare încă.',
    'text' => 'Adaugă una mai sus, importă o listă sau pornește din Jurnalul 404.']);
$gol = (string) ob_get_clean();
admin_componenta('tabel', [
    'id' => 'redir-tabel',
    'legenda' => 'Redirecționările site-ului',
    'antet_fix' => true,
    'dens' => true,
    'coloane' => [
        ['eticheta' => 'Adresa veche', 'cheie' => 'din'],
        'Ținta',
        'Sursa',
        ['eticheta' => 'Accesări', 'cheie' => 'hits', 'aliniere' => 'dreapta'],
        ['eticheta' => 'Ultima accesare', 'cheie' => 'ultima'],
        'Starea',
        'Acțiuni',
    ],
    'sortare' => ['cheie' => (string) ($_GET['sort'] ?? 'din'), 'dir' => (string) ($_GET['dir'] ?? 'asc')],
    'randuri' => $randuri,
    'gol' => $gol,
]);
?>
<?php if ($total > $peLaPagina): ?>
    <nav class="adm-paginare" aria-label="Paginile listei">
        <?php if ($pagina > 1): ?><a class="btn btn-light" href="<?= adm_e($linkFiltru(['pagina' => (string) ($pagina - 1)])) ?>">← Înapoi</a><?php endif; ?>
        <span>Pagina <?= (int) $pagina ?> din <?= (int) ceil($total / $peLaPagina) ?></span>
        <?php if ($pagina * $peLaPagina < $total): ?><a class="btn btn-light" href="<?= adm_e($linkFiltru(['pagina' => (string) ($pagina + 1)])) ?>">Înainte →</a><?php endif; ?>
    </nav>
<?php endif; ?>

<?php ob_start(); ?>
    <form method="post" action="/admin/seo/redirectionari/pachet">
        <?= $csrfCamp ?>
        <p class="muted" style="margin:0 0 8px;">Regulile din <code>redirecturi.json</code> al site-ului intră singure în listă (sursa „Din pachet”) când
            fișierul se schimbă. O regulă din pachet editată aici devine „Adăugată de mână”; una ștearsă rămâne oprită.</p>
        <button class="btn btn-secondary" type="submit">Reia regulile din pachet</button>
    </form>
<?php admin_componenta('sectiune-formular', ['id' => 'pachet', 'titlu' => 'Pachetul site-ului', 'pliabila' => true, 'restransa' => true,
    'continut' => (string) ob_get_clean()]); ?>
<?php endif; ?>
