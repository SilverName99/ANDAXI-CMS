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
 * Se poate rula de mai multe ori: postările deja aduse (blog_posts.wp_post_id) se actualizează, nu se dublează. Ce nu
 * suprascrie niciodată (revizuirea WP-23):
 *   - o postare din CMS (fără wp_post_id) cu același slug: postarea din export se adaugă ca nouă, cu slug-ul liber următor
 *     („-2”), decât cu --adopta-dupa-slug (atunci cea din CMS e preluată și primește wp_post_id). --dry-run le listează
 *     separat;
 *   - o postare din coș (deleted_at): se sare și se raportează, nu se scoate din coș;
 *   - o postare adusă de import și editată după aceea în admin (updated_at > created_at: importul le scrie egale, o
 *     salvare din admin care schimbă rândul mută updated_at): se sare și se raportează, decât cu --suprascrie. O salvare
 *     care schimbă doar etichetele, categoriile sau SEO nu atinge rândul și nu se vede.
 *
 * Imaginile (revizuirea WP-23): doar http/https, doar de pe domeniile exportului (și --extra-host), verificat și după
 * fiecare redirecționare; adresa IP a domeniului nu are voie să fie privată, loopback, link-local sau rezervată (și e
 * fixată pentru descărcare, fără proxy); fără svg; cel mult 15 MB; tipul (Content-Type și conținutul) jpeg, png, webp,
 * gif sau avif. Un fișier cu același nume deja în uploads e refolosit doar dacă are exact același conținut; altfel
 * imaginea nouă primește alt nume („-2”). Pentru testele locale: CMS_DEV_IMPORT_ADRESE_LOCALE=1 permite 127.0.0.1/::1.
 *
 * **Fișierul sursă se șterge** (planul Etapei 1, G-m9: un export WXR are emailurile autorilor și nu are voie să rămână în
 * storage/import, copiile de siguranță l-ar ține 12 luni) doar când toate sunt adevărate: e un export WordPress
 * (`<wp:wxr_version>`), are cel puțin o postare de tipul cerut, s-au adus toate (fără --limit și fără
 * --only-published), nicio imagine eșuată, nicio eroare (postare, categorie, etichetă, autor, legături, SEO), nicio
 * postare sărită pentru că a fost editată (o reluare cu --suprascrie are nevoie de fișier), fișierul e în
 * storage/import (Paths::stocare('import')), nu e --dry-run și nu e --pastreaza-sursa. Altfel scriptul spune de ce a
 * rămas.
 *
 * Opțiuni:
 *   --dry-run           arată ce s-ar întâmpla, fără să scrie nimic (și fără să șteargă sursa)
 *   --limit=N           doar primele N postări (pentru o probă)
 *   --only-published    sare peste draft-uri
 *   --skip-images       nu descarcă imaginile (adresele rămân cele vechi)
 *   --post-type=TIP     tipul de postare din WordPress (implicit: post; la Biovitality era „project”)
 *   --extra-host=HOST   încă un domeniu de pe care se pot descărca imagini
 *   --adopta-dupa-slug  o postare din CMS fără wp_post_id, cu același slug, e preluată (suprascrisă) în loc de una nouă
 *   --suprascrie        suprascrie și postările aduse de import și editate după aceea în admin
 *   --pastreaza-sursa   nu șterge fișierul exportului la final
 *
 * Cod de ieșire: 0 = gata (sau proba), 1 = eroare (fișier, export, bază, o postare sau un termen care n-au putut fi
 * scriși, sursa care trebuia ștearsă și n-a putut fi),
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


const IMPORT_IMAGINE_MAX_OCTETI = 15 * 1024 * 1024;
const IMPORT_IMAGINE_TIPURI = [
    'image/jpeg' => ['jpg', 'jpeg'],
    'image/png' => ['png'],
    'image/webp' => ['webp'],
    'image/gif' => ['gif'],
    'image/avif' => ['avif'],
];

/**
 * Ce a aflat importul despre termeni (categorii, etichete, autori) în rularea asta. Memoria se golește la rollback-ul unei
 * postări (revizuirea WP-23): un termen creat în tranzacția anulată nu mai există, iar id-ul lui ținut minte ar lega
 * postările următoare de un rând inexistent.
 */
final class TermeniImport
{
    /** @var array<string, array<string, int>> tabelă -> nume -> id */
    public static array $memorie = [];

    /** Erori la categorii, etichete, autori, legături, SEO (fiecare anulează postarea ei; se numără, nu se ascund). */
    public static int $esecuri = 0;

    public static function uita(): void
    {
        self::$memorie = [];
    }
}

$optiuni = [
    'dry-run' => false,
    'skip-images' => false,
    'only-published' => false,
    'pastreaza-sursa' => false,
    'adopta-dupa-slug' => false,
    'suprascrie' => false,
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
        'dry-run', 'skip-images', 'only-published', 'pastreaza-sursa', 'adopta-dupa-slug', 'suprascrie' => $optiuni[$cheie] = true,
        'limit' => $optiuni['limit'] = max(0, (int) $valoare),
        'post-type' => $optiuni['post-type'] = trim($valoare) !== '' ? trim($valoare) : 'post',
        'extra-host' => $optiuni['extra-host'][] = gazdaFaraPort($valoare),
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
$adreseLocale = getenv('CMS_DEV_IMPORT_ADRESE_LOCALE') === '1';
if ($adreseLocale && !$optiuni['skip-images']) {
    echo "CMS_DEV_IMPORT_ADRESE_LOCALE=1: imaginile se pot descărca și de pe 127.0.0.1 / ::1 (doar pentru teste).\n";
}

echo "Citesc exportul: {$caleFisier}\n";
try {
    $export = WordPressExport::fromFile($caleFisier, (string) $optiuni['post-type']);
} catch (Throwable $e) {
    fwrite(STDERR, 'Eroare: ' . $e->getMessage() . "\n");
    exit(1);
}

$esteWxr = $export->wxrVersion() !== '';
if (!$esteWxr) {
    echo "Atenție: fișierul nu pare un export WordPress (lipsește <wp:wxr_version>); nu se va șterge.\n";
}
$postari = $export->posts();
$totalInExport = count($postari);
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
    $alteTipuri = array_diff_key($export->tipuri(), ['attachment' => true, 'nav_menu_item' => true, (string) $optiuni['post-type'] => true]);
    if ($totalInExport === 0 && $alteTipuri !== []) {
        arsort($alteTipuri);
        echo 'În export sunt: ' . implode(', ', array_map(static fn (string $tip, int $n): string => "{$tip} ({$n})",
            array_keys($alteTipuri), $alteTipuri)) . ". Tipul articolelor se alege cu --post-type=TIP.\n";
    }
}

// Domeniile de pe care avem voie să aducem imagini: site-ul din export plus
// celelalte adrese de preview Hostinger ale aceluiași proprietar. Restul
// (imagini de pe site-uri străine) rămân neatinse și se raportează la final.
$gazdeProprii = [];
foreach ($export->attachments() as $adresa) {
    $gazda = strtolower(trim((string) parse_url($adresa, PHP_URL_HOST), '[]'));
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
/** @var array<string, string> adresă => motivul */
$imaginiEsuate = [];

$numarAdaugate = 0;
$numarActualizate = 0;
$numarSarite = 0;
$numarErori = 0;
/** @var list<string> postările din coș, sărite */
$inCos = [];
/** @var list<string> postările editate în admin după import, sărite (fără --suprascrie) */
$editate = [];
/** @var list<string> postările din CMS cu același slug */
$potriviriSlug = [];

foreach ($postari as $index => $postare) {
    $titlu = (string) $postare['title'];
    if ($titlu === '') {
        $numarSarite++;
        fwrite(STDERR, "  ! postare fără titlu (wp_id {$postare['wp_id']}) — sărită\n");
        continue;
    }

    $wpId = (int) $postare['wp_id'];
    $slugWp = (string) $postare['slug'];
    $descriere = sprintf('[%3d/%d] ', $index + 1, count($postari)) . mb_substr($titlu, 0, 58);

    // ── Postarea de la noi: întâi după wp_post_id; după slug doar una din CMS (fără wp_post_id), cu --adopta-dupa-slug.
    $existent = postareDupaWpId($db, $wpId);
    $adoptata = false;
    if ($existent === null && $slugWp !== '') {
        $potrivire = postareDupaSlug($db, $slugWp);
        if ($potrivire !== null) {
            $faraWp = $potrivire['wp_post_id'] === null;
            $potriviriSlug[] = $descriere . ' ↔ #' . $potrivire['id'] . ' „' . $slugWp . '”'
                . ($faraWp ? '' : ' (are alt wp_post_id: ' . $potrivire['wp_post_id'] . ')');
            if ($optiuni['adopta-dupa-slug'] && $faraWp) {
                $existent = $potrivire;
                $adoptata = true;
            }
        }
    }
    if ($existent !== null && $existent['deleted_at'] !== null) {
        $numarSarite++;
        $inCos[] = $descriere . ' (#' . $existent['id'] . ')';
        echo "în coș     {$descriere} — sărită (n-o scoate importul din coș)\n";
        continue;
    }
    if ($existent !== null && !$adoptata && $existent['editata'] && !$optiuni['suprascrie']) {
        $numarSarite++;
        $editate[] = $descriere . ' (#' . $existent['id'] . ')';
        echo "editată    {$descriere} — sărită (editată în admin după import; --suprascrie o rescrie)\n";
        continue;
    }

    $slug = $slugWp;
    if ($slug === '') {
        // Draft-urile din WordPress n-au încă `post_name`; îl facem din titlu.
        $slug = Slug::make($titlu, 'articol-' . $wpId);
    }
    if ($existent === null) {
        $slug = Slug::unique($slug, static fn (string $s): bool => slugLuat($db, $s), 'articol-' . $wpId, 255);
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
            $gazda = strtolower(trim((string) parse_url(html_entity_decode($adresa, ENT_QUOTES, 'UTF-8'), PHP_URL_HOST), '[]'));
            if ($gazda === '' || !isset($gazdeProprii[$gazda]) || !WordPressContent::esteImagine($adresa)) {
                $imaginiExterne[$adresa] = true;
                continue;
            }
            if ($optiuni['dry-run']) {
                $imaginiAduse[$adresa] = caleLocalaPentru($adresa);
                continue;
            }
            $rezultat = descarcaImaginea($adresa, $radacinaUploads, $gazdeProprii, $adreseLocale);
            if ($rezultat['adresa'] === null) {
                $imaginiEsuate[$adresa] = $rezultat['motiv'];
                continue;
            }
            $imaginiAduse[$adresa] = $rezultat['adresa'];
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

    $eticheta = ($adoptata ? 'preiau     ' : ($existent !== null ? 'actualizez ' : 'adaug      '))
        . $descriere . ((bool) $postare['is_published'] ? '' : ' (draft)')
        . ($existent === null && $slug !== $slugWp && $slugWp !== '' ? " → slug „{$slug}”" : '');
    echo $eticheta . "\n";

    if ($optiuni['dry-run']) {
        $existent !== null ? $numarActualizate++ : $numarAdaugate++;
        continue;
    }

    try {
        $db->beginTransaction();
        // Același moment în created_at și updated_at: o editare ulterioară din admin mută doar updated_at.
        $acum = (string) $db->query('SELECT NOW()')->fetchColumn();

        if ($existent !== null) {
            $postId = (int) $existent['id'];
            // Rândul e blocat până la commit: o mutare în coș din admin între citire și scriere nu e suprascrisă.
            $blocat = $db->prepare('SELECT 1 FROM blog_posts WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
            $blocat->execute(['id' => $postId]);
            if ($blocat->fetchColumn() === false) {
                throw new RuntimeException('postarea #' . $postId . ' a fost ștearsă sau mutată în coș între timp');
            }
            $stmt = $db->prepare(
                'UPDATE blog_posts
                 SET title = :title, excerpt = :excerpt, content = :content,
                     references_html = :references_html, reading_minutes = :reading_minutes,
                     published_at = :published_at, is_published = :is_published,
                     featured_image_url = :featured_image_url, category = :category,
                     category_id = :category_id, author_id = :author_id,
                     wp_post_id = :wp_post_id, created_at = :acum, updated_at = :acum2
                 WHERE id = :id AND deleted_at IS NULL'
            );
        } else {
            $stmt = $db->prepare(
                'INSERT INTO blog_posts
                    (title, slug, excerpt, content, references_html, reading_minutes, published_at,
                     is_published, featured_image_url, category, category_id, author_id, wp_post_id, created_at, updated_at)
                 VALUES
                    (:title, :slug, :excerpt, :content, :references_html, :reading_minutes, :published_at,
                     :is_published, :featured_image_url, :category, :category_id, :author_id, :wp_post_id, :acum, :acum2)'
            );
        }

        $idCategorii = [];
        foreach ((array) $postare['categories'] as $numeCategorie) {
            $idCategorie = idTermen($db, 'blog_categories', (string) $numeCategorie);
            if ($idCategorie > 0) {
                $idCategorii[] = $idCategorie;
            }
        }

        $parametri = $randuri;
        // `category` (numele) și `category_id` rămân pe prima categorie: așa
        // le folosesc listările vechi, care nu știu de tabela de legătură.
        $primaCategorie = mb_substr(trim((string) ($postare['categories'][0] ?? '')), 0, 150);
        $parametri['category'] = $primaCategorie !== '' ? $primaCategorie : null;
        $parametri['category_id'] = $idCategorii[0] ?? null;
        $parametri['author_id'] = idTermen($db, 'blog_authors', (string) $postare['author']) ?: null;
        $parametri['acum'] = $acum;
        $parametri['acum2'] = $acum;
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
            $idEticheta = idTermen($db, 'blog_tags', (string) $numeEticheta);
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
        // Termenii creați în tranzacția anulată nu mai există: memoria lor se golește.
        TermeniImport::uita();
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
if (TermeniImport::$esecuri > 0) {
    printf("  erori la categorii, etichete, autori, legături sau SEO: %d (postările lor n-au fost scrise, mai sus)\n", TermeniImport::$esecuri);
}

if ($potriviriSlug !== []) {
    printf("\n  %d postări din export au același slug ca o postare din CMS:\n", count($potriviriSlug));
    foreach ($potriviriSlug as $linie) {
        echo '    ' . $linie . "\n";
    }
    echo $optiuni['adopta-dupa-slug']
        ? "  (--adopta-dupa-slug: cele fără wp_post_id sunt preluate; restul se adaugă ca postări noi, cu alt slug)\n"
        : "  (se adaugă ca postări noi, cu alt slug; --adopta-dupa-slug le preia pe cele din CMS fără wp_post_id)\n";
}
if ($inCos !== []) {
    printf("\n  %d postări sunt în coș la noi și au fost sărite (scoate-le din coș în admin dacă le vrei aduse):\n", count($inCos));
    foreach ($inCos as $linie) {
        echo '    ' . $linie . "\n";
    }
}
if ($editate !== []) {
    printf("\n  %d postări au fost editate în admin după import și au fost sărite (--suprascrie le rescrie):\n", count($editate));
    foreach ($editate as $linie) {
        echo '    ' . $linie . "\n";
    }
}
if ($imaginiExterne !== []) {
    printf("\n  %d imagini sunt pe site-uri străine (sau nu sunt jpg/png/webp/gif/avif) și au rămas neatinse:\n", count($imaginiExterne));
    foreach (array_keys($imaginiExterne) as $adresa) {
        echo '    ' . mb_substr($adresa, 0, 110) . "\n";
    }
    echo "  (dacă vrei aduse și astea, rulează cu --extra-host=domeniul.ro)\n";
}
if ($imaginiEsuate !== []) {
    printf("\n  %d imagini n-au putut fi descărcate:\n", count($imaginiEsuate));
    foreach ($imaginiEsuate as $adresa => $motiv) {
        echo '    ' . mb_substr((string) $adresa, 0, 110) . ' — ' . $motiv . "\n";
    }
}

// ── Fișierul sursă (G-m9): un export WXR are emailurile autorilor; nu rămâne pe server după import, dar se șterge doar
// după un import complet și curat al unui export adevărat din storage/import (revizuirea WP-23).
$motive = [];
if ($optiuni['dry-run']) {
    $motive[] = 'probă (--dry-run)';
}
if ($optiuni['pastreaza-sursa']) {
    $motive[] = '--pastreaza-sursa';
}
if (!$esteWxr) {
    $motive[] = 'nu e un export WordPress (lipsește <wp:wxr_version>)';
}
if ($totalInExport === 0) {
    $motive[] = 'n-am găsit nicio postare de tipul „' . $optiuni['post-type'] . '”';
}
if ($optiuni['limit'] > 0) {
    $motive[] = '--limit: n-au fost aduse toate postările';
}
if ($optiuni['only-published']) {
    $motive[] = '--only-published: ciornele n-au fost aduse';
}
if ($imaginiEsuate !== []) {
    $motive[] = count($imaginiEsuate) . ' imagini n-au putut fi descărcate';
}
if ($numarErori > 0) {
    $motive[] = $numarErori . ' postări n-au putut fi scrise';
}
if ($editate !== []) {
    $motive[] = count($editate) . ' postări editate au fost sărite (pentru o reluare cu --suprascrie)';
}
$dosarImport = realpath(Paths::stocare('import'));
$caleReala = realpath($caleFisier);
if ($dosarImport === false || $caleReala === false || !str_starts_with($caleReala, rtrim($dosarImport, '/') . '/')) {
    $motive[] = 'nu e în ' . Paths::stocare('import') . ' (scriptul șterge doar de acolo)';
}

$codIesire = $numarErori > 0 ? 1 : 0;
if ($motive !== []) {
    echo "\nFișierul sursă a rămas: " . implode('; ', $motive) . ".\n";
    if (!$optiuni['dry-run']) {
        echo "Șterge-l după ultima rulare: are emailurile autorilor.\n";
    }
} elseif (@unlink((string) $caleReala)) {
    echo "\nFișierul sursă a fost șters ({$caleFisier}).\n";
} else {
    fwrite(STDERR, "\nFișierul sursă NU a putut fi șters ({$caleFisier}): șterge-l de mână, are emailurile autorilor.\n");
    $codIesire = 1;
}
exit($codIesire);

// ── Ajutoare ────────────────────────────────────────────────────────────────

/** „exemplu.ro:8080” → „exemplu.ro”, „[::1]:8080” → „::1”; un IPv6 fără paranteze rămâne întreg. */
function gazdaFaraPort(string $valoare): string
{
    $valoare = strtolower(trim($valoare));
    if (str_starts_with($valoare, '[')) {
        return (string) strstr(substr($valoare, 1) . ']', ']', true);
    }

    return substr_count($valoare, ':') === 1 ? (string) strstr($valoare, ':', true) : $valoare;
}

/**
 * Postarea adusă deja din WordPress (wp_post_id), cu starea ei.
 *
 * @return array{id: int, wp_post_id: ?int, deleted_at: ?string, editata: bool}|null
 */
function postareDupaWpId(PDO $db, int $wpId): ?array
{
    if ($wpId <= 0) {
        return null;
    }
    $stmt = $db->prepare('SELECT id, wp_post_id, deleted_at, (updated_at > created_at) AS editata
        FROM blog_posts WHERE wp_post_id = :wp ORDER BY id LIMIT 1');
    $stmt->execute(['wp' => $wpId]);

    return randPostare($stmt->fetch(PDO::FETCH_ASSOC));
}

/**
 * Postarea de la noi cu același slug (oricare: din CMS sau adusă din alt export).
 *
 * @return array{id: int, wp_post_id: ?int, deleted_at: ?string, editata: bool}|null
 */
function postareDupaSlug(PDO $db, string $slug): ?array
{
    $stmt = $db->prepare('SELECT id, wp_post_id, deleted_at, (updated_at > created_at) AS editata
        FROM blog_posts WHERE slug = :slug LIMIT 1');
    $stmt->execute(['slug' => $slug]);

    return randPostare($stmt->fetch(PDO::FETCH_ASSOC));
}

/** @return array{id: int, wp_post_id: ?int, deleted_at: ?string, editata: bool}|null */
function randPostare(mixed $rand): ?array
{
    if (!is_array($rand)) {
        return null;
    }

    return [
        'id' => (int) $rand['id'],
        'wp_post_id' => $rand['wp_post_id'] !== null ? (int) $rand['wp_post_id'] : null,
        'deleted_at' => $rand['deleted_at'] !== null ? (string) $rand['deleted_at'] : null,
        'editata' => (int) ($rand['editata'] ?? 0) === 1,
    ];
}

function slugLuat(PDO $db, string $slug): bool
{
    $stmt = $db->prepare('SELECT 1 FROM blog_posts WHERE slug = :slug LIMIT 1');
    $stmt->execute(['slug' => $slug]);

    return $stmt->fetchColumn() !== false;
}

/**
 * Categoria, eticheta sau autorul cu numele dat; îl creează dacă nu există (în tranzacția postării). O eroare se numără și
 * se aruncă mai departe: postarea se anulează și memoria termenilor se golește (TermeniImport::uita).
 */
function idTermen(PDO $db, string $tabela, string $nume): int
{
    // Lungimile coloanelor (blog/0017, blog/0033): numele și slug-ul.
    [$lungimeNume, $lungimeSlug, $rezerva, $coloaneExtra] = match ($tabela) {
        'blog_categories' => [150, 170, 'categorie', ['sort_order' => 100]],
        'blog_tags' => [150, 170, 'eticheta', []],
        'blog_authors' => [190, 190, 'autor', ['is_active' => 1]],
    };
    $nume = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $nume)), 0, $lungimeNume);
    if ($nume === '') {
        return 0;
    }
    if (isset(TermeniImport::$memorie[$tabela][$nume])) {
        return TermeniImport::$memorie[$tabela][$nume];
    }

    try {
        $stmt = $db->prepare("SELECT id FROM {$tabela} WHERE name = :name LIMIT 1");
        $stmt->execute(['name' => $nume]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id === 0) {
            $slug = Slug::unique(
                $nume,
                static function (string $s) use ($db, $tabela): bool {
                    $q = $db->prepare("SELECT 1 FROM {$tabela} WHERE slug = :slug LIMIT 1");
                    $q->execute(['slug' => $s]);

                    return $q->fetchColumn() !== false;
                },
                $rezerva,
                $lungimeSlug
            );
            $coloane = array_merge(['name' => $nume, 'slug' => $slug], $coloaneExtra);
            $ins = $db->prepare("INSERT INTO {$tabela} (" . implode(', ', array_keys($coloane)) . ') VALUES (:'
                . implode(', :', array_keys($coloane)) . ')');
            $ins->execute($coloane);
            $id = (int) $db->lastInsertId();
        }
        if ($id <= 0) {
            throw new RuntimeException("{$tabela}: „{$nume}” n-a primit un id");
        }

        return TermeniImport::$memorie[$tabela][$nume] = $id;
    } catch (Throwable $e) {
        TermeniImport::$esecuri++;
        throw new RuntimeException("{$tabela} „{$nume}”: " . $e->getMessage(), 0, $e);
    }
}

/**
 * Rescrie legăturile many-to-many ale unei postări. O eroare se numără și anulează postarea.
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
    } catch (Throwable $e) {
        TermeniImport::$esecuri++;
        throw new RuntimeException("{$tabela}: " . $e->getMessage(), 0, $e);
    }
}

/** Titlul și descrierea SEO ale postării. O eroare se numără și anulează postarea. */
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
    } catch (Throwable $e) {
        TermeniImport::$esecuri++;
        throw new RuntimeException('seo_pages: ' . $e->getMessage(), 0, $e);
    }
}

/**
 * Adresa publică pe care o va avea imaginea la noi, păstrând anul și luna din
 * WordPress ca să nu se calce fișierele cu același nume.
 */
function caleLocalaPentru(string $adresa): string
{
    $cale = (string) parse_url(html_entity_decode($adresa, ENT_QUOTES, 'UTF-8'), PHP_URL_PATH);
    $fisier = basename($cale);
    $fisier = (string) preg_replace('/[^A-Za-z0-9._-]/', '-', rawurldecode($fisier));
    $fisier = ltrim($fisier, '.');
    if ($fisier === '' || !str_contains($fisier, '.')) {
        $fisier = 'imagine-' . substr(md5($adresa), 0, 10) . '.jpg';
    }

    $subfolder = preg_match('#/(\d{4})/(\d{2})/#', $cale, $m) === 1 ? $m[1] . '/' . $m[2] : 'import';

    return '/uploads/blog/' . $subfolder . '/' . $fisier;
}

/**
 * Descarcă imaginea în uploads/blog (App\Support\Paths::uploads) și întoarce adresa ei publică. Un fișier cu același
 * nume e refolosit doar dacă are exact același conținut; altfel imaginea primește numele liber următor („-2”).
 *
 * @param array<string, true> $gazdePermise
 * @return array{adresa: ?string, motiv: string}
 */
function descarcaImaginea(string $adresa, string $radacina, array $gazdePermise, bool $adreseLocale): array
{
    $descarcat = descarca($adresa, $gazdePermise, $adreseLocale);
    if ($descarcat['corp'] === null) {
        return ['adresa' => null, 'motiv' => $descarcat['motiv']];
    }
    $corp = $descarcat['corp'];

    // Tipul: antetul și conținutul, amândouă o imagine raster permisă (fără svg).
    $info = @getimagesizefromstring($corp);
    $tipContinut = is_array($info) ? strtolower((string) ($info['mime'] ?? '')) : '';
    if (!isset(IMPORT_IMAGINE_TIPURI[$descarcat['tip']]) || !isset(IMPORT_IMAGINE_TIPURI[$tipContinut])) {
        return ['adresa' => null, 'motiv' => 'nu e o imagine jpeg/png/webp/gif/avif (Content-Type: '
            . ($descarcat['tip'] !== '' ? $descarcat['tip'] : '—') . ', conținut: ' . ($tipContinut !== '' ? $tipContinut : 'necunoscut') . ')'];
    }

    $adresaPublica = caleLocalaPentru($adresa);
    $subcale = substr($adresaPublica, strlen('/uploads/blog'));
    $folder = $radacina . dirname($subcale);
    $numeFisier = pathinfo($subcale, PATHINFO_FILENAME);
    $extensie = strtolower(pathinfo($subcale, PATHINFO_EXTENSION));
    if (!in_array($extensie, IMPORT_IMAGINE_TIPURI[$tipContinut], true)) {
        // Extensia urmează conținutul: un .jpg care e de fapt png se salvează .png.
        $extensie = IMPORT_IMAGINE_TIPURI[$tipContinut][0];
    }
    if (!is_dir($folder) && !@mkdir($folder, 0775, true) && !is_dir($folder)) {
        return ['adresa' => null, 'motiv' => 'dosarul ' . $folder . ' nu poate fi făcut'];
    }

    $amprenta = hash('sha256', $corp);
    for ($i = 1; $i <= 100; $i++) {
        $nume = $numeFisier . ($i === 1 ? '' : '-' . $i) . '.' . $extensie;
        $tinta = $folder . '/' . $nume;
        $public = '/uploads/blog' . dirname($subcale) . '/' . $nume;
        if (file_exists($tinta)) {
            if (is_file($tinta) && hash_file('sha256', $tinta) === $amprenta) {
                return ['adresa' => $public, 'motiv' => ''];
            }
            continue;
        }
        $f = @fopen($tinta, 'xb');
        if ($f === false) {
            continue;
        }
        $scris = fwrite($f, $corp);
        fclose($f);
        if ($scris !== strlen($corp)) {
            @unlink($tinta);
            return ['adresa' => null, 'motiv' => 'fișierul ' . $tinta . ' nu a putut fi scris'];
        }
        @chmod($tinta, 0664);

        return ['adresa' => $public, 'motiv' => ''];
    }

    return ['adresa' => null, 'motiv' => 'prea multe fișiere diferite cu numele ' . $numeFisier . '.' . $extensie];
}

/**
 * Descarcă o imagine: doar http/https, doar de pe gazdele permise (verificat și după fiecare redirecționare, urmărite de
 * mână), doar spre o adresă IP publică (fixată cu CURLOPT_RESOLVE, fără proxy, ca DNS-ul să nu se schimbe între
 * verificare și descărcare), cel mult IMPORT_IMAGINE_MAX_OCTETI.
 *
 * @param array<string, true> $gazdePermise
 * @return array{corp: ?string, tip: string, motiv: string}
 */
function descarca(string $adresa, array $gazdePermise, bool $adreseLocale): array
{
    if (!function_exists('curl_init')) {
        return ['corp' => null, 'tip' => '', 'motiv' => 'extensia curl lipsește'];
    }
    $adresa = html_entity_decode($adresa, ENT_QUOTES, 'UTF-8');

    for ($salt = 0; $salt <= 5; $salt++) {
        $parti = parse_url($adresa);
        $schema = strtolower((string) ($parti['scheme'] ?? ''));
        $gazda = strtolower(trim((string) ($parti['host'] ?? ''), '[]'));
        if (!in_array($schema, ['http', 'https'], true) || $gazda === '') {
            return ['corp' => null, 'tip' => '', 'motiv' => 'doar adrese http/https (' . mb_substr($adresa, 0, 80) . ')'];
        }
        if (!isset($gazdePermise[$gazda])) {
            return ['corp' => null, 'tip' => '', 'motiv' => "gazda {$gazda} nu e printre domeniile exportului (după redirecționare)"];
        }
        $ip = ipPublic($gazda, $adreseLocale);
        if ($ip === null) {
            return ['corp' => null, 'tip' => '', 'motiv' => "gazda {$gazda} nu se rezolvă la o adresă publică (privată, loopback, link-local sau rezervată)"];
        }
        $port = (int) ($parti['port'] ?? ($schema === 'https' ? 443 : 80));

        $corp = '';
        $preaMare = false;
        $ch = curl_init($adresa);
        if ($ch === false) {
            return ['corp' => null, 'tip' => '', 'motiv' => 'curl_init a eșuat'];
        }
        $optiuniCurl = [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_MAXFILESIZE => IMPORT_IMAGINE_MAX_OCTETI,
            CURLOPT_USERAGENT => 'andaxi-cms/import-wordpress',
            CURLOPT_WRITEFUNCTION => static function ($c, string $bucata) use (&$corp, &$preaMare): int {
                if (strlen($corp) + strlen($bucata) > IMPORT_IMAGINE_MAX_OCTETI) {
                    $preaMare = true;

                    return 0;
                }
                $corp .= $bucata;

                return strlen($bucata);
            },
        ];
        if (filter_var($gazda, FILTER_VALIDATE_IP) === false) {
            $optiuniCurl[CURLOPT_RESOLVE] = [$gazda . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)];
        }
        curl_setopt_array($ch, $optiuniCurl);
        $ok = curl_exec($ch);
        $cod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $tip = strtolower(trim(explode(';', (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
        $redirect = (string) (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
        $eroare = curl_error($ch);
        curl_close($ch);

        if ($preaMare || ($ok === false && str_contains(strtolower($eroare), 'maximum file size'))) {
            return ['corp' => null, 'tip' => $tip, 'motiv' => 'mai mare de ' . (IMPORT_IMAGINE_MAX_OCTETI >> 20) . ' MB'];
        }
        if ($ok === false) {
            return ['corp' => null, 'tip' => $tip, 'motiv' => 'curl: ' . $eroare];
        }
        if ($cod >= 300 && $cod < 400 && $redirect !== '') {
            $adresa = $redirect;
            continue;
        }
        if ($cod < 200 || $cod >= 300) {
            return ['corp' => null, 'tip' => $tip, 'motiv' => 'HTTP ' . $cod];
        }

        return ['corp' => $corp, 'tip' => $tip, 'motiv' => ''];
    }

    return ['corp' => null, 'tip' => '', 'motiv' => 'prea multe redirecționări'];
}

/**
 * Adresa IP la care se descarcă de pe gazdă: toate adresele ei trebuie să fie publice (nu private, loopback, link-local,
 * rezervate sau CGNAT); cu $adreseLocale (CMS_DEV_IMPORT_ADRESE_LOCALE=1, doar teste) e permis și loopback-ul.
 */
function ipPublic(string $gazda, bool $adreseLocale): ?string
{
    if (filter_var($gazda, FILTER_VALIDATE_IP) !== false) {
        $ipuri = [$gazda];
    } else {
        $ipuri = [];
        foreach (@gethostbynamel($gazda) ?: [] as $ip) {
            $ipuri[] = (string) $ip;
        }
        foreach (@dns_get_record($gazda, DNS_AAAA) ?: [] as $inregistrare) {
            if (isset($inregistrare['ipv6'])) {
                $ipuri[] = (string) $inregistrare['ipv6'];
            }
        }
        $ipuri = array_values(array_unique($ipuri));
    }
    if ($ipuri === []) {
        return null;
    }
    foreach ($ipuri as $ip) {
        if (!ipPermis($ip, $adreseLocale)) {
            return null;
        }
    }

    return $ipuri[0];
}

function ipPermis(string $ip, bool $adreseLocale): bool
{
    // IPv4 scris ca IPv6 (::ffff:127.0.0.1): se verifică partea IPv4.
    if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m) === 1) {
        $ip = $m[1];
    }
    if ($adreseLocale && ($ip === '::1' || str_starts_with($ip, '127.'))) {
        return true;
    }
    $flaguri = defined('FILTER_FLAG_GLOBAL_RANGE')
        ? FILTER_FLAG_GLOBAL_RANGE
        : FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    if (filter_var($ip, FILTER_VALIDATE_IP, $flaguri) === false) {
        return false;
    }
    // CGNAT (100.64.0.0/10) și link-local IPv6 (fe80::/10), pentru PHP fără FILTER_FLAG_GLOBAL_RANGE.
    if (preg_match('/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', $ip) === 1 || preg_match('/^fe[89ab]/i', $ip) === 1) {
        return false;
    }

    return true;
}
