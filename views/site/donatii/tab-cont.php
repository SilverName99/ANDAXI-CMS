<?php
/**
 * Tab-ul „Donații” din contul donatorului (pluginul `donatii`, din vertij dc52498 … ca1120c; agățarea `cont.taburi`): donația
 * lunară activă, cu oprirea ei, și istoricul donațiilor, cu chitanțele. Același HTML ca panoul din contul vertij; în plus,
 * rândul donațiilor făcute fără cont cu adresa contului, care apar după ce adresa e confirmată (regula nucleului, G-M4).
 *
 * @var array<int, array<string, mixed>> $donationList
 * @var array<string, mixed>|null        $donationActive
 * @var int                              $donationTotalCents
 * @var int                              $donatiiAscunse
 */
$donationList = is_array($donationList ?? null) ? $donationList : [];
$donationActive = is_array($donationActive ?? null) ? $donationActive : null;
$donationTotalCents = (int) ($donationTotalCents ?? 0);
$donatiiAscunse = (int) ($donatiiAscunse ?? 0);
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$formatDate = static function (mixed $value, string $format = 'd.m.Y'): string {
    $raw = trim((string) $value);
    if ($raw === '') {
        return '—';
    }
    $ts = strtotime($raw);
    return $ts !== false ? date($format, $ts) : $raw;
};
?>
<style>
.acc-card{background:#fff;border:1px solid #efe3f2;border-radius:18px;padding:26px 26px 24px;box-shadow:0 12px 32px rgba(111,43,152,.07);}
.acc-card + .acc-card{margin-top:18px;}
.acc-card h2{margin:0 0 6px;font-family:"Playfair Display",Georgia,serif;font-size:23px;color:#1f2937;}
.acc-card h3{margin:22px 0 12px;font-size:15px;color:#1f2937;}
.acc-card h3:first-of-type{margin-top:14px;}
.acc-lead{margin:0 0 18px;color:#6b7280;font-size:14.5px;line-height:1.6;}
.acc-head{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:4px;}
.acc-btn{display:inline-block;border:0;cursor:pointer;padding:12px 22px;border-radius:12px;font-weight:700;font-size:14.5px;
  font-family:inherit;background:linear-gradient(135deg,#ea4968,#6f2b98);color:#fff;text-decoration:none;text-align:center;}
.acc-btn:hover{opacity:.94;}
.acc-btn--ghost{background:#fff;color:#6f2b98;border:1.5px solid #e5d9ec;}
.acc-btn--ghost:hover{background:#faf6fc;opacity:1;}
.acc-btn--danger{background:#fff;color:#a11d33;border:1.5px solid #f3c9d0;}
.acc-btn--danger:hover{background:#fdf3f5;opacity:1;}

.acc-table-wrap{overflow-x:auto;}
.acc-table{width:100%;border-collapse:collapse;font-size:14px;}
.acc-table th{text-align:left;padding:10px 12px;border-bottom:1.5px solid #efe3f2;color:#6b7280;font-size:12.5px;
  font-weight:700;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap;}
.acc-table td{padding:13px 12px;border-bottom:1px solid #f4eef7;color:#374151;vertical-align:middle;}
.acc-table tr:last-child td{border-bottom:0;}
.acc-tag{display:inline-block;padding:3px 10px;border-radius:999px;background:#f4ecf7;color:#6f2b98;font-size:12px;font-weight:700;}
.acc-tag.is-ok{background:#e7f6ee;color:#12734a;}
.acc-tag.is-off{background:#fdeaec;color:#a11d33;}

.acc-info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-top:14px;}
.acc-info{padding:14px 16px;border-radius:12px;background:#faf6fc;}
.acc-info span{display:block;font-size:12.5px;color:#6b7280;margin-bottom:3px;}
.acc-info strong{font-size:14.5px;color:#1f2937;word-break:break-word;}

.acc-donation-active{border-color:#e7d6f0;background:linear-gradient(180deg,#fdfbfe,#fff);}
</style>
<div class="acc-panel-donatii">
<?php if ($donatiiAscunse > 0): ?>
                <article class="acc-card">
                    <p class="acc-lead" style="margin:0 0 12px;">Ai donații mai vechi făcute cu această adresă de email. Confirmă adresa ca să le vezi aici.</p>
                    <form method="post" action="/contul-meu/confirmare-email" style="margin:0;">
                        <button class="acc-btn acc-btn--ghost" type="submit">Trimite linkul</button>
                    </form>
                </article>
<?php endif; ?>
<?php if ($donationList !== []): ?>
                <?php if ($donationActive !== null): ?>
                    <article class="acc-card acc-donation-active">
                        <div class="acc-head">
                            <div>
                                <h2>Donația ta lunară</h2>
                                <p class="acc-lead" style="margin-bottom:0;">
                                    Îți mulțumim. Sprijinul constant ne ajută cel mai mult.
                                </p>
                            </div>
                            <span class="acc-tag is-ok">Activă</span>
                        </div>

                        <div class="acc-info-grid">
                            <div class="acc-info">
                                <span>Sumă lunară</span>
                                <strong><?= number_format(((int) ($donationActive['amount_cents'] ?? 0)) / 100, 2, ',', '.') ?>
                                    <?= $esc(strtoupper((string) ($donationActive['currency'] ?? 'RON'))) ?></strong>
                            </div>
                            <?php if (trim((string) ($donationActive['designation'] ?? '')) !== ''): ?>
                                <div class="acc-info">
                                    <span>Destinație</span>
                                    <strong><?= $esc($donationActive['designation']) ?></strong>
                                </div>
                            <?php endif; ?>
                            <div class="acc-info">
                                <span>Prima donație</span>
                                <strong><?= $esc($formatDate($donationActive['created_at'] ?? '')) ?></strong>
                            </div>
                        </div>

                        <form method="post" action="/contul-meu/donatii/opreste" style="margin-top:20px;"
                              data-donation-stop>
                            <?= \App\Support\AdminCsrf::camp() . "\n" ?>
                            <button class="acc-btn acc-btn--danger" type="submit">Oprește donația lunară</button>
                        </form>
                        <p class="acc-lead" style="margin:10px 0 0;font-size:13px;">
                            Se oprește imediat și nu îți mai retragem nicio sumă. Donațiile de până acum rămân neschimbate.
                        </p>
                    </article>
                <?php endif; ?>

                <article class="acc-card">
                    <div class="acc-head">
                        <div>
                            <h2>Istoricul donațiilor</h2>
                            <p class="acc-lead" style="margin-bottom:0;">Toate donațiile făcute cu această adresă de email.</p>
                        </div>
                        <?php if ($donationTotalCents > 0): ?>
                            <span class="acc-tag is-ok">
                                Total <?= number_format($donationTotalCents / 100, 2, ',', '.') ?> lei
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="acc-table-wrap" style="margin-top:16px;">
                        <table class="acc-table">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Sumă</th>
                                    <th>Tip</th>
                                    <th>Destinație</th>
                                    <th>Status</th>
                                    <th>Chitanță</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($donationList as $donation): ?>
                                <?php
                                $dStatus = (string) ($donation['status'] ?? '');
                                $dMonthly = (string) ($donation['frequency'] ?? 'once') === 'monthly';
                                $dTone = $dStatus === 'paid' ? 'is-ok' : ($dStatus === 'failed' ? 'is-off' : '');
                                $dLabel = match ($dStatus) {
                                    'paid' => 'Plătită',
                                    'failed' => 'Eșuată',
                                    'pending' => 'În procesare',
                                    default => $dStatus,
                                };
                                ?>
                                <tr>
                                    <td><?= $esc($formatDate($donation['paid_at'] ?? $donation['created_at'] ?? '')) ?></td>
                                    <td><strong><?= number_format(((int) ($donation['amount_cents'] ?? 0)) / 100, 2, ',', '.') ?>
                                        <?= $esc(strtoupper((string) ($donation['currency'] ?? 'RON'))) ?></strong></td>
                                    <td><?= $dMonthly ? 'Lunară' : 'Unică' ?></td>
                                    <td><?= $esc(trim((string) ($donation['designation'] ?? '')) ?: '—') ?></td>
                                    <td><span class="acc-tag <?= $dTone ?>"><?= $esc($dLabel) ?></span></td>
                                    <td>
                                        <?php $dReceipt = trim((string) ($donation['receipt_url'] ?? '')); ?>
                                        <?php if (str_starts_with($dReceipt, 'https://')): ?>
                                            <a href="<?= $esc($dReceipt) ?>" target="_blank" rel="noopener"
                                               style="color:#6f2b98;font-weight:700;">Vezi</a>
                                        <?php else: ?>
                                            <span style="color:#a9a3b4;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
<?php endif; ?>
</div>
<script>
(function () {
    var donationStop = document.querySelector('[data-donation-stop]');
    if (donationStop) {
        donationStop.addEventListener('submit', function (event) {
            if (!window.confirm('Oprim donația lunară? Nu îți vom mai retrage nicio sumă.')) {
                event.preventDefault();
            }
        });
    }
})();
</script>
