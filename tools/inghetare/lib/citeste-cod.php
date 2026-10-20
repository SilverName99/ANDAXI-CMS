<?php

declare(strict_types=1);

/**
 * Procesul-copil al generatorului înghețării (tools/inghetare/genereaza-setari.php): citește UN cod
 * (copia unui cod vechi, făcută cu git archive, sau arborele de lucru al acestui repo) și scrie pe
 * ieșire un JSON cu ce folosește acel cod când o setare lipsește din baza site-ului:
 *
 *  - `implicite`: Settings::DEFAULTS (constanta privată, citită prin Reflection din
 *    src/Support/Settings.php al acelui cod; Settings::all le pune peste rândurile lipsă);
 *  - `rezerve`: valorile de rezervă scrise în cod la citirea unei setări, `$settings['cheie'] ?? <literal>`
 *    (și `$<ceva>Settings[…]`, `$this->settings[…]`), cheie → lista valorilor (texte, ca după (string)).
 *    O rezervă care face parte dintr-o expresie (`?? 'a' . $b`) e trecută ca „<expresie>”;
 *  - `extrase`: valorile cerute de intrările „din”: „extras” ale înghețării, prin `--extrage=<fișier
 *    JSON>` ({cheie: {"fisier": "src/…", "tipar": "<regex cu un grup>"} | {"apel": "Clasa::metoda",
 *    "argumente": […], "cale": […], "json": true}}): cheie → {valoare} sau {eroare}.
 *
 * Un proces separat pentru fiecare cod, pentru că ambele au clasa App\Support\Settings. Nu încarcă
 * nimic din acest repo (doar copia dată), nu se conectează la nicio bază și nu iese în rețea.
 *
 *   php tools/inghetare/lib/citeste-cod.php <dosarul codului> [--extrage=<fișier>]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dosar = rtrim((string) ($argv[1] ?? ''), '/');
$fisierExtrase = null;
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--extrage=')) {
        $fisierExtrase = substr($argument, strlen('--extrage='));
    } else {
        fwrite(STDERR, 'argument necunoscut: ' . $argument . "\n");
        exit(2);
    }
}
if ($dosar === '' || !is_file($dosar . '/src/Support/Settings.php')) {
    fwrite(STDERR, 'codul din „' . $dosar . '” nu are src/Support/Settings.php' . "\n");
    exit(2);
}

// Clasele App\ ale acelui cod (pentru „apel”), din copia lui.
spl_autoload_register(static function (string $clasa) use ($dosar): void {
    if (str_starts_with($clasa, 'App\\')) {
        $fisier = $dosar . '/src/' . str_replace('\\', '/', substr($clasa, 4)) . '.php';
        if (is_file($fisier)) {
            require_once $fisier;
        }
    }
});

$iesire = ['implicite' => [], 'rezerve' => [], 'extrase' => [], 'fisiere' => 0];

// ------------------------------------------------------------------ Settings::DEFAULTS
try {
    $constanta = new ReflectionClassConstant('App\\Support\\Settings', 'DEFAULTS');
    $implicite = $constanta->getValue();
} catch (Throwable $e) {
    fwrite(STDERR, 'nu pot citi Settings::DEFAULTS: ' . $e->getMessage() . "\n");
    exit(1);
}
if (!is_array($implicite)) {
    fwrite(STDERR, "Settings::DEFAULTS nu e un array\n");
    exit(1);
}
foreach ($implicite as $cheie => $valoare) {
    if (!is_string($valoare) && !is_int($valoare) && !is_float($valoare)) {
        fwrite(STDERR, 'Settings::DEFAULTS[' . $cheie . '] nu e un text' . "\n");
        exit(1);
    }
    $iesire['implicite'][(string) $cheie] = (string) $valoare;
}
ksort($iesire['implicite'], SORT_STRING);

// ------------------------------------------------------------------ rezervele din cod
/** Textul unui literal PHP (T_CONSTANT_ENCAPSED_STRING), ca după evaluare. */
$text = static function (string $literal): string {
    $corp = substr($literal, 1, -1);
    if ($literal[0] === "'") {
        return preg_replace_callback("/\\\\([\\\\'])/", static fn (array $m): string => $m[1], $corp) ?? $corp;
    }
    return preg_replace_callback('/\\\\(u\{([0-9A-Fa-f]+)\}|x([0-9A-Fa-f]{1,2})|([0-7]{1,3})|[nrtvef\\\\$"])/',
        static function (array $m): string {
            if (($m[2] ?? '') !== '') {
                return mb_chr((int) hexdec($m[2]), 'UTF-8');
            }
            if (($m[3] ?? '') !== '') {
                return chr((int) hexdec($m[3]));
            }
            if (($m[4] ?? '') !== '') {
                return chr((int) octdec($m[4]) & 0xFF);
            }
            return match ($m[1]) {
                'n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\e", 'f' => "\f", default => $m[1],
            };
        }, $corp) ?? $corp;
};
$numeSetari = '/^(settings|[a-z][A-Za-z0-9]*Settings)$/';
$dupaLiteral = [')', ',', ';', ']', '}', ':'];
$fisiere = [];
foreach (['src', 'views', 'scripts', 'config', 'public'] as $parte) {
    if (!is_dir($dosar . '/' . $parte)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dosar . '/' . $parte, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
            $fisiere[] = $f->getPathname();
        }
    }
}
if (is_file($dosar . '/bootstrap.php')) {
    $fisiere[] = $dosar . '/bootstrap.php';
}
sort($fisiere, SORT_STRING);
$iesire['fisiere'] = count($fisiere);
foreach ($fisiere as $fisier) {
    $jetoane = array_values(array_filter(token_get_all((string) file_get_contents($fisier)), static fn (mixed $t): bool =>
        !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $n = count($jetoane);
    $eSir = static fn (int $i, int|string $tip, ?string $text = null): bool => $i < $n && (is_array($jetoane[$i])
        ? $jetoane[$i][0] === $tip && ($text === null || $jetoane[$i][1] === $text) : $jetoane[$i] === $tip);
    for ($i = 0; $i < $n; $i++) {
        $j = null;
        if (is_array($jetoane[$i]) && $jetoane[$i][0] === T_VARIABLE) {
            if (preg_match($numeSetari, substr($jetoane[$i][1], 1)) === 1) {
                $j = $i + 1;
            } elseif ($jetoane[$i][1] === '$this' && ($eSir($i + 1, T_OBJECT_OPERATOR) || $eSir($i + 1, T_NULLSAFE_OBJECT_OPERATOR))
                && $i + 2 < $n && is_array($jetoane[$i + 2]) && $jetoane[$i + 2][0] === T_STRING
                && preg_match($numeSetari, $jetoane[$i + 2][1]) === 1) {
                $j = $i + 3;
            }
        }
        if ($j === null || !$eSir($j, '[') || !$eSir($j + 1, T_CONSTANT_ENCAPSED_STRING) || !$eSir($j + 2, ']') || !$eSir($j + 3, T_COALESCE)) {
            continue;
        }
        $cheie = $text($jetoane[$j + 1][1]);
        if (preg_match('/^[a-z0-9][a-z0-9_]*$/', $cheie) !== 1) {
            continue;
        }
        $k = $j + 4;
        $minus = false;
        if ($eSir($k, '-')) {
            $minus = true;
            $k++;
        }
        $valoare = null;
        if ($k < $n && is_array($jetoane[$k])) {
            [$tip, $literal] = $jetoane[$k];
            if ($tip === T_CONSTANT_ENCAPSED_STRING && !$minus) {
                $valoare = $text($literal);
            } elseif ($tip === T_LNUMBER) {
                $numar = intval(str_replace('_', '', $literal), 0);
                $valoare = (string) ($minus ? -$numar : $numar);
            } elseif ($tip === T_DNUMBER) {
                $numar = (float) str_replace('_', '', $literal);
                $valoare = (string) ($minus ? -$numar : $numar);
            } elseif ($tip === T_STRING && !$minus && in_array(strtolower($literal), ['null', 'true', 'false'], true)) {
                $valoare = strtolower($literal) === 'true' ? '1' : '';
            }
        }
        // Doar un literal întreg: ce urmează după el închide expresia (altfel e o expresie, nu o valoare).
        if ($valoare === null || !($k + 1 < $n && in_array(is_array($jetoane[$k + 1]) ? null : $jetoane[$k + 1], $dupaLiteral, true)
            || ($k + 1 < $n && is_array($jetoane[$k + 1]) && $jetoane[$k + 1][0] === T_CLOSE_TAG))) {
            $valoare = '<expresie>';
        }
        $iesire['rezerve'][$cheie][$valoare] = true;
    }
}
ksort($iesire['rezerve'], SORT_STRING);
foreach ($iesire['rezerve'] as $cheie => $valori) {
    $lista = array_map('strval', array_keys($valori));
    sort($lista, SORT_STRING);
    $iesire['rezerve'][$cheie] = $lista;
}

// ------------------------------------------------------------------ valorile extrase
if ($fisierExtrase !== null) {
    $cereri = json_decode((string) @file_get_contents($fisierExtrase), true);
    if (!is_array($cereri)) {
        fwrite(STDERR, "--extrage: fișierul nu e JSON\n");
        exit(2);
    }
    foreach ($cereri as $cheie => $cerere) {
        try {
            if (!is_array($cerere)) {
                throw new RuntimeException('cererea nu e un obiect');
            }
            if (isset($cerere['fisier'], $cerere['tipar'])) {
                $cale = $dosar . '/' . ltrim((string) $cerere['fisier'], '/');
                if (str_contains((string) $cerere['fisier'], '..') || !is_file($cale)) {
                    throw new RuntimeException('fișierul ' . $cerere['fisier'] . ' nu există în cod');
                }
                if (@preg_match_all((string) $cerere['tipar'], (string) file_get_contents($cale), $m) === false || !isset($m[1])) {
                    throw new RuntimeException('tiparul nu e o expresie regulată cu un grup');
                }
                $gasite = array_values(array_unique($m[1]));
                if (count($gasite) !== 1) {
                    throw new RuntimeException('tiparul găsește ' . count($gasite) . ' valori diferite în ' . $cerere['fisier'] . ' (trebuie exact una)');
                }
                $valoare = $gasite[0];
            } elseif (isset($cerere['apel']) && preg_match('/^(App\\\\[A-Za-z0-9_\\\\]+)::([A-Za-z0-9_]+)$/', (string) $cerere['apel'], $m) === 1) {
                $metoda = new ReflectionMethod($m[1], $m[2]);
                $obiect = $metoda->isStatic() ? null : (new ReflectionClass($m[1]))->newInstanceWithoutConstructor();
                $valoare = $metoda->invokeArgs($obiect, array_values((array) ($cerere['argumente'] ?? [])));
                foreach ((array) ($cerere['cale'] ?? []) as $pas) {
                    if (!is_array($valoare) || !array_key_exists($pas, $valoare)) {
                        throw new RuntimeException('rezultatul apelului nu are „' . $pas . '”');
                    }
                    $valoare = $valoare[$pas];
                }
                if (!empty($cerere['json'])) {
                    $valoare = json_encode($valoare, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if (!is_string($valoare)) {
                    throw new RuntimeException('rezultatul apelului nu e un text (cu "json": true se scrie ca JSON)');
                }
            } else {
                throw new RuntimeException('cererea e {fisier, tipar} sau {apel: "App\\\\…::metoda", argumente, cale, json}');
            }
            $iesire['extrase'][(string) $cheie] = ['valoare' => $valoare];
        } catch (Throwable $e) {
            $iesire['extrase'][(string) $cheie] = ['eroare' => $e->getMessage()];
        }
    }
}

echo json_encode($iesire, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
