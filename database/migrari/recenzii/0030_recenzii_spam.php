<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Anti-spamul recenziilor (WP-21: C10, din Biovitality 8ba8ddd): adresa de la care a venit fiecare recenzie
 * (`ip_address`, după regula App\Support\IpClient; pentru limita de 4 pe oră și pentru admin), marcajul de spam și
 * motivul lui, plus indexul după care se numără „Spam” și „În așteptare”.
 *
 * Adoptivă: codul Biovitality face aceleași coloane și același index, cu aceleași definiții (ReviewGuard::ensureSchema),
 * deci pe baza lui migrarea le găsește și nu face nimic. Implicit nicio recenzie nu e spam, deci listele rămân cum
 * erau. Codul de dinainte merge pe schema nouă (coloane la coadă, cu valori implicite); codul nou scrie coloanele într-o
 * instrucțiune separată, care tace cât timp migrarea n-a rulat.
 */
return [
    'descriere' => 'Recenziile: adresa vizitatorului, marcajul de spam și motivul lui',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('product_reviews', 'ip_address', 'VARCHAR(64) NULL');
        $s->coloana('product_reviews', 'is_spam', 'TINYINT(1) NOT NULL DEFAULT 0');
        $s->coloana('product_reviews', 'spam_reason', 'VARCHAR(190) NULL');
        $s->index('product_reviews', 'idx_product_reviews_spam', ['is_spam']);
    },
];
