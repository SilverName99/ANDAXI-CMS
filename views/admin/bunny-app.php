<?php
$settings = is_array($settings ?? null) ? $settings : [];
$tokens = is_array($tokens ?? null) ? $tokens : [];
$otherTokens = is_array($otherTokens ?? null) ? $otherTokens : [];
$maxTokens = (int) ($maxTokens ?? 10);
$freshToken = (string) ($freshToken ?? '');
$adminEmail = (string) ($adminEmail ?? '');
$apiBaseUrl = rtrim((string) ($apiBaseUrl ?? ''), '/');
$storageOn = (string) ($settings['bunny_enabled'] ?? '0') === '1';
$appFile = is_array($appFile ?? null) ? $appFile : null;
$appPath = (string) ($appPath ?? '');
$canManageSettings = \App\Support\Auth::canAccessPath('/admin/settings/bunny');
$humanDate = static function (mixed $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return 'niciodată';
    }
    $time = strtotime($value);
    return $time !== false ? date('d.m.Y H:i', $time) : $value;
};
?>
<link rel="stylesheet" href="/assets/css/fisiere-cloud.css?v=<?= (int) (@filemtime(\App\Support\Paths::public('assets/css/fisiere-cloud.css')) ?: 1) ?>">
<?php
admin_componenta('antet-pagina', [
    'titlu' => 'Aplicația de desktop',
    'nivel' => 1,
    'subtitlu' => 'Deschide fișierele de lucru direct de pe calculatorul tău, cu preluare automată.',
    'actiuni' => '<a class="btn btn-secondary" href="/admin/bunny">Înapoi la fișiere</a>',
]);
?>
<section class="panel">
    <?php if ($appFile !== null): ?>
        <div class="bunny-app-download">
            <div class="bunny-app-download-info">
                <strong>Programul pentru calculatorul tău</strong>
                <span>
                    <?= htmlspecialchars((string) ($appFile['name'] ?? 'BiovitalityFiles.exe'), ENT_QUOTES) ?>
                    · <?= htmlspecialchars(\App\Support\BunnyStorage::formatSize((int) ($appFile['size'] ?? 0)), ENT_QUOTES) ?>
                    <?php
                    $appUpdated = strtotime((string) ($appFile['updated_at'] ?? ''));
                    if ($appUpdated !== false):
                        ?>
                        · pus la <?= htmlspecialchars(date('d.m.Y H:i', $appUpdated), ENT_QUOTES) ?>
                    <?php endif; ?>
                </span>
                <small>
                    Se descarcă din contul tău de admin, nu de pe GitHub. Windows va afișa un avertisment
                    la prima pornire („Windows protected your PC”) — apeși <em>More info</em> → <em>Run anyway</em>.
                </small>
            </div>
            <a class="btn" href="/admin/bunny/download?path=<?= rawurlencode($appPath) ?>">Descarcă aplicația</a>
        </div>
    <?php elseif ($appPath !== '' && $storageOn): ?>
        <div class="bunny-notice">
            Programul nu este încă urcat în bunny.net, la <code><?= htmlspecialchars($appPath, ENT_QUOTES) ?></code>.
            <?php if ($canManageSettings): ?>
                Urcă-l din <a href="/admin/bunny">pagina de fișiere</a> și butonul de descărcare apare aici singur.
            <?php else: ?>
                Cere-i Administratorului General să-l urce.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!$storageOn): ?>
        <div class="bunny-notice">
            Integrarea bunny.net este oprită, deci aplicația nu va găsi niciun fișier.
            Cheile se pot genera oricum, dar vor funcționa abia după pornirea integrării.
        </div>
    <?php endif; ?>

    <?php if ($freshToken !== ''): ?>
        <div class="bunny-token-fresh">
            <strong>Cheia ta nouă — copiaz-o acum</strong>
            <p>Nu se mai poate vedea după ce pleci de pe pagină. Dacă o pierzi, generezi alta și o retragi pe asta.</p>
            <div class="bunny-token-value">
                <code id="bunny-fresh-token"><?= htmlspecialchars($freshToken, ENT_QUOTES) ?></code>
                <button class="btn" type="button" id="bunny-copy-token" data-token="<?= htmlspecialchars($freshToken, ENT_QUOTES) ?>">Copiază</button>
            </div>
        </div>
    <?php endif; ?>

    <h2>Cheile tale</h2>
    <p class="bunny-muted">
        Câte una pentru fiecare calculator pe care instalezi aplicația. Cheia poartă drepturile contului tău
        (<?= htmlspecialchars($adminEmail !== '' ? $adminEmail : 'contul curent', ENT_QUOTES) ?>) și poate fi retrasă oricând.
        Cel mult <?= $maxTokens ?> chei active deodată.
    </p>

    <?php if ($tokens === []): ?>
        <div class="gallery-empty" style="min-height:150px;">
            <div class="icon">🔑</div>
            <h3>Nicio cheie încă</h3>
            <p>Generează una mai jos, apoi lipește-o în aplicație la prima pornire.</p>
        </div>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Calculator</th>
                    <th>Creată</th>
                    <th>Folosită ultima dată</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tokens as $token): ?>
                <tr>
                    <td><strong><?= htmlspecialchars((string) ($token['name'] ?? ''), ENT_QUOTES) ?></strong></td>
                    <td><?= htmlspecialchars($humanDate($token['created_at'] ?? ''), ENT_QUOTES) ?></td>
                    <td>
                        <?= htmlspecialchars($humanDate($token['last_used_at'] ?? ''), ENT_QUOTES) ?>
                        <?php if (trim((string) ($token['last_used_ip'] ?? '')) !== ''): ?>
                            <span class="bunny-muted">(<?= htmlspecialchars((string) $token['last_used_ip'], ENT_QUOTES) ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <form method="post" action="/admin/bunny/app/tokens/revoke"
                              onsubmit="return confirm('Retragi cheia? Aplicația de pe acel calculator nu va mai avea acces.');">
                            <?= \App\Support\AdminCsrf::camp() . "\n" ?>
                            <input type="hidden" name="token_id" value="<?= (int) ($token['id'] ?? 0) ?>">
                            <button class="btn btn-secondary" type="submit">Retrage</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($otherTokens !== []): ?>
        <h2 style="margin-top:24px;">Cheile celorlalți administratori</h2>
        <p class="bunny-muted">
            Le vezi ca Administrator General: dacă un calculator s-a pierdut sau un coleg a plecat, retragi cheia de aici.
        </p>
        <table class="table">
            <thead>
                <tr>
                    <th>Administrator</th>
                    <th>Calculator</th>
                    <th>Creată</th>
                    <th>Folosită ultima dată</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($otherTokens as $token): ?>
                <tr>
                    <td><?= htmlspecialchars((string) ($token['email'] ?? ('#' . (int) ($token['admin_id'] ?? 0))), ENT_QUOTES) ?></td>
                    <td><strong><?= htmlspecialchars((string) ($token['name'] ?? ''), ENT_QUOTES) ?></strong></td>
                    <td><?= htmlspecialchars($humanDate($token['created_at'] ?? ''), ENT_QUOTES) ?></td>
                    <td>
                        <?= htmlspecialchars($humanDate($token['last_used_at'] ?? ''), ENT_QUOTES) ?>
                        <?php if (trim((string) ($token['last_used_ip'] ?? '')) !== ''): ?>
                            <span class="bunny-muted">(<?= htmlspecialchars((string) $token['last_used_ip'], ENT_QUOTES) ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <form method="post" action="/admin/bunny/app/tokens/revoke"
                              onsubmit="return confirm('Retragi cheia colegului? Aplicația de pe acel calculator nu va mai avea acces.');">
                            <?= \App\Support\AdminCsrf::camp() . "\n" ?>
                            <input type="hidden" name="token_id" value="<?= (int) ($token['id'] ?? 0) ?>">
                            <button class="btn btn-secondary" type="submit">Retrage</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <form method="post" action="/admin/bunny/app/tokens" class="form-grid" style="margin-top:16px;">
        <?= \App\Support\AdminCsrf::camp() . "\n" ?>
        <div class="field" style="grid-column:1/-1;">
            <label>Nume calculator</label>
            <input type="text" name="name" maxlength="120" placeholder="ex: Laptop birou" required>
            <small>Doar ca să știi de unde vine fiecare cheie, când vrei să retragi una.</small>
        </div>
        <div style="grid-column:1/-1;">
            <button class="btn" type="submit">Generează cheie nouă</button>
        </div>
    </form>
</section>

<section class="panel">
    <h2>Cum funcționează</h2>
    <ol>
        <li>Instalezi aplicația pe calculator și lipești cheia de mai sus, o singură dată.</li>
        <li>Aplicația îți arată fișierele din bunny.net și cine are ce preluat.</li>
        <li>Dai dublu-clic pe un fișier: aplicația îl preia pe numele tău, îl descarcă și îl deschide în Excel (sau în programul potrivit).</li>
        <li>Când salvezi, aplicația urcă versiunea nouă și eliberează fișierul.</li>
    </ol>
    <p class="bunny-muted">
        Cât timp îl ai preluat, ceilalți văd „🔒 În lucru la <?= htmlspecialchars($adminEmail !== '' ? $adminEmail : 'tine', ENT_QUOTES) ?>”
        și nu pot salva peste tine — nici din aplicație, nici din admin.
    </p>

    <h3>Când apare o versiune nouă</h3>
    <p>
        Aplicația nu se actualizează singură. Când apare una nouă, o iei de sus și o pui peste cea veche
        (închide-o întâi, dacă rulează). Nu-ți va cere din nou adresa și cheia — acelea rămân salvate.
        Versiunea pe care o ai o vezi în titlul ferestrei aplicației.
    </p>

    <h3>Fără aplicație</h3>
    <p>
        Același lucru se poate face și din <a href="/admin/bunny">pagina de fișiere</a>: „Preia pentru editare”, descarci,
        modifici, apoi „Încarcă versiunea nouă”. Aplicația doar scurtează pașii.
    </p>

    <h3>Detalii tehnice</h3>
    <p class="bunny-muted">Aplicația vorbește cu adresa de mai jos. Datele de bunny.net rămân pe server, nu ajung niciodată în aplicație.</p>
    <pre class="bunny-code"><?= htmlspecialchars($apiBaseUrl !== '' ? $apiBaseUrl . '/api/desktop' : '/api/desktop', ENT_QUOTES) ?></pre>
</section>

<script>
(function () {
    var btn = document.getElementById('bunny-copy-token');
    if (!btn) { return; }
    btn.addEventListener('click', function () {
        var value = btn.getAttribute('data-token') || '';
        var done = function () {
            var previous = btn.textContent;
            btn.textContent = 'Copiat!';
            setTimeout(function () { btn.textContent = previous; }, 1400);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(done, function () { window.prompt('Copiază cheia:', value); });
            return;
        }
        window.prompt('Copiază cheia:', value);
    });
})();
</script>
