<?php
/**
 * Cele cinci celule cu ultima plată de activare, identice în „Cereri conturi”
 * și în „Listă utilizatori”. Așteaptă rândul curent în $activationRow, cu
 * aliasurile `activation_*` puse de ProfessionAccounts::latestActivationColumns().
 */
$activationRow = is_array($activationRow ?? null) ? $activationRow : [];

$activationDate = static function (mixed $value, string $format = 'd.m.Y'): string {
    $raw = trim((string) ($value ?? ''));
    if ($raw === '' || $raw === '0000-00-00 00:00:00') {
        return '';
    }
    $timestamp = strtotime($raw);

    return $timestamp !== false ? date($format, $timestamp) : '';
};

$activationStatus = trim((string) ($activationRow['activation_status'] ?? ''));
$activationPaidAt = $activationDate($activationRow['activation_paid_at'] ?? '', 'd.m.Y H:i');
$activationStart = $activationDate($activationRow['activation_period_start'] ?? '');
$activationEnd = $activationDate($activationRow['activation_period_end'] ?? '');
$activationMonths = (int) ($activationRow['activation_months'] ?? 0);
?>
<td style="white-space:nowrap;">
    <?php if ($activationStatus !== ''): ?>
        <?= number_format((float) ($activationRow['activation_amount'] ?? 0), 2, ',', '.') ?>
        <?= htmlspecialchars(strtoupper((string) ($activationRow['activation_currency'] ?? 'RON')), ENT_QUOTES) ?>
    <?php else: ?>
        &mdash;
    <?php endif; ?>
</td>
<td style="white-space:nowrap;">
    <?= $activationMonths > 0
        ? htmlspecialchars(\App\Support\ProfessionAccounts::durationLabel($activationMonths), ENT_QUOTES)
        : '&mdash;' ?>
</td>
<td><?= $activationStatus !== '' ? \App\Support\ProfessionAccounts::activationStatusBadge($activationStatus)
    . \App\Support\ProfessionAccounts::refundBadge((int) ($activationRow['activation_refunded_cents'] ?? 0),
        (float) ($activationRow['activation_amount'] ?? 0), (string) ($activationRow['activation_currency'] ?? 'ron')) : '&mdash;' ?></td>
<td style="white-space:nowrap;">
    <?php if ($activationStart !== '' || $activationEnd !== ''): ?>
        <?= htmlspecialchars($activationStart !== '' ? $activationStart : '?', ENT_QUOTES) ?>
        &rarr;
        <?= htmlspecialchars($activationEnd !== '' ? $activationEnd : '?', ENT_QUOTES) ?>
    <?php else: ?>
        &mdash;
    <?php endif; ?>
</td>
<td style="white-space:nowrap;"><?= $activationPaidAt !== '' ? htmlspecialchars($activationPaidAt, ENT_QUOTES) : '&mdash;' ?></td>
