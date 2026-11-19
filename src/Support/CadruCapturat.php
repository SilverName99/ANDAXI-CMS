<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\CadruCapturat\EroareJeton;
use App\Support\CadruCapturat\Jetoane;

/**
 * Cadrul paginilor capturate (adoptarea unui site WordPress, pașii 5a și 6; DECIZIE §2.7 [design] 1, I-C §6, B Anexa C): un
 * document capturat (`pagini_capturate`, cu fragmentele lui din `fragmente_capturate`) se randează din cele cinci bucăți și
 * conținutul lui (`pages.html_content` pentru o pagină, coloana `continut` pentru 404, embed, arhive), apoi:
 *  1. fragmentele (`{{ax:fragment:antet|subsol|banner}}`), după cheile documentului (varianta de antet și subsol se alege pe pagină);
 *  2. toate celelalte jetoane, într-o singură trecere (Jetoane::rezolva): scripturile runtime-ului (`runtime:cap`, `runtime:final`),
 *     nonce-ul, datele comportamentului (`date`: `ax-date` refăcut din partea paginii și a fragmentelor), clasele și
 *     `aria-current` ale elementelor de meniu (`meniu:*`), numărul formularului din subsol (`formular_index`: formularele din
 *     conținut), calea cererii (`cale`), anul (`an`) și valorile SEO (`seo.*`).
 * Spațiile și rândurile dintre bucăți sunt cele din captură (tăierea le ține în bucăți). Un jeton necunoscut sau rămas e o
 * EroareJeton: răspunsul e 500 și un rând în jurnal, niciodată o pagină pe jumătate. Aceeași funcție o folosesc dovada tăierii
 * (la import), P1 și cererile web.
 */
final class CadruCapturat
{
    /** Începutul documentului, cum îl scrie cadrul. */
    public const DOCTYPE = "<!DOCTYPE html>\n";

    /** Fragmentele unui document, în ordinea din el. */
    public const FRAGMENTE = ['antet', 'subsol', 'banner'];

    /** Forma unui nonce scris de Normalizare în captură (`{{ax:nonce}}`). */
    public const LOC_NONCE = '{{ax:nonce}}';

    /**
     * Documentul randat.
     *
     * @param array<string, mixed> $doc rândul din `pagini_capturate`, cu coloanele JSON decodate (dinJson): `date_pagina`
     *   (obiect: partea paginii din `ax-date`), `date_reteta` (rețeta ordinii), `meniu` (clasele și aria-current ale elementelor
     *   de meniu care diferă pe pagina aceasta de cele ale fragmentului), `valori_import` (cale, an, seo: valorile de la import)
     * @param array<string, array{html: string, date?: mixed, meniu?: array<string, array{0: string, 1: string}>}> $fragmente fel → fragmentul ales
     * @param array{cale: string, nonce: string, an: string, runtime?: array{cap?: string, final?: string}, seo?: array<string, string>} $cerere
     * @throws EroareJeton
     */
    public static function randeaza(array $doc, string $continut, array $fragmente, array $cerere): string
    {
        $html = self::asambleazaBucati((string) $doc['html_atribute'], (string) $doc['cap_html'], (string) $doc['body_atribute'], (string) $doc['inainte'],
            $continut, (string) $doc['dupa']);
        $html = self::puneFragmente($html, array_map(static fn (array $f): string => (string) $f['html'], $fragmente));
        $meniuPagina = self::lista($doc['meniu'] ?? []);
        $seoImport = self::lista(self::lista($doc['valori_import'] ?? [])['seo'] ?? []);
        $nonce = (string) $cerere['nonce'];
        $valori = static function (string $nume) use ($doc, $meniuPagina, $fragmente, $continut, $cerere, $seoImport, $nonce): ?string {
            if ($nume === 'nonce') {
                return $nonce;
            }
            if ($nume === 'runtime:cap' || $nume === 'runtime:final') {
                return (string) ($cerere['runtime'][substr($nume, 8)] ?? '');
            }
            if ($nume === 'cale') {
                return (string) $cerere['cale'];
            }
            if ($nume === 'an') {
                return (string) $cerere['an'];
            }
            if ($nume === 'formular_index') {
                return (string) self::formulareInContinut($continut);
            }
            if ($nume === 'date') {
                $reteta = self::lista($doc['date_reteta'] ?? null);
                if ($reteta === []) {
                    return null;
                }
                $d = self::asambleaza(self::obiecte($doc['date_pagina'] ?? null), self::obiecte($fragmente['antet']['date'] ?? null),
                    self::obiecte($fragmente['subsol']['date'] ?? null), $reteta);
                return self::json(self::cuNonce($d, $nonce));
            }
            if (preg_match('/^meniu:(antet|subsol):(\d{1,4}):(clase|aria)$/D', $nume, $m) === 1) {
                $i = $m[3] === 'clase' ? 0 : 1;
                $v = self::lista(self::lista($meniuPagina[$m[1]] ?? [])[$m[2]] ?? [])[$i]
                    ?? self::lista(self::lista($fragmente[$m[1]]['meniu'] ?? [])[$m[2]] ?? [])[$i] ?? null;
                return is_string($v) ? $v : null;
            }
            if (preg_match('/^seo\.(titlu|descriere|canonical|robots|imagine)$/D', $nume, $m) === 1) {
                $v = $cerere['seo'][$m[1]] ?? $seoImport[$m[1]] ?? null;
                return is_string($v) ? $v : null;
            }
            return null;
        };
        return Jetoane::rezolva($html, $valori);
    }

    /**
     * Un rând din `pagini_capturate` randat: conținutul lui (`pages.html_content` pentru o pagină, altfel coloana `continut`),
     * fragmentele alese din `fragmente_capturate`, coloanele JSON decodate.
     *
     * @param array<string, mixed> $doc rândul, cum vine din bază
     * @param array{cale: string, nonce: string, an: string, seo?: array<string, string>} $cerere
     * @param array{cap?: string, final?: string} $runtime
     * @throws EroareJeton
     */
    public static function randeazaRand(\PDO $db, array $doc, array $cerere, array $runtime): string
    {
        $continut = (string) ($doc['continut'] ?? '');
        if (($doc['tip'] ?? '') === 'pagina') {
            $q = $db->prepare('SELECT html_content FROM pages WHERE id = ?');
            $q->execute([(int) $doc['page_id']]);
            $continut = (string) $q->fetchColumn();
        }
        $fragmente = [];
        $q = $db->prepare('SELECT html, date, meniu FROM fragmente_capturate WHERE cheie = ?');
        foreach (self::FRAGMENTE as $f) {
            if (($doc[$f] ?? '') === '' || $doc[$f] === null) {
                continue;
            }
            $q->execute([(string) $doc[$f]]);
            $r = $q->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($r)) {
                throw new EroareJeton('lipsește fragmentul ' . $doc[$f]);
            }
            $fragmente[$f] = ['html' => (string) $r['html'], 'date' => self::dinJson($r['date']), 'meniu' => self::dinJsonLista($r['meniu'])];
        }
        $doc['date_pagina'] = self::dinJson($doc['date_pagina'] ?? null);
        $doc['date_reteta'] = self::dinJsonLista($doc['date_reteta'] ?? null);
        $doc['meniu'] = self::dinJsonLista($doc['meniu'] ?? null);
        $doc['valori_import'] = self::dinJsonLista($doc['valori_import'] ?? null);
        return self::randeaza($doc, $continut, $fragmente, $cerere + ['runtime' => $runtime]);
    }

    /**
     * Scripturile runtime-ului compat pe sloturile documentului (contractul pistei R, pasul 8a): manifestul versiunii
     * (`public/assets/compat/<dialect>/v<n>/sloturi.json`), fiecare cale din `cap` și `final` ca
     * `<script src="/assets/compat/<cale>?v=<12 hex din sha256-ul fișierului>"></script>`, sincron, în ordinea listei. Fără runtime
     * (`""`), nimic. Un manifest sau un fișier lipsă (o versiune copiată pe jumătate) e o eroare: pagina nu iese fără runtime.
     *
     * @return array{cap: string, final: string}
     * @throws \RuntimeException
     */
    public static function scripturiRuntime(string $runtime): array
    {
        if ($runtime === '') {
            return ['cap' => '', 'final' => ''];
        }
        if (isset(self::$runtime[$runtime])) {
            return self::$runtime[$runtime];
        }
        if (preg_match('/^([a-z0-9]{2,20})@([1-9][0-9]{0,2})$/D', $runtime, $m) !== 1) {
            throw new \RuntimeException('runtime necunoscut: ' . $runtime);
        }
        $manifest = Paths::public('assets/compat/' . $m[1] . '/v' . $m[2] . '/sloturi.json');
        $date = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;
        if (!is_array($date) || !is_array($date['cap'] ?? null) || !is_array($date['final'] ?? null)) {
            throw new \RuntimeException('manifestul runtime-ului lipsește sau e stricat: ' . $manifest);
        }
        $rez = ['cap' => '', 'final' => ''];
        foreach (['cap', 'final'] as $slot) {
            foreach ($date[$slot] as $cale) {
                if (!is_string($cale) || preg_match('#^[a-z0-9]{2,20}/v[1-9][0-9]{0,2}/[a-z0-9-]{1,60}\.js$#D', $cale) !== 1) {
                    throw new \RuntimeException('manifestul runtime-ului: cale nepermisă');
                }
                $fisier = Paths::public('assets/compat/' . $cale);
                if (!is_file($fisier)) {
                    throw new \RuntimeException('runtime-ul compat: lipsește ' . $cale);
                }
                $rez[$slot] .= '<script src="/assets/compat/' . $cale . '?v=' . substr((string) hash_file('sha256', $fisier), 0, 12) . '"></script>';
            }
        }
        return self::$runtime[$runtime] = $rez;
    }

    /**
     * Valoarea câmpurilor nonce ale formularelor capturate (`{{ax:nonce}}`) pentru o zi: un jeton HMAC cu cheia aplicației, aceeași
     * pentru toată ziua (pagina poate sta în cache), pe care serverul formularelor (pasul 9b) îl acceptă și pe ziua de dinainte.
     */
    public static function nonceZi(?string $zi = null, string $cheie = ''): string
    {
        $cheie = $cheie !== '' ? $cheie : (string) (getenv('APP_KEY') ?: ($_ENV['APP_KEY'] ?? 'cadru-capturat'));
        return substr(hash_hmac('sha256', 'formular-capturat|' . ($zi ?? date('Y-m-d')), $cheie), 0, 10);
    }

    /**
     * Rândul documentului capturat al unei pagini (`page_id`), al 404-ului (`404`) sau al unei căi (embed, arhive), ori null
     * (fără tabel, fără document).
     *
     * @return array<string, mixed>|null
     */
    public static function document(\PDO $db, ?int $pagina = null, ?string $cheie = null, ?string $cale = null): ?array
    {
        try {
            if ($pagina !== null) {
                $q = $db->prepare('SELECT * FROM pagini_capturate WHERE page_id = ? AND tip = \'pagina\'');
                $q->execute([$pagina]);
            } elseif ($cheie !== null) {
                $q = $db->prepare('SELECT * FROM pagini_capturate WHERE cheie = ?');
                $q->execute([$cheie]);
            } else {
                $q = $db->prepare('SELECT * FROM pagini_capturate WHERE cale_hash = ? AND tip IN (\'embed\', \'arhiva\')');
                $q->execute([hash('sha256', (string) $cale)]);
            }
            $r = $q->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return null;
        }
        return is_array($r) ? $r : null;
    }

    /**
     * Trimite un document capturat ca răspuns: statusul, antetele capturate din lista albă (tipul conținutului, cache-ul,
     * `expires`, `x-robots-tag`), corpul randat. Un jeton necunoscut sau rămas, un runtime lipsă: 500, un rând în jurnal, nicio
     * pagină pe jumătate. Întoarce codul trimis.
     *
     * @param array<string, mixed> $doc rândul din `pagini_capturate`
     * @param array{cale: string, nonce: string, an: string, seo?: array<string, string>} $cerere
     */
    public static function raspunde(\PDO $db, array $doc, array $cerere, int $status): int
    {
        try {
            $html = self::randeazaRand($db, $doc, $cerere, self::scripturiRuntime(Tema::runtime()));
        } catch (\Throwable $e) {
            error_log('ANDAXI-CMS: cadrul capturat: documentul „' . (string) ($doc['cheie'] ?? '?') . '” nu se poate randa (' . get_class($e) . ': ' . $e->getMessage() . ')');
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
            echo "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"><title>Eroare</title></head><body><p>Pagina nu se poate afișa acum.</p></body></html>\n";
            return 500;
        }
        // Politica site-ului pleacă de la cine răspunde (recenzia 3, R2-N1): un document capturat (și 404-ul lui pe o cale pe
        // care o rută a CMS-ului n-o servește) o are mereu, oricare ar fi ruta potrivită.
        CspSite::trimite((string) ($_SERVER['REQUEST_URI'] ?? ($cerere['cale'] ?? '/')), true);
        http_response_code($status);
        $tip = false;
        $antete = json_decode((string) ($doc['antete_http'] ?? ''), true);
        foreach (is_array($antete) ? $antete : [] as $a) {
            if (!is_array($a) || count($a) !== 2 || !is_string($a[0]) || !is_string($a[1]) || preg_match('/[\r\n]/', $a[1]) === 1) {
                continue;
            }
            $n = strtolower($a[0]);
            if (in_array($n, ['content-type', 'cache-control', 'expires', 'x-robots-tag'], true)) {
                header(self::numeAntet($n) . ': ' . $a[1], $n !== 'x-robots-tag');
                $tip = $tip || $n === 'content-type';
            }
        }
        if (!$tip) {
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo $html;
        return $status;
    }

    /** Manifestul capturii site-ului (pasul 4): `capturat/manifest.json` lângă `.env` (unde îl caută și importul). */
    public static function fisierManifest(): string
    {
        return dirname(Paths::env()) . '/capturat/manifest.json';
    }

    /**
     * Scripturile unei ieșiri a cadrului care nu sunt permise (P1, pasul 7): doar datele inerte (`ax-date`, JSON-LD,
     * `speculationrules`) și scripturile runtime-ului de pe sloturi (`/assets/compat/…?v=…`) au voie. @return list<string>
     */
    public static function scripturiNepermise(string $html, array $runtime): array
    {
        $permise = [];
        preg_match_all('#<script src="[^"]+"></script>#', ($runtime['cap'] ?? '') . ($runtime['final'] ?? ''), $m);
        foreach ($m[0] as $tag) {
            $permise[$tag] = true;
        }
        $rez = [];
        preg_match_all('#<script\b[^>]*>#i', $html, $mm);
        foreach ($mm[0] as $deschidere) {
            if (preg_match('#^<script (?:type="application/json" id="ax-date"|type="application/ld\+json"(?: class="[a-z-]+")?|type="speculationrules")>$#', $deschidere) === 1) {
                continue;
            }
            if (preg_match('#^<script src="[^"]+">$#', $deschidere) === 1 && isset($permise[$deschidere . '</script>'])) {
                continue;
            }
            $rez[] = mb_substr($deschidere, 0, 120);
        }
        return $rez;
    }

    /** Numele unui antet cu majusculele obișnuite (`content-type` → `Content-Type`). */
    private static function numeAntet(string $n): string
    {
        return implode('-', array_map('ucfirst', explode('-', $n)));
    }

    /** @var array<string, array{cap: string, final: string}> */
    private static array $runtime = [];

    /** Un JSON salvat ca listă asociativă (rețeta, meniul, valorile de la import); gol = []. @return array<mixed> */
    public static function dinJsonLista(?string $json): array
    {
        $v = $json === null || $json === '' ? null : json_decode($json, true);
        return is_array($v) ? $v : [];
    }

    /** Cele cinci bucăți puse la loc, în forma cadrului (`$cap` se termină cu `</head>`, bucata de după cu `</body>`). */
    public static function asambleazaBucati(string $htmlAtribute, string $cap, string $bodyAtribute, string $inainte, string $continut, string $dupa): string
    {
        return self::DOCTYPE . '<html' . $htmlAtribute . ">\n<head>" . $cap . "\n<body" . $bodyAtribute . '>' . $inainte . $continut . $dupa . "\n</html>\n";
    }

    /**
     * Fragmentele (`{{ax:fragment:<fel>}}`) puse în document, o dată; un fragment nu poate cuprinde alt fragment.
     *
     * @param array<string, string> $fragmente fel → html
     * @throws EroareJeton un fragment lipsă sau cuprins în altul
     */
    public static function puneFragmente(string $doc, array $fragmente): string
    {
        foreach ($fragmente as $html) {
            if (str_contains($html, '{{ax:fragment:')) {
                throw new EroareJeton('un fragment cuprinde alt fragment');
            }
        }
        return (string) preg_replace_callback('/\{\{ax:fragment:(antet|subsol|banner)\}\}/', static function (array $m) use ($fragmente): string {
            if (!isset($fragmente[$m[1]])) {
                throw new EroareJeton('lipsește fragmentul „' . $m[1] . '”');
            }
            return $fragmente[$m[1]];
        }, $doc);
    }

    /**
     * Datele comportamentului refăcute din părțile lor (pagina, antetul, subsolul), după rețeta ordinii (Divi4Taiere::imparte):
     * pentru fiecare configurație, proprietarul fiecărei chei (obiecte) sau al fiecărui element (liste), în ordinea din pagină.
     *
     * @param array<string, mixed> $pagina
     * @param array<string, mixed> $antet
     * @param array<string, mixed> $subsol
     * @param array<string, mixed> $reteta
     * @return array<string, mixed>
     */
    public static function asambleaza(array $pagina, array $antet, array $subsol, array $reteta): array
    {
        $parti = ['pagina' => $pagina, 'antet' => $antet, 'subsol' => $subsol];
        $rez = [];
        foreach ($reteta as $nume => $r) {
            $r = (array) $r;
            if (($r[0] ?? '') === 'obiect') {
                $o = new \stdClass();
                foreach ((array) ($r[1] ?? []) as $x) {
                    [$p, $k] = array_values((array) $x);
                    $sursa = $parti[$p][$nume] ?? null;
                    if ($sursa instanceof \stdClass && property_exists($sursa, (string) $k)) {
                        $o->{$k} = $sursa->{$k};
                    }
                }
                $rez[$nume] = $o;
            } elseif (($r[0] ?? '') === 'lista') {
                $poz = ['pagina' => 0, 'antet' => 0, 'subsol' => 0];
                $l = [];
                foreach ((array) ($r[1] ?? []) as $p) {
                    $sursa = array_values((array) ($parti[$p][$nume] ?? []));
                    if (array_key_exists($poz[$p], $sursa)) {
                        $l[] = $sursa[$poz[$p]];
                    }
                    $poz[$p]++;
                }
                $rez[$nume] = $l;
            } elseif (array_key_exists($nume, $pagina)) {
                $rez[$nume] = $pagina[$nume];
            }
        }
        return $rez;
    }

    /** JSON-ul blocului `ax-date`: aceleași date, codate determinist; „<” și „&” scăpate (nimic nu poate închide scriptul). */
    public static function json(array $date): string
    {
        return json_encode((object) $date, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
            | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * Datele, din JSON-ul lor salvat (obiectele rămân obiecte, ca la citirea capturii). Fără nicio schimbare de tip: importul a
     * refuzat deja întregii în afara ±2^53 și numerele infinite (SchemaDivi4::jsonStrict), deci ce s-a scris se citește la fel.
     */
    public static function dinJson(?string $json): mixed
    {
        return $json === null || $json === '' ? null : json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

    /** Câte formulare Divi are conținutul (numărul formularului din subsol, ca în WordPress). */
    public static function formulareInContinut(string $continut): int
    {
        return preg_match_all('/\bid="et_pb_contact_form_\d{1,3}"/', $continut);
    }

    /** Un obiect al datelor ca listă asociativă de nivel întâi (proprietățile lui), lăsând valorile neatinse. @return array<string, mixed> */
    private static function obiecte(mixed $v): array
    {
        return $v instanceof \stdClass ? get_object_vars($v) : (is_array($v) ? $v : []);
    }

    /** O valoare JSON decodată (obiect sau listă) ca listă asociativă, recursiv pe obiecte; altceva = []. @return array<mixed> */
    private static function lista(mixed $v): array
    {
        if ($v instanceof \stdClass) {
            $v = get_object_vars($v);
        }
        return is_array($v) ? array_map(static fn (mixed $x): mixed => $x instanceof \stdClass ? self::lista($x) : $x, $v) : [];
    }

    /** Valorile `{{ax:nonce}}` din date înlocuite cu nonce-ul cererii. */
    private static function cuNonce(mixed $v, string $nonce): mixed
    {
        if ($v === self::LOC_NONCE) {
            return $nonce;
        }
        if ($v instanceof \stdClass) {
            $o = new \stdClass();
            foreach (get_object_vars($v) as $k => $x) {
                $o->{$k} = self::cuNonce($x, $nonce);
            }
            return $o;
        }
        if (is_array($v)) {
            return array_map(static fn (mixed $x): mixed => self::cuNonce($x, $nonce), $v);
        }
        return $v;
    }
}
