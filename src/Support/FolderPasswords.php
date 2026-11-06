<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Parolă pe un folder din bunny.net.
 *
 * Un folder poate fi „închis": ca să-i vezi conținutul — din admin sau din
 * aplicația de desktop — trebuie să scrii parola lui. Parola apără tot ce e
 * înăuntru, oricât de adânc, fără să fie nevoie de bifat fișier cu fișier.
 *
 * Ce apără și ce nu — de spus limpede, fiindcă altfel se cred lucruri care nu
 * sunt adevărate: parola e o barieră între colegi, la nivelul site-ului.
 * Fișierul din bunny.net rămâne cum a fost, necriptat, iar adresa lui publică
 * de pe CDN funcționează mai departe pentru cine o cunoaște. Cine are cheia
 * zonei de storage îl vede oricum. Pentru așa ceva ar trebui criptat fișierul
 * însuși, ceea ce e cu totul altă discuție.
 *
 * Deblocarea nu ține veșnic: expiră după o oră de neatingere, iar o parolă
 * greșită de prea multe ori închide ușa pentru câteva minute.
 *
 * Pluginul `fisiere_cloud` (din Biovitality; docs/PLUGINURI.md, „Fișiere în cloud”), copiat cum e, fără `ensureSchema`:
 * tabelele `bunny_protected_folders` și `bunny_folder_unlocks` le face migrarea
 * `fisiere_cloud/0059` (adoptivă: pe Biovitality există, cu aceeași definiție).
 */
final class FolderPasswords
{
    /** Cât ține o deblocare de la ultima atingere. */
    public const UNLOCK_MINUTES = 60;

    /** Câte greșeli sunt îngăduite înainte de o pauză forțată. */
    public const MAX_GRESELI = 5;

    /** Cât durează pauza după prea multe greșeli. */
    public const PAUZA_MINUTE = 5;

    /** Lungimea minimă a unei parole de folder. */
    public const MIN_LUNGIME = 6;

    /** Amprenta unei căi, ca să poată fi cheie unică. */
    public static function hash(string $path): string
    {
        return hash('sha256', 'folder:' . BunnyStorage::normalizePath($path));
    }

    // ───────────────────────────────────────────────────────────
    // Punerea și scoaterea parolei
    // ───────────────────────────────────────────────────────────

    /**
     * Pune sau schimbă parola unui folder.
     *
     * @return array{ok: bool, error: string}
     */
    public static function protect(
        PDO $db,
        string $folder,
        string $password,
        string $hint,
        int $adminId,
        string $adminEmail
    ): array {
        $folder = BunnyStorage::normalizePath($folder);

        if ($folder === '') {
            return ['ok' => false, 'error' => 'Rădăcina zonei nu poate primi parolă.'];
        }
        if (FileVersions::isArchivePath($folder)) {
            return ['ok' => false, 'error' => 'Arhiva de versiuni nu poate primi parolă.'];
        }
        if (mb_strlen($password) < self::MIN_LUNGIME) {
            return ['ok' => false, 'error' => 'Parola trebuie să aibă cel puțin ' . self::MIN_LUNGIME . ' caractere.'];
        }

        try {
            $db->prepare(
                'INSERT INTO bunny_protected_folders
                    (folder_path, path_hash, password_hash, hint, created_by, created_by_email)
                 VALUES (:folder_path, :path_hash, :password_hash, :hint, :created_by, :created_by_email)
                 ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), hint = VALUES(hint)'
            )->execute([
                'folder_path' => mb_substr($folder, 0, 700),
                'path_hash' => self::hash($folder),
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'hint' => $hint !== '' ? mb_substr($hint, 0, 190) : null,
                'created_by' => $adminId > 0 ? $adminId : null,
                'created_by_email' => $adminEmail !== '' ? $adminEmail : null,
            ]);
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'Nu am putut salva parola folderului.'];
        }

        // O parolă nouă închide ușa pentru toți cei care o aveau deschisă cu cea
        // veche — altfel schimbarea n-ar însemna nimic până la expirare.
        self::forgetUnlocks($db, $folder);

        return ['ok' => true, 'error' => ''];
    }

    /** Scoate parola unui folder. */
    public static function remove(PDO $db, string $folder): bool
    {
        $folder = BunnyStorage::normalizePath($folder);
        if ($folder === '') {
            return false;
        }

        try {
            $stmt = $db->prepare('DELETE FROM bunny_protected_folders WHERE path_hash = :hash');
            $stmt->execute(['hash' => self::hash($folder)]);
            self::forgetUnlocks($db, $folder);

            return $stmt->rowCount() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Datele de protecție ale unui folder anume (fără parola în clar, evident).
     *
     * @return array<string, mixed>|null
     */
    public static function find(PDO $db, string $folder): ?array
    {
        foreach (self::all($db) as $record) {
            if ((string) $record['folder_path'] === BunnyStorage::normalizePath($folder)) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Toate folderele cu parolă. Se citesc o singură dată pe cerere: sunt
     * puține, iar căutarea celui care păzește o cale le cere pe toate.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(PDO $db): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            $rows = $db->query('SELECT * FROM bunny_protected_folders ORDER BY folder_path ASC')->fetchAll() ?: [];
        } catch (Throwable) {
            return self::$cache = [];
        }

        return self::$cache = array_values(array_filter($rows, 'is_array'));
    }

    /** Uită lista citită. Se cheamă după orice schimbare de protecție. */
    public static function resetCache(): void
    {
        self::$cache = null;
    }

    /** @var list<array<string, mixed>>|null */
    private static ?array $cache = null;

    /**
     * Folderul cu parolă care păzește calea dată, sau null dacă nu e păzită.
     *
     * Se caută în sus: pentru `contracte/2026/ianuarie.xlsx` întâi calea însăși
     * (dacă e chiar un folder închis), apoi `contracte/2026`, apoi `contracte`.
     * Câștigă cel mai adânc: un folder închis dinăuntrul altuia își cere propria
     * parolă, nu pe a părintelui.
     *
     * @return array<string, mixed>|null
     */
    public static function guardFor(PDO $db, string $path): ?array
    {
        $path = BunnyStorage::normalizePath($path);
        $protejate = self::all($db);
        if ($protejate === []) {
            return null;
        }

        $gasit = null;
        foreach ($protejate as $record) {
            $folder = BunnyStorage::normalizePath((string) $record['folder_path']);
            if ($folder === '') {
                continue;
            }
            $acopera = $path === $folder || str_starts_with($path . '/', $folder . '/');
            if (!$acopera) {
                continue;
            }
            if ($gasit === null || mb_strlen($folder) > mb_strlen((string) $gasit['folder_path'])) {
                $gasit = $record;
            }
        }

        return $gasit;
    }

    /** Parola dată se potrivește cu cea a folderului? */
    public static function verify(string $password, array $record): bool
    {
        $hash = (string) ($record['password_hash'] ?? '');

        return $hash !== '' && password_verify($password, $hash);
    }

    // ───────────────────────────────────────────────────────────
    // Deblocarea
    // ───────────────────────────────────────────────────────────

    /** Cine cere: sesiunea din browser. */
    public static function whoSession(): string
    {
        $id = session_id();

        return 'sesiune:' . substr(hash('sha256', is_string($id) && $id !== '' ? $id : 'fara-sesiune'), 0, 40);
    }

    /** Cine cere: o cheie de aplicație. */
    public static function whoToken(int $tokenId): string
    {
        return 'aplicatie:' . $tokenId;
    }

    /**
     * Încearcă deblocarea. Întoarce ce s-a întâmplat, ca apelantul să poată
     * spune omului adevărul: parolă greșită, prea multe încercări, sau gata.
     *
     * @return array{ok: bool, reason: string, wait_minutes: int}
     */
    public static function unlock(PDO $db, string $who, string $folder, string $password): array
    {
        $folder = BunnyStorage::normalizePath($folder);

        $record = self::find($db, $folder);
        if ($record === null) {
            // Nu e închis: nu e nimic de deblocat, dar nici o eroare.
            return ['ok' => true, 'reason' => 'not_protected', 'wait_minutes' => 0];
        }

        $stare = self::stare($db, $who, $folder);
        $asteapta = self::minuteDeAsteptat($stare);
        if ($asteapta > 0) {
            return ['ok' => false, 'reason' => 'throttled', 'wait_minutes' => $asteapta];
        }

        if (!self::verify($password, $record)) {
            self::greseala($db, $who, $folder, (int) ($stare['greseli'] ?? 0) + 1);

            return ['ok' => false, 'reason' => 'wrong_password', 'wait_minutes' => 0];
        }

        self::scrieDeblocare($db, $who, $folder);

        return ['ok' => true, 'reason' => 'unlocked', 'wait_minutes' => 0];
    }

    /**
     * Folderul e deblocat acum pentru cine întreabă?
     *
     * Fiecare atingere reîmprospătează valabilitatea: expirarea numără de la
     * ultima folosire, nu de la deblocare, ca să nu-i sară parola în față
     * omului tocmai când lucrează.
     */
    public static function isUnlocked(PDO $db, string $who, string $folder, bool $refresh = true): bool
    {
        $stare = self::stare($db, $who, $folder);
        $pana = trim((string) ($stare['unlocked_until'] ?? ''));
        if ($pana === '' || strtotime($pana) === false || strtotime($pana) < time()) {
            return false;
        }

        if ($refresh) {
            self::scrieDeblocare($db, $who, $folder);
        }

        return true;
    }

    /**
     * Calea e blocată pentru cine întreabă? Întoarce folderul care o păzește,
     * sau null dacă e liberă (fie nu e păzită, fie a fost deblocată).
     *
     * @return array<string, mixed>|null
     */
    public static function lockedFor(PDO $db, string $who, string $path, bool $refresh = true): ?array
    {
        $guard = self::guardFor($db, $path);
        if ($guard === null) {
            return null;
        }

        return self::isUnlocked($db, $who, (string) $guard['folder_path'], $refresh) ? null : $guard;
    }

    /** Închide la loc un folder pentru cine cere (sau toate, dacă e null). */
    public static function lock(PDO $db, string $who, ?string $folder = null): void
    {
        try {
            if ($folder === null) {
                $db->prepare('DELETE FROM bunny_folder_unlocks WHERE who = :who')->execute(['who' => $who]);
                return;
            }
            $db->prepare('DELETE FROM bunny_folder_unlocks WHERE who = :who AND path_hash = :hash')
               ->execute(['who' => $who, 'hash' => self::hash($folder)]);
        } catch (Throwable) {
        }
    }

    /** Câte minute mai are de așteptat cine a greșit prea des. Zero = poate încerca. */
    public static function waitMinutes(PDO $db, string $who, string $folder): int
    {
        return self::minuteDeAsteptat(self::stare($db, $who, $folder));
    }

    // ───────────────────────────────────────────────────────────
    // Mutarea și ștergerea folderelor
    // ───────────────────────────────────────────────────────────

    /**
     * Mută protecția odată cu folderul redenumit, împreună cu cele ale
     * subfolderelor lui. Altfel ar rămâne agățată de o cale moartă, iar folderul
     * nou ar apărea deschis.
     *
     * @return int câte protecții s-au mutat
     */
    public static function repathPrefix(PDO $db, string $oldPrefix, string $newPrefix): int
    {
        $oldPrefix = BunnyStorage::normalizePath($oldPrefix);
        $newPrefix = BunnyStorage::normalizePath($newPrefix);
        if ($oldPrefix === '' || $newPrefix === '' || $oldPrefix === $newPrefix) {
            return 0;
        }

        $mutate = 0;
        foreach (self::all($db) as $record) {
            $vechi = BunnyStorage::normalizePath((string) $record['folder_path']);
            if ($vechi !== $oldPrefix && !str_starts_with($vechi . '/', $oldPrefix . '/')) {
                continue;
            }
            $nou = $newPrefix . substr($vechi, strlen($oldPrefix));
            try {
                $db->prepare(
                    'UPDATE bunny_protected_folders SET folder_path = :nou, path_hash = :hash_nou
                     WHERE path_hash = :hash_vechi'
                )->execute([
                    'nou' => mb_substr($nou, 0, 700),
                    'hash_nou' => self::hash($nou),
                    'hash_vechi' => self::hash($vechi),
                ]);
                $mutate++;
            } catch (Throwable) {
            }
            self::forgetUnlocks($db, $vechi);
        }

        if ($mutate > 0) {
            self::resetCache();
        }

        return $mutate;
    }

    /**
     * Uită protecția unui folder șters, cu tot cu subfolderele lui.
     *
     * @return int câte protecții s-au șters
     */
    public static function forgetPrefix(PDO $db, string $folder): int
    {
        $folder = BunnyStorage::normalizePath($folder);
        if ($folder === '') {
            return 0;
        }

        $sterse = 0;
        foreach (self::all($db) as $record) {
            $cale = BunnyStorage::normalizePath((string) $record['folder_path']);
            if ($cale !== $folder && !str_starts_with($cale . '/', $folder . '/')) {
                continue;
            }
            if (self::remove($db, $cale)) {
                $sterse++;
            }
        }

        if ($sterse > 0) {
            self::resetCache();
        }

        return $sterse;
    }

    // ───────────────────────────────────────────────────────────
    // Intern
    // ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function stare(PDO $db, string $who, string $folder): array
    {
        try {
            $stmt = $db->prepare('SELECT * FROM bunny_folder_unlocks WHERE who = :who AND path_hash = :hash LIMIT 1');
            $stmt->execute(['who' => $who, 'hash' => self::hash($folder)]);
            $row = $stmt->fetch();

            return is_array($row) ? $row : [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function minuteDeAsteptat(array $stare): int
    {
        $pana = trim((string) ($stare['blocked_until'] ?? ''));
        if ($pana === '') {
            return 0;
        }
        $ts = strtotime($pana);
        if ($ts === false || $ts <= time()) {
            return 0;
        }

        return (int) max(1, ceil(($ts - time()) / 60));
    }

    private static function scrieDeblocare(PDO $db, string $who, string $folder): void
    {
        try {
            $db->prepare(
                'INSERT INTO bunny_folder_unlocks (who, folder_path, path_hash, unlocked_until, greseli, blocked_until)
                 VALUES (:who, :folder_path, :path_hash, :pana, 0, NULL)
                 ON DUPLICATE KEY UPDATE unlocked_until = VALUES(unlocked_until), greseli = 0, blocked_until = NULL'
            )->execute([
                'who' => $who,
                'folder_path' => mb_substr(BunnyStorage::normalizePath($folder), 0, 700),
                'path_hash' => self::hash($folder),
                'pana' => date('Y-m-d H:i:s', time() + self::UNLOCK_MINUTES * 60),
            ]);
        } catch (Throwable) {
        }
    }

    private static function greseala(PDO $db, string $who, string $folder, int $greseli): void
    {
        $blocatPana = $greseli >= self::MAX_GRESELI
            ? date('Y-m-d H:i:s', time() + self::PAUZA_MINUTE * 60)
            : null;

        try {
            $db->prepare(
                'INSERT INTO bunny_folder_unlocks (who, folder_path, path_hash, unlocked_until, greseli, blocked_until)
                 VALUES (:who, :folder_path, :path_hash, NULL, :greseli, :blocat)
                 ON DUPLICATE KEY UPDATE unlocked_until = NULL, greseli = VALUES(greseli), blocked_until = VALUES(blocked_until)'
            )->execute([
                'who' => $who,
                'folder_path' => mb_substr(BunnyStorage::normalizePath($folder), 0, 700),
                'path_hash' => self::hash($folder),
                'greseli' => $greseli >= self::MAX_GRESELI ? 0 : $greseli,
                'blocat' => $blocatPana,
            ]);
        } catch (Throwable) {
        }
    }

    /** Închide folderul pentru toată lumea — la schimbarea sau scoaterea parolei. */
    private static function forgetUnlocks(PDO $db, string $folder): void
    {
        try {
            $db->prepare('DELETE FROM bunny_folder_unlocks WHERE path_hash = :hash')
               ->execute(['hash' => self::hash($folder)]);
        } catch (Throwable) {
        }
        self::resetCache();
    }
}
