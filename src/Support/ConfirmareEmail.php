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
 *
 * **Revizuirea WP-25** (blocantele G-M4):
 * - dovada e a unei adrese, nu a contului: linkul ține hash-ul adresei pentru care a plecat (`email_hash`, migrarea
 *   conturi_clienti/0038) și confirmă doar dacă adresa contului e tot aceea; o resetare a parolei și o logare cu Google
 *   confirmă doar adresa pe care au dovedit-o; schimbarea adresei (din cont sau din admin) șterge confirmarea
 *   (`adresaSchimbata`);
 * - linkul se deschide (GET) într-o pagină cu un buton și confirmă doar la apăsare (POST), ca un program care deschide
 *   linkurile din emailuri să nu confirme nimic; unul cerut din cont („comenzi”) cere logarea în contul lui, iar cel de
 *   înregistrare cere parola contului pe pagina lui (altfel cineva care își face cont cu adresa altuia și îl lasă pe
 *   proprietarul adresei să apese linkul ar primi comenzile și punctele lui; cine știe parola e cel care a făcut contul);
 * - punctele de fidelitate ale comenzilor fără cont (`loyalty_points_pending_claim`) se revendică doar la confirmare
 *   (aici), niciodată la înregistrare sau la logare fără adresa dovedită.
 */
final class ConfirmareEmail
{
    public const ZILE_VALABIL = 7;
    public const RETRIMITERI_LA_15_MINUTE = 5;
    public const INREGISTRARI_LA_15_MINUTE = 8;
    public const SCOP_INREGISTRARE = 'inregistrare';
    public const SCOP_COMENZI = 'comenzi';
    /** Rezultatele lui `folosesteLink`. */
    public const LINK_CONFIRMAT = 'confirmat';
    public const LINK_PAROLA = 'parola';
    public const LINK_INVALID = 'invalid';
    public const LINK_LOGARE = 'logare';
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

    private static ?bool $areHashAdresa = null;

    /** Migrarea conturi_clienti/0038 (linkul legat de adresă) a rulat? Fără ea niciun link nu confirmă. */
    private static function areHashAdresa(PDO $db): bool
    {
        if (self::$areHashAdresa === null) {
            try {
                $stmt = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'customer_email_confirmations' AND COLUMN_NAME = 'email_hash'");
                self::$areHashAdresa = $stmt !== false && (int) $stmt->fetchColumn() === 1;
            } catch (Throwable) {
                self::$areHashAdresa = false;
            }
        }
        return self::$areHashAdresa;
    }

    /** Hash-ul unei adrese, cum îl ține linkul (litere mici, fără spații). */
    public static function hashAdresa(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    /** Adresa de acum a contului ('' dacă nu există). */
    private static function adresaContului(PDO $db, int $idUtilizator): string
    {
        $stmt = $db->prepare('SELECT email FROM users WHERE id = :id');
        $stmt->execute(['id' => $idUtilizator]);
        return trim((string) ($stmt->fetchColumn() ?: ''));
    }

    /**
     * Adresa contului s-a schimbat (din cont sau din admin): confirmarea nu mai e valabilă (era a adresei de dinainte).
     * Comenzile deja legate rămân ale contului.
     */
    public static function adresaSchimbata(PDO $db, int $idUtilizator, string $emailVechi, string $emailNou): void
    {
        if ($idUtilizator <= 0 || !self::areSchema($db) || self::hashAdresa($emailVechi) === self::hashAdresa($emailNou)) {
            return;
        }
        $db->prepare('UPDATE users SET email_confirmed_at = NULL WHERE id = :id')->execute(['id' => $idUtilizator]);
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
     * Marchează adresa ca dovedită (dacă nu era), revendică punctele de fidelitate ale comenzilor fără cont cu ea și le
     * leagă de cont. `$sursa`: „link”, „resetare”, „google”; `$adresaDovedita`: adresa la care a ajuns dovada (linkul,
     * emailul resetării, adresa verificată de Google). Dacă nu e adresa de acum a contului, nu se confirmă nimic.
     *
     * @return array{comenzi: int, puncte: int}
     */
    public static function marcheazaConfirmat(PDO $db, int $idUtilizator, string $sursa, string $adresaDovedita): array
    {
        $nimic = ['comenzi' => 0, 'puncte' => 0];
        if ($idUtilizator <= 0 || !self::areSchema($db)) {
            return $nimic;
        }
        $email = self::adresaContului($db, $idUtilizator);
        if ($email === '' || trim($adresaDovedita) === '' || self::hashAdresa($email) !== self::hashAdresa($adresaDovedita)) {
            return $nimic;
        }
        $db->prepare('UPDATE users SET email_confirmed_at = NOW() WHERE id = :id AND email_confirmed_at IS NULL')
            ->execute(['id' => $idUtilizator]);
        // Punctele întâi: revendicarea leagă și ea comanda de cont (doar pe cele cu puncte în așteptare).
        $puncte = 0;
        try {
            $puncte = LoyaltyService::claimPendingPointsByEmail($db, $idUtilizator, strtolower($email));
        } catch (Throwable) {
            $puncte = 0;
        }
        $leaga = $db->prepare('UPDATE orders SET user_id = :id WHERE user_id IS NULL AND billing_email = :email AND deleted_at IS NULL');
        $leaga->execute(['id' => $idUtilizator, 'email' => $email]);
        $legate = $leaga->rowCount();
        if ($legate > 0 || $puncte > 0) {
            AdminActivityLog::log($db, 'cont_comenzi_legate', [
                'user_id' => $idUtilizator,
                'comenzi' => $legate,
                'puncte' => $puncte,
                'dovada' => $sursa,
            ]);
        }
        return ['comenzi' => $legate, 'puncte' => $puncte];
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
        $email = self::adresaContului($db, $idUtilizator);
        if ($email === '' || !self::areHashAdresa($db)) {
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $db->prepare('INSERT INTO customer_email_confirmations (user_id, token_hash, scop, email_hash, created_at)
            VALUES (:id, :hash, :scop, :adresa, NOW())')
            ->execute(['id' => $idUtilizator, 'hash' => hash('sha256', $token), 'scop' => $scop === self::SCOP_COMENZI ? $scop : self::SCOP_INREGISTRARE,
                'adresa' => self::hashAdresa($email)]);
        return $token;
    }

    /**
     * Linkul e bun acum (nefolosit, din ultimele 7 zile, pentru adresa de acum a contului)? Rândul lui (id, user_id,
     * scop) sau null. Nu folosește linkul (pagina deschisă de GET).
     *
     * @return array{id: int, user_id: int, scop: string}|null
     */
    public static function linkValid(PDO $db, string $token): ?array
    {
        $token = trim($token);
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1 || !self::areSchema($db) || !self::areHashAdresa($db)) {
            return null;
        }
        $stmt = $db->prepare('SELECT c.id, c.user_id, c.scop, c.email_hash, u.email FROM customer_email_confirmations c
            INNER JOIN users u ON u.id = c.user_id
            WHERE c.token_hash = :hash AND c.used_at IS NULL AND c.created_at >= (NOW() - INTERVAL ' . self::ZILE_VALABIL . ' DAY) LIMIT 1');
        $stmt->execute(['hash' => hash('sha256', $token)]);
        $rand = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($rand) || (string) ($rand['email_hash'] ?? '') === ''
            || !hash_equals((string) $rand['email_hash'], self::hashAdresa((string) ($rand['email'] ?? '')))) {
            return null;
        }
        return ['id' => (int) $rand['id'], 'user_id' => (int) $rand['user_id'], 'scop' => (string) $rand['scop']];
    }

    /**
     * Folosește un link (apăsarea butonului, POST). Rezultatul:
     *  - `invalid`: expirat, folosit, necunoscut sau pentru altă adresă decât cea de acum a contului (nimic schimbat);
     *  - `logare`: un link „comenzi”, dar clientul logat acum (`$idLogat`) nu e al contului (nimic schimbat, linkul rămâne);
     *  - `parola`: linkul de înregistrare fără parola corectă a contului (nimic schimbat, linkul rămâne);
     *  - `confirmat`: adresa dovedită, punctele revendicate, comenzile legate.
     *
     * @return array{rezultat: string, user_id: ?int, comenzi: int, puncte: int}
     */
    public static function folosesteLink(PDO $db, string $token, ?int $idLogat, string $parola = ''): array
    {
        $raspuns = ['rezultat' => self::LINK_INVALID, 'user_id' => null, 'comenzi' => 0, 'puncte' => 0];
        $link = self::linkValid($db, $token);
        if ($link === null) {
            return $raspuns;
        }
        $raspuns['user_id'] = $link['user_id'];
        if ($link['scop'] === self::SCOP_COMENZI && $idLogat !== $link['user_id']) {
            $raspuns['rezultat'] = self::LINK_LOGARE;
            return $raspuns;
        }
        if ($link['scop'] !== self::SCOP_COMENZI) {
            $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = :id');
            $stmt->execute(['id' => $link['user_id']]);
            if ($parola === '' || !WordPressPassword::verify($parola, (string) ($stmt->fetchColumn() ?: ''))) {
                $raspuns['rezultat'] = self::LINK_PAROLA;
                return $raspuns;
            }
        }
        $marcat = $db->prepare('UPDATE customer_email_confirmations SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
        $marcat->execute(['id' => $link['id']]);
        if ($marcat->rowCount() !== 1) {
            return ['rezultat' => self::LINK_INVALID, 'user_id' => null, 'comenzi' => 0, 'puncte' => 0]; // folosit chiar acum
        }
        $legate = self::marcheazaConfirmat($db, $link['user_id'], 'link', self::adresaContului($db, $link['user_id']));
        return ['rezultat' => self::LINK_CONFIRMAT, 'user_id' => $link['user_id']] + $legate;
    }

    /**
     * La logare, cu `customer_email_confirmation_required` pornită: contul creat cât era pornită și neconfirmat încă nu
     * intră (are un link de înregistrare). Conturile de dinainte (fără un asemenea link) intră ca înainte.
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
        self::$areHashAdresa = null;
    }
}
