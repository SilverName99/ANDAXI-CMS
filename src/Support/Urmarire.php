<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Trackerele unui site adoptat de pe WordPress (consimțământul, VC-TRACKING-001…003; decizia coordonatorului: se păstrează,
 * dar pornesc doar după acord, pe categoria lor). Două părți:
 *
 *  - **declarația, în pachet** (`tema/tema.json` → `urmarire`, în git): ce trackere are site-ul, felul fiecăruia (o listă
 *    închisă: `gtag`, `gtm`, `clarity`, pe care încărcătorul `comun/v1/urmarire.js` le știe porni), categoria de acord, adresa
 *    scriptului (cu `{id}`), sursele exacte pe care le cere fiecare directivă CSP, cookie-urile de șters la retragere și, pentru
 *    `gtag`, parametrii lui `config` și valorile `set`. O sursă cu `*.` (subdomenii dinamice) se poate doar în `connect-src` și
 *    `img-src` și doar cu motivul ei scris în `csp_motiv` (cel puțin 20 de caractere); `script-src` primește doar gazde exacte;
 *  - **identificatorii, în privat** (`privat/urmarire.json`, git-ignorat, lângă pachet: identifică site-ul):
 *    `{"<fel>": "<id>"}`.
 *
 * Un tracker e activ doar cu declarația validă și cu identificatorul lui. Doar trackerele active lărgesc politica CSP
 * (CspSite) și ajung în pagină (`ax-date` → `ax_urmarire`, pus de cadrul capturat la randare); fără niciunul, politica și
 * paginile rămân ca înainte, octet cu octet.
 */
final class Urmarire
{
    public const FELURI = ['gtag', 'gtm', 'clarity'];
    public const CATEGORII = ['statistics', 'marketing', 'preferences'];
    public const DIRECTIVE = ['script-src', 'connect-src', 'img-src', 'frame-src'];
    /** Directivele în care o sursă `https://*.<domeniu>` e permisă (cu motiv). */
    public const DIRECTIVE_STEA = ['connect-src', 'img-src'];
    public const FISIER_PRIVAT = 'privat/urmarire.json';
    /** Cheia din `ax-date` (pusă doar la randare, niciodată din import). */
    public const CHEIE_DATE = 'ax_urmarire';

    private const CHEI = ['fel', 'categorie', 'src', 'csp', 'csp_motiv', 'cookie_retragere', 'parametri', 'seturi', 'motiv'];
    private const SURSA = '#^https://[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$#D';
    private const SURSA_STEA = '#^https://\*(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?){2,}$#D';
    private const ID = '/^[A-Za-z0-9_-]{4,40}$/D';

    /** @var array<string, mixed>|null doar pentru teste: identificatorii în locul fișierului privat */
    private static ?array $iduriProba = null;

    /**
     * Verifică declarația din `tema.json` → `urmarire`. Întoarce trackerele valide (cele greșite se scot) și problemele.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    public static function valideaza(mixed $brut): array
    {
        if (!is_array($brut) || ($brut !== [] && !array_is_list($brut))) {
            return [[], ['urmarire trebuie să fie o listă de trackere [{"fel", "categorie", "src", "csp", …}]']];
        }
        $rez = [];
        $probleme = [];
        $vazute = [];
        foreach ($brut as $i => $t) {
            $p = 'urmarire[' . $i . ']';
            $pb = self::valideazaUnul($t);
            if (is_string($pb)) {
                $probleme[] = $p . ': ' . $pb;
                continue;
            }
            if (isset($vazute[$pb['fel']])) {
                $probleme[] = $p . ': felul „' . $pb['fel'] . '” e declarat de două ori';
                continue;
            }
            $vazute[$pb['fel']] = true;
            $rez[] = $pb;
        }
        return [$rez, $probleme];
    }

    /** @return array<string, mixed>|string trackerul verificat sau problema */
    private static function valideazaUnul(mixed $t): array|string
    {
        if (!is_array($t) || array_is_list($t)) {
            return 'trebuie să fie un obiect';
        }
        $necunoscute = array_diff(array_filter(array_map('strval', array_keys($t)), static fn (string $k): bool => !str_starts_with($k, '_')), self::CHEI);
        if ($necunoscute !== []) {
            return 'chei necunoscute: ' . implode(', ', $necunoscute);
        }
        $fel = $t['fel'] ?? null;
        if (!is_string($fel) || !in_array($fel, self::FELURI, true)) {
            return 'fel trebuie să fie unul din ' . implode(', ', self::FELURI);
        }
        $cat = $t['categorie'] ?? null;
        if (!is_string($cat) || !in_array($cat, self::CATEGORII, true)) {
            return 'categorie trebuie să fie una din ' . implode(', ', self::CATEGORII);
        }
        $motive = $t['csp_motiv'] ?? [];
        if (!is_array($motive) || ($motive !== [] && array_is_list($motive))) {
            return 'csp_motiv trebuie să fie un obiect {"<sursă cu *.>": "<motiv>"}';
        }
        $csp = $t['csp'] ?? null;
        if (!is_array($csp) || $csp === [] || array_is_list($csp)) {
            return 'csp trebuie să fie un obiect {"<directivă>": ["https://<gazdă>", …]}';
        }
        $cspBun = [];
        foreach ($csp as $dir => $surse) {
            $dir = (string) $dir;
            if (!in_array($dir, self::DIRECTIVE, true)) {
                return 'csp: directiva „' . $dir . '” nu se poate lărgi (doar ' . implode(', ', self::DIRECTIVE) . ')';
            }
            if (!is_array($surse) || $surse === [] || !array_is_list($surse)) {
                return 'csp.' . $dir . ' trebuie să fie o listă nevidă de surse';
            }
            foreach ($surse as $s) {
                if (is_string($s) && preg_match(self::SURSA, $s) === 1) {
                    $cspBun[$dir][] = $s;
                    continue;
                }
                if (is_string($s) && preg_match(self::SURSA_STEA, $s) === 1) {
                    if (!in_array($dir, self::DIRECTIVE_STEA, true)) {
                        return 'csp.' . $dir . ': „' . $s . '”: o sursă cu *. se poate doar în ' . implode(', ', self::DIRECTIVE_STEA);
                    }
                    $m = $motive[$s] ?? null;
                    if (!is_string($m) || mb_strlen(trim($m)) < 20) {
                        return 'csp.' . $dir . ': „' . $s . '” are nevoie de motivul ei în csp_motiv (subdomeniile dinamice, cel puțin 20 de caractere)';
                    }
                    $cspBun[$dir][] = $s;
                    continue;
                }
                return 'csp.' . $dir . ': „' . (is_string($s) ? mb_substr($s, 0, 60) : gettype($s)) . '” nu e o sursă https exactă (https://<gazdă>, fără cale)';
            }
        }
        $src = $t['src'] ?? null;
        if (!is_string($src) || substr_count($src, '{id}') !== 1
            || preg_match('#^(https://[a-z0-9.-]+)/[A-Za-z0-9._~/-]*(?:\?[A-Za-z0-9._~=&-]*)?$#D', str_replace('{id}', 'ID', $src), $ms) !== 1) {
            return 'src trebuie să fie o adresă https a scriptului, cu {id} o dată';
        }
        if (!in_array($ms[1], $cspBun['script-src'] ?? [], true)) {
            return 'src: gazda „' . $ms[1] . '” lipsește din csp.script-src';
        }
        $cookie = $t['cookie_retragere'] ?? [];
        if (!is_array($cookie) || ($cookie !== [] && !array_is_list($cookie))
            || array_filter($cookie, static fn (mixed $c): bool => !is_string($c) || preg_match('/^[A-Za-z0-9_]{1,40}\*?$/D', $c) !== 1) !== []) {
            return 'cookie_retragere trebuie să fie o listă de nume de cookie (litere, cifre, _; un * doar la sfârșit)';
        }
        $param = $t['parametri'] ?? [];
        $seturi = $t['seturi'] ?? [];
        foreach (['parametri' => [$param, '/^[a-z][a-z0-9_]{0,39}$/D'], 'seturi' => [$seturi, '/^[A-Za-z][A-Za-z0-9_.]{0,59}$/D']] as $nume => [$v, $re]) {
            if ($v !== [] && $fel !== 'gtag') {
                return $nume . ' se poate doar la gtag';
            }
            if (!is_array($v) || ($v !== [] && array_is_list($v))) {
                return $nume . ' trebuie să fie un obiect';
            }
            foreach ($v as $k => $x) {
                if (preg_match($re, (string) $k) !== 1 || !(is_bool($x) || (is_string($x) && preg_match('/^[A-Za-z0-9 _.:-]{0,100}$/D', $x) === 1))) {
                    return $nume . ': „' . mb_substr((string) $k, 0, 40) . '” (cheia sau valoarea: text simplu sau true/false)';
                }
            }
        }
        return ['fel' => $fel, 'categorie' => $cat, 'src' => $src, 'csp' => $cspBun, 'cookie_retragere' => array_values($cookie),
            'parametri' => $param, 'seturi' => $seturi];
    }

    /**
     * Trackerele active: cele declarate (valide) care au identificatorul în fișierul privat.
     *
     * @param list<array<string, mixed>>|null $declarate implicit din tema site-ului
     * @param array<mixed>|null $iduri implicit din `privat/urmarire.json`
     * @return list<array<string, mixed>> fiecare cu `id` și `adresa` (src cu id-ul codat)
     */
    public static function active(?array $declarate = null, ?array $iduri = null): array
    {
        $declarate ??= (array) Tema::valori()['urmarire'];
        $iduri ??= self::$iduriProba ?? DateSite::json(self::FISIER_PRIVAT);
        $rez = [];
        foreach ($declarate as $t) {
            $id = $iduri[$t['fel']] ?? null;
            if (!is_string($id) || preg_match(self::ID, $id) !== 1) {
                continue;
            }
            $rez[] = $t + ['id' => $id, 'adresa' => str_replace('{id}', rawurlencode($id), (string) $t['src'])];
        }
        return $rez;
    }

    /**
     * Sursele CSP în plus ale trackerelor active, pe directivă.
     *
     * @param list<array<string, mixed>>|null $active
     * @return array<string, list<string>>
     */
    public static function csp(?array $active = null): array
    {
        $rez = [];
        foreach ($active ?? self::active() as $t) {
            foreach ((array) $t['csp'] as $dir => $surse) {
                foreach ((array) $surse as $s) {
                    if (!in_array($s, $rez[$dir] ?? [], true)) {
                        $rez[$dir][] = $s;
                    }
                }
            }
        }
        return $rez;
    }

    /**
     * Valoarea din `ax-date` → `ax_urmarire` pentru încărcător, sau null fără trackere active.
     *
     * @param list<array<string, mixed>>|null $active
     * @return list<array<string, mixed>>|null
     */
    public static function date(?array $active = null): ?array
    {
        $rez = [];
        foreach ($active ?? self::active() as $t) {
            $x = ['fel' => $t['fel'], 'categorie' => $t['categorie'], 'id' => $t['id'], 'src' => $t['adresa'], 'cookie_retragere' => $t['cookie_retragere']];
            if ($t['fel'] === 'gtag') {
                $x['parametri'] = (object) $t['parametri'];
                $x['seturi'] = (object) $t['seturi'];
            }
            $rez[] = $x;
        }
        return $rez === [] ? null : $rez;
    }

    /** Doar pentru teste: identificatorii în locul fișierului privat (null: fișierul). */
    public static function iduriPentruTeste(?array $iduri): void
    {
        self::$iduriProba = $iduri;
    }
}
