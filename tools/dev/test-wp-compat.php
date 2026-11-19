<?php

declare(strict_types=1);

/**
 * Testele stratului de adrese WordPress (App\Support\WpCompat, WpCompat\Ghicire, WpCompat\Harta, slug-urile Unicode;
 * docs/SEO.md, „Adresele unui site mutat de pe WordPress”), poarta G-wp, pe un site INVENTAT („Pensiunea Probă”: ID-uri
 * WordPress, slug-uri românești, grecești și cu majuscule). Nicio dată a unui client.
 *
 *   tools/dev/php.sh tools/dev/test-wp-compat.php
 *   tools/dev/php.sh tools/dev/test-wp-compat.php --oracol=<inventar>/goluri/r2-2/guess-permalink-301.json \
 *       --redirecturi=<inventar>/seo/redirects.json[,<alt tabel în același format>…] [--antete=<inventar>/seo/headers.json] \
 *       [--rest=<dosarul exporturilor REST>] [--raport=<fișier>]
 *
 * Fără argumente (G-wp):
 *  A. fără server: validarea wp-compat.json, forma adreselor (slash-ul final, canonicalul, segmentele WordPress), numele
 *     ghicirii (sanitize_title_for_query), formele de cale pe care WordPress le ghicește, parametrii adreselor simple,
 *     resturile cu 410 și endpointurile compatibile, Slug::unicode;
 *  B. pe o bază nouă (scripts/install.php, cu nucleu/0069): harta (Harta::scrie) și ghicirea pe fiecare regulă: 1–2
 *     segmente, cel mai mic ID WordPress, paginile din constructor după, numărul paginii (două salturi), fluxurile, arhivele,
 *     3 segmente, ciornele, atașamentele, o pagină redenumită sau ștearsă;
 *  C. pe un server cu wp-compat.json (APP_URL https, gazda inventată): slash-ul final într-un salt, majusculele 200 cu
 *     canonicalul cu litere mici, slug-urile Unicode, ?page_id= / ?p= / ?cat= / ?author= / ?attachment_id= / /index.php,
 *     atașamentele la fișier, ghicirea cu query-ul păstrat, sitemap-urile vechi (302), 404 cu antetele WordPress și /404/,
 *     resturile cu 410 (și endpointurile compatibile fără 410 și fără 301), sitemap-ul generat cu „/”, HEAD ca GET;
 *  D. adminul pe același server: adresa din editorul paginii, slug-ul neschimbat nerenormalizat, slug-ul Unicode din titlu,
 *     unicitatea pe calea întreagă, redirecționarea automată la schimbarea slug-ului (WP-38) cu „/”, meniul;
 *  E. fără wp-compat.json (al doilea server, aceeași bază): adresele, redirecționările și HEAD ca înainte, iar
 *     instantaneul rutelor (tools/dev/instantaneu-rute.php) neschimbat;
 *  F. cu pluginul catalog (o licență a testului, tools/dev/licenta.php catalog), cu și fără wp-compat.json: un produs
 *     căutat după un slug aproximativ primește 301-ul nucleului doar fără wp-compat.json.
 *
 * Cu --oracol și --redirecturi (datele reale, din afara repo-ului): harta ID-urilor din exporturile REST ale site-ului
 * (implicit `<inventar>/cache`), o bază și un server ca la C, apoi fiecare rând al oracolului ghicirii și al tabelelor de
 * redirecționări (--redirecturi: unul sau mai multe fișiere, despărțite prin virgulă), comparat; cu --antete (antetele
 * live, pe etichete), și antetele fiecărui 404 (tools/dev/lib/wp-compat-oracol.php). Raportul: --raport, implicit
 * $CMS_DEV_DIR/test-wp-compat-oracol.txt; cod 0 doar cu 0 diferențe.
 *
 * Bazele (cms_wpc, cms_wpc_oracol), serverele (CMS_DEV_PORT+21…+23) și dosarul $CMS_DEV_DIR/test-wp-compat/ se șterg la
 * final. Raport: $CMS_DEV_DIR/test-wp-compat-raport.txt; cod 0 doar dacă totul a trecut.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$optiuni = ['oracol' => null, 'redirecturi' => null, 'antete' => null, 'rest' => null, 'raport' => null];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(oracol|redirecturi|antete|rest|raport)=(.+)$/', $arg, $m) === 1) {
        $optiuni[$m[1]] = $m[2];
        continue;
    }
    fwrite(STDERR, "Argument necunoscut: {$arg} (vezi începutul fișierului)\n");
    exit(2);
}
$modOracol = $optiuni['oracol'] !== null || $optiuni['redirecturi'] !== null;
if ($modOracol && ($optiuni['oracol'] === null || $optiuni['redirecturi'] === null)) {
    fwrite(STDERR, "--oracol și --redirecturi merg împreună\n");
    exit(2);
}
if (!$modOracol && ($optiuni['antete'] !== null || $optiuni['rest'] !== null || $optiuni['raport'] !== null)) {
    fwrite(STDERR, "--antete, --rest și --raport merg doar cu --oracol și --redirecturi\n");
    exit(2);
}

$lucru = $dosarDev . '/test-wp-compat';
exec('rm -rf ' . escapeshellarg($lucru));
foreach (['site/storage', 'site-fara/storage', 'public', 'pachet', 'pachet-fara', 'licenta'] as $d) {
    @mkdir($lucru . '/' . $d, 0770, true);
}

require_once $radacina . '/bootstrap.php';

use App\Support\DateSite;
use App\Support\Slug;
use App\Support\WpCompat;
use App\Support\WpCompat\Ghicire;
use App\Support\WpCompat\Harta;

// Pe datele reale, jurnalul stă lângă raportul cerut (--raport), deci în afara repo-ului când raportul e acolo.
$fisierRaport = $modOracol
    ? ($optiuni['raport'] !== null ? dirname((string) $optiuni['raport']) : $dosarDev) . '/test-wp-compat-oracol-jurnal.txt'
    : $dosarDev . '/test-wp-compat-raport.txt';
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

// ---------------------------------------------------------------------------------------------------------------------
// Bazele, serverele, cererile
// ---------------------------------------------------------------------------------------------------------------------
$socket = (string) (getenv('CMS_DEV_SOCK') ?: ini_get('pdo_mysql.default_socket'));
$utilizator = (string) (getenv('CMS_DEV_DB_USER') ?: 'root');
$parolaDb = (string) (getenv('CMS_DEV_DB_PAROLA') ?: '');
$root = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', $utilizator, $parolaDb,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$conectare = static fn (string $baza): PDO => new PDO('mysql:unix_socket=' . $socket . ';dbname=' . $baza . ';charset=utf8mb4',
    $utilizator, $parolaDb, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$baze = [];
$serverePornite = [];
$opresteServerele = static function () use (&$serverePornite): void {
    foreach ($serverePornite as $pid) {
        if ($pid > 0) {
            // Doar procesele pornite aici (serverul și procesele lui de lucru).
            exec('pkill -TERM -P ' . (int) $pid . ' 2>/dev/null; kill ' . (int) $pid . ' 2>/dev/null');
            for ($i = 0; $i < 50 && trim((string) shell_exec('pgrep -P ' . (int) $pid . ' 2>/dev/null')) !== ''; $i++) {
                usleep(100000);
            }
        }
    }
    $serverePornite = [];
};
register_shutdown_function(static function () use (&$baze, $root, $lucru, $opresteServerele): void {
    $opresteServerele();
    $GLOBALS['db'] = null;
    gc_collect_cycles();
    foreach ($baze as $b) {
        try {
            $root->exec('DROP DATABASE IF EXISTS `' . $b . '`');
        } catch (Throwable) {
        }
    }
    exec('rm -rf ' . escapeshellarg($lucru));
});

/** O bază nouă a site-ului, făcută de scripts/install.php (migrările, cu nucleu/0069), fără licență: doar nucleul. */
$bazaNoua = static function (string $baza) use ($root, $radacina, $lucru, &$baze): array {
    $baze[] = $baza;
    $root->exec('DROP DATABASE IF EXISTS `' . $baza . '`');
    $root->exec('CREATE DATABASE `' . $baza . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $env = array_merge(getenv(), ['CMS_DEV_DB' => $baza, 'CMS_DEV_LICENTA_DOSAR' => $lucru . '/licenta']);
    $p = proc_open([$radacina . '/tools/dev/php.sh', 'scripts/install.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, $radacina, $env);
    $out = (string) stream_get_contents($pipe[1]) . (string) stream_get_contents($pipe[2]);
    fclose($pipe[1]);
    fclose($pipe[2]);
    return [proc_close($p), $out];
};

/**
 * O cerere HTTP fără redirecționări urmate (gazda, implicit cea inventată a site-ului).
 *
 * @return array{cod: int, antete: array<string, string>, corp: string}
 */
$cerere = static function (int $port, string $metoda, string $cale, array $antete = [], ?string $jar = null, array $date = []): array {
    $c = curl_init('http://127.0.0.1:' . $port . $cale);
    $antetePrimite = [];
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metoda,
        CURLOPT_NOBODY => $metoda === 'HEAD',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOPROXY => '*',
        CURLOPT_PROXY => '',
        CURLOPT_TIMEOUT => 60,
        CURLOPT_PATH_AS_IS => true,
        CURLOPT_HTTPHEADER => $antete,
        CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$antetePrimite): int {
            if (str_contains($linie, ':')) {
                [$nume, $valoare] = explode(':', $linie, 2);
                $antetePrimite[strtolower(trim($nume))] = trim($valoare);
            }
            return strlen($linie);
        },
    ]);
    if ($metoda === 'POST') {
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($date));
    }
    if ($jar !== null) {
        curl_setopt($c, CURLOPT_COOKIEFILE, $jar);
        curl_setopt($c, CURLOPT_COOKIEJAR, $jar);
    }
    $corp = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $cod, 'antete' => $antetePrimite, 'corp' => $corp];
};

/**
 * Un server `php -S` cu baza, pachetul și dosarul de site date (PLATFORMA_SITE_DIR prin auto_prepend_file: cache-ul de
 * pagini, sesiunile și copia hărții nu se amestecă cu ale serverului de dezvoltare).
 */
$pornesteServer = static function (int $port, string $baza, string $pachet, string $dosarSite, string $appUrl, string $dosarLicenta = '')
    use ($radacina, $lucru, $dosarDev, $cerere, &$serverePornite): bool {
    $preludiu = $dosarSite . '/preludiu.php';
    @mkdir($dosarSite . '/storage', 0770, true);
    file_put_contents($preludiu, "<?php\ndefine('PLATFORMA_SITE_DIR', " . var_export($dosarSite, true) . ");\ndefine('PLATFORMA_PUBLIC_DIR', "
        . var_export($lucru . '/public', true) . ");\n");
    $ini = ['-d ' . escapeshellarg('auto_prepend_file=' . $preludiu)];
    foreach (['pdo_mysql.default_socket', 'error_reporting', 'display_errors', 'log_errors', 'error_log', 'sendmail_path', 'memory_limit'] as $cheie) {
        $valoare = ini_get($cheie);
        if ($valoare !== false && $valoare !== '') {
            $ini[] = '-d ' . escapeshellarg($cheie . '=' . $valoare);
        }
    }
    $ini[] = '-d allow_url_fopen=0';
    $mediu = ['PHP_CLI_SERVER_WORKERS' => '2', 'DB_DATABASE' => $baza, 'SITE_DATE_DOSAR' => $pachet,
        'LICENTA_DOSAR' => $dosarLicenta !== '' ? $dosarLicenta : $lucru . '/licenta', 'APP_URL' => $appUrl];
    $env = '';
    foreach ($mediu as $nume => $valoare) {
        $env .= ' ' . $nume . '=' . escapeshellarg($valoare);
    }
    $comanda = 'cd ' . escapeshellarg($radacina) . ' && env' . $env . ' nohup ' . escapeshellarg(PHP_BINARY) . ' ' . implode(' ', $ini)
        . ' -S 127.0.0.1:' . $port . ' -t public tools/dev/router.php >> ' . escapeshellarg($dosarDev . '/server-wpc-' . $port . '.log')
        . ' 2>&1 & echo $!';
    $pid = (int) trim((string) shell_exec($comanda));
    $serverePornite[$port] = $pid;
    for ($i = 0; $i < 60; $i++) {
        if ($cerere($port, 'GET', '/health')['cod'] === 200) {
            return true;
        }
        usleep(200000);
    }
    return false;
};

if ($modOracol) {
    require __DIR__ . '/lib/wp-compat-oracol.php';
    exit($rezultatOracol);
}

$scrie('Test stratul de adrese WordPress (wp-compat.json) — ' . date('Y-m-d H:i:s'));

// ---------------------------------------------------------------------------------------------------------------------
// Pachetele de probă
// ---------------------------------------------------------------------------------------------------------------------
$gazda = 'pensiune-proba.example.test';
$baza = 'https://' . $gazda;
$configurare = [
    'slash_final' => true, 'majuscule' => '200', 'ghicire' => true, 'harta_id' => true, 'atasamente_la_fisier' => true, 'cautare_wp' => true,
    'sitemap_302' => ['/wp-sitemap.xml', '/sitemap_index.xml'],
    'resturi_410' => ['/wp-json/', '/xmlrpc.php', '/wp-login.php', '/wp-admin/', '/readme.html'],
    'compatibile' => [
        ['metoda' => 'POST', 'cale' => '/wp-admin/admin-ajax.php', 'actiune' => 'galerie_proba'],
        ['metoda' => 'GET', 'cale' => '/wp-json/proba/v1/consimtamant'],
    ],
    'antete_404' => 'wordpress',
];
file_put_contents($lucru . '/pachet/wp-compat.json', (string) json_encode($configurare, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
foreach (['pachet', 'pachet-fara'] as $p) {
    file_put_contents($lucru . '/' . $p . '/redirecturi.json', '{"exacte": [], "prefixe": []}');
    file_put_contents($lucru . '/' . $p . '/seo.json', '{}');
}
$folosestePachetul = static function (?string $dosar): void {
    DateSite::reseteaza($dosar);
    WpCompat::reseteaza();
};

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== A. Fără server: configurarea, forma adreselor, numele ghicirii, Slug::unicode');
$folosestePachetul($lucru . '/pachet-fara');
$verifica('fără wp-compat.json stratul e oprit: nicio cale schimbată (cuSlash, caleCanonica, formaCale), nicio cale exclusă în plus',
    !WpCompat::activ() && WpCompat::cuSlash('/camere') === '/camere' && WpCompat::caleCanonica('/CAMERE') === '/CAMERE'
    && \App\Support\SeoRedirects::formaCale('/camere/') === '/camere' && WpCompat::caiExcluseSeo() === [] && !WpCompat::slugUnicode()
    && !WpCompat::eRest('/wp-login.php'));
$verifica('exemplul de configurare trece validarea', WpCompat::valideaza($configurare) === []);
foreach ([
    'o listă în loc de obiect' => [[1, 2], 'obiect'],
    'o cheie necunoscută' => [['slash' => true], 'cheie necunoscută'],
    'majuscule cu altă valoare' => [['majuscule' => 200], 'majuscule'],
    'o cale relativă în resturi_410' => [['resturi_410' => ['wp-json/']], 'cale nevalidă'],
    'o cale cu „//”' => [['sitemap_302' => ['//x.xml']], 'cale nevalidă'],
    'un endpoint fără cale' => [['compatibile' => [['metoda' => 'POST']]], 'cale nevalidă'],
    'o acțiune cu spații' => [['compatibile' => [['metoda' => 'POST', 'cale' => '/a.php', 'actiune' => 'a b']]], 'actiune nevalidă'],
    'o bază cu majuscule' => [['baze_fara_ghicire' => ['Category']], 'segment nevalid'],
] as $eticheta => [$date, $asteptat]) {
    $probleme = WpCompat::valideaza($date);
    $verifica('validarea refuză ' . $eticheta, $probleme !== [] && str_contains(implode(' ', $probleme), $asteptat), implode('; ', $probleme));
}
$folosestePachetul($lucru . '/pachet');
$verifica('cu wp-compat.json: stratul pornit, cu fiecare cheie citită',
    WpCompat::activ() && WpCompat::slashFinal() && WpCompat::majuscule200() && WpCompat::ghicire() && WpCompat::hartaId()
    && WpCompat::atasamenteLaFisier() && WpCompat::cautareWp() && WpCompat::antete404() && WpCompat::slugUnicode()
    && WpCompat::sitemap302() === ['/wp-sitemap.xml', '/sitemap_index.xml'] && WpCompat::bazeFaraGhicire() === WpCompat::BAZE_IMPLICITE);
$forme = [
    ['cuSlash', '/camere', '/camere/'], ['cuSlash', '/camere/', '/camere/'], ['cuSlash', '/', '/'], ['cuSlash', '/sitemap.xml', '/sitemap.xml'],
    ['cuSlash', '/index.php', '/index.php'], ['cuSlash', '/a.b/c', '/a.b/c/'],
    ['cuSlashAdresa', '/camere?x=1', '/camere/?x=1'], ['cuSlashAdresa', '/camere#sus', '/camere/#sus'], ['cuSlashAdresa', 'https://alt.example/x', 'https://alt.example/x'],
    ['caleCanonica', '/CAMERE', '/camere/'], ['caleCanonica', '/camere-%C8%99i-pre%C8%9Buri', '/camere-%c8%99i-pre%c8%9buri/'],
    ['caleCanonica', '/camere//dubla', '/camere/dubla/'], ['caleCanonica', '/', '/'], ['caleCanonica', '/sitemap-pagini.xml', '/sitemap-pagini.xml'],
    ['caleCanonica', "/camere-\u{219}i", '/camere-%c8%99i/'],
    // Escape-urile ASCII fără rost se decodează (WordPress scrie canonicalul din post_name): /cam%65re/ → /camere/.
    ['caleCanonica', '/cam%65re/', '/camere/'], ['caleCanonica', '/camere%2Dnoi', '/camere-noi/'], ['caleCanonica', '/CAM%45RE/', '/camere/'],
    ['caleCanonica', '/a%2Fb', '/a%2fb/'], ['caleCanonica', '/camere%20noi/', '/camere%20noi/'],
    ['adresaPagina', '', '/'], ['adresaPagina', 'camere-și-prețuri', '/camere-%c8%99i-pre%c8%9buri/'], ['adresaPagina', 'camere/camera-dubla', '/camere/camera-dubla/'],
    ['adresaPagina', 'δωμάτια', '/%ce%b4%cf%89%ce%bc%ce%ac%cf%84%ce%b9%ce%b1/'], ['adresaPagina', 'oferte_vara', '/oferte_vara/'],
    ['segment', 'a?b#c%d', 'a%3fb%23c%25d'],
];
$gresite = [];
foreach ($forme as [$functie, $intrare, $asteptat]) {
    $iesire = WpCompat::$functie($intrare);
    if ($iesire !== $asteptat) {
        $gresite[] = $functie . '(' . $intrare . ') = ' . $iesire . ', nu ' . $asteptat;
    }
}
$verifica('forma adreselor (' . count($forme) . ' cazuri): „/” final doar la pagini, canonicalul cu litere mici și escape-urile cu litere mici, segmentele WordPress',
    $gresite === [], implode('; ', $gresite));
$verifica('aceeași pagină: doar literele mari ASCII pot diferi (nu diacriticele, nu Ș/ș, nu Δ/δ)',
    WpCompat::acelasiSlug('CAMERE', 'camere') && WpCompat::acelasiSlug('camere-și', 'camere-și') && !WpCompat::acelasiSlug('camere-si', 'camere-și')
    && !WpCompat::acelasiSlug('CAMERE-ȘI', 'camere-și') && !WpCompat::acelasiSlug('ΔΩΜΆΤΙΑ', 'δωμάτια'));
$nume = [
    'evenimente' => 'evenimente', 'Evenimente' => 'evenimente', 'evenimente-de' => 'evenimente-de', 'evenimente-' => 'evenimente',
    'hello.world' => 'hello-world', 'a%20b' => 'a-b', 'a+b' => 'a-b', 'CAMERE-%C8%98I' => 'camere-%c8%99i', 'camere-%C8%99i' => 'camere-%c8%99i',
    '%CE%94%CE%A9' => '%ce%b4%cf%89', 'x&amp;y' => 'xy', '<b>c</b>' => 'c', '--c--' => 'c', 'c_x' => 'c_x', '%25' => '', 'ÎNCHIRIERE' => '%c3%aenchiriere',
    // P1 (pasul „%”, scaner propriu, nu rescrierea „---$1---” a WordPress): un „%” urmat de două cifre hex rămâne (apoi tot
    // e lowercase), altfel „%” se omite; cazuri-limită: o cifră, la sfârșit, ne-hex.
    'end-cu-%' => 'end-cu', 'la-%2-mij' => 'la-2-mij', 'ne%zzhex' => 'nezzhex', 'pastrat-%C8%99-aici' => 'pastrat-%c8%99-aici',
];
$gresite = [];
foreach ($nume as $intrare => $asteptat) {
    if (Ghicire::nume((string) $intrare) !== $asteptat) {
        $gresite[] = $intrare . ' → ' . Ghicire::nume((string) $intrare) . ', nu ' . $asteptat;
    }
}
$verifica('numele ghicirii ca sanitize_title_for_query (' . count($nume) . ' cazuri: litere mici și în afara ASCII-ului, „.” → „-”, entitățile, cratimele)',
    $gresite === [], implode('; ', $gresite));
$formeCale = [
    '/c/' => ['c', 0], '/ro/camere/' => ['camere', 0], '/camere/excursii/' => ['excursii', 0], '/even/2/' => ['even', 2],
    '/even/page/3/' => ['even', 0], '/even/page3/' => ['even', 0], '/even/comment-page-2/' => ['even', 0], '/attachment/x/' => ['x', 0],
    '/even/feed/' => null, '/even/rss2/' => null, '/even/embed/' => null, '/even/trackback/' => null, '/feed/' => null, '/rss/' => null,
    '/embed/' => null, '/2026/' => null, '/2026/01/' => null, '/2026/page/2/' => null, '/category/camere/' => null, '/tag/x/' => null,
    '/author/x/' => null, '/search/x/' => null, '/type/x/' => null, '/feed/atom/' => null, '/page/2/' => null, '/comments/feed/' => null,
    '/x/y/z/' => null, '/a/b/c/d/' => null, '/2026/x/' => ['x', 0], '/page/x/' => ['x', 0], '/trackback/' => ['trackback', 0],
];
$gresite = [];
foreach ($formeCale as $cale => $asteptat) {
    $f = Ghicire::forma(array_values(array_filter(explode('/', $cale), static fn ($s) => $s !== '')));
    if ($f !== $asteptat) {
        $gresite[] = $cale . ' → ' . json_encode($f) . ', nu ' . json_encode($asteptat);
    }
}
$verifica('formele de cale pe care WordPress le ghicește (' . count($formeCale) . ' cazuri: 1–2 segmente, numărul paginii, fluxurile, arhivele, bazele)',
    $gresite === [], implode('; ', $gresite));
$parametri = [
    'page_id=14' => ['page_id', 14, ''], 'page_id=14&utm_source=x' => ['page_id', 14, 'utm_source=x'], 'utm_a=1&p=3&b=2' => ['p', 3, 'utm_a=1&b=2'],
    'cat=1' => ['cat', 1, ''], 'author=1&x=y' => ['author', 1, 'x=y'], 'attachment_id=81' => ['attachment_id', 81, ''],
    'page_id=abc' => [null, 0, ''], 'p=0' => [null, 0, ''], 'utm_source=x' => [null, 0, ''], '' => [null, 0, ''], 'p=1&page_id=2' => ['p', 1, ''],
];
$gresite = [];
foreach ($parametri as $query => $asteptat) {
    if (WpCompat::parametruId((string) $query) !== $asteptat) {
        $gresite[] = $query . ' → ' . json_encode(WpCompat::parametruId((string) $query));
    }
}
$verifica('parametrii adreselor simple (?p= înaintea lui ?page_id=; restul query-ului rămâne, în ordine)', $gresite === [], implode('; ', $gresite));
$verifica('resturile cu 410 (căile exacte și cele cu „/” final, fără deosebire de litere, și codate) și endpointurile compatibile (metoda, calea, acțiunea)',
    WpCompat::eRest('/wp-login.php') && WpCompat::eRest('/WP-LOGIN.PHP') && WpCompat::eRest('/wp-json/') && WpCompat::eRest('/wp-json')
    && WpCompat::eRest('/wp-json/wp/v2/users') && WpCompat::eRest('/wp-admin/admin-ajax.php') && WpCompat::eRest('/wp%2Djson/x')
    && !WpCompat::eRest('/wp-content/uploads/x.png') && !WpCompat::eRest('/wp-includes/js/x.js') && !WpCompat::eRest('/camere/')
    && WpCompat::eCompatibil('POST', '/wp-admin/admin-ajax.php', ['action' => 'galerie_proba'])
    && !WpCompat::eCompatibil('POST', '/wp-admin/admin-ajax.php', ['action' => 'altceva'])
    && !WpCompat::eCompatibil('GET', '/wp-admin/admin-ajax.php', [], ['action' => 'galerie_proba'])
    && WpCompat::eCompatibil('GET', '/wp-json/proba/v1/consimtamant') && !WpCompat::eCompatibil('POST', '/wp-json/proba/v1/consimtamant')
    && WpCompat::eCompatibil('HEAD', '/wp-json/proba/v1/consimtamant') && !WpCompat::eCompatibil('HEAD', '/wp-admin/admin-ajax.php', [], ['action' => 'galerie_proba']));
$verifica('endpointurile compatibile cu „//” în cale (strâns, ca la resturi): POST /wp-admin//admin-ajax.php cu acțiunea lui e tot compatibil',
    WpCompat::eRest('/wp-admin//admin-ajax.php') && WpCompat::eCompatibil('POST', '/wp-admin//admin-ajax.php', ['action' => 'galerie_proba'])
    && WpCompat::eCompatibil('GET', '/wp-json//proba/v1/consimtamant') && WpCompat::eCompatibil('GET', '/WP-JSON/proba/v1/consimtamant')
    && !WpCompat::eCompatibil('POST', '/wp-admin//admin-ajax.php', ['action' => 'altceva']));
$verifica('redirecționările SEO ocolesc resturile și endpointurile (WpCompat::neatinsDeSeo), dar ele nu intră în lista căilor excluse a '
    . 'coșului și a contului (SeoRedirects::caiExcluse: canonicalul și sesiunea pe 404)',
    WpCompat::neatinsDeSeo('/wp-json/proba/v1/consimtamant') && WpCompat::neatinsDeSeo('/wp-admin//admin-ajax.php') && WpCompat::neatinsDeSeo('/xmlrpc.php')
    && !WpCompat::neatinsDeSeo('/camere/') && !\App\Support\SeoRedirects::eExclusa('/wp-json/proba/v1/consimtamant')
    && !\App\Support\SeoRedirects::eExclusa('/wp-admin/admin-ajax.php') && \App\Support\SeoRedirects::eExclusa('/cos'));
// slugComparabil (SiteController, potrivirea aproximativă a produselor): cu slug-urile Unicode păstrează literele altor
// alfabete. Singurul apelant e oprit în modul WordPress (F), deci ramura se verifică direct, pe ambele pachete.
$site = new \App\Http\Controllers\SiteController();
$comparabil = static fn (string $v): string => (string) (new ReflectionMethod($site, 'slugComparabil'))->invoke($site, $v);
$cuUnicode = [$comparabil('Δωμάτια'), $comparabil('Σουίτα-2'), $comparabil('Kit-Vacanță_2024'), $comparabil('%CE%B4%CF%89')];
$folosestePachetul($lucru . '/pachet-fara');
$faraUnicode = [$comparabil('Δωμάτια'), $comparabil('Σουίτα-2'), $comparabil('Kit-Vacanță_2024')];
$folosestePachetul($lucru . '/pachet');
$verifica('slugComparabil: cu slug-urile Unicode două slug-uri grecești diferite rămân diferite (δωμάτια, σουίτα2), latina ca înainte; '
    . 'fără wp-compat.json ca până acum (grecește rămân doar cifrele)',
    $cuUnicode === ['δωμάτια', 'σουίτα2', 'kitvacanta2024', 'δω'] && $faraUnicode === ['', '2', 'kitvacanta2024'],
    json_encode([$cuUnicode, $faraUnicode], JSON_UNESCAPED_UNICODE));
$slugUri = ['Camere și Prețuri!' => 'camere-și-prețuri', 'Δωμάτια 2026' => 'δωμάτια-2026', '  --A_b  c--' => 'a-b-c', "s\u{326}i" => 'și',
    'Preț: 100%' => 'preț-100', 'ÎNCHIRIERE Integrală' => 'închiriere-integrală', '€' => ''];
$gresite = [];
foreach ($slugUri as $intrare => $asteptat) {
    if (Slug::unicode((string) $intrare) !== $asteptat) {
        $gresite[] = $intrare . ' → ' . Slug::unicode((string) $intrare);
    }
}
$verifica('Slug::unicode: literele oricărei limbi, cifrele, cratima; NFC; litere mici; simbolurile scoase', $gresite === [], implode('; ', $gresite));
// Adminul: slug-urile adreselor cu literele oricărei limbi; numele tehnice (câmpurile, cheile, fișierele) rămân ASCII.
$admin = new \App\Http\Controllers\AdminController();
$privatAdmin = static fn (string $metoda, string $valoare): string => (string) (new ReflectionMethod($admin, $metoda))->invoke($admin, $valoare);
$folosestePachetul($lucru . '/pachet-fara');
$slugFara = $privatAdmin('slugify', 'Cameră Dublă') . ' ' . $privatAdmin('normalizePageSlug', '/Camere-%C8%99i/Dubla/');
$folosestePachetul($lucru . '/pachet');
$verifica('adminul: slugify() și normalizePageSlug() cu slug-urile Unicode (un segment percent-encoded decodat), slugifyAscii() pentru numele '
    . 'tehnice; fără wp-compat.json ca până acum',
    $privatAdmin('slugify', 'Cameră Dublă') === 'cameră-dublă' && $privatAdmin('slugify', 'Δωμάτια') === 'δωμάτια'
    && $privatAdmin('normalizePageSlug', '/Camere-%C8%99i/Dubla/') === 'camere-și/dubla' && $privatAdmin('slugifyAscii', 'Cameră Dublă') === 'camer-dubl'
    && $slugFara === 'camer-dubl camere-c899i/dubla',
    $privatAdmin('slugify', 'Cameră Dublă') . ' / ' . $privatAdmin('normalizePageSlug', '/Camere-%C8%99i/Dubla/') . ' / ' . $privatAdmin('slugifyAscii', 'Cameră Dublă')
    . ' / fără: ' . $slugFara);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== B. Harta și ghicirea, pe o bază nouă');
[$cod, $out] = $bazaNoua('cms_wpc');
$verifica('scripts/install.php pe o bază nouă (cu nucleu/0069, tabelul wp_harta)', $cod === 0, trim($out));
$db = $conectare('cms_wpc');
$verifica('baza are wp_harta', Harta::disponibil($db));
$db->exec('DELETE FROM pages');
$pagini = [];
$adaugaPagina = static function (string $titlu, string $slug, int $publicata = 1) use ($db, &$pagini): int {
    $db->prepare('INSERT INTO pages (title, slug, html_content, css_content, js_content, is_published) VALUES (?, ?, ?, "", "", ?)')
        ->execute([$titlu, $slug, '<section><h1>' . htmlspecialchars($titlu) . '</h1><p>Pagina de probă a pensiunii.</p></section>', $publicata]);
    return $pagini[$slug] = (int) $db->lastInsertId();
};
// Postările WordPress (inventate), în ordinea ID-urilor: prima pagină, pagini românești, grecești, una copil, o ciornă,
// un articol (fără pagină în constructor), un proiect, atașamente, o categorie, un autor.
$wp = [
    [2, 'page', 'acasa', '/', 'publish', '', 'Acasă', ''],
    [5, 'page', 'termeni-si-conditii', '/termeni-si-conditii/', 'publish', '', 'Termeni', 'termeni-si-conditii'],
    [14, 'page', 'camere', '/camere/', 'publish', '', 'Camere', 'camere'],
    [21, 'page', 'camere-%c8%99i-pre%c8%9buri', '/camere-%c8%99i-pre%c8%9buri/', 'publish', '', 'Camere și prețuri', 'camere-și-prețuri'],
    [33, 'page', '%ce%b4%cf%89%ce%bc%ce%ac%cf%84%ce%b9%ce%b1', '/%ce%b4%cf%89%ce%bc%ce%ac%cf%84%ce%b9%ce%b1/', 'publish', '', 'Δωμάτια', 'δωμάτια'],
    [40, 'page', 'contact', '/contact/', 'publish', '', 'Contact', 'contact'],
    [52, 'page', 'camera-dubla', '/camere/camera-dubla/', 'publish', '', 'Camera dublă', 'camere/camera-dubla'],
    [60, 'page', 'evenimente-de-proba', '/evenimente-de-proba/', 'publish', '', 'Evenimente', 'evenimente-de-proba'],
    [61, 'page', 'ciorna-ascunsa', '/?page_id=61', 'draft', '', '', null],
    [64, 'page', 'oferte_vara', '/oferte_vara/', 'publish', '', 'Oferte de vară', 'oferte_vara'],
    [9000, 'page', 'cookie-policy', '/cookie-policy/', 'publish', '', 'Cookie', 'cookie-policy'],
    [1, 'post', 'salut-lume', '/salut-lume/', 'publish', '', '', null],
    [70, 'project', 'proiect-proba', '/project/proiect-proba/', 'publish', '', '', null],
    [80, 'attachment', 'harta-proba', '/harta-proba/', 'inherit', '2026/01/harta-proba.png', '', null],
    [81, 'attachment', 'scara-proba', '/camere/scara-proba/', 'inherit', '2026/01/scara.jpg', '', null],
    [82, 'attachment', '123', '/acasa/attachment/123/', 'inherit', '2026/01/123.jpg', '', null],
    [1, 'category', 'fara-categorie', '/category/fara-categorie/', 'publish', '', '', null],
    [1, 'author', 'autorul-probei', '/author/autorul-probei/', 'publish', '', '', null],
];
$randuri = [];
$legaturi = [];
foreach ($wp as $n => [$id, $tip, $slugWp, $cale, $stare, $fisier, $titlu, $slugCms]) {
    $randuri[] = ['wp_id' => $id, 'tip' => $tip, 'slug_wp' => $slugWp, 'cale' => $cale, 'stare' => $stare, 'ordine' => $n + 1, 'fisier' => $fisier];
    if ($slugCms !== null) {
        $legaturi[$id] = $adaugaPagina($titlu, $slugCms);
    }
}
// Paginile făcute în constructor după import (în ordinea creării) și pagina 404.
$adaugaPagina('Oferte noi', 'oferte-noi');
$adaugaPagina('Camping', 'camping');
$adaugaPagina('Pagina nu există', '404');
$adaugaPagina('Ciornă din constructor', 'ofertele-ascunse', 0);
$scrise = Harta::scrie($db, $randuri, $legaturi);
$verifica('Harta::scrie: toate rândurile, cu pagina din constructor la postările care au devenit pagini, fișierul ca adresă /wp-content/uploads/',
    $scrise === count($wp) && (int) $db->query("SELECT COUNT(*) FROM wp_harta WHERE pagina_id IS NOT NULL")->fetchColumn() === count($legaturi)
    && $db->query("SELECT fisier FROM wp_harta WHERE wp_id = 81 AND tip = 'attachment'")->fetchColumn() === '/wp-content/uploads/2026/01/scara.jpg');
$verifica('Harta::scrie rescrie harta (reluarea importului nu dublează rânduri)', Harta::scrie($db, $randuri, $legaturi) === count($wp)
    && (int) $db->query('SELECT COUNT(*) FROM wp_harta')->fetchColumn() === count($wp));
$ghicire = [
    '/c/' => '/camere/', '/co/' => '/contact/', '/coo/' => '/cookie-policy/', '/camera/' => '/camere/camera-dubla/',
    '/camere-%C8%99/' => '/camere-%c8%99i-pre%c8%9buri/', '/CAMERE-%C8%98I/' => '/camere-%c8%99i-pre%c8%9buri/',
    '/%CE%B4%CF%89/' => '/%ce%b4%cf%89%ce%bc%ce%ac%cf%84%ce%b9%ce%b1/', '/%CE%94%CE%A9/' => '/%ce%b4%cf%89%ce%bc%ce%ac%cf%84%ce%b9%ce%b1/',
    '/a/' => '/', '/acasa/' => '/', '/s/' => '/salut-lume/', '/pro/' => '/project/proiect-proba/', '/cio/' => null, '/of/' => '/oferte_vara/',
    '/ofe/' => '/oferte_vara/', '/oferte-n/' => '/oferte-noi/', '/cam/' => '/camere/', '/campi/' => '/camping/', '/x/camere/' => '/camere/',
    '/x/y/camere/' => null, '/camere/eveni/' => '/evenimente-de-proba/', '/even/2/' => '/evenimente-de-proba/2/',
    '/evenimente-de-proba/2/' => '/evenimente-de-proba/', '/camere/camera-dubla/3/' => '/camere/camera-dubla/',
    '/salut-lume/2/' => '/salut-lume/', '/even/page/3/' => '/evenimente-de-proba/', '/even/page3/' => '/evenimente-de-proba/',
    '/even/comment-page-2/' => '/evenimente-de-proba/', '/even/1/' => '/evenimente-de-proba/', '/even/feed/' => null,
    '/even/embed/' => null, '/feed/' => null, '/category/camere/' => null, '/2026/' => null, '/2026/01/' => null,
    '/camere-si-preturi/' => null, '/camere.html/' => null, '/404/' => null, '/oferte-noi-2/' => null, '/harta/' => null, '/e/' => '/evenimente-de-proba/',
    '/t/' => '/termeni-si-conditii/', '/Evenimente/' => '/evenimente-de-proba/', '/ofertele/' => null,
];
$gresite = [];
foreach ($ghicire as $cale => $asteptat) {
    $tinta = Ghicire::pentru($db, $cale);
    if ($tinta !== $asteptat) {
        $gresite[] = $cale . ' → ' . var_export($tinta, true) . ', nu ' . var_export($asteptat, true);
    }
}
$verifica('ghicirea pe ' . count($ghicire) . ' căi: cel mai mic ID WordPress, paginile din constructor după, numărul paginii, fără fluxuri, '
    . 'arhive, ciorne, atașamente, 3 segmente, diacritice tăiate sau pagina 404', $gresite === [], implode('; ', $gresite));
// O pagină redenumită în constructor se ghicește după numele nou, pe locul ID-ului ei WordPress; una ștearsă nu mai e țintă.
$db->prepare('UPDATE pages SET slug = ? WHERE id = ?')->execute(['evenimente', $legaturi[60]]);
$db->prepare('UPDATE pages SET deleted_at = NOW() WHERE id = ?')->execute([$legaturi[9000]]);
$verifica('o pagină redenumită: ghicită după slug-ul nou, cu adresa nouă; una ștearsă: nu mai e țintă (nici ?page_id=)',
    Ghicire::pentru($db, '/eveni/') === '/evenimente/' && Ghicire::pentru($db, '/evenimente-de/') === null
    && Ghicire::pentru($db, '/coo/') === null && WpCompat::tintaId($db, 'page_id', 60) === '/evenimente/' && WpCompat::tintaId($db, 'page_id', 9000) === null);
$db->prepare('UPDATE pages SET slug = ? WHERE id = ?')->execute(['evenimente-de-proba', $legaturi[60]]);
$db->prepare('UPDATE pages SET deleted_at = NULL WHERE id = ?')->execute([$legaturi[9000]]);
$verifica('adresele simple din hartă: pagina, articolul, proiectul, categoria, autorul, atașamentul (fișierul); ciorna și un ID necunoscut: nimic',
    WpCompat::tintaId($db, 'page_id', 14) === '/camere/' && WpCompat::tintaId($db, 'page_id', 2) === '/' && WpCompat::tintaId($db, 'p', 1) === '/salut-lume/'
    && WpCompat::tintaId($db, 'p', 70) === '/project/proiect-proba/' && WpCompat::tintaId($db, 'p', 14) === '/camere/'
    && WpCompat::tintaId($db, 'cat', 1) === '/category/fara-categorie/' && WpCompat::tintaId($db, 'author', 1) === '/author/autorul-probei/'
    && WpCompat::tintaId($db, 'attachment_id', 81) === '/wp-content/uploads/2026/01/scara.jpg' && WpCompat::tintaId($db, 'p', 81) === '/wp-content/uploads/2026/01/scara.jpg'
    && WpCompat::tintaId($db, 'page_id', 61) === null && WpCompat::tintaId($db, 'page_id', 999) === null && WpCompat::tintaId($db, 'page_id', 1) === null);
$verifica('atașamentele după adresa lor frumoasă (fără deosebire de litere mari, cu și fără „/” final); o pagină nu e atașament',
    Harta::fisierAtasament($db, '/camere/scara-proba/') === '/wp-content/uploads/2026/01/scara.jpg'
    && Harta::fisierAtasament($db, '/CAMERE/Scara-Proba') === '/wp-content/uploads/2026/01/scara.jpg'
    && Harta::fisierAtasament($db, '/harta-proba/') === '/wp-content/uploads/2026/01/harta-proba.png'
    && Harta::fisierAtasament($db, '/acasa/attachment/123/') === '/wp-content/uploads/2026/01/123.jpg' && Harta::fisierAtasament($db, '/camere/') === null);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== C. Pe server, cu wp-compat.json');
$port = (int) (getenv('CMS_DEV_PORT') ?: 8701) + 21;
$portFara = $port + 1;
if (!$pornesteServer($port, 'cms_wpc', $lucru . '/pachet', $lucru . '/site', $baza)) {
    $verifica('serverul cu wp-compat.json pornește', false, 'vezi ' . $dosarDev . '/server-wpc-' . $port . '.log');
    exit(1);
}
$h = ['Host: ' . $gazda];
$get = static fn (string $cale, array $antete = []): array => $cerere($port, 'GET', $cale, array_merge($h, $antete));
$loc = static fn (array $r): string => (string) ($r['antete']['location'] ?? '');
$canonical = static function (array $r): string {
    return preg_match('/<link rel="canonical" href="([^"]*)"/', $r['corp'], $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES) : '';
};
$redir = static function (string $cale, int $cod, string $tinta, string $metoda = 'GET') use ($cerere, $port, $h, $loc): string {
    $r = $cerere($port, $metoda, $cale, $h);
    return $r['cod'] === $cod && $loc($r) === $tinta ? '' : $cale . ' → ' . $r['cod'] . ' ' . $loc($r) . ' (așteptat ' . $cod . ' ' . $tinta . ')';
};
$probleme = array_filter([
    $redir('/camere', 301, $baza . '/camere/'), $redir('/camere?utm_source=x&b=1', 301, $baza . '/camere/?utm_source=x&b=1'),
    $redir('/camere//', 301, $baza . '/camere/'), $redir('/Camere', 301, $baza . '/Camere/'), $redir('/camere', 301, $baza . '/camere/', 'HEAD'),
    $redir('/camere/camera-dubla', 301, $baza . '/camere/camera-dubla/'),
]);
$r = $get('/camere/');
$verifica('slash-ul final: /x → 301 → /x/ într-un salt, cu query-ul păstrat (și HEAD, și cu majuscule), iar /x/ răspunde 200 cu canonicalul /x/',
    $probleme === [] && $r['cod'] === 200 && $canonical($r) === $baza . '/camere/', implode('; ', $probleme) . ' canonical ' . $canonical($r));
$r1 = $get('/CAMERE/');
$r2 = $get('/Contact/');
$r3 = $get('/contact/');
$verifica('majusculele: /CAMERE/ și /Contact/ răspund 200 cu canonicalul cu litere mici (ca WordPress), /contact/ 200',
    $r1['cod'] === 200 && $canonical($r1) === $baza . '/camere/' && $r2['cod'] === 200 && $canonical($r2) === $baza . '/contact/' && $r3['cod'] === 200,
    $r1['cod'] . ' ' . $canonical($r1) . ' / ' . $r2['cod'] . ' ' . $canonical($r2) . ' / ' . $r3['cod']);
$r1 = $get('/cam%65re/');
$r2 = $get('/CAM%45RE/');
$verifica('un escape ASCII în cale (/cam%65re/, /CAM%45RE/): pagina 200, cu canonicalul din slug-ul ei (/camere/), ca AIOSEO din post_name',
    $r1['cod'] === 200 && $canonical($r1) === $baza . '/camere/' && $r2['cod'] === 200 && $canonical($r2) === $baza . '/camere/',
    $r1['cod'] . ' ' . $canonical($r1) . ' / ' . $r2['cod'] . ' ' . $canonical($r2));
// SPEC-R2-04: căutarea paginii e mai largă decât forma canonică (customPage trim-uiește slug-ul; un „/" codat %2f ajunge la
// pagina imbricată), dar canonicalul vine din slug-ul paginii găsite, nu din calea cerută: WordPress/AIOSEO n-ar scrie
// niciodată /camere%20/ sau /camere%2fcamera-dubla/.
$r1 = $get('/camere%20/');
$r2 = $get('/%20camere/');
$r3 = $get('/camere%09/');
$r4 = $get('/camere%2fcamera-dubla/');
$r5 = $get('/camere%00/');
$verifica('SPEC-R2-04: o cerere mai largă decât slug-ul (spațiu sau tab tăiat de trim, octetul nul scos de router, „/" codat) găsește pagina (200), dar cu canonicalul din slug (/camere/, /camere/camera-dubla/)',
    $r1['cod'] === 200 && $canonical($r1) === $baza . '/camere/' && $r2['cod'] === 200 && $canonical($r2) === $baza . '/camere/'
    && $r3['cod'] === 200 && $canonical($r3) === $baza . '/camere/' && $r4['cod'] === 200 && $canonical($r4) === $baza . '/camere/camera-dubla/'
    && $r5['cod'] === 200 && $canonical($r5) === $baza . '/camere/',
    $r1['cod'] . ' ' . $canonical($r1) . ' / ' . $r2['cod'] . ' ' . $canonical($r2) . ' / ' . $r3['cod'] . ' ' . $canonical($r3) . ' / ' . $r4['cod'] . ' ' . $canonical($r4)
    . ' / ' . $r5['cod'] . ' ' . $canonical($r5));
$r1 = $get('/camere-%C8%99i-pre%C8%9Buri/');
$r2 = $get('/%CE%B4%CF%89%CE%BC%CE%AC%CF%84%CE%B9%CE%B1/');
$r3 = $get('/camere-si-preturi/');
$verifica('slug-urile Unicode: pagina românească și cea grecească 200, cu canonicalul în forma WordPress (escape-uri cu litere mici); fără diacritice: 404 (fără potrivire aproximativă)',
    $r1['cod'] === 200 && $canonical($r1) === $baza . '/camere-%c8%99i-pre%c8%9buri/' && $r2['cod'] === 200
    && $canonical($r2) === $baza . '/%ce%b4%cf%89%ce%bc%ce%ac%cf%84%ce%b9%ce%b1/' && $r3['cod'] === 404,
    $r1['cod'] . ' ' . $canonical($r1) . ' / ' . $r2['cod'] . ' / ' . $r3['cod']);
$probleme = array_filter([$redir('/CAMERE-%C8%98I-PRE%C8%9AURI/', 301, $baza . '/camere-%c8%99i-pre%c8%9buri/')]);
$verifica('majusculele din afara ASCII-ului: nu e aceeași pagină, dar ghicirea (litere mici în nume, ca WordPress) duce la ea', $probleme === [], implode('; ', $probleme));
$probleme = array_filter([
    $redir('/?page_id=14', 301, $baza . '/camere/'), $redir('/?page_id=14&utm_source=x', 301, $baza . '/camere/?utm_source=x'),
    $redir('/?page_id=2', 301, $baza . '/'), $redir('/?p=1', 301, $baza . '/salut-lume/'), $redir('/?p=14', 301, $baza . '/camere/'),
    $redir('/?p=70', 301, $baza . '/project/proiect-proba/'), $redir('/?cat=1', 301, $baza . '/category/fara-categorie/'),
    $redir('/?author=1', 301, $baza . '/author/autorul-probei/'), $redir('/index.php', 301, $baza . '/'),
    $redir('/index.php?page_id=14', 301, $baza . '/camere/'), $redir('/index.php?utm_source=x', 301, $baza . '/?utm_source=x'),
    $redir('/?page_id=21', 301, $baza . '/camere-%c8%99i-pre%c8%9buri/'),
]);
$r1 = $get('/?page_id=61');
$r2 = $get('/?page_id=999');
$r3 = $get('/?utm_source=x');
$verifica('?page_id=, ?p=, ?cat=, ?author=, /index.php → 301 la adresa frumoasă (din wp_harta), cu restul query-ului; o ciornă sau un ID necunoscut: 404; fără ID: prima pagină',
    $probleme === [] && $r1['cod'] === 404 && $r2['cod'] === 404 && $r3['cod'] === 200, implode('; ', $probleme) . ' ' . $r1['cod'] . '/' . $r2['cod'] . '/' . $r3['cod']);
// SPEC-R2-05: pe gazda www (sau cu „//"), 301-ul adresei simple scoate parametrul de ID în același salt (ca redirect_canonical
// din WordPress), nu-l lipește la loc. Restul query-ului rămâne.
$www = ['Host: www.' . $gazda];
$redirWww = static function (array $antete, string $cale, string $tinta) use ($cerere, $port): string {
    $r = $cerere($port, 'GET', $cale, $antete);
    $loc = (string) ($r['antete']['location'] ?? '');
    return $r['cod'] === 301 && $loc === $tinta ? '' : 'Host ' . ($antete[0] ?? '') . ' ' . $cale . ' → ' . $r['cod'] . ' ' . $loc . ' (așteptat 301 ' . $tinta . ')';
};
$probleme = array_filter([
    $redirWww($www, '/?page_id=14', $baza . '/camere/'),
    $redirWww($www, '/?attachment_id=81', $baza . '/wp-content/uploads/2026/01/scara.jpg'),
    $redirWww($www, '/?cat=1', $baza . '/category/fara-categorie/'),
    $redirWww($www, '/?author=1', $baza . '/author/autorul-probei/'),
    $redirWww($www, '/?p=1&utm_source=x', $baza . '/salut-lume/?utm_source=x'),
    $redirWww($h, '//?page_id=14', $baza . '/camere/'),
]);
$verifica('SPEC-R2-05: pe www (sau „//"), 301-ul adresei simple scoate ?page_id=/?p=/?cat=/?author=/?attachment_id= în același salt (nu-l lipește înapoi); restul query-ului rămâne',
    $probleme === [], implode('; ', $probleme));
$fisier = $baza . '/wp-content/uploads/2026/01/';
$probleme = array_filter([
    $redir('/?attachment_id=81', 301, $fisier . 'scara.jpg'), $redir('/camere/scara-proba/', 301, $fisier . 'scara.jpg'),
    $redir('/CAMERE/SCARA-PROBA/', 301, $fisier . 'scara.jpg'), $redir('/harta-proba/', 301, $fisier . 'harta-proba.png'),
    $redir('/acasa/attachment/123/', 301, $fisier . '123.jpg'), $redir('/harta-proba', 301, $fisier . 'harta-proba.png'),
    $redir('/harta-proba/?x=1', 301, $fisier . 'harta-proba.png?x=1'),
]);
$verifica('atașamentele → 301 la fișier: ?attachment_id=, adresa frumoasă (cu părinte, fără, cu „attachment/”), și fără „/” final într-un salt',
    $probleme === [], implode('; ', $probleme));
$probleme = array_filter([
    $redir('/c/', 301, $baza . '/camere/'), $redir('/co/?utm_source=facebook&utm_medium=social', 301, $baza . '/contact/?utm_source=facebook&utm_medium=social'),
    $redir('/even', 301, $baza . '/evenimente-de-proba/'), $redir('/even/2/', 301, $baza . '/evenimente-de-proba/2/'),
    $redir('/evenimente-de-proba/2/', 301, $baza . '/evenimente-de-proba/'), $redir('/acasa/', 301, $baza . '/'),
    $redir('/acasa', 301, $baza . '/'), $redir('/ro/camere/', 301, $baza . '/camere/'), $redir('/Evenimente/', 301, $baza . '/evenimente-de-proba/'),
    $redir('/of/', 301, $baza . '/oferte_vara/'), $redir('/campi/', 301, $baza . '/camping/'), $redir('/produs/came/', 301, $baza . '/camere/'),
]);
$r1 = $get('/x/y/camere/');
$r2 = $get('/even/feed/');
$verifica('ghicirea după rutele reale: 1–2 segmente, query-ul întocmai, un singur salt (și fără „/” final), numărul paginii în două salturi, '
    . 'și sub o rută a unui plugin oprit; 3 segmente și fluxurile: 404', $probleme === [] && $r1['cod'] === 404 && $r2['cod'] === 404,
    implode('; ', $probleme) . ' ' . $r1['cod'] . '/' . $r2['cod']);
$r1 = $get('/nu-exista-deloc/');
$r2 = $get('/404/');
$r3 = $get('/404');
$verifica('404 cu antetele WordPress (Cache-Control nocache, Expires 1984), fără canonical; /404/ nu e o pagină adresabilă',
    $r1['cod'] === 404 && ($r1['antete']['cache-control'] ?? '') === WpCompat::CACHE_CONTROL_404 && ($r1['antete']['expires'] ?? '') === WpCompat::EXPIRES_404
    && $canonical($r1) === '' && $r2['cod'] === 404 && str_contains($r2['corp'], 'Pagina nu există') && $r3['cod'] === 404,
    $r1['cod'] . ' ' . ($r1['antete']['cache-control'] ?? '') . ' ' . ($r1['antete']['expires'] ?? '') . ' / ' . $r2['cod'] . ' / ' . $r3['cod']);
$probleme = array_filter([$redir('/wp-sitemap.xml', 302, $baza . '/sitemap.xml'), $redir('/sitemap_index.xml', 302, $baza . '/sitemap.xml')]);
$verifica('sitemap-urile vechi → 302 /sitemap.xml', $probleme === [], implode('; ', $probleme));
$coduri = [];
foreach ([['GET', '/wp-login.php', []], ['POST', '/wp-login.php', []], ['POST', '/xmlrpc.php', []], ['GET', '/xmlrpc.php', []], ['GET', '/wp-json/', []],
    ['GET', '/wp-json/wp/v2/users', []], ['GET', '/wp-admin/', []], ['GET', '/wp-admin', []], ['GET', '/readme.html', []], ['GET', '/WP-LOGIN.PHP', []],
    ['POST', '/wp-admin/admin-ajax.php', ['action' => 'altceva']]] as [$metoda, $cale, $date]) {
    $coduri[$metoda . ' ' . $cale] = $cerere($port, $metoda, $cale, $h, null, $date)['cod'];
}
$verifica('resturile tehnice WordPress → 410, oricare ar fi metoda', array_unique(array_values($coduri)) === [410], json_encode($coduri));
$r1 = $cerere($port, 'POST', '/wp-admin/admin-ajax.php', $h, null, ['action' => 'galerie_proba']);
$r2 = $get('/wp-json/proba/v1/consimtamant');
$r3 = $get('/wp-content/uploads/nu-exista.png');
$r4 = $cerere($port, 'POST', '/wp-admin//admin-ajax.php', $h, null, ['action' => 'galerie_proba']);
$verifica('endpointurile compatibile nu primesc 410 și nici 301 spre „/x/” (le servesc pașii lor; aici 404, fără canonical, ca orice 404; '
    . 'și cu „//” în cale); fișierele copiate nu primesc 410',
    $r1['cod'] === 404 && $r2['cod'] === 404 && $loc($r2) === '' && $canonical($r2) === '' && $r3['cod'] === 404 && $r4['cod'] === 404,
    $r1['cod'] . '/' . $r2['cod'] . ' ' . $loc($r2) . ' canonical „' . $canonical($r2) . '”/' . $r3['cod'] . '/' . $r4['cod']);
$index = $get('/sitemap.xml');
$corpSitemap = '';
preg_match_all('#<loc>([^<]+)</loc>#', $index['corp'], $m);
foreach ($m[1] as $locSitemap) {
    $corpSitemap .= $get((string) parse_url(html_entity_decode($locSitemap, ENT_QUOTES | ENT_XML1, 'UTF-8'), PHP_URL_PATH))['corp'];
}
$verifica('sitemap-ul generat: adresele paginilor în forma WordPress, cu „/” final și escape-urile cu litere mici; fără ciorne și fără 404',
    $index['cod'] === 200 && str_contains($corpSitemap, '<loc>' . $baza . '/camere/</loc>') && str_contains($corpSitemap, '<loc>' . $baza . '/camere-%c8%99i-pre%c8%9buri/</loc>')
    && str_contains($corpSitemap, '<loc>' . $baza . '/camere/camera-dubla/</loc>') && !str_contains($corpSitemap, '<loc>' . $baza . '/camere</loc>')
    && !str_contains($corpSitemap, '/404') && !str_contains($corpSitemap, 'ofertele-ascunse'), substr($corpSitemap, 0, 300));
$probleme = [];
foreach (['/camere/' => [200, ''], '/CAMERE/' => [200, ''], '/c/' => [301, $baza . '/camere/'], '/camere' => [301, $baza . '/camere/'],
    '/?page_id=14' => [301, $baza . '/camere/'], '/harta-proba/' => [301, $fisier . 'harta-proba.png'], '/nu-exista-deloc/' => [404, ''],
    '/wp-login.php' => [410, ''], '/wp-json/proba/v1/consimtamant' => [404, '']] as $cale => [$cod, $tinta]) {
    $r = $cerere($port, 'HEAD', $cale, $h);
    if ($r['cod'] !== $cod || $loc($r) !== $tinta || ($cod === 404 && ($r['antete']['expires'] ?? '') !== WpCompat::EXPIRES_404)) {
        $probleme[] = 'HEAD ' . $cale . ' → ' . $r['cod'] . ' ' . $loc($r);
    }
}
$verifica('HEAD primește răspunsul unui GET (pagina 200, ghicirea, „/” final, ?page_id=, atașamentul, 404 cu antetele WordPress, 410; '
    . 'endpointul compatibil GET fără 410)', $probleme === [], implode('; ', $probleme));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== D. Adminul, cu wp-compat.json');
$jar = $lucru . '/jar-admin.txt';
// Adminul făcut de scripts/install.php (ADMIN_DEFAULT_EMAIL și parola de test, puse de tools/dev/php.sh).
$adminEmail = (string) getenv('ADMIN_DEFAULT_EMAIL');
$parola = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));
$cerere($port, 'POST', '/admin/login', $h, $jar, ['email' => $adminEmail, 'password' => $parola]);
$editor = $cerere($port, 'GET', '/admin/pages/' . $pagini['camere-și-prețuri'] . '/edit', $h, $jar);
// (Paginile nu cer jetonul AdminCsrf: el e doar pe acțiunile cu bani; sesiunea e SameSite=Lax.)
$lipsa = array_keys(array_filter([
    'codul 200' => $editor['cod'] !== 200,
    'adresa cu „/”' => !str_contains($editor['corp'], 'data-slash-final="1">/camere-și-prețuri/</strong>'),
    'slugify-ul Unicode' => !str_contains($editor['corp'], '/[^\p{L}\p{M}\p{N}-]/gu'),
    'fără slugify-ul vechi' => str_contains($editor['corp'], "/[^a-z0-9\\\\s-]/g"),
]));
$verifica('editorul paginii: adresa cu „/” final, slugify-ul JS cu literele oricărei limbi (\p{L})', $lipsa === [], 'lipsește: ' . implode(', ', $lipsa)
    . (preg_match('/id="page-url-preview"[^<]*<\/strong>/u', $editor['corp'], $mp) === 1 ? ' — ' . $mp[0] : ''));
$salveaza = static function (int $id, string $titlu, string $slug) use ($cerere, $port, $h, $jar): array {
    return $cerere($port, 'POST', '/admin/pages/save', $h, $jar, ['id' => (string) $id, 'title' => $titlu, 'slug' => $slug,
        'html_content' => '<section><p>Text de probă salvat din admin.</p></section>', 'css_content' => '', 'js_content' => '', 'is_published' => '1']);
};
$slugDin = static fn (int $id): string => (string) $db->query('SELECT slug FROM pages WHERE id = ' . $id)->fetchColumn();
$salveaza($pagini['oferte_vara'], 'Oferte de vară', 'oferte_vara');
$salveaza($pagini['δωμάτια'], 'Δωμάτια', 'δωμάτια');
$verifica('un slug neschimbat în formular nu se renormalizează (oferte_vara rămâne cu „_”, δωμάτια rămâne grecesc)',
    $slugDin($pagini['oferte_vara']) === 'oferte_vara' && $slugDin($pagini['δωμάτια']) === 'δωμάτια',
    $slugDin($pagini['oferte_vara']) . ' / ' . $slugDin($pagini['δωμάτια']));
$cerere($port, 'POST', '/admin/pages/save', $h, $jar, ['id' => '0', 'title' => 'Δωμάτια Νέα și Prețuri', 'slug' => '',
    'html_content' => '<section><p>Pagină nouă.</p></section>', 'is_published' => '1']);
$nou = $db->query("SELECT id, slug FROM pages WHERE title = 'Δωμάτια Νέα și Prețuri'")->fetch();
$salveaza($pagini['camping'], 'Camping', 'Camere Și Prețuri');
$mesaj = $cerere($port, 'GET', '/admin/pages/' . $pagini['camping'] . '/edit', $h, $jar);
$verifica('slug-ul din titlu păstrează literele (δωμάτια-νέα-și-prețuri); o adresă luată de altă pagină e refuzată cu adresa și pagina în mesaj',
    is_array($nou) && $nou['slug'] === 'δωμάτια-νέα-și-prețuri' && $slugDin($pagini['camping']) === 'camping'
    && str_contains($mesaj['corp'], 'Adresa /camere-%c8%99i-pre%c8%9buri/ e deja a paginii „Camere și prețuri”'),
    (is_array($nou) ? $nou['slug'] : '-') . ' / ' . $slugDin($pagini['camping']));
$salveaza($pagini['camping'], 'Camping', '/camping-%C8%99i-cort/');
$verifica('un slug lipit din bara browserului (percent-encoded) e decodat', $slugDin($pagini['camping']) === 'camping-și-cort', $slugDin($pagini['camping']));
$salveaza($pagini['oferte-noi'], 'Oferte noi', 'oferte-toamna');
$r = $get('/oferte-noi/');
$probleme = array_filter([$redir('/oferte-noi/', 301, $baza . '/oferte-toamna/'), $redir('/oferte-noi', 301, $baza . '/oferte-toamna/')]);
$regula = $db->query("SELECT from_path, to_url FROM seo_redirects WHERE source = 'slug' ORDER BY id DESC LIMIT 1")->fetch();
$verifica('slug schimbat (WP-38): redirecționarea automată în forma WordPress, /vechi/ → /nou/, într-un salt (și din /vechi)',
    $probleme === [] && is_array($regula) && $regula['to_url'] === '/oferte-toamna/', implode('; ', $probleme) . ' ' . json_encode($regula));
$meniu = $cerere($port, 'GET', '/admin/design?section=menu', $h, $jar);
$verifica('meniul propune paginile în forma WordPress („/camere/”, „/camere-%c8%99i-pre%c8%9buri/”, „/contact/”)',
    $meniu['cod'] === 200 && str_contains($meniu['corp'], 'data-menu-url="/camere/"') && str_contains($meniu['corp'], 'data-menu-url="/camere-%c8%99i-pre%c8%9buri/"')
    && str_contains($meniu['corp'], 'data-menu-url="/contact/"') && !str_contains($meniu['corp'], 'data-menu-url="/camere"'), 'cod ' . $meniu['cod']);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== E. Fără wp-compat.json: ca înainte');
if (!$pornesteServer($portFara, 'cms_wpc', $lucru . '/pachet-fara', $lucru . '/site-fara', $baza)) {
    $verifica('serverul fără wp-compat.json pornește', false, 'vezi ' . $dosarDev . '/server-wpc-' . $portFara . '.log');
    exit(1);
}
$getFara = static fn (string $cale): array => $cerere($portFara, 'GET', $cale, $h);
$probleme = [];
// (Redirecționările controllerului, pe gazda corectă, pleacă relative, ca până acum.)
foreach ([['/camere/', 301, $baza . '/camere'], ['/CAMERE', 301, '/camere'], ['/camere-si-preturi', 301, '/camere-%C8%99i-pre%C8%9Buri'],
    ['/?page_id=14', 200, ''], ['/c', 404, ''], ['/wp-login.php', 404, ''], ['/wp-sitemap.xml', 404, ''], ['/index.php', 404, ''],
    ['/?attachment_id=81', 200, ''], ['/camere/scara-proba', 404, ''], ['/404', 200, '']] as [$cale, $cod, $tinta]) {
    $r = $getFara($cale);
    if ($r['cod'] !== $cod || $loc($r) !== $tinta) {
        $probleme[] = $cale . ' → ' . $r['cod'] . ' ' . $loc($r);
    }
}
$r = $getFara('/nu-exista-deloc');
$r2 = $getFara('/camere');
$verifica('adresele ca înainte: „/” final scos, majusculele și diacriticele tăiate → 301 (potrivirea nucleului), ?page_id= ignorat, fără ghicire, '
    . 'fără 410, fără 302, fără atașamente, 404 fără antetele WordPress, canonicalul fără „/”',
    $probleme === [] && $r['cod'] === 404 && ($r['antete']['expires'] ?? '') !== WpCompat::EXPIRES_404 && $canonical($r2) === $baza . '/camere',
    implode('; ', $probleme) . ' canonical ' . $canonical($r2));
$r1 = $cerere($portFara, 'HEAD', '/camere', $h);
$r2 = $cerere($portFara, 'HEAD', '/camere/', $h);
$verifica('HEAD ca înainte: o adresă corectă 404 (routerul fără rute HEAD, docs/SEO.md §11), una cu „/” final 301 (stratul SEO o cere ca GET)',
    $r1['cod'] === 404 && $r2['cod'] === 301 && $loc($r2) === $baza . '/camere', $r1['cod'] . ' / ' . $r2['cod'] . ' ' . $loc($r2));
$jarFara = $lucru . '/jar-fara.txt';
$cerere($portFara, 'POST', '/admin/login', $h, $jarFara, ['email' => $adminEmail, 'password' => $parola]);
$editor = $cerere($portFara, 'GET', '/admin/pages/' . $pagini['camere'] . '/edit', $h, $jarFara);
$verifica('editorul paginii ca înainte: adresa fără „/” final, slugify-ul JS de până acum',
    $editor['cod'] === 200 && str_contains($editor['corp'], '<strong id="page-url-preview">/camere</strong>')
    && str_contains($editor['corp'], "/[^a-z0-9\\\\s-]/g") && !str_contains($editor['corp'], '\p{L}'), 'cod ' . $editor['cod']);
exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/tools/dev/instantaneu-rute.php') . ' 2>&1', $iesire, $codInstantaneu);
$verifica('instantaneul rutelor (tools/dev/instantaneu-rute.php) neschimbat: nicio rută nouă', $codInstantaneu === 0, implode(' ', array_slice($iesire, -3)));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== F. Cu pluginul catalog: potrivirea aproximativă a produselor doar fără wp-compat.json');
$opresteServerele();
$dosarLicentaCatalog = $lucru . '/licenta-catalog';
@mkdir($dosarLicentaCatalog, 0770, true);
exec('env CMS_DEV_LICENTA_DOSAR=' . escapeshellarg($dosarLicentaCatalog) . ' ' . escapeshellarg($radacina . '/tools/dev/php.sh') . ' '
    . escapeshellarg($radacina . '/tools/dev/licenta.php') . ' catalog 2>&1', $iesireLicenta, $codLicenta);
$db->exec("INSERT INTO products (name, slug, price, stock, is_active) VALUES ('Cort de probă mare', 'cort-proba-mare', 10, 5, 1)");
$pornitCatalog = $codLicenta === 0 && $pornesteServer($port, 'cms_wpc', $lucru . '/pachet', $lucru . '/site-catalog', $baza, $dosarLicentaCatalog)
    && $pornesteServer($portFara, 'cms_wpc', $lucru . '/pachet-fara', $lucru . '/site-catalog-fara', $baza, $dosarLicentaCatalog);
$r1 = $pornitCatalog ? $cerere($port, 'GET', '/produs/cort-proba/', $h) : ['cod' => 0, 'antete' => []];
$r2 = $pornitCatalog ? $cerere($port, 'GET', '/produs/cort-proba-mare/', $h) : ['cod' => 0, 'antete' => []];
$r3 = $pornitCatalog ? $cerere($portFara, 'GET', '/produs/cort-proba', $h) : ['cod' => 0, 'antete' => []];
$verifica('un produs după un slug aproximativ: cu wp-compat.json ghicirea, apoi 404 (fără 301-ul nucleului), produsul exact 200; '
    . 'fără wp-compat.json 301-ul nucleului, ca înainte',
    $pornitCatalog && $r1['cod'] === 404 && $loc($r1) === '' && $r2['cod'] === 200 && $r3['cod'] === 301 && $loc($r3) === '/produs/cort-proba-mare',
    ($pornitCatalog ? '' : 'licența sau serverele nu pornesc (' . implode(' ', array_slice($iesireLicenta, -2)) . '); ') . $r1['cod'] . ' ' . $loc($r1)
    . ' / ' . $r2['cod'] . ' / ' . $r3['cod'] . ' ' . $loc($r3));

$opresteServerele();
$scrie('');
$scrie('Rezultat: ' . $trecute . ' verificări trecute, ' . $esuate . ' eșuate.');
exit($esuate === 0 ? 0 : 1);
