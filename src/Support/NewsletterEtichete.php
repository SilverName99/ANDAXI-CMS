<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Etichetele abonaților newsletterului (migrarea newsletter/0082; docs/PLUGINURI.md, „Automatizări newsletter”): un nume
 * scurt pus pe un abonat („a cumpărat ceai”, „reactivat”). Le pun și le scot pașii automatizărilor; „Primește o etichetă”
 * pornește o automatizare. O etichetă nouă pe un abonat cheamă punctul `newsletter.eticheta_adaugata` (App\Support\Hooks),
 * deci fără pluginul automatizărilor nu se întâmplă nimic în plus.
 *
 * Doar abonații (newsletter_subscribers) au etichete: un client care nu e abonat nu primește niciuna.
 */
final class NewsletterEtichete
{
    public const LUNGIME_MAXIMA = 80;

    /** Tabelele există (o bază la zi cu migrarea newsletter/0082)? */
    public static function disponibil(PDO $db): bool
    {
        try {
            $db->query('SELECT 1 FROM newsletter_etichete LIMIT 1');
            $db->query('SELECT 1 FROM newsletter_abonati_etichete LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Numele curățat: fără spații la capete și în plus, fără caractere de control, cel mult 80 de litere. */
    public static function curataNume(string $nume): string
    {
        $nume = preg_replace('/[\x00-\x1F\x7F]+/u', '', $nume) ?? '';
        $nume = trim(preg_replace('/\s+/u', ' ', $nume) ?? '');
        return mb_substr($nume, 0, self::LUNGIME_MAXIMA);
    }

    /** @return list<array{id: int, nume: string, abonati: int}> */
    public static function lista(PDO $db): array
    {
        try {
            $randuri = $db->query(
                'SELECT e.id, e.nume, COUNT(ae.subscriber_id) AS abonati
                 FROM newsletter_etichete e
                 LEFT JOIN newsletter_abonati_etichete ae ON ae.eticheta_id = e.id
                 GROUP BY e.id, e.nume
                 ORDER BY e.nume ASC'
            )->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'nume' => (string) $r['nume'], 'abonati' => (int) $r['abonati'],
        ], $randuri);
    }

    public static function nume(PDO $db, int $etichetaId): string
    {
        $stmt = $db->prepare('SELECT nume FROM newsletter_etichete WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $etichetaId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    /** Id-ul etichetei cu numele dat (fără deosebire între litere mari și mici); cu `$creeaza`, o face dacă lipsește. */
    public static function idDupaNume(PDO $db, string $nume, bool $creeaza = true): int
    {
        $nume = self::curataNume($nume);
        if ($nume === '') {
            return 0;
        }
        $cauta = $db->prepare('SELECT id FROM newsletter_etichete WHERE nume = :nume LIMIT 1');
        $cauta->execute(['nume' => $nume]);
        $id = (int) ($cauta->fetchColumn() ?: 0);
        if ($id > 0 || !$creeaza) {
            return $id;
        }
        $db->prepare('INSERT IGNORE INTO newsletter_etichete (nume) VALUES (:nume)')->execute(['nume' => $nume]);
        $cauta->execute(['nume' => $nume]);
        return (int) ($cauta->fetchColumn() ?: 0);
    }

    public static function are(PDO $db, int $abonatId, int $etichetaId): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM newsletter_abonati_etichete WHERE subscriber_id = :s AND eticheta_id = :e LIMIT 1');
        $stmt->execute(['s' => $abonatId, 'e' => $etichetaId]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return list<int> etichetele abonatului */
    public static function aleAbonatului(PDO $db, int $abonatId): array
    {
        $stmt = $db->prepare('SELECT eticheta_id FROM newsletter_abonati_etichete WHERE subscriber_id = :s ORDER BY eticheta_id');
        $stmt->execute(['s' => $abonatId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Pune eticheta pe abonat. True doar dacă n-o avea: atunci (după scriere) cheamă `newsletter.eticheta_adaugata`, iar
     * automatizările „Primește o etichetă” pot porni. `$acum` e ora de scris (ceasul automatizărilor, în teste înghețat).
     */
    public static function adauga(PDO $db, int $abonatId, int $etichetaId, ?string $acum = null): bool
    {
        if ($abonatId <= 0 || $etichetaId <= 0) {
            return false;
        }
        $stmt = $db->prepare(
            'INSERT IGNORE INTO newsletter_abonati_etichete (subscriber_id, eticheta_id, created_at) VALUES (:s, :e, :c)'
        );
        $stmt->execute(['s' => $abonatId, 'e' => $etichetaId, 'c' => $acum ?? date('Y-m-d H:i:s')]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        Hooks::ruleaza('newsletter.eticheta_adaugata', $db, $abonatId, $etichetaId);
        return true;
    }

    /** Scoate eticheta; true dacă o avea. */
    public static function scoate(PDO $db, int $abonatId, int $etichetaId): bool
    {
        $stmt = $db->prepare('DELETE FROM newsletter_abonati_etichete WHERE subscriber_id = :s AND eticheta_id = :e');
        $stmt->execute(['s' => $abonatId, 'e' => $etichetaId]);
        return $stmt->rowCount() > 0;
    }
}
