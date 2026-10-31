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

    // WP-30: cadrul nou (meniul în cele opt grupuri, antetul, culoarea adminului). Paginile nu se schimbă.
    'WP-30' => [
        'sari-la-continut' => ['ce' => 'adaugat', 'rand' => '^link #continut$',
            'motiv' => 'linkul „Sari la conținut” al cadrului (primul element la Tab, pentru cine lucrează cu tastatura)'],
        'grupuri-vechi' => ['ce' => 'scos', 'rand' => '^data data-toggle-group = (utilizatori|magazin|configurare|blog|emailuri|newslettere)$',
            'motiv' => 'grupurile meniului de dinainte (Utilizatori, Magazin, Configurare, Blog, Setări email, Email-uri) își țineau cheia '
                . 'în data-toggle-group; meniul nou (docs/ARHITECTURA-ADMIN.md) leagă butonul grupului de lista lui prin aria-controls'],
        'meniu-newslettere' => ['ce' => 'adaugat', 'rand' => '^link /admin/emails/newsletters\\?tab=campaigns$', 'licenta' => ['toate', 'bioscem'],
            'rol' => ['adm', 'emailuri'],
            'motiv' => 'intrarea nouă „Newslettere” din Marketing (pagina campaniilor, la care se ajungea doar din tab-urile emailurilor)'],
        'accent-formular' => ['ce' => 'adaugat', 'rand' => '^form POST /admin/settings/store$', 'cale' => '^/admin/settings/store$', 'rol' => 'adm',
            'motiv' => 'formularul „Culoarea adminului” din Setări magazin / Setări site (aceeași adresă ca restul paginii)'],
        'accent-actiune' => ['ce' => 'adaugat', 'rand' => '^camp POST /admin/settings/store input:hidden action = save_admin_accent$',
            'cale' => '^/admin/settings/store$', 'rol' => 'adm', 'motiv' => 'acțiunea formularului „Culoarea adminului”'],
        'accent-culoare' => ['ce' => 'adaugat', 'rand' => '^camp POST /admin/settings/store input:color admin_culoare_accent$',
            'cale' => '^/admin/settings/store$', 'rol' => 'adm', 'motiv' => 'culoarea aleasă (setarea admin_culoare_accent)'],
        'accent-reseteaza' => ['ce' => 'adaugat', 'rand' => '^buton POST /admin/settings/store reseteaza = 1$',
            'cale' => '^/admin/settings/store$', 'rol' => 'adm', 'motiv' => 'butonul „Resetează” (accentul neutru)'],
    ],
];
