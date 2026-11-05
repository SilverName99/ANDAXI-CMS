<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Oferta campaniei (pluginul `campanii`, App\Support\OfertaCampanie; docs/PLUGINURI.md, „Campanii pe site”, „Oferta
 * campaniei”): reducerea pe produsele campaniei active, socotită în coș și păstrată pe comandă.
 *
 *  - `campanii.oferta_tip` (`al_n`, `n_la_m`, `procent`, `pret_fix`; NULL = nicio ofertă), `oferta_n`, `oferta_m`,
 *    `oferta_procent`;
 *  - `campanii_produse.pret_campanie`: prețul de campanie al produsului (tipul `pret_fix`);
 *  - `orders.campanie_id`, `campanie_oferta` (textul ofertei, de exemplu „Black Weekends: 1+1 −50%”, înghețat pe comandă),
 *    `campanie_reducere` (reducerea ofertei pe toată comanda, deja scăzută din prețurile liniilor și din subtotal);
 *  - `order_items.campanie_reducere` (reducerea pe linie, deja scăzută din `line_total`), `campanie_pret_initial` (prețul
 *    unei bucăți înainte de ofertă).
 *
 * Toate NULL: fără ofertă, nimic nu se schimbă, iar comenzile se scriu exact ca înainte (coloanele le completează un UPDATE
 * separat, doar pe o comandă cu oferta campaniei). Coloane la coadă: codul de dinainte merge pe schema nouă.
 */
return [
    'descriere' => 'Campaniile: oferta campaniei pe produse, pe comenzi și pe liniile lor (campanii)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('campanii', 'oferta_tip', 'VARCHAR(20) NULL');
        $s->coloana('campanii', 'oferta_n', 'SMALLINT UNSIGNED NULL');
        $s->coloana('campanii', 'oferta_m', 'SMALLINT UNSIGNED NULL');
        $s->coloana('campanii', 'oferta_procent', 'DECIMAL(5,2) NULL');
        $s->coloana('campanii_produse', 'pret_campanie', 'DECIMAL(10,2) NULL');
        $s->coloana('orders', 'campanie_id', 'INT UNSIGNED NULL');
        $s->coloana('orders', 'campanie_oferta', 'VARCHAR(200) NULL');
        $s->coloana('orders', 'campanie_reducere', 'DECIMAL(10,2) NULL');
        $s->coloana('order_items', 'campanie_reducere', 'DECIMAL(10,2) NULL');
        $s->coloana('order_items', 'campanie_pret_initial', 'DECIMAL(10,2) NULL');
    },
];
