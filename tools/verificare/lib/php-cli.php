<?php

declare(strict_types=1);

/**
 * PHP-ul liniei de comandă al găzduirii și extensiile lui (docs/VERIFICARE.md, „Dacă ceva nu merge”). Pe Hostinger
 * (21 oct 2026, site-ul de verificare) `/opt/alt/php83/usr/bin/php` nu încarcă sodium, deși modulul lui e pe disc
 * (`/opt/alt/php83/usr/lib64/php/modules/sodium.so`); `-d extension=sodium.so` îl încarcă. Bifa din hPanel → PHP
 * Configuration → PHP extensions schimbă doar PHP-ul site-ului (web). De aici trei lucruri, pentru toate uneltele de pe server:
 *
 * - **refuzul** unei unelte căreia îi lipsește o extensie cerută (`lipsa()`): dacă modulul ei e în `extension_dir`-ul
 *   PHP-ului, reparația exactă, `PHP="<PHP_BINARY> -d extension=sodium.so"` și aceeași comandă cu `$PHP`; altfel hPanel;
 * - **comanda de dat mai departe** („Pasul următor”, „Continuă cu”; `comanda()`): `PHP_BINARY <unealta>` sau, dacă procesul
 *   de acum are o extensie doar prin `-d` (PHP_BINARY pornit simplu n-o are), `PHP="<PHP_BINARY> -d …"; $PHP <unealta>`;
 * - **procesele copil** pornite cu PHP_BINARY sau cu alt PHP (lucrătorii și migrările comparației calculelor,
 *   `lib/calcule.php`): `completeaza()` dă argumentele `-d extension=<nume>.so` pentru extensiile din `EXTENSII` pe care
 *   procesul de acum le are, iar PHP-ul copilului, pornit simplu, nu (după o sondă a lui, o dată pe PHP și pe mediu).
 *
 * Doar extensiile din `EXTENSII` (cele de care au nevoie aplicația și uneltele), în ordinea în care se încarcă: mysqlnd și
 * PDO înaintea lui pdo_mysql. Un modul se caută doar în `extension_dir` (`<nume>.so`). Se încarcă după verificarea
 * versiunii PHP (sintaxa PHP 8.3, ca `lib/cont.php`). Nu tipărește nimic singur.
 */

namespace Unelte\Verificare;

final class PhpCli
{
    /** Extensiile urmărite, în ordinea în care se încarcă (o extensie după cele de care are nevoie). */
    public const EXTENSII = ['mysqlnd', 'pdo', 'pdo_mysql', 'sodium', 'mbstring', 'intl', 'gd', 'curl', 'openssl', 'zip'];

    /** De ce mai are nevoie o extensie ca să se încarce cu `-d` (pdo_mysql: driverul mysqlnd și PDO). */
    private const NEVOI = ['pdo_mysql' => ['mysqlnd', 'pdo']];

    /** Rândul sondei începe cu acest marcaj (PHP poate tipări înaintea lui avertismentele de pornire). */
    private const MARCAJ = '@@php-cli@@';

    /** @var array<string, array{dir: string, ext: array<string, bool>}|null> sondele făcute (comanda → rezultatul) */
    private static array $sonde = [];

    /** Codul sondei, pentru `php -r`: `extension_dir` și care extensii din `EXTENSII` sunt încărcate, ca JSON, pe un rând. */
    public static function codSonda(): string
    {
        return '$e = [];'
            . ' foreach (' . var_export(self::EXTENSII, true) . ' as $x) { $e[$x] = extension_loaded($x); }'
            . ' echo "\n' . self::MARCAJ . '", json_encode(["dir" => (string) ini_get("extension_dir"), "ext" => $e]), "\n";';
    }

    /**
     * Rezultatul sondei din ieșirea ei (null dacă rândul lipsește sau nu se înțelege).
     *
     * @return array{dir: string, ext: array<string, bool>}|null
     */
    public static function citesteSonda(string $iesire): ?array
    {
        $pozitie = strrpos($iesire, self::MARCAJ);
        if ($pozitie === false) {
            return null;
        }
        $rand = strtok(substr($iesire, $pozitie + strlen(self::MARCAJ)), "\n");
        $date = json_decode(is_string($rand) ? $rand : '', true);
        if (!is_array($date) || !is_string($date['dir'] ?? null) || !is_array($date['ext'] ?? null)) {
            return null;
        }
        $ext = [];
        foreach ($date['ext'] as $nume => $are) {
            if (is_string($nume) && preg_match('/^[a-z0-9_]{1,40}$/', $nume) === 1) {
                $ext[$nume] = $are === true;
            }
        }
        return ['dir' => $date['dir'], 'ext' => $ext];
    }

    /**
     * Sonda unui PHP (comanda: executabilul și eventualele `-d`), pornită în mediul procesului de acum, fără shell dacă se
     * poate (proc_open), altfel cu exec. Null dacă găzduirea nu lasă PHP-ul să pornească alte procese sau sonda n-a răspuns.
     * O dată pe comandă.
     *
     * @param list<string> $comanda
     * @return array{dir: string, ext: array<string, bool>}|null
     */
    public static function sondeaza(array $comanda): ?array
    {
        $cheie = implode("\0", $comanda);
        if (array_key_exists($cheie, self::$sonde)) {
            return self::$sonde[$cheie];
        }
        $comanda[] = '-r';
        $comanda[] = self::codSonda();
        $iesire = null;
        if (self::pornita('proc_open')) {
            $proces = @proc_open($comanda, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tevi);
            if (is_resource($proces)) {
                fclose($tevi[0]);
                $iesire = (string) stream_get_contents($tevi[1]);
                fclose($tevi[1]);
                stream_get_contents($tevi[2]);
                fclose($tevi[2]);
                proc_close($proces);
            }
        } elseif (self::pornita('exec')) {
            $linii = [];
            @exec(implode(' ', array_map('escapeshellarg', $comanda)) . ' 2>/dev/null', $linii);
            $iesire = implode("\n", $linii);
        }
        return self::$sonde[$cheie] = is_string($iesire) ? self::citesteSonda($iesire) : null;
    }

    /** @return array<string, bool> extensiile din `EXTENSII`, încărcate sau nu în procesul de acum */
    public static function incarcateAici(): array
    {
        $ext = [];
        foreach (self::EXTENSII as $nume) {
            $ext[$nume] = extension_loaded($nume);
        }
        return $ext;
    }

    /** Fișierul modulului extensiei e în dosarul dat (`extension_dir`)? */
    public static function modul(string $extensie, string $dosar): bool
    {
        return $dosar !== '' && preg_match('/^[a-z0-9_]{1,40}$/', $extensie) === 1 && is_file(rtrim($dosar, '/') . '/' . $extensie . '.so');
    }

    /**
     * Ce trebuie dat unui PHP ca să aibă extensiile `$dorite`, după sonda lui: `-d extension=<nume>.so` pentru fiecare pe care
     * nu o are și al cărei modul e în `extension_dir`-ul lui (cu nevoile ei înainte, NEVOI), în ordinea `EXTENSII` (altele
     * după ele). `lipsa`: cele care nu se pot aduce așa (fără modul).
     *
     * @param array{dir: string, ext: array<string, bool>} $sonda
     * @param list<string> $dorite
     * @return array{argumente: list<string>, adaugate: list<string>, lipsa: list<string>}
     */
    public static function completeaza(array $sonda, array $dorite): array
    {
        $are = static fn (string $nume): bool => !empty($sonda['ext'][$nume]);
        $deAdus = [];
        foreach ($dorite as $nume) {
            if ($are($nume)) {
                continue;
            }
            foreach (self::NEVOI[$nume] ?? [] as $nevoie) {
                if (!$are($nevoie)) {
                    $deAdus[$nevoie] = true;
                }
            }
            $deAdus[$nume] = true;
        }
        $ordine = array_values(array_unique(array_merge(array_values(array_intersect(self::EXTENSII, array_keys($deAdus))), array_keys($deAdus))));
        $rezultat = ['argumente' => [], 'adaugate' => [], 'lipsa' => []];
        foreach ($ordine as $nume) {
            if (self::modul($nume, $sonda['dir'])) {
                $rezultat['argumente'][] = '-d';
                $rezultat['argumente'][] = 'extension=' . $nume . '.so';
                $rezultat['adaugate'][] = $nume;
            } else {
                $rezultat['lipsa'][] = $nume;
            }
        }
        return $rezultat;
    }

    /**
     * Argumentele unui proces copil pornit cu PHP-ul dat (sonda lui), ca să aibă extensiile procesului de acum.
     *
     * @param array{dir: string, ext: array<string, bool>} $sonda
     * @return array{argumente: list<string>, adaugate: list<string>, lipsa: list<string>}
     */
    public static function pentruCopil(array $sonda): array
    {
        return self::completeaza($sonda, array_keys(array_filter(self::incarcateAici())));
    }

    /**
     * PHP-ul cu care se dau comenzile, dacă PHP_BINARY singur nu e de ajuns: `<PHP_BINARY> -d extension=<nume>.so …` pentru
     * extensiile pe care procesul de acum le are (și cele din `$lipsaAici`, cu modulul pe server) iar PHP_BINARY pornit simplu
     * nu. Null: PHP_BINARY e de ajuns. Dacă PHP_BINARY nu se poate întreba (procesele oprite), doar cele din `$lipsaAici`.
     *
     * @param list<string> $lipsaAici
     * @return array{forma: ?string, adaugate: list<string>, lipsa: list<string>, dosar: string}
     */
    public static function reparatie(array $lipsaAici = []): array
    {
        $sonda = PHP_BINARY !== '' ? self::sondeaza([PHP_BINARY]) : null;
        if ($sonda === null) {
            $sonda = ['dir' => (string) ini_get('extension_dir'), 'ext' => self::incarcateAici()];
        }
        $c = self::completeaza($sonda, array_merge(array_keys(array_filter(self::incarcateAici())), $lipsaAici));
        return [
            'forma' => $c['argumente'] === [] ? null : PHP_BINARY . ' ' . implode(' ', $c['argumente']),
            'adaugate' => $c['adaugate'],
            'lipsa' => $c['lipsa'],
            'dosar' => rtrim($sonda['dir'], '/'),
        ];
    }

    /** Comanda de dat mai departe: `PHP_BINARY <unealta>` sau, dacă PHP-ul are nevoie de `-d`, `PHP="…"; $PHP <unealta>`. */
    public static function comanda(string $unealta): string
    {
        $forma = self::reparatie()['forma'];
        return $forma === null ? PHP_BINARY . ' ' . $unealta : 'PHP="' . $forma . '"; $PHP ' . $unealta;
    }

    /**
     * Textul refuzului unei unelte căreia îi lipsesc extensii dintre `$cerute` (null: nu lipsește niciuna). Când modulul
     * fiecăreia e în `extension_dir`, reparația exactă, cu `$unealta` (calea ei de sub dosarul site-ului); altfel hPanel.
     *
     * @param list<string> $cerute
     */
    public static function lipsa(array $cerute, string $unealta): ?string
    {
        $lipsa = array_values(array_filter($cerute, static fn (string $e): bool => !extension_loaded($e)));
        if ($lipsa === []) {
            return null;
        }
        $nume = (count($lipsa) === 1 ? 'lipsește extensia PHP ' : 'lipsesc extensiile PHP ') . implode(', ', $lipsa);
        $r = self::reparatie($lipsa);
        if ($r['forma'] === null || array_intersect($lipsa, $r['lipsa']) !== []) {
            return $nume . ' (hPanel → PHP Configuration → PHP extensions; sau alt PHP din /opt/alt)';
        }
        $module = implode(', ', array_map(static fn (string $e): string => $r['dosar'] . '/' . $e . '.so', $lipsa));
        $unul = count($lipsa) === 1;
        return $nume . ' din PHP-ul liniei de comandă, deși ' . ($unul ? 'modulul ei e' : 'modulele lor sunt') . ' pe server (' . $module
            . '): PHP-ul acesta nu ' . ($unul ? 'îl' : 'le') . ' încarcă singur. Pornește-l cu ' . ($unul ? 'el' : 'ele') . ': PHP="' . $r['forma']
            . '", apoi aceeași comandă cu $PHP (de exemplu $PHP ' . $unealta . ')';
    }

    /** Funcția e pornită pe server (nu e în disable_functions)? */
    private static function pornita(string $functie): bool
    {
        $oprite = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return function_exists($functie) && !in_array($functie, $oprite, true);
    }
}
