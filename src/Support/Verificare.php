<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Site-ul de verificare (docs/VERIFICARE.md): copia pe care se încearcă codul nou înainte
 * să ajungă la un client. E închis publicului din prima cerere după deploy.
 *
 * `poarta()` rulează prima în `public/index.php` (înaintea ei doar `Paths` alege jurnalul erorilor PHP),
 * înaintea fișierelor încărcate, a stratului SEO și a sesiunii:
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
 * Fără parolă trec doar `/robots.txt`, `/health` și pingul monitorizării (`GET`/`HEAD /_andaxi/ping`,
 * `Ping`): pingul îl dă aplicația, care la el face doar `SELECT 1` și citește garda migrărilor, deci și
 * pe baza goală dinaintea primei încărcări (`schema: fara_registru`).
 *
 * Cu `VERIFICARE_WEBHOOKURI_DESCHISE=1` (modul de test al plăților, Etapa 2) trec fără parolă și
 * confirmările de plată (`POST /webhook/…`), doar cu o copie încărcată.
 *
 * Gărzile din cod (WP-08, mai jos: `destinatar()`, `trimiteEmail()`, `erp()`, `verificaFan()`,
 * `verificaCheieStripe()`, `btLivePermis()`, `euplatescPermis()`, `eticheteTertePermise()`,
 * `loginGooglePermis()`, `redirectUploadLipsa()`, `banner()`; lista lor: `garzi()`): fiecare e un rând
 * la locul din cod prin care trece tot, și nu face nimic în afara copiei. Fișierele de lucru stau în
 * `dosar()` = `Paths::stocareSite('verificare')` (azi `storage/verificare` din rădăcina codului), `.env`
 * e `Paths::env()`.
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
    /** Cheia privată a licenței de verificare, în `storage/licenta/` (0600; doar pe server, niciodată în git). */
    public const FISIER_CHEIE_LICENTA = 'verificare-cheie-privata.txt';
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

    /** Rădăcina codului (`Paths::radacina()`). */
    public static function radacina(): string
    {
        self::incarcaPaths();
        return Paths::radacina();
    }

    /** Fișierul `.env` al site-ului (`Paths::env()`). */
    public static function caleEnv(): string
    {
        self::incarcaPaths();
        return Paths::env();
    }

    /**
     * Dosarul de lucru al copiei: `Paths::stocareSite('verificare')`, azi `storage/verificare` din rădăcina codului.
     * Îl face dacă lipsește, cu gardul `.htaccess` (rădăcina trimite oricum totul în public/).
     */
    public static function dosar(): string
    {
        self::incarcaPaths();
        return Paths::stocareSite('verificare');
    }

    /** Dosarul de lucru există și se poate scrie în el? (îl face, cu gardul lui, dacă lipsește) */
    public static function pregatesteDosar(): bool
    {
        $dosar = self::dosar();
        return is_dir($dosar) && is_writable($dosar);
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
        $zile = self::zilePastrare();
        $fisier = self::dosar() . '/incarcat-la';
        if (!is_file($fisier)) {
            return ['stare' => 'lipsa', 'incarcat_la' => null, 'zile' => $zile];
        }
        return self::stareDinRand((string) strtok((string) @file_get_contents($fisier), "\n"), $acum, $zile);
    }

    /** Câte zile poate sta copia: `VERIFICARE_ZILE_PASTRARE`, între 1 și 7 (altfel 7). */
    public static function zilePastrare(): int
    {
        $zile = (int) trim((string) Env::get('VERIFICARE_ZILE_PASTRARE', (string) self::ZILE_MAXIM));
        return $zile < 1 || $zile > self::ZILE_MAXIM ? self::ZILE_MAXIM : $zile;
    }

    /**
     * Starea copiei după primul rând din `incarcat-la` (vezi stareCopie): `ok` sau `expirata` (mai veche de
     * `$zile` zile, de necitit sau cu mai mult de o oră în viitor).
     *
     * @return array{stare: string, incarcat_la: ?int, zile: int}
     */
    public static function stareDinRand(string $rand, int $acum, int $zile): array
    {
        $zile = $zile < 1 || $zile > self::ZILE_MAXIM ? self::ZILE_MAXIM : $zile;
        $rand = trim($rand);
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

    /** `Paths` rulează și înaintea autoloader-ului (poarta e prima din public/index.php; uneltele de pe server). */
    private static function incarcaPaths(): void
    {
        if (!class_exists(Paths::class, false)) {
            require_once __DIR__ . '/Paths.php';
        }
    }

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
        if (!class_exists(Ping::class, false)) {
            require_once __DIR__ . '/Ping.php';
        }
        self::forteazaAntete();

        $cale = self::cale();
        $citire = $metoda === 'GET' || $metoda === 'HEAD';
        if ($citire && $cale === '/robots.txt') {
            self::raspundeText(200, "User-agent: *\nDisallow: /\n", $metoda);
        }
        if ($citire && $cale === Ping::CALE) {
            // Pingul monitorizării, fără parolă, ca /health: îl dă aplicația (public/index.php, imediat după
            // bootstrap: doar SELECT 1 și garda migrărilor), cu antetele porții peste ale lui.
            return;
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
        if ($metoda === 'POST' && self::webhookDeschis($cale)) {
            // Confirmările de plată (`POST /webhook/…`) trec fără parolă doar cu VERIFICARE_WEBHOOKURI_DESCHISE=1
            // (modul de test al plăților, Etapa 2, cu chei de test: gărzile de mai jos refuză oricum alte chei)
            // și doar cu o copie încărcată și neexpirată.
            if (self::pregatesteDosar() && self::stareCopie()['stare'] === 'ok') {
                return;
            }
            self::raspunde(503, 'Copia de verificare lipsește', '<p>Pe acest site nu e încărcată nicio copie.</p>', $metoda, ['Retry-After: 3600']);
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

    /** Fișierul cheii private a licenței de verificare (configureaza.php o face, licenta.php semnează cu ea). */
    public static function fisierCheieLicenta(): string
    {
        self::incarcaPaths();
        return Paths::stocare('licenta') . '/' . self::FISIER_CHEIE_LICENTA;
    }

    /**
     * Cheia privată Ed25519 a licenței de verificare din fișierul ei (base64, 64 de octeți), sau null dacă lipsește,
     * e o legătură sau e stricată. Cu `$publica`: cheia publică potrivită (base64).
     */
    public static function cheiePrivataLicenta(?string &$publica = null): ?string
    {
        $publica = null;
        $fisier = self::fisierCheieLicenta();
        if (is_link($fisier) || !is_file($fisier) || !function_exists('sodium_crypto_sign_publickey_from_secretkey')) {
            return null;
        }
        $cheie = base64_decode(trim((string) @file_get_contents($fisier)), true);
        if (!is_string($cheie) || strlen($cheie) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return null;
        }
        $publica = base64_encode(sodium_crypto_sign_publickey_from_secretkey($cheie));
        return $cheie;
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
    // Gărzile din cod (WP-08)
    // ───────────────────────────────────────────────────────────
    //
    // Al doilea strat, după neutralizarea setărilor la încărcarea copiei (WP-09): chiar dacă în baza copiei a
    // rămas o setare a site-ului adevărat (o cheie Stripe live, adresa ERP-ului, un ID Google Analytics), codul nu
    // trimite nimic în afară. Fiecare gardă e un rând la locul prin care trece tot (docs/VERIFICARE.md, „Gărzile”).
    // Fără SITE_COPIE_VERIFICARE=1 fiecare întoarce exact ce ar fi fost și înainte, deci un site obișnuit (și
    // Bioscem) nu observă nimic. Lista lor, pentru stare.php și sondă: garzi().

    /** Ce se pune în fața subiectului fiecărui email al copiei. */
    public const PREFIX_SUBIECT = '[VERIFICARE] ';
    /** Câte emailuri pleacă pe zi de pe copie (decizia D2); restul se numără ca sărite. */
    public const EMAILURI_PE_ZI = 100;
    /** Gazda unei copii de verificare a ERP-ului (decizia D5), singura spre care poate vorbi copia. */
    public const TIPAR_GAZDA_ERP = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?-verif\.erp\.andaxi\.ro$/';
    /** Id-ul bannerului (o comparație a paginilor cu site-ul adevărat îl scoate după el). */
    public const ID_BANNER = 'verificare-banner';

    /** Emailurile „trimise” cât e pornită simularea (stare.php: unde ar pleca, fără să plece). */
    private static ?array $emailuriSimulate = null;

    /**
     * Destinatarul real al unui email: pe copie, doar proprietarul (`VERIFICARE_EMAIL`), oricui ar fi plecat
     * emailul pe site; altfel adresa dată. Fără o adresă bună a proprietarului emailul nu pleacă deloc.
     */
    public static function destinatar(string $to): string
    {
        if (!self::activ()) {
            return $to;
        }
        $proprietar = trim((string) Env::get('VERIFICARE_EMAIL', ''));
        if (filter_var($proprietar, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('Emailul nu a plecat: pe site-ul de verificare lipsește VERIFICARE_EMAIL (adresa proprietarului).');
        }
        return $proprietar;
    }

    /** Subiectul unui email: pe copie cu „[VERIFICARE] ” în față (o singură dată). */
    public static function subiect(string $subiect): string
    {
        if (!self::activ() || str_starts_with($subiect, self::PREFIX_SUBIECT)) {
            return $subiect;
        }
        return self::PREFIX_SUBIECT . $subiect;
    }

    /**
     * Trimite un email al copiei (OrderMailer::sendHtmlEmail, singurul drum al emailurilor, îl dă aici pe copie):
     * doar la proprietar, cu „[VERIFICARE]” în subiect și cu un rând despre destinatarul de pe site, prin căsuța
     * copiei (`VERIFICARE_SMTP_*`; SMTP-ul, SendGrid-ul și expeditorul din setările bazei nu se folosesc) sau, fără
     * căsuță, ca fișier `.eml` în `dosar()/mail/`. Cel mult EMAILURI_PE_ZI pe zi (fișierul `emailuri-azi.json`);
     * peste ele emailul nu pleacă: excepția îl trece „eșuat” în istoricul emailurilor, cu motivul, și se numără ca
     * sărit. Un newsletter spre 5.000 de abonați anonimizați trimite deci cel mult 100 de emailuri proprietarului.
     *
     * @param callable(string, string, string, string, string, array<string, string>): void $smtp clientul SMTP al
     *        aplicației: (către, subiect, html, numele expeditorului, adresa expeditorului, setările SMTP)
     */
    public static function trimiteEmail(string $to, string $subiect, string $html, string $numeExpeditor, callable $smtp): void
    {
        $catre = self::destinatar($to);
        $subiect = self::subiect($subiect);
        $html = self::cuNotaDestinatar($html, $to);
        $setari = self::setariSmtp();
        $numeExpeditor = str_starts_with($numeExpeditor, 'VERIFICARE') ? $numeExpeditor : trim('VERIFICARE ' . $numeExpeditor);
        $adresaExpeditor = $setari !== null && filter_var($setari['smtp_username'], FILTER_VALIDATE_EMAIL) !== false
            ? $setari['smtp_username'] : $catre;
        if (self::$emailuriSimulate !== null) {
            self::$emailuriSimulate[] = ['catre' => $catre, 'subiect' => $subiect, 'cale' => $setari !== null ? 'smtp' : 'eml',
                'gazda' => $setari['smtp_host'] ?? '', 'html' => $html];
            return;
        }
        if (!self::rezervaEmail()) {
            throw new \RuntimeException('Emailul nu a plecat (sărit): site-ul de verificare a trimis azi deja '
                . self::EMAILURI_PE_ZI . ' emailuri, cât e limita pe zi.');
        }
        if ($setari !== null) {
            $smtp($catre, $subiect, $html, $numeExpeditor, $adresaExpeditor, $setari);
            return;
        }
        self::scrieEml($catre, $subiect, $html, $numeExpeditor, $adresaExpeditor);
    }

    /**
     * Simularea emailurilor (doar pentru stare.php și teste): cu `true`, trimiteEmail doar notează unde ar pleca
     * fiecare email (fără să-l trimită, să-l scrie sau să-l numere); cu `false` se oprește și întoarce ce s-a notat.
     *
     * @return list<array{catre: string, subiect: string, cale: string, gazda: string, html: string}>
     */
    public static function simuleazaEmailuri(bool $porneste): array
    {
        $notate = self::$emailuriSimulate ?? [];
        self::$emailuriSimulate = $porneste ? [] : null;
        return $notate;
    }

    /** Setările SMTP ale căsuței copiei (`VERIFICARE_SMTP_*`); null fără gazdă (emailurile devin fișiere). */
    public static function setariSmtp(): ?array
    {
        $gazda = trim((string) Env::get('VERIFICARE_SMTP_HOST', ''));
        if ($gazda === '') {
            return null;
        }
        $port = (int) trim((string) Env::get('VERIFICARE_SMTP_PORT', '465'));
        return [
            'email_delivery_method' => 'smtp',
            'smtp_host' => $gazda,
            'smtp_port' => (string) ($port > 0 ? $port : 465),
            'smtp_username' => trim((string) Env::get('VERIFICARE_SMTP_UTILIZATOR', '')),
            'smtp_password' => (string) Env::get('VERIFICARE_SMTP_PAROLA', ''),
            'smtp_encryption' => strtolower(trim((string) Env::get('VERIFICARE_SMTP_CRIPTARE', 'ssl'))),
        ];
    }

    /** Câte emailuri au plecat și câte au fost sărite azi (`emailuri-azi.json`). @return array{zi: string, trimise: int, sarite: int} */
    public static function emailuriAzi(): array
    {
        $date = json_decode((string) @file_get_contents(self::dosar() . '/emailuri-azi.json'), true);
        $zi = date('Y-m-d');
        if (!is_array($date) || ($date['zi'] ?? '') !== $zi) {
            return ['zi' => $zi, 'trimise' => 0, 'sarite' => 0];
        }
        return ['zi' => $zi, 'trimise' => (int) ($date['trimise'] ?? 0), 'sarite' => (int) ($date['sarite'] ?? 0)];
    }

    /**
     * Copia poate vorbi cu ERP-ul? Doar cu o copie de verificare a lui (decizia D5): `VERIFICARE_ERP_URL` https pe o
     * gazdă `*-verif.erp.andaxi.ro` și `VERIFICARE_ERP_CHEIE`; adresa și cheia din setări nu se folosesc niciodată.
     * Null = ERP oprit (așa e în Etapa 1). În afara copiei: null (setările site-ului rămân cum sunt).
     *
     * @return array{url: string, cheie: string}|null
     */
    public static function erp(): ?array
    {
        $url = trim((string) Env::get('VERIFICARE_ERP_URL', ''));
        $cheie = trim((string) Env::get('VERIFICARE_ERP_CHEIE', ''));
        if (!self::activ() || $cheie === '' || !self::adresaErpPermisa($url)) {
            return null;
        }
        return ['url' => rtrim($url, '/'), 'cheie' => $cheie];
    }

    /** O adresă pe care copia o poate folosi pentru ERP (https, gazda unei copii de verificare a ERP-ului). */
    public static function adresaErpPermisa(string $url): bool
    {
        $parti = parse_url(trim($url));
        return is_array($parti) && strtolower((string) ($parti['scheme'] ?? '')) === 'https'
            && !isset($parti['user']) && !isset($parti['pass'])
            && preg_match(self::TIPAR_GAZDA_ERP, strtolower((string) ($parti['host'] ?? ''))) === 1;
    }

    /** Garda din ErpClient::request: pe copie, nicio cerere spre alt ERP decât copia lui de verificare. */
    public static function verificaAdresaErp(string $url): void
    {
        if (self::activ() && !self::adresaErpPermisa($url)) {
            throw new \RuntimeException('ERP-ul e oprit pe site-ul de verificare (doar o copie de verificare a ERP-ului, VERIFICARE_ERP_URL); cererea nu a plecat.');
        }
    }

    /** FAN Courier pe copie (decizia D6): oprit, în afară de un cont de test (`VERIFICARE_FAN_TEST=1`). */
    public static function fanPermis(): bool
    {
        return !self::activ() || trim((string) Env::get('VERIFICARE_FAN_TEST', '')) === '1';
    }

    /** Garda din FanCourierGateway: AWB-ul (`$awb`) și orice altă cerere spre FAN (tarif, urmărire, lockere, nomenclator). */
    public static function verificaFan(bool $awb = false): void
    {
        if (self::fanPermis()) {
            return;
        }
        throw new \RuntimeException($awb
            ? 'AWB-urile sunt oprite pe site-ul de verificare; niciun AWB nu a fost emis.'
            : 'FAN Courier e oprit pe site-ul de verificare (fără tarif live, AWB, urmărire); cererea nu a plecat.');
    }

    /** Pe copie Stripe primește doar o cheie secretă de test (`sk_test_…`); în afara copiei, orice cheie. */
    public static function cheieStripePermisa(string $cheie): bool
    {
        return !self::activ() || str_starts_with(trim($cheie), 'sk_test_');
    }

    /** Garda din StripeGateway::request. */
    public static function verificaCheieStripe(string $cheie): void
    {
        if (!self::cheieStripePermisa($cheie)) {
            throw new \RuntimeException('Pe site-ul de verificare Stripe primește doar o cheie de test (sk_test_…); cererea nu a plecat.');
        }
    }

    /** Banca Transilvania live (cu datele de producție)? Pe copie niciodată: doar modul de test. */
    public static function btLivePermis(): bool
    {
        return !self::activ();
    }

    /** EuPlătesc pe copie (decizia D4): oprit, în afară de un cont de test (`VERIFICARE_EUPLATESC_TEST=1`). */
    public static function euplatescPermis(): bool
    {
        return !self::activ() || trim((string) Env::get('VERIFICARE_EUPLATESC_TEST', '')) === '1';
    }

    /** Etichetele terților în pagini (Google Analytics, Tag Manager, Google Ads, Clarity, tawk.to): pe copie niciodată. */
    public static function eticheteTertePermise(): bool
    {
        return !self::activ();
    }

    /** Login-ul clienților cu Google: pe copie oprit, orice ar scrie în setări. */
    public static function loginGooglePermis(): bool
    {
        return !self::activ();
    }

    /**
     * Pozele nu se copiază pe verificare: unde trimite (302) o poză care lipsește de pe disc, `VERIFICARE_UPLOADS_DIN`
     * + aceeași cale. Doar pe copie, doar căi `/uploads/…` și `/og/…` (poza de distribuire) fără „..”, „\”, caractere
     * de control sau „//”, și doar cu o adresă https bună; altfel null (cererea primește 404-ul ei).
     */
    public static function adresaUpload(string $cale): ?string
    {
        if (!self::activ()) {
            return null;
        }
        $baza = rtrim(trim((string) Env::get('VERIFICARE_UPLOADS_DIN', '')), '/');
        $parti = parse_url($baza);
        if (!is_array($parti) || strtolower((string) ($parti['scheme'] ?? '')) !== 'https' || (string) ($parti['host'] ?? '') === ''
            || isset($parti['user']) || isset($parti['pass']) || isset($parti['query']) || isset($parti['fragment'])
            || preg_match('/[\s"\'<>\\\\]/', $baza) === 1) {
            return null;
        }
        $decodat = rawurldecode($cale);
        if ((!str_starts_with($cale, '/uploads/') && !str_starts_with($cale, '/og/')) || strlen($cale) > 2000
            || str_contains($cale, '//') || preg_match('/[\x00-\x20\x7f\\\\"\'<>?#]/', $cale) === 1
            || preg_match('#(^|/)\.\.?(/|$)#', $decodat) === 1 || preg_match('/[\x00-\x1f\x7f\\\\]/', $decodat) === 1) {
            return null;
        }
        return $baza . $cale;
    }

    /** Garda din public/index.php și ImagineDistribuire: o poză lipsă pe copie → 302 spre site-ul sursă. */
    public static function redirectUploadLipsa(string $cale): void
    {
        $adresa = self::adresaUpload($cale);
        if ($adresa === null) {
            return;
        }
        header('Location: ' . $adresa, true, 302);
        header('Cache-Control: ' . self::CACHE);
        exit;
    }

    /**
     * Bannerul copiei, primul în pagină (site: `views/layout.php`; admin: `views/admin/layout.php`); gol în afara
     * copiei. Pe site adresa proprietarului apare ascunsă pe jumătate (paginile publice le vede și cine verifică
     * paginile, decizia D8); în admin, întreagă.
     */
    public static function banner(string $loc = 'site'): string
    {
        if (!self::activ()) {
            return '';
        }
        $email = trim((string) Env::get('VERIFICARE_EMAIL', ''));
        $arata = $loc === 'admin' ? $email : self::emailAscuns($email);
        $text = 'SITE DE VERIFICARE — date anonimizate, plăți de test, emailurile ajung doar la '
            . ($arata !== '' ? $arata : 'proprietar (VERIFICARE_EMAIL lipsește: nu pleacă niciun email)');
        return '<div id="' . self::ID_BANNER . '" role="status" style="position:relative;z-index:2147483000;margin:0;'
            . 'padding:6px 12px;background:#b91c1c;color:#fff;font:600 13px/1.4 system-ui,-apple-system,Segoe UI,Arial,sans-serif;'
            . 'text-align:center;letter-spacing:.01em">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    /** O adresă de email ascunsă pe jumătate: „pr…@ex…ro”. */
    public static function emailAscuns(string $email): string
    {
        $pozitie = strrpos($email, '@');
        if ($pozitie === false || $pozitie === 0) {
            return '';
        }
        $local = substr($email, 0, $pozitie);
        $domeniu = substr($email, $pozitie + 1);
        $punct = strrpos($domeniu, '.');
        $tld = $punct === false ? '' : substr($domeniu, $punct + 1);
        return mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1))) . '…@' . mb_substr($domeniu, 0, 2) . '…' . $tld;
    }

    /** Pe copie, un `POST /webhook/…` care poate trece de poartă fără parolă (`VERIFICARE_WEBHOOKURI_DESCHISE=1`). */
    public static function webhookDeschis(string $cale): bool
    {
        return trim((string) Env::get('VERIFICARE_WEBHOOKURI_DESCHISE', '')) === '1'
            && preg_match('#^/webhook/[a-z0-9][a-z0-9/_-]*$#', $cale) === 1 && !str_contains($cale, '//');
    }

    /**
     * Cele 14 gărzi ale copiei, cum le vede procesul care le întreabă (stare.php în linia de comandă; sonda, în
     * PHP-ul site-ului): fiecare cu DA/NU și un text scurt, fără nicio valoare din .env. Doar întrebări pure (nicio
     * cerere, niciun fișier scris); stare.php cheamă în plus locurile din cod cu date inventate.
     *
     * @return array<string, array{ok: bool, text: string}>
     */
    public static function garzi(): array
    {
        $activ = self::activ();
        $acum = time();
        $zileSetate = trim((string) Env::get('VERIFICARE_ZILE_PASTRARE', ''));
        $zile = self::zilePastrare();
        // Regula expirării, pe date inventate: o copie mai veche de zilele ei (și una de 8 zile, chiar cu 30 cerute)
        // e expirată, una de acum o oră nu.
        $expira = self::stareDinRand((string) ($acum - ($zile * 86400 + 60)), $acum, $zile)['stare'] === 'expirata'
            && self::stareDinRand((string) ($acum - (self::ZILE_MAXIM + 1) * 86400), $acum, 30)['stare'] === 'expirata'
            && self::stareDinRand((string) ($acum - 3600), $acum, $zile)['stare'] === 'ok';
        $email = trim((string) Env::get('VERIFICARE_EMAIL', ''));
        $emailBun = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        $smtp = self::setariSmtp();
        $dosarCopie = self::dosar();
        $erp = self::erp();
        $fanTest = trim((string) Env::get('VERIFICARE_FAN_TEST', '')) === '1';
        $euTest = trim((string) Env::get('VERIFICARE_EUPLATESC_TEST', '')) === '1';
        $btLive = [];
        foreach (['BT_IPAY_LIVE_USER', 'BT_IPAY_LIVE_PASS', 'BT_IPAY_LIVE_CALLBACK_KEY'] as $cheie) {
            if (trim((string) Env::get($cheie, '')) !== '') {
                $btLive[] = $cheie;
            }
        }
        $upload = self::adresaUpload('/uploads/products/proba.webp');
        return [
            'poarta' => ['ok' => $activ && self::problemaPoarta() === null,
                'text' => 'poarta cu parolă (HTTP Basic sau formularul), 10 parole greșite → 429'],
            'noindex' => ['ok' => $activ, 'text' => 'noindex și no-store pe fiecare răspuns'],
            'expirare' => ['ok' => $activ && $expira, 'text' => 'copia se închide singură (503) după ' . $zile . ' zile'
                . ($zileSetate !== '' && (string) $zile !== $zileSetate ? ' (VERIFICARE_ZILE_PASTRARE nu e între 1 și 7: se folosesc 7)' : '')],
            'robots' => ['ok' => $activ, 'text' => 'robots.txt: „Disallow: /”'],
            'emailuri' => ['ok' => $activ && $emailBun && ($smtp !== null || (is_dir($dosarCopie) && is_writable($dosarCopie))),
                'text' => 'emailurile: doar la proprietar, cu „[VERIFICARE]”, cel mult ' . self::EMAILURI_PE_ZI . ' pe zi, '
                    . ($smtp !== null ? 'prin căsuța copiei' : 'ca fișiere în storage/verificare/mail')
                    . ($emailBun ? '' : ' (VERIFICARE_EMAIL lipsește)')],
            'erp' => ['ok' => $activ && !self::adresaErpPermisa('https://erp.andaxi.ro') && ($erp === null || self::adresaErpPermisa($erp['url'])),
                'text' => $erp === null ? 'ERP oprit' : 'ERP doar spre copia lui de verificare (VERIFICARE_ERP_URL)'],
            'fan' => ['ok' => $activ, 'text' => $fanTest ? 'FAN Courier pe contul de test (VERIFICARE_FAN_TEST=1)' : 'FAN Courier oprit: fără AWB, tarif live, urmărire'],
            'stripe' => ['ok' => $activ && !self::cheieStripePermisa('sk_live_x') && self::cheieStripePermisa('sk_test_x'),
                'text' => 'Stripe doar cu o cheie de test (sk_test_…)'],
            'bt' => ['ok' => $activ && !self::btLivePermis() && $btLive === [],
                'text' => 'Banca Transilvania doar în modul de test; datele live nu se citesc'
                    . ($btLive !== [] ? ' (în .env sunt completate: ' . implode(', ', $btLive) . ')' : '')],
            'euplatesc' => ['ok' => $activ, 'text' => $euTest ? 'EuPlătesc pe contul de test (VERIFICARE_EUPLATESC_TEST=1)' : 'EuPlătesc oprit'],
            'etichete' => ['ok' => $activ && !self::eticheteTertePermise(), 'text' => 'fără Google Analytics, Tag Manager, Google Ads, Clarity, tawk.to'],
            'google' => ['ok' => $activ && !self::loginGooglePermis(), 'text' => 'login cu Google oprit'],
            'uploads' => ['ok' => $upload !== null, 'text' => 'pozele lipsă: 302 spre VERIFICARE_UPLOADS_DIN'
                . ($upload === null ? ' (VERIFICARE_UPLOADS_DIN lipsește sau nu e o adresă https)' : '')],
            'banner' => ['ok' => self::banner('site') !== '' && self::banner('admin') !== '', 'text' => 'bannerul „SITE DE VERIFICARE” pe site și în admin'],
        ];
    }

    // ── Intern, gărzile ──

    /** Locul unui email în limita zilei (false: limita s-a atins, emailul se numără ca sărit). */
    private static function rezervaEmail(): bool
    {
        if (!self::pregatesteDosar()) {
            return false;
        }
        $fisier = self::dosar() . '/emailuri-azi.json';
        $nou = !is_file($fisier);
        $f = @fopen($fisier, 'c+');
        if ($f === false) {
            return false;
        }
        if ($nou) {
            @chmod($fisier, 0600);
        }
        flock($f, LOCK_EX);
        $date = json_decode((string) stream_get_contents($f), true);
        $zi = date('Y-m-d');
        if (!is_array($date) || ($date['zi'] ?? '') !== $zi) {
            $date = ['zi' => $zi, 'trimise' => 0, 'sarite' => 0];
        }
        $loc = (int) ($date['trimise'] ?? 0) < self::EMAILURI_PE_ZI;
        $date[$loc ? 'trimise' : 'sarite'] = (int) ($date[$loc ? 'trimise' : 'sarite'] ?? 0) + 1;
        ftruncate($f, 0);
        rewind($f);
        fwrite($f, (string) json_encode($date));
        fflush($f);
        flock($f, LOCK_UN);
        fclose($f);
        return $loc;
    }

    /**
     * Un rând la începutul emailului: cui i-ar fi plecat pe site. Arată adresa doar dacă e una a copiei anonimizate
     * (`@verificare.invalid`) sau a proprietarului; altfel spune doar că e o adresă din afara copiei (o dată
     * neanonimizată se vede așa, fără să ajungă în căsuță).
     */
    private static function cuNotaDestinatar(string $html, string $to): string
    {
        $to = trim($to);
        $proprietar = trim((string) Env::get('VERIFICARE_EMAIL', ''));
        $cine = preg_match('/@verificare\.invalid$/i', $to) === 1 || ($proprietar !== '' && strcasecmp($to, $proprietar) === 0)
            ? $to : 'o adresă din afara copiei anonimizate (ascunsă aici)';
        $nota = '<div style="margin:0 0 12px;padding:8px 12px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;'
            . 'font:13px/1.4 Arial,sans-serif">SITE DE VERIFICARE: pe site, acest email ar fi plecat la '
            . htmlspecialchars($cine, ENT_QUOTES, 'UTF-8') . '.</div>';
        $cuNota = preg_replace('/<body\b[^>]*>/i', '$0' . str_replace(['\\', '$'], ['\\\\', '\\$'], $nota), $html, 1, $inlocuiri);
        return is_string($cuNota) && $inlocuiri === 1 ? $cuNota : $nota . $html;
    }

    /** Emailul ca fișier `.eml` (0600) în `dosar()/mail/`, când copia n-are căsuță de trimitere. */
    private static function scrieEml(string $catre, string $subiect, string $html, string $numeExpeditor, string $adresaExpeditor): void
    {
        self::incarcaPaths();
        $dosar = Paths::stocareSite('verificare/mail');
        if (!is_dir($dosar) || !is_writable($dosar)) {
            throw new \RuntimeException('Emailul nu a plecat: dosarul storage/verificare/mail nu se poate scrie.');
        }
        $antet = static fn (string $text): string => '=?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], ' ', $text)) . '?=';
        $text = 'Date: ' . date('r') . "\r\n"
            . 'From: ' . $antet($numeExpeditor) . ' <' . $adresaExpeditor . ">\r\n"
            . 'To: <' . $catre . ">\r\n"
            . 'Subject: ' . $antet($subiect) . "\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\nX-Verificare: 1\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n");
        $fisier = $dosar . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';
        $umask = umask(0077);
        $scris = @file_put_contents($fisier, $text, LOCK_EX);
        umask($umask);
        if ($scris !== strlen($text)) {
            @unlink($fisier);
            throw new \RuntimeException('Emailul nu a plecat: fișierul .eml nu s-a putut scrie.');
        }
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
            // Gărzile copiei, cum le vede PHP-ul site-ului (doar DA/NU; stare.php le arată lângă ale liniei de comandă).
            'garzi' => array_map(static fn (array $garda): bool => $garda['ok'], self::garzi()),
        ];
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($fapte, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
