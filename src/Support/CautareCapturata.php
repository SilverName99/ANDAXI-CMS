<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Html5\Editare;
use App\Support\Html5\Token;
use PDO;
use Throwable;

/**
 * Căutarea WordPress a unui site capturat (adoptarea WordPress, pasul 9b; `wp-compat.json` → `cautare_wp`; docs/DEPLOY.md,
 * „Căutarea”): lupa din antet trimite `GET /?s=<termeni>`. Răspunsul e o pagină de rezultate în cadrul site-ului: documentul
 * 404 capturat (antetul, subsolul, bannerul și foile lui), cu conținutul înlocuit de lista paginilor găsite (titlul și
 * legătura) sau de mesajul fără rezultate, statusul 200, `noindex` (meta robots și `X-Robots-Tag`), niciodată în cache.
 *
 * Potrivirea e a WordPress-ului (WP_Query::parse_search): termenii despărțiți la spații, frazele între ghilimele întregi, cei de o
 * literă și cuvintele de legătură ale limbii WordPress (`stopwords`, en_US) scoși, peste 9 termeni toată fraza ca un termen,
 * `-termen` exclus; fiecare termen trebuie să fie în titlu, rezumat sau conținutul brut din WordPress (cu shortcodurile lui:
 * `corpus_cautare`, scris de import), fără deosebire de litere mari și de diacritice (ca colația bazei WordPress). Ordinea:
 * titlul cu toată fraza, titlul cu toți termenii, titlul cu un termen, rezumatul, conținutul, apoi cele mai noi întâi (id-ul
 * WordPress în locul datei). O pagină editată în admin după import se caută în textul ei de acum. Doar paginile publicate;
 * cel mult MAX_REZULTATE, pe o singură pagină (fără paginare). Termenul se arată doar escapat.
 */
final class CautareCapturata
{
    public const LUNGIME = 200;
    public const MAX_REZULTATE = 50;

    /** Cuvintele de legătură ale WordPress (wp_search_stopwords, en_US: limba site-ului de azi). */
    private const STOP = ['about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'for', 'from', 'how', 'in', 'is', 'it', 'of', 'on', 'or', 'that', 'the', 'this',
        'to', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'www'];

    public static function intercepteaza(?PDO $db, string $metoda, string $uri): bool
    {
        if (!in_array(strtoupper($metoda), ['GET', 'HEAD'], true) || !$db instanceof PDO || Tema::cadru() !== 'capturat' || !WpCompat::cautareWp()) {
            return false;
        }
        $parti = explode('?', explode('#', $uri, 2)[0], 2);
        if ($parti[0] !== '/' || !isset($parti[1])) {
            return false;
        }
        parse_str($parti[1], $q);
        if (!array_key_exists('s', $q)) {
            return false;
        }
        $termen = self::curata(is_string($q['s']) ? $q['s'] : '');
        try {
            $doc = CadruCapturat::document($db, cheie: '404');
            if ($doc === null) {
                return false;
            }
            $rezultate = self::cauta($db, $termen);
        } catch (Throwable $e) {
            error_log('ANDAXI-CMS: căutarea: ' . get_class($e) . ': ' . $e->getMessage());
            return false;
        }
        // Rezultatele (termenul cererii, titlurile paginilor) intră DUPĂ randare, în locul unui semn aleator: nimic din cerere nu
        // trece prin Jetoane::rezolva, deci un `{{ax:…}}` în termen sau într-un titlu rămâne text (escapat), niciodată un jeton.
        $semn = "\u{E000}ax-cautare-" . bin2hex(random_bytes(8)) . "\u{E000}";
        $doc['continut'] = $semn;
        $continut = self::continut($termen, $rezultate);
        $titlu = 'Rezultatele căutării pentru „' . $termen . '” – ' . Brand::numeSite(Settings::all($db));
        header('X-Robots-Tag: noindex, follow');
        CadruCapturat::raspunde($db, $doc, ['cale' => '/', 'nonce' => CadruCapturat::nonceZi(), 'an' => date('Y')], 200,
            static function (string $html) use ($titlu, $semn, $continut): string {
                if (substr_count($html, $semn) !== 1) {
                    throw new \RuntimeException('locul rezultatelor nu e o dată în pagina de rezultate');
                }
                $html = str_replace($semn, $continut, $html);
                try {
                    return self::cap($html, $titlu);
                } catch (Throwable $e) {
                    error_log('ANDAXI-CMS: căutarea: titlul paginii de rezultate rămâne al documentului 404 (' . $e->getMessage() . ')');
                    return $html;
                }
            }, true);
        return true;
    }

    /** Termenul cererii: text UTF-8, fără caractere de control, cel mult LUNGIME caractere. */
    public static function curata(string $s): string
    {
        if (!mb_check_encoding($s, 'UTF-8')) {
            return '';
        }
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s)), 0, self::LUNGIME);
    }

    /**
     * Termenii căutării, ca WordPress (parse_search_terms): frazele între ghilimele, apoi cuvintele; fără cei de o literă și fără
     * cuvintele de legătură; peste 9, toată fraza e un termen. @return list<string>
     */
    public static function termeni(string $s): array
    {
        $s = trim($s);
        if ($s === '') {
            return [];
        }
        preg_match_all('/".*?("|$)|((?<=[\t ",+])|^)[^\t ",+]+/u', $s, $m);
        $toti = [];
        foreach ($m[0] as $t) {
            $t = trim($t, "\"' \t\n\r\0\x0B");
            if ($t !== '') {
                $toti[] = $t;
            }
        }
        if (count($toti) > 9) {
            return [$s];
        }
        $buni = array_values(array_filter($toti, static fn (string $t): bool => !(strlen($t) === 1 && preg_match('/^[a-z\-]$/i', $t) === 1)
            && !in_array(mb_strtolower(ltrim($t, '-')), self::STOP, true)));
        return $buni !== [] ? $buni : $toti;
    }

    /**
     * Paginile găsite, în ordinea WordPress. @return list<array{titlu: string, adresa: string}>
     */
    public static function cauta(PDO $db, string $s): array
    {
        $termeni = self::termeni($s);
        $randuri = $db->query("SELECT pc.wp_id, pc.corpus_cautare, pc.editat_la, p.id, p.title, p.slug, p.html_content FROM pagini_capturate pc
            JOIN pages p ON p.id = pc.page_id WHERE pc.tip = 'pagina' AND p.is_published = 1 AND p.deleted_at IS NULL AND p.slug <> '404'")->fetchAll(PDO::FETCH_ASSOC);
        $plus = array_values(array_filter($termeni, static fn (string $t): bool => !str_starts_with($t, '-') || strlen($t) === 1));
        $minus = array_values(array_map(static fn (string $t): string => substr($t, 1), array_filter($termeni, static fn (string $t): bool => str_starts_with($t, '-') && strlen($t) > 1)));
        $fraza = self::pliaza($s);
        $gasite = [];
        foreach ($randuri as $r) {
            $c = $r['editat_la'] === null ? json_decode((string) $r['corpus_cautare'], true) : null;
            $titlu = is_array($c) ? (string) ($c['titlu'] ?? '') : (string) $r['title'];
            $rezumat = is_array($c) ? (string) ($c['rezumat'] ?? '') : '';
            $continut = is_array($c) ? (string) ($c['continut'] ?? '') : html_entity_decode(strip_tags((string) $r['html_content']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            [$pt, $pr, $pc] = [self::pliaza($titlu), self::pliaza($rezumat), self::pliaza($continut)];
            $in = static fn (string $t): bool => ($t = self::pliaza($t)) === '' || str_contains($pt, $t) || str_contains($pr, $t) || str_contains($pc, $t);
            foreach ($plus as $t) {
                if (!$in($t)) {
                    continue 2;
                }
            }
            foreach ($minus as $t) {
                if (($t = self::pliaza($t)) !== '' && (str_contains($pt, $t) || str_contains($pr, $t) || str_contains($pc, $t))) {
                    continue 2;
                }
            }
            if (count($plus) > 1) {
                $inTitlu = array_map(static fn (string $t): bool => str_contains($pt, self::pliaza($t)), $plus);
                $rang = $fraza !== '' && str_contains($pt, $fraza) ? 1 : (count($plus) < 7 && !in_array(false, $inTitlu, true) ? 2
                    : (count($plus) < 7 && in_array(true, $inTitlu, true) ? 3 : ($fraza !== '' && str_contains($pr, $fraza) ? 4
                    : ($fraza !== '' && str_contains($pc, $fraza) ? 5 : 6))));
            } else {
                $rang = $plus !== [] && str_contains($pt, self::pliaza($plus[0])) ? 0 : 1;
            }
            $gasite[] = ['rang' => $rang, 'wp' => (int) $r['wp_id'], 'titlu' => (string) $r['title'], 'adresa' => WpCompat::adresaPagina((string) $r['slug'])];
        }
        usort($gasite, static fn (array $a, array $b): int => [$a['rang'], -$a['wp']] <=> [$b['rang'], -$b['wp']]);
        return array_map(static fn (array $g): array => ['titlu' => $g['titlu'], 'adresa' => $g['adresa']], array_slice($gasite, 0, self::MAX_REZULTATE));
    }

    /** Textul pentru comparație: litere mici, fără diacritice (ca o colație `_ci` a bazei WordPress). */
    public static function pliaza(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($d)) {
                return (string) preg_replace('/\p{Mn}+/u', '', $d);
            }
        }
        return strtr($s, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'ö' => 'o', 'ó' => 'o', 'ü' => 'u', 'ú' => 'u', 'í' => 'i', 'ç' => 'c']);
    }

    /**
     * Conținutul paginii de rezultate (în `et_builder_inner_content` al șablonului 404), cu clasele de bază ale modulelor Divi
     * (secțiune, rând, coloană, text) pe care foile documentului 404 le au deja. Totul escapat.
     *
     * @param list<array{titlu: string, adresa: string}> $rezultate
     */
    public static function continut(string $termen, array $rezultate): string
    {
        // Escapat ca un jeton (și `{`): textul nu poate face un `{{ax:` nici dacă ar ajunge vreodată înaintea randării.
        $e = static fn (string $t): string => \App\Support\CadruCapturat\Jetoane::scapa($t, 'html');
        if ($rezultate === []) {
            $text = '<h1 class="not-found-title">Nu am găsit niciun rezultat</h1>'
                . '<p>Nicio pagină nu se potrivește cu „' . $e($termen) . '”. Încearcă alte cuvinte sau folosește meniul de mai sus pentru a găsi ce cauți.</p>';
        } else {
            $text = '<h1>Rezultatele căutării pentru „' . $e($termen) . '”</h1><ul>';
            foreach ($rezultate as $r) {
                $text .= '<li><a href="' . $e($r['adresa']) . '">' . $e($r['titlu']) . '</a></li>';
            }
            $text .= '</ul>';
        }
        return '<div class="et_pb_section et_section_regular"><div class="et_pb_row"><div class="et_pb_column et_pb_column_4_4 et-last-child">'
            . '<div class="et_pb_module et_pb_text et_pb_text_align_left et_pb_bg_layout_light"><div class="et_pb_text_inner">' . $text
            . '</div></div></div></div></div>';
    }

    /** Head-ul paginii de rezultate: titlul, `noindex, follow` în meta robots (dacă documentul 404 îl are), fără canonical. */
    public static function cap(string $html, string $titlu): string
    {
        $e = new Editare($html);
        $schimbat = false;
        foreach ($e->tokeni() as $k => $t) {
            if ($t->tip === Token::INCHIDERE && $t->nume === 'head') {
                break;
            }
            if ($t->tip !== Token::DESCHIDERE) {
                continue;
            }
            if ($t->nume === 'title') {
                $e->interior($k, \App\Support\CadruCapturat\Jetoane::scapa($titlu, 'html'));
                $schimbat = true;
            } elseif ($t->nume === 'meta' && strtolower((string) $t->valoare($html, 'name')) === 'robots') {
                $e->valoareAtribut($k, 'content', 'noindex, follow');
                $schimbat = true;
            } elseif ($t->nume === 'link' && in_array('canonical', explode(' ', strtolower((string) $t->valoare($html, 'rel'))), true)) {
                $e->stergeNod($k);
                $schimbat = true;
            }
        }
        return $schimbat ? $e->aplica() : $html;
    }
}
