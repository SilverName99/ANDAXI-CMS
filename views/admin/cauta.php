<?php
require_once __DIR__ . '/componente/_incarca.php';
// Căutarea în tot adminul (faza D, WP-36; CautareAdminController): pagina rezultatelor, pentru cine nu folosește fereastra de
// la Ctrl+K (fără JavaScript) sau a ales acolo „Vezi toate rezultatele”. Aceleași rezultate ca fereastra, grupate pe surse;
// fiecare sursă doar cu pluginul ei pe site și cu dreptul rolului (App\Support\CautareAdmin).
$cautare = is_array($cautare ?? null) ? $cautare : ['q' => '', 'grupuri' => [], 'ms' => 0];
$q = (string) $cautare['q'];
$numar = 0;
foreach ($cautare['grupuri'] as $grup) {
    $numar += count($grup['rezultate']);
}
admin_componenta('antet-pagina', [
    'titlu' => 'Caută în admin',
    'nivel' => 1,
    'id' => 'cauta-antet',
    'subtitlu' => $q === '' ? 'Scrie ce cauți (cel puțin ' . \App\Support\CautareAdmin::MINIM . ' litere sau cifre).'
        : ($numar === 0 ? 'Nimic găsit pentru „' . $q . '”.' : $numar . ($numar === 1 ? ' rezultat' : ' rezultate') . ' pentru „' . $q . '”.'),
    'ajutor' => [
        'Din orice pagină a adminului: Ctrl K (pe Mac ⌘ K) deschide fereastra căutării; săgețile aleg, Enter deschide, Esc închide.',
        'Se caută doar unde ai voie: fiecare rol vede doar listele lui.',
    ],
]);
admin_componenta('bara-filtre', [
    'actiune' => '/admin/cauta',
    'id' => 'cauta-filtre',
    'eticheta' => 'Caută în admin',
    'campuri' => [['tip' => 'cautare', 'nume' => 'q', 'eticheta' => 'Caută', 'valoare' => $q, 'placeholder' => \App\Support\CautareAdmin::indemn(),
        'tasta' => '/']],
]);
?>
<?php if ($q !== '' && mb_strlen($q) < \App\Support\CautareAdmin::MINIM): ?>
    <?php admin_componenta('mesaj', ['tip' => 'info', 'text' => 'Scrie cel puțin ' . \App\Support\CautareAdmin::MINIM . ' litere sau cifre.']); ?>
<?php elseif ($q !== '' && $numar === 0): ?>
    <?php admin_componenta('stare-goala', ['titlu' => 'Nimic găsit', 'text' => 'Încearcă doar o parte dintr-un cuvânt sau alt cuvânt.']); ?>
<?php endif; ?>
<div class="adm-cauta">
<?php foreach ($cautare['grupuri'] as $grup): ?>
    <section class="adm-cauta__grup" aria-labelledby="cauta-<?= adm_e($grup['sursa']) ?>">
        <h2 class="adm-cauta__titlu" id="cauta-<?= adm_e($grup['sursa']) ?>"><?= adm_e($grup['eticheta']) ?></h2>
        <?php if ($grup['incomplet']): ?>
            <p class="adm-cauta__nota">Căutarea în lista aceasta a durat prea mult și s-a oprit; încearcă un text mai exact.</p>
        <?php endif; ?>
        <ul class="adm-cauta__lista">
        <?php foreach ($grup['rezultate'] as $r): ?>
            <li><a class="adm-cauta__rezultat" href="<?= adm_e($r['url']) ?>"><span class="adm-cauta__nume"><?= adm_e($r['titlu']) ?></span><?php if ($r['detaliu'] !== ''): ?><span class="adm-cauta__detaliu"><?= adm_e($r['detaliu']) ?></span><?php endif; ?></a></li>
        <?php endforeach; ?>
        </ul>
    </section>
<?php endforeach; ?>
</div>
