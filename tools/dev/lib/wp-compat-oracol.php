<?php

declare(strict_types=1);

/**
 * tools/dev/test-wp-compat.php, cu --oracol și --redirecturi: stratul de adrese WordPress (App\Support\WpCompat) pe datele
 * reale ale unui site, luate din afara repo-ului (nimic de aici nu ține de un client: fișierele se găsesc după tipar).
 *
 *  1. Harta ID-urilor din exporturile REST ale site-ului live, fără dump-ul bazei (--rest: unul sau mai multe dosare,
 *     despărțite prin virgulă; implicit `<inventar>/cache`, unde `<inventar>` e dosarul de trei niveluri deasupra
 *     oracolului; un ID aflat în mai multe fișiere se ia o dată): `wp/v2/pages`, `wp/v2/posts`, `wp/v2/project` (ID-ul,
 *     slug-ul, adresa, starea), `wp/v2/search` (postările publicate care lipsesc din ele; una aflată și acolo trebuie să
 *     aibă același tip și aceeași adresă), `wp/v2/media` (atașamentele: slug-ul, adresa, fișierul, părintele; slug-ul
 *     primei pagini, fără `wp/v2/pages`, iese din adresele atașamentelor ei), `wp/v2/users` (autorii), `wp/v2/categories`
 *     sau, fără el, arhivele categoriilor din cache (clasa `category-<id>` a paginii și canonicalul ei). Comparată cu harta
 *     din oracol (`spec.slugs_by_id`): orice deosebire e o diferență.
 *  2. O bază nouă (o pagină neutră, „Pagina <id>”, pentru fiecare postare publicată; pagina 404), harta scrisă cu
 *     Harta::scrie, un pachet cu wp-compat.json-ul standard (fără endpointuri compatibile) și un server ca la secțiunea C.
 *  3. Fiecare rând al oracolului ghicirii (`verified` și `predicted_prefix_map`) și al tabelelor de redirecționări
 *     (--redirecturi: unul sau mai multe fișiere, despărțite prin virgulă, cu rânduri `url`, `first_status`,
 *     `first_location`), cerut cu gazda site-ului: codul și Location trebuie să fie ale WordPress-ului.
 *  4. Cu --antete (antetele live, un obiect pe etichete: `url`, `final_status`, `headers`): fiecare adresă la care
 *     WordPress a răspuns 404 trebuie să aibă aici codul 404 și aceleași `Cache-Control` și `Expires` (nocache_headers).
 *
 * Nu sunt diferențe, ci se trec separat:
 *  - DECLARAT: resturile tehnice cu 410 (wp-compat.json → resturi_410; diferența acceptată, scrisă proprietarului);
 *  - PASUL-9: ce servesc răspunsurile înghețate și căutarea (fluxurile, arhivele, `/page/N/`, sitemap-urile AIOSEO,
 *    `/sitemap.rss`, `?s=`, `/search/`), care nu există încă în acest pas;
 *  - STATIC: fișierele din arborii copiați (`/wp-content/…`) și răspunsurile live date de serverul web fără PHP (fără
 *    `X-Powered-By`), servite înaintea PHP-ului;
 *  - GAZDA: primul salt de pe http (http → https îl face găzduirea); se compară saltul de după.
 *
 * Raportul (fiecare rând): --raport, implicit $CMS_DEV_DIR/test-wp-compat-oracol.txt. Variabile din test-wp-compat.php:
 * $optiuni, $scrie, $cerere, $pornesteServer, $bazaNoua, $conectare, $lucru, $dosarDev, $opresteServerele. Pune
 * $rezultatOracol (0 doar cu 0 diferențe).
 */

use App\Support\DateSite;
use App\Support\WpCompat;
use App\Support\WpCompat\Ghicire;
use App\Support\WpCompat\Harta;

$rezultatOracol = 1;
$fisierOracol = (string) $optiuni['oracol'];
$oracol = json_decode((string) @file_get_contents($fisierOracol), true);
if (!is_array($oracol) || !is_array($oracol['verified'] ?? null)) {
    $scrie('EROARE: oracolul (' . $fisierOracol . ') nu se poate citi');
    return;
}
/** @var array<string, list<array<string, mixed>>> $tabeleRedirecturi fișierul → rândurile lui */
$tabeleRedirecturi = [];
foreach (array_values(array_filter(array_map('trim', explode(',', (string) $optiuni['redirecturi'])), static fn ($f) => $f !== '')) as $fisierRedirecturi) {
    $redirecturi = json_decode((string) @file_get_contents($fisierRedirecturi), true);
    if (!is_array($redirecturi) || !array_is_list($redirecturi)) {
        $scrie('EROARE: tabelul de redirecționări ' . $fisierRedirecturi . ' nu se poate citi (o listă JSON de rânduri)');
        return;
    }
    $tabeleRedirecturi[$fisierRedirecturi] = $redirecturi;
}
$anteteLive = null;
if ($optiuni['antete'] !== null) {
    $anteteLive = json_decode((string) @file_get_contents((string) $optiuni['antete']), true);
    if (!is_array($anteteLive) || ($anteteLive !== [] && array_is_list($anteteLive))) {
        $scrie('EROARE: antetele live (' . $optiuni['antete'] . ') nu se pot citi (un obiect JSON pe etichete)');
        return;
    }
}
$dosareRest = array_values(array_filter(array_map(static fn (string $d): string => rtrim(trim($d), '/'),
    explode(',', (string) ($optiuni['rest'] ?? (dirname((string) realpath($fisierOracol), 3) . '/cache')))), static fn (string $d): bool => $d !== ''));
$fisierRaportOracol = (string) ($optiuni['raport'] ?? ($dosarDev . '/test-wp-compat-oracol.txt'));
@file_put_contents($fisierRaportOracol, '');
$rand = static function (string $linie) use ($fisierRaportOracol): void {
    file_put_contents($fisierRaportOracol, $linie . "\n", FILE_APPEND);
};
$scrie('Test stratul de adrese WordPress pe oracolul real — ' . date('Y-m-d H:i:s'));
$scrie('  ..    exporturile REST: ' . implode(', ', $dosareRest) . '; raportul: ' . $fisierRaportOracol);

// ---------------------------------------------------------------------------------------------------------------------
// 1. Harta ID-urilor din exporturile REST
// ---------------------------------------------------------------------------------------------------------------------
/** @var array<string, int> $surseRest fișierul citit → elementele lui */
$surseRest = [];
/**
 * Elementele JSON (după id; la același id, primul fișier citit) din fișierele care se potrivesc cu oricare tipar, în
 * oricare dosar --rest, fără antetele salvate lângă ele. Tiparele cu „*wp-json_wp_v2_…” sunt numele din cache-ul
 * inventarului; celelalte, numele simple ale unui export (pages.json, media-2.json…).
 *
 * @param list<string> $tipare
 * @return array<int, array<string, mixed>>
 */
$citesteRest = static function (array $tipare) use ($dosareRest, &$surseRest): array {
    $rez = [];
    foreach ($dosareRest as $dosar) {
        foreach ($tipare as $tipar) {
            $fisiere = glob($dosar . '/' . $tipar) ?: [];
            sort($fisiere);
            foreach ($fisiere as $f) {
                if (str_ends_with($f, '.headers.txt') || str_ends_with($f, '.body') || isset($surseRest[$f])) {
                    continue;
                }
                $date = json_decode((string) file_get_contents($f), true);
                if (!is_array($date) || !array_is_list($date)) {
                    continue;
                }
                $surseRest[$f] = count($date);
                foreach ($date as $e) {
                    if (is_array($e) && isset($e['id']) && !isset($rez[(int) $e['id']])) {
                        $rez[(int) $e['id']] = $e;
                    }
                }
            }
        }
    }
    return $rez;
};
$cale = static fn (string $url): string => (string) (parse_url($url, PHP_URL_PATH) ?: '/');
$ultimSegment = static function (string $cale): string {
    $parti = array_values(array_filter(explode('/', $cale), static fn ($s) => $s !== ''));
    return (string) ($parti[count($parti) - 1] ?? '');
};
$pagini = $citesteRest(['*wp-json_wp_v2_pages*.json', 'pages.json', 'pages-*.json']);
$articole = $citesteRest(['*wp-json_wp_v2_posts*.json', 'posts.json', 'posts-*.json']);
$proiecte = $citesteRest(['*wp-json_wp_v2_project.json', 'project.json', 'projects.json']);
$cautare = $citesteRest(['*wp-json_wp_v2_search*.json']);
$media = $citesteRest(['*wp-json_wp_v2_media*.json', 'media.json', 'media-*.json']);
$autori = $citesteRest(['*wp-json_wp_v2_users*', 'users.json']);
$categorii = $citesteRest(['*wp-json_wp_v2_categories*.json', 'categories.json']);
$gazda = '';
foreach (array_merge(array_column($pagini, 'link'), array_column($articole, 'link'), array_column($cautare, 'url')) as $adresa) {
    $gazda = (string) parse_url((string) $adresa, PHP_URL_HOST);
    if ($gazda !== '') {
        break;
    }
}
if ($gazda === '' || ($pagini === [] && $cautare === [])) {
    $scrie('EROARE: lipsesc exporturile wp/v2/pages (sau wp/v2/search) în ' . implode(', ', $dosareRest));
    return;
}
$harta = [];
// Postările publicate: din wp/v2/pages, wp/v2/posts și wp/v2/project (cu slug-ul lor), apoi din wp/v2/search cele care lipsesc;
// un ID din ambele surse trebuie să aibă în amândouă același tip și aceeași adresă.
$postari = [];
$nepotriviriRest = [];
foreach ([['page', $pagini], ['post', $articole], ['project', $proiecte]] as [$tip, $lista]) {
    foreach ($lista as $id => $e) {
        if (($e['status'] ?? 'publish') === 'publish' && isset($e['link'])) {
            $postari[$id] = ['tip' => $tip, 'slug' => isset($e['slug']) ? (string) $e['slug'] : null, 'cale' => $cale((string) $e['link'])];
        }
    }
}
foreach ($cautare as $id => $e) {
    if (($e['type'] ?? '') !== 'post' || !in_array((string) ($e['subtype'] ?? ''), Harta::TIPURI_GHICITE, true)) {
        continue;
    }
    $caleCautare = $cale((string) ($e['url'] ?? ''));
    if (isset($postari[$id])) {
        if ($postari[$id]['tip'] !== (string) $e['subtype'] || $postari[$id]['cale'] !== $caleCautare) {
            $nepotriviriRest[] = $id . ': ' . $postari[$id]['tip'] . ' ' . $postari[$id]['cale'] . ' în export, ' . $e['subtype'] . ' ' . $caleCautare . ' în wp/v2/search';
        }
        continue;
    }
    $postari[$id] = ['tip' => (string) $e['subtype'], 'slug' => null, 'cale' => $caleCautare];
}
ksort($postari);
// Slug-ul primei pagini (adresa ei e „/”), când exportul nu-l are: din adresele atașamentelor ei (_get_page_link păstrează
// calea paginii).
$slugDinAtasamente = [];
foreach ($media as $e) {
    $parinte = (int) ($e['post'] ?? 0);
    if ($parinte > 0) {
        $parti = array_values(array_filter(explode('/', $cale((string) ($e['link'] ?? ''))), static fn ($s) => $s !== ''));
        if (count($parti) >= 2) {
            $slugDinAtasamente[$parinte] = $parti[count($parti) - 2];
        }
    }
}
foreach ($postari as $id => $p) {
    $slug = $p['slug'] ?? ($p['cale'] === '/' ? (string) ($slugDinAtasamente[$id] ?? '') : $ultimSegment($p['cale']));
    $harta[] = ['wp_id' => $id, 'tip' => $p['tip'], 'slug_wp' => strtolower($slug), 'cale' => $p['cale'], 'stare' => 'publish', 'ordine' => 0, 'fisier' => ''];
}
foreach ($media as $e) {
    $harta[] = ['wp_id' => (int) $e['id'], 'tip' => 'attachment', 'slug_wp' => (string) ($e['slug'] ?? ''), 'cale' => $cale((string) ($e['link'] ?? '')),
        'stare' => 'inherit', 'ordine' => 0, 'fisier' => $cale((string) ($e['source_url'] ?? ''))];
}
foreach ($autori as $e) {
    if (isset($e['id'], $e['slug'], $e['link'])) {
        $harta[] = ['wp_id' => (int) $e['id'], 'tip' => 'author', 'slug_wp' => (string) $e['slug'], 'cale' => $cale((string) $e['link']), 'stare' => 'publish',
            'ordine' => 0, 'fisier' => ''];
    }
}
$sursaCategorii = 'wp/v2/categories';
foreach ($categorii as $e) {
    if (isset($e['id'], $e['slug'], $e['link'])) {
        $harta[] = ['wp_id' => (int) $e['id'], 'tip' => 'category', 'slug_wp' => (string) $e['slug'], 'cale' => $cale((string) $e['link']), 'stare' => 'publish',
            'ordine' => 0, 'fisier' => ''];
    }
}
if ($categorii === []) {
    // Fără exportul categoriilor: arhivele lor din cache (clasa `category-<id>` a paginii și canonicalul ei).
    $sursaCategorii = 'arhivele din cache';
    foreach ($dosareRest as $dosar) {
        foreach (glob($dosar . '/*_category_*.html') ?: [] as $f) {
            $html = (string) file_get_contents($f);
            if (preg_match('/<body[^>]*class="[^"]*\bcategory-([0-9]+)\b/', $html, $m) === 1
                && preg_match('/<link rel="canonical" href="([^"]+)"/', $html, $u) === 1) {
                $caleCategorie = $cale(html_entity_decode($u[1], ENT_QUOTES));
                if (preg_match('#/feed/?$#', $caleCategorie) !== 1) {
                    $harta[] = ['wp_id' => (int) $m[1], 'tip' => 'category', 'slug_wp' => $ultimSegment($caleCategorie), 'cale' => $caleCategorie,
                        'stare' => 'publish', 'ordine' => 0, 'fisier' => ''];
                }
            }
        }
    }
}
$harta = \App\Support\ImportWordPress\HartaWp::ordoneaza($harta);
$numarTip = array_count_values(array_column($harta, 'tip'));
ksort($numarTip);
$scrie('  ..    harta din REST (gazda ' . $gazda . '): ' . count($harta) . ' rânduri (' . http_build_query($numarTip, '', ', ') . '; categoriile din '
    . $sursaCategorii . '; ' . count($surseRest) . ' fișiere: ' . implode(', ', array_map(static fn (string $f, int $n): string => basename($f) . ' ' . $n,
    array_keys($surseRest), array_values($surseRest))) . ')');
// Harta din oracol (ID-urile din baza site-ului) trebuie să fie exact aceasta.
$diferenteHarta = $nepotriviriRest;
$dinOracol = [];
foreach ((array) ($oracol['spec']['slugs_by_id'] ?? []) as $e) {
    $dinOracol[(int) $e['id']] = $e;
}
$dinRest = [];
foreach ($harta as $r) {
    if (in_array($r['tip'], Harta::TIPURI_GHICITE, true)) {
        $dinRest[$r['wp_id']] = $r;
    }
}
foreach ($dinOracol as $id => $e) {
    $r = $dinRest[$id] ?? null;
    if ($r === null || $r['slug_wp'] !== (string) $e['slug'] || $r['cale'] !== (string) $e['permalink']) {
        $diferenteHarta[] = $id . ': oracolul ' . $e['slug'] . ' ' . $e['permalink'] . ', REST ' . ($r === null ? '-' : $r['slug_wp'] . ' ' . $r['cale']);
    }
}
foreach (array_diff(array_keys($dinRest), array_keys($dinOracol)) as $id) {
    $diferenteHarta[] = $id . ': doar în REST';
}
$rand('== Harta: ' . count($dinRest) . ' postări din REST, ' . count($dinOracol) . ' în oracol');
foreach ($diferenteHarta as $d) {
    $rand('  DIFERENȚĂ  ' . $d);
}
$scrie('  ' . ($diferenteHarta === [] ? 'OK  ' : 'EȘEC') . '  harta din REST = harta din oracol (' . count($dinOracol) . ' postări: ID, slug, adresă)'
    . ($diferenteHarta === [] ? '' : ': ' . count($diferenteHarta) . ' diferențe'));

// ---------------------------------------------------------------------------------------------------------------------
// 2. Baza, pachetul, serverul
// ---------------------------------------------------------------------------------------------------------------------
[$codInstalare, $iesireInstalare] = $bazaNoua('cms_wpc_oracol');
if ($codInstalare !== 0) {
    $scrie('EROARE: scripts/install.php: ' . trim($iesireInstalare));
    return;
}
$dbOracol = $conectare('cms_wpc_oracol');
$dbOracol->exec('DELETE FROM pages');
$legaturi = [];
$adauga = $dbOracol->prepare('INSERT INTO pages (title, slug, html_content, css_content, js_content, is_published) VALUES (?, ?, ?, "", "", 1)');
foreach ($harta as $r) {
    if (!in_array($r['tip'], Harta::TIPURI_GHICITE, true)) {
        continue;
    }
    $slug = implode('/', array_map('rawurldecode', array_values(array_filter(explode('/', $r['cale']), static fn ($s) => $s !== ''))));
    $adauga->execute(['Pagina ' . $r['wp_id'], $slug, '<p>Pagina ' . $r['wp_id'] . '</p>']);
    $legaturi[$r['wp_id']] = (int) $dbOracol->lastInsertId();
}
$adauga->execute(['Pagina negăsită', '404', '<p>Pagina negăsită</p>']);
Harta::scrie($dbOracol, $harta, $legaturi);
$pachetOracol = $lucru . '/pachet-oracol';
@mkdir($pachetOracol, 0770, true);
file_put_contents($pachetOracol . '/wp-compat.json', (string) json_encode([
    'slash_final' => true, 'majuscule' => '200', 'ghicire' => true, 'harta_id' => true, 'atasamente_la_fisier' => true, 'cautare_wp' => true,
    'sitemap_302' => ['/wp-sitemap.xml', '/sitemap_index.xml'],
    'resturi_410' => ['/wp-json/', '/xmlrpc.php', '/wp-login.php', '/wp-admin/', '/readme.html'],
    'antete_404' => 'wordpress',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($pachetOracol . '/redirecturi.json', '{"exacte": [], "prefixe": []}');
file_put_contents($pachetOracol . '/seo.json', '{}');
DateSite::reseteaza($pachetOracol);
WpCompat::reseteaza();
$portOracol = (int) (getenv('CMS_DEV_PORT') ?: 8701) + 23;
$bazaUrl = 'https://' . $gazda;
if (!$pornesteServer($portOracol, 'cms_wpc_oracol', $pachetOracol, $lucru . '/site-oracol', $bazaUrl)) {
    $scrie('EROARE: serverul nu pornește (vezi ' . $dosarDev . '/server-wpc-' . $portOracol . '.log)');
    return;
}

// ---------------------------------------------------------------------------------------------------------------------
// 3. Rândurile
// ---------------------------------------------------------------------------------------------------------------------
/** O cerere cu gazda dată; Location fără schema și gazda site-ului (ca în oracolul ghicirii). @return array{0: int, 1: ?string} */
$raspuns = static function (string $gazdaCerere, string $caleSiQuery) use ($cerere, $portOracol, $bazaUrl): array {
    $r = $cerere($portOracol, 'GET', $caleSiQuery === '' ? '/' : $caleSiQuery, ['Host: ' . $gazdaCerere]);
    $locatie = $r['antete']['location'] ?? null;
    return [$r['cod'], $locatie];
};
$faraGazda = static fn (?string $l): ?string => $l !== null && str_starts_with($l, $bazaUrl) ? (substr($l, strlen($bazaUrl)) ?: '/') : $l;
/** Ce servesc pașii următori (răspunsurile înghețate, căutarea) sau serverul web, nu acest pas; null = se compară. */
$clasa = static function (string $caleCerere, string $query): ?string {
    if (WpCompat::eRest($caleCerere)) {
        return 'DECLARAT';
    }
    if (preg_match('#^/(wp-content|wp-includes)/.+\.[A-Za-z0-9]+$#', $caleCerere) === 1) {
        return 'STATIC';
    }
    parse_str($query, $q);
    $seg = array_values(array_filter(explode('/', strtolower($caleCerere)), static fn ($s) => $s !== ''));
    $ultim = (string) ($seg[count($seg) - 1] ?? '');
    if (array_key_exists('s', $q) || in_array($seg[0] ?? '', ['feed', 'comments', 'category', 'tag', 'author', 'search', 'page'], true)
        || in_array($ultim, Ghicire::FLUXURI, true) || preg_match('/^[0-9]{4}$/', $seg[0] ?? '') === 1
        || preg_match('#(-sitemap\.xml|^/sitemap\.rss)$#', $caleCerere) === 1) {
        return 'PASUL-9';
    }
    return null;
};
$numar = ['OK' => 0, 'DIFERENȚĂ' => 0, 'DECLARAT' => 0, 'PASUL-9' => 0, 'STATIC' => 0, 'GAZDA' => 0];
$text = static fn (array $valori): string => implode(' ', array_map(static fn ($v): string => (string) $v, $valori));
$noteaza = static function (string $eticheta, string $cerut, array $asteptat, array $primit, ?string $clasaRand) use (&$numar, $rand, $text): void {
    $egal = $asteptat === $primit;
    $stare = $egal ? 'OK' : ($clasaRand ?? 'DIFERENȚĂ');
    if ($clasaRand === 'DECLARAT' && $primit[0] !== 410) {
        $stare = 'DIFERENȚĂ';
    }
    $numar[$stare]++;
    $rand(sprintf('  %-10s %-22s %s  live: %s  aici: %s', $stare, mb_substr($eticheta, 0, 22), $cerut, $text($asteptat), $text($primit)));
};

$rand('');
$rand('== Oracolul ghicirii: rândurile verificate pe site-ul live');
foreach ($oracol['verified'] as $r) {
    $caleCerere = (string) $r['path'];
    [$caleSimpla, $query] = array_pad(explode('?', $caleCerere, 2), 2, '');
    [$cod, $locatie] = $raspuns($gazda, $caleCerere);
    $noteaza((string) ($r['note'] ?? 'verificat'), $caleCerere, [(int) $r['status'], $r['location'] ?? null], [$cod, $faraGazda($locatie)], $clasa($caleSimpla, $query));
}
$rand('');
$rand('== Oracolul ghicirii: harta prefixelor (fiecare prefix al fiecărui slug → câștigătoarea, după ID)');
foreach ((array) ($oracol['predicted_prefix_map'] ?? []) as $prefix => $e) {
    // Prefixul care e chiar slug-ul întreg e adresa paginii înseși: 200, fără redirecționare (ca pe live, „page:…” din tabel).
    $cerut = '/' . $prefix . '/';
    $asteptat = $cerut === (string) $e['to'] ? [200, null] : [301, (string) $e['to']];
    [$cod, $locatie] = $raspuns($gazda, $cerut);
    $noteaza('prefix', $cerut, $asteptat, [$cod, $faraGazda($locatie)], null);
}
foreach ($tabeleRedirecturi as $fisierRedirecturi => $redirecturi) {
    $rand('');
    $rand('== Tabelul de redirecționări al site-ului live: ' . basename($fisierRedirecturi) . ' (' . count($redirecturi) . ' rânduri)');
    foreach ($redirecturi as $r) {
        $url = (string) $r['url'];
        $parti = parse_url($url);
        $schema = strtolower((string) ($parti['scheme'] ?? 'https'));
        $gazdaRand = strtolower((string) ($parti['host'] ?? $gazda));
        $caleRand = (string) ($parti['path'] ?? '/');
        $queryRand = (string) ($parti['query'] ?? '');
        $lant = array_values((array) ($r['chain'] ?? []));
        $asteptat = [(int) $r['first_status'], $r['first_location'] ?? null];
        if ($schema === 'http') {
            // Primul salt (http → https) îl face găzduirea, nu PHP-ul: se compară saltul de după, de pe https.
            $numar['GAZDA']++;
            $rand(sprintf('  %-10s %-22s %s  live: %s %s  (găzduirea)', 'GAZDA', mb_substr((string) $r['label'], 0, 22), $url, $asteptat[0], (string) $asteptat[1]));
            $urmator = (string) ($lant[0][1] ?? '');
            if ($urmator === '' || !isset($lant[1])) {
                continue;
            }
            $parti = parse_url($urmator);
            $gazdaRand = strtolower((string) ($parti['host'] ?? $gazda));
            $caleRand = (string) ($parti['path'] ?? '/');
            $queryRand = (string) ($parti['query'] ?? '');
            $asteptat = [(int) $lant[1][0], $lant[1][1] ?? null];
            $url = $urmator;
        }
        [$cod, $locatie] = $raspuns($gazdaRand, $caleRand . ($queryRand !== '' ? '?' . $queryRand : ''));
        $noteaza((string) $r['label'], $url, $asteptat, [$cod, $locatie], $clasa($caleRand, $queryRand));
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// 4. Antetele fiecărui 404 (--antete): codul, Cache-Control și Expires ale WordPress-ului (nocache_headers)
// ---------------------------------------------------------------------------------------------------------------------
$antete404 = 0;
if ($anteteLive !== null) {
    $rand('');
    $rand('== Antetele 404 ale site-ului live (codul, Cache-Control, Expires)');
    foreach ($anteteLive as $eticheta => $e) {
        if (!is_array($e) || (int) ($e['final_status'] ?? 0) !== 404 || !isset($e['url'])) {
            continue;
        }
        $antete404++;
        $parti = parse_url((string) $e['url']);
        $caleRand = (string) ($parti['path'] ?? '/');
        $queryRand = (string) ($parti['query'] ?? '');
        $live = array_change_key_case((array) ($e['headers'] ?? []), CASE_LOWER);
        $asteptat = [404, $live['cache-control'] ?? null, $live['expires'] ?? null];
        $r = $cerere($portOracol, 'GET', $caleRand . ($queryRand !== '' ? '?' . $queryRand : ''), ['Host: ' . strtolower((string) ($parti['host'] ?? $gazda))]);
        $primit = [$r['cod'], $r['antete']['cache-control'] ?? null, $r['antete']['expires'] ?? null];
        // Un 404 dat de serverul web, fără PHP (fără X-Powered-By): nu-l face acest strat.
        $clasaRand = $clasa($caleRand, $queryRand) ?? (!isset($live['x-powered-by']) ? 'STATIC' : null);
        $noteaza('antete:' . $eticheta, (string) $e['url'], $asteptat, $primit, $clasaRand);
    }
}
$opresteServerele();

$rand('');
$rand('Rezumat: ' . json_encode($numar, JSON_UNESCAPED_UNICODE) . '; harta: ' . count($diferenteHarta) . ' diferențe');
$diferente = $numar['DIFERENȚĂ'] + count($diferenteHarta);
$scrie('  ' . ($numar['DIFERENȚĂ'] === 0 ? 'OK  ' : 'EȘEC') . '  rândurile oracolului (ghicirea, ' . count($tabeleRedirecturi) . ' tabele de redirecționări'
    . ($anteteLive !== null ? ', antetele a ' . $antete404 . ' răspunsuri 404' : ', fără antetele 404: lipsește --antete') . '): '
    . $numar['OK'] . ' identice, ' . $numar['DIFERENȚĂ'] . ' diferențe; '
    . 'în afara pasului: ' . $numar['DECLARAT'] . ' declarate (410), ' . $numar['PASUL-9'] . ' la pasul 9 (răspunsurile înghețate, căutarea), '
    . $numar['STATIC'] . ' statice (servite fără PHP), ' . $numar['GAZDA'] . ' salturi ale găzduirii (comparat saltul de după)');
$scrie('');
$scrie('Rezultat oracol: ' . $diferente . ' diferențe.');
$rezultatOracol = $diferente === 0 ? 0 : 1;
