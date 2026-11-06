<?php

declare(strict_types=1);

/**
 * Pluginul `concurenta` (din Biovitality; docs/PLUGINURI.md, „Analiza prețurilor concurenței”) față de codul Biovitality însuși, la
 * „ultimul commit revizuit” din docs/de-portat.md.
 *
 *   tools/dev/php.sh tools/dev/test-concurenta.php
 *
 * Două baze cu structura codului Biovitality (tools/dev/migrari/vechi/bv.sql), aceleași rânduri inventate (rândurile demo ale bazei de
 * dezvoltare, trei parteneri, celule cu prețuri, campanii, stoc, linkuri). Pe a doua migrările codului comun cu `--site=biovitality`,
 * ca la adoptare. Apoi, ca un administrator, pe codul Biovitality și pe codul comun:
 *
 * A  adoptarea: competition_partners și competition_prices au după migrări aceeași structură și aceleași rânduri; concurenta/0060
 *    „detectata”;
 * B  aceleași acțiuni pe ambele coduri: tabelul (partea paginii de sub antet, octet cu octet, fără jetonul formularului), adăugarea,
 *    editarea și refuzurile unui partener, salvarea pe celulă (JSON: sumele cu virgulă și cu punct, datele, bifele, linkul fără schemă,
 *    un câmp necunoscut), „Salvează tot”, exportul Excel (fiecare foaie, celulă cu celulă), ștergerea unui partener, golirea tabelului:
 *    aceleași răspunsuri (codul, Location, JSON-ul) și aceleași rânduri în tabele după fiecare; drepturile rolurilor;
 * C  bifa „Doar în magazin” (only_in_store): pe codul comun se vede după reîncărcare și în export; în codul Biovitality nu ajungea în
 *    celulă (singura diferență, declarată aici, exact pe celula ei);
 * D  doar pe codul comun: fiecare POST fără jetonul formularului (CSRF) e refuzat, nimic schimbat; numele partenerului în confirm()
 *    ca JSON (revizia de securitate, M4; în B confirmările se compară după textul văzut de om); pluginul oprit.
 *
 * Își face și își șterge bazele cms_cc_vechi, cms_cc_nou și dosarul $CMS_DEV_DIR/concurenta. Raportul:
 * $CMS_DEV_DIR/test-concurenta-raport.txt; cod de ieșire 0 doar dacă totul a trecut. Toate datele sunt inventate.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-concurenta-raport.txt';
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
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . mb_substr($detalii, 0, 3000) : ''));
    }
};
$scrie('Test concurenta (analiza prețurilor concurenței) — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

$sock = (string) (getenv('CMS_DEV_SOCK') ?: '/tmp/claude-0/maria/sock');
$bazaDev = (string) getenv('DB_DATABASE');
$baze = ['vechi' => 'cms_cc_vechi', 'nou' => 'cms_cc_nou'];
$dosarTest = $dosarDev . '/concurenta';
$pachetCopie = $dosarTest . '/pachet';
$dosarLicenta = $dosarTest . '/licenta';
$portBaza = (int) (getenv('CMS_DEV_PORT') ?: 8701);
$porturi = ['vechi' => $portBaza + 48, 'nou' => $portBaza + 49];
$servere = [];
$parolaAdmin = (string) getenv('ADMIN_DEFAULT_PASSWORD');

$conectare = static function (?string $db = null) use ($sock): PDO {
    return new PDO('mysql:unix_socket=' . $sock . ($db !== null ? ';dbname=' . $db : '') . ';charset=utf8mb4', (string) (getenv('CMS_DEV_DB_USER') ?: 'root'),
        (string) (getenv('CMS_DEV_DB_PAROLA') ?: ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
};
$opresteServerele = static function () use (&$servere): void {
    foreach ($servere as $pid) {
        // Doar procesele pornite aici, după pid (și copiii lor, lucrătorii lui php -S).
        exec('pkill -TERM -P ' . (int) $pid . ' 2>/dev/null; kill ' . (int) $pid . ' 2>/dev/null');
    }
    $servere = [];
};
$curata = static function () use ($conectare, $baze, $dosarTest, $dosarDev, $opresteServerele): void {
    $opresteServerele();
    try {
        $root = $conectare();
        foreach ($baze as $b) {
            $root->exec('DROP DATABASE IF EXISTS ' . $b);
        }
    } catch (Throwable) {
    }
    if (str_starts_with($dosarTest, $dosarDev . '/') && is_dir($dosarTest)) {
        exec('chmod -R u+rwX ' . escapeshellarg($dosarTest) . ' 2>/dev/null; rm -rf ' . escapeshellarg($dosarTest));
    }
};
register_shutdown_function($curata);
$curata();
mkdir($dosarTest, 0770, true);

$proces = static function (array $comanda, array $mediu) use ($radacina): array {
    $p = proc_open($comanda, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tevi, $radacina, $mediu);
    if (!is_resource($p)) {
        return ['cod' => -1, 'iesire' => '', 'tot' => 'nu pornește'];
    }
    $iesire = (string) stream_get_contents($tevi[1]);
    $erori = (string) stream_get_contents($tevi[2]);
    fclose($tevi[1]);
    fclose($tevi[2]);
    return ['cod' => proc_close($p), 'iesire' => $iesire, 'tot' => $iesire . $erori];
};
$ini = ['-d', 'pdo_mysql.default_socket=' . $sock, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'memory_limit=512M', '-d', 'allow_url_fopen=0'];
$mediu = static function (string $parte, array $inPlus = []) use ($baze, $pachetCopie, $dosarLicenta): array {
    $m = array_merge(getenv(), ['DB_DATABASE' => $baze[$parte], 'SITE_DATE_DOSAR' => $pachetCopie, 'LICENTA_DOSAR' => $dosarLicenta,
        'NO_PROXY' => '*', 'no_proxy' => '*'], $inPlus);
    foreach (['http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'all_proxy', 'CMS_DEV_MARCAJE_PORT'] as $k) {
        unset($m[$k]);
    }
    return array_filter($m, static fn ($v): bool => $v !== null);
};
$php = static fn (string $parte, string $script, array $argumente): array
    => $proces(array_merge([PHP_BINARY], $ini, [$radacina . '/' . $script], $argumente), $mediu($parte));
$coloane = static function (PDO $db, string $tabel): array {
    $s = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
    $s->execute([$tabel]);
    return array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN));
};
$tabeleCc = ['competition_partners', 'competition_prices'];
/** Rândurile celor două tabele, fără momente (created_at, updated_at). */
$starea = static function (string $parte) use ($conectare, $baze): array {
    $db = $conectare($baze[$parte]);
    return [
        'parteneri' => $db->query('SELECT id, name, website, has_campaign, campaign_start, campaign_end, is_active, sort_order FROM competition_partners
            ORDER BY id')->fetchAll(),
        'preturi' => $db->query('SELECT partner_id, product_id, price, campaign_active, campaign_price, campaign_start, campaign_end, out_of_stock,
            only_in_store, product_url FROM competition_prices ORDER BY partner_id, product_id')->fetchAll(),
    ];
};

$repoBv = (string) (getenv('CMS_DEV_REPO_BV') ?: dirname($radacina) . '/Mutare-Site-Biovitality');
$commitBv = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($radacina . '/tools/dev/de-portat.php') . ' --revizuit=biovitality'));
$codVechi = $dosarTest . '/cod-bv';

try {
    if (preg_match('/^cms_[a-z0-9_]+$/', $bazaDev) !== 1 || (int) $conectare($bazaDev)->query('SELECT COUNT(*) FROM products')->fetchColumn() < 6) {
        throw new RuntimeException('baza de dezvoltare (DB_DATABASE) lipsește sau e goală: rulează întâi tools/dev/instaleaza.sh');
    }
    if ($parolaAdmin === '' || preg_match('/^[0-9a-f]{7,40}$/', $commitBv) !== 1 || !is_dir($repoBv)) {
        throw new RuntimeException('ADMIN_DEFAULT_PASSWORD (tools/dev/php.sh), clona Biovitality (CMS_DEV_REPO_BV) sau commit-ul ei revizuit lipsește');
    }
    mkdir($codVechi, 0770, true);
    exec('git -C ' . escapeshellarg($repoBv) . ' archive ' . escapeshellarg($commitBv) . ' | tar -x -C ' . escapeshellarg($codVechi), $o, $cod);
    foreach (['storage/sessions', 'storage/cache/pages', 'storage/logs'] as $d) {
        @mkdir($codVechi . '/' . $d, 0770, true);
    }
    $scrie('');
    $scrie('Pregătirea');
    $verifica('codul Biovitality @' . $commitBv . ' scos din ' . basename($repoBv), $cod === 0 && is_file($codVechi . '/views/admin/competition.php'));

    exec('mkdir -p ' . escapeshellarg($pachetCopie) . ' ' . escapeshellarg($dosarLicenta) . ' && cp -a ' . escapeshellarg($radacina . '/site-uri/biovitality/.')
        . ' ' . escapeshellarg($pachetCopie) . ' && rm -rf ' . escapeshellarg($pachetCopie . '/privat'));
    $textInghetare = (string) file_get_contents($pachetCopie . '/setari-inghetate.json');
    $inghetare = json_decode($textInghetare, true);
    $privat = ['site' => $inghetare['site'], 'sursa' => $inghetare['sursa'], 'pentru' => hash('sha256', str_replace("\r\n", "\n", $textInghetare)), 'setari' => []];
    $cheiLipsa = [];
    foreach ((array) $inghetare['setari'] as $cheie => $intrare) {
        if (($intrare['privat'] ?? false) === true) {
            $privat['setari'][$cheie] = str_contains((string) $cheie, 'telefon') ? '0700 000 009' : 'test.' . $cheie . '@exemplu.ro';
        }
        if (($intrare['mod'] ?? '') === 'lipsa') {
            $cheiLipsa[] = (string) $cheie;
        }
    }
    mkdir($pachetCopie . '/privat', 0700, true);
    file_put_contents($pachetCopie . '/privat/setari-inghetate.json', (string) json_encode($privat));

    // ── Cele două baze, cu partenerii și celulele unui site în lucru ──
    $root = $conectare();
    preg_match_all('/^CREATE TABLE `([^`]+)`.*?;$/ms', (string) file_get_contents($radacina . '/tools/dev/migrari/vechi/bv.sql'), $creari, PREG_SET_ORDER);
    $dev = $conectare($bazaDev);
    $azi = date('Y-m-d');
    $ieri = date('Y-m-d', time() - 86400);
    $maine = date('Y-m-d', time() + 86400);
    $produse = [];
    foreach ($baze as $parte => $numeBaza) {
        $root->exec('CREATE DATABASE ' . $numeBaza . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $db = $conectare($numeBaza);
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($creari as [$sql]) {
            $db->exec($sql);
        }
        foreach ($creari as [, $tabel]) {
            $comune = array_values(array_intersect($coloane($db, $tabel), $coloane($dev, $tabel)));
            if ($comune === [] || $tabel === 'customer_email_confirmations' || in_array($tabel, $tabeleCc, true)) {
                continue;
            }
            $lista = '`' . implode('`, `', $comune) . '`';
            $unde = $tabel === 'settings' ? ' WHERE `key` NOT IN (' . implode(', ', array_map([$db, 'quote'], $cheiLipsa)) . ')' : '';
            $db->exec('INSERT INTO `' . $tabel . '` (' . $lista . ') SELECT ' . $lista . ' FROM `' . $bazaDev . '`.`' . $tabel . '`' . $unde);
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
        $produse = array_map('intval', $db->query('SELECT id FROM products WHERE deleted_at IS NULL ORDER BY name, id LIMIT 6')->fetchAll(PDO::FETCH_COLUMN));
        // Un produs cu preț redus, ca prețul nostru să fie cel plătit, nu cel de listă.
        $db->prepare('UPDATE products SET sale_price = ROUND(price * 0.8, 2) WHERE id = ?')->execute([$produse[1]]);
        $p = $db->prepare('INSERT INTO competition_partners (id, name, website, has_campaign, campaign_start, campaign_end, is_active, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $p->execute([1, 'Farmacia Demo', 'https://farmacia.exemplu.ro', 1, $ieri, $maine, 1, 1]);
        $p->execute([2, 'Magazin Exemplu', null, 0, null, null, 1, 2]);
        $p->execute([3, 'Partener Inactiv', 'https://inactiv.exemplu.ro', 0, null, null, 0, 3]);
        $c = $db->prepare('INSERT INTO competition_prices (partner_id, product_id, price, campaign_active, campaign_price, campaign_start, campaign_end,
            out_of_stock, only_in_store, product_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $c->execute([1, $produse[0], 99.90, 1, 79.90, null, null, 0, 0, 'https://farmacia.exemplu.ro/p1']);
        $c->execute([1, $produse[1], 10.00, 1, 8.00, '2020-01-01', '2020-02-01', 0, 0, null]);
        $c->execute([2, $produse[0], 120.00, 0, 100.00, null, null, 1, 0, null]);
        $c->execute([2, $produse[2], 5.50, 0, null, null, null, 0, 0, 'https://magazin.exemplu.ro/x']);
        $c->execute([3, $produse[0], 50.00, 0, null, null, null, 0, 0, null]);
    }
    $verifica('bazele cms_cc_vechi și cms_cc_nou: structura codului Biovitality, rândurile demo, 3 parteneri (unul inactiv, unul cu perioadă '
        . 'implicită de campanie), 5 celule (campanie activă, campanie trecută, stoc epuizat, linkuri), un produs cu preț redus', count($produse) === 6);

    // ── A: adoptarea ──
    $scrie('');
    $scrie('A. Adoptarea: migrările nu ating tabelele pluginului');
    $instantaneu = static function (PDO $db) use ($tabeleCc): array {
        $r = [];
        foreach ($tabeleCc as $t) {
            $r[$t] = [preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) ($db->query('SHOW CREATE TABLE `' . $t . '`')->fetch(PDO::FETCH_NUM)[1] ?? '')),
                $db->query('SELECT * FROM `' . $t . '` ORDER BY id')->fetchAll()];
        }
        return $r;
    };
    $dbNou = $conectare($baze['nou']);
    $inainte = $instantaneu($dbNou);
    $r = $php('nou', 'scripts/migreaza.php', ['--aplica', '--site=biovitality']);
    $verifica('scripts/migreaza.php --aplica --site=biovitality: cod 0', $r['cod'] === 0, mb_substr($r['tot'], -2000));
    $dupa = $instantaneu($dbNou);
    foreach ($tabeleCc as $t) {
        $verifica('A ' . $t . ': aceeași structură și aceleași ' . count($inainte[$t][1]) . ' rânduri după migrări', $inainte[$t] === $dupa[$t],
            json_encode([$inainte[$t][0], $dupa[$t][0]]) ?: '');
    }
    $rand0060 = $dbNou->query("SELECT rezultat, operatii FROM _platforma_migrari WHERE id = 'concurenta/0060_concurenta'")->fetch();
    $verifica('A concurenta/0060 „detectata”, 0 operații', is_array($rand0060) && $rand0060['rezultat'] === 'detectata' && (int) $rand0060['operatii'] === 0,
        json_encode($rand0060) ?: 'lipsă');
    $r = $php('nou', 'tools/dev/licenta.php', ['biovitality']);
    $verifica('licența de dezvoltare a Biovitality (cu concurenta)', $r['cod'] === 0, $r['tot']);

    $porneste = static function (string $cod, int $port, array $env) use (&$servere, $dosarTest, $ini, $radacina): bool {
        $jurnal = $dosarTest . '/server-' . $port . '.log';
        $p = proc_open(array_merge([PHP_BINARY], $ini, ['-S', '127.0.0.1:' . $port, '-t', $cod . '/public', $radacina . '/tools/dev/router.php']),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $jurnal, 'a'], 2 => ['file', $jurnal, 'a']], $tevi, $cod, $env);
        $servere[] = (int) proc_get_status($p)['pid'];
        for ($i = 0; $i < 100; $i++) {
            $c = curl_init('http://127.0.0.1:' . $port . '/robots.txt');
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
            curl_exec($c);
            $raspuns = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
            curl_close($c);
            if ($raspuns > 0) {
                return true;
            }
            usleep(100000);
        }
        return false;
    };
    $pornite = $porneste($codVechi, $porturi['vechi'], $mediu('vechi', ['APP_URL' => 'http://127.0.0.1:' . $porturi['vechi'], 'PHP_CLI_SERVER_WORKERS' => '4']))
        && $porneste($radacina, $porturi['nou'], $mediu('nou', ['APP_URL' => 'http://127.0.0.1:' . $porturi['nou'], 'PHP_CLI_SERVER_WORKERS' => '4']));
    $verifica('serverele pornesc: codul Biovitality (+48) și codul comun (+49)', $pornite);
    if (!$pornite) {
        throw new RuntimeException('serverele nu pornesc (jurnalele în ' . $dosarTest . ')');
    }

    /** O sesiune de admin pe un cod: cereri cu cookie-uri, jetonul CSRF citit din pagină (doar pe codul comun). */
    $sesiune = static function (string $parte, string $email) use ($porturi, $parolaAdmin, $dosarTest): Closure {
        $borcan = $dosarTest . '/cookies-' . $parte . '-' . md5($email . microtime()) . '.txt';
        $jeton = '';
        $c = static function (string $metoda, string $cale, array $campuri = [], ?string $json = null, array $antete = []) use ($porturi, $parte, $borcan, &$jeton): array {
            $h = curl_init('http://127.0.0.1:' . $porturi[$parte] . $cale);
            curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_COOKIEJAR => $borcan,
                CURLOPT_COOKIEFILE => $borcan, CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_HEADER => true]);
            if ($json !== null) {
                curl_setopt($h, CURLOPT_POSTFIELDS, $json);
                $antete[] = 'Content-Type: application/json';
            } elseif ($metoda === 'POST') {
                curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($campuri));
            }
            curl_setopt($h, CURLOPT_HTTPHEADER, $antete);
            $tot = (string) curl_exec($h);
            $cod = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
            $marime = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
            curl_close($h);
            $antet = substr($tot, 0, $marime);
            $corp = substr($tot, $marime);
            if (preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $corp, $m) === 1) {
                $jeton = $m[1];
            }
            return ['cod' => $cod, 'corp' => $corp, 'tip' => preg_match('/^Content-Type: (.+)$/mi', $antet, $m) === 1 ? trim($m[1]) : '',
                'location' => preg_match('/^Location: (.+)$/mi', $antet, $m) === 1 ? (string) preg_replace('#^https?://[^/]+#', '', trim($m[1])) : '',
                'jeton' => $jeton];
        };
        $c('GET', '/admin/login');
        $c('POST', '/admin/login', ['email' => $email, 'password' => $parolaAdmin]);
        return $c;
    };
    $adm = ['vechi' => $sesiune('vechi', 'admin@exemplu.ro'), 'nou' => $sesiune('nou', 'admin@exemplu.ro')];
    /** Partea paginii de sub antet (cardurile partenerilor, tabelul, subsolul), fără jetonul formularului. */
    $tabelul = static function (string $html): string {
        $i = strpos($html, '<!-- Parteneri: fiecare adaugă în tabel o pereche de coloane -->');
        $j = strpos($html, '</section>', (int) $i);
        $parte = $i !== false && $j !== false ? substr($html, $i, $j - $i) : '';
        $parte = (string) preg_replace('/\n\s*<input type="hidden" name="_csrf" value="[0-9a-f]{64}">/', '', $parte);
        // Confirmarea ștergerii unui partener: codul Biovitality o scrie ca șir JS între apostrofuri (un nume cu apostrof ieșea din
        // șir), codul comun ca JSON (revizia de securitate, M4). Se compară textul pe care îl vede omul.
        return (string) preg_replace_callback('/onsubmit="return confirm\((\'.*?\'|&quot;.*?&quot;)\);"/u', static function (array $m): string {
            $brut = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = str_starts_with($brut, "'") ? substr($brut, 1, -1) : (string) json_decode($brut);
            return 'onsubmit="return confirm(«' . htmlspecialchars($text, ENT_QUOTES) . '»);"';
        }, $parte);
    };
    $jetonNou = static function () use ($adm): string {
        return (string) $adm['nou']('GET', '/admin/competition')['jeton'];
    };

    // ── B: aceleași acțiuni pe ambele coduri ──
    $scrie('');
    $scrie('B. Analiza competiției: aceleași pagini, răspunsuri și rânduri pe codul Biovitality și pe codul comun');
    $pagina = ['vechi' => $adm['vechi']('GET', '/admin/competition'), 'nou' => $adm['nou']('GET', '/admin/competition')];
    $tv = $tabelul($pagina['vechi']['corp']);
    $tn = $tabelul($pagina['nou']['corp']);
    $verifica('B pagina: 200 pe ambele, partea de sub antet (parteneri, tabelul cu prețurile efective, campaniile, diferențele) identică octet cu '
        . 'octet (' . strlen($tn) . ' octeți, fără jetonul formularului)', $pagina['vechi']['cod'] === 200 && $pagina['nou']['cod'] === 200 && $tv !== ''
        && $tv === $tn, 'diferă de la octetul ' . strspn($tv ^ $tn, "\0"));
    $verifica('B pagina: antetul „Analiza competiție”, exportul și golirea, cu jetonul pe codul comun (și în scriptul celulelor, antetul X-CSRF-Token)', str_contains($pagina['nou']['corp'], 'Analiza competiție')
        && str_contains($pagina['nou']['corp'], 'href="/admin/competition/export"') && $pagina['nou']['jeton'] !== ''
        && str_contains($pagina['nou']['corp'], "'X-CSRF-Token': \"" . $pagina['nou']['jeton'] . '"'));
    $pas = static function (string $eticheta, string $metoda, string $cale, array $campuri = [], ?array $json = null) use ($adm, $jetonNou, $starea, $verifica): array {
        $raspunsuri = [];
        foreach (['vechi', 'nou'] as $parte) {
            $c = $campuri;
            $antete = [];
            if ($parte === 'nou') {
                $jeton = $jetonNou();
                $json !== null ? $antete[] = 'X-CSRF-Token: ' . $jeton : $c['_csrf'] = $jeton;
            }
            $r = $adm[$parte]($metoda, $cale, $c, $json !== null ? (string) json_encode($json) : null, $antete);
            $raspunsuri[$parte] = ['cod' => $r['cod'], 'location' => $r['location'], 'corp' => $json !== null ? $r['corp'] : ''];
        }
        $sv = $starea('vechi');
        $sn = $starea('nou');
        $verifica('B ' . $eticheta . ' → ' . $raspunsuri['nou']['cod'] . ($raspunsuri['nou']['location'] !== '' ? ' ' . $raspunsuri['nou']['location'] : '')
            . ($json !== null ? ' ' . $raspunsuri['nou']['corp'] : '') . '; aceleași rânduri (' . count($sn['parteneri']) . ' parteneri, ' . count($sn['preturi'])
            . ' celule)', $raspunsuri['vechi'] === $raspunsuri['nou'] && $sv === $sn, json_encode([$raspunsuri, $sv === $sn ? '' : [$sv, $sn]],
            JSON_UNESCAPED_UNICODE) ?: '');
        return $raspunsuri['nou'];
    };
    $pas('partener nou (site fără schemă, campanie cu perioadă)', 'POST', '/admin/competition/partners', ['id' => '0', 'name' => 'Concurent Nou',
        'website' => 'concurent.exemplu.ro', 'has_campaign' => '1', 'campaign_start' => $azi, 'campaign_end' => $maine, 'is_active' => '1', 'sort_order' => '4']);
    $pas('partener fără nume (refuzat)', 'POST', '/admin/competition/partners', ['id' => '0', 'name' => '  ', 'website' => '', 'sort_order' => '0']);
    $pas('partener cu perioada inversată (refuzat, înapoi la editare)', 'POST', '/admin/competition/partners', ['id' => '2', 'name' => 'Magazin Exemplu',
        'campaign_start' => $maine, 'campaign_end' => $ieri, 'is_active' => '1']);
    $pas('editarea unui partener (scos din tabel: inactiv)', 'POST', '/admin/competition/partners', ['id' => '2', 'name' => 'Magazin Exemplu 2',
        'website' => 'http://magazin.exemplu.ro', 'sort_order' => '9']);
    $pas('editarea la loc (activ)', 'POST', '/admin/competition/partners', ['id' => '2', 'name' => 'Magazin Exemplu', 'is_active' => '1', 'sort_order' => '2']);
    [$p0, $p1, $p2, $p3] = $produse;
    $pas('celula: prețul „1.234,50”', 'POST', '/admin/competition/cell', [], ['partner_id' => 1, 'product_id' => $p2, 'field' => 'price', 'value' => '1.234,50']);
    $pas('celula: prețul „1,234.5”', 'POST', '/admin/competition/cell', [], ['partner_id' => 2, 'product_id' => $p3, 'field' => 'price', 'value' => '1,234.5']);
    $pas('celula: prețul 0 (= neaflat, gol)', 'POST', '/admin/competition/cell', [], ['partner_id' => 2, 'product_id' => $p2, 'field' => 'price', 'value' => '0']);
    $pas('celula: campania bifată', 'POST', '/admin/competition/cell', [], ['partner_id' => 2, 'product_id' => $p3, 'field' => 'campaign_active', 'value' => '1']);
    $pas('celula: prețul de campanie', 'POST', '/admin/competition/cell', [], ['partner_id' => 2, 'product_id' => $p3, 'field' => 'campaign_price', 'value' => '999,9']);
    $pas('celula: începutul campaniei', 'POST', '/admin/competition/cell', [], ['partner_id' => 2, 'product_id' => $p3, 'field' => 'campaign_start', 'value' => $ieri]);
    $pas('celula: o dată greșită (golită)', 'POST', '/admin/competition/cell', [], ['partner_id' => 2, 'product_id' => $p3, 'field' => 'campaign_end', 'value' => '31.12.2026']);
    $pas('celula: stoc epuizat', 'POST', '/admin/competition/cell', [], ['partner_id' => 1, 'product_id' => $p2, 'field' => 'out_of_stock', 'value' => 'true']);
    $pas('celula: linkul fără schemă', 'POST', '/admin/competition/cell', [], ['partner_id' => 1, 'product_id' => $p2, 'field' => 'product_url',
        'value' => 'farmacia.exemplu.ro/produs?id=3']);
    $pas('celula: un link care nu e link (gol)', 'POST', '/admin/competition/cell', [], ['partner_id' => 2, 'product_id' => $p3, 'field' => 'product_url',
        'value' => 'nu e un link']);
    $pas('celula: un câmp necunoscut (refuzat, nimic scris)', 'POST', '/admin/competition/cell', [], ['partner_id' => 1, 'product_id' => $p2, 'field' => 'name',
        'value' => 'x']);
    $pas('celula: fără produs (refuzat)', 'POST', '/admin/competition/cell', [], ['partner_id' => 1, 'product_id' => 0, 'field' => 'price', 'value' => '1']);
    $pagina = ['vechi' => $adm['vechi']('GET', '/admin/competition'), 'nou' => $adm['nou']('GET', '/admin/competition')];
    $tv = $tabelul($pagina['vechi']['corp']);
    $tn = $tabelul($pagina['nou']['corp']);
    $verifica('B pagina după salvări (campanii active, trecute, perioada implicită a partenerului, prețul redus al nostru): identică', $tv === $tn && $tv !== '',
        'diferă de la octetul ' . strspn($tv ^ $tn, "\0"));
    $pas('„Salvează tot” (fără JavaScript)', 'POST', '/admin/competition/save-all', ['cells' => [
        1 => [$p3 => ['price' => '12,40', 'campaign_active' => '0', 'out_of_stock' => '0', 'product_url' => ''], $p0 => ['price' => '98.10']],
        2 => [$p0 => ['out_of_stock' => '0', 'campaign_price' => ''], $p3 => ['campaign_active' => '0']],
        0 => [$p0 => ['price' => '1']], 'x' => ['y' => 'z']]]);
    $pas('„Salvează tot” gol', 'POST', '/admin/competition/save-all', []);

    // Exportul: fiecare foaie, celulă cu celulă (fișierul .xlsx, despachetat).
    $foi = static function (string $continut, string $nume) use ($dosarTest): array {
        $fisier = $dosarTest . '/' . $nume . '.xlsx';
        file_put_contents($fisier, $continut);
        $zip = new ZipArchive();
        $r = [];
        if ($zip->open($fisier) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $intrare = (string) $zip->getNameIndex($i);
                if (str_starts_with($intrare, 'xl/worksheets/') || $intrare === 'xl/sharedStrings.xml' || $intrare === 'xl/workbook.xml') {
                    $r[$intrare] = (string) $zip->getFromIndex($i);
                }
            }
            $zip->close();
        }
        ksort($r);
        return $r;
    };
    $ev = $adm['vechi']('GET', '/admin/competition/export');
    $en = $adm['nou']('GET', '/admin/competition/export');
    $xv = $foi($ev['corp'], 'export-vechi');
    $xn = $foi($en['corp'], 'export-nou');
    $verifica('B exportul Excel: 200, ' . count($xn) . ' părți (foile „Comparație prețuri” și „Parteneri”), aceleași celule', $ev['cod'] === 200 && $en['cod'] === 200
        && count($xn) >= 3 && $xv === $xn && str_contains(implode('', $xn), 'Comparație prețuri'), json_encode(array_keys($xn)) ?: '');

    // ── C: bifa „Doar în magazin” ──
    $scrie('');
    $scrie('C. „Doar în magazin” (only_in_store): reparat în codul comun');
    $pas('celula: „Doar în magazin” bifat', 'POST', '/admin/competition/cell', [], ['partner_id' => 1, 'product_id' => $p0, 'field' => 'only_in_store', 'value' => '1']);
    $pagina = ['vechi' => $adm['vechi']('GET', '/admin/competition'), 'nou' => $adm['nou']('GET', '/admin/competition')];
    $tv = $tabelul($pagina['vechi']['corp']);
    $tn = $tabelul($pagina['nou']['corp']);
    // Cele trei urme ale bifei în pagină (clasa „on” a etichetei, „checked”, eticheta „Doar în magazinul fizic” din celula prețului),
    // scoase din ambele pagini: restul trebuie să fie identic, iar urmele să fie doar pe codul comun, o dată, exact pe celula bifată.
    $faraBifa = static fn (string $html): string => (string) preg_replace(['#<label class="cmp-only-store (on)?"#',
        '#(name="cells\[\d+\]\[\d+\]\[only_in_store\]" value="1") (checked)?\s*#', '#<span class="cmp-chip cmp-chip--store" (hidden)?>#'],
        ['<label class="cmp-only-store "', '$1 ', '<span class="cmp-chip cmp-chip--store">'], $html);
    $urme = static fn (string $html): array => [substr_count($html, '<label class="cmp-only-store on"'),
        preg_match_all('#name="cells\[1\]\[' . $p0 . '\]\[only_in_store\]" value="1" checked#', $html),
        preg_match_all('#\[only_in_store\]" value="1" checked#', $html), substr_count($html, '<span class="cmp-chip cmp-chip--store" >')];
    $verifica('C pagina: pe codul comun bifa se vede după reîncărcare (eticheta „on”, „checked”, „Doar în magazinul fizic”), o dată, pe celula '
        . 'bifată; în codul Biovitality nu; altfel pagina e identică', $faraBifa($tn) === $faraBifa($tv) && $urme($tn) === [1, 1, 1, 1]
        && $urme($tv) === [0, 0, 0, 0], json_encode([$urme($tn), $urme($tv)]) ?: '');
    $xv = $foi($adm['vechi']('GET', '/admin/competition/export')['corp'], 'export-vechi-2');
    $xn = $foi($adm['nou']('GET', '/admin/competition/export')['corp'], 'export-nou-2');
    $textXlsx = static fn (array $x): string => implode('', $x);
    $verifica('C exportul: pe codul comun „Doar în magazin” la celula bifată, în codul Biovitality „Disponibil”',
        str_contains($textXlsx($xn), 'Doar în magazin') && !str_contains($textXlsx($xv), 'Doar în magazin'));

    // ── B (continuare): ștergerea, golirea, rolurile ──
    $scrie('');
    $scrie('B. Ștergerea, golirea, rolurile');
    $pas('ștergerea partenerului 3, cu prețurile lui', 'POST', '/admin/competition/partners/3/delete');
    $pas('ștergerea unui partener care nu există', 'POST', '/admin/competition/partners/999/delete');
    $pas('golirea tabelului (partenerii rămân)', 'POST', '/admin/competition/clear');
    $pas('golirea unui tabel gol', 'POST', '/admin/competition/clear');
    foreach (['magazin@exemplu.ro' => 200, 'blog@exemplu.ro' => 302, 'emailuri@exemplu.ro' => 302] as $email => $asteptat) {
        $coduri = [];
        foreach (['vechi', 'nou'] as $parte) {
            $coduri[$parte] = $sesiune($parte, $email)('GET', '/admin/competition')['cod'];
        }
        $verifica('B ' . $email . ': /admin/competition → ' . $asteptat . ' pe ambele coduri', $coduri['vechi'] === $asteptat && $coduri['nou'] === $asteptat,
            json_encode($coduri) ?: '');
    }

    // ── D: doar pe codul comun ──
    $scrie('');
    $scrie('D. Doar pe codul comun: CSRF, pluginul oprit');
    $inainte = $starea('nou');
    $x = $adm['nou']('POST', '/admin/competition/partners', ['id' => '0', 'name' => 'Fără jeton', 'is_active' => '1']);
    $verifica('D partener nou fără jeton: refuzat (302 la tabel), nimic scris', $x['cod'] === 302 && $x['location'] === '/admin/competition'
        && $starea('nou') === $inainte);
    $x = $adm['nou']('POST', '/admin/competition/cell', [], (string) json_encode(['partner_id' => 1, 'product_id' => $p0, 'field' => 'price', 'value' => '5']));
    $verifica('D celula fără antetul X-CSRF-Token: 403 JSON, nimic scris', $x['cod'] === 403 && ($j = json_decode($x['corp'], true)) !== null
        && ($j['ok'] ?? null) === false && $starea('nou') === $inainte, $x['cod'] . ' ' . $x['corp']);
    $x = $adm['nou']('POST', '/admin/competition/cell', [], (string) json_encode(['partner_id' => 1, 'product_id' => $p0, 'field' => 'price', 'value' => '5']),
        ['X-CSRF-Token: ' . str_repeat('a', 64)]);
    $verifica('D celula cu un jeton greșit: 403', $x['cod'] === 403);
    foreach (['/admin/competition/clear', '/admin/competition/save-all', '/admin/competition/partners/1/delete'] as $cale) {
        $x = $adm['nou']('POST', $cale, $cale === '/admin/competition/save-all' ? ['cells' => [1 => [$p0 => ['price' => '7']]]] : []);
        $verifica('D ' . $cale . ' fără jeton: refuzat, nimic schimbat', $x['cod'] === 302 && $starea('nou') === $inainte);
    }
    // M4: numele partenerului ajunge în confirm() ca JSON, nu ca text care poate închide șirul JS.
    $adm['nou']('POST', '/admin/competition/partners', ['id' => '0', 'name' => "O'Brien \"<b>\" & Co", 'website' => '', 'is_active' => '1',
        'sort_order' => '9', '_csrf' => $jetonNou()]);
    $x = $adm['nou']('GET', '/admin/competition');
    $verifica('D M4 confirmarea ștergerii unui partener cu apostrof și ghilimele în nume: JSON (\\u0027, \\u0022, \\u003C, \\u0026)',
        str_contains($x['corp'], 'confirm(&quot;Ștergi partenerul „O\\u0027Brien \\u0022\\u003Cb\\u003E\\u0022 \\u0026 Co”')
        && !str_contains($x['corp'], "confirm('Ștergi partenerul „O"));
    $inainte = $starea('nou');
    $php('nou', 'tools/dev/licenta.php', ['biovitality', 'concurenta=oprit']);
    $x = $adm['nou']('GET', '/admin/competition');
    $verifica('D pluginul oprit: /admin/competition → 403 „Modulul nu este inclus în abonament”', $x['cod'] === 403 && str_contains($x['corp'], 'nu este inclus'));
    $x = $adm['nou']('GET', '/admin/products');
    $verifica('D pluginul oprit: meniul fără „Analiza competiție”', $x['cod'] === 200 && !str_contains($x['corp'], 'Analiza competiție')
        && !str_contains($x['corp'], 'href="/admin/competition"'));
    $verifica('D pluginul oprit: partenerii rămân în bază', count($starea('nou')['parteneri']) === count($inainte['parteneri']) && $inainte['parteneri'] !== []);
} catch (Throwable $e) {
    $verifica('testul a rulat până la capăt', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

$opresteServerele();
$scrie('');
$scrie('Rezultat: ' . $trecute . ' trecute, ' . $esuate . ' eșuate. Raport: ' . $fisierRaport);
exit($esuate === 0 ? 0 : 1);
