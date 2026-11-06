<?php

declare(strict_types=1);

/**
 * Pluginul `fisiere_cloud` (din Biovitality; docs/PLUGINURI.md, „Fișiere în cloud”) față de codul Biovitality însuși, la
 * „ultimul commit revizuit” din docs/de-portat.md: aplicația Windows instalată pe calculatoarele firmei (desktop/BiovitalityFiles
 * din repo-ul Biovitality) trebuie să meargă neschimbată pe codul comun.
 *
 *   tools/dev/php.sh tools/dev/test-fisiere-cloud.php
 *
 * Două baze cu structura codului Biovitality (tools/dev/migrari/vechi/bv.sql) și aceleași rânduri inventate (rândurile demo ale
 * bazei de dezvoltare, plus ce are un site cu pluginul: chei de aplicație, un folder cu parolă, o deblocare, un lacăt, o versiune,
 * setările bunny_*). Pe a doua rulează migrările codului comun cu `--site=biovitality`, ca la adoptare; pe fiecare, câte o zonă
 * bunny.net falsă (tools/dev/lib/bunny-fals.php) cu aceleași fișiere. Apoi:
 *
 * A  adoptarea: cele cinci tabele (admin_app_tokens, file_locks, file_versions, bunny_protected_folders, bunny_folder_unlocks) au
 *    după migrări exact structura (SHOW CREATE TABLE) și rândurile de dinainte, setările bunny_* la fel; migrarea fisiere_cloud/0059
 *    e „detectata” (nimic schimbat);
 * B  contractul API-ului aplicației: aceeași listă de cereri (fiecare adresă /api/desktop/*, cu antetele aplicației, Bearer și
 *    X-App-Token, JSON și multipart, ca ApiClient.cs) pe codul Biovitality și pe codul comun, una după alta: același cod de stare,
 *    același Content-Type, același corp (JSON-ul octet cu octet, după ce datele și momentele sunt înlocuite cu un semn; la descărcare
 *    conținutul și antetele Content-Disposition, Content-Length, X-File-Checksum), iar la final aceleași fișiere în zonă și aceleași
 *    rânduri în tabele (fără momente). Fiecare cod de stare al controllerului apare cel puțin o dată (200, 401, 403, 409, 413 nu: 64 MB,
 *    422, 423, 502, 503);
 * C  doar pe codul comun: cu pluginul oprit, /api/desktop/* răspunde cu JSON-ul 404 al gărzii, cu `error` (aplicația îl arată);
 *    garda căilor (`..`); adminul web: paginile, formularele fără jetonul CSRF refuzate (nimic făcut), cu el aceleași efecte ca pe
 *    codul Biovitality, rolul „Cloud user” doar la fișiere (nu Administrator General), parola de folder cu limita de încercări.
 *
 * Își face și își șterge bazele cms_fc_vechi, cms_fc_nou și dosarul $CMS_DEV_DIR/fisiere-cloud. Baza de dezvoltare trebuie să fie
 * făcută de tools/dev/instaleaza.sh (o citește, n-o schimbă). Raportul: $CMS_DEV_DIR/test-fisiere-cloud-raport.txt; cod de ieșire 0
 * doar dacă totul a trecut. Toate datele sunt inventate.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-fisiere-cloud-raport.txt';
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
$scrie('Test fisiere_cloud (aplicația de desktop și adminul) — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

$sock = (string) (getenv('CMS_DEV_SOCK') ?: '/tmp/claude-0/maria/sock');
$bazaDev = (string) getenv('DB_DATABASE');
$baze = ['vechi' => 'cms_fc_vechi', 'nou' => 'cms_fc_nou'];
$dosarTest = $dosarDev . '/fisiere-cloud';
$pachetCopie = $dosarTest . '/pachet';
$dosarLicenta = $dosarTest . '/licenta';
$portBaza = (int) (getenv('CMS_DEV_PORT') ?: 8701);
$porturi = ['vechi' => $portBaza + 44, 'nou' => $portBaza + 45];
$porturiBunny = ['vechi' => $portBaza + 46, 'nou' => $portBaza + 47];
$servere = [];
$zona = 'zona-test';
$cheieZona = 'parola-zonei-de-test';
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

/** O comandă cu mediul dat (proc_open, cu termen). */
$proces = static function (array $comanda, array $mediu, int $termen = 900) use ($radacina): array {
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
$ini = ['-d', 'pdo_mysql.default_socket=' . $sock, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'memory_limit=512M',
    '-d', 'allow_url_fopen=0', '-d', 'upload_max_filesize=80M', '-d', 'post_max_size=90M'];
/** Mediul unei părți: baza ei, pachetul Biovitality, licența testului, zona falsă; fără proxy (cererile spre 127.0.0.1). */
$mediu = static function (string $parte, array $inPlus = []) use ($baze, $pachetCopie, $dosarLicenta, $porturiBunny): array {
    $m = array_merge(getenv(), ['DB_DATABASE' => $baze[$parte], 'SITE_DATE_DOSAR' => $pachetCopie, 'LICENTA_DOSAR' => $dosarLicenta,
        'CMS_DEV_BUNNY_URL' => 'http://127.0.0.1:' . $porturiBunny[$parte], 'NO_PROXY' => '*', 'no_proxy' => '*'], $inPlus);
    foreach (['http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'all_proxy', 'CMS_DEV_MARCAJE_PORT'] as $k) {
        unset($m[$k]);
    }
    foreach ($m as $k => $v) {
        if ($v === null) {
            unset($m[$k]);
        }
    }
    return $m;
};
$php = static fn (string $parte, string $script, array $argumente): array
    => $proces(array_merge([PHP_BINARY], $ini, [$radacina . '/' . $script], $argumente), $mediu($parte));
$coloane = static function (PDO $db, string $tabel): array {
    $s = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
    $s->execute([$tabel]);
    return array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN));
};
$tabeleFc = ['admin_app_tokens', 'file_locks', 'file_versions', 'bunny_protected_folders', 'bunny_folder_unlocks'];
/** Structura și rândurile tabelelor pluginului (cu momentele), plus setările bunny_*: ce nu are voie să schimbe adoptarea. */
$instantaneu = static function (PDO $db) use ($tabeleFc): array {
    $r = [];
    foreach ($tabeleFc as $t) {
        $creare = (string) ($db->query('SHOW CREATE TABLE `' . $t . '`')->fetch(PDO::FETCH_NUM)[1] ?? '');
        $r[$t] = ['creare' => preg_replace('/ AUTO_INCREMENT=\d+/', '', $creare), 'randuri' => $db->query('SELECT * FROM `' . $t . '` ORDER BY id')->fetchAll()];
    }
    $r['settings'] = $db->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'bunny\\_%' ORDER BY `key`")->fetchAll(PDO::FETCH_KEY_PAIR);
    return $r;
};

// ── Codul Biovitality, scos cu git archive, cu zona falsă în locul adresei bunny.net (doar în copia testului) ──
$repoBv = (string) (getenv('CMS_DEV_REPO_BV') ?: dirname($radacina) . '/Mutare-Site-Biovitality');
$commitBv = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($radacina . '/tools/dev/de-portat.php') . ' --revizuit=biovitality'));
$codVechi = $dosarTest . '/cod-bv';
$tokenuri = [];

try {
    if (preg_match('/^cms_[a-z0-9_]+$/', $bazaDev) !== 1 || (int) $conectare($bazaDev)->query('SELECT COUNT(*) FROM admins')->fetchColumn() < 4) {
        throw new RuntimeException('baza de dezvoltare (DB_DATABASE) lipsește sau e goală: rulează întâi tools/dev/instaleaza.sh');
    }
    if ($parolaAdmin === '') {
        throw new RuntimeException('ADMIN_DEFAULT_PASSWORD lipsește (rulează testul cu tools/dev/php.sh)');
    }
    if (preg_match('/^[0-9a-f]{7,40}$/', $commitBv) !== 1 || !is_dir($repoBv)) {
        throw new RuntimeException('clona Biovitality (CMS_DEV_REPO_BV) sau commit-ul ei revizuit lipsește: ' . $repoBv . ' @ ' . $commitBv);
    }
    mkdir($codVechi, 0770, true);
    exec('git -C ' . escapeshellarg($repoBv) . ' archive ' . escapeshellarg($commitBv) . ' | tar -x -C ' . escapeshellarg($codVechi), $o, $cod);
    $fisierBunnyVechi = $codVechi . '/src/Support/BunnyStorage.php';
    $textBunny = (string) @file_get_contents($fisierBunnyVechi);
    $inlocuite = substr_count($textBunny, "'https://' . \$this->storageHost()");
    file_put_contents($fisierBunnyVechi, str_replace("'https://' . \$this->storageHost()", "(getenv('CMS_DEV_BUNNY_URL') ?: 'https://' . \$this->storageHost())",
        $textBunny));
    foreach (['storage/sessions', 'storage/cache/pages', 'storage/logs'] as $d) {
        @mkdir($codVechi . '/' . $d, 0770, true);
    }
    $scrie('');
    $scrie('Pregătirea');
    $verifica('codul Biovitality @' . $commitBv . ' scos din ' . basename($repoBv) . ', cu zona falsă în cele ' . $inlocuite . ' cereri ale BunnyStorage',
        $cod === 0 && $inlocuite === 3 && is_file($codVechi . '/src/Http/Controllers/DesktopApiController.php'));

    // Pachetul Biovitality, într-o copie, cu valori private inventate (ca tools/dev/test-calcule-bv.php).
    exec('mkdir -p ' . escapeshellarg($pachetCopie) . ' ' . escapeshellarg($dosarLicenta) . ' && cp -a ' . escapeshellarg($radacina . '/site-uri/biovitality/.')
        . ' ' . escapeshellarg($pachetCopie) . ' && rm -rf ' . escapeshellarg($pachetCopie . '/privat'));
    $inghetare = json_decode((string) file_get_contents($pachetCopie . '/setari-inghetate.json'), true);
    $textInghetare = (string) file_get_contents($pachetCopie . '/setari-inghetate.json');
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

    // ── Cele două baze: structura codului Biovitality, rândurile demo, ce are un site cu pluginul ──
    $root = $conectare();
    preg_match_all('/^CREATE TABLE `([^`]+)`.*?;$/ms', (string) file_get_contents($radacina . '/tools/dev/migrari/vechi/bv.sql'), $creari, PREG_SET_ORDER);
    $dev = $conectare($bazaDev);
    $hashParola = password_hash($parolaAdmin, PASSWORD_DEFAULT);
    foreach (['general' => 'tok-general', 'magazin' => 'tok-magazin', 'cloud' => 'tok-cloud', 'retrasa' => 'tok-retrasa', 'blog' => 'tok-blog'] as $cine => $samanta) {
        $tokenuri[$cine] = 'bvd_' . substr(hash('sha256', 'test-fisiere-cloud:' . $samanta), 0, 48);
    }
    foreach ($baze as $parte => $numeBaza) {
        $root->exec('CREATE DATABASE ' . $numeBaza . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $db = $conectare($numeBaza);
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($creari as [$sql]) {
            $db->exec($sql);
        }
        foreach ($creari as [, $tabel]) {
            $comune = array_values(array_intersect($coloane($db, $tabel), $coloane($dev, $tabel)));
            if ($comune === [] || $tabel === 'customer_email_confirmations' || in_array($tabel, $tabeleFc, true)) {
                continue;
            }
            $lista = '`' . implode('`, `', $comune) . '`';
            $unde = $tabel === 'settings' ? " WHERE `key` NOT IN (" . implode(', ', array_map([$db, 'quote'], $cheiLipsa)) . ") AND `key` NOT LIKE 'bunny\\_%'" : '';
            $db->exec('INSERT INTO `' . $tabel . '` (' . $lista . ') SELECT ' . $lista . ' FROM `' . $bazaDev . '`.`' . $tabel . '`' . $unde);
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
        // Adminii: cei demo (general, magazin, blog, emailuri) și un „Cloud user”; aceeași parolă.
        $db->prepare("INSERT INTO admins (email, password_hash, role, roles_json) VALUES ('cloud@exemplu.ro', ?, 'utilizator_cloud', '[\"utilizator_cloud\"]')")
            ->execute([$hashParola]);
        $id = static fn (string $email): int => (int) $db->query('SELECT id FROM admins WHERE email = ' . $db->quote($email))->fetchColumn();
        $adminGeneral = $id('admin@exemplu.ro');
        $adminMagazin = $id('magazin@exemplu.ro');
        $adminCloud = $id('cloud@exemplu.ro');
        $adminBlog = $id('blog@exemplu.ro');
        // Setările unui site cu pluginul pornit (zona falsă: numele, parola, o adresă CDN inventată).
        $setari = ['bunny_enabled' => '1', 'bunny_storage_zone' => $zona, 'bunny_storage_password' => $cheieZona, 'bunny_storage_region' => '',
            'bunny_cdn_url' => 'https://cdn.exemplu.invalid', 'bunny_base_folder' => '', 'bunny_api_key' => '', 'bunny_timeout' => '20',
            'bunny_upload_target' => 'local', 'bunny_gallery_folder' => 'galerie', 'bunny_lock_expire_hours' => '24', 'bunny_versions_keep' => '2',
            'bunny_work_folder' => 'documente', 'bunny_app_download_path' => 'aplicatie/BiovitalityFiles.exe'];
        $s = $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
        foreach ($setari as $k => $v) {
            $s->execute([$k, $v]);
        }
        // Rândurile unui site în lucru: cheile de aplicație, un folder cu parolă, o deblocare veche, un lacăt al magazinului, o versiune.
        $t = $db->prepare('INSERT INTO admin_app_tokens (admin_id, name, token_hash, revoked_at, created_at) VALUES (?, ?, ?, ?, ?)');
        foreach (['general' => $adminGeneral, 'magazin' => $adminMagazin, 'cloud' => $adminCloud, 'retrasa' => $adminGeneral, 'blog' => $adminBlog] as $cine => $admin) {
            $t->execute([$admin, 'Calculator ' . $cine, hash('sha256', $tokenuri[$cine]), $cine === 'retrasa' ? '2026-10-01 10:00:00' : null, '2026-09-01 09:00:00']);
        }
        $hashFolder = static fn (string $cale): string => hash('sha256', 'folder:' . $cale);
        $hashFisier = static fn (string $cale): string => hash('sha256', 'bunny:' . $cale);
        $db->prepare('INSERT INTO bunny_protected_folders (folder_path, path_hash, password_hash, hint, created_by, created_by_email, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute(['documente/contracte', $hashFolder('documente/contracte'), password_hash('parola-folderului', PASSWORD_DEFAULT),
            'ca de obicei', $adminGeneral, 'admin@exemplu.ro', '2026-09-02 09:00:00', '2026-09-02 09:00:00']);
        $db->prepare('INSERT INTO bunny_folder_unlocks (who, folder_path, path_hash, unlocked_until, greseli, blocked_until, updated_at) VALUES (?, ?, ?, ?, 0, NULL, ?)')
            ->execute(['aplicatie:999', 'documente/contracte', $hashFolder('documente/contracte'), '2026-09-02 10:00:00', '2026-09-02 09:00:00']);
        $db->prepare("INSERT INTO file_locks (storage, file_path, path_hash, admin_id, admin_email, note, origin, locked_at, expires_at)
            VALUES ('bunny', ?, ?, ?, ?, ?, 'web', ?, ?)")->execute(['documente/stoc.xlsx', $hashFisier('documente/stoc.xlsx'), $adminMagazin, 'magazin@exemplu.ro',
            'inventarul lunii', date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 86400)]);
        $db->prepare("INSERT INTO file_versions (storage, file_path, path_hash, version_path, size_bytes, admin_id, admin_email, created_at)
            VALUES ('bunny', ?, ?, ?, 12, ?, ?, ?)")->execute(['documente/preturi.xlsx', $hashFisier('documente/preturi.xlsx'),
            '_versiuni/documente/preturi.xlsx/20260901-090000-preturi.xlsx', $adminGeneral, 'admin@exemplu.ro', '2026-09-01 09:00:00']);
    }
    $verifica('bazele cms_fc_vechi și cms_fc_nou: structura codului Biovitality (bv.sql), rândurile demo, un „Cloud user”, 5 chei de aplicație (una retrasă), '
        . 'un folder cu parolă, o deblocare, un lacăt al altui admin, o versiune, setările bunny_*', true);

    // ── A: adoptarea (migrările codului comun cu --site=biovitality, pe cms_fc_nou) ──
    $scrie('');
    $scrie('A. Adoptarea: migrările nu ating tabelele și setările pluginului');
    $dbNou = $conectare($baze['nou']);
    $inainte = $instantaneu($dbNou);
    $r = $php('nou', 'scripts/migreaza.php', ['--aplica', '--site=biovitality']);
    $verifica('scripts/migreaza.php --aplica --site=biovitality pe baza în forma Biovitality: cod 0', $r['cod'] === 0, mb_substr($r['tot'], -2000));
    $dupa = $instantaneu($dbNou);
    foreach ($tabeleFc as $t) {
        $verifica('A ' . $t . ': aceeași structură (SHOW CREATE TABLE) și aceleași ' . count($inainte[$t]['randuri']) . ' rânduri după migrări',
            $inainte[$t] === $dupa[$t], json_encode([$inainte[$t]['creare'], $dupa[$t]['creare']]) ?: '');
    }
    $verifica('A setările bunny_* neschimbate (' . count($inainte['settings']) . ' chei, și cele „secrete”)', $inainte['settings'] === $dupa['settings'],
        json_encode(array_diff_assoc($dupa['settings'], $inainte['settings'])) ?: '');
    // Migrarea de date nucleu/0020 (rolurile adminilor, publicată în WP-03, înaintea pluginului) știe doar cele patru roluri ale
    // Bioscem, deci un cont „Cloud user” devine la adoptare Administrator General (rândul se rescrie: role și roles_json). Pe
    // biovitality.ro s-a întâmplat la adoptare (5 nov): rolul se pune la loc de mână, din Administratori (docs/PLUGINURI.md).
    $rolCloud = $dbNou->query("SELECT role, roles_json FROM admins WHERE email = 'cloud@exemplu.ro'")->fetch();
    $verifica('A (de știut) nucleu/0020 a făcut contul „Cloud user” Administrator General: ' . json_encode($rolCloud) . '; testul îi pune rolul la loc, ca '
        . 'proprietarul din Administratori', is_array($rolCloud) && $rolCloud['role'] === 'administrator_general');
    $dbNou->exec("UPDATE admins SET role = 'utilizator_cloud', roles_json = '[\"utilizator_cloud\"]' WHERE email = 'cloud@exemplu.ro'");
    $rand0059 = $dbNou->query("SELECT rezultat, operatii FROM _platforma_migrari WHERE id = 'fisiere_cloud/0059_fisiere_cloud'")->fetch();
    $verifica('A migrarea fisiere_cloud/0059 e „detectata”, 0 operații (totul exista)', is_array($rand0059) && $rand0059['rezultat'] === 'detectata'
        && (int) $rand0059['operatii'] === 0, json_encode($rand0059) ?: 'lipsă');
    $r = $php('nou', 'tools/dev/licenta.php', ['biovitality']);
    $verifica('licența de dezvoltare a Biovitality (cu fisiere_cloud), în dosarul testului', $r['cod'] === 0 && is_file($dosarLicenta . '/licenta.json'), $r['tot']);

    // ── Zonele false (aceleași fișiere) și cele două coduri ──
    $fisiereInitiale = [
        'documente/preturi.xlsx' => "preturi-v1\n",
        'documente/raport.xlsx' => "raport\n",
        'documente/stoc.xlsx' => "stoc\n",
        'documente/contracte/contract.docx' => "contract\n",
        'documente/contracte/anexe/anexa.pdf' => "%PDF-anexa\n",
        'documente/de-mutat/a.txt' => "a\n",
        'documente/de-mutat/sub/b.txt' => "b\n",
        'poze/logo.png' => "\x89PNG-fals\n",
        'poze/.bunny-folder' => "bunny\n",
        '_versiuni/documente/preturi.xlsx/20260901-090000-preturi.xlsx' => "preturi-v0\n",
        'aplicatie/BiovitalityFiles.exe' => "MZ-fals\n",
    ];
    $porneste = static function (array $comanda, string $cwd, array $env, int $port) use (&$servere, $dosarTest): bool {
        $jurnal = $dosarTest . '/server-' . $port . '.log';
        $p = proc_open($comanda, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $jurnal, 'a'], 2 => ['file', $jurnal, 'a']], $tevi, $cwd, $env);
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
    $pornite = true;
    foreach (['vechi' => $codVechi, 'nou' => $radacina] as $parte => $cod) {
        $dosarZona = $dosarTest . '/zona-' . $parte . '/date';
        foreach ($fisiereInitiale as $cale => $continut) {
            @mkdir(dirname($dosarZona . '/' . $cale), 0770, true);
            file_put_contents($dosarZona . '/' . $cale, $continut);
            touch($dosarZona . '/' . $cale, 1788000000);
        }
        $pornite = $pornite && $porneste([PHP_BINARY, '-S', '127.0.0.1:' . $porturiBunny[$parte], $radacina . '/tools/dev/lib/bunny-fals.php'], $dosarTest,
            ['BUNNY_FALS_DOSAR' => $dosarZona, 'BUNNY_FALS_CHEIE' => $cheieZona, 'BUNNY_FALS_ZONA' => $zona, 'PATH' => (string) getenv('PATH')], $porturiBunny[$parte]);
        $pornite = $pornite && $porneste(array_merge([PHP_BINARY], $ini, ['-S', '127.0.0.1:' . $porturi[$parte], '-t', $cod . '/public',
            $radacina . '/tools/dev/router.php']), $cod, $mediu($parte, ['APP_URL' => 'http://127.0.0.1:' . $porturi[$parte], 'PHP_CLI_SERVER_WORKERS' => '4']),
            $porturi[$parte]);
    }
    $verifica('serverele pornesc: codul Biovitality (+44) și codul comun (+45), fiecare cu zona lui falsă (+46, +47)', $pornite);
    if (!$pornite) {
        throw new RuntimeException('serverele nu pornesc (jurnalele în ' . $dosarTest . ')');
    }

    // ── B: contractul API-ului, cerere cu cerere ──
    $scrie('');
    $scrie('B. API-ul aplicației de desktop: aceleași cereri pe codul Biovitality și pe codul comun');
    /**
     * O cerere ca ApiClient.cs: Authorization: Bearer (sau X-App-Token), User-Agent-ul aplicației, corp JSON sau multipart.
     *
     * @return array{cod: int, tip: string, corp: string, antete: array<string, string>}
     */
    $cere = static function (string $parte, string $metoda, string $cale, ?string $token, array $optiuni = []) use ($porturi): array {
        $antete = ['User-Agent: BiovitalityFiles/1.0'];
        if ($token !== null) {
            $antete[] = ($optiuni['antet'] ?? 'bearer') === 'x-app-token' ? 'X-App-Token: ' . $token : 'Authorization: Bearer ' . $token;
        }
        $c = curl_init('http://127.0.0.1:' . $porturi[$parte] . $cale);
        $primite = [];
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_HEADERFUNCTION => static function ($c, string $rand) use (&$primite): int {
                if (str_contains($rand, ':')) {
                    [$k, $v] = explode(':', $rand, 2);
                    $primite[strtolower(trim($k))] = trim($v);
                }
                return strlen($rand);
            }]);
        if (array_key_exists('json', $optiuni)) {
            $antete[] = 'Content-Type: application/json; charset=utf-8';
            curl_setopt($c, CURLOPT_POSTFIELDS, is_string($optiuni['json']) ? $optiuni['json'] : json_encode($optiuni['json'], JSON_UNESCAPED_UNICODE));
        } elseif (array_key_exists('multipart', $optiuni)) {
            $campuri = [];
            foreach ($optiuni['multipart'] as $k => $v) {
                $campuri[$k] = is_array($v) ? new CURLStringFile($v[1], $v[0], 'application/octet-stream') : $v;
            }
            curl_setopt($c, CURLOPT_POSTFIELDS, $campuri);
        }
        curl_setopt($c, CURLOPT_HTTPHEADER, $antete);
        $corp = (string) curl_exec($c);
        $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        curl_close($c);
        return ['cod' => $cod, 'tip' => (string) ($primite['content-type'] ?? ''), 'corp' => $corp, 'antete' => $primite];
    };
    /** Momentele (data lacătului, a versiunii, a fișierului din zonă) înlocuite cu un semn, ca cele două rulări să se poată compara. */
    $normalizeaza = static function (string $text): string {
        $text = (string) preg_replace('#\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}#', '<DATA>', $text);
        $text = (string) preg_replace('#\d{8}-\d{6}-#', '<MOMENT>-', $text);
        return (string) preg_replace('#"updated_at":"[^"]*"#', '"updated_at":"<MOMENT>"', $text);
    };
    $coduriVazute = [];
    $pasi = 0;
    $diferite = [];
    $pas = static function (string $eticheta, string $metoda, string $cale, ?string $token, array $optiuni = [], ?int $codAsteptat = null)
        use ($cere, $normalizeaza, $verifica, &$coduriVazute, &$pasi, &$diferite): array {
        $vechi = $cere('vechi', $metoda, $cale, $token, $optiuni);
        $nou = $cere('nou', $metoda, $cale, $token, $optiuni);
        $pasi++;
        $coduriVazute[$nou['cod']] = true;
        $descarcare = str_starts_with($nou['tip'], 'application/octet-stream');
        $parti = static function (array $r) use ($normalizeaza, $descarcare): array {
            $p = ['cod' => $r['cod'], 'tip' => $r['tip'], 'corp' => $descarcare ? hash('sha256', $r['corp']) : $normalizeaza($r['corp']),
                'cache' => $r['antete']['cache-control'] ?? ''];
            if ($descarcare) {
                foreach (['content-disposition', 'content-length', 'x-file-checksum'] as $a) {
                    $p[$a] = $r['antete'][$a] ?? '';
                }
            }
            return $p;
        };
        $pv = $parti($vechi);
        $pn = $parti($nou);
        $json = json_decode($nou['corp'], true);
        $ok = $pv === $pn && ($codAsteptat === null || $nou['cod'] === $codAsteptat) && ($descarcare || is_array($json));
        if (!$ok) {
            $diferite[] = $eticheta;
        }
        $verifica('B ' . $eticheta . ' → ' . $nou['cod'] . ($descarcare ? ' (fișierul)' : ' ' . mb_substr($pn['corp'], 0, 110)), $ok,
            'Biovitality: ' . json_encode($pv, JSON_UNESCAPED_UNICODE) . ' | comun: ' . json_encode($pn, JSON_UNESCAPED_UNICODE));
        return ['vechi' => $vechi, 'nou' => $nou, 'json' => is_array($json) ? $json : []];
    };
    $tg = $tokenuri['general'];
    $tm = $tokenuri['magazin'];
    $tc = $tokenuri['cloud'];
    $tb = $tokenuri['blog'];
    $peAmbele = static function (callable $f) use ($conectare, $baze): void {
        foreach ($baze as $b) {
            $f($conectare($b));
        }
    };

    // Autentificarea.
    $pas('ping fără cheie', 'GET', '/api/desktop/ping', null, [], 401);
    $pas('ping cu o cheie greșită', 'GET', '/api/desktop/ping', 'bvd_' . str_repeat('0', 48), [], 401);
    $pas('ping cu o cheie fără prefixul bvd_', 'GET', '/api/desktop/ping', 'abc', [], 401);
    $pas('ping cu cheia retrasă', 'GET', '/api/desktop/ping', $tokenuri['retrasa'], [], 401);
    $r = $pas('ping, Administrator General (Bearer)', 'GET', '/api/desktop/ping', $tg, [], 200);
    $verifica('B ping: cheile pe care le citește aplicația (PingResponse)', array_keys($r['json']) === ['ok', 'api_version', 'email', 'roles', 'device',
        'storage_ready', 'lock_expire_hours'] && $r['json']['api_version'] === 1 && $r['json']['storage_ready'] === true, json_encode($r['json']) ?: '');
    $pas('ping cu X-App-Token', 'GET', '/api/desktop/ping', $tm, ['antet' => 'x-app-token'], 200);
    $r = $pas('ping, „Cloud user”', 'GET', '/api/desktop/ping', $tc, [], 200);
    $verifica('B rolul „Cloud user” rămâne „utilizator_cloud” (nu Administrator General)', ($r['json']['roles'] ?? null) === ['utilizator_cloud'],
        json_encode($r['json']) ?: '');
    $pas('ping, Administrator Blog', 'GET', '/api/desktop/ping', $tb, [], 200);

    // Listarea, folderul cu parolă, deblocarea și frâna.
    $r = $pas('lista rădăcinii', 'GET', '/api/desktop/files', $tg, [], 200);
    $verifica('B lista: cheile ListResponse / RemoteFolder / RemoteFile', array_keys($r['json']) === ['ok', 'path', 'folders', 'files']
        && array_keys($r['json']['folders'][0] ?? []) === ['name', 'path', 'locked'], json_encode($r['json']) ?: '');
    $r = $pas('lista folderului „documente” (stoc.xlsx preluat de magazin, contracte cu parolă)', 'GET', '/api/desktop/files?path=documente', $tg, [], 200);
    $verifica('B fișierul preluat de altcineva are lacătul (LockInfo) și folderul cu parolă e „locked”', array_keys($r['json']['files'][2]['lock'] ?? [])
        === ['owner', 'owner_id', 'is_mine', 'note', 'since', 'expires', 'expired'] && ($r['json']['folders'][0]['locked'] ?? null) === true,
        json_encode($r['json']) ?: '');
    $pas('calea cu „..” se curăță (garda căilor): ../../documente = documente', 'GET', '/api/desktop/files?path=' . rawurlencode('../../documente/./'), $tg, [], 200);
    $r = $pas('folderul cu parolă, închis', 'GET', '/api/desktop/files?path=documente/contracte', $tg, [], 423);
    $verifica('B 423: cheile pe care le citește aplicația pentru parolă (reason, folder, folder_name, hint, wait_minutes, error)',
        array_keys($r['json']) === ['ok', 'reason', 'folder', 'folder_name', 'hint', 'wait_minutes', 'error'] && $r['json']['reason'] === 'folder_locked',
        json_encode($r['json']) ?: '');
    $pas('un subfolder al folderului cu parolă', 'GET', '/api/desktop/files?path=documente/contracte/anexe', $tg, [], 423);
    $pas('descărcarea unui fișier din folderul cu parolă', 'GET', '/api/desktop/download?path=documente/contracte/contract.docx', $tg, [], 423);
    $pas('deblocarea fără cale', 'POST', '/api/desktop/folders/unlock', $tc, ['json' => ['password' => 'x']], 422);
    $pas('deblocarea unui folder fără parolă', 'POST', '/api/desktop/folders/unlock', $tc, ['json' => ['path' => 'poze', 'password' => 'x']], 200);
    for ($i = 1; $i <= 5; $i++) {
        $pas('parola greșită (' . $i . '/5)', 'POST', '/api/desktop/folders/unlock', $tc, ['json' => ['path' => 'documente/contracte', 'password' => 'gresit' . $i]], 403);
    }
    $r = $pas('după 5 greșeli: pauză, și cu parola bună', 'POST', '/api/desktop/folders/unlock', $tc,
        ['json' => ['path' => 'documente/contracte', 'password' => 'parola-folderului']], 403);
    $verifica('B frâna: „throttled”, wait_minutes 5', ($r['json']['reason'] ?? '') === 'throttled' && ($r['json']['wait_minutes'] ?? 0) === 5, json_encode($r['json']) ?: '');
    $pas('folderul rămâne închis pentru cheia oprită', 'GET', '/api/desktop/files?path=documente/contracte', $tc, [], 423);
    $r = $pas('deblocarea cu parola bună (altă cheie), dintr-un subfolder', 'POST', '/api/desktop/folders/unlock', $tg,
        ['json' => ['path' => 'documente/contracte/anexe', 'password' => 'parola-folderului']], 200);
    $verifica('B deblocarea: folderul care păzește calea, 60 de minute', ($r['json']['folder'] ?? '') === 'documente/contracte' && ($r['json']['minutes'] ?? 0) === 60);
    $pas('lista folderului deblocat', 'GET', '/api/desktop/files?path=documente/contracte', $tg, [], 200);
    $pas('lista „documente”: contracte nu mai e „locked” pentru cheia asta', 'GET', '/api/desktop/files?path=documente', $tg, [], 200);

    // Preluarea, descărcarea, salvarea, eliberarea.
    $pas('preluarea fără cale', 'POST', '/api/desktop/checkout', $tg, ['json' => ['note' => '']], 422);
    $r = $pas('preluarea lui preturi.xlsx', 'POST', '/api/desktop/checkout', $tg, ['json' => ['path' => 'documente/preturi.xlsx', 'note' => 'prețurile de toamnă']], 200);
    $verifica('B preluarea: download_url și lacătul meu', ($r['json']['download_url'] ?? '') === '/api/desktop/download?path=documente%2Fpreturi.xlsx'
        && ($r['json']['lock']['is_mine'] ?? false) === true, json_encode($r['json']) ?: '');
    $pas('a doua preluare, de același om', 'POST', '/api/desktop/checkout', $tg, ['json' => ['path' => 'documente/preturi.xlsx', 'note' => '']], 200);
    $pas('preluarea unui fișier preluat de altcineva', 'POST', '/api/desktop/checkout', $tg, ['json' => ['path' => 'documente/stoc.xlsx', 'note' => '']], 409);
    $pas('preluarea dintr-un folder cu parolă, închis pentru cheie', 'POST', '/api/desktop/checkout', $tc,
        ['json' => ['path' => 'documente/contracte/contract.docx', 'note' => '']], 423);
    $pas('descărcarea', 'GET', '/api/desktop/download?path=documente/preturi.xlsx', $tg, [], 200);
    $pas('descărcarea unui fișier care nu e în zonă', 'GET', '/api/desktop/download?path=documente/nu-exista.xlsx', $tg, [], 502);
    $pas('descărcarea fără cale', 'GET', '/api/desktop/download', $tg, [], 422);
    $pas('descărcarea cu „..” (rămâne în zonă: etc/passwd nu e acolo)', 'GET', '/api/desktop/download?path=' . rawurlencode('../../../../etc/passwd'), $tg, [], 502);
    $pas('salvarea unui fișier nepreluat', 'POST', '/api/desktop/upload', $tg, ['multipart' => ['path' => 'documente/raport.xlsx', 'release' => '1',
        'file' => ['raport.xlsx', "raport-v2\n"]]], 409);
    $pas('salvarea peste lacătul altcuiva', 'POST', '/api/desktop/upload', $tg, ['multipart' => ['path' => 'documente/stoc.xlsx', 'release' => '1',
        'file' => ['stoc.xlsx', "stoc-v2\n"]]], 409);
    $pas('salvarea fără fișier', 'POST', '/api/desktop/upload', $tg, ['multipart' => ['path' => 'documente/preturi.xlsx', 'release' => '0']], 422);
    $pas('salvarea fără cale', 'POST', '/api/desktop/upload', $tg, ['multipart' => ['file' => ['preturi.xlsx', 'x']]], 422);
    $r = $pas('salvarea, cu fișierul păstrat preluat (release=0)', 'POST', '/api/desktop/upload', $tg, ['multipart' => ['path' => 'documente/preturi.xlsx',
        'release' => '0', 'file' => ['preturi.xlsx', "preturi-v2\n"]]], 200);
    $verifica('B salvarea: copia veche în _versiuni, fișierul rămâne preluat', str_starts_with((string) ($r['json']['archived_version'] ?? ''),
        '_versiuni/documente/preturi.xlsx/') && ($r['json']['released'] ?? true) === false, json_encode($r['json']) ?: '');
    // A doua salvare în aceeași secundă ar da aceeași cale de arhivă: o secundă de pauză, ca în viață.
    sleep(1);
    $pas('a doua salvare, cu eliberarea (release=1)', 'POST', '/api/desktop/upload', $tg, ['multipart' => ['path' => 'documente/preturi.xlsx', 'release' => '1',
        'file' => ['preturi.xlsx', "preturi-v3\n"]]], 200);
    $pas('salvarea după eliberare (fișierul nu mai e preluat)', 'POST', '/api/desktop/upload', $tg, ['multipart' => ['path' => 'documente/preturi.xlsx',
        'release' => '1', 'file' => ['preturi.xlsx', "preturi-v4\n"]]], 409);
    $pas('preluarea din nou', 'POST', '/api/desktop/checkout', $tg, ['json' => ['path' => 'documente/preturi.xlsx', 'note' => '']], 200);
    sleep(1);
    $pas('a treia salvare (bunny_versions_keep = 2: cea mai veche copie se șterge)', 'POST', '/api/desktop/upload', $tg, ['multipart' => [
        'path' => 'documente/preturi.xlsx', 'release' => '1', 'file' => ['preturi.xlsx', "preturi-v4\n"]]], 200);
    $pas('eliberarea fără cale', 'POST', '/api/desktop/release', $tg, ['json' => ['force' => '0']], 422);
    $pas('eliberarea lacătului altcuiva, fără drept de forțare (Administrator Blog)', 'POST', '/api/desktop/release', $tb, ['json' => ['path' => 'documente/stoc.xlsx',
        'force' => '1']], 409);
    $pas('eliberarea unui fișier liber', 'POST', '/api/desktop/release', $tg, ['json' => ['path' => 'documente/raport.xlsx', 'force' => '0']], 200);
    $pas('preluarea lui raport.xlsx', 'POST', '/api/desktop/checkout', $tg, ['json' => ['path' => 'documente/raport.xlsx', 'note' => '']], 200);
    $pas('lista lacătelor', 'GET', '/api/desktop/locks', $tg, [], 200);
    $pas('lista lacătelor, „Cloud user”', 'GET', '/api/desktop/locks', $tc, [], 200);
    $pas('„Termină tot” (release-all)', 'POST', '/api/desktop/release-all', $tg, ['json' => []], 200);
    $pas('eliberarea forțată de Administratorul General', 'POST', '/api/desktop/release', $tg, ['json' => ['path' => 'documente/stoc.xlsx', 'force' => '1']], 200);

    // Fișierele noi, redenumirea, folderele, ștergerea.
    $r = $pas('fișier nou într-un folder', 'POST', '/api/desktop/upload-new', $tg, ['multipart' => ['path' => 'documente', 'file' => ['Ofertă nouă.xlsx', "oferta\n"]]],
        200);
    $verifica('B fișierul nou: numele curățat (UploadNewResponse)', array_keys($r['json']) === ['ok', 'path', 'name', 'renamed']
        && ($r['json']['name'] ?? '') === 'Oferta-noua.xlsx', json_encode($r['json']) ?: '');
    $r = $pas('fișier nou cu un nume luat', 'POST', '/api/desktop/upload-new', $tg, ['multipart' => ['path' => 'documente', 'file' => ['raport.xlsx', "raport-2\n"]]], 200);
    $verifica('B numele luat: „raport-1.xlsx”, renamed', ($r['json']['name'] ?? '') === 'raport-1.xlsx' && ($r['json']['renamed'] ?? false) === true);
    $pas('fișier nou cu un nume care nu se poate folosi', 'POST', '/api/desktop/upload-new', $tg, ['multipart' => ['path' => 'documente', 'file' => ['---', "x\n"]]], 422);
    $pas('fișier nou fără fișier', 'POST', '/api/desktop/upload-new', $tg, ['multipart' => ['path' => 'documente']], 422);
    $pas('fișier nou în folderul cu parolă, închis pentru cheie', 'POST', '/api/desktop/upload-new', $tc, ['multipart' => ['path' => 'documente/contracte',
        'file' => ['x.txt', "x\n"]]], 423);
    $pas('redenumirea fără nume nou', 'POST', '/api/desktop/rename', $tg, ['json' => ['path' => 'documente/raport.xlsx', 'new_name' => '', 'type' => 'file']], 422);
    $pas('redenumirea spre un nume luat', 'POST', '/api/desktop/rename', $tg, ['json' => ['path' => 'documente/raport.xlsx', 'new_name' => 'stoc.xlsx', 'type' => 'file']], 409);
    $pas('redenumirea cu același nume', 'POST', '/api/desktop/rename', $tg, ['json' => ['path' => 'documente/raport.xlsx', 'new_name' => 'raport.xlsx', 'type' => 'file']],
        200);
    $pas('redenumirea unui fișier', 'POST', '/api/desktop/rename', $tg, ['json' => ['path' => 'documente/raport.xlsx', 'new_name' => 'raport final.xlsx', 'type' => 'file']],
        200);
    $pas('preluarea de către magazin', 'POST', '/api/desktop/checkout', $tm, ['json' => ['path' => 'documente/Oferta-noua.xlsx', 'note' => '']], 200);
    $pas('redenumirea unui fișier preluat de altcineva', 'POST', '/api/desktop/rename', $tg, ['json' => ['path' => 'documente/Oferta-noua.xlsx',
        'new_name' => 'oferta.xlsx', 'type' => 'file']], 409);
    $pas('redenumirea arhivei de versiuni', 'POST', '/api/desktop/rename', $tg, ['json' => ['path' => '_versiuni', 'new_name' => 'vechi', 'type' => 'folder']], 409);
    $pas('redenumirea unui folder în care lucrează altcineva', 'POST', '/api/desktop/rename', $tg, ['json' => ['path' => 'documente', 'new_name' => 'docs',
        'type' => 'folder']], 409);
    $r = $pas('redenumirea unui folder (copiat fișier cu fișier)', 'POST', '/api/desktop/rename', $tg, ['json' => ['path' => 'documente/de-mutat', 'new_name' => 'mutat',
        'type' => 'folder']], 200);
    $verifica('B folderul redenumit: câte fișiere s-au mutat', ($r['json']['files'] ?? 0) === 2, json_encode($r['json']) ?: '');
    $pas('folderul deblocat se redenumește, cu parola după el', 'POST', '/api/desktop/rename', $tg, ['json' => ['path' => 'documente/contracte',
        'new_name' => 'acte', 'type' => 'folder']], 200);
    $pas('folderul redenumit rămâne cu parolă (pentru altă cheie)', 'GET', '/api/desktop/files?path=documente/acte', $tm, [], 423);
    $pas('folder nou fără nume', 'POST', '/api/desktop/folders', $tg, ['json' => ['path' => 'documente', 'name' => '']], 422);
    $pas('folder nou', 'POST', '/api/desktop/folders', $tg, ['json' => ['path' => 'documente', 'name' => 'Facturi Noiembrie']], 200);
    $pas('folder nou cu un nume luat', 'POST', '/api/desktop/folders', $tg, ['json' => ['path' => 'documente', 'name' => 'facturi-noiembrie']], 409);
    $pas('folder nou în rădăcină', 'POST', '/api/desktop/folders', $tg, ['json' => ['path' => '', 'name' => 'arhiva 2025']], 200);
    $pas('ștergerea fără cale', 'POST', '/api/desktop/delete', $tg, ['json' => ['type' => 'file']], 422);
    $pas('ștergerea arhivei de versiuni', 'POST', '/api/desktop/delete', $tg, ['json' => ['path' => '_versiuni/documente', 'type' => 'folder']], 409);
    $pas('ștergerea unui fișier preluat de altcineva', 'POST', '/api/desktop/delete', $tg, ['json' => ['path' => 'documente/Oferta-noua.xlsx', 'type' => 'file']], 409);
    $pas('ștergerea unui folder în care lucrează altcineva', 'POST', '/api/desktop/delete', $tg, ['json' => ['path' => 'documente', 'type' => 'folder']], 409);
    $pas('ștergerea unui fișier', 'POST', '/api/desktop/delete', $tg, ['json' => ['path' => 'documente/raport-1.xlsx', 'type' => 'file']], 200);
    $pas('ștergerea unui fișier care nu mai e', 'POST', '/api/desktop/delete', $tg, ['json' => ['path' => 'documente/raport-1.xlsx', 'type' => 'file']], 502);
    $pas('ștergerea unui folder', 'POST', '/api/desktop/delete', $tg, ['json' => ['path' => 'documente/mutat', 'type' => 'folder']], 200);
    $pas('după redenumire folderul e din nou închis (deblocarea era pe calea veche)', 'POST', '/api/desktop/delete', $tg, ['json' => ['path' => 'documente/acte',
        'type' => 'folder']], 423);
    $pas('deblocarea sub numele nou', 'POST', '/api/desktop/folders/unlock', $tg, ['json' => ['path' => 'documente/acte', 'password' => 'parola-folderului']], 200);
    $pas('ștergerea folderului cu parolă, deblocat (parola pleacă odată cu el)', 'POST', '/api/desktop/delete', $tg, ['json' => ['path' => 'documente/acte',
        'type' => 'folder']], 200);
    $pas('închiderea folderelor (toate)', 'POST', '/api/desktop/folders/lock', $tg, ['json' => []], 200);
    $pas('închiderea unui folder', 'POST', '/api/desktop/folders/lock', $tg, ['json' => ['path' => 'documente/contracte']], 200);
    $pas('corp JSON stricat (ca un formular gol)', 'POST', '/api/desktop/checkout', $tg, ['json' => '{nu e json'], 422);

    // Integrarea oprită și pornită la loc.
    $peAmbele(static fn (PDO $db) => $db->exec("UPDATE settings SET `value` = '0' WHERE `key` = 'bunny_enabled'"));
    $r = $pas('integrarea oprită: ping', 'GET', '/api/desktop/ping', $tg, [], 200);
    $verifica('B integrarea oprită: storage_ready = false', ($r['json']['storage_ready'] ?? true) === false);
    $pas('integrarea oprită: lista', 'GET', '/api/desktop/files', $tg, [], 503);
    $pas('integrarea oprită: descărcarea', 'GET', '/api/desktop/download?path=poze/logo.png', $tg, [], 503);
    $peAmbele(static fn (PDO $db) => $db->exec("UPDATE settings SET `value` = '1' WHERE `key` = 'bunny_enabled'"));
    $peAmbele(static fn (PDO $db) => $db->exec("UPDATE settings SET `value` = '' WHERE `key` = 'bunny_storage_password'"));
    $pas('integrarea fără parola zonei', 'GET', '/api/desktop/files', $tg, [], 503);
    $peAmbele(static fn (PDO $db) => $db->exec("UPDATE settings SET `value` = 'parola-gresita' WHERE `key` = 'bunny_storage_password'"));
    $pas('parola zonei greșită (Bunny răspunde 401)', 'GET', '/api/desktop/files', $tg, [], 502);
    $peAmbele(static fn (PDO $db) => $db->prepare("UPDATE settings SET `value` = ? WHERE `key` = 'bunny_storage_password'")->execute([$cheieZona]));

    $verifica('B ' . $pasi . ' cereri pe ambele coduri, ' . count($diferite) . ' diferite', $diferite === [], implode(' | ', $diferite));
    ksort($coduriVazute);
    $verifica('B codurile de stare ale controllerului, fiecare cel puțin o dată: ' . implode(', ', array_keys($coduriVazute)),
        array_diff([200, 401, 403, 409, 422, 423, 502, 503], array_keys($coduriVazute)) === []);

    // Starea de după: zonele și tabelele.
    $continutZona = static function (string $parte) use ($dosarTest, $normalizeaza): array {
        $dosar = $dosarTest . '/zona-' . $parte . '/date';
        $r = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dosar, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $r[$normalizeaza(substr($f->getPathname(), strlen($dosar) + 1))] = (string) file_get_contents($f->getPathname());
        }
        ksort($r);
        return $r;
    };
    $zv = $continutZona('vechi');
    $zn = $continutZona('nou');
    $verifica('B la final, aceleași ' . count($zn) . ' fișiere în cele două zone, cu același conținut (versiunile păstrate: bunny_versions_keep = 2)', $zv === $zn,
        json_encode([array_keys(array_diff_assoc($zv, $zn)), array_keys(array_diff_assoc($zn, $zv))]) ?: '');
    $starea = static function (PDO $db) use ($normalizeaza): array {
        return [
            'file_locks' => $db->query('SELECT storage, file_path, path_hash, admin_id, admin_email, note, origin FROM file_locks ORDER BY path_hash')->fetchAll(),
            'file_versions' => array_map(static fn (array $r): array => ['version_path' => $normalizeaza((string) $r['version_path'])] + $r,
                $db->query('SELECT file_path, path_hash, version_path, size_bytes, admin_id, admin_email FROM file_versions ORDER BY id')->fetchAll()),
            'bunny_protected_folders' => $db->query('SELECT folder_path, path_hash, hint, created_by, created_by_email FROM bunny_protected_folders ORDER BY id')->fetchAll(),
            'bunny_folder_unlocks' => $db->query('SELECT who, folder_path, path_hash, greseli, unlocked_until IS NULL AS inchis, blocked_until IS NULL AS neblocat
                FROM bunny_folder_unlocks ORDER BY who, path_hash')->fetchAll(),
            'admin_app_tokens' => $db->query('SELECT admin_id, name, token_hash, revoked_at, last_used_at IS NULL AS nefolosita, last_used_ip FROM admin_app_tokens
                ORDER BY id')->fetchAll(),
            'jurnal' => $db->query("SELECT action, context_json FROM admin_activity_log WHERE action LIKE 'bunny\\_%' ORDER BY id")->fetchAll(),
        ];
    };
    $sv = $starea($conectare($baze['vechi']));
    $sn = $starea($conectare($baze['nou']));
    foreach ($sv as $tabel => $randuri) {
        $verifica('B la final, ' . $tabel . ': aceleași ' . count($sn[$tabel]) . ' rânduri', $randuri === $sn[$tabel],
            json_encode([$randuri, $sn[$tabel]], JSON_UNESCAPED_UNICODE) ?: '');
    }
    $cereri = static fn (string $parte): array => array_count_values(file($dosarTest . '/zona-' . $parte . '/cereri.log', FILE_IGNORE_NEW_LINES) ?: []);
    $verifica('B aceleași cereri spre zonă (metoda și calea, de câte ori)', $cereri('vechi') == $cereri('nou'),
        json_encode([array_diff_assoc($cereri('vechi'), $cereri('nou')), array_diff_assoc($cereri('nou'), $cereri('vechi'))]) ?: '');

    // ── C: doar pe codul comun ──
    $scrie('');
    $scrie('C. Doar pe codul comun: pluginul oprit, adminul web, CSRF, rolul „Cloud user”');
    $r = $php('nou', 'tools/dev/licenta.php', ['biovitality', 'fisiere_cloud=oprit']);
    $verifica('licența Biovitality cu fisiere_cloud oprit', $r['cod'] === 0, $r['tot']);
    foreach (['GET /api/desktop/ping', 'GET /api/desktop/files', 'POST /api/desktop/checkout'] as $cerere) {
        [$metoda, $cale] = explode(' ', $cerere);
        $x = $cere('nou', $metoda, $cale, $tg, $metoda === 'POST' ? ['json' => ['path' => 'poze/logo.png']] : []);
        $j = json_decode($x['corp'], true);
        $verifica('C pluginul oprit: ' . $cerere . ' → 404 JSON al gărzii, cu `error` (ApiResponse.Error, arătat de aplicație)', $x['cod'] === 404
            && str_starts_with($x['tip'], 'application/json') && is_array($j) && ($j['ok'] ?? null) === false && ($j['error'] ?? '') === 'Pagina nu a fost găsită.',
            $x['cod'] . ' ' . $x['corp']);
    }
    $sesiune = static function (string $email) use ($porturi, $parolaAdmin, $dosarTest): array {
        $borcan = $dosarTest . '/cookies-' . md5($email . microtime()) . '.txt';
        $c = static function (string $metoda, string $cale, array $campuri = []) use ($porturi, $borcan): array {
            $h = curl_init('http://127.0.0.1:' . $porturi['nou'] . $cale);
            curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_COOKIEJAR => $borcan,
                CURLOPT_COOKIEFILE => $borcan, CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_HEADER => true]);
            if ($metoda === 'POST') {
                curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($campuri));
            }
            $tot = (string) curl_exec($h);
            $cod = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
            $marime = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
            curl_close($h);
            $antete = substr($tot, 0, $marime);
            return ['cod' => $cod, 'corp' => substr($tot, $marime), 'location' => preg_match('/^Location: (.+)$/mi', $antete, $m) === 1 ? trim($m[1]) : ''];
        };
        $c('GET', '/admin/login');
        $c('POST', '/admin/login', ['email' => $email, 'password' => $parolaAdmin]);
        return [$c];
    };
    $r = $php('nou', 'tools/dev/licenta.php', ['biovitality']);
    [$adm] = $sesiune('admin@exemplu.ro');
    $pagina = $adm('GET', '/admin/bunny?path=documente');
    $verifica('C /admin/bunny (Administrator General): 200, folderele zonei și formularele cu jetonul CSRF', $pagina['cod'] === 200
        && str_contains($pagina['corp'], 'Facturi-Noiembrie') && preg_match_all('/name="_csrf" value="[0-9a-f]{64}"/', $pagina['corp']) >= 5, (string) $pagina['cod']);
    preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $pagina['corp'], $m);
    $jeton = (string) ($m[1] ?? '');
    $x = $adm('POST', '/admin/bunny/folders', ['path' => 'documente', 'name' => 'fara-jeton']);
    $verifica('C un POST fără jeton (folder nou) e refuzat: înapoi la folder, nimic creat', $x['cod'] === 302 && str_contains($x['location'], '/admin/bunny?path=documente')
        && !is_dir($dosarTest . '/zona-nou/date/documente/fara-jeton'), $x['cod'] . ' ' . $x['location']);
    $x = $adm('POST', '/admin/bunny/folders', ['path' => 'documente', 'name' => 'cu-jeton', '_csrf' => $jeton]);
    $verifica('C cu jeton, folderul se creează (marcajul .bunny-folder)', $x['cod'] === 302 && is_file($dosarTest . '/zona-nou/date/documente/cu-jeton/.bunny-folder'));
    $x = $adm('POST', '/admin/bunny/app/tokens', ['name' => 'Laptop test']);
    $verifica('C o cheie de aplicație nouă fără jeton: refuzată', $x['cod'] === 302 && (int) $conectare($baze['nou'])->query("SELECT COUNT(*) FROM admin_app_tokens
        WHERE name = 'Laptop test'")->fetchColumn() === 0);
    $x = $adm('POST', '/admin/bunny/app/tokens', ['name' => 'Laptop test', '_csrf' => $jeton]);
    $pagina = $adm('GET', '/admin/bunny/app');
    $verifica('C cheia nouă se arată o dată (bvd_ + 48 hex), iar în bază rămâne doar amprenta ei', preg_match('/id="bunny-fresh-token">(bvd_[0-9a-f]{48})</',
        $pagina['corp'], $mt) === 1 && (int) $conectare($baze['nou'])->query("SELECT COUNT(*) FROM admin_app_tokens WHERE name = 'Laptop test' AND token_hash = "
        . $conectare($baze['nou'])->quote(hash('sha256', $mt[1] ?? '')))->fetchColumn() === 1 && !str_contains($adm('GET', '/admin/bunny/app')['corp'], $mt[1] ?? 'x'));
    $x = $cere('nou', 'GET', '/api/desktop/ping', (string) ($mt[1] ?? ''));
    $verifica('C cheia nouă merge în aplicație', $x['cod'] === 200);
    foreach (['/admin/settings/bunny' => 'Activează integrarea bunny.net', '/admin/gallery' => 'Fișiere bunny.net'] as $cale => $semn) {
        $p = $adm('GET', $cale);
        $verifica('C ' . $cale . ': 200, „' . $semn . '”', $p['cod'] === 200 && str_contains($p['corp'], $semn));
    }
    $p = $adm('GET', '/admin/settings/bunny');
    $verifica('C Setări → bunny.net nu arată parola zonei, doar ultimele 4 caractere', !str_contains($p['corp'], $cheieZona) && str_contains($p['corp'], '••••••test'));
    $x = $adm('POST', '/admin/settings/bunny', ['action' => 'save', 'bunny_enabled' => '1', 'bunny_storage_zone' => $zona, 'bunny_timeout' => '20']);
    $verifica('C Setări → bunny.net fără jeton: refuzat, nimic salvat', $x['cod'] === 302 && (string) $conectare($baze['nou'])->query("SELECT `value` FROM settings
        WHERE `key` = 'bunny_versions_keep'")->fetchColumn() === '2');

    [$cloud] = $sesiune('cloud@exemplu.ro');
    $p = $cloud('GET', '/admin');
    $verifica('C „Cloud user” după logare: nu vede Acasă, e trimis la /admin/bunny', $p['cod'] === 302 && str_ends_with($p['location'], '/admin/bunny'), $p['cod'] . ' '
        . $p['location']);
    $p = $cloud('GET', '/admin/bunny');
    $verifica('C „Cloud user”: /admin/bunny 200, meniul fără altceva în afară de bunny.net', $p['cod'] === 200 && !str_contains($p['corp'], 'href="/admin/products"')
        && !str_contains($p['corp'], 'href="/admin/settings/admins"'));
    foreach (['/admin/products', '/admin/settings/admins', '/admin/settings/bunny', '/admin/orders'] as $cale) {
        $p = $cloud('GET', $cale);
        $verifica('C „Cloud user”: ' . $cale . ' refuzat (302 la /admin/bunny)', $p['cod'] === 302 && str_ends_with($p['location'], '/admin/bunny'), $p['cod'] . ' ' . $p['location']);
    }
    $p = $cloud('GET', '/admin/bunny?path=documente');
    preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $p['corp'], $m);
    $jetonCloud = (string) ($m[1] ?? '');
    $dbNou = $conectare($baze['nou']);
    $dbNou->prepare('INSERT INTO bunny_protected_folders (folder_path, path_hash, password_hash, hint) VALUES (?, ?, ?, NULL)')
        ->execute(['poze', hash('sha256', 'folder:poze'), password_hash('parola-pozelor', PASSWORD_DEFAULT)]);
    $p = $cloud('GET', '/admin/bunny?path=poze');
    $verifica('C adminul web: folderul cu parolă arată poarta, fără numele fișierelor', $p['cod'] === 200 && str_contains($p['corp'], 'are parolă')
        && !str_contains($p['corp'], 'logo.png'));
    for ($i = 1; $i <= 5; $i++) {
        $cloud('POST', '/admin/bunny/folders/unlock', ['target' => 'poze', 'path' => 'poze', 'password' => 'gresit', '_csrf' => $jetonCloud]);
    }
    $p = $cloud('GET', '/admin/bunny?path=poze');
    $verifica('C adminul web: după 5 parole greșite, pauza (fără formular)', str_contains($p['corp'], 'Prea multe încercări greșite') && !str_contains($p['corp'],
        'action="/admin/bunny/folders/unlock"'));
    $jurnal = (int) $dbNou->query("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'bunny_folder_unlock_failed'")->fetchColumn();
    $verifica('C fiecare încercare greșită e în Jurnal activitate (bunny_folder_unlock_failed): ' . $jurnal, $jurnal >= 5 + 5);
    $randuri = $dbNou->query("SELECT password_hash FROM bunny_protected_folders")->fetchAll(PDO::FETCH_COLUMN);
    $verifica('C parolele folderelor stau doar ca password_hash (niciun text clar)', $randuri !== [] && array_filter($randuri, static fn ($h): bool
        => password_get_info((string) $h)['algo'] === null) === [] && !in_array('parola-pozelor', $randuri, true));

    // Paginile cu pluginul oprit: „Modulul nu este inclus în abonament” (403), meniul fără bunny.net.
    $php('nou', 'tools/dev/licenta.php', ['biovitality', 'fisiere_cloud=oprit']);
    $p = $adm('GET', '/admin/bunny');
    $verifica('C pluginul oprit: /admin/bunny → 403 „Modulul nu este inclus în abonament”', $p['cod'] === 403 && str_contains($p['corp'], 'nu este inclus'));
    $p = $adm('GET', '/admin/gallery');
    $verifica('C pluginul oprit: Galeria fără butonul „Fișiere bunny.net”, meniul fără bunny.net', $p['cod'] === 200 && !str_contains($p['corp'], 'bunny'));
    $p = $adm('GET', '/admin/settings/admins');
    $verifica('C pluginul oprit: Administratori nu mai oferă „Cloud user”, iar contul lui îl păstrează (câmp ascuns)', $p['cod'] === 200
        && !str_contains($p['corp'], '>Cloud user<') && str_contains($p['corp'], 'type="hidden" name="roles[]" value="utilizator_cloud"'));
    [$cloud2] = $sesiune('cloud@exemplu.ro');
    $p = $cloud2('GET', '/admin/products');
    $verifica('C pluginul oprit: „Cloud user” tot nu primește restul adminului', $p['cod'] === 302 && !str_contains($p['location'], '/admin/products'), $p['location']);
} catch (Throwable $e) {
    $verifica('testul a rulat până la capăt', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

$opresteServerele();
$scrie('');
$scrie('Rezultat: ' . $trecute . ' trecute, ' . $esuate . ' eșuate. Raport: ' . $fisierRaport);
exit($esuate === 0 ? 0 : 1);
