<?php

declare(strict_types=1);

namespace App\Support\Migrari;

use PDO;
use Throwable;

/**
 * Garda de la fiecare cerere (docs/MIGRARI.md, „Garda”): baza e la zi cu migrările din cod?
 *
 *  - `la_zi`: fiecare migrare din cod (`database/migrari/ultima.php`) e aplicată sau detectată pe bază. Pe
 *    drumul obișnuit, codul e cel cu care a rulat ultima oară runner-ul (`_platforma_stare.amprenta` = amprenta
 *    din ultima.php) și `ultima_ord` ≥ numărul ultimei migrări din cod. Altfel (cod nou, încă nemigrat, sau
 *    codul întors la o versiune de dinainte) se citesc migrările terminate din `_platforma_migrari`: numerele
 *    sunt rezervate pe pachete de lucru (planul Etapei 1, §4.3), deci o migrare nouă poate avea un număr mai
 *    mic decât una deja aplicată, iar `ultima_ord` singur ar spune „la zi”. O bază înaintea codului (după o
 *    revenire la codul de dinainte) e tot la zi: migrările doar adaugă, deci codul vechi merge pe schema nouă;
 *  - `in_urma`: runner-ul a lucrat pe bază, dar o migrare din cod nu e terminată pe ea (codul nou a fost urcat,
 *    `scripts/migreaza.php --aplica` încă nu a rulat sau s-a oprit), sau pașii site-ului (înghețarea setărilor,
 *    `_platforma_stare.pasi_site`) n-au terminat;
 *  - `fara_registru`: baza n-a văzut niciodată runner-ul (fără `_platforma_stare`): un site pe
 *    codul vechi, de dinainte de adoptare;
 *  - `eroare`: fără bază, sau citirea n-a mers (conexiunea căzută, fără drept de citire).
 *
 * O citire după cheia primară (rândurile `ultima_ord`, `amprenta` și `pasi_site`), plus lista migrărilor
 * terminate doar când amprenta diferă; ținută minte pe toată cererea (pe conexiune). O folosesc
 * `/health` (câmpul `schema`; 503 când e `in_urma`), bannerul din admin, pașii de activare ai pluginurilor
 * și cele 36 de funcții `ensure*Schema`/`asigura*Schema` ale codului moștenit (regula R1): pe o bază `la_zi`
 * ele ies pe loc, deci nicio cerere nu mai trimite DDL; pe una `fara_registru`, `in_urma` sau `eroare` fac ce
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
    /** @var array{ord: int, amprenta: string, migrari: list<string>}|null ultima.php, citit o dată */
    private static ?array $cod = null;
    /** Dosarul migrărilor al cărui ultima.php se citește (null = database/migrari); îl schimbă doar testele. */
    private static ?string $dosar = null;

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
        return self::cod()['ord'] ?? null;
    }

    /**
     * Doar testele (tools/dev/test-migrari.php): garda citește ultima.php din alt dosar de migrări, de exemplu
     * al unor migrări de jucărie; null = database/migrari. Uită și stările ținute minte.
     */
    public static function folosesteDosarul(?string $dosar): void
    {
        self::$dosar = $dosar;
        self::uita();
    }

    /** Uită stările ținute minte (teste, unelte care schimbă baza în același proces). */
    public static function uita(): void
    {
        self::$stari = null;
        self::$cod = null;
    }

    /**
     * ultima.php: ord (int), amprenta (sha256), migrari (id-urile, toate text); null dacă lipsește sau are altă formă
     * (un fișier din altă versiune a codului, nescris de genereaza-fixture.php --asteptat): garda spune atunci `eroare`.
     *
     * @return array{ord: int, amprenta: string, migrari: list<string>}|null
     */
    private static function cod(): ?array
    {
        if (self::$cod === null) {
            $fisier = (self::$dosar ?? dirname(__DIR__, 3) . '/database/migrari') . '/ultima.php';
            $date = is_file($fisier) ? require $fisier : null;
            if (!is_array($date) || !is_int($date['ord'] ?? null) || !is_string($date['amprenta'] ?? null)
                || !is_array($date['migrari'] ?? null) || array_filter($date['migrari'], 'is_string') !== $date['migrari']) {
                return null;
            }
            self::$cod = ['ord' => $date['ord'], 'amprenta' => $date['amprenta'], 'migrari' => array_values($date['migrari'])];
        }
        return self::$cod;
    }

    private static function citeste(PDO $db): string
    {
        $cod = self::cod();
        if ($cod === null) {
            return self::EROARE;
        }
        try {
            $stmt = $db->query("SELECT cheie, valoare FROM " . Runner::TABEL_STARE . " WHERE cheie IN ('ultima_ord', 'amprenta', '"
                . Runner::CHEIE_PASI_SITE . "')");
            if ($stmt === false) {
                // O conexiune fără excepții (ERRMODE_SILENT): codul erorii spune dacă lipsește tabelul.
                return (int) ($db->errorInfo()[1] ?? 0) === 1146 ? self::FARA_REGISTRU : self::EROARE;
            }
            $randuri = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            $codEroare = $e instanceof \PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
            return $codEroare === 1146 ? self::FARA_REGISTRU : self::EROARE;
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
        // Același cod ca la ultima rulare a runner-ului (aceeași listă „id:sha”): ultima_ord e cea mai mare ord
        // până la care toate migrările acestui cod sunt terminate, deci ajunge comparată cu ultima din cod.
        if (($randuri['amprenta'] ?? null) === $cod['amprenta']) {
            return (int) $valoare >= $cod['ord'] ? self::LA_ZI : self::IN_URMA;
        }
        // Alt cod decât la ultima rulare: o migrare nouă (poate cu un număr sub ultima_ord) sau codul de dinainte.
        return self::dupaMigrarileTerminate($db, $cod['migrari']);
    }

    /**
     * `la_zi` dacă fiecare migrare din cod e aplicată sau detectată în _platforma_migrari (în plus pot fi altele: o
     * bază înaintea codului), altfel `in_urma` (și fără _platforma_migrari, deși _platforma_stare există).
     *
     * @param list<string> $migrari id-urile din ultima.php
     */
    private static function dupaMigrarileTerminate(PDO $db, array $migrari): string
    {
        try {
            $stmt = $db->query('SELECT id FROM ' . Runner::TABEL_MIGRARI . " WHERE rezultat IN ('aplicata', 'detectata')");
            if ($stmt === false) {
                return (int) ($db->errorInfo()[1] ?? 0) === 1146 ? self::IN_URMA : self::EROARE;
            }
            $terminate = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        } catch (Throwable $e) {
            $codEroare = $e instanceof \PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
            return $codEroare === 1146 ? self::IN_URMA : self::EROARE;
        }
        foreach ($migrari as $id) {
            if (!isset($terminate[$id])) {
                return self::IN_URMA;
            }
        }
        return self::LA_ZI;
    }
}
