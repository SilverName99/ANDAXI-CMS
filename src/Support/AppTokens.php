<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Chei de acces pentru aplicația de desktop.
 *
 * Aplicația nu poate folosi sesiunea din browser, așa că fiecare administrator
 * își generează din admin o cheie proprie, pe care o lipește o dată în program.
 * Cheia se arată o singură dată; în baza de date rămâne doar amprenta ei, deci
 * nici cine are acces la baza de date n-o poate folosi.
 *
 * Cheia poartă drepturile administratorului care a generat-o și poate fi
 * retrasă oricând, fără să afecteze pe altcineva.
 *
 * Pluginul `fisiere_cloud` (din Biovitality; docs/PLUGINURI.md, „Fișiere în cloud”). Formatul cheii (`bvd_` + 48 hex),
 * amprenta (sha256) și antetele sunt cele ale codului Biovitality, ca aplicația Windows instalată și cheile deja date să
 * meargă neschimbate. Tabelul `admin_app_tokens` îl face migrarea `fisiere_cloud/0059` (adoptivă: pe Biovitality există).
 */
final class AppTokens
{
    /** Prefix vizibil, ca să se recunoască dintr-o privire ce e șirul lipit. */
    private const PREFIX = 'bvd_';

    /**
     * Câte chei active poate avea un administrator deodată (una pe calculator; revizia de securitate: fără plafon, o
     * sesiune furată putea lăsa în urmă oricâte chei).
     */
    public const MAX_ACTIVE = 10;

    /**
     * Generează o cheie nouă. Șirul întors e singura dată când se vede în clar.
     *
     * @return array{id: int, token: string}|null
     */
    public static function issue(PDO $db, int $adminId, string $name): ?array
    {
        if ($adminId <= 0) {
            return null;
        }

        $name = trim($name);
        if ($name === '') {
            $name = 'Calculatorul meu';
        }
        if (self::activeCount($db, $adminId) >= self::MAX_ACTIVE) {
            return null;
        }

        try {
            $token = self::PREFIX . bin2hex(random_bytes(24));
            $stmt = $db->prepare(
                'INSERT INTO admin_app_tokens (admin_id, name, token_hash) VALUES (:admin_id, :name, :token_hash)'
            );
            $stmt->execute([
                'admin_id' => $adminId,
                'name' => mb_substr($name, 0, 120),
                'token_hash' => self::fingerprint($token),
            ]);

            return ['id' => (int) $db->lastInsertId(), 'token' => $token];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Administratorul din spatele unei chei, sau null dacă e greșită/retrasă.
     * Actualizează și data ultimei folosiri, ca să se vadă cheile moarte.
     *
     * @return array{admin_id: int, email: string, roles: list<string>, token_id: int, token_name: string}|null
     */
    public static function authenticate(PDO $db, string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !str_starts_with($token, self::PREFIX)) {
            return null;
        }

        try {
            $stmt = $db->prepare(
                'SELECT t.id AS token_id, t.name AS token_name, t.admin_id, t.token_hash,
                        a.email, a.role, a.roles_json
                   FROM admin_app_tokens t
                   JOIN admins a ON a.id = t.admin_id
                  WHERE t.token_hash = :h AND t.revoked_at IS NULL
                  LIMIT 1'
            );
            $stmt->execute(['h' => self::fingerprint($token)]);
            $row = $stmt->fetch();
        } catch (Throwable) {
            return null;
        }

        // Căutarea e după amprentă (nu după cheie), deci nu spune nimic despre cheie; în plus, amprenta găsită se compară
        // în timp constant, octet cu octet (coloana are colația tabelului, care nu ține cont de litere mari și mici).
        if (!is_array($row) || !hash_equals((string) ($row['token_hash'] ?? ''), self::fingerprint($token))) {
            return null;
        }

        try {
            $touch = $db->prepare('UPDATE admin_app_tokens SET last_used_at = NOW(), last_used_ip = :ip WHERE id = :id');
            $touch->execute([
                'ip' => mb_substr(self::clientIp(), 0, 45),
                'id' => (int) $row['token_id'],
            ]);
        } catch (Throwable) {
        }

        return [
            'admin_id' => (int) $row['admin_id'],
            'email' => (string) ($row['email'] ?? ''),
            'roles' => Auth::rolesFromStorage((string) ($row['role'] ?? Auth::ROLE_GENERAL), $row['roles_json'] ?? null),
            'token_id' => (int) $row['token_id'],
            'token_name' => (string) ($row['token_name'] ?? ''),
        ];
    }

    /** Retrage o cheie. Un administrator își poate retrage doar cheile proprii. */
    public static function revoke(PDO $db, int $tokenId, int $adminId, bool $anyAdmin = false): bool
    {
        if ($tokenId <= 0) {
            return false;
        }

        try {
            $sql = 'UPDATE admin_app_tokens SET revoked_at = NOW() WHERE id = :id AND revoked_at IS NULL';
            $params = ['id' => $tokenId];
            if (!$anyAdmin) {
                $sql .= ' AND admin_id = :admin_id';
                $params['admin_id'] = $adminId;
            }
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            return $stmt->rowCount() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Cheile unui administrator (fără cele retrase), cele noi întâi.
     *
     * @return list<array<string, mixed>>
     */
    public static function forAdmin(PDO $db, int $adminId): array
    {
        try {
            $stmt = $db->prepare(
                'SELECT id, name, last_used_at, last_used_ip, created_at
                   FROM admin_app_tokens
                  WHERE admin_id = :admin_id AND revoked_at IS NULL
                  ORDER BY id DESC'
            );
            $stmt->execute(['admin_id' => $adminId]);

            return $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** Câte chei neretrase are administratorul (la eroare, plafonul: nu se mai dă nicio cheie). */
    public static function activeCount(PDO $db, int $adminId): int
    {
        try {
            $stmt = $db->prepare('SELECT COUNT(*) FROM admin_app_tokens WHERE admin_id = :admin_id AND revoked_at IS NULL');
            $stmt->execute(['admin_id' => $adminId]);

            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return self::MAX_ACTIVE;
        }
    }

    /**
     * Cheile active ale tuturor administratorilor, pentru Administratorul General (care le poate retrage pe oricare).
     * Fără amprente.
     *
     * @return list<array<string, mixed>>
     */
    public static function allActive(PDO $db): array
    {
        try {
            $rows = $db->query(
                'SELECT t.id, t.admin_id, t.name, t.last_used_at, t.last_used_ip, t.created_at, a.email
                   FROM admin_app_tokens t
                   LEFT JOIN admins a ON a.id = t.admin_id
                  WHERE t.revoked_at IS NULL
                  ORDER BY a.email ASC, t.id DESC'
            )->fetchAll();

            return is_array($rows) ? array_values($rows) : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** Cheia trimisă de aplicație: antet „Authorization: Bearer …" sau „X-App-Token". */
    public static function fromRequest(): string
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            $raw = getallheaders();
            if (is_array($raw)) {
                foreach ($raw as $key => $value) {
                    $headers[strtolower((string) $key)] = (string) $value;
                }
            }
        }

        // REDIRECT_HTTP_AUTHORIZATION: unele servere (Apache cu PHP ca CGI/FPM) dau antetul doar așa, după rescrierea din
        // .htaccess.
        $authorization = $headers['authorization']
            ?? (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if ($authorization !== '' && preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $m) === 1) {
            return trim($m[1]);
        }

        $direct = $headers['x-app-token'] ?? (string) ($_SERVER['HTTP_X_APP_TOKEN'] ?? '');

        return trim($direct);
    }

    private static function fingerprint(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Adresa vizitatorului, după regula App\Support\IpClient (cea a legăturii, sau a proxy-ului de încredere). */
    private static function clientIp(): string
    {
        return IpClient::adresa();
    }
}
