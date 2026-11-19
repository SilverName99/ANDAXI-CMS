<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\Captura;

/**
 * Cererile capturii (pasul 4a; DECIZIE §2.3 #2, C2 §0.5): **doar GET**, fără browser, fără cookie-uri și fără autentificare,
 * cu un ritm politicos și un buget fix de cereri. Clasa nu are altă metodă de cerere decât get(): captura nu trimite niciodată
 * un formular și nu face niciun POST.
 *
 *  - **gazdele:** doar cele din lista dată (site-ul și, pentru fonturi, gazdele lor); o adresă spre altă gazdă e refuzată
 *    înainte de orice rețea. Trackerele (TRACKERE: Clarity, Google Analytics, Tag Manager, DoubleClick…) sunt refuzate
 *    oricum, chiar dacă cineva le-ar pune în listă (CB-19: nicio lovitură spre trackerele clientului din probe);
 *  - **ritmul:** cel puțin `$ritm` secunde între două cereri (implicit 1: „cel mult o cerere pe secundă”);
 *  - **bugetul:** fiecare încercare (și reluările) se numără; la buget atins, EroareCaptura('buget'), fără nicio cerere în plus;
 *  - **429 și 503 cu `Retry-After`:** reluare după `Retry-After` (secunde sau dată HTTP; altfel 10/30/60 s), plafonată la
 *    `$asteptareMaxima`, cel mult `$reluari` ori pe cerere; după `$limitari` astfel de răspunsuri la rând, captura se oprește
 *    (EroareCaptura('limitare')): gazda a cerut să o lăsăm în pace;
 *  - **jurnalul** (`$fisierJurnal`): fiecare încercare e scrisă în fișier, doar adăugând, cu fflush și fsync, înainte de trimitere
 *    (`trimisa`: ora de început în ms, metoda, adresa, UA-ul) și după (`raspuns`: statusul, octeții, durata, eroarea); rămâne și
 *    dacă procesul se oprește la jumătate;
 *  - **limitele:** conectarea (`$timeoutConectare`), toată cererea (`$timeout`) și corpul decodat (`$corpMaxim`, ca și discul);
 *  - **redirecționările nu se urmează:** un 301 se păstrează cu `Location` (e și el un răspuns de înghețat);
 *  - **corpul** se decodează după `Content-Encoding` (gzip, br, deflate), ca în browser; octeții decodați se păstrează exact.
 *
 * Șase UA reale (UA), câte două cereri pe fiecare pentru documentele HTML (Captura): comparația lor arată ce pune serverul
 * după UA și ce se schimbă la fiecare cerere (nonce-urile).
 */
final class Cereri
{
    /** Cele șase UA ale capturii (Chrome Windows, Chrome Mac, Safari iPhone, Chrome Android, Firefox, Safari Mac). */
    public const UA = [
        'chrome-win' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        'chrome-mac' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        'safari-iphone' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
        'chrome-android' => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36',
        'firefox' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0',
        'safari-mac' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
    ];

    /** Gazdele trackerelor, refuzate mereu (sufixe: și subdomeniile lor). */
    public const TRACKERE = ['clarity.ms', 'google-analytics.com', 'analytics.google.com', 'googletagmanager.com', 'doubleclick.net',
        'googleadservices.com', 'googlesyndication.com', 'facebook.net', 'facebook.com', 'hotjar.com', 'hotjar.io'];

    public const CORP_MAXIM = 64 * 1024 * 1024;

    /** Antetele care descriu octeții de pe fir sau legătura, nu corpul păstrat (decodat): nu se păstrează (Raspuns::anteteDePastrat). */
    public const ANTETE_FIR = ['content-encoding', 'content-length', 'transfer-encoding', 'connection', 'keep-alive'];

    private int $facute = 0;
    /** @var resource|null */
    private $jurnalDeschis = null;
    private int $limitariLaRand = 0;
    private float $ultima = 0.0;
    /** @var list<array<string, mixed>> */
    private array $jurnal = [];
    /** @var list<string> */
    private array $gazde;

    /**
     * @param list<string> $gazdePermise `gazdă` sau `gazdă:port`, cu litere mici
     */
    public function __construct(
        array $gazdePermise,
        private readonly int $buget,
        private readonly float $ritm = 1.0,
        private readonly int $reluari = 3,
        private readonly int $limitari = 6,
        private readonly int $asteptareMaxima = 600,
        private readonly ?string $fisierJurnal = null,
        private readonly int $timeout = 90,
        private readonly int $timeoutConectare = 20,
        private readonly int $corpMaxim = self::CORP_MAXIM,
    ) {
        if ($buget < 1) {
            throw new EroareCaptura('folosire', 'bugetul de cereri trebuie să fie cel puțin 1');
        }
        if ($ritm < 0) {
            throw new EroareCaptura('folosire', 'ritmul nu poate fi negativ');
        }
        if ($timeout < 1 || $timeoutConectare < 1 || $corpMaxim < 1) {
            throw new EroareCaptura('folosire', 'limitele cererilor (timeout, corpul maxim) trebuie să fie pozitive');
        }
        $this->gazde = array_values(array_unique(array_map(static fn (string $g): string => strtolower(trim($g)), $gazdePermise)));
        foreach ($this->gazde as $g) {
            if (self::tracker((string) preg_replace('/:\d+$/', '', $g))) {
                throw new EroareCaptura('gazda', 'gazda ' . $g . ' e a unui tracker: captura nu o cere niciodată');
            }
        }
    }

    /** Gazda (fără port) e a unui tracker? */
    public static function tracker(string $gazda): bool
    {
        $gazda = strtolower(rtrim($gazda, '.'));
        foreach (self::TRACKERE as $t) {
            if ($gazda === $t || str_ends_with($gazda, '.' . $t)) {
                return true;
            }
        }
        return false;
    }

    /** Adresa poate fi cerută (schema http(s), gazda permisă, niciun tracker)? Motivul refuzului, sau null. */
    public function refuz(string $url): ?string
    {
        $p = parse_url($url);
        $schema = strtolower((string) ($p['scheme'] ?? ''));
        $gazda = strtolower((string) ($p['host'] ?? ''));
        if (!in_array($schema, ['http', 'https'], true) || $gazda === '') {
            return 'doar adrese http(s) absolute: ' . $url;
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return 'fără autentificare în adresă';
        }
        if (self::tracker($gazda)) {
            return 'gazda ' . $gazda . ' e a unui tracker (refuzată mereu)';
        }
        $cuPort = $gazda . (isset($p['port']) ? ':' . $p['port'] : '');
        if (!in_array($cuPort, $this->gazde, true)) {
            return 'gazda ' . $cuPort . ' nu e în lista capturii (' . implode(', ', $this->gazde) . ')';
        }
        return null;
    }

    /** Câte cereri au plecat (cu reluările). */
    public function facute(): int
    {
        return $this->facute;
    }

    public function ramase(): int
    {
        return max(0, $this->buget - $this->facute);
    }

    /** @return list<array<string, mixed>> fiecare încercare: ora de început (și în ms), metoda, adresa, UA-ul, statusul, octeții, durata */
    public function jurnal(): array
    {
        return $this->jurnal;
    }

    /** Fișierul jurnalului (null: doar în memorie). */
    public function fisierJurnal(): ?string
    {
        return $this->fisierJurnal;
    }

    /** @return array<string, mixed> ritmul, bugetul, gazdele permise și limitele, pentru antetul raportului */
    public function configuratie(): array
    {
        return ['ritm_s' => $this->ritm, 'buget' => $this->buget, 'gazde_permise' => $this->gazde, 'reluari' => $this->reluari,
            'limitari_la_rand' => $this->limitari, 'asteptare_maxima_s' => $this->asteptareMaxima, 'timeout_s' => $this->timeout,
            'timeout_conectare_s' => $this->timeoutConectare, 'corp_maxim_octeti' => $this->corpMaxim, 'metoda' => 'GET'];
    }

    /** O linie în jurnalul de pe disc: doar adăugată, cu fflush și fsync (rămâne și după o oprire la jumătate). */
    private function scrieJurnal(array $linie): void
    {
        if ($this->fisierJurnal === null) {
            return;
        }
        if ($this->jurnalDeschis === null) {
            if (is_link($this->fisierJurnal)) {
                throw new EroareCaptura('scriere', 'refuz să scriu jurnalul printr-o legătură: ' . $this->fisierJurnal);
            }
            $h = @fopen($this->fisierJurnal, 'ab');
            if ($h === false) {
                throw new EroareCaptura('scriere', 'nu pot scrie jurnalul cererilor: ' . $this->fisierJurnal);
            }
            $this->jurnalDeschis = $h;
        }
        $text = json_encode($linie, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        if (@fwrite($this->jurnalDeschis, $text) !== strlen($text) || !@fflush($this->jurnalDeschis)) {
            throw new EroareCaptura('scriere', 'nu pot scrie jurnalul cererilor: ' . $this->fisierJurnal);
        }
        @fsync($this->jurnalDeschis);
    }

    /**
     * Un GET. `$ua`: o cheie din UA. Reia la 429 (în buget); orice alt status se întoarce așa cum e (și 301, 404, 500).
     */
    public function get(string $url, string $ua = 'chrome-win', string $accept = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'): Raspuns
    {
        $refuz = $this->refuz($url);
        if ($refuz !== null) {
            throw new EroareCaptura('gazda', 'cerere refuzată, nimic trimis: ' . $refuz);
        }
        if (!isset(self::UA[$ua])) {
            throw new EroareCaptura('folosire', 'UA necunoscut: ' . $ua);
        }
        $incercari = 0;
        while (true) {
            if ($this->facute >= $this->buget) {
                throw new EroareCaptura('buget', 'bugetul de ' . $this->buget . ' cereri s-a terminat (oprit înaintea lui ' . $url . ')');
            }
            $this->asteaptaRitmul();
            $incercari++;
            $this->facute++;
            $r = $this->trimite($url, $ua, $accept, $incercari);
            if (!self::rabdareCeruta($r)) {
                $this->limitariLaRand = 0;
                return $r;
            }
            $this->limitariLaRand++;
            if ($this->limitariLaRand >= $this->limitari) {
                throw new EroareCaptura('limitare', 'gazda a răspuns ' . $r->status . ' (429 sau 503 cu Retry-After) de ' . $this->limitariLaRand
                    . ' ori la rând: captura se oprește (reia mai târziu)');
            }
            if ($incercari > $this->reluari) {
                return $r; // 429/503 după toate reluările: răspunsul rămâne cum e (Captura îl arată NU)
            }
            sleep($this->asteptare($r, $incercari));
        }
    }

    /** Gazda cere să așteptăm: 429, sau 503 cu `Retry-After` (supraîncărcare declarată). */
    public static function rabdareCeruta(Raspuns $r): bool
    {
        return $r->status === 429 || ($r->status === 503 && $r->antet('retry-after') !== null);
    }

    private function asteaptaRitmul(): void
    {
        $acum = hrtime(true) / 1e9;
        $urmatoarea = $this->ultima + $this->ritm;
        if ($this->ultima > 0 && $acum < $urmatoarea) {
            usleep((int) (($urmatoarea - $acum) * 1e6));
        }
        $this->ultima = hrtime(true) / 1e9;
    }

    /** Cât se așteaptă după un 429: `Retry-After` (secunde sau dată HTTP), altfel 10/30/60 s; cel mult $asteptareMaxima. */
    private function asteptare(Raspuns $r, int $incercari): int
    {
        $ra = trim((string) $r->antet('retry-after'));
        $s = null;
        if ($ra !== '' && ctype_digit($ra)) {
            $s = (int) $ra;
        } elseif ($ra !== '' && ($t = strtotime($ra)) !== false) {
            $s = $t - time();
        }
        $s ??= [10, 30, 60][min($incercari - 1, 2)];
        return max(1, min($this->asteptareMaxima, $s));
    }

    private function trimite(string $url, string $ua, string $accept, int $incercari): Raspuns
    {
        $c = curl_init();
        $antete = [];
        $brute = '';
        $corp = '';
        $prea = false;
        curl_setopt_array($c, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => self::UA[$ua],
            CURLOPT_HTTPHEADER => ['Accept: ' . $accept, 'Accept-Language: ro-RO,ro;q=0.9,en-US;q=0.8,en;q=0.7'],
            CURLOPT_ENCODING => '',
            CURLOPT_CONNECTTIMEOUT => $this->timeoutConectare,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SUPPRESS_CONNECT_HEADERS => true,
            CURLOPT_HEADERFUNCTION => static function ($h, string $linie) use (&$antete, &$brute): int {
                if (preg_match('#^HTTP/\S+\s+\d{3}#', $linie) === 1) {
                    // Un nou bloc de antete (100 Continue sau răspunsul final): ținem doar ultimul.
                    $antete = [];
                    $brute = '';
                }
                $brute .= $linie;
                if (str_contains($linie, ':')) {
                    [$n, $v] = explode(':', $linie, 2);
                    $antete[] = [strtolower(trim($n)), trim($v)];
                }
                return strlen($linie);
            },
            CURLOPT_WRITEFUNCTION => function ($h, string $bucata) use (&$corp, &$prea): int {
                if (strlen($corp) + strlen($bucata) > $this->corpMaxim) {
                    $prea = true;
                    return 0;
                }
                $corp .= $bucata;
                return strlen($bucata);
            },
        ]);
        foreach (['CURL_CA_BUNDLE', 'SSL_CERT_FILE'] as $v) {
            $ca = (string) getenv($v);
            if ($ca !== '' && is_file($ca)) {
                curl_setopt($c, CURLOPT_CAINFO, $ca);
                break;
            }
        }
        $startMs = (int) floor(microtime(true) * 1000);
        $this->scrieJurnal(['faza' => 'trimisa', 'nr' => $this->facute, 'start_ms' => $startMs, 'metoda' => 'GET', 'url' => $url, 'ua' => $ua,
            'incercarea' => $incercari]);
        $start = hrtime(true);
        $ok = curl_exec($c);
        $durata = (hrtime(true) - $start) / 1e9;
        $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $eroare = curl_error($c);
        curl_close($c);
        $codare = '';
        foreach ($antete as [$n, $v]) {
            if ($n === 'content-encoding') {
                $codare = strtolower($v);
            }
        }
        $intrare = ['nr' => $this->facute, 'ora' => date('c', intdiv($startMs, 1000)), 'start_ms' => $startMs, 'metoda' => 'GET', 'url' => $url, 'ua' => $ua,
            'status' => $status, 'octeti' => strlen($corp), 'durata_ms' => (int) round($durata * 1000), 'incercarea' => $incercari]
            + ($prea ? ['eroare' => 'corpul depășește ' . $this->corpMaxim . ' octeți'] : []) + ($ok === false ? ['eroare' => $eroare] : []);
        $this->jurnal[] = $intrare;
        $this->scrieJurnal(['faza' => 'raspuns'] + array_diff_key($intrare, ['ora' => 1, 'metoda' => 1, 'url' => 1, 'ua' => 1, 'incercarea' => 1]));
        if ($prea) {
            throw new EroareCaptura('retea', 'răspunsul de la ' . $url . ' depășește ' . $this->corpMaxim . ' octeți (corpul decodat)');
        }
        if ($ok === false || $status === 0) {
            throw new EroareCaptura('retea', 'niciun răspuns de la ' . $url . ': ' . $eroare);
        }
        return new Raspuns($url, $ua, $status, $antete, $brute, $corp, $codare, $durata, $incercari);
    }
}
