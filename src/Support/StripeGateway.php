<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Cererile spre API-ul Stripe. Din vertij (VT-C10, WP-15) au venit PaymentIntent, Customer, Product și Subscription
 * (creare, citire, anulare cu verbul DELETE), antetul `Stripe-Version` din setarea `stripe_api_version` (gol = fără
 * antet, ca până acum: contul folosește versiunea lui implicită), un `Idempotency-Key` opțional la creări și lista
 * endpoint-urilor de webhook (verificarea din Setări plăți, WP-25).
 */
final class StripeGateway
{
    private const API_BASE = 'https://api.stripe.com';

    /** Versiunea API aleasă de cel care a citit deja setările (null = se citește din baza conexiunii, o dată). */
    private static ?string $versiuneApi = null;

    /** Setările site-ului, dacă cel care cheamă le are deja: de aici se ia `stripe_api_version`. */
    public static function folosesteSetarile(array $setari): void
    {
        self::$versiuneApi = trim((string) ($setari['stripe_api_version'] ?? ''));
    }

    /**
     * Antetul `Stripe-Version` al cererilor: setarea `stripe_api_version`, citită o dată din baza conexiunii deschise
     * (`Database::connection`) dacă nu a dat-o nimeni prin `folosesteSetarile`. Gol = fără antet.
     */
    public static function versiuneApi(): string
    {
        if (self::$versiuneApi !== null) {
            return self::$versiuneApi;
        }
        $versiune = '';
        $db = Database::connection([]);
        if ($db instanceof \PDO) {
            try {
                $stmt = $db->prepare('SELECT `value` FROM settings WHERE `key` = :cheie LIMIT 1');
                $stmt->execute(['cheie' => 'stripe_api_version']);
                $valoare = $stmt->fetchColumn();
                $versiune = $valoare === false || $valoare === null ? '' : trim((string) $valoare);
            } catch (\Throwable) {
                $versiune = '';
            }
        }
        self::$versiuneApi = $versiune;
        return $versiune;
    }

    /**
     * Adresa API-ului. `STRIPE_API_BASE` din mediu o poate înlocui DOAR cu un server de probă de pe același calculator
     * (http://127.0.0.1:… sau http://localhost:…), pentru teste; orice altă valoare e ignorată, ca o cheie secretă să
     * nu poată pleca spre altă gazdă.
     */
    public static function apiBase(): string
    {
        $alta = rtrim(trim((string) Env::get('STRIPE_API_BASE', '')), '/');
        if ($alta !== '' && preg_match('#^http://(127\.0\.0\.1|localhost)(:\d{1,5})?$#', $alta) === 1) {
            return $alta;
        }
        return self::API_BASE;
    }

    public static function createCheckoutSession(string $secretKey, array $fields): array
    {
        return self::request('POST', '/v1/checkout/sessions', $secretKey, $fields);
    }

    public static function retrieveCheckoutSession(string $secretKey, string $sessionId, array $fields = []): array
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            throw new RuntimeException('ID-ul sesiunii Stripe lipsește.');
        }

        // Moștenit (codul de plecare): prin curl câmpurile unei cereri GET nu pleacă, deci `expand[]` al apelanților de azi nu
        // ajunge la Stripe și `payment_intent` vine ca id, exact cum îl citește codul banilor. Rămâne așa (comportament
        // neschimbat); metodele noi trimit câmpurile GET în query (DEZVOLTARE.md, „Erori moștenite”).
        return self::request('GET', '/v1/checkout/sessions/' . rawurlencode($sessionId), $secretKey, $fields);
    }

    /** O plată unică (PaymentIntent); cu `automatic_payment_methods` Stripe oferă cardul, Google Pay și Apple Pay. */
    public static function createPaymentIntent(string $secretKey, array $fields, ?string $idempotencyKey = null): array
    {
        return self::request('POST', '/v1/payment_intents', $secretKey, $fields, $idempotencyKey);
    }

    public static function retrievePaymentIntent(string $secretKey, string $paymentIntentId, array $fields = []): array
    {
        $paymentIntentId = trim($paymentIntentId);
        if ($paymentIntentId === '') {
            throw new RuntimeException('ID-ul PaymentIntent Stripe lipsește.');
        }

        return self::request('GET', '/v1/payment_intents/' . rawurlencode($paymentIntentId), $secretKey, $fields, null, true);
    }

    public static function createCustomer(string $secretKey, array $fields, ?string $idempotencyKey = null): array
    {
        return self::request('POST', '/v1/customers', $secretKey, $fields, $idempotencyKey);
    }

    /** Abonamentele cer un Product existent (Checkout acceptă date inline, abonamentele nu). */
    public static function createProduct(string $secretKey, array $fields, ?string $idempotencyKey = null): array
    {
        return self::request('POST', '/v1/products', $secretKey, $fields, $idempotencyKey);
    }

    /**
     * Un abonament (de exemplu o donație lunară). Cu `payment_behavior=default_incomplete` și expand pe factura lui,
     * întoarce secretul clientului pentru confirmarea cu Payment Element.
     */
    public static function createSubscription(string $secretKey, array $fields, ?string $idempotencyKey = null): array
    {
        return self::request('POST', '/v1/subscriptions', $secretKey, $fields, $idempotencyKey);
    }

    public static function retrieveSubscription(string $secretKey, string $subscriptionId, array $fields = []): array
    {
        $subscriptionId = trim($subscriptionId);
        if ($subscriptionId === '') {
            throw new RuntimeException('Id de abonament lipsă.');
        }

        return self::request('GET', '/v1/subscriptions/' . rawurlencode($subscriptionId), $secretKey, $fields, null, true);
    }

    /** Oprește un abonament imediat (verbul DELETE). */
    public static function cancelSubscription(string $secretKey, string $subscriptionId): array
    {
        $subscriptionId = trim($subscriptionId);
        if ($subscriptionId === '') {
            throw new RuntimeException('Id de abonament lipsă.');
        }

        return self::request('DELETE', '/v1/subscriptions/' . rawurlencode($subscriptionId), $secretKey);
    }

    /** Endpoint-urile de webhook ale contului (până la 100), pentru „Verifică webhook-ul” din Setări plăți. */
    public static function listWebhookEndpoints(string $secretKey): array
    {
        return self::request('GET', '/v1/webhook_endpoints', $secretKey, ['limit' => 100], null, true);
    }

    public static function verifyWebhookSignature(
        string $payload,
        string $signatureHeader,
        string $endpointSecret,
        int $tolerance = 300
    ): bool {
        if ($payload === '' || $signatureHeader === '' || $endpointSecret === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $chunk) {
            if (!str_contains($chunk, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $chunk, 2);
            $parts[trim($key)][] = trim($value);
        }

        $timestamp = isset($parts['t'][0]) ? (int) $parts['t'][0] : 0;
        $signatures = $parts['v1'] ?? [];
        if ($timestamp <= 0 || $signatures === []) {
            return false;
        }

        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signedPayload, $endpointSecret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * O cerere spre Stripe. `$queryLaGet`: câmpurile unei cereri GET pleacă în query (metodele noi); fără el, prin curl
     * nu pleacă (ca până acum, vezi retrieveCheckoutSession). Verbele în afară de GET și POST (DELETE) trimit câmpurile
     * în corp. `Stripe-Version` doar când setarea are o valoare; `Idempotency-Key` doar când e dat.
     */
    private static function request(
        string $method,
        string $path,
        string $secretKey,
        array $fields = [],
        ?string $idempotencyKey = null,
        bool $queryLaGet = false
    ): array {
        if ($secretKey === '') {
            throw new RuntimeException('Cheia Stripe secret lipsește.');
        }
        // Site-ul de verificare (docs/VERIFICARE.md): doar o cheie de test (sk_test_…).
        Verificare::verificaCheieStripe($secretKey);

        $method = strtoupper($method);
        $url = self::apiBase() . $path;
        $payload = http_build_query($fields);
        $anteteComune = [
            'Authorization: Bearer ' . $secretKey,
            'Content-Type: application/x-www-form-urlencoded',
        ];
        $versiune = self::versiuneApi();
        if ($versiune !== '') {
            $anteteComune[] = 'Stripe-Version: ' . $versiune;
        }
        $idempotencyKey = $idempotencyKey !== null ? trim($idempotencyKey) : '';
        if ($idempotencyKey !== '' && $method === 'POST') {
            $anteteComune[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init();
            if ($ch === false) {
                throw new RuntimeException('Nu s-a putut inițializa conexiunea Stripe.');
            }

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $anteteComune);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            } elseif ($method !== 'GET') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if ($payload !== '') {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                }
            } elseif ($queryLaGet && $payload !== '') {
                curl_setopt($ch, CURLOPT_URL, $url . '?' . $payload);
            }

            $rawBody = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($rawBody === false) {
                throw new RuntimeException('Stripe API indisponibil: ' . $error);
            }
        } else {
            $requestUrl = $method === 'GET' && $payload !== '' ? $url . '?' . $payload : $url;
            $context = stream_context_create([
                'http' => [
                    'method' => $method,
                    'header' => implode("\r\n", $anteteComune),
                    'content' => $method !== 'GET' ? $payload : '',
                    'timeout' => 30,
                    'ignore_errors' => true,
                ],
            ]);

            $rawBody = @file_get_contents($requestUrl, false, $context);
            if ($rawBody === false) {
                throw new RuntimeException('Stripe API indisponibil.');
            }

            $status = 0;
            $headers = $http_response_header ?? [];
            foreach ($headers as $headerLine) {
                if (preg_match('#HTTP/\S+\s+(\d{3})#', (string) $headerLine, $matches)) {
                    $status = (int) $matches[1];
                    break;
                }
            }
        }

        $response = json_decode($rawBody, true);
        if (!is_array($response)) {
            throw new RuntimeException('Răspuns Stripe invalid.');
        }

        if ($status < 200 || $status >= 300) {
            $message = (string) ($response['error']['message'] ?? 'Eroare Stripe.');
            throw new RuntimeException($message);
        }

        return $response;
    }
}

