<?php

declare(strict_types=1);

/**
 * Testele migrărilor (docs/MIGRARI.md, „Testele”): runner-ul însuși, pe migrări de jucărie scrise de
 * test (WP-02, WP-03: secțiunile U, D, R, T, S; WP-06: I), și migrările adevărate din database/migrari pe
 * schemele celor cinci coduri, T1–T11 (WP-04: secțiunea M).
 *
 *   tools/dev/php.sh tools/dev/test-migrari.php                # tot (poarta G-mig din porti.sh)
 *   tools/dev/php.sh tools/dev/test-migrari.php --runner       # doar runner-ul (U, D, R, I, T, S; poarta G-runner)
 *   tools/dev/php.sh tools/dev/test-migrari.php --migrari      # doar migrările adevărate (U, M, S; fără D și R)
 *   CMS_DEV_PHP=php8.3 tools/dev/php.sh tools/dev/test-migrari.php   # cu PHP-ul minim
 *   … --doar-mariadb                                           # fără MySQL 8 (parțial, spus în rezultat; decizia A1, 27 oct)
 *   … --fara-nestrict                                          # fără al doilea MariaDB, cel non-strict (parțial, spus în rezultat)
 *
 * U. Fără bază: Server (versiuni, ce știe fiecare, lanțul de clauze), Amprenta (aceleași rânduri
 *    în forma MariaDB și în forma MySQL dau aceeași amprentă, inclusiv tinyint(1) unsigned, un
 *    text implicit care începe cu x' și colația coloanelor; definițiile din migrări (CHARACTER SET
 *    și COLLATE semnalate); corpul unui CREATE TABLE; compararea), mesajele fără valori (și cu ghilimele, \ sau „' for key '” în valoare, pe care
 *    serverele nu le dublează), Registru (ordinea, numerele, proprietarii, migrari.lock, pașii
 *    site-ului).
 * Pe fiecare server (MariaDB din CMS_DEV_SOCK; „MariaDB nestrict”, al doilea MariaDB, în modul Hostinger
 * NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION, din CMS_DEV_SOCK_NESTRICT, pornit cu tools/dev/mariadb-nestrict.sh
 * dacă nu merge (WP-14); MySQL 8 din CMS_DEV_SOCK_MYSQL8, pornit cu tools/dev/mysql8.sh dacă nu merge):
 * D. Detectarea: un tabel cu toate felurile de coloane și chei, citit înapoi, e exact ce înțelege
 *    Amprenta din corpul lui; Schema direct (creare, „există”, altă definiție = avertisment, index
 *    echivalent sub alt nume, cursa cu alt proces acceptată, valori repetate sub un index unic
 *    numărate fără să fie arătate, NOT NULL fără DEFAULT, AFTER, ștergeri doar în „contract”,
 *    schimbarea unui tip refuzată fără voie de blocare); tabelComplet (un CREATE pe o bază nouă; pe un
 *    tabel vechi doar coloanele și indecșii care lipsesc, cheia străină lipsă un avertisment; cursa cu
 *    codul vechi care face tabelul chiar atunci, fără o coloană sau cu toate coloanele dar fără indecși
 *    și fără cheia străină; în simulare) și schimbaImplicit (rândurile neatinse); colația coloanelor
 *    (în amprentă doar cea diferită de a tabelului, JSON nu; CHARACTER SET/COLLATE refuzate în
 *    coloana(), tabel(), tabelComplet(); o coloană existentă cu altă colație = avertisment).
 * R. scripts/migreaza.php, cu fiecare cod de ieșire: bază goală fără migrări (0, „nimic de făcut”),
 *    bază străină (4, nimic creat), migrări de jucărie (planul 10, aplicarea, a doua oară nimic,
 *    asteptat.json, baza veche detectată, --pana-la), oprirea după fiecare operație și reluarea
 *    (amprenta finală aceeași, fiecare rând numărat o dată), oprirea chiar în tranzacția unei
 *    bucăți sau a unei instrucțiuni de date și chiar după commit-ul ei (MIGRARI_TEST_OPRESTE_IN:
 *    pică dacă cursorul sau numărătoarea ar ieși din tranzacția bucății), bugetul de timp (13,
 *    „partiala”, apoi gata), migrări schimbate sau dispărute (12), blocante și contract fără voie
 *    (11, baza neatinsă), parametrii site-ului (15), lacătele (3, inclusiv al sincronizării FAN),
 *    starea (_platforma_stare) neatinsă de un runner refuzat (3, 12) și scrisă cu codul final (0,
 *    14), un tabel ocupat (reîncercări, apoi 1 și reluare), mărimea tabelelor în plan, un tabel
 *    #sql-* rămas, sql_mode în ieșire și în jurnal (al serverului; migrările cu STRICT_TRANS_TABLES adăugat
 *    pe un server non-strict: o migrare care îngustează o coloană cu date mai lungi se oprește cu 1, datele
 *    rămân, iar sesiunea revine la modul serverului, WP-14), verificarea (14), configurarea (2), pașii
 *    site-ului, --json, nicio valoare din rânduri (nici cu ghilimele sau \ în ea, din date(),
 *    peBucati(), 1062 și 1366) și nicio parolă în ieșire. Garda (Garda::stare) cu o migrare nouă
 *    numerotată sub una deja aplicată (numerele rezervate pe pachete de lucru): in_urma până o aplică
 *    runner-ul, apoi la_zi, și tot la_zi după întoarcerea la codul de dinainte (runner-ul acelui cod: 12).
 * I. Pasul de înghețare a setărilor (WP-06; în tools/dev/migrari/test-inghetare-runner.php): pachetul fără --site
 *    sau al altui site (2), fără fișierul privat (15), planul, aplicarea (lipsa, lipsa_sau_gol, o valoare prezentă
 *    neatinsă, TRIM doar pe spații), a doua oară nimic, fișierul aplicat schimbat (12), corectura -2, provizoriu
 *    (15, și cu --permite-provizoriu în afara copiei de verificare; pe copie aplicat), pasi_site ({site, pasi}) în
 *    _platforma_stare până termină pașii din ea (o rulare cu --site care nu-i vede: 2, cheia neatinsă), dosarul
 *    pachetului lipsă sau fără înghețarea din site-uri/<site>/ (2), setare() doar în pașii site-ului.
 * T. Aceeași amprentă pe fiecare server după aceleași migrări; o conexiune MariaDB care
 *    primește id-ul de obiect al uneia MySQL eliberate (Amprenta nu-și amintește serverul vechi).
 * S. Linia de comandă nu mai lasă fișiere de sesiune (bootstrap.php): 3 rulări ale lui
 *    migreaza.php și una a lui diagnostic.php, numărul fișierelor din storage/sessions neschimbat;
 *    ANDAXI_CU_SESIUNE pornește totuși o sesiune.
 * M. Migrările adevărate (database/migrari), testele T1–T11 din migrari.md §4:
 *    T1  instalarea nouă: o bază goală → --aplica = asteptat.json, fără avertismente; plus
 *        genereaza-fixture.php --verifica (G-baza: baseline-ul = schema codului minus excluderile,
 *        grupul C = ce lăsau funcțiile codului pe aceleași rânduri inventate).
 *    T2  baseline-ul pe schema fiecărui cod (tools/dev/migrari/vechi/<cod>.sql, făcută din codul lui:
 *        cms, bs, bv, vt, gr; la zi cu codul, genereaza-fixture.php --verifica-fixture-uri, și cu clonele
 *        în dosare cu alt nume, CMS_DEV_REPO_* spre legături simbolice: sursa e numele repo-ului): planul
 *        are în grupul B exact numerele din migrari.md §2.2 (BS: nimic), aplicarea face exact ce arată
 *        planul, nu șterge și nu schimbă nimic din ce exista, ce rămâne în plus = extra-<cod>.txt,
 *        avertismentele = diferențele de definiție de dinainte; BV și GR fără parametrul procesatorului:
 *        codul 15, baza neatinsă. Plus baza veche (cms.sql + variante.sql: coloane, indecși, tabele și
 *        rânduri vechi, inventate): migrările lasă exact ce lăsau funcțiile codului (în afară de
 *        procesatorul linkurilor, care vine din parametrul site-ului).
 *    T2-live aceleași verificări pe schema site-urilor live (sonda, docs/VERIFICARE.md pasul 5:
 *        tools/dev/migrari/vechi/privat/<cod>-live.sql și extra-<cod>.txt, făcute de din-sonda.php, în
 *        afara git-ului); fără ele, SĂRIT cu motivul. Calea e verificată oricum, pe o sondă simulată
 *        (aceeași formă, din schema bs + variante), trecută prin din-sonda.php.
 *    T3  a doua aplicare nu face nimic (nicio migrare rulată, aceleași rânduri), --plan iese cu 0.
 *    T4  drumul unui site: migrările până la penultima (și până la finalul grupului B), apoi seed.php
 *        și tools/dev/date-demo.php, apoi restul: amprenta = asteptat.json, datele demo neschimbate în
 *        afară de tabelele pe care migrările rămase le declară. Scripturile (aici și în T10) rulează cu
 *        licența „toate” dintr-un dosar al testului (CMS_DEV_LICENTA_DOSAR): licența locală nu contează
 *        și rămâne neatinsă.
 *    T5  oprit (MIGRARI_TEST_OPRESTE_DUPA) după fiecare operație a instalării noi și a bazei vechi, și
 *        după o bucată de date (MIGRARI_TEST_OPRESTE_IN), apoi reluat o singură dată: reluarea iese cu 0,
 *        aceeași amprentă, aceleași date, nimic „in_curs”, fiecare operație numărată o dată.
 *    T6  codul vechi în paralel: funcțiile ensure* ale codului rulează înainte de baseline (după
 *        primele 4 migrări) și în același timp cu --aplica: nicio eroare (nici --aplica, nici o excepție
 *        a vreunei funcții, pe care procesul-copil o prinde în „erori”), baza la final completă.
 *    T7  o migrare aplicată, schimbată sau ștearsă într-o copie: codul 12, baza neatinsă.
 *    T8  o migrare de probă cu MODIFY COLUMN declarată neblocantă: 11 fără nimic aplicat; cu
 *        --permite-blocante se aplică.
 *    T9  aceeași amprentă pe MariaDB și pe MySQL 8 după T1, T2, baza veche și T4.
 *    T10 aplicația pe o bază migrată, la nivelul bazei: o bază făcută „pe vechi” (install.php, seed.php,
 *        date-demo.php) primește baseline-ul fără nicio schimbare de schemă și cu datele schimbate doar
 *        unde declară migrările de date; apoi funcțiile ensure* ale codului comun și ale codului Bioscem,
 *        fără nicio excepție, nu mai schimbă nimic pe ea, iar pe o bază nouă făcută de migrări nu schimbă
 *        schema și scriu doar rândurile declarate (pagina GDPR, categoriile de blog, lista de newsletter
 *        implicită: $randuriHelperiPeBazaNoua din migrari/test-baza.php). Testele aplicației
 *        (fum, pluginuri, SEO, comparația cu Bioscem) pe o bază migrată vin cu instalarea prin migrări (WP-05).
 *    T11 tools/dev/verifica-migrari.php trece pe repo și pică pe fiecare greșeală pusă într-o copie
 *        (număr dublat, migrare netrecută în migrari.lock, AFTER, IF NOT EXISTS, COLLATE, DDL scris de
 *        mână, ștergere în afara unei migrări „contract”, DDL nou în cod, o definiție de coloană nouă
 *        într-o funcție ensure*, una doar cu tipul, una într-o constantă a clasei, una într-un fișier fără
 *        DDL, DDL ieșit din cod, asteptat.json vechi, o coloană a altui proprietar) și trece pe texte
 *        obișnuite care încep cu numele unui tip ('text', 'Date invalide.', 'DATE(o.created_at) >= ?').
 *
 * Bazele (cms_mig_t_*) și dosarul de lucru ($CMS_DEV_DIR/test-migrari) se fac și se șterg aici;
 * MySQL 8 și MariaDB nestrict se opresc la final dacă i-a pornit testul. Doar date inventate. Raportul:
 * $CMS_DEV_DIR/test-migrari-raport.txt; cod de ieșire 0 doar dacă totul a trecut.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';

use App\Support\Migrari\Amprenta;
use App\Support\Migrari\Eroare;
use App\Support\Migrari\Executie;
use App\Support\Migrari\Garda;
use App\Support\Migrari\Registru;
use App\Support\Migrari\Schema;
use App\Support\Migrari\Server;

$argumente = array_slice($argv, 1);
foreach ($argumente as $argument) {
    if (!in_array($argument, ['--runner', '--migrari', '--doar-mariadb', '--fara-nestrict'], true)) {
        fwrite(STDERR, 'argument necunoscut: ' . $argument . " (--runner, --migrari, --doar-mariadb, --fara-nestrict)\n");
        exit(2);
    }
}
$doarMariaDb = in_array('--doar-mariadb', $argumente, true);
$faraNestrict = in_array('--fara-nestrict', $argumente, true);
$cuRunner = !in_array('--migrari', $argumente, true);
$cuMigrari = !in_array('--runner', $argumente, true);
if (!$cuRunner && !$cuMigrari) {
    fwrite(STDERR, "--runner și --migrari nu merg împreună (fără niciunul: tot)\n");
    exit(2);
}

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$lucru = $dosarDev . '/test-migrari';
$fisierRaport = $dosarDev . '/test-migrari-raport.txt';
@mkdir($dosarDev, 0777, true);
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
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . trim(substr($detalii, 0, 1500)) : ''));
    }
};
$aruncat = static function (callable $f): ?Throwable {
    try {
        $f();
    } catch (Throwable $e) {
        return $e;
    }
    return null;
};

$sterge = static function (string $cale) use (&$sterge): void {
    if (is_link($cale) || is_file($cale)) {
        @unlink($cale);
        return;
    }
    if (is_dir($cale)) {
        foreach (array_diff(scandir($cale) ?: [], ['.', '..']) as $intrare) {
            $sterge($cale . '/' . $intrare);
        }
        @rmdir($cale);
    }
};
$sterge($lucru);
mkdir($lucru . '/site-gol', 0777, true);

$scrie('Test migrări ANDAXI-CMS (' . ($cuRunner && $cuMigrari ? 'runner și migrările adevărate' : ($cuRunner ? 'doar runner-ul' : 'doar migrările adevărate'))
    . ') — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

// =====================================================================
// U. Fără bază
// =====================================================================
$scrie('');
$scrie('U. Fără bază');

$maria = Server::dinVersiune('10.11.14-MariaDB-0ubuntu0.24.04.1');
$mariaVeche = Server::dinVersiune('5.5.5-10.3.1-MariaDB-log');
$mysql = Server::dinVersiune('8.0.46-0ubuntu0.24.04.4');
$mysqlVechi = Server::dinVersiune('8.0.11');
$verifica('Server: familia și versiunea din SELECT VERSION() (și prefixul 5.5.5- al clienților vechi)',
    $maria->familie() === 'mariadb' && $maria->versiune() === '10.11.14' && $mariaVeche->familie() === 'mariadb'
    && $mariaVeche->versiune() === '10.3.1' && $mysql->familie() === 'mysql' && $mysql->versiune() === '8.0.46'
    && Server::dinVersiune('8.4.3')->versiune() === '8.4.3');
$verifica('Server: minimul MariaDB 10.3 / MySQL 8.0 (10.2 și 5.7 refuzate)',
    $maria->suportat() && $mariaVeche->suportat() && $mysql->suportat() && $mysqlVechi->suportat()
    && !Server::dinVersiune('10.2.44-MariaDB')->suportat() && !Server::dinVersiune('5.7.44-log')->suportat());
$verifica('Server: coloană nouă instant de la MariaDB 10.3.2 / MySQL 8.0.12; ștergere instant de la 10.4 / 8.0.29',
    $maria->coloanaNouaInstant() && !$mariaVeche->coloanaNouaInstant() && $mysql->coloanaNouaInstant() && !$mysqlVechi->coloanaNouaInstant()
    && $maria->stergereColoanaInstant() && !Server::dinVersiune('10.3.39-MariaDB')->stergereColoanaInstant()
    && !Server::dinVersiune('8.0.28')->stergereColoanaInstant() && $mysql->stergereColoanaInstant());
$verifica('Server: lanțul de clauze (INSTANT, INPLACE + LOCK=NONE; fără clauză doar cu voie de blocare; valoarea implicită instant)',
    $maria->lantClauze('coloana', false) === ['ALGORITHM=INSTANT', 'ALGORITHM=INPLACE, LOCK=NONE']
    && $mysqlVechi->lantClauze('coloana', false) === ['ALGORITHM=INPLACE, LOCK=NONE']
    && $maria->lantClauze('index', false) === ['ALGORITHM=INPLACE, LOCK=NONE']
    && $maria->lantClauze('index', true) === ['ALGORITHM=INPLACE, LOCK=NONE', '']
    && $maria->clasaEstimata('modifica') === 'copie' && $mysqlVechi->clasaEstimata('coloana') === 'inplace'
    && $mysqlVechi->lantClauze('implicit', false) === ['ALGORITHM=INSTANT', 'ALGORITHM=INPLACE, LOCK=NONE'] && $maria->clasaEstimata('implicit') === 'instant');

// Aceleași coloane, cum le scrie information_schema pe fiecare server (citite pe MariaDB 10.11 și MySQL 8.0.46).
$randuriCa = static function (string $familie): array {
    $m = $familie === 'mariadb';
    $coloane = [
        ['id', $m ? 'int(10) unsigned' : 'int unsigned', 'NO', null, 'auto_increment'],
        ['a', $m ? 'int(11)' : 'int', 'NO', '0', ''],
        ['b', $m ? 'int(11)' : 'int', 'YES', $m ? 'NULL' : null, ''],
        ['c', 'varchar(20)', 'NO', $m ? "'pending'" : 'pending', ''],
        ['e', 'decimal(10,2)', 'NO', '0.00', ''],
        ['f', 'tinyint(1)', 'NO', '1', ''],
        ['g', 'datetime', 'NO', $m ? 'current_timestamp()' : 'CURRENT_TIMESTAMP', $m ? '' : 'DEFAULT_GENERATED'],
        ['h', 'timestamp', 'YES', $m ? 'current_timestamp()' : 'CURRENT_TIMESTAMP',
            $m ? 'on update current_timestamp()' : 'DEFAULT_GENERATED on update CURRENT_TIMESTAMP'],
        ['j', 'varchar(10)', 'NO', $m ? "'0'" : '0', ''],
        ['k', $m ? 'bigint(20) unsigned' : 'bigint unsigned', 'YES', $m ? 'NULL' : null, ''],
        ['l', "enum('x','Y')", 'NO', $m ? "'x'" : 'x', ''],
        ['n', 'varchar(30)', 'NO', $m ? "'it''s'" : "it's", ''],
        ['q', $m ? 'year(4)' : 'year', 'YES', $m ? 'NULL' : null, ''],
        ['r', $m ? 'longtext' : 'json', 'YES', $m ? 'NULL' : null, ''],
        ['s', $m ? 'smallint(6)' : 'smallint', 'NO', '-1', ''],
        ['t', 'date', 'YES', $m ? "'2020-01-01'" : '2020-01-01', ''],
        ['u', 'varchar(10)', 'NO', $m ? "''" : '', ''],
        // MySQL păstrează lățimea doar la tinyint(1) cu semn; un text care începe cu x' sau 0x e text.
        ['v', $m ? 'tinyint(1) unsigned' : 'tinyint unsigned', 'NO', '0', ''],
        ['w', 'varchar(10)', 'NO', $m ? "'x''y'" : "x'y", ''],
        ['x', 'varchar(10)', 'NO', $m ? "'0x12'" : '0x12', ''],
        ['y', 'bit(3)', 'YES', "b'101'", ''],
        ['z', 'tinyint(1) unsigned zerofill', 'YES', $m ? 'NULL' : null, ''],
    ];
    return [
        'tabele' => [['tabel' => 'p', 'motor' => 'InnoDB', 'colatie' => $m ? 'utf8mb3_general_ci' : 'utf8_general_ci'],
            ['tabel' => 'r', 'motor' => 'InnoDB', 'colatie' => 'utf8mb4_unicode_ci'],
            ['tabel' => '_platforma_migrari', 'motor' => 'InnoDB', 'colatie' => 'x'],
            ['tabel' => 'fan_streets__sync', 'motor' => 'InnoDB', 'colatie' => 'x']],
        'coloane' => array_map(static fn (array $c): array => ['tabel' => 'p', 'coloana' => $c[0], 'tip' => $c[1], 'nul' => $c[2],
            'implicit' => $c[3], 'extra' => $c[4]], $coloane),
        'indecsi' => [
            ['tabel' => 'p', 'index' => 'uniq_c', 'non_unic' => 0, 'pozitie' => 2, 'coloana' => 'e', 'parte' => null, 'tip' => 'BTREE', 'expresie' => null],
            ['tabel' => 'p', 'index' => 'uniq_c', 'non_unic' => 0, 'pozitie' => 1, 'coloana' => 'c', 'parte' => null, 'tip' => 'BTREE', 'expresie' => null],
            ['tabel' => 'p', 'index' => 'idx_n', 'non_unic' => 1, 'pozitie' => 1, 'coloana' => 'n', 'parte' => 5, 'tip' => 'BTREE', 'expresie' => null],
        ],
        'fk' => [['tabel' => 'r', 'nume' => 'fk_r_p', 'coloana' => 'p_id', 'pozitie' => 1, 'tabel_referit' => 'p', 'coloana_referita' => 'id',
            'la_actualizare' => $m ? 'RESTRICT' : 'NO ACTION', 'la_stergere' => 'SET NULL']],
    ];
};
$ampM = Amprenta::normalizeaza($randuriCa('mariadb'), 'mariadb');
$ampY = Amprenta::normalizeaza($randuriCa('mysql'), 'mysql');
$verifica('Amprenta: rândurile MariaDB și MySQL ale acelorași coloane dau aceeași amprentă', Amprenta::json($ampM) === Amprenta::json($ampY),
    Amprenta::json($ampM) . "\n---\n" . Amprenta::json($ampY));
$p = $ampM['tabele']['p'] ?? [];
$verifica('Amprenta: forma comună (int fără lățime, tinyint(1) doar cu semn, year, json = longtext, texte între ghilimele — și x\'y, 0x12 —, numere fără, b\'…\' pe BIT, expresii)',
    ($p['coloane']['id']['tip'] ?? '') === 'int unsigned' && ($p['coloane']['a']['implicit'] ?? '') === '0'
    && $p['coloane']['b']['implicit'] === null && $p['coloane']['c']['implicit'] === "'pending'" && $p['coloane']['e']['implicit'] === '0.00'
    && $p['coloane']['f']['tip'] === 'tinyint(1)' && $p['coloane']['g']['implicit'] === 'current_timestamp'
    && $p['coloane']['h']['extra'] === 'on update current_timestamp' && $p['coloane']['j']['implicit'] === "'0'"
    && $p['coloane']['n']['implicit'] === "'it''s'" && $p['coloane']['q']['tip'] === 'year' && $p['coloane']['r']['tip'] === 'longtext'
    && $p['coloane']['u']['implicit'] === "''" && $p['coloane']['l']['tip'] === "enum('x','Y')"
    && $p['coloane']['v']['tip'] === 'tinyint unsigned' && $p['coloane']['w']['implicit'] === "'x''y'"
    && $p['coloane']['x']['implicit'] === "'0x12'" && $p['coloane']['y']['implicit'] === "b'101'"
    && $p['coloane']['z']['tip'] === 'tinyint unsigned zerofill', json_encode($p));
$verifica('Amprenta: indecși în ordinea coloanelor, cu prefix; NO ACTION = RESTRICT; utf8mb3 = utf8; _platforma_* și *__sync lipsesc',
    ($p['indecsi']['uniq_c'] ?? null) === ['unic' => true, 'coloane' => ['c', 'e']]
    && ($p['indecsi']['idx_n'] ?? null) === ['unic' => false, 'coloane' => ['n(5)']]
    && ($ampM['tabele']['r']['fk']['fk_r_p']['la_actualizare'] ?? '') === 'RESTRICT' && $p['colatie'] === 'utf8_general_ci'
    && array_keys($ampM['tabele']) === ['p', 'r']);

$def = static fn (string $d): array => Amprenta::coloanaDinDefinitie($d);
$cazuri = [
    ["INT UNSIGNED NOT NULL AUTO_INCREMENT", 'int unsigned', false, null, 'auto_increment'],
    ["INT(11) NOT NULL DEFAULT '0'", 'int', false, '0', ''],
    ['int null', 'int', true, null, ''],
    ["VARCHAR(20) NOT NULL DEFAULT 'pending'", 'varchar(20)', false, "'pending'", ''],
    ['VARCHAR(190) DEFAULT NULL', 'varchar(190)', true, null, ''],
    ['DECIMAL(10, 2) NOT NULL DEFAULT 0', 'decimal(10,2)', false, '0.00', ''],
    ["DECIMAL(5,1) DEFAULT '2.5'", 'decimal(5,1)', true, '2.5', ''],
    ['BOOLEAN NOT NULL DEFAULT FALSE', 'tinyint(1)', false, '0', ''],
    ['TINYINT(1) NOT NULL DEFAULT 1', 'tinyint(1)', false, '1', ''],
    ['DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', 'datetime', false, 'current_timestamp', ''],
    ['TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP', 'timestamp', true, 'current_timestamp', 'on update current_timestamp'],
    ["ENUM('x', 'Y') NOT NULL DEFAULT 'x'", "enum('x','Y')", false, "'x'", ''],
    ["VARCHAR(30) NOT NULL DEFAULT 'it''s' COMMENT 'cu, virgulă'", 'varchar(30)', false, "'it''s'", ''],
    ['SMALLINT NOT NULL DEFAULT -1', 'smallint', false, '-1', ''],
    ['JSON NULL', 'longtext', true, null, ''],
    ['DOUBLE PRECISION NOT NULL DEFAULT 0', 'double', false, '0', ''],
    ['BIGINT(20) UNSIGNED', 'bigint unsigned', true, null, ''],
    ['TINYINT(1) UNSIGNED NOT NULL DEFAULT 0', 'tinyint unsigned', false, '0', ''],
    ['TINYINT(1) ZEROFILL NULL', 'tinyint unsigned zerofill', true, null, ''],
    ['INT(5) ZEROFILL UNSIGNED', 'int unsigned zerofill', true, null, ''],
    ['TINYINT(1) SIGNED NOT NULL', 'tinyint(1)', false, null, ''],
    ["BIT(3) DEFAULT b'101'", 'bit(3)', true, "b'101'", ''],
    ["VARCHAR(10) NOT NULL DEFAULT 'x''y'", 'varchar(10)', false, "'x''y'", ''],
];
$gresite = [];
foreach ($cazuri as [$text, $tip, $nul, $implicit, $extra]) {
    $c = $def($text);
    if ($c['tip'] !== $tip || $c['null'] !== $nul || $c['implicit'] !== $implicit || $c['extra'] !== $extra || $c['necunoscute'] !== []) {
        $gresite[] = $text . ' → ' . json_encode($c);
    }
}
$verifica('Amprenta: definițiile scrise în migrări (' . count($cazuri) . ' feluri) la forma amprentei', $gresite === [], implode("\n", $gresite));
$verifica('Amprenta: AFTER/FIRST, PRIMARY KEY/UNIQUE și cuvintele neînțelese sunt semnalate',
    $def('INT NULL AFTER x')['pozitie'] && $def('INT FIRST')['pozitie'] && $def('INT PRIMARY KEY')['primar']
    && $def('INT UNIQUE')['unic'] && $def('VARCHAR(10) NOT NUL')['necunoscute'] !== []);
// O coloană dintr-o migrare ia colația tabelului: CHARACTER SET / CHARSET / COLLATE sunt semnalate (Schema le refuză),
// altfel o colație cerută ar trece pe lângă amprentă și pe lângă compararea cu o coloană existentă.
$cuColatie = $def('TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');
$verifica('Amprenta: CHARACTER SET, CHARSET și COLLATE într-o definiție sunt semnalate (tipul și NULL rămân înțelese)',
    $cuColatie['necunoscute'] === ['CHARACTER SET utf8mb4', 'COLLATE utf8mb4_unicode_ci'] && $cuColatie['tip'] === 'text' && $cuColatie['null'] === true
    && $def('VARCHAR(10) COLLATE utf8mb4_bin NULL')['necunoscute'] === ['COLLATE utf8mb4_bin']
    && $def('VARCHAR(10) CHARSET latin1 NOT NULL')['necunoscute'] === ['CHARSET latin1'] && $def('VARCHAR(10) BINARY')['necunoscute'] !== [],
    json_encode([$cuColatie, $def('VARCHAR(10) COLLATE utf8mb4_bin NULL'), $def('VARCHAR(10) CHARSET latin1 NOT NULL')]));
Amprenta::structuraDinCorp("id INT NOT NULL, email VARCHAR(190) COLLATE utf8mb4_bin NOT NULL, KEY idx_e (email)", $necunoscuteCorp);
Amprenta::structuraDinCorp("id INT NOT NULL, email VARCHAR(190) NOT NULL, KEY idx_e (email)", $necunoscuteCorpBun);
$verifica('Amprenta: corpul unui CREATE TABLE întoarce, pe coloană, cuvintele neînțelese (și COLLATE)',
    $necunoscuteCorp === ['email: COLLATE utf8mb4_bin'] && $necunoscuteCorpBun === [], json_encode([$necunoscuteCorp, $necunoscuteCorpBun]));

// Colația unei coloane: în amprentă doar când diferă de a tabelului; un longtext utf8mb4_bin (JSON pe MariaDB) nu.
$colatiiCa = static function (string $familie): array {
    $m = $familie === 'mariadb';
    $coloana = static fn (string $nume, string $tip, ?string $colatie): array => ['tabel' => 'c', 'coloana' => $nume, 'tip' => $tip,
        'nul' => 'YES', 'implicit' => $m ? 'NULL' : null, 'extra' => '', 'colatie' => $colatie];
    return ['tabele' => [['tabel' => 'c', 'motor' => 'InnoDB', 'colatie' => 'utf8mb4_unicode_ci'],
        ['tabel' => 'v', 'motor' => 'InnoDB', 'colatie' => $m ? 'utf8mb3_general_ci' : 'utf8_general_ci']],
        'coloane' => [$coloana('id', $m ? 'int(11)' : 'int', null), $coloana('nume', 'varchar(50)', 'utf8mb4_unicode_ci'),
            $coloana('email', 'varchar(190)', 'utf8mb4_bin'), $coloana('cod', 'varchar(5)', 'latin1_swedish_ci'),
            $coloana('date_json', $m ? 'longtext' : 'json', $m ? 'utf8mb4_bin' : null), $coloana('vechi', 'varchar(5)', $m ? 'utf8mb3_general_ci' : 'utf8_general_ci'),
            ['tabel' => 'v', 'coloana' => 'a', 'tip' => 'varchar(5)', 'nul' => 'YES', 'implicit' => null, 'extra' => '', 'colatie' => 'utf8mb3_general_ci'],
            ['tabel' => 'v', 'coloana' => 'fara_camp', 'tip' => 'varchar(5)', 'nul' => 'YES', 'implicit' => null, 'extra' => '']],
        'indecsi' => [], 'fk' => []];
};
$colM = Amprenta::normalizeaza($colatiiCa('mariadb'), 'mariadb');
$colY = Amprenta::normalizeaza($colatiiCa('mysql'), 'mysql');
$cc = $colM['tabele']['c']['coloane'] ?? [];
$verifica('Amprenta: colația coloanei doar când diferă de a tabelului (utf8mb4_bin, alt set de caractere, utf8mb3 = utf8); JSON nu; la fel pe ambele servere',
    Amprenta::json($colM) === Amprenta::json($colY) && !isset($cc['id']['colatie']) && !isset($cc['nume']['colatie'])
    && ($cc['email']['colatie'] ?? null) === 'utf8mb4_bin' && ($cc['cod']['colatie'] ?? null) === 'latin1_swedish_ci'
    && !isset($cc['date_json']['colatie']) && ($cc['vechi']['colatie'] ?? null) === 'utf8_general_ci'
    && !isset($colM['tabele']['v']['coloane']['a']['colatie']) && !isset($colM['tabele']['v']['coloane']['fara_camp']['colatie']),
    Amprenta::json($colM) . "\n---\n" . Amprenta::json($colY));
$faraColatie = $colM;
unset($faraColatie['tabele']['c']['coloane']['email']['colatie']);
$difColatie = Amprenta::compara($faraColatie, $colM);
$verifica('Amprenta::compara: o coloană cu altă colație decât cea așteptată e un avertisment (colația), nu o lipsă',
    $difColatie['lipsa'] === [] && count($difColatie['avertismente']) === 1 && str_contains($difColatie['avertismente'][0], 'coloana c.email')
    && str_contains($difColatie['avertismente'][0], 'COLLATE utf8mb4_bin')
    && Amprenta::diferenteColoana($colM['tabele']['c']['coloane']['email'], $def('VARCHAR(190) NULL')) === ['colația'],
    json_encode($difColatie));

$corp = "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `key` VARCHAR(20) NOT NULL DEFAULT '',
    a INT NULL, b INT NULL, user_id INT UNSIGNED NULL, UNIQUE KEY uniq_ab (a, b), KEY (b), FULLTEXT KEY ft_key (`key`),
    CONSTRAINT fk_x_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL, CHECK (a > 0)";
$st = Amprenta::structuraDinCorp($corp);
$verifica('Amprenta: corpul unui CREATE TABLE (coloane, PRIMARY, UNIQUE, KEY fără nume, FULLTEXT, index făcut de cheia străină)',
    array_keys($st['coloane']) === ['a', 'b', 'id', 'key', 'user_id'] && array_keys($st['indecsi']) === ['PRIMARY', 'b', 'fk_x_user', 'ft_key', 'uniq_ab']
    && $st['indecsi']['ft_key'] === ['unic' => false, 'coloane' => ['key'], 'tip' => 'FULLTEXT']
    && ($st['fk']['fk_x_user']['la_stergere'] ?? '') === 'SET NULL' && $st['coloane']['id']['null'] === false, json_encode($st));

$asteptat = ['tabele' => ['t' => ['motor' => 'InnoDB', 'colatie' => 'utf8mb4_unicode_ci',
    'coloane' => ['id' => ['tip' => 'int', 'null' => false, 'implicit' => null, 'extra' => 'auto_increment'],
        'c' => ['tip' => 'varchar(20)', 'null' => false, 'implicit' => "'x'", 'extra' => '']],
    'indecsi' => ['PRIMARY' => ['unic' => true, 'coloane' => ['id']], 'idx_c' => ['unic' => false, 'coloane' => ['c']]],
    'fk' => ['fk_t_u' => ['coloane' => ['c'], 'tabel' => 'u', 'referite' => ['id'], 'la_stergere' => 'RESTRICT', 'la_actualizare' => 'RESTRICT']]],
    'u' => ['motor' => 'InnoDB', 'colatie' => 'utf8mb4_unicode_ci', 'coloane' => [], 'indecsi' => [], 'fk' => []]]];
$curent = $asteptat;
unset($curent['tabele']['u'], $curent['tabele']['t']['fk']['fk_t_u'], $curent['tabele']['t']['indecsi']['idx_c']);
$curent['tabele']['t']['indecsi']['c_vechi'] = ['unic' => false, 'coloane' => ['c']];
$curent['tabele']['t']['coloane']['c']['tip'] = 'varchar(30)';
$curent['tabele']['t']['coloane']['veche'] = ['tip' => 'text', 'null' => true, 'implicit' => null, 'extra' => ''];
$curent['tabele']['vechi'] = $asteptat['tabele']['u'];
$dif = Amprenta::compara($asteptat, $curent);
$verifica('Amprenta::compara: lipsă = tabel/coloană/index (un index echivalent sub alt nume ajunge); avertisment = definiție, cheie străină; în plus = informație',
    $dif['lipsa'] === ['tabelul u'] && count($dif['avertismente']) === 2 && str_contains($dif['avertismente'][0], 'coloana t.c')
    && str_contains($dif['avertismente'][1], 'fk_t_u') && $dif['in_plus'] === ['coloana t.veche', 'tabelul vechi'], json_encode($dif));
unset($curent['tabele']['t']['coloane']['c']);
$verifica('Amprenta::compara: o coloană lipsă e lipsă', in_array('coloana t.c', Amprenta::compara($asteptat, $curent)['lipsa'], true));

// Mesajele, cum le scriu MariaDB 10.11 și MySQL 8.0 (citite pe ambele): o ghilimea din valoare nu se dublează.
$ciudat = "o'brien\\ion' for key 'x.exemplu@exemplu.ro";
$mesaje = [
    "Duplicate entry 'ana@exemplu.ro' for key 'email'" => "Duplicate entry '…' for key 'email'",
    "Duplicate entry '$ciudat' for key 'uniq_c_email'" => "Duplicate entry '…' for key 'uniq_c_email'",
    "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '$ciudat' for key 'c.uniq_c_email'"
        => "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '…' for key 'c.uniq_c_email'",
    "Duplicate entry 'x'y-z\\w' for key 'm.uniq_ab'" => "Duplicate entry '…' for key 'm.uniq_ab'",
    "Incorrect integer value: '$ciudat' for column `cms_t`.`c`.`n` at row 1" => "Incorrect integer value: '…' for column `cms_t`.`c`.`n` at row 1",
    "Incorrect date value: 'o'brien' for column 'd' at row 1" => "Incorrect date value: '…' for column 'd' at row 1",
    "Truncated incorrect DOUBLE value: 'o'brien'" => "Truncated incorrect DOUBLE value: '…'",
    "You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'o'brien' WHERE id = 1' at line 1"
        => "You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near '…' at line 1",
    "Duplicate entry '\xE9a'b' for key 'k'" => "Duplicate entry '…' for key 'k'",
    "Upholding foreign key constraints for table 't', entry 'o'brien', key 2 would lead to a duplicate entry"
        => "Upholding foreign key constraints for table '…', key 2 would lead to a duplicate entry",
    "Altă formă 'a' b 'c' d" => "Altă formă '…' d",
    // Doar nume: rămân cum sunt.
    "Unknown column 'nu_exista' in 'field list'" => "Unknown column 'nu_exista' in 'field list'",
    "Can't DROP 'nu_exista'; check that column/key exists" => "Can't DROP 'nu_exista'; check that column/key exists",
    "Can't DROP COLUMN `nu_exista`; check that it exists" => "Can't DROP COLUMN `nu_exista`; check that it exists",
    "Table 'cms_t.nu_exista' doesn't exist" => "Table 'cms_t.nu_exista' doesn't exist",
    "Data too long for column 'scurt' at row 1" => "Data too long for column 'scurt' at row 1",
    "Lock wait timeout exceeded; try restarting transaction" => "Lock wait timeout exceeded; try restarting transaction",
];
$gresite = [];
foreach ($mesaje as $mesaj => $asteptatMesaj) {
    $iesit = Schema::faraValori($mesaj);
    if ($iesit !== $asteptatMesaj || preg_match('/brien|exemplu|ana@|\\\\ion|z\\\\w|\xE9/', $iesit) === 1) {
        $gresite[] = $mesaj . ' → ' . $iesit;
    }
}
$verifica('Mesajele serverului fără valorile din rânduri (' . count($mesaje) . ' forme; și cu ghilimele, \\ sau „\' for key \'” în valoare)',
    $gresite === [], implode("\n", $gresite));

// Registrul, pe dosare scrise aici.
$scrieMigrare = static function (string $dosar, string $id, string $sus, string $tip = 'schema', bool $blocanta = false): void {
    $fisier = $dosar . '/' . $id . '.php';
    if (!is_dir(dirname($fisier))) {
        mkdir(dirname($fisier), 0777, true);
    }
    file_put_contents($fisier, "<?php\n\ndeclare(strict_types=1);\n\nuse App\\Support\\Migrari\\Schema;\n\nreturn [\n"
        . "    'descriere' => " . var_export('Test: ' . $id, true) . ",\n    'tip' => " . var_export($tip, true) . ",\n"
        . "    'blocanta' => " . ($blocanta ? 'true' : 'false') . ",\n    'sus' => static function (Schema \$s): void {\n" . $sus . "\n    },\n];\n");
};
$proprietari = array_merge(['nucleu'], \App\Support\Plugins::chei());
$registru = static fn (string $dosar): Registru => new Registru($dosar, $proprietari);
$codRegistru = static function (string $dosar) use ($registru): int {
    try {
        $r = $registru($dosar);
        $r->migrari();
        $r->verificaLacatul();
        $r->pasiSite('demo');
        return 0;
    } catch (Eroare $e) {
        return $e->getCode();
    }
};
$d = $lucru . '/registru-bun';
$scrieMigrare($d, 'catalog/0003_trei', '');
$scrieMigrare($d, 'nucleu/0001_unu', '');
$scrieMigrare($d, 'erp/0002_doi', '', 'date');
$scrieMigrare($d, 'site/demo/pas', '', 'site');
file_put_contents($d . '/README.md', 'notă');
$r = $registru($d);
$verifica('Registru: ordinea după NNNN (nu după dosar), proprietarul din dosar, pașii site-ului separat',
    array_column($r->migrari(), 'id') === ['nucleu/0001_unu', 'erp/0002_doi', 'catalog/0003_trei'] && $r->ultimaOrd() === 3
    && array_column($r->pasiSite('demo'), 'id') === ['site/demo/pas'] && $r->pasiSite('alt') === []
    && $r->migrari()[1]['proprietar'] === 'erp' && strlen($r->amprenta()) === 64 && $codRegistru($d) === 0);
$sha = $r->migrari()[0]['sha256'];
file_put_contents($r->migrari()[0]['fisier'], str_replace("\n", "\r\n", (string) file_get_contents($r->migrari()[0]['fisier'])));
$verifica('Registru: sha256 pe fișierul cu LF (un checkout cu CRLF nu schimbă nimic)', $registru($d)->migrari()[0]['sha256'] === $sha);
$cazuriRegistru = [
    'număr folosit de două ori' => static function (string $x) use ($scrieMigrare): void {
        $scrieMigrare($x, 'nucleu/0001_a', '');
        $scrieMigrare($x, 'catalog/0001_b', '');
    },
    'dosar care nu e un proprietar' => static fn (string $x) => $scrieMigrare($x, 'necunoscut/0001_a', ''),
    'nume fără NNNN_' => static fn (string $x) => $scrieMigrare($x, 'nucleu/1_a', ''),
    'migrare direct în rădăcină' => static fn (string $x) => file_put_contents($x . '/0001_a.php', '<?php return [];'),
    'cheie necunoscută' => static function (string $x): void {
        mkdir($x . '/nucleu', 0777, true);
        file_put_contents($x . '/nucleu/0001_a.php', "<?php return ['descriere' => 'x', 'tip' => 'schema', 'blocanta' => false, 'sus' => static function (): void {}, 'jos' => 1];");
    },
    'tip greșit' => static fn (string $x) => $scrieMigrare($x, 'nucleu/0001_a', '', 'altceva'),
    'pas de site fără tip „site”' => static fn (string $x) => $scrieMigrare($x, 'site/demo/pas', '', 'schema'),
];
$coduri = [];
foreach ($cazuriRegistru as $nume => $pregatire) {
    $x = $lucru . '/registru-' . substr(md5($nume), 0, 8);
    mkdir($x, 0777, true);
    $pregatire($x);
    $coduri[$nume] = $codRegistru($x);
}
$verifica('Registru: fiecare greșeală de cod dă 2 (' . implode(', ', array_keys($coduri)) . ')',
    array_unique(array_values($coduri)) === [2], json_encode($coduri, JSON_UNESCAPED_UNICODE));
$x = $lucru . '/registru-lacat';
$scrieMigrare($x, 'nucleu/0001_a', '');
file_put_contents($x . '/migrari.lock', json_encode(['migrari' => ['nucleu/0001_a' => str_repeat('0', 64)]]));
$codSchimbata = $codRegistru($x);
file_put_contents($x . '/migrari.lock', json_encode(['migrari' => ['nucleu/0001_a' => Registru::sha($x . '/nucleu/0001_a.php'), 'nucleu/0002_b' => 'abc']]));
$codDisparuta = $codRegistru($x);
file_put_contents($x . '/migrari.lock', json_encode(['migrari' => ['nucleu/0001_a' => Registru::sha($x . '/nucleu/0001_a.php'), 'nucleu/0002_b' => 'abc'],
    'retrase' => ['nucleu/0002_b' => 'test']]));
$verifica('Registru: migrari.lock — sha schimbat 12, fișier dispărut 12, trecut la „retrase” în regulă',
    $codSchimbata === 12 && $codDisparuta === 12 && $codRegistru($x) === 0, $codSchimbata . '/' . $codDisparuta);
$verifica('Registru: fără dosar = nicio migrare', $registru($lucru . '/nu-exista')->migrari() === [] && $registru($lucru . '/nu-exista')->ultimaOrd() === 0);

// =====================================================================
// Serverele
// =====================================================================
$utilizator = (string) (getenv('CMS_DEV_DB_USER') ?: 'root');
$parola = (string) (getenv('CMS_DEV_DB_PAROLA') ?: '');
$sockMaria = (string) (getenv('CMS_DEV_SOCK') ?: '/tmp/claude-0/maria/sock');
$sockMy8 = (string) (getenv('CMS_DEV_SOCK_MYSQL8') ?: '/tmp/claude-0/my8/run/sock');
$sockNestrict = (string) (getenv('CMS_DEV_SOCK_NESTRICT') ?: (getenv('CMS_DEV_NESTRICT_DIR') ?: '/tmp/claude-0/maria-nestrict') . '/run/sock');
$conectare = static function (string $sock, ?string $baza = null) use ($utilizator, $parola): PDO {
    return new PDO('mysql:unix_socket=' . $sock . ($baza !== null ? ';dbname=' . $baza : '') . ';charset=utf8mb4', $utilizator, $parola,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
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
$nestrictPornit = false;
$servere = ['MariaDB' => $sockMaria];
if (!$faraNestrict) {
    // Al doilea MariaDB, în modul serverului Hostinger (non-strict; tools/dev/mariadb-nestrict.sh, WP-14).
    if (!$merge($sockNestrict)) {
        $iesire = [];
        exec(escapeshellarg($radacina . '/tools/dev/mariadb-nestrict.sh') . ' porneste 2>&1', $iesire, $cod);
        $nestrictPornit = $cod === 0 && $merge($sockNestrict);
        if (!$nestrictPornit) {
            $verifica('MariaDB nestrict merge (CMS_DEV_SOCK_NESTRICT sau tools/dev/mariadb-nestrict.sh porneste)', false, implode("\n", $iesire)
                . "\nFără el testul e doar parțial: --fara-nestrict (spus explicit).");
        }
    }
    if ($merge($sockNestrict)) {
        $modNestrict = (string) $conectare($sockNestrict)->query('SELECT @@GLOBAL.sql_mode')->fetchColumn();
        $verifica('MariaDB nestrict: serverul e chiar non-strict (fără STRICT_TRANS_TABLES / STRICT_ALL_TABLES)',
            preg_match('/STRICT_(TRANS|ALL)_TABLES/', $modNestrict) !== 1, $modNestrict);
        $servere['MariaDB nestrict'] = $sockNestrict;
    }
}
if (!$doarMariaDb) {
    if (!$merge($sockMy8)) {
        exec(escapeshellarg($radacina . '/tools/dev/mysql8.sh') . ' porneste 2>&1', $iesire, $cod);
        $mysql8Pornit = $cod === 0 && $merge($sockMy8);
        if (!$mysql8Pornit) {
            $verifica('MySQL 8 merge (CMS_DEV_SOCK_MYSQL8 sau tools/dev/mysql8.sh porneste)', false, implode("\n", $iesire)
                . "\nFără MySQL 8 testul e doar parțial: --doar-mariadb (spus explicit).");
        }
    }
    if ($merge($sockMy8)) {
        $servere['MySQL 8'] = $sockMy8;
    }
}
$bazeFacute = [];
register_shutdown_function(static function () use (&$bazeFacute, $conectare, $sterge, $lucru, &$mysql8Pornit, &$nestrictPornit, $radacina): void {
    foreach ($bazeFacute as $sock => $baze) {
        try {
            $pdo = $conectare($sock);
            foreach (array_keys($baze) as $baza) {
                $pdo->exec('DROP DATABASE IF EXISTS `' . $baza . '`');
            }
        } catch (Throwable) {
        }
    }
    $sterge($lucru);
    if ($mysql8Pornit) {
        exec(escapeshellarg($radacina . '/tools/dev/mysql8.sh') . ' opreste > /dev/null 2>&1');
    }
    if ($nestrictPornit) {
        exec(escapeshellarg($radacina . '/tools/dev/mariadb-nestrict.sh') . ' opreste > /dev/null 2>&1');
    }
});
$bazaNoua = static function (string $sock, string $nume) use (&$bazeFacute, $conectare): PDO {
    if (preg_match('/^cms_mig_t_[a-z0-9_]+$/', $nume) !== 1) {
        throw new RuntimeException('nume de bază de test greșit: ' . $nume);
    }
    $root = $conectare($sock);
    $root->exec('DROP DATABASE IF EXISTS `' . $nume . '`');
    $root->exec('CREATE DATABASE `' . $nume . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $bazeFacute[$sock][$nume] = true;
    return $conectare($sock, $nume);
};

/** scripts/migreaza.php ca proces separat: [cod, stdout, stderr]. $mediu: null = variabila scoasă. */
$comandaRunner = static function (string $sock, string $baza, ?string $dosar, array $argumente, array $mediu = []) use ($radacina, $utilizator, $parola, $lucru): array {
    $comanda = array_merge([PHP_BINARY, '-d', 'pdo_mysql.default_socket=' . $sock, '-d', 'display_errors=stderr', '-d', 'log_errors=0',
        '-d', 'error_reporting=E_ALL', $radacina . '/scripts/migreaza.php'], $argumente);
    $env = getenv();
    unset($env['SITE_SLUG'], $env['MIGRARI_TEST_OPRESTE_DUPA']);
    $env = array_merge($env, ['DB_HOST' => 'localhost', 'DB_PORT' => '3306', 'DB_DATABASE' => $baza, 'DB_USERNAME' => $utilizator,
        'DB_PASSWORD' => $parola, 'MIGRARI_TEST_JURNAL' => $lucru . '/jurnal.log', 'MIGRARI_TEST_PAUZA_BUCATI_MS' => '0',
        'MIGRARI_TEST_PAUZE' => '0.1,0.1', 'SITE_DATE_DOSAR' => $lucru . '/site-gol']);
    $env['MIGRARI_TEST_DOSAR'] = $dosar ?? $lucru . '/fara-migrari';
    foreach ($mediu as $cheie => $valoare) {
        if ($valoare === null) {
            unset($env[$cheie]);
        } else {
            $env[$cheie] = (string) $valoare;
        }
    }
    return [$comanda, $env];
};
$ruleaza = static function (string $sock, string $baza, ?string $dosar, array $argumente, array $mediu = []) use ($comandaRunner, $radacina): array {
    [$comanda, $env] = $comandaRunner($sock, $baza, $dosar, $argumente, $mediu);
    $proces = proc_open($comanda, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, $radacina, $env);
    $out = (string) stream_get_contents($pipe[1]);
    $err = (string) stream_get_contents($pipe[2]);
    fclose($pipe[1]);
    fclose($pipe[2]);
    return [proc_close($proces), $out, $err];
};
$faraAvertismentePhp = static fn (string $err): bool => preg_match('/(Warning|Notice|Deprecated|Fatal error|Parse error):/', $err) !== 1;
$randuri = static function (PDO $db): array {
    try {
        $r = [];
        foreach ($db->query('SELECT id, ord, rezultat, operatii, cursor_json FROM _platforma_migrari ORDER BY id')->fetchAll() as $rand) {
            $r[$rand['id']] = $rand;
        }
        return $r;
    } catch (PDOException) {
        return [];
    }
};
$tabele = static fn (PDO $db): array => $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
/** _platforma_stare: cheie → valoare (gol dacă tabelul lipsește). */
$stareBaza = static function (PDO $db): array {
    try {
        return $db->query('SELECT cheie, valoare FROM _platforma_stare ORDER BY cheie')->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException) {
        return [];
    }
};
$codUltimaRulare = static fn (PDO $db): ?int => json_decode((string) ($stareBaza($db)['ultima_rulare'] ?? ''), true)['cod'] ?? null;
$amprenta = static fn (PDO $db): string => Amprenta::json(Amprenta::citeste($db, Server::detecteaza($db)));

/** asteptat.json al unui registru de test: migrările aplicate pe o bază goală, apoi amprenta ei. */
$genereazaAsteptat = static function (string $sock, string $dosar, array $argumente = [], array $mediu = []) use ($bazaNoua, $ruleaza, $conectare): void {
    @unlink($dosar . '/asteptat.json');
    $bazaNoua($sock, 'cms_mig_t_generare');
    $ruleaza($sock, 'cms_mig_t_generare', $dosar, array_merge(['--aplica'], $argumente), $mediu);
    file_put_contents($dosar . '/asteptat.json', $ruleaza($sock, 'cms_mig_t_generare', $dosar, ['--amprenta'])[1]);
    $conectare($sock)->exec('DROP DATABASE IF EXISTS cms_mig_t_generare');
};

// Registrele de jucărie (aceleași pe ambele servere).
$jucarii = $lucru . '/jucarii';
$scrieMigrare($jucarii, 'nucleu/0001_jucarii', <<<'PHP'
        $s->tabel('jucarii', "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nume VARCHAR(100) NOT NULL DEFAULT '',
            contor INT NOT NULL DEFAULT 0,
            creat_la DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_jucarii_nume (nume)");
        $s->coloana('jucarii', 'nume', "VARCHAR(100) NOT NULL DEFAULT ''");
        $s->coloana('jucarii', 'culoare', "VARCHAR(20) NOT NULL DEFAULT 'rosu'");
        $s->coloana('jucarii', 'pret', 'DECIMAL(10,2) NOT NULL DEFAULT 0');
        $s->index('jucarii', 'idx_jucarii_culoare', ['culoare']);
        $s->unic('jucarii', 'uniq_jucarii_cod', ['nume', 'culoare']);
PHP);
$scrieMigrare($jucarii, 'catalog/0002_culori', <<<'PHP'
        $s->tabel('culori', "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            jucarie_id INT UNSIGNED NULL,
            cod VARCHAR(20) NOT NULL,
            CONSTRAINT fk_culori_jucarie FOREIGN KEY (jucarie_id) REFERENCES jucarii(id) ON DELETE SET NULL");
        $s->coloana('jucarii', 'descriere', 'TEXT NULL');
PHP);
$scrieMigrare($jucarii, 'catalog/0003_contor', <<<'PHP'
        $s->date('Nouă jucării', "INSERT INTO jucarii (nume) SELECT CONCAT('j', n) FROM (SELECT 1 AS n UNION ALL SELECT 2
            UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8
            UNION ALL SELECT 9) numere WHERE NOT EXISTS (SELECT 1 FROM jucarii)");
        // Fără condiție, voit: dacă o bucată s-ar face de două ori, contorul ar ajunge la 2.
        $s->peBucati('jucarii', 'id', 2, static function (PDO $db, int $de, int $pana): int {
            $stmt = $db->prepare('UPDATE jucarii SET contor = contor + 1 WHERE id > ? AND id <= ?');
            $stmt->execute([$de, $pana]);
            return $stmt->rowCount();
        });
PHP, 'date');
$operatiiJucarii = 13; // 5 + 2 + 1 INSERT + 5 bucăți (id 1–9, câte 2)

$amprentePeServer = [];
$cheiServer = 0;
// D și R (runner-ul, pe migrări de jucărie): sărite doar cu --migrari.
foreach ($cuRunner ? $servere : [] as $numeServer => $sock) {
    $cheiServer++;
    $b = static fn (string $nume): string => 'cms_mig_t_' . $nume;
    $scrie('');
    $scrie('== ' . $numeServer . ' (' . Server::detecteaza($conectare($sock))->nume() . ')');

    // ---------------------------------------------------------------- D
    $scrie('D. Detectarea');
    $db = $bazaNoua($sock, $b('d'));
    $server = Server::detecteaza($db);
    $corpProba = "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, a INT NOT NULL DEFAULT 0, b INT NULL,
        c VARCHAR(20) NOT NULL DEFAULT 'pending', d VARCHAR(20) NULL DEFAULT NULL, e DECIMAL(10,2) NOT NULL DEFAULT 0,
        f TINYINT(1) NOT NULL DEFAULT 1, g DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        h TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, i TEXT NULL, j VARCHAR(10) NOT NULL DEFAULT '0',
        k BIGINT(20) UNSIGNED NULL, l ENUM('x', 'Y') NOT NULL DEFAULT 'x', m VARCHAR(10) NOT NULL,
        n VARCHAR(30) NOT NULL DEFAULT 'it''s', o DOUBLE NOT NULL DEFAULT 0, q YEAR NULL, r JSON NULL,
        s SMALLINT NOT NULL DEFAULT -1, t DATE NULL DEFAULT '2020-01-01', u VARCHAR(10) NOT NULL DEFAULT '',
        v BOOLEAN NOT NULL DEFAULT FALSE, w DECIMAL(5,1) DEFAULT '2.5', x DATETIME DEFAULT NULL, `key` INT,
        y TINYINT(1) UNSIGNED NOT NULL DEFAULT 0, z VARCHAR(10) NOT NULL DEFAULT 'x''y', aa VARCHAR(10) NOT NULL DEFAULT '0x12',
        ab VARCHAR(10) NOT NULL DEFAULT 'b''01''', ac BIT(3) NULL DEFAULT b'101', ad TINYINT(1) ZEROFILL NULL,
        ae INT(5) UNSIGNED ZEROFILL NULL,
        UNIQUE KEY uniq_c (c, d), KEY idx_m (m(5)), KEY (a, b), FULLTEXT KEY ft_i (i)";
    $db->exec('CREATE TABLE proba (' . $corpProba . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $citit = Amprenta::citeste($db, $server)['tabele']['proba'];
    $inteles = Amprenta::structuraDinCorp($corpProba);
    $diferite = [];
    foreach ($citit['coloane'] as $c => $definitie) {
        if (($inteles['coloane'][$c] ?? null) !== $definitie) {
            $diferite[] = 'coloana ' . $c . ': serverul ' . json_encode($definitie) . ', corpul ' . json_encode($inteles['coloane'][$c] ?? null);
        }
    }
    foreach ($citit['indecsi'] as $i => $definitie) {
        if (($inteles['indecsi'][$i] ?? null) != $definitie) {
            $diferite[] = 'indexul ' . $i;
        }
    }
    $verifica('ce scrie serverul despre un tabel = ce înțelege Amprenta din corpul lui (32 de coloane, 5 indecși)',
        $diferite === [] && count($citit['coloane']) === 32 && count($citit['indecsi']) === 5, implode("\n", $diferite));
    $amprentePeServer['proba'][$numeServer] = Amprenta::json(Amprenta::citeste($db, $server));

    // Colația coloanelor, citită de pe server: doar cea diferită de a tabelului intră în amprentă (JSON nu).
    $db->exec("CREATE TABLE colatii (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, nume VARCHAR(50) NULL,
        email VARCHAR(190) COLLATE utf8mb4_bin NULL, cod VARCHAR(5) CHARACTER SET latin1 NULL, date_json JSON NULL)
        ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE colatii_la_fel (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, nume VARCHAR(50) NULL,
        email VARCHAR(190) NULL, cod VARCHAR(5) NULL, date_json JSON NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $cit = Amprenta::citeste($db, $server, ['colatii', 'colatii_la_fel'])['tabele'];
    $difCol = Amprenta::compara(['tabele' => ['x' => $cit['colatii_la_fel'] ?? []]], ['tabele' => ['x' => $cit['colatii'] ?? []]]);
    $verifica('colația coloanelor de pe server: utf8mb4_bin și latin1 intră în amprentă, colația tabelului și JSON nu; '
        . 'două tabele care diferă doar prin ea au amprente diferite (2 avertismente)',
        ($cit['colatii']['coloane']['email']['colatie'] ?? null) === 'utf8mb4_bin'
        && ($cit['colatii']['coloane']['cod']['colatie'] ?? null) === 'latin1_swedish_ci'
        && !isset($cit['colatii']['coloane']['nume']['colatie']) && !isset($cit['colatii']['coloane']['date_json']['colatie'])
        && array_column($cit['colatii_la_fel']['coloane'] ?? [], 'colatie') === []
        && $difCol['lipsa'] === [] && count($difCol['avertismente']) === 2, json_encode($cit) . json_encode($difCol));
    $amprentePeServer['colatii'][$numeServer] = Amprenta::json(['format' => Amprenta::FORMAT, 'tabele' => $cit]);

    // information_schema care sare un tabel schimbat chiar atunci de alt DDL (MariaDB, avertismentul 1684; găsit de T6):
    // Amprenta citește totul din nou; dacă tabelul tot lipsește după pauze, codul 3 (rulează din nou), nu „tabelul lipsește”.
    $sare = new class ('mysql:unix_socket=' . $sock . ';dbname=' . $b('d') . ';charset=utf8mb4', $utilizator, $parola,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
        public int $deSarit = 0;

        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
        {
            if ($query === 'SHOW WARNINGS' && $this->deSarit > 0) {
                $this->deSarit--;
                return parent::query("SELECT 'Warning' AS Level, 1684 AS Code, 'Table ''cms_mig_t_d''.''proba'' was skipped since its "
                    . "definition is being modified by concurrent DDL statement' AS Message");
            }
            return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
        }
    };
    $pauzeVechi = Amprenta::$pauzeSarite;
    Amprenta::$pauzeSarite = [0.01, 0.01, 0.01];
    $sare->deSarit = 2;
    $cititSarit = Amprenta::citeste($sare, $server, ['proba']);
    $consumat = $sare->deSarit;
    $sare->deSarit = 1000;
    $ex = $aruncat(static fn () => Amprenta::citeste($sare, $server, ['proba']));
    Amprenta::$pauzeSarite = $pauzeVechi;
    $verifica('Amprenta: un tabel sărit de information_schema (1684, DDL în curs pe altă conexiune) se citește din nou; '
        . 'sărit mereu: codul 3, cu numele tabelului, nu „lipsește”',
        Amprenta::json($cititSarit) === Amprenta::json(Amprenta::citeste($db, $server, ['proba'])) && $consumat === 0
        && $ex instanceof Eroare && $ex->getCode() === Eroare::LACAT && str_contains($ex->getMessage(), 'tabelul proba'),
        $consumat . ' ' . ($ex?->getMessage() ?? 'nimic aruncat'));
    unset($sare);

    // Schema direct, pe bază.
    $executie = static function (string $tip = 'schema', bool $poateBloca = false): Executie {
        $nimic = static function (): void {
        };
        return new Executie('test/' . $tip, $tip, false, $poateBloca, '', $nimic, static function (int $d, ?array $c): void {
        }, $nimic, static function (string $f, string $o, string $c, float $ms): void {
        }, [0.1], 0);
    };
    $e = $executie();
    $s = new Schema($db, $server, $e);
    $s->tabel('t', "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, nume VARCHAR(50) NOT NULL DEFAULT '', email VARCHAR(190) NULL");
    $s->tabel('t', 'id INT');
    $s->coloana('t', 'nume', "VARCHAR(50) NOT NULL DEFAULT ''");
    $s->coloana('t', 'activ', 'TINYINT(1) NOT NULL DEFAULT 1');
    $s->coloana('t', 'activ', 'TINYINT(1) NOT NULL DEFAULT 1');
    $clase = array_column($e->operatii, 'clasa');
    $verifica('Schema: tabelul creat o dată, apoi „există”; coloana adăugată instant o dată, apoi „există”',
        $clase === ['creare', 'fara_efect', 'fara_efect', 'instant', 'fara_efect'] && $e->cuEfect === 2 && $e->avertismente === [], json_encode($clase));
    $s->coloana('t', 'nume', 'VARCHAR(80) NULL');
    $verifica('Schema: o coloană existentă cu altă definiție dă un avertisment și rămâne cum e',
        count($e->avertismente) === 1 && str_contains($e->avertismente[0], 'altă definiție')
        && Amprenta::citeste($db, $server, ['t'])['tabele']['t']['coloane']['nume']['tip'] === 'varchar(50)', json_encode($e->avertismente));
    $db->exec('ALTER TABLE t ADD INDEX nume_vechi (nume)');
    $s2 = new Schema($db, $server, $e2 = $executie());
    $s2->index('t', 'idx_t_nume', ['nume']);
    $verifica('Schema: un index echivalent sub alt nume (de pe un site vechi) ajunge',
        $e2->cuEfect === 0 && ($e2->operatii[0]['detaliu'] ?? '') === 'există ca nume_vechi', json_encode($e2->operatii));
    $s3 = new Schema($db, $server, $e3 = $executie());
    $s3->existaColoana('t', 'adaugata_de_altul');
    $conectare($sock, $b('d'))->exec('ALTER TABLE t ADD COLUMN adaugata_de_altul INT NULL');
    $s3->coloana('t', 'adaugata_de_altul', 'INT NULL');
    $verifica('Schema: coloana adăugată între timp de alt proces (codul vechi) → 1060 acceptat, fără eroare',
        $e3->cuEfect === 0 && str_contains($e3->operatii[0]['detaliu'] ?? '', 'între timp'), json_encode($e3->operatii));
    $db->exec("INSERT INTO t (nume, email) VALUES ('a', 'ana@exemplu.ro'), ('b', 'ana@exemplu.ro'), ('c', NULL), ('d', NULL)");
    $ex = $aruncat(static fn () => (new Schema($db, $server, $executie()))->unic('t', 'uniq_t_email', ['email']));
    $verifica('Schema: index unic peste valori repetate → codul 1 cu numărul lor, fără valori (NULL nu se numără)',
        $ex instanceof Eroare && $ex->getCode() === 1 && str_contains($ex->getMessage(), '1 valori') && !str_contains($ex->getMessage(), 'ana@'),
        $ex?->getMessage() ?? 'nimic aruncat');
    $ex = $aruncat(static fn () => (new Schema($db, $server, $executie()))->coloana('t', 'obligatorie', 'VARCHAR(10) NOT NULL'));
    $verifica('Schema: NOT NULL fără DEFAULT pe un tabel cu rânduri → refuzat (codul vechi n-ar mai putea scrie)',
        $ex instanceof Eroare && str_contains($ex->getMessage(), 'NOT NULL fără DEFAULT') && !(new Schema($db, $server, $executie()))->existaColoana('t', 'obligatorie'));
    $ex = $aruncat(static fn () => (new Schema($db, $server, $executie()))->coloana('t', 'pozitie', 'INT NULL AFTER nume'));
    $ex2 = $aruncat(static fn () => (new Schema($db, $server, $executie()))->coloana('t', 'gresita', 'INT NUL'));
    $ex3 = $aruncat(static fn () => (new Schema($db, $server, $executie()))->stergeColoana('t', 'nume'));
    $verifica('Schema: AFTER refuzat, un cuvânt greșit refuzat, ștergerea doar în „contract”',
        $ex instanceof Eroare && str_contains($ex->getMessage(), 'AFTER') && $ex2 instanceof Eroare && str_contains($ex2->getMessage(), 'nu înțeleg')
        && $ex3 instanceof Eroare && str_contains($ex3->getMessage(), 'contract'));
    $s4 = new Schema($db, $server, $e4 = $executie('contract'));
    $s4->stergeColoana('t', 'adaugata_de_altul');
    $s4->stergeColoana('t', 'adaugata_de_altul');
    $verifica('Schema: într-o migrare „contract”, ștergerea merge o dată, apoi „nu există”',
        $e4->cuEfect === 1 && !$s4->existaColoana('t', 'adaugata_de_altul'));
    $db->exec('ALTER TABLE t ADD COLUMN cantitate INT NOT NULL DEFAULT 0');
    $ex = $aruncat(static fn () => (new Schema($db, $server, $executie()))->modificaColoana('t', 'cantitate', 'BIGINT NOT NULL DEFAULT 0'));
    $tipDupa = Amprenta::citeste($db, $server, ['t'])['tabele']['t']['coloane']['cantitate']['tip'];
    $s5 = new Schema($db, $server, $e5 = $executie('schema', true));
    $s5->modificaColoana('t', 'cantitate', 'BIGINT NOT NULL DEFAULT 0');
    $verifica('Schema: schimbarea tipului fără voie de blocare → codul 11 și tabelul neatins; cu voie se face (copie)',
        $ex instanceof Eroare && $ex->getCode() === 11 && $tipDupa === 'int' && ($e5->operatii[0]['clasa'] ?? '') === 'copie'
        && Amprenta::citeste($db, $server, ['t'])['tabele']['t']['coloane']['cantitate']['tip'] === 'bigint', ($ex?->getMessage() ?? '') . json_encode($e5->operatii));
    $ex = $aruncat(static fn () => (new Schema($db, $server, $executie()))->citeste('UPDATE t SET nume = 1'));
    $ex2 = $aruncat(static fn () => (new Schema($db, $server, $executie()))->date('x', 'ALTER TABLE t ADD COLUMN y INT'));
    $verifica('Schema: citeste() doar citește, date() nu primește DDL', $ex instanceof Eroare && $ex2 instanceof Eroare);
    $s6 = new Schema($db, $server, $e6 = $executie());
    $schimbate = $s6->date('nume gol', "UPDATE t SET nume = '' WHERE nume = 'nu-exista'");
    $verifica('Schema: o instrucțiune de date fără rânduri atinse nu e o operație', $schimbate === 0 && $e6->cuEfect === 0);
    // Colația: o migrare nu cere una (coloana ia colația tabelului); o coloană existentă cu altă colație = avertisment.
    $ex = $aruncat(static fn () => (new Schema($db, $server, $executie()))->coloana('t', 'cheie_bin', 'VARCHAR(20) COLLATE utf8mb4_bin NULL'));
    $ex2 = $aruncat(static fn () => (new Schema($db, $server, $executie()))->tabel('tcol', 'id INT NOT NULL, cod VARCHAR(5) CHARACTER SET latin1 NULL'));
    $ex3 = $aruncat(static fn () => (new Schema($db, $server, $executie()))->tabelComplet('tcol', ['id' => 'INT NOT NULL', 'cod' => 'VARCHAR(5) COLLATE utf8mb4_bin NULL']));
    $verifica('Schema: CHARACTER SET / COLLATE într-o definiție de coloană refuzate (coloana(), tabel(), tabelComplet()), nimic creat',
        $ex instanceof Eroare && $ex2 instanceof Eroare && $ex3 instanceof Eroare && str_contains($ex->getMessage(), 'colația tabelului')
        && str_contains($ex2->getMessage(), 'cod: CHARACTER SET latin1') && !(new Schema($db, $server, $executie()))->existaColoana('t', 'cheie_bin')
        && !(new Schema($db, $server, $executie()))->existaTabel('tcol'),
        implode(' | ', array_map(static fn (?Throwable $x): string => $x?->getMessage() ?? '-', [$ex, $ex2, $ex3])));
    $sCol = new Schema($db, $server, $eCol = $executie());
    $sCol->coloana('colatii', 'email', 'VARCHAR(190) NULL');
    $verifica('Schema: o coloană existentă cu altă colație decât a tabelului = avertisment (colația), nimic schimbat',
        $eCol->cuEfect === 0 && count($eCol->avertismente) === 1 && str_contains($eCol->avertismente[0], 'colația')
        && (Amprenta::citeste($db, $server, ['colatii'])['tabele']['colatii']['coloane']['email']['colatie'] ?? null) === 'utf8mb4_bin',
        json_encode($eCol->avertismente));

    // tabelComplet (baseline-ul, WP-03): pe o bază fără tabel un singur CREATE; pe una veche, doar ce lipsește.
    $coloaneTc = [
        'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
        't_id' => 'INT UNSIGNED NULL',
        'cod' => "VARCHAR(20) NOT NULL DEFAULT ''",
        'stare' => "VARCHAR(20) NOT NULL DEFAULT 'nou'",
        'nota' => 'TEXT NULL',
        'creat_la' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
    ];
    $cheiTc = ['PRIMARY KEY (id)', 'UNIQUE KEY uniq_tc_cod (cod)', 'KEY idx_tc_stare (stare, creat_la)', 'KEY fk_tc_t (t_id)',
        'CONSTRAINT fk_tc_t FOREIGN KEY (t_id) REFERENCES t (id) ON DELETE SET NULL'];
    $s7 = new Schema($db, $server, $e7 = $executie());
    $s7->tabelComplet('tc', $coloaneTc, $cheiTc);
    $tc = Amprenta::citeste($db, $server, ['tc'])['tabele']['tc'] ?? null;
    $verifica('Schema: tabelComplet pe o bază fără tabel = un singur CREATE TABLE, cu coloanele, indecșii și cheia străină',
        array_column($e7->operatii, 'clasa') === ['creare'] && $e7->cuEfect === 1 && $e7->avertismente === []
        && array_keys($tc['coloane'] ?? []) === ['cod', 'creat_la', 'id', 'nota', 'stare', 't_id']
        && array_keys($tc['indecsi'] ?? []) === ['PRIMARY', 'fk_tc_t', 'idx_tc_stare', 'uniq_tc_cod'] && isset($tc['fk']['fk_tc_t'])
        && ($tc['colatie'] ?? '') === 'utf8mb4_unicode_ci', json_encode($e7->operatii) . json_encode($tc));
    $s7b = new Schema($db, $server, $e7b = $executie());
    $s7b->tabelComplet('tc', $coloaneTc, $cheiTc);
    $verifica('Schema: tabelComplet a doua oară: fiecare coloană și fiecare index „există”, nimic făcut',
        $e7b->cuEfect === 0 && array_unique(array_column($e7b->operatii, 'clasa')) === ['fara_efect'] && $e7b->avertismente === []
        && count($e7b->operatii) === 1 + 5 + 3, json_encode($e7b->operatii));
    // „Codul vechi”: același tabel, fără două coloane, cu un index sub alt nume, fără cheia străină, cu altă definiție la nota.
    $db->exec("CREATE TABLE tcv (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, t_id INT UNSIGNED NULL, cod VARCHAR(20) NOT NULL DEFAULT '',
        nota MEDIUMTEXT NULL, UNIQUE KEY cod_vechi (cod)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("INSERT INTO tcv (cod) VALUES ('a'), ('b')");
    $s8 = new Schema($db, $server, $e8 = $executie());
    $s8->tabelComplet('tcv', $coloaneTc, str_replace(['uniq_tc_cod', 'idx_tc_stare', 'fk_tc_t'], ['uniq_tcv_cod', 'idx_tcv_stare', 'fk_tcv_t'], $cheiTc));
    $tcv = Amprenta::citeste($db, $server, ['tcv'])['tabele']['tcv'];
    $faraEfectTcv = array_column(array_filter($e8->operatii, static fn (array $o): bool => $o['clasa'] === 'fara_efect'), 'obiect');
    $verifica('Schema: tabelComplet pe un tabel vechi: adaugă doar coloanele (instant) și indecșii (inplace) care lipsesc; '
        . 'indexul unic sub alt nume ajunge; cheia străină lipsă și definiția diferită = avertismente, nimic schimbat',
        $e8->cuEfect === 4 && array_values(array_filter(array_column($e8->operatii, 'clasa'), static fn (string $c): bool => $c !== 'fara_efect'))
            === ['instant', 'instant', 'inplace', 'inplace']
        && in_array('tcv.uniq_tcv_cod', $faraEfectTcv, true) && !isset($tcv['indecsi']['uniq_tcv_cod']) && isset($tcv['indecsi']['cod_vechi'])
        && isset($tcv['coloane']['stare'], $tcv['coloane']['creat_la'], $tcv['indecsi']['idx_tcv_stare'], $tcv['indecsi']['fk_tcv_t'])
        && $tcv['fk'] === [] && $tcv['coloane']['nota']['tip'] === 'mediumtext' && count($e8->avertismente) === 2
        && str_contains(implode("\n", $e8->avertismente), 'cheia străină tcv.fk_tcv_t lipsește')
        && str_contains(implode("\n", $e8->avertismente), 'coloana tcv.nota există cu altă definiție')
        && (int) $db->query("SELECT COUNT(*) FROM tcv WHERE stare = 'nou'")->fetchColumn() === 2, json_encode($e8->operatii) . json_encode($e8->avertismente));
    // Cursa: codul vechi creează tabelul (parțial) chiar după ce migrarea a văzut că lipsește.
    $s9 = new Schema($db, $server, $e9 = $executie());
    $s9->existaTabel('tcr');
    $conectare($sock, $b('d'))->exec("CREATE TABLE tcr (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, cod VARCHAR(20) NOT NULL DEFAULT '')");
    $s9->tabelComplet('tcr', ['id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT', 'cod' => "VARCHAR(20) NOT NULL DEFAULT ''", 'nota' => 'TEXT NULL'],
        ['PRIMARY KEY (id)', 'KEY idx_tcr_cod (cod)']);
    $tcr = Amprenta::citeste($db, $server, ['tcr'])['tabele']['tcr'];
    $verifica('Schema: tabelComplet când codul vechi face tabelul chiar atunci (CREATE IF NOT EXISTS fără efect): restul se adaugă',
        isset($tcr['coloane']['nota'], $tcr['indecsi']['idx_tcr_cod']), json_encode($e9->operatii));
    // Cursa, cu tabelul vechi complet la coloane, dar fără indecșii secundari și fără cheia străină.
    $s9b = new Schema($db, $server, $e9b = $executie());
    $s9b->existaTabel('tcr2');
    $conectare($sock, $b('d'))->exec("CREATE TABLE tcr2 (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, t_id INT UNSIGNED NULL,
        cod VARCHAR(20) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $s9b->tabelComplet('tcr2', ['id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT', 't_id' => 'INT UNSIGNED NULL', 'cod' => "VARCHAR(20) NOT NULL DEFAULT ''"],
        ['PRIMARY KEY (id)', 'KEY idx_tcr2_cod (cod)', 'UNIQUE KEY uniq_tcr2_cod_id (cod, id)', 'KEY fk_tcr2_t (t_id)',
            'CONSTRAINT fk_tcr2_t FOREIGN KEY (t_id) REFERENCES t (id) ON DELETE SET NULL']);
    $tcr2 = Amprenta::citeste($db, $server, ['tcr2'])['tabele']['tcr2'];
    $verifica('Schema: tabelComplet când codul vechi face chiar atunci tabelul cu toate coloanele, dar fără indecși și fără cheia străină: '
        . 'indecșii se adaugă, cheia străină lipsă e un avertisment, CREATE-ul fără efect nu e o operație',
        isset($tcr2['indecsi']['idx_tcr2_cod'], $tcr2['indecsi']['uniq_tcr2_cod_id'], $tcr2['indecsi']['fk_tcr2_t']) && $tcr2['fk'] === []
        && ($e9b->operatii[0]['clasa'] ?? '') === 'fara_efect' && str_contains($e9b->operatii[0]['detaliu'] ?? '', 'între timp')
        && $e9b->cuEfect === 3 && count($e9b->avertismente) === 1 && str_contains($e9b->avertismente[0], 'cheia străină tcr2.fk_tcr2_t lipsește'),
        json_encode($e9b->operatii) . json_encode($e9b->avertismente));
    $ex = $aruncat(static fn () => (new Schema($db, $server, $executie()))->tabelComplet('tx', ['id' => 'INT NOT NULL PRIMARY KEY']));
    $ex2 = $aruncat(static fn () => (new Schema($db, $server, $executie()))->tabelComplet('tx', ['id' => 'INT NOT NULL'], ['FULLTEXT KEY ft (id)']));
    $ex3 = $aruncat(static fn () => (new Schema($db, $server, $executie()))->tabelComplet('tx', ['id' => 'INT NOT NULL'], ['KEY (id)']));
    $ex4 = $aruncat(static fn () => (new Schema($db, $server, $executie()))->tabelComplet('tx', ['id' => 'INT NOT NULL', 'a' => 'INT NULL AFTER id']));
    $verifica('Schema: tabelComplet refuză o cheie în definiția coloanei, o cheie neînțeleasă sau fără nume, AFTER (și nu creează nimic)',
        $ex instanceof Eroare && $ex2 instanceof Eroare && $ex3 instanceof Eroare && $ex4 instanceof Eroare
        && !(new Schema($db, $server, $executie()))->existaTabel('tx'), implode(' | ', array_map(static fn (?Throwable $x): string => $x?->getMessage() ?? '-', [$ex, $ex2, $ex3, $ex4])));
    // În simulare: tabelul intră în model, iar ce urmează îl vede.
    $sim = new Executie('test/sim', 'schema', true, false, '', static function (): void {
    }, static function (int $d, ?array $c): void {
    }, static function (): void {
    }, static function (string $f, string $o, string $c, float $ms): void {
    });
    $sim->model = new ArrayObject();
    $s10 = new Schema($db, $server, $sim);
    $s10->tabelComplet('tsim', ['id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT', 'a' => 'INT NULL'], ['PRIMARY KEY (id)']);
    $s10->coloana('tsim', 'b', 'INT NULL');
    $s10->coloana('tsim', 'a', 'INT NULL');
    $verifica('Schema: tabelComplet în simulare: o singură „creare”, nimic trimis; coloana() de după vede tabelul din model',
        array_column($sim->operatii, 'clasa') === ['creare', 'instant', 'fara_efect'] && !(new Schema($db, $server, $executie()))->existaTabel('tsim'),
        json_encode($sim->operatii));

    // schimbaImplicit: doar valoarea implicită, rândurile neatinse (link_plata/0009).
    $db->exec("ALTER TABLE tcv ADD COLUMN procesator VARCHAR(20) NOT NULL DEFAULT 'stripe'");
    $s11 = new Schema($db, $server, $e11 = $executie());
    $s11->schimbaImplicit('tcv', 'procesator', "'euplatesc'");
    $s11->schimbaImplicit('tcv', 'procesator', "'euplatesc'");
    $db->exec("INSERT INTO tcv (cod) VALUES ('c')");
    $procesatori = $db->query('SELECT cod, procesator FROM tcv ORDER BY cod')->fetchAll(PDO::FETCH_KEY_PAIR);
    $verifica('Schema: schimbaImplicit schimbă instant doar valoarea implicită (rândurile vechi rămân), a doua oară „există”',
        array_column($e11->operatii, 'clasa') === ['instant', 'fara_efect'] && $e11->cuEfect === 1
        && $procesatori === ['a' => 'stripe', 'b' => 'stripe', 'c' => 'euplatesc']
        && Amprenta::citeste($db, $server, ['tcv'])['tabele']['tcv']['coloane']['procesator']['implicit'] === "'euplatesc'",
        json_encode($e11->operatii) . json_encode($procesatori));
    $ex = $aruncat(static fn () => (new Schema($db, $server, $executie()))->schimbaImplicit('tcv', 'procesator', "'x'; DROP TABLE t"));
    $ex2 = $aruncat(static fn () => (new Schema($db, $server, $executie()))->schimbaImplicit('tcv', 'nu_exista', '0'));
    $verifica('Schema: schimbaImplicit refuză altceva decât un text simplu sau un număr, și o coloană care lipsește',
        $ex instanceof Eroare && $ex2 instanceof Eroare && isset(Amprenta::citeste($db, $server, ['t'])['tabele']['t']));

    // ---------------------------------------------------------------- R
    $scrie('R. scripts/migreaza.php');
    $gol = $bazaNoua($sock, $b('gol'));
    [$cod, $out, $err] = $ruleaza($sock, $b('gol'), null, ['--plan']);
    $verifica('bază goală, nicio migrare: --plan iese cu 0, „nimic de făcut”', $cod === 0 && str_contains($out, 'Nimic de făcut') && $faraAvertismentePhp($err), $out . $err);
    [$cod, $out] = $ruleaza($sock, $b('gol'), null, ['--plan']);
    $verifica('--plan nu schimbă nimic (nici tabelele de urmărire)', $tabele($gol) === []);
    [$cod, $out, $err] = $ruleaza($sock, $b('gol'), null, ['--aplica']);
    $verifica('bază goală, nicio migrare: --aplica iese cu 0 și face doar _platforma_migrari, _platforma_stare',
        $cod === 0 && $tabele($gol) === ['_platforma_migrari', '_platforma_stare'] && $faraAvertismentePhp($err), $out . $err);
    $stare = $gol->query("SELECT valoare FROM _platforma_stare WHERE cheie = 'ultima_ord'")->fetchColumn();
    [$cod2, $out2] = $ruleaza($sock, $b('gol'), null, ['--plan']);
    $verifica('… cu ultima_ord 0; apoi --plan tot 0', $stare === '0' && $cod2 === 0, $out2);

    $straina = $bazaNoua($sock, $b('straina'));
    $straina->exec('CREATE TABLE wp_posts (ID BIGINT UNSIGNED NOT NULL PRIMARY KEY, post_title TEXT)');
    $coduri = [];
    foreach (['--plan', '--aplica', '--stare', '--verifica', '--amprenta'] as $comanda) {
        $coduri[$comanda] = $ruleaza($sock, $b('straina'), $jucarii, [$comanda])[0];
    }
    $verifica('bază străină (un WordPress): fiecare comandă iese cu 4 și nu creează nimic',
        array_unique(array_values($coduri)) === [4] && $tabele($straina) === ['wp_posts'], json_encode($coduri));

    // Migrările de jucărie: întâi asteptat.json, din ele însele, pe o bază goală.
    @unlink($jucarii . '/asteptat.json');
    $gen = $bazaNoua($sock, $b('gen'));
    [$cod, $out, $err] = $ruleaza($sock, $b('gen'), $jucarii, ['--plan']);
    $verifica('--plan pe o bază goală: 10, cu operațiile clasate (creare, instant, inplace, date)',
        $cod === 10 && str_contains($out, 'creare    tabel jucarii') && str_contains($out, 'instant   coloana jucarii.culoare')
        && str_contains($out, 'inplace   unic jucarii.uniq_jucarii_cod') && str_contains($out, 'date      pe_bucati jucarii'), $out . $err);
    [$cod, $out, $err] = $ruleaza($sock, $b('gen'), $jucarii, ['--aplica']);
    $verifica('--aplica fără asteptat.json: aplică, apoi 14 („nu pot spune dacă baza e completă”), iar ultima rulare are codul 14, nu 0',
        $cod === 14 && str_contains($out, 'lipsește') && count($randuri($gen)) === 3 && $faraAvertismentePhp($err)
        && $codUltimaRulare($gen) === 14 && ($stareBaza($gen)['ultima_ord'] ?? '') === '3', $out . $err . json_encode($stareBaza($gen)));
    [$cod, $json] = $ruleaza($sock, $b('gen'), $jucarii, ['--amprenta']);
    file_put_contents($jucarii . '/asteptat.json', $json);
    $verifica('--amprenta scrie amprenta (doar structură): 2 tabele de jucărie',
        $cod === 0 && array_keys(json_decode($json, true)['tabele'] ?? []) === ['culori', 'jucarii'], $json);
    $amprentePeServer['jucarii'][$numeServer] = $json;

    $a = $bazaNoua($sock, $b('a'));
    [$cod, $out, $err] = $ruleaza($sock, $b('a'), $jucarii, ['--aplica']);
    $r = $randuri($a);
    $verifica('--aplica pe o bază goală: 0, trei migrări „aplicata”, ' . $operatiiJucarii . ' operații, verificarea trecută',
        $cod === 0 && array_column($r, 'rezultat') === ['aplicata', 'aplicata', 'aplicata']
        && array_sum(array_map('intval', array_column($r, 'operatii'))) === $operatiiJucarii && str_contains($out, 'baza are tot ce cer migrările')
        && $faraAvertismentePhp($err), $out . $err . json_encode($r));
    $verifica('… fiecare jucărie numărată o dată, ultima_ord 3, ultima rulare cu codul 0, amprenta = asteptat.json',
        (int) $a->query('SELECT COUNT(*) FROM jucarii WHERE contor = 1')->fetchColumn() === 9
        && $a->query("SELECT valoare FROM _platforma_stare WHERE cheie = 'ultima_ord'")->fetchColumn() === '3'
        && $codUltimaRulare($a) === 0 && $amprenta($a) === $json);
    $sqlMode = (string) $a->query('SELECT @@GLOBAL.sql_mode')->fetchColumn();
    $serverStrict = preg_match('/STRICT_(TRANS|ALL)_TABLES/', $sqlMode) === 1;
    $jurnalTest = (string) @file_get_contents($lucru . '/jurnal.log');
    $verifica('--aplica scrie sql_mode-ul serverului în ieșire și în migrari.log, și dacă migrările l-au făcut strict ('
        . ($serverStrict ? 'deja strict' : 'non-strict: + STRICT_TRANS_TABLES') . ')',
        str_contains($out, 'sql_mode: ' . ($sqlMode !== '' ? $sqlMode : '(gol)')) && str_contains($jurnalTest, '--aplica: 3 de aplicat')
        && str_contains($jurnalTest, 'sql_mode ' . ($sqlMode !== '' ? $sqlMode : '(gol)'))
        && ($serverStrict ? str_contains($out, '(al serverului, deja strict)') && !str_contains($jurnalTest, '+ STRICT_TRANS_TABLES')
            : str_contains($out, 'STRICT_TRANS_TABLES adăugat') && str_contains($jurnalTest, '(migrările: + STRICT_TRANS_TABLES)')), $out . "\n" . $jurnalTest);
    [$cod, $out] = $ruleaza($sock, $b('a'), $jucarii, ['--aplica']);
    [$codPlan, $outPlan] = $ruleaza($sock, $b('a'), $jucarii, ['--plan']);
    $verifica('a doua oară: --aplica „nimic de făcut” (0 operații), --plan 0',
        $cod === 0 && str_contains($out, 'Nimic de făcut') && $codPlan === 0
        && (int) $a->query('SELECT COUNT(*) FROM jucarii WHERE contor = 1')->fetchColumn() === 9, $out . $outPlan);
    [$cod, $out] = $ruleaza($sock, $b('a'), $jucarii, ['--stare']);
    $verifica('--stare: ultima_ord 3, nimic de aplicat, niciun runner activ',
        $cod === 0 && str_contains($out, 'ultima_ord: 3') && str_contains($out, 'de aplicat: nimic') && str_contains($out, 'runner activ acum: nu'), $out);
    [$cod, $out] = $ruleaza($sock, $b('a'), $jucarii, ['--plan', '--json']);
    $date = json_decode($out, true);
    $verifica('--json: un singur obiect JSON, cu „cod” egal cu codul de ieșire', is_array($date) && ($date['cod'] ?? null) === $cod && $cod === 0, $out);

    // Aceeași migrare pe o bază unde obiectele există deja („detectata”).
    $a->exec("DELETE FROM _platforma_migrari WHERE id IN ('nucleu/0001_jucarii', 'catalog/0002_culori')");
    [$cod, $out] = $ruleaza($sock, $b('a'), $jucarii, ['--aplica']);
    $r = $randuri($a);
    $verifica('rândurile de urmărire pierdute (o copie veche restaurată): migrările se refac „detectata”, 0 operații',
        $cod === 0 && $r['nucleu/0001_jucarii']['rezultat'] === 'detectata' && $r['catalog/0002_culori']['rezultat'] === 'detectata'
        && (int) $r['nucleu/0001_jucarii']['operatii'] === 0, $out . json_encode($r));
    $veche = $bazaNoua($sock, $b('veche'));
    $veche->exec("CREATE TABLE settings (`key` VARCHAR(190) NOT NULL PRIMARY KEY, value TEXT)");
    $veche->exec('CREATE TABLE admins (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY)');
    $veche->exec('CREATE TABLE pages (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY)');
    $veche->exec("CREATE TABLE jucarii (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, descriere TEXT NULL,
        pret DECIMAL(10,2) NOT NULL DEFAULT 0, culoare VARCHAR(20) NOT NULL DEFAULT 'rosu', nume VARCHAR(100) NOT NULL DEFAULT '',
        contor INT NOT NULL DEFAULT 0, creat_la DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, coloana_veche INT NULL,
        KEY nume (nume), KEY culoare (culoare), UNIQUE KEY cod_vechi (nume, culoare)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $veche->exec("CREATE TABLE culori (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, jucarie_id INT UNSIGNED NULL, cod VARCHAR(20) NOT NULL,
        CONSTRAINT fk_culori_jucarie FOREIGN KEY (jucarie_id) REFERENCES jucarii(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    [$cod, $out] = $ruleaza($sock, $b('veche'), $jucarii, ['--plan', '--pana-la=catalog/0002_culori']);
    $verifica('baza unui site vechi (alte nume de indecși, altă ordine a coloanelor): --plan vede tot ca existent',
        $cod === 10 && !str_contains($out, 'instant') && !str_contains($out, 'inplace') && !str_contains($out, 'creare'), $out);
    [$cod, $out] = $ruleaza($sock, $b('veche'), $jucarii, ['--aplica', '--pana-la=catalog/0002_culori']);
    $r = $randuri($veche);
    $verifica('… --aplica --pana-la: ambele „detectata”, 0 operații, a treia neatinsă, verificarea sărită',
        $cod === 0 && array_column($r, 'rezultat') === ['detectata', 'detectata'] && str_contains($out, 'sărită'), $out . json_encode($r));
    [$cod, $out] = $ruleaza($sock, $b('veche'), $jucarii, ['--verifica']);
    $verifica('… --verifica: 0, tot ce trebuie există; coloana veche și tabelele site-ului „în plus”',
        $cod === 0 && str_contains($out, 'în plus  coloana jucarii.coloana_veche') && str_contains($out, 'în plus  tabelul settings'), $out);

    // Garda (App\Support\Migrari\Garda; docs/MIGRARI.md, „Garda”) cu numerele rezervate pe pachete de lucru (planul Etapei 1,
    // §4.3): o migrare nouă poate avea un număr sub una deja aplicată. Codul „v1” are 0001 și 0003, codul „v2” în plus 0002;
    // garda citește ultima.php al codului dat (Garda::folosesteDosarul), scris ca de genereaza-fixture.php (Registru::textUltima).
    $v1 = $lucru . '/goluri-v1-' . $cheiServer;
    $v2 = $lucru . '/goluri-v2-' . $cheiServer;
    foreach ([$v1, $v2] as $dosarGol) {
        $scrieMigrare($dosarGol, 'nucleu/0001_gol_unu', "        \$s->tabel('gol_unu', 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');");
        $scrieMigrare($dosarGol, 'catalog/0003_gol_trei', "        \$s->tabel('gol_trei', 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');");
    }
    $scrieMigrare($v2, 'catalog/0002_gol_doi', "        \$s->tabel('gol_doi', 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');");
    foreach ([$v1, $v2] as $dosarGol) {
        $genereazaAsteptat($sock, $dosarGol);
        file_put_contents($dosarGol . '/ultima.php', $registru($dosarGol)->textUltima());
    }
    $gardaCu = static function (string $dosarCod, PDO $db): string {
        Garda::folosesteDosarul($dosarCod);
        return Garda::stare($db);
    };
    $goluri = $bazaNoua($sock, $b('goluri'));
    [$cg1, $og1] = $ruleaza($sock, $b('goluri'), $v1, ['--aplica']);
    $stareV1 = $stareBaza($goluri);
    $dupaV1 = [$gardaCu($v1, $goluri), $gardaCu($v2, $goluri)];
    $verifica('garda: baza la zi cu codul v1 (0001, 0003; ultima_ord 3) e la_zi pentru v1 și in_urma pentru v2, care are în plus '
        . '0002, sub ultima_ord (aceeași ultimă migrare, altă amprentă)',
        $cg1 === 0 && ($stareV1['ultima_ord'] ?? '') === '3' && $registru($v1)->ultimaOrd() === $registru($v2)->ultimaOrd()
        && $dupaV1 === [Garda::LA_ZI, Garda::IN_URMA], $og1 . json_encode($dupaV1));
    [$cg2, $og2] = $ruleaza($sock, $b('goluri'), $v2, ['--stare']);
    $verifica('… --stare cu v2 arată 0002 de aplicat (ultima_ord 1: prima neterminată e 0002)',
        $cg2 === 0 && str_contains($og2, 'catalog/0002_gol_doi') && str_contains($og2, 'ultima_ord: 1'), $og2);
    [$cg3, $og3] = $ruleaza($sock, $b('goluri'), $v2, ['--aplica']);
    $dupaV2 = [$gardaCu($v2, $goluri), $gardaCu($v1, $goluri)];
    $verifica('… --aplica cu v2 aplică doar 0002 (după 0003, deja aplicată); apoi la_zi pentru v2 și pentru v1 (codul întors la '
        . 'versiunea de dinainte: baza are tot ce cere el, plus 0002)',
        $cg3 === 0 && array_column($randuri($goluri), 'rezultat', 'id') === ['catalog/0002_gol_doi' => 'aplicata', 'catalog/0003_gol_trei' => 'aplicata',
            'nucleu/0001_gol_unu' => 'aplicata'] && $dupaV2 === [Garda::LA_ZI, Garda::LA_ZI], $og3 . json_encode($dupaV2));
    $stareInainte = $stareBaza($goluri);
    [$cg4, $og4] = $ruleaza($sock, $b('goluri'), $v1, ['--aplica']);
    $verifica('… runner-ul cu v1 pe această bază: 12 (0002 aplicată lipsește din cod), starea neatinsă, garda tot la_zi',
        $cg4 === 12 && $stareBaza($goluri) === $stareInainte && $gardaCu($v1, $goluri) === Garda::LA_ZI, $og4);
    $goluri->exec("UPDATE _platforma_migrari SET rezultat = 'partiala' WHERE id = 'catalog/0002_gol_doi'");
    $goluri->exec("UPDATE _platforma_stare SET valoare = 'alt-cod' WHERE cheie = 'amprenta'");
    $partiala = $gardaCu($v2, $goluri);
    $goluri->exec("UPDATE _platforma_migrari SET rezultat = 'aplicata' WHERE id = 'catalog/0002_gol_doi'");
    $verifica('… cu amprenta altui cod și 0002 „partiala”: in_urma; din nou „aplicata”: la_zi',
        $partiala === Garda::IN_URMA && $gardaCu($v2, $goluri) === Garda::LA_ZI, $partiala);
    Garda::folosesteDosarul(null);

    // Oprit după fiecare operație, apoi reluat.
    $esecuriOprire = [];
    for ($k = 1; $k <= $operatiiJucarii; $k++) {
        $o = $bazaNoua($sock, $b('oprire'));
        [$cod, $out, $err] = $ruleaza($sock, $b('oprire'), $jucarii, ['--aplica'], ['MIGRARI_TEST_OPRESTE_DUPA' => $k]);
        if ($cod !== 1 || !str_contains($err, 'oprire de test după ' . $k)) {
            $esecuriOprire[] = 'k=' . $k . ': oprirea a dat ' . $cod . ' ' . trim($err . $out);
            continue;
        }
        [$cod, $out, $err] = $ruleaza($sock, $b('oprire'), $jucarii, ['--aplica']);
        $r = $randuri($o);
        $ramase = array_filter($r, static fn (array $x): bool => !in_array($x['rezultat'], ['aplicata', 'detectata'], true));
        $contoare = $o->query('SELECT COUNT(*) AS n, SUM(contor = 1) AS unu FROM jucarii')->fetch();
        if ($cod !== 0 || $ramase !== [] || count($r) !== 3 || $amprenta($o) !== $json || (int) $contoare['n'] !== 9 || (int) $contoare['unu'] !== 9
            || array_sum(array_map('intval', array_column($r, 'operatii'))) !== $operatiiJucarii) {
            $esecuriOprire[] = 'k=' . $k . ': reluarea ' . $cod . ', ' . json_encode($r) . ', contoare ' . json_encode($contoare) . ' ' . $out . $err;
        }
    }
    $verifica('oprit (ca un proces omorât) după fiecare din cele ' . $operatiiJucarii . ' operații, apoi reluat: 0, nimic „in_curs”, amprenta '
        . 'aceeași, fiecare jucărie numărată exact o dată, operațiile numărate o dată', $esecuriOprire === [], implode("\n", $esecuriOprire));

    // Oprit chiar înainte de commit-ul unei bucăți (sau al instrucțiunii de date) și chiar după el. Înainte:
    // serverul anulează tot, bucata se reface. După: bucata, cursorul și numărătoarea au rămas împreună, deci
    // nu se reface. Dacă cursorul sau numărătoarea ar ieși din tranzacție, aici o jucărie ar ajunge la
    // contor 2 sau o operație n-ar fi numărată.
    $jucariiNumarate = static fn (PDO $o, string $conditie): int => (int) $o->query('SELECT COUNT(*) FROM jucarii WHERE ' . $conditie)->fetchColumn();
    $puncte = [
        ['date_in_tranzactie', 1, static fn (PDO $o, array $r): bool => $jucariiNumarate($o, '1') === 0],
        ['date_dupa_commit', 1, static fn (PDO $o, array $r): bool => $jucariiNumarate($o, '1') === 9
            && (int) ($r['catalog/0003_contor']['operatii'] ?? -1) === 1],
    ];
    for ($k = 1; $k <= 5; $k++) {
        $puncte[] = ['bucata_in_tranzactie', $k, static fn (PDO $o, array $r): bool => $jucariiNumarate($o, 'contor = 1') === 2 * ($k - 1)
            && $jucariiNumarate($o, 'contor > 1') === 0];
        $puncte[] = ['bucata_dupa_commit', $k, static fn (PDO $o, array $r): bool => $jucariiNumarate($o, 'contor = 1') === min(2 * $k, 9)
            && str_ends_with((string) ($r['catalog/0003_contor']['cursor_json'] ?? ''), ':' . min(2 * $k, 9) . '}')
            && (int) ($r['catalog/0003_contor']['operatii'] ?? -1) === 1 + $k];
    }
    $esecuriPuncte = [];
    foreach ($puncte as [$punct, $k, $dupaOprire]) {
        $o = $bazaNoua($sock, $b('oprire'));
        [$cod, $out, $err] = $ruleaza($sock, $b('oprire'), $jucarii, ['--aplica'], ['MIGRARI_TEST_OPRESTE_IN' => $punct . ':' . $k]);
        if ($cod !== 1 || !str_contains($err, 'oprire de test la ' . $punct . ' #' . $k) || !$dupaOprire($o, $randuri($o))) {
            $esecuriPuncte[] = $punct . ':' . $k . ': oprirea a dat ' . $cod . ', ' . json_encode($randuri($o)) . ', contor ' . json_encode($o->query('SELECT contor, COUNT(*) n FROM jucarii GROUP BY contor')->fetchAll()) . ' ' . trim($err . $out);
            continue;
        }
        [$cod, $out, $err] = $ruleaza($sock, $b('oprire'), $jucarii, ['--aplica']);
        $r = $randuri($o);
        $ramase = array_filter($r, static fn (array $x): bool => !in_array($x['rezultat'], ['aplicata', 'detectata'], true));
        if ($cod !== 0 || $ramase !== [] || count($r) !== 3 || $amprenta($o) !== $json || $jucariiNumarate($o, '1') !== 9
            || $jucariiNumarate($o, 'contor = 1') !== 9 || array_sum(array_map('intval', array_column($r, 'operatii'))) !== $operatiiJucarii) {
            $esecuriPuncte[] = $punct . ':' . $k . ': reluarea ' . $cod . ', ' . json_encode($r) . ', contor ' . json_encode($o->query('SELECT contor, COUNT(*) n FROM jucarii GROUP BY contor')->fetchAll()) . ' ' . $out . $err;
        }
    }
    $verifica('oprit chiar înainte și chiar după commit-ul instrucțiunii de date și al fiecăreia din cele 5 bucăți (' . count($puncte) . ' opriri), '
        . 'apoi reluat: înainte nu rămâne nimic, după rămân bucata, cursorul și numărătoarea; la final fiecare jucărie numărată o dată, '
        . $operatiiJucarii . ' operații', $esecuriPuncte === [], implode("\n", $esecuriPuncte));

    // Bugetul de timp.
    $buget = $lucru . '/buget-' . $cheiServer;
    $scrieMigrare($buget, 'nucleu/0001_tabel', <<<'PHP'
        $s->tabel('rafturi', 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, contor INT NOT NULL DEFAULT 0');
        $s->date('40 de rafturi', 'INSERT INTO rafturi (contor) SELECT 0 FROM (SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3
            UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8) a,
            (SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5) b
            WHERE NOT EXISTS (SELECT 1 FROM rafturi)');
PHP);
    $scrieMigrare($buget, 'nucleu/0002_contor', <<<'PHP'
        $s->peBucati('rafturi', 'id', 2, static function (PDO $db, int $de, int $pana): int {
            $stmt = $db->prepare('UPDATE rafturi SET contor = contor + 1 WHERE id > ? AND id <= ?');
            $stmt->execute([$de, $pana]);
            return $stmt->rowCount();
        });
PHP, 'date');
    $g = $bazaNoua($sock, $b('buget'));
    $ruleaza($sock, $b('buget'), $buget, ['--aplica']);
    file_put_contents($buget . '/asteptat.json', $ruleaza($sock, $b('buget'), $buget, ['--amprenta'])[1]);
    $g = $bazaNoua($sock, $b('buget'));
    [$cod, $out] = $ruleaza($sock, $b('buget'), $buget, ['--aplica', '--buget=0']);
    $verifica('--buget=0: 13 înainte de prima migrare, niciuna începută', $cod === 13 && $randuri($g) === [], $out);
    [$cod, $out] = $ruleaza($sock, $b('buget'), $buget, ['--aplica', '--buget=0.3'], ['MIGRARI_TEST_PAUZA_BUCATI_MS' => '40']);
    $r = $randuri($g);
    $cursor = json_decode((string) ($r['nucleu/0002_contor']['cursor_json'] ?? ''), true);
    $pana = is_array($cursor) ? (int) reset($cursor) : -1;
    $facute = (int) $g->query('SELECT COUNT(*) FROM rafturi WHERE contor = 1')->fetchColumn();
    $panaLaCursor = (int) $g->query('SELECT COUNT(*) FROM rafturi WHERE id <= ' . $pana)->fetchColumn();
    $verifica('--buget=0.3 cu 20 de bucăți: 13, migrarea „partiala”, cursorul exact la ultima bucată făcută',
        $cod === 13 && ($r['nucleu/0002_contor']['rezultat'] ?? '') === 'partiala' && $facute === $panaLaCursor
        && $facute > 0 && $facute < 40, $out . json_encode($r) . ' făcute ' . $facute . ', până la cursor ' . $panaLaCursor);
    [$cod, $out] = $ruleaza($sock, $b('buget'), $buget, ['--aplica']);
    $verifica('… rularea următoare continuă de la cursor: 0, fiecare raft numărat o dată',
        $cod === 0 && (int) $g->query('SELECT COUNT(*) FROM rafturi WHERE contor = 1')->fetchColumn() === 40
        && ($randuri($g)['nucleu/0002_contor']['rezultat'] ?? '') === 'aplicata', $out);

    // 12: o migrare aplicată s-a schimbat sau a dispărut.
    $copie = $lucru . '/jucarii-copie-' . $cheiServer;
    $copiaza = static function (string $din, string $in) use (&$copiaza): void {
        @mkdir($in, 0777, true);
        foreach (array_diff(scandir($din) ?: [], ['.', '..']) as $intrare) {
            is_dir($din . '/' . $intrare) ? $copiaza($din . '/' . $intrare, $in . '/' . $intrare) : copy($din . '/' . $intrare, $in . '/' . $intrare);
        }
    };
    $copiaza($jucarii, $copie);
    file_put_contents($copie . '/nucleu/0001_jucarii.php', "\n// o schimbare\n", FILE_APPEND);
    [$codPlan, $outPlan] = $ruleaza($sock, $b('a'), $copie, ['--plan']);
    [$cod, $out] = $ruleaza($sock, $b('a'), $copie, ['--aplica']);
    $verifica('o migrare aplicată, schimbată în cod: --plan și --aplica ies cu 12, cu id-ul ei',
        $codPlan === 12 && $cod === 12 && str_contains($out, 'nucleu/0001_jucarii s-a schimbat'), $outPlan . $out);
    copy($jucarii . '/nucleu/0001_jucarii.php', $copie . '/nucleu/0001_jucarii.php');
    unlink($copie . '/catalog/0002_culori.php');
    [$cod, $out] = $ruleaza($sock, $b('a'), $copie, ['--plan']);
    file_put_contents($copie . '/migrari.lock', json_encode(['migrari' => new stdClass(), 'retrase' => ['catalog/0002_culori' => 'test']]));
    [$cod2, $out2] = $ruleaza($sock, $b('a'), $copie, ['--plan']);
    $verifica('o migrare aplicată, ștearsă din cod: 12; trecută la „retrase” în migrari.lock: 0',
        $cod === 12 && str_contains($out, 'nu mai există în cod') && $cod2 === 0, $out . $out2);

    // 11: blocante și contract, fără voie.
    $blocante = $lucru . '/blocante-' . $cheiServer;
    $scrieMigrare($blocante, 'nucleu/0001_baza', <<<'PHP'
        $s->tabel('stoc', 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, cantitate INT NOT NULL DEFAULT 0, veche INT NULL');
PHP);
    $scrieMigrare($blocante, 'nucleu/0002_tip', <<<'PHP'
        $s->modificaColoana('stoc', 'cantitate', 'BIGINT NOT NULL DEFAULT 0');
PHP);
    $scrieMigrare($blocante, 'nucleu/0003_declarata', <<<'PHP'
        $s->coloana('stoc', 'nota', 'VARCHAR(20) NULL');
PHP, 'schema', true);
    $scrieMigrare($blocante, 'nucleu/0004_sterge', <<<'PHP'
        $s->stergeColoana('stoc', 'veche');
PHP, 'contract');
    $genereazaAsteptat($sock, $blocante, ['--permite-blocante', '--permite-contract']);
    $bl = $bazaNoua($sock, $b('bl'));
    [$codPlan, $outPlan] = $ruleaza($sock, $b('bl'), $blocante, ['--plan']);
    [$cod, $out] = $ruleaza($sock, $b('bl'), $blocante, ['--aplica']);
    $verifica('--plan cu migrări blocante și de tip contract: 11, fiecare arătată (BLOCANTĂ, CONTRACT)',
        $codPlan === 11 && substr_count($outPlan, 'BLOCANTĂ') === 2 && str_contains($outPlan, 'CONTRACT') && str_contains($outPlan, 'copie'), $outPlan);
    $verifica('--aplica fără --permite-…: 11 și baza neatinsă (nici tabelele de urmărire, nici prima migrare, neblocantă)',
        $cod === 11 && $tabele($bl) === [] && str_contains($out, 'baza n-a fost atinsă'), $out);
    [$cod, $out] = $ruleaza($sock, $b('bl'), $blocante, ['--aplica', '--permite-blocante']);
    $verifica('doar cu --permite-blocante: tot 11 (rămâne cea de tip contract), baza neatinsă', $cod === 11 && $tabele($bl) === [], $out);
    [$cod, $out, $err] = $ruleaza($sock, $b('bl'), $blocante, ['--aplica', '--permite-blocante', '--permite-contract']);
    $st = Amprenta::citeste($bl, Server::detecteaza($bl), ['stoc'])['tabele']['stoc'] ?? ['coloane' => []];
    $verifica('cu ambele: aplicate (tipul schimbat prin copie, coloana nouă, cea veche ștearsă)',
        $cod === 0 && ($st['coloane']['cantitate']['tip'] ?? '') === 'bigint' && isset($st['coloane']['nota'])
        && !isset($st['coloane']['veche']) && count(array_filter($randuri($bl), static fn ($x) => $x['rezultat'] === 'aplicata')) === 4, $out . $err);
    // WP-14: o migrare care îngustează o coloană cu date mai lungi. Pe un server non-strict ALTER-ul ar tăia datele cu
    // un avertisment (1265) și ar merge mai departe; runner-ul rulează migrările cu STRICT_TRANS_TABLES, deci pe fiecare
    // server se oprește cu 1, iar datele rămân întregi. Aici în proces (Runner direct, ca install.php): după rulare
    // sesiunea are din nou modul serverului.
    $ingust = $lucru . '/ingusteaza-' . $cheiServer;
    $scrieMigrare($ingust, 'nucleu/0001_baza', <<<'PHP'
        $s->tabel('note', 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, text VARCHAR(40) NOT NULL DEFAULT \'\'');
PHP);
    $scrieMigrare($ingust, 'nucleu/0002_nota', <<<'PHP'
        $s->date('o notă lungă', "INSERT INTO note (text) VALUES ('douazeci-si-cinci-de-caractere')");
PHP, 'date');
    $scrieMigrare($ingust, 'nucleu/0003_ingusteaza', <<<'PHP'
        $s->modificaColoana('note', 'text', "VARCHAR(10) NOT NULL DEFAULT ''");
PHP);
    $in = $bazaNoua($sock, $b('in'));
    $modInainte = (string) $in->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    $runnerIngust = new \App\Support\Migrari\Runner($in, Server::detecteaza($in), $registru($ingust),
        ['permite_blocante' => true, 'jurnal' => $lucru . '/jurnal-ingust.log', 'pauze' => [0.1]]);
    $rIngust = $runnerIngust->aplica();
    $textIngust = (string) $in->query('SELECT text FROM note')->fetchColumn();
    $lungime = (int) ($in->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'note' AND COLUMN_NAME = 'text'")->fetchColumn());
    $verifica('o migrare care îngustează o coloană cu date mai lungi (' . (preg_match('/STRICT_(TRANS|ALL)_TABLES/', $modInainte) === 1
        ? 'server strict' : 'server NON-STRICT') . '): oprită cu 1, datele întregi, coloana neschimbată',
        ($rIngust['cod'] ?? null) === Eroare::MIGRARE && $textIngust === 'douazeci-si-cinci-de-caractere' && $lungime === 40
        && (array_column($randuri($in), 'rezultat', 'id')['nucleu/0002_nota'] ?? '') === 'aplicata',
        'cod ' . json_encode($rIngust['cod'] ?? null) . ', text de ' . strlen($textIngust) . ' caractere, coloana ' . $lungime . ': ' . (string) ($rIngust['mesaj'] ?? ''));
    $verifica('… după rulare sesiunea are din nou sql_mode-ul de dinainte (al serverului)',
        (string) $in->query('SELECT @@SESSION.sql_mode')->fetchColumn() === $modInainte);
    $schemaContract = $lucru . '/sterge-in-schema-' . $cheiServer;
    $scrieMigrare($schemaContract, 'nucleu/0001_x', "        \$s->tabel('de_sters', 'id INT');\n        \$s->stergeTabel('de_sters');");
    $bazaNoua($sock, $b('sc'));
    [$cod, $out] = $ruleaza($sock, $b('sc'), $schemaContract, ['--plan']);
    $verifica('o ștergere într-o migrare de tip „schema”: refuzată deja în plan (1)', $cod === 1 && str_contains($out, 'contract'), $out);

    // 15: parametrii site-ului.
    $param = $lucru . '/parametru-' . $cheiServer;
    $scrieMigrare($param, 'nucleu/0001_procesator', <<<'PHP'
        $procesator = $s->parametruSite('test.procesator');
        $s->tabel('linkuri', 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, procesator VARCHAR(20) NOT NULL');
        $s->date('procesatorul linkurilor existente', 'INSERT INTO linkuri (procesator) SELECT ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM linkuri)', [$procesator]);
PHP);
    @mkdir($lucru . '/site-cu-parametri', 0777, true);
    file_put_contents($lucru . '/site-cu-parametri/migrari.json', json_encode(['test.procesator' => 'stripe']));
    $genereazaAsteptat($sock, $param, [], ['SITE_DATE_DOSAR' => $lucru . '/site-cu-parametri']);
    $pb = $bazaNoua($sock, $b('param'));
    [$codPlan, $outPlan] = $ruleaza($sock, $b('param'), $param, ['--plan']);
    [$cod, $out] = $ruleaza($sock, $b('param'), $param, ['--aplica']);
    $verifica('parametrul site-ului lipsește: --plan și --aplica 15, cu numele cheii, baza neatinsă',
        $codPlan === 15 && $cod === 15 && str_contains($out, 'test.procesator') && $tabele($pb) === [], $outPlan . $out);
    [$cod, $out] = $ruleaza($sock, $b('param'), $param, ['--aplica'], ['SITE_DATE_DOSAR' => $lucru . '/site-cu-parametri']);
    $verifica('cu migrari.json în dosarul site-ului: aplicată, cu valoarea lui',
        $cod === 0 && $pb->query('SELECT procesator FROM linkuri')->fetchColumn() === 'stripe', $out);

    // 3: lacătele. Un runner refuzat nu atinge starea: nici cel care găsește lacătul ținut, nici codul mai vechi decât baza (12).
    $jucariiVechi = $lucru . '/jucarii-vechi-' . $cheiServer;
    @mkdir($jucariiVechi . '/nucleu', 0777, true);
    copy($jucarii . '/nucleu/0001_jucarii.php', $jucariiVechi . '/nucleu/0001_jucarii.php');
    $a->exec("UPDATE _platforma_stare SET valoare = 'santinela' WHERE cheie = 'ultima_rulare'");
    $stareInainte = $stareBaza($a);
    $tinut = $conectare($sock, $b('a'));
    $tinut->query("SELECT GET_LOCK(CONCAT('platforma_migrari:', MD5(DATABASE())), 0)")->fetchColumn();
    [$cod, $out] = $ruleaza($sock, $b('a'), $jucarii, ['--aplica']);
    [$codVechi, $outVechi] = $ruleaza($sock, $b('a'), $jucariiVechi, ['--aplica']);
    [$codStare, $outStare] = $ruleaza($sock, $b('a'), $jucarii, ['--stare']);
    $stareCuLacat = $stareBaza($a);
    $tinut->query("SELECT RELEASE_LOCK(CONCAT('platforma_migrari:', MD5(DATABASE())))");
    [$codVechi2, $outVechi2] = $ruleaza($sock, $b('a'), $jucariiVechi, ['--aplica']);
    $stareDupaVechi = $stareBaza($a);
    [$cod2] = $ruleaza($sock, $b('a'), $jucarii, ['--aplica']);
    $verifica('alt runner ține lacătul: --aplica 3 (--stare arată „runner activ”); eliberat: 0',
        $cod === 3 && str_contains($out, 'alt runner') && str_contains($outStare, 'runner activ acum: da') && $cod2 === 0, $out . $outStare);
    $verifica('un runner refuzat nu atinge _platforma_stare: lacătul ținut (3, și cu cod mai vechi), cod mai vechi decât baza (12); '
        . 'ultima_ord rămâne 3, amprenta și ultima rulare la fel; apoi o rulare bună o scrie (codul 0)',
        $codVechi === 3 && $codVechi2 === 12 && $stareCuLacat === $stareInainte && $stareDupaVechi === $stareInainte
        && ($stareInainte['ultima_ord'] ?? '') === '3' && $codUltimaRulare($a) === 0,
        $outVechi . $outVechi2 . json_encode([$stareInainte, $stareCuLacat, $stareDupaVechi, $stareBaza($a)]));
    $fan = $lucru . '/fan-' . $cheiServer;
    $scrieMigrare($fan, 'livrare_fan/0001_strazi', <<<'PHP'
        $s->lacatFan('strazi', 1);
        $s->tabel('strazi_test', 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');
PHP);
    $genereazaAsteptat($sock, $fan);
    $fb = $bazaNoua($sock, $b('fan'));
    $tinutFan = $conectare($sock, $b('fan'));
    $tinutFan->query("SELECT GET_LOCK(CONCAT('fan_nomenclator:', MD5(DATABASE()), ':', 'strazi'), 0)")->fetchColumn();
    [$cod, $out] = $ruleaza($sock, $b('fan'), $fan, ['--aplica']);
    $tinutFan->query("SELECT RELEASE_LOCK(CONCAT('fan_nomenclator:', MD5(DATABASE()), ':', 'strazi'))");
    [$cod2, $out2] = $ruleaza($sock, $b('fan'), $fan, ['--aplica']);
    $liber = (int) $tinutFan->query("SELECT GET_LOCK(CONCAT('fan_nomenclator:', MD5(DATABASE()), ':', 'strazi'), 0)")->fetchColumn();
    $tinutFan->query("SELECT RELEASE_LOCK(CONCAT('fan_nomenclator:', MD5(DATABASE()), ':', 'strazi'))");
    $verifica('sincronizarea FAN ține lacătul „strazi”: 3 (migrarea rămâne „in_curs”); eliberat: aplicată, iar lacătul e dat înapoi',
        $cod === 3 && str_contains($out, 'sincronizarea FAN') && $cod2 === 0 && $liber === 1
        && ($randuri($fb)['livrare_fan/0001_strazi']['rezultat'] ?? '') === 'aplicata', $out . $out2);

    // Un tabel ocupat: reîncercări, apoi renunță (1) și se reia.
    $ocupat = $lucru . '/ocupat-' . $cheiServer;
    $scrieMigrare($ocupat, 'nucleu/0001_tabel', "        \$s->tabel('ocupat', 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');");
    $scrieMigrare($ocupat, 'nucleu/0002_coloana', "        \$s->coloana('ocupat', 'nota', 'VARCHAR(20) NULL');");
    $genereazaAsteptat($sock, $ocupat);
    $oc = $bazaNoua($sock, $b('ocupat'));
    $ruleaza($sock, $b('ocupat'), $ocupat, ['--aplica', '--pana-la=nucleu/0001_tabel']);
    $blocheaza = $conectare($sock, $b('ocupat'));
    $blocheaza->exec("INSERT INTO ocupat VALUES (1)");
    [$codP, $outP] = $ruleaza($sock, $b('ocupat'), $ocupat, ['--plan']);
    [, $jsonP] = $ruleaza($sock, $b('ocupat'), $ocupat, ['--plan', '--json']);
    $opP = json_decode($jsonP, true)['migrari'][0]['operatii'][0] ?? [];
    $verifica('--plan pe un tabel care există: ALTER-ul are mărimea tabelului (rânduri și octeți, după information_schema; și în --json)',
        $codP === 10 && preg_match('/coloana ocupat\.nota \(~\d+ rânduri, \d+ KB\)/', $outP) === 1
        && is_int($opP['randuri'] ?? null) && is_int($opP['octeti'] ?? null) && $opP['octeti'] > 0, $outP . $jsonP);
    $blocheaza->beginTransaction();
    $blocheaza->query('SELECT * FROM ocupat')->fetchAll();
    [$comanda, $env] = $comandaRunner($sock, $b('ocupat'), $ocupat, ['--aplica', '--asteptare-lacat=1'],
        ['MIGRARI_TEST_PAUZE' => '0.5,0.5,0.5,0.5,0.5,0.5', 'MIGRARI_TEST_JURNAL' => $lucru . '/jurnal-ocupat-' . $cheiServer . '.log']);
    $proces = proc_open($comanda, [1 => ['file', $lucru . '/ocupat.out', 'w'], 2 => ['file', $lucru . '/ocupat.err', 'w']], $pipe, $radacina, $env);
    usleep(2_200_000);
    $blocheaza->commit();
    $cod = proc_close($proces);
    $jurnal = (string) @file_get_contents($lucru . '/jurnal-ocupat-' . $cheiServer . '.log');
    $verifica('tabel ocupat (o tranzacție lungă îl citește): ALTER-ul renunță după 1 s, reîncearcă, trece când tabelul se eliberează',
        $cod === 0 && str_contains($jurnal, 'ocupat, reîncerc') && ($randuri($oc)['nucleu/0002_coloana']['rezultat'] ?? '') === 'aplicata',
        $cod . ' ' . @file_get_contents($lucru . '/ocupat.out') . @file_get_contents($lucru . '/ocupat.err') . $jurnal);
    $oc->exec('ALTER TABLE ocupat DROP COLUMN nota');
    $oc->exec("DELETE FROM _platforma_migrari WHERE id = 'nucleu/0002_coloana'");
    $blocheaza->beginTransaction();
    $blocheaza->query('SELECT * FROM ocupat')->fetchAll();
    [$cod, $out] = $ruleaza($sock, $b('ocupat'), $ocupat, ['--aplica', '--asteptare-lacat=1'], ['MIGRARI_TEST_PAUZE' => '0.2']);
    $rand = $randuri($oc)['nucleu/0002_coloana']['rezultat'] ?? '';
    $blocheaza->commit();
    [$cod2] = $ruleaza($sock, $b('ocupat'), $ocupat, ['--aplica']);
    $verifica('… ocupat tot timpul: 1, migrarea „in_curs”; rulată din nou după: aplicată',
        $cod === 1 && str_contains($out, 'a rămas ocupat') && $rand === 'in_curs' && $cod2 === 0, $out . ' ' . $rand);

    // Un tabel #sql-* rămas de la un ALTER omorât (servere fără DDL atomic): planul îl arată, amprenta nu-l vede.
    $a->exec('CREATE TABLE `#sql-proba_1` (id INT) ENGINE=InnoDB');
    [$cod, $out] = $ruleaza($sock, $b('a'), $jucarii, ['--plan']);
    [$codA, $jsonA] = $ruleaza($sock, $b('a'), $jucarii, ['--amprenta']);
    $a->exec('DROP TABLE `#sql-proba_1`');
    $verifica('un tabel #sql-* rămas de la un ALTER întrerupt: --plan îl arată (avertisment, tot 0), amprenta nu-l vede',
        $cod === 0 && str_contains($out, 'tabelul #sql-proba_1 a rămas') && $codA === 0 && $jsonA === $json, $out);

    // 14: verificarea.
    $a->exec('ALTER TABLE culori DROP COLUMN cod');
    $a->exec('ALTER TABLE jucarii DROP INDEX idx_jucarii_culoare');
    [$cod, $out] = $ruleaza($sock, $b('a'), $jucarii, ['--verifica']);
    $verifica('--verifica după ce lipsesc o coloană și un index: 14, cu numele lor (doar nume)',
        $cod === 14 && str_contains($out, 'LIPSĂ    coloana culori.cod') && str_contains($out, 'LIPSĂ    indexul jucarii.idx_jucarii_culoare'), $out);

    // 2: configurarea.
    [$c1, $o1] = $ruleaza($sock, '', $jucarii, ['--plan']);
    [$c2, $o2] = $ruleaza('/nu/exista.sock', $b('a'), $jucarii, ['--plan']);
    [$c3, $o3, $e3] = $ruleaza($sock, $b('a'), $jucarii, ['--plan'], ['DB_USERNAME' => 'cms_mig_nimeni', 'DB_PASSWORD' => 'parola-secreta-test']);
    [$c4] = $ruleaza($sock, $b('a'), $jucarii, ['--plan', '--altceva']);
    [$c5] = $ruleaza($sock, $b('a'), $jucarii, ['--plan', '--pana-la=nucleu/9999_nu']);
    [$c6] = $ruleaza($sock, $b('a'), $lucru . '/registru-' . substr(md5('număr folosit de două ori'), 0, 8), ['--plan']);
    [$c7] = $ruleaza($sock, $b('a'), $jucarii, ['--plan', '--site=demo'], ['SITE_SLUG' => 'alt']);
    $verifica('configurarea: fără DB_DATABASE, socket greșit, acces refuzat, argument necunoscut, --pana-la necunoscut, registru greșit, '
        . '--site altul decât SITE_SLUG: 2 de fiecare dată',
        [$c1, $c2, $c3, $c4, $c5, $c6, $c7] === [2, 2, 2, 2, 2, 2, 2] && str_contains($o3, 'acces refuzat'), json_encode([$c1, $c2, $c3, $c4, $c5, $c6, $c7]) . $o1 . $o2 . $o3);
    $verifica('nicio parolă și niciun utilizator în ieșire', !str_contains($o3 . $e3, 'parola-secreta-test') && !str_contains($o3 . $e3, 'cms_mig_nimeni'));

    // Nicio valoare din rânduri în ieșire.
    $personale = $lucru . '/personale-' . $cheiServer;
    $scrieMigrare($personale, 'nucleu/0001_clienti', <<<'PHP'
        $s->tabel('clienti', 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190) NOT NULL');
PHP);
    $scrieMigrare($personale, 'nucleu/0002_unic', "        \$s->unic('clienti', 'uniq_clienti_email', ['email']);");
    $pe = $bazaNoua($sock, $b('pers'));
    $ruleaza($sock, $b('pers'), $personale, ['--aplica', '--pana-la=nucleu/0001_clienti']);
    $pe->exec("INSERT INTO clienti (email) VALUES ('maria.exemplu@exemplu.ro'), ('maria.exemplu@exemplu.ro'), ('ion.exemplu@exemplu.ro')");
    [$cod, $out, $err] = $ruleaza($sock, $b('pers'), $personale, ['--aplica']);
    $verifica('index unic peste emailuri repetate: 1 deja în simulare, cu numărul lor, fără niciun email în ieșire',
        $cod === 1 && str_contains($out, '1 valori') && !str_contains($out . $err, '@exemplu.ro')
        && !isset($randuri($pe)['nucleu/0002_unic']), $out . $err);
    $scrieMigrare($personale, 'nucleu/0002_unic', "        \$s->date('o dublură', \"INSERT INTO clienti (email) SELECT email FROM clienti WHERE id = 1\");\n"
        . "        \$s->date('a doua', \"UPDATE clienti SET email = 'x' WHERE id = 0\");");
    $pe->exec('DELETE FROM clienti WHERE id = 2');
    $pe->exec('ALTER TABLE clienti ADD UNIQUE INDEX uniq_clienti_email (email)');
    [$cod, $out, $err] = $ruleaza($sock, $b('pers'), $personale, ['--aplica']);
    $verifica('o eroare SQL cu o valoare în mesaj (Duplicate entry …): 1, mesajul fără valoare',
        $cod === 1 && str_contains($out, '1062') && !str_contains($out . $err, '@exemplu.ro'), $out . $err);
    // Valori cu ghilimele și \ în ele: serverele nu le dublează în mesaj, iar valoarea poate conține chiar „' for key '”.
    $pe->prepare('UPDATE clienti SET email = ? WHERE id = 3')->execute(['o\'brien\\ion\' for key \'x.exemplu@exemplu.ro']);
    $cazuriCiudate = [
        'date(), INSERT, 1062' => ["        \$s->date('o dublură', 'INSERT INTO clienti (email) SELECT email FROM clienti WHERE id = 3');", '1062', 'uniq_clienti_email'],
        'peBucati(), UPDATE, 1062' => ["        \$s->peBucati('clienti', 'id', 10, static function (PDO \$db, int \$de, int \$pana): int {\n"
            . "            \$valoare = \$db->query('SELECT email FROM clienti WHERE id = 3')->fetchColumn();\n"
            . "            \$stmt = \$db->prepare('UPDATE clienti SET email = ? WHERE id = 1');\n"
            . "            \$stmt->execute([\$valoare]);\n            return \$stmt->rowCount();\n        });", '1062', 'uniq_clienti_email'],
        'date(), text într-un INT, 1366' => ["        \$s->tabel('numere', 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, n INT NULL');\n"
            . "        \$s->date('numere', 'INSERT INTO numere (n) SELECT email FROM clienti WHERE id = 3');", '1366', ''],
    ];
    $esecuriCiudate = [];
    foreach ($cazuriCiudate as $eticheta => [$sus, $errno, $index]) {
        $scrieMigrare($personale, 'nucleu/0002_unic', $sus);
        [$cod, $out, $err] = $ruleaza($sock, $b('pers'), $personale, ['--aplica']);
        [$codJ, $outJ, $errJ] = $ruleaza($sock, $b('pers'), $personale, ['--aplica', '--json']);
        $tot = $out . $err . $outJ . $errJ;
        if ($cod !== 1 || $codJ !== 1 || !str_contains($out, 'eroare SQL ' . $errno) || ($index !== '' && !str_contains($out, $index))
            || preg_match('/brien|exemplu|\\\\ion/', $tot) === 1) {
            $esecuriCiudate[] = $eticheta . ': ' . $cod . '/' . $codJ . ' ' . $out . $err;
        }
    }
    $verifica('valori cu ghilimele și \\ (și „\' for key \'”) în mesajul serverului — date() și peBucati() cu 1062, 1366: 1, cu errno '
        . 'și numele indexului, fără nicio bucată din valoare, nici în --json', $esecuriCiudate === [], implode("\n", $esecuriCiudate));

    // Pașii site-ului.
    $siteR = $lucru . '/site-' . $cheiServer;
    $scrieMigrare($siteR, 'nucleu/0001_note', "        \$s->tabel('note', 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, text VARCHAR(20) NOT NULL');");
    $scrieMigrare($siteR, 'site/demo/nota-demo', "        \$s->date('nota site-ului', \"INSERT INTO note (text) SELECT 'demo' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM note)\");", 'site');
    $genereazaAsteptat($sock, $siteR);
    $sb = $bazaNoua($sock, $b('site'));
    [$cod, $out] = $ruleaza($sock, $b('site'), $siteR, ['--aplica']);
    $faraSite = array_keys($randuri($sb));
    [$cod2, $out2] = $ruleaza($sock, $b('site'), $siteR, ['--aplica', '--site=demo']);
    $r = $randuri($sb);
    [$cod3] = $ruleaza($sock, $b('site'), $siteR, ['--plan'], ['SITE_SLUG' => 'demo']);
    $verifica('pașii site-ului: doar cu --site=demo (sau SITE_SLUG), după migrări, cu ord 0; ultima_ord nu-i numără',
        $cod === 0 && $faraSite === ['nucleu/0001_note'] && $cod2 === 0 && ($r['site/demo/nota-demo']['rezultat'] ?? '') === 'aplicata'
        && (int) $r['site/demo/nota-demo']['ord'] === 0 && $sb->query('SELECT text FROM note')->fetchColumn() === 'demo' && $cod3 === 0
        && $sb->query("SELECT valoare FROM _platforma_stare WHERE cheie = 'ultima_ord'")->fetchColumn() === '1', $out . $out2 . json_encode($r));

    // ---------------------------------------------------------------- I
    require __DIR__ . '/migrari/test-inghetare-runner.php';
}

// =====================================================================
// T. Aceleași amprente pe ambele servere
// =====================================================================
$scrie('');
$scrie('T. ' . implode(', ', array_keys($servere)));
if (!$cuRunner) {
    $scrie('  SĂRIT runner-ul (--migrari)');
} elseif (count($servere) < 2) {
    $scrie('  SĂRIT un singur server (' . ($doarMariaDb ? '--doar-mariadb' : 'MySQL 8 nu merge') . ($faraNestrict ? ', --fara-nestrict' : '') . '): rezultatul e parțial');
} else {
    foreach ($amprentePeServer as $ce => $amprente) {
        $valori = array_values($amprente);
        $verifica('aceeași amprentă pe fiecare server (' . count($servere) . '): ' . $ce,
            count($valori) === count($servere) && count(array_unique($valori)) === 1,
            count($valori) === count($servere) ? implode("\n---\n", $valori) : 'lipsește un server');
    }
}
if ($cuRunner && isset($servere['MySQL 8'])) {
    // O conexiune MariaDB nouă primește id-ul de obiect al uneia MySQL 8 tocmai eliberate (PHP refolosește
    // id-urile): Amprenta nu are voie să-și amintească de la cea veche că serverul are STATISTICS.EXPRESSION.
    $pdoMy = $conectare($servere['MySQL 8']);
    $idVechi = spl_object_id($pdoMy);
    Amprenta::citeste($pdoMy, Server::detecteaza($pdoMy));
    unset($pdoMy);
    $pdoMaria = $conectare($servere['MariaDB']);
    $acelasiId = spl_object_id($pdoMaria) === $idVechi;
    $ex = $aruncat(static fn () => Amprenta::citeste($pdoMaria, Server::detecteaza($pdoMaria)));
    $verifica('o conexiune MariaDB cu id-ul de obiect al uneia MySQL 8 eliberate: Amprenta citește fără eroare',
        $acelasiId && $ex === null, ($acelasiId ? '' : 'id-ul nu s-a refolosit (testul n-ar dovedi nimic); ') . ($ex?->getMessage() ?? ''));
    unset($pdoMaria);
} elseif ($cuRunner) {
    $scrie('  SĂRIT MySQL 8 (' . ($doarMariaDb ? '--doar-mariadb' : 'nu merge') . '): rezultatul e parțial');
}

// =====================================================================
// S. Sesiunile din linia de comandă
// =====================================================================
$scrie('');
$scrie('S. Linia de comandă fără sesiune (bootstrap.php)');
$dosarSesiuni = $radacina . '/storage/sessions';
$numara = static fn (): int => count(glob($dosarSesiuni . '/sess_*') ?: []);
$inainte = $numara();
$bazaS = 'cms_mig_t_gol';
for ($i = 0; $i < 3; $i++) {
    $ruleaza($sockMaria, $bazaS, null, ['--stare']);
}
[$comanda, $env] = $comandaRunner($sockMaria, $bazaS, null, []);
$proces = proc_open([PHP_BINARY, '-d', 'pdo_mysql.default_socket=' . $sockMaria, $radacina . '/scripts/diagnostic.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, $radacina, $env);
stream_get_contents($pipe[1]);
stream_get_contents($pipe[2]);
proc_close($proces);
$verifica('3 rulări ale lui migreaza.php și una a lui diagnostic.php: numărul fișierelor de sesiune neschimbat (' . $inainte . ')',
    $numara() === $inainte, 'înainte ' . $inainte . ', după ' . $numara());
$cod = 'require ' . var_export($radacina . '/bootstrap.php', true) . '; echo session_status(), "|", json_encode($_SESSION), "|", session_id();';
$fara = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($cod));
$cu = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('define("ANDAXI_CU_SESIUNE", true); ' . $cod));
$idCu = explode('|', trim($cu))[2] ?? '';
$verifica('în linia de comandă: fără sesiune PHP, $_SESSION un array gol; cu ANDAXI_CU_SESIUNE, sesiune pornită',
    trim($fara) === PHP_SESSION_NONE . '|[]|' && str_starts_with(trim($cu), PHP_SESSION_ACTIVE . '|') && $idCu !== '', $fara . ' / ' . $cu);
if ($idCu !== '' && preg_match('/^[A-Za-z0-9,-]+$/', $idCu) === 1) {
    @unlink($dosarSesiuni . '/sess_' . $idCu);
}

// =====================================================================
// M. Migrările adevărate: T1–T11 (migrari.md §4; WP-04)
// =====================================================================
$saritLive = [];
if ($cuMigrari) {
    require __DIR__ . '/migrari/test-baza.php';
}

$scrie('');
$total = 'test-migrari: ' . $trecute . ' trecute, ' . $esuate . ' eșuate'
    . ' (' . implode(', ', array_keys($servere)) . (isset($servere['MySQL 8'], $servere['MariaDB nestrict']) ? '' : ': parțial') . ')' . ', PHP ' . PHP_VERSION
    . (!$cuMigrari ? ', doar runner-ul' : '') . (!$cuRunner ? ', fără D și R' : '')
    . ($saritLive !== [] ? '; T2-live sărit pentru ' . implode(', ', array_keys($saritLive)) . ' (sonda live n-a venit încă)' : '') . '.';
$scrie($total);
exit($esuate === 0 && (isset($servere['MySQL 8']) || $doarMariaDb) && (isset($servere['MariaDB nestrict']) || $faraNestrict) ? 0 : 1);
