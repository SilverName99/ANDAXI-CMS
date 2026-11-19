<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress;

/**
 * Datele de conectare ale unui site WordPress, citite din `wp-config.php` FĂRĂ să-l ruleze (docs/DEPLOY.md, „Adoptarea
 * unui site WordPress”): fișierul se desface în jetoane PHP (`token_get_all`) și se iau doar `define('DB_…', '…')`,
 * `$table_prefix = '…'` și câteva constante cu căi (`WP_CONTENT_DIR`, `UPLOADS`), scrise ca text simplu. O valoare
 * calculată (o funcție, o variabilă, o concatenare) nu se ghicește: se spune ce lipsește, iar importul nu pornește.
 *
 * Parola bazei nu iese niciodată din această clasă în vreun mesaj, jurnal sau raport (`fara()` o ascunde).
 */
final class ConfigWordPress
{
    /** Constantele citite (afară de ele, nimic din wp-config.php nu contează). */
    private const CONSTANTE = ['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'DB_CHARSET', 'WP_HOME', 'WP_SITEURL', 'WP_CONTENT_DIR', 'UPLOADS'];

    /**
     * @param array<string, string> $constante
     */
    private function __construct(
        public readonly string $dosar,
        public readonly array $constante,
        public readonly string $prefix,
    ) {
    }

    /**
     * Citește `wp-config.php` din rădăcina WordPress (sau din dosarul de deasupra ei, cum permite WordPress).
     *
     * @throws EroareImport dacă fișierul lipsește sau nu are datele bazei scrise simplu
     */
    public static function citeste(string $dosarWp): self
    {
        $dosarWp = rtrim($dosarWp, '/');
        $fisier = null;
        foreach ([$dosarWp . '/wp-config.php', dirname($dosarWp) . '/wp-config.php'] as $f) {
            // WordPress acceptă wp-config.php și un nivel mai sus, doar dacă acolo nu e alt WordPress.
            if (is_file($f) && ($f === $dosarWp . '/wp-config.php' || !is_file(dirname($dosarWp) . '/wp-settings.php'))) {
                $fisier = $f;
                break;
            }
        }
        if ($fisier === null) {
            throw new EroareImport('nu găsesc wp-config.php în ' . $dosarWp . ' (nici un nivel mai sus)');
        }
        $text = (string) @file_get_contents($fisier);
        if ($text === '') {
            throw new EroareImport('nu pot citi ' . $fisier);
        }
        [$constante, $prefix] = self::desface($text);
        foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST'] as $c) {
            if (!array_key_exists($c, $constante)) {
                throw new EroareImport($fisier . ' nu are ' . $c . ' scris ca text simplu (define(\'' . $c . '\', \'…\')); '
                    . 'un wp-config.php cu valori calculate nu se citește fără să fie rulat, deci importul nu pornește');
            }
        }
        if ($prefix === null) {
            throw new EroareImport($fisier . ' nu are $table_prefix scris ca text simplu');
        }
        if (preg_match('/^[A-Za-z0-9_]{1,40}$/', $prefix) !== 1) {
            throw new EroareImport('$table_prefix din ' . $fisier . ' are alte caractere decât litere, cifre și „_”');
        }
        return new self($dosarWp, $constante, $prefix);
    }

    /**
     * Jetoanele PHP ale fișierului: `define('NUME', <valoare>)` și `$table_prefix = <valoare>;`, unde valoarea e un șir
     * (cu ghilimele simple sau duble, fără `$` în cele duble), un număr sau true/false. Comentariile și restul codului se
     * sar; o definiție cu altă valoare (o expresie) nu intră în listă.
     *
     * @return array{0: array<string, string>, 1: ?string}
     */
    public static function desface(string $text): array
    {
        $jetoane = array_values(array_filter(token_get_all($text), static function ($j): bool {
            return !is_array($j) || !in_array($j[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
        }));
        $constante = [];
        $prefix = null;
        $n = count($jetoane);
        for ($i = 0; $i < $n; $i++) {
            $j = $jetoane[$i];
            if (is_array($j) && $j[0] === T_STRING && strtolower($j[1]) === 'define' && ($jetoane[$i + 1] ?? null) === '(') {
                $nume = self::literal($jetoane[$i + 2] ?? null);
                $virgula = $jetoane[$i + 3] ?? null;
                $valoare = self::literal($jetoane[$i + 4] ?? null);
                $inchis = $jetoane[$i + 5] ?? null;
                if ($nume !== null && $virgula === ',' && $valoare !== null && $inchis === ')' && in_array($nume, self::CONSTANTE, true)
                    && !array_key_exists($nume, $constante)) {
                    $constante[$nume] = $valoare;
                }
                continue;
            }
            if (is_array($j) && $j[0] === T_VARIABLE && $j[1] === '$table_prefix' && ($jetoane[$i + 1] ?? null) === '=') {
                $valoare = self::literal($jetoane[$i + 2] ?? null);
                if ($valoare !== null && ($jetoane[$i + 3] ?? null) === ';') {
                    $prefix = $valoare;
                }
            }
        }
        return [$constante, $prefix];
    }

    /** Valoarea unui jeton literal (șir, număr, true/false), sau null dacă jetonul e altceva. */
    private static function literal(mixed $j): ?string
    {
        if (!is_array($j)) {
            return null;
        }
        if ($j[0] === T_CONSTANT_ENCAPSED_STRING) {
            $s = $j[1];
            $ghilimea = $s[0];
            $interior = substr($s, 1, -1);
            if ($ghilimea === "'") {
                return strtr($interior, ['\\\\' => '\\', "\\'" => "'"]);
            }
            // Ghilimele duble: doar fără variabile (un `$` neprotejat ar fi o valoare calculată).
            if (preg_match('/(?<!\\\\)\$/', $interior) === 1) {
                return null;
            }
            return stripcslashes(str_replace('\\$', '$', $interior));
        }
        if ($j[0] === T_LNUMBER || $j[0] === T_DNUMBER) {
            return $j[1];
        }
        if ($j[0] === T_STRING && in_array(strtolower($j[1]), ['true', 'false'], true)) {
            return strtolower($j[1]) === 'true' ? '1' : '';
        }
        return null;
    }

    public function constanta(string $nume, string $implicit = ''): string
    {
        return $this->constante[$nume] ?? $implicit;
    }

    /**
     * Gazda, portul și socketul bazei, din DB_HOST (`localhost`, `localhost:3307`, `localhost:/cale/sock`,
     * `[::1]:3306`).
     *
     * @return array{gazda: string, port: ?int, socket: ?string}
     */
    public function gazdaBazei(): array
    {
        $h = trim($this->constanta('DB_HOST', 'localhost'));
        $port = null;
        $socket = null;
        if (preg_match('/^\[(.+)\](?::(\d+))?$/', $h, $m) === 1) {
            return ['gazda' => $m[1], 'port' => isset($m[2]) ? (int) $m[2] : null, 'socket' => null];
        }
        if (substr_count($h, ':') === 1) {
            [$h, $rest] = explode(':', $h, 2);
            if (ctype_digit($rest)) {
                $port = (int) $rest;
            } elseif ($rest !== '') {
                $socket = $rest;
            }
        }
        return ['gazda' => $h !== '' ? $h : 'localhost', 'port' => $port, 'socket' => $socket];
    }

    /** DSN-ul PDO al bazei WordPress. */
    public function dsn(): string
    {
        $g = $this->gazdaBazei();
        $charset = strtolower($this->constanta('DB_CHARSET', 'utf8mb4'));
        $charset = in_array($charset, ['utf8', 'utf8mb4', 'latin1'], true) ? ($charset === 'utf8' ? 'utf8mb4' : $charset) : 'utf8mb4';
        $dsn = 'mysql:';
        if ($g['socket'] !== null) {
            $dsn .= 'unix_socket=' . $g['socket'];
        } else {
            $dsn .= 'host=' . $g['gazda'] . ($g['port'] !== null ? ';port=' . $g['port'] : '');
        }
        return $dsn . ';dbname=' . $this->constanta('DB_NAME') . ';charset=' . $charset;
    }

    /** Numele dosarului de poze al WordPress din wp-content/ (al instalării WordPress, nu al site-ului nou: acela e Paths). */
    private const DOSAR_UPLOADS_WORDPRESS = 'uploads';

    /** Dosarul pozelor WordPress (wp-content/uploads, sau cel din WP_CONTENT_DIR / UPLOADS). */
    public function dosarUploads(): string
    {
        $continut = $this->constanta('WP_CONTENT_DIR');
        $continut = $continut !== '' && str_starts_with($continut, '/') ? rtrim($continut, '/') : $this->dosar . '/wp-content';
        $uploads = trim($this->constanta('UPLOADS'), '/');
        return $uploads !== '' ? $this->dosar . '/' . $uploads : $continut . '/' . self::DOSAR_UPLOADS_WORDPRESS;
    }

    /** Un text fără parola bazei (mesajele de eroare ale PDO o pot repeta). */
    public function fara(string $text): string
    {
        $parola = $this->constanta('DB_PASSWORD');
        return $parola !== '' ? str_replace($parola, '***', $text) : $text;
    }

    /** Ce se poate arăta omului despre baza WordPress (fără parolă). */
    public function descriere(): string
    {
        $g = $this->gazdaBazei();
        return $this->constanta('DB_NAME') . ' (utilizatorul ' . $this->constanta('DB_USER') . ', ' . $g['gazda']
            . ($g['port'] !== null ? ':' . $g['port'] : '') . ($g['socket'] !== null ? ', socket' : '') . ', prefixul ' . $this->prefix . ')';
    }
}
