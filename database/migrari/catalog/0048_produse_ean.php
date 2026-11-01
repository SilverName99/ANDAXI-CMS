<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Codul EAN (GTIN) al produsului (WP-39, SEO partea B, B4): `products.ean`, doar cifre (8, 12, 13 sau 14, cu cifra de
 * control verificată la salvare, FluxGoogleMerchant::gtinValid). Intră în datele structurate ale produsului (`gtin13`,
 * `gtin8`…) și în fluxul Google Merchant (`g:gtin`); fără cod, nimic nou (ca până acum).
 *
 * Pornește gol pe fiecare site; nu se vede nimic pe site până nu se completează din editorul produsului.
 */
return [
    'descriere' => 'Codul EAN (GTIN) al produselor, pentru datele structurate și fluxul Google Merchant',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('products', 'ean', 'VARCHAR(14) NULL');
    },
];
