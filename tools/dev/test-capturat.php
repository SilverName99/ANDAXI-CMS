<?php

declare(strict_types=1);

/**
 * Testele cadrului capturat (App\Support\CadruCapturat, App\Support\CspSite, cheile `tema.json` → `cadru`, `runtime`, `csp`;
 * adoptarea WordPress, pasul 6), poarta G-capturat, pe o captură INVENTATĂ („Pensiunea Probă”, tools/dev/lib/captura-divi-proba.php),
 * fără WordPress și fără nicio dată a unui client:
 *
 *  1. documentele tăiate și scrise în proces (Divi4, Divi4Taiere, PlanCapturat, ScriereCapturata, cu P1 la import și scripturile
 *     runtime-ului din manifestul divi4@1) pe o bază nouă (scripts/install.php), cu paginile lor în `pages` și SEO-ul în
 *     `seo_pages`;
 *  2. un server cu `cadru` = `capturat` și `runtime` = `divi4@1`: fiecare pagină = T cu scripturile runtime-ului la sloturile lor,
 *     octet cu octet după normalizarea nonce-urilor (valoarea zilei), cu anul cererii; 404-ul capturat (statusul, antetele lui,
 *     calea cerută în formular și JSON-LD); cardul embed; variantele de antet (prima pagină are alt antet); nicio scurgere a
 *     layout-ului nucleului; un jeton necunoscut dă 500, fără nimic din pagină;
 *  3. CSP-ul (CspSite): aceeași politică la prima cerere, la a doua (cache-ul de pagini) și cu alt `Host`; `nosniff` peste tot;
 *     cardul embed fără `frame-ancestors`; `csp.mod` = `raport` o trimite ca Report-Only; o sursă în plus din `csp`;
 *  4. fără `cadru` (alt pachet, aceeași bază): paginile ies prin layout-ul nucleului, fără CSP și fără nosniff, ca înainte;
 *  5. Tema::valideaza pe cheile noi;
 *  7. răspunsurile înghețate (robots, sitemap-ul) din captură, octet cu octet, cu antetele lor, la GET și HEAD; fără cadru, ale
 *     nucleului;
 *  6. P1 după import (scripts/verifica-randare.php --capturat): la fel cu amprenta aprobată, o editare dă 1 până la --aproba, un
 *     script nepermis în ieșire e NU; `/health` are rândul `capturat:` doar pe cadrul capturat.
 *
 *   tools/dev/php.sh tools/dev/test-capturat.php
 *
 * Baza (cms_capt), serverele (CMS_DEV_PORT+31…+33) și dosarul $CMS_DEV_DIR/test-capturat/ se șterg la final. Raport:
 * $CMS_DEV_DIR/test-capturat-raport.txt; cod 0 doar dacă totul a trecut.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$lucru = $dosarDev . '/test-capturat';
exec('rm -rf ' . escapeshellarg($lucru));
foreach (['site/storage', 'site-fara/storage', 'site-raport/storage', 'pachet/tema', 'pachet-fara', 'pachet-raport/tema', 'licenta'] as $d) {
    @mkdir($lucru . '/' . $d, 0770, true);
}

require_once $radacina . '/bootstrap.php';
require_once $radacina . '/tools/dev/lib/captura-divi-proba.php';

use App\Support\CadruCapturat;
use App\Support\CspSite;
use App\Support\ImportWordPress\Captura\DosarCapturi;
use App\Support\ImportWordPress\Captura\Normalizare;
use App\Support\ImportWordPress\Continut\Divi4;
use App\Support\ImportWordPress\Continut\Divi4Taiere;
use App\Support\ImportWordPress\PlanCapturat;
use App\Support\ImportWordPress\ScriereCapturata;
use App\Support\RaportCsp;
use App\Support\Tema;
use App\Support\Urmarire;

$fisierRaport = $dosarDev . '/test-capturat-raport.txt';
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
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . mb_substr($detalii, 0, 1500) : ''));
    }
};
$scrie('Test cadrul capturat (G-capturat) — ' . date('Y-m-d H:i:s'));

// ---------------------------------------------------------------------------------------------------------------------
// Baza, serverele, cererile
// ---------------------------------------------------------------------------------------------------------------------
$socket = (string) (getenv('CMS_DEV_SOCK') ?: ini_get('pdo_mysql.default_socket'));
$utilizator = (string) (getenv('CMS_DEV_DB_USER') ?: 'root');
$parolaDb = (string) (getenv('CMS_DEV_DB_PAROLA') ?: '');
$root = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', $utilizator, $parolaDb, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$baza = 'cms_capt';
$serverePornite = [];
register_shutdown_function(static function () use (&$serverePornite, $root, $baza, $lucru): void {
    foreach ($serverePornite as $pid) {
        if ($pid > 0) {
            // Doar procesele pornite aici (serverul și procesele lui de lucru).
            exec('pkill -TERM -P ' . (int) $pid . ' 2>/dev/null; kill ' . (int) $pid . ' 2>/dev/null');
            for ($i = 0; $i < 50 && trim((string) shell_exec('pgrep -P ' . (int) $pid . ' 2>/dev/null')) !== ''; $i++) {
                usleep(100000);
            }
        }
    }
    $GLOBALS['db'] = null;
    gc_collect_cycles();
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $baza . '`');
    } catch (Throwable) {
    }
    exec('rm -rf ' . escapeshellarg($lucru));
});

/** @return array{cod: int, antete: array<string, string>, corp: string} */
$cerere = static function (int $port, string $cale, array $antete = []): array {
    $c = curl_init('http://127.0.0.1:' . $port . $cale);
    $primite = [];
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60,
        CURLOPT_PATH_AS_IS => true, CURLOPT_HTTPHEADER => $antete, CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$primite): int {
            if (str_contains($linie, ':')) {
                [$n, $v] = explode(':', $linie, 2);
                $primite[strtolower(trim($n))] = trim($v);
            }
            return strlen($linie);
        }]);
    $corp = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $cod, 'antete' => $primite, 'corp' => $corp];
};
$pornesteServer = static function (int $port, string $pachet, string $dosarSite) use ($radacina, $lucru, $dosarDev, $baza, $cerere, &$serverePornite): bool {
    $preludiu = $dosarSite . '/preludiu.php';
    file_put_contents($preludiu, "<?php\ndefine('PLATFORMA_SITE_DIR', " . var_export($dosarSite, true) . ");\n");
    $ini = ['-d ' . escapeshellarg('auto_prepend_file=' . $preludiu)];
    foreach (['pdo_mysql.default_socket', 'error_reporting', 'display_errors', 'log_errors', 'error_log', 'memory_limit'] as $cheie) {
        $v = ini_get($cheie);
        if ($v !== false && $v !== '') {
            $ini[] = '-d ' . escapeshellarg($cheie . '=' . $v);
        }
    }
    $mediu = ['PHP_CLI_SERVER_WORKERS' => '2', 'DB_DATABASE' => $baza, 'SITE_DATE_DOSAR' => $pachet, 'LICENTA_DOSAR' => $lucru . '/licenta',
        'APP_URL' => 'https://pensiune-proba.example.test', 'APP_KEY' => 'cheie-de-proba-a-cadrului'];
    $env = '';
    foreach ($mediu as $n => $v) {
        $env .= ' ' . $n . '=' . escapeshellarg($v);
    }
    $pid = (int) trim((string) shell_exec('cd ' . escapeshellarg($radacina) . ' && env' . $env . ' nohup ' . escapeshellarg(PHP_BINARY) . ' ' . implode(' ', $ini)
        . ' -S 127.0.0.1:' . $port . ' -t public tools/dev/router.php >> ' . escapeshellarg($dosarDev . '/server-capturat-' . $port . '.log') . ' 2>&1 & echo $!'));
    $serverePornite[$port] = $pid;
    for ($i = 0; $i < 60; $i++) {
        if ($cerere($port, '/health')['cod'] === 200) {
            return true;
        }
        usleep(200000);
    }
    return false;
};

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 1. Documentele tăiate și scrise în proces, pe o bază nouă');
$root->exec('DROP DATABASE IF EXISTS `' . $baza . '`');
$root->exec('CREATE DATABASE `' . $baza . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$p = proc_open([$radacina . '/tools/dev/php.sh', 'scripts/install.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, $radacina,
    array_merge(getenv(), ['CMS_DEV_DB' => $baza, 'CMS_DEV_LICENTA_DOSAR' => $lucru . '/licenta']));
$outI = (string) stream_get_contents($pipe[1]) . (string) stream_get_contents($pipe[2]);
fclose($pipe[1]);
fclose($pipe[2]);
$db = new PDO('mysql:unix_socket=' . $socket . ';dbname=' . $baza . ';charset=utf8mb4', $utilizator, $parolaDb, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$verifica('scripts/install.php: baza cu tabelele capturii', proc_close($p) === 0 && ScriereCapturata::disponibil($db), $outI);
$url = 'https://pensiune-proba.example.test';
$an = date('Y');
$pagini = [
    ['cale' => '/', 'titlu' => 'Acasă', 'id' => 101, 'seoTitlu' => 'Pensiunea Probă: cazare la munte'],
    ['cale' => '/camere/', 'titlu' => 'Camere & prețuri', 'id' => 102, 'seoTitlu' => 'Camere & prețuri - Pensiunea Probă', 'descriere' => 'Camere confortabile.'],
    ['cale' => '/contact/', 'titlu' => 'Contact', 'id' => 103, 'formular' => true, 'seoTitlu' => 'Contact - Pensiunea Probă'],
];
$optiuni = ['url' => $url, 'nonce' => 'cccc111111', 'ua' => 'windows', 'an' => $an, 'antet' => 11, 'subsol' => 12, 'corp404' => 13,
    'meniu' => [['/', 'Acasă'], ['/camere/', 'Camere'], ['/contact/', 'Contact']], 'cale404' => '/x1-inexistent/', 'embed' => ['/camere/'], 'oembed' => ['/', '/contact/']];
capturaDiviProba($lucru . '/captura', $pagini, $optiuni);
// Prima pagină are un antet puțin altfel (o clasă în plus): două variante de antet.
$fisierAcasa = $lucru . '/captura/brut/' . hash('sha256', $url . '/') . '/chrome-win-1.corp';
file_put_contents($fisierAcasa, str_replace('et_pb_section_0_tb_header et_section_regular', 'et_pb_section_0_tb_header et_section_regular proba-acasa',
    (string) file_get_contents($fisierAcasa)));
$dosar = DosarCapturi::deschide($lucru . '/captura');
$aruncate = [];
foreach ($dosar->toate() as $x) {
    $r = $dosar->raspuns($x['url']);
    foreach ($r !== null && str_contains($r->corp, '<!DOCTYPE html>') ? (new Divi4())->transforma($r->corp, $url, [])->necunoscute : [] as $n) {
        if ($n['fel'] !== 'noscript' || str_contains($n['inceput'], 'googletagmanager')) {
            $aruncate[$n['sha256']] = ['sha256' => $n['sha256'], 'motiv' => 'proba: ' . ($n['id'] ?? $n['inceput'])];
        }
    }
}
$reguli = ['continut' => 'capturat', 'dialect' => 'divi4', 'scripturi_aruncate' => array_values($aruncate)];
$runtime = CadruCapturat::scripturiRuntime('divi4@1');
$verifica('scripturile runtime-ului din manifestul divi4@1: sincron, cu ?v=<12 hex>, incarcare.js primul în cap', str_starts_with($runtime['cap'], '<script src="/assets/compat/comun/v1/incarcare.js?v=')
    && preg_match_all('#<script src="/assets/compat/[a-z0-9]+/v1/[a-z0-9-]+\.js\?v=[0-9a-f]{12}"></script>#', $runtime['cap'] . $runtime['final']) >= 3
    && !str_contains($runtime['cap'] . $runtime['final'], 'async') && !str_contains($runtime['cap'] . $runtime['final'], 'defer'), json_encode($runtime));
$pc = new PlanCapturat();
$paginiDoc = [];
$paginiId = [];
$taieri = [];
$anModul = ['modul' => 'et_pb_text_0_tb_footer', 'an' => $an];
foreach ($pagini as $pg) {
    $r = $dosar->dupaCale($pg['cale']);
    $rd = (new Divi4($dosar))->transforma((string) $r?->corp, $url, $reguli, $pg['cale']);
    $seo = ['titlu' => $pg['seoTitlu'], 'descriere' => (string) ($pg['descriere'] ?? ''), 'canonical' => $url . $pg['cale'], 'robots' => '', 'imagine' => ''];
    $t = Divi4Taiere::taie($rd, ['tip' => 'pagina', 'cale' => $pg['cale'], 'seo' => $seo, 'an' => $anModul]);
    $cheie = 'pagina:' . $pg['id'];
    $pc->adaugaDocument($cheie, 'pagina', $t, ['titlu' => $pg['titlu'], 'wp_id' => $pg['id'], 'status' => 200, 'sha_captura' => (string) $r?->sha256(),
        'antete' => PlanCapturat::antete((array) $r?->antete, $url), 'valori_import' => ['cale' => $pg['cale']]]);
    $db->prepare('INSERT INTO pages (title, slug, html_content, is_published) VALUES (?, ?, ?, 1)')->execute([$pg['titlu'], trim($pg['cale'], '/'), $t->continut]);
    $id = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO seo_pages (page_type, page_ref, title, description, canonical_url) VALUES ('custom_page', ?, ?, ?, ?)")
        ->execute([(string) $id, $seo['titlu'], $seo['descriere'] !== '' ? $seo['descriere'] : null, $seo['canonical']]);
    $paginiDoc[$pg['cale']] = $cheie;
    $paginiId[$cheie] = $id;
    $taieri[$pg['cale']] = $t;
}
$pc->dinCaptura($dosar, $reguli, $paginiDoc, [], $anModul);
$pc->grupeaza();
$db->beginTransaction();
try {
    $scris = ScriereCapturata::scrie($db, $pc, $paginiId, $runtime);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    $scris = ['eroare' => $e->getMessage()];
}
// oEmbed-ul primei pagini și al paginii de contact: înghețat la endpointul lui (calea din index e a paginii), nu peste rutele / și /contact.
$oembedInghetate = array_values(array_filter($pc->raspunsuri, static fn (array $x): bool => $x['sursa'] === 'captura:oembed'));
$verifica('oEmbed-ul paginilor / și /contact/: înghețat la /wp-json/oembed/1.0/embed?url=…, cu JSON-ul lui, fără ciocnire cu rutele / și /contact; nimic înghețat la / sau /contact/',
    count($oembedInghetate) === 2 && array_unique(array_column($oembedInghetate, 'cale')) === ['/wp-json/oembed/1.0/embed']
    && array_column($oembedInghetate, 'query') === ['url=' . rawurlencode($url . '/'), 'url=' . rawurlencode($url . '/contact/')]
    && str_contains((string) $oembedInghetate[0]['corp'], '"type":"rich"')
    && array_filter($pc->raspunsuri, static fn (array $x): bool => in_array($x['cale'], ['/', '/contact/', '/contact'], true)) === []
    && array_filter($pc->probleme, static fn (string $t): bool => str_contains($t, 'se ciocnește')) === [], json_encode([$pc->probleme, array_column($pc->raspunsuri, 'cale')], JSON_UNESCAPED_UNICODE));
$verifica('tăierea și scrierea: 5 documente (3 pagini, 404, embed), 2 variante de antet, P1 la import cu runtime-ul', $pc->probleme === [] && ($scris['documente'] ?? 0) === 5
    && count(array_filter($pc->fragmente, static fn (array $f): bool => $f['fel'] === 'antet')) === 2, json_encode([$pc->probleme, $scris], JSON_UNESCAPED_UNICODE));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 2. Serverul cu cadrul capturat: paginile = T cu runtime-ul, 404-ul, embed-ul, variantele, nicio scurgere, 500 pe un jeton necunoscut');
file_put_contents($lucru . '/pachet/tema/tema.json', json_encode(['cadru' => 'capturat', 'runtime' => 'divi4@1', 'csp' => ['img-src' => ['https://poze.example.test/p/']]]));
file_put_contents($lucru . '/pachet-raport/tema/tema.json', json_encode(['cadru' => 'capturat', 'runtime' => 'divi4@1', 'csp' => ['mod' => 'raport']]));
// Adresele ca WordPress (wp-compat.json): slash-ul final, ca în captură (un site mutat de pe WordPress îl are mereu).
foreach (['pachet', 'pachet-fara', 'pachet-raport'] as $pa) {
    file_put_contents($lucru . '/' . $pa . '/redirecturi.json', '{"exacte": [], "prefixe": []}');
    file_put_contents($lucru . '/' . $pa . '/seo.json', '{}');
    // Ghicirea pornită: pe cadrul capturat tot nu ghicește (VC-URL-024).
    file_put_contents($lucru . '/' . $pa . '/wp-compat.json', '{"slash_final": true, "majuscule": "200", "antete_404": "wordpress", "ghicire": true}');
}
$port = (int) (getenv('CMS_DEV_PORT') ?: 8701) + 31;
$verifica('serverul cu cadru = capturat pornește', $pornesteServer($port, $lucru . '/pachet', $lucru . '/site'));
$norm = new Normalizare($url);
// Răspunsul web = T cu runtime-ul, cu foile amânate ale dialectului (data-ax-tarziu) trimise ca foi obișnuite (CadruCapturat::foiTarziiCaFoi).
$asteptatPagina = static fn (string $cale): string => CadruCapturat::foiTarziiCaFoi(ScriereCapturata::asteptat($taieri[$cale], $runtime));
$egale = static function (string $a, string $b) use ($norm): ?int {
    $na = $norm->document($a);
    $nb = $norm->document($b);
    if ($na === $nb) {
        return null;
    }
    $i = 0;
    while ($i < min(strlen($na), strlen($nb)) && $na[$i] === $nb[$i]) {
        $i++;
    }
    return $i;
};
$politica = CspSite::politica(false, ['img-src' => ['https://poze.example.test/p/']]);
$dif = [];
$raspunsuri = [];
foreach (['/', '/camere/'] as $cale) {
    $raspunsuri[$cale] = $r = $cerere($port, $cale);
    if ($r['cod'] !== 200 || ($d = $egale($r['corp'], $asteptatPagina($cale))) !== null) {
        $dif[] = $cale . ' (cod ' . $r['cod'] . ', prima diferență la ' . ($d ?? '-') . ': „' . substr($norm->document($r['corp']), max(0, (int) $d - 40), 120) . '”)';
    }
}
$verifica('paginile = T cu scripturile runtime-ului la sloturi (octet cu octet, cu nonce-urile normalizate)', $dif === [], implode('; ', $dif));
$tarziuT = (string) (preg_match('/<link[^>]*proba-tarziu-css[^>]*>/', ScriereCapturata::asteptat($taieri['/'], $runtime), $mt) === 1 ? $mt[0] : '');
$tarziuServit = (string) (preg_match('/<link[^>]*proba-tarziu-css[^>]*>/', $raspunsuri['/']['corp'], $ms) === 1 ? $ms[0] : '');
$verifica('foaia amânată a dialectului (în T: <link rel="preload" as="style" … data-ax-tarziu>) pleacă la răspuns ca <link rel="stylesheet">, pe loc, '
    . 'fără as și fără data-ax-tarziu (aspectul nu depinde de un script); niciun alt link schimbat',
    str_contains($tarziuT, 'data-ax-tarziu') && str_contains($tarziuT, "rel='preload'") && str_contains($tarziuServit, "rel='stylesheet'")
    && !str_contains($tarziuServit, 'data-ax-tarziu') && !str_contains($tarziuServit, " as='style'") && !str_contains($raspunsuri['/']['corp'], 'data-ax-tarziu')
    && CadruCapturat::foiTarziiCaFoi("<head><link rel='preload' as='font' href='/f.woff2' data-ax-tarziu></head><body><p>data-ax-tarziu</p></body>")
        === "<head><link rel='preload' as='font' href='/f.woff2' data-ax-tarziu></head><body><p>data-ax-tarziu</p></body>", $tarziuT . " → " . $tarziuServit);
$camere = $raspunsuri['/camere/']['corp'];
$verifica('valorile cererii: nonce-ul legat de formular (calea, numărul) în câmpul formularului, al zilei în ax-date, anul de azi, niciun jeton rămas',
    !str_contains($camere, '{{ax:') && substr_count($camere, 'value="' . CadruCapturat::nonceFormular('/camere/', 0, null, 'cheie-de-proba-a-cadrului') . '"') === 1
    && !str_contains($camere, 'value="' . CadruCapturat::nonceZi(null, 'cheie-de-proba-a-cadrului') . '"') && str_contains($camere, '© ' . $an . ' ' . WP_PROBA_SITE)
    && str_contains($camere, '"et_frontend_nonce":"' . CadruCapturat::nonceZi(null, 'cheie-de-proba-a-cadrului') . '"'));
$verifica('variantele de antet: prima pagină cu antetul ei, celelalte cu al lor', str_contains($raspunsuri['/']['corp'], 'proba-acasa') && !str_contains($camere, 'proba-acasa'));
$verifica('nicio scurgere a layout-ului nucleului (app.css, site-shell, bannerul și scripturile nucleului)', !str_contains($camere, '/assets/css/app.css')
    && !str_contains($camere, 'site-shell') && !str_contains($camere, 'cookie-banner') && !str_contains($camere, '/assets/js/'), '');
$r404 = $cerere($port, '/nu-exista/');
$e404 = CadruCapturat::foiTarziiCaFoi(ScriereCapturata::randeazaDinBaza($db, '404', $runtime, ['cale' => '/nu-exista/', 'nonce' => 'abcdef0123', 'an' => $an]));
$d404 = $egale($r404['corp'], $e404);
$verifica('404-ul capturat: status 404, calea cerută în formular și JSON-LD, antetele capturii', $r404['cod'] === 404 && $d404 === null
    && str_contains($r404['corp'], 'value="/nu-exista/"') && str_contains($r404['corp'], '\/nu-exista\/') && str_starts_with($r404['antete']['content-type'] ?? '', 'text/html'),
    $r404['cod'] . ' diferența la ' . ($d404 ?? '-') . ': „' . substr($norm->document($r404['corp']), max(0, (int) $d404 - 60), 160) . '” față de „'
    . substr($norm->document($e404), max(0, (int) $d404 - 60), 160) . '” ' . json_encode($r404['antete']));
$rEmbed = $cerere($port, '/camere/embed/');
$verifica('cardul embed: 200 din documentul lui, foaia pe media="all", fără frame-ancestors în CSP', $rEmbed['cod'] === 200 && str_contains($rEmbed['corp'], 'media="all"')
    && !str_contains($rEmbed['corp'], 'onload') && !str_contains((string) ($rEmbed['antete']['content-security-policy'] ?? ''), 'frame-ancestors')
    && str_contains((string) ($rEmbed['antete']['content-security-policy'] ?? ''), "script-src 'self' 'inline-speculation-rules'"), $rEmbed['cod'] . ' ' . json_encode($rEmbed['antete']));
// Fonturile de iconițe din foile furnizorilor (Swiper: „swiper-icons” ca data:application/font-woff), ca pe site-ul live.
$verifica('CSP: font-src permite data: (fonturile de iconițe ale foilor furnizorilor), iar script-src nu', str_contains(CspSite::politica(), "font-src 'self' data:")
    && !preg_match("/script-src[^;]*data:/", CspSite::politica()) && !preg_match("/default-src[^;]*data:/", CspSite::politica()));
// Trackerele (Urmarire): declarate în pachet, id-urile în privat; doar cele active lărgesc CSP-ul și ajung în ax-date.
$declUrm = [
    ['fel' => 'gtag', 'categorie' => 'statistics', 'src' => 'https://www.googletagmanager.com/gtag/js?id={id}',
        'csp' => ['script-src' => ['https://www.googletagmanager.com'], 'connect-src' => ['https://region1.google-analytics.com']],
        'cookie_retragere' => ['_ga', '_ga_*'], 'parametri' => ['googlesitekit_post_type' => 'page'], 'seturi' => ['developer_id.proba' => true]],
    ['fel' => 'clarity', 'categorie' => 'statistics', 'src' => 'https://www.clarity.ms/tag/{id}?ref=wordpress',
        'csp' => ['script-src' => ['https://www.clarity.ms'], 'connect-src' => ['https://*.clarity.ms']],
        'csp_motiv' => ['https://*.clarity.ms' => 'subdomeniul de colectare e ales dinamic pe proiect'], 'cookie_retragere' => ['_clck']],
];
[$vu, $pu] = Urmarire::valideaza($declUrm);
$verifica('urmarire: declarația bună trece (gtag cu parametri și seturi, clarity cu *.clarity.ms motivată)', count($vu) === 2 && $pu === [], implode('; ', $pu));
$rele = [
    'fel necunoscut' => ['fel' => 'pixel'] + $declUrm[0],
    'script-src cu *.' => array_replace($declUrm[1], ['csp' => ['script-src' => ['https://*.clarity.ms']]]),
    '*. fără motiv' => array_replace($declUrm[1], ['csp_motiv' => []]),
    'motiv prea scurt' => array_replace($declUrm[1], ['csp_motiv' => ['https://*.clarity.ms' => 'dinamic']]),
    'gazda src lipsă din script-src' => array_replace($declUrm[0], ['src' => 'https://alt.example.test/g.js?id={id}']),
    'src pe http' => array_replace($declUrm[0], ['src' => 'http://www.googletagmanager.com/gtag/js?id={id}']),
    'src fără {id}' => array_replace($declUrm[0], ['src' => 'https://www.googletagmanager.com/gtag/js']),
    'sursă cu cale' => array_replace($declUrm[0], ['csp' => ['script-src' => ['https://www.googletagmanager.com/gtag/']]]),
    'sursă care închide directiva' => array_replace($declUrm[0], ['csp' => ['script-src' => ["https://www.googletagmanager.com; script-src 'unsafe-inline'"]]]),
    'directivă nepermisă' => array_replace($declUrm[0], ['csp' => ['default-src' => ['https://www.googletagmanager.com']]]),
    'parametri la clarity' => $declUrm[1] + ['parametri' => ['x' => 'y']],
    'valoare cu cod în seturi' => array_replace($declUrm[0], ['seturi' => ['x' => '<script>']]),
    'cookie cu ;' => array_replace($declUrm[0], ['cookie_retragere' => ['_ga;path=/']]),
    'cheie necunoscută' => $declUrm[0] + ['inline' => 'x'],
];
$scapate = [];
foreach ($rele as $nume => $t) {
    [$v, $p] = Urmarire::valideaza([$t]);
    if ($v !== [] || $p === []) {
        $scapate[] = $nume;
    }
}
[$vd, $pd] = Urmarire::valideaza([$declUrm[0], $declUrm[0]]);
$verifica('urmarire: fiecare declarație greșită e scoasă, cu problema ei (și felul dublat)', $scapate === [] && count($vd) === 1 && count($pd) === 1, implode(', ', $scapate));
$activeU = Urmarire::active($vu, ['gtag' => 'GT-PROBA123', 'clarity' => 'id rău"']);
$verifica('urmarire: activ doar trackerul cu id valid în privat; adresa cu id-ul', count($activeU) === 1 && $activeU[0]['adresa'] === 'https://www.googletagmanager.com/gtag/js?id=GT-PROBA123'
    && Urmarire::active($vu, []) === [] && Urmarire::date([]) === null);
$politicaU = CspSite::politica(false, [], Urmarire::csp(Urmarire::active($vu, ['gtag' => 'GT-PROBA123', 'clarity' => 'proba6789'])));
$verifica('CSP cu trackere active: gazdele lor exacte în script-src și connect-src (*.clarity.ms doar în connect-src); fără trackere active: politica strictă, neschimbată',
    str_contains($politicaU, "script-src 'self' 'inline-speculation-rules' https://www.googletagmanager.com https://www.clarity.ms;")
    && str_contains($politicaU, "connect-src 'self' https://region1.google-analytics.com https://*.clarity.ms;")
    && CspSite::politica(false, [], Urmarire::csp([])) === CspSite::politica() && CspSite::politica(false, [], []) === CspSite::politica(), $politicaU);
$verifica('CSP: o sursă a trackerelor care închide directiva, sau *. în script-src, e ignorată (apărare în adâncime)',
    CspSite::politica(false, [], ['script-src' => ["https://a.test; script-src *", 'https://*.b.test'], 'connect-src' => ['https://*.b.test']])
        === str_replace("connect-src 'self'", "connect-src 'self' https://*.b.test", CspSite::politica()));
$dateU = Urmarire::date(Urmarire::active($vu, ['gtag' => 'GT-PROBA123']));
$htmlU = CadruCapturat::randeaza(['html_atribute' => '', 'cap_html' => '<script type="application/json" id="ax-date">{{ax:date}}</script>', 'body_atribute' => '',
    'inainte' => '', 'dupa' => '</body>', 'date_pagina' => (object) ['file' => []], 'date_reteta' => ['file' => ['lista', ['pagina']]]], '', [],
    ['cale' => '/', 'nonce' => 'abcdef0123', 'an' => '2026', 'urmarire' => $dateU]);
$verifica('ax-date: ax_urmarire pus la randare (felul, categoria, id-ul, adresa, parametrii); fără trackere, cheia lipsește',
    str_contains($htmlU, '"ax_urmarire":[{"fel":"gtag","categorie":"statistics","id":"GT-PROBA123","src":"https://www.googletagmanager.com/gtag/js?id=GT-PROBA123"')
    && str_contains($htmlU, '"parametri":{"googlesitekit_post_type":"page"}')
    && !str_contains(CadruCapturat::randeaza(['html_atribute' => '', 'cap_html' => '<script type="application/json" id="ax-date">{{ax:date}}</script>', 'body_atribute' => '',
        'inainte' => '', 'dupa' => '</body>', 'date_pagina' => (object) ['file' => []], 'date_reteta' => ['file' => ['lista', ['pagina']]]], '', [],
        ['cale' => '/', 'nonce' => 'abcdef0123', 'an' => '2026', 'urmarire' => []]), 'ax_urmarire'), substr($htmlU, 0, 400));
$verifica('import: cheia ax_urmarire nu e o cheie a capturii (importul nu o poate scrie)', !in_array(Urmarire::CHEIE_DATE, \App\Support\HtmlCapturat\SchemaDivi4::CHEI_AX_DATE, true));
$cuManager = '<body><div id="cmplz-manage-consent-container" class="cmplz-manage-consent-container"></div></body>';
$verifica('șablonul managerului: bannerul într-un <template> înainte de </body>, doar pe pagina cu containerul; niciodată cu „</template” în el',
    CadruCapturat::puneSablonManager($cuManager, '<div id="cmplz-cookiebanner-container">B</div>')
        === '<body><div id="cmplz-manage-consent-container" class="cmplz-manage-consent-container"></div><template id="ax-cmplz-sablon"><div id="cmplz-cookiebanner-container">B</div></template></body>'
    && CadruCapturat::puneSablonManager('<body><p>x</p></body>', 'B') === '<body><p>x</p></body>'
    && CadruCapturat::puneSablonManager($cuManager, '</template><script>x</script>') === $cuManager && CadruCapturat::puneSablonManager($cuManager, '') === $cuManager);
[$tv, $tp] = Tema::valideaza(json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/site-uri/pensiune/tema/tema.json'), true), dirname(__DIR__, 2) . '/site-uri/pensiune');
$verifica('pachetul pensiune: trackerele declarate (gtag, gtm, clarity) trec de verificare, fără identificatoare în pachet', $tp === [] && array_column($tv['urmarire'], 'fel') === ['gtag', 'gtm', 'clarity']
    && !preg_match('/\b(?:GT|GTM|G)-[A-Z0-9]{6,}\b/', (string) file_get_contents(dirname(__DIR__, 2) . '/site-uri/pensiune/tema/tema.json')), implode('; ', $tp));

$db->exec("UPDATE pagini_capturate SET dupa = CONCAT(dupa, '{{ax:necunoscut}}') WHERE cheie = 'pagina:103'");
$r500 = $cerere($port, '/contact/');
$verifica('un jeton necunoscut în document: 500, fără nimic din pagină', $r500['cod'] === 500 && !str_contains($r500['corp'], 'et_pb_section') && !str_contains($r500['corp'], '{{ax:'),
    $r500['cod'] . ' ' . substr($r500['corp'], 0, 200));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 3. CSP-ul site-ului: același la fiecare cerere și pe orice gazdă; nosniff; Report-Only pe copia de verificare');
$r2 = $cerere($port, '/camere/');
$rGazda = $cerere($port, '/camere/', ['Host: alt.example.test']);
$verifica('politica: aceeași la prima cerere, la a doua și cu alt Host, cu sursa în plus din tema.json', ($raspunsuri['/camere/']['antete']['content-security-policy'] ?? '') === $politica
    && ($r2['antete']['content-security-policy'] ?? '') === $politica && ($rGazda['antete']['content-security-policy'] ?? '') === $politica
    && str_contains($politica, 'img-src \'self\' data: https://poze.example.test/p/') && str_contains($politica, "frame-ancestors 'self'")
    && !str_contains($politica, 'unsafe-eval') && !preg_match("/script-src[^;]*'unsafe-inline'/", $politica), $politica . ' | ' . json_encode($r2['antete']));
$verifica('nosniff pe pagini, pe 404, pe embed și pe /health', ($raspunsuri['/camere/']['antete']['x-content-type-options'] ?? '') === 'nosniff'
    && ($r404['antete']['x-content-type-options'] ?? '') === 'nosniff' && ($rEmbed['antete']['x-content-type-options'] ?? '') === 'nosniff'
    && ($cerere($port, '/health')['antete']['x-content-type-options'] ?? '') === 'nosniff');
// Recenzia 2, R2-2: politica site-ului doar pe cadrul capturat; adminul, logarea și celelalte pagini ale CMS-ului își rulează scripturile.
$fara22 = [];
foreach (['/admin/login', '/admin', '/admin/pages/1/edit', '/health'] as $c22) {
    if (isset($cerere($port, $c22)['antete']['content-security-policy'])) {
        $fara22[] = $c22;
    }
}
$verifica('CSP-ul site-ului nu pleacă pe paginile CMS-ului (adminul, logarea, /health, API-ul); pleacă pe pagini, pe 404 și pe embed',
    $fara22 === [] && isset($raspunsuri['/camere/']['antete']['content-security-policy']) && isset($r404['antete']['content-security-policy'])
    && isset($rEmbed['antete']['content-security-policy']), implode(', ', $fara22));
// Recenzia 3, R2-N1: fiecare tipar de rută GET înregistrat, cerut pe cadrul capturat (parametrii cu o valoare de probă): orice
// răspuns al cărui corp e un document capturat (ax-date) are politica site-ului; adminul n-o are.
$ruter = new \App\Http\Router();
(require $radacina . '/src/Http/rute.php')($ruter);
$faraCsp = [];
$admineCuCsp = [];
$verificate = 0;
foreach ($ruter->rute() as $ruta) {
    if (strtoupper((string) ($ruta['metoda'] ?? $ruta['method'] ?? 'GET')) !== 'GET') {
        continue;
    }
    $cale = (string) preg_replace('/\{[^}]*\*\}/', 'proba/x', (string) ($ruta['tipar'] ?? $ruta['pattern'] ?? ''));
    $cale = (string) preg_replace('/\{[^}]+\}/', '1', $cale);
    if ($cale === '' || $cale[0] !== '/') {
        continue;
    }
    $r = $cerere($port, $cale);
    $verificate++;
    $esteDoc = str_contains($r['corp'], 'id="ax-date"');
    if ($esteDoc && !isset($r['antete']['content-security-policy'])) {
        $faraCsp[] = $cale . ' (' . $r['cod'] . ')';
    }
    if (str_starts_with($cale, '/admin') && !$esteDoc && isset($r['antete']['content-security-policy'])) {
        $admineCuCsp[] = $cale;
    }
}
$verifica('fiecare rută GET (' . $verificate . ' de tipare): un document capturat are mereu CSP-ul site-ului (și pe căile rutelor CMS-ului fără răspuns); adminul, niciodată',
    $verificate > 50 && $faraCsp === [] && $admineCuCsp === [], 'fără CSP: ' . implode(', ', array_slice($faraCsp, 0, 8)) . ' | admin cu CSP: ' . implode(', ', array_slice($admineCuCsp, 0, 5)));
// Recenzia 2, R2-2, în Chromium: editorul unei pagini și galeria din admin, după logare, fără nicio încălcare CSP.
$idPagina = (int) $db->query('SELECT id FROM pages ORDER BY id LIMIT 1')->fetchColumn();
$envNode = ['CMS_DEV_URL=http://127.0.0.1:' . $port, 'CMS_DEV_ADMIN_EMAIL=' . (string) getenv('ADMIN_DEFAULT_EMAIL'), 'CMS_DEV_PAROLA=' . (string) getenv('ADMIN_DEFAULT_PASSWORD'),
    'CMS_DEV_PAGINA=' . $idPagina, 'PLAYWRIGHT_BROWSERS_PATH=' . (getenv('PLAYWRIGHT_BROWSERS_PATH') ?: '/opt/pw-browsers'),
    'NODE_PATH=' . (getenv('NODE_PATH') ?: '/opt/node22/lib/node_modules'), 'NO_PROXY=*', 'no_proxy=*'];
$iesireNode = [];
exec('env -u http_proxy -u https_proxy -u HTTP_PROXY -u HTTPS_PROXY -u ALL_PROXY -u all_proxy ' . implode(' ', array_map('escapeshellarg', $envNode))
    . ' node ' . escapeshellarg(__DIR__ . '/capturat-admin-csp.cjs') . ' 2>&1', $iesireNode, $codNode);
$rezNode = json_decode((string) end($iesireNode), true);
$adminOk = is_array($rezNode) && count($rezNode) === 2;
foreach (is_array($rezNode) ? $rezNode : [] as $x) {
    $adminOk = $adminOk && ($x['logat'] ?? false) && ($x['status'] ?? 0) === 200 && ($x['incalcari'] ?? 1) === 0 && ($x['csp'] ?? 'x') === '' && ($x['inline'] ?? 0) > 0;
}
$verifica('Chromium: editorul paginii și galeria din admin, pe cadrul capturat, rulează scripturile inline (0 încălcări CSP, fără politica site-ului)',
    $adminOk, implode("\n", array_slice($iesireNode, -3)));
$portR = $port + 2;
$verifica('serverul cu csp.mod = raport pornește', $pornesteServer($portR, $lucru . '/pachet-raport', $lucru . '/site-raport'));
$rR = $cerere($portR, '/camere/');
$verifica('csp.mod = raport: Content-Security-Policy-Report-Only, nu politica aplicată', isset($rR['antete']['content-security-policy-report-only'])
    && !isset($rR['antete']['content-security-policy']) && $rR['cod'] === 200, json_encode($rR['antete']));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 4. Fără cadru: paginile ies prin layout-ul nucleului, fără CSP, ca înainte');
$portF = $port + 1;
$verifica('serverul fără tema.json pornește', $pornesteServer($portF, $lucru . '/pachet-fara', $lucru . '/site-fara'));
$rF = $cerere($portF, '/camere/');
$verifica('fără cadru: /camere/ prin layout-ul nucleului, fără CSP și fără nosniff', $rF['cod'] === 200 && str_contains($rF['corp'], 'site-container')
    && !isset($rF['antete']['content-security-policy']) && !isset($rF['antete']['x-content-type-options']), $rF['cod'] . ' ' . json_encode($rF['antete']));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 6. P1 după import (scripts/verifica-randare.php --capturat), /health');
// Docroot-ul e cel al codului (public/, cu runtime-ul compat), ca pe serverul testului.
$p1 = static function (array $arg) use ($radacina, $lucru, $baza): array {
    $p = proc_open(array_merge([$radacina . '/tools/dev/php.sh', 'scripts/verifica-randare.php', '--capturat'], $arg),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, $radacina, array_merge(getenv(), ['CMS_DEV_DB' => $baza, 'SITE_DATE_DOSAR' => $lucru . '/pachet']));
    $out = (string) stream_get_contents($pipe[1]) . (string) stream_get_contents($pipe[2]);
    fclose($pipe[1]);
    fclose($pipe[2]);
    return [proc_close($p), $out];
};
[$c1, $o1] = $p1([]);
$verifica('cu un jeton necunoscut în document: cod 1, documentul nu se randează', $c1 === 1 && str_contains($o1, 'pagina:103: nu se randează'), $o1);
$db->exec("UPDATE pagini_capturate SET dupa = REPLACE(dupa, '{{ax:necunoscut}}', '') WHERE cheie = 'pagina:103'");
[$c2, $o2] = $p1([]);
$verifica('fără nicio schimbare: cod 0, fiecare document la fel ca amprenta aprobată la import', $c2 === 0 && substr_count($o2, 'la fel') === 5, $o2);
$db->exec("UPDATE pagini_capturate SET cap_html = REPLACE(cap_html, '<title>', '<title>Editat: ') WHERE cheie = 'pagina:102'");
[$c3, $o3] = $p1([]);
[$c4, $o4] = $p1(['--aproba=' . $paginiId['pagina:102']]);
[$c5, $o5] = $p1([]);
$verifica('o editare: cod 1 (diferă de amprenta aprobată); --aproba=<pagina> o aprobă; apoi cod 0', $c3 === 1 && str_contains($o3, 'pagina:102: randarea')
    && $c4 === 0 && str_contains($o4, 'aprobat   pagina:102') && $c5 === 0, $o3 . $o4 . $o5);
$db->exec("UPDATE pagini_capturate SET dupa = CONCAT('<script>alert(1)</script>', dupa) WHERE cheie = 'pagina:101'");
[$c6, $o6] = $p1(['--aproba-toate']);
$verifica('un script nepermis în ieșire: NU și la aprobare (cod 1)', $c6 === 1 && str_contains($o6, 'pagina:101: script nepermis în ieșire: <script>'), $o6);
$verifica('/health pe cadrul capturat: rândul „capturat:” (manifestul capturii, sau „lipsa”)', preg_match('/^capturat: (?:[0-9a-f]{12}|lipsa)$/m', $cerere($port, '/health')['corp']) === 1
    && !str_contains($cerere($portF, '/health')['corp'], 'capturat:'));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 7. Răspunsurile înghețate (pasul 9a): robots, sitemap-ul, din captură, înaintea rutelor nucleului');
$robots = $cerere($port, '/robots.txt');
$robotsCapturat = (string) file_get_contents($lucru . '/captura/brut/' . hash('sha256', $url . '/robots.txt') . '/chrome-win-1.corp');
$verifica('/robots.txt: corpul capturat, octet cu octet, cu tipul lui; fără content-encoding', $robots['cod'] === 200 && $robots['corp'] === $robotsCapturat
    && str_starts_with($robots['antete']['content-type'] ?? '', 'text/plain') && !isset($robots['antete']['content-encoding'])
    && ($robots['antete']['x-content-type-options'] ?? '') === 'nosniff', json_encode($robots['antete']) . ' ' . $robots['corp']);
$harta = $cerere($port, '/sitemap.xml');
$verifica('răspunsurile înghețate au CSP-ul site-ului și nicio sesiune (fără Set-Cookie; recenzia 2, R2-2 și R2-5)',
    isset($robots['antete']['content-security-policy']) && !isset($robots['antete']['set-cookie']));
$verifica('Location: doar o cale strict relativă (recenzia 2, R2-4); TAB, CR, LF, spațiu, „//”, „/\\”, „\\” refuzate',
    \App\Support\RaspunsuriInghetate::locatieSigura('/camere/') && \App\Support\RaspunsuriInghetate::locatieSigura('/?p=1')
    && !\App\Support\RaspunsuriInghetate::locatieSigura("/\t/rau.example/x") && !\App\Support\RaspunsuriInghetate::locatieSigura('//rau.example/x')
    && !\App\Support\RaspunsuriInghetate::locatieSigura('/\\rau.example/x') && !\App\Support\RaspunsuriInghetate::locatieSigura("/a\r\nSet-Cookie: x")
    && !\App\Support\RaspunsuriInghetate::locatieSigura('/a b') && !\App\Support\RaspunsuriInghetate::locatieSigura('https://rau.example/'));
$verifica('un răspuns înghețat pe o cale a CMS-ului (admin, login, health, API) e o ciocnire (NU la import, sărit la servire); robots, sitemap-urile, fluxurile, oEmbed nu',
    \App\Support\RaspunsuriInghetate::coliziune('/admin') !== null && \App\Support\RaspunsuriInghetate::coliziune('/admin/login') !== null
    && \App\Support\RaspunsuriInghetate::coliziune('/health') !== null && \App\Support\RaspunsuriInghetate::coliziune('/assets/x.css') !== null
    && \App\Support\RaspunsuriInghetate::coliziune('/robots.txt') === null && \App\Support\RaspunsuriInghetate::coliziune('/page-sitemap.xml') === null
    && \App\Support\RaspunsuriInghetate::coliziune('/feed/') === null && \App\Support\RaspunsuriInghetate::coliziune('/wp-json/oembed/1.0/embed') === null
    && \App\Support\RaspunsuriInghetate::coliziune('/camere/') === null);
$verifica('/sitemap.xml: cel capturat (cu adresele site-ului), nu sitemap-ul generat', $harta['cod'] === 200 && str_contains($harta['corp'], '<loc>' . $url . '/camere/</loc>')
    && str_starts_with($harta['antete']['content-type'] ?? '', 'text/xml'), substr($harta['corp'], 0, 200));
$c = curl_init('http://127.0.0.1:' . $port . '/robots.txt');
curl_setopt_array($c, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '']);
$corpHead = (string) curl_exec($c);
$codHead = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
curl_close($c);
$verifica('HEAD /robots.txt: 200, fără corp', $codHead === 200 && $corpHead === '');
$robotsF = $cerere($portF, '/robots.txt');
$verifica('fără cadru: /robots.txt e al nucleului, nu cel capturat', $robotsF['cod'] === 200 && $robotsF['corp'] !== $robotsCapturat);
// VC-URL-024: niciun flux neînghețat și nicio ghicire pe cadrul capturat: 404-ul capturat, fără redirecționare.
$fara024 = [];
foreach (['/evenimente/feed/', '/camere/feed/', '/feed/rss2/', '/cam/', '/au/', '/h/'] as $c024) {
    $r024 = $cerere($port, $c024);
    if ($r024['cod'] !== 404 || isset($r024['antete']['location'])) {
        $fara024[] = $c024 . ' → ' . $r024['cod'] . ' ' . ($r024['antete']['location'] ?? '');
    }
}
$ghicitFara = $cerere($portF, '/cam/');
$verifica('VC-URL-024: fluxurile neînghețate și ghicirile pe prefix (/cam/, /au/, /h/) dau 404, fără Location; fără cadru, ghicirea merge ca înainte',
    $fara024 === [] && $ghicitFara['cod'] === 301 && str_ends_with($ghicitFara['antete']['location'] ?? '', '/camere/'), implode('; ', $fara024) . ' | ' . $ghicitFara['cod']);
// Fișierele de la rădăcină copiate de captură sub capturat/radacina/ (docroot-ul serverului de test e public/ al codului).
$dosarRad = $radacina . '/public/capturat/radacina';
$radNou = !is_dir($radacina . '/public/capturat');
register_shutdown_function(static function () use ($radacina, $radNou): void {
    if ($radNou) {
        exec('rm -rf ' . escapeshellarg($radacina . '/public/capturat'));
    }
});
@mkdir($dosarRad . '/wp-admin/images', 0755, true);
@mkdir($dosarRad . '/assets', 0755, true);
file_put_contents($dosarRad . '/favicon.ico', "\x00\x00\x01\x00proba");
file_put_contents($dosarRad . '/logo-proba.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>');
file_put_contents($dosarRad . '/wp-admin/images/proba.png', "\x89PNG\r\n\x1a\nproba");
file_put_contents($dosarRad . '/assets/ciocnire-proba.png', "\x89PNG\r\n\x1a\nciocnire");
file_put_contents($dosarRad . '/.ascuns.png', "\x89PNG\r\n\x1a\nascuns");
file_put_contents($dosarRad . '/scriptul.js', 'alert(1)');
$ico = $cerere($port, '/favicon.ico');
$svg = $cerere($port, '/logo-proba.svg');
$png = $cerere($port, '/wp-admin/images/proba.png');
$verifica('capturat/radacina: /favicon.ico și o poză sub wp-admin/images la adresa lor, octet cu octet, cu tipul fix și nosniff',
    $ico['cod'] === 200 && $ico['corp'] === "\x00\x00\x01\x00proba" && ($ico['antete']['content-type'] ?? '') === 'image/x-icon'
    && ($ico['antete']['x-content-type-options'] ?? '') === 'nosniff' && $png['cod'] === 200 && ($png['antete']['content-type'] ?? '') === 'image/png',
    json_encode([$ico['cod'], $ico['antete'], $png['cod']]));
$verifica('capturat/radacina: un SVG are CSP: sandbox în locul politicii site-ului', $svg['cod'] === 200 && ($svg['antete']['content-security-policy'] ?? '') === 'sandbox'
    && ($svg['antete']['content-type'] ?? '') === 'image/svg+xml', json_encode($svg['antete']));
$ciocnire = $cerere($port, '/assets/ciocnire-proba.png');
$ascuns = $cerere($port, '/.ascuns.png');
$js = $cerere($port, '/scriptul.js');
$urcare = $cerere($port, '/wp-admin/images/../../favicon.ico');
$faraRad = $cerere($portF, '/favicon.ico');
$verifica('capturat/radacina: nimic peste un dosar al CMS-ului (/assets/…), niciun fișier ascuns, niciun .js, nicio urcare cu „..”; fără cadru, nimic',
    $ciocnire['corp'] !== "\x89PNG\r\n\x1a\nciocnire" && $ascuns['corp'] !== "\x89PNG\r\n\x1a\nascuns" && $js['corp'] !== 'alert(1)'
    && $urcare['corp'] !== "\x00\x00\x01\x00proba" && $faraRad['corp'] !== "\x00\x00\x01\x00proba",
    json_encode([$ciocnire['cod'], $ascuns['cod'], $js['cod'], $urcare['cod'], $faraRad['cod']]));
// Diagnosticul fișierelor manifestului: copiile la calea publicată, cu sha256-ul lor; cele necopiate intenționat (cu motivul), doar numărate.
$verifica('diagnostic --capturat: copiate = la calea publicată (și capturat/radacina/) cu sha256-ul lor; necopiate cu motiv (.js, SVG refuzat) nu lipsesc; '
    . 'fără copie și fără motiv, o copie lipsă sau schimbată — NU', (static function () use ($lucru): bool {
    $dr = $lucru . '/docroot-diagnostic';
    exec('rm -rf ' . escapeshellarg($dr));
    @mkdir($dr . '/wp-content/themes/t', 0770, true);
    @mkdir($dr . '/capturat/radacina', 0770, true);
    file_put_contents($dr . '/wp-content/themes/t/a.css', 'p{}');
    file_put_contents($dr . '/capturat/radacina/favicon.ico', 'ico');
    file_put_contents($dr . '/wp-content/themes/t/b.css', 'schimbat');
    $sha = static fn (string $t): string => hash('sha256', $t);
    $f = static fn (string $cale, array $x): array => ['cale' => $cale, 'sha256' => str_repeat('0', 64)] + $x;
    $bune = ['fisiere' => [
        $f('/wp-content/themes/t/a.css', ['publicat' => '/wp-content/themes/t/a.css', 'sha256_publicat' => $sha('p{}')]),
        $f('/favicon.ico', ['publicat' => '/capturat/radacina/favicon.ico', 'sha256_publicat' => $sha('ico')]),
        $f('/wp-content/plugins/p/x.js', ['publicat' => '/wp-content/plugins/p/x.js', 'sha256_publicat' => null, 'necopiat' => 'tip nepermis: .js']),
        $f('/wp-includes/js/m/c.svg', ['publicat' => '/wp-includes/js/m/c.svg', 'sha256_publicat' => null, 'necopiat' => 'SVG refuzat de SvgSigur (element nepermis: <style>)']),
    ]];
    $r = \App\Support\CadruCapturat::verificaFisiereManifest($bune, $dr);
    $rele = ['fisiere' => [
        $f('/wp-content/themes/t/lipsa.css', ['publicat' => '/wp-content/themes/t/lipsa.css', 'sha256_publicat' => null]),
        $f('/wp-content/themes/t/c.css', ['publicat' => '/wp-content/themes/t/c.css', 'sha256_publicat' => $sha('c')]),
        $f('/wp-content/themes/t/b.css', ['publicat' => '/wp-content/themes/t/b.css', 'sha256_publicat' => $sha('b')]),
        $f('/wp-content/themes/t/gol.css', ['publicat' => '/wp-content/themes/t/gol.css', 'sha256_publicat' => null, 'necopiat' => ' ']),
        $f('/../x.css', []),
    ]];
    $q = \App\Support\CadruCapturat::verificaFisiereManifest($rele, $dr);
    exec('rm -rf ' . escapeshellarg($dr));
    return $r === ['verificate' => 2, 'necopiate' => 2, 'probleme' => []] && $q['necopiate'] === 0 && count($q['probleme']) === 5
        && in_array('lipsește /wp-content/themes/t/lipsa.css', $q['probleme'], true) && in_array('lipsește /wp-content/themes/t/c.css', $q['probleme'], true)
        && in_array('alt sha256 decât în manifest: /wp-content/themes/t/b.css', $q['probleme'], true)
        && in_array('lipsește /wp-content/themes/t/gol.css', $q['probleme'], true) && in_array('manifest: o intrare fără cale bună', $q['probleme'], true);
})());
$verifica('capturat/radacina: coliziunile ies la diagnostic (NU)', (static function (): bool {
    $c = \App\Support\RadacinaCapturata::coliziuni();
    return count($c) === 1 && str_contains($c[0], '/assets/ciocnire-proba.png');
})());

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 8. Rapoartele CSP (pasul 9a): /_andaxi/raport, înaintea sesiunii, numărători pe zi, nimic despre vizitator');
/** @return array{cod: int, antete: array<string, string>, corp: string} */
$trimite = static function (int $port, string $tip, string $corp, string $cale = '/_andaxi/raport'): array {
    $c = curl_init('http://127.0.0.1:' . $port . $cale);
    $primite = [];
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $corp, CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '',
        CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ['Content-Type: ' . $tip], CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$primite): int {
            if (str_contains($linie, ':')) {
                [$n, $v] = explode(':', $linie, 2);
                $primite[strtolower(trim($n))][] = trim($v);
            }
            return strlen($linie);
        }]);
    $r = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $cod, 'antete' => array_map(static fn (array $v): string => implode(', ', $v), $primite), 'corp' => $r];
};
$db->exec('DELETE FROM rapoarte_csp');
$raportVechi = (string) json_encode(['csp-report' => ['document-uri' => $url . '/camere/?email=cineva&x=1', 'violated-directive' => 'script-src-elem',
    'effective-directive' => 'script-src-elem', 'blocked-uri' => 'https://terta.example.test/s.js?id=123', 'source-file' => $url . '/camere/?a=b', 'line-number' => 12]]);
$rV = $trimite($port, 'application/csp-report', $raportVechi);
$trimite($port, 'application/csp-report; charset=utf-8', $raportVechi);
$randuri = $db->query('SELECT document, directiva, blocat, sursa, linie, numar FROM rapoarte_csp')->fetchAll(PDO::FETCH_ASSOC);
$verifica('application/csp-report: 204, fără corp, fără cookie, no-store; un rând cu numărul 2', $rV['cod'] === 204 && $rV['corp'] === ''
    && !isset($rV['antete']['set-cookie']) && str_contains($rV['antete']['cache-control'] ?? '', 'no-store') && count($randuri) === 1
    && (int) $randuri[0]['numar'] === 2, json_encode([$rV, $randuri]));
$verifica('cele cinci câmpuri fără query: calea documentului, directiva, adresa blocată, sursa, linia', array_map('strval', $randuri[0] ?? []) === ['document' => '/camere/',
    'directiva' => 'script-src-elem', 'blocat' => 'https://terta.example.test/s.js', 'sursa' => $url . '/camere/', 'linie' => '12', 'numar' => '2'], json_encode($randuri));
$rN = $trimite($port, 'application/reports+json', (string) json_encode([
    ['type' => 'csp-violation', 'body' => ['documentURL' => $url . '/', 'effectiveDirective' => 'img-src', 'blockedURL' => 'data:image/png;base64,AAAA', 'lineNumber' => 0]],
    ['type' => 'csp-violation', 'body' => ['documentURL' => $url . '/', 'effectiveDirective' => 'script-src', 'blockedURL' => 'inline', 'sourceFile' => $url . '/', 'lineNumber' => 3]],
    ['type' => 'deprecation', 'body' => ['id' => 'x']],
]));
$noi = $db->query("SELECT directiva, blocat FROM rapoarte_csp WHERE document = '/' ORDER BY directiva")->fetchAll(PDO::FETCH_NUM);
$verifica('application/reports+json: 204; doar rapoartele csp-violation; data: rămâne doar schema, inline rămâne cuvântul', $rN['cod'] === 204
    && $noi === [['img-src', 'data'], ['script-src', 'inline']], json_encode([$rN['cod'], $noi]));
$total = (int) $db->query('SELECT SUM(numar) FROM rapoarte_csp')->fetchColumn();
$coduri = [
    'tip' => $trimite($port, 'application/json', $raportVechi)['cod'],
    'mare' => $trimite($port, 'application/csp-report', '{"csp-report": {"document-uri": "' . str_repeat('a', 9000) . '"}}')['cod'],
    'stricat' => $trimite($port, 'application/csp-report', '{"csp-report": ')['cod'],
    'fara-raport' => $trimite($port, 'application/reports+json', '[{"type": "deprecation", "body": {}}]')['cod'],
];
$verifica('alt tip 415, peste 8 KB 413, JSON stricat 400, niciun raport CSP 400; nimic numărat', $coduri === ['tip' => 415, 'mare' => 413, 'stricat' => 400, 'fara-raport' => 400]
    && (int) $db->query('SELECT SUM(numar) FROM rapoarte_csp')->fetchColumn() === $total, json_encode($coduri));
$get = $cerere($port, '/_andaxi/raport');
$fara = $trimite($portF, 'application/csp-report', $raportVechi);
$verifica('GET pe adresa raportului nu e raport (404); fără cadru, POST-ul nu e interceptat și nu numără nimic', $get['cod'] === 404 && $fara['cod'] !== 204
    && (int) $db->query('SELECT SUM(numar) FROM rapoarte_csp')->fetchColumn() === $total, $get['cod'] . ' ' . $fara['cod']);
$lung = RaportCsp::citeste('application/csp-report', (string) json_encode(['csp-report' => ['document-uri' => 'https://x.test/' . str_repeat('ă', 300) . "\x01",
    'effective-directive' => '<b>rău</b>', 'blocked-uri' => 'javascript:alert(1)', 'line-number' => '9']]));
$verifica('câmpurile: tăiate la 200 de caractere, fără caractere de control; o directivă nevalidă goală; javascript: doar schema; linia doar întreg',
    $lung !== null && mb_strlen($lung[0]['document']) === 200 && !str_contains($lung[0]['document'], "\x01") && $lung[0]['directiva'] === ''
    && $lung[0]['blocat'] === 'javascript' && $lung[0]['linie'] === 0, json_encode($lung));
// Cel mult MAX_RANDURI_ZI rânduri pe zi: restul pe rândul „altele” (pe o zi de probă, ca să nu atingă rândurile de mai sus).
$ziProba = strtotime('2026-01-15 12:00:00');
for ($i = 0; $i <= RaportCsp::MAX_RANDURI_ZI + 2; $i++) {
    RaportCsp::primeste($db, 'application/csp-report', (string) json_encode(['csp-report' => ['document-uri' => $url . '/p' . $i . '/', 'effective-directive' => 'img-src']]), $ziProba);
}
$peZi = (int) $db->query("SELECT COUNT(*) FROM rapoarte_csp WHERE zi = '2026-01-15'")->fetchColumn();
$altele = (int) $db->query("SELECT numar FROM rapoarte_csp WHERE zi = '2026-01-15' AND directiva = 'altele'")->fetchColumn();
$verifica('cel mult ' . RaportCsp::MAX_RANDURI_ZI . ' rânduri pe zi, restul numărate pe „altele”', $peZi === RaportCsp::MAX_RANDURI_ZI + 1 && $altele === 3, $peZi . ' ' . $altele);
RaportCsp::primeste($db, 'application/csp-report', $raportVechi, $ziProba + (RaportCsp::PASTREAZA_ZILE + 1) * 86400);
$verifica('rândurile mai vechi de ' . RaportCsp::PASTREAZA_ZILE . ' de zile se șterg', (int) $db->query("SELECT COUNT(*) FROM rapoarte_csp WHERE zi = '2026-01-15'")->fetchColumn() === 0);
// Ziua PHP-ului (fusul aplicației), nu CURDATE() al serverului bazei: aproape de miezul nopții pot fi zile diferite.
// Recenzia 2, R2-3: nicio adresă IP și niciun agent scrise (nici în register_submit_logs); găleata zilei, cu sare, expiră; plafonul zilei.
$galeti = $lucru . '/galeti-csp';
$t0 = strtotime('2026-03-10 12:00:00');
$okuri = 0;
for ($i = 0; $i <= RaportCsp::BUGET; $i++) {
    $okuri += RaportCsp::inGaleata('203.0.113.7', $t0, $galeti) ? 1 : 0;
}
$altul = RaportCsp::inGaleata('203.0.113.8', $t0, $galeti);
$maine = RaportCsp::inGaleata('203.0.113.7', $t0 + 86400, $galeti);
$numeGaleti = (string) @file_get_contents($galeti . '/2026-03-11.json');
// Recenzia 3, R2-N2: o rețea IPv6 /64 e o singură găleată (altă adresă din același /64 nu primește buget nou); un singur fișier pe zi.
for ($i = 0; $i < RaportCsp::BUGET; $i++) {
    RaportCsp::inGaleata('2001:db8:1:2::' . dechex($i + 1), $t0 + 86400, $galeti);
}
$v6 = RaportCsp::inGaleata('2001:db8:1:2:ffff::1', $t0 + 86400, $galeti);
$v6alt = RaportCsp::inGaleata('2001:db8:1:3::1', $t0 + 86400, $galeti);
$fisiere = array_map('basename', glob($galeti . '/*') ?: []);
$verifica('rapoartele CSP: fără APP_KEY, numele gălețile vin dintr-un secret aleator scris o dată (niciodată o cheie fixă)', (static function (): bool {
    $d = sys_get_temp_dir() . '/raport-csp-secret-' . getmypid();
    $env = getenv('APP_KEY');
    putenv('APP_KEY=');
    $_ENV['APP_KEY'] = '';
    $ok1 = \App\Support\RaportCsp::inGaleata('198.51.100.9', time(), $d);
    $s1 = (string) @file_get_contents($d . '/secret');
    \App\Support\RaportCsp::inGaleata('198.51.100.9', time(), $d);
    $s2 = (string) @file_get_contents($d . '/secret');
    if ($env !== false) {
        putenv('APP_KEY=' . $env);
        $_ENV['APP_KEY'] = $env;
    } else {
        unset($_ENV['APP_KEY']);
    }
    exec('rm -rf ' . escapeshellarg($d));
    return $ok1 && strlen($s1) === 64 && $s1 === $s2 && !str_contains((string) file_get_contents(__DIR__ . '/../../src/Support/RaportCsp.php'), 'fara-cheie');
})());
$verifica('rapoartele CSP: găleata pe zi și pe rețea (BUGET, apoi refuz; un /64 e o găleată), alt vizitator separat, a doua zi de la capăt, ziua trecută ștearsă, un fișier pe zi, numele găleții nu e adresa',
    $okuri === RaportCsp::BUGET && $altul && $maine && !is_file($galeti . '/2026-03-10.json') && !str_contains($numeGaleti, '203.0.113')
    && !$v6 && $v6alt && count(array_filter($fisiere, static fn (string $f): bool => str_ends_with($f, '.json'))) === 1, $okuri . ' ' . implode(',', $fisiere));
$db->prepare("INSERT INTO rapoarte_csp (zi, cheie, directiva, numar, ultimul_la) VALUES ('2026-03-11', ?, 'img-src', ?, '2026-03-11 10:00:00')")
    ->execute([str_repeat('c', 64), RaportCsp::MAX_PE_ZI]);
$codPlafon = RaportCsp::primeste($db, 'application/csp-report', $raportVechi, strtotime('2026-03-11 12:00:00'));
$randuriPlafon = (int) $db->query("SELECT COUNT(*) FROM rapoarte_csp WHERE zi = '2026-03-11'")->fetchColumn();
$ipuri = (int) $db->query("SELECT COUNT(*) FROM register_submit_logs WHERE status LIKE 'l:raport%'")->fetchColumn();
$verifica('rapoartele CSP: peste plafonul zilei (' . RaportCsp::MAX_PE_ZI . ') 204 fără nimic scris; niciun rând cu IP sau agent în register_submit_logs',
    $codPlafon === 204 && $randuriPlafon === 1 && $ipuri === 0, $codPlafon . ' ' . $randuriPlafon . ' ' . $ipuri);
$db->prepare('DELETE FROM rapoarte_csp WHERE zi <> ?')->execute([date('Y-m-d')]);
\App\Support\DateSite::reseteaza($lucru . '/pachet-fara');
Tema::reseteaza();
$sanatateFara = RaportCsp::sanatate($db);
\App\Support\DateSite::reseteaza($lucru . '/pachet');
Tema::reseteaza();
$sanatate = RaportCsp::sanatate($db);
\App\Support\DateSite::reseteaza(null);
Tema::reseteaza();
$verifica('sănătatea: fără cadru nicio bucată; pe cadru, numărul pe 7 zile și cel mai des (text simplu); recente(): cel mai des primul', $sanatateFara === []
    && count($sanatate) === 1 && $sanatate[0]['ton'] === 'atentie' && str_contains($sanatate[0]['text'], '4 rapoarte în ultimele 7 zile; cel mai des: script-src-elem → https://terta.example.test/s.js pe /camere/ (2)')
    && (RaportCsp::recente($db)[0]['numar'] ?? 0) === 2, json_encode([$sanatateFara, $sanatate]));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 5. Tema::valideaza pe cheile cadrului capturat');
[$v, $pb] = Tema::valideaza(['cadru' => 'capturat', 'runtime' => 'divi4@1', 'csp' => ['mod' => 'raport', 'img-src' => ['https://poze.example.test/p/']],
    'previzualizare' => [390, 1440]], $lucru . '/pachet');
$verifica('valorile bune trec', $pb === [] && $v['cadru'] === 'capturat' && $v['runtime'] === 'divi4@1' && $v['csp']['mod'] === 'raport', implode('; ', $pb));
[, $pbRau] = Tema::valideaza(['cadru' => 'altul', 'runtime' => 'divi4@9', 'csp' => ['script-src' => ['https://rau.example.test/x.js'], 'img-src' => ['http://x.test/', 'https://*.x.test/'],
    'mod' => 'altul'], 'previzualizare' => [10]], $lucru . '/pachet');
$verifica('valorile rele: cadrul, un runtime fără manifest, script-src lărgit, surse http și cu *, modul, lățimile — 7 probleme', count($pbRau) === 7, implode('; ', $pbRau));

$scrie('');
$scrie('Rezultat: ' . $trecute . ' verificări trecute, ' . $esuate . ' eșuate.');
exit($esuate === 0 ? 0 : 1);
