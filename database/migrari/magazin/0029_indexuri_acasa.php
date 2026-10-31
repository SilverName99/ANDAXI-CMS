<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Indecșii listelor din admin (WP-32, MIG-19 din migrari.md): pagina Acasă și tab-urile listei de comenzi numără comenzile
 * după stare (de procesat, noi, în procesare, fără AWB, ordin de plată de verificat) și le adună pe zile (vânzările de azi,
 * graficul pe 14 zile); fără indecși, fiecare deschidere a paginii citea tot tabelul `orders`. Plus coșurile abandonate de
 * azi (`cart_abandonments.last_seen_at`).
 *
 * Doar indecși, fără date schimbate: codul de dinainte merge la fel pe schema nouă, iar codul nou merge și înainte de
 * migrare (aceleași interogări, mai încet). Niciun cod vechi nu face acești indecși, deci pe o bază adoptată se adaugă.
 */
return [
    'descriere' => 'Comenzile și coșurile: indecșii paginii Acasă și ai tab-urilor de comenzi',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->index('orders', 'idx_orders_stare', ['status', 'deleted_at']);
        $s->index('orders', 'idx_orders_creata', ['created_at']);
        $s->index('cart_abandonments', 'idx_cart_abandonments_vazut', ['last_seen_at']);
    },
];
