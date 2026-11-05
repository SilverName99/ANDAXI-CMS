<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Pluginul `campanii` (cerut pentru Biovitality, 5 nov 2026; docs/PLUGINURI.md, „Campanii pe site”): o campanie programată,
 * cu fereastra ei (început, sfârșit), care pe site are trei bucăți, toate doar cât campania e în fereastră:
 *
 *  1. **Pagina de deschidere.** La fiecare intrare nouă pe site (o vizită nouă: de pe orice link din afară, dar și fără
 *     Referer: adresa scrisă de mână, un semn de carte, o aplicație care nu trimite Referer), vizitatorul e trimis o dată pe
 *     vizită (cookie de sesiune, strict necesar) la `/campanie/<slug>?inapoi=<adresa cerută>`, unde vede pagina aleasă din
 *     Conținut → Pagini (editorul de pagini al nucleului) și, în stânga sus, butonul „← Înapoi în site-ul principal”, care
 *     duce la adresa cerută la început. Niciodată pentru roboți (Google vede site-ul ca înainte), admin, coș, checkout, cont,
 *     plăți și confirmările lor, API, adresele platformei, fișiere, cereri care nu sunt GET de document, navigarea din
 *     interiorul site-ului și pagina campaniei însăși (`deInterceptat`).
 *  2. **Insigna** (textul ales, implicit „Campanie”) pe produsele alese: pe pagina produsului, pe cardurile catalogului,
 *     în grila `{{featured_products}}` și pe cardurile produselor similare (banda de pe produs și `{{product_produse_similare}}`).
 *  3. **Bannerul de sus**: textul, culorile, fontul, mărimea, lipit sau nu la derulare; linkul scris în campanie sau, fără el
 *     (cât pagina campaniei e pornită), pagina campaniei cu întoarcerea la pagina curentă: singurul drum prin care
 *     vizitatorul o mai vede în aceeași vizită.
 *
 * Mai multe campanii se pot pregăti, dar două campanii pornite nu au voie să aibă ferestre care se suprapun (refuzat la
 * salvare), deci în orice clipă e cel mult una activă. Fără nicio campanie activă (sau cu pluginul oprit) site-ul iese
 * octet cu octet ca înainte; pe o bază fără tabelele migrării `campanii/0054` nimic nu e activ.
 */
final class Campanii
{
    /** Cookie-ul „am văzut pagina campaniei <id>”: de sesiune (fără Expires), HttpOnly, SameSite=Lax. */
    public const COOKIE = 'campanie_vazuta';

    public const TEXT_INAPOI = '← Înapoi în site-ul principal';
    public const INSIGNA_IMPLICITA = 'Campanie';

    /** Valorile implicite ale culorilor și ale bannerului (un câmp gol la salvare ia valoarea de aici). */
    public const IMPLICITE = [
        'insigna_fundal' => '#e31c23',
        'insigna_culoare' => '#ffffff',
        'banner_fundal' => '#1f2937',
        'banner_culoare' => '#ffffff',
        'banner_font' => 'site',
        'banner_marime' => 15,
    ];

    public const MARIME_MIN = 11;
    public const MARIME_MAX = 32;

    /**
     * Fonturile bannerului: cele din nucleu (`public/assets/vendor/fonturi/`, locale, fără Google) și câteva de sistem.
     *
     * @var array<string, array{eticheta: string, familie: string, css: ?string}>
     */
    public const FONTURI = [
        'site' => ['eticheta' => 'Fontul site-ului', 'familie' => 'inherit', 'css' => null],
        'dm-sans' => ['eticheta' => 'DM Sans', 'familie' => "'DM Sans', Arial, sans-serif", 'css' => '/assets/vendor/fonturi/dm-sans/dm-sans.css'],
        'playfair' => ['eticheta' => 'Playfair Display', 'familie' => "'Playfair Display', Georgia, serif", 'css' => '/assets/vendor/fonturi/playfair/playfair.css'],
        'rubik' => ['eticheta' => 'Rubik', 'familie' => "'Rubik', Arial, sans-serif", 'css' => '/assets/vendor/fonturi/rubik/rubik.css'],
        'ibm-plex' => ['eticheta' => 'IBM Plex Sans', 'familie' => "'IBM Plex Sans', Arial, sans-serif", 'css' => '/assets/vendor/fonturi/ibm-plex/ibm-plex.css'],
        'arial' => ['eticheta' => 'Arial', 'familie' => 'Arial, Helvetica, sans-serif', 'css' => null],
        'georgia' => ['eticheta' => 'Georgia', 'familie' => 'Georgia, serif', 'css' => null],
        'verdana' => ['eticheta' => 'Verdana', 'familie' => 'Verdana, Geneva, sans-serif', 'css' => null],
        'sistem' => ['eticheta' => 'Fontul telefonului / calculatorului', 'familie' => "system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif", 'css' => null],
    ];

    /** Stările unei campanii, cu numele din admin. */
    public const STARI = [
        'activa' => 'Activă acum',
        'programata' => 'Programată',
        'incheiata' => 'Încheiată',
        'oprita' => 'Oprită',
    ];

    /**
     * Roboții și previzualizările de linkuri (Google, Bing, rețelele sociale, unelte de verificare): nu se trimit niciodată
     * la pagina campaniei. Fără User-Agent = tot robot.
     */
    private const ROBOTI = '/bot\b|bot\/|crawl|spider|slurp|mediapartners|adsbot|google-|googleother|feedfetcher|bingpreview'
        . '|facebookexternalhit|facebot|meta-externalagent|twitterbot|linkedinbot|pinterest|whatsapp|telegram|discord|skype|slack'
        . '|embedly|quora link|vkshare|redditbot|applebot|yandex|baidu|duckduck|petal|semrush|ahrefs|mj12|dotbot|bytespider'
        . '|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|headlesschrome|phantomjs|curl\/|wget|python|go-http|java\/'
        . '|okhttp|axios|node-fetch|httpclient|libwww|scrapy|preview|validator|checker/i';

    /** Adresele care nu se opresc niciodată, pe lângă cele excluse din stratul SEO (SeoRedirects::eExclusa) și robots.txt. */
    private const CAI_NEINTERCEPTATE = ['/campanie', '/cont', '/og', '/tema', '/sitemap', '/feed', '/review-form', '/_verificare'];

    /** @var array<string, mixed>|false|null false = încă necitită în această cerere */
    private static array|false|null $activa = false;

    /** @var list<int>|null */
    private static ?array $produseActive = null;

    /** Pagina campaniei randată acum (adresa butonului și textul lui), pentru bara de sus; null pe celelalte pagini. */
    private static ?array $paginaCurenta = null;

    private static ?PDO $db = null;

    // ── Campania activă ────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Campania activă acum (pornită, în fereastra ei), sau null: pluginul nu e pe site, nicio campanie, baza fără tabele.
     * Citită o singură dată pe cerere.
     *
     * @return array<string, mixed>|null
     */
    public static function activa(?PDO $db = null): ?array
    {
        if (self::$activa !== false) {
            return self::$activa;
        }
        self::$activa = null;
        if (!Plugins::vizibil('campanii')) {
            return null;
        }
        $db ??= self::db();
        if (!$db instanceof PDO) {
            return null;
        }
        try {
            $stmt = $db->prepare('SELECT * FROM campanii
                WHERE pornita = 1 AND incepe_la IS NOT NULL AND se_termina_la IS NOT NULL AND incepe_la <= :acum AND se_termina_la > :acum2
                ORDER BY incepe_la DESC, id DESC LIMIT 1');
            $acum = date('Y-m-d H:i:s');
            $stmt->execute(['acum' => $acum, 'acum2' => $acum]);
            $rand = $stmt->fetch(PDO::FETCH_ASSOC);
            self::$activa = is_array($rand) ? $rand : null;
        } catch (Throwable) {
            // O bază fără migrarea campanii/0054 (sau căzută): nicio campanie, site-ul ca înainte.
            self::$activa = null;
        }
        return self::$activa;
    }

    /** Starea unei campanii față de momentul dat (implicit acum). */
    public static function stare(array $campanie, ?int $moment = null): string
    {
        $moment ??= time();
        $inceput = self::moment($campanie['incepe_la'] ?? null);
        $sfarsit = self::moment($campanie['se_termina_la'] ?? null);
        if ((int) ($campanie['pornita'] ?? 0) !== 1 || $inceput === null || $sfarsit === null) {
            return 'oprita';
        }
        if ($moment < $inceput) {
            return 'programata';
        }
        return $moment < $sfarsit ? 'activa' : 'incheiata';
    }

    /**
     * Partea din cheia cache-ului paginilor (ResponseCache): goală fără campanie activă (cheia rămâne exact cea de dinainte),
     * altfel campania și ultima ei salvare, ca o pagină randată fără banner (sau cu bannerul vechi) să nu se mai servească.
     */
    public static function cheieCache(): string
    {
        $activa = self::activa();
        return $activa === null ? '' : '|campanie:' . (int) $activa['id'] . ':' . (string) ($activa['updated_at'] ?? '');
    }

    /** @return list<int> produsele campaniei active (cu insignă) */
    public static function produseActive(): array
    {
        if (self::$produseActive !== null) {
            return self::$produseActive;
        }
        $activa = self::activa();
        $db = self::db();
        self::$produseActive = $activa !== null && $db instanceof PDO ? self::idProduse($db, (int) $activa['id']) : [];
        return self::$produseActive;
    }

    /** @return list<int> */
    public static function idProduse(PDO $db, int $idCampanie): array
    {
        try {
            $stmt = $db->prepare('SELECT product_id FROM campanii_produse WHERE campanie_id = :id ORDER BY product_id');
            $stmt->execute(['id' => $idCampanie]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {
            return [];
        }
    }

    // ── Insigna ───────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Insigna produsului pe cardul din catalog (views/site/components/product-card.php), sau '' (produsul nu e în campania
     * activă, nicio campanie, catalogul nu e pe site). Stă jos în stânga pozei: sus sunt reducerea (dreapta) și ofertele (stânga).
     */
    public static function insignaCard(array $produs): string
    {
        $text = self::textInsigna($produs);
        if ($text === '') {
            return '';
        }
        [$fundal, $culoare] = self::culoriInsigna();
        return '<span class="campanie-insigna campanie-insigna--card" style="position:absolute;left:8px;bottom:8px;z-index:2;'
            . 'max-width:calc(100% - 16px);padding:6px 10px;border-radius:999px;background:' . $fundal . ';color:' . $culoare . ';'
            . 'font:800 11px/1.15 &quot;DM Sans&quot;,Arial,sans-serif;letter-spacing:.04em;text-transform:uppercase;white-space:nowrap;'
            . 'overflow:hidden;text-overflow:ellipsis;box-shadow:0 6px 14px rgba(15,23,42,.18);">' . self::e($text) . '</span>';
    }

    /** Insigna de pe pagina produsului (views/site/product.php, deasupra produsului), sau ''. */
    public static function insignaPagina(array $produs): string
    {
        $text = self::textInsigna($produs);
        if ($text === '') {
            return '';
        }
        [$fundal, $culoare] = self::culoriInsigna();
        return '<p class="campanie-insigna-produs" style="margin:0 0 12px;"><span class="campanie-insigna campanie-insigna--pagina" '
            . 'style="display:inline-block;max-width:100%;padding:7px 14px;border-radius:999px;background:' . $fundal . ';color:' . $culoare . ';'
            . 'font:800 13px/1.2 &quot;DM Sans&quot;,Arial,sans-serif;letter-spacing:.04em;text-transform:uppercase;overflow-wrap:anywhere;">'
            . self::e($text) . '</span></p>';
    }

    /**
     * Insigna în fluxul cardului (nu peste poză), pentru cardurile al căror stil vine din pagină sau din temă: grila
     * `{{featured_products}}` (HomeSections), banda `{{product_produse_similare}}` și cardurile produselor similare. ''
     * fără campanie activă sau pentru un produs care nu e în ea.
     */
    public static function insignaInline(array $produs, string $varianta): string
    {
        $text = self::textInsigna($produs);
        return $text === '' ? '' : '<span class="campanie-insigna campanie-insigna--' . self::e($varianta) . '" style="'
            . self::stilInsignaInline() . '">' . self::e($text) . '</span>';
    }

    /** Stilul insignei din fluxul cardului (fără apostrofuri: intră și într-un șir JavaScript, în views/site/product.php). */
    public static function stilInsignaInline(): string
    {
        [$fundal, $culoare] = self::culoriInsigna();
        return 'display:inline-block;max-width:100%;margin:0 0 8px;padding:5px 10px;border-radius:999px;background:' . $fundal
            . ';color:' . $culoare . ';font:800 11px/1.2 &quot;DM Sans&quot;,Arial,sans-serif;letter-spacing:.04em;'
            . 'text-transform:uppercase;overflow-wrap:anywhere;';
    }

    /**
     * Produsele similare (lista din JSON-ul benzii de pe pagina produsului): cele din campania activă primesc cheia
     * `campanie_insigna` (textul insignei). Fără campanie activă lista rămâne exact aceeași.
     *
     * @param list<array<string, mixed>> $produse
     * @return list<array<string, mixed>>
     */
    public static function cuInsigne(array $produse): array
    {
        if (self::activa() === null) {
            return $produse;
        }
        foreach ($produse as $i => $produs) {
            if (is_array($produs) && ($text = self::textInsigna($produs)) !== '') {
                $produse[$i]['campanie_insigna'] = $text;
            }
        }
        return $produse;
    }

    public static function textInsigna(array $produs): string
    {
        $id = (int) ($produs['id'] ?? 0);
        if ($id <= 0 || !Plugins::vizibil('catalog')) {
            return '';
        }
        $activa = self::activa();
        if ($activa === null || !in_array($id, self::produseActive(), true)) {
            return '';
        }
        $text = trim((string) ($activa['insigna_text'] ?? ''));
        return $text !== '' ? $text : self::INSIGNA_IMPLICITA;
    }

    /** @return array{0: string, 1: string} */
    private static function culoriInsigna(): array
    {
        $activa = self::activa() ?? [];
        return [
            self::culoare((string) ($activa['insigna_fundal'] ?? ''), self::IMPLICITE['insigna_fundal']),
            self::culoare((string) ($activa['insigna_culoare'] ?? ''), self::IMPLICITE['insigna_culoare']),
        ];
    }

    // ── Bannerul și bara paginii campaniei (punctul layout.inceput_body) ──────────────────────────────────────────────

    /**
     * Agățarea de `layout.inceput_body` (config/hooks.php): pe pagina campaniei, bara cu butonul „Înapoi”; pe celelalte
     * pagini publice, bannerul campaniei active (dacă e pornit și are text). Fără campanie activă: ''.
     */
    public static function inceputBody(): string
    {
        if (self::$paginaCurenta !== null) {
            return self::baraInapoi((string) self::$paginaCurenta['inapoi'], (string) self::$paginaCurenta['text'],
                (bool) (self::$paginaCurenta['previzualizare'] ?? false));
        }
        $activa = self::activa();
        if ($activa === null || (int) ($activa['banner_pornit'] ?? 0) !== 1 || trim((string) ($activa['banner_text'] ?? '')) === '') {
            return '';
        }
        return self::htmlBanner($activa, false, self::linkImplicitBanner($activa, (string) ($_SERVER['REQUEST_URI'] ?? '/')));
    }

    /**
     * Linkul bannerului fără un link scris în campanie: pagina campaniei, cu întoarcerea la pagina pe care e vizitatorul
     * acum (doar cât campania are pagina pornită: pagină aleasă și „Arată pagina campaniei la prima intrare”); altfel ''.
     */
    public static function linkImplicitBanner(array $c, string $cerere): string
    {
        if ((int) ($c['pagina_la_intrare'] ?? 0) !== 1 || (int) ($c['pagina_id'] ?? 0) <= 0 || trim((string) ($c['slug'] ?? '')) === '') {
            return '';
        }
        return self::adresaPagina((string) $c['slug'], self::inapoiSigur($cerere));
    }

    /**
     * Bannerul de sus al unei campanii (și previzualizarea lui din admin, aceeași funcție).
     *
     * @param array<string, mixed> $c
     */
    public static function htmlBanner(array $c, bool $previzualizare = false, string $linkImplicit = ''): string
    {
        $font = self::FONTURI[(string) ($c['banner_font'] ?? '')] ?? self::FONTURI['site'];
        $marime = self::marime($c['banner_marime'] ?? null);
        $fundal = self::culoare((string) ($c['banner_fundal'] ?? ''), self::IMPLICITE['banner_fundal']);
        $culoare = self::culoare((string) ($c['banner_culoare'] ?? ''), self::IMPLICITE['banner_culoare']);
        $lipit = (int) ($c['banner_lipit'] ?? 0) === 1 && !$previzualizare;
        $text = self::e(trim((string) ($c['banner_text'] ?? '')));
        // Linkul scris în campanie are întâietate; fără el, pagina campaniei (linkImplicitBanner), cât ea e pornită.
        $link = self::linkBanner((string) ($c['banner_link'] ?? ''));
        if ($link === '') {
            $link = $linkImplicit;
        }
        $continut = $link !== ''
            ? '<a href="' . self::e($link) . '" style="color:inherit;text-decoration:underline;text-underline-offset:3px;">' . $text . '</a>'
            : $text;
        $html = $font['css'] !== null ? '<link rel="stylesheet" href="' . self::e($font['css']) . '">' : '';
        $html .= '<div class="campanie-banner" role="region" aria-label="Campanie" data-campanie-banner'
            . ($lipit ? ' data-lipit="1"' : '')
            . ' style="' . ($lipit ? 'position:sticky;top:0;z-index:1040;' : 'position:relative;z-index:5;')
            . 'background:' . $fundal . ';color:' . $culoare . ';font-family:' . self::e($font['familie']) . ';font-size:' . $marime . 'px;">'
            . '<div class="campanie-banner__text" style="box-sizing:border-box;max-width:1200px;margin:0 auto;padding:10px 16px;'
            . 'text-align:center;line-height:1.35;font-weight:600;overflow-wrap:anywhere;">' . $continut . '</div></div>';
        return $html;
    }

    /** Bara de sus a paginii campaniei: butonul „Înapoi în site-ul principal”, în stânga. */
    public static function baraInapoi(string $inapoi, string $text, bool $previzualizare = false): string
    {
        $text = trim($text) !== '' ? trim($text) : self::TEXT_INAPOI;
        $principala = Tema::culoarePrincipala();
        $nota = $previzualizare
            ? '<span style="margin-left:auto;font:600 13px/1.3 system-ui,Arial,sans-serif;color:#92400e;background:#fffbeb;'
                . 'border:1px solid #f59e0b;border-radius:8px;padding:6px 10px;">Previzualizare: campania nu e activă acum</span>'
            : '';
        return '<div class="campanie-inapoi" data-campanie-inapoi style="position:sticky;top:0;z-index:1050;box-sizing:border-box;'
            . 'display:flex;flex-wrap:wrap;align-items:center;gap:8px;width:100%;padding:10px 16px;background:#ffffff;'
            . 'border-bottom:1px solid #e5e7eb;box-shadow:0 2px 10px rgba(15,23,42,.06);">'
            . '<a class="campanie-inapoi__buton" href="' . self::e($inapoi) . '" style="display:inline-flex;align-items:center;'
            . 'min-height:44px;box-sizing:border-box;max-width:100%;padding:10px 18px;border-radius:999px;background:' . self::e($principala) . ';'
            . 'color:#ffffff;font:700 15px/1.2 system-ui,-apple-system,\'Segoe UI\',Roboto,Arial,sans-serif;text-decoration:none;'
            . 'overflow-wrap:anywhere;">' . self::e($text) . '</a>' . $nota . '</div>';
    }

    /** Pagina randată acum e pagina campaniei? (Layoutul nu-i pune deasupra popup-ul de anunț.) */
    public static function ePaginaCampaniei(): bool
    {
        return self::$paginaCurenta !== null;
    }

    /** Marchează pagina curentă ca pagina campaniei (o cheamă CampaniiController înaintea randării). */
    public static function randeazaPagina(string $inapoi, string $text, bool $previzualizare): void
    {
        self::$paginaCurenta = ['inapoi' => $inapoi, 'text' => $text, 'previzualizare' => $previzualizare];
    }

    // ── Prima intrare pe site ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * La începutul cererii (public/index.php, înaintea cache-ului de pagini): dacă vizitatorul intră acum pe site și nu a
     * văzut încă pagina campaniei active, îl trimite acolo (302), cu adresa cerută în `inapoi`. Întoarce true dacă a trimis.
     */
    public static function intercepteaza(?PDO $db = null): bool
    {
        if (PHP_SAPI === 'cli' || headers_sent() || !Plugins::vizibil('campanii')) {
            return false;
        }
        // Întâi tot ce nu cere baza: majoritatea cererilor se opresc aici.
        if (!self::deInterceptat($_SERVER, $_COOKIE, $_SESSION ?? [], null)) {
            return false;
        }
        $activa = self::activa($db);
        if ($activa === null || !self::deInterceptat($_SERVER, $_COOKIE, $_SESSION ?? [], $activa)
            || !self::arePagina($db ?? self::db(), $activa)) {
            return false;
        }
        self::marcheazaVazuta((int) $activa['id']);
        $cerere = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        header('Cache-Control: no-store, private');
        header('Location: ' . self::adresaPagina((string) $activa['slug'], self::inapoiSigur($cerere)), true, 302);
        return true;
    }

    /**
     * Regula opririi, fără bază (testată direct în tools/dev/test-campanii.php). Cu `$campanie` null verifică doar
     * cererea; cu o campanie, și dacă e o campanie cu pagină la intrare pe care vizitatorul n-a văzut-o încă.
     *
     * @param array<string, mixed> $server  $_SERVER
     * @param array<string, mixed> $cookie  $_COOKIE
     * @param array<string, mixed> $sesiune $_SESSION
     * @param array<string, mixed>|null $campanie
     */
    public static function deInterceptat(array $server, array $cookie, array $sesiune, ?array $campanie): bool
    {
        if (strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return false;
        }
        $cerere = (string) ($server['REQUEST_URI'] ?? '/');
        $cale = (string) (parse_url($cerere, PHP_URL_PATH) ?? '/');
        if ($cale === '' || $cale[0] !== '/') {
            return false;
        }
        if (self::eRobot((string) ($server['HTTP_USER_AGENT'] ?? ''))) {
            return false;
        }
        // Doar navigarea unui om spre o pagină: browserele cer text/html; fetch, iframe-uri, preîncărcările și AJAX nu.
        if (!str_contains(strtolower((string) ($server['HTTP_ACCEPT'] ?? '')), 'text/html')) {
            return false;
        }
        $dest = strtolower(trim((string) ($server['HTTP_SEC_FETCH_DEST'] ?? '')));
        $mod = strtolower(trim((string) ($server['HTTP_SEC_FETCH_MODE'] ?? '')));
        if (($dest !== '' && $dest !== 'document') || ($mod !== '' && $mod !== 'navigate')) {
            return false;
        }
        $scop = strtolower((string) ($server['HTTP_SEC_PURPOSE'] ?? $server['HTTP_PURPOSE'] ?? $server['HTTP_X_PURPOSE'] ?? ''));
        if (str_contains($scop, 'prefetch') || str_contains($scop, 'prerender') || str_contains($scop, 'preview')
            || trim((string) ($server['HTTP_X_REQUESTED_WITH'] ?? '')) !== '') {
            return false;
        }
        // O intrare nouă: fără Referer (adresa scrisă, semn de carte, aplicație) sau cu Referer de pe alt site, da (decizia
        // proprietarului din 5 nov). Navigarea din interiorul site-ului (un link al site-ului, butonul „Înapoi” al campaniei):
        // niciodată; așa nici un vizitator fără cookie-uri nu e trimis la campanie la fiecare clic. În aceeași vizită pagina
        // campaniei se mai vede doar din banner.
        $deUnde = strtolower((string) parse_url((string) ($server['HTTP_REFERER'] ?? ''), PHP_URL_HOST));
        $gazda = strtolower((string) preg_replace('/:\d+$/', '', (string) ($server['HTTP_HOST'] ?? '')));
        $faraWww = static fn (string $g): string => str_starts_with($g, 'www.') ? substr($g, 4) : $g;
        if ($deUnde !== '' && ($faraWww($deUnde) === $faraWww($gazda)
            || $faraWww($deUnde) === $faraWww(strtolower((string) parse_url(AppUrl::base(), PHP_URL_HOST))))) {
            return false;
        }
        if ((int) ($sesiune['admin_id'] ?? 0) > 0) {
            return false;
        }
        if (!self::caleDeInterceptat($cale, (string) (parse_url($cerere, PHP_URL_QUERY) ?? ''))) {
            return false;
        }
        if ($campanie === null) {
            return true;
        }
        if ((int) ($campanie['pagina_la_intrare'] ?? 0) !== 1 || (int) ($campanie['pagina_id'] ?? 0) <= 0
            || trim((string) ($campanie['slug'] ?? '')) === '') {
            return false;
        }
        return (string) ($cookie[self::COOKIE] ?? '') !== (string) (int) ($campanie['id'] ?? 0);
    }

    /** O pagină a site-ului pe care se poate pune campania în față (nu coș, plată, cont, admin, API, fișiere…). */
    public static function caleDeInterceptat(string $cale, string $query = ''): bool
    {
        $mic = strtolower($cale);
        if (SeoRedirects::eExclusa($mic) || Robots::blocheaza($mic)) {
            return false;
        }
        foreach (self::CAI_NEINTERCEPTATE as $prefix) {
            if ($mic === $prefix || str_starts_with($mic, $prefix . '/') || ($prefix === '/sitemap' && str_starts_with($mic, '/sitemap'))) {
                return false;
            }
        }
        // Fișiere (o poză, un script, un PDF): niciodată.
        if (preg_match('#\.[a-z0-9]{1,5}$#i', $mic) === 1) {
            return false;
        }
        // Paginile nucleului, ale catalogului (magazinul, categoriile, produsele) și ale blogului da; ale celorlalte pluginuri
        // (coșul, checkout-ul, plata, contul, acordurile GDPR, newsletterul…) și paginile de sistem ale hărții site-ului nu.
        $slug = trim(rawurldecode($mic), '/');
        if ($slug !== '' && (!in_array(Plugins::proprietarPagina($slug), [null, 'catalog', 'blog'], true)
            || in_array($slug, Sitemap::PAGINI_SISTEM, true) || str_starts_with($slug, 'checkout/') || str_starts_with($slug, 'contul-meu/'))) {
            return false;
        }
        // Linkul de ciornă al unui produs și o pagină cerută deja cu întoarcerea campaniei.
        parse_str($query, $parametri);
        return !isset($parametri['draft']) && !isset($parametri['inapoi']);
    }

    public static function eRobot(string $userAgent): bool
    {
        $userAgent = trim($userAgent);
        return $userAgent === '' || preg_match(self::ROBOTI, $userAgent) === 1;
    }

    /**
     * Adresa de întoarcere, doar o cale a acestui site (`/…`, fără `//`, fără schemă, fără `\`, fără caractere de control,
     * nu pagina campaniei): altfel prima pagină. Nicio redirecționare deschisă spre alt site.
     */
    public static function inapoiSigur(string $valoare): string
    {
        $valoare = trim($valoare);
        if ($valoare === '' || strlen($valoare) > 2000 || $valoare[0] !== '/' || str_starts_with($valoare, '//')
            || str_contains($valoare, '\\') || preg_match('/[\x00-\x20\x7f]/', $valoare) === 1) {
            return '/';
        }
        $decodat = rawurldecode($valoare);
        if (str_starts_with($decodat, '//') || str_contains($decodat, '\\') || preg_match('/[\x00-\x1f\x7f]/', $decodat) === 1) {
            return '/';
        }
        $parti = parse_url($valoare);
        if (!is_array($parti) || isset($parti['scheme']) || isset($parti['host']) || isset($parti['user'])) {
            return '/';
        }
        $cale = strtolower((string) ($parti['path'] ?? '/'));
        if ($cale === '/campanie' || str_starts_with($cale, '/campanie/')) {
            return '/';
        }
        return $valoare;
    }

    public static function adresaPagina(string $slug, string $inapoi = '/'): string
    {
        return '/campanie/' . rawurlencode($slug) . ($inapoi !== '' ? '?inapoi=' . rawurlencode($inapoi) : '');
    }

    /**
     * Cookie-ul „am văzut pagina campaniei”: strict necesar (ține minte doar că vizitatorul a trecut deja prin pagină, ca
     * să n-o mai vadă la fiecare clic), deci fără acordul pentru cookie-uri; de sesiune (dispare la închiderea browserului),
     * doar id-ul campaniei, nimic despre vizitator.
     */
    public static function marcheazaVazuta(int $idCampanie): void
    {
        if (headers_sent() || $idCampanie <= 0) {
            return;
        }
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        setcookie(self::COOKIE, (string) $idCampanie, ['path' => '/', 'samesite' => 'Lax', 'secure' => $https, 'httponly' => true]);
        $_COOKIE[self::COOKIE] = (string) $idCampanie;
    }

    /** Pagina de deschidere a campaniei, din Conținut → Pagini (publicată sau ciornă; nu una din coș), sau null. */
    public static function pagina(?PDO $db, array $campanie): ?array
    {
        $id = (int) ($campanie['pagina_id'] ?? 0);
        if (!$db instanceof PDO || $id <= 0) {
            return null;
        }
        try {
            $stmt = $db->prepare('SELECT id, title, slug, html_content, css_content, js_content FROM pages
                WHERE id = :id AND deleted_at IS NULL LIMIT 1');
            $stmt->execute(['id' => $id]);
            $rand = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($rand) ? $rand : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function arePagina(?PDO $db, array $campanie): bool
    {
        return self::pagina($db, $campanie) !== null;
    }

    // ── Adminul: lista, citirea, salvarea ─────────────────────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> toate campaniile, cele mai noi întâi, cu numărul produselor */
    public static function lista(PDO $db): array
    {
        try {
            return $db->query('SELECT c.*, (SELECT COUNT(*) FROM campanii_produse p WHERE p.campanie_id = c.id) AS numar_produse
                FROM campanii c ORDER BY (c.incepe_la IS NULL) ASC, c.incepe_la DESC, c.id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed>|null */
    public static function citeste(PDO $db, int $id): ?array
    {
        try {
            $stmt = $db->prepare('SELECT * FROM campanii WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $rand = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($rand) ? $rand : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    public static function dupaSlug(PDO $db, string $slug): ?array
    {
        try {
            $stmt = $db->prepare('SELECT * FROM campanii WHERE slug = :slug LIMIT 1');
            $stmt->execute(['slug' => $slug]);
            $rand = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($rand) ? $rand : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Tabelele migrării campanii/0054 există? (Altfel adminul spune să se ruleze migrările.) */
    public static function disponibil(PDO $db): bool
    {
        try {
            $db->query('SELECT 1 FROM campanii LIMIT 1');
            $db->query('SELECT 1 FROM campanii_produse LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** O campanie nouă, cu valorile implicite (formularul gol). */
    public static function noua(): array
    {
        return [
            'id' => 0, 'nume' => '', 'slug' => '', 'pornita' => 0, 'incepe_la' => null, 'se_termina_la' => null, 'pagina_id' => null,
            'pagina_la_intrare' => 1, 'text_inapoi' => self::TEXT_INAPOI, 'insigna_text' => self::INSIGNA_IMPLICITA,
            'insigna_fundal' => self::IMPLICITE['insigna_fundal'], 'insigna_culoare' => self::IMPLICITE['insigna_culoare'],
            'banner_pornit' => 0, 'banner_text' => '', 'banner_link' => '', 'banner_fundal' => self::IMPLICITE['banner_fundal'],
            'banner_culoare' => self::IMPLICITE['banner_culoare'], 'banner_font' => self::IMPLICITE['banner_font'],
            'banner_marime' => self::IMPLICITE['banner_marime'], 'banner_lipit' => 0,
        ];
    }

    /**
     * Citește și verifică formularul din admin. Întoarce campania gata de salvat (cu `produse`, lista id-urilor) și erorile
     * (goală = se poate salva). Datele: `Y-m-d\TH:i` (câmpul datetime-local al browserului), ora României.
     *
     * @param array<string, mixed> $post
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function dinFormular(PDO $db, array $post, int $id): array
    {
        $erori = [];
        $text = static fn (string $cheie, int $max): string => mb_substr(trim((string) ($post[$cheie] ?? '')), 0, $max);
        $c = [
            'id' => $id,
            'nume' => $text('nume', 160),
            'slug' => Slug::make($text('slug', 160) !== '' ? $text('slug', 160) : $text('nume', 160)),
            'pornita' => !empty($post['pornita']) ? 1 : 0,
            'incepe_la' => self::dataFormular((string) ($post['incepe_la'] ?? '')),
            'se_termina_la' => self::dataFormular((string) ($post['se_termina_la'] ?? '')),
            'pagina_id' => (int) ($post['pagina_id'] ?? 0) > 0 ? (int) $post['pagina_id'] : null,
            'pagina_la_intrare' => !empty($post['pagina_la_intrare']) ? 1 : 0,
            'text_inapoi' => $text('text_inapoi', 120),
            'insigna_text' => $text('insigna_text', 40),
            'insigna_fundal' => self::culoare($text('insigna_fundal', 7), self::IMPLICITE['insigna_fundal']),
            'insigna_culoare' => self::culoare($text('insigna_culoare', 7), self::IMPLICITE['insigna_culoare']),
            'banner_pornit' => !empty($post['banner_pornit']) ? 1 : 0,
            'banner_text' => $text('banner_text', 300),
            'banner_link' => $text('banner_link', 500),
            'banner_fundal' => self::culoare($text('banner_fundal', 7), self::IMPLICITE['banner_fundal']),
            'banner_culoare' => self::culoare($text('banner_culoare', 7), self::IMPLICITE['banner_culoare']),
            'banner_font' => isset(self::FONTURI[(string) ($post['banner_font'] ?? '')]) ? (string) $post['banner_font'] : self::IMPLICITE['banner_font'],
            'banner_marime' => self::marime($post['banner_marime'] ?? null),
            'banner_lipit' => !empty($post['banner_lipit']) ? 1 : 0,
        ];
        $produse = [];
        foreach ((array) ($post['produse'] ?? []) as $idProdus) {
            if ((int) $idProdus > 0) {
                $produse[(int) $idProdus] = true;
            }
        }
        $c['produse'] = array_keys($produse);

        if ($c['nume'] === '') {
            $erori[] = 'Scrie numele campaniei.';
        }
        if ($c['slug'] === '') {
            $erori[] = 'Adresa campaniei nu poate fi goală (litere și cifre).';
        } else {
            $alta = self::dupaSlug($db, (string) $c['slug']);
            if ($alta !== null && (int) $alta['id'] !== $id) {
                $erori[] = 'Adresa /campanie/' . $c['slug'] . ' e deja a campaniei „' . (string) $alta['nume'] . '”.';
            }
        }
        if (trim((string) ($post['incepe_la'] ?? '')) !== '' && $c['incepe_la'] === null) {
            $erori[] = 'Data de început nu e o dată.';
        }
        if (trim((string) ($post['se_termina_la'] ?? '')) !== '' && $c['se_termina_la'] === null) {
            $erori[] = 'Data de sfârșit nu e o dată.';
        }
        if ($c['incepe_la'] !== null && $c['se_termina_la'] !== null && $c['se_termina_la'] <= $c['incepe_la']) {
            $erori[] = 'Sfârșitul campaniei trebuie să fie după început.';
        }
        if ($c['pornita'] === 1) {
            if ($c['incepe_la'] === null || $c['se_termina_la'] === null) {
                $erori[] = 'O campanie pornită are nevoie de data de început și de cea de sfârșit.';
            } else {
                foreach (self::lista($db) as $alta) {
                    if ((int) $alta['id'] === $id || (int) $alta['pornita'] !== 1 || $alta['incepe_la'] === null || $alta['se_termina_la'] === null) {
                        continue;
                    }
                    if ((string) $alta['incepe_la'] < (string) $c['se_termina_la'] && (string) $alta['se_termina_la'] > (string) $c['incepe_la']) {
                        $erori[] = 'Se suprapune cu campania pornită „' . (string) $alta['nume'] . '” (' . self::interval($alta)
                            . '). Pe site poate fi activă o singură campanie odată: schimbă datele sau oprește cealaltă campanie.';
                    }
                }
            }
            if ($c['pagina_la_intrare'] === 1 && $c['pagina_id'] === null) {
                $erori[] = 'Alege pagina campaniei (sau debifează „Arată pagina campaniei la prima intrare pe site”).';
            }
        }
        if ($c['pagina_id'] !== null && self::pagina($db, ['pagina_id' => $c['pagina_id']]) === null) {
            $erori[] = 'Pagina aleasă nu mai există.';
        }
        if ($c['banner_link'] !== '' && self::linkBanner((string) $c['banner_link']) === '') {
            $erori[] = 'Linkul bannerului: o adresă a site-ului (/…) sau o adresă întreagă (https://…).';
        }
        if ($c['banner_pornit'] === 1 && $c['banner_text'] === '') {
            $erori[] = 'Scrie textul bannerului (sau debifează „Arată bannerul”).';
        }
        return [$c, $erori];
    }

    /** Salvează campania și produsele ei, într-o tranzacție. Întoarce id-ul. */
    public static function salveaza(PDO $db, array $c): int
    {
        $coloane = ['nume', 'slug', 'pornita', 'incepe_la', 'se_termina_la', 'pagina_id', 'pagina_la_intrare', 'text_inapoi', 'insigna_text',
            'insigna_fundal', 'insigna_culoare', 'banner_pornit', 'banner_text', 'banner_link', 'banner_fundal', 'banner_culoare',
            'banner_font', 'banner_marime', 'banner_lipit'];
        $valori = [];
        foreach ($coloane as $coloana) {
            $valori[$coloana] = $c[$coloana] ?? null;
        }
        $db->beginTransaction();
        try {
            $id = (int) ($c['id'] ?? 0);
            if ($id > 0) {
                $set = implode(', ', array_map(static fn (string $k): string => $k . ' = :' . $k, $coloane));
                $stmt = $db->prepare('UPDATE campanii SET ' . $set . ', updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $stmt->execute($valori + ['id' => $id]);
            } else {
                $stmt = $db->prepare('INSERT INTO campanii (' . implode(', ', $coloane) . ') VALUES (:' . implode(', :', $coloane) . ')');
                $stmt->execute($valori);
                $id = (int) $db->lastInsertId();
            }
            $db->prepare('DELETE FROM campanii_produse WHERE campanie_id = :id')->execute(['id' => $id]);
            if (($c['produse'] ?? []) !== []) {
                $adauga = $db->prepare('INSERT INTO campanii_produse (campanie_id, product_id) VALUES (:c, :p)');
                foreach ((array) $c['produse'] as $idProdus) {
                    $adauga->execute(['c' => $id, 'p' => (int) $idProdus]);
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        self::reseteaza();
        return $id;
    }

    public static function sterge(PDO $db, int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM campanii_produse WHERE campanie_id = :id')->execute(['id' => $id]);
            $stmt = $db->prepare('DELETE FROM campanii WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $db->commit();
        } catch (Throwable) {
            $db->rollBack();
            return false;
        }
        self::reseteaza();
        return $stmt->rowCount() === 1;
    }

    /** „5 nov 2026, 09:00 – 12 nov 2026, 23:59” (sau „fără date”). */
    public static function interval(array $c): string
    {
        $f = static function ($v): string {
            $t = self::moment($v);
            return $t === null ? '?' : date('d.m.Y, H:i', $t);
        };
        if (($c['incepe_la'] ?? null) === null && ($c['se_termina_la'] ?? null) === null) {
            return 'fără date';
        }
        return $f($c['incepe_la'] ?? null) . ' – ' . $f($c['se_termina_la'] ?? null);
    }

    /** Valoarea unui câmp datetime-local (`Y-m-d\TH:i`) dintr-o dată a bazei, sau ''. */
    public static function pentruCamp(mixed $valoare): string
    {
        $t = self::moment($valoare);
        return $t === null ? '' : date('Y-m-d\TH:i', $t);
    }

    // ── Ajutoare ──────────────────────────────────────────────────────────────────────────────────────────────────────

    /** Doar `#rrggbb` (sau `#rgb`, scris lung); altfel valoarea implicită. */
    public static function culoare(string $valoare, string $implicit): string
    {
        $valoare = strtolower(trim($valoare));
        if (preg_match('/^#[0-9a-f]{6}$/', $valoare) === 1) {
            return $valoare;
        }
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $valoare, $m) === 1) {
            return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }
        return $implicit;
    }

    public static function marime(mixed $valoare): int
    {
        $n = is_numeric($valoare) ? (int) $valoare : (int) self::IMPLICITE['banner_marime'];
        return max(self::MARIME_MIN, min(self::MARIME_MAX, $n));
    }

    /** Linkul bannerului: o cale a site-ului (`/…`, nu `//`) sau o adresă http(s) întreagă; altfel ''. */
    public static function linkBanner(string $link): string
    {
        $link = trim($link);
        if ($link === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $link) === 1) {
            return '';
        }
        if ($link[0] === '/' && !str_starts_with($link, '//')) {
            return $link;
        }
        $schema = strtolower((string) parse_url($link, PHP_URL_SCHEME));
        return in_array($schema, ['http', 'https'], true) && (string) parse_url($link, PHP_URL_HOST) !== '' ? $link : '';
    }

    /** Doar pentru teste și după o salvare: campania activă se citește din nou. */
    public static function reseteaza(): void
    {
        self::$activa = false;
        self::$produseActive = null;
        self::$paginaCurenta = null;
    }

    private static function dataFormular(string $valoare): ?string
    {
        $valoare = trim($valoare);
        if ($valoare === '') {
            return null;
        }
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'] as $format) {
            $d = \DateTimeImmutable::createFromFormat('!' . $format, $valoare);
            if ($d instanceof \DateTimeImmutable && $d->format($format) === $valoare) {
                return $d->format('Y-m-d H:i:s');
            }
        }
        return null;
    }

    private static function moment(mixed $valoare): ?int
    {
        if (!is_string($valoare) || trim($valoare) === '') {
            return null;
        }
        $t = strtotime($valoare);
        return $t === false ? null : $t;
    }

    private static function db(): ?PDO
    {
        if (self::$db instanceof PDO) {
            return self::$db;
        }
        $config = require __DIR__ . '/../../config/app.php';
        $db = Database::connection((array) ($config['db'] ?? []));
        return self::$db = $db instanceof PDO ? $db : null;
    }

    private static function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
