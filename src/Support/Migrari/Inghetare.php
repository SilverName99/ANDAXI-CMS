<?php

declare(strict_types=1);

namespace App\Support\Migrari;

/**
 * Înghețarea setărilor unui site vechi la adoptare (docs/MIGRARI.md, „Înghețarea setărilor”; MIG-09,
 * DB-02 din planul Etapei 1): fișierul `setari-inghetate.json` din pachetul site-ului
 * (`site-uri/<site>/`, pe server `SITE_DATE_DOSAR`) ține, pentru fiecare setare la care codul comun ar
 * face altceva decât codul vechi al site-ului, valoarea pe care codul vechi o folosea efectiv. Pasul
 * `site/<site>/setari-inghetate` al runner-ului o scrie o singură dată, la adoptare, și doar unde
 * lipsește: o setare pe care site-ul o are deja e valoarea clientului și nu se atinge.
 *
 * Formatul 2 (fișierul îl scrie tools/inghetare/genereaza-setari.php, determinist):
 *
 *   {"_despre": "…", "format": 2, "site": "bioscem", "sursa": "Nou-site-bioscem@9f59e79",
 *    "provizoriu": "…" (doar până se confirmă sursa), "amprenta_nucleu": "<sha256>",
 *    "setari": {"cheie": "valoare" | {"valoare": "…", "mod": "lipsa" | "lipsa_sau_gol", "din": "…",
 *               "privat": true, "sursa": "…", "extrage": {…}, "vechi": "…", "nucleu": "…", "nota": "…"}},
 *    "fara_inghetare": {"cheie": "motiv" | {"motiv": "…", "vechi": "…", "nucleu": "…"}}}
 *
 * - `lipsa` (implicit; și forma scurtă, doar textul): se scrie dacă lipsește (INSERT IGNORE);
 * - `lipsa_sau_gol`: și peste o valoare goală (`TRIM(value) = ''`), unde codul vechi cădea tot pe
 *   valoarea lui când găsea gol;
 * - `privat`: valoarea adevărată e date ale clientului (o adresă de email, un telefon, un nume) și
 *   nu stă în git cât repo-ul e public (decizia OD-49): în fișier e un înlocuitor inventat
 *   (`@exemplu.ro`), iar valoarea adevărată stă în `privat/setari-inghetate.json`, lângă el, în afara
 *   git-ului. Fără el pasul se oprește cu codul 15, înainte de orice scriere;
 * - `provizoriu`: sursa nu e încă cea confirmată (raportul „live față de repo” din Etapa 0); pasul
 *   se oprește cu codul 15, afară de `--permite-provizoriu` pe copia de verificare (SITE_COPIE_VERIFICARE=1,
 *   repetiția adoptării; pe orice alt site opțiunea nu are efect);
 * - `din`, `sursa`, `extrage`, `vechi`, `nucleu`, `nota`, `fara_inghetare` și `amprenta_nucleu` sunt
 *   ale generatorului și ale verificării (tools/inghetare/); runner-ul le citește doar ca să le
 *   valideze forma.
 *
 * Pasul se înregistrează cu sha256 al fișierului (cu sfârșituri de rând LF), deci un fișier aplicat
 * nu se mai schimbă (codul 12): o corectură după adoptare e un fișier nou, `setari-inghetate-2.json`.
 * Fișierul privat nu intră în sha (poate lipsi după adoptare), dar spune pentru ce fișier e (`pentru`).
 */
final class Inghetare
{
    public const FORMAT = 2;
    public const MODURI = ['lipsa', 'lipsa_sau_gol'];
    public const DIN = ['implicit', 'cod', 'manual', 'extras'];
    /** Domeniul adreselor inventate (date demo, înlocuitori). */
    public const DOMENIU_DEMO = 'exemplu.ro';
    public const FISIER = 'setari-inghetate.json';
    /** Numele fișierelor de înghețare dintr-un pachet: cel de bază și corecturile -2, -3 … */
    public const TIPAR_FISIER = '/^setari-inghetate(?:-([2-9]|[1-9]\d+))?\.json$/';

    private const CHEI_SUS = ['format', 'site', 'sursa', 'provizoriu', 'amprenta_nucleu', 'inchis', 'setari', 'fara_inghetare'];
    private const CHEI_SETARE = ['valoare', 'mod', 'din', 'privat', 'sursa', 'extrage', 'vechi', 'nucleu', 'nota'];
    private const CHEI_FARA = ['motiv', 'vechi', 'nucleu'];
    private const CHEI_PRIVAT = ['site', 'sursa', 'pentru', 'setari'];
    /** O cheie din `settings` (coloana `key` are 120 de caractere). */
    private const TIPAR_CHEIE = '/^[a-z0-9][a-z0-9_]{0,119}$/';
    private const TIPAR_EMAIL = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+/';
    /**
     * Un număr de telefon românesc (fix sau mobil, cu sau fără +40, cu spații, puncte, liniuțe, bare sau paranteze
     * între grupuri, câte cel mult trei: „(0244) 510 507”, „+40 (244) 510 507”, „0244 / 510 507”; și cu o cifră în
     * plus, „0721 234 5678”), ca un cuvânt întreg: nu o bucată dintr-un număr mai lung sau dintr-un sha256 scris în
     * hex. E plasa de siguranță a datelor private (OD-49), deci prinde mai mult decât numerele bine scrise.
     */
    private const TIPAR_TELEFON = '/(?<![\w+])(?:\+40|0040|\(?0)[\s.\/()-]{0,3}[1-9](?:[\s.\/()-]{0,3}\d){8,9}(?!\w)/';

    /**
     * @param array<string, mixed> $date
     * @param array<string, array<string, mixed>> $setari cheie → intrarea, cu toate câmpurile (valoare, mod, din, privat …)
     * @param array<string, array<string, string>> $fara cheie → [motiv, vechi?, nucleu?]
     */
    private function __construct(
        private string $fisier,
        private string $sha,
        private array $date,
        private array $setari,
        private array $fara,
    ) {
    }

    /** @throws Eroare codul 2: fișierul lipsește, nu e JSON sau nu are forma de mai sus */
    public static function dinFisier(string $fisier): self
    {
        $text = is_file($fisier) ? @file_get_contents($fisier) : false;
        if (!is_string($text)) {
            throw new Eroare('înghețarea setărilor: nu pot citi ' . $fisier, Eroare::CONFIGURARE);
        }
        return self::dinText($text, $fisier);
    }

    /** @throws Eroare codul 2 */
    public static function dinText(string $text, string $fisier): self
    {
        $nume = basename($fisier);
        $date = json_decode($text, true);
        if (!is_array($date) || array_is_list($date) && $date !== []) {
            throw new Eroare('înghețarea setărilor: ' . $nume . ' nu e un obiect JSON (' . json_last_error_msg() . ')', Eroare::CONFIGURARE);
        }
        $gresit = static fn (string $ce): Eroare => new Eroare('înghețarea setărilor: ' . $nume . ': ' . $ce, Eroare::CONFIGURARE);
        foreach (array_keys($date) as $cheie) {
            if (!str_starts_with((string) $cheie, '_') && !in_array($cheie, self::CHEI_SUS, true)) {
                throw $gresit('cheie necunoscută „' . $cheie . '” (' . implode(', ', self::CHEI_SUS) . '; cele cu _ sunt note)');
            }
        }
        if (($date['format'] ?? null) !== self::FORMAT) {
            throw $gresit('„format” trebuie să fie ' . self::FORMAT);
        }
        if (!is_string($date['site'] ?? null) || preg_match('/^[a-z0-9][a-z0-9-]*$/', $date['site']) !== 1) {
            throw $gresit('„site” lipsește sau nu e un nume de site (litere mici, cifre, -)');
        }
        if (!is_string($date['sursa'] ?? null) || preg_match('/^[A-Za-z0-9._-]+@[0-9a-f]{7,40}$/', $date['sursa']) !== 1) {
            throw $gresit('„sursa” trebuie să fie <repo>@<commit> (codul vechi din care vin valorile)');
        }
        foreach (['provizoriu', 'inchis'] as $camp) {
            if (array_key_exists($camp, $date) && (!is_string($date[$camp]) || trim($date[$camp]) === '')) {
                throw $gresit('„' . $camp . '” e un text care spune de ce (sau lipsește)');
            }
        }
        if (array_key_exists('amprenta_nucleu', $date) && (!is_string($date['amprenta_nucleu']) || preg_match('/^[0-9a-f]{64}$/', $date['amprenta_nucleu']) !== 1)) {
            throw $gresit('„amprenta_nucleu” e un sha256');
        }
        $obiect = static fn (mixed $v): bool => is_array($v) && ($v === [] || !array_is_list($v));
        if (!$obiect($date['setari'] ?? null)) {
            throw $gresit('„setari” trebuie să fie un obiect {cheie: valoare}');
        }
        if (array_key_exists('fara_inghetare', $date) && !$obiect($date['fara_inghetare'])) {
            throw $gresit('„fara_inghetare” trebuie să fie un obiect {cheie: motiv}');
        }

        $setari = [];
        foreach ($date['setari'] as $cheie => $intrare) {
            $cheie = (string) $cheie;
            if (preg_match(self::TIPAR_CHEIE, $cheie) !== 1) {
                throw $gresit('cheia „' . $cheie . '” nu e o cheie de setare (litere mici, cifre, _)');
            }
            if (is_string($intrare)) {
                $intrare = ['valoare' => $intrare];
            }
            if (!$obiect($intrare)) {
                throw $gresit('setari.' . $cheie . ' e un text sau un obiect {valoare, mod, …}');
            }
            foreach (array_keys($intrare) as $camp) {
                if (!in_array($camp, self::CHEI_SETARE, true)) {
                    throw $gresit('setari.' . $cheie . ': câmp necunoscut „' . $camp . '” (' . implode(', ', self::CHEI_SETARE) . ')');
                }
            }
            $intrare += ['mod' => 'lipsa', 'din' => 'manual', 'privat' => false];
            if (!is_string($intrare['valoare'] ?? null)) {
                throw $gresit('setari.' . $cheie . ': „valoare” e un text (setările sunt texte)');
            }
            if (!in_array($intrare['mod'], self::MODURI, true)) {
                throw $gresit('setari.' . $cheie . ': „mod” e ' . implode(' sau ', self::MODURI));
            }
            if (!in_array($intrare['din'], self::DIN, true)) {
                throw $gresit('setari.' . $cheie . ': „din” e ' . implode(', ', self::DIN));
            }
            if (!is_bool($intrare['privat'])) {
                throw $gresit('setari.' . $cheie . ': „privat” e true sau false');
            }
            foreach (['sursa', 'vechi', 'nucleu', 'nota'] as $camp) {
                if (array_key_exists($camp, $intrare) && !is_string($intrare[$camp])) {
                    throw $gresit('setari.' . $cheie . ': „' . $camp . '” e un text');
                }
            }
            if (($intrare['din'] === 'extras') !== array_key_exists('extrage', $intrare)
                || (array_key_exists('extrage', $intrare) && !$obiect($intrare['extrage']))) {
                throw $gresit('setari.' . $cheie . ': „extrage” (obiect) merge doar și mereu cu „din”: „extras”');
            }
            $setari[$cheie] = $intrare;
        }

        $fara = [];
        foreach ($date['fara_inghetare'] ?? [] as $cheie => $intrare) {
            $cheie = (string) $cheie;
            if (preg_match(self::TIPAR_CHEIE, $cheie) !== 1) {
                throw $gresit('fara_inghetare: „' . $cheie . '” nu e o cheie de setare');
            }
            if (isset($setari[$cheie])) {
                throw $gresit('„' . $cheie . '” e și în setari, și în fara_inghetare: una sau alta');
            }
            if (is_string($intrare)) {
                $intrare = ['motiv' => $intrare];
            }
            if (!$obiect($intrare) || array_diff(array_keys($intrare), self::CHEI_FARA) !== []
                || !is_string($intrare['motiv'] ?? null) || trim($intrare['motiv']) === ''
                || array_filter($intrare, static fn (mixed $v): bool => !is_string($v)) !== []) {
                throw $gresit('fara_inghetare.' . $cheie . ' e motivul (un text negol) sau {motiv, vechi, nucleu}');
            }
            $fara[$cheie] = $intrare;
        }
        ksort($setari, SORT_STRING);
        ksort($fara, SORT_STRING);
        return new self($fisier, hash('sha256', str_replace("\r\n", "\n", $text)), $date, $setari, $fara);
    }

    public function fisier(): string
    {
        return $this->fisier;
    }

    /** sha256 al fișierului, cu sfârșituri de rând LF (cel înregistrat în _platforma_migrari). */
    public function sha256(): string
    {
        return $this->sha;
    }

    public function site(): string
    {
        return (string) $this->date['site'];
    }

    public function sursa(): string
    {
        return (string) $this->date['sursa'];
    }

    public function provizoriu(): ?string
    {
        return isset($this->date['provizoriu']) ? (string) $this->date['provizoriu'] : null;
    }

    public function inchis(): ?string
    {
        return isset($this->date['inchis']) ? (string) $this->date['inchis'] : null;
    }

    public function amprentaNucleu(): ?string
    {
        return isset($this->date['amprenta_nucleu']) ? (string) $this->date['amprenta_nucleu'] : null;
    }

    /** @return array<string, mixed> fișierul, citit */
    public function date(): array
    {
        return $this->date;
    }

    /** @return array<string, array<string, mixed>> cheie → intrarea, cu toate câmpurile (cele lipsă cu valorile implicite) */
    public function setari(): array
    {
        return $this->setari;
    }

    /** @return array<string, array<string, string>> cheie → [motiv, vechi?, nucleu?] */
    public function faraInghetare(): array
    {
        return $this->fara;
    }

    /** @return list<string> cheile cu valoarea în fișierul privat */
    public function cheiPrivate(): array
    {
        return array_keys(array_filter($this->setari, static fn (array $i): bool => $i['privat']));
    }

    /** `privat/<numele fișierului>`, lângă fișierul de înghețare (în afara git-ului: /site-uri/*\/privat/ în .gitignore). */
    public static function caleaPrivata(string $fisier): string
    {
        return dirname($fisier) . '/privat/' . basename($fisier);
    }

    /**
     * Valorile adevărate ale cheilor private, din fișierul privat (verificat: același site, aceeași sursă,
     * făcut pentru exact acest fișier, exact cheile private, doar texte). Fără chei private: [].
     *
     * @return array<string, string>
     * @throws ParametruLipsa codul 15 (fără valoare în mesaj)
     */
    public function valoriPrivate(string $id): array
    {
        $chei = $this->cheiPrivate();
        $fisier = self::caleaPrivata($this->fisier);
        if ($chei === [] && !is_file($fisier)) {
            return [];
        }
        $lipsa = static fn (string $ce): ParametruLipsa => new ParametruLipsa($id . ': ' . $ce . ' (privat/' . basename($fisier)
            . ' lângă fișierul înghețării; îl scrie tools/inghetare/genereaza-setari.php și nu stă în git, decizia OD-49; docs/MIGRARI.md)');
        if (!is_file($fisier)) {
            throw $lipsa('lipsesc valorile private ale înghețării, pentru ' . count($chei) . ' chei: ' . implode(', ', $chei));
        }
        $date = json_decode((string) @file_get_contents($fisier), true);
        if (!is_array($date) || !is_array($date['setari'] ?? null)) {
            throw $lipsa('fișierul privat al înghețării nu e JSON valid ({"site", "sursa", "pentru", "setari"})');
        }
        foreach (array_keys($date) as $cheie) {
            if (!str_starts_with((string) $cheie, '_') && !in_array($cheie, self::CHEI_PRIVAT, true)) {
                throw $lipsa('fișierul privat al înghețării are cheia necunoscută „' . $cheie . '”');
            }
        }
        if (($date['site'] ?? null) !== $this->site() || ($date['sursa'] ?? null) !== $this->sursa()) {
            throw $lipsa('fișierul privat al înghețării e al altui site sau al altei surse (site/sursa diferite de ale fișierului)');
        }
        if (($date['pentru'] ?? null) !== $this->sha) {
            throw $lipsa('fișierul privat al înghețării e pentru altă versiune a fișierului (pentru ≠ sha256 al lui): refă-l cu generatorul');
        }
        $primite = array_map('strval', array_keys($date['setari']));
        sort($primite, SORT_STRING);
        $asteptate = $chei;
        sort($asteptate, SORT_STRING);
        if ($primite !== $asteptate) {
            throw $lipsa('fișierul privat al înghețării are alte chei decât cele private (lipsesc: '
                . (implode(', ', array_diff($asteptate, $primite)) ?: '—') . '; în plus: ' . (implode(', ', array_diff($primite, $asteptate)) ?: '—') . ')');
        }
        $valori = [];
        foreach ($date['setari'] as $cheie => $valoare) {
            if (!is_string($valoare)) {
                throw $lipsa('fișierul privat al înghețării: valoarea cheii ' . $cheie . ' nu e un text');
            }
            $valori[(string) $cheie] = $valoare;
        }
        return $valori;
    }

    /**
     * Pasul runner-ului: fiecare setare, în ordinea cheii, cu Schema::setare (scrisă doar unde lipsește,
     * sau și unde e goală la `lipsa_sau_gol`). În plan (simulare) arată ce s-ar scrie și ce există deja;
     * valorile private nu apar nicăieri.
     *
     * @throws ParametruLipsa codul 15: înghețare provizorie fără voie, valori private lipsă
     */
    public function aplica(Schema $s, string $id): void
    {
        $provizoriu = $this->provizoriu();
        if ($provizoriu !== null && !$s->permiteProvizoriu()) {
            throw new ParametruLipsa($id . ': înghețarea e provizorie (' . $provizoriu . '); se aplică doar după ce sursa e confirmată '
                . '(scoate „provizoriu” din fișier, cu generatorul); ' . $s->cumSeRepetaProvizoriu());
        }
        $private = $this->valoriPrivate($id);
        foreach ($this->setari as $cheie => $intrare) {
            $valoare = $intrare['privat'] ? $private[$cheie] : (string) $intrare['valoare'];
            $s->setare($cheie, $valoare, (string) $intrare['mod'], $intrare['privat'] ? 'valoare privată' : self::scurt($valoare));
        }
    }

    /** O valoare pentru plan și mesaje: între ghilimele, cel mult 60 de caractere (cu lungimea, dacă e tăiată), rândurile noi ca ⏎. */
    public static function scurt(string $valoare): string
    {
        $text = str_replace(["\r\n", "\n", "\r"], '⏎', $valoare);
        return mb_strlen($text) > 60 ? '„' . mb_substr($text, 0, 57) . '…”, ' . mb_strlen($valoare) . ' caractere' : '„' . $text . '”';
    }

    // ------------------------------------------------------------- datele private (OD-49)

    /**
     * Adresele de email dintr-un text care nu sunt @exemplu.ro (ale unui client, deci în afara git-ului).
     *
     * @return list<string>
     */
    public static function emailuriReale(string $text): array
    {
        preg_match_all(self::TIPAR_EMAIL, $text, $m);
        return array_values(array_filter($m[0], static fn (string $e): bool =>
            strcasecmp(substr($e, -strlen('@' . self::DOMENIU_DEMO)), '@' . self::DOMENIU_DEMO) !== 0));
    }

    /** @return list<string> numerele de telefon dintr-un text (oricare, nu există unele „demo” afară de 0700 000 00x) */
    public static function telefoane(string $text): array
    {
        preg_match_all(self::TIPAR_TELEFON, $text, $m);
        return array_values(array_filter($m[0], static fn (string $t): bool =>
            preg_match('/^0700 000 00\d$/', $t) !== 1));
    }

    /** Textul are date ale unui client: o adresă de email (nu @exemplu.ro) sau un număr de telefon. */
    public static function arePrivat(string $text): bool
    {
        return self::emailuriReale($text) !== [] || self::telefoane($text) !== [];
    }

    /**
     * Înlocuitorul unei valori private în fișierul din git: același număr de adrese, inventate
     * („adresa1@exemplu.ro, adresa2@exemplu.ro”), altfel un telefon inventat, altfel „Valoare demo”.
     * Determinist: același text dă mereu același înlocuitor.
     */
    public static function inlocuitor(string $valoare): string
    {
        preg_match_all(self::TIPAR_EMAIL, $valoare, $m);
        if ($m[0] !== []) {
            return implode(', ', array_map(static fn (int $i): string => 'adresa' . $i . '@' . self::DOMENIU_DEMO, range(1, count($m[0]))));
        }
        return self::telefoane($valoare) !== [] ? '0700 000 001' : 'Valoare demo';
    }

    /** Un înlocuitor scris de inlocuitor() (verificarea fișierului din git). */
    public static function eInlocuitor(string $valoare): bool
    {
        return $valoare === 'Valoare demo' || $valoare === '0700 000 001'
            || preg_match('/^adresa1@exemplu\.ro(?:, adresa\d+@exemplu\.ro)*$/', $valoare) === 1;
    }
}
