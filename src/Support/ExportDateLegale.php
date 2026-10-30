<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Exportul datelor cu păstrare legală ale unui plugin oprit (WP-26; decizia OD-51 = b, G-m11). Un plugin oprit nu mai
 * arată nimic în admin, dar rândurile lui rămân în bază; când clientul le cere (de exemplu comenzile, pentru contabilitate),
 * ANDAXI rulează `scripts/exporta-date-legale.php --ce=…` pe server. Scriptul scrie un CSV în
 * `storage/export/` (drepturi 0600) și dă un link `/_andaxi/export/<token>`, bun o singură dată, 24 de ore. În admin
 * nu apare nimic, deci clientul nu vede nimic despre pluginurile pe care nu le are.
 *
 * Pe disc nu stă tokenul, doar hash-ul lui (numele fișierelor). Fișierul se șterge după descărcare; unul expirat se șterge
 * la următoarea rulare a scriptului sau la următoarea cerere spre `/_andaxi/export/…` (oricare token).
 */
final class ExportDateLegale
{
    /** Ce se poate exporta: tabelul și coloana după care se aplică `--din`. */
    public const TIPURI = [
        'comenzi' => ['tabel' => 'orders', 'data' => 'created_at', 'plugin' => 'magazin'],
        'acorduri_gdpr' => ['tabel' => 'gdpr_agreements', 'data' => 'created_at', 'plugin' => 'acorduri_gdpr'],
        'donatii' => ['tabel' => 'donations', 'data' => 'created_at', 'plugin' => 'donatii'],
    ];

    /** Cât e bun linkul (secunde). */
    public const VALABIL = 86400;

    /** Coloanele care nu ies niciodată în export (secretele, dacă vreun tabel le-ar avea). */
    private const COLOANE_SECRETE = '/(token|password|parola|secret)/i';

    public static function dosar(): string
    {
        return Paths::stocareSite('export');
    }

    /**
     * Scrie exportul și întoarce numărul de rânduri, tokenul și calea fișierului. Refuză (RuntimeException, cu mesajul de
     * arătat) un tip necunoscut, o dată greșită sau un tabel care nu există pe acest site.
     *
     * @return array{randuri: int, token: string, fisier: string, expira: int}
     */
    public static function creeaza(PDO $db, string $ce, ?string $din = null): array
    {
        if (!isset(self::TIPURI[$ce])) {
            throw new RuntimeException('--ce trebuie să fie unul din: ' . implode(', ', array_keys(self::TIPURI)));
        }
        if ($din !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $din) !== 1 || !checkdate((int) substr($din, 5, 2), (int) substr($din, 8, 2), (int) substr($din, 0, 4)))) {
            throw new RuntimeException('--din trebuie să fie o dată AAAA-LL-ZZ');
        }
        $tip = self::TIPURI[$ce];
        $coloane = self::coloane($db, $tip['tabel']);
        if ($coloane === []) {
            throw new RuntimeException('tabelul ' . $tip['tabel'] . ' nu există pe acest site (nimic de exportat)');
        }
        $exportate = array_values(array_filter($coloane, static fn (string $c): bool => preg_match(self::COLOANE_SECRETE, $c) !== 1));
        $faraData = $din !== null && !in_array($tip['data'], $coloane, true);
        if ($faraData) {
            throw new RuntimeException('tabelul ' . $tip['tabel'] . ' nu are coloana ' . $tip['data'] . ' (--din nu se poate aplica)');
        }

        self::curataExpirate();
        $token = bin2hex(random_bytes(32));
        $cheie = hash('sha256', $token);
        $dosar = self::dosar();
        if (!is_dir($dosar) || !is_writable($dosar)) {
            throw new RuntimeException('dosarul exportului nu se poate scrie: ' . $dosar);
        }
        $fisier = $dosar . '/' . $cheie . '.csv';
        $vechi = umask(0077);
        try {
            $f = fopen($fisier, 'xb');
        } finally {
            umask($vechi);
        }
        if ($f === false) {
            throw new RuntimeException('fișierul exportului nu se poate crea');
        }
        @chmod($fisier, 0600);
        $randuri = 0;
        try {
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, $exportate, ',', '"', '');
            $sql = 'SELECT ' . implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $exportate))
                . ' FROM `' . $tip['tabel'] . '`'
                . ($din !== null ? ' WHERE `' . $tip['data'] . '` >= :din' : '')
                . (in_array('id', $coloane, true) ? ' ORDER BY `id`' : '');
            $stmt = $db->prepare($sql);
            $stmt->execute($din !== null ? ['din' => $din . ' 00:00:00'] : []);
            while (($rand = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                fputcsv($f, array_map([self::class, 'celula'], array_values($rand)), ',', '"', '');
                $randuri++;
            }
        } catch (Throwable $e) {
            fclose($f);
            @unlink($fisier);
            throw new RuntimeException('exportul nu s-a putut scrie: ' . $e->getMessage());
        }
        fclose($f);
        $expira = time() + self::VALABIL;
        $meta = ['ce' => $ce, 'randuri' => $randuri, 'expira' => $expira,
            'nume' => $ce . '-' . date('Y-m-d') . ($din !== null ? '-din-' . $din : '') . '.csv'];
        $vechi = umask(0077);
        try {
            $scris = file_put_contents($dosar . '/' . $cheie . '.json', json_encode($meta, JSON_UNESCAPED_UNICODE), LOCK_EX);
        } finally {
            umask($vechi);
        }
        if ($scris === false) {
            @unlink($fisier);
            throw new RuntimeException('exportul nu s-a putut înregistra');
        }
        @chmod($dosar . '/' . $cheie . '.json', 0600);
        return ['randuri' => $randuri, 'token' => $token, 'fisier' => $fisier, 'expira' => $expira];
    }

    /**
     * `GET /_andaxi/export/{token}`: fișierul, o singură dată (apoi se șterge); altfel 404. Mai întâi se șterg exporturile
     * expirate.
     */
    public static function serveste(string $token): void
    {
        self::curataExpirate();
        $gasit = null;
        if (preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
            $cheie = hash('sha256', $token);
            $dosar = self::dosar();
            $meta = $dosar . '/' . $cheie . '.json';
            $fisier = $dosar . '/' . $cheie . '.csv';
            // Folosirea o singură dată: cererea care redenumește prima fișierul de descriere îl primește; a doua nu-l mai găsește.
            $luat = $meta . '.folosit-' . bin2hex(random_bytes(4));
            if (is_file($meta) && @rename($meta, $luat)) {
                $date = json_decode((string) @file_get_contents($luat), true);
                @unlink($luat);
                if (is_array($date) && (int) ($date['expira'] ?? 0) >= time() && is_file($fisier)) {
                    $gasit = ['fisier' => $fisier, 'nume' => (string) ($date['nume'] ?? 'export.csv')];
                } else {
                    @unlink($fisier);
                }
            }
        }
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        if ($gasit === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Nu există.\n";
            return;
        }
        $nume = preg_replace('/[^A-Za-z0-9._-]/', '', $gasit['nume']) ?: 'export.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nume . '"');
        header('Content-Length: ' . (string) filesize($gasit['fisier']));
        readfile($gasit['fisier']);
        @unlink($gasit['fisier']);
    }

    /** Șterge exporturile expirate (fișierul și descrierea lui) și resturile fără pereche. Întoarce câte a șters. */
    public static function curataExpirate(): int
    {
        $dosar = self::dosar();
        $sterse = 0;
        foreach (glob($dosar . '/*.json') ?: [] as $meta) {
            $date = json_decode((string) @file_get_contents($meta), true);
            if (!is_array($date) || (int) ($date['expira'] ?? 0) < time()) {
                @unlink(substr($meta, 0, -5) . '.csv');
                @unlink($meta);
                $sterse++;
            }
        }
        // Un CSV fără descriere (descărcare întreruptă, o descriere ștearsă de mână) mai vechi decât valabilitatea.
        foreach (glob($dosar . '/*.csv') ?: [] as $csv) {
            if (!is_file(substr($csv, 0, -4) . '.json') && (int) @filemtime($csv) < time() - self::VALABIL) {
                @unlink($csv);
                $sterse++;
            }
        }
        return $sterse;
    }

    /** @return list<string> coloanele tabelului, în ordinea lor; gol dacă tabelul lipsește */
    private static function coloane(PDO $db, string $tabel): array
    {
        $stmt = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
            ORDER BY ORDINAL_POSITION');
        $stmt->execute(['t' => $tabel]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** O celulă: un text care începe ca o formulă (=, +, -, @) primește un apostrof în față, ca foaia de calcul să nu-l ruleze. */
    private static function celula(mixed $valoare): string
    {
        $text = $valoare === null ? '' : (string) $valoare;
        if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text) === 1 && !is_numeric($text)) {
            return "'" . $text;
        }
        return $text;
    }
}
