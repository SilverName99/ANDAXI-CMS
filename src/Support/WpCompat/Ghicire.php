<?php

declare(strict_types=1);

namespace App\Support\WpCompat;

use App\Support\AccesMembri;
use App\Support\Plugins;
use App\Support\Sitemap;
use App\Support\WpCompat;
use PDO;

/**
 * Ghicirea adresei greșite, ca WordPress (redirect_guess_404_permalink, nu strict), după rutele reale și înaintea paginii
 * 404 (SiteController::paginaNegasita, prin WpCompat::raspundeNegasit). Doar cu `"ghicire": true` în wp-compat.json.
 *
 * Regula (verificată pe oracolul ghicirii unui site real; adresele de aici sunt inventate):
 *  - o cale cu 1 sau 2 segmente; „numele” e ultimul segment (`/camere/` → camere, `/ro/camere/` → camere), decodat și
 *    curățat ca sanitize_title_for_query (litere mici, inclusiv `Ș` → `ș`; „.” → „-”; doar `%a-z0-9 _-`);
 *  - câștigă postarea publicată (pagină, articol, proiect; niciodată un atașament, o ciornă, un meniu sau un layout) al cărei
 *    slug începe cu numele; mai multe: cea cu ID-ul WordPress cel mai mic; paginile făcute în constructor după import vin
 *    după, în ordinea creării;
 *  - ținta e adresa ei (prima pagină: „/”); query string-ul îl păstrează apelantul, întocmai;
 *  - `/<nume>/<cifre>/` → `/<câștigătoare>/<cifre>/` (al doilea salt îl face pagina însăși: `/<pagină>/<cifre>/` →
 *    `/<pagină>/`); `/<nume>/page/<N>/` și `/<nume>/comment-page-<N>/` → adresa ei, fără număr;
 *  - pe cadrul capturat (`tema.json` → `cadru` = `capturat`) nu se ghicește deloc: 404 (VC-URL-024);
 *  - nu se ghicește: 3 sau mai multe segmente, fluxurile (`/<x>/feed/`), embed, trackback, arhivele (anul, bazele din
 *    `baze_fara_ghicire`), rădăcina fluxurilor (`/feed/`, `/rss/`…): pe acolo WordPress nu răspundea 404.
 */
final class Ghicire
{
    /** Fluxurile WordPress (`feed/(feed|rdf|rss|rss2|atom)`). */
    public const FLUXURI = ['feed', 'rdf', 'rss', 'rss2', 'atom'];

    /** Al doilea segment care face din cerere altă rută WordPress (fluxul postării, embed, trackback): fără ghicire. */
    public const SUFIXE_FARA_GHICIRE = ['feed', 'rdf', 'rss', 'rss2', 'atom', 'embed', 'trackback'];

    /**
     * Ținta pentru o cale pe care site-ul n-a găsit-o (calea cum a venit, percent-encoded, fără query), sau null (404).
     */
    public static function pentru(PDO $db, string $cale): ?string
    {
        // Pe cadrul capturat nu se ghicește deloc (VC-URL-024): o adresă greșită primește 404-ul capturat, oricare ar fi prefixul.
        if (!WpCompat::ghicire() || \App\Support\Tema::cadru() === 'capturat') {
            return null;
        }
        $segmente = array_values(array_filter(explode('/', $cale), static fn (string $s): bool => $s !== ''));
        $n = count($segmente);
        if ($n === 0 || $n > 3) {
            return null;
        }
        // O pagină care există, urmată de un număr de pagină (/pagina/2/): adresa ei, fără număr (redirect_canonical; paginile
        // de aici nu au <!--nextpage-->).
        if ($n >= 2 && ctype_digit($segmente[$n - 1])) {
            $existenta = self::adresaExistenta($db, array_slice($segmente, 0, -1));
            if ($existenta !== null) {
                return $existenta;
            }
        }
        $forma = self::forma($segmente);
        if ($forma === null) {
            return null;
        }
        [$segmentNume, $numarPagina] = $forma;
        $nume = self::nume($segmentNume);
        if ($nume === '') {
            return null;
        }
        $tinta = self::castigatoare($db, $nume);
        if ($tinta === null) {
            return null;
        }
        if ($numarPagina > 1) {
            // trailingslashit(get_permalink()) . user_trailingslashit($page, 'single_paged')
            $tinta = rtrim($tinta, '/') . '/' . $numarPagina . (WpCompat::slashFinal() ? '/' : '');
        }
        // Niciodată spre adresa cerută (fără buclă).
        if (strtolower(rtrim($tinta, '/')) === strtolower(rtrim((string) preg_replace('#/{2,}#', '/', $cale), '/'))) {
            return null;
        }
        return $tinta;
    }

    /**
     * Segmentul din care vine numele și numărul paginii (0 dacă nu e), după regulile de rescriere WordPress care ajung la
     * `name` (postările cu permalinkuri `/%postname%/`), sau null când WordPress nu ghicea (altă rută, fără 404).
     *
     * @param list<string> $segmente
     * @return array{0: string, 1: int}|null
     */
    public static function forma(array $segmente): ?array
    {
        $mic = array_map(static fn (string $s): string => strtolower(rawurldecode($s)), $segmente);
        $n = count($segmente);
        $an = static fn (string $s): bool => preg_match('/^[0-9]{4}$/', $s) === 1;
        if ($n === 1) {
            // feed/, rss/…, embed/ (prima pagină) și arhiva anului.
            if (in_array($mic[0], self::FLUXURI, true) || $mic[0] === 'embed' || $an($mic[0])) {
                return null;
            }
            return [$segmente[0], 0];
        }
        if ($n === 2) {
            if (in_array($mic[0], WpCompat::bazeFaraGhicire(), true)) {
                return null;
            }
            if ($an($mic[0]) && (ctype_digit($mic[1]) || in_array($mic[1], self::FLUXURI, true) || str_starts_with($mic[1], 'page'))) {
                return null;
            }
            if (($mic[0] === 'feed' && in_array($mic[1], self::FLUXURI, true)) || ($mic[0] === 'page' && ctype_digit($mic[1]))
                || ($mic[0] === 'comments' && (in_array($mic[1], self::FLUXURI, true) || $mic[1] === 'embed'))) {
                return null;
            }
            if (in_array($mic[1], self::SUFIXE_FARA_GHICIRE, true)) {
                return null;
            }
            if (ctype_digit($mic[1])) {
                // ([^/]+)(?:/([0-9]+))?/?$ → name + page
                return [$segmente[0], (int) $mic[1]];
            }
            if (preg_match('/^page[0-9]+$/', $mic[1]) === 1 || preg_match('/^comment-page-[0-9]+$/', $mic[1]) === 1) {
                return [$segmente[0], 0];
            }
            // [^/]+/([^/]+)/?$ → attachment, care devine `name` în WP_Query
            return [$segmente[1], 0];
        }
        // 3 segmente: doar ([^/]+)/page/?([0-9]{1,})/?$ (numele, fără număr: `paged` nu intră în adresa ghicită).
        if ($mic[1] === 'page' && ctype_digit($mic[2]) && !$an($mic[0]) && !in_array($mic[0], WpCompat::bazeFaraGhicire(), true)
            && !in_array($mic[0], ['feed', 'comments', 'page'], true)) {
            return [$segmente[0], 0];
        }
        return null;
    }

    /**
     * Numele ca în WordPress: valoarea decodată (parse_str) trecută prin sanitize_title_for_query (sanitize_title_with_dashes,
     * contextul „query”): litere mici (și cele din afara ASCII-ului), caracterele din afara ASCII-ului `%xx` cu litere mici,
     * entitățile scoase, „.” → „-”, doar `%a-z0-9 _-`, spațiile → „-”, fără „-” dublu sau la capete.
     */
    public static function nume(string $segment): string
    {
        $t = strip_tags(urldecode($segment));
        // „Octeții codați” ca în sanitize_title_with_dashes, dar scris de mână (vezi octetiCodati): un „%xx” rămâne „%xx”, un
        // „---xx---” din text devine „%xx”, restul de „%” pleacă.
        $t = self::octetiCodati($t);
        if (mb_check_encoding($t, 'UTF-8')) {
            $t = mb_strtolower($t, 'UTF-8');
            $t = self::codeazaOctetiiInalti($t); // fiecare octet ≥ 0x80 → „%” + două cifre hex mici
        }
        $t = strtolower($t);
        $t = self::scoateEntitatile($t); // „&…;” pe un singur rând → scos
        $t = str_replace('.', '-', $t);
        $t = self::doarPermise($t);      // ține doar „%”, a-z, 0-9, spațiu, „_”, „-”
        return self::liniute($t);        // spațiile/liniuțele în șir → o singură „-”, fără „-” la capete
    }

    /**
     * Păstrează secvențele codate (un „%” urmat de exact două cifre hexazecimale, cu majuscula lor) și scoate orice alt „%”.
     * Scaner propriu, scris independent (nu placeholder-ul „---$1---” al WordPress), cu același rezultat.
     */
    /**
     * Octeții codați din sanitize_title_with_dashes, scris de mână (nu cu rescrierile-placeholder, regex, ale WordPress): un „%”
     * urmat de două cifre hex rămâne „%xx” (octet păstrat), orice „---xx---” (două cifre hex între câte trei liniuțe) devine
     * „%xx”, orice alt „%” se scoate. Două treceri stânga-dreapta, fără suprapunere, întocmai ca pașii „%xx”→„---xx---” (cu
     * scoaterea „%”-urilor rătăcite) și apoi „---xx---”→„%xx”. A doua trece și peste „---xx---” care erau deja în text, de aceea
     * un „---5f---” din adresă ajunge „%5f” (comportamentul necondiționat al lui WordPress).
     */
    private static function octetiCodati(string $t): string
    {
        $n = strlen($t);
        $a = '';
        for ($i = 0; $i < $n;) {
            if ($t[$i] === '%') {
                if ($i + 2 < $n && self::hex($t[$i + 1]) && self::hex($t[$i + 2])) {
                    $a .= '---' . $t[$i + 1] . $t[$i + 2] . '---';
                    $i += 3;
                } else {
                    $i++; // „%” rătăcit: scos
                }
                continue;
            }
            $a .= $t[$i];
            $i++;
        }
        $m = strlen($a);
        $b = '';
        for ($i = 0; $i < $m;) {
            if ($i + 8 <= $m && $a[$i] === '-' && $a[$i + 1] === '-' && $a[$i + 2] === '-'
                && self::hex($a[$i + 3]) && self::hex($a[$i + 4])
                && $a[$i + 5] === '-' && $a[$i + 6] === '-' && $a[$i + 7] === '-') {
                $b .= '%' . $a[$i + 3] . $a[$i + 4];
                $i += 8;
                continue;
            }
            $b .= $a[$i];
            $i++;
        }
        return $b;
    }

    /** O cifră hexazecimală, ca `[a-fA-F0-9]`. */
    private static function hex(string $c): bool
    {
        return ($c >= '0' && $c <= '9') || ($c >= 'a' && $c <= 'f') || ($c >= 'A' && $c <= 'F');
    }

    /** Fiecare octet ≥ 0x80 → „%” + codul lui pe două cifre hex mici (ca utf8_uri_encode pe octeții non-ASCII). */
    private static function codeazaOctetiiInalti(string $t): string
    {
        $n = strlen($t);
        $r = '';
        for ($i = 0; $i < $n; $i++) {
            $o = ord($t[$i]);
            $r .= $o >= 0x80 ? sprintf('%%%02x', $o) : $t[$i];
        }
        return $r;
    }

    /**
     * Scoate „&…;” întocmai ca `/&.+?;/`: de la un „&”, cel mai apropiat „;” aflat după cel puțin un caracter, fără să treacă
     * peste un „\n” (ca „.” din PCRE, fără /s). Dacă nu găsește, „&” rămâne. Stânga-dreapta, fără suprapunere.
     */
    private static function scoateEntitatile(string $t): string
    {
        $n = strlen($t);
        $r = '';
        for ($i = 0; $i < $n;) {
            if ($t[$i] === '&') {
                $sf = -1;
                for ($k = $i + 1; $k < $n && $t[$k] !== "\n"; $k++) {
                    if ($k >= $i + 2 && $t[$k] === ';') {
                        $sf = $k;
                        break;
                    }
                }
                if ($sf >= 0) {
                    $i = $sf + 1; // „&…;” scos
                    continue;
                }
            }
            $r .= $t[$i];
            $i++;
        }
        return $r;
    }

    /** Ține doar octeții din `[%a-z0-9 _-]` (restul scos, ca `preg_replace('/[^%a-z0-9 _-]/', '', …)`). */
    private static function doarPermise(string $t): string
    {
        $n = strlen($t);
        $r = '';
        for ($i = 0; $i < $n; $i++) {
            $c = $t[$i];
            if ($c === '%' || $c === ' ' || $c === '_' || $c === '-' || ($c >= 'a' && $c <= 'z') || ($c >= '0' && $c <= '9')) {
                $r .= $c;
            }
        }
        return $r;
    }

    /**
     * Spațiile devin „-” (ca `/\s+/`→„-”) și liniuțele în șir se strâng la una (ca `|-+|`→„-”), apoi „-” de la capete se scot. În
     * acest punct singurul spațiu alb rămas e spațiul propriu-zis (restul a fost scos de doarPermise), deci un șir amestecat de
     * spații și liniuțe devine o singură „-”.
     */
    private static function liniute(string $t): string
    {
        $n = strlen($t);
        $r = '';
        for ($i = 0; $i < $n;) {
            if ($t[$i] === ' ' || $t[$i] === '-') {
                while ($i < $n && ($t[$i] === ' ' || $t[$i] === '-')) {
                    $i++;
                }
                $r .= '-';
                continue;
            }
            $r .= $t[$i];
            $i++;
        }
        return trim($r, '-');
    }

    /**
     * Adresa câștigătoarei pentru un nume curățat: postările din wp_harta după ID-ul WordPress, apoi paginile făcute în
     * constructor, în ordinea creării. Null dacă nu se potrivește nimic.
     */
    public static function castigatoare(PDO $db, string $nume): ?string
    {
        // Slug-urile de acum ale paginilor publicate (o singură interogare, oricâte rânduri are harta).
        $publicate = [];
        foreach ($db->query('SELECT id, slug FROM pages WHERE is_published = 1 AND deleted_at IS NULL')->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $publicate[(int) $p['id']] = trim((string) $p['slug'], '/');
        }
        $paginiDinHarta = [];
        foreach (Harta::candidati($db) as $rand) {
            if ($rand['pagina_id'] !== null) {
                $paginiDinHarta[$rand['pagina_id']] = true;
                // Pagina în care a devenit postarea: slug-ul ei de acum (o pagină redenumită se ghicește după numele nou).
                $slug = $publicate[$rand['pagina_id']] ?? null;
                if ($slug === null) {
                    continue;
                }
                $ultim = $slug === '' ? $rand['slug_wp'] : WpCompat::segment((string) substr($slug, (int) strrpos('/' . $slug, '/')));
                if (str_starts_with(strtolower($ultim), $nume)) {
                    return WpCompat::adresaPagina($slug);
                }
                continue;
            }
            if (str_starts_with(strtolower($rand['slug_wp']), $nume) && $rand['cale'] !== '') {
                return $rand['cale'];
            }
        }
        foreach (self::paginiConstructor($db) as $pagina) {
            if (isset($paginiDinHarta[$pagina['id']])) {
                continue;
            }
            $slug = $pagina['slug'];
            $ultim = WpCompat::segment((string) substr($slug, (int) strrpos('/' . $slug, '/')));
            if (str_starts_with(strtolower($ultim), $nume)) {
                return WpCompat::adresaPagina($slug);
            }
        }
        return null;
    }

    /**
     * Adresa unei pagini (din constructor, fără deosebire de litere mari ASCII) sau a unei postări WordPress publicate care
     * există exact la segmentele date, sau null.
     *
     * @param list<string> $segmente
     */
    public static function adresaExistenta(PDO $db, array $segmente): ?string
    {
        $slug = implode('/', array_map('rawurldecode', $segmente));
        if ($slug !== '') {
            $stmt = $db->prepare('SELECT slug FROM pages WHERE slug = ? AND is_published = 1 AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([$slug]);
            $gasit = $stmt->fetchColumn();
            if ($gasit !== false && WpCompat::acelasiSlug($slug, (string) $gasit) && trim((string) $gasit, '/') !== '404') {
                return WpCompat::adresaPagina((string) $gasit);
            }
        }
        $rand = Harta::postareDupaCale($db, '/' . implode('/', $segmente) . '/');
        if ($rand !== null && $rand['pagina_id'] === null) {
            return $rand['cale'];
        }
        return null;
    }

    /**
     * Paginile făcute în constructor care se pot ghici, în ordinea creării: publicate, nu doar pentru membri, nu paginile de
     * sistem (coș, comandă, cont, 404…), nu ale unui plugin pe care site-ul nu-l are.
     *
     * @return list<array{id: int, slug: string}>
     */
    private static function paginiConstructor(PDO $db): array
    {
        $rez = [];
        foreach ($db->query('SELECT id, slug FROM pages WHERE is_published = 1 AND deleted_at IS NULL'
            . AccesMembri::conditieSql($db, 'pages', 'pages', true) . ' ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) as $rand) {
            $slug = trim((string) $rand['slug'], '/');
            $mic = mb_strtolower($slug);
            if ($slug === '' || in_array($mic, Sitemap::PAGINI_SISTEM, true) || str_starts_with($mic, 'checkout/')
                || str_starts_with($mic, 'contul-meu/')) {
                continue;
            }
            $proprietar = Plugins::proprietarPagina($slug);
            if ($proprietar !== null && !Plugins::vizibil($proprietar)) {
                continue;
            }
            $rez[] = ['id' => (int) $rand['id'], 'slug' => $slug];
        }
        return $rez;
    }
}
