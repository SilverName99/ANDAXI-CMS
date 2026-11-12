<?php
$settings = is_array($settings ?? null) ? $settings : [];
$stats = is_array($stats ?? null) ? $stats : ['total' => 0, 'paid' => 0, 'sum' => 0];
$stripeConfigured = !empty($stripeConfigured);
$val = static fn(string $k, string $def = ''): string => htmlspecialchars((string) ($settings[$k] ?? $def), ENT_QUOTES);
$on = static fn(string $k, string $def = '0'): bool => (string) ($settings[$k] ?? $def) === '1';
?>
<section class="panel">
    <h1>Setări donații</h1>
    <p>Configurează popup-ul „SUSȚINE" (donații prin Stripe: card + Google Pay). Cheile Stripe se preiau din <a href="/admin/settings/payments">Setări plăți</a>.</p>

    <?php if (!$stripeConfigured): ?>
        <div class="alert alert-warning" style="background:#fef3c7;border:1px solid #fde68a;color:#92400e;padding:12px 16px;border-radius:10px;margin-bottom:16px;">
            ⚠ Stripe nu este configurat încă. Completează cheile în <a href="/admin/settings/payments">Setări plăți</a>, altfel donațiile nu vor funcționa.
        </div>
    <?php endif; ?>

    <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:18px;">
        <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:14px 18px;">
            <div style="font-size:12px;color:#6b7280;">Donații plătite</div>
            <div style="font-size:22px;font-weight:800;"><?= (int) $stats['paid'] ?></div>
        </div>
        <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:14px 18px;">
            <div style="font-size:12px;color:#6b7280;">Total încasat</div>
            <div style="font-size:22px;font-weight:800;"><?= number_format(((int) $stats['sum']) / 100, 2, ',', '.') ?> <?= strtoupper($val('donations_currency', 'RON')) ?></div>
        </div>
        <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:14px 18px;">
            <div style="font-size:12px;color:#6b7280;">Total intenții</div>
            <div style="font-size:22px;font-weight:800;"><?= (int) $stats['total'] ?></div>
        </div>
    </div>

    <form method="post" action="/admin/settings/donations" class="form-grid">
        <?= \App\Support\AdminCsrf::camp() . "\n" ?>
        <div class="field" style="grid-column:1/-1;">
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="donations_enabled" value="1" <?= $on('donations_enabled') ? 'checked' : '' ?>>
                Activează donațiile (afișează sertarul „SUSȚINE")
            </label>
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="donations_monthly_enabled" value="1" <?= $on('donations_monthly_enabled', '1') ? 'checked' : '' ?>>
                Permite donații lunare (abonament)
            </label>
        </div>
        <div class="field" style="grid-column:1/-1;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:12px 14px;">
            <label style="display:flex;align-items:center;gap:8px;font-weight:600;">
                <input type="checkbox" name="donations_demo_enabled" value="1" <?= $on('donations_demo_enabled') ? 'checked' : '' ?>>
                🧪 Activează Exemplu (demo)
            </label>
            <small style="color:#9a3412;">Afișează întreg fluxul de donație (sertar → pași → plată → mulțumire) chiar și fără Stripe configurat, pentru a-l prezenta clientei. La pasul de plată apare un card demonstrativ, iar butonul „Donează" duce direct la mesajul de mulțumire — <strong>nu se procesează nicio plată reală</strong>. Dezactivează-l înainte de lansarea reală.</small>
        </div>

        <div class="field">
            <label>Monedă</label>
            <input type="text" name="donations_currency" maxlength="3" value="<?= $val('donations_currency', 'ron') ?>">
        </div>
        <div class="field">
            <label>Sumă minimă (<?= strtoupper($val('donations_currency', 'RON')) ?>)</label>
            <input type="number" min="1" step="1" name="donations_min" value="<?= $val('donations_min', '5') ?>">
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Sume sugerate (separate prin virgulă)</label>
            <input type="text" name="donations_amounts" value="<?= $val('donations_amounts', '1000,500,250,100,50,25') ?>" placeholder="1000,500,250,100,50,25">
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Destinații (una pe linie)</label>
            <textarea name="donations_designations" rows="4" placeholder="Unde e nevoie mai mult&#10;Cercetare&#10;Educația pacienților"><?= $val('donations_designations', "Unde e nevoie mai mult\nCercetare\nEducația pacienților\nEvenimente și conferințe") ?></textarea>
        </div>

        <div class="field" style="grid-column:1/-1;">
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="donations_cover_fees_enabled" value="1" <?= $on('donations_cover_fees_enabled', '1') ? 'checked' : '' ?>>
                Oferă opțiunea „Acoperă comisioanele" în popup
            </label>
        </div>
        <div class="field">
            <label>Comision procentual (%)</label>
            <input type="text" name="donations_cover_fees_percent" value="<?= $val('donations_cover_fees_percent', '2.9') ?>">
        </div>
        <div class="field">
            <label>Comision fix (<?= strtoupper($val('donations_currency', 'RON')) ?>)</label>
            <input type="text" name="donations_cover_fees_fixed" value="<?= $val('donations_cover_fees_fixed', '1.0') ?>">
        </div>

        <div class="field" style="grid-column:1/-1;">
            <label>Titlu campanie (în popup)</label>
            <input type="text" name="donations_campaign_title" value="<?= $val('donations_campaign_title', 'Susține misiunea noastră!') ?>">
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Text campanie</label>
            <textarea name="donations_campaign_text" rows="3"><?= $val('donations_campaign_text') ?></textarea>
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Imagine campanie (URL din Galerie)</label>
            <input type="text" name="donations_campaign_image" value="<?= $val('donations_campaign_image') ?>" placeholder="/uploads/gallery/donatie.jpg">
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Text mulțumire (după donație)</label>
            <textarea name="donations_thanks_text" rows="2"><?= $val('donations_thanks_text', 'Îți mulțumim din suflet pentru sprijin! Contribuția ta contează enorm.') ?></textarea>
        </div>

        <div style="grid-column:1/-1;">
            <button class="btn" type="submit">Salvează setările de donații</button>
        </div>
    </form>
</section>
