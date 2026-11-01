<?php
/**
 * Setări → Redirecționări și 404 → Jurnalul 404 (WP-38; App\Http\Controllers\RedirectionariController::jurnal,
 * App\Support\Jurnal404).
 *
 * @var bool $disponibil
 * @var list<array<string, mixed>> $randuri
 * @var int $total
 * @var int $pagina
 * @var int $peLaPagina
 * @var string $q
 * @var string $csrf
 */
$csrfCamp = '<input type="hidden" name="_csrf" value="' . adm_e($csrf) . '">';
admin_componenta('antet-pagina', [
    'titlu' => 'Jurnalul 404',
    'nivel' => 1,
    'subtitlu' => 'Adresele publice la care site-ul a răspuns „pagina nu există”, cu numărul accesărilor: redirecționează-le spre pagina potrivită.',
    'ajutor' => [
        'Se notează doar calea (fără parametri), de câte ori și când, plus site-ul de pe care a venit omul (doar numele lui). Fără IP, fără browser.',
        'Rândurile mai vechi de ' . \App\Support\Jurnal404::ZILE_PASTRARE . ' de zile pleacă singure; jurnalul ține cel mult '
            . number_format(\App\Support\Jurnal404::MAX_RANDURI, 0, ',', '.') . ' de adrese.',
        'Nu intră: coșul, plata, contul, adminul, fișierele și încercările roboților de scanare (.php, .env, wp-admin…).',
    ],
    'actiuni' => '<a class="btn btn-secondary" href="/admin/seo/redirectionari">Redirecționări</a>',
]);
?>
<?php if (!$disponibil): ?>
<?php admin_componenta('mesaj', ['tip' => 'avertisment', 'titlu' => 'Jurnalul 404 lipsește', 'text' => 'Rulează migrările (scripts/migreaza.php --aplica).']); ?>
<?php else: ?>
<?php
admin_componenta('bara-filtre', [
    'actiune' => '/admin/seo/404',
    'id' => 'jurnal404-filtre',
    'campuri' => [['tip' => 'cautare', 'nume' => 'q', 'eticheta' => 'Caută', 'valoare' => $q, 'placeholder' => 'o parte din adresă', 'tasta' => '/']],
]);
$randuriTabel = [];
foreach ($randuri as $r) {
    $cale = (string) $r['path'];
    if (is_array($r['regula'] ?? null)) {
        $actiuni = '<span class="adm-stare adm-stare--ok">Are regulă: ' . adm_e((int) $r['regula']['code'] === 410 ? '410' : '→ ' . (string) $r['regula']['to_url']) . '</span>';
    } else {
        $actiuni = '<div class="adm-redir-actiuni">'
            . '<a class="btn btn-light" href="' . adm_e('/admin/seo/redirectionari?' . http_build_query(['din' => $cale, 'sursa_noua' => '404'])) . '#adauga">Redirecționează</a>'
            . '<form method="post" action="/admin/seo/redirectionari/adauga">' . $csrfCamp
            . '<input type="hidden" name="din" value="' . adm_e($cale) . '"><input type="hidden" name="cod" value="410">'
            . '<input type="hidden" name="sursa" value="404"><input type="hidden" name="inapoi" value="/admin/seo/404">'
            . '<button class="btn btn-light" type="submit" title="Dispărută definitiv: Google o scoate mai repede">410</button></form>'
            . '<form method="post" action="/admin/seo/404/' . (int) $r['id'] . '/sterge">' . $csrfCamp
            . '<button class="btn btn-light" type="submit" title="Scoate adresa din jurnal (revine dacă e cerută din nou)">Ignoră</button></form></div>';
    }
    $randuriTabel[] = ['celule' => [
        '<span class="adm-mono">' . adm_e($cale) . '</span>',
        '<span class="adm-numar">' . (int) $r['hits'] . '</span>',
        adm_e(date('d.m.Y H:i', (int) strtotime((string) $r['first_seen_at']))),
        adm_e(date('d.m.Y H:i', (int) strtotime((string) $r['last_seen_at']))),
        trim((string) ($r['referrer_host'] ?? '')) !== '' ? adm_e((string) $r['referrer_host']) : '—',
        $actiuni,
    ]];
}
ob_start();
admin_componenta('stare-goala', ['titlu' => $q !== '' ? 'Nicio adresă nu se potrivește căutării.' : 'Nicio pagină negăsită încă.',
    'text' => 'Când cineva deschide o adresă care nu există pe site, ea apare aici.']);
$gol = (string) ob_get_clean();
admin_componenta('tabel', [
    'id' => 'jurnal404-tabel',
    'legenda' => 'Paginile negăsite',
    'antet_fix' => true,
    'dens' => true,
    'coloane' => [
        ['eticheta' => 'Adresa', 'cheie' => 'cale'],
        ['eticheta' => 'Accesări', 'cheie' => 'hits', 'aliniere' => 'dreapta'],
        'Prima dată',
        ['eticheta' => 'Ultima dată', 'cheie' => 'ultima'],
        'Venit de pe',
        'Acțiuni',
    ],
    'sortare' => ['cheie' => (string) ($_GET['sort'] ?? 'hits'), 'dir' => (string) ($_GET['dir'] ?? 'desc')],
    'randuri' => $randuriTabel,
    'gol' => $gol,
]);
?>
<?php if ($total > $peLaPagina): ?>
    <nav class="adm-paginare" aria-label="Paginile jurnalului">
        <?php if ($pagina > 1): ?><a class="btn btn-light" href="<?= adm_e('/admin/seo/404?' . http_build_query(array_filter(['q' => $q, 'pagina' => $pagina - 1]))) ?>">← Înapoi</a><?php endif; ?>
        <span>Pagina <?= (int) $pagina ?> din <?= (int) ceil($total / $peLaPagina) ?></span>
        <?php if ($pagina * $peLaPagina < $total): ?><a class="btn btn-light" href="<?= adm_e('/admin/seo/404?' . http_build_query(array_filter(['q' => $q, 'pagina' => $pagina + 1]))) ?>">Înainte →</a><?php endif; ?>
    </nav>
<?php endif; ?>
<?php if ($total > 0): ?>
    <form method="post" action="/admin/seo/404/goleste" onsubmit="return confirm('Golești tot jurnalul 404?');" style="margin-top:12px;">
        <?= $csrfCamp ?>
        <button class="btn btn-light" type="submit">Golește jurnalul</button>
    </form>
<?php endif; ?>
<?php endif; ?>
