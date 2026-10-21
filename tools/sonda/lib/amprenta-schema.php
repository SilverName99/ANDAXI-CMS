<?php

declare(strict_types=1);

/**
 * Sonda schemei (tools/sonda/amprenta-schema.php, WP-10, MIG-08). Rândurile din
 * information_schema au exact forma lui App\Support\Migrari\Amprenta::citesteBrut() (plus
 * poziția, colația și expresia coloanelor generate, pe care normalizarea le ignoră), ca
 * Amprenta::normalizeaza() să le aducă la aceeași formă ca amprenta oricărei baze locale.
 * Garanțiile citirii: capul lui tools/verificare/lib/live.php.
 */

namespace Unelte\Verificare;

use PDOException;
use Throwable;

require_once dirname(__DIR__, 2) . '/verificare/lib/agregate.php';

final class AmprentaSchema
{
    public const FORMAT = 1;
    /** Peste atâtea rânduri estimate, un tabel nu se numără exact (doar estimarea serverului). */
    public const RANDURI_NUMARATE_MAXIM = 200000;

    /** Variabilele serverului citite (globale: ce primește orice conexiune nouă, deci și runner-ul migrărilor). */
    private const VARIABILE = [
        'sql_mode' => '@@GLOBAL.sql_mode', 'character_set_database' => '@@character_set_database', 'collation_database' => '@@collation_database',
        'character_set_server' => '@@GLOBAL.character_set_server', 'collation_server' => '@@GLOBAL.collation_server',
        'lock_wait_timeout' => '@@GLOBAL.lock_wait_timeout', 'innodb_lock_wait_timeout' => '@@GLOBAL.innodb_lock_wait_timeout',
        'innodb_default_row_format' => '@@GLOBAL.innodb_default_row_format', 'default_storage_engine' => '@@GLOBAL.default_storage_engine',
        'max_allowed_packet' => '@@GLOBAL.max_allowed_packet', 'lower_case_table_names' => '@@lower_case_table_names',
        'time_zone' => '@@GLOBAL.time_zone', 'system_time_zone' => '@@system_time_zone', 'version_comment' => '@@version_comment',
        'explicit_defaults_for_timestamp' => '@@GLOBAL.explicit_defaults_for_timestamp', 'innodb_file_per_table' => '@@GLOBAL.innodb_file_per_table',
        'max_statement_time' => '@@GLOBAL.max_statement_time', 'alter_algorithm' => '@@GLOBAL.alter_algorithm',
        'max_execution_time' => '@@GLOBAL.max_execution_time', 'wait_timeout' => '@@GLOBAL.wait_timeout',
    ];

    /** Drepturile care se pot scrie (orice alt cuvânt devine ALTUL). */
    private const DREPTURI = ['ALL PRIVILEGES', 'USAGE', 'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX', 'REFERENCES',
        'LOCK TABLES', 'CREATE TEMPORARY TABLES', 'EXECUTE', 'CREATE VIEW', 'SHOW VIEW', 'CREATE ROUTINE', 'ALTER ROUTINE', 'EVENT', 'TRIGGER',
        'DELETE HISTORY', 'PROCESS', 'RELOAD', 'SHOW DATABASES', 'SUPER', 'FILE', 'GRANT OPTION', 'REPLICATION CLIENT', 'REPLICATION SLAVE',
        'BINLOG MONITOR', 'SLAVE MONITOR', 'CREATE USER', 'SHUTDOWN', 'CREATE TABLESPACE'];

    /** @param list<string> $argv */
    public static function ruleaza(array $argv, string $radacina): int
    {
        set_error_handler(static function (int $nivel, string $mesaj, string $fisier, int $linie): bool {
            if ((error_reporting() & $nivel) === 0) {
                return false;
            }
            throw new \ErrorException('', 0, $nivel, $fisier, $linie);
        });
        try {
            Rulare::faraSesiune();
            $argumente = Rulare::argumente($argv, ['site', 'dosar', 'stdout', 'buget']);
            $nume = is_string($argumente['site'] ?? null) ? $argumente['site'] : '';
            if (!isset(Locul::SITE_URI[$nume])) {
                throw new Refuz('lipsește --site=<' . implode('|', array_keys(Locul::SITE_URI)) . '> (vezi capul fișierului)');
            }
            $buget = Agregate::BUGET_IMPLICIT;
            if (isset($argumente['buget'])) {
                if (!is_string($argumente['buget']) || preg_match('/^\d{1,4}$/', $argumente['buget']) !== 1 || (int) $argumente['buget'] > Agregate::BUGET_MAXIM) {
                    throw new Refuz('--buget=<secunde>, între 0 și ' . Agregate::BUGET_MAXIM);
                }
                $buget = (int) $argumente['buget'];
            }
            $peEcran = isset($argumente['stdout']);
            [$acasa] = Locul::verificare($radacina);
            $dosarDat = isset($argumente['dosar']) && is_string($argumente['dosar']) ? $argumente['dosar'] : null;
            $site = SiteLive::gaseste($nume, $dosarDat, $acasa, $radacina);
            $citire = CitireLive::deschide($site);
        } catch (Refuz $e) {
            restore_error_handler();
            fwrite(STDERR, 'REFUZAT: ' . $e->getMessage() . "\n");
            return 2;
        } catch (Throwable $e) {
            restore_error_handler();
            fwrite(STDERR, 'REFUZAT: ' . Agregate::descrieEroarea($e) . "\n");
            return 2;
        }

        $start = microtime(true);
        $erori = [];
        // O eroare fatală (lib/intrare.php): amprenta ar fi parțială, deci nu se scrie.
        Rulare::laOprire(static function (string $motiv): int {
            fwrite(STDERR, 'OPRIT: ' . $motiv . '; amprenta nu s-a scris (ar fi parțială). Rulează din nou, cu --buget mai mic sau mai târziu.' . "\n");
            return 1;
        });
        $date = [
            'format' => self::FORMAT,
            'unealta' => 'amprenta-schema',
            'site' => ['nume' => $nume, 'cod' => Locul::SITE_URI[$nume]['cod'], 'gazda' => $site->gazda, 'dosar' => $site->dosarRelativ],
            'generat_la' => date('c'),
            'php' => PHP_VERSION,
            'server' => ['familie' => $citire->familie, 'versiune' => $citire->versiune],
            'cod' => $site->cod(),
        ];
        $parti = [
            'server' => static fn (): array => self::server($citire),
            'privilegii' => static fn (): array => self::privilegii($citire),
            'obiecte' => static fn (): array => self::obiecte($citire),
            'brut' => static fn (): array => self::brut($citire),
            'setari_chei' => static fn (): array => self::setariChei($citire),
        ];
        foreach ($parti as $parte => $citeste) {
            if (microtime(true) - $start >= $buget) {
                $erori[] = $parte . ': bugetul de timp s-a terminat';
                continue;
            }
            try {
                $rezultat = $citire->inTranzactie($citeste);
                $date[$parte] = $parte === 'server' ? $date['server'] + $rezultat : $rezultat;
            } catch (Throwable $e) {
                $erori[] = $parte . ': ' . self::motiv($e);
            }
        }
        $tabele = [];
        foreach ($date['brut']['tabele'] ?? [] as $t) {
            $tabele[] = (string) $t['tabel'];
        }
        $date['randuri'] = self::randuri($citire, $date['brut']['tabele'] ?? [], $start, $buget, $erori);
        try {
            $date['agregate'] = $citire->inTranzactie(static fn (): array => self::agregate($citire, $tabele));
        } catch (Throwable $e) {
            $erori[] = 'agregate: ' . self::motiv($e);
        }
        $date['interogari'] = $citire->interogari;
        $date['durata_s'] = (int) round(microtime(true) - $start);
        $date['erori'] = $erori;
        restore_error_handler();
        Rulare::laOprire(null);

        $json = (string) json_encode($date, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        if ($peEcran) {
            echo $json;
            foreach ($erori as $eroare) {
                fwrite(STDERR, 'ATENȚIE: ' . $eroare . "\n");
            }
            return $erori === [] ? 0 : 1;
        }
        $brut = $date['brut'] ?? [];
        $indecsi = [];
        foreach ($brut['indecsi'] ?? [] as $i) {
            $indecsi[$i['tabel'] . "\0" . $i['index']] = true;
        }
        $fk = [];
        foreach ($brut['fk'] ?? [] as $f) {
            $fk[$f['tabel'] . "\0" . $f['nume']] = true;
        }
        echo 'ANDAXI-CMS — sonda schemei (WP-10, MIG-08), site-ul ' . $nume . ' (' . $site->gazda . ', ' . $site->dosarRelativ . ')' . "\n";
        echo ($citire->familie === 'mariadb' ? 'MariaDB' : 'MySQL') . ' ' . $citire->versiune . '; ' . count($tabele) . ' tabele, ' . count($brut['coloane'] ?? [])
            . ' coloane, ' . count($indecsi) . ' indecși, ' . count($fk) . ' chei străine; vederi ' . (int) ($date['obiecte']['vederi'] ?? 0)
            . ', declanșatoare ' . (int) ($date['obiecte']['declansatoare'] ?? 0) . ', rutine ' . (int) ($date['obiecte']['rutine'] ?? 0)
            . '; setări: ' . count($date['setari_chei']['chei'] ?? []) . ' chei; ' . $citire->interogari . ' interogări, ' . $date['durata_s'] . ' s' . "\n";
        foreach ($erori as $eroare) {
            echo '  EROARE  ' . $eroare . "\n";
        }
        try {
            $cale = Rulare::salveaza('amprenta-' . $nume . '-' . date('Ymd-His') . '.json', $json);
        } catch (Refuz $e) {
            fwrite(STDERR, 'EROARE: ' . $e->getMessage() . "\n");
            return 1;
        }
        echo ($erori === [] ? 'GATA' : 'PARȚIAL') . ': amprenta e în ' . $cale . ' (doar structură și numere): trimite-ne fișierul.' . "\n";
        return $erori === [] ? 0 : 1;
    }

    private static function motiv(Throwable $e): string
    {
        if ($e instanceof PDOException) {
            return CitireLive::eTimpDepasit($e) ? 'timpul depășit' : CitireLive::cod($e);
        }
        if ($e instanceof InstructiuneRefuzata) {
            return 'instrucțiune refuzată: ' . $e->getMessage();
        }
        return Agregate::descrieEroarea($e);
    }

    /** Un text de configurare al serverului (sql_mode, colații…), sau un semn că nu se scrie. */
    private static function text(mixed $valoare): ?string
    {
        if ($valoare === null) {
            return null;
        }
        $text = (string) $valoare;
        return preg_match('/^[A-Za-z0-9_.,:+ ()\/-]{0,2000}$/', $text) === 1 ? $text : '(valoare nestandard)';
    }

    /** Un nume de structură (tabel, coloană, index) care se poate scrie așa cum e. */
    private static function numeBun(string $nume): bool
    {
        return preg_match('/^[A-Za-z0-9_$#@.-]{1,64}$/', $nume) === 1;
    }

    /** @return array<string, ?string> */
    private static function server(CitireLive $citire): array
    {
        $rezultat = [];
        foreach (self::VARIABILE as $cheie => $variabila) {
            try {
                $rezultat[$cheie] = self::text($citire->valoare('SELECT ' . $variabila . ' AS v LIMIT 1'));
            } catch (PDOException) {
                $rezultat[$cheie] = null; // variabila nu există pe acest server
            }
        }
        return $rezultat;
    }

    /**
     * Drepturile utilizatorului bazei, din SHOW GRANTS: doar numele drepturilor, globale și pe
     * bază; numele bazei, al utilizatorului și orice parolă din rând nu se păstrează.
     *
     * @return array{global: list<string>, baza: list<string>, tabele_cu_drepturi: int, roluri: int}
     */
    private static function privilegii(CitireLive $citire): array
    {
        $rezultat = ['global' => [], 'baza' => [], 'tabele_cu_drepturi' => 0, 'roluri' => 0];
        foreach ($citire->randuri('SHOW GRANTS') as $rand) {
            $text = (string) reset($rand);
            if (preg_match('/^GRANT\s+PROXY\s+ON\s/i', $text) === 1) {
                continue; // dreptul de a lucra în numele altui utilizator, nu un drept pe tabele
            }
            if (preg_match('/^GRANT\s+(.+?)\s+ON\s+(\S+)\s+TO\s/i', $text, $m) !== 1) {
                if (preg_match('/^GRANT\s/i', $text) === 1) {
                    $rezultat['roluri']++;
                }
                continue;
            }
            $tinta = str_replace('`', '', $m[2]);
            $fel = $tinta === '*.*' ? 'global' : (str_ends_with($tinta, '.*') ? 'baza' : 'tabel');
            if ($fel === 'tabel') {
                $rezultat['tabele_cu_drepturi']++;
                continue;
            }
            $drepturi = preg_replace('/\([^)]*\)/', '', $m[1]) ?? '';
            foreach (explode(',', $drepturi) as $drept) {
                $drept = strtoupper(trim(preg_replace('/\s+/', ' ', $drept) ?? ''));
                $drept = in_array($drept, self::DREPTURI, true) ? $drept : 'ALTUL';
                if (!in_array($drept, $rezultat[$fel], true)) {
                    $rezultat[$fel][] = $drept;
                }
            }
            if (preg_match('/WITH GRANT OPTION/i', $text) === 1 && !in_array('GRANT OPTION', $rezultat[$fel], true)) {
                $rezultat[$fel][] = 'GRANT OPTION';
            }
        }
        sort($rezultat['global']);
        sort($rezultat['baza']);
        return $rezultat;
    }

    /** @return array<string, int> */
    private static function obiecte(CitireLive $citire): array
    {
        return [
            'vederi' => $citire->numar('SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() LIMIT 1'),
            'declansatoare' => $citire->numar('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() LIMIT 1'),
            'rutine' => $citire->numar('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() LIMIT 1'),
            'evenimente' => $citire->numar('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE() LIMIT 1'),
        ];
    }

    /**
     * Structura, în forma lui Amprenta::citesteBrut (cu câmpuri în plus: mărimile tabelelor;
     * poziția, colația și expresia generată a coloanelor).
     *
     * @return array{tabele: list<array<string, mixed>>, coloane: list<array<string, mixed>>, indecsi: list<array<string, mixed>>, fk: list<array<string, mixed>>, nume_nestandard: int}
     */
    private static function brut(CitireLive $citire): array
    {
        $nestandard = 0;
        $bune = static function (array $randuri, array $campuri) use (&$nestandard): array {
            $rezultat = [];
            foreach ($randuri as $r) {
                foreach ($campuri as $camp) {
                    if ($r[$camp] !== null && !self::numeBun((string) $r[$camp])) {
                        $nestandard++;
                        continue 2;
                    }
                }
                $rezultat[] = $r;
            }
            return $rezultat;
        };
        $tabele = $bune($citire->randuri("SELECT TABLE_NAME AS tabel, ENGINE AS motor, TABLE_COLLATION AS colatie, TABLE_ROWS AS randuri_estimate,
                DATA_LENGTH AS octeti_date, INDEX_LENGTH AS octeti_index, ROW_FORMAT AS format_rand
            FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME LIMIT 5000"), ['tabel']);
        foreach ($tabele as &$t) {
            foreach (['randuri_estimate', 'octeti_date', 'octeti_index'] as $camp) {
                $t[$camp] = $t[$camp] === null ? null : (int) $t[$camp];
            }
            $t['motor'] = self::text($t['motor']);
            $t['colatie'] = self::text($t['colatie']);
            $t['format_rand'] = self::text($t['format_rand']);
        }
        unset($t);
        $coloane = $bune($citire->randuri('SELECT TABLE_NAME AS tabel, COLUMN_NAME AS coloana, COLUMN_TYPE AS tip, IS_NULLABLE AS nul, COLUMN_DEFAULT AS implicit,
                EXTRA AS extra, ORDINAL_POSITION AS pozitie, COLLATION_NAME AS colatie, GENERATION_EXPRESSION AS expresie_generata
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION LIMIT 50000'), ['tabel', 'coloana']);
        foreach ($coloane as &$c) {
            $c['pozitie'] = (int) $c['pozitie'];
            foreach (['implicit', 'tip', 'expresie_generata'] as $camp) {
                // Structură scrisă de cod (tipuri, enum-uri, valori implicite), cu lungimea tăiată și fără caractere de control.
                $c[$camp] = $c[$camp] === null ? null : mb_substr((string) preg_replace('/[\x00-\x1f\x7f]/', ' ', (string) $c[$camp]), 0, 1000);
            }
            $c['colatie'] = self::text($c['colatie']);
            if ($c['expresie_generata'] === '') {
                $c['expresie_generata'] = null;
            }
        }
        unset($c);
        $expresie = $citire->numar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'information_schema'
            AND TABLE_NAME = 'STATISTICS' AND COLUMN_NAME = 'EXPRESSION' LIMIT 1") > 0;
        $indecsi = $bune($citire->randuri('SELECT TABLE_NAME AS tabel, INDEX_NAME AS `index`, NON_UNIQUE AS non_unic, SEQ_IN_INDEX AS pozitie,
                COLUMN_NAME AS coloana, SUB_PART AS parte, INDEX_TYPE AS tip, ' . ($expresie ? 'EXPRESSION' : 'NULL') . ' AS expresie
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX LIMIT 50000'), ['tabel', 'index', 'coloana']);
        foreach ($indecsi as &$i) {
            $i['non_unic'] = (int) $i['non_unic'];
            $i['pozitie'] = (int) $i['pozitie'];
            $i['parte'] = $i['parte'] === null ? null : (int) $i['parte'];
            $i['expresie'] = $i['expresie'] === null ? null : mb_substr((string) preg_replace('/[\x00-\x1f\x7f]/', ' ', (string) $i['expresie']), 0, 1000);
        }
        unset($i);
        // Ca Amprenta::chei: două interogări, unite aici (un JOIN în information_schema citește toate bazele serverului).
        $coloaneFk = $bune($citire->randuri('SELECT TABLE_NAME AS tabel, CONSTRAINT_NAME AS cheie_fk, COLUMN_NAME AS coloana, ORDINAL_POSITION AS pozitie,
                REFERENCED_TABLE_NAME AS tabel_referit, REFERENCED_COLUMN_NAME AS coloana_referita
            FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION LIMIT 50000'), ['tabel', 'cheie_fk', 'coloana', 'tabel_referit', 'coloana_referita']);
        $reguli = [];
        foreach ($citire->randuri('SELECT TABLE_NAME AS tabel, CONSTRAINT_NAME AS cheie_fk, UPDATE_RULE AS la_actualizare, DELETE_RULE AS la_stergere
            FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() LIMIT 50000') as $r) {
            $reguli[$r['tabel'] . "\0" . $r['cheie_fk']] = $r;
        }
        $fk = [];
        foreach ($coloaneFk as $r) {
            $regula = $reguli[$r['tabel'] . "\0" . $r['cheie_fk']] ?? null;
            if ($regula === null) {
                continue;
            }
            $fk[] = ['tabel' => $r['tabel'], 'nume' => $r['cheie_fk'], 'coloana' => $r['coloana'], 'pozitie' => (int) $r['pozitie'],
                'tabel_referit' => $r['tabel_referit'], 'coloana_referita' => $r['coloana_referita'],
                'la_actualizare' => self::text($regula['la_actualizare']), 'la_stergere' => self::text($regula['la_stergere'])];
        }
        return ['tabele' => $tabele, 'coloane' => $coloane, 'indecsi' => $indecsi, 'fk' => $fk, 'nume_nestandard' => $nestandard];
    }

    /** Numele cheilor din `settings`, fără valori. @return array{chei: list<string>, chei_nestandard: int} */
    private static function setariChei(CitireLive $citire): array
    {
        if (!$citire->existaColoana('settings', 'key')) {
            return ['chei' => [], 'chei_nestandard' => 0, 'lipsa' => true];
        }
        $chei = [];
        $altfel = 0;
        foreach ($citire->randuri('SELECT `key` AS k FROM settings ORDER BY `key` LIMIT 20000') as $r) {
            if (Raport::potrivit('nume', (string) $r['k'])) {
                $chei[] = (string) $r['k'];
            } else {
                $altfel++;
            }
        }
        return ['chei' => $chei, 'chei_nestandard' => $altfel];
    }

    /**
     * Rândurile exacte ale fiecărui tabel mic (COUNT(*) în tranzacția lui); la tabelele mari
     * doar estimarea serverului (din „tabele”).
     *
     * @param list<array<string, mixed>> $tabele
     * @param list<string> $erori
     * @return array<string, ?int>
     */
    private static function randuri(CitireLive $citire, array $tabele, float $start, int $buget, array &$erori): array
    {
        $rezultat = [];
        foreach ($tabele as $t) {
            $nume = (string) $t['tabel'];
            if (str_starts_with($nume, '#sql') || preg_match('/^[A-Za-z0-9_$]{1,64}$/', $nume) !== 1) {
                continue;
            }
            if ((int) ($t['randuri_estimate'] ?? 0) > self::RANDURI_NUMARATE_MAXIM) {
                $rezultat[$nume] = null;
                continue;
            }
            if (microtime(true) - $start >= $buget) {
                $erori[] = 'randuri: bugetul de timp s-a terminat la ' . $nume;
                break;
            }
            try {
                $rezultat[$nume] = $citire->inTranzactie(static fn (): int => $citire->numar('SELECT COUNT(*) FROM `' . $nume . '` LIMIT 1'));
            } catch (Throwable $e) {
                $rezultat[$nume] = null;
                $erori[] = 'randuri ' . $nume . ': ' . self::motiv($e);
            }
        }
        return $rezultat;
    }

    /**
     * Numerele pe care le citesc migrările de date (MIG-08): rândurile vechi din
     * `reviews` și câte lipsesc din `product_reviews`, galeriile vechi, adminii fără rol,
     * linkurile de plată pe procesator, străzile FAN fără cheie, tabelele de lucru rămase.
     *
     * @param list<string> $tabele
     * @return array<string, mixed>
     */
    private static function agregate(CitireLive $citire, array $tabele): array
    {
        $a = [];
        $are = static fn (string $t, string ...$coloane): bool => array_reduce($coloane, static fn (bool $ok, string $c): bool
            => $ok && $citire->existaColoana($t, $c), $citire->existaTabel($t));
        if ($are('reviews')) {
            $a['reviews_randuri'] = $citire->numar('SELECT COUNT(*) FROM reviews LIMIT 1');
            if ($are('product_reviews', 'product_id', 'user_name', 'rating', 'review_text', 'created_at') && $are('reviews', 'product_id', 'user_name', 'rating', 'review_text', 'created_at')) {
                $a['reviews_lipsa_din_product_reviews'] = $citire->numar('SELECT COUNT(*) FROM reviews r LEFT JOIN product_reviews pr
                    ON pr.product_id = r.product_id AND pr.user_name = r.user_name AND pr.rating = r.rating
                    AND ((pr.review_text IS NULL AND r.review_text IS NULL) OR pr.review_text = r.review_text) AND pr.created_at = r.created_at
                    WHERE pr.id IS NULL LIMIT 1');
            }
        }
        foreach (['gallery_json', 'image_gallery_json'] as $veche) {
            if ($are('products', $veche, 'gallery_images_json')) {
                $a['produse_' . $veche . '_fara_gallery_images_json'] = $citire->numar('SELECT COUNT(*) FROM products WHERE `' . $veche . "` IS NOT NULL AND `" . $veche
                    . "` <> '' AND (gallery_images_json IS NULL OR gallery_images_json = '') LIMIT 1");
            }
        }
        if ($are('admins', 'role')) {
            $a['admini_fara_rol'] = $citire->numar("SELECT COUNT(*) FROM admins WHERE role IS NULL OR role = '' LIMIT 1");
        }
        if ($are('admins', 'roles_json')) {
            $a['admini_roles_json_null'] = $citire->numar('SELECT COUNT(*) FROM admins WHERE roles_json IS NULL LIMIT 1');
        }
        if ($are('order_payment_links', 'procesator')) {
            $grupuri = [];
            foreach ($citire->randuri('SELECT procesator AS g, COUNT(*) AS n FROM order_payment_links GROUP BY procesator LIMIT 50') as $r) {
                $g = strtolower(trim((string) $r['g']));
                $g = $g === '' ? 'gol' : (preg_match('/^[a-z0-9_-]{1,40}$/', $g) === 1 ? $g : 'alta_valoare');
                $grupuri[$g] = ($grupuri[$g] ?? 0) + (int) $r['n'];
            }
            ksort($grupuri);
            $a['linkuri_plata_pe_procesator'] = $grupuri;
        } elseif ($are('order_payment_links')) {
            $a['linkuri_plata_total'] = $citire->numar('SELECT COUNT(*) FROM order_payment_links LIMIT 1');
        }
        if ($are('fan_streets', 'row_key')) {
            $a['fan_streets_row_key_gol'] = $citire->numar("SELECT COUNT(*) FROM fan_streets WHERE row_key = '' LIMIT 1");
        }
        $a['tabele_sync_vechi'] = count(array_filter($tabele, static fn (string $t): bool => str_ends_with($t, '__sync') || str_ends_with($t, '__vechi')));
        $a['tabele_sql_ramase'] = count(array_filter($tabele, static fn (string $t): bool => str_starts_with($t, '#sql')));
        return $a;
    }
}
