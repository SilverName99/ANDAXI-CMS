<?php

declare(strict_types=1);

/**
 * Poarta G-automatizari: pluginul `automatizari` (26 nov 2026; docs/PLUGINURI.md, „Automatizări newsletter”), pe baza de test
 * cu datele demo, abonați, comenzi și coșuri inventate (adrese @exemplu.ro), cu ceasul motorului înghețat
 * (App\Support\Automatizari\Ceas) și serverul de dezvoltare pentru căile adevărate (confirmarea abonării, înregistrarea,
 * schimbarea statusului unei comenzi, adminul, pixelul și clicurile):
 *
 *   tools/dev/php.sh tools/dev/test-automatizari.php                 # A–I
 *   tools/dev/php.sh tools/dev/test-automatizari.php --fara-browser  # fără H (Chromium)
 *
 * A. Regulile, fără server: setările curățate, arborele verificat (tipuri, chei, „Dacă” doar la capăt, adâncimea), așteptarea
 *    cu intervalul orar, amprenta subiectului (aceeași în PHP și în SQL), bugetul.
 * B. Fiecare declanșator, din calea lui adevărată: abonat confirmat (POST /newsletter/confirmare, cu și fără lista aleasă),
 *    comandă plasată (punctul comanda.plasata, cu filtrul pe categorie și pe produs, plata cu cardul așteptată, comanda anulată),
 *    comandă finalizată (POST /admin/orders/{id}/status; doar prima comandă), coș abandonat (cronul; regula fără emailuri duble
 *    față de emailul clasic), client inactiv (fereastra de 30 de zile, o dată pe oră), cont nou (POST /register), etichetă primită.
 * C. Pașii: așteaptă (cu ceasul mutat; intervalul orar), dacă / altfel (fiecare condiție, deschiderea și clicul prin
 *    pixelul și linkul adevărate), trimite email (codurile, subsolul de dezabonare semnat, List-Unsubscribe, urmărirea; fără
 *    urmărire pentru cine a refuzat-o), cod de reducere (cupon unic, idempotent), etichete, liste, anunță-mă.
 * D. O singură dată pe subiect, reintrarea (eveniment nou da, același eveniment nu), abonații dezabonați / respinși / pe
 *    lista „nu trimite” săriți la fiecare email, neabonații care nu intră deloc.
 * E. Bugetul comun cu campaniile (în proces și prin cronul adevărat), căderea la jumătate și reluarea fără retrimitere,
 *    eșecurile (3 încercări, apoi ieșirea; 5 la rând opresc trecerea), automatizarea oprită, pasul șters.
 * F. Pluginul se oprește / oprit: nimeni nu intră, fluxurile în curs se termină / nimic nu se mișcă; garda; linkurile din
 *    emailurile trimise merg; emailul clasic de coș abandonat ca înainte.
 * G. Adminul prin HTTP: lista, șabloanele, editorul, salvarea (CSRF, arbore greșit), pornirea, copia, ștergerea, emailul nou,
 *    testul, eticheta, previzualizarea izolată, builderul (type=automatizare), rolurile, scăparea textelor.
 * H. În Chromium (tools/dev/automatizari-browser.cjs): editorul (adăugarea unui pas din „+”, panoul, salvarea), lista; fără
 *    erori JS; mișcarea redusă.
 * I. Curățenia: toată baza pusă la loc (CHECKSUM TABLE), licența „toate”, jurnalul PHP fără avertismente noi.
 *
 * Raportul: $CMS_DEV_DIR/test-automatizari-raport.txt. Cod de ieșire 0 doar dacă totul a trecut.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';
require_once __DIR__ . '/lib/salvari-admin.php';

use App\Support\Automatizari\Catalog;
use App\Support\Automatizari\Ceas;
use App\Support\Automatizari\CadereSimulata;
use App\Support\Automatizari\Conditii;
use App\Support\Automatizari\CoduriReducere;
use App\Support\Automatizari\Declansatoare;
use App\Support\Automatizari\Flux;
use App\Support\Automatizari\Motor;
use App\Support\Automatizari\Rulari;
use App\Support\BugetTrimitere;
use App\Support\Database;
use App\Support\EmailAutomation;
use App\Support\Hooks;
use App\Support\NewsletterEtichete;
use App\Support\NewsletterService;
use App\Support\Paths;
use App\Support\Plugins;
use App\Support\ResponseCache;
use App\Support\Settings;

$faraBrowser = in_array('--fara-browser', $argv, true);
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-automatizari-raport.txt';
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
$scrie('Test automatizările newsletterului ANDAXI-CMS — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

// ── A. Regulile, fără server ─────────────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== A. Regulile, fără server');
$asteapta = Catalog::setariPas('asteapta', ['cat' => '99999', 'unitate' => 'secole', 'doar_ziua' => '1', 'ora_de' => 22, 'ora_pana' => 5]);
$verifica('așteptarea: numărul adus între limite, unitatea necunoscută → zile, un interval orar întors → 9–20',
    $asteapta === ['cat' => 1000, 'unitate' => 'zile', 'doar_ziua' => true, 'ora_de' => 9, 'ora_pana' => 20], json_encode($asteapta));
$cod = Catalog::setariPas('cod_reducere', ['tip' => 'percent', 'valoare' => 500, 'zile' => 0, 'prefix' => 'ab-c<1>"', 'cumulabil' => 'on']);
$verifica('codul de reducere: procentul cel mult 90, zilele cel puțin 1, începutul doar litere și cifre (mari)',
    $cod === ['tip' => 'percent', 'valoare' => 90.0, 'zile' => 1, 'prefix' => 'ABC1', 'cumulabil' => true], json_encode($cod));
$conditie = Catalog::setariPas('daca', ['conditie' => 'rau', 'email_pas' => '../x']);
$verifica('condiția necunoscută → „A deschis”; o cheie de pas greșită se scoate', $conditie === ['conditie' => 'a_deschis', 'email_pas' => ''], json_encode($conditie));
$verifica('id-urile: doar numere pozitive, fără dubluri, din listă sau text', Catalog::iduri(['3', 3, '-1', 'x', 0, '7']) === [3, 7]
    && Catalog::iduri('4, 5 x 6') === [4, 5, 6]);
$anunt = Catalog::setariPas('anunta', ['destinatari' => 'a@exemplu.ro, rau, B@EXEMPLU.RO; c@exemplu.ro,d@exemplu.ro,e@exemplu.ro,f@exemplu.ro']);
$verifica('anunțul: doar adrese valide, cel mult 5, cu litere mici', $anunt['destinatari'] === 'a@exemplu.ro, b@exemplu.ro, c@exemplu.ro, d@exemplu.ro, e@exemplu.ro',
    $anunt['destinatari']);
[$randuri, $erori] = Flux::randuriDinArbore([
    ['cheie' => 'p1', 'tip' => 'email', 'setari' => ['email_id' => 4]],
    ['cheie' => 'p2', 'tip' => 'daca', 'setari' => ['conditie' => 'a_deschis'], 'da' => [['cheie' => 'p3', 'tip' => 'asteapta', 'setari' => []]],
        'nu' => [['cheie' => 'p4', 'tip' => 'eticheta_adauga', 'setari' => ['eticheta_id' => 2]], ['cheie' => 'p5', 'tip' => 'lista_adauga', 'setari' => ['lista_id' => 1]]]],
]);
$harta = array_column($randuri, null, 'cheie');
$verifica('arborele → graful: următorul, ramurile Da / Nu, ordinea', $erori === [] && $harta['p1']['urmator'] === 'p2' && $harta['p2']['da'] === 'p3'
    && $harta['p2']['nu'] === 'p4' && $harta['p4']['urmator'] === 'p5' && $harta['p2']['urmator'] === null && $harta['p1']['ordine'] === 1, json_encode($randuri));
$pasiInapoi = [];
foreach ($randuri as $r) {
    $pasiInapoi[$r['cheie']] = $r;
}
$arboreInapoi = Flux::arbore($pasiInapoi);
$verifica('graful → arborele (dus-întors)', count($arboreInapoi) === 2 && $arboreInapoi[1]['nu'][1]['cheie'] === 'p5' && $arboreInapoi[1]['da'][0]['cheie'] === 'p3');
[, $erori] = Flux::randuriDinArbore([['cheie' => 'p1', 'tip' => 'daca', 'setari' => [], 'da' => [], 'nu' => []], ['cheie' => 'p2', 'tip' => 'email', 'setari' => []]]);
$verifica('arborele greșit: un pas după „Dacă / altfel” e refuzat', $erori !== [], json_encode($erori));
[, $erori] = Flux::randuriDinArbore([['cheie' => 'p1', 'tip' => 'email', 'setari' => []], ['cheie' => 'p2', 'tip' => 'actiune_proprie', 'setari' => []],
    ['cheie' => 'p1', 'tip' => 'asteapta', 'setari' => []]]);
$verifica('arborele greșit: tip necunoscut (acțiune proprie, schimbarea rolului, a statusului comenzii nu există) și cheie repetată', count($erori) === 2, json_encode($erori));
$adanc = [];
for ($i = 15; $i >= 1; $i--) {
    $adanc = [['cheie' => 'd' . $i, 'tip' => 'daca', 'setari' => [], 'da' => $adanc, 'nu' => []]];
}
[, $erori] = Flux::randuriDinArbore($adanc);
$verifica('arborele greșit: prea multe ramuri una în alta', $erori !== [], json_encode($erori));
$verifica('tipurile de pas sunt exact cele cerute (fără acțiune proprie, rol, status de comandă)', array_keys(Catalog::PASI) === ['asteapta', 'daca', 'email',
    'anunta', 'cod_reducere', 'eticheta_adauga', 'eticheta_scoate', 'lista_muta', 'lista_adauga']);
$fereastra = ['cat' => 1, 'unitate' => 'ore', 'doar_ziua' => true, 'ora_de' => 9, 'ora_pana' => 20];
$verifica('așteptarea cu intervalul orar: 19:30 + 1 oră → a doua zi la 9:00; 03:00 + 1 oră → 9:00; 10:00 + 1 oră → 11:00',
    Motor::scadentaAsteptare('2026-12-01 19:30:00', $fereastra)->format('Y-m-d H:i') === '2026-12-02 09:00'
    && Motor::scadentaAsteptare('2026-12-01 03:00:00', $fereastra)->format('Y-m-d H:i') === '2026-12-01 09:00'
    && Motor::scadentaAsteptare('2026-12-01 10:00:00', $fereastra)->format('Y-m-d H:i') === '2026-12-01 11:00'
    && Motor::scadentaAsteptare('2026-12-01 10:00:00', ['cat' => 3, 'unitate' => 'zile'])->format('Y-m-d H:i') === '2026-12-04 10:00');
$buget = new BugetTrimitere(3, null);
$buget->consuma(2);
$verifica('bugetul: 3 − 2 = 1, apoi epuizat', $buget->ramase() === 1 && !$buget->epuizat() && (function () use ($buget): bool { $buget->consuma(); return $buget->epuizat(); })());
$verifica('textul reducerii: 10% și 25,5 lei', Catalog::textReducere('percent', 10.0) === '10%' && Catalog::textReducere('fixed', 25.5) === '25,5 lei');

// ── Serverul și baza ─────────────────────────────────────────────────────────────────────────────────────────────────
$port = (int) (getenv('CMS_DEV_PORT') ?: '8701');
$baza = 'http://127.0.0.1:' . $port;
$cerere = static function (string $metoda, string $cale, ?string $jar = null, array $date = [], array $antete = []) use ($baza): array {
    $primite = [];
    $c = curl_init(str_starts_with($cale, 'http') ? $cale : $baza . $cale);
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOPROXY => '*',
        CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => array_merge(['User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
            'Accept: text/html,application/json,*/*;q=0.8', 'Origin: ' . $baza], $antete),
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
    return ['cod' => $cod, 'antete' => $primite, 'corp' => $corp, 'location' => (string) ($primite['location'] ?? '')];
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
if (!$db instanceof PDO || (int) $db->query('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND is_active = 1')->fetchColumn() < 10) {
    $scrie('EROARE: baza de test nu are datele demo; rulează întâi tools/dev/instaleaza.sh');
    exit(1);
}
if (!Flux::disponibil($db) || !NewsletterEtichete::disponibil($db)) {
    $scrie('EROARE: baza nu are tabelele migrărilor automatizari/0078 și newsletter/0082 (tools/dev/php.sh scripts/migreaza.php --aplica)');
    exit(1);
}
if (!Plugins::activ('automatizari')) {
    $scrie('EROARE: licența de test nu are pluginul automatizari pornit (tools/dev/php.sh tools/dev/licenta.php toate)');
    exit(1);
}
$unu = static function (string $sql, array $p = []) use ($db): string {
    $stmt = $db->prepare($sql);
    $stmt->execute($p);
    $v = $stmt->fetchColumn();
    return $v === false || $v === null ? '' : (string) $v;
};

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
$parolaAdmin = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));
$jarAdmin = $dosarDev . '/test-automatizari-admin-' . getmypid() . '.txt';
$jarEmailuri = $dosarDev . '/test-automatizari-emailuri-' . getmypid() . '.txt';
$jarMagazin = $dosarDev . '/test-automatizari-magazin-' . getmypid() . '.txt';
$jarClient = $dosarDev . '/test-automatizari-client-' . getmypid() . '.txt';
$licentaSchimbata = false;
$puneLaLoc = static function () use (&$licentaSchimbata, $db, $tabeleBaza, $instantaneu, $radacina, $jarAdmin, $jarEmailuri, $jarMagazin, $jarClient,
    $sesiuniInainte, $amPornitServerul): void {
    Ceas::fixeaza(null);
    Plugins::reseteaza();
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
    foreach ([$jarAdmin, $jarEmailuri, $jarMagazin, $jarClient] as $jar) {
        @unlink($jar);
    }
    if ($amPornitServerul) {
        exec(escapeshellarg($radacina . '/tools/dev/server.sh') . ' --opreste >/dev/null 2>&1');
    }
};
register_shutdown_function(static function () use ($puneLaLoc): void {
    $puneLaLoc();
});
$licenta = static function (string ...$argumente) use ($radacina, &$licentaSchimbata): bool {
    exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/tools/dev/licenta.php') . ' '
        . implode(' ', array_map('escapeshellarg', $argumente)) . ' 2>&1', $iesire, $cod);
    $licentaSchimbata = $argumente !== ['toate'];
    ResponseCache::purgePageCache();
    return $cod === 0;
};
$jeton = static fn (string $html): string => preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1]
    : (preg_match('/"csrf":"([0-9a-f]{64})"/', $html, $m2) === 1 ? $m2[1] : '');

// Ce stare au pluginurile în proces (licența „toate”); pentru „se oprește” / „oprit” se schimbă doar automatizari.
$stariToate = array_fill_keys(Plugins::chei(), Plugins::PORNIT);
$cuStarea = static function (string $stare) use ($stariToate): void {
    Plugins::folosesteStari(array_merge($stariToate, ['automatizari' => $stare]));
};

// Datele inventate: liste, etichete, abonați, comenzi, coșuri. Toate adresele @exemplu.ro, numele fictive.
$setari = Settings::all($db);
$listaImplicita = NewsletterService::defaultListId($db);
$db->prepare('INSERT INTO newsletter_lists (name, description, is_default) VALUES (:n, :d, 0)')->execute(['n' => 'Test automatizări A', 'd' => 'Listă de test']);
$listaA = (int) $db->lastInsertId();
$db->prepare('INSERT INTO newsletter_lists (name, description, is_default) VALUES (:n, :d, 0)')->execute(['n' => 'Test automatizări B', 'd' => 'Listă de test']);
$listaB = (int) $db->lastInsertId();
$abonat = static function (string $email, string $stare = 'active', array $liste = [], int $faraUrmarire = 0, string $nume = 'Ana Exemplu') use ($db, $listaImplicita): int {
    $db->prepare('INSERT INTO newsletter_subscribers (email, name, status, fara_urmarire, confirmed_at) VALUES (:e, :n, :s, :f, NOW())')
        ->execute(['e' => $email, 'n' => $nume, 's' => $stare, 'f' => $faraUrmarire]);
    $id = (int) $db->lastInsertId();
    foreach (array_merge([$listaImplicita], $liste) as $l) {
        $db->prepare('INSERT IGNORE INTO newsletter_list_subscribers (list_id, subscriber_id) VALUES (:l, :s)')->execute(['l' => $l, 's' => $id]);
    }
    return $id;
};
$nrComanda = 990000;
$produse = $db->query('SELECT id, category_id FROM products WHERE deleted_at IS NULL AND is_active = 1 AND category_id IS NOT NULL ORDER BY id LIMIT 6')
    ->fetchAll(PDO::FETCH_ASSOC);
$produsA = (int) $produse[0]['id'];
$categorieA = (int) $produse[0]['category_id'];
$produsB = 0;
foreach ($produse as $p) {
    if ((int) $p['category_id'] !== $categorieA) {
        $produsB = (int) $p['id'];
        break;
    }
}
$comanda = static function (string $email, string $status = 'pending', float $total = 120.0, array $produseComanda = [], ?string $creata = null,
    string $prenume = 'Ana') use ($db, &$nrComanda): int {
    $nrComanda++;
    $db->prepare(
        'INSERT INTO orders (order_number, status, payment_method, payment_status, billing_first_name, billing_last_name, billing_email, billing_phone,
            billing_address_line1, billing_city, billing_county, billing_postcode, subtotal, shipping_cost, total, created_at)
         VALUES (:n, :s, :pm, :ps, :fn, :ln, :e, :ph, :a, :c, :j, :cp, :sub, 0, :t, :cr)'
    )->execute(['n' => (string) $nrComanda, 's' => $status, 'pm' => $status === 'pending_payment' ? 'stripe' : 'ramburs',
        'ps' => $status === 'pending_payment' ? 'unpaid' : 'paid', 'fn' => $prenume, 'ln' => 'Exemplu', 'e' => $email, 'ph' => '0700000000',
        'a' => 'Strada Exemplu 1', 'c' => 'Orașul Demo', 'j' => 'Demo', 'cp' => '000000', 'sub' => $total, 't' => $total,
        'cr' => $creata ?? date('Y-m-d H:i:s')]);
    $id = (int) $db->lastInsertId();
    foreach ($produseComanda as $pid) {
        $db->prepare('INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, line_total) VALUES (:o, :p, :n, 1, :u, :u2)')
            ->execute(['o' => $id, 'p' => $pid, 'n' => 'Produs demo', 'u' => $total, 'u2' => $total]);
    }
    return $id;
};
/** O automatizare activă, cu pașii dați (emailurile: cheia locală → [subiect, text]). */
$automatizare = static function (string $declansator, array $setariD, array $pasi, array $emailuri = [], bool $reintrare = false, string $nume = '')
    use ($db, $setari): int {
    $definitii = [];
    foreach ($emailuri as $cheie => [$subiect, $text]) {
        $definitii[$cheie] = ['nume' => $subiect, 'subiect' => $subiect, 'blocuri' => [
            ['type' => 'header', 'content' => $subiect], ['type' => 'text', 'content' => $text],
            ['type' => 'button', 'label' => 'Vezi magazinul', 'url' => '/magazin']]];
    }
    $id = Flux::creeaza($db, $nume !== '' ? $nume : 'Test ' . $declansator, $declansator, $setariD, $pasi, null, $definitii);
    if ($reintrare) {
        $db->prepare('UPDATE automatizari SET permite_reintrarea = 1 WHERE id = :id')->execute(['id' => $id]);
    }
    $probleme = Flux::schimbaStarea($db, $id, 'activa');
    if ($probleme !== []) {
        throw new RuntimeException('automatizarea de test nu pornește: ' . implode(' ', $probleme));
    }
    return $id;
};
$rulari = static fn (int $a): array => $db->query('SELECT * FROM automatizari_rulari WHERE automatizare_id = ' . $a . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$trimise = static fn (int $a, string $stare = 'sent'): int => (int) $db->query("SELECT COUNT(*) FROM automatizari_trimiteri WHERE automatizare_id = {$a} AND stare = '{$stare}'")->fetchColumn();
$dosarMail = $dosarDev . '/mail';
@mkdir($dosarMail, 0775, true);
$emailuriNoi = static function (callable $fn) use ($dosarMail): array {
    $inainte = glob($dosarMail . '/*.eml') ?: [];
    $fn();
    clearstatcache();
    $noi = array_values(array_diff(glob($dosarMail . '/*.eml') ?: [], $inainte));
    sort($noi);
    return array_map(static function (string $f): string {
        $brut = (string) file_get_contents($f);
        // Corpul poate fi quoted-printable sau base64: îl decodăm cât să putem căuta în el.
        $decodat = quoted_printable_decode($brut);
        if (preg_match_all('/\r?\n\r?\n([A-Za-z0-9+\/=\r\n]{200,})/', $brut, $m)) {
            foreach ($m[1] as $bucata) {
                $b = base64_decode(preg_replace('/\s+/', '', $bucata) ?? '', true);
                if (is_string($b)) {
                    $decodat .= "\n" . $b;
                }
            }
        }
        return $decodat;
    }, $noi);
};
$tura = static function (?BugetTrimitere $b = null, array $optiuni = []) use ($db, $setari): array {
    return Motor::ruleaza($db, $setari, $b ?? new BugetTrimitere(1000, null), $optiuni);
};
$acumReal = static fn (): string => date('Y-m-d H:i:s');

// ── B. Declanșatoarele ──────────────────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== B. Declanșatoarele, din căile lor adevărate');
// B1. Abonat nou confirmat, prin linkul adevărat (POST /newsletter/confirmare/{token}).
$aConfirmat = $automatizare('abonat_confirmat', ['lista_id' => 0], [['cheie' => 'p1', 'tip' => 'email', 'setari' => ['email' => 'e1']]],
    ['e1' => ['Bun venit, {{prenume}}!', 'Mulțumim, {{prenume}}.']]);
$aConfirmatLista = $automatizare('abonat_confirmat', ['lista_id' => $listaB], [['cheie' => 'p1', 'tip' => 'email', 'setari' => ['email' => 'e1']]],
    ['e1' => ['Bun venit în lista B', 'Text']]);
$db->prepare("INSERT INTO newsletter_subscribers (email, name, status, pending_at) VALUES ('confirmare.automat@exemplu.ro', 'Ioana Exemplu', 'pending', NOW())")->execute();
$idPending = (int) $db->lastInsertId();
$db->prepare('INSERT INTO newsletter_list_subscribers (list_id, subscriber_id) VALUES (:l, :s)')->execute(['l' => $listaA, 's' => $idPending]);
$url = NewsletterService::urlConfirmare($idPending, 'confirmare.automat@exemplu.ro', time());
$r = $cerere('POST', (string) parse_url($url, PHP_URL_PATH));
$rAbonat = $rulari($aConfirmat);
$verifica('abonat confirmat: POST /newsletter/confirmare → abonatul activ și o rulare în automatizare (cu prenumele lui)', $r['cod'] === 200
    && count($rAbonat) === 1 && $rAbonat[0]['email'] === 'confirmare.automat@exemplu.ro'
    && (json_decode((string) $rAbonat[0]['context_json'], true)['prenume'] ?? '') === 'Ioana', $r['cod'] . ' ' . json_encode($rAbonat));
$verifica('abonat confirmat, cu lista aleasă B: abonatul din lista A nu intră', $rulari($aConfirmatLista) === []);
$r = $cerere('POST', (string) parse_url($url, PHP_URL_PATH));
$verifica('al doilea clic pe același link nu mai face o rulare', count($rulari($aConfirmat)) === 1);
$verifica('jurnalul: „intrat” și „pas”, fără adresa de email în detalii', $unu('SELECT COUNT(*) FROM automatizari_jurnal WHERE automatizare_id = :a AND eveniment IN (\'intrat\', \'pas\')',
    ['a' => $aConfirmat]) === '2' && $unu("SELECT COUNT(*) FROM automatizari_jurnal WHERE detalii LIKE '%@%'") === '0');

// B2. Comandă plasată (punctul comanda.plasata, chemat de checkout după tranzacție), cu filtrele.
$abonat('comanda.automat@exemplu.ro');
$aPlasataCat = $automatizare('comanda_plasata', ['categorii' => [$categorieA]], [['cheie' => 'p1', 'tip' => 'email', 'setari' => ['email' => 'e1']]],
    ['e1' => ['Mulțumim pentru comanda {{numar_comanda}}', 'Totalul: {{total_comanda}}']]);
$aPlasataProdus = $automatizare('comanda_plasata', ['produse' => [$produsB]], [['cheie' => 'p1', 'tip' => 'email', 'setari' => ['email' => 'e1']]],
    ['e1' => ['Produsul B', 'Text']]);
$cPlasata = $comanda('comanda.automat@exemplu.ro', 'pending', 150.0, [$produsA]);
Hooks::ruleaza('comanda.plasata', $db, $cPlasata);
$verifica('comandă plasată cu un produs din categoria aleasă: intră (cu numărul și totalul comenzii)', count($rulari($aPlasataCat)) === 1
    && (int) $rulari($aPlasataCat)[0]['comanda_id'] === $cPlasata);
$verifica('comandă plasată fără produsul ales: nu intră', $rulari($aPlasataProdus) === []);
$verifica('checkout-ul cheamă comanda.plasata după tranzacție (SiteController)', preg_match('/\$db->commit\(\);.{0,1400}Hooks::ruleaza\(\'comanda\.plasata\', \$db, \$orderId\)/s',
    (string) file_get_contents($radacina . '/src/Http/Controllers/SiteController.php')) === 1);
$abonat('card.automat@exemplu.ro');
$cCard = $comanda('card.automat@exemplu.ro', 'pending_payment', 80.0, [$produsA]);
Hooks::ruleaza('comanda.plasata', $db, $cCard);
$abonat('anulata.automat@exemplu.ro');
$cAnulata = $comanda('anulata.automat@exemplu.ro', 'pending_payment', 80.0, [$produsA]);
Hooks::ruleaza('comanda.plasata', $db, $cAnulata);
Ceas::fixeaza(date('Y-m-d H:i:s', time() + 60));
$raport = $tura();
$rCard = $db->query('SELECT * FROM automatizari_rulari WHERE comanda_id = ' . $cCard)->fetch(PDO::FETCH_ASSOC);
$verifica('plata cu cardul neconfirmată: fluxul așteaptă (fără email), plata confirmată: pleacă', is_array($rCard) && $rCard['stare'] === 'activa'
    && $rCard['pas_curent'] === 'p1' && $trimise($aPlasataCat) === 1, json_encode($rCard) . ' ' . json_encode($raport));
$db->exec("UPDATE orders SET status = 'pending', payment_status = 'paid' WHERE id = {$cCard}");
$db->exec("UPDATE orders SET status = 'cancelled' WHERE id = {$cAnulata}");
Ceas::avanseaza('+20 minutes');
$tura();
$verifica('după confirmarea plății emailul pleacă; comanda anulată iese din flux („comanda_anulata”), fără email', $trimise($aPlasataCat) === 2
    && $unu('SELECT motiv_iesire FROM automatizari_rulari WHERE comanda_id = :c', ['c' => $cAnulata]) === 'comanda_anulata');

// B3. Comandă finalizată, din Admin → Comenzi (POST /admin/orders/{id}/status), doar prima comandă.
$aFinalizata = $automatizare('comanda_finalizata', ['doar_prima' => true], [['cheie' => 'p1', 'tip' => 'email', 'setari' => ['email' => 'e1']]],
    ['e1' => ['Mulțumim pentru prima comandă', 'Text']]);
$abonat('prima.automat@exemplu.ro');
$cPrima = $comanda('prima.automat@exemplu.ro', 'processing', 99.0, [$produsA], date('Y-m-d H:i:s', time() - 86400));
$cADoua = $comanda('prima.automat@exemplu.ro', 'processing', 49.0, [$produsA]);
$r = $cerere('POST', '/admin/login', $jarAdmin, ['email' => 'admin@exemplu.ro', 'password' => $parolaAdmin]);
$verifica('logarea administratorului general', $r['cod'] === 302, (string) $r['cod']);
$paginaComenzi = $cerere('GET', '/admin/orders', $jarAdmin);
$csrfAdmin = $jeton($paginaComenzi['corp']);
$r = $cerere('POST', '/admin/orders/' . $cADoua . '/status', $jarAdmin, ['_csrf' => $csrfAdmin, 'status' => 'completed']);
$verifica('a doua comandă finalizată din admin: nu e prima, nu intră', $r['cod'] === 302 && $rulari($aFinalizata) === [], $r['cod'] . ' ' . $r['location']);
$r = $cerere('POST', '/admin/orders/' . $cPrima . '/status', $jarAdmin, ['_csrf' => $csrfAdmin, 'status' => 'completed']);
$verifica('prima comandă finalizată din admin (POST /admin/orders/{id}/status): intră', count($rulari($aFinalizata)) === 1
    && (int) $rulari($aFinalizata)[0]['comanda_id'] === $cPrima, $r['cod'] . ' ' . json_encode($rulari($aFinalizata)));
$r = $cerere('POST', '/admin/orders/' . $cPrima . '/status', $jarAdmin, ['_csrf' => $csrfAdmin, 'status' => 'completed']);
$verifica('aceeași comandă salvată din nou „Finalizată” (fără schimbare de status): nicio rulare nouă', count($rulari($aFinalizata)) === 1);

// B4. Coș abandonat (cronul) și regula fără emailuri duble.
$aCos = $automatizare('cos_abandonat', ['dupa' => 30, 'unitate' => 'minute'], [['cheie' => 'p1', 'tip' => 'email', 'setari' => ['email' => 'e1']]],
    ['e1' => ['Ai uitat ceva în coș', 'Coșul tău te așteaptă.']]);
$abonat('cos.abonat@exemplu.ro');
$db->exec("INSERT INTO cart_abandonments (session_id, email, customer_name, cart_snapshot, last_seen_at, created_at)
    VALUES ('sess-test-auto-1', 'cos.abonat@exemplu.ro', 'Maria Exemplu', 'Ceai demo x 1', NOW() - INTERVAL 2 HOUR, NOW() - INTERVAL 3 HOUR),
           ('sess-test-auto-2', 'cos.neabonat@exemplu.ro', 'Radu Exemplu', 'Ceai demo x 2', NOW() - INTERVAL 2 HOUR, NOW() - INTERVAL 3 HOUR),
           ('sess-test-auto-3', 'cos.vechi@exemplu.ro', 'Dan Exemplu', 'Ceai demo x 1', NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 10 DAY),
           ('sess-test-auto-4', 'cos.proaspat@exemplu.ro', 'Ilie Exemplu', 'Ceai demo x 1', NOW() - INTERVAL 5 MINUTE, NOW() - INTERVAL 5 MINUTE)");
$abonat('cos.vechi@exemplu.ro');
$abonat('cos.proaspat@exemplu.ro');
$verifica('regula: cu o automatizare „Coș abandonat” activă, coșurile abonaților sunt ale ei', Declansatoare::preiaCosurileAbonatilor($db));
Ceas::fixeaza($acumReal());
$raport = $tura();
$rCos = $rulari($aCos);
$verifica('coș abandonat: intră doar coșul abonatului, vechi de 2 ore (nu cel nevăzut de 5 minute, nu cel de acum 10 zile, nu neabonatul)',
    count($rCos) === 1 && $rCos[0]['email'] === 'cos.abonat@exemplu.ro' && $raport['cosuri'] === 1 && $trimise($aCos) === 1, json_encode($raport));
$verifica('coșul luat de automatizare e marcat: emailul clasic nu-l mai ia niciodată', $unu("SELECT abandoned_email_error FROM cart_abandonments WHERE session_id = 'sess-test-auto-1'") === 'automatizare'
    && $unu("SELECT abandoned_email_sent_at IS NOT NULL FROM cart_abandonments WHERE session_id = 'sess-test-auto-1'") === '1');
$setariCos = array_merge($setari, ['email_template_abandoned_cart_active' => '1', 'email_abandoned_after_minutes' => '30']);
$clasic = [];
$emailuriClasic = $emailuriNoi(static function () use ($db, $setariCos, &$clasic): void {
    $clasic = EmailAutomation::sendDueAbandonedCartEmails($db, $setariCos, 100);
});
$destinatariClasic = $db->query("SELECT session_id FROM cart_abandonments WHERE session_id LIKE 'sess-test-auto-%' AND abandoned_email_error IS NULL AND abandoned_email_sent_at IS NOT NULL ORDER BY session_id")->fetchAll(PDO::FETCH_COLUMN);
$verifica('fără emailuri duble: emailul clasic pleacă la neabonat, nu și la coșurile abonaților (nici la cel încă neintrat, de 10 zile)',
    $destinatariClasic === ['sess-test-auto-2'], json_encode($destinatariClasic) . ' ' . json_encode($clasic));
// Coș cumpărat între timp: emailul următor nu mai pleacă.
$aCos2 = $automatizare('cos_abandonat', ['dupa' => 30, 'unitate' => 'minute'], [['cheie' => 'p1', 'tip' => 'asteapta', 'setari' => ['cat' => 1, 'unitate' => 'ore']],
    ['cheie' => 'p2', 'tip' => 'email', 'setari' => ['email' => 'e1']]], ['e1' => ['Încă te așteaptă', 'Text']]);
$abonat('cos.cumpara@exemplu.ro');
$db->exec("INSERT INTO cart_abandonments (session_id, email, customer_name, cart_snapshot, last_seen_at, created_at)
    VALUES ('sess-test-auto-5', 'cos.cumpara@exemplu.ro', 'Vlad Exemplu', 'Ceai', NOW() - INTERVAL 2 HOUR, NOW() - INTERVAL 3 HOUR)");
$tura();
$db->exec("UPDATE cart_abandonments SET converted_at = NOW() WHERE session_id = 'sess-test-auto-5'");
Ceas::avanseaza('+2 hours');
$tura();
$rCumpara = $db->query("SELECT * FROM automatizari_rulari WHERE automatizare_id = {$aCos2} AND email = 'cos.cumpara@exemplu.ro'")->fetch(PDO::FETCH_ASSOC);
$verifica('coș cumpărat după intrare: fluxul iese („a_cumparat”) înainte de email', is_array($rCumpara) && $rCumpara['motiv_iesire'] === 'a_cumparat'
    && $unu('SELECT COUNT(*) FROM automatizari_trimiteri WHERE rulare_id = :r', ['r' => (int) $rCumpara['id']]) === '0', json_encode($rCumpara));
Flux::schimbaStarea($db, $aCos, 'oprita');
Flux::schimbaStarea($db, $aCos2, 'oprita');
$verifica('automatizarea de coș oprită: emailul clasic își ia înapoi coșurile abonaților (cele nemarcate)', !Declansatoare::preiaCosurileAbonatilor($db));

// B5. Client inactiv de X zile.
$aInactiv = $automatizare('client_inactiv', ['zile' => 90], [['cheie' => 'p1', 'tip' => 'email', 'setari' => ['email' => 'e1']]], ['e1' => ['Ne e dor de tine', 'Text']]);
Ceas::fixeaza($acumReal());
$abonat('inactiv.automat@exemplu.ro');
$abonat('foarte.vechi@exemplu.ro');
$abonat('activ.recent@exemplu.ro');
$comanda('inactiv.automat@exemplu.ro', 'completed', 60.0, [$produsA], date('Y-m-d H:i:s', time() - 95 * 86400));
$comanda('foarte.vechi@exemplu.ro', 'completed', 60.0, [$produsA], date('Y-m-d H:i:s', time() - 200 * 86400));
$comanda('activ.recent@exemplu.ro', 'completed', 60.0, [$produsA], date('Y-m-d H:i:s', time() - 10 * 86400));
$comanda('inactiv.neabonat@exemplu.ro', 'completed', 60.0, [$produsA], date('Y-m-d H:i:s', time() - 95 * 86400));
Settings::save($db, ['automatizari_scanare_inactivi' => '']);
$n = Declansatoare::scaneazaInactivi($db);
$emailuriInactivi = array_column($rulari($aInactiv), 'email');
$verifica('client inactiv de 90 de zile: intră cel cu ultima comandă acum 95 de zile; nu cel de acum 200 (în afara ferestrei de 30), nu cel recent, nu neabonatul',
    $n === 1 && $emailuriInactivi === ['inactiv.automat@exemplu.ro'], json_encode($emailuriInactivi));
$verifica('scanarea clienților inactivi rulează cel mult o dată pe oră', Declansatoare::scaneazaInactivi($db) === 0);

// B6. Cont nou de client, prin înregistrarea adevărată (POST /register).
$aCont = $automatizare('cont_creat', [], [['cheie' => 'p1', 'tip' => 'email', 'setari' => ['email' => 'e1']]], ['e1' => ['Contul tău e gata', 'Text']]);
$abonat('cont.nou.automat@exemplu.ro');
// Limita înregistrărilor de pe aceeași adresă IP (alte teste au încercat de pe 127.0.0.1): jurnalul se golește, apoi se pune la loc.
$db->exec('DELETE FROM register_submit_logs');
$pagina = $cerere('GET', '/register', $jarClient);
preg_match('/name="register_form_token" value="([^"]+)"/', $pagina['corp'], $tok);
preg_match('/name="register_form_rendered_at" value="(\d+)"/', $pagina['corp'], $randat);
sleep(3);
$r = $cerere('POST', '/register', $jarClient, ['first_name' => 'Elena', 'last_name' => 'Exemplu', 'email' => 'cont.nou.automat@exemplu.ro',
    'password' => 'Parola-Test-Automat-1', 'password_confirm' => 'Parola-Test-Automat-1', 'register_form_token' => $tok[1] ?? '',
    'register_form_rendered_at' => $randat[1] ?? '', 'register_hp_website' => '']);
$rCont = $rulari($aCont);
$verifica('cont nou (POST /register) cu adresa unui abonat: intră, cu id-ul contului și prenumele', $r['cod'] === 302 && count($rCont) === 1
    && (int) $rCont[0]['utilizator_id'] > 0 && (json_decode((string) $rCont[0]['context_json'], true)['prenume'] ?? '')
        === $unu('SELECT first_name FROM users WHERE id = :id', ['id' => (int) $rCont[0]['utilizator_id']]), $r['cod'] . ' ' . json_encode($rCont));
Hooks::ruleaza('cont.creat', $db, 999999, 'neabonat.cont@exemplu.ro');
$verifica('cont nou al cuiva care nu e abonat: nu intră', count($rulari($aCont)) === 1);

// B7. Primește o etichetă (pusă de pasul altei automatizări).
$etichetaVip = NewsletterEtichete::idDupaNume($db, 'Client fidel (test)');
$aEticheta = $automatizare('eticheta_primita', ['eticheta_id' => $etichetaVip], [['cheie' => 'p1', 'tip' => 'email', 'setari' => ['email' => 'e1']]],
    ['e1' => ['Ai primit statutul de client fidel', 'Text']]);
$aPuneEticheta = $automatizare('abonat_confirmat', ['lista_id' => $listaB], [['cheie' => 'p1', 'tip' => 'eticheta_adauga', 'setari' => ['eticheta_id' => $etichetaVip]]]);
$idEt = $abonat('eticheta.automat@exemplu.ro', 'active', [$listaB]);
Flux::schimbaStarea($db, $aConfirmatLista, 'oprita');
Declansatoare::laAbonareConfirmata($db, $idEt, 'eticheta.automat@exemplu.ro');
$tura();
$tura(); // rularea făcută de etichetă în timpul trecerii e luată la trecerea următoare
$verifica('pasul „Adaugă etichetă” pune eticheta, iar „Primește o etichetă” pornește cealaltă automatizare', NewsletterEtichete::are($db, $idEt, $etichetaVip)
    && count($rulari($aEticheta)) === 1 && $trimise($aEticheta) === 1, json_encode($rulari($aEticheta)));
$verifica('o etichetă pusă din nou (o avea deja) nu mai pornește nimic', !NewsletterEtichete::adauga($db, $idEt, $etichetaVip) && count($rulari($aEticheta)) === 1);

// ── C. Pașii ────────────────────────────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== C. Pașii');
// C1. Așteaptă + trimite email + coduri + subsol + urmărire.
Ceas::fixeaza('2026-12-07 10:00:00');
$aFlux = $automatizare('abonat_confirmat', ['lista_id' => $listaA], [
    ['cheie' => 'p1', 'tip' => 'cod_reducere', 'setari' => ['tip' => 'percent', 'valoare' => 15, 'zile' => 10, 'prefix' => 'TEST']],
    ['cheie' => 'p2', 'tip' => 'asteapta', 'setari' => ['cat' => 3, 'unitate' => 'zile']],
    ['cheie' => 'p3', 'tip' => 'email', 'setari' => ['email' => 'e1']],
    ['cheie' => 'p4', 'tip' => 'asteapta', 'setari' => ['cat' => 2, 'unitate' => 'zile']],
    ['cheie' => 'p5', 'tip' => 'daca', 'setari' => ['conditie' => 'a_dat_clic', 'email_pas' => 'p3'],
        'da' => [['cheie' => 'p6', 'tip' => 'eticheta_adauga', 'setari' => ['eticheta_id' => $etichetaVip]], ['cheie' => 'p6b', 'tip' => 'lista_muta', 'setari' => ['lista_id' => $listaB, 'din_lista_id' => 0]]],
        'nu' => [['cheie' => 'p7', 'tip' => 'daca', 'setari' => ['conditie' => 'a_deschis', 'email_pas' => ''],
            'da' => [['cheie' => 'p8', 'tip' => 'lista_adauga', 'setari' => ['lista_id' => $listaB]]],
            'nu' => [['cheie' => 'p9', 'tip' => 'anunta', 'setari' => ['destinatari' => 'echipa@exemplu.ro', 'subiect' => 'Nu a deschis: {{email}}']]]]]],
], ['e1' => ['Salut, {{prenume}}: codul tău {{cod_reducere}}', "Codul {{cod_reducere}} îți dă {{cod_reducere_valoare}} până la {{cod_reducere_expira}}.\nLink: https://www.exemplu.ro/oferta?a=1&b=2"]],
    false, 'Flux complet de test');
$idDeschide = $abonat('deschide.automat@exemplu.ro', 'active', [$listaA], 0, 'Ana Exemplu');
$idClic = $abonat('clic.automat@exemplu.ro', 'active', [$listaA], 0, 'Bogdan Exemplu');
$idNimic = $abonat('nimic.automat@exemplu.ro', 'active', [$listaA], 0, 'Cristi Exemplu');
$idFaraUrm = $abonat('fara.urmarire@exemplu.ro', 'active', [$listaA], 1, 'Dana Exemplu');
foreach ([[$idDeschide, 'deschide.automat@exemplu.ro'], [$idClic, 'clic.automat@exemplu.ro'], [$idNimic, 'nimic.automat@exemplu.ro'],
    [$idFaraUrm, 'fara.urmarire@exemplu.ro']] as [$id, $e]) {
    Declansatoare::laAbonareConfirmata($db, $id, $e);
}
$tura();
$rFlux = $rulari($aFlux);
$verifica('intrarea: cei patru abonați trec de codul de reducere și așteaptă 3 zile', count($rFlux) === 4
    && array_unique(array_column($rFlux, 'pas_curent')) === ['p2'] && $trimise($aFlux) === 0, json_encode(array_column($rFlux, 'pas_curent')));
$cupoane = $db->query("SELECT * FROM coupons WHERE import_batch = 'automatizare-{$aFlux}' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$verifica('codul de reducere: patru cupoane unice, de o folosire, 15%, valabile 10 zile, cu începutul „TEST-”', count($cupoane) === 4
    && count(array_unique(array_column($cupoane, 'code'))) === 4 && array_unique(array_map('strval', array_column($cupoane, 'is_unique'))) === ['1']
    && array_unique(array_map('strval', array_column($cupoane, 'max_uses_total'))) === ['1'] && array_unique(array_column($cupoane, 'type')) === ['percent']
    && (float) $cupoane[0]['value'] === 15.0 && str_starts_with((string) $cupoane[0]['code'], 'TEST-')
    && str_starts_with((string) $cupoane[0]['ends_at'], '2026-12-17'), json_encode($cupoane));
$rulareDeschide = Rulari::citeste($db, (int) $db->query("SELECT id FROM automatizari_rulari WHERE automatizare_id = {$aFlux} AND email = 'deschide.automat@exemplu.ro'")->fetchColumn());
$a = Flux::citeste($db, $aFlux);
$din = CoduriReducere::genereaza($db, $a, $rulareDeschide, 'p1', ['tip' => 'percent', 'valoare' => 15, 'zile' => 10, 'prefix' => 'TEST']);
$verifica('codul de reducere e idempotent: aceeași rulare și același pas dau același cod, fără un al doilea cupon',
    $din['cod'] === $rulareDeschide['context']['cod_reducere']['cod'] && (int) $unu("SELECT COUNT(*) FROM coupons WHERE import_batch = 'automatizare-{$aFlux}'") === 4);
Ceas::fixeaza('2026-12-09 10:00:00');
$tura();
$verifica('după 2 zile (din 3): încă așteaptă, nimic trimis', $trimise($aFlux) === 0 && array_unique(array_column($rulari($aFlux), 'pas_curent')) === ['p2']);
Ceas::fixeaza('2026-12-10 10:00:30');
$mesaje = $emailuriNoi(static function () use ($tura): void {
    $tura();
});
$verifica('după 3 zile: patru emailuri trimise, rulările așteaptă la pasul următor (2 zile)', $trimise($aFlux) === 4 && count($mesaje) === 4
    && array_unique(array_column($rulari($aFlux), 'pas_curent')) === ['p4'], count($mesaje) . ' ' . json_encode(array_column($rulari($aFlux), 'pas_curent')));
$mesajAna = '';
$mesajDana = '';
foreach ($mesaje as $m) {
    if (str_contains($m, 'To: deschide.automat@exemplu.ro')) {
        $mesajAna = $m;
    }
    if (str_contains($m, 'To: fara.urmarire@exemplu.ro')) {
        $mesajDana = $m;
    }
}
$codAna = (string) $rulareDeschide['context']['cod_reducere']['cod'];
$verifica('emailul: prenumele și codul în subiect, valoarea și data codului în text, niciun cod {{…}} rămas',
    str_contains($mesajAna, 'Ana') && str_contains($mesajAna, $codAna) && str_contains($mesajAna, '15%') && str_contains($mesajAna, '17.12.2026')
    && !str_contains($mesajAna, '{{'), mb_substr($mesajAna, 0, 600));
$verifica('emailul: subsolul de dezabonare cu linkul semnat al abonatului și antetele List-Unsubscribe (cu un clic)',
    preg_match('#/newsletter/unsubscribe/[A-Za-z0-9_.%-]+#', $mesajAna) === 1 && str_contains($mesajAna, 'List-Unsubscribe-Post: List-Unsubscribe=One-Click')
    && str_contains($mesajAna, 'Dezabonează-mă'));
$verifica('emailul: pixelul de deschidere și linkurile de clic semnate (/automatizari/d/, /automatizari/c/)', str_contains($mesajAna, '/automatizari/d/')
    && str_contains($mesajAna, '/automatizari/c/'));
$verifica('abonatul care a refuzat urmărirea: fără pixel și fără linkuri rescrise (dezabonarea rămâne)', $mesajDana !== ''
    && !str_contains($mesajDana, '/automatizari/d/') && !str_contains($mesajDana, '/automatizari/c/') && str_contains($mesajDana, '/newsletter/unsubscribe/'));
$verifica('istoricul „Emailuri trimise”: tipul newsletter_automatizare, trimis', (int) $unu("SELECT COUNT(*) FROM email_send_history WHERE email_type = 'newsletter_automatizare' AND status = 'sent'") >= 4);

// C2. Deschiderea și clicul, prin pixelul și linkul adevărate (HTTP), apoi ramurile.
$trimitere = static fn (string $email) => (int) $db->query("SELECT t.id FROM automatizari_trimiteri t JOIN automatizari_rulari r ON r.id = t.rulare_id
    WHERE t.automatizare_id = {$aFlux} AND r.email = " . $db->quote($email))->fetchColumn();
$tDeschide = $trimitere('deschide.automat@exemplu.ro');
$tClic = $trimitere('clic.automat@exemplu.ro');
$pixel = $cerere('GET', '/automatizari/d/' . $tDeschide . '/' . NewsletterService::semneaza('automatizare|d|' . $tDeschide));
$verifica('pixelul semnat: un GIF, fără cache, deschiderea numărată', $pixel['cod'] === 200 && str_starts_with((string) ($pixel['antete']['content-type'] ?? ''), 'image/gif')
    && $unu('SELECT deschideri FROM automatizari_trimiteri WHERE id = :id', ['id' => $tDeschide]) === '1');
$pixelRau = $cerere('GET', '/automatizari/d/' . $tClic . '/semnatura-gresita');
$verifica('pixelul cu semnătură greșită: tot un GIF, dar nimic numărat', $pixelRau['cod'] === 200 && $unu('SELECT deschideri FROM automatizari_trimiteri WHERE id = :id', ['id' => $tClic]) === '0');
$dest = 'https://www.exemplu.ro/oferta?a=1&b=2';
$clic = $cerere('GET', '/automatizari/c/' . $tClic . '/' . NewsletterService::semneaza('automatizare|c|' . $tClic . '|' . $dest) . '?u=' . rawurlencode($dest));
$verifica('clicul semnat: 302 spre adresa din email (cu & corect), clicul numărat, niciun cookie de urmărire (doar sesiunea, ca la newsletter)',
    $clic['cod'] === 302 && $clic['location'] === $dest && $unu('SELECT clicuri FROM automatizari_trimiteri WHERE id = :id', ['id' => $tClic]) === '1'
    && preg_match('/^(PHPSESSID|' . preg_quote(session_name(), '/') . ')=/', (string) ($clic['antete']['set-cookie'] ?? 'PHPSESSID=')) === 1
    && !str_contains((string) ($clic['antete']['set-cookie'] ?? ''), 'nl_cid'), $clic['cod'] . ' ' . $clic['location'] . ' ' . ($clic['antete']['set-cookie'] ?? ''));
$rau = $cerere('GET', '/automatizari/c/' . $tClic . '/' . NewsletterService::semneaza('automatizare|c|' . $tClic . '|' . $dest) . '?u=' . rawurlencode('https://phishing.exemplu.net/'));
$verifica('clicul spre altă adresă decât cea semnată: pagina 404, nicio trambulină', $rau['cod'] === 404 && $rau['location'] === '', $rau['cod'] . ' ' . $rau['location']);
$clicAltaTrimitere = $cerere('GET', '/automatizari/c/' . $tDeschide . '/' . NewsletterService::semneaza('automatizare|c|' . $tClic . '|' . $dest) . '?u=' . rawurlencode($dest));
$verifica('semnătura unei trimiteri nu merge pentru alta', $clicAltaTrimitere['cod'] === 404);
$mesajeAnunt = [];
Ceas::fixeaza('2026-12-12 10:01:00');
$mesajeAnunt = $emailuriNoi(static function () use ($tura): void {
    $tura();
});
$stari = [];
foreach ($rulari($aFlux) as $rr) {
    $stari[$rr['email']] = $rr['stare'] . ':' . (string) $rr['motiv_iesire'];
}
$verifica('ramurile: toți patru au terminat fluxul', array_unique(array_values($stari)) === ['terminata:terminat'], json_encode($stari));
$verifica('„A apăsat un link” → Da: eticheta pusă și mutat în lista B (scos din A, lista implicită rămâne)', NewsletterEtichete::are($db, $idClic, $etichetaVip)
    && Declansatoare::inLista($db, $idClic, $listaB) && !Declansatoare::inLista($db, $idClic, $listaA) && Declansatoare::inLista($db, $idClic, $listaImplicita));
$verifica('„A apăsat” → Nu, „A deschis” → Da: adăugat în lista B (rămâne și în A), fără etichetă', !NewsletterEtichete::are($db, $idDeschide, $etichetaVip)
    && Declansatoare::inLista($db, $idDeschide, $listaB) && Declansatoare::inLista($db, $idDeschide, $listaA));
$anunturi = array_values(array_filter($mesajeAnunt, static fn (string $m): bool => str_contains($m, 'To: echipa@exemplu.ro')));
$verifica('fără deschidere și fără clic → „Anunță-mă”: câte un email la echipa@exemplu.ro pentru cei doi (cel fără urmărire e mereu „Nu”)', count($anunturi) === 2
    && !Declansatoare::inLista($db, $idNimic, $listaB) && !Declansatoare::inLista($db, $idFaraUrm, $listaB), count($anunturi) . ' din ' . count($mesajeAnunt));
$numere = Flux::numerePasi($db, $aFlux);
$verifica('numărătorile pe pas: p3 intrate 4 / în procesare 0 / ieșite 4, trimise 4, deschise 2 (clicul e și deschidere), clicuri 1; p6 intrate 1',
    ($numere['p3']['intrate'] ?? 0) === 4 && ($numere['p3']['in_procesare'] ?? 9) === 0 && ($numere['p3']['iesite'] ?? 0) === 4 && ($numere['p3']['trimise'] ?? 0) === 4
    && ($numere['p3']['deschise'] ?? 0) === 2 && ($numere['p3']['clicuri'] ?? 0) === 1 && ($numere['p6']['intrate'] ?? 0) === 1, json_encode($numere));
$verifica('numărătorile automatizării: intrate 4, în procesare 0, ieșite 4', Flux::numere($db, $aFlux) === ['intrate' => 4, 'in_procesare' => 0, 'iesite' => 4]);

// C3. Celelalte condiții, pe rulări construite.
$rulareTest = ['id' => 0, 'email' => 'comanda.automat@exemplu.ro', 'comanda_id' => $cPlasata, 'intrata_la' => '2000-01-01 00:00:00', 'context' => []];
$verifica('„Valoarea comenzii cel puțin”: 150 ≥ 150 da, ≥ 150,01 nu, fără comandă nu', Conditii::adevarata($db, $rulareTest, ['conditie' => 'total_minim', 'valoare' => 150])
    && !Conditii::adevarata($db, $rulareTest, ['conditie' => 'total_minim', 'valoare' => 150.01])
    && !Conditii::adevarata($db, ['comanda_id' => 0] + $rulareTest, ['conditie' => 'total_minim', 'valoare' => 1]));
$cPlusUna = $comanda('comanda.automat@exemplu.ro', 'pending', 30.0, [$produsB], '2026-12-08 12:00:00');
$verifica('„A cumpărat”: produsul B (din altă comandă decât cea a fluxului) da, categoria A doar din comanda fluxului → nu, oricare da',
    Conditii::adevarata($db, $rulareTest, ['conditie' => 'a_cumparat', 'produse' => [$produsB], 'de_la_intrare' => false])
    && !Conditii::adevarata($db, $rulareTest, ['conditie' => 'a_cumparat', 'categorii' => [$categorieA], 'produse' => [], 'de_la_intrare' => false])
    && Conditii::adevarata($db, $rulareTest, ['conditie' => 'a_cumparat', 'de_la_intrare' => false]));
$verifica('„A cumpărat” doar de la intrare: o comandă de dinaintea intrării nu contează', !Conditii::adevarata($db, ['intrata_la' => '2026-12-09 00:00:00'] + $rulareTest,
    ['conditie' => 'a_cumparat', 'de_la_intrare' => true]));
$db->exec("UPDATE orders SET status = 'cancelled' WHERE id = {$cPlusUna}");
$verifica('„A cumpărat”: o comandă anulată nu contează', !Conditii::adevarata($db, $rulareTest, ['conditie' => 'a_cumparat', 'produse' => [$produsB], 'de_la_intrare' => false]));
$rulareAna = ['email' => 'clic.automat@exemplu.ro'] + $rulareTest;
$verifica('„Are eticheta” și „E în lista”', Conditii::adevarata($db, $rulareAna, ['conditie' => 'are_eticheta', 'eticheta_id' => $etichetaVip])
    && !Conditii::adevarata($db, ['email' => 'nimic.automat@exemplu.ro'] + $rulareTest, ['conditie' => 'are_eticheta', 'eticheta_id' => $etichetaVip])
    && Conditii::adevarata($db, $rulareAna, ['conditie' => 'in_lista', 'lista_id' => $listaB]) && !Conditii::adevarata($db, $rulareAna, ['conditie' => 'in_lista', 'lista_id' => $listaA]));
$idScoate = $automatizare('eticheta_primita', ['eticheta_id' => $etichetaVip], [['cheie' => 'p1', 'tip' => 'eticheta_scoate', 'setari' => ['eticheta_id' => $etichetaVip]]]);
NewsletterEtichete::scoate($db, $idNimic, $etichetaVip);
NewsletterEtichete::adauga($db, $idNimic, $etichetaVip, Ceas::sql());
$tura();
$verifica('„Scoate eticheta”: eticheta pusă, apoi scoasă de flux', !NewsletterEtichete::are($db, $idNimic, $etichetaVip) && count($rulari($idScoate)) === 1);
Flux::schimbaStarea($db, $idScoate, 'oprita');
Flux::schimbaStarea($db, $aEticheta, 'oprita');

// ── D. O singură dată, reintrarea, dezabonații ─────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== D. O singură dată pe subiect, reintrarea, dezabonații');
$aOdata = $automatizare('comanda_plasata', [], [['cheie' => 'p1', 'tip' => 'asteapta', 'setari' => ['cat' => 1, 'unitate' => 'zile']]], [], false, 'O dată');
$aReintra = $automatizare('comanda_plasata', [], [['cheie' => 'p1', 'tip' => 'asteapta', 'setari' => ['cat' => 1, 'unitate' => 'zile']]], [], true, 'Cu reintrare');
$abonat('repetat.automat@exemplu.ro');
$c1 = $comanda('repetat.automat@exemplu.ro');
$c2 = $comanda('repetat.automat@exemplu.ro');
Hooks::ruleaza('comanda.plasata', $db, $c1);
Hooks::ruleaza('comanda.plasata', $db, $c2);
Hooks::ruleaza('comanda.plasata', $db, $c2);
$verifica('fără reintrare: două comenzi ale aceluiași abonat → o singură rulare', count($rulari($aOdata)) === 1);
$verifica('cu „Permite reintrarea”: fiecare comandă nouă intră (2), aceeași comandă de două ori nu', count($rulari($aReintra)) === 2);
$verifica('amprenta subiectului e aceeași în PHP și în SQL (SHA2 pe adresa cu litere mici)',
    Rulari::amprenta('Repetat.Automat@Exemplu.ro') === $unu("SELECT SHA2(LOWER('Repetat.Automat@Exemplu.ro'), 256)")
    && Rulari::amprenta('a@exemplu.ro', 'cos:5:2026-12-01 10:00:00') === $unu("SELECT SHA2(CONCAT(LOWER('a@exemplu.ro'), '|cos:', 5, ':', DATE_FORMAT('2026-12-01 10:00:00', '%Y-%m-%d %H:%i:%s')), 256)"));
Flux::schimbaStarea($db, $aOdata, 'oprita');
Flux::schimbaStarea($db, $aReintra, 'oprita');
// Dezabonat între pași, respins, pe lista „nu trimite”; neabonat care nu intră.
$aDez = $automatizare('comanda_plasata', [], [['cheie' => 'p1', 'tip' => 'asteapta', 'setari' => ['cat' => 1, 'unitate' => 'ore']],
    ['cheie' => 'p2', 'tip' => 'email', 'setari' => ['email' => 'e1']]], ['e1' => ['Email după o oră', 'Text']], false, 'Dezabonați');
Ceas::fixeaza('2026-12-20 10:00:00');
$ids = [];
foreach (['dezabonat.intre@exemplu.ro', 'respins.intre@exemplu.ro', 'sters.intre@exemplu.ro', 'ramas.activ@exemplu.ro'] as $e) {
    $ids[$e] = $abonat($e);
    Hooks::ruleaza('comanda.plasata', $db, $comanda($e));
}
Hooks::ruleaza('comanda.plasata', $db, $comanda('niciodata.abonat@exemplu.ro'));
$abonat('dezabonat.dinainte@exemplu.ro', 'unsubscribed');
Hooks::ruleaza('comanda.plasata', $db, $comanda('dezabonat.dinainte@exemplu.ro'));
$verifica('neabonații și dezabonații de dinainte nu intră deloc (4 rulări din 6 comenzi)', count($rulari($aDez)) === 4);
$tura();
$db->exec("UPDATE newsletter_subscribers SET status = 'unsubscribed' WHERE email = 'dezabonat.intre@exemplu.ro'");
$db->exec("UPDATE newsletter_subscribers SET status = 'bounced' WHERE email = 'respins.intre@exemplu.ro'");
NewsletterService::suprima($db, 'sters.intre@exemplu.ro');
Ceas::avanseaza('+61 minutes');
$tura();
$motive = [];
foreach ($rulari($aDez) as $rr) {
    $motive[$rr['email']] = (string) $rr['motiv_iesire'];
}
$verifica('la email: dezabonatul, respinsul și cel de pe lista „nu trimite” ies („neabonat”), fără email; cel activ îl primește',
    $motive === ['dezabonat.intre@exemplu.ro' => 'neabonat', 'respins.intre@exemplu.ro' => 'neabonat', 'sters.intre@exemplu.ro' => 'neabonat', 'ramas.activ@exemplu.ro' => 'terminat']
    && $trimise($aDez) === 1, json_encode($motive));
Flux::schimbaStarea($db, $aDez, 'oprita');

// ── E. Bugetul, căderea, eșecurile, oprirea ─────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== E. Bugetul comun, căderea și reluarea, eșecurile');
$aBuget = $automatizare('comanda_plasata', [], [['cheie' => 'p1', 'tip' => 'email', 'setari' => ['email' => 'e1']]], ['e1' => ['Buget', 'Text']], false, 'Buget');
Ceas::fixeaza($acumReal());
foreach (['buget1', 'buget2', 'buget3'] as $b) {
    $abonat($b . '@exemplu.ro');
    Hooks::ruleaza('comanda.plasata', $db, $comanda($b . '@exemplu.ro'));
}
$bugetMic = new BugetTrimitere(10, null);
$bugetMic->consuma(8); // campaniile au trimis 8 din 10 în trecerea asta
$tura($bugetMic);
$verifica('bugetul comun: după 8 trimiteri ale campaniilor din 10, automatizările trimit 2, a treia rămâne scadentă', $trimise($aBuget) === 2
    && $unu("SELECT COUNT(*) FROM automatizari_rulari WHERE automatizare_id = {$aBuget} AND stare = 'activa' AND pas_curent = 'p1'") === '1');
$tura(new BugetTrimitere(10, null));
$verifica('trecerea următoare trimite restul', $trimise($aBuget) === 3);
// Cronul adevărat: o campanie care folosește tot bugetul rulării (--per-run), apoi automatizările.
$abonat('buget4@exemplu.ro');
Hooks::ruleaza('comanda.plasata', $db, $comanda('buget4@exemplu.ro'));
$db->exec("UPDATE automatizari_rulari SET urmatoarea_la = NOW() - INTERVAL 1 MINUTE WHERE automatizare_id = {$aBuget} AND stare = 'activa'");
$db->prepare("INSERT INTO newsletter_campaigns (name, subject, html_content, status, subscriber_list_id, subscriber_list_ids, sent_at)
    VALUES ('Campanie test buget', 'Campanie test', '<p>Test</p>', 'sending', :l, :ls, NOW())")->execute(['l' => $listaA, 'ls' => json_encode([$listaA])]);
$campanieBuget = (int) $db->lastInsertId();
$activiA = (int) $unu("SELECT COUNT(*) FROM newsletter_list_subscribers ls JOIN newsletter_subscribers s ON s.id = ls.subscriber_id AND s.status = 'active' WHERE ls.list_id = :l", ['l' => $listaA]);
exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/scripts/newsletter-campaigns.php') . ' --per-run=' . $activiA . ' 2>&1', $iesireCron, $codCron);
$iesireCron = implode("\n", $iesireCron);
$verifica('cronul (--per-run=' . $activiA . '): campania trimite ' . $activiA . ', automatizările 0 (bugetul rulării e comun)', $codCron === 0
    && str_contains($iesireCron, 'trimise=' . $activiA) && str_contains($iesireCron, 'Automatizari: rulari=') && str_contains($iesireCron, 'trimise=0,') && $trimise($aBuget) === 3, $iesireCron);
$iesireCron = [];
exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/scripts/newsletter-campaigns.php') . ' --per-run=' . $activiA . ' 2>&1', $iesireCron, $codCron);
$iesireCron = implode("\n", $iesireCron);
$verifica('rularea următoare a cronului (campania terminată): automatizarea trimite', $codCron === 0 && $trimise($aBuget) === 4, $iesireCron);
Flux::schimbaStarea($db, $aBuget, 'oprita');

// Căderea la jumătate și reluarea.
$aCadere = $automatizare('comanda_plasata', [], [['cheie' => 'p1', 'tip' => 'email', 'setari' => ['email' => 'e1']],
    ['cheie' => 'p2', 'tip' => 'eticheta_adauga', 'setari' => ['eticheta_id' => $etichetaVip]]], ['e1' => ['Cădere', 'Text']], false, 'Cădere');
Ceas::fixeaza('2026-12-21 10:00:00');
$idCadere = $abonat('cadere.automat@exemplu.ro');
Hooks::ruleaza('comanda.plasata', $db, $comanda('cadere.automat@exemplu.ro'));
$cazut = false;
$mesajeCadere = $emailuriNoi(static function () use ($tura, &$cazut): void {
    try {
        $tura(null, ['cad_dupa_rezervare' => true]);
    } catch (CadereSimulata) {
        $cazut = true;
    }
});
$rCad = $rulari($aCadere)[0];
$verifica('căderea după rezervarea emailului: nimic trimis, rularea rămâne revendicată, trimiterea „pending”', $cazut && $mesajeCadere === []
    && $rCad['stare'] === 'in_lucru' && $trimise($aCadere, 'pending') === 1);
$verifica('altă trecere nu o poate lua cât revendicarea e proaspătă (lacătul)', Rulari::revendica($db, (int) $rCad['id']) === null
    && $tura()['rulari'] === 0);
Ceas::avanseaza('+16 minutes');
$mesajeReluare = $emailuriNoi(static function () use ($tura): void {
    $tura();
});
$verifica('după 15 minute rularea se reia: emailul NU se retrimite („unknown”), fluxul merge mai departe și se termină',
    $mesajeReluare === [] && $trimise($aCadere, 'unknown') === 1 && $trimise($aCadere, 'sent') === 0 && $rulari($aCadere)[0]['stare'] === 'terminata'
    && NewsletterEtichete::are($db, $idCadere, $etichetaVip), json_encode($rulari($aCadere)[0]));
Flux::schimbaStarea($db, $aCadere, 'oprita');

// Eșecurile: serverul de email refuză (SMTP pe un port închis).
$aEsec = $automatizare('comanda_plasata', [], [['cheie' => 'p1', 'tip' => 'email', 'setari' => ['email' => 'e1']]], ['e1' => ['Eșec', 'Text']], false, 'Eșec');
Ceas::fixeaza('2026-12-22 10:00:00');
foreach (range(1, 6) as $i) {
    $abonat('esec' . $i . '@exemplu.ro');
    Hooks::ruleaza('comanda.plasata', $db, $comanda('esec' . $i . '@exemplu.ro'));
}
$setariRele = array_merge($setari, ['email_delivery_method' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => '9', 'smtp_encryption' => 'none']);
$raport = Motor::ruleaza($db, $setariRele, new BugetTrimitere(100, null));
$verifica('5 eșecuri la rând opresc trecerea (serverul de email are o problemă): al șaselea nu se mai încearcă', $raport['esuate'] === 5
    && $trimise($aEsec, 'failed') === 5 && $trimise($aEsec, 'pending') === 0, json_encode($raport));
Ceas::avanseaza('+16 minutes');
Motor::ruleaza($db, $setariRele, new BugetTrimitere(100, null));
Ceas::avanseaza('+16 minutes');
Motor::ruleaza($db, $setariRele, new BugetTrimitere(100, null));
Ceas::avanseaza('+16 minutes');
Motor::ruleaza($db, $setariRele, new BugetTrimitere(100, null));
$primul = $db->query("SELECT r.stare, r.motiv_iesire, t.incercari FROM automatizari_rulari r JOIN automatizari_trimiteri t ON t.rulare_id = r.id
    WHERE r.automatizare_id = {$aEsec} AND r.email = 'esec1@exemplu.ro'")->fetch(PDO::FETCH_ASSOC);
$verifica('un email respins se reîncearcă la 15 minute, de cel mult 3 ori, apoi rularea iese („eroare_trimitere”)', is_array($primul)
    && (int) $primul['incercari'] === 3 && $primul['stare'] === 'iesita' && $primul['motiv_iesire'] === 'eroare_trimitere', json_encode($primul));
Flux::schimbaStarea($db, $aEsec, 'oprita');

// Automatizare oprită și pas șters.
$aOprita = $automatizare('comanda_plasata', [], [['cheie' => 'p1', 'tip' => 'asteapta', 'setari' => ['cat' => 1, 'unitate' => 'ore']],
    ['cheie' => 'p2', 'tip' => 'email', 'setari' => ['email' => 'e1']]], ['e1' => ['Oprită', 'Text']], false, 'Oprită');
Ceas::fixeaza('2026-12-23 10:00:00');
$abonat('oprita.automat@exemplu.ro');
Hooks::ruleaza('comanda.plasata', $db, $comanda('oprita.automat@exemplu.ro'));
Flux::schimbaStarea($db, $aOprita, 'oprita');
Ceas::avanseaza('+2 hours');
$tura();
$verifica('automatizarea oprită: rularea stă pe loc (la așteptare), nimic trimis; nimeni nou nu intră', $rulari($aOprita)[0]['pas_curent'] === 'p1'
    && $trimise($aOprita) === 0);
Flux::schimbaStarea($db, $aOprita, 'activa');
$tura();
$verifica('pornită din nou: rularea merge mai departe și emailul pleacă', $trimise($aOprita) === 1);
$aSters = $automatizare('comanda_plasata', [], [['cheie' => 'p1', 'tip' => 'asteapta', 'setari' => ['cat' => 1, 'unitate' => 'ore']],
    ['cheie' => 'p2', 'tip' => 'email', 'setari' => ['email' => 'e1']]], ['e1' => ['Pas șters', 'Text']], false, 'Pas șters');
$abonat('pas.sters@exemplu.ro');
Hooks::ruleaza('comanda.plasata', $db, $comanda('pas.sters@exemplu.ro'));
$emailSters = (int) $unu('SELECT id FROM automatizari_emailuri WHERE automatizare_id = :a', ['a' => $aSters]);
Flux::salveaza($db, $aSters, 'Pas șters', 'comanda_plasata', [], false, [['cheie' => 'p2', 'tip' => 'email', 'setari' => ['email_id' => $emailSters]]]);
Ceas::avanseaza('+2 hours');
$tura();
$verifica('o rulare care aștepta la un pas șters din editor iese („pas_sters”), fără email', $rulari($aSters)[0]['motiv_iesire'] === 'pas_sters' && $trimise($aSters) === 0);
Flux::schimbaStarea($db, $aOprita, 'oprita');
Flux::schimbaStarea($db, $aSters, 'oprita');

// ── F. Pluginul se oprește / oprit ─────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== F. Pluginul se oprește / oprit');
$aStari = $automatizare('comanda_plasata', [], [['cheie' => 'p1', 'tip' => 'asteapta', 'setari' => ['cat' => 1, 'unitate' => 'ore']],
    ['cheie' => 'p2', 'tip' => 'email', 'setari' => ['email' => 'e1']]], ['e1' => ['Stări', 'Text']], true, 'Stări');
Ceas::fixeaza('2026-12-24 10:00:00');
$abonat('stari.automat@exemplu.ro');
Hooks::ruleaza('comanda.plasata', $db, $comanda('stari.automat@exemplu.ro'));
$cuStarea(Plugins::SE_OPRESTE);
Hooks::ruleaza('comanda.plasata', $db, $comanda('stari.automat@exemplu.ro'));
$verifica('se oprește: o comandă nouă nu mai intră (agățarea e doar „pornit”)', count($rulari($aStari)) === 1);
$verifica('se oprește: emailul clasic de coș abandonat își ia înapoi coșurile abonaților', !Declansatoare::preiaCosurileAbonatilor($db));
Ceas::avanseaza('+2 hours');
$tura();
$verifica('se oprește: fluxul în curs se termină (emailul pleacă)', $trimise($aStari) === 1);
$cuStarea(Plugins::OPRIT);
$abonat('oprit.automat@exemplu.ro');
Hooks::ruleaza('comanda.plasata', $db, $comanda('oprit.automat@exemplu.ro'));
$cuStarea(Plugins::PORNIT);
Hooks::ruleaza('comanda.plasata', $db, $c = $comanda('oprit.automat@exemplu.ro'));
$idOprit = (int) $unu("SELECT id FROM automatizari_rulari WHERE automatizare_id = :a AND email = 'oprit.automat@exemplu.ro'", ['a' => $aStari]);
$cuStarea(Plugins::OPRIT);
Ceas::avanseaza('+2 hours');
$raportOprit = $tura();
$verifica('oprit: comanda de cât era oprit nu a intrat; motorul nu mișcă nimic (rularea pornită înainte stă pe loc)', count($rulari($aStari)) === 2
    && $raportOprit['rulari'] === 0 && Rulari::citeste($db, $idOprit)['pas_curent'] === 'p1', json_encode($raportOprit));
$tEmailStari = (int) $unu("SELECT id FROM automatizari_trimiteri WHERE automatizare_id = :a AND stare = 'sent'", ['a' => $aStari]);
Plugins::reseteaza();
$licenta('toate', 'automatizari=oprit');
$verifica('oprit: Marketing → Automatizări newsletter dă „Modulul nu este inclus” (403), fără intrare în meniu', ($pg = $cerere('GET', '/admin/automatizari', $jarAdmin))['cod'] === 403
    && !str_contains($cerere('GET', '/admin', $jarAdmin)['corp'], 'href="/admin/automatizari"'), (string) $pg['cod']);
$verifica('oprit: builderul cu type=automatizare și salvarea lui dau 403', $cerere('GET', '/admin/emails/builder?type=automatizare&id=1', $jarAdmin)['cod'] === 403
    && $cerere('POST', '/admin/emails/builder', $jarAdmin, ['builder_type' => 'automatizare', 'builder_id' => '1'])['cod'] === 403);
$clicOprit = $cerere('GET', '/automatizari/c/' . $tEmailStari . '/' . NewsletterService::semneaza('automatizare|c|' . $tEmailStari . '|/magazin') . '?u=' . rawurlencode('/magazin'));
$verifica('oprit: un link dintr-un email deja trimis merge (302 în site), fără să numere clicul', $clicOprit['cod'] === 302
    && str_ends_with($clicOprit['location'], '/magazin') && $unu('SELECT clicuri FROM automatizari_trimiteri WHERE id = :id', ['id' => $tEmailStari]) === '0',
    $clicOprit['cod'] . ' ' . $clicOprit['location']);
$verifica('oprit: un link nesemnat dă pagina 404 a site-ului', $cerere('GET', '/automatizari/c/' . $tEmailStari . '/x?u=%2Fmagazin')['cod'] === 404);
$iesireCron = [];
exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/scripts/newsletter-campaigns.php') . ' 2>&1', $iesireCron, $codCron);
$verifica('oprit: cronul newsletterului nu mai are rândul automatizărilor', $codCron === 0 && !str_contains(implode("\n", $iesireCron), 'Automatizari'), implode(' ', $iesireCron));
$licenta('toate');
Plugins::reseteaza();
Ceas::fixeaza(null);

// ── G. Adminul ──────────────────────────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== G. Adminul');
$lista = $cerere('GET', '/admin/automatizari', $jarAdmin);
$csrf = $jeton($lista['corp']);
$verifica('lista: automatizările, comutatorul, numărătorile, șabloanele (bun venit, coș, mulțumire, inactivi), intrarea din meniu', $lista['cod'] === 200
    && str_contains($lista['corp'], 'Flux complet de test') && str_contains($lista['corp'], 'role="switch"') && str_contains($lista['corp'], 'În procesare')
    && str_contains($lista['corp'], 'value="bun_venit"') && str_contains($lista['corp'], 'value="cos_abandonat"') && str_contains($lista['corp'], 'value="multumire"')
    && str_contains($lista['corp'], 'value="recastigare"') && str_contains($lista['corp'], 'href="/admin/automatizari"') && $csrf !== '', (string) $lista['cod']);
$nrAutomatizari = $unu('SELECT COUNT(*) FROM automatizari');
$r = $cerere('POST', '/admin/automatizari/noua', $jarAdmin, ['sablon' => 'bun_venit']);
$verifica('o automatizare nouă fără jeton: refuzată, nimic creat', $r['cod'] === 302 && $unu('SELECT COUNT(*) FROM automatizari') === $nrAutomatizari);
$r = $cerere('POST', '/admin/automatizari/noua', $jarAdmin, ['_csrf' => $csrf, 'sablon' => 'bun_venit']);
$idNou = (int) $unu("SELECT MAX(id) FROM automatizari WHERE sablon = 'bun_venit'");
$verifica('din șablonul „Bun venit”: ciornă cu 3 pași și 2 emailuri, deschisă în editor', $r['cod'] === 302 && $r['location'] === '/admin/automatizari/' . $idNou
    && $unu('SELECT COUNT(*) FROM automatizari_pasi WHERE automatizare_id = :a', ['a' => $idNou]) === '3'
    && $unu('SELECT COUNT(*) FROM automatizari_emailuri WHERE automatizare_id = :a', ['a' => $idNou]) === '2'
    && $unu('SELECT stare FROM automatizari WHERE id = :a', ['a' => $idNou]) === 'ciorna', $r['cod'] . ' ' . $r['location']);
$nrSabloane = (int) $unu("SELECT COUNT(*) FROM automatizari WHERE sablon IN ('cos_abandonat', 'multumire', 'recastigare', 'gol')");
foreach (['cos_abandonat', 'multumire', 'recastigare', 'gol'] as $sablon) {
    $cerere('POST', '/admin/automatizari/noua', $jarAdmin, ['_csrf' => $csrf, 'sablon' => $sablon]);
}
$idRecastig = (int) $unu("SELECT MAX(id) FROM automatizari WHERE sablon = 'recastigare'");
$verifica('celelalte șabloane se fac și pot porni pe loc (recâștigarea are eticheta „Reactivat” făcută)', (int) $unu("SELECT COUNT(*) FROM automatizari WHERE sablon IN ('cos_abandonat', 'multumire', 'recastigare', 'gol')") === $nrSabloane + 4
    && Flux::problemePornire($db, Flux::citeste($db, $idRecastig), Flux::pasi($db, $idRecastig)) === [] && NewsletterEtichete::idDupaNume($db, 'Reactivat', false) > 0);
$editor = $cerere('GET', '/admin/automatizari/' . $idNou, $jarAdmin);
$verifica('editorul: pânza, panoul, datele în JSON (fără </script> nescăpat), scriptul', $editor['cod'] === 200 && str_contains($editor['corp'], 'id="af-flux"')
    && str_contains($editor['corp'], 'id="af-date"') && str_contains($editor['corp'], 'automatizari-editor.js') && str_contains($editor['corp'], 'au intrat'),
    (string) $editor['cod']);
$csrfEd = $jeton($editor['corp']);
$fluxBun = ['nume' => 'Bun venit <script>alert(1)</script>', 'declansator' => 'abonat_confirmat', 'setari' => ['lista_id' => 0], 'permite_reintrarea' => false,
    'pasi' => Flux::arbore(Flux::pasi($db, $idNou))];
$fluxBun['pasi'][] = ['cheie' => 'px1', 'tip' => 'lista_adauga', 'setari' => ['lista_id' => $listaB]];
$r = $cerere('POST', '/admin/automatizari/' . $idNou . '/salveaza', $jarAdmin, ['flux' => json_encode($fluxBun)]);
$verifica('salvarea fără jeton: 419, nimic schimbat', $r['cod'] === 419 && $unu('SELECT COUNT(*) FROM automatizari_pasi WHERE automatizare_id = :a', ['a' => $idNou]) === '3', (string) $r['cod']);
$r = $cerere('POST', '/admin/automatizari/' . $idNou . '/salveaza', $jarAdmin, ['_csrf' => $csrfEd, 'flux' => json_encode($fluxBun)]);
$j = json_decode($r['corp'], true);
$verifica('salvarea cu jetonul: 4 pași, numele nou', $r['cod'] === 200 && ($j['ok'] ?? false) === true
    && $unu('SELECT COUNT(*) FROM automatizari_pasi WHERE automatizare_id = :a', ['a' => $idNou]) === '4', $r['corp']);
$fluxRau = $fluxBun;
$fluxRau['pasi'] = [['cheie' => 'q1', 'tip' => 'daca', 'setari' => [], 'da' => [], 'nu' => []], ['cheie' => 'q2', 'tip' => 'asteapta', 'setari' => []]];
$r = $cerere('POST', '/admin/automatizari/' . $idNou . '/salveaza', $jarAdmin, ['_csrf' => $csrfEd, 'flux' => json_encode($fluxRau)]);
$verifica('salvarea unui arbore greșit: 422 cu motivul, pașii de dinainte rămân', $r['cod'] === 422 && str_contains($r['corp'], 'Dacă')
    && $unu('SELECT COUNT(*) FROM automatizari_pasi WHERE automatizare_id = :a', ['a' => $idNou]) === '4', $r['corp']);
$fluxStrain = $fluxBun;
$fluxStrain['pasi'][0]['setari']['email_id'] = (int) $unu('SELECT id FROM automatizari_emailuri WHERE automatizare_id = :a LIMIT 1', ['a' => $aFlux]);
$r = $cerere('POST', '/admin/automatizari/' . $idNou . '/salveaza', $jarAdmin, ['_csrf' => $csrfEd, 'flux' => json_encode($fluxStrain)]);
$verifica('un pas care arată spre emailul altei automatizări e refuzat', $r['cod'] === 422, $r['corp']);
$r = $cerere('POST', '/admin/automatizari/' . $idNou . '/stare', $jarAdmin, ['_csrf' => $csrfEd, 'stare' => 'activa', 'ajax' => '1']);
$verifica('pornirea din editor: activă', $r['cod'] === 200 && $unu('SELECT stare FROM automatizari WHERE id = :a', ['a' => $idNou]) === 'activa', $r['corp']);
$fluxGol = $fluxBun;
$fluxGol['pasi'] = [['cheie' => 'z1', 'tip' => 'email', 'setari' => ['email_id' => 0]]];
$r = $cerere('POST', '/admin/automatizari/' . $idNou . '/salveaza', $jarAdmin, ['_csrf' => $csrfEd, 'flux' => json_encode($fluxGol)]);
$j = json_decode($r['corp'], true);
$verifica('o automatizare activă salvată cu un email nefăcut: se oprește singură, cu motivul', ($j['oprita'] ?? false) === true
    && $unu('SELECT stare FROM automatizari WHERE id = :a', ['a' => $idNou]) === 'oprita' && str_contains(implode(' ', $j['probleme'] ?? []), 'emailul'), $r['corp']);
$r = $cerere('POST', '/admin/automatizari/' . $idNou . '/stare', $jarAdmin, ['_csrf' => $csrfEd, 'stare' => 'activa', 'ajax' => '1']);
$verifica('pornirea unui flux incomplet: refuzată (422), cu motivul', $r['cod'] === 422 && str_contains($r['corp'], 'emailul'), $r['corp']);
$r = $cerere('POST', '/admin/automatizari/' . $idNou . '/salveaza', $jarAdmin, ['_csrf' => $csrfEd, 'flux' => json_encode($fluxBun)]);
$r = $cerere('POST', '/admin/automatizari/' . $idNou . '/email-nou', $jarAdmin, ['_csrf' => $csrfEd]);
$j = json_decode($r['corp'], true);
$emailNou = (int) ($j['email']['id'] ?? 0);
$verifica('„Fă emailul”: un email nou al automatizării, cu conținut de pornire', $r['cod'] === 200 && $emailNou > 0
    && $unu('SELECT automatizare_id FROM automatizari_emailuri WHERE id = :id', ['id' => $emailNou]) === (string) $idNou, $r['corp']);
$prev = $cerere('GET', '/admin/automatizari/' . $idNou . '/previzualizare?email=' . $emailNou, $jarAdmin);
$verifica('previzualizarea: emailul izolat (CSP sandbox, fără scripturi), cu exemplele în locul codurilor', $prev['cod'] === 200
    && str_contains((string) ($prev['antete']['content-security-policy'] ?? ''), 'sandbox') && str_contains($prev['corp'], 'Ana') && !str_contains($prev['corp'], '{{prenume}}'));
$verifica('previzualizarea unui email al altei automatizări: 404', $cerere('GET', '/admin/automatizari/' . $idNou . '/previzualizare?email='
    . (int) $unu('SELECT id FROM automatizari_emailuri WHERE automatizare_id = :a LIMIT 1', ['a' => $aFlux]), $jarAdmin)['cod'] === 404);
$builder = $cerere('GET', '/admin/emails/builder?type=automatizare&id=' . $emailNou, $jarAdmin);
$verifica('builderul (varianta C) deschide emailul automatizării: „Email automatizare”, înapoi la editor, codurile', $builder['cod'] === 200
    && str_contains($builder['corp'], 'Email automatizare') && str_contains($builder['corp'], 'href="/admin/automatizari/' . $idNou)
    && str_contains($builder['corp'], '{{cod_reducere}}') && !str_contains($builder['corp'], 'Template activ'), (string) $builder['cod']);
$blocuri = [['type' => 'header', 'content' => 'Titlu nou din builder'], ['type' => 'text', 'content' => 'Salut, {{prenume}}!']];
$r = $cerere('POST', '/admin/emails/builder', $jarAdmin, ['builder_type' => 'automatizare', 'builder_id' => (string) $emailNou, 'builder_name' => 'Email din builder',
    'builder_subject' => 'Subiect din builder', 'builder_blocks_json' => json_encode($blocuri)]);
$verifica('salvarea din builder: blocurile, subiectul și HTML-ul emailului automatizării', $r['cod'] === 302
    && $unu('SELECT subiect FROM automatizari_emailuri WHERE id = :id', ['id' => $emailNou]) === 'Subiect din builder'
    && str_contains($unu('SELECT html_content FROM automatizari_emailuri WHERE id = :id', ['id' => $emailNou]), 'Titlu nou din builder'), $r['cod'] . ' ' . $r['location']);
$testMesaje = $emailuriNoi(static function () use ($cerere, $jarAdmin, $idNou, $csrfEd, $emailNou, &$rTest): void {
    $rTest = $cerere('POST', '/admin/automatizari/' . $idNou . '/email-test', $jarAdmin, ['_csrf' => $csrfEd, 'email_id' => (string) $emailNou, 'adresa' => 'test.admin@exemplu.ro']);
});
$verifica('emailul de test: pleacă la adresa dată, cu [Test] și exemplele, fără urmărire, fără nicio rulare', ($rTest['cod'] ?? 0) === 200 && count($testMesaje) === 1
    && str_contains($testMesaje[0], 'test.admin@exemplu.ro') && str_contains($testMesaje[0], 'Ana') && !str_contains($testMesaje[0], '/automatizari/d/')
    && $unu('SELECT COUNT(*) FROM automatizari_rulari WHERE automatizare_id = :a', ['a' => $idNou]) === '0', ($rTest['corp'] ?? '') . ' ' . count($testMesaje));
$r = $cerere('POST', '/admin/automatizari/' . $idNou . '/email-test', $jarAdmin, ['_csrf' => $csrfEd, 'email_id' => (string) $emailNou, 'adresa' => 'nu-e-adresa']);
$verifica('emailul de test la o adresă greșită: 422, nimic trimis', $r['cod'] === 422);
$r = $cerere('POST', '/admin/automatizari/' . $idNou . '/eticheta', $jarAdmin, ['_csrf' => $csrfEd, 'nume' => '  Client <b>nou</b>  ']);
$j = json_decode($r['corp'], true);
$verifica('eticheta nouă din editor (numele curățat de spații, păstrat ca text)', $r['cod'] === 200 && ($j['eticheta']['nume'] ?? '') === 'Client <b>nou</b>', $r['corp']);
$lista = $cerere('GET', '/admin/automatizari', $jarAdmin);
$verifica('textele scăpate: numele cu <script> apare ca text în listă și în JSON-ul editorului', !str_contains($lista['corp'], '<script>alert(1)</script>')
    && str_contains($lista['corp'], '&lt;script&gt;alert(1)&lt;/script&gt;')
    && !str_contains($cerere('GET', '/admin/automatizari/' . $idNou, $jarAdmin)['corp'], '<script>alert(1)'));
$r = $cerere('POST', '/admin/automatizari/' . $idNou . '/duplica', $jarAdmin, ['_csrf' => $csrf]);
$idCopie = (int) $unu('SELECT MAX(id) FROM automatizari');
$verifica('„Duplică”: o ciornă cu aceiași pași și emailurile copiate (alte id-uri), fără rulări', $r['cod'] === 302 && $idCopie > $idNou
    && $unu('SELECT stare FROM automatizari WHERE id = :a', ['a' => $idCopie]) === 'ciorna'
    && $unu('SELECT COUNT(*) FROM automatizari_pasi WHERE automatizare_id = :a', ['a' => $idCopie]) === $unu('SELECT COUNT(*) FROM automatizari_pasi WHERE automatizare_id = :a', ['a' => $idNou])
    && $unu('SELECT COUNT(*) FROM automatizari_emailuri WHERE automatizare_id = :a', ['a' => $idCopie]) === $unu('SELECT COUNT(*) FROM automatizari_emailuri WHERE automatizare_id = :a', ['a' => $idNou]));
$r = $cerere('POST', '/admin/automatizari/' . $aFlux . '/stare', $jarAdmin, ['_csrf' => $csrf, 'stare' => 'oprita']);
$verifica('comutatorul din listă (formular): oprește', $r['cod'] === 302 && $unu('SELECT stare FROM automatizari WHERE id = :a', ['a' => $aFlux]) === 'oprita');
$r = $cerere('POST', '/admin/automatizari/' . $aFlux . '/sterge', $jarAdmin, ['_csrf' => $csrf]);
$verifica('„Șterge”: automatizarea, pașii, emailurile, rulările, trimiterile și jurnalul pleacă; cupoanele trimise rămân', $r['cod'] === 302
    && $unu('SELECT COUNT(*) FROM automatizari WHERE id = :a', ['a' => $aFlux]) === '0' && $unu('SELECT COUNT(*) FROM automatizari_rulari WHERE automatizare_id = :a', ['a' => $aFlux]) === '0'
    && $unu('SELECT COUNT(*) FROM automatizari_jurnal WHERE automatizare_id = :a', ['a' => $aFlux]) === '0'
    && $unu("SELECT COUNT(*) FROM coupons WHERE import_batch = 'automatizare-{$aFlux}'") === '4');
$clicSters = $cerere('GET', '/automatizari/c/' . $tClic . '/' . NewsletterService::semneaza('automatizare|c|' . $tClic . '|' . $dest) . '?u=' . rawurlencode($dest));
$verifica('un link dintr-un email al unei automatizări șterse duce tot unde trebuie', $clicSters['cod'] === 302 && $clicSters['location'] === $dest);
// Rolurile: administratorul de emailuri da; cel de magazin nu.
$parolaRol = $parolaAdmin;
$r = $cerere('POST', '/admin/login', $jarEmailuri, ['email' => 'emailuri@exemplu.ro', 'password' => $parolaRol]);
$verifica('„Administrator email-uri”: lista automatizărilor și editorul (același drept ca newsletterele)', $cerere('GET', '/admin/automatizari', $jarEmailuri)['cod'] === 200
    && $cerere('GET', '/admin/automatizari/' . $idNou, $jarEmailuri)['cod'] === 200);
$r = $cerere('POST', '/admin/login', $jarMagazin, ['email' => 'magazin@exemplu.ro', 'password' => $parolaRol]);
$rm = $cerere('GET', '/admin/automatizari', $jarMagazin);
$rmJson = $cerere('POST', '/admin/automatizari/' . $idNou . '/salveaza', $jarMagazin, ['_csrf' => $csrfEd, 'flux' => json_encode($fluxBun)]);
$verifica('„Administrator magazin”: trimis la pagina lui, salvarea JSON refuzată (403)', $rm['cod'] === 302 && !str_contains($rm['location'], '/admin/automatizari')
    && $rmJson['cod'] === 403, $rm['cod'] . ' ' . $rm['location'] . ' ' . $rmJson['cod']);
$verifica('nelogat: lista trimite la login, salvarea JSON dă 401', $cerere('GET', '/admin/automatizari')['location'] === '/admin/login'
    && $cerere('POST', '/admin/automatizari/' . $idNou . '/salveaza', null, ['flux' => '{}'])['cod'] === 401);

// ── H. Chromium ─────────────────────────────────────────────────────────────────────────────────────────────────────
if (!$faraBrowser) {
    $scrie('');
    $scrie('== H. Chromium (tools/dev/automatizari-browser.cjs)');
    $iesireB = [];
    exec('node ' . escapeshellarg(__DIR__ . '/automatizari-browser.cjs') . ' ' . escapeshellarg($baza) . ' ' . escapeshellarg($parolaAdmin) . ' '
        . escapeshellarg((string) $idCopie) . ' ' . escapeshellarg($dosarDev . '/automatizari') . ' 2>&1', $iesireB, $codB);
    $rezB = json_decode((string) end($iesireB), true);
    if (!is_array($rezB)) {
        $verifica('scriptul din browser a rulat', false, implode("\n", array_slice($iesireB, -15)));
    } else {
        foreach ((array) ($rezB['verificari'] ?? []) as [$eticheta, $ok, $detalii]) {
            $verifica((string) $eticheta, (bool) $ok, (string) $detalii);
        }
        $pasiDupa = Flux::pasi($db, $idCopie);
        $verifica('după „Salvează” din browser: pasul „Așteaptă” adăugat din „+” e în bază', count(array_filter($pasiDupa, static fn (array $p): bool => $p['tip'] === 'asteapta')) >= 2,
            json_encode(array_column($pasiDupa, 'tip')));
    }
}

// ── I. Curățenia ────────────────────────────────────────────────────────────────────────────────────────────────────
$scrie('');
$scrie('== I. Curățenia');
$puneLaLoc();
$verifica('baza pusă la loc: CHECKSUM TABLE identic pe fiecare tabel', $sumeBaza() === $sumeInainte,
    implode(', ', array_keys(array_diff_assoc($sumeBaza(), $sumeInainte))));
$verifica('licența „toate” la loc (automatizari pornit)', Plugins::activ('automatizari'));
$erori = is_file($fisierErori) ? (string) file_get_contents($fisierErori, false, null, $pozitieErori) : '';
$verifica('jurnalul PHP: niciun Warning, Notice sau Fatal nou', preg_match('/PHP (Warning|Notice|Fatal|Parse)/', $erori) !== 1, mb_substr($erori, 0, 1200));

$scrie('');
$scrie('Rezultat: ' . $trecute . ' trecute, ' . $esuate . ' eșuate. Raport: ' . $fisierRaport);
exit($esuate === 0 ? 0 : 1);
