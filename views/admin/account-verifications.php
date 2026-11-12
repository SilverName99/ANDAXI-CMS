<?php
/** Lista cererilor de cont profesional trimise de utilizatori. */
$verifications = is_array($verifications ?? null) ? $verifications : [];
$statusFilter = (string) ($statusFilter ?? 'pending');
$search = (string) ($search ?? '');
$statusCounts = is_array($statusCounts ?? null) ? $statusCounts : [];

$statusTabs = [
    'pending' => 'În așteptare',
    'approved' => 'Aprobate',
    'rejected' => 'Respinse',
    'awaiting_email' => 'Neconfirmate pe email',
    'all' => 'Toate',
];

$statusBadge = static function (string $status): string {
    return match ($status) {
        'approved' => '<span style="padding:3px 10px;border-radius:999px;background:#e7f6ee;color:#12734a;font-size:12px;font-weight:700;">Aprobat</span>',
        'rejected' => '<span style="padding:3px 10px;border-radius:999px;background:#fdeaec;color:#a11d33;font-size:12px;font-weight:700;">Respins</span>',
        'awaiting_email' => '<span style="padding:3px 10px;border-radius:999px;background:#eef1f6;color:#5a6472;font-size:12px;font-weight:700;">Neconfirmat</span>',
        default => '<span style="padding:3px 10px;border-radius:999px;background:#fdf1e3;color:#9a5b12;font-size:12px;font-weight:700;">În așteptare</span>',
    };
};
?>
<section class="panel">
    <div class="section-head">
        <div>
            <h1>Cereri conturi profesionale</h1>
            <p>
                Verifică datele și documentele trimise de utilizatori, apoi aprobă sau respinge cererea.
                După aprobare, utilizatorul poate plăti activarea contului pentru un an.
            </p>
        </div>
        <div>
            <a class="btn btn-secondary" href="/admin/accounts/professions">Tipuri de cont</a>
        </div>
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
        <?php foreach ($statusTabs as $key => $label): ?>
            <?php
            $count = $key === 'all' ? array_sum($statusCounts) : (int) ($statusCounts[$key] ?? 0);
            $isActive = $statusFilter === $key;
            ?>
            <a class="btn <?= $isActive ? '' : 'btn-secondary' ?>"
               href="/admin/accounts/verifications?status=<?= urlencode($key) ?>">
                <?= htmlspecialchars($label, ENT_QUOTES) ?> (<?= $count ?>)
            </a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="/admin/accounts/verifications" class="form-grid" style="margin-bottom:14px;">
        <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES) ?>">
        <div class="field">
            <label>Caută</label>
            <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>"
                   placeholder="nume, email sau tip de cont">
        </div>
        <div class="field">
            <label style="visibility:hidden;">Caută</label>
            <button class="btn" type="submit">Caută</button>
        </div>
    </form>

    <div class="admin-table-scroll">
    <table class="table">
        <thead>
        <tr>
            <th>ID</th>
            <th>Solicitant</th>
            <th>Tip de cont</th>
            <th>Documente</th>
            <th>Stare</th>
            <th style="white-space:nowrap;">Cont activ până la</th>
            <?php require __DIR__ . '/membri/activation-head.php'; ?>
            <th>Trimisă</th>
            <th>Acțiuni</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($verifications === []): ?>
            <tr><td colspan="13">Nu există cereri pentru filtrul selectat.</td></tr>
        <?php else: ?>
            <?php foreach ($verifications as $verification): ?>
                <?php
                $verificationId = (int) ($verification['id'] ?? 0);
                $fullName = trim(((string) ($verification['first_name'] ?? '')) . ' ' . ((string) ($verification['last_name'] ?? '')));
                $activeUntil = trim((string) ($verification['account_active_until'] ?? ''));
                $activeUntilTs = $activeUntil !== '' ? strtotime($activeUntil) : false;
                $createdAt = trim((string) ($verification['created_at'] ?? ''));
                $createdTs = $createdAt !== '' ? strtotime($createdAt) : false;
                ?>
                <tr>
                    <td><?= $verificationId ?></td>
                    <td>
                        <strong><?= htmlspecialchars($fullName !== '' ? $fullName : '-', ENT_QUOTES) ?></strong><br>
                        <small><?= htmlspecialchars((string) ($verification['email'] ?? ''), ENT_QUOTES) ?></small>
                    </td>
                    <td><?= htmlspecialchars((string) ($verification['profession_name'] ?? '-'), ENT_QUOTES) ?></td>
                    <td><?= (int) ($verification['documents_count'] ?? 0) ?></td>
                    <td><?= $statusBadge((string) ($verification['status'] ?? '')) ?></td>
                    <td style="white-space:nowrap;">
                        <?php if ($activeUntilTs !== false): ?>
                            <?= htmlspecialchars(date('d.m.Y', $activeUntilTs), ENT_QUOTES) ?>
                            <?php if ($activeUntilTs < time()): ?>
                                <small style="color:#a11d33;">(expirat)</small>
                            <?php endif; ?>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <?php $activationRow = $verification; require __DIR__ . '/membri/activation-cells.php'; ?>
                    <td style="white-space:nowrap;"><?= $createdTs !== false ? htmlspecialchars(date('d.m.Y H:i', $createdTs), ENT_QUOTES) : '-' ?></td>
                    <td>
                        <a class="btn" href="/admin/accounts/verifications/<?= $verificationId ?>">Deschide</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
