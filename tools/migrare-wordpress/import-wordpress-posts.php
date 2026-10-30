<?php

declare(strict_types=1);

/**
 * Unealta internă `migrare_wordpress` (WP-23: B7, din Biovitality 14d19dc): aduce postările dintr-un export WordPress
 * (WXR) în blogul site-ului. Rulează doar cu pluginul blog și cu unealta internă în licență, pe o bază la zi cu
 * migrările (coloanele și tabelele blogului avansat le face migrarea blog/0033, nu scriptul).
 *
 *   $PHP tools/migrare-wordpress/import-wordpress-posts.php storage/import/export.xml --dry-run
 *   $PHP tools/migrare-wordpress/import-wordpress-posts.php storage/import/export.xml
 *
 * Ce face, pentru fiecare postare: titlu, slug, conținut curățat, rezumat, dată, status (publicat / draft), autor,
 * categorii, etichete, imagine principală, bibliografia din câmpul „surse” și meta-urile SEO. Imaginile din conținut, de
 * pe domeniile exportului, se descarcă în uploads/blog/<an>/<lună> și adresele se rescriu, ca articolele să nu mai
 * depindă de site-ul vechi (pe copia de verificare nu se descarcă nimic: adresele rămân, ca --skip-images).
 *
 * Se poate rula de mai multe ori: postările deja aduse se actualizează, nu se dublează (blog_posts.wp_post_id).
 *
 * **Fișierul sursă se șterge** după o rulare care a scris și n-a sărit nicio postare din cauza unei erori (planul Etapei
 * 1, G-m9): un export WXR are emailurile autorilor și nu are voie să rămână în storage/import (copiile de siguranță l-ar
 * ține 12 luni). `--pastreaza-sursa` îl lasă (de exemplu pentru încă o probă); --dry-run nu-l atinge niciodată.
 *
 * Opțiuni:
 *   --dry-run          arată ce s-ar întâmpla, fără să scrie nimic (și fără să șteargă sursa)
 *   --limit=N          doar primele N postări (pentru o probă)
 *   --only-published   sare peste draft-uri
 *   --skip-images      nu descarcă imaginile (adresele rămân cele vechi)
 *   --post-type=TIP    tipul de postare din WordPress (implicit: post; la Biovitality era „project”)
 *   --extra-host=HOST  încă un domeniu de pe care se pot descărca imagini
 *   --pastreaza-sursa  nu șterge fișierul exportului la final
 *
 * Cod de ieșire: 0 = gata (sau proba), 1 = eroare (fișier, export, bază, o postare sărită din cauza unei erori),
 * 2 = refuzat (unealta internă sau blogul nu sunt în licență, baza nu e la zi cu migrarea blog/0033).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/WordPressExport.php';
require_once __DIR__ . '/WordPressContent.php';

use App\Support\Database;
use App\Support\Paths;
use App\Support\Plugins;
use App\Support\Slug;
use App\Support\Verificare;
use App\Support\WordPressContent;
use App\Support\WordPressExport;

$optiuni = [
    'dry-run' => false,
    'skip-images' => false,
    'only-published' => false,
    'pastreaza-sursa' => false,
    'limit' => 0,
    'post-type' => 'post',
    'extra-host' => [],
];
$caleFisier = '';

foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--')) {
        $caleFisier = $argument;
        continue;
    }
    [$cheie, $valoare] = array_pad(explode('=', substr($argument, 2), 2), 2, '');
    match ($cheie) {
        'dry-run', 'skip-images', 'only-published', 'pastreaza-sursa' => $optiuni[$cheie] = true,
        'limit' => $optiuni['limit'] = max(0, (int) $valoare),
        'post-type' => $optiuni['post-type'] = trim($valoare) !== '' ? trim($valoare) : 'post',
        'extra-host' => $optiuni['extra-host'][] = strtolower(trim($valoare)),
        default => (static function () use ($cheie): void {
            fwrite(STDERR, "Opțiune necunoscută: --{$cheie} (vezi începutul fișierului)\n");
            exit(1);
        })(),
    };
}

if ($caleFisier === '') {
    fwrite(STDERR, "Utilizare: \$PHP tools/migrare-wordpress/import-wordpress-posts.php <export.xml> [opțiuni] (vezi începutul fișierului)\n");
    exit(1);
}

// Unealta internă: doar cu ea și cu blogul în licență (docs/PLUGINURI.md, „Unealta internă migrare_wordpress”).
if (!Plugins::vizibil('migrare_wordpress') || !Plugins::vizibil('blog')) {
    fwrite(STDERR, "Refuzat: importul articolelor din WordPress cere unealta internă migrare_wordpress și pluginul blog în licența site-ului.\n");
    exit(2);
}

$config = require dirname(__DIR__, 2) . '/config/app.php';
$db = Database::connection($config['db']);
if (!$db instanceof PDO) {
    fwrite(STDERR, "Conexiunea la baza de date nu este disponibilă.\n");
    exit(1);
}
// Coloanele și tabelele importului le face migrarea blog/0033 (fără DDL aici).
try {
    $db->query('SELECT wp_post_id, references_html FROM blog_posts LIMIT 0');
    $db->query('SELECT id FROM blog_tags LIMIT 0');
    $db->query('SELECT post_id FROM blog_post_tags LIMIT 0');
} catch (Throwable) {
    fwrite(STDERR, "Refuzat: baza nu are încă migrarea blog/0033 (rulează întâi \$PHP scripts/migreaza.php --aplica).\n");
    exit(2);
}
if (Verificare::activ() && !$optiuni['skip-images']) {
    // Copia de verificare nu cere nimic de pe site-urile adevărate (docs/VERIFICARE.md, „Gărzile copiei”).
    echo "Copia de verificare: imaginile nu se descarcă (ca --skip-images).\n";
    $optiuni['skip-images'] = true;
}

echo "Citesc exportul: {$caleFisier}\n";
try {
    $export = WordPressExport::fromFile($caleFisier, (string) $optiuni['post-type']);
} catch (Throwable $e) {
    fwrite(STDERR, 'Eroare: ' . $e->getMessage() . "\n");
    exit(1);
}

$postari = $export->posts();
if ($optiuni['only-published']) {
    $postari = array_values(array_filter($postari, static fn (array $p): bool => (bool) $p['is_published']));
}
if ($optiuni['limit'] > 0) {
    $postari = array_slice($postari, 0, $optiuni['limit']);
}
$publicate = count(array_filter($postari, static fn (array $p): bool => (bool) $p['is_published']));
printf(
    "Am găsit %d postări de tip „%s” (%d publicate, %d draft) și %d atașamente.\n\n",
    count($postari),
    (string) $optiuni['post-type'],
    $publicate,
    count($postari) - $publicate,
    count($export->attachments())
);
if ($postari === []) {
    echo "Nimic de importat.\n";
}

// Domeniile de pe care avem voie să aducem imagini: site-ul din export plus
// celelalte adrese de preview Hostinger ale aceluiași proprietar. Restul
// (imagini de pe site-uri străine) rămân neatinse și se raportează la final.
$gazdeProprii = [];
foreach ($export->attachments() as $adresa) {
    $gazda = strtolower((string) parse_url($adresa, PHP_URL_HOST));
    if ($gazda !== '') {
        $gazdeProprii[$gazda] = true;
    }
}
foreach ($optiuni['extra-host'] as $gazda) {
    if ($gazda !== '') {
        $gazdeProprii[$gazda] = true;
    }
}

$radacinaUploads = Paths::uploads('blog');
/** @var array<string, string> adresă veche => adresă nouă */
$imaginiAduse = [];
/** @var array<string, true> */
$imaginiExterne = [];
/** @var array<string, true> */
$imaginiEsuate = [];

$numarAdaugate = 0;
$numarActualizate = 0;
$numarSarite = 0;
$numarErori = 0;

foreach ($postari as $index => $postare) {
    $titlu = (string) $postare['title'];
    if ($titlu === '') {
        $numarSarite++;
        fwrite(STDERR, "  ! postare fără titlu (wp_id {$postare['wp_id']}) — sărită\n");
        continue;
    }

    $wpId = (int) $postare['wp_id'];
    $existent = gasestePostareaExistenta($db, $wpId, (string) $postare['slug']);

    $slug = (string) $postare['slug'];
    if ($slug === '') {
        // Draft-urile din WordPress n-au încă `post_name`; îl facem din titlu.
        $slug = Slug::make($titlu, 'articol-' . $wpId);
    }
    if ($existent === null) {
        $slug = Slug::unique($slug, static fn (string $s): bool => slugLuat($db, $s), 'articol-' . $wpId);
    }

    $continut = WordPressContent::curata((string) $postare['content']);
    $referinte = WordPressContent::curata((string) $postare['references_html']);
    $imagineProprie = $export->attachmentUrl((int) $postare['thumbnail_id']);

    // ── Imaginile ───────────────────────────────────────────────────────────
    if (!$optiuni['skip-images']) {
        $deAdus = WordPressContent::adreseImagini($continut . ' ' . $referinte);
        if ($imagineProprie !== '') {
            $deAdus[] = $imagineProprie;
        }
        foreach (array_unique($deAdus) as $adresa) {
            if (isset($imaginiAduse[$adresa]) || isset($imaginiExterne[$adresa]) || isset($imaginiEsuate[$adresa])) {
                continue;
            }
            $gazda = strtolower((string) parse_url($adresa, PHP_URL_HOST));
            if ($gazda === '' || !isset($gazdeProprii[$gazda]) || !WordPressContent::esteImagine($adresa)) {
                $imaginiExterne[$adresa] = true;
                continue;
            }
            $local = $optiuni['dry-run']
                ? caleLocalaPentru($adresa)
                : descarcaImaginea($adresa, $radacinaUploads);
            if ($local === null) {
                $imaginiEsuate[$adresa] = true;
                continue;
            }
            $imaginiAduse[$adresa] = $local;
        }

        $continut = WordPressContent::rescrieImagini($continut, $imaginiAduse);
        $referinte = WordPressContent::rescrieImagini($referinte, $imaginiAduse);
        if ($imagineProprie !== '' && isset($imaginiAduse[$imagineProprie])) {
            $imagineProprie = $imaginiAduse[$imagineProprie];
        }
    }

    $randuri = [
        'title' => $titlu,
        'slug' => $slug,
        'excerpt' => WordPressContent::rezumat((string) $postare['excerpt'], $continut),
        'content' => $continut !== '' ? $continut : '<p></p>',
        'references_html' => $referinte !== '' ? $referinte : null,
        'reading_minutes' => WordPressContent::minuteCitire($continut),
        'published_at' => (string) $postare['published_at'],
        'is_published' => (bool) $postare['is_published'] ? 1 : 0,
        'featured_image_url' => $imagineProprie !== '' ? $imagineProprie : null,
        'wp_post_id' => $wpId > 0 ? $wpId : null,
    ];

    $eticheta = ($existent !== null ? 'actualizez' : 'adaug     ')
        . sprintf(' [%3d/%d] ', $index + 1, count($postari))
        . ((bool) $postare['is_published'] ? '        ' : '(draft) ')
        . mb_substr($titlu, 0, 58);
    echo $eticheta . "\n";

    if ($optiuni['dry-run']) {
        $existent !== null ? $numarActualizate++ : $numarAdaugate++;
        continue;
    }

    try {
        $db->beginTransaction();

        if ($existent !== null) {
            $postId = (int) $existent['id'];
            $stmt = $db->prepare(
                'UPDATE blog_posts
                 SET title = :title, excerpt = :excerpt, content = :content,
                     references_html = :references_html, reading_minutes = :reading_minutes,
                     published_at = :published_at, is_published = :is_published,
                     featured_image_url = :featured_image_url, category = :category,
                     category_id = :category_id, author_id = :author_id,
                     wp_post_id = :wp_post_id, deleted_at = NULL
                 WHERE id = :id'
            );
        } else {
            $stmt = $db->prepare(
                'INSERT INTO blog_posts
                    (title, slug, excerpt, content, references_html, reading_minutes, published_at,
                     is_published, featured_image_url, category, category_id, author_id, wp_post_id)
                 VALUES
                    (:title, :slug, :excerpt, :content, :references_html, :reading_minutes, :published_at,
                     :is_published, :featured_image_url, :category, :category_id, :author_id, :wp_post_id)'
            );
        }

        $idCategorii = [];
        foreach ((array) $postare['categories'] as $numeCategorie) {
            $idCategorie = idCategorie($db, (string) $numeCategorie);
            if ($idCategorie > 0) {
                $idCategorii[] = $idCategorie;
            }
        }

        $parametri = $randuri;
        // `category` (numele) și `category_id` rămân pe prima categorie: așa
        // le folosesc listările vechi, care nu știu de tabela de legătură.
        $parametri['category'] = (string) (($postare['categories'][0] ?? '') ?: '') ?: null;
        $parametri['category_id'] = $idCategorii[0] ?? null;
        $parametri['author_id'] = idAutor($db, (string) $postare['author']) ?: null;
        if ($existent !== null) {
            unset($parametri['slug']);
            $parametri['id'] = $postId;
        }
        $stmt->execute($parametri);

        if ($existent === null) {
            $postId = (int) $db->lastInsertId();
        }

        sincronizeazaLegaturi($db, 'blog_post_categories', 'category_id', $postId, $idCategorii);

        $idEtichete = [];
        foreach ((array) $postare['tags'] as $numeEticheta) {
            $idEticheta = idEticheta($db, (string) $numeEticheta);
            if ($idEticheta > 0) {
                $idEtichete[] = $idEticheta;
            }
        }
        sincronizeazaLegaturi($db, 'blog_post_tags', 'tag_id', $postId, $idEtichete);

        salveazaSeo($db, $postId, (string) $postare['seo_title'], (string) $postare['seo_description']);

        $db->commit();
        $existent !== null ? $numarActualizate++ : $numarAdaugate++;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $numarSarite++;
        $numarErori++;
        fwrite(STDERR, "  ! „{$titlu}”: " . $e->getMessage() . "\n");
    }
}

// ── Raport ──────────────────────────────────────────────────────────────────
echo "\n";
echo $optiuni['dry-run'] ? "PROBĂ (n-am scris nimic)\n" : "Gata.\n";
printf("  adăugate:    %d\n  actualizate: %d\n  sărite:      %d\n", $numarAdaugate, $numarActualizate, $numarSarite);
printf("  imagini aduse: %d\n", count($imaginiAduse));

if ($imaginiExterne !== []) {
    printf("\n  %d imagini sunt pe site-uri străine și au rămas neatinse:\n", count($imaginiExterne));
    foreach (array_keys($imaginiExterne) as $adresa) {
        echo '    ' . mb_substr($adresa, 0, 110) . "\n";
    }
    echo "  (dacă vrei aduse și astea, rulează cu --extra-host=domeniul.ro)\n";
}
if ($imaginiEsuate !== []) {
    printf("\n  %d imagini n-au putut fi descărcate:\n", count($imaginiEsuate));
    foreach (array_keys($imaginiEsuate) as $adresa) {
        echo '    ' . mb_substr($adresa, 0, 110) . "\n";
    }
}


// ── Fișierul sursă (G-m9): un export WXR are emailurile autorilor; nu rămâne pe server după import.
if ($optiuni['dry-run']) {
    echo "\nFișierul sursă a rămas (probă).\n";
} elseif ($numarErori > 0) {
    echo "\nFișierul sursă a rămas: {$numarErori} postări n-au putut fi scrise (mai sus). Șterge-l după ce reiei importul.\n";
} elseif ($optiuni['pastreaza-sursa']) {
    echo "\nFișierul sursă a rămas (--pastreaza-sursa). Șterge-l după ultima rulare: are emailurile autorilor.\n";
} elseif (@unlink($caleFisier)) {
    echo "\nFișierul sursă a fost șters ({$caleFisier}).\n";
} else {
    fwrite(STDERR, "\nFișierul sursă NU a putut fi șters ({$caleFisier}): șterge-l de mână, are emailurile autorilor.\n");
    exit(1);
}
exit($numarErori > 0 ? 1 : 0);

// ── Ajutoare ────────────────────────────────────────────────────────────────

/** @return array<string, mixed>|null */
function gasestePostareaExistenta(PDO $db, int $wpId, string $slug): ?array
{
    if ($wpId > 0) {
        try {
            $stmt = $db->prepare('SELECT id FROM blog_posts WHERE wp_post_id = :wp LIMIT 1');
            $stmt->execute(['wp' => $wpId]);
            $rand = $stmt->fetch();
            if (is_array($rand)) {
                return $rand;
            }
        } catch (Throwable) {
        }
    }
    if ($slug === '') {
        return null;
    }
    try {
        $stmt = $db->prepare('SELECT id FROM blog_posts WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $rand = $stmt->fetch();

        return is_array($rand) ? $rand : null;
    } catch (Throwable) {
        return null;
    }
}

function slugLuat(PDO $db, string $slug): bool
{
    try {
        $stmt = $db->prepare('SELECT 1 FROM blog_posts WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);

        return $stmt->fetchColumn() !== false;
    } catch (Throwable) {
        return false;
    }
}

/** Categoria cu numele dat; o creează dacă nu există. */
function idCategorie(PDO $db, string $nume): int
{
    static $memorie = [];
    $nume = trim($nume);
    if ($nume === '') {
        return 0;
    }
    if (isset($memorie[$nume])) {
        return $memorie[$nume];
    }

    try {
        $stmt = $db->prepare('SELECT id FROM blog_categories WHERE name = :name LIMIT 1');
        $stmt->execute(['name' => $nume]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id === 0) {
            $slug = Slug::unique(
                $nume,
                static function (string $s) use ($db): bool {
                    $q = $db->prepare('SELECT 1 FROM blog_categories WHERE slug = :slug LIMIT 1');
                    $q->execute(['slug' => $s]);

                    return $q->fetchColumn() !== false;
                },
                'categorie'
            );
            $ins = $db->prepare('INSERT INTO blog_categories (name, slug, sort_order) VALUES (:name, :slug, 100)');
            $ins->execute(['name' => $nume, 'slug' => $slug]);
            $id = (int) $db->lastInsertId();
        }

        return $memorie[$nume] = $id;
    } catch (Throwable) {
        return 0;
    }
}

/** Eticheta cu numele dat; o creează dacă nu există. */
function idEticheta(PDO $db, string $nume): int
{
    static $memorie = [];
    $nume = trim($nume);
    if ($nume === '') {
        return 0;
    }
    if (isset($memorie[$nume])) {
        return $memorie[$nume];
    }

    try {
        $stmt = $db->prepare('SELECT id FROM blog_tags WHERE name = :name LIMIT 1');
        $stmt->execute(['name' => $nume]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id === 0) {
            $slug = Slug::unique(
                $nume,
                static function (string $s) use ($db): bool {
                    $q = $db->prepare('SELECT 1 FROM blog_tags WHERE slug = :slug LIMIT 1');
                    $q->execute(['slug' => $s]);

                    return $q->fetchColumn() !== false;
                },
                'eticheta'
            );
            $ins = $db->prepare('INSERT INTO blog_tags (name, slug) VALUES (:name, :slug)');
            $ins->execute(['name' => $nume, 'slug' => $slug]);
            $id = (int) $db->lastInsertId();
        }

        return $memorie[$nume] = $id;
    } catch (Throwable) {
        return 0;
    }
}

/** Autorul cu numele dat; îl creează dacă nu există. */
function idAutor(PDO $db, string $nume): int
{
    static $memorie = [];
    $nume = trim($nume);
    if ($nume === '') {
        return 0;
    }
    if (isset($memorie[$nume])) {
        return $memorie[$nume];
    }

    try {
        $stmt = $db->prepare('SELECT id FROM blog_authors WHERE name = :name LIMIT 1');
        $stmt->execute(['name' => $nume]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id === 0) {
            $slug = Slug::unique(
                $nume,
                static function (string $s) use ($db): bool {
                    $q = $db->prepare('SELECT 1 FROM blog_authors WHERE slug = :slug LIMIT 1');
                    $q->execute(['slug' => $s]);

                    return $q->fetchColumn() !== false;
                },
                'autor'
            );
            $ins = $db->prepare('INSERT INTO blog_authors (name, slug, is_active) VALUES (:name, :slug, 1)');
            $ins->execute(['name' => $nume, 'slug' => $slug]);
            $id = (int) $db->lastInsertId();
        }

        return $memorie[$nume] = $id;
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Rescrie legăturile many-to-many ale unei postări.
 *
 * @param int[] $idAsociate
 */
function sincronizeazaLegaturi(PDO $db, string $tabela, string $coloana, int $postId, array $idAsociate): void
{
    try {
        $sterge = $db->prepare("DELETE FROM {$tabela} WHERE post_id = :pid");
        $sterge->execute(['pid' => $postId]);
        if ($idAsociate === []) {
            return;
        }
        $adauga = $db->prepare("INSERT IGNORE INTO {$tabela} (post_id, {$coloana}) VALUES (:pid, :aid)");
        foreach (array_unique($idAsociate) as $id) {
            $adauga->execute(['pid' => $postId, 'aid' => $id]);
        }
    } catch (Throwable) {
    }
}

function salveazaSeo(PDO $db, int $postId, string $titlu, string $descriere): void
{
    if (trim($titlu) === '' && trim($descriere) === '') {
        return;
    }
    try {
        $stmt = $db->prepare(
            'INSERT INTO seo_pages (page_type, page_ref, title, description)
             VALUES ("blog_post", :ref, :title, :description)
             ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description)'
        );
        $stmt->execute([
            'ref' => (string) $postId,
            'title' => trim($titlu) !== '' ? trim($titlu) : null,
            'description' => trim($descriere) !== '' ? trim($descriere) : null,
        ]);
    } catch (Throwable) {
    }
}

/**
 * Adresa publică pe care o va avea imaginea la noi, păstrând anul și luna din
 * WordPress ca să nu se calce fișierele cu același nume.
 */
function caleLocalaPentru(string $adresa): string
{
    $cale = (string) parse_url($adresa, PHP_URL_PATH);
    $fisier = basename($cale);
    $fisier = (string) preg_replace('/[^A-Za-z0-9._-]/', '-', rawurldecode($fisier));
    if ($fisier === '' || !str_contains($fisier, '.')) {
        $fisier = 'imagine-' . substr(md5($adresa), 0, 10) . '.jpg';
    }

    $subfolder = preg_match('#/(\d{4})/(\d{2})/#', $cale, $m) === 1 ? $m[1] . '/' . $m[2] : 'import';

    return '/uploads/blog/' . $subfolder . '/' . $fisier;
}

/**
 * Descarcă imaginea în uploads/blog (App\Support\Paths::uploads) și întoarce adresa ei publică.
 * Un fișier deja adus nu se descarcă din nou.
 */
function descarcaImaginea(string $adresa, string $radacina): ?string
{
    $adresaPublica = caleLocalaPentru($adresa);
    $tinta = $radacina . substr($adresaPublica, strlen('/uploads/blog'));

    if (is_file($tinta) && filesize($tinta) > 0) {
        return $adresaPublica;
    }

    $folder = dirname($tinta);
    if (!is_dir($folder) && !@mkdir($folder, 0775, true) && !is_dir($folder)) {
        return null;
    }

    $continut = descarca($adresa);
    if ($continut === null || strlen($continut) < 100) {
        return null;
    }
    if (file_put_contents($tinta, $continut) === false) {
        return null;
    }
    @chmod($tinta, 0664);

    return $adresaPublica;
}

function descarca(string $adresa): ?string
{
    $adresa = html_entity_decode($adresa, ENT_QUOTES, 'UTF-8');

    if (function_exists('curl_init')) {
        $ch = curl_init($adresa);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_USERAGENT => 'andaxi-cms/import-wordpress',
            ]);
            $raspuns = curl_exec($ch);
            $cod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if (is_string($raspuns) && $cod >= 200 && $cod < 300) {
                return $raspuns;
            }

            return null;
        }
    }

    $context = stream_context_create(['http' => ['timeout' => 60, 'user_agent' => 'andaxi-cms/import-wordpress']]);
    $raspuns = @file_get_contents($adresa, false, $context);

    return is_string($raspuns) ? $raspuns : null;
}
