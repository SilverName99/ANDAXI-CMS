<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress;

/**
 * Stilurile comune ale unui layout Theme Builder, unificate după o pagină-sursă (adoptarea WordPress, fișierele statice;
 * docs/DEPLOY.md, „Fișierele statice”). Divi scrie regulile antetului și subsolului Theme Builder (`_tb_footer`, `_tb_header` în
 * selectori) în foaia `et-cache` a fiecărei pagini, iar foaia unei pagini se regenerează doar când pagina e salvată: după o
 * reproiectare a subsolului, unele pagini rămân cu regulile vechi (pe pensiune: Camere și Obiective turistice arată subsolul vechi,
 * Acasă și celelalte pe cel nou). Pachetul spune doar de unde se iau regulile (`import-wordpress.json` → `stiluri_comune`:
 * `{"_tb_footer": "/"}`); niciun CSS al clientului nu stă în pachet.
 *
 * La publicarea fișierelor statice: regulile cu marcajul din foile `et-cache` și din `<style>`-urile paginii-sursă sunt referința.
 * Foile și `<style>`-urile trec prin același drum înainte de comparație (`$css`: textul copiei publicate, cu adresele proprii
 * relative), apoi prin aceeași normalizare (multime()). O altă pagină a cărei mulțime de reguli cu marcajul diferă de referință
 * primește, în foile ei `et-cache` care au reguli cu marcajul, regulile ei scoase (și din `@media`, unde un bloc gol dispare) și
 * regulile sursei adăugate la sfârșitul primei dintre ele (cu blocurile `@media` ale lor); restul foii rămâne octet cu octet. O
 * pagină care diferă doar prin `<style>`-ul din HTML (pe care nu-l rescriem) e doar o ATENȚIE, cu un rezumat al diferenței:
 * nu oprește publicarea. Doar o pagină-sursă lipsă sau fără reguli e o problemă (NU).
 */
final class StiluriComune
{
    /** Marcajele permise (selectorii layout-urilor Theme Builder). */
    public const MARCAJE = ['_tb_footer', '_tb_header'];

    /**
     * Regula pachetului validă? `{"_tb_footer": "/"}`: un marcaj din MARCAJE → calea paginii-sursă.
     *
     * @return list<string> problemele
     */
    public static function valideaza(mixed $v): array
    {
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            return ['stiluri_comune: un obiect {"_tb_footer": "/cale"}'];
        }
        $p = [];
        foreach ($v as $marcaj => $cale) {
            if (!in_array($marcaj, self::MARCAJE, true) || !is_string($cale) || preg_match('#^/(?:[a-z0-9-]+/)*$#', $cale) !== 1) {
                $p[] = 'stiluri_comune.' . $marcaj . ': marcajul e unul din ' . implode(', ', self::MARCAJE) . ', valoarea o cale de pagină („/” sau „/pagina/”)';
            }
        }
        return $p;
    }

    /**
     * Desparte o foaie în regulile cu marcajul (ca text CSS, cu blocurile @media/@supports ale lor) și restul (octet cu octet,
     * cu un bloc @media rămas gol scos).
     *
     * @return array{rest: string, reguli: string, lista: list<array{0: string, 1: string, 2: string}>}
     */
    public static function separa(string $css, string $marcaj): array
    {
        $rest = '';
        $reguli = '';
        $lista = [];
        foreach (self::blocuri($css, 0, strlen($css)) as $b) {
            if ($b['fel'] === 'grup') {
                $inRest = '';
                $inReguli = '';
                foreach (self::blocuri($css, $b['interior'][0], $b['interior'][1]) as $r) {
                    $text = substr($css, $r['start'], $r['end'] - $r['start']);
                    if ($r['fel'] === 'regula' && str_contains($r['selector'], $marcaj)) {
                        $inReguli .= $text;
                        $lista[] = [$b['cap'], $r['selector'], $r['declaratii']];
                    } else {
                        $inRest .= $text;
                    }
                }
                $cap = substr($css, $b['start'], $b['interior'][0] - $b['start']);
                if ($inReguli === '') {
                    $rest .= substr($css, $b['start'], $b['end'] - $b['start']);
                    continue;
                }
                $reguli .= $cap . $inReguli . '}';
                if (trim($inRest) !== '') {
                    $rest .= $cap . $inRest . '}';
                }
                continue;
            }
            $text = substr($css, $b['start'], $b['end'] - $b['start']);
            if ($b['fel'] === 'regula' && str_contains($b['selector'], $marcaj)) {
                $reguli .= $text;
                $lista[] = ['', $b['selector'], $b['declaratii']];
            } else {
                $rest .= $text;
            }
        }
        return ['rest' => $rest, 'reguli' => $reguli, 'lista' => $lista];
    }

    /**
     * Mulțimea normalizată a regulilor cu marcajul, pentru comparația între pagini (aceeași pentru foi și pentru `<style>`):
     *  - selectorii: forma CPT a aceluiași layout (paginile fără constructor, 404-ul: `.et-db` și `#et-boc .et-l` în plus) adusă
     *    la forma paginii, spațiile strânse (și în jurul combinatorilor), lista de selectori sortată;
     *  - declarațiile: proprietatea cu litere mici, valoarea cu spațiile strânse, `! important` → `!important`, adresele din
     *    `url()` fără ghilimele și fără schemă și gazdă (`https://gazda/x`, `//gazda/x` → `/x`), declarațiile sortate;
     *  - `@media`/`@supports`: spațiile strânse, litere mici; regulile doar cu `font-variant-ligatures` (Divi le scrie diferit pe
     *    fiecare pagină) ignorate.
     *
     * @param list<array{0: string, 1: string, 2: string}> $lista
     * @return list<string>
     */
    public static function multime(array $lista): array
    {
        $rez = [];
        foreach ($lista as [$cap, $selector, $decl]) {
            $declaratii = [];
            foreach (self::desparte($decl, ';') as $d) {
                $p = strpos($d, ':');
                if ($p === false) {
                    continue;
                }
                $declaratii[] = strtolower(trim(substr($d, 0, $p))) . ':' . self::valoare(substr($d, $p + 1));
            }
            $declaratii = array_values(array_unique($declaratii));
            if ($declaratii === [] || array_filter($declaratii, static fn (string $d): bool => !str_starts_with($d, 'font-variant-ligatures:')) === []) {
                continue;
            }
            sort($declaratii);
            $selectori = [];
            foreach (self::desparte(str_replace('.et-db', '', $selector), ',') as $x) {
                $x = (string) preg_replace('/(?:\s*#et-boc\s+\.et-l)+\s+/', ' ', ' ' . $x);
                $selectori[] = trim((string) preg_replace('/\s*([>+~])\s*/', '$1', (string) preg_replace('/\s+/', ' ', $x)));
            }
            $selectori = array_values(array_unique($selectori));
            sort($selectori);
            $media = strtolower(trim((string) preg_replace('/\s*([():,])\s*/', '$1', (string) preg_replace('/\s+/', ' ', $cap))));
            $rez[] = $media . '|' . implode(',', $selectori) . '|' . implode(';', $declaratii);
        }
        $rez = array_values(array_unique($rez));
        sort($rez);
        return $rez;
    }

    /** Valoarea unei declarații, normalizată pentru comparație (multime()). */
    private static function valoare(string $v): string
    {
        $v = (string) preg_replace('/\s+/', ' ', trim($v));
        $v = (string) preg_replace('/\s*!\s*important$/i', '!important', $v);
        $v = (string) preg_replace_callback('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', static function (array $m): string {
            $u = (string) preg_replace('#^(?:https?:)?//[^/]+#i', '', trim($m[2]));
            return 'url(' . ($u === '' ? '/' : $u) . ')';
        }, $v);
        return (string) preg_replace('/\s*([,()\/])\s*/', '$1', $v);
    }

    /** Desparte după `$sep` în afara parantezelor, a parantezelor drepte și a ghilimelelor; bucățile goale scoase. @return list<string> */
    private static function desparte(string $s, string $sep): array
    {
        $rez = [];
        $bucata = '';
        $adanc = 0;
        $ghilimea = '';
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if ($ghilimea !== '') {
                $bucata .= $c;
                if ($c === '\\' && $i + 1 < $n) {
                    $bucata .= $s[++$i];
                } elseif ($c === $ghilimea) {
                    $ghilimea = '';
                }
                continue;
            }
            if ($c === '"' || $c === '\'') {
                $ghilimea = $c;
            } elseif ($c === '(' || $c === '[') {
                $adanc++;
            } elseif (($c === ')' || $c === ']') && $adanc > 0) {
                $adanc--;
            } elseif ($c === $sep && $adanc === 0) {
                if (trim($bucata) !== '') {
                    $rez[] = trim($bucata);
                }
                $bucata = '';
                continue;
            }
            $bucata .= $c;
        }
        if (trim($bucata) !== '') {
            $rez[] = trim($bucata);
        }
        return $rez;
    }

    /**
     * Planul pentru un site: ce foi se rescriu.
     *
     * @param array<string, string> $pagini calea paginii → HTML-ul capturat
     * @param array<string, string> $foi calea foii (fără query) → CSS-ul publicat
     * @param array<string, string> $reguliPachet marcaj → calea paginii-sursă
     * @param (callable(string): string)|null $css drumul CSS-ului din `<style>`-urile paginilor până la forma foilor publicate
     *   (CopiereStatica::textCss: adresele proprii relative), ca o pagină identică să se compare egal
     * @return array{foi: array<string, string>, pagini: list<string>, probleme: list<string>, atentii: list<string>}
     */
    public static function unifica(array $pagini, array $foi, array $reguliPachet, ?callable $css = null): array
    {
        $rez = ['foi' => [], 'pagini' => [], 'probleme' => [], 'atentii' => []];
        foreach ($reguliPachet as $marcaj => $sursa) {
            if (!isset($pagini[$sursa])) {
                $rez['probleme'][] = 'stiluri_comune.' . $marcaj . ': pagina-sursă ' . $sursa . ' nu e în captură';
                continue;
            }
            [$refText, $refLista] = self::alePaginii($pagini[$sursa], $foi, $rez['foi'], $marcaj, $css);
            if ($refLista === []) {
                $rez['probleme'][] = 'stiluri_comune.' . $marcaj . ': pagina-sursă ' . $sursa . ' n-are reguli ' . $marcaj;
                continue;
            }
            $ref = self::multime($refLista);
            foreach ($pagini as $cale => $html) {
                if ($cale === $sursa) {
                    continue;
                }
                [, $lista, $cuReguli, $dinFoi] = self::alePaginii($html, $foi, $rez['foi'], $marcaj, $css);
                if ($lista === [] || self::multime($lista) === $ref) {
                    continue;
                }
                // Foile paginii n-au reguli cu marcajul sau le au deja pe ale sursei: diferența e doar în `<style>`-ul din HTML.
                if ($cuReguli === [] || self::multime($dinFoi) === $ref) {
                    $rez['atentii'][] = 'stiluri_comune.' . $marcaj . ': ' . $cale . ' are alte reguli decât ' . $sursa . ' doar în <style>-ul din HTML (nu se rescrie; '
                        . self::rezumat(self::multime($lista), $ref) . ')';
                    continue;
                }
                foreach ($cuReguli as $k => $f) {
                    $text = $rez['foi'][$f] ?? $foi[$f];
                    $rez['foi'][$f] = rtrim(self::separa($text, $marcaj)['rest']) . ($k === 0 ? "\n" . $refText . "\n" : "\n");
                }
                $rez['pagini'][] = $cale;
                // Foile rescrise; dacă pagina tot diferă, diferența e în `<style>`-ul din HTML, care rămâne.
                $dupa = self::multime(self::alePaginii($html, $foi, $rez['foi'], $marcaj, $css)[1]);
                if ($dupa !== $ref) {
                    $rez['atentii'][] = 'stiluri_comune.' . $marcaj . ': ' . $cale . ': foile rescrise, dar <style>-ul din HTML are încă alte reguli decât ' . $sursa
                        . ' (' . self::rezumat($dupa, $ref) . ')';
                }
            }
        }
        $rez['pagini'] = array_values(array_unique($rez['pagini']));
        return $rez;
    }

    /**
     * Rezumatul unei diferențe: câte reguli are pagina față de sursă, câte lipsesc, câte sunt în plus, primul selector diferit.
     *
     * @param list<string> $pagina
     * @param list<string> $sursa
     */
    public static function rezumat(array $pagina, array $sursa): string
    {
        $lipsa = array_values(array_diff($sursa, $pagina));
        $plus = array_values(array_diff($pagina, $sursa));
        $prima = explode('|', (string) ($plus[0] ?? $lipsa[0] ?? '||'))[1] ?? '';
        return count($pagina) . ' reguli față de ' . count($sursa) . ', ' . count($lipsa) . ' lipsesc, ' . count($plus) . ' în plus; prima diferită: '
            . (mb_strlen($prima) > 80 ? mb_substr($prima, 0, 79) . '…' : $prima);
    }

    /** Foile et-cache ale unei pagini, în ordinea din HTML. @param array<string, string> $foi @return list<string> */
    public static function foiPagina(string $html, array $foi): array
    {
        $rez = [];
        if (preg_match_all('/<link\b[^>]*>/i', $html, $m) === false) {
            return [];
        }
        foreach ($m[0] as $tag) {
            if (preg_match('/\shref\s*=\s*(["\'])(.*?)\1/i', $tag, $h) !== 1) {
                continue;
            }
            $cale = (string) parse_url(html_entity_decode($h[2], ENT_QUOTES | ENT_HTML5), PHP_URL_PATH);
            if (str_contains($cale, '/et-cache/') && isset($foi[$cale]) && !in_array($cale, $rez, true)) {
                $rez[] = $cale;
            }
        }
        return $rez;
    }

    /**
     * Regulile cu marcajul ale unei pagini: din foile ei et-cache (cum sunt acum, cu rescrierile deja planificate) și din
     * `<style>`-urile HTML-ului.
     *
     * @param array<string, string> $foi
     * @param array<string, string> $scrise
     * @param (callable(string): string)|null $css
     * @return array{0: string, 1: list<array{0: string, 1: string, 2: string}>, 2: list<string>, 3: list<array{0: string, 1: string, 2: string}>}
     *   textul regulilor, toate regulile, foile cu reguli, doar regulile din foi
     */
    private static function alePaginii(string $html, array $foi, array $scrise, string $marcaj, ?callable $css): array
    {
        $text = '';
        $lista = [];
        $cuReguli = [];
        $dinFoi = [];
        foreach (self::foiPagina($html, $foi) as $f) {
            $s = self::separa($scrise[$f] ?? $foi[$f], $marcaj);
            if ($s['lista'] !== []) {
                $text .= $s['reguli'];
                $lista = array_merge($lista, $s['lista']);
                $dinFoi = array_merge($dinFoi, $s['lista']);
                $cuReguli[] = $f;
            }
        }
        if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $m) > 0) {
            foreach ($m[1] as $stil) {
                $s = self::separa($css !== null ? $css($stil) : $stil, $marcaj);
                $text .= $s['reguli'];
                $lista = array_merge($lista, $s['lista']);
            }
        }
        return [$text, $lista, $cuReguli, $dinFoi];
    }

    /**
     * Blocurile de pe un nivel: regulile (`selector{…}`), grupurile `@media`/`@supports` (cu interiorul lor), restul (alte
     * at-uri, comentarii, spații) ca „alt”. Ghilimelele și comentariile nu despart.
     *
     * @return list<array{fel: string, start: int, end: int, selector: string, declaratii: string, cap: string, interior: array{0: int, 1: int}}>
     */
    private static function blocuri(string $css, int $de, int $la): array
    {
        $rez = [];
        $i = $de;
        while ($i < $la) {
            $start = $i;
            // spații și comentarii: lipite de blocul următor (rămân în rest)
            while ($i < $la) {
                if (ctype_space($css[$i])) {
                    $i++;
                } elseif (substr($css, $i, 2) === '/*') {
                    $f = strpos($css, '*/', $i + 2);
                    $i = $f === false || $f + 2 > $la ? $la : $f + 2;
                } else {
                    break;
                }
            }
            if ($i >= $la) {
                $rez[] = ['fel' => 'alt', 'start' => $start, 'end' => $la, 'selector' => '', 'declaratii' => '', 'cap' => '', 'interior' => [0, 0]];
                break;
            }
            $acolada = self::urmator($css, $i, $la, ['{', ';', '}']);
            if ($acolada === null || $css[$acolada] !== '{') {
                $sf = $acolada === null ? $la : $acolada + 1;
                $rez[] = ['fel' => 'alt', 'start' => $start, 'end' => $sf, 'selector' => '', 'declaratii' => '', 'cap' => '', 'interior' => [0, 0]];
                $i = $sf;
                continue;
            }
            $inchis = self::pereche($css, $acolada, $la);
            $cap = trim(substr($css, $i, $acolada - $i));
            if (preg_match('/^@(media|supports)\b/i', $cap) === 1) {
                $rez[] = ['fel' => 'grup', 'start' => $start, 'end' => $inchis + 1, 'selector' => '', 'declaratii' => '', 'cap' => $cap, 'interior' => [$acolada + 1, $inchis]];
            } elseif ($cap !== '' && $cap[0] === '@') {
                $rez[] = ['fel' => 'alt', 'start' => $start, 'end' => $inchis + 1, 'selector' => '', 'declaratii' => '', 'cap' => $cap, 'interior' => [0, 0]];
            } else {
                $rez[] = ['fel' => 'regula', 'start' => $start, 'end' => $inchis + 1, 'selector' => $cap, 'declaratii' => trim(substr($css, $acolada + 1, $inchis - $acolada - 1)),
                    'cap' => '', 'interior' => [0, 0]];
            }
            $i = $inchis + 1;
        }
        return $rez;
    }

    /** Prima poziție a unuia din caractere, în afara ghilimelelor și comentariilor. @param list<string> $car */
    private static function urmator(string $css, int $i, int $la, array $car): ?int
    {
        while ($i < $la) {
            $c = $css[$i];
            if ($c === '"' || $c === '\'') {
                $f = strpos($css, $c, $i + 1);
                $i = $f === false ? $la : $f + 1;
                continue;
            }
            if ($c === '/' && ($css[$i + 1] ?? '') === '*') {
                $f = strpos($css, '*/', $i + 2);
                $i = $f === false ? $la : $f + 2;
                continue;
            }
            if (in_array($c, $car, true)) {
                return $i;
            }
            $i++;
        }
        return null;
    }

    /** Acolada care o închide pe cea de la $i (sau sfârșitul). */
    private static function pereche(string $css, int $i, int $la): int
    {
        $adanc = 0;
        for ($k = $i; $k < $la;) {
            $p = self::urmator($css, $k, $la, ['{', '}']);
            if ($p === null) {
                return $la - 1;
            }
            $adanc += $css[$p] === '{' ? 1 : -1;
            if ($adanc === 0) {
                return $p;
            }
            $k = $p + 1;
        }
        return $la - 1;
    }
}
