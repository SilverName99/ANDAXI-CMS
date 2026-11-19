<?php

declare(strict_types=1);

namespace App\Support\WpCompat;

use App\Support\WpCompat;
use PDO;
use Throwable;

/**
 * Tabelul `wp_harta` (migrarea nucleu/0069): harta unui site WordPress pe ID-uri, citită de stratul de adrese (WpCompat):
 * `?page_id=`, `?p=`, `?cat=`, `?author=`, `?attachment_id=`, adresele frumoase ale atașamentelor și ghicirea (Ghicire).
 * O scrie importul (pasul 5, din rândurile App\Support\ImportWordPress\HartaWp), cu `scrie()`.
 *
 * Un rând: `wp_id`, `tip` (page, post, project, attachment, category, author), `slug_wp` (post_name-ul, în forma WordPress:
 * percent-encoded cu litere mici), `cale` (adresa pe care o scria WordPress, cu „/” final după permalinkuri), `stare`
 * (publish, private, inherit), `ordine` (ordinea creării), `fisier` (la atașamente: adresa fișierului,
 * `/wp-content/uploads/…`) și `pagina_id` (pagina din constructor în care a devenit postarea, când a devenit una: adresa ei
 * de acum are întâietate, deci o pagină redenumită în admin rămâne ținta corectă, iar una ștearsă nu mai e țintă).
 *
 * Pe site-urile fără wp-compat.json tabelul rămâne gol și nu-l citește nimeni.
 */
final class Harta
{
    public const TABEL = 'wp_harta';

    public const TIPURI = ['page', 'post', 'project', 'attachment', 'category', 'author'];

    /** Tipurile pe care le ghicește WordPress (redirect_guess_404_permalink: tipurile publice, doar starea publish). */
    public const TIPURI_GHICITE = ['page', 'post', 'project'];

    /** Dosarul public al mediilor WordPress (ImportWordPress\Media::DOSAR_DOCROOT). */
    public const UPLOADS = '/wp-content/uploads/';

    private const COLOANE = 'wp_id, tip, slug_wp, cale, stare, ordine, fisier, pagina_id';

    /** @var array<int, bool> */
    private static array $disponibil = [];

    /** Tabelul există pe baza asta? (Pe o bază nemigrată stratul nu cade: nu găsește nimic.) */
    public static function disponibil(PDO $db): bool
    {
        $cheie = spl_object_id($db);
        if (!isset(self::$disponibil[$cheie])) {
            try {
                self::$disponibil[$cheie] = $db->query('SELECT 1 FROM ' . self::TABEL . ' LIMIT 1') !== false;
            } catch (Throwable) {
                self::$disponibil[$cheie] = false;
            }
        }
        return self::$disponibil[$cheie];
    }

    /**
     * Rândul unui ID WordPress, dintre tipurile date, cât se vede pentru un vizitator (o postare doar publicată).
     *
     * @param list<string> $tipuri
     * @return array{wp_id: int, tip: string, slug_wp: string, cale: string, stare: string, ordine: int, fisier: string, pagina_id: ?int}|null
     */
    public static function dupaId(PDO $db, array $tipuri, int $wpId): ?array
    {
        if ($tipuri === [] || $wpId <= 0 || !self::disponibil($db)) {
            return null;
        }
        $stmt = $db->prepare('SELECT ' . self::COLOANE . ' FROM ' . self::TABEL . ' WHERE wp_id = ? AND tip IN ('
            . implode(', ', array_fill(0, count($tipuri), '?')) . ') ORDER BY ordine ASC LIMIT 1');
        $stmt->execute(array_merge([$wpId], $tipuri));
        $rand = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($rand)) {
            return null;
        }
        $rand = self::rand($rand);
        if (in_array($rand['tip'], self::TIPURI_GHICITE, true) && $rand['stare'] !== 'publish') {
            return null;
        }
        return $rand;
    }

    /**
     * Adresa de acum a unui rând: a paginii din constructor în care a devenit (dacă e publicată), altfel calea scrisă de
     * WordPress. Null: pagina a fost ștearsă sau nu mai e publicată.
     *
     * @param array{cale: string, pagina_id: ?int} $rand
     */
    public static function adresaRand(PDO $db, array $rand): ?string
    {
        if ($rand['pagina_id'] !== null) {
            $slug = self::slugPagina($db, $rand['pagina_id']);
            return $slug === null ? null : WpCompat::adresaPagina($slug);
        }
        return $rand['cale'] !== '' ? $rand['cale'] : null;
    }

    /** Slug-ul de acum al unei pagini publicate din constructor, sau null. */
    public static function slugPagina(PDO $db, int $id): ?string
    {
        $stmt = $db->prepare('SELECT slug FROM pages WHERE id = ? AND is_published = 1 AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$id]);
        $slug = $stmt->fetchColumn();
        return $slug === false ? null : trim((string) $slug, '/');
    }

    /**
     * Fișierul atașamentului a cărui adresă frumoasă e chiar calea cerută (get_page_by_path caută și atașamentele; fără
     * deosebire de litere mari ASCII), sau null.
     */
    public static function fisierAtasament(PDO $db, string $cale): ?string
    {
        if (!self::disponibil($db)) {
            return null;
        }
        $cale = self::formaCautare($cale);
        if ($cale === '/') {
            return null;
        }
        $stmt = $db->prepare('SELECT fisier FROM ' . self::TABEL . " WHERE tip = 'attachment' AND fisier <> '' AND cale IN (?, ?)
            ORDER BY wp_id ASC LIMIT 1");
        $stmt->execute([rtrim($cale, '/') . '/', rtrim($cale, '/')]);
        $fisier = $stmt->fetchColumn();
        return $fisier === false ? null : (string) $fisier;
    }

    /**
     * Rândul unei postări (pagină, articol, proiect) publicate a cărei adresă WordPress e calea dată (fără deosebire de
     * litere mari ASCII), sau null. Pentru postările care n-au devenit pagini în constructor.
     *
     * @return array{wp_id: int, tip: string, slug_wp: string, cale: string, stare: string, ordine: int, fisier: string, pagina_id: ?int}|null
     */
    public static function postareDupaCale(PDO $db, string $cale): ?array
    {
        if (!self::disponibil($db)) {
            return null;
        }
        $cale = self::formaCautare($cale);
        $stmt = $db->prepare('SELECT ' . self::COLOANE . ' FROM ' . self::TABEL . " WHERE tip IN ('page', 'post', 'project')
            AND stare = 'publish' AND cale IN (?, ?) ORDER BY wp_id ASC LIMIT 1");
        $stmt->execute([rtrim($cale, '/') . '/', $cale === '/' ? '/' : rtrim($cale, '/')]);
        $rand = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($rand) ? self::rand($rand) : null;
    }

    /**
     * Postările ghicibile (TIPURI_GHICITE, publicate), după ID-ul WordPress (cel mai mic câștigă).
     *
     * @return list<array{wp_id: int, tip: string, slug_wp: string, cale: string, stare: string, ordine: int, fisier: string, pagina_id: ?int}>
     */
    public static function candidati(PDO $db): array
    {
        if (!self::disponibil($db)) {
            return [];
        }
        $rez = [];
        foreach ($db->query('SELECT ' . self::COLOANE . ' FROM ' . self::TABEL . " WHERE tip IN ('page', 'post', 'project')
            AND stare = 'publish' ORDER BY wp_id ASC, ordine ASC")->fetchAll(PDO::FETCH_ASSOC) as $rand) {
            $rez[] = self::rand($rand);
        }
        return $rez;
    }

    /** @return list<int> paginile din constructor care au un rând în hartă (pentru ele contează ordinea WordPress) */
    public static function paginiDinHarta(PDO $db): array
    {
        if (!self::disponibil($db)) {
            return [];
        }
        return array_map('intval', $db->query('SELECT pagina_id FROM ' . self::TABEL . ' WHERE pagina_id IS NOT NULL')
            ->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Scrie harta întreagă, în locul celei de dinainte, într-o tranzacție (importul o rescrie de fiecare dată).
     *
     * @param list<array{wp_id: int, tip: string, slug_wp: string, cale: string, stare: string, ordine: int, fisier: string}> $randuri
     *        rândurile HartaWp; `fisier` relativ la wp-content/uploads/ (sau deja o adresă „/…”)
     * @param array<int, int> $pagini ID-ul WordPress al unei postări (pagină, articol, proiect) → pagina din constructor
     * @return int rândurile scrise
     */
    public static function scrie(PDO $db, array $randuri, array $pagini = []): int
    {
        $inTranzactie = $db->inTransaction();
        if (!$inTranzactie) {
            $db->beginTransaction();
        }
        try {
            $db->exec('DELETE FROM ' . self::TABEL);
            $stmt = $db->prepare('INSERT INTO ' . self::TABEL . ' (' . self::COLOANE . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $scrise = 0;
            foreach ($randuri as $n => $r) {
                $tip = (string) $r['tip'];
                if (!in_array($tip, self::TIPURI, true)) {
                    continue;
                }
                $wpId = (int) $r['wp_id'];
                $paginaId = in_array($tip, self::TIPURI_GHICITE, true) && isset($pagini[$wpId]) ? (int) $pagini[$wpId] : null;
                $stmt->execute([$wpId, $tip, (string) $r['slug_wp'], (string) $r['cale'], (string) $r['stare'],
                    (int) ($r['ordine'] ?? 0) > 0 ? (int) $r['ordine'] : $n + 1, self::adresaFisier((string) ($r['fisier'] ?? '')), $paginaId]);
                $scrise++;
            }
            if (!$inTranzactie) {
                $db->commit();
            }
            return $scrise;
        } catch (Throwable $e) {
            if (!$inTranzactie && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** Adresa publică a unui fișier din wp-content/uploads/ („2026/01/harta.png” → „/wp-content/uploads/2026/01/harta.png”). */
    public static function adresaFisier(string $fisier): string
    {
        $fisier = trim($fisier);
        if ($fisier === '' || str_starts_with($fisier, '/')) {
            return $fisier;
        }
        return self::UPLOADS . implode('/', array_map([WpCompat::class, 'segment'], explode('/', trim($fisier, '/'))));
    }

    /**
     * Calea cerută în forma din hartă: fără „//”, escape-urile cu litere mici, octeții din afara ASCII-ului vizibil codați,
     * literele mari ASCII mici (cum compară WordPress părțile adresei).
     */
    private static function formaCautare(string $cale): string
    {
        $cale = (string) preg_replace('#/{2,}#', '/', $cale === '' ? '/' : $cale);
        $cale = (string) preg_replace_callback('/[^\x21-\x7e]/', static fn (array $m): string => sprintf('%%%02x', ord($m[0])), $cale);
        return strtolower($cale);
    }

    /**
     * @param array<string, mixed> $r
     * @return array{wp_id: int, tip: string, slug_wp: string, cale: string, stare: string, ordine: int, fisier: string, pagina_id: ?int}
     */
    private static function rand(array $r): array
    {
        return ['wp_id' => (int) $r['wp_id'], 'tip' => (string) $r['tip'], 'slug_wp' => (string) $r['slug_wp'], 'cale' => (string) $r['cale'],
            'stare' => (string) $r['stare'], 'ordine' => (int) $r['ordine'], 'fisier' => (string) ($r['fisier'] ?? ''),
            'pagina_id' => $r['pagina_id'] === null ? null : (int) $r['pagina_id']];
    }
}
