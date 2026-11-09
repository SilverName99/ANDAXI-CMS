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
 * Plata întreagă (9 nov 2026): o comandă cu cardul a cărei plată a eșuat, a expirat, a fost
 * anulată sau n-a fost terminată, fără nimic încasat, primește din admin un link pentru TOT totalul („Trimite link
 * de plată”). Linkul e tot un rând aici, cu aceeași referință `{numar_comanda}-P{n}`; ce fel de link e se vede din
 * comandă (`esteIntegral`: suma lui acoperă totalul), deci tabelul nu are nicio coloană nouă. Plătit, linkul face
 * comanda plătită exact ca o plată reușită din checkout (`confirmaPlataDetaliat`, `efecteDupaConfirmare`): starea,
 * emailul „comandă nouă”, trimiterea în ERP. La BT linkul pornește o plată obișnuită a comenzii, cu linkul ținut pe
 * ea (BtIpayPayments::pornestePlataIntegrala), deci autorizarea, încasarea la factura din ERP sau la 96 de ore și
 * eliberarea rămân cele ale comenzii. Gărzile împotriva plății duble: linkul nu se trimite cât o plată a comenzii e
 * autorizată sau în curs (`plataIntegrala`), se anulează când plata inițială se confirmă totuși
 * (`anuleazaIntegralePentruComanda`), nu mai pornește pe o comandă plătită (`motivLinkNeplatibil`), iar o plată
 * venită oricum peste una deja socotită lasă pe comandă nota „Plătită de două ori” (`NOTA_DUBLA`), cu ce e de rambursat.
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
     * Creează un link de plată. Un link nefolosit pentru aceeași sumă se
     * refolosește, ca să nu umplem tabelul la retrimiteri — dacă procesatorul
     * lui mai e disponibil (un link EuPlătesc trimis deja merge mai departe pe
     * EuPlătesc). Linkul nou primește procesatorul dat.
     *
     * @return array<string, mixed>|null
     */
    public static function creeaza(PDO $db, int $orderId, string $orderNumber, float $suma, string $procesator = self::PROCESATOR_EUPLATESC, array $settings = []): ?array
    {
        self::ensureSchema($db);
        $suma = round($suma, 2);
        if ($orderId <= 0 || $suma <= 0) {
            return null;
        }
        $procesator = self::procesator(['procesator' => $procesator]);

        $existent = self::linkActivPentruSuma($db, $orderId, $suma);
        if ($existent !== null && ($settings === [] || self::procesorDisponibil(self::procesator($existent), $settings))) {
            return $existent;
        }

        // Anulăm linkurile în așteptare pentru alte sume: nu mai sunt valide.
        try {
            $db->prepare(
                'UPDATE order_payment_links SET status = :anulat
                 WHERE order_id = :order_id AND status = :asteptare'
            )->execute([
                'anulat' => self::STATUS_ANULAT,
                'order_id' => $orderId,
                'asteptare' => self::STATUS_ASTEPTARE,
            ]);
        } catch (Throwable) {
            return null;
        }

        for ($incercare = 1; $incercare <= 20; $incercare++) {
            $numar = self::urmatorulNumar($db, $orderId);
            $referinta = substr($orderNumber, 0, 50) . '-P' . $numar;
            $token = bin2hex(random_bytes(24));
            try {
                $db->prepare(
                    'INSERT INTO order_payment_links (order_id, referinta, token, amount, status, procesator, expires_at)
                     VALUES (:order_id, :referinta, :token, :amount, :status, :procesator, :expires_at)'
                )->execute([
                    'order_id' => $orderId,
                    'referinta' => $referinta,
                    'token' => $token,
                    'amount' => number_format($suma, 2, '.', ''),
                    'status' => self::STATUS_ASTEPTARE,
                    'procesator' => $procesator,
                    'expires_at' => date('Y-m-d H:i:s', time() + self::VALABILITATE_ZILE * 86400),
                ]);
                return self::dupaToken($db, $token);
            } catch (Throwable) {
                // Referință deja folosită (două cereri simultane) — reîncercăm.
                continue;
            }
        }
        return null;
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

    /** Referința arată ca un link de plată („204026-P1")? */
    public static function pareReferintaDeLink(string $referinta): bool
    {
        return preg_match('/-P\d+$/', trim($referinta)) === 1;
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
     *  - `dublura`: un link de plată întreagă plătit peste o plată deja socotită (plata inițială a venit totuși, de
     *    exemplu printr-o confirmare întârziată) sau pe o comandă anulată: banii s-au luat, deci linkul rămâne plătit,
     *    dar nu se adună nimic; comanda primește nota „Plătită de două ori”, cu ce e de rambursat;
     *  - `diferenta`: ca până acum, suma se adaugă la cea încasată.
     * Și un link anulat se trece plătit dacă plata lui vine totuși (clientul era deja pe pagina procesatorului):
     * banii au fost luați și trebuie să se vadă. Doar prima confirmare schimbă ceva (`nou`).
     *
     * @return array{nou: bool, tip: string, order_id: int, eroare_anterioara?: string}
     */
    public static function confirmaPlataDetaliat(PDO $db, array $link, string $epId = '', string $approval = ''): array
    {
        $linkId = (int) ($link['id'] ?? 0);
        $orderId = (int) ($link['order_id'] ?? 0);
        $suma = round((float) ($link['amount'] ?? 0), 2);
        $rezultat = ['nou' => false, 'tip' => self::TIP_DIFERENTA, 'order_id' => $orderId];
        if ($linkId <= 0 || $orderId <= 0 || $suma <= 0) {
            return $rezultat;
        }

        // Coloanele ERP înaintea tranzacției: un DDL în mijlocul ei ar închide-o.
        ErpSync::ensureSchema($db);
        $amDeschis = false;
        try {
            if (!$db->inTransaction()) {
                $db->beginTransaction();
                $amDeschis = true;
            }
            // Doar tranziția „în așteptare" (sau „anulat") → „plătit" schimbă comanda.
            $stmt = $db->prepare(
                'UPDATE order_payment_links
                 SET status = :platit, paid_at = NOW(), ep_id = :ep_id, approval = :approval
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
            if ($stmt->rowCount() < 1) {
                if ($amDeschis) {
                    $db->commit();
                }
                return $rezultat;
            }

            $stmt = $db->prepare(
                'SELECT id, order_number, total, paid_amount, payment_status, status, payment_error
                 FROM orders WHERE id = :id AND deleted_at IS NULL LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['id' => $orderId]);
            $comanda = $stmt->fetch();
            $comanda = is_array($comanda) ? $comanda : null;

            if ($comanda !== null && self::nimicIncasat($comanda) && !self::comandaInchisa($comanda)) {
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
                     WHERE id = :order_id AND deleted_at IS NULL"
                )->execute([
                    'suma' => number_format($suma, 2, '.', ''),
                    'order_id' => $orderId,
                ]);
            } elseif ($comanda !== null && (self::comandaInchisa($comanda) && self::nimicIncasat($comanda)
                || (self::esteIntegral($link, $comanda) && !self::nimicIncasat($comanda)))) {
                $rezultat['tip'] = self::TIP_DUBLURA;
                $db->prepare('UPDATE orders SET payment_error = :nota WHERE id = :order_id')->execute([
                    'nota' => mb_substr(self::notaDubla($link, $comanda), 0, 1000),
                    'order_id' => $orderId,
                ]);
            } else {
                $db->prepare(
                    'UPDATE orders
                     SET paid_amount = ROUND(COALESCE(paid_amount, total) + :suma, 2),
                         payment_status = \'paid\',
                         paid_at = COALESCE(paid_at, NOW())
                     WHERE id = :order_id AND deleted_at IS NULL'
                )->execute([
                    'suma' => number_format($suma, 2, '.', ''),
                    'order_id' => $orderId,
                ]);
            }
            if ($amDeschis) {
                $db->commit();
            }
        } catch (Throwable) {
            if ($amDeschis && $db->inTransaction()) {
                $db->rollBack();
            }
            return $rezultat;
        }

        $rezultat['nou'] = true;
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
            return;
        }
        AdminActivityLog::log($db, 'order_platita_de_doua_ori', ['order_id' => $orderId]);
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
     * Linkul acoperă tot totalul comenzii (un link de plată întreagă)? O diferență e mereu mai mică decât totalul
     * (pe comandă era deja ceva încasat), deci suma deosebește cele două feluri fără o coloană în plus.
     */
    public static function esteIntegral(array $link, array $order): bool
    {
        $total = round((float) ($order['total'] ?? 0), 2);
        $suma = round((float) ($link['amount'] ?? 0), 2);
        return $total > 0 && $suma > 0 && $suma >= $total - 0.01;
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
     * De ce nu se mai poate plăti acum un link de plată întreagă (gol = se poate). Linkurile pentru diferență merg ca
     * până acum. Comanda plătită între timp (plata inițială confirmată totuși), anulată sau încheiată nu se mai plătește
     * prin link; nici una al cărei total a scăzut după trimitere (se cere un link nou, cu suma de acum). Un total crescut
     * face din link unul mai mic decât totalul: se plătește, iar restul rămâne de cerut ca diferență.
     *
     * @param array<string, mixed> $order
     */
    public static function motivLinkNeplatibil(array $link, array $order): string
    {
        if ((string) ($link['status'] ?? '') !== self::STATUS_ASTEPTARE || !self::esteIntegral($link, $order)) {
            return '';
        }
        if (!self::nimicIncasat($order)) {
            return 'Comanda a fost deja plătită; nu e nevoie să plătești din nou.';
        }
        if (self::comandaInchisa($order) || ($order['deleted_at'] ?? null) !== null) {
            return 'Comanda a fost anulată sau încheiată, deci linkul nu mai poate fi plătit.';
        }
        if (round((float) ($link['amount'] ?? 0), 2) > round((float) ($order['total'] ?? 0), 2) + 0.01) {
            return 'Totalul comenzii s-a schimbat de când ți-am trimis linkul. Scrie-ne și îți trimitem unul nou.';
        }
        return '';
    }

    /**
     * Linkul de plată întreagă plătit printr-o plată a comenzii însăși (Banca Transilvania: plata obișnuită a comenzii,
     * pornită din link, a făcut deja comanda plătită): doar linkul trece „plătit”, comanda nu se mai atinge aici.
     */
    public static function marcheazaPlatit(PDO $db, array $link, string $epId = '', string $approval = ''): bool
    {
        $linkId = (int) ($link['id'] ?? 0);
        if ($linkId <= 0) {
            return false;
        }
        try {
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
        } catch (Throwable) {
            return false;
        }
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
     * Comanda tocmai a devenit plătită pe alt drum (plata inițială confirmată totuși, de callback, de cron sau de
     * întoarcerea clientului; marcată plătită din admin): linkurile ei de plată întreagă încă neplătite se anulează,
     * ca nimeni să nu mai plătească o dată. Diferențele nu se ating. Întoarce câte s-au anulat.
     */
    public static function anuleazaIntegralePentruComanda(PDO $db, int $orderId): int
    {
        if ($orderId <= 0) {
            return 0;
        }
        try {
            $stmt = $db->prepare(
                'UPDATE order_payment_links l JOIN orders o ON o.id = l.order_id
                 SET l.status = :anulat
                 WHERE l.order_id = :order_id AND l.status = :asteptare AND o.total > 0 AND l.amount >= ROUND(o.total - 0.01, 2)'
            );
            $stmt->execute(['anulat' => self::STATUS_ANULAT, 'order_id' => $orderId, 'asteptare' => self::STATUS_ASTEPTARE]);
            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }

    /** Un link anulat, de plată întreagă, al unei comenzi plătite între timp (clientul nu mai are nimic de plătit)? */
    public static function anulatFiindcaPlatita(PDO $db, array $link): bool
    {
        if ((string) ($link['status'] ?? '') !== self::STATUS_ANULAT) {
            return false;
        }
        try {
            $stmt = $db->prepare('SELECT total, paid_amount, payment_status FROM orders WHERE id = :id AND deleted_at IS NULL LIMIT 1');
            $stmt->execute(['id' => (int) ($link['order_id'] ?? 0)]);
            $comanda = $stmt->fetch();
        } catch (Throwable) {
            return false;
        }
        return is_array($comanda) && self::esteIntegral($link, $comanda) && !self::nimicIncasat($comanda);
    }

    /** Linkul de plată întreagă plătit al comenzii (cel mai nou), dacă are. @return array<string, mixed>|null */
    public static function integralPlatit(PDO $db, int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }
        try {
            $stmt = $db->prepare(
                'SELECT l.* FROM order_payment_links l JOIN orders o ON o.id = l.order_id
                 WHERE l.order_id = :order_id AND l.status = :platit AND o.total > 0 AND l.amount >= ROUND(o.total - 0.01, 2)
                 ORDER BY l.id DESC LIMIT 1'
            );
            $stmt->execute(['order_id' => $orderId, 'platit' => self::STATUS_PLATIT]);
            $rand = $stmt->fetch();
            return is_array($rand) ? $rand : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Plata inițială a comenzii (EuPlătesc, Stripe) s-a confirmat pe o comandă deja plătită printr-un link de plată
     * întreagă: banii au venit de două ori. Comanda primește nota „Plătită de două ori”, cu ce e de rambursat; suma
     * încasată rămâne a unei singure plăți. Întoarce true dacă a pus nota.
     */
    public static function semnaleazaPlataInitialaDubla(PDO $db, int $orderId, string $procesator, string $referinta): bool
    {
        $link = self::integralPlatit($db, $orderId);
        if ($link === null) {
            return false;
        }
        $nota = self::NOTA_DUBLA . ': plata inițială a comenzii (' . $procesator . ($referinta !== '' ? ', ' . $referinta : '')
            . ') s-a confirmat după ce comanda fusese plătită prin linkul de plată ' . (string) ($link['referinta'] ?? '') . ' ('
            . number_format((float) ($link['amount'] ?? 0), 2, ',', '.') . ' lei, ' . self::numeProcesator((string) ($link['procesator'] ?? ''))
            . '). ' . self::INDICIU_RAMBURSARE;
        try {
            $db->prepare('UPDATE orders SET payment_error = :nota WHERE id = :id')->execute(['nota' => mb_substr($nota, 0, 1000), 'id' => $orderId]);
        } catch (Throwable) {
            return false;
        }
        AdminActivityLog::log($db, 'order_platita_de_doua_ori', ['order_id' => $orderId, 'link' => (string) ($link['referinta'] ?? '')]);
        return true;
    }

    private const INDICIU_RAMBURSARE = 'Rambursează una dintre plăți: o autorizare Banca Transilvania încă neîncasată se eliberează din '
        . 'fereastra comenzii („Anulează autorizarea”), o plată EuPlătesc sau Stripe se rambursează din panoul procesatorului. '
        . 'Pe comandă e socotită o singură plată.';

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

    /** @param array<string, mixed> $comanda */
    private static function notaDubla(array $link, array $comanda): string
    {
        $suma = number_format((float) ($link['amount'] ?? 0), 2, ',', '.') . ' lei';
        $cum = self::numeProcesator((string) ($link['procesator'] ?? ''));
        if (self::comandaInchisa($comanda) && self::nimicIncasat($comanda)) {
            return self::NOTA_DUBLA . '? Linkul de plată ' . (string) ($link['referinta'] ?? '') . ' (' . $suma . ', ' . $cum
                . ') a fost plătit după ce comanda fusese anulată sau încheiată; plata nu a fost socotită pe comandă. '
                . 'Rambursează-o clientului din panoul procesatorului, dacă nu reactivezi comanda.';
        }
        return self::NOTA_DUBLA . ': linkul de plată ' . (string) ($link['referinta'] ?? '') . ' (' . $suma . ', ' . $cum
            . ') a fost plătit după ce comanda era deja încasată. ' . self::INDICIU_RAMBURSARE;
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
                'tip' => self::esteIntegral($rand, $order) ? self::TIP_INTEGRALA : self::TIP_DIFERENTA,
                'stare' => $eticheta,
                'procesator' => self::numeProcesator(self::procesator($rand)),
                'creat_la' => (string) ($rand['created_at'] ?? ''),
                'platit_la' => (string) ($rand['paid_at'] ?? ''),
                'url' => $eticheta === 'trimis' ? AppUrl::absolut('/plata/' . rawurlencode((string) ($rand['token'] ?? ''))) : '',
            ];
        }
        return $lista;
    }

    /** @return array<string, mixed>|null */
    private static function linkActivPentruSuma(PDO $db, int $orderId, float $suma): ?array
    {
        try {
            $stmt = $db->prepare(
                'SELECT * FROM order_payment_links
                 WHERE order_id = :order_id AND status = :asteptare AND amount = :amount
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([
                'order_id' => $orderId,
                'asteptare' => self::STATUS_ASTEPTARE,
                'amount' => number_format($suma, 2, '.', ''),
            ]);
        } catch (Throwable) {
            return null;
        }
        $rand = $stmt->fetch();
        if (!is_array($rand) || self::esteExpirat($rand)) {
            return null;
        }
        return $rand;
    }

    private static function urmatorulNumar(PDO $db, int $orderId): int
    {
        try {
            $stmt = $db->prepare('SELECT COUNT(*) FROM order_payment_links WHERE order_id = :order_id');
            $stmt->execute(['order_id' => $orderId]);
            return max(1, (int) $stmt->fetchColumn() + 1);
        } catch (Throwable) {
            return 1;
        }
    }
}
