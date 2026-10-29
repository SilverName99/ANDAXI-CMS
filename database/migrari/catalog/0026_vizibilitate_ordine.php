<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Vizibilitatea și ordinea produselor în catalog (WP-19: C8, C14b + GR-08, C14c).
 *
 *  - `preview_token`: cheia linkului de ciornă al unui produs inactiv (`/produs/<slug>?draft=<cheie>`); fiecare produs
 *    existent primește una aici, o singură dată, pe bucăți de câte 500 de rânduri (32 de caractere hexazecimale din
 *    random_bytes, ca la produsele noi). Un produs care are deja cheie o păstrează (linkurile date rămân bune);
 *  - `sort_position`: locul fix al produsului în liste (1 = primul; NULL = ordinea implicită) și cheia sortării
 *    „Ordinea din catalog” (setarea catalog_sortare_implicita);
 *  - `hidden_in_catalog`: produsul nu apare în lista {{shop_catalog}} și în numărătoarea categoriilor, dar rămâne de
 *    cumpărat, cu pagina lui și în harta site-ului.
 *
 * Adoptivă: codul Biovitality face aceleași trei coloane, cu aceleași definiții (AdminController::ensureOptionalSchema,
 * SiteController::ensureProductCustomSchema), deci pe baza lui migrarea le găsește și completează doar cheile lipsă.
 * Codul de dinainte merge pe schema nouă (coloane la coadă, cu valori implicite); codul nou citește coloanele cu o
 * interogare de rezervă fără ele, cât timp migrarea n-a rulat.
 */
return [
    'descriere' => 'Produsele: cheia linkului de ciornă (completată pe bucăți), locul fix în liste, ascunderea din catalog',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('products', 'preview_token', 'VARCHAR(32) NULL');
        $s->coloana('products', 'sort_position', 'INT NULL');
        $s->coloana('products', 'hidden_in_catalog', 'TINYINT(1) NOT NULL DEFAULT 0');

        // Cheia de ciornă a produselor existente: fiecare rând fără cheie primește una, aleasă cu random_bytes (cheia
        // e secretul linkului, deci nu RAND()/MD5 din SQL). Reluat după o întrerupere de unde a rămas.
        $s->peBucati('products', 'id', 500, static function (PDO $db, int $de, int $pana): int {
            $stmt = $db->prepare("SELECT id FROM products WHERE id > ? AND id <= ? AND (preview_token IS NULL OR preview_token = '')");
            $stmt->execute([$de, $pana]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $pune = $db->prepare("UPDATE products SET preview_token = ? WHERE id = ? AND (preview_token IS NULL OR preview_token = '')");
            $schimbate = 0;
            foreach ($ids as $id) {
                $pune->execute([bin2hex(random_bytes(16)), (int) $id]);
                $schimbate += $pune->rowCount();
            }
            return $schimbate;
        }, 'products.preview_token');
    },
];
