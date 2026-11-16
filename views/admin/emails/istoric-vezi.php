<?php
/**
 * Corpul sertarului „Vezi” din Emailuri trimise (App\Http\Controllers\IstoricEmailController::vezi; layout-ul
 * admin/fragment). Emailul stă doar în iframe-ul `sandbox=""`, încărcat de la /admin/emails/history/continut (cu
 * `Content-Security-Policy: sandbox`): HTML-ul lui nu ajunge niciodată în pagina adminului.
 *
 * @var array{rand: ?array, stare: string, html: ?string, mesaj: string, marime: int, campanie: ?string} $previzualizare
 * @var int $idRand
 * @var string $textSimplu
 */
$rand = $previzualizare['rand'];
$stare = (string) $previzualizare['stare'];
$adresaContinut = '/admin/emails/history/continut?id=' . (int) $idRand;
?>
<div class="email-vezi" data-email-vezi-stare="<?= adm_e($stare) ?>">
<?php if (!is_array($rand)): ?>
    <?php admin_componenta('mesaj', ['tip' => 'eroare', 'titlu' => 'Email negăsit', 'text' => (string) $previzualizare['mesaj']]); ?>
<?php else: ?>
    <?php
    $statusRaw = strtolower(trim((string) ($rand['status'] ?? '')));
    $randuri = [
        'Data' => (string) ($rand['created_at'] ?? ''),
        'Tip email' => (string) ($rand['email_type'] ?? ''),
        'Destinatar' => (string) ($rand['recipient'] ?? ''),
        'Subiect' => (string) ($rand['subject'] ?? ''),
        'Status' => $statusRaw === 'resent' ? 'retrimis' : (string) ($rand['status'] ?? ''),
        'Sursă' => (string) ($rand['source'] ?? ''),
    ];
    if ((int) ($rand['order_id'] ?? 0) > 0 && \App\Support\Plugins::vizibil('magazin')) {
        // Numărul comenzii din momentul trimiterii (OrderMailer îl scrie în meta), altfel id-ul ei.
        $metaVezi = json_decode((string) ($rand['meta_json'] ?? ''), true);
        $nrVezi = is_array($metaVezi) ? trim((string) ($metaVezi['order_number'] ?? '')) : '';
        $randuri['Comandă'] = $nrVezi !== '' ? $nrVezi : '#' . (int) $rand['order_id'];
    }
    if (trim((string) ($rand['error_message'] ?? '')) !== '') {
        $randuri['Eroare'] = (string) $rand['error_message'];
    }
    ?>
    <dl class="email-vezi__date">
        <?php foreach ($randuri as $eticheta => $valoare): ?>
            <dt><?= adm_e($eticheta) ?></dt><dd><?= adm_e($valoare !== '' ? $valoare : '-') ?></dd>
        <?php endforeach; ?>
    </dl>
    <?php if (in_array($stare, ['pastrat', 'campanie'], true)): ?>
        <?php if ($stare === 'campanie'): ?>
            <?php admin_componenta('mesaj', ['tip' => 'info', 'text' => (string) $previzualizare['mesaj']]); ?>
        <?php else: ?>
            <p class="email-vezi__nota"><?= adm_e((string) $previzualizare['mesaj']) ?><?php if ((int) $previzualizare['marime'] > 0): ?>
                (<?= adm_e(number_format(max(1, (int) round((int) $previzualizare['marime'] / 1024)), 0, ',', '.')) ?> KB)<?php endif; ?></p>
        <?php endif; ?>
        <iframe class="email-vezi__cadru" src="<?= adm_e($adresaContinut) ?>" sandbox="" referrerpolicy="no-referrer"
                title="<?= adm_e('Emailul: ' . (string) ($rand['subject'] ?? '')) ?>"></iframe>
        <p class="email-vezi__nota">
            <a href="<?= adm_e($adresaContinut) ?>" target="_blank" rel="noopener noreferrer">Deschide emailul într-un tab nou</a>
            · scripturile și formularele din email nu rulează.
        </p>
        <?php if (trim($textSimplu) !== ''): ?>
            <details class="email-vezi__text">
                <summary>Varianta text (pentru programele de email fără HTML)</summary>
                <pre><?= adm_e($textSimplu) ?></pre>
            </details>
        <?php endif; ?>
    <?php else: ?>
        <?php admin_componenta('mesaj', ['tip' => $stare === 'inainte' ? 'info' : 'avertisment', 'text' => (string) $previzualizare['mesaj']]); ?>
    <?php endif; ?>
<?php endif; ?>
</div>
