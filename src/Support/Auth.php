<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

final class Auth
{
    public const ROLE_GENERAL = 'administrator_general';
    public const ROLE_STORE = 'administrator_magazin';
    public const ROLE_BLOG = 'administrator_blog';
    public const ROLE_EMAILS = 'administrator_emailuri';

    public static function roles(): array
    {
        return [
            self::ROLE_GENERAL => 'Administrator General',
            // Pe un site cu catalog, dar fără magazin, rolul se ocupă doar de produse.
            self::ROLE_STORE => Plugins::vizibil('magazin') || !Plugins::vizibil('catalog') ? 'Administrator magazin' : 'Administrator produse',
            self::ROLE_BLOG => 'Administrator Blog',
            self::ROLE_EMAILS => 'Administrator email-uri',
        ];
    }

    /**
     * Cât stă un administrator logat fără să facă nimic.
     *
     * Sesiunea ține acum 30 de zile, ca să nu se golească coșul cumpărătorului.
     * Pentru administrare ar fi prea mult: un calculator lăsat deschis ar
     * rămâne cu panoul la îndemâna oricui o lună întreagă. Deci partea de
     * administrare are ceasul ei, care se oprește separat — fără să atingă
     * coșul sau contul cumpărătorului din aceeași sesiune.
     */
    private const INACTIVITATE = 60 * 60 * 12; // 12 ore

    /** Rândul din Jurnal activitate scris când o parolă veche necriptată se criptează la logare (WP-12b). */
    public const JURNAL_PAROLA_CONVERTITA = 'parola_convertita';

    public static function check(): bool
    {
        if (!isset($_SESSION['admin_id'])) {
            return false;
        }

        $ultima = (int) ($_SESSION['admin_last_seen'] ?? 0);
        if ($ultima > 0 && (time() - $ultima) > self::INACTIVITATE) {
            self::logout();
            return false;
        }
        $_SESSION['admin_last_seen'] = time();
        return true;
    }

    /**
     * E logat un administrator în sesiunea asta? Doar citește: spre deosebire
     * de `check()`, nu reîmprospătează ceasul de inactivitate și nu deloghează
     * pe nimeni. Pentru paginile publice (checkout-ul), unde vizita clientului
     * nu are voie să țină în viață — sau să închidă — sesiunea de admin.
     */
    public static function esteLogatFaraReimprospatare(): bool
    {
        if (!isset($_SESSION['admin_id']) || (int) $_SESSION['admin_id'] <= 0) {
            return false;
        }
        $ultima = (int) ($_SESSION['admin_last_seen'] ?? 0);
        return !($ultima > 0 && (time() - $ultima) > self::INACTIVITATE);
    }

    /**
     * Administrator general logat, verificat doar prin citire (vezi mai sus).
     * `isGeneralAdmin()` singur nu ajunge: fără nimeni logat, rolul implicit
     * din sesiune e chiar „general".
     */
    public static function esteAdminGeneralFaraReimprospatare(): bool
    {
        return self::esteLogatFaraReimprospatare() && self::isGeneralAdmin();
    }

    public static function id(): ?int
    {
        return isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
    }

    public static function attempt(PDO $db, string $email, string $password): bool
    {
        self::ensureSchema($db);

        $stmt = $db->prepare('SELECT id, password_hash, role, roles_json FROM admins WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => strtolower(trim($email))]);
        $admin = $stmt->fetch();

        if (!$admin) {
            return false;
        }

        // Felul parolei salvate (G-M5, WP-12b; regula în ParolaAdmin): un hash se verifică doar ca hash, inclusiv
        // argon2 și bcrypt-ul vechi $2a$/$2b$; ce are forma unui hash, dar nu e unul pe care îl verificăm, nu intră
        // cu nicio parolă și nu se compară niciodată în clar. Doar o parolă veche salvată necriptat se compară în
        // clar, și se criptează la prima logare.
        $id = (int) $admin['id'];
        $stored = (string) $admin['password_hash'];
        $fel = ParolaAdmin::fel($stored);
        if ($fel === ParolaAdmin::GOL || $fel === ParolaAdmin::HASH_STRICAT) {
            return false;
        }
        $isValid = $fel === ParolaAdmin::TEXT_CLAR ? hash_equals($stored, $password) : password_verify($password, $stored);

        if (!$isValid) {
            return false;
        }

        if ($fel === ParolaAdmin::TEXT_CLAR) {
            if (self::rescrieParola($db, $id, $stored, $password)) {
                AdminActivityLog::log($db, self::JURNAL_PAROLA_CONVERTITA, ['rezultat' => 'era salvată necriptat; acum e criptată'], $id);
            }
        } elseif (ParolaAdmin::deRefacut($stored)) {
            self::rescrieParola($db, $id, $stored, $password);
        }

        $roles = self::rolesFromStorage(
            (string) ($admin['role'] ?? self::ROLE_GENERAL),
            $admin['roles_json'] ?? null
        );
        // Id nou de sesiune înainte de logare (N15): cine avea id-ul de dinainte nu primește nicio logare, nici pe
        // asta, nici una de dinainte din același browser (Sesiune::idNouLaLogare).
        Sesiune::idNouLaLogare();
        $_SESSION['admin_id'] = $id;
        $_SESSION['admin_roles'] = $roles;
        $_SESSION['admin_role'] = self::primaryRole($roles);
        $_SESSION['admin_last_seen'] = time();
        return true;
    }

    /**
     * Rescrie parola salvată cu `password_hash(PASSWORD_DEFAULT)` după o logare reușită (o parolă necriptată, un
     * bcrypt vechi, argon2 sau un cost mai mic decât cel implicit; `ParolaAdmin::deRefacut`). Doar dacă rândul are
     * încă valoarea verificată (altfel cineva a schimbat parola între timp și rămâne a lui). Întoarce true dacă
     * rândul s-a schimbat; o eroare a bazei nu oprește logarea (se încearcă din nou la următoarea).
     */
    private static function rescrieParola(PDO $db, int $id, string $vechi, string $parola): bool
    {
        try {
            $stmt = $db->prepare('UPDATE admins SET password_hash = :nou
                WHERE id = :id AND CAST(password_hash AS BINARY) = CAST(:vechi AS BINARY)');
            $stmt->execute(['nou' => password_hash($parola, PASSWORD_DEFAULT), 'id' => $id, 'vechi' => $vechi]);
            return $stmt->rowCount() === 1;
        } catch (Throwable) {
            return false;
        }
    }

    public static function logout(): void
    {
        unset($_SESSION['admin_id']);
        unset($_SESSION['admin_roles']);
        unset($_SESSION['admin_role']);
        unset($_SESSION['admin_last_seen']);
    }

    public static function role(): string
    {
        return self::primaryRole(self::currentRoles());
    }

    public static function currentRoles(): array
    {
        $legacyRole = (string) ($_SESSION['admin_role'] ?? self::ROLE_GENERAL);
        $sessionRoles = $_SESSION['admin_roles'] ?? null;
        if (is_array($sessionRoles)) {
            return self::normalizeRoles($sessionRoles, $legacyRole);
        }

        return self::normalizeRoles([$legacyRole], self::ROLE_GENERAL);
    }

    public static function primaryRole(array $roles): string
    {
        $normalized = self::normalizeRoles($roles, self::ROLE_GENERAL);
        $priority = [
            self::ROLE_GENERAL,
            self::ROLE_EMAILS,
            self::ROLE_STORE,
            self::ROLE_BLOG,
        ];
        foreach ($priority as $role) {
            if (in_array($role, $normalized, true)) {
                return $role;
            }
        }

        return $normalized[0] ?? self::ROLE_GENERAL;
    }

    public static function isGeneralAdmin(): bool
    {
        return self::hasRole(self::ROLE_GENERAL);
    }

    public static function hasRole(string $role): bool
    {
        $role = self::normalizeRole($role);
        return in_array($role, self::currentRoles(), true);
    }

    public static function hasAnyRole(array $roles): bool
    {
        if ($roles === []) {
            return true;
        }
        $current = self::currentRoles();
        foreach ($roles as $role) {
            $normalizedRole = self::normalizeRole((string) $role);
            if (in_array($normalizedRole, $current, true)) {
                return true;
            }
        }
        return false;
    }

    public static function normalizeRole(string $role): string
    {
        $role = trim($role);
        if (!array_key_exists($role, self::roles())) {
            return self::ROLE_GENERAL;
        }

        return $role;
    }

    public static function normalizeRoles(array $roles, string $fallbackRole = self::ROLE_GENERAL): array
    {
        $normalized = [];
        foreach ($roles as $role) {
            $value = trim((string) $role);
            if ($value === '' || !array_key_exists($value, self::roles())) {
                continue;
            }
            if (!in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }
        if ($normalized === []) {
            $normalized[] = self::normalizeRole($fallbackRole);
        }

        return $normalized;
    }

    public static function rolesFromStorage(string $legacyRole, mixed $rolesJson): array
    {
        $roles = [];
        if (is_array($rolesJson)) {
            $roles = $rolesJson;
        } elseif (is_string($rolesJson) && trim($rolesJson) !== '') {
            $decoded = json_decode($rolesJson, true);
            if (is_array($decoded)) {
                $roles = $decoded;
            }
        }
        $roles[] = $legacyRole;

        return self::normalizeRoles($roles, $legacyRole);
    }

    public static function defaultPathForRole(string|array $role = ''): string
    {
        $roles = [];
        if (is_array($role)) {
            $roles = self::normalizeRoles($role, self::ROLE_GENERAL);
        } elseif ($role !== '') {
            $roles = [self::normalizeRole((string) $role)];
        } else {
            $roles = self::currentRoles();
        }

        if (in_array(self::ROLE_GENERAL, $roles, true)) {
            return '/admin';
        }
        // Prima pagină a rolului pe care site-ul o are în licență: un administrator
        // de magazin pe un site fără catalog (sau de blog fără blog) nu ajunge pe
        // „Modulul nu este inclus în abonament”. Cu toate pluginurile, ca înainte.
        $candidati = [
            self::ROLE_EMAILS => ['/admin/emails/newsletters?tab=templates', '/admin/blog/posts', '/admin/emails/sender'],
            self::ROLE_STORE => ['/admin/products'],
            self::ROLE_BLOG => ['/admin/blog/posts', '/admin/gallery'],
        ];
        $prima = null;
        foreach ($candidati as $rol => $cai) {
            if (!in_array($rol, $roles, true)) {
                continue;
            }
            foreach ($cai as $cale) {
                $prima ??= $cale;
                if (Plugins::nimicAscuns() || Plugins::adresaVizibila($cale)) {
                    return $cale;
                }
            }
        }

        return $prima ?? '/admin';
    }

    public static function canAccessPath(string $path, string|array $role = ''): bool
    {
        $path = parse_url($path, PHP_URL_PATH) ?: '/admin';
        $roles = [];
        if (is_array($role)) {
            $roles = self::normalizeRoles($role, self::ROLE_GENERAL);
        } elseif ($role !== '') {
            $roles = [self::normalizeRole((string) $role)];
        } else {
            $roles = self::currentRoles();
        }

        if (in_array(self::ROLE_GENERAL, $roles, true)) {
            return true;
        }
        // Dashboard-ul arată date din toată aplicația: îl vede doar generalul.
        if ($path === '/admin') {
            return false;
        }

        foreach ($roles as $currentRole) {
            if (self::canAccessPathForRole($path, $currentRole)) {
                return true;
            }
        }

        return false;
    }

    private static function canAccessPathForRole(string $path, string $role): bool
    {
        $allowedPrefixes = match ($role) {
            // Exact intrările fostului grup „Magazin” al meniului, nimic altceva (din WP-30 ele stau în grupurile Comenzi,
            // Produse, Marketing — cupoanele — și Setări — coșul flotant; rolul nu se schimbă).
            self::ROLE_STORE => [
                '/admin/products',
                '/admin/categories',
                '/admin/products/fields',
                '/admin/products/templates',
                '/admin/products/reviews',
                '/admin/orders',
                // WP-26 (GR-15): cererile de ofertă stau lângă comenzi (pe un catalog fără magazin, ele sunt comenzile).
                '/admin/cereri-oferta',
                '/admin/coupons',
                '/admin/promo-products',
                '/admin/settings/floating-cart',
            ],
            self::ROLE_BLOG => [
                '/admin/blog/posts',
                '/admin/blog/templates',
                '/admin/blog/authors',
                '/admin/gallery',
            ],
            self::ROLE_EMAILS => [
                '/admin/blog/posts',
                '/admin/blog/templates',
                '/admin/blog/authors',
                '/admin/gallery',
                '/admin/emails',
            ],
            default => [],
        };

        foreach ($allowedPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                if ($role === self::ROLE_BLOG && (
                    str_starts_with($path, '/admin/users')
                    || str_starts_with($path, '/admin/products')
                    || str_starts_with($path, '/admin/orders')
                    || str_starts_with($path, '/admin/settings')
                    || str_starts_with($path, '/admin/emails')
                )) {
                    return false;
                }
                if ($role === self::ROLE_EMAILS && (
                    str_starts_with($path, '/admin/users')
                    || str_starts_with($path, '/admin/products')
                    || str_starts_with($path, '/admin/orders')
                    || str_starts_with($path, '/admin/settings')
                )) {
                    return false;
                }
                return true;
            }
        }

        return false;
    }

    private static function ensureSchema(PDO $db): void
    {
        if (\App\Support\Migrari\Garda::stare($db) === 'la_zi') { return; } // R1: schema o fac migrările (docs/MIGRARI.md)
        try {
            $db->exec('ALTER TABLE admins ADD COLUMN role VARCHAR(50) NOT NULL DEFAULT "administrator_general" AFTER password_hash');
        } catch (Throwable) {
        }
        try {
            $db->exec('UPDATE admins SET role = "administrator_general" WHERE role IS NULL OR role = ""');
        } catch (Throwable) {
        }
        try {
            $db->exec('ALTER TABLE admins ADD COLUMN roles_json LONGTEXT DEFAULT NULL AFTER role');
        } catch (Throwable) {
        }
        try {
            $rows = $db->query('SELECT id, role, roles_json FROM admins')->fetchAll() ?: [];
            $update = $db->prepare('UPDATE admins SET role = :role, roles_json = :roles_json WHERE id = :id');
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $roles = self::rolesFromStorage((string) ($row['role'] ?? self::ROLE_GENERAL), $row['roles_json'] ?? null);
                $primaryRole = self::primaryRole($roles);
                $rolesJson = json_encode($roles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($rolesJson === false) {
                    continue;
                }
                $update->execute([
                    'id' => (int) ($row['id'] ?? 0),
                    'role' => $primaryRole,
                    'roles_json' => $rolesJson,
                ]);
            }
        } catch (Throwable) {
        }
    }
}
