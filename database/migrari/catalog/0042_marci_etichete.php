<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Mărcile și etichetele produselor ca entități (WP-37, SEO partea B, B1; App\Support\MarciEtichete):
 *
 *  - `product_brands` și `product_tags`: numele și slug-ul fiecărei mărci și etichete (slug-ul e cel de azi al paginii,
 *    `/marca/{slug}` și `/eticheta/{slug}`: Slug::simplu, scris și aici, ca migrarea să nu se schimbe dacă se schimbă
 *    codul de mai târziu); două nume cu același slug (de exemplu „Vitamina D” și „vitamina d”) sunt aceeași pagină, deci
 *    același rând (primul nume găsit);
 *  - `product_tag_links`: ce etichete are fiecare produs;
 *  - `product_categories.intro_html`: textul de deasupra listei categoriei (NULL = lista ca până acum).
 *
 * Sursa rămâne cea de azi: câmpul „Marcă” (`products.brand`) și etichetele produsului (`products.tags_json`). Paginile
 * mărcilor și etichetelor le citesc tot de acolo, deci adresele lor nu se schimbă (aceleași liste înainte și după);
 * entitățile țin SEO-ul și lista din admin și se țin la zi la salvarea unui produs și la deschiderea paginii lor din
 * admin (MarciEtichete::sincronizeaza). Completarea de aici: pe bucăți de câte 500 de produse (și cele șterse sau
 * inactive: o marcă rămâne marcă), reluată de unde a rămas, de două ori = aceleași rânduri (INSERT IGNORE).
 */
return [
    'descriere' => 'Mărcile și etichetele produselor (din câmpul Marcă și din etichete, pe bucăți), textul de deasupra categoriei',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('product_categories', 'intro_html', 'MEDIUMTEXT NULL');

        $s->tabelComplet('product_brands', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name' => 'VARCHAR(190) NOT NULL',
            'slug' => 'VARCHAR(190) NOT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY slug (slug)',
        ]);
        $s->tabelComplet('product_tags', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name' => 'VARCHAR(190) NOT NULL',
            'slug' => 'VARCHAR(190) NOT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        ], [
            'PRIMARY KEY (id)',
            'UNIQUE KEY slug (slug)',
        ]);
        $s->tabelComplet('product_tag_links', [
            'product_id' => 'INT UNSIGNED NOT NULL',
            'tag_id' => 'INT UNSIGNED NOT NULL',
        ], [
            'PRIMARY KEY (product_id, tag_id)',
            'KEY idx_product_tag_links_tag (tag_id)',
        ]);

        // Slug::simplu de azi (marca și eticheta): litere mici, ă/â/î/ș/ț fără semne, restul în „-”.
        $slug = static function (string $valoare): string {
            $valoare = mb_strtolower(trim($valoare));
            $valoare = strtr($valoare, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
            return trim((string) preg_replace('/[^a-z0-9]+/', '-', $valoare), '-');
        };
        $s->peBucati('products', 'id', 500, static function (PDO $db, int $de, int $pana) use ($slug): int {
            $stmt = $db->prepare('SELECT id, brand, tags_json FROM products WHERE id > ? AND id <= ?');
            $stmt->execute([$de, $pana]);
            $marca = $db->prepare('INSERT IGNORE INTO product_brands (name, slug) VALUES (?, ?)');
            $eticheta = $db->prepare('INSERT IGNORE INTO product_tags (name, slug) VALUES (?, ?)');
            $idEticheta = $db->prepare('SELECT id FROM product_tags WHERE slug = ?');
            $leaga = $db->prepare('INSERT IGNORE INTO product_tag_links (product_id, tag_id) VALUES (?, ?)');
            $schimbate = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $produs) {
                $nume = mb_substr(trim((string) ($produs['brand'] ?? '')), 0, 190);
                $s1 = mb_substr($slug($nume), 0, 190);
                if ($nume !== '' && $s1 !== '') {
                    $marca->execute([$nume, $s1]);
                    $schimbate += $marca->rowCount();
                }
                $etichete = json_decode((string) ($produs['tags_json'] ?? ''), true);
                foreach (is_array($etichete) ? $etichete : [] as $text) {
                    $text = mb_substr(trim(is_scalar($text) ? (string) $text : ''), 0, 190);
                    $s2 = mb_substr($slug($text), 0, 190);
                    if ($text === '' || $s2 === '') {
                        continue;
                    }
                    $eticheta->execute([$text, $s2]);
                    $schimbate += $eticheta->rowCount();
                    $idEticheta->execute([$s2]);
                    $id = (int) ($idEticheta->fetchColumn() ?: 0);
                    if ($id > 0) {
                        $leaga->execute([(int) $produs['id'], $id]);
                        $schimbate += $leaga->rowCount();
                    }
                }
            }
            return $schimbate;
        }, 'product_brands+product_tags');
    },
];
