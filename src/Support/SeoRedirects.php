<?php

declare(strict_types=1);

namespace App\Support;

/**
 * O singură adresă pentru fiecare pagină, plus redirecționările adreselor vechi
 * ale site-ului.
 *
 * Rulează la începutul lui public/index.php, înainte de bootstrap (deci înainte
 * de sesiune și de autoloader), și se ocupă DOAR de cererile GET/HEAD care nu
 * țin de coș, plată, cont, admin, API sau confirmările de plată (vezi
 * caiExcluse(): lista nucleului, plus fiecare adresă „mereu deschisă” din
 * catalogul pluginurilor, plus ce adaugă site-ul în seo.json). Orice altă
 * cerere trece exact ca înainte, fără nicio atingere.
 *
 * Trei lucruri:
 *  1. gazda: varianta cu sau fără www a gazdei din APP_URL (pe http sau https)
 *     → gazda din APP_URL, pe https (nu face nimic dacă APP_URL nu e https sau
 *     e localhost/IP, deci nici local). Pe gazda corectă nu se redirecționează
 *     după schemă: http → https îl face găzduirea (marginea Hostinger), iar dacă
 *     serverul din spatele CDN-ului n-ar vedea https, o regulă de schemă de aici
 *     ar trimite fiecare pagină la ea însăși. Cine are deja coș sau cont deschis
 *     pe gazda veche rămâne acolo (cookie-ul de sesiune e legat de gazdă);
 *     roboții și vizitatorii noi se mută;
 *  2. calea: „/x/" și „/x//y" → „/x" și „/x/y" (prima pagină rămâne „/");
 *  3. harta adreselor vechi ale site-ului: adresa veche → pagina nouă, cu 301,
 *     sau 410 pentru resturi tehnice. Nucleul nu conține nicio regulă. Din
 *     WP-38 harta stă în baza de date (`seo_redirects`, managerul din admin,
 *     App\Support\Redirectionari), cu regulile din `redirecturi.json` al site-ului
 *     (vezi DateSite) ca sămânță; aici se citește o copie a ei din
 *     storage/cache (fără bază, înaintea sesiunii). Cât copia lipsește sau e
 *     veche (pachetul schimbat), cererea folosește regulile pachetului, ca
 *     înainte, iar la final (termina) copia se reface din bază.
 *
 * Cum: când o cerere are ceva de reparat, aplicația rulează normal pe calea
 * curată, cu răspunsul ținut în buffer. La final (funcție de oprire, deci și
 * după un exit()) se hotărăște:
 *  - harta se aplică doar dacă aplicația ar fi răspuns 404/410 sau cu o
 *    redirecționare ghicită (potrivirea aproximativă a produselor); o pagină
 *    care există nu e acoperită niciodată de hartă; o țintă care e pagina unui
 *    plugin pe care site-ul nu-l are nu se folosește (rămâne 404);
 *  - o redirecționare a aplicației devine un singur salt, direct la ținta finală;
 *  - o pagină care există primește 301 la adresa curată;
 *  - o pagină inexistentă pe gazda corectă își păstrează 404-ul (fără 301 spre 404).
 * Rezultatul: un singur 301, fără lanțuri și fără bucle; parametrii din adresă
 * (gclid, utm_*…) se păstrează. Un 301 nu trimite cookie-uri unui vizitator
 * fără sesiune (robot, link vechi); pagina la care ajunge îl primește normal.
 *
 * Oprire de urgență: SEO_REDIRECTURI=0 în .env dezactivează tot stratul.
 */
final class SeoRedirects
{
    /**
     * Căi care nu se redirecționează niciodată, pe orice site (prefixe, fără
     * diferență între litere mari și mici): coș, plată, cont, admin, API,
     * confirmări de plată și de ERP, autentificarea Google, newsletter,
     * fișiere, sănătate, adresele platformei (/_andaxi/…). Un prefix care se
     * termină în „/" prinde doar subcăile; unul fără „/" prinde calea exactă și
     * subcăile ei („/cos" prinde „/cos" și „/cos/…", dar nu „/cosmetice"). La
     * ele se adaugă adresele „mereu deschise” din catalogul pluginurilor și
     * `cai_excluse` din seo.json.
     */
    public const CAI_EXCLUSE = [
        '/admin', '/api', '/auth', '/webhook', '/plata', '/checkout',
        '/cos', '/contul-meu', '/login', '/register',
        '/newsletter', '/health', '/review-form', '/gdpr-agreements',
        '/contact/send', '/uploads', '/assets', '/storage', '/feed', '/.well-known',
        '/cdn-cgi', '/robots.txt', '/sitemap.xml', '/favicon.ico',
        // Adresele platformei (pingul monitorizării, App\Support\Ping; în Etapa 2 și preluarea licenței).
        '/_andaxi',
    ];

    /** Codurile la care aplicația „n-a găsit" pagina, deci harta poate răspunde. */
    private const CODURI_NEGASIT = [404, 410];

    private static bool $activ = false;
    private static bool $redirectCanonic = false;
    private static int $nivelBuffer = 0;
    private static string $caleCurata = '/';
    private static string $query = '';
    /** Query-ul ales de redirectCanonic() (poate fi gol: „?category=” mutat în cale); null dacă n-a fost apelat. */
    private static ?string $queryCanonic = null;
    private static ?string $bazaCanonica = null;
    private static bool $gazdaGresita = false;
    private static bool $caleGresita = false;
    /** @var array{0: ?string, 1: int}|null ținta din hartă și codul */
    private static ?array $regula = null;

    /** @var array<string, array{0: ?string, 1: int}>|null */
    private static ?array $harta = null;
    /** @var list<array{0: string, 1: ?string, 2: int, 3: int}>|null prefixul, ținta, codul, rândul din seo_redirects */
    private static ?array $prefixe = null;
    /** @var array<string, int> rândul din seo_redirects al fiecărei chei exacte (0 = din pachet, fără bază) */
    private static array $iduri = [];
    /** Harta vine din pachet pentru că copia din bază lipsește sau e veche: la final se reface (WP-38). */
    private static bool $hartaDeRefacut = false;
    /** Doar pentru teste: fără copie (harta = pachetul, ca înainte de WP-38). */
    private static bool $faraCopie = false;
    /** @var array<string, mixed>|null copia refăcută în această cerere (folosită și când fișierul n-a putut fi scris) */
    private static ?array $copieMemorie = null;

    /** După o copie care n-a putut fi scrisă, atâtea secunde fără încercări noi (harta pachetului, ca înainte). */
    public const PAUZA_ESEC = 300;
    /** @var list<string>|null lista completă (nucleu + catalog + site) */
    private static ?array $caiExcluse = null;

    /**
     * Pornește verificarea cererii curente. Nu trimite nimic și nu oprește
     * nimic: cel mult rescrie REQUEST_URI la calea curată și pune un buffer.
     */
    public static function incepe(): void
    {
        try {
            self::incepeIntern();
        } catch (\Throwable) {
            // Stratul SEO nu are voie să strice vreo cerere.
            self::$activ = false;
        }
    }

    private static function incepeIntern(): void
    {
        if (!class_exists(Env::class, false)) {
            require_once __DIR__ . '/Env.php';
        }
        if (!class_exists(Paths::class, false)) {
            require_once __DIR__ . '/Paths.php';
        }
        if (!class_exists(DateSite::class, false)) {
            require_once __DIR__ . '/DateSite.php';
        }
        Env::load(Paths::env());
        if (self::oprit()) {
            return;
        }

        $metoda = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($metoda !== 'GET' && $metoda !== 'HEAD') {
            return;
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        [$caleBruta, $query] = self::imparteUri($uri);
        // Întâi lista fixă (fără să citim catalogul): cele mai multe cereri excluse se opresc aici.
        if (self::eExclusaDupa(self::CAI_EXCLUSE, $caleBruta)) {
            return;
        }
        $caleCurata = self::curataCale($caleBruta);

        self::$bazaCanonica = self::bazaCanonica();
        $gazdaGresita = false;
        if (self::$bazaCanonica !== null) {
            $gazdaCanonica = (string) parse_url(self::$bazaCanonica, PHP_URL_HOST);
            $gazda = self::gazdaCerere();
            // Pe gazda canonică nimic de mutat, oricare ar fi schema (vezi sus).
            if ($gazda !== $gazdaCanonica && in_array($gazda, self::aliasuriGazda($gazdaCanonica), true)) {
                $gazdaGresita = true;
            }
            // Orice altă gazdă (adresa tehnică a serverului, un IP) nu se
            // atinge: nu știm sigur că e același site, iar o regulă greșită
            // aici ar face buclă.
        }
        $caleGresita = $caleCurata !== $caleBruta;
        $regula = self::regulaPentru($caleCurata);
        // HEAD pe o adresă cu litere mari (de exemplu /produs/…-sp21A, adresa
        // veche a căștilor AIRCALL la Bioscem): routerul n-are rute HEAD, deci
        // răspunsul ar fi 404 în loc de 301-ul pe care îl primește GET. Cererea
        // merge ca GET (mai jos), iar răspunsul aplicației pleacă neschimbat.
        // Din Bioscem `bb11ae0` (livrarea 4 SEO).
        $headCuMajuscule = $metoda === 'HEAD' && preg_match('/[A-Z]/', $caleCurata) === 1;
        // WP-38: copia hărții din bază lipsește sau e veche: cererea merge ca înainte (cu regulile pachetului), dar la
        // final se reface copia și se hotărăște cu ea. Doar la GET (un HEAD activat ar fi cerut ca GET, deci ar schimba
        // răspunsul unei pagini care există: 404 → 200).
        $deRefacut = self::$hartaDeRefacut && $metoda === 'GET';

        if (!$gazdaGresita && !$caleGresita && $regula === null && !$headCuMajuscule && !$deRefacut) {
            return;
        }
        // Abia acum lista completă (cu adresele de confirmare ale pluginurilor și ale site-ului).
        if (self::eExclusa($caleBruta) || self::eExclusa($caleCurata)) {
            return;
        }

        self::$activ = true;
        self::$caleCurata = $caleCurata;
        self::$query = $query;
        self::$gazdaGresita = $gazdaGresita;
        self::$caleGresita = $caleGresita;
        self::$regula = $regula;

        // Aplicația vede cererea pe calea curată: routerul, canonicalul și
        // cache-ul de pagini lucrează exact ca pentru adresa corectă.
        $_SERVER['SEO_REQUEST_URI_ORIGINAL'] = $uri;
        $_SERVER['REQUEST_URI'] = $caleCurata . ($query !== '' ? '?' . $query : '');
        if ($metoda === 'HEAD') {
            // Routerul n-are rute HEAD; ca să știm dacă pagina există, o cerem
            // ca GET. Serverul web nu trimite oricum corpul la HEAD.
            $_SERVER['SEO_REQUEST_METHOD_ORIGINAL'] = 'HEAD';
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }

        ob_start();
        self::$nivelBuffer = ob_get_level();
        register_shutdown_function([self::class, 'termina']);
    }

    /** Rulează la oprirea scriptului: hotărăște ce pleacă spre browser. */
    public static function termina(): void
    {
        if (!self::$activ) {
            return;
        }
        self::$activ = false;
        try {
            self::terminaIntern();
        } catch (\Throwable) {
            // Lăsăm răspunsul aplicației să plece neatins.
        }
    }

    private static function terminaIntern(): void
    {
        if (headers_sent() || ob_get_level() < self::$nivelBuffer) {
            return;
        }
        // WP-38: copia hărții refăcută din bază (pachetul sincronizat, regulile din admin), apoi regula cererii din nou.
        if (self::$hartaDeRefacut) {
            self::refaHarta();
            self::$regula = self::regulaPentru(self::$caleCurata);
        }

        // Cine are deja coș sau cont deschis pe gazda veche rămâne acolo: cookie-ul
        // de sesiune e legat de gazdă, iar pe gazda nouă coșul ar apărea gol.
        // Roboții și vizitatorii noi n-au sesiune, deci se mută.
        if (self::$gazdaGresita && self::areSesiuneCuCosSauCont()) {
            self::$gazdaGresita = false;
            self::$bazaCanonica = null;
        }

        $cod = (int) (http_response_code() ?: 200);
        $locatieAplicatie = self::antet('Location');

        // 1. Harta, doar unde aplicația n-a găsit pagina sau a ghicit-o. Un 301
        //    spre forma corectă a unei pagini existente (litere mari → mici)
        //    nu e ghicit: acolo pagina există și harta nu are ce căuta.
        $aplicatiaAGhicit = $cod >= 300 && $cod < 400 && !self::$redirectCanonic;
        if (self::$regula !== null && (in_array($cod, self::CODURI_NEGASIT, true) || $aplicatiaAGhicit)) {
            [$tinta, $codRegula] = self::$regula;
            if ($codRegula === 410) {
                if (in_array($cod, self::CODURI_NEGASIT, true)) {
                    // Pagina „nu există" a aplicației rămâne, doar codul spune „dispărut definitiv".
                    http_response_code(410);
                    self::numaraAccesarea(self::$caleCurata);
                    return;
                }
            } elseif ($tinta !== null) {
                // Un rând adăugat mai târziu poate face lanț (A → B, B → C) sau
                // buclă (A → B, B → A): lanțul se urmează până la capăt, într-un
                // singur salt; o buclă nu redirecționează deloc (rămâne 404).
                $tinta = self::tintaFinala(self::$caleCurata, $tinta);
                // O țintă care e pagina unui plugin pe care site-ul nu-l are ar da
                // 404 după redirecționare: atunci adresa veche rămâne 404.
                if ($tinta !== null && self::tintaVizibila($tinta)) {
                    self::redirectioneaza(self::adresaCompleta($tinta, self::$query), 301);
                    self::numaraAccesarea(self::$caleCurata);
                    return;
                }
            }
        }

        // 2. Redirecționarea aplicației (de exemplu produs găsit după un slug
        //    vechi), făcută dintr-un singur salt până la ținta finală.
        if ($cod >= 300 && $cod < 400 && $locatieAplicatie !== '') {
            if (self::$gazdaGresita || self::$caleGresita) {
                $tinta = self::locatieCanonica($locatieAplicatie);
                if ($tinta !== null) {
                    self::redirectioneaza($tinta, in_array($cod, [301, 308], true) ? 301 : $cod);
                }
            }
            return;
        }

        // 3. Pagina există: 301 la adresa curată, pe gazda corectă.
        if ($cod >= 200 && $cod < 300) {
            if (self::$gazdaGresita || self::$caleGresita) {
                self::redirectioneaza(self::adresaCompleta(self::$caleCurata, self::$query), 301);
            }
            return;
        }

        // 4. Altceva (404 fără regulă, 503 mentenanță, eroare): pe gazda greșită
        //    tot mutăm vizitatorul pe gazda corectă; pe gazda corectă lăsăm
        //    răspunsul așa cum e (fără 301 spre o pagină care nu există).
        if (self::$gazdaGresita) {
            self::redirectioneaza(self::adresaCompleta(self::$caleCurata, self::$query), 301);
        }
    }

    /**
     * Ținta unei reguli, urmând lanțul prin alte reguli din hartă (cel mult
     * 10 pași). Null dacă lanțul se întoarce la o adresă deja văzută (buclă),
     * dacă ajunge la o regulă 410 sau dacă ținta finală e chiar adresa cerută.
     */
    public static function tintaFinala(string $cale, string $tinta): ?string
    {
        $vazute = [self::cheie($cale) => true];
        for ($pas = 0; $pas < 10; $pas++) {
            $cheie = self::cheie($tinta);
            if (isset($vazute[$cheie])) {
                return null;
            }
            $vazute[$cheie] = true;
            $urmatoarea = self::regulaPentru($tinta);
            if ($urmatoarea === null) {
                return $tinta;
            }
            if ($urmatoarea[1] === 410 || $urmatoarea[0] === null) {
                return null;
            }
            $tinta = $urmatoarea[0];
        }
        return null;
    }

    /**
     * Ținta e o pagină pe care site-ul o are (nu a unui plugin oprit)? Fără
     * mecanismul de pluginuri încărcat (n-ar trebui), ținta se folosește.
     */
    private static function tintaVizibila(string $tinta): bool
    {
        if (!class_exists(Plugins::class)) {
            return true;
        }
        return Plugins::adresaVizibila($tinta);
    }

    /**
     * Vizitatorul are o sesiune deja existentă (cookie trimis de browser și
     * acceptat) cu produse în coș sau cu contul de client deschis?
     */
    private static function areSesiuneCuCosSauCont(): bool
    {
        if (!self::areSesiuneExistenta()) {
            return false;
        }
        if (!empty($_SESSION['cart']['items'])) {
            return true;
        }
        return class_exists(CustomerAuth::class) && CustomerAuth::check();
    }

    /**
     * Browserul a trimis cookie-ul de sesiune și sesiunea lui e cea folosită
     * acum? (Cu use_strict_mode, un cookie inventat sau expirat primește un id
     * nou, deci nu contează ca sesiune existentă.)
     */
    private static function areSesiuneExistenta(): bool
    {
        $trimis = (string) ($_COOKIE[session_name()] ?? '');
        return $trimis !== '' && $trimis === session_id();
    }

    /**
     * Un 301 către un vizitator fără sesiune (robot, link vechi) pleacă fără
     * cookie-uri: pagina la care ajunge îi pornește sesiunea, ca înainte. Cine
     * are deja o sesiune își primește cookie-urile neatinse.
     */
    private static function faraCookieuriPentruVizitatorNou(): void
    {
        if (!headers_sent() && !self::areSesiuneExistenta()) {
            header_remove('Set-Cookie');
        }
    }

    private static function redirectioneaza(string $locatie, int $cod): void
    {
        while (ob_get_level() >= self::$nivelBuffer && ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($cod);
        self::faraCookieuriPentruVizitatorNou();
        header('Location: ' . $locatie, true);
        header_remove('Content-Length');
        header_remove('ETag');
        header_remove('Last-Modified');
        header_remove('X-Page-Cache');
        // Primele săptămâni: browserele nu țin minte redirecționarea, ca o
        // întoarcere la versiunea anterioară să se vadă imediat.
        header('Cache-Control: ' . self::cacheControlRedirect(), true);
        header('Content-Type: text/html; charset=utf-8', true);
    }

    // ───────────────────────────────────────────────────────────
    // Reguli, publice pentru controller și pentru teste
    // ───────────────────────────────────────────────────────────

    /**
     * 301 de la o variantă a adresei (litere mari, alt fel de scriere) la
     * adresa corectă a unei pagini care există. Doar la GET/HEAD și doar în
     * afara căilor excluse. Întoarce true dacă a trimis redirecționarea.
     * Fără $query, parametrii din adresă se păstrează întocmai; cu $query
     * (de exemplu fără ?category=, mutat în cale), pleacă exact acela.
     */
    public static function redirectCanonic(string $caleCorecta, ?string $query = null): bool
    {
        $metoda = strtoupper((string) ($_SERVER['SEO_REQUEST_METHOD_ORIGINAL'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($metoda !== 'GET' && $metoda !== 'HEAD') {
            return false;
        }
        if (self::oprit()) {
            return false;
        }
        if ($caleCorecta === '' || self::eExclusa($caleCorecta)) {
            return false;
        }
        if ($query === null) {
            [, $query] = self::imparteUri((string) ($_SERVER['REQUEST_URI'] ?? '/'));
        }
        self::$redirectCanonic = true;
        // Pe www, cu „/” final sau „//”, saltul final îl face termina(): acolo
        // trebuie să plece tot acest query, nu cel original (altfel „?category=X”
        // s-ar lipi înapoi la /categorie/x).
        self::$queryCanonic = $query;
        self::faraCookieuriPentruVizitatorNou();
        header('Location: ' . $caleCorecta . ($query !== '' ? '?' . $query : ''), true, 301);
        header('Cache-Control: ' . self::cacheControlRedirect(), true);
        return true;
    }

    /**
     * Calea e una dintre cele care nu se redirecționează niciodată? Lista
     * completă: a nucleului, adresele mereu deschise ale pluginurilor și ce
     * adaugă site-ul (seo.json → cai_excluse).
     */
    public static function eExclusa(string $cale): bool
    {
        return self::eExclusaDupa(self::caiExcluse(), $cale);
    }

    /**
     * Toate căile excluse: lista nucleului, fiecare adresă „mereu deschisă” din
     * catalogul pluginurilor (webhook-uri, întoarceri de plată, notificări ERP,
     * linkurile din emailuri; partea dinaintea primului parametru: `GET
     * /newsletter/unsubscribe/{token}` → `/newsletter/unsubscribe/`) și
     * `cai_excluse` din seo.json-ul site-ului.
     *
     * @return list<string>
     */
    public static function caiExcluse(): array
    {
        if (self::$caiExcluse !== null) {
            return self::$caiExcluse;
        }
        $lista = self::CAI_EXCLUSE;
        foreach (self::caiMereuDeschise() as $cale) {
            $lista[] = $cale;
        }
        if (!class_exists(DateSite::class, false)) {
            require_once __DIR__ . '/DateSite.php';
        }
        foreach ((array) DateSite::seo('cai_excluse', []) as $cale) {
            $cale = strtolower(trim((string) $cale));
            if ($cale !== '' && $cale[0] === '/') {
                $lista[] = $cale;
            }
        }
        return self::$caiExcluse = array_values(array_unique($lista));
    }

    /**
     * Adresele „mereu deschise” din catalogul pluginurilor (config/plugins.php),
     * ca prefixe de cale: tot ce stă înaintea primului parametru.
     *
     * @return list<string>
     */
    public static function caiMereuDeschise(): array
    {
        $fisier = dirname(__DIR__, 2) . '/config/plugins.php';
        $catalog = is_file($fisier) ? require $fisier : [];
        $definitii = [];
        if (is_array($catalog['nucleu'] ?? null)) {
            $definitii[] = $catalog['nucleu'];
        }
        foreach ((array) ($catalog['pluginuri'] ?? []) as $definitie) {
            if (is_array($definitie)) {
                $definitii[] = $definitie;
            }
        }
        $cai = [];
        foreach ($definitii as $definitie) {
            foreach ((array) ($definitie['rute_mereu_deschise'] ?? []) as $text) {
                $text = trim((string) $text);
                if (preg_match('#^(?:GET|POST)\s+(/.*)$#', $text, $m) === 1) {
                    $text = $m[1];
                }
                $text = str_replace('\\.', '.', $text);
                if (str_ends_with($text, '/*')) {
                    $text = substr($text, 0, -1);
                }
                $paranteza = strpos($text, '{');
                if ($paranteza !== false) {
                    // Până la ultimul „/” dinaintea parametrului: „/x/{id}” → „/x/”.
                    $text = substr($text, 0, (int) strrpos(substr($text, 0, $paranteza), '/') + 1);
                }
                $text = strtolower($text);
                if ($text !== '' && $text !== '/' && $text[0] === '/') {
                    $cai[] = $text;
                }
            }
        }
        return array_values(array_unique($cai));
    }

    /** @param list<string> $lista */
    private static function eExclusaDupa(array $lista, string $cale): bool
    {
        $cale = strtolower($cale);
        if ($cale === '' || $cale[0] !== '/') {
            $cale = '/' . $cale;
        }
        foreach ($lista as $prefix) {
            if (str_ends_with($prefix, '/')) {
                if (str_starts_with($cale, $prefix)) {
                    return true;
                }
                continue;
            }
            if ($cale === $prefix || str_starts_with($cale, $prefix . '/')) {
                return true;
            }
        }
        // „//cos" sau „/cos/" ajung la router tot ca „/cos"; „%2F" la fel, decodat.
        $faraDuble = (string) preg_replace('#/{2,}#', '/', rawurldecode($cale));
        return $faraDuble !== $cale && self::eExclusaDupa($lista, $faraDuble);
    }

    /** „/x//y/" → „/x/y"; prima pagină rămâne „/". Codarea literelor rămâne neatinsă. */
    public static function curataCale(string $cale): string
    {
        $cale = (string) preg_replace('#/{2,}#', '/', $cale === '' ? '/' : $cale);
        if ($cale === '' || $cale[0] !== '/') {
            $cale = '/' . $cale;
        }
        if ($cale !== '/') {
            $cale = rtrim($cale, '/');
            if ($cale === '') {
                $cale = '/';
            }
        }
        return $cale;
    }

    /** Cheia din hartă: decodată, cu litere mici, fără bare duble sau finale. */
    public static function cheie(string $cale): string
    {
        $cale = rawurldecode(explode('?', $cale, 2)[0]);
        $cale = function_exists('mb_strtolower') ? mb_strtolower($cale, 'UTF-8') : strtolower($cale);
        return self::curataCale($cale);
    }

    /**
     * Regula din hartă pentru o cale (exactă sau după prefix), sau null.
     *
     * @return array{0: ?string, 1: int}|null
     */
    public static function regulaPentru(string $cale): ?array
    {
        self::incarcaHarta();
        $cheie = self::cheie($cale);
        if (isset(self::$harta[$cheie])) {
            return self::$harta[$cheie];
        }
        foreach (self::$prefixe ?? [] as [$prefix, $tinta, $cod]) {
            if (str_starts_with($cheie, $prefix)) {
                return [$tinta, $cod];
            }
        }
        return null;
    }

    /**
     * Toate regulile, cum sunt în `redirecturi.json` din dosarul site-ului
     * (pentru teste și verificări): [adresa veche, adresa nouă, cod, motiv].
     *
     * @return array{exacte: list<array<int, mixed>>, prefixe: list<array<int, mixed>>}
     */
    public static function reguli(): array
    {
        if (!class_exists(DateSite::class, false)) {
            require_once __DIR__ . '/DateSite.php';
        }
        $date = DateSite::json('redirecturi.json');
        return [
            'exacte' => array_values(array_filter((array) ($date['exacte'] ?? []), 'is_array')),
            'prefixe' => array_values(array_filter((array) ($date['prefixe'] ?? []), 'is_array')),
        ];
    }

    /**
     * O țintă bună: o cale din același site („/…”, nu „//gazdă” și fără schemă),
     * fără spații sau caractere de control. Altfel rândul se ignoră (o hartă nu
     * are voie să trimită vizitatorii pe alt site).
     */
    public static function tintaValida(string $tinta): bool
    {
        return $tinta !== '' && $tinta[0] === '/' && !str_starts_with($tinta, '//') && !str_contains($tinta, '\\')
            && preg_match('/[\x00-\x20\x7f]/', $tinta) !== 1;
    }

    private static function incarcaHarta(): void
    {
        if (self::$harta !== null) {
            return;
        }
        self::$harta = [];
        self::$prefixe = [];
        self::$iduri = [];
        // WP-38: copia hărții din bază (regulile pornite, cu pachetul sincronizat), dacă e a pachetului de acum.
        $copie = self::$faraCopie ? null : self::citesteCopia();
        if ($copie !== null) {
            foreach ((array) ($copie['exacte'] ?? []) as $cheie => $regula) {
                if (!is_array($regula) || !in_array((int) ($regula[1] ?? 0), [301, 410], true)) {
                    continue;
                }
                $tinta = is_string($regula[0] ?? null) ? $regula[0] : null;
                if ((int) $regula[1] === 301 && ($tinta === null || !self::tintaValida($tinta))) {
                    continue;
                }
                self::$harta[(string) $cheie] = [(int) $regula[1] === 410 ? null : $tinta, (int) $regula[1]];
                self::$iduri[(string) $cheie] = (int) ($regula[2] ?? 0);
            }
            foreach ((array) ($copie['prefixe'] ?? []) as $regula) {
                if (!is_array($regula) || !is_string($regula[0] ?? null) || !in_array((int) ($regula[2] ?? 0), [301, 410], true)) {
                    continue;
                }
                $tinta = is_string($regula[1] ?? null) ? $regula[1] : null;
                if ((int) $regula[2] === 301 && ($tinta === null || !self::tintaValida($tinta))) {
                    continue;
                }
                self::$prefixe[] = [(string) $regula[0], (int) $regula[2] === 410 ? null : $tinta, (int) $regula[2], (int) ($regula[3] ?? 0)];
            }
            return;
        }
        // Fără copie (sau veche): regulile pachetului, exact ca înainte; la finalul cererii copia se reface (dacă o scriere
        // n-a eșuat de curând: atunci fără încercare, până trece pauza).
        self::$hartaDeRefacut = !self::$faraCopie && !self::inPauza();
        $reguli = self::reguli();
        foreach ($reguli['exacte'] as $rand) {
            if (!isset($rand[0]) || !is_string($rand[0]) || !str_starts_with($rand[0], '/')) {
                continue;
            }
            $cod = (int) ($rand[2] ?? 301);
            $tinta = isset($rand[1]) && is_string($rand[1]) && $rand[1] !== '' ? $rand[1] : null;
            if ($cod !== 410 && ($tinta === null || !self::tintaValida($tinta))) {
                continue;
            }
            self::$harta[self::cheie($rand[0])] = [$cod === 410 ? null : $tinta, $cod === 410 ? 410 : 301];
        }
        foreach ($reguli['prefixe'] as $rand) {
            if (!isset($rand[0]) || !is_string($rand[0]) || !str_starts_with($rand[0], '/') || $rand[0] === '/') {
                continue;
            }
            $cod = (int) ($rand[2] ?? 410);
            $tinta = isset($rand[1]) && is_string($rand[1]) && $rand[1] !== '' ? $rand[1] : null;
            if ($cod !== 410 && ($tinta === null || !self::tintaValida($tinta))) {
                continue;
            }
            self::$prefixe[] = [strtolower($rand[0]), $cod === 410 ? null : $tinta, $cod === 410 ? 410 : 301, 0];
        }
    }

    // ───────────────────────────────────────────────────────────
    // Copia hărții din bază (WP-38; o scrie App\Support\Redirectionari)
    // ───────────────────────────────────────────────────────────

    /** Fișierul copiei: unul pe dosarul de date al site-ului (două site-uri pe același cod nu-și împart copia). */
    public static function fisierCopie(): string
    {
        if (!class_exists(DateSite::class, false)) {
            require_once __DIR__ . '/DateSite.php';
        }
        return Paths::stocare('cache') . '/redirecturi-' . substr(sha1(DateSite::dosar()), 0, 12) . '.json';
    }

    /** Semnătura pachetului: mărimea și momentul lui `redirecturi.json` (o copie făcută din alt pachet e veche). */
    public static function semnaturaPachet(): string
    {
        if (!class_exists(DateSite::class, false)) {
            require_once __DIR__ . '/DateSite.php';
        }
        $fisier = DateSite::dosar() . '/redirecturi.json';
        clearstatcache(true, $fisier);
        return is_file($fisier) ? (string) filesize($fisier) . ':' . (string) filemtime($fisier) : 'fara';
    }

    /**
     * Copia, dacă e bună: a pachetului de acum și, când e făcută doar din pachet (fără tabel), mai nouă de 5 minute.
     *
     * @return array<string, mixed>|null
     */
    private static function citesteCopia(): ?array
    {
        if (self::$copieMemorie !== null && (self::$copieMemorie['semnatura'] ?? null) === self::semnaturaPachet()) {
            return self::$copieMemorie;
        }
        $fisier = self::fisierCopie();
        $text = is_file($fisier) ? @file_get_contents($fisier) : false;
        $copie = is_string($text) ? json_decode($text, true) : null;
        if (!is_array($copie) || ($copie['semnatura'] ?? null) !== self::semnaturaPachet()) {
            return null;
        }
        // O schimbare a regulilor după care copia n-a fost scrisă: generația ei e mai mică decât cea de acum.
        if ((int) ($copie['generatie'] ?? 0) < self::generatie()) {
            return null;
        }
        if (($copie['sursa'] ?? '') !== 'baza' && (int) ($copie['facuta'] ?? 0) < time() - 300) {
            return null;
        }
        return $copie;
    }

    /**
     * Scrie copia (atomic: un fișier temporar mutat peste cel vechi) și uită harta din memorie.
     *
     * @param array<string, mixed> $copie sursa, facuta, exacte, prefixe
     */
    public static function scrieCopia(array $copie): bool
    {
        $copie['semnatura'] = self::semnaturaPachet();
        $fisier = self::fisierCopie();
        $dosar = dirname($fisier);
        if (!is_dir($dosar)) {
            @mkdir($dosar, 0775, true);
        }
        $temporar = $fisier . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($copie, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ok = is_string($json) && @file_put_contents($temporar, $json, LOCK_EX) !== false && @rename($temporar, $fisier);
        if (!$ok) {
            @unlink($temporar);
        }
        // Cererea de acum folosește harta refăcută, scrisă sau nu.
        self::$copieMemorie = $copie;
        self::semnaleazaEsec(!$ok);
        self::$harta = null;
        self::$prefixe = null;
        self::$iduri = [];
        return $ok;
    }

    /** Fișierul generației: crește la fiecare schimbare a regulilor (o copie cu o generație mai mică e veche). */
    private static function fisierGeneratie(): string
    {
        return self::fisierCopie() . '.generatie';
    }

    public static function generatie(): int
    {
        $text = @file_get_contents(self::fisierGeneratie());
        return is_string($text) ? max(0, (int) trim($text)) : 0;
    }

    public static function scrieGeneratia(int $generatie): void
    {
        $fisier = self::fisierGeneratie();
        $temporar = $fisier . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporar, (string) $generatie) !== false) {
            @rename($temporar, $fisier);
        }
    }

    /**
     * Lacătul copiei (flock exclusiv pe `<copie>.lacat`): refacerea citește baza și scrie copia ținându-l, deci două
     * refaceri nu se încrucișează. Fără drept de scriere: null (refacerea merge, fără lacăt).
     *
     * @return resource|null
     */
    public static function lacat()
    {
        $dosar = dirname(self::fisierCopie());
        if (!is_dir($dosar)) {
            @mkdir($dosar, 0775, true);
        }
        $f = @fopen(self::fisierCopie() . '.lacat', 'c');
        if ($f === false) {
            return null;
        }
        if (!flock($f, LOCK_EX)) {
            fclose($f);
            return null;
        }
        return $f;
    }

    /** @param resource|null $lacat */
    public static function elibereaza($lacat): void
    {
        if (is_resource($lacat)) {
            flock($lacat, LOCK_UN);
            fclose($lacat);
        }
    }

    /** Semnul „copia nu s-a putut scrie”: în dosarul copiei sau, dacă nici acolo nu se poate, în dosarul temporar al serverului. */
    private static function fisierEsec(): string
    {
        $dosar = dirname(self::fisierCopie());
        $nume = 'redirecturi-' . substr(sha1(self::fisierCopie()), 0, 12) . '.esec';
        return is_dir($dosar) && is_writable($dosar) ? $dosar . '/' . $nume : rtrim(sys_get_temp_dir(), '/') . '/andaxi-' . $nume;
    }

    private static function semnaleazaEsec(bool $esec): void
    {
        $fisier = self::fisierEsec();
        if (!$esec) {
            if (is_file($fisier)) {
                @unlink($fisier);
            }
            return;
        }
        if (!self::inPauza()) {
            error_log('ANDAXI SeoRedirects: copia hărții (' . self::fisierCopie() . ') nu s-a putut scrie; cererile folosesc regulile din '
                . 'redirecturi.json până la următoarea încercare (' . self::PAUZA_ESEC . ' s). Verifică dreptul de scriere în storage/cache.');
        }
        @file_put_contents($fisier, (string) time());
    }

    /** O scriere a copiei a eșuat de curând: fără încercări noi (fiecare cerere ar reface harta din bază). */
    private static function inPauza(): bool
    {
        $text = @file_get_contents(self::fisierEsec());
        return is_string($text) && (int) $text > time() - self::PAUZA_ESEC;
    }

    /**
     * Pentru pagina din admin: ultima scriere a copiei a reușit? (Altfel mesajul pentru om.)
     *
     * @return array{la_zi: bool, eroare: ?string}
     */
    public static function stareCopie(): array
    {
        $memorie = self::$copieMemorie;
        self::$copieMemorie = null;
        $laZi = self::citesteCopia() !== null;
        self::$copieMemorie = $memorie;
        $text = @file_get_contents(self::fisierEsec());
        $eroare = is_string($text) && (int) $text > 0
            ? 'Copia hărții nu s-a putut scrie în ' . dirname(self::fisierCopie()) . ' (ultima încercare: ' . date('d.m.Y H:i', (int) $text)
                . '). Până se repară dreptul de scriere, site-ul aplică doar regulile din pachet (redirecturi.json); regulile adăugate aici nu se aplică.'
            : null;
        return ['la_zi' => $laZi, 'eroare' => $eroare];
    }

    /** Copia din fișier e la zi (a pachetului și a generației de acum)? */
    public static function copieLaZi(): bool
    {
        $memorie = self::$copieMemorie;
        self::$copieMemorie = null;
        $laZi = self::citesteCopia() !== null;
        self::$copieMemorie = $memorie;
        return $laZi;
    }

    /** Reface copia din bază (la finalul unei cereri cu copia lipsă sau veche) și reîncarcă harta. */
    private static function refaHarta(): void
    {
        self::$hartaDeRefacut = false;
        try {
            if (class_exists(Redirectionari::class) && class_exists(Database::class)) {
                $config = require dirname(__DIR__, 2) . '/config/app.php';
                Redirectionari::reconstruieste(Database::connection((array) ($config['db'] ?? [])), false);
            }
        } catch (\Throwable) {
            // Rămâne harta pachetului, ca înainte.
        }
        self::$harta = null;
        self::incarcaHarta();
        // O copie care nu s-a putut scrie nu face din fiecare cerere o refacere.
        self::$hartaDeRefacut = false;
    }

    /** Rândul din seo_redirects care s-a aplicat (exact sau prefix): o accesare în plus (după răspuns, fără să-l întârzie). */
    private static function numaraAccesarea(string $cale): void
    {
        try {
            $cheie = self::cheie($cale);
            $id = self::$iduri[$cheie] ?? 0;
            if ($id === 0 && !isset(self::$harta[$cheie])) {
                foreach (self::$prefixe ?? [] as [$prefix, , , $idPrefix]) {
                    if (str_starts_with($cheie, $prefix)) {
                        $id = (int) $idPrefix;
                        break;
                    }
                }
            }
            if ($id > 0 && class_exists(Redirectionari::class) && class_exists(Database::class)) {
                $config = require dirname(__DIR__, 2) . '/config/app.php';
                Redirectionari::numaraAccesare(Database::connection((array) ($config['db'] ?? [])), $id);
            }
        } catch (\Throwable) {
        }
    }

    /** Doar pentru teste: harta doar din pachet (true) sau cu copia din bază (false, ca pe site). */
    public static function faraCopie(bool $da): void
    {
        self::$faraCopie = $da;
        self::$copieMemorie = null;
        self::$harta = null;
        self::$prefixe = null;
        self::$iduri = [];
        self::$hartaDeRefacut = false;
    }

    // ───────────────────────────────────────────────────────────
    // Gazda, schema și adresele
    // ───────────────────────────────────────────────────────────

    /**
     * „https://exemplu.ro" din APP_URL, sau null când stratul de gazdă nu are
     * ce face (APP_URL gol, pe http, localhost sau IP — adică în dezvoltare).
     */
    public static function bazaCanonica(): ?string
    {
        $appUrl = trim((string) Env::get('APP_URL', ''));
        if ($appUrl === '') {
            return null;
        }
        $parti = parse_url($appUrl);
        if (!is_array($parti) || strtolower((string) ($parti['scheme'] ?? '')) !== 'https') {
            return null;
        }
        $gazda = strtolower(rtrim((string) ($parti['host'] ?? ''), '.'));
        if ($gazda === '' || $gazda === 'localhost' || !str_contains($gazda, '.')
            || filter_var($gazda, FILTER_VALIDATE_IP) !== false || str_ends_with($gazda, '.localhost')) {
            return null;
        }
        $port = isset($parti['port']) && (int) $parti['port'] !== 443 ? ':' . (int) $parti['port'] : '';
        return 'https://' . $gazda . $port;
    }

    /**
     * Gazdele care se mută pe cea canonică: varianta cu sau fără www, plus ce
     * e trecut în SEO_GAZDE_ALIAS (separate prin virgulă). Orice altă gazdă
     * (adresa tehnică de la găzduire, un IP) rămâne neatinsă.
     *
     * @return list<string>
     */
    private static function aliasuriGazda(string $gazdaCanonica): array
    {
        $alias = str_starts_with($gazdaCanonica, 'www.')
            ? [substr($gazdaCanonica, 4)]
            : ['www.' . $gazdaCanonica];
        foreach (explode(',', (string) Env::get('SEO_GAZDE_ALIAS', '')) as $extra) {
            $extra = strtolower(trim($extra));
            if ($extra !== '' && $extra !== $gazdaCanonica) {
                $alias[] = $extra;
            }
        }
        return $alias;
    }

    private static function gazdaCerere(): string
    {
        $gazda = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
        $gazda = (string) preg_replace('/:\d+$/', '', $gazda);
        return rtrim($gazda, '.');
    }

    /** Adresa absolută (pe gazda canonică, dacă există) pentru o cale + query. */
    private static function adresaCompleta(string $cale, string $query): string
    {
        $separator = str_contains($cale, '?') ? '&' : '?';
        $adresa = $cale . ($query !== '' ? $separator . $query : '');
        return (self::$bazaCanonica ?? '') . $adresa;
    }

    /**
     * Locația dată de aplicație, mutată pe gazda canonică. Null dacă duce în
     * afara site-ului (atunci o lăsăm cum e). Fără query în locație, rămân
     * parametrii cererii; dar dacă aplicația a ales ea query-ul (redirectCanonic),
     * pleacă exact acela, chiar și gol.
     */
    private static function locatieCanonica(string $locatie): ?string
    {
        $queryImplicit = self::$queryCanonic ?? self::$query;
        if (str_starts_with($locatie, '/') && !str_starts_with($locatie, '//')) {
            [$cale, $query] = self::imparteUri($locatie);
            return self::adresaCompleta(self::curataCale($cale), $query !== '' ? $query : $queryImplicit);
        }
        $parti = parse_url($locatie);
        if (!is_array($parti) || !isset($parti['host'])) {
            return null;
        }
        $gazda = strtolower(rtrim((string) $parti['host'], '.'));
        $gazdaCanonica = self::$bazaCanonica !== null ? (string) parse_url(self::$bazaCanonica, PHP_URL_HOST) : self::gazdaCerere();
        if ($gazda !== $gazdaCanonica && !in_array($gazda, self::aliasuriGazda($gazdaCanonica), true)) {
            return null;
        }
        $query = (string) ($parti['query'] ?? '');
        return self::adresaCompleta(self::curataCale((string) ($parti['path'] ?? '/')), $query !== '' ? $query : $queryImplicit);
    }

    /** @return array{0: string, 1: string} calea brută și query-ul, fără parse_url („//x" nu e o gazdă aici) */
    private static function imparteUri(string $uri): array
    {
        if (preg_match('#^https?://[^/]+(/.*)?$#i', $uri, $m) === 1) {
            $uri = $m[1] ?? '/';
        }
        $parti = explode('?', $uri, 2);
        $cale = (string) ($parti[0] === '' ? '/' : $parti[0]);
        $cale = (string) preg_replace('/#.*$/', '', $cale);
        return [$cale, (string) ($parti[1] ?? '')];
    }

    private static function antet(string $nume): string
    {
        $prefix = strtolower($nume) . ':';
        $valoare = '';
        foreach (headers_list() as $linie) {
            if (str_starts_with(strtolower($linie), $prefix)) {
                $valoare = trim(substr($linie, strlen($prefix)));
            }
        }
        return $valoare;
    }

    private static function oprit(): bool
    {
        $valoare = class_exists(Env::class, false) ? (string) Env::get('SEO_REDIRECTURI', '1') : '1';
        return in_array(strtolower(trim($valoare)), ['0', 'false', 'off', 'nu'], true);
    }

    private static function cacheControlRedirect(): string
    {
        $valoare = trim((string) Env::get('SEO_REDIRECT_CACHE', ''));
        return $valoare !== '' ? $valoare : 'no-store';
    }

    /** Doar pentru teste: uită harta, lista de excluderi și starea cererii. */
    public static function reseteaza(): void
    {
        self::$harta = null;
        self::$prefixe = null;
        self::$iduri = [];
        self::$hartaDeRefacut = false;
        self::$copieMemorie = null;
        self::$caiExcluse = null;
        self::$activ = false;
        self::$redirectCanonic = false;
        self::$regula = null;
    }
}
