<?php

declare(strict_types=1);

/**
 * Verificarea fișierelor de migrare și a DDL-ului din cod (testul T11 al migrărilor, docs/MIGRARI.md,
 * „Verificarea fișierelor”; WP-04). Rulează în testul de fum (tools/dev/smoke.sh) și în poarta G-mig
 * (tools/dev/porti.sh): nu există CI, deci aici se oprește o migrare greșită sau un DDL nou în cod.
 *
 *   tools/dev/php.sh tools/dev/verifica-migrari.php                    # tot (cu o bază goală pe MariaDB, la final)
 *   tools/dev/php.sh tools/dev/verifica-migrari.php --fara-baza         # fără server: punctele 6 și 7 nu se fac (spus în rezultat)
 *   tools/dev/php.sh tools/dev/verifica-migrari.php --actualizeaza-lista   # după ce un DDL vechi a ieșit din cod: îl scoate din listă
 *   tools/dev/php.sh tools/dev/verifica-migrari.php --lista-initiala       # o singură dată (WP-04): lista de azi; refuză o listă existentă
 *
 * Ce verifică:
 *  1. Registrul (App\Support\Migrari\Registru): fiecare fișier are forma cerută, numărul NNNN e unic în tot
 *     dosarul, dosarul e „nucleu” sau cheia unui plugin din config/plugins.php.
 *  2. migrari.lock are exact migrările din cod, cu sha-ul lor (una nouă trebuie trecută: genereaza-fixture.php
 *     --asteptat), iar o migrare retrasă are motivul scris; ultima.php are ultimul număr, amprenta codului și
 *     id-urile migrărilor (Registru::ultima(); garda le citește la fiecare cerere).
 *  3. În fișierele de migrare: niciun DDL scris de mână (CREATE/ALTER/DROP/RENAME/TRUNCATE …: totul trece prin
 *     Schema, care detectează și alege clauzele); în definiții niciun AFTER/FIRST (blochează pe MySQL < 8.0.29 și
 *     eșuează în tăcere când coloana de referință lipsește), niciun IF [NOT] EXISTS (există doar pe MariaDB),
 *     niciun CHARACTER SET/CHARSET/COLLATE (coloana ia colația tabelului); ștergerile (stergeColoana,
 *     stergeTabel) doar într-o migrare de tip „contract”. Runner-ul le refuză oricum la rulare, dar doar pe
 *     ramura care rulează; aici se văd și cele de pe ramurile pentru obiecte vechi.
 *  4. DDL-ul din codul care ajunge pe site-uri (src/ fără src/Support/Migrari/, scripts/, public/, views/,
 *     config/, bootstrap.php, database/schema.sql): fiecare text DDL și fiecare definiție de coloană (ca listele
 *     de coloane din ErpSync::ensureSchema, BtIpayPayments::migreaza) trebuie să fie în
 *     tools/dev/migrari/ddl-din-cod.txt. O definiție = un tip SQL urmat de lungime sau de un atribut
 *     ('VARCHAR(40) NULL', 'INT NOT NULL DEFAULT 0'), oriunde în acest cod (și într-o constantă, o proprietate,
 *     altă metodă sau alt fișier decât DDL-ul care o folosește), sau doar tipul: cu majuscule ('DATETIME')
 *     oriunde, cu orice litere ('datetime') într-o funcție cu DDL. Un tip singur cu litere mici în afara unei
 *     funcții cu DDL nu se numără: nu se deosebește de tipurile câmpurilor din formulare ('text', 'date');
 *     SQL-ul din cod se scrie cu majuscule. În listă: cele de azi, din cele 36 de funcții ensure…Schema și
 *     asigura…Schema, install.php, schema.sql și tabelele de lucru ale sincronizării FAN, care rămân până la R2 (MIG-11). Unul nou
 *     (sau încă o copie a unuia vechi, sau unul mutat în altă funcție) = încălcare: o coloană nouă se face cu o
 *     migrare (regula 2 din planul Etapei 1). Unul care a ieșit din cod = lista se actualizează (--actualizeaza-lista,
 *     care doar scoate rânduri; un rând nou nu intră niciodată în listă de aici).
 *  5. asteptat.json există și e o amprentă (forma lui Amprenta).
 *  6. Pe o bază goală (MariaDB, cms_mig_v_*): asteptat.json = amprenta după toate migrările (la zi cu migrările).
 *  7. Pe o bază goală, din planul migrărilor: fiecare tabel, coloană și index pe care îl face o migrare e, în
 *     catalog (config/plugins.php → tabele: „tabel” sau „tabel.coloană*”), al proprietarului ei (dosarul ei);
 *     un index e al proprietarului tabelului sau al uneia dintre coloanele lui.
 *
 * Doar pentru testele lui (tools/dev/test-migrari.php, T11): --radacina=<copie> citește totul (migrările, codul,
 * catalogul, lista) dintr-o copie a repo-ului. Ieșirea are doar nume de fișiere, funcții și obiecte.
 * Cod de ieșire: 0 = nicio încălcare; 1 = încălcări (listate); 2 = argument greșit sau server oprit.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacinaReala = (string) realpath(dirname(__DIR__, 2));
foreach (['Eroare', 'Server', 'Amprenta', 'Registru'] as $clasa) {
    require_once $radacinaReala . '/src/Support/Migrari/' . $clasa . '.php';
}

use App\Support\Migrari\Amprenta;
use App\Support\Migrari\Eroare;
use App\Support\Migrari\Registru;

$faraBaza = false;
$actualizeaza = false;
$initiala = false;
$radacina = $radacinaReala;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--fara-baza') {
        $faraBaza = true;
    } elseif ($argument === '--actualizeaza-lista') {
        $actualizeaza = true;
    } elseif ($argument === '--lista-initiala') {
        $initiala = true;
    } elseif (str_starts_with($argument, '--radacina=')) {
        $radacina = (string) realpath(substr($argument, strlen('--radacina=')));
        if ($radacina === '' || !is_dir($radacina . '/database/migrari')) {
            fwrite(STDERR, "EROARE: --radacina nu e o copie a repo-ului (lipsește database/migrari)\n");
            exit(2);
        }
    } else {
        fwrite(STDERR, 'EROARE: argument necunoscut: ' . $argument . " (vezi capul fișierului)\n");
        exit(2);
    }
}

$dosarMigrari = $radacina . '/database/migrari';
$fisierLista = $radacina . '/tools/dev/migrari/ddl-din-cod.txt';
$incalcari = [];
$incalca = static function (string $text) use (&$incalcari): void {
    $incalcari[] = $text;
};
$relativ = static fn (string $cale): string => str_starts_with($cale, $radacina . '/') ? substr($cale, strlen($radacina) + 1) : $cale;

// ------------------------------------------------------------------------------------------ 1–2
$catalog = (static function (string $__fisier): mixed {
    return require $__fisier;
})($radacina . '/config/plugins.php');
$proprietari = array_merge(['nucleu'], array_keys((array) ($catalog['pluginuri'] ?? [])));
$registru = new Registru($dosarMigrari, $proprietari);
$migrari = [];
try {
    $migrari = $registru->migrari();
    $lacat = $registru->lacat();
} catch (Eroare $e) {
    $incalca('registrul: ' . $e->getMessage());
    $lacat = ['migrari' => [], 'retrase' => []];
}
$dinCod = [];
foreach ($migrari as $m) {
    $dinCod[$m['id']] = $m['sha256'];
}
foreach ($dinCod as $id => $sha) {
    if (!isset($lacat['migrari'][$id])) {
        $incalca('migrari.lock: lipsește ' . $id . ' (o migrare nouă se trece cu tools/dev/php.sh tools/dev/migrari/genereaza-fixture.php --asteptat)');
    } elseif ($lacat['migrari'][$id] !== $sha) {
        $incalca('migrari.lock: ' . $id . ' s-a schimbat după ce a fost trecută (sha256 diferit); o migrare publicată nu se schimbă, '
            . 'o schimbare e o migrare nouă');
    }
}
foreach ($lacat['migrari'] as $id => $sha) {
    if (!isset($dinCod[$id]) && !isset($lacat['retrase'][$id])) {
        $incalca('migrari.lock: ' . $id . ' e trecută, dar fișierul ei lipsește (una retrasă se trece la „retrase”, cu motivul)');
    }
}
foreach ($lacat['retrase'] as $id => $motiv) {
    if (!is_string($motiv) || trim($motiv) === '') {
        $incalca('migrari.lock: retrasa ' . $id . ' nu are motivul scris');
    }
    if (isset($dinCod[$id])) {
        $incalca('migrari.lock: ' . $id . ' e „retrasă”, dar fișierul ei e tot în cod');
    }
}
$fisierUltima = $dosarMigrari . '/ultima.php';
$ultima = is_file($fisierUltima) ? (static function (string $__fisier): mixed {
    return require $__fisier;
})($fisierUltima) : null;
if ($migrari !== [] && $ultima !== $registru->ultima()) {
    $incalca('ultima.php nu e la zi (ultima migrare ' . sprintf('%04d', (int) end($migrari)['ord']) . '; genereaza-fixture.php --asteptat)');
}

// --------------------------------------------------------------------------------------------- 3
/** Textele (literalele) unui fișier PHP: [text normalizat, rândul, funcția în care stă]. */
$literale = static function (string $fisier): array {
    $jetoane = token_get_all((string) file_get_contents($fisier));
    $rezultat = [];
    $stiva = [];          // [nume, adâncimea acoladei care îl deschide]
    $adancime = 0;
    $asteaptaNume = null; // 'clasa' | 'functie'
    $deschide = null;     // numele care primește următoarea acoladă
    $clasa = null;
    $clasaAdancime = -1;
    $anterior = null;
    $n = count($jetoane);
    for ($i = 0; $i < $n; $i++) {
        $j = $jetoane[$i];
        if (is_array($j) && in_array($j[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $tip = is_array($j) ? $j[0] : null;
        $text = is_array($j) ? $j[1] : $j;
        if ($asteaptaNume !== null) {
            if ($tip === T_STRING) {
                if ($asteaptaNume === 'clasa') {
                    $clasa = $text;
                    $deschide = ['clasa', $text];
                } else {
                    $deschide = ['functie', ($clasa !== null ? $clasa . '::' : '') . $text];
                }
                $asteaptaNume = null;
                $anterior = $j;
                continue;
            }
            if ($text !== '&') {
                $asteaptaNume = null; // o funcție anonimă: rămâne a funcției din jur
            }
        }
        if (in_array($tip, [T_CLASS, T_TRAIT, T_INTERFACE], true) || (defined('T_ENUM') && $tip === T_ENUM)) {
            $inainte = is_array($anterior) ? $anterior[0] : $anterior;
            if ($inainte !== T_DOUBLE_COLON && $inainte !== T_NEW) {
                $asteaptaNume = 'clasa';
            }
        } elseif ($tip === T_FUNCTION) {
            $asteaptaNume = 'functie';
        } elseif ($text === '{' || $tip === T_CURLY_OPEN || $tip === T_DOLLAR_OPEN_CURLY_BRACES) {
            $adancime++;
            if ($deschide !== null && $text === '{') {
                if ($deschide[0] === 'clasa') {
                    $clasaAdancime = $adancime;
                } else {
                    $stiva[] = [$deschide[1], $adancime];
                }
                $deschide = null;
            }
        } elseif ($text === '}') {
            if ($stiva !== [] && end($stiva)[1] === $adancime) {
                array_pop($stiva);
            }
            if ($clasa !== null && $adancime === $clasaAdancime) {
                $clasa = null;
                $clasaAdancime = -1;
            }
            $adancime--;
        } elseif ($text === ';' && $deschide !== null && $deschide[0] === 'functie') {
            $deschide = null; // o metodă abstractă
        }
        // În afara metodelor, dar în corpul unei clase (constante, proprietăți): „Clasa (clasă)”.
        $functie = $stiva !== [] ? end($stiva)[0] : ($clasa !== null && $clasaAdancime > 0 ? $clasa . ' (clasă)' : '(fișier)');
        if ($tip === T_CONSTANT_ENCAPSED_STRING) {
            $sir = $text;
            $sir = $sir[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($sir, 1, -1)) : stripcslashes(substr($sir, 1, -1));
            $rezultat[] = [$sir, $j[2], $functie];
        } elseif ($text === '"' || $tip === T_START_HEREDOC) {
            // Un text cu variabile: bucățile fixe, cu {…} în locul fiecărei variabile.
            $rand = is_array($j) ? $j[2] : 0;
            $sir = '';
            for ($i++; $i < $n; $i++) {
                $k = $jetoane[$i];
                if ((!is_array($k) && $k === '"') || (is_array($k) && $k[0] === T_END_HEREDOC)) {
                    break;
                }
                if (is_array($k) && $k[0] === T_ENCAPSED_AND_WHITESPACE) {
                    $sir .= $k[1];
                    $rand = $rand ?: $k[2];
                } elseif (!str_ends_with($sir, '{…}')) {
                    $sir .= '{…}';
                }
            }
            $rezultat[] = [$sir, $rand, $functie];
        }
        $anterior = $j;
    }
    foreach ($rezultat as &$r) {
        $r[0] = trim((string) preg_replace('/\s+/', ' ', $r[0]));
    }
    return $rezultat;
};

const VM_DDL = '/\b(CREATE\s+(TEMPORARY\s+)?TABLE|ALTER\s+TABLE|CREATE\s+(UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?INDEX|DROP\s+(TEMPORARY\s+)?TABLE'
    . '|DROP\s+INDEX|DROP\s+COLUMN|RENAME\s+TABLE|TRUNCATE\s+(TABLE\s+)?[`a-z_{]|(CREATE|ALTER|DROP)\s+(DATABASE|SCHEMA|VIEW|TRIGGER|PROCEDURE|FUNCTION|EVENT))\b/i';
const VM_TIPURI = 'TINYINT|SMALLINT|MEDIUMINT|INT|INTEGER|BIGINT|DECIMAL|NUMERIC|FLOAT|DOUBLE|REAL|BOOLEAN|BOOL|BIT|CHAR|VARCHAR|BINARY'
    . '|VARBINARY|TINYTEXT|TEXT|MEDIUMTEXT|LONGTEXT|TINYBLOB|BLOB|MEDIUMBLOB|LONGBLOB|ENUM|SET|DATE|DATETIME|TIMESTAMP|TIME|YEAR|JSON';
// Tipurile care pot avea o lungime în paranteză (fără DATE, YEAR, JSON, *TEXT/*BLOB cu prefix, BOOL: acolo
// o paranteză e o funcție SQL, 'DATE({…})').
const VM_TIPURI_CU_LUNGIME = 'TINYINT|SMALLINT|MEDIUMINT|INT|INTEGER|BIGINT|DECIMAL|NUMERIC|FLOAT|DOUBLE|REAL|BIT|CHAR|VARCHAR|BINARY'
    . '|VARBINARY|TEXT|BLOB|ENUM|SET|DATETIME|TIMESTAMP|TIME';
// O definiție de coloană: tipul, apoi un atribut, sau lungimea (o cifră, valorile unui ENUM/SET, o variabilă sau
// capătul textului: 'VARCHAR(' . $n . ')'). Nu și o funcție SQL cu o coloană în paranteză ('DATE(o.created_at) >= ?').
const VM_DEFINITIE = '/^((' . VM_TIPURI . ')\b\s*(UNSIGNED\b|SIGNED\b|ZEROFILL\b|NOT\s+NULL|NULL\b|DEFAULT\b|AUTO_INCREMENT|CHARACTER\s+SET|CHARSET|COLLATE)'
    . '|(' . VM_TIPURI_CU_LUNGIME . ')\s*\(\s*([\d\'"{]|$))/i';
// Doar tipul, nimic după ('DATETIME', 'TEXT'): cu majuscule, oriunde; cu orice litere, doar într-o funcție cu DDL
// (în rest 'text', 'date', 'json' sunt tipuri de câmpuri, chei și formate, nu SQL).
const VM_DOAR_TIP = '/^(' . VM_TIPURI . ')$/';
const VM_DOAR_TIP_ORICE = '/^(' . VM_TIPURI . ')$/i';
const VM_DML = '/^\s*(INSERT|UPDATE|DELETE|REPLACE|SELECT|WITH|SHOW)\b/i';

foreach ($migrari as $m) {
    $fisier = (string) $m['fisier'];
    foreach ($literale($fisier) as [$text, $rand]) {
        $loc = $relativ($fisier) . ':' . $rand;
        if (preg_match(VM_DDL, $text, $g) === 1) {
            $incalca($loc . ': DDL scris de mână („' . $g[1] . '”); tabelele, coloanele și indecșii se fac prin Schema '
                . '(tabelComplet, coloana, index, …), care detectează ce există și alege clauzele');
            continue;
        }
        if (preg_match(VM_DML, $text) === 1) {
            if (preg_match('/\bIF\s+(NOT\s+)?EXISTS\b/i', $text) === 1) {
                $incalca($loc . ': IF [NOT] EXISTS într-o instrucțiune; condiția se scrie cu WHERE (NOT) EXISTS sau cu Schema::dacaTabel');
            }
            continue;
        }
        if (preg_match('/\b(AFTER\s+`?[a-z_]|FIRST\b)/i', $text) === 1) {
            $incalca($loc . ': AFTER/FIRST într-o definiție; o coloană nouă se adaugă la coadă (ordinea coloanelor nu contează)');
        }
        if (preg_match('/\bIF\s+(NOT\s+)?EXISTS\b/i', $text) === 1) {
            $incalca($loc . ': IF [NOT] EXISTS într-o definiție (există doar pe MariaDB); detectarea o face Schema');
        }
        if (preg_match('/\b(CHARACTER\s+SET|CHARSET|COLLATE)\b/i', $text) === 1) {
            $incalca($loc . ': CHARACTER SET/CHARSET/COLLATE într-o definiție; coloana ia colația tabelului (utf8mb4_unicode_ci)');
        }
    }
    if ($m['tip'] !== 'contract' && preg_match('/->\s*sterge(Coloana|Tabel)\s*\(/', (string) file_get_contents($fisier), $g) === 1) {
        $incalca($relativ($fisier) . ': sterge' . $g[1] . '() într-o migrare de tip „' . $m['tip'] . '”; ștergerile doar în „contract”, la un release după');
    }
}

// --------------------------------------------------------------------------------------------- 4
/**
 * DDL-ul din codul de pe site-uri: cheie (fișier, funcție, fel, sha1 al textului) → [de câte ori, începutul textului].
 *
 * @return array<string, array{0: int, 1: string}>
 */
$ddlDinCod = static function () use ($radacina, $literale, $relativ): array {
    $fisiere = [];
    foreach (['src', 'scripts', 'public', 'views', 'config'] as $dosar) {
        if (!is_dir($radacina . '/' . $dosar)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radacina . '/' . $dosar, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $f) {
            $cale = $f->getPathname();
            if ($f->getExtension() === 'php' && !str_contains($cale, '/src/Support/Migrari/') && !str_contains($cale, '/public/uploads/')) {
                $fisiere[] = $cale;
            }
        }
    }
    if (is_file($radacina . '/bootstrap.php')) {
        $fisiere[] = $radacina . '/bootstrap.php';
    }
    sort($fisiere);
    $gasite = [];
    $adauga = static function (string $fisier, string $functie, string $fel, string $text) use (&$gasite, $relativ): void {
        $cheie = $relativ($fisier) . "\t" . $functie . "\t" . $fel . "\t" . substr(sha1($text), 0, 12);
        $gasite[$cheie] = [($gasite[$cheie][0] ?? 0) + 1, rtrim(mb_substr($text, 0, 90))];
    };
    foreach ($fisiere as $fisier) {
        $toate = $literale($fisier);
        $cuDdl = [];
        foreach ($toate as [$text, , $functie]) {
            if (preg_match(VM_DDL, $text) === 1) {
                $cuDdl[$functie] = true;
            }
        }
        // O definiție de coloană se numără oriunde în cod, nu doar lângă DDL: o listă de coloane ținută într-o
        // constantă, o proprietate, altă metodă sau alt fișier ajunge la fel la un ALTER din altă parte.
        foreach ($toate as [$text, , $functie]) {
            if (preg_match(VM_DDL, $text) === 1) {
                $adauga($fisier, $functie, 'ddl', $text);
            } elseif (preg_match(VM_DEFINITIE, $text) === 1 || preg_match(VM_DOAR_TIP, $text) === 1
                || (isset($cuDdl[$functie]) && preg_match(VM_DOAR_TIP_ORICE, $text) === 1)) {
                $adauga($fisier, $functie, 'definitie', $text);
            }
        }
    }
    // database/schema.sql (îl rulează install.php până la WP-05; apoi doar documentație, până la R2).
    $schema = $radacina . '/database/schema.sql';
    if (is_file($schema)) {
        $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($schema));
        foreach (explode(';', $sql) as $instructiune) {
            $text = trim((string) preg_replace('/\s+/', ' ', $instructiune));
            if ($text !== '' && preg_match(VM_DDL, $text) === 1) {
                $adauga($schema, '(schema.sql)', 'ddl', $text);
            }
        }
    }
    ksort($gasite);
    return $gasite;
};
$gasite = $ddlDinCod();

$antetLista = "# DDL-ul vechi din codul de pe site-uri, permis până la R2 (MIG-11; docs/MIGRARI.md, „Verificarea fișierelor”).\n"
    . "# Un rând: fișierul, funcția, felul (ddl = un text cu CREATE/ALTER/DROP/RENAME/TRUNCATE; definitie = o definiție de\n"
    . "# coloană, oriunde în cod, ca 'VARCHAR(40) NULL'), sha1 al textului normalizat (12 caractere), de câte ori apare, începutul textului.\n"
    . "# Scris de tools/dev/verifica-migrari.php. Se schimbă doar cu --actualizeaza-lista, care doar scoate rânduri:\n"
    . "# un DDL nou în cod nu intră aici niciodată, ci într-o migrare (database/migrari/).\n";
$scrieLista = static function (array $intrari) use ($fisierLista, $antetLista): void {
    $linii = [];
    foreach ($intrari as $cheie => [$numar, $inceput]) {
        $linii[] = $cheie . "\t" . $numar . "\t" . $inceput;
    }
    file_put_contents($fisierLista, $antetLista . implode("\n", $linii) . "\n");
};
$permise = [];
if (is_file($fisierLista)) {
    foreach (file($fisierLista, FILE_IGNORE_NEW_LINES) ?: [] as $linie) {
        if ($linie === '' || str_starts_with($linie, '#')) {
            continue;
        }
        $parti = explode("\t", $linie);
        if (count($parti) < 6 || preg_match('/^[1-9]\d*$/', $parti[4]) !== 1) {
            $incalca('tools/dev/migrari/ddl-din-cod.txt: rând stricat: ' . mb_substr($linie, 0, 120));
            continue;
        }
        $permise[implode("\t", array_slice($parti, 0, 4))] = [(int) $parti[4], implode("\t", array_slice($parti, 5))];
    }
} elseif (!$initiala) {
    $incalca('tools/dev/migrari/ddl-din-cod.txt lipsește (lista DDL-ului vechi din cod)');
}
if ($initiala) {
    // Doar o dată (WP-04): lista de azi, exact DDL-ul din cod. Apoi lista doar scade.
    if (is_file($fisierLista)) {
        fwrite(STDERR, "EROARE: --lista-initiala: tools/dev/migrari/ddl-din-cod.txt există deja; lista doar scade (--actualizeaza-lista)\n");
        exit(2);
    }
    $scrieLista($gasite);
    echo 'verifica-migrari: prima listă scrisă (', array_sum(array_column($gasite, 0)), " texte)\n";
    exit(0);
}
$noi = [];
$iesite = [];
foreach ($gasite as $cheie => [$numar, $inceput]) {
    $voie = $permise[$cheie][0] ?? 0;
    if ($numar > $voie) {
        $noi[$cheie] = [$numar - $voie, $inceput];
    }
}
foreach ($permise as $cheie => [$numar, $inceput]) {
    $acum = $gasite[$cheie][0] ?? 0;
    if ($acum < $numar) {
        $iesite[$cheie] = [$numar - $acum, $inceput];
    }
}
if ($actualizeaza) {
    if ($noi !== []) {
        foreach ($noi as $cheie => [$cate, $inceput]) {
            [$f, $functie, $fel] = explode("\t", $cheie);
            $incalca($f . ' ' . $functie . ': ' . $fel . ' nou, nu-l trec în listă („' . $inceput . '…”); o schemă nouă se face cu o migrare');
        }
    } else {
        $pastrate = [];
        foreach ($permise as $cheie => [$numar, $inceput]) {
            $ramase = min($numar, $gasite[$cheie][0] ?? 0);
            if ($ramase > 0) {
                $pastrate[$cheie] = [$ramase, $inceput];
            }
        }
        $scrieLista($pastrate);
        echo 'verifica-migrari: lista scrisă (', array_sum(array_column($pastrate, 0)), ' texte; ', array_sum(array_column($iesite, 0)), " scoase)\n";
    }
    $iesite = [];
}
foreach ($noi as $cheie => [$cate, $inceput]) {
    [$f, $functie, $fel] = explode("\t", $cheie);
    $incalca($f . ' ' . $functie . ': ' . ($fel === 'ddl' ? 'DDL nou' : 'definiție de coloană nouă') . ($cate > 1 ? ' (×' . $cate . ')' : '')
        . ' („' . $inceput . '…”). O coloană, un tabel sau un index nou se face cu o migrare în database/migrari/ (docs/MIGRARI.md), '
        . 'nu din cod');
}
foreach ($iesite as $cheie => [$cate, $inceput]) {
    [$f, $functie, $fel] = explode("\t", $cheie);
    $incalca($f . ' ' . $functie . ': ' . $fel . ' din listă a ieșit din cod (' . $cate . ', „' . $inceput . '…”): '
        . 'tools/dev/php.sh tools/dev/verifica-migrari.php --actualizeaza-lista (lista doar scade)');
}

// --------------------------------------------------------------------------------------------- 5
$asteptat = null;
try {
    $asteptat = $registru->asteptat();
} catch (Eroare $e) {
    $incalca($e->getMessage());
}
if ($migrari !== [] && $asteptat === null) {
    $incalca('database/migrari/asteptat.json lipsește (genereaza-fixture.php --asteptat)');
} elseif ($asteptat !== null && (int) ($asteptat['format'] ?? 0) !== Amprenta::FORMAT) {
    $incalca('asteptat.json are alt format decât Amprenta (' . (int) ($asteptat['format'] ?? 0) . ' față de ' . Amprenta::FORMAT . ')');
}

// ------------------------------------------------------------------------------------------ 6–7
$bazaVerificata = false;
if (!$faraBaza && $migrari !== [] && $asteptat !== null) {
    $sock = (string) (ini_get('pdo_mysql.default_socket') ?: (getenv('CMS_DEV_SOCK') ?: '/tmp/claude-0/maria/sock'));
    $utilizator = (string) (getenv('DB_USERNAME') ?: 'root');
    $parola = (string) (getenv('DB_PASSWORD') ?: '');
    try {
        $server = new PDO('mysql:unix_socket=' . $sock . ';charset=utf8mb4', $utilizator, $parola, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable) {
        fwrite(STDERR, 'EROARE: MariaDB nu răspunde pe ' . $sock . " (CMS_DEV_SOCK); fără server: --fara-baza (asteptat.json și proprietarii nu se verifică)\n");
        exit(2);
    }
    $baze = ['cms_mig_v_plan', 'cms_mig_v_aplica'];
    register_shutdown_function(static function () use ($server, $baze): void {
        foreach ($baze as $b) {
            try {
                $server->exec('DROP DATABASE IF EXISTS `' . $b . '`');
            } catch (Throwable) {
            }
        }
    });
    $migreaza = static function (string $baza, array $argumente) use ($radacinaReala, $dosarMigrari, $sock, $utilizator, $parola): array {
        $comanda = array_merge([PHP_BINARY, '-d', 'pdo_mysql.default_socket=' . $sock, '-d', 'display_errors=stderr', '-d', 'log_errors=0',
            $radacinaReala . '/scripts/migreaza.php'], $argumente);
        $env = getenv();
        unset($env['SITE_SLUG'], $env['MIGRARI_TEST_OPRESTE_DUPA'], $env['MIGRARI_TEST_OPRESTE_IN']);
        $env = array_merge($env, ['DB_HOST' => 'localhost', 'DB_PORT' => '3306', 'DB_DATABASE' => $baza, 'DB_USERNAME' => $utilizator,
            'DB_PASSWORD' => $parola, 'MIGRARI_TEST_DOSAR' => $dosarMigrari, 'MIGRARI_TEST_JURNAL' => '/dev/null', 'MIGRARI_TEST_PAUZA_BUCATI_MS' => '0']);
        $proces = proc_open($comanda, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, $radacinaReala, $env);
        $out = (string) stream_get_contents($pipe[1]);
        $err = (string) stream_get_contents($pipe[2]);
        fclose($pipe[1]);
        fclose($pipe[2]);
        return [proc_close($proces), $out, $err];
    };
    foreach ($baze as $b) {
        $server->exec('DROP DATABASE IF EXISTS `' . $b . '`');
        $server->exec('CREATE DATABASE `' . $b . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    // 6. asteptat.json la zi.
    [$cod, $out] = $migreaza('cms_mig_v_aplica', ['--aplica', '--json']);
    $raport = json_decode($out, true);
    if (!is_array($raport) || $cod !== 0) {
        $incalca('o bază goală: migreaza.php --aplica a ieșit cu ' . $cod . ': ' . mb_substr((string) ($raport['mesaj'] ?? $out), 0, 400)
            . ' (asteptat.json vechi? genereaza-fixture.php --asteptat)');
    } else {
        [$codA, $json] = $migreaza('cms_mig_v_aplica', ['--amprenta']);
        $amprenta = json_decode($json, true);
        if ($codA !== 0 || !is_array($amprenta) || Amprenta::json($amprenta) !== Amprenta::json($asteptat)) {
            $d = is_array($amprenta) ? Amprenta::compara($asteptat, $amprenta) : ['lipsa' => [], 'in_plus' => [], 'avertismente' => []];
            $incalca('asteptat.json nu e la zi cu migrările (genereaza-fixture.php --asteptat): '
                . implode('; ', array_slice(array_merge(array_map(static fn (string $o): string => 'în plus în asteptat.json: ' . $o, $d['lipsa']),
                    array_map(static fn (string $o): string => 'lipsește din asteptat.json: ' . $o, $d['in_plus']), $d['avertismente']), 0, 10)));
        }
    }

    // 7. Proprietarii obiectelor, din planul unei baze goale.
    $tipare = [];
    $intrariCatalog = ['nucleu' => (array) ($catalog['nucleu']['tabele'] ?? [])];
    foreach ((array) ($catalog['pluginuri'] ?? []) as $cheie => $plugin) {
        $intrariCatalog[$cheie] = (array) ($plugin['tabele'] ?? []);
    }
    foreach ($intrariCatalog as $cheie => $lista) {
        foreach ($lista as $tipar) {
            $tipare[] = [(string) $tipar, (string) $cheie];
        }
    }
    /** Proprietarul unui tabel sau al unei coloane („tabel.coloană”) după catalog; null = nimeni. */
    $alCui = static function (string $obiect) use ($tipare): ?string {
        [$tabel, $coloana] = array_pad(explode('.', $obiect, 2), 2, null);
        if ($coloana !== null) {
            foreach ($tipare as [$tipar, $cheie]) {
                if (str_contains($tipar, '.') && fnmatch($tipar, $tabel . '.' . $coloana)) {
                    return $cheie;
                }
            }
        }
        foreach ($tipare as [$tipar, $cheie]) {
            if (!str_contains($tipar, '.') && fnmatch($tipar, $tabel)) {
                return $cheie;
            }
        }
        return null;
    };
    [$cod, $out] = $migreaza('cms_mig_v_plan', ['--plan', '--json']);
    $plan = json_decode($out, true);
    if (!is_array($plan) || !in_array($cod, [0, 10], true)) {
        $incalca('o bază goală: migreaza.php --plan a ieșit cu ' . $cod . ': ' . mb_substr((string) ($plan['mesaj'] ?? $out), 0, 400));
    } else {
        $adaugateDupa = [];
        $create = [];
        foreach ((array) ($plan['migrari'] ?? []) as $m) {
            foreach ((array) ($m['operatii'] ?? []) as $o) {
                if ($o['fel'] === 'tabel' && $o['clasa'] === 'creare') {
                    $create[(string) $o['obiect']] = $m;
                } elseif ($o['fel'] === 'coloana') {
                    $adaugateDupa[(string) $o['obiect']] = true;
                }
            }
        }
        foreach ((array) ($plan['migrari'] ?? []) as $m) {
            $al = (string) $m['proprietar'];
            foreach ((array) ($m['operatii'] ?? []) as $o) {
                $obiect = (string) $o['obiect'];
                if ($o['fel'] === 'tabel' && $o['clasa'] === 'creare') {
                    $deVerificat = [$obiect => 'tabelul ' . $obiect];
                    foreach (array_keys((array) ($asteptat['tabele'][$obiect]['coloane'] ?? [])) as $c) {
                        if (!isset($adaugateDupa[$obiect . '.' . $c])) {
                            $deVerificat[$obiect . '.' . $c] = 'coloana ' . $obiect . '.' . $c;
                        }
                    }
                    foreach ($deVerificat as $cine => $eticheta) {
                        $proprietar = $alCui($cine);
                        if ($proprietar !== $al) {
                            $incalca($m['id'] . ': ' . $eticheta . ' e, în catalog, ' . ($proprietar === null ? 'al nimănui' : 'al lui ' . $proprietar)
                                . ' (config/plugins.php → tabele); îl face o migrare a lui ' . $al);
                        }
                    }
                } elseif ($o['fel'] === 'coloana') {
                    $proprietar = $alCui($obiect);
                    if ($proprietar !== $al) {
                        $incalca($m['id'] . ': coloana ' . $obiect . ' e, în catalog, ' . ($proprietar === null ? 'a nimănui' : 'a lui ' . $proprietar)
                            . ' (config/plugins.php → tabele); o adaugă o migrare a lui ' . $al);
                    }
                } elseif (in_array($o['fel'], ['index', 'unic'], true) && str_contains($obiect, '.')) {
                    [$tabel, $index] = explode('.', $obiect, 2);
                    $ai = [$alCui($tabel)];
                    foreach ((array) ($asteptat['tabele'][$tabel]['indecsi'][$index]['coloane'] ?? []) as $c) {
                        $ai[] = $alCui($tabel . '.' . preg_replace('/\(\d+\)$/', '', (string) $c));
                    }
                    if (!in_array($al, $ai, true)) {
                        $incalca($m['id'] . ': indexul ' . $obiect . ' nu e al lui ' . $al . ' (nici tabelul, nici o coloană a lui, în catalog)');
                    }
                }
            }
        }
        // Fiecare tabel și fiecare coloană din asteptat.json are un proprietar în catalog.
        foreach ((array) $asteptat['tabele'] as $tabel => $t) {
            if ($alCui((string) $tabel) === null) {
                $incalca('asteptat.json: tabelul ' . $tabel . ' nu e al nimănui în catalog (config/plugins.php → tabele)');
            }
            if (!isset($create[$tabel])) {
                $incalca('asteptat.json: tabelul ' . $tabel . ' nu-l face nicio migrare pe o bază goală');
            }
        }
    }
    $bazaVerificata = true;
}

// ------------------------------------------------------------------------------------------ rezultat
foreach ($incalcari as $text) {
    echo '  EȘEC  ', $text, "\n";
}
$numarDdl = array_sum(array_map(static fn (array $x): int => $x[0], array_filter($gasite, static fn (string $k): bool => explode("\t", $k)[2] === 'ddl', ARRAY_FILTER_USE_KEY)));
$numarDef = array_sum(array_column($gasite, 0)) - $numarDdl;
$functii = count(array_unique(array_map(static fn (string $k): string => implode("\t", array_slice(explode("\t", $k), 0, 2)), array_keys($gasite))));
echo 'verifica-migrari: ', count($migrari), ' migrări (ultima ', sprintf('%04d', $migrari === [] ? 0 : (int) end($migrari)['ord']), '), DDL vechi în cod: ', $numarDdl,
    ' texte și ', $numarDef, ' definiții de coloane în ', $functii, ' funcții/fișiere; ',
    $bazaVerificata ? 'asteptat.json și proprietarii verificați pe o bază goală' : 'FĂRĂ bază: asteptat.json și proprietarii obiectelor NEverificați (--fara-baza)',
    '; ', count($incalcari) === 0 ? 'nicio încălcare' : count($incalcari) . ' încălcări', ".\n";
exit($incalcari === [] ? 0 : 1);
