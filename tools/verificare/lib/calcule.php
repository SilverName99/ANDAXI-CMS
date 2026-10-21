<?php

declare(strict_types=1);

/**
 * Comparația calculelor (golden master, WP-11, G2; docs/VERIFICARE.md, „Comparația calculelor”): pentru aceleași comenzi
 * și aceeași bază, codul vechi al site-ului și codul comun trebuie să calculeze aceleași sume. Partea lui
 * tools/verificare/compara-calcule.php (orchestratorul); fiecare cod rulează în procesul lui, tools/verificare/lucrator-calcule.php.
 *
 * Nu e o comparație cu totalurile salvate: sumarul coșului citește prețurile, perioadele de reducere și cupoanele de
 * acum, deci un total de acum o lună poate diferi pe drept. Potrivirea cu totalul salvat e doar informativă.
 *
 * Unde rulează (niciodată pe o bază live: ambele coduri trimit DDL chiar în calculul verificat; nu există nicio opțiune
 * care să permită așa ceva):
 * - **pe site-ul de verificare** (implicit), de proprietar, noaptea, după încărcare: `.env` cu `SITE_COPIE_VERIFICARE=1`,
 *   din `~/domains/<verificare>/public_html` (`Locul::verificare`), baza nescrisă în `.env`-ul altui site din cont
 *   (`Cont::bazaAltuiSite`), copia încărcată și neexpirată (`storage/verificare/incarcat-la` și `_verificare`:
 *   `anonimizat = 1`, sursa = profilul, `incarcat_la` în ultimele `VERIFICARE_ZILE_PASTRARE` zile), codul vechi în
 *   `~/domains/<verificare>/cod-vechi/<profil>/` (scris de încărcare, fără legături), cu `.env`-ul lui spre aceeași bază;
 *   cu baza în urma codului comun (cod nou urcat după încărcare) rulează întâi `scripts/migreaza.php --aplica
 *   --site=<SITE_SLUG>` (planul de verificare, §2.3, „Ordering”); altă stare a gărzii (fără registru, eroare) e refuz;
 * - **local** (`--local`, poarta G-calc: tools/dev/compara-calcule-local.sh), doar cu uneltele de dezvoltare: fără `.env`
 *   în niciunul dintre coduri, `CMS_DEV_DIR` în mediu, o bază `cms_*`, fără `SITE_COPIE_VERIFICARE=1`; codul vechi dat
 *   cu `--cod-vechi=` (copia commit-ului revizuit al Bioscem), codul comun cel de aici sau `--cod-nou=` (o copie, pentru
 *   mutațiile testului); baza la zi cu migrările.
 * Fiecare lucrător verifică din nou, după ce codul lui s-a legat la bază, că e chiar baza așteptată (aceeași copie de
 * verificare, același rând `_verificare`; local, același nume `cms_*`), înaintea oricărui cod al aplicației.
 *
 * Cazurile (o singură interogare, pe baza copiei): ultimele `--n` comenzi (implicit 500; `deleted_at IS NULL`, fără cele
 * `failed`), plus fiecare comandă din ultimele 12 luni cu cupon, puncte, o linie cu ofertă BBD sau FANbox (cel mult 300).
 * Lucrătorilor ajung doar câmpurile de care are nevoie coșul (produsele, cantitățile, cheile BBD, cuponul, clientul,
 * punctele, județul și localitatea de facturare, FANbox) și cele ale comenzii salvate pentru recalculul din admin.
 *
 * Cupoanele (`--cupoane-valide`, implicit; `--fara-cupoane-valide` le lasă cum sunt): cele folosite de cazuri devin
 * valabile pe durata comparației (`is_active = 1`, fără `starts_at`/`ends_at`/`max_uses_total`/`used_at`), altfel
 * cupoanele vechi ar fi expirate în ambele coduri și reducerea nu s-ar mai calcula deloc. Doar pe baza copiei; valorile de
 * dinainte se țin într-un fișier 0600 (`calcule-cupoane-<amprenta bazei>.json` din dosarul temporar) și se pun la loc la
 * final, verificat; o rulare oprită între ele (procesul omorât) le pune la loc la începutul rulării următoare.
 *
 * Ce iese: doar numere, nume de câmpuri, tipuri de caz (cupon_procent, cupon_fix, cupon_inexistent, puncte, bbd, fanbox,
 * firma, judet_bucuresti, simplu), nume de chei ale payload-ului ERP și locuri din cod. Niciodată un id, un număr de
 * comandă, un nume, o sumă sau un produs (decizia D10: nici un export pe caz, nici anonimizat; o diferență se reproduce
 * pe datele demo, după tipul cazului). Textul se salvează și în `storage/verificare/sonde/calcule-<profil>-<data>.txt`
 * (local: `$CMS_DEV_DIR/calcule-raport.txt`).
 *
 * Diferențele voite (doar local, `--declarate=<fișier.json>`, ca listele `$porturiBioscem` ale comparației paginilor): o
 * schimbare făcută intenționat în codul comun (de exemplu transportul gratuit pe produs din WP-20, pe o comandă demo cu un
 * astfel de produs) se declară pe comanda ei, cu toate câmpurile care diferă (`shipping`, `total`, `linie.price`,
 * `erp.<cheie>` …, exact, nici mai multe, nici mai puține) și motivul. Un caz declarat care diferă exact la câmpurile lui
 * se numără „declarat”, nu „diferit”; o declarație nepotrivită (alte câmpuri, nicio diferență, o comandă care nu e caz, o
 * eroare) face comparația să pice, ca o diferență nedeclarată. Pe site-ul de verificare nu există declarații: acolo orice
 * diferență pică.
 *
 * Cod de ieșire: 0 „REZULTAT: TRECE” (niciun caz diferit nedeclarat, nicio declarație nepotrivită, nicio eroare în niciun
 * cod); 1 „REZULTAT: PICĂ” (sau o oprire după ce cupoanele au fost schimbate, puse la loc); 2 refuzat, nimic schimbat.
 *
 * Procesele copil (lucrătorii, `scripts/migreaza.php`) primesc aceleași extensii ca procesul comparației: unde PHP-ul lor
 * pornit simplu nu încarcă una (pe Hostinger, sodium în /opt/alt/php83, pornit de proprietar cu `$PHP`, adică cu
 * `-d extension=sodium.so`), primesc `-d extension=<nume>.so` (o sondă pe PHP, lib/php-cli.php), iar textul spune într-un rând ce
 * s-a adăugat.
 *
 * Sintaxa e PHP 8.3 (php_min); punctul de intrare verifică versiunea cu sintaxă PHP 7.0.
 */

namespace Unelte\Verificare;

use PDO;
use Throwable;

// Extensiile proceselor copil (lucrătorii, migrările): aceleași ca ale comenzii, și unde PHP_BINARY singur nu le încarcă.
require_once __DIR__ . '/php-cli.php';

final class ComparaCalcule
{
    /** Profilurile cu reguli de calcul (Biovitality în WP-11b: Stripe, numerotarea BV, transportul gratuit, pachetele excluse). */
    public const PROFILURI = ['bioscem'];

    /** Diferența peste care două sume rotunjite la 2 zecimale sunt altele. */
    public const TOLERANTA = 0.005;

    /** Câte comenzi speciale (cupon, puncte, BBD, FANbox, ultimele 12 luni) se adaugă celor mai noi. */
    public const SPECIALE_MAXIM = 300;

    /** Câmpurile sumarului coșului (CheckoutCalculator::buildSummary) comparate. */
    public const CAMPURI = ['subtotal', 'subtotal_without_vat', 'discount', 'points_discount', 'vat', 'vat_additional', 'shipping', 'total'];

    /** Câmpurile fiecărei linii din sumar comparate. */
    public const CAMPURI_LINIE = ['price', 'line_total', 'vat_value', 'coupon_discount', 'line_total_after_coupon'];

    /** Câmpurile de dincolo de sumar: recalculul transportului din admin și punctele promise. */
    public const CAMPURI_ALTE = ['transport_admin', 'transport_admin_0', 'puncte_estimate', 'puncte_estimate_salvat'];

    /** Câmpurile de dincolo de sumar care pot fi declarate (`--declarate`) ca diferențe voite, pe lângă CAMPURI, CAMPURI_ALTE și `erp.<cheie>`. */
    private const CAMPURI_DECLARABILE = ['cupon_ok', 'puncte_aplicate', 'linii'];

    /** Coloanele cupoanelor schimbate pe durata comparației (și puse la loc). */
    private const COLOANE_CUPON = ['is_active', 'starts_at', 'ends_at', 'max_uses_total', 'used_at'];

    /** Cât poate dura un lucrător (secunde). */
    private const TERMEN_LUCRATOR = 3600;

    /** @var list<string> */
    private array $text = [];

    private string $mod = 'verificare';
    private string $profil = 'bioscem';
    private string $radacina;
    private string $codNou;
    private string $codVechi = '';
    private string $phpNou;
    private string $phpVechi;
    private string $baza = '';
    private string $sursa = '';
    private string $incarcatLa = '';
    private string $dosarTmp = '';
    private bool $prinFisiere = false;
    private ?PDO $db = null;
    /** @var array<string, mixed>|null Cupoanele de dinainte (pentru punerea la loc). */
    private ?array $cupoaneOriginale = null;
    private string $fisierCupoane = '';
    /** @var list<array{comanda: int, campuri: list<string>, motiv: string}>|null Diferențele voite (--declarate, doar local). */
    private ?array $declarate = null;
    /** @var array<string, list<string>> argumentele `-d extension=…` ale fiecărui PHP copil, după sonda lui (o dată pe PHP) */
    private array $extensiiCopil = [];

    private function __construct(string $radacina)
    {
        $this->radacina = $radacina;
        $this->codNou = $radacina;
        $this->phpNou = PHP_BINARY;
        $this->phpVechi = PHP_BINARY;
    }

    /** @param list<string> $argv */
    public static function ruleaza(array $argv, string $radacina): int
    {
        $c = new self((string) realpath($radacina));
        try {
            return $c->ruleazaIntern($argv);
        } catch (Refuz $e) {
            if ($c->cupoaneOriginale !== null) {
                // Un refuz venit după ce cupoanele s-au schimbat (de exemplu un lucrător care refuză abia la calcule): oprire,
                // nu refuz, cu cupoanele puse la loc.
                $pus = $c->puneLaLocCupoanele();
                $c->spune('OPRIT: ' . $e->getMessage() . ($pus ? '; cupoanele au fost puse la loc' : '; cupoanele NU s-au putut pune la loc: rulează din nou comanda'));
                return 1;
            }
            $c->spune('REFUZAT: ' . $e->getMessage() . '. Nu am schimbat nimic.');
            return 2;
        } catch (Throwable $e) {
            $avea = $c->cupoaneOriginale !== null;
            $pus = $c->puneLaLocCupoanele();
            $c->spune('OPRIT: eroare neașteptată (' . get_class($e) . ' în ' . self::locul($e) . ')'
                . ($avea ? ($pus ? '; cupoanele au fost puse la loc' : '; cupoanele NU s-au putut pune la loc: rulează din nou comanda') : '; nu am schimbat nimic'));
            return $avea ? 1 : 2;
        }
    }

    /** @param list<string> $argv */
    private function ruleazaIntern(array $argv): int
    {
        Rulare::faraSesiune();
        // O eroare fatală PHP (memoria, timpul; lib/intrare.php): cupoanele schimbate se pun la loc, apoi „OPRIT”.
        Rulare::laOprire(function (string $motiv): int {
            $avea = $this->cupoaneOriginale !== null;
            $pus = $this->puneLaLocCupoanele();
            $this->spune('OPRIT: ' . $motiv . '; mesajul PHP nu se tipărește' . ($avea ? ($pus ? '; cupoanele au fost puse la loc'
                : '; cupoanele NU s-au putut pune la loc: rulează din nou comanda (le pune la loc la început)') : '; nu am schimbat nimic'));
            return $avea ? 1 : 2;
        });
        $opt = Rulare::argumente($argv, ['local', 'profil', 'cod-vechi', 'cod-nou', 'php-vechi', 'n', 'fara-cupoane-valide', 'prin-fisiere', 'declarate']);
        $this->profil = is_string($opt['profil'] ?? null) ? (string) $opt['profil'] : 'bioscem';
        if (!in_array($this->profil, self::PROFILURI, true)) {
            throw new Refuz('profil necunoscut' . (preg_match('/^[a-z]{1,20}$/', $this->profil) === 1 ? ' (' . $this->profil . ')' : '')
                . '; azi doar --profil=bioscem (Biovitality vine în WP-11b)');
        }
        $n = isset($opt['n']) ? (string) $opt['n'] : '500';
        if (preg_match('/^[1-9][0-9]{0,4}$/', $n) !== 1 || (int) $n > 20000) {
            throw new Refuz('--n trebuie să fie un număr între 1 și 20000');
        }
        $this->prinFisiere = isset($opt['prin-fisiere']);
        $cupoaneValide = !isset($opt['fara-cupoane-valide']);

        if (isset($opt['declarate'])) {
            if (!isset($opt['local'])) {
                throw new Refuz('--declarate e doar pentru testul local (--local): pe site-ul de verificare orice diferență pică');
            }
            $this->declarate = self::citesteDeclarate(is_string($opt['declarate']) ? $opt['declarate'] : '');
        }
        if (isset($opt['local'])) {
            $this->pregatesteLocal($opt);
        } else {
            foreach (['cod-vechi', 'cod-nou'] as $doarLocal) {
                if (isset($opt[$doarLocal])) {
                    throw new Refuz('--' . $doarLocal . ' e doar pentru testul local (--local); pe site-ul de verificare codul vechi e cel copiat de '
                        . 'încărcare, în cod-vechi/' . $this->profil . '/');
                }
            }
            $this->pregatesteVerificare();
        }
        if (isset($opt['php-vechi'])) {
            $this->phpVechi = self::phpDat((string) $opt['php-vechi']);
        }
        $versiuneVeche = $this->versiunePhp($this->phpVechi);
        if ($versiuneVeche === null || version_compare($versiuneVeche, '8.0', '<')) {
            throw new Refuz('PHP-ul codului vechi (--php-vechi) nu pornește sau e mai vechi de 8.0');
        }

        $this->conecteaza();
        $this->spune('Comparația calculelor (WP-11): codul vechi al site-ului față de codul comun, pe aceeași bază, aceleași comenzi');
        $commitNou = self::commit($this->codNou);
        $this->spune('  codul vechi: ' . ($this->mod === 'local' ? basename($this->codVechi) : 'cod-vechi/' . $this->profil . '/ (lângă public_html)'
            . ($this->commitSursa() !== null ? ', commit ' . $this->commitSursa() : '')) . ', PHP ' . $versiuneVeche);
        $this->spune('  codul comun: ' . ($this->codNou !== $this->radacina ? basename($this->codNou) . ', ' : '')
            . ($commitNou !== null ? 'commit ' . substr($commitNou, 0, 7) : 'fără .git') . ', PHP ' . PHP_VERSION);
        $this->spune('  baza: ' . ($this->mod === 'local' ? 'o bază cms_* a uneltelor de dezvoltare (date demo)'
            : 'copia de verificare ' . $this->profil . ', încărcată la ' . substr($this->incarcatLa, 0, 16) . ' UTC, anonimizată'));
        $this->verificaMigrarile();
        $this->puneLaLocCupoaneleRamase();

        // ── Cazurile ──
        $cazuri = $this->cazuri((int) $n);
        if ($cazuri === []) {
            throw new Refuz('nicio comandă de comparat (baza n-are comenzi care să nu fie șterse sau eșuate)');
        }

        // ── Lucrătorii: întâi doar garda și versiunile ──
        $sesiuniInainte = $this->sesiuni();
        $vechi = $this->lucrator('vechi', ['faza' => 'verifica']);
        $nou = $this->lucrator('nou', ['faza' => 'verifica']);
        $oprite = $nou['primul']['pluginuri_oprite'] ?? null;
        if (!is_array($oprite)) {
            throw new Refuz('codul comun (--cod-nou) nu are catalogul pluginurilor: nu e codul comun');
        }
        if ($oprite !== []) {
            throw new Refuz('pe codul comun nu sunt pornite toate pluginurile site-ului: ' . implode(', ', array_map(
                static fn ($c): string => preg_match('/^[a-z_]{1,40}$/', (string) $c) === 1 ? (string) $c : '?', $oprite))
                . ($this->mod === 'local' ? ' (tools/dev/php.sh tools/dev/licenta.php toate)' : ' (tools/verificare/licenta.php ' . $this->profil . ')'));
        }
        $this->spune('  pluginurile site-ului pe codul comun: toate pornite');
        $this->spune('  ' . self::textSetari((array) ($vechi['primul']['setari'] ?? []), (array) ($nou['primul']['setari'] ?? [])));

        // ── Cupoanele cazurilor, valabile pe durata comparației ──
        if ($cupoaneValide) {
            $numar = $this->cupoaneValide($cazuri);
            $this->spune('  cupoane: ' . $numar . ' făcute valabile pe durata comparației (--fara-cupoane-valide le lasă cum sunt)');
            $oprireTest = $this->mod === 'local' ? (string) getenv('CALCULE_TEST_OPRESTE_DUPA_CUPOANE') : '';
            if ($oprireTest === '1') {
                // Doar testul local (tools/dev/test-calcule.php): o rulare omorâtă aici, fără punerea la loc.
                $this->cupoaneOriginale = null;
                $this->spune('OPRIT: oprirea testului după cupoane (CALCULE_TEST_OPRESTE_DUPA_CUPOANE)');
                return 3;
            }
            if ($oprireTest === 'memorie') {
                // Tot doar testul: o eroare fatală (memoria) cu cupoanele schimbate; lib/intrare.php → laOprire le pune la loc.
                ini_set('memory_limit', (string) (memory_get_usage(true) + 4 * 1048576));
                $this->text[] = str_repeat('x', 64 * 1048576);
            }
        } else {
            $this->spune('  cupoane: lăsate cum sunt (--fara-cupoane-valide)');
        }

        // ── Calculele, pe rând: codul vechi, apoi codul comun ──
        try {
            $vechi = $this->lucrator('vechi', ['faza' => 'calcule', 'cazuri' => array_values($cazuri)], count($cazuri));
            $nou = $this->lucrator('nou', ['faza' => 'calcule', 'cazuri' => array_values($cazuri)], count($cazuri));
        } finally {
            $pus = $this->puneLaLocCupoanele();
        }
        if ($cupoaneValide) {
            if (!$pus) {
                throw new \RuntimeException('cupoanele nu s-au putut pune la loc');
            }
            $this->spune('  cupoane puse la loc la final: DA');
        }
        $sesiuniLasate = $this->stergeSesiunileNoi($sesiuniInainte);

        return $this->raport($cazuri, $vechi, $nou, $sesiuniLasate);
    }

    // ───────────────────────────────────────────────────────────
    // Locul și baza
    // ───────────────────────────────────────────────────────────

    /** @param array<string, string|true> $opt */
    private function pregatesteLocal(array $opt): void
    {
        $this->mod = 'local';
        if (trim((string) getenv('CMS_DEV_DIR')) === '') {
            throw new Refuz('--local rulează doar cu uneltele de dezvoltare (tools/dev/php.sh, tools/dev/compara-calcule-local.sh): CMS_DEV_DIR lipsește');
        }
        if (trim((string) getenv('SITE_COPIE_VERIFICARE')) === '1') {
            throw new Refuz('--local nu rulează cu SITE_COPIE_VERIFICARE=1 (pe site-ul de verificare comanda e fără --local)');
        }
        $this->baza = trim((string) getenv('DB_DATABASE'));
        if (preg_match('/^cms_[a-z0-9_]+$/', $this->baza) !== 1) {
            throw new Refuz('--local doar pe o bază cms_* (DB_DATABASE din mediu)');
        }
        $gazda = strtolower(trim((string) getenv('DB_HOST')));
        if (!in_array($gazda, ['', 'localhost', '127.0.0.1', '::1'], true)) {
            throw new Refuz('--local doar pe serverul de baze de date al calculatorului (DB_HOST localhost)');
        }
        if (!isset($opt['cod-vechi']) || !is_string($opt['cod-vechi'])) {
            throw new Refuz('--local cere --cod-vechi=<dosar> (copia commit-ului revizuit al Bioscem; o face tools/dev/compara-calcule-local.sh)');
        }
        $this->codVechi = self::dosarCod((string) $opt['cod-vechi'], 'codul vechi');
        if (isset($opt['cod-nou'])) {
            $this->codNou = self::dosarCod((string) $opt['cod-nou'], 'codul comun');
        }
        foreach ([$this->radacina, $this->codVechi, $this->codNou] as $dosar) {
            if (file_exists($dosar . '/.env')) {
                throw new Refuz('un cod are .env (' . basename($dosar) . '/.env): local baza vine doar din mediul uneltelor de dezvoltare');
            }
        }
        if ($this->codVechi === $this->codNou) {
            throw new Refuz('codul vechi și codul comun sunt același dosar');
        }
        $this->dosarTmp = rtrim((string) getenv('CMS_DEV_DIR'), '/') . '/calcule-tmp';
        $this->incarcaCodul();
    }

    /** bootstrap.php al codului comun (o dată): în linia de comandă, fără sesiune; .env-ul copiei sau, local, mediul. */
    private function incarcaCodul(): void
    {
        require_once $this->radacina . '/bootstrap.php';
    }

    private function pregatesteVerificare(): void
    {
        $this->mod = 'verificare';
        [$acasa] = Locul::verificare($this->radacina);
        // Codul comun, ca linie de comandă (fără sesiune), cu .env-ul copiei: de aici încolo Env::get îl vede.
        $this->incarcaCodul();
        $env = Locul::citesteEnv(\App\Support\Verificare::caleEnv());
        $this->baza = trim((string) ($env['DB_DATABASE'] ?? ''));
        if ($this->baza === '') {
            throw new Refuz('.env nu are DB_DATABASE (configureaza.php)');
        }
        $gazdaDb = (string) ($env['DB_HOST'] ?? 'localhost');
        $alt = Cont::bazaAltuiSite($acasa, $this->baza, $gazdaDb);
        if ($alt !== null) {
            throw new Refuz('baza din .env e a altui site din cont (' . (preg_match('/^[A-Za-z0-9._~\/() -]{1,120}$/', $alt) === 1 ? $alt : '?')
                . '); comparația rulează doar pe copia de verificare');
        }
        $slug = trim((string) ($env['SITE_SLUG'] ?? ''));
        if ($slug !== $this->profil) {
            throw new Refuz('pe copie e pachetul altui site (SITE_SLUG din .env nu e ' . $this->profil . '): încarcă întâi copia '
                . $this->profil . ' (incarca.php --din=' . $this->profil . ')');
        }
        $copie = \App\Support\Verificare::stareCopie();
        if ($copie['stare'] !== 'ok') {
            throw new Refuz($copie['stare'] === 'lipsa' ? 'copia de verificare lipsește (incarca.php --din=' . $this->profil . ')'
                : 'copia de verificare a expirat (mai veche de ' . $copie['zile'] . ' zile): sterge.php, apoi încarcă din nou');
        }
        $parinte = dirname($this->radacina) . '/cod-vechi';
        $dosar = $parinte . '/' . $this->profil;
        if (is_link($parinte) || is_link($dosar) || !is_dir($dosar)) {
            throw new Refuz('codul vechi lipsește din cod-vechi/' . $this->profil . '/ (sau e o legătură): încărcarea îl copiază '
                . '(incarca.php --din=' . $this->profil . ', fără --fara-cod-vechi)');
        }
        $this->codVechi = self::dosarCod($dosar, 'codul vechi');
        $envVechi = Locul::citesteEnv($this->codVechi . '/.env');
        $locala = static fn (string $g): string => in_array(strtolower(trim($g)), ['', 'localhost', '127.0.0.1', '::1'], true) ? 'localhost' : strtolower(trim($g));
        if ($envVechi === null || trim((string) ($envVechi['DB_DATABASE'] ?? '')) !== $this->baza
            || $locala((string) ($envVechi['DB_HOST'] ?? 'localhost')) !== $locala($gazdaDb)) {
            throw new Refuz('.env-ul codului vechi (cod-vechi/' . $this->profil . '/.env) nu e spre baza copiei: încarcă din nou copia');
        }
        $this->dosarTmp = \App\Support\Verificare::dosar() . '/tmp';
    }

    /** Dosarul unui cod de site (rădăcina, cu bootstrap.php și CheckoutCalculator), fără legături. */
    private static function dosarCod(string $dosar, string $ce): string
    {
        $real = realpath($dosar);
        if ($real === false || !is_dir($real) || !is_file($real . '/bootstrap.php') || !is_file($real . '/config/app.php')
            || !is_file($real . '/src/Support/CheckoutCalculator.php') || !is_file($real . '/src/Support/ErpSync.php')) {
            throw new Refuz($ce . ' nu e dosarul codului unui site (bootstrap.php, src/Support/CheckoutCalculator.php)');
        }
        return $real;
    }

    /** Un PHP dat cu --php-vechi: o cale absolută spre un executabil (de exemplu /opt/alt/php83/usr/bin/php). */
    private static function phpDat(string $cale): string
    {
        if (!str_starts_with($cale, '/') || str_contains($cale, "\0")) {
            $gasit = preg_match('/^php[0-9.]{0,5}$/', $cale) === 1 ? trim((string) @shell_exec('command -v ' . escapeshellarg($cale) . ' 2>/dev/null')) : '';
            if ($gasit === '' || !str_starts_with($gasit, '/')) {
                throw new Refuz('--php-vechi trebuie să fie calea întreagă a unui PHP (de exemplu /opt/alt/php83/usr/bin/php)');
            }
            $cale = $gasit;
        }
        if (!is_file($cale) || !is_executable($cale)) {
            throw new Refuz('--php-vechi nu e un executabil');
        }
        return $cale;
    }

    /** Versiunea unui PHP (pornit fără php.ini, doar pentru număr), sau null dacă nu pornește. */
    private function versiunePhp(string $php): ?string
    {
        try {
            $r = Proces::ruleaza([$php, '-n', '-r', 'echo PHP_VERSION;'], $this->mediu(), $this->radacina, '', $this->dosarTmp, $this->prinFisiere, 60);
        } catch (Throwable) {
            return null;
        }
        return $r['cod'] === 0 && preg_match('/^\d+\.\d+\.\d+/', trim($r['iesire']), $m) === 1 ? $m[0] : null;
    }

    private function conecteaza(): void
    {
        // Baza din .env (site-ul de verificare) sau din mediu (local), prin codul comun.
        $config = require $this->radacina . '/config/app.php';
        $db = \App\Support\Database::connection((array) ($config['db'] ?? []));
        if (!$db instanceof PDO) {
            throw new Refuz('legătura cu baza copiei nu merge (configureaza.php --rescrie dacă s-a schimbat parola)');
        }
        if ((string) $db->query('SELECT DATABASE()')->fetchColumn() !== $this->baza) {
            throw new Refuz('codul comun e legat la altă bază decât cea din .env');
        }
        $this->db = $db;
        if ($this->mod === 'verificare') {
            $marcaj = (int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '_verificare'")->fetchColumn() > 0
                ? $db->query('SELECT sursa, anonimizat, incarcat_la FROM _verificare LIMIT 1')->fetch(PDO::FETCH_ASSOC) : false;
            if (!is_array($marcaj)) {
                throw new Refuz('baza nu e o copie de verificare (lipsește _verificare): incarca.php --din=' . $this->profil);
            }
            if ((int) $marcaj['anonimizat'] !== 1) {
                throw new Refuz('copia nu e încheiată și anonimizată (_verificare: anonimizat = 0): incarca.php … --reia o termină, sterge.php o șterge');
            }
            if ((string) $marcaj['sursa'] !== $this->profil) {
                throw new Refuz('copia e din altă sursă decât ' . $this->profil . ' (de exemplu copia demonstrativă): sterge.php, apoi incarca.php --din='
                    . $this->profil);
            }
            $this->incarcatLa = (string) $marcaj['incarcat_la'];
            $stare = \App\Support\Verificare::stareDinRand($this->incarcatLa . ' UTC', time(), \App\Support\Verificare::zilePastrare());
            if ($this->incarcatLa === '' || $stare['stare'] !== 'ok') {
                throw new Refuz('copia din bază e mai veche decât zilele de păstrare (_verificare.incarcat_la): sterge.php, apoi încarcă din nou');
            }
            $this->sursa = $this->profil;
        }
    }

    /** Commit-ul sursei copiei (_verificare.cod_sursa_commit), scurt; null local sau fără el. */
    private function commitSursa(): ?string
    {
        if ($this->mod !== 'verificare' || $this->db === null) {
            return null;
        }
        try {
            $commit = (string) $this->db->query('SELECT cod_sursa_commit FROM _verificare LIMIT 1')->fetchColumn();
        } catch (Throwable) {
            return null;
        }
        return preg_match('/^[0-9a-f]{40}$/', $commit) === 1 ? substr($commit, 0, 7) : null;
    }

    /** Baza la zi cu migrările codului comun; pe copie, dacă e în urmă, `scripts/migreaza.php --aplica --site=<profil>` întâi. */
    private function verificaMigrarile(): void
    {
        $stare = \App\Support\Migrari\Garda::stare($this->db);
        if ($stare === \App\Support\Migrari\Garda::LA_ZI) {
            $this->spune('  migrările: baza e la zi cu codul comun');
            return;
        }
        if ($this->mod === 'local') {
            throw new Refuz('baza e „' . $stare . '” față de migrările codului comun: tools/dev/php.sh scripts/migreaza.php --aplica');
        }
        if ($stare !== \App\Support\Migrari\Garda::IN_URMA) {
            // Încărcarea lasă copia la zi; fără registru sau cu o eroare a gărzii nu e o copie făcută de ea.
            throw new Refuz('baza copiei e „' . $stare . '” față de migrările codului comun (încărcarea o lasă la zi): '
                . '$PHP scripts/migreaza.php --stare și trimite-ne textul');
        }
        $r = Proces::ruleaza(array_merge($this->php(PHP_BINARY), ['-d', 'display_errors=stderr'], self::iniSocket(), [$this->radacina . '/scripts/migreaza.php', '--aplica',
            '--site=' . $this->profil]),
            $this->mediu(), $this->radacina, '', $this->dosarTmp, false, 1800);
        \App\Support\Migrari\Garda::uita();
        $dupa = \App\Support\Migrari\Garda::stare($this->db);
        if ($r['cod'] !== 0 || $dupa !== \App\Support\Migrari\Garda::LA_ZI) {
            throw new Refuz('baza copiei era „' . $stare . '”, iar scripts/migreaza.php --aplica --site=' . $this->profil . ' a ieșit cu codul ' . $r['cod']
                . ' (după el: „' . $dupa . '”); rulează-l de mână și trimite-ne textul');
        }
        $this->spune('  migrările: baza era „' . $stare . '”; aplicate acum (scripts/migreaza.php --aplica --site=' . $this->profil . ', cod 0), la zi');
    }

    // ───────────────────────────────────────────────────────────
    // Cazurile
    // ───────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> cazurile, după id-ul comenzii (doar în memorie) */
    private function cazuri(int $n): array
    {
        $db = $this->db;
        assert($db instanceof PDO);
        $filtru = "deleted_at IS NULL AND status <> 'failed'";
        $ids = array_map('intval', $db->query('SELECT id FROM orders WHERE ' . $filtru . ' ORDER BY id DESC LIMIT ' . $n)->fetchAll(PDO::FETCH_COLUMN));
        $speciale = array_map('intval', $db->query('SELECT o.id FROM orders o WHERE o.' . str_replace(' AND ', ' AND o.', $filtru)
            . " AND o.created_at >= (NOW() - INTERVAL 12 MONTH) AND ((o.coupon_code IS NOT NULL AND o.coupon_code <> '') OR o.loyalty_points_used > 0
                OR o.shipping_method = 'fan_box' OR EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND oi.bbd_key IS NOT NULL AND oi.bbd_key <> ''))
             ORDER BY o.id DESC LIMIT " . self::SPECIALE_MAXIM)->fetchAll(PDO::FETCH_COLUMN));
        $toate = array_values(array_unique(array_merge($ids, $speciale)));
        sort($toate);
        $cazuri = [];
        $linii = [];
        foreach (array_chunk($toate, 500) as $bucata) {
            $semne = implode(',', array_fill(0, count($bucata), '?'));
            $st = $db->prepare('SELECT id, user_id, coupon_code, loyalty_points_used, loyalty_points_discount, shipping_method, fan_locker_id, billing_county,
                billing_city, billing_is_company, subtotal, discount_total, manual_discount, shipping_cost, shipping_manual, total FROM orders WHERE id IN (' . $semne . ')');
            $st->execute($bucata);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $cazuri[(int) $o['id']] = $o;
            }
            $st = $db->prepare('SELECT order_id, product_id, quantity, bbd_key FROM order_items WHERE order_id IN (' . $semne . ') ORDER BY id');
            $st->execute($bucata);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $linii[(int) $l['order_id']][] = [(int) ($l['product_id'] ?? 0), max(1, (int) $l['quantity']), (string) ($l['bbd_key'] ?? '')];
            }
        }
        $coduri = [];
        foreach ($cazuri as $o) {
            $cod = strtoupper(trim((string) ($o['coupon_code'] ?? '')));
            if ($cod !== '') {
                $coduri[$cod] = true;
            }
        }
        $tipuriCupon = [];
        foreach (array_chunk(array_keys($coduri), 500) as $bucata) {
            $st = $db->prepare('SELECT code, type FROM coupons WHERE code IN (' . implode(',', array_fill(0, count($bucata), '?')) . ')');
            $st->execute($bucata);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $tipuriCupon[strtoupper((string) $c['code'])] = (string) $c['type'];
            }
        }
        $rezultat = [];
        $i = 0;
        foreach ($cazuri as $id => $o) {
            $cupon = strtoupper(trim((string) ($o['coupon_code'] ?? '')));
            $laFanbox = (string) ($o['shipping_method'] ?? '') === 'fan_box';
            $liniiCaz = $linii[$id] ?? [];
            $tipuri = [];
            if ($cupon !== '') {
                $tipuri[] = !isset($tipuriCupon[$cupon]) ? 'cupon_inexistent' : ($tipuriCupon[$cupon] === 'percent' ? 'cupon_procent' : 'cupon_fix');
            }
            if ((int) ($o['loyalty_points_used'] ?? 0) > 0) {
                $tipuri[] = 'puncte';
            }
            foreach ($liniiCaz as $l) {
                if ($l[2] !== '') {
                    $tipuri[] = 'bbd';
                    break;
                }
            }
            if ($laFanbox) {
                $tipuri[] = 'fanbox';
            }
            if ((int) ($o['billing_is_company'] ?? 0) === 1) {
                $tipuri[] = 'firma';
            }
            if (mb_strtolower(trim((string) ($o['billing_county'] ?? ''))) === 'bucuresti') {
                $tipuri[] = 'judet_bucuresti';
            }
            sort($tipuri);
            $rezultat[$id] = [
                'i' => ++$i,
                'id' => (int) $id,
                'tip' => $tipuri === [] ? 'simplu' : implode('+', $tipuri),
                'linii' => $liniiCaz,
                'cupon' => $cupon,
                'client' => (int) ($o['user_id'] ?? 0),
                'puncte' => max(0, (int) ($o['loyalty_points_used'] ?? 0)),
                'judet' => (string) ($o['billing_county'] ?? ''),
                'oras' => (string) ($o['billing_city'] ?? ''),
                'la_fanbox' => $laFanbox,
                'fanbox' => $laFanbox ? max(0, (int) ($o['fan_locker_id'] ?? 0)) : 0,
                'subtotal' => (float) ($o['subtotal'] ?? 0),
                'discount_total' => (float) ($o['discount_total'] ?? 0),
                'puncte_discount' => (float) ($o['loyalty_points_discount'] ?? 0),
                'manual' => (float) ($o['manual_discount'] ?? 0),
                'transport' => (float) ($o['shipping_cost'] ?? 0),
                'transport_manual' => (int) ($o['shipping_manual'] ?? 0) === 1,
                'total_salvat' => (float) ($o['total'] ?? 0),
            ];
        }
        return $rezultat;
    }

    // ───────────────────────────────────────────────────────────
    // Cupoanele
    // ───────────────────────────────────────────────────────────

    /** @param array<int, array<string, mixed>> $cazuri */
    private function cupoaneValide(array $cazuri): int
    {
        $db = $this->db;
        assert($db instanceof PDO);
        $coduri = [];
        foreach ($cazuri as $caz) {
            if ((string) $caz['cupon'] !== '') {
                $coduri[(string) $caz['cupon']] = true;
            }
        }
        if ($coduri === []) {
            return 0;
        }
        $randuri = [];
        foreach (array_chunk(array_keys($coduri), 500) as $bucata) {
            $st = $db->prepare('SELECT id, ' . implode(', ', self::COLOANE_CUPON) . ' FROM coupons WHERE code IN (' . implode(',', array_fill(0, count($bucata), '?')) . ')');
            $st->execute($bucata);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $randuri[(string) $r['id']] = $r;
            }
        }
        if ($randuri === []) {
            return 0;
        }
        // Întâi fișierul cu valorile de dinainte (0600), apoi schimbarea: o rulare omorâtă le pune la loc data viitoare.
        $this->scrieFisierCupoane($randuri);
        $this->cupoaneOriginale = $randuri;
        $db->beginTransaction();
        $st = $db->prepare('UPDATE coupons SET is_active = 1, starts_at = NULL, ends_at = NULL, max_uses_total = NULL, used_at = NULL WHERE id = :id');
        foreach (array_keys($randuri) as $id) {
            $st->execute(['id' => (int) $id]);
        }
        $db->commit();
        return count($randuri);
    }

    private function fisierCupoane(): string
    {
        return $this->dosarTmp . '/calcule-cupoane-' . substr(hash('sha256', $this->baza), 0, 16) . '.json';
    }

    /** @param array<string, array<string, mixed>> $randuri */
    private function scrieFisierCupoane(array $randuri): void
    {
        $umask = umask(0077);
        try {
            if (!is_dir($this->dosarTmp) && !@mkdir($this->dosarTmp, 0700, true) && !is_dir($this->dosarTmp)) {
                throw new Refuz('nu pot face dosarul temporar al comparației');
            }
            $fisier = $this->fisierCupoane();
            $temporar = $fisier . '.' . getmypid() . '.tmp';
            $text = (string) json_encode(['baza' => hash('sha256', $this->baza), 'cupoane' => $randuri]);
            if (@file_put_contents($temporar, $text) !== strlen($text) || !@rename($temporar, $fisier)) {
                @unlink($temporar);
                throw new Refuz('nu pot scrie valorile de dinainte ale cupoanelor în dosarul temporar');
            }
            @chmod($fisier, 0600);
            $this->fisierCupoane = $fisier;
        } finally {
            umask($umask);
        }
    }

    /** Pune la loc cupoanele schimbate de această rulare (din memorie); true dacă nu era nimic de pus sau s-a pus și s-a verificat. */
    private function puneLaLocCupoanele(): bool
    {
        if ($this->cupoaneOriginale === null || $this->db === null) {
            return true;
        }
        $ok = $this->scrieCupoanele($this->cupoaneOriginale);
        if ($ok) {
            $this->cupoaneOriginale = null;
            if ($this->fisierCupoane !== '') {
                @unlink($this->fisierCupoane);
            }
        }
        return $ok;
    }

    /** Cupoanele unei rulări oprite (fișierul rămas pentru această bază), puse la loc înaintea oricărui alt lucru. */
    private function puneLaLocCupoaneleRamase(): void
    {
        $fisier = $this->fisierCupoane();
        if (!is_file($fisier) || is_link($fisier)) {
            return;
        }
        $date = json_decode((string) @file_get_contents($fisier), true);
        if (!is_array($date) || ($date['baza'] ?? '') !== hash('sha256', $this->baza) || !is_array($date['cupoane'] ?? null)) {
            throw new Refuz('fișierul cupoanelor unei rulări oprite (dosarul temporar) nu se înțelege; sterge.php golește dosarul temporar');
        }
        if (!$this->scrieCupoanele($date['cupoane'])) {
            throw new Refuz('cupoanele unei rulări oprite nu s-au putut pune la loc');
        }
        @unlink($fisier);
        $this->spune('  cupoanele unei rulări oprite (' . count($date['cupoane']) . '): puse la loc înainte de orice');
    }

    /** @param array<string, mixed> $randuri */
    private function scrieCupoanele(array $randuri): bool
    {
        $db = $this->db;
        if ($db === null) {
            return false;
        }
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $db->beginTransaction();
            $set = implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, self::COLOANE_CUPON));
            $st = $db->prepare('UPDATE coupons SET ' . $set . ' WHERE id = :id');
            foreach ($randuri as $id => $r) {
                $valori = ['id' => (int) $id];
                foreach (self::COLOANE_CUPON as $c) {
                    $valori[$c] = is_array($r) ? ($r[$c] ?? null) : null;
                }
                $st->execute($valori);
            }
            $db->commit();
            // Verificat: fiecare rând are acum valorile de dinainte.
            $st = $db->prepare('SELECT ' . implode(', ', self::COLOANE_CUPON) . ' FROM coupons WHERE id = :id');
            foreach ($randuri as $id => $r) {
                $st->execute(['id' => (int) $id]);
                $acum = $st->fetch(PDO::FETCH_ASSOC);
                foreach (self::COLOANE_CUPON as $c) {
                    if (!is_array($acum) || (string) ($acum[$c] ?? '') !== (string) (is_array($r) ? ($r[$c] ?? '') : '')) {
                        return false;
                    }
                }
            }
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    // ───────────────────────────────────────────────────────────
    // Lucrătorii
    // ───────────────────────────────────────────────────────────

    /** Mediul unui lucrător: pe site doar PATH, HOME, LANG; local mediul uneltelor; în ambele, cererile spre internet spre o gaură neagră. */
    private function mediu(): array
    {
        $gaura = 'http://127.0.0.1:9';
        $mediu = $this->mod === 'local' ? getenv() : ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME'), 'LANG' => 'C.UTF-8'];
        foreach (['http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'all_proxy'] as $cheie) {
            $mediu[$cheie] = $gaura;
        }
        $mediu['NO_PROXY'] = $mediu['no_proxy'] = '127.0.0.1,localhost';
        return $mediu;
    }

    /**
     * Un lucrător (`vechi` sau `nou`) cu cererea dată; întoarce primul rând (faza „verifica”) sau rezultatele pe caz.
     *
     * @param array<string, mixed> $cerere
     * @return array{primul: array<string, mixed>, cazuri: array<int, array<string, mixed>>, avertismente: array<string, int>}
     */
    private function lucrator(string $care, array $cerere, int $asteptate = 0): array
    {
        $php = $care === 'vechi' ? $this->phpVechi : $this->phpNou;
        $cod = $care === 'vechi' ? $this->codVechi : $this->codNou;
        $comanda = array_merge($this->php($php), ['-d', 'allow_url_fopen=0', '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'error_reporting=' . E_ALL,
            '-d', 'session.auto_start=0', '-d', 'opcache.enable_cli=0', '-d', 'memory_limit=' . self::memorie()]);
        $comanda = array_merge($comanda, self::iniSocket());
        $comanda[] = $this->radacina . '/tools/verificare/lucrator-calcule.php';
        $comanda[] = '--cod=' . $cod;
        $cerere += ['mod' => $this->mod, 'baza' => $this->baza, 'sursa' => $this->sursa, 'incarcat_la' => $this->incarcatLa, 'profil' => $this->profil];
        $eticheta = $care === 'vechi' ? 'codul vechi' : 'codul comun';
        $r = Proces::ruleaza($comanda, $this->mediu(), $cod, (string) json_encode($cerere, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->dosarTmp, $this->prinFisiere, self::TERMEN_LUCRATOR);
        $randuri = [];
        foreach (explode("\n", $r['iesire']) as $text) {
            if (trim($text) !== '') {
                $rand = json_decode($text, true);
                if (!is_array($rand)) {
                    throw new \RuntimeException('răspunsul lucrătorului (' . $eticheta . ') nu se înțelege');
                }
                $randuri[] = $rand;
            }
        }
        $primul = $randuri[0] ?? [];
        if (isset($primul['refuz'])) {
            throw new Refuz($eticheta . ': ' . (preg_match('/^[\p{L}0-9 .,:;()\/_*„”-]{1,200}$/u', (string) $primul['refuz']) === 1 ? $primul['refuz'] : 'refuz'));
        }
        $stderr = trim($r['erori']);
        $oprire = preg_match('/^OPRIT: [^\n]{1,200}$/m', $stderr, $m) === 1 ? $m[0] : '';
        if (($cerere['faza'] ?? '') === 'verifica') {
            if ($r['cod'] !== 0 || ($primul['ok'] ?? false) !== true) {
                throw new Refuz($eticheta . ' nu pornește (cod ' . $r['cod'] . ($oprire !== '' ? '; ' . $oprire : '') . ')');
            }
            return ['primul' => $primul, 'cazuri' => [], 'avertismente' => []];
        }
        $cazuri = [];
        $gata = null;
        foreach ($randuri as $rand) {
            if (isset($rand['gata'])) {
                $gata = $rand;
            } elseif (isset($rand['i'])) {
                $cazuri[(int) $rand['i']] = $rand;
            }
        }
        if ($r['cod'] !== 0 || $gata === null || count($cazuri) !== $asteptate) {
            throw new \RuntimeException($eticheta . ' s-a oprit după ' . count($cazuri) . ' din ' . $asteptate . ' cazuri (cod ' . $r['cod']
                . ($oprire !== '' ? '; ' . $oprire : '') . ')');
        }
        return ['primul' => $primul, 'cazuri' => $cazuri, 'avertismente' => array_map('intval', (array) ($gata['avertismente'] ?? []))];
    }

    /** Socketul bazei dat PHP-ului acestui proces (`pdo_mysql.default_socket`), dat mai departe proceselor copil. @return list<string> */
    /**
     * Un PHP copil (lucrătorul, migrările), cu aceleași extensii ca procesul de acum (lib/php-cli.php): pe Hostinger
     * /opt/alt/php83/usr/bin/php nu încarcă sodium singur, iar comanda proprietarului (`$PHP`, cu `-d extension=sodium.so`)
     * nu ajunge la PHP_BINARY. Sonda copilului rulează o dată pe PHP, cu mediul și felul lucrătorilor (țevi sau fișiere);
     * pentru fiecare extensie din PhpCli::EXTENSII pe care o avem aici, iar copilul pornit simplu nu, `-d extension=<nume>.so`
     * dacă modulul e în extension_dir-ul lui. Ce s-a adăugat se spune într-un rând (fără calea PHP-ului).
     *
     * @return list<string> executabilul și argumentele `-d extension=…`
     */
    private function php(string $php): array
    {
        if (!isset($this->extensiiCopil[$php])) {
            $sonda = $this->sondaPhp([$php]);
            $c = $sonda === null ? ['argumente' => [], 'adaugate' => [], 'lipsa' => []] : PhpCli::pentruCopil($sonda);
            $this->extensiiCopil[$php] = $c['argumente'];
            if ($c['argumente'] !== []) {
                // Verificat: cu -d, copilul chiar le are.
                $dupa = $this->sondaPhp(array_merge([$php], $c['argumente']));
                $tot = array_values(array_filter($c['adaugate'], static fn (string $e): bool => $dupa === null || empty($dupa['ext'][$e])));
                $this->spune('  extensiile PHP-ului ' . ($php === PHP_BINARY ? 'acestei comenzi' : 'codului vechi (--php-vechi)') . ' în procesele copil: '
                    . implode(', ', $c['adaugate']) . ' doar cu ' . implode(' ', $c['argumente']) . ($tot !== [] ? ' (tot lipsesc: ' . implode(', ', $tot) . ')' : ''));
            }
        }
        return array_merge([$php], $this->extensiiCopil[$php]);
    }

    /**
     * @param list<string> $comanda
     * @return array{dir: string, ext: array<string, bool>}|null
     */
    private function sondaPhp(array $comanda): ?array
    {
        try {
            $r = Proces::ruleaza(array_merge($comanda, ['-r', PhpCli::codSonda()]), $this->mediu(), $this->radacina, '', $this->dosarTmp, $this->prinFisiere, 60);
        } catch (Refuz $e) {
            throw $e;
        } catch (Throwable) {
            return null;
        }
        return PhpCli::citesteSonda($r['iesire']);
    }

    private static function iniSocket(): array
    {
        $socket = (string) ini_get('pdo_mysql.default_socket');
        return $socket !== '' ? ['-d', 'pdo_mysql.default_socket=' . $socket] : [];
    }

    private static function memorie(): string
    {
        $limita = (string) ini_get('memory_limit');
        return preg_match('/^(-1|[0-9]{1,6}[KMG]?)$/i', $limita) === 1 ? $limita : '512M';
    }

    // ───────────────────────────────────────────────────────────
    // Sesiunile (lucrătorii nu au voie să lase fișiere de sesiune)
    // ───────────────────────────────────────────────────────────

    /** @return array<string, list<string>> */
    private function sesiuni(): array
    {
        $r = [];
        foreach ([$this->codVechi, $this->codNou] as $cod) {
            $r[$cod] = array_map('basename', glob($cod . '/storage/sessions/sess_*') ?: []);
        }
        return $r;
    }

    /** @param array<string, list<string>> $inainte */
    private function stergeSesiunileNoi(array $inainte): int
    {
        $noi = 0;
        foreach ($this->sesiuni() as $cod => $fisiere) {
            foreach (array_diff($fisiere, $inainte[$cod] ?? []) as $fisier) {
                $noi++;
                @unlink($cod . '/storage/sessions/' . $fisier);
            }
        }
        return $noi;
    }

    // ───────────────────────────────────────────────────────────
    // Diferențele declarate (--declarate, doar local)
    // ───────────────────────────────────────────────────────────

    /**
     * Fișierul diferențelor voite: o listă JSON (poate fi goală) de `{"comanda": <id>, "campuri": [...], "motiv": "..."}`,
     * fiecare comandă o dată, câmpurile cu numele din raport (`shipping`, `linie.price`, `transport_admin`, `erp.transport` …;
     * `erp` singur nu: se numesc cheile payload-ului). Orice altceva e refuz, înaintea oricărei schimbări.
     *
     * @return list<array{comanda: int, campuri: list<string>, motiv: string}>
     */
    private static function citesteDeclarate(string $fisier): array
    {
        if ($fisier === '' || !is_file($fisier) || !is_readable($fisier)) {
            throw new Refuz('--declarate=<fișier.json>: fișierul diferențelor declarate nu există');
        }
        $date = json_decode((string) file_get_contents($fisier), true);
        if (!is_array($date) || ($date !== [] && array_keys($date) !== range(0, count($date) - 1))) {
            throw new Refuz('fișierul diferențelor declarate nu e o listă JSON ([{"comanda": …, "campuri": […], "motiv": "…"}])');
        }
        $permise = array_merge(self::CAMPURI, self::CAMPURI_DECLARABILE, self::CAMPURI_ALTE,
            array_map(static fn (string $c): string => 'linie.' . $c, array_merge(self::CAMPURI_LINIE, ['bbd_key'])));
        $declarate = [];
        $comenzi = [];
        foreach ($date as $k => $d) {
            $nr = '#' . ($k + 1);
            if (!is_array($d) || array_diff(array_keys($d), ['comanda', 'campuri', 'motiv']) !== []) {
                throw new Refuz('declarația ' . $nr . ' are alte chei decât comanda, campuri, motiv');
            }
            if (!is_int($d['comanda'] ?? null) || $d['comanda'] < 1) {
                throw new Refuz('declarația ' . $nr . ': comanda e id-ul comenzii (un număr întreg pozitiv)');
            }
            if (isset($comenzi[$d['comanda']])) {
                throw new Refuz('declarația ' . $nr . ': comanda e declarată de două ori (o declarație pe comandă, cu toate câmpurile ei)');
            }
            $campuri = $d['campuri'] ?? null;
            if (!is_array($campuri) || $campuri === [] || array_keys($campuri) !== range(0, count($campuri) - 1)) {
                throw new Refuz('declarația ' . $nr . ': campuri e lista câmpurilor care diferă (cel puțin unul)');
            }
            foreach ($campuri as $camp) {
                if (!is_string($camp) || (!in_array($camp, $permise, true) && preg_match('/^erp\.([A-Za-z0-9_]{1,60}|\(lipsă\))$/u', $camp) !== 1)) {
                    throw new Refuz('declarația ' . $nr . ': câmp necunoscut' . (is_string($camp) && preg_match('/^[a-z_.]{1,40}$/', $camp) === 1 ? ' (' . $camp . ')' : '')
                        . ' (numele din raport: ' . implode(', ', self::CAMPURI) . ', linie.<câmp>, transport_admin …, erp.<cheie>)');
                }
            }
            if (count(array_unique($campuri)) !== count($campuri)) {
                throw new Refuz('declarația ' . $nr . ': un câmp e scris de două ori');
            }
            if (!is_string($d['motiv'] ?? null) || mb_strlen(trim($d['motiv'])) < 10) {
                throw new Refuz('declarația ' . $nr . ': lipsește motivul (de ce diferă voit, cu WP-ul care a făcut schimbarea)');
            }
            sort($campuri, SORT_STRING);
            $comenzi[$d['comanda']] = true;
            $declarate[] = ['comanda' => $d['comanda'], 'campuri' => $campuri, 'motiv' => trim($d['motiv'])];
        }
        return $declarate;
    }

    /**
     * Câmpurile care diferă la un caz, cu numele folosite de declarații: `erp` înlocuit de cheile payload-ului care diferă
     * (`erp.<cheie>`), sortate.
     *
     * @param list<string> $campuri
     * @param array<string, int> $cheiErp
     * @return list<string>
     */
    private static function campuriExacte(array $campuri, array $cheiErp): array
    {
        $exacte = array_values(array_diff($campuri, ['erp']));
        if (in_array('erp', $campuri, true)) {
            foreach (array_keys($cheiErp) as $cheie) {
                $exacte[] = 'erp.' . $cheie;
            }
        }
        sort($exacte, SORT_STRING);
        return $exacte;
    }

    // ───────────────────────────────────────────────────────────
    // Raportul
    // ───────────────────────────────────────────────────────────

    /**
     * @param array<int, array<string, mixed>> $cazuri
     * @param array{cazuri: array<int, array<string, mixed>>, avertismente: array<string, int>} $vechi
     * @param array{cazuri: array<int, array<string, mixed>>, avertismente: array<string, int>} $nou
     */
    private function raport(array $cazuri, array $vechi, array $nou, int $sesiuniLasate): int
    {
        $identice = 0;
        $diferite = 0;
        $eroriVechi = [];
        $eroriNou = [];
        $peCamp = [];
        $peTipCaz = [];
        $cheiErp = [];
        $erpIdentice = 0;
        $adminIdentice = 0;
        $puncteIdentice = 0;
        $totalSalvat = 0;
        $peTip = [];
        $cupoaneOk = ['vechi' => 0, 'nou' => 0];
        $puncteFolosite = ['vechi' => 0, 'nou' => 0];
        $faraErori = 0;
        // Diferențele declarate (--declarate): după comanda cazului; ce s-a găsit pentru fiecare declarație.
        $declaratiaComenzii = [];
        foreach ((array) $this->declarate as $k => $d) {
            $declaratiaComenzii[(int) $d['comanda']] = (int) $k;
        }
        $gasitDeclarate = [];
        $declarateOk = 0;
        foreach ($cazuri as $caz) {
            foreach (explode('+', (string) $caz['tip']) as $t) {
                $peTip[$t] = ($peTip[$t] ?? 0) + 1;
            }
            $i = (int) $caz['i'];
            $a = $vechi['cazuri'][$i] ?? [];
            $b = $nou['cazuri'][$i] ?? [];
            if (isset($a['eroare'])) {
                $eroriVechi[(string) $a['eroare']] = ($eroriVechi[(string) $a['eroare']] ?? 0) + 1;
            }
            if (isset($b['eroare'])) {
                $eroriNou[(string) $b['eroare']] = ($eroriNou[(string) $b['eroare']] ?? 0) + 1;
            }
            $kDeclarat = $declaratiaComenzii[(int) $caz['id']] ?? null;
            if (isset($a['eroare']) || isset($b['eroare'])) {
                if ($kDeclarat !== null) {
                    $gasitDeclarate[$kDeclarat] = 'eroare în cod';
                }
                continue;
            }
            $faraErori++;
            foreach (['vechi' => $a, 'nou' => $b] as $parte => $rez) {
                if ((string) $caz['cupon'] !== '' && !empty($rez['rezumat']['cupon_ok'])) {
                    $cupoaneOk[$parte]++;
                }
                if ((int) ($rez['rezumat']['puncte_aplicate'] ?? 0) > 0) {
                    $puncteFolosite[$parte]++;
                }
            }
            $cheiCaz = [];
            $campuri = self::diferente($a, $b, $cheiCaz);
            if ($kDeclarat !== null) {
                $exacte = self::campuriExacte($campuri, $cheiCaz);
                $declarat = (array) $this->declarate[$kDeclarat]['campuri'];
                if ($exacte === $declarat) {
                    $gasitDeclarate[$kDeclarat] = true;
                } else {
                    $gasitDeclarate[$kDeclarat] = ($exacte === [] ? 'nicio diferență' : 'diferă la: ' . implode(', ', $exacte)) . '; declarat: ' . implode(', ', $declarat);
                }
            }
            if (!in_array('erp', $campuri, true)) {
                $erpIdentice++;
            }
            if (array_intersect($campuri, ['transport_admin', 'transport_admin_0']) === []) {
                $adminIdentice++;
            }
            if (array_intersect($campuri, ['puncte_estimate', 'puncte_estimate_salvat']) === []) {
                $puncteIdentice++;
            }
            if (abs((float) ($b['rezumat']['total'] ?? 0) - round((float) $caz['total_salvat'], 2)) <= self::TOLERANTA) {
                $totalSalvat++;
            }
            if ($campuri === []) {
                $identice++;
                continue;
            }
            if ($kDeclarat !== null && ($gasitDeclarate[$kDeclarat] ?? null) === true) {
                // O diferență voită, declarată exact (câmpurile ei, nici mai multe, nici mai puține).
                $declarateOk++;
                continue;
            }
            $diferite++;
            foreach ($campuri as $camp) {
                $peCamp[$camp] = ($peCamp[$camp] ?? 0) + 1;
            }
            foreach ($cheiCaz as $cheie => $numarChei) {
                $cheiErp[$cheie] = ($cheiErp[$cheie] ?? 0) + $numarChei;
            }
            $peTipCaz[(string) $caz['tip']] = ($peTipCaz[(string) $caz['tip']] ?? 0) + 1;
        }
        $total = count($cazuri);
        $nrEroriVechi = array_sum($eroriVechi);
        $nrEroriNou = array_sum($eroriNou);
        $this->spune('cazuri ' . $total . ' | identice ' . $identice . ' | diferite ' . $diferite . ' | erori vechi ' . $nrEroriVechi . ' | erori nou ' . $nrEroriNou);
        ksort($peTip);
        $this->spune('cazuri pe tip: ' . self::lista($peTip));
        $this->spune('cupoane aplicate fără eroare: vechi ' . $cupoaneOk['vechi'] . ', nou ' . $cupoaneOk['nou'] . ' | puncte folosite: vechi '
            . $puncteFolosite['vechi'] . ', nou ' . $puncteFolosite['nou']);
        arsort($peCamp);
        $this->spune('diferențe pe câmp: ' . self::lista($peCamp));
        arsort($peTipCaz);
        $this->spune('diferențe pe tip de caz: ' . self::lista($peTipCaz));
        arsort($cheiErp);
        $this->spune('payload ERP: identice ' . $erpIdentice . ' | chei diferite: ' . self::lista($cheiErp));
        $this->spune('transportul din admin: identice ' . $adminIdentice . ' | punctele promise: identice ' . $puncteIdentice);
        if ($nrEroriVechi + $nrEroriNou > 0) {
            $this->spune('erori: vechi ' . self::lista($eroriVechi, true) . ' | nou ' . self::lista($eroriNou, true));
        }
        $nepotrivite = [];
        if ($this->declarate !== null) {
            foreach ($this->declarate as $k => $d) {
                if (($gasitDeclarate[$k] ?? null) !== true) {
                    $nepotrivite[] = '#' . ($k + 1) . ' (' . (is_string($gasitDeclarate[$k] ?? null) ? $gasitDeclarate[$k] : 'comanda nu e printre cazuri') . ')';
                }
            }
            $this->spune('diferențe declarate (--declarate): ' . $declarateOk . ' potrivite exact | nepotrivite ' . count($nepotrivite));
            if ($nepotrivite !== []) {
                $this->spune('declarații nepotrivite: ' . implode(', ', $nepotrivite));
            }
        }
        $this->spune('informativ: total nou = total salvat la ' . $totalSalvat . '/' . $faraErori
            . ' (diferențele vin din prețurile, cupoanele și transportul de acum, nu din cod)');
        $this->spune('avertismente PHP: vechi ' . array_sum($vechi['avertismente']) . ', nou ' . array_sum($nou['avertismente'])
            . (array_sum($vechi['avertismente']) + array_sum($nou['avertismente']) > 0
                ? ' (vechi: ' . self::lista($vechi['avertismente'], true) . '; nou: ' . self::lista($nou['avertismente'], true) . ')' : ''));
        $this->spune('fișiere de sesiune lăsate de lucrători: ' . $sesiuniLasate . ($sesiuniLasate > 0 ? ' (șterse acum)' : ''));
        $trece = $diferite === 0 && $nrEroriVechi === 0 && $nrEroriNou === 0 && $nepotrivite === [];
        $this->spune('REZULTAT: ' . ($trece ? 'TRECE' : 'PICĂ'));
        $this->salveaza();
        return $trece ? 0 : 1;
    }

    /**
     * Câmpurile care diferă între rezultatul vechi și cel nou al unui caz (numele lor, o singură dată); `$cheiErp` numără cheile
     * de sus ale payload-ului ERP care diferă.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @param array<string, int> $cheiErp
     * @return list<string>
     */
    public static function diferente(array $a, array $b, array &$cheiErp): array
    {
        $dif = [];
        $ra = (array) ($a['rezumat'] ?? []);
        $rb = (array) ($b['rezumat'] ?? []);
        foreach (self::CAMPURI as $camp) {
            if (!array_key_exists($camp, $ra) || !array_key_exists($camp, $rb) || abs((float) $ra[$camp] - (float) $rb[$camp]) > self::TOLERANTA) {
                $dif[] = $camp;
            }
        }
        if (($ra['cupon_ok'] ?? null) !== ($rb['cupon_ok'] ?? null)) {
            $dif[] = 'cupon_ok';
        }
        if ((int) ($ra['puncte_aplicate'] ?? -1) !== (int) ($rb['puncte_aplicate'] ?? -2)) {
            $dif[] = 'puncte_aplicate';
        }
        $la = array_values((array) ($ra['linii'] ?? []));
        $lb = array_values((array) ($rb['linii'] ?? []));
        if (count($la) !== count($lb)) {
            $dif[] = 'linii';
        } else {
            foreach ($la as $k => $linie) {
                $alta = (array) $lb[$k];
                foreach (self::CAMPURI_LINIE as $camp) {
                    if (abs((float) ($linie[$camp] ?? 0) - (float) ($alta[$camp] ?? 0)) > self::TOLERANTA && !in_array('linie.' . $camp, $dif, true)) {
                        $dif[] = 'linie.' . $camp;
                    }
                }
                if ((string) ($linie['bbd_key'] ?? '') !== (string) ($alta['bbd_key'] ?? '') && !in_array('linie.bbd_key', $dif, true)) {
                    $dif[] = 'linie.bbd_key';
                }
            }
        }
        foreach (self::CAMPURI_ALTE as $camp) {
            if (!array_key_exists($camp, $a) || !array_key_exists($camp, $b) || abs((float) $a[$camp] - (float) $b[$camp]) > self::TOLERANTA) {
                $dif[] = $camp;
            }
        }
        $ea = $a['erp'] ?? null;
        $eb = $b['erp'] ?? null;
        if (!is_array($ea) || !is_array($eb) || (string) ($ea['sha1'] ?? 'a') !== (string) ($eb['sha1'] ?? 'b')) {
            $dif[] = 'erp';
            $ca = is_array($ea) ? (array) ($ea['chei'] ?? []) : [];
            $cb = is_array($eb) ? (array) ($eb['chei'] ?? []) : [];
            foreach (array_unique(array_merge(array_keys($ca), array_keys($cb))) as $cheie) {
                if (($ca[$cheie] ?? null) !== ($cb[$cheie] ?? null)) {
                    $nume = preg_match('/^[A-Za-z0-9_]{1,60}$/', (string) $cheie) === 1 ? (string) $cheie : '?';
                    $cheiErp[$nume] = ($cheiErp[$nume] ?? 0) + 1;
                }
            }
            if (!is_array($ea) || !is_array($eb)) {
                $cheiErp['(lipsă)'] = ($cheiErp['(lipsă)'] ?? 0) + 1;
            }
        }
        return $dif;
    }

    /**
     * Rândul despre setările din care se fac calculele: aceleași chei și valori în ambele coduri? (doar numele cheilor)
     *
     * @param array<string, mixed> $vechi
     * @param array<string, mixed> $nou
     */
    private static function textSetari(array $vechi, array $nou): string
    {
        $diferite = [];
        foreach (array_unique(array_merge(array_keys($vechi), array_keys($nou))) as $cheie) {
            if (($vechi[$cheie] ?? null) !== ($nou[$cheie] ?? null)) {
                $diferite[] = preg_match('/^[a-z0-9_]{1,80}$/', (string) $cheie) === 1 ? (string) $cheie : '?';
            }
        }
        sort($diferite);
        return 'setările calculelor (informativ): ' . count(array_unique(array_merge(array_keys($vechi), array_keys($nou)))) . ' chei, '
            . ($diferite === [] ? 'aceleași valori în ambele coduri' : 'alte valori în codul comun la ' . count($diferite) . ': ' . implode(', ', $diferite));
    }

    /** @param array<string, int> $numere */
    private static function lista(array $numere, bool $cuInmultire = false): string
    {
        if ($numere === []) {
            return '—';
        }
        $parti = [];
        foreach ($numere as $nume => $numar) {
            $parti[] = $nume . ($cuInmultire ? ' ×' : ' ') . $numar;
        }
        return implode(', ', $parti);
    }

    private function spune(string $text): void
    {
        echo $text, "\n";
        $this->text[] = $text;
    }

    private function salveaza(): void
    {
        $continut = implode("\n", $this->text) . "\n";
        if ($this->mod === 'local') {
            $fisier = rtrim((string) getenv('CMS_DEV_DIR'), '/') . '/calcule-raport.txt';
            @file_put_contents($fisier, $continut);
            return;
        }
        try {
            $cale = Rulare::salveaza('calcule-' . $this->profil . '-' . gmdate('Ymd-His') . '.txt', $continut);
            echo 'Textul e salvat în ', $cale, " (ni-l trimiți; nu are date ale clienților).\n";
        } catch (Refuz $e) {
            echo 'ATENȚIE: textul nu s-a putut salva (', $e->getMessage(), "); copiază-l de pe ecran.\n";
        }
    }

    private static function locul(Throwable $e): string
    {
        $unelte = dirname(__DIR__, 2) . '/';
        $fisier = $e->getFile();
        $loc = str_starts_with($fisier, $unelte) ? 'tools/' . substr($fisier, strlen($unelte)) : basename($fisier);
        return (preg_match('/^[A-Za-z0-9._\/-]{1,200}$/', $loc) === 1 ? $loc : '?') . ':' . $e->getLine();
    }

    /** Commit-ul unui cod din .git (HEAD, refs, packed-refs; fără comanda git), sau null. */
    public static function commit(string $dosar): ?string
    {
        $git = $dosar . '/.git';
        if (is_file($git)) {
            // Un worktree: „gitdir: <cale>”.
            $text = trim((string) @file_get_contents($git, false, null, 0, 1024));
            if (!str_starts_with($text, 'gitdir: ')) {
                return null;
            }
            $git = substr($text, 8);
            $git = str_starts_with($git, '/') ? $git : $dosar . '/' . $git;
        }
        if (!is_dir($git)) {
            return null;
        }
        $cap = trim((string) @file_get_contents($git . '/HEAD', false, null, 0, 512));
        if (preg_match('/^[0-9a-f]{40}$/', $cap) === 1) {
            return $cap;
        }
        if (!str_starts_with($cap, 'ref: refs/') || str_contains($cap, '..')) {
            return null;
        }
        $ref = substr($cap, 5);
        $comun = is_file($git . '/commondir') ? $git . '/' . trim((string) @file_get_contents($git . '/commondir', false, null, 0, 1024)) : $git;
        foreach ([$git, $comun] as $d) {
            $commit = trim((string) @file_get_contents($d . '/' . $ref, false, null, 0, 128));
            if (preg_match('/^[0-9a-f]{40}$/', $commit) === 1) {
                return $commit;
            }
            foreach (explode("\n", (string) @file_get_contents($d . '/packed-refs', false, null, 0, 4194304)) as $rand) {
                $parti = explode(' ', trim($rand));
                if (count($parti) === 2 && $parti[1] === $ref && preg_match('/^[0-9a-f]{40}$/', $parti[0]) === 1) {
                    return $parti[0];
                }
            }
        }
        return null;
    }
}

/**
 * Un proces copil (lucrătorul, migrările): prin țevi (`proc_open`) sau, dacă `proc_open` e oprit pe găzduire (`stare.php`
 * îl arată) sau cu `--prin-fisiere`, prin fișiere 0600 în dosarul temporar al copiei, șterse la final (`exec`).
 */
final class Proces
{
    public static function procOpenMerge(): bool
    {
        $oprite = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return function_exists('proc_open') && !in_array('proc_open', $oprite, true);
    }

    /**
     * Rulează comanda cu mediul dat (doar acela) în dosarul `$cwd`, cu `$intrare` pe stdin; prin țevi dacă `proc_open` merge și
     * nu se cer fișiere, altfel prin fișiere 0600 în `$dosarTmp` (șterse la final) cu `exec`. Lucrătorul primește atunci
     * `--intrare=` și `--iesire=` (citește și scrie el fișierele), celelalte comenzi stdin și stdout din fișiere.
     *
     * @param list<string> $comanda
     * @param array<string, string> $mediu
     * @return array{cod: int, iesire: string, erori: string}
     */
    public static function ruleaza(array $comanda, array $mediu, string $cwd, string $intrare, string $dosarTmp, bool $prinFisiere, int $termen): array
    {
        if (!$prinFisiere && self::procOpenMerge()) {
            return self::prinTevi($comanda, $mediu, $cwd, $intrare, $termen);
        }
        $oprite = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('exec') || in_array('exec', $oprite, true)) {
            if (self::procOpenMerge()) {
                return self::prinTevi($comanda, $mediu, $cwd, $intrare, $termen);
            }
            throw new Refuz('găzduirea nu lasă PHP-ul să pornească alte procese (proc_open și exec sunt oprite); trimite-ne textul lui stare.php');
        }
        return self::prinFisiere($comanda, $mediu, $cwd, $intrare, $dosarTmp);
    }

    /** @return array{cod: int, iesire: string, erori: string} */
    private static function prinTevi(array $comanda, array $mediu, string $cwd, string $intrare, int $termen): array
    {
        $proces = proc_open($comanda, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tevi, $cwd, $mediu);
        if (!is_resource($proces)) {
            throw new \RuntimeException('procesul nu pornește');
        }
        stream_set_blocking($tevi[1], false);
        stream_set_blocking($tevi[2], false);
        $iesire = '';
        $erori = '';
        $deScris = $intrare;
        $limita = microtime(true) + $termen;
        $intrareDeschisa = true;
        if ($deScris === '') {
            fclose($tevi[0]);
            $intrareDeschisa = false;
        } else {
            stream_set_blocking($tevi[0], false);
        }
        while (!feof($tevi[1]) || !feof($tevi[2])) {
            $citire = array_values(array_filter([$tevi[1], $tevi[2]], static fn ($t): bool => !feof($t)));
            $scriere = $intrareDeschisa ? [$tevi[0]] : null;
            $exceptii = null;
            if (@stream_select($citire, $scriere, $exceptii, 1) === false) {
                break;
            }
            if ($intrareDeschisa && $scriere !== null && $scriere !== []) {
                $scris = @fwrite($tevi[0], substr($deScris, 0, 65536));
                if ($scris === false) {
                    fclose($tevi[0]);
                    $intrareDeschisa = false;
                } else {
                    $deScris = (string) substr($deScris, $scris);
                    if ($deScris === '') {
                        fclose($tevi[0]);
                        $intrareDeschisa = false;
                    }
                }
            }
            foreach ($citire as $teava) {
                $bucata = (string) fread($teava, 1048576);
                if ($teava === $tevi[1]) {
                    $iesire .= $bucata;
                } else {
                    $erori .= $bucata;
                }
            }
            if (microtime(true) > $limita) {
                proc_terminate($proces, 9);
                $erori .= "\nOPRIT: timpul lucrătorului s-a terminat";
                break;
            }
        }
        if ($intrareDeschisa) {
            fclose($tevi[0]);
        }
        fclose($tevi[1]);
        fclose($tevi[2]);
        $cod = proc_close($proces);
        return ['cod' => $cod, 'iesire' => $iesire, 'erori' => $erori];
    }

    /** @return array{cod: int, iesire: string, erori: string} */
    private static function prinFisiere(array $comanda, array $mediu, string $cwd, string $intrare, string $dosar): array
    {
        $umask = umask(0077);
        $fisiere = [];
        try {
            if (!is_dir($dosar) && !@mkdir($dosar, 0700, true) && !is_dir($dosar)) {
                throw new Refuz('nu pot face dosarul temporar al comparației');
            }
            $baza = $dosar . '/calcule-' . bin2hex(random_bytes(8));
            $fisiere = ['in' => $baza . '.in.json', 'out' => $baza . '.out.jsonl', 'err' => $baza . '.err.txt'];
            if (@file_put_contents($fisiere['in'], $intrare) !== strlen($intrare)) {
                throw new Refuz('nu pot scrie în dosarul temporar al comparației');
            }
            $areLucratorul = str_ends_with((string) ($comanda[count($comanda) - 2] ?? ''), 'lucrator-calcule.php');
            if ($areLucratorul) {
                $comanda[] = '--intrare=' . $fisiere['in'];
                $comanda[] = '--iesire=' . $fisiere['out'];
            }
            $prefix = '';
            foreach ($mediu as $cheie => $valoare) {
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $cheie) === 1) {
                    $prefix .= (string) $cheie . '=' . escapeshellarg((string) $valoare) . ' ';
                }
            }
            $linie = 'cd ' . escapeshellarg($cwd) . ' && env -i ' . $prefix . implode(' ', array_map('escapeshellarg', $comanda))
                . ($areLucratorul ? ' < /dev/null > /dev/null' : ' < ' . escapeshellarg($fisiere['in']) . ' > ' . escapeshellarg($fisiere['out']))
                . ' 2> ' . escapeshellarg($fisiere['err']);
            $iesiri = [];
            $cod = 1;
            exec($linie, $iesiri, $cod);
            return ['cod' => $cod, 'iesire' => (string) @file_get_contents($fisiere['out']), 'erori' => (string) @file_get_contents($fisiere['err'])];
        } finally {
            foreach ($fisiere as $f) {
                @unlink($f);
            }
            umask($umask);
        }
    }
}
