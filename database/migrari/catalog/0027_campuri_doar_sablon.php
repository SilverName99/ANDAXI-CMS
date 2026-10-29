<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Câmpurile suplimentare legate de un șablon de produs (WP-19: C14d): `product_extra_fields.template_only` = 1 →
 * câmpul apare în editorul produsului doar când șablonul ales conține codul lui, iar pe pagina produsului nu intră
 * în lista de texte a paginii implicite și nici ca tab.
 *
 * Adoptivă: codul Biovitality face aceeași coloană (AdminController::ensureOptionalSchema), cu aceeași definiție.
 */
return [
    'descriere' => 'Câmpurile suplimentare: „apare doar la șabloanele care îl folosesc”',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('product_extra_fields', 'template_only', 'TINYINT(1) NOT NULL DEFAULT 0');
    },
];
