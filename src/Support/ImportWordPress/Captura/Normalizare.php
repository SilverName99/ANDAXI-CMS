<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\Captura;

use App\Support\Html5\Token;
use App\Support\Html5\Tokenizer;

/**
 * Normalizarea capturii unui site WordPress (adoptarea, pasul 4a; DECIZIE §6.7, o LISTĂ ÎNCHISĂ, declarată în
 * `ce-se-schimba.md`). Ce face, și nimic altceva:
 *  1. **nonce-urile** (`nonce`): valoarea câmpurilor ascunse `_wpnonce…` (formularele Divi) și a cheilor JSON din NONCE (plus
 *     cele date de pachet, găsite la comparația pe UA) devine `{{ax:nonce}}`, în HTML, în JSON-ul atributelor și în textul
 *     scripturilor (CB-15: așa o recaptură cu alte nonce-uri dă aceiași octeți, deci aceleași hash-uri ale aruncatelor, CB-14);
 *  2. **clasele de sistem de pe `<body>`** (`clasa-ua`): cele din CLASE_SISTEM, puse de server după UA, se scot; le pune
 *     runtime-ul în browser (spec. clase-ua);
 *  3. **adresele proprii absolute → relative** (`adresa-proprie`): `https://<gazdă>`, `http://<gazdă>`, `//<gazdă>` și formele
 *     escapate din JSON (`https:\/\/<gazdă>`, inclusiv în JSON-ul cu entități din `data-et-multi-view`) devin căi relative, în
 *     atributele elementelor, în CSS (`<style>`, `style=""`) și în JSON. Rămân absolute: head-ul SEO (`<meta>`, `<title>`,
 *     `<link rel=canonical|shortlink|prev|next|alternate>`: hreflang, RSS, oEmbed; JSON-LD), textul vizibil și `<textarea>`
 *     (codul de încorporare al cardului embed), plus răspunsurile înghețate (sitemap-urile, robots, oEmbed: raspuns()). La
 *     comparație (document()) se fac relative doar adresele în forma canonică (schema și gazda din adresa site-ului, plus
 *     `//gazdă`): http pe un site https și perechea www rămân absolute, deci nu mai sunt egale cu forma canonică. Se rescrie
 *     doar gazda exactă a site-ului (perechea `www.` rămâne absolută) și doar când rescrierea nu schimbă gazda la care duce
 *     adresa citită ca în browser (WHATWG): fără userinfo, fără „\”, tab, CR sau LF lângă gazdă, fără o cale care ar începe cu
 *     `//`, `/\`, `\/` sau `\\` (inlocuireSigura(); recenzia dialectului, B); altfel adresa rămâne neatinsă.
 *
 * Doar la comparație (document() și raspuns(); niciodată pe documentul servit, T(captura) păstrează o singură variantă, cea
 * capturată: decizia D7), variațiile aleatoare la fiecare cerere, fiecare ancorată pe construcția ei exactă (FELURI_COMPARATIE):
 * secretul cardurilor embed (în codul de încorporare din `textarea.wp-embed-share-input` și în oEmbed); referințele numerice ale
 * literelor ASCII din `span.cmplz-obfuscate` (obfuscarea unui email; cu fanionul ei în editari()); numărul aleator din valoarea
 * întreagă a ID-urilor taburilor cardului embed, înlocuit cu `{{ax:aleator:N}}` după ordinea primei apariții și doar dacă
 * fiecare trimitere (aria-controls, aria-describedby…) are un `id` cu aceeași valoare; ora din comentariul AIOSEO întreg „This
 * sitemap was dynamically generated on …”, doar pe un sitemap (calea lui și rădăcina `<urlset>`/`<sitemapindex>`).
 *
 * Fail-closed: un text de forma jetoanelor noastre (`{{ax:`) deja în captură nu e luat drept normalizat; jeton() îl găsește, iar
 * captura spune NU pe adresa lui (dialectul are aceeași regulă).
 *
 * Normalizarea nu reserializează nimic: dă o listă de înlocuiri pe intervale de octeți (editari()), fiecare cu felul ei; restul
 * octeților rămâne cel capturat. Dialectul (pasul 5a, Continut\Divi4) le aplică prin Html5\Editare, împreună cu editările lui;
 * comparația pe UA (Captura) le aplică direct (document()). O diferență între UA-uri care rămâne după normalizare înseamnă NU.
 */
final class Normalizare
{
    /** Numele câmpurilor și cheilor nonce (§6.7 #1). Un câmp de formular se potrivește și pe prefix (`_wpnonce-et-pb-…-0`). */
    public const NONCE = ['_wpnonce', 'et_frontend_nonce', 'et_ab_log_nonce', 'ajaxnonce'];

    public const LOC_NONCE = '{{ax:nonce}}';
    public const LOC_SECRET = '{{ax:secret}}';

    /** Clasele de sistem puse de server pe `<body>` după UA (§6.7 #7), scoase la captură: sistemul, browserul, dispozitivul. */
    public const CLASE_SISTEM = ['windows', 'osx', 'linux', 'ipad', 'iphone', 'android', 'chrome', 'safari', 'gecko', 'opera', 'edge',
        'ie', 'uiwebview', 'et_mobile_device', 'et_mobile_device_not_ipad'];

    /** Felurile editărilor normalizării (lista închisă). */
    public const FELURI = ['nonce', 'clasa-ua', 'adresa-proprie'];

    /**
     * Normalizările DOAR pentru comparație (document(), raspuns(): pe UA, între capturi, P1/P2), niciodată pe documentul servit,
     * fiecare ancorată pe construcția ei exactă (în afara ei nu atinge nimic):
     * `secret` — cele 10 caractere aleatoare din `data-secret=&quot;…&quot;` și `#?secret=…` ale codului de încorporare, doar în
     * textul unui `<textarea class="wp-embed-share-input">` (cardul embed) și în răspunsul oEmbed (D7: T păstrează secretul capturat);
     * `entitati-text` — referințele de caractere numerice ale literelor și cifrelor ASCII (și „@ . - _”) din textul aflat într-un
     * `<span class="cmplz-obfuscate">`: obfuscarea adreselor de email a unui plugin de consimțământ alege la fiecare cerere alte
     * referințe pentru aceleași caractere (DOM-ul e același);
     * `id-aleator-embed` — numărul aleator din valoarea ÎNTREAGĂ a unui `id` / `aria-controls` / `aria-describedby` /
     * `aria-labelledby` / `for` de forma `wp-embed-share-(tab|description)-(wordpress|html)-<pagină>-<aleator>` (cardul embed);
     * `ora-generarii` — data și ora din comentariul întreg „This sitemap was dynamically generated on <dată> at <oră> by All in One
     * SEO v<versiune> - the original SEO plugin for WordPress.” al unui sitemap (XML, sau sitemap-ul RSS al AIOSEO, `/sitemap.rss`).
     */
    public const FELURI_COMPARATIE = ['secret', 'entitati-text', 'id-aleator-embed', 'ora-generarii'];

    /** `ora-generarii`: comentariul întreg al generatorului de sitemap (doar la comparație; data și ora devin `{{ax:data}}`). */
    private const RE_ORA_GENERARII = '/(<!-- This sitemap was dynamically generated on )[A-Z][a-z]{2,8} \d{1,2}, \d{4} at \d{1,2}:\d{2}(?: [ap]m)?'
        . '( by All in One SEO v[0-9][0-9.]{0,20} - the original SEO plugin for WordPress\. -->)/';

    /** `id-aleator-embed`: valoarea întreagă a unui ID al taburilor cardului embed (doar la comparație). */
    private const RE_ID_EMBED = '/^(wp-embed-share-(?:tab|description)-(?:wordpress|html)-\d{1,20}-)\d{1,20}$/D';

    /** Atributele în care stă un ID al taburilor cardului embed. */
    private const ATRIBUTE_ID_EMBED = ['id', 'aria-controls', 'aria-describedby', 'aria-labelledby', 'for'];

    /** Clasa elementului cu adresa de email obfuscată (`entitati-text`). */
    private const CLASA_OBFUSCARE = 'cmplz-obfuscate';

    /** Clasa `<textarea>`-ului cu codul de încorporare al cardului embed (`secret`). */
    private const CLASA_TEXTAREA_EMBED = 'wp-embed-share-input';

    /** `rel`-urile din head care rămân absolute (SEO). */
    private const REL_SEO = ['canonical', 'shortlink', 'prev', 'next', 'alternate'];

    /** Atributele cu text arătat omului sau nume: adresele din ele nu se ating. */
    private const ATRIBUTE_TEXT = ['title', 'alt', 'placeholder', 'value', 'label', 'content', 'class', 'id', 'name', 'for', 'lang',
        'dir', 'role', 'type', 'rel', 'media', 'sizes', 'width', 'height'];

    /** Căile răspunsurilor care sunt sitemap-uri (`ora-generarii` se aplică doar lor): `/sitemap.xml`, `/page-sitemap.xml`, `/sitemap_index.xml`… */
    private const RE_CALE_SITEMAP = '#^/(?:[a-z0-9_-]+-)?sitemap(?:_index|[0-9]{1,4})?\.xml$#i';

    /** Rădăcina unui sitemap (sitemaps.org): `<urlset` sau `<sitemapindex`. */
    private const RE_CORP_SITEMAP = '#<(?:urlset|sitemapindex)[\s>]#';

    /** Sitemap-ul RSS al AIOSEO: doar calea lui exactă, cu rădăcina `<rss` (un feed obișnuit, `/feed/`, nu e sitemap). */
    private const CALE_SITEMAP_RSS = '/sitemap.rss';
    private const RE_CORP_SITEMAP_RSS = '#<rss[\s>]#';

    /** @var list<string> */
    private array $gazde;
    /** @var list<string> */
    private array $nonce;
    private string $reAdresa;
    /** Doar forma canonică a adresei site-ului (schema și gazda din `$urlSite`, plus `//gazdă`): comparațiile nu mai egalează http cu https și nici www cu gazda goală. */
    private string $reAdresaCanonica;
    private string $reNonceJson;

    /**
     * @param string $urlSite adresa site-ului live (`https://<gazdă>`); gazda și perechea ei cu/fără `www.` sunt „proprii” pentru
     *                        resurse și cereri (gazde()), dar doar gazda exactă se face relativă
     * @param list<string> $nonceInPlus nume de nonce găsite de comparația pe UA (pachetul: `captura.nonce`)
     */
    public function __construct(string $urlSite, array $nonceInPlus = [])
    {
        $gazda = strtolower((string) parse_url($urlSite, PHP_URL_HOST));
        $port = parse_url($urlSite, PHP_URL_PORT);
        if ($gazda === '' || preg_match('/^[a-z0-9.-]+$/', $gazda) !== 1) {
            throw new \InvalidArgumentException('adresa site-ului nu are o gazdă validă: ' . $urlSite);
        }
        if (is_int($port)) {
            // O copie pe alt port (o probă locală): originea proprie e gazda cu portul ei, fără pereche „www.”.
            $this->gazde = [$gazda . ':' . $port];
        } elseif (filter_var($gazda, FILTER_VALIDATE_IP) !== false) {
            $this->gazde = [$gazda];
        } else {
            $this->gazde = [$gazda, str_starts_with($gazda, 'www.') ? substr($gazda, 4) : 'www.' . $gazda];
        }
        $this->nonce = array_values(array_unique(array_merge(self::NONCE, array_values(array_filter($nonceInPlus,
            static fn (mixed $n): bool => is_string($n) && preg_match('/^[A-Za-z0-9_-]{2,80}$/', $n) === 1)))));
        // Doar gazda exactă a site-ului se rescrie (recenzia dialectului, B): perechea `www.` e altă gazdă pentru browser, deci
        // rămâne absolută (gazde() o ține pentru resursele și linkurile ascunse).
        $h = preg_quote($this->gazde[0], '/');
        // Schema opțională, „//” sau „\/\/” (JSON), gazda exactă: după ea nu poate urma un caracter de nume de gazdă (deci nu
        // `gazda.alt.ro`) și nici un port (alt server). Înainte nu poate fi o literă, o cifră sau „:” (`ftp://`, `xhttps://`).
        $this->reAdresa = '/(?<![A-Za-z0-9+.:\\\\-])(?:https?:)?(?:\/\/|\\\\\/\\\\\/)(?:' . $h . ')(?![A-Za-z0-9.-])(?!:\d)/i';
        // La comparație: doar schema canonică (sau „//”, care se rezolvă la ea) și gazda exactă din adresa site-ului. `http://` pe un
        // site https și perechea `www.` rămân absolute, deci o diferență între ele se vede (DECIZIE §6.7 #2: comparate rezolvate).
        $schema = strtolower((string) parse_url($urlSite, PHP_URL_SCHEME)) === 'http' ? 'http' : 'https';
        $this->reAdresaCanonica = '/(?<![A-Za-z0-9+.:\\\\-])(?:' . $schema . ':)?(?:\/\/|\\\\\/\\\\\/)(?:' . preg_quote($this->gazde[0], '/')
            . ')(?![A-Za-z0-9.-])(?!:\d)/i';
        $chei = implode('|', array_map(static fn (string $n): string => preg_quote($n, '/') . ($n === '_wpnonce' ? '[A-Za-z0-9_-]*' : ''), $this->nonce));
        $this->reNonceJson = '/(?<q>"|\\\\"|&quot;|&#034;)(?<k>' . $chei . ')\k<q>\s*:\s*\k<q>(?<v>[A-Za-z0-9_-]*)\k<q>/';
    }

    /** @return list<string> gazdele proprii (cu și fără `www.`) */
    public function gazde(): array
    {
        return $this->gazde;
    }

    /** @return list<string> */
    public function nonce(): array
    {
        return $this->nonce;
    }

    /**
     * Înlocuirile normalizării pe un document HTML, în ordinea textului, fără suprapuneri: fiecare e
     * `{start, end, text, fel: list<string>, tip: atribut|text, token: int, atribut?: string}`. Fără `$comparatie` (documentul
     * servit, dialectul): doar FELURI (nonce-urile, clasele de sistem, adresele proprii), fără textul scripturilor (dialectul le
     * tratează el: aruncate, configurații în `ax-date`, date inerte). Cu `$comparatie` (comparația pe UA, între capturi, P1/P2):
     * și textul scripturilor, plus `secret` și `id-aleator-embed`, fiecare doar pe construcția ei; adresele proprii doar în forma
     * canonică (schema și gazda din adresa site-ului), deci http/https și www/fără www nu mai sunt egale la comparație. `$sarite`:
     * intervale `[start, end)` pe care dialectul le scoate întregi (nimic de normalizat în ele). `$emailObfuscat`: regula
     * `entitati-text` (emailul obfuscat din `span.cmplz-obfuscate`), cu fanionul ei, separat de `$comparatie`.
     *
     * @param list<Token>|null $tokeni
     * @param list<array{0: int, 1: int}> $sarite
     * @return list<array{start: int, end: int, text: string, fel: list<string>, tip: string, token: int, atribut?: string}>
     */
    public function editari(string $html, ?array $tokeni = null, bool $comparatie = false, array $sarite = [], bool $emailObfuscat = false): array
    {
        $tokeni ??= Tokenizer::tokenizeaza($html);
        $rez = [];
        $inSarit = static function (int $s, int $e) use ($sarite): bool {
            foreach ($sarite as [$a, $b]) {
                if ($s >= $a && $e <= $b) {
                    return true;
                }
            }
            return false;
        };
        // `id-aleator-embed`: numărul aleator al ID-urilor cardului embed devine `{{ax:aleator:N}}` (N = ordinea primei apariții),
        // doar dacă fiecare trimitere (aria-controls, aria-describedby, aria-labelledby, for) are un `id` cu aceeași valoare
        // întreagă: altfel numărul rămâne cel capturat (o legătură ruptă e o diferență reală, deci NU la comparație).
        $aleatoare = $comparatie ? self::aleatoareEmbed($html, $tokeni, $inSarit) : null;
        // `entitati-text`: zonele `span.cmplz-obfuscate` (de la tagul de deschidere până la închiderea lui), doar cu fanionul lui.
        $obfuscate = [];
        if ($emailObfuscat) {
            foreach ($tokeni as $k => $t) {
                if ($t->tip === Token::DESCHIDERE && $t->nume === 'span' && in_array(self::CLASA_OBFUSCARE, $t->clase($html), true)) {
                    $p = Tokenizer::pereche($tokeni, $k);
                    if ($p !== null) {
                        $obfuscate[] = [$t->end, $tokeni[$p]->start];
                    }
                }
            }
        }
        $inObfuscat = static function (Token $t) use ($obfuscate): bool {
            foreach ($obfuscate as [$a, $b]) {
                if ($t->start >= $a && $t->end <= $b) {
                    return true;
                }
            }
            return false;
        };
        foreach ($tokeni as $k => $t) {
            if ($inSarit($t->start, $t->end)) {
                continue;
            }
            if ($t->tip === Token::DESCHIDERE) {
                foreach ($this->editariTag($html, $t, $k, $comparatie, $aleatoare) as $e) {
                    $rez[] = $e;
                }
                continue;
            }
            if ($t->tip === Token::TEXT && $t->brut === '') {
                if ($emailObfuscat && $inObfuscat($t) && str_contains($v0 = $t->octeti($html), '&#')) {
                    $fel = [];
                    $nou = self::entitatiText($v0, $fel);
                    if ($nou !== $v0) {
                        $rez[] = ['start' => $t->start, 'end' => $t->end, 'text' => $nou, 'fel' => $fel, 'tip' => 'text', 'token' => $k];
                    }
                }
                continue; // textul vizibil, comentariile, doctype: neatinse
            }
            if ($t->tip !== Token::TEXT) {
                continue;
            }
            $v = $t->octeti($html);
            $fel = [];
            if ($t->brut === 'textarea') {
                // Codul de încorporare al cardului embed: secretul lui e aleator la fiecare cerere (D7: doar la comparație).
                $deschidere = $tokeni[$k - 1] ?? null;
                if (!$comparatie || $deschidere === null || $deschidere->tip !== Token::DESCHIDERE || $deschidere->nume !== 'textarea'
                    || !in_array(self::CLASA_TEXTAREA_EMBED, $deschidere->clase($html), true)) {
                    continue;
                }
                $nou = $this->secrete($v, $fel);
            } elseif ($t->brut === 'style' || $t->brut === 'noscript' || ($comparatie && $t->brut === 'script')) {
                $nou = $this->adrese($this->nonceJson($v, $fel), $fel, $comparatie);
            } else {
                continue;
            }
            if ($nou !== $v) {
                $rez[] = ['start' => $t->start, 'end' => $t->end, 'text' => $nou, 'fel' => array_values(array_unique($fel)), 'tip' => 'text', 'token' => $k];
            }
        }
        usort($rez, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        return $rez;
    }

    /**
     * Numerele aleatoare ale ID-urilor cardului embed dintr-un document, în ordinea primei apariții (`număr → N`), sau null
     * dacă o trimitere (aria-controls, aria-describedby, aria-labelledby, for) nu are un `id` cu aceeași valoare întreagă.
     *
     * @param list<Token> $tokeni
     * @return array<string, int>|null
     */
    private static function aleatoareEmbed(string $html, array $tokeni, callable $inSarit): ?array
    {
        $iduri = [];
        $trimiteri = [];
        $ordine = [];
        foreach ($tokeni as $t) {
            if ($t->tip !== Token::DESCHIDERE || $inSarit($t->start, $t->end) || $t->nume === 'meta' || $t->nume === 'html') {
                continue;
            }
            foreach ($t->atribute as $a) {
                if ($a->duplicat || !$a->areValoare() || !in_array($a->nume, self::ATRIBUTE_ID_EMBED, true)) {
                    continue;
                }
                $v = $a->valoare($html);
                if (preg_match(self::RE_ID_EMBED, $v, $m) !== 1) {
                    continue;
                }
                $numar = substr($v, strlen($m[1]));
                $ordine[$numar] ??= count($ordine) + 1;
                if ($a->nume === 'id') {
                    $iduri[$v] = true;
                } else {
                    $trimiteri[] = $v;
                }
            }
        }
        foreach ($trimiteri as $v) {
            if (!isset($iduri[$v])) {
                return null;
            }
        }
        return $ordine;
    }

    /**
     * @param array<string, int>|null $aleatoare numerele aleatoare ale cardului embed (aleatoareEmbed), la comparație
     * @return list<array{start: int, end: int, text: string, fel: list<string>, tip: string, token: int, atribut: string}>
     */
    private function editariTag(string $html, Token $t, int $k, bool $comparatie, ?array $aleatoare = null): array
    {
        if ($t->nume === 'meta' || $t->nume === 'html') {
            return []; // head-ul SEO și atributele documentului rămân cum sunt
        }
        $faraAdrese = false;
        if ($t->nume === 'link') {
            $rel = preg_split('/[\t\n\f\r ]+/', strtolower(trim((string) $t->valoare($html, 'rel')))) ?: [];
            $faraAdrese = array_intersect($rel, self::REL_SEO) !== [];
        }
        $nonceCamp = false;
        if ($t->nume === 'input') {
            foreach (['name', 'id'] as $a) {
                $n = (string) $t->valoare($html, $a);
                if ($n !== '' && $this->esteNonce($n)) {
                    $nonceCamp = true;
                }
            }
        }
        $rez = [];
        foreach ($t->atribute as $a) {
            if ($a->duplicat || !$a->areValoare()) {
                continue;
            }
            $v = $a->valoare($html);
            $fel = [];
            $nou = $v;
            if ($nonceCamp && $a->nume === 'value') {
                $nou = self::LOC_NONCE;
                $fel[] = 'nonce';
            }
            if ($t->nume === 'body' && $a->nume === 'class') {
                $nou = $this->faraClaseSistem($nou, $fel);
            }
            if ($aleatoare !== null && in_array($a->nume, self::ATRIBUTE_ID_EMBED, true) && preg_match(self::RE_ID_EMBED, $nou, $m) === 1
                && isset($aleatoare[$numar = substr($nou, strlen($m[1]))])) {
                $nou = $m[1] . '{{ax:aleator:' . $aleatoare[$numar] . '}}';
                $fel[] = 'id-aleator-embed';
            }
            $nou = $this->nonceJson($nou, $fel);
            // Textul arătat omului (title, alt, aria-*, placeholder, valorile câmpurilor) rămâne cum e; adresele se fac relative
            // doar în atributele care poartă adrese, stiluri sau date (href, src, srcset, action, style, data-*…).
            if (!$faraAdrese && !in_array($a->nume, self::ATRIBUTE_TEXT, true) && !str_starts_with($a->nume, 'aria-')) {
                $nou = $this->adrese($nou, $fel, $comparatie);
            }
            if ($nou !== $v) {
                $rez[] = ['start' => $a->valoareStart, 'end' => $a->valoareEnd, 'text' => $nou, 'fel' => array_values(array_unique($fel)),
                    'tip' => 'atribut', 'token' => $k, 'atribut' => $a->nume];
            }
        }
        return $rez;
    }

    /** Un nume de câmp sau de cheie e nonce? (`_wpnonce` și pe prefix, restul exact) */
    public function esteNonce(string $nume): bool
    {
        foreach ($this->nonce as $n) {
            if ($nume === $n || ($n === '_wpnonce' && str_starts_with($nume, '_wpnonce'))) {
                return true;
            }
        }
        return false;
    }

    /** Documentul normalizat, pentru comparația pe UA, între capturi și în P1/P2 (cu textul scripturilor și FELURI_COMPARATIE). */
    public function document(string $html): string
    {
        return self::aplica($html, $this->editari($html, null, true, [], true));
    }

    /**
     * Octetul primului text de forma jetoanelor noastre (`{{ax:`, fără deosebire de litere mari, ca regula dialectului), sau null.
     * O captură care îl conține deja e NU (fail-closed): un nonce sau un text al paginii n-ar mai putea fi deosebit de un jeton.
     */
    public static function jeton(string $text): ?int
    {
        $p = stripos($text, '{{ax:');
        return $p === false ? null : $p;
    }

    /**
     * Un răspuns înghețat, pentru comparație: nonce-urile; la oEmbed (`$fel` = `oembed`) secretul codului de încorporare; doar la
     * un sitemap (`$cale` de sitemap și corpul cu rădăcina `<urlset>`/`<sitemapindex>`, sau `/sitemap.rss` cu `<rss>`), ora din comentariul întreg al
     * generatorului. Adresele rămân absolute. (Octeții răspunsului se păstrează cum au venit.)
     */
    public function raspuns(string $corp, string $fel = 'raspuns', string $cale = ''): string
    {
        $f = [];
        $corp = $this->nonceJson($corp, $f);
        if ($fel === 'oembed') {
            return $this->secrete($corp, $f);
        }
        $xml = preg_match(self::RE_CALE_SITEMAP, $cale) === 1 && preg_match(self::RE_CORP_SITEMAP, $corp) === 1;
        $rss = $cale === self::CALE_SITEMAP_RSS && preg_match(self::RE_CORP_SITEMAP_RSS, $corp) === 1;
        if (!$xml && !$rss) {
            return $corp;
        }
        return (string) preg_replace(self::RE_ORA_GENERARII, '${1}{{ax:data}}${2}', $corp);
    }

    /**
     * Textul unui script (sau al unui literal JSON din el), normalizat: adresele proprii și nonce-urile (CB-14, CB-15). Îl folosesc
     * sha256-ul aruncatelor și configurațiile care trec în `ax-date`.
     */
    public function textScript(string $js): string
    {
        $fel = [];
        return $this->adrese($this->nonceJson($js, $fel), $fel);
    }

    /** O foaie de stil copiată (CopiereStatica): doar adresele proprii devin relative (§6.7 #2). */
    public function css(string $css): string
    {
        $fel = [];
        return $this->adrese($css, $fel);
    }

    /**
     * Înlocuirile aplicate pe text (sortate, fără suprapuneri), fără nicio altă schimbare.
     *
     * @param list<array{start: int, end: int, text: string}> $editari
     */
    public static function aplica(string $text, array $editari): string
    {
        usort($editari, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $rez = '';
        $poz = 0;
        foreach ($editari as $e) {
            if ($e['start'] < $poz) {
                throw new \InvalidArgumentException('înlocuiri suprapuse la octetul ' . $e['start']);
            }
            $rez .= substr($text, $poz, $e['start'] - $poz) . $e['text'];
            $poz = $e['end'];
        }
        return $rez . substr($text, $poz);
    }

    /** @param list<string> $fel */
    private function nonceJson(string $v, array &$fel): string
    {
        $are = false;
        foreach ($this->nonce as $n) {
            if (str_contains($v, $n)) {
                $are = true;
                break;
            }
        }
        if (!$are) {
            return $v;
        }
        $nou = (string) preg_replace_callback($this->reNonceJson, static function (array $m) use (&$fel): string {
            if ($m['v'] === '') {
                return $m[0];
            }
            $fel[] = 'nonce';
            return $m['q'] . $m['k'] . $m['q'] . substr($m[0], strlen($m['q'] . $m['k'] . $m['q']), -strlen($m['v'] . $m['q'])) . self::LOC_NONCE . $m['q'];
        }, $v);
        return $nou;
    }

    /**
     * Doar pentru comparație: `&#NNN;` / `&#xHH;` ale literelor, cifrelor și „@ . - _” ASCII, decodate (aceleași caractere în DOM).
     *
     * @param list<string> $fel
     */
    private static function entitatiText(string $v, array &$fel): string
    {
        return (string) preg_replace_callback('/&#(?:([0-9]{1,7})|[xX]([0-9a-fA-F]{1,6}));/', static function (array $m) use (&$fel): string {
            $cod = ($m[2] ?? '') !== '' ? hexdec($m[2]) : (int) $m[1];
            $c = $cod < 128 ? chr((int) $cod) : '';
            if ($c !== '' && preg_match('/^[A-Za-z0-9@._-]$/', $c) === 1) {
                $fel = ['entitati-text'];
                return $c;
            }
            return $m[0];
        }, $v);
    }

    /** @param list<string> $fel */
    private function secrete(string $v, array &$fel): string
    {
        if (!str_contains($v, 'secret')) {
            return $v;
        }
        $nou = (string) preg_replace_callback('/(data-secret=(?:&quot;|&#034;|\\\\"|"|\'))([A-Za-z0-9]{10})(?![A-Za-z0-9])|(#\?secret=)([A-Za-z0-9]{10})(?![A-Za-z0-9])/',
            static function (array $m) use (&$fel): string {
                $fel[] = 'secret';
                return ($m[1] !== '' ? $m[1] : $m[3]) . self::LOC_SECRET;
            }, $v);
        return $nou;
    }

    /**
     * Adresele proprii absolute → relative. `$comparatie`: doar forma canonică (schema și gazda exactă din adresa site-ului, plus
     * `//gazdă`); http pe un site https și perechea www rămân absolute, ca o diferență între ele să se vadă la comparație.
     *
     * @param list<string> $fel
     */
    private function adrese(string $v, array &$fel, bool $comparatie = false): string
    {
        $jos = strtolower($v);
        $are = false;
        foreach ($this->gazde as $g) {
            if (str_contains($jos, $g)) {
                $are = true;
                break;
            }
        }
        if (!$are) {
            return $v;
        }
        if (preg_match_all($comparatie ? $this->reAdresaCanonica : $this->reAdresa, $v, $m, PREG_OFFSET_CAPTURE) === 0) {
            return $v;
        }
        $rez = '';
        $poz = 0;
        foreach ($m[0] as [$potrivire, $start]) {
            $sfarsit = $start + strlen($potrivire);
            $inlocuire = self::inlocuireSigura($v, $sfarsit, str_contains($potrivire, '\\/'));
            if ($inlocuire === null) {
                continue; // gazda nu e sigur a noastră (sau calea ar duce la alta): adresa rămâne neatinsă
            }
            $rez .= substr($v, $poz, $start - $poz) . $inlocuire;
            $poz = $sfarsit;
            $fel[] = 'adresa-proprie';
        }
        return $rez . substr($v, $poz);
    }

    /**
     * Ce pune adresa relativă în locul prefixului `schema://gazdă` care se termină la `$i`, sau null dacă adresa trebuie lăsată
     * neatinsă (recenzia dialectului, B). Un browser citește gazda după standardul WHATWG: la http(s) „\” e „/”, iar tab, CR și
     * LF se scot de oriunde din adresă. Rescrierea nu are voie să schimbe gazda la care duce adresa, deci se face doar când:
     *  - gazda citită e exact gazda potrivită: după ea vine sfârșitul textului, „/”, „?”, „#” sau sfârșitul adresei în context
     *    (ghilimele, `&quot;`, „)”, spațiu, „<”, „>”); orice altceva (`@` al unui userinfo, „:”, „%”, „\”, „,”, o entitate,
     *    un tab, CR sau LF care ar lipi gazda de ce urmează) o lasă neatinsă;
     *  - rezultatul nu începe cu `//`, `/\`, `\/` sau `\\` (și nici cu ele după scoaterea lui tab/CR/LF), adică nu devine
     *    o adresă protocol-relativă spre altă gazdă. În forma JSON (`\/\/gazdă`), `\/` e „/”.
     */
    private static function inlocuireSigura(string $v, int $i, bool $escapat): ?string
    {
        $gol = $escapat ? '\\/' : '/';
        if ($i >= strlen($v)) {
            return $gol;
        }
        $c = $v[$i];
        $dupa = null;
        if ($c === '/') {
            $dupa = $i + 1;
        } elseif (substr($v, $i, 2) === '\\/') {
            $dupa = $i + 2;
        }
        if ($dupa !== null) {
            // Calea începe aici: doar prefixul pleacă, dacă al doilea caracter al căii nu o face protocol-relativă.
            $d = $v[$dupa] ?? '';
            return in_array($d, ['/', '\\', "\t", "\r", "\n", '&'], true) ? null : '';
        }
        if (in_array($c, ['?', '#', '"', "'", ')', ' ', '<', '>'], true)) {
            return $gol;
        }
        if ($c === '&' && preg_match('/^&(?:quot|#0*34|#x0*22);/i', substr($v, $i, 12)) === 1) {
            return $gol;
        }
        return null;
    }

    /** @param list<string> $fel */
    private function faraClaseSistem(string $clase, array &$fel): string
    {
        // Fiecare clasă de sistem pleacă cu spațiul dinaintea ei (sau cu cel de după, dacă e prima); restul octeților rămâne.
        $rez = (string) preg_replace_callback('/(^|[\t\n\f\r ]+)([^\t\n\f\r ]+)(?=[\t\n\f\r ]|$)/', static function (array $m) use (&$fel): string {
            if (in_array(strtolower($m[2]), self::CLASE_SISTEM, true)) {
                $fel[] = 'clasa-ua';
                return '';
            }
            return $m[0];
        }, $clase);
        // O clasă de sistem aflată prima lasă spațiul de după ea în față: îl scoatem, ca restul să rămână ca în captură.
        if ($rez !== $clase && $clase !== '' && strpbrk($clase[0], "\t\n\f\r ") === false) {
            $rez = ltrim($rez, "\t\n\f\r ");
        }
        return $rez;
    }

    /**
     * Prima diferență dintre doi octeți-șiruri (pentru NU-ul comparației pe UA): poziția și câte 60 de octeți din jur.
     *
     * @return array{octet: int, a: string, b: string}|null
     */
    public static function primaDiferenta(string $a, string $b): ?array
    {
        if ($a === $b) {
            return null;
        }
        $n = min(strlen($a), strlen($b));
        $i = 0;
        while ($i < $n && $a[$i] === $b[$i]) {
            $i++;
        }
        $de = max(0, $i - 30);
        $taie = static fn (string $s): string => (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '?', mb_convert_encoding(substr($s, $de, 90), 'UTF-8', 'UTF-8'));
        return ['octet' => $i, 'a' => $taie($a), 'b' => $taie($b)];
    }
}
