<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Istoricul fișierelor de lucru din bunny.net.
 *
 * Bunny nu ține versiuni. Înainte de fiecare suprascriere, copia curentă e
 * mutată într-un folder de arhivă din aceeași zonă, iar în baza de date rămâne
 * evidența. Așa, o salvare greșită nu mai înseamnă pierderea variantei vechi.
 *
 * Se păstrează ultimele N copii (din setări); cele mai vechi se șterg singure,
 * ca arhiva să nu crească la nesfârșit.
 *
 * Pluginul `fisiere_cloud` (din Biovitality; docs/PLUGINURI.md, „Fișiere în cloud”), copiat cum e, fără `ensureSchema`:
 * tabelul `file_versions` îl face migrarea `fisiere_cloud/0059`
 * (adoptivă: pe Biovitality există, cu aceeași definiție).
 */
final class FileVersions
{
    public const ARCHIVE_FOLDER = '_versiuni';
    public const DEFAULT_KEEP = 10;

    /**
     * Mută copia curentă în arhivă, înainte ca fișierul să fie suprascris.
     * Întoarce calea de arhivă, sau null dacă fișierul încă nu există în zonă
     * (prima încărcare) ori dacă arhivarea n-a reușit.
     */
    public static function archive(
        PDO $db,
        BunnyStorage $client,
        string $path,
        int $adminId,
        string $adminEmail,
        int $keep = self::DEFAULT_KEEP
    ): ?string {
        $path = BunnyStorage::normalizePath($path);
        if ($path === '' || str_starts_with($path, self::ARCHIVE_FOLDER . '/')) {
            return null;
        }

        try {
            $contents = $client->download($path);
        } catch (Throwable) {
            // Fișierul nu există încă — nu e nimic de arhivat.
            return null;
        }

        $versionPath = self::buildVersionPath($path);
        try {
            $client->uploadContents($versionPath, $contents);
        } catch (Throwable) {
            return null;
        }

        try {
            $stmt = $db->prepare(
                'INSERT INTO file_versions (storage, file_path, path_hash, version_path, size_bytes, admin_id, admin_email)
                 VALUES ("bunny", :file_path, :path_hash, :version_path, :size_bytes, :admin_id, :admin_email)'
            );
            $stmt->execute([
                'file_path' => mb_substr($path, 0, 700),
                'path_hash' => FileLocks::hash($path),
                'version_path' => mb_substr($versionPath, 0, 760),
                'size_bytes' => strlen($contents),
                'admin_id' => $adminId > 0 ? $adminId : null,
                'admin_email' => $adminEmail !== '' ? $adminEmail : null,
            ]);
        } catch (Throwable) {
        }

        self::pruneOld($db, $client, $path, $keep);

        return $versionPath;
    }

    /**
     * Versiunile păstrate pentru un fișier, cea mai nouă întâi.
     *
     * @return list<array<string, mixed>>
     */
    public static function listFor(PDO $db, string $path): array
    {
        try {
            $stmt = $db->prepare(
                'SELECT id, version_path, size_bytes, admin_email, created_at
                   FROM file_versions
                  WHERE path_hash = :h
                  ORDER BY id DESC'
            );
            $stmt->execute(['h' => FileLocks::hash($path)]);
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }

        return array_values(array_map(static function (array $row): array {
            $row['size_human'] = BunnyStorage::formatSize((int) ($row['size_bytes'] ?? 0));
            $created = strtotime((string) ($row['created_at'] ?? ''));
            $row['created_human'] = $created !== false ? date('d.m.Y H:i', $created) : (string) ($row['created_at'] ?? '');

            return $row;
        }, array_filter($rows, 'is_array')));
    }

    /** O versiune anume, verificată că aparține fișierului cerut. */
    public static function find(PDO $db, int $versionId): ?array
    {
        try {
            $stmt = $db->prepare('SELECT * FROM file_versions WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $versionId]);
            $row = $stmt->fetch();
        } catch (Throwable) {
            return null;
        }

        return is_array($row) ? $row : null;
    }

    /** Leagă istoricul de calea nouă, după o redenumire. */
    public static function repath(PDO $db, string $oldPath, string $newPath): void
    {
        try {
            $stmt = $db->prepare(
                'UPDATE file_versions SET file_path = :new_path, path_hash = :new_hash WHERE path_hash = :old_hash'
            );
            $stmt->execute([
                'new_path' => mb_substr(BunnyStorage::normalizePath($newPath), 0, 700),
                'new_hash' => FileLocks::hash($newPath),
                'old_hash' => FileLocks::hash($oldPath),
            ]);
        } catch (Throwable) {
        }
    }

    /**
     * Calea asta e chiar arhiva, sau ceva dinăuntrul ei?
     *
     * Arhiva ține copiile de dinaintea fiecărei suprascrieri, iar în baza de
     * date rămâne evidența lor. Redenumită sau ștearsă din browser, evidența ar
     * arăta spre fișiere care nu mai există — un istoric care minte e mai rău
     * decât niciunul. De aceea folderul ăsta nu se atinge din site.
     */
    public static function isArchivePath(string $path): bool
    {
        $path = BunnyStorage::normalizePath($path);

        return $path === self::ARCHIVE_FOLDER || str_starts_with($path, self::ARCHIVE_FOLDER . '/');
    }

    /**
     * Mută istoricul odată cu folderul redenumit, ca versiunile vechi să rămână
     * legate de fișier și după ce calea lui s-a schimbat.
     *
     * Copiile arhivate rămân unde sunt, sub calea veche din `_versiuni` — ele
     * sunt fotografii ale trecutului, n-are rost mutate.
     *
     * @return int câte înregistrări s-au mutat
     */
    public static function repathPrefix(PDO $db, string $oldPrefix, string $newPrefix): int
    {
        $oldPrefix = BunnyStorage::normalizePath($oldPrefix);
        $newPrefix = BunnyStorage::normalizePath($newPrefix);
        if ($oldPrefix === '' || $newPrefix === '' || $oldPrefix === $newPrefix) {
            return 0;
        }

        try {
            $stmt = $db->prepare(
                "SELECT DISTINCT file_path FROM file_versions WHERE file_path LIKE :prefix ESCAPE '|'"
            );
            $stmt->execute(['prefix' => FileLocks::likePrefix($oldPrefix)]);
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return 0;
        }

        $moved = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $old = (string) $row['file_path'];
            self::repath($db, $old, $newPrefix . substr($old, strlen($oldPrefix)));
            $moved++;
        }

        return $moved;
    }

    /** Câte versiuni se păstrează, din setări. */
    public static function keepCount(array $settings): int
    {
        $keep = (int) ($settings['bunny_versions_keep'] ?? self::DEFAULT_KEEP);

        return max(0, min(100, $keep));
    }

    /**
     * Calea din arhivă pentru o copie nouă:
     * `_versiuni/documente/preturi.xlsx/20260826-143200-preturi.xlsx`
     */
    private static function buildVersionPath(string $path): string
    {
        $name = basename($path);
        $stamp = date('Ymd-His');

        return self::ARCHIVE_FOLDER . '/' . $path . '/' . $stamp . '-' . $name;
    }

    /** Șterge copiile care depășesc numărul păstrat. */
    private static function pruneOld(PDO $db, BunnyStorage $client, string $path, int $keep): void
    {
        $keep = max(0, min(100, $keep));

        try {
            $stmt = $db->prepare(
                'SELECT id, version_path FROM file_versions WHERE path_hash = :h ORDER BY id DESC'
            );
            $stmt->execute(['h' => FileLocks::hash($path)]);
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return;
        }

        $extra = array_slice($rows, $keep);
        foreach ($extra as $row) {
            if (!is_array($row)) {
                continue;
            }
            try {
                $client->deleteFile((string) $row['version_path']);
            } catch (Throwable) {
            }
            try {
                $del = $db->prepare('DELETE FROM file_versions WHERE id = :id');
                $del->execute(['id' => (int) $row['id']]);
            } catch (Throwable) {
            }
        }
    }
}
