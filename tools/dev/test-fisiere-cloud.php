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
 *    e „detectata” (nimic schimbat); nucleu/0020 face contul „Cloud user” Administrator General, iar
 *    scripts/restaureaza-roluri-cloud.php îl pune la loc din copia de dinainte (mysqldump | gzip, ca deploy/adopta.php,
 *    și una cu INSERT-uri rând cu rând): simularea, --aplica, conturile schimbate de mână și cele șterse, cheile de după adoptare,
 *    a doua rulare, fără hash-uri de parolă în ieșire;
 * B  contractul API-ului aplicației: aceeași listă de cereri (fiecare adresă /api/desktop/*, cu antetele aplicației, Bearer și
 *    X-App-Token, JSON și multipart, ca ApiClient.cs) pe codul Biovitality și pe codul comun, una după alta: același cod de stare,
 *    același Content-Type, același corp (JSON-ul octet cu octet, după ce datele și momentele sunt înlocuite cu un semn; la descărcare
 *    conținutul și antetele Content-Disposition, Content-Length, X-File-Checksum), iar la final aceleași fișiere în zonă și aceleași
 *    rânduri în tabele (fără momente; pauza după greșeli e acum pe administrator, nu pe cheie). Singura cerere cu alt răspuns e
 *    declarată (ștergerea unui folder cu un subfolder cu parolă închis: 409 → 423). Fiecare cod de stare al controllerului apare cel puțin o dată (200, 401, 403, 409, 413 nu: 64 MB,
 *    422, 423, 502, 503);
 * C  doar pe codul comun: cu pluginul oprit, /api/desktop/* răspunde cu JSON-ul 404 al gărzii, cu `error` (aplicația îl arată);
 *    garda căilor (`..`); adminul web: paginile, formularele fără jetonul CSRF refuzate (nimic făcut), cu el aceleași efecte ca pe
 *    codul Biovitality, rolul „Cloud user” doar la fișiere (nu Administrator General), parola de folder cu limita de încercări;
 * D  revizia de securitate (doar pe codul comun): copiile din `_versiuni` păzite ca originalul și ascunse (aplicația și browserul),
 *    căile cu alte litere mari, ștergerea unui folder cu un subfolder închis, nimic scris în `_versiuni`, frâna pe administrator
 *    (două chei; zece cereri deodată), parolele care nu se pot citi (503 / „Nu pot verifica”), parola unui subfolder doar cu cel de
 *    deasupra deschis și doar pe foldere, scoaterea parolei prin aceeași frână, Galeria cale cu cale, fără adrese CDN într-un
 *    folder cu parolă, confirm() cu JSON, limita de 64 MB și cererea peste post_max_size, zonele detectate fără parolă în pagină,
 *    plafonul de 10 chei și cheile celorlalți la Administratorul General; redenumirea unui folder cu un subfolder închis (refuzată),
 *    protecțiile mutate literă cu literă, numărătoarea greșelilor păstrată peste o redenumire.
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

    // Pentru scripts/restaureaza-roluri-cloud.php (H3): încă trei conturi cu „Cloud user” pe cms_fc_nou (unul și cu
    // Blog; unul schimbat apoi de mână; unul șters apoi), apoi copia bazei „de dinainte de adoptare”, făcută ca deploy/adopta.php
    // (Baza::copie: mysqldump cu INSERT-uri lungi, gzip) și una cu INSERT-uri rând cu rând, cu lista coloanelor.
    $dbNou = $conectare($baze['nou']);
    $extra = $dbNou->prepare('INSERT INTO admins (email, password_hash, role, roles_json) VALUES (?, ?, ?, ?)');
    $extra->execute(['cloud-blog@exemplu.ro', $hashParola, 'administrator_blog', '["administrator_blog","utilizator_cloud"]']);
    $extra->execute(["cloud-mana@exemplu.ro", $hashParola, 'utilizator_cloud', '["utilizator_cloud"]']);
    $extra->execute(['cloud-plecat@exemplu.ro', $hashParola, 'utilizator_cloud', null]);
    $copieBaza = $dosarTest . '/baza-inainte.sql.gz';
    $copieSql = $dosarTest . '/baza-inainte-randuri.sql';
    $dump = static function (string $optiuni, string $tinta) use ($sock, $baze): int {
        exec(escapeshellarg((string) (trim((string) shell_exec('command -v mariadb-dump')) ?: 'mysqldump'))
            . ' --socket=' . escapeshellarg($sock) . ' -u' . escapeshellarg((string) (getenv('CMS_DEV_DB_USER') ?: 'root'))
            . ' --single-transaction --quick --hex-blob --default-character-set=utf8mb4 --no-tablespaces --triggers --add-drop-table --skip-dump-date '
            . $optiuni . ' ' . $baze['nou'] . ' 2>/dev/null' . $tinta, $iesireDump, $codDump);
        return (int) $codDump;
    };
    $codDump = $dump('', ' | gzip -c > ' . escapeshellarg($copieBaza));
    $codDump2 = $dump('--complete-insert --skip-extended-insert', ' > ' . escapeshellarg($copieSql));
    $verifica('copia bazei de dinainte (mysqldump | gzip, ca la adoptare) și una cu INSERT-uri rând cu rând', $codDump === 0 && $codDump2 === 0
        && filesize($copieBaza) > 1000 && str_contains((string) file_get_contents($copieSql), 'INSERT INTO `admins` (`id`'));

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
    $verifica('A (de știut) nucleu/0020 a făcut contul „Cloud user” Administrator General: ' . json_encode($rolCloud) . '; îl pune la loc '
        . 'scripts/restaureaza-roluri-cloud.php', is_array($rolCloud) && $rolCloud['role'] === 'administrator_general'
        && $rolCloud['roles_json'] === '["administrator_general"]');

    // ── A (H3): rolurile „Cloud user” puse la loc din copia de dinainte ──
    $roluriAcum = static fn (): array => $dbNou->query("SELECT email, role, roles_json FROM admins WHERE email LIKE 'cloud%' ORDER BY email")
        ->fetchAll(PDO::FETCH_UNIQUE);
    $dbNou->exec("UPDATE admins SET role = 'administrator_magazin', roles_json = '[\"administrator_magazin\"]' WHERE email = 'cloud-mana@exemplu.ro'");
    $dbNou->exec("DELETE FROM admins WHERE email = 'cloud-plecat@exemplu.ro'");
    $idCloudBlog = (int) $dbNou->query("SELECT id FROM admins WHERE email = 'cloud-blog@exemplu.ro'")->fetchColumn();
    $cheieNoua = $dbNou->prepare('INSERT INTO admin_app_tokens (admin_id, name, token_hash, created_at) VALUES (?, ?, ?, ?)');
    $cheieNoua->execute([$idCloudBlog, 'Laptop dupa adoptare', hash('sha256', 'cheie-dupa'), '2026-11-06 08:00:00']);
    $idCheieDupa = (int) $dbNou->lastInsertId();
    $cheieNoua->execute([$idCloudBlog, 'Laptop dinainte', hash('sha256', 'cheie-inainte'), '2026-11-01 08:00:00']);
    $idCheieInainte = (int) $dbNou->lastInsertId();
    $inainteScript = $roluriAcum();
    $script = 'scripts/restaureaza-roluri-cloud.php';
    $sim = $php('nou', $script, ['--copie=' . $copieBaza]);
    $asteptateSim = ['cloud@exemplu.ro: acum administrator_general → utilizator_cloud', 'cloud-blog@exemplu.ro: acum administrator_blog → '
        . 'administrator_blog, utilizator_cloud', 'cloud-mana@exemplu.ro: acum administrator_magazin (înainte de adoptare: utilizator_cloud)',
        'Nu mai există (1): cloud-plecat@exemplu.ro', '„Laptop dupa adoptare” (#' . $idCheieDupa . ')', 'Simulare: nimic scris.'];
    $lipsesc = array_values(array_filter($asteptateSim, static fn (string $l): bool => !str_contains($sim['iesire'], $l)));
    $verifica('A H3 simularea: cine se pune la loc („acum … → …”), cine a fost schimbat de mână și cine nu mai e, cheia de după adoptare; '
        . 'nimic scris', $sim['cod'] === 0 && $lipsesc === [] && $roluriAcum() === $inainteScript && !str_contains($sim['iesire'], 'Laptop dinainte'),
        $sim['tot'] . ' | lipsesc: ' . implode(' / ', $lipsesc));
    $hashuri = $dbNou->query('SELECT password_hash FROM admins')->fetchAll(PDO::FETCH_COLUMN);
    $verifica('A H3 scriptul nu afișează hash-uri de parolă', !str_contains($sim['tot'], '$2y$') && array_filter($hashuri, static fn ($h): bool
        => str_contains($sim['tot'], (string) $h)) === []);
    $sim2 = $php('nou', $script, ['--copie=' . $copieSql]);
    $faraCopie = static fn (string $t): string => (string) preg_replace('/^copie .*$/m', '', $t);
    $verifica('A H3 aceeași simulare din copia cu INSERT-uri rând cu rând (.sql, cu lista coloanelor)', $sim2['cod'] === 0
        && $faraCopie($sim2['iesire']) === $faraCopie($sim['iesire']), $sim2['tot']);
    $gresit = $php('nou', $script, ['--copie=' . $dosarTest . '/nu-exista.sql.gz']);
    $verifica('A H3 fără copie: cod 2', $gresit['cod'] === 2, $gresit['tot']);
    $apl = $php('nou', $script, ['--copie=' . $copieBaza, '--aplica']);
    $dupaScript = $roluriAcum();
    $chei = $dbNou->query('SELECT id, revoked_at IS NOT NULL AS retrasa FROM admin_app_tokens WHERE id IN (' . $idCheieDupa . ', ' . $idCheieInainte . ')')
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    $verifica('A H3 --aplica: „Cloud user” și „Blog + Cloud user” la loc (role și roles_json de dinainte), cel schimbat de mână neatins, '
        . 'cheia de după adoptare retrasă, cea dinainte nu, un rând în Jurnal', $apl['cod'] === 0
        && ($dupaScript['cloud@exemplu.ro'] ?? null) === ['role' => 'utilizator_cloud', 'roles_json' => '["utilizator_cloud"]']
        && ($dupaScript['cloud-blog@exemplu.ro'] ?? null) === ['role' => 'administrator_blog', 'roles_json' => '["administrator_blog","utilizator_cloud"]']
        && ($dupaScript['cloud-mana@exemplu.ro'] ?? null) === ['role' => 'administrator_magazin', 'roles_json' => '["administrator_magazin"]']
        && (int) ($chei[$idCheieDupa] ?? 0) === 1 && (int) ($chei[$idCheieInainte] ?? 1) === 0
        && (int) $dbNou->query("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'roluri_cloud_restaurate'")->fetchColumn() === 1,
        $apl['tot'] . ' ' . json_encode([$dupaScript, $chei]));
    $iar = $php('nou', $script, ['--copie=' . $copieBaza, '--aplica']);
    $verifica('A H3 a doua rulare: „Deja la loc”, nimic de scris', $iar['cod'] === 0 && str_contains($iar['iesire'], 'Deja la loc (2)')
        && str_contains($iar['iesire'], 'Nimic de scris.') && $roluriAcum() === $dupaScript, $iar['tot']);
    // Rândurile ajutătoare pleacă: B compară cheile de aplicație cu codul Biovitality (care nu le are).
    $dbNou->exec('DELETE FROM admin_app_tokens WHERE id IN (' . $idCheieDupa . ', ' . $idCheieInainte . ')');
    $dbNou->exec("DELETE FROM admins WHERE email IN ('cloud-blog@exemplu.ro', 'cloud-mana@exemplu.ro')");
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
    // `$declarat` = [cod Biovitality, cod comun, motivul]: o cerere pe care revizia de securitate o refuză acum pe drept (singurele
    // diferențe permise față de codul Biovitality; docs/PLUGINURI.md).
    $pas = static function (string $eticheta, string $metoda, string $cale, ?string $token, array $optiuni = [], ?int $codAsteptat = null,
        ?array $declarat = null) use ($cere, $normalizeaza, $verifica, &$coduriVazute, &$pasi, &$diferite): array {
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
        if ($declarat !== null) {
            $ok = $vechi['cod'] === $declarat[0] && $nou['cod'] === $declarat[1] && is_array($json);
            $eticheta .= ' [diferență declarată, ' . $declarat[2] . ': ' . $declarat[0] . ' → ' . $declarat[1] . ']';
        }
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
    $pas('ștergerea unui folder în care lucrează altcineva (și cu „acte”, cu parolă, închis)', 'POST', '/api/desktop/delete', $tg, ['json' => ['path' => 'documente',
        'type' => 'folder']], null, [409, 423, 'M3: subfolderul cu parolă închis se verifică întâi']);
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
            // Deblocările (pe sesiune sau cheie) la fel; pauza după prea multe greșeli tot pe același folder, dar ținută acum pe
            // administrator (`admin:<id>`), nu pe cheie (`aplicatie:<id>`): diferența declarată a reviziei de securitate (M1).
            'bunny_folder_unlocks' => $db->query('SELECT who, folder_path, path_hash, greseli, unlocked_until IS NULL AS inchis, blocked_until IS NULL AS neblocat
                FROM bunny_folder_unlocks WHERE unlocked_until IS NOT NULL ORDER BY who, path_hash')->fetchAll(),
            // (rândul pauzei are acum cheia protecției, nu a căii: se compară folderul și numărul)
            'pauze' => $db->query('SELECT folder_path, greseli FROM bunny_folder_unlocks WHERE blocked_until IS NOT NULL ORDER BY folder_path')->fetchAll(),
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
    // Cu momentul copiei din `_versiuni` înlocuit (cele două coduri pot cădea în secunde diferite).
    $cereri = static fn (string $parte): array => array_count_values(array_map($normalizeaza, file($dosarTest . '/zona-' . $parte . '/cereri.log',
        FILE_IGNORE_NEW_LINES) ?: []));
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
                // Cu un fișier (CURLFile): multipart, ca formularul de urcare; altfel ca un formular obișnuit.
                $cuFisier = array_filter($campuri, static fn ($v): bool => $v instanceof CURLFile) !== [];
                curl_setopt($h, CURLOPT_POSTFIELDS, $cuFisier ? $campuri : http_build_query($campuri));
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

    // ── D: revizia de securitate (doar pe codul comun; docs/PLUGINURI.md, „Fișiere în cloud” → „Ce s-a strâns după revizie”) ──
    $scrie('');
    $scrie('D. Revizia de securitate: arhiva, literele mari, folderele din folder, frâna pe administrator, cheile, Galeria, CDN-ul');
    $zonaNou = $dosarTest . '/zona-nou/date';
    $versiuneSecreta = '_versiuni/secret/plan.png/20260101-000000-plan.png';
    $numeCiudat = "x'-alert(1)-'";
    foreach (['secret/plan.png' => "\x89PNG-secret\n", 'secret/sub/x.txt' => "x\n", $versiuneSecreta => "\x89PNG-secret-v0\n", 'public/a.png' => "\x89PNG-a\n",
        'public/' . $numeCiudat . '/.bunny-folder' => "bunny\n", 'arhiva-proiecte/intern/doc.txt' => "doc\n", 'proiecte/p.txt' => "p\n",
        'proiecte/public2/.bunny-folder' => "bunny\n"] as $cale => $continut) {
        @mkdir(dirname($zonaNou . '/' . $cale), 0770, true);
        file_put_contents($zonaNou . '/' . $cale, $continut);
    }
    $protejeaza = $dbNou->prepare('INSERT INTO bunny_protected_folders (folder_path, path_hash, password_hash, hint) VALUES (?, ?, ?, NULL)');
    foreach (['secret' => 'parola-secret', 'arhiva-proiecte/intern' => 'parola-intern', 'proiecte' => 'parola-proiecte'] as $folder => $parolaF) {
        $protejeaza->execute([$folder, hash('sha256', 'folder:' . $folder), password_hash($parolaF, PASSWORD_DEFAULT)]);
    }
    $idAdmin = static fn (string $email): int => (int) $dbNou->query('SELECT id FROM admins WHERE email = ' . $dbNou->quote($email))->fetchColumn();
    $nou = static fn (string $metoda, string $cale, ?string $token, array $optiuni = []): array => $cere('nou', $metoda, $cale, $token, $optiuni);
    $j = static fn (array $r): array => is_array($x = json_decode($r['corp'], true)) ? $x : [];

    // H1: copiile din _versiuni sunt păzite ca originalul și nu apar cât folderul e închis.
    $r = $nou('GET', '/api/desktop/files?path=_versiuni', $tm);
    $numeFoldere = array_column($j($r)['folders'] ?? [], 'name');
    $verifica('D H1 aplicația: în _versiuni nu apare „secret” (folder cu parolă închis), apare „documente”', $r['cod'] === 200
        && !in_array('secret', $numeFoldere, true) && in_array('documente', $numeFoldere, true), $r['corp']);
    $r = $nou('GET', '/api/desktop/files?path=_versiuni/secret', $tm);
    $verifica('D H1 aplicația: _versiuni/secret → 423, folderul „secret”', $r['cod'] === 423 && ($j($r)['folder'] ?? '') === 'secret', $r['corp']);
    $r = $nou('GET', '/api/desktop/download?path=' . rawurlencode($versiuneSecreta), $tm);
    $verifica('D H1 aplicația: descărcarea unei copii din _versiuni/secret → 423', $r['cod'] === 423, $r['cod'] . ' ' . mb_substr($r['corp'], 0, 200));
    // Literele mari nu ocolesc parola.
    $r = $nou('GET', '/api/desktop/download?path=SECRET/plan.png', $tm);
    $r2 = $nou('GET', '/api/desktop/files?path=Secret/sub', $tm);
    $verifica('D aplicația: SECRET/plan.png și Secret/sub tot închise (423)', $r['cod'] === 423 && $r2['cod'] === 423, $r['corp'] . ' ' . $r2['corp']);
    // M3: un folder cu un subfolder cu parolă închis nu se șterge.
    $r = $nou('POST', '/api/desktop/delete', $tm, ['json' => ['path' => 'arhiva-proiecte', 'type' => 'folder']]);
    $verifica('D M3 aplicația: ștergerea unui folder cu un subfolder cu parolă închis → 423 (subfolderul), nimic șters', $r['cod'] === 423
        && ($j($r)['folder'] ?? '') === 'arhiva-proiecte/intern' && is_file($zonaNou . '/arhiva-proiecte/intern/doc.txt'), $r['corp']);
    // L4: nimic scris de mână în _versiuni.
    $arhiva = [
        'fișier nou în _versiuni' => ['POST', '/api/desktop/upload-new', ['multipart' => ['path' => '_versiuni', 'file' => ['x.txt', "x\n"]]]],
        'folder nou în _versiuni' => ['POST', '/api/desktop/folders', ['json' => ['path' => '_versiuni', 'name' => 'nou']]],
        'folder nou „_Versiuni” în rădăcină' => ['POST', '/api/desktop/folders', ['json' => ['path' => '', 'name' => '_Versiuni']]],
        'folderul public redenumit „_versiuni”' => ['POST', '/api/desktop/rename', ['json' => ['path' => 'public', 'new_name' => '_versiuni', 'type' => 'folder']]],
        'redenumirea unei copii din _versiuni' => ['POST', '/api/desktop/rename', ['json' => ['path' => '_versiuni/documente/preturi.xlsx/x.xlsx',
            'new_name' => 'y.xlsx', 'type' => 'file']]],
    ];
    foreach ($arhiva as $eticheta => [$metoda, $cale, $optiuni]) {
        $r = $nou($metoda, $cale, $tg, $optiuni);
        $verifica('D L4 aplicația: ' . $eticheta . ' → 409 „archive”', $r['cod'] === 409 && ($j($r)['reason'] ?? '') === 'archive', $r['cod'] . ' ' . $r['corp']);
    }
    $copiaPreluata = '_versiuni/documente/preturi.xlsx/de-salvat.xlsx';
    $nou('POST', '/api/desktop/checkout', $tg, ['json' => ['path' => $copiaPreluata, 'note' => '']]);
    $r = $nou('POST', '/api/desktop/upload', $tg, ['multipart' => ['path' => $copiaPreluata, 'release' => '1', 'file' => ['x.xlsx', "x\n"]]]);
    $nou('POST', '/api/desktop/release', $tg, ['json' => ['path' => $copiaPreluata, 'force' => '0']]);
    $verifica('D L4 aplicația: salvarea peste o copie din _versiuni (preluată) → 409 „archive”, nimic scris', $r['cod'] === 409
        && ($j($r)['reason'] ?? '') === 'archive' && !is_file($zonaNou . '/' . $copiaPreluata) && !is_dir($zonaNou . '/_versiuni/nou')
        && !is_dir($zonaNou . '/_Versiuni'), $r['corp']);

    // M1: greșelile se numără pe administrator și folder, nu pe cheie.
    $tm2 = 'bvd_' . substr(hash('sha256', 'test-fisiere-cloud:tok-magazin-2'), 0, 48);
    $dbNou->prepare('INSERT INTO admin_app_tokens (admin_id, name, token_hash) VALUES (?, ?, ?)')->execute([$idAdmin('magazin@exemplu.ro'), 'Al doilea',
        hash('sha256', $tm2)]);
    for ($i = 1; $i <= 5; $i++) {
        $nou('POST', '/api/desktop/folders/unlock', $tm, ['json' => ['path' => 'secret', 'password' => 'gresit' . $i]]);
    }
    $r = $nou('POST', '/api/desktop/folders/unlock', $tm2, ['json' => ['path' => 'secret', 'password' => 'parola-secret']]);
    $verifica('D M1 după 5 greșeli cu o cheie, a doua cheie a aceluiași administrator e oprită și cu parola bună (throttled)', $r['cod'] === 403
        && ($j($r)['reason'] ?? '') === 'throttled', $r['corp']);
    $r = $nou('GET', '/api/desktop/files?path=secret', $tm2);
    $verifica('D M1 423 arată pauza administratorului (wait_minutes > 0)', $r['cod'] === 423 && (int) ($j($r)['wait_minutes'] ?? 0) > 0, $r['corp']);
    // Zece încercări trimise deodată (serverul are 4 lucrători): cel mult 5 verificate.
    $multi = curl_multi_init();
    $manere = [];
    for ($i = 0; $i < 10; $i++) {
        $h = curl_init('http://127.0.0.1:' . $porturi['nou'] . '/api/desktop/folders/unlock');
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['path' => 'proiecte', 'password' => 'gresit-' . $i]),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tb, 'Content-Type: application/json']]);
        curl_multi_add_handle($multi, $h);
        $manere[] = $h;
    }
    do {
        $stare = curl_multi_exec($multi, $active);
        if ($active) {
            curl_multi_select($multi, 1.0);
        }
    } while ($active && $stare === CURLM_OK);
    $motive = [];
    foreach ($manere as $h) {
        $motive[] = (string) (json_decode((string) curl_multi_getcontent($h), true)['reason'] ?? '?');
        curl_multi_remove_handle($multi, $h);
        curl_close($h);
    }
    curl_multi_close($multi);
    $numarate = array_count_values($motive);
    $verifica('D M1 10 parole greșite trimise deodată: cel mult 5 verificate (wrong_password), restul „throttled”: ' . json_encode($numarate),
        ($numarate['wrong_password'] ?? 0) <= 5 && ($numarate['wrong_password'] ?? 0) + ($numarate['throttled'] ?? 0) === 10);

    // M5: lista folderelor cu parolă nu se poate citi → totul închis.
    $dbNou->exec('RENAME TABLE bunny_protected_folders TO bunny_protected_folders_ascuns');
    $r = $nou('GET', '/api/desktop/files?path=public', $tg);
    $r2 = $nou('POST', '/api/desktop/folders/unlock', $tg, ['json' => ['path' => 'secret', 'password' => 'parola-secret']]);
    $pg = $adm('GET', '/admin/bunny?path=public');
    $dbNou->exec('RENAME TABLE bunny_protected_folders_ascuns TO bunny_protected_folders');
    $verifica('D M5 parolele nu se pot citi: aplicația primește 503 (lista și deblocarea), adminul „Nu pot verifica acum parolele”, fără fișiere',
        $r['cod'] === 503 && str_contains($r['corp'], 'Nu pot verifica') && $r2['cod'] === 503 && $pg['cod'] === 200
        && str_contains($pg['corp'], 'Nu pot verifica acum parolele') && !str_contains($pg['corp'], 'a.png'), $r['cod'] . ' ' . $r['corp'] . ' / ' . $r2['corp']);

    // Adminul web: sesiuni noi (magazin, blog), jetonul fiecăreia.
    $jetonDin = static function (callable $c, string $cale = '/admin/bunny'): string {
        preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $c('GET', $cale)['corp'], $m);
        return (string) ($m[1] ?? '');
    };
    [$mag] = $sesiune('magazin@exemplu.ro');
    $jMag = $jetonDin($mag);
    [$blog] = $sesiune('blog@exemplu.ro');
    $jBlog = $jetonDin($blog);
    $jAdm = $jetonDin($adm);
    $p = $mag('GET', '/admin/bunny?path=secret');
    $verifica('D M1 adminul web: pauza magazinului (pusă din aplicație) se vede și în browser', str_contains($p['corp'], 'Prea multe încercări greșite'));

    // H1 în browser.
    $p = $mag('GET', '/admin/bunny?path=_versiuni');
    $p2 = $mag('GET', '/admin/bunny?path=_versiuni/secret');
    $x = $mag('GET', '/admin/bunny/download?path=' . rawurlencode($versiuneSecreta));
    $verifica('D H1 adminul web: _versiuni fără „secret”, _versiuni/secret arată poarta, descărcarea copiei refuzată (302, fără conținut)',
        $p['cod'] === 200 && str_contains($p['corp'], 'bunny-folder-name">documente<') && !str_contains($p['corp'], 'bunny-folder-name">secret<')
        && str_contains($p2['corp'], 'are parolă') && !str_contains($p2['corp'], 'plan.png')
        && $x['cod'] === 302 && !str_contains($x['corp'], 'PNG-secret'));
    $p = $adm('GET', '/admin/bunny?path=_versiuni/documente');
    $verifica('D L4 adminul web: în _versiuni fără „Folder nou”, „Urcă fișiere”, „Preia”, „Redenumește”', $p['cod'] === 200
        && !str_contains($p['corp'], 'id="bunny-open-upload-modal"') && !str_contains($p['corp'], 'data-action="bunny-checkout"' . "\n")
        && !str_contains($p['corp'], 'data-action="bunny-rename"' . "\n"));

    // H2: parola unui subfolder doar cu folderele de deasupra deschise; doar pe foldere; parola actuală prin aceeași frână.
    $protectie = static fn (string $tinta): int => (int) $dbNou->query('SELECT COUNT(*) FROM bunny_protected_folders WHERE folder_path = '
        . $dbNou->quote($tinta))->fetchColumn();
    $mag('POST', '/admin/bunny/folders/protect', ['path' => 'proiecte', 'target' => 'proiecte/public2', 'password' => 'parola-mea-1',
        'password_confirm' => 'parola-mea-1', '_csrf' => $jMag]);
    $p = $mag('GET', '/admin/bunny');
    $verifica('D H2 cu „proiecte” închis, magazinul nu poate pune parolă pe proiecte/public2', $protectie('proiecte/public2') === 0
        && str_contains($p['corp'], 'de deasupra are parolă'));
    $adm('POST', '/admin/bunny/folders/protect', ['path' => 'public', 'target' => 'public/a.png', 'password' => 'parola-mea-1',
        'password_confirm' => 'parola-mea-1', '_csrf' => $jAdm]);
    $p = $adm('GET', '/admin/bunny');
    $verifica('D H2 parola nu se pune pe un fișier (nici de Administratorul General)', $protectie('public/a.png') === 0
        && str_contains($p['corp'], 'doar pe un folder'));
    $mag('POST', '/admin/bunny/folders/unlock', ['target' => 'proiecte', 'path' => 'proiecte', 'password' => 'parola-proiecte', '_csrf' => $jMag]);
    $mag('POST', '/admin/bunny/folders/protect', ['path' => 'proiecte', 'target' => 'proiecte/public2', 'password' => 'parola-mea-1',
        'password_confirm' => 'parola-mea-1', '_csrf' => $jMag]);
    $verifica('D H2 după deblocarea lui „proiecte”, parola pe proiecte/public2 se pune', $protectie('proiecte/public2') === 1);
    for ($i = 1; $i <= 5; $i++) {
        $mag('POST', '/admin/bunny/folders/unprotect', ['path' => 'proiecte', 'target' => 'proiecte/public2', 'current_password' => 'gresit' . $i,
            '_csrf' => $jMag]);
    }
    $mag('POST', '/admin/bunny/folders/unprotect', ['path' => 'proiecte', 'target' => 'proiecte/public2', 'current_password' => 'parola-mea-1',
        '_csrf' => $jMag]);
    $p = $mag('GET', '/admin/bunny?path=proiecte');
    $verifica('D H2 scoaterea parolei: după 5 parole actuale greșite, nici cea bună nu mai trece (aceeași frână ca deblocarea)',
        $protectie('proiecte/public2') === 1 && str_contains($p['corp'], 'Prea multe încercări greșite'));

    // M2 + L5: Galeria și adresele CDN.
    $adm('POST', '/admin/bunny/folders/unlock', ['target' => 'secret', 'path' => 'secret', 'password' => 'parola-secret', '_csrf' => $jAdm]);
    $p = $adm('GET', '/admin/bunny?path=secret');
    $p2 = $adm('GET', '/admin/bunny?path=public');
    $verifica('D L5 în „secret” (deschis) fără adrese CDN și miniaturi de pe CDN; în „public” cu ele', $p['cod'] === 200 && str_contains($p['corp'], 'plan.png')
        && !str_contains($p['corp'], 'cdn.exemplu.invalid') && str_contains($p2['corp'], 'https://cdn.exemplu.invalid/public/a.png'));
    $p = $adm('GET', '/admin/bunny?path=_versiuni');
    $verifica('D H1 cu „secret” deschis, Administratorul General vede _versiuni/secret', str_contains($p['corp'], 'bunny-folder-name">secret<'));
    $x = $adm('POST', '/admin/bunny/import', ['path' => 'public', 'folder_id' => '', '_csrf' => $jAdm, 'paths' => ['secret/plan.png', $versiuneSecreta,
        'SECRET/plan.png', 'public/a.png']]);
    $galerie = $dbNou->query("SELECT image_url FROM gallery_images WHERE image_url LIKE 'https://cdn.exemplu.invalid/%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $p = $adm('GET', '/admin/bunny?path=public');
    $verifica('D M2 trimiterea în Galerie: fiecare cale verificată; ce e în „secret” (deschis sau nu, și copia din _versiuni, și SECRET/…) rămâne, '
        . 'public/a.png pleacă', $galerie === ['https://cdn.exemplu.invalid/public/a.png'] && str_contains($p['corp'], 'într-un folder cu parolă'),
        json_encode($galerie));

    // M4: confirm() fără ieșire din șirul JS (numele folderului, emailul din lacăt).
    $dbNou->prepare("INSERT INTO file_locks (storage, file_path, path_hash, admin_id, admin_email, note, origin, locked_at, expires_at)
        VALUES ('bunny', 'public/a.png', ?, ?, ?, '', 'web', NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))")
        ->execute([hash('sha256', 'bunny:public/a.png'), $idAdmin('blog@exemplu.ro'), "o'brien\"<b>@exemplu.ro"]);
    $p = $adm('GET', '/admin/bunny?path=public');
    $verifica('D M4 confirm(): numele folderului și emailul din lacăt ca JSON (\\u0027, \\u0022, \\u003C), nu ca text care închide șirul',
        str_contains($p['corp'], 'confirm(&quot;Ștergi folderul „x\\u0027-alert(1)-\\u0027”') && str_contains($p['corp'], 'o\\u0027brien\\u0022\\u003Cb\\u003E@')
        && !str_contains($p['corp'], "confirm('Ștergi folderul „x") && !str_contains($p['corp'], "preluat de o&#039;brien"));

    // L4 în browser.
    $adm('POST', '/admin/bunny/folders', ['path' => '_versiuni', 'name' => 'de-mana', '_csrf' => $jAdm]);
    $adm('POST', '/admin/bunny/rename', ['path' => '', 'target' => 'public', 'new_name' => '_versiuni', 'type' => 'folder', '_csrf' => $jAdm]);
    $p = $adm('GET', '/admin/bunny');
    $verifica('D L4 adminul web: folder nou în _versiuni și redenumirea spre „_versiuni” refuzate', !is_dir($zonaNou . '/_versiuni/de-mana')
        && is_dir($zonaNou . '/public') && str_contains($p['corp'], 'se completează singur'));

    // L3 + L6: limita de 64 MB și cererea peste post_max_size (90M aici).
    $mare = $dosarTest . '/mare.bin';
    $foarteMare = $dosarTest . '/foarte-mare.bin';
    foreach ([$mare => 65, $foarteMare => 95] as $fisier => $mb) {
        $f = fopen($fisier, 'wb');
        ftruncate($f, $mb * 1024 * 1024);
        fclose($f);
    }
    $x = $adm('POST', '/admin/bunny/upload', ['path' => 'public', '_csrf' => $jAdm, 'files[0]' => new CURLFile($mare, 'application/octet-stream', 'mare.bin')]);
    $p = $adm('GET', '/admin/bunny?path=public');
    $verifica('D L3 un fișier de 65 MB din browser: refuzat, „Fișierul e prea mare” (64 MB), nimic urcat', $x['cod'] === 302
        && str_contains($p['corp'], 'Fișierul e prea mare') && !is_file($zonaNou . '/public/mare.bin'));
    $x = $adm('POST', '/admin/bunny/upload', ['path' => 'public', '_csrf' => $jAdm, 'files[0]' => new CURLFile($foarteMare, 'application/octet-stream',
        'foarte-mare.bin')]);
    $p = $adm('GET', '/admin/bunny');
    $verifica('D L6 o cerere peste post_max_size: „Fișierul e prea mare”, nu „Sesiunea formularului a expirat”', $x['cod'] === 302
        && str_contains($p['corp'], 'Fișierul e prea mare') && !str_contains($p['corp'], 'Sesiunea formularului a expirat'), $x['cod'] . ' ' . $x['location']);
    @unlink($mare);
    @unlink($foarteMare);

    // L1: parola zonelor detectate nu ajunge în pagină și nu se ia din formular.
    $p = $adm('GET', '/admin/settings/bunny');
    $adm('POST', '/admin/settings/bunny/use-zone', ['zone_index' => '0', 'zone_name' => 'zona-straina', 'zone_password' => 'parola-straina', '_csrf' => $jAdm]);
    $verifica('D L1 Setări: fără zone_password în pagină; „Folosește zona” nu ia numele și parola din formular (fără zone detectate în sesiune, refuzat)',
        !str_contains($p['corp'], 'zone_password') && (string) $dbNou->query("SELECT `value` FROM settings WHERE `key` = 'bunny_storage_zone'")->fetchColumn()
        === $zona);

    // Cheile de aplicație: plafonul și Administratorul General.
    $cheie = $dbNou->prepare('INSERT INTO admin_app_tokens (admin_id, name, token_hash) VALUES (?, ?, ?)');
    $idBlog = $idAdmin('blog@exemplu.ro');
    $activeBlog = static fn (): int => (int) $dbNou->query('SELECT COUNT(*) FROM admin_app_tokens WHERE revoked_at IS NULL AND admin_id = ' . $idBlog)->fetchColumn();
    for ($i = $activeBlog(); $i < 10; $i++) {
        $cheie->execute([$idBlog, 'Calculator ' . $i, hash('sha256', 'cheie-blog-' . $i)]);
    }
    $blog('POST', '/admin/bunny/app/tokens', ['name' => 'A unsprezecea', '_csrf' => $jBlog]);
    $p = $blog('GET', '/admin/bunny/app');
    $verifica('D cheile: cu 10 chei active, a unsprezecea e refuzată, cu mesaj', $activeBlog() === 10 && str_contains($p['corp'], 'Ai deja 10 chei active'));
    $idCheieBlog = (int) $dbNou->query("SELECT id FROM admin_app_tokens WHERE admin_id = " . $idBlog . " AND revoked_at IS NULL ORDER BY id DESC LIMIT 1")
        ->fetchColumn();
    $mag('POST', '/admin/bunny/app/tokens/revoke', ['token_id' => (string) $idCheieBlog, '_csrf' => $jMag]);
    $verifica('D cheile: magazinul nu poate retrage cheia altcuiva', $activeBlog() === 10);
    $p = $adm('GET', '/admin/bunny/app');
    $adm('POST', '/admin/bunny/app/tokens/revoke', ['token_id' => (string) $idCheieBlog, '_csrf' => $jAdm]);
    $verifica('D cheile: Administratorul General vede cheile celorlalți (cu emailul) și o retrage pe a blogului', str_contains($p['corp'],
        'Cheile celorlalți administratori') && str_contains($p['corp'], 'blog@exemplu.ro') && $activeBlog() === 9);

    // Redenumirea unui folder (a doua revizie): subfolderele cu parolă închise, literele mari, numărătoarea greșelilor.
    foreach (['numarat/f.txt' => "f\n", 'ARHIVA-PROIECTE/x.txt' => "x\n"] as $cale => $continut) {
        @mkdir(dirname($zonaNou . '/' . $cale), 0770, true);
        file_put_contents($zonaNou . '/' . $cale, $continut);
    }
    $protejeaza->execute(['numarat', hash('sha256', 'folder:numarat'), password_hash('parola-numarat', PASSWORD_DEFAULT)]);
    $protectiiSub = static fn (string $prefix): array => $dbNou->query('SELECT folder_path FROM bunny_protected_folders WHERE folder_path LIKE '
        . $dbNou->quote($prefix . '%') . ' ORDER BY folder_path')->fetchAll(PDO::FETCH_COLUMN);
    $r = $nou('POST', '/api/desktop/rename', $tm, ['json' => ['path' => 'arhiva-proiecte', 'new_name' => 'ap-mutat', 'type' => 'folder']]);
    $verifica('D redenumire aplicația: un folder cu un subfolder cu parolă închis → 423 (subfolderul), nimic mutat', $r['cod'] === 423
        && ($j($r)['folder'] ?? '') === 'arhiva-proiecte/intern' && is_file($zonaNou . '/arhiva-proiecte/intern/doc.txt') && !is_dir($zonaNou . '/ap-mutat'),
        $r['corp']);
    $mag('POST', '/admin/bunny/rename', ['path' => '', 'target' => 'arhiva-proiecte', 'new_name' => 'ap-mutat', 'type' => 'folder', '_csrf' => $jMag]);
    $p = $mag('GET', '/admin/bunny');
    $verifica('D redenumire browser: același refuz, cu mesaj', !is_dir($zonaNou . '/ap-mutat') && str_contains($p['corp'], 'subfolder cu parolă'));
    $r = $nou('POST', '/api/desktop/rename', $tg, ['json' => ['path' => 'ARHIVA-PROIECTE', 'new_name' => 'mare-mutat', 'type' => 'folder']]);
    $verifica('D redenumirea lui „ARHIVA-PROIECTE” (alt folder, aceleași litere) cu arhiva-proiecte/intern închis: tot 423', $r['cod'] === 423, $r['corp']);
    $nou('POST', '/api/desktop/folders/unlock', $tg, ['json' => ['path' => 'arhiva-proiecte/intern', 'password' => 'parola-intern']]);
    $r = $nou('POST', '/api/desktop/rename', $tg, ['json' => ['path' => 'ARHIVA-PROIECTE', 'new_name' => 'mare-mutat', 'type' => 'folder']]);
    $r2 = $nou('GET', '/api/desktop/files?path=arhiva-proiecte/intern', $tm);
    $verifica('D cu el deschis, „ARHIVA-PROIECTE” → „mare-mutat” se face, dar protecția lui arhiva-proiecte/intern rămâne pe loc (mutarea e '
        . 'literă cu literă) și folderul rămâne închis pentru ceilalți', $r['cod'] === 200 && is_file($zonaNou . '/mare-mutat/x.txt')
        && $protectiiSub('arhiva-proiecte') === ['arhiva-proiecte/intern'] && $protectiiSub('mare-mutat') === [] && $r2['cod'] === 423, $r['corp']
        . ' ' . json_encode([$protectiiSub('arhiva-proiecte'), $protectiiSub('mare-mutat')]));
    for ($i = 1; $i <= 5; $i++) {
        $nou('POST', '/api/desktop/folders/unlock', $tb, ['json' => ['path' => 'numarat', 'password' => 'gresit' . $i]]);
    }
    $nou('POST', '/api/desktop/folders/unlock', $tg, ['json' => ['path' => 'numarat', 'password' => 'parola-numarat']]);
    $r = $nou('POST', '/api/desktop/rename', $tg, ['json' => ['path' => 'numarat', 'new_name' => 'numarat-2', 'type' => 'folder']]);
    $r2 = $nou('POST', '/api/desktop/folders/unlock', $tb, ['json' => ['path' => 'numarat-2', 'password' => 'parola-numarat']]);
    $verifica('D numărătoarea greșelilor ține de protecție, nu de cale: după 5 greșeli ale blogului, redenumirea „numarat” → „numarat-2” nu-i '
        . 'dă încercări noi (throttled și cu parola bună)', $r['cod'] === 200 && $r2['cod'] === 403 && ($j($r2)['reason'] ?? '') === 'throttled',
        $r['corp'] . ' ' . $r2['corp']);

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
