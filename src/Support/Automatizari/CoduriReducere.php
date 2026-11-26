<?php

declare(strict_types=1);

namespace App\Support\Automatizari;

use App\Support\NewsletterService;
use PDO;

/**
 * Pasul „Cod de reducere”: un cupon unic, de o singură folosire, în sistemul de cupoane al magazinului (tabelul `coupons`,
 * pluginul `cupoane`: `is_unique` = 1, `max_uses_total` = 1, valabil N zile, lotul `automatizare-<id>` vizibil în Cupoane →
 * Coduri unice). Codul se pune în emailurile de după el cu `{{cod_reducere}}`.
 *
 * Idempotent: codul e făcut din semnătura (cheia secretă a newsletterului) rulării și a pasului, deci o rulare reluată după
 * o cădere ajunge la același cod, iar INSERT IGNORE nu face un al doilea cupon.
 */
final class CoduriReducere
{
    /** @return array{cod: string, tip: string, valoare: float, valoare_text: string, expira: string, expira_text: string} */
    public static function genereaza(PDO $db, array $automatizare, array $rulare, string $pas, array $cfg): array
    {
        $tip = (string) ($cfg['tip'] ?? 'percent');
        $valoare = (float) ($cfg['valoare'] ?? 10);
        $prefix = (string) ($cfg['prefix'] ?? '');
        $semnatura = NewsletterService::semneaza('automatizare|cod|' . (int) $automatizare['id'] . '|' . (int) $rulare['id'] . '|' . $pas);
        $corp = strtoupper(substr((string) preg_replace('/[^A-Za-z0-9]/', '', $semnatura), 0, 10));
        // Fără litere care se confundă la tastare (O/0, I/1).
        $corp = strtr($corp, ['O' => 'Q', '0' => '8', 'I' => 'K', '1' => '7']);
        $cod = ($prefix !== '' ? $prefix . '-' : '') . $corp;
        $acum = Ceas::acum();
        $expira = $acum->modify('+' . max(1, (int) ($cfg['zile'] ?? 14)) . ' days')->setTime(23, 59, 59);
        $existent = $db->prepare('SELECT ends_at FROM coupons WHERE code = :c LIMIT 1');
        $existent->execute(['c' => $cod]);
        $gasit = $existent->fetchColumn();
        if ($gasit === false) {
            $db->prepare(
                'INSERT IGNORE INTO coupons (name, code, type, value, max_uses_total, is_unique, import_batch, stacks_with_discounts,
                    is_active, starts_at, ends_at)
                 VALUES (:n, :c, :t, :v, 1, 1, :lot, :cum, 1, :de, :pana)'
            )->execute([
                'n' => mb_substr('Automatizare: ' . (string) $automatizare['nume'], 0, 190),
                'c' => $cod, 't' => $tip === 'fixed' ? 'fixed' : 'percent', 'v' => $valoare,
                'lot' => 'automatizare-' . (int) $automatizare['id'],
                'cum' => !empty($cfg['cumulabil']) ? 1 : 0,
                'de' => Ceas::sql($acum->modify('-1 minute')),
                'pana' => Ceas::sql($expira),
            ]);
        } elseif (is_string($gasit) && $gasit !== '') {
            $expira = new \DateTimeImmutable($gasit);
        }
        return [
            'cod' => $cod, 'tip' => $tip, 'valoare' => $valoare,
            'valoare_text' => Catalog::textReducere($tip, $valoare),
            'expira' => Ceas::sql($expira), 'expira_text' => $expira->format('d.m.Y'),
        ];
    }
}
