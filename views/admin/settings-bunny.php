<?php
/** @var array<string, string> $settings */
$settings = is_array($settings ?? null) ? $settings : [];
$detectedZones = is_array($detectedZones ?? null) ? $detectedZones : [];
$regions = is_array($regions ?? null) ? $regions : [];

$enabled = (string) ($settings['bunny_enabled'] ?? '0') === '1';
$zone = (string) ($settings['bunny_storage_zone'] ?? '');
$password = (string) ($settings['bunny_storage_password'] ?? '');
$region = (string) ($settings['bunny_storage_region'] ?? '');
$cdnUrl = (string) ($settings['bunny_cdn_url'] ?? '');
$baseFolder = (string) ($settings['bunny_base_folder'] ?? '');
$apiKey = (string) ($settings['bunny_api_key'] ?? '');
$uploadTarget = (string) ($settings['bunny_upload_target'] ?? 'local');
$galleryFolder = (string) ($settings['bunny_gallery_folder'] ?? 'galerie');
$configured = $zone !== '' && $password !== '';
// Doar ultimele caractere, cât să recunoști cheia salvată fără a o expune.
$mask = static fn (string $value): string => strlen($value) > 6
    ? '••••••' . substr($value, -4)
    : str_repeat('•', max(4, strlen($value)));
?>
<link rel="stylesheet" href="/assets/css/fisiere-cloud.css?v=<?= (int) (@filemtime(\App\Support\Paths::public('assets/css/fisiere-cloud.css')) ?: 1) ?>">
<?php
admin_componenta('antet-pagina', [
    'titlu' => 'bunny.net',
    'nivel' => 1,
    'subtitlu' => 'Leagă site-ul de Storage Zone-ul din bunny.net, ca administratorii să folosească direct folderele urcate acolo.',
    'actiuni' => $configured ? '<a class="btn" href="/admin/bunny">Deschide fișierele din Bunny</a>' : '',
]);
?>
<section class="panel">
    <form method="post" action="/admin/settings/bunny" class="form-grid">
        <?= \App\Support\AdminCsrf::camp() . "\n" ?>
        <label class="field" style="grid-column:1 / -1;">
            <span>
                <input type="checkbox" name="bunny_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                Activează integrarea bunny.net
            </span>
            <small>Cât timp e oprită, secțiunea „bunny.net” din meniu nu citește nimic, iar fișierele urcate rămân pe server.</small>
        </label>

        <label class="field">
            <span>Nume Storage Zone</span>
            <input type="text" name="bunny_storage_zone" autocomplete="off" spellcheck="false"
                   placeholder="biovitality"
                   value="<?= htmlspecialchars($zone, ENT_QUOTES) ?>">
            <small>Exact cum apare în bunny.net → Storage → numele zonei.</small>
        </label>

        <label class="field">
            <span>Regiune storage</span>
            <select name="bunny_storage_region">
                <?php foreach ($regions as $code => $label): ?>
                    <option value="<?= htmlspecialchars((string) $code, ENT_QUOTES) ?>" <?= $region === (string) $code ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $label, ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small>Regiunea principală aleasă la crearea zonei (Main Storage Region).</small>
        </label>

        <label class="field" style="grid-column:1 / -1;">
            <span>Parolă Storage Zone (AccessKey)</span>
            <input type="text" name="bunny_storage_password" autocomplete="off" spellcheck="false"
                   placeholder="<?= $password !== '' ? 'Parolă salvată — lasă gol ca să o păstrezi' : 'ex: 8f2c…-…-…' ?>"
                   value="">
            <small>
                Se copiază din Storage Zone → FTP &amp; API Access → „Password”.
                <?php if ($password !== ''): ?>
                    Parola curentă: <code><?= htmlspecialchars($mask($password), ENT_QUOTES) ?></code>
                <?php endif; ?>
            </small>
        </label>

        <label class="field" style="grid-column:1 / -1;">
            <span>Adresă publică (Pull Zone / CDN)</span>
            <input type="text" name="bunny_cdn_url" autocomplete="off" spellcheck="false"
                   placeholder="https://biovitality.b-cdn.net"
                   value="<?= htmlspecialchars($cdnUrl, ENT_QUOTES) ?>">
            <small>
                Pull Zone-ul legat de zona de storage. Fără el, fișierele se văd în admin, dar nu au adresă publică
                pe care să o folosească site-ul.
            </small>
        </label>

        <label class="field">
            <span>Folder de bază (opțional)</span>
            <input type="text" name="bunny_base_folder" autocomplete="off" spellcheck="false"
                   placeholder="site"
                   value="<?= htmlspecialchars($baseFolder, ENT_QUOTES) ?>">
            <small>Dacă zona e folosită și de alte proiecte, limitează administratorii la acest subfolder.</small>
        </label>

        <label class="field">
            <span>Un fișier preluat se eliberează singur după (ore)</span>
            <input type="number" name="bunny_lock_expire_hours" min="1" max="720"
                   value="<?= (int) ($settings['bunny_lock_expire_hours'] ?? 24) ?>">
            <small>Ca un fișier uitat preluat să nu blocheze pe nimeni la nesfârșit.</small>
        </label>

        <label class="field">
            <span>Câte versiuni vechi se păstrează</span>
            <input type="number" name="bunny_versions_keep" min="0" max="100"
                   value="<?= (int) ($settings['bunny_versions_keep'] ?? 10) ?>">
            <small>La fiecare salvare, copia dinainte se mută în folderul <code>_versiuni</code>. 0 = fără istoric.</small>
        </label>

        <label class="field">
            <span>Timeout cereri (secunde)</span>
            <input type="number" name="bunny_timeout" min="5" max="60"
                   value="<?= (int) ($settings['bunny_timeout'] ?? 20) ?>">
        </label>

        <label class="field">
            <span>Unde ajung fișierele urcate din admin</span>
            <select name="bunny_upload_target">
                <option value="local" <?= $uploadTarget === 'local' ? 'selected' : '' ?>>Pe server (public/uploads)</option>
                <option value="bunny" <?= $uploadTarget === 'bunny' ? 'selected' : '' ?>>În bunny.net (CDN)</option>
            </select>
            <small>Schimbarea afectează doar fișierele urcate de acum înainte; cele vechi rămân unde sunt.</small>
        </label>

        <label class="field">
            <span>Folder Bunny pentru încărcările din admin</span>
            <input type="text" name="bunny_gallery_folder" autocomplete="off" spellcheck="false"
                   placeholder="galerie"
                   value="<?= htmlspecialchars($galleryFolder, ENT_QUOTES) ?>">
            <small>Se creează subfoldere după secțiune (galerie/produse, galerie/blog…).</small>
        </label>

        <label class="field" style="grid-column:1 / -1;">
            <span>Unde stă programul de desktop în zonă</span>
            <input type="text" name="bunny_app_download_path" autocomplete="off" spellcheck="false"
                   placeholder="aplicatie/BiovitalityFiles.exe"
                   value="<?= htmlspecialchars((string) ($settings['bunny_app_download_path'] ?? ''), ENT_QUOTES) ?>">
            <small>
                Urcă executabilul acolo, iar administratorii îl descarcă singuri din
                <a href="/admin/bunny/app">Aplicația de desktop</a>, fără cont de GitHub.
                Lasă gol ca să ascunzi butonul.
            </small>
        </label>

        <label class="field" style="grid-column:1 / -1;">
            <span>Cheie API cont bunny.net (opțional)</span>
            <input type="text" name="bunny_api_key" autocomplete="off" spellcheck="false"
                   placeholder="<?= $apiKey !== '' ? 'Cheie salvată — lasă gol ca să o păstrezi' : 'Account Settings → API → API Key' ?>"
                   value="">
            <small>
                Cu ea, butonul „Detectează zonele” completează singur numele zonei, parola și adresa CDN.
                <?php if ($apiKey !== ''): ?>
                    Cheia curentă: <code><?= htmlspecialchars($mask($apiKey), ENT_QUOTES) ?></code>
                <?php endif; ?>
            </small>
        </label>

        <div style="grid-column:1 / -1;display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn" type="submit" name="action" value="save">Salvează</button>
            <button class="btn btn-secondary" type="submit" name="action" value="test">Testează conexiunea</button>
            <button class="btn btn-secondary" type="submit" name="action" value="detect">Detectează zonele din cont</button>
        </div>
    </form>
</section>

<?php if ($detectedZones !== []): ?>
<section class="panel">
    <h2>Zone găsite în contul bunny.net</h2>
    <p>Alege zona pe care o folosește site-ul; datele ei intră direct în formularul de mai sus.</p>
    <table class="table">
        <thead>
            <tr>
                <th>Zonă</th>
                <th>Regiune</th>
                <th>Adresă publică</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($detectedZones as $detected): ?>
            <tr>
                <td><strong><?= htmlspecialchars((string) ($detected['name'] ?? ''), ENT_QUOTES) ?></strong></td>
                <td><?= htmlspecialchars((string) ($regions[(string) ($detected['region'] ?? '')] ?? '—'), ENT_QUOTES) ?></td>
                <td>
                    <?php $detectedUrl = (string) ($detected['cdn_url'] ?? ''); ?>
                    <?= $detectedUrl !== '' ? htmlspecialchars($detectedUrl, ENT_QUOTES) : '<span class="bunny-muted">fără Pull Zone</span>' ?>
                </td>
                <td style="text-align:right;">
                    <form method="post" action="/admin/settings/bunny/use-zone">
                        <?= \App\Support\AdminCsrf::camp() . "\n" ?>
                        <input type="hidden" name="zone_name" value="<?= htmlspecialchars((string) ($detected['name'] ?? ''), ENT_QUOTES) ?>">
                        <input type="hidden" name="zone_password" value="<?= htmlspecialchars((string) ($detected['password'] ?? ''), ENT_QUOTES) ?>">
                        <input type="hidden" name="zone_region" value="<?= htmlspecialchars((string) ($detected['region'] ?? ''), ENT_QUOTES) ?>">
                        <input type="hidden" name="zone_cdn_url" value="<?= htmlspecialchars($detectedUrl, ENT_QUOTES) ?>">
                        <button class="btn btn-secondary" type="submit">Folosește zona</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<section class="panel">
    <h2>Cum se leagă cele două conturi</h2>
    <ol>
        <li>În bunny.net: <strong>Storage</strong> → zona ta → <strong>FTP &amp; API Access</strong>; de acolo iei numele zonei și parola.</li>
        <li>Tot în bunny.net: <strong>CDN</strong> → creezi (sau deschizi) un Pull Zone cu originea pe zona de storage și copiezi adresa <code>…b-cdn.net</code>.</li>
        <li>Aici: completezi cele două, apeși <em>Testează conexiunea</em>, apoi <em>Salvează</em>.</li>
        <li>Administratorii deschid <a href="/admin/bunny">bunny.net</a> din meniu și văd folderele urcate; de acolo copiază adresa unui fișier sau îl trimit în Galerie.</li>
    </ol>
    <p class="bunny-muted">
        Accesul la fișiere se dă prin acest panou, nu prin conturi separate în bunny.net — administratorii site-ului nu au nevoie
        de cont bunny.net ca să folosească folderele.
    </p>
</section>
