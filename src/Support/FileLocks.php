<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Lacătele pe fișierele de lucru din bunny.net.
 *
 * Nu împiedică pe nimeni la nivel de storage — Bunny n-are așa ceva. Ideea e
 * alta: cine vrea să lucreze la un fișier îl „preia", iar ceilalți văd cine îl
 * ține și de când. Așa nu se mai suprascriu doi oameni fără să știe unul de
 * altul, chiar dacă editarea în sine se întâmplă pe calculatorul fiecăruia.
 *
 * Un lacăt uitat nu blochează fișierul la nesfârșit: după numărul de ore din
 * setări expiră singur și poate fi preluat de altcineva.
 *
 * Pluginul `fisiere_cloud` (din Biovitality; docs/PLUGINURI.md, „Fișiere în cloud”), copiat cum e, fără `ensureSchema`:
 * tabelul `file_locks` îl face migrarea `fisiere_cloud/0059`
 * (adoptivă: pe Biovitality există, cu aceeași definiție).
 */
final class FileLocks
{
    public const DEFAULT_EXPIRE_HOURS = 24;

    /** Identitatea unei căi, scurtată la 64 de caractere ca să poată fi cheie unică. */
    public static function hash(string $path, string $storage = 'bunny'): string
    {
        return hash('sha256', $storage . ':' . BunnyStorage::normalizePath($path));
    }

    /**
     * Preia fișierul. Reușește dacă e liber, dacă lacătul e al aceluiași om sau
     * dacă lacătul altcuiva a expirat.
     *
     * @return array{ok: bool, reason: string, lock: array<string, mixed>|null}
     */
    public static function acquire(
        PDO $db,
        string $path,
        int $adminId,
        string $adminEmail,
        string $note = '',
        int $expireHours = self::DEFAULT_EXPIRE_HOURS,
        string $origin = 'web'
    ): array {
        // Când doi oameni apasă în aceeași clipă, InnoDB poate rupe una din
        // tranzacții (deadlock pe gap lock). Reîncercarea îi arată celui care a
        // pierdut cursa lacătul câștigătorului, nu o eroare tehnică.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $result = self::attemptAcquire($db, $path, $adminId, $adminEmail, $note, $expireHours, $origin);
            if ($result['reason'] !== 'error') {
                return $result;
            }
            usleep($attempt * 60_000);
        }

        return $result;
    }

    /** @return array{ok: bool, reason: string, lock: array<string, mixed>|null} */
    private static function attemptAcquire(
        PDO $db,
        string $path,
        int $adminId,
        string $adminEmail,
        string $note,
        int $expireHours,
        string $origin
    ): array {
        $path = BunnyStorage::normalizePath($path);
        if ($path === '' || $adminId <= 0) {
            return ['ok' => false, 'reason' => 'invalid', 'lock' => null];
        }

        $hash = self::hash($path);
        $expireHours = max(1, min(720, $expireHours));
        $ownTransaction = !$db->inTransaction();

        try {
            if ($ownTransaction) {
                $db->beginTransaction();
            }

            $stmt = $db->prepare('SELECT * FROM file_locks WHERE path_hash = :h FOR UPDATE');
            $stmt->execute(['h' => $hash]);
            $existing = $stmt->fetch();

            if (is_array($existing)) {
                $mine = (int) ($existing['admin_id'] ?? 0) === $adminId;
                if (!$mine && !self::isExpired($existing)) {
                    if ($ownTransaction) {
                        $db->commit();
                    }

                    return ['ok' => false, 'reason' => 'locked', 'lock' => self::decorate($existing)];
                }

                $update = $db->prepare(
                    'UPDATE file_locks
                        SET admin_id = :admin_id, admin_email = :admin_email, note = :note,
                            origin = :origin, locked_at = NOW(),
                            expires_at = DATE_ADD(NOW(), INTERVAL :hours HOUR)
                      WHERE id = :id'
                );
                $update->execute([
                    'admin_id' => $adminId,
                    'admin_email' => $adminEmail,
                    'note' => $note !== '' ? mb_substr($note, 0, 255) : null,
                    'origin' => $origin,
                    'hours' => $expireHours,
                    'id' => (int) $existing['id'],
                ]);
                $takenOver = !$mine;
            } else {
                $insert = $db->prepare(
                    'INSERT INTO file_locks (storage, file_path, path_hash, admin_id, admin_email, note, origin, expires_at)
                     VALUES ("bunny", :file_path, :path_hash, :admin_id, :admin_email, :note, :origin,
                             DATE_ADD(NOW(), INTERVAL :hours HOUR))'
                );
                $insert->execute([
                    'file_path' => mb_substr($path, 0, 700),
                    'path_hash' => $hash,
                    'admin_id' => $adminId,
                    'admin_email' => $adminEmail,
                    'note' => $note !== '' ? mb_substr($note, 0, 255) : null,
                    'origin' => $origin,
                    'hours' => $expireHours,
                ]);
                $takenOver = false;
            }

            if ($ownTransaction) {
                $db->commit();
            }
        } catch (Throwable) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            // Doi oameni au apăsat în aceeași clipă: cel care a pierdut cursa
            // vede lacătul câștigătorului, nu o eroare tehnică.
            $current = self::find($db, $path);
            if ($current !== null && (int) ($current['admin_id'] ?? 0) !== $adminId) {
                return ['ok' => false, 'reason' => 'locked', 'lock' => $current];
            }

            return ['ok' => false, 'reason' => 'error', 'lock' => null];
        }

        return [
            'ok' => true,
            'reason' => $takenOver ? 'taken_over' : 'acquired',
            'lock' => self::find($db, $path),
        ];
    }

    /**
     * Eliberează lacătul. Fără `$force`, doar deținătorul îl poate elibera.
     *
     * @return array{ok: bool, reason: string, lock: array<string, mixed>|null}
     */
    public static function release(PDO $db, string $path, int $adminId, bool $force = false): array
    {
        $path = BunnyStorage::normalizePath($path);
        if ($path === '') {
            return ['ok' => false, 'reason' => 'invalid', 'lock' => null];
        }

        $existing = self::find($db, $path);
        if ($existing === null) {
            return ['ok' => true, 'reason' => 'not_locked', 'lock' => null];
        }

        $mine = (int) ($existing['admin_id'] ?? 0) === $adminId;
        if (!$mine && !$force && !self::isExpired($existing)) {
            return ['ok' => false, 'reason' => 'not_owner', 'lock' => $existing];
        }

        try {
            $stmt = $db->prepare('DELETE FROM file_locks WHERE path_hash = :h');
            $stmt->execute(['h' => self::hash($path)]);
        } catch (Throwable) {
            return ['ok' => false, 'reason' => 'error', 'lock' => $existing];
        }

        return ['ok' => true, 'reason' => $mine ? 'released' : 'forced', 'lock' => $existing];
    }

    /** @return array<string, mixed>|null */
    public static function find(PDO $db, string $path): ?array
    {
        try {
            $stmt = $db->prepare('SELECT * FROM file_locks WHERE path_hash = :h LIMIT 1');
            $stmt->execute(['h' => self::hash($path)]);
            $row = $stmt->fetch();
        } catch (Throwable) {
            return null;
        }

        return is_array($row) ? self::decorate($row) : null;
    }

    /**
     * Lacătele pentru o listă de căi, într-o singură interogare — folosit când
     * afișăm un folder întreg.
     *
     * @param list<string> $paths
     * @return array<string, array<string, mixed>> cheia e calea
     */
    public static function forPaths(PDO $db, array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $hashes = [];
        foreach ($paths as $path) {
            $clean = BunnyStorage::normalizePath((string) $path);
            if ($clean !== '') {
                $hashes[self::hash($clean)] = $clean;
            }
        }
        if ($hashes === []) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($hashes), '?'));
            $stmt = $db->prepare('SELECT * FROM file_locks WHERE path_hash IN (' . $placeholders . ')');
            $stmt->execute(array_keys($hashes));
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[(string) $row['file_path']] = self::decorate($row);
        }

        return $result;
    }

    /**
     * Toate lacătele active, cel mai vechi întâi — pentru o privire de ansamblu.
     *
     * @return list<array<string, mixed>>
     */
    public static function active(PDO $db): array
    {
        try {
            $rows = $db->query('SELECT * FROM file_locks ORDER BY locked_at ASC')->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }

        // array_values: array_filter păstrează cheile, iar o listă cu găuri
        // s-ar codifica JSON ca obiect, nu ca șir — aplicația n-ar mai citi-o.
        return array_values(array_map(
            static fn (array $row): array => self::decorate($row),
            array_filter($rows, 'is_array')
        ));
    }

    /**
     * Eliberează toate fișierele preluate de un om. Întoarce câte au fost.
     * Folosit de butonul „Termină tot" din aplicație.
     */
    public static function releaseAllFor(PDO $db, int $adminId): int
    {
        if ($adminId <= 0) {
            return 0;
        }

        try {
            $stmt = $db->prepare('DELETE FROM file_locks WHERE admin_id = :admin_id');
            $stmt->execute(['admin_id' => $adminId]);

            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Fișierele preluate de un anumit om.
     *
     * @return list<array<string, mixed>>
     */
    public static function forAdmin(PDO $db, int $adminId): array
    {
        try {
            $stmt = $db->prepare('SELECT * FROM file_locks WHERE admin_id = :admin_id ORDER BY locked_at ASC');
            $stmt->execute(['admin_id' => $adminId]);
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }

        return array_values(array_map(
            static fn (array $row): array => self::decorate($row),
            array_filter($rows, 'is_array')
        ));
    }

    /**
     * Mută lacătul odată cu fișierul redenumit. Altfel ar rămâne agățat de o
     * cale care nu mai există, iar fișierul nou ar părea liber.
     */
    public static function repath(PDO $db, string $oldPath, string $newPath): void
    {
        try {
            $stmt = $db->prepare(
                'UPDATE file_locks SET file_path = :new_path, path_hash = :new_hash WHERE path_hash = :old_hash'
            );
            $stmt->execute([
                'new_path' => mb_substr(BunnyStorage::normalizePath($newPath), 0, 700),
                'new_hash' => self::hash($newPath),
                'old_hash' => self::hash($oldPath),
            ]);
        } catch (Throwable) {
        }
    }

    /**
     * Lacătele de pe fișierele dintr-un folder, oricât de adânc.
     *
     * Un folder nu se poate redenumi sau șterge cu inima ușoară dacă cineva
     * lucrează la un fișier dinăuntru: calea aceea dispare de sub el.
     *
     * @return list<array<string, mixed>>
     */
    public static function forPrefix(PDO $db, string $prefix): array
    {
        $prefix = BunnyStorage::normalizePath($prefix);
        if ($prefix === '') {
            return [];
        }

        try {
            $stmt = $db->prepare(
                "SELECT * FROM file_locks WHERE file_path LIKE :prefix ESCAPE '|' ORDER BY file_path ASC"
            );
            $stmt->execute(['prefix' => self::likePrefix($prefix)]);
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }

        return array_values(array_map(
            static fn (array $row): array => self::decorate($row),
            array_filter($rows, 'is_array')
        ));
    }

    /**
     * Primul fișier dintr-un folder ținut de altcineva decât cel care întreabă.
     * Null dacă nu-l ține nimeni — adică folderul poate fi atins.
     *
     * @return array<string, mixed>|null
     */
    public static function blockedUnder(PDO $db, string $prefix, int $adminId): ?array
    {
        foreach (self::forPrefix($db, $prefix) as $lock) {
            if ((int) ($lock['admin_id'] ?? 0) !== $adminId && !self::isExpired($lock)) {
                return $lock;
            }
        }

        return null;
    }

    /**
     * Mută lacătele odată cu folderul redenumit.
     *
     * `path_hash` e o amprentă, nu un text, deci nu se poate rescrie dintr-un
     * singur UPDATE cu LIKE: fiecare rând își recalculează amprenta din calea nouă.
     *
     * @return int câte lacăte s-au mutat
     */
    public static function repathPrefix(PDO $db, string $oldPrefix, string $newPrefix): int
    {
        $oldPrefix = BunnyStorage::normalizePath($oldPrefix);
        $newPrefix = BunnyStorage::normalizePath($newPrefix);
        if ($oldPrefix === '' || $newPrefix === '' || $oldPrefix === $newPrefix) {
            return 0;
        }

        $moved = 0;
        foreach (self::forPrefix($db, $oldPrefix) as $lock) {
            $old = (string) $lock['file_path'];
            $new = $newPrefix . substr($old, strlen($oldPrefix));
            self::repath($db, $old, $new);
            $moved++;
        }

        return $moved;
    }

    /**
     * Uită lacătul unui fișier șters, sau al tuturor fișierelor dintr-un folder
     * șters. Altfel ar rămâne agățate de căi care nu mai există și ar face
     * fișierele viitoare cu același nume să pară ocupate din prima.
     *
     * @return int câte lacăte s-au șters
     */
    public static function forget(PDO $db, string $path, bool $recursive = false): int
    {
        $path = BunnyStorage::normalizePath($path);
        if ($path === '') {
            return 0;
        }

        try {
            if ($recursive) {
                $stmt = $db->prepare(
                    "DELETE FROM file_locks WHERE path_hash = :hash OR file_path LIKE :prefix ESCAPE '|'"
                );
                $stmt->execute(['hash' => self::hash($path), 'prefix' => self::likePrefix($path)]);
            } else {
                $stmt = $db->prepare('DELETE FROM file_locks WHERE path_hash = :hash');
                $stmt->execute(['hash' => self::hash($path)]);
            }

            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Tiparul de LIKE pentru „tot ce e sub folderul ăsta".
     *
     * Numele de fișiere pot conține chiar caracterele speciale ale lui LIKE
     * („%", „_"), așa că se scapă cu „|" — altfel un folder numit „100%" ar
     * prinde în plasă jumătate din zonă.
     */
    public static function likePrefix(string $prefix): string
    {
        $escaped = str_replace(['|', '%', '_'], ['||', '|%', '|_'], BunnyStorage::normalizePath($prefix));

        return $escaped . '/%';
    }

    /** Șterge lacătele expirate. Rulat la afișarea listei, ca să nu se adune. */
    public static function purgeExpired(PDO $db): int
    {
        try {
            $stmt = $db->prepare('DELETE FROM file_locks WHERE expires_at IS NOT NULL AND expires_at < NOW()');
            $stmt->execute();

            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }

    public static function isExpired(array $lock): bool
    {
        $expires = trim((string) ($lock['expires_at'] ?? ''));
        if ($expires === '') {
            return false;
        }

        return strtotime($expires) < time();
    }

    /** Orele de valabilitate a unui lacăt, din setări. */
    public static function expireHours(array $settings): int
    {
        $hours = (int) ($settings['bunny_lock_expire_hours'] ?? self::DEFAULT_EXPIRE_HOURS);

        return max(1, min(720, $hours));
    }

    /** Adaugă câmpurile calculate de care are nevoie interfața. */
    private static function decorate(array $row): array
    {
        $row['is_expired'] = self::isExpired($row);
        $row['locked_at_human'] = self::humanDate((string) ($row['locked_at'] ?? ''));
        $row['expires_at_human'] = self::humanDate((string) ($row['expires_at'] ?? ''));
        $row['owner_label'] = trim((string) ($row['admin_email'] ?? '')) !== ''
            ? (string) $row['admin_email']
            : 'un administrator';

        return $row;
    }

    private static function humanDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $time = strtotime($value);
        if ($time === false) {
            return $value;
        }

        return date('d.m.Y H:i', $time);
    }
}
