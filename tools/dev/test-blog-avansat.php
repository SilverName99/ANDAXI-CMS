<?php

declare(strict_types=1);

/**
 * Testele blogului avansat portat din Biovitality (planul Etapei 1: WP-22, WP-23), cu serverul de dezvoltare, pe baza de
 * test cu datele demo.
 *
 *   tools/dev/php.sh tools/dev/test-blog-avansat.php
 *
 * WP-22 — schema, randarea, lista, textele (B1, B2, B3, X12, DB-13):
 * A. Fără server: App\Support\Slug (make, unique, transliterările unu-la-unu; simplu() și categorie() neschimbate; make()
 *    lasă neatins orice slug pe care îl dădea slugify() din admin), App\Support\BlogToc (id-uri, dubluri, id-uri puse de
 *    mână păstrate, un singur titlu fără cuprins), App\Support\BlogReferences (numerele, secțiunea #surse, citările legate,
 *    forma veche `[<a href="#surse">1</a>]`, o citare fără referință sau dintr-un link rămâne cum e, bibliografia liberă),
 *    App\Support\BlogSearch (highlight fără diacritice, escapat, termeni suprapuși, fragmentul), App\Support\BlogAvansat.
 * B. Schema: migrarea blog/0033 a făcut coloanele și tabelele (definițiile cerute), catalogul pluginului le are.
 * C. Blogul avansat pornit: căutarea și chips-urile pe /blog (doar categoriile cu articole publicate, cu numărul lor),
 *    ?q= fără diacritice cu <mark>, ?categorie= / ?eticheta=, paginile /blog/categorie/{slug} și /blog/eticheta/{slug}
 *    (titlul, lista, paginarea cu filtrul), 404 pentru o categorie sau etichetă care nu există sau o categorie inactivă;
 *    articolul: cuprinsul și ancorele, citările și bibliografia, etichetele, articolele similare (aceeași categorie
 *    întâi), lățimea copertei, secțiunea veche „Articole interesante” umplută de pe server, bibliografia și etichetele
 *    adăugate unui șablon fără tokenurile lor, fișierele blogului avansat încărcate doar pe un articol care le folosește.
 * D. Blogul avansat oprit (ca înghețarea Bioscem): /blog fără căutare și chips, ?q= ignorat (aceeași pagină ca /blog),
 *    articolul fără ancore și fără cuprins, secțiunea veche neatinsă, fișierele neîncărcate; bibliografia și etichetele
 *    unui articol care le are apar totuși; un șablon cu {{blog_toc}} primește cuprinsul; paginile de categorie merg.
 * E. Textele listei (DB-13): eticheta, titlul, descrierea (goale = fără), autorul și categoria implicite, inițiala.
 * F. Pluginul blog oprit: /blog/categorie/…, /blog/eticheta/… și /blog?q=… dau 404.
 *
 * Toată baza (fiecare tabel) e pusă la loc la final, verificat cu CHECKSUM TABLE; la fel licența „toate”, cache-ul
 * paginilor și sesiunile făcute aici. Serverul de dezvoltare se oprește la final dacă l-a pornit testul. Raportul:
 * $CMS_DEV_DIR/test-blog-avansat-raport.txt; cod de ieșire 0 doar dacă totul a trecut. Date inventate.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';
require_once __DIR__ . '/lib/salvari-admin.php';

use App\Support\BlogAvansat;
use App\Support\BlogReferences;
use App\Support\BlogSearch;
use App\Support\BlogToc;
use App\Support\Database;
use App\Support\Paths;
use App\Support\ResponseCache;
use App\Support\Slug;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-blog-avansat-raport.txt';
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
$scrie('Test blogul avansat (WP-22, WP-23) ANDAXI-CMS — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

$port = (int) (getenv('CMS_DEV_PORT') ?: '8701');
$baza = 'http://127.0.0.1:' . $port;

/** O cerere spre serverul de dezvoltare; antetele cu numele scris mic. */
$cerere = static function (string $metoda, string $cale, ?string $jar = null, array $date = [], int $timeout = 60) use ($baza): array {
    $antete = [];
    $c = curl_init(str_starts_with($cale, 'http') ? $cale : $baza . $cale);
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metoda,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOPROXY => '*',
        CURLOPT_PROXY => '',
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$antete): int {
            if (str_contains($linie, ':')) {
                [$nume, $valoare] = explode(':', $linie, 2);
                $antete[strtolower(trim($nume))] = trim($valoare);
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
    return ['cod' => $cod, 'antete' => $antete, 'corp' => $corp];
};

// Serverul de dezvoltare: îl pornește testul dacă nu merge.
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
if (!$db instanceof PDO || (int) $db->query('SELECT COUNT(*) FROM blog_posts WHERE is_published = 1')->fetchColumn() < 5) {
    $scrie('EROARE: baza de test nu are datele demo; rulează întâi tools/dev/instaleaza.sh');
    exit(1);
}
$unu = static function (string $sql, array $param = []) use ($db): string {
    $stmt = $db->prepare($sql);
    $stmt->execute($param);
    $valoare = $stmt->fetchColumn();
    return $valoare === false || $valoare === null ? '' : (string) $valoare;
};

// ── Instantaneul întregii baze: fiecare tabel, pus la loc la final și verificat cu CHECKSUM TABLE.
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
$importInainte = glob(Paths::stocare('import') . '/*') ?: [];

$parolaAdmin = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));
$adminEmail = (string) (getenv('CMS_DEV_ADMIN_EMAIL') ?: 'admin@exemplu.ro');
$jarAdmin = $dosarDev . '/test-blog-avansat-admin-' . getmypid() . '.txt';
$licentaSchimbata = false;
$pozeTest = [];

$puneLaLoc = static function () use (&$licentaSchimbata, &$pozeTest, $db, $tabeleBaza, $instantaneu, $radacina, $jarAdmin, $sesiuniInainte,
    $importInainte, $amPornitServerul): void {
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
    foreach (array_diff(glob(Paths::stocare('import') . '/*') ?: [], $importInainte) as $fisier) {
        is_dir($fisier) ? exec('rm -rf ' . escapeshellarg($fisier)) : @unlink($fisier);
    }
    foreach ($pozeTest as $poza) {
        @unlink($poza);
    }
    $pozeTest = [];
    @unlink($jarAdmin);
    if ($amPornitServerul) {
        exec(escapeshellarg($radacina . '/tools/dev/server.sh') . ' --opreste >/dev/null 2>&1');
    }
};
register_shutdown_function(static function () use ($puneLaLoc): void {
    $puneLaLoc();
});

$logareAdmin = static function () use ($cerere, $jarAdmin, $adminEmail, $parolaAdmin): bool {
    @unlink($jarAdmin);
    $r = $cerere('POST', '/admin/login', $jarAdmin, ['email' => $adminEmail, 'password' => $parolaAdmin]);
    return $r['cod'] === 302 && str_starts_with((string) ($r['antete']['location'] ?? ''), '/admin');
};
$setare = static function (string $cheie, ?string $valoare) use ($db): void {
    if ($valoare === null) {
        $db->prepare('DELETE FROM settings WHERE `key` = :k')->execute(['k' => $cheie]);
    } else {
        $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (:k, :v) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
            ->execute(['k' => $cheie, 'v' => $valoare]);
    }
    ResponseCache::purgePageCache();
};
$licenta = static function (string ...$argumente) use ($radacina, &$licentaSchimbata): bool {
    exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($radacina . '/tools/dev/licenta.php') . ' '
        . implode(' ', array_map('escapeshellarg', $argumente)) . ' 2>&1', $iesire, $cod);
    $licentaSchimbata = $argumente !== ['toate'];
    ResponseCache::purgePageCache();
    return $cod === 0;
};
$pagina = static fn (string $cale): array => $cerere('GET', $cale);

if (!$logareAdmin()) {
    $scrie('EROARE: login-ul adminului de test nu merge (parola din ' . $dosarDev . '/admin-parola.txt)');
    exit(1);
}

// =====================================================================
// WP-22 A. Clasele, fără server
// =====================================================================
$scrie('');
$scrie('== WP-22 A. Slug, BlogToc, BlogReferences, BlogSearch, BlogAvansat');
$verifica('Slug::make: diacriticele se transliterează („Sănătatea sistemului respirator” → sanatatea-sistemului-respirator)',
    Slug::make('Sănătatea sistemului respirator') === 'sanatatea-sistemului-respirator');
$verifica('Slug::make: ș/ț cu sedilă și virgulă, majuscule, semne → aceeași formă', Slug::make('  ŞTIINŢA și ȘTIINȚA: „ceai”! ') === 'stiinta-si-stiinta-ceai');
$verifica('Slug::make: textul fără litere → rezerva', Slug::make('—!—', 'articol') === 'articol' && Slug::make('') === '');
$luate = ['ceai' => true, 'ceai-2' => true];
$verifica('Slug::unique: la coliziune -2, -3 …', Slug::unique('Ceai', static fn (string $c): bool => isset($luate[$c])) === 'ceai-3');
$verifica('Slug::unique: un text lung se taie la 190 de caractere, fără „-” la capăt',
    mb_strlen(Slug::unique(str_repeat('ab ', 120), static fn (string $c): bool => false)) <= 190);
$verifica('Slug::transliterariUnuLaUnu: doar înlocuiri de un caracter (fără ß → ss, æ → ae)',
    !isset(Slug::transliterariUnuLaUnu()['ß']) && Slug::transliterariUnuLaUnu()['ă'] === 'a');
$verifica('Slug::simplu și Slug::categorie neschimbate (adresele magazinului)', Slug::simplu('Vitamina D-3 Ăî') === 'vitamina-d-3-ai'
    && Slug::categorie('Tiroidă și Menopauză') === 'tiroida-si-menopauza');
// make() pe un slug pe care l-a dat slugify() (doar a-z, 0-9 și „-” între ele) îl lasă neatins: adresa unui articol
// existent nu se schimbă la re-salvare.
$slugify = static function (string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9\- ]/i', '', $value) ?? '';
    $value = preg_replace('/[\s\-]+/', '-', $value) ?? '';
    return trim($value, '-');
};
mt_srand(22);
$neatinse = true;
$alfabet = 'abcdefghijklmnopqrstuvwxyz0123456789 -_ĂăȘșȚț!?.,';
for ($k = 0; $k < 2000; $k++) {
    $text = '';
    for ($n = mt_rand(1, 40); $n > 0; $n--) {
        $text .= mb_substr($alfabet, mt_rand(0, mb_strlen($alfabet) - 1), 1);
    }
    $vechi = $slugify($text);
    if (Slug::make($vechi) !== $vechi) {
        $neatinse = false;
        break;
    }
}
$verifica('Slug::make lasă neatins orice slug dat de slugify() din admin (2000 de texte la întâmplare)', $neatinse);

$toc = BlogToc::build('<h2>Introducere</h2><p>a</p><h3>Detalii</h3><h2>Introducere</h2><h2 id="al-meu">Concluzii</h2><h4>Nu</h4><h2>  </h2>');
$verifica('BlogToc: id-uri din text pe H2/H3, dublura primește -2, id-ul pus de mână rămâne, H4 și titlul gol fără id',
    str_contains($toc['html'], '<h2 id="introducere">Introducere</h2>') && str_contains($toc['html'], '<h3 id="detalii">Detalii</h3>')
    && str_contains($toc['html'], '<h2 id="introducere-2">Introducere</h2>') && str_contains($toc['html'], '<h2 id="al-meu">Concluzii</h2>')
    && str_contains($toc['html'], '<h4>Nu</h4>') && str_contains($toc['html'], '<h2>  </h2>') && $toc['count'] === 4, $toc['html']);
$verifica('BlogToc: cuprinsul are câte un rând pe titlu, H3 retras, linkurile spre ancore',
    substr_count($toc['toc'], '<li class="blog-toc__item') === 4 && str_contains($toc['toc'], 'blog-toc__item--h3')
    && str_contains($toc['toc'], 'href="#al-meu"') && str_contains($toc['toc'], 'href="#introducere-2"'), $toc['toc']);
$unTitlu = BlogToc::build('<h2>Singurul</h2><p>x</p>');
$verifica('BlogToc: un singur titlu → fără cuprins (dar cu ancoră)', $unTitlu['toc'] === '' && str_contains($unTitlu['html'], 'id="singurul"'));
$verifica('BlogToc: conținut gol → neschimbat', BlogToc::build('')['html'] === '' && BlogToc::build('')['toc'] === '');

$referinte = '<p>[1] Autor A, <a href="https://exemplu.ro/a">Studiu</a>.</p><p>[2] Autor B.</p><p>Fără număr.</p>';
$verifica('BlogReferences::numere: doar paragrafele care încep cu [n]', BlogReferences::numere($referinte) === [1, 2]);
$sectiune = BlogReferences::render($referinte);
$verifica('BlogReferences::render: secțiunea cu ancora #surse, fiecare referință cu id ref-n și săgeata spre cit-n',
    str_contains($sectiune, '<section class="blog-references" id="surse">') && str_contains($sectiune, 'id="ref-1" value="1"')
    && str_contains($sectiune, 'href="#cit-2"') && !str_contains($sectiune, 'Fără număr'), $sectiune);
$text = '<p>Prima [1], iar [1] din nou și [3] fără referință.</p><p>Veche [<a href="#surse">2</a>].</p>'
    . '<p><a href="https://exemplu.ro">link [2]</a></p><p data-x="[1]">atribut</p>';
$legat = BlogReferences::linkCitations($text, [1, 2]);
$verifica('BlogReferences::linkCitations: prima citare cu id cit-1, a doua fără, [3] fără referință rămâne text',
    substr_count($legat, '<a class="blog-cite" href="#ref-1" id="cit-1">[1]</a>') === 1
    && substr_count($legat, '<a class="blog-cite" href="#ref-1">[1]</a>') === 1 && str_contains($legat, 'și [3] fără'), $legat);
$verifica('BlogReferences::linkCitations: forma veche [<a href="#surse">2</a>] devine citare; [2] dintr-un link și atributele rămân',
    str_contains($legat, '<a class="blog-cite" href="#ref-2" id="cit-2">[2]</a>') && str_contains($legat, 'link [2]</a>')
    && str_contains($legat, 'data-x="[1]"'), $legat);
$verifica('BlogReferences: bibliografie liberă (fără [n]) → secțiunea cu ancoră, textul ca atare',
    BlogReferences::render('<p>Cărți citite</p>') === '<section class="blog-references" id="surse"><h2 class="blog-references__title">Referințe</h2>'
        . '<div class="blog-references__free"><p>Cărți citite</p></div></section>');
$verifica('BlogReferences: fără bibliografie → nimic', BlogReferences::render('') === '' && BlogReferences::numere('') === []
    && BlogReferences::linkCitations('<p>[1]</p>', []) === '<p>[1]</p>');

$verifica('BlogSearch::highlight: „sanatate” găsește „Sănătatea”, păstrând diacriticele',
    BlogSearch::highlight('Sănătatea inimii', 'sanatate') === '<mark class="blog-hit">Sănătate</mark>a inimii');
$verifica('BlogSearch::highlight: textul e escapat, termenii suprapuși dau un singur <mark>',
    BlogSearch::highlight('Ceai <b> & ceaiuri', 'ceai ceaiuri') === '<mark class="blog-hit">Ceai</mark> &lt;b&gt; &amp; <mark class="blog-hit">ceaiuri</mark>');
$verifica('BlogSearch::highlight: fără potrivire sau căutare goală → doar textul escapat',
    BlogSearch::highlight('a < b', 'zzz') === 'a &lt; b' && BlogSearch::highlight('a < b', '') === 'a &lt; b');
$fragment = BlogSearch::bestSnippet('Rezumat fără termen.', '<p>' . str_repeat('cuvânt ', 60) . 'Ghimbirul proaspăt ajută. ' . str_repeat('final ', 60) . '</p>', 'ghimbir');
$verifica('BlogSearch::bestSnippet: fragmentul din conținut din jurul termenului, cu „…”',
    str_contains($fragment, 'Ghimbirul proaspăt') && str_starts_with($fragment, '…') && str_ends_with($fragment, '…'), $fragment);
$verifica('BlogSearch::terms: cuvintele scurte cad, dacă rămâne ceva', BlogSearch::terms('a ceai de tei') === ['ceai', 'de', 'tei']
    && BlogSearch::terms('x') === ['x']);
$verifica('BlogAvansat: implicitele (pornit, titlul „Blog & Resurse”, eticheta/descrierea/categoria goale, autorul = numele site-ului)',
    BlogAvansat::pornit([]) && BlogAvansat::titluLista([]) === 'Blog & Resurse' && BlogAvansat::etichetaLista([]) === ''
    && BlogAvansat::descriereLista([]) === '' && BlogAvansat::categorieImplicita([]) === ''
    && BlogAvansat::autorImplicit(['store_name' => 'Firma Demo']) === 'Firma Demo' && BlogAvansat::initialaImplicita(['store_name' => 'Firma Demo']) === 'F'
    && !BlogAvansat::pornit(['blog_avansat' => '0']) && BlogAvansat::autorImplicit(['blog_autor_implicit' => 'Redacția']) === 'Redacția');

// =====================================================================
// WP-22 B. Schema
// =====================================================================
$scrie('');
$scrie('== WP-22 B. Schema (blog/0033) și catalogul');
$coloana = static function (string $tabel, string $coloana) use ($db): array {
    $stmt = $db->prepare('SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
    $stmt->execute(['t' => $tabel, 'c' => $coloana]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};
$latime = $coloana('blog_posts', 'featured_image_width');
$verifica('blog_posts.featured_image_width TINYINT UNSIGNED NOT NULL DEFAULT 100',
    ($latime['COLUMN_TYPE'] ?? '') === 'tinyint(3) unsigned' && ($latime['IS_NULLABLE'] ?? '') === 'NO' && ($latime['COLUMN_DEFAULT'] ?? '') === '100', json_encode($latime));
$verifica('blog_posts.references_html LONGTEXT NULL, wp_post_id INT UNSIGNED NULL cu indexul idx_blog_posts_wp',
    ($coloana('blog_posts', 'references_html')['COLUMN_TYPE'] ?? '') === 'longtext' && ($coloana('blog_posts', 'wp_post_id')['COLUMN_TYPE'] ?? '') === 'int(10) unsigned'
    && $unu("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blog_posts' AND INDEX_NAME = 'idx_blog_posts_wp'") === '1');
$verifica('blog_tags (name și slug unice) și blog_post_tags (cheia post_id + tag_id, indexul idx_bpt_tag)',
    $unu("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blog_tags' AND NON_UNIQUE = 0 AND INDEX_NAME IN ('name', 'slug')") === '2'
    && $unu("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blog_post_tags' AND INDEX_NAME IN ('PRIMARY', 'idx_bpt_tag')") === '3');
$verifica('migrarea blog/0033 e aplicată pe baza de dezvoltare', $unu("SELECT COUNT(*) FROM _platforma_migrari WHERE id = 'blog/0033_blog_avansat'") === '1');
$tabeleBlog = (array) (\App\Support\Plugins::catalog()['pluginuri']['blog']['tabele'] ?? []);
$verifica('catalogul pluginului blog are blog_tags și blog_post_tags', in_array('blog_tags', $tabeleBlog, true) && in_array('blog_post_tags', $tabeleBlog, true));

// =====================================================================
// Datele de test: categorii, etichete, articole (inventate), puse la loc la final
// =====================================================================
$categorie = static function (string $nume, string $slug, int $activa = 1) use ($db): int {
    $db->prepare('INSERT INTO blog_categories (name, slug, sort_order, is_active) VALUES (:n, :s, 95, :a)')->execute(['n' => $nume, 's' => $slug, 'a' => $activa]);
    return (int) $db->lastInsertId();
};
$eticheta = static function (string $nume, string $slug) use ($db): int {
    $db->prepare('INSERT INTO blog_tags (name, slug) VALUES (:n, :s)')->execute(['n' => $nume, 's' => $slug]);
    return (int) $db->lastInsertId();
};
$articol = static function (array $c) use ($db): int {
    $c += ['excerpt' => null, 'content' => '<p>Text.</p>', 'is_published' => 1, 'template_id' => null, 'references_html' => null,
        'featured_image_width' => 100, 'category' => null, 'category_id' => null, 'published_at' => date('Y-m-d H:i:s', time() - 3600)];
    $db->prepare('INSERT INTO blog_posts (title, slug, excerpt, content, reading_minutes, published_at, is_published, template_id, author_id,
            featured_image_url, featured_image_width, references_html, category, category_id)
        VALUES (:title, :slug, :excerpt, :content, 3, :published_at, :is_published, :template_id, NULL, NULL, :featured_image_width,
            :references_html, :category, :category_id)')->execute(array_intersect_key($c, array_flip(['title', 'slug', 'excerpt', 'content',
        'published_at', 'is_published', 'template_id', 'featured_image_width', 'references_html', 'category', 'category_id'])));
    return (int) $db->lastInsertId();
};
$leaga = static function (int $post, array $categorii = [], array $etichete = []) use ($db): void {
    foreach ($categorii as $c) {
        $db->prepare('INSERT INTO blog_post_categories (post_id, category_id) VALUES (?, ?)')->execute([$post, $c]);
    }
    foreach ($etichete as $e) {
        $db->prepare('INSERT INTO blog_post_tags (post_id, tag_id) VALUES (?, ?)')->execute([$post, $e]);
    }
};
$sufix = (string) getmypid();
$catPlante = $categorie('Plante test ' . $sufix, 'plante-test-' . $sufix);
$catGoala = $categorie('Goală test ' . $sufix, 'goala-test-' . $sufix);
$catInactiva = $categorie('Inactivă test ' . $sufix, 'inactiva-test-' . $sufix, 0);
$etiTei = $eticheta('Tei test ' . $sufix, 'tei-test-' . $sufix);
$etiNefolosita = $eticheta('Nefolosită ' . $sufix, 'nefolosita-' . $sufix);
$continutTitluri = '<h2>Despre sănătatea somnului</h2><p>Ceaiul de tei ajută [1], spune studiul [2].</p><h3>Cum se prepară</h3>'
    . '<p>Apă fierbinte.</p><h2>Concluzii</h2><p>Gata.</p>';
$referinteArticol = '<p>[1] Autor Demo, Studiu despre tei, 2020.</p><p>[2] Altă sursă demo.</p>';
$artPrincipal = $articol(['title' => 'Sănătatea somnului test ' . $sufix, 'slug' => 'sanatatea-somnului-test-' . $sufix,
    'content' => $continutTitluri, 'references_html' => $referinteArticol, 'featured_image_width' => 60,
    'category' => 'Plante test ' . $sufix, 'category_id' => $catPlante]);
$leaga($artPrincipal, [$catPlante], [$etiTei]);
$artVecin = $articol(['title' => 'Vecin în categorie ' . $sufix, 'slug' => 'vecin-categorie-' . $sufix, 'category_id' => $catPlante,
    'published_at' => date('Y-m-d H:i:s', time() - 7200)]);
$leaga($artVecin, [$catPlante]);
$artInactiv = $articol(['title' => 'Doar inactivă ' . $sufix, 'slug' => 'doar-inactiva-' . $sufix]);
$leaga($artInactiv, [$catInactiva]);
$artCiorna = $articol(['title' => 'Ciornă cu tei ' . $sufix, 'slug' => 'ciorna-tei-' . $sufix, 'is_published' => 0]);
$leaga($artCiorna, [$catGoala], [$etiTei]);
$sablonVechi = static function (string $html) use ($db, $sufix): int {
    $db->prepare('INSERT INTO blog_templates (name, slug, description, html_content, css_content, js_content, is_active)
        VALUES (?, ?, \'\', ?, \'\', \'\', 1)')->execute(['Șablon test ' . $sufix . ' ' . mt_rand(), 'sablon-test-' . $sufix . '-' . mt_rand(), $html]);
    return (int) $db->lastInsertId();
};
$sablonInteresante = $sablonVechi('<article class="leg"><h1>{{blog_title}}</h1><div class="leg__content">{{blog_content_html}}</div>'
    . '<section class="leg__related" data-blog-related><h2>Articole interesante</h2><div class="leg__related-grid" data-blog-related-list></div></section></article>');
$artVechi = $articol(['title' => 'Articol pe șablon vechi ' . $sufix, 'slug' => 'articol-sablon-vechi-' . $sufix, 'content' => $continutTitluri,
    'template_id' => $sablonInteresante, 'references_html' => $referinteArticol, 'category_id' => $catPlante]);
$leaga($artVechi, [$catPlante], [$etiTei]);
$sablonCuprins = $sablonVechi('<article class="cu-cuprins">{{blog_toc}}<div class="cc">{{blog_content_html}}</div>{{blog_related_posts}}</article>');
$artCuprins = $articol(['title' => 'Articol cu cuprins în șablon ' . $sufix, 'slug' => 'articol-cuprins-sablon-' . $sufix,
    'content' => $continutTitluri, 'template_id' => $sablonCuprins]);
ResponseCache::purgePageCache();

$fisiereAvansat = static fn (string $html): bool => str_contains($html, '/assets/css/blog-avansat.css?v=') && str_contains($html, '/assets/js/blog-anchors.js?v=');

// =====================================================================
// WP-22 C. Blogul avansat pornit
// =====================================================================
$scrie('');
$scrie('== WP-22 C. Blogul avansat pornit (blog_avansat = 1)');
$setare('blog_avansat', '1');
$lista = $pagina('/blog');
$verifica('/blog: formularul de căutare (GET, ?q=) și chips-urile categoriilor', $lista['cod'] === 200
    && str_contains($lista['corp'], '<form class="blog-search" method="get" action="/blog" role="search">')
    && str_contains($lista['corp'], '<nav class="blog-cats" aria-label="Categorii articole">'));
$verifica('/blog: chips-ul categoriei cu numărul articolelor ei publicate (3); categoria fără articole publicate și cea inactivă lipsesc',
    preg_match('#href="/blog/categorie/plante-test-' . $sufix . '">\s*Plante test ' . $sufix . '\s*<span class="blog-cats__count">3</span>#u', $lista['corp']) === 1
    && !str_contains($lista['corp'], 'goala-test-' . $sufix) && !str_contains($lista['corp'], 'inactiva-test-' . $sufix));
$cautare = $pagina('/blog?q=' . rawurlencode('sanatatea somnului'));
$verifica('/blog?q=sanatatea somnului (fără diacritice): articolul găsit, termenul în <mark>, rezumatul cu numărul, fără „Articol recent”',
    $cautare['cod'] === 200 && str_contains($cautare['corp'], '<mark class="blog-hit">Sănătatea</mark> <mark class="blog-hit">somnului</mark>')
    && str_contains($cautare['corp'], 'pentru „<strong>sanatatea somnului</strong>”') && !str_contains($cautare['corp'], 'Articol recent')
    && str_contains($cautare['corp'], '<title>Căutare: sanatatea somnului — '), mb_substr(strip_tags($cautare['corp']), 0, 300));
$nimic = $pagina('/blog?q=zzqqxx' . $sufix);
$verifica('/blog?q= fără rezultate: „Niciun articol pentru …” și linkul spre toate articolele', str_contains($nimic['corp'], 'Niciun articol pentru')
    && str_contains($nimic['corp'], 'Nu am găsit niciun articol după acest filtru.'));
$verifica('/blog?q= cu o listă în loc de text: lista întreagă, fără eroare', $pagina('/blog?q[]=x')['cod'] === 200);
$catPag = $pagina('/blog/categorie/plante-test-' . $sufix);
$verifica('/blog/categorie/{slug}: titlul categoriei (titlul listei), „3 articole”, doar articolele ei, chips-ul activ, titlul paginii',
    $catPag['cod'] === 200 && preg_match('#<h[12]>Plante test ' . $sufix . '</h[12]>#u', $catPag['corp']) === 1 && str_contains($catPag['corp'], '3 articole în această categorie.')
    && str_contains($catPag['corp'], 'vecin-categorie-' . $sufix) && !str_contains($catPag['corp'], 'articol-cuprins-sablon-' . $sufix)
    && str_contains($catPag['corp'], 'blog-cats__chip is-active') && str_contains($catPag['corp'], '<title>Plante test ' . $sufix . ' — '),
    mb_substr(strip_tags($catPag['corp']), 0, 400));
$verifica('/blog?categorie={slug}: aceeași listă filtrată', str_contains($pagina('/blog?categorie=plante-test-' . $sufix)['corp'], '3 articole în această categorie.'));
$etiPag = $pagina('/blog/eticheta/tei-test-' . $sufix);
$verifica('/blog/eticheta/{slug}: „Eticheta „…””, doar articolele publicate cu ea (ciorna nu)', $etiPag['cod'] === 200
    && str_contains($etiPag['corp'], 'Eticheta „Tei test ' . $sufix . '”') && str_contains($etiPag['corp'], '2 articole cu această etichetă.')
    && !str_contains($etiPag['corp'], 'ciorna-tei-' . $sufix));
$verifica('/blog/categorie/{slug} inexistentă, inactivă, și /blog/eticheta/{slug} inexistentă: 404 (pagina magazinului), nu o listă goală',
    $pagina('/blog/categorie/nu-exista-' . $sufix)['cod'] === 404 && $pagina('/blog/categorie/inactiva-test-' . $sufix)['cod'] === 404
    && $pagina('/blog/eticheta/nu-exista-' . $sufix)['cod'] === 404 && str_contains($pagina('/blog/eticheta/nu-exista-' . $sufix)['corp'], 'Eticheta nu a fost găsită'));
$verifica('/blog/eticheta/{slug} a unei etichete fără articole publicate: pagina ei, fără articole', str_contains($pagina('/blog/eticheta/nefolosita-' . $sufix)['corp'],
    'Nu am găsit niciun articol după acest filtru.'));
// Paginarea păstrează filtrul: 14 articole în categorie (13 pe pagină).
for ($i = 0; $i < 12; $i++) {
    $leaga($articol(['title' => 'Umplutură ' . $i . ' ' . $sufix, 'slug' => 'umplutura-' . $i . '-' . $sufix,
        'published_at' => date('Y-m-d H:i:s', time() - 86400 * 400 - $i)]), [$catPlante]);
}
$catPag = $pagina('/blog/categorie/plante-test-' . $sufix . '?q=umplutura');
$verifica('paginarea unei categorii cu căutare păstrează filtrul în linkuri (/blog/categorie/…?q=…&page=2)',
    str_contains($pagina('/blog/categorie/plante-test-' . $sufix)['corp'], 'href="/blog/categorie/plante-test-' . $sufix . '?page=2"')
    && str_contains($catPag['corp'], '12 articole găsite'), mb_substr(strip_tags($catPag['corp']), 0, 300));
$db->exec("DELETE FROM blog_post_categories WHERE post_id IN (SELECT id FROM blog_posts WHERE slug LIKE 'umplutura-%-" . $sufix . "')");
$db->exec("DELETE FROM blog_posts WHERE slug LIKE 'umplutura-%-" . $sufix . "'");
ResponseCache::purgePageCache();

$art = $pagina('/blog/sanatatea-somnului-test-' . $sufix);
$verifica('articolul (șablonul implicit): ancore pe H2/H3 și cuprinsul cu trei rânduri', $art['cod'] === 200
    && str_contains($art['corp'], '<h2 id="despre-sanatatea-somnului">') && str_contains($art['corp'], '<h3 id="cum-se-prepara">')
    && substr_count($art['corp'], 'class="blog-toc__item') === 3, mb_substr($art['corp'], 0, 200));
$verifica('articolul: citările legate (cit-1, cit-2) și bibliografia cu #surse, o singură dată',
    str_contains($art['corp'], '<a class="blog-cite" href="#ref-1" id="cit-1">[1]</a>') && substr_count($art['corp'], 'id="surse"') === 1
    && str_contains($art['corp'], 'id="ref-2" value="2"'));
$verifica('articolul: etichetele (chip spre /blog/eticheta/…) și articolele similare, cel din aceeași categorie primul',
    str_contains($art['corp'], '<a class="blog-tags__chip" href="/blog/eticheta/tei-test-' . $sufix . '">Tei test ' . $sufix . '</a>')
    && preg_match('#<section class="blog-related">.*?href="/blog/(?:vecin-categorie|articol-sablon-vechi)-' . $sufix . '"#s', $art['corp']) === 1
    && substr_count($art['corp'], 'class="blog-related-card"') === 3);
$verifica('articolul: coperta la 60% (bv-cover-img, regula de lățime), fișierele blogului avansat încărcate',
    str_contains($art['corp'], 'bv-cover-img') && str_contains($art['corp'], 'img.bv-cover-img{max-width:60%;') && $fisiereAvansat($art['corp']));
$verifica('fișierele blogului avansat: există și se servesc', $pagina('/assets/css/blog-avansat.css')['cod'] === 200
    && str_contains($pagina('/assets/js/blog-anchors.js')['corp'], 'bv-anchor-flash'));
$artV = $pagina('/blog/articol-sablon-vechi-' . $sufix);
$verifica('șablonul cu secțiunea veche „Articole interesante”: umplută de pe server cu articole înrudite, titlul „Articole similare”',
    str_contains($artV['corp'], '<h2>Articole similare</h2>') && str_contains($artV['corp'], 'data-blog-related-ready')
    && !str_contains($artV['corp'], 'data-blog-related-list') && str_contains($artV['corp'], 'class="leg__related-card"'));
$verifica('șablonul fără tokenurile noi: bibliografia și etichetele adăugate înaintea secțiunii articolelor similare',
    preg_match('#id="surse".*?class="blog-tags".*?data-blog-related#s', $artV['corp']) === 1);
$faraNimic = (string) $unu("SELECT slug FROM blog_posts WHERE is_published = 1 AND deleted_at IS NULL AND template_id IS NULL AND content NOT LIKE '%<h2%' AND id < "
    . $artPrincipal . ' ORDER BY id LIMIT 1');

// =====================================================================
// WP-22 D. Blogul avansat oprit (ca înghețarea Bioscem)
// =====================================================================
$scrie('');
$scrie('== WP-22 D. Blogul avansat oprit (blog_avansat = 0)');
$setare('blog_avansat', '0');
$lista = $pagina('/blog');
$verifica('/blog: fără căutare, fără chips-uri, fără stilurile lor', $lista['cod'] === 200 && !str_contains($lista['corp'], 'blog-search')
    && !str_contains($lista['corp'], 'blog-cats') && !str_contains($lista['corp'], 'blog-hit'));
$cuFiltre = $pagina('/blog?q=sanatatea&categorie=plante-test-' . $sufix . '&eticheta=tei-test-' . $sufix);
$verifica('/blog?q=…&categorie=…&eticheta=…: aceeași pagină ca /blog (filtrele ignorate)', $cuFiltre['corp'] === $lista['corp']);
$art = $pagina('/blog/sanatatea-somnului-test-' . $sufix);
$verifica('articolul (șablonul implicit): titluri fără ancore, fără cuprins, fără articole similare',
    str_contains($art['corp'], '<h2>Despre sănătatea somnului</h2>') && !str_contains($art['corp'], 'blog-toc') && !str_contains($art['corp'], 'blog-related'));
$verifica('articolul: bibliografia, citările și etichetele unui articol care le are apar totuși, cu fișierele lor',
    str_contains($art['corp'], 'id="surse"') && str_contains($art['corp'], 'class="blog-cite"') && str_contains($art['corp'], 'class="blog-tags"')
    && $fisiereAvansat($art['corp']));
$artV = $pagina('/blog/articol-sablon-vechi-' . $sufix);
$verifica('șablonul cu „Articole interesante”: secțiunea neatinsă (rămâne a scriptului)',
    str_contains($artV['corp'], '<h2>Articole interesante</h2><div class="leg__related-grid" data-blog-related-list></div>'));
$artC = $pagina('/blog/articol-cuprins-sablon-' . $sufix);
$verifica('un șablon care cere {{blog_toc}} și {{blog_related_posts}} le primește și cu blogul avansat oprit',
    str_contains($artC['corp'], '<nav class="blog-toc"') && str_contains($artC['corp'], 'id="despre-sanatatea-somnului"')
    && str_contains($artC['corp'], '<section class="blog-related">') && $fisiereAvansat($artC['corp']));
$simplu = $pagina('/blog/' . $faraNimic);
$verifica('un articol fără nimic din blogul avansat: fără fișierele lui', $simplu['cod'] === 200 && !$fisiereAvansat($simplu['corp'])
    && !str_contains($simplu['corp'], 'blog-avansat'), $faraNimic);
$verifica('/blog/categorie/{slug} merge și cu blogul avansat oprit (lista categoriei, fără căutare)',
    str_contains($pagina('/blog/categorie/plante-test-' . $sufix)['corp'], 'în această categorie.')
    && !str_contains($pagina('/blog/categorie/plante-test-' . $sufix)['corp'], '<form class="blog-search"'));
$setare('blog_avansat', null);

// =====================================================================
// WP-22 E. Textele listei (DB-13)
// =====================================================================
$scrie('');
$scrie('== WP-22 E. Textele listei și ale articolului (DB-13)');
$setare('blog_avansat', '0');
$setare('blog_eticheta_lista', '');
$setare('blog_descriere_lista', '');
$setare('blog_titlu_lista', 'Jurnal & noutăți');
$lista = $pagina('/blog')['corp'];
$antetLista = preg_match('#<header class="blog-listing__head">(.*?)</header>#s', $lista, $m) === 1 ? $m[1] : '';
$verifica('eticheta și descrierea goale nu se scriu; titlul din setare, escapat', $antetLista !== '' && !str_contains($antetLista, 'eyebrow')
    && str_contains($antetLista, '>Jurnal &amp; noutăți</h') && !str_contains($antetLista, '<p>'), $antetLista);
$setare('blog_eticheta_lista', 'Revista <Demo>');
$setare('blog_descriere_lista', 'Texte demo despre ceaiuri.');
$setare('blog_titlu_lista', null);
$lista = $pagina('/blog')['corp'];
$verifica('eticheta și descrierea din setări (escapate), titlul implicit „Blog & Resurse”',
    str_contains($lista, '<span class="blog-listing__eyebrow">Revista &lt;Demo&gt;</span>') && str_contains($lista, '<p>Texte demo despre ceaiuri.</p>')
    && str_contains($lista, '>Blog &amp; Resurse</h'));
$setare('blog_categorie_implicita', 'Categorie implicită demo');
$setare('blog_autor_implicit', 'Redacția Demo');
$db->prepare('UPDATE blog_posts SET category = NULL, author_id = NULL WHERE id = ?')->execute([$artCuprins]);
$db->prepare('UPDATE blog_templates SET html_content = ? WHERE id = ?')->execute(['<article><span class="c">{{blog_category}}</span>'
    . '<span class="i">{{blog_author_initials}}</span><span class="a">{{blog_author_name}}</span>{{blog_content_html}}</article>', $sablonCuprins]);
ResponseCache::purgePageCache();
$artC = $pagina('/blog/articol-cuprins-sablon-' . $sufix)['corp'];
$verifica('articolul fără categorie: categoria implicită din setare; fără autor: inițiala autorului implicit (autorul din bază rămâne gol)',
    str_contains($artC, '<span class="c">Categorie implicită demo</span><span class="i">R</span><span class="a"></span>'), mb_substr($artC, 0, 200));
$setare('blog_autor_implicit', null);
ResponseCache::purgePageCache();
$numeSite = \App\Support\Brand::numeSite(\App\Support\Settings::all($db));
$artC = $pagina('/blog/articol-cuprins-sablon-' . $sufix)['corp'];
$verifica('fără autor implicit: inițiala numelui site-ului', str_contains($artC, '<span class="i">' . mb_strtoupper(mb_substr($numeSite, 0, 1)) . '</span>'),
    $numeSite);
foreach (['blog_avansat', 'blog_eticheta_lista', 'blog_descriere_lista', 'blog_categorie_implicita'] as $cheie) {
    $setare($cheie, null);
}

// =====================================================================
// WP-22 F. Pluginul blog oprit
// =====================================================================
$scrie('');
$scrie('== WP-22 F. Pluginul blog oprit');
if ($licenta('catalog')) {
    $verifica('fără blog: /blog/categorie/…, /blog/eticheta/… și /blog?q=… dau 404', $pagina('/blog/categorie/plante-test-' . $sufix)['cod'] === 404
        && $pagina('/blog/eticheta/tei-test-' . $sufix)['cod'] === 404 && $pagina('/blog?q=ceai')['cod'] === 404);
} else {
    $verifica('licența „catalog” se poate pune', false);
}
$licenta('toate');

// =====================================================================
// Final: totul la loc
// =====================================================================
$scrie('');
$scrie('== Final: baza, licența, cache-ul și sesiunile la loc');
$puneLaLoc();
$sumeDupa = $sumeBaza();
$diferite = array_keys(array_diff_assoc($sumeDupa, $sumeInainte) + array_diff_assoc($sumeInainte, $sumeDupa));
$verifica('baza e exact cum era (CHECKSUM TABLE pe toate cele ' . count($sumeInainte) . ' tabele)', $diferite === [], implode(', ', $diferite));
$eroriNoi = is_file($fisierErori) ? (string) @file_get_contents($fisierErori, false, null, $pozitieErori) : '';
$verifica('nicio eroare PHP nouă în ' . basename($fisierErori), trim($eroriNoi) === '', mb_substr($eroriNoi, 0, 800));

$scrie('');
$scrie(sprintf('Rezultat: %d trecute, %d eșuate. Raport: %s', $trecute, $esuate, $fisierRaport));
exit($esuate === 0 ? 0 : 1);
