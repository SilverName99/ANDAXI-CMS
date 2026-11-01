<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Mărcile și etichetele produselor ca entități (WP-37, B1): `product_brands`, `product_tags`, `product_tag_links`
 * (migrarea catalog/0042).
 *
 * Sursa rămâne câmpul „Marcă” al produsului (`products.brand`) și etichetele lui (`products.tags_json`): paginile
 * `/marca/{slug}` și `/eticheta/{slug}` le citesc de acolo, deci adresele nu se schimbă. Entitățile țin lista din admin
 * (fiecare marcă și etichetă, cu SEO-ul ei: rândul din `seo_pages` după slug) și harta site-ului pe tipuri (WP-39). Se
 * țin la zi aici: la salvarea unui produs (`sincronizeazaProdus`) și la deschiderea listei din admin (`sincronizeaza`,
 * tot catalogul, pe bucăți), deci și după un import sau o schimbare din ERP. De două ori = aceleași rânduri.
 *
 * Slug-ul: `Slug::simplu` (cel de azi al paginilor); două nume cu același slug sunt aceeași pagină, deci același rând.
 */
final class MarciEtichete
{
    private const BUCATA = 500;

    /** Tabelele există (migrarea catalog/0042 a rulat)? */
    public static function disponibil(?PDO $db): bool
    {
        if (!$db instanceof PDO) {
            return false;
        }
        try {
            $db->query('SELECT 1 FROM product_tag_links LIMIT 0');
            $db->query('SELECT 1 FROM product_brands LIMIT 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Tot catalogul, pe bucăți: fiecare marcă și etichetă a produselor are rândul ei, iar legăturile produs–etichetă
     * sunt exact etichetele de acum ale fiecărui produs. Întoarce câte rânduri s-au schimbat (0 = era la zi).
     */
    public static function sincronizeaza(PDO $db): int
    {
        if (!self::disponibil($db)) {
            return 0;
        }
        $schimbate = 0;
        try {
            // Ce ar trebui să fie: din produse, într-o singură trecere (o interogare pe bucată de 500).
            $marciDorite = [];
            $eticheteDorite = [];
            $legaturiDorite = [];
            $maxim = (int) ($db->query('SELECT COALESCE(MAX(id), 0) FROM products')->fetchColumn() ?: 0);
            for ($de = 0; $de < $maxim; $de += self::BUCATA) {
                $stmt = $db->prepare('SELECT id, brand, tags_json FROM products WHERE id > ? AND id <= ?');
                $stmt->execute([$de, $de + self::BUCATA]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $produs) {
                    [$marca, $etichete] = self::dinProdus($produs);
                    if ($marca !== null && !isset($marciDorite[$marca[1]])) {
                        $marciDorite[$marca[1]] = $marca[0];
                    }
                    foreach ($etichete as [$nume, $slug]) {
                        $eticheteDorite[$slug] ??= $nume;
                        $legaturiDorite[(int) $produs['id'] . ':' . $slug] = [(int) $produs['id'], $slug];
                    }
                }
            }
            // Ce este: trei interogări.
            $marciExistente = array_flip(array_map('strval', $db->query('SELECT slug FROM product_brands')->fetchAll(PDO::FETCH_COLUMN) ?: []));
            $idEtichete = [];
            foreach ($db->query('SELECT id, slug FROM product_tags')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $idEtichete[(string) $r['slug']] = (int) $r['id'];
            }
            $pune = $db->prepare('INSERT IGNORE INTO product_brands (name, slug) VALUES (?, ?)');
            foreach ($marciDorite as $slug => $nume) {
                if (!isset($marciExistente[$slug])) {
                    $pune->execute([$nume, $slug]);
                    $schimbate += $pune->rowCount();
                }
            }
            $pune = $db->prepare('INSERT IGNORE INTO product_tags (name, slug) VALUES (?, ?)');
            $lipsa = false;
            foreach ($eticheteDorite as $slug => $nume) {
                if (!isset($idEtichete[$slug])) {
                    $pune->execute([$nume, $slug]);
                    $schimbate += $pune->rowCount();
                    $lipsa = true;
                }
            }
            if ($lipsa) {
                $idEtichete = [];
                foreach ($db->query('SELECT id, slug FROM product_tags')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                    $idEtichete[(string) $r['slug']] = (int) $r['id'];
                }
            }
            $dorite = [];
            foreach ($legaturiDorite as [$idProdus, $slug]) {
                if (isset($idEtichete[$slug])) {
                    $dorite[$idProdus . ':' . $idEtichete[$slug]] = [$idProdus, $idEtichete[$slug]];
                }
            }
            $existente = [];
            foreach ($db->query('SELECT product_id, tag_id FROM product_tag_links')->fetchAll(PDO::FETCH_NUM) ?: [] as [$p, $t]) {
                $existente[(int) $p . ':' . (int) $t] = [(int) $p, (int) $t];
            }
            $leaga = $db->prepare('INSERT IGNORE INTO product_tag_links (product_id, tag_id) VALUES (?, ?)');
            foreach (array_diff_key($dorite, $existente) as [$p, $t]) {
                $leaga->execute([$p, $t]);
                $schimbate += $leaga->rowCount();
            }
            $dezleaga = $db->prepare('DELETE FROM product_tag_links WHERE product_id = ? AND tag_id = ?');
            foreach (array_diff_key($existente, $dorite) as [$p, $t]) {
                $dezleaga->execute([$p, $t]);
                $schimbate += $dezleaga->rowCount();
            }
        } catch (Throwable) {
            // Lista din admin rămâne cea de dinainte; nimic nu se strică.
        }
        return $schimbate;
    }

    /**
     * Marca [nume, slug] (sau null) și etichetele [[nume, slug], …] unui produs, după regula paginilor (Slug::simplu).
     *
     * @param array<string, mixed> $produs
     * @return array{0: ?array{0: string, 1: string}, 1: list<array{0: string, 1: string}>}
     */
    private static function dinProdus(array $produs): array
    {
        $nume = mb_substr(trim((string) ($produs['brand'] ?? '')), 0, 190);
        $slug = mb_substr(Slug::simplu($nume), 0, 190);
        $marca = $nume !== '' && $slug !== '' ? [$nume, $slug] : null;
        $etichete = [];
        $brut = json_decode((string) ($produs['tags_json'] ?? ''), true);
        foreach (is_array($brut) ? $brut : [] as $text) {
            $text = mb_substr(trim(is_scalar($text) ? (string) $text : ''), 0, 190);
            $s = mb_substr(Slug::simplu($text), 0, 190);
            if ($text !== '' && $s !== '') {
                $etichete[$s] = [$text, $s];
            }
        }
        return [$marca, array_values($etichete)];
    }

    /** Un singur produs (după salvare). */
    public static function sincronizeazaProdus(?PDO $db, int $idProdus): void
    {
        if ($idProdus <= 0 || !self::disponibil($db)) {
            return;
        }
        try {
            $stmt = $db->prepare('SELECT id, brand, tags_json FROM products WHERE id = ? LIMIT 1');
            $stmt->execute([$idProdus]);
            $produs = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($produs)) {
                self::produs($db, $produs);
            } else {
                $db->prepare('DELETE FROM product_tag_links WHERE product_id = ?')->execute([$idProdus]);
            }
        } catch (Throwable) {
        }
    }

    /**
     * Mărcile, cu numărul produselor active și pagina lor (din entități; ordonate după nume).
     *
     * @return list<array{id: int, name: string, slug: string, products_count: int}>
     */
    public static function marci(PDO $db): array
    {
        try {
            $randuri = $db->query('SELECT id, name, slug FROM product_brands ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $numar = [];
            foreach ($db->query("SELECT TRIM(brand) AS b, COUNT(*) AS n FROM products WHERE deleted_at IS NULL AND is_active = 1
                AND TRIM(COALESCE(brand, '')) <> '' GROUP BY TRIM(brand)")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $s = Slug::simplu((string) $r['b']);
                $numar[$s] = ($numar[$s] ?? 0) + (int) $r['n'];
            }
        } catch (Throwable) {
            return [];
        }
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'slug' => (string) $r['slug'],
            'products_count' => $numar[(string) $r['slug']] ?? 0], $randuri);
    }

    /**
     * Etichetele, cu numărul produselor active (din legături).
     *
     * @return list<array{id: int, name: string, slug: string, products_count: int}>
     */
    public static function etichete(PDO $db): array
    {
        try {
            $randuri = $db->query('SELECT t.id, t.name, t.slug,
                    SUM(CASE WHEN p.id IS NOT NULL AND p.deleted_at IS NULL AND p.is_active = 1 THEN 1 ELSE 0 END) AS products_count
                FROM product_tags t
                LEFT JOIN product_tag_links l ON l.tag_id = t.id
                LEFT JOIN products p ON p.id = l.product_id
                GROUP BY t.id, t.name, t.slug
                ORDER BY t.name, t.id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'slug' => (string) $r['slug'],
            'products_count' => (int) $r['products_count']], $randuri);
    }

    /** @param array<string, mixed> $produs */
    private static function produs(PDO $db, array $produs): int
    {
        $schimbate = 0;
        [$marca, $etichete] = self::dinProdus($produs);
        if ($marca !== null) {
            $stmt = $db->prepare('INSERT IGNORE INTO product_brands (name, slug) VALUES (?, ?)');
            $stmt->execute($marca);
            $schimbate += $stmt->rowCount();
        }
        $idProdus = (int) ($produs['id'] ?? 0);
        $iduri = [];
        $pune = $db->prepare('INSERT IGNORE INTO product_tags (name, slug) VALUES (?, ?)');
        $cauta = $db->prepare('SELECT id FROM product_tags WHERE slug = ?');
        foreach ($etichete as [$text, $s]) {
            $pune->execute([$text, $s]);
            $schimbate += $pune->rowCount();
            $cauta->execute([$s]);
            $id = (int) ($cauta->fetchColumn() ?: 0);
            if ($id > 0) {
                $iduri[$id] = true;
            }
        }
        $actuale = $db->prepare('SELECT tag_id FROM product_tag_links WHERE product_id = ?');
        $actuale->execute([$idProdus]);
        $existente = array_map('intval', $actuale->fetchAll(PDO::FETCH_COLUMN) ?: []);
        foreach (array_diff($existente, array_keys($iduri)) as $idVechi) {
            $db->prepare('DELETE FROM product_tag_links WHERE product_id = ? AND tag_id = ?')->execute([$idProdus, $idVechi]);
            $schimbate++;
        }
        foreach (array_diff(array_keys($iduri), $existente) as $idNou) {
            $db->prepare('INSERT IGNORE INTO product_tag_links (product_id, tag_id) VALUES (?, ?)')->execute([$idProdus, $idNou]);
            $schimbate++;
        }
        return $schimbate;
    }
}
