<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AdminCsrf;
use App\Support\BrandedEmail;
use App\Support\ConfirmareEmail;
use App\Support\CustomerAuth;
use App\Support\DonationService;
use App\Support\Flash;
use App\Support\OrderMailer;
use App\Support\Paths;
use App\Support\Plugins;
use App\Support\Settings;
use App\Support\StripeEvenimente;
use App\Support\StripeGateway;
use App\Support\View;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Pluginul `donatii` (din vertij 32db865 … 8412906 „fundație, popup, mod Exemplu” și dc52498 … ca1120c „donații lunare,
 * chitanțe, versiunea API Stripe”, la 9ab7999; docs/PLUGINURI.md, „Donații”): sertarul „SUSȚINE” de pe paginile publice (o
 * dată sau lunar, cu comisionul acoperit la cerere și modul „Exemplu”, fără nicio plată), plata prin Stripe (PaymentIntent
 * pentru o donație unică; Customer, Product refolosit și Subscription pentru una lunară), evenimentele Stripe (plătită,
 * eșuată, fiecare lună plătită, abonamentul oprit), chitanțele, emailul de mulțumire, tab-ul „Donații” din contul
 * donatorului (cu oprirea donației lunare) și Admin → Donații, Setări donații.
 *
 * Copiat cum e (aceleași texte, reguli, adrese și câmpuri, aceleași cereri spre Stripe), cu schimbările codului comun:
 *  - schema o face migrarea `donatii/0062` (fără `DonationService::ensureSchema` la fiecare cerere);
 *  - evenimentele vin prin dispecerul nucleului (`StripeEvenimente`, același `/webhook/stripe`, aceeași semnătură): un
 *    eveniment retrimis nu se procesează de două ori, emailurile pleacă doar cu pluginul vizibil, banii se notează mereu;
 *  - fiecare POST din admin cere jetonul formularului (AdminCsrf), la fel oprirea donației lunare din cont;
 *  - `POST /api/donations/intent` primește doar JSON (sertarul trimite JSON; un formular HTML de pe alt site nu mai poate
 *    porni donații în numele nimănui) și nu pornește nimic cu pluginul „se oprește” (`Plugins::permiteInceput`);
 *  - cererile de creare spre Stripe au `Idempotency-Key` (referința donației), deci o cerere repetată nu face două plăți;
 *  - donațiile făcute fără cont cu adresa unui cont apar în cont (și se pot opri de acolo) doar după ce adresa e dovedită
 *    (regula comenzilor fără cont, G-M4, OD-47); cele făcute din cont, mereu.
 */
final class DonatiiController
{
    use Comun;

    // ------------------------------------------------------------------ site: plata

    /** `POST /api/donations/intent`: donația nouă (rândul „pending”) și plata ei în Stripe; răspunde cu cheia de confirmare. */
    public function intent(): void
    {
        $db = $this->db();
        if (!$db instanceof PDO) {
            $this->jsonResponse(['ok' => false, 'message' => 'Serviciu indisponibil.'], 500);
            return;
        }
        $settings = Settings::all($db);
        $config = DonationService::config($settings);

        if (!$config['enabled'] || !Plugins::permiteInceput('donatii')) {
            $this->jsonResponse(['ok' => false, 'message' => 'Donațiile nu sunt active momentan.'], 422);
            return;
        }
        $secretKey = trim((string) ($settings['stripe_secret_key'] ?? ''));
        $publishableKey = trim((string) ($settings['stripe_publishable_key'] ?? ''));
        if ($secretKey === '' || $publishableKey === '') {
            $this->jsonResponse(['ok' => false, 'message' => 'Plățile nu sunt configurate.'], 422);
            return;
        }

        // Doar JSON (sertarul trimite JSON): un formular de pe alt site nu poate trimite JSON fără acordul acestui site.
        if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
            $this->jsonResponse(['ok' => false, 'message' => 'Cerere invalidă.'], 415);
            return;
        }
        $payload = $this->requestPayload();
        $amountLei = (int) round((float) ($payload['amount'] ?? 0));
        $frequency = (($payload['frequency'] ?? 'once') === 'monthly' && $config['monthly_enabled']) ? 'monthly' : 'once';
        $designation = mb_substr(trim((string) ($payload['designation'] ?? '')), 0, 190);
        $coverFees = !empty($payload['cover_fees']) && $config['cover_fees_enabled'];
        $name = mb_substr(trim((string) ($payload['name'] ?? '')), 0, 190);
        $email = mb_substr(trim((string) ($payload['email'] ?? '')), 0, 190);
        $newsletter = !empty($payload['newsletter']);
        $consent = !empty($payload['consent']);

        if ($amountLei < (int) $config['min_amount']) {
            $this->jsonResponse(['ok' => false, 'message' => 'Suma minimă este ' . $config['min_amount'] . ' ' . strtoupper((string) $config['currency']) . '.'], 422);
            return;
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Introdu o adresă de email validă.'], 422);
            return;
        }
        if (!$consent) {
            $this->jsonResponse(['ok' => false, 'message' => 'Trebuie să accepți politica de confidențialitate.'], 422);
            return;
        }

        $currency = (string) $config['currency'];
        $baseCents = $amountLei * 100;
        $feeCents = $coverFees ? DonationService::feeCents($baseCents, $config) : 0;
        $totalCents = $baseCents + $feeCents;

        try {
            $reference = 'DON-' . strtoupper(bin2hex(random_bytes(4)));
            $donorUserId = CustomerAuth::id();
            $ins = $db->prepare('INSERT INTO donations
                (reference, user_id, donor_name, donor_email, amount_cents, fee_cents, currency, frequency, designation, cover_fees, newsletter_optin, consent, status)
                VALUES (:reference,:user_id,:name,:email,:amount,:fee,:currency,:frequency,:designation,:cover,:newsletter,1,"pending")');
            $ins->execute([
                'reference' => $reference,
                'user_id' => ($donorUserId !== null && $donorUserId > 0) ? $donorUserId : null,
                'name' => $name !== '' ? $name : null,
                'email' => $email,
                'amount' => $totalCents,
                'fee' => $feeCents,
                'currency' => $currency,
                'frequency' => $frequency,
                'designation' => $designation !== '' ? $designation : null,
                'cover' => $coverFees ? 1 : 0,
                'newsletter' => $newsletter ? 1 : 0,
            ]);
            $donationId = (int) $db->lastInsertId();
        } catch (Throwable) {
            $this->jsonResponse(['ok' => false, 'message' => 'Nu am putut înregistra donația.'], 500);
            return;
        }

        try {
            if ($frequency === 'monthly') {
                $customer = StripeGateway::createCustomer($secretKey, array_filter([
                    'email' => $email,
                    'name' => $name !== '' ? $name : null,
                ]), 'donatie-' . $reference . '-client');
                $customerId = (string) ($customer['id'] ?? '');
                $productId = $this->donationProductId($db, $settings, $secretKey);
                $sub = StripeGateway::createSubscription($secretKey, [
                    'customer' => $customerId,
                    'items[0][price_data][currency]' => $currency,
                    // Abonamentele cer un Product existent — spre deosebire de
                    // Checkout, nu acceptă datele produsului inline.
                    'items[0][price_data][product]' => $productId,
                    'items[0][price_data][recurring][interval]' => 'month',
                    'items[0][price_data][unit_amount]' => (string) $totalCents,
                    'payment_behavior' => 'default_incomplete',
                    'payment_settings[save_default_payment_method]' => 'on_subscription',
                    // Din versiunea 2025-03-31 (basil), factura nu mai are
                    // payment_intent; cheia de confirmare stă în confirmation_secret.
                    'expand[0]' => 'latest_invoice.confirmation_secret',
                    'expand[1]' => 'latest_invoice.payments',
                    'metadata[type]' => 'donation',
                    'metadata[donation_id]' => (string) $donationId,
                    'metadata[reference]' => $reference,
                ], 'donatie-' . $reference . '-abonament');
                $invoice = is_array($sub['latest_invoice'] ?? null) ? $sub['latest_invoice'] : [];
                $clientSecret = trim((string) ($invoice['confirmation_secret']['client_secret'] ?? ''));
                if ($clientSecret === '') {
                    // Conturi rămase pe o versiune de API mai veche.
                    $clientSecret = trim((string) ($invoice['payment_intent']['client_secret'] ?? ''));
                }

                $paymentIntentId = trim((string) (
                    $invoice['payments']['data'][0]['payment']['payment_intent']
                    ?? $invoice['payment_intent']['id']
                    ?? ''
                ));

                $db->prepare('UPDATE donations SET stripe_subscription_id = :sid, stripe_customer_id = :cid, stripe_payment_intent_id = :pi, stripe_invoice_id = :inv WHERE id = :id')
                   ->execute([
                       'sid' => (string) ($sub['id'] ?? ''),
                       'cid' => $customerId,
                       'pi' => $paymentIntentId !== '' ? $paymentIntentId : null,
                       'inv' => trim((string) ($invoice['id'] ?? '')) ?: null,
                       'id' => $donationId,
                   ]);
            } else {
                $intent = StripeGateway::createPaymentIntent($secretKey, array_filter([
                    'amount' => (string) $totalCents,
                    'currency' => $currency,
                    'automatic_payment_methods[enabled]' => 'true',
                    'description' => 'Donație unică - ' . $reference,
                    'receipt_email' => $email,
                    'metadata[type]' => 'donation',
                    'metadata[donation_id]' => (string) $donationId,
                    'metadata[reference]' => $reference,
                ]), 'donatie-' . $reference);
                $clientSecret = (string) ($intent['client_secret'] ?? '');
                $db->prepare('UPDATE donations SET stripe_payment_intent_id = :pi WHERE id = :id')
                   ->execute(['pi' => (string) ($intent['id'] ?? ''), 'id' => $donationId]);
            }
        } catch (Throwable $e) {
            try {
                $db->prepare('UPDATE donations SET status = "failed" WHERE id = :id')->execute(['id' => $donationId]);
            } catch (Throwable) {
            }
            if ($frequency === 'monthly') {
                // Produsul salvat poate fi fost șters din Stripe; îl uităm,
                // ca încercarea următoare să creeze unul nou.
                $this->forgetDonationProductId($db, $settings);
            }
            self::logAccountIssue('donatie_stripe', $e->getMessage());
            $this->jsonResponse(['ok' => false, 'message' => 'Eroare la inițierea plății: ' . $e->getMessage()], 502);
            return;
        }

        if ($clientSecret === '') {
            $this->jsonResponse(['ok' => false, 'message' => 'Nu am putut iniția plata.'], 502);
            return;
        }

        $this->jsonResponse([
            'ok' => true,
            'clientSecret' => $clientSecret,
            'publishableKey' => $publishableKey,
            'reference' => $reference,
            'frequency' => $frequency,
            'amountLabel' => number_format($totalCents / 100, 2, ',', '.') . ' ' . strtoupper($currency),
        ]);
    }

    /**
     * Id-ul produsului Stripe folosit pentru donațiile lunare. Se creează la
     * prima donație recurentă și se refolosește, ca să nu umplem catalogul
     * Stripe cu un produs per donator.
     */
    private function donationProductId(PDO $db, array $settings, string $secretKey): string
    {
        $stored = trim((string) ($settings['donations_stripe_product_id'] ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        $product = StripeGateway::createProduct($secretKey, [
            'name' => 'Donație lunară',
            'description' => 'Donație recurentă către ' . BrandedEmail::brandName($settings),
        ]);
        $productId = trim((string) ($product['id'] ?? ''));
        if ($productId === '') {
            throw new RuntimeException('Stripe nu a returnat un produs valid pentru donația lunară.');
        }

        try {
            Settings::save($db, ['donations_stripe_product_id' => $productId]);
        } catch (Throwable) {
            // Dacă salvarea eșuează, următoarea donație creează alt produs —
            // neplăcut, dar nu blochează plata în curs.
        }

        return $productId;
    }

    private function forgetDonationProductId(PDO $db, array $settings): void
    {
        if (trim((string) ($settings['donations_stripe_product_id'] ?? '')) === '') {
            return;
        }

        try {
            Settings::save($db, ['donations_stripe_product_id' => '']);
        } catch (Throwable) {
        }
    }

    // ------------------------------------------------------------------ site: contul

    /** `POST /contul-meu/donatii/opreste`: oprirea donației lunare, din contul propriu. */
    public function opreste(): void
    {
        $db = $this->db();
        $userId = CustomerAuth::id();
        if (!$db instanceof PDO || $userId === null || $userId <= 0) {
            Flash::set('error', 'Autentifică-te pentru a opri donația.');
            header('Location: /login');
            return;
        }
        if (!AdminCsrf::valid()) {
            Flash::set('error', 'Formularul a expirat. Încearcă din nou.');
            header('Location: /contul-meu?section=donations');
            return;
        }

        $user = CustomerAuth::user($db);
        $donations = self::donatiileContului($db, is_array($user) ? $user : ['id' => $userId]);
        $active = DonationService::activeSubscription($donations);

        if (!is_array($active)) {
            Flash::set('error', 'Nu ai nicio donație lunară activă.');
            header('Location: /contul-meu?section=donations');
            return;
        }

        // Id-ul vine din rândurile proprii ale utilizatorului, nu din formular,
        // ca nimeni să nu poată opri abonamentul altcuiva.
        $subscriptionId = trim((string) ($active['stripe_subscription_id'] ?? ''));
        $settings = Settings::all($db);
        $secretKey = trim((string) ($settings['stripe_secret_key'] ?? ''));

        if ($subscriptionId === '' || $secretKey === '') {
            Flash::set('error', 'Donația nu a putut fi oprită. Te rugăm să ne contactezi.');
            header('Location: /contul-meu?section=donations');
            return;
        }

        try {
            StripeGateway::cancelSubscription($secretKey, $subscriptionId);
        } catch (Throwable $exception) {
            self::logAccountIssue('anulare_donatie', $exception->getMessage());
            Flash::set('error', 'Nu am putut opri donația acum. Încearcă din nou sau scrie-ne.');
            header('Location: /contul-meu?section=donations');
            return;
        }

        DonationService::markSubscriptionCancelled($db, $subscriptionId);
        Flash::set('success', 'Donația lunară a fost oprită. Nu îți vom mai retrage nicio sumă.');
        header('Location: /contul-meu?section=donations');
    }

    /**
     * Donațiile unui cont: cele făcute din cont și, doar cu adresa dovedită (`users.email_confirmed_at`), cele făcute fără
     * cont cu aceeași adresă (regula comenzilor fără cont, G-M4).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function donatiileContului(PDO $db, array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);
        $email = ConfirmareEmail::confirmat($db, $userId) ? (string) ($user['email'] ?? '') : '';
        return DonationService::donationsFor($db, $userId, $email);
    }

    /** Câte donații fără cont cu adresa contului așteaptă dovada adresei (pentru rândul din tab). */
    private static function donatiiAscunse(PDO $db, array $user): int
    {
        $userId = (int) ($user['id'] ?? 0);
        $email = strtolower(trim((string) ($user['email'] ?? '')));
        if ($userId <= 0 || $email === '' || ConfirmareEmail::confirmat($db, $userId)) {
            return 0;
        }
        try {
            $stmt = $db->prepare('SELECT COUNT(*) FROM donations WHERE user_id IS NULL AND LOWER(donor_email) = :email');
            $stmt->execute(['email' => $email]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Agățarea `cont.taburi`: tab-ul „Donații”, doar pentru cine a donat măcar o dată (ca în vertij), cu insigna „lunar”
     * cât are o donație lunară activă.
     *
     * @return list<array<string, mixed>>
     */
    public static function tabCont(PDO $db, array $user): array
    {
        $list = self::donatiileContului($db, $user);
        $ascunse = self::donatiiAscunse($db, $user);
        if ($list === [] && $ascunse === 0) {
            return [];
        }
        $active = DonationService::activeSubscription($list);
        return [[
            'cheie' => 'donations',
            'eticheta' => 'Donații',
            'icon' => 'donations',
            'insigna' => $active !== null ? 'lunar' : '',
            'render' => static function (PDO $db, array $user) use ($list, $active, $ascunse): string {
                $total = 0;
                foreach ($list as $donation) {
                    if ((string) ($donation['status'] ?? '') === 'paid') {
                        $total += (int) ($donation['amount_cents'] ?? 0);
                    }
                }
                return View::fragment('site/donatii/tab-cont', [
                    'donationList' => $list,
                    'donationActive' => $active,
                    'donationTotalCents' => $total,
                    'donatiiAscunse' => $ascunse,
                ]);
            },
        ]];
    }

    /**
     * Agățarea `layout.final_body`: sertarul „SUSȚINE” pe paginile publice, cu donațiile pornite și cheia Stripe, sau în modul
     * „Exemplu” (pentru prezentare, fără nicio plată). Cu pluginul „se oprește” nu mai apare (nu începe nimic nou).
     */
    public static function sertar(): string
    {
        if (!Plugins::permiteInceput('donatii')) {
            return '';
        }
        $db = \App\Support\Database::connection((array) ((require Paths::radacina() . '/config/app.php')['db'] ?? []));
        if (!$db instanceof PDO) {
            return '';
        }
        $designSettings = Settings::all($db);
        $donationConfig = DonationService::config($designSettings);
        $donationPk = trim((string) ($designSettings['stripe_publishable_key'] ?? ''));
        $donationDemo = !empty($donationConfig['demo']);
        // Modul demo afișează sertarul singur (pentru prezentare), chiar dacă donațiile
        // nu sunt încă activate. În mod real e nevoie de donații active + cheie Stripe.
        if (!((!empty($donationConfig['enabled']) && $donationPk !== '') || $donationDemo)) {
            return '';
        }
        $donationPublicConfig = [
            'currency' => $donationConfig['currency'],
            'amounts' => array_values($donationConfig['amounts']),
            'defaultAmount' => $donationConfig['default_amount'],
            'minAmount' => $donationConfig['min_amount'],
            'designations' => array_values($donationConfig['designations']),
            'monthlyEnabled' => (bool) $donationConfig['monthly_enabled'],
            'coverFeesEnabled' => (bool) $donationConfig['cover_fees_enabled'],
            'coverFeesPercent' => $donationConfig['cover_fees_percent'],
            'coverFeesFixed' => $donationConfig['cover_fees_fixed'],
            'publishableKey' => $donationPk,
            'thanksText' => $donationConfig['thanks_text'],
            // Modul demo: rulează tot fluxul fără Stripe. Activ dacă e bifat SAU dacă nu există cheie.
            'demo' => $donationDemo || $donationPk === '',
        ];
        $donationJsVersion = @filemtime(Paths::public('assets/js/donation-widget.js')) ?: time();
        return View::fragment('site/donation-widget', ['donationConfig' => $donationConfig])
            . "\n" . '        <script>window.donationConfig = ' . (string) json_encode($donationPublicConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ';</script>'
            . ($donationPk !== '' ? "\n" . '        <script src="https://js.stripe.com/v3/"></script>' : '')
            . "\n" . '        <script src="/assets/js/donation-widget.js?v=' . $donationJsVersion . '" defer></script>' . "\n";
    }

    // ------------------------------------------------------------------ webhook-ul Stripe

    /**
     * Handler-ele Stripe ale donațiilor, pe dispecerul nucleului (ramurile webhook-ului din vertij, aceleași): plata unei
     * donații (unice sau prima lună), fiecare lună plătită a unei donații lunare (`invoice.paid` și, pentru endpoint-urile
     * vechi, `invoice.payment_succeeded`: același rând, indexul unic pe factură oprește dublura), abonamentul oprit.
     */
    public static function handlereStripe(): void
    {
        $plata = static fn (bool $platita): callable => static function (PDO $db, array $obiect, array $eveniment, bool $vizibil) use ($platita): ?string {
            return self::applyDonationResult($db, $obiect, $platita, $vizibil);
        };
        StripeEvenimente::handler('donatii', 'payment_intent.succeeded', $plata(true));
        StripeEvenimente::handler('donatii', 'payment_intent.payment_failed', $plata(false));
        $luna = static function (PDO $db, array $obiect, array $eveniment, bool $vizibil): ?string {
            // Lunile 2+ ale unei donații recurente: fiecare factură plătită
            // devine un rând nou în istoric. Confirmarea abonamentului vine tot
            // pe aici, nu pe payment_intent, deci emailul se trimite de aici.
            $recurringId = DonationService::recordRecurringCharge($db, $obiect);
            if ($recurringId === null) {
                return 'nimic_nou';
            }
            try {
                $row = $db->prepare('SELECT * FROM donations WHERE id = :id LIMIT 1');
                $row->execute(['id' => $recurringId]);
                $recurringDonation = $row->fetch();
            } catch (Throwable) {
                $recurringDonation = null;
            }
            if (is_array($recurringDonation) && $vizibil) {
                self::sendDonationThankYouEmail($db, $recurringDonation);
            }
            return 'luna_inregistrata';
        };
        StripeEvenimente::handler('donatii', 'invoice.paid', $luna);
        StripeEvenimente::handler('donatii', 'invoice.payment_succeeded', $luna);
        StripeEvenimente::handler('donatii', 'customer.subscription.deleted', static function (PDO $db, array $obiect): ?string {
            DonationService::markSubscriptionCancelled($db, (string) ($obiect['id'] ?? ''));
            return 'abonament_oprit';
        });
    }

    /**
     * Marchează o donație ca plătită/eșuată în urma unui eveniment Stripe.
     */
    private static function applyDonationResult(PDO $db, array $paymentIntent, bool $paid, bool $vizibil): ?string
    {
        $donationId = (int) ($paymentIntent['metadata']['donation_id'] ?? 0);
        $paymentIntentId = trim((string) ($paymentIntent['id'] ?? ''));
        if ($donationId <= 0 && $paymentIntentId === '') {
            return 'fara_donatie';
        }

        $where = $donationId > 0 ? 'id = :id' : 'stripe_payment_intent_id = :pi';
        $params = $donationId > 0 ? ['id' => $donationId] : ['pi' => $paymentIntentId];

        try {
            if ($paid) {
                $stmt = $db->prepare("UPDATE donations SET status = 'paid', paid_at = NOW() WHERE $where AND status <> 'paid'");
            } else {
                $stmt = $db->prepare("UPDATE donations SET status = CASE WHEN status = 'paid' THEN status ELSE 'failed' END WHERE $where");
            }
            $stmt->execute($params);
            // Doar tranziția reală în „paid" trimite emailul; reluările
            // webhook-ului nu modifică niciun rând, deci nu dublează mesajul.
            $changed = $stmt->rowCount() > 0;
        } catch (Throwable) {
            return 'eroare';
        }

        if (!$paid || !$changed) {
            return $paid ? 'deja_platita' : 'esuata';
        }

        try {
            $select = $db->prepare("SELECT * FROM donations WHERE $where LIMIT 1");
            $select->execute($params);
            $donation = $select->fetch();
        } catch (Throwable) {
            return 'platita';
        }

        if (!is_array($donation)) {
            return 'platita';
        }

        if (trim((string) ($donation['receipt_url'] ?? '')) === '') {
            $receiptUrl = self::stripeReceiptUrl(
                trim((string) (Settings::all($db)['stripe_secret_key'] ?? '')),
                (string) ($donation['stripe_payment_intent_id'] ?? '')
            );
            if ($receiptUrl !== '') {
                try {
                    $db->prepare('UPDATE donations SET receipt_url = :url WHERE id = :id')
                       ->execute(['url' => $receiptUrl, 'id' => (int) ($donation['id'] ?? 0)]);
                    $donation['receipt_url'] = $receiptUrl;
                } catch (Throwable) {
                }
            }
        }

        if ($vizibil) {
            self::sendDonationThankYouEmail($db, $donation);
        }
        return 'platita';
    }

    /**
     * Link-ul chitanței Stripe pentru o plată. Chitanța stă pe încasare
     * (charge), nu pe PaymentIntent, deci o cerem expandată.
     */
    public static function stripeReceiptUrl(string $secretKey, string $paymentIntentId): string
    {
        $paymentIntentId = trim($paymentIntentId);
        if ($secretKey === '' || $paymentIntentId === '') {
            return '';
        }

        try {
            $intent = StripeGateway::retrievePaymentIntent($secretKey, $paymentIntentId, [
                'expand[0]' => 'latest_charge',
            ]);
        } catch (Throwable) {
            return '';
        }

        $charge = $intent['latest_charge'] ?? null;
        if (is_array($charge)) {
            return trim((string) ($charge['receipt_url'] ?? ''));
        }

        return '';
    }

    /** Emailul de mulțumire către donator, în aceleași culori ca restul. */
    private static function sendDonationThankYouEmail(PDO $db, array $donation): void
    {
        $email = trim((string) ($donation['donor_email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $settings = self::emailBrandSettings(Settings::all($db));
        $currency = strtoupper(trim((string) ($donation['currency'] ?? 'ron'))) ?: 'RON';
        $amount = number_format(((int) ($donation['amount_cents'] ?? 0)) / 100, 2, ',', '.');
        $isMonthly = (string) ($donation['frequency'] ?? 'once') === 'monthly';
        $name = trim((string) ($donation['donor_name'] ?? ''));
        $designation = trim((string) ($donation['designation'] ?? ''));

        $facts = [
            ['label' => 'Sumă', 'value' => $amount . ' ' . $currency],
            ['label' => 'Tip', 'value' => $isMonthly ? 'Donație lunară' : 'Donație unică'],
        ];
        if ($designation !== '') {
            $facts[] = ['label' => 'Destinație', 'value' => $designation];
        }
        $facts[] = ['label' => 'Referință', 'value' => (string) ($donation['reference'] ?? '')];

        $parts = [
            'title' => 'Îți mulțumim pentru donație',
            'greeting' => $name !== '' ? 'Mulțumim, ' . $name . '!' : 'Mulțumim!',
            'intro' => $isMonthly
                ? 'Donația ta lunară a fost confirmată. Sprijinul constant ne ajută cel mai mult, pentru că ne putem planifica proiectele dinainte.'
                : 'Donația ta a fost confirmată. Fiecare contribuție ne ajută să ducem mai departe activitatea societății.',
            'facts' => $facts,
            'recipient' => $email,
        ];

        if ($isMonthly) {
            $parts['button'] = [
                'label' => 'Vezi donațiile tale',
                'url' => \App\Support\AppUrl::base() . '/contul-meu?section=donations',
            ];
            $parts['note'] = 'Poți opri oricând donația lunară din contul tău, fără să ne scrii. '
                . 'Dacă nu ai cont, răspunde la acest email și o oprim noi.';
        }

        try {
            OrderMailer::sendCustom(
                $email,
                'Îți mulțumim pentru donație',
                BrandedEmail::render($settings, $parts),
                $settings,
                $db,
                ['email_type' => 'donation_thanks', 'source' => 'donation']
            );
        } catch (Throwable $exception) {
            self::logAccountIssue('email_donatie', $exception->getMessage());
        }
    }

    // ------------------------------------------------------------------ admin

    /** `GET /admin/donations`: lista donațiilor, cu filtrul de stare, căutarea și totalurile. */
    public function lista(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin');
            return;
        }

        $status = trim((string) ($_GET['status'] ?? ''));
        if (!in_array($status, ['paid', 'pending', 'failed'], true)) {
            $status = '';
        }
        $search = trim((string) ($_GET['q'] ?? ''));

        $where = [];
        $params = [];
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($search !== '') {
            $where[] = '(donor_email LIKE :q OR donor_name LIKE :q OR reference LIKE :q)';
            $params['q'] = '%' . $search . '%';
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $donations = [];
        try {
            $stmt = $db->prepare('SELECT * FROM donations' . $whereSql . ' ORDER BY id DESC LIMIT 500');
            $stmt->execute($params);
            $donations = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            Flash::set('error', 'Donațiile nu au putut fi încărcate.');
        }

        // Totalurile se calculează pe tot tabelul, nu doar pe filtrul curent.
        $totals = ['all_cents' => 0, 'month_cents' => 0, 'count' => 0, 'recurring' => 0];
        try {
            $row = $db->query(
                'SELECT
                    COALESCE(SUM(CASE WHEN status = "paid" THEN amount_cents ELSE 0 END), 0) AS all_cents,
                    COALESCE(SUM(CASE WHEN status = "paid"
                        AND paid_at >= DATE_FORMAT(NOW(), "%Y-%m-01") THEN amount_cents ELSE 0 END), 0) AS month_cents,
                    COALESCE(SUM(status = "paid"), 0) AS cnt,
                    COALESCE(COUNT(DISTINCT CASE WHEN stripe_subscription_id IS NOT NULL
                        AND cancelled_at IS NULL THEN stripe_subscription_id END), 0) AS recurring
                 FROM donations'
            )->fetch();
            if (is_array($row)) {
                $totals = [
                    'all_cents' => (int) ($row['all_cents'] ?? 0),
                    'month_cents' => (int) ($row['month_cents'] ?? 0),
                    'count' => (int) ($row['cnt'] ?? 0),
                    'recurring' => (int) ($row['recurring'] ?? 0),
                ];
            }
        } catch (Throwable) {
        }

        View::render('admin/donations', [
            'title' => 'Donații',
            'donations' => is_array($donations) ? $donations : [],
            'totals' => $totals,
            'status' => $status,
            'search' => $search,
        ], 'admin/layout');
    }

    /** `GET /admin/settings/donations`. */
    public function setari(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        $settings = Settings::all($db);

        $stats = ['total' => 0, 'paid' => 0, 'sum' => 0];
        if ($db instanceof PDO) {
            try {
                $stats['total'] = (int) $db->query('SELECT COUNT(*) FROM donations')->fetchColumn();
                $stats['paid'] = (int) $db->query("SELECT COUNT(*) FROM donations WHERE status = 'paid'")->fetchColumn();
                $stats['sum'] = (int) $db->query("SELECT COALESCE(SUM(amount_cents),0) FROM donations WHERE status = 'paid'")->fetchColumn();
            } catch (Throwable) {
            }
        }

        View::render('admin/settings-donations', [
            'title' => 'Setări donații',
            'settings' => $settings,
            'stripeConfigured' => trim((string) ($settings['stripe_secret_key'] ?? '')) !== ''
                && trim((string) ($settings['stripe_publishable_key'] ?? '')) !== '',
            'stats' => $stats,
        ], 'admin/layout');
    }

    /** `POST /admin/settings/donations`. */
    public function setariSalveaza(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!AdminCsrf::valid()) {
            Flash::set('error', 'Formularul a expirat. Reîncarcă pagina și încearcă din nou.');
            header('Location: /admin/settings/donations');
            return;
        }
        $db = $this->db();

        $percent = max(0.0, min(15.0, (float) str_replace(',', '.', (string) ($_POST['donations_cover_fees_percent'] ?? '2.9'))));
        $fixed = max(0.0, (float) str_replace(',', '.', (string) ($_POST['donations_cover_fees_fixed'] ?? '1.0')));

        Settings::save($db, [
            'donations_enabled' => isset($_POST['donations_enabled']) ? '1' : '0',
            'donations_demo_enabled' => isset($_POST['donations_demo_enabled']) ? '1' : '0',
            'donations_currency' => strtolower(trim((string) ($_POST['donations_currency'] ?? 'ron'))) ?: 'ron',
            'donations_amounts' => trim((string) ($_POST['donations_amounts'] ?? '')),
            'donations_min' => (string) max(1, (int) ($_POST['donations_min'] ?? 5)),
            'donations_designations' => trim((string) ($_POST['donations_designations'] ?? '')),
            'donations_monthly_enabled' => isset($_POST['donations_monthly_enabled']) ? '1' : '0',
            'donations_cover_fees_enabled' => isset($_POST['donations_cover_fees_enabled']) ? '1' : '0',
            'donations_cover_fees_percent' => (string) $percent,
            'donations_cover_fees_fixed' => (string) $fixed,
            'donations_campaign_title' => trim((string) ($_POST['donations_campaign_title'] ?? '')),
            'donations_campaign_text' => trim((string) ($_POST['donations_campaign_text'] ?? '')),
            'donations_campaign_image' => trim((string) ($_POST['donations_campaign_image'] ?? '')),
            'donations_thanks_text' => trim((string) ($_POST['donations_thanks_text'] ?? '')),
        ]);

        Flash::set('success', 'Setările de donații au fost salvate.');
        header('Location: /admin/settings/donations');
    }

    // ------------------------------------------------------------------ ajutoare

    /** Emailurile au nevoie de URL absolut pentru logo, altfel clienții nu îl încarcă. */
    public static function emailBrandSettings(array $settings): array
    {
        if (trim((string) ($settings['email_site_url'] ?? '')) === '') {
            $settings['email_site_url'] = \App\Support\AppUrl::base();
        }

        return $settings;
    }

    /** Scrie în storage/logs/account.log ce a mers prost, ca problemele să nu mai fie invizibile (ca în vertij). */
    public static function logAccountIssue(string $context, string $message): void
    {
        $dir = Paths::stocareSite('logs');
        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }

        @file_put_contents(
            $dir . '/account.log',
            sprintf('[%s] %s: %s%s', date('Y-m-d H:i:s'), $context, $message, PHP_EOL),
            FILE_APPEND
        );
    }
}
