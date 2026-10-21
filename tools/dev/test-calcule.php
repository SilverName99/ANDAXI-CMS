<?php

declare(strict_types=1);

/**
 * Testele comparației calculelor (golden master, WP-11, G2; docs/VERIFICARE.md, „Comparația calculelor”):
 * tools/verificare/compara-calcule.php și lucrator-calcule.php, față de codul Bioscem însuși (commit-ul revizuit din
 * docs/de-portat.md, același cod de bază ca poarta G-cmp), pe datele demo. Îl rulează tools/dev/compara-calcule-local.sh
 * (poarta G-calc), care scoate întâi codul de bază:
 *
 *   tools/dev/php.sh tools/dev/test-calcule.php --cod-vechi=<copia codului Bioscem> [--commit-vechi=<sha>]
 *
 * A. Local (`--local`), pe cms_calc_t, o copie a bazei de dezvoltare plus comenzi inventate care acoperă fiecare tip de caz
 *    (puncte peste disponibil, sub minim, cu cupon; cupoane fixe, procentuale, expirate, oprite, unice folosite, inexistente,
 *    pe produse, pe categorii, fără cumulare, pentru un client; BBD, FANbox, firmă, București, reducere și transport puse de
 *    mână, precomandă, produse inactive și șterse, coș gol, peste și sub pragul de transport gratuit, o localitate cu km în
 *    plus):
 *    A1 comparația cu datele demo: „REZULTAT: TRECE”, „diferite 0”, fiecare tip de caz prezent, cupoane și puncte aplicate
 *       în ambele coduri, același payload ERP; fiecare rând al ieșirii are una din formele știute (doar numere, nume de
 *       câmpuri și de tipuri), niciun nume de produs, număr de comandă, email sau număr de bază; cupoanele puse la loc
 *       (CHECKSUM TABLE), niciun fișier de sesiune, dosarul temporar gol;
 *    A2 cu prețurile fixe ale transportului (de bază, FANbox, km suplimentari): tot „TRECE”;
 *    A3 --fara-cupoane-valide (mai puține cupoane aplicate, tot „TRECE”: punerea cupoanelor contează), --prin-fisiere
 *       (aceleași numere, fișierele șterse), --php-vechi=php<min>, --n;
 *    A4 câte o mutație în codul comun, într-o copie (`--cod-nou`): totalul, reducerea procentuală, prețul BBD, punctele,
 *       transportul din admin, punctele promise, o cheie a payload-ului ERP, o excepție: fiecare „PICĂ” și își numește
 *       câmpul (sau cheia, sau eroarea), deci comparația chiar vede fiecare calcul;
 *    A5 refuzurile (cod 2, nimic schimbat): fără --local în afara site-ului de verificare, altă bază decât cms_*, cu
 *       SITE_COPIE_VERIFICARE=1, un .env în codul vechi, codul vechi lipsă, alt profil, baza în urma migrărilor, o licență cu
 *       cupoanele oprite; lucrătorul pornit direct pe altă bază sau fără _verificare; o rulare oprită după cupoane → rularea
 *       următoare le pune la loc întâi.
 * B. Pe un site de verificare simulat ($CMS_DEV_DIR/calcule/acasa/domains/verificare.exemplu.test/public_html: copia codului
 *    din git, .env-ul copiei, licența de verificare pusă de licenta.php; codul vechi în cod-vechi/bioscem/ cu .env-ul lui;
 *    baza cms_calc_copie cu _verificare și un utilizator cu parolă), cu mediul gol ca pe Hostinger:
 *    B1 baza în urma codului comun (o migrare neaplicată) → migreaza --aplica întâi, apoi „TRECE”; a doua rulare „la zi”;
 *       textul salvat în storage/verificare/sonde/ (0600); nicio parolă, nume de bază, utilizator, adresă sau cale a contului;
 *    B2 refuzurile: _verificare lipsă, neîncheiat, din altă sursă, prea vechi; incarcat-la expirat; .env fără
 *       SITE_COPIE_VERIFICARE=1; alt HOME; baza în .env-ul altui site din cont; codul vechi lipsă, legătură sau cu .env-ul
 *       spre altă bază; --cod-vechi și --local pe server; o licență cu cupoanele oprite;
 *    B3 un PHP sub minim: mesajul, nu o eroare de sintaxă.
 *
 * Își face și își șterge bazele cms_calc_t, cms_calc_copie, cms_calc_alta și utilizatorul cms_calc_test; baza de dezvoltare
 * trebuie să fie făcută de tools/dev/instaleaza.sh (o copiază; n-o schimbă). Raportul: $CMS_DEV_DIR/test-calcule-raport.txt;
 * cod de ieșire 0 doar dacă totul a trecut. Toate datele sunt inventate.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
// Clasele codului comun (normalizarea localităților FAN), fără bootstrap.php: testul nu e o cerere a site-ului.
spl_autoload_register(static function (string $clasa) use ($radacina): void {
    if (str_starts_with($clasa, 'App\\') && is_file($fisier = $radacina . '/src/' . str_replace('\\', '/', substr($clasa, 4)) . '.php')) {
        require_once $fisier;
    }
});
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-calcule-raport.txt';
@file_put_contents($fisierRaport, '');
$trecute = 0;
$esuate = 0;
$scrie = static function (string $linie) use ($fisierRaport): void {
    echo $linie, "\n";
    file_put_contents($fisierRaport, $linie . "\n", FILE_APPEND);
};
$verifica = static function (string $eticheta, bool $ok, string $detalii = '') use (&$trecute, &$esuate, $scrie): void {
    if ($ok) {
        $trecute++;
        $scrie('  OK    ' . $eticheta);
    } else {
        $esuate++;
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . mb_substr($detalii, 0, 3000) : ''));
    }
};

$codVechi = '';
$commitVechi = '';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--cod-vechi=')) {
        $codVechi = (string) realpath(substr($argument, 12));
    } elseif (str_starts_with($argument, '--commit-vechi=')) {
        $commitVechi = substr($argument, 15);
    } else {
        fwrite(STDERR, "argument necunoscut: $argument\n");
        exit(1);
    }
}
if ($codVechi === '' || !is_file($codVechi . '/src/Support/CheckoutCalculator.php')) {
    fwrite(STDERR, "Lipsește --cod-vechi=<copia codului Bioscem> (o face tools/dev/compara-calcule-local.sh).\n");
    exit(1);
}
$scrie('Test comparația calculelor ANDAXI-CMS — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION . ', codul vechi ' . basename($codVechi));

$sock = (string) (getenv('CMS_DEV_SOCK') ?: '/tmp/claude-0/maria/sock');
$bazaDev = (string) getenv('DB_DATABASE');
$bazaT = 'cms_calc_t';
$bazaCopie = 'cms_calc_copie';
$bazaAlta = 'cms_calc_alta';
$utilizator = 'cms_calc_test';
$parolaDb = 'Pdb' . bin2hex(random_bytes(8));
$dosarTest = $dosarDev . '/calcule';
$acasa = $dosarTest . '/acasa';
$gazda = 'verificare.exemplu.test';
$copie = $acasa . '/domains/' . $gazda . '/public_html';
$codVechiV = $acasa . '/domains/' . $gazda . '/cod-vechi/bioscem';
$codMutat = $dosarTest . '/cod-nou';
$dosarTmpLocal = $dosarDev . '/calcule-tmp';
$emailProprietar = 'proprietar.calcule@exemplu.ro';
$phpMinim = preg_match("/'php_min'\\s*=>\\s*'([0-9.]+)'/", (string) file_get_contents($radacina . '/config/app.php'), $p) === 1 ? $p[1] : '';

$conectare = static function (?string $db = null) use ($sock): PDO {
    return new PDO('mysql:unix_socket=' . $sock . ($db !== null ? ';dbname=' . $db : '') . ';charset=utf8mb4', (string) (getenv('CMS_DEV_DB_USER') ?: 'root'),
        (string) (getenv('CMS_DEV_DB_PAROLA') ?: ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
};
$curata = static function () use ($conectare, $bazaT, $bazaCopie, $bazaAlta, $utilizator, $dosarTest, $dosarDev, $dosarTmpLocal): void {
    try {
        $root = $conectare();
        foreach ([$bazaT, $bazaCopie, $bazaAlta] as $b) {
            $root->exec('DROP DATABASE IF EXISTS ' . $b);
        }
        $root->exec("DROP USER IF EXISTS '" . $utilizator . "'@'localhost'");
    } catch (Throwable) {
    }
    if (str_starts_with($dosarTest, $dosarDev . '/') && is_dir($dosarTest)) {
        exec('chmod -R u+rwX ' . escapeshellarg($dosarTest) . ' 2>/dev/null; rm -rf ' . escapeshellarg($dosarTest));
    }
    foreach (glob($dosarTmpLocal . '/calcule-cupoane-*') ?: [] as $f) {
        @unlink($f);
    }
};
register_shutdown_function($curata);
$curata();

/** O comandă a uneltelor (proc_open, cu termen), cu mediul dat și intrarea pe stdin. */
$proces = static function (array $comanda, array $mediu, string $cwd, string $intrare = '', int $termen = 900): array {
    $p = proc_open($comanda, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tevi, $cwd, $mediu);
    if (!is_resource($p)) {
        return ['cod' => -1, 'iesire' => '', 'erori' => 'nu pornește', 'tot' => 'nu pornește'];
    }
    fwrite($tevi[0], $intrare);
    fclose($tevi[0]);
    stream_set_blocking($tevi[1], false);
    stream_set_blocking($tevi[2], false);
    $iesire = '';
    $erori = '';
    $limita = microtime(true) + $termen;
    while (!feof($tevi[1]) || !feof($tevi[2])) {
        $citire = array_values(array_filter([$tevi[1], $tevi[2]], static fn ($t): bool => !feof($t)));
        $w = null;
        $e = null;
        if (@stream_select($citire, $w, $e, 1) === false) {
            break;
        }
        foreach ($citire as $t) {
            $bucata = (string) fread($t, 65536);
            if ($t === $tevi[1]) {
                $iesire .= $bucata;
            } else {
                $erori .= $bucata;
            }
        }
        if (microtime(true) > $limita) {
            proc_terminate($p, 9);
            $erori .= "\n[TIMP DEPĂȘIT]";
            break;
        }
    }
    fclose($tevi[1]);
    fclose($tevi[2]);
    $cod = proc_close($p);
    return ['cod' => $cod, 'iesire' => $iesire, 'erori' => $erori, 'tot' => $iesire . $erori];
};
$ini = ['-d', 'pdo_mysql.default_socket=' . $sock, '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', '-d', 'log_errors=0',
    '-d', 'memory_limit=512M', '-d', 'allow_url_fopen=0'];
/** compara-calcule.php local (codul de aici), cu mediul uneltelor de dezvoltare pe baza dată. */
$compara = static function (array $argumente, array $mediuInPlus = [], string $php = PHP_BINARY) use ($proces, $ini, $radacina, $bazaT): array {
    $mediu = array_merge(getenv(), ['DB_DATABASE' => $bazaT], $mediuInPlus);
    foreach ($mediu as $k => $v) {
        if ($v === null) {
            unset($mediu[$k]);
        }
    }
    return $proces(array_merge([$php], $ini, [$radacina . '/tools/verificare/compara-calcule.php'], $argumente), $mediu, $radacina);
};
$ultimul = static fn (string $text): string => trim((string) strrchr("\n" . trim($text), "\n"));
$numar = static fn (string $text, string $tipar): ?int => preg_match($tipar, $text, $m) === 1 ? (int) $m[1] : null;

/** Formele permise ale rândurilor ieșirii: doar numere, DA/NU, nume de câmpuri, de tipuri de caz, de chei ERP, locuri din cod. */
$forme = [
    '/^Comparația calculelor \(WP-11\): codul vechi al site-ului față de codul comun, pe aceeași bază, aceleași comenzi$/u',
    '/^  codul vechi: (baza-[A-Za-z0-9.-]+|cod-vechi\/bioscem\/ \(lângă public_html\)(, commit [0-9a-f]{7})?), PHP \d+\.\d+\.\d+$/u',
    '/^  codul comun: ([a-z0-9-]+, )?(commit [0-9a-f]{7}|fără \.git), PHP \d+\.\d+\.\d+$/u',
    '/^  baza: (o bază cms_\* a uneltelor de dezvoltare \(date demo\)|copia de verificare bioscem, încărcată la \d{4}-\d\d-\d\d \d\d:\d\d UTC, anonimizată)$/u',
    '/^  migrările: (baza e la zi cu codul comun|baza era „[a-z_]+”; aplicate acum \(scripts\/migreaza\.php --aplica --site=bioscem, cod 0\), la zi)$/u',
    '/^  pluginurile site-ului pe codul comun: toate pornite$/u',
    '/^  setările calculelor \(informativ\): \d+ chei, (aceleași valori în ambele coduri|alte valori în codul comun la \d+: [a-z0-9_, ]+)$/u',
    '/^  cupoane: (\d+ făcute valabile pe durata comparației \(--fara-cupoane-valide le lasă cum sunt\)|lăsate cum sunt \(--fara-cupoane-valide\))$/u',
    '/^  cupoanele unei rulări oprite \(\d+\): puse la loc înainte de orice$/u',
    '/^  cupoane puse la loc la final: DA$/u',
    '/^cazuri \d+ \| identice \d+ \| diferite \d+ \| erori vechi \d+ \| erori nou \d+$/u',
    '/^cazuri pe tip: (—|[a-z_]+ \d+(, [a-z_]+ \d+)*)$/u',
    '/^cupoane aplicate fără eroare: vechi \d+, nou \d+ \| puncte folosite: vechi \d+, nou \d+$/u',
    '/^diferențe pe câmp: (—|[a-z_.]+ \d+(, [a-z_.]+ \d+)*)$/u',
    '/^diferențe pe tip de caz: (—|[a-z_+]+ \d+(, [a-z_+]+ \d+)*)$/u',
    '/^payload ERP: identice \d+ \| chei diferite: (—|[A-Za-z0-9_()ă]+ \d+(, [A-Za-z0-9_()ă]+ \d+)*)$/u',
    '/^transportul din admin: identice \d+ \| punctele promise: identice \d+$/u',
    '/^erori: vechi (—|[A-Za-z\\\\]+ în [A-Za-z0-9._\/-]+:\d+ ×\d+(, [A-Za-z\\\\]+ în [A-Za-z0-9._\/-]+:\d+ ×\d+)*) \| nou (—|[A-Za-z\\\\]+ în [A-Za-z0-9._\/-]+:\d+ ×\d+(, [A-Za-z\\\\]+ în [A-Za-z0-9._\/-]+:\d+ ×\d+)*)$/u',
    '/^informativ: total nou = total salvat la \d+\/\d+ \(diferențele vin din prețurile, cupoanele și transportul de acum, nu din cod\)$/u',
    '/^avertismente PHP: vechi \d+, nou \d+( \(vechi: .*; nou: .*\))?$/u',
    '/^fișiere de sesiune lăsate de lucrători: \d+( \(șterse acum\))?$/u',
    '/^REZULTAT: (TRECE|PICĂ)$/u',
    '/^Textul e salvat în storage\/verificare\/sonde\/calcule-bioscem-\d{8}-\d{6}\.txt \(ni-l trimiți; nu are date ale clienților\)\.$/u',
];
$randuriStraine = static function (string $iesire) use ($forme): array {
    $straine = [];
    foreach (explode("\n", trim($iesire)) as $rand) {
        $bun = false;
        foreach ($forme as $forma) {
            if (preg_match($forma, $rand) === 1) {
                $bun = true;
                break;
            }
        }
        if (!$bun) {
            $straine[] = $rand;
        }
    }
    return $straine;
};
$sumaCupoane = static fn (PDO $db): string => (string) $db->query('CHECKSUM TABLE coupons')->fetch(PDO::FETCH_NUM)[1];
$sesiuni = static fn (string $dosar): int => count(glob($dosar . '/storage/sessions/sess_*') ?: []);
$copiazaCodul = static function (string $din, string $in, bool $dinGit) use ($radacina): int {
    $fisiere = [];
    if ($dinGit) {
        foreach (array_filter(explode("\0", (string) shell_exec('git -C ' . escapeshellarg($din) . ' ls-files -z --cached --others --exclude-standard'))) as $f) {
            $fisiere[] = $f;
        }
    } else {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($din, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $fisiere[] = substr($f->getPathname(), strlen($din) + 1);
        }
    }
    $n = 0;
    foreach ($fisiere as $f) {
        if (!is_file($din . '/' . $f) || is_link($din . '/' . $f) || str_starts_with($f, '.env') || str_starts_with($f, '.git/') || str_starts_with($f, 'public/uploads/')
            || (str_starts_with($f, 'storage/') && !in_array(basename($f), ['.gitkeep', '.gitignore', '.htaccess'], true))) {
            continue;
        }
        @mkdir(dirname($in . '/' . $f), 0770, true);
        copy($din . '/' . $f, $in . '/' . $f);
        $n++;
    }
    foreach (['storage/sessions', 'storage/cache', 'storage/logs', 'storage/licenta', 'storage/verificare'] as $d) {
        @mkdir($in . '/' . $d, 0770, true);
    }
    return $n;
};
$copiazaBaza = static function (PDO $root, string $din, string $in): int {
    $root->exec('DROP DATABASE IF EXISTS `' . $in . '`');
    $root->exec('CREATE DATABASE `' . $in . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $tabele = $root->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' . $root->quote($din) . " AND TABLE_TYPE = 'BASE TABLE'")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tabele as $t) {
        $t = '`' . str_replace('`', '``', (string) $t) . '`';
        $root->exec('CREATE TABLE `' . $in . '`.' . $t . ' LIKE `' . $din . '`.' . $t);
        $root->exec('INSERT INTO `' . $in . '`.' . $t . ' SELECT * FROM `' . $din . '`.' . $t);
    }
    return count($tabele);
};

try {
    $root = $conectare();
    if (preg_match('/^cms_[a-z0-9_]+$/', $bazaDev) !== 1
        || (int) $root->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' . $root->quote($bazaDev)
            . " AND TABLE_NAME IN ('_platforma_stare', 'orders', 'coupons')")->fetchColumn() !== 3) {
        throw new RuntimeException('baza de dezvoltare (DB_DATABASE) lipsește sau nu e făcută prin migrări: rulează întâi tools/dev/instaleaza.sh');
    }
    $sumaDevInainte = implode(',', $root->query('CHECKSUM TABLE `' . $bazaDev . '`.coupons, `' . $bazaDev . '`.orders')->fetchAll(PDO::FETCH_COLUMN, 1));

    // =====================================================================
    // A. Local
    // =====================================================================
    $scrie('');
    $scrie('A. Local (--local), pe ' . $bazaT . ': o copie a bazei de dezvoltare, cu comenzi inventate pentru fiecare tip de caz');
    $tabeleCopiate = $copiazaBaza($root, $bazaDev, $bazaT);
    $db = $conectare($bazaT);
    $coloane = static function (PDO $db, string $tabel): array {
        return $db->query('SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, DATA_TYPE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '
            . $db->quote($tabel))->fetchAll();
    };
    /** Un rând nou, cu coloanele obligatorii fără valoare implicită completate (text gol, 0, acum). */
    $randNou = static function (PDO $db, string $tabel, array $valori) use ($coloane): int {
        foreach ($coloane($db, $tabel) as $c) {
            if (array_key_exists($c['COLUMN_NAME'], $valori) || $c['IS_NULLABLE'] === 'YES' || $c['COLUMN_DEFAULT'] !== null
                || str_contains((string) $c['EXTRA'], 'auto_increment')) {
                continue;
            }
            $valori[$c['COLUMN_NAME']] = in_array($c['DATA_TYPE'], ['int', 'tinyint', 'smallint', 'bigint', 'decimal', 'float', 'double'], true) ? 0
                : (in_array($c['DATA_TYPE'], ['datetime', 'timestamp', 'date'], true) ? date('Y-m-d H:i:s') : '');
        }
        $db->prepare('INSERT INTO `' . $tabel . '` (`' . implode('`, `', array_keys($valori)) . '`) VALUES (' . implode(', ', array_fill(0, count($valori), '?')) . ')')
            ->execute(array_values($valori));
        return (int) $db->lastInsertId();
    };
    $bbd34 = (string) $db->query("SELECT bbd_key FROM order_items WHERE product_id = 34 AND bbd_key <> '' LIMIT 1")->fetchColumn();
    $bbd29 = (string) $db->query("SELECT bbd_key FROM order_items WHERE product_id = 29 AND bbd_key <> '' LIMIT 1")->fetchColumn();
    $clientPuncte = (int) $db->query('SELECT id FROM users WHERE loyalty_points >= 300 ORDER BY loyalty_points DESC LIMIT 1')->fetchColumn();
    $clientAlt = (int) $db->query('SELECT id FROM users WHERE id <> ' . $clientPuncte . ' AND loyalty_points > 0 ORDER BY loyalty_points DESC LIMIT 1')->fetchColumn();
    $clientPutine = (int) $db->query('SELECT id FROM users WHERE id NOT IN (' . $clientPuncte . ', ' . $clientAlt . ') AND loyalty_points > 0 ORDER BY id LIMIT 1')->fetchColumn();
    $lockere = array_map('intval', $db->query('SELECT id FROM fan_lockers ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
    $verifica('baza ' . $bazaT . ': ' . $tabeleCopiate . ' tabele copiate din baza de dezvoltare; datele demo au produse BBD, clienți cu puncte și lockere FANbox',
        $tabeleCopiate > 40 && $bbd34 !== '' && $bbd29 !== '' && $clientPuncte > 0 && $clientAlt > 0 && $clientPutine > 0 && count($lockere) === 2);

    // Cupoanele inventate (pe produse, pe categorie, fără cumulare, pentru un client).
    $acum = date('Y-m-d H:i:s');
    foreach ([
        ['CALC-PRODUS', 'percent', 12, ['product_ids_json' => '[4]', 'apply_only_selected_products' => 1]],
        ['CALC-FARA-CUMUL', 'percent', 10, ['stacks_with_discounts' => 0]],
        ['CALC-CATEGORIE', 'fixed', 7, ['category_ids_json' => '[7]']],
        ['CALC-CATEGORIE-DOAR', 'percent', 20, ['category_ids_json' => '[7]', 'product_ids_json' => '[4,5]', 'apply_only_selected_products' => 1]],
        ['CALC-CLIENT', 'fixed', 5, ['allowed_user_ids_json' => '[' . $clientPuncte . ']']],
        ['CALC-ARTICOLE', 'percent', 15, ['min_items_count' => 3, 'max_items_count' => 6]],
    ] as [$codCupon, $tip, $valoare, $extra]) {
        $randNou($db, 'coupons', ['name' => 'Cupon de test ' . $codCupon, 'code' => $codCupon, 'type' => $tip, 'value' => $valoare, 'is_active' => 1] + $extra);
    }
    // Comenzile inventate: [linii [produs, cantitate, cheie BBD], cupon, client, puncte, județ, oraș, FANbox, firmă, reducere manuală, transport manual].
    $comenziInventate = [
        'puncte' => [[[1, 2, ''], [4, 3, '']], '', $clientPuncte, 150, 'Cluj', 'Cluj-Napoca', 0, 0, 0, null],
        'puncte peste disponibil' => [[[2, 1, '']], '', $clientAlt, 5000, 'Iasi', 'Iasi', 0, 0, 0, null],
        'puncte sub minim' => [[[5, 1, ''], [11, 2, '']], '', $clientPutine, 50, 'Timis', 'Timisoara', 0, 0, 0, null],
        'puncte fără cont' => [[[33, 1, '']], '', 0, 200, 'Arad', 'Arad', 0, 0, 0, null],
        'puncte și cupon procent' => [[[37, 1, '']], 'DEMO10', $clientPuncte, 120, 'Bucuresti', 'Sector 1', 0, 1, 0, null],
        'cupon fix, București, firmă' => [[[13, 1, '']], 'LIVRARE15', 0, 0, 'Bucuresti', 'Sector 3', 0, 1, 0, null],
        'cupon expirat, FANbox' => [[[26, 2, '']], 'VARA2026', 0, 0, 'Bucuresti', 'Sector 6', $lockere[0], 0, 0, null],
        'cupon unic folosit, BBD' => [[[34, 1, $bbd34], [3, 1, '']], 'UNIC-DEMO-0001', 0, 0, 'Brasov', 'Brasov', 0, 0, 0, null],
        'cupon oprit, produs redus' => [[[6, 2, '']], 'OPRIT20', 0, 0, 'Constanta', 'Constanta', 0, 0, 0, null],
        'cupon inexistent' => [[[7, 1, '']], 'NU-EXISTA-CALC', 0, 0, 'Sibiu', 'Sibiu', 0, 0, 0, null],
        'cupon pe produs' => [[[4, 2, ''], [5, 1, '']], 'CALC-PRODUS', 0, 0, 'Cluj', 'Turda', 0, 0, 0, null],
        'cupon fără cumulare' => [[[6, 1, ''], [7, 2, '']], 'CALC-FARA-CUMUL', 0, 0, 'Prahova', 'Ploiesti', 0, 0, 0, null],
        'cupon pe categorie, alt produs' => [[[4, 1, ''], [1, 1, '']], 'CALC-CATEGORIE', 0, 0, 'Dolj', 'Craiova', 0, 0, 0, null],
        'cupon pe categorie' => [[[4, 1, ''], [5, 2, '']], 'CALC-CATEGORIE', 0, 0, 'Dolj', 'Craiova', 0, 0, 0, null],
        'cupon pe produse, cu altele' => [[[4, 1, ''], [5, 1, ''], [1, 1, '']], 'CALC-CATEGORIE-DOAR', 0, 0, 'Bihor', 'Oradea', 0, 0, 0, null],
        'cupon pentru client' => [[[10, 3, '']], 'CALC-CLIENT', $clientPuncte, 0, 'Bacau', 'Bacau', 0, 0, 0, null],
        'cupon pentru alt client' => [[[10, 3, '']], 'CALC-CLIENT', $clientAlt, 0, 'Bacau', 'Bacau', 0, 0, 0, null],
        'cupon cu număr de articole' => [[[19, 2, ''], [23, 2, '']], 'CALC-ARTICOLE', 0, 0, 'Arges', 'Pitesti', 0, 0, 0, null],
        'cupon cu prea puține articole' => [[[19, 1, '']], 'CALC-ARTICOLE', 0, 0, 'Arges', 'Pitesti', 0, 0, 0, null],
        'reducere și transport puse de mână' => [[[14, 1, '']], '', 0, 0, 'Mures', 'Targu Mures', 0, 0, 10, 25.0],
        'precomandă' => [[[36, 2, ''], [38, 1, '']], '', 0, 0, 'Galati', 'Galati', 0, 0, 0, null],
        'produse inactive și șterse' => [[[8, 1, ''], [22, 1, ''], [19, 1, ''], [0, 1, '']], '', 0, 0, 'Buzau', 'Buzau', 0, 0, 0, null],
        'coș gol' => [[], '', 0, 0, 'Bucuresti', 'Sector 2', 0, 0, 0, null],
        'peste pragul de transport gratuit' => [[[40, 2, '']], '', 0, 0, 'Bucuresti', 'Sector 4', 0, 0, 0, null],
        'sub prag, provincie' => [[[19, 1, '']], '', 0, 0, 'Vaslui', 'Vaslui', 0, 0, 0, null],
        'FANbox cu BBD' => [[[29, 2, $bbd29], [24, 1, '']], '', 0, 0, 'Bucuresti', 'Sector 5', $lockere[1], 0, 0, null],
        'localitate cu km în plus' => [[[3, 1, '']], '', 0, 0, 'Cluj', 'Comuna Departe De Test', 0, 0, 0, null],
        'firmă, puncte, București, FANbox' => [[[20, 2, ''], [27, 1, '']], 'DEMO10', $clientPuncte, 100, 'Bucuresti', 'Sector 1', $lockere[0], 1, 0, null],
    ];
    $nrInventate = 0;
    foreach ($comenziInventate as $nume => [$linii, $cupon, $client, $puncte, $judet, $oras, $locker, $firma, $manual, $transportManual]) {
        $subtotal = 0.0;
        foreach ($linii as [$produs, $cantitate]) {
            $pret = $produs > 0 ? (float) $db->query('SELECT price FROM products WHERE id = ' . (int) $produs)->fetchColumn() : 5.0;
            $subtotal += $pret * $cantitate;
        }
        $nrInventate++;
        $id = $randNou($db, 'orders', [
            'order_number' => 'CALC-' . $nrInventate, 'user_id' => $client > 0 ? $client : null, 'status' => 'processing', 'payment_method' => 'cod',
            'payment_status' => 'pending', 'shipping_method' => $locker > 0 ? 'fan_box' : 'fan_courier', 'fan_locker_id' => $locker > 0 ? $locker : null,
            'shipping_cost' => $transportManual ?? 15.0, 'shipping_manual' => $transportManual !== null ? 1 : 0, 'coupon_code' => $cupon !== '' ? $cupon : null,
            'discount_total' => $cupon !== '' ? 5.0 : 0.0, 'subtotal' => round($subtotal, 2), 'total' => round($subtotal + 15 - $manual, 2),
            'manual_discount' => $manual, 'loyalty_points_used' => $puncte, 'loyalty_points_discount' => round($puncte * 0.1, 2),
            'billing_first_name' => 'Client', 'billing_last_name' => 'Calcul ' . $nrInventate, 'billing_phone' => '0700000' . str_pad((string) $nrInventate, 3, '0', STR_PAD_LEFT),
            'billing_email' => 'calcul.' . $nrInventate . '@exemplu.ro', 'billing_address_line1' => 'Strada Exemplu ' . $nrInventate, 'billing_city' => $oras,
            'billing_county' => $judet, 'billing_postcode' => '000000', 'billing_is_company' => $firma, 'billing_company_name' => $firma ? 'Firma Calcul SRL' : null,
            'shipping_same_as_billing' => 1, 'created_at' => date('Y-m-d H:i:s', time() - 86400 * 10), 'erp_status' => 'skipped',
        ]);
        foreach ($linii as [$produs, $cantitate, $cheie]) {
            $randNou($db, 'order_items', ['order_id' => $id, 'product_id' => $produs > 0 ? $produs : null, 'product_name' => 'Produs de test', 'quantity' => $cantitate,
                'unit_price' => 10.0, 'line_total' => 10.0 * $cantitate, 'bbd_key' => $cheie !== '' ? $cheie : null]);
        }
    }
    // Localitatea cu km suplimentari (prețurile fixe, A2), scrisă cu aceeași normalizare ca lista importată.
    $normalizeaza = Closure::bind(static fn (string $v): string => self::normalizeaza($v), null, \App\Support\ShippingPricing::class);
    $normalizeazaJudet = Closure::bind(static fn (string $v): string => self::normalizeazaJudet($v), null, \App\Support\ShippingPricing::class);
    $randNou($db, 'fan_localities_extra_km', ['county' => 'Cluj', 'locality' => 'Comuna Departe De Test', 'county_norm' => $normalizeazaJudet('Cluj'),
        'locality_norm' => $normalizeaza('Comuna Departe De Test'), 'exterior_km' => 12, 'created_at' => $acum, 'updated_at' => $acum]);
    $verifica($nrInventate . ' comenzi inventate și 6 cupoane inventate în ' . $bazaT, $nrInventate === count($comenziInventate));

    // ── A1 ──
    $scrie('');
    $scrie('A1. Comparația pe datele demo și pe comenzile inventate');
    $sumaInainte = $sumaCupoane($db);
    $sesiuniInainte = [$sesiuni($codVechi), $sesiuni($radacina)];
    $r = $compara(['--local', '--cod-vechi=' . $codVechi]);
    foreach (explode("\n", trim($r['iesire'])) as $rand) {
        $scrie('        | ' . $rand);
    }
    $iesireA1 = $r['iesire'];
    $cazuri = $numar($r['iesire'], '/^cazuri (\d+) \|/m');
    $comenziDb = (int) $db->query("SELECT COUNT(*) FROM orders WHERE deleted_at IS NULL AND status <> 'failed'")->fetchColumn();
    $verifica('compara-calcule.php --local: cod 0, „REZULTAT: TRECE”, „diferite 0”, nicio eroare în niciun cod', $r['cod'] === 0
        && $ultimul($r['iesire']) === 'REZULTAT: TRECE' && str_contains($r['iesire'], '| diferite 0 | erori vechi 0 | erori nou 0') && trim($r['erori']) === '',
        'cod ' . $r['cod'] . ': ' . $r['tot']);
    $verifica('fiecare comandă nu ștearsă și nu eșuată e un caz (' . $comenziDb . ', între ele cele ' . $nrInventate . ' inventate)', $cazuri === $comenziDb, (string) $cazuri);
    $tipuri = [];
    if (preg_match('/^cazuri pe tip: (.*)$/m', $r['iesire'], $m) === 1) {
        foreach (explode(', ', $m[1]) as $parte) {
            [$t, $n] = explode(' ', $parte) + [1 => '0'];
            $tipuri[$t] = (int) $n;
        }
    }
    $lipsa = array_values(array_filter(['bbd', 'cupon_fix', 'cupon_inexistent', 'cupon_procent', 'fanbox', 'firma', 'judet_bucuresti', 'puncte', 'simplu'],
        static fn (string $t): bool => ($tipuri[$t] ?? 0) < 1));
    $verifica('fiecare tip de caz e acoperit (bbd, cupon_fix, cupon_inexistent, cupon_procent, fanbox, firma, judet_bucuresti, puncte, simplu)', $lipsa === [],
        implode(', ', $lipsa));
    $cupoaneVechi = $numar($r['iesire'], '/^cupoane aplicate fără eroare: vechi (\d+),/m');
    $cupoaneNou = $numar($r['iesire'], '/^cupoane aplicate fără eroare: vechi \d+, nou (\d+)/m');
    $puncteNou = $numar($r['iesire'], '/puncte folosite: vechi \d+, nou (\d+)$/m');
    $verifica('cupoane aplicate în ambele coduri, la fel (' . $cupoaneNou . '), puncte folosite (' . $puncteNou . '), cupoane făcute valabile',
        $cupoaneVechi === $cupoaneNou && $cupoaneNou >= 8 && $puncteNou >= 3 && preg_match('/^  cupoane: ([1-9]\d*) făcute valabile/m', $r['iesire']) === 1);
    $verifica('payload ERP, transportul din admin și punctele promise: identice pe toate cazurile', $cazuri !== null
        && str_contains($r['iesire'], 'payload ERP: identice ' . $cazuri . ' | chei diferite: —')
        && str_contains($r['iesire'], 'transportul din admin: identice ' . $cazuri . ' | punctele promise: identice ' . $cazuri));
    $straine = $randuriStraine($r['iesire']);
    $nume = $db->query("SELECT name FROM products WHERE CHAR_LENGTH(name) > 6")->fetchAll(PDO::FETCH_COLUMN);
    $numere = $db->query('SELECT order_number FROM orders')->fetchAll(PDO::FETCH_COLUMN);
    $gasite = array_values(array_filter(array_merge($nume, $numere, [$bazaT, $bazaDev, '@']), static fn ($v): bool => $v !== '' && str_contains($r['tot'], (string) $v)));
    $verifica('ieșirea: fiecare rând are o formă știută (doar numere, nume de câmpuri și de tipuri), fără nume de produse, numere de comenzi, emailuri '
        . 'sau numele bazei', $straine === [] && $gasite === [], implode(' | ', array_merge($straine, $gasite)));
    $verifica('cupoanele puse la loc (CHECKSUM TABLE coupons neschimbat), niciun fișier de sesiune nou, dosarul temporar gol, raportul local scris',
        $sumaCupoane($db) === $sumaInainte && [$sesiuni($codVechi), $sesiuni($radacina)] === $sesiuniInainte
        && (glob($dosarTmpLocal . '/*') ?: []) === [] && is_file($dosarDev . '/calcule-raport.txt'));

    // ── A2 ──
    $scrie('');
    $scrie('A2. Cu prețurile fixe ale transportului (de bază, FANbox, km suplimentari)');
    foreach (['shipping_fixed_enabled' => '1', 'shipping_fixed_base' => '19', 'shipping_fixed_fanbox_enabled' => '1', 'shipping_fixed_fanbox' => '14.5',
        'shipping_fixed_extra_km' => '11'] as $cheie => $valoare) {
        $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute([$cheie, $valoare]);
    }
    $r = $compara(['--local', '--cod-vechi=' . $codVechi]);
    $verifica('prețurile fixe: „TRECE”, „diferite 0”, același număr de cazuri', $r['cod'] === 0 && $ultimul($r['iesire']) === 'REZULTAT: TRECE'
        && str_contains($r['iesire'], '| diferite 0 | erori vechi 0 | erori nou 0') && $numar($r['iesire'], '/^cazuri (\d+) \|/m') === $cazuri, $r['tot']);

    // ── A3 ──
    $scrie('');
    $scrie('A3. Opțiunile: --fara-cupoane-valide, --prin-fisiere, --php-vechi, --n');
    $r = $compara(['--local', '--cod-vechi=' . $codVechi, '--fara-cupoane-valide']);
    $faraCupoane = $numar($r['iesire'], '/^cupoane aplicate fără eroare: vechi \d+, nou (\d+)/m');
    $verifica('--fara-cupoane-valide: „TRECE”, cupoanele lăsate cum sunt, mai puține aplicate (' . $faraCupoane . ' < ' . $cupoaneNou . '): punerea lor contează',
        $r['cod'] === 0 && str_contains($r['iesire'], '  cupoane: lăsate cum sunt (--fara-cupoane-valide)') && $faraCupoane !== null && $faraCupoane < (int) $cupoaneNou, $r['tot']);
    $r = $compara(['--local', '--cod-vechi=' . $codVechi, '--prin-fisiere']);
    $verifica('--prin-fisiere: „TRECE”, aceleași cazuri, cupoane aplicate ca prin țevi, dosarul temporar gol după', $r['cod'] === 0
        && $numar($r['iesire'], '/^cazuri (\d+) \|/m') === $cazuri && $numar($r['iesire'], '/^cupoane aplicate fără eroare: vechi \d+, nou (\d+)/m') === $cupoaneNou
        && (glob($dosarTmpLocal . '/*') ?: []) === [], $r['tot']);
    $phpMin = 'php' . $phpMinim;
    if (trim((string) shell_exec('command -v ' . escapeshellarg($phpMin) . ' 2>/dev/null')) !== '') {
        $r = $compara(['--local', '--cod-vechi=' . $codVechi, '--php-vechi=' . $phpMin]);
        $verifica('--php-vechi=' . $phpMin . ': codul vechi pe PHP-ul minim, „TRECE”', $r['cod'] === 0 && str_contains($r['iesire'], ', PHP ' . $phpMinim . '.')
            && $ultimul($r['iesire']) === 'REZULTAT: TRECE', $r['tot']);
    } else {
        $scrie('  INFO  ' . $phpMin . ' nu e instalat: --php-vechi nu s-a încercat acum');
    }
    $r = $compara(['--local', '--cod-vechi=' . $codVechi, '--n=5']);
    $speciale = (int) $db->query("SELECT COUNT(*) FROM orders o WHERE o.deleted_at IS NULL AND o.status <> 'failed' AND o.created_at >= (NOW() - INTERVAL 12 MONTH)
        AND ((o.coupon_code IS NOT NULL AND o.coupon_code <> '') OR o.loyalty_points_used > 0 OR o.shipping_method = 'fan_box'
        OR EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND oi.bbd_key IS NOT NULL AND oi.bbd_key <> ''))")->fetchColumn();
    $cuCinci = (int) $db->query("SELECT COUNT(*) FROM (SELECT id FROM orders WHERE deleted_at IS NULL AND status <> 'failed' ORDER BY id DESC LIMIT 5) u")->fetchColumn();
    $ultimeleCinciSpeciale = (int) $db->query("SELECT COUNT(*) FROM (SELECT o.id FROM orders o WHERE o.deleted_at IS NULL AND o.status <> 'failed' ORDER BY o.id DESC LIMIT 5) u
        JOIN orders o ON o.id = u.id WHERE o.created_at >= (NOW() - INTERVAL 12 MONTH) AND ((o.coupon_code IS NOT NULL AND o.coupon_code <> '') OR o.loyalty_points_used > 0
        OR o.shipping_method = 'fan_box' OR EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND oi.bbd_key IS NOT NULL AND oi.bbd_key <> ''))")->fetchColumn();
    $asteptate = $cuCinci + $speciale - $ultimeleCinciSpeciale;
    $verifica('--n=5: cele mai noi 5 comenzi plus cele cu cupon, puncte, BBD sau FANbox (' . $asteptate . ' cazuri)', $r['cod'] === 0
        && $numar($r['iesire'], '/^cazuri (\d+) \|/m') === $asteptate, $r['tot']);

    // ── A4 ──
    $scrie('');
    $scrie('A4. Mutațiile codului comun (într-o copie, --cod-nou): fiecare trebuie să pice și să-și numească câmpul');
    mkdir($codMutat, 0770, true);
    $copiate = $copiazaCodul($radacina, $codMutat, true);
    $verifica('copia codului comun (' . $copiate . ' fișiere, fără .env și storage/)', $copiate > 200 && is_file($codMutat . '/src/Support/CheckoutCalculator.php'));
    $mediuMutatie = ['LICENTA_DOSAR' => $radacina . '/storage/licenta'];
    $mutatii = [
        'totalul (+0,01)' => ['src/Support/CheckoutCalculator.php', '$total = max(0, $subtotal - $discount - $pointsDiscount + $shipping + $vatAdditional);',
            '$total = max(0, $subtotal - $discount - $pointsDiscount + $shipping + $vatAdditional) + 0.01;', '/^diferențe pe câmp: .*\btotal \d+/m'],
        'reducerea procentuală (/99)' => ['src/Support/CheckoutCalculator.php', 'return min($baseAmount, ($baseAmount * $value) / 100);',
            'return min($baseAmount, ($baseAmount * $value) / 99);', '/^diferențe pe câmp: .*\bdiscount \d+/m', '/^diferențe pe tip de caz: .*cupon_procent/m'],
        'prețul BBD (+1)' => ['src/Support/CheckoutCalculator.php', '$bbdCandidate = max(0.0, (float) $bbdReducedPriceRaw);',
            '$bbdCandidate = max(0.0, (float) $bbdReducedPriceRaw) + 1.0;', '/^diferențe pe câmp: .*\blinie\.price \d+/m', '/^diferențe pe tip de caz: .*bbd/m'],
        'valoarea punctelor (×2)' => ['src/Support/LoyaltyService.php', "\$summary['discount'] = round(\$applied * (float) \$cfg['redeem_value'], 2);",
            "\$summary['discount'] = round(\$applied * (float) \$cfg['redeem_value'] * 2, 2);", '/^diferențe pe câmp: .*\bpoints_discount \d+/m',
            '/^diferențe pe tip de caz: .*puncte/m'],
        'transportul din admin (+1)' => ['src/Support/CheckoutCalculator.php', "        if (\$currentShipping > 0.0) {\n            return round(\$currentShipping, 2);",
            "        if (\$currentShipping > 0.0) {\n            return round(\$currentShipping + 1, 2);", '/^diferențe pe câmp: .*\btransport_admin \d+/m',
            '/^transportul din admin: identice (?!' . '%CAZURI%' . ' )\d+/m'],
        'punctele promise (+1)' => ['src/Support/LoyaltyService.php', "return max(0, (int) floor(\$base * (float) \$cfg['earn_rate']));",
            "return max(0, (int) floor(\$base * (float) \$cfg['earn_rate']) + 1);", '/^diferențe pe câmp: .*\bpuncte_estimate \d+/m'],
        'o cheie a payload-ului ERP' => ['src/Support/ErpSync.php', "'livrareTara' => 'RO',", "'livrareTara' => 'RO-',", '/^payload ERP: identice 0 \| chei diferite: livrareTara \d+$/m'],
        'o excepție în sumar' => ['src/Support/CheckoutCalculator.php', "        \$cartMap = Cart::items();\n", "        \$cartMap = Cart::items();\n        if (\$cartMap !== []) {\n"
            . "            throw new \\LogicException('mutatie');\n        }\n", '/^erori: vechi — \| nou LogicException în src\/Support\/CheckoutCalculator\.php:\d+ ×\d+$/m',
            '/\| erori vechi 0 \| erori nou [1-9]/'],
    ];
    foreach ($mutatii as $nume => $m) {
        [$fisier, $din, $cu] = $m;
        $tipare = array_slice($m, 3);
        $original = (string) file_get_contents($codMutat . '/' . $fisier);
        if (substr_count($original, $din) !== 1) {
            $verifica('mutația „' . $nume . '” se aplică (textul ei apare o dată în ' . $fisier . ')', false);
            continue;
        }
        file_put_contents($codMutat . '/' . $fisier, str_replace($din, $cu, $original));
        $r = $compara(['--local', '--cod-vechi=' . $codVechi, '--cod-nou=' . $codMutat], $mediuMutatie);
        file_put_contents($codMutat . '/' . $fisier, $original);
        $potrivite = true;
        foreach ($tipare as $tipar) {
            $potrivite = $potrivite && preg_match(str_replace('%CAZURI%', (string) $cazuri, $tipar), $r['iesire']) === 1;
        }
        $verifica('mutația „' . $nume . '”: cod 1, „REZULTAT: PICĂ”, câmpul ei numit; rândurile tot în formele știute', $r['cod'] === 1
            && $ultimul($r['iesire']) === 'REZULTAT: PICĂ' && $potrivite && $randuriStraine($r['iesire']) === [], 'cod ' . $r['cod'] . ': ' . $r['tot']);
    }
    $r = $compara(['--local', '--cod-vechi=' . $codVechi, '--cod-nou=' . $codMutat], $mediuMutatie);
    $verifica('copia fără mutații (după ultima, pusă la loc): „TRECE”', $r['cod'] === 0 && $ultimul($r['iesire']) === 'REZULTAT: TRECE', $r['tot']);

    // ── A5 ──
    $scrie('');
    $scrie('A5. Refuzurile (cod 2, nimic schimbat) și o rulare oprită');
    $sumaInainte = $sumaCupoane($db);
    $refuzuri = [
        'fără --local, în afara site-ului de verificare' => [[], [], 'dosarul site-ului de verificare'],
        'o bază care nu e cms_*' => [['--local', '--cod-vechi=' . $codVechi], ['DB_DATABASE' => 'alta_baza'], 'doar pe o bază cms_*'],
        'SITE_COPIE_VERIFICARE=1 cu --local' => [['--local', '--cod-vechi=' . $codVechi], ['SITE_COPIE_VERIFICARE' => '1'], 'SITE_COPIE_VERIFICARE=1'],
        'fără CMS_DEV_DIR' => [['--local', '--cod-vechi=' . $codVechi], ['CMS_DEV_DIR' => null], 'CMS_DEV_DIR lipsește'],
        'fără --cod-vechi' => [['--local'], [], 'cere --cod-vechi'],
        'codul vechi nu e un cod de site' => [['--local', '--cod-vechi=' . $dosarTest], [], 'nu e dosarul codului unui site'],
        'alt profil' => [['--local', '--cod-vechi=' . $codVechi, '--profil=biovitality'], [], 'WP-11b'],
        'un argument necunoscut' => [['--local', '--cod-vechi=' . $codVechi, '--orice'], [], 'argument necunoscut'],
        '--n greșit' => [['--local', '--cod-vechi=' . $codVechi, '--n=0'], [], '--n trebuie'],
        '--php-vechi greșit' => [['--local', '--cod-vechi=' . $codVechi, '--php-vechi=/nu/exista/php'], [], '--php-vechi'],
    ];
    foreach ($refuzuri as $nume => [$argumente, $mediu, $text]) {
        $r = $compara($argumente, $mediu);
        $verifica('refuz: ' . $nume . ' → cod 2, „REFUZAT: … ' . $text . ' …”', $r['cod'] === 2 && str_contains($r['tot'], 'REFUZAT:') && str_contains($r['tot'], $text),
            'cod ' . $r['cod'] . ': ' . $r['tot']);
    }
    // Un .env în codul vechi (local, baza vine doar din mediu).
    file_put_contents($codMutat . '/.env', "DB_DATABASE=cms_calc_alta\n");
    $r = $compara(['--local', '--cod-vechi=' . $codMutat]);
    unlink($codMutat . '/.env');
    $verifica('refuz: un .env în codul vechi → cod 2', $r['cod'] === 2 && str_contains($r['tot'], 'un cod are .env'), $r['tot']);
    // Baza în urma migrărilor codului comun.
    $amprenta = (string) $db->query("SELECT valoare FROM _platforma_stare WHERE cheie = 'amprenta'")->fetchColumn();
    $db->exec("UPDATE _platforma_stare SET valoare = 'cod-vechi' WHERE cheie = 'amprenta'");
    $db->exec("UPDATE _platforma_migrari SET rezultat = 'in_curs' WHERE id = 'magazin/0032_avertismente_produse'");
    $r = $compara(['--local', '--cod-vechi=' . $codVechi]);
    $db->prepare("UPDATE _platforma_stare SET valoare = ? WHERE cheie = 'amprenta'")->execute([$amprenta]);
    $db->exec("UPDATE _platforma_migrari SET rezultat = 'aplicata' WHERE id = 'magazin/0032_avertismente_produse'");
    $verifica('refuz: baza în urma migrărilor codului comun (local nu le aplică singur) → cod 2', $r['cod'] === 2 && str_contains($r['tot'], '„in_urma”'), $r['tot']);
    // O licență cu cupoanele oprite: comparația ar număra ca diferență ce oprește licența.
    $dosarLicenta = $dosarTest . '/licenta-fara-cupoane';
    mkdir($dosarLicenta, 0770, true);
    $l = $proces([$radacina . '/tools/dev/php.sh', 'tools/dev/licenta.php', 'bioscem', 'cupoane=oprit'], array_merge(getenv(), ['CMS_DEV_LICENTA_DOSAR' => $dosarLicenta]),
        $radacina);
    $r = $compara(['--local', '--cod-vechi=' . $codVechi], ['LICENTA_DOSAR' => $dosarLicenta]);
    $verifica('refuz: pe codul comun licența are cupoanele oprite → cod 2, numește pluginul', $l['cod'] === 0 && $r['cod'] === 2
        && str_contains($r['tot'], 'nu sunt pornite toate pluginurile site-ului: cupoane'), $l['tot'] . ' | ' . $r['tot']);
    $verifica('după refuzuri: cupoanele neatinse, dosarul temporar gol', $sumaCupoane($db) === $sumaInainte && (glob($dosarTmpLocal . '/*') ?: []) === []);

    // Lucrătorul pornit direct: garda lui, înaintea oricărui calcul.
    $lucrator = static function (string $cod, array $cerere) use ($proces, $ini, $radacina, $bazaT): array {
        return $proces(array_merge([PHP_BINARY], $ini, [$radacina . '/tools/verificare/lucrator-calcule.php', '--cod=' . $cod]),
            array_merge(getenv(), ['DB_DATABASE' => $bazaT]), $cod, (string) json_encode($cerere));
    };
    $r = $lucrator($codVechi, ['faza' => 'calcule', 'mod' => 'local', 'baza' => 'cms_alta_baza', 'cazuri' => [['i' => 1, 'id' => 1, 'linii' => [[4, 1, '']]]]]);
    $verifica('lucrătorul, cu altă bază decât cea legată de cod → cod 2, „altă bază”, niciun caz calculat', $r['cod'] === 2 && str_contains($r['iesire'], 'altă bază')
        && !str_contains($r['iesire'], '"rezumat"'), $r['tot']);
    $r = $lucrator($codVechi, ['faza' => 'calcule', 'mod' => 'verificare', 'baza' => $bazaT, 'sursa' => 'bioscem', 'incarcat_la' => '2026-01-01 00:00:00',
        'cazuri' => [['i' => 1, 'id' => 1, 'linii' => [[4, 1, '']]]]]);
    $verifica('lucrătorul în modul „verificare” pe o bază fără _verificare → cod 2, niciun caz calculat', $r['cod'] === 2 && str_contains($r['iesire'], 'nu e copia de verificare')
        && !str_contains($r['iesire'], '"rezumat"'), $r['tot']);
    $r = $proces(array_merge([PHP_BINARY], $ini, [$radacina . '/tools/verificare/lucrator-calcule.php', '--cod=' . $dosarTest]), getenv(), $radacina, '{}');
    $verifica('lucrătorul cu --cod care nu e un cod de site → cod 1', $r['cod'] === 1 && str_contains($r['tot'], 'nu e rădăcina unui cod'), $r['tot']);

    // O rulare oprită după ce a făcut cupoanele valabile (procesul omorât): următoarea le pune la loc întâi.
    $r = $compara(['--local', '--cod-vechi=' . $codVechi], ['CALCULE_TEST_OPRESTE_DUPA_CUPOANE' => '1']);
    $schimbate = $sumaCupoane($db) !== $sumaInainte;
    $fisierRamas = glob($dosarTmpLocal . '/calcule-cupoane-*.json') ?: [];
    $verifica('o rulare oprită după cupoane: cod 3, cupoanele rămase schimbate, fișierul lor (0600) în dosarul temporar', $r['cod'] === 3 && $schimbate
        && count($fisierRamas) === 1 && (fileperms($fisierRamas[0]) & 0777) === 0600, $r['tot']);
    $r = $compara(['--local', '--cod-vechi=' . $codVechi]);
    $verifica('rularea următoare: „cupoanele unei rulări oprite … puse la loc înainte de orice”, „TRECE”, cupoanele ca la început, fișierul șters', $r['cod'] === 0
        && preg_match('/^  cupoanele unei rulări oprite \(\d+\): puse la loc înainte de orice$/m', $r['iesire']) === 1 && $sumaCupoane($db) === $sumaInainte
        && (glob($dosarTmpLocal . '/*') ?: []) === [], $r['tot']);

    // O eroare fatală PHP (memoria) cu cupoanele schimbate: lib/intrare.php oprește rularea, iar ea le pune la loc pe loc.
    $r = $compara(['--local', '--cod-vechi=' . $codVechi], ['CALCULE_TEST_OPRESTE_DUPA_CUPOANE' => 'memorie']);
    $verifica('o eroare fatală (memoria) după cupoane: cod 1, „OPRIT: memoria PHP s-a terminat … cupoanele au fost puse la loc”, fără mesajul PHP-ului, '
        . 'cupoanele ca la început, niciun fișier rămas', $r['cod'] === 1 && str_contains($r['tot'], 'OPRIT: memoria PHP s-a terminat (memory_limit)')
        && str_contains($r['tot'], 'cupoanele au fost puse la loc') && !str_contains($r['tot'], 'Allowed memory') && $sumaCupoane($db) === $sumaInainte
        && (glob($dosarTmpLocal . '/*') ?: []) === [], $r['tot']);

    // =====================================================================
    // B. Pe un site de verificare simulat
    // =====================================================================
    $scrie('');
    $scrie('B. Pe un site de verificare simulat (mediul gol, ca pe Hostinger)');
    mkdir($copie, 0770, true);
    mkdir($codVechiV, 0700, true);
    $nCopie = $copiazaCodul($radacina, $copie, true);
    $nVechi = $copiazaCodul($codVechi, $codVechiV, false);
    $copiazaBaza($root, $bazaT, $bazaCopie);
    $root->exec("CREATE USER '" . $utilizator . "'@'localhost' IDENTIFIED BY " . $root->quote($parolaDb));
    $root->exec('GRANT ALL ON `' . $bazaCopie . "`.* TO '" . $utilizator . "'@'localhost'");
    $dbCopie = $conectare($bazaCopie);
    $dbCopie->exec('CREATE TABLE `_verificare` (`id` TINYINT UNSIGNED NOT NULL, `sursa` VARCHAR(40) NOT NULL, `anonimizat` TINYINT(1) NOT NULL DEFAULT 0,
        `pas` VARCHAR(40) NOT NULL, `grupuri_gata` TEXT NULL, `randuri` MEDIUMTEXT NULL, `verificari` TEXT NULL, `versiune_reguli` VARCHAR(40) NOT NULL,
        `cod_sursa_commit` VARCHAR(40) NULL, `admin_proprietar_id` INT UNSIGNED NULL, `inceput_la` DATETIME NOT NULL, `incarcat_la` DATETIME NULL,
        PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $dbCopie->prepare("INSERT INTO `_verificare` VALUES (1, 'bioscem', 1, 'gata', '[]', '{}', '{}', '2', ?, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
        ->execute([preg_match('/^[0-9a-f]{40}$/', $commitVechi) === 1 ? $commitVechi : null]);
    // Cheia licenței de verificare (ca configureaza.php) și .env-ul copiei.
    $pereche = sodium_crypto_sign_keypair();
    @mkdir($copie . '/storage/licenta', 0770, true);
    file_put_contents($copie . '/storage/licenta/verificare-cheie-privata.txt', base64_encode(sodium_crypto_sign_secretkey($pereche)) . "\n");
    chmod($copie . '/storage/licenta/verificare-cheie-privata.txt', 0600);
    $envCopie = ['APP_NAME' => 'VERIFICARE', 'APP_ENV' => 'verificare', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://' . $gazda, 'APP_TIMEZONE' => 'Europe/Bucharest',
        'DB_HOST' => 'localhost', 'DB_PORT' => '3306', 'DB_DATABASE' => $bazaCopie, 'DB_USERNAME' => $utilizator, 'DB_PASSWORD' => $parolaDb,
        'SITE_COPIE_VERIFICARE' => '1', 'VERIFICARE_SURSA' => 'bioscem', 'VERIFICARE_ZILE_PASTRARE' => '7', 'VERIFICARE_EMAIL' => $emailProprietar,
        'VERIFICARE_POARTA_UTILIZATOR' => 'verificare', 'VERIFICARE_POARTA_HASH' => password_hash('Poarta-' . bin2hex(random_bytes(6)), PASSWORD_DEFAULT),
        'VERIFICARE_UPLOADS_DIN' => 'https://bioscem.ro', 'SITE_SLUG' => 'bioscem', 'SITE_DATE_DOSAR' => $copie . '/site-uri/bioscem', 'LICENTA_SITE' => 'verificare',
        'LICENTA_CHEIE_PUBLICA' => base64_encode(sodium_crypto_sign_publickey($pereche)), 'BT_IPAY_MODE' => 'test'];
    $scrieEnv = static function (string $fisier, array $chei): void {
        $text = '';
        foreach ($chei as $k => $v) {
            $text .= $k . '=' . $v . "\n";
        }
        file_put_contents($fisier, $text);
        chmod($fisier, 0600);
    };
    $scrieEnv($copie . '/.env', $envCopie);
    $envVechi = ['APP_NAME' => 'VERIFICARE', 'APP_ENV' => 'verificare', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://' . $gazda, 'APP_TIMEZONE' => 'Europe/Bucharest',
        'DB_HOST' => 'localhost', 'DB_PORT' => '3306', 'DB_DATABASE' => $bazaCopie, 'DB_USERNAME' => $utilizator, 'DB_PASSWORD' => $parolaDb, 'ADMIN_DEFAULT_PASSWORD' => '',
        'BT_IPAY_MODE' => 'test'];
    $scrieEnv($codVechiV . '/.env', $envVechi);
    file_put_contents($copie . '/storage/verificare/incarcat-la', date('c') . "\n");
    $mediuServer = ['PATH' => (string) getenv('PATH'), 'HOME' => $acasa, 'LANG' => 'C.UTF-8'];
    $peServer = static function (string $script, array $argumente, array $optiuni = []) use ($proces, $ini, $copie, $mediuServer): array {
        return $proces(array_merge([$optiuni['php'] ?? PHP_BINARY], $ini, [$copie . '/' . $script], $argumente), ($optiuni['mediu'] ?? []) + $mediuServer,
            $optiuni['cwd'] ?? $copie);
    };
    $l = $peServer('tools/verificare/licenta.php', ['bioscem']);
    $verifica('site-ul de verificare simulat: copia codului (' . $nCopie . ' fișiere), codul vechi în cod-vechi/bioscem/ (' . $nVechi . ' fișiere) cu .env-ul '
        . 'lui, baza ' . $bazaCopie . ' cu _verificare, licența bioscem pusă de licenta.php', $nCopie > 200 && $nVechi > 100 && $l['cod'] === 0, $l['tot']);
    $secrete = [$parolaDb, $bazaCopie, $utilizator, $emailProprietar, $acasa, $dosarTest];
    $faraSecrete = static function (string $text) use ($secrete): string {
        $gasite = [];
        foreach ($secrete as $s) {
            if ($s !== '' && str_contains($text, $s)) {
                $gasite[] = substr($s, 0, 8) . '…';
            }
        }
        return implode(', ', $gasite);
    };

    // ── B1 ──
    $scrie('');
    $scrie('B1. Comparația pe copie: baza în urma codului comun (o migrare neaplicată), apoi la zi');
    $dbCopie->exec("UPDATE _platforma_stare SET valoare = 'cod-de-dinainte' WHERE cheie = 'amprenta'");
    $dbCopie->exec("DELETE FROM _platforma_migrari WHERE id = 'magazin/0032_avertismente_produse'");
    $dbCopie->exec('DROP TABLE order_product_warnings');
    $dbCopie->exec('DROP TABLE product_warnings');
    $sumaCopie = $sumaCupoane($dbCopie);
    $r = $peServer('tools/verificare/compara-calcule.php', []);
    foreach (explode("\n", trim($r['iesire'])) as $rand) {
        $scrie('        | ' . $rand);
    }
    $salvate = glob($copie . '/storage/verificare/sonde/calcule-bioscem-*.txt') ?: [];
    $verifica('compara-calcule.php pe copie: migrările aplicate întâi (baza era „in_urma”), „TRECE”, „diferite 0”, codul vechi cu commit-ul sursei',
        $r['cod'] === 0 && $ultimul(preg_replace('/\nTextul e salvat.*$/s', '', $r['iesire'])) === 'REZULTAT: TRECE'
        && str_contains($r['iesire'], 'migrările: baza era „in_urma”; aplicate acum') && str_contains($r['iesire'], '| diferite 0 | erori vechi 0 | erori nou 0')
        && (preg_match('/^[0-9a-f]{40}$/', $commitVechi) !== 1 || str_contains($r['iesire'], 'commit ' . substr($commitVechi, 0, 7))), 'cod ' . $r['cod'] . ': ' . $r['tot']);
    $verifica('pe copie: aceleași cazuri ca local, tabelele migrării făcute din nou, garda la zi (_platforma_stare)', $numar($r['iesire'], '/^cazuri (\d+) \|/m') === $cazuri
        && (int) $dbCopie->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_warnings'")->fetchColumn() === 1);
    $verifica('textul salvat în storage/verificare/sonde/calcule-bioscem-<data>.txt (0600), cu „REZULTAT: TRECE”', count($salvate) === 1
        && (fileperms($salvate[0]) & 0777) === 0600 && str_contains((string) file_get_contents($salvate[0]), "REZULTAT: TRECE\n"));
    $verifica('ieșirea pe copie: doar forme știute; nicio parolă, nume de bază sau de utilizator, adresă, cale a contului; stderr gol',
        $randuriStraine($r['iesire']) === [] && $faraSecrete($r['tot']) === '' && trim($r['erori']) === '' && !str_contains($r['tot'], '@'),
        implode(' | ', $randuriStraine($r['iesire'])) . ' ' . $faraSecrete($r['tot']) . ' ' . $r['erori']);
    $verifica('pe copie: cupoanele puse la loc, dosarul temporar al copiei gol, niciun fișier de sesiune în cod-vechi/ și în copie', $sumaCupoane($dbCopie) === $sumaCopie
        && (glob($copie . '/storage/verificare/tmp/*') ?: []) === [] && $sesiuni($codVechiV) === 0 && $sesiuni($copie) === 0);
    $r = $peServer('tools/verificare/compara-calcule.php', ['--prin-fisiere']);
    $verifica('a doua rulare (--prin-fisiere): „baza e la zi cu codul comun”, „TRECE”', $r['cod'] === 0 && str_contains($r['iesire'], '  migrările: baza e la zi cu codul comun')
        && str_contains($r['iesire'], "REZULTAT: TRECE\n") && (glob($copie . '/storage/verificare/tmp/*') ?: []) === [], $r['tot']);

    // ── B2 ──
    $scrie('');
    $scrie('B2. Refuzurile pe copie (cod 2, nimic schimbat)');
    $refuzaPeServer = static function (string $nume, string $text, callable $strica, callable $repara, array $argumente = [], array $optiuni = [])
        use ($peServer, $verifica, $sumaCupoane, $dbCopie, $faraSecrete): void {
        $suma = $sumaCupoane($dbCopie);
        $strica();
        $r = $peServer('tools/verificare/compara-calcule.php', $argumente, $optiuni);
        $repara();
        $verifica('refuz pe copie: ' . $nume . ' → cod 2, „REFUZAT: … ' . $text . ' …”, cupoanele neatinse', $r['cod'] === 2 && str_contains($r['tot'], 'REFUZAT:')
            && str_contains($r['tot'], $text) && $sumaCupoane($dbCopie) === $suma && $faraSecrete($r['tot']) === '', 'cod ' . $r['cod'] . ': ' . $r['tot']);
    };
    $faNimic = static function (): void {
    };
    $refuzaPeServer('_verificare lipsă', 'lipsește _verificare', static fn () => $dbCopie->exec('RENAME TABLE _verificare TO _verificare_t'),
        static fn () => $dbCopie->exec('RENAME TABLE _verificare_t TO _verificare'));
    $refuzaPeServer('copia neîncheiată (anonimizat = 0)', 'anonimizat = 0', static fn () => $dbCopie->exec('UPDATE _verificare SET anonimizat = 0'),
        static fn () => $dbCopie->exec('UPDATE _verificare SET anonimizat = 1'));
    $refuzaPeServer('copia demonstrativă (sursa demo)', 'altă sursă', static fn () => $dbCopie->exec("UPDATE _verificare SET sursa = 'demo'"),
        static fn () => $dbCopie->exec("UPDATE _verificare SET sursa = 'bioscem'"));
    $refuzaPeServer('_verificare.incarcat_la de acum 8 zile', 'zilele de păstrare', static fn () => $dbCopie->exec('UPDATE _verificare SET incarcat_la = UTC_TIMESTAMP() - INTERVAL 8 DAY'),
        static fn () => $dbCopie->exec('UPDATE _verificare SET incarcat_la = UTC_TIMESTAMP()'));
    $refuzaPeServer('incarcat-la expirat', 'a expirat', static fn () => file_put_contents($copie . '/storage/verificare/incarcat-la', date('c', time() - 9 * 86400) . "\n"),
        static fn () => file_put_contents($copie . '/storage/verificare/incarcat-la', date('c') . "\n"));
    $refuzaPeServer('incarcat-la lipsă', 'lipsește', static fn () => rename($copie . '/storage/verificare/incarcat-la', $copie . '/storage/verificare/incarcat-la.t'),
        static fn () => rename($copie . '/storage/verificare/incarcat-la.t', $copie . '/storage/verificare/incarcat-la'));
    $refuzaPeServer('.env fără SITE_COPIE_VERIFICARE=1', 'SITE_COPIE_VERIFICARE=1', static fn () => $scrieEnv($copie . '/.env', ['SITE_COPIE_VERIFICARE' => '0'] + $envCopie),
        static fn () => $scrieEnv($copie . '/.env', $envCopie));
    $refuzaPeServer('alt pachet în .env (SITE_SLUG)', 'pachetul altui site', static fn () => $scrieEnv($copie . '/.env', ['SITE_SLUG' => 'vertij'] + $envCopie),
        static fn () => $scrieEnv($copie . '/.env', $envCopie));
    $refuzaPeServer('alt HOME', 'HOME', $faNimic, $faNimic, [], ['mediu' => ['HOME' => $dosarTest]]);
    $refuzaPeServer('baza în .env-ul altui site din cont', 'altui site din cont', static function () use ($acasa, $bazaCopie): void {
        @mkdir($acasa . '/domains/bioscem.ro/public_html', 0770, true);
        file_put_contents($acasa . '/domains/bioscem.ro/public_html/.env', "APP_URL=https://bioscem.ro\nDB_HOST=localhost\nDB_DATABASE=" . $bazaCopie . "\n");
    }, static fn () => unlink($acasa . '/domains/bioscem.ro/public_html/.env'));
    $refuzaPeServer('codul vechi lipsă', 'codul vechi lipsește', static fn () => rename($codVechiV, $codVechiV . '.t'), static fn () => rename($codVechiV . '.t', $codVechiV));
    $refuzaPeServer('codul vechi e o legătură', 'legătură', static function () use ($codVechiV): void {
        rename($codVechiV, $codVechiV . '.t');
        symlink($codVechiV . '.t', $codVechiV);
    }, static function () use ($codVechiV): void {
        unlink($codVechiV);
        rename($codVechiV . '.t', $codVechiV);
    });
    $refuzaPeServer('.env-ul codului vechi spre altă bază', 'nu e spre baza copiei', static fn () => $scrieEnv($codVechiV . '/.env', ['DB_DATABASE' => $bazaAlta] + $envVechi),
        static fn () => $scrieEnv($codVechiV . '/.env', $envVechi));
    $refuzaPeServer('--cod-vechi pe server', 'doar pentru testul local', $faNimic, $faNimic, ['--cod-vechi=' . $codVechi]);
    $refuzaPeServer('--local pe server (copia are .env)', 'un cod are .env', $faNimic, $faNimic, ['--local', '--cod-vechi=' . $codVechiV],
        ['mediu' => ['CMS_DEV_DIR' => $dosarTest, 'DB_DATABASE' => 'cms_calc_copie']]);
    $refuzaPeServer('licența copiei cu cupoanele oprite', 'nu sunt pornite toate pluginurile site-ului: cupoane',
        static fn () => $peServer('tools/verificare/licenta.php', ['bioscem', 'cupoane=oprit']), static fn () => $peServer('tools/verificare/licenta.php', ['bioscem']));
    // Doar „in_urma” se migrează singur: o bază fără registru nu e o copie lăsată de încărcare (migreaza.php ar adopta-o).
    $refuzaPeServer('baza fără registrul migrărilor (fara_registru)', '„fara_registru”', static fn () => $dbCopie->exec('RENAME TABLE _platforma_stare TO _platforma_stare_t'),
        static fn () => $dbCopie->exec('RENAME TABLE _platforma_stare_t TO _platforma_stare'));
    $r = $peServer('tools/verificare/compara-calcule.php', []);
    $verifica('după refuzuri, din nou „TRECE”', $r['cod'] === 0 && str_contains($r['iesire'], "REZULTAT: TRECE\n"), $r['tot']);

    // ── B3 ──
    $scrie('');
    $scrie('B3. Un PHP sub minimul codului');
    $phpVechi = array_values(array_filter(['php7.0', 'php7.4', 'php8.0', 'php8.1', 'php8.2'], static fn (string $b): bool
        => version_compare(substr($b, 3), $phpMinim, '<') && trim((string) shell_exec('command -v ' . $b . ' 2>/dev/null')) !== ''));
    if ($phpVechi === []) {
        $scrie('  INFO  niciun PHP sub minimul ' . $phpMinim . ' instalat: mesajul pentru un PHP prea vechi nu s-a verificat acum');
    }
    foreach ($phpVechi as $binar) {
        $r = $peServer('tools/verificare/compara-calcule.php', [], ['php' => trim((string) shell_exec('command -v ' . $binar))]);
        $verifica($binar . ': compara-calcule.php spune că PHP-ul e prea vechi (cod 1, fără eroare de sintaxă)', $r['cod'] === 1
            && str_contains($r['tot'], 'mai vechi decât minimul') && !str_contains($r['tot'], 'Parse error'), $r['tot']);
    }
    foreach (array_filter($phpVechi, static fn (string $b): bool => version_compare(substr($b, 3), '8.0', '>=')) as $binar) {
        $r = $proces([trim((string) shell_exec('command -v ' . $binar)), '-l', $radacina . '/tools/verificare/lucrator-calcule.php'], getenv(), $radacina);
        $verifica($binar . ': lucrator-calcule.php are sintaxa PHP 8.0 (codul vechi poate rula pe PHP-ul lui live, --php-vechi)', $r['cod'] === 0, $r['tot']);
    }

    $sumaDevDupa = implode(',', $root->query('CHECKSUM TABLE `' . $bazaDev . '`.coupons, `' . $bazaDev . '`.orders')->fetchAll(PDO::FETCH_COLUMN, 1));
    $verifica('baza de dezvoltare neatinsă (CHECKSUM TABLE coupons, orders)', $sumaDevDupa === $sumaDevInainte);
} catch (Throwable $e) {
    $verifica('testul a rulat până la capăt', false, get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

$scrie('');
$scrie('Rezultat: ' . $trecute . ' trecute, ' . $esuate . ' eșuate');
exit($esuate === 0 ? 0 : 1);
