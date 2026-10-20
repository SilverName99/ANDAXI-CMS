<?php

declare(strict_types=1);

namespace App\Support\Migrari;

use PDO;
use PDOException;
use Throwable;

/**
 * Ce poate face o migrare (funcția `sus` primește un Schema). Fiecare metodă se uită întâi în
 * information_schema și face doar ce lipsește: un obiect care există deja înseamnă „nimic de
 * făcut”, deci aceeași migrare merge pe o bază nouă (creează), pe una veche (găsește, una câte
 * una, ce a creat codul vechi) și după o rulare întreruptă (continuă). Regulile, pe larg, în
 * docs/MIGRARI.md:
 *
 *  - niciodată AFTER/FIRST, niciodată IF NOT EXISTS în ALTER (doar MariaDB îl are): detectarea
 *    se face aici, la fel pe ambele servere;
 *  - fiecare ALTER pleacă cu ALGORITHM=INSTANT, apoi INPLACE + LOCK=NONE; fără clauză (adică
 *    posibil copierea tabelului, cu scrierile oprite) doar cu --permite-blocante;
 *  - o coloană care există cu altă definiție nu se schimbă singură: e un avertisment în plan
 *    (pentru o schimbare voită: modificaColoana, care e blocantă);
 *  - cheile străine doar în tabele noi, în corpul lui tabel();
 *  - ștergerile (stergeColoana, stergeTabel) doar în migrările de tip „contract”;
 *  - 1050/1060/1061/1091 (obiectul a apărut sau a dispărut între timp, de exemplu fiindcă
 *    codul vechi l-a creat chiar atunci): se verifică din nou și se acceptă dacă baza e cum
 *    trebuie; 1205/1213 (lacăt, deadlock): se reîncearcă după 2, 4, 8, 16, 30 s;
 *  - niciun mesaj nu conține valori din rânduri (faraValori).
 *
 * În simulare (planul, --plan, și verificarea dinaintea lui --aplica) nu se trimite nimic care
 * schimbă baza: operațiile se notează, cu o clasă (fara_efect, creare, instant, inplace, copie,
 * date, sterge), iar tabelele create de migrările de dinainte din plan se văd în model.
 */
final class Schema
{
    private const CURSE = [1050, 1060, 1061, 1091];
    private const REINCERCARI = [1205, 1213];
    /** Serverul nu poate operația cu clauza dată (sau a epuizat versiunile instant ale rândului, MySQL 8.0.29+). */
    private const NU_POATE = [1845, 1846, 4092];

    /** @var array<string, ?array> structura tabelelor din bază, citită la nevoie */
    private array $cache = [];

    /** @var array<string, ?array{randuri: int, octeti: int}> mărimea tabelelor din bază (doar pentru plan) */
    private array $marimi = [];

    public function __construct(
        private PDO $db,
        private Server $server,
        private Executie $executie,
    ) {
    }

    public function server(): Server
    {
        return $this->server;
    }

    public function simulare(): bool
    {
        return $this->executie->simulare;
    }

    /** --permite-provizoriu pe copia de verificare: o înghețare sau un parametru provizoriu al site-ului se aplică totuși. */
    public function permiteProvizoriu(): bool
    {
        return $this->executie->permiteProvizoriu;
    }

    /** Ce e de făcut cu un pas provizoriu refuzat (sfârșitul mesajului codului 15). */
    public function cumSeRepetaProvizoriu(): string
    {
        return $this->executie->provizoriuCerut
            ? '--permite-provizoriu merge doar pe copia de verificare (SITE_COPIE_VERIFICARE=1), nu aici'
            : 'pe copia de verificare, --permite-provizoriu';
    }

    // ------------------------------------------------------------ inspectare

    public function existaTabel(string $tabel): bool
    {
        return $this->structura($tabel) !== null;
    }

    public function existaColoana(string $tabel, string $coloana): bool
    {
        return isset($this->structura($tabel)['coloane'][$coloana]);
    }

    public function existaIndex(string $tabel, string $index): bool
    {
        return isset($this->structura($tabel)['indecsi'][$index]);
    }

    /** Pasul rulează doar dacă tabelul există (de exemplu un tabel vechi, de convertit). */
    public function dacaTabel(string $tabel, callable $pas): void
    {
        if ($this->existaTabel($tabel)) {
            $pas($this);
        }
    }

    /** Pasul rulează doar dacă există coloana (de exemplu o coloană veche, de copiat în cea nouă). */
    public function dacaColoana(string $tabel, string $coloana, callable $pas): void
    {
        if ($this->existaColoana($tabel, $coloana)) {
            $pas($this);
        }
    }

    /**
     * O interogare care doar citește (SELECT, SHOW). În simulare, un tabel care va fi creat
     * abia de plan dă un rezultat gol.
     *
     * @return list<array<string, mixed>>
     */
    public function citeste(string $sql, array $parametri = []): array
    {
        if (preg_match('/^\s*(SELECT|SHOW|WITH)\b/i', $sql) !== 1 || str_contains($sql, ';')) {
            throw new Eroare($this->executie->id . ': citeste() primește doar o interogare SELECT/SHOW', Eroare::MIGRARE);
        }
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($parametri);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            if ($this->executie->simulare && self::cod($e) === 1146) {
                return [];
            }
            throw $this->eroare('citeste', '', $e);
        }
    }

    // --------------------------------------------------------- tabele, coloane

    /**
     * Un tabel nou: corpul e ce stă între parantezele lui CREATE TABLE (coloane, chei, chei
     * străine). Motorul și setul de caractere le pune runner-ul: InnoDB, utf8mb4_unicode_ci.
     * Dacă tabelul există, nu se întâmplă nimic: coloana()/index() de după aduc structura la zi.
     */
    public function tabel(string $tabel, string $corp): void
    {
        $this->numeValid($tabel);
        if (preg_match('/\)\s*(ENGINE|DEFAULT\s+CHARSET|CHARSET|COLLATE)\b/i', $corp) === 1) {
            throw new Eroare($this->executie->id . ': tabel(' . $tabel . '): corpul are doar coloanele și cheile; motorul și setul de caractere le pune runner-ul', Eroare::MIGRARE);
        }
        $structura = Amprenta::structuraDinCorp($corp, $necunoscute);
        if ($necunoscute !== []) {
            throw new Eroare($this->executie->id . ': tabel(' . $tabel . '): nu înțeleg: ' . implode('; ', $necunoscute)
                . self::faraColatie($necunoscute), Eroare::MIGRARE);
        }
        if ($this->existaTabel($tabel)) {
            $this->noteaza('fara_efect', 'tabel', $tabel, 'există');
            return;
        }
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $tabel . '` (' . $corp . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        if ($this->executie->simulare) {
            $structura['motor'] = 'InnoDB';
            $structura['colatie'] = 'utf8mb4_unicode_ci';
            $this->executie->model[$tabel] = $structura;
            $this->noteaza('creare', 'tabel', $tabel, count($structura['coloane']) . ' coloane');
            return;
        }
        ($this->executie->inainte)();
        $start = microtime(true);
        $this->cuReincercari('tabel', $tabel, fn (): int => (int) $this->db->exec($sql));
        unset($this->cache[$tabel]);
        if ($this->notaTabelExistent()) {
            // CREATE TABLE IF NOT EXISTS fără efect: tabelul l-a făcut altcineva între timp (codul
            // vechi). Nu e o operație a migrării; tabelComplet aduce apoi la zi ce-i lipsește.
            $this->noteaza('fara_efect', 'tabel', $tabel, 'făcut între timp de altcineva (1050)');
            return;
        }
        $this->cuEfect('tabel', $tabel, 'creare', (microtime(true) - $start) * 1000);
    }

    /** Nota 1050 („Table … already exists”) a ultimului CREATE TABLE IF NOT EXISTS. */
    private function notaTabelExistent(): bool
    {
        try {
            foreach ($this->db->query('SHOW WARNINGS')->fetchAll(PDO::FETCH_ASSOC) as $nota) {
                if ((int) ($nota['Code'] ?? 0) === 1050) {
                    return true;
                }
            }
        } catch (PDOException) {
        }
        return false;
    }

    /**
     * Un tabel întreg, cum îl vrea codul: coloanele (nume => definiție, ca la coloana()) și cheile,
     * scrise ca în CREATE TABLE („PRIMARY KEY (id)”, „UNIQUE KEY nume (a, b)”, „KEY nume (a)”,
     * „CONSTRAINT fk_x FOREIGN KEY (a) REFERENCES t (id) ON DELETE SET NULL”). Pe o bază fără
     * tabel: un singur CREATE TABLE cu tot. Pe una care îl are (o bază veche, sau creat chiar
     * atunci de codul vechi): fiecare coloană se caută și se adaugă doar dacă lipsește (coloana()),
     * apoi fiecare index (index(), unic(): același nume sau unul echivalent ajunge). Cheia primară
     * și cheile străine nu se adaugă niciodată peste un tabel existent (o cheie străină pică pe
     * rânduri orfane și, pe MySQL, copiază tabelul): una care lipsește e un avertisment, iar
     * --verifica o arată. În chei, un nume de coloană rezervat se scrie între apostroafe inverse
     * („PRIMARY KEY (`key`)”); în lista coloanelor le pune runner-ul.
     *
     * @param array<string, string> $coloane
     * @param list<string> $chei
     */
    public function tabelComplet(string $tabel, array $coloane, array $chei = []): void
    {
        $this->numeValid($tabel);
        if ($coloane === []) {
            throw new Eroare($this->executie->id . ': tabelComplet(' . $tabel . ') fără coloane', Eroare::MIGRARE);
        }
        $parti = [];
        $cerute = [];
        foreach ($coloane as $coloana => $definitie) {
            $this->numeValid((string) $coloana);
            $cerute[(string) $coloana] = $this->definitie($tabel, (string) $coloana, $definitie);
            $parti[] = '`' . $coloana . '` ' . trim($definitie);
        }
        $primara = null;
        $indecsi = [];
        $straine = [];
        foreach ($chei as $cheie) {
            $structura = Amprenta::structuraDinCorp($cheie);
            if ($structura['coloane'] !== []) {
                throw new Eroare($this->executie->id . ': tabelComplet(' . $tabel . '): „' . $cheie . '” nu e o cheie (coloanele stau în lista lor)', Eroare::MIGRARE);
            }
            if ($structura['fk'] !== []) {
                $straine += $structura['fk'];
            } elseif (count($structura['indecsi']) === 1 && isset($structura['indecsi']['PRIMARY'])) {
                $primara = $structura['indecsi']['PRIMARY']['coloane'];
            } elseif (count($structura['indecsi']) === 1 && preg_match('/^\s*(UNIQUE\s+)?(KEY|INDEX)\s+`?[A-Za-z0-9_]+`?\s*\(/i', $cheie) === 1) {
                $indecsi += $structura['indecsi'];
            } else {
                throw new Eroare($this->executie->id . ': tabelComplet(' . $tabel . '): nu înțeleg cheia „' . $cheie
                    . '” (PRIMARY KEY (…), UNIQUE KEY nume (…), KEY nume (…), CONSTRAINT nume FOREIGN KEY (…) REFERENCES …)', Eroare::MIGRARE);
            }
            $parti[] = trim($cheie);
        }
        $exista = $this->existaTabel($tabel);
        $this->tabel($tabel, implode(",\n", $parti));
        if (!$exista) {
            // Creat acum. Dacă totuși îi lipsește ceva (o coloană, un index, cheia primară, o cheie
            // străină) sau o coloană are altă definiție, l-a creat altcineva chiar atunci (codul vechi,
            // cu CREATE TABLE IF NOT EXISTS al lui): se aduce la zi ca un tabel existent, cu
            // avertismentele lui.
            $creat = $this->structura($tabel);
            if ($creat !== null && self::eCaCerut($creat, $cerute, $primara, $indecsi, $straine)) {
                return;
            }
        }
        foreach ($coloane as $coloana => $definitie) {
            if ($primara !== null && in_array((string) $coloana, $primara, true)) {
                if (!$this->existaColoana($tabel, (string) $coloana)) {
                    $this->avertisment('tabelul ' . $tabel . ' nu are coloana cheii primare ' . $coloana . '; nu se adaugă peste un tabel existent');
                }
                continue;
            }
            $this->coloana($tabel, (string) $coloana, $definitie);
        }
        $existente = $this->structura($tabel)['indecsi'] ?? [];
        if ($primara !== null && !Amprenta::indecsiEchivalenti($existente['PRIMARY'] ?? ['unic' => false, 'coloane' => []], Amprenta::index(true, $primara))) {
            $this->avertisment('tabelul ' . $tabel . ' are altă cheie primară decât (' . implode(', ', $primara) . '); nu se schimbă');
        }
        foreach ($indecsi as $nume => $index) {
            if ($index['unic']) {
                $this->unic($tabel, (string) $nume, $index['coloane']);
            } else {
                $this->index($tabel, (string) $nume, $index['coloane']);
            }
        }
        $fkExistente = $this->structura($tabel)['fk'] ?? [];
        foreach ($straine as $nume => $fk) {
            $gasita = isset($fkExistente[$nume]);
            foreach ($fkExistente as $alta) {
                $gasita = $gasita || ($alta['coloane'] === $fk['coloane'] && $alta['tabel'] === $fk['tabel']);
            }
            if (!$gasita) {
                $this->avertisment('cheia străină ' . $tabel . '.' . $nume . ' lipsește; nu se adaugă peste un tabel existent (rânduri orfane, '
                    . 'copierea tabelului pe MySQL)');
            }
        }
    }

    /**
     * O coloană nouă, la coada tabelului. Dacă există: nimic; cu altă definiție: avertisment
     * (nu se schimbă niciodată singură). NOT NULL fără DEFAULT pe un tabel cu rânduri e
     * refuzat: codul vechi, care nu știe de coloană, n-ar mai putea scrie rânduri.
     */
    public function coloana(string $tabel, string $coloana, string $definitie): void
    {
        $this->numeValid($tabel);
        $this->numeValid($coloana);
        $ceruta = $this->definitie($tabel, $coloana, $definitie);
        $structura = $this->structuraObligatorie($tabel, 'coloana');
        if (isset($structura['coloane'][$coloana])) {
            $this->comparaDefinitia($tabel, $coloana, $structura['coloane'][$coloana], $ceruta);
            $this->noteaza('fara_efect', 'coloana', $tabel . '.' . $coloana, 'există');
            return;
        }
        if (!$ceruta['null'] && $ceruta['implicit'] === null && !str_contains($ceruta['extra'], 'auto_increment')
            && $this->areRanduri($tabel)) {
            throw new Eroare($this->executie->id . ': coloana ' . $tabel . '.' . $coloana
                . ' e NOT NULL fără DEFAULT, iar tabelul are rânduri: dă-i un DEFAULT (codul vechi scrie rânduri fără ea)', Eroare::MIGRARE);
        }
        $sql = 'ALTER TABLE `' . $tabel . '` ADD COLUMN `' . $coloana . '` ' . trim($definitie);
        if ($this->executie->simulare) {
            $this->modifica($tabel, static function (array $s) use ($coloana, $ceruta): array {
                $s['coloane'][$coloana] = self::doarColoana($ceruta);
                return $s;
            });
            $this->noteaza($this->server->clasaEstimata('coloana'), 'coloana', $tabel . '.' . $coloana, '', $tabel);
            return;
        }
        $this->alter('coloana', $tabel, $tabel . '.' . $coloana, $sql,
            fn (): bool => $this->existaColoana($tabel, $coloana));
    }

    /**
     * Doar valoarea implicită a unei coloane existente (ALTER COLUMN … SET DEFAULT): rândurile
     * nu se ating, iar serverul o face instant (doar în definiția tabelului). Pentru un pas
     * anume al unei migrări, nu pentru „adus la zi”: o coloană existentă cu altă definiție rămâne
     * altfel un avertisment. $valoare: un text între ghilimele simple sau un număr. Aceeași
     * valoare deja: nimic.
     */
    public function schimbaImplicit(string $tabel, string $coloana, string $valoare): void
    {
        $this->numeValid($tabel);
        $this->numeValid($coloana);
        $valoare = trim($valoare);
        if (preg_match("/^('[A-Za-z0-9_ .,:\\/@-]*'|-?[0-9]+(\\.[0-9]+)?)$/", $valoare) !== 1) {
            throw new Eroare($this->executie->id . ': schimbaImplicit(' . $tabel . '.' . $coloana . '): valoarea e un text simplu între ghilimele sau un număr', Eroare::MIGRARE);
        }
        $structura = $this->structuraObligatorie($tabel, 'schimbaImplicit');
        $existenta = $structura['coloane'][$coloana] ?? null;
        if ($existenta === null) {
            throw new Eroare($this->executie->id . ': schimbaImplicit: ' . $tabel . '.' . $coloana . ' nu există', Eroare::MIGRARE);
        }
        $ceruta = Amprenta::coloanaDinDefinitie($existenta['tip'] . ' DEFAULT ' . $valoare)['implicit'];
        if ($existenta['implicit'] === $ceruta) {
            $this->noteaza('fara_efect', 'implicit', $tabel . '.' . $coloana, 'are deja valoarea implicită ' . $valoare);
            return;
        }
        if ($this->executie->simulare) {
            $this->modifica($tabel, static function (array $s) use ($coloana, $ceruta): array {
                $s['coloane'][$coloana]['implicit'] = $ceruta;
                return $s;
            });
            $this->noteaza($this->server->clasaEstimata('implicit'), 'implicit', $tabel . '.' . $coloana, 'DEFAULT ' . $valoare, $tabel);
            return;
        }
        $this->alter('implicit', $tabel, $tabel . '.' . $coloana, 'ALTER TABLE `' . $tabel . '` ALTER COLUMN `' . $coloana . '` SET DEFAULT ' . $valoare,
            fn (): bool => ($this->structura($tabel)['coloane'][$coloana]['implicit'] ?? null) === $ceruta);
    }

    /**
     * Schimbă definiția unei coloane existente (tipul, NULL, valoarea implicită). Pe aproape
     * orice server asta copiază tabelul cu scrierile oprite, deci e mereu clasată „copie”:
     * runner-ul o refuză fără --permite-blocante (codul 11), oricum ar fi declarată migrarea.
     */
    public function modificaColoana(string $tabel, string $coloana, string $definitie): void
    {
        $this->numeValid($tabel);
        $this->numeValid($coloana);
        $ceruta = $this->definitie($tabel, $coloana, $definitie);
        $structura = $this->structuraObligatorie($tabel, 'modificaColoana');
        if (!isset($structura['coloane'][$coloana])) {
            throw new Eroare($this->executie->id . ': modificaColoana: ' . $tabel . '.' . $coloana . ' nu există (o coloană nouă se face cu coloana())', Eroare::MIGRARE);
        }
        if (Amprenta::diferenteColoana($structura['coloane'][$coloana], $ceruta) === []) {
            $this->noteaza('fara_efect', 'modifica', $tabel . '.' . $coloana, 'are deja definiția cerută');
            return;
        }
        $sql = 'ALTER TABLE `' . $tabel . '` MODIFY COLUMN `' . $coloana . '` ' . trim($definitie);
        if ($this->executie->simulare) {
            $this->modifica($tabel, static function (array $s) use ($coloana, $ceruta): array {
                $s['coloane'][$coloana] = self::doarColoana($ceruta);
                return $s;
            });
            $this->noteaza('copie', 'modifica', $tabel . '.' . $coloana, Amprenta::descrieColoana($structura['coloane'][$coloana])
                . ' → ' . Amprenta::descrieColoana(self::doarColoana($ceruta)), $tabel);
            return;
        }
        $this->alter('modifica', $tabel, $tabel . '.' . $coloana, $sql,
            fn (): bool => isset($this->structura($tabel)['coloane'][$coloana])
                && Amprenta::diferenteColoana($this->structura($tabel)['coloane'][$coloana], $ceruta) === []);
    }

    // ----------------------------------------------------------------- indecși

    /** @param list<string> $coloane numele coloanelor, eventual cu lungimea prefixului: ['slug', 'titlu(100)'] */
    public function index(string $tabel, string $index, array $coloane): void
    {
        $this->adaugaIndex($tabel, $index, $coloane, false);
    }

    /**
     * Un index unic. Înainte, numără valorile repetate: dacă există, migrarea se oprește cu
     * numărul lor (niciodată valorile).
     *
     * @param list<string> $coloane
     */
    public function unic(string $tabel, string $index, array $coloane): void
    {
        $this->adaugaIndex($tabel, $index, $coloane, true);
    }

    public function stergeIndex(string $tabel, string $index): void
    {
        $this->numeValid($tabel);
        $this->numeValid($index);
        $structura = $this->structura($tabel);
        if ($structura === null || !isset($structura['indecsi'][$index])) {
            $this->noteaza('fara_efect', 'sterge_index', $tabel . '.' . $index, 'nu există');
            return;
        }
        if ($this->executie->simulare) {
            $this->modifica($tabel, static function (array $s) use ($index): array {
                unset($s['indecsi'][$index]);
                return $s;
            });
            $this->noteaza('inplace', 'sterge_index', $tabel . '.' . $index, '', $tabel);
            return;
        }
        $this->alter('sterge_index', $tabel, $tabel . '.' . $index, 'ALTER TABLE `' . $tabel . '` DROP INDEX `' . $index . '`',
            fn (): bool => !$this->existaIndex($tabel, $index));
    }

    /** @param list<string> $coloane */
    private function adaugaIndex(string $tabel, string $index, array $coloane, bool $unic): void
    {
        $this->numeValid($tabel);
        $this->numeValid($index);
        if ($coloane === []) {
            throw new Eroare($this->executie->id . ': indexul ' . $tabel . '.' . $index . ' fără coloane', Eroare::MIGRARE);
        }
        $sqlColoane = [];
        $normalizate = [];
        foreach ($coloane as $coloana) {
            if (preg_match('/^([A-Za-z0-9_]+)(?:\((\d+)\))?$/', (string) $coloana, $m) !== 1) {
                throw new Eroare($this->executie->id . ': indexul ' . $tabel . '.' . $index . ': coloana „' . $coloana . '” nu e un nume', Eroare::MIGRARE);
            }
            $sqlColoane[] = '`' . $m[1] . '`' . (isset($m[2]) ? '(' . $m[2] . ')' : '');
            $normalizate[] = $m[1] . (isset($m[2]) ? '(' . $m[2] . ')' : '');
        }
        $dorit = Amprenta::index($unic, $normalizate);
        $fel = $unic ? 'unic' : 'index';
        $structura = $this->structuraObligatorie($tabel, $fel);
        if (isset($structura['indecsi'][$index])) {
            if (!Amprenta::indecsiEchivalenti($structura['indecsi'][$index], $dorit)) {
                $this->avertisment('indexul ' . $tabel . '.' . $index . ' există, pe (' . implode(', ', $structura['indecsi'][$index]['coloane'])
                    . ')' . ($structura['indecsi'][$index]['unic'] ? ' unic' : '') . '; migrarea cere (' . implode(', ', $normalizate) . ')'
                    . ($unic ? ' unic' : '') . '. Nu se schimbă.');
            }
            $this->noteaza('fara_efect', $fel, $tabel . '.' . $index, 'există');
            return;
        }
        foreach ($structura['indecsi'] as $alt => $existent) {
            if (Amprenta::indecsiEchivalenti($existent, $dorit)) {
                $this->noteaza('fara_efect', $fel, $tabel . '.' . $index, 'există ca ' . $alt);
                return;
            }
        }
        if ($unic) {
            $repetate = $this->valoriRepetate($tabel, $normalizate);
            if ($repetate > 0) {
                throw new Eroare($this->executie->id . ': indexul unic ' . $tabel . '.' . $index . ' nu se poate face: '
                    . $repetate . ' valori apar de mai multe ori (doar numărul; datele trebuie curățate întâi)', Eroare::MIGRARE);
            }
        }
        $sql = 'ALTER TABLE `' . $tabel . '` ADD ' . ($unic ? 'UNIQUE ' : '') . 'INDEX `' . $index . '` (' . implode(', ', $sqlColoane) . ')';
        if ($this->executie->simulare) {
            $this->modifica($tabel, static function (array $s) use ($index, $dorit): array {
                $s['indecsi'][$index] = $dorit;
                return $s;
            });
            $this->noteaza('inplace', $fel, $tabel . '.' . $index, '', $tabel);
            return;
        }
        $this->alter('index', $tabel, $tabel . '.' . $index, $sql, function () use ($tabel, $index, $dorit): bool {
            foreach ($this->structura($tabel)['indecsi'] ?? [] as $nume => $existent) {
                if ($nume === $index || Amprenta::indecsiEchivalenti($existent, $dorit)) {
                    return true;
                }
            }
            return false;
        });
    }

    // ---------------------------------------------- ștergeri (doar „contract”)

    public function stergeColoana(string $tabel, string $coloana): void
    {
        $this->doarContract('stergeColoana');
        $this->numeValid($tabel);
        $this->numeValid($coloana);
        if (!$this->existaColoana($tabel, $coloana)) {
            $this->noteaza('fara_efect', 'sterge_coloana', $tabel . '.' . $coloana, 'nu există');
            return;
        }
        if ($this->executie->simulare) {
            $this->modifica($tabel, static function (array $s) use ($coloana): array {
                unset($s['coloane'][$coloana]);
                return $s;
            });
            $this->noteaza('sterge', 'sterge_coloana', $tabel . '.' . $coloana, '', $tabel);
            return;
        }
        $this->alter('sterge_coloana', $tabel, $tabel . '.' . $coloana, 'ALTER TABLE `' . $tabel . '` DROP COLUMN `' . $coloana . '`',
            fn (): bool => !$this->existaColoana($tabel, $coloana));
    }

    public function stergeTabel(string $tabel): void
    {
        $this->doarContract('stergeTabel');
        $this->numeValid($tabel);
        if (!$this->existaTabel($tabel)) {
            $this->noteaza('fara_efect', 'sterge_tabel', $tabel, 'nu există');
            return;
        }
        if ($this->executie->simulare) {
            $this->executie->model[$tabel] = null;
            $this->noteaza('sterge', 'sterge_tabel', $tabel);
            return;
        }
        $this->executa('sterge_tabel', $tabel, $tabel, 'sterge', 'DROP TABLE IF EXISTS `' . $tabel . '`');
    }

    // ------------------------------------------------------------------- date

    /**
     * O singură instrucțiune care schimbă date (INSERT, UPDATE, DELETE, REPLACE), cu condiții
     * care o fac fără efect a doua oară. Întoarce câte rânduri a schimbat (0 în simulare).
     */
    public function date(string $descriere, string $sql, array $parametri = []): int
    {
        if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql) !== 1 || str_contains($sql, ';')) {
            throw new Eroare($this->executie->id . ': date() primește o singură instrucțiune INSERT/UPDATE/DELETE/REPLACE', Eroare::MIGRARE);
        }
        if ($this->executie->simulare) {
            $this->noteaza('date', 'date', $descriere);
            return 0;
        }
        ($this->executie->inainte)();
        $start = microtime(true);
        // Instrucțiunea și numărătoarea ei în _platforma_migrari, în aceeași tranzacție.
        $randuri = $this->cuReincercari('date', $descriere, function () use ($sql, $parametri): int {
            $this->db->beginTransaction();
            try {
                $stmt = $this->db->prepare($sql);
                $stmt->execute($parametri);
                $randuri = $stmt->rowCount();
                if ($randuri > 0) {
                    ($this->executie->progres)(1, null);
                }
                $this->comite('date');
                return $randuri;
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }
        });
        $ms = (microtime(true) - $start) * 1000;
        if ($randuri > 0) {
            $this->cuEfect('date', $descriere, 'date', $ms, false);
        } else {
            $this->noteaza('fara_efect', 'date', $descriere, '0 rânduri');
        }
        return $randuri;
    }

    /**
     * O setare a site-ului (tabelul `settings`), doar într-un pas al site-ului (înghețarea setărilor,
     * Inghetare::aplica): `lipsa` o scrie doar dacă lipsește (INSERT IGNORE), `lipsa_sau_gol` și peste o
     * valoare goală (UPDATE … WHERE TRIM(value) = ''). O setare care există rămâne a clientului. Planul
     * arată ce s-ar scrie ($arata: valoarea scurtată, sau „valoare privată”) și ce există deja; jurnalul
     * doar cheia. Instrucțiunea și numărătoarea ei, în aceeași tranzacție (ca la date()).
     */
    public function setare(string $cheie, string $valoare, string $mod, string $arata): void
    {
        if ($this->executie->tip !== 'site') {
            throw new Eroare($this->executie->id . ': setare() merge doar într-un pas al site-ului (înghețarea setărilor)', Eroare::MIGRARE);
        }
        if (preg_match('/^[a-z0-9][a-z0-9_]{0,119}$/', $cheie) !== 1 || !in_array($mod, ['lipsa', 'lipsa_sau_gol'], true)) {
            throw new Eroare($this->executie->id . ': setare(): cheie (litere mici, cifre, _) și mod lipsa | lipsa_sau_gol', Eroare::MIGRARE);
        }
        $obiect = 'settings.' . $cheie . ' (' . $mod . ')';
        // În simulare, pe o bază nouă tabelul vine abia din nucleu/0001: citeste() dă gol, deci „se scrie”.
        $rand = $this->citeste('SELECT `value` FROM settings WHERE `key` = ?', [$cheie]);
        $exista = $rand !== [];
        // TRIM al serverului taie doar spațiile (nu și tab-uri sau rânduri noi): aceeași regulă și aici.
        $goala = $exista && trim((string) $rand[0]['value'], ' ') === '';
        if ($exista && !($mod === 'lipsa_sau_gol' && $goala)) {
            $this->noteaza('fara_efect', 'setare', $obiect, 'există');
            return;
        }
        if ($this->executie->simulare) {
            $this->noteaza('date', 'setare', $obiect, ($exista ? 'se completează (e goală): ' : 'se scrie: ') . $arata);
            return;
        }
        ($this->executie->inainte)();
        $start = microtime(true);
        $randuri = $this->cuReincercari('setare', $obiect, function () use ($cheie, $valoare, $mod): int {
            $this->db->beginTransaction();
            try {
                $stmt = $this->db->prepare('INSERT IGNORE INTO settings (`key`, `value`) VALUES (?, ?)');
                $stmt->execute([$cheie, $valoare]);
                $randuri = $stmt->rowCount();
                if ($randuri === 0 && $mod === 'lipsa_sau_gol') {
                    $stmt = $this->db->prepare("UPDATE settings SET `value` = ? WHERE `key` = ? AND TRIM(`value`) = ''");
                    $stmt->execute([$valoare, $cheie]);
                    $randuri = $stmt->rowCount();
                }
                if ($randuri > 0) {
                    ($this->executie->progres)(1, null);
                }
                $this->comite('date');
                return $randuri;
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }
        });
        $ms = (microtime(true) - $start) * 1000;
        if ($randuri > 0) {
            $this->cuEfect('setare', $obiect, 'date', $ms, false);
        } else {
            // Între citire și scriere a pus-o altcineva (codul vechi, în timpul adoptării): rămâne a lui.
            $this->noteaza('fara_efect', 'setare', $obiect, 'există');
        }
    }

    /**
     * O schimbare de date pe tot tabelul, pe bucăți după cheia primară (numerică): $pas($db, $de, $pana)
     * face rândurile cu $de < pk <= $pana și poate întoarce câte a schimbat. Fiecare bucată și
     * cursorul ei se scriu în aceeași tranzacție, deci după o întrerupere (sau la capătul
     * bugetului de timp, codul 13) rularea următoare continuă exact de unde a rămas: o bucată nu
     * se face de două ori. Pasul nu are voie să trimită DDL (ar închide tranzacția).
     *
     * @param callable(PDO, int, int): (int|null|void) $pas
     */
    public function peBucati(string $tabel, string $pk, int $marime, callable $pas, ?string $cheie = null): void
    {
        $this->numeValid($tabel);
        $this->numeValid($pk);
        $marime = max(1, $marime);
        $cheie ??= $tabel . '.' . $pk . '#' . (++$this->executie->apeluriBucati);
        $obiect = $tabel . ' (' . $cheie . ')';
        if (($this->executie->cursor[$cheie] ?? null) === 'gata') {
            $this->noteaza('fara_efect', 'pe_bucati', $obiect, 'făcut la o rulare anterioară');
            return;
        }
        if ($this->executie->simulare) {
            $this->noteaza('date', 'pe_bucati', $obiect, 'bucăți de ' . $marime
                . (isset($this->executie->cursor[$cheie]) ? ', reluat după ' . $pk . ' ' . $this->executie->cursor[$cheie] : ''), $tabel);
            return;
        }
        if (!$this->existaTabel($tabel)) {
            throw new Eroare($this->executie->id . ': peBucati: tabelul ' . $tabel . ' nu există', Eroare::MIGRARE);
        }
        $limite = $this->db->query('SELECT MIN(`' . $pk . '`) AS minim, MAX(`' . $pk . '`) AS maxim FROM `' . $tabel . '`')->fetch(PDO::FETCH_ASSOC);
        $maxim = $limite['maxim'] ?? null;
        $de = isset($this->executie->cursor[$cheie]) ? (int) $this->executie->cursor[$cheie]
            : ($limite['minim'] !== null ? (int) $limite['minim'] - 1 : 0);
        while ($maxim !== null && $de < (int) $maxim) {
            ($this->executie->inainte)();
            $pana = min($de + $marime, (int) $maxim);
            $start = microtime(true);
            $schimbate = $this->cuReincercari('pe_bucati', $obiect, function () use ($pas, $de, $pana, $cheie): mixed {
                $this->db->beginTransaction();
                try {
                    $rezultat = $pas($this->db, $de, $pana);
                    if (!$this->db->inTransaction()) {
                        throw new Eroare($this->executie->id . ': pasul lui peBucati a închis tranzacția (DDL în pas?)', Eroare::MIGRARE);
                    }
                    $cursor = $this->executie->cursor;
                    $cursor[$cheie] = $pana;
                    ($this->executie->progres)(is_int($rezultat) && $rezultat === 0 ? 0 : 1, $cursor);
                    $this->comite('bucata');
                    $this->executie->cursor = $cursor;
                    return $rezultat;
                } catch (Throwable $e) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    throw $e;
                }
            });
            $ms = (microtime(true) - $start) * 1000;
            if (!(is_int($schimbate) && $schimbate === 0)) {
                $this->executie->cuEfect++;
                $this->executie->operatii[] = ['clasa' => 'date', 'fel' => 'pe_bucati', 'obiect' => $obiect, 'detaliu' => $de . '–' . $pana];
                ($this->executie->jurnal)('pe_bucati', $obiect . ' ' . $de . '-' . $pana, 'date', $ms);
                ($this->executie->dupa)();
            }
            $de = $pana;
            if ($this->executie->pauzaBucatiMs > 0 && $de < (int) $maxim) {
                usleep($this->executie->pauzaBucatiMs * 1000);
            }
        }
        $cursor = $this->executie->cursor;
        $cursor[$cheie] = 'gata';
        ($this->executie->progres)(0, $cursor);
        $this->executie->cursor = $cursor;
    }

    // ------------------------------------------------------- site, lacăte, note

    /**
     * Un răspuns pe care îl știe doar site-ul, din `migrari.json` din dosarul site-ului
     * (SITE_DATE_DOSAR; în repo: site-uri/<site>/migrari.json). Lipsește → codul 15, înainte
     * să se schimbe ceva (planul o vede deja). Un parametru trecut la `_provizoriu` ({cheie: motiv},
     * un răspuns încă neconfirmat de proprietar) e tot codul 15, afară de --permite-provizoriu pe copia
     * de verificare (SITE_COPIE_VERIFICARE=1; repetiția adoptării).
     */
    public function parametruSite(string $cheie): string
    {
        $fisier = rtrim($this->executie->dosarSite, '/') . '/migrari.json';
        $date = null;
        if (is_file($fisier)) {
            $date = json_decode((string) file_get_contents($fisier), true);
        }
        if (!is_array($date) || !array_key_exists($cheie, $date) || !is_scalar($date[$cheie]) || (string) $date[$cheie] === '') {
            throw new ParametruLipsa($this->executie->id . ': lipsește parametrul site-ului „' . $cheie . '” (migrari.json din dosarul site-ului'
                . (is_file($fisier) ? '' : ', care nu există') . ')');
        }
        $provizorii = is_array($date['_provizoriu'] ?? null) ? $date['_provizoriu'] : [];
        if (array_key_exists($cheie, $provizorii) && !$this->executie->permiteProvizoriu) {
            throw new ParametruLipsa($this->executie->id . ': parametrul site-ului „' . $cheie . '” e provizoriu ('
                . (is_string($provizorii[$cheie]) ? $provizorii[$cheie] : 'neconfirmat') . '); după confirmare se scoate din „_provizoriu” '
                . 'în migrari.json; ' . $this->cumSeRepetaProvizoriu());
        }
        return (string) $date[$cheie];
    }

    /**
     * Lacătul sincronizării nomenclatorului FAN (același nume ca FanNomenclator::NUME_LACAT):
     * cât timp migrarea atinge tabelele FAN, sincronizarea nu le poate înlocui cu copiile ei
     * (și nici invers). Ținut până la finalul migrării. Neobținut în $asteptare secunde → codul 3.
     */
    public function lacatFan(string $lista, int $asteptare = 60): void
    {
        if (!in_array($lista, ['localitati', 'strazi'], true)) {
            throw new Eroare($this->executie->id . ': lacatFan: lista e „localitati” sau „strazi”', Eroare::MIGRARE);
        }
        if ($this->executie->simulare) {
            $this->noteaza('fara_efect', 'lacat_fan', $lista, 'se ia la aplicare');
            return;
        }
        if (in_array($lista, $this->executie->lacateFan, true)) {
            return;
        }
        $stmt = $this->db->prepare("SELECT GET_LOCK(CONCAT('fan_nomenclator:', MD5(DATABASE()), ':', :lista), :asteptare)");
        $stmt->execute(['lista' => $lista, 'asteptare' => max(0, $asteptare)]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new Eroare($this->executie->id . ': sincronizarea FAN („' . $lista . '”) ține tabelele de ' . $asteptare
                . ' s; rulează din nou după ea', Eroare::LACAT);
        }
        $this->executie->lacateFan[] = $lista;
    }

    public function elibereazaLacatele(): void
    {
        foreach ($this->executie->lacateFan as $lista) {
            try {
                $stmt = $this->db->prepare("SELECT RELEASE_LOCK(CONCAT('fan_nomenclator:', MD5(DATABASE()), ':', :lista))");
                $stmt->execute(['lista' => $lista]);
            } catch (Throwable) {
                // Conexiunea închisă eliberează oricum lacătul.
            }
        }
        $this->executie->lacateFan = [];
    }

    /**
     * Un avertisment al migrării, scris în plan și la aplicare. Doar structură și numere:
     * niciodată valori din rânduri.
     */
    public function avertisment(string $text): void
    {
        $this->executie->avertismente[] = $this->executie->id . ': ' . $text;
    }

    // ---------------------------------------------------------------- interne

    /** @return array{tip: string, null: bool, implicit: ?string, extra: string, primar: bool, unic: bool, pozitie: bool, necunoscute: list<string>} */
    private function definitie(string $tabel, string $coloana, string $definitie): array
    {
        try {
            $ceruta = Amprenta::coloanaDinDefinitie($definitie);
        } catch (Throwable $e) {
            throw new Eroare($this->executie->id . ': ' . $tabel . '.' . $coloana . ': ' . $e->getMessage(), Eroare::MIGRARE);
        }
        $probleme = [];
        if ($ceruta['pozitie']) {
            $probleme[] = 'fără AFTER/FIRST (o coloană nouă stă la coadă; poziția nu contează)';
        }
        if ($ceruta['primar'] || $ceruta['unic']) {
            $probleme[] = 'cheile se fac cu index()/unic(), nu în definiția coloanei';
        }
        if ($ceruta['necunoscute'] !== []) {
            $probleme[] = 'nu înțeleg: ' . implode(' ', $ceruta['necunoscute']) . self::faraColatie($ceruta['necunoscute']);
        }
        if ($probleme !== []) {
            throw new Eroare($this->executie->id . ': ' . $tabel . '.' . $coloana . ': ' . implode('; ', $probleme), Eroare::MIGRARE);
        }
        return $ceruta;
    }

    /** Lămurirea pentru un CHARACTER SET / COLLATE scris într-o definiție de coloană. @param list<string> $necunoscute */
    private static function faraColatie(array $necunoscute): string
    {
        foreach ($necunoscute as $text) {
            if (preg_match('/\b(character\s+set|charset|collate)\b/i', $text) === 1) {
                return ' (o coloană ia colația tabelului, utf8mb4_unicode_ci: fără CHARACTER SET/COLLATE în definiție)';
            }
        }
        return '';
    }

    private function comparaDefinitia(string $tabel, string $coloana, array $existenta, array $ceruta): void
    {
        $diferente = Amprenta::diferenteColoana($existenta, $ceruta);
        if ($diferente !== []) {
            $this->avertisment('coloana ' . $tabel . '.' . $coloana . ' există cu altă definiție (' . implode(', ', $diferente) . '): '
                . Amprenta::descrieColoana($existenta) . '; migrarea cere ' . Amprenta::descrieColoana(self::doarColoana($ceruta)) . '. Nu se schimbă.');
        }
    }

    /**
     * Tabelul abia creat de tabelComplet e exact cel cerut: fiecare coloană, cu definiția ei
     * (coloanele cheii primare doar să existe, ca pe un tabel existent), cheia primară, fiecare
     * index (sub același nume, echivalent; altfel unul echivalent sub alt nume) și fiecare cheie
     * străină (același nume, sau aceleași coloane spre același tabel). Altfel tabelul l-a făcut
     * altcineva chiar atunci, iar tabelComplet îl aduce la zi ca pe unul existent.
     *
     * @param array<string, mixed> $creat
     * @param array<string, array<string, mixed>> $cerute
     * @param list<string>|null $primara
     * @param array<string, array<string, mixed>> $indecsi
     * @param array<string, array<string, mixed>> $straine
     */
    private static function eCaCerut(array $creat, array $cerute, ?array $primara, array $indecsi, array $straine): bool
    {
        foreach ($cerute as $coloana => $ceruta) {
            $existenta = $creat['coloane'][$coloana] ?? null;
            if ($existenta === null) {
                return false;
            }
            if (($primara === null || !in_array((string) $coloana, $primara, true)) && Amprenta::diferenteColoana($existenta, $ceruta) !== []) {
                return false;
            }
        }
        if ($primara !== null && !Amprenta::indecsiEchivalenti($creat['indecsi']['PRIMARY'] ?? ['unic' => false, 'coloane' => []], Amprenta::index(true, $primara))) {
            return false;
        }
        foreach ($indecsi as $nume => $index) {
            if (isset($creat['indecsi'][$nume])) {
                $gasit = Amprenta::indecsiEchivalenti($creat['indecsi'][$nume], $index);
            } else {
                $gasit = false;
                foreach ($creat['indecsi'] as $existent) {
                    $gasit = $gasit || Amprenta::indecsiEchivalenti($existent, $index);
                }
            }
            if (!$gasit) {
                return false;
            }
        }
        foreach ($straine as $nume => $fk) {
            $gasita = isset($creat['fk'][$nume]);
            foreach ($creat['fk'] as $alta) {
                $gasita = $gasita || ($alta['coloane'] === $fk['coloane'] && $alta['tabel'] === $fk['tabel']);
            }
            if (!$gasita) {
                return false;
            }
        }
        return true;
    }

    /** @return array{tip: string, null: bool, implicit: ?string, extra: string} */
    private static function doarColoana(array $c): array
    {
        return ['tip' => $c['tip'], 'null' => $c['null'], 'implicit' => $c['implicit'], 'extra' => $c['extra']];
    }

    private function doarContract(string $metoda): void
    {
        if ($this->executie->tip !== 'contract') {
            throw new Eroare($this->executie->id . ': ' . $metoda . ' doar într-o migrare de tip „contract” (ștergerile vin la un release după ce niciun cod nu mai folosește obiectul)', Eroare::MIGRARE);
        }
    }

    private function numeValid(string $nume): void
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $nume) !== 1) {
            throw new Eroare($this->executie->id . ': „' . $nume . '” nu e un nume de tabel/coloană/index (litere, cifre, _)', Eroare::MIGRARE);
        }
    }

    /** Structura tabelului (din model în simulare, altfel din bază), sau null dacă nu există. */
    private function structura(string $tabel): ?array
    {
        if ($this->executie->simulare && $this->executie->model !== null && $this->executie->model->offsetExists($tabel)) {
            return $this->executie->model[$tabel];
        }
        return $this->dinBaza($tabel);
    }

    private function dinBaza(string $tabel): ?array
    {
        if (!array_key_exists($tabel, $this->cache)) {
            $this->cache[$tabel] = Amprenta::citeste($this->db, $this->server, [$tabel])['tabele'][$tabel] ?? null;
        }
        return $this->cache[$tabel];
    }

    private function existaInBaza(string $tabel): bool
    {
        return $this->dinBaza($tabel) !== null;
    }

    private function structuraObligatorie(string $tabel, string $metoda): array
    {
        $structura = $this->structura($tabel);
        if ($structura === null) {
            throw new Eroare($this->executie->id . ': ' . $metoda . ': tabelul ' . $tabel . ' nu există (îl creează o migrare de dinainte?)', Eroare::MIGRARE);
        }
        return $structura;
    }

    /** În simulare: schimbă structura din model (copiată din bază la prima atingere). */
    private function modifica(string $tabel, callable $schimbare): void
    {
        $structura = $this->structura($tabel);
        if ($structura !== null) {
            $this->executie->model[$tabel] = $schimbare($structura);
        }
    }

    private function areRanduri(string $tabel): bool
    {
        if (!$this->existaInBaza($tabel)) {
            return false;
        }
        return $this->db->query('SELECT 1 FROM `' . $tabel . '` LIMIT 1')->fetchColumn() !== false;
    }

    /**
     * Cât de mare e un tabel din bază, după estimarea serverului (information_schema.TABLES:
     * TABLE_ROWS, DATA_LENGTH + INDEX_LENGTH), pentru plan: un ALTER care copiază tabelul ține
     * cât copierea acestor octeți (regula „sub 60 s” a migrărilor blocante, docs/MIGRARI.md).
     * Null pentru un tabel care nu e (încă) în bază.
     *
     * @return array{randuri: int, octeti: int}|null
     */
    private function marime(string $tabel): ?array
    {
        if (!array_key_exists($tabel, $this->marimi)) {
            $stmt = $this->db->prepare('SELECT TABLE_ROWS, COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0)
                FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$tabel]);
            $rand = $stmt->fetch(PDO::FETCH_NUM);
            $this->marimi[$tabel] = $rand === false ? null : ['randuri' => (int) $rand[0], 'octeti' => (int) $rand[1]];
        }
        return $this->marimi[$tabel];
    }

    private static function octeti(int $octeti): string
    {
        return $octeti >= 1048576 ? number_format($octeti / 1048576, 1, ',', '') . ' MB' : (int) round($octeti / 1024) . ' KB';
    }

    /**
     * Câte valori ar apărea de mai multe ori sub un index unic nou (doar numărul). În simulare,
     * o coloană pe care o adaugă abia planul are, pe rândurile existente, aceeași valoare
     * implicită: nu schimbă nimic la numărătoare, iar dacă e NULL, nimic nu se poate repeta.
     *
     * @param list<string> $coloane
     */
    private function valoriRepetate(string $tabel, array $coloane): int
    {
        $real = $this->dinBaza($tabel);
        if ($real === null) {
            return 0;
        }
        $planificat = $this->structura($tabel) ?? $real;
        $expresii = [];
        $conditii = [];
        foreach ($coloane as $coloana) {
            $nume = preg_replace('/\(\d+\)$/', '', $coloana) ?? $coloana;
            if (!isset($real['coloane'][$nume])) {
                $viitoare = $planificat['coloane'][$nume] ?? null;
                if ($viitoare === null || ($viitoare['null'] && $viitoare['implicit'] === null)) {
                    return 0;
                }
                continue;
            }
            if (preg_match('/^([A-Za-z0-9_]+)\((\d+)\)$/', $coloana, $m) === 1) {
                $expresii[] = 'LEFT(`' . $m[1] . '`, ' . (int) $m[2] . ')';
            } else {
                $expresii[] = '`' . $nume . '`';
            }
            $conditii[] = '`' . $nume . '` IS NOT NULL';
        }
        if ($expresii === []) {
            return $this->db->query('SELECT COUNT(*) FROM `' . $tabel . '`')->fetchColumn() > 1 ? 1 : 0;
        }
        // NULL nu se repetă într-un index unic; restul se compară cu colația coloanei, ca serverul.
        $sql = 'SELECT COUNT(*) FROM (SELECT 1 FROM `' . $tabel . '` WHERE ' . implode(' AND ', $conditii)
            . ' GROUP BY ' . implode(', ', $expresii) . ' HAVING COUNT(*) > 1) repetate';
        return (int) $this->db->query($sql)->fetchColumn();
    }

    /**
     * O operație a planului (sau una făcută). Cu $tabel (în simulare, pentru ALTER-uri și bucăți
     * pe un tabel care există deja în bază): și mărimea lui, în text și ca numere (randuri,
     * octeti) în --json.
     */
    private function noteaza(string $clasa, string $fel, string $obiect, string $detaliu = '', ?string $tabel = null): void
    {
        $operatie = ['clasa' => $clasa, 'fel' => $fel, 'obiect' => $obiect, 'detaliu' => $detaliu];
        $marime = $tabel !== null && $this->executie->simulare ? $this->marime($tabel) : null;
        if ($marime !== null) {
            $operatie['detaliu'] .= ($detaliu !== '' ? '; ' : '') . '~' . $marime['randuri'] . ' rânduri, ' . self::octeti($marime['octeti']);
            $operatie += $marime;
        }
        $this->executie->operatii[] = $operatie;
    }

    /**
     * Commit-ul unei instrucțiuni de date sau al unei bucăți, cu numărătoarea (și cursorul) ei
     * deja scrise în aceeași tranzacție. Cele două puncte sunt pentru teste
     * (MIGRARI_TEST_OPRESTE_IN): oprire ca un proces omorât chiar înainte de commit (totul se
     * anulează) și chiar după el (totul a rămas); în ambele cazuri rularea următoare trebuie
     * să numere fiecare rând o singură dată.
     */
    private function comite(string $fel): void
    {
        $this->punctTest($fel . '_in_tranzactie');
        $this->db->commit();
        $this->punctTest($fel . '_dupa_commit');
    }

    private function punctTest(string $punct): void
    {
        if ($this->executie->punctTest !== null) {
            ($this->executie->punctTest)($punct);
        }
    }

    private function cuEfect(string $fel, string $obiect, string $clasa, float $ms, bool $numara = true): void
    {
        if ($numara) {
            ($this->executie->progres)(1, null);
        }
        $this->executie->cuEfect++;
        $this->noteaza($clasa, $fel, $obiect);
        ($this->executie->jurnal)($fel, $obiect, $clasa, $ms);
        ($this->executie->dupa)();
    }

    /** O instrucțiune DDL fără lanț de clauze (DROP TABLE; CREATE TABLE își are pașii în tabel(), cu nota 1050). */
    private function executa(string $fel, string $tabel, string $obiect, string $clasa, string $sql): void
    {
        ($this->executie->inainte)();
        $start = microtime(true);
        $this->cuReincercari($fel, $obiect, fn (): int => (int) $this->db->exec($sql));
        unset($this->cache[$tabel]);
        $this->cuEfect($fel, $obiect, $clasa, (microtime(true) - $start) * 1000);
    }

    /**
     * Un ALTER cu lanțul de clauze al serverului. 1845/1846: clauza nu se poate, se încearcă
     * următoarea; lanțul epuizat fără voie de blocare → codul 11, cu tabelul neatins. O cursă
     * (obiectul a apărut sau a dispărut între inspectare și ALTER) se acceptă dacă baza e acum
     * cum o vrea migrarea.
     *
     * @param callable(): bool $stareDorita
     */
    private function alter(string $fel, string $tabel, string $obiect, string $sql, callable $stareDorita): void
    {
        ($this->executie->inainte)();
        $refuzuri = [];
        foreach ($this->server->lantClauze($fel, $this->executie->poateBloca) as $clauza) {
            $start = microtime(true);
            try {
                $this->cuReincercari($fel, $obiect, fn (): int => (int) $this->db->exec($clauza === '' ? $sql : $sql . ', ' . $clauza));
            } catch (PDOException $e) {
                $cod = self::cod($e);
                if (in_array($cod, self::NU_POATE, true)) {
                    $refuzuri[] = ($clauza === '' ? 'fără clauză' : $clauza) . ': ' . $cod;
                    continue;
                }
                unset($this->cache[$tabel]);
                if (in_array($cod, self::CURSE, true) && $stareDorita()) {
                    $this->noteaza('fara_efect', $fel, $obiect, 'făcut între timp de altcineva (' . $cod . ')');
                    return;
                }
                throw $this->eroare($fel, $obiect, $e);
            }
            unset($this->cache[$tabel]);
            $clasa = $clauza === '' ? 'copie' : (str_contains($clauza, 'INSTANT') ? 'instant' : 'inplace');
            $this->cuEfect($fel, $obiect, $clasa, (microtime(true) - $start) * 1000);
            return;
        }
        throw new Eroare($this->executie->id . ': ' . $obiect . ': serverul (' . $this->server->nume() . ') nu poate face '
            . $fel . ' fără să blocheze tabelul (' . implode('; ', $refuzuri) . '). Tabelul e neatins. Se aplică doar cu '
            . '--permite-blocante, cu site-ul în MENTENANTA (docs/MIGRARI.md)', Eroare::NEPERMISA);
    }

    /**
     * Reîncearcă la 1205 (lacăt așteptat prea mult: de exemplu un export lung ține tabelul)
     * și 1213 (deadlock), după pauzele din Executie; apoi codul 1, cu migrarea lăsată „in_curs”
     * (rularea din nou e sigură).
     *
     * @template T
     * @param callable(): T $operatie
     * @return T
     */
    private function cuReincercari(string $fel, string $obiect, callable $operatie): mixed
    {
        $pauze = $this->executie->pauze;
        for ($incercare = 0; ; $incercare++) {
            try {
                return $operatie();
            } catch (PDOException $e) {
                if (!in_array(self::cod($e), self::REINCERCARI, true)) {
                    if ($fel === 'date' || $fel === 'setare' || $fel === 'pe_bucati' || $fel === 'tabel' || $fel === 'sterge_tabel') {
                        throw $this->eroare($fel, $obiect, $e);
                    }
                    throw $e;
                }
                if (!isset($pauze[$incercare])) {
                    throw new Eroare($this->executie->id . ': ' . $obiect . ': tabelul a rămas ocupat după ' . ($incercare + 1)
                        . ' încercări (' . self::cod($e) . '); migrarea rămâne „in_curs”, rulează din nou mai târziu', Eroare::MIGRARE);
                }
                ($this->executie->jurnal)($fel, $obiect . ' (ocupat, reîncerc în ' . $pauze[$incercare] . ' s)', 'asteptare', 0.0);
                usleep((int) round($pauze[$incercare] * 1_000_000));
            }
        }
    }

    private function eroare(string $fel, string $obiect, PDOException $e): Eroare
    {
        $stare = (string) ($e->errorInfo[0] ?? '');
        return new Eroare($this->executie->id . ': ' . $fel . ($obiect !== '' ? ' ' . $obiect : '') . ': eroare SQL '
            . self::cod($e) . (preg_match('/^[0-9A-Z]{5}$/', $stare) === 1 ? ' (' . $stare . ')' : '') . ': '
            . self::faraValori((string) ($e->errorInfo[2] ?? $e->getMessage())), Eroare::MIGRARE);
    }

    public static function cod(PDOException $e): int
    {
        return (int) ($e->errorInfo[1] ?? 0);
    }

    /**
     * Un mesaj al serverului (sau al unei PDOException, cu „SQLSTATE[…]: …: <errno>” în față)
     * fără valorile din rânduri, doar cu numele obiectelor: „Duplicate entry 'x@y.ro' for key
     * 'uniq_email'” → „Duplicate entry '…' for key 'uniq_email'”. Contextul (migrarea, tabelul,
     * coloana) îl pune runner-ul.
     *
     * MariaDB și MySQL nu dublează ghilimelele unei valori în mesaj („Duplicate entry 'o'brien'
     * …”), deci perechile de ghilimele nu spun unde se termină valoarea. De aceea:
     *  1. formele cu o valoare (1062, 1366/1292, „near … at line”): valoarea e tot ce stă între
     *     începutul și sfârșitul fixe ale mesajului, oricât de ciudată ar fi (ghilimele, \, chiar
     *     „' for key '” în ea);
     *  2. un mesaj fără nicio ghilimea rămâne cum e (cele cu `nume` între apostroafe inverse nu
     *     au valori);
     *  3. formele cunoscute care au doar nume (1050, 1054, 1060, 1061, 1091, 1048, 1364, 1406 …)
     *     rămân cum sunt;
     *  4. orice altă formă: tot ce e între prima și ultima ghilimea devine '…'.
     * Fără /u: un mesaj cu octeți care nu sunt UTF-8 se ascunde la fel (nu scapă întreg).
     */
    public static function faraValori(string $mesaj): string
    {
        $cap = '';
        if (preg_match('/^(SQLSTATE\[[0-9A-Z]{5}\]: [^:\'"]*: (?:\d+ )?)(.*)$/s', $mesaj, $m) === 1) {
            $cap = $m[1];
            $mesaj = $m[2];
        }
        return $cap . self::mesajFaraValori($mesaj);
    }

    private static function mesajFaraValori(string $mesaj): string
    {
        $nume = '\'[A-Za-z0-9_$#.\-]*\'';
        $intreg = '`[A-Za-z0-9_$#\-]*`';
        $coloana = '(?:' . $nume . '|' . $intreg . '(?:\.' . $intreg . ')*)';
        $cuValoare = [
            '/^(Duplicate entry )\'.*\'( for key ' . $nume . ')$/s',
            '/^((?:Incorrect|Truncated incorrect) [A-Za-z0-9_ ]+ value: )\'.*\'((?: for column ' . $coloana . ' at row \d+)?)$/s',
            '/^([^\'"]* near )\'.*\'( at line \d+)$/s',
        ];
        foreach ($cuValoare as $forma) {
            if (preg_match($forma, $mesaj, $m) === 1) {
                return $m[1] . "'…'" . $m[2];
            }
        }
        if (strpbrk($mesaj, '\'"') === false) {
            return $mesaj;
        }
        $doarNume = [
            '/^Table ' . $nume . ' (?:already exists|doesn\'t exist)$/',
            '/^Unknown column ' . $nume . ' in \'[A-Za-z ]+\'$/',
            '/^Duplicate (?:column|key) name ' . $nume . '$/',
            '/^Can\'t DROP (?:(?:COLUMN|INDEX|CONSTRAINT|FOREIGN KEY|CHECK) )?(?:' . $nume . '|' . $intreg . '); check that (?:it|column\/key) exists$/',
            '/^Column ' . $coloana . ' cannot be null$/',
            '/^Field ' . $nume . ' doesn\'t have a default value$/',
            '/^(?:Data too long|Data truncated|Out of range value) for column ' . $coloana . ' at row \d+$/',
            '/^Key column ' . $nume . ' doesn\'t exist in table$/',
            '/^Invalid default value for ' . $nume . '$/',
            '/^BLOB\/TEXT column ' . $nume . ' used in key specification without a key length$/',
            '/^(?:BLOB|BLOB, TEXT, GEOMETRY or JSON|JSON) column ' . $nume . ' can\'t have a default value$/',
            '/^Can\'t create table ' . $intreg . '\.' . $intreg . ' \(errno: \d+ "[A-Za-z ]+"\)$/',
            '/^Failed to open the referenced table ' . $nume . '$/',
            '/^Referencing column ' . $nume . ' and referenced column ' . $nume . ' in foreign key constraint ' . $nume . ' are incompatible\.$/',
            '/^Check constraint ' . $nume . ' is violated\.$/',
            '/^Invalid JSON text(?: in argument \d+ to function [a-z_.]+)?: "[^"]*" at position \d+ in value for column ' . $nume . '\.$/',
        ];
        foreach ($doarNume as $forma) {
            if (preg_match($forma, $mesaj) === 1) {
                return $mesaj;
            }
        }
        $prima = strcspn($mesaj, '\'"');
        $ultima = max((int) strrpos($mesaj, "'"), (int) strrpos($mesaj, '"'));
        return substr($mesaj, 0, $prima) . "'…'" . substr($mesaj, $ultima + 1);
    }
}
