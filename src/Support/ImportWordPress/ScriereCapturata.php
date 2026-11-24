<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress;

use App\Support\CadruCapturat;
use App\Support\CadruCapturat\ContextRegiune;
use App\Support\CadruCapturat\Regiuni;
use App\Support\HtmlCapturat;
use App\Support\ImportWordPress\Captura\Normalizare;
use App\Support\ImportWordPress\Continut\Taiere;
use PDO;
use Throwable;

/**
 * Scrierea capturată (drumul capturat, pasul 5a; DECIZIE §2.3 #11): partea capturată a planului (PlanCapturat) în tabelele
 * migrării nucleu/0070 — `pagini_capturate`, `fragmente_capturate`, `raspunsuri_inghetate`, `formulare_compat`,
 * `galerii_capturate` (cu elementele) —, în tranzacția importului (Importator), după paginile din `pages`.
 *
 *  - **hash_import** pe fiecare rând: amprenta conținutului scris. La un reimport, un rând al cărui conținut de acum are altă
 *    amprentă a fost editat de om: conflicte() îl dă, iar importul îl refuză fără `--suprascrie` (corectura 1.9);
 *  - rândurile capturii care nu mai sunt în plan se șterg (doar cele needitate: celelalte sunt conflicte);
 *  - **P1 la import:** fiecare document se randează din rândurile abia scrise (CadruCapturat, pe aceeași conexiune), cu valorile
 *    de la import, și se compară cu T (documentul servit, cu scripturile runtime-ului la locul lor), după normalizarea de comparație
 *    (nonce-urile: §6.7 #1). O diferență = EroareImport: tranzacția se anulează, nimic scris. `sha_randare` e amprenta aprobată.
 *  - **regiunile dinamice** (pista A; nucleu/0072, `regiuni_capturate`): octeții capturați ai fiecărei regiuni (cu sha256-ul lor și
 *    starea capturii), rescriși întregi la fiecare import (nu se editează de mână). P1 le pune la loc pe octeții capturați; apoi
 *    **dovada fiecărei regiuni**: randatorul ei, cu starea capturii, dă aceiași octeți după normalizarea declarată (Regiuni::dovada),
 *    iar documentul cu ieșirea lui nu are nicio problemă de politică (HtmlCapturat) în plus față de captură; altfel EroareImport.
 */
final class ScriereCapturata
{
    public const TABELE = ['pagini_capturate', 'fragmente_capturate', 'raspunsuri_inghetate', 'formulare_compat', 'galerii_capturate', 'galerii_capturate_elemente',
        'regiuni_capturate'];

    /** Coloanele unui document acoperite de amprenta lui. */
    public const COLOANE_DOC = ['tip', 'page_id', 'cale', 'status', 'html_atribute', 'cap_html', 'body_atribute', 'inainte', 'continut', 'dupa', 'antet', 'subsol',
        'banner', 'date_pagina', 'date_reteta', 'meniu', 'valori_import', 'sectiuni', 'seo', 'seo_motiv', 'antete_http', 'corpus_cautare'];

    public const COLOANE_FRAGMENT = ['fel', 'layout', 'html', 'date', 'meniu'];
    public const COLOANE_RASPUNS = ['metoda', 'cale', 'query', 'status', 'antete', 'corp'];
    public const COLOANE_FORMULAR = ['pagina_capturata', 'fragment', 'tip', 'indice', 'config'];

    public static function disponibil(PDO $db): bool
    {
        try {
            foreach (self::TABELE as $t) {
                $db->query('SELECT 1 FROM ' . $t . ' LIMIT 1');
            }
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Amprenta unui rând: sha256 peste valorile coloanelor lui, în ordine. @param array<string, mixed> $r @param list<string> $coloane */
    public static function hash(array $r, array $coloane): string
    {
        return hash('sha256', (string) json_encode(array_map(static fn (string $c): ?string => $r[$c] === null ? null : (string) $r[$c], $coloane),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** Amprenta unei galerii, cu elementele ei. @param list<array<string, mixed>> $elemente */
    public static function hashGalerie(?string $setari, array $elemente): string
    {
        return hash('sha256', (string) json_encode([$setari, array_map(static fn (array $e): array => [(int) $e['ordine'], (string) $e['fisier'], $e['categorii'] === null ? null
            : (string) $e['categorii'], (string) $e['titlu'], (string) $e['alt']], $elemente)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Rândurile capturii editate de om de la importul de dinainte (amprenta de acum ≠ hash_import), pe care planul le-ar scrie peste
     * sau le-ar șterge. Doar citire; cu `$blocheaza` (într-o tranzacție) rândurile se citesc cu FOR UPDATE.
     *
     * @return list<array{fel: string, cheie: string, motiv: string}>
     */
    public static function conflicte(PDO $db, bool $blocheaza = false): array
    {
        if (!self::disponibil($db)) {
            return [];
        }
        $b = $blocheaza ? ' FOR UPDATE' : '';
        $rez = [];
        foreach ($db->query('SELECT cheie, hash_import, ' . implode(', ', self::COLOANE_DOC) . ' FROM pagini_capturate' . $b)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (self::hash($r, self::COLOANE_DOC) !== $r['hash_import']) {
                $rez[] = ['fel' => 'pagina_capturata', 'cheie' => (string) $r['cheie'], 'motiv' => 'documentul capturat „' . $r['cheie'] . '” e editat după import'];
            }
        }
        foreach ($db->query('SELECT cheie, hash_import, ' . implode(', ', self::COLOANE_FRAGMENT) . ' FROM fragmente_capturate' . $b)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (self::hash($r, self::COLOANE_FRAGMENT) !== $r['hash_import']) {
                $rez[] = ['fel' => 'fragment', 'cheie' => (string) $r['cheie'], 'motiv' => 'fragmentul „' . $r['cheie'] . '” e editat după import'];
            }
        }
        foreach ($db->query('SELECT cheie, hash_import, ' . implode(', ', self::COLOANE_RASPUNS) . ' FROM raspunsuri_inghetate' . $b)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (self::hash($r, self::COLOANE_RASPUNS) !== $r['hash_import']) {
                $rez[] = ['fel' => 'raspuns', 'cheie' => (string) $r['cheie'], 'motiv' => 'răspunsul înghețat ' . $r['cale'] . ' e editat după import'];
            }
        }
        foreach ($db->query('SELECT cheie, hash_import, ' . implode(', ', self::COLOANE_FORMULAR) . ' FROM formulare_compat' . $b)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (self::hash($r, self::COLOANE_FORMULAR) !== $r['hash_import']) {
                $rez[] = ['fel' => 'formular', 'cheie' => (string) $r['cheie'], 'motiv' => 'formularul „' . $r['cheie'] . '” e editat după import'];
            }
        }
        $el = $db->prepare('SELECT ordine, fisier, categorii, titlu, alt FROM galerii_capturate_elemente WHERE galerie_id = ? ORDER BY ordine, id');
        foreach ($db->query('SELECT id, cheie, setari, hash_import FROM galerii_capturate' . $b)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $el->execute([(int) $r['id']]);
            if (self::hashGalerie($r['setari'], $el->fetchAll(PDO::FETCH_ASSOC)) !== $r['hash_import']) {
                $rez[] = ['fel' => 'galerie', 'cheie' => (string) $r['cheie'], 'motiv' => 'galeria „' . $r['cheie'] . '” e editată după import'];
            }
        }
        return $rez;
    }

    /**
     * Scrie partea capturată (în tranzacția deschisă de Importator) și face P1 la import.
     *
     * @param array<string, int> $paginiId cheia documentului unei pagini (`pagina:<wp_id>`) → `pages.id`
     * @param array{cap?: string, final?: string} $runtime scripturile runtime-ului (CadruCapturat::scripturiRuntime)
     * @return array<string, int> câte rânduri s-au scris și s-au șters
     * @throws EroareImport P1 la import: o randare diferită de T
     */
    public static function scrie(PDO $db, PlanCapturat $pc, array $paginiId, array $runtime): array
    {
        $acum = date('Y-m-d H:i:s');
        $j = static fn (mixed $v): ?string => $v === null || $v === [] ? null : (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR);
        $jd = static fn (mixed $v): ?string => $v === null ? null : CadruCapturat::json((array) $v);
        $rez = ['documente' => 0, 'fragmente' => 0, 'raspunsuri' => 0, 'formulare' => 0, 'galerii' => 0, 'sterse' => 0];
        // Ce nu mai e în plan pleacă (conflictele — rândurile editate — le-a oprit deja Importator).
        $sterge = static function (string $tabel, string $coloana, array $chei) use ($db, &$rez): void {
            foreach ($db->query('SELECT ' . $coloana . ' FROM ' . $tabel)->fetchAll(PDO::FETCH_COLUMN) as $c) {
                if (!in_array((string) $c, $chei, true)) {
                    $db->prepare('DELETE FROM ' . $tabel . ' WHERE ' . $coloana . ' = ?')->execute([$c]);
                    $rez['sterse']++;
                }
            }
        };
        $sterge('pagini_capturate', 'cheie', array_map('strval', array_keys($pc->documente)));
        $sterge('fragmente_capturate', 'cheie', array_map('strval', array_keys($pc->fragmente)));
        $cheiRaspunsuri = array_map(static fn (array $r): string => self::cheieRaspuns($r['metoda'], $r['cale'], $r['query']), $pc->raspunsuri);
        $sterge('raspunsuri_inghetate', 'cheie', $cheiRaspunsuri);
        $sterge('formulare_compat', 'cheie', array_map('strval', array_keys($pc->formulare)));
        foreach ($db->query('SELECT id, cheie FROM galerii_capturate')->fetchAll(PDO::FETCH_ASSOC) as $g) {
            if (!isset($pc->galerii[(string) $g['cheie']])) {
                $db->prepare('DELETE FROM galerii_capturate_elemente WHERE galerie_id = ?')->execute([(int) $g['id']]);
                $db->prepare('DELETE FROM galerii_capturate WHERE id = ?')->execute([(int) $g['id']]);
                $rez['sterse']++;
            }
        }
        foreach ($pc->fragmente as $k => $f) {
            $k = (string) $k;
            $r = ['fel' => $f['fel'], 'layout' => $f['layout'], 'html' => $f['html'], 'date' => $f['date'] === [] ? null : $jd($f['date']), 'meniu' => $j($f['meniu'])];
            $db->prepare('INSERT INTO fragmente_capturate (cheie, fel, layout, html, date, meniu, sha, hash_import, importat_la) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE fel = VALUES(fel), layout = VALUES(layout), html = VALUES(html), date = VALUES(date), meniu = VALUES(meniu), sha = VALUES(sha),
                hash_import = VALUES(hash_import), importat_la = VALUES(importat_la)')
                ->execute([$k, $r['fel'], $r['layout'], $r['html'], $r['date'], $r['meniu'], $f['sha'], self::hash($r, self::COLOANE_FRAGMENT), $acum]);
            $rez['fragmente']++;
        }
        foreach ($pc->documente as $k => $doc) {
            $k = (string) $k; // o cheie ca „404” devine întreg în PHP
            /** @var Taiere $t */
            $t = $doc['taiere'];
            $pagina = $doc['tip'] === 'pagina' ? ($paginiId[$k] ?? null) : null;
            if ($doc['tip'] === 'pagina' && $pagina === null) {
                throw new EroareImport('documentul capturat ' . $k . ' n-are pagina lui în `pages` (eroare internă)');
            }
            $valoriImport = array_filter(['cale' => $t->valori['cale'] ?? ($doc['valori_import']['cale'] ?? null), 'an' => $t->valori['an'] ?? null,
                // Jetoanele documentului din regulile regiunilor (pista A): clasele corpului și titlul, cum le arată captura.
                'clase_body' => $t->valori['clase_body'] ?? null, 'titlu' => $t->valori['titlu'] ?? null,
                'seo' => array_filter(['titlu' => $t->valori['seo.titlu'] ?? null, 'descriere' => $t->valori['seo.descriere'] ?? null,
                    'canonical' => $t->valori['seo.canonical'] ?? null, 'robots' => $t->valori['seo.robots'] ?? null, 'imagine' => $t->valori['seo.imagine'] ?? null],
                    static fn (mixed $v): bool => $v !== null)], static fn (mixed $v): bool => $v !== null && $v !== []);
            $r = ['tip' => $doc['tip'], 'page_id' => $pagina, 'cale' => $doc['cale'] ?? null, 'status' => (int) ($doc['status'] ?? 200), 'html_atribute' => $t->htmlAtribute,
                'cap_html' => $t->cap, 'body_atribute' => $t->bodyAtribute, 'inainte' => $t->inainte, 'continut' => $doc['tip'] === 'pagina' ? null : $t->continut,
                'dupa' => $t->dupa, 'antet' => $doc['antet'] ?? null, 'subsol' => $doc['subsol'] ?? null, 'banner' => $doc['banner'] ?? null,
                'date_pagina' => $t->date !== null ? $jd($t->date['pagina']) : null, 'date_reteta' => $t->date !== null ? $j($t->date['reteta']) : null,
                'meniu' => $j($doc['meniu'] ?? null), 'valori_import' => $j($valoriImport), 'sectiuni' => $j($t->sectiuni), 'seo' => $t->seo, 'seo_motiv' => $t->seoMotiv,
                'antete_http' => $j($doc['antete'] ?? null), 'corpus_cautare' => $doc['corpus'] ?? null];
            $db->prepare('INSERT INTO pagini_capturate (cheie, tip, page_id, cale, cale_hash, wp_id, dialect, status, html_atribute, cap_html, body_atribute, inainte, continut,
                    dupa, antet, subsol, banner, date_pagina, date_reteta, meniu, valori_import, sectiuni, seo, seo_motiv, antete_http, corpus_cautare, sha_captura,
                    sha_randare, hash_import, wp_modificat, importat_la)
                VALUES (:cheie, :tip, :page_id, :cale, :cale_hash, :wp_id, :dialect, :status, :html_atribute, :cap_html, :body_atribute, :inainte, :continut, :dupa,
                    :antet, :subsol, :banner, :date_pagina, :date_reteta, :meniu, :valori_import, :sectiuni, :seo, :seo_motiv, :antete_http, :corpus_cautare, :sha_captura,
                    :sha_randare, :hash_import, :wp_modificat, :importat_la)
                ON DUPLICATE KEY UPDATE tip = VALUES(tip), page_id = VALUES(page_id), cale = VALUES(cale), cale_hash = VALUES(cale_hash), wp_id = VALUES(wp_id),
                    dialect = VALUES(dialect), status = VALUES(status), html_atribute = VALUES(html_atribute), cap_html = VALUES(cap_html),
                    body_atribute = VALUES(body_atribute), inainte = VALUES(inainte), continut = VALUES(continut), dupa = VALUES(dupa), antet = VALUES(antet),
                    subsol = VALUES(subsol), banner = VALUES(banner), date_pagina = VALUES(date_pagina), date_reteta = VALUES(date_reteta), meniu = VALUES(meniu),
                    valori_import = VALUES(valori_import), sectiuni = VALUES(sectiuni), seo = VALUES(seo), seo_motiv = VALUES(seo_motiv), antete_http = VALUES(antete_http),
                    corpus_cautare = VALUES(corpus_cautare), sha_captura = VALUES(sha_captura), sha_randare = VALUES(sha_randare), hash_import = VALUES(hash_import),
                    editat_la = NULL, wp_modificat = VALUES(wp_modificat), importat_la = VALUES(importat_la)')
                ->execute($r + ['cheie' => $k, 'cale_hash' => ($doc['cale'] ?? null) !== null ? hash('sha256', (string) $doc['cale']) : null, 'wp_id' => $doc['wp_id'] ?? null,
                    'dialect' => 'divi4', 'sha_captura' => (string) ($doc['sha_captura'] ?? ''), 'sha_randare' => str_repeat('0', 64),
                    'hash_import' => self::hash($r, self::COLOANE_DOC), 'wp_modificat' => $doc['wp_modificat'] ?? null, 'importat_la' => $acum]);
            $rez['documente']++;
        }
        foreach ($pc->raspunsuri as $i => $x) {
            $r = ['metoda' => $x['metoda'], 'cale' => $x['cale'], 'query' => $x['query'], 'status' => (int) $x['status'], 'antete' => (string) $j($x['antete'] ?: []) ?: '[]',
                'corp' => $x['corp']];
            $db->prepare('INSERT INTO raspunsuri_inghetate (cheie, metoda, cale, query, status, antete, corp, sha256, sursa, hash_import, importat_la)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE metoda = VALUES(metoda), cale = VALUES(cale), query = VALUES(query), status = VALUES(status),
                antete = VALUES(antete), corp = VALUES(corp), sha256 = VALUES(sha256), sursa = VALUES(sursa), hash_import = VALUES(hash_import), importat_la = VALUES(importat_la)')
                ->execute([$cheiRaspunsuri[$i], $r['metoda'], $r['cale'], $r['query'], $r['status'], $r['antete'], $r['corp'], hash('sha256', $r['corp']), $x['sursa'],
                    self::hash($r, self::COLOANE_RASPUNS), $acum]);
            $rez['raspunsuri']++;
        }
        foreach ($pc->formulare as $k => $f) {
            $k = (string) $k;
            $r = ['pagina_capturata' => $f['pagina_capturata'], 'fragment' => $f['fragment'], 'tip' => 'divi', 'indice' => (int) $f['indice'], 'config' => (string) $j($f['config'])];
            $db->prepare('INSERT INTO formulare_compat (cheie, pagina_capturata, fragment, tip, indice, config, hash_import, importat_la) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE pagina_capturata = VALUES(pagina_capturata), fragment = VALUES(fragment), tip = VALUES(tip), indice = VALUES(indice),
                config = VALUES(config), hash_import = VALUES(hash_import), importat_la = VALUES(importat_la)')
                ->execute([$k, $r['pagina_capturata'], $r['fragment'], $r['tip'], $r['indice'], $r['config'], self::hash($r, self::COLOANE_FORMULAR), $acum]);
            $rez['formulare']++;
        }
        foreach ($pc->galerii as $k => $g) {
            $k = (string) $k;
            $setari = $j($g['setari']);
            $elemente = [];
            foreach (array_values($g['elemente']) as $n => $e) {
                $elemente[] = ['ordine' => $n, 'fisier' => mb_substr((string) $e['fisier'], 0, 760), 'categorii' => $j($e['categorii'] ?: null),
                    'titlu' => mb_substr((string) $e['titlu'], 0, 500), 'alt' => mb_substr((string) $e['alt'], 0, 500)];
            }
            $q = $db->prepare('SELECT id FROM galerii_capturate WHERE cheie = ?');
            $q->execute([$k]);
            $id = (int) ($q->fetchColumn() ?: 0);
            if ($id > 0) {
                $db->prepare('UPDATE galerii_capturate SET pagina_capturata = ?, modul = ?, setari = ?, hash_import = ?, importat_la = ? WHERE id = ?')
                    ->execute([$g['pagina_capturata'], $g['modul'], $setari, self::hashGalerie($setari, $elemente), $acum, $id]);
                $db->prepare('DELETE FROM galerii_capturate_elemente WHERE galerie_id = ?')->execute([$id]);
            } else {
                $db->prepare('INSERT INTO galerii_capturate (cheie, pagina_capturata, modul, setari, hash_import, importat_la) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$k, $g['pagina_capturata'], $g['modul'], $setari, self::hashGalerie($setari, $elemente), $acum]);
                $id = (int) $db->lastInsertId();
            }
            $ad = $db->prepare('INSERT INTO galerii_capturate_elemente (galerie_id, ordine, fisier, categorii, titlu, alt) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($elemente as $e) {
                $ad->execute([$id, $e['ordine'], $e['fisier'], $e['categorii'], $e['titlu'], $e['alt']]);
            }
            $rez['galerii']++;
        }
        // Regiunile dinamice (pista A): octeții capturați ai fiecăreia, cu starea capturii; rescrise întregi (nu se editează de mână).
        $db->exec('DELETE FROM regiuni_capturate');
        $rez['regiuni'] = 0;
        $insRegiune = $db->prepare('INSERT INTO regiuni_capturate (document, regiune, n, fel, atribut, octeti, sha256, stare, importat_la)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($pc->documente as $k => $doc) {
            foreach ($doc['taiere']->regiuni as $r) {
                $insRegiune->execute([(string) $k, $r['regiune'], $r['n'], $r['mod'], $r['atribut'], $r['octeti'], hash('sha256', $r['octeti']),
                    $r['stare'] === null ? null : $j($r['stare']), $acum]);
                $rez['regiuni']++;
            }
        }
        // P1 la import: fiecare document, randat din rândurile abia scrise, = T cu scripturile runtime-ului la locul lor.
        $norm = new Normalizare($pc->url !== '' ? $pc->url : 'https://site.invalid');
        foreach ($pc->documente as $k => $doc) {
            $k = (string) $k;
            $randare = self::randeazaDinBaza($db, $k, $runtime, null);
            $asteptat = self::asteptat($doc['taiere'], $runtime);
            $na = $norm->document($asteptat);
            if ($norm->document($randare) !== $na) {
                throw new EroareImport('P1 la import: documentul ' . $k . ' randat din bază diferă de T' . self::diferenta($na, $norm->document($randare)) . '; nimic scris');
            }
            // Amprenta aprobată: a randării cu valorile de la import (deterministă, deci o refac la fel P1 de după import,
            // scripts/verifica-randare.php, fără captură și fără normalizare).
            $db->prepare('UPDATE pagini_capturate SET sha_randare = ? WHERE cheie = ?')->execute([hash('sha256', $randare), $k]);
            // Dovada regiunilor documentului: randatorul fiecăreia, cu starea capturii.
            $pb = self::dovadaRegiuni($db, $k, $doc, $asteptat, $runtime, $norm, $pc->politica);
            if ($pb !== null) {
                throw new EroareImport('dovada regiunilor: documentul ' . $k . ': ' . $pb . '; nimic scris');
            }
        }
        return $rez;
    }

    /**
     * Dovada regiunilor unui document (pista A): pentru fiecare regiune, randatorul ei (Regiuni::dovada), cu starea capturii
     * (ContextRegiune la dovadă), dă octeții capturați după normalizarea declarată; documentul așteptat cu ieșirea lui în locul
     * octeților capturați nu are nicio problemă HtmlCapturat în plus față de documentul capturat. null = dovedite; altfel motivul.
     *
     * @param array<string, mixed> $doc documentul din plan (cu `taiere`)
     * @param string $asteptat documentul așteptat (asteptat(): T cu scripturile runtime-ului `$runtime` la ancorele lor)
     * @param array{cap?: string, final?: string} $runtime
     */
    public static function dovadaRegiuni(PDO $db, string $cheie, array $doc, string $asteptat, array $runtime, Normalizare $norm,
        ?HtmlCapturat\ContextCapturat $politica): ?string
    {
        /** @var Taiere $t */
        $t = $doc['taiere'];
        if ($t->regiuni === []) {
            return null;
        }
        $cale = (string) ($doc['cale'] ?? ($doc['valori_import']['cale'] ?? '/'));
        $problemeCaptura = null;
        foreach ($t->regiuni as $r) {
            $id = (string) $r['regiune'];
            $ctx = (new ContextRegiune($db, $cheie, $cale, [], 'GET', is_array($r['stare']) ? $r['stare'] : []))
                ->pentru($id, (int) $r['n'], Regiuni::cache($id), (string) $r['mod'], isset($r['atribut']) ? (string) $r['atribut'] : null);
            $pb = Regiuni::dovada($id, (string) $r['octeti'], $ctx, $norm);
            if ($pb !== null) {
                return 'regiunea ' . $id . ':' . $r['n'] . ': ' . $pb;
            }
            // Politica pe ieșirea randatorului, în documentul ei: nicio problemă în plus față de captură.
            $iesire = (string) Regiuni::randator($id)?->randeaza($ctx);
            if ($r['mod'] === 'atribut' && !Regiuni::valoareAtributSigura($iesire)) {
                return 'regiunea ' . $id . ':' . $r['n'] . ': ieșirea randatorului nu se poate pune între ghilimelele atributului ' . $r['atribut'];
            }
            // Poziția regiunii în documentul așteptat: cea din T, plus scripturile runtime-ului puse înaintea ei.
            $poz = (int) $r['start'];
            foreach ($t->ancore as $loc => $ancora) {
                if ($ancora <= (int) $r['start']) {
                    $poz += strlen((string) ($runtime[$loc] ?? ''));
                }
            }
            if (substr($asteptat, $poz, strlen((string) $r['octeti'])) !== (string) $r['octeti']) {
                return 'regiunea ' . $id . ':' . $r['n'] . ': octeții capturați nu sunt la locul lor în documentul așteptat (eroare internă)';
            }
            $problemeCaptura ??= self::numaraProbleme(HtmlCapturat::verifica($asteptat, $politica, 'import'));
            $cuIesire = substr($asteptat, 0, $poz) . $iesire . substr($asteptat, $poz + strlen((string) $r['octeti']));
            $inPlus = self::numaraProbleme(HtmlCapturat::verifica($cuIesire, $politica, 'import'));
            foreach ($inPlus as $k => $n) {
                if ($n > ($problemeCaptura[$k] ?? 0)) {
                    return 'regiunea ' . $id . ':' . $r['n'] . ': ieșirea randatorului are o problemă de politică (HtmlCapturat): ' . explode("\0", $k)[1];
                }
            }
        }
        return null;
    }

    /** Problemele politicii numărate pe cod și mesaj. @param list<HtmlCapturat\Problema> $pb @return array<string, int> */
    private static function numaraProbleme(array $pb): array
    {
        $rez = [];
        foreach ($pb as $p) {
            $rez[$p->cod . "\0" . $p->mesaj] = ($rez[$p->cod . "\0" . $p->mesaj] ?? 0) + 1;
        }
        return $rez;
    }

    /**
     * Unde diferă două documente, pentru mesajul P1: octetul primei diferențe, lungimile și câte un fragment scurt din fiecare
     * (de la 30 de octeți înainte; caracterele de control ca „?”, rândurile noi ca „⏎”), ca diferența să se vadă din mesaj.
     */
    public static function diferenta(string $asteptat, string $randat): string
    {
        $dif = Normalizare::primaDiferenta($asteptat, $randat);
        if ($dif === null) {
            return '';
        }
        $arata = static fn (string $s): string => '„' . str_replace(["\r\n", "\n", "\r", "\t"], ['⏎', '⏎', '⏎', ' '], $s) . '”';
        return ' (prima diferență la octetul ' . $dif['octet'] . ' din ' . strlen($asteptat) . ' așteptați / ' . strlen($randat) . ' randați; T: '
            . $arata($dif['a']) . '; randat: ' . $arata($dif['b']) . ')';
    }

    /** T cu scripturile runtime-ului puse la ancorele lor (ce trebuie să dea randarea). @param array{cap?: string, final?: string} $runtime */
    public static function asteptat(Taiere $t, array $runtime): string
    {
        $doc = $t->html;
        $ancore = $t->ancore;
        arsort($ancore);
        foreach ($ancore as $loc => $poz) {
            $doc = substr($doc, 0, $poz) . (string) ($runtime[$loc] ?? '') . substr($doc, $poz);
        }
        return $doc;
    }

    /**
     * Un document capturat randat din bază (P1, cadrul): bucățile, fragmentele lui, conținutul paginii; cu valorile cererii date
     * sau, fără ele (null), cu valorile de la import (calea și anul capturii, SEO-ul de atunci).
     *
     * @param array{cap?: string, final?: string} $runtime
     * @param array{cale: string, nonce: string, an: string, seo?: array<string, string>}|null $cerere
     */
    public static function randeazaDinBaza(PDO $db, string $cheie, array $runtime, ?array $cerere): string
    {
        $q = $db->prepare('SELECT * FROM pagini_capturate WHERE cheie = ?');
        $q->execute([$cheie]);
        $doc = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($doc)) {
            throw new EroareImport('documentul capturat ' . $cheie . ' lipsește din bază');
        }
        return CadruCapturat::randeazaRand($db, $doc, $cerere ?? self::cerereImport($doc), $runtime);
    }

    /**
     * Valorile de la import ale unui document (P1 la import și scripts/verifica-randare.php): calea și anul capturii, SEO-ul de
     * atunci, fără adaosurile răspunsului web. Trackerele active (privat/urmarire.json, `ax-date` → `ax_urmarire`) și șablonul
     * managerului din Cookie Policy (`<template id="ax-cmplz-sablon">`, din fragmentul bannerului) le pune cadrul doar la
     * răspunsul web: T e captura, deci nu le are, iar P1 și amprenta randării nu depind de ele (cu fișierul privat pus, P1
     * dădea NU pe primul document, fără nicio editare; cu Cookie Policy importată, pe pagina ei).
     *
     * @return array{cale: string, nonce: string, an: string, seo: array<string, string>, urmarire: array{}, sablon_cmplz: string}
     */
    public static function cerereImport(array $doc): array
    {
        $v = json_decode((string) ($doc['valori_import'] ?? ''), true);
        $v = is_array($v) ? $v : [];
        return ['cale' => (string) ($v['cale'] ?? '/'), 'nonce' => 'p1', 'an' => (string) ($v['an'] ?? date('Y')), 'seo' => is_array($v['seo'] ?? null) ? $v['seo'] : [],
            'urmarire' => [], 'sablon_cmplz' => ''];
        // Regiunile dinamice: fără context de cerere, cadrul pune octeții capturați (CadruCapturat::randeaza), deci P1 = T.
    }

    /** Cheia unui răspuns înghețat: metoda, calea și query-ul. */
    public static function cheieRaspuns(string $metoda, string $cale, string $query): string
    {
        return hash('sha256', strtoupper($metoda) . "\n" . $cale . "\n" . $query);
    }
}
