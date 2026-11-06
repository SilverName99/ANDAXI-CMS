<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AdminActivityLog;
use App\Support\AppTokens;
use App\Support\Auth;
use App\Support\BunnyStorage;
use App\Support\Database;
use App\Support\FileLocks;
use App\Support\FileVersions;
use App\Support\FolderPasswords;
use App\Support\Settings;
use PDO;
use Throwable;

/**
 * API-ul folosit de aplicația de desktop.
 *
 * Aplicația nu primește niciodată datele de bunny.net: cere fișierul aici,
 * serverul îl aduce din zonă cu cheia lui și i-l trimite mai departe. La fel
 * la salvare. Așa, o aplicație instalată pe un calculator străin nu poate face
 * decât ce poate face și administratorul din browser.
 *
 * Autentificarea se face cu o cheie de aplicație (`Authorization: Bearer …`),
 * generată din admin și retrasă tot de acolo.
 *
 * Pluginul `fisiere_cloud` (din Biovitality; docs/PLUGINURI.md, „Fișiere în cloud”), copiat cum e: aplicația Windows
 * instalată (`desktop/BiovitalityFiles` din repo-ul Biovitality) vorbește cu el neschimbată, deci adresele `/api/desktop/*`,
 * antetele, codurile de stare și cheile JSON sunt exact cele de dinainte (tools/dev/test-fisiere-cloud.php le compară cu
 * codul Biovitality). Cu pluginul oprit, garda pluginurilor răspunde la `/api/desktop/*` cu JSON-ul ei 404, care are și
 * `error` (singurul câmp citit de aplicație la o eroare). Fără `ensureSchema` (tabelele le face migrarea `fisiere_cloud/0059`).
 *
 * Revizia de securitate a schimbat doar cererile care acum sunt refuzate pe drept (aceleași forme de răspuns pe care
 * aplicația le știe deja): copiile din `_versiuni` sunt păzite de parola folderului original și cele închise nu apar în
 * listă (423 `folder_locked` la intrare); ștergerea unui folder cu un subfolder cu parolă închis dă 423; nimic nu se
 * scrie în `_versiuni` (409 `archive`); dacă parolele nu se pot citi, 503 în loc de „deschis”; încercările greșite se
 * numără pe administrator, nu pe cheie.
 */
final class DesktopApiController
{
    /** Cât de mare poate fi un fișier trimis spre salvare. */
    private const MAX_UPLOAD_BYTES = 64 * 1024 * 1024;

    /** Versiunea protocolului, ca aplicația să știe dacă trebuie actualizată. */
    private const API_VERSION = 1;

    /** Lista folderelor cu parolă nu se poate citi: nu se deschide nimic. */
    private const FARA_PAROLE = 'Nu pot verifica acum parolele folderelor, așa că nu deschid nimic. Încearcă din nou peste puțin.';

    /** Verifică cheia și spune aplicației cine este și ce poate. */
    public function ping(): void
    {
        $identity = $this->authenticate();
        if ($identity === null) {
            return;
        }

        $settings = Settings::all($this->db());
        $client = BunnyStorage::fromSettings($settings);

        $this->json([
            'ok' => true,
            'api_version' => self::API_VERSION,
            'email' => $identity['email'],
            'roles' => $identity['roles'],
            'device' => $identity['token_name'],
            'storage_ready' => (string) ($settings['bunny_enabled'] ?? '0') === '1' && $client !== null,
            'lock_expire_hours' => FileLocks::expireHours($settings),
        ]);
    }

    /** Conținutul unui folder, cu starea de blocare a fiecărui fișier. */
    public function listFiles(): void
    {
        $identity = $this->authenticate();
        if ($identity === null) {
            return;
        }

        $client = $this->storage();
        if ($client === null) {
            return;
        }

        $path = BunnyStorage::normalizePath((string) ($_GET['path'] ?? ''));
        $db = $this->db();
        if ($this->folderLocked($db, $identity, $path)) {
            return;
        }

        try {
            $contents = $client->listPath($path);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 502);
            return;
        }

        $locks = [];
        if ($db instanceof PDO) {
            FileLocks::purgeExpired($db);
            $locks = FileLocks::forPaths($db, array_map(
                static fn (array $file): string => (string) $file['path'],
                $contents['files']
            ));
        }

        // În `_versiuni`, copiile fișierelor dintr-un folder cu parolă închis nu apar deloc (nici numele lor).
        $who = FolderPasswords::whoToken($identity['token_id']);
        if (FileVersions::isArchivePath($path) && $db instanceof PDO) {
            $vizibil = static fn (array $item): bool =>
                FolderPasswords::lockedFor($db, $who, (string) $item['path'], false) === null;
            $contents['files'] = array_values(array_filter($contents['files'], $vizibil));
            $contents['folders'] = array_values(array_filter($contents['folders'], $vizibil));
        }

        $files = array_map(function (array $file) use ($locks, $identity): array {
            $lock = $locks[(string) $file['path']] ?? null;

            return [
                'name' => $file['name'],
                'path' => $file['path'],
                'size' => $file['size'],
                'size_human' => BunnyStorage::formatSize((int) $file['size']),
                'updated_at' => $file['updated_at'],
                'media_type' => $file['media_type'],
                'lock' => $this->lockPayload($lock, $identity['admin_id']),
            ];
        }, $contents['files']);

        // Fiecare subfolder spune dacă are parolă și dacă e închis acum, ca
        // aplicația să-l poată marca în listă în loc să afle abia la intrare.
        $folders = array_map(function (array $folder) use ($db, $who): array {
            $inchis = $db instanceof PDO
                && FolderPasswords::lockedFor($db, $who, (string) $folder['path'], false) !== null;

            return $folder + ['locked' => $inchis];
        }, $contents['folders']);

        $this->json([
            'ok' => true,
            'path' => $path,
            'folders' => $folders,
            'files' => $files,
        ]);
    }

    /** Preia fișierul pentru editare. Fără asta, descărcarea nu blochează nimic. */
    public function checkout(): void
    {
        $identity = $this->authenticate();
        if ($identity === null) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            $this->json(['ok' => false, 'error' => 'Baza de date nu este disponibilă.'], 503);
            return;
        }

        $input = $this->input();
        $path = BunnyStorage::normalizePath((string) ($input['path'] ?? ''));
        if ($path === '') {
            $this->json(['ok' => false, 'error' => 'Calea fișierului lipsește.'], 422);
            return;
        }

        if ($this->folderLocked($db, $identity, $path)) {
            return;
        }

        $settings = Settings::all($db);
        $result = FileLocks::acquire(
            $db,
            $path,
            $identity['admin_id'],
            $identity['email'],
            (string) ($input['note'] ?? ''),
            FileLocks::expireHours($settings),
            'desktop'
        );

        if (!$result['ok']) {
            $this->json([
                'ok' => false,
                'error' => $result['reason'] === 'locked'
                    ? 'Fișierul este deja preluat de ' . (string) ($result['lock']['owner_label'] ?? 'altcineva') . '.'
                    : 'Nu am putut prelua fișierul.',
                'reason' => $result['reason'],
                'lock' => $this->lockPayload($result['lock'], $identity['admin_id']),
            ], 409);
            return;
        }

        $this->json([
            'ok' => true,
            'reason' => $result['reason'],
            'path' => $path,
            'lock' => $this->lockPayload($result['lock'], $identity['admin_id']),
            'download_url' => '/api/desktop/download?path=' . rawurlencode($path),
        ]);
    }

    /** Trimite conținutul fișierului. Cere ca fișierul să fie preluat de cel care cere. */
    public function download(): void
    {
        $identity = $this->authenticate();
        if ($identity === null) {
            return;
        }

        $client = $this->storage();
        if ($client === null) {
            return;
        }

        $path = BunnyStorage::normalizePath((string) ($_GET['path'] ?? ''));
        if ($path === '') {
            $this->json(['ok' => false, 'error' => 'Calea fișierului lipsește.'], 422);
            return;
        }
        if ($this->folderLocked($this->db(), $identity, $path)) {
            return;
        }

        try {
            $contents = $client->download($path);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 502);
            return;
        }

        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . strlen($contents));
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('X-File-Checksum: ' . hash('sha256', $contents));
        echo $contents;
    }

    /**
     * Salvează varianta nouă. Doar cel care a preluat fișierul poate salva, iar
     * copia veche merge întâi în arhivă.
     */
    public function upload(): void
    {
        $identity = $this->authenticate();
        if ($identity === null) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            $this->json(['ok' => false, 'error' => 'Baza de date nu este disponibilă.'], 503);
            return;
        }

        $path = BunnyStorage::normalizePath((string) ($_POST['path'] ?? ($_GET['path'] ?? '')));
        if ($path === '') {
            $this->json(['ok' => false, 'error' => 'Calea fișierului lipsește.'], 422);
            return;
        }

        if ($this->folderLocked($db, $identity, $path)) {
            return;
        }
        if ($this->inArhiva($path)) {
            return;
        }

        // Fișierul trebuie să fie preluat de cel care salvează. Altfel s-ar
        // putea suprascrie exact munca pe care lacătul o apăra.
        $lock = FileLocks::find($db, $path);
        if ($lock === null) {
            $this->json([
                'ok' => false,
                'reason' => 'not_checked_out',
                'error' => 'Fișierul nu este preluat. Preia-l întâi, apoi salvează.',
            ], 409);
            return;
        }
        if ((int) ($lock['admin_id'] ?? 0) !== $identity['admin_id']) {
            $this->json([
                'ok' => false,
                'reason' => 'locked',
                'error' => 'Fișierul este preluat de ' . (string) ($lock['owner_label'] ?? 'altcineva') . '.',
                'lock' => $this->lockPayload($lock, $identity['admin_id']),
            ], 409);
            return;
        }

        $upload = $_FILES['file'] ?? null;
        if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->json(['ok' => false, 'error' => 'Nu am primit fișierul.'], 422);
            return;
        }
        $tmp = (string) ($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $this->json(['ok' => false, 'error' => 'Fișierul primit nu este valid.'], 422);
            return;
        }
        if ((int) ($upload['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
            $this->json(['ok' => false, 'error' => 'Fișierul depășește limita de 64 MB.'], 413);
            return;
        }

        $client = $this->storage();
        if ($client === null) {
            return;
        }

        $settings = Settings::all($db);
        $archived = FileVersions::archive(
            $db,
            $client,
            $path,
            $identity['admin_id'],
            $identity['email'],
            FileVersions::keepCount($settings)
        );

        try {
            $client->uploadFile($path, $tmp);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 502);
            return;
        }

        $released = false;
        if ($this->truthy($_POST['release'] ?? '1')) {
            FileLocks::release($db, $path, $identity['admin_id']);
            $released = true;
        }

        $this->json([
            'ok' => true,
            'path' => $path,
            'archived_version' => $archived,
            'released' => $released,
        ]);
    }

    /** Renunță la fișier fără să salveze. */
    public function release(): void
    {
        $identity = $this->authenticate();
        if ($identity === null) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            $this->json(['ok' => false, 'error' => 'Baza de date nu este disponibilă.'], 503);
            return;
        }

        $input = $this->input();
        $path = BunnyStorage::normalizePath((string) ($input['path'] ?? ''));
        if ($path === '') {
            $this->json(['ok' => false, 'error' => 'Calea fișierului lipsește.'], 422);
            return;
        }

        // Eliberarea forțată rămâne dreptul Administratorului General.
        $force = $this->truthy($input['force'] ?? '0')
            && in_array(Auth::ROLE_GENERAL, $identity['roles'], true);

        $result = FileLocks::release($db, $path, $identity['admin_id'], $force);
        if (!$result['ok']) {
            $this->json([
                'ok' => false,
                'reason' => $result['reason'],
                'error' => 'Fișierul este preluat de ' . (string) ($result['lock']['owner_label'] ?? 'altcineva') . '.',
            ], 409);
            return;
        }

        $this->json(['ok' => true, 'reason' => $result['reason'], 'path' => $path]);
    }

    /**
     * Urcă un fișier nou în folderul cerut. Spre deosebire de `upload`, aici nu
     * există un lacăt de respectat, fiindcă fișierul încă nu există — dar nici
     * nu are voie să calce peste unul existent: dacă numele e luat, primește
     * unul liber („raport-1.xlsx"), iar aplicația spune cum s-a numit până la urmă.
     */
    public function uploadNew(): void
    {
        $identity = $this->authenticate();
        if ($identity === null) {
            return;
        }

        $client = $this->storage();
        if ($client === null) {
            return;
        }

        $folder = BunnyStorage::normalizePath((string) ($_POST['path'] ?? ''));
        if ($this->folderLocked($this->db(), $identity, $folder)) {
            return;
        }
        if ($this->inArhiva($folder)) {
            return;
        }

        $upload = $_FILES['file'] ?? null;
        if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->json(['ok' => false, 'error' => 'Nu am primit fișierul.'], 422);
            return;
        }
        $tmp = (string) ($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $this->json(['ok' => false, 'error' => 'Fișierul primit nu este valid.'], 422);
            return;
        }
        if ((int) ($upload['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
            $this->json(['ok' => false, 'error' => 'Fișierul depășește limita de 64 MB.'], 413);
            return;
        }

        $wanted = BunnyStorage::sanitizeName((string) ($upload['name'] ?? ''));
        if ($wanted === '') {
            $this->json(['ok' => false, 'error' => 'Numele fișierului nu poate fi folosit.'], 422);
            return;
        }

        $name = BunnyStorage::pickFreeName($wanted, $client->existingNames($folder));
        $path = $folder === '' ? $name : $folder . '/' . $name;
        if ($this->inArhiva($path)) {
            return;
        }

        try {
            $client->uploadFile($path, $tmp);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 502);
            return;
        }

        $this->json([
            'ok' => true,
            'path' => $path,
            'name' => $name,
            'renamed' => $name !== $wanted,
        ]);
    }


    /**
     * Redenumește un fișier.
     *
     * Bunny n-are redenumire, așa că se face din copiere + ștergere. Regulile:
     * fișierul trebuie să fie liber sau preluat chiar de cel care cere, numele
     * nou nu poate călca peste unul existent, iar lacătul și istoricul de
     * versiuni se mută pe calea nouă — altfel ar rămâne agățate de una moartă.
     */
    public function rename(): void
    {
        $identity = $this->authenticate();
        if ($identity === null) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            $this->json(['ok' => false, 'error' => 'Baza de date nu este disponibilă.'], 503);
            return;
        }

        $input = $this->input();
        $path = BunnyStorage::normalizePath((string) ($input['path'] ?? ''));
        $wanted = BunnyStorage::sanitizeName((string) ($input['new_name'] ?? ''));

        if ($path === '' || $wanted === '') {
            $this->json(['ok' => false, 'error' => 'Lipsește fișierul sau numele nou.'], 422);
            return;
        }

        if ($this->folderLocked($db, $identity, $path)) {
            return;
        }

        if ($this->isFolder($input)) {
            $this->renameFolder($identity, $db, $path, $wanted);
            return;
        }
        if ($this->inArhiva($path)) {
            return;
        }

        $lock = FileLocks::find($db, $path);
        if ($lock !== null && (int) ($lock['admin_id'] ?? 0) !== $identity['admin_id']) {
            $this->json([
                'ok' => false,
                'reason' => 'locked',
                'error' => 'Fișierul este deschis de ' . (string) ($lock['owner_label'] ?? 'altcineva') . '. Nu-l pot redenumi acum.',
            ], 409);
            return;
        }

        $folder = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';
        $target = $folder === '' ? $wanted : $folder . '/' . $wanted;

        if ($target === $path) {
            $this->json(['ok' => true, 'path' => $path, 'name' => $wanted, 'unchanged' => true]);
            return;
        }
        if ($this->inArhiva($target)) {
            return;
        }

        $client = $this->storage();
        if ($client === null) {
            return;
        }

        try {
            if ($client->exists($target)) {
                $this->json([
                    'ok' => false,
                    'reason' => 'exists',
                    'error' => 'Există deja un fișier cu numele „' . $wanted . '” în folderul ăsta.',
                ], 409);
                return;
            }

            $client->moveFile($path, $target);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 502);
            return;
        }

        FileLocks::repath($db, $path, $target);
        FileVersions::repath($db, $path, $target);

        AdminActivityLog::log($db, 'bunny_rename', ['from' => $path, 'to' => $target], $identity['admin_id']);

        $this->json(['ok' => true, 'path' => $target, 'name' => $wanted]);
    }

    /**
     * Redenumește un folder cu tot ce are în el.
     *
     * E mai greu decât la un fișier: Bunny copiază fiecare fișier prin serverul
     * nostru, iar dacă cineva are ceva preluat dinăuntru, calea lui ar dispărea
     * de sub el. De aceea se refuză din start dacă e cineva la lucru acolo.
     */
    private function renameFolder(array $identity, PDO $db, string $path, string $wanted): void
    {
        if (FileVersions::isArchivePath($path)) {
            $this->json([
                'ok' => false,
                'reason' => 'archive',
                'error' => 'Folderul „' . FileVersions::ARCHIVE_FOLDER . '” ține copiile de siguranță ale '
                    . 'fișierelor. Redenumit, istoricul ar arăta spre fișiere care nu mai există.',
            ], 409);
            return;
        }

        $parinte = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';
        if ($this->inArhiva($parinte === '' ? $wanted : $parinte . '/' . $wanted)) {
            return;
        }

        $blocking = FileLocks::blockedUnder($db, $path, $identity['admin_id']);
        if ($blocking !== null) {
            $this->json([
                'ok' => false,
                'reason' => 'locked',
                'error' => 'În folder e ceva la lucru: „' . basename((string) $blocking['file_path'])
                    . '” este deschis de ' . (string) ($blocking['owner_label'] ?? 'altcineva')
                    . '. Redenumește folderul după ce termină.',
            ], 409);
            return;
        }

        $parent = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';
        $target = $parent === '' ? $wanted : $parent . '/' . $wanted;

        if ($target === $path) {
            $this->json(['ok' => true, 'path' => $path, 'name' => $wanted, 'unchanged' => true]);
            return;
        }

        $client = $this->storage();
        if ($client === null) {
            return;
        }

        try {
            $moved = $client->moveFolder($path, $target);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 502);
            return;
        }

        FileLocks::repathPrefix($db, $path, $target);
        FileVersions::repathPrefix($db, $path, $target);
        FolderPasswords::repathPrefix($db, $path, $target);

        AdminActivityLog::log($db, 'bunny_rename_folder', [
            'from' => $path,
            'to' => $target,
            'files' => $moved,
        ], $identity['admin_id']);

        $this->json(['ok' => true, 'path' => $target, 'name' => $wanted, 'files' => $moved]);
    }

    /**
     * Creează un folder în cel deschis acum.
     *
     * Bunny n-are „mkdir", deci folderul apare printr-un fișier-marcaj minuscul,
     * ascuns din listări. Numele luat deja nu se suprascrie: se răspunde cu o
     * eroare, ca omul să aleagă altul.
     */
    public function createFolder(): void
    {
        $identity = $this->authenticate();
        if ($identity === null) {
            return;
        }

        $client = $this->storage();
        if ($client === null) {
            return;
        }

        $input = $this->input();
        $parent = BunnyStorage::normalizePath((string) ($input['path'] ?? ''));
        $name = BunnyStorage::sanitizeName((string) ($input['name'] ?? ''));

        if ($this->folderLocked($this->db(), $identity, $parent)) {
            return;
        }
        if ($name === '') {
            $this->json(['ok' => false, 'error' => 'Numele folderului este obligatoriu.'], 422);
            return;
        }

        $target = $parent === '' ? $name : $parent . '/' . $name;
        if ($this->inArhiva($target)) {
            return;
        }

        try {
            if (isset($client->existingNames($parent)[strtolower($name)])) {
                $this->json([
                    'ok' => false,
                    'reason' => 'exists',
                    'error' => 'Există deja „' . $name . '” în folderul ăsta.',
                ], 409);
                return;
            }

            $client->createFolder($target);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 502);
            return;
        }

        $db = $this->db();
        if ($db instanceof PDO) {
            AdminActivityLog::log($db, 'bunny_create_folder', ['path' => $target], $identity['admin_id']);
        }

        $this->json(['ok' => true, 'path' => $target, 'name' => $name]);
    }

    /**
     * Șterge un fișier sau un folder întreg.
     *
     * Ștergerea nu se poate lua înapoi, așa că singura apărare pe care o poate
     * da serverul e să nu lase pe cineva să șteargă de sub mâna altuia: un
     * fișier preluat de altcineva, sau un folder în care e ceva la lucru,
     * rămân pe loc. Copiile din arhivă nu se ating — dacă ștergerea a fost o
     * greșeală, istoricul e tot acolo.
     */
    public function delete(): void
    {
        $identity = $this->authenticate();
        if ($identity === null) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            $this->json(['ok' => false, 'error' => 'Baza de date nu este disponibilă.'], 503);
            return;
        }

        $input = $this->input();
        $path = BunnyStorage::normalizePath((string) ($input['path'] ?? ''));
        $isFolder = $this->isFolder($input);

        if ($path === '') {
            $this->json(['ok' => false, 'error' => 'Nu am primit ce anume să șterg.'], 422);
            return;
        }
        if ($this->folderLocked($db, $identity, $path)) {
            return;
        }

        if (FileVersions::isArchivePath($path)) {
            $this->json([
                'ok' => false,
                'reason' => 'archive',
                'error' => 'Folderul „' . FileVersions::ARCHIVE_FOLDER . '” ține copiile de siguranță ale '
                    . 'fișierelor. Nu se șterge de aici.',
            ], 409);
            return;
        }

        if ($isFolder) {
            // Un subfolder cu parolă închis pentru cheia asta: n-ai voie să ștergi ce n-ai voie să vezi.
            $sub = FolderPasswords::lockedUnder($db, FolderPasswords::whoToken($identity['token_id']), $path);
            if ($sub !== null) {
                $this->raspundeInchis($db, $identity, $sub);
                return;
            }
            $blocking = FileLocks::blockedUnder($db, $path, $identity['admin_id']);
            if ($blocking !== null) {
                $this->json([
                    'ok' => false,
                    'reason' => 'locked',
                    'error' => 'În folder e ceva la lucru: „' . basename((string) $blocking['file_path'])
                        . '” este deschis de ' . (string) ($blocking['owner_label'] ?? 'altcineva')
                        . '. Nu-l pot șterge acum.',
                ], 409);
                return;
            }
        } else {
            $lock = FileLocks::find($db, $path);
            if ($lock !== null
                && (int) ($lock['admin_id'] ?? 0) !== $identity['admin_id']
                && !FileLocks::isExpired($lock)) {
                $this->json([
                    'ok' => false,
                    'reason' => 'locked',
                    'error' => 'Fișierul este deschis de ' . (string) ($lock['owner_label'] ?? 'altcineva')
                        . '. Nu-l pot șterge acum.',
                ], 409);
                return;
            }
        }

        $client = $this->storage();
        if ($client === null) {
            return;
        }

        try {
            if ($isFolder) {
                $client->deleteFolder($path);
            } else {
                $client->deleteFile($path);
            }
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 502);
            return;
        }

        FileLocks::forget($db, $path, $isFolder);
        if ($isFolder) {
            FolderPasswords::forgetPrefix($db, $path);
        }

        AdminActivityLog::log($db, 'bunny_delete', [
            'target' => $path,
            'folder' => $isFolder,
        ], $identity['admin_id']);

        $this->json(['ok' => true, 'path' => $path, 'folder' => $isFolder]);
    }

    /** Eliberează dintr-o dată tot ce are omul preluat — butonul „Termină tot". */
    public function releaseAll(): void
    {
        $identity = $this->authenticate();
        if ($identity === null) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            $this->json(['ok' => false, 'error' => 'Baza de date nu este disponibilă.'], 503);
            return;
        }

        $paths = array_map(
            static fn (array $lock): string => (string) $lock['file_path'],
            FileLocks::forAdmin($db, $identity['admin_id'])
        );
        $released = FileLocks::releaseAllFor($db, $identity['admin_id']);

        $this->json(['ok' => true, 'released' => $released, 'paths' => $paths]);
    }

    /** Fișierele preluate acum — aplicația le arată ca „în lucru". */
    public function locks(): void
    {
        $identity = $this->authenticate();
        if ($identity === null) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            $this->json(['ok' => false, 'error' => 'Baza de date nu este disponibilă.'], 503);
            return;
        }

        FileLocks::purgeExpired($db);
        // Un fișier preluat dintr-un folder cu parolă n-are ce căuta în listă
        // cât folderul e închis: i-ar da în vileag numele și calea.
        $who = FolderPasswords::whoToken($identity['token_id']);
        $vizibile = array_values(array_filter(
            FileLocks::active($db),
            static fn (array $lock): bool =>
                // Fără reîmprospătare: o simplă listare n-are voie să prelungească
                // deblocarea, altfel un program lăsat deschis ar ține folderul veșnic.
                FolderPasswords::lockedFor($db, $who, (string) $lock['file_path'], false) === null
        ));
        $locks = array_map(
            fn (array $lock): array => ($this->lockPayload($lock, $identity['admin_id']) ?? []) + ['path' => $lock['file_path']],
            $vizibile
        );

        $this->json(['ok' => true, 'locks' => $locks]);
    }

    /**
     * Cheia din antet → administratorul din spatele ei.
     * Trimite singură răspunsul de eroare și întoarce null dacă nu e bună.
     *
     * @return array{admin_id: int, email: string, roles: list<string>, token_id: int, token_name: string}|null
     */
    private function authenticate(): ?array
    {
        header('Cache-Control: no-store');

        $db = $this->db();
        if (!$db instanceof PDO) {
            $this->json(['ok' => false, 'error' => 'Baza de date nu este disponibilă.'], 503);
            return null;
        }

        $token = AppTokens::fromRequest();
        if ($token === '') {
            $this->json(['ok' => false, 'error' => 'Lipsește cheia de aplicație.'], 401);
            return null;
        }

        $identity = AppTokens::authenticate($db, $token);
        if ($identity === null) {
            $this->json(['ok' => false, 'error' => 'Cheia de aplicație nu este validă sau a fost retrasă.'], 401);
            return null;
        }

        // Aceleași drepturi ca în browser: cine n-are voie la secțiunea de
        // fișiere, n-are voie nici prin aplicație.
        if (!Auth::canAccessPath('/admin/bunny', $identity['roles'])) {
            $this->json(['ok' => false, 'error' => 'Contul nu are acces la fișiere.'], 403);
            return null;
        }

        return $identity;
    }

    /** Clientul de storage, sau răspuns de eroare dacă integrarea e oprită. */
    private function storage(): ?BunnyStorage
    {
        $settings = Settings::all($this->db());
        if ((string) ($settings['bunny_enabled'] ?? '0') !== '1') {
            $this->json(['ok' => false, 'error' => 'Integrarea bunny.net este oprită.'], 503);
            return null;
        }

        $client = BunnyStorage::fromSettings($settings);
        if ($client === null) {
            $this->json(['ok' => false, 'error' => 'Integrarea bunny.net nu este configurată complet.'], 503);
            return null;
        }

        return $client;
    }

    /** @return array<string, mixed>|null */
    private function lockPayload(?array $lock, int $viewerId): ?array
    {
        if ($lock === null) {
            return null;
        }

        return [
            'owner' => $lock['owner_label'] ?? '',
            'owner_id' => (int) ($lock['admin_id'] ?? 0),
            'is_mine' => (int) ($lock['admin_id'] ?? 0) === $viewerId,
            'note' => (string) ($lock['note'] ?? ''),
            'since' => $lock['locked_at_human'] ?? '',
            'expires' => $lock['expires_at_human'] ?? '',
            'expired' => (bool) ($lock['is_expired'] ?? false),
        ];
    }

    /** Corpul cererii, fie JSON, fie câmpuri de formular. */
    private function input(): array
    {
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($type, 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);

            return is_array($decoded) ? $decoded : [];
        }

        return $_POST;
    }

    /**
     * Calea e într-un folder cu parolă, neîncă deschis de cheia asta?
     *
     * Dacă da, trimite singur răspunsul și întoarce true — apelantul se
     * oprește acolo. Aplicația recunoaște `folder_locked` și cere parola.
     */
    private function folderLocked(?PDO $db, array $identity, string $path): bool
    {
        if (!$db instanceof PDO) {
            // Fără bază de date nu se poate ști ce e închis, deci nu se deschide
            // nimic: o barieră care dispare la o pană de bază n-ar fi barieră.
            $this->json(['ok' => false, 'error' => 'Baza de date nu este disponibilă.'], 503);
            return true;
        }

        $guard = FolderPasswords::lockedFor($db, FolderPasswords::whoToken($identity['token_id']), $path);
        if ($guard === null) {
            return false;
        }

        $this->raspundeInchis($db, $identity, $guard);

        return true;
    }

    /** Răspunsul pentru un folder cu parolă închis (423, pe care aplicația îl știe), sau 503 dacă parolele nu se pot citi. */
    private function raspundeInchis(PDO $db, array $identity, array $guard): void
    {
        if (($guard['no_db'] ?? false) === true) {
            $this->json(['ok' => false, 'error' => self::FARA_PAROLE], 503);
            return;
        }

        $folder = (string) $guard['folder_path'];
        $this->json([
            'ok' => false,
            'reason' => 'folder_locked',
            'folder' => $folder,
            'folder_name' => basename($folder),
            'hint' => (string) ($guard['hint'] ?? ''),
            'wait_minutes' => FolderPasswords::waitMinutes(
                $db,
                FolderPasswords::whoToken($identity['token_id']),
                $folder,
                $identity['admin_id']
            ),
            'error' => 'Folderul „' . basename($folder) . '” are parolă.',
        ], 423);
    }

    /**
     * Calea e în arhiva de versiuni? Acolo scrie doar codul care face copiile (FileVersions::archive); un fișier urcat
     * sau un folder făcut de mână ar amesteca istoricul. Dacă da, trimite singur 409 `archive` și întoarce true.
     */
    private function inArhiva(string $path): bool
    {
        if (!FileVersions::isArchivePath($path)) {
            return false;
        }

        $this->json([
            'ok' => false,
            'reason' => 'archive',
            'error' => 'Folderul „' . FileVersions::ARCHIVE_FOLDER . '” ține copiile de siguranță ale '
                . 'fișierelor și se completează singur. Nu se scrie nimic în el de mână.',
        ], 409);

        return true;
    }

    /**
     * Deschide un folder cu parolă pentru cheia care cere.
     *
     * Deblocarea se ține pe server, legată de cheia de aplicație, nu în
     * memoria programului: așa expiră singură și poate fi închisă de oriunde.
     */
    public function unlockFolder(): void
    {
        $identity = $this->authenticate();
        if ($identity === null) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            $this->json(['ok' => false, 'error' => 'Baza de date nu este disponibilă.'], 503);
            return;
        }

        $input = $this->input();
        $path = BunnyStorage::normalizePath((string) ($input['path'] ?? ''));
        if ($path === '') {
            $this->json(['ok' => false, 'error' => 'Calea folderului lipsește.'], 422);
            return;
        }

        // Se deschide folderul care păzește calea cerută, nu neapărat calea
        // însăși: aplicația trimite folderul în care voia să intre.
        $guard = FolderPasswords::guardFor($db, $path);
        if ($guard === null) {
            $this->json(['ok' => true, 'reason' => 'not_protected', 'folder' => $path]);
            return;
        }
        if (($guard['no_db'] ?? false) === true) {
            $this->json(['ok' => false, 'error' => self::FARA_PAROLE], 503);
            return;
        }

        $folder = (string) $guard['folder_path'];
        $who = FolderPasswords::whoToken($identity['token_id']);
        $result = FolderPasswords::unlock($db, $who, $folder, (string) ($input['password'] ?? ''), $identity['admin_id']);
        if ($result['reason'] === 'unavailable') {
            $this->json(['ok' => false, 'error' => self::FARA_PAROLE], 503);
            return;
        }

        if (!$result['ok']) {
            AdminActivityLog::log($db, 'bunny_folder_unlock_failed', [
                'folder' => $folder,
                'motiv' => $result['reason'],
                'din' => 'aplicatie',
            ], $identity['admin_id']);

            $this->json([
                'ok' => false,
                'reason' => $result['reason'],
                'wait_minutes' => $result['wait_minutes'],
                'error' => $result['reason'] === 'throttled'
                    ? 'Prea multe încercări greșite. Mai așteaptă ' . $result['wait_minutes'] . ' minute.'
                    : 'Parola nu e bună.',
            ], 403);
            return;
        }

        $this->json([
            'ok' => true,
            'folder' => $folder,
            'minutes' => FolderPasswords::UNLOCK_MINUTES,
        ]);
    }

    /** Închide la loc un folder deschis mai devreme (sau toate, dacă lipsește calea). */
    public function lockFolder(): void
    {
        $identity = $this->authenticate();
        if ($identity === null) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            $this->json(['ok' => false, 'error' => 'Baza de date nu este disponibilă.'], 503);
            return;
        }

        $path = BunnyStorage::normalizePath((string) ($this->input()['path'] ?? ''));
        FolderPasswords::lock($db, FolderPasswords::whoToken($identity['token_id']), $path !== '' ? $path : null);

        $this->json(['ok' => true, 'folder' => $path]);
    }

    /** Cererea vorbește despre un folder, nu despre un fișier? */
    private function isFolder(array $input): bool
    {
        return strtolower(trim((string) ($input['type'] ?? 'file'))) === 'folder';
    }

    private function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'da', 'yes', 'on'], true);
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function db(): ?PDO
    {
        $config = require __DIR__ . '/../../../config/app.php';

        return Database::connection($config['db']);
    }
}
