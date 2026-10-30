<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Dispecerul webhook-ului Stripe (WP-25: VT-X1, port-vertij §4.1). `/webhook/stripe` e unul singur pentru tot site-ul:
 * plățile magazinului, linkurile de plată (X5), donațiile și membrii (Etapa 4). După verificarea semnăturii,
 * `SiteController::stripeWebhook` dă evenimentul aici:
 *
 *  1. îl notează în `stripe_events` (migrarea plata_stripe/0035; cheia e id-ul evenimentului): un eveniment deja procesat
 *     primește 200 „duplicate” (Stripe retrimite până primește 200), fără să mai atingă comanda;
 *  2. îi găsește proprietarul (`proprietar`, regulile în ordine: activarea unui cont de membru → `membri`; o donație,
 *     un abonament sau o plată din `donations` → `donatii`; `metadata.payment_link_token` → `link_plata`; o comandă
 *     (`metadata.order_number`, `order_id`, `client_reference_id`) → `magazin`);
 *  3. rulează handler-ul înregistrat pentru proprietar și tip (`handler`). **Handler-ul rulează în orice stare a
 *     pluginului**: banii primiți se înregistrează mereu. Primește `$vizibil` (pluginul pornit sau „se oprește”): emailurile
 *     către clienți se trimit doar atunci (regula pluginurilor noi; handler-ele magazinului sunt metodele de dinainte,
 *     neschimbate). Cu proprietarul oprit, `Plugins::jurnalOdataPeZi` scrie un rând pe zi pentru fiecare tip;
 *  4. notează rezultatul și momentul procesării. Răspunsul e mereu 200 după o semnătură bună (un tip necunoscut
 *     sau fără proprietar: „ok”, ca înainte); o semnătură greșită rămâne 400 (în controller).
 *
 * Evenimentele pe care le cere fiecare plugin stau în catalog (`stripe_evenimente`, config/plugins.php); Setări plăți le
 * arată pe cele ale pluginurilor de pe site și verifică endpoint-ul de webhook al contului (`verificaWebhook`).
 */
final class StripeEvenimente
{
    /** @var array<string, array<string, callable>> proprietar => tip => handler */
    private static array $handlere = [];
    /** Tabelul există (o dată pe cerere)? Pe o bază nemigrată evenimentele se procesează fără notare, ca înainte. */
    private static ?bool $areTabel = null;

    /**
     * Înregistrează handler-ul unui plugin pentru un tip de eveniment. Semnătura:
     * `fn (PDO $db, array $obiect, array $eveniment, bool $vizibil): ?string` (textul rezultatului, pentru jurnal).
     */
    public static function handler(string $plugin, string $tip, callable $fn): void
    {
        self::$handlere[$plugin][$tip] = $fn;
    }

    /** Doar pentru teste: uită handler-ele și ce s-a citit din bază. */
    public static function reseteaza(): void
    {
        self::$handlere = [];
        self::$areTabel = null;
    }

    /**
     * Procesează un eveniment cu semnătura verificată.
     *
     * @return array{cod: int, text: string, proprietar: ?string}
     */
    public static function proceseaza(PDO $db, array $eveniment): array
    {
        $idEveniment = trim((string) ($eveniment['id'] ?? ''));
        $tip = (string) ($eveniment['type'] ?? '');
        $obiect = $eveniment['data']['object'] ?? null;
        $proprietar = is_array($obiect) ? self::proprietar($db, $eveniment) : null;

        $noteaza = $idEveniment !== '' && self::areTabel($db);
        if ($noteaza) {
            try {
                $stmt = $db->prepare('INSERT IGNORE INTO stripe_events (event_id, type, api_version, livemode, object_id, proprietar, primit_la)
                    VALUES (:id, :tip, :versiune, :live, :obiect, :proprietar, NOW())');
                $stmt->execute([
                    'id' => mb_substr($idEveniment, 0, 255),
                    'tip' => mb_substr($tip, 0, 100),
                    'versiune' => mb_substr(trim((string) ($eveniment['api_version'] ?? '')), 0, 40) ?: null,
                    'live' => !empty($eveniment['livemode']) ? 1 : 0,
                    'obiect' => is_array($obiect) ? (mb_substr(trim((string) ($obiect['id'] ?? '')), 0, 255) ?: null) : null,
                    'proprietar' => $proprietar,
                ]);
                if ($stmt->rowCount() === 0) {
                    $procesat = $db->prepare('SELECT procesat_la FROM stripe_events WHERE event_id = :id');
                    $procesat->execute(['id' => mb_substr($idEveniment, 0, 255)]);
                    if ((string) ($procesat->fetchColumn() ?: '') !== '') {
                        return ['cod' => 200, 'text' => 'duplicate', 'proprietar' => $proprietar];
                    }
                    // Notat, dar neprocesat (o încercare oprită la jumătate): se procesează acum.
                }
            } catch (Throwable) {
                $noteaza = false;
            }
        }

        if (!is_array($obiect)) {
            self::noteazaRezultat($db, $noteaza, $idEveniment, 'fara_obiect');
            return ['cod' => 200, 'text' => 'ok', 'proprietar' => null];
        }

        $rezultat = 'fara_proprietar';
        if ($proprietar !== null) {
            $handler = self::$handlere[$proprietar][$tip] ?? null;
            if ($handler === null) {
                $rezultat = 'tip_neasteptat';
            } else {
                $vizibil = Plugins::vizibil($proprietar);
                if (!$vizibil) {
                    Plugins::jurnalOdataPeZi('stripe:' . $proprietar . ':' . $tip, 'Webhook Stripe „' . $tip . '” pentru pluginul oprit „'
                        . $proprietar . '”: banii s-au înregistrat, fără emailuri către client.');
                }
                $rezultat = (string) ($handler($db, $obiect, $eveniment, $vizibil) ?? 'procesat');
            }
        }
        self::noteazaRezultat($db, $noteaza, $idEveniment, $rezultat);

        return ['cod' => 200, 'text' => 'ok', 'proprietar' => $proprietar];
    }

    /**
     * Al cui e evenimentul: `membri`, `donatii`, `link_plata`, `magazin` sau null (al nimănui de pe acest site).
     */
    public static function proprietar(PDO $db, array $eveniment): ?string
    {
        $obiect = $eveniment['data']['object'] ?? null;
        if (!is_array($obiect)) {
            return null;
        }
        $metadata = is_array($obiect['metadata'] ?? null) ? $obiect['metadata'] : [];
        $tipMetadata = trim((string) ($metadata['type'] ?? ''));
        if ($tipMetadata === 'account_activation') {
            return 'membri';
        }
        if ($tipMetadata === 'donation' || self::alDonatiilor($db, $obiect)) {
            return 'donatii';
        }
        if (trim((string) ($metadata['payment_link_token'] ?? '')) !== '') {
            return 'link_plata';
        }
        if (trim((string) ($metadata['order_number'] ?? '')) !== '' || (int) ($metadata['order_id'] ?? 0) > 0
            || (str_starts_with((string) ($obiect['object'] ?? ''), 'checkout.session') && trim((string) ($obiect['client_reference_id'] ?? '')) !== '')) {
            return 'magazin';
        }
        return null;
    }

    /** Un abonament, o factură sau o plată ale unei donații (tabelul `donations`, al pluginului `donatii`, Etapa 4)? */
    private static function alDonatiilor(PDO $db, array $obiect): bool
    {
        $abonament = '';
        $plata = '';
        $tipObiect = (string) ($obiect['object'] ?? '');
        if ($tipObiect === 'subscription') {
            $abonament = (string) ($obiect['id'] ?? '');
        } elseif ($tipObiect === 'invoice') {
            // Două forme, după versiunea API a endpoint-ului (port-vertij §4.1).
            $abonament = (string) ($obiect['subscription'] ?? ($obiect['parent']['subscription_details']['subscription'] ?? ''));
        } elseif ($tipObiect === 'payment_intent') {
            $plata = (string) ($obiect['id'] ?? '');
        } elseif ($tipObiect === 'charge') {
            $plata = (string) ($obiect['payment_intent'] ?? '');
        }
        if ($abonament === '' && $plata === '') {
            return false;
        }
        try {
            $stmt = $db->prepare('SELECT 1 FROM donations WHERE ' . ($abonament !== '' ? 'stripe_subscription_id = :v' : 'stripe_payment_intent_id = :v')
                . ' LIMIT 1');
            $stmt->execute(['v' => $abonament !== '' ? $abonament : $plata]);
            return $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false; // fără tabelul donațiilor (pluginul nu e portat): nicio donație
        }
    }

    /**
     * Evenimentele cerute de pluginurile vizibile (câmpul `stripe_evenimente` din catalog), fără dubluri, sortate.
     *
     * @return list<string>
     */
    public static function ceruteDePluginuri(): array
    {
        $tipuri = [];
        foreach (Plugins::catalog()['pluginuri'] as $cheie => $definitie) {
            if (Plugins::vizibil((string) $cheie)) {
                foreach ((array) ($definitie['stripe_evenimente'] ?? []) as $tip) {
                    $tipuri[(string) $tip] = true;
                }
            }
        }
        $lista = array_keys($tipuri);
        sort($lista);
        return $lista;
    }

    /**
     * Ultimul eveniment primit, pe tipuri (pentru Setări plăți).
     *
     * @return array<string, string> tip => data
     */
    public static function ultimelePrimite(PDO $db): array
    {
        if (!self::areTabel($db)) {
            return [];
        }
        try {
            $out = [];
            foreach ($db->query('SELECT type, MAX(primit_la) AS ultimul FROM stripe_events GROUP BY type ORDER BY type')->fetchAll() ?: [] as $rand) {
                $out[(string) $rand['type']] = (string) $rand['ultimul'];
            }
            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * „Verifică webhook-ul” din Setări plăți: endpoint-ul contului Stripe cu adresa `<APP_URL>/webhook/stripe`, dacă e
     * pornit, versiunea lui API și evenimentele cerute de pluginurile site-ului care îi lipsesc.
     *
     * @return array{gasit: bool, activ: bool, versiune: string, lipsa: list<string>, eroare: string, adresa: string}
     */
    public static function verificaWebhook(string $cheieSecreta, string $appUrl): array
    {
        $adresa = rtrim($appUrl, '/') . '/webhook/stripe';
        $rezultat = ['gasit' => false, 'activ' => false, 'versiune' => '', 'lipsa' => [], 'eroare' => '', 'adresa' => $adresa];
        if (trim($cheieSecreta) === '') {
            $rezultat['eroare'] = 'Lipsește cheia secretă Stripe.';
            return $rezultat;
        }
        try {
            $lista = StripeGateway::listWebhookEndpoints(trim($cheieSecreta));
        } catch (Throwable $e) {
            $rezultat['eroare'] = 'Stripe nu a răspuns: ' . mb_substr($e->getMessage(), 0, 200);
            return $rezultat;
        }
        foreach ((array) ($lista['data'] ?? []) as $endpoint) {
            if (!is_array($endpoint) || rtrim((string) ($endpoint['url'] ?? ''), '/') !== $adresa) {
                continue;
            }
            $evenimente = array_map('strval', (array) ($endpoint['enabled_events'] ?? []));
            $rezultat['gasit'] = true;
            $rezultat['activ'] = (string) ($endpoint['status'] ?? '') === 'enabled';
            $rezultat['versiune'] = (string) ($endpoint['api_version'] ?? '');
            $rezultat['lipsa'] = in_array('*', $evenimente, true) ? [] : array_values(array_diff(self::ceruteDePluginuri(), $evenimente));
            return $rezultat;
        }
        return $rezultat;
    }

    private static function noteazaRezultat(PDO $db, bool $noteaza, string $idEveniment, string $rezultat): void
    {
        if (!$noteaza) {
            return;
        }
        try {
            $db->prepare('UPDATE stripe_events SET procesat_la = NOW(), rezultat = :r WHERE event_id = :id')
                ->execute(['r' => mb_substr($rezultat, 0, 255), 'id' => mb_substr($idEveniment, 0, 255)]);
        } catch (Throwable) {
            // Notarea nu oprește procesarea: banii sunt deja scriși de handler.
        }
    }

    private static function areTabel(PDO $db): bool
    {
        if (self::$areTabel === null) {
            try {
                $stmt = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stripe_events'");
                self::$areTabel = $stmt !== false && (int) $stmt->fetchColumn() > 0;
            } catch (Throwable) {
                self::$areTabel = false;
            }
        }
        return self::$areTabel;
    }
}
