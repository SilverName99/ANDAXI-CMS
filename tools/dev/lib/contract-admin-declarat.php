<?php

declare(strict_types=1);

/**
 * $contractAdminPorturi: diferențele declarate ale contractului adminului față de eticheta `admin-inainte-b`
 * (tools/dev/contract-admin.php, poarta G-admin). Un sub-tablou pe WP (planul, §4.3), fiecare declarație cu motivul ei:
 *
 *   'cheie-unica' => ['ce' => 'adaugat'|'scos', 'rand' => '<regex pe rândul contractului>',
 *                     'cale' => '<regex pe adresa paginii>' (lipsă = orice pagină), 'licenta' => … , 'rol' => … ,
 *                     'motiv' => 'de ce (în română)']
 *
 * Rândurile contractului sunt descrise în tools/dev/lib/contract-admin.php. Regulile:
 *  - o declarație acoperă un singur fel de rând, scris cât mai exact (fără „.*” care ar ascunde altceva);
 *  - fiecare declarație trebuie să acopere ceva măcar o dată în rulare (altfel poarta pică: n-ar dovedi nimic);
 *  - un formular nou poate posta doar spre o adresă care există deja în contractul etichetei (WP-33: bara acțiunilor în
 *    masă), deci un rând „form …” adăugat se declară doar cu adresa lui exactă.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
return [
    // WP-29: contractul de bază. Pe codul neatins, 0 diferențe: nimic declarat.
    'WP-29' => [],
];
