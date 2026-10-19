<?php

declare(strict_types=1);

namespace App\Support\Migrari;

use PDO;
use RuntimeException;

/**
 * Amprenta schemei: tabelele, coloanele, indecșii și cheile străine ale unei baze, scrise
 * la fel oricare ar fi serverul (MariaDB sau MySQL), ca două baze să se poată compara.
 * Doar structură: nume, tipuri, valori implicite din definiții. Niciun rând de date.
 *
 * Ce se aduce la aceeași formă (docs/MIGRARI.md, „Amprenta”):
 *  - lățimea afișată a întregilor: MariaDB scrie `int(11)`, MySQL ≥ 8.0.19 `int`; rămâne
 *    doar `tinyint(1)` (ambele îl păstrează); `year(4)` → `year`; `json` → `longtext`
 *    (pe MariaDB JSON e un longtext);
 *  - valoarea implicită: MariaDB ≥ 10.2.7 scrie un text între ghilimele (`'pending'`) și
 *    „fără valoare” ca `NULL`; MySQL scrie textul fără ghilimele și NULL adevărat. Aici:
 *    null = fără valoare sau NULL; un text = între ghilimele simple; un număr (pe o coloană
 *    numerică) = fără ghilimele, cu zecimalele coloanei; o expresie = cu litere mici,
 *    `current_timestamp()`/`CURRENT_TIMESTAMP`/`now()` → `current_timestamp`;
 *  - EXTRA: litere mici, fără `default_generated` (MySQL), `on update current_timestamp`;
 *  - cheile străine: `NO ACTION` = `RESTRICT` (InnoDB le tratează la fel; MySQL scrie unul,
 *    MariaDB celălalt); colațiile `utf8mb3_*` = `utf8_*`;
 *  - ordinea coloanelor nu contează (un `AFTER` eșuat pe un site vechi le-a amestecat);
 *  - tabelele `_platforma_*` (ale runner-ului), `*__sync` și `*__vechi` (copiile de lucru
 *    ale sincronizării FAN) și `#sql*` nu intră deloc.
 *
 * Aceeași normalizare o folosește Schema când compară o coloană cerută de o migrare cu
 * cea din bază, iar `normalizeaza()` primește și rânduri citite în altă parte (sonda
 * schemei de pe un site, WP-10), deci e o funcție pură.
 */
final class Amprenta
{
    public const FORMAT = 1;

    private const INTREGI = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'];
    private const NUMERICE = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double'];

    public static function ignorat(string $tabel): bool
    {
        return str_starts_with($tabel, '_platforma_') || str_starts_with($tabel, '#sql')
            || str_ends_with($tabel, '__sync') || str_ends_with($tabel, '__vechi');
    }

    // ------------------------------------------------------------------ citire

    /**
     * Amprenta bazei curente (sau doar a câtorva tabele).
     *
     * @param list<string>|null $doarTabele
     * @return array{format: int, tabele: array<string, array<string, mixed>>}
     */
    public static function citeste(PDO $db, Server $server, ?array $doarTabele = null): array
    {
        return self::normalizeaza(self::citesteBrut($db, $doarTabele), $server->familie());
    }

    /**
     * Rândurile din information_schema, cu nume de câmpuri fixe (aceeași formă o scrie
     * sonda de pe servere): tabele {tabel, motor, colatie}; coloane {tabel, coloana, tip,
     * nul, implicit, extra}; indecsi {tabel, index, non_unic, pozitie, coloana, parte, tip,
     * expresie}; fk {tabel, nume, coloana, pozitie, tabel_referit, coloana_referita,
     * la_actualizare, la_stergere}.
     *
     * @param list<string>|null $doarTabele
     * @return array{tabele: list<array<string, mixed>>, coloane: list<array<string, mixed>>, indecsi: list<array<string, mixed>>, fk: list<array<string, mixed>>}
     */
    public static function citesteBrut(PDO $db, ?array $doarTabele = null): array
    {
        $filtru = '';
        $parametri = [];
        if ($doarTabele !== null) {
            if ($doarTabele === []) {
                return ['tabele' => [], 'coloane' => [], 'indecsi' => [], 'fk' => []];
            }
            $filtru = ' AND TABLE_NAME IN (' . implode(', ', array_fill(0, count($doarTabele), '?')) . ')';
            $parametri = array_values($doarTabele);
        }
        $citeste = static function (string $sql) use ($db, $parametri): array {
            $stmt = $db->prepare($sql);
            $stmt->execute($parametri);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };
        $existaExpresie = self::areColoanaExpresie($db);
        return [
            'tabele' => $citeste("SELECT TABLE_NAME AS tabel, ENGINE AS motor, TABLE_COLLATION AS colatie
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'" . $filtru),
            'coloane' => $citeste('SELECT TABLE_NAME AS tabel, COLUMN_NAME AS coloana, COLUMN_TYPE AS tip,
                    IS_NULLABLE AS nul, COLUMN_DEFAULT AS implicit, EXTRA AS extra
                FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()' . $filtru),
            'indecsi' => $citeste('SELECT TABLE_NAME AS tabel, INDEX_NAME AS `index`, NON_UNIQUE AS non_unic,
                    SEQ_IN_INDEX AS pozitie, COLUMN_NAME AS coloana, SUB_PART AS parte, INDEX_TYPE AS tip, '
                    . ($existaExpresie ? 'EXPRESSION' : 'NULL') . ' AS expresie
                FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()' . $filtru),
            'fk' => self::chei($citeste('SELECT TABLE_NAME AS tabel, CONSTRAINT_NAME AS nume, COLUMN_NAME AS coloana,
                    ORDINAL_POSITION AS pozitie, REFERENCED_TABLE_NAME AS tabel_referit, REFERENCED_COLUMN_NAME AS coloana_referita
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL' . $filtru),
                $citeste('SELECT TABLE_NAME AS tabel, CONSTRAINT_NAME AS nume, UPDATE_RULE AS la_actualizare, DELETE_RULE AS la_stergere
                FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()' . $filtru)),
        ];
    }

    /**
     * Coloanele cheilor străine cu regulile lor. Două interogări unite aici, nu un JOIN: pe
     * MariaDB, un JOIN între tabelele din information_schema le citește pe ale tuturor bazelor
     * de pe server (sute de ms pe o găzduire comună), fiecare singură e filtrată pe bază.
     *
     * @param list<array<string, mixed>> $coloane
     * @param list<array<string, mixed>> $reguli
     * @return list<array<string, mixed>>
     */
    private static function chei(array $coloane, array $reguli): array
    {
        $dupaNume = [];
        foreach ($reguli as $r) {
            $dupaNume[$r['tabel'] . "\0" . $r['nume']] = $r;
        }
        $rezultat = [];
        foreach ($coloane as $c) {
            $regula = $dupaNume[$c['tabel'] . "\0" . $c['nume']] ?? null;
            if ($regula === null) {
                continue;
            }
            $rezultat[] = $c + ['la_actualizare' => $regula['la_actualizare'], 'la_stergere' => $regula['la_stergere']];
        }
        return $rezultat;
    }

    /** STATISTICS.EXPRESSION (indecșii pe expresii) există doar pe MySQL ≥ 8.0.13. */
    private static function areColoanaExpresie(PDO $db): bool
    {
        static $memorat = [];
        $cheie = spl_object_id($db);
        if (!isset($memorat[$cheie])) {
            $stmt = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'information_schema' AND TABLE_NAME = 'STATISTICS' AND COLUMN_NAME = 'EXPRESSION'");
            $memorat[$cheie] = (int) $stmt->fetchColumn() > 0;
        }
        return $memorat[$cheie];
    }

    // ------------------------------------------------------------ normalizare

    /**
     * @param array<string, list<array<string, mixed>>> $brut forma lui citesteBrut()
     * @return array{format: int, tabele: array<string, array<string, mixed>>}
     */
    public static function normalizeaza(array $brut, string $familie): array
    {
        $tabele = [];
        foreach ($brut['tabele'] ?? [] as $r) {
            $nume = (string) $r['tabel'];
            if (self::ignorat($nume)) {
                continue;
            }
            $tabele[$nume] = [
                'motor' => (string) ($r['motor'] ?? ''),
                'colatie' => self::colatie((string) ($r['colatie'] ?? '')),
                'coloane' => [],
                'indecsi' => [],
                'fk' => [],
            ];
        }
        foreach ($brut['coloane'] ?? [] as $r) {
            $t = (string) $r['tabel'];
            if (!isset($tabele[$t])) {
                continue;
            }
            $tabele[$t]['coloane'][(string) $r['coloana']] = self::coloanaDinInformatii($r, $familie);
        }
        $indecsi = [];
        foreach ($brut['indecsi'] ?? [] as $r) {
            $t = (string) $r['tabel'];
            if (!isset($tabele[$t])) {
                continue;
            }
            $indecsi[$t][(string) $r['index']][(int) $r['pozitie']] = $r;
        }
        foreach ($indecsi as $t => $lista) {
            foreach ($lista as $nume => $randuri) {
                ksort($randuri);
                $prim = reset($randuri);
                $coloane = [];
                foreach ($randuri as $r) {
                    $coloana = $r['coloana'] !== null ? (string) $r['coloana'] : '(' . strtolower((string) ($r['expresie'] ?? '')) . ')';
                    $coloane[] = $coloana . ($r['parte'] !== null && $r['parte'] !== '' ? '(' . (int) $r['parte'] . ')' : '');
                }
                $tabele[$t]['indecsi'][$nume] = self::index((int) $prim['non_unic'] === 0, $coloane, (string) ($prim['tip'] ?? ''));
            }
        }
        $fk = [];
        foreach ($brut['fk'] ?? [] as $r) {
            $t = (string) $r['tabel'];
            if (!isset($tabele[$t])) {
                continue;
            }
            $fk[$t][(string) $r['nume']][(int) $r['pozitie']] = $r;
        }
        foreach ($fk as $t => $lista) {
            foreach ($lista as $nume => $randuri) {
                ksort($randuri);
                $prim = reset($randuri);
                $tabele[$t]['fk'][$nume] = [
                    'coloane' => array_values(array_map(static fn (array $r): string => (string) $r['coloana'], $randuri)),
                    'tabel' => (string) $prim['tabel_referit'],
                    'referite' => array_values(array_map(static fn (array $r): string => (string) $r['coloana_referita'], $randuri)),
                    'la_stergere' => self::regulaFk((string) $prim['la_stergere']),
                    'la_actualizare' => self::regulaFk((string) $prim['la_actualizare']),
                ];
            }
        }
        foreach ($tabele as &$tabel) {
            ksort($tabel['coloane']);
            ksort($tabel['indecsi']);
            ksort($tabel['fk']);
        }
        unset($tabel);
        ksort($tabele);
        return ['format' => self::FORMAT, 'tabele' => $tabele];
    }

    /**
     * O coloană din information_schema.COLUMNS (câmpurile tip, nul, implicit, extra).
     *
     * @param array<string, mixed> $r
     * @return array{tip: string, null: bool, implicit: ?string, extra: string}
     */
    public static function coloanaDinInformatii(array $r, string $familie): array
    {
        $tip = self::tip((string) $r['tip']);
        $extraBrut = strtolower((string) ($r['extra'] ?? ''));
        $brut = $r['implicit'] ?? null;
        $implicit = null;
        if ($brut !== null) {
            $brut = (string) $brut;
            if ($familie === Server::MARIADB) {
                if ($brut === 'NULL') {
                    $implicit = null;
                } elseif (str_starts_with($brut, "'")) {
                    $implicit = self::literal(self::faraGhilimele($brut), $tip);
                } elseif (self::pareNumar($brut)) {
                    $implicit = self::literal($brut, $tip);
                } elseif (preg_match("/^(b|x)'/i", $brut) === 1 || str_starts_with(strtolower($brut), '0x')) {
                    $implicit = $brut;
                } else {
                    $implicit = self::expresie($brut);
                }
            } elseif (str_contains($extraBrut, 'default_generated')
                || (in_array(self::bazaTip($tip), ['datetime', 'timestamp'], true)
                    && preg_match('/^(current_timestamp|now|localtime|localtimestamp)\b/i', $brut) === 1)) {
                // MySQL < 8.0.13 nu scrie DEFAULT_GENERATED nici la CURRENT_TIMESTAMP.
                $implicit = self::expresie($brut);
            } elseif (preg_match("/^(b|x)'/i", $brut) === 1 || str_starts_with(strtolower($brut), '0x')) {
                $implicit = $brut;
            } else {
                $implicit = self::literal($brut, $tip);
            }
        }
        return [
            'tip' => $tip,
            'null' => strtoupper((string) $r['nul']) === 'YES',
            'implicit' => $implicit,
            'extra' => self::extra($extraBrut),
        ];
    }

    /**
     * Tipul unei coloane, la forma comună (vezi capul clasei).
     */
    public static function tip(string $tip): string
    {
        $tip = trim(preg_replace('/\s+/', ' ', self::minusculeInAfaraTextelor($tip)) ?? $tip);
        // Fără spații în paranteze, în afara textelor („decimal(10, 2)” → „decimal(10,2)”).
        $tip = self::faraSpatiiInParanteze($tip);
        $tip = preg_replace('/^national /', '', $tip) ?? $tip;
        $inlocuiri = [
            '/^(bool|boolean)\b/' => 'tinyint(1)',
            '/^integer\b/' => 'int',
            '/^(dec|numeric|fixed)\b/' => 'decimal',
            '/^(double precision|real)\b/' => 'double',
            '/^character varying\b/' => 'varchar',
            '/^character\b/' => 'char',
            '/^json\b/' => 'longtext',
            '/^year\(4\)/' => 'year',
        ];
        foreach ($inlocuiri as $model => $cu) {
            $tip = preg_replace($model, $cu, $tip) ?? $tip;
        }
        if (preg_match('/^(tinyint|smallint|mediumint|int|bigint)\((\d+)\)(.*)$/', $tip, $m) === 1
            && !($m[1] === 'tinyint' && $m[2] === '1')) {
            $tip = $m[1] . $m[3];
        }
        if (preg_match('/^decimal(?!\()(.*)$/', $tip, $m) === 1) {
            $tip = 'decimal(10,0)' . $m[1];
        } elseif (preg_match('/^decimal\((\d+)\)(.*)$/', $tip, $m) === 1) {
            $tip = 'decimal(' . $m[1] . ',0)' . $m[2];
        }
        if (preg_match('/^(char|binary|bit)(?!\()(.*)$/', $tip, $m) === 1) {
            $tip = $m[1] . '(1)' . $m[2];
        }
        return trim(str_replace(' signed', '', $tip));
    }

    private static function bazaTip(string $tip): string
    {
        return preg_match('/^[a-z]+/', $tip, $m) === 1 ? $m[0] : $tip;
    }

    public static function tipNumeric(string $tip): bool
    {
        return in_array(self::bazaTip($tip), self::NUMERICE, true);
    }

    /** Un literal ca valoare implicită: numerele fără ghilimele, cu zecimalele coloanei; textele între ghilimele. */
    public static function literal(string $valoare, string $tip): string
    {
        $baza = self::bazaTip($tip);
        if (in_array($baza, self::NUMERICE, true) && self::pareNumar(trim($valoare))) {
            $valoare = trim($valoare);
            if (in_array($baza, self::INTREGI, true)) {
                return self::intreg($valoare);
            }
            if ($baza === 'decimal' && preg_match('/^decimal\(\d+,(\d+)\)/', $tip, $m) === 1) {
                return number_format((float) $valoare, (int) $m[1], '.', '');
            }
            $numar = (float) $valoare;
            return $numar == (int) $numar && abs($numar) < 1e15 ? (string) (int) $numar : (string) $numar;
        }
        return "'" . str_replace("'", "''", $valoare) . "'";
    }

    private static function intreg(string $valoare): string
    {
        if (preg_match('/^([+-]?)0*(\d+)$/', $valoare, $m) === 1) {
            return ($m[1] === '-' && $m[2] !== '0' ? '-' : '') . $m[2];
        }
        return (string) (int) (float) $valoare;
    }

    private static function pareNumar(string $text): bool
    {
        return preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)(e[+-]?\d+)?$/i', $text) === 1;
    }

    /** O expresie ca valoare implicită sau la ON UPDATE. */
    public static function expresie(string $expresie): string
    {
        $e = strtolower(trim($expresie));
        while (str_starts_with($e, '(') && str_ends_with($e, ')') && self::parantezaInchisaLaFinal($e)) {
            $e = trim(substr($e, 1, -1));
        }
        $e = preg_replace('/^(current_timestamp|now|localtime|localtimestamp)(\(\))?$/', 'current_timestamp', $e) ?? $e;
        $e = preg_replace('/^(current_timestamp|now|localtime|localtimestamp)\((\d+)\)$/', 'current_timestamp($2)', $e) ?? $e;
        return $e;
    }

    private static function parantezaInchisaLaFinal(string $e): bool
    {
        $adancime = 0;
        $lungime = strlen($e);
        for ($i = 0; $i < $lungime; $i++) {
            if ($e[$i] === '(') {
                $adancime++;
            } elseif ($e[$i] === ')') {
                $adancime--;
                if ($adancime === 0 && $i < $lungime - 1) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function extra(string $extra): string
    {
        $extra = str_replace('default_generated', '', strtolower($extra));
        $extra = preg_replace('/on update (current_timestamp|now|localtimestamp)(\(\))?/', 'on update current_timestamp', $extra) ?? $extra;
        $extra = preg_replace('/on update (current_timestamp|now|localtimestamp)\((\d+)\)/', 'on update current_timestamp($2)', $extra) ?? $extra;
        $extra = preg_replace('/\bpersistent\b/', 'stored', $extra) ?? $extra;
        return trim(preg_replace('/\s+/', ' ', $extra) ?? $extra);
    }

    private static function colatie(string $colatie): string
    {
        return preg_replace('/^utf8mb3_/', 'utf8_', $colatie) ?? $colatie;
    }

    private static function regulaFk(string $regula): string
    {
        $regula = strtoupper(trim($regula));
        return $regula === 'NO ACTION' || $regula === '' ? 'RESTRICT' : $regula;
    }

    /**
     * @param list<string> $coloane
     * @return array{unic: bool, coloane: list<string>, tip?: string}
     */
    public static function index(bool $unic, array $coloane, string $tip = ''): array
    {
        $index = ['unic' => $unic, 'coloane' => array_values($coloane)];
        $tip = strtoupper(trim($tip));
        if ($tip !== '' && $tip !== 'BTREE' && $tip !== 'HASH') {
            $index['tip'] = $tip;
        }
        return $index;
    }

    /** Doi indecși echivalenți: aceleași coloane, în aceeași ordine, aceeași unicitate și același fel. */
    public static function indecsiEchivalenti(array $a, array $b): bool
    {
        return $a['unic'] === $b['unic'] && $a['coloane'] === $b['coloane'] && ($a['tip'] ?? '') === ($b['tip'] ?? '');
    }

    /** @param array{tip: string, null: bool, implicit: ?string, extra: string} $c */
    public static function descrieColoana(array $c): string
    {
        $text = $c['tip'] . ($c['null'] ? ' NULL' : ' NOT NULL');
        if ($c['implicit'] !== null) {
            $text .= ' DEFAULT ' . $c['implicit'];
        }
        return $text . ($c['extra'] !== '' ? ' ' . $c['extra'] : '');
    }

    /**
     * Ce deosebește o coloană existentă de cea cerută (gol = la fel). Nu se compară decât
     * ce contează pentru cod: tipul, NULL, valoarea implicită, auto_increment / on update.
     *
     * @return list<string>
     */
    public static function diferenteColoana(array $existenta, array $ceruta): array
    {
        $diferente = [];
        if ($existenta['tip'] !== $ceruta['tip']) {
            $diferente[] = 'tipul';
        }
        if ($existenta['null'] !== $ceruta['null']) {
            $diferente[] = 'NULL';
        }
        if ($existenta['implicit'] !== $ceruta['implicit']) {
            $diferente[] = 'valoarea implicită';
        }
        if ($existenta['extra'] !== $ceruta['extra']) {
            $diferente[] = 'extra';
        }
        return $diferente;
    }

    // ------------------------------------------------- definițiile din migrări

    /**
     * O definiție de coloană scrisă într-o migrare („VARCHAR(20) NOT NULL DEFAULT 'pending'”),
     * la forma amprentei. În plus: primar/unic (scrise în definiție), pozitie (AFTER/FIRST,
     * interzise) și necunoscute (cuvintele neînțelese, ca o greșeală de tipar să nu treacă).
     *
     * @return array{tip: string, null: bool, implicit: ?string, extra: string, primar: bool, unic: bool, pozitie: bool, necunoscute: list<string>}
     */
    public static function coloanaDinDefinitie(string $definitie): array
    {
        $jetoane = self::jetoane($definitie);
        $n = count($jetoane);
        if ($n === 0 || $jetoane[0][0] !== 'cuv') {
            throw new RuntimeException('definiție de coloană fără tip');
        }
        $i = 0;
        $cuv = static fn (int $k): string => isset($jetoane[$k]) && $jetoane[$k][0] === 'cuv' ? strtolower($jetoane[$k][1]) : '';
        $tip = $cuv($i++);
        if ($tip === 'national') {
            $tip = $cuv($i++);
        }
        if (($tip === 'double' && $cuv($i) === 'precision') || ($tip === 'character' && $cuv($i) === 'varying')) {
            $tip .= ' ' . $cuv($i++);
        }
        if (isset($jetoane[$i]) && $jetoane[$i][0] === 'grup') {
            $tip .= $jetoane[$i++][2];
        }
        while (in_array($cuv($i), ['unsigned', 'signed', 'zerofill'], true)) {
            $tip .= ' ' . $cuv($i++);
        }
        $tip = self::tip($tip);

        $rezultat = ['tip' => $tip, 'null' => true, 'implicit' => null, 'extra' => '', 'primar' => false,
            'unic' => false, 'pozitie' => false, 'necunoscute' => []];
        $nulExplicit = false;
        $extra = [];
        while ($i < $n) {
            $c = $cuv($i);
            if ($c === 'not' && $cuv($i + 1) === 'null') {
                $rezultat['null'] = false;
                $nulExplicit = true;
                $i += 2;
            } elseif ($c === 'null') {
                $rezultat['null'] = true;
                $nulExplicit = true;
                $i++;
            } elseif ($c === 'default') {
                [$rezultat['implicit'], $i] = self::valoareImplicita($jetoane, $i + 1, $tip);
            } elseif ($c === 'auto_increment') {
                $extra[] = 'auto_increment';
                $i++;
            } elseif ($c === 'on' && $cuv($i + 1) === 'update') {
                $expr = $jetoane[$i + 2][1] ?? '';
                $i += 3;
                if (isset($jetoane[$i]) && $jetoane[$i][0] === 'grup') {
                    $expr .= $jetoane[$i++][2];
                }
                $extra[] = 'on update ' . self::expresie($expr);
            } elseif ($c === 'comment') {
                $i += 2;
            } elseif ($c === 'character' && $cuv($i + 1) === 'set') {
                $i += 3;
            } elseif ($c === 'charset' || $c === 'collate') {
                $i += 2;
            } elseif ($c === 'primary' && $cuv($i + 1) === 'key') {
                $rezultat['primar'] = true;
                $i += 2;
            } elseif ($c === 'key') {
                $rezultat['primar'] = true;
                $i++;
            } elseif ($c === 'unique') {
                $rezultat['unic'] = true;
                $i += $cuv($i + 1) === 'key' ? 2 : 1;
            } elseif ($c === 'after') {
                $rezultat['pozitie'] = true;
                $i += 2;
            } elseif ($c === 'first') {
                $rezultat['pozitie'] = true;
                $i++;
            } elseif ($c === 'visible' || $c === 'invisible') {
                $i++;
            } elseif ($c === 'generated' || $c === 'as') {
                while ($i < $n && $jetoane[$i][0] !== 'grup') {
                    $i++;
                }
                $i++;
                $fel = $cuv($i);
                if (in_array($fel, ['virtual', 'stored', 'persistent'], true)) {
                    $i++;
                }
                $extra[] = ($fel === 'stored' || $fel === 'persistent' ? 'stored' : 'virtual') . ' generated';
            } elseif ($c === 'check') {
                $i += 2;
            } elseif ($c === 'references') {
                $i = $n;
            } else {
                $rezultat['necunoscute'][] = $jetoane[$i][2];
                $i++;
            }
        }
        if ($rezultat['primar'] || (in_array('auto_increment', $extra, true) && !$nulExplicit)) {
            $rezultat['null'] = false;
        }
        $rezultat['extra'] = self::extra(implode(' ', $extra));
        return $rezultat;
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $jetoane
     * @return array{0: ?string, 1: int}
     */
    private static function valoareImplicita(array $jetoane, int $i, string $tip): array
    {
        $j = $jetoane[$i] ?? null;
        if ($j === null) {
            return [null, $i];
        }
        if ($j[0] === 'sir') {
            return [self::literal($j[1], $tip), $i + 1];
        }
        if ($j[0] === 'grup') {
            return [self::expresie($j[2]), $i + 1];
        }
        if ($j[0] === 'semn' && ($j[1] === '-' || $j[1] === '+') && isset($jetoane[$i + 1]) && $jetoane[$i + 1][0] === 'cuv') {
            return [self::literal($j[1] . $jetoane[$i + 1][1], $tip), $i + 2];
        }
        $cuvant = strtolower($j[1]);
        if ($cuvant === 'null') {
            return [null, $i + 1];
        }
        if ($cuvant === 'true' || $cuvant === 'false') {
            return [self::literal($cuvant === 'true' ? '1' : '0', $tip), $i + 1];
        }
        if (self::pareNumar($j[1])) {
            return [self::literal($j[1], $tip), $i + 1];
        }
        if (preg_match("/^(b|x)'/i", $j[1]) === 1) {
            return [$j[1], $i + 1];
        }
        $expr = $j[1];
        if (isset($jetoane[$i + 1]) && $jetoane[$i + 1][0] === 'grup') {
            $expr .= $jetoane[$i + 1][2];
            $i++;
        }
        return [self::expresie($expr), $i + 1];
    }

    /**
     * Corpul unui CREATE TABLE dintr-o migrare (ce e între paranteze), ca structură: pe ea
     * se sprijină planul („coloana există deja în tabelul pe care îl creează migrarea”).
     *
     * @return array{coloane: array<string, array<string, mixed>>, indecsi: array<string, array<string, mixed>>, fk: array<string, array<string, mixed>>}
     */
    public static function structuraDinCorp(string $corp): array
    {
        $structura = ['coloane' => [], 'indecsi' => [], 'fk' => []];
        $fkFaraIndex = [];
        foreach (self::impartePeVirgule(self::jetoane($corp)) as $element) {
            if ($element === []) {
                continue;
            }
            $cuv = static fn (int $k): string => isset($element[$k]) && $element[$k][0] === 'cuv' ? strtolower($element[$k][1]) : '';
            $nume = null;
            $i = 0;
            if ($cuv(0) === 'constraint') {
                $i = 1;
                if (!in_array($cuv(1), ['primary', 'unique', 'foreign', 'check'], true)) {
                    $nume = $element[1][1];
                    $i = 2;
                }
            }
            $primul = $cuv($i);
            if ($primul === 'check') {
                continue;
            }
            if ($primul === 'primary' || $primul === 'unique' || $primul === 'key' || $primul === 'index'
                || $primul === 'fulltext' || $primul === 'spatial') {
                $unic = $primul === 'primary' || $primul === 'unique';
                $fel = $primul === 'fulltext' || $primul === 'spatial' ? strtoupper($primul) : '';
                $i++;
                while (in_array($cuv($i), ['key', 'index'], true)) {
                    $i++;
                }
                if (isset($element[$i]) && $element[$i][0] !== 'grup') {
                    $nume = $element[$i][1];
                    $i++;
                }
                $coloane = isset($element[$i]) && $element[$i][0] === 'grup' ? self::coloaneIndex($element[$i][2]) : [];
                if ($coloane === []) {
                    continue;
                }
                if ($primul === 'primary') {
                    $nume = 'PRIMARY';
                }
                $nume ??= self::numeColoanaIndex($coloane[0]);
                $structura['indecsi'][$nume] = self::index($unic, $coloane, $fel);
                continue;
            }
            if ($primul === 'foreign') {
                $i += $cuv($i + 1) === 'key' ? 2 : 1;
                if (isset($element[$i]) && $element[$i][0] !== 'grup') {
                    $i++;
                }
                $coloane = isset($element[$i]) && $element[$i][0] === 'grup' ? self::coloaneIndex($element[$i][2]) : [];
                $i++;
                $referit = '';
                $referite = [];
                $laStergere = 'RESTRICT';
                $laActualizare = 'RESTRICT';
                if ($cuv($i) === 'references') {
                    $referit = $element[$i + 1][1] ?? '';
                    $referite = isset($element[$i + 2]) && $element[$i + 2][0] === 'grup' ? self::coloaneIndex($element[$i + 2][2]) : [];
                    for ($k = $i + 3; $k < count($element); $k++) {
                        if ($cuv($k) === 'on' && in_array($cuv($k + 1), ['delete', 'update'], true)) {
                            $actiune = $cuv($k + 2);
                            if (($actiune === 'set' || $actiune === 'no') && $cuv($k + 3) !== '') {
                                $actiune .= ' ' . $cuv($k + 3);
                            }
                            if ($cuv($k + 1) === 'delete') {
                                $laStergere = self::regulaFk($actiune);
                            } else {
                                $laActualizare = self::regulaFk($actiune);
                            }
                        }
                    }
                }
                $numeFk = $nume ?? '(fără nume)';
                $structura['fk'][$numeFk] = ['coloane' => $coloane, 'tabel' => $referit, 'referite' => $referite,
                    'la_stergere' => $laStergere, 'la_actualizare' => $laActualizare];
                $fkFaraIndex[] = [$nume ?? ($coloane[0] ?? ''), $coloane];
                continue;
            }
            // O coloană: numele, apoi definiția.
            $numeColoana = $element[0][1];
            $restul = implode(' ', array_map(static fn (array $j): string => $j[2], array_slice($element, 1)));
            $definitie = self::coloanaDinDefinitie($restul);
            $structura['coloane'][$numeColoana] = [
                'tip' => $definitie['tip'], 'null' => $definitie['null'],
                'implicit' => $definitie['implicit'], 'extra' => $definitie['extra'],
            ];
            if ($definitie['primar']) {
                $structura['indecsi']['PRIMARY'] = self::index(true, [$numeColoana]);
            } elseif ($definitie['unic']) {
                $structura['indecsi'][$numeColoana] = self::index(true, [$numeColoana]);
            }
        }
        // Serverul face singur un index pentru o cheie străină fără unul care să înceapă cu coloanele ei.
        foreach ($fkFaraIndex as [$numeIndex, $coloane]) {
            $acoperita = false;
            foreach ($structura['indecsi'] as $index) {
                if (array_slice($index['coloane'], 0, count($coloane)) === $coloane) {
                    $acoperita = true;
                    break;
                }
            }
            if (!$acoperita && $numeIndex !== '') {
                $structura['indecsi'][$numeIndex] = self::index(false, $coloane);
            }
        }
        unset($structura['fk']['(fără nume)']);
        ksort($structura['coloane']);
        ksort($structura['indecsi']);
        return $structura;
    }

    /** Coloanele dintr-o listă de index „(a, b(191) DESC)” → ['a', 'b(191)']. @return list<string> */
    public static function coloaneIndex(string $grup): array
    {
        $interior = trim($grup);
        if (str_starts_with($interior, '(') && str_ends_with($interior, ')')) {
            $interior = substr($interior, 1, -1);
        }
        $coloane = [];
        foreach (self::impartePeVirgule(self::jetoane($interior)) as $element) {
            if ($element === [] || !in_array($element[0][0], ['cuv', 'id'], true)) {
                continue;
            }
            $coloana = $element[0][1];
            if (isset($element[1]) && $element[1][0] === 'grup' && preg_match('/^\(\s*(\d+)\s*\)$/', $element[1][2], $m) === 1) {
                $coloana .= '(' . $m[1] . ')';
            }
            $coloane[] = $coloana;
        }
        return $coloane;
    }

    private static function numeColoanaIndex(string $coloana): string
    {
        return preg_replace('/\(\d+\)$/', '', $coloana) ?? $coloana;
    }

    // --------------------------------------------------------------- comparare

    /**
     * Baza curentă față de amprenta așteptată.
     *  - lipsa: ce cere amprenta și nu există (tabel, coloană, index: același nume sau unul
     *    echivalent sub alt nume) → `--verifica` iese cu 14;
     *  - avertismente: există, dar altfel (definiția unei coloane, un index cu același nume
     *    și alte coloane, motorul, colația), sau lipsește o cheie străină (baza veche n-o
     *    are: nu se adaugă niciodată singură peste date existente);
     *  - in_plus: ce are baza în plus (coloane vechi, tabele de la scripturi unice): doar
     *    informație.
     *
     * @return array{lipsa: list<string>, avertismente: list<string>, in_plus: list<string>}
     */
    public static function compara(array $asteptat, array $curent): array
    {
        $lipsa = [];
        $avertismente = [];
        $inPlus = [];
        $asteptate = $asteptat['tabele'] ?? [];
        $curente = $curent['tabele'] ?? [];
        foreach ($asteptate as $nume => $tabel) {
            if (!isset($curente[$nume])) {
                $lipsa[] = 'tabelul ' . $nume;
                continue;
            }
            $acum = $curente[$nume];
            if (($tabel['motor'] ?? '') !== '' && ($acum['motor'] ?? '') !== ($tabel['motor'] ?? '')) {
                $avertismente[] = 'tabelul ' . $nume . ': motorul ' . ($acum['motor'] ?? '?') . ', se așteaptă ' . $tabel['motor'];
            }
            if (($tabel['colatie'] ?? '') !== '' && ($acum['colatie'] ?? '') !== ($tabel['colatie'] ?? '')) {
                $avertismente[] = 'tabelul ' . $nume . ': colația ' . ($acum['colatie'] ?? '?') . ', se așteaptă ' . $tabel['colatie'];
            }
            foreach ($tabel['coloane'] ?? [] as $coloana => $definitie) {
                if (!isset($acum['coloane'][$coloana])) {
                    $lipsa[] = 'coloana ' . $nume . '.' . $coloana;
                    continue;
                }
                if (self::diferenteColoana($acum['coloane'][$coloana], $definitie) !== []) {
                    $avertismente[] = 'coloana ' . $nume . '.' . $coloana . ': ' . self::descrieColoana($acum['coloane'][$coloana])
                        . ', se așteaptă ' . self::descrieColoana($definitie);
                }
            }
            foreach (array_diff_key($acum['coloane'] ?? [], $tabel['coloane'] ?? []) as $coloana => $_) {
                $inPlus[] = 'coloana ' . $nume . '.' . $coloana;
            }
            $folosite = [];
            foreach ($tabel['indecsi'] ?? [] as $index => $definitie) {
                if (isset($acum['indecsi'][$index])) {
                    $folosite[$index] = true;
                    if (!self::indecsiEchivalenti($acum['indecsi'][$index], $definitie)) {
                        $avertismente[] = 'indexul ' . $nume . '.' . $index . ': (' . implode(', ', $acum['indecsi'][$index]['coloane'])
                            . '), se așteaptă (' . implode(', ', $definitie['coloane']) . ')' . ($definitie['unic'] ? ' unic' : '');
                    }
                    continue;
                }
                $echivalent = null;
                foreach ($acum['indecsi'] ?? [] as $altNume => $alt) {
                    if (!isset($folosite[$altNume]) && !isset($tabel['indecsi'][$altNume]) && self::indecsiEchivalenti($alt, $definitie)) {
                        $echivalent = $altNume;
                        break;
                    }
                }
                if ($echivalent === null) {
                    $lipsa[] = 'indexul ' . $nume . '.' . $index;
                } else {
                    $folosite[$echivalent] = true;
                }
            }
            foreach ($acum['indecsi'] ?? [] as $index => $_) {
                if (!isset($folosite[$index]) && !isset($tabel['indecsi'][$index])) {
                    $inPlus[] = 'indexul ' . $nume . '.' . $index;
                }
            }
            foreach ($tabel['fk'] ?? [] as $fk => $definitie) {
                $gasita = isset($acum['fk'][$fk]);
                if (!$gasita) {
                    foreach ($acum['fk'] ?? [] as $alta) {
                        if ($alta['coloane'] === $definitie['coloane'] && $alta['tabel'] === $definitie['tabel']) {
                            $gasita = true;
                            break;
                        }
                    }
                }
                if (!$gasita) {
                    $avertismente[] = 'cheia străină ' . $nume . '.' . $fk . ' lipsește (nu se adaugă singură peste date existente)';
                }
            }
        }
        foreach (array_diff_key($curente, $asteptate) as $nume => $_) {
            $inPlus[] = 'tabelul ' . $nume;
        }
        return ['lipsa' => $lipsa, 'avertismente' => $avertismente, 'in_plus' => $inPlus];
    }

    // -------------------------------------------------------------------- JSON

    /** Forma scrisă în asteptat.json: chei ordonate, un rând pe valoare, LF. */
    public static function json(array $amprenta): string
    {
        return json_encode(self::ordoneaza($amprenta), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    public static function hash(array $amprenta): string
    {
        return hash('sha256', self::json($amprenta));
    }

    private static function ordoneaza(mixed $valoare): mixed
    {
        if (!is_array($valoare)) {
            return $valoare;
        }
        $lista = $valoare === [] || array_keys($valoare) === range(0, count($valoare) - 1);
        if (!$lista) {
            ksort($valoare);
        }
        foreach ($valoare as $cheie => $element) {
            $valoare[$cheie] = self::ordoneaza($element);
        }
        return $valoare;
    }

    // ------------------------------------------------------------ jetoane SQL

    /**
     * Un fragment SQL în jetoane: [fel, valoare, text]. Felurile: 'sir' (între ghilimele;
     * valoarea fără ele), 'id' (între `), 'grup' (o paranteză întreagă, cu tot ce e în ea),
     * 'cuv' (cuvânt sau număr), 'semn'.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function jetoane(string $sql): array
    {
        $jetoane = [];
        $lungime = strlen($sql);
        $i = 0;
        while ($i < $lungime) {
            $c = $sql[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if ($c === "'" || $c === '"') {
                [$valoare, $sfarsit] = self::citesteSir($sql, $i, $c);
                $jetoane[] = ['sir', $valoare, substr($sql, $i, $sfarsit - $i)];
                $i = $sfarsit;
                continue;
            }
            if ($c === '`') {
                $sfarsit = strpos($sql, '`', $i + 1);
                $sfarsit = $sfarsit === false ? $lungime : $sfarsit + 1;
                $jetoane[] = ['id', substr($sql, $i + 1, $sfarsit - $i - 2), substr($sql, $i, $sfarsit - $i)];
                $i = $sfarsit;
                continue;
            }
            if ($c === '(') {
                $adancime = 0;
                $j = $i;
                while ($j < $lungime) {
                    $cj = $sql[$j];
                    if ($cj === "'" || $cj === '"') {
                        $j = self::citesteSir($sql, $j, $cj)[1];
                        continue;
                    }
                    if ($cj === '(') {
                        $adancime++;
                    } elseif ($cj === ')') {
                        $adancime--;
                        if ($adancime === 0) {
                            $j++;
                            break;
                        }
                    }
                    $j++;
                }
                $text = substr($sql, $i, $j - $i);
                $jetoane[] = ['grup', $text, $text];
                $i = $j;
                continue;
            }
            if (preg_match('/\G[A-Za-z0-9_$.]+/', $sql, $m, 0, $i) === 1) {
                // b'0101' și x'1F' rămân un singur jeton.
                if (in_array(strtolower($m[0]), ['b', 'x'], true) && ($sql[$i + 1] ?? '') === "'") {
                    [, $sfarsit] = self::citesteSir($sql, $i + 1, "'");
                    $text = substr($sql, $i, $sfarsit - $i);
                    $jetoane[] = ['cuv', $text, $text];
                    $i = $sfarsit;
                    continue;
                }
                $jetoane[] = ['cuv', $m[0], $m[0]];
                $i += strlen($m[0]);
                continue;
            }
            $jetoane[] = ['semn', $c, $c];
            $i++;
        }
        return $jetoane;
    }

    /** @return array{0: string, 1: int} valoarea și poziția de după ghilimeaua de închidere */
    private static function citesteSir(string $sql, int $start, string $ghilimea): array
    {
        $valoare = '';
        $lungime = strlen($sql);
        $i = $start + 1;
        while ($i < $lungime) {
            $c = $sql[$i];
            if ($c === '\\' && $i + 1 < $lungime) {
                $valoare .= $sql[$i + 1];
                $i += 2;
                continue;
            }
            if ($c === $ghilimea) {
                if (($sql[$i + 1] ?? '') === $ghilimea) {
                    $valoare .= $ghilimea;
                    $i += 2;
                    continue;
                }
                return [$valoare, $i + 1];
            }
            $valoare .= $c;
            $i++;
        }
        return [$valoare, $lungime];
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $jetoane
     * @return list<list<array{0: string, 1: string, 2: string}>>
     */
    private static function impartePeVirgule(array $jetoane): array
    {
        $bucati = [[]];
        foreach ($jetoane as $jeton) {
            if ($jeton[0] === 'semn' && $jeton[1] === ',') {
                $bucati[] = [];
                continue;
            }
            $bucati[count($bucati) - 1][] = $jeton;
        }
        return $bucati;
    }

    private static function faraGhilimele(string $text): string
    {
        return self::citesteSir($text, 0, "'")[0];
    }

    private static function minusculeInAfaraTextelor(string $text): string
    {
        $rezultat = '';
        $lungime = strlen($text);
        $i = 0;
        while ($i < $lungime) {
            $c = $text[$i];
            if ($c === "'") {
                $sfarsit = self::citesteSir($text, $i, "'")[1];
                $rezultat .= substr($text, $i, $sfarsit - $i);
                $i = $sfarsit;
                continue;
            }
            $rezultat .= strtolower($c);
            $i++;
        }
        return $rezultat;
    }

    private static function faraSpatiiInParanteze(string $text): string
    {
        $rezultat = '';
        $adancime = 0;
        $lungime = strlen($text);
        $i = 0;
        while ($i < $lungime) {
            $c = $text[$i];
            if ($c === "'") {
                $sfarsit = self::citesteSir($text, $i, "'")[1];
                $rezultat .= substr($text, $i, $sfarsit - $i);
                $i = $sfarsit;
                continue;
            }
            if ($c === '(') {
                $adancime++;
            } elseif ($c === ')') {
                $adancime--;
            }
            if (!($adancime > 0 && ctype_space($c))) {
                $rezultat .= $c;
            }
            $i++;
        }
        return $rezultat;
    }
}
