<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Clientul logat. Revizuirea WP-25: sesiunea ține și versiunea sesiunilor contului (`users.session_version`, migrarea
 * conturi_clienti/0038) din clipa logării. Versiunea crește la resetarea parolei și când o logare cu Google anulează
 * parola unui cont neconfirmat (`incheieAlteSesiuni`): o sesiune făcută înainte nu mai e logată, la prima întrebare.
 * Pe o bază nemigrată (fără coloană) nimic nu se schimbă.
 */
final class CustomerAuth
{
    /** Cheia sesiunii cu clientul logat (și în `Sesiune::CHEI_LOGARE`). */
    public const SESSION_KEY = 'customer_user_id';
    /** Versiunea sesiunilor contului la logare (și în `Sesiune::CHEI_LOGARE`). */
    public const SESSION_VERSION_KEY = 'customer_session_version';

    /** @var array<int, bool> id => versiunea sesiunii verificată în cererea de acum */
    private static array $verificat = [];
    private static ?bool $areColoana = null;

    public static function check(): bool
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || (int) $_SESSION[self::SESSION_KEY] <= 0) {
            return false;
        }
        return self::versiuneaEBuna(Database::connection([]), (int) $_SESSION[self::SESSION_KEY]);
    }

    /**
     * Versiunea din sesiune e cea din bază? Altfel ieșirea din cont (o sesiune de dinainte de o resetare a parolei).
     * Fără bază încă deschisă nu se poate spune: se crede sesiunea, iar întrebarea se repetă cu baza (user()).
     */
    private static function versiuneaEBuna(?PDO $db, int $userId): bool
    {
        if (isset(self::$verificat[$userId])) {
            return self::$verificat[$userId];
        }
        if (!$db instanceof PDO || !self::areColoana($db)) {
            return true;
        }
        try {
            $stmt = $db->prepare('SELECT session_version FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $versiune = $stmt->fetchColumn();
        } catch (Throwable) {
            return true;
        }
        $buna = $versiune === false || (int) $versiune === (int) ($_SESSION[self::SESSION_VERSION_KEY] ?? 0);
        if (!$buna) {
            self::logout();
        }
        return self::$verificat[$userId] = $buna;
    }

    private static function areColoana(PDO $db): bool
    {
        if (self::$areColoana === null) {
            try {
                $stmt = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'users' AND COLUMN_NAME = 'session_version'");
                self::$areColoana = $stmt !== false && (int) $stmt->fetchColumn() === 1;
            } catch (Throwable) {
                self::$areColoana = false;
            }
        }
        return self::$areColoana;
    }

    /**
     * Toate sesiunile contului de până acum nu mai sunt logate (resetarea parolei, parola anulată la o logare cu Google).
     * Sesiunea de acum, dacă e a acestui cont, rămâne logată cu versiunea nouă.
     */
    public static function incheieAlteSesiuni(PDO $db, int $userId): void
    {
        if ($userId <= 0 || !self::areColoana($db)) {
            return;
        }
        $db->prepare('UPDATE users SET session_version = session_version + 1 WHERE id = :id')->execute(['id' => $userId]);
        unset(self::$verificat[$userId]);
        if ((int) ($_SESSION[self::SESSION_KEY] ?? 0) === $userId) {
            $stmt = $db->prepare('SELECT session_version FROM users WHERE id = :id');
            $stmt->execute(['id' => $userId]);
            $_SESSION[self::SESSION_VERSION_KEY] = (int) $stmt->fetchColumn();
        }
    }

    public static function id(): ?int
    {
        return self::check() ? (int) $_SESSION[self::SESSION_KEY] : null;
    }

    public static function login(int $userId): void
    {
        if ($userId > 0) {
            // Id nou de sesiune înainte de logare (N15, WP-12b): coșul trece în sesiunea nouă, iar cine avea id-ul
            // de dinainte nu primește nicio logare, nici pe asta, nici una de dinainte (Sesiune::idNouLaLogare).
            Sesiune::idNouLaLogare();
            $_SESSION[self::SESSION_KEY] = $userId;
            $_SESSION[self::SESSION_VERSION_KEY] = 0;
            $db = Database::connection([]);
            if ($db instanceof PDO && self::areColoana($db)) {
                $stmt = $db->prepare('SELECT session_version FROM users WHERE id = :id');
                $stmt->execute(['id' => $userId]);
                $_SESSION[self::SESSION_VERSION_KEY] = (int) $stmt->fetchColumn();
            }
            self::$verificat[$userId] = true;
        }
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY], $_SESSION[self::SESSION_VERSION_KEY]);
    }

    public static function user(PDO $db): ?array
    {
        $userId = self::id();
        if ($userId === null || $userId <= 0 || !self::versiuneaEBuna($db, $userId)) {
            return null;
        }

        $stmt = $db->prepare(
            'SELECT id, first_name, last_name, email, phone, birth_date, gender, loyalty_points, created_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            self::logout();
            return null;
        }

        return $row;
    }
}

