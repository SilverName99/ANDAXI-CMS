<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Rapoartele CSP ale cadrului capturat (adoptarea WordPress, pasul 9a; INSTRUCTIUNI-BUILDER-C2 §9a, lecția CB-05): adresa din
 * `report-uri` a politicii site-ului (CspSite::RAPORT). Interceptată în `public/index.php` înaintea sesiunii
 * (SesiuneLaNevoie::decide), ca Statistici::intercepteaza, fiindcă altfel un POST ar porni sesiunea; răspunde 204 și nimic altceva.
 *
 *  - doar POST, doar pe cadrul capturat (`tema.json` → `cadru` = `capturat`; altfel adresa nu e a nimănui, ca înainte);
 *  - doar `application/csp-report` (forma veche, un raport) și `application/reports+json` (Reporting API, o listă: cel mult
 *    MAX_RAPOARTE rapoarte `csp-violation`), corpul cel mult MAX_CORP octeți (altfel 415, 413, 400);
 *  - bugetul pe rețeaua vizitatorului (IPv6 /64, IPv4 /32), fără s-o țină minte (recenzia 2, R2-3; recenzia 3, R2-N2): o
 *    găleată pe zi, numele ei e un HMAC al rețelei cu o sare a zilei (din APP_KEY sau dintr-un secret aleator scris o dată în
 *    `storage/cache/raport-csp/secret`), într-un singur fișier al zilei (`<zi>.json`); fișierele zilelor trecute se șterg. Nicio adresă IP și niciun agent nu se scriu nicăieri (nici în
 *    register_submit_logs). Peste BUGET pe zi: 429;
 *  - un plafon al site-ului: peste MAX_PE_ZI rapoarte pe zi, 204 fără nimic scris;
 *  - nimic despre vizitator: nici IP-ul, nici agentul, nici cookie-ul; din raport rămân cinci câmpuri, fiecare tăiat la 200 de
 *    caractere și fără query (o adresă poate avea date personale în query): calea documentului, directiva, adresa blocată
 *    (schema, gazda și calea, sau cuvântul `inline`/`eval`/…), fișierul sursă, linia;
 *  - numărători pe zi (`rapoarte_csp`, nucleu/0071): un rând pe zi și pe cele cinci câmpuri, cel mult MAX_RANDURI_ZI rânduri pe
 *    zi (restul doar se numără pe rândul `altele`), păstrate PASTREAZA_ZILE zile.
 *
 * Le arată Acasă → sănătatea (textul escapat de vedere, ca orice bucată) și `diagnostic.php --capturat`; niciodată ca HTML.
 */
final class RaportCsp
{
    public const MAX_CORP = 8192;

    public const MAX_RAPOARTE = 10;

    public const MAX_CAMP = 200;

    public const MAX_RANDURI_ZI = 200;

    public const PASTREAZA_ZILE = 30;

    /** Rapoartele pe vizitator (găleata zilei). */
    public const BUGET = 120;

    /** Rapoartele pe zi ale întregului site; peste: 204, nimic scris. */
    public const MAX_PE_ZI = 2000;

    public const TIPURI = ['application/csp-report', 'application/reports+json'];

    /** Răspunde rapoartelor CSP, dacă cererea e una. Întoarce dacă a răspuns. */
    public static function intercepteaza(?PDO $db): bool
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST'
            || (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '') !== CspSite::RAPORT
            || Tema::cadru() !== 'capturat') {
            return false;
        }
        try {
            $corp = (string) @file_get_contents('php://input', false, null, 0, self::MAX_CORP + 1);
            $cod = self::primeste($db, (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''), $corp);
        } catch (Throwable) {
            $cod = 500;
        }
        if (!headers_sent()) {
            header_remove('Set-Cookie');
            http_response_code($cod);
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex');
        }
        return true;
    }

    /**
     * Un corp de raport. Întoarce codul HTTP: 204 (primit, sau numărat pe `altele`), 400 (corp greșit), 413 (prea mare), 415 (alt
     * tip), 429 (prea multe), 503 (fără bază sau fără tabel).
     */
    public static function primeste(?PDO $db, string $tip, string $corp, ?int $acum = null): int
    {
        $tip = strtolower(trim(explode(';', $tip, 2)[0]));
        if (!in_array($tip, self::TIPURI, true)) {
            return 415;
        }
        if (strlen($corp) > self::MAX_CORP) {
            return 413;
        }
        $rapoarte = self::citeste($tip, $corp);
        if ($rapoarte === null) {
            return 400;
        }
        if (!$db instanceof PDO) {
            return 503;
        }
        $acum ??= time();
        // Întâi plafonul zilei (nimic scris peste el, nici găleata), apoi găleata rețelei (recenzia 3, R2-N2).
        try {
            $q = $db->prepare('SELECT COALESCE(SUM(numar), 0) FROM rapoarte_csp WHERE zi = ?');
            $q->execute([date('Y-m-d', $acum)]);
            if ((int) $q->fetchColumn() >= self::MAX_PE_ZI) {
                return 204; // plafonul zilei: primit, nimic scris
            }
        } catch (Throwable) {
            return 503;
        }
        if (!self::inGaleata(IpClient::adresa(), $acum)) {
            return 429;
        }
        try {
            foreach ($rapoarte as $r) {
                self::numara($db, $r, $acum ?? time());
            }
        } catch (Throwable) {
            return 503;
        }
        return 204;
    }

    /**
     * Rapoartele dintr-un corp, cu cele cinci câmpuri curățate; null pe un corp greșit (sau fără niciun raport CSP).
     *
     * @return list<array{document: string, directiva: string, blocat: string, sursa: string, linie: int}>|null
     */
    public static function citeste(string $tip, string $corp): ?array
    {
        try {
            $j = json_decode($corp, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $brute = [];
        if ($tip === 'application/csp-report') {
            if (!is_array($j) || !is_array($j['csp-report'] ?? null)) {
                return null;
            }
            $c = $j['csp-report'];
            $brute[] = [$c['document-uri'] ?? '', $c['effective-directive'] ?? $c['violated-directive'] ?? '', $c['blocked-uri'] ?? '',
                $c['source-file'] ?? '', $c['line-number'] ?? 0];
        } else {
            if (!is_array($j) || !array_is_list($j)) {
                return null;
            }
            foreach (array_slice($j, 0, self::MAX_RAPOARTE) as $x) {
                if (!is_array($x) || ($x['type'] ?? '') !== 'csp-violation' || !is_array($x['body'] ?? null)) {
                    continue;
                }
                $b = $x['body'];
                $brute[] = [$b['documentURL'] ?? '', $b['effectiveDirective'] ?? '', $b['blockedURL'] ?? '', $b['sourceFile'] ?? '', $b['lineNumber'] ?? 0];
            }
        }
        $rez = [];
        foreach ($brute as [$doc, $dir, $blocat, $sursa, $linie]) {
            $rez[] = [
                'document' => self::cale(is_string($doc) ? $doc : ''),
                'directiva' => self::taie(is_string($dir) && preg_match('/^[a-z-]{1,40}$/', $dir) === 1 ? $dir : ''),
                'blocat' => self::adresa(is_string($blocat) ? $blocat : ''),
                'sursa' => self::adresa(is_string($sursa) ? $sursa : ''),
                'linie' => is_int($linie) && $linie > 0 && $linie < 10_000_000 ? $linie : 0,
            ];
        }
        return $rez === [] ? null : $rez;
    }

    /** Rețeaua unei adrese pentru găleată (recenzia 3, R2-N2): IPv6 pe /64, IPv4 întreagă (/32); '' dacă nu e o adresă. */
    public static function retea(string $adresa): string
    {
        $bin = @inet_pton(trim($adresa));
        if ($bin === false) {
            return '';
        }
        return strlen($bin) === 16 ? bin2hex(substr($bin, 0, 8)) . '/64' : bin2hex($bin) . '/32';
    }

    /**
     * Secretul cu care se numesc gălețile: APP_KEY, sau unul aleator scris o dată într-un fișier de date ignorat de git
     * (`storage/cache/raport-csp/secret`); niciodată o cheie fixă (recenzia 3). Null: fără secret, fără găleți.
     */
    private static function secret(string $dosar): ?string
    {
        $cheie = (string) Env::get('APP_KEY', '');
        if ($cheie !== '') {
            return $cheie;
        }
        $f = $dosar . '/secret';
        $s = is_file($f) ? trim((string) @file_get_contents($f)) : '';
        if (strlen($s) >= 32) {
            return $s;
        }
        if (!is_dir($dosar) && !@mkdir($dosar, 0770, true) && !is_dir($dosar)) {
            return null;
        }
        $s = bin2hex(random_bytes(32));
        $h = @fopen($f, 'x');
        if ($h === false) {
            $s = trim((string) @file_get_contents($f)); // altă cerere l-a scris între timp
            return strlen($s) >= 32 ? $s : null;
        }
        fwrite($h, $s);
        fclose($h);
        @chmod($f, 0600);
        return $s;
    }

    /**
     * Încă e loc în găleata rețelei vizitatorului pentru ziua de azi? Dacă da, o numără. Un singur fișier pe zi (`<zi>.json`:
     * găleata → numărul), sub blocare; numele găleții e un HMAC al rețelei (IPv6 /64, IPv4 /32) cu o sare a zilei derivată din
     * secret. Fișierele zilelor trecute se șterg (câteva, nu câte unul pe vizitator). Fără adresă, dosar sau secret: da.
     */
    public static function inGaleata(string $adresa, int $acum, ?string $dosar = null): bool
    {
        $retea = self::retea($adresa);
        if ($retea === '') {
            return true;
        }
        $zi = date('Y-m-d', $acum);
        $dosar ??= Paths::stocare('cache/raport-csp');
        try {
            $secret = self::secret($dosar);
            if ($secret === null) {
                return true;
            }
            $galeata = substr(hash_hmac('sha256', $retea, hash_hmac('sha256', 'raport-csp:' . $zi, $secret)), 0, 32);
            foreach (glob($dosar . '/*.json') ?: [] as $vechi) {
                if (basename($vechi) !== $zi . '.json' && preg_match('/^\d{4}-\d{2}-\d{2}\.json$/', basename($vechi)) === 1) {
                    @unlink($vechi);
                }
            }
            $h = @fopen($dosar . '/' . $zi . '.json', 'c+');
            if ($h === false) {
                return true;
            }
            flock($h, LOCK_EX);
            $date = json_decode((string) stream_get_contents($h), true);
            $date = is_array($date) ? $date : [];
            $n = (int) ($date[$galeata] ?? 0);
            $loc = $n < self::BUGET;
            if ($loc) {
                $date[$galeata] = $n + 1;
                ftruncate($h, 0);
                rewind($h);
                fwrite($h, (string) json_encode($date));
            }
            flock($h, LOCK_UN);
            fclose($h);
            return $loc;
        } catch (Throwable) {
            return true;
        }
    }

    /** Calea unei adrese (fără schemă, gazdă, query, fragment). */
    private static function cale(string $url): string
    {
        $cale = parse_url($url, PHP_URL_PATH);
        return self::taie(is_string($cale) ? $cale : '');
    }

    /** Schema, gazda și calea unei adrese (fără utilizator, query, fragment), sau un cuvânt al CSP-ului (`inline`, `eval`, …). */
    private static function adresa(string $url): string
    {
        if (preg_match('/^[a-z-]{1,20}$/', $url) === 1) {
            return $url;
        }
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'])) {
            return '';
        }
        $s = strtolower($p['scheme']);
        if (!in_array($s, ['http', 'https', 'ws', 'wss'], true)) {
            // data:, blob:, … : doar schema (restul e conținutul).
            return self::taie(preg_match('/^[a-z][a-z0-9+.-]{0,19}$/', $s) === 1 ? $s : '');
        }
        return self::taie($s . '://' . strtolower((string) ($p['host'] ?? '')) . (isset($p['port']) ? ':' . (int) $p['port'] : '') . (string) ($p['path'] ?? ''));
    }

    private static function taie(string $s): string
    {
        $s = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', mb_scrub($s, 'UTF-8'));
        return mb_substr($s, 0, self::MAX_CAMP);
    }

    /** @param array{document: string, directiva: string, blocat: string, sursa: string, linie: int} $r */
    private static function numara(PDO $db, array $r, int $acum): void
    {
        $zi = date('Y-m-d', $acum);
        $moment = date('Y-m-d H:i:s', $acum);
        $cheie = hash('sha256', implode("\0", [$r['document'], $r['directiva'], $r['blocat'], $r['sursa'], (string) $r['linie']]));
        $q = $db->prepare('SELECT COUNT(*) FROM rapoarte_csp WHERE zi = ?');
        $q->execute([$zi]);
        $exista = $db->prepare('SELECT 1 FROM rapoarte_csp WHERE zi = ? AND cheie = ?');
        $exista->execute([$zi, $cheie]);
        if ($exista->fetchColumn() === false && (int) $q->fetchColumn() >= self::MAX_RANDURI_ZI) {
            $r = ['document' => '', 'directiva' => 'altele', 'blocat' => '', 'sursa' => '', 'linie' => 0];
            $cheie = hash('sha256', 'altele');
        }
        $db->prepare('INSERT INTO rapoarte_csp (zi, cheie, document, directiva, blocat, sursa, linie, numar, ultimul_la)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?) ON DUPLICATE KEY UPDATE numar = numar + 1, ultimul_la = VALUES(ultimul_la)')
            ->execute([$zi, $cheie, $r['document'], $r['directiva'], $r['blocat'], $r['sursa'], $r['linie'], $moment]);
        $db->prepare('DELETE FROM rapoarte_csp WHERE zi < ?')->execute([date('Y-m-d', $acum - self::PASTREAZA_ZILE * 86400)]);
    }

    /**
     * Cele mai dese rapoarte din ultimele `$zile` zile (pentru diagnostic și sănătate).
     *
     * @return list<array{document: string, directiva: string, blocat: string, sursa: string, linie: int, numar: int}>
     */
    public static function recente(PDO $db, int $zile = 7, int $cate = 10, ?int $acum = null): array
    {
        try {
            $q = $db->prepare('SELECT document, directiva, blocat, sursa, linie, SUM(numar) AS numar FROM rapoarte_csp WHERE zi >= ?
                GROUP BY document, directiva, blocat, sursa, linie ORDER BY numar DESC, directiva, blocat LIMIT ' . max(1, min(100, $cate)));
            $q->execute([date('Y-m-d', ($acum ?? time()) - max(0, $zile - 1) * 86400)]);
            return array_map(static fn (array $r): array => [
                'document' => (string) $r['document'], 'directiva' => (string) $r['directiva'], 'blocat' => (string) $r['blocat'],
                'sursa' => (string) $r['sursa'], 'linie' => (int) $r['linie'], 'numar' => (int) $r['numar'],
            ], $q->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Bucata de sănătate de pe Acasă (doar pe cadrul capturat): câte rapoarte în ultimele 7 zile și cel mai des. Text simplu: vederea
     * îl escapează, ca pe orice bucată.
     *
     * @return list<array{text: string, ton: string, href: string}>
     */
    public static function sanatate(PDO $db): array
    {
        if (Tema::cadru() !== 'capturat') {
            return [];
        }
        $recente = self::recente($db, 7, 100);
        $total = array_sum(array_column($recente, 'numar'));
        if ($total === 0) {
            return [['text' => 'Politica CSP: niciun raport în ultimele 7 zile', 'ton' => 'ok', 'href' => '']];
        }
        $p = $recente[0];
        return [['text' => 'Politica CSP: ' . $total . ($total === 1 ? ' raport' : ' rapoarte') . ' în ultimele 7 zile; cel mai des: '
            . $p['directiva'] . ' → ' . ($p['blocat'] !== '' ? $p['blocat'] : '(fără adresă)') . ($p['document'] !== '' ? ' pe ' . $p['document'] : '')
            . ' (' . $p['numar'] . ')', 'ton' => 'atentie', 'href' => '']];
    }
}
