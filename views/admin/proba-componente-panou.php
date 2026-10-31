<?php
/**
 * Detaliile unui rând de probă, încărcate în sertarul paginii /admin/componente (WP-31).
 *
 * @var array<string, mixed> $rand
 */
require_once __DIR__ . '/componente/_incarca.php';
$etichetaStare = ['noua' => 'Nouă', 'in_lucru' => 'În lucru', 'expediata' => 'Expediată', 'livrata' => 'Livrată'];
?>
<dl class="adm-detalii">
    <dt>Comanda</dt><dd class="adm-mono"><?= adm_e($rand['numar']) ?></dd>
    <dt>Client</dt><dd><?= adm_e($rand['client']) ?></dd>
    <dt>Status</dt><dd><span class="adm-stare"><?= adm_e($etichetaStare[$rand['stare']] ?? $rand['stare']) ?></span></dd>
    <dt>Total</dt><dd class="adm-mono"><?= number_format((float) $rand['total'], 2, ',', '.') ?> lei</dd>
    <dt>Data</dt><dd class="adm-mono"><?= adm_e(date('d.m.Y', (int) strtotime((string) $rand['data']))) ?></dd>
</dl>
<?php admin_componenta('mesaj', ['tip' => 'info', 'text' => 'Date inventate: pe paginile reale, aici vin detaliile încărcate de la adresa lor (de exemplu fereastra unei comenzi, în WP-33).']); ?>
<p><a class="btn btn-secondary" href="/admin/componente#tabel-proba">Un link din panou</a></p>
