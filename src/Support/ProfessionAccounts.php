<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Conturi profesionale: meserii configurabile din admin, câmpuri dinamice per meserie,
 * cereri de verificare (aprobate manual de un administrator) și activare anuală plătită
 * prin Stripe.
 *
 * Pluginul `membri` (din vertij d3439a5 … ffa3d9c, 50ef812, af8ab87, 7d7d8cc, la 9ab7999): copiat cum e, fără
 * `ensureSchema` (tabelele și coloanele contului le face migrarea `membri/0063`, niciun DDL pe cerere), cu dosarul
 * documentelor cerut de la `Paths` (azi tot `storage/account-documents`, în Etapa 2 `site/storage/`) și cu prefixul
 * implicit al codului de membru neutru („M”, nu numele unui client: pe vertij `account_member_code_prefix` e înghețat
 * „SVTH”, ca azi).
 */
final class ProfessionAccounts
{
    public const STATUS_AWAITING_EMAIL = 'awaiting_email';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const FIELD_TYPES = ['text', 'textarea', 'number', 'date', 'select', 'checkbox', 'file'];

    /** Duratele de cotizație oferite implicit, în luni. */
    public const DEFAULT_DURATIONS = [1, 3, 6, 12];

    /** Dosarul documentelor, în storage/ al site-ului (Paths): nu stau în public/, se servesc doar printr-o rută de admin. */
    public const DOSAR_DOCUMENTE = 'account-documents';

    /** Prefixul codului de membru fără setare (vertij: „SVTH”, din înghețare). */
    public const PREFIX_COD_IMPLICIT = 'M';

    private const DEFAULT_ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    // ---------------------------------------------------------------- meserii

    /** @return array<int, array<string, mixed>> */
    public static function professions(?PDO $db, bool $onlyActive = true): array
    {
        if (!$db instanceof PDO) {
            return [];
        }

        try {
            $sql = 'SELECT * FROM professions';
            if ($onlyActive) {
                $sql .= ' WHERE is_active = 1';
            }
            $sql .= ' ORDER BY sort_order ASC, name ASC';
            $rows = $db->query($sql)?->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (Throwable) {
            return [];
        }
    }

    public static function profession(?PDO $db, int $professionId): ?array
    {
        if (!$db instanceof PDO || $professionId <= 0) {
            return null;
        }

        try {
            $stmt = $db->prepare('SELECT * FROM professions WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $professionId]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function professionFields(?PDO $db, int $professionId, bool $onlyActive = true): array
    {
        if (!$db instanceof PDO || $professionId <= 0) {
            return [];
        }

        try {
            $sql = 'SELECT * FROM profession_fields WHERE profession_id = :pid';
            if ($onlyActive) {
                $sql .= ' AND is_active = 1';
            }
            $sql .= ' ORDER BY sort_order ASC, id ASC';
            $stmt = $db->prepare($sql);
            $stmt->execute(['pid' => $professionId]);
            $rows = $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as $index => $row) {
            $rows[$index]['options'] = self::decodeOptions((string) ($row['options_json'] ?? ''));
        }

        return $rows;
    }

    /** @return array<int, string> */
    public static function decodeOptions(string $optionsJson): array
    {
        if (trim($optionsJson) === '') {
            return [];
        }

        $decoded = json_decode($optionsJson, true);
        if (!is_array($decoded)) {
            return [];
        }

        $options = [];
        foreach ($decoded as $option) {
            $value = trim((string) $option);
            if ($value !== '') {
                $options[] = $value;
            }
        }

        return $options;
    }

    /** Transformă textul din admin (o opțiune pe linie) în JSON. */
    public static function encodeOptions(string $raw): string
    {
        $options = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $value = trim((string) $line);
            if ($value !== '') {
                $options[] = $value;
            }
        }

        return $options === [] ? '' : (string) json_encode($options, JSON_UNESCAPED_UNICODE);
    }

    public static function normalizeFieldType(string $type): string
    {
        $type = strtolower(trim($type));
        return in_array($type, self::FIELD_TYPES, true) ? $type : 'text';
    }

    /** Cheie stabilă folosită în answers_json; nu se schimbă la redenumirea etichetei. */
    public static function normalizeFieldKey(string $raw, string $fallback = ''): string
    {
        $key = self::sluggifyKey($raw);
        if ($key === '') {
            $key = self::sluggifyKey($fallback);
        }

        return $key !== '' ? substr($key, 0, 100) : 'camp';
    }

    private static function sluggifyKey(string $raw): string
    {
        $value = strtr(mb_strtolower(trim($raw)), [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);
        $value = (string) preg_replace('/[^a-z0-9]+/', '_', $value);

        return trim($value, '_');
    }

    // ------------------------------------------------------------ verificări

    public static function latestVerification(?PDO $db, int $userId): ?array
    {
        if (!$db instanceof PDO || $userId <= 0) {
            return null;
        }

        try {
            $stmt = $db->prepare(
                'SELECT * FROM account_verifications WHERE user_id = :uid ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute(['uid' => $userId]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function verification(?PDO $db, int $verificationId): ?array
    {
        if (!$db instanceof PDO || $verificationId <= 0) {
            return null;
        }

        try {
            $stmt = $db->prepare(
                'SELECT v.*, u.first_name, u.last_name, u.email, u.phone, u.created_at AS user_created_at,
                        u.account_status, u.account_active_until, u.member_code
                 FROM account_verifications v
                 LEFT JOIN users u ON u.id = v.user_id
                 WHERE v.id = :id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $verificationId]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function documents(?PDO $db, int $verificationId): array
    {
        if (!$db instanceof PDO || $verificationId <= 0) {
            return [];
        }

        try {
            $stmt = $db->prepare(
                'SELECT * FROM account_verification_documents WHERE verification_id = :vid ORDER BY id ASC'
            );
            $stmt->execute(['vid' => $verificationId]);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed> Răspunsurile salvate, decodate. */
    public static function decodeAnswers(?string $answersJson): array
    {
        if ($answersJson === null || trim($answersJson) === '') {
            return [];
        }

        $decoded = json_decode($answersJson, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_AWAITING_EMAIL => 'Așteaptă confirmarea pe email',
            self::STATUS_PENDING => 'În așteptare',
            self::STATUS_APPROVED => 'Aprobat',
            self::STATUS_REJECTED => 'Respins',
            default => 'Fără cerere',
        };
    }

    // -------------------------------------------------------------- activare

    /**
     * Starea contului pentru un utilizator: dacă are cerere, dacă e aprobat,
     * dacă activarea e plătită și câte zile mai are.
     *
     * @return array<string, mixed>
     */
    public static function accountState(?PDO $db, ?array $user, array $settings = []): array
    {
        $state = [
            'has_account' => is_array($user),
            'verification' => null,
            'verification_status' => 'none',
            'profession' => null,
            'profession_name' => '',
            'activation_price' => 0.0,
            'activation_months' => 12,
            'duration_options' => [],
            'member_code' => '',
            'is_approved' => false,
            'is_active' => false,
            'active_until' => null,
            'active_until_label' => '',
            'days_left' => 0,
            'can_pay' => false,
            'review_note' => '',
            'can_reapply' => false,
        ];

        if (!$db instanceof PDO || !is_array($user)) {
            return $state;
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return $state;
        }

        $verification = self::latestVerification($db, $userId);
        $state['verification'] = $verification;
        $state['verification_status'] = is_array($verification)
            ? (string) ($verification['status'] ?? 'none')
            : 'none';
        $state['is_approved'] = $state['verification_status'] === self::STATUS_APPROVED;

        $professionId = (int) ($user['profession_id'] ?? 0);
        if ($professionId <= 0 && is_array($verification)) {
            $professionId = (int) ($verification['profession_id'] ?? 0);
        }
        $profession = self::profession($db, $professionId);
        if (is_array($profession)) {
            $state['profession'] = $profession;
            $state['profession_name'] = (string) ($profession['name'] ?? '');
            $state['activation_price'] = (float) ($profession['activation_price'] ?? 0);
            $months = (int) ($profession['activation_months'] ?? 12);
            $state['activation_months'] = $months > 0 ? $months : 12;
        } elseif (is_array($verification)) {
            $state['profession_name'] = (string) ($verification['profession_name'] ?? '');
        }

        $activeUntilRaw = trim((string) ($user['account_active_until'] ?? ''));
        if ($activeUntilRaw !== '') {
            $timestamp = strtotime($activeUntilRaw);
            if ($timestamp !== false) {
                $state['active_until'] = $activeUntilRaw;
                $state['active_until_label'] = date('d.m.Y', $timestamp);
                if ($timestamp > time()) {
                    $state['is_active'] = true;
                    $state['days_left'] = (int) ceil(($timestamp - time()) / 86400);
                }
            }
        }

        $state['member_code'] = trim((string) ($user['member_code'] ?? ''));
        $state['duration_options'] = self::durationOptions(
            (float) $state['activation_price'],
            (int) $state['activation_months'],
            $settings
        );

        $state['can_pay'] = $state['is_approved']
            && !$state['is_active']
            && $state['duration_options'] !== [];

        $state['review_note'] = is_array($verification)
            ? trim((string) ($verification['review_note'] ?? ''))
            : '';

        // O cerere respinsă nu e un capăt de drum: omul poate corecta documentele
        // și trimite una nouă. Cine are deja o cerere în lucru nu poate dubla.
        $state['can_reapply'] = !$state['is_active']
            && in_array($state['verification_status'], [self::STATUS_REJECTED, 'none'], true);

        return $state;
    }

    /** Un cont e „activ” doar dacă e aprobat de admin ȘI are cotizația plătită la zi. */
    public static function hasActiveAccount(?PDO $db, ?array $user): bool
    {
        $state = self::accountState($db, $user);
        return (bool) $state['is_approved'] && (bool) $state['is_active'];
    }

    // ------------------------------------------------------- durate cotizație

    /**
     * Duratele oferite la plată. Prețul configurat pe tipul de cont acoperă
     * `activation_months` (implicit 12), iar celelalte durate se calculează
     * proporțional — exact modelul din specificație: „cotizația pe an este 12 lei,
     * iar cea pentru o lună este 12/12 = 1 leu”.
     *
     * @return array<int, array{months: int, price: float, label: string}>
     */
    public static function durationOptions(float $basePrice, int $baseMonths, array $settings = []): array
    {
        if ($basePrice <= 0) {
            return [];
        }

        $baseMonths = $baseMonths > 0 ? $baseMonths : 12;
        $options = [];
        foreach (self::allowedDurations($settings) as $months) {
            $options[] = [
                'months' => $months,
                'price' => self::priceForMonths($basePrice, $baseMonths, $months),
                'label' => self::durationLabel($months),
            ];
        }

        return $options;
    }

    /** @return array<int, int> */
    public static function allowedDurations(array $settings = []): array
    {
        $raw = trim((string) ($settings['account_activation_durations'] ?? ''));
        if ($raw === '') {
            return self::DEFAULT_DURATIONS;
        }

        $durations = [];
        foreach (explode(',', $raw) as $part) {
            $months = (int) trim((string) $part);
            if ($months > 0 && $months <= 60) {
                $durations[] = $months;
            }
        }
        $durations = array_values(array_unique($durations));
        sort($durations);

        return $durations !== [] ? $durations : self::DEFAULT_DURATIONS;
    }

    public static function priceForMonths(float $basePrice, int $baseMonths, int $months): float
    {
        $baseMonths = $baseMonths > 0 ? $baseMonths : 12;
        $months = $months > 0 ? $months : 1;

        return round(($basePrice / $baseMonths) * $months, 2);
    }

    public static function durationLabel(int $months): string
    {
        if ($months === 12) {
            return '1 an';
        }
        if ($months % 12 === 0) {
            return ($months / 12) . ' ani';
        }

        return $months . ($months === 1 ? ' lună' : ' luni');
    }

    // ----------------------------------------------- ultima plată de activare

    public static function activationStatusLabel(string $status): string
    {
        return match (strtolower(trim($status))) {
            'paid' => 'Plătit',
            'failed' => 'Eșuat',
            'pending' => 'În așteptare',
            '' => '—',
            default => $status,
        };
    }

    /** Aceeași stare, dar ca badge colorat pentru tabelele din admin. */
    public static function activationStatusBadge(string $status): string
    {
        [$background, $color] = match (strtolower(trim($status))) {
            'paid' => ['#e7f6ee', '#12734a'],
            'failed' => ['#fdeaec', '#a11d33'],
            'pending' => ['#fdf1e3', '#9a5b12'],
            default => ['#eef1f6', '#5a6472'],
        };

        return '<span style="display:inline-block;padding:3px 10px;border-radius:999px;white-space:nowrap;'
            . 'background:' . $background . ';color:' . $color . ';font-size:12px;font-weight:700;">'
            . htmlspecialchars(self::activationStatusLabel($status), ENT_QUOTES)
            . '</span>';
    }

    /**
     * Coloanele ultimei plăți de activare, pentru listele din admin. Aliasurile
     * sunt prefixate cu `activation_` ca să nu se bată cu ale tabelei gazdă.
     */
    public static function latestActivationColumns(): string
    {
        return 'la.amount AS activation_amount,
                la.currency AS activation_currency,
                la.status AS activation_status,
                la.months AS activation_months,
                la.period_start AS activation_period_start,
                la.period_end AS activation_period_end,
                la.paid_at AS activation_paid_at';
    }

    /**
     * JOIN către ultima plată de activare a utilizatorului din `$userColumn`
     * (de forma `alias.coloana`). Plățile încasate au prioritate; dacă nu există
     * niciuna, rămâne ultima încercare, ca adminul să vadă și plățile eșuate.
     */
    public static function latestActivationJoin(string $userColumn): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/', $userColumn) !== 1) {
            $userColumn = 'NULL';
        }

        return ' LEFT JOIN account_activations la ON la.id = (
                     SELECT a2.id
                     FROM account_activations a2
                     WHERE a2.user_id = ' . $userColumn . '
                     ORDER BY (a2.paid_at IS NULL), a2.paid_at DESC, a2.id DESC
                     LIMIT 1
                 )';
    }

    // ---------------------------------------------------------- cod de membru

    /**
     * Cod unic afișat în cont și în dashboard. E derivat din id-ul contului, deci
     * rămâne stabil și lizibil (ex. M-00042).
     */
    public static function buildMemberCode(int $userId, array $settings = []): string
    {
        $prefix = strtoupper(trim((string) ($settings['account_member_code_prefix'] ?? self::PREFIX_COD_IMPLICIT)));
        $prefix = (string) preg_replace('/[^A-Z0-9]/', '', $prefix);
        if ($prefix === '') {
            $prefix = self::PREFIX_COD_IMPLICIT;
        }

        return $prefix . '-' . str_pad((string) max(1, $userId), 5, '0', STR_PAD_LEFT);
    }

    /** Atribuie codul de membru dacă lipsește. Întoarce codul contului. */
    public static function assignMemberCode(PDO $db, int $userId, array $settings = []): string
    {
        if ($userId <= 0) {
            return '';
        }

        try {
            $stmt = $db->prepare('SELECT member_code FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $existing = trim((string) ($stmt->fetchColumn() ?: ''));
            if ($existing !== '') {
                return $existing;
            }
        } catch (Throwable) {
            return '';
        }

        $code = self::buildMemberCode($userId, $settings);
        try {
            $update = $db->prepare('UPDATE users SET member_code = :code WHERE id = :id');
            $update->execute(['code' => $code, 'id' => $userId]);
        } catch (Throwable) {
            return '';
        }

        return $code;
    }

    /**
     * Extinde valabilitatea contului. Dacă mai are zile rămase, noua perioadă
     * pornește de la data expirării, ca să nu piardă nimic din ce a plătit.
     *
     * @return array{start: string, end: string}
     */
    public static function extendActivation(PDO $db, int $userId, int $months): array
    {
        $months = $months > 0 ? $months : 12;
        $start = time();

        try {
            $stmt = $db->prepare('SELECT account_active_until FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $current = trim((string) ($stmt->fetchColumn() ?: ''));
            if ($current !== '') {
                $currentTimestamp = strtotime($current);
                if ($currentTimestamp !== false && $currentTimestamp > $start) {
                    $start = $currentTimestamp;
                }
            }
        } catch (Throwable) {
        }

        $end = strtotime('+' . $months . ' months', $start);
        if ($end === false) {
            $end = $start + ($months * 30 * 86400);
        }

        $endFormatted = date('Y-m-d H:i:s', $end);

        try {
            $update = $db->prepare(
                'UPDATE users SET account_active_until = :until, account_status = :status WHERE id = :id'
            );
            $update->execute([
                'until' => $endFormatted,
                'status' => self::STATUS_APPROVED,
                'id' => $userId,
            ]);
        } catch (Throwable) {
        }

        return [
            'start' => date('Y-m-d H:i:s', $start),
            'end' => $endFormatted,
        ];
    }

    // --------------------------------------------------------------- upload

    public static function maxUploadBytes(array $settings): int
    {
        $mb = (float) ($settings['account_upload_max_mb'] ?? 8);
        if ($mb <= 0) {
            $mb = 8;
        }

        return (int) round($mb * 1024 * 1024);
    }

    /** @return array<int, string> */
    public static function allowedExtensions(array $settings): array
    {
        $raw = trim((string) ($settings['account_upload_allowed_ext'] ?? ''));
        if ($raw === '') {
            return self::DEFAULT_ALLOWED_EXTENSIONS;
        }

        $extensions = [];
        foreach (explode(',', $raw) as $part) {
            $ext = strtolower(trim((string) $part, " \t\n\r\0\x0B."));
            if ($ext !== '' && preg_match('/^[a-z0-9]{1,10}$/', $ext) === 1) {
                $extensions[] = $ext;
            }
        }

        return $extensions !== [] ? array_values(array_unique($extensions)) : self::DEFAULT_ALLOWED_EXTENSIONS;
    }

    public static function documentPath(string $storedName): string
    {
        return Paths::stocare(self::DOSAR_DOCUMENTE) . '/' . basename($storedName);
    }

    /** Documentele nu sunt servite direct de web server, deci numele poate fi opac. */
    public static function generateStoredName(string $extension): string
    {
        $extension = strtolower(trim($extension, '.'));
        if (preg_match('/^[a-z0-9]{1,10}$/', $extension) !== 1) {
            $extension = 'bin';
        }

        return date('Ymd') . '-' . bin2hex(random_bytes(16)) . '.' . $extension;
    }

    public static function ensureDocumentRoot(): bool
    {
        // Paths::stocareSite face dosarul (0770) și pune gardul .htaccess, ca vechiul „Require all denied”.
        $root = Paths::stocareSite(self::DOSAR_DOCUMENTE);

        return is_dir($root) && is_writable($root);
    }

    public static function humanFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0) . ' KB';
        }

        return $bytes . ' B';
    }
}
