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
