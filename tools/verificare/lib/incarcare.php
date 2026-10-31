<?php

declare(strict_types=1);

/**
 * Încărcarea copiei de verificare (WP-09; docs/VERIFICARE.md, „Încărcarea copiei”; planul de verificare §3.5):
 * tools/verificare/incarca.php. Două feluri:
 *
 * - `--din=<site>`: baza site-ului live (găsit după domeniul din `.env`-ul lui, ca la agregate.php: din acel `.env` se
 *   folosesc doar APP_URL și DB_*, plus, decizia D3, cele trei chei de test BT_IPAY_TEST_*), citită strict în citire, într-un
 *   instantaneu consecvent pe grup, fără buffer, și scrisă în baza copiei cu datele personale schimbate în PHP între citire
 *   și scriere, după regulile din tools/verificare/reguli/<site>.php. Nicio dată personală nu ajunge în baza copiei.
 * - `--demo`: baza copiei făcută din migrări și din datele inventate ale tools/dev/date-demo.php, cu pachetul unui site și
 *   o licență; nicio bază sursă nu se deschide (repetițiile personalului clienților, decizia OD-39).
 *
 * Ordinea (--din): verificările de loc și de bază, fără să atingă nimic → întrebările (confirmarea, contul de admin al
 * proprietarului) → sesiunea sursei, deschisă și verificată doar de citire (niciun rând citit; dacă nu merge: refuz, cod 2,
 * nimic atins) → `_verificare` scris PRIMUL, cu `anonimizat = 0` (înțelegerea cu sterge.php: o încărcare oprită la
 * jumătate rămâne o copie de verificare) → scanerul coloanelor (fail-closed: o coloană necunoscută oprește totul înaintea
 * oricărui rând) → structura (SHOW CREATE TABLE) → datele, grup cu grup (un instantaneu al sursei și o tranzacție a copiei
 * pe grup; `--reia` continuă de la primul grup neterminat) → setările neutralizate, adminul proprietarului, codul vechi în
 * `~/domains/<verificare>/cod-vechi/<site>/` (în afara public_html) → migrările (`migreaza --aplica --site=<site>`, în
 * proces) → aserțiunile A1–A9 (doar numere) → licența pachetului → `anonimizat = 1`, `incarcat_la` și
 * `storage/verificare/incarcat-la`. O aserțiune care pică (sau o eroare neprevăzută) șterge singură copia.
 *
 * Sintaxa e PHP 8.3 (php_min); incarca.php verifică versiunea cu sintaxă PHP 7.0 înainte să încarce fișierul acesta.
 */

namespace Unelte\Verificare;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/live.php';
require_once __DIR__ . '/cont.php';
require_once __DIR__ . '/php-cli.php';

/** Încărcarea a pornit și s-a oprit: copia se șterge singură (cod 1). Mesajul nu are valori din rânduri. */
final class EsecIncarcare extends RuntimeException
{
}

/** Încărcarea s-a oprit, dar se poate continua cu --reia (bugetul de timp, legătura pierdută): cod 3. */
final class Intrerupere extends RuntimeException
{
}

// ───────────────────────────────────────────────────────────────────────────
// Regulile unui site
// ───────────────────────────────────────────────────────────────────────────

final class Reguli
{
    public const MODURI = ['anonimizeaza', 'copiaza', 'setari', 'doar_structura'];
    /** Regulile din `transforma` și opțiunile fiecăreia (docs: capul tools/verificare/reguli/bioscem.php). */
    public const TRANSFORMARI = [
        'email' => [], 'emailuri' => [], 'email_admin' => [], 'telefon' => [], 'prenume' => [], 'nume' => ['email'],
        'nume_client' => [], 'nume_abonat' => [], 'adresa' => [], 'numar_strada' => [], 'cod_postal' => [], 'text' => ['daca'],
        'ref' => [], 'parola' => [], 'nul' => [], 'valoare' => ['valoare', 'daca_nenul'], 'firma' => [], 'cui' => [], 'reg_com' => [],
        'subiect' => ['din'], 'token' => [], 'cod_cupon' => [], 'nume_cupon' => [], 'cupon_comanda' => [], 'awb' => [],
        'awb_lista' => [], 'url_urmarire' => [], 'url_fara_interogare' => [],
    ];
    /** Regulile care fac o adresă @verificare.invalid (A1). */
    public const EMAIL = ['email', 'emailuri', 'email_admin'];
    /** Numele unui tabel sau al unei coloane pe care unealta le pune într-o instrucțiune. */
    public const TIPAR_NUME = '/^[A-Za-z0-9_$]{1,64}$/';
    /** O cheie de setare care arată a secret (lista ei albă cere neutralizarea, probleme()). */
    public const TIPAR_SETARE_SECRETA = '/(pass|secret|token|api_?key|_key$|private|iban|credential)/';

    /** @param array<string, mixed> $date */
    private function __construct(public readonly string $site, public readonly array $date)
    {
    }

    /** Regulile din tools/verificare/reguli/<site>.php (Refuz dacă lipsesc sau nu sunt bune). */
    public static function incarca(string $radacina, string $site): self
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $site) !== 1) {
            throw new Refuz('--din: nume de site nepermis');
        }
        $fisier = $radacina . '/tools/verificare/reguli/' . $site . '.php';
        if (!is_file($fisier)) {
            throw new Refuz('nu există încă reguli de anonimizare pentru „' . $site . '” (tools/verificare/reguli/' . $site . '.php; '
                . 'vertij și grafoanaytis le primesc la porturile lor). Nu am atins nimic');
        }
        $date = require $fisier;
        if (!is_array($date) || ($date['site'] ?? null) !== $site) {
            throw new Refuz('tools/verificare/reguli/' . $site . '.php nu are forma cerută. Nu am atins nimic');
        }
        $reguli = new self($site, $date);
        $probleme = $reguli->probleme();
        if ($probleme !== []) {
            throw new Refuz('regulile pentru ' . $site . ' au greșeli (' . implode('; ', array_slice($probleme, 0, 10)) . '). Nu am atins nimic');
        }
        return $reguli;
    }

    /** Din alt fișier (testele): aceleași verificări. @param array<string, mixed> $date */
    public static function din(array $date): self
    {
        return new self((string) ($date['site'] ?? ''), $date);
    }

    public function versiune(): string
    {
        return (string) ($this->date['versiune'] ?? '');
    }

    /**
     * Greșelile fișierului de reguli (forma, nu sursa): moduri și transformări necunoscute, o coloană și în `pastreaza`, și în
     * `transforma`, o coloană cu nume de date personale păstrată fără motiv, un tabel copiat în afara grupurilor sau în două,
     * o opțiune necunoscută, o coloană dintr-o condiție care nu e a tabelului. O listă goală = bune.
     *
     * @return list<string>
     */
    public function probleme(): array
    {
        $p = [];
        if (!is_int($this->date['versiune'] ?? null) || (int) $this->date['versiune'] < 1) {
            $p[] = 'versiune lipsă (un întreg)';
        }
        foreach (['licenta', 'pachet'] as $cheie) {
            if (!is_string($this->date[$cheie] ?? null) || preg_match('/^[a-z0-9][a-z0-9_-]{0,40}$/', (string) $this->date[$cheie]) !== 1) {
                $p[] = $cheie . ' lipsă';
            }
        }
        $inGrup = [];
        foreach ((array) ($this->date['grupuri'] ?? []) as $grup => $tabele) {
            if (preg_match('/^[a-z0-9_]{1,40}$/', (string) $grup) !== 1 || !is_array($tabele) || $tabele === []) {
                $p[] = 'grupul ' . $grup . ' nu are forma cerută';
                continue;
            }
            foreach ($tabele as $tabel) {
                if (isset($inGrup[$tabel])) {
                    $p[] = $tabel . ' e în două grupuri';
                }
                $inGrup[(string) $tabel] = (string) $grup;
            }
        }
        foreach ((array) ($this->date['tabele'] ?? []) as $tabel => $regula) {
            $tabel = (string) $tabel;
            $mod = (string) ($regula['mod'] ?? '');
            if (preg_match(self::TIPAR_NUME, $tabel) !== 1) {
                $p[] = 'nume de tabel nepermis: ' . $tabel;
                continue;
            }
            if (!in_array($mod, self::MODURI, true)) {
                $p[] = $tabel . ': mod necunoscut';
                continue;
            }
            if ($mod === 'doar_structura') {
                if (trim((string) ($regula['motiv'] ?? '')) === '') {
                    $p[] = $tabel . ': doar_structura fără motiv';
                }
                if (isset($inGrup[$tabel])) {
                    $p[] = $tabel . ': doar_structura într-un grup';
                }
                continue;
            }
            if (!isset($inGrup[$tabel])) {
                $p[] = $tabel . ': copiat, dar în niciun grup';
            }
            $pastreaza = (array) ($regula['pastreaza'] ?? []);
            $transforma = (array) ($regula['transforma'] ?? []);
            if (count(array_unique($pastreaza)) !== count($pastreaza)) {
                $p[] = $tabel . ': o coloană de două ori în pastreaza';
            }
            foreach (array_intersect($pastreaza, array_keys($transforma)) as $coloana) {
                $p[] = $tabel . '.' . $coloana . ': și păstrată, și transformată';
            }
            if ($mod === 'copiaza' && $transforma !== []) {
                $p[] = $tabel . ': un tabel „copiaza” nu are transformări (e „anonimizeaza”)';
            }
            if ($mod === 'anonimizeaza' && $transforma === []) {
                $p[] = $tabel . ': „anonimizeaza” fără transformări';
            }
            $toate = array_merge($pastreaza, array_keys($transforma));
            foreach ($toate as $coloana) {
                if (preg_match(self::TIPAR_NUME, (string) $coloana) !== 1) {
                    $p[] = $tabel . ': nume de coloană nepermis';
                }
            }
            foreach ((array) ($regula['motive'] ?? []) as $coloana => $motiv) {
                if (!in_array($coloana, $pastreaza, true) || trim((string) $motiv) === '') {
                    $p[] = $tabel . '.' . $coloana . ': motiv pentru o coloană care nu e păstrată, sau gol';
                }
            }
            foreach ($pastreaza as $coloana) {
                if (preg_match(CitireLive::TIPAR_PERSONAL, (string) $coloana) === 1 && trim((string) ($regula['motive'][$coloana] ?? '')) === '') {
                    $p[] = $tabel . '.' . $coloana . ': nume de date personale păstrat fără motiv';
                }
            }
            if (isset($regula['emailuri_publice']) && trim((string) $regula['emailuri_publice']) === '') {
                $p[] = $tabel . ': emailuri_publice fără motiv';
            }
            foreach ($transforma as $coloana => $t) {
                $nume = is_array($t) ? (string) ($t[0] ?? '') : '';
                if (!isset(self::TRANSFORMARI[$nume])) {
                    $p[] = $tabel . '.' . $coloana . ': transformare necunoscută';
                    continue;
                }
                foreach (array_keys($t) as $optiune) {
                    if ($optiune !== 0 && !in_array($optiune, self::TRANSFORMARI[$nume], true)) {
                        $p[] = $tabel . '.' . $coloana . ': opțiune necunoscută ' . $optiune;
                    }
                }
                foreach (['email', 'din', 'daca_nenul'] as $optiune) {
                    if (isset($t[$optiune]) && !in_array($t[$optiune], $toate, true)) {
                        $p[] = $tabel . '.' . $coloana . ': ' . $optiune . ' spre o coloană care nu e a tabelului';
                    }
                }
                if (isset($t['daca'])) {
                    foreach (array_keys((array) $t['daca']) as $c) {
                        if (!in_array($c, $toate, true)) {
                            $p[] = $tabel . '.' . $coloana . ': daca spre o coloană care nu e a tabelului';
                        }
                    }
                }
                if ($nume === 'valoare' && !array_key_exists('valoare', $t)) {
                    $p[] = $tabel . '.' . $coloana . ': valoare fără valoare';
                }
                if ($nume === 'nume' && !isset($t['email'])) {
                    $p[] = $tabel . '.' . $coloana . ': nume fără coloana emailului';
                }
                if ($nume === 'subiect' && !isset($t['din'])) {
                    $p[] = $tabel . '.' . $coloana . ': subiect fără din';
                }
            }
            if ($mod === 'setari' && $toate !== ['key', 'value']) {
                $p[] = $tabel . ': „setari” e doar tabelul key/value';
            }
        }
        foreach (array_keys($inGrup) as $tabel) {
            if (!isset($this->date['tabele'][$tabel])) {
                $p[] = $tabel . ': într-un grup, dar fără reguli';
            }
        }
        foreach ((array) ($this->date['modele'] ?? []) as $tipar => $baza) {
            if (@preg_match((string) $tipar, '') === false || !is_string($baza)) {
                $p[] = 'model greșit: ' . $tipar;
            }
        }
        $setari = (array) ($this->date['setari'] ?? []);
        foreach (['goale_tipar', 'proprietar_tipar', 'cunoscute_tipar'] as $cheie) {
            if (!is_string($setari[$cheie] ?? null) || @preg_match((string) $setari[$cheie], '') === false) {
                $p[] = 'setari.' . $cheie . ' nu e un tipar';
            }
        }
        foreach (['goale', 'zero', 'proprietar', 'casuta', 'prefix', 'aleatoare', 'cunoscute'] as $cheie) {
            if (!is_array($setari[$cheie] ?? null)) {
                $p[] = 'setari.' . $cheie . ' lipsă';
            }
        }
        if ($p === []) {
            // O cheie știută care arată a secret se neutralizează: lista albă nu lasă să treacă o parolă sau o cheie.
            foreach ((array) $setari['cunoscute'] as $cheie) {
                if (preg_match(self::TIPAR_SETARE_SECRETA, (string) $cheie) === 1 && !$this->setareNeutralizata((string) $cheie)) {
                    $p[] = 'setari.cunoscute: ' . $cheie . ' arată a secret, dar nu e neutralizată';
                }
            }
        }
        return $p;
    }

    /** Cheia e în lista neutralizării (golită, zero, a proprietarului, căsuța, prefixată, aleatoare) sau într-un tipar al ei. */
    public function setareNeutralizata(string $cheie): bool
    {
        $setari = (array) ($this->date['setari'] ?? []);
        foreach (['goale', 'zero', 'proprietar', 'casuta', 'prefix', 'aleatoare'] as $lista) {
            if (in_array($cheie, (array) ($setari[$lista] ?? []), true)) {
                return true;
            }
        }
        foreach (['goale_tipar', 'proprietar_tipar'] as $tipar) {
            if (is_string($setari[$tipar] ?? null) && preg_match($setari[$tipar], $cheie) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Scanerul setărilor (fail-closed, revizuirea WP-11b), înaintea oricărui rând și încă o dată pe fiecare rând copiat: o
     * cheie a sursei care nu e neutralizată și nu e știută de cod (`cunoscute`, `cunoscute_tipar`) e o problemă, cu numele
     * ei (un nume neobișnuit, doar lungimea). Lista goală = toate au regula lor.
     *
     * @param list<string> $chei
     * @return list<string>
     */
    public function scaneazaSetari(array $chei): array
    {
        $setari = (array) ($this->date['setari'] ?? []);
        $cunoscute = array_flip(array_map('strval', (array) ($setari['cunoscute'] ?? [])));
        $tipar = is_string($setari['cunoscute_tipar'] ?? null) ? $setari['cunoscute_tipar'] : '/(?!)/';
        $p = [];
        foreach ($chei as $cheie) {
            $cheie = (string) $cheie;
            if (preg_match('/^[a-z0-9_]{1,100}$/', $cheie) !== 1) {
                $p[] = 'settings: o cheie cu nume neobișnuit (' . mb_strlen($cheie) . ' caractere), fără regulă';
            } elseif (!isset($cunoscute[$cheie]) && preg_match($tipar, $cheie) !== 1 && !$this->setareNeutralizata($cheie)) {
                $p[] = 'settings.' . $cheie . (preg_match(self::TIPAR_SETARE_SECRETA, $cheie) === 1 ? ' (arată a secret)' : '')
                    . ': cheie necunoscută codului, fără regulă';
            }
        }
        return $p;
    }

    /** Regula unui tabel (și a tabelelor de lucru FAN, după `modele`), cu numele tabelului de bază; null = necunoscut. */
    public function tabel(string $tabel): ?array
    {
        if (isset($this->date['tabele'][$tabel])) {
            return $this->date['tabele'][$tabel] + ['_baza' => $tabel];
        }
        foreach ((array) ($this->date['modele'] ?? []) as $tipar => $baza) {
            if (preg_match((string) $tipar, $tabel) === 1) {
                $numeBaza = (string) preg_replace((string) $tipar, (string) $baza, $tabel);
                if (isset($this->date['tabele'][$numeBaza])) {
                    return $this->date['tabele'][$numeBaza] + ['_baza' => $numeBaza];
                }
            }
        }
        return null;
    }

    /** Grupul unui tabel copiat (după tabelul de bază), sau null. */
    public function grup(string $tabel): ?string
    {
        $regula = $this->tabel($tabel);
        if ($regula === null) {
            return null;
        }
        foreach ((array) $this->date['grupuri'] as $grup => $tabele) {
            if (in_array($regula['_baza'], $tabele, true)) {
                return (string) $grup;
            }
        }
        return null;
    }

    /** @return list<string> */
    public function grupuri(): array
    {
        return array_map('strval', array_keys((array) $this->date['grupuri']));
    }

    /**
     * Scanerul (fail-closed), înaintea oricărui rând copiat: pentru fiecare tabel al sursei, regula lui. Un tabel fără regulă
     * primește doar structura (și se spune pe nume). Într-un tabel copiat, fiecare coloană trebuie să fie în reguli: o coloană
     * necunoscută (oricare ar fi numele ei) oprește încărcarea, la fel una cu nume de date personale fără regulă sau motiv
     * (le prinde și `probleme()`), un tip de coloană pe care unealta nu-l copiază sigur, un nume de tabel sau de coloană
     * neobișnuit. Întoarce doar nume de structură.
     *
     * @param array<string, array{tip: string, coloane: array<string, array<string, mixed>>}> $structura
     * @return array{copiate: list<string>, doar_structura: list<string>, necunoscute: list<string>, probleme: list<string>, lipsa: list<string>}
     */
    public function scaneaza(array $structura): array
    {
        $r = ['copiate' => [], 'doar_structura' => [], 'necunoscute' => [], 'probleme' => [], 'lipsa' => []];
        foreach ($structura as $tabel => $info) {
            if (preg_match(self::TIPAR_NUME, $tabel) !== 1) {
                $r['probleme'][] = 'un tabel cu nume neobișnuit (' . strlen($tabel) . ' caractere)';
                continue;
            }
            $regula = $this->tabel($tabel);
            if ($regula === null) {
                $r['necunoscute'][] = $tabel;
                continue;
            }
            if ($regula['mod'] === 'doar_structura') {
                $r['doar_structura'][] = $tabel;
                continue;
            }
            $cunoscute = array_merge((array) ($regula['pastreaza'] ?? []), array_keys((array) ($regula['transforma'] ?? [])));
            foreach ($info['coloane'] as $coloana => $c) {
                if (preg_match(self::TIPAR_NUME, $coloana) !== 1) {
                    $r['probleme'][] = $tabel . ': o coloană cu nume neobișnuit';
                    continue;
                }
                if (!in_array($coloana, $cunoscute, true)) {
                    $r['probleme'][] = $tabel . '.' . $coloana . (preg_match(CitireLive::TIPAR_PERSONAL, $coloana) === 1
                        ? ' (nume de date personale)' : '') . ': coloană fără regulă';
                    continue;
                }
                if (!Copiere::tipCopiabil((string) $c['tip'])) {
                    $r['probleme'][] = $tabel . '.' . $coloana . ': tipul ' . preg_replace('/[^a-z0-9 ]/', '', strtolower((string) $c['tip'])) . ' nu se copiază';
                }
            }
            foreach ($cunoscute as $coloana) {
                if (!isset($info['coloane'][$coloana]) && !in_array($tabel . '.' . $coloana, $r['lipsa'], true)) {
                    $r['lipsa'][] = $tabel . '.' . $coloana;
                }
            }
            $r['copiate'][] = $tabel;
        }
        return $r;
    }
}

// ───────────────────────────────────────────────────────────────────────────
// Transformările (pseudonimele)
// ───────────────────────────────────────────────────────────────────────────

final class Transformari
{
    public const DOMENIU = 'verificare.invalid';
    /** O adresă de email într-un text (aceeași formă caută și A1 în coloanele de text). */
    public const TIPAR_EMAIL = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/';
    /** Costul bcrypt al parolelor aleatoare ale clienților (nimeni nu le știe; cu costul implicit, 10.000 de conturi ar dura minute). */
    public const COST_PAROLA = 4;

    /** Codurile unice de cupon: codul vechi (litere mici, fără spații) → codul nou. @var array<string, string> */
    public array $cupoane = [];

    public function __construct(private readonly string $sare)
    {
        if (strlen($sare) < 32) {
            throw new RuntimeException('sarea încărcării e prea scurtă');
        }
    }

    public function h(string $text): string
    {
        return hash_hmac('sha256', $text, $this->sare);
    }

    public static function gol(mixed $v): bool
    {
        return $v === null || (is_string($v) && trim($v) === '');
    }

    /** E: `u` + 12 hex din H(adresa cu litere mici, fără spații) + `@verificare.invalid`; gol rămâne gol. */
    public function email(mixed $v): mixed
    {
        if (self::gol($v)) {
            return $v;
        }
        return 'u' . substr($this->h('email:' . mb_strtolower(trim((string) $v))), 0, 12) . '@' . self::DOMENIU;
    }

    /** E pe fiecare adresă a unei liste (virgulă, punct și virgulă, spații), legate cu „, ”. */
    public function emailuri(mixed $v, ?int $max = null): mixed
    {
        if (self::gol($v)) {
            return $v;
        }
        $rezultat = [];
        foreach (preg_split('/[\s,;]+/', trim((string) $v)) ?: [] as $adresa) {
            if ($adresa === '') {
                continue;
            }
            $nou = (string) $this->email($adresa);
            if ($max !== null && mb_strlen(implode(', ', array_merge($rezultat, [$nou]))) > $max) {
                break;
            }
            $rezultat[] = $nou;
        }
        return implode(', ', $rezultat);
    }

    public function telefon(mixed $v): mixed
    {
        if (self::gol($v)) {
            return $v;
        }
        $cifre = (string) preg_replace('/\D+/', '', (string) $v);
        $h = $this->h('telefon:' . ($cifre !== '' ? $cifre : (string) $v));
        return '07' . sprintf('%08d', hexdec(substr($h, 0, 7)) % 100000000);
    }

    public function numeDinEmail(mixed $email, string $tabel, string $id): string
    {
        $cheie = self::gol($email) ? 'rand:' . $tabel . ':' . $id : 'email:' . mb_strtolower(trim((string) $email));
        return strtoupper(substr($this->h('nume:' . $cheie), 0, 6));
    }

    /**
     * 6 hex mari din H(valoarea, cu litere mici, fără spații la capete): același nume dă același pseudonim în orice tabel
     * (migrarea recenziilor vechi, de exemplu, potrivește reviews cu product_reviews după nume, ca pe site-ul adevărat).
     */
    public function hexValoare(string $fel, mixed $v): string
    {
        return strtoupper(substr($this->h($fel . ':' . mb_strtolower(trim((string) $v))), 0, 6));
    }

    public static function text(mixed $v): mixed
    {
        if (self::gol($v)) {
            return $v;
        }
        return '[text anonimizat, ' . mb_strlen((string) $v) . ' caractere]';
    }

    /**
     * Un text public păstrat (o recenzie aprobată: regula `text` cu `daca`, pe rândurile din afara condiției), cu fiecare
     * adresă de email din el schimbată cu pseudonimul ei (E): textul l-a scris un client, deci adresa e probabil a lui.
     * Restul textului rămâne cum e; o adresă deja @verificare.invalid rămâne.
     */
    public function faraEmailuri(mixed $v): mixed
    {
        if (self::gol($v) || !str_contains((string) $v, '@')) {
            return $v;
        }
        return (string) preg_replace_callback(self::TIPAR_EMAIL, fn (array $m): string => str_ends_with(strtolower($m[0]), '@' . self::DOMENIU)
            ? $m[0] : (string) $this->email($m[0]), (string) $v);
    }

    public static function parola(): string
    {
        return password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT, ['cost' => self::COST_PAROLA]);
    }

    /** Adresa FAN a unui AWB (FanCourierGateway::trackingUrl, aceeași formă). */
    public static function urlUrmarire(string $awb): string
    {
        if (class_exists(\App\Support\FanCourierGateway::class)) {
            return \App\Support\FanCourierGateway::trackingUrl($awb);
        }
        $a = urlencode($awb);
        return 'https://www.fancourier.ro/awb-tracking/?tracking=' . $a . '&awbnr=' . $a;
    }

    /**
     * Un rând, după regulile tabelului: coloanele din `transforma` calculate din rândul original (nu din cel deja schimbat),
     * restul copiate cum sunt. $coloane = informațiile sursei (nul, tip, lungime). Coloanele generate nu se scriu.
     *
     * @param array<string, mixed> $rand
     * @param array<string, array<string, mixed>> $coloane
     * @return array<string, mixed>
     */
    public function rand(string $tabel, array $regula, array $rand, array $coloane): array
    {
        $id = (string) ($rand['id'] ?? '');
        $nou = $rand;
        foreach ((array) ($regula['transforma'] ?? []) as $coloana => $t) {
            if (!array_key_exists($coloana, $rand)) {
                continue;
            }
            $info = $coloane[$coloana] ?? [];
            $v = $rand[$coloana];
            $max = isset($info['lungime']) && $info['lungime'] !== null ? (int) $info['lungime'] : null;
            $rezultat = match ($t[0]) {
                'email' => $this->email($v),
                'emailuri' => $this->emailuri($v, $max),
                'email_admin' => self::gol($v) ? $v : 'admin' . $id . '@' . self::DOMENIU,
                'telefon' => $this->telefon($v),
                'prenume' => self::gol($v) ? $v : 'Client',
                'nume' => self::gol($v) ? $v : $this->numeDinEmail($rand[$t['email']] ?? null, $tabel, $id),
                'nume_client' => self::gol($v) ? $v : 'Client ' . $this->hexValoare('client', $v),
                'nume_abonat' => self::gol($v) ? $v : 'Abonat ' . $this->hexValoare('abonat', $v),
                'adresa' => self::gol($v) ? $v : 'Strada Verificare ' . (((int) $id) % 200 + 1),
                'numar_strada' => self::gol($v) ? $v : '1',
                'cod_postal' => self::gol($v) ? $v : '000000',
                'text' => $this->conditie($t, $rand) ? self::text($v) : $this->faraEmailuri($v),
                'ref' => self::gol($v) ? $v : 'anon-' . $id,
                'parola' => self::parola(),
                'nul' => null,
                'valoare' => isset($t['daca_nenul']) && ($rand[$t['daca_nenul']] ?? null) === null ? $v : $t['valoare'],
                'firma' => self::gol($v) ? $v : 'Firma ' . $this->hexValoare('firma', $v) . ' SRL',
                'cui' => self::gol($v) ? $v : 'RO' . sprintf('%08d', hexdec(substr($this->h('cui:' . trim((string) $v)), 0, 7)) % 100000000),
                'reg_com' => self::gol($v) ? $v : 'J00/0000/2000',
                'subiect' => (string) ($rand[$t['din']] ?? ''),
                'token' => $this->tokenNou($v),
                'cod_cupon' => $this->codCupon($v, $rand, $id),
                'nume_cupon' => $this->numeCupon($v, $rand, $id),
                'cupon_comanda' => $this->cuponComanda($v),
                'awb' => self::gol($v) ? $v : 'DEMO' . $id,
                'awb_lista' => $this->awbLista($v, $rand, $id),
                'url_urmarire' => self::gol($v) ? $v : (self::gol($rand['fan_awb'] ?? null) ? null : self::urlUrmarire('DEMO' . $id)),
                'url_fara_interogare' => self::urlFaraInterogare($v),
            };
            $nou[$coloana] = self::potriveste($rezultat, $info);
        }
        return $nou;
    }

    /** O valoare nulă pe o coloană NOT NULL devine goală (sau 0); un text mai lung decât coloana se taie. */
    public static function potriveste(mixed $v, array $info): mixed
    {
        if ($v === null) {
            if (($info['nul'] ?? true) === true) {
                return null;
            }
            return in_array(strtolower((string) ($info['tip'] ?? '')), ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double'], true)
                ? 0 : '';
        }
        if (is_string($v) && isset($info['lungime']) && $info['lungime'] !== null && mb_strlen($v) > (int) $info['lungime']) {
            return mb_substr($v, 0, (int) $info['lungime']);
        }
        return $v;
    }

    private function conditie(array $t, array $rand): bool
    {
        foreach ((array) ($t['daca'] ?? []) as $coloana => $valoare) {
            if ((string) ($rand[$coloana] ?? '') !== (string) $valoare) {
                return false;
            }
        }
        return true;
    }

    private function tokenNou(mixed $v): string
    {
        do {
            $nou = bin2hex(random_bytes(32));
        } while (!self::gol($v) && hash_equals((string) $v, $nou));
        return $nou;
    }

    private function codCupon(mixed $v, array $rand, string $id): mixed
    {
        if ((int) ($rand['is_unique'] ?? 0) !== 1 || self::gol($v)) {
            return $v;
        }
        $nou = 'VERIF-' . $id;
        $this->cupoane[mb_strtolower(trim((string) $v))] = $nou;
        return $nou;
    }

    /** Numele unui cupon unic (un voucher dat cuiva, poate cu numele lui): „Cupon unic VERIF-<id>”; al unuia obișnuit rămâne. */
    private function numeCupon(mixed $v, array $rand, string $id): mixed
    {
        if ((int) ($rand['is_unique'] ?? 0) !== 1 || self::gol($v)) {
            return $v;
        }
        return 'Cupon unic VERIF-' . $id;
    }

    private function cuponComanda(mixed $v): mixed
    {
        if (self::gol($v)) {
            return $v;
        }
        $cheie = mb_strtolower(trim((string) $v));
        if (isset($this->cupoane[$cheie])) {
            return $this->cupoane[$cheie];
        }
        return $v;
    }

    /** JSON-ul coletelor (fan_awb_list, fan_awb_inlocuite): fiecare AWB rescris; adresa de urmărire refăcută. */
    private function awbLista(mixed $v, array $rand, string $id): mixed
    {
        if (self::gol($v)) {
            return $v;
        }
        $lista = json_decode((string) $v, true);
        if (!is_array($lista)) {
            return null;
        }
        $vechi = trim((string) ($rand['fan_awb'] ?? ''));
        $n = 0;
        foreach ($lista as $i => $intrare) {
            $n++;
            if (!is_array($intrare)) {
                $lista[$i] = 'DEMO' . $id . '-' . $n;
                continue;
            }
            if (isset($intrare['awb'])) {
                $awb = $vechi !== '' && trim((string) $intrare['awb']) === $vechi ? 'DEMO' . $id : 'DEMO' . $id . '-' . $n;
                $lista[$i]['awb'] = $awb;
                if (isset($intrare['tracking_url'])) {
                    $lista[$i]['tracking_url'] = self::urlUrmarire($awb);
                }
            }
        }
        return (string) json_encode($lista, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function urlFaraInterogare(mixed $v): mixed
    {
        if (self::gol($v)) {
            return $v;
        }
        $parti = parse_url(trim((string) $v));
        if (!is_array($parti) || !isset($parti['path']) && !isset($parti['host'])) {
            return null;
        }
        return (isset($parti['scheme'], $parti['host']) ? strtolower($parti['scheme']) . '://' . strtolower($parti['host'])
            . (isset($parti['port']) ? ':' . (int) $parti['port'] : '') : '') . ($parti['path'] ?? '/');
    }

    /** Valoarea unei setări după neutralizare, sau null dacă cheia nu e în listă. */
    public function setare(string $cheie, mixed $valoare, array $setari, string $proprietar, string $casuta): mixed
    {
        if (in_array($cheie, (array) $setari['goale'], true) || preg_match((string) $setari['goale_tipar'], $cheie) === 1) {
            return '';
        }
        if (in_array($cheie, (array) $setari['zero'], true)) {
            return '0';
        }
        if (in_array($cheie, (array) $setari['proprietar'], true) || preg_match((string) $setari['proprietar_tipar'], $cheie) === 1) {
            return $proprietar;
        }
        if (in_array($cheie, (array) $setari['casuta'], true)) {
            return $casuta;
        }
        if (in_array($cheie, (array) $setari['prefix'], true)) {
            $text = trim((string) $valoare);
            return str_starts_with($text, 'VERIFICARE') ? $text : trim('VERIFICARE ' . $text);
        }
        if (in_array($cheie, (array) $setari['aleatoare'], true)) {
            return substr($this->h('setare:' . $cheie), 0, 24);
        }
        return null;
    }
}

// ───────────────────────────────────────────────────────────────────────────
// Sursa: baza site-ului live, strict în citire
// ───────────────────────────────────────────────────────────────────────────

final class SursaCopie
{
    /** Cel mult atâtea secunde de așteptat un răspuns al bazei (mysqlnd.net_read_timeout). */
    public const TIMP_RETEA = 120;

    private bool $inInstantaneu = false;

    private function __construct(private readonly PDO $db, public readonly string $familie, public readonly string $versiune)
    {
    }

    /**
     * Conexiunea cu datele din `.env`-ul site-ului (doar DB_*), apoi sesiunea doar de citire (verificată: altfel nu se citește
     * nimic), REPEATABLE READ, fusul +00:00 (ca TIMESTAMP-urile să treacă neschimbate), așteptare scurtă după un lacăt.
     *
     * @param array<string, string> $c
     */
    public static function deschide(array $c): self
    {
        $gazda = trim($c['DB_HOST'] ?? '') !== '' ? trim($c['DB_HOST']) : '127.0.0.1';
        $port = (int) (trim($c['DB_PORT'] ?? '') !== '' ? $c['DB_PORT'] : 3306);
        @ini_set('mysqlnd.net_read_timeout', (string) self::TIMP_RETEA);
        try {
            $db = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $gazda, $port, (string) ($c['DB_DATABASE'] ?? '')),
                (string) ($c['DB_USERNAME'] ?? ''), (string) ($c['DB_PASSWORD'] ?? ''), [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 5,
                    PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
                    PDO::MYSQL_ATTR_LOCAL_INFILE => false,
                ]);
        } catch (PDOException $e) {
            throw new Refuz('legătura cu baza site-ului nu merge (' . CitireLive::cod($e) . ')');
        } finally {
            unset($c);
        }
        try {
            $versiune = (string) $db->query('SELECT VERSION()')->fetchColumn();
            $familie = stripos($versiune, 'mariadb') !== false ? 'mariadb' : 'mysql';
            $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->exec('SET SESSION TRANSACTION READ ONLY');
            $db->exec("SET time_zone = '+00:00'");
            $db->exec('SET SESSION lock_wait_timeout = 5');
            $db->exec('SET SESSION innodb_lock_wait_timeout = 5');
            // O copiere lungă (un tabel mare citit fără buffer) nu are voie să fie oprită de limita pe instrucțiune a contului.
            foreach (['mariadb' => 'SET SESSION max_statement_time = 0', 'mysql' => 'SET SESSION max_execution_time = 0'] as $f => $sql) {
                if ($f === $familie) {
                    try {
                        $db->exec($sql);
                    } catch (PDOException) {
                    }
                }
            }
            try {
                $db->exec('SET SESSION net_write_timeout = 600');
            } catch (PDOException) {
            }
            $doarCitire = null;
            foreach (['@@SESSION.transaction_read_only', '@@SESSION.tx_read_only'] as $variabila) {
                try {
                    $doarCitire = (int) $db->query('SELECT ' . $variabila)->fetchColumn();
                    break;
                } catch (PDOException) {
                }
            }
            if ($doarCitire !== 1) {
                throw new Refuz('sesiunea bazei sursei nu a trecut în modul doar de citire; nu citesc nimic');
            }
        } catch (PDOException $e) {
            throw new Refuz('sesiunea doar de citire a sursei nu se poate pregăti (' . CitireLive::cod($e) . ')');
        }
        return new self($db, $familie, preg_match('/^\d+\.\d+\.\d+/', $versiune, $m) === 1 ? $m[0] : '0.0.0');
    }

    public static function cita(string $nume): string
    {
        if (preg_match(Reguli::TIPAR_NUME, $nume) !== 1) {
            throw new InstructiuneRefuzata('nume de tabel sau coloană nepermis');
        }
        return '`' . $nume . '`';
    }

    /** Doar SELECT sau SHOW, una singură, fără comentarii și fără cuvintele unei scrieri sau ale unui lacăt. */
    public static function verificaInstructiunea(string $sql): void
    {
        $s = trim($sql);
        if (preg_match('/^(SELECT|SHOW)\s/i', $s) !== 1) {
            throw new InstructiuneRefuzata('doar SELECT sau SHOW');
        }
        if (str_contains($s, ';') || str_contains($s, '--') || str_contains($s, '#') || str_contains($s, '/*')) {
            throw new InstructiuneRefuzata('o singură instrucțiune, fără comentarii');
        }
        // Structura unui tabel: doar forma exactă, cu numele lui (CREATE e altfel un cuvânt interzis).
        if (preg_match('/^SHOW CREATE TABLE `[A-Za-z0-9_$]{1,64}`$/', $s) === 1) {
            return;
        }
        if (preg_match('/\b(INTO|UPDATE|INSERT|DELETE|REPLACE|CREATE|DROP|ALTER|GRANT|CALL|HANDLER|LOCK|SHARE|SLEEP|BENCHMARK|LOAD_FILE|GET_LOCK|'
            . 'RELEASE_LOCK|RELEASE_ALL_LOCKS|IS_FREE_LOCK|MASTER_POS_WAIT|DUMPFILE|OUTFILE|NOWAIT|SKIP\s+LOCKED)\b/i', $s) === 1) {
            throw new InstructiuneRefuzata('cuvânt interzis într-o citire');
        }
    }

    /** O citire mică (structura, numărători), cu buffer. @return list<array<string, mixed>> */
    public function randuri(string $sql, array $parametri = []): array
    {
        self::verificaInstructiunea($sql);
        if ($parametri === []) {
            $stmt = $this->db->query($sql);
        } else {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($parametri);
        }
        $randuri = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $randuri;
    }

    /**
     * Tabelele bazei, cu coloanele lor (tipul, NULL, lungimea, generată sau nu), din information_schema.
     *
     * @return array<string, array{tip: string, coloane: array<string, array<string, mixed>>}>
     */
    public function structura(): array
    {
        $tabele = [];
        foreach ($this->randuri('SELECT TABLE_NAME AS t, TABLE_TYPE AS tip FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME') as $r) {
            $tabele[(string) $r['t']] = ['tip' => (string) $r['tip'], 'coloane' => []];
        }
        foreach ($this->randuri('SELECT TABLE_NAME AS t, COLUMN_NAME AS c, DATA_TYPE AS tip, IS_NULLABLE AS nul, CHARACTER_MAXIMUM_LENGTH AS lungime, '
            . 'EXTRA AS extra, GENERATION_EXPRESSION AS generare FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() '
            . 'ORDER BY TABLE_NAME, ORDINAL_POSITION') as $r) {
            if (!isset($tabele[(string) $r['t']])) {
                continue;
            }
            $tabele[(string) $r['t']]['coloane'][(string) $r['c']] = [
                'tip' => strtolower((string) $r['tip']),
                'nul' => strtoupper((string) $r['nul']) === 'YES',
                'lungime' => $r['lungime'] !== null && (int) $r['lungime'] > 0 && (int) $r['lungime'] < 1 << 24 ? (int) $r['lungime'] : null,
                'generata' => preg_match('/\b(VIRTUAL|STORED|PERSISTENT)\b/i', (string) $r['extra']) === 1 && stripos((string) $r['extra'], 'DEFAULT_GENERATED') === false
                    || trim((string) ($r['generare'] ?? '')) !== '',
            ];
        }
        return $tabele;
    }

    /** Vederile, declanșatoarele, rutinele și evenimentele bazei (o copie nu le ia: încărcarea se oprește). @return array<string, int> */
    public function obiecteInterzise(): array
    {
        return [
            'vederi' => (int) $this->randuri("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'VIEW'")[0]['n'],
            'declanșatoare' => (int) $this->randuri('SELECT COUNT(*) AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')[0]['n'],
            'rutine' => (int) $this->randuri('SELECT COUNT(*) AS n FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()')[0]['n'],
            'evenimente' => (int) $this->randuri('SELECT COUNT(*) AS n FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()')[0]['n'],
        ];
    }

    public function creareTabel(string $tabel): string
    {
        $r = $this->randuri('SHOW CREATE TABLE ' . self::cita($tabel));
        $rand = array_values($r[0] ?? []);
        return (string) ($rand[1] ?? '');
    }

    public function sqlMode(): string
    {
        return (string) ($this->randuri('SELECT @@SESSION.sql_mode AS m')[0]['m'] ?? '');
    }

    /**
     * Un grup de citiri într-un singur instantaneu consecvent (START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY): fără
     * lacăte pe rândurile site-ului, iar o scriere ar fi refuzată de server. Se închide (ROLLBACK) orice ar fi.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function instantaneu(callable $fn): mixed
    {
        $this->db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        $this->inInstantaneu = true;
        try {
            return $fn();
        } finally {
            $this->inInstantaneu = false;
            try {
                $this->db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
    }

    public function numara(string $tabel): int
    {
        if (!$this->inInstantaneu) {
            throw new InstructiuneRefuzata('numărare în afara instantaneului');
        }
        return (int) ($this->randuri('SELECT COUNT(*) AS n FROM ' . self::cita($tabel))[0]['n'] ?? 0);
    }

    /**
     * Rândurile unui tabel, unul câte unul, fără buffer (memoria PHP ține un rând, nu tabelul), în instantaneul deschis.
     *
     * @param list<string> $coloane
     * @param callable(array<string, mixed>): void $fn
     */
    public function parcurge(string $tabel, array $coloane, callable $fn): int
    {
        if (!$this->inInstantaneu) {
            throw new InstructiuneRefuzata('citire în afara instantaneului');
        }
        $sql = 'SELECT ' . implode(', ', array_map([self::class, 'cita'], $coloane)) . ' FROM ' . self::cita($tabel);
        self::verificaInstructiunea($sql);
        $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $stmt = null;
        $n = 0;
        try {
            $stmt = $this->db->query($sql);
            while (($rand = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $n++;
                $fn($rand);
            }
            $stmt->closeCursor();
        } finally {
            $stmt = null;
            $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
        return $n;
    }

    /** Pentru teste: o scriere în sesiunea sursei (serverul trebuie s-o refuze). */
    public function incearcaScriere(string $sql): ?string
    {
        try {
            $this->db->exec($sql);
            return null;
        } catch (PDOException $e) {
            return CitireLive::cod($e);
        }
    }
}

// ───────────────────────────────────────────────────────────────────────────
// Copia: baza site-ului de verificare
// ───────────────────────────────────────────────────────────────────────────

final class Copiere
{
    public const TABEL_MARCAJ = '_verificare';
    /** Un lot de rânduri: cel mult atâtea rânduri, atâția octeți și atâția parametri. */
    public const RANDURI_LOT = 500;
    public const OCTETI_LOT = 4 * 1048576;
    public const PARAMETRI_LOT = 60000;
    private const TIPURI = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real', 'bit',
        'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json', 'enum', 'set', 'date', 'datetime', 'timestamp', 'time', 'year',
        'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'uuid', 'inet4', 'inet6'];
    private const BINARE = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob'];

    /** @var array<string, PDOStatement> */
    private array $insert = [];

    private function __construct(public readonly PDO $db)
    {
    }

    public static function tipCopiabil(string $tip): bool
    {
        return in_array(strtolower($tip), self::TIPURI, true);
    }

    /** @param array<string, string> $env */
    public static function deschide(array $env): self
    {
        try {
            $db = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', trim((string) ($env['DB_HOST'] ?? '')) !== '' ? trim((string) $env['DB_HOST']) : 'localhost',
                (int) ($env['DB_PORT'] ?? 3306) ?: 3306, (string) ($env['DB_DATABASE'] ?? '')), (string) ($env['DB_USERNAME'] ?? ''), (string) ($env['DB_PASSWORD'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
                PDO::MYSQL_ATTR_LOCAL_INFILE => false,
            ]);
            $db->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            throw new Refuz('baza copiei (DB_* din .env) nu se deschide (' . CitireLive::cod($e) . ')');
        }
        return new self($db);
    }

    /** Tabelele bazei copiei: nume → tip (BASE TABLE / VIEW). @return array<string, string> */
    public function tabele(): array
    {
        $r = [];
        foreach ($this->db->query('SELECT TABLE_NAME AS t, TABLE_TYPE AS tip FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll() as $rand) {
            $r[(string) $rand['t']] = (string) $rand['tip'];
        }
        ksort($r);
        return $r;
    }

    /** Rândul din `_verificare` (null dacă tabelul lipsește). */
    public function marcaj(): ?array
    {
        if (!isset($this->tabele()[self::TABEL_MARCAJ])) {
            return null;
        }
        $rand = $this->db->query('SELECT * FROM `' . self::TABEL_MARCAJ . '` LIMIT 1')->fetch();
        return is_array($rand) ? $rand : [];
    }

    /**
     * `_verificare`, primul tabel al unei încărcări (înțelegerea cu sterge.php): sursa, `anonimizat = 0`, pasul, grupurile
     * terminate și numărătorile lor, versiunea regulilor, commit-ul sursei, momentul începerii; la final `anonimizat = 1` și
     * `incarcat_la`.
     *
     * @param array<string, mixed> $date
     */
    public function scrieMarcaj(array $date): void
    {
        $this->db->exec('CREATE TABLE `' . self::TABEL_MARCAJ . '` (
            `id` TINYINT UNSIGNED NOT NULL,
            `sursa` VARCHAR(40) NOT NULL,
            `anonimizat` TINYINT(1) NOT NULL DEFAULT 0,
            `pas` VARCHAR(40) NOT NULL,
            `grupuri_gata` TEXT NULL,
            `randuri` MEDIUMTEXT NULL,
            `verificari` TEXT NULL,
            `versiune_reguli` VARCHAR(40) NOT NULL,
            `cod_sursa_commit` VARCHAR(40) NULL,
            `admin_proprietar_id` INT UNSIGNED NULL,
            `inceput_la` DATETIME NOT NULL,
            `incarcat_la` DATETIME NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->db->prepare('INSERT INTO `' . self::TABEL_MARCAJ . '` (id, sursa, anonimizat, pas, grupuri_gata, randuri, verificari, versiune_reguli,
            cod_sursa_commit, inceput_la) VALUES (1, :sursa, 0, :pas, :g, :r, :v, :versiune, :commit, UTC_TIMESTAMP())')->execute([
            'sursa' => (string) $date['sursa'], 'pas' => 'inceput', 'g' => '[]', 'r' => '{}', 'v' => '{}',
            'versiune' => (string) $date['versiune_reguli'], 'commit' => $date['cod_sursa_commit'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $campuri */
    public function actualizeazaMarcaj(array $campuri): void
    {
        $set = [];
        foreach (array_keys($campuri) as $camp) {
            $set[] = '`' . $camp . '` = :' . $camp;
        }
        $this->db->prepare('UPDATE `' . self::TABEL_MARCAJ . '` SET ' . implode(', ', $set) . ' WHERE id = 1')->execute($campuri);
    }

    /**
     * Golește baza copiei: vederile, tabelele (`_verificare` ultimul, ca o ștergere oprită la jumătate să lase tot o copie de
     * verificare), rutinele, evenimentele. Întoarce câte tabele a șters.
     */
    public function goleste(): int
    {
        $this->insert = [];
        try {
            $this->db->exec('ROLLBACK');
        } catch (Throwable) {
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tabele = $this->tabele();
        uksort($tabele, static fn (string $a, string $b): int => ($a === self::TABEL_MARCAJ ? 1 : 0) - ($b === self::TABEL_MARCAJ ? 1 : 0) ?: strcmp($a, $b));
        $n = 0;
        foreach ($tabele as $nume => $tip) {
            $this->db->exec(($tip === 'VIEW' ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ') . '`' . str_replace('`', '``', $nume) . '`');
            $n++;
        }
        foreach ($this->db->query('SELECT ROUTINE_TYPE AS t, ROUTINE_NAME AS n FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()')->fetchAll() as $r) {
            $this->db->exec('DROP ' . ($r['t'] === 'FUNCTION' ? 'FUNCTION' : 'PROCEDURE') . ' IF EXISTS `' . str_replace('`', '``', (string) $r['n']) . '`');
        }
        foreach ($this->db->query('SELECT EVENT_NAME AS n FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()')->fetchAll() as $r) {
            $this->db->exec('DROP EVENT IF EXISTS `' . str_replace('`', '``', (string) $r['n']) . '`');
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
        return $n;
    }

    /** Structura tabelelor sursei pe copie (SHOW CREATE TABLE, cu sql_mode-ul sursei), fără să atingă `_verificare`. */
    public function creeazaStructura(SursaCopie $sursa, array $tabele): void
    {
        $this->db->exec('SET SESSION sql_mode = ' . $this->db->quote($sursa->sqlMode()));
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_keys($this->tabele()) as $existent) {
            if ($existent !== self::TABEL_MARCAJ) {
                $this->db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $existent) . '`');
            }
        }
        foreach ($tabele as $tabel) {
            $sql = $sursa->creareTabel($tabel);
            if (!preg_match('/^CREATE TABLE `' . preg_quote($tabel, '/') . '`/', $sql)) {
                throw new EsecIncarcare('structura tabelului ' . $tabel . ' nu se poate citi din sursă');
            }
            try {
                $this->db->exec($sql);
            } catch (PDOException $e) {
                throw new EsecIncarcare('tabelul ' . $tabel . ' nu se poate face pe copie (' . CitireLive::cod($e) . ')');
            }
        }
    }

    /**
     * Scrie un lot de rânduri (un INSERT cu mai multe rânduri), cu tipurile potrivite: NULL, întregi, texte (binarele ca LOB).
     *
     * @param list<string> $coloane
     * @param list<array<string, mixed>> $randuri
     * @param array<string, array<string, mixed>> $info
     */
    public function insereaza(string $tabel, array $coloane, array $randuri, array $info): void
    {
        if ($randuri === []) {
            return;
        }
        $cheie = $tabel . '|' . count($randuri);
        if (!isset($this->insert[$cheie])) {
            $semne = '(' . implode(', ', array_fill(0, count($coloane), '?')) . ')';
            $this->insert[$cheie] = $this->db->prepare('INSERT INTO ' . SursaCopie::cita($tabel) . ' (' . implode(', ', array_map([SursaCopie::class, 'cita'], $coloane))
                . ') VALUES ' . implode(', ', array_fill(0, count($randuri), $semne)));
        }
        $stmt = $this->insert[$cheie];
        $i = 1;
        foreach ($randuri as $rand) {
            foreach ($coloane as $coloana) {
                $v = $rand[$coloana] ?? null;
                if ($v === null) {
                    $stmt->bindValue($i++, null, PDO::PARAM_NULL);
                } elseif (is_int($v)) {
                    $stmt->bindValue($i++, $v, PDO::PARAM_INT);
                } elseif (is_bool($v)) {
                    $stmt->bindValue($i++, $v ? 1 : 0, PDO::PARAM_INT);
                } elseif (in_array((string) ($info[$coloana]['tip'] ?? ''), self::BINARE, true)) {
                    $stmt->bindValue($i++, (string) $v, PDO::PARAM_LOB);
                } else {
                    $stmt->bindValue($i++, is_float($v) ? self::numar($v) : (string) $v, PDO::PARAM_STR);
                }
            }
        }
        $stmt->execute();
        $stmt->closeCursor();
    }

    /** Un float ca text, fără să piardă cifre (forma cea mai scurtă care se citește înapoi la fel). */
    private static function numar(float $v): string
    {
        $text = var_export($v, true);
        return str_contains($text, 'E') || str_contains($text, 'e') ? sprintf('%.17G', $v) : $text;
    }

    /** Câte rânduri pe lot, după numărul de coloane (limita de parametri a unei instrucțiuni pregătite). */
    public static function randuriPeLot(int $coloane): int
    {
        return max(1, min(self::RANDURI_LOT, intdiv(self::PARAMETRI_LOT, max(1, $coloane))));
    }

    public function numara(string $tabel): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM ' . SursaCopie::cita($tabel))->fetchColumn();
    }
}

// ───────────────────────────────────────────────────────────────────────────
// Rularea
// ───────────────────────────────────────────────────────────────────────────

final class Incarcare
{
    /** Fișierul cu sarea încărcării, cât încărcarea nu e terminată (--reia dă aceleași pseudonime): în storage/verificare/tmp/. */
    public const FISIER_SARE = 'sare-incarcare';
    public const PASI = ['inceput', 'schema', 'date', 'setari', 'admin', 'cod_vechi', 'migrari', 'verificari', 'licenta', 'gata'];
    /** Coduri de ieșire: 0 gata; 1 oprită, copia ștearsă; 2 refuzată, nimic atins; 3 oprită, se continuă cu --reia. */
    public const COD_GATA = 0;
    public const COD_STEARSA = 1;
    public const COD_REFUZ = 2;
    public const COD_REIA = 3;
    /**
     * Tabelele care nu au voie să aibă niciun rând pe copie, oricare ar fi regulile (planul de verificare §3.5.2; A2 le
     * numără pe acestea, nu doar pe cele „doar_structura” din reguli: o regulă schimbată greșit în „copiaza” tot pică).
     */
    public const FARA_RANDURI = ['gdpr_agreements', 'admin_activity_log', 'bt_ipay_log', 'checkout_submit_logs', 'register_submit_logs',
        'customer_password_resets', 'cart_abandonments', 'customer_email_confirmations', 'quote_submit_logs',
        // WP-R din 30 oct (newsletterul): lista „nu trimite” (amprenta adresei unui abonat șters) și jurnalul cererilor de
        // confirmare (amprenta adresei, IP-ul).
        'newsletter_suprimate', 'newsletter_cereri_confirmare'];

    private string $radacina;
    private string $acasa;
    private string $gazda;
    /** @var array<string, string> */
    private array $env = [];
    /** @var array<string, string|true> */
    private array $arg = [];
    private ?Reguli $reguli = null;
    private ?SiteLive $site = null;
    /** @var array<string, string> */
    private array $conexiuneSursa = [];
    private ?SursaCopie $sursa = null;
    private ?Copiere $copie = null;
    private ?Transformari $t = null;
    private string $sursaNume = '';
    private float $start;
    private float $buget = 0.0;
    /** @var list<string> */
    private array $raport = [];
    private bool $atinsa = false;
    private string $pas = 'inceput';
    private ?string $commitSursa = null;
    private string $emailProprietar = '';
    private string $parolaProprietar = '';
    private string $parolaPersonal = '';
    private string $pachet = '';
    private string $licenta = '';

    public static function ruleaza(array $argv, string $radacina): int
    {
        $incarcare = new self();
        $incarcare->radacina = (string) realpath($radacina);
        $incarcare->start = microtime(true);
        Rulare::laOprire(static function (string $motiv) use ($incarcare): int {
            $incarcare->spune('');
            $text = 'OPRIT: ' . $motiv . ' (mesajul PHP nu se tipărește: are calea contului). ';
            if (!$incarcare->atinsa) {
                $incarcare->spune($text . 'Nu am atins nimic.');
                return self::COD_REFUZ;
            }
            $incarcare->incheie($text . 'Copia neterminată rămâne închisă (fără incarcat-la): ' . ($incarcare->sursaNume === 'demo'
                ? 'fă-o din nou cu --demo --reincarca' : 'rulează din nou cu --reia') . ', sau o ștergi cu sterge.php.');
            return self::COD_REIA;
        });
        try {
            return $incarcare->executa($argv);
        } catch (Refuz $e) {
            // Codul 2 spune „nimic atins”: un refuz venit după ce copia s-a schimbat (nu ar trebui să existe) e o oprire, cu
            // ștergerea copiei și ultimul rând OPRIT, ca orice altă eroare de după acel punct.
            if ($incarcare->atinsa) {
                return $incarcare->esec($e->getMessage());
            }
            fwrite(STDERR, 'REFUZAT: ' . $e->getMessage() . "\n");
            return self::COD_REFUZ;
        } catch (Intrerupere $e) {
            $incarcare->spune('');
            $incarcare->incheie('ÎNTRERUPT: ' . $incarcare->faraNumeBaza($e->getMessage()) . '. Copia neterminată rămâne închisă (fără incarcat-la). '
                . ($incarcare->sursaNume === 'demo' ? 'Fă-o din nou cu: ' . PhpCli::comanda('tools/verificare/incarca.php --demo --reincarca')
                    : 'Continuă cu: ' . PhpCli::comanda('tools/verificare/incarca.php --din=' . $incarcare->sursaNume . ' --reia')));
            return self::COD_REIA;
        } catch (Throwable $e) {
            $motiv = $e instanceof EsecIncarcare || $e instanceof InstructiuneRefuzata ? $e->getMessage()
                : 'eroare neprevăzută ' . (new \ReflectionClass($e))->getShortName() . ($e instanceof PDOException ? ' (' . CitireLive::cod($e) . ')' : '')
                    . ' în ' . basename($e->getFile()) . ':' . $e->getLine();
            return $incarcare->esec($motiv);
        } finally {
            Rulare::laOprire(null);
        }
    }

    public function spune(string $text): void
    {
        $text = $this->faraNumeBaza($text);
        fwrite(STDOUT, $text . "\n");
        $this->raport[] = $text;
    }

    /**
     * Numele bazei copiei și al utilizatorului ei nu apar pe ecran (textul runner-ului migrărilor, de exemplu, scrie „baza
     * <nume>”): în locul lor, „copiei” și „<utilizatorul bazei>”.
     */
    private function faraNumeBaza(string $text): string
    {
        foreach (['DB_DATABASE' => 'copiei', 'DB_USERNAME' => '<utilizatorul bazei>'] as $cheie => $inLoc) {
            $nume = trim($this->env[$cheie] ?? '');
            if (strlen($nume) >= 3) {
                $text = str_replace(['baza ' . $nume, $nume], ['baza ' . $inLoc, $inLoc], $text);
            }
        }
        return $text;
    }

    /** Ultimul rând (GATA, OPRIT, ÎNTRERUPT): scris și în textul salvat, apoi tipărit după calea lui, ca să rămână ultimul pe ecran. */
    private function incheie(string $ultimul): void
    {
        $this->raport[] = $ultimul;
        $this->salveazaRaportul();
        fwrite(STDOUT, $ultimul . "\n");
    }

    private function rand(bool $ok, string $text): void
    {
        $this->spune('  ' . str_pad($ok ? 'DA' : 'NU', 6) . $text);
    }

    private function info(string $text): void
    {
        $this->spune('  INFO  ' . $text);
    }

    private function durata(float $de): string
    {
        $s = microtime(true) - $de;
        return $s < 60 ? number_format($s, 1, ',', '') . ' s' : (int) floor($s / 60) . ' min ' . (int) round(fmod($s, 60)) . ' s';
    }

    /** O încărcare care a atins copia și s-a oprit: copia se șterge singură (baza, incarcat-la, codul vechi, sarea). */
    private function esec(string $motiv): int
    {
        $motiv = $this->faraNumeBaza($motiv);
        $this->spune('');
        $this->spune('  NU    ' . $motiv);
        if (!$this->atinsa) {
            fwrite(STDERR, 'REFUZAT: ' . $motiv . ". Nu am atins nimic.\n");
            return self::COD_REFUZ;
        }
        $sters = $this->sterge();
        $this->spune('');
        $this->incheie($sters
            ? 'OPRIT: încărcarea nu s-a terminat, iar copia a fost ștearsă singură (baza goală, fără incarcat-la, fără codul vechi). '
                . 'Trimite-ne textul de mai sus (nu conține date de clienți).'
            : 'OPRIT: încărcarea nu s-a terminat și copia NU s-a putut șterge singură: rulează ' . PhpCli::comanda('tools/verificare/sterge.php') . ', '
                . 'apoi trimite-ne textul de mai sus.');
        return self::COD_STEARSA;
    }

    /** Ștergerea automată. Întoarce true dacă baza e goală și fișierele copiei nu mai sunt. */
    private function sterge(): bool
    {
        $bine = true;
        try {
            $this->copie?->goleste();
            $bine = $this->copie !== null && $this->copie->tabele() === [];
        } catch (Throwable) {
            $bine = false;
        }
        $this->curataFisiere(true);
        $codVechi = dirname($this->radacina) . '/cod-vechi';
        if (is_link($codVechi)) {
            @unlink($codVechi);
        } elseif (is_dir($codVechi)) {
            self::stergeDosar($codVechi);
        }
        clearstatcache();
        return $bine && !file_exists($codVechi) && !file_exists($this->fisierIncarcat());
    }

    private function fisierIncarcat(): string
    {
        return \App\Support\Verificare::dosar() . '/incarcat-la';
    }

    private function fisierSare(): string
    {
        return \App\Support\Verificare::dosar() . '/tmp/' . self::FISIER_SARE;
    }

    /**
     * Fișierele copiei de dinainte: incarcat-la (poarta arată „lipsește” până la final), emailurile ca fișiere, cache-ul
     * (paginile copiei vechi), sesiunile (logările pe copia veche), pozele copiei demonstrative; cu $siSarea și sarea.
     */
    private function curataFisiere(bool $siSarea): void
    {
        $dosar = \App\Support\Verificare::dosar();
        @unlink($dosar . '/incarcat-la');
        $pastreaza = ['.gitkeep', '.gitignore', '.htaccess'];
        self::golesteDosar($dosar . '/mail', $pastreaza);
        foreach ((array) @scandir($dosar . '/tmp') as $nume) {
            if (is_string($nume) && $nume !== '.' && $nume !== '..' && !in_array($nume, $pastreaza, true) && ($siSarea || $nume !== self::FISIER_SARE)) {
                $cale = $dosar . '/tmp/' . $nume;
                is_dir($cale) && !is_link($cale) ? self::stergeDosar($cale) : @unlink($cale);
            }
        }
        self::golesteDosar(\App\Support\Paths::stocare('cache'), $pastreaza);
        foreach (glob(\App\Support\Paths::stocare('sessions') . '/sess_*') ?: [] as $sesiune) {
            @unlink($sesiune);
        }
        $demo = \App\Support\Paths::uploads('demo');
        if (is_link($demo)) {
            @unlink($demo);
        } elseif (is_dir($demo)) {
            self::stergeDosar($demo);
        }
    }

    private static function golesteDosar(string $dosar, array $pastreaza): void
    {
        if (is_link($dosar) || !is_dir($dosar)) {
            return;
        }
        foreach ((array) @scandir($dosar) as $nume) {
            if (!is_string($nume) || $nume === '.' || $nume === '..' || in_array($nume, $pastreaza, true)) {
                continue;
            }
            $cale = $dosar . '/' . $nume;
            if (is_dir($cale) && !is_link($cale)) {
                self::golesteDosar($cale, $pastreaza);
                @rmdir($cale);
            } else {
                @unlink($cale);
            }
        }
    }

    /** Șterge un dosar cu tot ce e în el, fără să urmeze legăturile (o legătură se șterge ea însăși). */
    public static function stergeDosar(string $dosar): void
    {
        if (is_link($dosar)) {
            @unlink($dosar);
            return;
        }
        if (!is_dir($dosar)) {
            return;
        }
        foreach ((array) @scandir($dosar) as $nume) {
            if (!is_string($nume) || $nume === '.' || $nume === '..') {
                continue;
            }
            $cale = $dosar . '/' . $nume;
            is_dir($cale) && !is_link($cale) ? self::stergeDosar($cale) : @unlink($cale);
        }
        @rmdir($dosar);
    }

    private function salveazaRaportul(): void
    {
        if (!$this->atinsa) {
            return;
        }
        try {
            $cale = Rulare::salveaza('incarcare-' . ($this->sursaNume !== '' ? $this->sursaNume : 'copie') . '-' . date('Y-m-d-His') . '.txt',
                implode("\n", $this->raport) . "\n");
            fwrite(STDOUT, 'Textul de mai sus e și în ' . $cale . "\n");
        } catch (Throwable) {
        }
    }

    // ─────────────────────────────────────────────────── pașii

    private function executa(array $argv): int
    {
        Rulare::faraSesiune();
        $this->arg = Rulare::argumente($argv, ['din', 'demo', 'pachet', 'licenta', 'dosar', 'reincarca', 'reia', 'da', 'fara-cod-vechi', 'fara-bt-test', 'buget']);
        $demo = isset($this->arg['demo']);
        if ($demo === isset($this->arg['din'])) {
            throw new Refuz('alege ce încarci: --din=bioscem sau --din=biovitality (copia anonimizată a site-ului live) sau --demo [--pachet=<site>] [--licenta=<pachet>] '
                . '(copia demonstrativă, fără date personale)');
        }
        foreach (['demo', 'reincarca', 'reia', 'da', 'fara-cod-vechi', 'fara-bt-test'] as $comutator) {
            if (isset($this->arg[$comutator]) && $this->arg[$comutator] !== true) {
                throw new Refuz('--' . $comutator . ' nu primește o valoare');
            }
        }
        if (isset($this->arg['reincarca'], $this->arg['reia'])) {
            throw new Refuz('--reincarca (de la capăt) și --reia (continuă) nu merg împreună');
        }
        if ($demo && isset($this->arg['reia'])) {
            throw new Refuz('copia demonstrativă nu se continuă: se face din nou (--demo --reincarca)');
        }
        if ($demo && (isset($this->arg['dosar']) || isset($this->arg['fara-cod-vechi']) || isset($this->arg['fara-bt-test']))) {
            throw new Refuz('--dosar, --fara-cod-vechi și --fara-bt-test sunt doar pentru --din');
        }
        if (!$demo && isset($this->arg['pachet'])) {
            throw new Refuz('--pachet e doar pentru --demo (pachetul copiei unui site îl dau regulile lui)');
        }
        if (isset($this->arg['buget'])) {
            if (!is_string($this->arg['buget']) || preg_match('/^\d{1,6}$/', $this->arg['buget']) !== 1) {
                throw new Refuz('--buget=<secunde> (un număr)');
            }
            $this->buget = (float) $this->arg['buget'];
        }

        // ── Locul: doar din dosarul site-ului de verificare ──
        [$this->acasa, $this->gazda] = Locul::verificare($this->radacina);
        $env = Locul::citesteEnv(\App\Support\Verificare::caleEnv());
        $this->env = (array) $env;
        if (trim($this->env['DB_DATABASE'] ?? '') === '') {
            throw new Refuz('.env nu are DB_DATABASE: rulează întâi tools/verificare/configureaza.php');
        }
        if (filter_var(trim($this->env['VERIFICARE_EMAIL'] ?? ''), FILTER_VALIDATE_EMAIL) === false) {
            throw new Refuz('.env nu are un VERIFICARE_EMAIL bun: configureaza.php --rescrie');
        }
        if (!str_starts_with(trim($this->env['LICENTA_SITE'] ?? ''), 'verificare')) {
            throw new Refuz('LICENTA_SITE din .env nu începe cu „verificare”: configureaza.php --rescrie');
        }
        $cheiePublica = null;
        if (\App\Support\Verificare::cheiePrivataLicenta($cheiePublica) === null || trim($this->env['LICENTA_CHEIE_PUBLICA'] ?? '') !== $cheiePublica) {
            throw new Refuz('lipsește cheia licenței de verificare sau nu e perechea celei din .env (un .env de dinainte de WP-08): '
                . PhpCli::comanda('tools/verificare/configureaza.php --rescrie'));
        }
        $this->spune('Încărcarea copiei pe site-ul de verificare https://' . $this->gazda . ' — ' . date('Y-m-d H:i'));

        if ($demo) {
            $this->sursaNume = 'demo';
            $this->pachet = is_string($this->arg['pachet'] ?? null) ? (string) $this->arg['pachet'] : (trim($this->env['SITE_SLUG'] ?? '') !== '' ? trim($this->env['SITE_SLUG']) : 'bioscem');
            if (preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $this->pachet) !== 1 || !is_dir($this->radacina . '/site-uri/' . $this->pachet)) {
                throw new Refuz('--pachet: nu există pachetul site-uri/' . (preg_match('/^[a-z0-9-]{1,40}$/', $this->pachet) === 1 ? $this->pachet : '?')
                    . ' (bioscem, biovitality, vertij, grafoanaytis)');
            }
            if (!is_file($this->radacina . '/tools/dev/date-demo.php')) {
                throw new Refuz('lipsește tools/dev/date-demo.php din codul de pe server (datele inventate ale copiei demonstrative)');
            }
            $this->info('copie demonstrativă: doar migrări și date inventate (tools/dev/date-demo.php); nicio bază sursă nu se deschide');
        } else {
            $this->sursaNume = (string) $this->arg['din'];
            $this->reguli = Reguli::incarca($this->radacina, $this->sursaNume);
            $this->pachet = (string) $this->reguli->date['pachet'];
            $this->site = SiteLive::gaseste($this->sursaNume, is_string($this->arg['dosar'] ?? null) ? (string) $this->arg['dosar'] : null, $this->acasa, $this->radacina);
            $cod = $this->site->cod();
            $this->commitSursa = $cod['commit'];
            $this->spune('  INFO  sursa: ' . $this->site->titlu . ', dosarul ~/' . $this->site->dosarRelativ . '; codul '
                . ($cod['git'] ? ($cod['ramura'] ?? '(fără ramură)') . ' ' . substr((string) $cod['commit'], 0, 7) : 'fără .git'));
            $this->conexiuneSursa = $this->site->preiaConexiunea();
            $this->info('regulile de anonimizare: tools/verificare/reguli/' . $this->sursaNume . '.php, versiunea ' . $this->reguli->versiune());
        }
        $this->licenta = is_string($this->arg['licenta'] ?? null) ? (string) $this->arg['licenta']
            : ($this->reguli !== null ? (string) $this->reguli->date['licenta'] : $this->licentaPachet($this->pachet));
        $catalog = $this->catalog();
        $presetari = array_merge(['toate'], array_keys((array) ($catalog['licente_dev'] ?? [])), array_keys((array) ($catalog['pachete'] ?? [])));
        if (!in_array($this->licenta, $presetari, true)) {
            throw new Refuz('--licenta: nu există pachetul de pluginuri „' . (preg_match('/^[a-z0-9_-]{1,40}$/', $this->licenta) === 1 ? $this->licenta : '?')
                . '” (' . implode(', ', $presetari) . ')');
        }
        // ── Valorile private ale înghețării pachetului, înaintea oricărei schimbări: fără ele migrările de pe copie s-ar opri
        // cu codul 15 abia după copierea datelor (docs/MIGRARI.md, „Înghețarea setărilor”; OD-49) ──
        $fisierInghetare = $this->radacina . '/site-uri/' . $this->pachet . '/setari-inghetate.json';
        if (is_file($fisierInghetare)) {
            // Doar clasele înghețării (bootstrap.php se încarcă abia pe copia configurată).
            $src = $this->radacina . '/src/';
            spl_autoload_register(static function (string $clasa) use ($src): void {
                if (str_starts_with($clasa, 'App\\Support\\Migrari\\') && is_file($f = $src . str_replace('\\', '/', substr($clasa, 4)) . '.php')) {
                    require_once $f;
                }
            });
            try {
                \App\Support\Migrari\Inghetare::dinFisier($fisierInghetare)->valoriPrivate('site/' . $this->pachet . '/setari-inghetate');
            } catch (\App\Support\Migrari\ParametruLipsa $e) {
                throw new Refuz('înghețarea pachetului ' . $this->pachet . ' are valori private, dar site-uri/' . $this->pachet
                    . '/privat/setari-inghetate.json lipsește sau e al altei versiuni a înghețării: migrările s-ar opri cu codul 15. '
                    . 'Pune-l lângă pachet (ți-l trimitem noi; docs/MIGRARI.md), apoi rulează din nou. Nimic nu s-a schimbat.');
            }
        }

        // ── Baza copiei, fără s-o atingi: întâi că nu e a altui site (fără să se conecteze la ea), apoi deschisă ──
        $dbNume = trim($this->env['DB_DATABASE']);
        $dbGazda = trim($this->env['DB_HOST'] ?? 'localhost');
        if (!$demo && trim($this->conexiuneSursa['DB_DATABASE'] ?? '') === $dbNume
            && Locul::gazdaDin('mysql://' . (trim($this->conexiuneSursa['DB_HOST'] ?? '') ?: 'localhost')) === Locul::gazdaDin('mysql://' . ($dbGazda ?: 'localhost'))) {
            throw new Refuz('baza copiei e chiar baza site-ului sursă; nu o ating');
        }
        $altSite = Cont::bazaAltuiSite($this->acasa, $dbNume, $dbGazda);
        if ($altSite !== null) {
            throw new Refuz('baza din .env e scrisă și în .env-ul altui site din cont (' . $altSite . '); nu o ating');
        }
        $this->copie = Copiere::deschide($this->env);
        $tabele = $this->copie->tabele();
        $marcaj = $this->copie->marcaj();
        $mod = 'noua';
        if ($tabele !== [] && $marcaj === null) {
            throw new Refuz('baza copiei are ' . count($tabele) . ' tabele și nu e o copie de verificare (lipsește _verificare): pare baza unui site. Nu o ating');
        }
        if ($marcaj !== null) {
            $sursaVeche = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($marcaj['sursa'] ?? ''))) ?: '?';
            $anonimizat = (int) ($marcaj['anonimizat'] ?? 0) === 1;
            if (isset($this->arg['reia'])) {
                if ($anonimizat) {
                    throw new Refuz('copia (sursa ' . $sursaVeche . ') e deja terminată; nu e nimic de continuat (--reincarca o face din nou)');
                }
                if ($sursaVeche !== $this->sursaNume) {
                    throw new Refuz('încărcarea oprită e a altei surse (' . $sursaVeche . '): rulează întâi tools/verificare/sterge.php');
                }
                if ((string) ($marcaj['versiune_reguli'] ?? '') !== $this->reguli?->versiune()) {
                    throw new Refuz('regulile de anonimizare s-au schimbat de când a început încărcarea oprită: --reincarca (de la capăt)');
                }
                if (!is_file($this->fisierSare())) {
                    throw new Refuz('sarea încărcării oprite lipsește (storage/verificare/tmp): --reincarca (de la capăt)');
                }
                $mod = 'reia';
                $this->pas = in_array((string) ($marcaj['pas'] ?? ''), self::PASI, true) ? (string) $marcaj['pas'] : 'inceput';
            } elseif (isset($this->arg['reincarca'])) {
                if ($sursaVeche !== $this->sursaNume) {
                    throw new Refuz('pe copie e altă sursă (' . $sursaVeche . '): o singură sursă odată, rulează întâi tools/verificare/sterge.php');
                }
                $mod = 'reincarca';
            } else {
                throw new Refuz('pe copie e deja o încărcare (sursa ' . $sursaVeche . ', ' . ($anonimizat ? 'terminată' : 'oprită la pasul '
                    . preg_replace('/[^a-z_]/', '', (string) ($marcaj['pas'] ?? '?'))) . '): --reincarca o face din nou, --reia continuă una oprită, '
                    . 'sterge.php o șterge');
            }
        } elseif (isset($this->arg['reia'])) {
            throw new Refuz('pe copie nu e nicio încărcare oprită de continuat');
        } elseif (isset($this->arg['reincarca'])) {
            $mod = 'noua';
        }
        $this->info('baza copiei: ' . ($mod === 'noua' ? 'goală' : ($mod === 'reia' ? 'încărcare oprită la pasul ' . $this->pas . ' (o continui)'
            : 'are o copie ' . $this->sursaNume . ' (o fac din nou)')));

        // ── Întrebările, înaintea oricărei schimbări ──
        $this->intrebari($mod, $marcaj);

        // ── Sesiunea sursei, tot înaintea oricărei schimbări (nu citește niciun rând): o legătură care nu merge sau o sesiune
        // care nu trece în modul doar de citire e un refuz cu nimic atins (copia, .env-ul și sarea rămân cum erau) ──
        if (!$demo) {
            $this->sursa = SursaCopie::deschide($this->conexiuneSursa);
            $this->conexiuneSursa = [];
        }

        // ── De aici copia se schimbă ──
        $this->atinsa = true;
        $this->curataFisiere($mod !== 'reia');
        if ($mod === 'reincarca') {
            $this->copie->goleste();
            $this->info('copia de dinainte: ștearsă');
        }
        if ($mod !== 'reia') {
            $sare = random_bytes(32);
            $this->scrieSare($sare);
            $this->copie->scrieMarcaj(['sursa' => $this->sursaNume, 'versiune_reguli' => $this->reguli?->versiune() ?? 'demo',
                'cod_sursa_commit' => $this->commitSursa]);
            $this->rand(true, '_verificare scris primul (anonimizat = 0): o încărcare oprită rămâne o copie de verificare (sterge.php, --reia)');
        } else {
            $sare = (string) @file_get_contents($this->fisierSare());
            $sare = strlen($sare) === 64 && ctype_xdigit($sare) ? (string) hex2bin($sare) : '';
        }
        $this->t = new Transformari($sare);

        // .env: pachetul site-ului (SITE_SLUG, SITE_DATE_DOSAR) și, decizia D3, datele de test BT ale sursei.
        $this->pregatesteEnv();
        // Codul aplicației: în linia de comandă bootstrap.php nu pornește nicio sesiune; încarcă .env-ul de acum.
        require_once $this->radacina . '/bootstrap.php';

        if ($demo) {
            return $this->demo();
        }
        return $this->dinSursa($mod === 'reia' ? $marcaj : null);
    }

    /** Catalogul pluginurilor din codul deployat (config/plugins.php e doar un tablou). */
    private function catalog(): array
    {
        $catalog = require $this->radacina . '/config/plugins.php';
        return is_array($catalog) ? $catalog : [];
    }

    /** Licența implicită a unui pachet: cea de dezvoltare a site-ului (licente_dev), altfel „toate”. */
    private function licentaPachet(string $pachet): string
    {
        return isset($this->catalog()['licente_dev'][$pachet]) ? $pachet : 'toate';
    }

    /** @param array<string, mixed>|null $marcaj */
    private function intrebari(string $mod, ?array $marcaj): void
    {
        if (!isset($this->arg['da'])) {
            $intrebare = $this->sursaNume === 'demo'
                ? 'Fac copia demonstrativă (pachetul ' . $this->pachet . ', licența ' . $this->licenta . ')? Scrie „da”: '
                : 'Încarc copia anonimizată din ~/' . $this->site?->dosarRelativ . '? Scrie „da”: ';
            if (verificare_intreaba($intrebare, false) !== 'da') {
                throw new Refuz('n-ai scris „da”. Nu am atins nimic');
            }
        }
        $adminFacut = $mod === 'reia' && (int) ($marcaj['admin_proprietar_id'] ?? 0) > 0;
        if (!$adminFacut) {
            $implicit = trim($this->env['VERIFICARE_EMAIL']);
            $email = trim(verificare_intreaba('Emailul contului tău de admin pe copie (Enter = ' . \App\Support\Verificare::emailAscuns($implicit)
                . ', VERIFICARE_EMAIL din .env): ', false));
            $this->emailProprietar = strtolower($email !== '' ? $email : $implicit);
            if (filter_var($this->emailProprietar, FILTER_VALIDATE_EMAIL) === false || strlen($this->emailProprietar) > 190) {
                throw new Refuz('adresa contului de admin nu e validă. Nu am atins nimic');
            }
            $parola = verificare_intreaba('Parola contului tău de admin pe copie (cel puțin 10 caractere; nu se vede): ', true);
            if (strlen($parola) < 10) {
                throw new Refuz('parola contului de admin are sub 10 caractere. Nu am atins nimic');
            }
            // Parola contului poate fi aceeași cu a porții (proprietarul, 30 oct 2026): copia e
            // anonimizată, se închide singură după 7 zile, iar o parolă în plus de ținut minte nu
            // aduce aici destul cât să merite.
            if (!hash_equals($parola, verificare_intreaba('Parola contului de admin, încă o dată: ', true))) {
                throw new Refuz('cele două parole ale contului de admin nu sunt la fel. Nu am atins nimic');
            }
            $this->parolaProprietar = $parola;
        }
        if ($this->sursaNume === 'demo') {
            $parola = verificare_intreaba('Parola conturilor personalului pe copia demonstrativă (cei 3 admini cu rol parțial și clienții '
                . 'inventați; cel puțin 10 caractere; nu se vede): ', true);
            if (strlen($parola) < 10) {
                throw new Refuz('parola personalului are sub 10 caractere. Nu am atins nimic');
            }
            // Poate fi aceeași cu parola contului tău (proprietarul, 31 oct 2026): copia are doar date inventate și o
            // folosește doar el.
            if (!hash_equals($parola, verificare_intreaba('Parola personalului, încă o dată: ', true))) {
                throw new Refuz('cele două parole ale personalului nu sunt la fel. Nu am atins nimic');
            }
            $this->parolaPersonal = $parola;
        }
    }

    private function scrieSare(string $sare): void
    {
        $dosar = \App\Support\Verificare::dosar() . '/tmp';
        if (!is_dir($dosar) && !@mkdir($dosar, 0700, true) && !is_dir($dosar)) {
            throw new EsecIncarcare('nu pot face dosarul storage/verificare/tmp');
        }
        $umask = umask(0077);
        try {
            if (@file_put_contents($this->fisierSare(), bin2hex($sare)) !== 64) {
                throw new EsecIncarcare('nu pot scrie sarea încărcării în storage/verificare/tmp');
            }
            @chmod($this->fisierSare(), 0600);
        } finally {
            umask($umask);
        }
    }

    /**
     * `.env`-ul copiei: SITE_SLUG și SITE_DATE_DOSAR spre pachetul încărcat (migrările rulează pașii lui: înghețarea setărilor),
     * VERIFICARE_SURSA (site-ul copiat sau `demo`), la --din VERIFICARE_UPLOADS_DIN spre site-ul copiat (pozele lipsă, garda
     * 13) și decizia D3: BT_IPAY_TEST_USER/PASS/CALLBACK_KEY copiate din `.env`-ul sursei, doar dacă aici sunt goale (cheile
     * de test ale băncii; BT_IPAY_LIVE_* nu se citesc niciodată). Scris atomic (temporar 0600, apoi rename), fiecare valoare
     * verificată că se citește înapoi la fel; celelalte rânduri rămân cum erau.
     */
    private function pregatesteEnv(): void
    {
        $schimbari = [];
        $dosarPachet = $this->radacina . '/site-uri/' . $this->pachet;
        if (trim($this->env['SITE_SLUG'] ?? '') !== $this->pachet) {
            $schimbari['SITE_SLUG'] = $this->pachet;
        }
        if (rtrim(trim($this->env['SITE_DATE_DOSAR'] ?? ''), '/') !== $dosarPachet) {
            $schimbari['SITE_DATE_DOSAR'] = $dosarPachet;
        }
        if (trim($this->env['VERIFICARE_SURSA'] ?? '') !== $this->sursaNume) {
            $schimbari['VERIFICARE_SURSA'] = $this->sursaNume;
        }
        if ($this->site !== null && preg_match('/^[a-z0-9.-]{1,253}$/', $this->site->gazda) === 1
            && rtrim(trim($this->env['VERIFICARE_UPLOADS_DIN'] ?? ''), '/') !== 'https://' . $this->site->gazda) {
            $schimbari['VERIFICARE_UPLOADS_DIN'] = 'https://' . $this->site->gazda;
        }
        $bt = 'nu';
        if ($this->site !== null && !isset($this->arg['fara-bt-test'])) {
            $sursaEnv = Locul::citesteEnv($this->site->dosar . '/.env') ?? [];
            $cheiBt = ['BT_IPAY_TEST_USER', 'BT_IPAY_TEST_PASS', 'BT_IPAY_TEST_CALLBACK_KEY'];
            $deCopiat = [];
            foreach ($cheiBt as $cheie) {
                $deCopiat[$cheie] = trim((string) ($sursaEnv[$cheie] ?? ''));
            }
            unset($sursaEnv);
            $goaleAici = array_filter($cheiBt, fn (string $c): bool => trim($this->env[$c] ?? '') === '');
            if (count($goaleAici) === 3 && count(array_filter($deCopiat, static fn (string $v): bool => $v !== '')) === 3) {
                $schimbari += $deCopiat;
                $bt = 'copiate';
            } elseif (count($goaleAici) < 3) {
                $bt = 'erau deja';
            } else {
                $bt = 'lipsesc pe sursă';
            }
            unset($deCopiat);
        }
        if ($schimbari !== []) {
            $cale = \App\Support\Verificare::caleEnv();
            $text = (string) @file_get_contents($cale);
            foreach ($schimbari as $cheie => $valoare) {
                $rand = $cheie . '=' . $valoare;
                $text = preg_match('/^' . preg_quote($cheie, '/') . '=.*$/m', $text) === 1
                    ? (string) preg_replace_callback('/^' . preg_quote($cheie, '/') . '=.*$/m', static fn (): string => $rand, $text, 1)
                    : rtrim($text, "\n") . "\n" . $rand . "\n";
            }
            $citite = \App\Support\Verificare::citesteEnv($text);
            foreach ($schimbari as $cheie => $valoare) {
                if (($citite[$cheie] ?? null) !== $valoare) {
                    throw new EsecIncarcare('valoarea pentru ' . $cheie . ' nu s-ar citi la fel din .env; .env neschimbat');
                }
            }
            $temporar = dirname($cale) . '/.env.' . bin2hex(random_bytes(6)) . '.tmp';
            $umask = umask(0077);
            $scris = @file_put_contents($temporar, $text, LOCK_EX);
            umask($umask);
            if ($scris !== strlen($text) || !@chmod($temporar, 0600) || !@rename($temporar, $cale)) {
                @unlink($temporar);
                throw new EsecIncarcare('nu pot scrie .env');
            }
            $this->env = (array) Locul::citesteEnv($cale);
        }
        $this->rand(true, '.env: pachetul site-ului ' . $this->pachet . ' și sursa copiei (SITE_SLUG, SITE_DATE_DOSAR, VERIFICARE_SURSA'
            . ($this->site !== null ? ', VERIFICARE_UPLOADS_DIN' : '') . (array_diff_key($schimbari, array_flip(['BT_IPAY_TEST_USER', 'BT_IPAY_TEST_PASS',
                'BT_IPAY_TEST_CALLBACK_KEY'])) !== [] ? ': scrise acum' : ': erau deja') . ')' . ($this->site !== null ? '; datele de test ale băncii (decizia D3): ' . $bt : ''));
    }

    // ─────────────────────────────────────────────────── --din

    /** @param array<string, mixed>|null $marcaj */
    private function dinSursa(?array $marcaj): int
    {
        $reguli = $this->reguli;
        assert($reguli !== null);
        $gata = $marcaj !== null && $this->pas !== 'inceput' ? (array) json_decode((string) ($marcaj['grupuri_gata'] ?? '[]'), true) : [];
        $randuri = $marcaj !== null && $this->pas !== 'inceput' ? (array) json_decode((string) ($marcaj['randuri'] ?? '{}'), true) : [];
        $verificari = $marcaj !== null && $this->pas !== 'inceput' ? (array) json_decode((string) ($marcaj['verificari'] ?? '{}'), true) : [];
        $adminId = (int) ($marcaj['admin_proprietar_id'] ?? 0);

        // ── Sursa (sesiunea doar de citire, deschisă înainte de orice schimbare), obiectele interzise, scanerul ──
        assert($this->sursa !== null);
        $this->rand(true, 'baza sursei: ' . ($this->sursa->familie === 'mariadb' ? 'MariaDB ' : 'MySQL ') . $this->sursa->versiune
            . ', sesiune doar de citire (READ ONLY, verificată), instantanee consecvente, fără buffer');
        $interzise = array_filter($this->sursa->obiecteInterzise());
        if ($interzise !== []) {
            throw new EsecIncarcare('baza sursei are ' . implode(', ', array_map(static fn (string $k, int $n): string => $n . ' ' . $k, array_keys($interzise), $interzise))
                . ': o copie nu le ia, deci nu încarc nimic');
        }
        $structura = $this->sursa->structura();
        if (isset($structura[Copiere::TABEL_MARCAJ])) {
            throw new EsecIncarcare('baza sursei are tabelul _verificare: e o copie de verificare, nu site-ul live');
        }
        $scan = $reguli->scaneaza($structura);
        if ($scan['probleme'] !== []) {
            foreach (array_slice($scan['probleme'], 0, 50) as $problema) {
                $this->spune('  NU    ' . $problema);
            }
            throw new EsecIncarcare('scanerul a oprit încărcarea înaintea oricărui rând: ' . count($scan['probleme']) . ' coloane fără regulă în '
                . 'tools/verificare/reguli/' . $this->sursaNume . '.php (doar numele lor, mai sus). Trimite-ne textul: întâi se scriu regulile');
        }
        $this->rand(true, 'scanerul coloanelor: ' . count($scan['copiate']) . ' tabele copiate, fiecare coloană cu regula ei; ' . count($scan['doar_structura'])
            . ' doar structura (' . implode(', ', $scan['doar_structura']) . ')');
        if ($scan['necunoscute'] !== []) {
            $this->spune('  INFO  tabele fără reguli, copiate doar ca structură, fără niciun rând: ' . implode(', ', $scan['necunoscute']));
        }
        if ($scan['lipsa'] !== []) {
            $this->info(count($scan['lipsa']) . ' coloane din reguli nu sunt în baza sursei (ale codului comun, venite cu migrările)');
        }
        // Setările (fail-closed, ca o coloană): fiecare cheie a sursei e știută de cod sau neutralizată (și pe fiecare rând copiat).
        foreach (array_intersect($scan['copiate'], array_keys(array_filter((array) $reguli->date['tabele'],
            static fn (array $r): bool => ($r['mod'] ?? '') === 'setari'))) as $tabelSetari) {
            $chei = array_map(static fn (array $r): string => (string) $r['k'], $this->sursa->instantaneu(
                fn (): array => $this->sursa->randuri('SELECT `key` AS k FROM ' . SursaCopie::cita($tabelSetari))));
            $faraRegula = $reguli->scaneazaSetari($chei);
            if ($faraRegula !== []) {
                foreach (array_slice($faraRegula, 0, 50) as $problema) {
                    $this->spune('  NU    ' . $problema);
                }
                throw new EsecIncarcare('scanerul a oprit încărcarea înaintea oricărui rând: ' . count($faraRegula) . ' chei de setări pe care nu le știe '
                    . 'codul și nu le neutralizează tools/verificare/reguli/' . $this->sursaNume . '.php (doar numele lor, mai sus). Trimite-ne textul: '
                    . 'întâi se scriu regulile');
            }
            $this->rand(true, 'scanerul setărilor: ' . count($chei) . ' chei, fiecare știută de cod sau neutralizată');
        }

        // ── Structura ──
        if ($this->pas === 'inceput') {
            $deCopiat = array_keys($structura);
            $this->copie->creeazaStructura($this->sursa, $deCopiat);
            $this->pas = 'schema';
            $this->copie->actualizeazaMarcaj(['pas' => 'schema']);
            $this->rand(true, 'structura: ' . count($deCopiat) . ' tabele, cu SHOW CREATE TABLE al sursei');
        } else {
            $peCopie = $this->copie->tabele();
            $lipsa = array_diff(array_keys($structura), array_keys($peCopie));
            if ($lipsa !== []) {
                throw new EsecIncarcare('de la începutul încărcării sursa are tabele noi (' . implode(', ', array_slice($lipsa, 0, 10)) . '): --reincarca');
            }
        }

        // ── Datele, grup cu grup ──
        if (in_array($this->pas, ['schema', 'date'], true)) {
            $this->spune('');
            $this->spune('Datele (un instantaneu al sursei și o tranzacție a copiei pe grup; datele personale schimbate în drum):');
            $this->copie->db->exec('SET FOREIGN_KEY_CHECKS = 0');
            $this->copie->db->exec('SET SESSION sql_mode = ' . $this->copie->db->quote($this->sursa->sqlMode()));
            $peGrup = [];
            foreach ($scan['copiate'] as $tabel) {
                $peGrup[(string) $reguli->grup($tabel)][] = $tabel;
            }
            foreach ($reguli->grupuri() as $grup) {
                if (in_array($grup, $gata, true)) {
                    $this->info('grupul ' . $grup . ': copiat într-o rulare de dinainte');
                    continue;
                }
                $tabeleGrup = $this->ordine($reguli, $grup, $peGrup[$grup] ?? []);
                if ($tabeleGrup === []) {
                    $gata[] = $grup;
                    continue;
                }
                $deLa = microtime(true);
                $numarate = $this->copiazaGrup($reguli, $grup, $tabeleGrup, $structura, $gata, $randuri, $verificari);
                $this->rand(true, 'grupul ' . $grup . ': ' . count($tabeleGrup) . ' tabele, ' . array_sum($numarate) . ' rânduri (= sursa, tabel cu tabel), '
                    . $this->durata($deLa));
                // Bugetul (--buget): după el nu mai începe niciun grup; o rulare copiază cel puțin un grup, iar după ultimul merge până la capăt.
                $ramase = array_diff($reguli->grupuri(), $gata);
                if (isset($this->arg['buget']) && $ramase !== [] && microtime(true) - $this->start >= $this->buget) {
                    throw new Intrerupere('bugetul de timp (--buget=' . (int) $this->buget . ') s-a terminat după grupul ' . $grup . '; mai sunt '
                        . count($ramase) . ' grupuri');
                }
            }
            $this->pas = 'setari';
            $this->copie->actualizeazaMarcaj(['pas' => 'setari']);
        }

        // ── Setările, adminul proprietarului, codul vechi ──
        if ($this->pas === 'setari') {
            $this->neutralizeazaSetari();
            $this->pas = 'admin';
            $this->copie->actualizeazaMarcaj(['pas' => 'admin']);
        }
        if ($this->pas === 'admin') {
            $adminId = $this->adminProprietar();
            $this->pas = 'cod_vechi';
            $this->copie->actualizeazaMarcaj(['pas' => 'cod_vechi', 'admin_proprietar_id' => $adminId]);
        }
        if ($this->pas === 'cod_vechi') {
            if (isset($this->arg['fara-cod-vechi'])) {
                $this->info('codul vechi: nu (--fara-cod-vechi)');
            } else {
                $this->copiazaCodVechi();
            }
            $this->pas = 'migrari';
            $this->copie->actualizeazaMarcaj(['pas' => 'migrari']);
        }

        // ── Migrările (pasul 9: baseline-ul, migrările de date și înghețarea setărilor Bioscem; repetiția adoptării) ──
        if ($this->pas === 'migrari') {
            $this->migrari(false);
            $this->neutralizeazaSetari(true);
            $this->pas = 'verificari';
            $this->copie->actualizeazaMarcaj(['pas' => 'verificari']);
        }

        // ── Aserțiunile ──
        if ($this->pas === 'verificari') {
            $this->verificari($reguli, $adminId > 0 ? $adminId : (int) ($this->copie->marcaj()['admin_proprietar_id'] ?? 0),
                $randuri, (array) json_decode((string) ($this->copie->marcaj()['verificari'] ?? '{}'), true), $scan['copiate']);
            $this->pas = 'licenta';
            $this->copie->actualizeazaMarcaj(['pas' => 'licenta']);
        }
        if ($this->pas === 'licenta') {
            $this->puneLicenta();
            $this->pas = 'gata';
        }
        return $this->termina($randuri);
    }

    /**
     * Tabelele unui grup, în ordinea din reguli (cupoanele înaintea comenzilor), apoi tabelele de lucru FAN după tabelul lor.
     *
     * @param list<string> $existente
     * @return list<string>
     */
    private function ordine(Reguli $reguli, string $grup, array $existente): array
    {
        $ordonate = [];
        foreach ((array) $reguli->date['grupuri'][$grup] as $tabel) {
            foreach ($existente as $existent) {
                if (($reguli->tabel($existent)['_baza'] ?? null) === $tabel && !in_array($existent, $ordonate, true)) {
                    $ordonate[] = $existent;
                }
            }
        }
        usort($ordonate, static function (string $a, string $b) use ($reguli, $grup): int {
            $ordine = array_flip((array) $reguli->date['grupuri'][$grup]);
            return ($ordine[$reguli->tabel($a)['_baza']] <=> $ordine[$reguli->tabel($b)['_baza']]) ?: (strlen($a) <=> strlen($b)) ?: strcmp($a, $b);
        });
        return $ordonate;
    }

    /**
     * Un grup: în copie se golesc întâi tabelele lui (resturile unei rulări oprite), apoi, într-un singur instantaneu al
     * sursei și într-o singură tranzacție a copiei, fiecare tabel: numărătoarea sursei, rândurile transformate pe loturi,
     * numărătoarea copiei (A7: aceleași trei numere). Marcajul (grupul gata, numerele) se scrie în aceeași tranzacție.
     *
     * @param list<string> $tabele
     * @param array<string, array{tip: string, coloane: array<string, array<string, mixed>>}> $structura
     * @param list<string> $gata
     * @param array<string, int> $randuri
     * @param array<string, mixed> $verificari
     * @return array<string, int>
     */
    private function copiazaGrup(Reguli $reguli, string $grup, array $tabele, array $structura, array &$gata, array &$randuri, array &$verificari): array
    {
        $copie = $this->copie;
        $t = $this->t;
        foreach ($tabele as $tabel) {
            $copie->db->exec('DELETE FROM ' . SursaCopie::cita($tabel));
        }
        $numarate = [];
        if (in_array('coupons', array_map(static fn (string $x): string => (string) ($reguli->tabel($x)['_baza'] ?? ''), $tabele), true)) {
            $t->cupoane = [];
        }
        // A8 și A9 se țin aici, din rândurile sursei, nu din reguli: o regulă schimbată greșit (un jeton „pastreaza”) tot pică.
        $jetoaneSursa = [];
        $coduriUnice = [];
        $copie->db->beginTransaction();
        try {
            $this->sursa->instantaneu(function () use ($reguli, $tabele, $structura, $copie, $t, &$numarate, &$jetoaneSursa, &$coduriUnice): void {
                foreach ($tabele as $tabel) {
                    $regula = (array) $reguli->tabel($tabel);
                    $info = $structura[$tabel]['coloane'];
                    $coloane = array_values(array_filter(array_keys($info), static fn (string $c): bool => !$info[$c]['generata']));
                    $inSursa = $this->sursa->numara($tabel);
                    $peLot = Copiere::randuriPeLot(count($coloane));
                    $lot = [];
                    $octeti = 0;
                    $citite = $this->sursa->parcurge($tabel, $coloane, function (array $rand) use ($tabel, $regula, $info, $coloane, $copie, $t, $peLot, &$lot, &$octeti,
                        &$jetoaneSursa, &$coduriUnice): void {
                        $baza = (string) ($regula['_baza'] ?? '');
                        if ($baza === 'order_payment_links' && !Transformari::gol($rand['token'] ?? null)) {
                            $jetoaneSursa[hash('sha256', (string) $rand['token'])] = true;
                        }
                        if ($baza === 'coupons' && (int) ($rand['is_unique'] ?? 0) === 1 && !Transformari::gol($rand['code'] ?? null)) {
                            $coduriUnice[hash('sha256', mb_strtolower(trim((string) $rand['code'])))] = true;
                        }
                        $nou = $regula['mod'] === 'setari' ? $this->setareInDrum($rand) : $t->rand($tabel, $regula, $rand, $info);
                        foreach ($nou as $v) {
                            $octeti += is_string($v) ? strlen($v) : 8;
                        }
                        $lot[] = $nou;
                        if (count($lot) >= $peLot || $octeti >= Copiere::OCTETI_LOT) {
                            $copie->insereaza($tabel, $coloane, $lot, $info);
                            $lot = [];
                            $octeti = 0;
                        }
                    });
                    $copie->insereaza($tabel, $coloane, $lot, $info);
                    $lot = [];
                    $peCopie = $copie->numara($tabel);
                    if ($citite !== $inSursa || $peCopie !== $inSursa) {
                        throw new EsecIncarcare('A7: ' . $tabel . ' are ' . $inSursa . ' rânduri în sursă, ' . $citite . ' citite și ' . $peCopie . ' pe copie');
                    }
                    $numarate[$tabel] = $inSursa;
                }
            });
            foreach ($numarate as $tabel => $n) {
                $randuri[$tabel] = $n;
            }
            $baze = array_map(static fn (string $x): string => (string) ($reguli->tabel($x)['_baza'] ?? ''), $tabele);
            if (in_array('order_payment_links', $baze, true)) {
                $egale = 0;
                foreach ($copie->db->query('SELECT token FROM order_payment_links')->fetchAll(PDO::FETCH_COLUMN) as $token) {
                    if (isset($jetoaneSursa[hash('sha256', (string) $token)])) {
                        $egale++;
                    }
                }
                $verificari['A8'] = ['jetoane' => count($jetoaneSursa), 'egale' => $egale];
            }
            if (in_array('coupons', $baze, true)) {
                $ramase = 0;
                foreach ($tabele as $tabel) {
                    $coloana = match ((string) ($reguli->tabel($tabel)['_baza'] ?? '')) {
                        'coupons' => 'code',
                        'orders' => 'coupon_code',
                        default => null,
                    };
                    if ($coloana === null) {
                        continue;
                    }
                    $stmt = $copie->db->query('SELECT ' . SursaCopie::cita($coloana) . ' FROM ' . SursaCopie::cita($tabel) . ' WHERE ' . SursaCopie::cita($coloana)
                        . " IS NOT NULL AND " . SursaCopie::cita($coloana) . " <> ''");
                    while (($cod = $stmt->fetchColumn()) !== false) {
                        if (isset($coduriUnice[hash('sha256', mb_strtolower(trim((string) $cod)))])) {
                            $ramase++;
                        }
                    }
                }
                $verificari['A9'] = ['coduri_unice' => count($coduriUnice), 'ramase' => $ramase];
            }
            $gata[] = $grup;
            $copie->actualizeazaMarcaj(['pas' => 'date', 'grupuri_gata' => (string) json_encode(array_values($gata)),
                'randuri' => (string) json_encode($randuri), 'verificari' => (string) json_encode($verificari)]);
            $copie->db->commit();
        } catch (Throwable $e) {
            try {
                $copie->db->rollBack();
            } catch (Throwable) {
            }
            if ($e instanceof PDOException && CitireLive::eLegaturaPierduta($e)) {
                throw new Intrerupere('legătura cu baza s-a pierdut în grupul ' . $grup . ' (' . CitireLive::cod($e) . ')');
            }
            if ($e instanceof PDOException) {
                throw new EsecIncarcare('grupul ' . $grup . ' nu s-a putut copia (' . CitireLive::cod($e) . ')');
            }
            throw $e;
        }
        return $numarate;
    }

    /** Un rând din settings, cu valoarea neutralizată în drum. @param array<string, mixed> $rand @return array<string, mixed> */
    private function setareInDrum(array $rand): array
    {
        $setari = (array) $this->reguli?->date['setari'];
        $faraRegula = $this->reguli?->scaneazaSetari([(string) $rand['key']]) ?? ['fără reguli'];
        if ($faraRegula !== []) {
            // Venită după scaner (între citirea cheilor și instantaneul grupului): grupul nu se scrie.
            throw new EsecIncarcare('scanerul setărilor a oprit copierea: ' . $faraRegula[0]);
        }
        $nou = $this->t->setare((string) $rand['key'], $rand['value'], $setari, $this->proprietar(), $this->casuta());
        if ($nou !== null) {
            $rand['value'] = $nou;
        }
        return $rand;
    }

    private function proprietar(): string
    {
        return trim($this->env['VERIFICARE_EMAIL'] ?? '');
    }

    private function casuta(): string
    {
        $casuta = trim($this->env['VERIFICARE_SMTP_UTILIZATOR'] ?? '');
        return filter_var($casuta, FILTER_VALIDATE_EMAIL) !== false ? $casuta : 'no-reply@' . Transformari::DOMENIU;
    }

    /** Lista neutralizării, încă o dată, peste tot tabelul (și cheile lipsă se scriu). Întoarce câte chei a scris. */
    private function neutralizeazaSetari(bool $dupaMigrari = false): int
    {
        $setari = $this->reguli !== null ? (array) $this->reguli->date['setari'] : $this->neutralizareImplicita();
        $db = $this->copie->db;
        $existente = [];
        foreach ($db->query('SELECT `key`, `value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR) as $cheie => $valoare) {
            $existente[(string) $cheie] = $valoare;
        }
        $chei = array_merge((array) $setari['goale'], (array) $setari['zero'], (array) $setari['proprietar'], (array) $setari['casuta'],
            (array) $setari['prefix'], (array) $setari['aleatoare']);
        foreach (array_keys($existente) as $cheie) {
            if (preg_match((string) $setari['goale_tipar'], $cheie) === 1 || preg_match((string) $setari['proprietar_tipar'], $cheie) === 1) {
                $chei[] = $cheie;
            }
        }
        $scrise = 0;
        $stmt = $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (:k, :v) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
        foreach (array_unique($chei) as $cheie) {
            $nou = $this->t->setare($cheie, $existente[$cheie] ?? null, $setari, $this->proprietar(), $this->casuta());
            if ($nou !== null && (!array_key_exists($cheie, $existente) || (string) $existente[$cheie] !== (string) $nou)) {
                $stmt->execute(['k' => $cheie, 'v' => (string) $nou]);
                $scrise++;
            }
        }
        if (!$dupaMigrari) {
            $this->rand(true, 'setările: secretele, cheile și id-urile conturilor externe golite, plățile, ERP-ul, FAN-ul și etichetele terților oprite, '
                . 'destinatarii emailurilor = adresa ta (' . count(array_unique($chei)) . ' chei; ' . $scrise . ' scrise acum, restul schimbate în drum)');
        } elseif ($scrise > 0) {
            $this->info('setările, după migrări: ' . $scrise . ' chei puse din nou');
        }
        return $scrise;
    }

    /**
     * Lista neutralizării pentru copia demonstrativă: a regulilor pachetului, dacă site-ul are reguli (Biovitality, cu cheile
     * fișierelor din cloud), altfel cea a Bioscem. @return array<string, mixed>
     */
    private function neutralizareImplicita(): array
    {
        $fisier = dirname(__DIR__) . '/reguli/' . (preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $this->pachet) === 1 ? $this->pachet : 'bioscem') . '.php';
        $reguli = require (is_file($fisier) ? $fisier : dirname(__DIR__) . '/reguli/bioscem.php');
        return (array) $reguli['setari'];
    }

    /** Contul de administrator general al proprietarului (adresa și parola scrise la început). */
    private function adminProprietar(): int
    {
        $db = $this->copie->db;
        $coloane = array_column($db->query("SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins'")->fetchAll(), 'c');
        if (!in_array('email', $coloane, true) || !in_array('password_hash', $coloane, true)) {
            throw new EsecIncarcare('tabelul admins al copiei nu are email și password_hash');
        }
        $hash = password_hash($this->parolaProprietar, PASSWORD_BCRYPT);
        $rol = \App\Support\Auth::ROLE_GENERAL;
        $existent = $db->prepare('SELECT id FROM admins WHERE email = :e LIMIT 1');
        $existent->execute(['e' => $this->emailProprietar]);
        $id = (int) $existent->fetchColumn();
        $valori = ['email' => $this->emailProprietar, 'password_hash' => $hash];
        if (in_array('role', $coloane, true)) {
            $valori['role'] = $rol;
        }
        if (in_array('roles_json', $coloane, true)) {
            $valori['roles_json'] = (string) json_encode([$rol]);
        }
        if ($id > 0) {
            $set = implode(', ', array_map(static fn (string $c): string => '`' . $c . '` = :' . $c, array_keys($valori)));
            $db->prepare('UPDATE admins SET ' . $set . ' WHERE id = :id')->execute($valori + ['id' => $id]);
        } else {
            $db->prepare('INSERT INTO admins (`' . implode('`, `', array_keys($valori)) . '`) VALUES (:' . implode(', :', array_keys($valori)) . ')')->execute($valori);
            $id = (int) $db->lastInsertId();
        }
        $this->parolaProprietar = '';
        $this->rand($id > 0, 'contul tău de administrator general (' . \App\Support\Verificare::emailAscuns($this->emailProprietar) . '); adminii site-ului au adrese '
            . '@verificare.invalid și parole aleatoare, deci nu se pot loga');
        return $id;
    }

    /**
     * Codul vechi al sursei în `~/domains/<verificare>/cod-vechi/<site>/` (în afara public_html: nu se vede din browser și nu
     * are cron), pentru comparația calculelor (WP-11). Doar cod: fără .env (și .env.*), .git, storage/ (dosarele rămân goale),
     * public/uploads, sitemap-urile (public/*.xml), jurnale (*.log, error_log), arhive și copii de baze; fișierele de alte
     * feluri se sar și se numără. Legăturile nu se urmează. Lângă el, un .env doar cu baza copiei, adresa copiei și BT în test.
     */
    private function copiazaCodVechi(): void
    {
        $sursa = $this->site?->dosar;
        assert(is_string($sursa));
        $parinte = dirname($this->radacina) . '/cod-vechi';
        if (is_link($parinte) || (file_exists($parinte) && !is_dir($parinte))) {
            throw new EsecIncarcare('cod-vechi de lângă site nu e un dosar (o legătură?): rulează sterge.php');
        }
        $tinta = $parinte . '/' . $this->sursaNume;
        self::stergeDosar($tinta);
        $umask = umask(0077);
        $numar = ['fisiere' => 0, 'octeti' => 0, 'sarite' => 0, 'legaturi' => 0, 'mari' => 0];
        $extensiiSarite = [];
        try {
            if (!is_dir($tinta) && !@mkdir($tinta, 0700, true)) {
                throw new EsecIncarcare('nu pot face dosarul cod-vechi/' . $this->sursaNume);
            }
            $parcurge = function (string $relativ) use (&$parcurge, $sursa, $tinta, &$numar, &$extensiiSarite): void {
                $dosar = $sursa . ($relativ !== '' ? '/' . $relativ : '');
                foreach ((array) @scandir($dosar) as $nume) {
                    if (!is_string($nume) || $nume === '.' || $nume === '..') {
                        continue;
                    }
                    $rel = ($relativ !== '' ? $relativ . '/' : '') . $nume;
                    $cale = $sursa . '/' . $rel;
                    if (is_link($cale)) {
                        $numar['legaturi']++;
                        continue;
                    }
                    if (is_dir($cale)) {
                        if ($rel === '.git' || $rel === 'public/uploads') {
                            if ($rel === 'public/uploads') {
                                @mkdir($tinta . '/' . $rel, 0700, true);
                            }
                            continue;
                        }
                        @mkdir($tinta . '/' . $rel, 0700, true);
                        $parcurge($rel);
                        continue;
                    }
                    if (!is_file($cale)) {
                        continue;
                    }
                    $motiv = self::motivSarit($rel, (int) @filesize($cale));
                    if ($motiv !== null) {
                        $numar['sarite']++;
                        if ($motiv !== '') {
                            $extensiiSarite[$motiv] = ($extensiiSarite[$motiv] ?? 0) + 1;
                        }
                        continue;
                    }
                    $marime = (int) @filesize($cale);
                    if ($marime > 20 * 1048576) {
                        $numar['mari']++;
                        continue;
                    }
                    if (!@copy($cale, $tinta . '/' . $rel)) {
                        throw new EsecIncarcare('nu pot copia un fișier al codului vechi');
                    }
                    @chmod($tinta . '/' . $rel, 0600);
                    $numar['fisiere']++;
                    $numar['octeti'] += $marime;
                }
            };
            $parcurge('');
            $env = "# Codul vechi al " . $this->sursaNume . " pe site-ul de verificare (tools/verificare/incarca.php): doar baza copiei.\n"
                . "APP_NAME=VERIFICARE\nAPP_ENV=verificare\nAPP_DEBUG=false\nAPP_URL=https://" . $this->gazda . "\nAPP_TIMEZONE=Europe/Bucharest\n"
                . 'DB_HOST=' . trim($this->env['DB_HOST'] ?? 'localhost') . "\nDB_PORT=" . trim($this->env['DB_PORT'] ?? '3306') . "\n"
                . 'DB_DATABASE=' . trim($this->env['DB_DATABASE']) . "\nDB_USERNAME=" . trim($this->env['DB_USERNAME'] ?? '') . "\n"
                . 'DB_PASSWORD=' . trim($this->env['DB_PASSWORD'] ?? '') . "\n"
                . "ADMIN_DEFAULT_PASSWORD=\nBT_IPAY_MODE=test\nBT_IPAY_TEST_USER=\nBT_IPAY_TEST_PASS=\nBT_IPAY_TEST_CALLBACK_KEY=\n"
                . "BT_IPAY_LIVE_USER=\nBT_IPAY_LIVE_PASS=\nBT_IPAY_LIVE_CALLBACK_KEY=\n";
            if (@file_put_contents($tinta . '/.env', $env) !== strlen($env)) {
                throw new EsecIncarcare('nu pot scrie .env-ul codului vechi');
            }
            @chmod($tinta . '/.env', 0600);
        } finally {
            umask($umask);
        }
        ksort($extensiiSarite);
        $this->rand(true, 'codul vechi în ~/domains/' . $this->gazda . '/cod-vechi/' . $this->sursaNume . '/ (în afara public_html, fără cron): '
            . $numar['fisiere'] . ' fișiere, ' . round($numar['octeti'] / 1048576, 1) . ' MB; sărite ' . $numar['sarite'] . ' (.env, storage, '
            . 'poze, sitemap, jurnale, arhive' . ($extensiiSarite !== [] ? '; alte feluri: ' . implode(', ', array_map(static fn (string $e, int $n): string => $e . ' ' . $n,
                array_keys($extensiiSarite), $extensiiSarite)) : '') . ')' . ($numar['legaturi'] > 0 ? ', ' . $numar['legaturi'] . ' legături neurmate' : '')
            . ($numar['mari'] > 0 ? ', ' . $numar['mari'] . ' fișiere peste 20 MB' : '') . '; .env-ul lui: doar baza copiei, BT în test');
    }

    /** Extensiile de cod copiate; orice alt fișier se sare (fail-closed: o copie de bază uitată în dosar nu pleacă). */
    private const EXTENSII_COD = ['php', 'phtml', 'js', 'mjs', 'css', 'html', 'htm', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico',
        'woff', 'woff2', 'ttf', 'otf', 'eot', 'map', 'json', 'md', 'txt', 'xml', 'lock', 'yml', 'yaml', 'dist', 'example'];

    /** Un .json/.xml/.txt al codului: în dosarele codului (și vendor/) sau unul dintre fișierele știute ale rădăcinii și ale public/. */
    private static function dateDeCod(string $rel): bool
    {
        if (preg_match('#^(?:src|views|config|database|scripts|resources|vendor)/#', $rel) === 1) {
            return true;
        }
        return in_array($rel, ['composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'public/manifest.json',
            'public/browserconfig.xml', 'public/ads.txt', 'public/humans.txt', 'public/.well-known/security.txt'], true);
    }

    /** null = se copiază; '' = sărit fără să se numere felul (.env, storage, jurnale); altfel felul fișierului sărit. */
    public static function motivSarit(string $rel, int $marime = 0): ?string
    {
        $nume = basename($rel);
        if (str_starts_with($nume, '.env') && $nume !== '.env.example') {
            return '';
        }
        if (str_starts_with($rel, 'storage/')) {
            return in_array($nume, ['.gitkeep', '.gitignore', '.htaccess'], true) ? null : '';
        }
        if ($nume === 'error_log' || str_ends_with($nume, '.log') || preg_match('#^public/[^/]+\.xml$#', $rel) === 1) {
            return '';
        }
        if (in_array($nume, ['.htaccess', '.gitignore', '.gitkeep', '.user.ini', 'robots.txt'], true)) {
            return null;
        }
        $extensie = strtolower(pathinfo($nume, PATHINFO_EXTENSION));
        if ($extensie === 'sql') {
            // Schema și seed-ul codului, nu o copie a bazei.
            return str_starts_with($rel, 'database/') && $marime < 2 * 1048576 ? null : 'sql';
        }
        if (in_array($extensie, ['json', 'xml', 'txt'], true) && !self::dateDeCod($rel)) {
            // Un export lăsat în dosar (export.xml-ul WordPress, utilizatori.json, comenzi.txt) nu pleacă: doar cele din dosarele
            // codului și cele știute ale rădăcinii și ale public/ (revizuirea WP-11b).
            return $extensie;
        }
        if (in_array($extensie, self::EXTENSII_COD, true)) {
            return null;
        }
        return $extensie !== '' && preg_match('/^[a-z0-9]{1,8}$/', $extensie) === 1 ? $extensie : 'fără extensie';
    }

    /**
     * Migrările pe copie, în proces, ca `scripts/migreaza.php --plan` + `--aplica` + `--verifica` cu `--site=<pachet>`
     * (--permite-provizoriu are efect doar pe copia de verificare, pentru înghețările provizorii BV, VT, GR). Textul runner-ului
     * (nume de obiecte, numere, durate) intră în raport: din el se iau duratele pentru migrari.json.
     */
    private function migrari(bool $demo): void
    {
        $this->spune('');
        $this->spune($demo ? 'Migrările pe baza goală a copiei (ca o instalare nouă, cu pașii pachetului ' . $this->pachet . '):'
            : 'Migrările pe copie (repetiția adoptării: scripts/migreaza.php --aplica --site=' . $this->pachet . ', în proces):');
        $config = require $this->radacina . '/config/app.php';
        \App\Support\DateSite::reseteaza();
        try {
            $site = \App\Support\Migrari\Consola::site($this->pachet);
            [$pdo, $server] = \App\Support\Migrari\Consola::conecteaza((array) ($config['db'] ?? []));
        } catch (\App\Support\Migrari\Eroare $e) {
            throw new EsecIncarcare('migrările nu pornesc: ' . $e->getMessage());
        }
        $optiuni = \App\Support\Migrari\Consola::optiuniDinMediu($this->radacina, ['site' => $site, 'permite_provizoriu' => true]);
        $registru = new \App\Support\Migrari\Registru(\App\Support\Migrari\Consola::dosarMigrari($this->radacina),
            array_merge(['nucleu'], \App\Support\Plugins::chei()));
        $runner = new \App\Support\Migrari\Runner($pdo, $server, $registru, $optiuni);
        $deLa = microtime(true);
        foreach (['plan', 'aplica', 'verifica'] as $comanda) {
            try {
                $raport = match ($comanda) {
                    'plan' => $runner->plan(),
                    'aplica' => $runner->aplica(),
                    'verifica' => $runner->verifica(),
                };
            } catch (\App\Support\Migrari\Eroare $e) {
                $raport = ['comanda' => $comanda, 'cod' => $e->getCode(), 'mesaj' => $e->getMessage(), 'migrari' => [], 'avertismente' => []];
            } catch (Throwable $e) {
                $raport = ['comanda' => $comanda, 'cod' => 1, 'mesaj' => 'eroare neașteptată: ' . \App\Support\Migrari\Schema::faraValori($e->getMessage()),
                    'migrari' => [], 'avertismente' => []];
            }
            foreach (explode("\n", rtrim(\App\Support\Migrari\Consola::text($raport))) as $linie) {
                $this->spune('    ' . $linie);
            }
            $cod = (int) $raport['cod'];
            if ($comanda === 'plan' && in_array($cod, [0, 10], true)) {
                continue;
            }
            if ($cod === \App\Support\Migrari\Eroare::BUGET || $cod === 3) {
                throw new Intrerupere('migrările s-au oprit (codul ' . $cod . ')');
            }
            if ($cod !== 0) {
                throw new EsecIncarcare('migrările pe copie s-au oprit cu codul ' . $cod . ' (' . $comanda . ')'
                    . ($cod === 15 ? '; fiecare pachet cu valori private în înghețare (bioscem, biovitality, vertij, grafoanaytis) cere site-uri/<site>/privat/ lângă pachet (docs/MIGRARI.md)' : ''));
            }
        }
        \App\Support\Migrari\Garda::uita();
        $stare = \App\Support\Migrari\Garda::stare($pdo);
        $pdo = null;
        if ($stare !== 'la_zi') {
            throw new EsecIncarcare('după migrări garda spune ' . preg_replace('/[^a-z_]/', '', $stare) . ', nu la_zi');
        }
        $this->rand(true, 'migrările: aplicate și verificate, garda spune la_zi (' . $this->durata($deLa) . ')');
    }

    /**
     * Aserțiunile A1–A9, pe copie (doar numere). Oricare pică: copia se șterge.
     *
     * @param array<string, int> $randuri
     * @param array<string, mixed> $verificari
     * @param list<string> $copiate tabelele sursei copiate cu rânduri (scanerul)
     */
    private function verificari(Reguli $reguli, int $adminId, array $randuri, array $verificari, array $copiate): void
    {
        $this->spune('');
        $this->spune('Verificările copiei (doar numere; oricare pică: copia se șterge):');
        $db = $this->copie->db;
        $peCopie = $this->copie->tabele();
        $coloane = [];
        foreach ($db->query('SELECT TABLE_NAME AS t, COLUMN_NAME AS c, DATA_TYPE AS tip FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()')->fetchAll() as $r) {
            $coloane[(string) $r['t']][(string) $r['c']] = strtolower((string) $r['tip']);
        }
        $esecuri = [];
        $faraProprietar = static fn (string $tabel): string => $tabel === 'admins' && $adminId > 0 ? ' AND id <> ' . $adminId : '';
        $peReguli = static function (array $nume) use ($reguli, $peCopie, $coloane): array {
            $r = [];
            foreach (array_keys($peCopie) as $tabel) {
                $regula = $reguli->tabel($tabel);
                foreach ((array) ($regula['transforma'] ?? []) as $coloana => $t) {
                    if (in_array($t[0], $nume, true) && isset($coloane[$tabel][$coloana])) {
                        $r[] = [$tabel, $coloana, $t[0]];
                    }
                }
            }
            return $r;
        };
        $numar = static fn (string $sql): int => (int) $db->query($sql)->fetchColumn();
        $q = static fn (string $n): string => SursaCopie::cita($n);

        // A1: adresele de email. Fiecare coloană cu adrese din altă parte e numită pe ecran (doar tabel.coloană și câte):
        // textul trimis de proprietar arată unde e adresa, fără ea.
        $rele = 0;
        $unde = [];
        $coloaneEmail = $peReguli(Reguli::EMAIL);
        foreach ($coloaneEmail as [$tabel, $coloana, $fel]) {
            if ($fel === 'emailuri') {
                $n = 0;
                foreach ($db->query('SELECT ' . $q($coloana) . ' FROM ' . $q($tabel) . ' WHERE ' . $q($coloana) . " LIKE '%@%'" . $faraProprietar($tabel))->fetchAll(PDO::FETCH_COLUMN) as $v) {
                    foreach (preg_split('/[\s,;]+/', trim((string) $v)) ?: [] as $adresa) {
                        if ($adresa !== '' && !str_ends_with(strtolower($adresa), '@' . Transformari::DOMENIU)) {
                            $n++;
                        }
                    }
                }
            } else {
                $n = $numar('SELECT COUNT(*) FROM ' . $q($tabel) . ' WHERE ' . $q($coloana) . ' IS NOT NULL AND ' . $q($coloana) . " <> '' AND "
                    . $q($coloana) . " NOT LIKE '%@verificare.invalid'" . $faraProprietar($tabel));
            }
            if ($n > 0) {
                $rele += $n;
                $unde[$tabel . '.' . $coloana] = ($unde[$tabel . '.' . $coloana] ?? 0) + $n;
            }
        }
        $generice = 0;
        $scanate = 0;
        foreach (array_keys($peCopie) as $tabel) {
            $regula = $reguli->tabel($tabel);
            if ($regula === null || in_array($regula['mod'], ['doar_structura', 'setari'], true) || isset($regula['emailuri_publice'])) {
                continue;
            }
            foreach ((array) ($coloane[$tabel] ?? []) as $coloana => $tip) {
                if (!in_array($tip, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json'], true)) {
                    continue;
                }
                $scanate++;
                $stmt = $db->query('SELECT ' . $q($coloana) . ' FROM ' . $q($tabel) . ' WHERE ' . $q($coloana) . " LIKE '%@%'" . $faraProprietar($tabel));
                while (($v = $stmt->fetchColumn()) !== false) {
                    if (preg_match_all(Transformari::TIPAR_EMAIL, (string) $v, $m) > 0) {
                        foreach ($m[0] as $adresa) {
                            if (!str_ends_with(strtolower($adresa), '@' . Transformari::DOMENIU)) {
                                $generice++;
                                $unde[$tabel . '.' . $coloana] = ($unde[$tabel . '.' . $coloana] ?? 0) + 1;
                            }
                        }
                    }
                }
            }
        }
        ksort($unde);
        $esecuri[] = $this->aserțiune('A1', $rele === 0 && $generice === 0, 'emailurile: ' . count($coloaneEmail) . ' coloane cu pseudonime, toate @verificare.invalid ('
            . $rele . ' altfel); căutarea generică în ' . $scanate . ' coloane de text: ' . $generice . ' adrese din afara verificare.invalid (contul tău nu se numără)'
            . ($unde !== [] ? '; adresele sunt în: ' . implode(', ', array_map(static fn (string $c, int $n): string => $c . ' (' . $n . ')',
                array_slice(array_keys($unde), 0, 30), array_slice(array_values($unde), 0, 30))) . (count($unde) > 30 ? ' și încă ' . (count($unde) - 30) . ' coloane' : '') : ''));

        // A2: tabelele doar cu structură, fără niciun rând.
        $cuRanduri = [];
        foreach (array_keys($peCopie) as $tabel) {
            $regula = $reguli->tabel($tabel);
            if ($tabel !== Copiere::TABEL_MARCAJ && ($regula === null || $regula['mod'] === 'doar_structura' || in_array($tabel, self::FARA_RANDURI, true))
                && $numar('SELECT COUNT(*) FROM ' . $q($tabel)) > 0) {
                $cuRanduri[] = $tabel;
            }
        }
        $esecuri[] = $this->aserțiune('A2', $cuRanduri === [], 'tabelele doar cu structură (acordurile GDPR, jurnalele, resetările de parolă, coșurile '
            . 'abandonate, cele fără reguli): 0 rânduri' . ($cuRanduri !== [] ? '; au rânduri: ' . implode(', ', $cuRanduri) : ''));

        // Coloanele găsite după nume în tabelele copiate (nu doar după reguli: o regulă schimbat greșit în „pastreaza” tot pică).
        $dupaNume = static function (string $tipar) use ($reguli, $peCopie, $coloane): array {
            $r = [];
            foreach (array_keys($peCopie) as $tabel) {
                $regula = $reguli->tabel($tabel);
                if ($regula === null || !in_array($regula['mod'], ['anonimizeaza', 'copiaza'], true)) {
                    continue;
                }
                foreach (array_keys((array) ($coloane[$tabel] ?? [])) as $coloana) {
                    if (preg_match($tipar, $coloana) === 1) {
                        $r[] = [$tabel, $coloana, ''];
                    }
                }
            }
            return $r;
        };
        $unice = static function (array ...$liste): array {
            $r = [];
            foreach ($liste as $lista) {
                foreach ($lista as $c) {
                    $r[$c[0] . '.' . $c[1]] = $c;
                }
            }
            return array_values($r);
        };

        // A3: telefoanele.
        $telefoane = 0;
        $coloaneTelefon = $unice($peReguli(['telefon']), $dupaNume('/(phone|telefon)/i'));
        foreach ($coloaneTelefon as [$tabel, $coloana]) {
            $telefoane += $numar('SELECT COUNT(*) FROM ' . $q($tabel) . ' WHERE ' . $q($coloana) . ' IS NOT NULL AND ' . $q($coloana) . " <> '' AND "
                . $q($coloana) . " NOT REGEXP '^07[0-9]{8}$'");
        }
        $esecuri[] = $this->aserțiune('A3', $telefoane === 0, 'telefoanele: ' . count($coloaneTelefon) . ' coloane, toate 07 + 8 cifre sau goale (' . $telefoane . ' altfel)');

        // A4: IP-urile, browserele, id-urile Google, răspunsurile băncii și celelalte coloane golite.
        $pline = 0;
        $coloaneNul = $unice($peReguli(['nul']), $dupaNume('/^(ip|.+_ip|ip_address|user_agent|google_id|last_status_json)$/i'));
        foreach ($coloaneNul as [$tabel, $coloana]) {
            // O dată (birth_date) nu se compară cu '' (MySQL 8 în modul strict refuză).
            $text = !in_array($coloane[$tabel][$coloana] ?? '', ['date', 'datetime', 'timestamp', 'time', 'year'], true);
            $pline += $numar('SELECT COUNT(*) FROM ' . $q($tabel) . ' WHERE ' . $q($coloana) . ' IS NOT NULL' . ($text ? ' AND ' . $q($coloana) . " <> ''" : ''));
        }
        $esecuri[] = $this->aserțiune('A4', $pline === 0, 'IP-urile, browserele, users.google_id, bt_ipay_transactions.last_status_json și celelalte '
            . count($coloaneNul) . ' coloane golite: NULL (' . $pline . ' altfel)');

        // A5: parolele.
        $rele = 0;
        $coloaneParola = $unice($peReguli(['parola']), array_values(array_filter($dupaNume('/^password_hash$/'),
            static fn (array $c): bool => in_array($c[0], ['users', 'admins'], true))));
        foreach ($coloaneParola as [$tabel, $coloana]) {
            $rele += $numar('SELECT COUNT(*) FROM ' . $q($tabel) . ' WHERE (' . $q($coloana) . ' IS NULL OR ' . $q($coloana) . " NOT LIKE '$2y$%' OR CHAR_LENGTH("
                . $q($coloana) . ') <> 60)' . ($tabel === 'admins' && $adminId > 0 ? ' AND id <> ' . $adminId : ''));
        }
        $esecuri[] = $this->aserțiune('A5', $rele === 0 && $coloaneParola !== [], 'parolele clienților și ale adminilor: bcrypt ($2y$, 60 de caractere), aleatoare ('
            . $rele . ' altfel)');

        // A6: setările neutralizate.
        $setari = (array) $reguli->date['setari'];
        $valori = [];
        foreach ($db->query('SELECT `key`, `value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR) as $cheie => $valoare) {
            $valori[(string) $cheie] = (string) $valoare;
        }
        $gresite = [];
        $chei = array_merge((array) $setari['goale'], (array) $setari['zero'], (array) $setari['proprietar'], (array) $setari['casuta'],
            (array) $setari['prefix'], (array) $setari['aleatoare']);
        foreach (array_keys($valori) as $cheie) {
            if (preg_match((string) $setari['goale_tipar'], $cheie) === 1 || preg_match((string) $setari['proprietar_tipar'], $cheie) === 1) {
                $chei[] = $cheie;
            }
        }
        foreach (array_unique($chei) as $cheie) {
            if (!array_key_exists($cheie, $valori) || self::setareNeutralizata($cheie, $valori[$cheie], $setari, $this->proprietar(), $this->casuta()) === false) {
                $gresite[] = $cheie;
            }
        }
        $esecuri[] = $this->aserțiune('A6', $gresite === [], 'setările neutralizate: ' . count(array_unique($chei)) . ' chei cu valoarea copiei'
            . ($gresite !== [] ? '; altfel: ' . implode(', ', $gresite) : ''));

        // A7: numărătorile, în instantaneul fiecărui grup (verificate la copiere: sursa = citite = copia; aici, că niciun tabel n-a lipsit).
        $nenumarate = array_values(array_diff($copiate, array_keys($randuri)));
        $esecuri[] = $this->aserțiune('A7', $nenumarate === [] && count($randuri) === count($copiate), 'rândurile: ' . count($copiate) . ' tabele copiate, fiecare cu tot '
            . 'atâtea rânduri ca sursa în același instantaneu (' . array_sum($randuri) . ' rânduri)' . ($nenumarate !== [] ? '; fără numărătoare: '
            . implode(', ', $nenumarate) : ''));

        // A8: jetoanele linkurilor de plată.
        $a8 = (array) ($verificari['A8'] ?? []);
        $jetoaneRele = isset($peCopie['order_payment_links'])
            ? $numar("SELECT COUNT(*) FROM order_payment_links WHERE token NOT REGEXP '^[0-9a-f]{64}$'")
                + $numar('SELECT COUNT(*) - COUNT(DISTINCT token) FROM order_payment_links') : 0;
        $esecuri[] = $this->aserțiune('A8', (!isset($peCopie['order_payment_links']) || $a8 !== []) && (int) ($a8['egale'] ?? 0) === 0 && $jetoaneRele === 0,
            'linkurile de plată: ' . (int) ($a8['jetoane'] ?? 0) . ' jetoane noi, 0 egale cu unul din sursă (' . (int) ($a8['egale'] ?? 0) . ' altfel), toate 64 hex diferite');

        // A9: codurile unice de cupon (vouchere la purtător, ar merge pe site-ul adevărat).
        $a9 = (array) ($verificari['A9'] ?? []);
        $necunoscute = isset($peCopie['coupons']) ? $numar("SELECT COUNT(*) FROM coupons WHERE is_unique = 1 AND code <> CONCAT('VERIF-', id)") : 0;
        $esecuri[] = $this->aserțiune('A9', (!isset($peCopie['coupons']) || $a9 !== []) && $necunoscute === 0 && (int) ($a9['ramase'] ?? 0) === 0,
            'codurile unice de cupon: ' . (int) ($a9['coduri_unice'] ?? 0) . ' rescrise VERIF-<id>, și în comenzi (' . ($necunoscute + (int) ($a9['ramase'] ?? 0))
            . ' coduri ale sursei rămase)');

        $pica = array_values(array_filter($esecuri, static fn (?string $e): bool => $e !== null));
        if ($pica !== []) {
            throw new EsecIncarcare('verificările ' . implode(', ', $pica) . ' au picat');
        }
    }

    /**
     * A6, socotită aici din lista regulilor, nu cu Transformari::setare (o greșeală acolo nu trece și de verificare): goale →
     * '', zero → '0', proprietar → VERIFICARE_EMAIL, căsuța → căsuța SMTP a copiei (sau no-reply@verificare.invalid), prefix →
     * începe cu VERIFICARE, aleatoare → 24 de cifre hex. Aceeași întâietate ca a listei. null = cheia nu e în listă.
     *
     * @param array<string, mixed> $setari
     */
    public static function setareNeutralizata(string $cheie, string $valoare, array $setari, string $proprietar, string $casuta): ?bool
    {
        $in = static fn (string $lista): bool => in_array($cheie, (array) ($setari[$lista] ?? []), true);
        $potriveste = static fn (string $tipar): bool => preg_match((string) ($setari[$tipar] ?? '/(?!)/'), $cheie) === 1;
        return match (true) {
            $in('goale') || $potriveste('goale_tipar') => $valoare === '',
            $in('zero') => $valoare === '0',
            $in('proprietar') || $potriveste('proprietar_tipar') => $proprietar !== '' && $valoare === $proprietar,
            $in('casuta') => $valoare === $casuta && filter_var($valoare, FILTER_VALIDATE_EMAIL) !== false,
            $in('prefix') => str_starts_with($valoare, 'VERIFICARE'),
            $in('aleatoare') => preg_match('/^[0-9a-f]{24}$/', $valoare) === 1,
            default => null,
        };
    }

    private function aserțiune(string $nume, bool $ok, string $text): ?string
    {
        $this->rand($ok, $nume . ' ' . $text);
        return $ok ? null : $nume;
    }

    private function puneLicenta(): void
    {
        require_once __DIR__ . '/licenta-copie.php';
        $cheiePrivata = \App\Support\Verificare::cheiePrivataLicenta();
        try {
            $catalog = \App\Support\Plugins::catalog();
            $pusa = LicentaCopie::pune(LicentaCopie::pluginuri($catalog, $this->licenta), (string) $cheiePrivata);
        } catch (RuntimeException $e) {
            throw new EsecIncarcare('licența copiei nu s-a pus: ' . $e->getMessage());
        }
        $this->rand(true, 'licența copiei: pachetul ' . $this->licenta . ', v' . $pusa['versiune'] . ' (' . count($pusa['pornite']) . ' pluginuri: '
            . implode(', ', $pusa['pornite']) . ')');
        foreach (LicentaCopie::activari($this->acasa) as $rand) {
            $this->info($rand);
        }
    }

    /** @param array<string, int> $randuri */
    private function termina(array $randuri): int
    {
        $this->copie->actualizeazaMarcaj(['anonimizat' => 1, 'pas' => 'gata', 'incarcat_la' => gmdate('Y-m-d H:i:s')]);
        $dosar = \App\Support\Verificare::dosar();
        $umask = umask(0077);
        $scris = @file_put_contents($dosar . '/incarcat-la', gmdate('Y-m-d\TH:i:s\Z') . "\n");
        umask($umask);
        if ($scris === false) {
            throw new EsecIncarcare('nu pot scrie storage/verificare/incarcat-la');
        }
        @unlink($this->fisierSare());
        $this->rand(true, '_verificare: anonimizat = 1 și incarcat_la; storage/verificare/incarcat-la scris (copia se deschide cu parola porții, '
            . \App\Support\Verificare::zilePastrare() . ' zile); sarea încărcării ștearsă');
        if ($randuri !== []) {
            $this->spune('');
            $this->spune('Rândurile copiate (sursa = copia, în același instantaneu):');
            ksort($randuri);
            foreach ($randuri as $tabel => $n) {
                $this->spune('  ' . str_pad((string) $tabel, 34) . $n);
            }
        }
        $this->spune('');
        $this->spune('Durata: ' . $this->durata($this->start) . '. Trimite-ne tot textul (doar numere și nume de tabele, nicio dată a clienților).');
        $this->incheie('GATA: copia e anonimizată, toate verificările au trecut');
        return self::COD_GATA;
    }

    // ─────────────────────────────────────────────────── --demo

    /**
     * Copia demonstrativă: migrările (cu pașii pachetului), seed-ul neutru, setările implicite, seed.sql și datele inventate ale
     * tools/dev/date-demo.php, rulate pe copie; adresele @exemplu.ro devin @verificare.invalid, conturile personalului și ale
     * clienților inventați primesc parola personalului, setările se neutralizează ca la o copie a unui site, apoi contul
     * proprietarului, verificările care au sens fără date reale, licența, marcajul. Nicio bază sursă nu se deschide.
     */
    private function demo(): int
    {
        $this->migrari(true);
        $db = \App\Support\Database::connection((array) ((require $this->radacina . '/config/app.php')['db'] ?? []));
        if (!$db instanceof PDO) {
            throw new EsecIncarcare('baza copiei nu răspunde după migrări');
        }
        // Ca scripts/install.php (pașii 2 și 3): seed-ul neutru al blogului, apoi setările implicite; ca scripts/seed.php: seed.sql.
        if ((int) $db->query('SELECT COUNT(*) FROM blog_categories')->fetchColumn() === 0) {
            $categorie = $db->prepare('INSERT IGNORE INTO blog_categories (name, slug, sort_order) VALUES (:name, :slug, :sort_order)');
            foreach ([['Noutăți', 'noutati', 1], ['Evenimente', 'evenimente', 2]] as [$nume, $slug, $ordine]) {
                $categorie->execute(['name' => $nume, 'slug' => $slug, 'sort_order' => $ordine]);
            }
        }
        \App\Support\Settings::save($db, \App\Support\Settings::all($db));
        $seed = (string) @file_get_contents($this->radacina . '/database/seed.sql');
        if ($seed === '') {
            throw new EsecIncarcare('lipsește database/seed.sql');
        }
        $db->exec($seed);
        // Datele inventate, cu garda lui date-demo.php pentru copia de verificare (ANDAXI_DEMO_PE_VERIFICARE).
        if (!defined('ANDAXI_DEMO_PE_VERIFICARE')) {
            define('ANDAXI_DEMO_PE_VERIFICARE', true);
        }
        ob_start();
        try {
            (static function (string $fisier): void {
                require $fisier;
            })($this->radacina . '/tools/dev/date-demo.php');
        } finally {
            $iesire = (string) ob_get_clean();
        }
        if (!str_contains($iesire, 'Date demo gata')) {
            throw new EsecIncarcare('datele inventate (tools/dev/date-demo.php) nu s-au scris');
        }
        $this->rand(true, 'migrările, seed-ul neutru, setările implicite, seed.sql și datele inventate ale tools/dev/date-demo.php');

        // Adresele inventate @exemplu.ro devin @verificare.invalid, în orice coloană de text.
        $rescrise = 0;
        foreach ($this->copie->db->query("SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND DATA_TYPE IN ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext') AND TABLE_NAME <> '" . Copiere::TABEL_MARCAJ . "'")->fetchAll() as $r) {
            $q = SursaCopie::cita((string) $r['c']);
            $rescrise += (int) $this->copie->db->exec('UPDATE ' . SursaCopie::cita((string) $r['t']) . ' SET ' . $q . " = REPLACE(" . $q . ", '@exemplu.ro', '@"
                . Transformari::DOMENIU . "') WHERE " . $q . " LIKE '%@exemplu.ro%'");
        }
        $hash = password_hash($this->parolaPersonal, PASSWORD_BCRYPT);
        $this->parolaPersonal = '';
        $personal = $this->copie->db->query('SELECT email FROM admins ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->copie->db->prepare('UPDATE admins SET password_hash = :h')->execute(['h' => $hash]);
        $clienti = $this->copie->db->prepare('UPDATE users SET password_hash = :h');
        $clienti->execute(['h' => $hash]);
        $this->rand(true, 'adresele inventate: ' . $rescrise . ' rânduri rescrise @verificare.invalid; conturile personalului (' . implode(', ', $personal)
            . ') și ale celor ' . $clienti->rowCount() . ' clienți inventați au parola personalului');

        $this->neutralizeazaSetari();
        $adminId = $this->adminProprietar();
        $this->copie->actualizeazaMarcaj(['admin_proprietar_id' => $adminId, 'pas' => 'verificari']);

        // Verificările care au sens pe date inventate: emailurile, telefoanele, parolele, setările.
        $this->spune('');
        $this->spune('Verificările copiei demonstrative (doar numere):');
        $reguli = Reguli::din(['setari' => $this->neutralizareImplicita()]);
        $db2 = $this->copie->db;
        $numar = static fn (string $sql): int => (int) $db2->query($sql)->fetchColumn();
        $esecuri = [];
        $releEmail = 0;
        $undeEmail = [];
        foreach (['users.email' => "SELECT COUNT(*) FROM users WHERE email NOT LIKE '%@verificare.invalid'",
            'orders.billing_email' => "SELECT COUNT(*) FROM orders WHERE billing_email NOT LIKE '%@verificare.invalid'",
            'admins.email' => 'SELECT COUNT(*) FROM admins WHERE email NOT LIKE ' . $db2->quote('%@' . Transformari::DOMENIU) . ' AND id <> ' . $adminId,
            'newsletter_subscribers.email' => "SELECT COUNT(*) FROM newsletter_subscribers WHERE email NOT LIKE '%@verificare.invalid'"] as $coloana => $sql) {
            $n = $numar($sql);
            if ($n > 0) {
                $releEmail += $n;
                $undeEmail[] = $coloana . ' (' . $n . ')';
            }
        }
        $esecuri[] = $this->aserțiune('A1', $releEmail === 0, 'emailurile clienților, comenzilor, adminilor și abonaților: toate @verificare.invalid (' . $releEmail . ' altfel)'
            . ($undeEmail !== [] ? '; adresele sunt în: ' . implode(', ', $undeEmail) : ''));
        $releTelefon = $numar("SELECT COUNT(*) FROM users WHERE phone IS NOT NULL AND phone <> '' AND phone NOT REGEXP '^07[0-9]{8}$'");
        $esecuri[] = $this->aserțiune('A3', $releTelefon === 0, 'telefoanele clienților: 07 + 8 cifre (' . $releTelefon . ' altfel)');
        $releParola = $numar("SELECT COUNT(*) FROM users WHERE password_hash NOT LIKE '$2y$%'") + $numar("SELECT COUNT(*) FROM admins WHERE password_hash NOT LIKE '$2y$%'");
        $esecuri[] = $this->aserțiune('A5', $releParola === 0, 'parolele: bcrypt (' . $releParola . ' altfel)');
        $setari = (array) $reguli->date['setari'];
        $gresite = 0;
        $valori = $db2->query('SELECT `key`, `value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (array_merge((array) $setari['goale'], (array) $setari['zero'], (array) $setari['proprietar'], (array) $setari['casuta'],
            (array) $setari['prefix'], (array) $setari['aleatoare']) as $cheie) {
            if (!array_key_exists($cheie, $valori) || self::setareNeutralizata($cheie, (string) $valori[$cheie], $setari, $this->proprietar(), $this->casuta()) === false) {
                $gresite++;
            }
        }
        $esecuri[] = $this->aserțiune('A6', $gresite === 0, 'setările neutralizate au valoarea copiei (' . $gresite . ' altfel)');
        $this->info('A2, A4, A7–A9: nu se aplică (nicio bază sursă, doar date inventate)');
        if (array_filter($esecuri) !== []) {
            throw new EsecIncarcare('verificările ' . implode(', ', array_filter($esecuri)) . ' au picat');
        }
        $this->puneLicenta();
        return $this->termina([]);
    }
}
