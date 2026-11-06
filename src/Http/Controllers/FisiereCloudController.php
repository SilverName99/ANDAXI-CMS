<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AdminActivityLog;
use App\Support\AdminCsrf;
use App\Support\AppTokens;
use App\Support\Auth;
use App\Support\BunnyStorage;
use App\Support\FileLocks;
use App\Support\FileVersions;
use App\Support\Flash;
use App\Support\FolderPasswords;
use App\Support\Settings;
use App\Support\View;
use PDO;
use Throwable;

/**
 * Pluginul `fisiere_cloud` în admin (din Biovitality f5486ee … f9b4d78, metodele `bunny*` ale lui AdminController la d95598e;
 * docs/PLUGINURI.md, „Fișiere în cloud”): Conținut → bunny.net (`/admin/bunny`: folderele și fișierele zonei de storage, urcarea,
 * folderele noi, ștergerea, redenumirea, preluarea pentru editare cu lacăt, versiunea nouă cu copia veche în `_versiuni`,
 * descărcarea prin server, parolele pe foldere, trimiterea în Galerie), Aplicația de desktop (`/admin/bunny/app`: cheile
 * fiecărui administrator) și Setări → bunny.net (`/admin/settings/bunny`, doar administratorul general).
 *
 * Copiat cum e (aceleași texte, aceleași reguli, aceleași adrese și câmpuri), cu două schimbări: fiecare POST cere jetonul
 * formularului (AdminCsrf; codul Biovitality se baza doar pe cookie-ul `SameSite=Lax`), iar schema o face migrarea
 * `fisiere_cloud/0059`, nu `ensureSchema` la fiecare cerere.
 */
final class FisiereCloudController
{
    use Comun;

    public function bunnySettingsForm(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $settings = Settings::all($db);

        // Zonele descoperite cu cheia de cont sunt ținute o singură afișare, cât
        // să poată fi alese din formular.
        $detectedZones = $_SESSION['bunny_detected_zones'] ?? null;
        unset($_SESSION['bunny_detected_zones']);

        View::render('admin/settings-bunny', [
            'title' => 'bunny.net',
            'settings' => $settings,
            'detectedZones' => is_array($detectedZones) ? $detectedZones : [],
            'regions' => BunnyStorage::REGIONS,
        ], 'admin/layout');
    }

    public function bunnySettingsSave(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!$this->formularValid('/admin/settings/bunny')) {
            return;
        }

        $db = $this->db();
        $settings = Settings::all($db);
        $action = trim((string) ($_POST['action'] ?? 'save'));

        // Parola zonei și cheia de cont se păstrează dacă rândul e lăsat gol,
        // ca să nu fie nevoie de re-lipire la fiecare salvare.
        $storagePassword = trim((string) ($_POST['bunny_storage_password'] ?? ''));
        if ($storagePassword === '') {
            $storagePassword = (string) ($settings['bunny_storage_password'] ?? '');
        }
        $apiKey = trim((string) ($_POST['bunny_api_key'] ?? ''));
        if ($apiKey === '') {
            $apiKey = (string) ($settings['bunny_api_key'] ?? '');
        }

        $uploadTarget = trim((string) ($_POST['bunny_upload_target'] ?? 'local'));
        if (!in_array($uploadTarget, ['local', 'bunny'], true)) {
            $uploadTarget = 'local';
        }

        $values = [
            'bunny_enabled' => isset($_POST['bunny_enabled']) ? '1' : '0',
            'bunny_storage_zone' => trim((string) ($_POST['bunny_storage_zone'] ?? '')),
            'bunny_storage_password' => $storagePassword,
            'bunny_storage_region' => BunnyStorage::normalizeRegion((string) ($_POST['bunny_storage_region'] ?? '')),
            'bunny_cdn_url' => BunnyStorage::normalizeCdnUrl((string) ($_POST['bunny_cdn_url'] ?? '')),
            'bunny_base_folder' => BunnyStorage::normalizePath((string) ($_POST['bunny_base_folder'] ?? '')),
            'bunny_api_key' => $apiKey,
            'bunny_timeout' => (string) max(5, min(60, (int) ($_POST['bunny_timeout'] ?? 20))),
            'bunny_upload_target' => $uploadTarget,
            'bunny_gallery_folder' => BunnyStorage::normalizePath((string) ($_POST['bunny_gallery_folder'] ?? 'galerie')),
            'bunny_lock_expire_hours' => (string) max(1, min(720, (int) ($_POST['bunny_lock_expire_hours'] ?? 24))),
            'bunny_versions_keep' => (string) max(0, min(100, (int) ($_POST['bunny_versions_keep'] ?? 10))),
            'bunny_app_download_path' => BunnyStorage::normalizePath((string) ($_POST['bunny_app_download_path'] ?? '')),
        ];

        Settings::save($db, $values);
        $settings = array_merge($settings, $values);

        if ($db instanceof PDO) {
            AdminActivityLog::log($db, 'bunny_settings_save', [
                'action' => $action,
                'zone' => $values['bunny_storage_zone'],
                'cdn_url' => $values['bunny_cdn_url'],
                'upload_target' => $values['bunny_upload_target'],
            ]);
        }

        if ($action === 'detect') {
            try {
                $zones = BunnyStorage::accountZones($apiKey, (int) $values['bunny_timeout']);
                if ($zones === []) {
                    Flash::set('error', 'Contul bunny.net nu are nicio zonă de storage.');
                } else {
                    $_SESSION['bunny_detected_zones'] = $zones;
                    Flash::set('success', 'Am găsit ' . count($zones) . ' zone în contul bunny.net. Alege una mai jos.');
                }
            } catch (Throwable $e) {
                Flash::set('error', $e->getMessage());
            }

            header('Location: /admin/settings/bunny');
            return;
        }

        if ($action === 'test') {
            $client = BunnyStorage::fromSettings($settings);
            if ($client === null) {
                Flash::set('error', 'Completează numele zonei și parola de storage înainte de test.');
            } else {
                try {
                    $info = $client->ping();
                    Flash::set('success', sprintf(
                        'Conexiune reușită la %s: %d foldere și %d fișiere în rădăcina zonei.',
                        $info['host'],
                        $info['folders'],
                        $info['files']
                    ));
                } catch (Throwable $e) {
                    Flash::set('error', $e->getMessage());
                }
            }

            header('Location: /admin/settings/bunny');
            return;
        }

        Flash::set('success', 'Setările bunny.net au fost salvate.');
        header('Location: /admin/settings/bunny');
    }

    /** Alege o zonă detectată automat și îi salvează datele. */
    public function bunnySettingsUseZone(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!$this->formularValid('/admin/settings/bunny')) {
            return;
        }

        $name = trim((string) ($_POST['zone_name'] ?? ''));
        $password = trim((string) ($_POST['zone_password'] ?? ''));
        if ($name === '' || $password === '') {
            Flash::set('error', 'Zona selectată nu are datele complete.');
            header('Location: /admin/settings/bunny');
            return;
        }

        Settings::save($this->db(), [
            'bunny_storage_zone' => $name,
            'bunny_storage_password' => $password,
            'bunny_storage_region' => BunnyStorage::normalizeRegion((string) ($_POST['zone_region'] ?? '')),
            'bunny_cdn_url' => BunnyStorage::normalizeCdnUrl((string) ($_POST['zone_cdn_url'] ?? '')),
        ]);

        Flash::set('success', 'Zona „' . $name . '” a fost preluată în setări. Verifică adresa CDN și salvează.');
        header('Location: /admin/settings/bunny');
    }

    public function bunnyMedia(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $settings = Settings::all($db);
        $path = BunnyStorage::normalizePath((string) ($_GET['path'] ?? ''));
        $searchQuery = trim((string) ($_GET['q'] ?? ''));

        $client = (string) ($settings['bunny_enabled'] ?? '0') === '1'
            ? BunnyStorage::fromSettings($settings)
            : null;

        $folders = [];
        $files = [];
        $error = '';

        // Folder cu parolă: nu se citește nimic din el până nu e deschis. Lista
        // se cere de la Bunny abia după poartă, ca nici măcar numele fișierelor
        // să nu ajungă în pagină.
        $lockedFolder = $this->bunnyLocked($db, $path);

        if ($lockedFolder === null) {
            if ($client === null) {
                $error = (string) ($settings['bunny_enabled'] ?? '0') === '1'
                    ? 'Integrarea bunny.net nu este configurată complet.'
                    : '';
            } else {
                try {
                    $contents = $client->listPath($path);
                    $folders = $contents['folders'];
                    $files = $contents['files'];
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }

        if ($searchQuery !== '') {
            $needle = mb_strtolower($searchQuery);
            $matches = static fn (string $name): bool => str_contains(mb_strtolower($name), $needle);
            $folders = array_values(array_filter($folders, static fn (array $f): bool => $matches($f['name'])));
            $files = array_values(array_filter($files, static fn (array $f): bool => $matches($f['name'])));
        }

        $galleryFolders = [];
        $locks = [];
        if ($db instanceof PDO) {
            try {
                $galleryFolders = $db->query('SELECT id, name FROM gallery_folders ORDER BY name ASC')->fetchAll() ?: [];
            } catch (Throwable) {
                $galleryFolders = [];
            }

            // Lacătele expirate nu mai blochează pe nimeni: le curățăm la fiecare
            // afișare, ca lista să arate starea adevărată.
            FileLocks::purgeExpired($db);
            $locks = FileLocks::forPaths($db, array_map(
                static fn (array $file): string => (string) $file['path'],
                $files
            ));
        }

        // Care dintre folderele afișate au parolă, ca să poarte lacătul; și
        // dacă folderul deschis acum e el însuși unul cu parolă, ca să apară
        // butonul de închis la loc.
        $protectedFolders = [];
        $currentProtected = null;
        $openGuard = null;
        if ($db instanceof PDO) {
            foreach (FolderPasswords::all($db) as $record) {
                $protectedFolders[(string) $record['folder_path']] = $record;
            }
            $currentProtected = $protectedFolders[$path] ?? null;
            // Folderul cu parolă în care ne aflăm și care e deschis acum — poate
            // fi chiar ăsta sau unul de mai sus.
            if ($lockedFolder === null) {
                $openGuard = FolderPasswords::guardFor($db, $path);
            }
        }

        View::render('admin/bunny-media', [
            'title' => 'bunny.net',
            'settings' => $settings,
            'client' => $client,
            'path' => $path,
            'breadcrumbs' => $this->bunnyBreadcrumbs($path),
            'folders' => $folders,
            'files' => $files,
            'galleryFolders' => $galleryFolders,
            'locks' => $locks,
            'currentAdminId' => (int) (Auth::id() ?? 0),
            'canForceRelease' => Auth::isGeneralAdmin(),
            'searchQuery' => $searchQuery,
            'error' => $error,
            'lockedFolder' => $lockedFolder,
            'protectedFolders' => $protectedFolders,
            'currentProtected' => $currentProtected,
            'openGuard' => $openGuard,
            'isGeneralAdmin' => Auth::isGeneralAdmin(),
            'lockWaitMinutes' => $lockedFolder !== null && $db instanceof PDO
                ? FolderPasswords::waitMinutes($db, FolderPasswords::whoSession(), (string) $lockedFolder['folder_path'])
                : 0,
        ], 'admin/layout');
    }

    public function bunnyUpload(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!$this->formularValid($this->bunnyMediaUrl((string) ($_POST['path'] ?? '')))) {
            return;
        }

        $path = BunnyStorage::normalizePath((string) ($_POST['path'] ?? ''));
        if (($inchis = $this->bunnyLocked($this->db(), $path)) !== null) {
            Flash::set('error', $this->bunnyLockedMessage($inchis));
            header('Location: ' . $this->bunnyMediaUrl($path));
            return;
        }

        $client = $this->bunnyClient();
        if ($client === null) {
            Flash::set('error', 'Integrarea bunny.net nu este configurată.');
            header('Location: ' . $this->bunnyMediaUrl($path));
            return;
        }

        $uploads = $this->normalizeUploadedFiles($_FILES['files'] ?? null);
        if ($uploads === []) {
            Flash::set('error', 'Nu ai ales niciun fișier.');
            header('Location: ' . $this->bunnyMediaUrl($path));
            return;
        }

        $taken = $this->bunnyExistingNames($client, $path);
        $done = 0;
        $errors = [];
        foreach ($uploads as $upload) {
            if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $errors[] = (string) $upload['name'] . ': încărcarea a eșuat (limita PHP de upload?).';
                continue;
            }
            $tmp = (string) ($upload['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                continue;
            }
            $name = $this->bunnyFreeName(BunnyStorage::sanitizeName((string) ($upload['name'] ?? '')), $taken);
            if ($name === '') {
                $errors[] = 'Un fișier are un nume pe care nu îl pot folosi.';
                continue;
            }
            try {
                $client->uploadFile($path === '' ? $name : $path . '/' . $name, $tmp);
                $taken[strtolower($name)] = true;
                $done++;
            } catch (Throwable $e) {
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }

        if ($done > 0) {
            Flash::set('success', $done === 1 ? 'Fișierul a fost urcat în Bunny.' : $done . ' fișiere au fost urcate în Bunny.');
        }
        if ($errors !== []) {
            Flash::set('error', implode(' ', array_slice($errors, 0, 3)));
        }

        header('Location: ' . $this->bunnyMediaUrl($path));
    }

    public function bunnyCreateFolder(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!$this->formularValid($this->bunnyMediaUrl((string) ($_POST['path'] ?? '')))) {
            return;
        }

        $path = BunnyStorage::normalizePath((string) ($_POST['path'] ?? ''));
        $name = BunnyStorage::sanitizeName((string) ($_POST['name'] ?? ''));
        $client = $this->bunnyClient();
        $inchis = $this->bunnyLocked($this->db(), $path);

        if ($inchis !== null) {
            Flash::set('error', $this->bunnyLockedMessage($inchis));
        } elseif ($client === null) {
            Flash::set('error', 'Integrarea bunny.net nu este configurată.');
        } elseif ($name === '') {
            Flash::set('error', 'Numele folderului este obligatoriu.');
        } else {
            try {
                $client->createFolder($path === '' ? $name : $path . '/' . $name);
                Flash::set('success', 'Folderul „' . $name . '” a fost creat în Bunny.');
            } catch (Throwable $e) {
                Flash::set('error', $e->getMessage());
            }
        }

        header('Location: ' . $this->bunnyMediaUrl($path));
    }

    public function bunnyDelete(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!$this->formularValid($this->bunnyMediaUrl((string) ($_POST['path'] ?? '')))) {
            return;
        }

        $parent = BunnyStorage::normalizePath((string) ($_POST['path'] ?? ''));
        $target = BunnyStorage::normalizePath((string) ($_POST['target'] ?? ''));
        $isFolder = (string) ($_POST['type'] ?? 'file') === 'folder';
        $client = $this->bunnyClient();
        $db = $this->db();

        if ($client === null) {
            Flash::set('error', 'Integrarea bunny.net nu este configurată.');
        } elseif ($target === '') {
            Flash::set('error', 'Nu am primit ce anume să șterg.');
        } elseif (($busy = $this->bunnyBusyWith($db, $target, $isFolder)) !== null) {
            // Ștergerea nu se ia înapoi, deci nu trece peste munca altuia.
            Flash::set('error', $busy);
        } else {
            try {
                if ($isFolder) {
                    $client->deleteFolder($target);
                    Flash::set('success', 'Folderul a fost șters din Bunny.');
                } else {
                    $client->deleteFile($target);
                    Flash::set('success', 'Fișierul a fost șters din Bunny.');
                }
                if ($db instanceof PDO) {
                    // Fără asta, lacătul ar rămâne agățat de o cale moartă și un
                    // fișier viitor cu același nume ar părea ocupat din prima.
                    FileLocks::forget($db, $target, $isFolder);
                    if ($isFolder) {
                        FolderPasswords::forgetPrefix($db, $target);
                    }
                    AdminActivityLog::log($db, 'bunny_delete', ['target' => $target, 'folder' => $isFolder]);
                }
            } catch (Throwable $e) {
                Flash::set('error', $e->getMessage());
            }
        }

        header('Location: ' . $this->bunnyMediaUrl($parent));
    }

    /**
     * Trece fișierele bifate din Bunny în Galerie, ca înregistrări care arată
     * spre adresa de pe CDN. De acolo sunt folosibile în pagini, produse și blog.
     */
    public function bunnyImportToGallery(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!$this->formularValid($this->bunnyMediaUrl((string) ($_POST['path'] ?? '')))) {
            return;
        }

        $path = BunnyStorage::normalizePath((string) ($_POST['path'] ?? ''));
        $client = $this->bunnyClient();
        $db = $this->db();

        if ($client === null || !$db instanceof PDO) {
            Flash::set('error', 'Integrarea bunny.net sau conexiunea DB nu este disponibilă.');
            header('Location: ' . $this->bunnyMediaUrl($path));
            return;
        }
        if (!$client->hasCdnUrl()) {
            Flash::set('error', 'Adaugă întâi adresa Pull Zone (CDN) în setări, altfel fișierele nu au adresă publică.');
            header('Location: ' . $this->bunnyMediaUrl($path));
            return;
        }
        // Trimiterea în Galerie face fișierul vizibil pe site: un folder cu
        // parolă n-are voie să iasă pe ușa asta cât e închis.
        if (($inchis = $this->bunnyLocked($db, $path)) !== null) {
            Flash::set('error', $this->bunnyLockedMessage($inchis));
            header('Location: ' . $this->bunnyMediaUrl($path));
            return;
        }

        $paths = $_POST['paths'] ?? [];
        if (!is_array($paths) || $paths === []) {
            Flash::set('error', 'Nu ai bifat niciun fișier.');
            header('Location: ' . $this->bunnyMediaUrl($path));
            return;
        }

        $folderId = (int) ($_POST['folder_id'] ?? 0);

        $exists = $db->prepare('SELECT id FROM gallery_images WHERE image_url = :url LIMIT 1');
        $insert = $db->prepare(
            'INSERT INTO gallery_images (title, media_type, image_url, folder_id, alt_text, sort_order, is_active)
             VALUES (:title, :media_type, :image_url, :folder_id, :alt_text, 0, 1)'
        );

        $imported = 0;
        $skipped = 0;
        $unsupported = 0;
        foreach ($paths as $rawPath) {
            $filePath = BunnyStorage::normalizePath((string) $rawPath);
            if ($filePath === '') {
                continue;
            }
            // Galeria ține doar imagini și clipuri; un PDF acolo ar apărea rupt.
            $mediaType = BunnyStorage::mediaType($filePath);
            if ($mediaType === 'file') {
                $unsupported++;
                continue;
            }
            $url = $client->publicUrl($filePath);
            if ($url === '') {
                continue;
            }

            $exists->execute(['url' => $url]);
            if ($exists->fetchColumn() !== false) {
                $skipped++;
                continue;
            }

            $baseName = basename($filePath);
            $title = trim((string) (preg_replace(
                '/\s+/',
                ' ',
                str_replace(['-', '_'], ' ', pathinfo($baseName, PATHINFO_FILENAME))
            ) ?? ''));

            // `title` e VARCHAR(190), `alt_text` VARCHAR(255): tăiem numele lungi.
            $title = mb_substr($title !== '' ? $title : $baseName, 0, 190);

            $insert->execute([
                'title' => $title,
                'media_type' => $mediaType,
                'image_url' => $url,
                'folder_id' => $folderId > 0 ? $folderId : null,
                'alt_text' => mb_substr($title, 0, 255),
            ]);
            $imported++;
        }

        if ($imported > 0) {
            Flash::set('success', $imported . ' fișiere din Bunny au fost adăugate în Galerie.'
                . ($skipped > 0 ? ' ' . $skipped . ' erau deja acolo.' : ''));
        } elseif ($skipped > 0) {
            Flash::set('success', 'Fișierele bifate erau deja în Galerie.');
        } elseif ($unsupported === 0) {
            Flash::set('error', 'Nu am putut importa fișierele bifate.');
        }
        if ($unsupported > 0) {
            Flash::set('error', $unsupported . ' fișiere au fost sărite: Galeria ține doar imagini și clipuri.');
        }

        header('Location: ' . $this->bunnyMediaUrl($path));
    }



    /**
     * Pune sau schimbă parola unui folder.
     *
     * Parola nouă nu se poate pune peste una veche fără s-o știi — altfel
     * oricine ar putea ocoli protecția schimbând-o. Singura excepție e
     * Administratorul General, ca o parolă uitată să nu însemne un folder
     * pierdut pentru totdeauna; resetarea lui se scrie în jurnal.
     */
    public function bunnyFolderProtect(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!$this->formularValid($this->bunnyMediaUrl((string) ($_POST['path'] ?? '')))) {
            return;
        }

        $parent = BunnyStorage::normalizePath((string) ($_POST['path'] ?? ''));
        $target = BunnyStorage::normalizePath((string) ($_POST['target'] ?? ''));
        $parola = (string) ($_POST['password'] ?? '');
        $confirmare = (string) ($_POST['password_confirm'] ?? '');
        $veche = (string) ($_POST['current_password'] ?? '');
        $indiciu = trim((string) ($_POST['hint'] ?? ''));
        $db = $this->db();
        $redirect = $this->bunnyMediaUrl($parent);

        if (!$db instanceof PDO || $target === '') {
            Flash::set('error', 'Nu am primit folderul.');
            header('Location: ' . $redirect);
            return;
        }
        if ($parola !== $confirmare) {
            Flash::set('error', 'Cele două parole nu se potrivesc.');
            header('Location: ' . $redirect);
            return;
        }

        $existenta = FolderPasswords::find($db, $target);
        $general = Auth::isGeneralAdmin();
        if ($existenta !== null && !$general && !FolderPasswords::verify($veche, $existenta)) {
            Flash::set('error', 'Parola actuală a folderului nu e bună.');
            header('Location: ' . $redirect);
            return;
        }

        $rezultat = FolderPasswords::protect(
            $db,
            $target,
            $parola,
            $indiciu,
            (int) (Auth::id() ?? 0),
            $this->currentAdminEmail()
        );
        if (!$rezultat['ok']) {
            Flash::set('error', $rezultat['error']);
            header('Location: ' . $redirect);
            return;
        }

        AdminActivityLog::log($db, $existenta === null ? 'bunny_folder_protect' : 'bunny_folder_password_change', [
            'folder' => $target,
            'resetat_de_general' => $existenta !== null && $general && !FolderPasswords::verify($veche, $existenta),
        ]);

        Flash::set('success', $existenta === null
            ? 'Folderul „' . basename($target) . '” are acum parolă.'
            : 'Parola folderului „' . basename($target) . '” a fost schimbată. Cine îl avea deschis trebuie s-o scrie din nou.');
        header('Location: ' . $redirect);
    }

    /** Scoate parola unui folder. Aceleași reguli ca la schimbare. */
    public function bunnyFolderUnprotect(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!$this->formularValid($this->bunnyMediaUrl((string) ($_POST['path'] ?? '')))) {
            return;
        }

        $parent = BunnyStorage::normalizePath((string) ($_POST['path'] ?? ''));
        $target = BunnyStorage::normalizePath((string) ($_POST['target'] ?? ''));
        $veche = (string) ($_POST['current_password'] ?? '');
        $db = $this->db();
        $redirect = $this->bunnyMediaUrl($parent);

        if (!$db instanceof PDO || $target === '') {
            Flash::set('error', 'Nu am primit folderul.');
            header('Location: ' . $redirect);
            return;
        }

        $existenta = FolderPasswords::find($db, $target);
        if ($existenta === null) {
            Flash::set('error', 'Folderul ăsta n-are parolă.');
            header('Location: ' . $redirect);
            return;
        }

        $general = Auth::isGeneralAdmin();
        $stiaParola = FolderPasswords::verify($veche, $existenta);
        if (!$general && !$stiaParola) {
            Flash::set('error', 'Parola folderului nu e bună.');
            header('Location: ' . $redirect);
            return;
        }

        FolderPasswords::remove($db, $target);

        AdminActivityLog::log($db, 'bunny_folder_unprotect', [
            'folder' => $target,
            'fara_parola' => !$stiaParola,
        ]);

        Flash::set('success', 'Folderul „' . basename($target) . '” nu mai are parolă.');
        header('Location: ' . $redirect);
    }

    /** Deschide un folder cu parolă pentru sesiunea curentă. */
    public function bunnyFolderUnlock(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!$this->formularValid($this->bunnyMediaUrl((string) ($_POST['path'] ?? '')))) {
            return;
        }

        $target = BunnyStorage::normalizePath((string) ($_POST['target'] ?? ''));
        $catre = BunnyStorage::normalizePath((string) ($_POST['path'] ?? $target));
        $parola = (string) ($_POST['password'] ?? '');
        $db = $this->db();

        if (!$db instanceof PDO || $target === '') {
            Flash::set('error', 'Nu am primit folderul.');
            header('Location: ' . $this->bunnyMediaUrl(''));
            return;
        }

        $rezultat = FolderPasswords::unlock($db, FolderPasswords::whoSession(), $target, $parola);
        if (!$rezultat['ok']) {
            AdminActivityLog::log($db, 'bunny_folder_unlock_failed', [
                'folder' => $target,
                'motiv' => $rezultat['reason'],
            ]);
            Flash::set('error', $rezultat['reason'] === 'throttled'
                ? 'Prea multe încercări greșite. Mai așteaptă ' . $rezultat['wait_minutes'] . ' minute.'
                : 'Parola nu e bună.');
            header('Location: ' . $this->bunnyMediaUrl($catre));
            return;
        }

        header('Location: ' . $this->bunnyMediaUrl($catre));
    }

    /** Închide la loc un folder deschis, fără să aștepte expirarea. */
    public function bunnyFolderLock(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!$this->formularValid($this->bunnyMediaUrl((string) ($_POST['path'] ?? '')))) {
            return;
        }

        $target = BunnyStorage::normalizePath((string) ($_POST['target'] ?? ''));
        $db = $this->db();
        if ($db instanceof PDO) {
            FolderPasswords::lock($db, FolderPasswords::whoSession(), $target !== '' ? $target : null);
        }

        Flash::set('success', $target !== ''
            ? 'Folderul „' . basename($target) . '” s-a închis la loc.'
            : 'Toate folderele cu parolă s-au închis la loc.');
        header('Location: ' . $this->bunnyMediaUrl($target !== '' ? dirname($target) === '.' ? '' : dirname($target) : ''));
    }

    public function bunnyAppAccess(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $adminId = (int) (Auth::id() ?? 0);

        // Cheia nou generată se arată o singură dată, imediat după creare.
        $freshToken = (string) ($_SESSION['bunny_fresh_token'] ?? '');
        unset($_SESSION['bunny_fresh_token']);

        $settings = Settings::all($db);
        $appPath = BunnyStorage::normalizePath((string) ($settings['bunny_app_download_path'] ?? ''));

        // Executabilul urcat în Bunny, dacă e acolo: administratorii îl iau din
        // admin, nu de pe GitHub, și nu le trebuie niciun cont în plus.
        $appFile = null;
        if ($appPath !== '' && (string) ($settings['bunny_enabled'] ?? '0') === '1') {
            $client = BunnyStorage::fromSettings($settings);
            if ($client !== null) {
                try {
                    $appFile = $client->fileInfo($appPath);
                } catch (Throwable) {
                    $appFile = null;
                }
            }
        }

        View::render('admin/bunny-app', [
            'title' => 'Aplicația de desktop',
            'settings' => $settings,
            'tokens' => $db instanceof PDO ? AppTokens::forAdmin($db, $adminId) : [],
            'freshToken' => $freshToken,
            'adminEmail' => $this->currentAdminEmail(),
            'apiBaseUrl' => $this->appUrl(),
            'appFile' => $appFile,
            'appPath' => $appPath,
        ], 'admin/layout');
    }

    public function bunnyAppTokenCreate(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!$this->formularValid('/admin/bunny/app')) {
            return;
        }

        $db = $this->db();
        $adminId = (int) (Auth::id() ?? 0);
        if (!$db instanceof PDO || $adminId <= 0) {
            Flash::set('error', 'Nu am putut genera cheia.');
            header('Location: /admin/bunny/app');
            return;
        }

        $issued = AppTokens::issue($db, $adminId, (string) ($_POST['name'] ?? ''));
        if ($issued === null) {
            Flash::set('error', 'Nu am putut genera cheia.');
            header('Location: /admin/bunny/app');
            return;
        }

        $_SESSION['bunny_fresh_token'] = $issued['token'];
        AdminActivityLog::log($db, 'app_token_issue', ['token_id' => $issued['id']]);

        Flash::set('success', 'Cheie generată. Copiaz-o acum — nu se mai poate vedea după ce pleci de pe pagină.');
        header('Location: /admin/bunny/app');
    }

    public function bunnyAppTokenRevoke(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!$this->formularValid('/admin/bunny/app')) {
            return;
        }

        $db = $this->db();
        $adminId = (int) (Auth::id() ?? 0);
        $tokenId = (int) ($_POST['token_id'] ?? 0);

        if ($db instanceof PDO && AppTokens::revoke($db, $tokenId, $adminId)) {
            AdminActivityLog::log($db, 'app_token_revoke', ['token_id' => $tokenId]);
            Flash::set('success', 'Cheia a fost retrasă. Aplicația de pe acel calculator nu mai are acces.');
        } else {
            Flash::set('error', 'Nu am putut retrage cheia.');
        }

        header('Location: /admin/bunny/app');
    }

    /** Preia un fișier pentru editare din interfața web. */
    public function bunnyCheckout(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!$this->formularValid($this->bunnyMediaUrl((string) ($_POST['path'] ?? '')))) {
            return;
        }

        $parent = BunnyStorage::normalizePath((string) ($_POST['path'] ?? ''));
        $target = BunnyStorage::normalizePath((string) ($_POST['target'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));
        $db = $this->db();

        if (!$db instanceof PDO || $target === '') {
            Flash::set('error', 'Nu am putut prelua fișierul.');
            header('Location: ' . $this->bunnyMediaUrl($parent));
            return;
        }

        if (($inchis = $this->bunnyLocked($db, $target)) !== null) {
            Flash::set('error', $this->bunnyLockedMessage($inchis));
            header('Location: ' . $this->bunnyMediaUrl($parent));
            return;
        }

        $settings = Settings::all($db);
        $result = FileLocks::acquire(
            $db,
            $target,
            (int) (Auth::id() ?? 0),
            $this->currentAdminEmail(),
            $note,
            FileLocks::expireHours($settings)
        );

        if ($result['ok']) {
            Flash::set('success', 'Ai preluat „' . basename($target) . '”. Descarcă-l, modifică-l, apoi încarcă versiunea nouă.');
        } else {
            Flash::set('error', $result['reason'] === 'locked'
                ? 'Fișierul este preluat de ' . (string) ($result['lock']['owner_label'] ?? 'altcineva') . ' din ' . (string) ($result['lock']['locked_at_human'] ?? '') . '.'
                : 'Nu am putut prelua fișierul.');
        }

        header('Location: ' . $this->bunnyMediaUrl($parent));
    }


    /** Redenumește un fișier din Bunny. Vezi `DesktopApiController::rename` pentru reguli. */
    public function bunnyRename(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!$this->formularValid($this->bunnyMediaUrl((string) ($_POST['path'] ?? '')))) {
            return;
        }

        $parent = BunnyStorage::normalizePath((string) ($_POST['path'] ?? ''));
        $target = BunnyStorage::normalizePath((string) ($_POST['target'] ?? ''));
        $wanted = BunnyStorage::sanitizeName((string) ($_POST['new_name'] ?? ''));
        $db = $this->db();
        $redirect = $this->bunnyMediaUrl($parent);

        if (!$db instanceof PDO || $target === '' || $wanted === '') {
            Flash::set('error', 'Lipsește fișierul sau numele nou.');
            header('Location: ' . $redirect);
            return;
        }

        if (($inchis = $this->bunnyLocked($db, $target)) !== null) {
            Flash::set('error', $this->bunnyLockedMessage($inchis));
            header('Location: ' . $redirect);
            return;
        }

        if ((string) ($_POST['type'] ?? 'file') === 'folder') {
            $this->bunnyRenameFolder($db, $target, $wanted, $redirect);
            return;
        }

        $lock = FileLocks::find($db, $target);
        if ($lock !== null && (int) ($lock['admin_id'] ?? 0) !== (int) (Auth::id() ?? 0)) {
            Flash::set('error', 'Fișierul este deschis de ' . (string) ($lock['owner_label'] ?? 'altcineva') . '. Nu-l pot redenumi acum.');
            header('Location: ' . $redirect);
            return;
        }

        $folder = str_contains($target, '/') ? substr($target, 0, (int) strrpos($target, '/')) : '';
        $destination = $folder === '' ? $wanted : $folder . '/' . $wanted;
        if ($destination === $target) {
            header('Location: ' . $redirect);
            return;
        }

        $client = $this->bunnyClient();
        if ($client === null) {
            Flash::set('error', 'Integrarea bunny.net nu este configurată.');
            header('Location: ' . $redirect);
            return;
        }

        try {
            if ($client->exists($destination)) {
                Flash::set('error', 'Există deja un fișier cu numele „' . $wanted . '” în folderul ăsta.');
                header('Location: ' . $redirect);
                return;
            }
            $client->moveFile($target, $destination);
        } catch (Throwable $e) {
            Flash::set('error', $e->getMessage());
            header('Location: ' . $redirect);
            return;
        }

        FileLocks::repath($db, $target, $destination);
        FileVersions::repath($db, $target, $destination);

        AdminActivityLog::log($db, 'bunny_rename', ['from' => $target, 'to' => $destination]);

        Flash::set('success', 'Redenumit în „' . $wanted . '”.');
        header('Location: ' . $redirect);
    }

    /**
     * Redenumește un folder cu tot ce are în el.
     *
     * Bunny n-are redenumire nici la foldere, deci fiecare fișier trece prin
     * server: se copiază tot sub numele nou și abia apoi se șterge folderul
     * vechi. Cât timp cineva are un fișier preluat dinăuntru, operația se
     * refuză — i-ar dispărea calea de sub mână.
     *
     * Atenție: linkurile publice către fișierele din folder se schimbă odată
     * cu el, exact ca la redenumirea unui fișier.
     */
    private function bunnyRenameFolder(PDO $db, string $target, string $wanted, string $redirect): void
    {
        if (FileVersions::isArchivePath($target)) {
            Flash::set('error', 'Folderul „' . FileVersions::ARCHIVE_FOLDER . '” ține copiile de siguranță ale '
                . 'fișierelor. Redenumit, istoricul ar arăta spre fișiere care nu mai există.');
            header('Location: ' . $redirect);
            return;
        }

        $blocking = FileLocks::blockedUnder($db, $target, (int) (Auth::id() ?? 0));
        if ($blocking !== null) {
            Flash::set('error', 'În folder e ceva la lucru: „' . basename((string) $blocking['file_path'])
                . '” este deschis de ' . (string) ($blocking['owner_label'] ?? 'altcineva')
                . '. Redenumește folderul după ce termină.');
            header('Location: ' . $redirect);
            return;
        }

        $parent = str_contains($target, '/') ? substr($target, 0, (int) strrpos($target, '/')) : '';
        $destination = $parent === '' ? $wanted : $parent . '/' . $wanted;
        if ($destination === $target) {
            header('Location: ' . $redirect);
            return;
        }

        $client = $this->bunnyClient();
        if ($client === null) {
            Flash::set('error', 'Integrarea bunny.net nu este configurată.');
            header('Location: ' . $redirect);
            return;
        }

        try {
            $moved = $client->moveFolder($target, $destination);
        } catch (Throwable $e) {
            Flash::set('error', $e->getMessage());
            header('Location: ' . $redirect);
            return;
        }

        FileLocks::repathPrefix($db, $target, $destination);
        FileVersions::repathPrefix($db, $target, $destination);
        // Parola merge după folder. Altfel ar rămâne agățată de o cale moartă,
        // iar folderul, sub numele nou, ar apărea deschis pentru toată lumea.
        FolderPasswords::repathPrefix($db, $target, $destination);

        AdminActivityLog::log($db, 'bunny_rename_folder', [
            'from' => $target,
            'to' => $destination,
            'files' => $moved,
        ]);

        Flash::set('success', 'Folderul se numește acum „' . $wanted . '”'
            . ($moved > 0 ? ', cu tot cu cele ' . $moved . ' fișiere din el.' : '.'));
        header('Location: ' . $redirect);
    }

    /** Eliberează un fișier preluat. Administratorul General poate forța. */
    public function bunnyRelease(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!$this->formularValid($this->bunnyMediaUrl((string) ($_POST['path'] ?? '')))) {
            return;
        }

        $parent = BunnyStorage::normalizePath((string) ($_POST['path'] ?? ''));
        $target = BunnyStorage::normalizePath((string) ($_POST['target'] ?? ''));
        $db = $this->db();

        if (!$db instanceof PDO || $target === '') {
            Flash::set('error', 'Nu am putut elibera fișierul.');
            header('Location: ' . $this->bunnyMediaUrl($parent));
            return;
        }

        if (($inchis = $this->bunnyLocked($db, $target)) !== null) {
            Flash::set('error', $this->bunnyLockedMessage($inchis));
            header('Location: ' . $this->bunnyMediaUrl($parent));
            return;
        }

        $force = Auth::isGeneralAdmin();
        $result = FileLocks::release($db, $target, (int) (Auth::id() ?? 0), $force);

        if ($result['ok']) {
            Flash::set('success', $result['reason'] === 'forced'
                ? 'Ai eliberat forțat „' . basename($target) . '”, preluat de ' . (string) ($result['lock']['owner_label'] ?? 'altcineva') . '.'
                : 'Ai eliberat „' . basename($target) . '”.');
            if ($result['reason'] === 'forced') {
                AdminActivityLog::log($db, 'file_lock_forced_release', [
                    'path' => $target,
                    'owner' => (string) ($result['lock']['admin_email'] ?? ''),
                ]);
            }
        } else {
            Flash::set('error', 'Fișierul este preluat de ' . (string) ($result['lock']['owner_label'] ?? 'altcineva') . '.');
        }

        header('Location: ' . $this->bunnyMediaUrl($parent));
    }

    /**
     * Trimite fișierul către browser, luat din Bunny cu cheia serverului.
     * Documentele de lucru nu trec prin adresa publică de CDN.
     */
    public function bunnyDownload(): void
    {
        if (!$this->guard()) {
            return;
        }

        $target = BunnyStorage::normalizePath((string) ($_GET['path'] ?? ''));
        $client = $this->bunnyClient();

        if ($client === null || $target === '') {
            Flash::set('error', 'Nu am putut descărca fișierul.');
            header('Location: /admin/bunny');
            return;
        }
        if (($inchis = $this->bunnyLocked($this->db(), $target)) !== null) {
            Flash::set('error', $this->bunnyLockedMessage($inchis));
            header('Location: ' . $this->bunnyMediaUrl(dirname($target) === '.' ? '' : dirname($target)));
            return;
        }

        try {
            $contents = $client->download($target);
        } catch (Throwable $e) {
            Flash::set('error', $e->getMessage());
            header('Location: ' . $this->bunnyMediaUrl(dirname($target) === '.' ? '' : dirname($target)));
            return;
        }

        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . strlen($contents));
        header('Content-Disposition: attachment; filename="' . basename($target) . '"');
        header('Cache-Control: no-store');
        echo $contents;
    }

    /** Încarcă versiunea nouă a unui fișier preluat, păstrând copia veche. */
    public function bunnyReplace(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!$this->formularValid($this->bunnyMediaUrl((string) ($_POST['path'] ?? '')))) {
            return;
        }

        $parent = BunnyStorage::normalizePath((string) ($_POST['path'] ?? ''));
        $target = BunnyStorage::normalizePath((string) ($_POST['target'] ?? ''));
        $db = $this->db();
        $redirect = $this->bunnyMediaUrl($parent);

        if (!$db instanceof PDO || $target === '') {
            Flash::set('error', 'Nu am putut încărca versiunea nouă.');
            header('Location: ' . $redirect);
            return;
        }

        if (($inchis = $this->bunnyLocked($db, $target)) !== null) {
            Flash::set('error', $this->bunnyLockedMessage($inchis));
            header('Location: ' . $redirect);
            return;
        }

        $adminId = (int) (Auth::id() ?? 0);
        $lock = FileLocks::find($db, $target);
        if ($lock === null) {
            Flash::set('error', 'Preia întâi fișierul, apoi încarcă versiunea nouă.');
            header('Location: ' . $redirect);
            return;
        }
        if ((int) ($lock['admin_id'] ?? 0) !== $adminId) {
            Flash::set('error', 'Fișierul este preluat de ' . (string) ($lock['owner_label'] ?? 'altcineva') . '.');
            header('Location: ' . $redirect);
            return;
        }

        $upload = $_FILES['file'] ?? null;
        if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Flash::set('error', 'Nu ai ales niciun fișier.');
            header('Location: ' . $redirect);
            return;
        }
        $tmp = (string) ($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            Flash::set('error', 'Fișierul primit nu este valid.');
            header('Location: ' . $redirect);
            return;
        }

        $client = $this->bunnyClient();
        if ($client === null) {
            Flash::set('error', 'Integrarea bunny.net nu este configurată.');
            header('Location: ' . $redirect);
            return;
        }

        $settings = Settings::all($db);
        FileVersions::archive($db, $client, $target, $adminId, $this->currentAdminEmail(), FileVersions::keepCount($settings));

        try {
            $client->uploadFile($target, $tmp);
        } catch (Throwable $e) {
            Flash::set('error', $e->getMessage());
            header('Location: ' . $redirect);
            return;
        }

        if (!isset($_POST['keep_lock'])) {
            FileLocks::release($db, $target, $adminId);
        }

        Flash::set('success', 'Versiunea nouă a fost salvată. Copia veche a rămas în istoric.');
        header('Location: ' . $redirect);
    }

    /** Emailul administratorului logat, pentru evidența lacătelor. */
    private function currentAdminEmail(): string
    {
        $db = $this->db();
        $adminId = Auth::id();
        if (!$db instanceof PDO || $adminId === null) {
            return '';
        }

        try {
            $stmt = $db->prepare('SELECT email FROM admins WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $adminId]);
            $email = $stmt->fetchColumn();
        } catch (Throwable) {
            return '';
        }

        return $email !== false ? (string) $email : '';
    }

    /**
     * Motivul pentru care ceva nu poate fi atins acum, sau null dacă e liber.
     * La folder se uită la tot ce conține, oricât de adânc.
     */
    private function bunnyBusyWith(?PDO $db, string $target, bool $isFolder): ?string
    {
        $inchis = $this->bunnyLocked($db, $target);
        if ($inchis !== null) {
            return $this->bunnyLockedMessage($inchis);
        }
        if (FileVersions::isArchivePath($target)) {
            return 'Folderul „' . FileVersions::ARCHIVE_FOLDER . '” ține copiile de siguranță ale fișierelor. '
                . 'Nu se atinge de aici.';
        }
        if (!$db instanceof PDO) {
            return null;
        }

        $me = (int) (Auth::id() ?? 0);
        if ($isFolder) {
            $blocking = FileLocks::blockedUnder($db, $target, $me);

            return $blocking === null ? null : 'În folder e ceva la lucru: „'
                . basename((string) $blocking['file_path']) . '” este deschis de '
                . (string) ($blocking['owner_label'] ?? 'altcineva') . '.';
        }

        $lock = FileLocks::find($db, $target);
        if ($lock === null || (int) ($lock['admin_id'] ?? 0) === $me || FileLocks::isExpired($lock)) {
            return null;
        }

        return 'Fișierul este deschis de ' . (string) ($lock['owner_label'] ?? 'altcineva') . '.';
    }

    /**
     * Folderul cu parolă care ține închisă calea asta pentru cel logat acum,
     * sau null dacă e liberă — fie nu e păzită, fie a fost deja deschisă.
     *
     * Fără bază de date nu se poate ști ce e închis, iar atunci răspunsul e
     * „închis": o barieră care dispare când baza sughiță n-ar fi o barieră.
     *
     * @return array<string, mixed>|null
     */
    private function bunnyLocked(?PDO $db, string $path): ?array
    {
        if (!$db instanceof PDO) {
            return ['folder_path' => BunnyStorage::normalizePath($path), 'hint' => null, 'no_db' => true];
        }

        return FolderPasswords::lockedFor($db, FolderPasswords::whoSession(), $path);
    }

    /** Ce i se spune omului despre un folder închis. */
    private function bunnyLockedMessage(array $guard): string
    {
        if (($guard['no_db'] ?? false) === true) {
            return 'Nu pot verifica parolele folderelor fără baza de date, așa că nu deschid nimic.';
        }

        return 'Folderul „' . basename((string) $guard['folder_path'])
            . '” are parolă. Deschide-l întâi, apoi încearcă din nou.';
    }

    private function bunnyClient(): ?BunnyStorage
    {
        $settings = Settings::all($this->db());
        if ((string) ($settings['bunny_enabled'] ?? '0') !== '1') {
            return null;
        }

        return BunnyStorage::fromSettings($settings);
    }

    private function bunnyMediaUrl(string $path): string
    {
        $path = BunnyStorage::normalizePath($path);

        return '/admin/bunny' . ($path !== '' ? '?path=' . rawurlencode($path) : '');
    }

    /** @return list<array{name: string, path: string}> */
    private function bunnyBreadcrumbs(string $path): array
    {
        $crumbs = [];
        $current = '';
        foreach (explode('/', BunnyStorage::normalizePath($path)) as $segment) {
            if ($segment === '') {
                continue;
            }
            $current = $current === '' ? $segment : $current . '/' . $segment;
            $crumbs[] = ['name' => $segment, 'path' => $current];
        }

        return $crumbs;
    }

    /**
     * Numele deja ocupate într-un folder Bunny, ca să nu suprascriem la urcare.
     *
     * @return array<string, true>
     */
    private function bunnyExistingNames(BunnyStorage $client, string $path): array
    {
        return $client->existingNames($path);
    }

    /** @param array<string, true> $taken */
    private function bunnyFreeName(string $name, array $taken): string
    {
        return BunnyStorage::pickFreeName($name, $taken);
    }

    /**
     * Normalizează `$_FILES['câmp']` (multiplu sau simplu) într-o listă de fișiere.
     *
     * @return list<array{name: string, tmp_name: string, error: int}>
     */
    private function normalizeUploadedFiles(mixed $field): array
    {
        if (!is_array($field)) {
            return [];
        }

        $names = $field['name'] ?? null;
        if (!is_array($names)) {
            if ((string) $names === '') {
                return [];
            }
            return [[
                'name' => (string) $names,
                'tmp_name' => (string) ($field['tmp_name'] ?? ''),
                'error' => (int) ($field['error'] ?? UPLOAD_ERR_NO_FILE),
            ]];
        }

        $files = [];
        foreach (array_keys($names) as $index) {
            $name = (string) ($names[$index] ?? '');
            $error = (int) ($field['error'][$index] ?? UPLOAD_ERR_NO_FILE);
            if ($name === '' || $error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = [
                'name' => $name,
                'tmp_name' => (string) ($field['tmp_name'][$index] ?? ''),
                'error' => $error,
            ];
        }

        return $files;
    }

    /**
     * Jetonul formularului (AdminCsrf) e cel al sesiunii? Altfel mesajul și întoarcerea la pagina de unde a venit formularul,
     * fără să se facă nimic.
     */
    private function formularValid(string $inapoi): bool
    {
        if (AdminCsrf::valid()) {
            return true;
        }
        Flash::set('error', 'Sesiunea formularului a expirat. Reîncarcă pagina și încearcă din nou.');
        header('Location: ' . $inapoi);
        return false;
    }
}
