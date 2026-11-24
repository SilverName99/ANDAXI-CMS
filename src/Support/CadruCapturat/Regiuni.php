<?php

declare(strict_types=1);

namespace App\Support\CadruCapturat;

use App\Support\HtmlCapturat\SchemaDivi4;
use App\Support\ImportWordPress\Captura\Normalizare;
use App\Support\Paths;

/**
 * Regiunile dinamice ale documentelor capturate (pista A a mutării unui site WooCommerce; docs/MAGAZIN-WC.md, docs/DEPLOY.md,
 * „Regiunile dinamice”): un nod marcat al documentului capturat al cărui interior (sau valoarea unui atribut) îl randează codul
 * nostru, în markupul site-ului, ca foaia capturată să-l stilizeze. Un singur mecanism pentru coș, checkout, cont, listele de
 * articole, calendar.
 *
 *  - **Regulile** stau în pachet (`import-wordpress.json` → `regiuni`, valideazaReguli): documentul, selectorul (gramatica închisă
 *    SchemaDivi4::selectorSigur), randatorul (lista închisă CATALOG), modul (`interior` sau `atribut`, cu atributul din
 *    SchemaDivi4::REGIUNI_ATRIBUTE). Tot acolo, locurile jetoanelor `{{ax:clase_body}}` (clasele corpului) și `{{ax:titlu}}`.
 *  - **Importul** (Divi4Taiere) pune în locul regiunii jetonul `{{ax:regiune:<id>:<n>}}`, printr-o editare Html5\Editare (contextul
 *    arborelui verificat), iar octeții capturați rămân în `regiuni_capturate` (nucleu/0072), cu starea pe care o arată captura.
 *  - **Dovada** (dovada(), la import și în G-regiuni): randatorul, cu starea capturii, dă exact octeții capturați după normalizarea
 *    declarată (Captura\Normalizare plus Randator::normalizeaza); altfel NU, cu primul octet diferit. Un randator încă neconstruit
 *    (pista lui nu e gata) nu are dovadă: importul unui document cu regiunea lui e NU.
 *  - **Randarea** (CadruCapturat): la o cerere, jetonul primește ieșirea randatorului (randeaza()), pusă în pagină fără să mai treacă
 *    prin jetoane; la P1, la previzualizare și la verificarea salvării, octeții capturați (nimic nu se randează din date).
 *  - **Cache-ul** (CATALOG → `cache`): `pagina` (documentul poate sta în cache; randatorul nu citește sesiunea) sau `sesiune`
 *    (documentul cu o asemenea regiune pleacă `Cache-Control: no-store, private`, deci nu intră în cache-ul de pagini).
 *
 * Randatorii de probă ai uneltelor (`proba:*`, inregistreazaProba) există doar cu constanta ANDAXI_DEV_REGIUNI_PROBA, pe care o
 * definesc doar uneltele de dezvoltare (tools/dev/lib/router-regiuni.php, tools/dev/test-regiuni.php); pe un server ea nu există.
 */
final class Regiuni
{
    /**
     * Randatorii știuți (lista închisă): id → `cache` (`pagina` | `sesiune`), `moduri` (`interior`, `atribut`), `atribute` (pentru
     * modul `atribut`), pista care îl construiește și clasa lui (`clasa`, o implementare Randator, în spațiul de nume al pistei,
     * docs/MAGAZIN-WC.md). Un id care nu e aici e refuzat la validare și la import. Un randator e „construit” când clasa lui există
     * (și implementează Randator): pista lui adaugă doar clasa (și dovada ei în poarta ei), fără să schimbe lista; până atunci un
     * document cu regiunea lui nu se poate importa (dovada e NU). O clasă poate servi mai multe id-uri (ContextRegiune::regiune) și
     * ambele moduri ale unui id (ContextRegiune::mod).
     *
     * @var array<string, array{cache: string, moduri: list<string>, atribute?: list<string>, pista: string, clasa: class-string}>
     */
    public const CATALOG = [
        // Produsul și magazinul (pista G): formularul de adăugare în coș (cu data, pista K), JSON-LD-ul produsului, lista magazinului.
        'wc:adauga_in_cos' => ['cache' => 'pagina', 'moduri' => ['interior'], 'pista' => 'G', 'clasa' => \App\Support\MagazinWc\RandareProdus::class],
        'wc:jsonld_produs' => ['cache' => 'pagina', 'moduri' => ['interior'], 'pista' => 'G', 'clasa' => \App\Support\MagazinWc\RandareProdus::class],
        'wc:magazin_lista' => ['cache' => 'pagina', 'moduri' => ['interior'], 'pista' => 'G', 'clasa' => \App\Support\MagazinWc\RandareProdus::class],
        // Coșul (pista G). Modulele produselor și totalurilor își schimbă și clasa când coșul e gol (`et_pb_wc_cart_empty`): regiunea
        // lor are și modul `atribut`, pe `class`, lângă interior (specificația markupului WooCommerce, starea coșului gol).
        'wc:cos_notificari' => ['cache' => 'sesiune', 'moduri' => ['interior'], 'pista' => 'G', 'clasa' => \App\Support\MagazinWc\RandareCos::class],
        'wc:cos_produse' => ['cache' => 'sesiune', 'moduri' => ['interior', 'atribut'], 'atribute' => ['class'], 'pista' => 'G',
            'clasa' => \App\Support\MagazinWc\RandareCos::class],
        'wc:cos_totaluri' => ['cache' => 'sesiune', 'moduri' => ['interior', 'atribut'], 'atribute' => ['class'], 'pista' => 'G',
            'clasa' => \App\Support\MagazinWc\RandareCos::class],
        'wc:cos_gol' => ['cache' => 'sesiune', 'moduri' => ['interior'], 'pista' => 'G', 'clasa' => \App\Support\MagazinWc\RandareCos::class],
        // Checkout-ul, comanda primită, plata unei comenzi, contul (pista L).
        'wc:checkout_notificari' => ['cache' => 'sesiune', 'moduri' => ['interior'], 'pista' => 'L', 'clasa' => \App\Support\MagazinWc\RandareCheckout::class],
        'wc:checkout_facturare' => ['cache' => 'sesiune', 'moduri' => ['interior'], 'pista' => 'L', 'clasa' => \App\Support\MagazinWc\RandareCheckout::class],
        'wc:checkout_livrare' => ['cache' => 'sesiune', 'moduri' => ['interior'], 'pista' => 'L', 'clasa' => \App\Support\MagazinWc\RandareCheckout::class],
        'wc:checkout_info' => ['cache' => 'sesiune', 'moduri' => ['interior'], 'pista' => 'L', 'clasa' => \App\Support\MagazinWc\RandareCheckout::class],
        'wc:checkout_detalii' => ['cache' => 'sesiune', 'moduri' => ['interior'], 'pista' => 'L', 'clasa' => \App\Support\MagazinWc\RandareCheckout::class],
        'wc:checkout_plata' => ['cache' => 'sesiune', 'moduri' => ['interior'], 'pista' => 'L', 'clasa' => \App\Support\MagazinWc\RandareCheckout::class],
        'wc:comanda_primita' => ['cache' => 'sesiune', 'moduri' => ['interior'], 'pista' => 'L',
            'clasa' => \App\Support\MagazinWc\RandareComandaPrimita::class],
        'wc:comanda_plata' => ['cache' => 'sesiune', 'moduri' => ['interior'], 'pista' => 'L', 'clasa' => \App\Support\MagazinWc\RandareCheckout::class],
        'wc:cont' => ['cache' => 'sesiune', 'moduri' => ['interior'], 'pista' => 'L', 'clasa' => \App\Support\MagazinWc\RandareCont::class],
        // Calendarul evenimentelor (pista K): evenimentele în `data-events`, categoriile filtrului.
        'wcpc:evenimente' => ['cache' => 'pagina', 'moduri' => ['atribut'], 'atribute' => ['data-events'], 'pista' => 'K',
            'clasa' => \App\Support\DateEvenimente\RandareCalendar::class],
        'wcpc:categorii' => ['cache' => 'pagina', 'moduri' => ['interior'], 'pista' => 'K', 'clasa' => \App\Support\DateEvenimente\RandareCalendar::class],
        // Pagina abonamentului MailPoet (pista E): gestionarea abonării, confirmarea, dezabonarea (după tokenul din adresă).
        'mailpoet:pagina' => ['cache' => 'sesiune', 'moduri' => ['interior'], 'pista' => 'E', 'clasa' => \App\Support\MailPoetCompat\RandarePagina::class],
        // Articolele, listele de articole și căutarea (pista B).
        'articol:titlu' => ['cache' => 'pagina', 'moduri' => ['interior'], 'pista' => 'B', 'clasa' => \App\Support\BlogCapturat\RandareArticol::class],
        'articol:meta' => ['cache' => 'pagina', 'moduri' => ['interior'], 'pista' => 'B', 'clasa' => \App\Support\BlogCapturat\RandareArticol::class],
        'articol:continut' => ['cache' => 'pagina', 'moduri' => ['interior'], 'pista' => 'B', 'clasa' => \App\Support\BlogCapturat\RandareArticol::class],
        'blog:lista' => ['cache' => 'pagina', 'moduri' => ['interior'], 'pista' => 'B', 'clasa' => \App\Support\BlogCapturat\RandareLista::class],
        // Rezultatele căutării: pe cerere, niciodată în cache (ca CautareCapturata).
        'cautare:rezultate' => ['cache' => 'sesiune', 'moduri' => ['interior'], 'pista' => 'B', 'clasa' => \App\Support\BlogCapturat\RandareCautare::class],
    ];

    /** Jetoanele documentului pe care le pot pune regulile (`{{ax:clase_body}}`, `{{ax:titlu}}`). */
    public const JETOANE = ['clase_body', 'titlu'];

    /** Modurile unei regiuni. */
    public const MODURI = ['interior', 'atribut'];

    /** Documentele-șablon (paginile capturate cu `tip` = `sablon_*`, pista B). */
    public const SABLOANE = ['sablon:articol', 'sablon:categorie', 'sablon:cautare'];

    /** Cheile unei reguli. */
    public const CHEI_REGULA = ['document', 'selector', 'regiune', 'mod', 'atribut', 'jeton'];

    /** Cel mult atâtea reguli într-un pachet. */
    public const MAX_REGULI = 200;

    /** Forma id-ului unui randator (`<familie>:<nume>`) și a jetonului unei regiuni (`regiune:<id>:<n>`). */
    public const RE_ID = '/^[a-z]{2,12}:[a-z_]{2,40}$/D';
    public const RE_JETON = '/^regiune:([a-z]{2,12}:[a-z_]{2,40}):(\d{1,3})$/D';

    /** Randatorii de probă ai uneltelor de dezvoltare. @var array<string, array{cache: string, moduri: list<string>, atribute: list<string>, randator: Randator}> */
    private static array $proba = [];

    private static bool $probaIncarcata = false;

    // ───────────────────────────────────────────────────────────
    // Catalogul
    // ───────────────────────────────────────────────────────────

    /** Declarația unui randator (CATALOG sau, în unelte, o probă), sau null. @return array{cache: string, moduri: list<string>, atribute: list<string>, pista: string}|null */
    public static function declaratie(string $id): ?array
    {
        if (isset(self::CATALOG[$id])) {
            $d = self::CATALOG[$id];
            return ['cache' => $d['cache'], 'moduri' => $d['moduri'], 'atribute' => $d['atribute'] ?? [], 'pista' => $d['pista']];
        }
        self::incarcaProba();
        if (isset(self::$proba[$id])) {
            return ['cache' => self::$proba[$id]['cache'], 'moduri' => self::$proba[$id]['moduri'], 'atribute' => self::$proba[$id]['atribute'], 'pista' => 'proba'];
        }
        return null;
    }

    /**
     * Randatorul construit al unui id, sau null (necunoscut ori încă neconstruit: clasa lui din CATALOG nu există încă sau nu
     * implementează Randator).
     */
    public static function randator(string $id): ?Randator
    {
        if (isset(self::CATALOG[$id])) {
            $clasa = self::CATALOG[$id]['clasa'];
            return class_exists($clasa) && is_subclass_of($clasa, Randator::class) ? new $clasa() : null;
        }
        self::incarcaProba();
        return isset(self::$proba[$id]) ? self::$proba[$id]['randator'] : null;
    }

    /** `pagina` sau `sesiune` (un id necunoscut: `sesiune`, ca la îndoială documentul să nu intre în cache). */
    public static function cache(string $id): string
    {
        return self::declaratie($id)['cache'] ?? 'sesiune';
    }

    /**
     * Un randator de probă, pentru uneltele de dezvoltare (G-regiuni): doar cu constanta ANDAXI_DEV_REGIUNI_PROBA (definită doar de
     * tools/dev), doar în linia de comandă sau în serverul `php -S`, doar cu un id `proba:*`.
     *
     * @param list<string> $moduri
     * @param list<string> $atribute
     */
    public static function inregistreazaProba(string $id, string $cache, array $moduri, array $atribute, Randator $r): void
    {
        if (!defined('ANDAXI_DEV_REGIUNI_PROBA') || !in_array(PHP_SAPI, ['cli', 'cli-server'], true) || !str_starts_with($id, 'proba:')
            || preg_match(self::RE_ID, $id) !== 1 || !in_array($cache, ['pagina', 'sesiune'], true)) {
            throw new \LogicException('un randator de probă se înregistrează doar din uneltele de dezvoltare, cu un id proba:*');
        }
        self::$proba[$id] = ['cache' => $cache, 'moduri' => $moduri, 'atribute' => $atribute, 'randator' => $r];
    }

    /** Fișierul randatorilor de probă (constanta uneltelor), încărcat o dată, doar de sub tools/dev/. */
    private static function incarcaProba(): void
    {
        if (self::$probaIncarcata || !defined('ANDAXI_DEV_REGIUNI_PROBA')) {
            return;
        }
        self::$probaIncarcata = true;
        $fisier = realpath((string) constant('ANDAXI_DEV_REGIUNI_PROBA'));
        $unelte = realpath(Paths::radacina() . '/tools/dev');
        if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && $fisier !== false && $unelte !== false && str_starts_with($fisier, $unelte . '/')) {
            require_once $fisier;
        }
    }

    // ───────────────────────────────────────────────────────────
    // Regulile pachetului
    // ───────────────────────────────────────────────────────────

    /**
     * Problemele regulilor `regiuni` (gol = bune): o listă de cel mult MAX_REGULI obiecte, fiecare cu doar CHEI_REGULA:
     *  - `{"document", "selector", "regiune", "mod"?: "interior" | "atribut", "atribut"?}`: o regiune; randatorul din CATALOG, modul
     *    printre modurile lui, atributul (doar la `atribut`) printre atributele lui și în SchemaDivi4::REGIUNI_ATRIBUTE;
     *  - `{"document", "jeton": "clase_body"}`: clasele corpului; `{"document", "selector", "jeton": "titlu"}`: titlul (interiorul unui
     *    element doar cu text).
     * `document`: calea unui document capturat („/…/”), „404”, „produs:*” (toate paginile de produs capturate) sau un șablon
     * (SABLOANE). `selector`: gramatica închisă SchemaDivi4::selectorSigur (taguri, #id, .clasă, spații și „>”). Aceeași regulă de
     * două ori e o greșeală.
     *
     * @return list<string>
     */
    public static function valideazaReguli(mixed $reguli): array
    {
        if (!is_array($reguli) || ($reguli !== [] && !array_is_list($reguli))) {
            return ['regiuni: o listă de reguli {"document", "selector", "regiune", "mod"} sau {"document", "jeton"}'];
        }
        if (count($reguli) > self::MAX_REGULI) {
            return ['regiuni: cel mult ' . self::MAX_REGULI . ' reguli'];
        }
        $p = [];
        $vazute = [];
        foreach ($reguli as $i => $r) {
            $et = 'regiuni[' . $i . ']';
            if (!is_array($r) || ($r !== [] && array_is_list($r))) {
                $p[] = $et . ': un obiect';
                continue;
            }
            foreach (array_keys($r) as $k) {
                if (!in_array((string) $k, self::CHEI_REGULA, true)) {
                    $p[] = $et . ': cheie necunoscută „' . mb_substr((string) $k, 0, 40) . '” (sunt: ' . implode(', ', self::CHEI_REGULA) . ')';
                }
            }
            $doc = $r['document'] ?? null;
            if (!is_string($doc) || !self::documentValid($doc)) {
                $p[] = $et . ': document = calea unui document („/…/”), „404”, „produs:*” sau ' . implode(', ', self::SABLOANE);
            }
            $selector = $r['selector'] ?? null;
            if (array_key_exists('selector', $r) && (!is_string($selector) || !SchemaDivi4::selectorSigur($selector))) {
                $p[] = $et . ': selector nepermis (doar taguri, #id, .clasă, spații și „>”; cel mult ' . SchemaDivi4::STRUCTURA_MAX_LUNGIME . ' de octeți)'
                    . (is_string($selector) ? ': „' . mb_substr($selector, 0, 60) . '”' : '');
            }
            if (array_key_exists('jeton', $r)) {
                $jeton = $r['jeton'];
                if (!in_array($jeton, self::JETOANE, true)) {
                    $p[] = $et . ': jeton = ' . implode(' sau ', self::JETOANE);
                } elseif (array_key_exists('regiune', $r) || array_key_exists('mod', $r) || array_key_exists('atribut', $r)) {
                    $p[] = $et . ': o regulă a unui jeton nu are regiune, mod sau atribut';
                } elseif ($jeton === 'clase_body' && array_key_exists('selector', $r)) {
                    $p[] = $et . ': jetonul clase_body e pe <body>, fără selector';
                } elseif ($jeton === 'titlu' && !array_key_exists('selector', $r)) {
                    $p[] = $et . ': jetonul titlu cere selectorul elementului';
                }
                $cheie = (string) json_encode([$doc, $jeton, $jeton === 'titlu' ? $selector : null]);
            } else {
                $id = $r['regiune'] ?? null;
                $decl = is_string($id) ? self::declaratie($id) : null;
                $mod = $r['mod'] ?? 'interior';
                if (!is_string($id) || preg_match(self::RE_ID, $id) !== 1 || $decl === null) {
                    $p[] = $et . ': randatorul „' . (is_string($id) ? mb_substr($id, 0, 60) : gettype($id)) . '” nu e în lista închisă (Regiuni::CATALOG)';
                }
                if (!array_key_exists('selector', $r)) {
                    $p[] = $et . ': o regiune cere selectorul elementului ei';
                }
                if (!in_array($mod, self::MODURI, true)) {
                    $p[] = $et . ': mod = interior sau atribut';
                } elseif ($decl !== null && !in_array($mod, $decl['moduri'], true)) {
                    $p[] = $et . ': randatorul „' . $id . '” nu are modul „' . $mod . '” (are: ' . implode(', ', $decl['moduri']) . ')';
                }
                $atribut = $r['atribut'] ?? null;
                if ($mod === 'atribut') {
                    if (!is_string($atribut) || !in_array($atribut, SchemaDivi4::REGIUNI_ATRIBUTE, true)
                        || ($decl !== null && !in_array($atribut, $decl['atribute'], true))) {
                        $p[] = $et . ': atributul unei regiuni e unul din ' . implode(', ', SchemaDivi4::REGIUNI_ATRIBUTE) . ', al randatorului ei';
                    }
                } elseif (array_key_exists('atribut', $r)) {
                    $p[] = $et . ': atribut doar cu "mod": "atribut"';
                }
                // Același element poate avea o regiune pe interior și una pe un atribut (coșul: interiorul și clasa modulului).
                $cheie = (string) json_encode([$doc, $selector, $id, $mod, $mod === 'atribut' ? $atribut : null]);
            }
            if (isset($vazute[$cheie])) {
                $p[] = $et . ': aceeași regulă de două ori (regiuni[' . $vazute[$cheie] . '])';
            }
            $vazute[$cheie] ??= $i;
        }
        return $p;
    }

    /** O valoare `document` din gramatica regulilor. */
    public static function documentValid(string $doc): bool
    {
        if ($doc === '404' || $doc === 'produs:*' || in_array($doc, self::SABLOANE, true)) {
            return true;
        }
        return str_starts_with($doc, '/') && !str_starts_with($doc, '//') && strlen($doc) <= 300 && preg_match('/[\s\\\\\x00-\x1F]/', $doc) !== 1
            && !str_contains('/' . rawurldecode($doc) . '/', '/../');
    }

    /**
     * Regulile care se aplică unui document: cele cu documentul lui (calea, comparată decodată), „404”, „produs:*” (tip `produs`) sau
     * șablonul lui (tip `sablon_articol`, `sablon_categorie`, `sablon_cautare`).
     *
     * @param list<array<string, mixed>> $reguli validate
     * @return list<array<string, mixed>>
     */
    public static function pentruDocument(array $reguli, string $tip, ?string $cale): array
    {
        $rez = [];
        foreach ($reguli as $r) {
            $doc = (string) ($r['document'] ?? '');
            $da = match (true) {
                $doc === '404' => $tip === '404',
                $doc === 'produs:*' => $tip === 'produs',
                str_starts_with($doc, 'sablon:') => $tip === 'sablon_' . substr($doc, strlen('sablon:')),
                default => $cale !== null && in_array($tip, ['pagina', 'arhiva', 'produs'], true) && rawurldecode($doc) === rawurldecode($cale),
            };
            if ($da) {
                $rez[] = $r;
            }
        }
        return $rez;
    }

    // ───────────────────────────────────────────────────────────
    // Randarea și dovada
    // ───────────────────────────────────────────────────────────

    /**
     * Ieșirea randatorului unei regiuni, la o cerere. Un randator necunoscut sau încă neconstruit e o EroareJeton (cadrul răspunde 500,
     * nicio pagină pe jumătate).
     *
     * Un randator `pagina` nu vede sesiunea (contractul Randator, aplicat aici, nu doar cerut; SEC-A3): cât randează, `$_SESSION` e un
     * tablou gol, desprins de sesiune (modulul sesiunii își păstrează datele, deci nici o închidere a ei nu le pierde), apoi se leagă la
     * loc întocmai (și lipsa lui). Ce ar citi direct din `$_SESSION` e deci nimic, iar o scriere în el, ca și o sesiune pornită sau
     * închisă de el, e o EroareJeton (500): documentul lui poate sta în cache-ul de pagini, unde nimic de-al unui vizitator nu are voie
     * să ajungă.
     *
     * @throws EroareJeton
     */
    public static function randeaza(string $id, ContextRegiune $ctx): string
    {
        $r = self::randator($id);
        if ($r === null) {
            throw new EroareJeton('regiunea „' . $id . '” n-are un randator construit');
        }
        if (self::cache($id) !== 'pagina') {
            return $r->randeaza($ctx);
        }
        $stareInainte = session_status();
        $existaSesiunea = array_key_exists('_SESSION', $GLOBALS);
        if ($existaSesiunea) {
            $legatura = &$_SESSION; // aceeași referință pe care o ține modulul sesiunii, când sesiunea e pornită
        }
        unset($_SESSION);
        $_SESSION = [];
        try {
            $iesire = $r->randeaza($ctx);
            $scris = $_SESSION !== [];
            $schimbata = session_status() !== $stareInainte;
        } finally {
            unset($_SESSION);
            if ($existaSesiunea) {
                $_SESSION = &$legatura;
            }
        }
        if ($scris || $schimbata) {
            throw new EroareJeton('regiunea „' . $id . '” are cache „pagina”, dar ' . ($schimbata ? 'a pornit sau a închis sesiunea' : 'a scris în sesiune'));
        }
        return $iesire;
    }

    /**
     * Dovada unei regiuni (la import și în teste): randatorul, cu starea capturii, dă octeții capturați după normalizarea declarată
     * (Captura\Normalizare, apoi Randator::normalizeaza, pe ambele părți). null = dovedită; altfel motivul (NU), cu primul octet diferit.
     */
    public static function dovada(string $id, string $capturat, ContextRegiune $ctx, Normalizare $norm): ?string
    {
        $r = self::randator($id);
        if ($r === null) {
            return self::declaratie($id) === null ? 'randatorul „' . $id . '” nu e în lista închisă'
                : 'randatorul „' . $id . '” nu e încă construit (pista ' . (self::declaratie($id)['pista'] ?? '?') . '): regiunea nu se poate dovedi';
        }
        try {
            $iesire = $r->randeaza($ctx);
        } catch (\Throwable $e) {
            return 'randatorul „' . $id . '” a aruncat ' . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200);
        }
        $a = $r->normalizeaza($norm->document($capturat));
        $b = $r->normalizeaza($norm->document($iesire));
        $dif = Normalizare::primaDiferenta($a, $b);
        if ($dif === null) {
            return null;
        }
        return 'ieșirea randatorului „' . $id . '” diferă de captură, prima diferență la octetul ' . $dif['octet'] . ' (captura: „' . $dif['a']
            . '”; randat: „' . $dif['b'] . '”)';
    }

    /**
     * O ieșire pentru modul `atribut` se poate pune între ghilimelele atributului? (fără ghilimele, fără `<` și `>`, fiecare `&` o
     * referință de caracter).
     */
    public static function valoareAtributSigura(string $v): bool
    {
        return preg_match('/["\'<>]/', $v) !== 1 && preg_match('/&(?![A-Za-z][A-Za-z0-9]{1,31};|#[0-9]{1,7};|#[xX][0-9A-Fa-f]{1,6};)/', $v) !== 1;
    }

    // ───────────────────────────────────────────────────────────
    // Scăparea datelor (pentru randatori)
    // ───────────────────────────────────────────────────────────
    //
    // Ieșirea unui randator nu mai trece prin jetoane (CadruCapturat::randeaza o pune între bucățile deja rezolvate), deci un `{`
    // din date nu trebuie scăpat: rămâne text, iar octeții pot fi exact ai site-ului capturat. Randatorul care trebuie să dea exact
    // octeții WordPress își alege forma (de exemplu esc_attr nu codează din nou entitățile).

    /** Text în HTML (ca esc_html, cu `'` ca `&#039;`; entitățile din text se codează din nou). */
    public static function html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
    }

    /** Valoarea unui atribut între ghilimele (ca esc_attr). */
    public static function attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
    }

    /** Un JSON de pus într-un atribut între ghilimele (ca esc_attr(wp_json_encode(…)): fără flaguri, ca wp_json_encode). */
    public static function jsonAttr(mixed $valoare, int $flaguri = 0): string
    {
        return self::attr((string) json_encode($valoare, $flaguri | JSON_THROW_ON_ERROR));
    }
}
