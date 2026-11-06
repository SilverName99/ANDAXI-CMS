<?php

use App\Support\BunnyStorage;

/** @var array<string, string> $settings */
$settings = is_array($settings ?? null) ? $settings : [];
/** @var BunnyStorage|null $client */
$client = $client instanceof BunnyStorage ? $client : null;
$path = (string) ($path ?? '');
$breadcrumbs = is_array($breadcrumbs ?? null) ? $breadcrumbs : [];
$folders = is_array($folders ?? null) ? $folders : [];
$files = is_array($files ?? null) ? $files : [];
$galleryFolders = is_array($galleryFolders ?? null) ? $galleryFolders : [];
$locks = is_array($locks ?? null) ? $locks : [];
$currentAdminId = (int) ($currentAdminId ?? 0);
$canForceRelease = (bool) ($canForceRelease ?? false);
$searchQuery = trim((string) ($searchQuery ?? ''));
$error = (string) ($error ?? '');
$lockedFolder = is_array($lockedFolder ?? null) ? $lockedFolder : null;
$protectedFolders = is_array($protectedFolders ?? null) ? $protectedFolders : [];
$currentProtected = is_array($currentProtected ?? null) ? $currentProtected : null;
$openGuard = is_array($openGuard ?? null) ? $openGuard : null;
$isGeneralAdmin = (bool) ($isGeneralAdmin ?? false);
$lockWaitMinutes = (int) ($lockWaitMinutes ?? 0);
// Într-un folder cu parolă nu se arată adresele publice de pe CDN și nu se
// trimite nimic în Galerie: parola apără panoul, dar adresa aceea e deschisă
// oricui o cunoaște, iar Galeria ar publica fișierul pe site.
$inProtected = $openGuard !== null;
$enabled = (string) ($settings['bunny_enabled'] ?? '0') === '1';
$hasCdn = $client !== null && $client->hasCdnUrl();

$urlFor = static function (string $target) use ($searchQuery): string {
    $query = [];
    if ($target !== '') {
        $query['path'] = $target;
    }
    if ($searchQuery !== '') {
        $query['q'] = $searchQuery;
    }

    return '/admin/bunny' . ($query !== [] ? '?' . http_build_query($query) : '');
};
$parentPath = '';
if ($breadcrumbs !== []) {
    $parentPath = count($breadcrumbs) > 1 ? (string) $breadcrumbs[count($breadcrumbs) - 2]['path'] : '';
}
?>
<link rel="stylesheet" href="/assets/css/fisiere-cloud.css?v=<?= (int) (@filemtime(\App\Support\Paths::public('assets/css/fisiere-cloud.css')) ?: 1) ?>">
<?php ob_start(); ?>
<?php if ($enabled && $client !== null && $lockedFolder === null): ?>
    <button class="btn btn-secondary" type="button" id="bunny-open-folder-modal">+ Folder nou</button>
    <button class="btn" type="button" id="bunny-open-upload-modal">+ Urcă fișiere</button>
<?php endif; ?>
    <a class="btn btn-secondary" href="/admin/bunny/app">Aplicația de desktop</a>
<?php if (\App\Support\Auth::canAccessPath('/admin/settings/bunny')): ?>
    <a class="btn btn-secondary" href="/admin/settings/bunny">Setări</a>
<?php endif; ?>
<?php $antetActiuni = (string) ob_get_clean(); ?>
<?php
admin_componenta('antet-pagina', [
    'titlu' => 'bunny.net',
    'nivel' => 1,
    'subtitlu' => 'Folderele urcate în Storage Zone' . ($client !== null ? ' ' . $client->zoneName() : '') . ' — le poți folosi direct pe site.',
    'actiuni' => $antetActiuni,
]);
?>
<section class="panel">
    <?php if (!$enabled): ?>
        <div class="gallery-empty">
            <div class="icon">🐰</div>
            <h3>Integrarea bunny.net este oprită</h3>
            <p>
                <?php if (\App\Support\Auth::canAccessPath('/admin/settings/bunny')): ?>
                    Pornește-o din <a href="/admin/settings/bunny">Setări → bunny.net</a> ca să vezi aici folderele tale.
                <?php else: ?>
                    Cere-i Administratorului General să o pornească din Setări → bunny.net.
                <?php endif; ?>
            </p>
        </div>
    <?php elseif ($client === null || $error !== ''): ?>
        <div class="gallery-empty">
            <div class="icon">⚠️</div>
            <h3>Nu pot citi fișierele din Bunny</h3>
            <p><?= htmlspecialchars($error !== '' ? $error : 'Integrarea nu este configurată complet.', ENT_QUOTES) ?></p>
            <?php if (\App\Support\Auth::canAccessPath('/admin/settings/bunny')): ?>
                <p><a class="btn btn-secondary" href="/admin/settings/bunny">Verifică setările</a></p>
            <?php endif; ?>
        </div>
    <?php elseif ($lockedFolder !== null): ?>
        <?php
        $folderInchis = (string) $lockedFolder['folder_path'];
        $indiciu = trim((string) ($lockedFolder['hint'] ?? ''));
        $inapoi = str_contains($folderInchis, '/')
            ? substr($folderInchis, 0, (int) strrpos($folderInchis, '/'))
            : '';
        ?>
        <div class="bunny-gate">
            <div class="bunny-gate-icon">🔒</div>
            <h3>Folderul „<?= htmlspecialchars(basename($folderInchis), ENT_QUOTES) ?>” are parolă</h3>
            <p>Scrie parola ca să vezi ce e înăuntru. Rămâne deschis o oră de la ultima folosire.</p>
            <?php if ($indiciu !== ''): ?>
                <p class="bunny-gate-hint">Indiciu: <?= htmlspecialchars($indiciu, ENT_QUOTES) ?></p>
            <?php endif; ?>

            <?php if ($lockWaitMinutes > 0): ?>
                <p class="bunny-gate-wait">
                    Prea multe încercări greșite. Mai încearcă peste <?= $lockWaitMinutes ?>
                    <?= $lockWaitMinutes === 1 ? 'minut' : 'minute' ?>.
                </p>
            <?php else: ?>
                <form method="post" action="/admin/bunny/folders/unlock" class="bunny-gate-form">
                    <?= \App\Support\AdminCsrf::camp() . "\n" ?>
                    <input type="hidden" name="target" value="<?= htmlspecialchars($folderInchis, ENT_QUOTES) ?>">
                    <input type="hidden" name="path" value="<?= htmlspecialchars($path, ENT_QUOTES) ?>">
                    <input type="password" name="password" required autofocus autocomplete="off"
                           placeholder="Parola folderului" aria-label="Parola folderului">
                    <button class="btn" type="submit">Deschide</button>
                </form>
            <?php endif; ?>

            <p class="bunny-gate-back">
                <a href="<?= htmlspecialchars($urlFor($inapoi), ENT_QUOTES) ?>">↰ Înapoi la folderul de deasupra</a>
            </p>
        </div>

    <?php else: ?>

        <?php if ($openGuard !== null): ?>
            <div class="bunny-protected-bar">
                <span class="bunny-protected-icon">🔓</span>
                <div class="bunny-protected-text">
                    <strong>Folderul „<?= htmlspecialchars(basename((string) $openGuard['folder_path']), ENT_QUOTES) ?>” are parolă</strong>
                    <small>
                        E deschis pentru tine încă o oră. Parola apără panoul, nu și adresa publică de pe CDN:
                        cine cunoaște linkul unui fișier de aici tot îl poate deschide, de aceea linkurile publice
                        și trimiterea în Galerie sunt oprite înăuntru.
                    </small>
                </div>
                <div class="bunny-protected-actions">
                    <form method="post" action="/admin/bunny/folders/lock">
                        <?= \App\Support\AdminCsrf::camp() . "\n" ?>
                        <input type="hidden" name="target" value="<?= htmlspecialchars((string) $openGuard['folder_path'], ENT_QUOTES) ?>">
                        <button class="btn btn-secondary" type="submit">🔒 Închide la loc</button>
                    </form>
                    <button class="btn btn-secondary" type="button"
                            data-action="bunny-protect"
                            data-target="<?= htmlspecialchars((string) $openGuard['folder_path'], ENT_QUOTES) ?>"
                            data-name="<?= htmlspecialchars(basename((string) $openGuard['folder_path']), ENT_QUOTES) ?>"
                            data-has-password="1"
                            data-hint="<?= htmlspecialchars((string) ($openGuard['hint'] ?? ''), ENT_QUOTES) ?>">Schimbă parola</button>
                    <button class="btn btn-secondary" type="button"
                            data-action="bunny-unprotect"
                            data-target="<?= htmlspecialchars((string) $openGuard['folder_path'], ENT_QUOTES) ?>"
                            data-name="<?= htmlspecialchars(basename((string) $openGuard['folder_path']), ENT_QUOTES) ?>">Scoate parola</button>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$hasCdn): ?>
            <div class="bunny-notice">
                Zona nu are încă o adresă publică (Pull Zone). Fișierele se văd aici, dar nu pot fi folosite pe site
                până nu se completează adresa CDN<?= \App\Support\Auth::canAccessPath('/admin/settings/bunny')
                    ? ' în <a href="/admin/settings/bunny">setări</a>'
                    : ' de către Administratorul General' ?>.
            </div>
        <?php endif; ?>

        <nav class="bunny-breadcrumbs">
            <?php if ($path !== ''): ?>
                <a class="btn btn-secondary bunny-up" href="<?= htmlspecialchars($urlFor($parentPath), ENT_QUOTES) ?>">↰ Sus</a>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($urlFor(''), ENT_QUOTES) ?>">
                <?= htmlspecialchars($client->zoneName(), ENT_QUOTES) ?><?= $client->baseFolder() !== '' ? '/' . htmlspecialchars($client->baseFolder(), ENT_QUOTES) : '' ?>
            </a>
            <?php foreach ($breadcrumbs as $crumb): ?>
                <span>/</span>
                <a href="<?= htmlspecialchars($urlFor((string) $crumb['path']), ENT_QUOTES) ?>"><?= htmlspecialchars((string) $crumb['name'], ENT_QUOTES) ?></a>
            <?php endforeach; ?>
        </nav>

        <form method="get" action="/admin/bunny" class="gallery-search-form">
            <?php if ($path !== ''): ?>
                <input type="hidden" name="path" value="<?= htmlspecialchars($path, ENT_QUOTES) ?>">
            <?php endif; ?>
            <input type="text" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES) ?>" placeholder="Caută în folderul curent...">
            <button class="btn btn-secondary" type="submit">Caută</button>
            <?php if ($searchQuery !== ''): ?>
                <a class="btn btn-secondary" href="<?= htmlspecialchars('/admin/bunny' . ($path !== '' ? '?path=' . rawurlencode($path) : ''), ENT_QUOTES) ?>">Reset</a>
            <?php endif; ?>
        </form>

        <?php if ($folders !== []): ?>
            <div class="folder-shelf bunny-folders">
                <?php foreach ($folders as $folder): ?>
                    <?php
                    $folderPath = (string) $folder['path'];
                    $protejat = $protectedFolders[$folderPath] ?? null;
                    // Arhiva de versiuni n-are butoane: serverul refuză oricum
                    // să o redenumească sau să o șteargă, iar un buton care doar
                    // dă eroare e mai rău decât unul care lipsește.
                    $esteArhiva = \App\Support\FileVersions::isArchivePath($folderPath);
                    ?>
                    <article class="bunny-folder<?= $protejat !== null ? ' bunny-folder--locked' : '' ?>">
                        <a class="bunny-folder-open" href="<?= htmlspecialchars($urlFor($folderPath), ENT_QUOTES) ?>"
                           title="<?= htmlspecialchars((string) $folder['name'], ENT_QUOTES) ?>">
                            <span class="bunny-folder-icon"><?= $protejat !== null ? '🔒' : '📁' ?></span>
                            <span class="bunny-folder-name"><?= htmlspecialchars((string) $folder['name'], ENT_QUOTES) ?></span>
                        </a>
                        <div class="bunny-folder-tools">
                            <?php if ($esteArhiva): ?>
                                <span class="bunny-folder-tag">arhiva de versiuni</span>
                            <?php else: ?>
                                <button class="icon-btn<?= $protejat !== null ? ' bunny-folder-key' : '' ?>" type="button"
                                        data-action="bunny-protect"
                                        data-target="<?= htmlspecialchars($folderPath, ENT_QUOTES) ?>"
                                        data-name="<?= htmlspecialchars((string) $folder['name'], ENT_QUOTES) ?>"
                                        data-has-password="<?= $protejat !== null ? '1' : '0' ?>"
                                        data-hint="<?= htmlspecialchars((string) ($protejat['hint'] ?? ''), ENT_QUOTES) ?>"
                                        title="<?= $protejat !== null ? 'Schimbă parola folderului' : 'Pune parolă pe folder' ?>">🔑</button>
                                <button class="icon-btn" type="button"
                                        data-action="bunny-rename"
                                        data-type="folder"
                                        data-target="<?= htmlspecialchars($folderPath, ENT_QUOTES) ?>"
                                        data-name="<?= htmlspecialchars((string) $folder['name'], ENT_QUOTES) ?>"
                                        title="Redenumește folderul">✎</button>
                                <form method="post" action="/admin/bunny/delete" onsubmit="return confirm('Ștergi folderul „<?= htmlspecialchars((string) $folder['name'], ENT_QUOTES) ?>” din Bunny, cu tot ce conține? Operația nu poate fi anulată.');">
                                    <?= \App\Support\AdminCsrf::camp() . "\n" ?>
                                    <input type="hidden" name="path" value="<?= htmlspecialchars($path, ENT_QUOTES) ?>">
                                    <input type="hidden" name="target" value="<?= htmlspecialchars($folderPath, ENT_QUOTES) ?>">
                                    <input type="hidden" name="type" value="folder">
                                    <button class="icon-btn danger" type="submit" title="Șterge folderul">🗑</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($files === []): ?>
            <div class="gallery-empty">
                <div class="icon">🗂</div>
                <h3>Niciun fișier aici</h3>
                <p><?= $searchQuery !== '' ? 'Nu există rezultate pentru căutarea ta în acest folder.' : 'Urcă fișiere sau intră într-un folder.' ?></p>
            </div>
        <?php else: ?>
            <form method="post" action="/admin/bunny/import" id="bunny-import-form">
                <?= \App\Support\AdminCsrf::camp() . "\n" ?>
                <input type="hidden" name="path" value="<?= htmlspecialchars($path, ENT_QUOTES) ?>">
                <div class="bunny-toolbar">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" id="bunny-select-all">
                        Selectează toate
                    </label>
                    <div class="bunny-toolbar-actions">
                        <select name="folder_id">
                            <option value="">Galerie — fără folder</option>
                            <?php foreach ($galleryFolders as $galleryFolder): ?>
                                <option value="<?= (int) $galleryFolder['id'] ?>"><?= htmlspecialchars((string) $galleryFolder['name'], ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn" type="submit" <?= $hasCdn && !$inProtected ? '' : 'disabled' ?>
                                <?= $inProtected ? 'title="Fișierele dintr-un folder cu parolă nu se trimit în Galerie: acolo devin publice pe site."' : '' ?>>Trimite în Galerie</button>
                    </div>
                </div>

                <div class="gallery-grid bunny-files-grid">
                    <?php foreach ($files as $file): ?>
                        <?php
                        $fileUrl = (string) $file['url'];
                        $fileName = (string) $file['name'];
                        $mediaType = (string) $file['media_type'];
                        $lock = $locks[(string) $file['path']] ?? null;
                        $lockIsMine = $lock !== null && (int) ($lock['admin_id'] ?? 0) === $currentAdminId;
                        ?>
                        <article class="bunny-card<?= $lock !== null ? ($lockIsMine ? ' is-mine' : ' is-locked') : '' ?>">
                            <div class="bunny-card-thumb">
                                <?php if ($mediaType === 'video' && $fileUrl !== ''): ?>
                                    <video controls preload="metadata">
                                        <source src="<?= htmlspecialchars($fileUrl, ENT_QUOTES) ?>" type="video/mp4">
                                    </video>
                                <?php elseif ($mediaType === 'image' && $fileUrl !== ''): ?>
                                    <img src="<?= htmlspecialchars($fileUrl, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($fileName, ENT_QUOTES) ?>"
                                         loading="lazy"
                                         onerror="this.onerror=null;this.src='/assets/img/product-placeholder.svg';">
                                <?php else: ?>
                                    <span class="bunny-file-icon"><?= $mediaType === 'video' ? '🎬' : '📄' ?></span>
                                <?php endif; ?>

                                <label class="bunny-card-pick" title="<?= $mediaType === 'file' ? 'Galeria ține doar imagini și clipuri.' : 'Bifează pentru a trimite în Galerie' ?>">
                                    <?php if ($mediaType === 'file'): ?>
                                        <input type="checkbox" disabled>
                                        <span class="bunny-muted">Doar link</span>
                                    <?php else: ?>
                                        <input type="checkbox" name="paths[]" value="<?= htmlspecialchars((string) $file['path'], ENT_QUOTES) ?>" class="bunny-item-checkbox">
                                        <span>Selectează</span>
                                    <?php endif; ?>
                                </label>
                            </div>

                            <div class="bunny-card-body">
                                <strong title="<?= htmlspecialchars($fileName, ENT_QUOTES) ?>"><?= htmlspecialchars($fileName, ENT_QUOTES) ?></strong>
                                <span class="bunny-card-meta">
                                    <?= htmlspecialchars(BunnyStorage::formatSize((int) $file['size']), ENT_QUOTES) ?>
                                    · <?= htmlspecialchars(strtoupper($mediaType), ENT_QUOTES) ?>
                                </span>

                                <?php if ($lock !== null): ?>
                                    <div class="bunny-lock <?= $lockIsMine ? 'is-mine' : '' ?>">
                                        <strong>🔒 Deschis de <?= htmlspecialchars((string) $lock['owner_label'], ENT_QUOTES) ?><?= $lockIsMine ? ' (tu)' : '' ?></strong>
                                        <span>din <?= htmlspecialchars((string) $lock['locked_at_human'], ENT_QUOTES) ?></span>
                                        <?php if (trim((string) ($lock['note'] ?? '')) !== ''): ?>
                                            <em><?= htmlspecialchars((string) $lock['note'], ENT_QUOTES) ?></em>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="bunny-card-actions">
                                <?php if ($lock === null): ?>
                                    <button class="btn bunny-act-main" type="button"
                                            data-action="bunny-checkout"
                                            data-target="<?= htmlspecialchars((string) $file['path'], ENT_QUOTES) ?>"
                                            data-name="<?= htmlspecialchars($fileName, ENT_QUOTES) ?>">
                                        Preia pentru editare
                                    </button>
                                <?php elseif ($lockIsMine): ?>
                                    <button class="btn bunny-act-main" type="button"
                                            data-action="bunny-replace"
                                            data-target="<?= htmlspecialchars((string) $file['path'], ENT_QUOTES) ?>"
                                            data-name="<?= htmlspecialchars($fileName, ENT_QUOTES) ?>">
                                        Salvează versiunea nouă
                                    </button>
                                <?php elseif ($canForceRelease): ?>
                                    <button class="btn btn-secondary bunny-act-main" type="submit"
                                            formaction="/admin/bunny/release" formmethod="post"
                                            name="target" value="<?= htmlspecialchars((string) $file['path'], ENT_QUOTES) ?>"
                                            onclick="return confirm('Eliberezi forțat fișierul preluat de <?= htmlspecialchars((string) $lock['owner_label'], ENT_QUOTES) ?>? Dacă lucrează chiar acum, munca lui se poate pierde.');">
                                        Eliberează forțat
                                    </button>
                                <?php endif; ?>

                                <div class="bunny-act-row">
                                    <?php if ($lockIsMine): ?>
                                        <a class="btn btn-secondary" href="/admin/bunny/download?path=<?= rawurlencode((string) $file['path']) ?>">Descarcă</a>
                                        <button class="btn btn-secondary" type="submit"
                                                formaction="/admin/bunny/release" formmethod="post"
                                                name="target" value="<?= htmlspecialchars((string) $file['path'], ENT_QUOTES) ?>"
                                                onclick="return confirm('Renunți la fișier fără să salvezi?');">
                                            Renunță
                                        </button>
                                    <?php elseif ($fileUrl !== '' && !$inProtected): ?>
                                        <button class="btn btn-secondary" type="button" data-action="bunny-copy-url" data-url="<?= htmlspecialchars($fileUrl, ENT_QUOTES) ?>">Copiază link</button>
                                        <a class="btn btn-secondary" href="<?= htmlspecialchars($fileUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener">Deschide</a>
                                    <?php endif; ?>

                                    <?php if ($lock === null || $lockIsMine): ?>
                                        <button class="btn btn-secondary" type="button"
                                                data-action="bunny-rename"
                                                data-target="<?= htmlspecialchars((string) $file['path'], ENT_QUOTES) ?>"
                                                data-name="<?= htmlspecialchars($fileName, ENT_QUOTES) ?>">Redenumește</button>
                                    <?php endif; ?>

                                    <button class="icon-btn danger" type="submit"
                                            formaction="/admin/bunny/delete" formmethod="post"
                                            name="target" value="<?= htmlspecialchars((string) $file['path'], ENT_QUOTES) ?>"
                                            title="Șterge din Bunny"
                                            onclick="return confirm('Ștergi fișierul din Bunny? Linkurile care îl folosesc pe site nu vor mai funcționa.');"
                                    >🗑</button>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($enabled && $client !== null && $error === ''): ?>
<div class="modal-overlay" id="bunny-upload-modal">
    <div class="modal-card">
        <div class="modal-head">
            <h3>Urcă fișiere în Bunny</h3>
            <button type="button" class="icon-btn" data-close="bunny-upload-modal">✕</button>
        </div>
        <form method="post" action="/admin/bunny/upload" class="form-grid" enctype="multipart/form-data">
            <?= \App\Support\AdminCsrf::camp() . "\n" ?>
            <input type="hidden" name="path" value="<?= htmlspecialchars($path, ENT_QUOTES) ?>">
            <div class="field" style="grid-column:1/-1;">
                <label>Fișiere</label>
                <input type="file" name="files[]" multiple required>
                <small>Ajung în folderul <code><?= htmlspecialchars($path !== '' ? $path : '/', ENT_QUOTES) ?></code>. Numele se curăță de diacritice și spații.</small>
            </div>
            <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:8px;">
                <button class="btn btn-secondary" type="button" data-close="bunny-upload-modal">Renunță</button>
                <button class="btn" type="submit">Urcă</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="bunny-checkout-modal">
    <div class="modal-card" style="max-width:520px;">
        <div class="modal-head">
            <h3>Preia pentru editare</h3>
            <button type="button" class="icon-btn" data-close="bunny-checkout-modal">✕</button>
        </div>
        <form method="post" action="/admin/bunny/checkout" class="form-grid">
            <?= \App\Support\AdminCsrf::camp() . "\n" ?>
            <input type="hidden" name="path" value="<?= htmlspecialchars($path, ENT_QUOTES) ?>">
            <input type="hidden" name="target" id="bunny-checkout-target">
            <div class="field" style="grid-column:1/-1;">
                <p style="margin:0 0 4px;">Preiei <strong id="bunny-checkout-name"></strong>.</p>
                <p class="bunny-muted" style="margin:0;font-size:13px;">
                    Ceilalți administratori vor vedea că lucrezi tu la el și nu vor putea salva peste tine.
                    După ce termini, încarcă versiunea nouă sau renunță.
                </p>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>La ce lucrezi? (opțional)</label>
                <input type="text" name="note" maxlength="255" placeholder="ex: actualizez prețurile de toamnă">
                <small>Apare lângă lacăt, ca ceilalți să știe cât să aștepte.</small>
            </div>
            <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:8px;">
                <button class="btn btn-secondary" type="button" data-close="bunny-checkout-modal">Renunță</button>
                <button class="btn" type="submit">Preia fișierul</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="bunny-rename-modal">
    <div class="modal-card" style="max-width:520px;">
        <div class="modal-head">
            <h3 id="bunny-rename-title">Redenumește fișierul</h3>
            <button type="button" class="icon-btn" data-close="bunny-rename-modal">✕</button>
        </div>
        <form method="post" action="/admin/bunny/rename" class="form-grid">
            <?= \App\Support\AdminCsrf::camp() . "\n" ?>
            <input type="hidden" name="path" value="<?= htmlspecialchars($path, ENT_QUOTES) ?>">
            <input type="hidden" name="target" id="bunny-rename-target">
            <input type="hidden" name="type" id="bunny-rename-type" value="file">
            <div class="field" style="grid-column:1/-1;">
                <label>Nume nou</label>
                <input type="text" name="new_name" id="bunny-rename-input" required maxlength="200">
                <small id="bunny-rename-hint">
                    Se curăță de diacritice și spații. Extensia o alegi tu — dacă o schimbi,
                    fișierul se va deschide cu alt program. Linkurile vechi către fișier nu vor mai funcționa.
                </small>
            </div>
            <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:8px;">
                <button class="btn btn-secondary" type="button" data-close="bunny-rename-modal">Renunță</button>
                <button class="btn" type="submit">Redenumește</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="bunny-replace-modal">
    <div class="modal-card" style="max-width:520px;">
        <div class="modal-head">
            <h3>Încarcă versiunea nouă</h3>
            <button type="button" class="icon-btn" data-close="bunny-replace-modal">✕</button>
        </div>
        <form method="post" action="/admin/bunny/replace" class="form-grid" enctype="multipart/form-data">
            <?= \App\Support\AdminCsrf::camp() . "\n" ?>
            <input type="hidden" name="path" value="<?= htmlspecialchars($path, ENT_QUOTES) ?>">
            <input type="hidden" name="target" id="bunny-replace-target">
            <div class="field" style="grid-column:1/-1;">
                <label>Fișierul modificat pentru <strong id="bunny-replace-name"></strong></label>
                <input type="file" name="file" required>
                <small>Copia de acum rămâne în istoric, deci te poți întoarce la ea.</small>
            </div>
            <label class="field" style="grid-column:1/-1;">
                <span>
                    <input type="checkbox" name="keep_lock" value="1">
                    Păstrează fișierul preluat și după salvare
                </span>
                <small>Bifează dacă mai ai de lucru la el. Altfel se eliberează singur.</small>
            </label>
            <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:8px;">
                <button class="btn btn-secondary" type="button" data-close="bunny-replace-modal">Renunță</button>
                <button class="btn" type="submit">Salvează versiunea nouă</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="bunny-folder-modal">
    <div class="modal-card">
        <div class="modal-head">
            <h3>Folder nou în Bunny</h3>
            <button type="button" class="icon-btn" data-close="bunny-folder-modal">✕</button>
        </div>
        <form method="post" action="/admin/bunny/folders" class="form-grid">
            <?= \App\Support\AdminCsrf::camp() . "\n" ?>
            <input type="hidden" name="path" value="<?= htmlspecialchars($path, ENT_QUOTES) ?>">
            <div class="field" style="grid-column:1/-1;">
                <label>Nume folder</label>
                <input type="text" name="name" required placeholder="produse">
                <small>Se creează în <code><?= htmlspecialchars($path !== '' ? $path : '/', ENT_QUOTES) ?></code>.</small>
            </div>
            <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:8px;">
                <button class="btn btn-secondary" type="button" data-close="bunny-folder-modal">Renunță</button>
                <button class="btn" type="submit">Creează</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="bunny-protect-modal">
    <div class="modal-card" style="max-width:520px;">
        <div class="modal-head">
            <h3 id="bunny-protect-title">Pune parolă pe folder</h3>
            <button type="button" class="icon-btn" data-close="bunny-protect-modal">✕</button>
        </div>
        <form method="post" action="/admin/bunny/folders/protect" class="form-grid" autocomplete="off">
            <?= \App\Support\AdminCsrf::camp() . "\n" ?>
            <input type="hidden" name="path" value="<?= htmlspecialchars($path, ENT_QUOTES) ?>">
            <input type="hidden" name="target" id="bunny-protect-target">

            <div class="field" style="grid-column:1/-1;" id="bunny-protect-current-wrap" hidden>
                <label>Parola actuală</label>
                <input type="password" name="current_password" id="bunny-protect-current" autocomplete="off">
                <small id="bunny-protect-current-note">Fără ea nu se poate schimba parola unui folder.</small>
            </div>

            <div class="field" style="grid-column:1/-1;">
                <label>Parolă nouă</label>
                <input type="password" name="password" required minlength="6" autocomplete="new-password">
                <small>Cel puțin 6 caractere. O știu toți cei cărora le-o spui — nu e legată de contul nimănui.</small>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Încă o dată</label>
                <input type="password" name="password_confirm" required minlength="6" autocomplete="new-password">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Indiciu (opțional)</label>
                <input type="text" name="hint" id="bunny-protect-hint" maxlength="190" placeholder="ex: ca parola de la contabilitate">
                <small>Se vede de oricine ajunge la folder, deci să nu fie chiar parola.</small>
            </div>

            <p class="bunny-protect-note" style="grid-column:1/-1;">
                Parola oprește accesul din panou și din aplicația de desktop, pentru tot ce e în folder.
                Fișierul din bunny.net rămâne cum e: cine cunoaște adresa lui publică de pe CDN, sau are cheia
                zonei de storage, tot îl poate lua. E o barieră între colegi, nu criptare.
            </p>

            <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:8px;">
                <button class="btn btn-secondary" type="button" data-close="bunny-protect-modal">Renunță</button>
                <button class="btn" type="submit" id="bunny-protect-submit">Pune parola</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="bunny-unprotect-modal">
    <div class="modal-card" style="max-width:520px;">
        <div class="modal-head">
            <h3>Scoate parola folderului</h3>
            <button type="button" class="icon-btn" data-close="bunny-unprotect-modal">✕</button>
        </div>
        <form method="post" action="/admin/bunny/folders/unprotect" class="form-grid" autocomplete="off">
            <?= \App\Support\AdminCsrf::camp() . "\n" ?>
            <input type="hidden" name="path" value="<?= htmlspecialchars($path, ENT_QUOTES) ?>">
            <input type="hidden" name="target" id="bunny-unprotect-target">
            <p style="grid-column:1/-1;margin:0;">
                Folderul <strong id="bunny-unprotect-name"></strong> rămâne vizibil pentru oricine are acces la
                secțiunea de fișiere.
            </p>
            <div class="field" style="grid-column:1/-1;" id="bunny-unprotect-current-wrap">
                <label>Parola folderului</label>
                <input type="password" name="current_password" id="bunny-unprotect-current" autocomplete="off">
                <small id="bunny-unprotect-note">Scrie parola ca să confirmi că ai dreptul s-o scoți.</small>
            </div>
            <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:8px;">
                <button class="btn btn-secondary" type="button" data-close="bunny-unprotect-modal">Renunță</button>
                <button class="btn" type="submit">Scoate parola</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    var open = function (id) {
        var modal = document.getElementById(id);
        if (modal) { modal.classList.add('open'); }
    };
    var close = function (id) {
        var modal = document.getElementById(id);
        if (modal) { modal.classList.remove('open'); }
    };

    var uploadBtn = document.getElementById('bunny-open-upload-modal');
    if (uploadBtn) { uploadBtn.addEventListener('click', function () { open('bunny-upload-modal'); }); }
    var folderBtn = document.getElementById('bunny-open-folder-modal');
    if (folderBtn) { folderBtn.addEventListener('click', function () { open('bunny-folder-modal'); }); }

    document.querySelectorAll('[data-close]').forEach(function (btn) {
        btn.addEventListener('click', function () { close(btn.getAttribute('data-close')); });
    });
    document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) { overlay.classList.remove('open'); }
        });
    });

    var selectAll = document.getElementById('bunny-select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.bunny-item-checkbox').forEach(function (box) {
                box.checked = selectAll.checked;
            });
        });
    }

    document.querySelectorAll('[data-action="bunny-checkout"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('bunny-checkout-target').value = btn.getAttribute('data-target') || '';
            document.getElementById('bunny-checkout-name').textContent = btn.getAttribute('data-name') || '';
            open('bunny-checkout-modal');
        });
    });

    var general = <?= $isGeneralAdmin ? 'true' : 'false' ?>;

    document.querySelectorAll('[data-action="bunny-protect"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var arePAROLA = btn.getAttribute('data-has-password') === '1';
            var nume = btn.getAttribute('data-name') || '';
            document.getElementById('bunny-protect-target').value = btn.getAttribute('data-target') || '';
            document.getElementById('bunny-protect-hint').value = btn.getAttribute('data-hint') || '';
            document.getElementById('bunny-protect-title').textContent = arePAROLA
                ? 'Schimbă parola folderului „' + nume + '”'
                : 'Pune parolă pe folderul „' + nume + '”';
            document.getElementById('bunny-protect-submit').textContent = arePAROLA ? 'Schimbă parola' : 'Pune parola';

            // Parola veche se cere doar la schimbare. Administratorul General
            // poate reseta fără ea, ca o parolă uitată să nu însemne un folder
            // pierdut — dar resetarea lui se scrie în jurnal.
            var camp = document.getElementById('bunny-protect-current');
            var cutie = document.getElementById('bunny-protect-current-wrap');
            cutie.hidden = !arePAROLA;
            camp.required = arePAROLA && !general;
            camp.value = '';
            document.getElementById('bunny-protect-current-note').textContent = general
                ? 'Ca Administrator General poți lăsa gol, dar resetarea se scrie în jurnalul de activitate.'
                : 'Fără ea nu se poate schimba parola unui folder.';
            open('bunny-protect-modal');
        });
    });

    document.querySelectorAll('[data-action="bunny-unprotect"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('bunny-unprotect-target').value = btn.getAttribute('data-target') || '';
            document.getElementById('bunny-unprotect-name').textContent = btn.getAttribute('data-name') || '';
            var camp = document.getElementById('bunny-unprotect-current');
            camp.required = !general;
            camp.value = '';
            document.getElementById('bunny-unprotect-note').textContent = general
                ? 'Ca Administrator General poți lăsa gol, dar se scrie în jurnalul de activitate.'
                : 'Scrie parola ca să confirmi că ai dreptul s-o scoți.';
            open('bunny-unprotect-modal');
        });
    });

    document.querySelectorAll('[data-action="bunny-rename"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var isFolder = btn.getAttribute('data-type') === 'folder';
            document.getElementById('bunny-rename-target').value = btn.getAttribute('data-target') || '';
            document.getElementById('bunny-rename-input').value = btn.getAttribute('data-name') || '';
            document.getElementById('bunny-rename-type').value = isFolder ? 'folder' : 'file';
            document.getElementById('bunny-rename-title').textContent = isFolder
                ? 'Redenumește folderul'
                : 'Redenumește fișierul';
            document.getElementById('bunny-rename-hint').textContent = isFolder
                ? 'Se curăță de diacritice și spații. Bunny nu are redenumire de foldere, așa că '
                  + 'fiecare fișier dinăuntru se copiază sub calea nouă — la un folder mare durează. '
                  + 'Linkurile publice către fișierele din el se schimbă.'
                : 'Se curăță de diacritice și spații. Extensia o alegi tu — dacă o schimbi, '
                  + 'fișierul se va deschide cu alt program. Linkurile vechi către fișier nu vor mai funcționa.';
            open('bunny-rename-modal');
        });
    });

    document.querySelectorAll('[data-action="bunny-replace"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('bunny-replace-target').value = btn.getAttribute('data-target') || '';
            document.getElementById('bunny-replace-name').textContent = btn.getAttribute('data-name') || '';
            open('bunny-replace-modal');
        });
    });

    document.querySelectorAll('[data-action="bunny-copy-url"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var url = btn.getAttribute('data-url') || '';
            var done = function () {
                var previous = btn.textContent;
                btn.textContent = 'Copiat!';
                setTimeout(function () { btn.textContent = previous; }, 1400);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(done, function () { window.prompt('Copiază linkul:', url); });
                return;
            }
            window.prompt('Copiază linkul:', url);
        });
    });
})();
</script>
