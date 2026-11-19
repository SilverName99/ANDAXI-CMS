<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\Captura;

use App\Support\ImportWordPress\Adrese;
use App\Support\ImportWordPress\Inventar;

/**
 * Lista adreselor capturii (pasul 4a; DECIZIE §2.3 #1, C2 §4a). Fiecare adresă are un fel:
 *  - `pagina`: paginile WordPress publicate (fără parolă), prima la `/`, fără cele scoase de pachet (`pagini.<slug>.importa: false`);
 *  - `postare`: celelalte postări publicate ale tipurilor publice știute de inventar: articolele și proiectele Divi (`project`),
 *    fără cele scoase de pachet (`articole.<slug>.importa: false`);
 *  - `arhiva`: arhivele lor: a articolelor (`/` când prima pagină e lista articolelor), categoriile cu articole, autorii,
 *    lunile și anii cu articole, arhiva proiectelor (`/project/`), fiecare și cu pagina 2 când are mai multe postări decât
 *    încap pe o pagină (`posts_per_page`);
 *  - `cautare`: căutarea fără rezultate (`/?s=<aleator>`), cu rezultate (`/?s=<un cuvânt dintr-un titlu>`) și pagina ei 2 când
 *    rezultatele nu încap pe una;
 *  - `404`: o adresă inexistentă, aleatoare la fiecare captură (`/<aleator>-inexistent/`);
 *  - `embed`: cardul `/<pagină>/embed/` al fiecărei pagini rămase (`/embed/` pentru prima pagină): devine pagină capturată;
 *  - `oembed`: `/wp-json/oembed/1.0/embed?url=<adresa paginii>` pentru fiecare pagină rămasă: răspuns înghețat;
 *  - `raspuns`: fișierele speciale (robots.txt, sitemap-urile cu XSL-ul lor, sitemap.rss, feed-urile): răspunsuri înghețate;
 *  - `scoasa`: fiecare postare scoasă de pachet, ca o vede live acum (oracolul „captura minus paginile scoase”, 9a);
 *  - `redirectionare`: adresele vechi pe care inventarul le știe (`_wp_old_slug`): live le redirecționează (301, neurmat).
 * Ce inventarul nu poate pune în listă e spus în avertismenteInventar(): tipurile de postări necunoscute, paginile atașamentelor.
 *
 * Pe server lista vine din inventarul WordPress (dinInventar, baza doar citită). Când captura se face din mediul nostru
 * (DECIZIE §2.3 #2: serverul nu-și poate cere propriul domeniu), lista vine dintr-un fișier JSON (dinFisier), scris din
 * inventar, în afara repo-ului: `{"adrese": [{"fel": "pagina", "cale": "/camere/"}, …]}` (`{aleator}` în cale devine o
 * valoare aleatoare; la `oembed`, calea e a paginii).
 */
final class ListaAdrese
{
    public const FELURI = ['pagina', 'postare', 'arhiva', 'cautare', '404', 'embed', 'oembed', 'raspuns', 'scoasa', 'redirectionare'];

    /** Felurile care sunt documente HTML ale site-ului nou (servite de cadru ca pagini capturate): comparate pe 6 UA × 2, cu resursele lor. */
    public const HTML = ['pagina', 'postare', 'arhiva', 'cautare', '404', 'embed'];

    /** Felurile normalizate ca documente HTML la comparație (și paginile scoase, fără resursele lor). */
    public const DOCUMENTE = ['pagina', 'postare', 'arhiva', 'cautare', '404', 'embed', 'scoasa'];

    /** Răspunsurile înghețate cerute de pe orice site (sub-sitemap-urile și XSL-ul le găsește captura în sitemap.xml). */
    public const RASPUNSURI = ['/robots.txt', '/sitemap.xml', '/sitemap.rss', '/feed/', '/comments/feed/'];

    /**
     * @param array<string, mixed> $reguli regulile pachetului (import-wordpress.json)
     * @return list<array{fel: string, cale: string, url: string, wp_id?: int}>
     */
    public static function dinInventar(Inventar $i, array $reguli, string $url, ?string $aleator = null): array
    {
        $baza = rtrim($url !== '' ? $url : (string) $i->site['url'], '/');
        $aleator ??= bin2hex(random_bytes(5));
        $slash = $i->slashFinal();
        $pePagina = max(1, (int) ($i->site['articole_pe_pagina'] ?? 10));
        $prima = $i->site['arata_pe_prima'] === 'page' ? (int) $i->site['prima_pagina'] : 0;
        $scoasa = static function (string $grup, string $slug) use ($reguli): bool {
            return is_array($reguli[$grup][$slug] ?? null) && ($reguli[$grup][$slug]['importa'] ?? true) === false;
        };
        $harta = [];
        foreach ($i->harta as $h) {
            $harta[$h['tip'] . ':' . $h['wp_id']] = $h['cale'];
        }
        $adrese = [];
        $pagini = [];
        $scoase = [];
        $redirectionari = [];
        foreach ($i->pagini as $id => $p) {
            if ($p['stare'] !== 'publish' || ($p['parola'] ?? false)) {
                continue;
            }
            $cale = $id === $prima ? '/' : (string) ($p['cale_wp'] ?? $p['cale']);
            if ($scoasa('pagini', (string) $p['slug'])) {
                $scoase[] = ['fel' => 'scoasa', 'cale' => $cale, 'wp_id' => (int) $id];
                continue;
            }
            $pagini[] = ['id' => (int) $id, 'cale' => $cale, 'ordine' => $id === $prima ? -1 : (int) $id];
            foreach ((array) ($p['sluguri_vechi'] ?? []) as $vechi) {
                $parinte = rtrim(dirname(rtrim((string) ($p['cale_wp'] ?? $p['cale']), '/')), '/');
                $redirectionari[] = ['fel' => 'redirectionare', 'cale' => ($parinte === '' || $parinte === '.' ? '' : $parinte) . '/' . Adrese::segmentWordPress((string) $vechi)
                    . ($slash ? '/' : ''), 'wp_id' => (int) $id];
            }
        }
        usort($pagini, static fn (array $a, array $b): int => $a['ordine'] <=> $b['ordine']);
        foreach ($pagini as $p) {
            $adrese[] = ['fel' => 'pagina', 'cale' => $p['cale'], 'wp_id' => $p['id']];
        }
        // Articolele și proiectele publicate, la adresa lor din harta WordPress (HartaWp).
        $articole = [];
        foreach ($i->articole as $id => $a) {
            if ($a['stare'] !== 'publish' || ($a['parola'] ?? false)) {
                continue;
            }
            $cale = (string) ($harta['post:' . $id] ?? $a['cale_wp'] ?? $a['cale']);
            if ($scoasa('articole', (string) $a['slug'])) {
                $scoase[] = ['fel' => 'scoasa', 'cale' => $cale, 'wp_id' => (int) $id];
                continue;
            }
            $articole[] = $a;
            $adrese[] = ['fel' => 'postare', 'cale' => $cale, 'wp_id' => (int) $id];
            foreach ((array) ($a['sluguri_vechi'] ?? []) as $vechi) {
                $redirectionari[] = ['fel' => 'redirectionare', 'cale' => Inventar::caleArticol(['slug' => (string) $vechi] + $a, (string) $i->site['permalinkuri'], true),
                    'wp_id' => (int) $id];
            }
        }
        $proiecte = array_filter($i->proiecte['proiecte'], static fn (array $p): bool => $p['stare'] === 'publish');
        foreach ($proiecte as $id => $p) {
            if (isset($harta['project:' . $id])) {
                $adrese[] = ['fel' => 'postare', 'cale' => (string) $harta['project:' . $id], 'wp_id' => (int) $id];
            }
        }
        // Arhivele (cu pagina 2 când nu încap pe una).
        $arhiva = static function (string $cale, int $numar) use (&$adrese, $pePagina, $slash): void {
            $adrese[] = ['fel' => 'arhiva', 'cale' => $cale];
            if ($numar > $pePagina) {
                $adrese[] = ['fel' => 'arhiva', 'cale' => rtrim($cale, '/') . '/page/2' . ($slash ? '/' : '')];
            }
        };
        if ($articole !== []) {
            if ($i->site['arata_pe_prima'] !== 'page') {
                $arhiva('/', count($articole));
            } elseif (($pa = (int) ($i->site['pagina_articole'] ?? 0)) > 0 && count($articole) > $pePagina && isset($harta['page:' . $pa])) {
                $adrese[] = ['fel' => 'arhiva', 'cale' => rtrim((string) $harta['page:' . $pa], '/') . '/page/2' . ($slash ? '/' : '')];
            }
            $luni = [];
            $ani = [];
            foreach ($articole as $a) {
                $t = strtotime((string) $a['data']) ?: 0;
                $luni[date('Y/m', $t)] = ($luni[date('Y/m', $t)] ?? 0) + 1;
                $ani[date('Y', $t)] = ($ani[date('Y', $t)] ?? 0) + 1;
            }
            foreach ($ani as $an => $n) {
                $arhiva('/' . $an . ($slash ? '/' : ''), $n);
            }
            foreach ($luni as $luna => $n) {
                $arhiva('/' . $luna . ($slash ? '/' : ''), $n);
            }
        }
        foreach ($i->categorii as $id => $c) {
            if ((int) ($c['numar'] ?? 0) > 0 && isset($harta['category:' . $id])) {
                $arhiva((string) $harta['category:' . $id], (int) $c['numar']);
            }
        }
        foreach ($i->autori as $id => $u) {
            if (isset($harta['author:' . $id])) {
                $arhiva((string) $harta['author:' . $id], count($articole));
            }
        }
        if ($proiecte !== []) {
            $arhiva('/project' . ($slash ? '/' : ''), count($proiecte));
        }
        // Căutarea: fără rezultate, cu rezultate (un cuvânt dintr-un titlu publicat) și pagina ei 2.
        $adrese[] = ['fel' => 'cautare', 'cale' => '/?s={aleator}'];
        $titluri = array_merge(array_column($articole, 'titlu'), array_map(static fn (array $p): string => (string) $i->pagini[$p['id']]['titlu'], $pagini));
        $cuvant = null;
        foreach ($titluri as $t) {
            if (preg_match('/\p{L}{4,}/u', (string) $t, $m) === 1) {
                $cuvant = mb_strtolower($m[0], 'UTF-8');
                break;
            }
        }
        if ($cuvant !== null) {
            $adrese[] = ['fel' => 'cautare', 'cale' => '/?s=' . rawurlencode($cuvant)];
            $gasite = 0;
            foreach (array_merge($articole, array_map(static fn (array $p): array => $i->pagini[$p['id']], $pagini)) as $x) {
                $gasite += mb_stripos((string) $x['titlu'] . ' ' . (string) ($x['continut'] ?? ''), $cuvant, 0, 'UTF-8') !== false ? 1 : 0;
            }
            if ($gasite > $pePagina) {
                $adrese[] = ['fel' => 'cautare', 'cale' => '/page/2/?s=' . rawurlencode($cuvant)];
            }
        }
        $adrese[] = ['fel' => '404', 'cale' => '/{aleator}-inexistent/'];
        foreach ($pagini as $p) {
            $adrese[] = ['fel' => 'embed', 'cale' => rtrim($p['cale'], '/') . '/embed/', 'wp_id' => $p['id']];
        }
        foreach ($pagini as $p) {
            $adrese[] = ['fel' => 'oembed', 'cale' => $p['cale'], 'wp_id' => $p['id']];
        }
        foreach (self::RASPUNSURI as $cale) {
            $adrese[] = ['fel' => 'raspuns', 'cale' => $cale];
        }
        foreach (array_merge($scoase, $redirectionari) as $x) {
            $adrese[] = $x;
        }
        return self::completeaza($adrese, $baza, $aleator);
    }

    /**
     * Ce nu poate intra în lista din inventar, de spus în plan (ATENȚIE): tipurile de postări pe care inventarul nu le știe
     * (deci adresele lor publice nu sunt capturate) și paginile atașamentelor (le tratează stratul de adrese, pasul 2).
     *
     * @return list<string>
     */
    public static function avertismenteInventar(Inventar $i): array
    {
        $rez = [];
        foreach ($i->altele as $cheie => $n) {
            if (str_starts_with((string) $cheie, 'tip:') && (int) $n > 0 && $cheie !== 'tip:project') {
                $rez[] = 'tipul de postări „' . substr((string) $cheie, 4) . '” (' . $n . ') nu e știut de inventar: adresele lui publice nu sunt în lista capturii';
            }
        }
        if (($i->site['pagini_atasamente'] ?? false) && $i->atasamente !== []) {
            $rez[] = 'paginile atașamentelor (' . count($i->atasamente) . ') nu sunt în lista capturii: le tratează stratul de adrese (pasul 2)';
        }
        return $rez;
    }

    /** @return list<array{fel: string, cale: string, url: string}> */
    public static function dinFisier(string $fisier, string $url, ?string $aleator = null): array
    {
        $date = json_decode((string) @file_get_contents($fisier), true);
        if (!is_array($date) || !is_array($date['adrese'] ?? null)) {
            throw new EroareCaptura('folosire', 'lista adreselor ' . $fisier . ' nu e de forma {"adrese": [{"fel": …, "cale": …}]}');
        }
        $adrese = [];
        foreach ($date['adrese'] as $n => $a) {
            if (!is_array($a) || !in_array($a['fel'] ?? null, self::FELURI, true) || !is_string($a['cale'] ?? null) || !str_starts_with($a['cale'], '/')
                || str_starts_with($a['cale'], '//')) {
                throw new EroareCaptura('folosire', 'lista adreselor, rândul ' . $n . ': {"fel": ' . implode('|', self::FELURI) . ', "cale": "/…"}');
            }
            $adrese[] = ['fel' => $a['fel'], 'cale' => $a['cale']];
        }
        return self::completeaza($adrese, rtrim($url, '/'), $aleator);
    }

    /**
     * @param list<array{fel: string, cale: string, url: string}> $adrese
     * @param list<string> $feluri
     * @return list<array{fel: string, cale: string, url: string}>
     */
    public static function filtreaza(array $adrese, array $feluri): array
    {
        return array_values(array_filter($adrese, static fn (array $a): bool => in_array($a['fel'], $feluri, true)));
    }

    /**
     * @param list<array<string, mixed>> $adrese
     * @return list<array{fel: string, cale: string, url: string}>
     */
    private static function completeaza(array $adrese, string $baza, ?string $aleator): array
    {
        if (preg_match('#^https?://[^/?\#]+$#i', $baza) !== 1) {
            throw new EroareCaptura('folosire', 'adresa site-ului trebuie să fie de forma https://<gazdă>: ' . $baza);
        }
        $aleator ??= bin2hex(random_bytes(5));
        $rez = [];
        $vazute = [];
        foreach ($adrese as $a) {
            $cale = str_replace('{aleator}', $aleator, (string) $a['cale']);
            $url = $a['fel'] === 'oembed'
                ? $baza . '/wp-json/oembed/1.0/embed?url=' . rawurlencode($baza . $cale)
                : $baza . $cale;
            if (isset($vazute[$url])) {
                continue;
            }
            $vazute[$url] = true;
            $rez[] = ['fel' => (string) $a['fel'], 'cale' => $cale, 'url' => $url] + (isset($a['wp_id']) ? ['wp_id' => (int) $a['wp_id']] : []);
        }
        return $rez;
    }
}
