<?php
/** Detaliul unei cereri: datele completate, documentele încărcate, decizia și istoricul plăților. */
$verification = is_array($verification ?? null) ? $verification : [];
$answers = is_array($answers ?? null) ? $answers : [];
$documents = is_array($documents ?? null) ? $documents : [];
$activations = is_array($activations ?? null) ? $activations : [];

$verificationId = (int) ($verification['id'] ?? 0);
$status = (string) ($verification['status'] ?? '');
$fullName = trim(((string) ($verification['first_name'] ?? '')) . ' ' . ((string) ($verification['last_name'] ?? '')));
$activeUntil = trim((string) ($verification['account_active_until'] ?? ''));
$activeUntilTs = $activeUntil !== '' ? strtotime($activeUntil) : false;
$isDecided = in_array($status, ['approved', 'rejected'], true);

$formatDate = static function (mixed $value, string $format = 'd.m.Y H:i'): string {
    $raw = trim((string) $value);
    if ($raw === '') {
        return '—';
    }
    $timestamp = strtotime($raw);
    return $timestamp !== false ? date($format, $timestamp) : $raw;
};

$statusLabel = \App\Support\ProfessionAccounts::statusLabel($status);
?>
<section class="panel">
    <div class="section-head">
        <div>
            <h1>Cerere #<?= $verificationId ?></h1>
            <p>
                <?= htmlspecialchars($fullName !== '' ? $fullName : 'Utilizator', ENT_QUOTES) ?>
                &middot; <?= htmlspecialchars((string) ($verification['email'] ?? ''), ENT_QUOTES) ?>
                &middot; stare: <strong><?= htmlspecialchars($statusLabel, ENT_QUOTES) ?></strong>
            </p>
        </div>
        <div>
            <a class="btn btn-secondary" href="/admin/accounts/verifications">&larr; Înapoi la listă</a>
        </div>
    </div>

    <table class="table">
        <tbody>
        <tr>
            <th style="width:250px;">Cod de membru</th>
            <td><code><?= htmlspecialchars((string) ($verification['member_code'] ?? '—'), ENT_QUOTES) ?></code></td>
        </tr>
        <tr>
            <th>Tip de cont solicitat</th>
            <td><?= htmlspecialchars((string) ($verification['profession_name'] ?? '—'), ENT_QUOTES) ?></td>
        </tr>
        <tr>
            <th>Telefon</th>
            <td><?= htmlspecialchars((string) ($verification['phone'] ?? '—'), ENT_QUOTES) ?></td>
        </tr>
        <tr>
            <th>Cerere trimisă</th>
            <td><?= htmlspecialchars($formatDate($verification['created_at'] ?? ''), ENT_QUOTES) ?></td>
        </tr>
        <tr>
            <th>Email confirmat</th>
            <td><?= htmlspecialchars($formatDate($verification['confirmed_at'] ?? ''), ENT_QUOTES) ?></td>
        </tr>
        <tr>
            <th>Acord GDPR</th>
            <td>
                <?php if ((int) ($verification['gdpr_accepted'] ?? 0) === 1): ?>
                    Acceptat la <?= htmlspecialchars($formatDate($verification['gdpr_accepted_at'] ?? ''), ENT_QUOTES) ?>
                <?php else: ?>
                    Nebifat
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>IP / browser la trimitere</th>
            <td>
                <code><?= htmlspecialchars((string) ($verification['submitted_ip'] ?? '—'), ENT_QUOTES) ?></code><br>
                <small><?= htmlspecialchars((string) ($verification['submitted_user_agent'] ?? ''), ENT_QUOTES) ?></small>
            </td>
        </tr>
        <tr>
            <th>Cont activ până la</th>
            <td>
                <?php if ($activeUntilTs !== false): ?>
                    <?= htmlspecialchars(date('d.m.Y', $activeUntilTs), ENT_QUOTES) ?>
                    <?= $activeUntilTs < time() ? '<strong style="color:#a11d33;">(expirat)</strong>' : '' ?>
                <?php else: ?>
                    Neactivat (plata nu a fost efectuată)
                <?php endif; ?>
            </td>
        </tr>
        <?php if ($isDecided): ?>
        <tr>
            <th>Decizie</th>
            <td>
                <?= htmlspecialchars($statusLabel, ENT_QUOTES) ?>
                la <?= htmlspecialchars($formatDate($verification['reviewed_at'] ?? ''), ENT_QUOTES) ?>
                <?php if (trim((string) ($verification['review_note'] ?? '')) !== ''): ?>
                    <br><small>Notă: <?= htmlspecialchars((string) $verification['review_note'], ENT_QUOTES) ?></small>
                <?php endif; ?>
            </td>
        </tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<section class="panel">
    <h2>Date completate de utilizator</h2>
    <?php if ($answers === []): ?>
        <p>Nu au fost completate câmpuri suplimentare.</p>
    <?php else: ?>
        <table class="table">
            <thead>
            <tr>
                <th style="width:280px;">Câmp</th>
                <th>Valoare</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($answers as $key => $answer): ?>
                <?php
                $label = is_array($answer) ? (string) ($answer['label'] ?? $key) : (string) $key;
                $value = is_array($answer) ? (string) ($answer['value'] ?? '') : (string) $answer;
                $type = is_array($answer) ? (string) ($answer['type'] ?? 'text') : 'text';
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($label, ENT_QUOTES) ?></strong></td>
                    <td>
                        <?= nl2br(htmlspecialchars($value !== '' ? $value : '—', ENT_QUOTES)) ?>
                        <?php if ($type === 'file'): ?>
                            <small style="color:#64748b;"> (fișier — vezi mai jos)</small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Documente încărcate</h2>
    <p style="color:#64748b;">
        Fișierele sunt stocate în afara zonei publice și pot fi deschise doar de administratorii autentificați.
    </p>
    <?php if ($documents === []): ?>
        <p>Nu au fost încărcate documente.</p>
    <?php else: ?>
        <table class="table">
            <thead>
            <tr>
                <th>Document</th>
                <th>Fișier</th>
                <th>Tip</th>
                <th>Mărime</th>
                <th>Încărcat</th>
                <th>Acțiuni</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($documents as $document): ?>
                <?php $documentId = (int) ($document['id'] ?? 0); ?>
                <tr>
                    <td><?= htmlspecialchars((string) ($document['field_label'] ?? '—'), ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars((string) ($document['original_name'] ?? ''), ENT_QUOTES) ?></td>
                    <td><small><?= htmlspecialchars((string) ($document['mime_type'] ?? '—'), ENT_QUOTES) ?></small></td>
                    <td><?= htmlspecialchars(\App\Support\ProfessionAccounts::humanFileSize((int) ($document['file_size'] ?? 0)), ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars($formatDate($document['created_at'] ?? ''), ENT_QUOTES) ?></td>
                    <td>
                        <a class="btn" href="/admin/accounts/documents/<?= $documentId ?>" target="_blank" rel="noopener">
                            Deschide
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Decizie</h2>
    <p style="color:#64748b;">
        După aprobare, utilizatorul primește un email și poate plăti activarea din contul lui.
        Contul devine activ doar după efectuarea plății.
    </p>
    <form method="post" action="/admin/accounts/verifications/<?= $verificationId ?>/decision" class="form-grid">
        <?= \App\Support\AdminCsrf::camp() . "\n" ?>
        <div class="field" style="grid-column:1/-1;">
            <label>Notă pentru utilizator (opțional, apare în emailul de respingere)</label>
            <textarea name="review_note" rows="3"><?= htmlspecialchars((string) ($verification['review_note'] ?? ''), ENT_QUOTES) ?></textarea>
        </div>
        <div style="grid-column:1/-1;display:flex;gap:10px;">
            <button class="btn" type="submit" name="decision" value="approved"
                    onclick="return confirm('Aprobi această cerere?');">
                Aprobă cererea
            </button>
            <button class="btn btn-secondary" type="submit" name="decision" value="rejected"
                    onclick="return confirm('Respingi această cerere?');">
                Respinge cererea
            </button>
        </div>
    </form>
</section>

<?php if ($activations !== []): ?>
<section class="panel">
    <h2>Istoric plăți activare</h2>
    <table class="table">
        <thead>
        <tr>
            <th>ID</th>
            <th>Sumă</th>
            <th>Perioadă achitată</th>
            <th>Stare</th>
            <th>Perioadă</th>
            <th>Plătit la</th>
            <th>Stripe</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($activations as $activation): ?>
            <tr>
                <td><?= (int) ($activation['id'] ?? 0) ?></td>
                <td>
                    <?= number_format((float) ($activation['amount'] ?? 0), 2, ',', '.') ?>
                    <?= htmlspecialchars(strtoupper((string) ($activation['currency'] ?? 'RON')), ENT_QUOTES) ?>
                </td>
                <td><?= htmlspecialchars(\App\Support\ProfessionAccounts::durationLabel((int) ($activation['months'] ?? 12)), ENT_QUOTES) ?></td>
                <td><?= \App\Support\ProfessionAccounts::activationStatusBadge((string) ($activation['status'] ?? ''))
                    . \App\Support\ProfessionAccounts::refundBadge((int) ($activation['refunded_cents'] ?? 0), (float) ($activation['amount'] ?? 0),
                        (string) ($activation['currency'] ?? 'ron')) ?></td>
                <td>
                    <?= htmlspecialchars($formatDate($activation['period_start'] ?? '', 'd.m.Y'), ENT_QUOTES) ?>
                    &rarr;
                    <?= htmlspecialchars($formatDate($activation['period_end'] ?? '', 'd.m.Y'), ENT_QUOTES) ?>
                </td>
                <td><?= htmlspecialchars($formatDate($activation['paid_at'] ?? ''), ENT_QUOTES) ?></td>
                <td><small><code><?= htmlspecialchars((string) ($activation['stripe_session_id'] ?? '—'), ENT_QUOTES) ?></code></small></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>
