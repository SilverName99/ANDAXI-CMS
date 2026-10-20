<?php

declare(strict_types=1);

namespace App\Support\Migrari;

use PDO;
use Throwable;

/**
 * Garda de la fiecare cerere (docs/MIGRARI.md, „Garda”): baza e la zi cu migrările din cod?
 *
 *  - `la_zi`: `_platforma_stare.ultima_ord` ≥ numărul ultimei migrări din cod
 *    (`database/migrari/ultima.php`). O bază înaintea codului (după o revenire la codul de
 *    dinainte) e tot la zi: migrările doar adaugă, deci codul vechi merge pe schema nouă;
 *  - `in_urma`: runner-ul a lucrat pe bază, dar nu a ajuns la ultima migrare din cod (codul nou
 *    a fost urcat, `scripts/migreaza.php --aplica` încă nu a rulat sau s-a oprit), sau pașii
 *    site-ului (înghețarea setărilor, `_platforma_stare.pasi_site`) n-au terminat;
 *  - `fara_registru`: baza n-a văzut niciodată runner-ul (fără `_platforma_stare`): un site pe
 *    codul vechi, de dinainte de adoptare;
 *  - `eroare`: fără bază, sau citirea n-a mers (conexiunea căzută, fără drept de citire).
 *
 * O singură citire după cheia primară (rândurile `ultima_ord` și `pasi_site`), ținută minte pe toată
 * cererea (pe conexiune). O folosesc
 * `/health` (câmpul `schema`; 503 când e `in_urma`), bannerul din admin și cele 36 de funcții
 * `ensure*Schema`/`asigura*Schema` ale codului moștenit (regula R1): pe o bază `la_zi` ele ies pe
 * loc, deci nicio cerere nu mai trimite DDL; pe una `fara_registru`, `in_urma` sau `eroare` fac ce
 * făceau până acum, ca un site încă neadoptat să meargă exact ca înainte. Corpurile lor se șterg
 * după cele patru adoptări (R2, MIG-11).
 */
final class Garda
{
    public const LA_ZI = 'la_zi';
    public const IN_URMA = 'in_urma';
    public const FARA_REGISTRU = 'fara_registru';
    public const EROARE = 'eroare';

    /** @var \WeakMap<PDO, string>|null starea, pe conexiune (o conexiune închisă își ia starea cu ea) */
    private static ?\WeakMap $stari = null;
    private static ?int $ultimaDinCod = null;

    public static function stare(?PDO $db): string
    {
        if (!$db instanceof PDO) {
            return self::EROARE;
        }
        self::$stari ??= new \WeakMap();
        if (isset(self::$stari[$db])) {
            return self::$stari[$db];
        }
        return self::$stari[$db] = self::citeste($db);
    }

    /** Starea bazei site-ului (conexiunea aplicației, Database::connection): pentru /health și bannerul din admin. */
    public static function stareSite(): string
    {
        $config = require dirname(__DIR__, 3) . '/config/app.php';
        return self::stare(\App\Support\Database::connection((array) ($config['db'] ?? [])));
    }

    /** Ce înseamnă o stare care nu e `la_zi` și ce e de făcut (bannerul administratorului general). */
    public static function explicatie(string $stare): string
    {
        return match ($stare) {
            self::IN_URMA => 'Codul are migrări (sau pașii site-ului, de exemplu înghețarea setărilor) care n-au rulat încă pe această bază. '
                . 'Rulează pe server: php scripts/migreaza.php --aplica (cu SITE_SLUG din .env; docs/MIGRARI.md). '
                . 'Până atunci site-ul își face singur coloanele, ca înainte.',
            self::FARA_REGISTRU => 'Baza n-a trecut încă prin migrări (un site de dinainte de codul comun). Rulează pe server: '
                . 'php scripts/migreaza.php --plan, apoi --aplica (docs/MIGRARI.md). Până atunci site-ul merge ca înainte.',
            self::EROARE => 'Starea migrărilor nu se poate citi (baza nu răspunde sau lipsește database/migrari/ultima.php).',
            default => '',
        };
    }

    /** Numărul ultimei migrări din cod (database/migrari/ultima.php), sau null dacă fișierul lipsește. */
    public static function ultimaDinCod(): ?int
    {
        if (self::$ultimaDinCod === null) {
            $fisier = dirname(__DIR__, 3) . '/database/migrari/ultima.php';
            $date = is_file($fisier) ? require $fisier : null;
            if (!is_array($date) || !isset($date['ord']) || !is_int($date['ord'])) {
                return null;
            }
            self::$ultimaDinCod = $date['ord'];
        }
        return self::$ultimaDinCod;
    }

    /** Uită stările ținute minte (teste, unelte care schimbă baza în același proces). */
    public static function uita(): void
    {
        self::$stari = null;
        self::$ultimaDinCod = null;
    }

    private static function citeste(PDO $db): string
    {
        $ultima = self::ultimaDinCod();
        if ($ultima === null) {
            return self::EROARE;
        }
        try {
            $stmt = $db->query("SELECT cheie, valoare FROM " . Runner::TABEL_STARE . " WHERE cheie IN ('ultima_ord', '"
                . Runner::CHEIE_PASI_SITE . "')");
            if ($stmt === false) {
                // O conexiune fără excepții (ERRMODE_SILENT): codul erorii spune dacă lipsește tabelul.
                return (int) ($db->errorInfo()[1] ?? 0) === 1146 ? self::FARA_REGISTRU : self::EROARE;
            }
            $randuri = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            $cod = $e instanceof \PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
            return $cod === 1146 ? self::FARA_REGISTRU : self::EROARE;
        }
        if (!is_array($randuri)) {
            return self::EROARE;
        }
        // Pașii site-ului (înghețarea setărilor) au început și n-au terminat: nimic nu scrie încă setări (pașii de activare).
        if (isset($randuri[Runner::CHEIE_PASI_SITE])) {
            return self::IN_URMA;
        }
        $valoare = $randuri['ultima_ord'] ?? false;
        // Tabelul există, dar fără rând (runner-ul s-a oprit înainte să scrie starea): în urmă.
        if ($valoare === false || !is_string($valoare) || preg_match('/^\d+$/', $valoare) !== 1) {
            return self::IN_URMA;
        }
        return (int) $valoare >= $ultima ? self::LA_ZI : self::IN_URMA;
    }
}
