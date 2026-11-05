<?php

declare(strict_types=1);

/**
 * Testul deploy-ului din Etapa 2 (docs/DEPLOY.md): bin/platforma, deploy/release.sh, adopta.sh, actualizeaza.sh și
 * revino.sh, pe o găzduire simulată ca Hostinger, în $CMS_DEV_DIR/deploy/acasa:
 *
 *   platforma/repo/          un repo git cu codul de acum (fișierele din git și cele necomise) și două pachete de test
 *                            (site-uri/proba-a, din tools/dev/site-demo, cu un fișier public; site-uri/proba-b, din
 *                            tools/dev/site-demo-tema, cu tema), în trei commit-uri: v1, v2 (o schimbare fără efect),
 *                            v3 (prima pagină aruncă o excepție)
 *   domains/a.exemplu.test/  site-ul A: în public_html un „cod vechi” inventat, checkout git (.env, storage/, poze în
 *                            public/uploads/, un sitemap scris de admin, assets/.htaccess, un error_log), cu baza
 *                            cms_dep_a făcută ca a codului Biovitality (tools/dev/migrari/vechi/bv.sql) și câteva rânduri
 *   domains/b.exemplu.test/  site-ul B, la fel (cms_dep_b), servit de un PHP cu open_basedir pe dosarul domeniului
 *
 * Serverele: `php -S` pe CMS_DEV_PORT+50 și +51, cu un router care face ce fac cele două .htaccess (codul vechi:
 * totul spre public/; stub-ul: fișierele care există, restul la index.php), deci aceleași servere trec de la codul vechi
 * la stub și înapoi, ca Apache-ul de pe Hostinger.
 *
 *   R  release.sh: planul nu scrie nimic; versiunea (VERSION, COMMIT, SHA256SUMS, doar de citit, fără tools/ și
 *      site-uri/); a doua rulare doar verifică; --verifica prinde un fișier schimbat și unul în plus; un commit cu o
 *      eroare de sintaxă e refuzat fără să lase nimic; --lista, --sterge (refuzat pentru o versiune folosită).
 *   P  bin/platforma: --arata, fără --site, un script din afara versiunii, o versiune care lipsește.
 *   A  adopta.sh pe A: --plan nu schimbă nimic (codul, baza, dosarele); o migrare care pică (un declanșator pe admins
 *      oprește migrarea de date nucleu/0020, după ce migrările de schemă au mers) → oprire în faza „migrari”, site-ul
 *      în mentenanță (pingul trece), reluarea continuă de la „migrari” și pică la fel; revino.sh --plan nu schimbă
 *      nimic; revino.sh --aplica → codul vechi octet cu octet (toate fișierele, cu .git și pozele) și baza exact cea
 *      de dinainte (structura și toate rândurile fiecărui tabel, declanșatorul); apoi adoptarea întreagă reușită:
 *      pingul pe versiune, /health, pozele, fișierele aduse, cronurile.
 *   B  adopta.sh pe B (open_basedir: versiunea pusă și în site/releases/): testul de fum pică (mentenanța din admin
 *      pornită în bază), oprire în „verificare”; după reparare, reluarea continuă de la „verificare” și deschide site-ul.
 *   U  actualizeaza.sh: --plan nu schimbă nimic; A pe v2 (B rămâne pe v1, assets/.htaccess al site-ului păstrat);
 *      A pe v3 (prima pagină pică) → întors singur la v2, deschis, cod 1; B pe v2 (în site/releases/).
 *   V  revino.sh după o adoptare reușită: refuză cu date noi în bază (o comandă), apoi --pierde-datele-noi → codul
 *      vechi octet cu octet, baza exact cea de dinaintea adoptării; B cu --fara-baza → codul vechi pe baza migrată.
 *
 *   tools/dev/php.sh tools/dev/test-deploy.php
 *
 * Bazele cms_dep_* se fac și se șterg aici; serverele se opresc și dosarul se șterge la final. Raport:
 * $CMS_DEV_DIR/test-deploy-raport.txt; cod 0 doar dacă totul a trecut.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
@mkdir($dosarDev, 0770, true);
$fisierRaport = $dosarDev . '/test-deploy-raport.txt';
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
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . $detalii : ''));
    }
};
$scrie('Test deploy ANDAXI-CMS (Etapa 2: release, adopta, actualizeaza, revino) — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

$portBaza = (int) (getenv('CMS_DEV_PORT') ?: 8701);
$porturi = ['a' => $portBaza + 50, 'b' => $portBaza + 51];
$dosarTest = $dosarDev . '/deploy';
$acasa = $dosarTest . '/acasa';
$repo = $acasa . '/platforma/repo';
$sock = (string) ini_get('pdo_mysql.default_socket');
$php = PHP_BINARY . ' -d pdo_mysql.default_socket=' . $sock;
$gauraNeagra = 'http://127.0.0.1:9';
$siteuri = [];
foreach (['a', 'b'] as $x) {
    $d = $acasa . '/domains/' . $x . '.exemplu.test';
    $siteuri[$x] = ['domeniu' => $x . '.exemplu.test', 'dosar' => $d, 'public' => $d . '/public_html', 'site' => $d . '/site', 'slug' => 'proba-' . $x,
        'baza' => 'cms_dep_' . $x, 'url' => 'http://127.0.0.1:' . $porturi[$x], 'nume' => 'Magazin ' . strtoupper($x)];
}
$pidServere = [];
$root = new PDO('mysql:host=localhost', (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$opreste = static function (int $pid): void {
    if ($pid > 0) {
        exec('pkill -TERM -P ' . $pid . ' 2>/dev/null; kill ' . $pid . ' 2>/dev/null');
        for ($i = 0; $i < 50 && trim((string) shell_exec('kill -0 ' . $pid . ' 2>/dev/null && echo x')) !== ''; $i++) {
            usleep(100000);
        }
    }
};
$curata = static function () use (&$pidServere, $opreste, $root, $dosarTest, $dosarDev): void {
    foreach ($pidServere as $pid) {
        $opreste($pid);
    }
    $pidServere = [];
    foreach (['cms_dep_a', 'cms_dep_b'] as $b) {
        try {
            $root->exec('DROP DATABASE IF EXISTS ' . $b);
        } catch (Throwable) {
        }
    }
    if (str_starts_with($dosarTest, $dosarDev . '/') && is_dir($dosarTest)) {
        exec('chmod -R u+w ' . escapeshellarg($dosarTest) . ' 2>/dev/null; rm -rf ' . escapeshellarg($dosarTest));
    }
};
/** Rulează o comandă (listă), cu PHP-ul testului în PHP: [cod, ieșire+erori]. */
$ruleaza = static function (array $comanda, array $env = []) use ($php, $dosarTest): array {
    $mediu = [];
    foreach (getenv() as $k => $v) {
        $mediu[$k] = $v;
    }
    $mediu = array_merge($mediu, ['PHP' => $php], $env);
    $proces = proc_open($comanda, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $tevi, null, $mediu);
    $iesire = (string) stream_get_contents($tevi[1]);
    fclose($tevi[1]);
    $cod = proc_close($proces);
    file_put_contents($dosarTest . '/comenzi.log', '$ ' . implode(' ', array_map('escapeshellarg', $comanda)) . "\n" . $iesire . "\n[cod " . $cod . "]\n\n", FILE_APPEND);
    return [$cod, $iesire];
};
$deploy = static function (string $script, array $argumente) use ($ruleaza, $radacina, $acasa): array {
    return $ruleaza(array_merge(['bash', $radacina . '/deploy/' . $script . '.sh'], $argumente, ['--acasa=' . $acasa]));
};
$git = static function (string $dosar, string ...$a) use ($ruleaza): array {
    return $ruleaza(array_merge(['git', '-C', $dosar, '-c', 'user.email=test@exemplu.ro', '-c', 'user.name=Test'], $a));
};
$cerere = static function (string $url, array $antete = []): array {
    $c = curl_init($url);
    $h = [];
    foreach ($antete as $k => $v) {
        $h[] = $k . ': ' . $v;
    }
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $h]);
    $corp = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $cod, 'corp' => $corp];
};
$ping = static function (string $x) use ($cerere, &$siteuri): array {
    $r = $cerere($siteuri[$x]['url'] . '/_andaxi/ping');
    return (array) (json_decode($r['corp'], true) ?: ['http' => $r['cod']]);
};
/** Amprenta testului (independentă de deploy): fiecare intrare, cu drepturile, mărimea și sha256-ul fiecărui fișier. */
$amprentaCod = static function (string $dosar): array {
    $rez = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dosar, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($dosar));
        $rez[$rel] = ($f->isLink() ? 'l' : ($f->isDir() ? 'd' : 'f')) . ' ' . decoct(fileperms($f->getPathname()) & 07777)
            . ($f->isFile() && !$f->isLink() ? ' ' . $f->getSize() . ' ' . hash_file('sha256', $f->getPathname()) : '');
    }
    ksort($rez);
    return $rez;
};
/** Amprenta bazei: SHOW CREATE TABLE și toate rândurile fiecărui tabel (sha256), plus declanșatoarele. */
$amprentaBaza = static function (string $baza) use ($root): array {
    $rez = [];
    foreach ($root->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . $baza . "' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $creare = (string) $root->query('SHOW CREATE TABLE `' . $baza . '`.`' . $t . '`')->fetch(PDO::FETCH_NUM)[1];
        $randuri = $root->query('SELECT * FROM `' . $baza . '`.`' . $t . '`')->fetchAll(PDO::FETCH_NUM);
        $randuri = array_map('serialize', $randuri);
        sort($randuri);
        $rez['tabel ' . $t] = hash('sha256', $creare) . ' ' . count($randuri) . ' ' . hash('sha256', implode("\n", $randuri));
    }
    foreach ($root->query("SELECT TRIGGER_NAME, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '" . $baza . "'")->fetchAll(PDO::FETCH_NUM) as [$n, $sql]) {
        $rez['declansator ' . $n] = hash('sha256', (string) $sql);
    }
    ksort($rez);
    return $rez;
};
$diferente = static function (array $a, array $b): string {
    $d = [];
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
        if (($a[$k] ?? null) !== ($b[$k] ?? null)) {
            $d[] = $k;
        }
    }
    return implode(', ', array_slice($d, 0, 10)) . (count($d) > 10 ? ' … (' . count($d) . ')' : '');
};
$contine = static fn (string $text, string ...$bucati): bool => array_reduce($bucati, static fn (bool $ok, string $b): bool => $ok && str_contains($text, $b), true);
$scurt = static fn (string $iesire): string => mb_substr(trim((string) preg_replace('/\s+/', ' ', $iesire)), -900);

$pornesteServerul = static function (string $x, bool $openBasedir) use (&$pidServere, &$siteuri, &$porturi, $dosarTest, $sock, $radacina, $cerere, $gauraNeagra): bool {
    $s = $siteuri[$x];
    $ini = ['-d', 'pdo_mysql.default_socket=' . $sock, '-d', 'error_reporting=E_ALL', '-d', 'display_errors=0', '-d', 'log_errors=1',
        '-d', 'error_log=' . $dosarTest . '/server-' . $x . '-erori.log', '-d', 'sendmail_path=' . $radacina . '/tools/dev/mail-capturat.sh',
        '-d', 'memory_limit=512M', '-d', 'allow_url_fopen=0'];
    if ($openBasedir) {
        // Ca o găzduire care nu lasă un site să citească în afara dosarului lui: versiunea comună nu se vede.
        @mkdir($dosarTest . '/gazda/tmp-' . $x, 0770, true);
        $ini = array_merge($ini, ['-d', 'open_basedir=' . $s['dosar'] . ':' . $dosarTest . '/gazda', '-d', 'sys_temp_dir=' . $dosarTest . '/gazda/tmp-' . $x,
            '-d', 'upload_tmp_dir=' . $dosarTest . '/gazda/tmp-' . $x]);
    }
    $env = ['GAZDA_DOCROOT' => $s['public'], 'PATH' => (string) getenv('PATH'), 'LANG' => 'C.UTF-8', 'CMS_DEV_DIR' => $dosarTest,
        'http_proxy' => $gauraNeagra, 'https_proxy' => $gauraNeagra, 'HTTP_PROXY' => $gauraNeagra, 'HTTPS_PROXY' => $gauraNeagra,
        'NO_PROXY' => '127.0.0.1,localhost', 'no_proxy' => '127.0.0.1,localhost'];
    $mediu = '';
    foreach ($env as $k => $v) {
        $mediu .= ' ' . $k . '=' . escapeshellarg($v);
    }
    $comanda = 'cd ' . escapeshellarg($dosarTest . '/gazda') . ' && env -i' . $mediu . ' nohup ' . escapeshellarg(PHP_BINARY) . ' ' . implode(' ', array_map('escapeshellarg', $ini))
        . ' -S 127.0.0.1:' . $porturi[$x] . ' -t ' . escapeshellarg($dosarTest . '/gazda') . ' ' . escapeshellarg($dosarTest . '/gazda/router.php')
        . ' >> ' . escapeshellarg($dosarTest . '/server-' . $x . '.log') . ' 2>&1 & echo $!';
    $pidServere[$x] = (int) trim((string) shell_exec($comanda));
    for ($i = 0; $i < 50; $i++) {
        if ($cerere($s['url'] . '/')['cod'] !== 0) {
            return true;
        }
        usleep(200000);
    }
    return false;
};

try {
    $curata();
    foreach ($porturi as $p) {
        if ($cerere('http://127.0.0.1:' . $p . '/')['cod'] !== 0) {
            throw new RuntimeException('pe portul ' . $p . ' răspunde deja un server; oprește-l întâi');
        }
    }
    mkdir($dosarTest . '/gazda', 0770, true);
    // Routerul serverelor: ce fac .htaccess-urile pe Hostinger. Codul vechi: totul spre public/ (fișierele care există se
    // servesc, .php-urile se rulează, restul la public/index.php). Stub-ul: fișierele din public_html, restul la index.php.
    // Niciun fișier sau dosar cu punct în față (ca deploy/stub/.htaccess), afară de .well-known/.
    file_put_contents($dosarTest . '/gazda/router.php', <<<'PHP'
<?php
$doc = (string) getenv('GAZDA_DOCROOT');
$cale = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/'));
if (preg_match('#(^|/)\.(?!well-known/)#', $cale) === 1 || str_contains($cale, '..')) {
    http_response_code(403);
    echo 'interzis';
    return true;
}
$vechi = is_file($doc . '/public/index.php') && !is_file($doc . '/mentenanta.html');
$baza = $vechi ? $doc . '/public' : $doc;
$f = $baza . $cale;
if ($cale !== '/' && is_file($f)) {
    if (str_ends_with($f, '.php')) {
        $_SERVER['SCRIPT_FILENAME'] = $f;
        $_SERVER['SCRIPT_NAME'] = $cale;
        chdir(dirname($f));
        require $f;
        return true;
    }
    $tipuri = ['css' => 'text/css', 'js' => 'text/javascript', 'png' => 'image/png', 'svg' => 'image/svg+xml', 'xml' => 'application/xml', 'html' => 'text/html'];
    header('Content-Type: ' . ($tipuri[strtolower(pathinfo($f, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    readfile($f);
    return true;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $baza . '/index.php';
$_SERVER['DOCUMENT_ROOT'] = $baza;
chdir($baza);
require $baza . '/index.php';
return true;
PHP);

    // ─────────────────────────────────────────────────────────────────
    // Repo-ul: codul de acum și pachetele de test, în commit-uri
    // ─────────────────────────────────────────────────────────────────
    $scrie('');
    $scrie('R. Repo-ul și versiunile (deploy/release.sh)');
    mkdir($repo, 0770, true);
    $lista = (string) shell_exec('git -C ' . escapeshellarg($radacina) . ' ls-files -z --cached --others --exclude-standard');
    $copiate = 0;
    foreach (array_filter(explode("\0", $lista)) as $f) {
        if (!is_file($radacina . '/' . $f) || is_link($radacina . '/' . $f) || str_starts_with($f, '.env') || str_contains($f, '/privat/')
            || (str_starts_with($f, 'storage/') && !in_array(basename($f), ['.gitkeep', '.gitignore'], true))) {
            continue;
        }
        @mkdir(dirname($repo . '/' . $f), 0770, true);
        copy($radacina . '/' . $f, $repo . '/' . $f);
        chmod($repo . '/' . $f, fileperms($radacina . '/' . $f) & 0777);
        $copiate++;
    }
    /** Un pachet de test: pachet.json refăcut (site-ul și sha256-ul fiecărui fișier). */
    $faPachet = static function (string $din, string $slug, array $inPlus) use ($repo): void {
        $tinta = $repo . '/site-uri/' . $slug;
        exec('mkdir -p ' . escapeshellarg($tinta) . ' && cp -a ' . escapeshellarg($din) . '/. ' . escapeshellarg($tinta) . '/');
        foreach ($inPlus as $rel => $continut) {
            @mkdir(dirname($tinta . '/' . $rel), 0770, true);
            file_put_contents($tinta . '/' . $rel, $continut);
        }
        $manifest = json_decode((string) file_get_contents($tinta . '/pachet.json'), true);
        $manifest['site'] = $slug;
        $manifest['fisiere'] = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tinta, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($tinta) + 1);
            if ($rel !== 'pachet.json') {
                $manifest['fisiere'][$rel] = hash_file('sha256', $f->getPathname());
            }
        }
        ksort($manifest['fisiere']);
        file_put_contents($tinta . '/pachet.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    };
    $migrariJson = json_encode(['_despre' => 'pachet de test (tools/dev/test-deploy.php)', 'link_plata.procesator_linkuri_existente' => 'stripe'], JSON_PRETTY_PRINT) . "\n";
    $faPachet($radacina . '/tools/dev/site-demo', 'proba-a', ['migrari.json' => $migrariJson,
        'public/assets/img/proba-a.svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"><rect width="4" height="4" fill="#0f766e"/></svg>' . "\n"]);
    $faPachet($radacina . '/tools/dev/site-demo-tema', 'proba-b', ['migrari.json' => $migrariJson]);
    $git($repo, 'init', '-q');
    $git($repo, 'add', '-A');
    $git($repo, 'commit', '-qm', 'v1: codul de acum');
    $commit1 = trim($git($repo, 'rev-parse', 'HEAD')[1]);
    $data = trim($git($repo, 'show', '-s', '--format=%cd', '--date=format:%Y.%m.%d', 'HEAD')[1]);
    $v1 = $data . '-' . substr($commit1, 0, 7);
    $verifica('repo-ul de test: ' . $copiate . ' fișiere ale codului de acum și pachetele proba-a, proba-b; v1 = ' . $v1, $copiate > 300
        && preg_match('/^[0-9a-f]{40}$/', $commit1) === 1);

    [$c, $o] = $deploy('release', ['--ref=HEAD']);
    $verifica('release.sh fără --aplica: planul, cod 0, nimic scris', $c === 0 && str_contains($o, 'PLAN: nimic scris') && !is_dir($acasa . '/platforma/releases/' . $v1), $scurt($o));
    [$c, $o] = $deploy('release', ['--ref=HEAD', '--aplica']);
    $r1 = $acasa . '/platforma/releases/' . $v1;
    $faraDrept = trim((string) shell_exec('find ' . escapeshellarg($r1) . ' -perm /222 | head -3'));
    $verifica('release.sh --aplica: versiunea cu VERSION, COMMIT, SHA256SUMS, bin/platforma; fără tools/, site-uri/, .env; doar de citit', $c === 0
        && trim((string) @file_get_contents($r1 . '/VERSION')) === $v1 && trim((string) @file_get_contents($r1 . '/COMMIT')) === $commit1
        && is_file($r1 . '/SHA256SUMS') && is_file($r1 . '/bin/platforma') && !is_dir($r1 . '/tools') && !is_dir($r1 . '/site-uri') && !file_exists($r1 . '/.env')
        && $faraDrept === '' && is_file($acasa . '/platforma/bin/platforma') && $contine($o, 'php -l:', 'fără erori de sintaxă'),
        'cod ' . $c . ', scriibile: ' . $faraDrept . ' | ' . $scurt($o));
    [$c, $o] = $deploy('release', ['--ref=HEAD', '--aplica']);
    $verifica('a doua rulare: „există deja și e neatinsă”, cod 0', $c === 0 && str_contains($o, 'există deja și e neatinsă'), $scurt($o));
    // Un fișier schimbat și unul în plus: --verifica le prinde; puse la loc, trece.
    $fisierProba = $r1 . '/config/app.php';
    $original = (string) file_get_contents($fisierProba);
    chmod($fisierProba, 0644);
    file_put_contents($fisierProba, $original . "\n");
    chmod($r1 . '/config', 0755);
    file_put_contents($r1 . '/config/in-plus.php', '<?php');
    [$c, $o] = $deploy('release', ['--verifica=' . $v1]);
    $verifica('--verifica: un fișier schimbat și unul în plus → cod 1, amândouă numite', $c === 1
        && $contine($o, 'fișier schimbat: config/app.php', 'fișier în plus: config/in-plus.php'), $scurt($o));
    file_put_contents($fisierProba, $original);
    unlink($r1 . '/config/in-plus.php');
    exec('chmod a-w ' . escapeshellarg($fisierProba) . ' ' . escapeshellarg($r1 . '/config'));
    [$c, $o] = $deploy('release', ['--verifica=' . $v1]);
    $verifica('puse la loc: --verifica → cod 0', $c === 0 && str_contains($o, 'e neatinsă'), $scurt($o));
    // Un commit cu o eroare de sintaxă: refuzat, fără niciun dosar rămas.
    file_put_contents($repo . '/src/Stricat.php', "<?php\nfunction ( {\n");
    $git($repo, 'add', '-A');
    $git($repo, 'commit', '-qm', 'stricat');
    [$c, $o] = $deploy('release', ['--ref=HEAD', '--aplica']);
    $verifica('un commit cu o eroare de sintaxă: release.sh → cod 1 (php -l, fișierul numit), nicio versiune sau dosar temporar rămas', $c === 1
        && $contine($o, 'php -l', 'src/Stricat.php') && count(glob($acasa . '/platforma/releases/*', GLOB_ONLYDIR) ?: []) === 1
        && (glob($acasa . '/platforma/releases/.*tmp*') ?: []) === [], $scurt($o));
    $git($repo, 'rm', '-q', 'src/Stricat.php');
    $git($repo, 'commit', '-qm', 'reparat');

    // ── P. bin/platforma ──
    $scrie('');
    $scrie('P. bin/platforma');
    $bin = $acasa . '/platforma/bin/platforma';
    [$c, $o] = $ruleaza([PHP_BINARY, $bin, 'scripts/migreaza.php']);
    $verifica('fără --site= → cod 2, cu folosirea', $c === 2 && $contine($o, 'lipsește --site=', 'Folosire:'), $scurt($o));
    [$c, $o] = $ruleaza([PHP_BINARY, $bin, '--site=' . $siteuri['a']['site'], '--arata']);
    $verifica('un dosar de site care nu există → cod 2', $c === 2 && str_contains($o, 'nu există'), $scurt($o));

    // ─────────────────────────────────────────────────────────────────
    // Site-urile vechi: cod vechi (checkout git), baza ca a codului Biovitality, licența
    // ─────────────────────────────────────────────────────────────────
    $scrie('');
    $scrie('Site-urile „vechi” (checkout git în public_html, baza din bv.sql)');
    preg_match_all('/^CREATE TABLE `([^`]+)`.*?;$/ms', (string) file_get_contents($radacina . '/tools/dev/migrari/vechi/bv.sql'), $creari, PREG_SET_ORDER);
    $cheiePublica = trim((string) shell_exec('CMS_DEV_DIR=' . escapeshellarg($dosarTest) . ' ' . escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg($radacina . '/tools/dev/licenta.php') . ' cheie'));
    $amprente = [];
    foreach ($siteuri as $x => $s) {
        // Codul vechi: un mic site PHP, checkout git, cu .htaccess-ul de azi (totul spre public/).
        $p = $s['public'];
        foreach (['public/assets/css', 'public/uploads/gallery', 'scripts', 'storage/cache', 'storage/logs'] as $d) {
            mkdir($p . '/' . $d, 0775, true);
        }
        file_put_contents($p . '/.htaccess', "RewriteEngine On\nRewriteCond %{REQUEST_URI} !^/public/\nRewriteRule ^(.*)$ public/$1 [L]\n");
        file_put_contents($p . '/index.php', "<?php\nrequire __DIR__ . '/public/index.php';\n");
        file_put_contents($p . '/.gitignore', "/.env\n/storage/logs/\n/public/uploads/\n/public/*.xml\n/public/assets/.htaccess\n*.log\n");
        file_put_contents($p . '/public/.htaccess', "RewriteEngine On\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule ^ index.php [L,QSA]\n");
        file_put_contents($p . '/public/index.php', <<<'PHP'
<?php
$env = [];
foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    if (str_contains($l, '=') && $l[0] !== '#') {
        [$k, $v] = explode('=', $l, 2);
        $env[trim($k)] = trim($v, " \"'");
    }
}
$pdo = new PDO('mysql:host=localhost;dbname=' . $env['DB_DATABASE'], $env['DB_USERNAME'], $env['DB_PASSWORD']);
$n = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
echo '<!doctype html><html><body>COD VECHI ' . htmlspecialchars($env['APP_NAME']) . ' produse: ' . $n . '</body></html>';
PHP);
        file_put_contents($p . '/public/assets/css/vechi.css', "body { color: #333; }\n");
        file_put_contents($p . '/scripts/erp-sync.php', "<?php\necho 'erp vechi';\n");
        file_put_contents($p . '/scripts/bbd-stock-debug.php', "<?php\necho 'unealta veche';\n");
        file_put_contents($p . '/storage/cache/.gitkeep', '');
        $git($p, 'init', '-q');
        $git($p, 'add', '-A');
        $git($p, 'commit', '-qm', 'codul vechi al site-ului ' . $x);
        $siteuri[$x]['commit_vechi'] = trim($git($p, 'rev-parse', 'HEAD')[1]);
        // Ce are un site live în plus față de git: .env, jurnale, poze, un sitemap scris de admin, regulile de cache din
        // assets/, un error_log lăsat de PHP (Hostinger).
        file_put_contents($p . '/.env', implode("\n", ['APP_NAME="' . $s['nume'] . '"', 'APP_ENV=production', 'APP_DEBUG=false', 'APP_URL=' . $s['url'],
            'DB_HOST=localhost', 'DB_PORT=3306', 'DB_DATABASE=' . $s['baza'], 'DB_USERNAME=' . (string) getenv('DB_USERNAME'),
            'DB_PASSWORD=' . (string) getenv('DB_PASSWORD'), 'ADMIN_DEFAULT_EMAIL=admin@exemplu.ro', 'FAN_API_BASE=' . $gauraNeagra, 'BT_IPAY_MODE=test',
            'BT_IPAY_BASE_URL_OVERRIDE=' . $gauraNeagra, '']));
        file_put_contents($p . '/storage/logs/app.log', "un jurnal vechi\n");
        $poza = imagecreatetruecolor(40, 30);
        imagefill($poza, 0, 0, imagecolorallocate($poza, 200, 80, 30));
        imagepng($poza, $p . '/public/uploads/gallery/poza-' . $x . '.png');
        file_put_contents($p . '/public/sitemap-vechi.xml', "<?xml version=\"1.0\"?><urlset/>\n");
        file_put_contents($p . '/public/assets/.htaccess', "# regulile de cache scrise de admin (" . $x . ")\n");
        file_put_contents($p . '/public/error_log', "[PHP Warning] ceva vechi\n");

        // Baza: structura codului Biovitality și câteva rânduri (un admin cu rolul gol: migrarea nucleu/0020 îl aduce la zi).
        $root->exec('CREATE DATABASE ' . $s['baza'] . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $b = new PDO('mysql:host=localhost;dbname=' . $s['baza'], (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $b->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($creari as [$sql]) {
            $b->exec($sql);
        }
        $b->exec('SET FOREIGN_KEY_CHECKS = 1');
        $b->prepare("INSERT INTO settings (`key`, `value`) VALUES ('store_name', ?), ('maintenance_enabled', '0')")->execute([$s['nume']]);
        $b->prepare("INSERT INTO admins (email, password_hash, role) VALUES ('admin@exemplu.ro', ?, '')")->execute([password_hash('Parola-' . $x . '-1234', PASSWORD_DEFAULT)]);
        $b->exec("INSERT INTO products (name, slug, price, stock, is_active) VALUES ('Produs de proba', 'produs-de-proba', 25.50, 10, 1)");
        // Ca serverul Hostinger (non-strict): coloanele NOT NULL fără valoare implicită primesc ''.
        $b->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        $b->exec("INSERT INTO orders (order_number, billing_first_name, billing_last_name, billing_phone, billing_email, billing_address_line1, billing_city, billing_county, total) "
            . "VALUES ('BV-1', 'Ion', 'Pop', '0700000000', 'ion@exemplu.ro', 'Strada 1', 'Iasi', 'Iasi', 25.50)");

        // Ce pune proprietarul înainte (docs/DEPLOY.md): licența site-ului în site/storage/licenta/.
        mkdir($s['site'] . '/storage/licenta', 0770, true);
        [$c, $o] = $ruleaza([PHP_BINARY, $radacina . '/tools/dev/licenta.php', 'toate', '--site=' . $s['slug']], ['CMS_DEV_DIR' => $dosarTest,
            'LICENTA_DOSAR' => $s['site'] . '/storage/licenta', 'LICENTA_SITE' => $s['slug'], 'LICENTA_CHEIE_PUBLICA' => $cheiePublica,
            'DB_DATABASE' => 'cms_dep_nu_exista']);
        $verifica('site ' . $x . ': codul vechi (git, commit ' . substr($siteuri[$x]['commit_vechi'], 0, 7) . '), baza ' . $s['baza'] . ' (' . count($creari)
            . ' tabele din bv.sql), licența de test în site/storage/licenta', $c === 0 && is_file($s['site'] . '/storage/licenta/licenta.json'), $scurt($o));
        $amprente[$x] = ['cod' => $amprentaCod($p), 'baza' => $amprentaBaza($s['baza'])];
    }
    file_put_contents($dosarTest . '/cron-a.txt', implode("\n", [
        '*/10 * * * * /usr/bin/php ' . $siteuri['a']['public'] . '/scripts/erp-sync.php >/dev/null 2>&1',
        '0 3 * * * /usr/bin/php ' . $siteuri['a']['public'] . '/scripts/bbd-stock-debug.php',
        '*/5 * * * * curl -s https://a.exemplu.test/health >/dev/null',
        '0 4 * * * /usr/bin/php /home/altcineva/domains/alt.ro/public_html/scripts/erp-sync.php', '']));
    $verifica('serverul site-ului A pornește (codul vechi)', $pornesteServerul('a', false) && str_contains($cerere($siteuri['a']['url'] . '/')['corp'], 'COD VECHI'));
    $verifica('serverul site-ului B pornește (codul vechi, open_basedir pe dosarul domeniului)', $pornesteServerul('b', true)
        && str_contains($cerere($siteuri['b']['url'] . '/')['corp'], 'COD VECHI'));
    [$c, $o] = $ruleaza([PHP_BINARY, $bin, '--site=' . $siteuri['a']['public'], '--arata']);
    $verifica('bin/platforma pe un dosar fără .env de site → cod 2', $c === 2, $scurt($o));

    $argA = ['--site=proba-a', '--domeniu=a.exemplu.test', '--versiune=' . $v1, '--commit-vechi=' . substr($siteuri['a']['commit_vechi'], 0, 7),
        '--url=' . $siteuri['a']['url'], '--cheie-licenta=' . $cheiePublica, '--cron-vechi=' . $dosarTest . '/cron-a.txt', '--php-cron=' . PHP_BINARY];
    $argB = ['--site=proba-b', '--domeniu=b.exemplu.test', '--versiune=' . $v1, '--commit-vechi=' . $siteuri['b']['commit_vechi'],
        '--url=' . $siteuri['b']['url'], '--cheie-licenta=' . $cheiePublica, '--php-cron=' . PHP_BINARY];

    // ─────────────────────────────────────────────────────────────────
    // A. Adoptarea site-ului A: planul, o migrare care pică, revenirea, adoptarea reușită
    // ─────────────────────────────────────────────────────────────────
    $scrie('');
    $scrie('A. adopta.sh și revino.sh pe site-ul A');
    $inainteSite = $amprentaCod($siteuri['a']['site']);
    [$c, $o] = $deploy('adopta', $argA);
    $verifica('--plan: cod 0, toate verificările (git la commit, sodium web, baza, pachetul, licența), planul migrărilor (cod 10) și cronurile', $c === 0
        && $contine($o, 'toate verificările au trecut', 'sodium da', 'migreaza.php --plan: codul 10', 'PLAN: site-ul n-a fost schimbat', 'ADAUGI'), $scurt($o));
    $verifica('--plan nu schimbă nimic: codul vechi, baza, site/ (doar licența pusă de proprietar), fără public_html.nou',
        $amprentaCod($siteuri['a']['public']) === $amprente['a']['cod'] && $amprentaBaza('cms_dep_a') === $amprente['a']['baza']
        && $amprentaCod($siteuri['a']['site']) === $inainteSite && !is_dir($siteuri['a']['dosar'] . '/public_html.nou')
        && glob($acasa . '/platforma/plan-*') === [], $diferente($amprentaCod($siteuri['a']['public']), $amprente['a']['cod']));
    [$c, $o] = $deploy('adopta', array_merge($argA, ['--aplica', '--commit-vechi=abcdef1']));
    $verifica('alt commit vechi decât cel revizuit → oprit în „verificari”, cod 1, nimic schimbat', $c === 1
        && $contine($o, 'nu la cel revizuit', 'site-ul a rămas pe codul vechi') && $amprentaCod($siteuri['a']['public']) === $amprente['a']['cod'], $scurt($o));

    // Migrarea care pică: un declanșator oprește orice UPDATE pe admins (migrarea de date nucleu/0020).
    $root->exec("CREATE TRIGGER cms_dep_a.proba_opreste BEFORE UPDATE ON cms_dep_a.admins FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'proba'");
    $bazaCuDeclansator = $amprentaBaza('cms_dep_a');
    [$c, $o] = $deploy('adopta', array_merge($argA, ['--aplica']));
    $rasp = $cerere($siteuri['a']['url'] . '/');
    $verifica('o migrare care pică (nucleu/0020): oprit în faza „migrari”, cod 1, cu cele două drumuri (reluare, revino.sh)', $c === 1
        && $contine($o, 'faza „migrari”', 'MENTENANȚĂ', 'deploy/revino.sh --site=proba-a'), $scurt($o));
    $verifica('site-ul A e în mentenanță: / → 503 „Revenim”; pingul trece (/_andaxi/); codul vechi în public_html.vechi',
        $rasp['cod'] === 503 && str_contains($rasp['corp'], 'Revenim') && isset($ping('a')['ok']) && is_dir($siteuri['a']['dosar'] . '/public_html.vechi'),
        $rasp['cod'] . ' ' . json_encode($ping('a')));
    $jeton = trim((string) @file_get_contents($siteuri['a']['site'] . '/MENTENANTA'));
    $cuJeton = $cerere($siteuri['a']['url'] . '/admin/login', ['X-Andaxi-Deploy' => $jeton]);
    $cuGresit = $cerere($siteuri['a']['url'] . '/admin/login', ['X-Andaxi-Deploy' => str_repeat('0', 32)]);
    $verifica('jetonul din MENTENANTA trece de stub (/admin/login 200); un jeton greșit nu (503)', preg_match('/^[0-9a-f]{32}$/', $jeton) === 1
        && $cuJeton['cod'] === 200 && $cuGresit['cod'] === 503, $cuJeton['cod'] . ' ' . $cuGresit['cod']);
    [$c, $o] = $deploy('adopta', array_merge($argA, ['--aplica']));
    $verifica('reluarea: fazele terminate se sar (verificari … mentenanta), pică din nou la „migrari”', $c === 1
        && $contine($o, 'verificari: făcută deja', 'mentenanta: făcută deja', 'faza „migrari”') && !str_contains($o, 'comutat: codul vechi'), $scurt($o));
    [$c, $o] = $deploy('revino', ['--site=proba-a', '--url=' . $siteuri['a']['url']]);
    $verifica('revino.sh --plan: cod 0, spune ce ar face (baza pusă la loc), nimic schimbat (tot 503)', $c === 0
        && $contine($o, 'pusă la loc din', 'PLAN: nimic schimbat') && $cerere($siteuri['a']['url'] . '/')['cod'] === 503, $scurt($o));
    $inceput = microtime(true);
    [$c, $o] = $deploy('revino', ['--site=proba-a', '--url=' . $siteuri['a']['url'], '--aplica']);
    $durata = microtime(true) - $inceput;
    $verifica('revino.sh --aplica: cod 0, în ' . round($durata, 1) . ' s; baza „exact ca la copie”, codul „octet cu octet”', $c === 0
        && $contine($o, 'exact ca la copie', 'octet cu octet', 'site-ul vechi răspunde') && $durata < 120, $scurt($o));
    $verifica('codul vechi al lui A e exact cel de dinainte (fiecare fișier, cu .git, .env, poze, sitemap, error_log)',
        $amprentaCod($siteuri['a']['public']) === $amprente['a']['cod'], $diferente($amprentaCod($siteuri['a']['public']), $amprente['a']['cod']));
    $verifica('baza lui A e exact cea de dinainte (structura și rândurile fiecărui tabel, declanșatorul; fără tabelele migrărilor)',
        $amprentaBaza('cms_dep_a') === $bazaCuDeclansator, $diferente($amprentaBaza('cms_dep_a'), $bazaCuDeclansator));
    $verifica('site-ul A servește din nou codul vechi', str_contains($cerere($siteuri['a']['url'] . '/')['corp'], 'COD VECHI ' . $siteuri['a']['nume']));

    // Adoptarea reușită.
    $root->exec('DROP TRIGGER cms_dep_a.proba_opreste');
    $bazaInainteA = $amprentaBaza('cms_dep_a');
    $inceput = microtime(true);
    [$c, $o] = $deploy('adopta', array_merge($argA, ['--aplica']));
    $durata = microtime(true) - $inceput;
    $p = $ping('a');
    $verifica('adopta.sh --aplica: cod 0, „GATA”, în ' . round($durata, 1) . ' s; fiecare fază, testul de fum trecut', $c === 0 && $contine($o, 'GATA',
        'migrările sunt aplicate și verificate', 'diagnostic.php --pachet: pachetul', 'pingul: 200', 'o pagină de produs', 'pagina de logare din admin',
        'MENTENANTA ștearsă'), $scurt($o));
    $verifica('pingul: versiunea ' . $v1 . ', schema la_zi; / e codul nou (nu „COD VECHI”), deschis oricui', ($p['cod'] ?? null) === $v1
        && ($p['schema'] ?? null) === 'la_zi' && ($r = $cerere($siteuri['a']['url'] . '/'))['cod'] === 200 && !str_contains($r['corp'], 'COD VECHI')
        && !is_file($siteuri['a']['site'] . '/MENTENANTA'), json_encode($p));
    $pozaA = $cerere($siteuri['a']['url'] . '/uploads/gallery/poza-a.png');
    $verifica('pozele mutate în public_html/uploads/ și servite (aceiași octeți); sitemap-ul și assets/.htaccess aduse; error_log nu',
        $pozaA['cod'] === 200 && hash('sha256', $pozaA['corp']) === hash_file('sha256', $siteuri['a']['public'] . '/uploads/gallery/poza-a.png')
        && is_file($siteuri['a']['public'] . '/sitemap-vechi.xml') && str_contains((string) @file_get_contents($siteuri['a']['public'] . '/assets/.htaccess'), '(a)')
        && !file_exists($siteuri['a']['public'] . '/error_log') && !is_dir($siteuri['a']['dosar'] . '/public_html.vechi/public/uploads'), (string) $pozaA['cod']);
    $verifica('fișierul public al pachetului în docroot (/assets/img/proba-a.svg 200); stub-ul în public_html', $cerere($siteuri['a']['url'] . '/assets/img/proba-a.svg')['cod'] === 200
        && is_file($siteuri['a']['public'] . '/mentenanta.html') && str_contains((string) file_get_contents($siteuri['a']['public'] . '/index.php'), 'PLATFORMA_SITE_DIR'));
    $env = (string) @file_get_contents($siteuri['a']['site'] . '/.env');
    $verifica('site/.env: cel vechi plus SITE_SLUG, SITE_DATE_DOSAR, LICENTA_SITE, LICENTA_CHEIE_PUBLICA; doar al proprietarului (0600)',
        $contine($env, 'APP_NAME="' . $siteuri['a']['nume'] . '"', 'SITE_SLUG=proba-a', 'SITE_DATE_DOSAR=' . $siteuri['a']['site'] . '/pachet', 'LICENTA_SITE=proba-a',
            'LICENTA_CHEIE_PUBLICA=' . $cheiePublica) && (fileperms($siteuri['a']['site'] . '/.env') & 0777) === 0600);
    $cron = (string) @file_get_contents($siteuri['a']['site'] . '/deploy/cron-nou.txt');
    $verifica('cronurile: erp-sync.php trece pe ~/platforma/bin/platforma --site=…, cu programul vechi; bbd-stock-debug.php se șterge (nu există); curl rămâne; '
        . 'rândul altui site e ignorat', str_contains($cron, '*/10 * * * * ' . PHP_BINARY . ' ' . $bin . ' --site=' . $siteuri['a']['site'] . ' scripts/erp-sync.php >/dev/null 2>&1')
        && !str_contains($cron, 'bbd-stock') && $contine($o, 'nu mai există în codul comun', 'RĂMÂNE : */5') && !str_contains($o, 'alt.ro'), $cron);
    $stare = json_decode((string) @file_get_contents($siteuri['a']['site'] . '/deploy/adopta.stare'), true);
    $verifica('starea: toate cele 8 faze, comutat, pozele mutate din public/uploads', is_array($stare) && count((array) ($stare['faze'] ?? [])) === 8
        && ($stare['comutat'] ?? false) === true && ($stare['uploads'] ?? '') === 'public/uploads');
    [$c, $o] = $deploy('adopta', array_merge($argA, ['--aplica']));
    $verifica('a doua adoptare a aceluiași site: refuzată (stub-ul e deja acolo; o versiune nouă: actualizeaza.sh)', $c !== 0
        && str_contains($o, 'actualizeaza.sh'), $scurt($o));
    [$c, $o] = $ruleaza([PHP_BINARY, '-d', 'pdo_mysql.default_socket=' . $sock, $bin, '--site=a.exemplu.test', 'scripts/migreaza.php', '--stare'], ['HOME' => $acasa]);
    $verifica('bin/platforma --site=<domeniu> (lansatorul cronurilor) rulează migreaza.php --stare pe site-ul A → 0', $c === 0, $scurt($o));
    [$c, $o] = $ruleaza([PHP_BINARY, $bin, '--site=' . $siteuri['a']['site'], '../../../../etc/passwd']);
    [$c2, $o2] = $ruleaza([PHP_BINARY, $bin, '--site=' . $siteuri['a']['site'], '--versiune=2026.01.01-0000000', '--arata']);
    $verifica('bin/platforma: un script din afara versiunii → 2; o versiune care lipsește → 2', $c === 2 && str_contains($o, 'nu e un fișier al versiunii')
        && $c2 === 2 && str_contains($o2, 'nu există'), $scurt($o . $o2));

    // ─────────────────────────────────────────────────────────────────
    // B. Site-ul B: open_basedir, testul de fum care pică, reluarea
    // ─────────────────────────────────────────────────────────────────
    $scrie('');
    $scrie('B. adopta.sh pe site-ul B (open_basedir; testul de fum pică, apoi reluarea)');
    $root->exec("UPDATE cms_dep_b.settings SET `value` = '1' WHERE `key` = 'maintenance_enabled'");
    [$c, $o] = $deploy('adopta', array_merge($argB, ['--aplica']));
    $verifica('mentenanța din admin pornită: oprit în faza „verificare” (prima pagină), cod 1, site-ul în mentenanța deploy-ului', $c === 1
        && $contine($o, 'faza „verificare”', 'NU       prima pagină', 'site-ul NU poate citi în afara dosarului lui') && $cerere($siteuri['b']['url'] . '/')['cod'] === 503, $scurt($o));
    $verifica('open_basedir: versiunea pusă și în site/releases/' . $v1 . ' (legături fixe, aceeași versiune)', is_file($siteuri['b']['site'] . '/releases/' . $v1 . '/VERSION')
        && fileinode($siteuri['b']['site'] . '/releases/' . $v1 . '/bootstrap.php') === fileinode($r1 . '/bootstrap.php'));
    [$c, $o] = $deploy('actualizeaza', ['--site=proba-b', '--versiune=' . $v1, '--url=' . $siteuri['b']['url'], '--aplica']);
    $verifica('actualizeaza.sh pe o adoptare neterminată → refuzat (cod 2), fără nicio schimbare', $c === 2 && str_contains($o, 'nu e terminată')
        && is_file($siteuri['b']['site'] . '/MENTENANTA'), $scurt($o));
    $root->exec("UPDATE cms_dep_b.settings SET `value` = '0' WHERE `key` = 'maintenance_enabled'");
    [$c, $o] = $deploy('adopta', array_merge($argB, ['--aplica']));
    $verifica('după reparare, reluarea: „migrari” făcută deja, testul de fum trece, site-ul B deschis pe ' . $v1, $c === 0
        && $contine($o, 'migrari: făcută deja', 'GATA') && ($ping('b')['cod'] ?? null) === $v1 && $cerere($siteuri['b']['url'] . '/')['cod'] === 200
        && $cerere($siteuri['b']['url'] . '/tema/css/tema-demo.css')['cod'] === 200, $scurt($o));

    // ─────────────────────────────────────────────────────────────────
    // U. actualizeaza.sh
    // ─────────────────────────────────────────────────────────────────
    $scrie('');
    $scrie('U. actualizeaza.sh: o versiune bună, una care pică (întoarcere singură), B pe versiunea nouă');
    file_put_contents($repo . '/README.md', (string) file_get_contents($repo . '/README.md') . "\n<!-- v2 -->\n");
    $git($repo, 'commit', '-qam', 'v2');
    $v2 = $data . '-' . substr(trim($git($repo, 'rev-parse', 'HEAD')[1]), 0, 7);
    [$c2, $o2] = $deploy('release', ['--ref=HEAD', '--aplica']);
    $acasaView = $repo . '/views/site/home.php';
    $verifica('v2 făcută (' . $v2 . ')', $c2 === 0 && is_file($acasa . '/platforma/releases/' . $v2 . '/VERSION') && is_file($acasaView), $scurt($o2));
    file_put_contents($acasaView, "<?php throw new \\RuntimeException('proba: prima pagina pica'); ?>\n" . (string) file_get_contents($acasaView));
    $git($repo, 'commit', '-qam', 'v3: prima pagina pica');
    $v3 = $data . '-' . substr(trim($git($repo, 'rev-parse', 'HEAD')[1]), 0, 7);
    [$c3, $o3] = $deploy('release', ['--ref=HEAD', '--aplica']);
    $verifica('v3 făcută (' . $v3 . '; php -l trece, eroarea apare doar la rulare)', $c3 === 0 && is_dir($acasa . '/platforma/releases/' . $v3), $scurt($o3));

    [$c, $o] = $deploy('actualizeaza', ['--site=proba-a', '--versiune=' . $v2, '--url=' . $siteuri['a']['url']]);
    $verifica('--plan: cod 0, licența și planul migrărilor cu codul nou, nimic schimbat (RELEASE tot ' . $v1 . ')', $c === 0
        && $contine($o, 'cu versiunea nouă, licența se verifică', 'PLAN: nimic schimbat') && trim((string) file_get_contents($siteuri['a']['site'] . '/RELEASE')) === $v1, $scurt($o));
    [$c, $o] = $deploy('actualizeaza', ['--site=proba-a', '--versiune=' . $v2, '--url=' . $siteuri['a']['url'], '--aplica']);
    $verifica('A pe ' . $v2 . ': cod 0, pingul spune ' . $v2 . ', RELEASE schimbat; B rămâne pe ' . $v1, $c === 0 && str_contains($o, 'GATA')
        && ($ping('a')['cod'] ?? null) === $v2 && trim((string) file_get_contents($siteuri['a']['site'] . '/RELEASE')) === $v2
        && ($ping('b')['cod'] ?? null) === $v1, $scurt($o));
    $verifica('assets/ al versiunii noi, cu assets/.htaccess și poza pachetului ale site-ului păstrate; cel de dinainte în .assets-' . $v1,
        str_contains((string) @file_get_contents($siteuri['a']['public'] . '/assets/.htaccess'), '(a)') && is_file($siteuri['a']['public'] . '/assets/img/proba-a.svg')
        && is_dir($siteuri['a']['public'] . '/.assets-' . $v1) && $cerere($siteuri['a']['url'] . '/.assets-' . $v1 . '/css/app.css')['cod'] === 403);
    [$c, $o] = $deploy('actualizeaza', ['--site=proba-a', '--versiune=' . $v3, '--url=' . $siteuri['a']['url'], '--aplica']);
    $verifica('A pe ' . $v3 . ' (prima pagină pică): testul de fum oprește, întoarcere singură la ' . $v2 . ', site-ul deschis, cod 1', $c === 1
        && $contine($o, 'NU       prima pagină', 'Întoarcerea la ' . $v2, 'RELEASE = ' . $v2, 'trece verificarea; MENTENANTA ștearsă')
        && ($ping('a')['cod'] ?? null) === $v2 && trim((string) file_get_contents($siteuri['a']['site'] . '/RELEASE')) === $v2
        && !is_file($siteuri['a']['site'] . '/MENTENANTA') && $cerere($siteuri['a']['url'] . '/')['cod'] === 200, $scurt($o));
    [$c, $o] = $deploy('actualizeaza', ['--site=proba-b', '--versiune=' . $v2, '--url=' . $siteuri['b']['url'], '--aplica']);
    $verifica('B pe ' . $v2 . ': cod 0, versiunea pusă și în site/releases/ (open_basedir), tema păstrată', $c === 0 && ($ping('b')['cod'] ?? null) === $v2
        && is_file($siteuri['b']['site'] . '/releases/' . $v2 . '/VERSION') && $cerere($siteuri['b']['url'] . '/tema/css/tema-demo.css')['cod'] === 200, $scurt($o));
    [$c, $o] = $deploy('release', ['--sterge=' . $v2, '--aplica']);
    [$c3, $o3] = $deploy('release', ['--sterge=' . $v3, '--aplica']);
    $verifica('release.sh --sterge: refuzat pentru ' . $v2 . ' (folosită de site-uri), făcut pentru ' . $v3 . ' (nefolosită)', $c === 1 && str_contains($o, 'e folosită de')
        && $c3 === 0 && !is_dir($acasa . '/platforma/releases/' . $v3), $scurt($o . $o3));

    // ─────────────────────────────────────────────────────────────────
    // V. revino.sh după o adoptare reușită (și după actualizări)
    // ─────────────────────────────────────────────────────────────────
    $scrie('');
    $scrie('V. revino.sh după o adoptare reușită');
    $root->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
    $root->exec("INSERT INTO cms_dep_a.orders (order_number, billing_first_name, billing_last_name, billing_phone, billing_email, billing_address_line1, billing_city, "
        . "billing_county, total) VALUES ('BV-2', 'Ana', 'Pop', '0700000001', 'ana@exemplu.ro', 'Strada 2', 'Iasi', 'Iasi', 10)");
    [$c, $o] = $deploy('revino', ['--site=proba-a', '--url=' . $siteuri['a']['url'], '--aplica']);
    $verifica('o comandă nouă după copie: revino.sh --aplica refuză (cod 1), cu --fara-baza și --pierde-datele-noi; nimic schimbat', $c === 1
        && $contine($o, 'orders: 1 rânduri noi', '--fara-baza', '--pierde-datele-noi') && ($ping('a')['cod'] ?? null) === $v2, $scurt($o));
    [$c, $o] = $deploy('revino', ['--site=proba-a', '--url=' . $siteuri['a']['url'], '--aplica', '--pierde-datele-noi']);
    $verifica('--pierde-datele-noi: cod 0; codul vechi octet cu octet, baza exact cea de dinaintea adoptării', $c === 0
        && $amprentaCod($siteuri['a']['public']) === $amprente['a']['cod'] && $amprentaBaza('cms_dep_a') === $bazaInainteA
        && str_contains($cerere($siteuri['a']['url'] . '/')['corp'], 'COD VECHI'), $scurt($o) . ' | ' . $diferente($amprentaCod($siteuri['a']['public']), $amprente['a']['cod'])
        . ' | ' . $diferente($amprentaBaza('cms_dep_a'), $bazaInainteA));
    [$c, $o] = $deploy('revino', ['--site=proba-b', '--url=' . $siteuri['b']['url'], '--aplica', '--fara-baza']);
    $verifica('B cu --fara-baza: cod 0, codul vechi octet cu octet, baza rămâne migrată (_platforma_stare), codul vechi merge pe ea', $c === 0
        && $amprentaCod($siteuri['b']['public']) === $amprente['b']['cod'] && in_array('_platforma_stare', $root->query("SELECT TABLE_NAME FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = 'cms_dep_b'")->fetchAll(PDO::FETCH_COLUMN), true) && str_contains($cerere($siteuri['b']['url'] . '/')['corp'], 'COD VECHI'), $scurt($o));
    [$c, $o] = $deploy('revino', ['--site=proba-a', '--domeniu=a.exemplu.test']);
    $verifica('revino.sh a doua oară: nimic de întors (cod 1, mesaj clar)', $c === 1 && str_contains($o, 'n-a fost adoptat'), $scurt($o));
    $erori = trim((string) @file_get_contents($dosarTest . '/server-a-erori.log') . (string) @file_get_contents($dosarTest . '/server-b-erori.log'));
    $grave = array_values(array_filter(explode("\n", $erori), static fn (string $l): bool => preg_match('/PHP (Fatal|Parse) error/', $l) === 1 && !str_contains($l, 'proba: prima pagina pica')));
    $verifica('jurnalele serverelor: nicio eroare fatală în afara celei voite (v3)', $grave === [], implode(' | ', array_slice($grave, 0, 3)));
} catch (Throwable $e) {
    $verifica('testul a mers până la capăt', false, $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    if (getenv('CMS_DEV_PASTREAZA') !== '1') {
        $curata();
        clearstatcache();
        $verifica('curățenia: bazele cms_dep_* șterse, serverele oprite, găzduirea simulată ștearsă', !is_dir($dosarTest)
            && (int) $root->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'cms\\_dep\\_%'")->fetchColumn() === 0);
    } else {
        foreach ($pidServere as $pid) {
            $opreste($pid);
        }
        $scrie('CMS_DEV_PASTREAZA=1: găzduirea rămâne în ' . $dosarTest);
    }
}

$scrie('');
$scrie('Rezultat: ' . $trecute . ' verificări trecute, ' . $esuate . ' eșuate.');
exit($esuate === 0 ? 0 : 1);
