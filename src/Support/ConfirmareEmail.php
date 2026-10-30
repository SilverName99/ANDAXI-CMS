<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Confirmarea adresei de email a conturilor de client (WP-25: VT-C9, decizia OD-19) și regula comenzilor fără cont
 * (G-M4, decizia OD-47; aceeași în toate cele patru coduri vechi, N14).
 *
 * **Regula comenzilor fără cont (mereu, fără setare):** o comandă plasată fără cont (`user_id IS NULL`) cu adresa unui
 * cont apare în contul lui (și îi completează adresa de facturare) doar după ce clientul a dovedit că adresa e a lui
 * (`users.email_confirmed_at`). Dovada: linkul de confirmare (trimis doar când îl cere clientul, din cont), un link de
 * resetare a parolei folosit (emailul resetării dovedește adresa) sau o logare cu Google (care refuză deja o adresă
 * neverificată). La confirmare, comenzile fără cont cu acea adresă se leagă de cont (`orders.user_id`), cu un rând în
 * Jurnal activitate; tot așa revin comenzile dezlegate la ștergerea unui cont, doar la cine dovedește adresa.
 *
 * **Confirmarea la înregistrare** (setarea `customer_email_confirmation_required`, oprită implicit): contul creat cât e
 * pornită primește linkul pe email și nu intră până nu-l folosește (conturile de dinainte intră ca înainte). Linkurile
 * sunt valabile 7 zile, o singură dată; în bază stă doar hash-ul sha256 al tokenului. Retrimiterile au bugetul lor
 * (5 la 15 minute de la aceeași adresă, VT-C8), separat de cel al înregistrărilor.
 *
 * Pe o bază încă nemigrată (fără `conturi_clienti/0036`) nimeni nu e confirmat: comenzile fără cont nu se arată, nimic
 * nu cade.
 */
final class ConfirmareEmail
{
    public const ZILE_VALABIL = 7;
    public const RETRIMITERI_LA_15_MINUTE = 5;
    public const INREGISTRARI_LA_15_MINUTE = 8;
    public const SCOP_INREGISTRARE = 'inregistrare';
    public const SCOP_COMENZI = 'comenzi';
    public const TEXT_IMPLICIT_COMENZI = 'Ai comenzi mai vechi făcute cu această adresă. Confirmă adresa ca să le vezi aici.';

    private static ?bool $areSchema = null;

    /** Migrarea conturi_clienti/0036 a rulat (coloana și tabelul există)? O dată pe cerere. */
    public static function areSchema(PDO $db): bool
    {
        if (self::$areSchema === null) {
            try {
                $stmt = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                    AND ((TABLE_NAME = 'users' AND COLUMN_NAME = 'email_confirmed_at')
                      OR (TABLE_NAME = 'customer_email_confirmations' AND COLUMN_NAME = 'scop'))");
                self::$areSchema = $stmt !== false && (int) $stmt->fetchColumn() === 2;
            } catch (Throwable) {
                self::$areSchema = false;
            }
        }
        return self::$areSchema;
    }

    /** Clientul a dovedit adresa? */
    public static function confirmat(PDO $db, int $idUtilizator): bool
    {
        if ($idUtilizator <= 0 || !self::areSchema($db)) {
            return false;
        }
        $stmt = $db->prepare('SELECT email_confirmed_at FROM users WHERE id = :id');
        $stmt->execute(['id' => $idUtilizator]);
        return trim((string) ($stmt->fetchColumn() ?: '')) !== '';
    }

    /**
     * Condiția comenzilor unui cont, pentru `WHERE` (cu parametrii ei): cele legate de cont și, doar după confirmare, și
     * cele fără cont cu adresa lui.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function conditieComenzi(PDO $db, int $idUtilizator, string $email): array
    {
        if (self::confirmat($db, $idUtilizator)) {
            return ['(user_id = :user_id OR (user_id IS NULL AND billing_email = :email))', ['user_id' => $idUtilizator, 'email' => $email]];
        }
        return ['user_id = :user_id', ['user_id' => $idUtilizator]];
    }

    /** Câte comenzi fără cont are adresa unui cont neconfirmat (pentru rândul din cont); 0 dacă e confirmat. */
    public static function comenziAscunse(PDO $db, int $idUtilizator, string $email): int
    {
        if ($idUtilizator <= 0 || trim($email) === '' || self::confirmat($db, $idUtilizator)) {
            return 0;
        }
        try {
            $stmt = $db->prepare('SELECT COUNT(*) FROM orders WHERE user_id IS NULL AND billing_email = :email AND deleted_at IS NULL');
            $stmt->execute(['email' => $email]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Marchează adresa ca dovedită (dacă nu era) și leagă de cont comenzile fără cont cu ea. `$sursa`: „link”,
     * „resetare”, „google”. Întoarce câte comenzi s-au legat.
     */
    public static function marcheazaConfirmat(PDO $db, int $idUtilizator, string $sursa): int
    {
        if ($idUtilizator <= 0 || !self::areSchema($db)) {
            return 0;
        }
        $stmt = $db->prepare('SELECT email, email_confirmed_at FROM users WHERE id = :id');
        $stmt->execute(['id' => $idUtilizator]);
        $rand = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($rand)) {
            return 0;
        }
        $email = trim((string) ($rand['email'] ?? ''));
        if (trim((string) ($rand['email_confirmed_at'] ?? '')) === '') {
            $db->prepare('UPDATE users SET email_confirmed_at = NOW() WHERE id = :id AND email_confirmed_at IS NULL')
                ->execute(['id' => $idUtilizator]);
        }
        if ($email === '') {
            return 0;
        }
        $leaga = $db->prepare('UPDATE orders SET user_id = :id WHERE user_id IS NULL AND billing_email = :email AND deleted_at IS NULL');
        $leaga->execute(['id' => $idUtilizator, 'email' => $email]);
        $legate = $leaga->rowCount();
        if ($legate > 0) {
            AdminActivityLog::log($db, 'cont_comenzi_legate', [
                'user_id' => $idUtilizator,
                'comenzi' => $legate,
                'dovada' => $sursa,
            ]);
        }
        return $legate;
    }

    /**
     * Un link nou de confirmare pentru cont: tokenul (64 de caractere hex) se întoarce o singură dată; în bază, doar
     * hash-ul lui. Null pe o bază nemigrată.
     */
    public static function linkNou(PDO $db, int $idUtilizator, string $scop): ?string
    {
        if ($idUtilizator <= 0 || !self::areSchema($db)) {
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $db->prepare('INSERT INTO customer_email_confirmations (user_id, token_hash, scop, created_at) VALUES (:id, :hash, :scop, NOW())')
            ->execute(['id' => $idUtilizator, 'hash' => hash('sha256', $token), 'scop' => $scop === self::SCOP_COMENZI ? $scop : self::SCOP_INREGISTRARE]);
        return $token;
    }

    /**
     * Folosește un link: valabil (nefolosit, din ultimele 7 zile) → contul confirmat, comenzile legate; întoarce id-ul
     * contului. Un link expirat, folosit sau necunoscut nu confirmă nimic (null).
     */
    public static function folosesteLink(PDO $db, string $token): ?int
    {
        $token = trim($token);
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1 || !self::areSchema($db)) {
            return null;
        }
        $stmt = $db->prepare('SELECT id, user_id FROM customer_email_confirmations
            WHERE token_hash = :hash AND used_at IS NULL AND created_at >= (NOW() - INTERVAL ' . self::ZILE_VALABIL . ' DAY) LIMIT 1');
        $stmt->execute(['hash' => hash('sha256', $token)]);
        $rand = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($rand)) {
            return null;
        }
        $marcat = $db->prepare('UPDATE customer_email_confirmations SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
        $marcat->execute(['id' => (int) $rand['id']]);
        if ($marcat->rowCount() !== 1) {
            return null; // folosit de altă cerere în aceeași clipă
        }
        $idUtilizator = (int) $rand['user_id'];
        self::marcheazaConfirmat($db, $idUtilizator, 'link');
        return $idUtilizator;
    }

    /**
     * La logare, cu `customer_email_confirmation_required` pornită: contul creat cât era pornită și neconfirmat încă nu
     * intră (are un link de înregistrare nefolosit). Conturile de dinainte (fără un asemenea link) intră ca înainte.
     */
    public static function blocheazaLogarea(PDO $db, array $setari, int $idUtilizator): bool
    {
        if ((string) ($setari['customer_email_confirmation_required'] ?? '0') !== '1' || !self::areSchema($db)
            || self::confirmat($db, $idUtilizator)) {
            return false;
        }
        $stmt = $db->prepare('SELECT COUNT(*) FROM customer_email_confirmations WHERE user_id = :id AND scop = :scop');
        $stmt->execute(['id' => $idUtilizator, 'scop' => self::SCOP_INREGISTRARE]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Bugetul înregistrărilor și al retrimiterilor de la aceeași adresă (`register_submit_logs`, 15 minute; VT-C8): cele
     * două se numără separat (rândurile „resend” sunt ale retrimiterilor), ca un client care a încercat de câteva ori să
     * se înscrie să-și mai poată cere linkul.
     */
    public static function limitat(PDO $db, string $ip, string $scop = 'register'): bool
    {
        if ($ip === '') {
            return false;
        }
        $retrimitere = $scop === 'resend';
        try {
            [$acelasiVizitator, $parametri] = IpClient::conditieLimita('ip_address', $ip);
            $stmt = $db->prepare('SELECT COUNT(*) FROM register_submit_logs WHERE ' . $acelasiVizitator
                . ' AND created_at >= (NOW() - INTERVAL 15 MINUTE) AND ' . ($retrimitere ? "status = 'resend'" : "(status IS NULL OR status <> 'resend')"));
            $stmt->execute($parametri);
            return (int) $stmt->fetchColumn() >= ($retrimitere ? self::RETRIMITERI_LA_15_MINUTE : self::INREGISTRARI_LA_15_MINUTE);
        } catch (Throwable) {
            return false;
        }
    }

    /** Notează o retrimitere în bugetul ei. */
    public static function noteazaRetrimitere(PDO $db, string $ip): void
    {
        try {
            $db->prepare("INSERT INTO register_submit_logs (ip_address, user_agent, status, created_at) VALUES (:ip, :ua, 'resend', NOW())")
                ->execute([
                    'ip' => $ip !== '' ? $ip : null,
                    'ua' => mb_substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255) ?: null,
                ]);
        } catch (Throwable) {
        }
    }

    /** Emailul cu linkul de confirmare (BrandedEmail, ca emailurile de cont). */
    public static function trimiteEmail(PDO $db, array $setari, array $utilizator, string $token): void
    {
        $adresa = trim((string) ($utilizator['email'] ?? ''));
        if ($adresa === '' || !filter_var($adresa, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $link = AppUrl::base() . '/cont/confirmare/' . $token;
        $html = BrandedEmail::render($setari, [
            'title' => 'Confirmă adresa de email',
            'greeting' => 'Bună' . (trim((string) ($utilizator['first_name'] ?? '')) !== '' ? ', ' . trim((string) $utilizator['first_name']) : '') . '!',
            'intro' => 'Apasă butonul de mai jos ca să confirmi că această adresă îți aparține. Linkul e valabil '
                . self::ZILE_VALABIL . ' zile și se poate folosi o singură dată.',
            'button' => ['label' => 'Confirmă adresa', 'url' => $link],
            'note' => 'Dacă nu tu ai cerut acest email, îl poți ignora.',
            'fallback_url' => $link,
        ]);
        OrderMailer::sendCustom($adresa, 'Confirmă adresa de email', $html, $setari, $db, [
            'email_type' => 'customer_email_confirmation',
            'source' => 'cont',
        ]);
    }

    /** Doar pentru teste. */
    public static function reseteaza(): void
    {
        self::$areSchema = null;
    }
}
