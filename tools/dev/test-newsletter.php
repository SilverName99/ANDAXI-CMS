<?php

declare(strict_types=1);

/**
 * Testele newsletterului portat din codul vechi în WP-R din 30 oct 2026 (Bioscem e2e2d77 … 739f58c; codul vechi n-avea teste
 * scrise, doar verificările descrise în mesajele commit-urilor, refăcute aici), cu serverul de dezvoltare, pe baza de test.
 * Ce se vede în pagini și ce rămâne în bază după fiecare flux public sau din admin, față de codul Bioscem însuși, îl dovedește
 * comparația (tools/dev/compara-cu-baza.sh, cazurile „newsletter: …”); aici, ce nu se vede acolo:
 *
 *   tools/dev/php.sh tools/dev/test-newsletter.php
 *
 * A. Cheile: pragul cheii vechi scris de migrarea newsletter/0040; cheia de semnare aleatoare (Settings::secretGenerat), refăcută
 *    după ce e golită; ascunse din Settings::all și din Settings::save; IPv6 numărat pe /64; tokenul vechi doar sub prag; lista de
 *    verificare nu cere adrese din rețeaua serverului (SSRF).
 * B. Trimiterea (serverul de email care refuză e un port închis): oprirea după 5 respingeri, cel mult 3 încercări, cronul nu
 *    reia o campanie oprită sau o ciornă, lacătul campaniei (altă conexiune îl ține: nimic trimis), fiecare o singură dată, a
 *    doua apăsare refuzată, antetele List-Unsubscribe / List-Unsubscribe-Post.
 * C. Dubla confirmare: „pending” și emailul, limita pe adresă, pe IP (IPv6 /64), plafonul global și nota lui, linkul de
 *    dezabonare în afara plafonului, curățenia din cron.
 * D. Lista „nu trimite” (doar amprenta) și importurile care nu reactivează un dezabonat.
 * E. Pe site: GET-ul arată doar butonul, POST-ul „cu un clic” din antet dezabonează, tokenul vechi pe POST e refuzat, clicurile
 *    (cheia nouă, cheia veche peste prag), 12 cereri de abonare în paralel = un email, pluginul oprit.
 *
 * Toată baza (fiecare tabel) e pusă la loc la final, verificat cu CHECKSUM TABLE; la fel licența „toate”, sesiunile și emailurile
 * capturate. Raportul: $CMS_DEV_DIR/test-newsletter-raport.txt; cod de ieșire 0 doar dacă totul a trecut. Date inventate.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';
require_once __DIR__ . '/lib/salvari-admin.php';

use App\Support\Database;
use App\Support\Paths;
use App\Support\ResponseCache;
use App\Support\Settings;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-newsletter-raport.txt';
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
$scrie('Test newsletter (WP-R din 30 oct: codul vechi e2e2d77 … 739f58c) ANDAXI-CMS — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

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
if (!$db instanceof PDO || (int) $db->query('SELECT COUNT(*) FROM newsletter_subscribers')->fetchColumn() < 20) {
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
$jarAdmin = $dosarDev . '/test-newsletter-admin-' . getmypid() . '.txt';
$jarClient = $dosarDev . '/test-newsletter-client-' . getmypid() . '.txt';
$licentaSchimbata = false;
$fisiereTest = [];
$procese = [];
$dosarLucru = $dosarDev . '/test-newsletter-' . getmypid();
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

$ns = \App\Support\NewsletterService::class;
/** O metodă privată a lui NewsletterService (cheile, tokenurile, lacătul). */
$privat = static fn (string $metoda, mixed ...$argumente): mixed => (new ReflectionMethod($ns, $metoda))->invoke(null, ...$argumente);
/** Uită cheia și pragul ținute minte în proces (după ce testul le schimbă în bază). */
$uita = static function () use ($ns): void {
    foreach (['cheieSemnare' => null, 'limitaVeche' => null] as $proprietate => $valoare) {
        (new ReflectionProperty($ns, $proprietate))->setValue(null, $valoare);
    }
};
$b64 = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
$tokenCu = static fn (int $id, string $email, string $cheie): string => $b64($id . '|' . strtolower($email)) . '.'
    . $b64(hash_hmac('sha256', $b64($id . '|' . strtolower($email)), $cheie, true));
$setari = Settings::all($db);

// Datele testului: o listă cu 6 abonați inventați și o ciornă trimisă doar lor.
$db->exec("INSERT INTO newsletter_lists (name, description, is_default) VALUES ('Lista testului newsletter', 'test', 0)");
$listaTest = (int) $db->lastInsertId();
$abonatiTest = [];
for ($k = 1; $k <= 6; $k++) {
    $db->prepare('INSERT INTO newsletter_subscribers (email, name, status) VALUES (?, ?, \'active\')')->execute([sprintf('nltest%02d@exemplu.ro', $k), 'Test ' . $k]);
    $abonatiTest[] = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO newsletter_list_subscribers (list_id, subscriber_id) VALUES (?, ?)')->execute([$listaTest, end($abonatiTest)]);
}
$campanieNoua = static function (string $nume) use ($db, $listaTest): int {
    $db->prepare('INSERT INTO newsletter_campaigns (name, template_type, subscriber_list_id, subject, html_content, status)
        VALUES (?, \'newsletter\', ?, \'Subiect de test\', \'<p>Salut <a href="https://exemplu.ro/x?a=1&amp;b=2">link</a></p>\', \'draft\')')
        ->execute([$nume, $listaTest]);
    return (int) $db->lastInsertId();
};

// =====================================================================
$scrie('');
$scrie('== A. Cheile, pragul, adresele (codul vechi 9aae46a, fd07fc3, 739f58c)');
$verifica('A1 migrarea newsletter/0040 a scris pragul cheii vechi pe baza nouă: {"campanie":0,"abonat":0} (un site nou n-are linkuri vechi)',
    $unu("SELECT value FROM settings WHERE `key` = 'newsletter_cheie_limita'") === '{"campanie":0,"abonat":0}');
$db->exec("DELETE FROM settings WHERE `key` = 'newsletter_cheie_semnare'");
$uita();
$cheie1 = $privat('cheieSemnare');
$db->exec("UPDATE settings SET `value` = '' WHERE `key` = 'newsletter_cheie_semnare'");
$uita();
$cheie2 = $privat('cheieSemnare');
$uita();
$verifica('A2 cheia de semnare: 64 hex aleatoare, făcută la prima semnare; golită (ca pe copia de verificare), alta nouă, o singură dată',
    preg_match('/^[0-9a-f]{64}$/', $cheie1) === 1 && preg_match('/^[0-9a-f]{64}$/', $cheie2) === 1 && $cheie1 !== $cheie2
    && $privat('cheieSemnare') === $cheie2 && $unu("SELECT value FROM settings WHERE `key` = 'newsletter_cheie_semnare'") === $cheie2);
Settings::save($db, ['newsletter_cheie_semnare' => 'scris-din-formular', 'newsletter_cheie_limita' => 'x']);
$toate = Settings::all($db);
$verifica('A3 cheia și pragul nu ies prin Settings::all și nu se scriu cu Settings::save (Settings::CHEI_ASCUNSE)',
    !isset($toate['newsletter_cheie_semnare']) && !isset($toate['newsletter_cheie_limita'])
    && $unu("SELECT value FROM settings WHERE `key` = 'newsletter_cheie_semnare'") === $cheie2);
$verifica('A4 limita pe IP: IPv4 pe adresă, IPv6 pe rețeaua /64', $ns::cheieIp('198.51.100.7') === '198.51.100.7'
    && $ns::cheieIp('2001:db8:1:2:aaaa::1') === '2001:db8:1:2::/64' && $ns::cheieIp('2001:db8:1:2:bbbb::9') === '2001:db8:1:2::/64');
$cheieVeche = $privat('cheieVeche');
$db->exec("UPDATE settings SET `value` = '{\"campanie\":0,\"abonat\":" . $abonatiTest[2] . "}' WHERE `key` = 'newsletter_cheie_limita'");
$uita();
$sub = $privat('parseUnsubscribeToken', $tokenCu($abonatiTest[0], 'nltest01@exemplu.ro', $cheieVeche));
$peste = $privat('parseUnsubscribeToken', $tokenCu($abonatiTest[4], 'nltest05@exemplu.ro', $cheieVeche));
$nou = $privat('parseUnsubscribeToken', $tokenCu($abonatiTest[4], 'nltest05@exemplu.ro', $cheie2));
$verifica('A5 tokenul vechi e crezut doar pentru abonații de sub prag (și marcat „vechi”), cel nou pentru oricine, unul falsificat deloc',
    ($sub['vechi'] ?? null) === true && $peste === null && ($nou['vechi'] ?? null) === false
    && $privat('parseUnsubscribeToken', $tokenCu($abonatiTest[0], 'nltest01@exemplu.ro', str_repeat('0', 64))) === null);
$ipPublic = static fn (string $u): ?string => (new ReflectionMethod(\App\Support\NewsletterVerificare::class, 'ipPublic'))->invoke(null, $u);
$neRefuzate = array_filter(['http://127.0.0.1/x', 'http://10.1.2.3/', 'http://192.168.1.1/', 'http://169.254.169.254/latest', 'http://100.64.0.1/',
    'https://8.8.8.8:8443/', 'ftp://8.8.8.8/'], static fn (string $u): bool => $ipPublic($u) !== null);
$verifica('A6 lista de verificare nu cere adrese din rețeaua serverului (SSRF): buclă, private, link-local, CGNAT, alt port, altă schemă',
    $neRefuzate === [] && $ipPublic('http://8.8.8.8/') === '8.8.8.8', implode(', ', $neRefuzate));

// =====================================================================
$scrie('');
$scrie('== B. Trimiterea: o singură dată, încercările, oprirea (codul vechi e2e2d77, 9aae46a)');
$pica = ['email_delivery_method' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => '9', 'smtp_encryption' => 'none'] + $setari;
$c1 = $campanieNoua('Test: serverul refuză');
$r = $ns::sendCampaignNow($db, $c1, $pica, 100, null, 'admin');
$rand = $db->query('SELECT status, last_error FROM newsletter_campaigns WHERE id = ' . $c1)->fetch(PDO::FETCH_ASSOC);
$trimiteri = $db->query('SELECT status, attempts FROM newsletter_campaign_sends WHERE campaign_id = ' . $c1)->fetchAll(PDO::FETCH_ASSOC);
$verifica('B1 după 5 respingeri la rând campania trece în „Oprit”, cu motivul; 5 adrese „failed” cu o încercare, a șasea neatinsă',
    ($r['paused'] ?? false) === true && ($rand['status'] ?? '') === 'paused' && str_contains((string) $rand['last_error'], 'Serverul de email a refuzat 5')
    && count($trimiteri) === 5 && array_unique(array_map(static fn (array $t): string => $t['status'] . '/' . $t['attempts'], $trimiteri)) === ['failed/1'],
    json_encode([$r, $rand, $trimiteri], JSON_UNESCAPED_UNICODE));
$verifica('B2 cronul nu reia singur o campanie oprită (pornire „cron” doar din „sending”), nici o ciornă',
    !empty($ns::sendCampaignNow($db, $c1, $pica, 100, null, 'cron')['nepornit'])
    && !empty($ns::sendCampaignNow($db, $campanieNoua('Test: ciornă'), $setari, 100, null, 'cron')['nepornit']));
$ns::sendCampaignNow($db, $c1, $pica, 100, null, 'admin');
$ns::sendCampaignNow($db, $c1, $pica, 100, null, 'admin');
$incercari = $db->query('SELECT MAX(attempts) FROM newsletter_campaign_sends WHERE campaign_id = ' . $c1)->fetchColumn();
$verifica('B3 o adresă respinsă se reîncearcă de cel mult 3 ori: după 3 treceri nu mai e de servit',
    (int) $incercari === 3 && $ns::remainingCount($db, $c1, [$listaTest]) === 1, 'încercări ' . $incercari . ', rămase ' . $ns::remainingCount($db, $c1, [$listaTest]));
$c2 = $campanieNoua('Test: lacăt ocupat');
$alta = new PDO('mysql:host=localhost;dbname=' . (string) $app['db']['database'] . ';charset=utf8mb4', (string) $app['db']['username'],
    (string) $app['db']['password']);
$alta->query("SELECT GET_LOCK(" . $alta->quote($privat('numeLacat', $db, $c2)) . ', 0)')->fetchColumn();
$ocupat = $ns::sendCampaignNow($db, $c2, $setari, 100, null, 'admin');
$alta->query("SELECT RELEASE_LOCK(" . $alta->quote($privat('numeLacat', $db, $c2)) . ')');
$alta = null;
$mailuriC2 = $mailuriNoi($mailInainte);
$r2 = $ns::sendCampaignNow($db, $c2, $setari, 100, null, 'admin');
$r3 = null;
try {
    $ns::sendCampaignNow($db, $c2, $setari, 100, null, 'admin');
} catch (RuntimeException $e) {
    $r3 = $e->getMessage();
}
$mailuriCampanie = array_values(array_filter(array_diff_key($mailuriNoi($mailInainte), $mailuriC2),
    static fn (string $m): bool => str_contains($m, 'Subiect de test') || str_contains($m, '=?UTF-8?B?')));
$verifica('B4 cât altcineva ține lacătul campaniei, „Trimite acum” nu trimite nimic (busy); apoi fiecare din cei 6 o singură dată, iar a doua '
    . 'apăsare e refuzată („deja trimis”)', !empty($ocupat['busy']) && ($ocupat['sent'] ?? -1) === 0 && ($r2['sent'] ?? 0) === 6
    && ($r2['finished'] ?? false) === true && is_string($r3) && str_contains($r3, 'deja trimis')
    && (int) $unu('SELECT COUNT(*) FROM newsletter_campaign_sends WHERE campaign_id = ' . $c2 . ' AND status = \'sent\' AND attempts = 1') === 6,
    json_encode([$ocupat, $r2, $r3], JSON_UNESCAPED_UNICODE));
// Emailul campaniei: antetele de dezabonare (RFC 8058), linkul personal, clicurile cu „&” decodat.
$emlCampanie = '';
foreach ($mailuriNoi($mailInainte) as $eml) {
    if (str_contains($eml, 'List-Unsubscribe-Post') && str_contains($eml, 'nltest01@exemplu.ro')) {
        $emlCampanie = $eml;
    }
}
$linkDezabonare = preg_match('/^List-Unsubscribe:\s*<([^>]+)>/mi', $emlCampanie, $m) === 1 ? $m[1] : '';
$verifica('B5 newsletterul are List-Unsubscribe (linkul personal, cu adresa completă) și List-Unsubscribe-Post: List-Unsubscribe=One-Click',
    str_starts_with($linkDezabonare, rtrim((string) getenv('APP_URL'), '/') . '/newsletter/unsubscribe/')
    && $antetMail($emlCampanie, 'List-Unsubscribe-Post') === 'List-Unsubscribe=One-Click', mb_substr($emlCampanie, 0, 600));

// =====================================================================
$scrie('');
$scrie('== C. Dubla confirmare și limitele (codul vechi 7123076, f56a3b5, 739f58c)');
$cerereA = static fn (string $email, string $ip) => $ns::cerereAbonare($db, $listaTest, $email, 'Persoană', $ip, $setari)['stare'];
$db->exec('DELETE FROM newsletter_cereri_confirmare');
$p1 = $cerereA('limita-adresa@exemplu.ro', '203.0.113.5');
$p2 = $cerereA('limita-adresa@exemplu.ro', '203.0.113.6');
$verifica('C1 o adresă nouă devine „pending” (nu „active”) și primește emailul; a doua cerere în 5 minute nu mai trimite',
    $p1 === 'trimis' && $p2 === 'limita_adresa' && $unu("SELECT status FROM newsletter_subscribers WHERE email = 'limita-adresa@exemplu.ro'") === 'pending'
    && (int) $unu("SELECT COUNT(*) FROM email_send_history WHERE recipient = 'limita-adresa@exemplu.ro' AND email_type = 'newsletter_confirmare'") === 1);
$scrieCereri = static function (int $cate, ?string $ip, string $tip) use ($db): void {
    $ins = $db->prepare('INSERT INTO newsletter_cereri_confirmare (email_hash, ip, tip, created_at) VALUES (?, ?, ?, NOW())');
    for ($k = 0; $k < $cate; $k++) {
        $ins->execute([hash('sha256', $tip . $k . microtime()), $ip, $tip]);
    }
};
$scrieCereri(20, '2001:db8:1:2::/64', 'abonare');
$verifica('C2 limita pe IP (20 pe oră) numără IPv6 pe rețeaua /64: altă adresă din aceeași /64 e refuzată, una din altă /64 trece',
    $cerereA('ipv6-a@exemplu.ro', '2001:db8:1:2:aaaa::1') === 'limita_ip' && $cerereA('ipv6-b@exemplu.ro', '2001:db8:1:3::1') === 'trimis');
$db->exec('DELETE FROM newsletter_cereri_confirmare');
$db->exec("DELETE FROM settings WHERE `key` = 'newsletter_limita_globala_atinsa'");
$scrieCereri(\App\Support\NewsletterService::LIMITA_GLOBALA_ORA, null, 'abonare');
$global = $cerereA('globala@exemplu.ro', '203.0.113.9');
$verifica('C3 plafonul global (' . \App\Support\NewsletterService::LIMITA_GLOBALA_ORA . ' confirmări pe oră) oprește cererile și se vede în admin '
    . '(newsletter_limita_globala_atinsa)', $global === 'limita_globala' && $unu("SELECT value FROM settings WHERE `key` = 'newsletter_limita_globala_atinsa'") !== '');
$db->exec("UPDATE settings SET `value` = '{\"campanie\":1000000,\"abonat\":1000000}' WHERE `key` = 'newsletter_cheie_limita'");
$uita();
$mailuriInainteLink = $mailuriNoi($mailInainte);
$stareLink = $ns::trimiteLinkDezabonare($db, $tokenCu($abonatiTest[1], 'nltest02@exemplu.ro', $cheieVeche), '203.0.113.10', $setari);
$verifica('C4 emailul „linkul tău de dezabonare” nu intră în plafonul global (nimeni nu poate bloca o dezabonare): pleacă, cu linkul nou',
    $stareLink === 'gata' && (int) $unu("SELECT COUNT(*) FROM email_send_history WHERE recipient = 'nltest02@exemplu.ro' AND email_type = 'newsletter_link_dezabonare'") === 1
    && $unu('SELECT status FROM newsletter_subscribers WHERE id = ' . $abonatiTest[1]) === 'active');
// Curățenia din cron: cererile neconfirmate mai vechi de 30 de zile.
$db->exec("UPDATE newsletter_subscribers SET pending_at = NOW() - INTERVAL 31 DAY WHERE email = 'limita-adresa@exemplu.ro'");
$db->exec("UPDATE newsletter_subscribers SET status = 'unsubscribed', pending_at = NOW() - INTERVAL 31 DAY WHERE id = " . $abonatiTest[5]);
$db->exec("UPDATE newsletter_cereri_confirmare SET created_at = NOW() - INTERVAL 3 DAY LIMIT 5");
$inainteCereri = (int) $unu('SELECT COUNT(*) FROM newsletter_cereri_confirmare');
$sterse = $ns::curataCereriExpirate($db);
$verifica('C5 curățenia: adresa neconfirmată în 30 de zile se șterge (și din liste), dezabonatul rămâne (fără pending_at), jurnalul pierde rândurile de peste 2 zile',
    $sterse >= 1 && $unu("SELECT COUNT(*) FROM newsletter_subscribers WHERE email = 'limita-adresa@exemplu.ro'") === '0'
    && $unu('SELECT CONCAT(status, \'/\', COALESCE(pending_at, \'NULL\')) FROM newsletter_subscribers WHERE id = ' . $abonatiTest[5]) === 'unsubscribed/NULL'
    && (int) $unu('SELECT COUNT(*) FROM newsletter_cereri_confirmare') === $inainteCereri - 5);

// =====================================================================
$scrie('');
$scrie('== D. Abonații: lista „nu trimite”, importurile nu reactivează (codul vechi e2e2d77, 7123076)');
$ns::suprima($db, 'Sters.Test@Exemplu.ro ');
$refuz = '';
try {
    $ns::subscribeToList($db, $listaTest, 'sters.test@exemplu.ro', 'X');
} catch (\App\Support\AdresaSuprimata $e) {
    $refuz = $e->getMessage();
}
$verifica('D1 lista „nu trimite” ține doar amprenta adresei normalizate; adăugarea din admin sau din import e refuzată (AdresaSuprimata)',
    $refuz !== '' && $unu("SELECT motiv FROM newsletter_suprimate WHERE email_hash = '" . hash('sha256', 'sters.test@exemplu.ro') . "'") === 'stergere_admin'
    && (int) $unu("SELECT COUNT(*) FROM newsletter_suprimate WHERE email_hash LIKE '%@%'") === 0);
$ns::subscribeToList($db, $listaTest, 'nltest06@exemplu.ro', '');
$verifica('D2 un import (adăugare fără consimțământ nou) nu reactivează un dezabonat și nu-i șterge numele',
    $unu('SELECT CONCAT(status, \'/\', name) FROM newsletter_subscribers WHERE id = ' . $abonatiTest[5]) === 'unsubscribed/Test 6');

// =====================================================================
$scrie('');
$scrie('== E. Pe site: dezabonarea, clicurile, formularul, pluginul oprit');
$verifica('E1 logarea adminului', $logareAdmin());
$cheieAcum = $privat('cheieSemnare');
$tokenNou = $tokenCu($abonatiTest[2], 'nltest03@exemplu.ro', $cheieAcum);
$get = $cerere('GET', '/newsletter/unsubscribe/' . rawurlencode($tokenNou));
$verifica('E2 GET pe linkul de dezabonare arată doar butonul „Confirm dezabonarea” (noindex) și nu dezabonează',
    $get['cod'] === 200 && str_contains($get['corp'], 'Confirm dezabonarea</button>') && ($get['antete']['x-robots-tag'] ?? '') === 'noindex'
    && $unu('SELECT status FROM newsletter_subscribers WHERE id = ' . $abonatiTest[2]) === 'active');
$unClic = $cerere('POST', $linkDezabonare, null, ['List-Unsubscribe' => 'One-Click']);
$verifica('E3 POST-ul „cu un clic” al clientului de email, la linkul din List-Unsubscribe, dezabonează (text simplu, 200)',
    $unClic['cod'] === 200 && trim($unClic['corp']) === 'Dezabonat' && $unu('SELECT status FROM newsletter_subscribers WHERE id = ' . $abonatiTest[0]) === 'unsubscribed');
$vechiPost = $cerere('POST', '/newsletter/unsubscribe/' . rawurlencode($tokenCu($abonatiTest[3], 'nltest04@exemplu.ro', $cheieVeche)), null, ['x' => '1']);
$verifica('E4 un POST direct cu tokenul vechi e refuzat (403) și nu dezabonează', $vechiPost['cod'] === 403
    && $unu('SELECT status FROM newsletter_subscribers WHERE id = ' . $abonatiTest[3]) === 'active');
// Clicurile: semnate cu cheia nouă duc mai departe (cu „&” decodat) și pun cookie-ul de atribuire; cu cheia veche doar sub prag.
$c3 = $campanieNoua('Test: clicuri');
$url = 'https://exemplu.ro/x?a=1&amp;b=2';
$semn = static fn (string $cheie, int $camp, string $u): string => $b64(hash_hmac('sha256', 'click|' . $camp . '|' . $abonatiTest[4] . '|' . $u, $cheie, true));
$clicNou = $cerere('GET', '/newsletter/track/click/' . $c3 . '/' . $abonatiTest[4] . '/' . $semn($cheieAcum, $c3, $url) . '?url=' . rawurlencode($url));
$db->exec("UPDATE settings SET `value` = '{\"campanie\":0,\"abonat\":1000000}' WHERE `key` = 'newsletter_cheie_limita'");
$clicVechi = $cerere('GET', '/newsletter/track/click/' . $c3 . '/' . $abonatiTest[4] . '/' . $semn($cheieVeche, $c3, $url) . '?url=' . rawurlencode($url));
$verifica('E5 clicul semnat cu cheia nouă duce la adresă („&amp;” decodat) cu cookie-ul nl_cid; semnat cu cheia veche pentru o campanie de '
    . 'după prag: prima pagină, fără cookie', $clicNou['cod'] === 302 && ($clicNou['antete']['location'] ?? '') === 'https://exemplu.ro/x?a=1&b=2'
    && str_contains((string) ($clicNou['antete']['set-cookie'] ?? ''), 'nl_cid=' . $c3) && $clicVechi['cod'] === 302
    && rtrim((string) ($clicVechi['antete']['location'] ?? ''), '/') === rtrim((string) getenv('APP_URL'), '/')
    && !str_contains((string) ($clicVechi['antete']['set-cookie'] ?? ''), 'nl_cid'), json_encode([$clicNou['antete'], $clicVechi['antete']]));
// Formularul public: cereri în paralel pentru aceeași adresă trimit un singur email (rezervarea sub lacăt).
$db->prepare('INSERT INTO newsletter_optin_forms (name, slug, list_id, fields_json, is_active) VALUES (?, ?, ?, ?, 1)')
    ->execute(['Formularul testului', 'formular-test-nl', $listaTest, json_encode([['type' => 'email', 'name' => 'email', 'required' => 1]])]);
$db->exec('DELETE FROM newsletter_cereri_confirmare');
$multi = curl_multi_init();
$manere = [];
for ($k = 0; $k < 12; $k++) {
    $h = curl_init('http://127.0.0.1:' . $port . '/newsletter/optin/formular-test-nl');
    curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['email' => 'paralel@exemplu.ro', 'response' => 'json']),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_TIMEOUT => 30]);
    curl_multi_add_handle($multi, $h);
    $manere[] = $h;
}
do {
    curl_multi_exec($multi, $active);
    curl_multi_select($multi, 0.2);
} while ($active);
$coduri = array_map(static fn ($h): int => (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE), $manere);
foreach ($manere as $h) {
    curl_multi_remove_handle($multi, $h);
    curl_close($h);
}
curl_multi_close($multi);
$verifica('E6 12 cereri de abonare în paralel pentru aceeași adresă: un singur email de confirmare și un singur rând în jurnal (lacătul)',
    (int) $unu("SELECT COUNT(*) FROM email_send_history WHERE recipient = 'paralel@exemplu.ro' AND email_type = 'newsletter_confirmare'") === 1
    && (int) $unu('SELECT COUNT(*) FROM newsletter_cereri_confirmare') === 1 && array_diff($coduri, [200]) === [], implode(',', $coduri));
// Pluginul oprit: doar linkurile din newsletterele trimise mai au răspuns.
$licenta('toate', 'newsletter=oprit');
$tokenOprit = $tokenCu($abonatiTest[3], 'nltest04@exemplu.ro', $cheieAcum);
$gresit = $cerere('GET', '/newsletter/unsubscribe/nu-e-bun.zzz');
$bun = $cerere('POST', '/newsletter/unsubscribe/' . rawurlencode($tokenOprit), null, ['List-Unsubscribe' => 'One-Click']);
$confirmare = $cerere('GET', '/newsletter/confirmare/ceva.zzz');
$verificare = $cerere('POST', '/admin/emails/builder/verificare', $jarAdmin, ['subject' => 'x', 'list_ids' => (string) $listaTest]);
$licenta('toate', 'catalog=oprit', 'magazin=oprit');
$builderFaraCatalog = $cerere('GET', '/admin/emails/builder?type=campaign&id=' . $c3, $jarAdmin);
$licenta('toate');
$builderCuCatalog = $cerere('GET', '/admin/emails/builder?type=campaign&id=' . $c3, $jarAdmin);
$verifica('E8 șabloanele newsletterului fără catalog pe site: niciun card de produs, butonul duce la prima pagină („Vezi site-ul”); cu catalogul, '
    . 'cardurile cu „Vezi produsul”', $builderFaraCatalog['cod'] === 200 && !str_contains($builderFaraCatalog['corp'], 'Vezi produsul')
    && str_contains($builderFaraCatalog['corp'], 'Vezi site-ul') && str_contains($builderCuCatalog['corp'], 'Vezi produsul'),
    $builderFaraCatalog['cod'] . ' ' . $builderCuCatalog['cod']);
$verifica('E7 newsletterul oprit: un link nevalid dă pagina 404 a site-ului, dezabonarea „cu un clic” merge (mereu deschisă), confirmarea '
    . 'abonării și lista de verificare din admin nu (garda)', $gresit['cod'] === 404 && !isset($gresit['antete']['x-andaxi-garda'])
    && $bun['cod'] === 200 && trim($bun['corp']) === 'Dezabonat' && $confirmare['cod'] === 404 && isset($confirmare['antete']['x-andaxi-garda'])
    && $verificare['cod'] === 403, json_encode([$gresit['cod'], $bun['cod'], $confirmare['cod'], $verificare['cod']]));

// =====================================================================
$scrie('');
$scrie('== Final');
$puneLaLoc();
$verifica('baza pusă la loc: CHECKSUM TABLE identic pe fiecare tabel', $sumeBaza() === $sumeInainte,
    implode(', ', array_keys(array_diff_assoc($sumeBaza(), $sumeInainte))));
$eroriNoi = is_file($fisierErori) ? substr((string) file_get_contents($fisierErori), $pozitieErori) : '';
$verifica('nicio eroare PHP nouă în jurnal', preg_match_all('/PHP (Warning|Notice|Fatal error|Parse error)/', $eroriNoi) === 0, mb_substr($eroriNoi, 0, 1500));
$scrie('');
$scrie('Rezultat: ' . $trecute . ' verificări trecute, ' . $esuate . ' eșuate. Raport: ' . $fisierRaport);
exit($esuate === 0 ? 0 : 1);
