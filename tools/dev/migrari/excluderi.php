<?php

declare(strict_types=1);

/**
 * Ce NU primește o instalare nouă din schema de azi a codului (planul Etapei 1, WP-03; migrari.md §5 B și
 * recomandarea OD-06) și diferențele declarate între schema codului și migrări. Le citesc
 * tools/dev/migrari/genereaza-fixture.php (G-baza, fixture-urile) și tools/dev/test-migrari.php (T1, T2).
 *
 *  - excluse: obiectele vechi care rămân doar pe site-urile care le au, până la faza „contract”. Fiecare
 *    trebuie să existe în schema codului (altfel lista e veche: --verifica pică);
 *  - colatie_server: tabelele pe care codul le face cu `DEFAULT CHARSET=utf8mb4` fără colație (BtIpayPayments),
 *    deci cu colația implicită a serverului (MariaDB utf8mb4_general_ci, MySQL 8 utf8mb4_0900_ai_ci). Migrările
 *    fac orice tabel cu utf8mb4_unicode_ci (Schema::tabel); pe o bază veche colația rămâne cum e (un
 *    avertisment la --verifica, nu o operație).
 */
return [
    'excluse' => [
        'tabelul reviews' => 'tabelul de dinainte de product_reviews; 0023 îl copiază o singură dată pe site-urile care îl au',
        'tabelul mannequin_points' => 'nefolosit: pluginul manechin ține punctele în settings.mannequin_points_json (schema.sql și install.php îl definesc diferit)',
        'tabelul mannequin_point_products' => 'nefolosit, ca mannequin_points',
        'coloana products.gallery_json' => 'galeria veche (install.php o crea la fiecare instalare și ținea un UPDATE la fiecare cerere); 0021 o copiază o dată în gallery_images_json',
    ],
    'colatie_server' => ['bt_ipay_log', 'bt_ipay_transactions'],
];
