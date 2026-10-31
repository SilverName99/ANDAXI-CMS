<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Rezultatul pe comandă al unei acțiuni în masă din Admin → Comenzi (faza D, WP-33): după `POST /admin/orders/bulk`, lista
 * arată, deasupra tabelului și pe rândul fiecărei comenzi, ce a mers și ce nu (AWB generat, eroarea FAN, trimisă în ERP,
 * statusul schimbat, transportul pus, mutată în coș). Doar o notă de afișat, ținută în sesiune până la prima deschidere a
 * listei, ca mesajul de după o salvare (Flash): acțiunea în sine, mesajul ei și tot ce scrie în bază rămân ale
 * AdminController::ordersBulkAction, neschimbate.
 */
final class RezultatMasaComenzi
{
    private const CHEIE = 'adm_comenzi_masa_rezultat';

    /** Cele mai multe rânduri ținute (acțiunea în masă nu are o limită a bifelor; lista are cel mult 400 de comenzi). */
    private const MAXIM = 400;

    /**
     * @param array<int, array{ok: bool, mesaj?: string}> $rezultate id comandă => rezultat
     */
    public static function pune(string $actiune, array $rezultate): void
    {
        $curate = [];
        foreach ($rezultate as $id => $rezultat) {
            if ((int) $id <= 0 || count($curate) >= self::MAXIM) {
                continue;
            }
            $curate[(int) $id] = ['ok' => (bool) ($rezultat['ok'] ?? false), 'mesaj' => mb_substr(trim((string) ($rezultat['mesaj'] ?? '')), 0, 300)];
        }
        $_SESSION[self::CHEIE] = ['actiune' => $actiune, 'rezultate' => $curate];
    }

    /**
     * Rezultatul ultimei acțiuni (și îl șterge), sau null.
     *
     * @return array{actiune: string, rezultate: array<int, array{ok: bool, mesaj: string}>}|null
     */
    public static function ia(): ?array
    {
        $rezultat = $_SESSION[self::CHEIE] ?? null;
        unset($_SESSION[self::CHEIE]);
        if (!is_array($rezultat) || !is_array($rezultat['rezultate'] ?? null)) {
            return null;
        }
        return ['actiune' => (string) ($rezultat['actiune'] ?? ''), 'rezultate' => $rezultat['rezultate']];
    }

    /** Numele acțiunii, pentru titlul notei. */
    public static function eticheta(string $actiune): string
    {
        return match ($actiune) {
            'awb' => 'Generează AWB',
            'erp_retry' => 'Trimite în ERP',
            'status' => 'Schimbă statusul',
            'shipping' => 'Corectează transportul',
            'delete' => 'Mută în coș',
            default => $actiune,
        };
    }
}
