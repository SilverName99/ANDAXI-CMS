<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Site-ul de verificare (docs/VERIFICARE.md): copia pe care se încearcă codul nou înainte
 * să ajungă la un client. E închis publicului din prima cerere după deploy.
 *
 * `poarta()` rulează prima în `public/index.php`, înaintea fișierelor încărcate, a
 * stratului SEO și a sesiunii:
 *
 * - **Fără bază configurată** (nici `.env`, nici `DB_DATABASE` în mediu: un deploy proaspăt)
 *   orice cerere primește 503 „neconfigurat”. Altfel aplicația ar merge fără bază, cu
 *   textele implicite din cod (ale Bioscem). Regula e a oricărui site, dar pe un site
 *   adevărat `.env` există mereu, deci nu se schimbă nimic acolo.
 * - **`SITE_COPIE_VERIFICARE=1`**: pe fiecare răspuns `X-Robots-Tag: noindex, nofollow,
 *   noarchive` și `Cache-Control: no-store, private` (și peste ce pune aplicația, ca un
 *   CDN să nu păstreze o pagină văzută cu parolă); `/robots.txt` = `Disallow: /` și
 *   `/health` = „ok”, ambele fără parolă și fără bază (cu o copie încărcată și neexpirată,
 *   `GET /health` îl dă aplicația, tot fără parolă: „ok” + „schema: <stare>”, 503 cu baza
 *   în urma codului, ca pe un site adevărat); orice altceva cere parola porții:
 *   HTTP Basic (citit din `PHP_AUTH_USER`, din antetul `Authorization` sau din
 *   `getallheaders()`) sau, dacă găzduirea nu dă antetul mai departe, formularul din
 *   pagina 401 (cookie semnat, 12 ore). 10 parole greșite de la aceeași adresă în 10
 *   minute → 429 (adresa după `IpClient`, o adresă IPv6 cu toată rețeaua ei /64, păstrată
 *   doar ca amprentă). Cu parola bună, până la prima încărcare (sau după
 *   `VERIFICARE_ZILE_PASTRARE` zile, cel mult 7) copia lipsește sau a expirat → 503; abia
 *   apoi cererea ajunge la aplicație.
 * - Altfel nu face nimic: nicio ieșire, niciun antet.
 *
 * Gărzile din cod (emailuri, plăți, ERP, FAN, etichete de urmărire, banner) vin în WP-08,
 * tot aici (`destinatar()` …). Fișierele de lucru stau în `dosar()`: deocamdată
 * `storage/verificare` din rădăcina codului; WP-07 îl mută în `Paths::stocareSite()`, iar
 * calea `.env` în `Paths::env()`.
 */
final class Verificare
{
    /** Cookie-ul formularului porții. */
    public const COOKIE = 'verificare_poarta';
    /** Utilizatorul porții scris de configureaza.php. */
    public const UTILIZATOR_IMPLICIT = 'verificare';
    public const ROBOTI = 'noindex, nofollow, noarchive';
    public const CACHE = 'no-store, private';
    public const CALE_INTRARE = '/_verificare/intrare';
    public const CALE_IESIRE = '/_verificare/iesire';
    /** Răspunsul cu faptele găzduirii, pentru stare.php (doar cu jetonul de unică folosință). */
    public const CALE_SONDA = '/_verificare/sonda';
    public const COOKIE_SONDA = 'verificare_sonda';
    public const ESECURI_MAXIM = 10;
    public const ESECURI_FEREASTRA = 600;
    /** Câte zile poate sta o copie (decizia D1: cel mult 7). */
    public const ZILE_MAXIM = 7;
    public const DURATA_COOKIE = 43200;
    public const DURATA_SONDA = 120;
    /** Câte adrese ține fișierul de încercări greșite, ca să nu crească la nesfârșit. */
    private const ESECURI_ADRESE_MAXIM = 5000;

    // ───────────────────────────────────────────────────────────
    // Starea site-ului
    // ───────────────────────────────────────────────────────────

    /** Site-ul e copia de verificare? (`SITE_COPIE_VERIFICARE=1`, din `.env` sau din mediu) */
    public static function activ(): bool
    {
        return trim((string) Env::get('SITE_COPIE_VERIFICARE', '')) === '1';
    }

    /** Are aplicația o bază configurată? (`DB_DATABASE` nevid) */
    public static function configurat(): bool
    {
        return trim((string) Env::get('DB_DATABASE', '')) !== '';
    }

    public static function radacina(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Fișierul `.env` al site-ului (WP-07: `Paths::env()`). */
    public static function caleEnv(): string
    {
        return self::radacina() . '/.env';
    }

    /** Dosarul de lucru al copiei (temporar; WP-07: `Paths::stocareSite('verificare')`). */
    public static function dosar(): string
    {
        return dirname(__DIR__, 2) . '/storage/verificare';
    }

    /** Face dosarul de lucru (cu gardul `.htaccess`) dacă lipsește; întoarce dacă se poate scrie în el. */
    public static function pregatesteDosar(): bool
    {
        $dosar = self::dosar();
        if (!is_dir($dosar)) {
            @mkdir($dosar, 0770, true);
        }
        if (!is_dir($dosar) || !is_writable($dosar)) {
            return false;
        }
        // .htaccess-ul din rădăcină trimite deja totul în public/; un gard în plus nu strică.
        $gard = $dosar . '/.htaccess';
        if (!is_file($gard)) {
            @file_put_contents($gard, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        return true;
    }

    /**
     * Copia încărcată: `ok`, `lipsa` (încă nu s-a încărcat nimic sau a fost ștearsă) sau
     * `expirata` (mai veche decât `VERIFICARE_ZILE_PASTRARE` zile, cel mult 7; o dată
     * care nu se înțelege sau e în viitor contează ca expirată). Data încărcării e primul
     * rând din `dosar()/incarcat-la` (ISO 8601 sau secunde Unix), scris de încărcare (WP-09).
     *
     * @return array{stare: string, incarcat_la: ?int, zile: int}
     */
    public static function stareCopie(?int $acum = null): array
    {
        $acum ??= time();
        $zile = (int) trim((string) Env::get('VERIFICARE_ZILE_PASTRARE', (string) self::ZILE_MAXIM));
        if ($zile < 1 || $zile > self::ZILE_MAXIM) {
            $zile = self::ZILE_MAXIM;
        }
        $fisier = self::dosar() . '/incarcat-la';
        if (!is_file($fisier)) {
            return ['stare' => 'lipsa', 'incarcat_la' => null, 'zile' => $zile];
        }
        $rand = trim((string) strtok((string) @file_get_contents($fisier), "\n"));
        $moment = preg_match('/^\d{9,11}$/', $rand) === 1 ? (int) $rand : strtotime($rand);
        if ($moment === false || $moment <= 0 || $moment > $acum + 3600 || $acum - $moment > $zile * 86400) {
            return ['stare' => 'expirata', 'incarcat_la' => $moment === false ? null : $moment, 'zile' => $zile];
        }
        return ['stare' => 'ok', 'incarcat_la' => $moment, 'zile' => $zile];
    }

    /** Ce lipsește din configurarea porții (null = nimic); doar numele cheilor, fără valori. */
    public static function problemaPoarta(): ?string
    {
        if (trim((string) Env::get('VERIFICARE_POARTA_UTILIZATOR', '')) === '') {
            return 'VERIFICARE_POARTA_UTILIZATOR lipsește';
        }
        $hash = trim((string) Env::get('VERIFICARE_POARTA_HASH', ''));
        $info = $hash === '' ? [] : password_get_info($hash);
        if (empty($info['algo'])) {
            return 'VERIFICARE_POARTA_HASH lipsește sau nu e un hash de parolă';
        }
        return null;
    }

    // ───────────────────────────────────────────────────────────
    // Poarta
    // ───────────────────────────────────────────────────────────

    /** Rulează prima în public/index.php (vezi capul clasei). */
    public static function poarta(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (!class_exists(Env::class, false)) {
            require_once __DIR__ . '/Env.php';
        }
        Env::load(self::caleEnv());
        $metoda = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if (!self::configurat()) {
            self::raspunde(503, 'Site neconfigurat', '<p>Site-ul nu este încă configurat.</p>', $metoda, ['Retry-After: 3600']);
        }
        if (!self::activ()) {
            return;
        }
        if (!class_exists(IpClient::class, false)) {
            require_once __DIR__ . '/IpClient.php';
        }
        self::forteazaAntete();

        $cale = self::cale();
        $citire = $metoda === 'GET' || $metoda === 'HEAD';
        if ($citire && $cale === '/robots.txt') {
            self::raspundeText(200, "User-agent: *\nDisallow: /\n", $metoda);
        }
        if ($citire && $cale === '/health') {
            // Cu o copie încărcată (și neexpirată) un GET îl dă aplicația, fără parolă, ca pe un site adevărat:
            // „ok” + „schema: <stare>”, 503 cu baza în urma codului (docs/MIGRARI.md, „Garda”). Așa o
            // repetiție a deploy-ului vede pe verificare exact ce ar vedea pe site. Fără copie (și la HEAD,
            // pe care routerul aplicației nu-l are): „ok” de aici.
            if ($metoda === 'GET' && self::stareCopie()['stare'] === 'ok') {
                return;
            }
            self::raspundeText(200, 'ok', $metoda);
        }
        if (self::problemaPoarta() !== null || !self::pregatesteDosar()) {
            // Fără parolă sau fără dosarul de lucru nu se poate deschide nimic (motivul îl arată stare.php).
            self::raspunde(503, 'Site neconfigurat', '<p>Site-ul nu este încă configurat.</p>', $metoda, ['Retry-After: 3600']);
        }
        if ($metoda === 'GET' && $cale === self::CALE_SONDA) {
            $jeton = self::jetonSonda();
            if ($jeton !== null) {
                self::raspundeSonda($jeton);
            }
        }

        $ip = IpClient::adresa();
        $asteapta = self::secundeBlocare($ip);
        if ($asteapta > 0) {
            self::raspunde(429, 'Prea multe încercări', '<p>Prea multe parole greșite de la această adresă. Încearcă din nou peste '
                . (int) ceil($asteapta / 60) . ' minute.</p>', $metoda, ['Retry-After: ' . $asteapta]);
        }
        if ($cale === self::CALE_INTRARE && $metoda === 'POST') {
            self::intrare($ip);
        }
        if ($cale === self::CALE_IESIRE) {
            self::stergeCookie();
            header('Location: /', true, 303);
            exit;
        }

        $rezultat = self::autentificare();
        if ($rezultat !== 'ok') {
            if ($rezultat === 'gresit') {
                self::noteazaEsec($ip);
            }
            self::cerePoarta($metoda, $rezultat === 'gresit' ? 'Utilizator sau parolă greșite.' : '', true);
        }

        $copie = self::stareCopie();
        if ($copie['stare'] === 'lipsa') {
            self::raspunde(503, 'Copia de verificare lipsește', '<p>Pe acest site nu s-a încărcat încă nicio copie (sau a fost '
                . 'ștearsă). Până la prima încărcare site-ul nu arată nimic altceva.</p>', $metoda, ['Retry-After: 3600']);
        }
        if ($copie['stare'] === 'expirata') {
            self::raspunde(503, 'Copia de verificare a expirat', '<p>Copia are mai mult de ' . $copie['zile'] . ' zile (sau data '
                . 'încărcării nu se poate citi). Se șterge și se încarcă din nou (docs/VERIFICARE.md).</p>', $metoda, ['Retry-After: 3600']);
        }
        // Cererea ajunge la aplicație.
    }

    /**
     * Utilizatorul și parola trimise prin HTTP Basic, de oriunde le-a lăsat serverul web:
     * `PHP_AUTH_USER`/`PHP_AUTH_PW`, antetul `Authorization` (și varianta lui după o
     * rescriere internă, `REDIRECT_…`) sau `getallheaders()` (pe care se bazează și
     * Biovitality pe Hostinger). Un antet Basic stricat dă utilizator și parolă goale (deci
     * o încercare greșită); alt fel de antet (Bearer) nu e al porții: null.
     *
     * @param array<string, mixed> $antete getallheaders() cu numele cu litere mici
     * @return array{utilizator: string, parola: string, sursa: string}|null
     */
    public static function credentialeBasic(array $server, array $antete): ?array
    {
        if (isset($server['PHP_AUTH_USER'])) {
            return ['utilizator' => (string) $server['PHP_AUTH_USER'], 'parola' => (string) ($server['PHP_AUTH_PW'] ?? ''), 'sursa' => 'PHP_AUTH_USER'];
        }
        $surse = [];
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'REDIRECT_REDIRECT_HTTP_AUTHORIZATION'] as $cheie) {
            $surse[$cheie] = (string) ($server[$cheie] ?? '');
        }
        $surse['getallheaders'] = (string) ($antete['authorization'] ?? '');
        foreach ($surse as $sursa => $valoare) {
            $valoare = trim($valoare);
            if ($valoare === '' || preg_match('/^Basic\s+(\S*)$/i', $valoare, $m) !== 1) {
                continue;
            }
            $decodat = base64_decode($m[1], true);
            if ($decodat === false || !str_contains($decodat, ':')) {
                return ['utilizator' => '', 'parola' => '', 'sursa' => $sursa];
            }
            [$utilizator, $parola] = explode(':', $decodat, 2);
            return ['utilizator' => $utilizator, 'parola' => $parola, 'sursa' => $sursa];
        }
        return null;
    }

    /** Valoarea cookie-ului porții, valabilă până la `$expira` (semnată cu o cheie din hash-ul parolei). */
    public static function cookieNou(string $utilizator, string $hash, int $expira): string
    {
        return 'v1.' . $expira . '.' . hash_hmac('sha256', 'v1|' . $expira . '|' . $utilizator, self::cheie($hash, 'cookie'));
    }

    public static function cookieValid(string $valoare, string $utilizator, string $hash, int $acum): bool
    {
        $parti = explode('.', $valoare);
        if (count($parti) !== 3 || $parti[0] !== 'v1' || preg_match('/^\d{1,12}$/', $parti[1]) !== 1) {
            return false;
        }
        $expira = (int) $parti[1];
        if ($expira < $acum || $expira > $acum + self::DURATA_COOKIE + 60) {
            return false;
        }
        return hash_equals(self::cookieNou($utilizator, $hash, $expira), $valoare);
    }

    /**
     * Gazda site-ului dacă rădăcina codului e exact `<acasă>/domains/<gazdă>/public_html`
     * (un site separat în hPanel); altfel null: `~/public_html` (site-ul principal al
     * contului) sau un subdosar din `public_html`-ul altui site (de exemplu un subdomeniu
     * pus de hPanel în `domains/<alt-site>/public_html/<sub>`) nu sunt acceptate.
     */
    public static function dosarSite(string $radacina, string $acasa): ?string
    {
        $radacina = realpath($radacina);
        $acasa = realpath($acasa);
        if ($radacina === false || $acasa === false) {
            return null;
        }
        $prefix = rtrim($acasa, '/') . '/domains/';
        if (!str_starts_with($radacina, $prefix)) {
            return null;
        }
        $parti = explode('/', substr($radacina, strlen($prefix)));
        if (count($parti) !== 2 || $parti[1] !== 'public_html') {
            return null;
        }
        $gazda = strtolower($parti[0]);
        $eticheta = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        if (strlen($gazda) > 253 || preg_match('/^' . $eticheta . '(?:\.' . $eticheta . ')+$/', $gazda) !== 1) {
            return null;
        }
        return $gazda;
    }

    /**
     * Cheile unui `.env`, citite exact cum le citește `Env::load()` (rânduri `CHEIE=valoare`,
     * fără comentarii, spațiile și ghilimelele de la capete scoase). configureaza.php
     * verifică cu ea că fiecare valoare scrisă se citește înapoi la fel.
     *
     * @return array<string, string>
     */
    public static function citesteEnv(string $text): array
    {
        $chei = [];
        foreach (preg_split('/\R/', $text) ?: [] as $rand) {
            $rand = trim($rand);
            if ($rand === '' || str_starts_with($rand, '#') || !str_contains($rand, '=')) {
                continue;
            }
            [$nume, $valoare] = explode('=', $rand, 2);
            $chei[trim($nume)] = trim(trim($valoare), "\"'");
        }
        return $chei;
    }

    // ───────────────────────────────────────────────────────────
    // Intern
    // ───────────────────────────────────────────────────────────

    /** Antetele copiei, acum și din nou chiar înainte de trimitere (peste ce pune aplicația). */
    private static function forteazaAntete(): void
    {
        $aplica = static function (): void {
            header('X-Robots-Tag: ' . self::ROBOTI, true);
            header('Cache-Control: ' . self::CACHE, true);
            // Cache-ul serverului LiteSpeed (Hostinger) nu păstrează nimic de aici.
            header('X-LiteSpeed-Cache-Control: no-cache', true);
        };
        $aplica();
        header_register_callback($aplica);
    }

    /**
     * Un câmp al cererii (`$_GET`, `$_POST`, `$_COOKIE`) ca text. O listă (`x[]=…`, `x[a]=…`),
     * pe care o poate trimite oricine, contează ca un câmp gol: nici avertisment în jurnal,
     * nici altceva decât un răspuns de „lipsă” sau „greșit”.
     *
     * @param array<array-key, mixed> $sursa
     */
    private static function parametru(array $sursa, string $cheie, string $implicit = ''): string
    {
        $valoare = $sursa[$cheie] ?? $implicit;
        return is_string($valoare) ? $valoare : '';
    }

    /** Calea cererii, fără parametri și fără decodare (o formă neobișnuită cade la poartă). */
    private static function cale(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $pozitie = strpos($uri, '?');
        $cale = $pozitie === false ? $uri : substr($uri, 0, $pozitie);
        return $cale === '' ? '/' : $cale;
    }

    /** 'ok', 'lipsa' (nimic trimis) sau 'gresit' (utilizator sau parolă greșite). */
    private static function autentificare(): string
    {
        $utilizator = trim((string) Env::get('VERIFICARE_POARTA_UTILIZATOR', ''));
        $hash = trim((string) Env::get('VERIFICARE_POARTA_HASH', ''));
        $cookie = self::parametru($_COOKIE, self::COOKIE);
        if ($cookie !== '' && self::cookieValid($cookie, $utilizator, $hash, time())) {
            return 'ok';
        }
        $basic = self::credentialeBasic($_SERVER, self::anteteCerere());
        if ($basic === null) {
            return 'lipsa';
        }
        return self::potrivire($basic['utilizator'], $basic['parola'], $utilizator, $hash) ? 'ok' : 'gresit';
    }

    private static function potrivire(string $utilizatorDat, string $parolaData, string $utilizator, string $hash): bool
    {
        // Parola se verifică mereu, ca durata să nu spună dacă utilizatorul era bun.
        $parolaBuna = password_verify($parolaData, $hash);
        return hash_equals($utilizator, $utilizatorDat) && $parolaBuna;
    }

    /** Formularul porții (POST /_verificare/intrare): cookie semnat și înapoi la pagina cerută. */
    private static function intrare(string $ip): void
    {
        $utilizator = trim((string) Env::get('VERIFICARE_POARTA_UTILIZATOR', ''));
        $hash = trim((string) Env::get('VERIFICARE_POARTA_HASH', ''));
        $inapoi = self::caleInapoi(self::parametru($_POST, 'inapoi', '/'));
        if (!self::potrivire(self::parametru($_POST, 'utilizator'), self::parametru($_POST, 'parola'), $utilizator, $hash)) {
            self::noteazaEsec($ip);
            // Fără provocarea Basic: cine folosește formularul nu mai primește și fereastra browserului.
            self::cerePoarta('POST', 'Utilizator sau parolă greșite.', false, $inapoi);
        }
        $expira = time() + self::DURATA_COOKIE;
        setcookie(self::COOKIE, self::cookieNou($utilizator, $hash, $expira), [
            'expires' => $expira,
            'path' => '/',
            'secure' => self::eHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        header('Location: ' . $inapoi, true, 303);
        exit;
    }

    private static function stergeCookie(): void
    {
        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => self::eHttps(), 'httponly' => true, 'samesite' => 'Lax']);
    }

    /** O cale locală de întoarcere după formular; orice altceva → „/”. */
    private static function caleInapoi(string $cale): string
    {
        if ($cale === '' || $cale[0] !== '/' || str_starts_with($cale, '//') || str_starts_with($cale, '/_verificare/')
            || preg_match('/[\x00-\x1f\x7f\\\\]/', $cale) === 1 || strlen($cale) > 2000) {
            return '/';
        }
        return $cale;
    }

    /** 401: fereastra Basic a browserului (în modul `basic`) și, în pagină, formularul. */
    private static function cerePoarta(string $metoda, string $mesaj, bool $cuBasic, ?string $inapoi = null): void
    {
        $inapoi ??= self::caleInapoi((string) ($_SERVER['REQUEST_URI'] ?? '/'));
        $antete = [];
        if ($cuBasic && strtolower(trim((string) Env::get('VERIFICARE_POARTA_MOD', 'basic'))) !== 'formular') {
            $antete[] = 'WWW-Authenticate: Basic realm="Site de verificare", charset="UTF-8"';
        }
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $continut = '<p>Site-ul e închis publicului. Intră cu utilizatorul și parola porții.</p>'
            . ($mesaj !== '' ? '<p class="eroare">' . $e($mesaj) . '</p>' : '')
            . '<form method="post" action="' . self::CALE_INTRARE . '">'
            . '<input type="hidden" name="inapoi" value="' . $e($inapoi) . '">'
            . '<label>Utilizator <input name="utilizator" autocomplete="username" required></label>'
            . '<label>Parola <input type="password" name="parola" autocomplete="current-password" required></label>'
            . '<button type="submit">Intră</button></form>';
        self::raspunde(401, 'Site de verificare', $continut, $metoda, $antete);
    }

    /** @param list<string> $antete */
    private static function raspunde(int $cod, string $titlu, string $continut, string $metoda, array $antete = []): void
    {
        http_response_code($cod);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: ' . self::ROBOTI);
        header('Cache-Control: ' . self::CACHE);
        foreach ($antete as $antet) {
            header($antet);
        }
        if ($metoda !== 'HEAD') {
            $titluHtml = htmlspecialchars($titlu, ENT_QUOTES, 'UTF-8');
            echo '<!doctype html><html lang="ro"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<meta name="robots" content="noindex, nofollow"><title>' . $titluHtml . '</title>'
                . '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;color:#1f2937}'
                . 'h1{font-size:1.4rem}label{display:block;margin:.75rem 0}input{display:block;width:100%;padding:.5rem;'
                . 'box-sizing:border-box}button{margin-top:.5rem;padding:.5rem 1.25rem}.eroare{color:#b91c1c}</style></head>'
                . '<body><h1>' . $titluHtml . '</h1>' . $continut . '</body></html>';
        }
        exit;
    }

    private static function raspundeText(int $cod, string $text, string $metoda): void
    {
        http_response_code($cod);
        header('Content-Type: text/plain; charset=utf-8');
        if ($metoda !== 'HEAD') {
            echo $text;
        }
        exit;
    }

    /** @return array<string, string> getallheaders() cu numele cu litere mici */
    private static function anteteCerere(): array
    {
        $antete = [];
        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $nume => $valoare) {
                $antete[strtolower((string) $nume)] = (string) $valoare;
            }
        }
        return $antete;
    }

    private static function eHttps(): bool
    {
        return (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) ($_SERVER['HTTPS'] ?? '')) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /** O cheie derivată din hash-ul parolei porții (o parolă nouă anulează cookie-urile și amprentele). */
    private static function cheie(string $hash, string $scop): string
    {
        return hash('sha256', 'verificare-poarta|' . $scop . '|' . $hash, true);
    }

    // ── Încercările greșite (storage/verificare/poarta-esecuri.json) ──

    private static function amprentaIp(string $ip): string
    {
        // Adresa nu se scrie pe disc; doar o amprentă cu cheia porții. O adresă IPv6 contează cu
        // toată rețeaua ei /64 (IpClient::cheieLimita): altfel, schimbând adresa în /64-ul lui la
        // fiecare încercare, cineva n-ar ajunge niciodată la 10.
        return substr(hash_hmac('sha256', IpClient::cheieLimita($ip), self::cheie(trim((string) Env::get('VERIFICARE_POARTA_HASH', '')), 'ip')), 0, 32);
    }

    /** Câte secunde mai e blocată adresa (0 = deloc). */
    private static function secundeBlocare(string $ip): int
    {
        $fisier = self::dosar() . '/poarta-esecuri.json';
        if (!is_file($fisier)) {
            return 0;
        }
        $date = json_decode((string) @file_get_contents($fisier), true);
        $momente = is_array($date) ? (array) ($date[self::amprentaIp($ip)] ?? []) : [];
        $acum = time();
        $recente = array_values(array_filter(array_map('intval', $momente), static fn (int $t): bool => $t > $acum - self::ESECURI_FEREASTRA));
        if (count($recente) < self::ESECURI_MAXIM) {
            return 0;
        }
        sort($recente);
        return max(1, $recente[count($recente) - self::ESECURI_MAXIM] + self::ESECURI_FEREASTRA - $acum);
    }

    private static function noteazaEsec(string $ip): void
    {
        $fisier = self::dosar() . '/poarta-esecuri.json';
        $nou = !is_file($fisier);
        $f = @fopen($fisier, 'c+');
        if ($f === false) {
            return;
        }
        if ($nou) {
            @chmod($fisier, 0600);
        }
        flock($f, LOCK_EX);
        $date = json_decode((string) stream_get_contents($f), true);
        $date = is_array($date) ? $date : [];
        $acum = time();
        $cheie = self::amprentaIp($ip);
        $curat = [];
        foreach ($date as $amprenta => $momente) {
            $pastrate = array_values(array_filter(array_map('intval', (array) $momente), static fn (int $t): bool => $t > $acum - self::ESECURI_FEREASTRA));
            if ($pastrate !== []) {
                $curat[(string) $amprenta] = array_slice($pastrate, -self::ESECURI_MAXIM * 2);
            }
        }
        $curat[$cheie][] = $acum;
        if (count($curat) > self::ESECURI_ADRESE_MAXIM) {
            uasort($curat, static fn (array $a, array $b): int => max($b) <=> max($a));
            $curat = array_slice($curat, 0, self::ESECURI_ADRESE_MAXIM, true);
        }
        ftruncate($f, 0);
        rewind($f);
        fwrite($f, (string) json_encode($curat));
        fflush($f);
        flock($f, LOCK_UN);
        fclose($f);
    }

    // ── Sonda pentru stare.php ──

    /**
     * Jetonul sondei, dacă cererea îl are pe cel scris de stare.php (`dosar()/sonda.json`:
     * amprenta lui și ora la care expiră, 2 minute). Se folosește o singură dată.
     */
    private static function jetonSonda(): ?string
    {
        $jeton = self::parametru($_GET, 'sonda');
        $fisier = self::dosar() . '/sonda.json';
        if (preg_match('/^[a-f0-9]{64}$/', $jeton) !== 1 || !is_file($fisier)) {
            return null;
        }
        $date = json_decode((string) @file_get_contents($fisier), true);
        if (!is_array($date) || (int) ($date['expira'] ?? 0) < time()
            || !hash_equals((string) ($date['amprenta'] ?? ''), hash('sha256', $jeton))) {
            return null;
        }
        @unlink($fisier);
        return $jeton;
    }

    /** Faptele găzduirii văzute de PHP-ul site-ului: doar da/nu, versiuni și clase de adrese. */
    private static function raspundeSonda(string $jeton): void
    {
        $antete = self::anteteCerere();
        $basic = self::credentialeBasic($_SERVER, $antete);
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $xff = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        $hopuri = $xff === '' ? [] : array_map('trim', explode(',', $xff));
        $oprite = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $functii = [];
        foreach (['proc_open', 'exec', 'shell_exec', 'symlink', 'set_time_limit'] as $functie) {
            $functii[$functie] = function_exists($functie) && !in_array($functie, $oprite, true);
        }
        $extensii = [];
        foreach (['pdo_mysql', 'sodium', 'curl', 'mbstring', 'gd', 'intl', 'openssl', 'zip'] as $extensie) {
            $extensii[$extensie] = extension_loaded($extensie);
        }
        $proto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        $fapte = [
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'server' => substr((string) preg_replace('/[^A-Za-z0-9 .\/_()-]/', '', (string) ($_SERVER['SERVER_SOFTWARE'] ?? '')), 0, 60),
            'basic' => $basic !== null && $basic['utilizator'] === 'sonda' && hash_equals($jeton, $basic['parola']) ? $basic['sursa'] : '',
            'cookie' => hash_equals($jeton, self::parametru($_COOKIE, self::COOKIE_SONDA)),
            'antet' => hash_equals($jeton, (string) ($antete['x-verificare-sonda'] ?? '')),
            'remote_addr' => IpClient::clasa($remote),
            'remote_addr_e_server' => $remote !== '' && $remote === (string) ($_SERVER['SERVER_ADDR'] ?? ''),
            'xff' => count($hopuri),
            'xff_ultima' => $hopuri === [] ? '' : IpClient::clasa((string) preg_replace('/:\d+$/', '', (string) end($hopuri))),
            'x_real_ip' => trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? '')) !== '',
            'cf_connecting_ip' => trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '')) !== '',
            'forwarded' => trim((string) ($_SERVER['HTTP_FORWARDED'] ?? '')) !== '',
            'https' => (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) ($_SERVER['HTTPS'] ?? '')) !== 'off'),
            'x_forwarded_proto' => in_array($proto, ['http', 'https'], true) ? $proto : '',
            'max_execution_time' => (string) ini_get('max_execution_time'),
            'memory_limit' => (string) ini_get('memory_limit'),
            'functii' => $functii,
            'extensii' => $extensii,
            'imagewebp' => function_exists('imagewebp'),
        ];
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($fapte, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
