<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Bugetul comun al trimiterilor de email (pista E a mutării unui site WooCommerce; docs/PLUGINURI.md, „Newsletter”): o „găleată cu
 * jetoane” în baza site-ului (`buget_trimitere`, migrarea newsletter/0077), aceeași pentru campanii, automatizări (pista J) și
 * confirmări, ca limita serverului de email (pe un site mutat de pe MailPoet, limita de trimitere importată din setările lui).
 *
 *  - `ia()` (campaniile, automatizările, emailul „Abonat nou” către administrator): într-o tranzacție, rândul blocat (`FOR UPDATE`),
 *    jetoanele umplute pe timpul trecut (`capacitate` pe `perioada_s` secunde, cel mult `capacitate`); cu cel puțin un jeton, unul se
 *    ia și emailul pleacă; altfel lotul se oprește și se reia la următoarea rulare a cronului (emailul „Abonat nou” nu mai pleacă);
 *  - `consuma()` (confirmările, linkul de dezabonare cerut din pagină): nu se amână niciodată, dar se numără
 *    (jetoanele pot coborî sub 0), ca MailPoet, care numără confirmările în fereastra lui fără să le oprească;
 *  - fără rândul bugetului (orice site care nu l-a configurat: toate cele de azi), nicio limită: `ia()` = true, `consuma()` nu face nimic.
 *
 * Diferența față de fereastra glisantă a lui MailPoet (o rafală de `capacitate`, apoi câte unul la `perioada_s / capacitate` secunde) e
 * declarată (VC-MP-14).
 */
final class BugetTrimitere
{
    public const IMPLICIT = 'smtp';

    /** @var array<string, bool> bugetele care nu există (citite o dată pe proces) */
    private static array $lipsa = [];

    /** Ia un jeton pentru un email care poate aștepta: true = poate pleca acum. */
    public static function ia(PDO $db, string $nume = self::IMPLICIT): bool
    {
        return self::schimba($db, $nume, false);
    }

    /** Numără un email care nu așteaptă (jetoanele pot coborî sub 0). */
    public static function consuma(PDO $db, string $nume = self::IMPLICIT): void
    {
        self::schimba($db, $nume, true);
    }

    /**
     * Bugetul (importul MailPoet: `mta.frequency.emails` pe `mta.frequency.interval` minute): capacitatea și perioada; un buget nou
     * pornește plin, unul existent își păstrează jetoanele (cel mult noua capacitate).
     */
    public static function configureaza(PDO $db, int $capacitate, int $perioadaS, string $nume = self::IMPLICIT): void
    {
        $capacitate = max(1, $capacitate);
        $perioadaS = max(1, $perioadaS);
        $db->prepare('INSERT INTO buget_trimitere (nume, capacitate, perioada_s, jetoane, actualizat_la) VALUES (?, ?, ?, ?, NOW(6))
            ON DUPLICATE KEY UPDATE capacitate = VALUES(capacitate), perioada_s = VALUES(perioada_s), jetoane = LEAST(jetoane, VALUES(capacitate))')
            ->execute([$nume, $capacitate, $perioadaS, $capacitate]);
        unset(self::$lipsa[$nume]);
    }

    /** Starea bugetului (pentru admin și teste), sau null fără el. @return array{capacitate: int, perioada_s: int, jetoane: float}|null */
    public static function stare(PDO $db, string $nume = self::IMPLICIT): ?array
    {
        try {
            $q = $db->prepare('SELECT capacitate, perioada_s, jetoane, TIMESTAMPDIFF(MICROSECOND, actualizat_la, NOW(6)) AS us FROM buget_trimitere WHERE nume = ?');
            $q->execute([$nume]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($r)) {
            return null;
        }
        return ['capacitate' => (int) $r['capacitate'], 'perioada_s' => (int) $r['perioada_s'], 'jetoane' => self::umplut($r)];
    }

    /** Pentru teste: uită bugetele citite ca lipsă. */
    public static function uita(): void
    {
        self::$lipsa = [];
    }

    private static function schimba(PDO $db, string $nume, bool $oricum): bool
    {
        if (isset(self::$lipsa[$nume])) {
            return true;
        }
        $propria = !$db->inTransaction();
        try {
            if ($propria) {
                $db->beginTransaction();
            }
            $q = $db->prepare('SELECT capacitate, perioada_s, jetoane, TIMESTAMPDIFF(MICROSECOND, actualizat_la, NOW(6)) AS us FROM buget_trimitere WHERE nume = ? FOR UPDATE');
            $q->execute([$nume]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($r)) {
                if ($propria) {
                    $db->commit();
                }
                self::$lipsa[$nume] = true;
                return true;
            }
            $jetoane = self::umplut($r);
            $poate = $oricum || $jetoane >= 1.0;
            $db->prepare('UPDATE buget_trimitere SET jetoane = ?, actualizat_la = NOW(6) WHERE nume = ?')
                ->execute([number_format($poate ? $jetoane - 1.0 : $jetoane, 4, '.', ''), $nume]);
            if ($propria) {
                $db->commit();
            }
            return $poate;
        } catch (Throwable $e) {
            if ($propria && $db->inTransaction()) {
                $db->rollBack();
            }
            // Fără tabel (o bază nemigrată) sau o eroare a bazei: nu oprim trimiterile pentru o unealtă de protecție (o dată pe proces).
            error_log('ANDAXI-CMS: bugetul trimiterilor nu se poate citi (' . get_class($e) . ')');
            self::$lipsa[$nume] = true;
            return true;
        }
    }

    /** Jetoanele de acum: cele salvate plus umplerea pe timpul trecut, cel mult capacitatea. @param array<string, mixed> $r */
    private static function umplut(array $r): float
    {
        $cap = max(1, (int) $r['capacitate']);
        $per = max(1, (int) $r['perioada_s']);
        $sec = max(0.0, (float) $r['us'] / 1000000.0);
        return min((float) $cap, (float) $r['jetoane'] + $sec * $cap / $per);
    }
}
