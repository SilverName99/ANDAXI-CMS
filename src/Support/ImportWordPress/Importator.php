<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress;

use App\Support\Paths;
use App\Support\Redirectionari;
use App\Support\Settings;
use PDO;
use Throwable;

/**
 * Aplică un Plan pe baza codului comun: copiază mediile (Media, în docroot-ul nou la aceeași cale, cu manifestul lor),
 * apoi, într-o tranzacție, paginile (`pages`), SEO-ul lor (`seo_pages`), setările (numele, logo-ul, iconița, ID-urile de
 * analiză; pe drumul necapturat și meniul, antetul și subsolul) și pozele în Galerie (`gallery_images`, dosarul
 * „WordPress”, cu adresele lor `/wp-content/uploads/…`), iar la urmă redirecționările (`seo_redirects`, sursa „import”).
 *
 * Se poate rula din nou: harta importului (`storage/import-wordpress/harta.json`: id-ul WordPress → rândul de pe codul
 * comun) face ca o pagină importată deja să fie actualizată, nu dublată. O pagină cu același slug care NU e din import
 * (scrisă de om) nu se atinge niciodată: importul se oprește înainte de orice scriere. Un rând scris de import și
 * editat după aceea de om (pagina, rândul SEO, o setare: amprentele, corectura 1.9) se refuză la fel, afară de
 * `--suprascrie`.
 */
final class Importator
{
    /** Dosarul Galeriei în care intră pozele importate. */
    public const DOSAR_GALERIE = ['nume' => 'WordPress', 'slug' => 'wordpress'];

    /**
     * @param string|null $tintaMedia unde se copiază mediile (implicit `<docroot>/wp-content/uploads`, Paths::public)
     * @return array<string, mixed> ce s-a făcut (pentru raport)
     */
    public static function aplica(Plan $plan, PDO $db, bool $cuMedia = true, bool $suprascrie = false, ?string $tintaMedia = null): array
    {
        if ($plan->probleme !== []) {
            throw new EroareImport('planul are ' . count($plan->probleme) . ' probleme (NU); nu import nimic');
        }
        if ($plan->capturat) {
            // Pe drumul capturat (pasul 5a), `pages.html_content` primește doar bucata conținutului fiecărui document tăiat; restul
            // (head-ul, antetul, subsolul, bannerul, datele, 404-ul, embed-urile, răspunsurile înghețate) merge în tabelele capturii
            // (ScriereCapturata, migrarea nucleu/0070). Fără tăiere (sau fără tabele), nimic: documentul întreg n-ajunge în pagină.
            if ($plan->capturata === null || array_filter($plan->pagini, static fn (array $pg): bool => !isset($pg['document'])) !== []) {
                throw new EroareImport('drumul capturat: o pagină a planului n-are documentul ei tăiat (pasul 5a); nu import nimic');
            }
            if (!ScriereCapturata::disponibil($db)) {
                throw new EroareImport('baza site-ului n-are tabelele capturii (migrarea nucleu/0070: pagini_capturate…): php scripts/migreaza.php --aplica');
            }
        }
        $harta = self::harta();
        // Paginile cu același slug, care nu sunt ale importului (și cele din coș): oprire înainte de orice scriere.
        $conflicte = self::conflicteAdrese($db, $plan, $harta);
        if ($conflicte !== []) {
            throw new EroareImport('pe site există deja pagini cu adresele: ' . implode(', ', $conflicte) . '; nu sunt din import, deci nu le scriu peste');
        }
        // SPEC-R3-05: două slug-uri ale planului care diferă doar printr-un accent se bat cap în cap pe cheia UNIQUE a bazei
        // (utf8mb4_unicode_ci), deși trec de verificarea PHP cu cheie exactă din plan. Le prinde înaintea scrierii, nu la --aplica
        // cu un „1062 Duplicate entry” nelămurit.
        $coliziuni = self::coliziuniSlug($db, $plan);
        if ($coliziuni !== []) {
            throw new EroareImport('slug-uri care se bat cap în cap pe cheia unică a bazei: ' . implode('; ', $coliziuni) . '; nu import nimic');
        }
        if (!Amprente::disponibil($db)) {
            throw new EroareImport('baza site-ului n-are tabelul ' . Amprente::TABEL . ' (migrarea nucleu/0068): php scripts/migreaza.php --aplica');
        }
        // Corectura 1.9: rândurile editate de om de la importul de dinainte (și ale capturii: hash_import pe fiecare rând).
        $editate = array_merge(Amprente::conflicte($db, $plan, $harta, $plan->capturat), $plan->capturat ? ScriereCapturata::conflicte($db) : []);
        if ($editate !== [] && !$suprascrie) {
            throw new EroareImport(count($editate) . ' rânduri au fost editate după importul de dinainte (' . implode('; ', array_column(array_slice($editate, 0, 5), 'motiv'))
                . (count($editate) > 5 ? '; …' : '') . '); nu le scriu peste: --suprascrie, dacă vrei totuși conținutul din WordPress');
        }
        $rez = ['media' => null, 'pagini' => 0, 'pagini_noi' => 0, 'seo' => 0, 'setari' => 0, 'galerie' => 0, 'redirecturi' => 0, 'redirecturi_refuzate' => [],
            'suprascrise' => $suprascrie ? array_column($editate, 'motiv') : []];
        if ($cuMedia) {
            $rez['media'] = Media::copiaza($plan->media['fisiere'], $tintaMedia ?? Paths::public(Media::DOSAR_DOCROOT));
            Media::scrieManifest($rez['media']['manifest'], Paths::stocareSite('import-wordpress') . '/media.json');
        }

        $db->beginTransaction();
        // R4: copierea mediilor (mai sus) durează (fiecare fișier are sha256 de două ori), iar verificările de dinaintea ei
        // (conflicteAdrese, Amprente::conflicte) s-au făcut înainte de ea. Un om care salvează o pagină între timp ar fi scris
        // peste în tăcere. De aceea reverificăm aici, ÎN tranzacție, cu rândurile blocate (SELECT … FOR UPDATE): o salvare în curs
        // se serializează înaintea noastră și o vedem, iar una nouă nu mai poate începe până nu terminăm. Dacă s-a schimbat ceva,
        // anulăm tot (nicio pagină scrisă) cu mesajul conflictului; mediile deja copiate rămân pe disc (se spune în mesaj).
        try {
            $mediiCopiate = is_array($rez['media']) ? (int) $rez['media']['copiate'] : 0;
            $nota = $cuMedia ? ' (mediile deja copiate rămân pe disc: ' . $mediiCopiate . ' fișiere)' : '';
            $conflicteAcum = self::conflicteAdrese($db, $plan, $harta, true);
            if ($conflicteAcum !== []) {
                throw new EroareImport('între verificare și scriere au apărut pagini cu adresele: ' . implode(', ', $conflicteAcum)
                    . '; nu sunt din import, nu le scriu peste' . $nota);
            }
            $editateAcum = array_merge(Amprente::conflicte($db, $plan, $harta, $plan->capturat, true), $plan->capturat ? ScriereCapturata::conflicte($db, true) : []);
            if ($editateAcum !== [] && !$suprascrie) {
                throw new EroareImport(count($editateAcum) . ' rânduri au fost editate în timpul importului ('
                    . implode('; ', array_column(array_slice($editateAcum, 0, 5), 'motiv')) . (count($editateAcum) > 5 ? '; …' : '')
                    . '); nu le scriu peste' . $nota);
            }
            $paginiId = [];
            foreach ($plan->pagini as $pg) {
                [$id, $noua] = self::scriePagina($db, $pg, $harta);
                $rez['pagini']++;
                $rez['pagini_noi'] += $noua ? 1 : 0;
                $harta['pagini'][(string) $pg['wp_id']] = $id;
                if (isset($pg['document'])) {
                    $paginiId[(string) $pg['document']] = $id;
                }
                if (self::scrieSeo($db, $id, (array) ($pg['seo'] ?? []), (int) $pg['wp_id'])) {
                    $rez['seo']++;
                }
            }
            if ($plan->capturat && $plan->capturata !== null) {
                // Pasul 5a: documentele tăiate, fragmentele, răspunsurile înghețate, formularele, galeriile; apoi P1 la import (o
                // randare diferită de T anulează tot), cu scripturile runtime-ului pe care le pune cadrul (pasul 6: tema.json → runtime).
                $rez['capturat'] = ScriereCapturata::scrie($db, $plan->capturata, $paginiId,
                    \App\Support\CadruCapturat::scripturiRuntime(\App\Support\Tema::runtime()));
            }
            $setari = $plan->setari;
            // Corectura 1.6: pe drumul capturat antetul, meniul și subsolul sunt fragmente capturate (nu setările design_*).
            if (!$plan->capturat) {
                if ($plan->meniu !== []) {
                    $setari['design_menu_html'] = self::meniuHtml($plan->meniu);
                }
                foreach ($plan->design as $k => $v) {
                    if (preg_match('/^design_(header|footer|menu)_(html|css|js)$/', $k) === 1) {
                        $setari[$k] = $v;
                    }
                }
            }
            Settings::save($db, $setari);
            // Amprenta fiecărei setări, din valoarea rămasă în bază (cum o citește apoi Amprente::conflicte).
            $valoare = $db->prepare('SELECT `value` FROM settings WHERE `key` = ?');
            foreach (array_keys($setari) as $k) {
                $valoare->execute([(string) $k]);
                Amprente::scrie($db, 'setare', (string) $k, Amprente::hashSetare((string) $valoare->fetchColumn()));
            }
            $rez['setari'] = count($setari);
            $rez['galerie'] = self::scrieGalerie($db, $plan, $harta);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            // Conflictul la re-verificarea sub blocare (R4) e deja un EroareImport cu mesajul lui: îl lăsăm așa (nimic scris).
            throw $e instanceof EroareImport ? $e : new EroareImport('scrierea în baza site-ului n-a mers (nimic scris): ' . $e->getMessage());
        }
        self::salveazaHarta($harta);

        foreach ($plan->redirecturi as $r) {
            $existenta = Redirectionari::dupaCheie($db, (string) (Redirectionari::curataDin($r['din'], $r['prefix']) ?? ''), $r['prefix']);
            if ($existenta !== null && (string) ($existenta['source'] ?? '') !== 'import') {
                continue; // o regulă scrisă de om (sau din pachet) rămâne a lui
            }
            $o = Redirectionari::adauga($db, $r['din'], (string) ($r['spre'] ?? ''), $r['cod'], 'import', $r['nota'], 'import WordPress', $r['prefix']);
            if ($o['ok']) {
                $rez['redirecturi']++;
            } else {
                $rez['redirecturi_refuzate'][] = $r['din'] . ': ' . $o['mesaj'];
            }
        }
        return $rez;
    }

    /**
     * Adresele planului pe care stau deja alte pagini decât ale importului (unicitatea slug-ului cuprinde și paginile din
     * coș, deci și ele), ca text pentru plan („/despre (pagina 7, în coș: …)”); goală = nicio piedică. O pagină a importului
     * mutată în coș nu e aici: e un conflict al amprentelor (Amprente::conflicte). Doar citire; o arată și planul scriptului.
     *
     * Cu `$blocheaza` (doar într-o tranzacție) rândurile se citesc cu `FOR UPDATE`, ca re-verificarea din tranzacția scrierii
     * (R4) să vadă o salvare făcută în timpul copierii mediilor și s-o oprească pe una nouă.
     *
     * @param array{pagini: array<string, int>} $harta
     * @return list<string>
     */
    public static function conflicteAdrese(PDO $db, Plan $plan, array $harta, bool $blocheaza = false): array
    {
        $conflicte = [];
        $q = $db->prepare('SELECT id, deleted_at FROM pages WHERE slug = ? LIMIT 1' . ($blocheaza ? ' FOR UPDATE' : ''));
        foreach ($plan->pagini as $pg) {
            $q->execute([$pg['slug']]);
            $rand = $q->fetch(PDO::FETCH_ASSOC);
            if (is_array($rand) && (int) ($harta['pagini'][(string) $pg['wp_id']] ?? 0) !== (int) $rand['id']) {
                $conflicte[] = '/' . $pg['slug'] . ' (pagina ' . $rand['id'] . ($rand['deleted_at'] !== null
                    ? ', în coș: o ștergi definitiv din coș sau îi schimbi adresa' : '') . ')';
            }
        }
        return $conflicte;
    }

    /**
     * Coliziunile slug-urilor pe care le scrie importul, așa cum le compară baza (`pages.slug`, UNIQUE sub utf8mb4_unicode_ci):
     * două pagini WordPress ale planului ale căror slug-uri diferă doar printr-un accent (δωματια vs δωμάτια) trec de
     * verificarea PHP cu cheie exactă din plan, dar se bat cap în cap pe cheia UNIQUE. Lăsăm MariaDB să decidă egalitatea pe
     * colația cheii: cheia de sortare a colației (WEIGHT_STRING pe utf8mb4_unicode_ci) e egală exact când două valori se bat
     * cap în cap pe cheia UNIQUE; grupăm slug-urile după ea (fără niciun DDL: doar o citire). Spațiile de la coadă nu contează
     * (colația e PAD SPACE), deci se taie întâi. Coliziunile cu rânduri EXISTENTE le prinde deja conflicteAdrese
     * (`WHERE slug = ?`, tot sub colație).
     *
     * @return list<string> fiecare coliziune, cu ambele ID-uri WordPress și adrese
     */
    public static function coliziuniSlug(PDO $db, Plan $plan): array
    {
        $pagini = array_values(array_filter($plan->pagini, static fn (array $pg): bool => (string) $pg['slug'] !== ''));
        if (count($pagini) < 2) {
            return [];
        }
        $q = $db->prepare('SELECT HEX(WEIGHT_STRING(CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci))');
        $grupuri = [];
        $coliziuni = [];
        foreach ($pagini as $p) {
            $q->execute([rtrim((string) $p['slug'], ' ')]);
            $cheie = (string) $q->fetchColumn();
            if (isset($grupuri[$cheie])) {
                $a = $grupuri[$cheie];
                $coliziuni[] = '/' . $p['slug'] . ' („' . $p['titlu'] . '”, WordPress ' . $p['wp_id'] . ', ' . $p['cale'] . ') și /' . $a['slug']
                    . ' („' . $a['titlu'] . '”, WordPress ' . $a['wp_id'] . ', ' . $a['cale'] . ')';
                continue; // raportăm coliziunea o dată; nu o mai grupăm
            }
            $grupuri[$cheie] = $p;
        }
        return $coliziuni;
    }

    /**
     * Scrie (sau actualizează, după hartă) o pagină și îi ține minte amprenta. Întoarce id-ul ei și dacă e nouă. O pagină a
     * importului mutată în coș se restaurează pe același id (aici se ajunge doar cu --suprascrie: Amprente::conflicte).
     *
     * @return array{0: int, 1: bool}
     */
    private static function scriePagina(PDO $db, array $pg, array $harta): array
    {
        $id = (int) ($harta['pagini'][(string) $pg['wp_id']] ?? 0);
        if ($id > 0) {
            $q = $db->prepare('SELECT id FROM pages WHERE id = ?');
            $q->execute([$id]);
            $id = (int) ($q->fetchColumn() ?: 0);
        }
        $valori = ['title' => mb_substr((string) $pg['titlu'], 0, 190), 'slug' => $pg['slug'], 'html' => (string) $pg['html'],
            'css' => (string) $pg['css'] !== '' ? (string) $pg['css'] : null, 'js' => (string) $pg['js'] !== '' ? (string) $pg['js'] : null,
            'pub' => $pg['publicata'] ? 1 : 0];
        $noua = $id <= 0;
        if (!$noua) {
            $db->prepare('UPDATE pages SET title = :title, slug = :slug, html_content = :html, css_content = :css, js_content = :js, is_published = :pub,
                deleted_at = NULL WHERE id = :id')->execute($valori + ['id' => $id]);
        } else {
            $db->prepare('INSERT INTO pages (title, slug, html_content, css_content, js_content, is_published) VALUES (:title, :slug, :html, :css, :js, :pub)')
                ->execute($valori);
            $id = (int) $db->lastInsertId();
        }
        // Amprenta, din rândul așa cum a rămas în bază (colația și tipurile coloanelor incluse).
        $q = $db->prepare('SELECT title, slug, html_content, css_content, js_content, is_published FROM pages WHERE id = ?');
        $q->execute([$id]);
        Amprente::scrie($db, 'pagina', (string) $id, Amprente::hashPagina((array) $q->fetch(PDO::FETCH_ASSOC)), (int) $pg['wp_id']);
        return [$id, $noua];
    }

    /** @param array<string, string> $seo */
    private static function scrieSeo(PDO $db, int $id, array $seo, int $wpId): bool
    {
        $valori = ['title' => (string) ($seo['titlu'] ?? '') ?: null, 'description' => (string) ($seo['descriere'] ?? '') ?: null,
            'canonical' => (string) ($seo['canonical'] ?? '') ?: null, 'image' => (string) ($seo['imagine'] ?? '') ?: null,
            'robots' => (string) ($seo['robots'] ?? '') ?: null];
        if (array_filter($valori) === []) {
            // Doar un rând scris de import (verificat înainte, Amprente::conflicte) ajunge să fie șters aici.
            $db->prepare("DELETE FROM seo_pages WHERE page_type = 'custom_page' AND page_ref = ?")->execute([(string) $id]);
            Amprente::sterge($db, 'seo', (string) $id);
            return false;
        }
        $db->prepare("INSERT INTO seo_pages (page_type, page_ref, title, description, canonical_url, image_url, robots)
            VALUES ('custom_page', :ref, :title, :description, :canonical, :image, :robots)
            ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), canonical_url = VALUES(canonical_url),
                image_url = VALUES(image_url), robots = VALUES(robots)")->execute($valori + ['ref' => (string) $id]);
        $q = $db->prepare('SELECT ' . implode(', ', Amprente::COLOANE_SEO) . " FROM seo_pages WHERE page_type = 'custom_page' AND page_ref = ?");
        $q->execute([(string) $id]);
        Amprente::scrie($db, 'seo', (string) $id, Amprente::hashSeo((array) $q->fetch(PDO::FETCH_ASSOC)), $wpId);
        return true;
    }

    /** Pozele (atașamentele de tip imagine copiate) în Galerie, dosarul „WordPress”, la adresele lor `/wp-content/uploads/…`. */
    private static function scrieGalerie(PDO $db, Plan $plan, array &$harta): int
    {
        $db->prepare('INSERT IGNORE INTO gallery_folders (name, slug) VALUES (?, ?)')->execute([self::DOSAR_GALERIE['nume'], self::DOSAR_GALERIE['slug']]);
        $q = $db->prepare('SELECT id FROM gallery_folders WHERE slug = ?');
        $q->execute([self::DOSAR_GALERIE['slug']]);
        $dosar = (int) $q->fetchColumn();
        $exista = $db->prepare('SELECT id FROM gallery_images WHERE image_url = ? LIMIT 1');
        $adauga = $db->prepare('INSERT INTO gallery_images (title, media_type, image_url, folder_id, alt_text, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
        $n = 0;
        $ordine = 0;
        foreach ($plan->media['fisiere'] as $rel => $f) {
            $at = (int) $f['atasament'];
            if ($at <= 0 || !isset($plan->atasamente[$at]) || $plan->atasamente[$at]['fisier'] !== $rel) {
                continue; // doar poza principală a fiecărui atașament, nu și mărimile ei
            }
            $a = $plan->atasamente[$at];
            $tip = str_starts_with($a['mime'], 'image/') ? 'image' : (str_starts_with($a['mime'], 'video/') ? 'video' : '');
            if ($tip === '') {
                continue;
            }
            // Corectura 1.10: adresa fișierului e cea din WordPress; Galeria e doar o listă din care alege adminul.
            $url = Adrese::media($rel);
            $exista->execute([$url]);
            $ordine++;
            if ($exista->fetchColumn() !== false) {
                continue;
            }
            $titlu = trim($a['titlu']) !== '' ? $a['titlu'] : pathinfo($rel, PATHINFO_FILENAME);
            $adauga->execute([mb_substr($titlu, 0, 190), $tip, $url, $dosar, mb_substr($a['alt'] !== '' ? $a['alt'] : $titlu, 0, 255), $ordine]);
            $harta['galerie'][(string) $at] = (int) $db->lastInsertId();
            $n++;
        }
        return $n;
    }

    /** Meniul ca listă (ca sertarul de meniu al nucleului, `.menu-root`), cu submeniuri. @param list<array<string, mixed>> $elemente */
    public static function meniuHtml(array $elemente, bool $radacina = true): string
    {
        $html = $radacina ? '<ul class="menu-root">' : '<ul class="sub-menu">';
        foreach ($elemente as $e) {
            $html .= '<li><a href="' . htmlspecialchars((string) $e['url'], ENT_QUOTES) . '"' . ((string) ($e['tinta'] ?? '') === '_blank' ? ' target="_blank" rel="noopener"' : '')
                . '>' . htmlspecialchars((string) $e['titlu'], ENT_QUOTES) . '</a>' . ((array) ($e['copii'] ?? []) !== [] ? self::meniuHtml((array) $e['copii'], false) : '') . '</li>';
        }
        return $html . '</ul>';
    }

    /** @return array{pagini: array<string, int>, galerie: array<string, int>} */
    public static function harta(): array
    {
        $d = json_decode((string) @file_get_contents(Paths::stocareSite('import-wordpress') . '/harta.json'), true);
        return ['pagini' => is_array($d['pagini'] ?? null) ? $d['pagini'] : [], 'galerie' => is_array($d['galerie'] ?? null) ? $d['galerie'] : []];
    }

    private static function salveazaHarta(array $harta): void
    {
        $f = Paths::stocareSite('import-wordpress') . '/harta.json';
        if (@file_put_contents($f . '.tmp', (string) json_encode($harta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false || !@rename($f . '.tmp', $f)) {
            throw new EroareImport('nu pot scrie harta importului (' . $f . '): o reluare ar dubla paginile');
        }
    }
}
