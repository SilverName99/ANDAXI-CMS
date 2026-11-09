<?php
/**
 * Lista donațiilor încasate prin Stripe.
 *
 * @var array<int, array<string, mixed>> $donations
 * @var array<string, int>               $totals
 * @var string                           $status
 * @var string                           $search
 */
$donations = is_array($donations ?? null) ? $donations : [];
$totals = is_array($totals ?? null) ? $totals : [];
$status = (string) ($status ?? '');
$search = (string) ($search ?? '');

$money = static function (int $cents, string $currency = 'RON'): string {
    return number_format($cents / 100, 2, ',', '.') . ' ' . strtoupper($currency);
};
$when = static function (?string $raw): string {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '—';
    }
    $ts = strtotime($raw);
    return $ts !== false ? date('d.m.Y H:i', $ts) : $raw;
};
$statusLabel = static function (string $s): array {
    return match ($s) {
        'paid' => ['Plătită', 'ok'],
        'failed' => ['Eșuată', 'off'],
        'pending' => ['În așteptare', 'wait'],
        default => [$s !== '' ? $s : '—', 'wait'],
    };
};
?>
<style>
.don-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-bottom:22px;}
.don-stat{background:#fff;border:1px solid #e9e2ef;border-radius:14px;padding:16px 18px;}
.don-stat strong{display:block;font-size:24px;line-height:1.2;color:#1f2937;}
.don-stat span{display:block;margin-top:4px;font-size:12.5px;color:#64748b;}
.don-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px;}
.don-filters input[type=text]{padding:9px 12px;border:1px solid #e2e8f0;border-radius:9px;font-size:14px;min-width:240px;}
.don-chip{display:inline-block;padding:7px 14px;border-radius:999px;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:13px;font-weight:600;text-decoration:none;}
.don-chip.is-active{background:#6f2b98;border-color:#6f2b98;color:#fff;}
.don-table{width:100%;border-collapse:collapse;font-size:14px;background:#fff;}
.don-table th{text-align:left;padding:11px 12px;border-bottom:1.5px solid #eee5f2;color:#64748b;font-size:12px;
  font-weight:700;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap;}
.don-table td{padding:12px;border-bottom:1px solid #f4eef7;color:#334155;vertical-align:middle;}
.don-table tr:last-child td{border-bottom:0;}
.don-tag{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;}
.don-tag.ok{background:#e7f6ee;color:#12734a;}
.don-tag.off{background:#fdeaec;color:#a11d33;}
.don-tag.wait{background:#fdf1e3;color:#9a5b12;}
.don-ref{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;color:#6f2b98;}
.don-sub{display:inline-block;margin-left:6px;padding:2px 7px;border-radius:999px;background:#f4ecf7;color:#6f2b98;font-size:11px;font-weight:700;}
.don-sub.is-off{background:#f1f5f9;color:#64748b;}
.don-empty{padding:34px 22px;text-align:center;color:#64748b;background:#fff;border-radius:14px;}
</style>

<section class="panel">
    <div class="section-head">
        <div>
            <h1>Donații</h1>
            <p>Donațiile încasate prin Stripe, unice și lunare.</p>
        </div>
        <div>
            <a class="btn btn-secondary" href="/admin/settings/donations">Setări donații</a>
        </div>
    </div>

    <div class="don-stats">
        <div class="don-stat">
            <strong><?= htmlspecialchars($money((int) ($totals['all_cents'] ?? 0)), ENT_QUOTES) ?></strong>
            <span>Total încasat</span>
        </div>
        <div class="don-stat">
            <strong><?= htmlspecialchars($money((int) ($totals['month_cents'] ?? 0)), ENT_QUOTES) ?></strong>
            <span>Luna curentă</span>
        </div>
        <div class="don-stat">
            <strong><?= (int) ($totals['count'] ?? 0) ?></strong>
            <span>Donații plătite</span>
        </div>
        <div class="don-stat">
            <strong><?= (int) ($totals['recurring'] ?? 0) ?></strong>
            <span>Donatori lunari activi</span>
        </div>
    </div>

    <form method="get" action="/admin/donations" class="don-filters">
        <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>"
               placeholder="Caută după email, nume sau referință">
        <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES) ?>">
        <button class="btn" type="submit">Caută</button>
        <?php
        $chips = ['' => 'Toate', 'paid' => 'Plătite', 'pending' => 'În așteptare', 'failed' => 'Eșuate'];
        foreach ($chips as $value => $label):
            $url = '/admin/donations?status=' . urlencode($value) . ($search !== '' ? '&q=' . urlencode($search) : '');
            ?>
            <a class="don-chip <?= $status === $value ? 'is-active' : '' ?>" href="<?= htmlspecialchars($url, ENT_QUOTES) ?>">
                <?= htmlspecialchars($label, ENT_QUOTES) ?>
            </a>
        <?php endforeach; ?>
    </form>

    <?php if ($donations === []): ?>
        <p class="don-empty">Nicio donație înregistrată<?= ($status !== '' || $search !== '') ? ' pentru filtrul ales' : '' ?>.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="don-table">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Donator</th>
                        <th>Sumă</th>
                        <th>Tip</th>
                        <th>Destinație</th>
                        <th>Status</th>
                        <th>Referință</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($donations as $donation): ?>
                    <?php
                    [$label, $tone] = $statusLabel((string) ($donation['status'] ?? ''));
                    $isMonthly = (string) ($donation['frequency'] ?? 'once') === 'monthly';
                    $cancelled = trim((string) ($donation['cancelled_at'] ?? '')) !== '';
                    $name = trim((string) ($donation['donor_name'] ?? ''));
                    $email = trim((string) ($donation['donor_email'] ?? ''));
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($when((string) ($donation['paid_at'] ?? $donation['created_at'] ?? '')), ENT_QUOTES) ?></td>
                        <td>
                            <?php if ($name !== ''): ?>
                                <strong><?= htmlspecialchars($name, ENT_QUOTES) ?></strong><br>
                            <?php endif; ?>
                            <span style="color:#64748b;font-size:13px;"><?= htmlspecialchars($email !== '' ? $email : '—', ENT_QUOTES) ?></span>
                        </td>
                        <td><strong><?= htmlspecialchars($money((int) ($donation['amount_cents'] ?? 0), (string) ($donation['currency'] ?? 'ron')), ENT_QUOTES) ?></strong></td>
                        <td>
                            <?= $isMonthly ? 'Lunară' : 'Unică' ?>
                            <?php if ($isMonthly): ?>
                                <span class="don-sub <?= $cancelled ? 'is-off' : '' ?>">
                                    <?= $cancelled ? 'oprită' : 'activă' ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars(trim((string) ($donation['designation'] ?? '')) ?: '—', ENT_QUOTES) ?></td>
                        <td><span class="don-tag <?= $tone ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></span></td>
                        <td><span class="don-ref"><?= htmlspecialchars((string) ($donation['reference'] ?? ''), ENT_QUOTES) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
