<?php

declare(strict_types=1);

namespace App\Support\HtmlCapturat;

/**
 * Schema dialectului `divi4` pentru politica HTML-ului capturat (App\Support\HtmlCapturat, pasul 3): lista închisă a
 * atributelor `data-*` și forma JSON-ului lor, șabloanele configurațiilor inline scoase de dialect (pasul 5a) și regulile
 * scripturilor aruncate. E doar DATE și reguli (fără să citească vreun fișier al furnizorilor, clean-room: lista vine din
 * `INVENTAR.md` și din `design/specificatii/`, nu din codul Divi). O folosesc:
 *  - `HtmlCapturat`, ca să știe ce `data-*` sunt permise și ce chei pot avea (`data-props`, `data-settings`), ce atribute
 *    `data-*` poartă adrese (validate ca scheme sigure) și ce `data-*` poartă HTML (multi-view: validat recursiv prin
 *    aceeași politică);
 *  - dialectul (pasul 5a) și harnașul care încearcă politica pe datele reale: `scriptInert()`, `scriptConfig()` și
 *    `scriptAruncat()` spun cum ar fi mutat un `<script>` (în `ax-date`, aruncat, sau lăsat inert), iar
 *    `handlerRecunoscut()` dă transformarea deterministă a unui `on…` din lista închisă (preload → `data-ax-tarziu`,
 *    embed → `media="all"`). Politica însăși nu transformă nimic: refuză orice `<script>` executabil și orice `on…`.
 *
 * Listele doar se lărgesc după o revizie de om (DECIZIE §2.9): ce nu e aici e refuzat, nu scos în tăcere.
 */
final class SchemaDivi4
{
    /**
     * Atributele `data-*` permise, toate întâlnite în capturile reale plus cele puse de importul nostru (`data-ax-*`).
     * Valoarea: felul conținutului — `scalar` (text simplu, fără schemă de script), `url` (o adresă, validată ca
     * http(s)/relativă/`#`/mailto/tel), `json` (literal JSON, validat recursiv), `html` (HTML, validat recursiv prin
     * politică). Un `data-*` care nu e aici e refuzat.
     */
    public const DATA = [
        // puse de importul nostru (secțiuni, sloturi, grupuri desktop/telefon, CSS-ul amânat — normalizarea §6.7 #4/#6)
        'data-ax-s' => 'scalar', 'data-ax-slot' => 'scalar', 'data-ax-g' => 'scalar', 'data-ax-tarziu' => 'scalar',
        // dg-carousel
        'data-props' => 'json', 'data-hash' => 'scalar',
        // Divi Gallery Extended
        'data-gallery_order' => 'scalar', 'data-gallery_order_by' => 'scalar', 'data-image_size' => 'scalar',
        'data-include_categories' => 'scalar', 'data-isotope_transition_duration' => 'scalar', 'data-number_of_images' => 'scalar',
        'data-offset_number' => 'scalar', 'data-show_caption' => 'scalar', 'data-show_title' => 'scalar',
        'data-enable_lightbox' => 'scalar', 'data-enable_navigation' => 'scalar', 'data-enable_overlay' => 'scalar',
        'data-lightbox_effect' => 'scalar', 'data-overlay_icon' => 'scalar', 'data-zoom_icon' => 'scalar',
        'data-link_icon' => 'scalar', 'data-link_target' => 'scalar', 'data-title_area' => 'scalar', 'data-title_level' => 'scalar',
        'data-caption_area' => 'scalar', 'data-categories' => 'scalar', 'data-mfp-src' => 'url',
        // lightbox-urile (titlurile fără „<”: le verifică politica), galeria și pozele multi-view
        'data-caption' => 'scalar', 'data-lightbox' => 'scalar', 'data-lightbox_type' => 'scalar', 'data-src' => 'url',
        'data-et-multi-view' => 'json', 'data-et-multi-view-load-phone-hidden' => 'scalar',
        'data-et-multi-view-load-tablet-hidden' => 'scalar',
        'data-name-en' => 'scalar', 'data-id' => 'scalar', 'data-type' => 'scalar', 'data-width' => 'scalar',
        // DiviMenus (popup-urile, pinii)
        'data-popup-id' => 'scalar', 'data-effect-in' => 'scalar', 'data-effect-out' => 'scalar', 'data-effect-ms' => 'scalar',
        // formularul Divi
        'data-field_type' => 'scalar', 'data-original_id' => 'scalar', 'data-required_mark' => 'scalar',
        'data-form_unique_id' => 'scalar', 'data-form_unique_num' => 'scalar',
        // Complianz (bannerul, placeholderul hărții, managerul)
        'data-cmplz' => 'scalar', 'data-category' => 'scalar', 'data-service' => 'scalar', 'data-cmplz-target' => 'scalar',
        'data-src-cmplz' => 'url', 'data-placeholder-image' => 'url', 'data-deferlazy' => 'scalar', 'data-relative_url' => 'url',
        'data-waitfor' => 'scalar',
        // text/toggle (dica), iconițe, SEO
        'data-settings' => 'json', 'data-icon' => 'scalar', 'data-prefix' => 'scalar', 'data-nosnippet' => 'scalar',
        'data-wp-strategy' => 'scalar', 'data-title' => 'scalar',
        // Mutarea unui site WooCommerce (pista A, docs/MAGAZIN-WC.md; numele atributelor din markupul WooCommerce și Divi, fără
        // valorile unui site): evenimentele calendarului (regiunea wcpc:evenimente), coloanele grilei de articole (salvattore),
        // ordinea câmpurilor din checkout, produsul și cantitatea unui link de coș, textul gol al unei liste de alegere.
        'data-events' => 'json', 'data-columns' => 'scalar', 'data-priority' => 'scalar', 'data-product_id' => 'scalar',
        'data-product_sku' => 'scalar', 'data-quantity' => 'scalar', 'data-placeholder' => 'scalar',
    ];

    /**
     * Regiunile dinamice (pista A; CadruCapturat\Regiuni): atributele pe care le poate umple o regiune în modul `atribut` (lista
     * închisă: `data-events`, evenimentele calendarului, JSON, și în DATA; `class`, clasa modulelor coșului, care primesc
     * `et_pb_wc_cart_empty` la coșul gol) și forma jetonului unei regiuni, `{{ax:regiune:<id>:<n>}}`, pus de import în locul
     * octeților capturați (în interiorul unui element sau în valoarea atributului).
     */
    public const REGIUNI_ATRIBUTE = ['data-events', 'class'];
    public const RE_JETON_REGIUNE = '/\{\{ax:regiune:[a-z]{2,12}:[a-z_]{2,40}:\d{1,3}\}\}/';

    /**
     * Clasele corpului pe care le pot adăuga sau scoate regiunile, după starea paginii (`{{ax:clase_body}}`, ContextRegiune): cele pe
     * care WooCommerce le pune pe `<body>` la contul clientului, la endpointurile lui, la coș și la checkout. Lista e închisă.
     */
    public const CLASE_BODY = ['logged-in', 'woocommerce-account', 'woocommerce-cart', 'woocommerce-checkout', 'woocommerce-order-received',
        'woocommerce-order-pay', 'woocommerce-orders', 'woocommerce-view-order', 'woocommerce-downloads', 'woocommerce-edit-account',
        'woocommerce-edit-address', 'woocommerce-payment-methods', 'woocommerce-add-payment-method', 'woocommerce-delete-payment-method',
        'woocommerce-set-default-payment-method', 'woocommerce-lost-password', 'woocommerce-customer-logout'];

    /** Cheile permise în `data-props` (dg-carousel). O cheie în plus = NU (lista se lărgește după o revizie). */
    public const PROPS = ['allowtouchmove', 'arrow', 'autoSpeed', 'autoplay', 'autoplay_viewport', 'autowidth', 'center_mode',
        'cfshadow', 'cover_rotate', 'desktop', 'dots', 'hashNavigation', 'item_spacing', 'item_spacing_phone', 'item_spacing_tablet',
        'keyboard', 'lazybefore', 'lazyload', 'loop', 'mobile', 'mousewheel', 'multislide', 'order', 'pause_onhover', 'scroller_effect',
        'scroller_speed', 'simulatetouch', 'slide_row', 'slide_row_phone', 'slide_row_tablet', 'slider_effec', 'speed', 'tablet'];

    /** Cheile permise în `data-settings` (textul dica cu „citește mai mult”). */
    public const SETTINGS = ['limit', 'limit_phone', 'limit_tablet', 'responsive', 'status', 'text_less', 'text_more'];

    /** Cheile de poluare a prototipului, refuzate oriunde într-un JSON `data-*`. */
    public const CHEI_INTERZISE = ['__proto__', 'prototype', 'constructor'];

    /**
     * Blocul nostru de configurație inert, singurul `<script type="application/json">` cu id permis (pasul 5a îi lipește
     * configurațiile furnizorilor aici, ca date, nu ca scripturi).
     */
    public const AX_DATE_ID = 'ax-date';

    /** Tipurile de `<script>` cu date inerte pe care politica le lasă (restul, orice ar fi, sunt refuzate). */
    public const SCRIPT_DATE = ['application/ld+json', 'speculationrules'];

    /**
     * Numele variabilelor configurațiilor inline pe care dialectul le mută în `ax-date` (pasul 5a): un script
     * `var <nume> = <JSON>;` (una sau mai multe). Lista e închisă; o configurație necunoscută = NU la import.
     */
    public const CONFIG = ['DIVI', 'et_builder_utils_params', 'et_frontend_scripts', 'et_link_options_data',
        'et_pb_box_shadow_elements', 'et_pb_custom', 'et_pb_sticky_elements', 'complianz', 'DiviGalleryExtendedFrontendData',
        '_wpmejsSettings', 'mejsL10n'];

    /** Literalele JSON din scripturile-șablon aruncate care trec în `ax-date` (`var file = […]`: foile „late”). */
    public const DATE_INCORPORATE = ['file'];

    /**
     * Cheia din `ax-date` cu clasele de structură observate pe pagină fără o regulă care să reiasă din DOM (runtime-ul
     * `divi4/v1/structura.js` le pune la încărcare): `{"<clasă>": ["<selector>", …]}`. O pune importul din pachetul site-ului
     * (`import-wordpress.json` → `captura.structura`), pe document; fără ea, clasele nu se pun.
     */
    public const AX_STRUCTURA = 'ax_structura';

    /** Clasele pe care `ax_structura` le poate cere (lista închisă a runtime-ului). */
    public const STRUCTURA_CLASE = ['et-last-child'];

    /** Cel mult atâția selectori pe clasă și atâția octeți într-un selector (ca runtime-ul: 200). */
    public const STRUCTURA_MAX_SELECTORI = 200;
    public const STRUCTURA_MAX_LUNGIME = 200;

    /**
     * Valoarea lui `ax_structura` (decodată: tablou asociativ sau stdClass), verificată: un obiect nevid, cheile doar din
     * STRUCTURA_CLASE, fiecare cu o listă nevidă de selectori siguri (selectorSigur). Întoarce problemele (gol = bună).
     *
     * @return list<string>
     */
    public static function structura(mixed $v): array
    {
        if ($v instanceof \stdClass) {
            $v = get_object_vars($v);
        }
        if (!is_array($v) || $v === [] || array_is_list($v)) {
            return ['„' . self::AX_STRUCTURA . '” trebuie să fie un obiect nevid {"<clasă>": ["<selector>", …]}'];
        }
        $rez = [];
        foreach ($v as $clasa => $selectori) {
            $clasa = (string) $clasa;
            if (!in_array($clasa, self::STRUCTURA_CLASE, true)) {
                $rez[] = '„' . self::AX_STRUCTURA . '”: clasa „' . mb_substr($clasa, 0, 40) . '” nu e în lista închisă (' . implode(', ', self::STRUCTURA_CLASE) . ')';
                continue;
            }
            if (!is_array($selectori) || $selectori === [] || !array_is_list($selectori) || count($selectori) > self::STRUCTURA_MAX_SELECTORI) {
                $rez[] = '„' . self::AX_STRUCTURA . '” → „' . $clasa . '”: o listă de 1–' . self::STRUCTURA_MAX_SELECTORI . ' selectori';
                continue;
            }
            foreach ($selectori as $i => $s) {
                if (!is_string($s) || !self::selectorSigur($s)) {
                    $rez[] = '„' . self::AX_STRUCTURA . '” → „' . $clasa . '”[' . $i . ']: selector nepermis (doar taguri, #id, .clasă, spații și „>”; cel mult '
                        . self::STRUCTURA_MAX_LUNGIME . ' de octeți)' . (is_string($s) ? ': „' . mb_substr($s, 0, 60) . '”' : '');
                }
            }
        }
        return $rez;
    }

    /**
     * Un selector CSS din gramatica închisă a lui `ax_structura`: selectori compuși (un nume de tag cu litere mici, urmat de
     * oricâte `#id` și `.clasă`; cel puțin una dintre ele) legați prin spații (descendent) sau prin „>” (copil direct), cu cel
     * mult 16 compuși și STRUCTURA_MAX_LUNGIME octeți. Nimic altceva: fără pseudo-clase, atribute, virgule, `*`, `+`, `~`,
     * escapări, ghilimele sau caractere în afara ASCII; fără spații la capete.
     */
    public static function selectorSigur(string $s): bool
    {
        if ($s === '' || strlen($s) > self::STRUCTURA_MAX_LUNGIME || trim($s, ' ') !== $s) {
            return false;
        }
        $ident = '-?[A-Za-z_][A-Za-z0-9_-]*';
        $compus = '(?:[a-z][a-z0-9]*(?:[#.]' . $ident . ')*|(?:[#.]' . $ident . ')+)';
        if (preg_match('/^' . $compus . '(?:(?: +| *> *)' . $compus . '){0,15}$/D', $s) !== 1) {
            return false;
        }
        return true;
    }

    /** Cheile de sus permise în `ax-date`: tot ce pune importul (configurațiile știute, literalele șabloanelor, `ax_structura`). */
    public const CHEI_AX_DATE = [...self::CONFIG, ...self::DATE_INCORPORATE, self::AX_STRUCTURA];

    /** Felul unui `data-*` (scalar/url/json/html), sau null dacă nu e permis. */
    public static function felData(string $nume): ?string
    {
        return self::DATA[strtolower($nume)] ?? null;
    }

    /** Un `<script>` cu date inerte scrise de noi (ld+json, speculationrules, blocul `ax-date`)? */
    public static function scriptInert(?string $type, ?string $id): bool
    {
        $type = $type === null ? null : strtolower(trim($type));
        if ($type === 'application/json') {
            return $id === self::AX_DATE_ID;
        }
        return $type !== null && in_array($type, self::SCRIPT_DATE, true);
    }

    /**
     * Numele variabilelor dintr-un script `var <nume> = <JSON>;` (una sau mai multe, doar spații între ele), dacă tot
     * scriptul are această formă și fiecare valoare e un literal JSON; altfel []. Dialectul (pasul 5a) mută aceste
     * configurații în `ax-date`. Nu evaluează nimic: doar recunoaște forma.
     *
     * @return list<string>
     */
    public static function scriptConfig(string $js): array
    {
        $n = strlen($js);
        $p = 0;
        $nume = [];
        while (true) {
            $p = self::saltSpatii($js, $p);
            if ($p >= $n) {
                break;
            }
            if (preg_match('/\Gvar\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*/A', $js, $m, 0, $p) !== 1) {
                return [];
            }
            $p += strlen($m[0]);
            $sf = self::sfarsitJson($js, $p);
            if ($sf === null) {
                return [];
            }
            $lit = substr($js, $p, $sf - $p);
            if (json_decode($lit) === null && trim($lit) !== 'null') {
                return [];
            }
            $nume[] = $m[1];
            $p = self::saltSpatii($js, $sf);
            if ($p >= $n || $js[$p] !== ';') {
                return [];
            }
            $p++;
        }
        return $nume;
    }

    /** Un script aruncat cunoscut (trackere, stub-urile jQuery, puntea Clarity, dfh, documentele embed auto-generate)? */
    public static function scriptAruncat(string $js, ?string $id, ?string $src): bool
    {
        if ($src !== null) {
            return true; // orice `<script src>` al furnizorilor e aruncat (runtime-ul nostru îi ia locul)
        }
        $id = (string) $id;
        foreach (['gtag', 'gtm', 'cmplz', 'jquery', 'google_gtagjs', 'googlesitekit', 'mediaelement', 'divi-custom-script',
            'et-builder-modules-script', 'wp-emoji', 'recaptcha', 'wpcaptcha'] as $semn) {
            if ($id !== '' && str_contains($id, $semn)) {
                return true;
            }
        }
        foreach (['dataLayer', 'cmplzCallClarity', 'function gtag', 'dfh_collapse_menu', 'jqueryParams', 'clarity',
            "className = 'js'", 'wp.receiveEmbedMessage', 'et-cache', '_googlesitekit', 'var file'] as $semn) {
            if (str_contains($js, $semn)) {
                return true;
            }
        }
        return false;
    }

    /** Valorile exacte ale lui `onload` pe un preload de CSS, octet cu octet (cu și fără „;” la sfârșit): → `data-ax-tarziu`. */
    public const ONLOAD_TARZIU = ["this.onload=null;this.rel='stylesheet'", "this.onload=null;this.rel='stylesheet';"];

    /** Valoarea exactă a lui `onload` pe foaia cardului embed: → `media="all"`. */
    public const ONLOAD_MEDIA = "media='all'";

    /**
     * Transformarea deterministă a unui atribut de eveniment din lista închisă (DECIZIE §6.7 #4; recenzia M3: strictă).
     * Întoarce perechea `[atributNou, valoareNouă|null]` cu care importul îl înlocuiește, sau null dacă handlerul nu e pe listă
     * (atunci politica refuză documentul, NU). Lista, fără nicio toleranță (nicio decodare, niciun spațiu scos):
     *  - pe un `<link>` cu `preload` printre `rel`, `as` = `style`, un `href` nevid al site-ului (`$tag['hrefPropriu']`) și
     *    `onload` exact una din ONLOAD_TARZIU → `data-ax-tarziu`;
     *  - pe un `<link rel="stylesheet">` (singurul `rel`), `onload` exact ONLOAD_MEDIA → `media="all"`.
     * Un tag cu mai multe atribute `on…` (`$tag['dubluOn']`) nu se transformă. Restul (alt element, `as=script|font`, fără
     * `href`, `'style sheet'`, `thi s.onload`, `onload="media=all"` fără ghilimele — pe live îi dă lui media valoarea
     * `document.all`, deci foaia nu se aplică) rămâne și e NU.
     *
     * @param array{rel?: ?string, as?: ?string, href?: ?string, hrefPropriu?: bool, dubluOn?: bool} $tag
     * @return array{0: string, 1: ?string}|null
     */
    public static function handlerRecunoscut(string $element, string $numeAtribut, string $valoare, array $tag = []): ?array
    {
        if ($numeAtribut !== 'onload' || $element !== 'link' || ($tag['dubluOn'] ?? false)) {
            return null;
        }
        $rel = preg_split('/[\t\n\f\r ]+/', strtolower((string) ($tag['rel'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (in_array($valoare, self::ONLOAD_TARZIU, true) && in_array('preload', $rel, true) && strtolower((string) ($tag['as'] ?? '')) === 'style'
            && (string) ($tag['href'] ?? '') !== '' && ($tag['hrefPropriu'] ?? false)) {
            return ['data-ax-tarziu', ''];
        }
        if ($valoare === self::ONLOAD_MEDIA && $rel === ['stylesheet']) {
            return ['media', 'all'];
        }
        return null;
    }

    /**
     * Citirea strictă a unui literal JSON (configurațiile inline și datele scripturilor-șablon, pasul 5a; recenzia m5): ca
     * json_decode, dar NU (InvalidArgumentException, cu motivul) pe o cheie dublată într-un obiect (json_decode o ține în tăcere
     * pe ultima), pe o cheie goală, pe un întreg în afara ±2^53 (JavaScript l-ar rotunji; nicio schimbare de tip în șir), pe un
     * număr care nu încape într-un double (`1e400` → INF), pe o adâncime peste 256 și pe orice text în afara literalului.
     * Obiectele ies ca stdClass (ca la citirea capturii).
     *
     * @throws \InvalidArgumentException
     */
    public static function jsonStrict(string $json): mixed
    {
        $p = 0;
        $n = strlen($json);
        self::jsonValoare($json, $p, $n, 0);
        if (self::saltSpatii($json, $p) !== $n) {
            throw new \InvalidArgumentException('text după literalul JSON');
        }
        try {
            return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('JSON nevalid: ' . $e->getMessage());
        }
    }

    private static function jsonValoare(string $s, int &$p, int $n, int $adancime): void
    {
        if ($adancime > 256) {
            throw new \InvalidArgumentException('JSON prea adânc (peste 256 de niveluri)');
        }
        $p = self::saltSpatii($s, $p);
        if ($p >= $n) {
            throw new \InvalidArgumentException('JSON incomplet');
        }
        $c = $s[$p];
        if ($c === '{' || $c === '[') {
            $obiect = $c === '{';
            $p++;
            $chei = [];
            $p = self::saltSpatii($s, $p);
            if ($p < $n && $s[$p] === ($obiect ? '}' : ']')) {
                $p++;
                return;
            }
            while (true) {
                if ($obiect) {
                    $p = self::saltSpatii($s, $p);
                    $cheie = self::jsonSir($s, $p, $n);
                    if ($cheie === '') {
                        throw new \InvalidArgumentException('cheie goală într-un obiect JSON');
                    }
                    if (isset($chei[$cheie])) {
                        throw new \InvalidArgumentException('cheie dublată „' . mb_substr($cheie, 0, 40) . '” într-un obiect JSON');
                    }
                    $chei[$cheie] = true;
                    $p = self::saltSpatii($s, $p);
                    if ($p >= $n || $s[$p] !== ':') {
                        throw new \InvalidArgumentException('lipsește „:” după o cheie JSON');
                    }
                    $p++;
                }
                self::jsonValoare($s, $p, $n, $adancime + 1);
                $p = self::saltSpatii($s, $p);
                if ($p < $n && $s[$p] === ',') {
                    $p++;
                    continue;
                }
                if ($p < $n && $s[$p] === ($obiect ? '}' : ']')) {
                    $p++;
                    return;
                }
                throw new \InvalidArgumentException('JSON nevalid la octetul ' . $p);
            }
        }
        if ($c === '"') {
            self::jsonSir($s, $p, $n);
            return;
        }
        if (preg_match('/\G-?(?:0|[1-9][0-9]*)(\.[0-9]+)?([eE][-+]?[0-9]+)?/A', $s, $m, 0, $p) === 1) {
            $p += strlen($m[0]);
            if (($m[1] ?? '') === '' && ($m[2] ?? '') === '') {
                $abs = ltrim($m[0], '-');
                if (strlen($abs) > 16 || (strlen($abs) === 16 && strcmp($abs, '9007199254740992') > 0)) {
                    throw new \InvalidArgumentException('întreg în afara ±2^53 (' . $m[0] . ')');
                }
            } elseif (!is_finite((float) $m[0])) {
                throw new \InvalidArgumentException('număr care nu încape într-un double (' . $m[0] . ')');
            }
            return;
        }
        foreach (['true', 'false', 'null'] as $lit) {
            if (substr_compare($s, $lit, $p, strlen($lit)) === 0) {
                $p += strlen($lit);
                return;
            }
        }
        throw new \InvalidArgumentException('valoare JSON nevalidă la octetul ' . $p);
    }

    /** Un șir JSON (cu escapările permise), decodat; `$p` trece de ghilimeaua de închidere. */
    private static function jsonSir(string $s, int &$p, int $n): string
    {
        if ($p >= $n || $s[$p] !== '"' || preg_match('/\G"((?:[^"\\\\\x00-\x1F]++|\\\\["\\\\\/bfnrt]|\\\\u[0-9a-fA-F]{4})*+)"/A', $s, $m, 0, $p) !== 1) {
            throw new \InvalidArgumentException('șir JSON nevalid la octetul ' . $p);
        }
        $p += strlen($m[0]);
        return (string) json_decode('"' . $m[1] . '"');
    }

    private static function saltSpatii(string $s, int $p): int
    {
        $n = strlen($s);
        while ($p < $n && ($s[$p] === ' ' || $s[$p] === "\t" || $s[$p] === "\n" || $s[$p] === "\r" || $s[$p] === "\f")) {
            $p++;
        }
        return $p;
    }

    /** Sfârșitul (exclusiv) al literalului JSON care începe la `$p`, sau null dacă nu e un literal JSON întreg acolo. */
    public static function sfarsitJson(string $s, int $p): ?int
    {
        $n = strlen($s);
        if ($p >= $n) {
            return null;
        }
        $c = $s[$p];
        if ($c !== '{' && $c !== '[') {
            if (preg_match('/\G(?:"(?:[^"\\\\]|\\\\.)*"|-?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?|true|false|null)/A', $s, $m, 0, $p) === 1) {
                return $p + strlen($m[0]);
            }
            return null;
        }
        $adanc = 0;
        $inSir = false;
        for ($i = $p; $i < $n; $i++) {
            $x = $s[$i];
            if ($inSir) {
                if ($x === '\\') {
                    $i++;
                    continue;
                }
                if ($x === '"') {
                    $inSir = false;
                }
                continue;
            }
            if ($x === '"') {
                $inSir = true;
            } elseif ($x === '{' || $x === '[') {
                $adanc++;
            } elseif ($x === '}' || $x === ']') {
                $adanc--;
                if ($adanc === 0) {
                    return $i + 1;
                }
            }
        }
        return null;
    }
}
