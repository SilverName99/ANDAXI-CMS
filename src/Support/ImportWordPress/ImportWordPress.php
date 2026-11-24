<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress;

use App\Support\DateSite;
use App\Support\HtmlCapturat\SchemaDivi4;
use App\Support\ImportWordPress\Continut\Convertor;

/**
 * Importul unui site WordPress pe codul comun (docs/DEPLOY.md, „Adoptarea unui site WordPress”): legătura dintre
 * linia de comandă (`scripts/importa-wordpress.php`, rulat de `deploy/adopta-wp.sh` prin bin/platforma) și piese:
 *
 *   ConfigWordPress  wp-config.php citit fără să fie rulat (baza, prefixul, dosarul pozelor)
 *   SursaWordPress   baza WordPress, doar citită (tranzacție READ ONLY)
 *   Inventar         ce are site-ul (pagini, poze, meniuri, SEO, pluginuri, restul)
 *   Plan             ce s-ar face (pagini, redirecționări, setări, fișiere; NU / ATENȚIE)
 *   Importator       aplicarea pe baza codului comun și copierea pozelor
 *
 * Regulile site-ului stau în pachetul lui, `import-wordpress.json` (formatul: REGULI, verificat de
 * tools/dev/verifica-pachet.php).
 */
final class ImportWordPress
{
    /**
     * Cheile din import-wordpress.json:
     *  - `pagini`: {"<slug WordPress>": {"importa": false, "motiv": "…", "spre": "/cale" | "cod": 410} | {"slug": "alt-slug"} | {"publicata": false}};
     *  - `articole`: {"spre": "/cale"}: unde duc adresele articolelor (fără blog pe site); fără ea, 410;
     *  - `redirecturi`: [["/vechi", "/nou" | null, 301 | 410, "motiv", prefix?]] în plus;
     *  - `setari`: {"cheie": "valoare"}: setări fixe ale site-ului nou (fără date personale: pachetul e public);
     *  - `continut`: „capturat” (drumul capturat: paginile din captura site-ului live, pasul 4) sau numele convertorului
     *    paginilor făcute cu un constructor (drumul necapturat, de exemplu „divi”);
     *  - `dialect`: pe drumul capturat, dialectul capturii (DIALECTE: „divi4”), care dă convertorul paginilor (pasul 5);
     *  - `scripturi_aruncate`: pe drumul capturat, scripturile (și `<noscript>`-urile) pe care dialectul le scoate, fiecare
     *    `{"sha256": "<64 hexa>", "motiv": "…"}` (sha256-ul elementului după normalizarea adreselor proprii și a nonce-urilor;
     *    pasul 5a). Un script care nu e aici, nici date inerte, nici o configurație știută, înseamnă NU;
     *  - `captura`: `{"nonce": ["<nume>", …]}`, nonce-uri în plus față de cele știute (Captura\Normalizare::NONCE), găsite de
     *    comparația pe UA (pasul 4a); `{"structura": {"<cale>" | "404": {"et-last-child": ["<selector>", …]}}}`, clasele de
     *    structură observate pe un document fără o regulă în DOM, puse de dialect în `ax-date` → `ax_structura` (runtime-ul
     *    `structura.js` le aplică; SchemaDivi4::structura: clasele din lista închisă, selectorii doar taguri, #id, .clasă,
     *    spații și „>”).
     *  - `stiluri_comune`: pe drumul capturat, `{"_tb_footer": "/"}`: regulile layout-ului Theme Builder (subsolul, antetul) se iau
     *    din foile paginii numite, pe toate paginile unde au rămas vechi (StiluriComune, la publicarea fișierelor statice);
     *  - `stiluri_dupa_model`: pe drumul capturat, `{"/cale/": [{"et_pb_blurb_14": "et_pb_blurb_10", …}, …]}`: modulele unei secțiuni
     *    rămase cu regulile vechi în foaia `et-cache` a paginii primesc regulile modulelor-model (StiluriComune::dupaModelSite, la
     *    publicarea fișierelor statice);
     *  - `legaturi`: pe drumul capturat, `[{"modul": "et_pb_text_8_tb_footer", "text": "…", "href": "https://…"}]`: textul exact din
     *    modulul numit devine o legătură `<a href>` (Divi4, la import; legătura Divi a modulului, prin script, se scoate);
     *  - `regiuni`: pe drumul capturat (pista A a mutării unui site WooCommerce), regiunile dinamice ale documentelor și locurile
     *    jetoanelor `{{ax:clase_body}}` și `{{ax:titlu}}` (CadruCapturat\Regiuni::valideazaReguli: documentul, selectorul din gramatica
     *    închisă, randatorul din lista închisă, modul). Reguli și în privat/ (documentele al căror nume e o dată a site-ului), adăugate
     *    după cele din pachet;
     *  - doar în privat/ (`privat/import-wordpress.json`): `sabloane` (`{"articol": "/<cale>/", "categorie": "/<cale>/",
     *    "cautare": "/?s=<termen>"}`: adresele capturate din care se fac documentele-șablon, pista B).
     * Pachetul e public: niciun literal de email, nicio adresă de destinatar.
     */
    public const REGULI = ['_despre', 'pagini', 'articole', 'redirecturi', 'setari', 'continut', 'dialect', 'scripturi_aruncate', 'captura', 'stiluri_comune',
        'stiluri_dupa_model', 'legaturi', 'regiuni', 'sabloane'];

    /** Felurile documentelor-șablon din `sabloane` (pista B; tipurile `sablon_*` din Divi4Taiere::TIPURI). */
    public const SABLOANE = ['articol', 'categorie', 'cautare'];

    /** Valoarea lui `continut` pentru drumul capturat. */
    public const CONTINUT_CAPTURAT = 'capturat';

    /** Dialectele capturii știute (Flatsome vine la pasul 17). */
    public const DIALECTE = ['divi4'];

    /** @return array<string, mixed> regulile pachetului site-ului ([] fără fișier) */
    public static function reguli(): array
    {
        $r = DateSite::json('import-wordpress.json');
        // Recenzia 2, R3-7: ce e derivat din site-ul real stă doar în privat/; în fișierul public e NU (o cheie pe care
        // valideazaReguli o refuză, cu motivul în ea).
        foreach (self::doarPrivate($r) as $cheie) {
            $r['public: ' . $cheie . ' stă doar în privat/'] = true;
        }
        if (array_key_exists('captura', $r) && !is_array($r['captura'])) {
            return $r; // „captura” care nu e obiect: valideazaReguli o refuză (NU), fără îmbinarea privatelor (R3-8)
        }
        // Datele private ale pachetului (git-ignorate, `privat/`): clasele de structură observate pe site-ul live
        // (`captura.structura`, din observațiile pistei H) sunt date ale clientului, deci nu stau în import-wordpress.json.
        $privat = DateSite::dosar() . '/' . self::STRUCTURA_PRIVATA;
        if (is_file($privat)) {
            // Citit strict (cheie dublată, cheie goală, numere în afara ±2^53 = NU), ca datele din ax-date (recenzia M2).
            $brut = filesize($privat) > self::PRIVAT_MAX ? '' : (string) file_get_contents($privat);
            try {
                $strict = SchemaDivi4::jsonStrict($brut);
                $st = $strict instanceof \stdClass ? json_decode($brut, true) : null;
            } catch (\InvalidArgumentException) {
                $st = null;
            }
            if (!is_array($st) || ($st !== [] && array_is_list($st))) {
                // O cheie care nu e o cale: valideazaReguli o refuză, cu motivul în ea.
                $r['captura']['structura']['privat: nu e un obiect JSON strict'] = [];
            } else {
                foreach ($st as $cale => $v) {
                    if ($cale === '_despre') {
                        continue;
                    }
                    if (array_key_exists('captura', $r) && !is_array($r['captura'])) {
                        break; // valideazaReguli refuză „captura” care nu e obiect (fără TypeError aici)
                    }
                    if (isset($r['captura']['structura'][$cale])) {
                        // Aceeași cale și în pachet: o greșeală, nu o îmbinare în tăcere (valideazaReguli o refuză).
                        $r['captura']['structura']['privat:' . $cale] = $v;
                        continue;
                    }
                    $r['captura']['structura'][$cale] = $v;
                }
            }
        }
        // Regulile private ale pachetului (`privat/import-wordpress.json`, git-ignorat): ce e derivat din conținutul clientului
        // (sha256-urile scripturilor scoase, cu motivul; nonce-urile în plus). Doar aceste chei, adăugate la cele din pachet.
        $regPriv = DateSite::dosar() . '/' . self::REGULI_PRIVATE;
        if (is_file($regPriv)) {
            $brut = filesize($regPriv) > self::PRIVAT_MAX ? '' : (string) file_get_contents($regPriv);
            try {
                $strict = SchemaDivi4::jsonStrict($brut);
                $rp = $strict instanceof \stdClass ? json_decode($brut, true) : null;
            } catch (\InvalidArgumentException) {
                $rp = null;
            }
            if (!is_array($rp) || ($rp !== [] && array_is_list($rp))) {
                $r['privat: import-wordpress.json nu e un obiect JSON strict'] = true;
            } else {
                foreach ($rp as $k => $v) {
                    if ($k === '_despre') {
                        continue;
                    }
                    if ($k === 'scripturi_aruncate' && is_array($v) && array_is_list($v)) {
                        $r['scripturi_aruncate'] = array_merge(array_values((array) ($r['scripturi_aruncate'] ?? [])), $v);
                    } elseif ($k === 'regiuni' && is_array($v) && array_is_list($v) && (!array_key_exists('regiuni', $r) || (is_array($r['regiuni'])
                        && array_is_list($r['regiuni'])))) {
                        // Regulile regiunilor din privat (documentele al căror nume e o dată a site-ului), după cele din pachet.
                        $r['regiuni'] = array_merge(array_values((array) ($r['regiuni'] ?? [])), $v);
                    } elseif ($k === 'sabloane' && !array_key_exists('sabloane', $r)) {
                        $r['sabloane'] = $v;
                    } elseif ($k === 'captura' && is_array($v) && array_keys($v) === ['nonce'] && is_array($v['nonce']) && array_is_list($v['nonce'])
                        && (!array_key_exists('captura', $r) || is_array($r['captura']))) {
                        $r['captura']['nonce'] = array_values(array_merge((array) ($r['captura']['nonce'] ?? []), $v['nonce']));
                    } else {
                        $r['privat: cheie nepermisă ' . mb_substr((string) $k, 0, 40)] = true;
                    }
                }
            }
        }
        return $r;
    }

    /**
     * Cheile unui import-wordpress.json PUBLIC care au voie doar în privat/ (recenzia 2, R3-7): `scripturi_aruncate` (sha256-urile
     * scripturilor site-ului real), `captura.structura` (selectorii observați), `captura.nonce`, `sabloane` (pista A: adresele
     * documentelor-șablon).
     *
     * @param array<mixed> $public
     * @return list<string>
     */
    public static function doarPrivate(array $public): array
    {
        $rez = [];
        if (array_key_exists('scripturi_aruncate', $public)) {
            $rez[] = 'scripturi_aruncate';
        }
        // Pista A: adresele documentelor-șablon (un articol, o categorie, un termen de căutare ale site-ului real).
        if (array_key_exists('sabloane', $public)) {
            $rez[] = 'sabloane';
        }
        foreach (['structura', 'nonce'] as $k) {
            if (is_array($public['captura'] ?? null) && array_key_exists($k, $public['captura'])) {
                $rez[] = 'captura.' . $k;
            }
        }
        return $rez;
    }

    /** Mărimea maximă a unui fișier privat citit de reguli() (recenzia 2, R3-8). */
    public const PRIVAT_MAX = 1024 * 1024;

    /** Regulile private ale pachetului (git-ignorate): `{"scripturi_aruncate": [...], "captura": {"nonce": [...]}}`. */
    public const REGULI_PRIVATE = 'privat/import-wordpress.json';

    /** Fișierul privat cu `captura.structura` (în dosarul pachetului, git-ignorat): `{"<cale>" | "404": {"et-last-child": [...]}}`. */
    public const STRUCTURA_PRIVATA = 'privat/captura-structura.json';

    /**
     * Problemele unui import-wordpress.json (gol = bun), pentru verifica-pachet.
     *
     * @return list<string>
     */
    public static function valideazaReguli(mixed $r): array
    {
        if (!is_array($r) || ($r !== [] && array_is_list($r))) {
            return ['import-wordpress.json trebuie să fie un obiect JSON'];
        }
        $p = [];
        foreach (array_keys($r) as $k) {
            if (!in_array($k, self::REGULI, true)) {
                $p[] = 'cheie necunoscută „' . $k . '” (sunt: ' . implode(', ', self::REGULI) . ')';
            }
        }
        foreach ((array) ($r['pagini'] ?? []) as $slug => $regula) {
            if (!is_string($slug) || !is_array($regula)) {
                $p[] = 'pagini: fiecare regulă e un obiect, după slug-ul WordPress';
                continue;
            }
            foreach ($regula as $k => $v) {
                $bun = match ($k) {
                    'importa', 'publicata' => is_bool($v),
                    'motiv' => is_string($v),
                    'spre' => is_string($v) && str_starts_with($v, '/') && !str_starts_with($v, '//'),
                    'cod' => $v === 410,
                    'slug' => is_string($v) && preg_match('#^[a-z0-9]+(?:[-/][a-z0-9]+)*$#', $v) === 1,
                    default => false,
                };
                if (!$bun) {
                    $p[] = 'pagini.' . $slug . '.' . $k . ': valoare greșită sau cheie necunoscută';
                }
            }
        }
        if (isset($r['articole']) && (!is_array($r['articole']) || array_diff(array_keys($r['articole']), ['spre']) !== []
            || (isset($r['articole']['spre']) && (!is_string($r['articole']['spre']) || !str_starts_with($r['articole']['spre'], '/'))))) {
            $p[] = 'articole: doar {"spre": "/cale"}';
        }
        if (array_key_exists('stiluri_comune', $r)) {
            $p = array_merge($p, StiluriComune::valideaza($r['stiluri_comune']));
        }
        if (array_key_exists('stiluri_dupa_model', $r)) {
            $p = array_merge($p, StiluriComune::valideazaModel($r['stiluri_dupa_model']));
        }
        if (array_key_exists('legaturi', $r)) {
            $p = array_merge($p, \App\Support\ImportWordPress\Continut\Divi4::valideazaLegaturi($r['legaturi']));
        }
        foreach ((array) ($r['redirecturi'] ?? []) as $i => $regula) {
            if (!is_array($regula) || !is_string($regula[0] ?? null) || !str_starts_with((string) $regula[0], '/')
                || !(is_string($regula[1] ?? null) || ($regula[1] ?? null) === null) || !in_array($regula[2] ?? 301, [301, 410], true)) {
                $p[] = 'redirecturi[' . $i . ']: ["/vechi", "/nou" sau null, 301 sau 410, "motiv"]';
            }
        }
        foreach ((array) ($r['setari'] ?? []) as $k => $v) {
            if (!is_string($k) || preg_match('/^[a-z0-9_]{2,120}$/', $k) !== 1 || !is_scalar($v)) {
                $p[] = 'setari.' . $k . ': numele unei setări (litere mici, cifre, „_”) și o valoare simplă';
            } elseif (preg_match('/(parola|password|secret|token|api_key|cheie)/', $k) === 1) {
                $p[] = 'setari.' . $k . ': fără secrete în pachet (e public)';
            }
        }
        if (isset($r['continut']) && (!is_string($r['continut']) || preg_match('/^[a-z0-9-]{2,40}$/', $r['continut']) !== 1)) {
            $p[] = 'continut: „' . self::CONTINUT_CAPTURAT . '” sau numele unui convertor';
        }
        $capturat = ($r['continut'] ?? null) === self::CONTINUT_CAPTURAT;
        if ($capturat && !in_array($r['dialect'] ?? null, self::DIALECTE, true)) {
            $p[] = 'dialect: pe drumul capturat, unul dintre ' . implode(', ', self::DIALECTE);
        } elseif (!$capturat && array_key_exists('dialect', $r)) {
            $p[] = 'dialect: doar cu "continut": "' . self::CONTINUT_CAPTURAT . '"';
        }
        foreach (['scripturi_aruncate', 'captura', 'regiuni', 'sabloane'] as $cheie) {
            if (!$capturat && array_key_exists($cheie, $r)) {
                $p[] = $cheie . ': doar cu "continut": "' . self::CONTINUT_CAPTURAT . '"';
            }
        }
        // Regiunile dinamice (pista A): gramatica închisă a regulilor, randatorii din lista închisă.
        if (array_key_exists('regiuni', $r)) {
            $p = array_merge($p, \App\Support\CadruCapturat\Regiuni::valideazaReguli($r['regiuni']));
        }
        if (array_key_exists('sabloane', $r)) {
            $sab = $r['sabloane'];
            if (!is_array($sab) || ($sab !== [] && array_is_list($sab))) {
                $p[] = 'sabloane: un obiect {"articol": "/<cale>/", "categorie": "/<cale>/", "cautare": "/?s=<termen>"}';
            } else {
                foreach ($sab as $fel => $cale) {
                    if (!in_array((string) $fel, self::SABLOANE, true)) {
                        $p[] = 'sabloane: felul „' . mb_substr((string) $fel, 0, 40) . '” nu e unul din ' . implode(', ', self::SABLOANE);
                    } elseif (!is_string($cale) || !str_starts_with($cale, '/') || str_starts_with($cale, '//') || strlen($cale) > 300
                        || preg_match('/[\s\\\\\x00-\x1F]/', $cale) === 1 || str_contains('/' . rawurldecode($cale) . '/', '/../')) {
                        $p[] = 'sabloane.' . $fel . ': adresa unui document capturat („/…/” sau „/?s=…”)';
                    }
                }
            }
        }
        $vazute = [];
        foreach ((array) ($r['scripturi_aruncate'] ?? []) as $i => $x) {
            if (!is_array($x) || !is_string($x['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $x['sha256']) !== 1
                || !is_string($x['motiv'] ?? null) || trim($x['motiv']) === '' || array_diff(array_keys($x), ['sha256', 'motiv', 'fel']) !== []
                || (isset($x['fel']) && !in_array($x['fel'], ['script-inline', 'script-src', 'noscript'], true))) {
                $p[] = 'scripturi_aruncate[' . $i . ']: {"sha256": "<64 hexa, litere mici>", "motiv": "…"} (și cel mult "fel")';
                continue;
            }
            if (isset($vazute[$x['sha256']])) {
                $p[] = 'scripturi_aruncate[' . $i . ']: sha256 de două ori';
            }
            $vazute[$x['sha256']] = true;
            if (preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $x['motiv']) === 1) {
                $p[] = 'scripturi_aruncate[' . $i . ']: fără adrese de email în motiv (pachetul e public)';
            }
        }
        if (isset($r['captura'])) {
            if (!is_array($r['captura']) || array_diff(array_keys($r['captura']), ['nonce', 'structura']) !== []) {
                $p[] = 'captura: doar {"nonce": ["<nume>", …], "structura": {"<cale>": {"et-last-child": ["<selector>", …]}}}';
            }
            if (is_array($r['captura']) && array_key_exists('structura', $r['captura'])) {
                $st = $r['captura']['structura'];
                if (!is_array($st) || ($st !== [] && array_is_list($st))) {
                    $p[] = 'captura.structura: un obiect {"<cale>" | "404": {"et-last-child": ["<selector>", …]}}';
                } else {
                    foreach ($st as $doc => $v) {
                        $doc = (string) $doc;
                        if ($doc !== '404' && (!str_starts_with($doc, '/') || str_starts_with($doc, '//') || strlen($doc) > 300
                            || preg_match('/[\s\\\\]/', $doc) === 1 || str_contains('/' . rawurldecode($doc) . '/', '/../'))) {
                            $p[] = 'captura.structura: „' . mb_substr($doc, 0, 60) . '” nu e calea unui document („/…/”) sau „404”';
                        }
                        foreach (SchemaDivi4::structura($v) as $pb) {
                            $p[] = 'captura.structura („' . mb_substr($doc, 0, 60) . '”): ' . $pb;
                        }
                    }
                }
            }
            foreach ((array) ($r['captura']['nonce'] ?? []) as $n) {
                if (!is_string($n) || preg_match('/^[A-Za-z0-9_-]{2,80}$/', $n) !== 1) {
                    $p[] = 'captura.nonce: nume de câmp sau de cheie (litere, cifre, „_”, „-”)';
                }
            }
        }
        return $p;
    }

    /**
     * Inventarul și planul unui site WordPress.
     *
     * @param list<Convertor> $convertoare convertoarele constructorilor de pagini, în plus față de cel simplu
     * @param array{fara?: list<string>} $optiuni
     * @return array{config: ConfigWordPress, sursa: SursaWordPress, inventar: Inventar, context: Context, plan: Plan}
     */
    public static function planifica(string $dosarWp, string $url = '', array $convertoare = [], array $optiuni = [], ?array $reguli = null): array
    {
        $config = ConfigWordPress::citeste($dosarWp);
        $sursa = SursaWordPress::deschide($config);
        $inventar = Inventar::citeste($sursa);
        $reguli ??= self::reguli();
        $context = new Context($inventar, new Adrese($inventar->site['url'] !== '' ? $inventar->site['url'] : $url), $reguli, $config->dosar,
            $config->dosarUploads(), $url, $convertoare);
        return ['config' => $config, 'sursa' => $sursa, 'inventar' => $inventar, 'context' => $context, 'plan' => Plan::fa($inventar, $context, $optiuni)];
    }
}
