<?php

declare(strict_types=1);

/**
 * Citirea, strict în citire, a unui site live (WP-10): partea comună a lui
 * tools/verificare/agregate.php și tools/sonda/amprenta-schema.php (docs/VERIFICARE.md,
 * „Verificările pe site-urile live”). Se rulează noaptea, de proprietar, prin SSH, din
 * dosarul site-ului de verificare, cu datele de acces la bază din .env-ul site-ului live.
 *
 * Ce garantează (le verifică tools/dev/test-sonde.php):
 * - **Locul.** Rulează doar din codul site-ului de verificare: `~/domains/<domeniu>/public_html`
 *   cu `SITE_COPIE_VERIFICARE=1` în .env-ul lui. Din dosarul unui site live (unde, după
 *   adoptare, va fi tot codul acesta) refuză.
 * - **Site-ul live** se caută după domeniul setului: `~/domains/<domeniu>/public_html`
 *   (și `www.<domeniu>`) sau `~/public_html`, cu un .env obișnuit (nu legătură) al cărui
 *   APP_URL are domeniul așteptat și care nu e o copie de verificare. Un singur dosar
 *   găsit, altfel refuză (cu `--dosar=` se alege, dar se verifică la fel).
 * - **.env-ul site-ului live** se citește o dată, în memorie: din el rămân doar APP_URL,
 *   APP_TIMEZONE și DB_* (folosite doar pentru conexiune) și numele cheilor nevide.
 *   Nicio valoare nu se tipărește, nici într-o eroare: o eroare a bazei apare doar ca cod
 *   („eroarea 1045”), nu cu mesajul ei (care poate conține utilizatorul sau o valoare).
 * - **În dosarul site-ului live nu se scrie nimic**: nici fișiere temporare, nici sesiuni
 *   (bootstrap.php nu se încarcă; o sesiune deja pornită oprește rularea). Se citesc doar
 *   .env, .git/HEAD cu refs, amprenta câtorva fișiere de cod, numele din câteva dosare
 *   (pentru numărat; conținutul lor nu se deschide), fișierele paginilor din pachetul
 *   site-ului (doar amprenta).
 * - **În bază:** o sesiune `TRANSACTION READ ONLY` (verificată după setare; altfel nu
 *   pornește); fiecare instrucțiune într-o tranzacție `WITH CONSISTENT SNAPSHOT, READ ONLY`
 *   a ei, închisă imediat după ce s-au luat rândurile, înaintea pauzei: lacătul de metadate
 *   al unui tabel citit se ține doar cât rulează instrucțiunea (cel mult 15 s, de obicei
 *   milisecunde), deci un ALTER al site-ului așteaptă după noi cel mult atât, nu cât un grup.
 *   Numerele unui grup pot veni astfel din momente la câteva milisecunde distanță. Doar
 *   `SELECT`/`SHOW` (fiecare instrucțiune verificată înainte: fără `;`, comentarii, INTO,
 *   FOR UPDATE, SLEEP…), fiecare SELECT terminat cu LIMIT, cel mult 15 s pe instrucțiune
 *   (`max_statement_time` / `max_execution_time`), 5 s după un lacăt, o pauză după fiecare
 *   instrucțiune, 30 s după un răspuns al bazei (`mysqlnd.net_read_timeout`) și un buget de
 *   timp pe rulare. Tabelele cu texte lungi se parcurg pe bucăți de cel mult 2 MB; un rând
 *   peste 8 MB, sau mai mare decât încape în memoria PHP rămasă (`memory_limit`), nu se
 *   citește, doar se numără: memoria nu se termină în mijlocul unei citiri. Conexiunea refuză
 *   și mai multe instrucțiuni într-una (`MYSQL_ATTR_MULTI_STATEMENTS`) și `LOAD DATA LOCAL`.
 * - **Erorile PHP** nu le tipărește PHP-ul (mesajul lui are calea contului și poate avea o
 *   valoare): punctele de intrare le opresc afișarea, iar o eroare fatală (memoria, timpul)
 *   oprește rularea cu un text fix; agregate.php scrie atunci și verificările de până acolo
 *   (Rulare::opritDeEroare).
 * - **Ieșirea** are doar numere, DA/NU, valori din liste fixe și nume de structură (tabele,
 *   coloane, chei de setări, clase CSS, slug-uri de pagini publicate, id-uri de produse,
 *   commit-ul codului): fiecare valoare trece printr-un tipar înainte să fie scrisă, iar
 *   una care nu se potrivește oprește verificarea ei („valoare refuzată”), nu ajunge pe
 *   ecran. O coloană cu nume de date personale (același tipar ca la
 *   încărcarea anonimizată din WP-09) nu poate fi citită deloc.
 *
 * Sintaxa e PHP 8.3 (php_min); punctele de intrare verifică versiunea cu sintaxă PHP 7.0.
 */

namespace Unelte\Verificare;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__, 3) . '/src/Support/Verificare.php';

/** Refuzul de a rula (dosar greșit, .env lipsă, conexiune refuzată): cod de ieșire 2. Mesajul nu are valori. */
final class Refuz extends RuntimeException
{
}

/** O instrucțiune care nu e strict de citire, sau o coloană cu nume de date personale. */
final class InstructiuneRefuzata extends RuntimeException
{
}

/** O valoare care nu se potrivește tiparului ei: nu se tipărește. */
final class ValoareRefuzata extends RuntimeException
{
}

// ───────────────────────────────────────────────────────────────────────────
// Locul: de unde rulează și ce site citește
// ───────────────────────────────────────────────────────────────────────────

final class Locul
{
    /**
     * Site-urile live cunoscute: numele setului / al sondei → domeniile lui și codul scurt al
     * fixture-urilor migrărilor (tools/dev/migrari/vechi/<cod>.sql).
     */
    public const SITE_URI = [
        'bioscem' => ['gazde' => ['bioscem.ro'], 'cod' => 'bs', 'titlu' => 'Bioscem'],
        'biovitality' => ['gazde' => ['biovitality.ro'], 'cod' => 'bv', 'titlu' => 'Biovitality'],
        'vertij' => ['gazde' => ['vertij-tinitus.ro'], 'cod' => 'vt', 'titlu' => 'vertij-tinitus'],
        'grafoanaytis' => ['gazde' => ['grafoanaytis.ro'], 'cod' => 'gr', 'titlu' => 'grafoanaytis'],
    ];

    /**
     * Verifică că rulăm din codul site-ului de verificare; întoarce [acasă, gazda verificării].
     *
     * @return array{0: string, 1: string}
     */
    public static function verificare(string $radacina): array
    {
        $radacina = (string) realpath($radacina);
        // <acasă>/domains/<gazdă>/public_html → acasă = trei niveluri mai sus.
        $acasa = $radacina !== '' ? dirname($radacina, 3) : '';
        $gazda = $acasa !== '' ? \App\Support\Verificare::dosarSite($radacina, $acasa) : null;
        if ($gazda === null) {
            throw new Refuz('rulează doar din dosarul site-ului de verificare (~/domains/<domeniul-verificare>/public_html); '
                . 'acest cod nu e într-un asemenea dosar');
        }
        $home = (string) getenv('HOME');
        if ($home !== '' && realpath($home) !== false && realpath($home) !== $acasa) {
            throw new Refuz('dosarul codului nu e în contul tău (HOME nu e dosarul de deasupra lui domains/)');
        }
        // .env-ul codului care rulează (Paths::env(): azi lângă cod, în Etapa 2 în dosarul site-ului).
        $env = self::citesteEnv(\App\Support\Verificare::caleEnv());
        if ($env === null || trim($env['SITE_COPIE_VERIFICARE'] ?? '') !== '1') {
            throw new Refuz('rulează doar din dosarul site-ului de verificare: aici .env lipsește sau nu are SITE_COPIE_VERIFICARE=1 '
                . '(pe un site live aceste unelte nu rulează)');
        }
        return [$acasa, $gazda];
    }

    /**
     * Cheile unui .env (exact ca Env::load), sau null dacă fișierul lipsește, e o legătură, nu
     * e un fișier obișnuit, e prea mare sau nu se poate citi (open_basedir, drepturi).
     *
     * @return array<string, string>|null
     */
    public static function citesteEnv(string $cale): ?array
    {
        if (!is_file($cale) || is_link($cale) || !is_readable($cale)) {
            return null;
        }
        $marime = @filesize($cale);
        if ($marime === false || $marime > 65536) {
            return null;
        }
        $text = @file_get_contents($cale);
        return is_string($text) ? \App\Support\Verificare::citesteEnv($text) : null;
    }

    /** Domeniul unui APP_URL, cu litere mici, fără „www.”; '' dacă nu se înțelege. */
    public static function gazdaDin(string $url): string
    {
        $gazda = strtolower((string) parse_url(trim($url), PHP_URL_HOST));
        return str_starts_with($gazda, 'www.') ? substr($gazda, 4) : $gazda;
    }
}

final class SiteLive
{
    /** Fișierele de cod a căror amprentă se scrie (G1: ce cod rulează); niciodată config sau .env. */
    public const FISIERE_COD = [
        'public/index.php',
        'bootstrap.php',
        'src/Http/rute.php',
        'src/Http/Controllers/SiteController.php',
        'src/Http/Controllers/AdminController.php',
        'src/Support/CheckoutCalculator.php',
        'src/Support/Settings.php',
    ];

    /** Cheile .env folosite (doar pentru conexiune și fusul orar); restul doar ca nume. */
    private const CHEI_FOLOSITE = ['APP_URL', 'APP_TIMEZONE', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];

    /** @param array<string, string> $conexiune @param list<string> $cheiNevide @param array<string, string> $alegeri */
    private function __construct(
        public readonly string $nume,
        public readonly string $titlu,
        public readonly string $dosar,
        public readonly string $dosarRelativ,
        public readonly string $gazda,
        private array $conexiune,
        private readonly array $cheiNevide,
        private readonly array $alegeri,
    ) {
    }

    /**
     * Site-ul live al unui set: dosarul dat cu --dosar (verificat), sau singurul dosar
     * candidat al cărui .env are domeniul setului.
     */
    public static function gaseste(string $nume, ?string $dosarDat, string $acasa, string $radacinaVerificare): self
    {
        $site = Locul::SITE_URI[$nume] ?? null;
        if ($site === null) {
            throw new Refuz('site necunoscut: ' . (preg_match('/^[a-z0-9_-]{1,40}$/', $nume) === 1 ? $nume : '?')
                . ' (cunoscute: ' . implode(', ', array_keys(Locul::SITE_URI)) . ')');
        }
        $gazde = $site['gazde'];
        $verificare = (string) realpath($radacinaVerificare);
        if ($dosarDat !== null) {
            // Shell-ul nu înlocuiește „~” după „=” (--dosar=~/domains/…): o facem aici, cu dosarul contului.
            if ($dosarDat === '~' || str_starts_with($dosarDat, '~/')) {
                $dosarDat = rtrim($acasa, '/') . substr($dosarDat, 1);
            }
            $real = realpath($dosarDat);
            if ($real === false || !is_dir($real)) {
                throw new Refuz('--dosar: dosarul nu există');
            }
            $relativ = self::relativ($real, $acasa);
            if ($relativ === null) {
                throw new Refuz('--dosar trebuie să fie ~/public_html sau ~/domains/<domeniu>/public_html din contul tău');
            }
            if ($real === $verificare) {
                throw new Refuz('--dosar e chiar site-ul de verificare, nu site-ul live ' . $site['titlu']);
            }
            $motiv = '';
            $env = self::envPotrivit($real, $gazde, $motiv);
            if ($env === null) {
                throw new Refuz('--dosar ' . $relativ . ': ' . $motiv);
            }
            return self::din($nume, $site['titlu'], $real, $relativ, $env);
        }
        $candidati = [];
        foreach ($gazde as $gazda) {
            $candidati[] = $acasa . '/domains/' . $gazda . '/public_html';
            $candidati[] = $acasa . '/domains/www.' . $gazda . '/public_html';
        }
        $candidati[] = $acasa . '/public_html';
        $gasite = [];
        $refuzate = [];
        foreach (array_unique($candidati) as $candidat) {
            $real = realpath($candidat);
            if ($real === false || !is_dir($real) || $real === $verificare || isset($gasite[$real])) {
                continue;
            }
            $relativ = self::relativ($real, $acasa);
            if ($relativ === null) {
                continue;
            }
            $motiv = '';
            $env = self::envPotrivit($real, $gazde, $motiv);
            if ($env === null) {
                if (is_file($real . '/.env')) {
                    $refuzate[] = $relativ . ' (' . $motiv . ')';
                }
                continue;
            }
            $gasite[$real] = [$relativ, $env];
        }
        if (count($gasite) === 0) {
            throw new Refuz('nu găsesc site-ul live ' . $site['titlu'] . ' (' . implode(', ', $gazde) . ') în ~/domains/<domeniu>/public_html '
                . 'sau ~/public_html' . ($refuzate !== [] ? '; nepotrivite: ' . implode('; ', $refuzate) : '')
                . '. Dă dosarul cu --dosar=~/domains/<domeniu>/public_html');
        }
        if (count($gasite) > 1) {
            throw new Refuz('găsesc mai multe dosare cu ' . implode(', ', $gazde) . ': '
                . implode(', ', array_map(static fn (array $g): string => $g[0], $gasite)) . '. Alege unul cu --dosar=');
        }
        $real = (string) array_key_first($gasite);
        return self::din($nume, $site['titlu'], $real, $gasite[$real][0], $gasite[$real][1]);
    }

    /** `public_html` sau `domains/<gazdă>/public_html` (relativ la acasă), sau null pentru orice alt dosar. */
    private static function relativ(string $real, string $acasa): ?string
    {
        $acasa = rtrim((string) realpath($acasa), '/');
        if ($acasa === '') {
            return null;
        }
        if ($real === $acasa . '/public_html') {
            return 'public_html';
        }
        $gazda = \App\Support\Verificare::dosarSite($real, $acasa);
        return $gazda !== null ? 'domains/' . $gazda . '/public_html' : null;
    }

    /**
     * .env-ul unui dosar, dacă e al site-ului cerut; altfel null și motivul (fără valori din .env,
     * doar domeniul găsit, care e public).
     *
     * @param list<string> $gazde
     * @return array<string, string>|null
     */
    private static function envPotrivit(string $dosar, array $gazde, string &$motiv): ?array
    {
        $cale = $dosar . '/.env';
        if (is_link($cale)) {
            $motiv = '.env e o legătură, nu un fișier';
            return null;
        }
        $env = Locul::citesteEnv($cale);
        if ($env === null) {
            $motiv = is_file($cale) ? '.env nu se poate citi' : 'fără .env';
            return null;
        }
        if (trim($env['SITE_COPIE_VERIFICARE'] ?? '') === '1') {
            $motiv = 'e o copie de verificare, nu un site live';
            return null;
        }
        $gazda = Locul::gazdaDin($env['APP_URL'] ?? '');
        if (!in_array($gazda, $gazde, true)) {
            $motiv = 'APP_URL are ' . (preg_match('/^[a-z0-9.-]{1,253}$/', $gazda) === 1 && $gazda !== '' ? 'domeniul ' . $gazda : 'alt domeniu sau lipsește')
                . ', nu ' . implode(', ', $gazde);
            return null;
        }
        if (trim($env['DB_DATABASE'] ?? '') === '') {
            $motiv = '.env nu are DB_DATABASE';
            return null;
        }
        return $env;
    }

    /** @param array<string, string> $env */
    private static function din(string $nume, string $titlu, string $dosar, string $relativ, array $env): self
    {
        $conexiune = [];
        foreach (self::CHEI_FOLOSITE as $cheie) {
            $conexiune[$cheie] = (string) ($env[$cheie] ?? '');
        }
        $nevide = [];
        foreach ($env as $cheie => $valoare) {
            if (trim($valoare) !== '' && preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $cheie) === 1) {
                $nevide[] = $cheie;
            }
        }
        sort($nevide);
        // Valori din liste fixe, nu secrete: modul BT, mediul, depanarea.
        $alegeri = [];
        foreach (['BT_IPAY_MODE' => ['test', 'live'], 'APP_ENV' => ['production', 'local', 'verificare', 'staging'], 'APP_DEBUG' => ['true', 'false', '1', '0']] as $cheie => $permise) {
            $valoare = strtolower(trim((string) ($env[$cheie] ?? '')));
            $alegeri[$cheie] = $valoare === '' ? 'gol' : (in_array($valoare, $permise, true) ? $valoare : 'alta');
        }
        $gazda = Locul::gazdaDin($env['APP_URL'] ?? '');
        unset($env);
        return new self($nume, $titlu, $dosar, $relativ, $gazda, $conexiune, $nevide, $alegeri);
    }

    /**
     * Datele conexiunii, o singură dată (după deschidere se uită).
     *
     * @return array<string, string>
     */
    public function preiaConexiunea(): array
    {
        $conexiune = $this->conexiune;
        $this->conexiune = [];
        return $conexiune;
    }

    /** Numele cheilor .env cu valoare (fără valori). @return list<string> */
    public function cheiEnvNevide(): array
    {
        return $this->cheiNevide;
    }

    /** O valoare .env dintr-o listă fixă (`gol`, `alta` sau una din listă). */
    public function alegereEnv(string $cheie): string
    {
        return $this->alegeri[$cheie] ?? 'gol';
    }

    /**
     * Codul de pe site: dacă e un deploy git, ramura și commit-ul (din .git/HEAD și refs, fără
     * comanda git, care poate rescrie indexul), și amprenta câtorva fișiere de cod.
     *
     * @return array{git: bool, ramura: ?string, commit: ?string, fisiere: array<string, ?string>}
     */
    public function cod(): array
    {
        $rezultat = ['git' => is_dir($this->dosar . '/.git') && !is_link($this->dosar . '/.git'), 'ramura' => null, 'commit' => null, 'fisiere' => []];
        if ($rezultat['git']) {
            $cap = trim((string) @file_get_contents($this->dosar . '/.git/HEAD', false, null, 0, 512));
            if (str_starts_with($cap, 'ref: refs/heads/')) {
                $ref = substr($cap, 5);
                if (preg_match('#^refs/heads/[A-Za-z0-9._/-]{1,200}$#', $ref) === 1 && !str_contains($ref, '..')) {
                    $rezultat['ramura'] = substr($ref, strlen('refs/heads/'));
                    $commit = trim((string) @file_get_contents($this->dosar . '/.git/' . $ref, false, null, 0, 128));
                    if (preg_match('/^[0-9a-f]{40}$/', $commit) !== 1 && is_file($this->dosar . '/.git/packed-refs')) {
                        $commit = '';
                        foreach (explode("\n", (string) @file_get_contents($this->dosar . '/.git/packed-refs', false, null, 0, 1048576)) as $rand) {
                            $parti = explode(' ', trim($rand));
                            if (count($parti) === 2 && $parti[1] === $ref) {
                                $commit = $parti[0];
                            }
                        }
                    }
                    $rezultat['commit'] = preg_match('/^[0-9a-f]{40}$/', $commit) === 1 ? $commit : null;
                }
            } elseif (preg_match('/^[0-9a-f]{40}$/', $cap) === 1) {
                $rezultat['commit'] = $cap;
            }
        }
        foreach (self::FISIERE_COD as $fisier) {
            $cale = $this->dosar . '/' . $fisier;
            $rezultat['fisiere'][$fisier] = is_file($cale) && !is_link($cale) ? substr((string) hash_file('sha256', $cale), 0, 16) : null;
        }
        return $rezultat;
    }

    /**
     * Câte fișiere sunt într-un dosar al site-ului (și în subdosarele lui, până la 4 niveluri),
     * fără să deschidă vreunul; null dacă dosarul lipsește. Fișierele de pază ale git-ului și
     * ale serverului web nu se numără.
     */
    public function numaraFisiere(string $relativ): ?int
    {
        $cale = $this->cale($relativ);
        if ($cale === null || !is_dir($cale)) {
            return null;
        }
        $numar = 0;
        $vizitate = 0;
        $parcurge = function (string $dosar, int $adancime) use (&$parcurge, &$numar, &$vizitate): void {
            $intrari = @scandir($dosar);
            if (!is_array($intrari)) {
                return;
            }
            foreach ($intrari as $intrare) {
                if ($intrare === '.' || $intrare === '..' || ++$vizitate > 100000) {
                    continue;
                }
                $complet = $dosar . '/' . $intrare;
                if (is_link($complet)) {
                    continue;
                }
                if (is_dir($complet)) {
                    if ($adancime < 4) {
                        $parcurge($complet, $adancime + 1);
                    }
                } elseif (is_file($complet) && !in_array($intrare, ['.gitignore', '.gitkeep', '.htaccess', 'index.html'], true)) {
                    $numar++;
                }
            }
        };
        $parcurge($cale, 1);
        return $numar;
    }

    /**
     * Numele fișierelor dintr-un dosar care se potrivesc unui tipar (doar numele, de exemplu
     * paginile din pachetul unui site); null dacă dosarul lipsește.
     *
     * @return list<string>|null
     */
    public function numeFisiere(string $relativ, string $tipar): ?array
    {
        $cale = $this->cale($relativ);
        if ($cale === null || !is_dir($cale)) {
            return null;
        }
        $nume = [];
        foreach ((array) @scandir($cale) as $intrare) {
            if (is_string($intrare) && preg_match($tipar, $intrare) === 1 && is_file($cale . '/' . $intrare) && !is_link($cale . '/' . $intrare)) {
                $nume[] = $intrare;
            }
        }
        sort($nume);
        return array_slice($nume, 0, 500);
    }

    /** Amprenta sha256 a unui fișier al site-ului (fișier obișnuit, nu legătură), sau null. */
    public function amprentaFisier(string $relativ): ?string
    {
        $cale = $this->cale($relativ);
        if ($cale === null || !is_file($cale) || is_link($cale) || (int) @filesize($cale) > 20 * 1048576) {
            return null;
        }
        $amprenta = @hash_file('sha256', $cale);
        return is_string($amprenta) ? $amprenta : null;
    }

    /** Calea completă a unui fișier relativ la dosarul site-ului, doar în interiorul lui; altfel null. */
    private function cale(string $relativ): ?string
    {
        if ($relativ === '' || str_contains($relativ, '..') || str_starts_with($relativ, '/') || preg_match('#^[A-Za-z0-9._/-]{1,200}$#', $relativ) !== 1) {
            return null;
        }
        return $this->dosar . '/' . $relativ;
    }
}

// ───────────────────────────────────────────────────────────────────────────
// Baza, strict în citire
// ───────────────────────────────────────────────────────────────────────────

final class CitireLive
{
    /** Cel mult atâtea secunde pe instrucțiune (serverul o oprește). */
    public const TIMP_INSTRUCTIUNE = 15;
    /** Pauza după fiecare instrucțiune, ca baza site-ului să nu simtă rularea. */
    public const PAUZA_MS = 20;
    /** LIMIT-ul cel mai mare acceptat. */
    public const LIMITA_MAXIMA = 50000;
    /** O bucată din parcurge() are cel mult atâția octeți de text (memoria PHP a contului e mică). */
    public const OCTETI_BUCATA = 2 * 1048576;
    /** Un rând mai mare nu se citește deloc (doar se numără, ca „prea mare”). */
    public const OCTETI_RAND = 8 * 1048576;
    /** Un text citit stă în memorie de cel mult atâtea ori (rezultatul brut, șirul PHP, copiile prelucrării). */
    public const COPII_IN_MEMORIE = 4;
    /** Memoria PHP lăsată liberă după o bucată, pentru restul rulării și pentru raport. */
    public const REZERVA_MEMORIE = 4 * 1048576;
    /**
     * Cel mult atâtea secunde de așteptat un răspuns al bazei (mysqlnd.net_read_timeout, implicit o
     * zi). O instrucțiune durează cel mult TIMP_INSTRUCTIUNE; după o eroare fatală în mijlocul unei
     * citiri, închiderea conexiunii ar aștepta altfel o zi, cu tranzacția (și lacătul ei) deschisă.
     */
    public const TIMP_RETEA = 30;
    /** Numele de coloane cu date personale (același tipar ca la încărcarea anonimizată, WP-09): nu se citesc. */
    public const TIPAR_PERSONAL = '/(e-?mail|phone|telefon|first_name|last_name|full_name|nume|prenume|name$|address|adresa|street|strada|postcode|cnp|ci_|^ip$|_ip$|ip_address|user_agent|token|password|parola|secret|iban|birth|gender|signature|semnatura|google_id|session)/i';

    private bool $inTranzactie = false;
    public int $interogari = 0;
    /** @var array<string, array<string, true>>|null */
    private ?array $structura = null;

    private function __construct(private readonly PDO $db, public readonly string $familie, public readonly string $versiune)
    {
    }

    /**
     * Conexiunea cu datele din .env-ul site-ului, apoi sesiunea doar de citire. Orice eroare
     * devine un refuz cu codul erorii, fără mesajul ei.
     */
    public static function deschide(SiteLive $site): self
    {
        $c = $site->preiaConexiunea();
        $fus = trim($c['APP_TIMEZONE'] ?? '');
        if ($fus === '' || !in_array($fus, \DateTimeZone::listIdentifiers(), true)) {
            $fus = 'Europe/Bucharest';
        }
        date_default_timezone_set($fus);
        $gazda = trim($c['DB_HOST'] ?? '') !== '' ? trim($c['DB_HOST']) : '127.0.0.1';
        $port = (int) (trim($c['DB_PORT'] ?? '') !== '' ? $c['DB_PORT'] : 3306);
        $optiuni = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            PDO::MYSQL_ATTR_LOCAL_INFILE => false,
        ];
        // Citit la deschiderea conexiunii: cel mult TIMP_RETEA secunde după un răspuns al bazei.
        @ini_set('mysqlnd.net_read_timeout', (string) self::TIMP_RETEA);
        try {
            $db = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $gazda, $port, (string) $c['DB_DATABASE']),
                (string) $c['DB_USERNAME'], (string) $c['DB_PASSWORD'], $optiuni);
        } catch (PDOException $e) {
            throw new Refuz('legătura cu baza site-ului nu merge (' . self::cod($e) . ')');
        } finally {
            unset($c);
        }
        try {
            $versiune = (string) $db->query('SELECT VERSION()')->fetchColumn();
            $familie = stripos($versiune, 'mariadb') !== false ? 'mariadb' : 'mysql';
            $numar = preg_match('/^\d+\.\d+\.\d+/', $versiune, $m) === 1 ? $m[0] : '0.0.0';
            $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->exec('SET SESSION TRANSACTION READ ONLY');
            $deplasare = date('P');
            if (preg_match('/^[+-]\d{2}:\d{2}$/', $deplasare) === 1) {
                $db->exec("SET time_zone = '" . $deplasare . "'");
            }
            if ($familie === 'mariadb') {
                $db->exec('SET SESSION max_statement_time = ' . self::TIMP_INSTRUCTIUNE);
            } else {
                $db->exec('SET SESSION max_execution_time = ' . (self::TIMP_INSTRUCTIUNE * 1000));
            }
            $db->exec('SET SESSION lock_wait_timeout = 5');
            $db->exec('SET SESSION innodb_lock_wait_timeout = 5');
            $doarCitire = null;
            foreach (['@@SESSION.transaction_read_only', '@@SESSION.tx_read_only'] as $variabila) {
                try {
                    $doarCitire = (int) $db->query('SELECT ' . $variabila)->fetchColumn();
                    break;
                } catch (PDOException) {
                }
            }
            if ($doarCitire !== 1) {
                throw new Refuz('sesiunea bazei nu a trecut în modul doar de citire; nu citesc nimic');
            }
        } catch (PDOException $e) {
            throw new Refuz('sesiunea doar de citire nu se poate pregăti (' . self::cod($e) . ')');
        }
        return new self($db, $familie, $numar);
    }

    /** memory_limit în octeți; null = fără limită. */
    public static function limitaMemorie(): ?int
    {
        $text = strtolower(trim((string) ini_get('memory_limit')));
        if (preg_match('/^(\d+)([kmg]?)$/', $text, $m) !== 1 || (int) $m[1] <= 0) {
            return null;
        }
        return (int) $m[1] * match ($m[2]) {
            'g' => 1073741824,
            'm' => 1048576,
            'k' => 1024,
            default => 1,
        };
    }

    /**
     * Câți octeți de text se pot citi acum: cel mult OCTETI_RAND și cât încape în memoria PHP
     * rămasă (memory_limit), cu COPII_IN_MEMORIE copii și REZERVA_MEMORIE liberă. Un rând mai
     * mare nu se citește (altfel memoria s-ar termina în mijlocul citirii, cu conexiunea pe jumătate).
     */
    public static function octetiCareIncap(): int
    {
        $limita = self::limitaMemorie();
        if ($limita === null) {
            return self::OCTETI_RAND;
        }
        return max(0, min(self::OCTETI_RAND, intdiv($limita - memory_get_usage() - self::REZERVA_MEMORIE, self::COPII_IN_MEMORIE)));
    }

    /** „eroarea 1045 (28000)” dintr-o excepție PDO, fără mesaj. */
    public static function cod(Throwable $e): string
    {
        $info = $e instanceof PDOException && is_array($e->errorInfo) ? $e->errorInfo : [];
        $driver = isset($info[1]) ? (int) $info[1] : 0;
        if ($driver === 0 && preg_match('/\[(\d{4})\]/', $e->getMessage(), $m) === 1) {
            $driver = (int) $m[1];
        }
        $stare = isset($info[0]) && preg_match('/^[0-9A-Z]{5}$/', (string) $info[0]) === 1 ? ' (' . $info[0] . ')' : '';
        return 'eroarea ' . ($driver > 0 ? $driver : '?') . $stare;
    }

    /** Eroarea e timpul depășit al unei instrucțiuni (MariaDB 1969, MySQL 3024)? */
    public static function eTimpDepasit(Throwable $e): bool
    {
        $cod = $e instanceof PDOException && is_array($e->errorInfo) ? (int) ($e->errorInfo[1] ?? 0) : 0;
        return in_array($cod, [1969, 3024], true);
    }

    /** Legătura s-a pierdut (2006, 2013)? */
    public static function eLegaturaPierduta(Throwable $e): bool
    {
        $cod = $e instanceof PDOException && is_array($e->errorInfo) ? (int) ($e->errorInfo[1] ?? 0) : 0;
        return in_array($cod, [2006, 2013], true);
    }

    /**
     * Refuză orice instrucțiune care nu e strict de citire: doar SELECT sau SHOW, una singură,
     * fără comentarii, fără INTO / FOR UPDATE / LOCK IN SHARE MODE / FOR SHARE, fără funcții
     * care așteaptă sau citesc fișiere; un SELECT se termină cu LIMIT n (n ≤ 50000).
     */
    public static function verificaInstructiunea(string $sql): void
    {
        $s = trim($sql);
        if (preg_match('/^(SELECT|SHOW)\s/i', $s) !== 1) {
            throw new InstructiuneRefuzata('doar SELECT sau SHOW');
        }
        if (str_contains($s, ';') || str_contains($s, '--') || str_contains($s, '#') || str_contains($s, '/*')) {
            throw new InstructiuneRefuzata('o singură instrucțiune, fără comentarii');
        }
        if (preg_match('/\b(INTO|UPDATE|LOCK|SHARE|SLEEP|BENCHMARK|LOAD_FILE|GET_LOCK|RELEASE_LOCK|RELEASE_ALL_LOCKS|IS_FREE_LOCK|MASTER_POS_WAIT|DUMPFILE|OUTFILE|NOWAIT|SKIP\s+LOCKED)\b/i', $s) === 1) {
            throw new InstructiuneRefuzata('cuvânt interzis într-o citire');
        }
        if (stripos($s, 'SELECT') === 0) {
            if (preg_match('/\bLIMIT\s+(\d+)$/i', $s, $m) !== 1 || (int) $m[1] < 1 || (int) $m[1] > self::LIMITA_MAXIMA) {
                throw new InstructiuneRefuzata('un SELECT se termină cu LIMIT n (cel mult ' . self::LIMITA_MAXIMA . ')');
            }
        }
    }

    /**
     * O citire (într-o tranzacție deschisă de inTranzactie). Numele coloanelor rezultatului nu
     * au voie să fie de date personale. După ea tranzacția se reia (reiaTranzactia), înaintea
     * pauzei: lacătele de metadate ale tabelelor ei nu rămân ținute până la următoarea.
     *
     * @param array<int|string, scalar|null> $parametri
     * @return list<array<string, mixed>>
     */
    public function randuri(string $sql, array $parametri = []): array
    {
        if (!$this->inTranzactie) {
            throw new InstructiuneRefuzata('citire în afara unei tranzacții doar de citire');
        }
        self::verificaInstructiunea($sql);
        // Fără parametri, protocolul text (SHOW GRANTS nu merge pe orice server ca instrucțiune pregătită).
        if ($parametri === []) {
            $stmt = $this->db->query($sql);
        } else {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($parametri);
        }
        $randuri = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        unset($stmt);
        $this->interogari++;
        $this->reiaTranzactia();
        usleep(self::PAUZA_MS * 1000);
        if ($randuri !== []) {
            foreach (array_keys($randuri[0]) as $coloana) {
                if (preg_match(self::TIPAR_PERSONAL, (string) $coloana) === 1) {
                    throw new InstructiuneRefuzata('coloana „' . preg_replace('/[^A-Za-z0-9_]/', '?', (string) $coloana) . '” are nume de date personale');
                }
            }
        }
        return $randuri;
    }

    /** Prima valoare a primului rând (null dacă nu e niciun rând). @param array<int|string, scalar|null> $parametri */
    public function valoare(string $sql, array $parametri = []): mixed
    {
        $randuri = $this->randuri($sql, $parametri);
        if ($randuri === []) {
            return null;
        }
        $prim = $randuri[0];
        return reset($prim);
    }

    /** @param array<int|string, scalar|null> $parametri */
    public function numar(string $sql, array $parametri = []): int
    {
        return (int) $this->valoare($sql, $parametri);
    }

    /**
     * Un grup de citiri, fiecare într-o tranzacție `WITH CONSISTENT SNAPSHOT, READ ONLY` a ei
     * (randuri() o reia după fiecare instrucțiune); ultima se închide imediat după grup
     * (ROLLBACK), orice ar fi: lacătele de metadate nu rămân ținute.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function inTranzactie(callable $fn): mixed
    {
        if ($this->inTranzactie) {
            return $fn();
        }
        $this->db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        $this->inTranzactie = true;
        try {
            return $fn();
        } finally {
            $this->inTranzactie = false;
            try {
                $this->db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
    }

    /**
     * Într-o tranzacție deschisă de inTranzactie: o închide și deschide alta (alt instantaneu),
     * ca lacătele de metadate ale tabelelor citite până aici să nu rămână ținute. randuri() o
     * cheamă după fiecare instrucțiune, deci și o parcurgere lungă ține lacătul doar cât o bucată.
     */
    public function reiaTranzactia(): void
    {
        if ($this->inTranzactie) {
            $this->db->exec('ROLLBACK');
            $this->db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        }
    }

    /** Tabelele și coloanele bazei (o singură citire din information_schema). @return array<string, array<string, true>> */
    public function structura(): array
    {
        if ($this->structura === null) {
            $this->structura = [];
            $randuri = $this->inTranzactie(fn (): array => $this->randuri(
                'SELECT TABLE_NAME AS tabel, COLUMN_NAME AS coloana FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() LIMIT 50000'));
            foreach ($randuri as $r) {
                $this->structura[(string) $r['tabel']][(string) $r['coloana']] = true;
            }
        }
        return $this->structura;
    }

    public function existaTabel(string $tabel): bool
    {
        return isset($this->structura()[$tabel]);
    }

    public function existaColoana(string $tabel, string $coloana): bool
    {
        return isset($this->structura()[$tabel][$coloana]);
    }

    /**
     * Parcurge un tabel pe bucăți, după `id`, fără să-l citească tot odată, fiecare instrucțiune
     * în instantaneul ei. Întâi mărimile (`LENGTH` pe coloanele cerute, pentru cel mult
     * $pePagina rânduri), apoi doar rândurile care încap într-o bucată de OCTETI_BUCATA (cel
     * puțin unul): memoria PHP ține o bucată, nu $pePagina texte lungi. Un rând peste
     * octetiCareIncap() (OCTETI_RAND, sau mai puțin dacă memoria PHP rămasă nu ajunge) nu se
     * citește (se numără în `prea_mari`). Se oprește după $maxim rânduri citite (rezultatul e
     * atunci parțial).
     * Coloanele cerute nu au voie să fie de date personale (le verifică randuri()).
     *
     * @param list<string> $coloane
     * @param callable(array<string, mixed>, int): void $fn primește rândul și id-ul lui
     * @param list<scalar|null> $parametri
     * @return array{randuri: int, partial: bool, prea_mari: int}
     */
    public function parcurge(string $tabel, array $coloane, string $conditie, array $parametri, callable $fn, int $pePagina = 100, int $maxim = 20000): array
    {
        foreach (array_merge([$tabel], $coloane) as $nume) {
            if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $nume) !== 1) {
                throw new InstructiuneRefuzata('nume de tabel sau coloană nepermis');
            }
        }
        $lista = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $coloane));
        $marime = implode(' + ', array_map(static fn (string $c): string => 'COALESCE(LENGTH(`' . $c . '`), 0)', $coloane));
        $unde = '(' . ($conditie !== '' ? $conditie : '1 = 1') . ')';
        $ultimul = 0;
        $citite = 0;
        $preaMari = 0;
        while (true) {
            $marimi = $this->randuri('SELECT `id` AS id_rand, ' . $marime . ' AS marime FROM `' . $tabel . '` WHERE ' . $unde
                . ' AND `id` > ? ORDER BY `id` LIMIT ' . $pePagina, array_merge($parametri, [$ultimul]));
            $alese = [];
            $suma = 0;
            $intrerupt = false;
            $incape = self::octetiCareIncap();
            $bucata = min(self::OCTETI_BUCATA, $incape);
            foreach ($marimi as $m) {
                $octeti = (int) $m['marime'];
                if ($octeti > $incape) {
                    $preaMari++;
                    $ultimul = (int) $m['id_rand'];
                    continue;
                }
                if ($alese !== [] && $suma + $octeti > $bucata) {
                    $intrerupt = true;
                    break;
                }
                $alese[] = (int) $m['id_rand'];
                $suma += $octeti;
                $ultimul = (int) $m['id_rand'];
            }
            if ($alese !== []) {
                // Aceeași condiție și aceeași limită de mărime: un rând schimbat între timp nu trece de ea.
                $randuri = $this->randuri('SELECT `id` AS id_rand, ' . $lista . ' FROM `' . $tabel . '` WHERE ' . $unde . ' AND `id` IN ('
                    . implode(', ', array_fill(0, count($alese), '?')) . ') AND ' . $marime . ' <= ' . $incape . ' ORDER BY `id` LIMIT ' . count($alese),
                    array_merge($parametri, $alese));
                foreach ($randuri as $r) {
                    $id = (int) $r['id_rand'];
                    unset($r['id_rand']);
                    $fn($r, $id);
                    if (++$citite >= $maxim) {
                        return ['randuri' => $citite, 'partial' => true, 'prea_mari' => $preaMari];
                    }
                }
                unset($randuri);
            }
            if (!$intrerupt && count($marimi) < $pePagina) {
                return ['randuri' => $citite, 'partial' => false, 'prea_mari' => $preaMari];
            }
        }
    }
}

// ───────────────────────────────────────────────────────────────────────────
// Ieșirea: doar valori care se potrivesc tiparului lor
// ───────────────────────────────────────────────────────────────────────────

final class Raport
{
    /** Tiparele valorilor tipărite. Nimic altceva nu ajunge pe ecran. */
    public const TIPARE = [
        'cheie' => '/^[A-Za-z0-9_.:\/-]{1,160}$/',
        'id' => '/^[0-9]{1,10}$/',
        'slug' => '/^[a-z0-9](?:[a-z0-9_-]|\/(?!\/)){0,190}$/',
        'nume' => '/^[A-Za-z0-9_$.-]{1,190}$/',
        'clasa' => '/^-?[_a-zA-Z][_a-zA-Z0-9-]{0,63}$/',
        'hex' => '/^[0-9a-f]{7,64}$/',
        'ref' => '/^[A-Za-z0-9._\/-]{1,120}$/',
        'luna' => '/^[0-9]{4}-[0-9]{2}$/',
        'versiune' => '/^[A-Za-z0-9.+_~:-]{1,80}$/',
        'cale' => '/^[A-Za-z0-9._\/-]{1,200}$/',
    ];

    public const STARI = ['OK', 'INFO', 'ATENȚIE', 'LIPSĂ', 'EROARE', 'SĂRIT'];

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> [stare, cheie, valoare, descriere]; stare '==' = titlu */
    private array $randuri = [];
    /** @var array<string, int> */
    private array $numarate = ['OK' => 0, 'INFO' => 0, 'ATENȚIE' => 0, 'LIPSĂ' => 0, 'EROARE' => 0, 'SĂRIT' => 0];

    public static function potrivit(string $tip, string $valoare): bool
    {
        return isset(self::TIPARE[$tip]) && preg_match(self::TIPARE[$tip], $valoare) === 1;
    }

    private static function cere(string $tip, string $valoare): string
    {
        if (!self::potrivit($tip, $valoare)) {
            throw new ValoareRefuzata($tip);
        }
        return $valoare;
    }

    public function titlu(string $text): void
    {
        $this->randuri[] = ['==', '', '', $text];
    }

    private function adauga(string $stare, string $cheie, string $valoare, string $descriere): void
    {
        $this->randuri[] = [$stare, self::cere('cheie', $cheie), $valoare, $descriere];
        $this->numarate[$stare]++;
    }

    /** Un număr; cu $asteptat, OK dacă e egal, ATENȚIE altfel. */
    public function numar(string $cheie, int $valoare, string $descriere = '', ?int $asteptat = null): void
    {
        $this->adauga($asteptat === null ? 'INFO' : ($valoare === $asteptat ? 'OK' : 'ATENȚIE'), $cheie, (string) $valoare, $descriere);
    }

    public function daNu(string $cheie, bool $valoare, string $descriere = '', ?bool $asteptat = null): void
    {
        $this->adauga($asteptat === null ? 'INFO' : ($valoare === $asteptat ? 'OK' : 'ATENȚIE'), $cheie, $valoare ? 'DA' : 'NU', $descriere);
    }

    /** @param list<string> $permise */
    public function alegere(string $cheie, string $valoare, array $permise, string $descriere = ''): void
    {
        if (!in_array($valoare, $permise, true)) {
            throw new ValoareRefuzata('alegere');
        }
        $this->adauga('INFO', $cheie, $valoare, $descriere);
    }

    /** Un nume de structură, o versiune, un commit, o lună… (tip din TIPARE). */
    public function nume(string $cheie, string $valoare, string $tip, string $descriere = ''): void
    {
        $this->adauga('INFO', $cheie, self::cere($tip, $valoare), $descriere);
    }

    /** @param list<string|int> $valori */
    public function lista(string $cheie, array $valori, string $tip, string $descriere = '', int $maxim = 300): void
    {
        $text = [];
        foreach (array_slice($valori, 0, $maxim) as $valoare) {
            $text[] = self::cere($tip, (string) $valoare);
        }
        $rest = count($valori) - count($text);
        $this->adauga('INFO', $cheie, '[' . count($valori) . '] ' . implode(', ', $text) . ($rest > 0 ? ' … (+' . $rest . ')' : ''), $descriere);
    }

    /** Un tabel sau o coloană de care are nevoie verificarea lipsește (site-ul nu are funcția). */
    public function lipsa(string $cheie, string $obiect): void
    {
        $this->adauga('LIPSĂ', $cheie, preg_match('/^(tabelul|coloana) [A-Za-z0-9_.]{1,130}$/', $obiect) === 1 ? $obiect : 'un obiect', '');
    }

    public function eroare(string $cheie, string $motiv): void
    {
        $this->adauga('EROARE', preg_match(self::TIPARE['cheie'], $cheie) === 1 ? $cheie : 'verificare', $motiv, '');
    }

    public function sarit(string $cheie, string $motiv): void
    {
        $this->adauga('SĂRIT', $cheie, $motiv, '');
    }

    /** @return array<string, int> */
    public function numarate(): array
    {
        return $this->numarate;
    }

    /** Rândurile, ca text: „  STARE    cheie: valoare  — descriere”, listele lungi pe mai multe rânduri. */
    public function text(): string
    {
        $text = '';
        foreach ($this->randuri as [$stare, $cheie, $valoare, $descriere]) {
            if ($stare === '==') {
                $text .= "\n== " . $descriere . "\n";
                continue;
            }
            $rand = '  ' . str_pad($stare, 9 + strlen($stare) - mb_strlen($stare)) . $cheie . ': ';
            $continuare = str_repeat(' ', 11 + 4);
            $bucati = mb_strlen($valoare) > 100 ? explode(', ', $valoare) : [$valoare];
            $linie = $rand;
            $prima = true;
            foreach ($bucati as $i => $bucata) {
                $adaos = ($prima ? '' : ', ') . $bucata;
                if (!$prima && mb_strlen($linie) + mb_strlen($adaos) > 110) {
                    $text .= $linie . ",\n";
                    $linie = $continuare . $bucata;
                } else {
                    $linie .= $adaos;
                }
                $prima = false;
            }
            $text .= $linie . ($descriere !== '' ? '  — ' . $descriere : '') . "\n";
        }
        return $text;
    }
}

/** Ce primește fiecare verificare dintr-un set. */
final class Context
{
    public function __construct(public readonly CitireLive $citire, public readonly Raport $raport, public readonly SiteLive $site)
    {
    }

    /**
     * Tabelele și coloanele de care are nevoie o verificare ('orders', 'orders.erp_status');
     * dacă lipsește unul, scrie LIPSĂ și întoarce false.
     *
     * @param list<string> $obiecte
     */
    public function cere(string $cheie, array $obiecte): bool
    {
        foreach ($obiecte as $obiect) {
            [$tabel, $coloana] = array_pad(explode('.', $obiect, 2), 2, null);
            if (!$this->citire->existaTabel((string) $tabel)) {
                $this->raport->lipsa($cheie, 'tabelul ' . $tabel);
                return false;
            }
            if ($coloana !== null && !$this->citire->existaColoana((string) $tabel, $coloana)) {
                $this->raport->lipsa($cheie, 'coloana ' . $tabel . '.' . $coloana);
                return false;
            }
        }
        return true;
    }

    /** Toate obiectele există (fără să scrie nimic)? @param list<string> $obiecte */
    public function are(array $obiecte): bool
    {
        foreach ($obiecte as $obiect) {
            [$tabel, $coloana] = array_pad(explode('.', $obiect, 2), 2, null);
            if (!$this->citire->existaTabel((string) $tabel) || ($coloana !== null && !$this->citire->existaColoana((string) $tabel, $coloana))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Un număr dintr-o interogare, dacă există obiectele cerute.
     *
     * @param list<string> $cere
     * @param array<int|string, scalar|null> $parametri
     */
    public function numar(string $cheie, array $cere, string $sql, array $parametri = [], string $descriere = '', ?int $asteptat = null): ?int
    {
        if (!$this->cere($cheie, $cere)) {
            return null;
        }
        $n = $this->citire->numar($sql, $parametri);
        $this->raport->numar($cheie, $n, $descriere, $asteptat);
        return $n;
    }

    /**
     * Numere pe grupuri: interogarea întoarce (g, n). Un grup gol devine „gol”, unul care nu e un
     * cuvânt scurt (litere mici, cifre, _ -) intră la „alta_valoare”.
     *
     * @param list<string> $cere
     * @param array<int|string, scalar|null> $parametri
     */
    public function grupuri(string $prefix, array $cere, string $sql, array $parametri = [], string $descriere = ''): void
    {
        if (!$this->cere($prefix, $cere)) {
            return;
        }
        $grupuri = [];
        foreach ($this->citire->randuri($sql, $parametri) as $r) {
            $g = strtolower(trim((string) ($r['g'] ?? '')));
            $g = $g === '' ? 'gol' : (preg_match('/^[a-z0-9_.-]{1,40}$/', $g) === 1 ? $g : 'alta_valoare');
            $grupuri[$g] = ($grupuri[$g] ?? 0) + (int) $r['n'];
        }
        ksort($grupuri);
        if ($grupuri === []) {
            $this->raport->numar($prefix . '.total', 0, $descriere);
            return;
        }
        foreach ($grupuri as $g => $n) {
            $this->raport->numar($prefix . '.' . $g, $n, $descriere);
        }
    }

    /**
     * Pentru câteva chei de setări: există rândul, are valoare, e „1” (calculate în SQL; valorile
     * nu se citesc).
     *
     * @param list<string> $chei
     * @return array<string, array{exista: bool, nevida: bool, unu: bool}>
     */
    public function setari(array $chei): array
    {
        $rezultat = [];
        foreach ($chei as $cheie) {
            $rezultat[$cheie] = ['exista' => false, 'nevida' => false, 'unu' => false];
        }
        if ($chei === [] || !$this->are(['settings.key', 'settings.value'])) {
            return $rezultat;
        }
        $randuri = $this->citire->randuri('SELECT `key` AS k, (TRIM(COALESCE(`value`, \'\')) <> \'\') AS nevida, (TRIM(COALESCE(`value`, \'\')) = \'1\') AS unu
            FROM settings WHERE `key` IN (' . implode(', ', array_fill(0, count($chei), '?')) . ') LIMIT 1000', array_values($chei));
        foreach ($randuri as $r) {
            $rezultat[(string) $r['k']] = ['exista' => true, 'nevida' => (int) $r['nevida'] === 1, 'unu' => (int) $r['unu'] === 1];
        }
        return $rezultat;
    }
}

// ───────────────────────────────────────────────────────────────────────────
// Rularea
// ───────────────────────────────────────────────────────────────────────────

final class Rulare
{
    /** Ce scrie unealta dacă o eroare fatală o oprește (primește motivul, întoarce codul de ieșire). */
    private static ?\Closure $laOprire = null;

    /** @param (\Closure(string): int)|null $fn */
    public static function laOprire(?\Closure $fn): void
    {
        self::$laOprire = $fn;
    }

    /**
     * Chemată de lib/intrare.php după o eroare fatală (memoria, timpul): $motiv e un text fix
     * (felul erorii și locul ei în codul uneltelor, fără mesajul PHP-ului). Scrie ce a strâns
     * unealta până acolo (laOprire) și întoarce codul de ieșire: 1 dacă s-a citit ceva, 2 dacă nu.
     */
    public static function opritDeEroare(string $motiv): int
    {
        // Memoria poate fi la capăt: încă puțină, cât să se scrie ce s-a strâns.
        @ini_set('memory_limit', (string) (memory_get_usage(true) + 64 * 1048576));
        $fn = self::$laOprire;
        self::$laOprire = null;
        if ($fn !== null) {
            try {
                return $fn($motiv);
            } catch (Throwable) {
            }
        }
        fwrite(STDERR, 'OPRIT: ' . $motiv . '; mesajul PHP nu se tipărește (are calea contului). Nimic scris.' . "\n");
        return $fn !== null ? 1 : 2;
    }

    /**
     * Argumentele „--nume=valoare” și „--nume”, cu lista celor permise.
     *
     * @param list<string> $argv
     * @param list<string> $permise
     * @return array<string, string|true>
     */
    public static function argumente(array $argv, array $permise): array
    {
        $rezultat = [];
        foreach (array_slice($argv, 1) as $argument) {
            if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $argument, $m) !== 1 || !in_array($m[1], $permise, true)) {
                throw new Refuz('argument necunoscut (vezi capul fișierului): ' . (preg_match('/^[A-Za-z0-9=._\/-]{1,60}$/', $argument) === 1 ? $argument : '?'));
            }
            $rezultat[$m[1]] = array_key_exists(2, $m) ? $m[2] : true;
        }
        return $rezultat;
    }

    /** Nicio sesiune PHP pornită (session.auto_start): altfel s-ar scrie un fișier de sesiune. */
    public static function faraSesiune(): void
    {
        if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
            @session_abort();
            throw new Refuz('PHP-ul a pornit singur o sesiune (session.auto_start); rulează cu -d session.auto_start=0');
        }
        if (function_exists('proc_nice')) {
            @proc_nice(10);
        }
    }

    /**
     * Scrie rezultatul în storage/verificare/sonde/ al site-ului de verificare (`Verificare::dosar()`, în afara
     * git-ului și a site-ului public), doar pentru proprietar (0600). Întoarce calea, scrisă față de dosarul site-ului.
     */
    public static function salveaza(string $nume, string $continut): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,120}\.(txt|json)$/', $nume) !== 1) {
            throw new Refuz('nume de fișier de rezultat nepermis');
        }
        $umask = umask(0077);
        try {
            // Dosarul de lucru, cu gardul .htaccess (Paths::stocareSite), apoi sonde/ doar pentru proprietar.
            $dosar = \App\Support\Verificare::dosar() . '/sonde';
            if (!is_dir($dosar) && !@mkdir($dosar, 0700, true) && !is_dir($dosar)) {
                throw new Refuz('nu pot face dosarul storage/verificare/sonde');
            }
            $cale = $dosar . '/' . $nume;
            if (@file_put_contents($cale, $continut) === false) {
                throw new Refuz('nu pot scrie rezultatul în storage/verificare/sonde');
            }
            @chmod($cale, 0600);
        } finally {
            umask($umask);
        }
        return 'storage/verificare/sonde/' . $nume;
    }
}
