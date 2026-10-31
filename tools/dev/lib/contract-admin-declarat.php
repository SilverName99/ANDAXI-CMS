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

    // WP-32: Acasă („B — Compact”: cifrele zilei, graficul, comenzile recente, „De făcut”, stocul, rândul de sănătate) și
    // „Abonamentul tău” (o intrare nouă în Setări). Celulele noi sunt ale tabelului „Comenzi recente” de pe Acasă.
    'WP-32' => [
        'meniu-abonament' => ['ce' => 'adaugat', 'rand' => '^link /admin/abonament$', 'rol' => 'adm',
            'motiv' => 'intrarea „Abonamentul tău” din Setări (doar administratorul general) și butonul de pe Acasă'],
        'acasa-stoc' => ['ce' => 'adaugat', 'rand' => '^link #acasa-stoc$', 'cale' => '^/admin$', 'rol' => 'adm',
            'motiv' => 'cardul „Stoc scăzut” și rândul lui din „De făcut” duc la lista stocului de pe aceeași pagină'],
        'acasa-comenzi' => ['ce' => 'adaugat', 'rand' => '^link /admin/orders$', 'cale' => '^/admin$', 'rol' => 'adm',
            'motiv' => 'butonul „Toate comenzile” din antet și numărul comenzilor din rândul „pe scurt”'],
        'acasa-comenzi-filtre' => ['ce' => 'adaugat', 'cale' => '^/admin$', 'rol' => 'adm',
            'rand' => '^link /admin/orders\?(status=pending|status=processing&livrare=fara_awb|payment_method=bank_transfer&payment_status=unpaid'
                . '|precomanda=asteptare|rest_incasat=1|erp=esuate)$',
            'motiv' => 'cardul „Comenzi de procesat”, butonul „Procesează comenzile noi” și rândurile din „De făcut”: lista comenzilor, filtrată'],
        'acasa-comanda' => ['ce' => 'adaugat', 'rand' => '^link /admin/orders\?q=\d+$', 'cale' => '^/admin$', 'rol' => 'adm',
            'motiv' => 'numărul fiecărei comenzi recente duce la lista comenzilor căutată după el'],
        'acasa-produse' => ['ce' => 'adaugat', 'rand' => '^link /admin/products$', 'cale' => '^/admin$', 'rol' => 'adm',
            'motiv' => 'numărul produselor din rândul „pe scurt” și „Toate produsele” de lângă stocul scăzut'],
        'acasa-recenzii' => ['ce' => 'adaugat', 'rand' => '^link /admin/products/reviews$', 'cale' => '^/admin$', 'rol' => 'adm',
            'motiv' => 'rândul „recenzii noi de aprobat” din „De făcut” (doar cu recenziile)'],
        'acasa-sanatate' => ['ce' => 'adaugat', 'rand' => '^link /admin/settings/(erp|shipping)$', 'cale' => '^/admin$', 'rol' => 'adm',
            'motiv' => 'rândul de sănătate: legătura cu ERP-ul (doar cu ERP) și contul FAN Courier (doar cu livrarea FAN)'],
        'acasa-celula-numar' => ['ce' => 'adaugat', 'rand' => '^celula \d+$', 'cale' => '^/admin$', 'rol' => 'adm',
            'motiv' => 'coloana „Comanda” a comenzilor recente (numărul comenzii)'],
        'acasa-celula-ora' => ['ce' => 'adaugat', 'rand' => '^celula ((azi|ieri)|\d\d\.\d\d), \d\d:\d\d$', 'cale' => '^/admin$', 'rol' => 'adm',
            'motiv' => 'coloana „Ora” a comenzilor recente'],
        'acasa-celula-total' => ['ce' => 'adaugat', 'rand' => '^celula \d{1,3}(\.\d{3})*,\d\d lei$', 'cale' => '^/admin$', 'rol' => 'adm',
            'motiv' => 'coloana „Total” a comenzilor recente'],
        'acasa-celula-plata' => ['ce' => 'adaugat', 'rand' => '^celula (Ramburs|Card|Card BT|Ordin de plată)$', 'cale' => '^/admin$', 'rol' => 'adm',
            'motiv' => 'coloana „Plată” a comenzilor recente'],
        'acasa-celula-stare' => ['ce' => 'adaugat', 'cale' => '^/admin$', 'rol' => 'adm',
            'rand' => '^celula (În așteptare|Plată în așteptare|În procesare|Finalizată|Anulată|Rambursată|Returnată|Eșuată|Trimisă|În așteptare|Netrimisă'
                . '|Anulare în curs|Retur în curs)$',
            'motiv' => 'coloanele „Status” și „ERP” (doar cu ERP) ale comenzilor recente'],
        'acasa-celula-client' => ['ce' => 'adaugat', 'rand' => "^celula (\\p{Lu}[\\p{L}'. -]*( \\d+)?|[\\w.+-]+@[\\w.-]+)( Client agresiv)?$", 'cale' => '^/admin$',
            'rol' => 'adm', 'motiv' => 'coloana „Client” a comenzilor recente (numele sau, fără nume, adresa de email)'],
    ],
];
