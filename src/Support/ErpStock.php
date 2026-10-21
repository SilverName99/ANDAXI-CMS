<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Disponibilitatea produselor, citită din ERP.
 *
 * Când integrarea de stoc e pornită, site-ul nu mai afișează cantitatea din
 * fișa produsului, ci doar dacă produsul e disponibil: ERP-ul întoarce stocul
 * din gestiune minus rezervările comenzilor în lucru.
 *
 * Regula de siguranță e „fail open": dacă ERP-ul nu răspunde sau nu cunoaște
 * SKU-ul, rămâne valabil stocul de pe site. Un ERP picat nu are voie să golească
 * magazinul.
 *
 * Ca afișarea să nu aștepte ERP-ul (planul Etapei 1, WP-12 C3; din Biovitality `c64fc2d`,
 * `6cc6fc5`): cererea de stoc are un timeout scurt (`ErpClient::fromDbRapid`, 3 secunde), un
 * eșec se ține minte 60 de secunde (`cache/erp-stock-esec`: în acest timp nu se mai întreabă
 * ERP-ul deloc), iar un SKU pe care ERP-ul nu-l cunoaște se ține minte tot 60 de secunde, cu
 * momentul răspunsului (`necunoscut_la`), în același fișier ca disponibilul celor cunoscute.
 * Altfel un ERP căzut ar fi adăugat timeout-ul la fiecare pagină de magazin, iar un produs
 * cu un SKU necunoscut ar fi întrebat ERP-ul la fiecare afișare. Rezultatul rămâne același:
 * SKU-urile necunoscute lipsesc din el, deci rămâne stocul de pe site.
 */
final class ErpStock
{
    /** Cât timp ținem răspunsul, ca să nu lovim ERP-ul la fiecare afișare. */
    private const TTL_SECONDS = 60;

    /** @var array<string, float>|null Disponibilul pe SKU, pe durata cererii. */
    private static ?array $memo = null;

    /** @var array<string, int>|null SKU-urile necunoscute în ERP => momentul răspunsului (Unix). */
    private static ?array $necunoscute = null;

    /** Integrarea de stoc e pornită și configurată? */
    public static function enabled(?PDO $db): bool
    {
        if (!$db instanceof PDO) {
            return false;
        }
        try {
            $settings = Settings::all($db);
        } catch (Throwable) {
            return false;
        }
        return (string) ($settings['erp_enabled'] ?? '0') === '1'
            && (string) ($settings['erp_stock_enabled'] ?? '0') === '1'
            && Plugins::vizibil('erp');
    }

    /**
     * Marchează produsele indisponibile după ERP.
     *
     * Rândurile primite trebuie să aibă cheia `id`; SKU-urile se citesc din
     * baza de date, ca să nu fie nevoie de modificări în fiecare interogare.
     *
     * @param array<int, array<string, mixed>> $products
     */
    public static function applyToProducts(?PDO $db, array &$products): void
    {
        if ($products === [] || !self::enabled($db)) {
            return;
        }

        $ids = [];
        foreach ($products as $product) {
            $id = (int) ($product['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return;
        }

        $skuById = self::skusByProductId($db, array_values($ids));
        if ($skuById === []) {
            return;
        }

        $availability = self::availability($db, array_values($skuById));
        if ($availability === []) {
            return;
        }

        foreach ($products as &$product) {
            $id = (int) ($product['id'] ?? 0);
            $sku = $skuById[$id] ?? null;
            if ($sku === null) {
                continue;
            }
            $key = strtoupper($sku);
            if (!array_key_exists($key, $availability)) {
                continue;
            }
            $disponibil = $availability[$key];
            $product['out_of_stock'] = $disponibil > 0 ? 0 : 1;
            $product['stock'] = (int) floor(max(0.0, $disponibil));
            // Marcaj pentru interfețe: cantitatea afișată vine din gestiune,
            // nu din fișa produsului de pe site.
            $product['stock_from_erp'] = 1;
        }
        unset($product);
    }

    /** Varianta pentru un singur produs. */
    public static function applyToProduct(?PDO $db, array $product): array
    {
        $rows = [$product];
        self::applyToProducts($db, $rows);
        return $rows[0];
    }

    /**
     * Cantitatea disponibilă pe SKU (majuscule). SKU-urile pe care ERP-ul nu le
     * cunoaște lipsesc din rezultat, ca apelantul să păstreze stocul de pe site.
     *
     * @param string[] $skus
     * @return array<string, float>
     */
    public static function availability(?PDO $db, array $skus): array
    {
        if (!$db instanceof PDO || $skus === []) {
            return [];
        }

        $wanted = [];
        foreach ($skus as $sku) {
            $value = trim((string) $sku);
            if ($value !== '') {
                $wanted[strtoupper($value)] = $value;
            }
        }
        if ($wanted === []) {
            return [];
        }

        if (self::$memo === null || self::$necunoscute === null) {
            [self::$memo, self::$necunoscute] = self::readCache();
        }

        // De întrebat: SKU-urile care nu sunt în cache, nici ca știute, nici ca necunoscute de
        // mai puțin de 60 de secunde.
        $acum = time();
        $lipsa = [];
        foreach ($wanted as $key => $value) {
            if (array_key_exists($key, self::$memo)) {
                continue;
            }
            if (isset(self::$necunoscute[$key]) && $acum - self::$necunoscute[$key] < self::TTL_SECONDS) {
                continue;
            }
            $lipsa[$key] = $value;
        }
        if ($lipsa !== []) {
            // Timeout scurt: pe magazin, stocul din ERP nu merită așteptat 20 de secunde.
            $client = ErpClient::fromDbRapid($db);
            if ($client !== null && !self::erpEsuatRecent()) {
                try {
                    $raspuns = $client->stockBySku(array_values($lipsa));
                    $acum = time();
                    foreach ($lipsa as $key => $_) {
                        $info = $raspuns[$key] ?? null;
                        if (is_array($info) && ($info['cunoscut'] ?? false) === true) {
                            self::$memo[$key] = (float) ($info['disponibil'] ?? 0);
                            unset(self::$necunoscute[$key]);
                        } else {
                            // Necunoscut în ERP (sau lipsă din răspuns): nu intră în cache ca
                            // „indisponibil" — rămâne stocul de pe site —, dar nici nu se mai
                            // întreabă la fiecare afișare: 60 de secunde, cu momentul răspunsului.
                            self::$necunoscute[$key] = $acum;
                        }
                    }
                    self::writeCache(self::$memo, self::$necunoscute);
                    self::stergeMarcajEsec();
                } catch (Throwable) {
                    // ERP indisponibil: rămâne stocul de pe site. Eșecul se ține minte, altfel
                    // fiecare pagină ar aștepta din nou timeout-ul, cu ERP-ul căzut.
                    self::marcheazaEsec();
                }
            }
        }

        $out = [];
        foreach ($wanted as $key => $_) {
            if (array_key_exists($key, self::$memo)) {
                $out[$key] = self::$memo[$key];
            }
        }
        return $out;
    }

    /**
     * Golește cache-ul (după un import sau o schimbare de setări), cu tot cu SKU-urile necunoscute
     * și cu eșecul ținut minte: cu altă adresă sau altă cheie, ERP-ul se întreabă din nou imediat.
     */
    public static function flush(): void
    {
        self::$memo = null;
        self::$necunoscute = null;
        foreach ([self::cacheFile(), self::fisierEsec()] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * @param int[] $ids
     * @return array<int, string>
     */
    private static function skusByProductId(PDO $db, array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmt = $db->prepare(
                "SELECT id, sku FROM products WHERE id IN ($placeholders) AND sku IS NOT NULL AND sku <> ''"
            );
            $stmt->execute($ids);
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku !== '') {
                $out[(int) $row['id']] = $sku;
            }
        }
        return $out;
    }

    /**
     * Cache-ul de pe disc: SKU => disponibil (cele știute) și SKU => ['necunoscut_la' => moment]
     * (cele necunoscute în ERP). Tot fișierul expiră după 60 de secunde de la ultima scriere.
     *
     * @return array{0: array<string, float>, 1: array<string, int>}
     */
    private static function readCache(): array
    {
        $file = self::cacheFile();
        if (!is_file($file)) {
            return [[], []];
        }
        if (time() - (int) @filemtime($file) > self::TTL_SECONDS) {
            return [[], []];
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return [[], []];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [[], []];
        }

        $stiute = [];
        $necunoscute = [];
        foreach ($decoded as $sku => $valoare) {
            $cheie = strtoupper((string) $sku);
            if (is_array($valoare)) {
                if (isset($valoare['necunoscut_la']) && is_numeric($valoare['necunoscut_la'])) {
                    $necunoscute[$cheie] = (int) $valoare['necunoscut_la'];
                }
                continue;
            }
            $stiute[$cheie] = (float) $valoare;
        }
        return [$stiute, $necunoscute];
    }

    /**
     * @param array<string, float> $data
     * @param array<string, int> $necunoscute
     */
    private static function writeCache(array $data, array $necunoscute): void
    {
        $file = self::cacheFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $continut = $data;
        foreach ($necunoscute as $sku => $moment) {
            $continut[$sku] = ['necunoscut_la' => $moment];
        }
        $json = json_encode($continut);
        if ($json !== false) {
            @file_put_contents($file, $json, LOCK_EX);
        }
    }

    /**
     * ERP-ul a picat de curând (în ultimele 60 de secunde)? Atunci nu-l mai întrebăm: un ERP care nu
     * răspunde ar adăuga altfel timeout-ul întreg la FIECARE cerere, pentru toți vizitatorii deodată.
     */
    private static function erpEsuatRecent(): bool
    {
        $file = self::fisierEsec();
        if (!is_file($file)) {
            return false;
        }
        if (time() - (int) @filemtime($file) > self::TTL_SECONDS) {
            @unlink($file);
            return false;
        }
        return true;
    }

    private static function marcheazaEsec(): void
    {
        $file = self::fisierEsec();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($file, (string) time(), LOCK_EX);
    }

    private static function stergeMarcajEsec(): void
    {
        $file = self::fisierEsec();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private static function fisierEsec(): string
    {
        return Paths::stocare('cache/erp-stock-esec');
    }

    private static function cacheFile(): string
    {
        return Paths::stocare('cache/erp-stock.json');
    }
}
