<?php

declare(strict_types=1);

/**
 * Schema pe care o produce CODUL unui site, pe o bază goală (WP-03, MIG-03), și ce se face din ea:
 * fixture-urile testelor migrărilor, fișierele generate ale migrărilor și verificarea migrărilor
 * față de cod. Portat din uneltele analizei migrărilor (harness.php, run.sh, compare.php).
 *
 * Schema codului: pe o bază goală rulează `scripts/install.php` al acelui cod, apoi fiecare funcție
 * a lui care își face schema din mers (`ensure*Schema`, `asigura*Schema*`, `pregatesteSchema`),
 * chemată prin Reflection, în treceri repetate până când amprenta nu se mai schimbă (cel puțin
 * două). Fiecare fază rulează într-un proces nou (memoriile statice ale funcțiilor nu ascund
 * nimic), pe o COPIE a codului (git archive, sau arborele de lucru copiat): clonele rămân neatinse.
 * Pluginurile sunt toate „pornit” (un înlocuitor al lui App\Support\Plugins, fără licență), deci
 * fiecare ramură a funcțiilor își face tabelele. Baza e inventată și goală; în ea ajung doar ce
 * scriu install.php și funcțiile (setările implicite, adminul cu adresa @exemplu.ro).
 *
 *   tools/dev/php.sh tools/dev/migrari/genereaza-fixture.php --cod=cms
 *   tools/dev/php.sh tools/dev/migrari/genereaza-fixture.php --cod=bs --repo=../Nou-site-bioscem@9f59e79
 *   … --server=mysql8          pe al doilea server (tools/dev/mysql8.sh; pornit și oprit aici dacă nu mergea)
 *   … --iesire=<dosar>         unde scrie <cod>.sql și <cod>-amprenta.json (implicit $CMS_DEV_DIR/fixture-migrari;
 *                              fixture-urile din git stau în tools/dev/migrari/vechi/, WP-04)
 *   … --pastreaza-baza         nu șterge baza cms_mig_fx_<cod> la final (pentru o privire de mână)
 *
 *   tools/dev/php.sh tools/dev/migrari/genereaza-fixture.php --asteptat
 *       database/migrari/asteptat.json (amprenta unei baze goale după `migreaza.php --aplica`),
 *       migrari.lock (id → sha256; o migrare deja trecută acolo cu alt sha e refuzată, afară de
 *       --rescrie-lacatul: o migrare publicată nu se schimbă) și ultima.php (pentru garda, WP-05:
 *       Registru::textUltima()).
 *
 *   tools/dev/php.sh tools/dev/migrari/genereaza-fixture.php --verifica [--server=mariadb|mysql8] [--repo=<clona CMS>@<commit>]
 *       Acceptanța migrărilor de bază (WP-03; poarta G-baza din porti.sh), pe MariaDB și pe MySQL 8
 *       (implicit amândouă): pe o bază goală toate migrările dau exact asteptat.json, fără avertismente,
 *       iar baseline-ul (până la GF_ULTIMA_DIN_BAZA) dă schema codului minus excluderile de mai jos
 *       (fiecare trebuie să existe în schema codului, altfel lista e veche) și diferențele declarate; pe
 *       baza făcută de cod („pe vechi”: install.php + funcțiile), planul are în grupul B (0001–0019)
 *       doar „există deja”, baseline-ul face 0 operații (totul „detectata”), schema rămâne aceeași,
 *       verificarea trece și a doua aplicare n-are nimic de făcut; fixture-ul SQL făcut pe MariaDB se
 *       încarcă pe fiecare server și dă înapoi aceeași amprentă; pe MySQL 8, codul vechi face aceeași
 *       schemă ca pe MariaDB (în afară de colațiile declarate); migrările de date (grupul C) lasă pe
 *       rândurile inventate din GF_RANDURI_GRUP_C exact ce lăsau funcțiile codului. Schema codului e a
 *       arborelui de lucru, cu instalarea de dinainte de migrări: din WP-05 install.php rulează migrările,
 *       deci scripts/install.php și database/schema.sql vin de la commit-ul din capul lui vechi/cms.sql
 *       (funcțiile ensure* sunt cele de acum, cu garda lor); --repo=<clonă>@<commit> = alt cod, întreg.
 *
 *   tools/dev/php.sh tools/dev/migrari/genereaza-fixture.php --compara a-amprenta.json b-amprenta.json …
 *       fiecare amprentă față de prima: tabele, coloane, indecși lipsă / în plus și definițiile diferite.
 *
 *   tools/dev/php.sh tools/dev/migrari/genereaza-fixture.php --fixture-uri [--cms=<commit>]
 *       Fixture-urile din git ale testului T2 (WP-04): tools/dev/migrari/vechi/<cod>.sql (doar structură) și
 *       extra-<cod>.txt (ce are acea schemă în plus față de asteptat.json), pentru cms, bs, bv, vt, gr. Codul
 *       vechi al unui site (bs, bv, vt, gr) e clona lui (CMS_DEV_REPO_BS, _BV, _VT, _GR) la „ultimul commit
 *       revizuit” din docs/de-portat.md: când acela se mută (un commit nou portat), fixture-ul se reface aici
 *       și T2 arată ce s-a schimbat. Sursa scrisă în fișiere e numele repo-ului (Nou-site-bioscem@<commit> …),
 *       oricum s-ar numi dosarul clonei. cms e acest repo la commit-ul scris în capul lui cms.sql (implicit HEAD la
 *       prima generare; --cms=<commit> îl schimbă): schema pe care o făcea codul comun înainte de migrări.
 *   tools/dev/php.sh tools/dev/migrari/genereaza-fixture.php --verifica-fixture-uri
 *       Fără să scrie nimic: fiecare fixture din git are amprenta schemei pe care o face codul din capul lui
 *       (refăcută acum, pe MariaDB), extra-<cod>.txt e la zi cu asteptat.json, iar sursa unui site vechi e
 *       „ultimul commit revizuit” al lui. Îl rulează tools/dev/test-migrari.php (T2).
 *
 * Căile relative sunt față de rădăcina repo-ului (tools/dev/php.sh pornește de acolo).
 * Bazele (cms_mig_fx_*) se fac și se șterg aici. Ieșirea are doar nume de obiecte și numere.
 * Procesul-copil (`--copil --faza=install|helperi`) primește și baze cms_mig_t_* (testele T6, T10 din
 * tools/dev/test-migrari.php rulează funcțiile codului vechi pe bazele lor).
 * Cod de ieșire 0 doar dacă totul a mers (la --verifica: dacă fiecare verificare a trecut).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// ============================================================================================
// Procesul-copil: o singură fază pe copia unui cod. Nu încarcă nimic din acest repo (clasele
// App\ sunt ale copiei), deci stă înaintea oricărui require de mai jos.
// ============================================================================================
if (($argv[1] ?? '') === '--copil') {
    gf_copil(array_slice($argv, 2));
    exit(0);
}

$radacina = (string) realpath(dirname(__DIR__, 3));
foreach (['Eroare', 'Server', 'Amprenta', 'Registru'] as $clasa) {
    require_once $radacina . '/src/Support/Migrari/' . $clasa . '.php';
}

use App\Support\Migrari\Amprenta;
use App\Support\Migrari\Registru;
use App\Support\Migrari\Server;

/**
 * Ce NU primește o instalare nouă din schema de azi a codului (GF_EXCLUSE) și tabelele cu colația
 * serverului (GF_COLATIE_SERVER): tools/dev/migrari/excluderi.php (aceeași listă în test-migrari.php).
 */
$gfExcluderi = require __DIR__ . '/excluderi.php';
define('GF_EXCLUSE', $gfExcluderi['excluse']);
define('GF_COLATIE_SERVER', $gfExcluderi['colatie_server']);

/** Fixture-urile din git (tools/dev/migrari/vechi/): codul → [titlu, site-ul din docs/de-portat.md, variabila clonei]. */
const GF_FIXTURE = [
    'cms' => ['ANDAXI-CMS (acest repo)', null, null],
    'bs' => ['Bioscem', 'bioscem', 'CMS_DEV_REPO_BS'],
    'bv' => ['Biovitality', 'biovitality', 'CMS_DEV_REPO_BV'],
    'vt' => ['vertij', 'vertij', 'CMS_DEV_REPO_VT'],
    'gr' => ['grafoanaytis', 'grafoanaytis', 'CMS_DEV_REPO_GR'],
];

/** Ultima migrare a baseline-ului (grupurile B și C): schema codului se compară cu migrările până la ea. */
const GF_ULTIMA_DIN_BAZA = 'blog/0024_legaturi_categorii';

/**
 * Rândurile inventate pentru migrările de date (grupul C, pasul 6 din --verifica): fiecare caz pe care
 * codul vechi îl aducea la zi la fiecare cerere. Aceleași rânduri trec o dată prin funcțiile codului și o
 * dată prin migrări (GF_CITIRI_GRUP_C trebuie să iasă la fel). Doar date inventate (@exemplu.ro).
 */
const GF_RANDURI_GRUP_C = [
    // 0020: rolurile adminilor. role gol + roles_json (revizuirea WP-03), gol, doar spații (gol la colația
    // PAD SPACE a coloanei), un tab (nu e gol pentru server), cu spațiu la coadă, necunoscut, mai multe
    // roluri repetate, JSON greșit, un obiect JSON, valori care nu sunt texte, listă goală, deja la zi.
    "INSERT INTO admins (id, email, password_hash, role, roles_json) VALUES
        (101, 'rol-gol-blog@exemplu.ro', 'x', '', '[\"administrator_blog\"]'),
        (102, 'rol-gol@exemplu.ro', 'x', '', NULL),
        (103, 'rol-spatii@exemplu.ro', 'x', '   ', '[\"administrator_magazin\"]'),
        (104, 'rol-tab@exemplu.ro', 'x', CHAR(9 USING utf8mb4), '[\"administrator_blog\"]'),
        (105, 'rol-spatiu-final@exemplu.ro', 'x', 'administrator_blog ', NULL),
        (106, 'rol-necunoscut@exemplu.ro', 'x', 'necunoscut', '[\"altceva\", 7]'),
        (107, 'rol-multe@exemplu.ro', 'x', 'administrator_blog', '[\"administrator_magazin\", \"administrator_emailuri\", \"administrator_magazin\"]'),
        (108, 'rol-json-gresit@exemplu.ro', 'x', 'administrator_general', 'nu e json'),
        (109, 'rol-obiect@exemplu.ro', 'x', 'administrator_magazin', '{\"a\": \"administrator_blog\"}'),
        (110, 'rol-amestec@exemplu.ro', 'x', 'administrator_emailuri', '[null, true, 5, \" administrator_blog \"]'),
        (111, 'rol-lista-goala@exemplu.ro', 'x', '', '[]'),
        (112, 'rol-la-zi@exemplu.ro', 'x', 'administrator_general', '[\"administrator_general\"]'),
        (113, 'rol-json-gol@exemplu.ro', 'x', 'administrator_blog', ''),
        (114, 'rol-json-spatii@exemplu.ro', 'x', 'administrator_emailuri', '  ')",
    // 0021: galeria din coloana veche gallery_json (singura coloană veche a catalogului din schema codului).
    "INSERT INTO products (id, name, slug, gallery_json, gallery_images_json) VALUES
        (401, 'Produs demo', 'produs-demo', '[\"/uploads/demo/a.png\"]', NULL),
        (402, 'Produs cu galerie', 'produs-cu-galerie', '[\"/uploads/demo/vechi.png\"]', '[\"/uploads/demo/nou.png\"]'),
        (403, 'Produs cu galerie goala', 'produs-cu-galerie-goala', '[\"/uploads/demo/b.png\"]', '')",
    // 0022: numele gol al cupoanelor.
    "INSERT INTO coupons (id, code, name) VALUES (201, 'DEMO-GOL', ''), (202, 'DEMO-NUME', 'Are nume')",
    // 0023: recenziile din tabelul vechi (una deja copiată, una nu) și sursa celor fără sursă.
    "INSERT INTO reviews (product_id, user_name, rating, review_text, is_approved, created_at) VALUES
        (401, 'Client Demo', 5, 'Foarte bun', 1, '2026-01-02 10:00:00'), (401, 'Alt Client', 4, NULL, 0, '2026-01-03 10:00:00')",
    "INSERT INTO product_reviews (product_id, user_name, rating, review_text, is_approved, created_at, source) VALUES
        (401, 'Client Demo', 5, 'Foarte bun', 1, '2026-01-02 10:00:00', 'product_page'), (401, 'Fara Sursa', 3, 'ok', 1, '2026-01-04 10:00:00', '')",
    // 0024: legăturile articol–categorie din categoria principală.
    "INSERT INTO blog_categories (id, name, slug) VALUES (301, 'Categorie demo', 'categorie-demo'), (302, 'A doua demo', 'a-doua-demo')",
    "INSERT INTO blog_posts (id, title, slug, content, published_at, category_id) VALUES
        (311, 'Fara legatura', 'fara-legatura', 'x', '2026-01-01 10:00:00', 301),
        (312, 'Cu legatura', 'cu-legatura', 'x', '2026-01-01 10:00:00', 302),
        (313, 'Fara categorie', 'fara-categorie', 'x', '2026-01-01 10:00:00', NULL)",
    'INSERT INTO blog_post_categories (post_id, category_id) VALUES (312, 302)',
];

/** Ce se compară după pasul 6 (doar rândurile inventate de mai sus). */
const GF_CITIRI_GRUP_C = [
    'admins (0020)' => 'SELECT id, role, roles_json FROM admins WHERE id BETWEEN 101 AND 114 ORDER BY id',
    'products.gallery_images_json (0021)' => 'SELECT id, gallery_images_json FROM products WHERE id BETWEEN 401 AND 403 ORDER BY id',
    'coupons.name (0022)' => 'SELECT id, name FROM coupons WHERE id IN (201, 202) ORDER BY id',
    'product_reviews (0023)' => 'SELECT product_id, user_name, rating, review_text, is_approved, created_at, source FROM product_reviews
        WHERE product_id = 401 ORDER BY created_at, user_name',
    'blog_post_categories (0024)' => 'SELECT post_id, category_id FROM blog_post_categories WHERE post_id BETWEEN 311 AND 313 ORDER BY post_id, category_id',
];

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$lucru = $dosarDev . '/fixture-migrari';
$utilizator = (string) (getenv('DB_USERNAME') ?: 'root');
$parola = (string) (getenv('DB_PASSWORD') ?: '');
$sockMaria = (string) (ini_get('pdo_mysql.default_socket') ?: (getenv('CMS_DEV_SOCK') ?: '/tmp/claude-0/maria/sock'));
$sockMy8 = (string) (getenv('CMS_DEV_SOCK_MYSQL8') ?: '/tmp/claude-0/my8/run/sock');
$dosarMigrari = $radacina . '/database/migrari';

$opreste = static function (string $mesaj): never {
    fwrite(STDERR, 'EROARE: ' . $mesaj . "\n");
    exit(1);
};

// ---------------------------------------------------------------------------- argumentele
$comanda = null;
$cod = null;
$repo = null;
$servere = null;
$iesire = $lucru;
$pastreaza = false;
$rescrieLacatul = false;
$deComparat = [];
$commitCms = null;
foreach (array_slice($argv, 1) as $argument) {
    if (in_array($argument, ['--asteptat', '--verifica', '--compara', '--fixture-uri', '--verifica-fixture-uri'], true)) {
        if ($comanda !== null) {
            $opreste('o singură comandă odată (--cod, --asteptat, --verifica, --compara, --fixture-uri, --verifica-fixture-uri)');
        }
        $comanda = substr($argument, 2);
    } elseif (preg_match('/^--cms=([0-9a-f]{7,40})$/', $argument, $m) === 1) {
        $commitCms = $m[1];
    } elseif (preg_match('/^--cod=([a-z][a-z0-9]{0,9})$/', $argument, $m) === 1) {
        if ($comanda !== null && $comanda !== 'cod') {
            $opreste('o singură comandă odată (--cod, --asteptat, --verifica, --compara)');
        }
        $comanda = 'cod';
        $cod = $m[1];
    } elseif (str_starts_with($argument, '--repo=')) {
        $repo = substr($argument, strlen('--repo='));
    } elseif (preg_match('/^--server=(mariadb|mysql8)$/', $argument, $m) === 1) {
        $servere = [$m[1]];
    } elseif (str_starts_with($argument, '--iesire=')) {
        $iesire = rtrim(substr($argument, strlen('--iesire=')), '/');
        if (!str_starts_with($iesire, '/')) {
            $iesire = (string) getcwd() . '/' . $iesire;
        }
    } elseif ($argument === '--pastreaza-baza') {
        $pastreaza = true;
    } elseif ($argument === '--rescrie-lacatul') {
        $rescrieLacatul = true;
    } elseif ($comanda === 'compara' && !str_starts_with($argument, '--')) {
        $deComparat[] = $argument;
    } else {
        $opreste('argument necunoscut: ' . $argument . ' (vezi capul fișierului)');
    }
}
if ($comanda === null) {
    $opreste('lipsește comanda: --cod=<cod> [--repo=<clonă>@<commit>], --asteptat, --verifica sau --compara (vezi capul fișierului)');
}
if ($repo !== null && !in_array($comanda, ['cod', 'verifica'], true)) {
    $opreste('--repo merge doar cu --cod=<cod> și --verifica');
}
if ($commitCms !== null && $comanda !== 'fixture-uri') {
    $opreste('--cms=<commit> merge doar cu --fixture-uri');
}

// ------------------------------------------------------------------------- --compara (fără bază)
if ($comanda === 'compara') {
    if (count($deComparat) < 2) {
        $opreste('--compara cere cel puțin două amprente (fișierele <cod>-amprenta.json)');
    }
    $amprente = [];
    foreach ($deComparat as $fisier) {
        $date = is_file($fisier) ? json_decode((string) file_get_contents($fisier), true) : null;
        if (!is_array($date) || !is_array($date['tabele'] ?? null)) {
            $opreste($fisier . ' nu e o amprentă (forma lui Amprenta: {"format", "tabele"})');
        }
        $amprente[basename($fisier)] = $date;
    }
    $prima = array_key_first($amprente);
    $numara = static fn (array $a): string => count($a['tabele']) . ' tabele, '
        . array_sum(array_map(static fn (array $t): int => count($t['coloane']), $a['tabele'])) . ' coloane, '
        . array_sum(array_map(static fn (array $t): int => count($t['indecsi']), $a['tabele'])) . ' indecși, '
        . array_sum(array_map(static fn (array $t): int => count($t['fk']), $a['tabele'])) . ' chei străine';
    foreach ($amprente as $nume => $a) {
        echo $nume, ': ', $numara($a), "\n";
    }
    foreach ($amprente as $nume => $a) {
        if ($nume === $prima) {
            continue;
        }
        $d = Amprenta::compara($amprente[$prima], $a);
        echo "\n== ", $nume, ' față de ', $prima, ': lipsesc ', count($d['lipsa']), ', în plus ', count($d['in_plus']),
            ', diferite ', count($d['avertismente']), "\n";
        foreach ($d['lipsa'] as $obiect) {
            echo '  lipsește  ', $obiect, "\n";
        }
        foreach ($d['in_plus'] as $obiect) {
            echo '  în plus   ', $obiect, "\n";
        }
        foreach ($d['avertismente'] as $text) {
            echo '  diferit   ', $text, "\n";
        }
    }
    exit(0);
}

// ---------------------------------------------------------------------------------- serverele
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
$servere ??= $comanda === 'verifica' ? ['mariadb', 'mysql8'] : ['mariadb'];
$socket = ['mariadb' => $sockMaria, 'mysql8' => $sockMy8];
$numeServer = ['mariadb' => 'MariaDB', 'mysql8' => 'MySQL 8'];
$mysql8Pornit = false;
if (!$merge($sockMaria) && in_array('mariadb', $servere, true)) {
    $opreste('MariaDB nu răspunde pe ' . $sockMaria . ' (CMS_DEV_SOCK; docs/DEZVOLTARE.md)');
}
if (in_array('mysql8', $servere, true) && !$merge($sockMy8)) {
    exec(escapeshellarg($radacina . '/tools/dev/mysql8.sh') . ' porneste 2>&1', $iesireMy8, $codMy8);
    $mysql8Pornit = $codMy8 === 0 && $merge($sockMy8);
    if (!$mysql8Pornit) {
        $opreste('MySQL 8 nu merge (tools/dev/mysql8.sh porneste): ' . implode(' ', $iesireMy8));
    }
}

$bazeFacute = [];
$dosareDeSters = [];
register_shutdown_function(static function () use (&$bazeFacute, &$dosareDeSters, $conectare, &$mysql8Pornit, &$pastreaza, $radacina): void {
    foreach ($bazeFacute as $sock => $baze) {
        try {
            $pdo = $conectare($sock);
            foreach (array_keys($baze) as $baza) {
                if (!$pastreaza) {
                    $pdo->exec('DROP DATABASE IF EXISTS `' . $baza . '`');
                }
            }
        } catch (Throwable) {
        }
    }
    foreach ($dosareDeSters as $dosar) {
        gf_sterge($dosar);
    }
    if ($mysql8Pornit) {
        exec(escapeshellarg($radacina . '/tools/dev/mysql8.sh') . ' opreste > /dev/null 2>&1');
    }
});

$bazaNoua = static function (string $sock, string $nume) use (&$bazeFacute, $conectare): PDO {
    if (preg_match('/^cms_mig_fx_[a-z0-9_]+$/', $nume) !== 1) {
        throw new RuntimeException('nume de bază greșit: ' . $nume . ' (doar cms_mig_fx_*)');
    }
    $root = $conectare($sock);
    $root->exec('DROP DATABASE IF EXISTS `' . $nume . '`');
    $root->exec('CREATE DATABASE `' . $nume . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $bazeFacute[$sock][$nume] = true;
    return $conectare($sock, $nume);
};
$amprentaBazei = static function (string $sock, string $baza) use ($conectare): array {
    $db = $conectare($sock, $baza);
    return Amprenta::citeste($db, Server::detecteaza($db));
};
$descrie = static fn (array $a): string => count($a['tabele']) . ' tabele, '
    . array_sum(array_map(static fn (array $t): int => count($t['coloane']), $a['tabele'])) . ' coloane, '
    . array_sum(array_map(static fn (array $t): int => count($t['indecsi']), $a['tabele'])) . ' indecși, '
    . array_sum(array_map(static fn (array $t): int => count($t['fk']), $a['tabele'])) . ' chei străine';

// ------------------------------------------------------------------------- copia codului
/**
 * Copia codului de citit: `<clonă>@<commit>` cu git archive (refolosită dacă există), altfel
 * arborele de lucru al acestui repo (src, scripts, database, config), copiat din nou de fiecare dată.
 *
 * @return array{dosar: string, eticheta: string}
 */
$copieCod = static function (?string $repo) use ($radacina, $lucru, $opreste): array {
    @mkdir($lucru, 0777, true);
    if ($repo !== null) {
        $clona = $repo;
        $commit = null;
        if (preg_match('/^(.*)@([0-9A-Za-z._\/-]+)$/', $repo, $m) === 1) {
            [$clona, $commit] = [$m[1], $m[2]];
        }
        if ($commit === null) {
            $opreste('--repo=<clonă>@<commit>: lipsește commit-ul');
        }
        if (!str_starts_with($clona, '/')) {
            $clona = (string) getcwd() . '/' . $clona;
        }
        $clona = (string) realpath($clona);
        // Un worktree (`git worktree add`) are `.git` fișier, nu dosar: e tot o clonă (ca în tools/inghetare/lib/Generator.php).
        if ($clona === '' || (!is_dir($clona . '/.git') && !is_file($clona . '/.git'))) {
            $opreste('--repo: ' . $repo . ' nu e o clonă git');
        }
        $scurt = trim((string) shell_exec('git -C ' . escapeshellarg($clona) . ' rev-parse --short ' . escapeshellarg($commit . '^{commit}') . ' 2>/dev/null'));
        if (preg_match('/^[0-9a-f]{7,40}$/', $scurt) !== 1) {
            $opreste('--repo: commit necunoscut în ' . $clona . ': ' . $commit);
        }
        $dosar = $lucru . '/cod-' . basename($clona) . '-' . $scurt;
        if (!is_file($dosar . '/scripts/install.php')) {
            gf_sterge($dosar);
            mkdir($dosar, 0777, true);
            exec('git -C ' . escapeshellarg($clona) . ' archive ' . escapeshellarg($scurt) . ' | tar -x -C ' . escapeshellarg($dosar), $_, $codTar);
            if ($codTar !== 0) {
                $opreste('git archive ' . $scurt . ' din ' . $clona . ' n-a mers');
            }
        }
        $eticheta = basename($clona) . '@' . $scurt;
    } else {
        $dosar = $lucru . '/cod-acum';
        gf_sterge($dosar);
        mkdir($dosar, 0777, true);
        foreach (['src', 'scripts', 'database', 'config'] as $parte) {
            gf_copiaza($radacina . '/' . $parte, $dosar . '/' . $parte);
        }
        $head = trim((string) shell_exec('git -C ' . escapeshellarg($radacina) . ' rev-parse --short HEAD 2>/dev/null'));
        $modificat = trim((string) shell_exec('git -C ' . escapeshellarg($radacina) . ' status --porcelain -- src scripts database config 2>/dev/null')) !== '';
        // Din WP-05 install.php rulează migrările: schema „codului” e cea a instalării de dinainte (install.php și
        // schema.sql de la commit-ul din capul lui vechi/cms.sql), plus funcțiile ensure* de acum. Așa arată un site
        // vechi pe codul comun, înainte de baseline (tools/dev/migrari/pe-vechi.php face la fel baza de dezvoltare).
        $vechi = gf_commitInainteDeMigrari($radacina);
        if ($vechi === null) {
            $opreste('nu găsesc „-- sursa: ANDAXI-CMS@<commit>” în capul lui tools/dev/migrari/vechi/cms.sql');
        }
        foreach (['scripts/install.php', 'database/schema.sql'] as $fisierVechi) {
            exec('git -C ' . escapeshellarg($radacina) . ' show ' . escapeshellarg($vechi . ':' . $fisierVechi) . ' > '
                . escapeshellarg($dosar . '/' . $fisierVechi) . ' 2>/dev/null', $_, $codShow);
            if ($codShow !== 0) {
                $opreste('git show ' . $vechi . ':' . $fisierVechi . ' n-a mers');
            }
        }
        $eticheta = 'ANDAXI-CMS@' . ($head !== '' ? $head : '?') . ($modificat ? ' (+ modificări necomise)' : '') . ', install.php de la ' . $vechi;
    }
    foreach (['src', 'scripts/install.php', 'database/schema.sql'] as $necesar) {
        if (!file_exists($dosar . '/' . $necesar)) {
            $opreste('copia codului (' . $eticheta . ') nu are ' . $necesar);
        }
    }
    @mkdir($dosar . '/storage/logs', 0777, true);
    return ['dosar' => $dosar, 'eticheta' => $eticheta];
};

/** O fază (install | helperi) într-un proces nou, pe copia codului. @return array<string, mixed> */
$copil = static function (string $faza, string $dosarCod, string $sock, string $baza): array {
    $comanda = [PHP_BINARY, '-d', 'memory_limit=512M', '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'error_reporting=E_ALL',
        '-d', 'allow_url_fopen=0', __FILE__, '--copil', '--faza=' . $faza, '--dosar=' . $dosarCod, '--sock=' . $sock, '--baza=' . $baza];
    $env = getenv();
    // Nimic din codul vechi nu are voie să iasă în afară (ca în tools/dev/comun.sh).
    foreach (['http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'all_proxy'] as $proxy) {
        $env[$proxy] = 'http://127.0.0.1:9';
    }
    $proces = proc_open($comanda, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, $dosarCod, $env);
    if (!is_resource($proces)) {
        return ['erori' => ['nu pot porni procesul fazei ' . $faza]];
    }
    $out = (string) stream_get_contents($pipe[1]);
    $err = (string) stream_get_contents($pipe[2]);
    fclose($pipe[1]);
    fclose($pipe[2]);
    $codIesire = proc_close($proces);
    $rezultat = json_decode($out, true);
    if (!is_array($rezultat) || $codIesire !== 0) {
        return ['erori' => ['faza ' . $faza . ' s-a oprit (cod ' . $codIesire . '): ' . trim(substr($err !== '' ? $err : $out, 0, 600))]];
    }
    $rezultat['avertismente_php'] = preg_match_all('/^PHP (Warning|Notice|Deprecated):/m', $err)
        + preg_match_all('/^(Warning|Notice|Deprecated):/m', $err);
    return $rezultat;
};

/**
 * Schema codului din $dosarCod pe o bază goală: install.php, apoi funcțiile, până când amprenta nu
 * se mai schimbă (cel puțin două treceri, cel mult șase).
 *
 * @return array{amprenta: array, treceri: list<string>}
 */
$schemaCodului = static function (string $dosarCod, string $sock, string $baza) use ($bazaNoua, $copil, $amprentaBazei, $opreste): array {
    $bazaNoua($sock, $baza);
    $install = $copil('install', $dosarCod, $sock, $baza);
    if (($install['erori'] ?? []) !== []) {
        $opreste('install.php: ' . implode('; ', $install['erori']));
    }
    $treceri = ['install.php: ' . (int) ($install['instructiuni'] ?? 0) . ' instrucțiuni (' . (int) ($install['esuate'] ?? 0) . ' eșuate)'];
    $anterioara = null;
    for ($trecere = 1; $trecere <= 6; $trecere++) {
        $r = $copil('helperi', $dosarCod, $sock, $baza);
        if (($r['erori'] ?? []) !== []) {
            $opreste('trecerea ' . $trecere . ' prin funcții: ' . implode('; ', array_slice($r['erori'], 0, 5)));
        }
        $amprenta = $amprentaBazei($sock, $baza);
        $treceri[] = 'trecerea ' . $trecere . ': ' . (int) ($r['metode'] ?? 0) . ' funcții, ' . (int) ($r['instructiuni'] ?? 0)
            . ' instrucțiuni (' . (int) ($r['esuate'] ?? 0) . ' eșuate)' . ((int) ($r['avertismente_php'] ?? 0) > 0 ? ', ' . $r['avertismente_php'] . ' avertismente PHP' : '');
        if ($anterioara !== null && Amprenta::json($amprenta) === Amprenta::json($anterioara)) {
            return ['amprenta' => $amprenta, 'treceri' => $treceri];
        }
        $anterioara = $amprenta;
    }
    $opreste('schema codului nu s-a oprit din schimbat după 6 treceri prin funcții');
};

/** Schema bazei ca SQL (doar structură), din SHOW CREATE TABLE, fără AUTO_INCREMENT=N. */
$sqlSchema = static function (PDO $db, string $antet): string {
    $tabele = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")
        ->fetchAll(PDO::FETCH_COLUMN);
    $sql = $antet . "SET FOREIGN_KEY_CHECKS = 0;\n\n";
    foreach ($tabele as $tabel) {
        $creare = (string) $db->query('SHOW CREATE TABLE `' . str_replace('`', '``', (string) $tabel) . '`')->fetch(PDO::FETCH_NUM)[1];
        $creare = (string) preg_replace('/\) ENGINE=(\S+) AUTO_INCREMENT=\d+/', ') ENGINE=$1', $creare);
        $sql .= $creare . ";\n\n";
    }
    return $sql . "SET FOREIGN_KEY_CHECKS = 1;\n";
};
$incarcaSql = static function (string $sock, string $baza, string $sql) use ($bazaNoua): void {
    $db = $bazaNoua($sock, $baza);
    $db->exec($sql);
};

/** scripts/migreaza.php ca proces separat, pe o bază și un server anume: [cod, stdout, stderr]. */
$migreaza = static function (string $sock, string $baza, array $argumente) use ($radacina, $utilizator, $parola, $lucru): array {
    $comanda = array_merge([PHP_BINARY, '-d', 'pdo_mysql.default_socket=' . $sock, '-d', 'display_errors=stderr', '-d', 'log_errors=0',
        '-d', 'error_reporting=E_ALL', $radacina . '/scripts/migreaza.php'], $argumente);
    $env = getenv();
    unset($env['SITE_SLUG'], $env['MIGRARI_TEST_DOSAR'], $env['MIGRARI_TEST_OPRESTE_DUPA'], $env['MIGRARI_TEST_OPRESTE_IN']);
    $env = array_merge($env, ['DB_HOST' => 'localhost', 'DB_PORT' => '3306', 'DB_DATABASE' => $baza, 'DB_USERNAME' => $utilizator,
        'DB_PASSWORD' => $parola, 'MIGRARI_TEST_JURNAL' => $lucru . '/migrari.log', 'MIGRARI_TEST_PAUZA_BUCATI_MS' => '0']);
    $proces = proc_open($comanda, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, $radacina, $env);
    $out = (string) stream_get_contents($pipe[1]);
    $err = (string) stream_get_contents($pipe[2]);
    fclose($pipe[1]);
    fclose($pipe[2]);
    return [proc_close($proces), $out, $err];
};
$proprietari = static function () use ($radacina): array {
    $catalog = require $radacina . '/config/plugins.php';
    return array_merge(['nucleu'], array_keys((array) ($catalog['pluginuri'] ?? [])));
};

// ================================================================================== --cod
if ($comanda === 'cod') {
    $copie = $copieCod($repo);
    $dosareDeSters[] = $repo === null ? $copie['dosar'] : null;
    $dosareDeSters = array_values(array_filter($dosareDeSters));
    $server = $servere[0];
    $baza = 'cms_mig_fx_' . $cod;
    echo 'Schema codului ', $copie['eticheta'], ' pe ', $numeServer[$server], ', baza ', $baza, "\n";
    $rezultat = $schemaCodului($copie['dosar'], $socket[$server], $baza);
    foreach ($rezultat['treceri'] as $linie) {
        echo '  ', $linie, "\n";
    }
    if (!is_dir($iesire) && !@mkdir($iesire, 0777, true) && !is_dir($iesire)) {
        $opreste('nu pot face dosarul de ieșire ' . $iesire);
    }
    $versiune = (string) $conectare($socket[$server])->query('SELECT VERSION()')->fetchColumn();
    $antet = '-- Schema pe care o face codul ' . $copie['eticheta'] . ' pe o bază goală: scripts/install.php, apoi funcțiile'
        . "\n-- ensure*/asigura* până nu se mai schimbă (" . $numeServer[$server] . ' ' . $versiune . "). Doar structură, niciun rând.\n"
        . '-- Generat de tools/dev/migrari/genereaza-fixture.php --cod=' . $cod . ($repo !== null ? ' --repo=…@' . substr($copie['eticheta'], strrpos($copie['eticheta'], '@') + 1) : '')
        . "; nu se editează de mână.\n";
    file_put_contents($iesire . '/' . $cod . '.sql', $sqlSchema($conectare($socket[$server], $baza), $antet));
    file_put_contents($iesire . '/' . $cod . '-amprenta.json', Amprenta::json($rezultat['amprenta']));
    echo 'genereaza-fixture ', $cod, ': ', $descrie($rezultat['amprenta']), '; scrise: ', $iesire, '/', $cod, '.sql, ', $cod, "-amprenta.json\n";
    exit(0);
}

// ================================================================ --fixture-uri, --verifica-fixture-uri
if ($comanda === 'fixture-uri' || $comanda === 'verifica-fixture-uri') {
    $scrieFisiere = $comanda === 'fixture-uri';
    $sock = $socket['mariadb'];
    $dosarVechi = $radacina . '/tools/dev/migrari/vechi';
    $asteptatFix = is_file($dosarMigrari . '/asteptat.json') ? json_decode((string) file_get_contents($dosarMigrari . '/asteptat.json'), true) : null;
    if (!is_array($asteptatFix) || !is_array($asteptatFix['tabele'] ?? null)) {
        $opreste('database/migrari/asteptat.json lipsește sau nu e o amprentă (genereaza-fixture.php --asteptat)');
    }
    /** „Ultimul commit revizuit” al unui site vechi, din docs/de-portat.md (tools/dev/de-portat.php --revizuit). */
    $revizuit = static function (string $site) use ($radacina, $opreste): string {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($radacina . '/tools/dev/de-portat.php') . ' --revizuit=' . escapeshellarg($site) . ' 2>&1', $iesire, $cod);
        $commit = trim(implode('', $iesire));
        if ($cod !== 0 || preg_match('/^[0-9a-f]{7,40}$/', $commit) !== 1) {
            $opreste('nu găsesc „ultimul commit revizuit” al ' . $site . ' în docs/de-portat.md: ' . $commit);
        }
        return $commit;
    };
    /** Sursa scrisă în capul unui fixture: [eticheta, commit] sau null. */
    $sursaFixture = static function (string $fisier): ?array {
        $cap = (string) @file_get_contents($fisier, false, null, 0, 2000);
        return preg_match('/^-- sursa: (\S+)@([0-9a-f]{7,40})$/m', $cap, $m) === 1 ? [$m[1], $m[2]] : null;
    };
    $implicite = ['CMS_DEV_REPO_BS' => 'Nou-site-bioscem', 'CMS_DEV_REPO_BV' => 'Mutare-Site-Biovitality',
        'CMS_DEV_REPO_VT' => 'vertij-tinitus-refacut', 'CMS_DEV_REPO_GR' => 'site-nou-grafoanaytis.ro'];
    $extraText = static function (string $cod, array $inPlus, string $sursa): string {
        sort($inPlus);
        return '# Obiectele pe care schema codului ' . $sursa . ' (fixture-ul ' . $cod . ".sql) le are în plus față de\n"
            . "# database/migrari/asteptat.json: rămân pe site după baseline (migrările nu le ating). Testul T2 cere\n"
            . "# exact lista asta după --aplica. Scris de tools/dev/migrari/genereaza-fixture.php --fixture-uri.\n"
            . implode("\n", $inPlus) . ($inPlus !== [] ? "\n" : '');
    };
    $trecuteF = 0;
    $esuateF = 0;
    $verificaF = static function (string $eticheta, bool $ok, string $detalii = '') use (&$trecuteF, &$esuateF): void {
        if ($ok) {
            $trecuteF++;
            echo '  OK    ', $eticheta, "\n";
        } else {
            $esuateF++;
            echo '  EȘEC  ', $eticheta, ($detalii !== '' ? ': ' . trim(substr($detalii, 0, 2000)) : ''), "\n";
        }
    };
    if ($scrieFisiere && !is_dir($dosarVechi)) {
        mkdir($dosarVechi, 0777, true);
    }
    $versiune = (string) $conectare($sock)->query('SELECT VERSION()')->fetchColumn();
    foreach (GF_FIXTURE as $codF => [$titlu, $site, $variabila]) {
        $fisierSql = $dosarVechi . '/' . $codF . '.sql';
        $fisierExtra = $dosarVechi . '/extra-' . $codF . '.txt';
        $dinCap = $sursaFixture($fisierSql);
        if ($variabila === null) {
            $clona = $radacina;
            $eticheta = 'ANDAXI-CMS';
            $commit = $scrieFisiere ? ($commitCms ?? $dinCap[1] ?? trim((string) shell_exec('git -C ' . escapeshellarg($radacina) . ' rev-parse --short HEAD'))) : ($dinCap[1] ?? '');
        } else {
            $clona = (string) (getenv($variabila) ?: dirname($radacina) . '/' . $implicite[$variabila]);
            // Numele repo-ului, nu al dosarului clonei: eticheta intră în capul fixture-ului și în extra-<cod>.txt
            // (din git), deci o clonă într-un dosar cu alt nume (CMS_DEV_REPO_*) trebuie să dea aceleași fișiere.
            $eticheta = $implicite[$variabila];
            $deAcum = $revizuit($site);
            $commit = $scrieFisiere ? $deAcum : ($dinCap[1] ?? '');
            if (!$scrieFisiere) {
                $verificaF($codF . '.sql e făcut din „ultimul commit revizuit” al ' . $titlu . ' (' . $deAcum . ', docs/de-portat.md)',
                    $commit !== '' && (str_starts_with($deAcum, $commit) || str_starts_with($commit, $deAcum)),
                    'în capul fixture-ului: ' . ($commit !== '' ? $commit : 'fără sursă') . '. Codul vechi a avansat: '
                    . 'tools/dev/php.sh tools/dev/migrari/genereaza-fixture.php --fixture-uri, apoi T2 (test-migrari.php) arată ce s-a schimbat');
            }
        }
        if ($commit === '') {
            $verificaF($codF . '.sql există și are sursa în cap (-- sursa: <repo>@<commit>)', false, 'tools/dev/php.sh tools/dev/migrari/genereaza-fixture.php --fixture-uri');
            continue;
        }
        $copieF = $copieCod($clona . '@' . $commit);
        $rezultat = $schemaCodului($copieF['dosar'], $sock, 'cms_mig_fx_' . $codF);
        $A = $rezultat['amprenta'];
        $inPlus = Amprenta::compara($asteptatFix, $A)['in_plus'];
        $sursa = $eticheta . '@' . substr($copieF['eticheta'], strrpos($copieF['eticheta'], '@') + 1);
        if ($scrieFisiere) {
            $antet = '-- Fixture-ul migrărilor pentru ' . $titlu . ' (cod ' . $codF . '): schema pe care o face codul vechi pe o bază goală:'
                . "\n-- scripts/install.php, apoi funcțiile ensure…/asigura… până nu se mai schimbă (MariaDB " . $versiune . ").\n"
                . "-- Doar structură, niciun rând; nimic din datele unui site. Îl încarcă tools/dev/test-migrari.php (T2).\n"
                . '-- sursa: ' . $sursa . "\n"
                . "-- Generat de tools/dev/migrari/genereaza-fixture.php --fixture-uri; nu se editează de mână.\n";
            file_put_contents($fisierSql, $sqlSchema($conectare($sock, 'cms_mig_fx_' . $codF), $antet));
            file_put_contents($fisierExtra, $extraText($codF, $inPlus, $sursa));
            echo 'fixture ', $codF, ' (', $sursa, '): ', $descrie($A), ', ', count($inPlus), " obiecte în plus față de asteptat.json\n";
            continue;
        }
        $eroare = null;
        try {
            $incarcaSql($sock, 'cms_mig_fx_' . $codF . '_git', (string) file_get_contents($fisierSql));
        } catch (Throwable $e) {
            $eroare = $e->getMessage();
        }
        $G = $eroare === null ? $amprentaBazei($sock, 'cms_mig_fx_' . $codF . '_git') : ['tabele' => []];
        $d = Amprenta::compara($A, $G);
        $verificaF($codF . '.sql (din git) = schema pe care o face acum codul ' . $sursa . ' (' . $descrie($A) . ')',
            $eroare === null && Amprenta::json($G) === Amprenta::json($A), (string) ($eroare ?? implode('; ', array_slice(array_merge(
                array_map(static fn (string $o): string => 'lipsește din fixture ' . $o, $d['lipsa']),
                array_map(static fn (string $o): string => 'în plus în fixture ' . $o, $d['in_plus']), $d['avertismente']), 0, 20))
                . ' — tools/dev/php.sh tools/dev/migrari/genereaza-fixture.php --fixture-uri'));
        $verificaF('extra-' . $codF . '.txt e la zi (' . count($inPlus) . ' obiecte în plus față de asteptat.json)',
            is_file($fisierExtra) && (string) file_get_contents($fisierExtra) === $extraText($codF, $inPlus, $sursa),
            'tools/dev/php.sh tools/dev/migrari/genereaza-fixture.php --fixture-uri');
    }
    if ($scrieFisiere) {
        exit(0);
    }
    echo 'genereaza-fixture --verifica-fixture-uri: ', $trecuteF, ' trecute, ', $esuateF, " eșuate\n";
    exit($esuateF === 0 ? 0 : 1);
}

// ============================================================================== --asteptat
if ($comanda === 'asteptat') {
    $server = $servere[0];
    $sock = $socket[$server];
    $registru = new Registru($dosarMigrari, $proprietari());
    $migrari = $registru->migrari();
    if ($migrari === []) {
        $opreste('nicio migrare în ' . $dosarMigrari);
    }
    @mkdir($lucru, 0777, true);

    // migrari.lock întâi (runner-ul îl citește): o migrare deja trecută acolo nu se schimbă (afară de
    // --rescrie-lacatul, doar înainte de prima publicare), una ștearsă trebuie trecută la „retrase”.
    $lacat = $registru->lacat();
    $noi = [];
    $schimbate = [];
    foreach ($migrari as $m) {
        $vechiSha = $lacat['migrari'][$m['id']] ?? null;
        if ($vechiSha !== null && $vechiSha !== $m['sha256']) {
            $schimbate[] = $m['id'];
        }
        $noi[$m['id']] = $m['sha256'];
    }
    if ($schimbate !== [] && !$rescrieLacatul) {
        $opreste('migrări deja trecute în migrari.lock s-au schimbat: ' . implode(', ', $schimbate)
            . '. O migrare publicată nu se schimbă (o schimbare = o migrare nouă); doar înainte de prima publicare: --rescrie-lacatul');
    }
    foreach (array_keys($lacat['migrari']) as $id) {
        if (!isset($noi[$id]) && !isset($lacat['retrase'][$id])) {
            $opreste('migrarea ' . $id . ' e în migrari.lock, dar fișierul ei lipsește (una retrasă se trece la „retrase”, cu motivul)');
        }
    }
    $retrase = $lacat['retrase'];
    ksort($retrase);
    $fisierLacat = $dosarMigrari . '/migrari.lock';
    $fisierAsteptat = $dosarMigrari . '/asteptat.json';
    $fisierUltima = $dosarMigrari . '/ultima.php';
    $inainte = [];
    foreach ([$fisierLacat, $fisierAsteptat, $fisierUltima] as $fisier) {
        $inainte[$fisier] = is_file($fisier) ? (string) file_get_contents($fisier) : null;
    }
    // La o eroare, cele trei fișiere rămân cum erau.
    $renunta = static function (string $mesaj) use ($inainte, $opreste): never {
        foreach ($inainte as $fisier => $continut) {
            if ($continut === null) {
                @unlink($fisier);
            } else {
                file_put_contents($fisier, $continut);
            }
        }
        $opreste($mesaj);
    };
    file_put_contents($fisierLacat, json_encode(['migrari' => $noi, 'retrase' => (object) $retrase],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    @unlink($fisierAsteptat);

    // asteptat.json: amprenta unei baze goale după toate migrările.
    $baza = 'cms_mig_fx_asteptat';
    $bazaNoua($sock, $baza);
    [$codAplica, $outAplica] = $migreaza($sock, $baza, ['--aplica', '--json']);
    $raport = json_decode($outAplica, true);
    // Fără asteptat.json, --aplica se termină cu 14 („nu pot spune dacă baza e completă”); altceva e o eroare.
    if (!is_array($raport) || !in_array($codAplica, [0, 14], true) || ($codAplica === 14 && !($raport['verificare']['fara_asteptat'] ?? false))) {
        $renunta('migreaza.php --aplica pe o bază goală: cod ' . $codAplica . ': ' . trim(substr((string) ($raport['mesaj'] ?? $outAplica), 0, 800)));
    }
    [$codAmprenta, $json] = $migreaza($sock, $baza, ['--amprenta']);
    $amprenta = json_decode($json, true);
    if ($codAmprenta !== 0 || !is_array($amprenta) || !is_array($amprenta['tabele'] ?? null)) {
        $renunta('migreaza.php --amprenta: cod ' . $codAmprenta);
    }
    file_put_contents($fisierAsteptat, Amprenta::json($amprenta));
    [$codVerifica, $outVerifica] = $migreaza($sock, $baza, ['--verifica']);
    if ($codVerifica !== 0) {
        $renunta('--verifica după generare: cod ' . $codVerifica . ': ' . trim($outVerifica));
    }
    file_put_contents($fisierUltima, $registru->textUltima());
    $vechi = $inainte[$fisierAsteptat];
    echo 'genereaza-fixture --asteptat (', $numeServer[$server], '): ', count($migrari), ' migrări, ultima ', sprintf('%04d', $registru->ultimaOrd()),
        '; asteptat.json: ', $descrie($amprenta), ($vechi !== null && $vechi !== Amprenta::json($amprenta) ? ' (SCHIMBAT)' : ($vechi === null ? ' (nou)' : ' (neschimbat)')),
        "; migrari.lock, ultima.php scrise\n";
    exit(0);
}

// ============================================================================== --verifica
$trecute = 0;
$esuate = 0;
$verifica = static function (string $eticheta, bool $ok, string $detalii = '') use (&$trecute, &$esuate): void {
    if ($ok) {
        $trecute++;
        echo '  OK    ', $eticheta, "\n";
    } else {
        $esuate++;
        echo '  EȘEC  ', $eticheta, ($detalii !== '' ? ': ' . trim(substr($detalii, 0, 3000)) : ''), "\n";
    }
};
$diferenteText = static function (array $a, array $b): string {
    $d = Amprenta::compara($a, $b);
    $linii = array_merge(array_map(static fn (string $o): string => 'lipsește ' . $o, $d['lipsa']),
        array_map(static fn (string $o): string => 'în plus ' . $o, $d['in_plus']), $d['avertismente']);
    return implode("\n", array_slice($linii, 0, 40)) . (count($linii) > 40 ? "\n… încă " . (count($linii) - 40) : '');
};
/** Scoate din amprenta codului un obiect al listei GF_EXCLUSE; false dacă nu există (lista e veche). */
$scoate = static function (array &$amprenta, string $obiect): bool {
    if (preg_match('/^tabelul ([a-z0-9_]+)$/', $obiect, $m) === 1) {
        if (!isset($amprenta['tabele'][$m[1]])) {
            return false;
        }
        unset($amprenta['tabele'][$m[1]]);
        return true;
    }
    if (preg_match('/^coloana ([a-z0-9_]+)\.([a-z0-9_]+)$/', $obiect, $m) === 1) {
        if (!isset($amprenta['tabele'][$m[1]]['coloane'][$m[2]])) {
            return false;
        }
        unset($amprenta['tabele'][$m[1]]['coloane'][$m[2]]);
        return true;
    }
    return false;
};

$registru = new Registru($dosarMigrari, $proprietari());
$migrari = $registru->migrari();
$fisierAsteptat = $dosarMigrari . '/asteptat.json';
echo 'genereaza-fixture --verifica: ', count($migrari), ' migrări în database/migrari (ultima ', sprintf('%04d', $registru->ultimaOrd()), ")\n";
$asteptat = is_file($fisierAsteptat) ? json_decode((string) file_get_contents($fisierAsteptat), true) : null;
$verifica('database/migrari/asteptat.json există și e o amprentă', is_array($asteptat) && is_array($asteptat['tabele'] ?? null));
$lacat = $registru->lacat();
$verifica('migrari.lock are exact migrările din cod, cu sha-ul lor; ultima.php e la zi',
    $lacat['migrari'] === array_combine(array_column($migrari, 'id'), array_column($migrari, 'sha256'))
    && is_file($dosarMigrari . '/ultima.php')
    && (require $dosarMigrari . '/ultima.php') === $registru->ultima(),
    'rulează genereaza-fixture.php --asteptat');
$grupB = array_values(array_filter($migrari, static fn (array $m): bool => $m['ord'] >= 1 && $m['ord'] <= 19));
$grupC = array_values(array_filter($migrari, static fn (array $m): bool => $m['ord'] >= 20 && $m['ord'] <= 24));
$verifica('grupul B are 19 migrări (0001–0019, tip schema), grupul C 5 (0020–0024, tip date), ultima ' . GF_ULTIMA_DIN_BAZA,
    count($grupB) === 19 && count($grupC) === 5 && array_values(array_unique(array_column($grupB, 'tip'))) === ['schema']
    && array_values(array_unique(array_column($grupC, 'tip'))) === ['date'] && end($grupC)['id'] === GF_ULTIMA_DIN_BAZA);
if (!is_array($asteptat)) {
    exit(1);
}
$dupaBaza = count($migrari) - count($grupB) - count($grupC);
$grupa = static fn (array $m): string => $m['ord'] <= 19 ? 'B' : ($m['ord'] <= 24 ? 'C' : 'după');
$stari = static function (array $raport) use ($grupa): array {
    $stari = [];
    foreach ((array) ($raport['migrari'] ?? []) as $m) {
        $stari[$grupa($m)][$m['stare']] = ($stari[$grupa($m)][$m['stare']] ?? 0) + 1;
        $stari['operatii ' . $grupa($m)] = ($stari['operatii ' . $grupa($m)] ?? 0) + (int) ($m['operatii_acum'] ?? 0);
    }
    return $stari;
};

$copie = $copieCod($repo);
if ($repo === null) {
    $dosareDeSters[] = $copie['dosar'];
}
$fixtureMaria = null;
$amprentaCodMaria = null;
foreach ($servere as $server) {
    $sock = $socket[$server];
    echo "\n== ", $numeServer[$server], ' (', (string) $conectare($sock)->query('SELECT VERSION()')->fetchColumn(), ")\n";

    // 1. Schema codului (pe vechi: install.php + funcțiile).
    $cod = $schemaCodului($copie['dosar'], $sock, 'cms_mig_fx_cod');
    $A = $cod['amprenta'];
    echo '  schema codului ', $copie['eticheta'], ': ', $descrie($A), ' (', implode('; ', $cod['treceri']), ")\n";
    // Schema codului ca SQL, înainte ca migrările să atingă baza: pentru pasul 6 pe acest server, iar
    // cea de pe MariaDB și pentru pasul 5 (încărcată pe fiecare server).
    $fixtureServer = $sqlSchema($conectare($sock, 'cms_mig_fx_cod'), "-- test\n");
    if ($server === 'mariadb') {
        $amprentaCodMaria = $A;
        $fixtureMaria = $fixtureServer;
    }

    // 2. Toate migrările pe o bază goală = asteptat.json.
    $bazaNoua($sock, 'cms_mig_fx_nou');
    [$c, $o] = $migreaza($sock, 'cms_mig_fx_nou', ['--aplica', '--json']);
    $r = json_decode($o, true);
    $s = $stari(is_array($r) ? $r : []);
    $verifica('o bază goală: --aplica iese cu 0 (grupul B aplicat, grupul C fără nimic de convertit, verificarea față de asteptat.json trecută), fără avertismente',
        $c === 0 && ($s['B'] ?? null) === ['aplicata' => 19] && ($s['C'] ?? null) === ['detectata' => 5]
        && array_sum((array) ($s['după'] ?? [])) === $dupaBaza && (array) ($r['avertismente'] ?? ['?']) === [],
        'cod ' . $c . ', stări ' . json_encode($s) . ': ' . substr((string) ($r['mesaj'] ?? $o), 0, 1500)
        . "\navertismente: " . implode("\n", array_slice((array) ($r['avertismente'] ?? []), 0, 20)));
    $F = $amprentaBazei($sock, 'cms_mig_fx_nou');
    $verifica('instalarea nouă = asteptat.json (' . $descrie($F) . ')', Amprenta::json($F) === Amprenta::json($asteptat), $diferenteText($asteptat, $F));
    [$c2, $o2] = $migreaza($sock, 'cms_mig_fx_nou', ['--plan']);
    $verifica('a doua oară nimic de făcut (--plan iese cu 0)', $c2 === 0, 'cod ' . $c2 . ': ' . $o2);

    // 3. Baseline-ul (0001–0024) pe o bază goală = schema codului minus excluderile, cu diferențele declarate.
    $Fb = $F;
    if ($dupaBaza > 0) {
        $bazaNoua($sock, 'cms_mig_fx_baza');
        [$cb, $ob] = $migreaza($sock, 'cms_mig_fx_baza', ['--aplica', '--pana-la=' . GF_ULTIMA_DIN_BAZA]);
        $verifica('o bază goală, doar baseline-ul (--pana-la=' . GF_ULTIMA_DIN_BAZA . '): iese cu 0', $cb === 0, 'cod ' . $cb . ': ' . $ob);
        $Fb = $amprentaBazei($sock, 'cms_mig_fx_baza');
    }
    $minus = $A;
    $lipsaDinCod = [];
    foreach (array_keys(GF_EXCLUSE) as $obiect) {
        if (!$scoate($minus, $obiect)) {
            $lipsaDinCod[] = $obiect;
        }
    }
    $verifica('fiecare excludere există în schema codului (' . implode(', ', array_keys(GF_EXCLUSE)) . ')', $lipsaDinCod === [], implode(', ', $lipsaDinCod));
    $implicita = (string) $conectare($sock)->query("SELECT DEFAULT_COLLATE_NAME FROM information_schema.CHARACTER_SETS WHERE CHARACTER_SET_NAME = 'utf8mb4'")->fetchColumn();
    $colatiiGresite = [];
    foreach (GF_COLATIE_SERVER as $tabel) {
        if (($minus['tabele'][$tabel]['colatie'] ?? null) !== $implicita || ($Fb['tabele'][$tabel]['colatie'] ?? null) !== 'utf8mb4_unicode_ci') {
            $colatiiGresite[] = $tabel . ' (codul: ' . ($minus['tabele'][$tabel]['colatie'] ?? '?') . ', migrările: ' . ($Fb['tabele'][$tabel]['colatie'] ?? '?') . ')';
        }
        if (isset($minus['tabele'][$tabel])) {
            $minus['tabele'][$tabel]['colatie'] = 'utf8mb4_unicode_ci';
        }
    }
    $verifica('colația declarată: ' . implode(', ', GF_COLATIE_SERVER) . ' au în cod colația serverului (' . $implicita . '), în migrări utf8mb4_unicode_ci',
        $colatiiGresite === [], implode('; ', $colatiiGresite));
    $verifica('baseline-ul pe o bază goală = schema codului minus excluderile (' . $descrie($minus) . ')'
        . ($dupaBaza === 0 ? ' = asteptat.json' : ''), Amprenta::json($minus) === Amprenta::json($Fb), $diferenteText($Fb, $minus));

    // 4. Baza făcută de cod („pe vechi”): baseline-ul găsește tot, fără nicio operație.
    [$cp, $op] = $migreaza($sock, 'cms_mig_fx_cod', ['--plan', '--json']);
    $plan = json_decode($op, true);
    $deFacut = [];
    foreach ((array) ($plan['migrari'] ?? []) as $m) {
        foreach ((array) ($m['operatii'] ?? []) as $operatie) {
            $permise = match ($grupa($m)) {
                'B' => ['fara_efect'],
                'C' => ['fara_efect', 'date'],
                default => null,
            };
            if ($permise !== null && !in_array($operatie['clasa'], $permise, true)) {
                $deFacut[] = $m['id'] . ': ' . $operatie['clasa'] . ' ' . $operatie['fel'] . ' ' . $operatie['obiect'];
            }
        }
    }
    $verifica('baza făcută de cod: --plan iese cu 10, toate cele ' . count($migrari) . ' migrări noi, grupul B doar „există deja”, grupul C doar date',
        $cp === 10 && count($plan['migrari'] ?? []) === count($migrari) && $deFacut === [],
        'cod ' . $cp . ': ' . implode("\n", array_slice($deFacut, 0, 40)) . ' ' . substr((string) ($plan['mesaj'] ?? $op), 0, 300));
    [$ca, $oa] = $migreaza($sock, 'cms_mig_fx_cod', ['--aplica', '--json', '--pana-la=' . GF_ULTIMA_DIN_BAZA]);
    $aplicare = json_decode($oa, true);
    $s = $stari(is_array($aplicare) ? $aplicare : []);
    $verifica('baza făcută de cod: baseline-ul (--aplica --pana-la=' . GF_ULTIMA_DIN_BAZA . ') iese cu 0; grupul B: 0 operații, toate 19 „detectata”',
        $ca === 0 && ($s['B'] ?? null) === ['detectata' => 19] && ($s['operatii B'] ?? -1) === 0,
        'cod ' . $ca . ', stări ' . json_encode($s) . ': ' . substr((string) ($aplicare['mesaj'] ?? $oa), 0, 1500));
    $verifica('baza făcută de cod: grupul C fără nimic de convertit (nicio dată veche): 5 „detectata”, 0 operații',
        ($s['C'] ?? null) === ['detectata' => 5] && ($s['operatii C'] ?? -1) === 0, json_encode($s));
    $dupa = $amprentaBazei($sock, 'cms_mig_fx_cod');
    $verifica('baza făcută de cod: schema neschimbată de baseline', Amprenta::json($dupa) === Amprenta::json($A), $diferenteText($A, $dupa));
    if ($dupaBaza > 0) {
        [$cr, $or] = $migreaza($sock, 'cms_mig_fx_cod', ['--aplica']);
        $verifica('baza făcută de cod: migrările de după baseline (' . $dupaBaza . ') se aplică, cod 0', $cr === 0, 'cod ' . $cr . ': ' . $or);
    }
    [$cv, $ov] = $migreaza($sock, 'cms_mig_fx_cod', ['--verifica', '--json']);
    $rv = json_decode($ov, true);
    $inPlus = (array) ($rv['verificare']['in_plus'] ?? []);
    sort($inPlus);
    $asteptatInPlus = array_keys(GF_EXCLUSE);
    sort($asteptatInPlus);
    $verifica('baza făcută de cod: --verifica iese cu 0; în plus exact excluderile; avertismente doar colația declarată',
        $cv === 0 && $inPlus === $asteptatInPlus && count((array) ($rv['verificare']['avertismente'] ?? [])) === count(GF_COLATIE_SERVER),
        'cod ' . $cv . "\nîn plus: " . implode(', ', $inPlus) . "\navertismente: " . implode("\n", (array) ($rv['verificare']['avertismente'] ?? [])));
    [$c3] = $migreaza($sock, 'cms_mig_fx_cod', ['--plan']);
    $verifica('baza făcută de cod: după aplicare, --plan iese cu 0', $c3 === 0, 'cod ' . $c3);

    // 5. Fixture-ul SQL: făcut pe MariaDB, se încarcă pe fiecare server și dă aceeași amprentă.
    if ($fixtureMaria !== null) {
        $eroare = null;
        try {
            $incarcaSql($sock, 'cms_mig_fx_sql', $fixtureMaria);
        } catch (Throwable $e) {
            $eroare = $e->getMessage();
        }
        $S = $eroare === null ? $amprentaBazei($sock, 'cms_mig_fx_sql') : ['tabele' => []];
        $verifica('fixture-ul SQL (făcut pe MariaDB) se încarcă și dă aceeași amprentă ca schema codului de pe MariaDB',
            $eroare === null && Amprenta::json($S) === Amprenta::json($amprentaCodMaria), (string) ($eroare ?? $diferenteText($amprentaCodMaria, $S)));
    }
    if ($server === 'mysql8' && $amprentaCodMaria !== null) {
        $faraColatii = static function (array $a): array {
            foreach (GF_COLATIE_SERVER as $tabel) {
                unset($a['tabele'][$tabel]['colatie']);
            }
            return $a;
        };
        $verifica('pe MySQL 8 codul face aceeași schemă ca pe MariaDB (în afară de colația declarată)',
            Amprenta::json($faraColatii($A)) === Amprenta::json($faraColatii($amprentaCodMaria)), $diferenteText($amprentaCodMaria, $A));
    }

    // 6. Grupul C față de codul vechi: aceleași rânduri inventate (GF_RANDURI_GRUP_C), pe schema codului,
    //    trec o dată prin funcțiile codului (o trecere, ca la o cerere) și o dată prin migrări (baseline-ul).
    //    Fiecare migrare de date trebuie să lase exact ce lăsa codul vechi.
    $eroare = null;
    try {
        foreach (['cms_mig_fx_c_cod', 'cms_mig_fx_c_mig'] as $bazaC) {
            $incarcaSql($sock, $bazaC, $fixtureServer);
            $dbC = $conectare($sock, $bazaC);
            foreach (GF_RANDURI_GRUP_C as $sql) {
                $dbC->exec($sql);
            }
        }
    } catch (Throwable $e) {
        $eroare = $e->getMessage();
    }
    $citesteC = static function (string $baza) use ($conectare, $sock): array {
        $db = $conectare($sock, $baza);
        $rezultat = [];
        foreach (GF_CITIRI_GRUP_C as $ce => $sql) {
            $rezultat[$ce] = $db->query($sql)->fetchAll(PDO::FETCH_NUM);
        }
        return $rezultat;
    };
    if ($eroare !== null) {
        $verifica('grupul C față de codul vechi: rândurile inventate se scriu pe schema codului', false, $eroare);
    } else {
        $inainteC = $citesteC('cms_mig_fx_c_mig');
        $vechi = $copil('helperi', $copie['dosar'], $sock, 'cms_mig_fx_c_cod');
        [$c6, $o6] = $migreaza($sock, 'cms_mig_fx_c_mig', ['--aplica', '--json', '--pana-la=' . GF_ULTIMA_DIN_BAZA]);
        $r6 = json_decode($o6, true);
        $s6 = $stari(is_array($r6) ? $r6 : []);
        $dinCod = $citesteC('cms_mig_fx_c_cod');
        $dinMigrari = $citesteC('cms_mig_fx_c_mig');
        $diferite = [];
        foreach (GF_CITIRI_GRUP_C as $ce => $_) {
            if ($dinCod[$ce] !== $dinMigrari[$ce]) {
                $diferite[] = $ce . "\n    codul vechi: " . json_encode($dinCod[$ce], JSON_UNESCAPED_UNICODE)
                    . "\n    migrările:   " . json_encode($dinMigrari[$ce], JSON_UNESCAPED_UNICODE);
            }
            if ($inainteC[$ce] === $dinMigrari[$ce]) {
                $diferite[] = $ce . ': nimic schimbat (cazul nu verifică nimic)';
            }
        }
        $randGolBlog = $dinMigrari['admins (0020)'][0] ?? [];
        $verifica('grupul C față de codul vechi, pe rânduri inventate (' . implode(', ', array_keys(GF_CITIRI_GRUP_C)) . '): '
            . 'migrările lasă exact ce lăsau funcțiile codului; role gol + roles_json → administrator_general în plus',
            ($vechi['erori'] ?? []) === [] && $c6 === 0 && ($s6['C'] ?? null) === ['aplicata' => 5] && $diferite === []
            && array_map('strval', $randGolBlog) === ['101', 'administrator_general', '["administrator_blog","administrator_general"]'],
            implode('; ', array_slice((array) ($vechi['erori'] ?? []), 0, 3)) . ' cod ' . $c6 . ', stări ' . json_encode($s6) . "\n" . implode("\n", $diferite)
            . "\n" . substr((string) ($r6['mesaj'] ?? $o6), 0, 600));
    }
}

echo "\n", 'genereaza-fixture --verifica: ', $trecute, ' trecute, ', $esuate, ' eșuate (', implode(', ', array_map(static fn (string $s): string => $numeServer[$s], $servere)), ")\n";
exit($esuate === 0 ? 0 : 1);

// ============================================================================================
// Funcții
// ============================================================================================

/** Commit-ul codului comun dinaintea migrărilor (instalarea veche): sursa scrisă în capul lui vechi/cms.sql. */
function gf_commitInainteDeMigrari(string $radacina): ?string
{
    $cap = (string) @file_get_contents($radacina . '/tools/dev/migrari/vechi/cms.sql', false, null, 0, 2000);
    return preg_match('/^-- sursa: ANDAXI-CMS@([0-9a-f]{7,40})$/m', $cap, $m) === 1 ? $m[1] : null;
}

function gf_sterge(string $cale): void
{
    if (is_link($cale) || is_file($cale)) {
        @unlink($cale);
        return;
    }
    if (is_dir($cale)) {
        foreach (array_diff(scandir($cale) ?: [], ['.', '..']) as $intrare) {
            gf_sterge($cale . '/' . $intrare);
        }
        @rmdir($cale);
    }
}

function gf_copiaza(string $sursa, string $destinatie): void
{
    if (is_link($sursa)) {
        return;
    }
    if (is_file($sursa)) {
        copy($sursa, $destinatie);
        return;
    }
    if (!is_dir($sursa)) {
        return;
    }
    @mkdir($destinatie, 0777, true);
    foreach (array_diff(scandir($sursa) ?: [], ['.', '..']) as $intrare) {
        gf_copiaza($sursa . '/' . $intrare, $destinatie . '/' . $intrare);
    }
}

/**
 * Procesul-copil: --faza=install|helperi --dosar=<copia codului> --sock=<socket> --baza=<bază>.
 * Scrie pe ecran un JSON: câte instrucțiuni au ajuns la bază (și câte au eșuat), câte funcții au
 * fost chemate, erorile (excepțiile funcțiilor). Fără textul instrucțiunilor și fără valori.
 *
 * @param list<string> $argumente
 */
function gf_copil(array $argumente): void
{
    $optiuni = [];
    foreach ($argumente as $argument) {
        if (preg_match('/^--(faza|dosar|sock|baza)=(.+)$/', $argument, $m) === 1) {
            $optiuni[$m[1]] = $m[2];
        }
    }
    $dosar = (string) ($optiuni['dosar'] ?? '');
    $baza = (string) ($optiuni['baza'] ?? '');
    $faza = (string) ($optiuni['faza'] ?? '');
    if (!in_array($faza, ['install', 'helperi'], true) || preg_match('/^cms_mig_(fx|t)_[a-z0-9_]+$/', $baza) !== 1 || !is_dir($dosar . '/src')) {
        fwrite(STDERR, "copil: argumente greșite\n");
        exit(2);
    }
    date_default_timezone_set('Europe/Bucharest');
    $_SESSION = [];
    $_SERVER['REQUEST_URI'] = '/admin';
    $_SERVER['HTTP_HOST'] = '127.0.0.1';

    // Pluginurile: toate pornite, fără licență (nimic citit sau scris în storage/licenta).
    if (is_file($dosar . '/src/Support/Plugins.php')) {
        eval('namespace App\Support; final class Plugins {
            public static function vizibil(string $k): bool { return true; }
            public static function activ(string $k): bool { return true; }
            public static function permiteInceput(string $k): bool { return true; }
            public static function oricareVizibil(array $k): bool { return true; }
            public static function stare(string $k): string { return "pornit"; }
            public static function __callStatic($n, $a) { return true; }
        }');
    }
    spl_autoload_register(static function (string $clasa) use ($dosar): void {
        if (str_starts_with($clasa, 'App\\')) {
            $fisier = $dosar . '/src/' . str_replace('\\', '/', substr($clasa, 4)) . '.php';
            if (is_file($fisier)) {
                require_once $fisier;
            }
        }
    });

    $db = new GfPdo('mysql:unix_socket=' . $optiuni['sock'] . ';dbname=' . $baza . ';charset=utf8mb4',
        (string) (getenv('DB_USERNAME') ?: 'root'), (string) (getenv('DB_PASSWORD') ?: ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STATEMENT_CLASS => [GfStmt::class, []],
        ]);
    $GLOBALS['GF_DB'] = $db;
    $GLOBALS['GF_CONFIG'] = [
        'name' => 'Firma Demo', 'env' => 'local', 'debug' => false, 'url' => 'http://127.0.0.1:9', 'timezone' => 'Europe/Bucharest',
        'db' => ['host' => 'localhost', 'port' => 3306, 'database' => $baza, 'username' => '', 'password' => ''],
        'admin' => ['default_email' => 'admin@exemplu.ro', 'default_password' => 'parola-demo-fixture'],
        'licenta' => ['cheie_publica' => '', 'site' => '', 'dosar' => ''],
        'php_min' => '8.3',
    ];

    $rezultat = ['faza' => $faza, 'erori' => []];
    if ($faza === 'install') {
        $codInstall = (string) file_get_contents($dosar . '/scripts/install.php');
        $inlocuiri = [
            "require_once __DIR__ . '/../bootstrap.php';" => '',
            "require __DIR__ . '/../config/app.php'" => "\$GLOBALS['GF_CONFIG']",
            "__DIR__ . '/../database/schema.sql'" => var_export($dosar . '/database/schema.sql', true),
            "Database::connection(\$config['db'])" => "\$GLOBALS['GF_DB']",
        ];
        foreach ($inlocuiri as $din => $in) {
            if (!str_contains($codInstall, $din)) {
                $rezultat['erori'][] = 'install.php nu mai are forma știută (lipsește ' . $din . '); adaptează genereaza-fixture.php';
            }
            $codInstall = str_replace($din, $in, $codInstall);
        }
        // vertij: folderul documentelor nu ține de schemă.
        $codInstall = str_replace('ProfessionAccounts::ensureDocumentRoot();', '', $codInstall);
        $codInstall = (string) preg_replace('/^<\?php/', '', $codInstall);
        $codInstall = (string) preg_replace('/declare\(strict_types=1\);/', '', $codInstall);
        if ($rezultat['erori'] === []) {
            ob_start();
            try {
                eval($codInstall);
            } catch (Throwable $e) {
                $rezultat['erori'][] = 'install.php: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 300);
            }
            $iesire = (string) ob_get_clean();
            if (!str_contains($iesire, 'Install finalizat')) {
                $rezultat['erori'][] = 'install.php nu a spus „Install finalizat”';
            }
        }
    } else {
        $metode = gf_metodeSchema($dosar);
        $rezultat['metode'] = count($metode);
        foreach ($metode as [$clasa, $metoda]) {
            ob_start();
            try {
                $reflectie = new ReflectionMethod($clasa, $metoda);
                if ($reflectie->getNumberOfRequiredParameters() > 1) {
                    throw new RuntimeException('cere mai mult de un parametru');
                }
                $reflectie->setAccessible(true);
                $obiect = $reflectie->isStatic() ? null : (new ReflectionClass($clasa))->newInstanceWithoutConstructor();
                $reflectie->invoke($obiect, ...($reflectie->getNumberOfParameters() >= 1 ? [$db] : []));
            } catch (Throwable $e) {
                $rezultat['erori'][] = substr((string) strrchr($clasa, '\\'), 1) . '::' . $metoda . ': ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200);
            }
            ob_end_clean();
        }
    }
    $rezultat['instructiuni'] = GfJurnal::$instructiuni;
    $rezultat['esuate'] = GfJurnal::$esuate;
    echo json_encode($rezultat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

/**
 * Funcțiile codului care își fac schema din mers: [clasă, metodă], în ordinea numelui.
 *
 * @return list<array{0: string, 1: string}>
 */
function gf_metodeSchema(string $dosar): array
{
    $metode = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dosar . '/src', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $fisier) {
        if ($fisier->getExtension() !== 'php' || str_contains($fisier->getPathname(), '/src/Support/Migrari/')) {
            continue;
        }
        $sursa = (string) file_get_contents($fisier->getPathname());
        if (preg_match_all('/function\s+((?:(?:ensure|asigura)[A-Za-z0-9_]*Schema[A-Za-z0-9_]*|pregatesteSchema))\s*\(/', $sursa, $m) === 0) {
            continue;
        }
        $relativ = substr($fisier->getPathname(), strlen($dosar . '/src/'), -4);
        $clasa = 'App\\' . str_replace('/', '\\', $relativ);
        foreach (array_unique($m[1]) as $metoda) {
            $metode[] = [$clasa, $metoda];
        }
    }
    usort($metode, static fn (array $a, array $b): int => strcmp($a[0] . '::' . $a[1], $b[0] . '::' . $b[1]));
    return $metode;
}

final class GfJurnal
{
    public static int $instructiuni = 0;
    public static int $esuate = 0;

    public static function noteaza(bool $ok): void
    {
        self::$instructiuni++;
        if (!$ok) {
            self::$esuate++;
        }
    }
}

final class GfStmt extends PDOStatement
{
    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        try {
            $r = parent::execute($params);
            GfJurnal::noteaza(true);
            return $r;
        } catch (PDOException $e) {
            GfJurnal::noteaza(false);
            throw $e;
        }
    }
}

final class GfPdo extends PDO
{
    public function exec(string $statement): int|false
    {
        try {
            $r = parent::exec($statement);
            GfJurnal::noteaza(true);
            return $r;
        } catch (PDOException $e) {
            GfJurnal::noteaza(false);
            throw $e;
        }
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        try {
            $r = $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
            GfJurnal::noteaza(true);
            return $r;
        } catch (PDOException $e) {
            GfJurnal::noteaza(false);
            throw $e;
        }
    }
}
