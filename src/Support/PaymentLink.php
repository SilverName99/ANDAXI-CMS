<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Linkuri de plată pentru diferența rămasă pe o comandă.
 *
 * Cazul tipic: clientul a plătit cu cardul, apoi cere un produs în plus.
 * Operatorul adaugă produsul, totalul crește, iar diferența se încasează
 * printr-un link trimis pe email. Plata se leagă de aceeași comandă: la
 * confirmare, `orders.paid_amount` crește, iar comanda pleacă din nou în
 * ERP, unde suma încasată se actualizează pe aceeași factură.
 *
 * Referința trimisă la procesator e `{numar_comanda}-P{n}`: unică pentru
 * fiecare link (procesatorul refuză același număr de două ori), dar
 * recunoscută la întoarcere ca aparținând comenzii.
 *
 * Fiecare link ține minte procesatorul cu care se plătește (`procesator`):
 * EuPlătesc, ca până acum, sau Banca Transilvania, când BT e pornit în
 * producție, sau Stripe (WP-20, X5, din Biovitality: site-urile care încasează
 * doar prin Stripe), când niciunul dintre primele două nu e pornit. Linkurile
 * deja trimise merg mai departe pe procesatorul lor.
 *
 * Plata întreagă (9 nov 2026): o comandă cu cardul a cărei plată a eșuat, a expirat, a fost anulată sau n-a fost
 * terminată, fără nimic încasat, primește din admin un link pentru TOT totalul („Trimite link de plată”). Felul linkului
 * se scrie la creare, în referință: `{numar_comanda}-PI{n}` (`esteIntegral`), deci nu depinde de totalul de mai târziu
 * și tabelul nu are nicio coloană nouă. Plătit, linkul face comanda plătită exact ca o plată reușită din checkout
 * (`confirmaPlataDetaliat`, `efecteDupaConfirmare`): starea, emailul „comandă nouă”, trimiterea în ERP. La BT linkul
 * pornește o plată obișnuită a comenzii, cu linkul ținut pe ea (BtIpayPayments::pornestePlataIntegrala), deci
 * autorizarea, încasarea la factura din ERP sau la 96 de ore și eliberarea rămân cele ale comenzii.
 *
 * Gărzile împotriva plății duble (pentru orice link): linkul de plată întreagă nu se trimite cât o plată a comenzii e
 * autorizată sau în curs (`plataIntegrala`; la Stripe, sesiunea inițială se expiră întâi); de fiecare dată când comanda
 * primește bani, linkurile neplătite mai mari decât restul de acum se anulează (`anuleazaDepasite`); un link nu mai
 * pornește peste cât mai e de plătit (`motivLinkNeplatibil`); o plată venită oricum peste una deja socotită (și același
 * link plătit de două ori, sau o diferență care trece încasatul peste total) lasă pe comandă nota „Plătită de două ori”
 * (`NOTA_DUBLA`, `scrieNotaDubla`), cu plata de rambursat și cea socotită, fiecare cu procesatorul și id-ul ei.
 */
final class PaymentLink
{
    public const STATUS_ASTEPTARE = 'pending';
    public const STATUS_PLATIT = 'paid';
    public const STATUS_ANULAT = 'cancelled';

    public const PROCESATOR_EUPLATESC = 'euplatesc';
    public const PROCESATOR_BT = 'btipay';
    public const PROCESATOR_STRIPE = 'stripe';

    /** Ce a făcut o plată de link pe comandă (confirmaPlataDetaliat). */
    public const TIP_DIFERENTA = 'diferenta';
    public const TIP_INTEGRALA = 'integrala';
    /** Linkul a fost plătit peste o plată deja socotită pe comandă (sau pe o comandă închisă): nimic adunat, doar nota. */
    public const TIP_DUBLURA = 'dublura';

    /** Începutul notei puse pe comandă (orders.payment_error) când a fost plătită de două ori. */
    public const NOTA_DUBLA = 'Plătită de două ori';

    /** Comenzile care nu mai primesc un link de plată întreagă (și pe care un asemenea link nu se mai plătește). */
    private const COMENZI_INCHISE = ['cancelled', 'completed', 'refunded', 'returned'];

    /** Cât timp rămâne valabil un link netrimis la plată (zile). */
    private const VALABILITATE_ZILE = 30;

    /** Versiunea tabelului; se schimbă la fiecare coloană nouă. */
    private const VERSIUNE_SCHEMA = '2';
    private const CHEIE_VERSIUNE = 'payment_links_schema_version';

    /**
     * Tabelul linkurilor. Se creează (și se completează cu coloanele noi) o
     * singură dată: versiunea rămâne în `settings`, așa că paginile publice
     * (/plata/...) nu mai trimit DDL la fiecare vizită.
     */
    public static function ensureSchema(PDO $db): void
    {
        if (\App\Support\Migrari\Garda::stare($db) === 'la_zi') { return; } // R1: schema o fac migrările (docs/MIGRARI.md)
        static $gata = false;
        if ($gata) {
            return;
        }
        $gata = true;

        try {
            $stmt = $db->prepare('SELECT `value` FROM settings WHERE `key` = :k LIMIT 1');
            $stmt->execute(['k' => self::CHEIE_VERSIUNE]);
            if ((string) ($stmt->fetchColumn() ?: '') === self::VERSIUNE_SCHEMA) {
                return;
            }
        } catch (Throwable) {
            // Fără tabelul de setări mergem pe drumul lung.
        }

        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS order_payment_links (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    order_id INT UNSIGNED NOT NULL,
                    referinta VARCHAR(60) NOT NULL,
                    token VARCHAR(64) NOT NULL,
                    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                    status VARCHAR(20) NOT NULL DEFAULT "pending",
                    procesator VARCHAR(20) NOT NULL DEFAULT "euplatesc",
                    ep_id VARCHAR(64) DEFAULT NULL,
                    approval VARCHAR(64) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    expires_at DATETIME DEFAULT NULL,
                    paid_at DATETIME DEFAULT NULL,
                    UNIQUE KEY uq_order_payment_links_referinta (referinta),
                    UNIQUE KEY uq_order_payment_links_token (token),
                    KEY idx_order_payment_links_order (order_id, status)
                )'
            );
        } catch (Throwable) {
            // Tabelul există deja.
        }
        try {
            // Linkurile de dinainte au fost toate EuPlătesc.
            $db->exec('ALTER TABLE order_payment_links ADD COLUMN procesator VARCHAR(20) NOT NULL DEFAULT "euplatesc" AFTER status');
        } catch (Throwable) {
            // Coloana există deja.
        }

        try {
            $db->query('SELECT procesator FROM order_payment_links LIMIT 0');
            $db->prepare(
                'INSERT INTO settings (`key`, `value`) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
            )->execute(['k' => self::CHEIE_VERSIUNE, 'v' => self::VERSIUNE_SCHEMA]);
        } catch (Throwable) {
            // Data viitoare încercăm din nou.
        }
    }

    /**
     * Cu ce procesator se plătește un link NOU: Banca Transilvania când e
     * pornită în producție, cu datele de acces și deschisă clienților (nu doar
     * administratorilor); altfel EuPlătesc, ca până acum; altfel Stripe (WP-20,
     * X5), când e pornit cu cheia secretă. Gol = niciunul.
     */
    public static function procesorPentruLinkNou(array $settings): string
    {
        if (self::btPentruLinkuri($settings, true)) {
            return self::PROCESATOR_BT;
        }
        if (self::euPlatescPentruLinkuri($settings, true)) {
            return self::PROCESATOR_EUPLATESC;
        }
        if (self::stripePentruLinkuri($settings, true)) {
            return self::PROCESATOR_STRIPE;
        }
        return '';
    }

    /** Se mai poate plăti azi un link cu procesatorul lui? */
    public static function procesorDisponibil(string $procesator, array $settings): bool
    {
        return match ($procesator) {
            self::PROCESATOR_BT => self::btPentruLinkuri($settings, false),
            self::PROCESATOR_STRIPE => self::stripePentruLinkuri($settings, false),
            default => self::euPlatescPentruLinkuri($settings, false),
        };
    }

    /**
     * BT poate încasa linkuri: setările o permit și licența are pluginul.
     * Un link NOU cere pluginul „pornit”; unul deja trimis se poate plăti și
     * cât pluginul „se oprește” (e o plată începută).
     */
    private static function btPentruLinkuri(array $settings, bool $linkNou): bool
    {
        $licenta = $linkNou ? Plugins::permiteInceput('plata_bt') : Plugins::vizibil('plata_bt');
        return $licenta && BtIpayPayments::poatePlatiLinkuri($settings);
    }

    /** La fel ca mai sus, pentru EuPlătesc. */
    private static function euPlatescPentruLinkuri(array $settings, bool $linkNou): bool
    {
        $licenta = $linkNou ? Plugins::permiteInceput('plata_euplatesc') : Plugins::vizibil('plata_euplatesc');
        return $licenta && EuPlatescGateway::isEnabled($settings);
    }

    /**
     * La fel, pentru Stripe (WP-20, X5): pornit în Setări plăți, cu cheia secretă, ca la checkout. Pluginul Stripe nu cere
     * magazinul (îl folosesc și donațiile), deci aici îl cerem noi, ca la EuPlătesc: un link de plată e al unei comenzi.
     */
    private static function stripePentruLinkuri(array $settings, bool $linkNou): bool
    {
        $licenta = $linkNou
            ? Plugins::permiteInceput('plata_stripe') && Plugins::permiteInceput('magazin')
            : Plugins::vizibil('plata_stripe') && Plugins::vizibil('magazin');
        return $licenta
            && (string) ($settings['stripe_enabled'] ?? '0') === '1'
            && trim((string) ($settings['stripe_secret_key'] ?? '')) !== '';
    }

    public static function procesator(array $link): string
    {
        return match ((string) ($link['procesator'] ?? '')) {
            self::PROCESATOR_BT => self::PROCESATOR_BT,
            self::PROCESATOR_STRIPE => self::PROCESATOR_STRIPE,
            default => self::PROCESATOR_EUPLATESC,
        };
    }

    /**
     * Cu ce se plătește linkul ACUM: procesatorul lui; un link BT trece pe
     * EuPlătesc doar dacă BT nu mai e disponibil (oprit, mod test) și
     * EuPlătesc e pornit — ca un link deja trimis să nu ducă într-o fundătură.
     * Referința `{comanda}-P{n}` e recunoscută la fel de ambele drumuri.
     */
    public static function procesorEfectiv(array $link, array $settings): string
    {
        $procesator = self::procesator($link);
        if ($procesator === self::PROCESATOR_BT
            && !self::btPentruLinkuri($settings, false)
            && self::euPlatescPentruLinkuri($settings, false)) {
            return self::PROCESATOR_EUPLATESC;
        }
        // Pluginul EuPlătesc scos din licență: un link deja trimis prin el se
        // plătește prin BT, dacă BT e pe site (altfel linkul nu se mai poate plăti online).
        if ($procesator === self::PROCESATOR_EUPLATESC
            && !Plugins::vizibil('plata_euplatesc')
            && self::btPentruLinkuri($settings, false)) {
            return self::PROCESATOR_BT;
        }
        return $procesator;
    }

    /**
     * Pluginul procesatorului cu care s-ar plăti linkul nu mai e pe site (licența):
     * pagina linkului nu mai pornește nicio plată și cere clientului să ne contacteze.
     */
    public static function procesorScosDinLicenta(string $procesator): bool
    {
        return !Plugins::vizibil(match ($procesator) {
            self::PROCESATOR_BT => 'plata_bt',
            self::PROCESATOR_STRIPE => 'plata_stripe',
            default => 'plata_euplatesc',
        });
    }

    /** Restul de încasat pentru o comandă: total − cât s-a încasat deja. */
    public static function restDeIncasat(array $order): float
    {
        if (strtolower((string) ($order['payment_status'] ?? '')) !== 'paid') {
            return 0.0;
        }
        $total = round((float) ($order['total'] ?? 0), 2);
        $incasat = $order['paid_amount'] ?? null;
        if ($incasat === null || $incasat === '') {
            $incasat = $total;
        }
        return round(max(0.0, $total - (float) $incasat), 2);
    }

    /**
     * Creează un link de plată. Un link nefolosit pentru aceeași sumă și de același fel se refolosește, ca să nu umplem
     * tabelul la retrimiteri — dacă procesatorul lui mai e disponibil (un link EuPlătesc trimis deja merge mai departe pe
     * EuPlătesc). Linkul nou primește procesatorul dat; celelalte linkuri neplătite ale comenzii se anulează.
     *
     * Felul linkului se scrie la creare, în referință: `{nr}-P{n}` pentru o diferență, `{nr}-PI{n}` pentru plata întreagă
     * a comenzii (`esteIntegral`); nu se mai schimbă, orice s-ar întâmpla apoi cu totalul. Cererile pentru aceeași comandă
     * se fac pe rând: rândul comenzii se blochează întâi (ca la confirmare și la anulare), apoi linkurile ei.
     *
     * @return array<string, mixed>|null
     */
    public static function creeaza(PDO $db, int $orderId, string $orderNumber, float $suma, string $procesator = self::PROCESATOR_EUPLATESC,
        array $settings = [], string $tip = self::TIP_DIFERENTA): ?array
    {
        self::ensureSchema($db);
        $suma = round($suma, 2);
        if ($orderId <= 0 || $suma <= 0) {
            return null;
        }
        $procesator = self::procesator(['procesator' => $procesator]);
        $integral = $tip === self::TIP_INTEGRALA;

        $amDeschis = false;
        try {
            if (!$db->inTransaction()) {
                $db->beginTransaction();
                $amDeschis = true;
            }
            $stmt = $db->prepare('SELECT id FROM orders WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $orderId]);
            $stmt->fetchAll();

            $existent = self::linkActivPentruSuma($db, $orderId, $suma, $integral);
            if ($existent !== null && ($settings === [] || self::procesorDisponibil(self::procesator($existent), $settings))) {
                if ($amDeschis) {
                    $db->commit();
                }
                return $existent;
            }

            // Anulăm linkurile în așteptare pentru alte sume: nu mai sunt valide.
            $db->prepare(
                'UPDATE order_payment_links SET status = :anulat
                 WHERE order_id = :order_id AND status = :asteptare'
            )->execute([
                'anulat' => self::STATUS_ANULAT,
                'order_id' => $orderId,
                'asteptare' => self::STATUS_ASTEPTARE,
            ]);

            $token = '';
            for ($incercare = 1; $incercare <= 20; $incercare++) {
                $numar = self::urmatorulNumar($db, $orderId) + $incercare - 1;
                $referinta = substr($orderNumber, 0, 49) . ($integral ? '-PI' : '-P') . $numar;
                $tokenNou = bin2hex(random_bytes(24));
                try {
                    $db->prepare(
                        'INSERT INTO order_payment_links (order_id, referinta, token, amount, status, procesator, expires_at)
                         VALUES (:order_id, :referinta, :token, :amount, :status, :procesator, :expires_at)'
                    )->execute([
                        'order_id' => $orderId,
                        'referinta' => $referinta,
                        'token' => $tokenNou,
                        'amount' => number_format($suma, 2, '.', ''),
                        'status' => self::STATUS_ASTEPTARE,
                        'procesator' => $procesator,
                        'expires_at' => date('Y-m-d H:i:s', time() + self::VALABILITATE_ZILE * 86400),
                    ]);
                    $token = $tokenNou;
                    break;
                } catch (Throwable) {
                    // Referință deja folosită (un link vechi cu același număr) — încercăm următorul.
                    continue;
                }
            }
            if ($amDeschis) {
                $db->commit();
            }
        } catch (Throwable) {
            if ($amDeschis && $db->inTransaction()) {
                $db->rollBack();
            }
            return null;
        }
        return $token !== '' ? self::dupaToken($db, $token) : null;
    }

    /** @return array<string, mixed>|null */
    public static function dupaToken(PDO $db, string $token): ?array
    {
        self::ensureSchema($db);
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        try {
            $stmt = $db->prepare('SELECT * FROM order_payment_links WHERE token = :token LIMIT 1');
            $stmt->execute(['token' => $token]);
        } catch (Throwable) {
            return null;
        }
        $rand = $stmt->fetch();
        return is_array($rand) ? $rand : null;
    }

    /** @return array<string, mixed>|null */
    public static function dupaId(PDO $db, int $id): ?array
    {
        self::ensureSchema($db);
        if ($id <= 0) {
            return null;
        }
        try {
            $stmt = $db->prepare('SELECT * FROM order_payment_links WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
        } catch (Throwable) {
            return null;
        }
        $rand = $stmt->fetch();
        return is_array($rand) ? $rand : null;
    }

    /** @return array<string, mixed>|null */
    public static function dupaReferinta(PDO $db, string $referinta): ?array
    {
        self::ensureSchema($db);
        $referinta = trim($referinta);
        if ($referinta === '') {
            return null;
        }
        try {
            $stmt = $db->prepare('SELECT * FROM order_payment_links WHERE referinta = :referinta LIMIT 1');
            $stmt->execute(['referinta' => $referinta]);
        } catch (Throwable) {
            return null;
        }
        $rand = $stmt->fetch();
        return is_array($rand) ? $rand : null;
    }

    /** Referința arată ca un link de plată („204026-P1”; plata întreagă „204026-PI2”)? */
    public static function pareReferintaDeLink(string $referinta): bool
    {
        return preg_match('/-PI?\d+$/', trim($referinta)) === 1;
    }

    public static function esteExpirat(array $link): bool
    {
        $expira = trim((string) ($link['expires_at'] ?? ''));
        if ($expira === '') {
            return false;
        }
        return strtotime($expira) !== false && strtotime($expira) < time();
    }

    /**
     * Confirmă plata unui link și adaugă suma la cea deja încasată pe comandă.
     * Idempotentă: un link deja plătit nu adaugă suma a doua oară.
     *
     * @return bool true dacă acum a fost marcat plătit (prima confirmare)
     */
    public static function confirmaPlata(PDO $db, array $link, string $epId = '', string $approval = ''): bool
    {
        return self::confirmaPlataDetaliat($db, $link, $epId, $approval)['nou'];
    }

    /**
     * Confirmarea plății unui link, cu ce a făcut ea pe comandă (`tip`):
     *  - `integrala`: comanda nu avea nimic încasat (plata cu cardul eșuată sau neterminată), deci linkul o plătește
     *    ca o plată reușită din checkout: „plătită”, starea „în așteptare plată”/„eșuată” → „în așteptare”, suma
     *    încasată = suma linkului; emailul și ERP-ul le face apoi efecteDupaConfirmare;
     *  - `dublura`: un link de plată întreagă plătit peste o plată deja socotită (plata inițială a venit totuși) sau pe o
     *    comandă anulată, ori același link plătit a doua oară (alt id de plată): banii s-au luat, dar nu se adună nimic;
     *    comanda primește nota „Plătită de două ori”, cu plata de rambursat și cea socotită, fiecare cu id-ul ei;
     *  - `diferenta`: ca până acum, suma se adaugă la cea încasată; dacă astfel încasatul trece de total (linkul era
     *    anulat, iar diferența fusese deja încasată pe alt drum), tot nota, cu cât e de rambursat.
     * Și un link anulat se trece plătit dacă plata lui vine totuși (clientul era deja pe pagina procesatorului): banii
     * au fost luați și trebuie să se vadă. După orice plată, linkurile neplătite mai mari decât restul de acum se anulează.
     *
     * Ordinea blocărilor: rândul comenzii, apoi linkul (ca la creare și la anulare). O scriere care nu reușește nu se
     * înghite: întoarce `eroare` (procesatorul primește un cod de eroare și reîncearcă) sau, în tranzacția apelantului
     * (plățile BT), aruncă mai departe, ca apelantul să anuleze tot.
     *
     * @return array{nou: bool, tip: string, order_id: int, eroare: bool, eroare_anterioara?: string}
     */
    public static function confirmaPlataDetaliat(PDO $db, array $link, string $epId = '', string $approval = ''): array
    {
        $linkId = (int) ($link['id'] ?? 0);
        $orderId = (int) ($link['order_id'] ?? 0);
        $rezultat = ['nou' => false, 'tip' => self::TIP_DIFERENTA, 'order_id' => $orderId, 'eroare' => false];
        if ($linkId <= 0 || $orderId <= 0 || round((float) ($link['amount'] ?? 0), 2) <= 0) {
            return $rezultat;
        }
        $epId = substr(trim($epId), 0, 64);
        $approval = substr(trim($approval), 0, 64);

        // Coloanele ERP înaintea tranzacției: un DDL în mijlocul ei ar închide-o.
        ErpSync::ensureSchema($db);
        self::ensureSchema($db);
        $amDeschis = false;
        try {
            if (!$db->inTransaction()) {
                $db->beginTransaction();
                $amDeschis = true;
            }
            $stmt = $db->prepare(
                // Tot rândul: coloanele plăților (ep_id, sesiunea Stripe) le citește plataSocotita pentru notă.
                'SELECT * FROM orders WHERE id = :id AND deleted_at IS NULL LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['id' => $orderId]);
            $comanda = $stmt->fetch();
            $comanda = is_array($comanda) ? $comanda : null;
            $stmt = $db->prepare('SELECT * FROM order_payment_links WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute(['id' => $linkId]);
            $rand = $stmt->fetch();
            if (!is_array($rand)) {
                if ($amDeschis) {
                    $db->commit();
                }
                return $rezultat;
            }
            $suma = round((float) ($rand['amount'] ?? 0), 2);
            $procesator = self::procesator($rand);

            if ((string) ($rand['status'] ?? '') === self::STATUS_PLATIT) {
                // Același link, plătit a doua oară (altă plată la procesator: alt ep_id, altă sesiune Stripe).
                $anterior = trim((string) ($rand['ep_id'] ?? ''));
                if ($comanda !== null && $epId !== '' && $anterior !== '' && !hash_equals($anterior, $epId)) {
                    self::scrieNotaDubla($db, $comanda, self::descrierePlata($procesator, $epId, $suma, 'a doua plată a linkului '
                        . (string) $rand['referinta']), self::descrierePlata($procesator, $anterior, $suma, 'prima plată a linkului '
                        . (string) $rand['referinta']), $procesator);
                    $rezultat['tip'] = self::TIP_DUBLURA;
                    $rezultat['nou'] = true;
                }
                if ($amDeschis) {
                    $db->commit();
                }
                return $rezultat;
            }

            // „În așteptare" sau „anulat" → „plătit".
            $db->prepare(
                'UPDATE order_payment_links SET status = :platit, paid_at = NOW(), ep_id = :ep_id, approval = :approval WHERE id = :id'
            )->execute([
                'platit' => self::STATUS_PLATIT,
                'ep_id' => $epId !== '' ? $epId : null,
                'approval' => $approval !== '' ? $approval : null,
                'id' => $linkId,
            ]);
            $rezultat['nou'] = true;
            if ($comanda === null) {
                if ($amDeschis) {
                    $db->commit();
                }
                return $rezultat;
            }
            $integral = self::esteIntegral($rand);
            $platiiLinkului = self::descrierePlata($procesator, $epId, $suma, 'linkul ' . (string) $rand['referinta']);

            if (self::nimicIncasat($comanda) && !self::comandaInchisa($comanda)) {
                $rezultat['tip'] = self::TIP_INTEGRALA;
                // Motivul plății eșuate se șterge de pe comandă, ca la o plată reușită din checkout; rămâne în jurnal.
                $rezultat['eroare_anterioara'] = (string) ($comanda['payment_error'] ?? '');
                $db->prepare(
                    "UPDATE orders
                     SET payment_status = 'paid',
                         status = CASE WHEN status IN ('pending_payment', 'failed') THEN 'pending' ELSE status END,
                         paid_at = COALESCE(paid_at, NOW()),
                         payment_error = NULL,
                         paid_amount = :suma
                     WHERE id = :order_id"
                )->execute(['suma' => number_format($suma, 2, '.', ''), 'order_id' => $orderId]);
            } elseif ($integral || (self::comandaInchisa($comanda) && self::nimicIncasat($comanda))) {
                $rezultat['tip'] = self::TIP_DUBLURA;
                if (self::comandaInchisa($comanda) && self::nimicIncasat($comanda)) {
                    self::scrieNotaDubla($db, $comanda, $platiiLinkului, 'niciuna (comanda fusese anulată sau încheiată înainte de plată)', $procesator);
                } else {
                    self::scrieNotaDubla($db, $comanda, $platiiLinkului, self::plataSocotita($db, $comanda, $linkId), $procesator);
                }
            } else {
                $db->prepare(
                    'UPDATE orders
                     SET paid_amount = ROUND(COALESCE(paid_amount, total) + :suma, 2),
                         payment_status = \'paid\',
                         paid_at = COALESCE(paid_at, NOW())
                     WHERE id = :order_id'
                )->execute(['suma' => number_format($suma, 2, '.', ''), 'order_id' => $orderId]);
                $stmt = $db->prepare('SELECT total, paid_amount FROM orders WHERE id = :id');
                $stmt->execute(['id' => $orderId]);
                $dupa = $stmt->fetch() ?: [];
                $peste = round((float) ($dupa['paid_amount'] ?? 0) - (float) ($dupa['total'] ?? 0), 2);
                if ($peste > 0.009) {
                    // Diferența fusese deja încasată (alt link, încasare consemnată): acum e încasat peste total.
                    self::scrieNotaDubla($db, $comanda, number_format(min($peste, $suma), 2, ',', '.') . ' lei din ' . $platiiLinkului,
                        'restul încasărilor comenzii (încasat acum ' . number_format((float) $dupa['paid_amount'], 2, ',', '.') . ' lei, la un total de '
                        . number_format((float) $dupa['total'], 2, ',', '.') . ' lei)', $procesator);
                }
            }
            $stmt = $db->prepare('SELECT id, total, paid_amount, payment_status FROM orders WHERE id = :id');
            $stmt->execute(['id' => $orderId]);
            self::anuleazaDepasiteBlocat($db, (array) ($stmt->fetch() ?: []));
            if ($amDeschis) {
                $db->commit();
            }
        } catch (Throwable $eroare) {
            if (!$amDeschis) {
                throw $eroare;
            }
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return ['nou' => false, 'tip' => self::TIP_DIFERENTA, 'order_id' => $orderId, 'eroare' => true];
        }
        return $rezultat;
    }

    /**
     * Ce urmează după prima confirmare a unui link (în afara bazei): la plata întreagă emailul „comandă nouă” și
     * trimiterea în ERP, ca după o plată reușită din checkout (comanda cu cardul pleacă în ERP abia după plată); la o
     * diferență, ERP-ul află noua sumă încasată pe aceeași comandă; o dublură nu pleacă nicăieri (nota e pe comandă).
     *
     * @param array{nou: bool, tip: string, order_id: int} $confirmare
     */
    public static function efecteDupaConfirmare(PDO $db, array $confirmare): void
    {
        $orderId = (int) ($confirmare['order_id'] ?? 0);
        if (empty($confirmare['nou']) || $orderId <= 0) {
            return;
        }
        if ($confirmare['tip'] === self::TIP_INTEGRALA) {
            try {
                EmailAutomation::sendOrderTemplateById($db, Settings::all($db), $orderId, 'new_order');
            } catch (Throwable) {
                // Emailul nu ține plata pe loc.
            }
            try {
                ErpSync::push($db, $orderId);
            } catch (Throwable) {
                // Cronul ERP reîncearcă; banii sunt deja înregistrați pe site.
            }
            AdminActivityLog::log($db, 'order_platita_link_integral', ['order_id' => $orderId,
                'plata_esuata_inainte' => mb_substr((string) ($confirmare['eroare_anterioara'] ?? ''), 0, 300)]);
            return;
        }
        if ($confirmare['tip'] === self::TIP_DIFERENTA) {
            try {
                ErpSync::push($db, $orderId, true);
            } catch (Throwable) {
                // Cronul reîncearcă; banii sunt deja înregistrați pe site.
            }
        }
    }

    /** Un lacăt MySQL pe nume, cu amprenta bazei (serverul e împărțit pe găzduirea comună); îl eliberează și moartea procesului. */
    public static function blocheaza(PDO $db, string $nume, int $secunde): bool
    {
        try {
            $stmt = $db->prepare('SELECT GET_LOCK(:n, :s)');
            $stmt->execute(['n' => self::numeLacat($db, $nume), 's' => max(0, $secunde)]);
            return (int) $stmt->fetchColumn() === 1;
        } catch (Throwable) {
            return false;
        }
    }

    public static function elibereaza(PDO $db, string $nume): void
    {
        try {
            $db->prepare('SELECT RELEASE_LOCK(:n)')->execute(['n' => self::numeLacat($db, $nume)]);
        } catch (Throwable) {
            // Lacătul se eliberează oricum la închiderea conexiunii.
        }
    }

    private static function numeLacat(PDO $db, string $nume): string
    {
        static $baza = null;
        if ($baza === null) {
            try {
                $baza = (string) $db->query('SELECT DATABASE()')->fetchColumn();
            } catch (Throwable) {
                $baza = '';
            }
        }
        return substr('lp:' . substr(md5($baza), 0, 10) . ':' . $nume, 0, 64);
    }

    /** Pe comandă nu e încasat nimic (nici plătită, nici o sumă trecută)? */
    public static function nimicIncasat(array $order): bool
    {
        $plata = strtolower(trim((string) ($order['payment_status'] ?? '')));
        $incasat = $order['paid_amount'] ?? null;
        return !in_array($plata, ['paid', 'refunded'], true) && ($incasat === null || $incasat === '' || (float) $incasat <= 0.004);
    }

    private static function comandaInchisa(array $order): bool
    {
        return in_array(strtolower(trim((string) ($order['status'] ?? ''))), self::COMENZI_INCHISE, true);
    }

    /**
     * Linkul e unul de plată întreagă a comenzii? Felul se scrie la creare, în referință (`{nr}-PI{n}`; o diferență e
     * `{nr}-P{n}`), deci nu depinde de totalul de acum al comenzii.
     */
    public static function esteIntegral(array $link): bool
    {
        return preg_match('/-PI\d+$/', trim((string) ($link['referinta'] ?? ''))) === 1;
    }

    /**
     * Cât mai e de plătit pe comandă pentru un link: la o comandă fără nimic încasat, totalul; altfel restul
     * (total − încasat, restDeIncasat).
     */
    public static function restPentruLink(array $order): float
    {
        return self::nimicIncasat($order) ? round(max(0.0, (float) ($order['total'] ?? 0)), 2) : self::restDeIncasat($order);
    }

    /**
     * Comanda poate primi acum un link pentru plata întreagă? Doar o comandă cu plata online cu cardul (orice
     * procesator al site-ului), neanulată și neterminată, fără nimic încasat, a cărei plată a eșuat, a expirat, a fost
     * anulată (suma eliberată) sau n-a fost terminată (în „Plată în așteptare” de mai mult decât expirarea plăților,
     * `bt_ipay_expire_minutes`, implicit 60 de minute); și fără o plată BT a comenzii autorizată sau încă în curs la
     * bancă (clientul ar plăti de două ori). Suma e totalul comenzii.
     *
     * @param array<string, mixed> $order rândul din `orders` (payment_method, status, payment_status, paid_amount, total, created_at)
     * @return array{ok: bool, motiv: string, suma: float}
     */
    public static function plataIntegrala(PDO $db, array $order, array $settings): array
    {
        $motiv = self::motivFaraPlataIntegrala($order, $settings);
        if ($motiv === '' && BtIpayPayments::areTabele($db)) {
            $motiv = BtIpayPayments::motivFaraPlataIntegrala($db, (int) ($order['id'] ?? 0), BtIpayPayments::setari($settings)['minute_expirare']);
        }
        return $motiv !== ''
            ? ['ok' => false, 'motiv' => $motiv, 'suma' => 0.0]
            : ['ok' => true, 'motiv' => '', 'suma' => round((float) ($order['total'] ?? 0), 2)];
    }

    /**
     * Partea din plataIntegrala care se citește doar din rândul comenzii (fără plățile BT; o folosește și lista comenzilor,
     * pentru „Trimite din nou link de plată”): gol = comanda poate primi linkul.
     *
     * @param array<string, mixed> $order
     */
    public static function motivFaraPlataIntegrala(array $order, array $settings): string
    {
        if (!PaymentMethods::esteCard((string) ($order['payment_method'] ?? ''))) {
            return 'Comanda nu e cu plata online cu cardul.';
        }
        if (($order['deleted_at'] ?? null) !== null || self::comandaInchisa($order)) {
            return 'Comanda e anulată sau încheiată; nu se mai plătește.';
        }
        $plata = strtolower(trim((string) ($order['payment_status'] ?? '')));
        if ($plata === 'paid') {
            return 'Comanda e deja plătită.';
        }
        if ($plata === 'refunded') {
            return 'Plata comenzii a fost rambursată.';
        }
        if (!self::nimicIncasat($order)) {
            return 'Pe comandă e deja încasată o sumă; restul se cere ca diferență.';
        }
        if (round((float) ($order['total'] ?? 0), 2) <= 0) {
            return 'Comanda nu are nimic de plătit.';
        }
        if (strtolower(trim((string) ($order['status'] ?? ''))) === 'pending_payment' && $plata !== 'failed') {
            $minute = BtIpayPayments::setari($settings)['minute_expirare'];
            $creata = strtotime((string) ($order['created_at'] ?? ''));
            if ($creata === false || $creata > time() - $minute * 60) {
                return 'Plata cu cardul a comenzii poate fi încă în curs: linkul se poate trimite după ' . $minute
                    . ' de minute de la plasare, dacă plata nu s-a confirmat.';
            }
        }
        return '';
    }

    /**
     * De ce nu se mai poate plăti acum un link (gol = se poate). Linkul de plată întreagă: nu pe o comandă plătită între
     * timp (plata inițială confirmată totuși), anulată sau încheiată, nici cu totalul schimbat de la trimitere (în orice
     * sens: se cere un link nou, cu suma de acum). Linkul pentru o diferență: nu peste cât mai e de plătit acum
     * (`restPentruLink`; diferența încasată între timp pe alt drum, un alt link plătit).
     *
     * @param array<string, mixed> $order
     */
    public static function motivLinkNeplatibil(array $link, array $order): string
    {
        if ((string) ($link['status'] ?? '') !== self::STATUS_ASTEPTARE) {
            return '';
        }
        $suma = round((float) ($link['amount'] ?? 0), 2);
        if (self::esteIntegral($link)) {
            if (!self::nimicIncasat($order)) {
                return 'Comanda a fost deja plătită; nu e nevoie să plătești din nou.';
            }
            if (self::comandaInchisa($order) || ($order['deleted_at'] ?? null) !== null) {
                return 'Comanda a fost anulată sau încheiată, deci linkul nu mai poate fi plătit.';
            }
            if (abs($suma - round((float) ($order['total'] ?? 0), 2)) > 0.01) {
                return 'Totalul comenzii s-a schimbat de când ți-am trimis linkul. Scrie-ne și îți trimitem unul nou.';
            }
            return '';
        }
        $rest = self::restPentruLink($order);
        if ($suma > $rest + 0.009) {
            return $rest > 0.009
                ? 'Suma linkului nu mai corespunde: pe comandă au rămas de plătit ' . number_format($rest, 2, ',', '.') . ' lei. Scrie-ne și îți trimitem un link nou.'
                : 'Diferența a fost deja încasată; nu e nevoie să plătești din nou.';
        }
        return '';
    }

    /**
     * Linkul de plată întreagă plătit printr-o plată a comenzii însăși (Banca Transilvania: plata obișnuită a comenzii,
     * pornită din link, a făcut deja comanda plătită): doar linkul trece „plătit”, comanda nu se mai atinge aici.
     * Se cheamă în tranzacția plății BT, după scrierea comenzii; o eroare merge mai departe (tranzacția se anulează).
     */
    public static function marcheazaPlatit(PDO $db, array $link, string $epId = '', string $approval = ''): bool
    {
        $linkId = (int) ($link['id'] ?? 0);
        if ($linkId <= 0) {
            return false;
        }
        $stmt = $db->prepare(
            'UPDATE order_payment_links SET status = :platit, paid_at = NOW(), ep_id = :ep_id, approval = :approval
             WHERE id = :id AND status IN (:asteptare, :anulat)'
        );
        $stmt->execute([
            'platit' => self::STATUS_PLATIT,
            'ep_id' => $epId !== '' ? substr($epId, 0, 64) : null,
            'approval' => $approval !== '' ? substr($approval, 0, 64) : null,
            'id' => $linkId,
            'asteptare' => self::STATUS_ASTEPTARE,
            'anulat' => self::STATUS_ANULAT,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** Anulează un link încă neplătit (întors: dacă acum e anulat). */
    public static function anuleaza(PDO $db, int $linkId): bool
    {
        if ($linkId <= 0) {
            return false;
        }
        try {
            $stmt = $db->prepare('UPDATE order_payment_links SET status = :anulat WHERE id = :id AND status = :asteptare');
            $stmt->execute(['anulat' => self::STATUS_ANULAT, 'id' => $linkId, 'asteptare' => self::STATUS_ASTEPTARE]);
            return $stmt->rowCount() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Comanda tocmai a devenit plătită (sau a primit bani): orice link încă neplătit al ei mai mare decât restul de
     * încasat de acum se anulează — linkurile de plată întreagă, dar și o diferență încasată între timp pe alt drum —,
     * ca nimeni să nu mai plătească o dată. Se cheamă din fiecare drum al plății: checkout (EuPlătesc, Stripe, BT, cu
     * callback, cron sau întoarcerea clientului), linkurile, „Plătit prin link extern”, „Înregistrează încasarea”.
     * Blochează rândul comenzii întâi, apoi linkurile (aceeași ordine ca la confirmare). Întoarce câte s-au anulat.
     */
    public static function anuleazaDepasite(PDO $db, int $orderId): int
    {
        if ($orderId <= 0) {
            return 0;
        }
        $amDeschis = false;
        try {
            if (!$db->inTransaction()) {
                $db->beginTransaction();
                $amDeschis = true;
            }
            $stmt = $db->prepare('SELECT id, total, paid_amount, payment_status FROM orders WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $orderId]);
            $n = self::anuleazaDepasiteBlocat($db, (array) ($stmt->fetch() ?: []));
            if ($amDeschis) {
                $db->commit();
            }
            return $n;
        } catch (Throwable $eroare) {
            if (!$amDeschis) {
                throw $eroare;
            }
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return 0;
        }
    }

    /** @param array<string, mixed> $comanda rândul comenzii, deja blocat */
    private static function anuleazaDepasiteBlocat(PDO $db, array $comanda): int
    {
        if ((int) ($comanda['id'] ?? 0) <= 0 || strtolower((string) ($comanda['payment_status'] ?? '')) !== 'paid') {
            return 0;
        }
        $stmt = $db->prepare(
            'UPDATE order_payment_links SET status = :anulat
             WHERE order_id = :order_id AND status = :asteptare AND amount > :rest'
        );
        $stmt->execute([
            'anulat' => self::STATUS_ANULAT,
            'order_id' => (int) $comanda['id'],
            'asteptare' => self::STATUS_ASTEPTARE,
            'rest' => number_format(self::restDeIncasat($comanda) + 0.009, 3, '.', ''),
        ]);
        return $stmt->rowCount();
    }

    /**
     * De ce a fost anulat un link, pentru client (gol = un motiv obișnuit, „Cere-ne unul nou”): comanda plătită între
     * timp (link de plată întreagă) sau diferența încasată (link de diferență mai mare decât restul de acum).
     */
    public static function motivAnulare(PDO $db, array $link): string
    {
        if ((string) ($link['status'] ?? '') !== self::STATUS_ANULAT) {
            return '';
        }
        try {
            $stmt = $db->prepare('SELECT total, paid_amount, payment_status FROM orders WHERE id = :id AND deleted_at IS NULL LIMIT 1');
            $stmt->execute(['id' => (int) ($link['order_id'] ?? 0)]);
            $comanda = $stmt->fetch();
        } catch (Throwable) {
            return '';
        }
        if (!is_array($comanda) || self::nimicIncasat($comanda)) {
            return '';
        }
        if (self::esteIntegral($link)) {
            return 'Comanda a fost deja plătită; nu e nevoie să plătești din nou.';
        }
        return round((float) ($link['amount'] ?? 0), 2) > self::restDeIncasat($comanda) + 0.009
            ? 'Diferența a fost deja încasată; nu e nevoie să plătești din nou.' : '';
    }

    /** Linkul de plată întreagă plătit al comenzii (cel mai nou), dacă are. @return array<string, mixed>|null */
    public static function integralPlatit(PDO $db, int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }
        try {
            $stmt = $db->prepare("SELECT * FROM order_payment_links WHERE order_id = :order_id AND status = :platit
                AND referinta REGEXP '-PI[0-9]+$' ORDER BY id DESC LIMIT 1");
            $stmt->execute(['order_id' => $orderId, 'platit' => self::STATUS_PLATIT]);
            $rand = $stmt->fetch();
            return is_array($rand) ? $rand : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * O plată a comenzii însăși (EuPlătesc, Stripe) s-a confirmat pe o comandă deja plătită: printr-un link de plată
     * întreagă, sau printr-o altă plată a comenzii (alt ep_id, altă sesiune Stripe decât cea socotită). Banii au venit
     * de două ori: comanda primește nota „Plătită de două ori”, cu plata de rambursat (aceasta, cu id-ul ei) și cea
     * socotită; suma încasată rămâne a unei singure plăți. `$socotitaAnterior`: id-ul plății socotite, dacă se știe
     * (ep_id-ul, sesiunea Stripe de pe comandă); gol = doar un link de plată întreagă plătit face din ea o dublură.
     * Întoarce true dacă a pus nota.
     */
    public static function semnaleazaPlataComandaDubla(PDO $db, int $orderId, string $procesator, string $id, float $suma,
        string $socotitaAnterior = ''): bool
    {
        if ($orderId <= 0) {
            return false;
        }
        $link = self::integralPlatit($db, $orderId);
        if ($link === null && ($socotitaAnterior === '' || $id === '' || hash_equals($socotitaAnterior, $id))) {
            return false;
        }
        try {
            $stmt = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $orderId]);
            $comanda = $stmt->fetch();
            if (!is_array($comanda)) {
                return false;
            }
            $socotita = $link !== null
                ? self::descrierePlata(self::procesator($link), (string) ($link['ep_id'] ?? ''), (float) $link['amount'], 'linkul ' . (string) $link['referinta'])
                : self::descrierePlata($procesator, $socotitaAnterior, (float) ($comanda['paid_amount'] ?? $suma), 'plata comenzii');
            self::scrieNotaDubla($db, $comanda, self::descrierePlata($procesator, $id, $suma, 'plata comenzii, confirmată a doua oară'),
                $socotita, $procesator);
        } catch (Throwable) {
            return false;
        }
        return true;
    }

    /** O plată în nota „Plătită de două ori”: procesatorul, id-ul ei la procesator, suma și de unde vine. */
    public static function descrierePlata(string $procesator, string $id, float $suma, string $despre = ''): string
    {
        $eticheta = match (self::procesator(['procesator' => $procesator])) {
            self::PROCESATOR_STRIPE => 'Stripe, sesiunea ',
            self::PROCESATOR_BT => 'Banca Transilvania, plata ',
            default => 'EuPlătesc, ep_id ',
        };
        return $eticheta . ($id !== '' ? $id : '(fără id)') . ', ' . number_format($suma, 2, ',', '.') . ' lei'
            . ($despre !== '' ? ' (' . $despre . ')' : '');
    }

    /**
     * Plata socotită acum pe comandă (cea care NU se rambursează): linkul de plată întreagă plătit (altul decât
     * `$faraLinkul`), altfel plata comenzii după metoda ei (ep_id-ul EuPlătesc, sesiunea Stripe, plata BT socotită).
     *
     * @param array<string, mixed> $comanda
     */
    public static function plataSocotita(PDO $db, array $comanda, int $faraLinkul = 0): string
    {
        $orderId = (int) ($comanda['id'] ?? 0);
        $incasat = (float) ($comanda['paid_amount'] ?? $comanda['total'] ?? 0);
        try {
            $stmt = $db->prepare("SELECT * FROM order_payment_links WHERE order_id = :o AND status = 'paid' AND id <> :fara
                AND referinta REGEXP '-PI[0-9]+$' ORDER BY id DESC LIMIT 1");
            $stmt->execute(['o' => $orderId, 'fara' => $faraLinkul]);
            $link = $stmt->fetch();
            if (is_array($link)) {
                return self::descrierePlata(self::procesator($link), (string) ($link['ep_id'] ?? ''), (float) $link['amount'],
                    'linkul ' . (string) $link['referinta']);
            }
            $metoda = strtolower((string) ($comanda['payment_method'] ?? ''));
            if ($metoda === PaymentMethods::BT_IPAY && BtIpayPayments::areTabele($db)) {
                $stmt = $db->prepare("SELECT bt_order_number FROM bt_ipay_transactions WHERE order_id = :o AND kind = 'order' AND in_comanda = 1
                    ORDER BY id DESC LIMIT 1");
                $stmt->execute(['o' => $orderId]);
                return self::descrierePlata(self::PROCESATOR_BT, (string) ($stmt->fetchColumn() ?: ''), $incasat, 'plata comenzii');
            }
            if ($metoda === 'stripe') {
                return self::descrierePlata(self::PROCESATOR_STRIPE, (string) ($comanda['stripe_session_id'] ?? ''), $incasat, 'plata comenzii');
            }
            if ($metoda === 'euplatesc') {
                return self::descrierePlata(self::PROCESATOR_EUPLATESC, (string) ($comanda['euplatesc_ep_id'] ?? ''), $incasat, 'plata comenzii');
            }
        } catch (Throwable) {
            // Fără detalii: nota spune doar suma.
        }
        return 'plata comenzii, ' . number_format($incasat, 2, ',', '.') . ' lei';
    }

    /**
     * Scrie pe comandă nota „Plătită de două ori”: plata de rambursat (nesocotită pe comandă), cu procesatorul și id-ul
     * ei, plata socotită (care NU se rambursează și nu se eliberează) și cum se rambursează. O a doua dublură pe aceeași
     * comandă se adaugă la notă. Și în jurnalul adminilor.
     *
     * @param array<string, mixed> $comanda
     */
    public static function scrieNotaDubla(PDO $db, array $comanda, string $deRambursat, string $socotita, string $procesatorDeRambursat): void
    {
        $cum = match (self::procesator(['procesator' => $procesatorDeRambursat])) {
            self::PROCESATOR_STRIPE => 'Rambursarea se face din panoul Stripe, pe sesiunea de mai sus.',
            self::PROCESATOR_BT => 'Rambursarea se face din fereastra comenzii, la plata BT de mai sus („Anulează autorizarea” dacă e doar '
                . 'autorizată, altfel „Rambursează”).',
            default => 'Rambursarea se face din panoul EuPlătesc, pe ep_id-ul de mai sus.',
        };
        $parte = 'De rambursat (nesocotită pe comandă): ' . $deRambursat . '. Socotită pe comandă (nu o rambursa, nu o elibera): '
            . $socotita . '. ' . $cum;
        $acum = trim((string) ($comanda['payment_error'] ?? ''));
        $nota = str_starts_with($acum, self::NOTA_DUBLA)
            ? (str_contains($acum, $deRambursat) ? $acum : $acum . ' | ' . $parte)
            : self::NOTA_DUBLA . '. ' . $parte;
        $db->prepare('UPDATE orders SET payment_error = :nota WHERE id = :id')->execute([
            'nota' => mb_substr($nota, 0, 2000),
            'id' => (int) ($comanda['id'] ?? 0),
        ]);
        AdminActivityLog::log($db, 'order_platita_de_doua_ori', [
            'order_id' => (int) ($comanda['id'] ?? 0),
            'de_rambursat' => mb_substr($deRambursat, 0, 300),
            'socotita' => mb_substr($socotita, 0, 300),
        ]);
    }

    /** Comanda poartă nota „Plătită de două ori”? */
    public static function platitaDeDouaOri(array $order): bool
    {
        return str_starts_with(trim((string) ($order['payment_error'] ?? '')), self::NOTA_DUBLA);
    }

    public static function numeProcesator(string $procesator): string
    {
        return match ($procesator) {
            self::PROCESATOR_BT => 'Banca Transilvania',
            self::PROCESATOR_STRIPE => 'Stripe',
            default => 'EuPlătesc',
        };
    }

    /**
     * Linkurile comenzii, pentru fereastra comenzii din admin: referința, suma, felul (diferență / plată întreagă),
     * starea („trimis”, „plătit”, „anulat”, „expirat”), procesatorul și datele; adresa doar pentru cele încă valabile.
     *
     * @param array<string, mixed> $order
     * @return list<array<string, mixed>>
     */
    public static function pentruAdmin(PDO $db, array $order): array
    {
        $orderId = (int) ($order['id'] ?? 0);
        if ($orderId <= 0) {
            return [];
        }
        try {
            $stmt = $db->prepare('SELECT * FROM order_payment_links WHERE order_id = :id ORDER BY id DESC LIMIT 50');
            $stmt->execute(['id' => $orderId]);
            $randuri = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }
        $lista = [];
        foreach ($randuri as $rand) {
            if (!is_array($rand)) {
                continue;
            }
            $stare = (string) ($rand['status'] ?? '');
            $eticheta = match (true) {
                $stare === self::STATUS_PLATIT => 'plătit',
                $stare === self::STATUS_ANULAT => 'anulat',
                self::esteExpirat($rand) => 'expirat',
                default => 'trimis',
            };
            $lista[] = [
                'referinta' => (string) ($rand['referinta'] ?? ''),
                'suma' => round((float) ($rand['amount'] ?? 0), 2),
                'tip' => self::esteIntegral($rand) ? self::TIP_INTEGRALA : self::TIP_DIFERENTA,
                'stare' => $eticheta,
                'procesator' => self::numeProcesator(self::procesator($rand)),
                'creat_la' => (string) ($rand['created_at'] ?? ''),
                'platit_la' => (string) ($rand['paid_at'] ?? ''),
                'url' => $eticheta === 'trimis' ? AppUrl::absolut('/plata/' . rawurlencode((string) ($rand['token'] ?? ''))) : '',
            ];
        }
        return $lista;
    }

    /** Linkul neplătit, neexpirat, pentru aceeași sumă și de același fel. @return array<string, mixed>|null */
    private static function linkActivPentruSuma(PDO $db, int $orderId, float $suma, bool $integral = false): ?array
    {
        $stmt = $db->prepare(
            "SELECT * FROM order_payment_links
             WHERE order_id = :order_id AND status = :asteptare AND amount = :amount
               AND referinta " . ($integral ? '' : 'NOT ') . "REGEXP '-PI[0-9]+$'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([
            'order_id' => $orderId,
            'asteptare' => self::STATUS_ASTEPTARE,
            'amount' => number_format($suma, 2, '.', ''),
        ]);
        $rand = $stmt->fetch();
        if (!is_array($rand) || self::esteExpirat($rand)) {
            return null;
        }
        return $rand;
    }

    private static function urmatorulNumar(PDO $db, int $orderId): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM order_payment_links WHERE order_id = :order_id');
        $stmt->execute(['order_id' => $orderId]);
        return max(1, (int) $stmt->fetchColumn() + 1);
    }
}
