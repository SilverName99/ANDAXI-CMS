<?php

declare(strict_types=1);

/**
 * Testele porturilor din vertij și grafoanaytis ale pasului 5 (planul Etapei 1: WP-15, WP-24, WP-25, WP-26), cu serverul
 * de dezvoltare, pe baza de test cu datele demo.
 *
 *   tools/dev/php.sh tools/dev/test-porturi-vt-gr.php
 *
 * WP-15 — porturile mici în nucleu (VT-C1, VT-C2, VT-C3, VT-C10, GR-01, GR-06, GR-04, GR-05):
 * A. Cache-ul paginilor: un fișier trunchiat sau stricat nu se servește și nu dă 304 (pagina se face din nou), o pagină fără
 *    </html> nu intră în cache, scrierea prin fișier temporar unic (nimic rămas), Cache-Control după
 *    page_cache_browser_policy („revalidate” implicit, „max_age” ca înainte), și la HIT și la 304.
 * B. Jurnalul erorilor fatale: o eroare fatală după pornirea cererii lasă un rând în storage/logs/fatal.log, cu calea fără
 *    query string.
 * C. StripeGateway pe un Stripe fals (tools/dev/lib/stripe-fals.php): fără setare niciun Stripe-Version; cu setare, antetul;
 *    Idempotency-Key doar la creările care îl primesc; DELETE; GET cu query la metodele noi și fără la sesiunea de checkout
 *    (moștenit); lista webhook-urilor; o adresă STRIPE_API_BASE care nu e locală e ignorată.
 * D. Reply-To (GR-01): emailul formularului de contact (prin server) și sendCustom/sendTemplate cu from_email → vizitatorul;
 *    fără from_email sau cu o adresă invalidă → expeditorul; aceleași antete pe drumul SMTP (un server SMTP fals).
 * E. {{an}} în antetul și subsolul din Design site (GR-06).
 * F. Galeria (GR-04, GR-05): PDF-ul în galerie (media_type document, fișa din grilă), un PDF fals și o imagine falsă
 *    refuzate, PDF-ul refuzat la încărcarea din editorul de produs și de articol; încărcarea multiplă (titlurile din nume,
 *    fișierul refuzat numit, un rând în jurnalul adminilor), o cerere peste post_max_size cu mesajul ei.
 *
 * Toată baza (fiecare tabel) e pusă la loc la final, verificat cu CHECKSUM TABLE; la fel licența „toate”, cache-ul
 * paginilor, sesiunile, fișierele încărcate, emailurile capturate și jurnalul fatal. Serverele pornite aici se opresc.
 * Raportul: $CMS_DEV_DIR/test-porturi-vt-gr-raport.txt; cod de ieșire 0 doar dacă totul a trecut. Date inventate.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';
require_once __DIR__ . '/lib/salvari-admin.php';

use App\Support\Database;
use App\Support\OrderMailer;
use App\Support\Paths;
use App\Support\ResponseCache;
use App\Support\Settings;
use App\Support\StripeGateway;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-porturi-vt-gr-raport.txt';
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
$scrie('Test porturile VT/GR (WP-15, WP-24, WP-25, WP-26) ANDAXI-CMS — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

$port = (int) (getenv('CMS_DEV_PORT') ?: '8701');
$baza = 'http://127.0.0.1:' . $port;

/**
 * O cerere spre serverul de dezvoltare; antetele cu numele scris mic. `$date`: un tablou (formular; cu un CURLFile devine
 * multipart) sau un text (corpul trimis așa, de exemplu JSON).
 */
$cerere = static function (string $metoda, string $cale, ?string $jar = null, array|string $date = [], array $antete = [], int $timeout = 60) use ($baza): array {
    $primite = [];
    $c = curl_init(str_starts_with($cale, 'http') ? $cale : $baza . $cale);
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metoda,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOPROXY => '*',
        CURLOPT_PROXY => '',
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $antete,
        CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$primite): int {
            if (str_contains($linie, ':')) {
                [$nume, $valoare] = explode(':', $linie, 2);
                $primite[strtolower(trim($nume))] = trim($valoare);
            }
            return strlen($linie);
        },
    ]);
    if ($metoda === 'POST') {
        $multipart = is_array($date) && array_filter($date, static fn ($v): bool => $v instanceof CURLFile) !== [];
        curl_setopt($c, CURLOPT_POSTFIELDS, is_string($date) || $multipart ? $date : http_build_query($date));
    }
    if ($jar !== null) {
        curl_setopt($c, CURLOPT_COOKIEFILE, $jar);
        curl_setopt($c, CURLOPT_COOKIEJAR, $jar);
    }
    $corp = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $cod, 'antete' => $primite, 'corp' => $corp];
};

// Serverul de dezvoltare: îl pornește testul dacă nu merge.
$amPornitServerul = false;
if ($cerere('GET', '/health')['cod'] !== 200) {
    exec(escapeshellarg($radacina . '/tools/dev/server.sh') . ' 2>&1', $iesire, $codServer);
    if ($codServer !== 0) {
        $scrie('EROARE: serverul nu pornește: ' . implode(' ', $iesire));
        exit(1);
    }
    $amPornitServerul = true;
}

$app = require $radacina . '/config/app.php';
$db = Database::connection((array) $app['db']);
if (!$db instanceof PDO || (int) $db->query('SELECT COUNT(*) FROM blog_posts WHERE is_published = 1')->fetchColumn() < 5) {
    $scrie('EROARE: baza de test nu are datele demo; rulează întâi tools/dev/instaleaza.sh');
    exit(1);
}
$unu = static function (string $sql, array $param = []) use ($db): string {
    $stmt = $db->prepare($sql);
    $stmt->execute($param);
    $valoare = $stmt->fetchColumn();
    return $valoare === false || $valoare === null ? '' : (string) $valoare;
};

// ── Instantaneul întregii baze: fiecare tabel, pus la loc la final și verificat cu CHECKSUM TABLE.
$tabeleBaza = [];
foreach ($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
    ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) as $tabel) {
    $chei = $db->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND CONSTRAINT_NAME = 'PRIMARY'");
    $chei->execute(['t' => $tabel]);
    $coloaneCheie = $chei->fetchAll(PDO::FETCH_COLUMN);
    $tabeleBaza[] = ['tabel' => (string) $tabel, 'unde' => '1 = 1', 'cheie' => count($coloaneCheie) === 1 ? (string) $coloaneCheie[0] : null];
}
$sumeBaza = static function () use ($db, $tabeleBaza): array {
    $sume = [];
    $lista = implode(', ', array_map(static fn (array $t): string => '`' . $t['tabel'] . '`', $tabeleBaza));
    foreach ($db->query('CHECKSUM TABLE ' . $lista)->fetchAll(PDO::FETCH_NUM) as $rand) {
        $sume[(string) $rand[0]] = (string) $rand[1];
    }
    return $sume;
};
$instantaneu = salvari_instantaneu($db, $tabeleBaza);
$sumeInainte = $sumeBaza();
$sesiuniInainte = glob(Paths::stocare('sessions') . '/sess_*') ?: [];
$fisierErori = $dosarDev . '/php-erori.log';
$pozitieErori = is_file($fisierErori) ? (int) filesize($fisierErori) : 0;
$dosarMail = $dosarDev . '/mail';
@mkdir($dosarMail, 0775, true);
$mailInainte = glob($dosarMail . '/*.eml') ?: [];
$uploadsInainte = [];
foreach (['gallery', 'documente'] as $sub) {
    $uploadsInainte[$sub] = glob(Paths::uploads($sub) . '/*') ?: [];
}
$fatalLog = Paths::stocare('logs') . '/fatal.log';
$fatalInainte = is_file($fatalLog) ? (string) file_get_contents($fatalLog) : null;
$cacheTmpInainte = glob(Paths::stocare('cache/pages') . '/*.tmp') ?: [];

$parolaAdmin = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));
$adminEmail = (string) (getenv('CMS_DEV_ADMIN_EMAIL') ?: 'admin@exemplu.ro');
$jarAdmin = $dosarDev . '/test-porturi-vt-gr-admin-' . getmypid() . '.txt';
$jarClient = $dosarDev . '/test-porturi-vt-gr-client-' . getmypid() . '.txt';
$licentaSchimbata = false;
$fisiereTest = [];
$procese = [];
$dosarLucru = $dosarDev . '/test-porturi-vt-gr-' . getmypid();
@mkdir($dosarLucru, 0700, true);

$puneLaLoc = static function () use (&$licentaSchimbata, &$fisiereTest, &$procese, $db, $tabeleBaza, $instantaneu, $radacina, $jarAdmin,
    $jarClient, $sesiuniInainte, $amPornitServerul, $dosarMail, $mailInainte, $uploadsInainte, $fatalLog, $fatalInainte, $dosarLucru): void {
    foreach ($procese as $proces) {
        if (is_resource($proces)) {
            $stare = proc_get_status($proces);
            if (($stare['running'] ?? false) && (int) ($stare['pid'] ?? 0) > 0) {
                exec('pkill -TERM -P ' . (int) $stare['pid'] . ' 2>/dev/null');
                posix_kill((int) $stare['pid'], SIGTERM);
            }
            proc_close($proces);
        }
    }
    $procese = [];
    if ($licentaSchimbata) {
        exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/tools/dev/licenta.php') . ' toate >/dev/null 2>&1');
        $licentaSchimbata = false;
    }
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    salvari_pune_la_loc($db, $tabeleBaza, $instantaneu);
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    ResponseCache::purgePageCache();
    foreach (array_diff(glob(Paths::stocare('sessions') . '/sess_*') ?: [], $sesiuniInainte) as $sesiune) {
        @unlink($sesiune);
    }
    foreach (array_diff(glob($dosarMail . '/*.eml') ?: [], $mailInainte) as $mail) {
        @unlink($mail);
    }
    foreach ($uploadsInainte as $sub => $inainte) {
        foreach (array_diff(glob(Paths::uploads($sub) . '/*') ?: [], $inainte) as $fisier) {
            is_dir($fisier) ? exec('rm -rf ' . escapeshellarg($fisier)) : @unlink($fisier);
        }
    }
    if ($fatalInainte === null) {
        @unlink($fatalLog);
    } else {
        file_put_contents($fatalLog, $fatalInainte);
    }
    foreach ($fisiereTest as $fisier) {
        @unlink($fisier);
    }
    $fisiereTest = [];
    exec('rm -rf ' . escapeshellarg($dosarLucru));
    @unlink($jarAdmin);
    @unlink($jarClient);
    if ($amPornitServerul) {
        exec(escapeshellarg($radacina . '/tools/dev/server.sh') . ' --opreste >/dev/null 2>&1');
    }
};
register_shutdown_function(static function () use ($puneLaLoc): void {
    $puneLaLoc();
});

$logareAdmin = static function () use ($cerere, $jarAdmin, $adminEmail, $parolaAdmin): bool {
    @unlink($jarAdmin);
    $r = $cerere('POST', '/admin/login', $jarAdmin, ['email' => $adminEmail, 'password' => $parolaAdmin]);
    return $r['cod'] === 302 && str_starts_with((string) ($r['antete']['location'] ?? ''), '/admin');
};
$setare = static function (string $cheie, ?string $valoare) use ($db): void {
    if ($valoare === null) {
        $db->prepare('DELETE FROM settings WHERE `key` = :k')->execute(['k' => $cheie]);
    } else {
        $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (:k, :v) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
            ->execute(['k' => $cheie, 'v' => $valoare]);
    }
    ResponseCache::purgePageCache();
};
$licenta = static function (string ...$argumente) use ($radacina, &$licentaSchimbata): bool {
    exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/tools/dev/licenta.php') . ' '
        . implode(' ', array_map('escapeshellarg', $argumente)) . ' 2>&1', $iesire, $cod);
    $licentaSchimbata = $argumente !== ['toate'];
    ResponseCache::purgePageCache();
    return $cod === 0;
};
/** Mesajul flash din sesiune, citit din pagina următoare. */
$flash = static function (string $html): string {
    return preg_match('#<section class="panel" style="border-color:\#(?:34d399|fca5a5);[^"]*">(.*?)</section>#s', $html, $m) === 1
        ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')) : '';
};
/** Emailurile capturate după un moment (fișierele noi din dosarul de mail), cel mai nou la final. */
$mailuriNoi = static function (array $dinainte) use ($dosarMail): array {
    $noi = array_values(array_diff(glob($dosarMail . '/*.eml') ?: [], $dinainte));
    sort($noi);
    return array_map(static fn (string $f): string => (string) file_get_contents($f), $noi);
};
/** Un antet dintr-un email capturat (prima apariție, fără continuările de rând). */
$antetMail = static function (string $eml, string $nume): string {
    $cap = (string) preg_split("/\r?\n\r?\n/", $eml, 2)[0];
    return preg_match('/^' . preg_quote($nume, '/') . ':\s*(.*)$/mi', $cap, $m) === 1 ? trim($m[1]) : '';
};
/** Pornește un proces în fundal (oprit la final); întoarce resursa. */
$porneste = static function (array $comanda, array $mediu = []) use (&$procese): mixed {
    $proces = proc_open($comanda, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipe, null, array_merge(getenv(), $mediu));
    if (is_resource($proces)) {
        $procese[] = $proces;
    }
    return $proces;
};
$asteaptaPort = static function (int $p): bool {
    for ($i = 0; $i < 50; $i++) {
        $s = @fsockopen('127.0.0.1', $p, $errno, $errstr, 0.2);
        if (is_resource($s)) {
            fclose($s);
            return true;
        }
        usleep(100000);
    }
    return false;
};

if (!$logareAdmin()) {
    $scrie('EROARE: login-ul adminului de test nu merge (parola din ' . $dosarDev . '/admin-parola.txt)');
    exit(1);
}

// ── Stripe-ul fals (WP-15 C, WP-25): un server PHP pe CMS_DEV_PORT+28.
$portStripe = $port + 28;
$dosarStripe = $dosarLucru . '/stripe';
@mkdir($dosarStripe, 0700, true);
$porneste([PHP_BINARY, '-S', '127.0.0.1:' . $portStripe, $radacina . '/tools/dev/lib/stripe-fals.php'], ['STRIPE_FALS_DOSAR' => $dosarStripe]);
if (!$asteaptaPort($portStripe)) {
    $scrie('EROARE: Stripe-ul fals nu pornește pe portul ' . $portStripe);
    exit(1);
}
$cereriStripe = static function () use ($dosarStripe): array {
    $out = [];
    foreach (@file($dosarStripe . '/cereri.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linie) {
        $out[] = (array) json_decode($linie, true);
    }
    @unlink($dosarStripe . '/cereri.jsonl');
    return $out;
};
$raspunsuriStripe = static function (array $raspunsuri) use ($dosarStripe): void {
    file_put_contents($dosarStripe . '/raspunsuri.json', json_encode($raspunsuri, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
};

// ── Al doilea server al codului curent (CMS_DEV_PORT+30, aceeași bază): Stripe-ul fals ca STRIPE_API_BASE,
// session.cache_limiter gol și routerul lib/router-cache.php. Cu limitatorul implicit („nocache”) PHP trimite
// „Cache-Control: no-store” la fiecare pagină cu sesiune, iar tipul implicit text/html nu apare în headers_list(), deci
// azi nicio pagină obișnuită nu intră în cache-ul paginilor (moștenit, DEZVOLTARE.md „Erori moștenite”); așa (și cu
// cookie-ul sesiunii trimis înapoi, fără Set-Cookie) cache-ul lucrează, iar integritatea lui se poate verifica.
$port2 = $port + 30;
$baza2 = 'http://127.0.0.1:' . $port2;
$eroriServer2 = $dosarLucru . '/erori-server2.log';
$porneste([$radacina . '/tools/dev/php.sh', '-d', 'session.cache_limiter=', '-d', 'error_log=' . $eroriServer2, '-S', '127.0.0.1:' . $port2, '-t', $radacina . '/public',
    $radacina . '/tools/dev/lib/router-cache.php'], ['CMS_DEV_PORT' => (string) $port2, 'STRIPE_API_BASE' => 'http://127.0.0.1:' . $portStripe,
    'PHP_CLI_SERVER_WORKERS' => '2']);
if (!$asteaptaPort($port2)) {
    $scrie('EROARE: al doilea server nu pornește pe portul ' . $port2);
    exit(1);
}

// =====================================================================
// WP-15 A. Cache-ul paginilor (VT-C1, VT-C2)
// =====================================================================
$scrie('');
$scrie('== WP-15 A. Cache-ul paginilor: integritatea și Cache-Control');
$jarCache = $dosarLucru . '/cache-jar.txt';
$cerere('GET', $baza2 . '/health', $jarCache);
$cerere('GET', $baza2 . '/', $jarCache);
$pc = static fn (array $antete = []): array => $cerere('GET', $baza2 . '/contact', $jarCache, [], $antete);
$setare('cache_pages_enabled', '1');
$setare('cache_pages_rules_json', json_encode([['path_pattern' => '/contact', 'ttl_seconds' => 600]]));
$setare('page_cache_browser_policy', null);
$dosarCache = Paths::stocare('cache/pages');
$prima = $pc();
$fisiereHtml = glob($dosarCache . '/*.html') ?: [];
$verifica('prima cerere: MISS, pagina scrisă în cache, Cache-Control „no-cache, must-revalidate” (implicitul revalidate)',
    ($prima['antete']['x-page-cache'] ?? '') === 'MISS' && count($fisiereHtml) === 1
    && ($prima['antete']['cache-control'] ?? '') === 'no-cache, must-revalidate',
    json_encode([$prima['antete']['x-page-cache'] ?? null, $prima['antete']['cache-control'] ?? null, count($fisiereHtml)]));
$verifica('scrierea în cache nu lasă fișiere temporare (numele unic, mutat la locul lui)',
    array_diff(glob($dosarCache . '/*.tmp') ?: [], $cacheTmpInainte) === []);
$doua = $pc();
$verifica('a doua cerere: HIT, același corp, același Cache-Control', ($doua['antete']['x-page-cache'] ?? '') === 'HIT'
    && $doua['corp'] === $prima['corp'] && ($doua['antete']['cache-control'] ?? '') === 'no-cache, must-revalidate');
$etag = (string) ($prima['antete']['etag'] ?? '');
$r304 = $pc(['If-None-Match: ' . $etag]);
$verifica('If-None-Match cu ETag-ul: 304 (HIT-304), cu Cache-Control-ul politicii', $r304['cod'] === 304
    && ($r304['antete']['x-page-cache'] ?? '') === 'HIT-304' && ($r304['antete']['cache-control'] ?? '') === 'no-cache, must-revalidate');
// Fișierul trunchiat (o scriere întreruptă): nu se servește, nu dă 304, pagina se face din nou.
$fisierCache = $fisiereHtml[0] ?? '';
if ($fisierCache !== '') {
    file_put_contents($fisierCache, substr((string) file_get_contents($fisierCache), 0, 500));
} else {
    $fisierCache = $dosarLucru . '/lipsa.html';
    file_put_contents($fisierCache, '');
}
$trunchiat304 = $pc(['If-None-Match: ' . $etag]);
$verifica('fișier trunchiat + If-None-Match: nu 304, pagina întreagă din nou (MISS), cu </html>', $trunchiat304['cod'] === 200
    && ($trunchiat304['antete']['x-page-cache'] ?? '') === 'MISS' && str_contains($trunchiat304['corp'], '</html>')
    && $trunchiat304['corp'] === $prima['corp'], (string) $trunchiat304['cod'] . ' ' . ($trunchiat304['antete']['x-page-cache'] ?? ''));
$verifica('după refacere fișierul din cache e iar întreg (sha1 = ETag)', $fisierCache !== ''
    && '"' . sha1((string) file_get_contents($fisierCache)) . '"' === (string) ($trunchiat304['antete']['etag'] ?? ''));
file_put_contents($fisierCache, str_replace('</html>', '</htm1>', (string) file_get_contents($fisierCache)));
$stricat = $pc();
$verifica('fișier stricat (același lungime, alt conținut): nu se servește (MISS, pagina bună)',
    ($stricat['antete']['x-page-cache'] ?? '') === 'MISS' && $stricat['corp'] === $prima['corp']);
$cacheable = new ReflectionMethod(ResponseCache::class, 'isResponseCacheable');
$verifica('o pagină HTML fără </html> (randare întreruptă) nu intră în cache; cu </html> intră',
    $cacheable->invoke(null, 200, ['Content-Type: text/html; charset=utf-8'], '<html><body>jumătate') === false
    && $cacheable->invoke(null, 200, ['Content-Type: text/html; charset=utf-8'], '<html><body>tot</body></html>') === true);
// Politica „max_age” (înghețarea site-urilor vechi): exact antetul de până acum.
$setare('page_cache_browser_policy', 'max_age');
$maxAge = $pc();
$maxAgeHit = $pc();
$maxAge304 = $pc(['If-None-Match: ' . (string) ($maxAge['antete']['etag'] ?? '')]);
$verifica('„max_age”: MISS, HIT și 304 cu „public, max-age=600” (TTL-ul regulii), ca înainte de WP-15',
    ($maxAge['antete']['cache-control'] ?? '') === 'public, max-age=600' && ($maxAgeHit['antete']['cache-control'] ?? '') === 'public, max-age=600'
    && ($maxAgeHit['antete']['x-page-cache'] ?? '') === 'HIT' && $maxAge304['cod'] === 304
    && ($maxAge304['antete']['cache-control'] ?? '') === 'public, max-age=600',
    json_encode([$maxAge['antete']['cache-control'] ?? null, $maxAgeHit['antete']['cache-control'] ?? null, $maxAge304['antete']['cache-control'] ?? null]));
$verifica('ResponseCache::cacheControlNou: o valoare necunoscută a setării = „revalidate”; TTL peste o zi → max-age=86400',
    ResponseCache::cacheControlNou(['page_cache_browser_policy' => 'x'], 600) === 'no-cache, must-revalidate'
    && ResponseCache::cacheControlNou(['page_cache_browser_policy' => 'max_age'], 999999) === 'public, max-age=86400');
$setare('page_cache_browser_policy', null);
$setare('cache_pages_enabled', null);
$setare('cache_pages_rules_json', null);

// =====================================================================
// WP-15 B. Jurnalul erorilor fatale (VT-C3)
// =====================================================================
$scrie('');
$scrie('== WP-15 B. Jurnalul erorilor fatale');
$scriptFatal = $dosarLucru . '/fatal.php';
file_put_contents($scriptFatal, '<?php
$_SERVER["REQUEST_URI"] = "/_andaxi/ping?token=secret-de-test";
$_SERVER["REQUEST_METHOD"] = "GET";
ob_start();
require ' . var_export($radacina . '/public/index.php', true) . ';
ob_end_clean();
functie_care_nu_exista_wp15();
');
$inainteFatal = is_file($fatalLog) ? (string) file_get_contents($fatalLog) : '';
// Cu jurnalul de erori al lui (eroarea e voită; jurnalul comun îl citește testul de fum, care nu primește Fatal).
exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' -d ' . escapeshellarg('error_log=' . $dosarLucru . '/erori-fatal.log') . ' '
    . escapeshellarg($scriptFatal) . ' >/dev/null 2>&1');
$dupaFatal = is_file($fatalLog) ? (string) file_get_contents($fatalLog) : '';
$randNou = substr($dupaFatal, strlen($inainteFatal));
$verifica('o eroare fatală lasă un rând în storage/logs/fatal.log: momentul, „GET /_andaxi/ping”, mesajul, fișierul și linia',
    str_starts_with($dupaFatal, $inainteFatal) && str_contains($randNou, 'GET /_andaxi/ping')
    && str_contains($randNou, 'functie_care_nu_exista_wp15') && str_contains($randNou, 'fatal.php:'), $randNou);
$verifica('rândul nu are query string-ul (tokenurile din adrese nu ajung în jurnal)', !str_contains($randNou, 'secret-de-test')
    && !str_contains($randNou, '?'));
$verifica('dosarul jurnalului are gardul .htaccess (Paths::stocareSite)', is_file(dirname($fatalLog) . '/.htaccess'));

// =====================================================================
// WP-15 C. StripeGateway (VT-C10) pe Stripe-ul fals
// =====================================================================
$scrie('');
$scrie('== WP-15 C. StripeGateway: Stripe-Version, Idempotency-Key, DELETE, query, webhook-uri');
putenv('STRIPE_API_BASE=http://127.0.0.1:' . $portStripe);
$_ENV['STRIPE_API_BASE'] = 'http://127.0.0.1:' . $portStripe;
$setare('stripe_api_version', null);
$versiuneProp = new ReflectionProperty(StripeGateway::class, 'versiuneApi');
$versiuneProp->setValue(null, null);
$cereriStripe();
StripeGateway::createCheckoutSession('sk_test_inventat', ['mode' => 'payment']);
$c1 = $cereriStripe();
$verifica('fără setarea stripe_api_version: cererea pleacă fără Stripe-Version și fără Idempotency-Key, cu cheia ca Bearer',
    count($c1) === 1 && !isset($c1[0]['antete']['stripe-version']) && !isset($c1[0]['antete']['idempotency-key'])
    && ($c1[0]['antete']['authorization'] ?? '') === 'Bearer sk_test_inventat' && ($c1[0]['corp'] ?? '') === 'mode=payment'
    && ($c1[0]['cale'] ?? '') === '/v1/checkout/sessions', json_encode($c1));
$setare('stripe_api_version', '2026-08-26.proba');
$versiuneProp->setValue(null, null);
StripeGateway::createPaymentIntent('sk_test_inventat', ['amount' => 1000, 'currency' => 'ron'], 'don-R1-pi');
$c2 = $cereriStripe();
$verifica('cu setarea: Stripe-Version citit din baza conexiunii; Idempotency-Key la o creare care îl primește',
    ($c2[0]['antete']['stripe-version'] ?? '') === '2026-08-26.proba' && ($c2[0]['antete']['idempotency-key'] ?? '') === 'don-R1-pi'
    && ($c2[0]['cale'] ?? '') === '/v1/payment_intents', json_encode($c2));
StripeGateway::folosesteSetarile(['stripe_api_version' => '']);
StripeGateway::cancelSubscription('sk_test_inventat', 'sub_123');
$c3 = $cereriStripe();
$verifica('folosesteSetarile cu valoarea goală: fără antet; cancelSubscription = DELETE /v1/subscriptions/sub_123',
    ($c3[0]['metoda'] ?? '') === 'DELETE' && ($c3[0]['cale'] ?? '') === '/v1/subscriptions/sub_123' && !isset($c3[0]['antete']['stripe-version']),
    json_encode($c3));
StripeGateway::folosesteSetarile(['stripe_api_version' => '2026-08-26.proba']);
StripeGateway::retrievePaymentIntent('sk_test_inventat', 'pi_1', ['expand[]' => 'latest_charge']);
StripeGateway::retrieveSubscription('sk_test_inventat', 'sub_9', ['expand[]' => 'latest_invoice']);
StripeGateway::retrieveCheckoutSession('sk_test_inventat', 'cs_1', ['expand[]' => 'payment_intent']);
StripeGateway::listWebhookEndpoints('sk_test_inventat');
StripeGateway::createCustomer('sk_test_inventat', ['email' => 'ana@exemplu.ro'], 'don-R1-cus');
StripeGateway::createProduct('sk_test_inventat', ['name' => 'Donație'], null);
StripeGateway::createSubscription('sk_test_inventat', ['customer' => 'cus_1'], 'don-R1-sub');
StripeGateway::retrievePaymentIntent('sk_test_inventat', 'pi_2');
$c4 = $cereriStripe();
$rez = array_map(static fn (array $c): string => $c['metoda'] . ' ' . $c['cale'] . ($c['query'] !== '' ? '?' . urldecode($c['query']) : '')
    . ' [' . ($c['antete']['idempotency-key'] ?? '-') . ']', $c4);
$verifica('metodele noi: GET cu query (expand), sesiunea de checkout fără query (moștenit), webhook-urile cu limit=100, POST-urile '
    . 'cu Idempotency-Key doar unde e dat; Stripe-Version pe toate', $rez === [
        'GET /v1/payment_intents/pi_1?expand[]=latest_charge [-]',
        'GET /v1/subscriptions/sub_9?expand[]=latest_invoice [-]',
        'GET /v1/checkout/sessions/cs_1 [-]',
        'GET /v1/webhook_endpoints?limit=100 [-]',
        'POST /v1/customers [don-R1-cus]',
        'POST /v1/products [-]',
        'POST /v1/subscriptions [don-R1-sub]',
        'GET /v1/payment_intents/pi_2 [-]',
    ] && count(array_filter($c4, static fn (array $c): bool => ($c['antete']['stripe-version'] ?? '') === '2026-08-26.proba')) === 8,
    json_encode($rez, JSON_UNESCAPED_SLASHES));
$raspunsuriStripe(['GET /v1/payment_intents/pi_eroare' => ['cod' => 402, 'corp' => ['error' => ['message' => 'Card refuzat (fals).']]]]);
$eroare = '';
try {
    StripeGateway::retrievePaymentIntent('sk_test_inventat', 'pi_eroare');
} catch (RuntimeException $e) {
    $eroare = $e->getMessage();
}
$cereriStripe();
$verifica('un răspuns de eroare al Stripe devine excepția cu mesajul lui', $eroare === 'Card refuzat (fals).', $eroare);
putenv('STRIPE_API_BASE=https://altundeva.exemplu.ro');
$_ENV['STRIPE_API_BASE'] = 'https://altundeva.exemplu.ro';
$verifica('STRIPE_API_BASE care nu e o adresă locală e ignorată (cheia nu pleacă altundeva decât la api.stripe.com)',
    StripeGateway::apiBase() === 'https://api.stripe.com');
putenv('STRIPE_API_BASE');
unset($_ENV['STRIPE_API_BASE']);
$verifica('fără STRIPE_API_BASE: api.stripe.com', StripeGateway::apiBase() === 'https://api.stripe.com');
StripeGateway::folosesteSetarile(['stripe_api_version' => '']);
$setare('stripe_api_version', null);
$raspunsuriStripe([]);

// =====================================================================
// WP-15 D. Reply-To (GR-01)
// =====================================================================
$scrie('');
$scrie('== WP-15 D. Reply-To = cine a scris');
$setariMail = Settings::all($db);
$expeditor = trim((string) ($setariMail['order_email_from_address'] ?? ''));
$dinainte = glob($dosarMail . '/*.eml') ?: [];
$setare('contact_form_recipients', 'birou@exemplu.ro');
$rc = $cerere('POST', '/contact/send', null, json_encode(['name' => 'Ana Exemplu', 'email' => 'ana.vizitator@exemplu.ro',
    'subject' => 'Întrebare', 'message' => 'Bună ziua, o întrebare de test.']), ['Content-Type: application/json']);
$mailuri = $mailuriNoi($dinainte);
$mailContact = (string) end($mailuri);
$verifica('formularul de contact: emailul spre birou are Reply-To = adresa vizitatorului, From rămâne expeditorul site-ului',
    $rc['cod'] === 200 && count($mailuri) === 1 && $antetMail($mailContact, 'Reply-To') === 'ana.vizitator@exemplu.ro'
    && str_contains($antetMail($mailContact, 'From'), '<' . $expeditor . '>') && $antetMail($mailContact, 'To') === 'birou@exemplu.ro',
    $rc['cod'] . ' ' . count($mailuri) . ' ' . $antetMail($mailContact, 'Reply-To') . ' / ' . $antetMail($mailContact, 'From'));
$setare('contact_form_recipients', null);
$dinainte = glob($dosarMail . '/*.eml') ?: [];
OrderMailer::sendCustom('client@exemplu.ro', 'Comandă (test)', '<p>x</p>', $setariMail, $db, ['email_type' => 'test']);
OrderMailer::sendCustom('client@exemplu.ro', 'Comandă (test 2)', '<p>x</p>', $setariMail, $db,
    ['email_type' => 'test', 'from_email' => "rau@exemplu.ro\r\nBcc: altcineva@exemplu.ro"]);
OrderMailer::sendTemplate('new_order', ['to' => 'client@exemplu.ro', 'order_number' => 'CMD-TEST-1', 'customer_name' => 'Ana'],
    $setariMail, $db, ['from_email' => 'ana.vizitator@exemplu.ro']);
$mailuri = $mailuriNoi($dinainte);
$verifica('fără from_email (emailurile comenzii) Reply-To = expeditorul; o adresă cu rând nou e ignorată (expeditorul, fără Bcc)',
    count($mailuri) === 3 && $antetMail($mailuri[0], 'Reply-To') === $expeditor && $antetMail($mailuri[1], 'Reply-To') === $expeditor
    && !str_contains($mailuri[1], 'altcineva@exemplu.ro'), json_encode(array_map(static fn ($m) => $antetMail($m, 'Reply-To'), $mailuri)));
$verifica('sendTemplate cu from_email: Reply-To = vizitatorul', $antetMail($mailuri[2] ?? '', 'Reply-To') === 'ana.vizitator@exemplu.ro');
$istoric = $db->query("SELECT COUNT(*) FROM email_send_history WHERE recipient = 'client@exemplu.ro' AND status = 'sent'")->fetchColumn();
$verifica('trimiterile au rândurile lor în istoricul emailurilor (status sent)', (int) $istoric >= 3, (string) $istoric);
// Drumul SMTP, cu un server SMTP fals: aceleași antete.
$portSmtp = $port + 29;
$scriptSmtp = $dosarLucru . '/smtp-fals.php';
file_put_contents($scriptSmtp, '<?php
$s = stream_socket_server("tcp://127.0.0.1:' . $portSmtp . '", $e, $es);
while ($c = @stream_socket_accept($s, 30)) {
    fwrite($c, "220 fals\r\n"); $date = false; $mesaj = "";
    while (($l = fgets($c)) !== false) {
        if ($date) { if (rtrim($l, "\r\n") === ".") { $date = false; file_put_contents(' . var_export($dosarLucru . '/smtp.eml', true) . ', $mesaj, FILE_APPEND); fwrite($c, "250 ok\r\n"); } else { $mesaj .= $l; } continue; }
        $cmd = strtoupper(substr(trim($l), 0, 4));
        if ($cmd === "EHLO") { fwrite($c, "250 fals\r\n"); }
        elseif ($cmd === "DATA") { $date = true; fwrite($c, "354 date\r\n"); }
        elseif ($cmd === "QUIT") { fwrite($c, "221 pa\r\n"); break; }
        else { fwrite($c, "250 ok\r\n"); }
    }
    fclose($c);
}
');
$porneste([PHP_BINARY, $scriptSmtp]);
$asteaptaPort($portSmtp);
$setariSmtp = array_merge($setariMail, ['email_delivery_method' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => (string) $portSmtp,
    'smtp_encryption' => 'none', 'smtp_username' => '', 'smtp_password' => '']);
OrderMailer::sendCustom('birou@exemplu.ro', 'Contact (SMTP)', '<p>x</p>', $setariSmtp, null, ['from_email' => 'ana.vizitator@exemplu.ro']);
OrderMailer::sendCustom('client@exemplu.ro', 'Comandă (SMTP)', '<p>x</p>', $setariSmtp, null, []);
$smtp = (string) @file_get_contents($dosarLucru . '/smtp.eml');
$parti = preg_split('/^Date: /m', $smtp, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$verifica('SMTP: Reply-To = vizitatorul la contact, = expeditorul la comandă; From = expeditorul în ambele',
    count($parti) === 2 && $antetMail('Date: ' . $parti[0], 'Reply-To') === 'ana.vizitator@exemplu.ro'
    && $antetMail('Date: ' . $parti[1], 'Reply-To') === $expeditor && str_contains($antetMail('Date: ' . $parti[0], 'From'), '<' . $expeditor . '>'),
    mb_substr($smtp, 0, 600));

// =====================================================================
// WP-15 E. {{an}} în antet și subsol (GR-06)
// =====================================================================
$scrie('');
$scrie('== WP-15 E. {{an}} în Design site');
$setare('design_header_html', '<header class="antet-test-wp15">Antet {{an}} {{menu}}</header>');
$setare('design_footer_html', '<footer class="subsol-test-wp15">© {{an}} Firma Demo</footer>');
$pagina = $cerere('GET', '/contact')['corp'];
$an = date('Y');
$verifica('antetul și subsolul primesc anul curent în locul lui {{an}}', str_contains($pagina, 'Antet ' . $an . ' ')
    && str_contains($pagina, '© ' . $an . ' Firma Demo') && !str_contains($pagina, '{{an}}'));
$adminDesign = $cerere('GET', '/admin/design?section=footer', $jarAdmin)['corp'];
$verifica('Design site arată ajutorul pentru {{an}}', str_contains($adminDesign, '{{an}}') && str_contains($adminDesign, 'anul curent'));
$setare('design_header_html', null);
$setare('design_footer_html', null);

// =====================================================================
// WP-15 F. Galeria: PDF-uri și încărcarea multiplă (GR-04, GR-05)
// =====================================================================
$scrie('');
$scrie('== WP-15 F. Galeria: PDF-uri, verificarea conținutului, încărcarea multiplă');
$pdf = $dosarLucru . '/brosura-test.pdf';
file_put_contents($pdf, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
$pdfFals = $dosarLucru . '/document-fals.pdf';
file_put_contents($pdfFals, "<html>nu sunt un PDF</html>");
$png = $dosarLucru . '/poza-test.png';
$imagine = imagecreatetruecolor(4, 3);
imagepng($imagine, $png);
imagedestroy($imagine);
$pngFals = $dosarLucru . '/poza-falsa.png';
file_put_contents($pngFals, 'text, nu imagine');
$txt = $dosarLucru . '/notite.txt';
file_put_contents($txt, 'text');
$numarGalerie = static fn (): int => (int) $db->query('SELECT COUNT(*) FROM gallery_images')->fetchColumn();
$inainte = $numarGalerie();
$r = $cerere('POST', '/admin/gallery', $jarAdmin, ['title' => 'Brosura test', 'image_file' => new CURLFile($pdf, 'application/pdf', 'brosura-test.pdf'),
    'is_active' => '1']);
$randPdf = $db->query("SELECT media_type, image_url FROM gallery_images ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$verifica('PDF în galerie: rând cu media_type „document”, fișierul în /uploads/gallery/', $r['cod'] === 302 && $numarGalerie() === $inainte + 1
    && ($randPdf['media_type'] ?? '') === 'document' && preg_match('#^/uploads/gallery/brosura-test(-\d+)?\.pdf$#', (string) ($randPdf['image_url'] ?? '')) === 1
    && is_file(Paths::public() . (string) ($randPdf['image_url'] ?? '')), json_encode($randPdf));
$galerie = $cerere('GET', '/admin/gallery', $jarAdmin)['corp'];
$verifica('grila galeriei arată fișa documentului (PDF, numele fișierului, link într-o filă nouă)', str_contains($galerie, 'gallery-item__document')
    && str_contains($galerie, basename((string) ($randPdf['image_url'] ?? 'x'))) && str_contains($galerie, 'application/pdf'));
$r = $cerere('POST', '/admin/gallery', $jarAdmin, ['title' => 'Fals', 'image_file' => new CURLFile($pdfFals, 'application/pdf', 'document-fals.pdf')]);
$verifica('un „PDF” care nu începe cu %PDF- e refuzat (niciun rând nou)', $numarGalerie() === $inainte + 1);
$r = $cerere('POST', '/admin/gallery', $jarAdmin, ['title' => 'Poza falsă', 'image_file' => new CURLFile($pngFals, 'image/png', 'poza-falsa.png')]);
$verifica('o „imagine” pe care getimagesize() n-o citește e refuzată', $numarGalerie() === $inainte + 1);
foreach (['/admin/products/image-upload', '/admin/blog/posts/image-upload'] as $cale) {
    $r = $cerere('POST', $cale, $jarAdmin, ['title' => 'Pdf in produs', 'image_file' => new CURLFile($pdf, 'application/pdf', 'brosura-test.pdf')],
        ['Accept: application/json']);
    $verifica('PDF refuzat la ' . $cale . ' (422, niciun rând, niciun fișier nou)', $r['cod'] === 422 && $numarGalerie() === $inainte + 1, $r['cod'] . ' ' . $r['corp']);
}
$r = $cerere('POST', '/admin/products/image-upload', $jarAdmin, ['title' => 'Poza produs test', 'image_file' => new CURLFile($png, 'image/png', 'poza-test.png')],
    ['Accept: application/json']);
$verifica('o imagine adevărată trece la încărcarea din editorul de produs', $r['cod'] === 200 && $numarGalerie() === $inainte + 2, $r['cod'] . ' ' . $r['corp']);
$jurnalInainte = (int) $unu("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'galerie_incarcare_multipla'");
$folder = (int) $unu('SELECT id FROM gallery_folders ORDER BY id LIMIT 1');
$r = $cerere('POST', '/admin/gallery/bulk-upload', $jarAdmin, [
    'image_files[0]' => new CURLFile($png, 'image/png', 'Poza_de-test unu.png'),
    'image_files[1]' => new CURLFile($pdf, 'application/pdf', 'catalog-servicii.pdf'),
    'image_files[2]' => new CURLFile($txt, 'text/plain', 'notite.txt'),
    'folder_id' => (string) $folder,
    'is_active' => '1',
]);
$noi = $db->query('SELECT title, alt_text, media_type, folder_id, is_active FROM gallery_images ORDER BY id DESC LIMIT 2')->fetchAll(PDO::FETCH_ASSOC);
$mesaj = $flash($cerere('GET', '/admin/gallery', $jarAdmin)['corp']);
$verifica('încărcarea multiplă: 2 rânduri (titlul și textul alternativ din numele fișierului, folderul ales, activ), PDF-ul ca document',
    $r['cod'] === 302 && $numarGalerie() === $inainte + 4 && ($noi[1]['title'] ?? '') === 'Poza de test unu' && ($noi[1]['alt_text'] ?? '') === 'Poza de test unu'
    && ($noi[0]['title'] ?? '') === 'catalog servicii' && ($noi[0]['media_type'] ?? '') === 'document'
    && (int) ($noi[0]['folder_id'] ?? 0) === $folder && (int) ($noi[1]['is_active'] ?? 0) === 1, json_encode($noi, JSON_UNESCAPED_UNICODE));
$verifica('mesajul numără fișierele încărcate și îl numește pe cel refuzat', str_contains($mesaj, 'Au fost încărcate 2 fișiere')
    && str_contains($mesaj, 'notite.txt'), $mesaj);
$jurnal = $db->query("SELECT context_json FROM admin_activity_log WHERE action = 'galerie_incarcare_multipla' ORDER BY id DESC LIMIT 1")->fetchColumn();
$verifica('un singur rând în jurnalul adminilor pentru toată încărcarea (2 încărcate, 1 eșuat)',
    (int) $unu("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'galerie_incarcare_multipla'") === $jurnalInainte + 1
    && str_contains((string) $jurnal, '"incarcate":2') && str_contains((string) $jurnal, '"esuate":1'), (string) $jurnal);
$r = $cerere('POST', '/admin/gallery/bulk-upload', $jarAdmin, ['folder_id' => '']);
$verifica('fără niciun fișier: „Nu a fost selectat niciun fișier.”', $r['cod'] === 302
    && str_contains($flash($cerere('GET', '/admin/gallery', $jarAdmin)['corp']), 'Nu a fost selectat niciun fișier'));
$mare = $dosarLucru . '/mare.bin';
$limitaPost = (string) ini_get('post_max_size');
$octetiLimita = (int) $limitaPost * (str_ends_with(strtolower($limitaPost), 'm') ? 1048576 : (str_ends_with(strtolower($limitaPost), 'g') ? 1073741824 : 1));
file_put_contents($mare, str_repeat('x', $octetiLimita + 1024));
// Pe al doilea server, cu jurnalul lui: PHP scrie acolo avertismentul cererii prea mari (așteptat).
$jarAdmin2 = $dosarLucru . '/admin2-jar.txt';
$cerere('POST', $baza2 . '/admin/login', $jarAdmin2, ['email' => $adminEmail, 'password' => $parolaAdmin]);
$r = $cerere('POST', $baza2 . '/admin/gallery/bulk-upload', $jarAdmin2, ['image_files[0]' => new CURLFile($mare, 'image/png', 'mare.png')]);
$mesajMare = $flash($cerere('GET', $baza2 . '/admin/gallery', $jarAdmin2)['corp']);
$verifica('o cerere peste post_max_size (' . $limitaPost . '): mesajul spune limita, nu „niciun fișier”', $r['cod'] === 302
    && str_contains($mesajMare, 'peste limita serverului') && $numarGalerie() === $inainte + 4, $mesajMare);
@unlink($mare);

// ##WP24##

// =====================================================================
// Final: baza pusă la loc, nicio eroare PHP nouă
// =====================================================================
$scrie('');
$scrie('== Final');
$eroriServer2Text = (string) @file_get_contents($eroriServer2);
$puneLaLoc();
$verifica('baza pusă la loc: CHECKSUM TABLE identic pe fiecare tabel', $sumeBaza() === $sumeInainte,
    implode(', ', array_keys(array_diff_assoc($sumeBaza(), $sumeInainte))));
$eroriNoi = is_file($fisierErori) ? substr((string) file_get_contents($fisierErori), $pozitieErori) : '';
$grave = preg_match_all('/PHP (Warning|Notice|Fatal error|Parse error)/', $eroriNoi);
$verifica('nicio eroare PHP nouă în jurnal', $grave === 0, mb_substr($eroriNoi, 0, 1500));
$eroriDoi = $eroriServer2Text;
$verifica('al doilea server: în jurnalul lui doar avertismentul așteptat al cererii peste post_max_size',
    (int) preg_match_all('/PHP (Warning|Notice|Fatal error|Parse error)/', $eroriDoi) === 1
    && preg_match('/PHP Warning:  PHP Request Startup: POST Content-Length of \d+ bytes exceeds the limit/', $eroriDoi) === 1,
    mb_substr($eroriDoi, 0, 1500));

$scrie('');
$scrie('Rezultat: ' . $trecute . ' verificări trecute, ' . $esuate . ' eșuate. Raport: ' . $fisierRaport);
exit($esuate === 0 ? 0 : 1);
