<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress;

use PDO;
use Throwable;

/**
 * Reimportul nu scrie niciodată peste munca oamenilor (corectura 1.9, O4, R15): la fiecare scriere, importul ține
 * minte hash-ul rândului scris (tabelul `import_amprente`, migrarea nucleu/0068): al paginii (titlul, slug-ul,
 * conținutul, CSS-ul, JS-ul, publicarea), al rândului ei SEO și al fiecărei setări. La un import nou, un rând al cărui
 * hash de acum diferă de cel de la import a fost editat de om: `conflicte()` îl listează, iar Importator îl refuză
 * fără `--suprascrie` (planul îl arată ca NU).
 *
 *  - o pagină din harta importului fără amprentă (scrisă de un import mai vechi decât amprentele) e tot un conflict:
 *    nu se știe dacă a editat-o cineva;
 *  - o pagină a importului mutată în coș sau ștearsă definitiv de om e un conflict (o ștergere e tot munca omului);
 *    cu `--suprascrie`, importul o face din nou (cea din coș se restaurează, pe același id);
 *  - un rând SEO existent fără amprentă (scris de om) e un conflict; la fel un rând SEO al importului golit de om (panoul
 *    SEO șterge rândul când toate câmpurile lui sunt goale: rândul lipsește, dar amprenta lui a rămas). Amprenta SEO acoperă
 *    și og_type și intro_html (editabile de om, dar nescrise de import): un om care le pune e un conflict, iar un reimport cu
 *    SEO gol nu mai poate șterge rândul în tăcere (R3);
 *  - o setare a importului (cu amprentă) se compară cu amprenta ei; una pe care importul de dinainte n-a scris-o se
 *    compară, la un reimport, cu valoarea implicită a instalării: pusă de om (alta decât implicita și decât cea din plan),
 *    e un conflict. La primul import (nicio amprentă încă) setările implicite ale instalării se scriu peste, ca până acum.
 */
final class Amprente
{
    public const TABEL = 'import_amprente';

    /** Hash-ul unei pagini (rândul din `pages`). @param array<string, mixed> $r */
    public static function hashPagina(array $r): string
    {
        return self::hash([(string) ($r['title'] ?? ''), (string) ($r['slug'] ?? ''), (string) ($r['html_content'] ?? ''),
            (string) ($r['css_content'] ?? ''), (string) ($r['js_content'] ?? ''), (string) (int) ($r['is_published'] ?? 0)]);
    }

    /** Coloanele din `seo_pages` pe care le acoperă amprenta (og_type și intro_html sunt editabile de om în panoul SEO). */
    public const COLOANE_SEO = ['title', 'description', 'canonical_url', 'image_url', 'robots', 'og_type', 'intro_html'];

    /** Hash-ul rândului SEO al unei pagini (din `seo_pages`), peste toate câmpurile editabile. @param array<string, mixed> $r */
    public static function hashSeo(array $r): string
    {
        return self::hash(array_map(static fn (string $c): string => (string) ($r[$c] ?? ''), self::COLOANE_SEO));
    }

    public static function hashSetare(string $valoare): string
    {
        return self::hash([$valoare]);
    }

    /** @param list<string> $valori */
    private static function hash(array $valori): string
    {
        return hash('sha256', (string) json_encode($valori, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function disponibil(PDO $db): bool
    {
        try {
            $db->query('SELECT 1 FROM ' . self::TABEL . ' LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, string> cheia („fel:cheie”) → hash-ul de la import */
    public static function toate(PDO $db): array
    {
        if (!self::disponibil($db)) {
            return [];
        }
        $rez = [];
        foreach ($db->query('SELECT fel, cheie, hash FROM ' . self::TABEL)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rez[$r['fel'] . ':' . $r['cheie']] = (string) $r['hash'];
        }
        return $rez;
    }

    public static function scrie(PDO $db, string $fel, string $cheie, string $hash, ?int $wpId = null): void
    {
        $db->prepare('INSERT INTO ' . self::TABEL . ' (fel, cheie, hash, wp_id, scris_la) VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE hash = VALUES(hash), wp_id = VALUES(wp_id), scris_la = VALUES(scris_la)')->execute([$fel, $cheie, $hash, $wpId]);
    }

    public static function sterge(PDO $db, string $fel, string $cheie): void
    {
        $db->prepare('DELETE FROM ' . self::TABEL . ' WHERE fel = ? AND cheie = ?')->execute([$fel, $cheie]);
    }

    /**
     * Rândurile editate de om de la ultimul import, pe care planul le-ar scrie peste: paginile din harta importului, rândurile
     * lor SEO și setările. Doar citire.
     *
     * Cu `$blocheaza` (doar într-o tranzacție) rândurile se citesc cu `FOR UPDATE`: re-verificarea din tranzacția scrierii (R4)
     * vede astfel o editare făcută de om în timpul copierii mediilor (se serializează înaintea noastră) și oprește una nouă.
     *
     * @param array{pagini: array<string, int>} $harta harta importului (Importator::harta)
     * @return list<array{fel: string, cheie: string, motiv: string}>
     */
    public static function conflicte(PDO $db, Plan $plan, array $harta, bool $faraSetariDesign = false, bool $blocheaza = false): array
    {
        $amprente = self::toate($db);
        $rez = [];
        $blocare = $blocheaza ? ' FOR UPDATE' : '';
        $pagina = $db->prepare('SELECT id, title, slug, html_content, css_content, js_content, is_published, deleted_at FROM pages WHERE id = ?' . $blocare);
        $seo = $db->prepare('SELECT ' . implode(', ', self::COLOANE_SEO) . " FROM seo_pages WHERE page_type = 'custom_page' AND page_ref = ?" . $blocare);
        foreach ($plan->pagini as $pg) {
            $id = (int) ($harta['pagini'][(string) $pg['wp_id']] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $pagina->execute([$id]);
            $rand = $pagina->fetch(PDO::FETCH_ASSOC);
            $stiut = $amprente['pagina:' . $id] ?? null;
            if (!is_array($rand)) {
                // Ștearsă definitiv din CMS. Fără amprentă (un import mai vechi decât amprentele, o hartă veche) nu se știe
                // nimic despre ea: importul o face din nou, ca înainte.
                if ($stiut !== null) {
                    $rez[] = ['fel' => 'pagina', 'cheie' => (string) $id, 'motiv' => 'pagina ' . $id . ' („' . $pg['titlu'] . '”, din importul de dinainte) '
                        . 'a fost ștearsă definitiv după import (--suprascrie o face din nou)'];
                }
                continue;
            }
            if ($rand['deleted_at'] !== null) {
                $rez[] = ['fel' => 'pagina', 'cheie' => (string) $id, 'motiv' => 'pagina ' . $id . ' (/' . $rand['slug'] . ', „' . $rand['title'] . '”) '
                    . 'a fost mutată în coș după import (--suprascrie o restaurează, cu conținutul din WordPress)'];
                continue;
            }
            if ($stiut === null || $stiut !== self::hashPagina($rand)) {
                $rez[] = ['fel' => 'pagina', 'cheie' => (string) $id, 'motiv' => 'pagina ' . $id . ' (/' . $rand['slug'] . ', „' . $rand['title'] . '”) '
                    . ($stiut === null ? 'nu are amprenta importului' : 'a fost editată după import')];
            }
            $seo->execute([(string) $id]);
            $randSeo = $seo->fetch(PDO::FETCH_ASSOC);
            $stiutSeo = $amprente['seo:' . $id] ?? null;
            if (is_array($randSeo) && ($stiutSeo === null || $stiutSeo !== self::hashSeo($randSeo))) {
                $rez[] = ['fel' => 'seo', 'cheie' => (string) $id, 'motiv' => 'SEO-ul paginii ' . $id . ' (/' . $rand['slug'] . ') '
                    . ($stiutSeo === null ? 'scris de om (fără amprenta importului)' : 'editat după import')];
            } elseif (!is_array($randSeo) && $stiutSeo !== null) {
                // Panoul SEO șterge rândul când omul îi golește toate câmpurile: golirea e tot o editare.
                $rez[] = ['fel' => 'seo', 'cheie' => (string) $id, 'motiv' => 'SEO-ul paginii ' . $id . ' (/' . $rand['slug'] . ') a fost golit după import'];
            }
        }
        $setari = $plan->setari;
        if (!$faraSetariDesign) {
            foreach ($plan->design as $k => $v) {
                $setari[$k] = $v;
            }
            if ($plan->meniu !== []) {
                $setari['design_menu_html'] = '';
            }
        }
        if ($setari !== []) {
            $q = $db->prepare('SELECT `value` FROM settings WHERE `key` = ?' . $blocare);
            // Un import de dinainte a scris deja ceva (orice amprentă): de acum o setare fără amprentă nu mai e o implicită a
            // instalării pe care importul o poate scrie peste fără să întrebe.
            $reimport = $amprente !== [];
            $implicite = \App\Support\Settings::all(null);
            foreach ($setari as $k => $valoareNoua) {
                $stiut = $amprente['setare:' . $k] ?? null;
                $q->execute([$k]);
                $acum = $q->fetchColumn();
                if ($stiut === null) {
                    if (!$reimport || $acum === false || (string) $acum === (string) ($implicite[(string) $k] ?? '')
                        || (string) $acum === (string) $valoareNoua) {
                        continue;
                    }
                    $rez[] = ['fel' => 'setare', 'cheie' => (string) $k, 'motiv' => 'setarea ' . $k . ' a fost pusă de om (importul de dinainte n-a scris-o)'];
                    continue;
                }
                if ($acum !== false && self::hashSetare((string) $acum) !== $stiut) {
                    $rez[] = ['fel' => 'setare', 'cheie' => (string) $k, 'motiv' => 'setarea ' . $k . ' a fost schimbată după import'];
                }
            }
        }
        return $rez;
    }
}
