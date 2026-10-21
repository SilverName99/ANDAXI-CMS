<?php

declare(strict_types=1);

/**
 * Testele încărcării copiei de verificare (WP-09; docs/VERIFICARE.md, „Încărcarea copiei”): tools/verificare/incarca.php,
 * regulile tools/verificare/reguli/bioscem.php și ce schimbă încărcarea în configureaza/stare/licenta/sterge.php.
 *
 *   tools/dev/php.sh tools/dev/test-incarcare.php
 *   CMS_DEV_PHP=php8.3 tools/dev/php.sh tools/dev/test-incarcare.php   # cu PHP-ul minim (poarta G-verif le rulează pe amândouă)
 *   tools/dev/php.sh tools/dev/test-incarcare.php --fara-baza          # doar A
 *
 * A. Fără bază: regulile acoperă fiecare tabel și fiecare coloană a schemei Bioscem 4254ca4 (tools/dev/migrari/vechi/bs.sql:
 *    și avertismentul pe produs; câmpurile SEO ale categoriilor și mărcilor sunt rânduri în seo_pages) și a schemei codului
 *    comun (database/migrari/asteptat.json, tabelele runner-ului), nicio regulă spre o coloană care nu există, fiecare nume de
 *    date personale cu regula sau motivul lui; greșelile din reguli (fiecare fel) sunt prinse; scanerul (o coloană personală
 *    sau doar necunoscută oprește, un tabel necunoscut = doar structura, un tip necopiabil oprește); fiecare transformare
 *    (pseudonimele consecvente și diferite de la o sare la alta, telefoanele, numele, AWB-urile, cupoanele, adresele,
 *    lungimea coloanei, NULL pe NOT NULL), neutralizarea setărilor și verificarea ei (A6) socotită separat, ce fișiere ale
 *    codului vechi se copiază, garda instrucțiunilor sursei; incarca.php sub un PHP prea vechi.
 * B. O găzduire simulată ($CMS_DEV_DIR/incarcare/acasa): site-ul de verificare (copia codului din git, configurat cu
 *    configureaza.php, servit pe CMS_DEV_PORT+15) și un Bioscem „live” inventat (domains/bioscem.ro/public_html: .env cu
 *    datele de test și live ale băncii, cod, .git, sesiuni, poze, sitemap, jurnale, o copie de bază uitată, un export, o
 *    arhivă, o legătură spre afară), cu baza cms_incarc_sursa făcută ca a unui site încă neadoptat (tools/dev/migrari/pe-vechi.php,
 *    seed-ul și datele demo, plus tabelele avertismentului din bs.sql: aceleași tabele și coloane ca bs.sql, verificat), cu
 *    un rând inventat în fiecare tabel anonimizat și „doar structura”, cu un marcaj aleator în fiecare coloană personală, plus
 *    setările din lista neutralizării, seo_pages pentru o categorie și o marcă, avertismentele, un tabel de lucru FAN și unul
 *    necunoscut; copia pe cms_incarc_tinta. Referința cms_incarc_ref: aceeași sursă copiată simplu și trecută prin aceleași
 *    migrări. Pe tot parcursul: marcajul, parolele, numele bazelor și ale utilizatorilor și calea contului nu apar în nicio
 *    ieșire și în niciun text salvat.
 *    B1 refuzurile (cod 2, nimic atins: copia fără niciun tabel, sursa și dosarul live neschimbate): argumentele, un site
 *       fără reguli, răspunsurile (da, parolele, adresa, intrarea încheiată), alt dosar, alt HOME, site-ul live negăsit, baza
 *       copiei = baza sursei sau a altui site din cont (fără conexiune la ea), o bază cu tabele fără _verificare.
 *    B2 scanerul: o coloană personală nouă, una doar necunoscută, o vedere, un _verificare în sursă → cod 1, doar numele pe
 *       ecran, copia ștearsă singură.
 *    B3 încărcarea: „GATA” ultimul rând, A1–A9 DA; pe copie niciun marcaj (în nicio coloană a niciunui tabel) și niciuna din
 *       valorile personale ale sursei (căutate întregi); _verificare încheiat, incarcat-la, sarea ștearsă; rândurile = sursa;
 *       tabelele „doar structura” și cel necunoscut fără rânduri; față de referință: CHECKSUM TABLE egal pe tabelele copiate cum
 *       sunt (și seo_pages cu categoria și marca, avertismentele, liniile comenzilor) și coloanele păstrate egale în cele
 *       anonimizate; pseudonimele consecvente între tabele, codurile unice, jetoanele, AWB-urile, setările, migrările
 *       (înghețarea Bioscem), licența bioscem, contul proprietarului (se loghează), adminii site-ului (nu), D3 (.env), codul
 *       vechi (doar cod, în afara public_html), sursa neschimbată (baza și dosarul), stare.php „GATA” înainte și după, pagina
 *       servită doar cu parola porții, textul salvat.
 *    B4 sesiunea sursei e doar de citire (serverul refuză o scriere, și în instantaneu); încărcarea cu un utilizator doar cu SELECT.
 *    B5 --reincarca: refuzat fără el; cu el, altă sare, alt pseudonim.
 *    B6 --reia: --buget=0 copiază un grup pe rulare (cod 3, copia închisă, stare.php „NU E GATA”, site-ul „lipsește”), apoi
 *       --reia până la „GATA”, cu pseudonimele consecvente între rulări; resturile unui grup neterminat se șterg; --reia
 *       refuzat pe o copie terminată, cu alte reguli, fără sare; sterge.php pe o încărcare oprită.
 *    B7 mutațiile: fiecare aserțiune pică pe o regulă sau un pas stricat într-o copie a codului (A1…A9), iar copia se șterge.
 *    B8 --demo: fără nicio bază sursă (site-ul live ascuns), aceleași rânduri ca datele demo din baza de dezvoltare, adresele
 *       @verificare.invalid, personalul și clienții cu parola personalului (se loghează), contul proprietarului; un pachet cu
 *       înghețare privată fără privat/ → oprit, copia ștearsă; cu valori private inventate și --licenta=toate → „GATA”;
 *       sterge.php șterge și pozele copiei.
 *    B9 pe MySQL 8 (pornit dacă nu mergea): aceeași sursă și aceeași încărcare, „GATA”, niciun marcaj.
 *
 * Cere baza de dezvoltare făcută de tools/dev/instaleaza.sh (B8 o compară cu copia demonstrativă). Bazele cms_incarc_* și
 * utilizatorii lor (pe ambele servere), serverul copiei și dosarul găzduirii se fac și se șterg aici; MySQL 8 se oprește
 * dacă l-a pornit testul. Raportul: $CMS_DEV_DIR/test-incarcare-raport.txt; cod de ieșire 0 doar dacă totul a trecut.
 * Toate datele sunt inventate.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/src/Support/Env.php';
require_once $radacina . '/src/Support/Verificare.php';
require_once $radacina . '/tools/verificare/lib/incarcare.php';

use Unelte\Verificare\CitireLive;
use Unelte\Verificare\Incarcare;
use Unelte\Verificare\InstructiuneRefuzata;
use Unelte\Verificare\Reguli;
use Unelte\Verificare\SursaCopie;
use Unelte\Verificare\Transformari;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-incarcare-raport.txt';
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
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . mb_substr($detalii, 0, 2500) : ''));
    }
};
$faraBaza = in_array('--fara-baza', $argv, true);
$scrie('Test încărcare ANDAXI-CMS — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

// =====================================================================
// A. Fără bază
// =====================================================================
$scrie('');
$scrie('A. Fără bază: regulile, scanerul, transformările');

/** Tabelele și coloanele unui fișier CREATE TABLE (fixture-urile din tools/dev/migrari/vechi/). */
$coloaneSql = static function (string $fisier): array {
    $r = [];
    $tabel = null;
    foreach (file($fisier, FILE_IGNORE_NEW_LINES) ?: [] as $linie) {
        if (preg_match('/^CREATE TABLE `([^`]+)`/', $linie, $m) === 1) {
            $tabel = $m[1];
            $r[$tabel] = [];
            continue;
        }
        if ($tabel !== null && preg_match('/^  `([^`]+)` ([a-z]+)/', $linie, $m) === 1) {
            $r[$tabel][$m[1]] = ['tip' => $m[2], 'nul' => !str_contains($linie, 'NOT NULL'), 'lungime' => null, 'generata' => false];
        }
    }
    return $r;
};
$structuraDin = static function (array $tabele): array {
    $r = [];
    foreach ($tabele as $tabel => $coloane) {
        $r[$tabel] = ['tip' => 'BASE TABLE', 'coloane' => $coloane];
    }
    return $r;
};

$reguli = Reguli::incarca($radacina, 'bioscem');
$verifica('regulile Bioscem se încarcă, fără nicio greșeală de formă (probleme())', $reguli->probleme() === [], implode('; ', $reguli->probleme()));
$bs = $coloaneSql($radacina . '/tools/dev/migrari/vechi/bs.sql');
$verifica('bs.sql (schema Bioscem 4254ca4) are tabelele avertismentului pe produs și seo_pages', isset($bs['product_warnings'], $bs['order_product_warnings'], $bs['seo_pages'])
    && count($bs) >= 59, (string) count($bs));
$scanBs = $reguli->scaneaza($structuraDin($bs));
$verifica('scanerul pe schema Bioscem 4254ca4: fiecare tabel are regulă (' . count($scanBs['copiate']) . ' copiate, ' . count($scanBs['doar_structura'])
    . ' doar structura), fiecare coloană a unui tabel copiat e în reguli, niciun tip necopiabil', $scanBs['probleme'] === [] && $scanBs['necunoscute'] === [],
    implode('; ', array_merge($scanBs['probleme'], $scanBs['necunoscute'])));
$asteptat = json_decode((string) file_get_contents($radacina . '/database/migrari/asteptat.json'), true);
$cms = [];
foreach ((array) $asteptat['tabele'] as $tabel => $info) {
    foreach ((array) $info['coloane'] as $coloana => $c) {
        $cms[$tabel][$coloana] = ['tip' => (string) preg_replace('/[^a-z].*$/', '', (string) $c['tip']), 'nul' => (bool) $c['null'], 'lungime' => null, 'generata' => false];
    }
}
// Tabelele runner-ului (le face migreaza.php însuși, nu sunt în asteptat.json).
$cms['_platforma_migrari'] = array_fill_keys(['id', 'ord', 'proprietar', 'tip', 'sha256', 'blocanta', 'rezultat', 'operatii', 'cursor_json', 'cod_versiune',
    'server_versiune', 'inceputa_la', 'terminata_la', 'durata_ms'], ['tip' => 'varchar', 'nul' => true, 'lungime' => null, 'generata' => false]);
$cms['_platforma_stare'] = array_fill_keys(['cheie', 'valoare', 'actualizata_la'], ['tip' => 'varchar', 'nul' => true, 'lungime' => null, 'generata' => false]);
$scanCms = $reguli->scaneaza($structuraDin($cms));
$verifica('scanerul pe schema codului comun (asteptat.json, ' . count($cms) . ' tabele cu ale runner-ului): nicio coloană fără regulă, niciun tabel necunoscut '
    . '(o bază deja adoptată se încarcă la fel)', $scanCms['probleme'] === [] && $scanCms['necunoscute'] === [], implode('; ', array_merge($scanCms['probleme'], $scanCms['necunoscute'])));
// Nicio regulă spre o coloană care nu există nicăieri (o regulă rămasă după o redenumire n-ar mai apăra nimic).
$existente = [];
foreach ([$bs, $cms] as $schema) {
    foreach ($schema as $tabel => $coloane) {
        foreach (array_keys($coloane) as $coloana) {
            $existente[$tabel . '.' . $coloana] = true;
        }
        $existente[$tabel] = true;
    }
}
$vechi = [];
foreach ((array) $reguli->date['tabele'] as $tabel => $regula) {
    if (!isset($existente[$tabel])) {
        $vechi[] = $tabel;
        continue;
    }
    foreach (array_merge((array) ($regula['pastreaza'] ?? []), array_keys((array) ($regula['transforma'] ?? []))) as $coloana) {
        if (!isset($existente[$tabel . '.' . $coloana])) {
            $vechi[] = $tabel . '.' . $coloana;
        }
    }
}
$verifica('fiecare tabel și fiecare coloană din reguli există în schema Bioscem sau a codului comun', $vechi === [], implode(', ', $vechi));
$personale = [];
foreach ($bs + $cms as $tabel => $coloane) {
    $regula = $reguli->tabel($tabel);
    if ($regula === null || $regula['mod'] === 'doar_structura') {
        continue;
    }
    foreach (array_keys($coloane) as $coloana) {
        if (preg_match(CitireLive::TIPAR_PERSONAL, $coloana) === 1 && !isset($regula['transforma'][$coloana]) && trim((string) ($regula['motive'][$coloana] ?? '')) === '') {
            $personale[] = $tabel . '.' . $coloana;
        }
    }
}
$verifica('fiecare coloană cu nume de date personale dintr-un tabel copiat e transformată sau păstrată cu motiv', $personale === [], implode(', ', $personale));
$verifica('tabelele cu date personale fără rânduri: acordurile GDPR, jurnalele, resetările de parolă, coșurile abandonate', count(array_filter(['gdpr_agreements',
    'admin_activity_log', 'bt_ipay_log', 'checkout_submit_logs', 'register_submit_logs', 'customer_password_resets', 'cart_abandonments'],
    static fn (string $t): bool => ($reguli->tabel($t)['mod'] ?? '') === 'doar_structura')) === 7);

// Fiecare fel de greșeală din reguli e prinsă.
$strica = static function (callable $schimba) use ($reguli): array {
    $date = $reguli->date;
    $schimba($date);
    return Reguli::din($date)->probleme();
};
$cazuri = [
    'o coloană personală păstrată fără motiv' => static function (array &$d): void {
        $d['tabele']['users']['pastreaza'][] = 'phone';
        unset($d['tabele']['users']['transforma']['phone']);
    },
    'o transformare necunoscută' => static function (array &$d): void {
        $d['tabele']['users']['transforma']['email'] = ['ascunde'];
    },
    'o coloană și păstrată, și transformată' => static function (array &$d): void {
        $d['tabele']['orders']['pastreaza'][] = 'notes';
    },
    'un tabel copiat în niciun grup' => static function (array &$d): void {
        $d['grupuri']['comenzi'] = array_values(array_diff($d['grupuri']['comenzi'], ['orders']));
    },
    'nume fără coloana emailului' => static function (array &$d): void {
        $d['tabele']['users']['transforma']['last_name'] = ['nume'];
    },
    'o condiție spre o coloană străină' => static function (array &$d): void {
        $d['tabele']['product_reviews']['transforma']['review_text'] = ['text', 'daca' => ['nu_exista' => 0]];
    },
    'doar_structura fără motiv' => static function (array &$d): void {
        $d['tabele']['gdpr_agreements']['motiv'] = '';
    },
    'un tabel „copiaza” cu transformări' => static function (array &$d): void {
        $d['tabele']['products']['transforma'] = ['name' => ['text']];
        $d['tabele']['products']['pastreaza'] = array_values(array_diff($d['tabele']['products']['pastreaza'], ['name']));
    },
    'versiunea lipsă' => static function (array &$d): void {
        unset($d['versiune']);
    },
    'o opțiune necunoscută' => static function (array &$d): void {
        $d['tabele']['users']['transforma']['email'] = ['email', 'sare' => 'x'];
    },
];
foreach ($cazuri as $ce => $schimba) {
    $verifica('regulile stricate se refuză: ' . $ce, $strica($schimba) !== []);
}

// Scanerul pe o sursă cu o coloană nouă, un tabel nou, un tip necopiabil.
$sursaNoua = $structuraDin($bs);
$sursaNoua['orders']['coloane']['telefon_livrare'] = ['tip' => 'varchar', 'nul' => true, 'lungime' => 30, 'generata' => false];
$sursaNoua['products']['coloane']['culoare_noua'] = ['tip' => 'varchar', 'nul' => true, 'lungime' => 30, 'generata' => false];
$sursaNoua['tabel_nou_test'] = ['tip' => 'BASE TABLE', 'coloane' => ['id' => ['tip' => 'int', 'nul' => false, 'lungime' => null, 'generata' => false]]];
$sursaNoua['pages']['coloane']['title']['tip'] = 'geometry';
$scan = $reguli->scaneaza($sursaNoua);
$verifica('scanerul: o coloană personală nouă (orders.telefon_livrare) și una doar necunoscută (products.culoare_noua) opresc, cu numele lor; '
    . 'un tip necopiabil oprește; un tabel nou = doar structura', count($scan['probleme']) === 3 && str_contains(implode(' ', $scan['probleme']), 'orders.telefon_livrare (nume de date personale)')
    && str_contains(implode(' ', $scan['probleme']), 'products.culoare_noua: coloană fără regulă') && str_contains(implode(' ', $scan['probleme']), 'pages.title: tipul geometry')
    && $scan['necunoscute'] === ['tabel_nou_test'], implode('; ', $scan['probleme']));
$sursaFan = $structuraDin($bs);
$sursaFan['fan_streets__sync'] = $sursaFan['fan_streets'];
$scan = $reguli->scaneaza($sursaFan);
$verifica('tabelele de lucru FAN (fan_streets__sync) iau regulile tabelului lor, în grupul lui', $scan['probleme'] === [] && in_array('fan_streets__sync', $scan['copiate'], true)
    && $reguli->grup('fan_streets__sync') === 'fan');

// Transformările.
$t = new Transformari(random_bytes(32));
$t2 = new Transformari(random_bytes(32));
$e1 = $t->email('Ana.Pop@Exemplu-Client.ro');
$verifica('E: u + 12 hex @verificare.invalid, aceeași adresă (litere mari, spații) = același pseudonim, altă sare = alt pseudonim, gol rămâne gol',
    preg_match('/^u[0-9a-f]{12}@verificare\.invalid$/', (string) $e1) === 1 && $t->email(' ana.pop@exemplu-client.ro ') === $e1 && $t2->email('ana.pop@exemplu-client.ro') !== $e1
    && $t->email('') === '' && $t->email(null) === null && $t->email('alta@exemplu-client.ro') !== $e1, (string) $e1);
$lista = (string) $t->emailuri('ana.pop@exemplu-client.ro; Ion@x.ro,  z@y.ro');
$verifica('E pe o listă de adrese (virgulă, punct și virgulă); o listă prea lungă pentru coloană pierde adresele de la coadă',
    count(explode(', ', $lista)) === 3 && str_starts_with($lista, (string) $e1) && count(explode(', ', (string) $t->emailuri('a@b.ro, c@d.ro, e@f.ro', 70))) === 2, $lista);
$tel = (string) $t->telefon('+40 (722) 123-456');
$verifica('T: 07 + 8 cifre, același număr scris altfel = același, gol rămâne gol', preg_match('/^07\d{8}$/', $tel) === 1 && $t->telefon('0040722123456') !== null
    && $t->telefon('40722123456') === $tel && $t->telefon('') === '', $tel);
$rand = ['id' => 7, 'first_name' => 'Ana', 'last_name' => 'Pop', 'email' => 'Ana.Pop@exemplu-client.ro', 'phone' => '0722123456', 'birth_date' => '1990-01-01',
    'gender' => 'f', 'password_hash' => 'parola-in-clar', 'google_id' => '123', 'loyalty_points' => 5, 'created_at' => '2026-01-01 10:00:00'];
$nou = $t->rand('users', (array) $reguli->tabel('users'), $rand, ['birth_date' => ['nul' => true, 'tip' => 'date'], 'gender' => ['nul' => true, 'tip' => 'varchar']]);
$comanda = $t->rand('orders', (array) $reguli->tabel('orders'), ['id' => 99, 'billing_first_name' => 'Ana', 'billing_last_name' => 'Pop', 'billing_email' => 'ana.pop@exemplu-client.ro',
    'shipping_first_name' => '', 'shipping_last_name' => 'Pop', 'billing_address_line1' => 'Str. Lungă 5', 'billing_postcode' => '400001', 'billing_city' => 'Cluj-Napoca',
    'billing_company_name' => 'Firma Reală SRL', 'billing_company_tax_id' => 'RO123', 'billing_company_registration_no' => 'J12/1/2020', 'total' => '123.45',
    'fan_awb' => '2345', 'fan_awb_list' => json_encode([['awb' => '2345', 'tracking_url' => 'https://x/2345', 'gestiune' => 'Depozit', 'colet' => 1],
        ['awb' => '2346', 'tracking_url' => 'https://x/2346', 'colet' => 2]]), 'fan_tracking_url' => 'https://x/2345', 'notes' => 'Sunați la 0722…',
    'stripe_session_id' => 'cs_live_1', 'erp_problems' => 'x', 'coupon_code' => null], ['erp_problems' => ['nul' => true, 'tip' => 'text']]);
$lista = json_decode((string) $comanda['fan_awb_list'], true);
$verifica('rândul unui client: prenumele „Client”, numele din H(email) (același în comenzile lui), emailul E, telefonul T, data nașterii, genul și Google NULL, '
    . 'parola bcrypt aleatoare, punctele și data neschimbate', $nou['first_name'] === 'Client' && preg_match('/^[0-9A-F]{6}$/', (string) $nou['last_name']) === 1
    && $nou['last_name'] === $comanda['billing_last_name'] && $comanda['shipping_last_name'] === $nou['last_name'] && $comanda['shipping_first_name'] === ''
    && $nou['email'] === $e1 && $nou['phone'] === $t->telefon('0722123456') && $nou['birth_date'] === null && $nou['gender'] === null && $nou['google_id'] === null
    && str_starts_with((string) $nou['password_hash'], '$2y$04$') && strlen((string) $nou['password_hash']) === 60 && $nou['loyalty_points'] === 5
    && $nou['created_at'] === '2026-01-01 10:00:00', json_encode($nou) ?: '');
$verifica('rândul unei comenzi: adresa „Strada Verificare N”, codul poștal 000000, orașul păstrat, firma/CUI/registrul inventate, totalul păstrat, AWB-urile '
    . 'DEMO<id> (lista: primul = AWB-ul comenzii, al doilea DEMO<id>-2, adresele de urmărire refăcute), textul liber anonimizat, referința Stripe anon-<id>',
    $comanda['billing_address_line1'] === 'Strada Verificare 100' && $comanda['billing_postcode'] === '000000' && $comanda['billing_city'] === 'Cluj-Napoca'
    && preg_match('/^Firma [0-9A-F]{6} SRL$/', (string) $comanda['billing_company_name']) === 1 && preg_match('/^RO\d{8}$/', (string) $comanda['billing_company_tax_id']) === 1
    && $comanda['billing_company_registration_no'] === 'J00/0000/2000' && $comanda['total'] === '123.45' && $comanda['fan_awb'] === 'DEMO99'
    && ($lista[0]['awb'] ?? '') === 'DEMO99' && ($lista[1]['awb'] ?? '') === 'DEMO99-2' && ($lista[0]['gestiune'] ?? '') === 'Depozit'
    && str_contains((string) ($lista[1]['tracking_url'] ?? ''), 'DEMO99-2') && str_contains((string) $comanda['fan_tracking_url'], 'tracking=DEMO99')
    && $comanda['notes'] === '[text anonimizat, 15 caractere]' && $comanda['stripe_session_id'] === 'anon-99' && $comanda['erp_problems'] === null
    && $comanda['coupon_code'] === null, json_encode($comanda, JSON_UNESCAPED_UNICODE) ?: '');
$cupon = $t->rand('coupons', (array) $reguli->tabel('coupons'), ['id' => 12, 'code' => 'UNIC-ABC', 'name' => 'Cupon unic-abc pentru Ana', 'is_unique' => 1], []);
$cuponObisnuit = $t->rand('coupons', (array) $reguli->tabel('coupons'), ['id' => 13, 'code' => 'VARA10', 'name' => 'Vara', 'is_unique' => 0], []);
$cuComanda = $t->rand('orders', (array) $reguli->tabel('orders'), ['id' => 5, 'coupon_code' => ' unic-abc ', 'billing_email' => ''], []);
$faraComanda = $t->rand('orders', (array) $reguli->tabel('orders'), ['id' => 6, 'coupon_code' => 'VARA10', 'billing_email' => ''], []);
$verifica('cupoanele: un cod unic devine VERIF-<id> (și în comenzile care îl au, oricum ar fi scris), iar numele lui „Cupon unic VERIF-<id>” (putea numi '
    . 'clientul); un cod obișnuit și numele lui rămân', $cupon['code'] === 'VERIF-12' && $cupon['name'] === 'Cupon unic VERIF-12' && $cuponObisnuit['code'] === 'VARA10'
    && $cuponObisnuit['name'] === 'Vara' && $cuComanda['coupon_code'] === 'VERIF-12'
    && $faraComanda['coupon_code'] === 'VARA10', json_encode([$cupon, $cuComanda]) ?: '');
$recenzii = [$t->rand('product_reviews', (array) $reguli->tabel('product_reviews'), ['id' => 1, 'user_name' => 'Ana', 'user_email' => 'a@b.ro', 'review_text' => 'Secret', 'is_approved' => 0], []),
    $t->rand('product_reviews', (array) $reguli->tabel('product_reviews'), ['id' => 2, 'user_name' => 'Ion', 'user_email' => null, 'review_text' => 'Public', 'is_approved' => 1], [])];
$vechea = $t->rand('reviews', (array) $reguli->tabel('reviews'), ['id' => 9, 'user_name' => ' ion ', 'review_text' => 'Public', 'is_approved' => 1], []);
$verifica('recenziile: textul celor nemoderate anonimizat, al celor aprobate (publice) păstrat; numele „Client …” din H(nume): același nume, același '
    . 'pseudonim și în tabelul vechi reviews (migrarea lui le potrivește după nume), alt nume, alt pseudonim', $recenzii[0]['review_text'] === '[text anonimizat, 6 caractere]'
    && $recenzii[1]['review_text'] === 'Public' && preg_match('/^Client [0-9A-F]{6}$/', (string) $recenzii[1]['user_name']) === 1 && $recenzii[1]['user_email'] === null
    && $vechea['user_name'] === $recenzii[1]['user_name'] && $recenzii[0]['user_name'] !== $recenzii[1]['user_name']);
$puncte = [$t->rand('loyalty_points_transactions', (array) $reguli->tabel('loyalty_points_transactions'), ['id' => 1, 'admin_id' => 3, 'reason' => 'Bonus pentru Ana', 'meta_json' => '{}'], []),
    $t->rand('loyalty_points_transactions', (array) $reguli->tabel('loyalty_points_transactions'), ['id' => 2, 'admin_id' => null, 'reason' => 'Comanda 203801', 'meta_json' => '{}'], [])];
$verifica('punctele: motivul scris de un admin devine „Ajustare manuală”, cel automat rămâne; meta_json NULL', $puncte[0]['reason'] === 'Ajustare manuală'
    && $puncte[1]['reason'] === 'Comanda 203801' && $puncte[0]['meta_json'] === null);
$email = $t->rand('email_logs', (array) $reguli->tabel('email_logs'), ['id' => 1, 'email_type' => 'new_order', 'recipient' => 'a@b.ro, c@d.ro', 'subject' => 'Comanda ta, Ana',
    'error_message' => 'x'], []);
$verifica('emailurile trimise: destinatarii E, subiectul = felul emailului, eroarea NULL', $email['subject'] === 'new_order' && count(explode(', ', (string) $email['recipient'])) === 2
    && !str_contains((string) $email['recipient'], 'b.ro') && $email['error_message'] === null);
$mesaj = $t->rand('contact_form_messages', (array) $reguli->tabel('contact_form_messages'), ['id' => 4, 'name' => 'Ana', 'email' => 'a@b.ro', 'phone' => '0722', 'subject' => 'S',
    'message' => 'M', 'source_url' => 'https://bioscem.ro/contact?email=a@b.ro#x', 'ip_address' => '1.2.3.4', 'user_agent' => 'UA'], []);
$verifica('mesajele de contact: adresa paginii fără parametri, IP-ul și browserul NULL', $mesaj['source_url'] === 'https://bioscem.ro/contact' && $mesaj['ip_address'] === null
    && $mesaj['user_agent'] === null && str_starts_with((string) $mesaj['name'], 'Client '));
$admin = $t->rand('admins', (array) $reguli->tabel('admins'), ['id' => 3, 'email' => 'sef@bioscem.ro', 'password_hash' => '5f4dcc3b5aa765d61d8327deb882cf99', 'role' => 'x'], []);
$link = $t->rand('order_payment_links', (array) $reguli->tabel('order_payment_links'), ['id' => 2, 'token' => str_repeat('a', 64), 'ep_id' => null, 'approval' => 'A1'], []);
$verifica('adminii: admin<id>@verificare.invalid și o parolă bcrypt aleatoare; linkurile de plată: jeton nou de 64 hex, aprobarea anon-<id>, NULL rămâne NULL',
    $admin['email'] === 'admin3@verificare.invalid' && password_get_info((string) $admin['password_hash'])['algo'] !== null && $admin['role'] === 'x'
    && preg_match('/^[0-9a-f]{64}$/', (string) $link['token']) === 1 && $link['token'] !== str_repeat('a', 64) && $link['approval'] === 'anon-2' && $link['ep_id'] === null);
$verifica('NULL pe o coloană NOT NULL devine gol (sau 0); un text mai lung decât coloana se taie', Transformari::potriveste(null, ['nul' => false, 'tip' => 'varchar']) === ''
    && Transformari::potriveste(null, ['nul' => false, 'tip' => 'int']) === 0 && Transformari::potriveste(null, ['nul' => true]) === null
    && Transformari::potriveste('abcdef', ['lungime' => 3]) === 'abc');
$setari = (array) $reguli->date['setari'];
$neutru = static fn (string $cheie, string $valoare = 'valoare'): mixed => $t->setare($cheie, $valoare, $setari, 'proprietar@exemplu.ro', 'verificare@exemplu.ro');
$verifica('setările: cheile secrete (din listă și după tipar) golite, comutatoarele „0”, destinatarii = proprietarul (și după tipar), expeditorul = căsuța copiei, '
    . 'numele expeditorului cu „VERIFICARE ” o singură dată, cheia de mentenanță nouă (24 hex, aceeași la --reia), restul neatins',
    $neutru('stripe_secret_key') === '' && $neutru('o_cheie_noua_api_key') === '' && $neutru('erp_url') === '' && $neutru('maintenance_allowed_ips') === ''
    && $neutru('erp_enabled', '1') === '0' && $neutru('contact_form_recipients') === 'proprietar@exemplu.ro'
    && $neutru('email_template_shipped_admin_recipients') === 'proprietar@exemplu.ro' && $neutru('order_email_from_address') === 'verificare@exemplu.ro'
    && $neutru('order_email_from_name', 'Bioscem') === 'VERIFICARE Bioscem' && $neutru('order_email_from_name', 'VERIFICARE Bioscem') === 'VERIFICARE Bioscem'
    && preg_match('/^[0-9a-f]{24}$/', (string) $neutru('maintenance_key')) === 1 && $neutru('maintenance_key') === $neutru('maintenance_key', 'alta')
    && $neutru('store_name') === null && $neutru('design_antet_html') === null);

$cuLista = static fn (string $cheie, string $valoare): ?bool => Incarcare::setareNeutralizata($cheie, $valoare, $setari, 'proprietar@exemplu.ro', 'verificare@exemplu.ro');
$verifica('A6 se socotește din lista regulilor, nu cu transformarea: o valoare rămasă a site-ului pică (cheia secretă, comutatorul pornit, alt destinatar, '
    . 'numele expeditorului fără VERIFICARE, cheia de mentenanță veche), valoarea copiei trece, o cheie din afara listei nu se verifică',
    $cuLista('stripe_secret_key', 'sk_live_1') === false && $cuLista('stripe_secret_key', '') === true && $cuLista('o_cheie_noua_api_key', 'x') === false
    && $cuLista('erp_enabled', '1') === false && $cuLista('erp_enabled', '0') === true && $cuLista('contact_form_recipients', 'alt@exemplu.ro') === false
    && $cuLista('email_template_shipped_admin_recipients', 'proprietar@exemplu.ro') === true && $cuLista('order_email_from_address', 'verificare@exemplu.ro') === true
    && $cuLista('order_email_from_name', 'Bioscem') === false && $cuLista('order_email_from_name', 'VERIFICARE Bioscem') === true
    && $cuLista('maintenance_key', 'cheie-veche') === false && $cuLista('maintenance_key', str_repeat('a', 24)) === true && $cuLista('store_name', 'orice') === null);

// Codul vechi: ce se copiază.
$copiate = [];
foreach (['public/index.php', 'src/Support/X.php', 'views/a.php', 'public/assets/app.css', 'public/assets/app.js', 'public/assets/logo.png', 'database/schema.sql',
    'config/app.php', 'composer.json', 'public/.htaccess', '.htaccess', 'storage/sessions/.gitkeep', 'public/robots.txt', 'README.md', '.env.example'] as $fisier) {
    if (Incarcare::motivSarit($fisier) !== null) {
        $copiate[] = $fisier;
    }
}
$sarite = [];
foreach (['.env', '.env.backup', '.env.2026', 'storage/sessions/sess_abc', 'storage/import/dump.sql', 'storage/logs/php.log', 'public/error_log', 'error_log',
    'public/sitemap.xml', 'public/sitemap-produse.xml', 'backup.sql', 'comenzi.csv', 'arhiva.zip', 'export.tar', 'x.log', 'public/uploads-fara-extensie'] as $fisier) {
    if (Incarcare::motivSarit($fisier) === null) {
        $sarite[] = $fisier;
    }
}
$verifica('codul vechi: se copiază codul (php, css, js, poze ale temei, schema.sql, .htaccess, .gitkeep din storage, .env.example); se sar .env*, storage/, '
    . 'jurnalele, sitemap-urile, copiile de bază, exporturile și arhivele', $copiate === [] && $sarite === [], implode(', ', array_merge($copiate, $sarite)));

// Garda instrucțiunilor sursei.
$refuzata = static function (string $sql): bool {
    try {
        SursaCopie::verificaInstructiunea($sql);
        return false;
    } catch (InstructiuneRefuzata) {
        return true;
    }
};
$verifica('sursa: garda primește SELECT/SHOW (și coloanele updated_at, deleted_at) și refuză orice scriere, lacăt, comentariu sau a doua instrucțiune',
    !$refuzata('SELECT `id`, `updated_at`, `deleted_at` FROM `orders`') && !$refuzata('SHOW CREATE TABLE `orders`')
    && $refuzata('INSERT INTO orders VALUES (1)') && $refuzata('UPDATE orders SET x = 1') && $refuzata('DELETE FROM orders') && $refuzata('SELECT 1; DROP TABLE x')
    && $refuzata('SELECT * FROM orders FOR UPDATE') && $refuzata('SELECT * FROM orders LOCK IN SHARE MODE') && $refuzata('SELECT 1 /* x */')
    && $refuzata('SELECT * INTO OUTFILE "/tmp/x" FROM orders') && $refuzata('SELECT SLEEP(5)') && $refuzata('CREATE TABLE x (id INT)')
    && $refuzata('SELECT GET_LOCK("x", 1)') && $refuzata('SHOW CREATE TABLE `x`, `y`') && $refuzata('SHOW CREATE PROCEDURE `x`'));

// Un PHP prea vechi: mesajul, nu o eroare de sintaxă.
$phpMinim = preg_match("/'php_min'\\s*=>\\s*'([0-9.]+)'/", (string) file_get_contents($radacina . '/config/app.php'), $p) === 1 ? $p[1] : '';
$phpVechi = array_values(array_filter(['php7.0', 'php7.4', 'php8.0', 'php8.1', 'php8.2'], static fn (string $b): bool
    => version_compare(substr($b, 3), $phpMinim, '<') && trim((string) shell_exec('command -v ' . $b . ' 2>/dev/null')) !== ''));
if ($phpVechi === []) {
    $scrie('  INFO  niciun PHP sub minimul ' . $phpMinim . ' instalat: mesajul pentru un PHP prea vechi nu s-a verificat acum');
}
foreach ($phpVechi as $binar) {
    $iesire = [];
    exec(escapeshellarg($binar) . ' ' . escapeshellarg($radacina . '/tools/verificare/incarca.php') . ' --din=bioscem 2>&1', $iesire, $cod);
    $verifica($binar . ': incarca.php spune că PHP-ul e prea vechi (cod 1, fără eroare de sintaxă)', $cod === 1
        && str_contains(implode("\n", $iesire), 'mai vechi decât minimul') && !str_contains(implode("\n", $iesire), 'Parse error'), implode(' ', $iesire));
}

if ($faraBaza) {
    $scrie('');
    $scrie('Rezultat (doar A, --fara-baza): ' . $trecute . ' trecute, ' . $esuate . ' eșuate');
    exit($esuate === 0 ? 0 : 1);
}

// =====================================================================
// B. Găzduirea simulată
// =====================================================================
$m = 'mrk' . bin2hex(random_bytes(5));
$M = strtoupper($m);
$telefon = '07' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
$port = (int) (getenv('CMS_DEV_PORT') ?: 8701) + 15;
$dosarTest = $dosarDev . '/incarcare';
$acasa = $dosarTest . '/acasa';
$gazda = 'verificare.exemplu.test';
$copie = $acasa . '/domains/' . $gazda . '/public_html';
$codVechi = $acasa . '/domains/' . $gazda . '/cod-vechi';
$live = $acasa . '/domains/bioscem.ro/public_html';
$sockMaria = (string) (getenv('CMS_DEV_SOCK') ?: '/tmp/claude-0/maria/sock');
$sockMy8 = (string) (getenv('CMS_DEV_SOCK_MYSQL8') ?: '/tmp/claude-0/my8/run/sock');
$bazaSursa = 'cms_incarc_sursa';
$bazaTinta = 'cms_incarc_tinta';
$bazaRef = 'cms_incarc_ref';
$utilizatorLive = 'cms_incarc_live';
$utilizatorCitire = 'cms_incarc_citire';
$utilizatorTinta = 'cms_incarc_tinta';
$parolaLive = 'Pl' . bin2hex(random_bytes(8));
$parolaCitire = 'Pc' . bin2hex(random_bytes(8));
$parolaTinta = 'Pt' . bin2hex(random_bytes(8));
$parolaPoarta = 'Poarta-' . bin2hex(random_bytes(8));
$parolaAdmin = 'Admin-' . bin2hex(random_bytes(8));
$parolaPersonal = 'Personal-' . bin2hex(random_bytes(8));
$emailProprietar = 'proprietar.verificare@exemplu.ro';
// Clientul inventat al rândurilor cu marcaj: aceeași adresă în toate tabelele (pseudonimul trebuie să fie același peste tot).
$emailClient = 'client.' . $m . '@exemplu-client.ro';
$jeton = bin2hex(random_bytes(32));
$btTest = ['BT_IPAY_TEST_USER' => 'bttu' . $m, 'BT_IPAY_TEST_PASS' => 'bttp' . $m, 'BT_IPAY_TEST_CALLBACK_KEY' => 'bttk' . $m];
$secrete = [$m, $M, $telefon, $parolaLive, $parolaCitire, $parolaTinta, $parolaPoarta, $parolaAdmin, $parolaPersonal, $emailProprietar, $jeton,
    $bazaSursa, $bazaTinta, $utilizatorLive, $utilizatorCitire, $dosarTest, 'exemplu-client.ro'];
// Ce nu are voie să ajungă în baza copiei (căutat în fiecare coloană de text a fiecărui tabel): marcajul, telefonul,
// jetonul, domeniul adreselor clientului inventat, parolele în clar.
$cautateInCopie = [strtolower($m), $telefon, $jeton, 'exemplu-client.ro', 'bioscem-test.ro'];
$iesiri = '';
$pidServer = 0;
$mysql8Pornit = false;

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
$opresteServerul = static function () use (&$pidServer): void {
    if ($pidServer > 0) {
        exec('pkill -TERM -P ' . $pidServer . ' 2>/dev/null; kill ' . $pidServer . ' 2>/dev/null');
        for ($i = 0; $i < 50 && trim((string) shell_exec('pgrep -P ' . $pidServer . ' 2>/dev/null; kill -0 ' . $pidServer . ' 2>/dev/null && echo x')) !== ''; $i++) {
            usleep(100000);
        }
    }
    $pidServer = 0;
};
$curata = static function () use ($conectare, $merge, $sockMaria, $sockMy8, $bazaSursa, $bazaTinta, $bazaRef, $utilizatorLive, $utilizatorCitire,
    $utilizatorTinta, $dosarTest, $dosarDev, $opresteServerul): void {
    $opresteServerul();
    foreach ([$sockMaria, $sockMy8] as $sock) {
        if (!$merge($sock)) {
            continue;
        }
        $root = $conectare($sock);
        foreach ([$bazaSursa, $bazaTinta, $bazaRef] as $b) {
            $root->exec('DROP DATABASE IF EXISTS ' . $b);
        }
        foreach ([$utilizatorLive, $utilizatorCitire, $utilizatorTinta] as $u) {
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

/** Mediul uneltelor de pe server: gol, ca pe Hostinger; cererile spre internet spre un port pe care nu ascultă nimic. */
$mediu = static function (string $acasaData): array {
    $gaura = 'http://127.0.0.1:9';
    return ['PATH' => (string) getenv('PATH'), 'HOME' => $acasaData, 'LANG' => 'C.UTF-8', 'http_proxy' => $gaura, 'https_proxy' => $gaura,
        'HTTP_PROXY' => $gaura, 'HTTPS_PROXY' => $gaura, 'ALL_PROXY' => $gaura, 'all_proxy' => $gaura, 'NO_PROXY' => '127.0.0.1,localhost',
        'no_proxy' => '127.0.0.1,localhost'];
};
/**
 * Un script al site-ului de verificare (din $copie, sau o cale absolută), cu un mediu gol, erorile PHP pe ecran (uneltele
 * le înlocuiesc cu texte fixe), intrarea dată pe stdin și un termen (o unealtă agățată e un eșec, nu un test oprit).
 */
$ruleaza = static function (string $script, array $argumente, string $intrare = '', array $optiuni = []) use ($copie, $acasa, $sockMaria, $mediu, &$iesiri): array {
    $ini = ['-d', 'pdo_mysql.default_socket=' . ($optiuni['sock'] ?? $sockMaria), '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL',
        '-d', 'log_errors=0', '-d', 'memory_limit=512M', '-d', 'opcache.enable_cli=0', '-d', 'allow_url_fopen=0'];
    $comanda = array_merge([$optiuni['php'] ?? PHP_BINARY], $ini, [str_starts_with($script, '/') ? $script : $copie . '/' . $script], $argumente);
    $proces = proc_open($comanda, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tevi, $optiuni['cwd'] ?? $copie, $mediu($optiuni['home'] ?? $acasa));
    if (!is_resource($proces)) {
        return ['cod' => -1, 'iesire' => '', 'erori' => 'nu pornește', 'tot' => 'nu pornește'];
    }
    fwrite($tevi[0], $intrare);
    fclose($tevi[0]);
    stream_set_blocking($tevi[1], false);
    stream_set_blocking($tevi[2], false);
    $iesire = '';
    $erori = '';
    $termen = microtime(true) + ($optiuni['timp'] ?? 600);
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
    $cod = proc_close($proces);
    if ($depasit) {
        $cod = -2;
        $erori .= "\n[test-incarcare: TIMP DEPĂȘIT]";
    }
    $iesiri .= $iesire . $erori;
    return ['cod' => $cod, 'iesire' => $iesire, 'erori' => $erori, 'tot' => $iesire . $erori];
};
/** incarca.php cu răspunsurile date (câte un rând fiecare). */
$incarca = static fn (array $argumente, array $raspunsuri = [], array $optiuni = []): array
    => $ruleaza('tools/verificare/incarca.php', $argumente, implode("\n", $raspunsuri) . "\n", $optiuni);
// Răspunsurile obișnuite cu --da: emailul contului de admin (Enter = VERIFICARE_EMAIL), parola, încă o dată.
$raspunsuriAdmin = ['', $parolaAdmin, $parolaAdmin];
$ultimul = static fn (string $text): string => trim((string) strrchr("\n" . trim($text), "\n"));
$faraSecrete = static function (string $text) use (&$secrete): string {
    $gasite = [];
    foreach ($secrete as $secret) {
        if ($secret !== '' && stripos($text, $secret) !== false) {
            $gasite[] = substr($secret, 0, 6) . '…';
        }
    }
    return implode(', ', $gasite);
};
/** Ieșirea curată a unei rulări: fără avertismentele PHP (înlocuite de intrare.php), fără secrete. */
$curat = static fn (array $r): bool => !preg_match('/(AVERTISMENT PHP|Warning:|Fatal error|Deprecated:|Notice:)/', $r['tot']) && $faraSecrete($r['tot']) === '';
$descrie = static fn (array $r): string => 'cod ' . $r['cod'] . ': ' . mb_substr($r['tot'], -2500) . ($faraSecrete($r['tot']) !== '' ? ' [SECRETE: ' . $faraSecrete($r['tot']) . ']' : '');
$tabele = static function (PDO $db): array {
    return $db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
};
$sume = static function (PDO $db) use ($tabele): array {
    $r = [];
    foreach ($tabele($db) as $tabel) {
        $r[$tabel] = (string) $db->query('CHECKSUM TABLE `' . $tabel . '`')->fetch(PDO::FETCH_NUM)[1];
    }
    return $r;
};
/** Toate fișierele și dosarele de sub $dosar (mărime, dată, drepturi, legături), ca să se vadă că nimic nu s-a schimbat. */
$instantaneu = static function (string $dosar): array {
    $r = [];
    if (!is_dir($dosar)) {
        return $r;
    }
    clearstatcache();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dosar, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $r[$f->getPathname()] = ($f->isLink() ? 'l:' . readlink($f->getPathname()) : ($f->isDir() ? 'd' : 'f:' . $f->getSize() . ':' . md5_file($f->getPathname())))
            . ':' . $f->getMTime() . ':' . substr(sprintf('%o', $f->getPerms()), -4);
    }
    ksort($r);
    return $r;
};
/** Coloanele de text ale fiecărui tabel care au una din valorile căutate (litere mici sau mari), ca listă „tabel.coloana”. */
$cuValori = static function (PDO $db, array $cautate) use ($tabele): array {
    $gasite = [];
    $coloane = $db->query("SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND DATA_TYPE IN ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json', 'enum', 'set', 'binary', 'varbinary', 'tinyblob',
        'blob', 'mediumblob', 'longblob')")->fetchAll();
    foreach ($coloane as $c) {
        $conditii = [];
        foreach ($cautate as $v) {
            $conditii[] = 'LOWER(CONVERT(`' . $c['c'] . '` USING utf8mb4)) LIKE ' . $db->quote('%' . strtolower((string) $v) . '%');
        }
        if ((int) $db->query('SELECT COUNT(*) FROM `' . $c['t'] . '` WHERE ' . implode(' OR ', $conditii))->fetchColumn() > 0) {
            $gasite[] = $c['t'] . '.' . $c['c'];
        }
    }
    return $gasite;
};
/** Toate valorile de text ale bazei (sau ale coloanelor date), legate, ca să se caute în ele valori întregi. */
$textBaza = static function (PDO $db, ?array $doar = null): string {
    $text = '';
    $coloane = $db->query("SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND DATA_TYPE IN ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json') AND TABLE_NAME <> '_verificare'")->fetchAll();
    foreach ($coloane as $c) {
        if ($doar !== null && !isset($doar[$c['t'] . '.' . $c['c']])) {
            continue;
        }
        foreach ($db->query('SELECT `' . $c['c'] . '` FROM `' . $c['t'] . '` WHERE `' . $c['c'] . '` IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $text .= "\x1f" . $v;
        }
    }
    return $text;
};

/** O bază făcută de tools/dev (php.sh) cu un script al proiectului: [cod, ieșire]. */
$construieste = static function (string $sock, string $baza, array $script, array $mediuInPlus = []) use ($radacina, $dosarTest): array {
    $mediuProces = getenv();
    $mediuProces['CMS_DEV_DB'] = $baza;
    $mediuProces['CMS_DEV_SOCK'] = $sock;
    $mediuProces['CMS_DEV_LICENTA_DOSAR'] = $dosarTest . '/licenta-' . $baza;
    @mkdir($dosarTest . '/licenta-' . $baza, 0770, true);
    $proces = proc_open(array_merge([$radacina . '/tools/dev/php.sh'], $script), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $tevi, $radacina,
        $mediuInPlus + $mediuProces);
    fclose($tevi[0]);
    $iesire = (string) stream_get_contents($tevi[1]);
    fclose($tevi[1]);
    return [proc_close($proces), $iesire];
};

/** Coloanele unui tabel din information_schema: nume → [tip, nul, implicit, extra, lungime, tip_complet]. */
$coloaneTabel = static function (PDO $db, string $tabel): array {
    $s = $db->prepare('SELECT COLUMN_NAME AS c, DATA_TYPE AS tip, IS_NULLABLE AS nul, COLUMN_DEFAULT AS implicit, EXTRA AS extra,
        CHARACTER_MAXIMUM_LENGTH AS lungime, COLUMN_TYPE AS complet FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ORDER BY ORDINAL_POSITION');
    $s->execute([$tabel]);
    $r = [];
    foreach ($s->fetchAll() as $c) {
        $r[(string) $c['c']] = $c;
    }
    return $r;
};
/**
 * Un rând nou într-un tabel: valorile date, iar pentru celelalte coloane NOT NULL fără valoare implicită una potrivită
 * tipului (inventată, fără marcaj). Cheile străine sunt oprite (rândurile inventate nu țin de ordinea tabelelor).
 */
$randNou = static function (PDO $db, string $tabel, array $valori) use ($coloaneTabel): int {
    $rand = [];
    foreach ($coloaneTabel($db, $tabel) as $nume => $c) {
        if (array_key_exists($nume, $valori)) {
            $rand[$nume] = $valori[$nume];
            continue;
        }
        $generata = preg_match('/(VIRTUAL|STORED|PERSISTENT|auto_increment)/i', (string) $c['extra']) === 1;
        if ($generata || $c['nul'] === 'YES' || ($c['implicit'] !== null && strtoupper((string) $c['implicit']) !== 'NULL')) {
            continue;
        }
        $rand[$nume] = match (true) {
            in_array($c['tip'], ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double'], true) => 1,
            $c['tip'] === 'enum' => preg_match("/^enum\\('((?:[^']|'')*)'/", (string) $c['complet'], $p) === 1 ? str_replace("''", "'", $p[1]) : '',
            $c['tip'] === 'date' => '2026-01-02',
            in_array($c['tip'], ['datetime', 'timestamp'], true) => '2026-01-02 10:00:00',
            $c['tip'] === 'time' => '10:00:00',
            default => substr('v' . bin2hex(random_bytes(6)), 0, max(1, min(13, (int) ($c['lungime'] ?? 13)))),
        };
    }
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    $db->prepare('INSERT INTO `' . $tabel . '` (`' . implode('`, `', array_keys($rand)) . '`) VALUES (' . implode(', ', array_fill(0, count($rand), '?')) . ')')
        ->execute(array_values($rand));
    // Înaintea oricărei alte instrucțiuni (după un SET, id-ul ultimului rând scris e 0).
    $id = (int) $db->lastInsertId();
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    return $id;
};
/**
 * Baza „live” inventată: rândurile cu marcaj în fiecare coloană transformată de reguli (adrese, telefoane, nume, texte
 * libere, jetoane, AWB-uri, coduri unice, parole în clar), în fiecare tabel „doar structura” (toate coloanele de text) și în
 * setările din lista neutralizării; plus seo_pages pentru categorii și mărci, avertismentele, un tabel de lucru FAN și un
 * tabel necunoscut. Întoarce id-urile rândurilor cu marcaj, pe tabel.
 */
$umple = static function (PDO $db) use ($reguli, $randNou, $coloaneTabel, $m, $M, $telefon, $emailClient, $jeton): array {
    $db->exec("SET time_zone = '+00:00'");
    $valoare = static function (string $tabel, string $coloana, string $fel, array $c) use ($m, $M, $telefon, $emailClient, $jeton): mixed {
        if (in_array($c['tip'], ['date', 'datetime', 'timestamp'], true)) {
            return $c['tip'] === 'date' ? '1990-02-03' : '2026-02-03 04:05:06';
        }
        if (in_array($c['tip'], ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal'], true)) {
            return 7;
        }
        $json = str_ends_with($coloana, '_json') || $coloana === 'erp_problems';
        $v = match ($fel) {
            'email' => $emailClient,
            'email_admin' => 'admin.' . $m . '@bioscem-test.ro',
            'emailuri' => $emailClient . ', alt.' . $m . '@exemplu-client.ro',
            'telefon' => $telefon,
            'parola' => 'parola-' . $m,
            'token' => $jeton,
            'cod_cupon' => 'UNIC-' . $M,
            'nume_cupon' => 'Cupon UNIC-' . $M . ' pentru ' . $m,
            'cupon_comanda' => ' unic-' . $m . ' ',
            'awb' => 'AWB' . $M,
            'awb_lista' => (string) json_encode([['awb' => 'AWB' . $M, 'tracking_url' => 'https://www.fancourier.ro/awb-tracking/?tracking=AWB' . $M, 'colet' => 1],
                ['awb' => 'AWB2' . $M, 'tracking_url' => 'https://www.fancourier.ro/awb-tracking/?tracking=AWB2' . $M, 'colet' => 2]]),
            'url_urmarire' => 'https://www.fancourier.ro/awb-tracking/?tracking=AWB' . $M,
            'url_fara_interogare' => 'https://bioscem.ro/contact?email=' . $emailClient . '&m=' . $m,
            'cui' => 'RO' . $m,
            default => $json ? (string) json_encode(['m' => $m, 'email' => $emailClient, 'telefon' => $telefon])
                : 'Text ' . $m . ' de la ' . $emailClient . ', tel. ' . $telefon,
        };
        $lungime = $c['lungime'] !== null ? (int) $c['lungime'] : null;
        if ($lungime !== null && mb_strlen($v) > $lungime) {
            $v = mb_substr($m . ' ' . $v, 0, $lungime);
        }
        return $v;
    };
    $ids = [];
    // Întâi clientul, ca rândurile celorlalte tabele să fie ale lui.
    $ordine = array_keys((array) $reguli->date['tabele']);
    usort($ordine, static fn (string $a, string $b): int => ($b === 'users') <=> ($a === 'users') ?: ($b === 'orders') <=> ($a === 'orders'));
    foreach ($ordine as $tabel) {
        $regula = (array) $reguli->date['tabele'][$tabel];
        $coloane = $coloaneTabel($db, $tabel);
        if ($coloane === [] || !in_array($regula['mod'], ['anonimizeaza', 'doar_structura'], true)) {
            continue;
        }
        $valori = [];
        if ($regula['mod'] === 'doar_structura') {
            foreach ($coloane as $nume => $c) {
                if (in_array($c['tip'], ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'], true) && stripos((string) $c['extra'], 'generated') === false) {
                    $valori[$nume] = $valoare($tabel, $nume, $nume === 'email' || str_ends_with($nume, '_email') ? 'email' : 'text', $c);
                }
            }
        } else {
            foreach ((array) $regula['transforma'] as $nume => $t) {
                if (isset($coloane[$nume])) {
                    $valori[$nume] = $valoare($tabel, $nume, (string) $t[0], $coloane[$nume]);
                }
            }
        }
        $legaturi = [
            'coupons' => ['is_unique' => 1],
            'product_reviews' => ['is_approved' => 0],
            'reviews' => ['is_approved' => 0],
            'loyalty_points_transactions' => ['admin_id' => 1, 'user_id' => $ids['users'] ?? null],
            'orders' => ['user_id' => $ids['users'] ?? null, 'billing_city' => 'Cluj-Napoca', 'total' => '123.45'],
            'user_addresses' => ['user_id' => $ids['users'] ?? null],
            'order_payment_links' => ['order_id' => $ids['orders'] ?? null],
            'bt_ipay_transactions' => ['order_id' => $ids['orders'] ?? null],
            'email_logs' => ['order_id' => $ids['orders'] ?? null, 'email_type' => 'new_order'],
            'email_send_history' => ['order_id' => $ids['orders'] ?? null, 'email_type' => 'new_order'],
        ];
        foreach ($legaturi[$tabel] ?? [] as $nume => $v) {
            if (isset($coloane[$nume])) {
                $valori[$nume] = $v;
            }
        }
        try {
            $ids[$tabel] = $randNou($db, $tabel, $valori);
        } catch (PDOException $e) {
            throw new RuntimeException('rândul cu marcaj în ' . $tabel . ': ' . $e->getMessage());
        }
    }
    // Setările din lista neutralizării, cu valorile unui site adevărat (inventate, cu marcaj).
    $setari = ['stripe_secret_key' => 'sk_live_' . $m, 'erp_api_key' => 'erp' . $m, 'o_cheie_noua_api_key' => 'k' . $m, 'smtp_password' => 'smtp' . $m,
        'smtp_host' => 'smtp.' . $m . '.bioscem-test.ro', 'erp_enabled' => '1', 'stripe_enabled' => '1', 'bt_ipay_enabled' => '1', 'fan_awb_auto' => '1',
        'contact_form_recipients' => 'dest.' . $m . '@exemplu-client.ro', 'email_template_comanda_noua_admin_recipients' => 'admin.' . $m . '@exemplu-client.ro',
        'bt_ipay_notify_emails' => $emailClient, 'order_email_from_address' => 'comenzi.' . $m . '@bioscem-test.ro', 'order_email_from_name' => 'Bioscem',
        'maintenance_key' => 'mk' . $m, 'maintenance_allowed_ips' => '10.1.2.3', 'fan_sender_phone' => $telefon, 'fan_cod_iban' => 'RO49' . $M,
        'google_analytics_id' => 'G-' . $M, 'tawk_property_id' => 'tawk' . $m];
    $s = $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    foreach ($setari as $cheie => $v) {
        $s->execute([$cheie, $v]);
    }
    // Conținut copiat cum e: titlurile pentru Google ale unei categorii și ale unei mărci, avertismentul unui produs și ce a
    // confirmat clientul pe o comandă (Bioscem 0582ce9, 5f2e483), un cupon obișnuit.
    $produs = (int) $db->query('SELECT MIN(id) FROM products')->fetchColumn();
    $categorie = (string) $db->query('SELECT slug FROM product_categories ORDER BY id LIMIT 1')->fetchColumn();
    $randNou($db, 'seo_pages', ['page_type' => 'category', 'page_ref' => $categorie, 'title' => 'Categoria pentru Google', 'description' => 'Descrierea categoriei']);
    $randNou($db, 'seo_pages', ['page_type' => 'brand', 'page_ref' => 'Marca Demo', 'title' => 'Marca pentru Google', 'description' => 'Descrierea mărcii']);
    $randNou($db, 'product_warnings', ['product_id' => $produs, 'title' => 'Produs refrigerat', 'body' => 'Se păstrează la frigider; întrebări la contact@bioscem.ro',
        'checkbox_label' => 'Am înțeles', 'updated_at' => '2026-10-20 10:00:00']);
    $randNou($db, 'order_product_warnings', ['order_id' => $ids['orders'], 'title' => 'Produs refrigerat', 'body' => 'Se păstrează la frigider',
        'checkbox_label' => 'Am înțeles', 'checkbox_ticked' => 1, 'product_ids_json' => '[' . $produs . ']', 'product_names_json' => '["Produs"]',
        'confirmed_at' => '2026-10-20 10:00:00']);
    $randNou($db, 'coupons', ['code' => 'VARA10', 'name' => 'Vara', 'type' => 'percent', 'value' => 10, 'is_unique' => 0]);
    // Un tabel de lucru al sincronizării FAN (regulile tabelului lui) și unul necunoscut (doar structura, niciun rând).
    $db->exec('CREATE TABLE fan_streets__sync LIKE fan_streets');
    $randNou($db, 'fan_streets__sync', ['county' => 'Cluj', 'locality' => 'Cluj-Napoca', 'street' => 'Strada Memorandumului', 'row_key' => 'k1']);
    $db->exec('CREATE TABLE tabel_vechi_test (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, nota VARCHAR(190) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $randNou($db, 'tabel_vechi_test', ['nota' => 'Text ' . $m]);
    return $ids;
};
/** Sursa „live” pe un server: codul de dinainte de migrări, seed-ul, datele demo, tabelele avertismentului din bs.sql, rândurile cu marcaj. */
$facSursa = static function (string $sock) use ($conectare, $construieste, $bazaSursa, $radacina, $umple, $bs, $tabele): array {
    $root = $conectare($sock);
    $root->exec('DROP DATABASE IF EXISTS ' . $bazaSursa);
    $root->exec('CREATE DATABASE ' . $bazaSursa . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $rezultate = [];
    foreach ([['tools/dev/licenta.php', 'toate'], ['tools/dev/migrari/pe-vechi.php'], ['scripts/seed.php'], ['tools/dev/date-demo.php']] as $script) {
        $rezultate[$script[0]] = $construieste($sock, $bazaSursa, $script);
        if ($rezultate[$script[0]][0] !== 0) {
            return ['eroare' => $script[0] . ': ' . mb_substr($rezultate[$script[0]][1], -1500)];
        }
    }
    $db = $conectare($sock, $bazaSursa);
    $existente = $tabele($db);
    preg_match_all('/^CREATE TABLE `([^`]+)`.*?;$/ms', (string) file_get_contents($radacina . '/tools/dev/migrari/vechi/bs.sql'), $creari, PREG_SET_ORDER);
    $adaugate = [];
    foreach ($creari as [$sql, $tabel]) {
        if (!in_array($tabel, $existente, true)) {
            $db->exec($sql);
            $adaugate[] = $tabel;
        }
    }
    $schema = [];
    foreach ($db->query('SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()')->fetchAll() as $r) {
        $schema[(string) $r['t']][] = (string) $r['c'];
    }
    $diferente = [];
    foreach ($bs + $schema as $tabel => $_) {
        $a = array_keys($bs[$tabel] ?? []);
        $b = $schema[$tabel] ?? [];
        sort($a);
        sort($b);
        if ($a !== $b) {
            $diferente[] = $tabel . ' (' . implode(',', array_diff($a, $b)) . ' / ' . implode(',', array_diff($b, $a)) . ')';
        }
    }
    try {
        $ids = $umple($db);
    } catch (Throwable $e) {
        return ['eroare' => $e->getMessage()];
    }
    return ['adaugate' => $adaugate, 'diferente' => $diferente, 'ids' => $ids, 'iesire' => $rezultate['tools/dev/migrari/pe-vechi.php'][1]];
};

/** O cerere spre copie (serverul ei, pe CMS_DEV_PORT+15), fără redirecționări urmate. */
$cerere = static function (string $metoda, string $cale, array $optiuni = []) use ($port): array {
    $c = curl_init('http://127.0.0.1:' . $port . $cale);
    $antete = [];
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metoda,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOPROXY => '*',
        CURLOPT_PROXY => '',
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$antete): int {
            if (str_contains($linie, ':')) {
                [$nume, $valoare] = explode(':', $linie, 2);
                $antete[strtolower(trim($nume))] = trim($valoare);
            }
            return strlen($linie);
        },
    ]);
    if (isset($optiuni['basic'])) {
        curl_setopt($c, CURLOPT_USERPWD, $optiuni['basic']);
    }
    if (isset($optiuni['jar'])) {
        curl_setopt($c, CURLOPT_COOKIEFILE, $optiuni['jar']);
        curl_setopt($c, CURLOPT_COOKIEJAR, $optiuni['jar']);
    }
    if (isset($optiuni['post'])) {
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($optiuni['post']));
    }
    $corp = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $cod, 'antete' => $antete, 'corp' => $corp];
};
$pornesteServerul = static function () use (&$pidServer, $port, $copie, $mediu, $acasa, $cerere, $dosarTest, $sockMaria, $opresteServerul): bool {
    $opresteServerul();
    $env = '';
    foreach ($mediu($acasa) + ['PHP_CLI_SERVER_WORKERS' => '2'] as $nume => $valoare) {
        $env .= ' ' . $nume . '=' . escapeshellarg($valoare);
    }
    $pidServer = (int) trim((string) shell_exec('cd ' . escapeshellarg($copie) . ' && env -i' . $env . ' nohup ' . escapeshellarg(PHP_BINARY)
        . ' -d ' . escapeshellarg('pdo_mysql.default_socket=' . $sockMaria) . ' -d display_errors=0 -d log_errors=1 -d '
        . escapeshellarg('error_log=' . $dosarTest . '/php-erori-server.log') . ' -d opcache.enable=0 -S 127.0.0.1:' . $port
        . ' -t public tools/dev/router.php >> ' . escapeshellarg($dosarTest . '/server.log') . ' 2>&1 & echo $!'));
    for ($i = 0; $i < 50; $i++) {
        if ($cerere('GET', '/health')['cod'] !== 0) {
            return true;
        }
        usleep(200000);
    }
    return false;
};
$stare = static fn (): array => $ruleaza('tools/verificare/stare.php', ['--adresa=http://127.0.0.1:' . $port]);
$sterge = static fn (): array => $ruleaza('tools/verificare/sterge.php', ['--da']);
$reguliCopie = $copie . '/tools/verificare/reguli/bioscem.php';
$libCopie = $copie . '/tools/verificare/lib/incarcare.php';
/** Regulile din copia codului, schimbate (o mutație): fișierul scris din tabloul schimbat; null = cele din git. */
$scrieReguli = static function (?callable $schimba) use ($reguliCopie, $radacina): void {
    if ($schimba === null) {
        copy($radacina . '/tools/verificare/reguli/bioscem.php', $reguliCopie);
        return;
    }
    $date = require $radacina . '/tools/verificare/reguli/bioscem.php';
    $schimba($date);
    file_put_contents($reguliCopie, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($date, true) . ";\n");
};
$marcaj = static function (PDO $db): ?array {
    $exista = (int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '_verificare'")->fetchColumn() > 0;
    return $exista ? ($db->query('SELECT * FROM _verificare LIMIT 1')->fetch() ?: []) : null;
};
$fisierSare = $copie . '/storage/verificare/tmp/' . Incarcare::FISIER_SARE;
$fisierIncarcat = $copie . '/storage/verificare/incarcat-la';
/** Copia ștearsă de tot: baza goală, fără incarcat-la, codul vechi și sarea. */
$stearsa = static function (PDO $tinta) use ($tabele, $fisierIncarcat, $codVechi, $fisierSare): bool {
    clearstatcache();
    return $tabele($tinta) === [] && !file_exists($fisierIncarcat) && !file_exists($codVechi) && !file_exists($fisierSare);
};

try {
    $curata();
    if (!$merge($sockMaria)) {
        throw new RuntimeException('MariaDB nu merge pe ' . $sockMaria);
    }
    $root = $conectare($sockMaria);
    if ($cerere('GET', '/health')['cod'] !== 0) {
        throw new RuntimeException('pe portul ' . $port . ' răspunde deja un server; oprește-l întâi');
    }
    // B8 compară copia demonstrativă cu datele demo ale bazei de dezvoltare (tools/dev/instaleaza.sh).
    $bazaDev = (string) getenv('DB_DATABASE');
    if (preg_match('/^cms_[a-z0-9_]+$/', $bazaDev) !== 1 || !in_array('products', $tabele($conectare($sockMaria, $bazaDev)), true)) {
        throw new RuntimeException('baza de dezvoltare (DB_DATABASE) lipsește sau e goală: rulează întâi tools/dev/instaleaza.sh');
    }

    // ── Site-ul de verificare: copia codului din git (cu modificările necomise), ca un deploy din hPanel ──
    $scrie('');
    $scrie('B. Găzduirea simulată: site-ul de verificare și un Bioscem „live” inventat');
    mkdir($copie, 0770, true);
    $lista = (string) shell_exec('git -C ' . escapeshellarg($radacina) . ' ls-files -z --cached --others --exclude-standard');
    $copiate = 0;
    foreach (array_filter(explode("\0", $lista), static fn (string $f): bool => $f !== '') as $fisier) {
        if (!is_file($radacina . '/' . $fisier) || str_starts_with($fisier, '.env')
            || (str_starts_with($fisier, 'storage/') && !in_array(basename($fisier), ['.gitkeep', '.gitignore'], true))) {
            continue;
        }
        @mkdir(dirname($copie . '/' . $fisier), 0770, true);
        copy($radacina . '/' . $fisier, $copie . '/' . $fisier);
        @chmod($copie . '/' . $fisier, fileperms($radacina . '/' . $fisier) & 0777);
        $copiate++;
    }
    $verifica('copia codului (' . $copiate . ' fișiere, fără storage/, .env și privat/)', $copiate > 100 && is_file($copie . '/tools/verificare/incarca.php')
        && !is_dir($copie . '/site-uri/vertij/privat'));

    // ── Baza „live”: ca a unui Bioscem încă neadoptat ──
    $construita = $facSursa($sockMaria);
    $verifica('baza „live” (MariaDB): codul de dinainte de migrări (pe-vechi.php, garda: fara_registru), seed-ul, datele demo și tabelele '
        . 'avertismentului din bs.sql; aceleași tabele și coloane ca schema Bioscem 4254ca4 (bs.sql)', !isset($construita['eroare'])
        && str_contains((string) ($construita['iesire'] ?? ''), 'garda: fara_registru') && $construita['adaugate'] === ['order_product_warnings', 'product_warnings']
        && $construita['diferente'] === [], json_encode($construita, JSON_UNESCAPED_UNICODE) ?: '');
    if (isset($construita['eroare'])) {
        throw new RuntimeException('baza „live” nu s-a putut face');
    }
    $idsMarcaj = $construita['ids'];
    $sursa = $conectare($sockMaria, $bazaSursa);
    $verifica('rândurile cu marcaj: în fiecare tabel cu reguli de anonimizare și în fiecare tabel „doar structura” (' . count($idsMarcaj) . ' tabele)',
        count(array_filter((array) $reguli->date['tabele'], static fn (array $r): bool => in_array($r['mod'], ['anonimizeaza', 'doar_structura'], true)))
        === count($idsMarcaj), implode(', ', array_keys($idsMarcaj)));
    $root->exec('CREATE DATABASE ' . $bazaTinta . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    foreach ([[$utilizatorLive, $parolaLive, 'ALL', $bazaSursa], [$utilizatorCitire, $parolaCitire, 'SELECT', $bazaSursa], [$utilizatorTinta, $parolaTinta, 'ALL', $bazaTinta]]
        as [$u, $p, $drept, $b]) {
        $root->exec("CREATE USER '" . $u . "'@'localhost' IDENTIFIED BY " . $root->quote($p));
        $root->exec('GRANT ' . $drept . ' ON ' . $b . ".* TO '" . $u . "'@'localhost'");
    }
    $tinta = $conectare($sockMaria, $bazaTinta);

    // ── Dosarul site-ului live: .env (cu datele de test BT, decizia D3), cod, .git, și ce nu e cod ──
    $envLive = ['APP_URL' => 'https://bioscem.ro', 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'DB_HOST' => 'localhost', 'DB_PORT' => '3306',
        'DB_DATABASE' => $bazaSursa, 'DB_USERNAME' => $utilizatorLive, 'DB_PASSWORD' => $parolaLive, 'BT_IPAY_MODE' => 'live',
        'BT_IPAY_LIVE_USER' => 'btlu' . $m, 'BT_IPAY_LIVE_PASS' => 'btlp' . $m, 'BT_IPAY_LIVE_CALLBACK_KEY' => 'btlk' . $m, 'ADMIN_DEFAULT_PASSWORD' => 'adm' . $m,
        'SMTP_PASSWORD' => 'smtp' . $m] + $btTest;
    $scrieEnvLive = static function (array $chei) use ($live): void {
        $text = '';
        foreach ($chei as $cheie => $valoare) {
            $text .= $cheie . '=' . $valoare . "\n";
        }
        file_put_contents($live . '/.env', $text);
        chmod($live . '/.env', 0600);
    };
    foreach (['public/assets', 'src/Support', 'views', 'config', 'database', 'storage/sessions', 'storage/logs', 'public/uploads/products', '.git/refs/heads/claude'] as $d) {
        @mkdir($live . '/' . $d, 0770, true);
    }
    $scrieEnvLive($envLive);
    $codLive = ['public/index.php' => "<?php // Bioscem\n", 'src/Support/Settings.php' => "<?php // setări\n", 'views/acasa.php' => "<h1>Bioscem</h1>\n",
        'public/assets/app.css' => "body{margin:0}\n", 'public/assets/app.js' => "console.log(1);\n", 'public/assets/logo.png' => "\x89PNG logo",
        'config/app.php' => "<?php return [];\n", 'database/schema.sql' => "CREATE TABLE x (id INT);\n", 'composer.json' => "{}\n", '.htaccess' => "Deny from all\n",
        'public/.htaccess' => "RewriteEngine On\n", 'public/robots.txt' => "User-agent: *\n", 'README.md' => "# Bioscem\n", '.env.example' => "APP_URL=\n"];
    $necod = ['.env.backup' => 'DB_PASSWORD=' . $parolaLive, 'storage/sessions/sess_' . $m => 'cos|' . $emailClient, 'storage/logs/app.log' => $emailClient,
        'public/uploads/products/poza-' . $m . '.jpg' => 'jpg ' . $m, 'public/sitemap.xml' => '<urlset>' . $m . '</urlset>', 'error_log' => 'PHP ' . $emailClient,
        'backup-' . $m . '.sql' => 'INSERT INTO users VALUES (' . $m . ')', 'comenzi.csv' => $emailClient, 'arhiva.zip' => 'PK' . $m];
    foreach ($codLive + $necod as $fisier => $continut) {
        file_put_contents($live . '/' . $fisier, $continut);
    }
    @mkdir($dosarTest . '/afara', 0770, true);
    file_put_contents($dosarTest . '/afara/secret.txt', $m);
    symlink($dosarTest . '/afara', $live . '/legatura-afara');
    file_put_contents($live . '/.git/HEAD', "ref: refs/heads/claude/ramura-live\n");
    file_put_contents($live . '/.git/refs/heads/claude/ramura-live', str_repeat('b', 40) . "\n");
    $instantaneuLive = $instantaneu($live);
    $sumeSursa = $sume($sursa);

    // ── configureaza.php pe baza goală a copiei ──
    $r = $ruleaza('tools/verificare/configureaza.php', [], implode("\n", [$bazaTinta, $utilizatorTinta, $parolaTinta, $emailProprietar, '', $parolaPoarta, $parolaPoarta]) . "\n");
    $verifica('configureaza.php pe baza goală a copiei: cod 0', $r['cod'] === 0 && is_file($copie . '/.env'), $descrie($r));
    $verifica('serverul copiei pornește (port ' . $port . ')', $pornesteServerul());
    $s = $stare();
    $verifica('stare.php înainte de încărcare: „GATA” (baza goală, copia lipsește)', $s['cod'] === 0 && str_starts_with($ultimul($s['iesire']), 'GATA'), $descrie($s));

    // =================================================================
    $scrie('');
    $scrie('B1. Refuzurile (cod 2, nimic atins)');
    $refuzuri = [
        'fără --din și fără --demo' => [[], [], 'alege ce încarci'],
        '--din și --demo împreună' => [['--din=bioscem', '--demo'], [], 'alege ce încarci'],
        'un site fără reguli (biovitality)' => [['--din=biovitality'], [], 'nu există încă reguli de anonimizare pentru „biovitality”'],
        'un nume de site nepermis' => [['--din=../x'], [], 'nume de site nepermis'],
        'un argument necunoscut' => [['--din=bioscem', '--sterge-tot'], [], 'argument necunoscut'],
        '--pachet fără --demo' => [['--din=bioscem', '--pachet=vertij'], [], '--pachet e doar pentru --demo'],
        '--reia fără o încărcare oprită' => [['--din=bioscem', '--reia', '--da'], $raspunsuriAdmin, 'nu e nicio încărcare oprită'],
        '--reia și --reincarca' => [['--din=bioscem', '--reia', '--reincarca'], [], 'nu merg împreună'],
        '--buget nu e un număr' => [['--din=bioscem', '--buget=mult'], [], '--buget'],
        '--licenta necunoscută' => [['--din=bioscem', '--licenta=nu-exista'], [], '--licenta: nu există pachetul'],
        '--demo --reia' => [['--demo', '--reia'], [], 'copia demonstrativă nu se continuă'],
        '--demo cu --fara-cod-vechi' => [['--demo', '--fara-cod-vechi'], [], 'sunt doar pentru --din'],
        '--demo cu un pachet inexistent' => [['--demo', '--pachet=nu-exista'], [], '--pachet: nu există'],
        'alt răspuns decât „da”' => [['--din=bioscem'], ['nu'], 'n-ai scris „da”'],
        'parola contului de admin sub 12 caractere' => [['--din=bioscem', '--da'], ['', 'scurta', 'scurta'], 'sub 12 caractere'],
        'cele două parole diferite' => [['--din=bioscem', '--da'], ['', $parolaAdmin, $parolaAdmin . 'x'], 'nu sunt la fel'],
        'parola contului = parola porții' => [['--din=bioscem', '--da'], ['', $parolaPoarta, $parolaPoarta], 'alta decât parola porții'],
        'o adresă de admin invalidă' => [['--din=bioscem', '--da'], ['nu-e-email', $parolaAdmin, $parolaAdmin], 'nu e validă'],
        'intrarea se încheie înainte de răspunsuri' => [['--din=bioscem', '--da'], [''], 'intrarea s-a încheiat'],
    ];
    foreach ($refuzuri as $ce => [$argumente, $raspunsuri, $text]) {
        $r = $ruleaza('tools/verificare/incarca.php', $argumente, $raspunsuri === [] ? '' : implode("\n", $raspunsuri) . "\n");
        $verifica($ce . ' → refuzat', $r['cod'] === 2 && str_contains($r['erori'], 'REFUZAT') && str_contains($r['tot'], $text) && $tabele($tinta) === [] && $curat($r),
            $descrie($r));
    }
    $r2 = $ruleaza($radacina . '/tools/verificare/incarca.php', ['--din=bioscem', '--da'], implode("\n", $raspunsuriAdmin) . "\n");
    $verifica('din codul de dezvoltare (nu e ~/domains/<domeniu>/public_html) → refuzat', $r2['cod'] === 2 && str_contains($r2['tot'], 'site-ului de verificare')
        && $tabele($tinta) === [], $descrie($r2));
    $r = $incarca(['--din=bioscem', '--da'], $raspunsuriAdmin, ['home' => $dosarTest]);
    $verifica('HOME din alt cont → refuzat', $r['cod'] === 2 && str_contains($r['tot'], 'HOME') && $tabele($tinta) === [], $descrie($r));
    rename($live, $live . '-ascuns');
    $r = $incarca(['--din=bioscem', '--da'], $raspunsuriAdmin);
    rename($live . '-ascuns', $live);
    $verifica('site-ul live nu se găsește → refuzat, cu indicația --dosar', $r['cod'] === 2 && str_contains($r['tot'], '--dosar') && $tabele($tinta) === [], $descrie($r));
    $envCopie = (string) file_get_contents($copie . '/.env');
    file_put_contents($copie . '/.env', str_replace('DB_DATABASE=' . $bazaTinta, 'DB_DATABASE=' . $bazaSursa, $envCopie));
    $r = $incarca(['--din=bioscem', '--da'], $raspunsuriAdmin);
    file_put_contents($copie . '/.env', $envCopie);
    $verifica('baza copiei = baza site-ului live → refuzat înainte de orice conexiune la ea, fără numele bazei', $r['cod'] === 2
        && str_contains($r['tot'], 'baza copiei e chiar baza site-ului sursă') && $curat($r), $descrie($r));
    // Aceeași bază scrisă și în .env-ul altui site din cont (nu al sursei): refuzată tot fără conexiune.
    @mkdir($acasa . '/domains/alt-site.exemplu.test/public_html', 0770, true);
    file_put_contents($acasa . '/domains/alt-site.exemplu.test/public_html/.env', "APP_URL=https://alt-site.exemplu.test\nDB_HOST=localhost\nDB_DATABASE=" . $bazaTinta . "\n");
    $r = $incarca(['--din=bioscem', '--da'], $raspunsuriAdmin);
    exec('rm -rf ' . escapeshellarg($acasa . '/domains/alt-site.exemplu.test'));
    $verifica('baza copiei scrisă în .env-ul altui site din cont → refuzat, cu domeniul lui, fără numele bazei', $r['cod'] === 2
        && str_contains($r['tot'], 'altui site din cont (alt-site.exemplu.test)') && $curat($r), $descrie($r));
    $tinta->exec('CREATE TABLE settings (`key` VARCHAR(120) PRIMARY KEY, `value` TEXT)');
    $r = $incarca(['--din=bioscem', '--da'], $raspunsuriAdmin);
    $verifica('baza copiei are tabele fără _verificare (pare baza unui site) → refuzat, neatinsă', $r['cod'] === 2 && str_contains($r['tot'], 'pare baza unui site')
        && $tabele($tinta) === ['settings'], $descrie($r));
    $tinta->exec('DROP TABLE settings');
    $verifica('după refuzuri: copia goală, fără incarcat-la, cod-vechi sau sare; sursa și dosarul live neschimbate', $stearsa($tinta)
        && $sume($sursa) === $sumeSursa && $instantaneu($live) === $instantaneuLive);

    // =================================================================
    $scrie('');
    $scrie('B2. Scanerul și obiectele pe care o copie nu le ia (cod 1, înaintea oricărui rând; copia ștearsă singură)');
    $oprite = [
        'o coloană nouă cu nume de date personale (orders.telefon_livrare)' => ['ALTER TABLE orders ADD COLUMN telefon_livrare VARCHAR(30) NULL',
            "UPDATE orders SET telefon_livrare = '" . $telefon . "', updated_at = updated_at", 'ALTER TABLE orders DROP COLUMN telefon_livrare',
            'NU    orders.telefon_livrare (nume de date personale): coloană fără regulă'],
        'o coloană nouă oarecare (products.culoare_noua)' => ['ALTER TABLE products ADD COLUMN culoare_noua VARCHAR(30) NULL', "UPDATE products SET culoare_noua = '" . $m . "', updated_at = updated_at",
            'ALTER TABLE products DROP COLUMN culoare_noua', 'NU    products.culoare_noua: coloană fără regulă'],
        'o vedere' => ['CREATE VIEW vedere_test AS SELECT id FROM orders', null, 'DROP VIEW vedere_test', '1 vederi'],
        'un tabel _verificare (sursa e o copie)' => ['CREATE TABLE _verificare (id INT)', null, 'DROP TABLE _verificare', 'e o copie de verificare'],
    ];
    foreach ($oprite as $ce => [$fa, $umpleSql, $desfa, $text]) {
        $sursa->exec($fa);
        if ($umpleSql !== null) {
            $sursa->exec($umpleSql);
        }
        $r = $incarca(['--din=bioscem', '--da'], $raspunsuriAdmin);
        $sursa->exec($desfa);
        $verifica($ce . ' → cod 1, doar numele pe ecran, copia ștearsă singură', $r['cod'] === 1 && str_contains($r['tot'], $text)
            && str_contains($r['tot'], 'copia a fost ștearsă singură') && $stearsa($tinta) && $curat($r), $descrie($r));
    }
    $verifica('sursa neschimbată de încercări (CHECKSUM TABLE)', $sume($sursa) === $sumeSursa, implode(', ', array_keys(array_diff_assoc($sume($sursa), $sumeSursa))));
    $verifica('dosarul site-ului live neschimbat (fișiere, date, drepturi, nicio sesiune nouă)', $instantaneu($live) === $instantaneuLive,
        implode(', ', array_keys(array_diff_assoc($instantaneu($live), $instantaneuLive) + array_diff_assoc($instantaneuLive, $instantaneu($live)))));

    // =================================================================
    $scrie('');
    $scrie('B3. Încărcarea');
    // Referința: aceeași bază a sursei, trecută prin aceleași migrări (fără anonimizare): coloanele păstrate ale copiei
    // trebuie să fie exact ale ei, tabelele copiate cum sunt la fel (CHECKSUM TABLE).
    $root->exec('CREATE DATABASE ' . $bazaRef . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    foreach ($tabele($sursa) as $tabel) {
        $root->exec('CREATE TABLE ' . $bazaRef . '.`' . $tabel . '` LIKE ' . $bazaSursa . '.`' . $tabel . '`');
        $root->exec('INSERT INTO ' . $bazaRef . '.`' . $tabel . '` SELECT * FROM ' . $bazaSursa . '.`' . $tabel . '`');
    }
    [$codRef, $iesireRef] = $construieste($sockMaria, $bazaRef, ['scripts/migreaza.php', '--aplica', '--site=bioscem'], ['SITE_DATE_DOSAR' => $radacina . '/site-uri/bioscem']);
    $verifica('referința: copia simplă a sursei, trecută prin migrări (scripts/migreaza.php --aplica --site=bioscem)', $codRef === 0, mb_substr($iesireRef, -1500));
    $ref = $conectare($sockMaria, $bazaRef);

    $deLa = microtime(true);
    $r = $incarca(['--din=bioscem'], array_merge(['da'], $raspunsuriAdmin));
    $durata = round(microtime(true) - $deLa, 1);
    $verifica('încărcarea: cod 0, „GATA”, fiecare aserțiune A1–A9 DA, niciun NU (' . $durata . ' s)', $r['cod'] === 0
        && $ultimul($r['iesire']) === 'GATA: copia e anonimizată, toate verificările au trecut' && !str_contains($r['tot'], '  NU    ')
        && count(array_filter(range(1, 9), static fn (int $n): bool => preg_match('/^  DA    A' . $n . ' /m', $r['iesire']) === 1)) === 9, $descrie($r));
    $verifica('pe ecran: nicio valoare din rânduri, nicio parolă, niciun nume de bază sau de utilizator, nicio adresă întreagă, nicio cale a contului',
        $curat($r), $faraSecrete($r['tot']));
    $verifica('pe ecran: _verificare scris primul, sursa doar în citire, scanerul, structura, grupurile, setările, contul, codul vechi, migrările, licența',
        str_contains($r['iesire'], 'DA    _verificare scris primul (anonimizat = 0)') && str_contains($r['iesire'], 'sesiune doar de citire (READ ONLY, verificată)')
        && str_contains($r['iesire'], 'tabele fără reguli, copiate doar ca structură, fără niciun rând: tabel_vechi_test')
        && str_contains($r['iesire'], 'DA    grupul comenzi: ') && str_contains($r['iesire'], 'DA    setările: ') && str_contains($r['iesire'], 'DA    contul tău de administrator general')
        && str_contains($r['iesire'], 'DA    codul vechi în ~/domains/' . $gazda . '/cod-vechi/bioscem/') && str_contains($r['iesire'], 'garda spune la_zi')
        && str_contains($r['iesire'], 'DA    licența copiei: pachetul bioscem') && str_contains($r['iesire'], 'datele de test ale băncii (decizia D3): '), $r['iesire']);
    $fisierRaportCopie = $copie . '/' . (preg_match('#^Textul de mai sus e și în (storage/verificare/sonde/incarcare-bioscem-[0-9-]+\.txt)$#m', $r['iesire'], $p) === 1 ? $p[1] : '-');
    $textRaport = (string) @file_get_contents($fisierRaportCopie);
    $verifica('textul salvat în storage/verificare/sonde/ (0600, calea pe ecran), la fel ca ecranul, cu „GATA” la final, fără secrete', is_file($fisierRaportCopie)
        && (fileperms($fisierRaportCopie) & 0777) === 0600 && $faraSecrete($textRaport) === '' && str_ends_with($textRaport, "GATA: copia e anonimizată, toate verificările au trecut\n")
        && str_contains($textRaport, 'DA    A9 '), $fisierRaportCopie);

    $cuMarcaj = $cuValori($tinta, $cautateInCopie);
    $verifica('pe copie: niciun marcaj, telefon, jeton sau adresă a clientului inventat, în nicio coloană a niciunui tabel', $cuMarcaj === [], implode(', ', $cuMarcaj));
    // Fiecare valoare personală a sursei (coloanele transformate fără condiție), căutată întreagă în tot textul copiei; nu se
    // caută cele care apar și în conținutul copiat cum e (un nume de produs egal cu un nume de recenzie nu e o scurgere).
    $transformate = [];
    foreach ((array) $reguli->date['tabele'] as $tabel => $regula) {
        foreach ((array) ($regula['transforma'] ?? []) as $coloana => $t) {
            if (!isset($t['daca']) && !isset($t['daca_nenul']) && !in_array($t[0], ['cod_cupon', 'nume_cupon', 'cupon_comanda', 'subiect'], true)) {
                $transformate[$tabel . '.' . $coloana] = true;
            }
        }
    }
    $pastrate = $textBaza($sursa, null);
    $valoriPersonale = [];
    foreach (array_keys($transformate) as $tc) {
        [$tabel, $coloana] = explode('.', $tc);
        if (!in_array($tabel, $tabele($sursa), true)) {
            continue;
        }
        foreach ($sursa->query('SELECT DISTINCT `' . $coloana . '` FROM `' . $tabel . '` WHERE `' . $coloana . '` IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $v = trim((string) $v);
            if (mb_strlen($v) >= 6 && !preg_match('/^[0-9 .:-]+$/', $v)) {
                $valoriPersonale[$v] = $tc;
            }
        }
    }
    $textSursaPastrat = '';
    foreach ($tabele($sursa) as $tabel) {
        $regula = $reguli->tabel($tabel);
        if ($regula === null || $regula['mod'] === 'doar_structura') {
            continue;
        }
        foreach ((array) ($regula['pastreaza'] ?? []) as $coloana) {
            if (in_array($coloana, array_column($sursa->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "
                . $sursa->quote($tabel))->fetchAll(), 'COLUMN_NAME'), true)) {
                foreach ($sursa->query('SELECT `' . $coloana . '` FROM `' . $tabel . '` WHERE `' . $coloana . '` IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $v) {
                    $textSursaPastrat .= "\x1f" . $v;
                }
            }
        }
    }
    unset($pastrate);
    $textCopie = $textBaza($tinta);
    $scurse = [];
    foreach ($valoriPersonale as $v => $tc) {
        if (!str_contains($textSursaPastrat, $v) && str_contains($textCopie, $v)) {
            $scurse[] = $tc;
        }
    }
    $verifica('pe copie: niciuna din cele ' . count($valoriPersonale) . ' valori personale ale sursei (adrese, telefoane, nume, texte libere, jetoane, '
        . 'parole, IP-uri…), căutate întregi în tot textul copiei', $scurse === [] && count($valoriPersonale) > 200, implode(', ', array_unique($scurse)));

    $mk = $marcaj($tinta);
    $verifica('_verificare încheiat (sursa bioscem, anonimizat = 1, pasul gata, incarcat_la, versiunea regulilor, commit-ul codului sursei); incarcat-la '
        . 'scris (0600); sarea ștearsă', is_array($mk) && ($mk['sursa'] ?? '') === 'bioscem' && (int) ($mk['anonimizat'] ?? 0) === 1 && ($mk['pas'] ?? '') === 'gata'
        && ($mk['incarcat_la'] ?? null) !== null && ($mk['versiune_reguli'] ?? '') === '1' && ($mk['cod_sursa_commit'] ?? '') === str_repeat('b', 40)
        && is_file($fisierIncarcat) && (fileperms($fisierIncarcat) & 0777) === 0600 && !file_exists($fisierSare), json_encode($mk) ?: '');
    // Numerele de pe ecran = rândurile sursei, tabel cu tabel.
    preg_match_all('/^  ([a-z0-9_]+)\s+(\d+)$/m', substr($r['iesire'], (int) strpos($r['iesire'], 'Rândurile copiate')), $gasite, PREG_SET_ORDER);
    $nepotrivite = [];
    foreach ($gasite as [, $tabel, $n]) {
        $inSursa = (int) $sursa->query('SELECT COUNT(*) FROM `' . $tabel . '`')->fetchColumn();
        if ($inSursa !== (int) $n) {
            $nepotrivite[] = $tabel . ' ' . $n . '≠' . $inSursa;
        }
    }
    $doarStructura = array_merge(array_keys(array_filter((array) $reguli->date['tabele'], static fn (array $x): bool => $x['mod'] === 'doar_structura')), ['tabel_vechi_test']);
    // Pe admin_activity_log, după licență, doar jurnalul pașilor de activare ai pluginurilor (fără admin, adresă sau IP).
    $cuRanduri = array_values(array_filter($doarStructura, static fn (string $t): bool => !in_array($t, $tabele($tinta), true)
        || (int) $tinta->query('SELECT COUNT(*) FROM `' . $t . '`' . ($t === 'admin_activity_log'
            ? " WHERE action <> 'activare_plugin' OR admin_id IS NOT NULL OR admin_email IS NOT NULL OR ip IS NOT NULL" : ''))->fetchColumn() > 0));
    $verifica('rândurile pe ecran = ale sursei (' . count($gasite) . ' tabele copiate); tabelele „doar structura” și cel necunoscut sunt pe copie, fără niciun rând '
        . '(afară de jurnalul pașilor de activare, scris după verificări)',
        count($gasite) === 53 && $nepotrivite === [] && $cuRanduri === [], implode(', ', array_merge($nepotrivite, $cuRanduri)));

    // Copia față de referință (aceeași sursă, aceleași migrări): tabelele copiate cum sunt au același CHECKSUM TABLE; în
    // celelalte, fiecare coloană care nu e transformată are exact valorile referinței.
    $cheiePrimara = static function (PDO $db, string $tabel): array {
        $s = $db->prepare("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'PRIMARY' ORDER BY SEQ_IN_INDEX");
        $s->execute([$tabel]);
        return $s->fetchAll(PDO::FETCH_COLUMN);
    };
    $amprentaColoane = static function (PDO $db, string $tabel, array $coloane, string $unde, array $ordine): string {
        $sql = 'SELECT `' . implode('`, `', $coloane) . '` FROM `' . $tabel . '`' . $unde . ' ORDER BY `' . implode('`, `', $ordine !== [] ? $ordine : $coloane) . '`';
        return md5((string) json_encode($db->query($sql)->fetchAll(PDO::FETCH_NUM)));
    };
    $sumeCopie = $sume($tinta);
    $sumeRef = $sume($ref);
    $copiateLaFel = [];
    $diferite = [];
    $comparate = 0;
    foreach (array_keys($sumeCopie) as $tabel) {
        $regula = $reguli->tabel($tabel);
        if ($regula === null || in_array($regula['mod'], ['doar_structura', 'setari'], true) || !isset($sumeRef[$tabel])) {
            continue;
        }
        // Registrul migrărilor: aceleași migrări, cu același sha și rezultat (duratele și momentele diferă).
        if (str_starts_with($tabel, '_platforma_')) {
            $coloane = $tabel === '_platforma_migrari' ? ['id', 'ord', 'proprietar', 'tip', 'sha256', 'blocanta', 'rezultat'] : ['cheie'];
            if ($amprentaColoane($tinta, $tabel, $coloane, '', []) !== $amprentaColoane($ref, $tabel, $coloane, '', [])) {
                $diferite[] = $tabel;
            }
            continue;
        }
        if ($regula['mod'] === 'copiaza') {
            if ($sumeCopie[$tabel] === $sumeRef[$tabel]) {
                $copiateLaFel[] = $tabel;
            } else {
                $diferite[] = $tabel . ' (CHECKSUM)';
            }
            continue;
        }
        $coloane = array_values(array_diff(array_keys($coloaneTabel($tinta, $tabel)), array_keys((array) $regula['transforma'])));
        $unde = $tabel === 'admins' ? ' WHERE email <> ' . $tinta->quote($emailProprietar) : '';
        $comparate += count($coloane);
        if ($amprentaColoane($tinta, $tabel, $coloane, $unde, $cheiePrimara($tinta, $tabel)) !== $amprentaColoane($ref, $tabel, $coloane, '', $cheiePrimara($ref, $tabel))) {
            $diferite[] = $tabel;
        }
    }
    $verifica('tabelele copiate cum sunt = referința (CHECKSUM TABLE egal: ' . count($copiateLaFel) . ' tabele, și seo_pages cu categoria și marca, '
        . 'product_warnings, order_product_warnings, order_items, fan_streets__sync); în tabelele anonimizate, cele ' . $comparate . ' coloane păstrate '
        . '(sumele, stările, orașele, datele, id-urile) = referința', $diferite === [] && in_array('seo_pages', $copiateLaFel, true)
        && in_array('product_warnings', $copiateLaFel, true) && in_array('order_product_warnings', $copiateLaFel, true) && in_array('order_items', $copiateLaFel, true)
        && in_array('fan_streets__sync', $copiateLaFel, true) && $comparate > 150, implode(', ', $diferite));

    // Pseudonimele: aceeași adresă a sursei → același pseudonim în toate tabelele; adrese diferite → pseudonime diferite.
    $val = static function (PDO $db, string $tabel, string $coloana, int $id): mixed {
        $s = $db->prepare('SELECT `' . $coloana . '` FROM `' . $tabel . '` WHERE id = ?');
        $s->execute([$id]);
        return $s->fetchColumn();
    };
    $e = (string) $val($tinta, 'users', 'email', $idsMarcaj['users']);
    $laFel = [];
    foreach ([['orders', 'billing_email'], ['orders', 'edit_unlocked_by'], ['product_reviews', 'user_email'], ['newsletter_subscribers', 'email'],
        ['newsletter_campaign_sends', 'email'], ['newsletter_campaign_clicks', 'email'], ['contact_form_messages', 'email']] as [$tabel, $coloana]) {
        $laFel[$tabel . '.' . $coloana] = $val($tinta, $tabel, $coloana, $idsMarcaj[$tabel]) === $e;
    }
    $laFel['email_logs.recipient'] = str_starts_with((string) $val($tinta, 'email_logs', 'recipient', $idsMarcaj['email_logs']), $e . ', ');
    $numeClient = (string) $val($tinta, 'users', 'last_name', $idsMarcaj['users']);
    $laFel['nume'] = preg_match('/^[0-9A-F]{6}$/', $numeClient) === 1 && $val($tinta, 'orders', 'billing_last_name', $idsMarcaj['orders']) === $numeClient
        && $val($tinta, 'orders', 'shipping_last_name', $idsMarcaj['orders']) === $numeClient && $val($tinta, 'users', 'first_name', $idsMarcaj['users']) === 'Client';
    $perechi = $sursa->query('SELECT o.id AS o, u.id AS u FROM orders o JOIN users u ON u.id = o.user_id WHERE LOWER(TRIM(o.billing_email)) = LOWER(TRIM(u.email))')->fetchAll();
    $perechiRele = 0;
    foreach ($perechi as $p) {
        if ($val($tinta, 'orders', 'billing_email', (int) $p['o']) !== $val($tinta, 'users', 'email', (int) $p['u'])) {
            $perechiRele++;
        }
    }
    $distincte = static fn (PDO $db): int => (int) $db->query('SELECT COUNT(DISTINCT LOWER(TRIM(email))) FROM users')->fetchColumn();
    $verifica('pseudonimele: adresa clientului inventat are același pseudonim u…@verificare.invalid în users, orders, recenzii, newsletter, campanii, '
        . 'mesaje și emailurile trimise, același nume (H) pe facturare și livrare; ' . count($perechi) . ' comenzi ale clienților cu cont: aceeași adresă; '
        . 'adrese diferite rămân diferite', preg_match('/^u[0-9a-f]{12}@verificare\.invalid$/', $e) === 1 && !in_array(false, $laFel, true)
        && count($perechi) > 3 && $perechiRele === 0 && $distincte($tinta) === $distincte($sursa), json_encode($laFel) . ' rele: ' . $perechiRele);
    $adresa = $tinta->query('SELECT * FROM user_addresses WHERE id = ' . (int) $idsMarcaj['user_addresses'])->fetch();
    $comanda = $tinta->query('SELECT * FROM orders WHERE id = ' . (int) $idsMarcaj['orders'])->fetch();
    $awbLista = json_decode((string) $comanda['fan_awb_list'], true);
    $verifica('rândurile cu marcaj: telefonul 07…, adresa „Strada Verificare N”, codul poștal 000000, numele „Client …”, nota „[text anonimizat]”, '
        . 'AWB-urile DEMO<id> (și în lista coletelor, cu adresa de urmărire), firma și CUI-ul inventate, orașul și totalul păstrate',
        preg_match('/^07\d{8}$/', (string) $val($tinta, 'users', 'phone', $idsMarcaj['users'])) === 1 && str_starts_with((string) $adresa['street'], 'Strada Verificare ')
        && $adresa['postcode'] === '000000' && $adresa['street_no'] === '1' && $adresa['address_line2'] === null && str_starts_with((string) $adresa['full_name'], 'Client ')
        && str_starts_with((string) $comanda['notes'], '[text anonimizat, ') && $comanda['fan_awb'] === 'DEMO' . $comanda['id']
        && ($awbLista[0]['awb'] ?? '') === 'DEMO' . $comanda['id'] && ($awbLista[1]['awb'] ?? '') === 'DEMO' . $comanda['id'] . '-2'
        && str_contains((string) $comanda['fan_tracking_url'], 'DEMO' . $comanda['id']) && preg_match('/^Firma [0-9A-F]{6} SRL$/', (string) $comanda['billing_company_name']) === 1
        && preg_match('/^RO\d{8}$/', (string) $comanda['billing_company_tax_id']) === 1 && $comanda['billing_city'] === 'Cluj-Napoca' && $comanda['total'] === '123.45',
        json_encode([$adresa, $comanda], JSON_UNESCAPED_UNICODE) ?: '');
    $cupon = $tinta->query('SELECT * FROM coupons WHERE id = ' . (int) $idsMarcaj['coupons'])->fetch();
    $jetoaneSursa = $sursa->query('SELECT token FROM order_payment_links')->fetchAll(PDO::FETCH_COLUMN);
    $jetoaneCopie = $tinta->query('SELECT token FROM order_payment_links')->fetchAll(PDO::FETCH_COLUMN);
    $verifica('codul unic de cupon: VERIF-<id> în cupon și în comanda care îl avea (scris altfel), numele „Cupon unic VERIF-<id>”; cuponul obișnuit VARA10 '
        . 'neschimbat; jetoanele linkurilor de plată: noi, 64 hex, niciunul al sursei', $cupon['code'] === 'VERIF-' . $cupon['id'] && $cupon['name'] === 'Cupon unic VERIF-' . $cupon['id']
        && $comanda['coupon_code'] === 'VERIF-' . $cupon['id'] && (int) $tinta->query("SELECT COUNT(*) FROM coupons WHERE code = 'VARA10'")->fetchColumn() === 1
        && count($jetoaneCopie) === count($jetoaneSursa) && array_intersect($jetoaneCopie, $jetoaneSursa) === []
        && count(array_filter($jetoaneCopie, static fn ($j): bool => preg_match('/^[0-9a-f]{64}$/', (string) $j) === 1)) === count($jetoaneCopie),
        json_encode([$cupon, $comanda['coupon_code']], JSON_UNESCAPED_UNICODE) ?: '');
    $setariCopie = $tinta->query('SELECT `key`, `value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    $setariRef = $ref->query('SELECT `key`, `value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    $neatinse = 0;
    $atinseRau = [];
    foreach ($setariRef as $cheie => $v) {
        if (Incarcare::setareNeutralizata((string) $cheie, (string) ($setariCopie[$cheie] ?? ''), (array) $reguli->date['setari'], $emailProprietar,
            'no-reply@verificare.invalid') === null) {
            $neatinse++;
            if (($setariCopie[$cheie] ?? null) !== $v) {
                $atinseRau[] = $cheie;
            }
        }
    }
    $verifica('setările: secretele golite (și o cheie nouă după tipar), comutatoarele 0, destinatarii = adresa ta (și după tipar), expeditorul fără căsuță = '
        . 'no-reply@verificare.invalid, „VERIFICARE Bioscem”, cheia de mentenanță nouă; celelalte ' . $neatinse . ' setări = referința (și înghețarea Bioscem)',
        $setariCopie['stripe_secret_key'] === '' && $setariCopie['o_cheie_noua_api_key'] === '' && $setariCopie['smtp_host'] === '' && $setariCopie['erp_enabled'] === '0'
        && $setariCopie['stripe_enabled'] === '0' && $setariCopie['contact_form_recipients'] === $emailProprietar
        && $setariCopie['email_template_comanda_noua_admin_recipients'] === $emailProprietar && $setariCopie['bt_ipay_notify_emails'] === $emailProprietar
        && $setariCopie['order_email_from_address'] === 'no-reply@verificare.invalid' && $setariCopie['order_email_from_name'] === 'VERIFICARE Bioscem'
        && preg_match('/^[0-9a-f]{24}$/', (string) $setariCopie['maintenance_key']) === 1 && $setariCopie['maintenance_allowed_ips'] === ''
        && $atinseRau === [] && $neatinse > 100 && array_key_exists('erp_trimite_din', $setariCopie), implode(', ', $atinseRau));
    $migrari = $tinta->query("SELECT COUNT(*) FROM _platforma_migrari WHERE id LIKE 'site/bioscem/%'")->fetchColumn();
    $licenta = json_decode((string) @file_get_contents($copie . '/storage/licenta/licenta.json'), true);
    $catalog = require $radacina . '/config/plugins.php';
    $pluginuriLicenta = array_keys((array) ($licenta['pluginuri'] ?? []));
    sort($pluginuriLicenta);
    $asteptate = $catalog['licente_dev']['bioscem'];
    sort($asteptate);
    $verifica('migrările pe copie: pasul site-ului Bioscem (înghețarea setărilor) în registru; licența copiei = pachetul bioscem (licente_dev)',
        (int) $migrari === 1 && $pluginuriLicenta === $asteptate, (string) $migrari . ' / ' . implode(',', $pluginuriLicenta) . ' / ' . substr((string) json_encode($licenta), 0, 300));
    $admini = $tinta->query('SELECT * FROM admins ORDER BY id')->fetchAll();
    $proprietar = array_values(array_filter($admini, static fn (array $a): bool => $a['email'] === $emailProprietar));
    $altii = array_values(array_filter($admini, static fn (array $a): bool => $a['email'] !== $emailProprietar));
    $verifica('contul tău: administrator general cu parola dată; adminii site-ului: admin<id>@verificare.invalid, parole aleatoare (nici cea în clar a sursei)',
        count($proprietar) === 1 && password_verify($parolaAdmin, (string) $proprietar[0]['password_hash']) && $proprietar[0]['role'] === 'administrator_general'
        && (int) $mk['admin_proprietar_id'] === (int) $proprietar[0]['id'] && count($altii) === 5
        && array_filter($altii, static fn (array $a): bool => preg_match('/^admin' . $a['id'] . '@verificare\.invalid$/', (string) $a['email']) !== 1
            || password_verify('parola-' . $m, (string) $a['password_hash'])) === [], json_encode(array_column($admini, 'email')) ?: '');
    $envCopie = \App\Support\Verificare::citesteEnv((string) file_get_contents($copie . '/.env'));
    $verifica('.env-ul copiei: sursa bioscem, pozele lipsă spre https://bioscem.ro, pachetul bioscem; decizia D3: cheile de test BT ale sursei; cheile live nu '
        . '(goale), modul test', ($envCopie['VERIFICARE_SURSA'] ?? '') === 'bioscem' && ($envCopie['VERIFICARE_UPLOADS_DIN'] ?? '') === 'https://bioscem.ro'
        && ($envCopie['SITE_SLUG'] ?? '') === 'bioscem' && ($envCopie['SITE_DATE_DOSAR'] ?? '') === realpath($copie) . '/site-uri/bioscem'
        && array_intersect_key($envCopie, $btTest) === $btTest
        && ($envCopie['BT_IPAY_LIVE_PASS'] ?? 'x') === '' && ($envCopie['BT_IPAY_LIVE_USER'] ?? 'x') === '' && ($envCopie['BT_IPAY_MODE'] ?? '') === 'test'
        && !str_contains((string) file_get_contents($copie . '/.env'), 'btl'), json_encode(array_intersect_key($envCopie, $btTest + ['BT_IPAY_MODE' => 1])) ?: '');
    // Codul vechi: doar codul, în afara public_html.
    $vechi = $codVechi . '/bioscem';
    $fisiereVechi = array_map(static fn (string $c): string => substr($c, strlen($vechi) + 1), array_keys(array_filter($instantaneu($vechi), static fn (string $x): bool => str_starts_with($x, 'f:'))));
    sort($fisiereVechi);
    $asteptateVechi = array_merge(array_keys($codLive), ['.env']);
    sort($asteptateVechi);
    $envVechi = (string) @file_get_contents($vechi . '/.env');
    $verifica('codul vechi în ~/domains/<verificare>/cod-vechi/bioscem/ (lângă public_html, nu în el): exact fișierele de cod, la fel; fără .env.backup, .git, '
        . 'sesiuni, jurnale, poze, sitemap, copia de bază, exportul, arhiva, legătura; dosarul 0700, fișierele 0600; .env-ul lui doar cu baza copiei',
        $fisiereVechi === $asteptateVechi && md5_file($vechi . '/public/index.php') === md5($codLive['public/index.php']) && is_dir($vechi . '/public/uploads')
        && !file_exists($vechi . '/legatura-afara') && !is_dir($vechi . '/.git') && (fileperms($vechi) & 0777) === 0700 && (fileperms($vechi . '/README.md') & 0777) === 0600
        && str_contains($envVechi, 'DB_DATABASE=' . $bazaTinta . "\n") && str_contains($envVechi, 'BT_IPAY_MODE=test') && !str_contains($envVechi, $m)
        && !str_contains($envVechi, $parolaLive) && !str_starts_with($vechi, $copie), implode(', ', array_diff($fisiereVechi, $asteptateVechi)) . ' | '
        . implode(', ', array_diff($asteptateVechi, $fisiereVechi)));
    $verifica('sursa neschimbată (CHECKSUM TABLE pe fiecare tabel) și dosarul live neatins (nicio sesiune, niciun fișier nou)', $sume($sursa) === $sumeSursa
        && $instantaneu($live) === $instantaneuLive);
    $s = $stare();
    $verifica('stare.php după încărcare: „GATA”, cu „DA copia e încheiată și anonimizată” și „DA incarcat-la doar cu o copie încheiată”', $s['cod'] === 0
        && str_starts_with($ultimul($s['iesire']), 'GATA') && str_contains($s['iesire'], 'DA    copia e încheiată și anonimizată (_verificare: anonimizat = 1, sursa bioscem)')
        && str_contains($s['iesire'], 'DA    incarcat-la doar cu o copie încheiată') && $curat($s), $descrie($s));
    $basic = 'verificare:' . $parolaPoarta;
    $pagina = $cerere('GET', '/', ['basic' => $basic]);
    $faraParola = $cerere('GET', '/');
    $logare = static function (string $email, string $parola) use ($cerere, $basic, $dosarTest): bool {
        $jar = $dosarTest . '/jar-' . bin2hex(random_bytes(4)) . '.txt';
        $r = $cerere('POST', '/admin/login', ['basic' => $basic, 'jar' => $jar, 'post' => ['email' => $email, 'password' => $parola]]);
        // Pagina de start a rolului (un rol parțial nu începe pe /admin).
        $r = $cerere('GET', (string) ($r['antete']['location'] ?? '/admin'), ['basic' => $basic, 'jar' => $jar]);
        return $r['cod'] === 200 && str_contains($r['corp'], '/admin/logout');
    };
    $verifica('copia servită: cu parola porții pagina de start 200, fără ea 401; contul tău se loghează, un admin al site-ului (cu parola lui în clar din sursă) nu',
        $pagina['cod'] === 200 && $faraParola['cod'] === 401 && $logare($emailProprietar, $parolaAdmin) && !$logare('admin' . $idsMarcaj['admins'] . '@verificare.invalid', 'parola-' . $m),
        $pagina['cod'] . ' / ' . $faraParola['cod']);

    // =================================================================
    $scrie('');
    $scrie('B4. Sursa doar în citire');
    $sesiune = SursaCopie::deschide(['DB_HOST' => 'localhost', 'DB_PORT' => '3306', 'DB_DATABASE' => $bazaSursa, 'DB_USERNAME' => $utilizatorLive,
        'DB_PASSWORD' => $parolaLive]);
    $scrieri = ["INSERT INTO settings (`key`, `value`) VALUES ('test_scriere', 'x')", "UPDATE settings SET `value` = 'x' WHERE `key` = 'store_name'",
        'DELETE FROM coupons'];
    $refuzate = array_map(static fn (string $sql): ?string => $sesiune->incearcaScriere($sql), $scrieri);
    $inInstantaneu = $sesiune->instantaneu(static fn (): array => array_map(static fn (string $sql): ?string => $sesiune->incearcaScriere($sql), $scrieri));
    $inAfara = static function (callable $f): bool {
        try {
            $f();
            return false;
        } catch (InstructiuneRefuzata) {
            return true;
        }
    };
    $verifica('sesiunea sursei: serverul refuză INSERT, UPDATE, DELETE (1792), și în sesiune, și în instantaneul unui grup; citirea în afara instantaneului e refuzată de unealtă',
        count(array_filter(array_merge($refuzate, $inInstantaneu), static fn (?string $c): bool => $c !== null && str_contains($c, '1792'))) === 6
        && $inAfara(static fn () => $sesiune->numara('orders')) && $inAfara(static fn () => $sesiune->parcurge('orders', ['id'], static function (): void {
        })) && $sume($sursa) === $sumeSursa, json_encode([$refuzate, $inInstantaneu]) ?: '');
    unset($sesiune);
    $timpEnv = filemtime($live . '/.env');
    $scrieEnvLive(['DB_USERNAME' => $utilizatorCitire, 'DB_PASSWORD' => $parolaCitire] + $envLive);
    $r = $incarca(['--din=bioscem', '--reincarca', '--da'], $raspunsuriAdmin);
    $scrieEnvLive($envLive);
    touch($live . '/.env', (int) $timpEnv);
    $verifica('încărcarea cu un utilizator al sursei care are doar SELECT: „GATA” (unealta doar citește)', $r['cod'] === 0 && $ultimul($r['iesire']) === 'GATA: copia e anonimizată, toate verificările au trecut'
        && $curat($r), $descrie($r));

    // =================================================================
    $scrie('');
    $scrie('B5. --reincarca');
    $e1 = (string) $val($tinta, 'users', 'email', $idsMarcaj['users']);
    $r = $incarca(['--din=bioscem', '--da'], $raspunsuriAdmin);
    $verifica('fără --reincarca peste o copie terminată → refuzat, copia neatinsă', $r['cod'] === 2 && str_contains($r['tot'], 'pe copie e deja o încărcare (sursa bioscem, terminată)')
        && $val($tinta, 'users', 'email', $idsMarcaj['users']) === $e1, $descrie($r));
    $r = $incarca(['--din=bioscem', '--reincarca', '--da'], $raspunsuriAdmin);
    $e2 = (string) $val($tinta, 'users', 'email', $idsMarcaj['users']);
    $verifica('--reincarca: „GATA”, cu altă sare (alt pseudonim pentru aceeași adresă), copia de dinainte ștearsă întâi', $r['cod'] === 0
        && str_contains($r['iesire'], 'copia de dinainte: ștearsă') && preg_match('/^u[0-9a-f]{12}@verificare\.invalid$/', $e2) === 1 && $e2 !== $e1 && $curat($r), $descrie($r));

    // =================================================================
    $scrie('');
    $scrie('B6. --reia: o încărcare oprită continuă de la grupul la care a rămas');
    $r = $incarca(['--din=bioscem', '--reincarca', '--da', '--buget=0'], $raspunsuriAdmin);
    $mk = $marcaj($tinta);
    $verifica('--buget=0: cod 3 după primul grup (setari), „ÎNTRERUPT … --reia” ultimul rând; _verificare cu anonimizat = 0, fără incarcat-la; sarea '
        . 'păstrată (0600) pentru aceleași pseudonime', $r['cod'] === 3 && str_starts_with($ultimul($r['iesire']), 'ÎNTRERUPT: bugetul de timp (--buget=0) s-a terminat după grupul setari')
        && str_ends_with($ultimul($r['iesire']), '--din=bioscem --reia') && (int) ($mk['anonimizat'] ?? 1) === 0 && ($mk['pas'] ?? '') === 'date'
        && json_decode((string) ($mk['grupuri_gata'] ?? ''), true) === ['setari'] && !file_exists($fisierIncarcat) && is_file($fisierSare)
        && (fileperms($fisierSare) & 0777) === 0600 && $curat($r), $descrie($r));
    $s = $stare();
    $pagina = $cerere('GET', '/', ['basic' => $basic]);
    $verifica('o încărcare oprită: stare.php „NU E GATA” (copia nu e încheiată), site-ul arată „copia lipsește” (503) cu parola porții', $s['cod'] === 1
        && str_contains($s['iesire'], 'NU    copia e încheiată și anonimizată (_verificare: anonimizat = 0, sursa bioscem): încărcare neterminată (pasul date)')
        && str_starts_with($ultimul($s['iesire']), 'NU E GATA') && $pagina['cod'] === 503, 'pagina: ' . $pagina['cod'] . '; stare.php cod ' . $s['cod'] . ': '
        . implode(' | ', array_filter(explode("\n", $s['iesire']), static fn (string $l): bool => str_starts_with($l, '  NU ') || str_starts_with($l, 'NU E'))));
    // Resturile unui grup neterminat (o rulare oprită în mijlocul lui): grupul se ia de la capăt, fără ele.
    $randNou($tinta, 'pages', ['title' => 'Rest al unei rulări oprite', 'slug' => 'rest-' . bin2hex(random_bytes(3))]);
    $coduri = [];
    $paginiDupaPrima = -1;
    for ($i = 0; $i < 15; $i++) {
        $r = $incarca(['--din=bioscem', '--reia', '--da', '--buget=0'], $raspunsuriAdmin);
        $coduri[] = $r['cod'];
        if ($i === 0) {
            $paginiDupaPrima = (int) $tinta->query('SELECT COUNT(*) FROM pages')->fetchColumn();
        }
        if ($r['cod'] !== 3 || !$curat($r)) {
            break;
        }
    }
    $verifica('--reia --buget=0: câte un grup pe rulare (platforma, fără tabele în sursă, sărit), până la „GATA” (' . count($coduri) . ' rulări: '
        . implode(' ', $coduri) . ')', $coduri === [3, 3, 3, 3, 3, 3, 3, 3, 0] && $ultimul($r['iesire']) === 'GATA: copia e anonimizată, toate verificările au trecut'
        && $curat($r), $descrie($r));
    $verifica('resturile grupului neterminat: șterse înainte de copiere (pages = sursa)', $paginiDupaPrima === (int) $sursa->query('SELECT COUNT(*) FROM pages')->fetchColumn(),
        (string) $paginiDupaPrima);
    $e3 = (string) $val($tinta, 'users', 'email', $idsMarcaj['users']);
    $verifica('pseudonimele, aceleași între rulări (aceeași sare): clientul inventat are același pseudonim în comenzi (grupul comenzi), în newsletter și în '
        . 'mesaje (copiate în alte rulări); numerele = sursa', preg_match('/^u[0-9a-f]{12}@verificare\.invalid$/', $e3) === 1
        && $val($tinta, 'newsletter_subscribers', 'email', $idsMarcaj['newsletter_subscribers']) === $e3
        && $val($tinta, 'contact_form_messages', 'email', $idsMarcaj['contact_form_messages']) === $e3 && $val($tinta, 'orders', 'billing_email', $idsMarcaj['orders']) === $e3
        && (int) $tinta->query('SELECT COUNT(*) FROM orders')->fetchColumn() === (int) $sursa->query('SELECT COUNT(*) FROM orders')->fetchColumn()
        && !file_exists($fisierSare) && is_file($fisierIncarcat) && $cuValori($tinta, $cautateInCopie) === []);
    $r = $incarca(['--din=bioscem', '--reia', '--da'], $raspunsuriAdmin);
    $verifica('--reia pe o copie terminată → refuzat', $r['cod'] === 2 && str_contains($r['tot'], 'e deja terminată'), $descrie($r));
    // O încărcare oprită: alte reguli (versiunea), sarea lipsă → --reia refuzat; sterge.php o șterge.
    $r = $incarca(['--din=bioscem', '--reincarca', '--da', '--buget=0'], $raspunsuriAdmin);
    $scrieReguli(static function (array &$d): void {
        $d['versiune'] = 2;
    });
    $r2 = $incarca(['--din=bioscem', '--reia', '--da'], $raspunsuriAdmin);
    $scrieReguli(null);
    rename($fisierSare, $fisierSare . '.pus-deoparte');
    $r3 = $incarca(['--din=bioscem', '--reia', '--da'], $raspunsuriAdmin);
    rename($fisierSare . '.pus-deoparte', $fisierSare);
    $verifica('--reia refuzat cu alte reguli decât la început și fără sarea încărcării (--reincarca), copia oprită neatinsă', $r['cod'] === 3 && $r2['cod'] === 2
        && str_contains($r2['tot'], 'regulile de anonimizare s-au schimbat') && $r3['cod'] === 2 && str_contains($r3['tot'], 'sarea încărcării oprite lipsește')
        && (int) ($marcaj($tinta)['anonimizat'] ?? 1) === 0, $descrie($r2) . ' / ' . $descrie($r3));
    $s = $sterge();
    $r = $incarca(['--din=bioscem', '--reia', '--da'], $raspunsuriAdmin);
    $verifica('sterge.php pe o încărcare oprită: „GATA”, baza goală, sarea ștearsă; apoi --reia → refuzat (nimic de continuat)', $s['cod'] === 0
        && str_starts_with($ultimul($s['iesire']), 'GATA: copia a fost ștearsă') && $stearsa($tinta) && $r['cod'] === 2 && str_contains($r['tot'], 'nu e nicio încărcare oprită')
        && $curat($s), $descrie($s) . ' / ' . $descrie($r));

    // =================================================================
    $scrie('');
    $scrie('B7. Mutațiile: fiecare aserțiune pică pe o regulă sau un pas stricat, iar copia se șterge singură');
    $libOriginal = (string) file_get_contents($libCopie);
    $coloaneCos = array_keys($coloaneTabel($sursa, 'cart_abandonments'));
    $mutatii = [
        'A1' => ['regula: orders.notes păstrată (are adresa clientului)', static function (array &$d): void {
            unset($d['tabele']['orders']['transforma']['notes']);
            $d['tabele']['orders']['pastreaza'][] = 'notes';
        }],
        'A2' => ['regula: cart_abandonments copiată cum e', static function (array &$d) use ($coloaneCos): void {
            $motive = [];
            foreach ($coloaneCos as $c) {
                if (preg_match(\Unelte\Verificare\CitireLive::TIPAR_PERSONAL, $c) === 1) {
                    $motive[$c] = 'mutație de test';
                }
            }
            $d['tabele']['cart_abandonments'] = ['mod' => 'copiaza', 'pastreaza' => $coloaneCos, 'motive' => $motive, 'emailuri_publice' => 'mutație de test'];
            $d['grupuri']['mesaje'][] = 'cart_abandonments';
        }],
        'A3' => ['regula: user_addresses.phone ca text', static function (array &$d): void {
            $d['tabele']['user_addresses']['transforma']['phone'] = ['text'];
        }],
        'A4' => ['regula: users.google_id păstrat', static function (array &$d): void {
            unset($d['tabele']['users']['transforma']['google_id']);
            $d['tabele']['users']['pastreaza'][] = 'google_id';
            $d['tabele']['users']['motive'] = ['google_id' => 'mutație de test'];
        }],
        'A5' => ['regula: admins.password_hash păstrat (parola în clar a sursei)', static function (array &$d): void {
            unset($d['tabele']['admins']['transforma']['password_hash']);
            $d['tabele']['admins']['pastreaza'][] = 'password_hash';
            $d['tabele']['admins']['motive'] = ['password_hash' => 'mutație de test'];
        }],
        'A6' => ['pasul: comutatoarele neutralizate puse pe 1', ["            return '0';", "            return '1';"]],
        'A7' => ['pasul: ultimul lot al fiecărui tabel nescris', ["                    \$copie->insereaza(\$tabel, \$coloane, \$lot, \$info);\n                    \$lot = [];\n                    \$peCopie",
            "                    \$lot = [];\n                    \$peCopie"]],
        'A8' => ['regula: order_payment_links.token păstrat', static function (array &$d): void {
            unset($d['tabele']['order_payment_links']['transforma']['token']);
            $d['tabele']['order_payment_links']['pastreaza'][] = 'token';
            $d['tabele']['order_payment_links']['motive'] = ['token' => 'mutație de test'];
        }],
        'A9' => ['regula: coupons.code păstrat (codurile unice)', static function (array &$d): void {
            unset($d['tabele']['coupons']['transforma']['code']);
            $d['tabele']['coupons']['pastreaza'][] = 'code';
        }],
    ];
    foreach ($mutatii as $asertiune => [$ce, $mutatie]) {
        if (is_callable($mutatie)) {
            $scrieReguli($mutatie);
            $bune = Reguli::din(require $reguliCopie)->probleme() === [];
        } else {
            $bune = substr_count($libOriginal, $mutatie[0]) === 1;
            file_put_contents($libCopie, str_replace($mutatie[0], $mutatie[1], $libOriginal));
        }
        $r = $incarca(['--din=bioscem', '--reincarca', '--da'], $raspunsuriAdmin);
        $scrieReguli(null);
        file_put_contents($libCopie, $libOriginal);
        $verifica($asertiune . ' pică (' . $ce . '): cod 1, „NU    ' . $asertiune . '”, copia ștearsă singură, fără secrete pe ecran', $bune && $r['cod'] === 1
            && preg_match('/^  NU    ' . $asertiune . '[ :]/m', $r['iesire']) === 1 && str_contains($r['iesire'], 'copia a fost ștearsă singură') && $stearsa($tinta)
            && $curat($r), $descrie($r));
    }
    $verifica('după mutații: sursa neschimbată, dosarul live neatins', $sume($sursa) === $sumeSursa && $instantaneu($live) === $instantaneuLive);

    // =================================================================
    $scrie('');
    $scrie('B8. --demo: copia demonstrativă, fără nicio bază sursă');
    rename($live, $live . '-ascuns');
    $raspunsuriDemo = ['', $parolaAdmin, $parolaAdmin, $parolaPersonal, $parolaPersonal];
    $r = $incarca(['--demo', '--da'], ['', $parolaAdmin, $parolaAdmin, $parolaAdmin, $parolaAdmin]);
    $verifica('--demo: parola personalului = parola contului tău → refuzat', $r['cod'] === 2 && str_contains($r['tot'], 'alta decât parola contului tău') && $tabele($tinta) === [],
        $descrie($r));
    $r = $incarca(['--demo', '--da'], $raspunsuriDemo);
    $verifica('--demo: cod 0, „GATA”, „nicio bază sursă nu se deschide”, A1 A3 A5 A6 DA', $r['cod'] === 0 && $ultimul($r['iesire']) === 'GATA: copia e anonimizată, toate verificările au trecut'
        && str_contains($r['iesire'], 'nicio bază sursă nu se deschide') && preg_match_all('/^  DA    A[1356] /m', $r['iesire']) === 4 && $curat($r), $descrie($r));
    $dev = $conectare($sockMaria, $bazaDev);
    $numere = [];
    foreach (['products', 'product_categories', 'orders', 'order_items', 'users', 'user_addresses', 'coupons', 'product_reviews', 'blog_posts',
        'newsletter_subscribers', 'loyalty_points_transactions', 'bt_ipay_transactions', 'pages'] as $tabel) {
        $nDev = (int) $dev->query('SELECT COUNT(*) FROM `' . $tabel . '`')->fetchColumn();
        $nCopie = (int) $tinta->query('SELECT COUNT(*) FROM `' . $tabel . '`')->fetchColumn();
        if ($nDev !== $nCopie || $nCopie === 0) {
            $numere[] = $tabel . ' ' . $nCopie . '≠' . $nDev;
        }
    }
    $catalogDemo = static fn (PDO $db): string => md5((string) json_encode($db->query('SELECT sku, name, price FROM products ORDER BY sku')->fetchAll(PDO::FETCH_NUM)));
    $mk = $marcaj($tinta);
    $verifica('copia demonstrativă: aceleași rânduri inventate ca datele demo ale bazei de dezvoltare (produsele la fel: sku, nume, preț); _verificare: sursa demo, anonimizat = 1',
        $numere === [] && $catalogDemo($tinta) === $catalogDemo($dev) && ($mk['sursa'] ?? '') === 'demo' && (int) ($mk['anonimizat'] ?? 0) === 1, implode(', ', $numere));
    $adminiDemo = $tinta->query('SELECT email, password_hash, role FROM admins ORDER BY id')->fetchAll();
    $personal = array_values(array_filter($adminiDemo, static fn (array $a): bool => $a['email'] !== $emailProprietar));
    $clientiRai = (int) $tinta->query("SELECT COUNT(*) FROM users WHERE email NOT LIKE '%@verificare.invalid'")->fetchColumn();
    $parolePersonal = count(array_filter($tinta->query('SELECT password_hash FROM users')->fetchAll(PDO::FETCH_COLUMN), static fn ($h): bool => !password_verify($parolaPersonal, (string) $h)));
    $verifica('adresele @verificare.invalid (clienți, comenzi, abonați, personal); personalul (3 roluri parțiale) și clienții inventați cu parola personalului; '
        . 'contul tău cu parola ta', $clientiRai === 0 && (int) $tinta->query("SELECT COUNT(*) FROM orders WHERE billing_email NOT LIKE '%@verificare.invalid'")->fetchColumn() === 0
        && (int) $tinta->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE email NOT LIKE '%@verificare.invalid'")->fetchColumn() === 0
        && count($personal) >= 3 && array_filter($personal, static fn (array $a): bool => !str_ends_with($a['email'], '@verificare.invalid')
            || !password_verify($parolaPersonal, (string) $a['password_hash'])) === [] && count(array_unique(array_column($personal, 'role'))) >= 3 && $parolePersonal === 0
        && count(array_filter($adminiDemo, static fn (array $a): bool => $a['email'] === $emailProprietar && password_verify($parolaAdmin, (string) $a['password_hash']))) === 1,
        json_encode(array_column($adminiDemo, 'role')) . ' clienți: ' . $clientiRai . ' parole: ' . $parolePersonal);
    $setariDemo = $tinta->query('SELECT `key`, `value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    $licenta = json_decode((string) @file_get_contents($copie . '/storage/licenta/licenta.json'), true);
    $pluginuriLicenta = array_keys((array) ($licenta['pluginuri'] ?? []));
    sort($pluginuriLicenta);
    $verifica('setările neutralizate ca la o copie a unui site (destinatarii = adresa ta, plățile oprite); licența bioscem; pozele inventate în public/uploads/demo',
        ($setariDemo['contact_form_recipients'] ?? '') === $emailProprietar && ($setariDemo['stripe_enabled'] ?? '') === '0' && $pluginuriLicenta === $asteptate
        && glob($copie . '/public/uploads/demo/*.png') !== [], implode(',', $pluginuriLicenta) . ' / ' . count(glob($copie . '/public/uploads/demo/*.png') ?: []));
    $pagina = $cerere('GET', '/', ['basic' => $basic]);
    $verifica('copia demonstrativă servită (200 cu parola porții), personalul se loghează cu parola personalului', $pagina['cod'] === 200
        && $logare((string) $personal[0]['email'], $parolaPersonal), (string) $pagina['cod']);
    $r = $incarca(['--demo', '--da'], $raspunsuriDemo);
    $verifica('a doua copie demonstrativă fără --reincarca → refuzat', $r['cod'] === 2 && str_contains($r['tot'], 'pe copie e deja o încărcare (sursa demo, terminată)'), $descrie($r));
    $r = $incarca(['--demo', '--reincarca', '--pachet=vertij', '--da'], $raspunsuriDemo);
    $verifica('--demo --pachet=vertij fără valorile private ale înghețării (site-uri/vertij/privat/) → cod 1, codul 15, copia ștearsă singură (și pozele)',
        $r['cod'] === 1 && str_contains($r['tot'], 'codul 15') && $stearsa($tinta) && !file_exists($copie . '/public/uploads/demo') && $curat($r), $descrie($r));
    // Valorile private ale înghețării vertij, inventate (pe server stau lângă pachet, în afara git-ului).
    $fisierVertij = $copie . '/site-uri/vertij/setari-inghetate.json';
    $inghetareVertij = json_decode((string) file_get_contents($fisierVertij), true);
    $privat = ['site' => $inghetareVertij['site'], 'sursa' => $inghetareVertij['sursa'], 'pentru' => hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($fisierVertij))),
        'setari' => []];
    foreach ((array) $inghetareVertij['setari'] as $cheie => $intrare) {
        if (is_array($intrare) && ($intrare['privat'] ?? false) === true) {
            $privat['setari'][$cheie] = 'vertij.' . $m . '@exemplu-client.ro';
        }
    }
    @mkdir($copie . '/site-uri/vertij/privat', 0700, true);
    file_put_contents($copie . '/site-uri/vertij/privat/setari-inghetate.json', (string) json_encode($privat));
    $sorteaza = static function (array $a): array {
        sort($a);
        return $a;
    };
    $r = $incarca(['--demo', '--reincarca', '--pachet=vertij', '--licenta=toate', '--da'], $raspunsuriDemo);
    $envCopie = \App\Support\Verificare::citesteEnv((string) file_get_contents($copie . '/.env'));
    $licenta = json_decode((string) @file_get_contents($copie . '/storage/licenta/licenta.json'), true);
    $verifica('--demo --pachet=vertij --licenta=toate, cu valorile private: „GATA”, licența cu toate pluginurile, .env cu pachetul vertij; valoarea privată nu e '
        . 'nici pe ecran, nici în copie (destinatarii sunt adresa ta)', $r['cod'] === 0 && $ultimul($r['iesire']) === 'GATA: copia e anonimizată, toate verificările au trecut'
        && $curat($r) && ($envCopie['SITE_SLUG'] ?? '') === 'vertij' && ($envCopie['VERIFICARE_SURSA'] ?? '') === 'demo' && $sorteaza(array_keys((array) ($licenta['pluginuri'] ?? []))) === $sorteaza(array_keys($catalog['pluginuri']))
        && $cuValori($tinta, [$m]) === [], $descrie($r));
    exec('rm -rf ' . escapeshellarg($copie . '/site-uri/vertij/privat'));
    $s = $sterge();
    $verifica('sterge.php pe copia demonstrativă: „GATA”, șterge și pozele inventate (public/uploads/demo)', $s['cod'] === 0 && str_contains($s['iesire'], 'poze ale copiei demonstrative')
        && !file_exists($copie . '/public/uploads/demo') && $stearsa($tinta), $descrie($s));
    rename($live . '-ascuns', $live);

    // =================================================================
    $scrie('');
    $scrie('B9. Pe MySQL 8');
    if (!$merge($sockMy8)) {
        exec(escapeshellarg($radacina . '/tools/dev/mysql8.sh') . ' porneste 2>&1', $iesireMy8, $codMy8);
        $mysql8Pornit = $codMy8 === 0 && $merge($sockMy8);
    }
    if (!$merge($sockMy8)) {
        $verifica('MySQL 8 merge (tools/dev/mysql8.sh porneste)', false, implode("\n", $iesireMy8 ?? []));
    } else {
        $rootMy8 = $conectare($sockMy8);
        $construitaMy8 = $facSursa($sockMy8);
        $verifica('baza „live” pe MySQL 8, făcută la fel (aceleași tabele și coloane ca bs.sql)', !isset($construitaMy8['eroare']) && $construitaMy8['diferente'] === [],
            json_encode($construitaMy8, JSON_UNESCAPED_UNICODE) ?: '');
        $rootMy8->exec('DROP DATABASE IF EXISTS ' . $bazaTinta);
        $rootMy8->exec('CREATE DATABASE ' . $bazaTinta . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        foreach ([[$utilizatorLive, $parolaLive, 'ALL', $bazaSursa], [$utilizatorTinta, $parolaTinta, 'ALL', $bazaTinta]] as [$u, $p, $drept, $b]) {
            $rootMy8->exec("DROP USER IF EXISTS '" . $u . "'@'localhost'");
            $rootMy8->exec("CREATE USER '" . $u . "'@'localhost' IDENTIFIED BY " . $rootMy8->quote($p));
            $rootMy8->exec('GRANT ' . $drept . ' ON ' . $b . ".* TO '" . $u . "'@'localhost'");
        }
        $r = $incarca(['--din=bioscem', '--da'], $raspunsuriAdmin, ['sock' => $sockMy8]);
        $tintaMy8 = $conectare($sockMy8, $bazaTinta);
        $verifica('MySQL 8: aceeași încărcare, „GATA”, A1–A9 DA, sursa citită pe MySQL 8 în modul doar de citire', $r['cod'] === 0
            && $ultimul($r['iesire']) === 'GATA: copia e anonimizată, toate verificările au trecut' && str_contains($r['iesire'], 'baza sursei: MySQL 8')
            && preg_match_all('/^  DA    A[1-9] /m', $r['iesire']) === 9 && $curat($r), $descrie($r));
        $cuMarcajMy8 = $cuValori($tintaMy8, $cautateInCopie);
        $verifica('MySQL 8: pe copie niciun marcaj, telefon, jeton sau adresă a clientului inventat', $cuMarcajMy8 === [], implode(', ', $cuMarcajMy8));
        $s = $ruleaza('tools/verificare/sterge.php', ['--da'], '', ['sock' => $sockMy8]);
        $verifica('MySQL 8: sterge.php șterge copia', $s['cod'] === 0 && $tabele($tintaMy8) === [], $descrie($s));
    }

    // =================================================================
    $scrie('');
    $verifica('pe tot parcursul: niciun marcaj, nicio parolă, niciun nume de bază sau de utilizator, nicio cale a contului în ieșirile uneltelor',
        $faraSecrete($iesiri) === '', $faraSecrete($iesiri));
    $rapoarte = '';
    foreach (glob($copie . '/storage/verificare/sonde/*.txt') ?: [] as $fisier) {
        $rapoarte .= (string) file_get_contents($fisier);
    }
    $verifica('textele salvate în storage/verificare/sonde/ (' . count(glob($copie . '/storage/verificare/sonde/*.txt') ?: []) . '): fără secrete', $faraSecrete($rapoarte) === '',
        $faraSecrete($rapoarte));

} catch (Throwable $e) {
    $verifica('testul a rulat până la capăt', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
}

$scrie('');
$scrie('Rezultat: ' . $trecute . ' trecute, ' . $esuate . ' eșuate');
exit($esuate === 0 ? 0 : 1);
