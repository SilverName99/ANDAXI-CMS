<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress;

/**
 * Harta unui site WordPress pe ID-uri (corectura 1.8; tabelul `wp_harta` al stratului de adrese, App\Support\WpCompat):
 * fiecare pagină, articol și proiect publicat, fiecare atașament, fiecare categorie și fiecare autor al conținutului
 * publicat, cu id-ul WordPress, tipul, slug-ul în forma WordPress (`post_name` neatins), adresa pe care o scrie
 * WordPress (`Location`, canonical), starea, ordinea creării și, la atașamente, fișierul din wp-content/uploads.
 *
 * Adresele se calculează ca WordPress, din baza lui (fără să ruleze nimic):
 *  - pagina: calea părinților (get_page_uri), prima pagină `/`; articolul: structura permalinkurilor; proiectul:
 *    `/project/<slug>/` (tipul Divi);
 *  - atașamentul (get_attachment_link): legătura părintelui + slug-ul (`attachment/<slug>` dacă slug-ul e un număr sau
 *    structura are %category%); la o pagină părinte, calea ei chiar dacă e prima pagină (_get_page_link); fără părinte,
 *    `/<slug>/`; cu un părinte care nu se vede, `/?attachment_id=<id>`;
 *  - categoria: `/<category_base sau category>/<părinții>/<slug>/`; autorul: `/author/<user_nicename>/`.
 *
 * „/” final după structura permalinkurilor (user_trailingslashit); fără permalinkuri, adresele simple (`/?page_id=`).
 */
final class HartaWp
{
    /** Tipurile pe care WordPress le poate ghici (redirect_guess_404_permalink: publicate, vizibile). */
    public const TIPURI_GHICITE = ['page', 'post', 'project'];

    /** Tipurile hărții. */
    public const TIPURI = ['page', 'post', 'project', 'attachment', 'category', 'author'];

    /**
     * @return list<array{wp_id: int, tip: string, slug_wp: string, cale: string, stare: string, ordine: int, fisier: string}>
     */
    public static function dinInventar(Inventar $i): array
    {
        $structura = (string) ($i->site['permalinkuri'] ?? '');
        $slash = $i->slashFinal();
        $prima = (string) ($i->site['arata_pe_prima'] ?? '') === 'page' ? (int) ($i->site['prima_pagina'] ?? 0) : 0;
        $randuri = [];
        // Calea paginii fără prima pagină (_get_page_link), pentru atașamente.
        $caiPagini = [];
        foreach ($i->pagini as $id => $p) {
            // O ciornă (sau o pagină programată) are în WordPress doar adresa simplă (_get_page_link).
            $caiPagini[$id] = $structura === '' || in_array($p['stare'], ['draft', 'pending', 'future'], true) ? '/?page_id=' . $id
                : (string) ($p['cale_wp'] ?? $p['cale']);
            if ($p['stare'] === 'publish' || $p['stare'] === 'private') {
                $randuri[] = self::rand($id, 'page', (string) $p['slug'], $id === $prima ? '/' : $caiPagini[$id], (string) $p['stare'], '');
            }
        }
        $caiArticole = [];
        foreach ($i->articole as $id => $a) {
            $caiArticole[$id] = (string) ($a['cale_wp'] ?? $a['cale']);
            if ($a['stare'] === 'publish' || $a['stare'] === 'private') {
                $randuri[] = self::rand($id, 'post', (string) $a['slug'], $caiArticole[$id], (string) $a['stare'], '');
            }
        }
        $caiProiecte = [];
        foreach ($i->proiecte['proiecte'] as $id => $p) {
            $caiProiecte[$id] = $structura === '' ? '/?p=' . $id : Adrese::caleWordPress(['project', (string) $p['slug']], $slash);
            if ($p['stare'] === 'publish' || $p['stare'] === 'private') {
                $randuri[] = self::rand($id, 'project', (string) $p['slug'], $caiProiecte[$id], (string) $p['stare'], '');
            }
        }
        $caiAtasamente = [];
        $legatura = static function (int $id, array $vazute = []) use (&$legatura, &$caiAtasamente, $i, $caiPagini, $caiArticole, $caiProiecte,
            $structura, $slash): string {
            if (isset($caiAtasamente[$id])) {
                return $caiAtasamente[$id];
            }
            $a = $i->atasamente[$id];
            $parinte = (int) $a['parinte'];
            $simpla = '/?attachment_id=' . $id;
            if ($structura === '') {
                return $caiAtasamente[$id] = $simpla;
            }
            if ($parinte <= 0) {
                return $caiAtasamente[$id] = Adrese::caleWordPress([(string) $a['slug']], $slash);
            }
            $parinteCale = $caiPagini[$parinte] ?? $caiArticole[$parinte] ?? $caiProiecte[$parinte] ?? null;
            if ($parinteCale === null && isset($i->atasamente[$parinte]) && $parinte !== $id && !isset($vazute[$parinte])) {
                $parinteCale = $legatura($parinte, $vazute + [$id => true]);
            }
            if ($parinteCale === null || str_contains($parinteCale, '?')) {
                return $caiAtasamente[$id] = $simpla;
            }
            $nume = ctype_digit((string) $a['slug']) || str_contains($structura, '%category%') ? 'attachment/' . $a['slug'] : (string) $a['slug'];
            return $caiAtasamente[$id] = rtrim($parinteCale, '/') . '/' . Adrese::segmentWordPress($nume) . ($slash ? '/' : '');
        };
        foreach ($i->atasamente as $id => $a) {
            $randuri[] = self::rand($id, 'attachment', (string) $a['slug'], $legatura($id), 'inherit', (string) $a['fisier']);
        }
        $baza = (string) ($i->site['baza_categorii'] ?? '') !== '' ? (string) $i->site['baza_categorii'] : 'category';
        foreach ($i->categorii as $id => $c) {
            $parti = [(string) $c['slug']];
            $parinte = (int) $c['parinte'];
            $vazute = [$id => true];
            while ($parinte > 0 && isset($i->categorii[$parinte]) && !isset($vazute[$parinte])) {
                $vazute[$parinte] = true;
                array_unshift($parti, (string) $i->categorii[$parinte]['slug']);
                $parinte = (int) $i->categorii[$parinte]['parinte'];
            }
            $cale = $structura === '' ? '/?cat=' . $id : Adrese::caleWordPress(array_merge([$baza], $parti), $slash);
            $randuri[] = self::rand($id, 'category', (string) $c['slug'], $cale, 'publish', '');
        }
        foreach ($i->autori as $id => $u) {
            $cale = $structura === '' ? '/?author=' . $id : Adrese::caleWordPress(['author', (string) $u['slug']], $slash);
            $randuri[] = self::rand($id, 'author', (string) $u['slug'], $cale, 'publish', '');
        }
        return self::ordoneaza($randuri);
    }

    /**
     * Rândurile în ordinea creării: postările (pagini, articole, proiecte, atașamente: același șir de id-uri) după id,
     * apoi categoriile și autorii; `ordine` e poziția în acest șir.
     *
     * @param list<array{wp_id: int, tip: string, slug_wp: string, cale: string, stare: string, ordine: int, fisier: string}> $randuri
     * @return list<array{wp_id: int, tip: string, slug_wp: string, cale: string, stare: string, ordine: int, fisier: string}>
     */
    public static function ordoneaza(array $randuri): array
    {
        $grup = static fn (string $tip): int => in_array($tip, ['category', 'author'], true) ? ($tip === 'category' ? 1 : 2) : 0;
        usort($randuri, static fn (array $a, array $b): int => [$grup($a['tip']), $a['wp_id'], $a['tip']] <=> [$grup($b['tip']), $b['wp_id'], $b['tip']]);
        foreach ($randuri as $n => $r) {
            $randuri[$n]['ordine'] = $n + 1;
        }
        return $randuri;
    }

    /** @return array{wp_id: int, tip: string, slug_wp: string, cale: string, stare: string, ordine: int, fisier: string} */
    public static function rand(int $id, string $tip, string $slug, string $cale, string $stare, string $fisier): array
    {
        return ['wp_id' => $id, 'tip' => $tip, 'slug_wp' => $slug, 'cale' => $cale, 'stare' => $stare, 'ordine' => 0, 'fisier' => $fisier];
    }
}
