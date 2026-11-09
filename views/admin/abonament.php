<?php
require_once __DIR__ . '/componente/_incarca.php';
// „Abonamentul tău” (faza D, WP-32): modulele pe care le are site-ul acum, din licența aplicată (AbonamentController).
// Doar modulele pornite sau care se opresc; niciun nume al unui modul pe care site-ul nu-l are.
$module = is_array($module ?? null) ? $module : [];
$nucleu = is_array($nucleu ?? null) ? $nucleu : ['nume' => 'Nucleu', 'descriere' => ''];
$e = static fn (mixed $text): string => htmlspecialchars((string) $text, ENT_QUOTES);
admin_componenta('antet-pagina', [
    'titlu' => 'Abonamentul tău',
    'nivel' => 1,
    'id' => 'abonament-antet',
    'subtitlu' => 'Modulele pe care le are site-ul acum, după licența primită de la ANDAXI.',
    'ajutor' => [
        'Lista vine din licența site-ului, aplicată pe server. Un modul care „se oprește” încă merge, ca să se termine ce a început '
            . '(comenzi, plăți); după data arătată nu mai pornește nimic nou.',
        'Pentru un modul în plus sau pentru întrebări despre abonament, scrie-ne la ANDAXI WEB SOLUTIONS.',
    ],
]);
?>
<?php if (!empty($licentaLipseste)): ?>
    <?php admin_componenta('mesaj', ['tip' => 'avertisment', 'titlu' => 'Licența lipsește',
        'text' => 'Site-ul funcționează doar cu funcțiile de bază. Vă rugăm să contactați ANDAXI WEB SOLUTIONS.']); ?>
<?php endif; ?>
<ul class="adm-abonament" aria-label="Modulele site-ului">
    <li class="adm-abonament__modul">
        <div>
            <h2 class="adm-abonament__nume"><?= $e($nucleu['nume']) ?> <span class="adm-stare adm-stare--trimis">Inclus</span></h2>
            <p class="adm-abonament__descriere"><?= $e($nucleu['descriere']) ?></p>
        </div>
    </li>
    <?php foreach ($module as $modul): ?>
        <li class="adm-abonament__modul<?= $modul['stare'] === \App\Support\Plugins::SE_OPRESTE ? ' adm-abonament__modul--se-opreste' : '' ?>" data-modul="<?= $e($modul['cheie']) ?>">
            <div>
                <h2 class="adm-abonament__nume">
                    <?= $e($modul['nume']) ?>
                    <?php if ($modul['stare'] === \App\Support\Plugins::SE_OPRESTE): ?>
                        <span class="adm-stare adm-stare--programat">Se oprește<?= $modul['din'] !== null ? ' din ' . $e(date('d.m.Y', (int) $modul['din'])) : '' ?></span>
                    <?php else: ?>
                        <span class="adm-stare adm-stare--trimis">Pornit</span>
                    <?php endif; ?>
                </h2>
                <p class="adm-abonament__descriere"><?= $e($modul['descriere']) ?></p>
            </div>
        </li>
    <?php endforeach; ?>
</ul>
<p class="adm-acasa__nota"><?= count($module) ?> <?= count($module) === 1 ? 'modul' : 'module' ?> în abonament, pe lângă funcțiile de bază.</p>
<?php if (is_array($sincronizare ?? null)): ?>
    <?php
    // Preluarea automată a licenței de la ANDAXI (App\Support\LicentaSincronizare; AbonamentController::sincronizare).
    $stareSinc = is_array($sincronizare['stare'] ?? null) ? $sincronizare['stare'] : null;
    $bunSinc = $stareSinc !== null && in_array($stareSinc['rezultat'], ['ok', 'la_zi'], true);
    $dataSinc = static function (?string $text): string {
        $moment = $text !== null && $text !== '' ? strtotime($text) : false;
        return $moment !== false ? date('d.m.Y H:i', $moment) : '—';
    };
    ?>
    <section class="adm-abonament__modul adm-abonament__sincronizare" id="abonament-sincronizare" aria-labelledby="abonament-sincronizare-titlu">
        <h2 id="abonament-sincronizare-titlu" class="adm-abonament__nume">Licența de la ANDAXI</h2>
        <?php if ($stareSinc === null): ?>
            <p class="adm-abonament__descriere">Licența se verifică automat la câteva minute; prima verificare n-a rulat încă.</p>
        <?php else: ?>
            <p class="adm-abonament__descriere" data-sincronizare="<?= $e($stareSinc['rezultat']) ?>">
                Ultima verificare: <strong><?= $e($dataSinc($stareSinc['la'])) ?></strong> —
                <?php if ($bunSinc): ?>
                    <span class="adm-stare adm-stare--trimis"><?= $stareSinc['rezultat'] === 'ok' ? 'Licență nouă aplicată' : 'La zi' ?></span>
                    (versiunea <?= (int) $stareSinc['versiune'] ?>).
                <?php else: ?>
                    <span class="adm-stare adm-stare--programat">Neactualizată</span>
                    site-ul merge mai departe cu licența de până acum<?= (int) $stareSinc['versiune_curenta'] > 0 ? ' (versiunea ' . (int) $stareSinc['versiune_curenta'] . ')' : '' ?>.
                <?php endif; ?>
            </p>
            <?php if (!$bunSinc): ?>
                <p class="adm-acasa__nota">Motivul: <?= $e($stareSinc['mesaj']) ?><?= $stareSinc['ultima_reusita'] !== null ? ' Ultima verificare reușită: ' . $e($dataSinc($stareSinc['ultima_reusita'])) . '.' : '' ?></p>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($sincronizare['configurata'])): ?>
            <form method="post" action="/admin/abonament/verifica">
                <?= \App\Support\AdminCsrf::camp() ?>
                <button type="submit" class="btn btn-secondary">Verifică acum</button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
