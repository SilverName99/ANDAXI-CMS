<?php

declare(strict_types=1);

/**
 * Testele serverului formularelor și ale căutării unui site capturat (App\Support\FormularCapturat, App\Support\CautareCapturata,
 * nonce-urile din App\Support\CadruCapturat; adoptarea WordPress, pasul 9b; docs/DEPLOY.md, „Formularele de contact” și
 * „Căutarea”), poarta G-formulare, pe o captură INVENTATĂ („Pensiunea Probă”, tools/dev/lib/captura-divi-proba.php), fără
 * WordPress și fără nicio dată a unui client:
 *
 *  1. cheia nonce-urilor (APP_KEY, altfel un secret aleator scris o dată, niciodată o constantă) și nonce-ul legat de formular
 *     (calea, numărul, ziua);
 *  2. un server cu cadrul capturat: fiecare formular din pagini are nonce-ul lui; o trimitere bună (fără JavaScript) dă pagina cu
 *     mesajul în locul formularului, scrie mesajul în `contact_form_messages` și trimite emailul (prins de tools/dev/mail-capturat.sh)
 *     la destinatarul formularului, cu Reply-To vizitatorul; JSON cu `X-Ax-Formular: 1`; formularul din conținut și cel din subsolul
 *     unei pagini cu formular în conținut; formularul din subsolul 404-ului;
 *  3. refuzurile: câmpurile obligatorii și emailul (valorile puse la loc, escapate), nonce-ul greșit, al altui formular, al altei
 *     pagini, de alaltăieri (cel de ieri trece), capcana, altă origine (Sec-Fetch-Site, Origin), un corp prea mare, un câmp prea
 *     lung, antetele (nicio linie nouă din câmpuri în email), bugetul vizitatorului (429), trimiterea dublă, un POST fără
 *     formular capturat (merge mai departe, ca înainte);
 *  4. emailul care nu pleacă: mesajul rămâne în bază cu starea `netrimis`, vizitatorul vede succesul;
 *  5. căutarea `/?s=`: rezultatele în cadrul site-ului (antetul și subsolul capturate), noindex, termenul escapat, mesajul fără
 *     rezultate, diacriticele și literele mari ca WordPress, niciodată în cache; fără `cautare_wp`, prima pagină ca înainte.
 *
 *   tools/dev/php.sh tools/dev/test-formulare-wp.php
 *
 * Baza (cms_form), serverele (CMS_DEV_PORT+41, +42) și dosarul $CMS_DEV_DIR/test-formulare/ se șterg la final. Raport:
 * $CMS_DEV_DIR/test-formulare-raport.txt; cod 0 doar dacă totul a trecut.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$lucru = $dosarDev . '/test-formulare';
exec('rm -rf ' . escapeshellarg($lucru));
foreach (['site/storage', 'site-fara/storage', 'pachet/tema', 'pachet-fara/tema', 'licenta', 'mail'] as $d) {
    @mkdir($lucru . '/' . $d, 0770, true);
}

require_once $radacina . '/bootstrap.php';
require_once $radacina . '/tools/dev/lib/captura-divi-proba.php';

use App\Support\CadruCapturat;
use App\Support\CautareCapturata;
use App\Support\FormularCapturat;
use App\Support\ImportWordPress\Captura\DosarCapturi;
use App\Support\ImportWordPress\Continut\Divi4;
use App\Support\ImportWordPress\Continut\Divi4Taiere;
use App\Support\ImportWordPress\PlanCapturat;
use App\Support\ImportWordPress\ScriereCapturata;

$fisierRaport = $dosarDev . '/test-formulare-raport.txt';
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
$scrie('Test formularele și căutarea site-ului capturat (G-formulare) — ' . date('Y-m-d H:i:s'));

$socket = (string) (getenv('CMS_DEV_SOCK') ?: ini_get('pdo_mysql.default_socket'));
$utilizator = (string) (getenv('CMS_DEV_DB_USER') ?: 'root');
$parolaDb = (string) (getenv('CMS_DEV_DB_PAROLA') ?: '');
$root = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', $utilizator, $parolaDb, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$baza = 'cms_form';
$cheieApp = 'cheie-de-proba-a-formularelor-0123456789abcdef';
$serverePornite = [];
register_shutdown_function(static function () use (&$serverePornite, $root, $baza, $lucru): void {
    foreach ($serverePornite as $pid) {
        if ($pid > 0) {
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
    if (getenv('CMS_DEV_PASTREAZA') !== '1') {
        exec('rm -rf ' . escapeshellarg($lucru));
    }
});

/** @return array{cod: int, antete: array<string, string>, corp: string} */
$cerere = static function (int $port, string $cale, array $antete = [], ?array $post = null, ?string $corpBrut = null): array {
    $c = curl_init('http://127.0.0.1:' . $port . $cale);
    $primite = [];
    $optiuni = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60,
        CURLOPT_PATH_AS_IS => true, CURLOPT_HTTPHEADER => $antete, CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$primite): int {
            if (str_contains($linie, ':')) {
                [$n, $v] = explode(':', $linie, 2);
                $primite[strtolower(trim($n))] = trim($v);
            }
            return strlen($linie);
        }];
    if ($post !== null || $corpBrut !== null) {
        $optiuni[CURLOPT_POST] = true;
        $optiuni[CURLOPT_POSTFIELDS] = $corpBrut ?? http_build_query($post);
    }
    curl_setopt_array($c, $optiuni);
    $corp = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $cod, 'antete' => $primite, 'corp' => $corp];
};
$pornesteServer = static function (int $port, string $pachet, string $dosarSite) use ($radacina, $lucru, $dosarDev, $baza, $cerere, &$serverePornite, $cheieApp): bool {
    $preludiu = $dosarSite . '/preludiu.php';
    file_put_contents($preludiu, "<?php\ndefine('PLATFORMA_SITE_DIR', " . var_export($dosarSite, true) . ");\n");
    $ini = ['-d ' . escapeshellarg('auto_prepend_file=' . $preludiu)];
    foreach (['pdo_mysql.default_socket', 'error_reporting', 'display_errors', 'log_errors', 'error_log', 'memory_limit', 'sendmail_path'] as $cheie) {
        $v = ini_get($cheie);
        if ($v !== false && $v !== '') {
            $ini[] = '-d ' . escapeshellarg($cheie . '=' . $v);
        }
    }
    $mediu = ['PHP_CLI_SERVER_WORKERS' => '2', 'DB_DATABASE' => $baza, 'SITE_DATE_DOSAR' => $pachet, 'LICENTA_DOSAR' => $lucru . '/licenta',
        'APP_URL' => 'https://pensiune-proba.example.test', 'APP_KEY' => $cheieApp, 'IP_PROXY_DE_INCREDERE' => '127.0.0.1', 'CMS_DEV_MAIL_DIR' => $lucru . '/mail'];
    $env = '';
    foreach ($mediu as $n => $v) {
        $env .= ' ' . $n . '=' . escapeshellarg($v);
    }
    $pid = (int) trim((string) shell_exec('cd ' . escapeshellarg($radacina) . ' && env' . $env . ' nohup ' . escapeshellarg(PHP_BINARY) . ' ' . implode(' ', $ini)
        . ' -S 127.0.0.1:' . $port . ' -t public tools/dev/router.php >> ' . escapeshellarg($dosarDev . '/server-formulare-' . $port . '.log') . ' 2>&1 & echo $!'));
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
$scrie('== 1. Cheia nonce-urilor și nonce-ul legat de formular');
$envVechi = getenv('APP_KEY');
putenv('APP_KEY=');
$_ENV['APP_KEY'] = '';
$secret = \App\Support\Paths::stocare('cache/cadru-capturat/secret');
@unlink($secret);
$k1 = CadruCapturat::cheie();
$verifica('fără APP_KEY: un secret aleator, scris o dată (0600), niciodată constanta de dinainte', strlen($k1) === 64 && is_file($secret)
    && trim((string) file_get_contents($secret)) === $k1 && (fileperms($secret) & 0777) === 0600
    && !str_contains((string) file_get_contents($radacina . '/src/Support/CadruCapturat.php'), "'cadru-capturat')"));
@unlink($secret);
putenv('APP_KEY=' . $cheieApp);
$_ENV['APP_KEY'] = $cheieApp;
$n0 = CadruCapturat::nonceFormular('/contact/', 0, '2031-05-01');
$verifica('nonce-ul formularului: 20 de cifre hexa, diferit pe altă cale, alt formular, altă zi, altă cheie', preg_match('/^[0-9a-f]{20}$/', $n0) === 1
    && $n0 !== CadruCapturat::nonceFormular('/camere/', 0, '2031-05-01') && $n0 !== CadruCapturat::nonceFormular('/contact/', 1, '2031-05-01')
    && $n0 !== CadruCapturat::nonceFormular('/contact/', 0, '2031-05-02') && $n0 !== CadruCapturat::nonceFormular('/contact/', 0, '2031-05-01', 'alta-cheie')
    && $n0 === CadruCapturat::nonceFormular('/contact/', 0, '2031-05-01'));
$html = '<form><input type="hidden" id="_wpnonce-et-pb-contact-form-submitted-1" name="_wpnonce-et-pb-contact-form-submitted-1" value="abcdef0123" />'
    . '<input type="hidden" name="_wpnonce-et-pb-contact-form-submitted-1x" value="abcdef0123"><input name="alt" value="abcdef0123"></form>';
$verifica('legaNonceFormulare: doar câmpul nonce Divi (numele exact) primește nonce-ul legat; restul octeților neatinși',
    CadruCapturat::legaNonceFormulare($html, '/x/', '2031-05-01') === str_replace('value="abcdef0123" />', 'value="' . CadruCapturat::nonceFormular('/x/', 1, '2031-05-01') . '" />', $html));
$verifica('limba textelor Divi: din <html lang> (ro-RO → ro; en-US și altele → en)', FormularCapturat::limba(' lang="ro-RO"') === 'ro'
    && FormularCapturat::limba(' lang="en-US"') === 'en' && FormularCapturat::limba('') === 'en');
$verifica('termenii căutării ca WordPress: frazele între ghilimele, fără cuvintele de legătură și literele singure, peste 9 = fraza',
    CautareCapturata::termeni('camera "cu vedere" the a') === ['camera', 'cu vedere'] && CautareCapturata::termeni('a b c d e f g h i j') === ['a b c d e f g h i j']
    && CautareCapturata::termeni('the') === ['the'] && CautareCapturata::pliaza('ȘTIRI Ăă') === 'stiri aa');
$verifica('destinatarii: întâi cei din admin (contact_form_recipients), apoi al formularului din WordPress, apoi adresa de contact a site-ului',
    FormularCapturat::destinatari(null, ['contact_form_recipients' => 'a@exemplu.ro, b@exemplu.ro'], ['destinatar' => 'wp@exemplu.ro']) === ['a@exemplu.ro', 'b@exemplu.ro']
    && FormularCapturat::destinatari(null, [], ['destinatar' => 'wp@exemplu.ro']) === ['wp@exemplu.ro']
    && FormularCapturat::destinatari(null, ['contact_email' => 'site@exemplu.ro'], ['destinatar' => null]) === ['site@exemplu.ro']);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 2. Captura inventată, scrisă pe o bază nouă; serverul cu cadrul capturat');
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
    ['cale' => '/', 'titlu' => 'Acasă', 'id' => 101],
    ['cale' => '/camere/', 'titlu' => 'Camere & prețuri', 'id' => 102, 'inCorp' => '<p>Camera cu vedere spre munte și baie proprie.</p>'],
    ['cale' => '/contact/', 'titlu' => 'Contact', 'id' => 103, 'formular' => true],
    ['cale' => '/stiri/', 'titlu' => 'Știri de la munte', 'id' => 104],
];
$optiuni = ['url' => $url, 'nonce' => 'cccc111111', 'ua' => 'windows', 'an' => $an, 'antet' => 11, 'subsol' => 12, 'corp404' => 13,
    'meniu' => [['/', 'Acasă'], ['/camere/', 'Camere'], ['/contact/', 'Contact']], 'cale404' => '/x1-inexistent/'];
capturaDiviProba($lucru . '/captura', $pagini, $optiuni);
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
$pc = new PlanCapturat();
$paginiDoc = [];
$paginiId = [];
$anModul = ['modul' => 'et_pb_text_0_tb_footer', 'an' => $an];
foreach ($pagini as $pg) {
    $r = $dosar->dupaCale($pg['cale']);
    $rd = (new Divi4($dosar))->transforma((string) $r?->corp, $url, $reguli, $pg['cale']);
    $t = Divi4Taiere::taie($rd, ['tip' => 'pagina', 'cale' => $pg['cale'], 'seo' => [], 'an' => $anModul]);
    $cheie = 'pagina:' . $pg['id'];
    $pc->adaugaDocument($cheie, 'pagina', $t, ['titlu' => $pg['titlu'], 'wp_id' => $pg['id'], 'status' => 200, 'sha_captura' => (string) $r?->sha256(),
        'antete' => PlanCapturat::antete((array) $r?->antete, $url), 'valori_import' => ['cale' => $pg['cale']],
        'corpus' => PlanCapturat::corpus(['titlu' => $pg['titlu'], 'rezumat' => '', 'continut' => '[et_pb_text]' . strip_tags($pg['inCorp'] ?? '') . '[/et_pb_text]'])]);
    $db->prepare('INSERT INTO pages (title, slug, html_content, is_published) VALUES (?, ?, ?, 1)')->execute([$pg['titlu'], trim($pg['cale'], '/'), $t->continut]);
    $paginiId[$cheie] = (int) $db->lastInsertId();
    $paginiDoc[$pg['cale']] = $cheie;
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
$verifica('tăierea și scrierea: 6 documente (4 pagini, 404, fără embed), formularele (conținutul paginii Contact și subsolul)', ($scris['documente'] ?? 0) === 5
    && ($scris['formulare'] ?? 0) === 2, json_encode([$pc->probleme, $scris], JSON_UNESCAPED_UNICODE));
// Ce aduce importul din baza WordPress (destinatarul, mesajele): aici scrise direct, inventate.
$cfg = $db->query('SELECT cheie, config FROM formulare_compat ORDER BY cheie')->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($cfg as $k => $c) {
    $c = json_decode((string) $c, true);
    $c['destinatar'] = str_starts_with((string) $k, 'doc:') ? 'rezervari@exemplu.ro' : 'subsol@exemplu.ro';
    $c['titlu'] = str_starts_with((string) $k, 'doc:') ? 'Rezervă' : '';
    $c['mesaj_succes'] = str_starts_with((string) $k, 'doc:') ? 'Am primit cererea <b>ta</b>, revenim curând.' : '';
    foreach ($c['campuri'] as &$camp) {
        $camp['obligatoriu'] = true;
    }
    unset($camp);
    $c['campuri'][] = ['id' => 'email', 'tip' => 'email', 'eticheta' => 'Email', 'obligatoriu' => true, 'fel' => 'email'];
    $c['campuri'][] = ['id' => 'mesaj', 'tip' => 'text', 'eticheta' => 'Mesaj', 'obligatoriu' => false, 'fel' => 'text'];
    $db->prepare('UPDATE formulare_compat SET config = ? WHERE cheie = ?')->execute([json_encode($c, JSON_UNESCAPED_UNICODE), $k]);
}
// Câmpurile email și mesaj și în DOM (formularul inventat are doar „Nume”): ca pe site-ul de azi (Nume, Email, Mesaj, Trimite).
$campuriDom = static fn (string $n): string => '<p class="et_pb_contact_field" data-id="email" data-type="email"><input type="text" id="et_pb_contact_email_' . $n
    . '" class="input" value="" name="et_pb_contact_email_' . $n . '" data-required_mark="required" data-field_type="email" data-original_id="email" placeholder="Email"></p>'
    . '<p class="et_pb_contact_field" data-id="mesaj" data-type="text"><textarea name="et_pb_contact_mesaj_' . $n . '" id="et_pb_contact_mesaj_' . $n
    . '" class="et_pb_contact_message input" data-required_mark="not_required" data-field_type="text" data-original_id="mesaj" placeholder="Mesaj"></textarea></p>';
$db->exec("UPDATE fragmente_capturate SET html = REPLACE(html, '<input type=\"hidden\" value=\"et_contact_proccess\" name=\"et_pb_contactform_submit_{{ax:formular_index}}\">', '"
    . str_replace("'", "''", $campuriDom('{{ax:formular_index}}')) . "<input type=\"hidden\" value=\"et_contact_proccess\" name=\"et_pb_contactform_submit_{{ax:formular_index}}\">') WHERE fel = 'subsol'");
$db->prepare("UPDATE pages SET html_content = REPLACE(html_content, '<input type=\"hidden\" value=\"et_contact_proccess\" name=\"et_pb_contactform_submit_0\">', ?) WHERE slug = 'contact'")
    ->execute([$campuriDom('0') . '<input type="hidden" value="et_contact_proccess" name="et_pb_contactform_submit_0">']);
$db->exec("INSERT INTO settings (`key`, `value`) VALUES ('store_name', 'Pensiunea Probă'), ('order_email_from_address', 'site@exemplu.ro'), ('email_delivery_method', 'smtp'), ('smtp_host', '')
    ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
$db->exec("DELETE FROM settings WHERE `key` = 'contact_form_recipients'");
foreach (['pachet', 'pachet-fara'] as $pa) {
    file_put_contents($lucru . '/' . $pa . '/tema/tema.json', json_encode(['cadru' => 'capturat', 'runtime' => 'divi4@1']));
    file_put_contents($lucru . '/' . $pa . '/redirecturi.json', '{"exacte": [], "prefixe": []}');
    file_put_contents($lucru . '/' . $pa . '/seo.json', '{}');
}
file_put_contents($lucru . '/pachet/wp-compat.json', '{"slash_final": true, "majuscule": "200", "antete_404": "wordpress", "cautare_wp": true}');
file_put_contents($lucru . '/pachet-fara/wp-compat.json', '{"slash_final": true, "majuscule": "200", "antete_404": "wordpress"}');
$port = (int) (getenv('CMS_DEV_PORT') ?: 8701) + 41;
$verifica('serverul cu cadrul capturat pornește', $pornesteServer($port, $lucru . '/pachet', $lucru . '/site'));

$mailuri = static function () use ($lucru): array {
    $f = glob($lucru . '/mail/*.eml') ?: [];
    sort($f);
    return array_map(static fn (string $x): string => (string) file_get_contents($x), $f);
};
$mesaje = static fn (): array => $db->query('SELECT id, name, email, phone, subject, message, status, source_url, ip_address FROM contact_form_messages ORDER BY id')->fetchAll();
$ip = 0;
/** Un vizitator nou (altă adresă, prin proxy-ul de încredere al testului): bugetul lui pornește de la zero. */
$vizitator = static function () use (&$ip): array {
    $ip++;
    return ['X-Forwarded-For: 198.51.100.' . $ip];
};
$formular = static function (string $corp, int $n): ?array {
    if (preg_match('#<div id="et_pb_contact_form_' . $n . '"[^>]*>.*?</form>\s*</div>\s*</div>#s', $corp, $m) !== 1
        && preg_match('#<div id="et_pb_contact_form_' . $n . '"[^>]*>.*?<div class="et-pb-contact-message">.*?</div>\s*</div>#s', $corp, $m) !== 1) {
        return null;
    }
    preg_match('#name="_wpnonce-et-pb-contact-form-submitted-' . $n . '" value="([^"]*)"#', $m[0], $nonce);
    preg_match('#<div class="et-pb-contact-message">(.*?)</div>#s', $m[0], $mesaj);
    return ['html' => $m[0], 'nonce' => $nonce[1] ?? '', 'mesaj' => $mesaj[1] ?? null, 'are_form' => str_contains($m[0], '<form')];
};
$trimite = static function (string $cale, int $n, array $campuri, array $antete = [], ?string $nonce = null) use ($cerere, $port, $formular, $vizitator): array {
    $antete = $antete !== [] ? $antete : $vizitator();
    if ($nonce === null) {
        $pagina = $cerere($port, $cale, $antete);
        $nonce = (string) ($formular($pagina['corp'], $n)['nonce'] ?? '');
    }
    $post = ['et_pb_contactform_submit_' . $n => 'et_contact_proccess', '_wpnonce-et-pb-contact-form-submitted-' . $n => $nonce, 'ax_capcana' => '',
        '_wp_http_referer' => $cale];
    foreach ($campuri as $k => $v) {
        $post[str_contains((string) $k, 'et_pb_') || $k === 'ax_capcana' ? $k : 'et_pb_contact_' . $k . '_' . $n] = $v;
    }
    return $cerere($port, $cale, $antete, $post);
};

$rCamere = $cerere($port, '/camere/');
$rContact = $cerere($port, '/contact/');
$fCamere = $formular($rCamere['corp'], 0);
$fContact0 = $formular($rContact['corp'], 0);
$fContact1 = $formular($rContact['corp'], 1);
$verifica('fiecare formular are nonce-ul lui (calea, numărul, ziua de azi), capcana și e servit din cache la fel', $fCamere !== null && $fContact0 !== null && $fContact1 !== null
    && $fCamere['nonce'] === CadruCapturat::nonceFormular('/camere/', 0) && $fContact0['nonce'] === CadruCapturat::nonceFormular('/contact/', 0)
    && $fContact1['nonce'] === CadruCapturat::nonceFormular('/contact/', 1) && $fContact0['nonce'] !== $fContact1['nonce']
    && str_contains($rCamere['corp'], 'name="ax_capcana"') && $formular($cerere($port, '/camere/')['corp'], 0)['nonce'] === $fCamere['nonce'],
    json_encode([$fCamere['nonce'] ?? null, $fContact0['nonce'] ?? null, $fContact1['nonce'] ?? null]));

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 3. O trimitere bună, fără JavaScript și cu fetch (JSON)');
$r = $trimite('/camere/', 0, ['name' => 'Ana Probă', 'email' => 'ana@exemplu.ro', 'mesaj' => "Bună ziua,\nare camera <b>vedere</b>?"]);
$f = $formular($r['corp'], 0);
$m = $mesaje();
$e = $mailuri();
$verifica('fără JavaScript: 200, pagina întreagă cu mesajul Divi în .et-pb-contact-message și fără formular, fără cache', $r['cod'] === 200 && $f !== null
    && $f['mesaj'] === '<p>Îți mulțumim că ne-ai contactat</p>' && !$f['are_form'] && str_contains($r['corp'], 'et-l--header') && str_contains($r['corp'], 'et-l--footer')
    && str_contains((string) ($r['antete']['cache-control'] ?? ''), 'no-store') && isset($r['antete']['content-security-policy']),
    $r['cod'] . ' ' . json_encode($f, JSON_UNESCAPED_UNICODE) . ' ' . json_encode($r['antete']));
$verifica('mesajul e scris în contact_form_messages (numele, emailul, textul, pagina, adresa vizitatorului), starea new', count($m) === 1
    && $m[0]['name'] === 'Ana Probă' && $m[0]['email'] === 'ana@exemplu.ro' && $m[0]['message'] === "Bună ziua,\nare camera <b>vedere</b>?"
    && $m[0]['source_url'] === '/camere/' && $m[0]['status'] === 'new' && $m[0]['ip_address'] === '198.51.100.' . $ip
    && $m[0]['subject'] === 'Mesaj nou de la Pensiunea Probă', json_encode($m, JSON_UNESCAPED_UNICODE));
$verifica('emailul: la destinatarul formularului din WordPress, Reply-To vizitatorul, valorile escapate', count($e) === 1 && str_contains($e[0], 'To: subsol@exemplu.ro')
    && preg_match('/^Reply-To:.*ana@exemplu\.ro/mi', $e[0]) === 1 && str_contains($e[0], '&lt;b&gt;vedere&lt;/b&gt;') && !str_contains($e[0], '<b>vedere</b>'),
    substr($e[0] ?? '', 0, 1500));
$rJ = $trimite('/contact/', 0, ['name' => 'Ion Probă', 'email' => 'ion@exemplu.ro', 'mesaj' => 'Rezerv o cameră.'], array_merge($vizitator(), ['X-Ax-Formular: 1']));
$j = json_decode($rJ['corp'], true);
$verifica('cu X-Ax-Formular: 1 (runtime-ul, fetch): JSON {"stare": "trimis", "mesaje": [mesajul formularului, fără HTML]}, no-store',
    $rJ['cod'] === 200 && str_starts_with((string) ($rJ['antete']['content-type'] ?? ''), 'application/json') && is_array($j) && $j['stare'] === 'trimis'
    && $j['mesaje'] === ['Am primit cererea ta, revenim curând.'] && str_contains((string) ($rJ['antete']['cache-control'] ?? ''), 'no-store'), $rJ['corp']);
$e = $mailuri();
$verifica('formularul din conținut: destinatarul lui, subiectul cu titlul formularului', count($e) === 2 && str_contains($e[1], 'To: rezervari@exemplu.ro')
    && str_contains($e[1], 'Mesaj nou de la Pensiunea Prob') && count($mesaje()) === 2, substr($e[1] ?? '', 0, 600));
$r1 = $trimite('/contact/', 1, ['name' => 'Eva', 'email' => 'eva@exemplu.ro', 'mesaj' => 'Din subsol.']);
$f1 = $formular($r1['corp'], 1);
$verifica('formularul din subsolul unei pagini cu formular în conținut (numărul 1): scris, mesajul în el, formularul din conținut neatins',
    $r1['cod'] === 200 && $f1 !== null && !$f1['are_form'] && ($formular($r1['corp'], 0)['are_form'] ?? false) && end($mailuri()) !== false
    && str_contains((string) end($mailuri()), 'To: subsol@exemplu.ro') && count($mesaje()) === 3, $r1['cod'] . ' ' . json_encode($f1, JSON_UNESCAPED_UNICODE));
$cale404 = '/nu-exista-' . bin2hex(random_bytes(3)) . '/';
$r404 = $trimite($cale404, 0, ['name' => 'Dan', 'email' => 'dan@exemplu.ro', 'mesaj' => 'De pe 404.']);
$verifica('formularul din subsolul 404-ului (orice adresă necunoscută, ca WordPress): 404, scris, mesajul în pagină', $r404['cod'] === 404
    && ($formular($r404['corp'], 0)['mesaj'] ?? '') === '<p>Îți mulțumim că ne-ai contactat</p>' && count($mesaje()) === 4 && end($mesaje())['source_url'] === $cale404);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 4. Refuzurile');
$inainte = count($mesaje());
$mailInainte = count($mailuri());
$rO = $trimite('/camere/', 0, ['name' => '<script>alert(1)</script>"', 'email' => '', 'mesaj' => '</textarea><img src=x onerror=alert(2)>']);
$fO = $formular($rO['corp'], 0);
$verifica('un câmp obligatoriu gol: mesajul Divi de eroare, formularul rămâne cu valorile trimise, escapate (nimic executabil)', $rO['cod'] === 200 && $fO !== null && $fO['are_form']
    && $fO['mesaj'] === '<p class="et_pb_contact_error_text">Asigură-te că ai completat toate câmpurile obligatorii.</p>'
    && str_contains($fO['html'], 'value="&lt;script&gt;alert(1)&lt;/script&gt;&quot;"') && str_contains($fO['html'], '&lt;/textarea&gt;&lt;img src=x onerror=alert(2)&gt;</textarea>')
    && !str_contains($rO['corp'], '<script>alert(1)') && !str_contains($rO['corp'], '<img src=x'), json_encode($fO, JSON_UNESCAPED_UNICODE));
$rE = $trimite('/camere/', 0, ['name' => 'X', 'email' => 'nu-e-email', 'mesaj' => 'a']);
$verifica('un email greșit: „Adresă de email nevalidă.”', ($formular($rE['corp'], 0)['mesaj'] ?? '') === '<p class="et_pb_contact_error_text">Adresă de email nevalidă.</p>');
$bune = ['name' => 'Bot', 'email' => 'bot@exemplu.ro', 'mesaj' => 'x'];
$reincarca = '<p class="et_pb_contact_error_text">Te rugăm să reîncarci pagina și să încerci din nou.</p>';
$rN = $trimite('/camere/', 0, $bune, [], 'gresit');
$rNA = $trimite('/camere/', 0, $bune, [], CadruCapturat::nonceFormular('/contact/', 0));
$rNF = $trimite('/contact/', 0, $bune, [], CadruCapturat::nonceFormular('/contact/', 1));
$rNV = $trimite('/camere/', 0, $bune, [], CadruCapturat::nonceFormular('/camere/', 0, date('Y-m-d', time() - 2 * 86400)));
$verifica('nonce-ul greșit, al altei pagini, al altui formular, de alaltăieri: „reîncarcă pagina”, nimic scris', ($formular($rN['corp'], 0)['mesaj'] ?? '') === $reincarca
    && ($formular($rNA['corp'], 0)['mesaj'] ?? '') === $reincarca && ($formular($rNF['corp'], 0)['mesaj'] ?? '') === $reincarca
    && ($formular($rNV['corp'], 0)['mesaj'] ?? '') === $reincarca && count($mesaje()) === $inainte);
$rIeri = $trimite('/camere/', 0, ['name' => 'Ieri', 'email' => 'ieri@exemplu.ro', 'mesaj' => 'nonce de ieri'], [], CadruCapturat::nonceFormular('/camere/', 0, date('Y-m-d', time() - 86400)));
$verifica('nonce-ul de ieri (o pagină din cache de aseară) trece', ($formular($rIeri['corp'], 0)['are_form'] ?? true) === false && count($mesaje()) === $inainte + 1);
$rC = $trimite('/camere/', 0, $bune + ['ax_capcana' => 'http://spam.example']);
$rC2 = $trimite('/camere/', 0, $bune + ['et_pb_contactform_validate_0' => 'x']);
$verifica('capcana completată (ax_capcana, et_pb_contactform_validate_N): refuzat, nimic scris, niciun email', ($formular($rC['corp'], 0)['mesaj'] ?? '') === $reincarca
    && ($formular($rC2['corp'], 0)['mesaj'] ?? '') === $reincarca && count($mesaje()) === $inainte + 1 && count($mailuri()) === $mailInainte + 1);
$rX = $trimite('/camere/', 0, $bune, array_merge($vizitator(), ['Sec-Fetch-Site: cross-site']));
$rX2 = $trimite('/camere/', 0, $bune, array_merge($vizitator(), ['Origin: https://alt-site.example']));
$rX3 = $trimite('/camere/', 0, ['name' => 'Aceeași origine', 'email' => 'o@exemplu.ro'], array_merge($vizitator(), ['Sec-Fetch-Site: same-origin', 'Origin: http://127.0.0.1:' . $port]));
$verifica('altă origine (Sec-Fetch-Site cross-site, Origin străin): 403, nimic scris; aceeași origine trece', $rX['cod'] === 403 && $rX2['cod'] === 403 && $rX3['cod'] === 200
    && count($mesaje()) === $inainte + 2, $rX['cod'] . ' ' . $rX2['cod'] . ' ' . $rX3['cod']);
$rL = $trimite('/camere/', 0, ['name' => 'Lung', 'email' => 'l@exemplu.ro', 'mesaj' => str_repeat('a', FormularCapturat::LUNGIME_TEXT + 1)]);
$rM = $cerere($port, '/camere/', $vizitator(), null, http_build_query(['et_pb_contactform_submit_0' => 'et_contact_proccess', 'x' => str_repeat('b', FormularCapturat::MARIME_CERERE + 10)]));
$verifica('un câmp peste limită: eroarea lui; un corp peste ' . FormularCapturat::MARIME_CERERE . ' octeți: 413; nimic scris',
    str_contains((string) ($formular($rL['corp'], 0)['mesaj'] ?? ''), 'prea lung') && $rM['cod'] === 413 && count($mesaje()) === $inainte + 2, $rL['cod'] . ' ' . $rM['cod']);
$rH = $trimite('/camere/', 0, ['name' => "Mihai\r\nBcc: victima@exemplu.ro", 'email' => 'mihai@exemplu.ro', 'mesaj' => 'antete']);
$ultimul = end($mesaje());
$ultimulMail = (string) end($mailuri());
$verifica('antetele: o linie nouă dintr-un câmp de un rând devine spațiu (în bază și în email), niciun Bcc în email', $ultimul['name'] === 'Mihai Bcc: victima@exemplu.ro'
    && preg_match('/^Bcc:/mi', $ultimulMail) !== 1 && !str_contains($ultimulMail, "\nBcc: victima"), $ultimulMail);
$dublu = ['name' => 'Dublu', 'email' => 'dublu@exemplu.ro', 'mesaj' => 'același mesaj'];
$vd = $vizitator();
$trimite('/camere/', 0, $dublu, $vd);
$nDublu = count($mesaje());
$rD = $trimite('/camere/', 0, $dublu, $vd);
$verifica('aceeași trimitere de două ori (pagina reîncărcată): succes, scrisă o dată', count($mesaje()) === $nDublu && ($formular($rD['corp'], 0)['are_form'] ?? true) === false);
$vb = $vizitator();
$coduri = [];
for ($i = 0; $i < FormularCapturat::INCERCARI_15_MIN + 1; $i++) {
    $coduri[] = $trimite('/camere/', 0, ['name' => '', 'email' => ''], $vb)['cod'];
}
$verifica('bugetul vizitatorului: după ' . FormularCapturat::INCERCARI_15_MIN . ' încercări în 15 minute, 429 cu mesajul în pagină', in_array(429, $coduri, true)
    && end($coduri) === 429 && $coduri[0] === 200, implode(',', $coduri));
$rFara = $cerere($port, '/camere/', $vizitator(), ['nume' => 'fara formular']);
$verifica('un POST fără et_pb_contactform_submit_N: nu e al formularelor, merge mai departe (ca înainte, nicio pagină capturată cu mesaj)', $rFara['cod'] !== 200
    || !str_contains($rFara['corp'], 'et_pb_contact_error_text'), (string) $rFara['cod']);

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 5. Emailul care nu pleacă: mesajul rămâne în admin');
$db->exec("UPDATE settings SET `value` = '127.0.0.1' WHERE `key` = 'smtp_host'");
$db->exec("INSERT INTO settings (`key`, `value`) VALUES ('smtp_port', '9') ON DUPLICATE KEY UPDATE `value` = '9'");
\App\Support\ResponseCache::purgePageCache();
$mailInainte = count($mailuri());
$rS = $trimite('/camere/', 0, ['name' => 'Fără SMTP', 'email' => 'f@exemplu.ro', 'mesaj' => 'serverul de email e oprit']);
$ultimul = end($mesaje());
$verifica('SMTP-ul nu răspunde: vizitatorul vede succesul, mesajul e în bază cu starea netrimis, niciun email', ($formular($rS['corp'], 0)['are_form'] ?? true) === false
    && $ultimul['name'] === 'Fără SMTP' && $ultimul['status'] === FormularCapturat::STARE_NETRIMIS && count($mailuri()) === $mailInainte, json_encode($ultimul, JSON_UNESCAPED_UNICODE));
$vedere = (string) file_get_contents($radacina . '/views/admin/emails.php');
$verifica('adminul (Emailuri → Formulare de contact) arată mesajele, cu „emailul n-a plecat” la cele netrimise', str_contains($vedere, "FormularCapturat::STARE_NETRIMIS")
    && str_contains($vedere, 'emailul n-a plecat'));
$db->exec("UPDATE settings SET `value` = '' WHERE `key` = 'smtp_host'");

// ---------------------------------------------------------------------------------------------------------------------
$scrie('');
$scrie('== 6. Căutarea /?s=');
$rQ = $cerere($port, '/?s=CAMERA');
$lista = static fn (string $corp): string => preg_match('#<h1>Rezultatele căutării.*?</ul>#su', $corp, $m) === 1 ? $m[0] : '';
$verifica('/?s=CAMERA: 200, în cadrul site-ului (antetul și subsolul capturate), legătura spre /camere/ cu titlul escapat, noindex, fără cache',
    $rQ['cod'] === 200 && str_contains($rQ['corp'], 'et-l--header') && str_contains($rQ['corp'], 'et-l--footer')
    && str_contains($lista($rQ['corp']), '<a href="/camere/">Camere &amp; prețuri</a>') && !str_contains($lista($rQ['corp']), '/contact/')
    && str_contains((string) ($rQ['antete']['x-robots-tag'] ?? ''), 'noindex') && str_contains((string) ($rQ['antete']['cache-control'] ?? ''), 'no-store')
    && str_contains($rQ['corp'], '<title>Rezultatele căutării pentru „CAMERA” – Pensiunea Probă</title>') && !str_contains($rQ['corp'], '{{ax:'),
    $rQ['cod'] . ' ' . json_encode($rQ['antete']) . ' ' . substr($rQ['corp'], 0, 300));
$rD = $cerere($port, '/?s=stiri');
$verifica('fără diacritice găsește „Știri” (ca o colație _ci a bazei WordPress)', str_contains($lista($rD['corp']), 'href="/stiri/"'));
$rV = $cerere($port, '/?s=vedere+munte');
$verifica('doi termeni: ambii în pagină (conținutul brut din WordPress), oriunde', str_contains($lista($rV['corp']), 'href="/camere/"') && !str_contains($lista($rV['corp']), 'href="/stiri/"'));
$rZ = $cerere($port, '/?s=' . rawurlencode('<script>alert(1)</script> zzz'));
$verifica('fără rezultate: mesajul în română, termenul escapat (în titlu și în pagină), nimic executabil', $rZ['cod'] === 200
    && str_contains($rZ['corp'], 'Nu am găsit niciun rezultat') && str_contains($rZ['corp'], '„&lt;script&gt;alert(1)&lt;/script&gt; zzz”')
    && !str_contains($rZ['corp'], '<script>alert(1)'), substr($rZ['corp'], 0, 200));
$verifica('formularul din subsolul paginii de rezultate are nonce-ul căii / (trimite la prima pagină)', ($formular($rQ['corp'], 0)['nonce'] ?? '') === CadruCapturat::nonceFormular('/', 0));
$portF = $port + 1;
$verifica('serverul fără cautare_wp pornește', $pornesteServer($portF, $lucru . '/pachet-fara', $lucru . '/site-fara'));
$rF = $cerere($portF, '/?s=camera');
$verifica('fără cautare_wp: /?s= dă prima pagină, ca înainte', $rF['cod'] === 200 && !str_contains($rF['corp'], 'Rezultatele căutării') && str_contains($rF['corp'], '<h1>Acasă</h1>'));

$scrie('');
$scrie('Rezultat: ' . $trecute . ' verificări trecute, ' . $esuate . ' eșuate.');
exit($esuate === 0 ? 0 : 1);
