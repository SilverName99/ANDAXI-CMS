<?php

declare(strict_types=1);

namespace App\Support\MailPoetCompat;

use App\Support\CadruCapturat;
use App\Support\IpClient;
use PDO;
use Throwable;

/**
 * Bugetul cererilor routerului MailPoet (Router::LIMITA_MINUT pe minut, pe vizitator; pista E, SEC-E-05). Ca LimitaCereri, un rând
 * în `register_submit_logs` pe cerere, cu starea `l:mailpoet` (nearătată în jurnalul înregistrărilor din admin), dar fără să
 * păstreze cine a cerut: în locul adresei, amprenta vizitatorului (`mp:` + 32 de caractere hex: HMAC-SHA256 al rețelei lui,
 * IpClient::cheieLimita — adresa IPv4, rețeaua /64 a unei adrese IPv6 —, cu cheia cadrului capturat; fără cheie, adresa nu se poate
 * reface din ea), fără user agent. Fiecare cerere șterge întâi rândurile routerului mai vechi de PASTRARE_MINUTE (bugetul privește
 * doar ultimul minut), deci tabelul ține cel mult o oră de cereri ale routerului. Prefixul `mp:` (o adresă IP nu începe niciodată
 * așa) ține ștergerea și numărarea pe indexul (ip_address, created_at): doar rândurile routerului, nu tot jurnalul.
 *
 * Fără adresă, fără cheie sau fără tabel: true (nu oprim linkurile din emailuri pentru o unealtă de protecție).
 */
final class BugetRouter
{
    public const STARE = 'l:mailpoet';
    public const PREFIX = 'mp:';
    public const PASTRARE_MINUTE = 60;

    /** Încă e loc în bugetul ultimului minut? Dacă da, notează cererea (doar amprenta) și întoarce true; altfel false. */
    public static function incearca(PDO $db, int $maxim): bool
    {
        $amprenta = self::amprenta(IpClient::adresa());
        if ($amprenta === null || $maxim <= 0) {
            return true;
        }
        try {
            $db->prepare("DELETE FROM register_submit_logs WHERE ip_address LIKE 'mp:%' AND status = ? AND created_at < (NOW() - INTERVAL "
                . self::PASTRARE_MINUTE . ' MINUTE)')->execute([self::STARE]);
            $q = $db->prepare('SELECT COUNT(*) FROM register_submit_logs WHERE ip_address = ? AND status = ? AND created_at >= (NOW() - INTERVAL 1 MINUTE)');
            $q->execute([$amprenta, self::STARE]);
            if ((int) $q->fetchColumn() >= $maxim) {
                return false;
            }
            $db->prepare('INSERT INTO register_submit_logs (ip_address, user_agent, status, created_at) VALUES (?, NULL, ?, NOW())')
                ->execute([$amprenta, self::STARE]);
        } catch (Throwable) {
            return true;
        }
        return true;
    }

    /** Amprenta unui vizitator (`mp:` + HMAC-SHA256 al rețelei, primele 32 de caractere hex); null fără adresă sau fără cheie. */
    public static function amprenta(string $ip): ?string
    {
        $ip = trim($ip);
        $cheie = CadruCapturat::cheie();
        if ($ip === '' || $cheie === '') {
            return null;
        }
        return self::PREFIX . substr(hash_hmac('sha256', 'mailpoet-buget|' . IpClient::cheieLimita($ip), $cheie), 0, 32);
    }
}
