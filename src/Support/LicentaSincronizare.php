<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Preluarea automată a licenței de la CRM (docs/PLUGINURI.md, „Preluarea automată”).
 *
 * Cronul `scripts/licenta-sincronizare.php` (la 5 minute, prin `bin/platforma`) și butonul „Verifică acum” din Admin →
 * Abonamentul tău cer `GET LICENTA_URL` cu `Authorization: Bearer LICENTA_CHEIE` (ambele din `.env`-ul site-ului). CRM-ul
 * răspunde cu licența curentă a site-ului, exact fișierul semnat. O licență primită se pune pe site **doar** dacă:
 *
 *  - semnătura Ed25519 se verifică cu cheia publică pe care site-ul o are deja (`LICENTA_CHEIE_PUBLICA`, prin
 *    `Licenta::verifica`, aceeași verificare ca pentru un fișier urcat de mână) și e pentru acest site (`LICENTA_SITE`,
 *    același `site` ca licența de acum);
 *  - `versiune` e cel puțin cea de acum (licența folosită sau, dacă nu se verifică niciuna, ultima aplicată): nicio
 *    întoarcere la o listă veche, nicio reluare a unei licențe vechi; aceeași versiune doar cu același conținut.
 *
 * Licența nu are dată de expirare (formatul din `Licenta`): e bună până vine una mai nouă, exact ca un fișier urcat de mână.
 *
 * Apoi se scrie cu `Licenta::instaleaza()` (fișier temporar + rename, 0660), adică exact ca un `licenta.json` urcat de mână,
 * iar stările pluginurilor se aplică pe loc (`Plugins`: `stare-aplicata.json`, jurnalul, golirea memoriei paginilor). Pașii de
 * activare ai unui plugin pornit (`config/activari.php`, de exemplu `erp_trimite_din`) rulează la prima cerere web de după,
 * ca la un fișier urcat de mână (`Activari::laCerere`); schema nu depinde de pluginuri, deci nu e nicio migrare de rulat.
 *
 * La orice eșec (CRM-ul căzut, un răspuns respins, disc fără drept de scriere) licența de acum rămâne, eșecul se scrie în
 * `storage/logs/licenta-sincronizare.log` (o dată pe oră pentru același motiv) și în `sincronizare.json` (dosarul licenței),
 * pe care îl arată adminul. Site-ul nu se strică niciodată din cauza preluării. Cheia nu se scrie nicăieri: nici în jurnal,
 * nici în fișierul de stare, nici în mesaje.
 *
 * Doar HTTPS, cu certificatul verificat și fără redirecționări; singura excepție e o adresă de pe aceeași mașină
 * (`http://127.0.0.1…`, `http://localhost…`), care nu trece prin rețea (testele, cu un CRM simulat).
 */
final class LicentaSincronizare
{
    public const FISIER_STARE = 'sincronizare.json';
    public const FISIER_LACAT = '.sincronizare.lock';
    public const FISIER_JURNAL = 'licenta-sincronizare.log';
    /** Un răspuns mai mare nu e o licență. */
    public const MARIME_MAXIMA = 262144;
    /** Același motiv de eșec se scrie în jurnal cel mult o dată pe oră. */
    private const JURNAL_REPETARE_SECUNDE = 3600;

    /** Rezultatele: `ok` (licență nouă pusă), `la_zi` (CRM-ul are aceeași), `respinsa`, `eroare`, `neconfigurat`, `ocupat`. */
    public const OK = 'ok';
    public const LA_ZI = 'la_zi';
    public const RESPINSA = 'respinsa';
    public const EROARE = 'eroare';
    public const NECONFIGURAT = 'neconfigurat';
    public const OCUPAT = 'ocupat';

    /** @var array{url: string, cheie: string}|null */
    private static ?array $config = null;
    private static int $timeout = 15;
    private static ?string $jurnal = null;

    /**
     * Doar pentru teste: altă adresă și cheie decât cele din `.env` (`url`, `cheie`), alt jurnal (`jurnal`: calea
     * fișierului) și alt termen. `null` = înapoi la `.env`.
     */
    public static function configureaza(?array $config, int $timeout = 15): void
    {
        self::$config = $config === null ? null : ['url' => trim((string) ($config['url'] ?? '')), 'cheie' => trim((string) ($config['cheie'] ?? ''))];
        self::$jurnal = $config === null ? null : (isset($config['jurnal']) ? (string) $config['jurnal'] : null);
        self::$timeout = max(1, $timeout);
    }

    /** E configurată preluarea automată (adresa și cheia în `.env`)? */
    public static function configurata(): bool
    {
        $c = self::config();
        return $c['url'] !== '' && $c['cheie'] !== '';
    }

    /**
     * Cere licența de la CRM și o pune pe site dacă trece toate verificările (capul clasei). Nu aruncă niciodată.
     *
     * @param string $sursa `cron` sau `admin` (în jurnal și în fișierul de stare)
     * @return array{rezultat: string, mesaj: string, versiune: int, versiune_curenta: int}
     */
    public static function ruleaza(string $sursa = 'cron'): array
    {
        try {
            return self::ruleazaCuLacat($sursa);
        } catch (Throwable $e) {
            // Orice altceva (o eroare a codului, disc plin): licența de acum rămâne.
            $rezultat = self::rezultat(self::EROARE, 'eroare neașteptată: ' . self::faraCheie($e->getMessage()), 0, self::versiuneCurenta());
            self::noteaza($sursa, $rezultat);
            return $rezultat;
        }
    }

    /**
     * Ce s-a întâmplat la ultima preluare (`sincronizare.json`), pentru admin; null dacă nu a rulat niciodată.
     *
     * @return array{la: string, sursa: string, rezultat: string, mesaj: string, versiune: int, versiune_curenta: int,
     *               ultima_reusita: ?string}|null
     */
    public static function ultimaStare(): ?array
    {
        $fisier = Licenta::dosar() . '/' . self::FISIER_STARE;
        $date = is_file($fisier) ? json_decode((string) @file_get_contents($fisier), true) : null;
        if (!is_array($date) || !is_string($date['rezultat'] ?? null)) {
            return null;
        }
        return [
            'la' => (string) ($date['la'] ?? ''),
            'sursa' => (string) ($date['sursa'] ?? ''),
            'rezultat' => (string) $date['rezultat'],
            'mesaj' => (string) ($date['mesaj'] ?? ''),
            'versiune' => (int) ($date['versiune'] ?? 0),
            'versiune_curenta' => (int) ($date['versiune_curenta'] ?? 0),
            'ultima_reusita' => is_string($date['ultima_reusita'] ?? null) ? $date['ultima_reusita'] : null,
        ];
    }

    /**
     * Verificările unei licențe primite, fără rețea și fără să scrie ceva (și pentru teste).
     *
     * @return array{ok: bool, mesaj: string, versiune: int, licenta: ?array}
     */
    public static function verificaPrimita(string $json): array
    {
        $esec = static fn (string $mesaj, int $versiune = 0): array => ['ok' => false, 'mesaj' => $mesaj, 'versiune' => $versiune, 'licenta' => null];
        $verificare = Licenta::verifica($json);
        if (!$verificare['ok']) {
            return $esec('licența primită e respinsă: ' . $verificare['eroare']);
        }
        $licenta = (array) $verificare['licenta'];
        $versiune = (int) $licenta['versiune'];

        $curenta = Licenta::incarca()['licenta'];
        if (is_array($curenta) && !hash_equals((string) ($curenta['site'] ?? ''), (string) $licenta['site'])) {
            return $esec('licența primită e pentru site-ul „' . $licenta['site'] . '”, cea de acum pentru „' . (string) ($curenta['site'] ?? '') . '”.', $versiune);
        }
        $minima = self::versiuneCurenta();
        if ($versiune < $minima) {
            return $esec('licența primită are versiunea ' . $versiune . ', mai veche decât cea de pe site (' . $minima . ').', $versiune);
        }
        if (is_array($curenta) && $versiune === (int) $curenta['versiune'] && self::continut($curenta) !== self::continut($licenta)) {
            return $esec('licența primită are aceeași versiune (' . $versiune . ') ca cea de pe site, dar alt conținut.', $versiune);
        }
        return ['ok' => true, 'mesaj' => '', 'versiune' => $versiune, 'licenta' => $licenta];
    }

    // ---------------------------------------------------------------- intern

    private static function ruleazaCuLacat(string $sursa): array
    {
        $curenta = self::versiuneCurenta();
        $config = self::config();
        $problema = self::problemaConfig($config);
        if ($problema !== null) {
            $rezultat = self::rezultat(self::NECONFIGURAT, $problema, 0, $curenta);
            self::noteaza($sursa, $rezultat);
            return $rezultat;
        }

        $dosar = Licenta::dosar();
        if (!is_dir($dosar)) {
            @mkdir($dosar, 0770, true);
        }
        // Cronul și butonul din admin nu lucrează deodată (al doilea iese: „ocupat”).
        $lacat = @fopen($dosar . '/' . self::FISIER_LACAT, 'c');
        if ($lacat !== false) {
            @chmod($dosar . '/' . self::FISIER_LACAT, 0660);
        }
        if ($lacat === false) {
            $rezultat = self::rezultat(self::EROARE, 'nu pot scrie în ' . $dosar . ' (drepturi de scriere?); licența de acum rămâne.', 0, $curenta);
            self::noteaza($sursa, $rezultat);
            return $rezultat;
        }
        try {
            if (!flock($lacat, LOCK_EX | LOCK_NB)) {
                return self::rezultat(self::OCUPAT, 'o altă preluare rulează chiar acum.', 0, $curenta);
            }
            $rezultat = self::preiaSiInstaleaza($config);
            self::noteaza($sursa, $rezultat);
            return $rezultat;
        } finally {
            @flock($lacat, LOCK_UN);
            @fclose($lacat);
        }
    }

    /** @param array{url: string, cheie: string} $config */
    private static function preiaSiInstaleaza(array $config): array
    {
        $curenta = self::versiuneCurenta();
        $raspuns = self::cere($config);
        if ($raspuns['eroare'] !== '') {
            return self::rezultat(self::EROARE, $raspuns['eroare'] . '; licența de acum rămâne.', 0, $curenta);
        }
        $verificare = self::verificaPrimita($raspuns['corp']);
        if (!$verificare['ok']) {
            return self::rezultat(self::RESPINSA, $verificare['mesaj'] . ' Licența de acum rămâne.', $verificare['versiune'], $curenta);
        }
        $versiune = $verificare['versiune'];
        $fisier = Licenta::dosar() . '/' . Licenta::FISIER;
        if (is_file($fisier) && @file_get_contents($fisier) === $raspuns['corp'] && Licenta::incarca()['sursa'] === Licenta::FISIER) {
            return self::rezultat(self::LA_ZI, 'licența e la zi (v' . $versiune . ').', $versiune, $curenta);
        }
        $instalare = Licenta::instaleaza($raspuns['corp']);
        if (!$instalare['ok']) {
            return self::rezultat(self::EROARE, 'licența v' . $versiune . ' nu a putut fi pusă: ' . $instalare['eroare'] . ' Licența de acum rămâne.', $versiune, $curenta);
        }
        // Ca o licență urcată de mână, la prima cerere: stările noi, stare-aplicata.json, jurnalul, memoria paginilor.
        Plugins::reseteaza();
        Plugins::incarca();
        $pornite = [];
        foreach (Plugins::stari() as $cheie => $stare) {
            if ($stare !== Plugins::OPRIT) {
                $pornite[] = $cheie . ($stare === Plugins::SE_OPRESTE ? ' (se oprește)' : '');
            }
        }
        return self::rezultat(self::OK, 'licența v' . $versiune . ' a fost pusă pe site (înainte: v' . $curenta . '); pluginuri: '
            . ($pornite === [] ? 'doar nucleul' : implode(', ', $pornite)) . '.', $versiune, $curenta);
    }

    /**
     * GET la CRM. Întoarce corpul sau un mesaj de eroare (fără cheie, fără antete).
     *
     * @param array{url: string, cheie: string} $config
     * @return array{corp: string, eroare: string}
     */
    private static function cere(array $config): array
    {
        if (!function_exists('curl_init')) {
            return ['corp' => '', 'eroare' => 'extensia PHP curl lipsește'];
        }
        $local = self::esteLocal($config['url']);
        $corp = '';
        $preaMare = false;
        $ch = curl_init($config['url']);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $config['cheie'],
                'Accept: application/json',
                'User-Agent: andaxi-cms/licenta-sincronizare',
            ],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => $local ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => min(5, self::$timeout),
            CURLOPT_TIMEOUT => self::$timeout,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $bucata) use (&$corp, &$preaMare): int {
                if (strlen($corp) + strlen($bucata) > self::MARIME_MAXIMA) {
                    $preaMare = true;
                    return 0; // oprește transferul
                }
                $corp .= $bucata;
                return strlen($bucata);
            },
        ]);
        $mers = curl_exec($ch);
        $cod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $eroare = curl_error($ch);
        curl_close($ch);

        $gazda = (string) parse_url($config['url'], PHP_URL_HOST);
        if ($preaMare) {
            return ['corp' => '', 'eroare' => 'CRM-ul (' . $gazda . ') a trimis mai mult de ' . self::MARIME_MAXIMA . ' de octeți'];
        }
        if ($mers === false) {
            return ['corp' => '', 'eroare' => 'CRM-ul (' . $gazda . ') nu răspunde: ' . self::faraCheie($eroare !== '' ? $eroare : 'fără răspuns')];
        }
        if ($cod !== 200) {
            $motiv = match ($cod) {
                401 => ' (cheia site-ului e greșită sau a fost schimbată în CRM: LICENTA_CHEIE)',
                404 => ' (CRM-ul nu are încă o licență emisă pentru site, sau adresa LICENTA_URL e greșită)',
                429 => ' (prea multe cereri; se reia singur)',
                default => '',
            };
            return ['corp' => '', 'eroare' => 'CRM-ul (' . $gazda . ') a răspuns cu HTTP ' . $cod . $motiv];
        }
        return ['corp' => $corp, 'eroare' => ''];
    }

    /** @return array{url: string, cheie: string} */
    private static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }
        $app = require __DIR__ . '/../../config/app.php';
        $licenta = (array) ($app['licenta'] ?? []);
        return ['url' => trim((string) ($licenta['url'] ?? '')), 'cheie' => trim((string) ($licenta['cheie'] ?? ''))];
    }

    /** @param array{url: string, cheie: string} $config */
    private static function problemaConfig(array $config): ?string
    {
        if ($config['url'] === '' && $config['cheie'] === '') {
            return 'preluarea automată nu e configurată (LICENTA_URL și LICENTA_CHEIE în .env).';
        }
        if ($config['url'] === '') {
            return 'lipsește LICENTA_URL din .env.';
        }
        if ($config['cheie'] === '') {
            return 'lipsește LICENTA_CHEIE din .env.';
        }
        $parti = parse_url($config['url']);
        if (!is_array($parti) || !isset($parti['scheme'], $parti['host']) || isset($parti['user']) || isset($parti['pass'])
            || isset($parti['fragment'])) {
            return 'LICENTA_URL nu e o adresă de forma https://crm.andaxi.ro/api/site/licenta.';
        }
        $schema = strtolower((string) $parti['scheme']);
        if ($schema !== 'https' && !($schema === 'http' && self::esteLocal($config['url']))) {
            return 'LICENTA_URL trebuie să fie https:// (cheia site-ului nu pleacă necriptată).';
        }
        if (preg_match('/^[\x21-\x7E]+$/', $config['cheie']) !== 1) {
            return 'LICENTA_CHEIE are spații sau caractere care nu pot fi într-o cheie.';
        }
        return null;
    }

    /** Adresă de pe aceeași mașină (nu trece prin rețea): doar ea poate fi http://. */
    private static function esteLocal(string $url): bool
    {
        $gazda = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        return in_array($gazda, ['127.0.0.1', 'localhost', '::1'], true);
    }

    /**
     * Versiunea sub care nu se coboară: licența folosită acum sau, dacă acum nu se verifică niciuna (de exemplu cheia
     * publică schimbată), ultima aplicată (`stare-aplicata.json`).
     */
    private static function versiuneCurenta(): int
    {
        $curenta = Licenta::incarca()['licenta'];
        $versiune = is_array($curenta) ? (int) ($curenta['versiune'] ?? 0) : 0;
        $fisier = Licenta::dosar() . '/' . Plugins::FISIER_STARE;
        $stare = is_file($fisier) ? json_decode((string) @file_get_contents($fisier), true) : null;
        if (is_array($stare) && is_array($stare['licenta'] ?? null)) {
            $versiune = max($versiune, (int) ($stare['licenta']['versiune'] ?? 0));
        }
        return $versiune;
    }

    /** Conținutul unei licențe verificate, comparabil (fără ordinea cheilor). */
    private static function continut(array $licenta): string
    {
        unset($licenta['semnatura']);
        return Licenta::jsonCanonic($licenta);
    }

    /** @return array{rezultat: string, mesaj: string, versiune: int, versiune_curenta: int} */
    private static function rezultat(string $rezultat, string $mesaj, int $versiune, int $curenta): array
    {
        return ['rezultat' => $rezultat, 'mesaj' => self::faraCheie($mesaj), 'versiune' => $versiune, 'versiune_curenta' => $curenta];
    }

    /** Cheia nu ajunge în niciun mesaj, chiar dacă ar apărea într-o eroare a bibliotecii. */
    private static function faraCheie(string $text): string
    {
        $cheie = self::config()['cheie'];
        return $cheie !== '' && strlen($cheie) >= 8 ? str_replace($cheie, '***', $text) : $text;
    }

    /** Fișierul de stare (pentru admin) și jurnalul (o schimbare, sau același eșec cel mult o dată pe oră). */
    private static function noteaza(string $sursa, array $rezultat): void
    {
        $vechi = self::ultimaStare();
        $fisierVechi = Licenta::dosar() . '/' . self::FISIER_STARE;
        $dateVechi = is_file($fisierVechi) ? json_decode((string) @file_get_contents($fisierVechi), true) : null;
        $jurnalizatLa = is_array($dateVechi) ? (int) ($dateVechi['jurnalizat_la'] ?? 0) : 0;
        $acum = time();
        $reusita = in_array($rezultat['rezultat'], [self::OK, self::LA_ZI], true);
        $deScris = $vechi === null || $vechi['rezultat'] !== $rezultat['rezultat'] || $vechi['mesaj'] !== $rezultat['mesaj']
            || $rezultat['rezultat'] === self::OK || (!$reusita && $acum - $jurnalizatLa >= self::JURNAL_REPETARE_SECUNDE);
        if ($deScris) {
            self::jurnal($sursa . ' ' . $rezultat['rezultat'] . ': ' . $rezultat['mesaj']);
            $jurnalizatLa = $acum;
        }
        $nou = [
            'la' => date('c', $acum),
            'sursa' => $sursa,
            'rezultat' => $rezultat['rezultat'],
            'mesaj' => $rezultat['mesaj'],
            'versiune' => $rezultat['versiune'],
            'versiune_curenta' => $rezultat['versiune_curenta'],
            'ultima_reusita' => $reusita ? date('c', $acum) : ($vechi['ultima_reusita'] ?? null),
            'jurnalizat_la' => $jurnalizatLa,
        ];
        $dosar = Licenta::dosar();
        if (is_dir($dosar)) {
            Licenta::scrieAtomic($dosar . '/' . self::FISIER_STARE, (string) json_encode($nou, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    private static function jurnal(string $mesaj): void
    {
        $fisier = self::$jurnal;
        if ($fisier === null) {
            $dosar = Paths::stocareSite('logs');
            if (!is_dir($dosar) || !is_writable($dosar)) {
                return;
            }
            $fisier = $dosar . '/' . self::FISIER_JURNAL;
        }
        @file_put_contents($fisier, date('c') . '  ' . self::faraCheie($mesaj) . "\n", FILE_APPEND | LOCK_EX);
    }
}
