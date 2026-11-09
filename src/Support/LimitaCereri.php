<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Bugetul unei acțiuni publice pe vizitator (adresa lui, o rețea IPv6 /64 ca una, IpClient::conditieLimita), în ultimele
 * minute: de exemplu donațiile pornite din sertar (pluginul `donatii`) sau întoarcerile de la Stripe ale cotizației (`membri`),
 * fiecare o cerere spre Stripe. Rândurile stau în `register_submit_logs`, cu starea `l:<scop>` (numărate separat de
 * înregistrări și de retrimiteri, ConfirmareEmail::limitat; nearătate în jurnalul înregistrărilor din admin).
 */
final class LimitaCereri
{
    public const PREFIX = 'l:';

    /**
     * Încă e loc în buget? Dacă da, notează cererea și întoarce true; altfel false (nimic notat). Fără adresă sau fără tabel:
     * true (nu oprim site-ul pentru o unealtă de protecție).
     */
    public static function incearca(PDO $db, string $scop, int $maxim, int $minute = 15): bool
    {
        $ip = mb_substr(IpClient::adresa(), 0, 64);
        $stare = self::PREFIX . mb_substr($scop, 0, 18);
        if ($ip === '' || $maxim <= 0) {
            return true;
        }
        try {
            [$acelasiVizitator, $parametri] = IpClient::conditieLimita('ip_address', $ip);
            $stmt = $db->prepare('SELECT COUNT(*) FROM register_submit_logs WHERE ' . $acelasiVizitator
                . ' AND status = :stare AND created_at >= (NOW() - INTERVAL ' . max(1, $minute) . ' MINUTE)');
            $stmt->execute($parametri + ['stare' => $stare]);
            if ((int) $stmt->fetchColumn() >= $maxim) {
                return false;
            }
            $db->prepare('INSERT INTO register_submit_logs (ip_address, user_agent, status, created_at) VALUES (:ip, :ua, :stare, NOW())')
                ->execute([
                    'ip' => $ip,
                    'ua' => mb_substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255) ?: null,
                    'stare' => $stare,
                ]);
        } catch (Throwable) {
            return true;
        }
        return true;
    }
}
