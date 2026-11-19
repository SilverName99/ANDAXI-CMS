<?php

declare(strict_types=1);

/**
 * Poarta G-captura (adoptarea WordPress, pasul 4a; App\Support\ImportWordPress\Captura, scripts/captureaza-wordpress.php): captura
 * unui site Divi INVENTAT („Pensiunea Probă”, tools/dev/lib/captura-proba.php), servit local de `php -S` pe 127.0.0.1. Nicio
 * cerere nu iese din mașină (tools/dev/php.sh blochează internetul), nicio dată a unui client.
 *
 *   tools/dev/php.sh tools/dev/test-captura.php
 *
 * Ce verifică: lista adreselor (fișierul, oEmbed, 404 aleator, feluri), cererile (doar GET, fără cookie-uri, ritmul, bugetul, 429
 * cu Retry-After, 301 neurmat, gazdele și trackerele refuzate înainte de rețea), normalizarea (nonce-urile și în textul scripturilor,
 * secretele embed, clasele de sistem, adresele proprii relative, head-ul SEO absolut), comparația pe UA (o diferență plantată după
 * UA și una la fiecare cerere = NU, cu diferența arătată), ieșirea (brut, index, manifest, raport; niciodată în repo), răspunsurile
 * înghețate (robots cu CRLF și fără newline final, sitemap-urile găsite), copierea statică (refuzurile), fonturile, integritatea,
 * dosarul capturilor și șabloanele `.htaccess` (`nosniff`). Plus linia de comandă: --plan nu cere nimic.
 *
 * Fișierele de lucru: în $CMS_DEV_TMP (sau dosarul temporar al sistemului), în afara repo-ului; se șterg la final.
 * Raport: $CMS_DEV_DIR/test-captura-raport.txt; cod 0 doar dacă totul a trecut.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';

use App\Support\ImportWordPress\Captura\Captura;
use App\Support\ImportWordPress\Captura\Cereri;
use App\Support\ImportWordPress\Captura\CopiereStatica;
use App\Support\ImportWordPress\Captura\DosarCapturi;
use App\Support\ImportWordPress\Captura\EroareCaptura;
use App\Support\ImportWordPress\Captura\FonturiGoogle;
use App\Support\ImportWordPress\Captura\Integritate;
use App\Support\ImportWordPress\Captura\ListaAdrese;
use App\Support\ImportWordPress\Captura\Normalizare;
use App\Support\ImportWordPress\Captura\Resurse;
use App\Support\ImportWordPress\Continut\Divi4;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
@mkdir($dosarDev, 0770, true);
$fisierRaport = $dosarDev . '/test-captura-raport.txt';
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
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . substr($detalii, 0, 1500) : ''));
    }
};
$arunca = static function (callable $f): ?EroareCaptura {
    try {
        $f();
    } catch (EroareCaptura $e) {
        return $e;
    }
    return null;
};
$scrie('Test captura WordPress (G-captura, pasul 4a) — ' . date('Y-m-d H:i:s'));

// Lucrul: în afara repo-ului (captura refuză să scrie în el).
$tmp = rtrim((string) (getenv('CMS_DEV_TMP') ?: sys_get_temp_dir()), '/') . '/cms-test-captura-' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
foreach (['stare', 'iesire', 'public', 'sursa', 'wp'] as $d) {
    mkdir($tmp . '/' . $d, 0770, true);
}
// Serverul de probă, pe un port liber.
$s = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr((string) stream_socket_get_name($s, false), strrpos((string) stream_socket_get_name($s, false), ':') + 1);
fclose($s);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $radacina . '/tools/dev/lib/captura-proba.php'], [1 => ['file', $tmp . '/server.log', 'a'],
    2 => ['file', $tmp . '/server.log', 'a']], $pipe, $tmp, array_merge(getenv(), ['CAPTURA_PROBA_STARE' => $tmp . '/stare']));
register_shutdown_function(static function () use (&$server, $tmp): void {
    if (is_resource($server)) {
        $st = proc_get_status($server);
        if ($st['running']) {
            posix_kill((int) $st['pid'], SIGTERM);
        }
        proc_close($server);
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});
for ($i = 0; $i < 50; $i++) {
    $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
    if ($c !== false) {
        fclose($c);
        break;
    }
    usleep(100000);
}
$site = 'http://127.0.0.1:' . $port;
$gazda = '127.0.0.1:' . $port;
$cereriServer = static function () use ($tmp): array {
    return array_map(static fn (string $l): array => (array) json_decode($l, true), file($tmp . '/stare/cereri.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
};

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== Lista adreselor');
file_put_contents($tmp . '/lista.json', json_encode(['adrese' => [['fel' => 'pagina', 'cale' => '/'], ['fel' => 'pagina', 'cale' => '/camere/'],
    ['fel' => 'pagina', 'cale' => '/diferita/'], ['fel' => 'pagina', 'cale' => '/aleatoare/'], ['fel' => 'pagina', 'cale' => '/obfuscat/'], ['fel' => '404', 'cale' => '/{aleator}-inexistent/'],
    ['fel' => 'embed', 'cale' => '/camere/embed/'], ['fel' => 'embed', 'cale' => '/embed/'], ['fel' => 'oembed', 'cale' => '/camere/'],
    ['fel' => 'raspuns', 'cale' => '/robots.txt'], ['fel' => 'raspuns', 'cale' => '/sitemap.xml'], ['fel' => 'pagina', 'cale' => '/camere/']]]));
$lista = ListaAdrese::dinFisier($tmp . '/lista.json', $site, 'abc123');
$verifica('din fișier: 11 adrese (dublura scoasă), 404-ul cu valoarea aleatoare', count($lista) === 11 && $lista[5]['url'] === $site . '/abc123-inexistent/', json_encode($lista));
$verifica('oEmbed: adresa REST cu pagina codată (cum o leagă head-ul)', $lista[8]['url'] === $site . '/wp-json/oembed/1.0/embed?url=' . rawurlencode($site . '/camere/'));
$verifica('filtreaza(): doar felurile cerute', count(ListaAdrese::filtreaza($lista, ['pagina'])) === 5 && count(ListaAdrese::filtreaza($lista, ['raspuns'])) === 2);
file_put_contents($tmp . '/lista-rea.json', json_encode(['adrese' => [['fel' => 'post', 'cale' => '/x/']]]));
$verifica('un fel necunoscut sau o cale fără „/” sunt refuzate', $arunca(static fn () => ListaAdrese::dinFisier($tmp . '/lista-rea.json', $site)) !== null);
$plan = Captura::plan($lista);
$verifica('planul: 6 UA × 2 pe fiecare adresă (implicit), cu --raspunsuri-ua=2 răspunsurile înghețate pe 2 UA × 2',
    $plan['cereri'] === 11 * 12 && Captura::plan($lista, 2)['cereri'] === 8 * 12 + 3 * 4, json_encode([$plan, Captura::plan($lista, 2)]));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== Lista adreselor din inventarul WordPress (DECIZIE §2.3 #1), pe site-ul WordPress de probă (tools/dev/lib/wordpress-proba.php)');
require_once $radacina . '/tools/dev/lib/wordpress-proba.php';
$sock = (string) (getenv('CMS_DEV_SOCK') ?: ini_get('pdo_mysql.default_socket'));
$rootWp = new PDO('mysql:unix_socket=' . $sock . ';charset=utf8mb4', (string) (getenv('CMS_DEV_DB_USER') ?: 'root'), (string) (getenv('CMS_DEV_DB_PAROLA') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$bazaWpCap = 'cms_cap_wp_' . getmypid();
register_shutdown_function(static function () use ($rootWp, $bazaWpCap): void {
    $GLOBALS['sursaWpCap'] = null;
    $GLOBALS['inventarWpCap'] = null;
    gc_collect_cycles();
    try {
        $rootWp->exec('DROP DATABASE IF EXISTS `' . $bazaWpCap . '`');
    } catch (Throwable) {
    }
});
$idsWp = wordpressProba($rootWp, $bazaWpCap, $tmp . '/wp-proba', ['prefix' => 'wpc_', 'divi' => true, 'divi4' => true]);
$rootWp->exec("INSERT INTO wpc_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, post_name, to_ping, pinged, post_modified,"
    . " post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type) SELECT post_author, post_date, post_date_gmt, post_content,"
    . " 'Hello din nou', post_excerpt, 'publish', 'hello-din-nou', to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, 0, guid, 0, 'post', ''"
    . " FROM wpc_posts WHERE post_name = 'hello-world'");
$rootWp->exec("INSERT INTO wpc_options (option_name, option_value) VALUES ('posts_per_page', '1') ON DUPLICATE KEY UPDATE option_value = '1'");
$rootWp->exec('USE mysql');
$sursaWpCap = \App\Support\ImportWordPress\SursaWordPress::deschide(\App\Support\ImportWordPress\ConfigWordPress::citeste($tmp . '/wp-proba'));
$inventarWpCap = \App\Support\ImportWordPress\Inventar::citeste($sursaWpCap);
$listaWp = ListaAdrese::dinInventar($inventarWpCap, ['pagini' => ['contact' => ['importa' => false]]], WP_PROBA_URL, 'abc');
$are = static function (string $fel, string $cale) use ($listaWp): bool {
    foreach ($listaWp as $a) {
        if ($a['fel'] === $fel && $a['cale'] === $cale) {
            return true;
        }
    }
    return false;
};
$paginiWp = array_column(array_filter($listaWp, static fn (array $a): bool => $a['fel'] === 'pagina'), 'cale');
$verifica('inventar: paginile publicate (prima la /, pagina copil), apoi articolele și proiectul Divi („postare”); fără privată, ciornă și pagina scoasă de pachet',
    $are('pagina', '/') && $are('pagina', '/camere/') && $are('pagina', '/despre-noi/echipa/') && $are('postare', '/hello-world/') && $are('postare', '/hello-din-nou/')
    && $are('postare', '/project/drumetie-pe-munte/') && !in_array('/pagina-privata/', $paginiWp, true) && !in_array('/ciorna/', $paginiWp, true) && !in_array('/contact/', $paginiWp, true)
    && !in_array('/hello-world/', $paginiWp, true) && count(Captura::ua('postare', 2)) === 6, json_encode($paginiWp));
$verifica('inventar: arhivele (anul, luna, categoria, autorul, proiectele), cu pagina 2 când nu încap pe una (posts_per_page = 1)',
    $are('arhiva', '/2024/') && $are('arhiva', '/2024/page/2/') && $are('arhiva', '/2024/05/') && $are('arhiva', '/2024/05/page/2/') && $are('arhiva', '/category/uncategorized/')
    && !$are('arhiva', '/category/uncategorized/page/2/') && $are('arhiva', '/project/') && count(array_filter($listaWp, static fn (array $a): bool => $a['fel'] === 'arhiva'
        && str_starts_with($a['cale'], '/author/'))) === 2, json_encode(array_column(array_filter($listaWp, static fn (array $a): bool => $a['fel'] === 'arhiva'), 'cale')));
$verifica('inventar: căutarea fără rezultate, cu rezultate (un cuvânt din titlu) și pagina ei 2; 404, embed și oEmbed pe pagini; fișierele speciale',
    $are('cautare', '/?s=abc') && $are('cautare', '/?s=hello') && $are('cautare', '/page/2/?s=hello') && $are('404', '/abc-inexistent/') && $are('embed', '/camere/embed/')
    && $are('oembed', '/camere/') && $are('raspuns', '/robots.txt') && $are('raspuns', '/sitemap.xml'));
$verifica('inventar: pagina scoasă de pachet (ca o vede live, „scoasa”) și slug-ul vechi (redirecționarea WordPress, „redirectionare”)', $are('scoasa', '/contact/')
    && $are('redirectionare', '/camerele-noastre/'));
$verifica('felurile noi: arhivele și căutarea pe 6 UA × 2, cu resursele lor; pagina scoasă și redirecționarea ca răspunsurile înghețate', count(Captura::ua('arhiva', 2)) === 6
    && count(Captura::ua('cautare', 2)) === 6 && count(Captura::ua('scoasa', 2)) === 2 && count(Captura::ua('redirectionare', 2)) === 2
    && in_array('scoasa', ListaAdrese::DOCUMENTE, true) && !in_array('scoasa', ListaAdrese::HTML, true));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== Cererile: doar GET, gazdele, trackerele, ritmul, bugetul, 429, 301');
$verifica('un tracker în lista gazdelor e refuzat de la început', $arunca(static fn () => new Cereri(['www.googletagmanager.com'], 10)) !== null);
$c0 = new Cereri([$gazda], 3, 0.0);
foreach (['https://www.google-analytics.com/g/collect', 'https://www.clarity.ms/tag/abc', 'https://stats.g.doubleclick.net/x', 'https://www.googletagmanager.com/gtm.js?id=X',
    'https://alt-site.example.test/', 'ftp://' . $gazda . '/x', $site . '@altul.test/'] as $u) {
    $verifica('refuzat înaintea rețelei: ' . $u, $arunca(static fn () => $c0->get($u)) !== null && $c0->facute() === 0);
}
$verifica('clasa n-are altă metodă de cerere decât get() (nici POST, nici trimiterea unui formular)',
    array_values(array_filter(get_class_methods(Cereri::class), static fn (string $m): bool => preg_match('/^(post|put|patch|delete|trimite|formular|cere)/i', $m) === 1)) === []);
$inainte = count($cereriServer());
$c1 = new Cereri([$gazda], 3, 0.3);
$r1 = $c1->get($site . '/camere/', 'chrome-win');
$verifica('429 la prima cerere: reluată după Retry-After, 200 la a doua încercare', $r1->status === 200 && $r1->incercari === 2 && $c1->facute() === 2, $r1->status . '/' . $r1->incercari);
$t0 = microtime(true);
$r2 = $c1->get($site . '/embed/', 'firefox');
$verifica('301 neurmat: statusul și Location păstrate', $r2->status === 301 && $r2->antet('location') === $site . '/', (string) $r2->antet('location'));
$verifica('ritmul: cel puțin 0,3 s între cereri', microtime(true) - $t0 >= 0.29);
$verifica('bugetul: a patra cerere e refuzată, fără să plece', ($e = $arunca(static fn () => $c1->get($site . '/'))) !== null && $e->fel === 'buget' && $c1->facute() === 3);
$dupa = array_slice($cereriServer(), $inainte);
$verifica('serverul a văzut exact cererile numărate, toate GET, niciuna cu cookie (deși serverul le pune)', count($dupa) === 3
    && array_filter($dupa, static fn (array $x): bool => $x['metoda'] !== 'GET' || $x['cookie']) === [], json_encode($dupa));
$verifica('UA-ul trimis e cel cerut', str_contains((string) ($dupa[2]['ua'] ?? ''), 'Firefox/'));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== Cererile, cazurile de margine: redirecționări străine, 429 și 503, Retry-After, mărimea, timeout, bugetul, jurnalul, adresele');
$n0 = count($cereriServer());
$cj = new Cereri([$gazda], 60, 0.0, 2, 3, 2, $tmp . '/jurnal-proba.jsonl', 1, 1, 4096);
$rA = $cj->get($site . '/la-alta-gazda/');
$rT = $cj->get($site . '/la-tracker/');
$verifica('redirecționarea spre altă gazdă și spre un tracker: neurmate (statusul și Location păstrate, o singură cerere fiecare)',
    $rA->status === 302 && str_starts_with((string) $rA->antet('location'), 'http://127.0.0.2:') && $rT->status === 301
    && str_contains((string) $rT->antet('location'), 'google-analytics.com') && count(array_slice($cereriServer(), $n0)) === 2);
$t = microtime(true);
$r5 = $cj->get($site . '/ocupat/');
$verifica('503 cu Retry-After: reluat ca un 429 (după ~1 s), apoi 200', $r5->status === 200 && $r5->incercari === 2 && microtime(true) - $t >= 0.9, $r5->status . '/' . $r5->incercari);
$t = microtime(true);
$rd = $cj->get($site . '/429-data/');
$verifica('Retry-After ca dată HTTP: așteptarea până la ea (plafonată la 2 s), apoi 200', $rd->status === 200 && $rd->incercari === 2 && microtime(true) - $t >= 0.9
    && microtime(true) - $t < 4.5, $rd->status . '/' . round(microtime(true) - $t, 2));
$t = microtime(true);
$rz = (new Cereri([$gazda], 5, 0.0, 1, 9, 1))->get($site . '/429-o-zi/');
$verifica('Retry-After de o zi: plafonat (aici la 1 s); după reluări răspunsul rămâne 429', $rz->status === 429 && $rz->incercari === 2 && microtime(true) - $t < 3.5);
$cl = new Cereri([$gazda], 20, 0.0, 5, 3, 1);
$e = $arunca(static fn () => $cl->get($site . '/mereu-429/'));
$verifica('429 la rând: după 3 răspunsuri captura se oprește politicos (limitare), fiecare încercare numărată', $e !== null && $e->fel === 'limitare' && $cl->facute() === 3);
$e = $arunca(static fn () => $cj->get($site . '/mare/'));
$verifica('mărimea: un corp peste limită (aici 4 KB) e oprit și jurnalizat, numărat în buget', $e !== null && $e->fel === 'retea' && str_contains($e->getMessage(), 'depășește')
    && str_contains((string) ($cj->jurnal()[count($cj->jurnal()) - 1]['eroare'] ?? ''), 'depășește'));
$t = microtime(true);
$e = $arunca(static fn () => $cj->get($site . '/incet/'));
$verifica('timeout: o cerere care nu se termină la timp e oprită (rețea), numărată și jurnalizată', $e !== null && $e->fel === 'retea' && microtime(true) - $t < 2.5
    && ($cj->jurnal()[count($cj->jurnal()) - 1]['eroare'] ?? '') !== '');
usleep(1500000); // serverul de probă e unul singur: îl lăsăm să termine cererea lentă
$linii = array_map(static fn (string $l): array => (array) json_decode($l, true), file($tmp . '/jurnal-proba.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
$trimise = array_values(array_filter($linii, static fn (array $l): bool => ($l['faza'] ?? '') === 'trimisa'));
$verifica('jurnalul pe disc: fiecare încercare are o linie înainte (start în ms, GET, adresa) și una după (statusul), doar adăugate',
    count($trimise) === $cj->facute() && count(array_filter($linii, static fn (array $l): bool => ($l['faza'] ?? '') === 'raspuns')) === $cj->facute()
    && array_filter($trimise, static fn (array $l): bool => ($l['metoda'] ?? '') !== 'GET' || !is_int($l['start_ms'] ?? null) || ($l['start_ms'] ?? 0) < 1e12) === []
    && $cj->jurnal()[0]['metoda'] === 'GET' && is_int($cj->jurnal()[0]['start_ms']), count($linii) . ' linii, ' . $cj->facute() . ' cereri');
$verifica('configurația cererilor pentru antetul raportului: ritmul, bugetul, gazdele permise, metoda', $cj->configuratie()['buget'] === 60 && $cj->configuratie()['gazde_permise'] === [$gazda]
    && $cj->configuratie()['metoda'] === 'GET' && $cj->configuratie()['corp_maxim_octeti'] === 4096);
$capB = new Captura($site, $tmp . '/iesire-buget', new Normalizare($site));
$nB = count($cereriServer());
$capB->prinHttp([['fel' => 'raspuns', 'cale' => '/robots.txt', 'url' => $site . '/robots.txt'], ['fel' => 'pagina', 'cale' => '/camere/', 'url' => $site . '/camere/'],
    ['fel' => 'raspuns', 'cale' => '/feed/', 'url' => $site . '/feed/']], new Cereri([$gazda], 3, 0.0), 1, static function (): void {
    }, false);
$verifica('bugetul terminat în mijlocul capturii: oprire fără nicio cerere în plus, adresele rămase NU „necapturată”', count(array_slice($cereriServer(), $nB)) === 3
    && count(array_filter($capB->probleme, static fn (string $p): bool => str_contains($p, 'necapturată') && str_contains($p, 'bugetul'))) === 2, json_encode($capB->probleme));
$capC = new Captura($site, $tmp . '/iesire-comprimat', new Normalizare($site));
$capC->prinHttp([['fel' => 'raspuns', 'cale' => '/comprimat/', 'url' => $site . '/comprimat/']], new Cereri([$gazda], 4, 0.0), 1, static function (): void {
}, false);
$rc = $capC->adrese[0]['raspunsuri'][0] ?? [];
$antC = (string) @file_get_contents($tmp . '/iesire-comprimat/' . substr((string) ($rc['fisier'] ?? ''), 0, -5) . '.antete');
$verifica('antetele păstrate: fără Content-Encoding, Content-Length și Connection (corpul păstrat e cel decodat); rândurile scoase stau în index',
    (string) @file_get_contents($tmp . '/iesire-comprimat/' . ($rc['fisier'] ?? 'x')) === '<!doctype html><p>comprimat</p>' && $antC !== ''
    && preg_match('/^(content-encoding|content-length|connection|transfer-encoding|keep-alive):/im', $antC) !== 1 && ($rc['corp'] ?? '') === 'decodat'
    && preg_grep('/^content-encoding:\s*gzip$/i', (array) ($rc['antete_scoase'] ?? [])) !== [] && ($rc['sha256_antete'] ?? '') === hash('sha256', $antC), $antC);
$cu = new Cereri([$gazda, 'pensiune-proba.test'], 5, 0.0);
$adrese = ['http://' . $gazda . '/' => true, 'HTTP://' . strtoupper($gazda) . '/' => true, 'https://pensiune-proba.test/x' => true, 'https://PENSIUNE-PROBA.TEST/x' => true,
    'https://pensiune-proba.test:443/x' => false, 'https://pensiune-proba.test./x' => false, 'https://user' . '@' . 'pensiune-proba.test/x' => false,
    'https://pensiune-proba.test@alta.example/x' => false, 'https://alta.example#@pensiune-proba.test/' => false, 'https://alta.example?@pensiune-proba.test/' => false,
    'https://pensiune-proba.test\\@alta.example/' => false, 'https://pensiune-probă.test/x' => false, 'https://xn--pensiune-prob-dpb.test/x' => false,
    'https://127.0.0.2/' => false, 'https://[::1]/' => false, 'https://www.pensiune-proba.test/x' => false, 'ftp://pensiune-proba.test/x' => false, '//pensiune-proba.test/x' => false];
$gresite = [];
foreach ($adrese as $u => $acceptata) {
    if (($cu->refuz($u) === null) !== $acceptata) {
        $gresite[] = $u;
    }
}
$verifica('adresele: doar gazda exactă din listă (litere mari da; port, punct final, userinfo, IDN, alt IP, www, altă schemă — refuzate), fără nicio cerere',
    $gresite === [] && $cu->facute() === 0, json_encode($gresite));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== Normalizarea (lista închisă, DECIZIE §6.7)');
$n = new Normalizare('https://pensiune-proba.test');
$doc = '<!doctype html><html lang="ro"><head><meta property="og:url" content="https://pensiune-proba.test/camere/"><link rel="canonical" href="https://pensiune-proba.test/camere/">'
    . '<link rel="stylesheet" href="https://pensiune-proba.test/wp-content/a.css?ver=1&#038;x=2"><style>.a{background:url(https://pensiune-proba.test/u.png)}</style></head>'
    . '<body class="home windows et_pb_gutter"><a href="https://pensiune-proba.test">acasă</a> <a href="https://pensiune-proba.test.alt.ro/x">alt</a> <a href="https://pensiune-proba.test:8080/x">port</a>'
    . '<img src="https://www.pensiune-proba.test/u/a.jpg" srcset="https://pensiune-proba.test/u/a-480.jpg 480w, //pensiune-proba.test/u/a.jpg 980w" alt="https://pensiune-proba.test/alt">'
    . '<div data-et-multi-view="{&quot;src&quot;:&quot;https:\/\/pensiune-proba.test\/u\/b.png&quot;}"></div>'
    . '<input type="hidden" name="_wpnonce-et-pb-contact-form-submitted-0" value="a1b2c3d4e5"><textarea>data-secret=&quot;Pr0baS3cr1&quot; https://pensiune-proba.test/camere/#?secret=Pr0baS3cr1</textarea>'
    . '<script>var et_pb_custom = {"ajaxurl":"https:\/\/pensiune-proba.test\/wp-admin\/admin-ajax.php","et_frontend_nonce":"0f0f0f0f0f"};</script>Vizitați https://pensiune-proba.test/ azi.</body></html>';
$norm = $n->document($doc);
$servit = Normalizare::aplica($doc, $n->editari($doc));
$verifica('head-ul SEO rămâne absolut (og:url, canonical), și în documentul servit, și la comparație', str_contains($norm, 'content="https://pensiune-proba.test/camere/"')
    && str_contains($norm, 'rel="canonical" href="https://pensiune-proba.test/camere/"') && str_contains($servit, 'rel="canonical" href="https://pensiune-proba.test/camere/"'));
$verifica('documentul servit: adresele proprii devin relative (link, CSS, href gol → „/”, srcset, protocol-relativ, JSON escapat cu entități); www rămâne absolut (altă gazdă)',
    str_contains($servit, 'href="/wp-content/a.css?ver=1&#038;x=2"') && str_contains($servit, 'url(/u.png)') && str_contains($servit, '<a href="/">acasă')
    && str_contains($servit, 'src="https://www.pensiune-proba.test/u/a.jpg"') && str_contains($servit, 'srcset="/u/a-480.jpg 480w, /u/a.jpg 980w"') && str_contains($servit, '&quot;\/u\/b.png&quot;'), $servit);
$verifica('la comparație: doar forma canonică (https://gazda, //gazda) devine relativă; www rămâne absolut',
    str_contains($norm, 'href="/wp-content/a.css?ver=1&#038;x=2"') && str_contains($norm, 'srcset="/u/a-480.jpg 480w, /u/a.jpg 980w"')
    && str_contains($norm, 'src="https://www.pensiune-proba.test/u/a.jpg"') && str_contains($norm, '&quot;\/u\/b.png&quot;'), $norm);
$verifica('alte gazde, porturi și textul arătat (alt, textarea, text) rămân neatinse', str_contains($norm, 'https://pensiune-proba.test.alt.ro/x') && str_contains($norm, 'https://pensiune-proba.test:8080/x')
    && str_contains($norm, 'alt="https://pensiune-proba.test/alt"') && str_contains($norm, 'Vizitați https://pensiune-proba.test/ azi') && str_contains($norm, '&quot; https://pensiune-proba.test/camere/#?secret='));
$verifica('nonce-urile: câmpul formularului și cheia JSON din textul scriptului → {{ax:nonce}}', str_contains($norm, 'value="{{ax:nonce}}"') && str_contains($norm, '"et_frontend_nonce":"{{ax:nonce}}"')
    && str_contains($norm, '"ajaxurl":"\/wp-admin\/admin-ajax.php"'));
$verifica('clasa de sistem scoasă de pe <body>; un secret din alt <textarea> rămâne și la comparație (D7: doar codul de încorporare al cardului)',
    str_contains($norm, 'class="home et_pb_gutter"') && str_contains($norm, 'data-secret=&quot;Pr0baS3cr1&quot;') && str_contains($norm, '#?secret=Pr0baS3cr1'));
$verifica('editari(): în afara intervalelor, octeții sunt ai capturii; fiecare cu felul ei din lista închisă', (static function () use ($n, $doc): bool {
    $ed = $n->editari($doc, null, true, [], true);
    foreach ($ed as $e) {
        foreach ($e['fel'] as $f) {
            if (!in_array($f, array_merge(Normalizare::FELURI, Normalizare::FELURI_COMPARATIE), true)) {
                return false;
            }
        }
    }
    return Normalizare::aplica($doc, $ed) === $n->document($doc) && count($ed) >= 8;
})());
$verifica('nonce-uri în plus din pachet (captura.nonce)', str_contains((new Normalizare('https://pensiune-proba.test', ['proba_token']))->raspuns('{"proba_token":"zz99"}'), '{{ax:nonce}}')
    && !str_contains($n->raspuns('{"proba_token":"zz99"}'), '{{ax:nonce}}'));
// Recenzia dialectului, B: rescrierea adreselor proprii nu schimbă niciodată gazda la care duce o adresă. Gazda e citită aici ca
// în browser (WHATWG): entitățile decodate, tab/CR/LF scoase, „\” = „/” la http(s), userinfo-ul și portul tăiate.
$gazdaCa = static function (string $u, bool $json = false): string {
    $u = html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($json) {
        $u = str_replace('\/', '/', $u);
    }
    $u = str_replace(["\t", "\r", "\n"], '', trim($u, " \x00..\x1F"));
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $u) === 1 && preg_match('#^https?:#i', $u) !== 1) {
        return '(altă schemă)';
    }
    $u = str_replace('\\', '/', $u);
    $u = (string) preg_replace('#^https?:(?!//)#i', '', $u);
    if (str_starts_with($u, '//') || preg_match('#^https?://#i', $u) === 1) {
        $aut = preg_split('#[/?\#]#', (string) preg_replace('#^(https?:)?//#i', '', $u))[0];
        return strtolower((string) preg_replace('#:\d*$#', '', (string) preg_replace('#^.*@#', '', $aut)));
    }
    return 'pensiune-proba.test'; // relativă: pe gazda paginii
};
$pg = 'pensiune-proba.test';
$adreseB = [
    'simplă' => 'https://' . $pg . '/camere/', 'protocol-relativ' => '//' . $pg . '/camere/', 'litere mari' => 'HTTPS://PENSIUNE-PROBA.TEST/x',
    'goală' => 'https://' . $pg, 'cu query' => 'https://' . $pg . '?p=1', 'cu fragment' => 'https://' . $pg . '#sus', 'www' => 'https://www.' . $pg . '/x',
    'subdomeniu' => 'https://cdn.' . $pg . '/x', 'gazdă care începe la fel' => 'https://' . $pg . '.rau.example/x', 'port' => 'https://' . $pg . ':8443/x',
    'userinfo' => 'https://' . $pg . '@rau.example/x', 'userinfo cu parolă' => 'https://' . $pg . ':p' . '@rau.example/x',
    '// după gazdă' => 'https://' . $pg . '//rau.example/poza.png', '/\\ după gazdă' => 'https://' . $pg . '/\\rau.example/poza.png',
    '\\ după gazdă' => 'https://' . $pg . '\\rau.example/poza.png', '\\\\ după gazdă' => 'https://' . $pg . '\\\\rau.example/poza.png',
    'percent-encoded' => 'https://' . $pg . '%2F%2Frau.example/x', 'tab în gazdă' => 'https://' . $pg . "\t.rau.example/x",
    'LF după gazdă, apoi //' => 'https://' . $pg . "\n//rau.example/x", 'CR între //' => 'https://' . $pg . "/\r/rau.example/x",
    'entitate / după gazdă' => 'https://' . $pg . '/&#47;rau.example/x', 'entitate @' => 'https://' . $pg . '&#64;rau.example/x',
];
$schimbate = [];
$rescrise = 0;
foreach ($adreseB as $titlu => $u) {
    foreach ([false, true] as $comparatie) {
        $html = '<a href="' . htmlspecialchars($u, ENT_QUOTES, 'UTF-8', false) . '">x</a>';
        $dupa = $comparatie ? $n->document($html) : Normalizare::aplica($html, $n->editari($html));
        preg_match('/href="([^"]*)"/', $dupa, $m);
        $rescrise += ($m[1] ?? '') !== htmlspecialchars($u, ENT_QUOTES, 'UTF-8', false) ? 1 : 0;
        if ($gazdaCa($u) !== $gazdaCa($m[1] ?? '')) {
            $schimbate[] = $titlu . ($comparatie ? ' (comparație)' : '') . ': ' . $gazdaCa($u) . ' → ' . $gazdaCa($m[1] ?? '') . ' (' . ($m[1] ?? '') . ')';
        }
    }
    // În CSS și în JSON-ul unui script (escapat), ca în foi și în textul scripturilor.
    $css = $n->css('a{background:url(' . $u . ')}');
    preg_match('/url\((.*)\)\}$/s', $css, $m);
    if ($gazdaCa($u) !== $gazdaCa($m[1] ?? '')) {
        $schimbate[] = $titlu . ' (CSS): ' . $gazdaCa($u) . ' → ' . $gazdaCa($m[1] ?? '');
    }
    $js = $n->textScript('x("' . str_replace('/', '\/', $u) . '")');
    preg_match('/^x\("(.*)"\)$/s', $js, $m);
    if ($gazdaCa($u) !== $gazdaCa($m[1] ?? '', true)) {
        $schimbate[] = $titlu . ' (JSON): ' . $gazdaCa($u) . ' → ' . $gazdaCa($m[1] ?? '', true) . ' (' . ($m[1] ?? '') . ')';
    }
}
$verifica('adresele proprii: nicio rescriere nu schimbă gazda (userinfo, //, /\\, \\, %2F, tab/CR/LF, entități; în atribute, CSS și JSON)',
    $schimbate === [], implode('; ', $schimbate));
$verifica('adresele proprii: cele sigure tot devin relative (simplă, protocol-relativ, litere mari, goală, query, fragment)', (static function () use ($n): bool {
    $h = '<a href="https://pensiune-proba.test/camere/">a</a><a href="//pensiune-proba.test/c/">b</a><a href="HTTPS://PENSIUNE-PROBA.TEST/x">c</a>'
        . '<a href="https://pensiune-proba.test">d</a><a href="https://pensiune-proba.test?p=1">e</a><a href="https://pensiune-proba.test#sus">f</a>';
    return Normalizare::aplica($h, $n->editari($h)) === '<a href="/camere/">a</a><a href="/c/">b</a><a href="/x">c</a><a href="/">d</a><a href="/?p=1">e</a><a href="/#sus">f</a>';
})());
$verifica('adresele care nu sunt sigur ale noastre rămân octet cu octet (userinfo, // și \\ după gazdă, tab, LF, entitate)', (static function () use ($n): bool {
    foreach (['https://pensiune-proba.test' . '@rau.example/x', 'https://pensiune-proba.test//rau.example/p.png', 'https://pensiune-proba.test/\\rau.example/p.png',
        'https://pensiune-proba.test\\rau.example/p.png', "https://pensiune-proba.test\t.rau.example/x", "https://pensiune-proba.test/\n/rau.example/x",
        'https://pensiune-proba.test/&#47;rau.example/x'] as $u) {
        $h = '<img src="' . $u . '" alt="">';
        if (Normalizare::aplica($h, $n->editari($h)) !== $h || $n->textScript('x("' . $u . '")') !== 'x("' . $u . '")') {
            return false;
        }
    }
    return true;
})());
$compara = static fn (string $a, string $b): bool => $n->document($a) === $n->document($b);
$verifica('http/https și www/fără www nu mai sunt egale la comparație (//gazda, rezolvată, da); în documentul servit http devine relativ, www rămâne absolut (altă gazdă; recenzia B)',
    !$compara('<a href="http://pensiune-proba.test/x">x</a>', '<a href="https://pensiune-proba.test/x">x</a>')
    && !$compara('<a href="https://www.pensiune-proba.test/x">x</a>', '<a href="https://pensiune-proba.test/x">x</a>')
    && !$compara('<div data-u="http:\/\/pensiune-proba.test\/x"></div>', '<div data-u="https:\/\/pensiune-proba.test\/x"></div>')
    && $compara('<a href="//pensiune-proba.test/x">x</a>', '<a href="https://pensiune-proba.test/x">x</a>')
    && Normalizare::aplica('<a href="http://pensiune-proba.test/x">x</a>', $n->editari('<a href="http://pensiune-proba.test/x">x</a>')) === '<a href="/x">x</a>'
    && Normalizare::aplica('<a href="http://www.pensiune-proba.test/x">x</a>', $n->editari('<a href="http://www.pensiune-proba.test/x">x</a>')) === '<a href="http://www.pensiune-proba.test/x">x</a>');
$verifica('legăturile alternate din head (hreflang, RSS, oEmbed) rămân absolute, ca restul head-ului SEO (DECIZIE §2.3 #9, §6.7 #2)', (static function () use ($n): bool {
    $h = '<!doctype html><head><link rel="alternate" hreflang="en" href="https://pensiune-proba.test/en/rooms/"><link rel="alternate" type="application/rss+xml" href="https://pensiune-proba.test/feed/">'
        . '<link rel="alternate" type="application/json+oembed" href="https://pensiune-proba.test/wp-json/oembed/1.0/embed?url=x"><link rel="stylesheet" href="https://pensiune-proba.test/s.css"></head>';
    $s = Normalizare::aplica($h, $n->editari($h));
    return substr_count($s, 'href="https://pensiune-proba.test/') === 3 && str_contains($s, 'href="/s.css"') && substr_count($n->document($h), 'href="https://pensiune-proba.test/') === 3;
})());
$verifica('un text „{{ax:” deja în captură (un nonce „{{ax:nonce}}”, oricum scris) e găsit: NU la captură, nu luat drept normalizat',
    Normalizare::jeton('<input type="hidden" name="_wpnonce-x" value="{{ax:nonce}}">') !== null && Normalizare::jeton('{"et_frontend_nonce":"{{AX:nonce}}"}') !== null
    && Normalizare::jeton('<p>{ {ax:x}}</p>') === null);

// Regulile doar de comparație (FELURI_COMPARATIE, aprobate provizoriu cu condiția: fiecare ancorată pe construcția ei, niciodată
// în T): pentru fiecare, construcția cu două valori aleatoare e egală la comparație; aceeași formă în afara construcției rămâne
// diferită; T (dialectul) păstrează octeții capturii.
$embed = static fn (string $secret, string $nr, string $cls = 'wp-embed-share-input', ?string $nrPanou = null): string => '<!DOCTYPE html>' . "\n" . '<html lang="ro-RO" class="no-js">' . "\n<head>\n<title>Camere</title>\n</head>\n"
    . '<body class="page"><div class="wp-embed"><button role="tab" aria-controls="wp-embed-share-tab-wordpress-2-' . $nr . '">WordPress</button>'
    . '<div id="wp-embed-share-tab-wordpress-2-' . ($nrPanou ?? $nr) . '" role="tabpanel"><input aria-describedby="wp-embed-share-description-wordpress-2-' . $nr . '" readonly>'
    . '<p id="wp-embed-share-description-wordpress-2-' . $nr . '">x</p></div>'
    . '<textarea class="' . $cls . '" readonly>&lt;blockquote data-secret=&quot;' . $secret . '&quot;&gt;&lt;/blockquote&gt;&lt;iframe src=&quot;https://pensiune-proba.test/camere/embed/#?secret='
    . $secret . '&quot;&gt;&lt;/iframe&gt;</textarea></div>' . "\n</body>\n</html>\n";
$farFeluri = static fn (\App\Support\ImportWordPress\Continut\RezultatDialect $r): bool => array_filter($r->editari,
    static fn (array $e): bool => array_intersect(explode('+', $e['fel']), Normalizare::FELURI_COMPARATIE) !== []) === [];
$tE = (new Divi4())->transforma($embed('Pr0baS3cr1', '4242424242'), 'https://pensiune-proba.test', []);
$verifica('secret: în codul de încorporare al cardului embed și în oEmbed, egal la comparație; în alt textarea, în text, în atribut, în alt răspuns — diferit; T îl păstrează',
    $compara($embed('Pr0baS3cr1', '1'), $embed('Q9w8e7r6t5', '1')) && !$compara($embed('Pr0baS3cr1', '1', 'alta'), $embed('Q9w8e7r6t5', '1', 'alta'))
    && !$compara('<p>data-secret=&quot;Pr0baS3cr1&quot;</p>', '<p>data-secret=&quot;Q9w8e7r6t5&quot;</p>')
    && !$compara('<div data-secret="Pr0baS3cr1"></div>', '<div data-secret="Q9w8e7r6t5"></div>')
    && $n->raspuns('{"html":"<iframe src=\"x#?secret=Pr0baS3cr1\" data-secret=\"Pr0baS3cr1\">"}', 'oembed') === $n->raspuns('{"html":"<iframe src=\"x#?secret=Q9w8e7r6t5\" data-secret=\"Q9w8e7r6t5\">"}', 'oembed')
    && $n->raspuns('{"html":"data-secret=\"Pr0baS3cr1\""}') !== $n->raspuns('{"html":"data-secret=\"Q9w8e7r6t5\""}')
    && $tE->probleme === [] && str_contains($tE->html, 'data-secret=&quot;Pr0baS3cr1&quot;') && str_contains($tE->html, '#?secret=Pr0baS3cr1') && $farFeluri($tE), json_encode($tE->probleme));
$obf = static fn (string $email, string $cls = 'cmplz-obfuscate'): string => '<!doctype html><p>Email: <span class="' . $cls . '">' . $email . '<span class="cmplz-fmail-domain">.test</span></span> &#60;b&#62;</p>';
$tO = (new Divi4())->transforma($obf('c&#111;nt&#097;ct@ex&#097;mple'), 'https://pensiune-proba.test', []);
$areFel = static fn (array $ed, string $fel): bool => array_filter($ed, static fn (array $e): bool => in_array($fel, $e['fel'], true)) !== [];
$verifica('entitati-text: doar în span.cmplz-obfuscate (și în span-urile lui), egal la comparație; în alt span, în text, în atribut — diferit; T neatins; fanionul lui, separat de comparație',
    $compara($obf('c&#111;nt&#097;ct@ex&#097;mple'), $obf('co&#110;ta&#099;t@exampl&#101;')) && !$compara($obf('c&#111;ntact@example', 'alta'), $obf('co&#110;tact@example', 'alta'))
    && !$compara('<p>c&#111;ntact</p>', '<p>co&#110;tact</p>') && !$compara('<a title="c&#111;ntact">x</a>', '<a title="co&#110;tact">x</a>')
    && str_contains($n->document($obf('c&#111;nt&#097;ct@ex&#097;mple')), '>contact@example<span class="cmplz-fmail-domain">.test</span></span> &#60;b&#62;')
    && str_contains($tO->html, 'c&#111;nt&#097;ct@ex&#097;mple') && $farFeluri($tO)
    && !$areFel($n->editari($obf('c&#111;nt&#097;ct@ex&#097;mple'), null, true), 'entitati-text')
    && $areFel($n->editari($obf('c&#111;nt&#097;ct@ex&#097;mple'), null, false, [], true), 'entitati-text'));
$verifica('id-aleator-embed: doar valoarea ÎNTREAGĂ a unui id / aria-controls / aria-describedby al taburilor cardului; cu alt sufix, în class, în text — diferit; T neatins',
    $compara($embed('Pr0baS3cr1', '4242424242'), $embed('Pr0baS3cr1', '17')) && !$compara('<p id="wp-embed-share-tab-html-2-123-x"></p>', '<p id="wp-embed-share-tab-html-2-456-x"></p>')
    && !$compara('<p class="wp-embed-share-tab-html-2-123"></p>', '<p class="wp-embed-share-tab-html-2-456"></p>') && !$compara('<p>wp-embed-share-tab-html-2-123</p>', '<p>wp-embed-share-tab-html-2-456</p>')
    && str_contains($tE->html, 'id="wp-embed-share-tab-wordpress-2-4242424242"') && $n->editari($embed('Pr0baS3cr1', '4242424242')) === []);
$verifica('id-aleator-embed: aria-controls și id-ul panoului cu valori diferite (legătura ruptă) nu sunt egale cu un card întreg și nici între ele; un card întreg dă {{ax:aleator:1}}',
    !$compara($embed('Pr0baS3cr1', '1111', 'wp-embed-share-input', '2222'), $embed('Pr0baS3cr1', '3333'))
    && !$compara($embed('Pr0baS3cr1', '1111', 'wp-embed-share-input', '2222'), $embed('Pr0baS3cr1', '5555', 'wp-embed-share-input', '6666'))
    && substr_count($n->document($embed('Pr0baS3cr1', '7')), '{{ax:aleator:1}}') === 4 && !str_contains($n->document($embed('Pr0baS3cr1', '7')), '{{ax:aleator:2}}'));
$com = static fn (string $ora, string $de = 'This sitemap was dynamically generated on', string $rest = "<urlset><url><lastmod>2031-03-03T09:%s:00+00:00</lastmod></url></urlset>"): string
    => '<?xml version="1.0" encoding="UTF-8"?>' . "\r\n<!-- " . $de . ' March 3, 2031 at ' . $ora . " am by All in One SEO v9.8.7.6 - the original SEO plugin for WordPress. -->\r\n" . sprintf($rest, substr($ora, -2));
$rs = static fn (string $corp, string $cale = '/sitemap.xml'): string => $n->raspuns($corp, 'raspuns', $cale);
$verifica('ora-generarii: doar comentariul întreg al generatorului, doar pe un sitemap (calea și rădăcina <urlset>/<sitemapindex>); altă formulare, <lastmod>, un feed, un corp fără rădăcină — diferite',
    str_contains($rs($com('9:14')), 'generated on {{ax:data}} by All in One SEO') && !str_contains($rs($com('9:14', 'generated on')), '{{ax:data}}')
    && $rs($com('9:14')) !== $rs($com('9:15')) && $rs($com('9:14', rest: '<urlset></urlset>')) === $rs($com('9:15', rest: '<urlset></urlset>'))
    && str_contains($rs($com('9:14', rest: '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></sitemapindex>'), '/page-sitemap.xml'), '{{ax:data}}')
    && !str_contains($rs($com('9:14', rest: '<urlset></urlset>'), '/feed/'), '{{ax:data}}') && !str_contains($rs($com('9:14', rest: '<rss></rss>')), '{{ax:data}}'));
if (class_exists(\App\Support\CadruCapturat\Jetoane::class)) {
    $verifica('fail-closed: o captură care conține deja „{{ax:” (oricum scris) e NU la dialect, fără T', ($rJ = (new Divi4())->transforma('<!doctype html><p>{{AX:cale}}</p>', 'https://pensiune-proba.test', []))->html === ''
        && str_contains(implode(' ', $rJ->probleme), '{{ax:') && (new Divi4())->transforma('<!doctype html><p>{ {ax:cale}}</p>', 'https://pensiune-proba.test', [])->probleme === []);
} else {
    $scrie('  SĂRIT fail-closed la dialect: vine cu App\Support\CadruCapturat\Jetoane (pista I); la captură îl verifică testul de mai sus și „/jeton/”');
}

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== Captura prin HTTP pe site-ul de probă (6 UA × 2), comparația pe UA, ieșirea');
$verifica('ieșirea în repo e refuzată', ($e = $arunca(static fn () => new Captura($site, $radacina . '/storage/logs/dev/captura', new Normalizare($site)))) !== null && $e->fel === 'scriere');
@symlink($radacina . '/storage', $tmp . '/spre-repo');
@symlink($radacina . '/nu-exista-inca', $tmp . '/fara-tinta');
$cwd = getcwd();
chdir($radacina);
$cazuriIesire = [
    'relativă, din repo' => ['storage/captura-x', false],
    'printr-o legătură spre repo' => [$tmp . '/spre-repo/captura-x', false],
    '„<legătură>/..” (nucleul rezolvă întâi legătura)' => [$tmp . '/spre-repo/../docs', false],
    'printr-o legătură fără țintă' => [$tmp . '/fara-tinta/captura-x', false],
    '„..” care iese din repo' => [$radacina . '/../' . basename(dirname($radacina)) . '-captura-proba-' . getmypid(), true],
    '„..” în afara repo-ului' => [$tmp . '/a/b/../c', true],
];
$gresite = [];
foreach ($cazuriIesire as $caz => [$cale, $acceptat]) {
    $e = $arunca(static fn () => Captura::verificaIesirea($cale));
    if (($e === null) !== $acceptat) {
        $gresite[] = $caz;
    }
}
chdir((string) $cwd);
$verifica('ieșirea: calea reală (legăturile rezolvate înainte de „..”), deci repo-ul e refuzat oricum ar fi scris; o legătură fără țintă e refuzată', $gresite === []
    && Captura::verificaIesirea($tmp . '/a/b/../c') === realpath($tmp) . '/a/c', json_encode($gresite));
mkdir($tmp . '/schimbat/x', 0770, true);
$capS = new Captura($site, $tmp . '/schimbat/x', new Normalizare($site));
rename($tmp . '/schimbat', $tmp . '/schimbat-vechi');
mkdir($tmp . '/altundeva-x/x', 0770, true);
symlink($tmp . '/altundeva-x', $tmp . '/schimbat');
$verifica('ieșirea schimbată după verificare (o legătură pusă între timp): refuzată înainte de orice cerere sau scriere',
    ($e = $arunca(static fn () => $capS->pregatesteIesirea())) !== null && $e->fel === 'scriere');
@mkdir($tmp . '/git-proba/public', 0770, true);
exec('git -C ' . escapeshellarg($tmp . '/git-proba') . ' init -q 2>&1', $iesireGit, $codGit);
$faraIgnorare = $arunca(static fn () => CopiereStatica::verificaTinta($tmp . '/git-proba/public'));
file_put_contents($tmp . '/git-proba/.gitignore', "/public/wp-content/\n/public/wp-includes/\n/public/capturat/\n");
$verifica('--public într-un arbore git: refuzat cât wp-content/, wp-includes/ și capturat/ nu sunt ignorate; acceptat după; și public/ din repo (.gitignore)',
    $codGit === 0 && $faraIgnorare !== null && $faraIgnorare->fel === 'folosire' && CopiereStatica::verificaTinta($tmp . '/git-proba/public') === realpath($tmp) . '/git-proba/public'
    && $arunca(static fn () => CopiereStatica::verificaTinta($radacina . '/public')) === null && CopiereStatica::verificaTinta($tmp . '/public') === realpath($tmp) . '/public',
    (string) $faraIgnorare?->getMessage());
$cap = new Captura($site, $tmp . '/iesire', new Normalizare($site));
$cereri = new Cereri([$gazda], 200, 0.0);
$progres = [];
$cap->prinHttp(ListaAdrese::dinFisier($tmp . '/lista.json', $site, 'abc123'), $cereri, 6, static function (string $f, string $t) use (&$progres): void {
    $progres[] = $f . ' ' . $t;
});
$intrare = static function (string $cale) use ($cap): ?array {
    foreach ($cap->adrese as $a) {
        if ($a['cale'] === $cale) {
            return $a;
        }
    }
    return null;
};
$acasa = $intrare('/');
$verifica('/: 12 răspunsuri, identice după normalizare (nonce-uri și clase de sistem diferite pe fiecare)', $acasa !== null && count($acasa['raspunsuri']) === 12 && $acasa['identice']
    && count(array_unique(array_column($acasa['raspunsuri'], 'sha256'))) > 1 && count(array_unique(array_column($acasa['raspunsuri'], 'sha256_normalizat'))) === 1, json_encode($acasa['diferente'] ?? null));
$dif = $intrare('/diferita/');
$verifica('/diferita/: NU, diferă după UA, cu diferența arătată', $dif !== null && !$dif['identice'] && in_array('dupa-ua', array_column($dif['diferente'], 'fel'), true)
    && array_filter($cap->probleme, static fn (string $p): bool => str_contains($p, '/diferita/') && str_contains($p, 'după UA') && str_contains($p, 'Versiunea')) !== []);
$verifica('/diferita/: fiecare UA dă aceiași octeți la ambele cereri, deci diferența e pusă pe seama UA-ului', $dif !== null
    && array_unique(array_column($dif['diferente'], 'fel')) === ['dupa-ua']);
$ale = $intrare('/aleatoare/');
$verifica('/aleatoare/: NU, diferă la fiecare cerere (o valoare care nu e nonce), nu „după UA”', $ale !== null
    && array_unique(array_column($ale['diferente'], 'fel')) === ['la-fiecare-cerere']);
$obfC = $intrare('/obfuscat/');
$verifica('/obfuscat/: emailul obfuscat aleator la fiecare cerere, identic după normalizarea de comparație', $obfC !== null && $obfC['identice']
    && count(array_unique(array_column($obfC['raspunsuri'], 'sha256'))) > 1);
$p404 = $intrare('/abc123-inexistent/');
$verifica('404: statusul și șablonul păstrate', $p404 !== null && $p404['status'] === 404 && $p404['identice']);
$emb = $intrare('/camere/embed/');
$verifica('embed: secretele și ID-urile taburilor diferite la fiecare cerere, egale după normalizare', $emb !== null && $emb['identice']
    && count(array_unique(array_column($emb['raspunsuri'], 'sha256'))) > 1);
$red = $intrare('/embed/');
$verifica('/embed/: 301 cu Location păstrat, neurmat', $red !== null && $red['status'] === 301 && $red['location'] === $site . '/' && $red['identice']);
$capJ = new Captura($site, $tmp . '/iesire-jeton', new Normalizare($site));
$capJ->prinHttp([['fel' => 'pagina', 'cale' => '/jeton/', 'url' => $site . '/jeton/']], new Cereri([$gazda], 12, 0.0), 6, static function (): void {
});
$verifica('o pagină care conține deja „{{ax:” e NU la captură (fail-closed), cu răspunsul și octetul arătate', ($capJ->adrese[0]['identice'] ?? true) === false
    && array_filter($capJ->probleme, static fn (string $p): bool => str_contains($p, '/jeton/') && str_contains($p, '{{ax:') && str_contains($p, 'octetul')) !== [], json_encode($capJ->probleme));
$rob = $intrare('/robots.txt');
$brut = $rob !== null ? (string) file_get_contents($tmp . '/iesire/' . $rob['referinta']) : '';
$verifica('robots.txt: octeții exacți (CRLF, fără newline final)', str_contains($brut, "\r\nDisallow: /wp-admin/\r\n") && !str_ends_with($brut, "\n") && $rob['identice']);
$verifica('sitemap.xml: identic după ora generării; sub-sitemap-ul și XSL-ul găsite și capturate', ($intrare('/sitemap.xml')['identice'] ?? false)
    && $intrare('/page-sitemap.xml') !== null && $intrare('/default-sitemap.xsl?sitemap=root') !== null,
    implode(' | ', array_column($cap->adrese, 'cale')));
$oe = $intrare('/camere/');
$verifica('oEmbed (fel separat, adresa REST): capturat, egal după normalizarea secretelor', array_filter($cap->adrese, static fn (array $a): bool => $a['fel'] === 'oembed' && $a['identice']) !== []);
$manifest = $cap->manifest(null, null);
$raport = $cap->raport($cereri);
$verifica('ieșirea: index.json, manifest.json, raport-captura.json, brut/<sha>/ cu .corp și .antete; nimic în altă parte',
    is_file($tmp . '/iesire/index.json') && is_file($tmp . '/iesire/manifest.json') && is_file($tmp . '/iesire/raport-captura.json')
    && is_file($tmp . '/iesire/' . $acasa['referinta']) && is_file(substr($tmp . '/iesire/' . $acasa['referinta'], 0, -5) . '.antete')
    && array_diff(scandir($tmp . '/iesire'), ['.', '..', 'brut', 'index.json', 'manifest.json', 'raport-captura.json']) === []);
$verifica('manifestul: paginile cu sha256-ul răspunsului de referință; raportul cu sha256-ul manifestului',
    in_array($acasa['sha256'], array_column($manifest['pagini'], 'sha256'), true) && $raport['sha256_manifest'] === hash_file('sha256', $tmp . '/iesire/manifest.json'));
$verifica('manifestul: resursele proprii (foi, scripturi, poze din srcset și din multi-view) găsite de analiza statică',
    count(array_filter(array_column($manifest['lipsa'], 'url'), static fn (string $u): bool => str_contains($u, '/wp-content/uploads/2024/05/foisor.png')
        || str_contains($u, 'curtea-480x320.jpg') || str_contains($u, 'scripts.min.js') || str_contains($u, 'et-proba-late.css'))) === 4, json_encode($manifest['lipsa']));
$verifica('manifestul: trackerul cerut de pagini apare la gazdele externe, marcat tracker (nu s-a cerut)', array_filter($manifest['externe'],
    static fn (array $e): bool => $e['gazda'] === 'www.googletagmanager.com' && $e['tracker']) !== []);
$toate = $cereriServer();
$verifica('toate cererile capturii: GET, fără cookie, în buget (' . $cereri->facute() . ' ≤ 200), câte una pe UA și repetare',
    array_filter($toate, static fn (array $x): bool => $x['metoda'] !== 'GET' || $x['cookie']) === [] && $cereri->facute() === count($cereri->jurnal()) && $cereri->facute() <= 200);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== Dosarul capturilor (--din-dosar): captura noastră și o oglindă cu descrieri și hartă');
$d = DosarCapturi::deschide($tmp . '/iesire');
$verifica('captura noastră: răspunsul de referință după adresă și după cale', $d->alNostru && $d->dupaCale('/camere/')?->sha256() === $intrare('/camere/')['sha256']
    && $d->url === $site);
mkdir($tmp . '/oglinda/a', 0770, true);
file_put_contents($tmp . '/oglinda/a/foaie', 'body{}');
file_put_contents($tmp . '/oglinda/a/foaie.meta.json', json_encode(['url' => $site . '/wp-content/themes/Divi/style.css?ver=4.27.4', 'status' => 200, 'headers' => ['content-type' => 'text/css']]));
file_put_contents($tmp . '/oglinda/pagina.html', '<!doctype html><p>x</p>');
file_put_contents($tmp . '/oglinda/pagina.headers.txt', "HTTP/1.1 200 Connection Established\r\n\r\nHTTP/2 200 \r\ncontent-type: text/html\r\n");
file_put_contents($tmp . '/harta.json', json_encode([$site . '/x/' => 'pagina.html']));
$o = DosarCapturi::deschide($tmp . '/oglinda', $tmp . '/harta.json');
$verifica('oglinda: fișierul descris de .meta.json, găsit după adresă (cu entități)', $o->cauta($site . '/wp-content/themes/Divi/style.css?ver=4.27.4')['fisier'] === $tmp . '/oglinda/a/foaie');
$verifica('oglinda: fișierul din hartă, cu statusul din blocul curl (după proxy)', $o->raspuns($site . '/x/')?->status === 200 && $o->raspuns($site . '/x/')->tip() === 'text/html');
file_put_contents($tmp . '/harta-rea.json', json_encode([$site . '/y/' => '../afara']));
$verifica('harta: o cale ieșită din dosar e refuzată', $arunca(static fn () => DosarCapturi::deschide($tmp . '/oglinda', $tmp . '/harta-rea.json')) !== null);
$capV = new Captura($site, $tmp . '/iesire-lant', new Normalizare($site));
$cV = new Cereri([$gazda], 20, 0.0, 3, 6, 600, $tmp . '/iesire-lant/' . Captura::JURNAL);
$capV->prinHttp([['fel' => 'raspuns', 'cale' => '/robots.txt', 'url' => $site . '/robots.txt'], ['fel' => 'pagina', 'cale' => '/camere/', 'url' => $site . '/camere/']],
    $cV, 1, static function (): void {
    }, false);
$capV->manifest();
$rV = $capV->raport($cV, ['mod' => 'proba']);
$lant = static function (string $dosar, string $sha, ?string $url = null) use ($site): string {
    try {
        DosarCapturi::deschideVerificat($dosar, $sha, $url ?? $site);
        return 'ok';
    } catch (EroareCaptura $e) {
        return $e->fel . ': ' . $e->getMessage();
    }
};
$copie = static function (string $nume) use ($tmp): string {
    exec('rm -rf ' . escapeshellarg($tmp . '/' . $nume) . ' && cp -a ' . escapeshellarg($tmp . '/iesire-lant') . ' ' . escapeshellarg($tmp . '/' . $nume));
    return $tmp . '/' . $nume;
};
$dV = DosarCapturi::deschideVerificat($tmp . '/iesire-lant', $rV['sha256_raport'], $site);
// Recenzia 2, R1-2: o captură care a ieșit cu NU nu se spală prin --din-dosar (probleme sau o oprire în raport, o adresă neidentică).
$dNu = $copie('iesire-nu');
$rNu = json_decode((string) file_get_contents($dNu . '/raport-captura.json'), true);
$rNu['probleme'] = ['/camere/: diferă după UA'];
file_put_contents($dNu . '/raport-captura.json', json_encode($rNu));
$dOp = $copie('iesire-oprita');
$rOp = json_decode((string) file_get_contents($dOp . '/raport-captura.json'), true);
$rOp['oprit'] = 'bugetul s-a terminat';
file_put_contents($dOp . '/raport-captura.json', json_encode($rOp));
$verifica('--din-dosar: o captură cu probleme sau oprită (raportul ei) e NU (lant), oricât de întreg ar fi lanțul',
    str_starts_with($lant($dNu, hash_file('sha256', $dNu . '/raport-captura.json')), 'lant: captura urcată nu trece verificarea: captura a ieșit cu NU')
    && str_starts_with($lant($dOp, hash_file('sha256', $dOp . '/raport-captura.json')), 'lant: captura urcată nu trece verificarea: captura a ieșit cu NU'));
$verifica('captura urcată verificată: sha256-ul raportului → indexul, manifestul, jurnalul → fiecare fișier din brut/; răspunsurile ei, ca la captură',
    $dV->dupaCale('/camere/')?->sha256() === $capV->adrese[1]['sha256'] && isset($rV['sha256_fisiere'][Captura::JURNAL])
    && count((array) json_decode((string) file_get_contents($tmp . '/iesire-lant/manifest.json'), true)['brut']) >= 6);
$cazuriLant = [];
$cazuriLant['alt sha256 al raportului'] = $lant($tmp . '/iesire-lant', str_repeat('0', 64));
$d1 = $copie('lant-corp');
file_put_contents($d1 . '/' . $capV->adrese[1]['referinta'], '<!doctype html><p>schimbat după urcare</p>');
$cazuriLant['un corp schimbat'] = $lant($d1, $rV['sha256_raport']);
$d2 = $copie('lant-in-plus');
file_put_contents($d2 . '/brut/in-plus.corp', 'x');
$cazuriLant['un fișier în plus în brut/'] = $lant($d2, $rV['sha256_raport']);
$d3 = $copie('lant-legatura');
symlink($tmp . '/lista.json', $d3 . '/brut/legatura.corp');
$cazuriLant['o legătură în brut/'] = $lant($d3, $rV['sha256_raport']);
$d4 = $copie('lant-jurnal');
file_put_contents($d4 . '/' . Captura::JURNAL, "{}\n", FILE_APPEND);
$cazuriLant['jurnalul schimbat'] = $lant($d4, $rV['sha256_raport']);
$cazuriLant['alt site'] = $lant($tmp . '/iesire-lant', $rV['sha256_raport'], 'https://alt-site.example.test');
// Un lanț întreg, dar ostil (cineva care a schimbat și raportul și ne dă sha256-ul lui): referința în afara dosarului tot e refuzată.
$d5 = $copie('lant-ostil');
file_put_contents($tmp . '/afara-lant.txt', 'din afara dosarului');
$ix = (array) json_decode((string) file_get_contents($d5 . '/index.json'), true);
$ix['adrese'][0]['referinta'] = '../afara-lant.txt';
file_put_contents($d5 . '/index.json', json_encode($ix));
$mx = (array) json_decode((string) file_get_contents($d5 . '/manifest.json'), true);
$mx['brut'][] = ['fisier' => 'brut/../../afara-lant.txt', 'sha256' => hash_file('sha256', $tmp . '/afara-lant.txt'), 'marime' => 1];
file_put_contents($d5 . '/manifest.json', json_encode($mx));
$rx = (array) json_decode((string) file_get_contents($d5 . '/raport-captura.json'), true);
$rx['sha256_fisiere']['index.json'] = hash_file('sha256', $d5 . '/index.json');
$rx['sha256_fisiere']['manifest.json'] = hash_file('sha256', $d5 . '/manifest.json');
file_put_contents($d5 . '/raport-captura.json', json_encode($rx));
$cazuriLant['lanț întreg, dar cu o referință în afara dosarului'] = $lant($d5, hash_file('sha256', $d5 . '/raport-captura.json'));
$neprinse = array_keys(array_filter($cazuriLant, static fn (string $r): bool => !str_starts_with($r, 'lant: ')));
$verifica('captura urcată refuzată (lant) la: alt sha256 al raportului, un corp schimbat, un fișier în plus, o legătură, jurnalul schimbat, alt site, un lanț ostil',
    $neprinse === [], json_encode($cazuriLant, JSON_UNESCAPED_UNICODE));
$verifica('și fără verificare (deschide(), importul): o referință în afara dosarului nu se citește', ($e = $arunca(static fn () => DosarCapturi::deschide($d5))) !== null
    && str_contains($e->getMessage(), 'afara-lant.txt'));
$cap2 = new Captura($site, $tmp . '/iesire2', new Normalizare($site));
$cap2->dinDosar(ListaAdrese::dinFisier($tmp . '/lista.json', $site, 'abc123'), $d, static function (): void {
});
$verifica('din dosar: fără rețea, fără comparație pe UA (o variantă pe adresă), aceleași octeți', $cap2->adrese[0]['sha256'] === $acasa['sha256']
    && str_contains(implode(' ', $cap2->info), 'sărită'));
$m2 = $cap2->manifest(null, $o);
$verifica('manifestul din oglindă: sha256-ul fișierului din oglindă', array_filter($m2['fisiere'], static fn (array $f): bool => str_contains($f['url'], 'style.css')
    && $f['sha256'] === hash('sha256', 'body{}') && $f['sursa'] === 'oglinda') !== []);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== Copierea statică: doar tipurile permise, fără .js/.php, fără legături, fără cod PHP, SVG prin SvgSigur');
// Poze reale, minuscule: semnătura și antetul lor trec de getimagesize(); octeții de după ele sunt ai probei. PNG, JPEG, GIF și
// WebP din GD; ICO (cu un PNG înăuntru) și antetul unui AVIF (ftyp, meta: hdlr, pitm, iinf, iprp cu ispe/pixi/av1C și ipma), de mână.
$poza = static function (string $fel): string {
    $im = imagecreatetruecolor(2, 2);
    ob_start();
    match ($fel) { 'png' => imagepng($im), 'jpg' => imagejpeg($im), 'gif' => imagegif($im), 'webp' => imagewebp($im) };
    return (string) ob_get_clean();
};
$png = $poza('png');
$ico = pack('vvv', 0, 1, 1) . pack('CCCCvvVV', 2, 2, 0, 0, 1, 32, strlen($png), 22) . $png;
$cutie = static fn (string $t, string $p): string => pack('N', 8 + strlen($p)) . $t . $p;
$cutieP = static fn (string $t, string $p): string => $cutie($t, "\0\0\0\0" . $p);
$avif = $cutie('ftyp', 'avif' . pack('N', 0) . 'mif1avifmiaf') . $cutieP('meta', $cutieP('hdlr', pack('N', 0) . 'pict' . str_repeat("\0", 13))
    . $cutieP('pitm', pack('n', 1)) . $cutieP('iinf', pack('n', 1) . $cutie('infe', "\x02\0\0\0" . pack('nn', 1, 0) . "av01\0"))
    . $cutie('iprp', $cutie('ipco', $cutieP('ispe', pack('NN', 2, 2)) . $cutieP('pixi', "\x03\x08\x08\x08") . $cutie('av1C', "\x81\x00\x0c\x00"))
    . $cutieP('ipma', pack('N', 1) . pack('n', 1) . "\x03\x81\x02\x83")));
$sr = $tmp . '/sursa';
foreach (['wp-content/themes/Divi/style.css' => 'a{background:url(' . $site . '/wp-content/uploads/a.png)}', 'wp-content/uploads/a.png' => $png . "proba",
    'wp-content/uploads/rau.png' => $png . "<?php system('x');", 'wp-content/plugins/p/p.php' => '<?php echo 1;', 'wp-content/plugins/p/p.js' => 'alert(1)',
    'wp-content/uploads/rau.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'wp-content/uploads/bun.svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path d="M0 0h1v1z"/></svg>',
    'wp-content/uploads/.ascuns/x.png' => "\x89PNG", 'altundeva/secret.png' => "\x89PNG"] as $rel => $c) {
    @mkdir(dirname($sr . '/' . $rel), 0770, true);
    file_put_contents($sr . '/' . $rel, $c);
}
symlink($sr . '/altundeva/secret.png', $sr . '/wp-content/uploads/legatura.png');
$cssCopiat = CopiereStatica::copiaza($sr, $tmp . '/public', 'wp-content/themes/Divi/style.css', new Normalizare($site));
$verifica('CSS copiat, cu adresele proprii relative; ambele sha256 întoarse: al sursei și al fișierului publicat (rescris)',
    $cssCopiat['sha256_publicat'] === hash_file('sha256', $tmp . '/public/wp-content/themes/Divi/style.css') && $cssCopiat['sha256_sursa'] === hash_file('sha256', $sr . '/wp-content/themes/Divi/style.css')
    && $cssCopiat['sha256_sursa'] !== $cssCopiat['sha256_publicat'] && $cssCopiat['publicat'] === '/wp-content/themes/Divi/style.css'
    && file_get_contents($tmp . '/public/wp-content/themes/Divi/style.css') === 'a{background:url(/wp-content/uploads/a.png)}');
$pngCopiat = CopiereStatica::copiaza($sr, $tmp . '/public', '/wp-content/uploads/a.png');
@mkdir($sr . '/wp-content/themes/proba', 0770, true);
file_put_contents($sr . '/wp-content/themes/proba/importa.css', "@import url('https://fonts.googleapis.com/css?family=Proba+Sans:400&display=swap');\nbody{color:#111}\n");
CopiereStatica::copiaza($sr, $tmp . '/public', '/wp-content/themes/proba/importa.css');
$cssImport = (string) @file_get_contents($tmp . '/public/wp-content/themes/proba/importa.css');
$verifica('copierea: un @import de fonturi Google dintr-o foaie proprie trece pe copia locală (aceeași cheie ca în pagini; recenzia 2, R1-8)',
    str_contains($cssImport, '@import url("' . FonturiGoogle::cale('https://fonts.googleapis.com/css?family=Proba+Sans:400&display=swap') . '")')
    && !str_contains($cssImport, 'googleapis') && FonturiGoogle::cheie('http://fonts.googleapis.com/css?family=X') === FonturiGoogle::cheie('//fonts.googleapis.com/css?family=X')
    && FonturiGoogle::cheie('//fonts.googleapis.com/css?family=X') === FonturiGoogle::cheie('https://fonts.googleapis.com/css?family=X'), $cssImport);
copy($sr . '/wp-content/uploads/a.png', $sr . '/wp-content/uploads/poza.php.png');
copy($sr . '/wp-content/uploads/a.png', $sr . '/wp-content/uploads/poza.PHTML.jpg.png');
$verifica('copierea: un nume cu o extensie executabilă înăuntru (poza.php.png, poza.PHTML.jpg.png) e refuzat, ca la Media (recenzia 2, R1-5)',
    $arunca(static fn () => CopiereStatica::copiaza($sr, $tmp . '/public', '/wp-content/uploads/poza.php.png')) !== null
    && $arunca(static fn () => CopiereStatica::copiaza($sr, $tmp . '/public', '/wp-content/uploads/poza.PHTML.jpg.png')) !== null
    && !file_exists($tmp . '/public/wp-content/uploads/poza.php.png'));
$verifica('PNG copiat neschimbat (sha256-ul sursei = al publicatului)', $pngCopiat['sha256_publicat'] === hash('sha256', $png . 'proba') && $pngCopiat['sha256_sursa'] === $pngCopiat['sha256_publicat']);
foreach (['wp-content/plugins/p/p.php' => 'tip nepermis', 'wp-content/plugins/p/p.js' => 'tip nepermis', 'wp-content/uploads/rau.png' => 'cod PHP',
    'wp-content/uploads/rau.svg' => 'SvgSigur', 'wp-content/uploads/legatura.png' => 'legătură', 'wp-content/uploads/.ascuns/x.png' => 'ascuns',
    'wp-content/../altundeva/secret.png' => '„..”'] as $rel => $motiv) {
    $p = CopiereStatica::problema($sr, $rel);
    $verifica('refuzat (' . $motiv . '): ' . $rel, $p !== null && str_contains($p, $motiv) && !is_file($tmp . '/public/' . $rel), (string) $p);
}
$verifica('SVG curat: trece', CopiereStatica::problema($sr, 'wp-content/uploads/bun.svg') === null);
foreach (['wp-content/uploads/dublu.php.png' => $png . "<?php echo 1; ?>", 'wp-content/uploads/scurt.css' => "a{}\n<? echo 1; ?>", 'wp-content/uploads/MARE.PHP' => 'x',
    'wp-content/uploads/b.PNG' => $png . "proba-mare", 'wp-content/uploads/C.SVG' => '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>',
    'wp-content/uploads/fara-extensie' => 'x', 'wp-content/uploads/x.phtml' => 'x', 'wp-content/uploads/x.pht' => 'x', 'wp-content/uploads/x.shtml' => 'x',
    'wp-content/uploads/.user.ini' => 'x', 'wp-content/uploads/.htaccess' => 'x', 'favicon.ico' => $ico . 'proba', 'logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>',
    'wp-admin/images/w.png' => $png . "admin", 'assets/a.png' => $png . "ciocnire", 'blog/b.png' => $png . "ruta"] as $rel => $c) {
    @mkdir(dirname($sr . '/' . $rel), 0770, true);
    file_put_contents($sr . '/' . $rel, $c);
}
@mkdir($tmp . '/afara-wp', 0770, true);
file_put_contents($tmp . '/afara-wp/nu-public.txt', 'în afara rădăcinii WordPress');
link($tmp . '/afara-wp/nu-public.txt', $sr . '/wp-content/uploads/tare.png');
$refuzuri = ['wp-content/uploads/dublu.php.png' => 'extensie executabilă', 'wp-content/uploads/scurt.css' => 'cod PHP', 'wp-content/uploads/MARE.PHP' => 'tip nepermis',
    'wp-content/uploads/fara-extensie' => 'tip nepermis', 'wp-content/uploads/x.phtml' => 'tip nepermis', 'wp-content/uploads/x.pht' => 'tip nepermis',
    'wp-content/uploads/x.shtml' => 'tip nepermis', 'wp-content/uploads/.user.ini' => 'ascuns', 'wp-content/uploads/.htaccess' => 'ascuns', 'wp-content/uploads/tare.png' => 'legătură tare'];
$gresite = [];
foreach ($refuzuri as $rel => $motiv) {
    $p = CopiereStatica::problema($sr, $rel);
    if ($p === null || !str_contains($p, $motiv)) {
        $gresite[] = $rel . ' → ' . ($p ?? 'acceptat');
    }
}
$verifica('refuzate: extensia dublă cu „<?php”, „<?” urmat de spațiu într-o foaie, .PHP cu majuscule, fără extensie, .phtml/.pht/.shtml, .user.ini, .htaccess, legătura tare (nlink 2)',
    $gresite === [], json_encode($gresite, JSON_UNESCAPED_UNICODE));
// Un PNG valid cu „<?=” în datele comprimate (un chunk IDAT în plus, cu CRC corect, înaintea lui IEND), ca în pozele reale.
$idat = 'IDAT' . "\x78\x9c<?=\x00<?=";
$pngIdat = substr($png, 0, -12) . pack('N', strlen($idat) - 4) . $idat . pack('N', crc32($idat)) . substr($png, -12);
$imagini = [
    'wp-content/uploads/sansa.png' => [$png . "\x00<? \x01<?\n", null], 'wp-content/uploads/sansa.jpg' => [$poza('jpg') . "<?\t", null],
    'wp-content/uploads/sansa.gif' => [$poza('gif') . '<? ', null], 'wp-content/uploads/sansa.webp' => [$poza('webp') . "<?\r", null],
    'wp-content/uploads/sansa.ico' => [$ico . "<?\x0B", null], 'wp-content/uploads/sansa.avif' => [$avif . '<? ', null],
    'wp-content/uploads/php.png' => [$png . '<?PhP echo 1;', 'cod PHP'], 'wp-content/uploads/egal.webp' => [$poza('webp') . '<?=1', null],
    'wp-content/uploads/egal-idat.png' => [$pngIdat, null],
    'wp-content/uploads/jpeg-ca.png' => [$poza('jpg'), 'semnătura'], 'wp-content/uploads/png-ca.avif' => [$png, 'semnătura'],
    'wp-content/uploads/trunchiat.jpg' => ["\xFF\xD8\xFF\xE0" . str_repeat('x', 40), 'antetul'], 'wp-content/uploads/gol.gif' => ['', 'semnătura'],
    'wp-content/uploads/sansa.css' => ['a{}<? b', 'cod PHP'],
];
$gresite = [];
foreach ($imagini as $rel => [$c, $motiv]) {
    file_put_contents($sr . '/' . $rel, $c);
    $p = CopiereStatica::problema($sr, $rel);
    if ($motiv === null ? $p !== null : ($p === null || !str_contains($p, $motiv))) {
        $gresite[] = $rel . ' → ' . ($p ?? 'acceptat');
    }
}
$verifica('pozele (PNG, JPEG, GIF, WebP, ICO, AVIF) cu semnătura extensiei și antetul acceptat de getimagesize(): „<?” + spațiu și „<?=” printre octeți (și în IDAT) '
    . 'trec, „<?php” (orice litere) nu; semnătura altui tip, un antet stricat sau un fișier gol refuzate; în CSS „<?” + spațiu rămâne refuzat',
    $gresite === [], json_encode($gresite, JSON_UNESCAPED_UNICODE));
$verifica('acceptate cu majuscule în extensie (b.PNG, C.SVG prin SvgSigur), la aceeași cale sub wp-content/', CopiereStatica::problema($sr, 'wp-content/uploads/b.PNG') === null
    && CopiereStatica::problema($sr, 'wp-content/uploads/C.SVG') === null && CopiereStatica::tinta('/wp-content/uploads/b.PNG') === '/wp-content/uploads/b.PNG');
$rad = [];
foreach (['favicon.ico', 'logo.svg', 'wp-admin/images/w.png'] as $rel) {
    $rad[$rel] = CopiereStatica::copiaza($sr, $tmp . '/public', $rel);
}
$verifica('fișierele proprii din afara wp-content/ și wp-includes/ se copiază doar sub capturat/radacina/<cale>, niciodată la rădăcina docroot-ului',
    $rad['favicon.ico']['publicat'] === '/capturat/radacina/favicon.ico' && is_file($tmp . '/public/capturat/radacina/favicon.ico') && !file_exists($tmp . '/public/favicon.ico')
    && $rad['logo.svg']['publicat'] === '/capturat/radacina/logo.svg' && $rad['wp-admin/images/w.png']['publicat'] === '/capturat/radacina/wp-admin/images/w.png'
    && !file_exists($tmp . '/public/wp-admin') && !file_exists($tmp . '/public/logo.svg'));
$capM = new Captura($site, $tmp . '/iesire-manifest', new Normalizare($site));
$capM->resurse[$site . '/wp-content/themes/Divi/style.css'] = ['url' => $site . '/wp-content/themes/Divi/style.css', 'fel' => 'foaie', 'pagini' => ['/']];
$capM->resurse[$site . '/favicon.ico'] = ['url' => $site . '/favicon.ico', 'fel' => 'imagine', 'pagini' => ['/']];
$capM->manifest($sr);
foreach ($capM->manifestScris()['fisiere'] as $f) {
    $capM->inregistreazaCopia($f['url'], CopiereStatica::copiaza($sr, $tmp . '/public-manifest', $f['cale'], new Normalizare($site)));
}
$mf = array_column((array) json_decode((string) file_get_contents($tmp . '/iesire-manifest/manifest.json'), true)['fisiere'], null, 'cale');
$verifica('manifestul ține ambele sha256-uri după copiere: al sursei și al fișierului publicat (foaia rescrisă diferă), plus calea publicată',
    ($mf['/wp-content/themes/Divi/style.css']['sha256_sursa'] ?? '') === hash_file('sha256', $sr . '/wp-content/themes/Divi/style.css')
    && ($mf['/wp-content/themes/Divi/style.css']['sha256_publicat'] ?? '') === hash_file('sha256', $tmp . '/public-manifest/wp-content/themes/Divi/style.css')
    && $mf['/wp-content/themes/Divi/style.css']['sha256_sursa'] !== $mf['/wp-content/themes/Divi/style.css']['sha256_publicat']
    && ($mf['/favicon.ico']['publicat'] ?? '') === '/capturat/radacina/favicon.ico' && ($mf['/favicon.ico']['sha256_publicat'] ?? '') === hash_file('sha256', $sr . '/favicon.ico'),
    json_encode($mf));
$verifica('coliziunile cu CMS-ul sunt NU: un dosar din public/ (assets), o rută (/blog/{slug}), un fișier din docroot-ul nou; /favicon.ico și /wp-admin/… nu',
    str_contains((string) CopiereStatica::coliziune('/assets/a.png'), 'assets') && str_contains((string) CopiereStatica::coliziune('/blog/b.png'), '/blog/{slug}')
    && CopiereStatica::coliziune('/favicon.ico') === null && CopiereStatica::coliziune('/wp-admin/images/w.png') === null
    && CopiereStatica::coliziune('/wp-content/uploads/a.png') === null && (static function () use ($tmp): bool {
        @mkdir($tmp . '/docroot-cu-stub', 0770, true);
        touch($tmp . '/docroot-cu-stub/robots-stub.png');
        return CopiereStatica::coliziune('/robots-stub.png', $tmp . '/docroot-cu-stub') !== null;
    })());

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== SvgSigur pentru SVG-urile de neîncredere (CopiereStatica): doar UTF-8, fără DOCTYPE, entități și instrucțiuni, fără bare inverse');
$ns = 'xmlns="http://www.w3.org/2000/svg"';
$bun = '<svg ' . $ns . ' viewBox="0 0 1 1"><title>x &amp; y</title><path d="M0 0h1v1z" style="fill:#c00"/><use href="#a"/></svg>';
$u16 = static fn (string $s): string => "\xFF\xFE" . mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
$svgCazuri = [
    'curat' => [$bun, true],
    'curat, cu declarația XML UTF-8 la octetul 0' => ['<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $bun, true],
    'declarația XML cu altă codare' => ['<?xml version="1.0" encoding="ISO-8859-2"?>' . $bun, false],
    'declarația XML după un spațiu' => [' <?xml version="1.0"?>' . $bun, false],
    'UTF-16 (cu BOM)' => [$u16('<?xml version="1.0" encoding="UTF-16"?>' . $bun), false],
    'BOM UTF-8' => ["\xEF\xBB\xBF" . $bun, false],
    'octeți care nu sunt UTF-8' => [str_replace('x &amp; y', "x \xC3\x28 y", $bun), false],
    'DOCTYPE cu o entitate internă' => ['<!DOCTYPE svg [<!ENTITY t "marcaj">]><svg ' . $ns . '><title>&t;</title></svg>', false],
    'entitate externă (XXE)' => ['<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///nu-exista">]><svg ' . $ns . '><title>&x;</title></svg>', false],
    'instrucțiune de stil înaintea rădăcinii' => ['<?xml-stylesheet type="text/css" href="s.css"?>' . $bun, false],
    'instrucțiune după rădăcină' => [$bun . '<?proba x?>', false],
    'CDATA' => ['<svg ' . $ns . '><title><![CDATA[x]]></title></svg>', false],
    'script' => ['<svg ' . $ns . '><script>1</script></svg>', false],
    'foreignObject' => ['<svg ' . $ns . '><foreignObject><p xmlns="http://www.w3.org/1999/xhtml">x</p></foreignObject></svg>', false],
    'atribut on*' => ['<svg ' . $ns . ' onload="x()"><path d="M0 0"/></svg>', false],
    'href spre altă resursă' => ['<svg ' . $ns . '><use href="https://cdn.example.test/s.svg#a"/></svg>', false],
    'xlink:href cu schemă' => ['<svg ' . $ns . ' xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="data:image/svg+xml,x"/></svg>', false],
    'element <style>' => ['<svg ' . $ns . '><style>path{fill:url(#a)}</style></svg>', false],
    'url() spre altă resursă' => ['<svg ' . $ns . '><path d="M0 0" fill="url(https://img.example.test/p.png)"/></svg>', false],
    'bară inversă în style (escapare CSS)' => ['<svg ' . $ns . '><path d="M0 0" style="fill:\\75rl(#a)"/></svg>', false],
    'bară inversă într-un atribut de prezentare' => ['<svg ' . $ns . '><path d="M0 0" fill="u\\72l(#a)"/></svg>', false],
];
$gresite = [];
foreach ($svgCazuri as $caz => [$svg, $acceptat]) {
    if ((\App\Support\SvgSigur::problemaFisierNesigur($svg) === null) !== $acceptat) {
        $gresite[] = $caz;
    }
}
$verifica('SvgSigur::problemaFisierNesigur(): ' . count($svgCazuri) . ' cazuri (curat; declarația; UTF-16, BOM, octeți nevalizi; DOCTYPE, XXE; instrucțiuni înainte și după rădăcină; CDATA; script, foreignObject, on*, href și url() externe, <style>; bare inverse în stiluri)',
    $gresite === [], json_encode($gresite, JSON_UNESCAPED_UNICODE));
$verifica('SvgSigur::problemaFisier() (pachetele scrise de noi) rămâne cum era: același SVG curat trece', \App\Support\SvgSigur::problemaFisier($bun) === null);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== Fonturile Google: CSS-ul cu UA de Chrome, woff2 local, CSS-ul rescris');
$fg = new FonturiGoogle(new Cereri([$gazda], 10, 0.0), $gazda, $gazda, false); // site-ul de probă e http (implicit: doar https)
$foaie = $site . '/css?family=Proba+Sans:400,700&#038;display=swap';
$verifica('esteFoaie(): după gazda foii; cheia și calea deterministe', FonturiGoogle::esteFoaie($foaie, $gazda) && !FonturiGoogle::esteFoaie($site . '/alt.css', $gazda)
    && FonturiGoogle::cale($foaie) === '/capturat/fonturi/' . FonturiGoogle::cheie($foaie) . '.css' && FonturiGoogle::cheie($foaie) === FonturiGoogle::cheie(html_entity_decode($foaie)));
$x = $fg->aduce($foaie, $tmp . '/public' . FonturiGoogle::DOSAR);
$css = (string) @file_get_contents($tmp . '/public' . $x['css']);
$verifica('woff2 (UA Chrome), salvat sub /capturat/fonturi/, CSS-ul rescris cu același unicode-range și font-display', count($x['fonturi']) === 1
    && str_ends_with($x['fonturi'][0]['cale'], '.woff2') && is_file($tmp . '/public' . $x['fonturi'][0]['cale']) && str_contains($css, 'url(' . $x['fonturi'][0]['cale'] . ')')
    && str_contains($css, 'unicode-range: U+0100-02BA, U+02BD-02C5;') && str_contains($css, 'font-display: swap;') && !str_contains($css, $gazda), $css);

$cf = new Cereri([$gazda], 10, 0.0);
$fgH = new FonturiGoogle($cf, $gazda, $gazda);
$verifica('fonturile: doar https (o foaie cu altă schemă și un fișier de font http refuzate înaintea oricărei cereri); gazda și tipul fișierului verificate',
    ($e = $arunca(static fn () => $fgH->aduce('ftp://' . $gazda . '/css?family=X', $tmp . '/public' . FonturiGoogle::DOSAR))) !== null && $e->fel === 'gazda' && $cf->facute() === 0
    && str_contains((string) FonturiGoogle::problemaFont('http://fonts.gstatic.com/s/x.woff2'), 'https') && FonturiGoogle::problemaFont('https://fonts.gstatic.com/s/x.woff2') === null
    && FonturiGoogle::problemaFont('https://alt.example.test/x.woff2') !== null && FonturiGoogle::problemaFont('https://fonts.gstatic.com/s/x.js') !== null
    && FonturiGoogle::problemaFont('https://u' . '@' . 'fonts.gstatic.com/s/x.woff2') !== null);
$fgL = new FonturiGoogle(new Cereri([$gazda], 10, 0.0), $gazda, $gazda, false);
$verifica('fonturile: o foaie care nu e text/css și un font cu alt tip decât al extensiei sunt refuzate, nimic scris',
    ($e1 = $arunca(static fn () => $fgL->aduce($site . '/css-text', $tmp . '/public-fonturi-rele' . FonturiGoogle::DOSAR))) !== null && str_contains($e1->getMessage(), 'text/css')
    && ($e2 = $arunca(static fn () => $fgL->aduce($site . '/css-font-gresit', $tmp . '/public-fonturi-rele' . FonturiGoogle::DOSAR))) !== null && str_contains($e2->getMessage(), 'font/woff2')
    && glob($tmp . '/public-fonturi-rele/capturat/fonturi/*') === []);
$cD = new Cereri([$gazda], 10, 0.0);
$fgD = new FonturiGoogle($cD, $gazda, $gazda, false);
$xD1 = $fgD->aduce($foaie, $tmp . '/fonturi-dedup');
$dupaPrima = $cD->facute();
$xD1b = $fgD->aduce(html_entity_decode($foaie), $tmp . '/fonturi-dedup');
$xD2 = $fgD->aduce($site . '/css-dublat', $tmp . '/fonturi-dedup');
$cssD2 = (string) @file_get_contents($tmp . '/fonturi-dedup/' . $xD2['fisier_css']);
$verifica('fonturile: fiecare adresă cerută o dată pe rulare (aceeași foaie de două ori, același font în două @font-face și în două foi); CSS-ul rescris în fiecare loc',
    $dupaPrima === 2 && $xD1b === $xD1 && $cD->facute() === 4 && $fgD->fonturiAduse() === 2 && count($xD2['fonturi']) === 2
    && substr_count($cssD2, 'url(' . $xD1['fonturi'][0]['cale'] . ')') === 2 && !str_contains($cssD2, $gazda)
    && $xD2['sha256_css'] === hash_file('sha256', $tmp . '/fonturi-dedup/' . $xD2['fisier_css']), $cD->facute() . ' ' . $cssD2);
// --fonturi cu --lista: fonturile în dosarul capturii, cu sha256-ul în manifest; --din-dosar le publică pe copie, verificate.
$capF = $tmp . '/captura-fonturi';
@mkdir($capF . '/' . FonturiGoogle::DOSAR_CAPTURA, 0755, true);
$xF = (new FonturiGoogle(new Cereri([$gazda], 10, 0.0), $gazda, $gazda, false))->aduce($foaie, $capF . '/' . FonturiGoogle::DOSAR_CAPTURA);
$foiF = [['url' => $foaie] + $xF];
$nPub = CopiereStatica::publicaFonturi($capF, $foiF, $tmp . '/copie-fonturi');
$nPub2 = CopiereStatica::publicaFonturi($capF, $foiF, $tmp . '/copie-fonturi');
file_put_contents($capF . '/' . FonturiGoogle::DOSAR_CAPTURA . '/' . $xF['fonturi'][0]['fisier'], 'schimbat');
$eSchimbat = $arunca(static fn () => CopiereStatica::publicaFonturi($capF, $foiF, $tmp . '/copie-fonturi-2'));
$eNume = $arunca(static fn () => CopiereStatica::publicaFonturi($capF, [['fisier_css' => '../x.css', 'sha256_css' => str_repeat('a', 64), 'fonturi' => []]], $tmp . '/copie-fonturi-3'));
$verifica('fonturile din captură (--lista): publicate de --din-dosar în /capturat/fonturi/ doar cu sha256-ul din manifest; un font schimbat sau un nume străin = NU, nimic publicat',
    $nPub === 2 && $nPub2 === 0 && is_file($tmp . '/copie-fonturi' . $xF['css']) && is_file($tmp . '/copie-fonturi' . $xF['fonturi'][0]['cale'])
    && $eSchimbat !== null && $eSchimbat->fel === 'lant' && !is_dir($tmp . '/copie-fonturi-2') && $eNume !== null && $eNume->fel === 'lant' && !is_dir($tmp . '/copie-fonturi-3'));
$cB = new Cereri([$gazda], 2, 0.0);
$verifica('fonturile: bugetul lor e tare (o foaie cu două fonturi pe un buget de 2 cereri se oprește cu „buget”, fără a treia cerere)',
    ($eB = $arunca(static fn () => (new FonturiGoogle($cB, $gazda, $gazda, false))->aduce($site . '/css-dublat', $tmp . '/fonturi-buget'))) !== null && $eB->fel === 'buget'
    && $cB->facute() === 2 && FonturiGoogle::BUGET === 120);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== Integritatea: sumele nucleului, semnele codului ascuns, linkurile ascunse');
$wpI = $tmp . '/wp-integritate';
$fisiereWp = ['wp-includes/version.php' => "<?php\n\$wp_version = '6.8.1';\n\$wp_local_package = 'ro_RO';\n", 'wp-login.php' => '<?php // login modificat',
    'wp-settings.php' => '<?php // setari', 'wp-includes/in-plus.php' => '<?php // în plus în nucleu', 'wp-config.php' => '<?php // config',
    'wp-content/plugins/proba-public/proba-public.php' => "<?php\n/*\n * Plugin Name: Proba public\n * Version: 1.2.3\n */\n", 'wp-content/plugins/proba-public/inc/a.php' => '<?php // a',
    'wp-content/plugins/proba-premium/proba-premium.php' => "<?php\n/*\n * Plugin Name: Proba premium\n * Version: 9.0\n */\n", 'wp-content/themes/Divi/style.css' => '/* Theme Name: Divi proba */',
    'wp-content/mu-plugins/m.php' => '<?php // mu', 'wp-content/uploads/index.php' => '<?php // Silence is golden.', 'wp-content/uploads/2024/05/s.js' => 'x=eval(atob("YQ=="));'];
foreach ($fisiereWp as $rel => $c) {
    @mkdir(dirname($wpI . '/' . $rel), 0770, true);
    file_put_contents($wpI . '/' . $rel, $c);
}
file_put_contents($tmp . '/stare/sume.json', json_encode(['checksums' => ['6.8.1' => ['wp-includes/version.php' => md5($fisiereWp['wp-includes/version.php']),
    'wp-login.php' => md5('<?php // original'), 'wp-settings.php' => md5('<?php // setari'), 'wp-blog-header.php' => md5('x'), 'wp-content/plugins/index.php' => md5('y')]]]));
file_put_contents($tmp . '/stare/plugin-proba-public-1.2.3.json', json_encode(['files' => ['proba-public.php' => ['md5' => 'x', 'sha256' => [hash('sha256', $fisiereWp['wp-content/plugins/proba-public/proba-public.php'])]],
    'inc/a.php' => ['sha256' => hash('sha256', '<?php // a')]]]));
$nI = count($cereriServer());
$ci = new Cereri([$gazda], 10, 0.0, 3, 6, 600, $tmp . '/jurnal-integritate-proba.jsonl');
$iv = Integritate::verifica($wpI, $ci, $site, $site);
$pl = array_column($iv['pluginuri'], null, 'slug');
$cereriI = array_column(array_slice($cereriServer(), $nI), 'uri');
$verifica('integritatea: limba instalației (ro_RO, nu en_US) în cererea sumelor; un fișier al nucleului diferit și un PHP în plus în wp-includes/ = NU',
    $iv['stare'] === Integritate::NU && $iv['limba'] === 'ro_RO' && str_contains((string) ($cereriI[0] ?? ''), 'locale=ro_RO') && $iv['nucleu']['diferite'] === ['wp-login.php']
    && $iv['nucleu']['in_plus_php'] === ['wp-includes/in-plus.php'] && $iv['nucleu']['lipsa'] === ['wp-blog-header.php'], json_encode([$iv['probleme'], $cereriI]));
$verifica('integritatea: pluginul cu sume pe wordpress.org verificat; cel fără (404) neverificabil, cu sha256-ul fiecărui fișier; tema Divi și mu-plugins neverificabile, listate',
    ($pl['proba-public']['stare'] ?? '') === 'verificat' && ($pl['proba-premium']['stare'] ?? '') === 'neverificabil' && count($pl['proba-premium']['fisiere'] ?? []) === 1
    && ($iv['teme'][0]['nume'] ?? '') === 'Divi' && ($iv['teme'][0]['fisiere'][0]['sha256'] ?? '') === hash('sha256', '/* Theme Name: Divi proba */')
    && ($iv['mu_pluginuri'][0]['nume'] ?? '') === 'm.php' && count($cereriI) === 3 && $ci->facute() === 3, json_encode($iv['pluginuri']));
$verifica('integritatea: semne() și în uploads/ și mu-plugins/ (ATENȚIE); ciotul „Silence is golden” din uploads tolerat', in_array('wp-content/uploads/2024/05/s.js', array_column($iv['semne'], 'fisier'), true)
    && $iv['uploads_liniste'] === ['wp-content/uploads/index.php'] && $iv['uploads_php'] === []);
file_put_contents($wpI . '/wp-content/uploads/2024/05/x.php', '<?php echo 1;');
$nI = count($cereriServer());
$ivU = Integritate::verifica($wpI, $c2 = new Cereri([$gazda], 10, 0.0), $site, $site);
$verifica('integritatea: un fișier PHP în uploads/ = NU înaintea oricărei cereri', $ivU['stare'] === Integritate::NU && $ivU['uploads_php'] === ['wp-content/uploads/2024/05/x.php']
    && $c2->facute() === 0 && count($cereriServer()) === $nI);
unlink($wpI . '/wp-content/uploads/2024/05/x.php');
unlink($wpI . '/wp-includes/in-plus.php');
file_put_contents($wpI . '/wp-login.php', '<?php // original');
$ivB = Integritate::verifica($wpI, new Cereri([$gazda], 10, 0.0), $site, $site);
$verifica('integritatea trece pe instalația curată (fișierele lipsă și PHP-ul în plus din rădăcină doar ATENȚIE); câte cereri planifică', $ivB['stare'] === Integritate::TRECUT
    && Integritate::cereriPlanificate($wpI) === 3 && Integritate::inAsteptare('din mediul nostru')['stare'] === 'în așteptare (server)', json_encode($ivB['probleme']));
$verifica('semnele codului ascuns: eval(atob(…)), gzinflate(base64_decode(…)), String.fromCharCode lung; un cod obișnuit fără semne',
    in_array('eval(atob(…))', Integritate::semne('x=1;eval(atob("YWxlcnQ="));'), true) && Integritate::semne('<?php echo gzinflate(base64_decode("x"));') !== []
    && Integritate::semne('String.fromCharCode(' . implode(',', range(60, 110)) . ')') !== [] && Integritate::semne('function a(){return 1;}') === []);
$verifica('linkurile spre alte gazde din elemente ascunse (spam SEO)', Integritate::ascunse('<div style="display:none"><a href="https://spam.example.test/">x</a></div><a href="https://vizibil.example.test/">y</a>'
    . '<div hidden><a href="/intern/">z</a></div>', ['pensiune-proba.test']) === ['https://spam.example.test/']);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== Resursele, șabloanele .htaccess, linia de comandă');
$res = array_column(Resurse::dinHtml('<link rel="stylesheet" href="/a.css"><script src="https://alt.test/b.js"></script><img srcset="c-1.jpg 1x, ../d.jpg 2x">'
    . '<div style="background-image:url(&quot;/e.png&quot;)"></div><style>@import "/f.css"; .x{background:url(g.svg)}</style>', 'https://pensiune-proba.test/p/q/'), 'url');
$verifica('analiza statică: link, script, srcset relativ (cu ..), url() din style cu entități, @import și url() din <style>', $res === ['https://alt.test/b.js',
    'https://pensiune-proba.test/a.css', 'https://pensiune-proba.test/e.png', 'https://pensiune-proba.test/f.css', 'https://pensiune-proba.test/p/d.jpg',
    'https://pensiune-proba.test/p/q/c-1.jpg', 'https://pensiune-proba.test/p/q/g.svg'], json_encode($res));
$wpFoi = $tmp . '/wp-foi';
foreach (['wp-content/themes/Divi/style.css' => '@import url("parts/b.css"); .x{background:url(../../uploads/2024/05/fundal.jpg)} .y{background:image-set("i-1x.png" 1x, "i-2x.png" 2x)}'
    . ' @font-face{font-family:P;src:url(/wp-content/themes/Divi/core/f.woff2) format("woff2"), local("P")} /* url(nu-in-manifest.png) */ .w{background:url(https://alta.example.test/e.png)}',
    'wp-content/themes/Divi/parts/b.css' => '.z{background:url(z.png)} @import "c.css";', 'wp-content/themes/Divi/parts/c.css' => '.c{background:url(' . $site . '/wp-content/uploads/c.png)}',
    'wp-content/uploads/2024/05/fundal.jpg' => 'j', 'wp-content/themes/Divi/i-1x.png' => 'p', 'wp-content/themes/Divi/i-2x.png' => 'p', 'wp-content/themes/Divi/core/f.woff2' => 'w',
    'wp-content/themes/Divi/parts/z.png' => 'p', 'wp-content/uploads/c.png' => 'p'] as $rel => $c) {
    @mkdir(dirname($wpFoi . '/' . $rel), 0770, true);
    file_put_contents($wpFoi . '/' . $rel, $c);
}
$capF = new Captura($site, $tmp . '/iesire-foi', new Normalizare($site));
$capF->resurse[$site . '/wp-content/themes/Divi/style.css'] = ['url' => $site . '/wp-content/themes/Divi/style.css', 'fel' => 'foaie', 'pagini' => ['/camere/']];
$capF->resurse[$site . '/wp-content/themes/Divi/lipsa.css'] = ['url' => $site . '/wp-content/themes/Divi/lipsa.css', 'fel' => 'foaie', 'pagini' => ['/camere/']];
$mF = $capF->manifest($wpFoi);
$caiF = array_column($mF['fisiere'], 'cale');
$asteptate = ['/wp-content/themes/Divi/parts/b.css', '/wp-content/themes/Divi/parts/c.css', '/wp-content/uploads/2024/05/fundal.jpg', '/wp-content/themes/Divi/i-1x.png',
    '/wp-content/themes/Divi/i-2x.png', '/wp-content/themes/Divi/core/f.woff2', '/wp-content/themes/Divi/parts/z.png', '/wp-content/uploads/c.png'];
$zF = array_column($mF['fisiere'], null, 'cale');
$verifica('foile proprii analizate recursiv: url() relative și absolute, @import pe două niveluri, image-set, src din @font-face; comentariile nu; cu paginile și foaia de origine',
    array_diff($asteptate, $caiF) === [] && !in_array('/nu-in-manifest.png', $caiF, true) && ($zF['/wp-content/themes/Divi/parts/z.png']['din_foi'] ?? []) === ['/wp-content/themes/Divi/parts/b.css']
    && ($zF['/wp-content/themes/Divi/parts/z.png']['pagini'] ?? []) === ['/camere/'] && in_array('alta.example.test', array_column($mF['externe'], 'gazda'), true),
    json_encode([array_values(array_diff($asteptate, $caiF)), $caiF]));
$verifica('o foaie proprie care nu se poate citi (nici disc, nici oglindă) e spusă ATENȚIE, cu calea ei', array_filter($capF->avertismente,
    static fn (string $a): bool => str_contains($a, 'nu s-au putut citi') && str_contains($a, '/wp-content/themes/Divi/lipsa.css')) !== []);
foreach (['wp-content', 'wp-includes', 'capturat'] as $arbore) {
    $t = (string) file_get_contents($radacina . '/deploy/stub/' . $arbore . '.htaccess');
    // Fiecare FilesMatch din șablon, încercat pe nume de fișiere (PCRE, ca Apache 2.4; fără (?i), deci și ca LiteSpeed).
    preg_match_all('#<FilesMatch "([^"]+)">\s*(Require all denied|Header set Content-Security-Policy "sandbox"|Header set Access-Control-Allow-Origin "\*")#', $t, $fm, PREG_SET_ORDER);
    $tipare = ['refuz' => [], 'sandbox' => [], 'cors' => []];
    foreach ($fm as $x) {
        $tipare[str_starts_with($x[2], 'Require') ? 'refuz' : (str_contains($x[2], 'sandbox') ? 'sandbox' : 'cors')][] = '#' . str_replace('#', '\#', $x[1]) . '#';
    }
    $potriveste = static fn (string $fel, string $nume): bool => array_filter($tipare[$fel], static fn (string $re): bool => preg_match($re, $nume) === 1) !== [];
    $refuzate = ['a.php', 'a.PHP', 'a.Php5', 'a.php7', 'a.phtml', 'a.PHTML', 'a.pht', 'a.phps', 'a.phar', 'a.PHAR', 'a.shtml', 'a.SHTM', 'a.pl', 'a.py', 'a.cgi', 'a.sh',
        'a.js', 'a.JS', 'a.mjs', 'a.cjs', 'a.jsx'];
    $servite = ['a.png', 'a.css', 'a.woff2', 'a.svg', 'a.jpg', 'a.php.png', 'a.mp4', 'a.json'];
    $gresiteH = array_merge(array_filter($refuzate, static fn (string $n): bool => !$potriveste('refuz', $n)), array_filter($servite, static fn (string $n): bool => $potriveste('refuz', $n)));
    $verifica($arbore . '.htaccess: refuzate oricum ar fi scrise (.php, .php5, .phtml, .pht, .phps, .phar, .shtml, .js…), servite pozele și foile; CSP sandbox pe .svg/.SVG/.svgz; CORS pe fonturi',
        $gresiteH === [] && $potriveste('sandbox', 'a.SVG') && $potriveste('sandbox', 'a.svgz') && !$potriveste('sandbox', 'a.png') && $potriveste('cors', 'a.WOFF2')
        && !$potriveste('cors', 'a.css'), json_encode([$gresiteH, $tipare]));
    $farăIfModule = (string) preg_replace('#<IfModule [^>]+>.*?</IfModule>#s', '', $t);
    $verifica($arbore . '.htaccess: fără php_flag/php_value (500 pe LiteSpeed), nosniff „always”, fiecare Header/Expires/RemoveHandler într-un <IfModule>',
        preg_match('/^\s*php_(flag|value|admin_flag|admin_value)\b/mi', $t) !== 1 && str_contains($t, 'Header always set X-Content-Type-Options "nosniff"')
        && preg_match('/^\s*(Header|Expires\w*|RemoveHandler|RemoveType)\b/m', $farăIfModule) !== 1);
}
$cli = static function (array $arg, ?string $script = null) use ($radacina): array {
    $sock = (string) (getenv('CMS_DEV_SOCK') ?: ini_get('pdo_mysql.default_socket'));
    $p = proc_open(array_merge([PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'pdo_mysql.default_socket=' . $sock, $script ?? $radacina . '/scripts/captureaza-wordpress.php'], $arg),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, $radacina);
    $out = (string) stream_get_contents($pp[1]) . (string) stream_get_contents($pp[2]);
    return [proc_close($p), $out];
};
$fara = '--fara-integritate=proba locală, fără discul WordPress';
$n0 = count($cereriServer());
[$cod, $out] = $cli(['--url=' . $site, '--lista=' . $tmp . '/lista.json', '--plan', $fara, '--iesire=' . $tmp . '/iesire3']);
$verifica('--plan: nicio cerere, nimic scris; cererile planificate, rezerva sitemap-urilor găsite în index și integritatea sărită, arătate', $cod === 0
    && count($cereriServer()) === $n0 && !is_dir($tmp . '/iesire3') && str_contains($out, 'cereri planificate: 132') && str_contains($out, 'rezerva sitemap-urilor găsite în index: 72')
    && str_contains($out, '= 204 din bugetul 300') && str_contains($out, 'integritatea: sărită'), $out);
[$cod, $out] = $cli(['--url=' . $site, '--lista=' . $tmp . '/lista.json', '--plan', '--buget=150', $fara, '--iesire=' . $tmp . '/iesire3']);
$verifica('--plan peste buget cu rezervele (132 + 72 > 150): NU, cod 1', $cod === 1 && str_contains($out, 'depășesc bugetul'), $out);
[$cod, $out] = $cli(['--url=' . $site, '--lista=' . $tmp . '/lista.json', '--aplica', $fara, '--iesire=' . $radacina . '/storage/captura-proba']);
$verifica('--aplica cu ieșirea în repo: refuzat înaintea capturii, cod 2', $cod === 2 && str_contains($out, 'nu se scrie în repo') && !is_dir($radacina . '/storage/captura-proba'), $out);
$n0 = count($cereriServer());
[$cod, $out] = $cli(['--url=' . $site, '--lista=' . $tmp . '/lista.json', '--aplica', $fara, '--raspunsuri-ua=2', '--resurse-din=' . $tmp . '/nu-exista', '--iesire=' . $tmp . '/iesire-resurse']);
$verifica('--resurse-din lipsă: cod 2 înaintea oricărei cereri, nimic scris (nu după captură)', $cod === 2 && str_contains($out, 'dosarul capturilor nu există')
    && count($cereriServer()) === $n0 && !file_exists($tmp . '/iesire-resurse'), $out);
file_put_contents($tmp . '/lista-rea-json.json', '{"adrese": [');
$folosiri = [
    'fără --url' => ['--lista=' . $tmp . '/lista.json', '--plan', $fara],
    '--lista care nu e JSON' => ['--url=' . $site, '--lista=' . $tmp . '/lista-rea-json.json', '--plan', $fara, '--iesire=' . $tmp . '/x'],
    'o gazdă cu „_”' => ['--url=http://site_proba.test', '--lista=' . $tmp . '/lista.json', '--plan', $fara, '--iesire=' . $tmp . '/x'],
    'o adresă cu cale' => ['--url=' . $site . '/cale', '--lista=' . $tmp . '/lista.json', '--plan', $fara, '--iesire=' . $tmp . '/x'],
    '--doar=,' => ['--url=' . $site, '--lista=' . $tmp . '/lista.json', '--plan', '--doar=,', $fara, '--iesire=' . $tmp . '/x'],
    '--ritm=0' => ['--url=' . $site, '--lista=' . $tmp . '/lista.json', '--plan', '--ritm=0', $fara, '--iesire=' . $tmp . '/x'],
    '--ritm=-1 la --plan' => ['--url=' . $site, '--lista=' . $tmp . '/lista.json', '--plan', '--ritm=-1', $fara, '--iesire=' . $tmp . '/x'],
    '--ritm=0.5' => ['--url=' . $site, '--lista=' . $tmp . '/lista.json', '--plan', '--ritm=0.5', $fara, '--iesire=' . $tmp . '/x'],
    '--buget=2001' => ['--url=' . $site, '--lista=' . $tmp . '/lista.json', '--plan', '--buget=2001', $fara, '--iesire=' . $tmp . '/x'],
    '--buget=0' => ['--url=' . $site, '--lista=' . $tmp . '/lista.json', '--plan', '--buget=0', $fara, '--iesire=' . $tmp . '/x'],
    '--lista fără --fara-integritate' => ['--url=' . $site, '--lista=' . $tmp . '/lista.json', '--plan', '--iesire=' . $tmp . '/x'],
    '--din-dosar fără --sha-raport' => ['--url=' . $site, '--lista=' . $tmp . '/lista.json', '--aplica', '--din-dosar=' . $tmp . '/iesire', $fara, '--iesire=' . $tmp . '/x'],
    '--public fără --wp' => ['--url=' . $site, '--lista=' . $tmp . '/lista.json', '--plan', '--public=' . $tmp . '/public', $fara, '--iesire=' . $tmp . '/x'],
    '--fara-integritate cu --public' => ['--url=' . $site, '--wp=' . $tmp, '--plan', '--public=' . $tmp . '/public', $fara, '--iesire=' . $tmp . '/x'],
    '--fonturi cu --din-oglinda' => ['--url=' . $site, '--lista=' . $tmp . '/lista.json', '--plan', '--fonturi', '--din-oglinda=' . $tmp, $fara, '--iesire=' . $tmp . '/x'],
    'un argument necunoscut' => ['--url=' . $site, '--lista=' . $tmp . '/lista.json', '--plan', '--nu-exista', $fara],
];
$gresite = [];
foreach ($folosiri as $caz => $arg) {
    [$cod, $out] = $cli($arg);
    if ($cod !== 2 || str_contains($out, 'Fatal error') || str_contains($out, 'Uncaught')) {
        $gresite[] = $caz . ' → ' . $cod;
    }
}
$verifica('folosirea greșită: cod 2 (niciodată 255 sau o excepție neprinsă), la --plan ca la --aplica: ' . implode('; ', array_keys($folosiri)), $gresite === [] && !is_dir($tmp . '/x'),
    json_encode($gresite, JSON_UNESCAPED_UNICODE));
[$codF, $outF] = $cli(['--url=' . $site, '--lista=' . $tmp . '/lista.json', '--plan', '--fonturi', $fara, '--iesire=' . $tmp . '/x']);
$verifica('--fonturi fără --public (din mediul nostru): planul trece, cu bugetul separat al fonturilor (' . FonturiGoogle::BUGET . '), în afara bugetului site-ului',
    $codF === 0 && str_contains($outF, 'fonturile Google: un buget al lor, separat, de cel mult ' . FonturiGoogle::BUGET . ' cereri') && str_contains($outF, 'în dosarul capturii (fonturi/)')
    && !str_contains($outF, 'rezerva fonturilor'), $outF);
[$cod, $out] = $cli(['--url=https://pensiune-probă.test', '--lista=' . $tmp . '/lista.json', '--plan', $fara, '--iesire=' . $tmp . '/x']);
$verifica('o gazdă IDN e scrisă în punycode (xn--…) și folosită așa', $cod === 0 && str_contains($out, 'site-ul: https://xn--pensiune-prob-1rb.test '), $out);
file_put_contents($tmp . '/lista2.json', json_encode(['adrese' => [['fel' => 'raspuns', 'cale' => '/robots.txt']]]));
$n0 = count($cereriServer());
[$cod, $out] = $cli(['--url=' . $site, '--lista=' . $tmp . '/lista2.json', '--aplica', '--raspunsuri-ua=1', $fara, '--iesire=' . $tmp . '/iesire4']);
$r4 = (array) json_decode((string) @file_get_contents($tmp . '/iesire4/raport-captura.json'), true);
// Recenzia 2, R1-3: a doua rulare în același dosar e refuzată (cod 2) înaintea oricărei scrieri: raportul și jurnalul rămân octet cu octet.
$shaR4 = (string) @hash_file('sha256', $tmp . '/iesire4/raport-captura.json');
$shaJ4 = (string) @hash_file('sha256', $tmp . '/iesire4/' . Captura::JURNAL);
[$cod4b, $out4b] = $cli(['--url=' . $site, '--lista=' . $tmp . '/lista2.json', '--aplica', '--raspunsuri-ua=1', $fara, '--iesire=' . $tmp . '/iesire4']);
$verifica('o captură existentă în --iesire: cod 2, raport-captura.json și jurnalul neatinse', $cod4b === 2 && str_contains($out4b, 'are deja o captură')
    && hash_file('sha256', $tmp . '/iesire4/raport-captura.json') === $shaR4 && hash_file('sha256', $tmp . '/iesire4/' . Captura::JURNAL) === $shaJ4, $out4b);
$sha4 = preg_match('/raport-captura\.json: sha256 ([0-9a-f]{64})/', $out, $mSha) === 1 ? $mSha[1] : '';
$jurnal4 = file($tmp . '/iesire4/' . Captura::JURNAL, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$verifica('--aplica: 2 cereri la cel puțin 1 s una de alta, GATA; jurnalul pe disc; raportul cu ritmul, bugetul, gazdele permise, integritatea „în așteptare” și motivul; sha256-ul lui tipărit',
    $cod === 0 && str_contains($out, 'GATA') && str_contains($out, 'cereri făcute: 2 din 300') && count(array_slice($cereriServer(), $n0)) === 2
    && ($cereriServer()[count($cereriServer()) - 1]['ora'] ?? 0) - ($cereriServer()[count($cereriServer()) - 2]['ora'] ?? 0) >= 0.95 && count($jurnal4) === 4
    && (float) ($r4['antet']['ritm_s'] ?? 0) === 1.0 && ($r4['antet']['buget'] ?? null) === 300 && ($r4['antet']['cereri']['gazde_permise'] ?? null) === [$gazda]
    && ($r4['antet']['integritate']['stare'] ?? '') === 'în așteptare (server)' && str_contains((string) ($r4['antet']['integritate']['motiv'] ?? ''), 'proba locală')
    && $sha4 === hash_file('sha256', $tmp . '/iesire4/raport-captura.json') && ($r4['sha256_jurnal'] ?? '') === hash_file('sha256', $tmp . '/iesire4/' . Captura::JURNAL), $out);
$n0 = count($cereriServer());
[$cod, $out] = $cli(['--url=' . $site, '--lista=' . $tmp . '/lista2.json', '--aplica', '--din-dosar=' . $tmp . '/iesire4', '--sha-raport=' . $sha4, $fara, '--iesire=' . $tmp . '/iesire5']);
[$codR, $outR] = $cli(['--url=' . $site, '--lista=' . $tmp . '/lista2.json', '--aplica', '--din-dosar=' . $tmp . '/iesire4', '--sha-raport=' . str_repeat('ab', 32), $fara,
    '--iesire=' . $tmp . '/iesire6']);
$verifica('--din-dosar cu sha256-ul tipărit: verificată, fără rețea, GATA; cu alt sha256: NU, cod 2', $cod === 0 && str_contains($out, 'GATA') && count($cereriServer()) === $n0
    && $codR === 2 && str_contains($outR, 'nu trece verificarea'), $out . "\n" . $outR);
// Gazda cere oprirea DUPĂ cereri (429 mereu): adresele de după nu se mai cer; raportul și jurnalul pe disc, întregi.
$n0 = count($cereriServer());
file_put_contents($tmp . '/lista-oprire.json', json_encode(['adrese' => [['fel' => 'raspuns', 'cale' => '/robots.txt'], ['fel' => 'raspuns', 'cale' => '/mereu-429/'],
    ['fel' => 'raspuns', 'cale' => '/feed/']]]));
[$cod, $out] = $cli(['--url=' . $site, '--lista=' . $tmp . '/lista-oprire.json', '--aplica', '--raspunsuri-ua=1', $fara, '--iesire=' . $tmp . '/iesire-oprire']);
$r7 = (array) json_decode((string) @file_get_contents($tmp . '/iesire-oprire/raport-captura.json'), true);
$noi7 = array_slice($cereriServer(), $n0);
$verifica('gazda cere oprirea DUPĂ cereri (429 de 6 ori la rând): cod 1, /feed/ necerută, raportul cu jurnalul tuturor cererilor, jurnalul pe disc întreg',
    $cod === 1 && count($noi7) === 2 + 6 && array_filter($noi7, static fn (array $c): bool => str_starts_with((string) $c['uri'], '/feed')) === []
    && str_contains($out, '/feed/: necapturată') && count($r7['jurnal'] ?? []) === 8 && count(file($tmp . '/iesire-oprire/' . Captura::JURNAL, FILE_SKIP_EMPTY_LINES) ?: []) === 16, $out);
// O eroare fatală DUPĂ cereri: o clasă care aruncă la încărcare (un încărcător pus înaintea celui din bootstrap); raportul îl
// scrie funcția de oprire (register_shutdown_function), cu motivul, jurnalul tuturor cererilor și sha256-ul tipărit.
file_put_contents($tmp . '/cli-fatal.php', '<?php spl_autoload_register(static function (string $c): void { if ($c === '
    . var_export('App\\Support\\ImportWordPress\\Captura\\Resurse', true) . ') { throw new Error(\'oprire de probă\'); } }, true, true);' . "\n"
    . 'require ' . var_export($radacina . '/scripts/captureaza-wordpress.php', true) . ";\n");
$n0 = count($cereriServer());
file_put_contents($tmp . '/lista-fatal.json', json_encode(['adrese' => [['fel' => 'raspuns', 'cale' => '/robots.txt'], ['fel' => 'pagina', 'cale' => '/']]]));
[$cod, $out] = $cli(['--url=' . $site, '--lista=' . $tmp . '/lista-fatal.json', '--aplica', '--raspunsuri-ua=1', $fara, '--iesire=' . $tmp . '/iesire-fatala'], $tmp . '/cli-fatal.php');
$r8 = (array) json_decode((string) @file_get_contents($tmp . '/iesire-fatala/raport-captura.json'), true);
$noi8 = count(array_slice($cereriServer(), $n0));
$verifica('o eroare fatală DUPĂ cereri: raportul tot e scris la oprire, cu motivul, jurnalul celor 2 + 12 cereri, jurnalul pe disc întreg, sha256-ul tipărit',
    $cod === 255 && $noi8 === 2 + 12 && str_contains((string) ($r8['oprit'] ?? ''), 'eroare fatală') && str_contains((string) ($r8['oprit'] ?? ''), 'oprire de probă')
    && count($r8['jurnal'] ?? []) === $noi8 && count(file($tmp . '/iesire-fatala/' . Captura::JURNAL, FILE_SKIP_EMPTY_LINES) ?: []) === 2 * $noi8
    && preg_match('/raport-captura\.json: sha256 ([0-9a-f]{64})/', $out, $m8) === 1 && $m8[1] === hash_file('sha256', $tmp . '/iesire-fatala/raport-captura.json'), $out);
[$cod, $out] = $cli(['--url=' . $site, '--wp=' . $tmp . '/wp-proba', '--plan', '--public=' . $tmp . '/git-proba-2/public', '--iesire=' . $tmp . '/x']);
$verifica('--public într-un arbore git care nu ignoră arborii copiați: cod 2, înainte de orice', (static function () use ($tmp, $cli, $site): bool {
    @mkdir($tmp . '/git-proba-2/public', 0770, true);
    exec('git -C ' . escapeshellarg($tmp . '/git-proba-2') . ' init -q 2>&1');
    [$c, $o] = $cli(['--url=' . $site, '--wp=' . $tmp . '/wp-proba', '--plan', '--public=' . $tmp . '/git-proba-2/public', '--iesire=' . $tmp . '/x']);
    return $c === 2 && str_contains($o, 'nu e ignorat');
})());
file_put_contents($tmp . '/wp-proba/wp-content/uploads/stray.php', '<?php echo 1;');
$n0 = count($cereriServer());
[$cod, $out] = $cli(['--url=' . $site, '--wp=' . $tmp . '/wp-proba', '--aplica', '--buget=2000', '--iesire=' . $tmp . '/iesire8', '--public=' . $tmp . '/public8']);
$verifica('integritatea NU (un PHP în uploads/): cod 2, înaintea oricărei cereri a capturii și a oricărei copieri; integritate.json și raportul scrise',
    $cod === 2 && str_contains($out, 'integritatea WordPress nu trece') && count($cereriServer()) === $n0 && !is_dir($tmp . '/public8')
    && is_file($tmp . '/iesire8/integritate.json') && (json_decode((string) @file_get_contents($tmp . '/iesire8/raport-captura.json'), true)['antet']['integritate']['stare'] ?? '') === 'NU', $out);
// Recenzia 4, R1-N6: dovada unui NU al integrității rămâne: a doua rulare e refuzată (cod 2) înaintea oricărei scrieri.
$sha8 = array_map(static fn (string $f): string => (string) @hash_file('sha256', $tmp . '/iesire8/' . $f), ['integritate.json', 'raport-captura.json']);
$n0 = count($cereriServer());
[$cod8b, $out8b] = $cli(['--url=' . $site, '--wp=' . $tmp . '/wp-proba', '--aplica', '--buget=2000', '--iesire=' . $tmp . '/iesire8', '--public=' . $tmp . '/public8']);
[$cod8c, $out8c] = $cli(['--url=' . $site, '--lista=' . $tmp . '/lista2.json', '--aplica', '--raspunsuri-ua=1', $fara, '--iesire=' . $tmp . '/iesire8']);
$verifica('un dosar cu integritatea NU (recenzia 4, R1-N6): a doua rulare, cu sau fără integritate, cod 2 înaintea oricărei cereri; integritate.json și raportul octet cu octet',
    $cod8b === 2 && $cod8c === 2 && str_contains($out8b, 'verificare a integrității') && str_contains($out8c, 'verificare a integrității') && count($cereriServer()) === $n0
    && $sha8[0] !== '' && array_map(static fn (string $f): string => (string) @hash_file('sha256', $tmp . '/iesire8/' . $f), ['integritate.json', 'raport-captura.json']) === $sha8,
    $out8b . "\n" . $out8c);
// Recenzia 3, R1-N5: raportul unei rulări refuzate înaintea integrității (fără integritate.json) nu blochează dosarul.
@mkdir($tmp . '/iesire9');
file_put_contents($tmp . '/iesire9/raport-captura.json', json_encode(['format' => 2, 'antet' => ['refuzat' => true], 'oprit' => 'refuz de probă']));
file_put_contents($tmp . '/iesire9/' . Captura::JURNAL, '');
[$cod9, $out9] = $cli(['--url=' . $site, '--lista=' . $tmp . '/lista2.json', '--aplica', '--raspunsuri-ua=1', $fara, '--iesire=' . $tmp . '/iesire9']);
$verifica('o rulare refuzată înaintea integrității nu blochează dosarul (recenzia 3, R1-N5): a doua rulare în același --iesire, GATA',
    $cod9 === 0 && str_contains($out9, 'GATA') && !str_contains((string) @file_get_contents($tmp . '/iesire9/raport-captura.json'), 'refuz de probă'), $out9);
unlink($tmp . '/wp-proba/wp-content/uploads/stray.php');
// Recenzia 2, R1-6: dintr-o captură urcată, discul trebuie să fie cel din manifestul ei, verificat înaintea primei copii
// (integritatea cere api.wordpress.org, deci pe drumul întreg doar pe server; aici, verificarea însăși).
$fisiere6 = [['url' => $site . '/wp-content/a.css', 'cale' => '/wp-content/a.css', 'sursa' => 'disc', 'sha256_sursa' => str_repeat('a', 64)],
    ['url' => $site . '/wp-content/b.png', 'cale' => '/wp-content/b.png', 'sursa' => 'disc', 'sha256_sursa' => str_repeat('b', 64)],
    ['url' => 'https://alt.example/x.css', 'cale' => '', 'sursa' => 'externa']];
$verif6 = [['url' => $site . '/wp-content/a.css', 'sha256_sursa' => str_repeat('a', 64)], ['url' => $site . '/wp-content/b.png', 'sha256_sursa' => str_repeat('b', 64)]];
$schimbat6 = $verif6;
$schimbat6[1]['sha256_sursa'] = str_repeat('c', 64);
$verifica('--din-dosar --public: fiecare fișier static de pe disc e cel din manifestul verificat (un sha256 schimbat sau un fișier în plus = NU, nimic copiat)',
    CopiereStatica::nepotriviriManifest($fisiere6, $verif6) === [] && count(CopiereStatica::nepotriviriManifest($fisiere6, $schimbat6)) === 1
    && count(CopiereStatica::nepotriviriManifest($fisiere6, [$verif6[0]])) === 1
    && str_contains((string) file_get_contents($radacina . '/scripts/captureaza-wordpress.php'), '!$deCopiatBlocat'));

$scrie('');
$scrie('Rezultat: ' . $trecute . ' verificări trecute, ' . $esuate . ' eșuate.');
exit($esuate === 0 ? 0 : 1);
