<?php

declare(strict_types=1);

/**
 * Ce au în comun scripturile din deploy/ (release.php, adopta.php, actualizeaza.php, revino.php; docs/DEPLOY.md):
 * ieșirea pe ecran și în jurnal, opțiunile, comenzile pornite, fișierele, baza de date (copia și restaurarea), cererile
 * HTTP de verificare și locurile unui site în așezarea din Etapa 2.
 *
 * Nu încarcă nimic din aplicație: scripturile lucrează pe site-uri, iar codul unui site îl rulează doar prin
 * bin/platforma al versiunii lui, ca un proces separat.
 */

namespace Platforma\Deploy;

use PDO;
use RuntimeException;
use Throwable;

/** O oprire cu mesaj pentru om și cod de ieșire (1 = o verificare sau o fază a picat, 2 = folosire, 3 = alt deploy rulează). */
final class Oprire extends RuntimeException
{
    /** @param list<string> $pasi ce poate face omul mai departe */
    public function __construct(string $mesaj, int $cod = 1, public readonly array $pasi = [])
    {
        parent::__construct($mesaj, $cod);
    }
}

final class Iesire
{
    private static ?string $jurnal = null;
    /** @var list<string> rândurile de dinaintea jurnalului (scrise în el când se deschide) */
    private static array $tampon = [];

    public static function jurnal(string $fisier): void
    {
        @mkdir(dirname($fisier), 0700, true);
        self::$jurnal = $fisier;
        @file_put_contents($fisier, implode('', self::$tampon), FILE_APPEND);
        @chmod($fisier, 0600);
        self::$tampon = [];
    }

    public static function fisierJurnal(): ?string
    {
        return self::$jurnal;
    }

    public static function scrie(string $linie = ''): void
    {
        echo $linie, "\n";
        self::inJurnal($linie);
    }

    /** Doar în jurnal (de exemplu ieșirea lungă a unei comenzi, deja afișată). */
    public static function inJurnal(string $text): void
    {
        $text = rtrim($text, "\n") . "\n";
        if (self::$jurnal !== null) {
            @file_put_contents(self::$jurnal, $text, FILE_APPEND);
        } else {
            self::$tampon[] = $text;
        }
    }

    public static function titlu(string $text): void
    {
        self::scrie('');
        self::scrie('== ' . $text . ' ' . str_repeat('=', max(3, 70 - mb_strlen($text))));
    }

    public static function ok(string $text): void
    {
        self::scrie('  OK       ' . $text);
    }

    public static function info(string $text): void
    {
        self::scrie('  ..       ' . $text);
    }

    public static function atentie(string $text): void
    {
        self::scrie('  ATENȚIE  ' . $text);
    }

    public static function nu(string $text): void
    {
        self::scrie('  NU       ' . $text);
    }

    public static function oprire(Oprire $o): int
    {
        self::scrie('');
        self::scrie('OPRIT: ' . $o->getMessage());
        foreach ($o->pasi as $pas) {
            self::scrie('  -> ' . $pas);
        }
        if (self::$jurnal !== null) {
            self::scrie('Jurnalul: ' . self::$jurnal);
        }
        return $o->getCode() > 0 ? $o->getCode() : 1;
    }
}

/** Opțiunile de forma --nume=valoare și --nume. */
final class Optiuni
{
    /** @param array<string, string|true> $valori */
    private function __construct(private array $valori)
    {
    }

    /**
     * @param list<string> $argv
     * @param list<string> $permise numele permise; cu „=” la sfârșit cer valoare („site=”), fără, sunt comutatoare
     */
    public static function citeste(array $argv, array $permise): self
    {
        $valori = [];
        foreach (array_slice($argv, 1) as $argument) {
            if (preg_match('/^--([a-z][a-z0-9-]*)(?:=(.*))?$/s', $argument, $m) !== 1) {
                throw new Oprire('nu înțeleg argumentul „' . $argument . '”', 2);
            }
            $cuValoare = array_key_exists(2, $m);
            if (!in_array($m[1] . ($cuValoare ? '=' : ''), $permise, true)) {
                throw new Oprire('opțiune necunoscută: --' . $m[1] . (in_array($m[1] . '=', $permise, true) ? ' (cere o valoare: --' . $m[1] . '=…)' : ''), 2);
            }
            if ($cuValoare && $m[2] === '') {
                throw new Oprire('--' . $m[1] . '= e gol', 2);
            }
            $valori[$m[1]] = $cuValoare ? $m[2] : true;
        }
        return new self($valori);
    }

    public function are(string $nume): bool
    {
        return isset($this->valori[$nume]);
    }

    public function text(string $nume, ?string $implicit = null): ?string
    {
        $v = $this->valori[$nume] ?? null;
        return is_string($v) ? $v : $implicit;
    }

    public function cere(string $nume, string $despre): string
    {
        $v = $this->text($nume);
        if ($v === null) {
            throw new Oprire('lipsește --' . $nume . '=' . $despre, 2);
        }
        return $v;
    }

    /** --plan (implicit) sau --aplica; amândouă e o greșeală. */
    public function aplica(): bool
    {
        if ($this->are('plan') && $this->are('aplica')) {
            throw new Oprire('alege --plan sau --aplica, nu amândouă', 2);
        }
        return $this->are('aplica');
    }
}

/** Comenzile pornite: mereu ca listă de argumente (fără shell), afară de conductele scrise explicit în `shell()`. */
final class Comanda
{
    /**
     * @param list<string> $comanda
     * @param array{cwd?: string, env?: array<string, string>, afiseaza?: bool, intrare?: string} $o
     * @return array{cod: int, iesire: string, erori: string}
     */
    public static function ruleaza(array $comanda, array $o = []): array
    {
        $env = isset($o['env']) ? $o['env'] + self::mediu() : null;
        $descriptori = [0 => isset($o['intrare']) ? ['file', $o['intrare'], 'r'] : ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proces = @proc_open($comanda, $descriptori, $tevi, $o['cwd'] ?? null, $env);
        if (!is_resource($proces)) {
            return ['cod' => 127, 'iesire' => '', 'erori' => 'nu pot porni ' . $comanda[0]];
        }
        $iesire = '';
        $erori = '';
        $deschise = [1 => $tevi[1], 2 => $tevi[2]];
        foreach ($deschise as $t) {
            stream_set_blocking($t, false);
        }
        $rest = ['1' => '', '2' => ''];
        while ($deschise !== []) {
            $citite = array_values($deschise);
            $w = null;
            $e = null;
            if (@stream_select($citite, $w, $e, 1) === false) {
                break;
            }
            foreach ($citite as $t) {
                $i = array_search($t, $deschise, true);
                $bucata = (string) fread($t, 65536);
                if ($bucata === '' && feof($t)) {
                    fclose($t);
                    unset($deschise[$i]);
                    continue;
                }
                if ($i === 1) {
                    $iesire .= $bucata;
                } else {
                    $erori .= $bucata;
                }
                if (!empty($o['afiseaza'])) {
                    $rest[(string) $i] .= $bucata;
                    while (($p = strpos($rest[(string) $i], "\n")) !== false) {
                        Iesire::scrie('     | ' . substr($rest[(string) $i], 0, $p));
                        $rest[(string) $i] = substr($rest[(string) $i], $p + 1);
                    }
                }
            }
        }
        foreach ($rest as $r) {
            if ($r !== '' && !empty($o['afiseaza'])) {
                Iesire::scrie('     | ' . $r);
            }
        }
        $cod = proc_close($proces);
        if (empty($o['afiseaza']) && ($iesire !== '' || $erori !== '')) {
            Iesire::inJurnal('     $ ' . implode(' ', array_map('escapeshellarg', $comanda)) . "\n" . self::indenteaza($iesire . $erori));
        }
        return ['cod' => $cod, 'iesire' => $iesire, 'erori' => $erori];
    }

    /**
     * O conductă scrisă explicit (copia bazei: mysqldump | gzip > fișier), cu bash -o pipefail. Fiecare bucată
     * venită din afară trece prin escapeshellarg la cel care o cheamă.
     *
     * @return array{cod: int, iesire: string, erori: string}
     */
    public static function shell(string $comanda, array $o = []): array
    {
        return self::ruleaza(['bash', '-o', 'pipefail', '-c', $comanda], $o);
    }

    /** Mediul procesului de acum, ca listă (proc_open cu env cere tot mediul). @return array<string, string> */
    public static function mediu(): array
    {
        $mediu = [];
        foreach (getenv() as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $mediu[$k] = $v;
            }
        }
        return $mediu;
    }

    public static function exista(string $program): bool
    {
        return self::ruleaza(['bash', '-c', 'command -v ' . escapeshellarg($program)])['cod'] === 0;
    }

    /** Primul program care există dintre cele date, sau null. */
    public static function primul(string ...$programe): ?string
    {
        foreach ($programe as $p) {
            if (self::exista($p)) {
                return $p;
            }
        }
        return null;
    }

    /** PHP-ul cu care rulează scripturile pornite (PLATFORMA_PHP din deploy/lib/php.sh), ca listă. @return list<string> */
    public static function php(): array
    {
        $php = trim((string) getenv('PLATFORMA_PHP'));
        $parti = $php !== '' ? preg_split('/\s+/', $php) : false;
        return is_array($parti) && $parti !== [] ? array_values($parti) : [PHP_BINARY];
    }

    private static function indenteaza(string $text): string
    {
        $text = rtrim($text);
        return $text === '' ? '' : '     | ' . str_replace("\n", "\n     | ", $text) . "\n";
    }
}

final class Fisiere
{
    /** Scrie un fișier dintr-o bucată (fișier temporar, apoi rename), cu drepturile date. */
    public static function scrie(string $fisier, string $continut, int $drepturi = 0640): void
    {
        $temp = $fisier . '.tmp-' . getmypid();
        if (@file_put_contents($temp, $continut) === false) {
            throw new Oprire('nu pot scrie ' . $temp);
        }
        @chmod($temp, $drepturi);
        if (!@rename($temp, $fisier)) {
            @unlink($temp);
            throw new Oprire('nu pot redenumi ' . $temp . ' în ' . $fisier);
        }
    }

    public static function dosar(string $dosar, int $drepturi = 0750): void
    {
        if (!is_dir($dosar) && !@mkdir($dosar, $drepturi, true) && !is_dir($dosar)) {
            throw new Oprire('nu pot face dosarul ' . $dosar);
        }
    }

    /** cp -a (păstrează drepturile, datele și legăturile). */
    public static function copiaza(string $din, string $in): void
    {
        $r = Comanda::ruleaza(['cp', '-a', $din, $in]);
        if ($r['cod'] !== 0) {
            throw new Oprire('copierea ' . $din . ' → ' . $in . ' n-a mers: ' . trim($r['erori']));
        }
    }

    /** Conținutul unui dosar copiat într-altul (care poate exista deja), fără să suprascrie ce e acolo dacă $peste e false. */
    public static function copiazaContinut(string $din, string $in, bool $peste = true): void
    {
        self::dosar($in);
        $r = Comanda::ruleaza(array_merge(['cp', '-a'], $peste ? [] : ['-n'], [rtrim($din, '/') . '/.', rtrim($in, '/') . '/']));
        if ($r['cod'] !== 0) {
            throw new Oprire('copierea ' . $din . ' → ' . $in . ' n-a mers: ' . trim($r['erori']));
        }
    }

    public static function muta(string $din, string $in): void
    {
        if (file_exists($in) || is_link($in)) {
            throw new Oprire('nu mut ' . $din . ': ' . $in . ' există deja');
        }
        if (!@rename($din, $in)) {
            throw new Oprire('nu pot muta ' . $din . ' în ' . $in);
        }
        clearstatcache();
    }

    /**
     * Șterge un dosar, dar doar sub unul dintre dosarele date (o plasă de siguranță: scripturile nu șterg niciodată
     * altceva decât ce au făcut ele).
     *
     * @param list<string> $permise
     */
    public static function sterge(string $cale, array $permise): void
    {
        if (!file_exists($cale) && !is_link($cale)) {
            return;
        }
        $bun = false;
        foreach ($permise as $p) {
            if ($p !== '' && str_starts_with($cale, rtrim($p, '/') . '/') && !str_contains(substr($cale, strlen($p)), '/../')) {
                $bun = true;
            }
        }
        if (!$bun) {
            throw new Oprire('refuz să șterg ' . $cale . ' (nu e un dosar al deploy-ului)');
        }
        // Doar dosarele devin scriibile (ca rm să poată șterge din ele), niciodată fișierele: o copie a unei versiuni
        // făcută cu legături fixe (site/releases/<v>) împarte fișierele cu versiunea comună, care rămân doar de citit.
        if (is_dir($cale) && !is_link($cale)) {
            Comanda::ruleaza(['find', $cale, '-type', 'd', '-exec', 'chmod', 'u+w', '{}', '+']);
        }
        $r = Comanda::ruleaza(['rm', '-rf', '--', $cale]);
        clearstatcache();
        if ($r['cod'] !== 0 || file_exists($cale)) {
            throw new Oprire('nu pot șterge ' . $cale . ': ' . trim($r['erori']));
        }
    }

    /**
     * Fișierele unui dosar (căi relative, sortate). Dosarele care încep cu unul dintre prefixele din $sari nu se
     * parcurg.
     *
     * @param list<string> $sari
     * @return list<string>
     */
    public static function lista(string $dosar, array $sari = []): array
    {
        $rez = [];
        if (!is_dir($dosar)) {
            return $rez;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($dosar, \FilesystemIterator::SKIP_DOTS),
            static function (\SplFileInfo $f) use ($dosar, $sari): bool {
                $rel = substr($f->getPathname(), strlen($dosar) + 1);
                foreach ($sari as $s) {
                    if ($rel === rtrim($s, '/') || str_starts_with($rel, rtrim($s, '/') . '/')) {
                        return false;
                    }
                }
                return true;
            }
        ));
        foreach ($it as $f) {
            $rez[] = substr($f->getPathname(), strlen($dosar) + 1);
        }
        sort($rez, SORT_STRING);
        return $rez;
    }

    /**
     * Amprenta unui dosar, pentru „pus la loc exact”: fiecare intrare (fișier, dosar, legătură), câte un rând
     * „<cale>\t<fel> <drepturi> …”, cu mărimea, data și (pentru fișiere în afara $doarMarime) sha256-ul. Sortată.
     *
     * @param list<string> $doarMarime prefixe (de exemplu „public/uploads”) unde ajung mărimea și data: pozele pot fi mulți GB
     */
    public static function amprenta(string $dosar, array $doarMarime = []): string
    {
        $randuri = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dosar, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $rel = substr($f->getPathname(), strlen($dosar) + 1);
            $mod = sprintf('%o', fileperms($f->getPathname()) & 07777);
            if ($f->isLink()) {
                $randuri[] = $rel . "\tl " . $mod . ' ' . readlink($f->getPathname());
            } elseif ($f->isDir()) {
                $randuri[] = $rel . "\td " . $mod;
            } else {
                $mare = false;
                foreach ($doarMarime as $p) {
                    $mare = $mare || str_starts_with($rel, rtrim($p, '/') . '/');
                }
                $randuri[] = $rel . "\tf " . $mod . ' ' . $f->getSize() . ' ' . $f->getMTime() . ' '
                    . ($mare ? '-' : (string) hash_file('sha256', $f->getPathname()));
            }
        }
        sort($randuri, SORT_STRING);
        return implode("\n", $randuri) . "\n";
    }

    /**
     * Ce diferă între două amprente: [schimbate în afara $separat, schimbate sub $separat] (căile, sortate). Sub $separat
     * (pozele) o diferență e de obicei o poză încărcată între timp, deci se spune separat.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function diferente(string $atunci, string $acum, string $separat = ''): array
    {
        $citeste = static function (string $text): array {
            $rez = [];
            foreach (explode("\n", trim($text)) as $l) {
                if ($l !== '') {
                    [$cale, $info] = explode("\t", $l, 2) + [1 => ''];
                    $rez[$cale] = $info;
                }
            }
            return $rez;
        };
        $a = $citeste($atunci);
        $b = $citeste($acum);
        $rez = [[], []];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $cale) {
            if (($a[$cale] ?? null) !== ($b[$cale] ?? null)) {
                $rez[$separat !== '' && ($cale === $separat || str_starts_with($cale, $separat . '/')) ? 1 : 0][] = $cale;
            }
        }
        sort($rez[0]);
        sort($rez[1]);
        return $rez;
    }

    /** Mărimea unui dosar, în octeți (du -sb), sau 0. */
    public static function marime(string $dosar, array $exclude = []): int
    {
        $comanda = ['du', '-sb'];
        foreach ($exclude as $e) {
            $comanda[] = '--exclude=' . $e;
        }
        $comanda[] = $dosar;
        $r = Comanda::ruleaza($comanda);
        return $r['cod'] === 0 ? (int) $r['iesire'] : 0;
    }

    public static function om(int|float $octeti): string
    {
        $u = ['octeți', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($octeti >= 1024 && $i < 3) {
            $octeti /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) (int) $octeti : number_format($octeti, 1, ',', '')) . ' ' . $u[$i];
    }
}

/** `.env`-ul unui site, citit ca App\Support\Env (fără să încarce aplicația). */
final class Env
{
    /** @return array<string, string> */
    public static function citeste(string $fisier): array
    {
        $rez = [];
        foreach (is_file($fisier) ? (file($fisier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $linie) {
            $linie = trim($linie);
            if ($linie === '' || str_starts_with($linie, '#') || !str_contains($linie, '=')) {
                continue;
            }
            [$nume, $valoare] = explode('=', $linie, 2);
            $rez[trim($nume)] = trim(trim($valoare), "\"'");
        }
        return $rez;
    }

    /**
     * Textul `.env`-ului cu cheile date puse: o cheie care există e înlocuită pe rândul ei (rândul vechi rămâne,
     * comentat), una care lipsește se adaugă la sfârșit, sub un titlu.
     *
     * @param array<string, string> $chei
     */
    public static function cuChei(string $text, array $chei, string $titlu): string
    {
        $adaugate = [];
        foreach ($chei as $cheie => $valoare) {
            $rand = $cheie . '=' . $valoare;
            $gasit = false;
            $text = (string) preg_replace_callback('/^[ \t]*' . preg_quote($cheie, '/') . '[ \t]*=.*$/m',
                static function (array $m) use ($rand, &$gasit): string {
                    if ($gasit || trim($m[0]) === $rand) {
                        $gasit = true;
                        return trim($m[0]) === $rand ? $m[0] : '# ' . $m[0];
                    }
                    $gasit = true;
                    return '# ' . $m[0] . "   (înlocuit de deploy)\n" . $rand;
                }, $text);
            if (!$gasit) {
                $adaugate[] = $rand;
            }
        }
        if ($adaugate !== []) {
            $text = rtrim($text, "\n") . "\n\n# " . $titlu . "\n" . implode("\n", $adaugate) . "\n";
        }
        return $text;
    }
}

/** Baza unui site, după `.env`-ul lui: interogări (PDO), copia (mysqldump) și punerea ei la loc (mysql). */
final class Baza
{
    private ?PDO $pdo = null;

    /** @param array<string, string> $env */
    public function __construct(private readonly array $env)
    {
    }

    public function nume(): string
    {
        return (string) ($this->env['DB_DATABASE'] ?? '');
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            if ($this->nume() === '') {
                throw new Oprire('.env-ul n-are DB_DATABASE');
            }
            $gazda = (string) ($this->env['DB_HOST'] ?? 'localhost');
            $dsn = 'mysql:host=' . $gazda . ';port=' . (int) ($this->env['DB_PORT'] ?? 3306) . ';dbname=' . $this->nume() . ';charset=utf8mb4';
            try {
                $this->pdo = new PDO($dsn, (string) ($this->env['DB_USERNAME'] ?? ''), (string) ($this->env['DB_PASSWORD'] ?? ''), [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10,
                ]);
            } catch (Throwable $e) {
                throw new Oprire('baza ' . $this->nume() . ' nu răspunde (' . self::faraValori($e->getMessage()) . ')');
            }
        }
        return $this->pdo;
    }

    /** @return list<string> tabelele (BASE TABLE), sortate */
    public function tabele(string $fel = 'BASE TABLE'): array
    {
        $q = $this->pdo()->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME');
        $q->execute([$this->nume(), $fel]);
        return array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    public function numar(string $sql, array $parametri = []): int
    {
        $q = $this->pdo()->prepare($sql);
        $q->execute($parametri);
        return (int) $q->fetchColumn();
    }

    public function octeti(): int
    {
        return $this->numar('SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$this->nume()]);
    }

    /** Rutinele, declanșatoarele, vederile și evenimentele bazei (copia le cere explicit). @return array<string, int> */
    public function obiecte(): array
    {
        $b = [$this->nume()];
        return [
            'rutine' => $this->numar('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?', $b),
            'declansatoare' => $this->numar('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?', $b),
            'vederi' => $this->numar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'VIEW'", $b),
            'evenimente' => $this->numar('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?', $b),
        ];
    }

    /** Drepturile utilizatorului (SHOW GRANTS), ca listă de cuvinte mari („ALL PRIVILEGES”, „ALTER”…). @return list<string> */
    public function drepturi(): array
    {
        $rez = [];
        foreach ($this->pdo()->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN) as $rand) {
            if (preg_match('/^GRANT (.+?) ON (\S+) TO/i', (string) $rand, $m) === 1) {
                $tinta = str_replace(['`', '\\'], '', strtolower($m[2]));
                if ($tinta === '*.*' || $tinta === strtolower($this->nume()) . '.*' || fnmatch($tinta, strtolower($this->nume()) . '.*')) {
                    foreach (explode(',', strtoupper($m[1])) as $d) {
                        $rez[] = trim($d);
                    }
                }
            }
        }
        return array_values(array_unique($rez));
    }

    /**
     * Amprenta bazei, pentru „pusă la loc exact” și pentru „s-a scris ceva de atunci”: fiecare tabel cu `SHOW CREATE
     * TABLE` și `CHECKSUM TABLE` (orice INSERT, UPDATE sau DELETE o schimbă), plus declanșatoarele, vederile, rutinele și
     * evenimentele. Doar structură și sume, fără valori.
     *
     * @return array<string, mixed>
     */
    public function amprenta(): array
    {
        $pdo = $this->pdo();
        $tabele = [];
        foreach ($this->tabele() as $t) {
            $creare = (string) ($pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $t) . '`')->fetch(PDO::FETCH_NUM)[1] ?? '');
            $suma = (string) ($pdo->query('CHECKSUM TABLE `' . str_replace('`', '``', $t) . '`')->fetch(PDO::FETCH_NUM)[1] ?? '');
            // AUTO_INCREMENT=N din CREATE TABLE se schimbă și la un INSERT anulat; suma rândurilor spune dacă s-a scris ceva.
            $tabele[$t] = hash('sha256', (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', $creare)) . ' ' . $suma;
        }
        $rez = ['tabele' => $tabele, 'vederi' => $this->tabele('VIEW')];
        foreach (['declansatoare' => ['TRIGGERS', 'TRIGGER_SCHEMA', 'TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT'],
            'rutine' => ['ROUTINES', 'ROUTINE_SCHEMA', 'ROUTINE_NAME, ROUTINE_TYPE, ROUTINE_DEFINITION'],
            'evenimente' => ['EVENTS', 'EVENT_SCHEMA', 'EVENT_NAME, EVENT_DEFINITION, STATUS']] as $cheie => [$tabel, $coloanaBaza, $coloane]) {
            $rez[$cheie] = [];
            $q = $pdo->prepare('SELECT ' . $coloane . ' FROM information_schema.' . $tabel . ' WHERE ' . $coloanaBaza . ' = ? ORDER BY 1');
            $q->execute([$this->nume()]);
            foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) {
                $rez[$cheie][(string) $r[0]] = hash('sha256', implode('|', array_map('strval', $r)));
            }
        }
        return $rez;
    }

    /**
     * Tabelele în care s-a scris (sau care s-au schimbat) față de o amprentă de dinainte: tabelele cu altă sumă sau
     * structură, cele noi și cele dispărute. Gol = nimic scris de atunci.
     *
     * @return list<string>
     */
    public function tabeleSchimbate(array $amprentaVeche): array
    {
        $acum = $this->amprenta()['tabele'];
        $atunci = (array) ($amprentaVeche['tabele'] ?? []);
        $rez = [];
        foreach (array_unique(array_merge(array_keys($atunci), array_keys($acum))) as $t) {
            if (($atunci[$t] ?? null) !== ($acum[$t] ?? null)) {
                $rez[] = $t . (!isset($atunci[$t]) ? ' (nou)' : (!isset($acum[$t]) ? ' (șters)' : ''));
            }
        }
        sort($rez);
        return $rez;
    }

    /**
     * Obiectele bazei (declanșatoare, rutine, evenimente, vederi) al căror DEFINER nu e utilizatorul de acum: o copie
     * încărcată de alt utilizator le-ar refuza la jumătatea încărcării (pe Hostinger nu există SUPER). @return list<string>
     */
    public function definitoriStraini(): array
    {
        $eu = (string) $this->pdo()->query('SELECT CURRENT_USER()')->fetchColumn();
        $normal = static fn (string $d): string => strtolower(str_replace('`', '', $d));
        $rez = [];
        foreach ([['TRIGGERS', 'TRIGGER_SCHEMA', 'TRIGGER_NAME'], ['ROUTINES', 'ROUTINE_SCHEMA', 'ROUTINE_NAME'], ['EVENTS', 'EVENT_SCHEMA', 'EVENT_NAME'],
            ['VIEWS', 'TABLE_SCHEMA', 'TABLE_NAME']] as [$tabel, $coloanaBaza, $nume]) {
            $q = $this->pdo()->prepare('SELECT ' . $nume . ', DEFINER FROM information_schema.' . $tabel . ' WHERE ' . $coloanaBaza . ' = ?');
            $q->execute([$this->nume()]);
            foreach ($q->fetchAll(PDO::FETCH_NUM) as [$n, $d]) {
                if ($normal((string) $d) !== $normal($eu)) {
                    $rez[] = strtolower($tabel) . ' ' . $n . ' (DEFINER ' . $d . ')';
                }
            }
        }
        return $rez;
    }

    /**
     * Așteaptă (cel mult $secunde) până nu mai lucrează nicio altă conexiune pe această bază: o cerere a codului vechi
     * sau un cron pornit înainte de mentenanță. Întoarce câte au rămas (0 = liniște). Vede doar conexiunile
     * utilizatorului bazei (pe găzduirea comună, ale site-ului).
     */
    public function asteaptaLiniste(int $secunde): int
    {
        $sfarsit = microtime(true) + $secunde;
        do {
            $q = $this->pdo()->prepare("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB = ? AND ID <> CONNECTION_ID() AND COMMAND NOT IN ('Sleep', 'Daemon', 'Binlog Dump')");
            $q->execute([$this->nume()]);
            $n = (int) $q->fetchColumn();
            if ($n === 0) {
                return 0;
            }
            usleep(500000);
        } while (microtime(true) < $sfarsit);
        return $n;
    }

    /** Cele mai mari id-uri din tabelele cu date noi ale clienților (comenzi, conturi…), care există. @return array<string, int> */
    public function ultimeleIduri(): array
    {
        $rez = [];
        $existente = $this->tabele();
        foreach (['orders', 'order_items', 'users', 'newsletter_subscribers', 'contact_messages', 'product_reviews', 'payment_links'] as $t) {
            if (in_array($t, $existente, true)) {
                try {
                    $rez[$t] = (int) $this->pdo()->query('SELECT COALESCE(MAX(id), 0) FROM `' . $t . '`')->fetchColumn();
                } catch (Throwable) {
                }
            }
        }
        return $rez;
    }

    /** Fișierul de opțiuni al clientului mysql (parola nu apare în lista proceselor); îl șterge cine îl cere. */
    public function fisierOptiuni(string $dosar): string
    {
        Fisiere::dosar($dosar, 0700);
        $fisier = $dosar . '/.my-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.cnf';
        $citat = static fn (string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
        $gazda = (string) ($this->env['DB_HOST'] ?? 'localhost');
        $text = "[client]\nuser=" . $citat((string) ($this->env['DB_USERNAME'] ?? '')) . "\npassword=" . $citat((string) ($this->env['DB_PASSWORD'] ?? ''))
            . "\nhost=" . $citat($gazda) . "\nport=" . (int) ($this->env['DB_PORT'] ?? 3306) . "\n";
        $socket = trim((string) ini_get('pdo_mysql.default_socket'));
        if ($gazda === 'localhost' && $socket !== '') {
            $text .= 'socket=' . $citat($socket) . "\n";
        }
        $vechi = umask(0077);
        file_put_contents($fisier, $text);
        umask($vechi);
        return $fisier;
    }

    /**
     * Copia bazei: mysqldump | gzip, apoi amprenta bazei și lista tabelelor lângă ea (`<fișier>.json`). Întoarce
     * secundele.
     */
    /**
     * $consecventa: copia de revenire, cu scrierile oprite: o scriere în timpul ei (amprenta diferă înainte și după) o
     * oprește. Fără, copia de siguranță cu site-ul pornit: doar un avertisment.
     */
    public function copie(string $fisier, bool $consecventa = true): float
    {
        $dump = Comanda::primul('mysqldump', 'mariadb-dump') ?? throw new Oprire('nu găsesc mysqldump (nici mariadb-dump)');
        $obiecte = $this->obiecte();
        $optiuni = $this->fisierOptiuni(dirname($fisier));
        $inceput = microtime(true);
        try {
            $comanda = escapeshellarg($dump) . ' --defaults-extra-file=' . escapeshellarg($optiuni)
                . ' --single-transaction --quick --hex-blob --default-character-set=utf8mb4 --no-tablespaces --triggers'
                . ' --add-drop-table --skip-dump-date' . ($obiecte['rutine'] > 0 ? ' --routines' : '') . ($obiecte['evenimente'] > 0 ? ' --events' : '')
                . ' ' . escapeshellarg($this->nume()) . ' | gzip -c > ' . escapeshellarg($fisier . '.tmp');
            // Amprenta înainte și după copie: dacă diferă, cineva a scris în timpul copiei (copia n-ar mai fi cea a amprentei).
            $amprenta = $this->amprenta();
            $iduri = $this->ultimeleIduri();
            $r = Comanda::shell($comanda);
            if ($r['cod'] !== 0) {
                @unlink($fisier . '.tmp');
                throw new Oprire('copia bazei n-a mers: ' . self::faraValori(trim($r['erori'])));
            }
            $scrise = $this->tabeleSchimbate($amprenta);
            if ($scrise !== [] && !$consecventa) {
                Iesire::info('cu site-ul pornit, în timpul copiei s-a scris în ' . implode(', ', array_slice($scrise, 0, 5))
                    . ' (normal; copia de revenire se face din nou, cu scrierile oprite)');
                $amprenta = $this->amprenta();
            } elseif ($scrise !== []) {
                @unlink($fisier . '.tmp');
                throw new Oprire('în timpul copiei bazei s-a scris în ' . implode(', ', array_slice($scrise, 0, 8)) . '; copia nu e consecventă (alt proces lucrează pe bază)');
            }
            $verifica = Comanda::ruleaza(['gzip', '-t', $fisier . '.tmp']);
            if ($verifica['cod'] !== 0 || (int) filesize($fisier . '.tmp') < 100) {
                throw new Oprire('copia bazei e stricată sau goală (' . $fisier . '.tmp)');
            }
            chmod($fisier . '.tmp', 0600);
            rename($fisier . '.tmp', $fisier);
            Fisiere::scrie($fisier . '.json', (string) json_encode(['baza' => $this->nume(), 'gazda' => $this->gazda(), 'facuta' => date('c'),
                'amprenta' => $amprenta, 'ultimele_iduri' => $iduri, 'obiecte' => $obiecte], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", 0600);
        } finally {
            @unlink($optiuni);
        }
        return microtime(true) - $inceput;
    }

    /**
     * Copia e întreagă (gzip -t) și e a acestei baze (numele și gazda din .env-ul ei)? Întoarce informațiile ei.
     *
     * @return array<string, mixed>
     */
    public function verificaCopia(string $fisier): array
    {
        $info = json_decode((string) @file_get_contents($fisier . '.json'), true);
        if (!is_array($info) || !is_file($fisier)) {
            throw new Oprire('copia bazei lipsește sau n-are fișierul ei .json: ' . $fisier);
        }
        if (($info['baza'] ?? null) !== $this->nume() || ($info['gazda'] ?? null) !== $this->gazda()) {
            throw new Oprire('copia ' . $fisier . ' e a bazei ' . (string) ($info['baza'] ?? '?') . ' de pe ' . (string) ($info['gazda'] ?? '?')
                . ', nu a lui ' . $this->nume() . ' de pe ' . $this->gazda() . ' (din .env); n-o încarc și n-am atins baza');
        }
        if (Comanda::ruleaza(['gzip', '-t', $fisier])['cod'] !== 0) {
            throw new Oprire('copia bazei e stricată (gzip -t): ' . $fisier . '; n-am atins baza');
        }
        return $info;
    }

    /** Gazda și portul bazei, așa cum le scrie .env-ul („localhost:3306”). */
    public function gazda(): string
    {
        return (string) ($this->env['DB_HOST'] ?? 'localhost') . ':' . (int) ($this->env['DB_PORT'] ?? 3306);
    }

    /**
     * Pune la loc copia: verifică întâi că fișierul e întreg și că e copia acestei baze (numele, gazda), apoi șterge
     * tabelele, vederile, rutinele și evenimentele care nu erau în ea (făcute după copie) și încarcă fișierul. Întoarce
     * diferențele de amprentă față de cea de la copie (gol = baza e exact cum era).
     *
     * @return list<string>
     */
    public function restaureaza(string $fisier): array
    {
        $info = $this->verificaCopia($fisier);
        $client = Comanda::primul('mysql', 'mariadb') ?? throw new Oprire('nu găsesc clientul mysql (nici mariadb)');
        $pdo = $this->pdo();
        $amprenta = (array) ($info['amprenta'] ?? []);
        $aveau = array_keys((array) ($amprenta['tabele'] ?? []));
        $acum = $this->amprenta();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->tabele('VIEW') as $v) {
            if (!in_array($v, (array) ($amprenta['vederi'] ?? []), true)) {
                $pdo->exec('DROP VIEW `' . str_replace('`', '``', $v) . '`');
            }
        }
        foreach ($this->tabele() as $t) {
            if (!in_array($t, $aveau, true)) {
                $pdo->exec('DROP TABLE `' . str_replace('`', '``', $t) . '`');
            }
        }
        foreach (array_keys((array) ($acum['rutine'] ?? [])) as $r) {
            if (!isset($amprenta['rutine'][$r])) {
                $tip = (string) $pdo->query("SELECT ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = " . $pdo->quote($r))->fetchColumn();
                $pdo->exec('DROP ' . ($tip === 'FUNCTION' ? 'FUNCTION' : 'PROCEDURE') . ' IF EXISTS `' . str_replace('`', '``', $r) . '`');
            }
        }
        foreach (array_keys((array) ($acum['evenimente'] ?? [])) as $e) {
            if (!isset($amprenta['evenimente'][$e])) {
                $pdo->exec('DROP EVENT IF EXISTS `' . str_replace('`', '``', $e) . '`');
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $optiuni = $this->fisierOptiuni(dirname($fisier));
        try {
            $r = Comanda::shell('gzip -dc ' . escapeshellarg($fisier) . ' | ' . escapeshellarg($client) . ' --defaults-extra-file=' . escapeshellarg($optiuni)
                . ' --default-character-set=utf8mb4 ' . escapeshellarg($this->nume()));
        } finally {
            @unlink($optiuni);
        }
        if ($r['cod'] !== 0) {
            throw new Oprire('încărcarea copiei bazei n-a mers: ' . self::faraValori(trim($r['erori'])));
        }
        $this->pdo = null;
        return self::diferente($amprenta, $this->amprenta());
    }

    /** @return list<string> */
    public static function diferente(array $inainte, array $acum): array
    {
        $rez = [];
        foreach (['tabele' => 'tabelul', 'declansatoare' => 'declanșatorul', 'rutine' => 'rutina', 'evenimente' => 'evenimentul'] as $cheie => $nume) {
            $a = (array) ($inainte[$cheie] ?? []);
            $b = (array) ($acum[$cheie] ?? []);
            foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $t) {
                if (!isset($b[$t])) {
                    $rez[] = $nume . ' ' . $t . ' lipsește';
                } elseif (!isset($a[$t])) {
                    $rez[] = $nume . ' ' . $t . ' e în plus';
                } elseif ($a[$t] !== $b[$t]) {
                    $rez[] = $nume . ' ' . $t . ' diferă';
                }
            }
        }
        if ((array) ($inainte['vederi'] ?? []) !== (array) ($acum['vederi'] ?? [])) {
            $rez[] = 'vederile diferă';
        }
        return $rez;
    }

    /** Un mesaj al serverului fără valori între ghilimele (ca Schema::faraValori). */
    public static function faraValori(string $mesaj): string
    {
        return (string) preg_replace("/'[^']*'/", "'…'", $mesaj);
    }
}

/** Cererile de verificare spre site (curl), cu jetonul deploy-ului când site-ul e în MENTENANTA. */
final class Http
{
    /**
     * @param array<string, string> $antete
     * @return array{cod: int, antete: array<string, string>, corp: string, eroare: string, timp: float}
     */
    public static function cere(string $url, array $antete = [], int $limita = 30): array
    {
        if (!function_exists('curl_init')) {
            throw new Oprire('PHP-ul liniei de comandă n-are extensia curl');
        }
        $c = curl_init($url);
        $primite = [];
        $liste = [];
        foreach ($antete as $k => $v) {
            $liste[] = $k . ': ' . $v;
        }
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => $limita,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => $liste, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_USERAGENT => 'ANDAXI-deploy/1 (verificare)', CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$primite): int {
                if (str_contains($linie, ':')) {
                    [$n, $v] = explode(':', $linie, 2);
                    $primite[strtolower(trim($n))] = trim($v);
                } elseif (str_starts_with($linie, 'HTTP/')) {
                    $primite = [];
                }
                return strlen($linie);
            },
        ]);
        $inceput = microtime(true);
        $corp = curl_exec($c);
        $rez = ['cod' => (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE), 'antete' => $primite, 'corp' => is_string($corp) ? $corp : '',
            'eroare' => curl_error($c), 'timp' => microtime(true) - $inceput];
        curl_close($c);
        return $rez;
    }
}

/** Starea unui deploy pe un site (fazele terminate și ce trebuie ținut minte între rulări), ca JSON. */
final class Stare
{
    /** @var array<string, mixed> */
    public array $date;

    public function __construct(public readonly string $fisier)
    {
        $d = is_file($fisier) ? json_decode((string) file_get_contents($fisier), true) : null;
        $this->date = is_array($d) ? $d : ['faze' => []];
    }

    public function facuta(string $faza): bool
    {
        return isset($this->date['faze'][$faza]);
    }

    public function marcheaza(string $faza): void
    {
        $this->date['faze'][$faza] = date('c');
        $this->salveaza();
    }

    public function pune(string $cheie, mixed $valoare): void
    {
        $this->date[$cheie] = $valoare;
        $this->salveaza();
    }

    public function ia(string $cheie, mixed $implicit = null): mixed
    {
        return $this->date[$cheie] ?? $implicit;
    }

    public function salveaza(): void
    {
        Fisiere::dosar(dirname($this->fisier), 0700);
        Fisiere::scrie($this->fisier, (string) json_encode($this->date, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", 0600);
    }
}

/** Locurile unui site pe găzduire (docs/DEPLOY.md, „Așezarea”). */
final class Site
{
    public readonly string $dosar;
    public readonly string $docroot;
    public readonly string $vechi;
    public readonly string $nou;
    public readonly string $site;
    public readonly string $lucru;
    public readonly string $releases;

    public function __construct(public readonly string $acasa, public readonly string $domeniu)
    {
        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domeniu) !== 1) {
            throw new Oprire('domeniul „' . $domeniu . '” nu arată ca un domeniu (exemplu.ro)', 2);
        }
        $this->dosar = $acasa . '/domains/' . $domeniu;
        $this->docroot = $this->dosar . '/public_html';
        $this->vechi = $this->dosar . '/public_html.vechi';
        $this->nou = $this->dosar . '/public_html.nou';
        $this->site = $this->dosar . '/site';
        $this->lucru = $this->site . '/deploy';
        $this->releases = $acasa . '/platforma/releases';
    }

    /** Dosarul de acasă: --acasa=, altfel HOME. */
    public static function acasa(Optiuni $o): string
    {
        $acasa = rtrim((string) ($o->text('acasa') ?? getenv('HOME') ?: ''), '/');
        if ($acasa === '' || !is_dir($acasa)) {
            throw new Oprire('nu găsesc dosarul de acasă (HOME sau --acasa=)', 2);
        }
        return (string) realpath($acasa);
    }

    /**
     * Site-ul după --domeniu=, sau după --site=<slug>: domeniul al cărui site/.env are SITE_SLUG=<slug>.
     */
    public static function gaseste(Optiuni $o): self
    {
        $acasa = self::acasa($o);
        $domeniu = $o->text('domeniu');
        $slug = $o->text('site');
        if ($domeniu !== null) {
            $s = new self($acasa, $domeniu);
            if ($slug !== null && is_file($s->site . '/.env') && (Env::citeste($s->site . '/.env')['SITE_SLUG'] ?? '') !== $slug) {
                throw new Oprire('site/.env al lui ' . $domeniu . ' are alt SITE_SLUG decât ' . $slug, 2);
            }
            return $s;
        }
        if ($slug === null) {
            throw new Oprire('lipsește --site=<site> (sau --domeniu=<domeniu>)', 2);
        }
        $gasite = [];
        foreach (glob($acasa . '/domains/*/site/.env') ?: [] as $env) {
            if ((Env::citeste($env)['SITE_SLUG'] ?? '') === $slug) {
                $gasite[] = basename(dirname($env, 2));
            }
        }
        if (count($gasite) !== 1) {
            throw new Oprire($gasite === [] ? 'niciun site cu SITE_SLUG=' . $slug . ' în ' . $acasa . '/domains/*/site/.env; dă --domeniu='
                : 'mai multe site-uri au SITE_SLUG=' . $slug . ' (' . implode(', ', $gasite) . '); dă --domeniu=', 2);
        }
        return new self($acasa, $gasite[0]);
    }

    /** Dosarul unei versiuni: site/releases/<v> (dacă site-ul n-are voie să citească în afara dosarului lui), altfel cel comun. */
    public function cod(string $versiune): string
    {
        foreach ([$this->site . '/releases/' . $versiune, $this->releases . '/' . $versiune] as $d) {
            if (is_file($d . '/VERSION')) {
                return $d;
            }
        }
        return $this->releases . '/' . $versiune;
    }

    /** Stub-ul e deja în public_html? */
    public function cuStub(): bool
    {
        return is_file($this->docroot . '/index.php') && is_file($this->docroot . '/mentenanta.html')
            && str_contains((string) @file_get_contents($this->docroot . '/index.php'), 'PLATFORMA_SITE_DIR');
    }

    /** Lacătul site-ului: un singur deploy odată. */
    public function lacat(): mixed
    {
        Fisiere::dosar($this->acasa . '/platforma/lacate', 0700);
        $f = fopen($this->acasa . '/platforma/lacate/' . $this->domeniu, 'c');
        if ($f === false || !flock($f, LOCK_EX | LOCK_NB)) {
            throw new Oprire('alt script de deploy lucrează chiar acum pe ' . $this->domeniu . '; așteaptă să termine', 3);
        }
        return $f;
    }
}

final class Versiune
{
    public const TIPAR = '/^[0-9][0-9.]*-[0-9a-f]{7}$/';

    /**
     * Verifică o versiune urcată: VERSION, SHA256SUMS (fiecare fișier, niciunul în plus sau lipsă). Întoarce
     * problemele (gol = bună).
     *
     * @return list<string>
     */
    public static function verifica(string $dosar, string $versiune): array
    {
        if (!is_dir($dosar)) {
            return ['versiunea ' . $versiune . ' nu există: ' . $dosar];
        }
        $probleme = [];
        if (trim((string) @file_get_contents($dosar . '/VERSION')) !== $versiune) {
            $probleme[] = 'VERSION nu spune ' . $versiune;
        }
        $sume = [];
        foreach (file($dosar . '/SHA256SUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linie) {
            if (preg_match('/^([0-9a-f]{64})  (.+)$/', $linie, $m) === 1) {
                $sume[$m[2]] = $m[1];
            }
        }
        if ($sume === []) {
            return array_merge($probleme, ['SHA256SUMS lipsește sau e gol']);
        }
        $reale = array_values(array_diff(Fisiere::lista($dosar), ['SHA256SUMS']));
        $scriibile = trim(Comanda::ruleaza(['find', $dosar, '-perm', '/222', '!', '-type', 'l'])['iesire']);
        if ($scriibile !== '') {
            $n = substr_count($scriibile, "\n") + 1;
            $probleme[] = $n . ' fișiere sau dosare scriibile (versiunea trebuie să fie doar de citit), de exemplu '
                . substr(strtok($scriibile, "\n") ?: '', strlen($dosar) + 1);
        }
        foreach ($reale as $rel) {
            if (!isset($sume[$rel])) {
                $probleme[] = 'fișier în plus: ' . $rel;
            } elseif (!hash_equals($sume[$rel], (string) hash_file('sha256', $dosar . '/' . $rel))) {
                $probleme[] = 'fișier schimbat: ' . $rel;
            }
        }
        foreach (array_diff(array_keys($sume), $reale) as $rel) {
            $probleme[] = 'fișier lipsă: ' . $rel;
        }
        return count($probleme) > 20 ? array_merge(array_slice($probleme, 0, 20), ['… și încă ' . (count($probleme) - 20)]) : $probleme;
    }

    /** php_min din config/app.php al versiunii. */
    public static function phpMin(string $dosar): string
    {
        preg_match("/'php_min'\s*=>\s*'([0-9.]+)'/", (string) @file_get_contents($dosar . '/config/app.php'), $m);
        return $m[1] ?? '8.3';
    }
}

/**
 * Mentenanța deploy-ului: fișierul site/MENTENANTA, citit de stub (deploy/stub/index.php) și de bin/platforma.
 *
 *  - „ușoară”: vizitatorii văd „Revenim în câteva minute”, dar plățile (/webhook/), ERP-ul (/api/erp/) și pingul trec;
 *    cronurile (bin/platforma) nu rulează;
 *  - „strictă” (al doilea rând „strict”): și plățile și ERP-ul primesc 503 cu Retry-After (procesatorii de plăți și
 *    ERP-ul retrimit la un 5xx), deci nimic nu scrie în bază cât se copiază, se migrează sau se pune la loc baza.
 *
 * Cererile cu jetonul (primul rând) trec în ambele: așa verifică deploy-ul site-ul nou.
 */
final class Mentenanta
{
    public static function pune(Site $s, string $jeton, bool $stricta): void
    {
        Fisiere::scrie($s->site . '/MENTENANTA', $jeton . "\n" . ($stricta ? "strict\n" : ''), 0640);
    }

    /** Conținutul de acum (null = fără mentenanță), ca să poată fi pus la loc exact. */
    public static function citeste(Site $s): ?string
    {
        return is_file($s->site . '/MENTENANTA') ? (string) file_get_contents($s->site . '/MENTENANTA') : null;
    }

    public static function puneLaLoc(Site $s, ?string $continut): void
    {
        if ($continut === null) {
            @unlink($s->site . '/MENTENANTA');
        } else {
            Fisiere::scrie($s->site . '/MENTENANTA', $continut, 0640);
        }
    }

    public static function scoate(Site $s): void
    {
        @unlink($s->site . '/MENTENANTA');
    }
}

/**
 * Redenumirile unui pas (de exemplu public_html → public_html.vechi, apoi public_html.nou → public_html), ținute minte
 * în starea deploy-ului: intenția se scrie înainte de prima redenumire, iar după fiecare, câte s-au făcut. Un script oprit
 * între două redenumiri (SSH-ul căzut, procesul omorât) lasă deci o urmă: la rularea următoare `termina()` face ce a
 * rămas, înainte de orice altceva, așa că nu rămâne niciun dosar lipsă și nu se șterge niciodată singura copie.
 *
 * PLATFORMA_TEST_OPRESTE_DUPA=<cheie>:<pas> (doar testele, tools/dev/test-deploy.php) oprește procesul imediat după
 * redenumirea cu numărul dat, înainte să-și scrie progresul: exact ce lasă un proces omorât.
 */
final class Redenumiri
{
    /** @param list<array{0: string, 1: string}> $pasi */
    public static function fa(Stare $stare, string $cheie, array $pasi): void
    {
        if (in_array($cheie, (array) $stare->ia('redenumiri_facute', []), true)) {
            return;
        }
        $inCurs = (array) $stare->ia('redenumiri', []);
        if (!isset($inCurs[$cheie])) {
            $inCurs[$cheie] = ['pasi' => $pasi, 'facuti' => 0];
            $stare->pune('redenumiri', $inCurs);
        }
        self::continua($stare, $cheie);
    }

    /** Termină redenumirile rămase la jumătate de la o rulare oprită. @return list<string> cheile terminate */
    public static function termina(Stare $stare): array
    {
        $rez = [];
        foreach (array_keys((array) $stare->ia('redenumiri', [])) as $cheie) {
            Iesire::atentie('o rulare de dinainte s-a oprit la jumătatea pasului „' . $cheie . '”; îl termin acum');
            self::continua($stare, (string) $cheie);
            $rez[] = (string) $cheie;
        }
        return $rez;
    }

    /** Uită un pas făcut (de exemplu după o întoarcere, ca o rulare nouă să-l poată face din nou). */
    public static function uita(Stare $stare, string ...$chei): void
    {
        $stare->pune('redenumiri_facute', array_values(array_diff((array) $stare->ia('redenumiri_facute', []), $chei)));
    }

    private static function continua(Stare $stare, string $cheie): void
    {
        $inCurs = (array) $stare->ia('redenumiri', []);
        $pasi = (array) ($inCurs[$cheie]['pasi'] ?? []);
        $test = (string) getenv('PLATFORMA_TEST_OPRESTE_DUPA');
        for ($i = (int) ($inCurs[$cheie]['facuti'] ?? 0); $i < count($pasi); $i++) {
            [$din, $in] = $pasi[$i];
            clearstatcache();
            $existaDin = file_exists($din) || is_link($din);
            $existaIn = file_exists($in) || is_link($in);
            if ($existaDin && !$existaIn) {
                if (!@rename($din, $in)) {
                    throw new Oprire('nu pot muta ' . $din . ' în ' . $in);
                }
                if ($test === $cheie . ':' . ($i + 1)) {
                    fwrite(STDERR, "PLATFORMA_TEST_OPRESTE_DUPA: oprit după pasul " . ($i + 1) . " al lui „" . $cheie . "”\n");
                    exit(9);
                }
            } elseif ($existaDin || !$existaIn) {
                throw new Oprire('pasul „' . $cheie . '” (' . ($i + 1) . '/' . count($pasi) . ': ' . $din . ' → ' . $in . ') nu se mai poate face: '
                    . ($existaDin ? 'amândouă există' : 'niciunul nu există') . '. Nu schimb nimic; uită-te la cele două dosare (ne trimiți ce vezi)');
            }
            $inCurs[$cheie]['facuti'] = $i + 1;
            $stare->pune('redenumiri', $inCurs);
        }
        unset($inCurs[$cheie]);
        $stare->pune('redenumiri', $inCurs);
        $facute = (array) $stare->ia('redenumiri_facute', []);
        $facute[] = $cheie;
        $stare->pune('redenumiri_facute', array_values(array_unique($facute)));
    }
}

/**
 * Docroot-ul unui site (public_html/) în așezarea din Etapa 2: ce pune deploy-ul acolo, dintr-o versiune și un pachet.
 *
 *  - rădăcina: stub-ul (`deploy/stub/index.php`, `.htaccess`, `mentenanta.html`) și fișierele din `public/` ale pachetului
 *    care nu sunt în `assets/`;
 *  - `assets/`: copia lui `public/assets/` a versiunii, peste care vin fișierele pachetului din `public/assets/` și fișierele
 *    site-ului (scrise de aplicație, ca `assets/.htaccess`, sau aduse de la codul vechi);
 *  - `tema/`: `tema/public/` al pachetului.
 *
 * Restul docroot-ului (`uploads/`, sitemap-urile scrise de admin, fișierele aduse de la codul vechi) e al site-ului și
 * deploy-ul nu-l atinge.
 */
final class Docroot
{
    public const STUB = ['index.php', '.htaccess', 'mentenanta.html'];

    /** Fișierele din `public/` ale pachetului, relative la docroot. @return list<string> */
    public static function publicePachet(string $pachet): array
    {
        return Fisiere::lista($pachet . '/public');
    }

    /** @return array<string, string> calea din docroot → fișierul sursă (stub-ul și fișierele pachetului din afara assets/) */
    public static function radacina(string $cod, string $pachet): array
    {
        $rez = [];
        foreach (self::STUB as $f) {
            $rez[$f] = $cod . '/deploy/stub/' . $f;
        }
        foreach (self::publicePachet($pachet) as $rel) {
            if (!str_starts_with($rel, 'assets/') && !str_starts_with($rel, 'tema/') && !str_starts_with($rel, 'uploads/')) {
                $rez[$rel] = $pachet . '/public/' . $rel;
            }
        }
        return $rez;
    }

    /**
     * `assets/` pentru o versiune: copia versiunii; apoi, din `assets/` de acum ($curent, dacă e dat), fișierele care nu
     * sunt nici ale versiunii de dinainte, nici ale pachetului de dinainte (deci ale site-ului); la urmă fișierele
     * pachetului din `public/assets/`. Dosarul iese scriibil (aplicația scrie `assets/.htaccess`).
     */
    public static function assets(string $cod, string $pachet, string $tinta, ?string $curent = null, ?string $codVechi = null, ?string $pachetVechi = null): void
    {
        Fisiere::copiaza($cod . '/public/assets', $tinta);
        Comanda::ruleaza(['chmod', '-R', 'u+w', $tinta]);
        if ($curent !== null && is_dir($curent)) {
            $aleVersiunii = $codVechi !== null ? array_flip(Fisiere::lista($codVechi . '/public/assets')) : [];
            $alePachetului = [];
            foreach ($pachetVechi !== null ? self::publicePachet($pachetVechi) : [] as $rel) {
                if (str_starts_with($rel, 'assets/')) {
                    $alePachetului[substr($rel, 7)] = true;
                }
            }
            foreach (Fisiere::lista($curent) as $rel) {
                if (!isset($aleVersiunii[$rel]) && !isset($alePachetului[$rel])) {
                    Fisiere::dosar(dirname($tinta . '/' . $rel), 0755);
                    Fisiere::copiaza($curent . '/' . $rel, $tinta . '/' . $rel);
                }
            }
        }
        foreach (self::publicePachet($pachet) as $rel) {
            if (str_starts_with($rel, 'assets/')) {
                Fisiere::dosar(dirname($tinta . '/' . substr($rel, 7)), 0755);
                @unlink($tinta . '/' . substr($rel, 7));
                Fisiere::copiaza($pachet . '/public/' . $rel, $tinta . '/' . substr($rel, 7));
            }
        }
        Comanda::ruleaza(['chmod', '-R', 'u+w,go+rX', $tinta]);
    }

    /** `tema/` din `tema/public/` al pachetului; false dacă pachetul n-are fișiere publice ale temei. */
    public static function tema(string $pachet, string $tinta): bool
    {
        if (!is_dir($pachet . '/tema/public')) {
            return false;
        }
        Fisiere::copiaza($pachet . '/tema/public', $tinta);
        Comanda::ruleaza(['chmod', '-R', 'u+w,go+rX', $tinta]);
        return true;
    }
}

/**
 * Golește ce a randat codul în storage/cache/ al site-ului: paginile (ResponseCache: cheia nu conține versiunea, deci
 * paginile codului de dinainte s-ar servi încă o jumătate de oră), sitemap-urile, fluxul Google Merchant și foile
 * pregătite din assets/. Datele (stocul ERP, mărimile pozelor, pozele de distribuire) rămân. Întoarce câte fișiere a șters.
 */
function golesteCacheCod(Site $s): int
{
    $n = 0;
    foreach (['cache/pages/*.html', 'cache/pages/*.json', 'cache/sitemap/*', 'cache/assets/*', 'cache/feed-google-merchant.xml'] as $tipar) {
        foreach (glob($s->site . '/storage/' . $tipar) ?: [] as $f) {
            if (is_file($f) && basename($f) !== '.gitkeep' && @unlink($f)) {
                $n++;
            }
        }
    }
    return $n;
}

/** Ce spune codul de ieșire al lui scripts/migreaza.php (docs/MIGRARI.md, „Codurile de ieșire”). */
function sensMigrari(int $cod): string
{
    return match ($cod) {
        0 => 'nimic de făcut / totul aplicat și verificat',
        1 => 'o migrare a dat eroare (mesajul de mai sus spune care); rularea din nou e sigură',
        2 => 'configurare: .env, pachetul site-ului (SITE_DATE_DOSAR), SITE_SLUG sau baza',
        3 => 'alt runner lucrează pe aceeași bază (sau sincronizarea FAN ține tabelele); din nou peste câteva minute',
        4 => 'baza nu e a unui site ANDAXI: verifică DB_DATABASE',
        10 => 'sunt migrări de aplicat, toate fără blocare',
        11 => 'sunt migrări blocante sau de tip contract: cer --permite-blocante (doar cu site-ul în mentenanță)',
        12 => 'o migrare aplicată pe bază s-a schimbat sau lipsește din cod (codul e mai vechi decât baza?)',
        13 => 'bugetul de timp s-a terminat; continuă la rularea următoare',
        14 => 'după aplicare lipsește un obiect cerut de asteptat.json (lista LIPSĂ de mai sus)',
        15 => 'lipsește un parametru al site-ului, înghețarea e provizorie, sau lipsesc valorile private (site/privat/)',
        default => 'cod neașteptat (vezi ieșirea de mai sus)',
    };
}

/**
 * Verificarea unui site după o comutare (testul de fum al deploy-ului): pingul (versiunea și schema), /health (schema
 * și pachetul), prima pagină, o pagină de produs, pagina de logare din admin, coșul, un fișier din assets/ și o poză din
 * uploads/, apoi jurnalele PHP ale site-ului (nicio eroare fatală nouă). Cererile cer „no-cache” (ocolesc cache-ul
 * paginilor și pe cel al CDN-ului). Cu $jeton (site-ul în MENTENANTA), cererile îl
 * trimit, ca stub-ul să le lase să treacă. Întoarce problemele (gol = trecut).
 *
 * @return list<string>
 */
function verificaSiteul(Site $s, string $url, ?string $jeton, string $versiune, ?string $produs): array
{
    // no-cache: răspunsul codului de acum, nu o pagină din cache-ul paginilor (ResponseCache) sau al CDN-ului.
    $antete = ['Cache-Control' => 'no-cache', 'Pragma' => 'no-cache']
        + ($jeton !== null ? ['X-Andaxi-Deploy' => $jeton, 'Cookie' => 'andaxi_deploy=' . $jeton] : []);
    $jurnale = [];
    foreach (['php.log', 'fatal.log'] as $j) {
        $f = $s->site . '/storage/logs/' . $j;
        clearstatcache(true, $f);
        $jurnale[$f] = is_file($f) ? (int) filesize($f) : 0;
    }
    $probleme = [];
    $verifica = static function (string $eticheta, bool $ok, string $detalii) use (&$probleme): void {
        if ($ok) {
            Iesire::ok($eticheta);
        } else {
            Iesire::nu($eticheta . ': ' . $detalii);
            $probleme[] = $eticheta;
        }
    };
    $scurt = static fn (array $r): string => 'HTTP ' . $r['cod'] . ($r['eroare'] !== '' ? ' (' . $r['eroare'] . ')' : '') . ', '
        . mb_substr(trim((string) preg_replace('/\s+/', ' ', strip_tags($r['corp']))), 0, 160);
    $fatal = static fn (string $corp): bool => preg_match('/(Fatal error|Uncaught |Parse error|Stack trace:)/', $corp) === 1;

    $r = Http::cere($url . '/_andaxi/ping', $antete);
    $d = json_decode($r['corp'], true);
    $verifica('pingul: 200, baza răspunde, codul ' . $versiune . ', schema la_zi', $r['cod'] === 200 && is_array($d) && ($d['ok'] ?? false) === true
        && ($d['cod'] ?? null) === $versiune && ($d['schema'] ?? null) === 'la_zi', $scurt($r));
    $amprenta = is_file($s->site . '/pachet/pachet.json') ? substr((string) hash_file('sha256', $s->site . '/pachet/pachet.json'), 0, 12) : 'lipsa';
    $r = Http::cere($url . '/health', $antete);
    $verifica('/health: ok, schema: la_zi, pachet: ' . $amprenta, $r['cod'] === 200 && str_starts_with($r['corp'], "ok\n")
        && str_contains($r['corp'], 'schema: la_zi') && str_contains($r['corp'], 'pachet: ' . $amprenta), $scurt($r));
    $r = Http::cere($url . '/', $antete);
    $verifica('prima pagină: 200, HTML, fără erori PHP', $r['cod'] === 200 && stripos($r['corp'], '<html') !== false && !$fatal($r['corp'])
        && !str_contains($r['corp'], 'Revenim în câteva minute'), $scurt($r));
    if ($produs === null) {
        try {
            $baza = new Baza(Env::citeste($s->site . '/.env'));
            $produs = (string) ($baza->pdo()->query('SELECT slug FROM products WHERE deleted_at IS NULL AND is_active = 1 AND hidden_in_catalog = 0 '
                . "AND slug <> '' ORDER BY id LIMIT 1")->fetchColumn() ?: '');
        } catch (Throwable) {
            $produs = '';
        }
    }
    if ($produs !== '') {
        $r = Http::cere($url . '/produs/' . rawurlencode($produs), $antete);
        $verifica('o pagină de produs (/produs/' . $produs . '): 200, fără erori PHP', $r['cod'] === 200 && !$fatal($r['corp']), $scurt($r));
    } else {
        Iesire::atentie('niciun produs activ în bază: pagina de produs nu s-a verificat (--produs=<slug>)');
    }
    $r = Http::cere($url . '/admin/login', $antete);
    $verifica('pagina de logare din admin: 200, cu câmpul parolei', $r['cod'] === 200 && preg_match('/type=["\']password["\']/i', $r['corp']) === 1
        && !$fatal($r['corp']), $scurt($r));
    $r = Http::cere($url . '/cos', $antete);
    $verifica('coșul (/cos): 200, fără erori PHP', $r['cod'] === 200 && !$fatal($r['corp']), $scurt($r));
    $r = Http::cere($url . '/assets/css/app.css', $antete);
    $verifica('un fișier din assets/ (/assets/css/app.css): 200', $r['cod'] === 200 && strlen($r['corp']) > 100, $scurt($r));
    $poza = null;
    $n = 0;
    if (is_dir($s->docroot . '/uploads')) {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($s->docroot . '/uploads', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (++$n > 5000) {
                break;
            }
            if ($f->isFile() && preg_match('/\.(jpe?g|png|webp|gif)$/i', $f->getFilename()) === 1) {
                $poza = substr($f->getPathname(), strlen($s->docroot));
                break;
            }
        }
    }
    if ($poza !== null) {
        $r = Http::cere($url . str_replace('%2F', '/', rawurlencode($poza)), $antete);
        $verifica('o poză din uploads/ (' . $poza . '): 200', $r['cod'] === 200 && $r['corp'] !== '', $scurt($r));
    }
    $grave = [];
    foreach ($jurnale as $f => $marime) {
        clearstatcache(true, $f);
        if (is_file($f) && (int) filesize($f) > $marime) {
            $nou = (string) file_get_contents($f, false, null, $marime);
            // fatal.log are doar erori fatale (public/index.php), deci orice rând nou e unul; php.log are și avertismente.
            foreach (explode("\n", $nou) as $l) {
                if (trim($l) !== '' && (str_ends_with($f, '/fatal.log') ? !str_starts_with($l, ' ') : preg_match('/PHP (Fatal|Parse) error|Uncaught /', $l) === 1)) {
                    $grave[] = basename($f) . ': ' . $l;
                }
            }
        }
    }
    $verifica('jurnalele PHP ale site-ului (site/storage/logs): nicio eroare fatală nouă', $grave === [], implode(' | ', array_map(
        static fn (string $l): string => mb_substr($l, 0, 200), array_slice($grave, 0, 3))));
    return $probleme;
}

/** Versiunea și în site/releases/<v> (legături fixe: fără spațiu în plus), pentru o găzduire cu open_basedir. */
function legaVersiunea(Site $s, string $versiune): void
{
    $tinta = $s->site . '/releases/' . $versiune;
    if (!is_dir($tinta)) {
        Fisiere::dosar($s->site . '/releases', 0750);
        $r = Comanda::ruleaza(['cp', '-al', $s->releases . '/' . $versiune, $tinta]);
        if ($r['cod'] !== 0) {
            Fisiere::sterge($tinta, [$s->site . '/releases']);
            Fisiere::copiaza($s->releases . '/' . $versiune, $tinta);
        }
    }
    $probleme = Versiune::verifica($tinta, $versiune);
    if ($probleme !== []) {
        throw new Oprire('copia versiunii din ' . $tinta . ' nu e bună: ' . implode('; ', array_slice($probleme, 0, 3)));
    }
    Iesire::ok('versiunea și în ' . $tinta . ' (site-ul nu poate citi în afara dosarului lui)');
}

/**
 * Pachetul site-ului (docs/PACHETE.md, „Pe server”), scris în $tinta: din --pachet=<dosar> (fără privat/ de acolo),
 * altfel `git archive <commit>:site-uri/<slug>` din repo (commit-ul versiunii, deci pachetul merge cu codul ei); apoi
 * site/privat/ (valorile private ale înghețării, puse de mână) copiat în privat/.
 */
function scriePachet(Site $s, string $slug, ?string $dinDosar, string $repo, string $commit, string $tinta): void
{
    Fisiere::dosar($tinta, 0750);
    if ($dinDosar !== null) {
        Fisiere::copiazaContinut($dinDosar, $tinta);
        Fisiere::sterge($tinta . '/privat', [$tinta]);
    } else {
        $r = Comanda::shell('git -C ' . escapeshellarg($repo) . ' archive --format=tar ' . escapeshellarg($commit . ':site-uri/' . $slug)
            . ' | tar -xf - -C ' . escapeshellarg($tinta));
        if ($r['cod'] !== 0) {
            throw new Oprire('pachetul site-uri/' . $slug . ' nu se scoate din repo (' . $repo . ', ' . substr($commit, 0, 12) . '): ' . trim($r['erori']));
        }
    }
    if (is_dir($s->site . '/privat')) {
        Fisiere::copiaza($s->site . '/privat', $tinta . '/privat');
        Comanda::ruleaza(['chmod', '-R', 'go-rwx', $tinta . '/privat']);
    }
}

/** @return array{0: ?array, 1: ?array} pachet.json și setari-inghetate.json, citite fără să scrie pachetul */
function citestePachet(string $slug, ?string $dinDosar, string $repo, string $commit): array
{
    $citeste = static function (string $fisier) use ($slug, $dinDosar, $repo, $commit): ?array {
        if ($dinDosar !== null) {
            $text = @file_get_contents($dinDosar . '/' . $fisier);
        } else {
            $r = Comanda::ruleaza(['git', '-C', $repo, 'show', $commit . ':site-uri/' . $slug . '/' . $fisier]);
            $text = $r['cod'] === 0 ? $r['iesire'] : false;
        }
        $d = is_string($text) ? json_decode($text, true) : null;
        return is_array($d) ? $d : null;
    };
    return [$citeste('pachet.json'), $citeste('setari-inghetate.json')];
}

/** @return list<string> problemele pachetului pentru un site live (privat/ lipsă, înghețare provizorie) */
function problemePachet(Site $s, string $slug, ?array $manifest, ?array $inghetare): array
{
    $rez = [];
    if ($manifest === null) {
        return ['pachetul site-uri/' . $slug . '/ lipsește (n-are pachet.json)'];
    }
    if (($manifest['site'] ?? null) !== $slug) {
        $rez[] = 'pachetul e al site-ului „' . (string) ($manifest['site'] ?? '?') . '”, nu „' . $slug . '”';
    }
    $cuPrivat = false;
    foreach ((array) ($inghetare['setari'] ?? []) as $intrare) {
        $cuPrivat = $cuPrivat || (is_array($intrare) && ($intrare['privat'] ?? false) === true);
    }
    if ($cuPrivat) {
        $privat = json_decode((string) @file_get_contents($s->site . '/privat/setari-inghetate.json'), true);
        if (!is_array($privat)) {
            $rez[] = 'înghețarea are valori private, dar lipsește ' . $s->site . '/privat/setari-inghetate.json (îl copiezi de mână, docs/DEPLOY.md)';
        } elseif (($privat['site'] ?? null) !== $slug) {
            $rez[] = $s->site . '/privat/setari-inghetate.json e al altui site';
        }
    }
    if (is_array($inghetare) && isset($inghetare['provizoriu'])) {
        $rez[] = 'înghețarea setărilor din pachet e încă provizorie: pe un site live migrările se opresc cu codul 15 (tools/inghetare/genereaza-setari.php --confirmat)';
    }
    return $rez;
}

/**
 * Codul unui site rulat prin bin/platforma al versiunii (migreaza.php, diagnostic.php), cu PHP-ul scripturilor.
 *
 * @param list<string> $argumente
 * @return array{cod: int, iesire: string, erori: string}
 */
function platforma(string $dosarSite, string $cod, string $versiune, array $argumente, bool $afiseaza = false, ?string $public = null): array
{
    $comanda = array_merge(Comanda::php(), [$cod . '/bin/platforma', '--site=' . $dosarSite, '--versiune=' . $versiune, '--in-mentenanta'],
        $public !== null ? ['--public=' . $public] : [], $argumente);
    return Comanda::ruleaza($comanda, ['afiseaza' => $afiseaza, 'cwd' => $cod]);
}

/**
 * Rulează scriptul: prinde oprirea și orice eroare neașteptată, cu un mesaj clar, și întoarce codul de ieșire.
 */
function porneste(callable $principal): int
{
    try {
        $cod = $principal();
        return is_int($cod) ? $cod : 0;
    } catch (Oprire $o) {
        return Iesire::oprire($o);
    } catch (Throwable $e) {
        return Iesire::oprire(new Oprire('eroare neașteptată: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 1));
    }
}
