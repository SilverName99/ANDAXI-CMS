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
 * (tabelul îl face migrarea `donatii/0062`, niciun DDL pe cerere) și cu o singură regulă în plus în `donationsFor`:
 * donațiile făcute fără cont cu adresa unui cont apar în contul lui doar după ce adresa e dovedită (regula comenzilor
 * fără cont a nucleului, G-M4, OD-47; App\Support\ConfirmareEmail).
 */
final class DonationService
{
    /**
     * Înregistrează plata unei luni dintr-o donație recurentă. Prima lună e deja
     * salvată la crearea abonamentului; de la a doua încolo, Stripe trimite
     * facturi noi, iar fiecare devine un rând propriu în istoric.
     *
     * @return int|null id-ul rândului afectat, sau null dacă nu e nimic nou
     */
    public static function recordRecurringCharge(PDO $db, array $invoice): ?int
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

        // Prima factură aparține rândului deja existent: îl marcăm, nu dublăm.
        if (trim((string) ($origin['stripe_invoice_id'] ?? '')) === '') {
            try {
                $db->prepare(
                    "UPDATE donations
                     SET stripe_invoice_id = :inv, status = 'paid',
                         paid_at = COALESCE(paid_at, NOW()),
                         receipt_url = COALESCE(:receipt, receipt_url)
                     WHERE id = :id"
                )->execute([
                    'inv' => $invoiceId,
                    'receipt' => trim((string) ($invoice['hosted_invoice_url'] ?? '')) ?: null,
                    'id' => (int) $origin['id'],
                ]);
                return (int) $origin['id'];
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
                    OR (:email <> "" AND LOWER(donor_email) = :email)
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
        foreach ($donations as $donation) {
            $subscriptionId = trim((string) ($donation['stripe_subscription_id'] ?? ''));
            $cancelled = trim((string) ($donation['cancelled_at'] ?? ''));
            if ($subscriptionId !== '' && $cancelled === '' && (string) ($donation['status'] ?? '') === 'paid') {
                return $donation;
            }
        }

        return null;
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
