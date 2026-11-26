<?php

declare(strict_types=1);

namespace App\Support\Automatizari;

use App\Support\Plugins;
use App\Support\Settings;
use PDO;
use Throwable;

/**
 * Declanșatoarele: de unde intră oamenii într-o automatizare.
 *
 *  - din codul site-ului, prin punctele App\Support\Hooks (config/hooks.php, doar cu pluginul pornit): abonarea confirmată,
 *    eticheta pusă, contul creat, comanda plasată, schimbarea statusului unei comenzi;
 *  - din cron (Motor, la fiecare trecere): coșurile abandonate și clienții fără comandă de X zile.
 *
 * O agățare nu aruncă niciodată (o greșeală aici nu are voie să oprească o comandă sau o abonare): orice excepție se
 * notează în jurnalul PHP, fără date personale, și se merge mai departe.
 */
final class Declansatoare
{
    /** Statusurile unei comenzi care nu contează (anulată, eșuată, rambursată, returnată). */
    public const COMENZI_NULE = ['cancelled', 'failed', 'refunded', 'returned'];

    /** Coșurile mai vechi de atât nu mai intră (la pornirea unui flux, coșurile de acum o lună nu primesc nimic). */
    public const ZILE_COS_MAXIM = 3;

    /** Fereastra „a trecut de X zile fără comandă”: doar cine a trecut pragul în ultimele 30 de zile (nu toți clienții vechi deodată). */
    public const ZILE_FEREASTRA_INACTIV = 30;

    private static function sigur(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            error_log('Automatizari: declanșatorul a eșuat (' . get_class($e) . ' la ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
        }
    }

    public static function laAbonareConfirmata(PDO $db, int $abonatId, string $email): void
    {
        self::sigur(static function () use ($db, $abonatId, $email): void {
            if (!Flux::disponibil($db)) {
                return;
            }
            $confirmat = $db->prepare('SELECT confirmed_at FROM newsletter_subscribers WHERE id = :id LIMIT 1');
            $confirmat->execute(['id' => $abonatId]);
            $moment = (string) ($confirmat->fetchColumn() ?: Ceas::sql());
            foreach (Flux::activeCu($db, 'abonat_confirmat') as $a) {
                $lista = (int) ($a['setari']['lista_id'] ?? 0);
                if ($lista > 0 && !self::inLista($db, $abonatId, $lista)) {
                    continue;
                }
                Rulari::intra($db, $a, $email, [], 'abonare:' . $moment);
            }
        });
    }

    public static function laEtichetaAdaugata(PDO $db, int $abonatId, int $etichetaId): void
    {
        self::sigur(static function () use ($db, $abonatId, $etichetaId): void {
            if (!Flux::disponibil($db)) {
                return;
            }
            $email = self::emailAbonat($db, $abonatId);
            if ($email === '') {
                return;
            }
            foreach (Flux::activeCu($db, 'eticheta_primita') as $a) {
                if ((int) ($a['setari']['eticheta_id'] ?? 0) === $etichetaId) {
                    Rulari::intra($db, $a, $email, [], 'eticheta:' . $etichetaId . ':' . Ceas::sql());
                }
            }
        });
    }

    public static function laContCreat(PDO $db, int $utilizatorId, string $email): void
    {
        self::sigur(static function () use ($db, $utilizatorId, $email): void {
            if ($utilizatorId <= 0 || str_ends_with(strtolower($email), '@local.invalid') || !Flux::disponibil($db)) {
                return;
            }
            $prenume = $db->prepare('SELECT first_name FROM users WHERE id = :id LIMIT 1');
            $prenume->execute(['id' => $utilizatorId]);
            $context = ['prenume' => trim((string) ($prenume->fetchColumn() ?: ''))];
            foreach (Flux::activeCu($db, 'cont_creat') as $a) {
                Rulari::intra($db, $a, $email, $context, 'cont:' . $utilizatorId, $utilizatorId);
            }
        });
    }

    public static function laComandaPlasata(PDO $db, int $comandaId): void
    {
        self::sigur(static function () use ($db, $comandaId): void {
            if ($comandaId <= 0 || !Flux::disponibil($db)) {
                return;
            }
            $automatizari = Flux::activeCu($db, 'comanda_plasata');
            if ($automatizari === []) {
                return;
            }
            $comanda = self::comanda($db, $comandaId);
            if ($comanda === null) {
                return;
            }
            foreach ($automatizari as $a) {
                if (!self::comandaPotrivita($db, $comandaId, $a['setari'])) {
                    continue;
                }
                Rulari::intra($db, $a, $comanda['email'], self::contextComanda($comanda), 'comanda:' . $comandaId,
                    $comanda['user_id'] ?: null, $comandaId);
            }
        });
    }

    /** „Comanda e livrată / finalizată”: statusul trece în `completed` (Finalizată; din admin, la AWB, din ERP). */
    public static function laSchimbareStatus(PDO $db, int $comandaId, string $din, string $in): void
    {
        self::sigur(static function () use ($db, $comandaId, $din, $in): void {
            if ($in !== 'completed' || $din === 'completed' || $comandaId <= 0 || !Flux::disponibil($db)) {
                return;
            }
            $automatizari = Flux::activeCu($db, 'comanda_finalizata');
            if ($automatizari === []) {
                return;
            }
            $comanda = self::comanda($db, $comandaId);
            if ($comanda === null) {
                return;
            }
            foreach ($automatizari as $a) {
                if (!self::comandaPotrivita($db, $comandaId, $a['setari'])) {
                    continue;
                }
                if (!empty($a['setari']['doar_prima']) && self::areComandaAnterioara($db, $comanda)) {
                    continue;
                }
                Rulari::intra($db, $a, $comanda['email'], self::contextComanda($comanda), 'finalizata:' . $comandaId,
                    $comanda['user_id'] ?: null, $comandaId);
            }
        });
    }

    /**
     * Coșurile abandonate (cron): coșuri cu adresa unui abonat activ, nefinalizate, nevăzute de `dupa` minute (dar nu mai
     * vechi de 3 zile), fără o comandă a aceleiași adrese de la începutul coșului (sau din ultimele 7 zile: aceeași plasă ca
     * emailul clasic, EmailAutomation::sendDueAbandonedCartEmails).
     */
    public static function scaneazaCosuri(PDO $db, int $limita = 200): int
    {
        if (!Plugins::vizibil('magazin')) {
            return 0;
        }
        $intrate = 0;
        foreach (Flux::activeCu($db, 'cos_abandonat') as $a) {
            $minute = Catalog::minuteCos($a['setari']);
            $acum = Ceas::acum();
            $reintrare = !empty($a['permite_reintrarea']);
            $cheieSql = $reintrare
                ? "SHA2(CONCAT(LOWER(ca.email), '|cos:', ca.id, ':', DATE_FORMAT(ca.last_seen_at, '%Y-%m-%d %H:%i:%s')), 256)"
                : 'SHA2(LOWER(ca.email), 256)';
            $stmt = $db->prepare(
                "SELECT ca.id, ca.email, ca.customer_name, ca.last_seen_at
                 FROM cart_abandonments ca
                 JOIN newsletter_subscribers s ON s.email = ca.email AND s.status = 'active'
                 WHERE ca.converted_at IS NULL
                   AND ca.email IS NOT NULL AND ca.email <> ''
                   AND ca.last_seen_at <= :prag
                   AND ca.last_seen_at >= :cel_mai_vechi
                   AND NOT EXISTS (
                       SELECT 1 FROM orders o
                       WHERE o.billing_email = ca.email AND o.deleted_at IS NULL
                         AND o.status NOT IN ('cancelled', 'failed', 'refunded')
                         AND o.created_at >= LEAST(ca.created_at, :sapte_zile)
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM automatizari_rulari r WHERE r.automatizare_id = :a AND r.cheie_unica = {$cheieSql}
                   )
                 ORDER BY ca.last_seen_at ASC
                 LIMIT {$limita}"
            );
            $stmt->execute([
                'prag' => Ceas::sql($acum->modify('-' . $minute . ' minutes')),
                'cel_mai_vechi' => Ceas::sql($acum->modify('-' . self::ZILE_COS_MAXIM . ' days')),
                'sapte_zile' => Ceas::sql($acum->modify('-7 days')),
                'a' => (int) $a['id'],
            ]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $cos) {
                $context = ['cos_id' => (int) $cos['id'], 'prenume' => trim((string) strtok(trim((string) ($cos['customer_name'] ?? '')), ' '))];
                $eveniment = 'cos:' . (int) $cos['id'] . ':' . (string) $cos['last_seen_at'];
                if (Rulari::intra($db, $a, (string) $cos['email'], $context, $eveniment) !== null) {
                    $intrate++;
                    // Coșul e al automatizării: emailul clasic de coș abandonat nu-l mai ia niciodată (nici după ce
                    // automatizarea se oprește), deci omul nu primește două emailuri pentru același coș.
                    $db->prepare(
                        "UPDATE cart_abandonments SET abandoned_email_sent_at = :acum, abandoned_email_error = 'automatizare'
                         WHERE id = :id AND abandoned_email_sent_at IS NULL"
                    )->execute(['acum' => Ceas::sql(), 'id' => (int) $cos['id']]);
                }
            }
        }
        return $intrate;
    }

    /**
     * Clienții fără comandă de X zile (cron, cel mult o dată pe oră): abonații activi a căror ultimă comandă (nu anulată) a
     * trecut de X zile în ultimele 30 de zile. Cu reintrarea, cheia e ultima comandă: după o comandă nouă și alte X zile, din nou.
     */
    public static function scaneazaInactivi(PDO $db, int $limita = 200, bool $oricand = false): int
    {
        if (!Plugins::vizibil('magazin')) {
            return 0;
        }
        $automatizari = Flux::activeCu($db, 'client_inactiv');
        if ($automatizari === []) {
            return 0;
        }
        $acum = Ceas::acum();
        $ultima = (string) (Settings::all($db)['automatizari_scanare_inactivi'] ?? '');
        if (!$oricand && $ultima !== '' && strtotime($ultima) !== false && strtotime($ultima) > $acum->getTimestamp() - 3600
            && strtotime($ultima) <= $acum->getTimestamp()) {
            return 0;
        }
        Settings::save($db, ['automatizari_scanare_inactivi' => Ceas::sql($acum)]);
        $intrate = 0;
        $nule = "'" . implode("', '", self::COMENZI_NULE) . "'";
        foreach ($automatizari as $a) {
            $zile = (int) ($a['setari']['zile'] ?? 90);
            $reintrare = !empty($a['permite_reintrarea']);
            $cheieSql = $reintrare ? "SHA2(CONCAT(LOWER(u.email), '|inactiv:', u.ultima_id), 256)" : 'SHA2(LOWER(u.email), 256)';
            $stmt = $db->prepare(
                "SELECT u.email, u.ultima_id, u.ultima FROM (
                     SELECT LOWER(o.billing_email) AS email, MAX(o.created_at) AS ultima, MAX(o.id) AS ultima_id
                     FROM orders o
                     WHERE o.deleted_at IS NULL AND o.status NOT IN ({$nule}) AND o.billing_email IS NOT NULL AND o.billing_email <> ''
                     GROUP BY LOWER(o.billing_email)
                 ) u
                 JOIN newsletter_subscribers s ON s.email = u.email AND s.status = 'active'
                 WHERE u.ultima <= :prag AND u.ultima >= :fereastra
                   AND NOT EXISTS (SELECT 1 FROM automatizari_rulari r WHERE r.automatizare_id = :a AND r.cheie_unica = {$cheieSql})
                 ORDER BY u.ultima ASC
                 LIMIT {$limita}"
            );
            $stmt->execute([
                'prag' => Ceas::sql($acum->modify('-' . $zile . ' days')),
                'fereastra' => Ceas::sql($acum->modify('-' . ($zile + self::ZILE_FEREASTRA_INACTIV) . ' days')),
                'a' => (int) $a['id'],
            ]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $client) {
                $comanda = self::comanda($db, (int) $client['ultima_id']);
                $context = $comanda !== null ? ['prenume' => $comanda['prenume'], 'ultima_comanda' => $comanda['numar']] : [];
                if (Rulari::intra($db, $a, (string) $client['email'], $context, 'inactiv:' . (int) $client['ultima_id']) !== null) {
                    $intrate++;
                }
            }
        }
        return $intrate;
    }

    /**
     * Regula „fără emailuri duble” la coșul abandonat: cât pluginul e pornit și are o automatizare „Coș abandonat” activă,
     * coșurile abonaților activi sunt ale ei, iar emailul clasic (Emailuri de comandă → Coș abandonat,
     * EmailAutomation::sendDueAbandonedCartEmails) le sare. Coșurile celor care nu sunt abonați primesc emailul clasic ca
     * până acum (dacă e pornit). Fără plugin sau fără o astfel de automatizare: nimic nu se schimbă.
     */
    public static function preiaCosurileAbonatilor(PDO $db): bool
    {
        if (!Plugins::activ('automatizari')) {
            return false;
        }
        try {
            if (!Flux::disponibil($db)) {
                return false;
            }
            return Flux::activeCu($db, 'cos_abandonat') !== [];
        } catch (Throwable) {
            return false;
        }
    }

    // ── Ajutoare ──────────────────────────────────────────────────────────────────────────────────────────────────────

    public static function inLista(PDO $db, int $abonatId, int $listaId): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM newsletter_list_subscribers WHERE list_id = :l AND subscriber_id = :s LIMIT 1');
        $stmt->execute(['l' => $listaId, 's' => $abonatId]);
        return (bool) $stmt->fetchColumn();
    }

    private static function emailAbonat(PDO $db, int $abonatId): string
    {
        $stmt = $db->prepare('SELECT email FROM newsletter_subscribers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $abonatId]);
        return strtolower(trim((string) ($stmt->fetchColumn() ?: '')));
    }

    /** @return array{id: int, email: string, prenume: string, numar: string, total: float, status: string, user_id: int, created_at: string}|null */
    public static function comanda(PDO $db, int $comandaId): ?array
    {
        $stmt = $db->prepare(
            'SELECT id, order_number, billing_email, billing_first_name, total, status, user_id, created_at
             FROM orders WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['id' => $comandaId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($r) || trim((string) $r['billing_email']) === '') {
            return null;
        }
        return ['id' => (int) $r['id'], 'email' => strtolower(trim((string) $r['billing_email'])),
            'prenume' => trim((string) ($r['billing_first_name'] ?? '')), 'numar' => (string) ($r['order_number'] ?? ''),
            'total' => (float) ($r['total'] ?? 0), 'status' => (string) ($r['status'] ?? ''), 'user_id' => (int) ($r['user_id'] ?? 0),
            'created_at' => (string) ($r['created_at'] ?? '')];
    }

    private static function contextComanda(array $comanda): array
    {
        return ['prenume' => $comanda['prenume'], 'numar_comanda' => $comanda['numar'], 'total_comanda' => $comanda['total']];
    }

    /**
     * Comanda are cel puțin un produs dintre cele alese sau dintr-o categorie aleasă (principală sau suplimentară)? Fără
     * produse și categorii alese, orice comandă.
     */
    public static function comandaPotrivita(PDO $db, int $comandaId, array $cfg): bool
    {
        $produse = Catalog::iduri($cfg['produse'] ?? []);
        $categorii = Catalog::iduri($cfg['categorii'] ?? []);
        if ($produse === [] && $categorii === []) {
            return true;
        }
        return self::produseInComenzi($db, 'oi.order_id = ?', [$comandaId], $produse, $categorii);
    }

    /**
     * Există o linie de comandă (din comenzile alese de `$unde`) cu un produs din listă sau dintr-o categorie din listă?
     *
     * @param list<mixed> $parametri
     * @param list<int> $produse
     * @param list<int> $categorii
     */
    public static function produseInComenzi(PDO $db, string $unde, array $parametri, array $produse, array $categorii): bool
    {
        $conditii = [];
        if ($produse !== []) {
            $conditii[] = 'oi.product_id IN (' . implode(',', array_fill(0, count($produse), '?')) . ')';
            $parametri = array_merge($parametri, $produse);
        }
        if ($categorii !== []) {
            $semne = implode(',', array_fill(0, count($categorii), '?'));
            $conditii[] = 'p.category_id IN (' . $semne . ')';
            $conditii[] = 'EXISTS (SELECT 1 FROM product_category_links l WHERE l.product_id = oi.product_id AND l.category_id IN (' . $semne . '))';
            $parametri = array_merge($parametri, $categorii, $categorii);
        }
        $stmt = $db->prepare(
            'SELECT 1 FROM order_items oi JOIN orders o ON o.id = oi.order_id LEFT JOIN products p ON p.id = oi.product_id
             WHERE ' . $unde . ' AND (' . implode(' OR ', $conditii) . ') LIMIT 1'
        );
        $stmt->execute($parametri);
        return (bool) $stmt->fetchColumn();
    }

    /** Adresa are o comandă (nu anulată) plasată înaintea acesteia? („Doar prima comandă”.) */
    private static function areComandaAnterioara(PDO $db, array $comanda): bool
    {
        $nule = "'" . implode("', '", self::COMENZI_NULE) . "'";
        $stmt = $db->prepare(
            "SELECT 1 FROM orders WHERE billing_email = :e AND id <> :id AND deleted_at IS NULL AND status NOT IN ({$nule})
               AND (created_at < :c OR (created_at = :c2 AND id < :id2)) LIMIT 1"
        );
        $stmt->execute(['e' => $comanda['email'], 'id' => $comanda['id'], 'c' => $comanda['created_at'], 'c2' => $comanda['created_at'],
            'id2' => $comanda['id']]);
        return (bool) $stmt->fetchColumn();
    }
}
