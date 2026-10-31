<?php

declare(strict_types=1);

/**
 * Secțiunea M din tools/dev/test-migrari.php: migrările adevărate (database/migrari) pe schemele celor
 * cinci coduri, testele T1–T11 din migrari.md §4 (WP-04). Capul lui test-migrari.php spune ce face
 * fiecare test. Nu se rulează singur: îl încarcă test-migrari.php (fără --runner), după secțiunea S, și
 * folosește ce a pregătit acela ($servere, $bazaNoua, $ruleaza, $comandaRunner, $conectare, $verifica,
 * $scrie, $lucru, $proprietari …). Doar date inventate; bazele sunt cms_mig_t_*.
 */

if (PHP_SAPI !== 'cli' || !isset($verifica, $servere, $bazaNoua, $ruleaza, $comandaRunner)) {
    fwrite(STDERR, "tools/dev/migrari/test-baza.php se rulează din tools/dev/test-migrari.php\n");
    exit(2);
}

use App\Support\Migrari\Amprenta;
use App\Support\Migrari\Registru;
use App\Support\Migrari\Server;

$scrie('');
$scrie('M. Migrările adevărate (database/migrari): T1–T11');

$dosarReal = $radacina . '/database/migrari';
$dosarVechi = $radacina . '/tools/dev/migrari/vechi';
$dosarPrivat = $dosarVechi . '/privat';
$genereazaFixture = $radacina . '/tools/dev/migrari/genereaza-fixture.php';
$excluderi = require $radacina . '/tools/dev/migrari/excluderi.php';
$registruReal = new Registru($dosarReal, $proprietari);
$migrariReale = $registruReal->migrari();
$asteptatReal = $registruReal->asteptat() ?? ['format' => Amprenta::FORMAT, 'tabele' => []];
$asteptatJson = Amprenta::json($asteptatReal);
$idPeOrd = array_column($migrariReale, 'id', 'ord');
$ultimaOrd = $registruReal->ultimaOrd();
$penultimaId = (string) ($migrariReale[count($migrariReale) - 2]['id'] ?? '');
$finalB = (string) ($idPeOrd[19] ?? '');
$grupa = static fn (int $ord): string => $ord <= 19 ? 'B' : ($ord <= 24 ? 'C' : 'după');
$numarB = count(array_filter($migrariReale, static fn (array $m): bool => $m['ord'] <= 19));
$numarC = count(array_filter($migrariReale, static fn (array $m): bool => $m['ord'] >= 20 && $m['ord'] <= 24));
$descrie = static fn (array $a): string => count($a['tabele']) . ' tabele, '
    . array_sum(array_map(static fn (array $t): int => count($t['coloane']), $a['tabele'])) . ' coloane, '
    . array_sum(array_map(static fn (array $t): int => count($t['indecsi']), $a['tabele'])) . ' indecși, '
    . array_sum(array_map(static fn (array $t): int => count($t['fk']), $a['tabele'])) . ' chei străine';

// Parametrul procesatorului linkurilor vechi (BV, GR; link_plata/0009): o valoare de test, într-un dosar al site-ului.
@mkdir($lucru . '/site-procesator', 0777, true);
file_put_contents($lucru . '/site-procesator/migrari.json', json_encode(['link_plata.procesator_linkuri_existente' => 'stripe']));
$cuParametru = ['SITE_DATE_DOSAR' => $lucru . '/site-procesator'];
$faraParametru = ['SITE_DATE_DOSAR' => $lucru . '/site-gol'];

/** scripts/migreaza.php pe migrările adevărate: [cod, raportul JSON (sau ['mesaj' => ieșirea]), stderr]. */
$mig = static function (string $sock, string $baza, array $argumente, array $mediu = [], ?string $dosar = null)
    use ($ruleaza, $dosarReal, $cuParametru): array {
    [$cod, $out, $err] = $ruleaza($sock, $baza, $dosar ?? $dosarReal, $argumente, array_merge($cuParametru, $mediu));
    $raport = in_array('--json', $argumente, true) ? json_decode($out, true) : null;
    return [$cod, is_array($raport) ? $raport : ['mesaj' => trim($out . ' ' . $err)], $err, $out];
};

/** Instrucțiunile unui fișier SQL, una câte una (un exec cu mai multe ar ascunde o eroare de după prima). */
$instructiuni = static function (string $sql): array {
    $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
    return array_values(array_filter(array_map('trim', preg_split('/;\s*$/m', $sql) ?: []), static fn (string $x): bool => $x !== ''));
};
/** O bază nouă din fișierele SQL date (în ordine) și din instrucțiunile de după. */
$incarca = static function (string $sock, string $baza, array $fisiere, array $dupa = []) use ($bazaNoua, $instructiuni): PDO {
    $db = $bazaNoua($sock, $baza);
    foreach ($fisiere as $fisier) {
        foreach ($instructiuni((string) file_get_contents($fisier)) as $i) {
            $db->exec($i);
        }
    }
    foreach ($dupa as $i) {
        $db->exec($i);
    }
    return $db;
};
/** Rândurile și CHECKSUM-ul fiecărui tabel (fără _platforma_*): tabel → „rânduri:sumă”. */
$instantaneu = static function (PDO $db): array {
    $r = [];
    $tabeleBaza = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
        ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tabeleBaza as $t) {
        if (str_starts_with((string) $t, '_platforma_')) {
            continue;
        }
        $suma = $db->query('CHECKSUM TABLE `' . $t . '`')->fetch(PDO::FETCH_NUM)[1] ?? '';
        $r[$t] = $db->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn() . ':' . $suma;
    }
    return $r;
};
$schimbate = static fn (array $a, array $b): array => array_keys(array_diff_assoc($b, $a) + array_diff_assoc($a, $b));
$amp = static fn (PDO $db): array => Amprenta::citeste($db, Server::detecteaza($db));
/** Operațiile cu efect ale unui raport, pe grupe: grupa → „clasa fel” → câte (--plan: planul; --aplica: ce s-a făcut). */
$operatii = static function (array $raport) use ($grupa): array {
    $n = [];
    foreach ((array) ($raport['migrari'] ?? []) as $m) {
        foreach ((array) ($m['operatii'] ?? []) as $o) {
            if (($o['clasa'] ?? '') === 'fara_efect') {
                continue;
            }
            $g = $grupa((int) $m['ord']);
            $k = $o['clasa'] . ' ' . $o['fel'];
            $n[$g][$k] = ($n[$g][$k] ?? 0) + 1;
        }
    }
    foreach ($n as &$x) {
        ksort($x);
    }
    return $n;
};
$stari = static function (array $raport) use ($grupa): array {
    $s = [];
    foreach ((array) ($raport['migrari'] ?? []) as $m) {
        $s[$grupa((int) $m['ord'])][$m['stare']] = ($s[$grupa((int) $m['ord'])][$m['stare']] ?? 0) + 1;
    }
    return $s;
};
/**
 * Tabelele făcute de migrările de după baseline (grupul „după”, de exemplu magazin/0032, WP-R): pe o bază făcută de un
 * cod care nu le avea, ele sunt singura schimbare de schemă pe care o aduce --aplica, deci „schema neschimbată” se
 * compară fără ele. O coloană nouă pe un tabel existent o ia $coloaneNoiDupaBaseline (mai jos); orice altă schimbare a
 * unui tabel existent (un index, o coloană schimbată) nu intră nicăieri (atunci testul pică).
 */
$tabeleNoiDupaBaseline = static function (array $raport) use ($grupa): array {
    $t = [];
    foreach ((array) ($raport['migrari'] ?? []) as $m) {
        if ($grupa((int) $m['ord']) !== 'după') {
            continue;
        }
        foreach ((array) ($m['operatii'] ?? []) as $o) {
            if (($o['clasa'] ?? '') === 'creare' && ($o['fel'] ?? '') === 'tabel') {
                $t[] = (string) $o['obiect'];
            }
        }
    }
    sort($t);
    return array_values(array_unique($t));
};
$faraTabele = static function (array $amprenta, array $tabele): array {
    foreach ($tabele as $tabel) {
        unset($amprenta['tabele'][$tabel]);
    }
    return $amprenta;
};
/**
 * Coloanele adăugate de migrările de după baseline (WP-19: catalog/0026 și 0027 pe tabelele catalogului; WP-20, WP-21 la fel)
 * la tabele care existau: „tabel.coloană”. Pe o bază făcută de un cod care nu le avea, ele sunt (cu tabelele noi) singura
 * schimbare de schemă pe care o aduce --aplica.
 */
// Coloanele (sau, cu $fel = 'index', indecșii) puse de migrările de după baseline, ca „tabel.nume”.
$coloaneNoiDupaBaseline = static function (array $raport, string $fel = 'coloana') use ($grupa): array {
    $c = [];
    foreach ((array) ($raport['migrari'] ?? []) as $m) {
        if ($grupa((int) $m['ord']) !== 'după') {
            continue;
        }
        foreach ((array) ($m['operatii'] ?? []) as $o) {
            if (($o['fel'] ?? '') === $fel && ($o['clasa'] ?? '') !== 'fara_efect') {
                $c[] = (string) $o['obiect'];
            }
        }
    }
    sort($c);
    return array_values(array_unique($c));
};
// Scoate din amprentă coloanele date (sau, cu $cheie = 'indecsi', indecșii dați), „tabel.nume”.
$faraColoane = static function (array $amprenta, array $coloane, string $cheie = 'coloane'): array {
    foreach ($coloane as $tabelColoana) {
        [$tabel, $coloana] = explode('.', $tabelColoana, 2) + [1 => ''];
        unset($amprenta['tabele'][$tabel][$cheie][$coloana]);
    }
    return $amprenta;
};
$operatiiFacute = static fn (array $raport): int => array_sum(array_map(static fn (array $m): int => (int) ($m['operatii_acum'] ?? 0), (array) ($raport['migrari'] ?? [])));
$liniiObiecte = static function (string $fisier): ?array {
    if (!is_file($fisier)) {
        return null;
    }
    $linii = array_values(array_filter(array_map('trim', file($fisier, FILE_IGNORE_NEW_LINES) ?: []),
        static fn (string $l): bool => $l !== '' && !str_starts_with($l, '#')));
    sort($linii);
    return $linii;
};
$sortat = static function (array $x): array {
    sort($x);
    return array_values($x);
};
$listaScurta = static fn (array $x, int $cate = 12): string => implode(', ', array_slice($x, 0, $cate)) . (count($x) > $cate ? ' … (' . count($x) . ')' : '');

/**
 * Tabelele pe care le schimbă o migrare, după operațiile ei: din obiectul operațiilor de schemă și ale bucăților,
 * iar pentru instrucțiunile de date (obiectul lor e descrierea) din lista de mai jos. O migrare de date nouă se
 * trece aici, cu tabelele ei: altfel T4 și T10 pică (nu știu ce are voie să schimbe).
 */
$tabeleDate = [
    'plata_bt/0008_baza_bt' => ['settings'],
    'link_plata/0009_baza_link_plata' => ['settings'],
    'nucleu/0020_roluri_admini' => ['admins'],
    'catalog/0021_coloane_vechi_catalog' => ['products', 'product_extra_fields', 'product_templates', 'product_extra_field_values'],
    'cupoane/0022_coloane_vechi_cupoane' => ['coupons'],
    'recenzii/0023_recenzii_vechi' => ['product_reviews'],
    'blog/0024_legaturi_categorii' => ['blog_post_categories'],
    // După baseline (WP-R): versiunea schemei avertismentelor, ca la BT (0008).
    'magazin/0032_avertismente_produse' => ['settings'],
    // WP-19 (C8): cheia linkului de ciornă a produselor existente, completată pe bucăți.
    'catalog/0026_vizibilitate_ordine' => ['products'],
    // WP-R (newsletterul Bioscem 739f58c): pragul cheii vechi de semnare (newsletter_cheie_limita), scris o dată, la adoptare.
    'newsletter/0040_newsletter_dubla_confirmare' => ['settings'],
];
$tabeleAtinse = static function (array $raport) use ($tabeleDate): array {
    $t = [];
    $nedeclarate = [];
    foreach ((array) ($raport['migrari'] ?? []) as $m) {
        foreach ((array) ($m['operatii'] ?? []) as $o) {
            if (($o['clasa'] ?? '') === 'fara_efect' || ($o['fel'] ?? '') === 'lacat_fan') {
                continue;
            }
            if ($o['fel'] === 'date') {
                if (!isset($tabeleDate[$m['id']])) {
                    $nedeclarate[] = $m['id'];
                }
                foreach ($tabeleDate[$m['id']] ?? [] as $x) {
                    $t[$x] = true;
                }
                continue;
            }
            $t[(string) preg_replace('/[ .].*$/', '', (string) $o['obiect'])] = true;
        }
    }
    return [array_keys($t), array_values(array_unique($nedeclarate))];
};

/** Paralel: comenzile [cmd, env] date, câte $cate odată; ieșirile în fișiere (fără pipe-uri care s-ar umple). @return list<array{0:int,1:string,2:string}> */
$inParalel = static function (array $comenzi, int $cate = 4) use ($radacina, $lucru): array {
    $rezultate = [];
    $active = [];
    $urmatoare = 0;
    $total = count($comenzi);
    while ($urmatoare < $total || $active !== []) {
        while ($urmatoare < $total && count($active) < $cate) {
            [$cmd, $env] = $comenzi[$urmatoare];
            $out = $lucru . '/paralel-' . $urmatoare . '.out';
            $err = $lucru . '/paralel-' . $urmatoare . '.err';
            $p = proc_open($cmd, [1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $_, $radacina, $env);
            $active[$urmatoare] = [$p, $out, $err];
            $urmatoare++;
        }
        foreach ($active as $i => [$p, $out, $err]) {
            $st = proc_get_status($p);
            if (!$st['running']) {
                $cod = (int) $st['exitcode'];
                proc_close($p);
                $rezultate[$i] = [$cod, (string) @file_get_contents($out), (string) @file_get_contents($err)];
                @unlink($out);
                @unlink($err);
                unset($active[$i]);
            }
        }
        if ($active !== []) {
            usleep(15_000);
        }
    }
    ksort($rezultate);
    return array_values($rezultate);
};

/** Copia codului pentru funcțiile ensure* (procesul-copil al lui genereaza-fixture.php): acest repo (arborele de lucru) sau Bioscem. */
$copiaza = static function (string $din, string $in) use (&$copiaza): void {
    if (is_link($din)) {
        return;
    }
    if (is_file($din)) {
        copy($din, $in);
        return;
    }
    @mkdir($in, 0777, true);
    foreach (array_diff(scandir($din) ?: [], ['.', '..']) as $x) {
        $copiaza($din . '/' . $x, $in . '/' . $x);
    }
};
$codCms = $lucru . '/cod-cms';
foreach (['src', 'scripts', 'database', 'config'] as $parte) {
    $copiaza($radacina . '/' . $parte, $codCms . '/' . $parte);
}
@mkdir($codCms . '/storage/logs', 0777, true);
$repoBs = (string) (getenv('CMS_DEV_REPO_BS') ?: dirname($radacina) . '/Nou-site-bioscem');
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($radacina . '/tools/dev/de-portat.php') . ' --revizuit=bioscem 2>&1', $iesireRev, $codRev);
$revizuitBs = trim(implode('', $iesireRev));
$codBs = $lucru . '/cod-bs';
@mkdir($codBs, 0777, true);
exec('git -C ' . escapeshellarg($repoBs) . ' archive ' . escapeshellarg($revizuitBs) . ' | tar -x -C ' . escapeshellarg($codBs) . ' 2>&1', $iesireTar, $codTar);
@mkdir($codBs . '/storage/logs', 0777, true);
$verifica('codul Bioscem pentru T10 (CMS_DEV_REPO_BS la „ultimul commit revizuit” ' . $revizuitBs . ')',
    $codRev === 0 && $codTar === 0 && is_file($codBs . '/scripts/install.php'), implode("\n", array_merge($iesireRev, $iesireTar)));
/** O comandă a procesului-copil: funcțiile ensure* ale codului din $dosarCod, o trecere, pe baza dată. */
$comandaHelperi = static function (string $sock, string $baza, string $dosarCod) use ($genereazaFixture, $utilizator, $parola): array {
    $env = getenv();
    foreach (['http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'all_proxy'] as $proxy) {
        $env[$proxy] = 'http://127.0.0.1:9';
    }
    $env['DB_USERNAME'] = $utilizator;
    $env['DB_PASSWORD'] = $parola;
    return [[PHP_BINARY, '-d', 'memory_limit=512M', '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'error_reporting=E_ALL',
        '-d', 'allow_url_fopen=0', $genereazaFixture, '--copil', '--faza=helperi', '--dosar=' . $dosarCod, '--sock=' . $sock, '--baza=' . $baza], $env];
};
/**
 * Rezultatul unui proces-copil al funcțiilor ensure* ([cod, stdout, stderr]): [bun, JSON-ul lui]. Bun = cod 0, JSON
 * citit și nicio eroare: copilul prinde excepția fiecărei funcții în „erori” și iese tot cu 0, iar pe un site o
 * excepție a unei funcții ensure* e o cerere a codului vechi căzută (500).
 */
$rezultatHelperi = static function (array $r): array {
    $json = json_decode($r[1], true);
    if (!is_array($json)) {
        return [false, ['erori' => ['copilul a ieșit cu ' . $r[0] . ', fără JSON: ' . mb_substr(trim($r[1] . ' ' . $r[2]), 0, 300)]]];
    }
    $erori = array_key_exists('erori', $json) ? (array) $json['erori'] : ['fără „erori” în JSON'];
    if ($r[0] !== 0) {
        $erori[] = 'copilul a ieșit cu ' . $r[0] . ': ' . mb_substr(trim($r[2]), 0, 300);
    }
    $json['erori'] = $erori;
    return [$erori === [], $json];
};
$helperi = static function (string $sock, string $baza, string $dosarCod) use ($comandaHelperi, $inParalel, $rezultatHelperi): array {
    [$r] = $inParalel([$comandaHelperi($sock, $baza, $dosarCod)], 1);
    return $rezultatHelperi($r);
};
/**
 * Un script al proiectului prin tools/dev/php.sh, pe o bază de test (seed.php, date-demo.php, install.php), cu licența
 * „toate” dintr-un dosar al testului (CMS_DEV_LICENTA_DOSAR): ca procesul-copil al funcțiilor ensure* (toate
 * pluginurile pornite) și oricare ar fi licența locală din storage/licenta, pe care n-o atinge.
 */
$dosarLicenta = $lucru . '/licenta-toate';
@mkdir($dosarLicenta, 0777, true);
$phpSh = static function (string $sock, string $baza, array $script) use ($radacina, $dosarLicenta): array {
    $env = getenv();
    $env['CMS_DEV_DB'] = $baza;
    $env['CMS_DEV_SOCK'] = $sock;
    $env['CMS_DEV_LICENTA_DOSAR'] = $dosarLicenta;
    $p = proc_open(array_merge([$radacina . '/tools/dev/php.sh'], $script), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, $radacina, $env);
    $out = (string) stream_get_contents($pipe[1]);
    $err = (string) stream_get_contents($pipe[2]);
    fclose($pipe[1]);
    fclose($pipe[2]);
    return [proc_close($p), $out . $err];
};
$licentaLocala = static function () use ($radacina): array {
    $r = [];
    foreach (glob($radacina . '/storage/licenta/*.json') ?: [] as $f) {
        $r[basename($f)] = sha1((string) file_get_contents($f));
    }
    return $r;
};
$licentaLocalaInainte = $licentaLocala();
[$codLic, $outLic] = $phpSh($sockMaria, 'cms_mig_t_licenta', ['tools/dev/licenta.php', 'toate']);
$verifica('T4, T10: licența „toate” într-un dosar al testului (' . trim($outLic) . '), nu în storage/licenta',
    $codLic === 0 && is_file($dosarLicenta . '/licenta.json') && $licentaLocala() === $licentaLocalaInainte, $outLic);

// Ce trebuie să facă baseline-ul (grupul B) pe schema fiecărui cod: numerele din migrari.md §2.2 (BS = CMS:
// nimic; BV și GR: 3 tabele, 24 de coloane, valoarea implicită a procesatorului; VT: 8 tabele, 57 de coloane,
// un index), plus cele două versiuni de schemă scrise în setări (0008, 0009), pe care doar codul Bioscem le
// scrie singur (cms și bs primesc aici rândurile pe care le-ar fi scris codul lor la prima plată BT / link).
$asteptatB = [
    'cms' => [],
    'bs' => [],
    'bv' => ['creare tabel' => 3, 'date date' => 2, 'instant coloana' => 24, 'instant implicit' => 1],
    'vt' => ['creare tabel' => 8, 'date date' => 2, 'inplace index' => 1, 'instant coloana' => 57],
    'gr' => ['creare tabel' => 3, 'date date' => 2, 'instant coloana' => 24, 'instant implicit' => 1],
];
/** Ce are voie baseline-ul să scoată de pe o bază veche: indexul unic de dinainte de row_key (pasul vechi din livrare_fan/0005). */
$scoaseVoit = ['indexul fan_streets.uniq_fan_streets'];
/**
 * Ce scriu funcțiile ensure* pe o bază nouă, T10: date, nu schemă, pe care migrările nu le scriu. Tabel (în ordine) →
 * [citirea, rândurile]. Ale codului comun: nimic, pe orice bază la zi (garda R1, WP-05). Ale Bioscem (codul vechi, fără
 * gardă), pe o bază făcută doar de migrări: pagina /acorduri-gdpr (la noi, pasul de activare al lui acorduri_gdpr),
 * cele patru categorii de blog ale Bioscem (la noi, seed-ul neutru al instalării: Noutăți și Evenimente) și lista
 * implicită de newsletter (la noi, NewsletterService::defaultListId, la nevoie); pe o bază făcută de scripts/install.php,
 * doar lista de newsletter: pagina și categoriile le-a pus deja instalarea.
 */
$randuriHelperiPeBazaNoua = [
    'blog_categories' => ['SELECT slug FROM blog_categories ORDER BY slug', ['evenimente', 'info-medici', 'info-pacienti', 'noutati']],
    'newsletter_lists' => ['SELECT is_default FROM newsletter_lists ORDER BY id', ['1']],
    'pages' => ['SELECT slug FROM pages ORDER BY slug', ['acorduri-gdpr']],
];
$randuriBioscemPeInstalareNoua = [
    'newsletter_lists' => ['SELECT is_default FROM newsletter_lists ORDER BY id', ['1']],
];
$versiuniBs = ["INSERT INTO settings (`key`, `value`) VALUES ('bt_ipay_schema_version', '3'), ('payment_links_schema_version', '2')"];
$cuProcesatorLipsa = ['bv', 'gr'];

// T1/T2 (fără server pe care să-l aleg): genereaza-fixture.php --verifica (G-baza) și --verifica-fixture-uri.
$scrie('T1, T2: migrările de bază față de schema codului (genereaza-fixture.php)');
$ruleazaGf = static function (array $argumente, array $mediu = []) use ($genereazaFixture, $radacina, $utilizator, $parola, $sockMaria): array {
    $env = array_merge(getenv(), $mediu);
    $env['DB_USERNAME'] = $utilizator;
    $env['DB_PASSWORD'] = $parola;
    $p = proc_open(array_merge([PHP_BINARY, '-d', 'pdo_mysql.default_socket=' . $sockMaria, '-d', 'display_errors=stderr', $genereazaFixture], $argumente),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, $radacina, $env);
    $out = (string) stream_get_contents($pipe[1]);
    $err = (string) stream_get_contents($pipe[2]);
    fclose($pipe[1]);
    fclose($pipe[2]);
    return [proc_close($p), $out . $err];
};
[$codGf, $outGf] = $ruleazaGf(array_merge(['--verifica'], $doarMariaDb ? ['--server=mariadb'] : []));
$verifica('T1: genereaza-fixture.php --verifica (G-baza): baseline-ul pe o bază goală = schema codului minus excluderile, '
    . 'pe baza făcută de cod 0 operații în grupul B, grupul C = ce lăsau funcțiile codului; ' . trim((string) strrchr(trim($outGf), "\n") ?: $outGf),
    $codGf === 0, implode("\n", array_filter(explode("\n", $outGf), static fn (string $l): bool => str_contains($l, 'EȘEC') || str_contains($l, 'EROARE'))));
[$codFx, $outFx] = $ruleazaGf(['--verifica-fixture-uri']);
$verifica('T2: fixture-urile din git (tools/dev/migrari/vechi/<cod>.sql, extra-<cod>.txt) = schema pe care o face acum codul fiecăruia, '
    . 'din „ultimul commit revizuit” (genereaza-fixture.php --verifica-fixture-uri)',
    $codFx === 0, implode("\n", array_filter(explode("\n", $outFx), static fn (string $l): bool => str_contains($l, 'EȘEC') || str_contains($l, 'EROARE'))));
// Aceleași fișiere cu clonele în dosare cu alt nume (CMS_DEV_REPO_* spre o clonă oarecare, aici o legătură simbolică):
// sursa scrisă în capul fixture-ului și în extra-<cod>.txt e numele repo-ului, nu al dosarului clonei (WP-07, deschis (b);
// revizuirea WP-R din 21 oct). Legăturile stau în $lucru; $sterge le șterge fără să intre în clone.
$cuAltNume = $lucru . '/clone-cu-alt-nume';
@mkdir($cuAltNume, 0777, true);
$mediuAltNume = [];
foreach (['CMS_DEV_REPO_BS' => 'Nou-site-bioscem', 'CMS_DEV_REPO_BV' => 'Mutare-Site-Biovitality', 'CMS_DEV_REPO_VT' => 'vertij-tinitus-refacut',
    'CMS_DEV_REPO_GR' => 'site-nou-grafoanaytis.ro'] as $variabilaClona => $numeImplicit) {
    $legatura = $cuAltNume . '/alta-clona-' . strtolower(substr($variabilaClona, -2));
    if (is_link($legatura)) {
        unlink($legatura);
    }
    symlink((string) (getenv($variabilaClona) ?: dirname($radacina) . '/' . $numeImplicit), $legatura);
    $mediuAltNume[$variabilaClona] = $legatura;
}
[$codAlt, $outAlt] = $ruleazaGf(['--verifica-fixture-uri'], $mediuAltNume);
$verifica('T2: aceleași fixture-uri cu clonele în dosare cu alt nume (CMS_DEV_REPO_* spre ' . basename($cuAltNume) . '/alta-clona-*): '
    . 'sursa din fișiere e numele repo-ului, nu al dosarului',
    $codAlt === 0, implode("\n", array_filter(explode("\n", $outAlt), static fn (string $l): bool => str_contains($l, 'EȘEC') || str_contains($l, 'EROARE'))));
$extraCms = $liniiObiecte($dosarVechi . '/extra-cms.txt') ?? [];
$verifica('T2: extra-cms.txt = exact excluderile instalării noi (tools/dev/migrari/excluderi.php)',
    $extraCms === $sortat(array_keys($excluderi['excluse'])), implode(', ', $extraCms));

/**
 * T2 pe o schemă veche deja încărcată ($db): planul, aplicarea, verificarea, ce s-a adăugat, a doua aplicare.
 * $asteptatePlan: grupa B → „clasa fel” → câte (null = nu se verifică, la sonda live); $extra: obiectele în plus
 * așteptate după baseline. Întoarce [amprenta după, operațiile făcute, mesajul pentru T9].
 */
$baseline = static function (string $sock, string $baza, PDO $db, string $eticheta, ?array $asteptatePlan, array $extra, bool $cuProcesator)
    use ($mig, $verifica, $amp, $asteptatReal, $asteptatJson, $operatii, $stari, $operatiiFacute, $instantaneu, $sortat, $listaScurta,
        $faraParametru, $tabele, $numarB, $numarC, $scoaseVoit): array {
    $P = $amp($db);
    $fata = Amprenta::compara($asteptatReal, $P);
    // O bază de dinainte de fan_streets.row_key: pasul vechi al lui 0005 (docs/MIGRARI.md, „Străzile FAN de dinainte de row_key”).
    $faraRowKey = isset($P['tabele']['fan_streets']) && !isset($P['tabele']['fan_streets']['coloane']['row_key']);
    if ($cuProcesator) {
        $inainte = $tabele($db);
        [$c15, $r15] = $mig($sock, $baza, ['--plan', '--json'], $faraParametru);
        [$c15a] = $mig($sock, $baza, ['--aplica', '--json'], $faraParametru);
        $verifica($eticheta . ': linkuri vechi fără procesator și fără parametrul site-ului: --plan și --aplica 15, baza neatinsă',
            $c15 === 15 && $c15a === 15 && $tabele($db) === $inainte && str_contains((string) ($r15['mesaj'] ?? ''), 'link_plata.procesator_linkuri_existente'),
            $c15 . '/' . $c15a . ' ' . ($r15['mesaj'] ?? ''));
    }
    [$cp, $rp] = $mig($sock, $baza, ['--plan', '--json']);
    $opPlan = $operatii($rp);
    $copii = array_filter(array_merge(...array_values($opPlan ?: [[]])), static fn (int $n, string $k): bool => str_starts_with($k, 'copie '), ARRAY_FILTER_USE_BOTH);
    $verifica($eticheta . ': --plan iese cu 10' . ($asteptatePlan !== null ? ', grupul B are exact ' . json_encode($asteptatePlan, JSON_UNESCAPED_UNICODE) : '')
        . ', nicio copiere de tabel (blocantă)',
        $cp === 10 && ($asteptatePlan === null || ($opPlan['B'] ?? []) === $asteptatePlan) && $copii === [],
        'cod ' . $cp . ', planul ' . json_encode($opPlan, JSON_UNESCAPED_UNICODE) . ' ' . mb_substr((string) ($rp['mesaj'] ?? ''), 0, 300));
    [$ca, $ra] = $mig($sock, $baza, ['--aplica', '--json']);
    $opAplica = $operatii($ra);
    $s = $stari($ra);
    $facute = $operatiiFacute($ra);
    // Planul simulează și instrucțiunile de date, fără să știe câte rânduri vor schimba: se compară doar schema, pe obiecte.
    // Pe o bază de dinainte de row_key, indexul unic uniq_fan_streets_row vine abia după bucăți: planul nu-l arată (un avertisment).
    $schemaB = static function (array $raport): array {
        $o = [];
        foreach ((array) ($raport['migrari'] ?? []) as $m) {
            foreach ((array) ($m['operatii'] ?? []) as $op) {
                if ((int) $m['ord'] <= 19 && !in_array($op['clasa'] ?? '', ['fara_efect', 'date'], true)) {
                    $o[] = $op['clasa'] . ' ' . $op['fel'] . ' ' . $op['obiect'];
                }
            }
        }
        sort($o);
        return $o;
    };
    $doarLaAplicare = array_values(array_diff($schemaB($ra), $schemaB($rp)));
    $doarInPlan = array_values(array_diff($schemaB($rp), $schemaB($ra)));
    $indexDupaBucati = $faraRowKey && count($doarLaAplicare) === 1 && str_ends_with($doarLaAplicare[0], ' fan_streets.uniq_fan_streets_row');
    $verifica($eticheta . ': --aplica iese cu 0; în grupul B, schema exact cum arăta planul' . ($asteptatePlan !== null ? ' (și datele)' : '')
        . ($faraRowKey ? ' (plus uniq_fan_streets_row, după bucățile lui row_key)' : '') . '; grupul C nimic (niciun rând vechi); toate „aplicata” sau „detectata”',
        $ca === 0 && $doarInPlan === [] && ($doarLaAplicare === [] || $indexDupaBucati)
        && ($asteptatePlan === null || ($opAplica['B'] ?? []) === ($opPlan['B'] ?? []))
        && !isset($opAplica['C'])
        && array_sum($s['B'] ?? []) === $numarB && array_sum($s['C'] ?? []) === $numarC
        && array_diff(array_keys(array_merge(...array_values($s ?: [[]]))), ['aplicata', 'detectata']) === [],
        'cod ' . $ca . ', făcute ' . json_encode($opAplica, JSON_UNESCAPED_UNICODE) . ', stări ' . json_encode($s) . ', doar la aplicare: ' . implode(', ', $doarLaAplicare)
        . ', doar în plan: ' . implode(', ', $doarInPlan) . ' ' . mb_substr((string) ($ra['mesaj'] ?? ''), 0, 600));
    [$cv, $rv] = $mig($sock, $baza, ['--verifica', '--json']);
    $inPlus = $sortat((array) ($rv['verificare']['in_plus'] ?? []));
    $av = $sortat((array) ($rv['verificare']['avertismente'] ?? []));
    $extra = array_values(array_diff($extra, $scoaseVoit));
    // Acolo pasul vechi al lui 0005 adaugă row_key cu DEFAULT '', cum o adăuga codul, deci după baseline coloana
    // diferă de cea a unei instalări noi (un avertisment în plus, voit).
    $avAsteptate = $fata['avertismente'];
    $avNoi = array_values(array_diff($av, $avAsteptate));
    $avPermiseNoi = $faraRowKey && count($avNoi) === 1 && str_starts_with($avNoi[0], "coloana fan_streets.row_key: char(40) NOT NULL DEFAULT ''");
    $verifica($eticheta . ': --verifica iese cu 0; în plus exact ' . count($extra) . ' obiecte (extra), avertismentele = diferențele de definiție de dinainte ('
        . count($fata['avertismente']) . ($faraRowKey ? ', plus fan_streets.row_key cu DEFAULT \'\' de la pasul vechi al lui 0005' : '') . ')',
        $cv === 0 && (array) ($rv['verificare']['lipsa'] ?? ['?']) === [] && $inPlus === $sortat($extra)
        && array_diff($avAsteptate, $av) === [] && ($avNoi === [] || $avPermiseNoi),
        'cod ' . $cv . "\nîn plus neașteptat: " . $listaScurta(array_diff($inPlus, $extra)) . "\nîn extra, dar nu în plus: " . $listaScurta(array_diff($extra, $inPlus))
        . "\navertismente: " . implode(' | ', array_slice($av, 0, 6)) . "\nde dinainte: " . implode(' | ', array_slice($fata['avertismente'], 0, 6)));
    $Q = $amp($db);
    $dif = Amprenta::compara($P, $Q);
    $verifica($eticheta . ': baseline-ul n-a șters (afară de indexul vechi uniq_fan_streets, dacă era: îl înlocuiește uniq_fan_streets_row) '
        . 'și n-a schimbat nimic din ce exista; a adăugat exact ce lipsea (' . count($fata['lipsa']) . ' obiecte)',
        array_diff($dif['lipsa'], $scoaseVoit) === [] && $dif['avertismente'] === [] && $sortat($dif['in_plus']) === $sortat($fata['lipsa']),
        'șterse: ' . $listaScurta($dif['lipsa']) . "\nschimbate: " . implode(' | ', array_slice($dif['avertismente'], 0, 6))
        . "\nadăugate în plus: " . $listaScurta(array_diff($dif['in_plus'], $fata['lipsa'])) . "\nlipsă și neadăugate: " . $listaScurta(array_diff($fata['lipsa'], $dif['in_plus'])));
    $dupaPrima = $instantaneu($db);
    [$c2, $r2] = $mig($sock, $baza, ['--aplica', '--json']);
    [$c3] = $mig($sock, $baza, ['--plan']);
    $verifica($eticheta . ': T3 — a doua aplicare: nicio migrare rulată, aceleași rânduri, aceeași amprentă; --plan 0',
        $c2 === 0 && (array) ($r2['migrari'] ?? ['?']) === [] && $c3 === 0 && $instantaneu($db) === $dupaPrima && Amprenta::json($amp($db)) === Amprenta::json($Q),
        'cod ' . $c2 . '/' . $c3 . ' ' . mb_substr((string) ($r2['mesaj'] ?? ''), 0, 300));
    return [$Q, $facute];
};

/**
 * T2-live pe dosarul dat (tools/dev/migrari/vechi/privat/ sau dosarul unei sonde simulate): <cod>-live.sql,
 * extra-<cod>.txt, <cod>-fata-de-asteptat.txt (din-sonda.php). Întoarce false dacă fișierul schemei lipsește.
 */
$t2Live = static function (string $sock, string $numeServer, string $dosar, string $cod, string $eticheta)
    use ($incarca, $baseline, $liniiObiecte, $verifica): bool {
    $sql = $dosar . '/' . $cod . '-live.sql';
    if (!is_file($sql)) {
        return false;
    }
    $extra = $liniiObiecte($dosar . '/extra-' . $cod . '.txt');
    $verifica($eticheta . ': din-sonda.php a scris și extra-' . $cod . '.txt și ' . $cod . '-fata-de-asteptat.txt',
        $extra !== null && is_file($dosar . '/' . $cod . '-fata-de-asteptat.txt'), 'rulează din nou din-sonda.php pe fișierul sondei');
    $baza = 'cms_mig_t_live_' . $cod;
    $eroare = null;
    $db = null;
    try {
        $db = $incarca($sock, $baza, [$sql]);
    } catch (Throwable $e) {
        $eroare = $e->getMessage();
    }
    $verifica($eticheta . ': schema refăcută din sondă se încarcă pe ' . $numeServer, $eroare === null, (string) $eroare);
    if ($db === null || $extra === null) {
        return true;
    }
    // Linkuri de plată fără coloana procesator (codul BV și GR): fără parametrul site-ului, 15; cu el (valoarea de test), baseline-ul.
    $faraProcesator = (int) $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'order_payment_links'")->fetchColumn() > 0 && (int) $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_payment_links' AND COLUMN_NAME = 'procesator'")->fetchColumn() === 0;
    $baseline($sock, $baza, $db, $eticheta, null, $extra, $faraProcesator);
    return true;
};

// Datele bazei vechi (cms.sql + variante.sql), după migrări sau după funcțiile codului.
$citiriVechi = [
    'admins' => 'SELECT id, role, roles_json FROM admins ORDER BY id',
    'products' => 'SELECT id, gallery_images_json, badge_popular, badge_best_seller, badge_seasonal FROM products ORDER BY id',
    'product_extra_fields' => 'SELECT id, name, field_type FROM product_extra_fields ORDER BY id',
    'product_templates' => 'SELECT id, html_content, css_content, js_content FROM product_templates ORDER BY id',
    'product_extra_field_values' => 'SELECT id, `value` FROM product_extra_field_values ORDER BY id',
    'coupons' => 'SELECT id, name, min_items_count, max_items_count FROM coupons ORDER BY id',
    'product_reviews' => 'SELECT product_id, user_name, rating, review_text, is_approved, created_at, source FROM product_reviews ORDER BY product_id, user_name',
    'blog_post_categories' => 'SELECT post_id, category_id FROM blog_post_categories ORDER BY post_id, category_id',
    'fan_streets' => 'SELECT id, row_key FROM fan_streets ORDER BY id',
    'order_payment_links' => 'SELECT id, procesator FROM order_payment_links ORDER BY id',
    'settings (versiuni)' => "SELECT `key`, `value` FROM settings WHERE `key` IN ('bt_ipay_schema_version', 'payment_links_schema_version') ORDER BY `key`",
];
$citesteVechi = static function (PDO $db) use ($citiriVechi): array {
    $r = [];
    foreach ($citiriVechi as $ce => $sql) {
        try {
            $r[$ce] = json_encode($db->query($sql)->fetchAll(PDO::FETCH_NUM), JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            $r[$ce] = 'eroare: ' . $e->getMessage();
        }
    }
    return $r;
};
$extraVariante = $liniiObiecte($dosarVechi . '/extra-variante.txt') ?? [];
$fisiereVechi = [$dosarVechi . '/cms.sql', $dosarVechi . '/variante.sql'];

/** Oprit după fiecare k (1..$total) pe baze pregătite de $pregateste, reluat o dată; $final(PDO, k) → null sau ce nu e bine. */
$opritSiReluat = static function (string $sock, string $prefix, int $total, callable $pregateste, callable $final, callable $mediuOprire)
    use ($comandaRunner, $inParalel, $conectare, $dosarReal, $cuParametru): array {
    $rele = [];
    $lot = 8;
    for ($start = 1; $start <= $total; $start += $lot) {
        $ks = range($start, min($total, $start + $lot - 1));
        $comenzi = [];
        foreach ($ks as $i => $k) {
            $baza = 'cms_mig_t_' . $prefix . '_' . $i;
            $pregateste($baza);
            $comenzi[] = $comandaRunner($sock, $baza, $dosarReal, ['--aplica'], array_merge($cuParametru, $mediuOprire($k)));
        }
        $opriri = $inParalel($comenzi);
        $reluari = [];
        foreach ($ks as $i => $k) {
            if ($opriri[$i][0] !== 1 || !str_contains($opriri[$i][2], 'oprire de test')) {
                $rele[] = 'k=' . $k . ': oprirea a dat ' . $opriri[$i][0] . ' ' . mb_substr(trim($opriri[$i][2] . $opriri[$i][1]), 0, 300);
            }
            $reluari[] = $comandaRunner($sock, 'cms_mig_t_' . $prefix . '_' . $i, $dosarReal, ['--aplica'], $cuParametru);
        }
        $rezultate = $inParalel($reluari);
        foreach ($ks as $i => $k) {
            $baza = 'cms_mig_t_' . $prefix . '_' . $i;
            // O singură reluare trebuie să termine (pe un site: un pas de deploy care nu raportează nicio eroare).
            $cod = $rezultate[$i][0];
            $problema = $cod !== 0 ? 'reluarea a dat ' . $cod . ' ' . mb_substr(trim($rezultate[$i][1] . $rezultate[$i][2]), 0, 300) : $final($conectare($sock, $baza), $k);
            if ($problema !== null) {
                $rele[] = 'k=' . $k . ': ' . $problema;
            }
        }
    }
    return $rele;
};

$ampPeServer = [];
foreach ($servere as $numeServer => $sock) {
    $scrie('');
    $scrie('== ' . $numeServer . ' (' . Server::detecteaza($conectare($sock))->nume() . '): migrările adevărate');
    $b = static fn (string $nume): string => 'cms_mig_t_' . $nume;

    // ------------------------------------------------------------------ T1 (+ T3)
    $scrie('T1. Instalarea nouă');
    $db = $bazaNoua($sock, $b('t1'));
    [$cp, $rp] = $mig($sock, $b('t1'), ['--plan', '--json']);
    $verifica('T1: o bază goală: --plan iese cu 10, toate cele ' . count($migrariReale) . ' migrări noi, fără avertismente, nimic scris',
        $cp === 10 && count((array) ($rp['migrari'] ?? [])) === count($migrariReale) && (array) ($rp['avertismente'] ?? ['?']) === [] && $tabele($db) === [],
        'cod ' . $cp . ' ' . mb_substr((string) ($rp['mesaj'] ?? ''), 0, 300));
    [$ca, $ra] = $mig($sock, $b('t1'), ['--aplica', '--json']);
    $s = $stari($ra);
    $operatiiNoi = $operatiiFacute($ra);
    $verifica('T1: --aplica iese cu 0: grupul B „aplicata” (' . $numarB . '), grupul C „detectata” (' . $numarC . ', nimic de convertit), '
        . 'fără avertismente, ' . $operatiiNoi . ' operații',
        $ca === 0 && ($s['B'] ?? null) === ['aplicata' => $numarB] && ($s['C'] ?? null) === ['detectata' => $numarC]
        && (array) ($ra['avertismente'] ?? ['?']) === [] && $operatiiNoi > 0,
        'cod ' . $ca . ', stări ' . json_encode($s) . ' ' . mb_substr((string) ($ra['mesaj'] ?? ''), 0, 600));
    $F = $amp($db);
    $verifica('T1: instalarea nouă = database/migrari/asteptat.json (' . $descrie($F) . ')', Amprenta::json($F) === $asteptatJson,
        implode('; ', array_slice(array_merge(Amprenta::compara($asteptatReal, $F)['lipsa'], Amprenta::compara($asteptatReal, $F)['in_plus']), 0, 20)));
    $stareT1 = $stareBaza($db);
    $verifica('T1: _platforma_stare: ultima_ord = ' . $ultimaOrd . ', amprenta codului, ultima rulare cu codul 0',
        ($stareT1['ultima_ord'] ?? '') === (string) $ultimaOrd && ($stareT1['amprenta'] ?? '') === $registruReal->amprenta() && $codUltimaRulare($db) === 0,
        json_encode($stareT1));
    $instT1 = $instantaneu($db);
    [$c2, $r2] = $mig($sock, $b('t1'), ['--aplica', '--json']);
    [$c3] = $mig($sock, $b('t1'), ['--plan']);
    $verifica('T3: instalarea nouă, a doua aplicare: nicio migrare rulată, aceleași rânduri și aceeași amprentă; --plan 0',
        $c2 === 0 && (array) ($r2['migrari'] ?? ['?']) === [] && $c3 === 0 && $instantaneu($db) === $instT1 && Amprenta::json($amp($db)) === $asteptatJson,
        'cod ' . $c2 . '/' . $c3);
    $ampPeServer['T1 instalarea nouă'][$numeServer] = Amprenta::json($F);

    // ------------------------------------------------------------------ T2 (+ T3)
    $scrie('T2. Baseline-ul pe schema fiecărui cod (tools/dev/migrari/vechi/)');
    foreach ($asteptatB as $codF => $plan) {
        $extra = $liniiObiecte($dosarVechi . '/extra-' . $codF . '.txt');
        $eroare = null;
        $dbF = null;
        try {
            $dbF = $incarca($sock, $b('t2_' . $codF), [$dosarVechi . '/' . $codF . '.sql'], in_array($codF, ['cms', 'bs'], true) ? $versiuniBs : []);
        } catch (Throwable $e) {
            $eroare = $e->getMessage();
        }
        $verifica('T2 ' . $codF . ': ' . $codF . '.sql și extra-' . $codF . '.txt există, schema se încarcă pe ' . $numeServer,
            $eroare === null && $extra !== null, (string) ($eroare ?? 'lipsește extra-' . $codF . '.txt (genereaza-fixture.php --fixture-uri)'));
        if ($dbF === null || $extra === null) {
            continue;
        }
        [$Q] = $baseline($sock, $b('t2_' . $codF), $dbF, 'T2 ' . $codF, $plan, $extra, in_array($codF, $cuProcesatorLipsa, true));
        $ampPeServer['T2 ' . $codF][$numeServer] = Amprenta::json($Q);
    }

    // Baza veche: cms.sql + variante.sql, o dată prin funcțiile codului, o dată prin migrări.
    $scrie('T2. Baza veche (cms.sql + variante.sql: obiecte și rânduri vechi, inventate)');
    $eroare = null;
    try {
        $dbCod = $incarca($sock, $b('vechi_cod'), $fisiereVechi);
        $dbMig = $incarca($sock, $b('vechi_mig'), $fisiereVechi);
    } catch (Throwable $e) {
        $eroare = $e->getMessage();
    }
    $verifica('T2 vechi: cms.sql + variante.sql se încarcă pe ' . $numeServer . ' (extra-variante.txt: ' . count($extraVariante) . ' obiecte)',
        $eroare === null && $extraVariante !== [], (string) $eroare);
    $QVechi = null;
    $dateVechi = null;
    $operatiiVechi = 0;
    if ($eroare === null) {
        $inainte = $citesteVechi($dbMig);
        [$okH, $rH] = $helperi($sock, $b('vechi_cod'), $codCms);
        $dinCod = $citesteVechi($conectare($sock, $b('vechi_cod')));
        $Pv = $amp($dbMig);
        $fataV = Amprenta::compara($asteptatReal, $Pv);
        [$c15] = $mig($sock, $b('vechi_mig'), ['--aplica'], $faraParametru);
        $verifica('T2 vechi: linkuri fără procesator, fără parametrul site-ului: 15, nimic schimbat',
            $c15 === 15 && $citesteVechi($dbMig) === $inainte && Amprenta::json($amp($dbMig)) === Amprenta::json($Pv));
        [$ca, $ra] = $mig($sock, $b('vechi_mig'), ['--aplica', '--json']);
        $operatiiVechi = $operatiiFacute($ra);
        $dateVechi = $citesteVechi($dbMig);
        $diferite = [];
        foreach ($citiriVechi as $ce => $_) {
            if ($ce === 'order_payment_links') {
                continue;
            }
            if ($dateVechi[$ce] !== $dinCod[$ce]) {
                $diferite[] = $ce . "\n    codul: " . $dinCod[$ce] . "\n    migrările: " . $dateVechi[$ce];
            }
            if ($ce !== 'settings (versiuni)' && $dateVechi[$ce] === $inainte[$ce]) {
                $diferite[] = $ce . ': nimic schimbat (cazul nu verifică nimic)';
            }
        }
        $verifica('T2 vechi: --aplica iese cu 0 (' . $operatiiVechi . ' operații); migrările lasă exact ce lăsau funcțiile ensure* ale codului: '
            . implode(', ', array_keys($citiriVechi)),
            $okH && ($rH['erori'] ?? []) === [] && $ca === 0 && $diferite === [],
            'cod ' . $ca . ' ' . mb_substr((string) ($ra['mesaj'] ?? ''), 0, 400) . "\n" . implode('; ', array_slice((array) ($rH['erori'] ?? []), 0, 3)) . "\n" . implode("\n", $diferite));
        $verifica('T2 vechi: procesatorul linkurilor vechi = parametrul site-ului (stripe), nu presupunerea codului comun (euplatesc)',
            $dateVechi['order_payment_links'] === '[[1,"stripe"],[2,"stripe"]]' && $dinCod['order_payment_links'] === '[[1,"euplatesc"],[2,"euplatesc"]]',
            $dateVechi['order_payment_links'] . ' / ' . $dinCod['order_payment_links']);
        [$cv, $rv] = $mig($sock, $b('vechi_mig'), ['--verifica', '--json']);
        $inPlusV = $sortat((array) ($rv['verificare']['in_plus'] ?? []));
        $asteptatInPlus = $sortat(array_merge($extraCms, $extraVariante));
        $QVechi = $amp($dbMig);
        $difV = Amprenta::compara($Pv, $QVechi);
        $ramase = $dbMig->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME IN ('fan_streets__sync', 'fan_localities__vechi') ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
        $avV = (array) ($rv['verificare']['avertismente'] ?? []);
        $avNoiV = array_values(array_diff($avV, $fataV['avertismente']));
        $verifica('T2 vechi: --verifica iese cu 0; în plus exact extra-cms + extra-variante; singurul obiect vechi scos: indexul uniq_fan_streets '
            . '(înlocuit de uniq_fan_streets_row); avertismentele de dinainte plus fan_streets.row_key (DEFAULT \'\', ca vechiul cod); '
            . 'copiile sincronizării FAN neatinse și în afara amprentei',
            $cv === 0 && $inPlusV === $asteptatInPlus && $difV['lipsa'] === ['indexul fan_streets.uniq_fan_streets'] && $difV['avertismente'] === []
            && array_diff($fataV['avertismente'], $avV) === [] && count($avNoiV) === 1 && str_starts_with($avNoiV[0], 'coloana fan_streets.row_key:')
            && $ramase === ['fan_localities__vechi', 'fan_streets__sync'] && !isset($QVechi['tabele']['fan_streets__sync']),
            'cod ' . $cv . "\nîn plus: " . $listaScurta(array_diff($inPlusV, $asteptatInPlus)) . ' / lipsă din plus: ' . $listaScurta(array_diff($asteptatInPlus, $inPlusV))
            . "\nșterse: " . implode(', ', $difV['lipsa']) . "\nschimbate: " . implode(' | ', $difV['avertismente']) . "\nrămase: " . implode(', ', $ramase)
            . "\navertismente noi: " . implode(' | ', $avNoiV));
        $ampPeServer['T2 baza veche'][$numeServer] = Amprenta::json($QVechi);
        $instV = $instantaneu($dbMig);
        [$c2, $r2] = $mig($sock, $b('vechi_mig'), ['--aplica', '--json']);
        $verifica('T3: baza veche, a doua aplicare: nicio migrare rulată, aceleași rânduri', $c2 === 0 && (array) ($r2['migrari'] ?? ['?']) === []
            && $instantaneu($dbMig) === $instV);
    }

    // ------------------------------------------------------------------ T2-live
    $scrie('T2-live. Schema site-urilor live (sonda, tools/dev/migrari/vechi/privat/)');
    foreach (['bs', 'bv', 'vt', 'gr'] as $codL) {
        if (!$t2Live($sock, $numeServer, $dosarPrivat, $codL, 'T2-live ' . $codL)) {
            $saritLive[$codL] = true;
            $scrie('  SĂRIT T2-live ' . $codL . ': lipsește tools/dev/migrari/vechi/privat/' . $codL . '-live.sql — sonda live n-a venit încă '
                . '(docs/VERIFICARE.md, pasul 5; apoi tools/dev/php.sh tools/dev/migrari/din-sonda.php <fișierul sondei>)');
        }
    }
    // Calea T2-live, pe o sondă simulată: schema bs + variante, citită ca sonda (Amprenta::citesteBrut), trecută prin din-sonda.php.
    $dbSonda = $incarca($sock, $b('sonda_sim'), [$dosarVechi . '/bs.sql', $dosarVechi . '/variante.sql']);
    $familie = Server::detecteaza($dbSonda)->familie();
    $dosarSim = $lucru . '/sonda-simulata-' . $familie;
    @mkdir($dosarSim, 0777, true);
    file_put_contents($dosarSim . '/amprenta-bs-simulata.json', json_encode(['unealta' => 'amprenta-schema', 'format' => 1,
        'generat_la' => date('Y-m-d H:i:s'), 'site' => ['nume' => 'bioscem', 'cod' => 'bs'],
        'server' => ['familie' => $familie, 'versiune' => Server::detecteaza($dbSonda)->versiune()], 'brut' => Amprenta::citesteBrut($dbSonda)]));
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($radacina . '/tools/dev/migrari/din-sonda.php') . ' ' . escapeshellarg($dosarSim . '/amprenta-bs-simulata.json')
        . ' --iesire=' . escapeshellarg($dosarSim) . ' 2>&1', $iesireSonda, $codSonda);
    $verifica('T2-live (sondă simulată, ' . $familie . '): din-sonda.php scrie bs-live.sql, extra-bs.txt, bs-fata-de-asteptat.txt',
        $codSonda === 0 && is_file($dosarSim . '/bs-live.sql'), implode("\n", $iesireSonda));
    unset($iesireSonda);
    if ($codSonda === 0) {
        $t2Live($sock, $numeServer, $dosarSim, 'bs', 'T2-live (sondă simulată)');
    }

    // ------------------------------------------------------------------ T4
    $scrie('T4. Drumul unui site: migrările de până atunci, datele demo, apoi restul');
    foreach (array_values(array_unique(array_filter([$finalB, $penultimaId]))) as $panaLa) {
        $baza = $b('t4');
        $db4 = $bazaNoua($sock, $baza);
        [$c1] = $mig($sock, $baza, ['--aplica', '--pana-la=' . $panaLa]);
        [$cs, $os] = $phpSh($sock, $baza, ['scripts/seed.php']);
        [$cd, $od] = $phpSh($sock, $baza, ['tools/dev/date-demo.php']);
        $inainte = $instantaneu($db4);
        [$ca, $ra] = $mig($sock, $baza, ['--aplica', '--json']);
        $dupa = $instantaneu($db4);
        [$atinse, $nedeclarate] = $tabeleAtinse($ra);
        $schimbateT4 = $schimbate($inainte, $dupa);
        $A4 = $amp($db4);
        $verifica('T4 (până la ' . $panaLa . ', apoi seed.php și date-demo.php, apoi restul): --aplica 0, amprenta = asteptat.json, '
            . 'datele demo schimbate doar în tabelele migrărilor rămase (' . $operatiiFacute($ra) . ' operații)',
            $c1 === 0 && $cs === 0 && $cd === 0 && str_contains($od, 'Date demo gata') && $ca === 0 && Amprenta::json($A4) === $asteptatJson
            && $nedeclarate === [] && array_diff($schimbateT4, $atinse) === [],
            'coduri ' . $c1 . '/' . $cs . '/' . $cd . '/' . $ca . ' ' . mb_substr((string) ($ra['mesaj'] ?? ''), 0, 300)
            . "\nschimbate în afara migrărilor: " . implode(', ', array_diff($schimbateT4, $atinse))
            . ($nedeclarate !== [] ? "\nmigrări de date fără tabele declarate în \$tabeleDate: " . implode(', ', $nedeclarate) : '')
            . "\n" . mb_substr(trim($os . ' ' . $od), -400));
        $ampPeServer['T4 până la ' . $panaLa][$numeServer] = Amprenta::json($A4);
    }

    // ------------------------------------------------------------------ T5
    $scrie('T5. Oprit după fiecare operație, apoi reluat');
    $rele = $opritSiReluat($sock, 't5', $operatiiNoi, static function (string $baza) use ($bazaNoua, $sock): void {
        $bazaNoua($sock, $baza);
    }, static function (PDO $db, int $k) use ($amp, $asteptatJson, $randuri, $operatiiNoi, $stareBaza, $ultimaOrd): ?string {
        $r = $randuri($db);
        $neterminate = array_filter($r, static fn (array $x): bool => !in_array($x['rezultat'], ['aplicata', 'detectata'], true));
        $suma = array_sum(array_map('intval', array_column($r, 'operatii')));
        if (Amprenta::json($amp($db)) !== $asteptatJson || $neterminate !== [] || $suma !== $operatiiNoi
            || ($stareBaza($db)['ultima_ord'] ?? '') !== (string) $ultimaOrd) {
            return 'amprenta ' . (Amprenta::json($amp($db)) === $asteptatJson ? 'bună' : 'alta') . ', neterminate ' . implode(',', array_keys($neterminate))
                . ', operații numărate ' . $suma . ' din ' . $operatiiNoi;
        }
        return null;
    }, static fn (int $k): array => ['MIGRARI_TEST_OPRESTE_DUPA' => (string) $k]);
    $verifica('T5: instalarea nouă oprită (ca un proces omorât) după fiecare din cele ' . $operatiiNoi . ' operații, apoi reluată: amprenta = asteptat.json, '
        . 'nimic „in_curs”, fiecare operație numărată o dată, ultima_ord ' . $ultimaOrd, $rele === [], implode("\n", array_slice($rele, 0, 10)));
    if ($QVechi !== null && $dateVechi !== null) {
        $pregatesteVechi = static function (string $baza) use ($incarca, $sock, $fisiereVechi): void {
            $incarca($sock, $baza, $fisiereVechi);
        };
        $finalVechi = static function (PDO $db, int $k) use ($amp, $QVechi, $citesteVechi, $dateVechi, $randuri, $operatiiVechi): ?string {
            $r = $randuri($db);
            $neterminate = array_filter($r, static fn (array $x): bool => !in_array($x['rezultat'], ['aplicata', 'detectata'], true));
            $suma = array_sum(array_map('intval', array_column($r, 'operatii')));
            $date = $citesteVechi($db);
            if (Amprenta::json($amp($db)) !== Amprenta::json($QVechi) || $date !== $dateVechi || $neterminate !== [] || $suma !== $operatiiVechi) {
                return 'amprenta ' . (Amprenta::json($amp($db)) === Amprenta::json($QVechi) ? 'bună' : 'alta') . ', date diferite: '
                    . implode(', ', array_keys(array_diff_assoc($date, $dateVechi))) . ', neterminate ' . implode(',', array_keys($neterminate))
                    . ', operații numărate ' . $suma . ' din ' . $operatiiVechi;
            }
            return null;
        };
        $rele = $opritSiReluat($sock, 't5v', $operatiiVechi, $pregatesteVechi, $finalVechi, static fn (int $k): array => ['MIGRARI_TEST_OPRESTE_DUPA' => (string) $k]);
        $verifica('T5: baza veche oprită după fiecare din cele ' . $operatiiVechi . ' operații (bucățile de date incluse), apoi reluată: aceeași amprentă, '
            . 'aceleași date, nimic „in_curs”, fiecare operație numărată o dată', $rele === [], implode("\n", array_slice($rele, 0, 10)));
        $puncte = ['bucata_in_tranzactie:1', 'bucata_dupa_commit:1', 'bucata_in_tranzactie:3', 'bucata_dupa_commit:3', 'date_in_tranzactie:2', 'date_dupa_commit:2'];
        $rele = $opritSiReluat($sock, 't5b', count($puncte), $pregatesteVechi, $finalVechi,
            static fn (int $k): array => ['MIGRARI_TEST_OPRESTE_IN' => $puncte[$k - 1]]);
        $verifica('T5: baza veche oprită chiar în tranzacția unei bucăți / a unei instrucțiuni de date și chiar după commit-ul ei ('
            . implode(', ', $puncte) . '), apoi reluată: cursorul respectat, aceleași date, fiecare operație numărată o dată',
            $rele === [], implode("\n", array_slice($rele, 0, 10)));
    }

    // ------------------------------------------------------------------ T6
    $scrie('T6. Codul vechi în paralel cu migrările');
    $baza = $b('t6');
    $db6 = $bazaNoua($sock, $baza);
    [$c1] = $mig($sock, $baza, ['--aplica', '--pana-la=' . ($idPeOrd[4] ?? '')]);
    [$okH, $rH] = $helperi($sock, $baza, $codCms);
    [$ca, $ra] = $mig($sock, $baza, ['--aplica', '--json']);
    [$cv, $rv] = $mig($sock, $baza, ['--verifica', '--json']);
    $implicita = (string) $conectare($sock)->query("SELECT DEFAULT_COLLATE_NAME FROM information_schema.CHARACTER_SETS WHERE CHARACTER_SET_NAME = 'utf8mb4'")->fetchColumn();
    $avPermise = array_map(static fn (string $t): string => 'tabelul ' . $t . ': colația ' . $implicita . ', se așteaptă utf8mb4_unicode_ci', $excluderi['colatie_server']);
    // Codul vechi poate face înaintea migrărilor un tabel fără cheia lui străină (product_reviews, din funcțiile ensure*):
    // o cheie străină lipsă nu se adaugă niciodată singură peste date (migrari.md §3.8), deci e un avertisment voit aici.
    $avT6 = static fn (array $av): array => array_values(array_filter(array_diff($av, $avPermise),
        static fn (string $a): bool => preg_match('/^cheia străină [a-z0-9_]+\.[a-z0-9_]+ lipsește/', $a) !== 1));
    $inPlus6 = (array) ($rv['verificare']['in_plus'] ?? []);
    $verifica('T6: funcțiile ensure* ale codului după primele 4 migrări (codul vechi servește în timpul adoptării): nicio eroare; apoi --aplica: 0; '
        . '--verifica 0, în plus doar excluderile, avertismente doar colația tabelelor BT și cheile străine ale tabelelor făcute de cod',
        $c1 === 0 && $okH && $ca === 0 && $cv === 0 && (array) ($rv['verificare']['lipsa'] ?? ['?']) === []
        && array_diff($inPlus6, $extraCms) === [] && $avT6((array) ($rv['verificare']['avertismente'] ?? [])) === [],
        'coduri ' . $c1 . '/' . $ca . '/' . $cv . ' ' . mb_substr((string) ($ra['mesaj'] ?? ''), 0, 300) . "\nerorile funcțiilor: " . implode('; ', array_slice((array) $rH['erori'], 0, 3))
        . "\nîn plus: " . implode(', ', $inPlus6) . "\navertismente: " . implode(' | ', (array) ($rv['verificare']['avertismente'] ?? [])));
    $releT6 = [];
    for ($runda = 1; $runda <= 3; $runda++) {
        $bazaNoua($sock, $baza);
        // Cu pauzele de pe site-uri la 1205/1213 (2, 4, 8, 16, 30 s; MIGRARI_TEST_PAUZE scos), nu cu cele scurte ale
        // celorlalte teste: pe MySQL 8 un ALTER al codului vechi și unul al migrării pe același tabel pot da un
        // deadlock (1213) de câteva ori la rând, cât timp codul vechi încă își face tabelele.
        $comenzi = [$comandaRunner($sock, $baza, $dosarReal, ['--aplica', '--json'], array_merge($cuParametru, ['MIGRARI_TEST_PAUZE' => null]))];
        for ($h = 0; $h < $runda; $h++) {
            $comenzi[] = $comandaHelperi($sock, $baza, $codCms);
        }
        $rez = $inParalel($comenzi, count($comenzi));
        // Fiecare trecere a codului vechi: cod 0 și nicio excepție (pe un site, o cerere căzută în timpul baseline-ului).
        $eroriH = [];
        foreach (array_slice($rez, 1) as $h => $rh) {
            [$bunH, $jsonH] = $rezultatHelperi($rh);
            if (!$bunH) {
                $eroriH[] = 'trecerea ' . ($h + 1) . ': ' . implode('; ', array_slice((array) $jsonH['erori'], 0, 3));
            }
        }
        [$ca2] = $mig($sock, $baza, ['--aplica']);
        [$cv2, $rv2] = $mig($sock, $baza, ['--verifica', '--json']);
        $inPlus = (array) ($rv2['verificare']['in_plus'] ?? []);
        $av = (array) ($rv2['verificare']['avertismente'] ?? []);
        if ($rez[0][0] !== 0 || $eroriH !== [] || $ca2 !== 0 || $cv2 !== 0 || (array) ($rv2['verificare']['lipsa'] ?? ['?']) !== [] || array_diff($inPlus, $extraCms) !== []
            || $avT6($av) !== []) {
            $releT6[] = 'runda ' . $runda . ': --aplica în paralel ' . $rez[0][0] . ' (' . mb_substr((string) (json_decode($rez[0][1], true)['mesaj'] ?? $rez[0][1] . $rez[0][2]), 0, 300)
                . '), funcțiile codului: ' . ($eroriH === [] ? 'fără erori' : implode(' | ', $eroriH))
                . ', apoi ' . $ca2 . ', --verifica ' . $cv2 . '; în plus ' . implode(', ', array_diff($inPlus, $extraCms)) . '; avertismente ' . implode(' | ', $avT6($av));
        }
    }
    $verifica('T6: --aplica pe o bază goală în același timp cu 1, 2, 3 treceri ale funcțiilor ensure* ale codului: --aplica 0 (cursele acceptate), '
        . 'fiecare trecere a codului fără nicio eroare, baza completă la final, în plus doar excluderile (information_schema care sare un tabel '
        . 'schimbat chiar atunci: citit din nou)', $releT6 === [], implode("\n", $releT6));

    // ------------------------------------------------------------------ T7
    $scrie('T7. O migrare aplicată nu se schimbă');
    $copieT7 = $lucru . '/migrari-t7';
    $sterge($copieT7);
    $copiaza($dosarReal, $copieT7);
    $stareInainte = $stareBaza($db);
    $id13 = (string) ($idPeOrd[13] ?? '');
    $fisier13 = $copieT7 . '/' . $id13 . '.php';
    file_put_contents($fisier13, "\n// o schimbare\n", FILE_APPEND);
    [$c1, , , $o1] = $mig($sock, $b('t1'), ['--plan'], [], $copieT7);
    $lacat = json_decode((string) file_get_contents($copieT7 . '/migrari.lock'), true);
    $lacat['migrari'][$id13] = Registru::sha($fisier13);
    file_put_contents($copieT7 . '/migrari.lock', json_encode($lacat));
    [$c2, , , $o2] = $mig($sock, $b('t1'), ['--aplica'], [], $copieT7);
    copy($dosarReal . '/' . $id13 . '.php', $fisier13);
    $idUltima = (string) ($idPeOrd[$ultimaOrd] ?? '');
    unlink($copieT7 . '/' . $idUltima . '.php');
    $lacat['migrari'][$id13] = Registru::sha($fisier13);
    file_put_contents($copieT7 . '/migrari.lock', json_encode($lacat));
    [$c3, , , $o3] = $mig($sock, $b('t1'), ['--plan'], [], $copieT7);
    $lacat['retrase'] = [$idUltima => 'test T7'];
    file_put_contents($copieT7 . '/migrari.lock', json_encode($lacat));
    [$c4] = $mig($sock, $b('t1'), ['--plan'], [], $copieT7);
    $verifica('T7: pe baza instalată, o migrare aplicată schimbată: 12 (față de migrari.lock, apoi față de baza care a aplicat-o, cu lacătul rescris); '
        . 'ștearsă: 12; trecută la „retrase”: 0; baza și starea neatinse',
        $c1 === 12 && str_contains($o1, $id13) && $c2 === 12 && str_contains($o2, 'după ce a fost aplicată') && $c3 === 12 && $c4 === 0
        && Amprenta::json($amp($db)) === $asteptatJson && $stareBaza($db) === $stareInainte,
        json_encode([$c1, $c2, $c3, $c4]) . ' ' . mb_substr($o1 . ' | ' . $o2 . ' | ' . $o3, 0, 600));

    // ------------------------------------------------------------------ T8
    $scrie('T8. O migrare blocantă nedeclarată');
    $copieT8 = $lucru . '/migrari-t8';
    $sterge($copieT8);
    $copiaza($dosarReal, $copieT8);
    $scrieMigrare($copieT8, 'nucleu/9999_proba_blocanta', "        \$s->modificaColoana('pages', 'title', 'VARCHAR(300) NOT NULL');");
    $inainte = $instantaneu($db);
    [$cp, , , $op] = $mig($sock, $b('t1'), ['--plan'], [], $copieT8);
    [$ca, , , $oa] = $mig($sock, $b('t1'), ['--aplica'], [], $copieT8);
    $neatinsa = Amprenta::json($amp($db)) === $asteptatJson && !isset($randuri($db)['nucleu/9999_proba_blocanta']) && $instantaneu($db) === $inainte;
    [$cb, , , $ob] = $mig($sock, $b('t1'), ['--aplica', '--permite-blocante'], [], $copieT8);
    $titlu = Amprenta::citeste($db, Server::detecteaza($db), ['pages'])['tabele']['pages']['coloane']['title'] ?? [];
    $verifica('T8: MODIFY COLUMN într-o migrare declarată neblocantă, pe baza instalată: --plan și --aplica 11, nimic aplicat; '
        . 'cu --permite-blocante: aplicată (copie), coloana schimbată',
        $cp === 11 && str_contains($op, 'copie') && $ca === 11 && $neatinsa && $cb === 0 && ($titlu['tip'] ?? '') === 'varchar(300)'
        && ($randuri($db)['nucleu/9999_proba_blocanta']['rezultat'] ?? '') === 'aplicata',
        json_encode([$cp, $ca, $neatinsa, $cb, $titlu]) . ' ' . mb_substr($op . ' | ' . $oa . ' | ' . $ob, 0, 600));

    // ------------------------------------------------------------------ T10
    $scrie('T10. Aplicația pe o bază migrată: una făcută ca înainte (apoi baseline), una făcută de install.php, una doar de migrări');
    $avPermise10 = $avPermise;
    $helperiNuSchimba = static function (PDO $db, string $baza, string $eticheta) use ($sock, $helperi, $amp, $instantaneu, $schimbate, $verifica,
        $codCms, $codBs, $revizuitBs): void {
        $A = $amp($db);
        foreach (['codului comun' => $codCms, 'codului Bioscem ' . $revizuitBs => $codBs] as $al => $dosarCod) {
            $S = $instantaneu($db);
            [$okH, $rH] = $helperi($sock, $baza, $dosarCod);
            $verifica('T10: funcțiile ensure* ale ' . $al . ' pe ' . $eticheta . ': nicio eroare, nicio schimbare de schemă, niciun rând schimbat',
                $okH && Amprenta::json($amp($db)) === Amprenta::json($A) && $instantaneu($db) === $S,
                implode('; ', array_slice((array) $rH['erori'], 0, 3)) . ' schimbate: ' . implode(', ', $schimbate($S, $instantaneu($db))));
        }
    };

    // a) Ca un site vechi: install.php de dinainte de migrări + funcțiile ensure* (tools/dev/migrari/pe-vechi.php), seed, date
    //    demo, apoi baseline-ul.
    $baza = $b('t10');
    $db10 = $bazaNoua($sock, $baza);
    [$cpv, $opv] = $phpSh($sock, $baza, ['tools/dev/migrari/pe-vechi.php']);
    [$cs] = $phpSh($sock, $baza, ['scripts/seed.php']);
    [$cd, $od] = $phpSh($sock, $baza, ['tools/dev/date-demo.php']);
    $P10 = $amp($db10);
    $S10 = $instantaneu($db10);
    [$ca, $ra] = $mig($sock, $baza, ['--aplica', '--json']);
    $s10 = $stari($ra);
    [$atinse, $nedeclarate] = $tabeleAtinse($ra);
    // Schema: neschimbată, în afară de tabelele și coloanele noi ale migrărilor de după baseline (codul vechi nu le avea).
    $noiDupa10 = $tabeleNoiDupaBaseline($ra);
    $coloaneDupa10 = $coloaneNoiDupaBaseline($ra);
    $indecsiDupa10 = $coloaneNoiDupaBaseline($ra, 'index');
    $Q10 = $faraColoane($faraColoane($faraTabele($amp($db10), $noiDupa10), $coloaneDupa10), $indecsiDupa10, 'indecsi');
    $schimbate10 = $schimbate($S10, $instantaneu($db10));
    $verifica('T10: baza făcută ca înainte (pe-vechi.php, seed.php, date-demo.php): baseline-ul iese cu 0, grupul B „detectata” fără nicio operație, '
        . 'schema neschimbată (în afară de tabelele noi de după baseline: ' . ($noiDupa10 !== [] ? implode(', ', $noiDupa10) : 'niciunul')
        . '; coloanele noi: ' . ($coloaneDupa10 !== [] ? implode(', ', $coloaneDupa10) : 'niciuna')
        . '; indecșii noi: ' . ($indecsiDupa10 !== [] ? implode(', ', $indecsiDupa10) : 'niciunul') . '), '
        . 'datele schimbate doar unde declară migrările (' . $operatiiFacute($ra) . ' operații)',
        $cpv === 0 && str_contains($opv, 'garda: fara_registru') && $cs === 0 && $cd === 0 && $ca === 0
        && ($s10['B'] ?? null) === ['detectata' => $numarB] && !isset($operatii($ra)['B'])
        && Amprenta::json($Q10) === Amprenta::json($P10) && $nedeclarate === [] && array_diff($schimbate10, $atinse) === [],
        'coduri ' . $cpv . '/' . $cs . '/' . $cd . '/' . $ca . ', stări ' . json_encode($s10) . ', schimbate ' . implode(', ', $schimbate10)
        . ', tabele noi după baseline ' . implode(', ', $noiDupa10)
        . ' ' . mb_substr((string) ($ra['mesaj'] ?? ''), 0, 300) . ' ' . mb_substr(trim($opv . ' ' . $od), -300));
    [$cv, $rv] = $mig($sock, $baza, ['--verifica', '--json']);
    $verifica('T10: baza făcută ca înainte, după baseline: --verifica 0, în plus exact excluderile, avertismente doar colația tabelelor BT',
        $cv === 0 && $sortat((array) ($rv['verificare']['in_plus'] ?? [])) === $extraCms
        && $sortat((array) ($rv['verificare']['avertismente'] ?? [])) === $sortat($avPermise10),
        'cod ' . $cv . ' ' . implode(', ', (array) ($rv['verificare']['in_plus'] ?? [])) . ' | ' . implode(' | ', (array) ($rv['verificare']['avertismente'] ?? [])));
    $helperiNuSchimba($db10, $baza, 'baza făcută ca înainte, după baseline, cu date demo');
    // Diferențele declarate în site-uri/bioscem/ce-se-schimba.md (WP-05): pe o bază la zi garda oprește și readucerea
    // datelor șterse de admin. Toate categoriile de blog șterse (ca din Admin → Blog → Categorii) și o recenzie copiată
    // din tabelul vechi `reviews`, apoi ștearsă din Admin → Recenzii (rândul rămâne în `reviews`, lipsește din
    // product_reviews): codul comun nu le readuce; codul Bioscem pune la loc cele patru categorii ale lui și recenzia.
    $db10->exec('UPDATE blog_posts SET category_id = NULL');
    $db10->exec('DELETE FROM blog_post_categories');
    $db10->exec('DELETE FROM blog_categories');
    $idProdus10 = (int) $db10->query('SELECT MIN(id) FROM products')->fetchColumn();
    $db10->prepare("INSERT INTO reviews (product_id, user_name, rating, review_text, is_approved, created_at)
        VALUES (:p, 'Recenzent T10 inventat', 4, 'Recenzie veche inventată, ștearsă de admin', 1, '2024-02-03 04:05:06')")->execute(['p' => $idProdus10]);
    $recenzieT10 = "SELECT COUNT(*) FROM product_reviews WHERE user_name = 'Recenzent T10 inventat'";
    $S10d = $instantaneu($db10);
    [$okC10, $rC10] = $helperi($sock, $baza, $codCms);
    $schimbateC10 = $schimbate($S10d, $instantaneu($db10));
    [$okB10, $rB10] = $helperi($sock, $baza, $codBs);
    $schimbateB10 = $sortat($schimbate($S10d, $instantaneu($db10)));
    $categoriiB10 = implode(',', $db10->query('SELECT slug FROM blog_categories ORDER BY slug')->fetchAll(PDO::FETCH_COLUMN));
    $verifica('T10: diferențele declarate (ce-se-schimba.md): categoriile de blog șterse toate și o recenzie copiată din `reviews`, apoi '
        . 'ștearsă, rămân șterse cu codul comun (nimic scris); codul Bioscem ' . $revizuitBs . ' le readuce (cele patru categorii, recenzia)',
        $idProdus10 > 0 && $okC10 && $schimbateC10 === [] && $okB10 && $schimbateB10 === ['blog_categories', 'product_reviews']
        && $categoriiB10 === 'evenimente,info-medici,info-pacienti,noutati' && (int) $db10->query($recenzieT10)->fetchColumn() === 1,
        implode('; ', array_slice(array_merge((array) $rC10['erori'], (array) $rB10['erori']), 0, 3)) . ' comun: ' . implode(', ', $schimbateC10)
        . '; Bioscem: ' . implode(', ', $schimbateB10) . ' (categorii ' . $categoriiB10 . ')');

    // b) Un site nou: scripts/install.php (migrările, seed-ul neutru, setările, pașii de activare, adminul), seed, date demo.
    $bazaI = $b('t10_inst');
    $dbI = $bazaNoua($sock, $bazaI);
    [$ci, $oi] = $phpSh($sock, $bazaI, ['scripts/install.php']);
    $randuriInstalare = [
        'categorii' => implode(',', $dbI->query('SELECT slug FROM blog_categories ORDER BY slug')->fetchAll(PDO::FETCH_COLUMN)),
        'pagini' => implode(',', $dbI->query('SELECT slug FROM pages ORDER BY slug')->fetchAll(PDO::FETCH_COLUMN)),
        'admini' => json_encode($dbI->query('SELECT email, role, roles_json FROM admins ORDER BY id')->fetchAll(PDO::FETCH_NUM)),
        'setari' => (int) $dbI->query('SELECT COUNT(*) FROM settings')->fetchColumn(),
        'liste' => (int) $dbI->query('SELECT COUNT(*) FROM newsletter_lists')->fetchColumn(),
    ];
    [$cpI, $rpI] = $mig($sock, $bazaI, ['--plan', '--json']);
    $verifica('T10: scripts/install.php pe o bază goală: 0, schema = asteptat.json, --plan 0; seed-ul neutru (noutati, evenimente), pagina '
        . '/acorduri-gdpr (pasul de activare, licența „toate”), adminul cu rolul în forma de azi, setările implicite, fără listă de newsletter',
        $ci === 0 && Amprenta::json($amp($dbI)) === $asteptatJson && $cpI === 0 && $randuriInstalare['categorii'] === 'evenimente,noutati'
        && $randuriInstalare['pagini'] === 'acorduri-gdpr'
        && $randuriInstalare['admini'] === json_encode([['admin@exemplu.ro', 'administrator_general', '["administrator_general"]']])
        && $randuriInstalare['setari'] >= count(\App\Support\Settings::all(null)) && $randuriInstalare['liste'] === 0,
        'cod ' . $ci . ', plan ' . $cpI . ' ' . json_encode($randuriInstalare, JSON_UNESCAPED_UNICODE) . ' ' . mb_substr($oi, -400));
    $SI = $instantaneu($dbI);
    [$ci2, $oi2] = $phpSh($sock, $bazaI, ['scripts/install.php']);
    $verifica('T10: scripts/install.php a doua oară, pe baza instalată: 1, „baza există”, nimic scris',
        $ci2 === 1 && str_contains($oi2, 'Baza există') && $instantaneu($dbI) === $SI, 'cod ' . $ci2 . ' ' . mb_substr($oi2, -300));
    $NI = $bazaNoua($sock, $b('t10_inst_nou'));
    [$cn] = $phpSh($sock, $b('t10_inst_nou'), ['scripts/install.php']);
    $SIN = $instantaneu($NI);
    [$okBn, $rBn] = $helperi($sock, $b('t10_inst_nou'), $codBs);
    $schimbateBn = $sortat($schimbate($SIN, $instantaneu($NI)));
    $randuriBn = [];
    foreach ($randuriBioscemPeInstalareNoua as $tabel => [$sql, $asteptate]) {
        $gasite = array_map('strval', $NI->query($sql)->fetchAll(PDO::FETCH_COLUMN));
        if ($gasite !== $asteptate) {
            $randuriBn[] = $tabel . ': ' . implode(', ', $gasite) . ' (așteptat ' . implode(', ', $asteptate) . ')';
        }
    }
    $verifica('T10: funcțiile ensure* ale codului Bioscem ' . $revizuitBs . ' pe o bază nouă făcută de install.php (fără date demo): nicio eroare, '
        . 'schema rămâne asteptat.json; scriu doar ' . implode(', ', array_keys($randuriBioscemPeInstalareNoua))
        . ' (lista implicită; restul l-a pus instalarea)',
        $cn === 0 && $okBn && Amprenta::json($amp($NI)) === $asteptatJson && $schimbateBn === array_keys($randuriBioscemPeInstalareNoua) && $randuriBn === [],
        implode('; ', array_slice((array) $rBn['erori'], 0, 3)) . "\ntabele schimbate: " . implode(', ', $schimbateBn) . "\n" . implode("\n", $randuriBn));
    [$csI] = $phpSh($sock, $bazaI, ['scripts/seed.php']);
    [$cdI, $odI] = $phpSh($sock, $bazaI, ['tools/dev/date-demo.php']);
    $verifica('T10: seed.php și date-demo.php pe baza făcută de install.php', $csI === 0 && $cdI === 0 && str_contains($odI, 'Date demo gata'),
        mb_substr($odI, -300));
    $helperiNuSchimba($dbI, $bazaI, 'baza făcută de install.php, cu date demo');

    // c) Doar migrările, fără instalare: ce scriu funcțiile ensure* ale fiecărui cod.
    foreach (['codului comun' => [$codCms, []], 'codului Bioscem ' . $revizuitBs => [$codBs, $randuriHelperiPeBazaNoua]] as $al => [$dosarCod, $randuriAsteptate]) {
        $bazaNoua($sock, $b('t10_nou'));
        $mig($sock, $b('t10_nou'), ['--aplica']);
        $dbN = $conectare($sock, $b('t10_nou'));
        $SN = $instantaneu($dbN);
        [$okN, $rN] = $helperi($sock, $b('t10_nou'), $dosarCod);
        $An = $amp($dbN);
        [$cvn] = $mig($sock, $b('t10_nou'), ['--verifica', '--json']);
        $schimbateN = $sortat($schimbate($SN, $instantaneu($dbN)));
        $randuriN = [];
        foreach ($randuriAsteptate as $tabel => [$sql, $asteptate]) {
            $gasite = array_map('strval', $dbN->query($sql)->fetchAll(PDO::FETCH_COLUMN));
            if ($gasite !== $asteptate) {
                $randuriN[] = $tabel . ': ' . implode(', ', $gasite) . ' (așteptat ' . implode(', ', $asteptate) . ')';
            }
        }
        $verifica('T10: funcțiile ensure* ale ' . $al . ' pe o bază nouă făcută doar de migrări: nicio eroare, schema rămâne asteptat.json, '
            . '--verifica 0; rânduri scrise: ' . ($randuriAsteptate === [] ? 'niciunul (garda R1)' : implode(', ', array_keys($randuriAsteptate))),
            $okN && Amprenta::json($An) === $asteptatJson && $cvn === 0 && $schimbateN === array_keys($randuriAsteptate) && $randuriN === [],
            implode('; ', array_slice((array) $rN['erori'], 0, 3)) . ' ' . implode('; ', array_slice(array_merge(Amprenta::compara($asteptatReal, $An)['in_plus'],
                Amprenta::compara($asteptatReal, $An)['avertismente']), 0, 10)) . "\ntabele schimbate: " . implode(', ', $schimbateN) . "\n" . implode("\n", $randuriN));
    }
}

$verifica('T4, T10: licența locală (storage/licenta) neatinsă de scripturile rulate cu licența testului', $licentaLocala() === $licentaLocalaInainte);

// ------------------------------------------------------------------ T9
$scrie('');
$scrie('T9. Aceeași amprentă pe fiecare server (' . implode(', ', array_keys($servere)) . ')');
if (count($servere) >= 2) {
    foreach ($ampPeServer as $ce => $amprente) {
        $valori = array_values($amprente);
        $diferente = [];
        foreach (array_slice($valori, 1) as $alta) {
            $c = Amprenta::compara(json_decode($valori[0], true), json_decode($alta, true));
            $diferente = array_merge($diferente, $c['lipsa'], $c['in_plus'], $c['avertismente']);
        }
        $verifica('T9: aceeași amprentă pe fiecare server (' . count($servere) . '): ' . $ce,
            count($valori) === count($servere) && count(array_unique($valori)) === 1,
            count($valori) === count($servere) ? implode('; ', array_slice($diferente, 0, 10)) : 'lipsește un server');
    }
} else {
    $scrie('  SĂRIT T9: un singur server (' . ($doarMariaDb ? '--doar-mariadb' : 'MySQL 8 nu merge') . '): rezultatul e parțial');
}

// ------------------------------------------------------------------ T11
$scrie('');
$scrie('T11. Verificarea fișierelor de migrare (tools/dev/verifica-migrari.php)');
$lint = static function (array $argumente) use ($radacina, $sockMaria, $utilizator, $parola): array {
    $env = getenv();
    $env['DB_USERNAME'] = $utilizator;
    $env['DB_PASSWORD'] = $parola;
    $p = proc_open(array_merge([PHP_BINARY, '-d', 'pdo_mysql.default_socket=' . $sockMaria, '-d', 'display_errors=stderr',
        $radacina . '/tools/dev/verifica-migrari.php'], $argumente), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, $radacina, $env);
    $out = (string) stream_get_contents($pipe[1]);
    $err = (string) stream_get_contents($pipe[2]);
    fclose($pipe[1]);
    fclose($pipe[2]);
    return [proc_close($p), $out . $err];
};
[$cl, $ol] = $lint([]);
$verifica('T11: verifica-migrari.php pe repo: nicio încălcare (' . trim((string) preg_replace('/^.*verifica-migrari: /s', '', $ol)) . ')', $cl === 0, $ol);
// O copie a repo-ului (ce citește verificarea), în care se pune pe rând câte o greșeală.
$copieL = $lucru . '/copie-repo';
$sterge($copieL);
foreach (['src', 'scripts', 'public', 'views', 'config', 'database'] as $parte) {
    $copiaza($radacina . '/' . $parte, $copieL . '/' . $parte);
}
copy($radacina . '/bootstrap.php', $copieL . '/bootstrap.php');
@mkdir($copieL . '/tools/dev/migrari', 0777, true);
copy($radacina . '/tools/dev/migrari/ddl-din-cod.txt', $copieL . '/tools/dev/migrari/ddl-din-cod.txt');
[$c0, $o0] = $lint(['--radacina=' . $copieL]);
$verifica('T11: copia repo-ului trece la fel (nicio încălcare)', $c0 === 0, $o0);
$mutatii = [];
$cuSchimbare = static function (string $fisier, callable $schimba, callable $verificare) use ($copieL): mixed {
    $cale = $copieL . '/' . $fisier;
    $inainte = is_file($cale) ? (string) file_get_contents($cale) : null;
    $dupa = $schimba($inainte);
    @mkdir(dirname($cale), 0777, true);
    file_put_contents($cale, $dupa);
    try {
        return $verificare();
    } finally {
        if ($inainte === null) {
            @unlink($cale);
        } else {
            file_put_contents($cale, $inainte);
        }
    }
};
$migrareNoua = static fn (string $sus, string $tip = 'schema'): string => "<?php\n\ndeclare(strict_types=1);\n\nuse App\\Support\\Migrari\\Schema;\n\nreturn [\n"
    . "    'descriere' => 'Probă T11',\n    'tip' => '" . $tip . "',\n    'blocanta' => false,\n    'sus' => static function (Schema \$s): void {\n" . $sus . "\n    },\n];\n";
// Un DDL vechi care iese din cod: CREATE TABLE-ul lui AdminActivityLog::ensureSchema devine o citire.
$faraDdlActivitate = static fn (?string $x): string => str_replace('CREATE TABLE IF NOT EXISTS admin_activity_log', 'SELECT 1 FROM admin_activity_log', (string) $x);
$cazuriLint = [
    'număr NNNN folosit de două ori' => ['database/migrari/catalog/0013_dublat.php', static fn (?string $x): string => $migrareNoua("        \$s->coloana('products', 'proba_t11', 'INT NULL');"), 'folosit de două ori'],
    'migrare nouă netrecută în migrari.lock' => ['database/migrari/nucleu/0098_proba.php', static fn (?string $x): string => $migrareNoua("        \$s->coloana('pages', 'proba_t11', 'INT NULL');"), 'migrari.lock: lipsește nucleu/0098_proba'],
    'AFTER într-o definiție' => ['database/migrari/nucleu/0098_proba.php', static fn (?string $x): string => $migrareNoua("        if (\$s->existaTabel('nu_exista')) {\n            \$s->coloana('pages', 'proba_t11', 'INT NULL AFTER title');\n        }"), 'AFTER/FIRST'],
    'IF NOT EXISTS într-o definiție' => ['database/migrari/nucleu/0098_proba.php', static fn (?string $x): string => $migrareNoua("        \$s->coloana('pages', 'proba_t11', 'INT NULL IF NOT EXISTS');"), 'IF [NOT] EXISTS'],
    'COLLATE într-o definiție' => ['database/migrari/nucleu/0098_proba.php', static fn (?string $x): string => $migrareNoua("        \$s->coloana('pages', 'proba_t11', 'VARCHAR(10) COLLATE utf8mb4_bin NULL');"), 'CHARACTER SET/CHARSET/COLLATE'],
    'DDL scris de mână într-o migrare' => ['database/migrari/nucleu/0098_proba.php', static fn (?string $x): string => $migrareNoua("        \$s->peBucati('pages', 'id', 100, static function (PDO \$db, int \$de, int \$pana): int {\n            return (int) \$db->exec('ALTER TABLE pages ADD COLUMN proba_t11 INT NULL');\n        });", 'date'), 'DDL scris de mână'],
    'ștergere într-o migrare „schema”' => ['database/migrari/nucleu/0098_proba.php', static fn (?string $x): string => $migrareNoua("        \$s->stergeColoana('pages', 'proba_t11');"), 'ștergerile doar în „contract”'],
    'migrare publicată schimbată' => ['database/migrari/' . ($idPeOrd[13] ?? '') . '.php', static fn (?string $x): string => (string) $x . "\n// o schimbare\n", 's-a schimbat după ce a fost trecută'],
    'DDL nou într-o funcție ensure*' => ['src/Support/ErpSync.php', static fn (?string $x): string => str_replace("        try {\n            \$db->exec('CREATE INDEX idx_orders_erp_status",
        "        try {\n            \$db->exec('ALTER TABLE orders ADD COLUMN proba_t11 INT NULL');\n        } catch (Throwable) {\n        }\n        try {\n            \$db->exec('CREATE INDEX idx_orders_erp_status", (string) $x), 'DDL nou'],
    'o definiție de coloană nouă în lista din ErpSync::ensureSchema' => ['src/Support/ErpSync.php', static fn (?string $x): string => str_replace("            'erp_status' => \"VARCHAR(20) NOT NULL DEFAULT 'pending'\",",
        "            'erp_status' => \"VARCHAR(20) NOT NULL DEFAULT 'pending'\",\n            'proba_t11' => 'MEDIUMINT NULL',", (string) $x), 'definiție de coloană nouă'],
    'definiții doar cu tipul (DATETIME, TEXT) în lista din ErpSync::ensureSchema' => ['src/Support/ErpSync.php', static fn (?string $x): string => str_replace("            'erp_status' => \"VARCHAR(20) NOT NULL DEFAULT 'pending'\",",
        "            'erp_status' => \"VARCHAR(20) NOT NULL DEFAULT 'pending'\",\n            'proba_t11' => 'DATETIME',\n            'proba_t11_2' => 'TEXT',", (string) $x), ['definiție de coloană nouă („DATETIME', 'definiție de coloană nouă („TEXT']],
    'un tip singur cu litere mici într-o funcție cu DDL' => ['src/Support/ErpSync.php', static fn (?string $x): string => str_replace("            'erp_status' => \"VARCHAR(20) NOT NULL DEFAULT 'pending'\",",
        "            'erp_status' => \"VARCHAR(20) NOT NULL DEFAULT 'pending'\",\n            'proba_t11' => 'datetime',", (string) $x), 'definiție de coloană nouă („datetime'],
    'o definiție într-o constantă a clasei, adăugată la lista din ErpSync::ensureSchema' => ['src/Support/ErpSync.php', static fn (?string $x): string => str_replace(
        ["final class ErpSync\n{\n", '        foreach ($columns as $name => $definition) {'],
        ["final class ErpSync\n{\n    private const COLOANE_T11 = ['proba_t11' => 'VARCHAR(40) NULL'];\n\n", '        foreach (array_merge($columns, self::COLOANE_T11) as $name => $definition) {'],
        (string) $x), 'ErpSync (clasă): definiție de coloană nouă'],
    'o listă de coloane într-un fișier fără DDL' => ['src/Support/ColoaneProbaT11.php', static fn (?string $x): string => "<?php\n\nnamespace App\\Support;\n\nfinal class ColoaneProbaT11\n{\n"
        . "    public const COLOANE = ['proba_t11' => 'INT NOT NULL DEFAULT 0'];\n}\n", 'ColoaneProbaT11 (clasă): definiție de coloană nouă'],
    'DDL într-o clasă nouă din src/' => ['src/Support/ProbaT11.php', static fn (?string $x): string => "<?php\n\nnamespace App\\Support;\n\nfinal class ProbaT11\n{\n    public static function f(\\PDO \$db): void\n    {\n        \$db->exec(\"CREATE TABLE proba_t11 (id INT)\");\n    }\n}\n", 'DDL nou'],
    'DDL în schema.sql' => ['database/schema.sql', static fn (?string $x): string => (string) $x . "\nCREATE TABLE IF NOT EXISTS proba_t11 (id INT);\n", 'DDL nou'],
    'DDL vechi ieșit din cod (lista nu e actualizată)' => ['src/Support/AdminActivityLog.php', $faraDdlActivitate, 'a ieșit din cod'],
    'asteptat.json vechi' => ['database/migrari/asteptat.json', static function (?string $x): string {
        $a = json_decode((string) $x, true);
        unset($a['tabele']['pages']['coloane']['js_content']);
        return Amprenta::json($a);
    }, 'asteptat.json nu e la zi'],
];
foreach ($cazuriLint as $eticheta => [$fisier, $schimba, $mesaj]) {
    [$c, $o] = $cuSchimbare($fisier, $schimba, static fn (): array => $lint(['--radacina=' . $copieL]));
    $toateMesajele = array_filter((array) $mesaj, static fn (string $m): bool => str_contains($o, $m)) === (array) $mesaj;
    $mutatii[$eticheta] = $c === 1 && $toateMesajele ? null : 'cod ' . $c . ': ' . mb_substr($o, 0, 500);
}
$rele = array_filter($mutatii);
$verifica('T11: verifica-migrari.php pică (1, cu motivul) pe fiecare greșeală pusă în copie: ' . implode('; ', array_keys($cazuriLint)),
    $rele === [], implode("\n", array_map(static fn (string $k, string $v): string => $k . ' → ' . $v, array_keys($rele), $rele)));
// Și invers: textele obișnuite din cod care încep cu numele unui tip (tipuri de câmpuri, mesaje, funcții SQL) nu sunt
// definiții, altfel orice cod nou din AdminController (care are DDL) ar pica fără motiv.
[$cn, $on] = $cuSchimbare('src/Http/Controllers/AdminController.php', static fn (?string $x): string => str_replace("    public function dashboard(): void\n",
    "    private function probaT11Texte(PDO \$db): array\n    {\n"
    . "        \$campuri = [['type' => 'text'], ['type' => 'date'], ['type' => 'json'], ['format' => 'year'], 'Text'];\n"
    . "        \$conditii = ['DATE(o.created_at) >= ?', 'YEAR(o.created_at) = ?', \"DATE({\$db->quote('x')})\"];\n"
    . "        return [\$campuri, \$conditii, 'Date invalide.', 'Text sub buton', 'Timestamp invalid', 'Set implicit'];\n    }\n\n"
    . "    public function dashboard(): void\n", (string) $x), static fn (): array => $lint(['--radacina=' . $copieL, '--fara-baza']));
$verifica('T11: texte obișnuite care încep cu numele unui tip, într-o metodă nouă din AdminController (\'text\', \'date\', \'Date invalide.\', '
    . '\'DATE(o.created_at) >= ?\', "DATE({…})"): nicio încălcare', $cn === 0, $on);
// Proprietarul: o coloană a magazinului adăugată de o migrare a catalogului (cu lock, ultima.php și asteptat.json la zi).
$proprietarGresit = static function () use ($copieL, $migrareNoua, $lint, $bazaNoua, $ruleaza, $sockMaria, $proprietari, $conectare): array {
    file_put_contents($copieL . '/database/migrari/catalog/0098_proba.php', $migrareNoua("        \$s->coloana('orders', 'proba_t11', 'INT NULL');"));
    $r = new Registru($copieL . '/database/migrari', $proprietari);
    $lacat = $r->lacat();
    $lacat['migrari']['catalog/0098_proba'] = Registru::sha($copieL . '/database/migrari/catalog/0098_proba.php');
    file_put_contents($copieL . '/database/migrari/migrari.lock', json_encode(['migrari' => $lacat['migrari'], 'retrase' => (object) $lacat['retrase']]));
    file_put_contents($copieL . '/database/migrari/ultima.php', $r->textUltima());
    $bazaNoua($sockMaria, 'cms_mig_t_t11');
    $ruleaza($sockMaria, 'cms_mig_t_t11', $copieL . '/database/migrari', ['--aplica']);
    file_put_contents($copieL . '/database/migrari/asteptat.json', $ruleaza($sockMaria, 'cms_mig_t_t11', $copieL . '/database/migrari', ['--amprenta'])[1]);
    $conectare($sockMaria)->exec('DROP DATABASE IF EXISTS cms_mig_t_t11');
    return $lint(['--radacina=' . $copieL]);
};
$salvate = [];
foreach (['migrari.lock', 'ultima.php', 'asteptat.json'] as $f) {
    $salvate[$f] = (string) file_get_contents($copieL . '/database/migrari/' . $f);
}
[$cp, $op] = $proprietarGresit();
foreach ($salvate as $f => $continut) {
    file_put_contents($copieL . '/database/migrari/' . $f, $continut);
}
@unlink($copieL . '/database/migrari/catalog/0098_proba.php');
$verifica('T11: o coloană a magazinului (orders.proba_t11) adăugată de o migrare a catalogului, cu migrari.lock, ultima.php și asteptat.json la zi: '
    . 'pică doar pe proprietar', $cp === 1 && str_contains($op, 'coloana orders.proba_t11 e, în catalog, a lui magazin') && substr_count($op, 'EȘEC') === 1, $op);
// --actualizeaza-lista: doar scoate.
$fisierListaCopie = $copieL . '/tools/dev/migrari/ddl-din-cod.txt';
$listaInainte = (string) file_get_contents($fisierListaCopie);
[$cu, $ou] = $cuSchimbare('src/Support/ErpSync.php', static fn (?string $x): string => str_replace("            'erp_status' => \"VARCHAR(20) NOT NULL DEFAULT 'pending'\",",
    "            'erp_status' => \"VARCHAR(20) NOT NULL DEFAULT 'pending'\",\n            'proba_t11' => 'MEDIUMINT NULL',", (string) $x),
    static fn (): array => $lint(['--radacina=' . $copieL, '--actualizeaza-lista', '--fara-baza']));
$neschimbata = (string) file_get_contents($fisierListaCopie) === $listaInainte;
$fisierActivitate = $copieL . '/src/Support/AdminActivityLog.php';
$codActivitate = (string) file_get_contents($fisierActivitate);
file_put_contents($fisierActivitate, $faraDdlActivitate($codActivitate));
[$cs, $os] = $lint(['--radacina=' . $copieL, '--actualizeaza-lista', '--fara-baza']);
$listaDupa = (string) file_get_contents($fisierListaCopie);
[$cdupa, $odupa] = $lint(['--radacina=' . $copieL, '--fara-baza']);
file_put_contents($fisierActivitate, $codActivitate);
[$cinapoi, $oinapoi] = $lint(['--radacina=' . $copieL, '--fara-baza']);
file_put_contents($fisierListaCopie, $listaInainte);
[$cr] = $lint(['--radacina=' . $copieL, '--lista-initiala', '--fara-baza']);
$verifica('T11: --actualizeaza-lista refuză un DDL nou (lista neschimbată); scoate unul ieșit din cod (un rând mai puțin, apoi verificarea trece); '
    . 'DDL-ul pus la loc după aceea e unul nou (încălcare); --lista-initiala refuză o listă existentă',
    $cu === 1 && $neschimbata && $cs === 0 && substr_count($listaDupa, "\n") === substr_count($listaInainte, "\n") - 1 && $cdupa === 0
    && $cinapoi === 1 && str_contains($oinapoi, 'DDL nou') && $cr === 2,
    json_encode([$cu, $neschimbata, $cs, substr_count($listaDupa, "\n") - substr_count($listaInainte, "\n"), $cdupa, $cinapoi, $cr])
    . ' ' . mb_substr($ou . $os . $odupa, 0, 600));
$sterge($copieL);
