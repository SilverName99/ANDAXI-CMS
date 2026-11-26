<?php

declare(strict_types=1);

namespace App\Support\Automatizari;

use App\Support\NewsletterService;
use PDO;
use Throwable;

/**
 * Rulările: trecerea unui om (subiectul, după adresa de email) printr-o automatizare (tabelul `automatizari_rulari`).
 *
 *  - **O singură dată pe subiect**: cheia unică e amprenta adresei, deci al doilea eveniment pentru aceeași adresă nu mai
 *    intră (INSERT IGNORE pe UNIQUE(automatizare_id, cheie_unica)). Cu „Permite reintrarea” cheia e adresa + evenimentul
 *    (comanda, coșul, confirmarea), deci același eveniment tot nu intră de două ori, dar unul nou da.
 *  - **Doar abonații activi intră**: o adresă care nu e abonată (sau e dezabonată, respinsă, pe lista „nu trimite”) nu
 *    primește nimic de la un flux de newsletter; verificarea se repetă la fiecare email (Motor).
 *  - **Revendicarea**: cronul ia o rulare scadentă (`activa` → `in_lucru`, cu un jeton), o duce cât poate și o lasă. O
 *    rulare rămasă `in_lucru` peste 15 minute (procesul a murit) se reia; pașii sunt idempotenți (emailul e rezervat înaintea
 *    trimiterii, codul de reducere e același la a doua încercare), deci nimic nu pleacă de două ori.
 */
final class Rulari
{
    /** După atâtea minute, o revendicare e a unui proces mort: rularea se poate lua din nou. */
    public const MINUTE_REVENDICARE = 15;

    public static function amprenta(string $email, ?string $eveniment = null): string
    {
        $email = strtolower(trim($email));
        return hash('sha256', $eveniment === null ? $email : $email . '|' . $eveniment);
    }

    /**
     * Abonatul activ cu adresa dată (status „active”, nu pe lista „nu trimite”), sau null.
     *
     * @return array{id: int, email: string, name: string, fara_urmarire: bool}|null
     */
    public static function abonatActiv(PDO $db, string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $stmt = $db->prepare('SELECT id, email, name, status, fara_urmarire FROM newsletter_subscribers WHERE email = :e LIMIT 1');
        $stmt->execute(['e' => $email]);
        $rand = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($rand) || (string) $rand['status'] !== 'active' || NewsletterService::esteSuprimat($db, $email)) {
            return null;
        }
        return ['id' => (int) $rand['id'], 'email' => strtolower((string) $rand['email']), 'name' => (string) ($rand['name'] ?? ''),
            'fara_urmarire' => (int) ($rand['fara_urmarire'] ?? 0) === 1];
    }

    /**
     * Pune subiectul în automatizare (dacă e abonat activ și n-a mai intrat). Întoarce id-ul rulării noi sau null.
     *
     * @param array<string, mixed> $automatizare rândul din Flux::citeste / Flux::activeCu
     * @param array<string, mixed> $context datele evenimentului (comanda, coșul, prenumele)
     */
    public static function intra(PDO $db, array $automatizare, string $email, array $context, string $eveniment,
        ?int $utilizatorId = null, ?int $comandaId = null): ?int
    {
        $abonat = self::abonatActiv($db, $email);
        if ($abonat === null) {
            return null;
        }
        $pasi = Flux::pasi($db, (int) $automatizare['id']);
        $primul = Flux::primul($pasi);
        if ($primul === null) {
            return null;
        }
        $cheie = self::amprenta($abonat['email'], !empty($automatizare['permite_reintrarea']) ? $eveniment : null);
        $acum = Ceas::sql();
        if (!isset($context['prenume']) || trim((string) $context['prenume']) === '') {
            $context['prenume'] = trim((string) strtok(trim($abonat['name']), ' '));
        }
        $insert = $db->prepare(
            'INSERT IGNORE INTO automatizari_rulari
                (automatizare_id, cheie_unica, email, abonat_id, utilizator_id, comanda_id, context_json, stare, pas_curent,
                 pas_inceput_la, urmatoarea_la, intrata_la)
             VALUES (:a, :c, :e, :ab, :u, :co, :ctx, \'activa\', :p, :acum, :acum2, :acum3)'
        );
        $insert->execute([
            'a' => (int) $automatizare['id'], 'c' => $cheie, 'e' => $abonat['email'], 'ab' => $abonat['id'],
            'u' => $utilizatorId, 'co' => $comandaId,
            'ctx' => (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'p' => $primul, 'acum' => $acum, 'acum2' => $acum, 'acum3' => $acum,
        ]);
        if ($insert->rowCount() !== 1) {
            return null;
        }
        $id = (int) $db->lastInsertId();
        self::jurnal($db, (int) $automatizare['id'], $id, null, 'intrat', (string) $automatizare['declansator']);
        self::jurnal($db, (int) $automatizare['id'], $id, $primul, 'pas');
        return $id;
    }

    /** Un rând în jurnal (fără adrese de email: doar id-uri, pași și motive). */
    public static function jurnal(PDO $db, int $automatizareId, ?int $rulareId, ?string $pas, string $eveniment, string $detalii = ''): void
    {
        try {
            $db->prepare(
                'INSERT INTO automatizari_jurnal (automatizare_id, rulare_id, pas_cheie, eveniment, detalii, created_at)
                 VALUES (:a, :r, :p, :e, :d, :c)'
            )->execute(['a' => $automatizareId, 'r' => $rulareId, 'p' => $pas, 'e' => $eveniment,
                'd' => $detalii !== '' ? mb_substr($detalii, 0, 255) : null, 'c' => Ceas::sql()]);
        } catch (Throwable) {
        }
    }

    /** Rulările revendicate de un proces care nu le-a mai lăsat (a murit) devin din nou scadente. */
    public static function elibereazaRevendicariVechi(PDO $db): int
    {
        $prag = Ceas::sql(Ceas::acum()->modify('-' . self::MINUTE_REVENDICARE . ' minutes'));
        $stmt = $db->prepare(
            "UPDATE automatizari_rulari SET stare = 'activa', revendicata_de = NULL
             WHERE stare = 'in_lucru' AND revendicata_la < :prag"
        );
        $stmt->execute(['prag' => $prag]);
        return $stmt->rowCount();
    }

    /** Ia rularea (doar dacă e încă scadentă și liberă). Întoarce jetonul revendicării sau null. */
    public static function revendica(PDO $db, int $id): ?string
    {
        $jeton = bin2hex(random_bytes(16));
        $stmt = $db->prepare(
            "UPDATE automatizari_rulari SET stare = 'in_lucru', revendicata_la = :acum, revendicata_de = :j
             WHERE id = :id AND stare = 'activa' AND urmatoarea_la <= :acum2"
        );
        $stmt->execute(['acum' => Ceas::sql(), 'j' => $jeton, 'id' => $id, 'acum2' => Ceas::sql()]);
        return $stmt->rowCount() === 1 ? $jeton : null;
    }

    /** @return array<string, mixed>|null */
    public static function citeste(PDO $db, int $id): ?array
    {
        $stmt = $db->prepare('SELECT * FROM automatizari_rulari WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $rand = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($rand)) {
            return null;
        }
        $context = json_decode((string) ($rand['context_json'] ?? ''), true);
        $rand['context'] = is_array($context) ? $context : [];
        $rand['id'] = (int) $rand['id'];
        $rand['automatizare_id'] = (int) $rand['automatizare_id'];
        return $rand;
    }

    public static function salveazaContext(PDO $db, int $id, array $context): void
    {
        $db->prepare('UPDATE automatizari_rulari SET context_json = :c WHERE id = :id')
            ->execute(['c' => (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id' => $id]);
    }

    /** Rularea trece la pasul `$pas` (null = fluxul s-a terminat). Rămâne revendicată: motorul continuă cu ea. */
    public static function muta(PDO $db, array $rulare, ?string $pas, string $jeton): void
    {
        $acum = Ceas::sql();
        if ($pas === null) {
            $db->prepare(
                "UPDATE automatizari_rulari SET stare = 'terminata', pas_curent = NULL, terminata_la = :acum, motiv_iesire = 'terminat',
                        revendicata_de = NULL
                 WHERE id = :id AND revendicata_de = :j"
            )->execute(['acum' => $acum, 'id' => $rulare['id'], 'j' => $jeton]);
            self::jurnal($db, $rulare['automatizare_id'], $rulare['id'], null, 'iesit', 'terminat');
            return;
        }
        $db->prepare(
            'UPDATE automatizari_rulari SET pas_curent = :p, pas_inceput_la = :acum, urmatoarea_la = :acum2, incercari = 0
             WHERE id = :id AND revendicata_de = :j'
        )->execute(['p' => $pas, 'acum' => $acum, 'acum2' => $acum, 'id' => $rulare['id'], 'j' => $jeton]);
        self::jurnal($db, $rulare['automatizare_id'], $rulare['id'], $pas, 'pas');
    }

    /** Rularea iese din flux înainte de capăt (dezabonat, comanda anulată, pas șters…). */
    public static function iese(PDO $db, array $rulare, string $motiv, string $jeton): void
    {
        $db->prepare(
            "UPDATE automatizari_rulari SET stare = 'iesita', terminata_la = :acum, motiv_iesire = :m, revendicata_de = NULL
             WHERE id = :id AND revendicata_de = :j"
        )->execute(['acum' => Ceas::sql(), 'm' => mb_substr($motiv, 0, 40), 'id' => $rulare['id'], 'j' => $jeton]);
        self::jurnal($db, $rulare['automatizare_id'], $rulare['id'], $rulare['pas_curent'] !== null ? (string) $rulare['pas_curent'] : null, 'iesit', $motiv);
    }

    /** Rularea se lasă până la `$cand` (o așteptare, o reîncercare, bugetul terminat). */
    public static function lasaPana(PDO $db, array $rulare, string $cand, string $jeton, bool $incercare = false): void
    {
        $db->prepare(
            "UPDATE automatizari_rulari SET stare = 'activa', urmatoarea_la = :c, revendicata_de = NULL"
            . ($incercare ? ', incercari = incercari + 1' : '') . '
             WHERE id = :id AND revendicata_de = :j'
        )->execute(['c' => $cand, 'id' => $rulare['id'], 'j' => $jeton]);
    }
}
