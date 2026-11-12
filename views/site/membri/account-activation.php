<?php
/**
 * Secțiunea „Activează contul” din /contul-meu.
 *
 * Plata cotizației se poate face pe 1, 3, 6 sau 12 luni, iar prețul fiecărei
 * perioade se calculează din cotizația configurată pe categorie. Selectorul
 * apare doar când contul a fost aprobat de un administrator și nu are deja
 * cotizația la zi; cât timp contul e activ, butonul rămâne inactiv și arată
 * cât mai este până la reînnoire.
 *
 * @var array<string, mixed>  $accountState      Din ProfessionAccounts::accountState()
 * @var array<string, string> $activationConfig  Texte editabile din admin
 */
$accountState = is_array($accountState ?? null) ? $accountState : [];
$activationConfig = is_array($activationConfig ?? null) ? $activationConfig : [];

$status = (string) ($accountState['verification_status'] ?? 'none');
$isApproved = (bool) ($accountState['is_approved'] ?? false);
$isActive = (bool) ($accountState['is_active'] ?? false);
$daysLeft = (int) ($accountState['days_left'] ?? 0);
$activeUntilLabel = (string) ($accountState['active_until_label'] ?? '');
$hasExpired = !$isActive && trim((string) ($accountState['active_until'] ?? '')) !== '';
$professionName = (string) ($accountState['profession_name'] ?? '');
$memberCode = trim((string) ($accountState['member_code'] ?? ''));
$durationOptions = is_array($accountState['duration_options'] ?? null) ? $accountState['duration_options'] : [];
$canReapply = !empty($accountState['can_reapply']);
$activationHistory = is_array($activationHistory ?? null) ? $activationHistory : [];
$reviewNote = trim((string) ($accountState['review_note'] ?? ''));

$title = trim((string) ($activationConfig['title'] ?? '')) ?: 'Activează contul';
// Cand cotizatia e platita, "Activeaza contul" nu mai descrie ce vede omul.
if ($isActive) {
    $title = 'Cotizația mea';
}
$buttonLabel = trim((string) ($activationConfig['button'] ?? '')) ?: 'Activează contul';
$renewLabel = trim((string) ($activationConfig['renew_button'] ?? '')) ?: 'Reactivează contul';

$activeText = str_replace(
    ['{{data_expirare}}', '{{zile_ramase}}'],
    [$activeUntilLabel, (string) $daysLeft],
    (string) ($activationConfig['active_text'] ?? '')
);

if ($isActive) {
    $badgeLabel = 'Cont activ';
    $badgeTone = 'is-active';
    $message = $activeText;
} elseif ($status === 'awaiting_email') {
    $badgeLabel = 'Confirmă emailul';
    $badgeTone = 'is-waiting';
    $message = (string) ($activationConfig['awaiting_email_text'] ?? '');
} elseif ($status === 'pending') {
    $badgeLabel = 'În verificare';
    $badgeTone = 'is-waiting';
    $message = (string) ($activationConfig['pending_text'] ?? '');
} elseif ($status === 'rejected') {
    $badgeLabel = 'Cerere respinsă';
    $badgeTone = 'is-rejected';
    $message = (string) ($activationConfig['rejected_text'] ?? '');
} elseif ($isApproved) {
    $badgeLabel = $hasExpired ? 'Cotizație expirată' : 'Aprobat — neactivat';
    $badgeTone = 'is-waiting';
    $message = (string) ($activationConfig['intro'] ?? '');
} else {
    $badgeLabel = 'Fără cerere';
    $badgeTone = 'is-waiting';
    $message = 'Nu ai trimis încă o cerere de cont de membru.';
}

$canPay = $isApproved && !$isActive && $durationOptions !== [];
// Perioada de un an e cea mai avantajoasă, deci o preselectăm când e disponibilă.
$defaultMonths = 0;
foreach ($durationOptions as $option) {
    if ((int) ($option['months'] ?? 0) === 12) {
        $defaultMonths = 12;
        break;
    }
}
if ($defaultMonths === 0 && $durationOptions !== []) {
    $defaultMonths = (int) ($durationOptions[array_key_last($durationOptions)]['months'] ?? 0);
}
?>
<style>
.account-activation{background:#fff;border:1px solid #efe3f2;border-radius:18px;padding:26px 26px 24px;}
.account-activation h2{margin:0 0 4px;font-size:22px;color:#1f2937;}
.account-activation-badge{display:inline-block;margin-bottom:14px;padding:5px 13px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;}
.account-activation-badge.is-active{background:#e7f6ee;color:#12734a;}
.account-activation-badge.is-waiting{background:#fdf1e3;color:#9a5b12;}
.account-activation-badge.is-rejected{background:#fdeaec;color:#a11d33;}
.account-activation-message{margin:0;color:#4b5563;font-size:15px;line-height:1.65;}
.account-activation-meta{margin:18px 0 0;padding:16px 18px;border-radius:12px;background:#faf6fc;display:grid;gap:9px;}
.account-activation-meta div{display:flex;justify-content:space-between;gap:16px;font-size:14px;color:#4b5563;}
.account-activation-meta strong{color:#1f2937;}
.account-activation-meta code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13.5px;color:#6f2b98;font-weight:700;}
.account-activation-actions{margin-top:22px;}
.account-activation-periods{display:grid;gap:10px;margin:0 0 18px;padding:0;border:0;}
.account-activation-periods legend{padding:0;margin:0 0 10px;font-size:13px;font-weight:700;color:#4b5563;}
.account-activation-period{display:flex;align-items:center;gap:12px;padding:13px 16px;border:1.5px solid #e5d9ec;border-radius:12px;cursor:pointer;transition:border-color .15s ease,background .15s ease;}
.account-activation-period:hover{border-color:#d9c2e6;background:#fdfbfe;}
.account-activation-period input{flex:0 0 auto;margin:0;}
.account-activation-period span{flex:1;font-size:14.5px;color:#4b5563;}
.account-activation-period strong{font-size:15px;color:#1f2937;}
.account-activation-period:has(input:checked){border-color:#6f2b98;background:#faf6fc;}
.account-activation-btn{display:inline-block;width:100%;box-sizing:border-box;text-align:center;padding:14px 20px;border:0;border-radius:12px;font-weight:700;font-size:15px;cursor:pointer;background:linear-gradient(135deg,#ea4968,#6f2b98);color:#fff;}
.account-activation-btn:hover{opacity:.94;}
.account-activation-btn[disabled]{background:#eceaf0;color:#8b8b9c;cursor:not-allowed;}
.account-activation-note{margin:10px 0 0;color:#8b8b9c;font-size:12.5px;line-height:1.5;}
.account-activation-history{margin:22px 0 0;padding:18px 0 0;border-top:1px solid #efe3f2;}
.account-activation-history h3{margin:0 0 12px;font-size:15px;color:#1f2937;}
.account-activation-history table{width:100%;border-collapse:collapse;font-size:13.5px;}
.account-activation-history th{text-align:left;padding:7px 8px;color:#8b8b9c;font-size:11.5px;font-weight:700;
  text-transform:uppercase;letter-spacing:.03em;border-bottom:1px solid #efe3f2;}
.account-activation-history td{padding:10px 8px;border-bottom:1px solid #f6f1f8;color:#4b5563;}
.account-activation-history tr:last-child td{border-bottom:0;}
.account-activation-receipt{color:#6f2b98;font-weight:700;text-decoration:none;}
.account-activation-receipt:hover{text-decoration:underline;}
.account-activation-note-box{margin:16px 0 0;padding:14px 16px;border-radius:12px;background:#fdf3f5;border:1px solid #f3c9d6;border-left:5px solid #ea4968;}
.account-activation-note-box strong{display:block;margin-bottom:4px;color:#a11d33;font-size:14px;}
.account-activation-note-box p{margin:0;color:#6b7280;font-size:13.5px;line-height:1.6;white-space:pre-line;}
</style>

<section class="account-activation">
    <h2><?= htmlspecialchars($title, ENT_QUOTES) ?></h2>
    <span class="account-activation-badge <?= htmlspecialchars($badgeTone, ENT_QUOTES) ?>">
        <?= htmlspecialchars($badgeLabel, ENT_QUOTES) ?>
    </span>

    <?php if (trim($message) !== ''): ?>
        <p class="account-activation-message"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <?php if ($status === 'rejected' && $reviewNote !== ''): ?>
        <div class="account-activation-note-box">
            <strong>Ce trebuie corectat</strong>
            <p><?= htmlspecialchars($reviewNote, ENT_QUOTES) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($memberCode !== '' || $professionName !== '' || $activeUntilLabel !== ''): ?>
        <div class="account-activation-meta">
            <?php if ($memberCode !== ''): ?>
                <div>
                    <span>Cod de membru</span>
                    <strong><code><?= htmlspecialchars($memberCode, ENT_QUOTES) ?></code></strong>
                </div>
            <?php endif; ?>
            <?php if ($professionName !== ''): ?>
                <div><span>Categorie</span><strong><?= htmlspecialchars($professionName, ENT_QUOTES) ?></strong></div>
            <?php endif; ?>
            <?php if ($activeUntilLabel !== ''): ?>
                <div>
                    <span><?= $isActive ? 'Cotizație plătită până la' : 'A expirat pe' ?></span>
                    <strong><?= htmlspecialchars($activeUntilLabel, ENT_QUOTES) ?></strong>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($activationHistory !== []): ?>
        <div class="account-activation-history">
            <h3>Cotizații plătite</h3>
            <table>
                <thead>
                    <tr><th>Data</th><th>Perioadă</th><th>Sumă</th><th>Chitanță</th></tr>
                </thead>
                <tbody>
                <?php foreach ($activationHistory as $entry): ?>
                    <?php
                    $paidAt = strtotime((string) ($entry['paid_at'] ?? ''));
                    $periodEnd = strtotime((string) ($entry['period_end'] ?? ''));
                    $entryMonths = (int) ($entry['months'] ?? 0);
                    $receipt = trim((string) ($entry['receipt_url'] ?? ''));
                    ?>
                    <tr>
                        <td><?= $paidAt !== false ? date('d.m.Y', $paidAt) : '—' ?></td>
                        <td>
                            <?= $entryMonths > 0 ? htmlspecialchars(\App\Support\ProfessionAccounts::durationLabel($entryMonths), ENT_QUOTES) : '—' ?>
                            <?php if ($periodEnd !== false): ?>
                                <span style="color:#8b8b9c;">· până la <?= date('d.m.Y', $periodEnd) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= number_format((float) ($entry['amount'] ?? 0), 2, ',', '.') ?>
                            <?= htmlspecialchars(strtoupper((string) ($entry['currency'] ?? 'RON')), ENT_QUOTES) ?></td>
                        <td>
                            <?php if ($receipt !== ''): ?>
                                <a class="account-activation-receipt" href="<?= htmlspecialchars($receipt, ENT_QUOTES) ?>"
                                   target="_blank" rel="noopener">Vezi chitanța</a>
                            <?php else: ?>
                                <span style="color:#a9a3b4;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="account-activation-actions">
        <?php if ($canPay): ?>
            <form method="post" action="/contul-meu/activare">
                <?= \App\Support\AdminCsrf::camp() . "\n" ?>
                <fieldset class="account-activation-periods">
                    <legend>Alege perioada</legend>
                    <?php foreach ($durationOptions as $option): ?>
                        <?php $months = (int) ($option['months'] ?? 0); ?>
                        <label class="account-activation-period">
                            <input type="radio" name="activation_months" value="<?= $months ?>"
                                   <?= $months === $defaultMonths ? 'checked' : '' ?> required>
                            <span><?= htmlspecialchars((string) ($option['label'] ?? ''), ENT_QUOTES) ?></span>
                            <strong><?= number_format((float) ($option['price'] ?? 0), 2, ',', '.') ?> lei</strong>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <button class="account-activation-btn" type="submit">
                    <?= htmlspecialchars($hasExpired ? $renewLabel : $buttonLabel, ENT_QUOTES) ?>
                </button>
            </form>
            <p class="account-activation-note">Plata se face securizat prin Stripe.</p>
        <?php elseif ($isActive): ?>
            <button class="account-activation-btn" type="button" disabled>
                <?php if ($daysLeft > 0): ?>
                    Reînnoire disponibilă peste <?= $daysLeft ?> <?= $daysLeft === 1 ? 'zi' : 'zile' ?>
                <?php else: ?>
                    Cont activ
                <?php endif; ?>
            </button>
            <p class="account-activation-note">
                Îți vom aminti pe email înainte de expirare, ca să nu pierzi accesul.
            </p>
        <?php elseif ($canReapply): ?>
            <a class="account-activation-btn" href="/contul-meu/cerere-noua">
                <?= $status === 'rejected' ? 'Trimite o cerere nouă' : 'Trimite o cerere de cont' ?>
            </a>
            <p class="account-activation-note">
                <?= $status === 'rejected'
                    ? 'Poți corecta documentele și trimite cererea din nou, fără să îți faci alt cont.'
                    : 'Alege categoria din care faci parte și încarcă documentele cerute.' ?>
            </p>
        <?php else: ?>
            <button class="account-activation-btn" type="button" disabled>
                <?= htmlspecialchars($buttonLabel, ENT_QUOTES) ?>
            </button>
            <?php if ($status === 'awaiting_email'): ?>
                <form method="post" action="/cont/retrimite-confirmarea" style="margin-top:12px;">
                    <?= \App\Support\AdminCsrf::camp() . "\n" ?>
                    <button class="account-activation-btn" type="submit"
                            style="background:#fff;color:#6f2b98;border:1.5px solid #e5d9ec;">
                        Retrimite emailul de confirmare
                    </button>
                </form>
                <p class="account-activation-note">
                    Confirmă adresa de email ca să îți putem verifica cererea.
                </p>
            <?php elseif ($status === 'pending'): ?>
                <p class="account-activation-note">
                    Butonul se activează imediat ce cererea ta este aprobată.
                </p>
            <?php elseif ($isApproved): ?>
                <p class="account-activation-note">
                    Cotizația nu este configurată pentru categoria ta. Te rugăm să ne contactezi.
                </p>
            <?php else: ?>
                <p class="account-activation-note">
                    <a href="/register">Trimite o cerere de cont</a> pentru a putea activa accesul.
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
