<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress;

/**
 * SEO-ul paginilor și articolelor unui site WordPress, din pluginul lui de SEO, cu etichetele „inteligente” înlocuite:
 *
 *  - All in One SEO (v4+): tabelul `aioseo_posts` (titlul, descrierea, canonicalul, robots, poza de distribuire), cu
 *    șabloanele implicite din `aioseo_options` / `aioseo_options_dynamic` (#post_title #separator_sa #site_title);
 *  - Yoast SEO: `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, … cu șabloanele din `wpseo_titles` (%%title%% %%sep%% …);
 *  - Rank Math: `rank_math_title`, `rank_math_description`, … cu `rank-math-options-titles` (%title% %sep% …).
 *
 * O etichetă pe care n-o știe (de exemplu #post_content, al cărei text îl face pluginul din conținut) se lasă goală și
 * se spune în `probleme`: planul o arată, iar importul poate lua textul din pagina randată (Importator, „--url”).
 */
final class SeoWordPress
{
    /**
     * @return array<int, array{titlu: string, descriere: string, canonical: string, robots: string, imagine: string, sursa: string, probleme: list<string>}>
     */
    public static function citeste(SursaWordPress $s, Inventar $i): array
    {
        $postari = $i->pagini + $i->articole;
        if ($postari === []) {
            return [];
        }
        if (in_array('all-in-one-seo-pack', $i->pluginuri, true) || in_array('all-in-one-seo-pack-pro', $i->pluginuri, true)) {
            return self::aioseo($s, $i, $postari);
        }
        if (in_array('wordpress-seo', $i->pluginuri, true)) {
            return self::yoast($s, $i, $postari);
        }
        if (in_array('seo-by-rank-math', $i->pluginuri, true)) {
            return self::rankMath($s, $i, $postari);
        }
        $rez = [];
        foreach ($postari as $id => $p) {
            $rez[$id] = self::gol('');
        }
        return $rez;
    }

    /** @return array{titlu: string, descriere: string, canonical: string, robots: string, imagine: string, sursa: string, probleme: list<string>} */
    private static function gol(string $sursa): array
    {
        return ['titlu' => '', 'descriere' => '', 'canonical' => '', 'robots' => '', 'imagine' => '', 'sursa' => $sursa, 'probleme' => []];
    }

    /** @param array<int, array<string, mixed>> $postari */
    private static function aioseo(SursaWordPress $s, Inventar $i, array $postari): array
    {
        $optiuni = json_decode((string) $s->optiune('aioseo_options', ''), true);
        $dinamice = json_decode((string) $s->optiune('aioseo_options_dynamic', ''), true);
        $optiuni = is_array($optiuni) ? $optiuni : [];
        $dinamice = is_array($dinamice) ? $dinamice : [];
        $separator = html_entity_decode((string) ($optiuni['searchAppearance']['global']['separator'] ?? '-'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $randuri = [];
        if ($s->areTabel('aioseo_posts')) {
            foreach (array_chunk(array_keys($postari), 500) as $bucata) {
                foreach ($s->randuri('SELECT * FROM ' . $s->t('aioseo_posts') . ' WHERE post_id IN (' . implode(',', array_map('intval', $bucata)) . ')') as $r) {
                    $randuri[(int) $r['post_id']] = $r;
                }
            }
        }
        $poze = $s->meta(array_keys($postari), ['_thumbnail_id']);
        $rez = [];
        foreach ($postari as $id => $p) {
            $tip = (string) $p['tip'];
            $r = $randuri[$id] ?? [];
            $sablonTitlu = (string) ($dinamice['searchAppearance']['postTypes'][$tip]['title'] ?? '#post_title #separator_sa #site_title');
            $sablonDescriere = (string) ($dinamice['searchAppearance']['postTypes'][$tip]['metaDescription'] ?? '#post_excerpt');
            $o = self::gol('aioseo');
            $etichete = static function (string $text) use ($p, $i, $separator, &$o): string {
                return self::eticheteAioseo($text, $p, $i, $separator, $o);
            };
            $titlu = trim((string) ($r['title'] ?? ''));
            $o['titlu'] = $etichete($titlu !== '' ? $titlu : $sablonTitlu);
            $descriere = trim((string) ($r['description'] ?? ''));
            $o['descriere'] = $etichete($descriere !== '' ? $descriere : $sablonDescriere);
            $o['canonical'] = trim((string) ($r['canonical_url'] ?? ''));
            $implicitRobots = (string) ($r['robots_default'] ?? '1') !== '0';
            $noindex = !$implicitRobots && (string) ($r['robots_noindex'] ?? '0') === '1';
            $nofollow = !$implicitRobots && (string) ($r['robots_nofollow'] ?? '0') === '1';
            if (!$noindex && (bool) ($dinamice['searchAppearance']['postTypes'][$tip]['advanced']['robotsMeta']['default'] ?? true) === false
                && (bool) ($dinamice['searchAppearance']['postTypes'][$tip]['advanced']['robotsMeta']['noindex'] ?? false)) {
                $noindex = true;
            }
            $o['robots'] = $noindex ? ($nofollow ? 'noindex,nofollow' : 'noindex,follow') : '';
            $tipPoza = (string) ($r['og_image_type'] ?? 'default');
            if (trim((string) ($r['og_image_custom_url'] ?? '')) !== '') {
                $o['imagine'] = trim((string) $r['og_image_custom_url']);
            } elseif (in_array($tipPoza, ['default', 'featured'], true) && (int) ($poze[$id]['_thumbnail_id'] ?? 0) > 0) {
                $a = $i->atasamente[(int) $poze[$id]['_thumbnail_id']] ?? null;
                $o['imagine'] = $a !== null ? 'uploads:' . $a['fisier'] : '';
            }
            $rez[$id] = $o;
        }
        return $rez;
    }

    /** @param array<string, mixed> $o (probleme) */
    private static function eticheteAioseo(string $text, array $p, Inventar $i, string $separator, array &$o): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $rezumat = trim(strip_tags((string) $p['rezumat']));
        $luni = ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];
        $valori = [
            '#separator_sa' => $separator, '#site_title' => (string) $i->site['nume'], '#tagline' => (string) $i->site['descriere'],
            '#post_title' => (string) $p['titlu'], '#post_excerpt_only' => $rezumat, '#post_excerpt' => $rezumat, '#page_number' => '',
            '#current_year' => date('Y'), '#current_month' => $luni[(int) date('n') - 1], '#current_day' => date('j'),
            '#permalink' => rtrim((string) $i->site['url'], '/') . (string) ($p['cale'] ?? '/'),
        ];
        // Cele mai lungi întâi (#post_excerpt_only înaintea lui #post_excerpt).
        uksort($valori, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $text = strtr($text, $valori);
        if (preg_match_all('/#[a-z_]{3,}/', $text, $m) > 0) {
            foreach (array_unique($m[0]) as $necunoscuta) {
                $o['probleme'][] = 'eticheta AIOSEO ' . $necunoscuta . ' nu se poate calcula din bază (lăsată goală)';
                $text = str_replace($necunoscuta, '', $text);
            }
        }
        return self::curata($text, $separator);
    }

    /** Spațiile strânse și separatorii rămași singuri la capete (după o etichetă goală). */
    private static function curata(string $text, string $separator): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($separator !== '') {
            $q = preg_quote($separator, '/');
            $text = trim((string) preg_replace(['/^(' . $q . '\s*)+/u', '/(\s*' . $q . ')+$/u', '/(' . $q . '\s*){2,}/u'], ['', '', $separator . ' '], $text));
        }
        return $text;
    }

    /** @param array<int, array<string, mixed>> $postari */
    private static function yoast(SursaWordPress $s, Inventar $i, array $postari): array
    {
        $titluri = $s->optiune('wpseo_titles', []);
        $titluri = is_array($titluri) ? $titluri : [];
        $separatori = ['sc-dash' => '-', 'sc-ndash' => '–', 'sc-mdash' => '—', 'sc-middot' => '·', 'sc-bull' => '•', 'sc-star' => '*', 'sc-smstar' => '⋆',
            'sc-pipe' => '|', 'sc-tilde' => '~', 'sc-laquo' => '«', 'sc-raquo' => '»', 'sc-lt' => '<', 'sc-gt' => '>'];
        $separator = $separatori[(string) ($titluri['separator'] ?? 'sc-dash')] ?? '-';
        $meta = $s->meta(array_keys($postari), ['_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_canonical', '_yoast_wpseo_meta-robots-noindex',
            '_yoast_wpseo_meta-robots-nofollow', '_yoast_wpseo_opengraph-image']);
        $rez = [];
        foreach ($postari as $id => $p) {
            $m = $meta[$id] ?? [];
            $tip = (string) $p['tip'];
            $o = self::gol('yoast');
            $inlocuieste = static function (string $text) use ($p, $i, $separator, &$o): string {
                $text = strtr($text, ['%%title%%' => (string) $p['titlu'], '%%sitename%%' => (string) $i->site['nume'], '%%sitedesc%%' => (string) $i->site['descriere'],
                    '%%sep%%' => $separator, '%%excerpt%%' => trim(strip_tags((string) $p['rezumat'])), '%%page%%' => '', '%%currentyear%%' => date('Y')]);
                if (preg_match_all('/%%[a-z_]+%%/', $text, $mm) > 0) {
                    foreach (array_unique($mm[0]) as $x) {
                        $o['probleme'][] = 'variabila Yoast ' . $x . ' nu se poate calcula din bază (lăsată goală)';
                        $text = str_replace($x, '', $text);
                    }
                }
                return self::curata($text, $separator);
            };
            $o['titlu'] = $inlocuieste(trim((string) ($m['_yoast_wpseo_title'] ?? '')) ?: (string) ($titluri['title-' . $tip] ?? '%%title%% %%sep%% %%sitename%%'));
            $o['descriere'] = $inlocuieste(trim((string) ($m['_yoast_wpseo_metadesc'] ?? '')) ?: (string) ($titluri['metadesc-' . $tip] ?? ''));
            $o['canonical'] = trim((string) ($m['_yoast_wpseo_canonical'] ?? ''));
            $noindex = (string) ($m['_yoast_wpseo_meta-robots-noindex'] ?? '') === '1';
            $o['robots'] = $noindex ? ((string) ($m['_yoast_wpseo_meta-robots-nofollow'] ?? '') === '1' ? 'noindex,nofollow' : 'noindex,follow') : '';
            $o['imagine'] = trim((string) ($m['_yoast_wpseo_opengraph-image'] ?? ''));
            $rez[$id] = $o;
        }
        return $rez;
    }

    /** @param array<int, array<string, mixed>> $postari */
    private static function rankMath(SursaWordPress $s, Inventar $i, array $postari): array
    {
        $titluri = $s->optiune('rank-math-options-titles', []);
        $titluri = is_array($titluri) ? $titluri : [];
        $separator = html_entity_decode((string) ($titluri['title_separator'] ?? '-'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $meta = $s->meta(array_keys($postari), ['rank_math_title', 'rank_math_description', 'rank_math_canonical_url', 'rank_math_robots', 'rank_math_facebook_image']);
        $rez = [];
        foreach ($postari as $id => $p) {
            $m = $meta[$id] ?? [];
            $tip = (string) $p['tip'];
            $o = self::gol('rankmath');
            $inlocuieste = static function (string $text) use ($p, $i, $separator, &$o): string {
                $text = strtr($text, ['%title%' => (string) $p['titlu'], '%sitename%' => (string) $i->site['nume'], '%sitedesc%' => (string) $i->site['descriere'],
                    '%sep%' => $separator, '%excerpt%' => trim(strip_tags((string) $p['rezumat'])), '%page%' => '', '%currentyear%' => date('Y')]);
                if (preg_match_all('/%[a-z_]+%/', $text, $mm) > 0) {
                    foreach (array_unique($mm[0]) as $x) {
                        $o['probleme'][] = 'variabila Rank Math ' . $x . ' nu se poate calcula din bază (lăsată goală)';
                        $text = str_replace($x, '', $text);
                    }
                }
                return self::curata($text, $separator);
            };
            $o['titlu'] = $inlocuieste(trim((string) ($m['rank_math_title'] ?? '')) ?: (string) ($titluri['pt_' . $tip . '_title'] ?? '%title% %sep% %sitename%'));
            $o['descriere'] = $inlocuieste(trim((string) ($m['rank_math_description'] ?? '')) ?: (string) ($titluri['pt_' . $tip . '_description'] ?? ''));
            $o['canonical'] = trim((string) ($m['rank_math_canonical_url'] ?? ''));
            $robots = $m['rank_math_robots'] ?? [];
            $noindex = is_array($robots) && in_array('noindex', $robots, true);
            $o['robots'] = $noindex ? (in_array('nofollow', (array) $robots, true) ? 'noindex,nofollow' : 'noindex,follow') : '';
            $o['imagine'] = trim((string) ($m['rank_math_facebook_image'] ?? ''));
            $rez[$id] = $o;
        }
        return $rez;
    }
}
