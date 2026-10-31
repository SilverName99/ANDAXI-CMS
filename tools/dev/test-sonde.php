<?php

declare(strict_types=1);

/**
 * Testele verificărilor pe site-urile live (WP-10, poarta G-sonde): tools/verificare/agregate.php
 * cu cele patru seturi, tools/sonda/amprenta-schema.php, tools/sonda/pachet-site.php (WP-14) și tools/dev/migrari/din-sonda.php
 * (docs/VERIFICARE.md, „Verificările pe site-urile live”).
 *
 *   tools/dev/php.sh tools/dev/test-sonde.php
 *   CMS_DEV_PHP=php8.3 tools/dev/php.sh tools/dev/test-sonde.php   # cu PHP-ul minim
 *
 * A. Fără bază: garda instrucțiunilor (doar SELECT/SHOW, una singură, fără comentarii, INTO,
 *    FOR UPDATE, SLEEP…, SELECT-ul cu LIMIT), tiparele ieșirii (un email, un telefon, un text
 *    liber sunt refuzate), tiparul numelor de coloane cu date personale, clasele CSS din HTML
 *    și CSS (nu din declarații sau din url()) și din JavaScript (classList, className, selectorii),
 *    domeniul din APP_URL, .env-ul ca legătură.
 * B. O găzduire simulată în $CMS_DEV_DIR/sonde/acasa-u<marcaj>/ (același cont: site-ul de
 *    verificare și patru site-uri „live” inventate, bioscem.ro, www.grafoanaytis.ro,
 *    biovitality.ro, vertij-tinitus.ro), cu baza cms_sonde_t, făcută ca a unui site live încă neadoptat:
 *    de codul de dinainte de migrări (tools/dev/migrari/pe-vechi.php, cu tabelul vechi `reviews` și
 *    celelalte obiecte vechi, fără registrul migrărilor), plus seed-ul și datele demo.
 *    Scripturile rulează cu un mediu gol (ca pe Hostinger) și cu erorile PHP pe ecran (uneltele
 *    le înlocuiesc cu „AVERTISMENT PHP” / „OPRIT”, căutate și ele în fiecare ieșire):
 *    B1 refuzurile (cod 2, nimic citit, nimic scris): alt dosar decât al verificării, .env fără
 *       SITE_COPIE_VERIFICARE=1, codul din dosarul unui site live, HOME din alt cont, set sau site
 *       necunoscut, argument necunoscut, --dosar spre verificare, spre alt dosar, spre un subdosar,
 *       spre site-ul altui domeniu, spre o copie de verificare, un .env care e legătură, două
 *       dosare cu același domeniu, niciunul, parola bazei greșită (doar codul erorii, fără
 *       utilizator sau bază), session.auto_start; un PHP sub minim primește mesajul, nu o eroare.
 *    B2 fiecare set pe copia bazei: cod 0, nicio EROARE; tabelele altor site-uri apar LIPSĂ.
 *    B3 cu rânduri inventate pentru fiecare verificare (personale, toate cu un marcaj aleator):
 *       fiecare număr se schimbă exact cât trebuie (deltele așteptate sunt scrise aici); numerele
 *       parolelor adminilor sunt, pe toate rândurile, cele date de regula logării
 *       (App\Support\ParolaAdmin::fel, WP-12b), și pe valorile la care SQL-ul s-ar putea despărți de ea.
 *    B4 sesiunea doar de citire (în proces): INSERT, CREATE, ALTER refuzate de server (1792),
 *       timpul pe instrucțiune setat, garda refuză o scriere, o citire în afara tranzacției și o
 *       coloană cu nume de date personale; parcurge pe bucăți; în mijlocul unui grup, un ALTER
 *       din altă conexiune pe un tabel deja citit nu așteaptă (lacătul ținut doar cât
 *       instrucțiunea); cu un utilizator care are doar SELECT, toate seturile și sonda rulează
 *       fără nicio EROARE.
 *    B5 sonda: JSON-ul, amprenta = cea a bazei (Amprenta), rândurile exacte, cheile setărilor,
 *       drepturile, agregatele; --stdout nu scrie nimic.
 *    B6 din-sonda.php: schema refăcută se încarcă și dă aceeași amprentă pe MariaDB și pe MySQL 8;
 *       extra-<cod>.txt are obiectele în plus; refuză un fișier sau un dosar neignorat de git, și
 *       cu căi relative (din rădăcină și din tools/dev, un dosar nou, „..”), fără să lase ceva
 *       (fixture-urile din git din tools/dev/migrari/vechi/ rămân neschimbate, octet cu octet).
 *    B7 pe MySQL 8 (aceeași bază, copiată): fiecare set dă aceleași rânduri ca pe MariaDB.
 *    B8 --buget=0: totul SĂRIT, cod 1.
 *    B9 texte lungi cu memoria PHP mică: 40 de articole de 1 MB se citesc pe bucăți la
 *       memory_limit=16M, unul de 9 MB și unul de 7,5 MB (peste memoria rămasă) doar se numără
 *       (ATENȚIE); unul cu 300.000 de atribute class termină memoria în V-CSS (32M): „OPRIT”,
 *       cod 1, fără să se agațe, verificările de până acolo scrise, fără mesajul PHP-ului (calea
 *       contului). Fiecare rulare are un termen (300 s): o unealtă agățată e un eșec.
 *    B10 tools/sonda/pachet-site.php --site=bioscem (DB-24, V1–V12): cod 0, un rând V1 pentru fiecare cheie înghețată
 *       (cele private, fără fișierul lor, necomparate), pachetul verificat (V12); patru chei puse egal / diferit / lipsă /
 *       gol, pagini, articole, produse, o campanie și setări cunoscute mută fiecare V exact cât trebuie; puse la loc, același
 *       rezultat; niciun marcaj și nicio adresă în ieșire; un site necunoscut refuzat (2).
 *    Pe tot parcursul: niciun marcaj, nicio parolă, niciun nume de bază sau de utilizator și
 *    nicio adresă de email în ieșiri sau în fișierele scrise; baza neschimbată (CHECKSUM TABLE
 *    înainte și după); în dosarele site-urilor live niciun fișier nou sau schimbat (și nicio
 *    sesiune); în site-ul de verificare doar storage/verificare/sonde/; nimic din privat/ sau
 *    din storage/verificare/sonde/ în git.
 *
 * Bazele cms_sonde_t, cms_sonde_rt (pe ambele servere) și utilizatorii cms_sonde_* se fac și se
 * șterg aici; MySQL 8 se pornește (și se oprește) dacă nu mergea. Raportul:
 * $CMS_DEV_DIR/test-sonde-raport.txt; cod de ieșire 0 doar dacă totul a trecut. Date inventate.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/tools/sonda/lib/amprenta-schema.php';
require_once $radacina . '/src/Support/Migrari/Amprenta.php';
require_once $radacina . '/src/Support/Migrari/Server.php';
require_once $radacina . '/src/Support/ParolaAdmin.php';

use App\Support\Migrari\Amprenta;
use App\Support\ParolaAdmin;
use Unelte\Verificare\CitireLive;
use Unelte\Verificare\ClaseCss;
use Unelte\Verificare\InstructiuneRefuzata;
use Unelte\Verificare\Locul;
use Unelte\Verificare\Raport;
use Unelte\Verificare\SiteLive;
use Unelte\Verificare\ValoareRefuzata;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-sonde-raport.txt';
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
$scrie('Test sonde ANDAXI-CMS — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

// =====================================================================
// A. Fără bază
// =====================================================================
$scrie('');
$scrie('A. Fără bază');

$refuzata = static function (string $sql): bool {
    try {
        CitireLive::verificaInstructiunea($sql);
        return false;
    } catch (InstructiuneRefuzata) {
        return true;
    }
};
foreach (['SELECT COUNT(*) FROM orders LIMIT 1', "SELECT `key` AS k FROM settings WHERE `key` LIKE ? LIMIT 100", 'SHOW GRANTS',
    "SELECT COUNT(*) FROM (SELECT post_id FROM blog_post_categories GROUP BY post_id LIMIT 50000) x LIMIT 1", 'SELECT @@GLOBAL.sql_mode AS v LIMIT 1'] as $sql) {
    $verifica('garda primește o citire: ' . $sql, !$refuzata($sql));
}
foreach (['INSERT INTO settings VALUES (1, 2)', 'UPDATE orders SET status = 1', 'DELETE FROM orders', 'DROP TABLE orders', 'CREATE TABLE x (id INT)',
    'ALTER TABLE orders ADD c INT', 'SET SESSION TRANSACTION READ WRITE', 'CALL p()', 'DO SLEEP(1)', 'HANDLER orders OPEN', 'LOAD DATA INFILE "x" INTO TABLE t',
    'REPLACE INTO settings VALUES (1, 2)', 'TRUNCATE orders', 'GRANT ALL ON *.* TO x', 'LOCK TABLES orders READ',
    'SELECT 1 LIMIT 1; DROP TABLE orders', 'SELECT 1 LIMIT 1 -- x', 'SELECT 1 /* x */ LIMIT 1', 'SELECT 1 # x' . "\n" . 'LIMIT 1',
    "SELECT * FROM orders INTO OUTFILE '/tmp/x' LIMIT 1", 'SELECT * FROM orders LIMIT 1 INTO @a', 'SELECT * FROM orders FOR UPDATE LIMIT 1',
    'SELECT * FROM orders LIMIT 1 FOR UPDATE', 'SELECT * FROM orders LOCK IN SHARE MODE LIMIT 1', 'SELECT * FROM orders LIMIT 1 FOR SHARE',
    'SELECT SLEEP(5) LIMIT 1', 'SELECT BENCHMARK(1000000, MD5(1)) LIMIT 1', "SELECT LOAD_FILE('/etc/passwd') LIMIT 1", "SELECT GET_LOCK('x', 1) LIMIT 1",
    'SELECT * FROM orders', 'SELECT * FROM orders LIMIT 0', 'SELECT * FROM orders LIMIT 50001', 'SELECT * FROM orders LIMIT 10, 5',
    'WITH x AS (SELECT 1) SELECT * FROM x LIMIT 1', '(SELECT 1) LIMIT 1', '  select 1 limit 1; '] as $sql) {
    $verifica('garda refuză: ' . str_replace("\n", ' ', $sql), $refuzata($sql));
}

$refuzatRaport = static function (callable $fn): bool {
    try {
        $fn(new Raport());
        return false;
    } catch (ValoareRefuzata) {
        return true;
    }
};
$verifica('ieșirea refuză un email ca nume, un telefon ca id, un text liber ca slug sau clasă, o cheie cu spații',
    $refuzatRaport(static fn (Raport $r) => $r->nume('x', 'ion.popescu@exemplu.ro', 'nume'))
    && $refuzatRaport(static fn (Raport $r) => $r->lista('x', ['0712345678901'], 'id'))
    && $refuzatRaport(static fn (Raport $r) => $r->lista('x', ['Ion Popescu'], 'slug'))
    && $refuzatRaport(static fn (Raport $r) => $r->lista('x', ['a b'], 'clasa'))
    && $refuzatRaport(static fn (Raport $r) => $r->numar('cheie cu spatii', 1))
    && $refuzatRaport(static fn (Raport $r) => $r->alegere('x', 'altceva', ['DA', 'NU']))
    && $refuzatRaport(static fn (Raport $r) => $r->nume('x', 'Strada Exemplu 1', 'ref'))
    && $refuzatRaport(static fn (Raport $r) => $r->nume('x', '2026-10-20', 'luna'))
    && $refuzatRaport(static fn (Raport $r) => $r->lista('x', ['acasa', 'ion@exemplu.ro'], 'slug')));
$r = new Raport();
$r->titlu('Titlu');
$r->numar('a.b', 3, 'descriere');
$r->numar('a.c', 1, '', 0);
$r->daNu('a.d', true);
$r->lista('a.e', range(1, 60), 'id');
$r->lipsa('a.f', 'tabelul donations');
$r->lipsa('a.g', 'tabelul ion@exemplu.ro');
$text = $r->text();
$verifica('ieșirea: „STARE cheie: valoare — descriere”, ATENȚIE la o valoare neașteptată, listele lungi pe mai multe rânduri, LIPSĂ doar cu nume de obiecte',
    str_contains($text, "== Titlu\n") && str_contains($text, '  INFO     a.b: 3  — descriere') && str_contains($text, '  ATENȚIE  a.c: 1')
    && str_contains($text, '  INFO     a.d: DA') && str_contains($text, 'a.e: [60] 1, 2, 3') && substr_count($text, "\n") > 8
    && str_contains($text, '  LIPSĂ    a.f: tabelul donations') && str_contains($text, '  LIPSĂ    a.g: un obiect') && !str_contains($text, '@'), $text);

foreach (['email', 'billing_email', 'user_email', 'phone', 'billing_phone', 'first_name', 'last_name', 'full_name', 'user_name', 'name', 'ip', 'submitted_ip',
    'ip_address', 'user_agent', 'token', 'token_hash', 'password_hash', 'cnp', 'ci_serie', 'google_id', 'session_id', 'nume', 'prenume', 'adresa_corespondenta',
    'billing_address_line1', 'street', 'postcode', 'birth_date', 'gender', 'signature_data_url', 'nume_semnatura', 'iban', 'stripe_secret'] as $coloana) {
    $verifica('coloana cu date personale recunoscută: ' . $coloana, preg_match(CitireLive::TIPAR_PERSONAL, $coloana) === 1);
}
foreach (['id', 'k', 'n', 'g', 'v', 'h', 'pid', 'bk', 'tabel', 'coloana', 'tip', 'implicit', 'status', 'slug', 'html_content', 'css_content', 'content',
    'description', 'bbd_entries_json', 'bbd_key', 'created_at', 'total', 'cunoscut', 'vechi', 'gol', 'cheie_fk', 'randuri_estimate', 'id_rand', 's', 'are'] as $coloana) {
    $verifica('coloana fără date personale primită: ' . $coloana, preg_match(CitireLive::TIPAR_PERSONAL, $coloana) !== 1);
}

$clase = ClaseCss::dinHtml('<div class="a  b-c _d"><span class=\'e\'>x</span><p class=f>y</p><i class="{{tok}} g"></i><b class="<?= $x ?>"></b>'
    . '<style>.h { background: url(img.png) } .i:hover, .j > .k { width: 0.5em } @media (max-width: 600px) { .l { color: red } }</style>'
    . '<div data-class="nu" class="ion@exemplu.ro m"></div>');
sort($clase);
$verifica('clasele CSS din HTML: atributele class (cu ghilimele duble, simple, fără), blocurile <style>; fără tokenuri, fără un email',
    $clase === ['_d', 'a', 'b-c', 'e', 'f', 'h', 'i', 'j', 'k', 'l', 'm'], implode(' ', $clase));
$clase = ClaseCss::dinCss("/* .comentariu */ .a{color:red} .b .c::before{content:'.nu'} a[href$=\".pdf\"]{x:y} .d{background:url(x.png);margin:.5em}"
    . " @import url(\"y.css\"); @media screen { .e { } } @font-face { src: url(f.woff) } .-f, .g--h {}");
sort($clase);
$verifica('clasele CSS din selectori, nu din comentarii, texte, url() sau declarații', $clase === ['-f', 'a', 'b', 'c', 'd', 'e', 'g--h'], implode(' ', $clase));
$clase = ClaseCss::dinJs("document.querySelectorAll('.meniu > .element').forEach(function (el) { el.classList.add('activ', \"deschis\"); "
    . "el.classList.toggle(`se-trage`, x > 1); el.classList.replace('vechi', 'nou'); }); el.className = mare ? 'mare' : 'mic'; el.className += ' adaugat'; "
    . "el.setAttribute('class', 'atribut-unu atribut-doi'); var h = '<div class=\"din-text\">' + x + '</div><i class=\\\"scapat\\\">'; "
    . "var t = `<p class=\"sablon-\${n} fix\">`; $('.jq-selector').addClass('jq-adaugat'); jQuery(document).on('click', '.delegat', f); el.closest('.parinte'); "
    . "var u = 'https://www.exemplu.ro/x.png'; el.style.color = 'ion@exemplu.ro'; // .comentariu");
sort($clase);
$verifica('clasele din JavaScript: classList, className, setAttribute, HTML din texte, selectorii (querySelector, $(), closest, .on); nu din adrese sau comentarii',
    $clase === ['activ', 'adaugat', 'atribut-doi', 'atribut-unu', 'delegat', 'deschis', 'din-text', 'element', 'fix', 'jq-adaugat', 'jq-selector', 'mare', 'meniu',
        'mic', 'nou', 'parinte', 'scapat', 'se-trage', 'vechi'], implode(' ', $clase));

// lib/intrare.php (începutul punctelor de intrare): PHP-ul nu-și mai tipărește erorile (calea contului),
// rămân textele fixe; o eroare fatală înainte de citire → „OPRIT”, cod 2. Cu afișarea și jurnalul pe ecran pornite.
$cuIntrare = static function (string $cod) use ($radacina): array {
    $iesire = [];
    exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d log_errors=1 -d error_reporting=E_ALL -d memory_limit=16M -r '
        . escapeshellarg('require ' . var_export($radacina . '/tools/verificare/lib/intrare.php', true) . '; ' . $cod) . ' 2>&1', $iesire, $codIesire);
    return ['cod' => $codIesire, 'tot' => implode("\n", $iesire)];
};
$r = $cuIntrare('$a = []; echo $a["cheie_lipsa"]; echo "dupa\n";');
$verifica('intrare.php: un avertisment PHP → „AVERTISMENT PHP: E_WARNING”, fără mesajul PHP-ului, rularea continuă', $r['cod'] === 0
    && str_contains($r['tot'], 'AVERTISMENT PHP: E_WARNING') && str_contains($r['tot'], 'dupa') && !str_contains($r['tot'], 'Warning')
    && !str_contains($r['tot'], 'cheie_lipsa') && !str_contains($r['tot'], $radacina), $r['tot']);
$r = $cuIntrare('$x = str_repeat("x", 64 * 1048576);');
$verifica('intrare.php: memoria terminată înainte de citire → „OPRIT: memoria PHP…”, cod 2, fără mesajul PHP-ului', $r['cod'] === 2
    && str_contains($r['tot'], 'OPRIT: memoria PHP s-a terminat (memory_limit)') && str_contains($r['tot'], 'Nimic citit')
    && !preg_match('/(Fatal|Allowed memory)/', $r['tot']) && !str_contains($r['tot'], $radacina), $r['tot']);

$verifica('domeniul din APP_URL: litere mici, fără www., fără port sau cale',
    Locul::gazdaDin('https://WWW.Bioscem.ro/') === 'bioscem.ro' && Locul::gazdaDin('https://bioscem.ro:443/x') === 'bioscem.ro'
    && Locul::gazdaDin('bioscem.ro') === '' && Locul::gazdaDin('') === '');

// =====================================================================
// B. Găzduirea simulată
// =====================================================================
$m = 'mrk' . bin2hex(random_bytes(5));
$M = strtoupper($m);
$telefon = '07' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
$parolaTot = 'Pw' . bin2hex(random_bytes(8));
$parolaCitire = 'Pc' . bin2hex(random_bytes(8));
$utilizatorTot = 'cms_sonde_tot';
$utilizatorCitire = 'cms_sonde_citire';
$baza = 'cms_sonde_t';
$dosarTest = $dosarDev . '/sonde';
$acasa = $dosarTest . '/acasa-u' . $m;
$verif = $acasa . '/domains/verificare.exemplu.test/public_html';
$live = [
    'bioscem' => $acasa . '/domains/bioscem.ro/public_html',
    'grafoanaytis' => $acasa . '/domains/www.grafoanaytis.ro/public_html',
    'biovitality' => $acasa . '/domains/biovitality.ro/public_html',
    'vertij' => $acasa . '/domains/vertij-tinitus.ro/public_html',
];
$gazde = ['bioscem' => 'bioscem.ro', 'grafoanaytis' => 'www.grafoanaytis.ro', 'biovitality' => 'biovitality.ro', 'vertij' => 'vertij-tinitus.ro'];
$sockMaria = (string) (getenv('CMS_DEV_SOCK') ?: '/tmp/claude-0/maria/sock');
$sockMy8 = (string) (getenv('CMS_DEV_SOCK_MYSQL8') ?: '/tmp/claude-0/my8/run/sock');
$secrete = [$m, $M, $telefon, $parolaTot, $parolaCitire, $utilizatorTot, $utilizatorCitire, $baza, 'acasa-u'];
$iesiri = '';

$conectare = static function (string $sock, ?string $db = null): PDO {
    return new PDO('mysql:unix_socket=' . $sock . ($db !== null ? ';dbname=' . $db : '') . ';charset=utf8mb4', (string) (getenv('CMS_DEV_DB_USER') ?: 'root'),
        (string) (getenv('CMS_DEV_DB_PAROLA') ?: ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
};
$merge = static function (string $sock) use ($conectare): bool {
    try {
        $conectare($sock);
        return true;
    } catch (Throwable) {
        return false;
    }
};
$mysql8Pornit = false;
$curata = static function () use ($conectare, $merge, $sockMaria, $sockMy8, $utilizatorTot, $utilizatorCitire, $dosarTest, $dosarDev): void {
    foreach ([$sockMaria, $sockMy8] as $sock) {
        if (!$merge($sock)) {
            continue;
        }
        $root = $conectare($sock);
        foreach (['cms_sonde_t', 'cms_sonde_rt'] as $b) {
            $root->exec('DROP DATABASE IF EXISTS ' . $b);
        }
        foreach ([$utilizatorTot, $utilizatorCitire] as $u) {
            $root->exec("DROP USER IF EXISTS '" . $u . "'@'localhost'");
        }
    }
    if (str_starts_with($dosarTest, $dosarDev . '/') && is_dir($dosarTest)) {
        exec('chmod -R u+rwX ' . escapeshellarg($dosarTest) . ' 2>/dev/null; rm -rf ' . escapeshellarg($dosarTest));
    }
};
register_shutdown_function(static function () use ($curata, &$mysql8Pornit, $radacina): void {
    $curata();
    if ($mysql8Pornit) {
        exec(escapeshellarg($radacina . '/tools/dev/mysql8.sh') . ' opreste > /dev/null 2>&1');
    }
});

/** Un script al uneltelor, cu un mediu gol (ca pe Hostinger) și erorile PHP pe ecran. */
$ruleaza = static function (string $script, array $argumente, array $optiuni = []) use ($verif, $acasa, $sockMaria, &$iesiri): array {
    $php = $optiuni['php'] ?? PHP_BINARY;
    $cod = $optiuni['cod'] ?? $verif;
    $ini = ['-d', 'pdo_mysql.default_socket=' . ($optiuni['sock'] ?? $sockMaria), '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL',
        '-d', 'log_errors=0', '-d', 'memory_limit=512M'];
    foreach ($optiuni['ini'] ?? [] as $cheie => $valoare) {
        array_push($ini, '-d', $cheie . '=' . $valoare);
    }
    $comanda = array_merge([$php], $ini, [str_starts_with($script, '/') ? $script : $cod . '/' . $script], $argumente);
    $mediu = ['PATH' => (string) getenv('PATH'), 'HOME' => $optiuni['home'] ?? $acasa, 'LANG' => 'C.UTF-8'];
    $proces = proc_open($comanda, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tevi, $optiuni['cwd'] ?? $cod, $mediu);
    if (!is_resource($proces)) {
        return ['cod' => -1, 'iesire' => '', 'erori' => 'nu pornește', 'tot' => ''];
    }
    fclose($tevi[0]);
    // Ambele țevi deodată (o ieșire mare pe stderr nu blochează procesul) și cu un termen: o
    // unealtă care se agață (de exemplu la închiderea conexiunii) e un eșec, nu un test oprit.
    stream_set_blocking($tevi[1], false);
    stream_set_blocking($tevi[2], false);
    $iesire = '';
    $erori = '';
    $termen = microtime(true) + ($optiuni['timp'] ?? 300);
    $depasit = false;
    while (!feof($tevi[1]) || !feof($tevi[2])) {
        $deCitit = array_values(array_filter([$tevi[1], $tevi[2]], static fn ($t): bool => !feof($t)));
        $scriere = null;
        $exceptii = null;
        if (@stream_select($deCitit, $scriere, $exceptii, 1) === false) {
            break;
        }
        foreach ($deCitit as $teava) {
            $bucata = (string) fread($teava, 65536);
            if ($teava === $tevi[1]) {
                $iesire .= $bucata;
            } else {
                $erori .= $bucata;
            }
        }
        if (microtime(true) > $termen) {
            $depasit = true;
            proc_terminate($proces, 9);
            break;
        }
    }
    fclose($tevi[1]);
    fclose($tevi[2]);
    $codIesire = proc_close($proces);
    if ($depasit) {
        $codIesire = -2;
        $erori .= "\n[test-sonde: TIMP DEPĂȘIT, procesul oprit după " . ($optiuni['timp'] ?? 300) . ' s]';
    }
    $iesiri .= $iesire . $erori;
    return ['cod' => $codIesire, 'iesire' => $iesire, 'erori' => $erori, 'tot' => $iesire . $erori];
};

/** Rândurile „STARE cheie: valoare — descriere” → [cheie => [stare, valoare]] (listele pe mai multe rânduri, unite). */
$parseaza = static function (string $text): array {
    $randuri = [];
    $ultima = null;
    foreach (explode("\n", $text) as $linie) {
        if (preg_match('/^  (OK|INFO|ATENȚIE|LIPSĂ|EROARE|SĂRIT)\s+([A-Za-z0-9_.:\/-]+): (.*)$/u', $linie, $p) === 1) {
            $valoare = $p[3];
            $poz = mb_strpos($valoare, '  — ');
            $ultima = $p[2];
            $randuri[$ultima] = [$p[1], $poz === false ? $valoare : mb_substr($valoare, 0, $poz)];
            continue;
        }
        if ($ultima !== null && str_starts_with($linie, str_repeat(' ', 15)) && trim($linie) !== '') {
            $rest = trim($linie);
            $poz = mb_strpos($rest, '  — ');
            $randuri[$ultima][1] .= ' ' . ($poz === false ? $rest : mb_substr($rest, 0, $poz));
            continue;
        }
        $ultima = null;
    }
    return $randuri;
};

/** Toate fișierele și dosarele de sub $dosar (cu mărime, dată, drepturi), în afară de excepții. */
$instantaneu = static function (string $dosar, array $exceptii = []): array {
    $rezultat = [];
    if (!is_dir($dosar)) {
        return $rezultat;
    }
    clearstatcache();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dosar, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $cale = $f->getPathname();
        foreach ($exceptii as $exceptie) {
            if ($cale === $exceptie || str_starts_with($cale, $exceptie . '/')) {
                continue 2;
            }
        }
        $rezultat[$cale] = ($f->isLink() ? 'l' : ($f->isDir() ? 'd' : 'f:' . $f->getSize())) . ':' . $f->getMTime() . ':' . substr(sprintf('%o', $f->getPerms()), -4);
    }
    ksort($rezultat);
    return $rezultat;
};

$sumeTabele = static function (PDO $db): array {
    $sume = [];
    foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tabel) {
        $sume[(string) $tabel] = (string) $db->query('CHECKSUM TABLE `' . $tabel . '`')->fetch(PDO::FETCH_NUM)[1];
    }
    ksort($sume);
    return $sume;
};

$scrieEnv = static function (string $dosar, array $chei): void {
    @mkdir($dosar, 0770, true);
    $text = '';
    foreach ($chei as $cheie => $valoare) {
        $text .= $cheie . '=' . $valoare . "\n";
    }
    file_put_contents($dosar . '/.env', $text);
    chmod($dosar . '/.env', 0600);
};

try {
    $curata();
    if (!$merge($sockMaria)) {
        throw new RuntimeException('MariaDB nu merge pe ' . $sockMaria);
    }
    $root = $conectare($sockMaria);

    // ── Baza: ca a unui site live încă neadoptat, cu datele demo, și doi utilizatori ──
    // Baza unui site live a făcut-o codul de dinainte de migrări: install.php și schema.sql de atunci, apoi funcțiile
    // ensure* la primele pagini (tools/dev/migrari/pe-vechi.php), fără registrul migrărilor (_platforma_*, pe care un site
    // neadoptat nu-l are). Așa are, făcute de codul lor și nu scrise de mână aici, și obiectele vechi pe care o instalare
    // nouă nu le mai primește (tabelul `reviews`, tabelele manechinului, products.gallery_json). Apoi seed-ul și datele demo
    // (scripts/seed.php, tools/dev/date-demo.php), ca pe baza de dezvoltare; licența „toate” dintr-un dosar al testului
    // (CMS_DEV_LICENTA_DOSAR: licența locală nu se atinge). Baza de dezvoltare nu se citește deloc.
    $root->exec('CREATE DATABASE ' . $baza . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $dosarLicenta = $dosarTest . '/licenta-toate';
    @mkdir($dosarLicenta, 0770, true);
    $construieste = static function (array $script) use ($radacina, $baza, $sockMaria, $dosarLicenta): array {
        $mediu = getenv();
        $mediu['CMS_DEV_DB'] = $baza;
        $mediu['CMS_DEV_SOCK'] = $sockMaria;
        $mediu['CMS_DEV_LICENTA_DOSAR'] = $dosarLicenta;
        $proces = proc_open(array_merge([$radacina . '/tools/dev/php.sh'], $script), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tevi, $radacina, $mediu);
        $iesire = (string) stream_get_contents($tevi[1]) . (string) stream_get_contents($tevi[2]);
        fclose($tevi[1]);
        fclose($tevi[2]);
        return [proc_close($proces), $iesire];
    };
    $construire = [];
    foreach ([['tools/dev/licenta.php', 'toate'], ['tools/dev/migrari/pe-vechi.php'], ['scripts/seed.php'], ['tools/dev/date-demo.php']] as $script) {
        $construire[$script[0]] = $construieste($script);
    }
    $coduriConstruire = array_map(static fn (array $r): int => $r[0], $construire);
    $tabeleLive = $root->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . $baza . "'")->fetchAll(PDO::FETCH_COLUMN);
    $verifica('baza „live”: făcută de codul de dinainte de migrări (pe-vechi.php, garda: fara_registru), seed.php și date-demo.php, cod 0; '
        . 'are tabelul vechi `reviews` (al codului vechi), nu are registrul migrărilor (_platforma_*)',
        array_filter($coduriConstruire) === [] && str_contains($construire['tools/dev/migrari/pe-vechi.php'][1], 'garda: fara_registru')
        && str_contains($construire['tools/dev/date-demo.php'][1], 'Date demo gata') && in_array('reviews', $tabeleLive, true)
        && array_filter($tabeleLive, static fn (string $t): bool => str_starts_with($t, '_platforma_')) === [] && count($tabeleLive) > 50,
        json_encode($coduriConstruire) . "\n" . implode("\n", array_map(static fn (array $r): string => mb_substr($r[1], -600), $construire)));
    if (array_filter($coduriConstruire) !== []) {
        throw new RuntimeException('baza „live” nu s-a putut face (mai sus)');
    }
    $root->exec("CREATE USER '" . $utilizatorTot . "'@'localhost' IDENTIFIED BY " . $root->quote($parolaTot));
    $root->exec('GRANT ALL ON ' . $baza . ".* TO '" . $utilizatorTot . "'@'localhost'");
    $root->exec("CREATE USER '" . $utilizatorCitire . "'@'localhost' IDENTIFIED BY " . $root->quote($parolaCitire));
    $root->exec('GRANT SELECT ON ' . $baza . ".* TO '" . $utilizatorCitire . "'@'localhost'");
    $db = $conectare($sockMaria, $baza);
    $amprentaInainte = Amprenta::citeste($db, \App\Support\Migrari\Server::detecteaza($db));

    // ── Site-ul de verificare: codul uneltelor, cu .env-ul copiei ──
    foreach (['tools/verificare', 'tools/sonda'] as $dosarCod) {
        exec('mkdir -p ' . escapeshellarg($verif . '/' . dirname($dosarCod)) . ' && cp -r ' . escapeshellarg($radacina . '/' . $dosarCod) . ' '
            . escapeshellarg($verif . '/' . $dosarCod));
    }
    @mkdir($verif . '/src/Support', 0770, true);
    @mkdir($verif . '/config', 0770, true);
    // Verificare și căile site-ului pe care le cere (Paths: .env, storage/verificare; WP-07); regula parolelor adminilor
    // (ParolaAdmin, WP-12b), pe care o încarcă setul comun.
    copy($radacina . '/src/Support/Verificare.php', $verif . '/src/Support/Verificare.php');
    copy($radacina . '/src/Support/Paths.php', $verif . '/src/Support/Paths.php');
    copy($radacina . '/src/Support/ParolaAdmin.php', $verif . '/src/Support/ParolaAdmin.php');
    copy($radacina . '/config/app.php', $verif . '/config/app.php');
    // Sonda pachetului (tools/sonda/pachet-site.php, DB-24): înghețarea și manifestul pachetului, cu pachetul Bioscem din
    // git (fără privat/, ca o clonă; pe site-ul de verificare codul e o clonă întreagă).
    @mkdir($verif . '/src/Support/Migrari', 0770, true);
    foreach (['src/Support/PachetSite.php', 'src/Support/Migrari/Eroare.php', 'src/Support/Migrari/ParametruLipsa.php', 'src/Support/Migrari/Inghetare.php'] as $fisierCod) {
        copy($radacina . '/' . $fisierCod, $verif . '/' . $fisierCod);
    }
    // (git archive nu-l scoate: /site-uri e export-ignore, .gitattributes; site-ul de verificare ia codul cu git clone.)
    @mkdir($verif . '/site-uri', 0770, true);
    exec('cp -r ' . escapeshellarg($radacina . '/site-uri/bioscem') . ' ' . escapeshellarg($verif . '/site-uri/bioscem') . ' && rm -rf '
        . escapeshellarg($verif . '/site-uri/bioscem/privat'));
    $scrieEnv($verif, ['APP_URL' => 'https://verificare.exemplu.test', 'SITE_COPIE_VERIFICARE' => '1', 'DB_HOST' => 'localhost', 'DB_DATABASE' => 'cms_verif_inexistenta']);

    // ── Site-urile „live”: .env spre cms_sonde_t, cod și dosare inventate ──
    foreach ($live as $site => $dosar) {
        $scrieEnv($dosar, ['APP_URL' => 'https://' . $gazde[$site], 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'DB_HOST' => 'localhost', 'DB_PORT' => '3306',
            'DB_DATABASE' => $baza, 'DB_USERNAME' => $utilizatorTot, 'DB_PASSWORD' => $parolaTot, 'BT_IPAY_MODE' => 'live', 'BT_IPAY_LIVE_PASS' => 'bt-' . $m,
            'ADMIN_DEFAULT_PASSWORD' => 'adm-' . $m]);
        @mkdir($dosar . '/public', 0770, true);
        file_put_contents($dosar . '/public/index.php', "<?php // codul site-ului " . $site . "\n");
        @mkdir($dosar . '/storage/sessions', 0770, true);
        file_put_contents($dosar . '/storage/sessions/sess_' . $m, 'cos|a:0:{}');
        @mkdir($dosar . '/storage/import', 0770, true);
        file_put_contents($dosar . '/storage/import/.gitignore', "*\n");
    }
    @mkdir($live['bioscem'] . '/.git/refs/heads/claude', 0770, true);
    file_put_contents($live['bioscem'] . '/.git/HEAD', "ref: refs/heads/claude/ramura-test\n");
    file_put_contents($live['bioscem'] . '/.git/refs/heads/claude/ramura-test', str_repeat('b', 40) . "\n");
    @mkdir($live['grafoanaytis'] . '/.git', 0770, true);
    file_put_contents($live['grafoanaytis'] . '/.git/HEAD', "ref: refs/heads/main\n");
    file_put_contents($live['grafoanaytis'] . '/.git/packed-refs', "# pack-refs with: peeled\n" . str_repeat('c', 40) . " refs/heads/main\n");

    $scrie('');
    $scrie('B1. Refuzurile (cod 2, nimic citit, nimic scris)');
    $faraBaza = static fn (array $r): bool => $r['cod'] === 2 && str_contains($r['erori'], 'REFUZAT') && $r['iesire'] === ''
        && !preg_match('/(Warning|Fatal|AVERTISMENT PHP|OPRIT)/', $r['tot']);
    $r = $ruleaza($radacina . '/tools/verificare/agregate.php', ['--set=bioscem'], ['cod' => $radacina]);
    $verifica('din codul de dezvoltare (nu e ~/domains/<domeniu>/public_html) → refuzat', $faraBaza($r) && str_contains($r['erori'], 'site-ului de verificare'), $r['tot']);
    rename($verif . '/.env', $verif . '/.env.pus-deoparte');
    $r = $ruleaza('tools/verificare/agregate.php', ['--set=bioscem']);
    rename($verif . '/.env.pus-deoparte', $verif . '/.env');
    $verifica('site-ul de verificare fără .env → refuzat', $faraBaza($r) && str_contains($r['erori'], 'SITE_COPIE_VERIFICARE'), $r['tot']);
    $envVerif = (string) file_get_contents($verif . '/.env');
    file_put_contents($verif . '/.env', str_replace('SITE_COPIE_VERIFICARE=1', 'SITE_COPIE_VERIFICARE=0', $envVerif));
    $r = $ruleaza('tools/sonda/amprenta-schema.php', ['--site=bioscem']);
    file_put_contents($verif . '/.env', $envVerif);
    $verifica('SITE_COPIE_VERIFICARE=0 → refuzat (sonda la fel)', $faraBaza($r), $r['tot']);
    // Codul uneltelor în dosarul unui site live (cum va fi după adoptare): refuzat.
    exec('mkdir -p ' . escapeshellarg($live['biovitality'] . '/tools') . ' && cp -r ' . escapeshellarg($verif . '/tools/verificare') . ' '
        . escapeshellarg($live['biovitality'] . '/tools/') . ' && mkdir -p ' . escapeshellarg($live['biovitality'] . '/src/Support') . ' '
        . escapeshellarg($live['biovitality'] . '/config') . ' && cp ' . escapeshellarg($verif . '/src/Support/Verificare.php') . ' '
        . escapeshellarg($verif . '/src/Support/Paths.php') . ' ' . escapeshellarg($verif . '/src/Support/ParolaAdmin.php') . ' '
        . escapeshellarg($live['biovitality'] . '/src/Support/') . ' && cp ' . escapeshellarg($verif . '/config/app.php') . ' ' . escapeshellarg($live['biovitality'] . '/config/'));
    $r = $ruleaza('tools/verificare/agregate.php', ['--set=bioscem'], ['cod' => $live['biovitality']]);
    exec('rm -rf ' . escapeshellarg($live['biovitality'] . '/tools') . ' ' . escapeshellarg($live['biovitality'] . '/src') . ' ' . escapeshellarg($live['biovitality'] . '/config'));
    $verifica('din dosarul unui site live → refuzat', $faraBaza($r) && str_contains($r['erori'], 'pe un site live'), $r['tot']);
    $r = $ruleaza('tools/verificare/agregate.php', ['--set=bioscem'], ['home' => $dosarTest]);
    $verifica('HOME din alt cont → refuzat', $faraBaza($r) && str_contains($r['erori'], 'HOME'), $r['tot']);
    foreach ([[['--set=necunoscut'], 'set necunoscut'], [['--set=comun'], 'setul comun singur'], [[], 'fără --set'], [['--set=bioscem', '--sterge'], 'argument necunoscut'],
        [['--set=bioscem', '--buget=99999'], 'buget prea mare'], [['--set=bioscem', '--dosar=' . $verif], '--dosar = site-ul de verificare'],
        [['--set=bioscem', '--dosar=' . $dosarTest], '--dosar în afara ~/domains'], [['--set=bioscem', '--dosar=' . $live['bioscem'] . '/public'], '--dosar = un subdosar'],
        [['--set=bioscem', '--dosar=' . $live['grafoanaytis']], '--dosar = site-ul altui domeniu'], [['--set=bioscem', '--dosar=/nu/exista'], '--dosar inexistent']] as [$arg, $ce]) {
        $r = $ruleaza('tools/verificare/agregate.php', $arg);
        $verifica($ce . ' → refuzat', $faraBaza($r), $r['tot']);
    }
    $r = $ruleaza('tools/sonda/amprenta-schema.php', ['--site=altul']);
    $verifica('sonda: site necunoscut → refuzat', $faraBaza($r), $r['tot']);
    // O copie de verificare cu domeniul bioscem.ro (de exemplu o repetiție): nu e site-ul live.
    $copieBs = $acasa . '/domains/www.bioscem.ro/public_html';
    $scrieEnv($copieBs, ['APP_URL' => 'https://bioscem.ro', 'SITE_COPIE_VERIFICARE' => '1', 'DB_DATABASE' => $baza]);
    $r = $ruleaza('tools/verificare/agregate.php', ['--set=bioscem', '--dosar=' . $copieBs]);
    $verifica('--dosar = o copie de verificare → refuzat', $faraBaza($r) && str_contains($r['erori'], 'copie de verificare'), $r['tot']);
    exec('rm -rf ' . escapeshellarg(dirname($copieBs)));
    // Două dosare cu același domeniu: ~/public_html (site-ul principal al contului) și domains/bioscem.ro.
    $scrieEnv($acasa . '/public_html', ['APP_URL' => 'https://www.bioscem.ro', 'DB_DATABASE' => $baza]);
    $r = $ruleaza('tools/verificare/agregate.php', ['--set=bioscem']);
    $verifica('două dosare cu bioscem.ro → refuzat, cu ambele dosare (relativ la acasă)', $faraBaza($r) && str_contains($r['erori'], 'public_html')
        && str_contains($r['erori'], 'domains/bioscem.ro/public_html'), $r['tot']);
    exec('rm -rf ' . escapeshellarg($acasa . '/public_html'));
    // .env ca legătură spre alt fișier.
    $envBs = (string) file_get_contents($live['bioscem'] . '/.env');
    rename($live['bioscem'] . '/.env', $dosarTest . '/env-pus-deoparte');
    symlink($dosarTest . '/env-pus-deoparte', $live['bioscem'] . '/.env');
    $r = $ruleaza('tools/verificare/agregate.php', ['--set=bioscem']);
    unlink($live['bioscem'] . '/.env');
    rename($dosarTest . '/env-pus-deoparte', $live['bioscem'] . '/.env');
    $verifica('.env-ul site-ului live e o legătură → refuzat', $faraBaza($r) && str_contains($r['erori'], 'legătură'), $r['tot']);
    // Niciun dosar: setul vertij fără site.
    rename($live['vertij'], $live['vertij'] . '-ascuns');
    $r = $ruleaza('tools/verificare/agregate.php', ['--set=vertij']);
    rename($live['vertij'] . '-ascuns', $live['vertij']);
    $verifica('site-ul setului nu se găsește → refuzat, cu indicația --dosar', $faraBaza($r) && str_contains($r['erori'], '--dosar'), $r['tot']);
    // Parola bazei greșită: doar codul erorii, fără utilizator, bază sau parolă.
    file_put_contents($live['bioscem'] . '/.env', str_replace('DB_PASSWORD=' . $parolaTot, 'DB_PASSWORD=gresita-' . $m, $envBs));
    $r = $ruleaza('tools/verificare/agregate.php', ['--set=bioscem']);
    $r2 = $ruleaza('tools/sonda/amprenta-schema.php', ['--site=bioscem']);
    file_put_contents($live['bioscem'] . '/.env', $envBs);
    $verifica('parola bazei greșită → refuzat cu „eroarea 1045”, fără utilizator, bază sau parolă (și sonda)', $faraBaza($r) && $faraBaza($r2)
        && str_contains($r['erori'], 'eroarea 1045') && !str_contains($r['tot'] . $r2['tot'], $utilizatorTot) && !str_contains($r['tot'], $baza), $r['tot']);
    $r = $ruleaza('tools/verificare/agregate.php', ['--set=bioscem'], ['ini' => ['session.auto_start' => '1', 'session.save_path' => $dosarTest]]);
    $verifica('session.auto_start=1 → refuzat', $faraBaza($r) && str_contains($r['erori'], 'session.auto_start'), $r['tot']);
    @array_map('unlink', glob($dosarTest . '/sess_*') ?: []);
    $phpMinim = preg_match("/'php_min'\\s*=>\\s*'([0-9.]+)'/", (string) file_get_contents($radacina . '/config/app.php'), $p) === 1 ? $p[1] : '';
    $phpVechi = array_values(array_filter(['php7.0', 'php7.4', 'php8.0', 'php8.1', 'php8.2'], static fn (string $b): bool
        => version_compare(substr($b, 3), $phpMinim, '<') && trim((string) shell_exec('command -v ' . $b . ' 2>/dev/null')) !== ''));
    if ($phpVechi === []) {
        $scrie('  INFO  niciun PHP sub minimul ' . $phpMinim . ' instalat: mesajul pentru un PHP prea vechi nu s-a verificat acum');
    }
    foreach ($phpVechi as $binar) {
        foreach (['tools/verificare/agregate.php' => ['--set=bioscem'], 'tools/sonda/amprenta-schema.php' => ['--site=bioscem']] as $script => $arg) {
            $r = $ruleaza($script, $arg, ['php' => trim((string) shell_exec('command -v ' . $binar))]);
            $verifica($binar . ': ' . basename($script) . ' spune că PHP-ul e prea vechi (cod 1, fără eroare de sintaxă)', $r['cod'] === 1
                && str_contains($r['erori'], 'mai vechi decât minimul') && !str_contains($r['tot'], 'Parse error'), $r['tot']);
        }
    }
    // --dosar=~/… ca în ghid: shell-ul nu înlocuiește „~” după „=”, deci îl primește scriptul.
    $r = $ruleaza('tools/verificare/agregate.php', ['--set=bioscem', '--dosar=~/domains/bioscem.ro/public_html', '--fara-fisier']);
    $verifica('--dosar=~/domains/bioscem.ro/public_html (cu „~” neînlocuit de shell) → găsit, cod 0, nimic scris', $r['cod'] === 0
        && str_contains($r['iesire'], 'dosarul domains/bioscem.ro/public_html') && str_contains($r['iesire'], 'Nimic scris'), mb_substr($r['tot'], 0, 600));
    $verifica('după refuzuri: nimic scris în site-ul de verificare', !is_dir($verif . '/storage'));

    // ── Instantaneele: dosarele site-urilor live și baza, înainte de orice rulare ──
    $exceptiiVerif = [$verif . '/storage'];
    $instantaneuInainte = $instantaneu($acasa, $exceptiiVerif);
    $sumeInainte = $sumeTabele($db);

    $scrie('');
    $scrie('B2. Fiecare set pe copia bazei de dezvoltare');
    $rulari1 = [];
    foreach (array_keys($live) as $set) {
        $r = $ruleaza('tools/verificare/agregate.php', ['--set=' . $set]);
        $rulari1[$set] = $parseaza($r['iesire']);
        $erori = array_keys(array_filter($rulari1[$set], static fn (array $v): bool => $v[0] === 'EROARE' || $v[0] === 'SĂRIT'));
        $verifica('setul ' . $set . ': cod 0, „GATA”, nicio EROARE, fără avertismente PHP, rezultatul în storage/verificare/sonde/', $r['cod'] === 0
            && str_contains($r['iesire'], 'GATA:') && $erori === [] && !preg_match('/(Warning|Notice|Deprecated|Fatal|AVERTISMENT PHP|OPRIT)/', $r['tot'])
            && count(glob($verif . '/storage/verificare/sonde/agregate-' . $set . '-*.txt') ?: []) === 1, implode(', ', $erori) . "\n" . mb_substr($r['tot'], -2000));
    }
    $verifica('pe baza unui site fără funcțiile lor, tabelele VT, GR, BV apar LIPSĂ (nu EROARE)', ($rulari1['vertij']['vt.donatii'][0] ?? '') === 'LIPSĂ'
        && ($rulari1['grafoanaytis']['g2.cereri_oferta'][0] ?? '') === 'LIPSĂ' && ($rulari1['biovitality']['bv.produse_pachet'][0] ?? '') === 'LIPSĂ');
    $verifica('domeniul cu www. (www.grafoanaytis.ro) e găsit; codul din packed-refs', ($rulari1['grafoanaytis']['cod.commit'][1] ?? '') === str_repeat('c', 40)
        && ($rulari1['grafoanaytis']['cod.ramura'][1] ?? '') === 'main');
    $verifica('codul Bioscem: ramura, commit-ul, amprenta lui public/index.php, lipsa celorlalte fișiere', ($rulari1['bioscem']['cod.ramura'][1] ?? '') === 'claude/ramura-test'
        && ($rulari1['bioscem']['cod.commit'][1] ?? '') === str_repeat('b', 40)
        && ($rulari1['bioscem']['cod.sha256.public/index.php'][1] ?? '') === substr(hash_file('sha256', $live['bioscem'] . '/public/index.php'), 0, 16)
        && ($rulari1['bioscem']['cod.sha256.bootstrap.php'][1] ?? '') === 'lipsește');
    $verifica('.env: doar DA/NU și valori din liste fixe (BT live, parola de instalare = ATENȚIE)', ($rulari1['bioscem']['env_cu_valoare.BT_IPAY_LIVE_PASS'][1] ?? '') === 'DA'
        && ($rulari1['bioscem']['env_cu_valoare.ADMIN_DEFAULT_PASSWORD'] ?? []) === ['ATENȚIE', 'DA'] && ($rulari1['bioscem']['env.BT_IPAY_MODE'][1] ?? '') === 'live'
        && ($rulari1['bioscem']['env.APP_DEBUG'][1] ?? '') === 'false');

    // ── B3. Rânduri inventate pentru fiecare verificare ──
    $scrie('');
    $scrie('B3. Rânduri inventate (toate datele personale cu marcajul ' . substr($m, 0, 3) . '…): fiecare număr se schimbă exact');
    $ins = static function (string $tabel, array $randuri) use ($db): int {
        $id = 0;
        foreach ($randuri as $rand) {
            $db->prepare('INSERT INTO `' . $tabel . '` (`' . implode('`, `', array_keys($rand)) . '`) VALUES (' . implode(', ', array_fill(0, count($rand), '?')) . ')')
                ->execute(array_values($rand));
            $id = (int) $db->lastInsertId();
        }
        return $id;
    };
    $hash = password_hash('x', PASSWORD_BCRYPT);
    // Parolele adminilor (WP-12b, App\Support\ParolaAdmin): câte una de fiecare fel, plus cazurile în care SQL-ul s-ar
    // putea despărți de regula din PHP (lungimea în octeți față de caractere, un spațiu față de gol, alte hash-uri: $6$,
    // $P$, litere mari; forma hash-ului fără nume de algoritm sau cu un nume care nu e ASCII; argon2 fără „$” în față,
    // pe care password_get_info îl recunoaște).
    $ins('admins', [['email' => 'admin1.' . $m . '@exemplu.ro', 'password_hash' => 'parola-' . $m], ['email' => 'admin2.' . $m . '@exemplu.ro', 'password_hash' => '$2a$' . substr($hash, 4)],
        ['email' => 'admin3.' . $m . '@exemplu.ro', 'password_hash' => ''],
        ['email' => 'admin4.' . $m . '@exemplu.ro', 'password_hash' => password_hash('x', PASSWORD_ARGON2ID)],
        ['email' => 'admin5.' . $m . '@exemplu.ro', 'password_hash' => '$2b$' . substr($hash, 4)],
        ['email' => 'admin6.' . $m . '@exemplu.ro', 'password_hash' => '$2y$10$scurt'],
        ['email' => 'admin7.' . $m . '@exemplu.ro', 'password_hash' => substr($hash, 0, 59) . 'ă'],
        ['email' => 'admin8.' . $m . '@exemplu.ro', 'password_hash' => '$6$rounds=5000$sare$' . str_repeat('a', 86)],
        ['email' => 'admin9.' . $m . '@exemplu.ro', 'password_hash' => ' '],
        ['email' => 'admin10.' . $m . '@exemplu.ro', 'password_hash' => '$P$B' . str_repeat('a', 30)],
        ['email' => 'admin11.' . $m . '@exemplu.ro', 'password_hash' => '$$dublu'],
        ['email' => 'admin12.' . $m . '@exemplu.ro', 'password_hash' => '$ARGON2ID$v=19$x'],
        ['email' => 'admin13.' . $m . '@exemplu.ro', 'password_hash' => '$ă$x'],
        // password_get_info nu se uită la primul caracter al unui argon2: fără „$” în față e un text, nu un hash.
        ['email' => 'admin14.' . $m . '@exemplu.ro', 'password_hash' => 'xargon2id$v=19$m=65536,t=4,p=1$Parola'],
        ['email' => 'admin15.' . $m . '@exemplu.ro', 'password_hash' => 'Xargon2i$v=19$m=1']]);
    $client = $ins('users', [['first_name' => 'Prenume' . $m, 'last_name' => 'Nume' . $m, 'email' => 'client.' . $m . '@exemplu.ro', 'phone' => $telefon, 'password_hash' => $hash]]);
    $bbd = json_encode([['date' => '2027-01-01', 'lot' => 'L1', 'price' => '10', 'stock' => 5, 'key' => 'abc123'], ['date' => '2027-02-01', 'lot' => 'L2', 'price' => '9', 'stock' => 5]]);
    $produsBbd = $ins('products', [['name' => 'Produs BBD test', 'slug' => 'produs-bbd-test', 'is_active' => 1, 'bbd_enabled' => 1, 'bbd_entries_json' => $bbd,
        'description' => '<div class="clasa-test-produs">Descriere</div>', 'price' => 10]]);
    $produsInactiv = $ins('products', [['name' => 'Produs inactiv test', 'slug' => 'produs-inactiv-test', 'is_active' => 0, 'price' => 5]]);
    $comanda = static fn (array $date): int => $ins('orders', [$date + ['billing_first_name' => 'Prenume' . $m, 'billing_last_name' => 'Nume' . $m,
        'billing_phone' => '0700000000', 'billing_address_line1' => 'Strada ' . $m . ' 1', 'billing_city' => 'Cluj-Napoca', 'billing_county' => 'Cluj',
        'billing_postcode' => '400000', 'notes' => 'notă ' . $m, 'subtotal' => 10, 'total' => 10]]);
    $db->exec("SET @acum = NOW()");
    $o1 = $comanda(['order_number' => 'ORD1-' . $m, 'user_id' => null, 'billing_email' => 'client.' . $m . '@exemplu.ro', 'status' => 'processing', 'erp_status' => 'pending',
        'payment_method' => 'cod', 'payment_status' => 'pending', 'created_at' => date('Y-m-d H:i:s', strtotime('-400 days'))]);
    $o2 = $comanda(['order_number' => 'ORD2-' . $m, 'user_id' => $client, 'billing_email' => 'client.' . $m . '@exemplu.ro', 'status' => 'completed', 'erp_status' => 'sent',
        'erp_synced_at' => '2025-03-11 10:00:00', 'payment_method' => 'cod', 'payment_status' => 'paid', 'created_at' => '2025-03-10 09:00:00']);
    $o3 = $comanda(['order_number' => 'ORD3-' . $m, 'user_id' => null, 'billing_email' => 'alt.' . $m . '@exemplu.ro', 'status' => 'processing', 'erp_status' => 'failed',
        'payment_method' => 'stripe', 'payment_status' => 'pending', 'fan_awb' => 'DEMO' . $m, 'coupon_code' => 'COD ' . $M, 'created_at' => date('Y-m-d H:i:s', strtotime('-5 days'))]);
    $o4 = $comanda(['order_number' => 'ORD4-' . $m, 'user_id' => null, 'billing_email' => 'alt2.' . $m . '@exemplu.ro', 'status' => 'completed', 'erp_status' => 'pending',
        'payment_method' => 'cod', 'payment_status' => 'paid', 'created_at' => date('Y-m-d H:i:s', strtotime('-10 days'))]);
    $ins('order_items', [['order_id' => $o3, 'product_id' => $produsBbd, 'product_name' => 'Produs BBD test', 'bbd_key' => 'cheiedisparuta', 'quantity' => 1, 'unit_price' => 9, 'line_total' => 9],
        ['order_id' => $o3, 'product_id' => $produsBbd, 'product_name' => 'Produs BBD test', 'bbd_key' => 'abc123', 'quantity' => 1, 'unit_price' => 10, 'line_total' => 10],
        ['order_id' => $o4, 'product_id' => $produsInactiv, 'product_name' => 'Produs inactiv test', 'quantity' => 1, 'unit_price' => 5, 'line_total' => 5],
        ['order_id' => $o2, 'product_id' => $produsInactiv, 'product_name' => 'Produs inactiv test', 'quantity' => 1, 'unit_price' => 5, 'line_total' => 5]]);
    $b1 = $ins('blog_posts', [['title' => 'Titlu ' . $m, 'slug' => 'Slug-Mare-Test', 'content' => '<h2>a</h2><p>' . $m . '</p><h3>b</h3>', 'category' => 'Categorie text ' . $m,
        'category_id' => null, 'is_published' => 1, 'published_at' => date('Y-m-d H:i:s')]]);
    $b2 = $ins('blog_posts', [['title' => 'Titlu 2', 'slug' => 'articol-test-categorie', 'content' => 'x', 'category' => 'Evenimente', 'category_id' => null, 'is_published' => 1,
        'published_at' => date('Y-m-d H:i:s')]]);
    $b3 = $ins('blog_posts', [['title' => 'Titlu 3', 'slug' => 'articol-test-doua-categorii', 'content' => 'x', 'category_id' => 2, 'is_published' => 0, 'published_at' => date('Y-m-d H:i:s')]]);
    $ins('blog_post_categories', [['post_id' => $b3, 'category_id' => 2], ['post_id' => $b3, 'category_id' => 4]]);
    $ins('blog_templates', [['name' => 'Șablon test', 'slug' => 'sablon-test', 'html_content' => '<ul data-blog-related-list="1"></ul>',
        'js_content' => '$(".lista").addClass("clasa-test-js-sablon");']]);
    $camp = $ins('product_extra_fields', [['name' => 'Câmp html', 'field_key' => 'camp_html_test', 'field_type' => 'html']]);
    $ins('product_extra_field_values', [['product_id' => $produsBbd, 'field_id' => $camp, 'value' => '<b>' . $m . '</b>']]);
    $ins('coupons', [['name' => 'Cupon cu spațiu', 'code' => 'COD ' . $M, 'is_active' => 1], ['name' => 'Cupon compact', 'code' => 'COD' . $M, 'is_active' => 1]]);
    $ins('pages', [['title' => 'Catalog', 'slug' => 'pagina-catalog-test', 'html_content' => '<div class="clasa-test-pagina">{{shop_catalog}}</div>', 'is_published' => 1,
        'js_content' => "document.querySelectorAll('.clasa-test-pagina').forEach(function (el) { el.classList.add('clasa-test-js-pagina'); });"],
        ['title' => 'Nepublicată', 'slug' => 'pagina-nepublicata-test', 'html_content' => '{{shop_catalog}} {{an}}', 'is_published' => 0],
        ['title' => 'Majuscule', 'slug' => 'Pagina-Cu-Majuscule', 'html_content' => '<p>' . $m . '</p>', 'is_published' => 1]]);
    $setare = static fn (string $cheie, string $valoare) => $db->prepare('REPLACE INTO settings (`key`, `value`) VALUES (?, ?)')->execute([$cheie, $valoare]);
    $setare('design_header_html', '<header class="clasa-test-antet">© {{an}}</header>');
    $setare('design_header_css', '.clasa-test-css{color:red}');
    $setare('design_header_js', "var potrivit = document.querySelector('.clasa-test-js-selector'); if (potrivit) { potrivit.classList.toggle('clasa-test-js-antet'); }");
    $setare('stripe_secret_key', 'sk_live_' . $m);
    $setare('tawk_property_id', 'tawk-' . $m);
    $ins('gallery_images', [['title' => 'Document ' . $m, 'media_type' => 'document', 'image_url' => '/uploads/' . $m . '.pdf']]);
    $ins('contact_form_messages', [['name' => 'Nume ' . $m, 'email' => 'contact.' . $m . '@exemplu.ro', 'phone' => $telefon, 'subject' => 'Subiect ' . $m, 'message' => 'Mesaj ' . $m,
        'created_at' => date('Y-m-d H:i:s')]]);
    $ins('newsletter_subscribers', [['email' => 'abonat.' . $m . '@exemplu.ro', 'name' => 'Abonat ' . $m, 'status' => 'active']]);
    $ins('product_reviews', [['product_id' => $produsBbd, 'user_name' => 'Recenzent ' . $m, 'user_email' => 'recenzent.' . $m . '@exemplu.ro', 'rating' => 5,
        'review_text' => 'Text ' . $m, 'is_approved' => 0]]);
    // Tabelul vechi de recenzii (îl are baza „live”, făcută de codul de dinainte de migrări; o instalare nouă nu-l mai
    // primește, recenzii/0013, iar 0023 îl copiază o dată, la adoptare, în product_reviews): un rând necopiat încă.
    $ins('reviews', [['product_id' => $produsBbd, 'user_name' => 'Vechi ' . $m, 'rating' => 4, 'review_text' => 'Vechi ' . $m, 'is_approved' => 1, 'created_at' => '2024-01-01 10:00:00']]);
    $ins('fan_streets', [['county' => 'Cluj', 'locality' => 'Cluj-Napoca', 'street' => 'Strada test', 'county_norm' => 'cluj', 'locality_norm' => 'cluj-napoca',
        'street_norm' => 'strada test', 'row_key' => '', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]]);
    $ins('order_payment_links', [['order_id' => $o3, 'referinta' => 'REF-' . $m, 'token' => 'tok' . $m, 'amount' => 10, 'status' => 'pending', 'procesator' => 'stripe']]);
    $ins('email_send_history', [['email_type' => 'test', 'recipient' => 'dest.' . $m . '@exemplu.ro', 'subject' => 'Subiect ' . $m, 'status' => 'failed']]);
    // GR: cererile de ofertă (DDL-ul GR) și ordinea manuală.
    $db->exec('CREATE TABLE quote_requests (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NULL, name VARCHAR(160) NOT NULL,
        email VARCHAR(190) NOT NULL, phone VARCHAR(60) NULL, message TEXT NULL, status VARCHAR(20) NOT NULL DEFAULT "noua",
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL, INDEX idx_quote_status (status))');
    $ins('quote_requests', [['name' => 'Cerere ' . $m, 'email' => 'cerere.' . $m . '@exemplu.ro', 'phone' => $telefon, 'message' => 'Vreau ' . $m],
        ['name' => 'Cerere2 ' . $m, 'email' => 'cerere2.' . $m . '@exemplu.ro', 'message' => 'x'],
        ['name' => 'Cerere3 ' . $m, 'email' => 'cerere3.' . $m . '@exemplu.ro', 'status' => 'rezolvata', 'deleted_at' => date('Y-m-d H:i:s')]]);
    $db->exec('ALTER TABLE products ADD COLUMN sort_order INT NOT NULL DEFAULT 0');
    $db->exec('UPDATE products SET sort_order = 5 WHERE id = ' . $produsBbd);
    // VT: donațiile, membrii, verificările.
    $db->exec("CREATE TABLE donations (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(40) NOT NULL UNIQUE, donor_name VARCHAR(190) NULL,
        donor_email VARCHAR(190) NULL, amount_cents INT UNSIGNED NOT NULL DEFAULT 0, frequency ENUM('once','monthly') NOT NULL DEFAULT 'once',
        status VARCHAR(30) NOT NULL DEFAULT 'pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $ins('donations', [['reference' => 'D1' . $m, 'donor_name' => 'Donator ' . $m, 'donor_email' => 'd1.' . $m . '@exemplu.ro', 'amount_cents' => 5000, 'frequency' => 'monthly', 'status' => 'paid'],
        ['reference' => 'D2' . $m, 'donor_name' => 'Donator ' . $m, 'donor_email' => 'd2.' . $m . '@exemplu.ro', 'amount_cents' => 5000, 'frequency' => 'monthly', 'status' => 'paid'],
        ['reference' => 'D3' . $m, 'donor_name' => 'Donator ' . $m, 'amount_cents' => 100, 'frequency' => 'once', 'status' => 'pending']]);
    $db->exec("ALTER TABLE users ADD COLUMN account_status VARCHAR(20) NOT NULL DEFAULT 'none', ADD COLUMN account_active_until DATETIME NULL");
    $db->exec("UPDATE users SET account_status = 'active', account_active_until = NOW() + INTERVAL 30 DAY WHERE id = " . $client);
    $db->exec('CREATE TABLE professions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1)');
    $ins('professions', [['name' => 'Medic'], ['name' => 'Veche', 'is_active' => 0]]);
    $db->exec("CREATE TABLE account_verifications (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'awaiting_email',
        answers_json LONGTEXT NULL, submitted_ip VARCHAR(64) NULL)");
    $ins('account_verifications', [['user_id' => $client, 'status' => 'pending', 'answers_json' => '{"nume":"' . $m . '"}', 'submitted_ip' => '203.0.113.9'],
        ['user_id' => $client, 'status' => 'approved']]);
    $db->exec('CREATE TABLE account_verification_documents (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, verification_id INT UNSIGNED NOT NULL, original_name VARCHAR(255) NOT NULL)');
    $ins('account_verification_documents', [['verification_id' => 1, 'original_name' => 'buletin-' . $m . '.pdf']]);
    $db->exec("CREATE TABLE account_activations (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending')");
    $ins('account_activations', [['user_id' => $client, 'status' => 'paid']]);
    $db->exec('ALTER TABLE pages ADD COLUMN requires_active_account TINYINT(1) NOT NULL DEFAULT 0');
    $db->exec('UPDATE pages SET requires_active_account = 1 WHERE id = 1');
    $db->exec('ALTER TABLE blog_posts ADD COLUMN requires_active_account TINYINT(1) NOT NULL DEFAULT 0');
    $db->exec('UPDATE blog_posts SET requires_active_account = 1 WHERE id = ' . $b1);
    // BV: pachete, transport gratuit, aplicația de birou, etichete, WordPress.
    $db->exec('ALTER TABLE products ADD COLUMN bundle_items_json LONGTEXT NULL, ADD COLUMN free_shipping TINYINT(1) NOT NULL DEFAULT 0,
        ADD COLUMN hidden_in_catalog TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN sort_position INT NULL');
    $db->exec("UPDATE products SET bundle_items_json = '[{\"id\":1}]', free_shipping = 1 WHERE id = " . $produsBbd);
    $db->exec('UPDATE products SET hidden_in_catalog = 1, sort_position = 3 WHERE id = ' . $produsInactiv);
    $db->exec('ALTER TABLE product_extra_fields ADD COLUMN template_only TINYINT(1) NOT NULL DEFAULT 0');
    $db->exec('CREATE TABLE admin_app_tokens (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, token_hash VARCHAR(64) NOT NULL, label VARCHAR(100) NULL)');
    $ins('admin_app_tokens', [['token_hash' => hash('sha256', $m), 'label' => 'Birou ' . $m]]);
    $db->exec('CREATE TABLE blog_tags (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL)');
    $ins('blog_tags', [['name' => 'a'], ['name' => 'b']]);
    $db->exec('ALTER TABLE blog_posts ADD COLUMN wp_post_id INT NULL');
    $db->exec('UPDATE blog_posts SET wp_post_id = 77 WHERE id = ' . $b2);
    // Obiecte vechi pentru extra-<cod>.txt.
    $db->exec('ALTER TABLE products ADD COLUMN label_popular TINYINT(1) NOT NULL DEFAULT 0');
    $db->exec('CREATE TABLE tabel_vechi_test (id INT PRIMARY KEY)');
    // Fișierele site-urilor: copii WordPress (numele au marcajul), documentele VT, pachetul GR.
    file_put_contents($live['bioscem'] . '/storage/import/export-' . $m . '.sql', 'x');
    @mkdir($live['bioscem'] . '/storage/import/wp', 0770, true);
    file_put_contents($live['bioscem'] . '/storage/import/wp/' . $m . '.xml', 'x');
    @mkdir($live['vertij'] . '/storage/account-documents/1', 0770, true);
    foreach ([1, 2, 3] as $i) {
        file_put_contents($live['vertij'] . '/storage/account-documents/1/' . $m . '-' . $i . '.pdf', 'x');
    }
    $acasaDb = $db->query("SELECT html_content AS h, css_content AS c, js_content AS j FROM pages WHERE slug = '' LIMIT 1")->fetch();
    @mkdir($live['grafoanaytis'] . '/database/pagini', 0770, true);
    @mkdir($live['grafoanaytis'] . '/database/design', 0770, true);
    file_put_contents($live['grafoanaytis'] . '/database/pagini/acasa.html', (string) $acasaDb['h']);
    foreach (['c' => 'css', 'j' => 'js'] as $coloana => $ext) {
        if ((string) $acasaDb[$coloana] !== '') {
            file_put_contents($live['grafoanaytis'] . '/database/pagini/acasa.' . $ext, (string) $acasaDb[$coloana]);
        }
    }
    file_put_contents($live['grafoanaytis'] . '/database/pagini/blog.html', '<p>altul</p>');
    file_put_contents($live['grafoanaytis'] . '/database/pagini/lipsa-test.html', '<p>x</p>');
    file_put_contents($live['grafoanaytis'] . '/database/design/antet.css', '.clasa-test-css{color:red}');

    // ── B10. Sonda pachetului (tools/sonda/pachet-site.php, V1–V12; DB-24) ──
    $scrie('');
    $scrie('B10. Sonda pachetului site-ului (V1–V12)');
    foreach (['Eroare', 'ParametruLipsa', 'Inghetare'] as $clasaMigrari) {
        require_once $radacina . '/src/Support/Migrari/' . $clasaMigrari . '.php';
    }
    $inghetareBs = \App\Support\Migrari\Inghetare::dinFisier($radacina . '/site-uri/bioscem/setari-inghetate.json');
    $cheiInghetate = array_keys($inghetareBs->setari());
    $privateBs = $inghetareBs->cheiPrivate();
    $sonda = static function () use ($ruleaza, $parseaza): array {
        $r = $ruleaza('tools/sonda/pachet-site.php', ['--site=bioscem']);
        return [$r, $parseaza($r['iesire'])];
    };
    [$rP1, $p1] = $sonda();
    $eroriP = array_keys(array_filter($p1, static fn (array $v): bool => in_array($v[0], ['EROARE', 'SĂRIT', 'LIPSĂ'], true)));
    $verifica('pachet-site --site=bioscem: cod 0, „GATA”, nicio EROARE/SĂRIT/LIPSĂ, fără avertismente PHP, rezultatul în storage/verificare/sonde/',
        $rP1['cod'] === 0 && str_contains($rP1['iesire'], 'GATA:') && $eroriP === [] && !preg_match('/(Warning|Notice|Deprecated|Fatal|AVERTISMENT PHP|OPRIT)/', $rP1['tot'])
        && count(glob($verif . '/storage/verificare/sonde/pachet-site-bioscem-*.txt') ?: []) === 1, implode(', ', $eroriP) . "\n" . mb_substr($rP1['tot'], -2000));
    $stariV1 = [];
    foreach ($cheiInghetate as $cheie) {
        $stariV1[$cheie] = $p1['v1.' . $cheie][1] ?? '?';
    }
    $totalV1 = array_sum(array_map(static fn (string $s): int => (int) ($p1['v1.total.' . $s][1] ?? 0), ['lipsa', 'gol', 'egal', 'diferit', 'nevid']));
    $verifica('V1: un rând pentru fiecare din cele ' . count($cheiInghetate) . ' chei înghețate, cu o stare din listă; totalurile adunate = numărul cheilor',
        array_diff($stariV1, ['lipsa', 'gol', 'egal', 'diferit', 'nevid']) === [] && $totalV1 === count($cheiInghetate), json_encode($stariV1));
    $verifica('V1: cheile private (' . count($privateBs) . ', fără fișierul privat) nu se compară: doar lipsa / gol / nevid',
        array_diff(array_intersect_key($stariV1, array_flip($privateBs)), ['lipsa', 'gol', 'nevid']) === []);
    $verifica('V12: pachetul site-uri/bioscem/ se verifică față de pachet.json', ($p1['v12.pachet_verificat'] ?? []) === ['OK', 'DA'] && ($p1['v12.probleme'][1] ?? '') === '0');

    // Schimbări cunoscute, apoi puse la loc: fiecare V se mișcă exact cât trebuie.
    $publice = array_values(array_filter($cheiInghetate, static fn (string $k): bool => !in_array($k, $privateBs, true)
        && preg_match('/(_password$|_secret|_api_key$|^smtp_)/', $k) !== 1));
    [$cheieEgala, $cheieDiferita, $cheieLipsa, $cheieGoala] = array_slice($publice, 0, 4);
    $valoareInghetata = static fn (string $k): string => (string) $inghetareBs->setari()[$k]['valoare'];
    $setariSalvate = [];
    foreach (array_merge([$cheieEgala, $cheieDiferita, $cheieLipsa, $cheieGoala], ['design_header_html', 'order_number_mode', 'order_number_next',
        'contact_form_recipients', 'order_email_from_name']) as $k) {
        $st = $db->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        $setariSalvate[$k] = $v === false ? null : (string) $v;
    }
    $puneSetare = static function (string $k, ?string $v) use ($db): void {
        if ($v === null) {
            $db->prepare('DELETE FROM settings WHERE `key` = ?')->execute([$k]);
        } else {
            $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute([$k, $v]);
        }
    };
    $puneSetare($cheieEgala, $valoareInghetata($cheieEgala));
    $puneSetare($cheieDiferita, $valoareInghetata($cheieDiferita) . '-altfel-' . $m);
    $puneSetare($cheieLipsa, null);
    $puneSetare($cheieGoala, '  ');
    $puneSetare('design_header_html', '<header class="x">{{menu}}{{mobile_menu}}</header>');
    $puneSetare('order_number_mode', 'sequential');
    $puneSetare('order_number_next', '1001');
    $puneSetare('contact_form_recipients', 'contact.' . $m . '@exemplu.ro, altul');
    $puneSetare('order_email_from_name', 'Expeditor ' . $m);
    // Paginile „404”, „checkout/succes” și „home”: una existentă (din datele demo) se nepublică sau se publică, una lipsă se
    // face publicată; delta așteptată după caz, apoi totul la loc.
    $paginiSalvate = [];
    $deltaPagini = [];
    $idsPaginiNoi = [];
    foreach (['404' => 'v5.pagini_404', 'checkout/succes' => 'v3.pagini_succes', 'home' => 'v2.pagini_acasa'] as $slugP => $cheieV) {
        $slugP = (string) $slugP; // cheia „404” e un întreg în PHP
        $rand = $db->query('SELECT id, is_published, deleted_at FROM pages WHERE slug = ' . $db->quote($slugP))->fetch();
        if (is_array($rand)) {
            $paginiSalvate[(int) $rand['id']] = $rand;
            $publicata = (int) $rand['is_published'] === 1 && $rand['deleted_at'] === null;
            $db->prepare('UPDATE pages SET is_published = ?, deleted_at = NULL WHERE id = ?')->execute([$publicata ? 0 : 1, (int) $rand['id']]);
            $deltaPagini[$cheieV] = $publicata ? -1 : 1;
        } else {
            $idsPaginiNoi[] = $ins('pages', [['title' => 'Test ' . $slugP, 'slug' => $slugP, 'html_content' => '<p>x</p>', 'is_published' => 1]]);
            $deltaPagini[$cheieV] = 1;
        }
    }
    $idsP = [
        'pages' => $idsPaginiNoi,
        'blog_posts' => [$ins('blog_posts', [['title' => 'Fără autor ' . $m, 'slug' => 'fara-autor-' . $m, 'content' => '<p>x</p>', 'is_published' => 1,
            'author_id' => null, 'category' => '', 'featured_image_url' => '/assets/img/product-placeholder.svg', 'published_at' => date('Y-m-d H:i:s')]])],
        'products' => [$ins('products', [['name' => 'Fără descriere ' . $m, 'slug' => 'fara-descriere-' . $m, 'price' => 1, 'is_active' => 1,
            'short_description' => '', 'image_url' => '/assets/img/product-placeholder.svg']])],
        'newsletter_campaigns' => [$ins('newsletter_campaigns', [['name' => 'Trimisă ' . $m, 'subject' => 'Subiect', 'html_content' => '<p>x</p>', 'status' => 'sent',
            'sent_at' => date('Y-m-d H:i:s', time() - 86400)]])],
    ];
    [$rP2, $p2] = $sonda();
    $delta = static fn (string $k): int => (int) ($p2[$k][1] ?? 0) - (int) ($p1[$k][1] ?? 0);
    $verifica('V1: egal / diferit / lipsa / gol pentru patru chei înghețate puse așa',
        ($p2['v1.' . $cheieEgala][1] ?? '') === 'egal' && ($p2['v1.' . $cheieDiferita][1] ?? '') === 'diferit'
        && ($p2['v1.' . $cheieLipsa][1] ?? '') === 'lipsa' && ($p2['v1.' . $cheieGoala][1] ?? '') === 'gol',
        json_encode(array_map(static fn (string $k): string => $k . '=' . ($p2['v1.' . $k][1] ?? '?'), [$cheieEgala, $cheieDiferita, $cheieLipsa, $cheieGoala])));
    $verifica('V2, V3, V5: paginile acasă/home, checkout/succes și 404 publicate sau nepublicate se văd exact (' . json_encode($deltaPagini) . ')',
        $delta('v2.pagini_acasa') === $deltaPagini['v2.pagini_acasa'] && $delta('v3.pagini_succes') === $deltaPagini['v3.pagini_succes']
        && $delta('v5.pagini_404') === $deltaPagini['v5.pagini_404'], $rP2['iesire']);
    $verifica('V4: antetul nevid, cu {{mobile_menu}}; niciun logo bioscem-logo.* în dosarul live', ($p2['v4.antet_nevid'][1] ?? '') === 'DA'
        && ($p2['v4.antet_cu_mobile_menu'][1] ?? '') === 'DA' && ($p2['v4.logo_png'][1] ?? '') === 'NU');
    $verifica('V6–V9: +1 articol fără categorie și fără autor, +1 produs fără descriere scurtă, +1 produs și +1 articol cu poza de rezervă, +1 campanie trimisă',
        $delta('v6.fara_categorie') === 1 && $delta('v6.fara_autor') === 1 && $delta('v7.fara_descriere_scurta') === 1
        && $delta('v8.produse_poza_rezerva') === 1 && $delta('v8.articole_poza_rezerva') === 1 && $delta('v9.campanii_trimise_365z') === 1, $rP2['iesire']);
    $verifica('V10, V11: numerotarea secvențială cu numărul următor, o adresă validă la contact, expeditorul nevid',
        ($p2['v10.order_number_mode_sequential'][1] ?? '') === 'DA' && ($p2['v10.order_number_next_exista'][1] ?? '') === 'DA'
        && ($p2['v11.contact_form_recipients_valid'][1] ?? '') === 'DA' && ($p2['v11.order_email_from_name_nevid'][1] ?? '') === 'DA');
    $verifica('pachet-site: nicio valoare de setare (niciun marcaj, nicio adresă de email) în ieșire',
        !str_contains($rP2['tot'], $m) && preg_match('/@[A-Za-z0-9-]+\./', $rP2['tot']) !== 1, $rP2['tot']);
    foreach ($idsP as $tabel => $ids) {
        foreach ($ids as $id) {
            $db->prepare('DELETE FROM `' . $tabel . '` WHERE id = ?')->execute([$id]);
        }
    }
    foreach ($setariSalvate as $k => $v) {
        $puneSetare($k, $v);
    }
    foreach ($paginiSalvate as $idP => $rand) {
        $db->prepare('UPDATE pages SET is_published = ?, deleted_at = ? WHERE id = ?')->execute([(int) $rand['is_published'], $rand['deleted_at'], $idP]);
    }
    [$rP3] = $sonda();
    $verifica('pachet-site după punerea la loc: același rezultat ca prima rulare (fără ora și durata)',
        preg_replace('/(Rulat: .*|REZUMAT: .*|GATA: .*)/', '', $rP3['iesire']) === preg_replace('/(Rulat: .*|REZUMAT: .*|GATA: .*)/', '', $rP1['iesire']));
    $refuz = $ruleaza('tools/sonda/pachet-site.php', ['--site=altul']);
    $refuzFara = $ruleaza('tools/sonda/pachet-site.php', ['--set=bioscem']);
    $verifica('pachet-site: un site necunoscut și --set (opțiunea agregatelor) refuzate cu 2', $refuz['cod'] === 2 && $refuzFara['cod'] === 2,
        $refuz['tot'] . $refuzFara['tot']);
    foreach (glob($verif . '/storage/verificare/sonde/pachet-site-*.txt') ?: [] as $fisierSonda) {
        unlink($fisierSonda);
    }

    $instantaneuInainte = $instantaneu($acasa, $exceptiiVerif);
    $sumeInainte = $sumeTabele($db);

    $rulari2 = [];
    $texte2 = [];
    foreach (array_keys($live) as $set) {
        $r = $ruleaza('tools/verificare/agregate.php', ['--set=' . $set]);
        $texte2[$set] = $r['iesire'];
        $rulari2[$set] = $parseaza($r['iesire']);
        $erori = array_keys(array_filter($rulari2[$set], static fn (array $v): bool => $v[0] === 'EROARE' || $v[0] === 'SĂRIT'));
        $verifica('setul ' . $set . ' cu rândurile inventate: cod 0, nicio EROARE', $r['cod'] === 0 && $erori === [] && !preg_match('/(Warning|Notice|Deprecated|Fatal|AVERTISMENT PHP|OPRIT)/', $r['tot']),
            implode(', ', $erori) . "\n" . mb_substr($r['tot'], -1500));
    }
    $valoare = static fn (array $rulare, string $cheie): ?string => $rulare[$cheie][1] ?? null;
    $numar = static fn (array $rulare, string $cheie): int => (int) ($rulare[$cheie][1] ?? 0);
    // [set, cheie, schimbarea] (delta față de rularea 1) sau [set, cheie, '=', valoarea] sau [set, cheie, 'are', element] (într-o listă).
    $asteptari = [
        ['bioscem', 'admini.total', 15], ['bioscem', 'admini.parola_necunoscuta', 6], ['bioscem', 'admini.parola_bcrypt_2a_2b', 2], ['bioscem', 'admini.parola_goala', 1],
        ['bioscem', 'admini.parola_hash_cunoscut', 1], ['bioscem', 'admini.parola_hash_stricat', 5],
        ['bioscem', 'erp.de_trimis.peste_1_an', 1], ['bioscem', 'erp.de_trimis.7_30_zile', 1], ['bioscem', 'erp.de_trimis.sub_7_zile', 0],
        ['bioscem', 'erp.status.pending', 2], ['bioscem', 'erp.status.failed', 1], ['bioscem', 'erp.status.sent', '=', '1'],
        ['bioscem', 'erp.trimisa_cea_mai_veche_comanda_luna', '=', '2025-03'], ['bioscem', 'erp.prima_sincronizare_luna', '=', '2025-03'],
        ['bioscem', 'import.fisiere', 2],
        ['bioscem', 'comenzi_fara_cont.total', 3], ['bioscem', 'comenzi_fara_cont.conturi_cu_acelasi_email', 1], ['bioscem', 'comenzi_fara_cont.pe_emailul_unui_cont', 1],
        ['bioscem', 'magazin.comenzi_total', 4], ['bioscem', 'magazin.comenzi_365_zile', 2], ['bioscem', 'plati.comenzi_365_zile.stripe', 1], ['bioscem', 'plati.comenzi_365_zile.cod', 1],
        ['bioscem', 'livrare_fan.awb_365_zile', 1], ['bioscem', 'cupoane.comenzi_365_zile', 1], ['bioscem', 'cupoane.active', 2],
        ['bioscem', 'link_plata.linkuri_365_zile.stripe', '=', '1'], ['bioscem', 'chat_live.cod_tawk_setat', '=', 'DA'],
        ['bioscem', 'recenzii.total', 1], ['bioscem', 'recenzii.aprobate', 0], ['bioscem', 'recenzii.365_zile', 1], ['bioscem', 'oferte_bbd.produse', 1],
        ['bioscem', 'conturi_clienti.total', 1], ['bioscem', 'blog.articole_publicate', 2], ['bioscem', 'blog.articole_total', 3],
        ['bioscem', 'newsletter.abonati.active', 1], ['bioscem', 'contact.mesaje_total', 1], ['bioscem', 'contact.mesaje_365_zile', 1],
        ['bioscem', 'produse.active', 1], ['bioscem', 'produse.inactive', 1], ['bioscem', 'galerie.document', '=', '1'],
        ['bioscem', 'pagini.publicate', 2], ['bioscem', 'pagini.nepublicate', 1], ['bioscem', 'pagini.sluguri_publicate', 'are', 'pagina-catalog-test'],
        ['bioscem', 'pagini.sluguri_publicate_altfel', 1], ['bioscem', 'pagini.cu_token.shop_catalog', 2], ['bioscem', 'pagini.cu_token.an', 1],
        ['bioscem', 'emailuri.30_zile.failed', '=', '1'],
        ['bioscem', 'css.clasa-test-produs', '=', '1'], ['bioscem', 'css.clasa-test-pagina', '=', '1'], ['bioscem', 'css.clasa-test-antet', '=', '1'],
        ['bioscem', 'css.clasa-test-css', '=', '1'], ['bioscem', 'css.clasa-test-js-pagina', '=', '1'], ['bioscem', 'css.clasa-test-js-antet', '=', '1'],
        ['bioscem', 'css.clasa-test-js-selector', '=', '1'], ['bioscem', 'css.clasa-test-js-sablon', '=', '1'], ['bioscem', 'css.randuri_citite.design', 3],
        ['bioscem', 'v1.produse_cu_oferte_bbd', 1], ['bioscem', 'v1.produse_cu_o_oferta_fara_cheie', 1], ['bioscem', 'v1.chei_rezervate_fara_oferta_curenta', 1],
        ['bioscem', 'v1.linii_de_comanda_pe_chei_disparute', 1],
        ['bioscem', 'v2.produse_inactive_comandate_90_zile', 1], ['bioscem', 'v2.id_produse', 'are', (string) $produsInactiv],
        ['bioscem', 'v3a.sluguri_care_s_ar_schimba', 1], ['bioscem', 'v3b.articole_cu_2_titluri_h2_h3', 1], ['bioscem', 'v3c.sabloane_cu_articole_inrudite', 1],
        ['bioscem', 'v3d.articole_cu_mai_multe_categorii', 1], ['bioscem', 'v3e.articole_cu_categoria_doar_text', 2], ['bioscem', 'v3e.din_ele_cu_nume_de_categorie_existent', 1],
        ['bioscem', 'v4.campuri_html', 1], ['bioscem', 'v4.campuri_html_cu_marcaj', 1], ['bioscem', 'v4.produse_cu_marcaj_in_campuri_html', 1], ['bioscem', 'v4.valori_cu_marcaj', 1],
        ['bioscem', 'v5.coduri_cu_spatii', 1], ['bioscem', 'v5.coduri_active_cu_spatii_egale_fara_spatii_cu_alt_cod', 1],
        ['bioscem', 'bs_v1.pagini_publicate', 1], ['bioscem', 'bs_v1.pagini_nepublicate', 1], ['bioscem', 'bs_v1.sluguri', 'are', 'pagina-catalog-test'],
        ['bioscem', 'bs_v2.antet_are_an', '=', 'DA'], ['bioscem', 'bs_v2.subsol_are_an', '=', 'NU'],
        ['grafoanaytis', 'g2.cereri_oferta.total', '=', '2'], ['grafoanaytis', 'g2.cereri_oferta.sterse', '=', '1'], ['grafoanaytis', 'g2.cereri_oferta.stare.noua', '=', '2'],
        ['grafoanaytis', 'g2.cereri_oferta.365_zile', '=', '3'], ['grafoanaytis', 'g2.produse_cu_ordine_manuala', '=', '1'],
        ['grafoanaytis', 'g5.pagina.acasa.html', '=', 'egal'], ['grafoanaytis', 'g5.pagina.acasa.css', '=', 'egal'], ['grafoanaytis', 'g5.pagina.acasa.js', '=', 'egal'],
        ['grafoanaytis', 'g5.pagina.blog.html', '=', 'diferit'], ['grafoanaytis', 'g5.pagina.lipsa-test.html', '=', 'lipsă din bază'],
        ['grafoanaytis', 'g5.design.design_header_css', '=', 'egal'],
        ['grafoanaytis', 'g5.design.design_header_html', '=', substr(hash('sha256', '<header class="clasa-test-antet">© {{an}}</header>'), 0, 16)],
        ['biovitality', 'v7.chei_setari', 'are', 'tawk_property_id'], ['biovitality', 'bv.produse_pachet', '=', '1'], ['biovitality', 'bv.comenzi_365_zile_cu_pachet', '=', '1'],
        ['biovitality', 'bv.produse_transport_gratuit', '=', '1'], ['biovitality', 'bv.produse_ascunse_din_catalog', '=', '1'], ['biovitality', 'bv.produse_cu_ordine_manuala', '=', '1'],
        ['biovitality', 'bv.campuri_doar_sablon', '=', '0'], ['biovitality', 'bv.randuri.admin_app_tokens', '=', '1'], ['biovitality', 'bv.randuri.blog_tags', '=', '2'],
        ['biovitality', 'bv.articole_importate_wordpress', '=', '1'],
        ['vertij', 'vt.donatii.total', '=', '3'], ['vertij', 'vt.donatii.paid.monthly', '=', '2'], ['vertij', 'vt.donatii.pending.once', '=', '1'],
        ['vertij', 'vt.donatii.365_zile', '=', '3'], ['vertij', 'vt.membri.activi', '=', '1'], ['vertij', 'vt.conturi.active', '=', '1'],
        ['vertij', 'vt.profesii_active', '=', '1'], ['vertij', 'vt.verificari.pending', '=', '1'], ['vertij', 'vt.verificari.approved', '=', '1'],
        ['vertij', 'vt.verificari.documente', '=', '1'], ['vertij', 'vt.activari.paid', '=', '1'], ['vertij', 'vt.pagini_doar_membri', '=', '1'],
        ['vertij', 'vt.articole_doar_membri', '=', '1'], ['vertij', 'vt.fisiere_documente', '=', '3'],
    ];
    foreach ($asteptari as $a) {
        [$set, $cheie] = $a;
        $acum = $valoare($rulari2[$set], $cheie);
        if (count($a) === 3) {
            $ok = $acum !== null && $numar($rulari2[$set], $cheie) - $numar($rulari1[$set], $cheie) === $a[2];
            $verifica($set . ' ' . $cheie . ': +' . $a[2], $ok, 'înainte ' . ($valoare($rulari1[$set], $cheie) ?? 'lipsă') . ', acum ' . ($acum ?? 'lipsă'));
        } elseif ($a[2] === '=') {
            $verifica($set . ' ' . $cheie . ' = ' . $a[3], $acum === $a[3], 'acum ' . ($acum ?? 'lipsă'));
        } else {
            $elemente = $acum === null ? [] : preg_split('/, | /', (string) preg_replace('/^\[\d+\] /', '', $acum));
            $verifica($set . ' ' . $cheie . ' conține ' . $a[3], in_array($a[3], $elemente ?: [], true), 'acum ' . ($acum ?? 'lipsă'));
        }
    }
    $verifica('o valoare neașteptată e ATENȚIE (parolă în clar, hash stricat, coduri care se ciocnesc), restul INFO/OK',
        ($rulari2['bioscem']['admini.parola_necunoscuta'][0] ?? '') === 'ATENȚIE' && ($rulari2['bioscem']['v5.coduri_active_cu_spatii_egale_fara_spatii_cu_alt_cod'][0] ?? '') === 'ATENȚIE'
        && ($rulari2['bioscem']['admini.parola_hash_stricat'][0] ?? '') === 'ATENȚIE'
        && ($rulari1['bioscem']['admini.parola_necunoscuta'][0] ?? '') === 'OK' && ($rulari1['bioscem']['admini.parola_hash_stricat'][0] ?? '') === 'OK');
    // Numărătoarea parolelor e chiar regula logării (WP-12b): pe toate rândurile din admins (și ale datelor demo), fiecare
    // număr al setului = câte valori pune App\Support\ParolaAdmin::fel în felul lui. Așa „parola_necunoscuta” = exact
    // adminii pe care Auth::attempt îi compară în clar, numărul care trebuie să fie 0 înainte de MIG-11.
    $dupaFel = array_fill_keys([ParolaAdmin::HASH, ParolaAdmin::BCRYPT_VECHI, ParolaAdmin::HASH_STRICAT, ParolaAdmin::GOL, ParolaAdmin::TEXT_CLAR], 0);
    foreach ($db->query('SELECT password_hash FROM admins')->fetchAll(PDO::FETCH_COLUMN) as $valoareParola) {
        $dupaFel[ParolaAdmin::fel((string) $valoareParola)]++;
    }
    $dinSet = [ParolaAdmin::HASH => 'admini.parola_hash_cunoscut', ParolaAdmin::BCRYPT_VECHI => 'admini.parola_bcrypt_2a_2b',
        ParolaAdmin::HASH_STRICAT => 'admini.parola_hash_stricat', ParolaAdmin::GOL => 'admini.parola_goala', ParolaAdmin::TEXT_CLAR => 'admini.parola_necunoscuta'];
    $dinSetNumere = array_map(static fn (string $cheie): int => $numar($rulari2['bioscem'], $cheie), $dinSet);
    $verifica('parolele adminilor: fiecare număr al setului = ParolaAdmin::fel pe aceleași ' . array_sum($dupaFel) . ' rânduri (regula logării), totalul la fel',
        $dinSetNumere === $dupaFel && $numar($rulari2['bioscem'], 'admini.total') === array_sum($dupaFel),
        'setul ' . json_encode($dinSetNumere) . ', ParolaAdmin ' . json_encode($dupaFel));
    $verifica('V-CSS la urmă, după verificările setului', ($pozCss = strpos($texte2['bioscem'], '== Clasele CSS')) !== false && $pozCss > (int) strpos($texte2['bioscem'], '== BS-V1'));

    // ── B4. Sesiunea doar de citire (în proces) ──
    $scrie('');
    $scrie('B4. Doar citire');
    $site = SiteLive::gaseste('bioscem', null, $acasa, $verif);
    $citire = CitireLive::deschide($site);
    $pdo = (new ReflectionProperty(CitireLive::class, 'db'))->getValue($citire);
    $refuzDeServer = static function (string $sql) use ($pdo): ?int {
        try {
            $pdo->exec($sql);
            return 0;
        } catch (PDOException $e) {
            return (int) ($e->errorInfo[1] ?? -1);
        }
    };
    $verifica('sesiunea: serverul refuză INSERT, UPDATE, CREATE TABLE, ALTER, CREATE TEMPORARY (1792)', $refuzDeServer("INSERT INTO settings (`key`, `value`) VALUES ('x', 'y')") === 1792
        && $refuzDeServer("UPDATE settings SET `value` = 'x' WHERE `key` = 'site_name'") === 1792 && $refuzDeServer('CREATE TABLE t_sonda (id INT)') === 1792
        && $refuzDeServer('ALTER TABLE settings ADD COLUMN c INT') === 1792 && $refuzDeServer('CREATE TEMPORARY TABLE t_sonda (id INT)') === 1792);
    $verifica('sesiunea: timpul pe instrucțiune (15 s) și 5 s după un lacăt', (int) $pdo->query('SELECT @@SESSION.max_statement_time')->fetchColumn() === CitireLive::TIMP_INSTRUCTIUNE
        && (int) $pdo->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn() === 5);
    $garda = static function (callable $fn): bool {
        try {
            $fn();
            return false;
        } catch (InstructiuneRefuzata) {
            return true;
        }
    };
    $verifica('garda: o scriere, o citire în afara tranzacției, o coloană cu date personale (email, phone, token, password_hash) sunt refuzate',
        $garda(static fn () => $citire->inTranzactie(static fn () => $citire->randuri("UPDATE settings SET `value` = 'x' LIMIT 1")))
        && $garda(static fn () => $citire->randuri('SELECT COUNT(*) FROM users LIMIT 1'))
        && $garda(static fn () => $citire->inTranzactie(static fn () => $citire->randuri('SELECT email FROM users LIMIT 1')))
        && $garda(static fn () => $citire->inTranzactie(static fn () => $citire->randuri('SELECT phone, id FROM users LIMIT 1')))
        && $garda(static fn () => $citire->inTranzactie(static fn () => $citire->randuri('SELECT token FROM order_payment_links LIMIT 1')))
        && $garda(static fn () => $citire->inTranzactie(static fn () => $citire->randuri('SELECT password_hash FROM admins LIMIT 1'))));
    $verifica('după grup, tranzacția e închisă (fără lacăte ținute): o citire în afara ei e refuzată din nou', $citire->inTranzactie(static fn () => $citire->numar('SELECT 1 LIMIT 1')) === 1
        && $garda(static fn () => $citire->numar('SELECT 1 LIMIT 1')));
    $idParcurse = [];
    $parcurs = $citire->inTranzactie(static function () use ($citire, &$idParcurse): array {
        $rezultat = $citire->parcurge('products', ['slug'], '', [], static function (array $r, int $id) use (&$idParcurse): void {
            $idParcurse[] = $id;
        }, 7);
        $rezultat['dupa'] = $citire->numar('SELECT COUNT(*) FROM products LIMIT 1');
        return $rezultat;
    });
    $toateId = array_map('intval', $db->query('SELECT id FROM products ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    $verifica('parcurge pe bucăți de 7 (fiecare în instantaneul ei): fiecare rând o dată, în ordine; citirea merge și după', $idParcurse === $toateId
        && $parcurs['randuri'] === count($toateId) && !$parcurs['partial'] && $parcurs['dupa'] === count($toateId));
    $parcurs = $citire->inTranzactie(static fn (): array => $citire->parcurge('products', ['slug'], '', [], static function (): void {
    }, 7, 10));
    $verifica('parcurge se oprește la maxim și spune că e parțial', $parcurs === ['randuri' => 10, 'partial' => true, 'prea_mari' => 0]);
    // Lacătul de metadate: în mijlocul unui grup, după o citire, un ALTER pe tabelul citit (din altă
    // conexiune, cu 1 s de așteptare) trece: lacătul s-a eliberat după instrucțiune, nu la capătul grupului.
    $db->exec('CREATE TABLE t_lacat_test (id INT PRIMARY KEY)');
    $alterInGrup = $citire->inTranzactie(static function () use ($citire, $conectare, $sockMaria, $baza): int {
        $citire->numar('SELECT COUNT(*) FROM t_lacat_test LIMIT 1');
        $alta = $conectare($sockMaria, $baza);
        $alta->exec('SET SESSION lock_wait_timeout = 1');
        try {
            $alta->exec("ALTER TABLE t_lacat_test COMMENT = 'schimbat'");
            $cod = 0;
        } catch (PDOException $e) {
            $cod = (int) ($e->errorInfo[1] ?? -1);
        }
        $citire->numar('SELECT COUNT(*) FROM t_lacat_test LIMIT 1');
        return $cod;
    });
    $db->exec('DROP TABLE t_lacat_test');
    $verifica('în mijlocul unui grup, un ALTER al site-ului pe un tabel deja citit nu așteaptă după noi (lacătul ținut doar cât instrucțiunea)',
        $alterInGrup === 0, 'eroarea ' . $alterInGrup . ' (1205 = a așteptat lacătul de metadate)');
    unset($citire, $pdo);
    $faraEroare = true;
    $detalii = '';
    foreach (['bioscem', 'grafoanaytis', 'biovitality', 'vertij'] as $set) {
        $dosarSet = $live[$set];
        // .env-ul cu utilizatorul doar SELECT, apoi pus la loc cu aceeași dată (instantaneul o compară).
        $envSet = (string) file_get_contents($dosarSet . '/.env');
        $dataEnv = (int) filemtime($dosarSet . '/.env');
        file_put_contents($dosarSet . '/.env', str_replace(['DB_USERNAME=' . $utilizatorTot, 'DB_PASSWORD=' . $parolaTot], ['DB_USERNAME=' . $utilizatorCitire, 'DB_PASSWORD=' . $parolaCitire], $envSet));
        $r = $ruleaza('tools/verificare/agregate.php', ['--set=' . $set, '--fara-fisier']);
        $rp = $ruleaza('tools/sonda/amprenta-schema.php', ['--site=' . $set, '--stdout']);
        file_put_contents($dosarSet . '/.env', $envSet);
        touch($dosarSet . '/.env', $dataEnv);
        $erori = array_keys(array_filter($parseaza($r['iesire']), static fn (array $v): bool => $v[0] === 'EROARE'));
        $json = json_decode($rp['iesire'], true);
        if ($r['cod'] !== 0 || $erori !== [] || $rp['cod'] !== 0 || !is_array($json) || ($json['erori'] ?? ['?']) !== []) {
            $faraEroare = false;
            $detalii .= $set . ': ' . implode(', ', $erori) . ' ' . mb_substr($r['erori'] . $rp['erori'], 0, 500) . "\n";
        }
        if ($set === 'bioscem' && is_array($json)) {
            $verifica('sonda cu utilizatorul doar SELECT: drepturile pe bază sunt exact [SELECT], fără nume de bază sau utilizator',
                ($json['privilegii']['baza'] ?? null) === ['SELECT'] && !str_contains($rp['iesire'], $utilizatorCitire), json_encode($json['privilegii'] ?? null) ?: '');
        }
    }
    $verifica('cu un utilizator care are doar SELECT, fiecare set și sonda rulează fără nicio EROARE (nu le trebuie alt drept)', $faraEroare, $detalii);

    // ── B5. Sonda ──
    $scrie('');
    $scrie('B5. Sonda schemei');
    $r = $ruleaza('tools/sonda/amprenta-schema.php', ['--site=bioscem']);
    $fisiereSonda = glob($verif . '/storage/verificare/sonde/amprenta-bioscem-*.json') ?: [];
    $verifica('sonda: cod 0, „GATA”, un fișier .json 0600 în storage/verificare/sonde/', $r['cod'] === 0 && str_contains($r['iesire'], 'GATA:') && count($fisiereSonda) === 1
        && (fileperms($fisiereSonda[0]) & 0777) === 0600 && !preg_match('/(Warning|Notice|Deprecated|Fatal|AVERTISMENT PHP|OPRIT)/', $r['tot']), $r['tot']);
    $sonda = $fisiereSonda !== [] ? json_decode((string) file_get_contents($fisiereSonda[0]), true) : null;
    $iesiri .= $fisiereSonda !== [] ? (string) file_get_contents($fisiereSonda[0]) : '';
    $serverDb = \App\Support\Migrari\Server::detecteaza($db);
    $amprentaDb = Amprenta::citeste($db, $serverDb);
    $verifica('sonda: amprenta din rândurile ei (Amprenta::normalizeaza) = amprenta bazei', is_array($sonda)
        && Amprenta::json(Amprenta::normalizeaza($sonda['brut'], $sonda['server']['familie'])) === Amprenta::json($amprentaDb));
    $exacte = true;
    foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tabel) {
        if (($sonda['randuri'][$tabel] ?? -1) !== (int) $db->query('SELECT COUNT(*) FROM `' . $tabel . '`')->fetchColumn()) {
            $exacte = false;
        }
    }
    $verifica('sonda: rândurile exacte ale fiecărui tabel', $exacte);
    $chei = $db->query('SELECT `key` FROM settings ORDER BY `key`')->fetchAll(PDO::FETCH_COLUMN);
    $verifica('sonda: numele cheilor din settings, fără valori', ($sonda['setari_chei']['chei'] ?? null) === $chei && !str_contains(json_encode($sonda) ?: '', 'sk_live_'));
    $verifica('sonda: agregatele (reviews vechi lipsă din product_reviews, străzi FAN fără cheie, linkuri pe procesator)',
        ($sonda['agregate']['reviews_lipsa_din_product_reviews'] ?? null) === 1 && ($sonda['agregate']['fan_streets_row_key_gol'] ?? null) === 1
        && ($sonda['agregate']['linkuri_plata_pe_procesator']['stripe'] ?? null) === 1, json_encode($sonda['agregate'] ?? null) ?: '');
    $verifica('sonda: serverul (familie, versiune, sql_mode), obiectele, drepturile globale/pe bază (ALL PRIVILEGES), codul site-ului',
        ($sonda['server']['familie'] ?? '') === 'mariadb' && ($sonda['server']['sql_mode'] ?? null) !== null && ($sonda['obiecte']['vederi'] ?? -1) === 0
        && in_array('ALL PRIVILEGES', $sonda['privilegii']['baza'] ?? [], true) && ($sonda['cod']['commit'] ?? '') === str_repeat('b', 40) && ($sonda['erori'] ?? ['?']) === []);
    $inainteStdout = $instantaneu($verif . '/storage');
    $r = $ruleaza('tools/sonda/amprenta-schema.php', ['--site=bioscem', '--stdout']);
    $verifica('sonda --stdout: JSON-ul pe ecran, nimic scris', $r['cod'] === 0 && is_array(json_decode($r['iesire'], true)) && $instantaneu($verif . '/storage') === $inainteStdout);
    $r = $ruleaza('tools/verificare/agregate.php', ['--set=vertij', '--fara-fisier']);
    $verifica('agregate --fara-fisier: nimic scris', $r['cod'] === 0 && str_contains($r['iesire'], 'Nimic scris') && $instantaneu($verif . '/storage') === $inainteStdout);

    // ── B6. din-sonda.php ──
    $scrie('');
    $scrie('B6. Schema refăcută din sondă (din-sonda.php)');
    $dosarPrivat = $dosarTest . '/privat';
    @mkdir($dosarPrivat, 0700, true);
    copy($fisiereSonda[0], $dosarPrivat . '/amprenta-bioscem.json');
    file_put_contents($dosarPrivat . '/referinta.json', Amprenta::json($amprentaInainte));
    $convertor = static function (array $argumente, ?string $dosarCurent = null) use ($radacina): array {
        $iesire = [];
        exec('cd ' . escapeshellarg($dosarCurent ?? $radacina) . ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($radacina . '/tools/dev/migrari/din-sonda.php')
            . ' ' . implode(' ', array_map('escapeshellarg', $argumente)) . ' 2>&1', $iesire, $cod);
        return ['cod' => $cod, 'iesire' => implode("\n", $iesire)];
    };
    $r = $convertor([$dosarPrivat . '/amprenta-bioscem.json', '--fata-de=' . $dosarPrivat . '/referinta.json', '--iesire=' . $dosarPrivat]);
    $sql = is_file($dosarPrivat . '/bs-live.sql') ? (string) file_get_contents($dosarPrivat . '/bs-live.sql') : '';
    $extra = is_file($dosarPrivat . '/extra-bs.txt') ? (string) file_get_contents($dosarPrivat . '/extra-bs.txt') : '';
    $verifica('din-sonda: cod 0, bs-live.sql (fără niciun INSERT), extra-bs.txt, bs-fata-de-asteptat.txt', $r['cod'] === 0 && str_contains($sql, 'CREATE TABLE `orders`')
        && stripos($sql, 'INSERT') === false && is_file($dosarPrivat . '/bs-fata-de-asteptat.txt'), $r['iesire']);
    $verifica('extra-bs.txt: obiectele în plus față de referință (coloane vechi, tabele de la scripturi, tabelele altor site-uri)',
        str_contains($extra, "coloana products.label_popular\n") && str_contains($extra, "tabelul tabel_vechi_test\n") && str_contains($extra, "tabelul quote_requests\n")
        && str_contains($extra, "coloana users.account_status\n"), $extra);
    foreach (['MariaDB' => $sockMaria, 'MySQL 8' => $sockMy8] as $numeServer => $sock) {
        if ($numeServer === 'MySQL 8' && !$merge($sock)) {
            exec(escapeshellarg($radacina . '/tools/dev/mysql8.sh') . ' porneste 2>&1', $iesireMy8, $codMy8);
            $mysql8Pornit = $codMy8 === 0 && $merge($sock);
            if (!$mysql8Pornit) {
                $verifica('MySQL 8 merge (tools/dev/mysql8.sh porneste)', false, implode("\n", $iesireMy8));
                continue;
            }
        }
        $rootServer = $conectare($sock);
        $rootServer->exec('DROP DATABASE IF EXISTS cms_sonde_rt');
        $rootServer->exec('CREATE DATABASE cms_sonde_rt CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $dbRt = $conectare($sock, 'cms_sonde_rt');
        $dbRt->exec($sql);
        $amprentaRt = Amprenta::citeste($dbRt, \App\Support\Migrari\Server::detecteaza($dbRt));
        $verifica($numeServer . ': schema refăcută se încarcă și are aceeași amprentă ca baza sondată', Amprenta::json($amprentaRt) === Amprenta::json($amprentaDb),
            implode("\n", array_slice(array_merge(...array_values(Amprenta::compara($amprentaDb, $amprentaRt))), 0, 10)));
        $rootServer->exec('DROP DATABASE cms_sonde_rt');
    }
    // Refuzurile convertorului: un fișier sau un dosar din repo neignorat de git.
    $neignorat = $radacina . '/tools/dev/sonda-test-' . $m . '.json';
    copy($fisiereSonda[0], $neignorat);
    $r1 = $convertor([$neignorat, '--iesire=' . $dosarPrivat]);
    unlink($neignorat);
    $r2 = $convertor([$dosarPrivat . '/amprenta-bioscem.json', '--iesire=' . $radacina . '/tools/dev']);
    file_put_contents($dosarPrivat . '/nu-e-sonda.json', '{"x":1}');
    $r3 = $convertor([$dosarPrivat . '/nu-e-sonda.json', '--iesire=' . $dosarPrivat]);
    $verifica('din-sonda refuză: intrarea din repo neignorată de git, ieșirea într-un dosar neignorat, un JSON care nu e al sondei', $r1['cod'] === 1 && $r2['cod'] === 1
        && $r3['cod'] === 1 && !is_file($radacina . '/tools/dev/bs-live.sql'), $r1['iesire'] . ' / ' . $r2['iesire'] . ' / ' . $r3['iesire']);
    // Aceleași refuzuri cu căi relative (față de dosarul curent): dosarul fixture-urilor din git, din rădăcină
    // și din tools/dev, un dosar nou din repo (nefăcut), o cale cu „..” printr-un dosar inexistent, intrarea relativă.
    $fisierSonda = $dosarPrivat . '/amprenta-bioscem.json';
    // Fixture-urile din git (WP-04: vechi/<cod>.sql, extra-<cod>.txt) stau chiar în dosarul refuzat: se compară
    // conținutul lor înainte și după (nu doar existența), iar ce era acolo nu se șterge niciodată.
    $dosarVechiGit = $radacina . '/tools/dev/migrari/vechi';
    $continutVechi = static function () use ($dosarVechiGit): array {
        $fisiere = [];
        foreach (is_dir($dosarVechiGit) ? array_diff(scandir($dosarVechiGit) ?: [], ['.', '..', 'privat']) : [] as $nume) {
            $fisiere[$dosarVechiGit . '/' . $nume] = is_file($dosarVechiGit . '/' . $nume) ? (string) file_get_contents($dosarVechiGit . '/' . $nume) : null;
        }
        return $fisiere;
    };
    $vechiInainte = $continutVechi();
    $relative = [
        'din rădăcină, --iesire=tools/dev/migrari/vechi' => $convertor([$fisierSonda, '--iesire=tools/dev/migrari/vechi']),
        'din tools/dev, --iesire=migrari/vechi' => $convertor([$fisierSonda, '--iesire=migrari/vechi'], $radacina . '/tools/dev'),
        'din rădăcină, --iesire=tools/dev/sonda-nou-…/sub' => $convertor([$fisierSonda, '--iesire=tools/dev/sonda-nou-' . $m . '/sub']),
        'din rădăcină, --iesire=tools/dev/x-…/../migrari/vechi' => $convertor([$fisierSonda, '--iesire=tools/dev/x-' . $m . '/../migrari/vechi']),
    ];
    copy($fisierSonda, $neignorat);
    $relative['intrarea relativă tools/dev/sonda-test-….json'] = $convertor(['tools/dev/sonda-test-' . $m . '.json', '--iesire=' . $dosarPrivat]);
    unlink($neignorat);
    $vechiDupa = $continutVechi();
    $lasate = array_values(array_merge(array_keys(array_diff_key($vechiDupa, $vechiInainte)),
        array_filter([$radacina . '/tools/dev/sonda-nou-' . $m, $radacina . '/tools/dev/x-' . $m], 'file_exists')));
    foreach ($lasate as $cale) {
        // Ce ar fi lăsat un convertor stricat nu rămâne în repo (ar putea intra într-un commit).
        exec('rm -rf ' . escapeshellarg($cale));
    }
    foreach ($vechiInainte as $cale => $continut) {
        if (!array_key_exists($cale, $vechiDupa) || $vechiDupa[$cale] !== $continut) {
            // Un fixture din git rescris sau șters: pus la loc, și testul pică.
            $lasate[] = $cale . ' (schimbat)';
            if ($continut !== null) {
                file_put_contents($cale, $continut);
            }
        }
    }
    $verifica('din-sonda refuză și cu căi relative (cod 1, nimic scris, niciun dosar făcut): ' . implode('; ', array_keys($relative)),
        array_filter($relative, static fn (array $r): bool => $r['cod'] !== 1) === [] && $lasate === [],
        implode(' / ', array_map(static fn (array $r): string => $r['cod'] . ': ' . $r['iesire'], $relative)) . ' lăsate: ' . implode(', ', $lasate));
    $rRelativ = $convertor(['privat/amprenta-bioscem.json', '--iesire=privat/relativ'], $dosarTest);
    $verifica('din-sonda cu căi relative în afara repo-ului (sau într-un dosar ignorat): scrie acolo', $rRelativ['cod'] === 0
        && is_file($dosarPrivat . '/relativ/bs-live.sql'), $rRelativ['iesire']);

    // ── B7. MySQL 8: aceeași bază, aceleași rânduri ──
    $scrie('');
    $scrie('B7. Pe MySQL 8');
    if ($merge($sockMy8)) {
        $rootMy8 = $conectare($sockMy8);
        $rootMy8->exec('DROP DATABASE IF EXISTS ' . $baza);
        $rootMy8->exec('CREATE DATABASE ' . $baza . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $rootMy8->exec("DROP USER IF EXISTS '" . $utilizatorTot . "'@'localhost'");
        $rootMy8->exec("CREATE USER '" . $utilizatorTot . "'@'localhost' IDENTIFIED BY " . $rootMy8->quote($parolaTot));
        $rootMy8->exec('GRANT ALL ON ' . $baza . ".* TO '" . $utilizatorTot . "'@'localhost'");
        $dbMy8 = $conectare($sockMy8, $baza);
        $dbMy8->exec($sql);
        $dbMy8->exec("SET SESSION sql_mode = ''");
        $db->exec("SET time_zone = '+00:00'");
        $dbMy8->exec("SET time_zone = '+00:00'");
        // Rândurile se copiază tabel cu tabel, în ordinea numelor, deci cheile străine ale schemei vechi (de exemplu
        // order_items → orders) sunt oprite cât durează copierea: sunt aceleași rânduri ca pe MariaDB, unde cheile
        // s-au verificat la scriere.
        $dbMy8->exec('SET SESSION foreign_key_checks = 0');
        foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tabel) {
            // Ce sare Amprenta (tabelele runner-ului, _platforma_*, pe care baza „live” de aici nu le are, dar le-ar avea un
            // site prins în mijlocul adoptării; tabelele de lucru ale serverului) nu e în sondă, deci nici în schema de pe
            // MySQL 8; verificările nu le citesc.
            if (Amprenta::ignorat((string) $tabel)) {
                continue;
            }
            $randuri = $db->query('SELECT * FROM `' . $tabel . '`')->fetchAll(PDO::FETCH_ASSOC);
            foreach (array_chunk($randuri, 200) as $bucata) {
                $coloane = array_keys($bucata[0]);
                $semne = '(' . implode(', ', array_fill(0, count($coloane), '?')) . ')';
                $dbMy8->prepare('INSERT INTO `' . $tabel . '` (`' . implode('`, `', $coloane) . '`) VALUES ' . implode(', ', array_fill(0, count($bucata), $semne)))
                    ->execute(array_merge(...array_map('array_values', $bucata)));
            }
        }
        $dbMy8->exec('SET SESSION foreign_key_checks = 1');
        $db->exec('SET time_zone = SYSTEM');
        $toateLaFel = true;
        $diferente = [];
        foreach (array_keys($live) as $set) {
            $r = $ruleaza('tools/verificare/agregate.php', ['--set=' . $set, '--fara-fisier'], ['sock' => $sockMy8]);
            $rulareMy8 = $parseaza($r['iesire']);
            if ($r['cod'] !== 0 || !str_contains($r['iesire'], 'baza MySQL')) {
                $toateLaFel = false;
                $diferente[] = $set . ': cod ' . $r['cod'] . ' ' . mb_substr($r['tot'], -800);
            }
            foreach ($rulari2[$set] + $rulareMy8 as $cheie => $_) {
                if (($rulari2[$set][$cheie] ?? null) !== ($rulareMy8[$cheie] ?? null)) {
                    $toateLaFel = false;
                    $diferente[] = $set . ' ' . $cheie . ': MariaDB ' . json_encode($rulari2[$set][$cheie] ?? null, JSON_UNESCAPED_UNICODE)
                        . ', MySQL ' . json_encode($rulareMy8[$cheie] ?? null, JSON_UNESCAPED_UNICODE);
                }
            }
        }
        $verifica('MySQL 8: fiecare set dă exact aceleași rânduri ca pe MariaDB', $toateLaFel, implode("\n", array_slice($diferente, 0, 20)));
        $r = $ruleaza('tools/sonda/amprenta-schema.php', ['--site=bioscem', '--stdout'], ['sock' => $sockMy8]);
        $sondaMy8 = json_decode($r['iesire'], true);
        $verifica('MySQL 8: sonda rulează, familia mysql, amprenta din rândurile ei = a bazei MySQL', $r['cod'] === 0 && is_array($sondaMy8)
            && ($sondaMy8['server']['familie'] ?? '') === 'mysql' && Amprenta::json(Amprenta::normalizeaza($sondaMy8['brut'], 'mysql'))
                === Amprenta::json(Amprenta::citeste($dbMy8, \App\Support\Migrari\Server::detecteaza($dbMy8))), mb_substr($r['erori'], 0, 500));
        $sesiuneMy8 = $ruleaza('tools/verificare/agregate.php', ['--set=bioscem', '--fara-fisier', '--buget=0'], ['sock' => $sockMy8]);
        $verifica('MySQL 8: sesiunea doar de citire se pregătește (max_execution_time, transaction_read_only)', str_contains($sesiuneMy8['iesire'], 'sesiune doar de citire'));
        unset($dbMy8);
        $rootMy8->exec('DROP DATABASE ' . $baza);
        $rootMy8->exec("DROP USER '" . $utilizatorTot . "'@'localhost'");
    } else {
        $verifica('MySQL 8 merge', false, 'fără MySQL 8 testul e parțial');
    }

    // ── B8. Bugetul ──
    $scrie('');
    $scrie('B8. Bugetul de timp');
    $r = $ruleaza('tools/verificare/agregate.php', ['--set=bioscem', '--fara-fisier', '--buget=0']);
    $sarite = array_filter($parseaza($r['iesire']), static fn (array $v): bool => $v[0] === 'SĂRIT');
    $verifica('--buget=0: fiecare grup SĂRIT, „PARȚIAL”, cod 1', $r['cod'] === 1 && count($sarite) >= 15 && str_contains($r['iesire'], 'PARȚIAL'), $r['tot']);

    // ── B9. Texte lungi: memoria PHP a contului ──
    $scrie('');
    $scrie('B9. Texte lungi în conținut, cu memoria PHP mică');
    $randuriBlog = $numar($parseaza($ruleaza('tools/verificare/agregate.php', ['--set=vertij', '--fara-fisier'])['iesire']), 'css.randuri_citite.blog');
    $idMari = [];
    $umplutura = str_repeat('x', 1048576);
    for ($i = 0; $i < 40; $i++) {
        $idMari[] = $ins('blog_posts', [['title' => 'Lung ' . $i, 'slug' => 'articol-lung-' . $i, 'content' => '<div class="clasa-lunga-' . $i . '">' . $umplutura . '</div>',
            'is_published' => 0, 'published_at' => date('Y-m-d H:i:s')]]);
    }
    unset($umplutura);
    $idMari[] = $ins('blog_posts', [['title' => 'Prea lung', 'slug' => 'articol-prea-lung', 'content' => '<div class="clasa-prea-lunga">' . str_repeat('y', 9 * 1048576) . '</div>',
        'is_published' => 0, 'published_at' => date('Y-m-d H:i:s')]]);
    $r = $ruleaza('tools/verificare/agregate.php', ['--set=vertij', '--fara-fisier'], ['ini' => ['memory_limit' => '16M']]);
    $rulare = $parseaza($r['iesire']);
    $verifica('40 de articole de 1 MB și unul de 9 MB, cu memory_limit=16M: „GATA”, cele 40 citite pe bucăți, cel de 9 MB doar numărat (ATENȚIE)', $r['cod'] === 0
        && str_contains($r['iesire'], 'GATA:') && !preg_match('/(Warning|Fatal|AVERTISMENT PHP|OPRIT)/', $r['tot'])
        && $numar($rulare, 'css.randuri_citite.blog') === $randuriBlog + 40 && ($rulare['css.clasa-lunga-0'][1] ?? '') === '1'
        && ($rulare['css.clasa-lunga-39'][1] ?? '') === '1' && !isset($rulare['css.clasa-prea-lunga'])
        && ($rulare['css.randuri_prea_mari.blog'] ?? []) === ['ATENȚIE', '1'], mb_substr($r['tot'], -1500));
    // Un articol de 7,5 MB (sub limita de 8 MB, dar peste memoria PHP rămasă la 16M): nu se citește,
    // se numără; memoria nu se termină în mijlocul citirii (închiderea conexiunii s-ar agăța).
    $idMari[] = $ins('blog_posts', [['title' => 'Aproape prea lung', 'slug' => 'articol-aproape-prea-lung',
        'content' => '<div class="clasa-aproape">' . str_repeat('z', 7 * 1048576 + 524288) . '</div>', 'is_published' => 0, 'published_at' => date('Y-m-d H:i:s')]]);
    $r = $ruleaza('tools/verificare/agregate.php', ['--set=vertij', '--fara-fisier'], ['ini' => ['memory_limit' => '16M'], 'timp' => 120]);
    $rulare = $parseaza($r['iesire']);
    $verifica('un articol de 7,5 MB peste memoria rămasă (memory_limit=16M): necitit, numărat (ATENȚIE, 2 rânduri), „GATA”, cod 0', $r['cod'] === 0
        && str_contains($r['iesire'], 'GATA:') && !preg_match('/(Warning|Fatal|AVERTISMENT PHP|OPRIT)/', $r['tot']) && !isset($rulare['css.clasa-aproape'])
        && ($rulare['css.randuri_prea_mari.blog'] ?? []) === ['ATENȚIE', '2'] && ($rulare['css.clasa-lunga-39'][1] ?? '') === '1', mb_substr($r['tot'], -1500));
    // Un articol de 3,3 MB cu 300.000 de atribute class: se citește, dar prelucrarea lui termină memoria
    // (memory_limit=32M): „OPRIT”, cod 1, verificările de până acolo scrise, fără mesajul PHP-ului.
    $idMari[] = $ins('blog_posts', [['title' => 'Multe clase', 'slug' => 'articol-multe-clase', 'content' => str_repeat('<i class=c>', 300000),
        'is_published' => 0, 'published_at' => date('Y-m-d H:i:s')]]);
    $fisiereInainte = glob($verif . '/storage/verificare/sonde/agregate-vertij-*.txt') ?: [];
    sleep(1);
    $r = $ruleaza('tools/verificare/agregate.php', ['--set=vertij'], ['ini' => ['memory_limit' => '32M'], 'timp' => 120]);
    $rulare = $parseaza($r['iesire']);
    $fisiereNoi = array_values(array_diff(glob($verif . '/storage/verificare/sonde/agregate-vertij-*.txt') ?: [], $fisiereInainte));
    $salvat = $fisiereNoi !== [] ? (string) file_get_contents($fisiereNoi[0]) : '';
    $verifica('memoria PHP terminată în V-CSS: cod 1 (nu agățat), „OPRIT: memoria PHP…”, verificările de până acolo pe ecran și în fișier, grupul css ca '
        . 'EROARE, fără mesajul PHP (calea contului)', $r['cod'] === 1 && str_contains($r['iesire'], 'OPRIT: memoria PHP s-a terminat (memory_limit)')
        && str_contains($r['iesire'], 'REZUMAT:') && isset($rulare['vt.donatii.total']) && ($rulare['css'][0] ?? '') === 'EROARE'
        && str_contains($salvat, 'OPRIT: memoria PHP') && str_contains($salvat, 'vt.donatii.total')
        && !preg_match('/(Fatal|Allowed memory|Warning|' . preg_quote($acasa, '/') . ')/', $r['tot']), mb_substr($r['tot'], -1500));
    $db->exec('DELETE FROM blog_posts WHERE id IN (' . implode(', ', array_map('intval', $idMari)) . ')');

    // ── Pe tot parcursul ──
    $scrie('');
    $scrie('Pe tot parcursul');
    $sumeDupa = $sumeTabele($db);
    $verifica('baza site-ului neschimbată de rulări (CHECKSUM TABLE pe fiecare tabel, aceeași listă de tabele)', $sumeDupa === $sumeInainte,
        json_encode(array_diff_assoc($sumeDupa, $sumeInainte)) ?: '');
    $instantaneuDupa = $instantaneu($acasa, array_merge($exceptiiVerif, [$dosarPrivat]));
    $schimbate = array_keys(array_diff_assoc($instantaneuDupa, $instantaneuInainte) + array_diff_assoc($instantaneuInainte, $instantaneuDupa));
    $verifica('în dosarele site-urilor live și ale contului: niciun fișier nou, schimbat sau șters (nici sesiuni)', $schimbate === [],
        implode("\n", array_map(static fn (string $c): string => str_replace($acasa, '~', $c), array_slice($schimbate, 0, 10))));
    $scriseVerif = array_map(static fn (string $c): string => substr($c, strlen($verif) + 1), array_keys($instantaneu($verif . '/storage')));
    $verifica('în site-ul de verificare: doar storage/verificare/sonde/ (și gardul .htaccess)', array_filter($scriseVerif, static fn (string $c): bool
        => !in_array($c, ['storage', 'storage/verificare', 'storage/verificare/.htaccess', 'storage/verificare/sonde'], true)
        && !str_starts_with($c, 'storage/verificare/sonde/')) === [], implode(', ', $scriseVerif));
    $fisiereScrise = glob($verif . '/storage/verificare/sonde/*') ?: [];
    foreach ($fisiereScrise as $fisier) {
        $iesiri .= (string) file_get_contents($fisier);
    }
    $verifica('fișierele de rezultat: 0600, într-un dosar 0700', $fisiereScrise !== [] && array_filter($fisiereScrise, static fn (string $f): bool => (fileperms($f) & 0777) !== 0600) === []
        && (fileperms($verif . '/storage/verificare/sonde') & 0777) === 0700);
    $gasite = [];
    foreach ($secrete as $secret) {
        if (str_contains($iesiri, $secret)) {
            $gasite[] = substr($secret, 0, 4) . '…';
        }
    }
    $verifica('nicio dată personală, parolă, nume de bază sau de utilizator, cale din cont în ieșiri și în fișierele scrise (' . strlen($iesiri) . ' octeți)',
        $gasite === [], implode(', ', $gasite));
    $verifica('nicio adresă de email și niciun telefon în ieșiri', preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[a-z]{2,}/', $iesiri) !== 1
        && preg_match('/\b07\d{8}\b/', $iesiri) !== 1);
    $urmarite = (string) shell_exec('git -C ' . escapeshellarg($radacina) . ' ls-files tools/dev/migrari/vechi/privat storage/verificare 2>/dev/null');
    $ignorate = true;
    foreach (['tools/dev/migrari/vechi/privat/bs-live.sql', 'tools/dev/migrari/vechi/privat/amprenta-bioscem.json', 'storage/verificare/sonde/agregate-bioscem-1.txt'] as $cale) {
        exec('git -C ' . escapeshellarg($radacina) . ' check-ignore -q ' . escapeshellarg($cale), $_, $codIgnorat);
        $ignorate = $ignorate && $codIgnorat === 0;
    }
    $verifica('git: tools/dev/migrari/vechi/privat/ și storage/verificare/sonde/ sunt ignorate, nimic din ele urmărit', trim($urmarite) === '' && $ignorate, $urmarite);
} catch (Throwable $e) {
    $verifica('testul a rulat până la capăt', false, get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

$scrie('');
$scrie('test-sonde: ' . $trecute . ' trecute, ' . $esuate . ' eșuate (PHP ' . PHP_VERSION . '). Raport: ' . $fisierRaport);
exit($esuate === 0 ? 0 : 1);
