<?php

declare(strict_types=1);

/**
 * Testele newsletterului portat din codul vechi în WP-R din 30 oct 2026 (Bioscem e2e2d77 … 739f58c; codul vechi n-avea teste
 * scrise, doar verificările descrise în mesajele commit-urilor, refăcute aici), cu serverul de dezvoltare, pe baza de test.
 * Ce se vede în pagini și ce rămâne în bază după fiecare flux public sau din admin, față de codul Bioscem însuși, îl dovedește
 * comparația (tools/dev/compara-cu-baza.sh, cazurile „newsletter: …”); aici, ce nu se vede acolo:
 *
 *   tools/dev/php.sh tools/dev/test-newsletter.php
 *   tools/dev/php.sh tools/dev/test-newsletter.php --fara-browser
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
 * F. Statistici newsletter, comenzile atribuite unei campanii (20 nov 2026): aceeași condiție ca totalul din card, cele mai noi
 *    întâi, paginile, linkul spre comandă (și spre coș), etichetele listei comenzilor, textul scris ca text; fără comenzi nimic
 *    nou; rolul emailuri și un site fără pluginul magazin nu văd panoul (nici datele clienților).
 * H. Builderul „C – clasic, curățat” (26 nov 2026), pe server: emailurile făcute cu builderul de dinainte (fiecare tip vechi de bloc,
 *    campaniile și șabloanele demo) ies octet cu octet ca la codul a4e8547 (NewsletterService de atunci, din git, sub alt nume), în
 *    afara subsolului; subsolul cu datele firmei, linkul de dezabonare și politica de confidențialitate; blocurile noi (logo, meniu,
 *    cod, produse); produsele „Automat” față de interogări scrise separat; recomandările pe abonat la o trimitere adevărată; curățarea
 *    textului; ruta produselor; încărcarea pozelor din builder (CSRF, doar poze); paleta; Setări site → Datele firmei.
 * G. Builderul în Chromium (tools/dev/newsletter-builder-browser.cjs), pe un newsletter nou, nesalvat: Desktop → Mobil → Editez →
 *    Desktop → Mobil → Desktop arată de fiecare dată emailul (680 / 375 px), într-un cadru izolat nou; înălțimea e a emailului; doar
 *    ultimul clic se pune; o eroare se spune; titlul pus într-o coloană se vede, cu eticheta lui; pânza e lipită și de aceeași înălțime
 *    ca emailul randat; scrisul pe pânză (bara de text, lipirea) și codul din panou care nu rulează; pozele trase și lipite; Anulează /
 *    Refă; tastatura; salvarea automată; 1366 px; „Alege un șablon” (G14–G19): miniaturile adevărate, tastatura, previzualizarea Desktop /
 *    Mobil, întrebarea peste un email cu conținut, Anulează, aceleași blocuri ca înainte, 390 px. Fără Chromium: --fara-browser.
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

$faraBrowser = in_array('--fara-browser', $argv, true);
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
// nl_cid e un cookie de statistici (bannerul de cookie-uri pe categorii): doar cu acordul pentru ele.
$clicFaraAcord = $cerere('GET', '/newsletter/track/click/' . $c3 . '/' . $abonatiTest[4] . '/' . $semn($cheieAcum, $c3, $url) . '?url=' . rawurlencode($url));
$clicNou = $cerere('GET', '/newsletter/track/click/' . $c3 . '/' . $abonatiTest[4] . '/' . $semn($cheieAcum, $c3, $url) . '?url=' . rawurlencode($url), null, [],
    ['Cookie: ' . \App\Support\CookieConsent::nume() . '=' . \App\Support\CookieConsent::valoare(['statistici'])]);
$db->exec("UPDATE settings SET `value` = '{\"campanie\":0,\"abonat\":1000000}' WHERE `key` = 'newsletter_cheie_limita'");
$clicVechi = $cerere('GET', '/newsletter/track/click/' . $c3 . '/' . $abonatiTest[4] . '/' . $semn($cheieVeche, $c3, $url) . '?url=' . rawurlencode($url));
$verifica('E5 clicul semnat cu cheia nouă duce la adresă („&amp;” decodat) cu cookie-ul nl_cid (cu acordul pentru statistici; fără el, '
    . 'aceeași adresă, fără cookie); semnat cu cheia veche pentru o campanie de după prag: prima pagină, fără cookie', $clicNou['cod'] === 302
    && $clicFaraAcord['cod'] === 302 && ($clicFaraAcord['antete']['location'] ?? '') === 'https://exemplu.ro/x?a=1&b=2'
    && !str_contains((string) ($clicFaraAcord['antete']['set-cookie'] ?? ''), 'nl_cid') && ($clicNou['antete']['location'] ?? '') === 'https://exemplu.ro/x?a=1&b=2'
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
$scrie('== F. Statistici newsletter: comenzile atribuite unei campanii (20 nov 2026)');
// O campanie trimisă și 28 de comenzi demo atribuite ei: una anulată și una cu plata neconfirmată (nu se numără), una mutată în
// coș (se numără, ca în card), una cu un nume și o adresă care ar strica pagina dacă n-ar fi scrise ca text.
$cF = $campanieNoua('Test: comenzi atribuite');
$db->exec("UPDATE newsletter_campaigns SET status = 'sent', sent_at = NOW() WHERE id = " . $cF);
$cGoala = $campanieNoua('Test: fără comenzi');
$db->exec("UPDATE newsletter_campaigns SET status = 'sent', sent_at = NOW() WHERE id = " . $cGoala);
$idsF = array_map('intval', $db->query('SELECT id FROM orders WHERE deleted_at IS NULL ORDER BY id LIMIT 28')->fetchAll(PDO::FETCH_COLUMN));
$db->exec("UPDATE orders SET nl_campaign_id = " . $cF . ", status = 'completed' WHERE id IN (" . implode(',', $idsF) . ')');
$db->exec("UPDATE orders SET status = 'processing' WHERE id = " . $idsF[4]);
$db->exec("UPDATE orders SET status = 'pending' WHERE id = " . $idsF[5]);
$db->exec("UPDATE orders SET status = 'cancelled' WHERE id = " . $idsF[0]);
$db->exec("UPDATE orders SET status = 'pending_payment' WHERE id = " . $idsF[1]);
$db->exec('UPDATE orders SET deleted_at = NOW() WHERE id = ' . $idsF[2]);
$db->prepare('UPDATE orders SET billing_first_name = :n, billing_last_name = :p, billing_email = :e WHERE id = :id')
    ->execute(['n' => '<img src=x onerror=alert(1)>', 'p' => 'Proba', 'e' => 'ana&"proba"@exemplu.ro', 'id' => $idsF[3]]);
$undeF = 'o.nl_campaign_id = :c AND o.status NOT IN ("cancelled","refunded","failed","returned","trash","pending_payment")';
$asteptateF = $db->prepare('SELECT o.order_number FROM orders o WHERE ' . $undeF . ' ORDER BY o.created_at DESC, o.id DESC');
$asteptateF->execute(['c' => $cF]);
$asteptateF = array_map('strval', $asteptateF->fetchAll(PDO::FETCH_COLUMN));
$nrInCos = $unu('SELECT order_number FROM orders WHERE id = ' . $idsF[2]);
$numereExcluse = [$unu('SELECT order_number FROM orders WHERE id = ' . $idsF[0]), $unu('SELECT order_number FROM orders WHERE id = ' . $idsF[1])];
$adresaF = '/admin/emails/newsletters?tab=stats&campaign_id=' . $cF;
/** Numerele de comandă din panou, în ordine, și linkurile lor. */
$randuriF = static function (string $html): array {
    preg_match_all('#<td class="ac-nr">\s*<a class="nl-stats-ancora" href="([^"]*)">([^<]*)</a>#', $html, $m);
    return ['numere' => array_map(static fn (string $v): string => html_entity_decode($v, ENT_QUOTES), $m[2]), 'linkuri' => $m[1]];
};
$verifica('F1 logarea adminului', $logareAdmin());
$p1 = $cerere('GET', $adresaF, $jarAdmin);
$p2 = $cerere('GET', $adresaF . '&com_page=2', $jarAdmin);
$r1 = $randuriF($p1['corp']);
$r2 = $randuriF($p2['corp']);
$verifica('F2 cardul „Venituri atribuite”: „N comenzi” e o ancoră spre panoul „Comenzile atribuite (N)”, cu N numărat cu aceeași condiție '
    . '(fără cea anulată și cea cu plata neconfirmată, cu cea din coș)', $p1['cod'] === 200 && count($asteptateF) === 26
    && str_contains($p1['corp'], '<a class="nl-stats-ancora" href="#nl-comenzi-panel">26 comenzi</a>')
    && str_contains($p1['corp'], '<div class="nl-stats-panel" id="nl-comenzi-panel">')
    && str_contains($p1['corp'], 'Comenzile atribuite (26)</p>'), $p1['cod'] . ' ' . count($asteptateF));
$verifica('F3 lista: cele mai noi întâi, 25 pe pagină, a doua pagină cu restul (com_page=2, ancora panoului), aceleași comenzi ca în card',
    count($r1['numere']) === 25 && count($r2['numere']) === 1 && array_merge($r1['numere'], $r2['numere']) === $asteptateF
    && str_contains($p1['corp'], 'Pagina 1 din 2 — 26 comenzi') && str_contains($p1['corp'], 'com_page=2#nl-comenzi-panel')
    && array_intersect($numereExcluse, array_merge($r1['numere'], $r2['numere'])) === [],
    json_encode([count($r1['numere']), count($r2['numere'])]));
$toateLinkurile = array_combine(array_merge($r1['numere'], $r2['numere']), array_merge($r1['linkuri'], $r2['linkuri']));
$nrObisnuit = $asteptateF[0] === $nrInCos ? $asteptateF[1] : $asteptateF[0];
$verifica('F4 numărul duce la comanda din Admin → Comenzi (căutată după număr); cea mutată în coș, la coș, cu eticheta „în coș”',
    ($toateLinkurile[$nrObisnuit] ?? '') === '/admin/orders?q=' . rawurlencode($nrObisnuit)
    && ($toateLinkurile[$nrInCos] ?? '') === '/admin/orders/trash' && str_contains($p1['corp'] . $p2['corp'], 'title="Mutată în coș din Admin → Comenzi">în coș</span>'),
    json_encode([$nrObisnuit => $toateLinkurile[$nrObisnuit] ?? null, $nrInCos => $toateLinkurile[$nrInCos] ?? null]));
// Rândul unei comenzi: data, clientul (ca în lista comenzilor), totalul, starea și plata cu etichetele listei comenzilor.
$randRef = $db->query('SELECT order_number, created_at, billing_first_name, billing_last_name, billing_email, total, status, payment_method
    FROM orders WHERE id = ' . $idsF[5])->fetch(PDO::FETCH_ASSOC);
$etichetaPlata = ['cod' => 'Ramburs', 'euplatesc' => 'Card', 'stripe' => 'Card', 'btipay' => 'Card BT', 'card' => 'Card', 'bank_transfer' => 'OP'];
$etichetaStare = ['pending' => 'În așteptare', 'processing' => 'În procesare', 'completed' => 'Finalizată'];
$htmlRand = '';
foreach ([$p1['corp'], $p2['corp']] as $corpF) {
    if (preg_match('#<tr>\s*<td class="ac-nr">\s*<a class="nl-stats-ancora" href="[^"]*">' . preg_quote(htmlspecialchars((string) $randRef['order_number'], ENT_QUOTES), '#')
        . '</a>.*?</tr>#s', $corpF, $mr) === 1) {
        $htmlRand = $mr[0];
    }
}
$verifica('F5 un rând: data și ora plasării, clientul și adresa lui, totalul, starea și plata cu etichetele din lista comenzilor',
    $htmlRand !== '' && str_contains($htmlRand, '<td class="ac-data">' . date('d.m.Y H:i', (int) strtotime((string) $randRef['created_at'])) . '</td>')
    && str_contains($htmlRand, '<strong>' . htmlspecialchars(\App\Support\CustomerDisplay::nume((string) $randRef['billing_first_name'],
        (string) $randRef['billing_last_name'], (string) $randRef['billing_email']), ENT_QUOTES) . '</strong>')
    && str_contains($htmlRand, '<span class="nl-stats-mic">' . htmlspecialchars((string) $randRef['billing_email'], ENT_QUOTES) . '</span>')
    && str_contains($htmlRand, '<strong>' . number_format((float) $randRef['total'], 2) . ' RON</strong>')
    && str_contains($htmlRand, '>' . ($etichetaStare[(string) $randRef['status']] ?? '?') . '</span>')
    && str_contains($htmlRand, '<td class="ac-plata">' . ($etichetaPlata[strtolower((string) $randRef['payment_method'])] ?? '?') . '</td>'),
    mb_substr(strip_tags($htmlRand), 0, 400));
$verifica('F6 numele și adresa clientului sunt scrise ca text (niciun <img> din numele comenzii)',
    !str_contains($p1['corp'] . $p2['corp'], '<img src=x onerror') && str_contains($p1['corp'] . $p2['corp'], '&lt;img src=x onerror=alert(1)&gt; Proba')
    && str_contains($p1['corp'] . $p2['corp'], 'ana&amp;&quot;proba&quot;@exemplu.ro'));
$goala = $cerere('GET', '/admin/emails/newsletters?tab=stats&campaign_id=' . $cGoala, $jarAdmin);
$verifica('F7 o campanie fără comenzi atribuite: cardul fără „comenzi” și fără panou (pagina ca înainte)', $goala['cod'] === 200
    && str_contains($goala['corp'], '<div class="nl-kpi-sub"></div>') && !str_contains($goala['corp'], 'nl-comenzi-panel'));
// Rolul emailuri vede statisticile newsletterului, dar nu și Admin → Comenzi: nici lista, nici datele clienților.
$jarEmailuri = $dosarLucru . '/jar-emailuri.txt';
$logareEmailuri = $cerere('POST', '/admin/login', $jarEmailuri, ['email' => 'emailuri@exemplu.ro', 'password' => $parolaAdmin]);
$rolEmailuri = $cerere('GET', $adresaF, $jarEmailuri);
$comenziEmailuri = $cerere('GET', '/admin/orders', $jarEmailuri);
$emailuriClienti = $db->query('SELECT DISTINCT billing_email FROM orders WHERE nl_campaign_id = ' . $cF)->fetchAll(PDO::FETCH_COLUMN);
$scapate = array_values(array_filter($emailuriClienti, static fn ($e): bool => str_contains($rolEmailuri['corp'], htmlspecialchars((string) $e, ENT_QUOTES))));
$verifica('F8 rolul emailuri (fără Admin → Comenzi): vede cardul cu „26 comenzi” ca text, fără ancoră, fără panou și fără nicio adresă de client',
    $logareEmailuri['cod'] === 302 && $comenziEmailuri['cod'] === 302 && $rolEmailuri['cod'] === 200
    && str_contains($rolEmailuri['corp'], '<div class="nl-kpi-sub">26 comenzi</div>') && !str_contains($rolEmailuri['corp'], 'nl-comenzi-panel')
    && $scapate === [], json_encode([$logareEmailuri['cod'], $comenziEmailuri['cod'], $rolEmailuri['cod'], $scapate]));
// Fără pluginul magazin, Admin → Comenzi nu există: nici panoul (garda paginii comenzilor, Plugins::adresaVizibila).
$licenta('toate', 'magazin=oprit');
$faraMagazin = $cerere('GET', $adresaF, $jarAdmin);
$licenta('toate');
$verifica('F9 fără pluginul magazin (fără Admin → Comenzi): cardul ca text, fără panou', $faraMagazin['cod'] === 200
    && str_contains($faraMagazin['corp'], '<div class="nl-kpi-sub">26 comenzi</div>') && !str_contains($faraMagazin['corp'], 'nl-comenzi-panel'),
    (string) $faraMagazin['cod']);

// =====================================================================
$scrie('');
$scrie('== H. Builderul „C – clasic, curățat” pe server: emailurile vechi, blocurile noi, produsele, subsolul, încărcarea (26 nov 2026)');
/** Partea HTML a unui email capturat: corpul după antete (mail()) sau partea base64 din MIME (OrderMailer, SMTP). */
$corpMail = static function (string $eml): string {
    $parti = preg_split("/\r?\n\r?\n/", $eml, 2);
    if (str_contains((string) ($parti[1] ?? ''), '<body')) {
        return (string) $parti[1];
    }
    preg_match_all('/Content-Transfer-Encoding: base64\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+)/', $eml, $m);
    foreach ($m[1] as $bucata) {
        $html = (string) base64_decode(preg_replace('/\s+/', '', $bucata), true);
        if (str_contains($html, '<body')) {
            return $html;
        }
    }
    return '';
};
$faraSubsol = static fn (string $h): string => (string) preg_replace('/<!-- newsletter-unsubscribe-footer:start -->.*?<!-- newsletter-unsubscribe-footer:end -->/s', '', $h);
// Codul de dinainte de builderul nou (a4e8547, linia comună): NewsletterService de atunci, sub alt nume, în același proces.
$bazaBuilder = 'a4e8547';
$codVechi = (string) shell_exec('git -C ' . escapeshellarg($radacina) . ' show ' . $bazaBuilder . ':src/Support/NewsletterService.php 2>/dev/null');
$areVechi = str_contains($codVechi, 'final class NewsletterService');
if ($areVechi) {
    $fisierVechi = $dosarLucru . '/NewsletterServiceBaza.php';
    // __DIR__ (config/app.php) e al codului de acum: fișierul stă în dosarul testului.
    file_put_contents($fisierVechi, str_replace(['final class NewsletterService', '__DIR__'],
        ['final class NewsletterServiceBaza', var_export($radacina . '/src/Support', true)], $codVechi));
    require_once $fisierVechi;
}
$vechi = '\\App\\Support\\NewsletterServiceBaza';
// Emailuri făcute cu builderul de dinainte: fiecare tip vechi de bloc, cu formele vechi ale coloanelor, textele cu etichetele
// editorului, linkuri markdown și adrese scrise direct, fonturi, fundaluri cu imagine, ascunderea pe dispozitive, blocuri fără chei.
$blocuriVechi = [
    [['type' => 'header', 'content' => "Ofertă <b>specială</b>\nla ceaiuri", 'align' => 'center', 'font_size' => 32, 'font_size_mobile' => 24,
        'background' => '#2d7d5f', 'text_color' => '#ffffff', 'font_family' => 'georgia', 'padding_top' => 20, 'padding_bottom' => 20],
     ['type' => 'text', 'content' => "Salut <i>tuturor</i> & <u>bine</u> ați venit!\n[Vezi oferta](https://exemplu.ro/oferta) sau www.exemplu.ro/x\n<s>vechi</s> <strong>nou</strong> <em>e</em>",
        'align' => 'left', 'font_size' => 16, 'font_size_mobile' => 14, 'background' => '#ffffff', 'text_color' => '#333333', 'padding_left' => 28, 'padding_right' => 28],
     ['type' => 'image', 'image_url' => '/uploads/demo/ceai-de-menta.png', 'alt' => 'Ceai', 'width' => 80, 'link_url' => '/magazin', 'border_radius' => 6],
     ['type' => 'button', 'label' => 'Cumpără', 'url' => '/magazin', 'button_color' => '#dc2626', 'btn_pad_v' => 12, 'btn_pad_h' => 30, 'radius' => 4],
     ['type' => 'divider', 'line_color' => '#cccccc', 'width' => 60, 'thickness' => 2, 'padding_top' => 8],
     ['type' => 'spacer', 'height' => 18, 'background' => '#f9fafb', 'hide_mobile' => 1],
     ['type' => 'quote', 'content' => "Cel mai bun <b>ceai</b>\nde până acum", 'author' => 'Ana', 'hide_desktop' => 1],
     ['type' => 'social', 'links' => [['platform' => 'facebook', 'url' => 'https://facebook.com/x'], ['platform' => 'necunoscut', 'url' => '']], 'icon_size' => 28],
     ['type' => 'video', 'video_url' => 'https://www.youtube.com/watch?v=abc', 'thumbnail_url' => '/uploads/video-thumbs/x.jpg', 'title' => 'Clip', 'label' => '▶ Vezi'],
     ['type' => 'text', 'content' => 'Fond', 'bg_image' => '/uploads/demo/ceai-de-menta.png', 'bg_size' => 'contain', 'hide_tablet' => 1]],
    [['type' => 'columns_2', 'background' => '#f0fdf4', 'padding_left' => 12, 'columns' => [
        [['type' => 'header', 'content' => 'Stânga', 'background' => '', 'text_color' => '#1f2937', 'align' => 'left'], ['type' => 'image', 'image_url' => '/uploads/demo/ceai-de-tei.png']],
        [['type' => 'button', 'label' => 'Dreapta', 'url' => 'https://exemplu.ro'], ['type' => 'spacer', 'height' => 8]]]],
     ['type' => 'columns_3', 'columns_content' => ['unu', ['doi', 'trei'], ['type' => 'text', 'content' => 'patru']]],
     ['type' => 'columns_2_6040', 'content_columns' => [[['type' => 'text', 'content' => 'Șaizeci']], []]],
     ['type' => 'columns_2_3070', 'columns' => [[], [['type' => 'divider']]]],
     ['type' => 'necunoscut', 'content' => 'devine text'],
     ['content' => 'fără tip'],
     'nu e bloc'],
    [],
];
$campaniiDemo = $db->query("SELECT subject, blocks_json FROM newsletter_campaigns WHERE blocks_json IS NOT NULL AND blocks_json NOT IN ('', '[]')
    UNION ALL SELECT subject, blocks_json FROM newsletter_templates WHERE blocks_json IS NOT NULL AND blocks_json NOT IN ('', '[]')")->fetchAll(PDO::FETCH_ASSOC);
// Doar cele făcute numai din tipurile de dinainte (o campanie făcută între timp cu blocurile noi nu e „veche”).
$tipuriVechi = ['header', 'text', 'image', 'video', 'button', 'quote', 'social', 'divider', 'spacer', 'columns_2', 'columns_2_6040', 'columns_2_4060',
    'columns_2_7030', 'columns_2_3070', 'columns_3'];
$doarVechi = static function (mixed $lista) use (&$doarVechi, $tipuriVechi): bool {
    foreach (is_array($lista) ? $lista : [] as $bloc) {
        if (is_array($bloc) && isset($bloc['type']) && (!in_array($bloc['type'], $tipuriVechi, true)
            || (isset($bloc['columns']) && is_array($bloc['columns']) && !$doarVechi(array_merge(...array_values(array_filter($bloc['columns'], 'is_array'))))))) {
            return false;
        }
    }
    return true;
};
foreach ($campaniiDemo as $rand) {
    $blocuriDemo = json_decode((string) $rand['blocks_json'], true) ?: [];
    if ($doarVechi($blocuriDemo)) {
        $blocuriVechi[] = $blocuriDemo;
    }
}
$identice = 0;
$diferite = [];
foreach ($blocuriVechi as $k => $blocuri) {
    if (!$areVechi) {
        break;
    }
    $cuSubsolV = $vechi::renderHtmlFromBlocks($blocuri, 'Subiect ' . $k);
    $cuSubsolN = $ns::renderHtmlFromBlocks($blocuri, 'Subiect ' . $k);
    $faraV = $vechi::renderHtmlFromBlocks($blocuri, 'Subiect ' . $k, false);
    $faraN = $ns::renderHtmlFromBlocks($blocuri, 'Subiect ' . $k, false);
    $normV = json_encode($vechi::normalizeBlocks($blocuri));
    $normN = json_encode($ns::normalizeBlocks($blocuri));
    if ($faraSubsol($cuSubsolV) === $faraSubsol($cuSubsolN) && $faraV === $faraN && $normV === $normN
        && substr_count($cuSubsolN, 'newsletter-unsubscribe-footer:start') === 1) {
        $identice++;
    } else {
        $diferite[] = $k;
    }
}
$verifica('H1 emailurile făcute cu builderul de dinainte (' . count($blocuriVechi) . ': fiecare tip vechi de bloc, coloanele în formele vechi, '
    . 'campaniile și șabloanele demo) ies octet cu octet ca la codul ' . $bazaBuilder . ': HTML-ul fără subsol, emailul de comandă (fără subsol) și '
    . 'blocurile normalizate; doar subsolul e altul', $areVechi && $diferite === [] && $identice === count($blocuriVechi),
    $areVechi ? 'diferite: ' . json_encode($diferite) : 'nu găsesc codul ' . $bazaBuilder . ' în git');
// Subsolul: datele firmei din setări (inventate), linkul de dezabonare personal, politica de confidențialitate doar spre o pagină publicată.
$setare('gdpr_operator_nume', 'Firma Demo SRL');
$setare('gdpr_operator_sediu', 'Str. Exemplu 10, Cluj-Napoca');
$setare('gdpr_operator_regcom', 'J12/0000/2020');
$setare('gdpr_operator_cui', 'RO00000000');
$setare('account_gdpr_url', '/politica-de-confidentialitate');
$ns::uitaDateSubsol();
$paginaPolitica = (int) $unu("SELECT COUNT(*) FROM pages WHERE slug = 'politica-de-confidentialitate' AND is_published = 1 AND deleted_at IS NULL");
$linkTest = rtrim((string) getenv('APP_URL'), '/') . '/newsletter/unsubscribe/TOKEN-TEST';
$subsol = $ns::withNewsletterFooter('<html><body><p>x</p></body></html>', $linkTest);
$verifica('H2 subsolul automat: denumirea, sediul, Reg. Com., CUI-ul, de ce primește omul emailul, butonul „Dezabonează-mă” cu linkul '
    . 'personal (neschimbat), politica de confidențialitate spre pagina publicată, între semnele de până acum', $paginaPolitica === 1
    && str_contains($subsol, '<!-- newsletter-unsubscribe-footer:start -->') && str_contains($subsol, '<!-- newsletter-unsubscribe-footer:end -->')
    && str_contains($subsol, '<b style="color:#334155;">Firma Demo SRL</b> &middot; Str. Exemplu 10, Cluj-Napoca &middot; Reg. Com. J12/0000/2020 &middot; CUI RO00000000')
    && str_contains($subsol, 'Primești acest email pentru că adresa ta e abonată la newsletterul')
    && str_contains($subsol, '<a href="' . htmlspecialchars($linkTest, ENT_QUOTES) . '" style="display:inline-block;padding:8px 14px;border:1px solid #cbd5e1;border-radius:999px;background:#ffffff;color:#334155;font-weight:600;text-decoration:none;">Dezabonează-mă</a>')
    && str_contains($subsol, 'href="' . rtrim((string) getenv('APP_URL'), '/') . '/politica-de-confidentialitate"'), $subsol);
$setare('account_gdpr_url', '/pagina-care-nu-exista');
$setare('gdpr_operator_cui', '');
$ns::uitaDateSubsol();
$subsolTest = $ns::withNewsletterFooter('<html><body><p>x</p></body></html>', '#test', true);
$lipsa = $ns::dateSubsol()['lipsa'];
$verifica('H3 fără pagina politicii nu apare linkul (nu un link spre 404), un câmp gol lipsește din subsol și e numit în avertisment; emailul '
    . 'de test: „Email de test – linkul de dezabonare este inactiv.”, fără buton', !str_contains($subsolTest, 'Politica de confidențialitate')
    && !str_contains($subsolTest, 'CUI') && str_contains($subsolTest, 'Email de test – linkul de dezabonare este inactiv.')
    && !str_contains($subsolTest, 'Dezabonează-mă') && $lipsa === ['CUI-ul'], json_encode($lipsa, JSON_UNESCAPED_UNICODE));
$setare('gdpr_operator_cui', 'RO00000000');
$setare('account_gdpr_url', '/politica-de-confidentialitate');
$ns::uitaDateSubsol();
// Blocurile noi: logo, meniu, cod de reducere, produse alese, în HTML de email (stiluri pe elemente, tabele pentru Outlook).
$produseDemo = $db->query("SELECT id, name, slug, price, image_url FROM products WHERE deleted_at IS NULL AND is_active = 1
    AND image_url LIKE '/uploads/%' ORDER BY id LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
$baza = rtrim((string) getenv('APP_URL'), '/');
$blocuriNoi = [
    ['type' => 'logo', 'background' => '#ffffff', 'width' => 150, 'padding_top' => 20, 'padding_bottom' => 16],
    ['type' => 'logo', 'image_url' => '/uploads/demo/ceai-de-menta.png', 'width' => 120, 'link_url' => '/magazin', 'align' => 'left'],
    ['type' => 'menu', 'background' => '#0f766e', 'text_color' => '#ffffff', 'items' => [['label' => 'Ceaiuri', 'url' => '/categorie/ceaiuri'],
        ['label' => 'Rău', 'url' => 'javascript:alert(1)'], ['label' => '', 'url' => '/gol']]],
    ['type' => 'coupon', 'code' => 'TOAMNA20', 'content' => 'Folosește <b>codul</b>:', 'note' => 'Până duminică', 'border_color' => 'red;background:url(x)'],
    ['type' => 'products', 'mod' => 'manual', 'pe_rand' => 2, 'title' => 'Alese de noi', 'produse' => array_map(static fn (array $p): array =>
        ['id' => (int) $p['id'], 'name' => 'nume vechi', 'image_url' => '', 'url' => '', 'price' => 1], $produseDemo)],
];
$htmlNoi = $ns::renderHtmlFromBlocks($blocuriNoi, 'Blocuri noi', false);
$numeSite = \App\Support\BrandedEmail::brandName(Settings::all($db));
$okProduse = $produseDemo !== [];
foreach ($produseDemo as $p) {
    $okProduse = $okProduse && str_contains($htmlNoi, '>' . htmlspecialchars((string) $p['name'], ENT_QUOTES) . '</div>')
        && str_contains($htmlNoi, 'href="' . $baza . '/produs/' . rawurlencode((string) $p['slug']) . '"')
        && str_contains($htmlNoi, 'src="' . $baza . (string) $p['image_url'] . '"');
}
$verifica('H4 blocurile noi în email: logo-ul site-ului (fără logo, numele magazinului) cu link spre prima pagină, logo ales cu link, meniul '
    . '(linkurile complete; javascript: → „#”; o etichetă goală nu apare), codul de reducere (o culoare care nu e culoare nu ajunge în stil), '
    . 'produsele alese cu datele de acum din catalog (numele, poza, linkul), 2 pe rând, cu tabel pentru Outlook, fără script',
    str_contains($htmlNoi, 'href="' . $baza . '/" target="_blank"') && str_contains($htmlNoi, '>' . htmlspecialchars($numeSite, ENT_QUOTES) . '</span>')
    && str_contains($htmlNoi, '<img src="' . $baza . '/uploads/demo/ceai-de-menta.png" alt="' . htmlspecialchars($numeSite, ENT_QUOTES) . '" width="120"')
    && str_contains($htmlNoi, 'href="' . $baza . '/categorie/ceaiuri"') && str_contains($htmlNoi, '<a href="#" target="_blank"') && !str_contains($htmlNoi, 'javascript:')
    && !str_contains($htmlNoi, '/gol"') && str_contains($htmlNoi, '>TOAMNA20</div>') && str_contains($htmlNoi, 'Folosește <b>codul</b>:')
    && !str_contains($htmlNoi, 'url(x)') && str_contains($htmlNoi, 'border:2px dashed #2d7d5f')
    && $okProduse && !str_contains($htmlNoi, 'nume vechi') && str_contains($htmlNoi, 'max-width:300px')
    && str_contains($htmlNoi, '<!--[if mso]><table role="presentation"') && !str_contains($htmlNoi, '<script'), mb_substr($htmlNoi, 0, 600));
// Produsele „Automat”: aceeași alegere ca o interogare scrisă separat.
\App\Support\ProduseEmail::uita();
$stariFara = "'cancelled','failed','refunded','returned','pending_payment'";
$cuPoza = "p.image_url IS NOT NULL AND p.image_url <> '' AND p.image_url NOT LIKE '%.svg'";
$vanduteAsteptat = array_map('intval', $db->query("SELECT p.id FROM products p INNER JOIN (SELECT oi.product_id, SUM(oi.quantity) q FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id WHERE o.deleted_at IS NULL AND o.status NOT IN ($stariFara) AND o.created_at >= NOW() - INTERVAL 365 DAY
    GROUP BY oi.product_id) v ON v.product_id = p.id WHERE p.deleted_at IS NULL AND p.is_active = 1 AND COALESCE(p.hidden_in_catalog, 0) = 0 AND $cuPoza
    ORDER BY v.q DESC, p.id DESC LIMIT 4")->fetchAll(PDO::FETCH_COLUMN));
$noiAsteptat = array_map('intval', $db->query("SELECT p.id FROM products p WHERE p.deleted_at IS NULL AND p.is_active = 1 AND COALESCE(p.hidden_in_catalog, 0) = 0
    AND $cuPoza ORDER BY p.created_at DESC, p.id DESC LIMIT 4")->fetchAll(PDO::FETCH_COLUMN));
$catTest = (int) $unu("SELECT p.category_id FROM products p WHERE p.deleted_at IS NULL AND p.is_active = 1 AND p.category_id IS NOT NULL AND $cuPoza
    GROUP BY p.category_id ORDER BY COUNT(*) DESC LIMIT 1");
$dinCat = array_map('intval', $db->query("SELECT p.id FROM products p WHERE p.deleted_at IS NULL AND p.is_active = 1 AND (p.category_id = $catTest
    OR EXISTS (SELECT 1 FROM product_category_links l WHERE l.product_id = p.id AND l.category_id = $catTest))")->fetchAll(PDO::FETCH_COLUMN));
$idRedus = (int) $unu("SELECT p.id FROM products p WHERE p.deleted_at IS NULL AND p.is_active = 1 AND $cuPoza ORDER BY p.id DESC LIMIT 1");
$db->exec('UPDATE products SET sale_price = ROUND(price * 0.5, 2) WHERE id = ' . $idRedus);
$ids = static fn (array $lista): array => array_map(static fn (array $p): int => (int) $p['id'], $lista);
$rez = static fn (array $bloc): array => \App\Support\ProduseEmail::rezolva($db, $bloc + ['mod' => 'auto', 'numar' => 4]);
$rVandute = $ids($rez(['sursa' => 'vandute', 'zile' => 365]));
$rNoi = $ids($rez(['sursa' => 'noi']));
$rCat = $ids($rez(['sursa' => 'categorie', 'categorie_id' => $catTest]));
$rReduse = $rez(['sursa' => 'reduse']);
$verifica('H5 produsele „Automat”, alese la trimitere: cele mai vândute în N zile (fără comenzile anulate, eșuate, rambursate, returnate, '
    . 'neplătite), cele noi, din categorie (principală sau suplimentară), reduse acum (cea mai mare reducere întâi, cu prețul vechi); doar '
    . 'active, cu poză', count($vanduteAsteptat) === 4 && $rVandute === $vanduteAsteptat && $rNoi === $noiAsteptat
    && $rCat !== [] && array_diff($rCat, $dinCat) === [] && ($rReduse[0]['id'] ?? 0) === $idRedus && ($rReduse[0]['old_price'] ?? null) !== null
    && (float) $rReduse[0]['price'] < (float) $rReduse[0]['old_price'],
    json_encode([$rVandute, $vanduteAsteptat, $rNoi, $noiAsteptat, $rCat, $ids($rReduse), $idRedus]));
// Recomandările pe abonat: nlrec1 are o comandă recentă (o comandă demo mutată pe adresa lui), nlrec2 n-are niciuna.
$comandaRec = (int) $unu("SELECT o.id FROM orders o INNER JOIN order_items oi ON oi.order_id = o.id INNER JOIN products p ON p.id = oi.product_id
    WHERE o.deleted_at IS NULL AND o.status = 'completed' AND p.category_id IS NOT NULL GROUP BY o.id ORDER BY o.id LIMIT 1");
$db->exec("UPDATE orders SET billing_email = 'nlrec1@exemplu.ro', created_at = NOW() WHERE id = " . $comandaRec);
$cumparate = array_map('intval', $db->query('SELECT product_id FROM order_items WHERE order_id = ' . $comandaRec)->fetchAll(PDO::FETCH_COLUMN));
$categoriiCumparate = array_map('intval', $db->query('SELECT DISTINCT category_id FROM products WHERE id IN (' . implode(',', $cumparate) . ')')->fetchAll(PDO::FETCH_COLUMN));
$candidati = array_map('intval', $db->query('SELECT p.id FROM products p WHERE p.deleted_at IS NULL AND p.is_active = 1 AND ' . $cuPoza
    . ' AND p.id NOT IN (' . implode(',', $cumparate) . ') AND (p.category_id IN (' . implode(',', $categoriiCumparate) . ')'
    . ' OR EXISTS (SELECT 1 FROM product_category_links l WHERE l.product_id = p.id AND l.category_id IN (' . implode(',', $categoriiCumparate) . ')))')->fetchAll(PDO::FETCH_COLUMN));
$blocRec = ['type' => 'products', 'mod' => 'auto', 'sursa' => 'recomandate', 'numar' => 2, 'pe_rand' => 2, 'title' => 'Pentru tine'];
$rec1 = $ids(\App\Support\ProduseEmail::rezolva($db, $blocRec, 'NLrec1@exemplu.ro'));
$rec2 = $ids(\App\Support\ProduseEmail::rezolva($db, $blocRec, 'nlrec2@exemplu.ro'));
$vandute90 = $ids($rez(['sursa' => 'vandute', 'zile' => 90, 'numar' => 2]));
$cuSemn = $ns::renderHtmlFromBlocks([$blocRec], 'Rec', true, true);
$verifica('H6 „Recomandate fiecărui abonat”: din categoriile ultimei lui comenzi, fără ce a cumpărat; fără comenzi, cele mai vândute; la '
    . 'trimitere emailul are un semn (un comentariu) înlocuit pentru fiecare destinatar, iar previzualizarea arată cele mai vândute',
    $candidati !== [] && $rec1 !== [] && in_array($rec1[0], $candidati, true) && array_intersect($rec1, $cumparate) === [] && $rec2 === $vandute90
    && str_contains($cuSemn, '<!--nl-produse-abonat:') && !str_contains($ns::produseAbonat($cuSemn, 'nlrec1@exemplu.ro'), 'nl-produse-abonat')
    && !str_contains($ns::renderHtmlFromBlocks([$blocRec], 'Rec'), 'nl-produse-abonat'), json_encode([$rec1, $candidati, $cumparate, $rec2, $vandute90]));
// Trimiterea adevărată (pe serverul de email capturat): fiecare abonat primește produsele lui și subsolul cu linkul lui.
$db->exec("INSERT INTO newsletter_lists (name, description, is_default) VALUES ('Lista recomandărilor', 'test', 0)");
$listaRec = (int) $db->lastInsertId();
foreach (['nlrec1@exemplu.ro', 'nlrec2@exemplu.ro'] as $adresaRec) {
    $db->prepare('INSERT INTO newsletter_subscribers (email, name, status) VALUES (?, ?, \'active\')')->execute([$adresaRec, 'Rec']);
    $db->prepare('INSERT INTO newsletter_list_subscribers (list_id, subscriber_id) VALUES (?, ?)')->execute([$listaRec, (int) $db->lastInsertId()]);
}
$db->prepare('INSERT INTO newsletter_campaigns (name, template_type, subscriber_list_id, subject, html_content, blocks_json, status)
    VALUES (\'Test: produse pe abonat\', \'newsletter\', ?, \'Recomandări\', \'\', ?, \'draft\')')
    ->execute([$listaRec, json_encode([['type' => 'text', 'content' => 'Salut'], $blocRec])]);
$cH = (int) $db->lastInsertId();
$mailInainteH = glob($dosarMail . '/*.eml') ?: [];
$ns::uitaDateSubsol();
$rH = $ns::sendCampaignNow($db, $cH, $setari, 100, null, 'admin');
$corpuri = [];
$antetDezabonare = [];
foreach ($mailuriNoi($mailInainteH) as $eml) {
    if (preg_match('/^To:.*?(nlrec[12]@exemplu\.ro)/mi', $eml, $m) === 1) {
        $corpuri[$m[1]] = $corpMail($eml);
        $antetDezabonare[$m[1]] = preg_match('/^List-Unsubscribe:\s*<([^>]+)>/mi', $eml, $mu) === 1 ? $mu[1] : '-';
    }
}
$nume = static fn (int $id): string => htmlspecialchars((string) $unu('SELECT name FROM products WHERE id = ' . $id), ENT_QUOTES);
$verifica('H7 trimiterea: nlrec1 primește recomandările lui, nlrec2 cele mai vândute; fiecare are subsolul cu firma și linkul personal de '
    . 'dezabonare (același ca în antetul List-Unsubscribe), niciun semn rămas', ($rH['sent'] ?? 0) === 2 && count($corpuri) === 2
    && str_contains($corpuri['nlrec1@exemplu.ro'] ?? '', $nume($rec1[0])) && str_contains($corpuri['nlrec2@exemplu.ro'] ?? '', $nume($vandute90[0]))
    && !str_contains(implode('', $corpuri), 'nl-produse-abonat') && substr_count(implode('', $corpuri), 'Firma Demo SRL') === 2
    && count(array_filter(array_keys($corpuri), static fn (string $a): bool => str_starts_with($antetDezabonare[$a], $baza . '/newsletter/unsubscribe/')
        && str_contains($corpuri[$a], 'href="' . htmlspecialchars($antetDezabonare[$a], ENT_QUOTES) . '" style="display:inline-block;padding:8px 14px;'))) === 2,
    json_encode([$rH, array_map(static fn (string $c): int => strlen($c), $corpuri)]));
// Textul scris pe pânză se curăță la randare, ca înainte: doar etichetele editorului; linkurile doar spre adrese sigure.
$rau = "a <b onmouseover=\"alert(1)\">x</b> <img src=x onerror=alert(2)> <script>alert(3)</script> <a href=\"javascript:alert(4)\">j</a> "
    . "<a href=\" JavaScript:alert(5)\">k</a> <span style=\"color:red;background:url(x)\">s</span> <span style=\"color:#ff0000\">roșu</span> "
    . "<a href=\"https://exemplu.ro/?a=1&b=2\">bun</a> <a href=\"/magazin\">cale</a> <a href=\"mailto:x@exemplu.ro\">m</a>";
$curat = '';
foreach (['text', 'header', 'quote'] as $tipRau) {
    $curat .= $ns::renderHtmlFromBlocks([['type' => $tipRau, 'content' => $rau]], 'x', false);
}
$previzRau = json_decode($cerere('POST', '/admin/emails/builder/preview', $jarAdmin, ['blocks_json' => json_encode([['type' => 'text', 'content' => $rau]]),
    'subject' => 'x'])['corp'], true);
$verifica('H8 curățarea textului (scris pe pânză sau în panou): fără atribute on…, <img>, <script>, javascript: (și cu spații sau majuscule), '
    . 'stiluri străine; rămân <b>, <span style="color:#…">, linkurile http(s) (și cu &), căile din site, mailto; la fel în previzualizarea serverului',
    !preg_match('/<[^>]+\son\w+=/i', $curat) && !str_contains($curat, '<img src=x') && !str_contains($curat, '<script') && !preg_match('/href="\s*javascript/i', $curat)
    && !preg_match('/style="[^"]*url\(/', $curat) && str_contains($curat, '<span style="color:#ff0000">roșu</span>') && str_contains($curat, '<a href="https://exemplu.ro/?a=1&amp;b=2">bun</a>')
    && str_contains($curat, '<a href="/magazin">cale</a>') && str_contains($curat, '<a href="mailto:x@exemplu.ro">m</a>') && substr_count($curat, '<a href="#">') === 10
    && is_array($previzRau) && ($previzRau['ok'] ?? false) === true && !preg_match('/<[^>]+\son\w+=/i', (string) $previzRau['html'])
    && !str_contains((string) $previzRau['html'], '<script>alert'), mb_substr($curat, 0, 900));
// Ruta produselor pentru builder și încărcarea pozelor din builder (aceeași rută ca Galerie, cu jetonul CSRF, doar poze, JSON).
$cautare = json_decode($cerere('GET', '/admin/emails/builder/produse?q=ceai', $jarAdmin)['corp'], true);
$anonim = $cerere('GET', '/admin/emails/builder/produse?q=ceai');
$automat = json_decode($cerere('GET', '/admin/emails/builder/produse?sursa=vandute&zile=365&numar=4', $jarAdmin)['corp'], true);
$verifica('H9 GET /admin/emails/builder/produse: căutarea („ceai”), alegerea automată ca la trimitere; fără admin, nimic',
    is_array($cautare) && ($cautare['ok'] ?? false) && ($cautare['items'] ?? []) !== []
    && array_filter($cautare['items'], static fn (array $p): bool => !str_contains(mb_strtolower($p['name']), 'ceai')) === []
    && $ids($automat['items'] ?? []) === $vanduteAsteptat && $anonim['cod'] === 302 && !str_contains($anonim['corp'], '"items":[{'),
    json_encode([$cautare['items'][0] ?? null, $anonim['cod']]));
$paginaBuilder = $cerere('GET', '/admin/emails/builder?type=campaign&id=' . $cH, $jarAdmin)['corp'];
// Jetonul AdminCsrf al sesiunii (același pe toate paginile): din datele builderului, altfel din bara comenzilor.
$jeton = preg_match('/"csrf":"([0-9a-f]{64})"/', $paginaBuilder, $m) === 1 ? $m[1]
    : (preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $cerere('GET', '/admin/orders', $jarAdmin)['corp'], $m) === 1 ? $m[1] : '');
$png = $dosarLucru . '/poza-test.png';
$imgTest = imagecreatetruecolor(40, 20);
imagefill($imgTest, 0, 0, imagecolorallocate($imgTest, 30, 120, 90));
imagepng($imgTest, $png);
$falsa = $dosarLucru . '/nu-e-poza.png';
file_put_contents($falsa, "<?php echo 'x';");
$pdf = $dosarLucru . '/document.pdf';
file_put_contents($pdf, "%PDF-1.4\n%test\n");
$incarca = static fn (array $date, array $antete) => $cerere('POST', '/admin/gallery/bulk-upload', $jarAdmin, $date, $antete);
$randuriGalerie = (int) $unu('SELECT COUNT(*) FROM gallery_images');
$bun = $incarca(['raspuns' => 'json', 'image_files[0]' => new CURLFile($png, 'image/png', 'Poza din builder.png')], ['X-CSRF-Token: ' . $jeton]);
$bunJ = json_decode($bun['corp'], true);
$faraJeton = $incarca(['raspuns' => 'json', 'image_files[0]' => new CURLFile($png, 'image/png', 'a.png')], []);
$jetonGresit = $incarca(['raspuns' => 'json', 'image_files[0]' => new CURLFile($png, 'image/png', 'a.png')], ['X-CSRF-Token: ' . str_repeat('0', 64)]);
$fals = json_decode($incarca(['raspuns' => 'json', 'image_files[0]' => new CURLFile($falsa, 'image/png', 'nu-e-poza.png')], ['X-CSRF-Token: ' . $jeton])['corp'], true);
$doc = json_decode($incarca(['raspuns' => 'json', 'image_files[0]' => new CURLFile($pdf, 'application/pdf', 'document.pdf')], ['X-CSRF-Token: ' . $jeton])['corp'], true);
$anonimUp = $cerere('POST', '/admin/gallery/bulk-upload', null, ['raspuns' => 'json', 'image_files[0]' => new CURLFile($png, 'image/png', 'a.png')]);
$urlUrcat = (string) ($bunJ['imagini'][0]['url'] ?? '');
$verifica('H10 încărcarea din builder (POST /admin/gallery/bulk-upload, raspuns=json): poza intră în galerie (fișierul și rândul), cu jetonul '
    . 'CSRF; fără jeton sau cu altul 403; un fișier care nu e poză (și cu nume .png) sau un PDF refuzat cu mesaj; fără admin 401; nimic altceva scris',
    $bun['cod'] === 200 && ($bunJ['ok'] ?? false) === true && str_starts_with($urlUrcat, '/uploads/gallery/') && is_file(Paths::uploads(substr($urlUrcat, 9)))
    && (int) $unu('SELECT COUNT(*) FROM gallery_images WHERE image_url = ' . $db->quote($urlUrcat)) === 1
    && $faraJeton['cod'] === 403 && $jetonGresit['cod'] === 403 && ($fals['ok'] ?? true) === false && str_contains((string) ($fals['mesaj'] ?? ''), 'doar poze')
    && ($doc['ok'] ?? true) === false && $anonimUp['cod'] === 401 && (int) $unu('SELECT COUNT(*) FROM gallery_images') === $randuriGalerie + 1,
    json_encode([$bun['cod'], $bunJ, $faraJeton['cod'], $jetonGresit['cod'], $fals, $doc, $anonimUp['cod']], JSON_UNESCAPED_UNICODE));
// Pagina builderului: paleta (cele trei grupuri și „Mai mult”), avertismentul datelor firmei doar când lipsesc, șabloanele cu blocurile noi.
$setare('gdpr_operator_sediu', '');
$cuAvertisment = $cerere('GET', '/admin/emails/builder?type=campaign&id=' . $cH, $jarAdmin)['corp'];
$setare('gdpr_operator_sediu', 'Str. Exemplu 10, Cluj-Napoca');
$faraAvertisment = $paginaBuilder;
$verifica('H11 builderul: paleta Magazin (Logo, Meniu, Produse, Cod reducere), Conținut, Așezare și „Mai mult” (secțiunile 60/40…, social); '
    . 'avertismentul „Subsolul automat … nu are încă: sediul” doar cu date lipsă; șabloanele magazinului încep cu blocul Logo și au blocul Produse, '
    . 'fără blocul de text de subsol (subsolul e automat)', str_contains($faraAvertisment, '<h3 class="eb-grup-titlu">Magazin</h3>')
    && str_contains($faraAvertisment, '<h3 class="eb-grup-titlu">Conținut</h3>') && str_contains($faraAvertisment, '<h3 class="eb-grup-titlu">Așezare</h3>')
    && count(array_filter(['logo', 'menu', 'products', 'coupon', 'header', 'text', 'image', 'button', 'video', 'quote', 'columns_2', 'columns_3', 'divider',
        'spacer', 'columns_2_6040', 'columns_2_4060', 'columns_2_7030', 'columns_2_3070', 'social'],
        static fn (string $t): bool => str_contains($faraAvertisment, 'data-block-type="' . $t . '"'))) === 19
    && !str_contains($faraAvertisment, 'id="eb-avertisment-firma"') && str_contains($cuAvertisment, 'id="eb-avertisment-firma"')
    && str_contains($cuAvertisment, 'nu are încă: sediul (adresa).')
    && str_contains($faraAvertisment, '"noutati":[{"type":"logo"') && str_contains($faraAvertisment, '{"type":"products","mod":"manual"')
    && !str_contains($faraAvertisment, 'Primești acest email pentru că te-ai abonat'));
// Datele firmei se completează din Setări site → Datele firmei.
$salvareFirma = $cerere('POST', '/admin/settings/store', $jarAdmin, ['action' => 'save_date_firma', 'gdpr_operator_nume' => '  Altă  Firma SRL ',
    'gdpr_operator_sediu' => 'Str. Test 1', 'gdpr_operator_regcom' => 'J40/1/2020', 'gdpr_operator_cui' => 'RO1', 'account_gdpr_url' => '/politica-de-confidentialitate']);
$paginaSetari = $cerere('GET', '/admin/settings/store?tab=general', $jarAdmin)['corp'];
$verifica('H12 Setări site → Datele firmei: salvează denumirea (spațiile strânse), sediul, Reg. Com., CUI-ul și pagina politicii (aceleași chei ca '
    . 'operatorul GDPR); caseta e pe pagină', $salvareFirma['cod'] === 302 && $unu("SELECT value FROM settings WHERE `key` = 'gdpr_operator_nume'") === 'Altă Firma SRL'
    && $unu("SELECT value FROM settings WHERE `key` = 'gdpr_operator_cui'") === 'RO1' && str_contains($paginaSetari, 'id="setari-firma"')
    && str_contains($paginaSetari, 'name="gdpr_operator_regcom" maxlength="190" value="J40/1/2020"'), (string) $salvareFirma['cod']);
$setare('gdpr_operator_nume', 'Firma Demo SRL');
$ns::uitaDateSubsol();

// =====================================================================
if (!$faraBrowser) {
    $scrie('');
    $scrie('== G. Builderul în Chromium: Desktop / Mobil, pânza, scrisul pe pânză, pozele, tastatura (26 nov 2026)');
    $dosarCapturi = $dosarDev . '/newsletter-builder';
    @mkdir($dosarCapturi, 0775, true);
    $env = ['CMS_DEV_URL=' . $baza, 'CMS_DEV_ADMIN_EMAIL=' . $adminEmail, 'CMS_DEV_PAROLA=' . $parolaAdmin, 'CMS_DEV_CAPTURI=' . $dosarCapturi,
        'NO_PROXY=*', 'no_proxy=*'];
    $iesire = [];
    exec('env -u http_proxy -u https_proxy -u HTTP_PROXY -u HTTPS_PROXY -u ALL_PROXY -u all_proxy ' . implode(' ', array_map('escapeshellarg', $env))
        . ' node ' . escapeshellarg(__DIR__ . '/newsletter-builder-browser.cjs') . ' 2>&1', $iesire, $codNode);
    $rezultate = json_decode((string) end($iesire), true);
    if (!is_array($rezultate) || $rezultate === []) {
        $verifica('Chromium a rulat', false, 'cod ' . $codNode . ': ' . implode("\n", array_slice($iesire, -15)));
    } else {
        foreach ($rezultate as $rezultat) {
            $verifica((string) $rezultat['eticheta'], (bool) $rezultat['ok'], (string) $rezultat['detalii']);
        }
        $scrie('  INFO  capturile: ' . $dosarCapturi);
    }
}

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
