<?php

declare(strict_types=1);

/**
 * Testele „Vezi” din Admin → Rapoarte → Emailuri trimise (16 nov 2026; App\Support\EmailContinut, migrarea nucleu/0067,
 * docs/PLUGINURI.md, „Emailurile trimise și conținutul lor”), cu serverul de dezvoltare, pe baza de test cu datele demo:
 *
 *   tools/dev/php.sh tools/dev/test-emailuri-trimise.php               # tot, cu Chromium (partea F)
 *   tools/dev/php.sh tools/dev/test-emailuri-trimise.php --fara-browser
 *
 * A. Păstrarea: un email de comandă trimis („Comandă expediată”, prin EmailAutomation, ca la AWB) are conținutul în
 *    `email_send_bodies`, comprimat (gzcompress), exact HTML-ul primit de furnizor (fișierul capturat de mail()); după
 *    schimbarea prețului și a totalului comenzii, previzualizarea arată tot prețul trimis; un email eșuat își păstrează și el
 *    conținutul; peste 4 MB, nimic păstrat („prea_mare”).
 * B. Un rând de dinainte (fără conținut): „Conținutul nu a fost păstrat (email trimis înainte de <data migrării>)”.
 * C. Newsletterul: o campanie trimisă la doi abonați inventați scrie două rânduri `newsletter_campaign` fără conținut păstrat;
 *    „Vezi” le arată din campanie (subsolul trimiterii, cu un loc gol în locul linkului de dezabonare, fără pixel și fără urmărirea clicurilor);
 *    campania ștearsă: mesajul.
 * D. Adminul (HTTP): rândurile istoricului au datele butonului (marcajul em:istoric-vezi, o dată), sertarul; `vezi` dă
 *    fragmentul cu iframe-ul `sandbox=""` și fără HTML-ul emailului în pagină; `continut` dă exact emailul, cu
 *    `Content-Security-Policy: sandbox` fără `allow-scripts`, `no-store`; refuzat nelogat (login), pentru rolul blog și
 *    magazin; permis pentru rolul emailuri (ca pagina istoricului).
 * E. Păstrarea limitată și ștergerea datelor: conținutul de peste 90 de zile se golește (și la deschiderea istoricului),
 *    cel de 89 rămâne; ștergerea unui cont din Admin → Utilizatori golește conținutul emailurilor trimise adresei lui și al
 *    emailurilor comenzilor lui; ștergerea unui abonat, al emailurilor trimise lui; mesajele lor în „Vezi”.
 * F. În Chromium (tools/dev/emailuri-trimise-browser.cjs, 1366 px, deviceScaleFactor 1): butonul pe fiecare rând, sertarul,
 *    cadrul cu origine opacă, scriptul unui email de probă care nu rulează, rândul de dinainte, newsletterul; capturile.
 * G. Curățenia: toată baza pusă la loc (CHECKSUM TABLE), sesiunile, emailurile capturate de test; jurnalul PHP fără
 *    avertismente noi.
 *
 * Raportul: $CMS_DEV_DIR/test-emailuri-trimise-raport.txt. Cod de ieșire 0 doar dacă totul a trecut. Doar date inventate.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';
require_once __DIR__ . '/lib/salvari-admin.php';

use App\Support\Database;
use App\Support\EmailAutomation;
use App\Support\EmailContinut;
use App\Support\NewsletterService;
use App\Support\OrderMailer;
use App\Support\Paths;
use App\Support\Settings;

$faraBrowser = in_array('--fara-browser', $argv, true);
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-emailuri-trimise-raport.txt';
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
$scrie('Test „Vezi” din Emailuri trimise ANDAXI-CMS — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

// ── Serverul și baza ─────────────────────────────────────────────────────────────────────────────────────────────────
$port = (int) (getenv('CMS_DEV_PORT') ?: '8701');
$baza = 'http://127.0.0.1:' . $port;
$cerere = static function (string $metoda, string $cale, ?string $jar = null, array $date = []) use ($baza): array {
    $primite = [];
    $c = curl_init($baza . $cale);
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOPROXY => '*',
        CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0 (X11; Linux x86_64) test-emailuri-trimise'],
        CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$primite): int {
            if (str_contains($linie, ':')) {
                [$nume, $valoare] = explode(':', $linie, 2);
                $primite[strtolower(trim($nume))] = trim($valoare);
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
    return ['cod' => $cod, 'antete' => $primite, 'corp' => $corp];
};
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
if (!$db instanceof PDO || (int) $db->query("SELECT COUNT(*) FROM orders WHERE order_number IN ('1045', '1008')")->fetchColumn() !== 2) {
    $scrie('EROARE: baza de test nu are comenzile demo; rulează întâi tools/dev/instaleaza.sh');
    exit(1);
}
$unu = static function (string $sql, array $param = []) use ($db): string {
    $stmt = $db->prepare($sql);
    $stmt->execute($param);
    $valoare = $stmt->fetchColumn();
    return $valoare === false || $valoare === null ? '' : (string) $valoare;
};
$rand = static function (string $sql, array $param = []) use ($db): array {
    $stmt = $db->prepare($sql);
    $stmt->execute($param);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($r) ? $r : [];
};
$ultimulRand = static fn (): int => (int) $unu('SELECT COALESCE(MAX(id), 0) FROM email_send_history');
$corp = static fn (int $id): array => $rand('SELECT html_gz, html_bytes, cleared_reason, cleared_at, created_at FROM email_send_bodies WHERE history_id = :id',
    ['id' => $id]);
/** Rândul conținutului există, dar conținutul e golit (NULL). */
$gol = static fn (int $id): bool => array_key_exists('html_gz', $corp($id)) && $corp($id)['html_gz'] === null;

// Instantaneul întregii baze: fiecare tabel, pus la loc la final și verificat cu CHECKSUM TABLE.
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
    foreach ($db->query('CHECKSUM TABLE ' . $lista)->fetchAll(PDO::FETCH_NUM) as $r) {
        $sume[(string) $r[0]] = (string) $r[1];
    }
    return $sume;
};
$instantaneu = salvari_instantaneu($db, $tabeleBaza);
$sumeInainte = $sumeBaza();
$sesiuniInainte = glob(Paths::stocare('sessions') . '/sess_*') ?: [];
$dosarMail = $dosarDev . '/mail';
$mailInainte = glob($dosarMail . '/*.eml') ?: [];
$fisierErori = $dosarDev . '/php-erori.log';
$pozitieErori = is_file($fisierErori) ? (int) filesize($fisierErori) : 0;
$parolaAdmin = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));
$adminEmail = (string) (getenv('CMS_DEV_ADMIN_EMAIL') ?: 'admin@exemplu.ro');
$jaruri = [];
foreach (['admin', 'blog', 'magazin', 'emailuri', 'nelogat'] as $rol) {
    $jaruri[$rol] = $dosarDev . '/test-emailuri-trimise-' . $rol . '-' . getmypid() . '.txt';
}
$puneLaLoc = static function () use ($db, $tabeleBaza, $instantaneu, $jaruri, $sesiuniInainte, $amPornitServerul, $radacina, $dosarMail,
    $mailInainte): void {
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    salvari_pune_la_loc($db, $tabeleBaza, $instantaneu);
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    foreach (array_diff(glob(Paths::stocare('sessions') . '/sess_*') ?: [], $sesiuniInainte) as $sesiune) {
        @unlink($sesiune);
    }
    foreach (array_diff(glob($dosarMail . '/*.eml') ?: [], $mailInainte) as $mail) {
        @unlink($mail);
    }
    foreach ($jaruri as $jar) {
        @unlink($jar);
    }
    if ($amPornitServerul) {
        exec(escapeshellarg($radacina . '/tools/dev/server.sh') . ' --opreste >/dev/null 2>&1');
    }
};
$pusLaLoc = false;
register_shutdown_function(static function () use ($puneLaLoc, &$pusLaLoc): void {
    if (!$pusLaLoc) {
        $puneLaLoc();
    }
});
$setari = Settings::all($db);
$dataMigrarii = $unu('SELECT DATE_FORMAT(terminata_la, "%d.%m.%Y") FROM _platforma_migrari WHERE id = :id', ['id' => EmailContinut::MIGRAREA]);
/** HTML-ul din emailul capturat de mail() în dezvoltare (tools/dev/mail-capturat.sh): ce a primit „furnizorul”. */
$ultimulMailCapturat = static function () use ($dosarMail, $mailInainte): string {
    $noi = array_diff(glob($dosarMail . '/*.eml') ?: [], $mailInainte);
    usort($noi, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b) ?: strcmp($a, $b));
    $fisier = (string) end($noi);
    $mesaj = $fisier !== '' ? (string) file_get_contents($fisier) : '';
    $parti = preg_split("/\r?\n\r?\n/", $mesaj, 2);
    return str_replace("\r\n", "\n", (string) ($parti[1] ?? ''));
};

// =====================================================================
$scrie('');
$scrie('== A. Păstrarea conținutului la trimitere');
$comanda = $rand("SELECT id, order_number, total FROM orders WHERE order_number = '1045'");
$idComanda = (int) $comanda['id'];
$item = $rand('SELECT id, product_name, unit_price, quantity FROM order_items WHERE order_id = :o ORDER BY id LIMIT 1', ['o' => $idComanda]);
$pretTrimis = number_format((float) $item['unit_price'], 2, ',', '.') . ' lei';
$db->prepare("DELETE FROM email_logs WHERE order_id = :o AND email_type = 'shipped'")->execute(['o' => $idComanda]);
$inainte = $ultimulRand();
EmailAutomation::sendOrderTemplateById($db, $setari, $idComanda, 'shipped');
$idExpediat = $ultimulRand();
$randExpediat = $rand('SELECT email_type, status, recipient FROM email_send_history WHERE id = :id', ['id' => $idExpediat]);
$corpExpediat = $corp($idExpediat);
$comprimat = (string) ($corpExpediat['html_gz'] ?? '');
$htmlPastrat = $comprimat !== '' ? (string) @gzuncompress($comprimat) : '';
$verifica('A1 „Comandă expediată” (EmailAutomation, comanda 1045): un rând nou în istoric, „sent”, cu conținutul păstrat',
    $idExpediat > $inainte && ($randExpediat['email_type'] ?? '') === 'shipped' && ($randExpediat['status'] ?? '') === 'sent' && $htmlPastrat !== '',
    json_encode($randExpediat));
$verifica('A2 conținutul e comprimat (gzcompress: mai mic, nu începe cu „<”), cu mărimea necomprimată scrisă',
    $comprimat !== '' && $comprimat[0] !== '<' && strlen($comprimat) < strlen($htmlPastrat) && (int) $corpExpediat['html_bytes'] === strlen($htmlPastrat)
    && $corpExpediat['cleared_reason'] === null, strlen($comprimat) . ' / ' . strlen($htmlPastrat));
$capturat = $ultimulMailCapturat();
$verifica('A3 conținutul păstrat = exact HTML-ul primit de furnizor (emailul capturat de mail())',
    $capturat !== '' && rtrim(str_replace("\r\n", "\n", $htmlPastrat)) === rtrim($capturat), strlen($capturat) . ' față de ' . strlen($htmlPastrat));
$verifica('A4 … cu numărul comenzii, produsul și prețul de la trimitere (' . $pretTrimis . ')',
    str_contains($htmlPastrat, '1045') && str_contains($htmlPastrat, htmlspecialchars((string) $item['product_name'], ENT_QUOTES)) && str_contains($htmlPastrat, $pretTrimis));
// Prețul și totalul se schimbă după trimitere (clientul vede altceva în checkout decât în email): „Vezi” arată ce a plecat.
$db->prepare('UPDATE order_items SET unit_price = unit_price + 7.77 WHERE id = :id')->execute(['id' => (int) $item['id']]);
$db->prepare('UPDATE orders SET total = total + 7.77 WHERE id = :id')->execute(['id' => $idComanda]);
$pretNou = number_format((float) $item['unit_price'] + 7.77, 2, ',', '.') . ' lei';
$p = EmailContinut::previzualizare($db, $idExpediat);
$verifica('A5 după schimbarea prețului (' . $pretTrimis . ' → ' . $pretNou . '): „Vezi” arată tot emailul trimis, nu unul refăcut',
    $p['stare'] === 'pastrat' && $p['html'] === $htmlPastrat && str_contains((string) $p['html'], $pretTrimis) && !str_contains((string) $p['html'], $pretNou),
    $p['stare']);
// Un email care n-a plecat: conținutul rămâne (se vede ce ar fi primit omul).
$pica = ['email_delivery_method' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => '9', 'smtp_encryption' => 'none'] + $setari;
try {
    OrderMailer::sendCustom('esuat@exemplu.ro', 'Email de test eșuat', '<p>Conținut EȘUAT-' . getmypid() . '</p>', $pica, $db,
        ['email_type' => 'custom', 'source' => 'test']);
} catch (Throwable) {
}
$idEsuat = $ultimulRand();
$pEsuat = EmailContinut::previzualizare($db, $idEsuat);
$verifica('A6 un email eșuat („failed”) își păstrează și el conținutul',
    $unu('SELECT status FROM email_send_history WHERE id = :id', ['id' => $idEsuat]) === 'failed' && $pEsuat['stare'] === 'pastrat'
    && str_contains((string) $pEsuat['html'], 'EȘUAT-' . getmypid()));
OrderMailer::sendCustom('mare@exemplu.ro', 'Email prea mare', '<p>' . str_repeat('x', EmailContinut::MARIME_MAXIMA + 10) . '</p>', $setari, $db,
    ['email_type' => 'custom', 'source' => 'test']);
$idMare = $ultimulRand();
$pMare = EmailContinut::previzualizare($db, $idMare);
$verifica('A7 peste 4 MB de HTML: nimic păstrat („prea_mare”), iar „Vezi” o spune',
    ($corp($idMare)['cleared_reason'] ?? '') === 'prea_mare' && $gol($idMare) && $pMare['stare'] === 'prea_mare'
    && str_contains($pMare['mesaj'], 'peste 4 MB'), $pMare['mesaj']);
// Un link secret (resetarea parolei, confirmări, dezabonare): păstrat fără jeton, ca istoricul să nu poată fi folosit în locul omului.
$jetonReset = bin2hex(random_bytes(32));
$jetonNl = 'MTIzfGFAYi5ybw.' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
OrderMailer::sendCustom('reset@exemplu.ro', 'Resetare parolă', '<p><a href="' . $baza . '/contul-meu/resetare-parola/' . $jetonReset . '">Resetează parola</a></p>'
    . '<p>Sau copiază: ' . $baza . '/contul-meu/resetare-parola/' . $jetonReset . '</p><p><a href="/newsletter/unsubscribe/' . $jetonNl . '">x</a></p>'
    . '<img src="' . $baza . '/assets/img/logo-demo.png" alt="">', $setari, $db, ['email_type' => 'password_reset', 'source' => 'test']);
$idReset = $ultimulRand();
$pReset = EmailContinut::previzualizare($db, $idReset);
$capturatReset = $ultimulMailCapturat();
$verifica('A8 resetarea parolei: emailul a plecat cu jetonul, dar e păstrat fără el („' . EmailContinut::JETON_ASCUNS . '”, și în text și în href '
    . 'relativ), iar „Vezi” o spune; restul emailului neatins',
    str_contains($capturatReset, $jetonReset) && $pReset['stare'] === 'pastrat' && !str_contains((string) $pReset['html'], $jetonReset)
    && !str_contains((string) $pReset['html'], substr($jetonNl, 15)) && substr_count((string) $pReset['html'], EmailContinut::JETON_ASCUNS) === 3
    && str_contains((string) $pReset['html'], '/assets/img/logo-demo.png') && str_contains($pReset['mesaj'], 'jetonul linkului secret'), $pReset['mesaj']);

// =====================================================================
$scrie('');
$scrie('== B. Un email de dinainte');
$db->prepare("INSERT INTO email_send_history (order_id, email_type, source, recipient, subject, status, provider, sent_at, created_at)
    VALUES (:o, 'new_order', 'order_automation', 'vechi@exemplu.ro', 'Comandă nouă 1045 (de dinainte)', 'sent', 'smtp', NOW() - INTERVAL 30 DAY,
    NOW() - INTERVAL 30 DAY)")->execute(['o' => $idComanda]);
$idVechi = $ultimulRand();
$pVechi = EmailContinut::previzualizare($db, $idVechi);
$verifica('B1 fără conținut păstrat: „Conținutul nu a fost păstrat (email trimis înainte de ' . $dataMigrarii . ')”',
    $dataMigrarii !== '' && $pVechi['stare'] === 'inainte' && $pVechi['html'] === null
    && $pVechi['mesaj'] === 'Conținutul nu a fost păstrat (email trimis înainte de ' . $dataMigrarii . ').', $pVechi['mesaj']);

// =====================================================================
$scrie('');
$scrie('== C. Newsletterul: din campanie, fără o copie pe abonat');
$db->exec("INSERT INTO newsletter_lists (name, description, is_default) VALUES ('Lista testului Emailuri trimise', 'test', 0)");
$lista = (int) $db->lastInsertId();
foreach (['nl-vezi-1@exemplu.ro', 'nl-vezi-2@exemplu.ro'] as $adresa) {
    $db->prepare("INSERT INTO newsletter_subscribers (email, name, status) VALUES (?, 'Abonat test', 'active')")->execute([$adresa]);
    $db->prepare('INSERT INTO newsletter_list_subscribers (list_id, subscriber_id) VALUES (?, ?)')->execute([$lista, (int) $db->lastInsertId()]);
}
$textCampanie = 'Oferta de toamnă DEMO-NL-' . getmypid();
$db->prepare("INSERT INTO newsletter_campaigns (name, template_type, subscriber_list_id, subject, html_content, status)
    VALUES ('Campania testului Vezi', 'newsletter', ?, 'Noutăți demo', ?, 'draft')")
    ->execute([$lista, '<p>' . $textCampanie . ' <a href="https://exemplu.ro/oferta">vezi oferta</a></p>']);
$idCampanie = (int) $db->lastInsertId();
$inainteNl = $ultimulRand();
$rezultatNl = NewsletterService::sendCampaignNow($db, $idCampanie, $setari, 100, null, 'admin');
$randuriNl = $db->query("SELECT id FROM email_send_history WHERE id > $inainteNl AND email_type = 'newsletter_campaign' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$verifica('C1 campania trimisă la 2 abonați: 2 rânduri „newsletter_campaign”, niciunul cu conținut păstrat',
    ($rezultatNl['sent'] ?? 0) === 2 && count($randuriNl) === 2
    && (int) $unu('SELECT COUNT(*) FROM email_send_bodies WHERE history_id > :i', ['i' => $inainteNl]) === 0, json_encode($rezultatNl));
$idNl = (int) ($randuriNl[0] ?? 0);
$pNl = EmailContinut::previzualizare($db, $idNl);
$verifica('C2 „Vezi” pe newsletter: conținutul campaniei, cu subsolul trimiterii („Dezabonează-mă”) și un loc gol în locul linkului abonatului',
    $pNl['stare'] === 'campanie' && str_contains((string) $pNl['html'], $textCampanie)
    && str_contains((string) $pNl['html'], 'href="' . EmailContinut::LINK_DEZABONARE . '"') && str_contains((string) $pNl['html'], 'Dezabonează-mă')
    && !str_contains((string) $pNl['html'], 'Email de test')
    && str_contains($pNl['mesaj'], 'conținutul campaniei „Campania testului Vezi”'), $pNl['mesaj']);
$verifica('C3 … fără pixelul de deschidere, fără linkurile de urmărire și fără tokenul abonatului',
    !str_contains((string) $pNl['html'], '/newsletter/') && str_contains((string) $pNl['html'], 'href="https://exemplu.ro/oferta"'));
$db->prepare("INSERT INTO email_send_history (email_type, source, recipient, subject, status, meta_json)
    VALUES ('newsletter_campaign', 'newsletter_campaign_send', 'nl-vezi-3@exemplu.ro', 'Noutăți demo', 'sent', :m)")
    ->execute(['m' => json_encode(['campaign_id' => 999999])]);
$pFara = EmailContinut::previzualizare($db, $ultimulRand());
$verifica('C4 newsletterul unei campanii șterse: mesajul, fără conținut', $pFara['stare'] === 'campanie_lipsa' && $pFara['html'] === null, $pFara['mesaj']);

// Un email de probă cu script și handler de eroare (pentru Chromium, F): conținutul unui email nu rulează niciodată.
OrderMailer::sendCustom('script@exemplu.ro', 'Email cu script', '<!doctype html><html><head><title>Email</title></head><body>'
    . '<p id="stare-script">NERULAT</p><script>document.getElementById("stare-script").textContent = "RULAT"; try { parent.document.title = "XSS"; } catch (e) {} alert(1);</script>'
    . '<img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" onerror="alert(2)" onload="document.getElementById(\'stare-script\').textContent = \'RULAT\'"></body></html>',
    $setari, $db, ['email_type' => 'custom', 'source' => 'test']);
$idScript = $ultimulRand();

// =====================================================================
$scrie('');
$scrie('== D. Adminul: pagina istoricului, „vezi”, „continut”, rolurile');
$r = $cerere('POST', '/admin/login', $jaruri['admin'], ['email' => $adminEmail, 'password' => $parolaAdmin]);
$verifica('logarea administratorului general', $r['cod'] === 302, (string) $r['cod']);
$pagina = $cerere('GET', '/admin/emails/newsletters?tab=history', $jaruri['admin'])['corp'];
$idsPagina = preg_match('#<script type="application/json" id="email-istoric-vezi">(\[[0-9,]*\])</script>#', $pagina, $m) === 1 ? json_decode($m[1], true) : null;
$idsAsteptate = array_map('intval', $db->query('SELECT id FROM email_send_history ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_COLUMN));
$verifica('D1 istoricul: id-urile rândurilor pentru butonul „Vezi”, în ordinea rândurilor, și sertarul #email-vezi-panou',
    $idsPagina === $idsAsteptate && str_contains($pagina, 'id="email-vezi-panou"') && str_contains($pagina, "'data-adm-panou-sursa', '/admin/emails/history/vezi?id='"),
    json_encode([$idsPagina, $idsAsteptate]));
$verifica('D2 … blocul nou între marcajele lui (em:istoric-vezi), o singură dată (tools/dev/compara-cu-baza.php)',
    getenv('CMS_DEV_MARCAJE_PORT') === '1'
        ? substr_count($pagina, '<!--port:em:istoric-vezi-->') === 1 && substr_count($pagina, '<!--/port:em:istoric-vezi-->') === 1
        : !str_contains($pagina, '<!--port:'));
$vezi = $cerere('GET', '/admin/emails/history/vezi?id=' . $idExpediat, $jaruri['admin']);
$verifica('D3 GET /admin/emails/history/vezi: fragmentul cu datele rândului și iframe-ul sandbox="" spre /continut',
    $vezi['cod'] === 200 && str_contains($vezi['corp'], 'src="/admin/emails/history/continut?id=' . $idExpediat . '" sandbox="" referrerpolicy="no-referrer"')
    && str_contains($vezi['corp'], '<dd>1045</dd>') && !str_contains($vezi['corp'], '<html') && str_contains((string) ($vezi['antete']['cache-control'] ?? ''), 'no-store'),
    mb_substr($vezi['corp'], 0, 600));
$verifica('D4 … HTML-ul emailului nu ajunge în pagina adminului (doar varianta text, escapată, în <pre>)',
    !str_contains($vezi['corp'], '<table role="presentation"') && str_contains($vezi['corp'], '<pre>') && str_contains($vezi['corp'], 'Varianta text'));
$continut = $cerere('GET', '/admin/emails/history/continut?id=' . $idExpediat, $jaruri['admin']);
$csp = (string) ($continut['antete']['content-security-policy'] ?? '');
$verifica('D5 GET /admin/emails/history/continut: exact emailul păstrat, cu CSP „sandbox” fără allow-scripts, no-store, fără Referer',
    $continut['cod'] === 200 && $continut['corp'] === $htmlPastrat && str_starts_with($csp, 'sandbox;') && !str_contains($csp, 'allow-')
    && str_contains($csp, "default-src 'none'") && str_contains((string) ($continut['antete']['cache-control'] ?? ''), 'no-store')
    && ($continut['antete']['referrer-policy'] ?? '') === 'no-referrer' && ($continut['antete']['x-frame-options'] ?? '') === 'SAMEORIGIN', $csp);
$veziVechi = $cerere('GET', '/admin/emails/history/vezi?id=' . $idVechi, $jaruri['admin']);
$verifica('D6 rândul de dinainte: „Conținutul nu a fost păstrat (email trimis înainte de …)”, fără iframe',
    str_contains($veziVechi['corp'], 'Conținutul nu a fost păstrat (email trimis înainte de ' . $dataMigrarii . ').') && !str_contains($veziVechi['corp'], '<iframe'));
$veziNl = $cerere('GET', '/admin/emails/history/vezi?id=' . $idNl, $jaruri['admin']);
$continutNl = $cerere('GET', '/admin/emails/history/continut?id=' . $idNl, $jaruri['admin']);
$verifica('D7 newsletterul: nota campaniei în fragment, conținutul campaniei la /continut (tot cu CSP sandbox)',
    str_contains($veziNl['corp'], 'conținutul campaniei') && str_contains($veziNl['corp'], 'sandbox=""') && str_contains($continutNl['corp'], $textCampanie)
    && str_starts_with((string) ($continutNl['antete']['content-security-policy'] ?? ''), 'sandbox;'));
$lipsa = $cerere('GET', '/admin/emails/history/vezi?id=99999999', $jaruri['admin']);
$verifica('D8 un rând care nu există: 404, „Emailul nu există în istoric.”', $lipsa['cod'] === 404 && str_contains($lipsa['corp'], 'Emailul nu există în istoric.'));
foreach (['/admin/emails/history/vezi?id=', '/admin/emails/history/continut?id='] as $adresa) {
    $nelogat = $cerere('GET', $adresa . $idExpediat, $jaruri['nelogat']);
    $verifica('D9 nelogat, ' . $adresa . '…: trimis la login, fără email', $nelogat['cod'] === 302
        && str_contains((string) ($nelogat['antete']['location'] ?? ''), '/admin/login') && !str_contains($nelogat['corp'], '1045'));
}
foreach (['blog' => 'blog@exemplu.ro', 'magazin' => 'magazin@exemplu.ro', 'emailuri' => 'emailuri@exemplu.ro'] as $rol => $email) {
    $cerere('POST', '/admin/login', $jaruri[$rol], ['email' => $email, 'password' => $parolaAdmin]);
    $pag = $cerere('GET', '/admin/emails/newsletters?tab=history', $jaruri[$rol]);
    $v = $cerere('GET', '/admin/emails/history/vezi?id=' . $idExpediat, $jaruri[$rol]);
    $c = $cerere('GET', '/admin/emails/history/continut?id=' . $idExpediat, $jaruri[$rol]);
    if ($rol === 'emailuri') {
        $verifica('D10 rolul emailuri (vede istoricul): vede și emailul', $pag['cod'] === 200 && $v['cod'] === 200 && $c['cod'] === 200
            && $c['corp'] === $htmlPastrat, $pag['cod'] . '/' . $v['cod'] . '/' . $c['cod']);
    } else {
        $verifica('D10 rolul ' . $rol . ' (nu vede istoricul): refuzat și la „vezi” și la „continut”, fără email',
            $pag['cod'] === 302 && $v['cod'] === 302 && $c['cod'] === 302 && !str_contains($v['corp'] . $c['corp'], '1045')
            && !str_contains((string) ($v['antete']['location'] ?? ''), '/admin/emails'), $pag['cod'] . '/' . $v['cod'] . '/' . $c['cod']);
    }
}

// =====================================================================
$scrie('');
$scrie('== E. Păstrarea limitată și ștergerea datelor');
OrderMailer::sendCustom('retentie@exemplu.ro', 'Email de 89 de zile', '<p>Rămâne</p>', $setari, $db, ['email_type' => 'custom', 'source' => 'test']);
$id89 = $ultimulRand();
OrderMailer::sendCustom('retentie@exemplu.ro', 'Email de 91 de zile', '<p>Pleacă</p>', $setari, $db, ['email_type' => 'custom', 'source' => 'test']);
$id91 = $ultimulRand();
$db->prepare('UPDATE email_send_bodies SET created_at = NOW() - INTERVAL 89 DAY WHERE history_id = :id')->execute(['id' => $id89]);
$db->prepare('UPDATE email_send_bodies SET created_at = NOW() - INTERVAL 91 DAY WHERE history_id = :id')->execute(['id' => $id91]);
$golite = EmailContinut::curataVechi($db);
$verifica('E1 păstrarea de ' . EmailContinut::ZILE_PASTRARE . ' de zile: cel de 91 de zile golit („retentie”), cel de 89 neatins',
    $golite === 1 && $gol($id91) && ($corp($id91)['cleared_reason'] ?? '') === 'retentie'
    && ($corp($id89)['html_gz'] ?? null) !== null && array_key_exists('cleared_reason', $corp($id89)) && $corp($id89)['cleared_reason'] === null, (string) $golite);
$p91 = EmailContinut::previzualizare($db, $id91);
$verifica('E2 … „Vezi”: „Conținutul a fost șters automat pe <azi>: se păstrează 90 de zile de la trimitere.”',
    $p91['stare'] === 'sters' && $p91['mesaj'] === 'Conținutul a fost șters automat pe ' . date('d.m.Y') . ': se păstrează 90 de zile de la trimitere.', $p91['mesaj']);
$db->prepare('UPDATE email_send_bodies SET created_at = NOW() - INTERVAL 95 DAY WHERE history_id = :id')->execute(['id' => $id89]);
$cerere('GET', '/admin/emails/newsletters?tab=history', $jaruri['admin']);
$verifica('E3 și la deschiderea istoricului: conținutul vechi golit', ($corp($id89)['cleared_reason'] ?? '') === 'retentie');
OrderMailer::sendCustom('retentie@exemplu.ro', 'Email nou', '<p>Nou</p>', $setari, $db, ['email_type' => 'custom', 'source' => 'test']);
$db->prepare('UPDATE email_send_bodies SET created_at = NOW() - INTERVAL 100 DAY, cleared_at = NULL, cleared_reason = NULL, html_gz = :h WHERE history_id = :id')
    ->execute(['h' => gzcompress('<p>vechi</p>'), 'id' => $id91]);
OrderMailer::sendCustom('retentie@exemplu.ro', 'Încă un email', '<p>Nou</p>', $setari, $db, ['email_type' => 'custom', 'source' => 'test']);
$verifica('E4 și la scrierea unui email nou: conținutul vechi golit', ($corp($id91)['cleared_reason'] ?? '') === 'retentie');

// Ștergerea unui cont din Admin → Utilizatori: emailurile adresei lui și ale comenzilor lui.
$client = $rand("SELECT u.id, u.email, o.id AS comanda FROM users u JOIN orders o ON o.user_id = u.id WHERE o.order_number = '1008'");
OrderMailer::sendCustom((string) $client['email'], 'Contul tău', '<p>Bun venit</p>', $setari, $db, ['email_type' => 'account_created', 'source' => 'test']);
$idCont = $ultimulRand();
OrderMailer::sendCustom('copie-admin@exemplu.ro', 'Comandă nouă 1008', '<p>Copia adminului</p>', $setari, $db,
    ['email_type' => 'new_order', 'source' => 'test', 'order_id' => (int) $client['comanda']]);
$idCopie = $ultimulRand();
OrderMailer::sendCustom('altcineva@exemplu.ro', 'Alt email', '<p>Altcineva</p>', $setari, $db, ['email_type' => 'custom', 'source' => 'test']);
$idAltul = $ultimulRand();
$r = $cerere('POST', '/admin/users/delete-selected', $jaruri['admin'], ['user_ids' => [(int) $client['id']]]);
$verifica('E5 contul șters din Admin → Utilizatori: conținutul emailurilor trimise adresei lui și al emailurilor comenzii lui golit („gdpr”)',
    $r['cod'] === 302 && $unu('SELECT COUNT(*) FROM users WHERE id = :id', ['id' => (int) $client['id']]) === '0'
    && ($corp($idCont)['cleared_reason'] ?? '') === 'gdpr' && $gol($idCont) && $gol($idCopie)
    && ($corp($idCopie)['cleared_reason'] ?? '') === 'gdpr', json_encode([$corp($idCont)['cleared_reason'] ?? '', $corp($idCopie)['cleared_reason'] ?? '']));
$verifica('E6 … al altor adrese neatins; rândurile din istoric rămân', ($corp($idAltul)['html_gz'] ?? null) !== null
    && $unu('SELECT COUNT(*) FROM email_send_history WHERE id IN (' . $idCont . ',' . $idCopie . ')') === '2');
$pCont = EmailContinut::previzualizare($db, $idCont);
$verifica('E7 … „Vezi”: „Conținutul a fost șters pe <azi>, odată cu datele destinatarului …”', $pCont['stare'] === 'sters'
    && str_starts_with($pCont['mesaj'], 'Conținutul a fost șters pe ' . date('d.m.Y') . ', odată cu datele destinatarului'), $pCont['mesaj']);
// Ștergerea unui abonat la newsletter.
OrderMailer::sendCustom('nl-vezi-2@exemplu.ro', 'Confirmă abonarea', '<p>Confirmă</p>', $setari, $db, ['email_type' => 'newsletter_confirmare', 'source' => 'test']);
$idConfirmare = $ultimulRand();
$abonat = (int) $unu("SELECT id FROM newsletter_subscribers WHERE email = 'nl-vezi-2@exemplu.ro'");
$r = $cerere('POST', '/admin/emails', $jaruri['admin'], ['section' => 'newsletter-subscriber-delete', 'subscriber_id' => $abonat, 'list_id' => $lista]);
$verifica('E8 abonatul șters din Liste abonați: conținutul emailurilor trimise lui golit („gdpr”)', $r['cod'] === 302
    && $unu('SELECT COUNT(*) FROM newsletter_subscribers WHERE id = :id', ['id' => $abonat]) === '0' && ($corp($idConfirmare)['cleared_reason'] ?? '') === 'gdpr');
$stersePrinFunctie = EmailContinut::stergePentruAdrese($db, ['  RETENTIE@exemplu.ro ']);
$verifica('E9 EmailContinut::stergePentruAdrese: adresa fără spații și fără majuscule; doar conținutul încă păstrat',
    $stersePrinFunctie === 2 && (int) $unu("SELECT COUNT(*) FROM email_send_bodies b JOIN email_send_history h ON h.id = b.history_id
        WHERE h.recipient = 'retentie@exemplu.ro' AND b.html_gz IS NOT NULL") === 0, (string) $stersePrinFunctie);

// =====================================================================
if (!$faraBrowser) {
    $scrie('');
    $scrie('== F. În Chromium (1366 px, deviceScaleFactor 1)');
    $dosarCapturi = $dosarDev . '/emailuri-trimise';
    @mkdir($dosarCapturi, 0775, true);
    $env = ['CMS_DEV_URL=' . $baza, 'CMS_DEV_ADMIN_EMAIL=' . $adminEmail, 'CMS_DEV_PAROLA=' . $parolaAdmin, 'CMS_DEV_ID_COMANDA=' . $idExpediat,
        'CMS_DEV_ID_SCRIPT=' . $idScript, 'CMS_DEV_ID_VECHI=' . $idVechi, 'CMS_DEV_ID_NEWSLETTER=' . $idNl, 'CMS_DEV_TEXT_COMANDA=' . $pretTrimis,
        'CMS_DEV_TEXT_NEWSLETTER=' . $textCampanie, 'CMS_DEV_CAPTURI=' . $dosarCapturi, 'NO_PROXY=*', 'no_proxy=*'];
    $iesire = [];
    exec('env -u http_proxy -u https_proxy -u HTTP_PROXY -u HTTPS_PROXY -u ALL_PROXY -u all_proxy ' . implode(' ', array_map('escapeshellarg', $env))
        . ' node ' . escapeshellarg(__DIR__ . '/emailuri-trimise-browser.cjs') . ' 2>&1', $iesire, $codNode);
    $rezultate = json_decode((string) end($iesire), true);
    if (!is_array($rezultate) || $rezultate === []) {
        $verifica('Chromium a rulat', false, 'cod ' . $codNode . ': ' . implode("\n", array_slice($iesire, -15)));
    } else {
        foreach ($rezultate as $rezultat) {
            $verifica('F ' . (string) $rezultat['eticheta'], (bool) $rezultat['ok'], (string) $rezultat['detalii']);
        }
        $scrie('  INFO  capturile: ' . $dosarCapturi);
    }
}

// =====================================================================
$scrie('');
$scrie('== G. Curățenia');
$puneLaLoc();
$pusLaLoc = true;
$verifica('baza pusă la loc: CHECKSUM TABLE identic pe fiecare tabel', $sumeBaza() === $sumeInainte,
    implode(', ', array_keys(array_diff_assoc($sumeBaza(), $sumeInainte))));
$erori = is_file($fisierErori) ? (string) file_get_contents($fisierErori, false, null, $pozitieErori) : '';
$verifica('jurnalul PHP: niciun Warning, Notice sau Fatal nou', preg_match('/PHP (Warning|Notice|Fatal|Parse)/', $erori) !== 1, mb_substr($erori, 0, 1200));

$scrie('');
$scrie('Rezultat: ' . $trecute . ' trecute, ' . $esuate . ' eșuate. Raport: ' . $fisierRaport);
exit($esuate === 0 ? 0 : 1);
