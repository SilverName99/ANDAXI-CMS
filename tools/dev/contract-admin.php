<?php

declare(strict_types=1);

/**
 * Contractul adminului (poarta G-admin, WP-29): pentru fiecare pagină de admin (tools/dev/lib/pagini-admin.php) × cele
 * patru roluri (administratorul general, magazin, blog, emailuri) × licențele `toate`, `bioscem` și `grafoanaytis`, ce
 * trimite și ce citește pagina (formularele, câmpurile cu nume, valorile ascunse, opțiunile, butoanele, linkurile,
 * atributele data-* și celulele tabelelor; tools/dev/lib/contract-admin.php), pe codul curent și pe codul de la eticheta
 * `admin-inainte-b` (commit-ul dinaintea refacerii adminului, faza D), pe aceeași bază de dezvoltare. Trece doar dacă
 * fiecare diferență e declarată, cu motivul ei, în tools/dev/lib/contract-admin-declarat.php ($contractAdminPorturi) și
 * fiecare declarație e folosită.
 *
 *   tools/dev/php.sh tools/dev/contract-admin.php                    # toată poarta (întâi autotestul)
 *   tools/dev/php.sh tools/dev/contract-admin.php --autotest         # doar mecanismul (fără servere)
 *   tools/dev/php.sh tools/dev/contract-admin.php --licente=toate --roluri=adm --pagini='^/admin/orders'
 *   tools/dev/php.sh tools/dev/contract-admin.php --lista-json       # lista paginilor, JSON (pentru uneltele din browser)
 *   tools/dev/php.sh tools/dev/contract-admin.php --eticheta=<commit>  # față de alt commit decât eticheta
 *
 * Serverele: codul curent pe CMS_DEV_PORT + 32, codul etichetei (git archive în $CMS_DEV_DIR/baza-admin-<commit>) pe + 33,
 * ambele cu licența într-un dosar al comparației ($CMS_DEV_DIR/contract-admin/licenta), deci licența bazei de dezvoltare
 * (storage/licenta) rămâne neatinsă. Contractele și diferențele fiecărei pagini: $CMS_DEV_DIR/contract-admin/<licență>/<rol>/.
 * Cu un filtru (--licente, --roluri, --pagini) declarațiile nefolosite nu pică (rularea nu e toată poarta).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/lib/contract-admin.php';
require_once __DIR__ . '/lib/pagini-admin.php';

$radacina = dirname(__DIR__, 2);
$optiuni = [];
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--')) {
        fwrite(STDERR, "EROARE: argument necunoscut: $argument\n");
        exit(2);
    }
    [$nume, $valoare] = array_pad(explode('=', substr($argument, 2), 2), 2, '1');
    $optiuni[$nume] = $valoare;
}

$esecuriAutotest = contract_autotest();
foreach ($esecuriAutotest as $esec) {
    echo '  EȘEC  autotest: ', $esec, "\n";
}
if ($esecuriAutotest !== []) {
    echo 'Contractul adminului: autotestul a picat (', count($esecuriAutotest), ").\n";
    exit(1);
}
echo "  OK    autotestul contractului (extragerea, aspectul fără diferențe, 13 mutații, declarațiile, normalizarea)\n";
if (isset($optiuni['autotest'])) {
    exit(0);
}

require_once $radacina . '/bootstrap.php';
$config = require $radacina . '/config/app.php';
$db = \App\Support\Database::connection($config['db']);
if (!$db instanceof PDO) {
    fwrite(STDERR, "EROARE: baza de dezvoltare nu răspunde (tools/dev/instaleaza.sh)\n");
    exit(1);
}
$pagini = pagini_admin($db);
if (isset($optiuni['lista-json'])) {
    echo json_encode(['admin' => $pagini, 'anonim' => pagini_admin_anonim()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$portDev = (int) (getenv('CMS_DEV_PORT') ?: 8701);
$portCurent = $portDev + 32;
$portBaza = $portDev + 33;
$eticheta = (string) ($optiuni['eticheta'] ?? 'admin-inainte-b');
$licente = array_values(array_filter(explode(',', (string) ($optiuni['licente'] ?? 'toate,bioscem,grafoanaytis'))));
$roluri = array_values(array_filter(explode(',', (string) ($optiuni['roluri'] ?? 'adm,magazin,blog,emailuri'))));
$filtruPagini = (string) ($optiuni['pagini'] ?? '');
$filtrat = isset($optiuni['licente']) || isset($optiuni['roluri']) || $filtruPagini !== '';
$emailRol = ['adm' => (string) (getenv('CMS_DEV_ADMIN_EMAIL') ?: 'admin@exemplu.ro'), 'magazin' => 'magazin@exemplu.ro',
    'blog' => 'blog@exemplu.ro', 'emailuri' => 'emailuri@exemplu.ro'];
foreach ($roluri as $rol) {
    if (!isset($emailRol[$rol])) {
        fwrite(STDERR, "EROARE: rol necunoscut: $rol (adm, magazin, blog, emailuri)\n");
        exit(2);
    }
}
$parola = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));

$git = static function (string ...$argumente) use ($radacina): ?string {
    $comanda = 'git -C ' . escapeshellarg($radacina) . ' ' . implode(' ', array_map('escapeshellarg', $argumente)) . ' 2>/dev/null';
    $iesire = [];
    exec($comanda, $iesire, $cod);
    return $cod === 0 ? trim(implode("\n", $iesire)) : null;
};
$commit = $git('rev-parse', '--short', $eticheta . '^{commit}');
if ($commit === null || $commit === '') {
    echo "  EȘEC  eticheta „{$eticheta}” nu există în repo (git fetch origin tag {$eticheta}; WP-29 o pune pe commit-ul dinaintea WP-30)\n";
    exit(1);
}
$dosarBaza = $dosarDev . '/baza-admin-' . $commit;
if (!is_file($dosarBaza . '/public/index.php')) {
    exec('rm -rf ' . escapeshellarg($dosarBaza));
    @mkdir($dosarBaza, 0770, true);
    exec('git -C ' . escapeshellarg($radacina) . ' archive ' . escapeshellarg($commit) . ' | tar -x -C ' . escapeshellarg($dosarBaza), $_, $cod);
    if ($cod !== 0) {
        echo "  EȘEC  nu pot scoate {$eticheta} ({$commit}) în {$dosarBaza}\n";
        exit(1);
    }
}
foreach (glob($dosarBaza . '/storage/sessions/sess_*') ?: [] as $f) {
    @unlink($f);
}
// Aceleași încărcări ca site-ul curent (pozele demo).
exec('rm -rf ' . escapeshellarg($dosarBaza . '/public/uploads'));
if (is_dir($radacina . '/public/uploads')) {
    exec('cp -a ' . escapeshellarg($radacina . '/public/uploads') . ' ' . escapeshellarg($dosarBaza . '/public/uploads'));
}

$dosarLucru = $dosarDev . '/contract-admin';
exec('rm -rf ' . escapeshellarg($dosarLucru));
@mkdir($dosarLucru . '/licenta', 0770, true);
putenv('LICENTA_DOSAR=' . $dosarLucru . '/licenta');
putenv('PHP_CLI_SERVER_WORKERS=4');

$phpDev = [PHP_BINARY];
foreach (['pdo_mysql.default_socket', 'sendmail_path', 'memory_limit', 'error_reporting'] as $ini) {
    if ((string) ini_get($ini) !== '') {
        $phpDev[] = '-d';
        $phpDev[] = $ini . '=' . ini_get($ini);
    }
}
array_push($phpDev, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'allow_url_fopen=0');

$servere = [];
// Routerul lui `php -S` vine de aici (git archive nu scoate tools/, .gitattributes): face doar ce fac cele două .htaccess.
$router = $radacina . '/tools/dev/router.php';
$porneste = static function (string $eticheta, int $port, string $dosar) use (&$servere, $phpDev, $dosarLucru, $router): void {
    $jurnal = $dosarLucru . '/server-' . $eticheta . '.log';
    $comanda = array_merge($phpDev, ['-d', 'error_log=' . $dosarLucru . '/php-erori-' . $eticheta . '.log',
        '-S', '127.0.0.1:' . $port, '-t', $dosar . '/public', $router]);
    $proces = proc_open($comanda, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $jurnal, 'a'], 2 => ['file', $jurnal, 'a']], $_, $dosar);
    if (!is_resource($proces)) {
        throw new RuntimeException('serverul ' . $eticheta . ' nu pornește');
    }
    $servere[$eticheta] = $proces;
    for ($i = 0; $i < 60; $i++) {
        $c = curl_init('http://127.0.0.1:' . $port . '/health');
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 2]);
        curl_exec($c);
        $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        curl_close($c);
        if ($cod === 200) {
            return;
        }
        usleep(200000);
    }
    throw new RuntimeException('serverul ' . $eticheta . ' (portul ' . $port . ') nu răspunde la /health; vezi ' . $jurnal);
};
$opreste = static function () use (&$servere): void {
    foreach ($servere as $proces) {
        $stare = proc_get_status($proces);
        if ($stare['running']) {
            exec('pkill -TERM -P ' . (int) $stare['pid'] . ' 2>/dev/null');
            proc_terminate($proces);
        }
        proc_close($proces);
    }
    $servere = [];
};
register_shutdown_function($opreste);

foreach ([$portCurent, $portBaza] as $port) {
    $c = curl_init('http://127.0.0.1:' . $port . '/health');
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 2]);
    if (curl_exec($c) !== false && (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE) > 0) {
        echo "  EȘEC  pe portul {$port} răspunde deja un server (nepornit de contract); oprește-l întâi\n";
        exit(1);
    }
    curl_close($c);
}

$cerere = static function (int $port, string $metoda, string $cale, string $jar, array $date = []): array {
    $c = curl_init('http://127.0.0.1:' . $port . $cale);
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 120, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_HEADER => true]);
    if ($metoda === 'POST') {
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($date));
    }
    $raspuns = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $marime = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    $antet = substr($raspuns, 0, $marime);
    return ['cod' => $cod, 'location' => preg_match('/^Location:\s*(\S+)/mi', $antet, $m) === 1 ? $m[1] : '',
        'corp' => substr($raspuns, $marime)];
};

$declarateToate = [];
foreach ((array) require __DIR__ . '/lib/contract-admin-declarat.php' as $wp => $lista) {
    foreach ((array) $lista as $cheie => $declaratie) {
        $declaratie['wp'] = $wp;
        $declarateToate[$wp . ':' . $cheie] = $declaratie;
    }
}
$folosite = [];
$nedeclarate = [];
$numarPagini = 0;
$numarRanduri = 0;
$declarateGasite = 0;
$inceput = microtime(true);

try {
    $porneste('curent', $portCurent, $radacina);
    $porneste('baza', $portBaza, $dosarBaza);
    echo "  INFO  cod curent pe portul {$portCurent}, {$eticheta} ({$commit}) pe portul {$portBaza}; licențe: ", implode(', ', $licente),
        '; roluri: ', implode(', ', $roluri), '; ', count($pagini), " pagini (+ login)\n";

    if (isset($optiuni['capturi'])) {
        // Capturile de referință ale fazei D (tools/dev/capturi.cjs, setul „faza-d”), cu licența „toate”: codul etichetei
        // și codul curent, în $CMS_DEV_DIR/capturi/faza-d/{<eticheta>,acum}/<lățime>/. Fără contract.
        $iesire = [];
        exec(implode(' ', array_map('escapeshellarg', array_merge($phpDev, [$radacina . '/tools/dev/licenta.php', 'toate']))) . ' 2>&1', $iesire, $cod);
        $cod === 0 || throw new RuntimeException('licența „toate” nu s-a pus');
        $codCapturi = 0;
        foreach (['baza' => [$portBaza, $eticheta], 'curent' => [$portCurent, 'acum']] as [$port, $numeDosar]) {
            $dosarCapturi = $dosarDev . '/capturi/faza-d/' . $numeDosar;
            exec('rm -rf ' . escapeshellarg($dosarCapturi));
            $env = ['CMS_DEV_URL=http://127.0.0.1:' . $port, 'CMS_DEV_PAROLA=' . $parola, 'CMS_DEV_ADMIN_EMAIL=' . $emailRol['adm'],
                'CMS_DEV_CAPTURI=' . $dosarCapturi, 'CMS_DEV_CAPTURI_SET=faza-d',
                'NODE_PATH=' . (getenv('NODE_PATH') ?: '/opt/node22/lib/node_modules'),
                'PLAYWRIGHT_BROWSERS_PATH=' . (getenv('PLAYWRIGHT_BROWSERS_PATH') ?: '/opt/pw-browsers')];
            if (($latimiCapturi = (string) ($optiuni['latimi'] ?? '')) !== '') {
                $env[] = 'CMS_DEV_CAPTURI_LATIMI=' . $latimiCapturi;
            }
            passthru('env ' . implode(' ', array_map('escapeshellarg', $env)) . ' node ' . escapeshellarg($radacina . '/tools/dev/capturi.cjs')
                . ' 2>&1', $codNode);
            $codCapturi = max($codCapturi, (int) $codNode);
        }
        echo $codCapturi === 0 ? "Capturile fazei D: {$dosarDev}/capturi/faza-d/{{$eticheta},acum}/\n" : "Capturile fazei D au eșuat.\n";
        exit($codCapturi === 0 ? 0 : 1);
    }

    foreach ($licente as $licenta) {
        $iesire = [];
        exec(implode(' ', array_map('escapeshellarg', array_merge($phpDev, [$radacina . '/tools/dev/licenta.php', $licenta]))) . ' 2>&1', $iesire, $cod);
        if ($cod !== 0) {
            throw new RuntimeException('licența „' . $licenta . '” nu s-a pus: ' . implode(' ', array_slice($iesire, -2)));
        }
        $rulari = array_merge([['anonim', pagini_admin_anonim()]], array_map(static fn (string $r): array => [$r, $pagini], $roluri));
        foreach ($rulari as [$rol, $listaRol]) {
            $dosarRol = $dosarLucru . '/' . $licenta . '/' . $rol;
            @mkdir($dosarRol, 0770, true);
            $jaruri = ['baza' => $dosarRol . '/jar-baza.txt', 'curent' => $dosarRol . '/jar-curent.txt'];
            if ($rol !== 'anonim') {
                foreach (['baza' => $portBaza, 'curent' => $portCurent] as $server => $port) {
                    $r = $cerere($port, 'POST', '/admin/login', $jaruri[$server], ['email' => $emailRol[$rol], 'password' => $parola]);
                    $unde = (string) parse_url($r['location'], PHP_URL_PATH);
                    if ($r['cod'] !== 302 || !str_starts_with($unde, '/admin') || $unde === '/admin/login') {
                        throw new RuntimeException("logarea {$emailRol[$rol]} pe serverul {$server} a dus la „{$r['location']}” (cod {$r['cod']}); "
                            . "parola din {$dosarDev}/admin-parola.txt nu e a bazei? (tools/dev/instaleaza.sh)");
                    }
                }
            }
            foreach ($listaRol as $pagina) {
                if ($filtruPagini !== '' && preg_match('#' . $filtruPagini . '#', $pagina['cale']) !== 1) {
                    continue;
                }
                $raspunsuri = [];
                foreach (['baza' => $portBaza, 'curent' => $portCurent] as $server => $port) {
                    $r = $cerere($port, 'GET', $pagina['cale'], $jaruri[$server]);
                    $html = contract_normalizeaza($r['corp'], ['127.0.0.1:' . $portBaza, '127.0.0.1:' . $portCurent]);
                    $raspunsuri[$server] = contract_extrage($r['cod'], contract_normalizeaza($r['location'],
                        ['127.0.0.1:' . $portBaza, '127.0.0.1:' . $portCurent]), $html);
                    file_put_contents($dosarRol . '/' . $pagina['nume'] . '.' . $server . '.txt', implode("\n", $raspunsuri[$server]) . "\n");
                }
                $numarPagini++;
                $numarRanduri += count($raspunsuri['curent']);
                $diferente = contract_diferente($raspunsuri['baza'], $raspunsuri['curent']);
                $inainte = $folosite;
                $ramase = contract_fara_declarate($diferente, $declarateToate, $pagina['cale'], $licenta, $rol, $folosite);
                $declarateGasite += array_sum($folosite) - array_sum($inainte);
                if ($diferente !== []) {
                    $text = '';
                    foreach ($diferente as $rand => $n) {
                        $text .= ($n > 0 ? '+' : '-') . abs($n) . ' ' . $rand . (isset($ramase[$rand]) ? '' : '   (declarat)') . "\n";
                    }
                    file_put_contents($dosarRol . '/' . $pagina['nume'] . '.diff', $text);
                }
                foreach ($ramase as $rand => $n) {
                    $nedeclarate[] = sprintf('%s %s %s: %s%d %s', $licenta, $rol, $pagina['cale'], $n > 0 ? '+' : '-', abs($n), $rand);
                }
            }
        }
    }
} catch (Throwable $e) {
    echo '  EȘEC  ', $e->getMessage(), "\n";
    exit(1);
} finally {
    $opreste();
}

$nefolosite = $filtrat ? [] : array_diff(array_keys($declarateToate), array_keys($folosite));
foreach (array_slice($nedeclarate, 0, 60) as $linie) {
    echo '  DIFERĂ ', $linie, "\n";
}
if (count($nedeclarate) > 60) {
    echo '  …     încă ', count($nedeclarate) - 60, " rânduri (toate în {$dosarLucru}/<licență>/<rol>/*.diff)\n";
}
foreach ($nefolosite as $cheie) {
    echo '  EȘEC  declarația ', $cheie, ' (', (string) ($declarateToate[$cheie]['motiv'] ?? ''), ") n-a acoperit nimic în rulare\n";
}
printf("Contractul adminului față de %s (%s): %d pagini × rol × licență, %d rânduri; diferențe nedeclarate %d, declarate %d (%d declarații); %.0f s\n",
    $eticheta, $commit, $numarPagini, $numarRanduri, count($nedeclarate), $declarateGasite, count($declarateToate), microtime(true) - $inceput);
exit($nedeclarate === [] && $nefolosite === [] ? 0 : 1);
