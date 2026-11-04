<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Ridicarea personală de la sediu (portul 807058e, fe122fd; App\Support\RidicarePersonala): comanda nu are
 * transport și nu primește AWB. Pe comandă se îngheață alegerea (`pickup_in_person`), adresa sediului din acel moment
 * (`pickup_address`, ca la FANbox: setările se pot schimba, comanda trebuie să rămână explicabilă) și momentul anunțului
 * „gata de ridicare” (`pickup_ready_notified_at`, ca butonul din Comenzi să nu trimită emailul de două ori din greșeală).
 *
 * Codul vechi le face din mers, în CheckoutCalculator::ensureOrderShippingSchema (cu AFTER fan_locker_postcode); aici,
 * la coadă, cu aceleași tipuri: pe o bază veche care le are deja, migrarea le găsește și nu le mai face. Comenzile de
 * dinainte rămân fără ridicare (0, NULL).
 */
return [
    'descriere' => 'Ridicarea personală de la sediu pe comenzi (fără transport, fără AWB; anunțul „gata de ridicare”)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('orders', 'pickup_in_person', 'TINYINT(1) NOT NULL DEFAULT 0');
        $s->coloana('orders', 'pickup_address', 'VARCHAR(255) DEFAULT NULL');
        $s->coloana('orders', 'pickup_ready_notified_at', 'DATETIME DEFAULT NULL');
    },
];
