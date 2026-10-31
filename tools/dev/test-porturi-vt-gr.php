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
 * WP-24 — punctele de legătură și poarta conținutului pentru membri (VT-X2, VT-C4):
 * A. App\Support\Hooks: punct necunoscut refuzat, agățarea contează doar cât pluginul e vizibil (sau activ).
 * B. App\Support\AccesMembri: decizia (liber / notă / 404), listele, condiția SQL, căile restricționate.
 * C. Pe site: paginile și articolele doar pentru membri (404 cu `membri` oprit, nota 403 cu el pornit), API-ul, listele,
 *    tokenurile categoriei, harta site-ului, linkurile din meniu.
 * D. Cu agățări de probă (al doilea server, CMS_DEV_HOOKS): membrul activ, nota pluginului, ștergerea contului,
 *    înregistrarea, câmpurile editorului, layout.final_body.
 *
 * WP-25 — conturile și infrastructura Stripe (VT-X1, VT-X3, VT-X4, VT-C5, VT-C8, VT-C9):
 * A. App\Support\StripeEvenimente: proprietarul unui eveniment, evenimentele cerute, verificarea webhook-ului.
 * B. /webhook/stripe: semnătura, notarea în stripe_events, duplicatele, proprietarul oprit.
 * C. Setări plăți: evenimentele cerute, ultimul primit, „Verifică webhook-ul” pe Stripe-ul fals.
 * D. {{auth_form}}, regula comenzilor fără cont, confirmarea adresei (link o dată, 7 zile, retrimiterea limitată),
 *    tab-urile contului.
 *
 * WP-26 — cererile de ofertă, meniul fără magazin, uneltele de operare (GR-15, GR-14c, GR-21, VT-O1):
 * A. App\Support\CereriOferta (hash-ul adresei, limita), grupul „Produse” fără magazin, regula anonimizării.
 * B. {{product_quote_button}}, /api/cerere-oferta (salvarea, emailul, validarea, capcana, limita 429), lista din admin
 *    (CSRF, starea, ștergerea, numele în confirm()), stările se oprește / oprit, licența grafoanaytis.
 * C. scripts/exporta-date-legale.php și /_andaxi/export/{token} (rândurile, 0600, o singură folosire, expirarea).
 * D. scripts/verifica-email.php și scripts/diagnostic.php --email (fără adrese și parole).
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

use App\Support\AccesMembri;
use App\Support\CereriOferta;
use App\Support\ConfirmareEmail;
use App\Support\Database;
use App\Support\Hooks;
use App\Support\OrderMailer;
use App\Support\Paths;
use App\Support\Plugins;
use App\Support\ResponseCache;
use App\Support\Settings;
use App\Support\StripeEvenimente;
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
    // Din WP-31 adminul arată mesajul cu componenta mesaj (id ax-mesaj-succes / ax-mesaj-eroare); paginile site-ului, ca înainte.
    if (preg_match('#<div class="adm-mesaj [^"]*" id="ax-mesaj-(?:succes|eroare)"[^>]*>(.*?)</div>\s*</div>#s', $html, $m) === 1) {
        return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
    }
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
// Agățările de probă ale celui de-al doilea server (WP-24 D: CMS_DEV_HOOKS, citit de App\Support\Hooks doar cu APP_ENV=local).
$fisierHooks = $dosarLucru . '/hooks-proba.php';
file_put_contents($fisierHooks, <<<'HOOKS'
<?php
$jurnal = (string) getenv('CMS_DEV_HOOKS_JURNAL');
$scrie = static function (array $intrare) use ($jurnal): void {
    file_put_contents($jurnal, json_encode($intrare, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
};
$camp = static function (array $rand): string {
    return '<input type="hidden" name="requires_active_account_present" value="1"><label><input type="checkbox" name="requires_active_account"'
        . ' value="1"' . ((int) ($rand['requires_active_account'] ?? 0) === 1 ? ' checked' : '') . '> Doar cont activ</label>';
};
return [
    ['punct' => 'continut.membru_activ', 'plugin' => 'membri', 'fn' => static fn ($db, array $u): bool => ($u['email'] ?? '') === 'membru.activ@exemplu.ro'],
    ['punct' => 'continut.nota', 'plugin' => 'membri', 'fn' => static fn ($db, $u, string $titlu): string
        => '<div class="nota-plugin-test">Nota pluginului: ' . htmlspecialchars($titlu, ENT_QUOTES) . '</div>'],
    ['punct' => 'layout.final_body', 'plugin' => 'donatii', 'fn' => static fn (): string => '<!--final-body-test-->'],
    ['punct' => 'utilizator.sters', 'plugin' => 'membri', 'fn' => static function ($db, array $iduri, string $din) use ($scrie): void {
        $scrie(['ids' => $iduri, 'din' => $din]);
    }],
    ['punct' => 'inregistrare.formular', 'plugin' => 'membri', 'stare' => 'activ', 'fn' => static fn (): string
        => '<div class="camp-inregistrare-test">Profesia (test)</div>'],
    ['punct' => 'inregistrare.valideaza', 'plugin' => 'membri', 'stare' => 'activ', 'fn' => static fn ($db, array $s): array
        => (($_POST['profesie_test'] ?? '') === 'rea') ? ['error' => 'Profesia aleasă nu e validă (test).']
            : ['data' => ['profesie' => (string) ($_POST['profesie_test'] ?? '')]]],
    ['punct' => 'inregistrare.cont_existent', 'plugin' => 'membri', 'stare' => 'activ', 'fn' => static fn ($db, string $email, array $date): ?string
        => ($date['profesie'] ?? '') === 'reluare' ? '/contact?reluare=1' : null],
    ['punct' => 'inregistrare.dupa_creare', 'plugin' => 'membri', 'stare' => 'activ', 'fn' => static function ($db, int $id, array $date) use ($scrie): ?string {
        $scrie(['creat' => $id, 'date' => $date]);
        return ($date['profesie'] ?? '') === 'confirmare' ? '/contact?confirma=1' : null;
    }],
    ['punct' => 'editor.pagina.campuri', 'plugin' => 'membri', 'fn' => static fn (array $rand): string => $camp($rand)],
    ['punct' => 'editor.articol.campuri', 'plugin' => 'membri', 'fn' => static fn (array $rand): string => $camp($rand)],
    ['punct' => 'cont.taburi', 'plugin' => 'donatii', 'fn' => static fn ($db, array $u): array => ($u['email'] ?? '') !== 'tab.test@exemplu.ro' ? []
        : [['cheie' => 'donatii-test', 'eticheta' => 'Donațiile mele (test)', 'icon' => '', 'insigna' => '',
            'render' => static fn (): string => '<div class="tab-plugin-test-continut">Donații (test)</div>']]],
    ['punct' => 'cont.antet', 'plugin' => 'donatii', 'fn' => static fn ($db, array $u): array => ($u['email'] ?? '') !== 'tab.test@exemplu.ro' ? []
        : [['eticheta' => 'Donații', 'valoare' => '3']]],
];
HOOKS);
$eroriServer2 = $dosarLucru . '/erori-server2.log';
$porneste([$radacina . '/tools/dev/php.sh', '-d', 'session.cache_limiter=', '-d', 'error_log=' . $eroriServer2, '-S', '127.0.0.1:' . $port2, '-t', $radacina . '/public',
    $radacina . '/tools/dev/lib/router-cache.php'], ['CMS_DEV_PORT' => (string) $port2, 'STRIPE_API_BASE' => 'http://127.0.0.1:' . $portStripe,
    'PHP_CLI_SERVER_WORKERS' => '2', 'CMS_DEV_HOOKS' => $fisierHooks, 'CMS_DEV_HOOKS_JURNAL' => $dosarLucru . '/hooks-jurnal.jsonl']);
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

// =====================================================================
// WP-24 A. Hooks, fără server
// =====================================================================
$scrie('');
$scrie('== WP-24 A. Punctele de legătură (App\Support\Hooks)');
$aruncat = static function (callable $f): string {
    try {
        $f();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return '';
};
Hooks::reseteaza();
$verifica('fără agățări (config/hooks.php gol): html() gol, ruleaza() [], oricare() false, primul() null',
    Hooks::html('layout.final_body') === '' && Hooks::ruleaza('utilizator.sters', $db, [1], 'admin') === []
    && Hooks::oricare('continut.membru_activ', $db, []) === false && Hooks::primul('inregistrare.dupa_creare', $db, 1, []) === null);
$verifica('un punct necunoscut sau un plugin care nu e în catalog e refuzat', str_contains($aruncat(static fn () => Hooks::adauga('layout.nu_exista', 'membri', static fn () => '')), 'nu există')
    && str_contains($aruncat(static fn () => Hooks::adauga('layout.final_body', 'plugin_inventat', static fn () => '')), 'nu e în catalog'));
Hooks::adauga('layout.final_body', 'donatii', static fn (): string => '<i>d</i>');
Hooks::adauga('layout.final_body', 'membri', static fn (): string => '<i>m</i>');
Hooks::adauga('inregistrare.formular', 'membri', static fn (): string => '<i>f</i>', 'activ');
Hooks::adauga('inregistrare.dupa_creare', 'membri', static fn (PDO $d, int $id): ?string => $id === 7 ? '/x' : null);
Hooks::adauga('continut.membru_activ', 'membri', static fn (): bool => true);
Plugins::folosesteStari(array_merge(Plugins::stari(), ['donatii' => Plugins::PORNIT, 'membri' => Plugins::PORNIT]));
$verifica('cu pluginurile pornite: html() lipește în ordinea agățării, primul() dă prima valoare, oricare() true',
    Hooks::html('layout.final_body') === '<i>d</i><i>m</i>' && Hooks::primul('inregistrare.dupa_creare', $db, 7, []) === '/x'
    && Hooks::primul('inregistrare.dupa_creare', $db, 8, []) === null && Hooks::oricare('continut.membru_activ', $db, []) === true
    && array_keys(Hooks::ruleaza('layout.final_body')) === ['donatii', 'membri']);
Plugins::folosesteStari(array_merge(Plugins::stari(), ['donatii' => Plugins::OPRIT, 'membri' => Plugins::SE_OPRESTE]));
$verifica('donatii oprit: agățarea lui nu rulează; membri „se oprește”: cele „vizibil” rulează, cele „activ” nu',
    Hooks::html('layout.final_body') === '<i>m</i>' && Hooks::html('inregistrare.formular') === '' && Hooks::are('continut.membru_activ')
    && !Hooks::are('inregistrare.formular'));
Plugins::reseteaza();
Hooks::reseteaza();
Plugins::incarca();

// =====================================================================
// WP-24 B. AccesMembri, fără server
// =====================================================================
$scrie('');
$scrie('== WP-24 B. Poarta conținutului pentru membri (App\Support\AccesMembri)');
AccesMembri::reseteaza();
$restr = ['title' => 'R', 'content' => '<p>secret</p>', 'content_text' => 'secret', 'requires_active_account' => '1'];
$liber = ['title' => 'L', 'content' => '<p>public</p>', 'content_text' => 'public', 'requires_active_account' => '0'];
$stariCu = static fn (string $membri): array => array_merge(Plugins::stari(), ['membri' => $membri]);
Plugins::folosesteStari($stariCu(Plugins::OPRIT));
$verifica('membri oprit: un rând restricționat = 404, unul liber = liber; lista fără rândul restricționat; SQL cu condiția',
    AccesMembri::decizie($db, $restr) === AccesMembri::NEGASIT && AccesMembri::decizie($db, $liber) === AccesMembri::LIBER
    && AccesMembri::filtreazaLista($db, [$liber, $restr]) === [$liber]
    && AccesMembri::conditieSql($db, 'p') === ' AND p.requires_active_account = 0');
Plugins::folosesteStari($stariCu(Plugins::PORNIT));
AccesMembri::reseteaza();
$_SESSION = [];
$lista = AccesMembri::filtreazaLista($db, [$liber, $restr]);
$verifica('membri pornit, vizitator: nota; lista păstrează rândul, fără text și cu is_locked; SQL fără condiție (rândul se vede ca teaser)',
    AccesMembri::decizie($db, $restr) === AccesMembri::NOTA && count($lista) === 2 && $lista[1]['content'] === ''
    && $lista[1]['content_text'] === '' && ($lista[1]['is_locked'] ?? false) === true && $lista[0] === $liber
    && AccesMembri::conditieSql($db, 'p') === '');
$verifica('harta site-ului și evenimentul următor: condiția mereu; un alias greșit nu intră în SQL',
    AccesMembri::conditieSql($db, 'p', 'blog_posts', true) === ' AND p.requires_active_account = 0' && AccesMembri::conditieSql($db, 'p;x') === '');
$idMembru = (int) $unu("SELECT id FROM users ORDER BY id LIMIT 1");
Hooks::adauga('continut.membru_activ', 'membri', static fn (PDO $d, array $u): bool => (int) $u['id'] === $idMembru);
$_SESSION = ['customer_user_id' => $idMembru];
AccesMembri::reseteaza();
$verifica('membri pornit, membru activ (agățarea continut.membru_activ): liber, lista neatinsă',
    AccesMembri::decizie($db, $restr) === AccesMembri::LIBER && AccesMembri::filtreazaLista($db, [$liber, $restr]) === [$liber, $restr]);
$_SESSION = [];
Hooks::reseteaza();
AccesMembri::reseteaza();
Plugins::reseteaza();
Plugins::incarca();
$verifica('coloanaSql: coloana pe baza migrată (nucleu/0034), alias-ul dat', AccesMembri::coloanaSql($db, 'p') === ', p.requires_active_account'
    && AccesMembri::areColoana($db, 'pages') && AccesMembri::areColoana($db, 'blog_posts') && !AccesMembri::areColoana($db, 'users'));
$coloane = $db->query("SELECT TABLE_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
    AND COLUMN_NAME = 'requires_active_account' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_NUM);
$verifica('migrarea nucleu/0034: pages și blog_posts au requires_active_account TINYINT(1) NOT NULL DEFAULT 0, 0 peste tot',
    json_encode($coloane) === json_encode([['blog_posts', 'tinyint(1)', 'NO', '0'], ['pages', 'tinyint(1)', 'NO', '0']])
    && (int) $unu('SELECT COUNT(*) FROM pages WHERE requires_active_account <> 0') === 0
    && (int) $unu('SELECT COUNT(*) FROM blog_posts WHERE requires_active_account <> 0') === 0, json_encode($coloane));

// =====================================================================
// WP-24 C. Paginile și articolele doar pentru membri, prin server
// =====================================================================
$scrie('');
$scrie('== WP-24 C. Poarta pe site: pagini, articole, API, liste, tokenuri, sitemap, linkuri');
$catEvenimente = (int) $unu("SELECT id FROM blog_categories WHERE slug = 'evenimente' LIMIT 1");
$db->prepare("INSERT INTO pages (title, slug, html_content, css_content, js_content, is_published, requires_active_account)
    VALUES ('Pagina membrilor test', 'pagina-membri-test', '<h1>Pentru membri</h1><p>Text secret al membrilor.</p>', '', '', 1, 1)")->execute();
$idPaginaMembri = (int) $db->lastInsertId();
$tokenuri = '<div id="t-latest">{{latest_posts:6}}</div><div id="t-slider">{{category_posts:evenimente}}</div>'
    . '<div id="t-rows">{{category_event_rows:evenimente}}</div><div id="t-grid">{{category_grid_paged:evenimente,noutati}}</div>'
    . '<div id="t-next">{{next_event_title}}</div><p><a href="/pagina-membri-test">link spre membri</a></p>';
$db->prepare("INSERT INTO pages (title, slug, html_content, css_content, js_content, is_published) VALUES ('Tokenuri membri test', 'tokenuri-membri-test', :h, '', '', 1)")
    ->execute(['h' => $tokenuri]);
$idPaginaTokenuri = (int) $db->lastInsertId();
$db->prepare("INSERT INTO blog_posts (title, slug, excerpt, content, reading_minutes, published_at, is_published, category, category_id,
    event_start_date, event_location, requires_active_account)
    VALUES ('Atelier secret membri', 'atelier-secret-membri', 'Rezumat public al atelierului.', '<p>Conținut secret al atelierului.</p>', 3,
    NOW() - INTERVAL 1 HOUR, 1, 'Evenimente', :c, CURDATE() + INTERVAL 1 DAY, 'Sala membrilor', 1)")->execute(['c' => $catEvenimente ?: null]);
$idArticolMembri = (int) $db->lastInsertId();
if ($catEvenimente > 0) {
    $db->prepare('INSERT IGNORE INTO blog_post_categories (post_id, category_id) VALUES (:p, :c)')->execute(['p' => $idArticolMembri, 'c' => $catEvenimente]);
}
ResponseCache::purgePageCache();
// Membri pornit (licența „toate”), vizitator, fără agățări (serverul de dezvoltare): nota nucleului.
$pagina = $cerere('GET', '/pagina-membri-test');
$verifica('membri pornit, vizitator: pagina restricționată = 403, noindex, nofollow, nota nucleului, fără textul ei',
    $pagina['cod'] === 403 && str_contains($pagina['corp'], '<meta name="robots" content="noindex, nofollow">')
    && str_contains($pagina['corp'], 'Conținut pentru membri') && str_contains($pagina['corp'], 'Pagina membrilor test')
    && !str_contains($pagina['corp'], 'Text secret al membrilor'), $pagina['cod'] . ' ' . mb_substr(strip_tags($pagina['corp']), 0, 300));
$articol = $cerere('GET', '/blog/atelier-secret-membri');
$verifica('membri pornit, vizitator: articolul restricționat = 403 cu nota, fără conținut', $articol['cod'] === 403
    && !str_contains($articol['corp'], 'Conținut secret al atelierului'));
$api = $cerere('GET', '/api/blog/posts/atelier-secret-membri');
$verifica('API-ul articolului: 403, requires_active_account: true, fără conținut', $api['cod'] === 403
    && (json_decode($api['corp'], true)['requires_active_account'] ?? null) === true && !str_contains($api['corp'], 'secret al atelierului'));
$apiLista = json_decode($cerere('GET', '/api/blog/posts')['corp'], true);
$dinLista = array_values(array_filter((array) ($apiLista['posts'] ?? []), static fn ($p): bool => ($p['slug'] ?? '') === 'atelier-secret-membri'));
$verifica('API-ul listei: articolul apare blocat (is_locked, content și content_text goale), fără coloana requires_active_account',
    count($dinLista) === 1 && ($dinLista[0]['is_locked'] ?? false) === true && ($dinLista[0]['content'] ?? 'x') === ''
    && ($dinLista[0]['content_text'] ?? 'x') === '' && !array_key_exists('requires_active_account', $dinLista[0])
    && !array_key_exists('requires_active_account', (array) (($apiLista['posts'] ?? [])[0] ?? [])), json_encode($dinLista));
$tok = $cerere('GET', '/tokenuri-membri-test')['corp'];
$sectiune = static fn (string $html, string $id): string => (string) (preg_match('#<div id="' . $id . '">(.*?)</div>\s*<div id="#s', $html . '<div id="', $m) === 1 ? $m[1] : '');
$verifica('{{latest_posts}}, {{category_posts}}, {{category_event_rows}}, {{category_grid_paged}}: articolul ca teaser (titlul), fără textul secret',
    str_contains($sectiune($tok, 't-latest'), 'Atelier secret membri') && str_contains($sectiune($tok, 't-slider'), 'Atelier secret membri')
    && str_contains($tok, 'class="vter__row') && str_contains($tok, 'data-vter-page-size="6"') && !str_contains($tok, 'secret al atelierului'),
    mb_substr(strip_tags($tok), 0, 400));
$verifica('{{next_event_title}}: un eveniment doar pentru membri nu apare niciodată (A11)', !str_contains($sectiune($tok, 't-next'), 'Atelier secret membri'));
$verifica('sliderul: cardul de eveniment (data în colț, locația, „Vezi detalii”) pentru postarea cu event_start_date',
    str_contains($tok, 'vtcs__card vtcs__card--event') && str_contains($tok, 'vtcs__date-day') && str_contains($tok, 'Sala membrilor')
    && str_contains($tok, 'Vezi detalii →'));
$sitemap = $cerere('GET', '/sitemap.xml')['corp'];
$verifica('sitemap.xml: fără pagina și articolul restricționate (mereu)', !str_contains($sitemap, 'pagina-membri-test')
    && !str_contains($sitemap, 'atelier-secret-membri') && str_contains($sitemap, 'tokenuri-membri-test'));
$verifica('linkul spre pagina restricționată rămâne cât timp membri e pe site', str_contains($tok, 'href="/pagina-membri-test"'));
// Revizuirea WP-24: căutarea din /blog nu se uită în textul unui articol restricționat (la cine nu e membru activ).
$cautaText = $cerere('GET', '/blog?q=' . rawurlencode('Conținut secret'))['corp'];
$cautaTitlu = $cerere('GET', '/blog?q=' . rawurlencode('Atelier secret'))['corp'];
$verifica('căutarea în blog: articolul doar pentru membri se găsește după titlu, nu după textul lui (care nu se arată)',
    !str_contains($cautaText, '/blog/atelier-secret-membri') && str_contains($cautaTitlu, '/blog/atelier-secret-membri'));
// Membri oprit: 404 și dispare din liste și din linkuri.
$licenta('toate', 'membri=oprit');
AccesMembri::reseteaza();
$pagina = $cerere('GET', '/pagina-membri-test');
$articol = $cerere('GET', '/blog/atelier-secret-membri');
$api = $cerere('GET', '/api/blog/posts/atelier-secret-membri');
$verifica('membri oprit: pagina 404, articolul 404, API-ul 404 (JSON), fără nimic din conținut', $pagina['cod'] === 404 && $articol['cod'] === 404
    && $api['cod'] === 404 && !str_contains($pagina['corp'] . $articol['corp'] . $api['corp'], 'secret'),
    $pagina['cod'] . ' ' . $articol['cod'] . ' ' . $api['cod']);
$tok = $cerere('GET', '/tokenuri-membri-test')['corp'];
$apiLista = $cerere('GET', '/api/blog/posts')['corp'];
$blog = $cerere('GET', '/blog')['corp'];
$verifica('membri oprit: articolul lipsește din {{latest_posts}}, din tokenurile categoriilor, din /blog și din API-ul listei',
    !str_contains($tok, 'Atelier secret membri') && !str_contains($apiLista, 'atelier-secret-membri') && !str_contains($blog, 'atelier-secret-membri'));
$verifica('membri oprit: linkul spre pagina restricționată se scoate din textul paginii (ca linkurile oricărui plugin oprit)',
    !str_contains($tok, 'href="/pagina-membri-test"') && str_contains($tok, '<div id="t-latest">'));
$licenta('toate');
AccesMembri::reseteaza();
// Salvarea din editor păstrează fanionul fără câmpul pluginului.
$salveazaPagina = static function (array $extra) use ($cerere, $jarAdmin, $idPaginaMembri): array {
    return $cerere('POST', '/admin/pages/save', $jarAdmin, $extra + ['id' => (string) $idPaginaMembri, 'title' => 'Pagina membrilor test',
        'slug' => 'pagina-membri-test', 'html_content' => '<h1>Pentru membri</h1><p>Text secret al membrilor.</p>', 'css_content' => '',
        'js_content' => '', 'is_published' => '1']);
};
$fanion = static fn (): int => (int) $unu('SELECT requires_active_account FROM pages WHERE id = :id', ['id' => $idPaginaMembri]);
$salveazaPagina([]);
$dupaFara = $fanion();
$salveazaPagina(['requires_active_account_present' => '1']);
$dupaDebifat = $fanion();
$salveazaPagina(['requires_active_account_present' => '1', 'requires_active_account' => '1']);
$verifica('pagina salvată fără câmpul pluginului păstrează fanionul (1); cu requires_active_account_present=1 și fără bifă → 0; cu bifă → 1',
    $dupaFara === 1 && $dupaDebifat === 0 && $fanion() === 1, json_encode([$dupaFara, $dupaDebifat, $fanion()]));
$editor = $cerere('GET', '/admin/pages/' . $idPaginaMembri . '/edit', $jarAdmin)['corp'];
$verifica('editorul paginii: fără pluginul membri portat, niciun câmp „Doar cont activ” (îl adaugă agățarea)', !str_contains($editor, 'requires_active_account'));
$salveazaArticol = static function (array $extra) use ($cerere, $jarAdmin, $idArticolMembri): array {
    return $cerere('POST', '/admin/blog/posts', $jarAdmin, $extra + ['action' => 'save', 'id' => (string) $idArticolMembri,
        'title' => 'Atelier secret membri', 'slug' => 'atelier-secret-membri', 'content' => '<p>Conținut secret al atelierului.</p>',
        'excerpt' => 'Rezumat public al atelierului.', 'reading_minutes' => '3', 'is_published' => '1',
        'published_at' => date('Y-m-d\TH:i', time() - 3600), 'timezone_offset_minutes' => '0']);
};
$fanionArticol = static fn (): int => (int) $unu('SELECT requires_active_account FROM blog_posts WHERE id = :id', ['id' => $idArticolMembri]);
$salveazaArticol([]);
$articolFara = $fanionArticol();
$salveazaArticol(['requires_active_account_present' => '1']);
$verifica('articolul salvat fără câmpul pluginului păstrează fanionul; cu requires_active_account_present=1 fără bifă → 0',
    $articolFara === 1 && $fanionArticol() === 0, json_encode([$articolFara, $fanionArticol()]));
$db->prepare('UPDATE blog_posts SET requires_active_account = 1 WHERE id = :id')->execute(['id' => $idArticolMembri]);

// =====================================================================
// WP-24 D. Cu agățări de probă (al doilea server, CMS_DEV_HOOKS)
// =====================================================================
$scrie('');
$scrie('== WP-24 D. Agățările: membrul activ, nota pluginului, ștergerea contului, înregistrarea, editorul, layout.final_body');
$parolaMembru = 'Parola-membru-test-1';
$db->prepare("INSERT INTO users (first_name, last_name, email, password_hash) VALUES ('Membru', 'Activ', 'membru.activ@exemplu.ro', :h)")
    ->execute(['h' => password_hash($parolaMembru, PASSWORD_BCRYPT)]);
$idMembruActiv = (int) $db->lastInsertId();
$jarMembru = $dosarLucru . '/membru-jar.txt';
$cerere('GET', $baza2 . '/login', $jarMembru);
$cerere('POST', $baza2 . '/login', $jarMembru, ['email' => 'membru.activ@exemplu.ro', 'password' => $parolaMembru]);
$pagina = $cerere('GET', $baza2 . '/pagina-membri-test', $jarMembru);
$verifica('membrul activ (continut.membru_activ) vede pagina: 200, cu textul ei', $pagina['cod'] === 200
    && str_contains($pagina['corp'], 'Text secret al membrilor'), (string) $pagina['cod']);
$articol = $cerere('GET', $baza2 . '/blog/atelier-secret-membri', $jarMembru);
$api = json_decode($cerere('GET', $baza2 . '/api/blog/posts', $jarMembru)['corp'], true);
$dinLista = array_values(array_filter((array) ($api['posts'] ?? []), static fn ($p): bool => ($p['slug'] ?? '') === 'atelier-secret-membri'));
$verifica('membrul activ: articolul 200 cu conținutul; în listă fără is_locked, cu text', $articol['cod'] === 200
    && str_contains($articol['corp'], 'Conținut secret al atelierului') && count($dinLista) === 1 && !isset($dinLista[0]['is_locked'])
    && ($dinLista[0]['content'] ?? '') !== '');
$nota = $cerere('GET', $baza2 . '/pagina-membri-test');
$verifica('vizitatorul: nota pluginului (continut.nota) în locul celei a nucleului, tot 403', $nota['cod'] === 403
    && str_contains($nota['corp'], 'nota-plugin-test') && str_contains($nota['corp'], 'Nota pluginului: Pagina membrilor test')
    && !str_contains($nota['corp'], 'Text secret'));
$verifica('layout.final_body: HTML-ul agățării chiar înainte de </body> (al doilea server), nimic pe serverul fără agățări',
    str_contains($nota['corp'], '<!--final-body-test--></body>') && !str_contains($cerere('GET', '/contact')['corp'], 'final-body-test'));
$editor = $cerere('GET', $baza2 . '/admin/pages/' . $idPaginaMembri . '/edit', $jarAdmin2)['corp'];
$editorArticol = $cerere('GET', $baza2 . '/admin/blog/posts/editor?post=' . $idArticolMembri, $jarAdmin2)['corp'];
$verifica('editor.pagina.campuri / editor.articol.campuri: câmpul agățării, bifat după rândul salvat',
    str_contains($editor, 'name="requires_active_account_present"') && str_contains($editor, 'value="1" checked> Doar cont activ')
    && str_contains($editorArticol, 'name="requires_active_account_present"') && str_contains($editorArticol, 'checked> Doar cont activ'));
$jurnalHooks = $dosarLucru . '/hooks-jurnal.jsonl';
$idSters = (int) $unu("SELECT id FROM users WHERE email <> 'membru.activ@exemplu.ro' ORDER BY id DESC LIMIT 1");
$cerere('POST', $baza2 . '/admin/users/delete-selected', $jarAdmin2, ['user_ids' => [(string) $idSters]]);
$cerere('POST', $baza2 . '/contul-meu/sterge', $jarMembru, ['confirm_delete' => '1']);
$intrari = array_map(static fn (string $l): array => (array) json_decode($l, true), file($jurnalHooks, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
$sterse = array_values(array_filter($intrari, static fn (array $i): bool => isset($i['din'])));
$verifica('utilizator.sters: din admin ([id], „admin”) și din cont ([id], „cont”), înaintea ștergerii; conturile șterse',
    count($sterse) === 2 && $sterse[0] === ['ids' => [$idSters], 'din' => 'admin'] && $sterse[1] === ['ids' => [$idMembruActiv], 'din' => 'cont']
    && (int) $unu('SELECT COUNT(*) FROM users WHERE id IN (' . $idSters . ',' . $idMembruActiv . ')') === 0, json_encode($intrari));
$inregistrare = $cerere('GET', $baza2 . '/register', $jarClient);
$verifica('inregistrare.formular: câmpul pluginului în formularul de înregistrare (al doilea server); nimic pe primul',
    str_contains($inregistrare['corp'], 'camp-inregistrare-test') && !str_contains($cerere('GET', '/register')['corp'], 'camp-inregistrare-test'));
$inregistreaza = static function (array $extra) use ($cerere, $baza2, $jarClient): array {
    $formular = $cerere('GET', $baza2 . '/register', $jarClient)['corp'];
    preg_match('/name="register_form_token" value="([^"]+)"/', $formular, $t);
    preg_match('/name="register_form_rendered_at" value="(\d+)"/', $formular, $ra);
    sleep(3);
    return $cerere('POST', $baza2 . '/register', $jarClient, $extra + ['register_form_token' => $t[1] ?? '', 'register_form_rendered_at' => $ra[1] ?? '0',
        'register_hp_website' => '', 'first_name' => 'Ana', 'last_name' => 'Test', 'phone' => '0700000000',
        'password' => 'parola-test-123', 'password_confirm' => 'parola-test-123']);
};
$db->exec("DELETE FROM register_submit_logs");
$r = $inregistreaza(['email' => 'inregistrare.rea@exemplu.ro', 'profesie_test' => 'rea']);
$mesaj = $cerere('GET', $baza2 . '/register', $jarClient)['corp'];
$verifica('inregistrare.valideaza: eroarea pluginului oprește înregistrarea (mesajul ei, niciun cont)', $r['cod'] === 302
    && str_contains($mesaj, 'Profesia aleasă nu e validă (test).')
    && (int) $unu("SELECT COUNT(*) FROM users WHERE email = 'inregistrare.rea@exemplu.ro'") === 0, $r['cod'] . ' ' . ($r['antete']['location'] ?? ''));
$r = $inregistreaza(['email' => 'inregistrare.buna@exemplu.ro', 'profesie_test' => 'confirmare']);
$idNouCont = (int) $unu("SELECT id FROM users WHERE email = 'inregistrare.buna@exemplu.ro'");
$intrari = array_map(static fn (string $l): array => (array) json_decode($l, true), file($jurnalHooks, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
$creat = array_values(array_filter($intrari, static fn (array $i): bool => isset($i['creat'])));
$verifica('inregistrare.dupa_creare: primește id-ul și datele validate; redirecționarea lui, fără logare automată',
    $idNouCont > 0 && $r['cod'] === 302 && ($r['antete']['location'] ?? '') === '/contact?confirma=1'
    && ($creat[0] ?? []) === ['creat' => $idNouCont, 'date' => ['profesie' => 'confirmare']]
    && !str_contains($cerere('GET', $baza2 . '/contul-meu', $jarClient)['corp'], 'inregistrare.buna@exemplu.ro'), json_encode([$r['cod'], $r['antete']['location'] ?? '', $creat]));
$r = $inregistreaza(['email' => 'inregistrare.buna@exemplu.ro', 'profesie_test' => 'reluare']);
$verifica('inregistrare.cont_existent: o adresă care are deja cont → redirecționarea pluginului', $r['cod'] === 302
    && ($r['antete']['location'] ?? '') === '/contact?reluare=1', json_encode([$r['cod'], $r['antete']['location'] ?? '']));
$db->exec("DELETE FROM register_submit_logs");

// =====================================================================
// WP-25 A. Dispecerul Stripe, fără server
// =====================================================================
$scrie('');
$scrie('== WP-25 A. StripeEvenimente: proprietarul, evenimentele cerute, verificarea webhook-ului');
$ev = static fn (array $obiect, string $tip = 'checkout.session.completed'): array => ['id' => 'evt_x', 'type' => $tip, 'data' => ['object' => $obiect]];
$verifica('proprietarul: activarea unui cont → membri; o donație → donatii; un link de plată → link_plata; o comandă → magazin; altceva → null',
    StripeEvenimente::proprietar($db, $ev(['metadata' => ['type' => 'account_activation', 'order_number' => 'X']])) === 'membri'
    && StripeEvenimente::proprietar($db, $ev(['metadata' => ['type' => 'donation']])) === 'donatii'
    && StripeEvenimente::proprietar($db, $ev(['metadata' => ['payment_link_token' => 'abc', 'order_number' => 'X']])) === 'link_plata'
    && StripeEvenimente::proprietar($db, $ev(['metadata' => ['order_number' => 'CMD-1']])) === 'magazin'
    && StripeEvenimente::proprietar($db, $ev(['object' => 'payment_intent', 'metadata' => ['order_id' => '5']], 'payment_intent.succeeded')) === 'magazin'
    && StripeEvenimente::proprietar($db, $ev(['object' => 'checkout.session', 'client_reference_id' => 'CMD-2'])) === 'magazin'
    && StripeEvenimente::proprietar($db, $ev(['object' => 'invoice', 'subscription' => 'sub_x'], 'invoice.paid')) === null
    && StripeEvenimente::proprietar($db, $ev(['object' => 'customer'], 'customer.created')) === null);
$cerute = StripeEvenimente::ceruteDePluginuri();
$verifica('evenimentele cerute de pluginurile vizibile (licența „toate”): ale magazinului, donațiilor și membrilor, fără dubluri',
    in_array('checkout.session.completed', $cerute, true) && in_array('invoice.paid', $cerute, true) && in_array('charge.refunded', $cerute, true)
    && count($cerute) === count(array_unique($cerute)), implode(', ', $cerute));
putenv('STRIPE_API_BASE=http://127.0.0.1:' . $portStripe);
$_ENV['STRIPE_API_BASE'] = 'http://127.0.0.1:' . $portStripe;
StripeGateway::folosesteSetarile([]);
$raspunsuriStripe(['GET /v1/webhook_endpoints' => ['cod' => 200, 'corp' => ['data' => [
    ['url' => 'https://alt.exemplu.ro/webhook/stripe', 'status' => 'enabled', 'enabled_events' => ['*']],
    ['url' => 'http://site.exemplu.ro/webhook/stripe', 'status' => 'enabled', 'api_version' => '2026-08-26.proba',
        'enabled_events' => ['checkout.session.completed', 'payment_intent.succeeded']],
]]]]);
$rez = StripeEvenimente::verificaWebhook('sk_test_inventat', 'http://site.exemplu.ro');
$verifica('verificaWebhook: endpoint-ul cu adresa site-ului, pornit, versiunea lui, evenimentele cerute care îi lipsesc',
    $rez['gasit'] && $rez['activ'] && $rez['versiune'] === '2026-08-26.proba' && in_array('checkout.session.expired', $rez['lipsa'], true)
    && !in_array('checkout.session.completed', $rez['lipsa'], true) && $rez['eroare'] === '', json_encode($rez));
$rez = StripeEvenimente::verificaWebhook('sk_test_inventat', 'http://altul.exemplu.ro');
$verifica('verificaWebhook: fără endpoint pentru adresa site-ului → negăsit; fără cheie → eroarea ei', !$rez['gasit']
    && StripeEvenimente::verificaWebhook('', 'http://x')['eroare'] === 'Lipsește cheia secretă Stripe.');
$cereriStripe();
putenv('STRIPE_API_BASE');
unset($_ENV['STRIPE_API_BASE']);
// Revizuirea WP-25: două livrări ale aceluiași eveniment în aceeași clipă (stripe_events.in_lucru_la, plata_stripe/0039).
StripeEvenimente::reseteaza();
$apeluri = 0;
StripeEvenimente::handler('magazin', 'checkout.session.completed', static function () use (&$apeluri): string {
    $apeluri++;
    return 'procesat_test';
});
$evConcurent = ['id' => 'evt_wp25_concurent', 'type' => 'checkout.session.completed', 'data' => ['object' => ['metadata' => ['order_number' => 'CMD-X']]]];
$db->exec("INSERT INTO stripe_events (event_id, type, primit_la, in_lucru_la) VALUES ('evt_wp25_concurent', 'checkout.session.completed', NOW(), NOW())");
$inLucru = StripeEvenimente::proceseaza($db, $evConcurent);
$db->exec("UPDATE stripe_events SET in_lucru_la = NOW() - INTERVAL 6 MINUTE WHERE event_id = 'evt_wp25_concurent'");
$reluat = StripeEvenimente::proceseaza($db, $evConcurent);
$dinNou = StripeEvenimente::proceseaza($db, $evConcurent);
StripeEvenimente::handler('magazin', 'checkout.session.expired', static function (): string {
    throw new RuntimeException('handler căzut (test)');
});
$evCazut = ['id' => 'evt_wp25_cazut', 'type' => 'checkout.session.expired', 'data' => ['object' => ['metadata' => ['order_number' => 'CMD-X']]]];
try {
    StripeEvenimente::proceseaza($db, $evCazut);
    $aruncat = false;
} catch (RuntimeException) {
    $aruncat = true;
}
$verifica('evenimentul luat în lucru de altă cerere: 409 fără procesare; unul luat de peste 5 minute se reia (o dată); apoi „duplicate”; '
    . 'un handler căzut eliberează evenimentul (in_lucru_la gol) pentru retrimiterea lui Stripe',
    $inLucru['cod'] === 409 && $reluat['cod'] === 200 && $dinNou['text'] === 'duplicate' && $apeluri === 1 && $aruncat
    && $unu("SELECT in_lucru_la FROM stripe_events WHERE event_id = 'evt_wp25_cazut'") === ''
    && $unu("SELECT procesat_la FROM stripe_events WHERE event_id = 'evt_wp25_cazut'") === '', json_encode([$inLucru, $reluat, $dinNou, $apeluri]));
$db->exec("DELETE FROM stripe_events WHERE event_id IN ('evt_wp25_concurent', 'evt_wp25_cazut')");
StripeEvenimente::reseteaza();

// =====================================================================
// WP-25 B. Webhook-ul prin server
// =====================================================================
$scrie('');
$scrie('== WP-25 B. /webhook/stripe: semnătura, notarea, duplicatele, proprietarul oprit');
$secret = 'whsec_test_wp25';
$setare('stripe_webhook_secret', $secret);
$trimiteEveniment = static function (array $eveniment, ?string $secretSemnare = null, string $bazaServer = '') use ($cerere, $secret): array {
    $corp = json_encode($eveniment, JSON_UNESCAPED_SLASHES);
    $t = time();
    return $cerere('POST', $bazaServer . '/webhook/stripe', null, $corp, ['Content-Type: application/json',
        'Stripe-Signature: t=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $corp, $secretSemnare ?? $secret)]);
};
$comanda = $db->query("SELECT id, order_number FROM orders WHERE deleted_at IS NULL AND order_number <> '' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$idComanda = (int) $comanda['id'];
$db->prepare("UPDATE orders SET payment_status = 'pending', status = 'pending_payment', paid_at = NULL WHERE id = :id")->execute(['id' => $idComanda]);
$platit = ['id' => 'evt_wp25_platit', 'type' => 'checkout.session.completed', 'api_version' => '2026-08-26.proba', 'data' => ['object' => [
    'id' => 'cs_wp25', 'object' => 'checkout.session', 'payment_status' => 'paid', 'payment_intent' => 'pi_wp25',
    'metadata' => ['order_number' => (string) $comanda['order_number']]]]];
$r = $trimiteEveniment($platit, 'whsec_gresit');
$verifica('semnătură greșită: 400 invalid_signature, nimic notat', $r['cod'] === 400 && trim($r['corp']) === 'invalid_signature'
    && (int) $unu("SELECT COUNT(*) FROM stripe_events WHERE event_id LIKE 'evt_wp25%'") === 0);
$emailuriComanda = static fn (): int => (int) $unu("SELECT COUNT(*) FROM email_send_history WHERE order_id = :id", ['id' => $idComanda]);
$inainteEmail = $emailuriComanda();
$r = $trimiteEveniment($platit);
$rand = $db->query("SELECT * FROM stripe_events WHERE event_id = 'evt_wp25_platit'")->fetch(PDO::FETCH_ASSOC) ?: [];
$verifica('sesiunea plătită: 200 ok, comanda plătită, evenimentul notat (proprietarul magazin, versiunea, procesat)', $r['cod'] === 200
    && trim($r['corp']) === 'ok' && $unu('SELECT payment_status FROM orders WHERE id = :id', ['id' => $idComanda]) === 'paid'
    && ($rand['proprietar'] ?? '') === 'magazin' && ($rand['api_version'] ?? '') === '2026-08-26.proba' && ($rand['procesat_la'] ?? null) !== null
    && ($rand['object_id'] ?? '') === 'cs_wp25', json_encode($rand));
$dupaPrimul = $emailuriComanda();
$db->prepare("UPDATE orders SET payment_status = 'pending' WHERE id = :id")->execute(['id' => $idComanda]);
$r = $trimiteEveniment($platit);
$verifica('același eveniment retrimis: 200 „duplicate”, comanda neatinsă, niciun email nou', $r['cod'] === 200 && trim($r['corp']) === 'duplicate'
    && $unu('SELECT payment_status FROM orders WHERE id = :id', ['id' => $idComanda]) === 'pending' && $emailuriComanda() === $dupaPrimul,
    $r['corp'] . ' ' . $dupaPrimul . '/' . $emailuriComanda());
$r = $trimiteEveniment(['id' => 'evt_wp25_client', 'type' => 'customer.created', 'data' => ['object' => ['id' => 'cus_1', 'object' => 'customer']]]);
$verifica('un tip pe care nu-l tratează nimeni: 200 ok, notat fără proprietar', $r['cod'] === 200 && trim($r['corp']) === 'ok'
    && $unu("SELECT rezultat FROM stripe_events WHERE event_id = 'evt_wp25_client'") === 'fara_proprietar');
$r = $trimiteEveniment(['type' => 'payment_intent.payment_failed', 'data' => ['object' => ['id' => 'pi_x', 'object' => 'payment_intent',
    'metadata' => ['order_id' => (string) $idComanda]]]]);
$verifica('un eveniment fără id se procesează (plata eșuată), fără rând în stripe_events', $r['cod'] === 200
    && $unu('SELECT payment_status FROM orders WHERE id = :id', ['id' => $idComanda]) === 'failed'
    && (int) $unu("SELECT COUNT(*) FROM stripe_events WHERE event_id LIKE 'evt_wp25%'") === 2);
$jurnalPluginuri = Paths::stocare('logs') . '/pluginuri.log';
$jurnalInainte = (string) @file_get_contents($jurnalPluginuri);
// Rândul „o dată pe zi” se notează în pluginuri-azi.json: pus deoparte și golit, ca rândul să se scrie și la o a doua rulare.
$fisierAzi = Paths::stocare('logs') . '/pluginuri-azi.json';
$aziInainte = @file_get_contents($fisierAzi);
@unlink($fisierAzi);
$licenta('toate', 'magazin=oprit');
$db->prepare("UPDATE orders SET payment_status = 'pending', status = 'pending_payment' WHERE id = :id")->execute(['id' => $idComanda]);
$r = $trimiteEveniment(['id' => 'evt_wp25_oprit', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_o', 'object' => 'checkout.session',
    'payment_status' => 'paid', 'metadata' => ['order_number' => (string) $comanda['order_number']]]]]);
$jurnalDupa = (string) @file_get_contents($jurnalPluginuri);
$verifica('magazin oprit: webhook-ul răspunde, banii se înregistrează (comanda plătită) și un rând pe zi în pluginuri.log',
    $r['cod'] === 200 && $unu('SELECT payment_status FROM orders WHERE id = :id', ['id' => $idComanda]) === 'paid'
    && str_contains(substr($jurnalDupa, strlen($jurnalInainte)), 'checkout.session.completed'), $r['cod'] . ' ' . mb_substr(substr($jurnalDupa, strlen($jurnalInainte)), 0, 300));
$licenta('toate');
if (is_string($aziInainte)) {
    file_put_contents($fisierAzi, $aziInainte);
} else {
    @unlink($fisierAzi);
}
if ($jurnalInainte === '') {
    @unlink($jurnalPluginuri);
} else {
    file_put_contents($jurnalPluginuri, $jurnalInainte);
}
$setare('stripe_webhook_secret', null);

// =====================================================================
// WP-25 C. Setări plăți: „Verifică webhook-ul” (al doilea server, Stripe-ul fals)
// =====================================================================
$scrie('');
$scrie('== WP-25 C. Setări plăți: evenimentele cerute, ultimul primit, „Verifică webhook-ul”');
$setare('stripe_secret_key', 'sk_test_inventat');
$raspunsuriStripe(['GET /v1/webhook_endpoints' => ['cod' => 200, 'corp' => ['data' => [['url' => $baza2 . '/webhook/stripe', 'status' => 'enabled',
    'api_version' => '2026-08-26.proba', 'enabled_events' => ['checkout.session.completed']]]]]]);
$plati = $cerere('GET', $baza2 . '/admin/settings/payments', $jarAdmin2)['corp'];
preg_match('/name="_csrf" value="([^"]+)"/', $plati, $mc);
$verifica('pagina arată evenimentele cerute și ultimul eveniment primit (din stripe_events)', str_contains($plati, 'id="stripe-webhook"')
    && str_contains($plati, 'checkout.session.async_payment_failed') && str_contains($plati, 'customer.created'));
$fara = $cerere('POST', $baza2 . '/admin/settings/payments/verifica-webhook', $jarAdmin2, []);
$verifica('fără jetonul CSRF: refuzat (nicio cerere spre Stripe)', $fara['cod'] === 302 && $cereriStripe() === []);
$r = $cerere('POST', $baza2 . '/admin/settings/payments/verifica-webhook', $jarAdmin2, ['_csrf' => $mc[1] ?? '']);
$plati = $cerere('GET', $baza2 . '/admin/settings/payments', $jarAdmin2)['corp'];
$cereriVerificare = $cereriStripe();
$verifica('„Verifică webhook-ul”: GET /v1/webhook_endpoints cu cheia, rezultatul (pornit, versiunea, ce lipsește) arătat o dată',
    $r['cod'] === 302 && count($cereriVerificare) === 1 && ($cereriVerificare[0]['cale'] ?? '') === '/v1/webhook_endpoints'
    && str_contains($plati, 'Webhook-ul există: pornit') && str_contains($plati, '2026-08-26.proba') && str_contains($plati, 'îi lipsesc')
    && !str_contains($cerere('GET', $baza2 . '/admin/settings/payments', $jarAdmin2)['corp'], 'Webhook-ul există'), mb_substr(strip_tags($plati), 0, 200));
$setare('stripe_secret_key', null);
$raspunsuriStripe([]);

// =====================================================================
// WP-25 D. Conturile: {{auth_form}}, regula comenzilor fără cont, confirmarea adresei, tab-urile
// =====================================================================
$scrie('');
$scrie('== WP-25 D. {{auth_form}}, comenzile fără cont, confirmarea adresei, tab-urile contului');
foreach (['login' => 'Intrare test', 'register' => 'Înregistrare test', 'pagina-auth-test' => 'Alta'] as $slugAuth => $titluAuth) {
    $db->prepare("INSERT INTO pages (title, slug, html_content, css_content, js_content, is_published) VALUES (:t, :s, :h, '', '', 1)")
        ->execute(['t' => $titluAuth, 's' => $slugAuth, 'h' => '<div class="pagina-auth">{{auth_form}}</div>']);
}
$login = $cerere('GET', '/login')['corp'];
$register = $cerere('GET', '/register')['corp'];
$alta = $cerere('GET', '/pagina-auth-test')['corp'];
$verifica('{{auth_form}}: pe paginile „login” și „register” formularul (fila potrivită, capcana ascunsă cu display:none, jetonul anti-robot)',
    str_contains($login, 'data-auth-form') && str_contains($login, 'class="active" href="/login"') && str_contains($register, 'class="active" href="/register"')
    && str_contains($register, 'name="register_form_token" value="') && str_contains($register, 'style="display:none;" aria-hidden="true"'));
$verifica('{{auth_form}} pe altă pagină: gol (fără formular, fără codul tokenului)', !str_contains($alta, 'data-auth-form') && !str_contains($alta, '{{auth_form}}'));
$db->exec("DELETE FROM pages WHERE slug IN ('login', 'register', 'pagina-auth-test')");
// Regula comenzilor fără cont (G-M4; cu reparațiile revizuirii WP-25).
$textDinMail = static function (string $eml): string {
    return quoted_printable_decode($eml) . base64_decode((string) (preg_match('/\r?\n\r?\n([A-Za-z0-9+\/=\r\n]{40,})/', $eml, $mb) === 1
        ? preg_replace('/\s+/', '', $mb[1]) : ''));
};
$cereLink = static function (string $jar) use ($cerere, $dosarMail, $mailuriNoi, $textDinMail): string {
    $dinainte = glob($dosarMail . '/*.eml') ?: [];
    $cerere('POST', '/contul-meu/confirmare-email', $jar, []);
    $mailuri = $mailuriNoi($dinainte);
    return preg_match('#/cont/confirmare/([a-f0-9]{64})#', $textDinMail((string) end($mailuri)), $mt) === 1 ? $mt[1] : '';
};
$coloaneComenzi = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
    AND COLUMN_NAME NOT IN ('id', 'order_number', 'user_id', 'billing_email', 'loyalty_points_pending_claim', 'loyalty_points_awarded',
    'loyalty_points_awarded_at') ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
$lista = implode(', ', array_map(static fn ($c): string => '`' . $c . '`', $coloaneComenzi));
$comandaFaraCont = static function (string $numar, string $email, int $puncte = 0) use ($db, $lista, $idComanda): int {
    $db->prepare("INSERT INTO orders (order_number, user_id, billing_email, loyalty_points_pending_claim, loyalty_points_awarded, {$lista})
        SELECT :n, NULL, :e, :p, 0, {$lista} FROM orders WHERE id = :id")->execute(['n' => $numar, 'e' => $email, 'p' => $puncte, 'id' => $idComanda]);
    return (int) $db->lastInsertId();
};
$contNou = static function (string $prenume, string $email, string $parola) use ($db): int {
    $db->prepare("INSERT INTO users (first_name, last_name, email, password_hash) VALUES (:f, 'Test', :e, :h)")
        ->execute(['f' => $prenume, 'e' => $email, 'h' => password_hash($parola, PASSWORD_BCRYPT)]);
    return (int) $db->lastInsertId();
};
$logare = static function (string $jar, string $email, string $parola) use ($cerere): array {
    @unlink($jar);
    $cerere('GET', '/login', $jar);
    return $cerere('POST', '/login', $jar, ['email' => $email, 'password' => $parola]);
};
$idFaraCont = $comandaFaraCont('CMD-FARA-CONT-WP25', 'ana@exemplu.ro', 40);
$db->prepare("UPDATE orders SET billing_first_name = 'Ana', billing_address_line1 = 'Strada Inventată 7' WHERE id = :id")->execute(['id' => $idFaraCont]);
$parolaAna = 'parola-ana-wp25';
$idAna = $contNou('Ana', 'ana@exemplu.ro', $parolaAna);
$jarAna = $dosarLucru . '/ana-jar.txt';
$r = $logare($jarAna, 'ana@exemplu.ro', $parolaAna);
$cont = $cerere('GET', '/contul-meu?section=orders', $jarAna)['corp'];
$verifica('cont nou cu adresa unei comenzi fără cont: comanda NU apare, nici adresa ei de facturare; rândul „Ai comenzi mai vechi…” cu butonul; '
    . 'la logare punctele ei (40, în așteptare) NU se revendică (revizuirea WP-25, blocantul 2)',
    !str_contains($cont, 'CMD-FARA-CONT-WP25') && !str_contains($cont, 'Strada Inventată 7')
    && str_contains($cont, ConfirmareEmail::TEXT_IMPLICIT_COMENZI) && str_contains($cont, 'action="/contul-meu/confirmare-email"')
    && $unu('SELECT user_id FROM orders WHERE id = :id', ['id' => $idFaraCont]) === ''
    && (int) $unu('SELECT loyalty_points_pending_claim FROM orders WHERE id = :id', ['id' => $idFaraCont]) === 40
    && (int) $unu('SELECT COUNT(*) FROM loyalty_points_transactions WHERE user_id = :id', ['id' => $idAna]) === 0);
$dinainteMail = glob($dosarMail . '/*.eml') ?: [];
$tokenAna = $cereLink($jarAna);
$mailuri = $mailuriNoi($dinainteMail);
$verifica('„Trimite linkul”: un email spre adresa contului cu linkul /cont/confirmare/<token>; în bază doar hash-ul tokenului și al adresei (scop „comenzi”)',
    count($mailuri) === 1 && $tokenAna !== '' && $antetMail((string) $mailuri[0], 'To') === 'ana@exemplu.ro'
    && $unu('SELECT token_hash FROM customer_email_confirmations WHERE user_id = :id', ['id' => $idAna]) === hash('sha256', $tokenAna)
    && $unu('SELECT email_hash FROM customer_email_confirmations WHERE user_id = :id', ['id' => $idAna]) === hash('sha256', 'ana@exemplu.ro')
    && $unu('SELECT scop FROM customer_email_confirmations WHERE user_id = :id', ['id' => $idAna]) === 'comenzi');
$db->prepare('UPDATE customer_email_confirmations SET created_at = NOW() - INTERVAL 8 DAY WHERE user_id = :id')->execute(['id' => $idAna]);
$r = $cerere('POST', '/cont/confirmare/' . $tokenAna, $jarAna);
$verifica('linkul expirat (8 zile): nu confirmă nimic, comanda rămâne fără cont', $r['cod'] === 302
    && $unu('SELECT email_confirmed_at FROM users WHERE id = :id', ['id' => $idAna]) === ''
    && $unu('SELECT user_id FROM orders WHERE id = :id', ['id' => $idFaraCont]) === '');
$db->prepare('UPDATE customer_email_confirmations SET created_at = NOW() WHERE user_id = :id')->execute(['id' => $idAna]);
$pagina = $cerere('GET', '/cont/confirmare/' . $tokenAna, $jarAna);
$jarStrain = $dosarLucru . '/strain-jar.txt';
$rStrain = $cerere('POST', '/cont/confirmare/' . $tokenAna, $jarStrain);
$verifica('deschiderea linkului (GET) arată doar butonul (POST), fără să confirme; apăsat fără logare în cont (alt browser): trimis la '
    . 'intrare, nimic confirmat, linkul rămâne bun (revizuirea WP-25, majorul 3)',
    $pagina['cod'] === 200 && str_contains($pagina['corp'], 'method="post" action="/cont/confirmare/' . $tokenAna . '"')
    && str_contains((string) ($pagina['antete']['cache-control'] ?? ''), 'no-store')
    && $rStrain['cod'] === 302 && str_starts_with((string) ($rStrain['antete']['location'] ?? ''), '/login?next=')
    && $unu('SELECT email_confirmed_at FROM users WHERE id = :id', ['id' => $idAna]) === ''
    && $unu('SELECT used_at FROM customer_email_confirmations WHERE user_id = :id', ['id' => $idAna]) === '', $pagina['cod'] . ' ' . $rStrain['cod']);
$r = $cerere('POST', '/cont/confirmare/' . $tokenAna, $jarAna);
$cont = $cerere('GET', '/contul-meu?section=orders', $jarAna)['corp'];
$verifica('linkul apăsat din cont: adresa confirmată, punctele (40) revendicate, comanda legată de cont, listată, rândul dispare; un rând în Jurnal activitate',
    $r['cod'] === 302 && $unu('SELECT email_confirmed_at FROM users WHERE id = :id', ['id' => $idAna]) !== ''
    && (int) $unu('SELECT user_id FROM orders WHERE id = :id', ['id' => $idFaraCont]) === $idAna && str_contains($cont, 'CMD-FARA-CONT-WP25')
    && (int) $unu('SELECT loyalty_points_pending_claim FROM orders WHERE id = :id', ['id' => $idFaraCont]) === 0
    && (int) $unu('SELECT COALESCE(SUM(points_delta), 0) FROM loyalty_points_transactions WHERE user_id = :id', ['id' => $idAna]) === 40
    && !str_contains($cont, ConfirmareEmail::TEXT_IMPLICIT_COMENZI)
    && (int) $unu("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'cont_comenzi_legate' AND context_json LIKE :c", ['c' => '%"user_id":' . $idAna . '%']) === 1);
$r = $cerere('POST', '/cont/confirmare/' . $tokenAna, $jarAna);
$verifica('același link a doua oară: refuzat (folosit)', $r['cod'] === 302 && str_contains($cerere('GET', '/contul-meu', $jarAna)['corp'], 'expirat sau a fost deja folosit'));
// Blocantul 1: adresa schimbată după confirmare.
$idVictima = $comandaFaraCont('CMD-VICTIMA-WP25', 'victima@exemplu.ro', 25);
$rutaProfil = (string) (preg_match('#action="(/contul-meu/[a-z/-]*profil[a-z/-]*)"#', $cerere('GET', '/contul-meu?section=profile', $jarAna)['corp'], $mp) === 1 ? $mp[1] : '');
$cerere('POST', $rutaProfil !== '' ? $rutaProfil : '/contul-meu/profil', $jarAna, ['first_name' => 'Ana', 'last_name' => 'Test', 'email' => 'victima@exemplu.ro']);
$cont = $cerere('GET', '/contul-meu?section=orders', $jarAna)['corp'];
$verifica('adresa schimbată după confirmare (din cont): confirmarea se șterge, comanda fără cont a noii adrese NU apare, punctele ei nu se '
    . 'revendică nici la o logare nouă (revizuirea WP-25, blocantul 1)',
    $rutaProfil !== '' && $unu('SELECT email FROM users WHERE id = :id', ['id' => $idAna]) === 'victima@exemplu.ro'
    && $unu('SELECT email_confirmed_at FROM users WHERE id = :id', ['id' => $idAna]) === '' && !str_contains($cont, 'CMD-VICTIMA-WP25')
    && ($logare($jarAna, 'victima@exemplu.ro', $parolaAna)['cod'] === 302)
    && $unu('SELECT user_id FROM orders WHERE id = :id', ['id' => $idVictima]) === ''
    && (int) $unu('SELECT loyalty_points_pending_claim FROM orders WHERE id = :id', ['id' => $idVictima]) === 25, 'ruta: ' . $rutaProfil);
// Varianta: linkul cerut pe adresa A, apoi adresa schimbată în B, apoi apăsat.
$parolaDan = 'parola-dan-wp25';
$idDan = $contNou('Dan', 'dan@exemplu.ro', $parolaDan);
$jarDan = $dosarLucru . '/dan-jar.txt';
$logare($jarDan, 'dan@exemplu.ro', $parolaDan);
$idVictima2 = $comandaFaraCont('CMD-VICTIMA2-WP25', 'victima2@exemplu.ro');
$tokenDan = $cereLink($jarDan);
$cerere('POST', $rutaProfil !== '' ? $rutaProfil : '/contul-meu/profil', $jarDan, ['first_name' => 'Dan', 'last_name' => 'Test', 'email' => 'victima2@exemplu.ro']);
$r = $cerere('POST', '/cont/confirmare/' . $tokenDan, $jarDan);
$verifica('linkul cerut pe adresa A, apăsat după schimbarea adresei în B: refuzat, comanda lui B rămâne fără cont',
    $tokenDan !== '' && $r['cod'] === 302 && $unu('SELECT user_id FROM orders WHERE id = :id', ['id' => $idVictima2]) === ''
    && $unu('SELECT email_confirmed_at FROM users WHERE id = :id', ['id' => $idDan]) === '');
// Adminul schimbă adresa: confirmarea se șterge.
$db->prepare('UPDATE users SET email_confirmed_at = NOW() WHERE id = :id')->execute(['id' => $idDan]);
$logareAdmin();
$cerere('POST', '/admin/users/save', $jarAdmin, ['id' => (string) $idDan, 'first_name' => 'Dan', 'last_name' => 'Test', 'email' => 'dan.nou@exemplu.ro']);
$verifica('adresa schimbată din admin (Utilizatori): confirmarea se șterge',
    $unu('SELECT email FROM users WHERE id = :id', ['id' => $idDan]) === 'dan.nou@exemplu.ro'
    && $unu('SELECT email_confirmed_at FROM users WHERE id = :id', ['id' => $idDan]) === '');
// Resetarea parolei dovedește adresa și închide celelalte sesiuni.
$idFaraContB = $comandaFaraCont('CMD-FARA-CONT-WP25B', 'bogdan@exemplu.ro');
$idBogdan = $contNou('Bogdan', 'bogdan@exemplu.ro', 'parola-veche-1');
$jarVechiBogdan = $dosarLucru . '/bogdan-vechi-jar.txt';
$logare($jarVechiBogdan, 'bogdan@exemplu.ro', 'parola-veche-1');
$logatInainte = str_contains($cerere('GET', '/contul-meu', $jarVechiBogdan)['corp'], 'bogdan@exemplu.ro');
$dinainteMail = glob($dosarMail . '/*.eml') ?: [];
$cerere('POST', '/contul-meu/resetare-parola', null, ['email' => 'bogdan@exemplu.ro']);
preg_match('#/contul-meu/resetare-parola/([A-Za-z0-9]+)#', $textDinMail((string) (($mailuriNoi($dinainteMail))[0] ?? '')), $mrt);
$cerere('POST', '/contul-meu/resetare-parola/' . ($mrt[1] ?? 'x'), null, ['password' => 'parola-noua-123', 'password_confirm' => 'parola-noua-123']);
$dupa = $cerere('GET', '/contul-meu', $jarVechiBogdan);
$verifica('un link de resetare a parolei folosit dovedește adresa (contul confirmat, comanda fără cont legată) și închide sesiunile de dinainte '
    . 'ale contului (revizuirea WP-25: users.session_version)',
    $logatInainte && $unu('SELECT email_confirmed_at FROM users WHERE id = :id', ['id' => $idBogdan]) !== ''
    && (int) $unu('SELECT user_id FROM orders WHERE id = :id', ['id' => $idFaraContB]) === $idBogdan
    && (int) $unu('SELECT session_version FROM users WHERE id = :id', ['id' => $idBogdan]) === 1
    && !str_contains((string) $dupa['corp'], 'bogdan@exemplu.ro') && in_array($dupa['cod'], [302, 200], true), 'token: ' . ($mrt[1] ?? '-'));
// Logarea cu Google (în proces: SiteController::resolveGoogleUser și ConfirmareEmail::marcheazaConfirmat, fără Google).
$site = new \App\Http\Controllers\SiteController();
$rezolva = new ReflectionMethod($site, 'resolveGoogleUser');
$idElena = $contNou('Elena', 'elena@exemplu.ro', 'parola-pusa-de-altcineva');
$comandaElena = $comandaFaraCont('CMD-ELENA-WP25', 'elena@exemplu.ro');
$parolaAnulata = false;
$argumente = [$db, ['sub' => 'google-test-elena', 'email' => 'elena@exemplu.ro', 'email_verified' => true, 'given_name' => 'Elena'], &$parolaAnulata];
$idGoogle = $rezolva->invokeArgs($site, $argumente);
$confirmareGoogle = ConfirmareEmail::marcheazaConfirmat($db, $idGoogle, 'google', 'elena@exemplu.ro');
$verifica('Google într-un cont existent, neconfirmat, cu parolă: parola veche anulată, sesiunile lui închise, un rând în Jurnal activitate; '
    . 'adresa verificată de Google confirmă contul și leagă comanda (revizuirea WP-25, majorul 3)',
    $idGoogle === $idElena && $parolaAnulata && !password_verify('parola-pusa-de-altcineva', $unu('SELECT password_hash FROM users WHERE id = :id', ['id' => $idElena]))
    && (int) $unu('SELECT session_version FROM users WHERE id = :id', ['id' => $idElena]) === 1
    && (int) $unu("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'cont_parola_anulata_google' AND context_json LIKE :c", ['c' => '%"user_id":' . $idElena . '%']) === 1
    && $confirmareGoogle['comenzi'] === 1 && (int) $unu('SELECT user_id FROM orders WHERE id = :id', ['id' => $comandaElena]) === $idElena);
$db->prepare("UPDATE users SET email = 'elena.alta@exemplu.ro', email_confirmed_at = NULL WHERE id = :id")->execute(['id' => $idElena]);
$comandaAlta = $comandaFaraCont('CMD-ELENA-ALTA-WP25', 'elena.alta@exemplu.ro');
$parolaAnulata = false;
$argumente = [$db, ['sub' => 'google-test-elena', 'email' => 'elena@exemplu.ro', 'email_verified' => true], &$parolaAnulata];
$idGoogle = $rezolva->invokeArgs($site, $argumente);
$confirmareGoogle = ConfirmareEmail::marcheazaConfirmat($db, $idGoogle, 'google', 'elena@exemplu.ro');
$verifica('Google cu o adresă verificată alta decât cea de acum a contului (schimbată în profil după legare): nu confirmă nimic',
    $idGoogle === $idElena && $confirmareGoogle['comenzi'] === 0 && !$parolaAnulata
    && $unu('SELECT email_confirmed_at FROM users WHERE id = :id', ['id' => $idElena]) === ''
    && $unu('SELECT user_id FROM orders WHERE id = :id', ['id' => $comandaAlta]) === '');
$verifica('callback-ul Google: confirmă cu adresa trimisă de Google, nu cu cea din cont',
    str_contains((string) file_get_contents($radacina . '/src/Http/Controllers/SiteController.php'),
        "ConfirmareEmail::marcheazaConfirmat(\$db, \$userId, 'google', strtolower(trim((string) (\$userInfo['email'] ?? ''))))"));
// Confirmarea cerută la înregistrare (vertij, Biovitality).
$setare('customer_email_confirmation_required', '1');
$db->exec('DELETE FROM register_submit_logs');
$idComandaCezar = $comandaFaraCont('CMD-CEZAR-WP25', 'cezar@exemplu.ro', 15);
$jarNou = $dosarLucru . '/nou-jar.txt';
$formular = $cerere('GET', '/register', $jarNou)['corp'];
preg_match('/name="register_form_token" value="([^"]+)"/', $formular, $t);
preg_match('/name="register_form_rendered_at" value="(\d+)"/', $formular, $ra);
sleep(3);
$dinainteMail = glob($dosarMail . '/*.eml') ?: [];
$r = $cerere('POST', '/register', $jarNou, ['register_form_token' => $t[1] ?? '', 'register_form_rendered_at' => $ra[1] ?? '0', 'register_hp_website' => '',
    'first_name' => 'Cezar', 'last_name' => 'Test', 'email' => 'cezar@exemplu.ro', 'phone' => '0700000001', 'password' => 'parola-cezar-1',
    'password_confirm' => 'parola-cezar-1']);
$idCezar = (int) $unu("SELECT id FROM users WHERE email = 'cezar@exemplu.ro'");
$mailCezar = $mailuriNoi($dinainteMail);
$tokenCezar = preg_match('#/cont/confirmare/([a-f0-9]{64})#', $textDinMail((string) end($mailCezar)), $mc2) === 1 ? $mc2[1] : '';
$verifica('confirmarea cerută: contul creat, linkul pe email, fără logare automată (spre /login), fără puncte revendicate',
    $idCezar > 0 && $r['cod'] === 302 && ($r['antete']['location'] ?? '') === '/login' && count($mailCezar) === 1 && $tokenCezar !== ''
    && !str_contains($cerere('GET', '/contul-meu', $jarNou)['corp'] . '', 'cezar@exemplu.ro')
    && (int) $unu('SELECT loyalty_points_pending_claim FROM orders WHERE id = :id', ['id' => $idComandaCezar]) === 15);
$r = $cerere('POST', '/login', $jarNou, ['email' => 'cezar@exemplu.ro', 'password' => 'parola-cezar-1']);
$paginaLogin = $cerere('GET', '/login', $jarNou)['corp'];
$verifica('logarea contului neconfirmat: refuzată cu mesajul ei și butonul „Trimite din nou linkul de confirmare”', $r['cod'] === 302
    && str_contains($paginaLogin, 'Confirmă întâi adresa de email') && str_contains($paginaLogin, 'Trimite din nou linkul de confirmare'));
$jarAltBrowser = $dosarLucru . '/alt-browser-jar.txt';
$paginaCezar = $cerere('GET', '/cont/confirmare/' . $tokenCezar, $jarAltBrowser)['corp'];
$fara = $cerere('POST', '/cont/confirmare/' . $tokenCezar, $jarAltBrowser, []);
$gresita = $cerere('POST', '/cont/confirmare/' . $tokenCezar, $jarAltBrowser, ['password' => 'nu-e-parola']);
$verifica('linkul de înregistrare: pagina cere parola contului; fără ea sau cu una greșită nu confirmă nimic (cine a primit linkul pe un cont '
    . 'făcut de altcineva cu adresa lui nu-l confirmă), linkul rămâne bun',
    str_contains($paginaCezar, 'name="password"') && $fara['cod'] === 302 && $gresita['cod'] === 302
    && $unu('SELECT email_confirmed_at FROM users WHERE id = :id', ['id' => $idCezar]) === ''
    && $unu('SELECT user_id FROM orders WHERE id = :id', ['id' => $idComandaCezar]) === '');
$bun = $cerere('POST', '/cont/confirmare/' . $tokenCezar, $jarAltBrowser, ['password' => 'parola-cezar-1']);
$verifica('cu parola contului: adresa confirmată, clientul intră, punctele (15) revendicate și comanda legată',
    $bun['cod'] === 302 && $unu('SELECT email_confirmed_at FROM users WHERE id = :id', ['id' => $idCezar]) !== ''
    && str_contains($cerere('GET', '/contul-meu', $jarAltBrowser)['corp'], 'cezar@exemplu.ro')
    && (int) $unu('SELECT user_id FROM orders WHERE id = :id', ['id' => $idComandaCezar]) === $idCezar
    && (int) $unu('SELECT COALESCE(SUM(points_delta), 0) FROM loyalty_points_transactions WHERE user_id = :id', ['id' => $idCezar]) === 15);
$jarAnaNou = $dosarLucru . '/ana2-jar.txt';
$r = $cerere('POST', '/login', $jarAnaNou, ['email' => 'bogdan@exemplu.ro', 'password' => 'parola-noua-123']);
$verifica('un cont de dinainte (fără link de înregistrare) intră ca înainte, cu setarea pornită', $r['cod'] === 302
    && str_starts_with((string) ($r['antete']['location'] ?? ''), '/contul-meu'));
$idFelix = $contNou('Felix', 'felix@exemplu.ro', 'parola-felix-1');
ConfirmareEmail::linkNou($db, $idFelix, ConfirmareEmail::SCOP_INREGISTRARE);
$jarFelix = $dosarLucru . '/felix-jar.txt';
$cerere('GET', '/login', $jarFelix);
$cerere('POST', '/login', $jarFelix, ['email' => 'felix@exemplu.ro', 'password' => 'parola-felix-1']);
$coduri = [];
for ($i = 0; $i < 6; $i++) {
    $cerere('POST', '/contul-meu/confirmare-email', $jarFelix, []);
    $coduri[] = str_contains($cerere('GET', '/login', $jarFelix)['corp'], 'prea multe linkuri') ? 'limita' : 'trimis';
}
$verifica('retrimiterea: 5 la 15 minute de la aceeași adresă, a șasea refuzată; bugetul nu se amestecă cu al înregistrărilor (VT-C8)',
    $coduri === ['trimis', 'trimis', 'trimis', 'trimis', 'trimis', 'limita']
    && (int) $unu("SELECT COUNT(*) FROM register_submit_logs WHERE status = 'resend'") === 5, implode(',', $coduri));
$db->exec('DELETE FROM register_submit_logs');
$setare('customer_email_confirmation_required', null);
// Tab-urile contului (agățări de probă pe al doilea server).
$db->prepare("INSERT INTO users (first_name, last_name, email, password_hash) VALUES ('Tab', 'Test', 'tab.test@exemplu.ro', :h)")
    ->execute(['h' => password_hash('parola-tab-123', PASSWORD_BCRYPT)]);
$jarTab = $dosarLucru . '/tab-jar.txt';
$cerere('GET', $baza2 . '/login', $jarTab);
$cerere('POST', $baza2 . '/login', $jarTab, ['email' => 'tab.test@exemplu.ro', 'password' => 'parola-tab-123']);
$cont = $cerere('GET', $baza2 . '/contul-meu?section=donatii-test', $jarTab)['corp'];
$contAna = $cerere('GET', $baza2 . '/contul-meu?section=donatii-test', $jarAna)['corp'];
$verifica('cont.taburi: tab-ul pluginului în meniu și conținutul lui când e ales; cont.antet: statistica din antet',
    str_contains($cont, 'href="/contul-meu?section=donatii-test"') && str_contains($cont, 'Donațiile mele (test)') && str_contains($cont, 'tab-plugin-test-continut')
    && str_contains($cont, '<strong>3</strong>') && str_contains($cont, '<span>Donații</span>'));
$verifica('fără conținut pentru client (agățarea nu dă tab-ul): niciun tab, iar ?section=donatii-test duce la profil', !str_contains($contAna, 'donatii-test')
    && str_contains($contAna, 'Profilul meu'));

// =====================================================================
// WP-26 A. Cererile de ofertă, meniul fără magazin și regula anonimizării, fără server
// =====================================================================
$scrie('');
$scrie('== WP-26 A. CereriOferta (limita, hash-ul adresei), grupul „Produse” fără magazin, regula anonimizării');
CereriOferta::reseteaza();
$db->exec('DELETE FROM quote_submit_logs');
$hashA = CereriOferta::hashIp($db, '203.0.113.7');
$secretIp = $unu("SELECT value FROM settings WHERE `key` = 'cereri_oferta_secret_ip'");
Settings::save($db, ['cereri_oferta_secret_ip' => 'suprascris']);
$verifica('hash-ul adresei: 64 de caractere hex, același pentru aceeași adresă, altul pentru alta, nu sha256 simplu al adresei; cheia HMAC e un '
    . 'secret aleator al site-ului (revizuirea WP-26), ascuns din Settings::all și pe care Settings::save nu-l scrie',
    preg_match('/^[a-f0-9]{64}$/', $hashA) === 1 && $hashA === CereriOferta::hashIp($db, '203.0.113.7') && $hashA !== CereriOferta::hashIp($db, '203.0.113.8')
    && $hashA !== hash('sha256', '203.0.113.7') && CereriOferta::hashIp($db, '2001:db8:1:2::5') === CereriOferta::hashIp($db, '2001:db8:1:2::9')
    && preg_match('/^[a-f0-9]{64}$/', $secretIp) === 1 && $hashA === hash_hmac('sha256', '203.0.113.7', $secretIp)
    && !array_key_exists('cereri_oferta_secret_ip', Settings::all($db))
    && $unu("SELECT value FROM settings WHERE `key` = 'cereri_oferta_secret_ip'") === $secretIp);
for ($i = 0; $i < CereriOferta::LIMITA_CERERI - 1; $i++) {
    CereriOferta::noteazaTrimiterea($db, '203.0.113.7');
}
$subLimita = !CereriOferta::limitat($db, '203.0.113.7');
CereriOferta::noteazaTrimiterea($db, '203.0.113.7');
$verifica('limita: 4 trimiteri = încă voie, a 5-a = limitat (5 la 10 minute); altă adresă nu e atinsă; în tabel doar hash-ul',
    $subLimita && CereriOferta::limitat($db, '203.0.113.7') && !CereriOferta::limitat($db, '203.0.113.8')
    && (int) $unu("SELECT COUNT(*) FROM quote_submit_logs WHERE ip_hash LIKE '%203.0.113%'") === 0);
$db->exec('UPDATE quote_submit_logs SET created_at = NOW() - INTERVAL 11 MINUTE');
$verifica('limita: trimiterile mai vechi de 10 minute nu se mai numără', !CereriOferta::limitat($db, '203.0.113.7'));
$db->exec('DELETE FROM quote_submit_logs');
$stariInainte = Plugins::stari();
Plugins::folosesteStari(array_merge($stariInainte, ['magazin' => Plugins::OPRIT]));
$grupDupaCheie = static fn (string $cheie): array => array_values(array_filter(Plugins::meniuAdmin(), static fn (array $s): bool => $s['cheie'] === $cheie))[0] ?? [];
$comenziFara = $grupDupaCheie('comenzi');
$produseFara = $grupDupaCheie('produse');
Plugins::folosesteStari($stariInainte);
$comenziCu = $grupDupaCheie('comenzi');
// WP-30 (meniul nou, OD-25): grupul „Produse” există oricum cu catalogul; „Cereri de ofertă” stă în „Comenzi”, singură fără
// magazin (deci link cu numele ei, views/admin/layout.php), lângă „Comenzi” cu el. Înlocuiește eticheta „Produse” a GR-14c.
$verifica('GR-14c / WP-30: fără magazin „Comenzi” are doar „Cereri de ofertă”, iar „Produse” are catalogul; cu magazin, Comenzi + Cereri de ofertă',
    array_column((array) ($comenziFara['intrari'] ?? []), 'eticheta') === ['Cereri de ofertă'] && ($produseFara['eticheta'] ?? '') === 'Produse'
    && in_array('Produse', array_column((array) ($produseFara['intrari'] ?? []), 'eticheta'), true)
    && array_column((array) ($comenziCu['intrari'] ?? []), 'eticheta') === ['Comenzi', 'Cereri de ofertă']);
require_once $radacina . '/src/Support/Verificare.php';
require_once $radacina . '/tools/verificare/lib/incarcare.php';
$reguliBs = \Unelte\Verificare\Reguli::incarca($radacina, 'bioscem');
$regulaCereri = (array) $reguliBs->tabel('quote_requests');
$t = new \Unelte\Verificare\Transformari(random_bytes(32));
$randCerere = ['id' => 4, 'product_id' => 1, 'product_name' => 'Produs Demo', 'product_slug' => 'produs-demo', 'name' => 'Ana Pop',
    'company' => 'Firma Pop SRL', 'email' => 'ana.pop@exemplu-client.ro', 'phone' => '0722333444', 'message' => 'Vreau 500 de bucăți, sunați-mă',
    'status' => 'noua', 'admin_note' => 'A sunat Ana Pop', 'consent_at' => '2026-10-01 10:00:00', 'source_url' => 'https://exemplu.ro/produs/x?email=ana',
    'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00', 'deleted_at' => null];
$anonim = $t->rand('quote_requests', $regulaCereri, $randCerere, []);
$verifica('regula anonimizării (tools/verificare/reguli): quote_requests „anonimizeaza” (cine a cerut, mesajul, nota, adresa paginii schimbate; '
    . 'produsul și starea păstrate), quote_submit_logs doar structura',
    ($regulaCereri['mod'] ?? '') === 'anonimizeaza' && ($reguliBs->tabel('quote_submit_logs')['mod'] ?? '') === 'doar_structura'
    && str_ends_with((string) $anonim['email'], '@verificare.invalid') && !str_contains(json_encode($anonim, JSON_UNESCAPED_UNICODE) ?: '', 'Pop')
    && !str_contains((string) $anonim['phone'], '722333444') && $anonim['product_name'] === 'Produs Demo' && $anonim['status'] === 'noua'
    && !str_contains((string) $anonim['source_url'], 'email=ana'), json_encode($anonim, JSON_UNESCAPED_UNICODE) ?: '');

// =====================================================================
// WP-26 B. Butonul, cererea, lista din admin, stările pluginului (prin server)
// =====================================================================
$scrie('');
$scrie('== WP-26 B. {{product_quote_button}}, /api/cerere-oferta, Cereri de ofertă în admin, se oprește și oprit');
$produsOferta = $db->query('SELECT id, slug, name, product_template_id FROM products WHERE deleted_at IS NULL AND is_active = 1
    AND product_template_id IS NOT NULL ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$slugOferta = (string) $produsOferta['slug'];
$db->prepare("UPDATE product_templates SET html_content = CONCAT(html_content, '<div class=\"oferta-test\">{{product_quote_button}}</div>') WHERE id = :id")
    ->execute(['id' => (int) $produsOferta['product_template_id']]);
ResponseCache::purgePageCache();
$paginaProdus = $cerere('GET', '/produs/' . $slugOferta)['corp'];
$verifica('pagina produsului: butonul cu textul implicit „Cere ofertă”, sertarul cu formularul (produsul în data-produs, capcana), scriptul '
    . 'sertarului; tema demo fără Bootstrap: JavaScript-ul Bootstrap din nucleu și regulile CSS ale sertarului',
    str_contains($paginaProdus, 'sertar-oferta__declansator') && preg_match('#aria-controls="sertar-oferta">\s*Cere ofertă#u', $paginaProdus) === 1
    && str_contains($paginaProdus, 'data-produs="' . $slugOferta . '"') && str_contains($paginaProdus, 'name="website"')
    && str_contains($paginaProdus, '/assets/js/sertar-oferta.js?v=') && str_contains($paginaProdus, 'bootstrap.bundle.min.js')
    && str_contains($paginaProdus, '.sertar-oferta.offcanvas{position:fixed') && !str_contains($paginaProdus, '{{product_quote_button}}'),
    mb_substr($paginaProdus, max(0, (int) strpos($paginaProdus, 'oferta-test') - 50), 400));
$setare('cereri_oferta_buton', 'Solicită mostre și ofertă de preț');
$setare('cereri_oferta_confidentialitate_url', '/politica-de-confidentialitate');
$paginaProdus = $cerere('GET', '/produs/' . $slugOferta)['corp'];
$verifica('textele din setări (ca înghețarea grafoanaytis): butonul și linkul spre politica de confidențialitate',
    str_contains($paginaProdus, 'Solicită mostre și ofertă de preț') && str_contains($paginaProdus, 'href="/politica-de-confidentialitate"'));
$setare('contact_form_recipients', 'birou@exemplu.ro');
$db->exec('DELETE FROM quote_submit_logs');
$maxCerere = (int) $unu('SELECT COALESCE(MAX(id), 0) FROM quote_requests');
$dinainteMail = glob($dosarMail . '/*.eml') ?: [];
$json = static fn (array $d): string => (string) json_encode($d, JSON_UNESCAPED_UNICODE);
$antetJson = ['Content-Type: application/json', 'Referer: ' . $baza . '/produs/' . $slugOferta];
$r = $cerere('POST', '/api/cerere-oferta', null, $json(['product_slug' => $slugOferta, 'name' => 'Ionel Ofertant', 'company' => 'Tipar Test SRL',
    'email' => 'Ionel.Ofertant@exemplu.ro', 'phone' => '0700111222', 'message' => "Vreau 1000 de bucăți.\nCât costă?", 'website' => '', 'consent' => true]), $antetJson);
$rand = $db->query('SELECT * FROM quote_requests WHERE id > ' . $maxCerere)->fetch(PDO::FETCH_ASSOC) ?: [];
$mailuri = $mailuriNoi($dinainteMail);
$verifica('o cerere bună: 200 cu textul de mulțumire; rândul salvat (produsul găsit după slug pe server, emailul cu litere mici, acordul, '
    . 'pagina de pe care a venit), starea „noua”',
    $r['cod'] === 200 && str_contains($r['corp'], '"ok":true') && str_contains($r['corp'], 'Vă răspundem cât mai curând')
    && (int) ($rand['product_id'] ?? 0) === (int) $produsOferta['id'] && ($rand['product_name'] ?? '') === $produsOferta['name']
    && ($rand['email'] ?? '') === 'ionel.ofertant@exemplu.ro' && ($rand['consent_at'] ?? '') !== '' && ($rand['status'] ?? '') === 'noua'
    && str_ends_with((string) ($rand['source_url'] ?? ''), '/produs/' . $slugOferta), $r['cod'] . ' ' . $r['corp']);
$subiectMail = static fn (string $s): string => str_contains($s, '=?') ? (string) iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : $s;
$verifica('emailul spre destinatarii formularului de contact: subiectul cu produsul, Reply-To = vizitatorul (GR-01)',
    count($mailuri) === 1 && $antetMail((string) ($mailuri[0] ?? ''), 'To') === 'birou@exemplu.ro'
    && str_contains($antetMail((string) ($mailuri[0] ?? ''), 'Reply-To'), 'ionel.ofertant@exemplu.ro')
    && str_contains($subiectMail($antetMail((string) ($mailuri[0] ?? ''), 'Subject')), 'Cerere de ofertă: ' . $produsOferta['name']),
    mb_substr((string) ($mailuri[0] ?? ''), 0, 600));
$verifica('jurnalul limitei: un rând, doar hash-ul adresei (nu 127.0.0.1)',
    (int) $unu('SELECT COUNT(*) FROM quote_submit_logs') === 1 && $unu('SELECT ip_hash FROM quote_submit_logs') === CereriOferta::hashIp($db, '127.0.0.1'));
$rFaraEmail = $cerere('POST', '/api/cerere-oferta', null, $json(['name' => 'X', 'email' => 'nu-e-adresa', 'consent' => true]), $antetJson);
$rFaraAcord = $cerere('POST', '/api/cerere-oferta', null, $json(['name' => 'X', 'email' => 'x@exemplu.ro', 'consent' => false]), $antetJson);
$rCapcana = $cerere('POST', '/api/cerere-oferta', null, $json(['name' => 'Robot', 'email' => 'robot@exemplu.ro', 'consent' => true, 'website' => 'http://spam']), $antetJson);
$verifica('email greșit 422, fără acord 422, capcana completată: 200 „ok”, dar niciun rând nou',
    $rFaraEmail['cod'] === 422 && $rFaraAcord['cod'] === 422 && $rCapcana['cod'] === 200 && str_contains($rCapcana['corp'], '"ok":true')
    && (int) $unu('SELECT COUNT(*) FROM quote_requests WHERE id > ' . $maxCerere) === 1);
$r5 = $cerere('POST', '/api/cerere-oferta', null, $json(['name' => 'A cincea', 'email' => 'a5@exemplu.ro', 'consent' => true]), $antetJson);
$r6 = $cerere('POST', '/api/cerere-oferta', null, $json(['name' => 'A șasea', 'email' => 'a6@exemplu.ro', 'consent' => true]), $antetJson);
$verifica('limita prin server: a 5-a trimitere în 10 minute trece, a 6-a primește 429 și nu se salvează',
    $r5['cod'] === 200 && $r6['cod'] === 429 && (int) $unu("SELECT COUNT(*) FROM quote_requests WHERE email = 'a6@exemplu.ro'") === 0, $r6['cod'] . ' ' . $r6['corp']);
$db->exec('DELETE FROM quote_submit_logs');
$idCerere = (int) ($rand['id'] ?? 0);
$numeRau = 'Ionel O' . "'" . 'Brien"); alert(1); ("';
$db->prepare('UPDATE quote_requests SET name = :n WHERE id = :id')->execute(['n' => $numeRau, 'id' => $idCerere]);
$logareAdmin();
$lista = $cerere('GET', '/admin/cereri-oferta', $jarAdmin)['corp'];
$verifica('lista din admin: cererea cu produsul și emailul; numele (din formularul public) ajunge în confirm() doar ca șir JSON',
    str_contains($lista, 'ionel.ofertant@exemplu.ro') && str_contains($lista, 'href="/produs/' . $slugOferta . '"') && str_contains($lista, 'Cereri de ofertă')
    && !str_contains($lista, "O'Brien\"); alert(1)")
    && str_contains($lista, 'return confirm(' . htmlspecialchars((string) json_encode('Ștergeți cererea de la ' . $numeRau . '?', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES) . ')'), mb_substr($lista, (int) strpos($lista, 'onsubmit'), 300));
preg_match('/name="_csrf" value="([^"]+)"/', $lista, $mc);
$cerere('POST', '/admin/cereri-oferta/' . $idCerere . '/stare', $jarAdmin, ['stare' => 'in_lucru', 'nota' => 'Fără jeton']);
$faraJeton = $unu('SELECT status FROM quote_requests WHERE id = :id', ['id' => $idCerere]);
$r = $cerere('POST', '/admin/cereri-oferta/' . $idCerere . '/stare', $jarAdmin, ['stare' => 'trimisa', 'nota' => 'Ofertă trimisă pe email', 'stare_filtru' => 'noua',
    '_csrf' => $mc[1] ?? '']);
$verifica('starea: fără jetonul anti-CSRF nu se schimbă nimic; cu el starea și nota se salvează, înapoi la filtrul deschis, un rând în Jurnal activitate',
    $faraJeton === 'noua' && $r['cod'] === 302 && ($r['antete']['location'] ?? '') === '/admin/cereri-oferta?stare=noua'
    && $unu('SELECT status FROM quote_requests WHERE id = :id', ['id' => $idCerere]) === 'trimisa'
    && $unu('SELECT admin_note FROM quote_requests WHERE id = :id', ['id' => $idCerere]) === 'Ofertă trimisă pe email'
    && (int) $unu("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'cerere_oferta_stare'") >= 1);
$r = $cerere('POST', '/admin/cereri-oferta/' . $idCerere . '/sterge', $jarAdmin, ['_csrf' => $mc[1] ?? '']);
$lista = $cerere('GET', '/admin/cereri-oferta', $jarAdmin)['corp'];
$verifica('ștergerea: „moale” (deleted_at), cererea nu mai e în listă, rândul rămâne în bază',
    $r['cod'] === 302 && $unu('SELECT deleted_at FROM quote_requests WHERE id = :id', ['id' => $idCerere]) !== ''
    && !str_contains($lista, 'ionel.ofertant@exemplu.ro'));
$licenta('toate', 'cereri_oferta=se_opreste');
$paginaProdus = $cerere('GET', '/produs/' . $slugOferta)['corp'];
$r = $cerere('POST', '/api/cerere-oferta', null, $json(['name' => 'Târziu', 'email' => 'tarziu@exemplu.ro', 'consent' => true]), $antetJson);
$lista = $cerere('GET', '/admin/cereri-oferta', $jarAdmin);
$verifica('se oprește: fără buton și fără scriptul lui pe produs, o cerere nouă 403 (nesalvată), lista din admin rămâne',
    !str_contains($paginaProdus, 'sertar-oferta') && !str_contains($paginaProdus, '{{product_quote_button}}') && $r['cod'] === 403
    && (int) $unu("SELECT COUNT(*) FROM quote_requests WHERE email = 'tarziu@exemplu.ro'") === 0 && $lista['cod'] === 200
    && str_contains($lista['corp'], 'a5@exemplu.ro'), $r['cod'] . ' ' . $lista['cod']);
$licenta('toate', 'cereri_oferta=oprit');
$paginaProdus = $cerere('GET', '/produs/' . $slugOferta)['corp'];
$r = $cerere('POST', '/api/cerere-oferta', null, $json(['name' => 'Oprit', 'email' => 'oprit@exemplu.ro', 'consent' => true]), $antetJson);
$lista = $cerere('GET', '/admin/cereri-oferta', $jarAdmin);
$meniu = $cerere('GET', '/admin', $jarAdmin)['corp'];
$verifica('oprit: fără buton, /api/cerere-oferta 404 (JSON), lista din admin 403, meniul fără „Cereri de ofertă”; rândurile rămân în bază',
    !str_contains($paginaProdus, 'sertar-oferta') && !str_contains($paginaProdus, '{{product_quote_button}}') && $r['cod'] === 404
    && str_contains((string) ($r['antete']['content-type'] ?? ''), 'json') && $lista['cod'] === 403 && !str_contains($meniu, 'href="/admin/cereri-oferta"')
    && (int) $unu('SELECT COUNT(*) FROM quote_requests') >= 5, $r['cod'] . ' ' . $lista['cod']);
$licenta('grafoanaytis');
$meniu = $cerere('GET', '/admin', $jarAdmin)['corp'];
// WP-30 (meniul nou, docs/ARHITECTURA-ADMIN.md): grupul „Produse” are catalogul, iar „Cereri de ofertă” e singura intrare a
// grupului „Comenzi”, deci apare ca link cu numele ei (înlocuiește eticheta „Produse” a grupului Magazin, GR-14c, OD-25).
$verifica('licența grafoanaytis: grupul din meniu „Produse” și linkul „Cereri de ofertă” (singur în Comenzi); nimic din magazin',
    preg_match('#<span class="ax-meniu-eticheta">Produse</span>#u', $meniu) === 1
    && preg_match('#<a class="ax-meniu-link[^"]*" href="/admin/cereri-oferta"[^>]*>.*?<span class="ax-meniu-eticheta">Cereri de ofertă</span>#su', $meniu) === 1
    && !str_contains($meniu, 'href="/admin/orders"'), mb_substr(strip_tags($meniu), 0, 400));
$licenta('toate');
$setare('cereri_oferta_buton', null);
$setare('cereri_oferta_confidentialitate_url', null);
$setare('contact_form_recipients', null);

// =====================================================================
// WP-26 C. Exportul datelor legale (scripts/exporta-date-legale.php, /_andaxi/export/{token})
// =====================================================================
$scrie('');
$scrie('== WP-26 C. Exportul datelor cu păstrare legală');
$dosarExport = \App\Support\ExportDateLegale::dosar();
$exportInainte = array_merge(glob($dosarExport . '/*.csv') ?: [], glob($dosarExport . '/*.json') ?: []);
$ruleaza = static function (string ...$argumente) use ($radacina): array {
    exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/scripts/exporta-date-legale.php') . ' '
        . implode(' ', array_map('escapeshellarg', $argumente)) . ' 2>&1', $iesire, $cod);
    return ['cod' => $cod, 'iesire' => implode("\n", $iesire)];
};
$e = $ruleaza('--ce=comenzi');
preg_match('#/_andaxi/export/([a-f0-9]{64})#', $e['iesire'], $ml);
$tokenExport = (string) ($ml[1] ?? '');
$cheieExport = hash('sha256', $tokenExport);
$fisierExport = $dosarExport . '/' . $cheieExport . '.csv';
$comenziTotal = (int) $unu('SELECT COUNT(*) FROM orders');
$verifica('scriptul: cod 0, doar numărul de rânduri (= comenzile din bază) și linkul; CSV-ul în storage/export cu drepturi 0600, numit după '
    . 'hash-ul tokenului (tokenul nu e pe disc)',
    $e['cod'] === 0 && str_contains($e['iesire'], 'rânduri  : ' . $comenziTotal) && $tokenExport !== '' && is_file($fisierExport)
    && (fileperms($fisierExport) & 0777) === 0600 && !str_contains($e['iesire'], '@') && glob($dosarExport . '/*' . $tokenExport . '*') === [], $e['iesire']);
$r = $cerere('GET', '/_andaxi/export/' . $tokenExport);
$inregistrari = [];
$fCsv = fopen('php://memory', 'r+');
fwrite($fCsv, (string) preg_replace('/^\xEF\xBB\xBF/', '', $r['corp']));
rewind($fCsv);
while (($linieCsv = fgetcsv($fCsv, null, ',', '"', '')) !== false) {
    $inregistrari[] = $linieCsv;
}
fclose($fCsv);
$verifica('linkul: 200, text/csv ca atașament, no-store; capul de tabel plus un rând pe comandă (numărul din bază)',
    $r['cod'] === 200 && str_starts_with((string) ($r['antete']['content-type'] ?? ''), 'text/csv')
    && str_contains((string) ($r['antete']['content-disposition'] ?? ''), 'attachment') && str_contains((string) ($r['antete']['cache-control'] ?? ''), 'no-store')
    && in_array('order_number', $inregistrari[0] ?? [], true) && count($inregistrari) - 1 === $comenziTotal, $r['cod'] . ' ' . count($inregistrari));
$r2 = $cerere('GET', '/_andaxi/export/' . $tokenExport);
$verifica('a doua folosire: 404; fișierul și descrierea lui au dispărut', $r2['cod'] === 404 && !is_file($fisierExport)
    && !is_file($dosarExport . '/' . $cheieExport . '.json'));
$e = $ruleaza('--ce=acorduri_gdpr', '--din=2099-01-01');
preg_match('#/_andaxi/export/([a-f0-9]{64})#', $e['iesire'], $ml);
$tokenGdpr = (string) ($ml[1] ?? '');
$metaGdpr = $dosarExport . '/' . hash('sha256', $tokenGdpr) . '.json';
$verifica('--din în viitor: 0 rânduri, tot cu link', $e['cod'] === 0 && str_contains($e['iesire'], 'rânduri  : 0') && is_file($metaGdpr), $e['iesire']);
$metaDate = json_decode((string) file_get_contents($metaGdpr), true);
$metaDate['expira'] = time() - 1;
file_put_contents($metaGdpr, json_encode($metaDate));
$r = $cerere('GET', '/_andaxi/export/' . bin2hex(random_bytes(32)));
$verifica('un token necunoscut: 404; un export expirat se șterge la următoarea cerere (fișierul și descrierea)',
    $r['cod'] === 404 && !is_file($metaGdpr) && !is_file(substr($metaGdpr, 0, -5) . '.csv'));
$eDonatii = $ruleaza('--ce=donatii');
$eGresit = $ruleaza('--ce=parole');
$eData = $ruleaza('--ce=comenzi', '--din=31-12-2025');
$verifica('refuzuri: --ce necunoscut cod 2, o dată greșită cod 2, un tabel care nu există pe site (donations) cod 1, fără fișier lăsat',
    $eGresit['cod'] === 2 && $eData['cod'] === 2 && $eDonatii['cod'] === ((int) $unu("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donations'") === 1 ? 0 : 1)
    && array_diff(array_merge(glob($dosarExport . '/*.csv') ?: [], glob($dosarExport . '/*.json') ?: []), $exportInainte) === [],
    $eGresit['iesire'] . ' | ' . $eData['iesire'] . ' | ' . $eDonatii['iesire']);
// Revizuirea WP-26: curățenia exporturilor expirate fără alt export (o dată pe zi la o cerere, la deploy, din Dashboard).
$expirat = static function () use ($dosarExport): string {
    $cheie = hash('sha256', bin2hex(random_bytes(32)));
    file_put_contents($dosarExport . '/' . $cheie . '.csv', "a\n");
    file_put_contents($dosarExport . '/' . $cheie . '.json', json_encode(['expira' => time() - 10, 'nume' => 'x.csv', 'ce' => 'comenzi', 'randuri' => 0]));
    return $dosarExport . '/' . $cheie;
};
$semnCuratenie = $dosarExport . '/.curatat';
$semnInainte = is_file($semnCuratenie) ? (int) filemtime($semnCuratenie) : null;
@unlink($semnCuratenie);
$e1 = $expirat();
$cerere('GET', '/health');
$e2 = $expirat();
$cerere('GET', '/health');
$sters1 = !is_file($e1 . '.csv') && !is_file($e1 . '.json');
$ramas2 = is_file($e2 . '.csv');
$logareAdmin();
$cerere('GET', '/admin', $jarAdmin);
$e3 = $expirat();
exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/scripts/migreaza.php') . ' --aplica >/dev/null 2>&1');
$verifica('exporturile expirate se șterg fără alt export: la prima cerere a zilei (a doua cerere din zi nu mai caută), la Dashboard și la '
    . 'scripts/migreaza.php --aplica',
    $sters1 && $ramas2 && !is_file($e2 . '.csv') && !is_file($e3 . '.csv') && !is_file($e3 . '.json'));
if ($semnInainte === null) {
    @unlink($semnCuratenie);
} else {
    @touch($semnCuratenie, $semnInainte);
}
foreach (array_diff(array_merge(glob($dosarExport . '/*.csv') ?: [], glob($dosarExport . '/*.json') ?: []), $exportInainte) as $rest) {
    @unlink($rest);
}

// =====================================================================
// WP-26 D. Uneltele de operare: verifica-email.php, diagnostic.php --email
// =====================================================================
$scrie('');
$scrie('== WP-26 D. scripts/verifica-email.php și scripts/diagnostic.php --email (fără adrese, fără parole)');
$scriptOp = static function (string $script, string ...$argumente) use ($radacina): array {
    exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/scripts/' . $script) . ' '
        . implode(' ', array_map('escapeshellarg', $argumente)) . ' 2>&1', $iesire, $cod);
    return ['cod' => $cod, 'iesire' => implode("\n", $iesire)];
};
$setare('contact_form_recipients', 'secretariat.firma@exemplu-real.ro, gresit-fara-arond');
$setare('smtp_host', 'smtp.exemplu-real.ro');
$setare('smtp_username', 'cont.smtp@alt-domeniu.ro');
$setare('smtp_password', 'parola-smtp-foarte-secreta');
$setare('order_email_from_address', 'comenzi@exemplu-real.ro');
$db->exec("INSERT INTO email_send_history (recipient, subject, email_type, status, created_at) VALUES ('destinatar.ascuns@exemplu-real.ro', 'Test', 'test_wp26', 'sent', NOW())");
$v = $scriptOp('verifica-email.php');
$verifica('verifica-email: destinatarii și ultimele trimiteri ascunse (s***@domeniu), parola doar „(pusă)”, adresa invalidă numărată, '
    . 'avertismentul DMARC (contul SMTP pe alt domeniu), cod 1',
    $v['cod'] === 1 && str_contains($v['iesire'], 's***@exemplu-real.ro') && str_contains($v['iesire'], 'd***@exemplu-real.ro')
    && !str_contains($v['iesire'], 'secretariat.firma') && !str_contains($v['iesire'], 'destinatar.ascuns') && !str_contains($v['iesire'], 'cont.smtp@')
    && !str_contains($v['iesire'], 'parola-smtp-foarte-secreta') && str_contains($v['iesire'], '(pusă)') && str_contains($v['iesire'], 'DMARC')
    && str_contains($v['iesire'], '1 din adresele'), $v['iesire']);
$vr = $scriptOp('verifica-email.php', '--repara', '--destinatari=birou.nou@exemplu-real.ro', '--expeditor=comenzi@alt-domeniu.ro');
$verifica('verifica-email --repara: valorile din parametri scrise (contact_form_recipients, order_email_from_address), parola neatinsă; '
    . 'fără parametri refuzat (cod 2)',
    $unu("SELECT value FROM settings WHERE `key` = 'contact_form_recipients'") === 'birou.nou@exemplu-real.ro'
    && $unu("SELECT value FROM settings WHERE `key` = 'order_email_from_address'") === 'comenzi@alt-domeniu.ro'
    && $unu("SELECT value FROM settings WHERE `key` = 'smtp_password'") === 'parola-smtp-foarte-secreta' && !str_contains($vr['iesire'], 'parola-smtp')
    && $scriptOp('verifica-email.php', '--repara')['cod'] === 2, $vr['iesire']);
$dg = $scriptOp('diagnostic.php', '--email');
$verifica('diagnostic.php --email: doar numere (trimiterile pe tipuri și stări), nicio adresă, niciun IP, cod 0',
    $dg['cod'] === 0 && str_contains($dg['iesire'], 'test_wp26') && !str_contains($dg['iesire'], '@') && preg_match('/\b\d{1,3}(\.\d{1,3}){3}\b/', $dg['iesire']) !== 1,
    $dg['iesire']);
foreach (['contact_form_recipients', 'smtp_host', 'smtp_username', 'smtp_password', 'order_email_from_address'] as $cheieOp) {
    $setare($cheieOp, null);
}

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
    && preg_match('/PHP Warning:  (PHP Request Startup: )?POST Content-Length of \d+ bytes exceeds the limit/', $eroriDoi) === 1,
    mb_substr($eroriDoi, 0, 1500));

$scrie('');
$scrie('Rezultat: ' . $trecute . ' verificări trecute, ' . $esuate . ' eșuate. Raport: ' . $fisierRaport);
exit($esuate === 0 ? 0 : 1);
