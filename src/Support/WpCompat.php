<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\WpCompat\Ghicire;
use App\Support\WpCompat\Harta;
use PDO;
use Throwable;

/**
 * Stratul de adrese WordPress (adoptarea unui site WordPress, pasul 2; docs/SEO.md, „Adresele unui site WordPress”):
 * un site mutat de pe WordPress păstrează adresele și răspunsurile pe care le dădea WordPress (slash-ul final, majusculele,
 * `?page_id=`, ghicirea adresei greșite, atașamentele, sitemap-urile vechi, 404-ul), iar adresele tehnice ale WordPress-ului
 * răspund 410.
 *
 * Îl pornește `wp-compat.json` din pachetul site-ului (DateSite::json). **Fără fișier nu face nimic**: nicio cerere, niciun
 * antet și nicio adresă nu se schimbă (toate site-urile de azi). Un fișier stricat se ignoră întreg, cu un rând în jurnalul
 * PHP (tools/dev/verifica-pachet.php nu-l lasă să ajungă pe un server).
 *
 * Cheile (toate opționale; ce lipsește e oprit):
 *  - `slash_final` (bool): adresa canonică a unei pagini are „/” la sfârșit (permalinkurile `/%postname%/`): `/x` → 301 →
 *    `/x/` într-un singur salt, cu query-ul păstrat (SeoRedirects); la fel canonicalul, sitemap-urile generate, meniurile,
 *    adresa din editorul paginii și redirecționările automate de la schimbarea slug-ului (WP-38). O cale al cărei ultim
 *    segment are „.” (`/sitemap.xml`, `/index.php`) e un fișier: rămâne fără „/”;
 *  - `majuscule` ("200"): `/CAMERE/` răspunde 200 cu canonicalul cu litere mici, ca WordPress, în locul 301-ului nucleului;
 *    doar literele ASCII (WordPress nu potrivește `Ș` cu `ș`);
 *  - `ghicire` (bool): o adresă necunoscută duce, cu 301, la pagina al cărei slug începe cu ultimul ei segment
 *    (redirect_guess_404_permalink; WpCompat\Ghicire);
 *  - `harta_id` (bool): `?page_id=`, `?p=`, `?cat=`, `?author=` și `/index.php` → 301 la adresa frumoasă (tabelul
 *    `wp_harta`, WpCompat\Harta); un ID necunoscut → 404, ca WordPress;
 *  - `atasamente_la_fisier` (bool): `?attachment_id=` și adresa frumoasă a unui atașament → 301 la fișier (WordPress 6.4+,
 *    fără paginile atașamentelor);
 *  - `cautare_wp` (bool): căutarea în forma WordPress (pasul 9; aici doar validată);
 *  - `sitemap_302` (căi): sitemap-urile vechi → 302 la `/sitemap.xml`;
 *  - `resturi_410` (căi; una cu „/” la sfârșit acoperă tot ce e sub ea): adresele tehnice WordPress → 410, oricare ar fi
 *    metoda (fișierele copiate, din `wp-content/` și `wp-includes/`, le servește serverul web înaintea PHP-ului);
 *  - `compatibile` (listă de `{"metoda", "cale", "actiune"?}`): excepțiile din `resturi_410`, endpointurile pe care site-ul
 *    le servește în forma WordPress (cu `actiune`: doar cererile cu acel `action`); stratul SEO nu le atinge;
 *  - `antete_404` ("wordpress"): orice 404 pleacă cu antetele `nocache_headers()` ale WordPress;
 *  - `baze_fara_ghicire` (segmente): primele segmente pe care WordPress le dă arhivelor (implicit `BAZE_IMPLICITE`); sub
 *    ele nu se ghicește. Lista din pachet o înlocuiește pe cea implicită (baza categoriilor se poate schimba în WordPress).
 *
 * Slug-urile Unicode (`Slug::unicode`) pornesc tot de aici (`slugUnicode()`). Tot de aici, o cerere HEAD primește
 * răspunsul unui GET, ca la WordPress (`intercepteaza()`; fără fișier, HEAD rămâne ca până acum).
 *
 * Rulează și înainte de autoloader (din SeoRedirects): partea citită acolo (config(), căile) folosește doar Env, Paths și
 * DateSite.
 */
final class WpCompat
{
    public const FISIER = 'wp-compat.json';

    /** Cheile știute; altă cheie face fișierul invalid (o greșeală de scriere nu trece în tăcere). */
    public const CHEI = ['slash_final', 'majuscule', 'ghicire', 'harta_id', 'atasamente_la_fisier', 'cautare_wp', 'sitemap_302',
        'resturi_410', 'compatibile', 'antete_404', 'baze_fara_ghicire'];

    /** Antetele unui 404 WordPress (nocache_headers()). */
    public const CACHE_CONTROL_404 = 'no-cache, must-revalidate, max-age=0, no-store, private';
    public const EXPIRES_404 = 'Wed, 11 Jan 1984 05:00:00 GMT';

    /** Primele segmente ale arhivelor nucleului WordPress (categoriile, etichetele, autorii, căutarea, formatele articolelor). */
    public const BAZE_IMPLICITE = ['category', 'tag', 'author', 'search', 'type'];

    /** Parametrii adreselor simple, în ordinea în care se încearcă (WP_Query: `p` înaintea lui `page_id`). */
    private const PARAMETRI_ID = ['p', 'page_id', 'attachment_id', 'cat', 'author'];

    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    // ───────────────────────────────────────────────────────────
    // Configurarea
    // ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> configurarea validă din pachet, sau [] (stratul oprit) */
    public static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }
        if (!class_exists(DateSite::class, false)) {
            require_once __DIR__ . '/DateSite.php';
        }
        $date = DateSite::json(self::FISIER);
        if ($date === []) {
            return self::$config = [];
        }
        $probleme = self::valideaza($date);
        if ($probleme !== []) {
            error_log('ANDAXI-CMS: ' . self::FISIER . ' din pachetul site-ului nu e valid (' . implode('; ', array_slice($probleme, 0, 3))
                . '); stratul de adrese WordPress rămâne oprit.');
            return self::$config = [];
        }
        return self::$config = $date;
    }

    public static function activ(): bool
    {
        return self::config() !== [];
    }

    public static function slashFinal(): bool
    {
        return (self::config()['slash_final'] ?? false) === true;
    }

    public static function majuscule200(): bool
    {
        return (string) (self::config()['majuscule'] ?? '') === '200';
    }

    public static function ghicire(): bool
    {
        return (self::config()['ghicire'] ?? false) === true;
    }

    public static function hartaId(): bool
    {
        return (self::config()['harta_id'] ?? false) === true;
    }

    public static function atasamenteLaFisier(): bool
    {
        return (self::config()['atasamente_la_fisier'] ?? false) === true;
    }

    public static function cautareWp(): bool
    {
        return (self::config()['cautare_wp'] ?? false) === true;
    }

    public static function antete404(): bool
    {
        return (string) (self::config()['antete_404'] ?? '') === 'wordpress';
    }

    /** @return list<string> căile sitemap-urilor vechi (cu litere mici) */
    public static function sitemap302(): array
    {
        return array_values(array_map('strtolower', (array) (self::config()['sitemap_302'] ?? [])));
    }

    /** @return list<string> căile tehnice cu 410 (cu litere mici) */
    public static function resturi410(): array
    {
        return array_values(array_map('strtolower', (array) (self::config()['resturi_410'] ?? [])));
    }

    /** @return list<array{metoda: string, cale: string, actiune: string}> */
    public static function compatibile(): array
    {
        $rez = [];
        foreach ((array) (self::config()['compatibile'] ?? []) as $c) {
            $rez[] = ['metoda' => strtoupper((string) $c['metoda']), 'cale' => strtolower((string) $c['cale']),
                'actiune' => (string) ($c['actiune'] ?? '')];
        }
        return $rez;
    }

    /** @return list<string> */
    public static function bazeFaraGhicire(): array
    {
        $baze = self::config()['baze_fara_ghicire'] ?? null;
        return is_array($baze) ? array_values(array_map('strval', $baze)) : self::BAZE_IMPLICITE;
    }

    /**
     * Slug-urile paginilor păstrează literele oricărei limbi (Slug::unicode), în admin și în JS-ul editorului. Azi doar pe
     * site-urile cu wp-compat.json; aici se va adăuga și cheia limbilor, când va exista.
     */
    public static function slugUnicode(): bool
    {
        return self::activ();
    }

    /** Doar pentru teste și unelte: uită configurarea citită (alt pachet; după DateSite::reseteaza). */
    public static function reseteaza(): void
    {
        self::$config = null;
    }

    /**
     * Problemele unui wp-compat.json (gol = bun): un obiect, doar cheile știute, cu tipurile lor.
     *
     * @return list<string>
     */
    public static function valideaza(mixed $date): array
    {
        if (!is_array($date) || ($date !== [] && array_is_list($date))) {
            return ['trebuie să fie un obiect JSON'];
        }
        $probleme = [];
        foreach (array_keys($date) as $cheie) {
            if (!in_array((string) $cheie, self::CHEI, true)) {
                $probleme[] = 'cheie necunoscută: ' . $cheie . ' (știute: ' . implode(', ', self::CHEI) . ')';
            }
        }
        foreach (['slash_final', 'ghicire', 'harta_id', 'atasamente_la_fisier', 'cautare_wp'] as $cheie) {
            if (array_key_exists($cheie, $date) && !is_bool($date[$cheie])) {
                $probleme[] = $cheie . ': true sau false';
            }
        }
        if (array_key_exists('majuscule', $date) && !in_array($date['majuscule'], ['200', '301'], true)) {
            $probleme[] = 'majuscule: "200" (ca WordPress) sau "301" (ca nucleul)';
        }
        if (array_key_exists('antete_404', $date) && !in_array($date['antete_404'], ['wordpress', 'nucleu'], true)) {
            $probleme[] = 'antete_404: "wordpress" sau "nucleu"';
        }
        $cale = static fn (mixed $c): bool => is_string($c) && preg_match('#^/[A-Za-z0-9._~/-]*$#', $c) === 1 && !str_contains($c, '//')
            && !str_contains($c, '/../') && !str_ends_with($c, '/..');
        foreach (['sitemap_302', 'resturi_410'] as $cheie) {
            if (!array_key_exists($cheie, $date)) {
                continue;
            }
            if (!is_array($date[$cheie]) || !array_is_list($date[$cheie])) {
                $probleme[] = $cheie . ': o listă de căi';
                continue;
            }
            foreach ($date[$cheie] as $c) {
                if (!$cale($c) || $c === '/') {
                    $probleme[] = $cheie . ': cale nevalidă ' . json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        . ' (o cale absolută, fără „//”, fără „..”, nu „/”)';
                }
            }
        }
        if (array_key_exists('compatibile', $date)) {
            if (!is_array($date['compatibile']) || !array_is_list($date['compatibile'])) {
                $probleme[] = 'compatibile: o listă de obiecte {"metoda", "cale", "actiune"}';
            } else {
                foreach ($date['compatibile'] as $n => $c) {
                    $eticheta = 'compatibile[' . $n . ']';
                    if (!is_array($c) || array_is_list($c)) {
                        $probleme[] = $eticheta . ': un obiect {"metoda", "cale", "actiune"}';
                        continue;
                    }
                    foreach (array_keys($c) as $k) {
                        if (!in_array((string) $k, ['metoda', 'cale', 'actiune'], true)) {
                            $probleme[] = $eticheta . ': cheie necunoscută ' . $k;
                        }
                    }
                    if (!in_array($c['metoda'] ?? null, ['GET', 'POST', 'HEAD'], true)) {
                        $probleme[] = $eticheta . ': metoda GET, POST sau HEAD';
                    }
                    if (!$cale($c['cale'] ?? null) || ($c['cale'] ?? '') === '/') {
                        $probleme[] = $eticheta . ': cale nevalidă';
                    }
                    if (array_key_exists('actiune', $c) && (!is_string($c['actiune']) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $c['actiune']) !== 1)) {
                        $probleme[] = $eticheta . ': actiune nevalidă (litere, cifre, „_”, „-”)';
                    }
                }
            }
        }
        if (array_key_exists('baze_fara_ghicire', $date)) {
            if (!is_array($date['baze_fara_ghicire']) || !array_is_list($date['baze_fara_ghicire'])) {
                $probleme[] = 'baze_fara_ghicire: o listă de segmente';
            } else {
                foreach ($date['baze_fara_ghicire'] as $b) {
                    if (!is_string($b) || preg_match('/^[a-z0-9_-]{1,64}$/', $b) !== 1) {
                        $probleme[] = 'baze_fara_ghicire: segment nevalid ' . json_encode($b, JSON_UNESCAPED_UNICODE);
                    }
                }
            }
        }
        return $probleme;
    }

    // ───────────────────────────────────────────────────────────
    // Forma adreselor
    // ───────────────────────────────────────────────────────────

    /**
     * Calea cu „/” final, cu `slash_final` pornit, când e a unei pagini (ultimul segment fără „.”): „/x” → „/x/”. Altfel
     * (stratul oprit, „/”, un fișier) neschimbată. Doar calea, fără query.
     */
    public static function cuSlash(string $cale): string
    {
        if ($cale === '' || $cale === '/' || str_ends_with($cale, '/') || !self::slashFinal()) {
            return $cale;
        }
        $ultim = substr($cale, (int) strrpos($cale, '/') + 1);
        return str_contains($ultim, '.') ? $cale : $cale . '/';
    }

    /** Ca cuSlash(), pentru o adresă cu query sau fragment („/x?a=1” → „/x/?a=1”). */
    public static function cuSlashAdresa(string $adresa): string
    {
        if (!self::slashFinal() || !str_starts_with($adresa, '/') || str_starts_with($adresa, '//')) {
            return $adresa;
        }
        $poz = strcspn($adresa, '?#');
        return self::cuSlash(substr($adresa, 0, $poz)) . substr($adresa, $poz);
    }

    /**
     * Calea canonică a unei cereri, ca o scria WordPress (get_permalink, din `post_name`): fiecare segment decodat, apoi codat
     * din nou ca un segment WordPress (segment(): octeții din afara ASCII-ului vizibil `%xx` cu litere mici, un escape ASCII
     * fără rost, `%65` sau `%2D`, devine litera lui), literele mari ASCII mici (cu `majuscule` "200": canonicalul paginii
     * cerute cu majuscule) și „/” final (`slash_final`). Pagina găsită are slug-ul egal cu calea decodată (WpCompat::acelasiSlug),
     * deci canonicalul ei e chiar adresaPagina(slug). Cu stratul oprit, neschimbată.
     */
    public static function caleCanonica(string $cale): string
    {
        if (!self::activ()) {
            return $cale;
        }
        $cale = (string) preg_replace('#/{2,}#', '/', $cale === '' ? '/' : $cale);
        $mici = self::majuscule200();
        $segmente = [];
        foreach (explode('/', $cale) as $s) {
            $s = rawurldecode($s);
            $segmente[] = self::segment($mici ? strtolower($s) : $s);
        }
        return self::cuSlash(implode('/', $segmente));
    }

    /**
     * Un segment de cale în forma WordPress (utf8_uri_encode): octeții din afara ASCII-ului vizibil și caracterele care nu
     * pot sta într-un segment, `%xx` cu litere mici; restul neatins („camere-și” → „camere-%c8%99i”).
     */
    public static function segment(string $segment): string
    {
        return (string) preg_replace_callback('/[^\x21-\x7e]|[%?#\/"<>\\\\^`{|}]/', static fn (array $m): string => sprintf('%%%02x', ord($m[0])), $segment);
    }

    /**
     * Adresa publică a unei pagini din constructor (slug-ul din `pages`, decodat, cu „/” între segmente), în forma
     * WordPress: „/” pentru prima pagină, altfel segmentele percent-encoded cu litere mici și „/” final (`slash_final`).
     */
    public static function adresaPagina(string $slug): string
    {
        $slug = trim($slug, '/');
        if ($slug === '') {
            return '/';
        }
        return self::cuSlash('/' . implode('/', array_map([self::class, 'segment'], explode('/', $slug))));
    }

    /**
     * Pagina găsită după slug (colația bazei nu ține cont de litere mari și de diacritice) e chiar cea cerută? În modul
     * WordPress doar literele mari ASCII pot diferi: fără diacritice tăiate, fără `Ș` = `ș`, fără altă normalizare.
     */
    public static function acelasiSlug(string $cerut, string $salvat): bool
    {
        return $cerut === $salvat || strtolower($cerut) === strtolower($salvat);
    }

    /** O cale a site-ului ca adresă întreagă, pe gazda din APP_URL (https, ca WordPress); în dezvoltare, calea goală. */
    public static function adresaAbsoluta(string $cale): string
    {
        if (!class_exists(SeoRedirects::class, false)) {
            require_once __DIR__ . '/SeoRedirects.php';
        }
        return (SeoRedirects::bazaCanonica() ?? '') . $cale;
    }

    // ───────────────────────────────────────────────────────────
    // Resturile WordPress și endpointurile compatibile
    // ───────────────────────────────────────────────────────────

    /** Calea (orice metodă) e una dintre adresele tehnice WordPress din `resturi_410`? (Fără deosebire de litere mari.) */
    public static function eRest(string $cale): bool
    {
        $lista = self::resturi410();
        if ($lista === []) {
            return false;
        }
        foreach (array_unique([strtolower($cale), strtolower(rawurldecode($cale)), strtolower((string) preg_replace('#/{2,}#', '/', rawurldecode($cale)))]) as $c) {
            foreach ($lista as $rest) {
                if (str_ends_with($rest, '/') ? str_starts_with($c, $rest) || $c === rtrim($rest, '/') : $c === $rest || rtrim($c, '/') === $rest) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Cererea e a unui endpoint compatibil (`compatibile`): aceeași metodă (HEAD contează și ca GET, ca la WordPress),
     * aceeași cale și, dacă intrarea o cere, același `action` (din POST, apoi din query, ca admin-ajax.php)?
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $get
     */
    public static function eCompatibil(string $metoda, string $cale, array $post = [], array $get = []): bool
    {
        $metoda = strtoupper($metoda);
        $metode = $metoda === 'HEAD' ? ['HEAD', 'GET'] : [$metoda];
        $cale = self::caleDeComparat($cale);
        foreach (self::compatibile() as $c) {
            if (!in_array($c['metoda'], $metode, true) || $c['cale'] !== $cale) {
                continue;
            }
            if ($c['actiune'] === '') {
                return true;
            }
            $actiune = $post['action'] ?? $get['action'] ?? null;
            if (is_string($actiune) && $actiune === $c['actiune']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Căile pe care stratul de redirecționări SEO nu le atinge în modul WordPress: resturile cu 410 și endpointurile
     * compatibile (o cale fără „/” final nu primește 301 spre „/x/”, iar endpointul își vede adresa neschimbată).
     *
     * @return list<string>
     */
    public static function caiExcluseSeo(): array
    {
        $cai = self::resturi410();
        foreach (self::compatibile() as $c) {
            $cai[] = $c['cale'];
        }
        return array_values(array_unique($cai));
    }

    /**
     * Calea (orice metodă) e una pe care SeoRedirects n-o atinge (caiExcluseSeo: un rest cu 410 sau calea unui endpoint
     * compatibil), decodată, fără deosebire de litere mari și cu „//” strâns? Doar redirecționările o ocolesc (incepe(),
     * redirectCanonic()): nu intră în SeoRedirects::caiExcluse(), lista căilor de coș, cont și plată, care rămân „exact ca
     * înainte” și pe 404 (canonicalul, sesiunea, jurnalul 404). Un endpoint compatibil care răspunde 404 (pasul lui încă
     * nu e în cod) e deci un 404 obișnuit, fără canonical.
     */
    public static function neatinsDeSeo(string $cale): bool
    {
        if (!self::activ()) {
            return false;
        }
        if (self::eRest($cale)) {
            return true;
        }
        $cale = self::caleDeComparat($cale);
        foreach (self::compatibile() as $c) {
            if ($c['cale'] === $cale) {
                return true;
            }
        }
        return false;
    }

    /** Calea pentru comparațiile cu listele din wp-compat.json: decodată, cu litere mici, cu „//” strâns. */
    private static function caleDeComparat(string $cale): string
    {
        return strtolower((string) preg_replace('#/{2,}#', '/', rawurldecode($cale)));
    }

    // ───────────────────────────────────────────────────────────
    // Cererea
    // ───────────────────────────────────────────────────────────

    /**
     * Răspunde, în locul aplicației, cererilor pe care WordPress le trata înaintea paginilor (public/index.php, după
     * pluginuri și înaintea campaniilor și a cache-ului de pagini): resturile tehnice (410, orice metodă, în afara
     * endpointurilor compatibile), sitemap-urile vechi (302), adresele simple `?page_id=`, `?p=`, `?cat=`, `?author=`,
     * `?attachment_id=` și `/index.php` (301; un ID necunoscut: 404). Pune și antetele WordPress pe 404 (`antete_404`).
     * O cerere HEAD merge mai departe ca GET (cum răspunde WordPress: aceleași coduri și antete, fără corp; corpul îl
     * taie serverul web), fiindcă routerul nucleului n-are rute HEAD (docs/SEO.md, §11).
     * Întoarce true dacă a răspuns. Fără wp-compat.json nu face nimic.
     */
    public static function intercepteaza(?PDO $db, string $metoda, string $uri): bool
    {
        if (!self::activ()) {
            return false;
        }
        self::pregatesteAntete404();
        [$cale, $query] = self::imparte($uri);
        $metoda = strtoupper($metoda);
        if (self::eRest($cale) && !self::eCompatibil($metoda, $cale, $_POST, $_GET)) {
            self::raspunde(410, null);
            return true;
        }
        if ($metoda === 'HEAD') {
            // Ca la SeoRedirects (aceleași chei): aplicația vede un GET, iar cine are nevoie află metoda cerută.
            $_SERVER['SEO_REQUEST_METHOD_ORIGINAL'] = 'HEAD';
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }
        $metodaOriginala = strtoupper((string) ($_SERVER['SEO_REQUEST_METHOD_ORIGINAL'] ?? $metoda));
        if (!in_array($metodaOriginala, ['GET', 'HEAD'], true)) {
            return false;
        }
        $caleMica = strtolower($cale);
        if (in_array($caleMica, self::sitemap302(), true)) {
            self::raspunde(302, self::adresaAbsoluta('/sitemap.xml'));
            return true;
        }
        $index = $caleMica === '/index.php';
        if ($cale !== '/' && !$index) {
            return false;
        }
        [$parametru, $id, $rest] = self::parametruId($query);
        if ($parametru === null) {
            if ($index) {
                self::raspunde(301, self::adresaAbsoluta('/') . ($query !== '' ? '?' . $query : ''));
                return true;
            }
            return false;
        }
        $tinta = $db instanceof PDO ? self::tintaId($db, $parametru, $id) : null;
        if ($tinta === null) {
            // Ca WordPress: o postare care nu există (sau nu se vede) e un 404, nu prima pagină.
            http_response_code(404);
            (new \App\Http\Controllers\SiteController())->paginaNegasita();
            return true;
        }
        self::raspunde(301, (str_starts_with($tinta, '/') ? self::adresaAbsoluta($tinta) : $tinta) . ($rest !== '' ? '?' . $rest : ''));
        return true;
    }

    /**
     * Parametrul adresei simple din query (primul din PARAMETRI_ID cu un număr), id-ul și restul query-ului (ceilalți
     * parametri, în ordinea și forma lor; WordPress îi lipește la adresa frumoasă).
     *
     * @return array{0: ?string, 1: int, 2: string}
     */
    public static function parametruId(string $query): array
    {
        if ($query === '') {
            return [null, 0, ''];
        }
        $bucati = explode('&', $query);
        $gasit = null;
        $id = 0;
        $index = -1;
        foreach (self::PARAMETRI_ID as $parametru) {
            foreach ($bucati as $n => $bucata) {
                [$nume, $valoare] = array_pad(explode('=', $bucata, 2), 2, '');
                if (rawurldecode($nume) === $parametru && preg_match('/^[0-9]{1,19}$/', rawurldecode($valoare)) === 1 && (int) $valoare > 0) {
                    $gasit = $parametru;
                    $id = (int) rawurldecode($valoare);
                    $index = $n;
                    break 2;
                }
            }
        }
        if ($gasit === null) {
            return [null, 0, ''];
        }
        // Parametrii adreselor simple pleacă toți (WordPress: _remove_qs_args_if_not_in_url pe p, page_id, attachment_id…).
        $rest = [];
        foreach ($bucati as $n => $bucata) {
            $nume = rawurldecode(explode('=', $bucata, 2)[0]);
            if ($n === $index || ($bucata === '') || in_array($nume, ['p', 'page_id', 'attachment_id', 'pagename', 'name', 'post_type'], true)
                || ($gasit === 'cat' && $nume === 'cat') || ($gasit === 'author' && $nume === 'author')) {
                continue;
            }
            $rest[] = $bucata;
        }
        return [$gasit, $id, implode('&', $rest)];
    }

    /** Ținta unei adrese simple (calea sau adresa fișierului), din wp_harta; null dacă nu există sau nu se vede. */
    public static function tintaId(PDO $db, string $parametru, int $id): ?string
    {
        $tipuri = match ($parametru) {
            'page_id' => ['page'],
            'p' => ['post', 'page', 'project', 'attachment'],
            'attachment_id' => ['attachment'],
            'cat' => ['category'],
            'author' => ['author'],
            default => [],
        };
        if (!self::hartaId() && $parametru !== 'attachment_id') {
            return null;
        }
        $rand = Harta::dupaId($db, $tipuri, $id);
        if ($rand === null) {
            return null;
        }
        if ($rand['tip'] === 'attachment') {
            return self::atasamenteLaFisier() && $rand['fisier'] !== '' ? $rand['fisier'] : null;
        }
        return Harta::adresaRand($db, $rand);
    }

    /**
     * Pentru o adresă pe care aplicația n-a găsit-o (SiteController::paginaNegasita, GET/HEAD): atașamentul de la acea
     * adresă (301 la fișier) sau pagina ghicită (301), cu query-ul păstrat întocmai. Întoarce true dacă a trimis
     * redirecționarea.
     */
    public static function raspundeNegasit(?PDO $db, string $uri): bool
    {
        if (!self::activ() || !$db instanceof PDO) {
            return false;
        }
        $metoda = strtoupper((string) ($_SERVER['SEO_REQUEST_METHOD_ORIGINAL'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($metoda, ['GET', 'HEAD'], true)) {
            return false;
        }
        [$cale, $query] = self::imparte($uri);
        $tinta = null;
        try {
            if (self::atasamenteLaFisier()) {
                $tinta = Harta::fisierAtasament($db, $cale);
            }
            if ($tinta === null && self::ghicire()) {
                $tinta = Ghicire::pentru($db, $cale);
            }
        } catch (Throwable) {
            $tinta = null;
        }
        if ($tinta === null) {
            return false;
        }
        self::raspunde(301, self::adresaAbsoluta($tinta) . ($query !== '' ? '?' . $query : ''));
        return true;
    }

    /**
     * Antetele WordPress pe orice 404 (`antete_404`: nocache_headers()), puse chiar înainte de trimiterea antetelor, oricine
     * ar fi răspuns 404. Nu pe site-ul de verificare (Verificare își pune singur antetele, cu același mecanism).
     */
    private static function pregatesteAntete404(): void
    {
        if (!self::antete404() || headers_sent()) {
            return;
        }
        if (class_exists(Verificare::class) && Verificare::activ()) {
            return;
        }
        header_register_callback(static function (): void {
            if (http_response_code() === 404) {
                header('Cache-Control: ' . self::CACHE_CONTROL_404, true);
                header('Expires: ' . self::EXPIRES_404, true);
            }
        });
    }

    /** Un răspuns fără corp: 410, 302 sau 301 (cu Location), fără cookie-uri pentru un vizitator fără sesiune. */
    private static function raspunde(int $cod, ?string $locatie): void
    {
        http_response_code($cod);
        SeoRedirects::faraCookieuriPentruVizitatorNou();
        if ($locatie !== null) {
            // Query-ul e cel pe care l-am pus noi în Location (poate fi gol: parametrii `?page_id=`, `?p=`, `?cat=`… scoși):
            // dacă apoi SeoRedirects mai corectează gazda (www) sau „//", să nu-l lipească la loc pe cel original (SPEC-R2-05).
            // Query-ul se ia ca în SeoRedirects (despărțit la „?”, fără parse_url, care poate refuza o cale neobișnuită).
            SeoRedirects::fixeazaQueryCanonic(self::imparte($locatie)[1]);
            header('Location: ' . $locatie, true, $cod);
            header('Cache-Control: ' . SeoRedirects::cacheControlRedirect(), true);
        } else {
            header('Cache-Control: no-store', true);
        }
        header('Content-Type: text/html; charset=utf-8', true);
    }

    /** @return array{0: string, 1: string} calea (cum a venit, percent-encoded) și query-ul */
    private static function imparte(string $uri): array
    {
        if (preg_match('#^https?://[^/]+(/.*)?$#i', $uri, $m) === 1) {
            $uri = $m[1] ?? '/';
        }
        $uri = explode('#', $uri, 2)[0];
        $parti = explode('?', $uri, 2);
        return [$parti[0] === '' ? '/' : $parti[0], (string) ($parti[1] ?? '')];
    }
}
