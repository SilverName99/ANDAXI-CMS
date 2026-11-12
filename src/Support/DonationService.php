<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Suport pentru donații (Stripe direct): configurare din setări, calcule de comision, istoricul unui donator și
 * evenimentele lunare ale abonamentelor. Popup-ul „SUSȚINE" folosește aceste date.
 *
 * Pluginul `donatii` (din vertij 32db865 … 8412906 și dc52498 … ca1120c, la 9ab7999): copiat cum e, fără `ensureSchema`
 * (tabelul îl face migrarea `donatii/0062`, niciun DDL pe cerere), cu schimbările codului comun:
 *  - `donationsFor`: donațiile făcute fără cont (`user_id` gol) cu adresa unui cont apar în contul lui doar după ce adresa e
 *    dovedită (regula comenzilor fără cont a nucleului, G-M4, OD-47; App\Support\ConfirmareEmail); cele ale altui cont, niciodată;
 *  - o factură lunară neplătită (`invoice.payment_failed`) e un rând „failed” al lunii ei (plătit mai târziu, același rând
 *    devine „paid”); o rambursare (`charge.refunded`) se notează pe rândul plății (`refunded_cents`, `donatii/0064`);
 *  - mai multe donații lunare active ale aceleiași persoane (`activeSubscriptions`), fiecare oprită separat.
 */
final class DonationService
{
    /** Suma maximă a unei donații online, în lei (revizuirea de securitate, 9 nov 2026); mai mult, prin transfer. */
    public const MAX_LEI = 100000;

    /** Donațiile pornite din sertar de același vizitator în 15 minute (LimitaCereri). */
    public const INCERCARI_LA_15_MINUTE = 10;

    /**
     * Înregistrează plata unei luni dintr-o donație recurentă. Prima lună e deja
     * salvată la crearea abonamentului; de la a doua încolo, Stripe trimite
     * facturi noi, iar fiecare devine un rând propriu în istoric.
     *
     * @return int|null id-ul rândului afectat, sau null dacă nu e nimic nou
     */
    public static function recordRecurringCharge(PDO $db, array $invoice, bool $paid = true): ?int
    {
        $subscriptionId = trim((string) (
            $invoice['subscription']
            ?? $invoice['parent']['subscription_details']['subscription']
            ?? ''
        ));
        $invoiceId = trim((string) ($invoice['id'] ?? ''));
        if ($subscriptionId === '' || $invoiceId === '') {
            return null;
        }

        try {
            // Rândul original al abonamentului ne dă donatorul și destinația.
            $stmt = $db->prepare(
                'SELECT * FROM donations
                 WHERE stripe_subscription_id = :sub
                 ORDER BY id ASC LIMIT 1'
            );
            $stmt->execute(['sub' => $subscriptionId]);
            $origin = $stmt->fetch();
        } catch (Throwable) {
            return null;
        }

        if (!is_array($origin)) {
            return null;
        }

        // O factură deja notată (de exemplu una neplătită, plătită apoi la reîncercarea lui Stripe): același rând, nicio dublură.
        try {
            $existent = $db->prepare('SELECT id, status FROM donations WHERE stripe_invoice_id = :inv LIMIT 1');
            $existent->execute(['inv' => $invoiceId]);
            $randFactura = $existent->fetch();
        } catch (Throwable) {
            return null;
        }
        if (is_array($randFactura)) {
            // O lună plătită sau rambursată nu se mai schimbă (un eveniment întârziat nu desface o rambursare și nu trimite al
            // doilea email de mulțumire); doar una „failed”/„pending” trece în „paid”, o singură dată.
            if ($paid && !in_array((string) ($randFactura['status'] ?? ''), ['paid', 'refunded'], true)) {
                $platita = $db->prepare("UPDATE donations SET status = 'paid', paid_at = COALESCE(paid_at, NOW()),
                        receipt_url = COALESCE(:receipt, receipt_url) WHERE id = :id AND status NOT IN ('paid', 'refunded')");
                $platita->execute(['receipt' => trim((string) ($invoice['hosted_invoice_url'] ?? '')) ?: null, 'id' => (int) $randFactura['id']]);
                return $platita->rowCount() === 1 ? (int) $randFactura['id'] : null;
            }
            return null;
        }
        if (!$paid) {
            return self::recordFailedInvoice($db, $origin, $invoice, $subscriptionId, $invoiceId);
        }

        // Prima factură aparține rândului deja existent: îl marcăm, nu dublăm.
        if (trim((string) ($origin['stripe_invoice_id'] ?? '')) === '') {
            try {
                // Factura se leagă mereu de rând; starea trece în „paid” doar dacă nu era deja plătită sau rambursată (un
                // rând deja plătit pe payment_intent.succeeded nu primește al doilea email, o rambursare nu se desface).
                $db->prepare('UPDATE donations SET stripe_invoice_id = :inv WHERE id = :id')
                    ->execute(['inv' => $invoiceId, 'id' => (int) $origin['id']]);
                $platita = $db->prepare(
                    "UPDATE donations
                     SET status = 'paid',
                         paid_at = COALESCE(paid_at, NOW()),
                         receipt_url = COALESCE(:receipt, receipt_url)
                     WHERE id = :id AND status NOT IN ('paid', 'refunded')"
                );
                $platita->execute([
                    'receipt' => trim((string) ($invoice['hosted_invoice_url'] ?? '')) ?: null,
                    'id' => (int) $origin['id'],
                ]);
                return $platita->rowCount() === 1 ? (int) $origin['id'] : null;
            } catch (Throwable) {
                return null;
            }
        }

        if (trim((string) ($origin['stripe_invoice_id'] ?? '')) === $invoiceId) {
            return null;
        }

        $amount = (int) ($invoice['amount_paid'] ?? $invoice['total'] ?? $origin['amount_cents'] ?? 0);
        $paymentIntentId = trim((string) (
            $invoice['payment_intent']
            ?? $invoice['payments']['data'][0]['payment']['payment_intent']
            ?? ''
        ));

        try {
            $insert = $db->prepare(
                'INSERT INTO donations
                    (reference, user_id, donor_name, donor_email, amount_cents, fee_cents, currency,
                     frequency, designation, cover_fees, newsletter_optin, consent, status,
                     stripe_payment_intent_id, stripe_subscription_id, stripe_customer_id,
                     stripe_invoice_id, receipt_url, paid_at)
                 VALUES
                    (:reference, :user_id, :name, :email, :amount, 0, :currency,
                     "monthly", :designation, :cover, 0, 1, "paid",
                     :pi, :sub, :customer, :invoice, :receipt, NOW())'
            );
            $insert->execute([
                'reference' => 'DON-' . strtoupper(bin2hex(random_bytes(4))),
                'user_id' => $origin['user_id'] ?? null,
                'name' => $origin['donor_name'] ?? null,
                'email' => $origin['donor_email'] ?? null,
                'amount' => $amount > 0 ? $amount : (int) ($origin['amount_cents'] ?? 0),
                'currency' => (string) ($origin['currency'] ?? 'ron'),
                'designation' => $origin['designation'] ?? null,
                'cover' => (int) ($origin['cover_fees'] ?? 0),
                'pi' => $paymentIntentId !== '' ? $paymentIntentId : null,
                'sub' => $subscriptionId,
                'customer' => $origin['stripe_customer_id'] ?? null,
                'invoice' => $invoiceId,
                'receipt' => trim((string) ($invoice['hosted_invoice_url'] ?? '')) ?: null,
            ]);
            $newId = (int) $db->lastInsertId();
            return $newId > 0 ? $newId : null;
        } catch (Throwable) {
            // Indexul unic pe factură oprește dublurile când Stripe reia livrarea.
            return null;
        }
    }

    /**
     * O factură lunară neplătită (`invoice.payment_failed`): prima factură a abonamentului rămâne pe rândul original (care nu
     * fusese încă plătit: „failed”), iar una din lunile următoare devine un rând nou „failed”, ca istoricul să arate luna
     * neîncasată. Stripe reîncearcă plata; o plată reușită a aceleiași facturi trece rândul în „paid” (mai sus).
     *
     * @param array<string, mixed> $origin
     * @param array<string, mixed> $invoice
     */
    private static function recordFailedInvoice(PDO $db, array $origin, array $invoice, string $subscriptionId, string $invoiceId): ?int
    {
        try {
            if (trim((string) ($origin['stripe_invoice_id'] ?? '')) === '') {
                $db->prepare("UPDATE donations SET stripe_invoice_id = :inv,
                        status = CASE WHEN status IN ('paid', 'refunded') THEN status ELSE 'failed' END WHERE id = :id")
                    ->execute(['inv' => $invoiceId, 'id' => (int) $origin['id']]);
                return (int) $origin['id'];
            }
            $db->prepare(
                'INSERT INTO donations
                    (reference, user_id, donor_name, donor_email, amount_cents, fee_cents, currency,
                     frequency, designation, cover_fees, newsletter_optin, consent, status,
                     stripe_payment_intent_id, stripe_subscription_id, stripe_customer_id, stripe_invoice_id)
                 VALUES
                    (:reference, :user_id, :name, :email, :amount, 0, :currency,
                     "monthly", :designation, :cover, 0, 1, "failed", :pi, :sub, :customer, :invoice)'
            )->execute([
                'reference' => 'DON-' . strtoupper(bin2hex(random_bytes(4))),
                'user_id' => $origin['user_id'] ?? null,
                'name' => $origin['donor_name'] ?? null,
                'email' => $origin['donor_email'] ?? null,
                'amount' => (int) ($invoice['amount_due'] ?? $invoice['total'] ?? $origin['amount_cents'] ?? 0) ?: (int) ($origin['amount_cents'] ?? 0),
                'currency' => (string) ($origin['currency'] ?? 'ron'),
                'designation' => $origin['designation'] ?? null,
                'cover' => (int) ($origin['cover_fees'] ?? 0),
                'pi' => trim((string) ($invoice['payment_intent'] ?? $invoice['payments']['data'][0]['payment']['payment_intent'] ?? '')) ?: null,
                'sub' => $subscriptionId,
                'customer' => $origin['stripe_customer_id'] ?? null,
                'invoice' => $invoiceId,
            ]);
            $id = (int) $db->lastInsertId();
            return $id > 0 ? $id : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Rambursarea unei plăți (`charge.refunded`): pe rândul donației plătite cu acel PaymentIntent, suma rambursată de până acum
     * (`amount_refunded` al încasării; idempotent, aceeași valoare la o retrimitere) și momentul primei rambursări; rambursată
     * întreagă, starea devine „refunded”. Întoarce rândul schimbat sau null.
     *
     * @param array<string, mixed> $charge
     * @return array<string, mixed>|null
     */
    public static function recordRefund(PDO $db, array $charge): ?array
    {
        $paymentIntentId = trim((string) ($charge['payment_intent'] ?? ''));
        $refunded = max(0, (int) ($charge['amount_refunded'] ?? 0));
        if ($paymentIntentId === '' || $refunded <= 0) {
            return null;
        }
        try {
            $stmt = $db->prepare("SELECT * FROM donations WHERE stripe_payment_intent_id = :pi AND status IN ('paid', 'refunded') ORDER BY id ASC LIMIT 1");
            $stmt->execute(['pi' => $paymentIntentId]);
            $donation = $stmt->fetch();
            if (!is_array($donation)) {
                return null;
            }
            $amount = (int) ($donation['amount_cents'] ?? 0);
            $full = !empty($charge['refunded']) || ($amount > 0 && $refunded >= $amount);
            $update = $db->prepare('UPDATE donations SET refunded_cents = :refunded, refunded_at = COALESCE(refunded_at, NOW()), status = :status
                WHERE id = :id AND (refunded_cents <> :refunded2 OR status <> :status2)');
            $status = $full ? 'refunded' : 'paid';
            $update->execute(['refunded' => $refunded, 'refunded2' => $refunded, 'status' => $status, 'status2' => $status, 'id' => (int) $donation['id']]);
            if ($update->rowCount() === 0) {
                return null;
            }
            $donation['refunded_cents'] = $refunded;
            $donation['status'] = $status;
            return $donation;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Un abonament din nou activ în Stripe (`customer.subscription.updated` cu `active` sau `past_due`, de exemplu după o plată
     * reușită la reîncercare): nu mai e „oprit”, ca donatorul să-și vadă donația lunară și s-o poată opri cât Stripe încă încasează.
     */
    public static function markSubscriptionActive(PDO $db, string $subscriptionId): void
    {
        $subscriptionId = trim($subscriptionId);
        if ($subscriptionId === '') {
            return;
        }
        try {
            $db->prepare('UPDATE donations SET cancelled_at = NULL WHERE stripe_subscription_id = :sub AND cancelled_at IS NOT NULL')
                ->execute(['sub' => $subscriptionId]);
        } catch (Throwable) {
        }
    }

    /** Marchează un abonament ca oprit, indiferent de unde a fost anulat. */
    public static function markSubscriptionCancelled(PDO $db, string $subscriptionId): void
    {
        $subscriptionId = trim($subscriptionId);
        if ($subscriptionId === '') {
            return;
        }

        try {
            $db->prepare(
                'UPDATE donations SET cancelled_at = COALESCE(cancelled_at, NOW())
                 WHERE stripe_subscription_id = :sub'
            )->execute(['sub' => $subscriptionId]);
        } catch (Throwable) {
        }
    }

    /**
     * Donațiile unei persoane: cele legate de cont, plus cele făcute cu aceeași
     * adresă de email înainte de autentificare.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function donationsFor(?PDO $db, int $userId, string $email): array
    {
        if (!$db instanceof PDO) {
            return [];
        }

        $email = strtolower(trim($email));
        if ($userId <= 0 && $email === '') {
            return [];
        }

        try {
            $stmt = $db->prepare(
                'SELECT * FROM donations
                 WHERE (user_id = :uid AND :uid > 0)
                    OR (:email <> "" AND user_id IS NULL AND LOWER(donor_email) = :email)
                 ORDER BY id DESC
                 LIMIT 200'
            );
            $stmt->execute(['uid' => $userId, 'email' => $email]);
            $rows = $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * Donația lunară încă activă a persoanei, dacă există. O considerăm activă
     * cât timp are abonament, a fost plătită măcar o dată și nu a fost oprită.
     *
     * @param array<int, array<string, mixed>> $donations
     * @return array<string, mixed>|null
     */
    public static function activeSubscription(array $donations): ?array
    {
        return self::activeSubscriptions($donations)[0] ?? null;
    }

    /**
     * Toate donațiile lunare încă active, una pe abonament (rândul cel mai nou al fiecăruia, în ordinea listei: cea mai nouă
     * întâi). O persoană poate avea mai multe; fiecare se oprește separat.
     *
     * @param array<int, array<string, mixed>> $donations
     * @return list<array<string, mixed>>
     */
    public static function activeSubscriptions(array $donations): array
    {
        $active = [];
        foreach ($donations as $donation) {
            $subscriptionId = trim((string) ($donation['stripe_subscription_id'] ?? ''));
            $cancelled = trim((string) ($donation['cancelled_at'] ?? ''));
            if ($subscriptionId !== '' && !isset($active[$subscriptionId]) && $cancelled === ''
                && in_array((string) ($donation['status'] ?? ''), ['paid', 'failed', 'refunded'], true)) {
                $active[$subscriptionId] = $donation;
            }
        }
        // Un abonament fără nicio lună plătită (doar încercări eșuate) nu e o donație activă; o lună rambursată a fost plătită, iar
        // abonamentul încasează mai departe (se poate opri).
        $platite = [];
        foreach ($donations as $donation) {
            if (in_array((string) ($donation['status'] ?? ''), ['paid', 'refunded'], true)) {
                $platite[trim((string) ($donation['stripe_subscription_id'] ?? ''))] = true;
            }
        }
        return array_values(array_filter($active, static fn (array $d): bool => isset($platite[trim((string) ($d['stripe_subscription_id'] ?? ''))])));
    }

    /**
     * Config public al donațiilor (folosit de popup + validare server).
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public static function config(array $settings): array
    {
        $amounts = self::parseAmounts((string) ($settings['donations_amounts'] ?? '1000,500,250,100,50,25'));
        if ($amounts === []) {
            $amounts = [1000, 500, 250, 100, 50, 25];
        }
        $designations = self::parseLines((string) ($settings['donations_designations'] ?? "Unde e nevoie mai mult\nCercetare\nEducația pacienților\nEvenimente și conferințe"));
        if ($designations === []) {
            $designations = ['Unde e nevoie mai mult'];
        }

        return [
            'enabled' => (string) ($settings['donations_enabled'] ?? '0') === '1',
            'demo' => (string) ($settings['donations_demo_enabled'] ?? '0') === '1',
            'currency' => strtolower(trim((string) ($settings['donations_currency'] ?? $settings['stripe_currency'] ?? 'ron'))) ?: 'ron',
            'amounts' => $amounts,
            'default_amount' => (int) ($amounts[3] ?? $amounts[0] ?? 100),
            'min_amount' => max(1, (int) ($settings['donations_min'] ?? 5)),
            'designations' => $designations,
            'monthly_enabled' => (string) ($settings['donations_monthly_enabled'] ?? '1') === '1',
            'cover_fees_enabled' => (string) ($settings['donations_cover_fees_enabled'] ?? '1') === '1',
            'cover_fees_percent' => max(0.0, min(15.0, (float) ($settings['donations_cover_fees_percent'] ?? 2.9))),
            'cover_fees_fixed' => max(0.0, (float) ($settings['donations_cover_fees_fixed'] ?? 1.0)),
            'campaign_title' => (string) ($settings['donations_campaign_title'] ?? 'Susține misiunea noastră!'),
            'campaign_text' => (string) ($settings['donations_campaign_text'] ?? ''),
            'campaign_image' => (string) ($settings['donations_campaign_image'] ?? ''),
            'thanks_text' => (string) ($settings['donations_thanks_text'] ?? 'Îți mulțumim din suflet pentru sprijin! Contribuția ta contează enorm.'),
        ];
    }

    /**
     * Comisionul acoperit de donator (dacă bifează „acoperă comisioanele"), în bani (cents).
     */
    public static function feeCents(int $amountCents, array $config): int
    {
        $percent = (float) ($config['cover_fees_percent'] ?? 0);
        $fixed = (float) ($config['cover_fees_fixed'] ?? 0);
        $fee = (int) round($amountCents * ($percent / 100) + $fixed * 100);
        return max(0, $fee);
    }

    /** @return array<int,int> sume valide (lei întregi) */
    private static function parseAmounts(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $tok) {
            $n = (int) preg_replace('/[^0-9]/', '', $tok);
            if ($n > 0) {
                $out[] = $n;
            }
        }
        return array_values(array_unique($out));
    }

    /** @return array<int,string> */
    private static function parseLines(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }
}
