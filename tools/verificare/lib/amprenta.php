<?php

declare(strict_types=1);

/**
 * Amprenta paginilor unui site (WP-11, G3; docs/VERIFICARE.md, „Amprenta paginilor”): partea lui
 * tools/verificare/amprenta-site.php. Cere paginile publice ale unui site adevărat (live sau de verificare) și scrie, pentru
 * fiecare adresă, ce se vede din afară: codul, unde trimite, felul răspunsului, amprenta corpului normalizat, titlul,
 * robots, canonical, H1, tipurile JSON-LD, numărul de linkuri interne, formularele, X-Robots-Tag și poza de distribuire.
 * Două amprente (de exemplu bioscem.ro pe codul vechi și site-ul de verificare pe codul comun, cu aceeași bază) se compară
 * apoi câmp cu câmp (`--compara`).
 *
 * Ce cere (doar GET, fără cookie-uri, `User-Agent: ANDAXI-audit/1.0`, fără să urmeze redirecționările — `Location` se
 * scrie —, cu o pauză între cereri, cel puțin 1 s pe un site care nu e pe calculatorul tău):
 * - lista fixă: `/`, `/magazin`, `/blog`, `/contact`, `/cos`, `/checkout`, `/robots.txt`, `/sitemap.xml`, o adresă care nu
 *   există (`/pagina-care-nu-exista-<aleator>`), plus paginile aduse din Bioscem în WP-R: fluxul Google Merchant
 *   (`/feed/google-merchant.xml`) și casetele avertismentelor coșului (`/api/cart/avertismente`, singura adresă `/api/`
 *   cerută: GET, coșul gol al unui vizitator nou);
 * - fiecare `<loc>` din `/sitemap.xml` (și din hărțile unui index), de pe aceeași gazdă;
 * - linkurile de pe aceeași gazdă din prima pagină (un nivel);
 * - adresele vechi ale categoriilor (`/magazin?category=<slug>` pentru fiecare `/categorie/<slug>` găsită, plus una
 *   inexistentă), care trebuie să trimită 301 (livrarea 2 SEO, A3);
 * - pozele de distribuire (`/og/…` din `og:image`, cel mult 20, aceleași pe ambele site-uri: primele după adresă).
 * Niciodată: `/admin`, `/api/` (afară de cea de mai sus), `/webhook`, `/plata`, `/contul-meu`, `/newsletter/` (dezabonări
 * și urmăriri), `/auth/`, `/checkout/…` (plăți, confirmări), `/gdpr-agreements/`, `/_verificare`, `/_andaxi`, `/uploads/`,
 * `/logout`. Fiecare cerere fără cookie pornește pe site o sesiune nouă (goală), ca la orice vizitator nou.
 *
 * Normalizarea corpului e cea a comparației locale (tools/dev/lib/normalizeaza-html.php): tokenurile, momentele, `?v=`,
 * gazda site-ului → `{GAZDA}`, bannerul copiei de verificare scos (`id="verificare-banner"`), plus versiunea pozei de
 * distribuire (`/og/<12 hex>/` → `/og/{V}/`, depinde de data fișierului; și în calea rândurilor `/og/…`, și la alegerea
 * celor 20 de poze, ca aceeași poză cu altă dată a fișierului să fie același rând) și cele două câmpuri ascunse ale
 * formularului de recenzie, declarate (CAMPURI_RECENZIE, WP-21). În fișier nu se scrie gazda.
 *
 * Gazda sursă (`--gazda-sursa=<gazdă>`): un site pornit pe conținutul altuia (copia de verificare a bioscem.ro) are în
 * conținutul copiat (descrierile produselor și paginile importate din WordPress, canonical-urile puse de mână în
 * `seo_pages`) linkuri absolute spre site-ul copiat. Cu `--gazda-sursa=bioscem.ro` ele sunt ale site-ului amprentat, ca pe
 * bioscem.ro: `{GAZDA}` în adrese, linkuri interne, cerute pe copie. O copie de verificare (bannerul în prima pagină) cere
 * opțiunea (altfel oprește după prima cerere). În capul fișierului se scrie doar o amprentă a gazdei sursei (a gazdei
 * amprentate, fără opțiune): două amprente cu alte gazde sursă nu se compară (refuz), fiindcă linkurile absolute ale
 * conținutului ar ieși diferite oricare ar fi codul.
 *
 * Comparația: câmpurile structurale (codul, `Location`, felul, canonical, robots, titlul, H1, JSON-LD, linkurile,
 * formularele, X-Robots-Tag, poza de distribuire) și adresele care sunt doar într-una; cu `--strict` și corpul. Când doar
 * una dintre amprente e a unei copii de verificare (bannerul ei în pagini), câmpurile pe care le pun gărzile copiei nu se
 * compară și se spune asta (X-Robots-Tag, pus de poartă pe fiecare răspuns; poza de distribuire și adresele `/og/`, garda 13:
 * pozele nu se copiază); `--strict` cere atunci două amprente de același fel.
 *
 * Sintaxa e PHP 8.3 (php_min); punctul de intrare verifică versiunea cu sintaxă PHP 7.0.
 */

namespace Unelte\Verificare;

use DOMDocument;
use DOMXPath;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/dev/lib/normalizeaza-html.php';

final class RefuzAmprenta extends RuntimeException
{
}

final class AmprentaSite
{
    /** 2 (revizia WP-11): amprenta gazdei sursă în cap, versiunea pozei normalizată și în calea rândurilor `/og/`. */
    public const VERSIUNE = 2;
    public const USER_AGENT = 'ANDAXI-audit/1.0';
    /** Coloanele fișierului, în ordine. */
    public const COLOANE = ['cale', 'de_unde', 'cod', 'location', 'tip', 'corp', 'titlu', 'robots', 'canonical', 'h1', 'jsonld', 'linkuri', 'formulare',
        'x_robots', 'og_image'];
    /** Câmpurile comparate (fără --strict); corpul doar cu --strict. */
    public const STRUCTURALE = ['cod', 'location', 'tip', 'canonical', 'robots', 'titlu', 'h1', 'jsonld', 'linkuri', 'formulare', 'x_robots', 'og_image'];
    /** Câmpurile pe care le pun gărzile copiei de verificare (necomparate între o copie și un site adevărat). */
    public const DE_LA_GARZI = ['x_robots' => 'poarta îl pune pe fiecare răspuns', 'og_image' => 'garda 13: pozele nu se copiază'];
    /** Câmpurile care sunt amprente (diferența se arată doar cu adresa). */
    public const AMPRENTE = ['corp', 'h1', 'formulare'];
    /** Adresele cerute întotdeauna, înaintea celorlalte. */
    public const FIXE = ['/', '/magazin', '/blog', '/contact', '/cos', '/checkout', '/robots.txt', '/sitemap.xml', '/feed/google-merchant.xml',
        '/api/cart/avertismente'];
    /**
     * Adresele care nu se cer niciodată: un prefix cu „/” la sfârșit oprește doar adresele de sub el (`/checkout/…`, dar nu
     * `/checkout`), unul fără oprește și adresa însăși; `/admin` oprește orice începe așa.
     */
    public const INTERZISE = ['/admin', '/api/', '/webhook', '/plata', '/contul-meu', '/newsletter/', '/auth', '/checkout/', '/gdpr-agreements', '/_verificare',
        '/_andaxi', '/uploads/', '/logout'];
    /** Excepțiile din INTERZISE (adresa exactă, doar GET). */
    public const PERMISE = ['/api/cart/avertismente'];
    public const OG_MAXIM = 20;
    public const CATEGORII_MAXIM = 200;
    public const PREFIX_INEXISTENTA = '/pagina-care-nu-exista-';
    /** Cât poate avea un răspuns (octeți); unul mai mare se taie și se scrie ca atare. */
    public const MARIME_MAXIMA = 33554432;

    private \CurlHandle $curl;
    private string $schema;
    private string $gazda;
    /** @var list<string> Gazdele ale căror adrese sunt ale site-ului amprentat: a lui și, cu --gazda-sursa, a site-ului copiat. */
    private array $gazde;
    private string $aleator;
    /** @var list<string> */
    private array $text = [];

    private function __construct(private string $baza, private ?string $poarta, private ?string $parola, private int $maxim, private float $pauza,
        private ?string $gazdaSursa)
    {
        $parti = parse_url($baza);
        $this->schema = strtolower((string) ($parti['scheme'] ?? ''));
        $this->gazda = strtolower((string) ($parti['host'] ?? '')) . (isset($parti['port']) ? ':' . (int) $parti['port'] : '');
        $this->gazde = array_values(array_unique(array_filter([$this->gazda, (string) $gazdaSursa], static fn (string $g): bool => $g !== '')));
        $this->aleator = bin2hex(random_bytes(8));
        $curl = curl_init();
        if (!$curl instanceof \CurlHandle) {
            throw new RefuzAmprenta('curl nu pornește');
        }
        $this->curl = $curl;
    }

    // ───────────────────────────────────────────────────────────
    // Linia de comandă
    // ───────────────────────────────────────────────────────────

    /** @param list<string> $argv */
    public static function ruleaza(array $argv): int
    {
        try {
            $opt = [];
            $pozitionale = [];
            foreach (array_slice($argv, 1) as $argument) {
                if (preg_match('/^--(baza|poarta|iesire|max|pauza|gazda-sursa)=(.*)$/s', $argument, $m) === 1) {
                    $opt[$m[1]] = $m[2];
                } elseif ($argument === '--compara' || $argument === '--strict') {
                    $opt[substr($argument, 2)] = true;
                } elseif (!str_starts_with($argument, '--')) {
                    $pozitionale[] = $argument;
                } else {
                    throw new RefuzAmprenta('argument necunoscut (vezi capul fișierului)' . (preg_match('/^--[a-z-]{1,30}/', $argument, $m) === 1 ? ': ' . $m[0] : '')
                        . '; parola porții se dă doar în VERIFICARE_POARTA_PAROLA, niciodată în linia de comandă');
                }
            }
            if (isset($opt['compara'])) {
                if (count($pozitionale) !== 2 || isset($opt['baza']) || isset($opt['gazda-sursa'])) {
                    throw new RefuzAmprenta('--compara cere două fișiere: --compara A.tsv B.tsv [--strict]');
                }
                return ComparatieAmprente::ruleaza($pozitionale[0], $pozitionale[1], isset($opt['strict']));
            }
            if ($pozitionale !== [] || isset($opt['strict'])) {
                throw new RefuzAmprenta('fără --compara, doar --baza=… --iesire=… [--poarta=…] [--gazda-sursa=…] [--max=…] [--pauza=…]');
            }
            $a = self::pregateste($opt);
            return $a->amprenteaza((string) $opt['iesire']);
        } catch (RefuzAmprenta $e) {
            fwrite(STDERR, 'REFUZAT: ' . $e->getMessage() . ". Nu am cerut nimic.\n");
            return 2;
        }
    }

    /** @param array<string, string|true> $opt */
    private static function pregateste(array $opt): self
    {
        $baza = is_string($opt['baza'] ?? null) ? rtrim((string) $opt['baza'], '/') : '';
        $parti = parse_url($baza);
        if ($baza === '' || !is_array($parti) || !in_array(strtolower((string) ($parti['scheme'] ?? '')), ['http', 'https'], true) || ($parti['host'] ?? '') === ''
            || isset($parti['user']) || isset($parti['pass']) || (isset($parti['path']) && $parti['path'] !== '') || isset($parti['query']) || isset($parti['fragment'])) {
            throw new RefuzAmprenta('--baza trebuie să fie adresa site-ului, fără cale (de exemplu --baza=https://bioscem.ro)');
        }
        $local = in_array(strtolower((string) $parti['host']), ['127.0.0.1', 'localhost', '[::1]', '::1'], true);
        if (!is_string($opt['iesire'] ?? null) || trim((string) $opt['iesire']) === '') {
            throw new RefuzAmprenta('lipsește --iesire=<fișier> (de exemplu storage/verificare/sonde/amprenta-bioscem.tsv)');
        }
        $dosar = dirname((string) $opt['iesire']);
        if (!is_dir($dosar) || !is_writable($dosar)) {
            throw new RefuzAmprenta('dosarul fișierului --iesire nu există sau nu se poate scrie în el');
        }
        $maxim = isset($opt['max']) ? (string) $opt['max'] : '3000';
        if (preg_match('/^[1-9][0-9]{0,4}$/', $maxim) !== 1 || (int) $maxim > 20000) {
            throw new RefuzAmprenta('--max trebuie să fie un număr între 1 și 20000');
        }
        $pauza = isset($opt['pauza']) ? (string) $opt['pauza'] : '1.0';
        if (preg_match('/^[0-9]{1,2}(\.[0-9]{1,3})?$/', $pauza) !== 1) {
            throw new RefuzAmprenta('--pauza e în secunde (de exemplu --pauza=1.5)');
        }
        if (!$local && (float) $pauza < 1.0) {
            throw new RefuzAmprenta('pe un site care nu e pe calculatorul tău pauza e cel puțin 1 secundă între cereri (--pauza=1)');
        }
        $poarta = null;
        $parola = null;
        if (isset($opt['poarta'])) {
            $poarta = (string) $opt['poarta'];
            if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $poarta) !== 1) {
                throw new RefuzAmprenta('--poarta e utilizatorul porții (de exemplu --poarta=verificare)');
            }
            $parola = (string) getenv('VERIFICARE_POARTA_PAROLA');
            if ($parola === '') {
                throw new RefuzAmprenta('cu --poarta, parola porții se dă în VERIFICARE_POARTA_PAROLA (read -rs VERIFICARE_POARTA_PAROLA; export VERIFICARE_POARTA_PAROLA)');
            }
            if (!$local && strtolower((string) $parti['scheme']) !== 'https') {
                throw new RefuzAmprenta('parola porții se trimite doar pe https');
            }
        }
        $gazdaSursa = isset($opt['gazda-sursa']) ? self::gazdaData((string) $opt['gazda-sursa']) : null;
        if (!function_exists('curl_init') || !class_exists(DOMDocument::class)) {
            throw new RefuzAmprenta('lipsesc extensiile PHP curl sau dom');
        }
        return new self($baza, $poarta, $parola, (int) $maxim, (float) $pauza, $gazdaSursa);
    }

    /** Gazda dată cu --gazda-sursa (`bioscem.ro`, `www.bioscem.ro`, `https://bioscem.ro`, `127.0.0.1:8717`), mică, fără schemă. */
    private static function gazdaData(string $valoare): string
    {
        $valoare = strtolower(trim($valoare));
        if (preg_match('#^https?://#', $valoare) === 1) {
            $parti = parse_url(rtrim($valoare, '/'));
            if (!is_array($parti) || ($parti['host'] ?? '') === '' || isset($parti['user']) || isset($parti['pass']) || (isset($parti['path']) && $parti['path'] !== '')
                || isset($parti['query']) || isset($parti['fragment'])) {
                $valoare = '';
            } else {
                $valoare = (string) $parti['host'] . (isset($parti['port']) ? ':' . (int) $parti['port'] : '');
            }
        }
        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,62}\.){0,10}[a-z0-9-]{1,63}(:[0-9]{1,5})?$/', $valoare) !== 1) {
            throw new RefuzAmprenta('--gazda-sursa e gazda site-ului al cărui conținut e copiat (de exemplu --gazda-sursa=bioscem.ro), fără cale');
        }
        return $valoare;
    }

    /** Amprenta (12 hex) gazdei sursă scrisă în capul fișierului: aceeași cu și fără `www.`, fără să scrie gazda. */
    public static function amprentaGazdei(string $gazda): string
    {
        return substr(sha1(self::faraWww(strtolower(trim($gazda)))), 0, 12);
    }

    /** Adresa e una pe care unealta o cere? (calea, cu interogarea; GET) */
    public static function permisa(string $cale): bool
    {
        if (!str_starts_with($cale, '/') || str_starts_with($cale, '//') || strlen($cale) > 2000 || preg_match('/[\x00-\x20\x7f\\\\]/', $cale) === 1) {
            return false;
        }
        $drum = strtolower((string) parse_url('http://x' . $cale, PHP_URL_PATH));
        if (in_array($drum, self::PERMISE, true) && !str_contains($cale, '?')) {
            return true;
        }
        if (str_contains($drum, '/../') || str_ends_with($drum, '/..')) {
            return false;
        }
        foreach (self::INTERZISE as $prefix) {
            $faraBara = rtrim($prefix, '/');
            if (str_starts_with($drum, $faraBara . '/') || (!str_ends_with($prefix, '/') && $drum === $faraBara)
                || ($prefix === '/admin' && str_starts_with($drum, '/admin'))) {
                return false;
            }
        }
        return true;
    }

    // ───────────────────────────────────────────────────────────
    // Amprentarea
    // ───────────────────────────────────────────────────────────

    private function amprenteaza(string $iesire): int
    {
        $inceput = microtime(true);
        $this->spune('Amprenta site-ului (WP-11): ' . $this->schema . '://' . $this->gazda . ($this->poarta !== null ? ', cu parola porții' : '')
            . ($this->gazdaSursa !== null ? ', conținutul copiat de pe ' . $this->gazdaSursa . ' (--gazda-sursa: adresele lui sunt ale acestui site)' : '')
            . '; GET, fără cookie-uri, ' . self::USER_AGENT . ', pauză ' . rtrim(rtrim(number_format($this->pauza, 3, '.', ''), '0'), '.') . ' s');
        $randuri = [];
        $surse = [];
        $sarite = ['interzise' => 0, 'alta_gazda' => 0];
        $deCerut = [];
        $adauga = function (string $cale, string $deUnde) use (&$deCerut, &$sarite): void {
            if (isset($deCerut[$cale])) {
                return;
            }
            if (!self::permisa($cale)) {
                $sarite['interzise']++;
                return;
            }
            $deCerut[$cale] = $deUnde;
        };

        // Întâi prima pagină: poarta (parola), apoi ce se cere.
        $prima = $this->cere('/');
        if ($prima['cod'] === 0) {
            return $this->opreste('site-ul nu răspunde (' . $prima['tip'] . ')');
        }
        if ($prima['cod'] === 401) {
            return $this->opreste($this->poarta === null ? 'site-ul cere parola porții: --poarta=verificare, cu parola în VERIFICARE_POARTA_PAROLA'
                : 'parola porții nu e bună (poarta a răspuns 401; m-am oprit după o singură încercare)');
        }
        if ($prima['cod'] === 429) {
            return $this->opreste('poarta a răspuns 429 (prea multe încercări de la această adresă): aștepți 10 minute');
        }
        $campuriPrima = self::campuri($prima['corp'], $prima['tip'], $this->gazde);
        if ($campuriPrima['copie'] && $this->gazdaSursa === null) {
            // Conținutul copiei vine de pe site-ul copiat, cu linkurile lui absolute: fără gazda lui, ar ieși adrese străine.
            return $this->opreste('e o copie de verificare (bannerul ei în prima pagină): dă și gazda site-ului copiat, de exemplu '
                . '--gazda-sursa=bioscem.ro, ca linkurile absolute din conținutul copiat (descrieri, pagini, canonical-uri puse de mână) '
                . 'să fie ale copiei, ca pe site-ul copiat');
        }
        $inexistenta = self::PREFIX_INEXISTENTA . $this->aleator;
        foreach (self::FIXE as $cale) {
            $adauga($cale, 'fix');
        }
        $adauga($inexistenta, 'fix');
        $raspunsuri = ['/' => $prima];

        // Harta site-ului (și hărțile unui index).
        $harta = $this->cereCuPauza('/sitemap.xml');
        $raspunsuri['/sitemap.xml'] = $harta;
        $harti = [];
        $locuri = $this->locuri($harta['corp'], $harti);
        foreach (array_slice($harti, 0, 50) as $hartaCopil) {
            $cale = $this->caleDeAici($hartaCopil);
            if ($cale === null) {
                $sarite['alta_gazda']++;
                continue;
            }
            $adauga($cale, 'harta');
            if (isset($deCerut[$cale]) && !isset($raspunsuri[$cale])) {
                $raspunsuri[$cale] = $this->cereCuPauza($cale);
                $sub = [];
                $locuri = array_merge($locuri, $this->locuri($raspunsuri[$cale]['corp'], $sub));
            }
        }
        foreach ($locuri as $loc) {
            $cale = $this->caleDeAici($loc);
            if ($cale === null) {
                $sarite['alta_gazda']++;
                continue;
            }
            $adauga($cale, 'harta');
        }
        // Linkurile din prima pagină.
        foreach ($campuriPrima['linkuri_cai'] as $cale) {
            $adauga($cale, 'link');
        }
        // Adresele vechi ale categoriilor (301 spre /categorie/<slug>), plus una inexistentă.
        $categorii = [];
        foreach (array_keys($deCerut) as $cale) {
            if (preg_match('#^/categorie/([A-Za-z0-9._~%-]+)$#', $cale, $m) === 1) {
                $categorii[$m[1]] = true;
            }
        }
        foreach (array_slice(array_keys($categorii), 0, self::CATEGORII_MAXIM) as $slug) {
            $adauga('/magazin?category=' . $slug, 'categorie-veche');
        }
        $adauga('/magazin?category=categorie-care-nu-exista-' . $this->aleator, 'categorie-veche');

        // Cererile (în ordine, cu pauză), până la --max.
        foreach ($deCerut as $cale => $deUnde) {
            if (count($randuri) >= $this->maxim) {
                break;
            }
            $r = $raspunsuri[$cale] ?? $this->cereCuPauza($cale);
            $randuri[$cale] = $this->rand($cale, $deUnde, $r);
            $surse[$deUnde] = ($surse[$deUnde] ?? 0) + 1;
        }
        // Pozele de distribuire găsite în pagini (aceleași pe ambele site-uri: primele după adresa fără versiune).
        $poze = self::pozeDeCerut($randuri);
        $vazute = array_flip(array_map(static fn ($c): string => self::faraVersiune((string) $c), array_keys($randuri)));
        foreach ($poze as $cale) {
            if (count($randuri) >= $this->maxim || isset($vazute[self::faraVersiune($cale)])) {
                continue;
            }
            $randuri[$cale] = $this->rand($cale, 'og', $this->cereCuPauza($cale));
            $surse['og'] = ($surse['og'] ?? 0) + 1;
        }

        $copie = false;
        foreach ($randuri as $rand) {
            $copie = $copie || $rand['copie'];
        }
        $coduri = [];
        foreach ($randuri as $rand) {
            $coduri[(string) $rand['cod']] = ($coduri[(string) $rand['cod']] ?? 0) + 1;
        }
        ksort($coduri);
        FisierAmprenta::scrie($iesire, array_values($randuri), $copie, self::amprentaGazdei($this->gazdaSursa ?? $this->gazda));
        $this->spune('copie de verificare (bannerul ei în pagini): ' . ($copie ? 'DA' : 'NU'));
        $this->spune('adrese: ' . count($randuri) . ' (fixe ' . ($surse['fix'] ?? 0) . ', din hartă ' . ($surse['harta'] ?? 0) . ', linkuri din prima pagină '
            . ($surse['link'] ?? 0) . ', categorii vechi ' . ($surse['categorie-veche'] ?? 0) . ', poze de distribuire ' . ($surse['og'] ?? 0) . ')'
            . (count($deCerut) + count($poze) > count($randuri) ? '; peste --max=' . $this->maxim . ' n-am mai cerut' : ''));
        $this->spune('sărite: ' . $sarite['interzise'] . ' adrese pe care unealta nu le cere niciodată (admin, plăți, cont, API…), '
            . $sarite['alta_gazda'] . ' de pe altă gazdă');
        $this->spune('coduri: ' . implode(', ', array_map(static fn (string $c, int $n): string => $c . ' ' . $n, array_keys($coduri), $coduri)));
        $this->spune('durata: ' . (int) round(microtime(true) - $inceput) . ' s');
        $this->spune('GATA: amprenta e scrisă în ' . $iesire . ' (fără gazdă; compari două cu --compara A.tsv B.tsv)');
        return 0;
    }

    private function opreste(string $motiv): int
    {
        $this->spune('OPRIT: ' . $motiv . '. N-am scris nicio amprentă.');
        return 1;
    }

    private function spune(string $text): void
    {
        echo $text, "\n";
        $this->text[] = $text;
    }

    /** @return array{cod: int, location: string, tip: string, x_robots: string, corp: string} */
    private function cereCuPauza(string $cale): array
    {
        if ($this->pauza > 0) {
            usleep((int) round($this->pauza * 1000000));
        }
        return $this->cere($cale);
    }

    /** @return array{cod: int, location: string, tip: string, x_robots: string, corp: string} */
    private function cere(string $cale): array
    {
        $corp = '';
        $prea = false;
        $antete = [];
        $optiuni = [
            CURLOPT_URL => $this->baza . $cale,
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: */*', 'Cache-Control: no-cache'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($c, string $rand) use (&$antete): int {
                if (preg_match('/^HTTP\/\S+\s+\d{3}/', $rand) === 1) {
                    $antete = [];
                } elseif (str_contains($rand, ':')) {
                    [$nume, $valoare] = explode(':', $rand, 2);
                    $antete[strtolower(trim($nume))][] = trim($valoare);
                }
                return strlen($rand);
            },
            CURLOPT_WRITEFUNCTION => static function ($c, string $bucata) use (&$corp, &$prea): int {
                if (strlen($corp) + strlen($bucata) > self::MARIME_MAXIMA) {
                    $prea = true;
                    return 0;
                }
                $corp .= $bucata;
                return strlen($bucata);
            },
        ];
        if ($this->poarta !== null) {
            $optiuni[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $optiuni[CURLOPT_USERPWD] = $this->poarta . ':' . (string) $this->parola;
        }
        curl_reset($this->curl);
        curl_setopt_array($this->curl, $optiuni);
        $ok = curl_exec($this->curl);
        $cod = (int) curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE);
        if ($ok === false && !$prea) {
            return ['cod' => 0, 'location' => '', 'tip' => 'eroare-retea-' . curl_errno($this->curl), 'x_robots' => '', 'corp' => ''];
        }
        return [
            'cod' => $cod,
            'location' => (string) ($antete['location'][0] ?? ''),
            'tip' => $prea ? 'prea-mare' : strtolower(str_replace(' ', '', (string) ($antete['content-type'][0] ?? ''))),
            'x_robots' => strtolower(implode(', ', $antete['x-robots-tag'] ?? [])),
            'corp' => $corp,
        ];
    }

    /** Calea (cu interogarea) unei adrese de pe gazda site-ului (sau gazda sursă; cu sau fără www.), sau null. */
    private function caleDeAici(string $adresa): ?string
    {
        $adresa = trim(html_entity_decode($adresa, ENT_QUOTES | ENT_XML1, 'UTF-8'));
        if (str_starts_with($adresa, '/') && !str_starts_with($adresa, '//')) {
            return self::faraFragment($adresa);
        }
        $parti = parse_url($adresa);
        if (!is_array($parti) || !in_array(strtolower((string) ($parti['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }
        $gazda = strtolower((string) ($parti['host'] ?? '')) . (isset($parti['port']) ? ':' . (int) $parti['port'] : '');
        if (!self::eGazdaSitului($gazda, $this->gazde)) {
            return null;
        }
        return self::faraFragment(($parti['path'] ?? '/') . (isset($parti['query']) ? '?' . $parti['query'] : ''));
    }

    /** @param list<string> $gazde */
    private static function eGazdaSitului(string $gazda, array $gazde): bool
    {
        if ($gazda === '') {
            return false;
        }
        foreach ($gazde as $g) {
            if (self::faraWww($gazda) === self::faraWww(strtolower((string) $g))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Pozele de distribuire de cerut (cel mult OG_MAXIM, căile din pagini): primele după calea fără versiunea fișierului
     * (`/og/{V}/…`), deci aceleași pe două site-uri cu aceleași poze, oricare ar fi data fișierelor; o poză văzută cu două
     * versiuni se cere o singură dată.
     *
     * @param iterable<array<string, string|int|bool>> $randuri
     * @return list<string>
     */
    public static function pozeDeCerut(iterable $randuri): array
    {
        $poze = [];
        foreach ($randuri as $rand) {
            $og = (string) ($rand['og_cale'] ?? '');
            if ($og !== '' && str_starts_with($og, '/og/')) {
                $poze[self::faraVersiune($og)] ??= $og;
            }
        }
        ksort($poze, SORT_STRING);
        return array_values(array_slice($poze, 0, self::OG_MAXIM));
    }

    /** O cale `/og/<12 hex>/…` cu versiunea pozei scoasă (`/og/{V}/…`); celelalte căi rămân. */
    private static function faraVersiune(string $cale): string
    {
        return (string) preg_replace('#^/og/[0-9a-f]{12}/#', '/og/{V}/', $cale);
    }

    private static function faraFragment(string $cale): string
    {
        $pozitie = strpos($cale, '#');
        $cale = $pozitie === false ? $cale : substr($cale, 0, $pozitie);
        return $cale === '' ? '/' : $cale;
    }

    private static function faraWww(string $gazda): string
    {
        return str_starts_with($gazda, 'www.') ? substr($gazda, 4) : $gazda;
    }

    /**
     * `<loc>`-urile unei hărți; `$harti` primește hărțile unui index (`<sitemapindex>`).
     *
     * @param list<string> $harti
     * @return list<string>
     */
    private function locuri(string $xml, array &$harti): array
    {
        if (!str_contains($xml, '<loc>')) {
            return [];
        }
        preg_match_all('#<loc>\s*([^<]{1,2000}?)\s*</loc>#', $xml, $m);
        if (str_contains($xml, '<sitemapindex')) {
            $harti = array_merge($harti, $m[1]);
            return [];
        }
        return $m[1];
    }

    /**
     * Rândul amprentei pentru un răspuns.
     *
     * @param array{cod: int, location: string, tip: string, x_robots: string, corp: string} $r
     * @return array<string, string|int|bool>
     */
    private function rand(string $cale, string $deUnde, array $r): array
    {
        return self::randDin($cale, $deUnde, $r, $this->gazde, [$this->aleator]);
    }

    /**
     * Rândul amprentei, fără rețea (îl folosesc și testele): câmpurile din răspuns, cu gazdele site-ului (a lui și gazda
     * sursă), textele aleatoare și versiunea pozelor de distribuire normalizate (și în cale: același rând oricare ar fi data
     * fișierului).
     *
     * @param array{cod: int, location: string, tip: string, x_robots: string, corp: string} $r
     * @param list<string> $gazde
     * @param list<string> $aleatoare
     * @return array<string, string|int|bool>
     */
    public static function randDin(string $cale, string $deUnde, array $r, array $gazde, array $aleatoare): array
    {
        $campuri = self::campuri($r['corp'], $r['tip'], $gazde);
        $text = self::eText($r['tip']);
        $corp = $text ? self::normalizeazaCorp($r['corp'], $gazde, $aleatoare) : $r['corp'];
        return [
            'cale' => self::faraVersiune(str_replace($aleatoare, '{ALEATOR}', $cale)),
            'de_unde' => $deUnde,
            'cod' => $r['cod'],
            'location' => $r['location'] !== '' ? str_replace($aleatoare, '{ALEATOR}', self::adresa($r['location'], $gazde)) : '-',
            'tip' => $r['tip'] !== '' ? $r['tip'] : '-',
            'corp' => sha1($corp),
            'titlu' => $campuri['titlu'],
            'robots' => $campuri['robots'],
            'canonical' => str_replace($aleatoare, '{ALEATOR}', $campuri['canonical']),
            'h1' => $campuri['h1'],
            'jsonld' => $campuri['jsonld'],
            'linkuri' => $campuri['linkuri'],
            'formulare' => $campuri['formulare'],
            'x_robots' => $r['x_robots'] !== '' ? $r['x_robots'] : '-',
            'og_image' => $campuri['og_image'],
            'og_cale' => $campuri['og_cale'],
            'copie' => $campuri['copie'],
        ];
    }

    private static function eText(string $tip): bool
    {
        return preg_match('#(text/|html|xml|json|javascript|ecmascript)#', $tip) === 1;
    }

    /**
     * Corpul normalizat (tools/dev/lib/normalizeaza-html.php, cu gazdele site-ului, plus versiunea pozelor de distribuire).
     *
     * @param list<string> $gazde
     * @param list<string> $aleatoare
     */
    public static function normalizeazaCorp(string $corp, array $gazde, array $aleatoare): string
    {
        $corp = normalizeaza_html($corp, ['fara_banner' => true, 'gazde' => $gazde, 'aleatoare' => $aleatoare]);
        $corp = normalizeaza_inlocuieste(self::CAMPURI_RECENZIE, '', $corp);
        return normalizeaza_inlocuieste('#/og/[0-9a-f]{12}/#', '/og/{V}/', $corp);
    }

    /**
     * Cele două câmpuri ascunse ale formularului de recenzie (WP-21, C10: App\Support\ReviewGuard::campuriFormular(),
     * declarate în site-uri/<slug>/ce-se-schimba.md), exact cum le scrie codul comun, după normalizare (momentul e
     * `<MOMENT>`), cu marcajele de dezvoltare `bv:recenzie-capcana*` dacă sunt: se scot din corp, ca o pagină de produs
     * a codului vechi și aceeași pagină a codului comun să aibă aceeași amprentă. Doar perechea exactă: un atribut
     * schimbat, alt câmp sau orice altă diferență a formularului rămâne în corp (și „DIFERĂ” cu --strict).
     */
    public const CAMPURI_RECENZIE = '#(?:<!--port:bv:recenzie-capcana(?:-qr)?-->)?'
        . '<input type="text" name="review_website" value="" tabindex="-1" autocomplete="off" aria-hidden="true"'
        . ' style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;">'
        . '<input type="hidden" name="review_opened_at" value="<MOMENT>">'
        . '(?:<!--/port:bv:recenzie-capcana(?:-qr)?-->)?#';

    /**
     * O adresă scrisă cu `{GAZDA}` când e a site-ului (relativă, sau pe una dintre gazdele lui: a lui și gazda sursă),
     * întreagă altfel; `/og/<versiune>/` → `/og/{V}/`.
     *
     * @param list<string> $gazde
     */
    public static function adresa(string $adresa, array $gazde): string
    {
        $adresa = trim(html_entity_decode($adresa, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($adresa === '') {
            return '-';
        }
        if (str_starts_with($adresa, '/') && !str_starts_with($adresa, '//')) {
            $rezultat = NORMALIZEAZA_GAZDA . $adresa;
        } else {
            $parti = parse_url(str_starts_with($adresa, '//') ? 'https:' . $adresa : $adresa);
            $g = is_array($parti) ? strtolower((string) ($parti['host'] ?? '')) . (isset($parti['port']) ? ':' . (int) $parti['port'] : '') : '';
            $rezultat = self::eGazdaSitului($g, $gazde)
                ? NORMALIZEAZA_GAZDA . ($parti['path'] ?? '/') . (isset($parti['query']) ? '?' . $parti['query'] : '')
                : $adresa;
        }
        return (string) preg_replace('#/og/[0-9a-f]{12}/#', '/og/{V}/', $rezultat);
    }

    /**
     * Câmpurile unei pagini HTML (din corpul fără banner): titlul, robots, canonical, H1 (număr și amprentă), tipurile JSON-LD
     * (sortate, de la orice adâncime), linkurile interne (număr; și căile lor, pentru primul nivel al căutării), formularele
     * (număr și amprenta acțiunilor), poza de distribuire, dacă e o copie de verificare. „Interne” sunt adresele relative și
     * cele de pe gazdele site-ului (a lui și gazda sursă).
     *
     * @param list<string> $gazde
     * @return array{titlu: string, robots: string, canonical: string, h1: string, jsonld: string, linkuri: string, linkuri_cai: list<string>,
     *               formulare: string, og_image: string, og_cale: string, copie: bool}
     */
    public static function campuri(string $corp, string $tip, array $gazde): array
    {
        $gol = ['titlu' => '-', 'robots' => '-', 'canonical' => '-', 'h1' => '-', 'jsonld' => '-', 'linkuri' => '-', 'linkuri_cai' => [], 'formulare' => '-',
            'og_image' => '-', 'og_cale' => '', 'copie' => false];
        if (!str_contains($tip, 'html') || trim($corp) === '') {
            return $gol;
        }
        $copie = str_contains($corp, 'id="' . NORMALIZEAZA_ID_BANNER . '"');
        $html = normalizeaza_html($corp, ['fara_banner' => true]);
        $dom = new DOMDocument();
        $vechi = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($vechi);
        $x = new DOMXPath($dom);
        $text = static fn (string $t): string => trim((string) preg_replace('/\s+/u', ' ', $t));
        $mic = 'translate(%s, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")';

        $titlu = $x->query('//title')->item(0);
        $robots = [];
        foreach ($x->query('//meta[' . sprintf($mic, '@name') . '="robots"]/@content') ?: [] as $nod) {
            $robots[] = strtolower($text((string) $nod->nodeValue));
        }
        $canonical = '-';
        foreach ($x->query('//link[@href]') ?: [] as $nod) {
            $rel = ' ' . strtolower($text((string) $nod->getAttribute('rel'))) . ' ';
            if (str_contains($rel, ' canonical ')) {
                $canonical = self::adresa((string) $nod->getAttribute('href'), $gazde);
                break;
            }
        }
        $h1 = [];
        foreach ($x->query('//h1') ?: [] as $nod) {
            $h1[] = $text((string) $nod->textContent);
        }
        $tipuri = [];
        $jsonGresit = false;
        foreach ($x->query('//script[' . sprintf($mic, '@type') . '="application/ld+json"]') ?: [] as $nod) {
            $date = json_decode(trim((string) $nod->textContent), true);
            if (!is_array($date)) {
                $jsonGresit = true;
                continue;
            }
            self::tipuriJsonLd($date, $tipuri);
        }
        $tipuri = array_values(array_unique($tipuri));
        sort($tipuri, SORT_STRING);
        $linkuri = 0;
        $cai = [];
        foreach ($x->query('//a[@href]') ?: [] as $nod) {
            $href = trim((string) $nod->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || preg_match('/^(mailto|tel|javascript|data|sms|whatsapp):/i', $href) === 1) {
                continue;
            }
            $adresa = self::adresa($href, $gazde);
            if (str_starts_with($adresa, NORMALIZEAZA_GAZDA)) {
                $linkuri++;
                $cai[] = self::faraFragment(substr($adresa, strlen(NORMALIZEAZA_GAZDA)));
            } elseif (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) && !str_starts_with($href, '//')) {
                // O adresă relativă fără „/” (rar): tot a site-ului.
                $linkuri++;
            }
        }
        $actiuni = [];
        foreach ($x->query('//form') ?: [] as $nod) {
            $actiuni[] = strtolower($text((string) $nod->getAttribute('method'))) . ' ' . self::adresa((string) $nod->getAttribute('action'), $gazde);
        }
        sort($actiuni, SORT_STRING);
        $og = '-';
        $ogCale = '';
        foreach ($x->query('//meta[@property="og:image"]/@content') ?: [] as $nod) {
            $og = self::adresa((string) $nod->nodeValue, $gazde);
            $brut = (string) $nod->nodeValue;
            $parti = parse_url($brut);
            if (str_starts_with($og, NORMALIZEAZA_GAZDA)) {
                $ogCale = str_starts_with($brut, '/') ? $brut : (string) (($parti['path'] ?? '') . (isset($parti['query']) ? '?' . $parti['query'] : ''));
            }
            break;
        }
        return [
            'titlu' => $titlu !== null && $text((string) $titlu->textContent) !== '' ? mb_substr($text((string) $titlu->textContent), 0, 300) : '-',
            'robots' => $robots !== [] ? implode(' | ', $robots) : '-',
            'canonical' => $canonical,
            'h1' => count($h1) . ':' . ($h1 === [] ? '-' : substr(sha1(implode("\n", $h1)), 0, 12)),
            'jsonld' => ($tipuri !== [] ? implode(',', $tipuri) : '-') . ($jsonGresit ? ' (JSON-LD de necitit)' : ''),
            'linkuri' => (string) $linkuri,
            'linkuri_cai' => array_values(array_unique($cai)),
            'formulare' => count($actiuni) . ':' . ($actiuni === [] ? '-' : substr(sha1(implode("\n", $actiuni)), 0, 12)),
            'og_image' => $og,
            'og_cale' => $ogCale,
            'copie' => $copie,
        ];
    }

    /** @param list<string> $tipuri */
    private static function tipuriJsonLd(mixed $date, array &$tipuri): void
    {
        if (!is_array($date)) {
            return;
        }
        foreach ($date as $cheie => $valoare) {
            if ($cheie === '@type') {
                foreach ((array) $valoare as $tip) {
                    if (is_string($tip) && preg_match('/^[A-Za-z0-9:\/._-]{1,80}$/', $tip) === 1) {
                        $tipuri[] = $tip;
                    }
                }
            } elseif (is_array($valoare)) {
                self::tipuriJsonLd($valoare, $tipuri);
            }
        }
    }
}

/**
 * Fișierul amprentei: un TSV cu un rând de cap (versiunea, copia de verificare, numărul de adrese, amprenta gazdei sursă,
 * data) și coloanele.
 */
final class FisierAmprenta
{
    public const CAP = '# amprenta-site ';

    /**
     * @param list<array<string, string|int|bool>> $randuri
     * @param string $sursa amprenta gazdei sursă (AmprentaSite::amprentaGazdei: --gazda-sursa sau, fără ea, gazda amprentată)
     */
    public static function scrie(string $fisier, array $randuri, bool $copie, string $sursa): void
    {
        if (preg_match('/^[0-9a-f]{12}$/', $sursa) !== 1) {
            throw new RefuzAmprenta('amprenta gazdei sursă nu e bună');
        }
        $text = self::CAP . AmprentaSite::VERSIUNE . ' | copie de verificare: ' . ($copie ? 'DA' : 'NU') . ' | adrese: ' . count($randuri) . ' | sursa: '
            . $sursa . ' | ' . gmdate('Y-m-d\TH:i:s\Z') . "\n" . implode("\t", AmprentaSite::COLOANE) . "\n";
        foreach ($randuri as $rand) {
            $valori = [];
            foreach (AmprentaSite::COLOANE as $coloana) {
                $valoare = trim((string) preg_replace('/[\t\r\n]+/', ' ', (string) ($rand[$coloana] ?? '-')));
                $valori[] = $valoare === '' ? '-' : $valoare;
            }
            $text .= implode("\t", $valori) . "\n";
        }
        $umask = umask(0077);
        try {
            $temporar = $fisier . '.' . getmypid() . '.tmp';
            if (@file_put_contents($temporar, $text) !== strlen($text) || !@rename($temporar, $fisier)) {
                @unlink($temporar);
                throw new RefuzAmprenta('nu pot scrie fișierul amprentei');
            }
            @chmod($fisier, 0600);
        } finally {
            umask($umask);
        }
    }

    /** @return array{copie: bool, adrese: int, sursa: string, data: string, randuri: array<string, array<string, string>>} */
    public static function citeste(string $fisier): array
    {
        $text = is_file($fisier) && is_readable($fisier) ? (string) @file_get_contents($fisier) : '';
        $linii = explode("\n", rtrim($text, "\n"));
        if (count($linii) < 2 || preg_match('/^# amprenta-site (\d+) \| /', $linii[0], $v) !== 1) {
            throw new RefuzAmprenta('fișierul ' . basename($fisier) . ' nu e o amprentă scrisă de amprenta-site.php');
        }
        if ((int) $v[1] !== AmprentaSite::VERSIUNE) {
            throw new RefuzAmprenta('fișierul ' . basename($fisier) . ' e de altă versiune a amprentei (' . $v[1] . '); fă-l din nou cu această unealtă');
        }
        if (preg_match('/^# amprenta-site \d+ \| copie de verificare: (DA|NU) \| adrese: (\d+) \| sursa: ([0-9a-f]{12}) \| (\S+)$/', $linii[0], $m) !== 1) {
            throw new RefuzAmprenta('fișierul ' . basename($fisier) . ' nu e o amprentă scrisă de amprenta-site.php');
        }
        if ($linii[1] !== implode("\t", AmprentaSite::COLOANE)) {
            throw new RefuzAmprenta('fișierul ' . basename($fisier) . ' are alte coloane');
        }
        $randuri = [];
        foreach (array_slice($linii, 2) as $linie) {
            $valori = explode("\t", $linie);
            if (count($valori) !== count(AmprentaSite::COLOANE)) {
                throw new RefuzAmprenta('fișierul ' . basename($fisier) . ' are un rând stricat');
            }
            $rand = array_combine(AmprentaSite::COLOANE, $valori);
            $randuri[$rand['cale']] = $rand;
        }
        if (count($randuri) !== (int) $m[2]) {
            throw new RefuzAmprenta('fișierul ' . basename($fisier) . ' nu are câte adrese spune capul lui');
        }
        return ['copie' => $m[1] === 'DA', 'adrese' => count($randuri), 'sursa' => $m[3], 'data' => $m[4], 'randuri' => $randuri];
    }
}

/** `--compara A.tsv B.tsv [--strict]`: diferențele pe câmp și adresele care diferă (cel mult 50 pe câmp). */
final class ComparatieAmprente
{
    public const MAXIM_PE_CAMP = 50;

    public static function ruleaza(string $a, string $b, bool $strict): int
    {
        $A = FisierAmprenta::citeste($a);
        $B = FisierAmprenta::citeste($b);
        if ($A['sursa'] !== $B['sursa']) {
            // Conținutul (descrierile, paginile, canonical-urile puse de mână) are linkuri absolute spre site-ul lui de origine:
            // cu alte gazde sursă ar ieși alte linkuri interne, alte adrese cerute și alt canonical, oricare ar fi codul.
            throw new RefuzAmprenta('amprentele au alte gazde sursă (A ' . $A['sursa'] . ', B ' . $B['sursa'] . '): conținutul unui site pornit pe '
                . 'conținutul altuia (copia de verificare) are linkuri absolute spre site-ul copiat, deci amprenta lui se face cu '
                . '--gazda-sursa=<gazda site-ului copiat>, de exemplu --gazda-sursa=bioscem.ro; fă din nou amprenta căreia îi lipsește');
        }
        $unaCopie = $A['copie'] !== $B['copie'];
        if ($strict && $unaCopie) {
            throw new RefuzAmprenta('--strict compară doar două amprente de același fel (amândouă ale unei copii de verificare, sau niciuna): '
                . 'pe copie gărzile schimbă corpul paginilor (bannerul, etichetele terților, robots.txt)');
        }
        $spune = static function (string $t): void {
            echo $t, "\n";
        };
        $spune('Amprentele: A ' . basename($a) . ' (copie de verificare: ' . ($A['copie'] ? 'DA' : 'NU') . ', ' . $A['adrese'] . ' adrese, ' . $A['data'] . ') și B '
            . basename($b) . ' (copie de verificare: ' . ($B['copie'] ? 'DA' : 'NU') . ', ' . $B['adrese'] . ' adrese, ' . $B['data'] . ')'
            . '; aceeași gazdă sursă' . ($strict ? '; cu --strict (și corpul)' : '; câmpurile structurale (corpul doar cu --strict)'));
        $campuri = AmprentaSite::STRUCTURALE;
        if ($strict) {
            $campuri[] = 'corp';
        }
        $necomparate = [];
        if ($unaCopie) {
            $campuri = array_values(array_diff($campuri, array_keys(AmprentaSite::DE_LA_GARZI)));
        }
        // Cu o singură copie de verificare, pozele (/og/, /uploads/) nu se compară deloc: pe copie lipsesc (garda 13), deci
        // paginile ei nici nu le au în og:image, iar cele cerute trimit 302 spre site-ul sursă.
        $poza = static fn (string $cale): bool => str_starts_with($cale, '/og/') || str_starts_with($cale, '/uploads/');
        $pozeSarite = 0;
        $toate = array_unique(array_merge(array_keys($A['randuri']), array_keys($B['randuri'])));
        if ($unaCopie) {
            $pozeSarite = count(array_filter($toate, $poza));
        }
        $deComparat = static fn (array $cai): array => $unaCopie ? array_values(array_filter($cai, static fn (string $c): bool => !$poza($c))) : array_values($cai);
        $doarA = $deComparat(array_diff(array_keys($A['randuri']), array_keys($B['randuri'])));
        $doarB = $deComparat(array_diff(array_keys($B['randuri']), array_keys($A['randuri'])));
        $comune = $deComparat(array_intersect(array_keys($A['randuri']), array_keys($B['randuri'])));
        $diferente = [];
        foreach ($comune as $cale) {
            foreach ($campuri as $camp) {
                $va = $A['randuri'][$cale][$camp];
                $vb = $B['randuri'][$cale][$camp];
                if ($va !== $vb) {
                    $diferente[$camp][] = in_array($camp, AmprentaSite::AMPRENTE, true) ? $cale : $cale . ' (' . self::scurt($va) . ' → ' . self::scurt($vb) . ')';
                }
            }
        }
        $spune('adrese în ambele: ' . count($comune) . ' | doar în A: ' . count($doarA) . ' | doar în B: ' . count($doarB));
        foreach (['A' => $doarA, 'B' => $doarB] as $care => $lista) {
            sort($lista, SORT_STRING);
            foreach (array_slice($lista, 0, self::MAXIM_PE_CAMP) as $cale) {
                $spune('  doar în ' . $care . ': ' . $cale);
            }
            if (count($lista) > self::MAXIM_PE_CAMP) {
                $spune('  … încă ' . (count($lista) - self::MAXIM_PE_CAMP) . ' doar în ' . $care);
            }
        }
        if ($unaCopie) {
            foreach (AmprentaSite::DE_LA_GARZI as $camp => $motiv) {
                $necomparate[] = $camp . ' (' . $motiv . ')';
            }
            $spune('necomparate (doar ' . ($A['copie'] ? 'A' : 'B') . ' e copia de verificare): ' . implode(', ', $necomparate) . '; adresele /og/ și /uploads/: '
                . $pozeSarite . ' (garda 13)');
        }
        ksort($diferente);
        $spune('diferențe pe câmp: ' . ($diferente === [] ? '—' : implode(', ', array_map(static fn (string $c, array $l): string => $c . ' ' . count($l),
            array_keys($diferente), $diferente))));
        foreach ($diferente as $camp => $lista) {
            sort($lista, SORT_STRING);
            foreach (array_slice($lista, 0, self::MAXIM_PE_CAMP) as $rand) {
                $spune('  ' . $camp . ': ' . $rand);
            }
            if (count($lista) > self::MAXIM_PE_CAMP) {
                $spune('  … încă ' . (count($lista) - self::MAXIM_PE_CAMP) . ' la ' . $camp);
            }
        }
        $laFel = $diferente === [] && $doarA === [] && $doarB === [];
        $spune('REZULTAT: ' . ($laFel ? 'LA FEL' : 'DIFERĂ'));
        return $laFel ? 0 : 1;
    }

    private static function scurt(string $valoare): string
    {
        return mb_strlen($valoare) > 120 ? mb_substr($valoare, 0, 117) . '…' : $valoare;
    }
}
