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

    // WP-33: lista comenzilor pe componente: tab-urile de status, bara de filtre (în adresă, cu livrarea și ERP-ul), sortarea
    // din antetul tabelului, bara acțiunilor în masă (aceeași adresă, POST /admin/orders/bulk, aceleași câmpuri; acțiunea e
    // acum butonul apăsat, nu o listă), bifele rândurilor legate de formularul barei și panoul comenzii (sertarul).
    'WP-33' => [
        'masa-bife' => ['ce' => 'adaugat', 'rand' => '^camp POST /admin/orders/bulk input:checkbox order_ids\[\] = \d+$', 'cale' => '^/admin/orders(\?|$)',
            'motiv' => 'bifa fiecărui rând trimite id-ul comenzii direct în formularul barei (order_ids[], atributul form=); înainte un script '
                . 'copia bifele în câmpuri ascunse cu același nume, la trimitere'],
        'masa-actiune-lista' => ['ce' => 'scos', 'rand' => '^(camp POST /admin/orders/bulk select bulk_action|optiuni POST /admin/orders/bulk bulk_action: \|delete\|status\|awb\|shipping\|erp_retry)$',
            'cale' => '^/admin/orders(\?|$)', 'motiv' => 'lista „Acțiune în masă” + „Aplică pe selecție” devine câte un buton pe acțiune (rândul următor)'],
        'masa-butoane' => ['ce' => 'adaugat', 'rand' => '^buton POST /admin/orders/bulk bulk_action = (awb|status|erp_retry|shipping|delete) -> POST /admin/orders/bulk$',
            'cale' => '^/admin/orders(\?|$)', 'motiv' => 'butoanele barei acțiunilor în masă: aceleași valori ale lui bulk_action, aceeași adresă; '
                . '„Confirmă” și „Schimbă statusul” trimit amândouă bulk_action=status (Confirmă cu statusul „În procesare”)'],
        'masa-date' => ['ce' => 'adaugat', 'cale' => '^/admin/orders(\?|$)',
            'rand' => '^data (data-tasta-comenzi = [acp]|data-bulk-status = processing|data-adm-masa-tabel = comenzi-tabel|data-adm-confirmare = .+)$',
            'motiv' => 'bara acțiunilor în masă: scurtăturile A/C/P, statusul pus de „Confirmă”, tabelul ei, întrebările dinaintea trimiterii '
                . '(aceleași texte ca vechile confirmări)'],
        'filtre-camp-cautare' => ['ce' => 'adaugat', 'rand' => '^camp GET /admin/orders input:search q$', 'cale' => '^/admin/orders(\?|$)',
            'motiv' => 'căutarea din bara de filtre e un câmp de căutare (același nume q)'],
        'filtre-camp-text' => ['ce' => 'scos', 'rand' => '^camp GET /admin/orders input:text q$', 'cale' => '^/admin/orders(\?|$)',
            'motiv' => 'același câmp q, acum de tip search'],
        'filtre-status-sort' => ['ce' => 'scos', 'cale' => '^/admin/orders(\?|$)',
            'rand' => '^(camp GET /admin/orders select (status|sort)|optiuni GET /admin/orders status: \|pending\|pending_payment\|processing\|completed\|cancelled\|refunded\|failed\|returned'
                . '|optiuni GET /admin/orders sort: date\|status\|total)$',
            'motiv' => 'statusul se alege din tab-uri, sortarea din antetul coloanelor (aceiași parametri status, sort, dir în adresă)'],
        'filtre-noi' => ['ce' => 'adaugat', 'cale' => '^/admin/orders(\?|$)',
            'rand' => '^(camp GET /admin/orders select (livrare|erp)|optiuni GET /admin/orders livrare: \|fara_awb\|cu_awb\|fanbox|optiuni GET /admin/orders erp: \|trimise\|asteptare\|esuate)$',
            'motiv' => 'filtrele noi ale machetei: livrarea (doar cu livrarea FAN) și ERP-ul (doar cu ERP); citite de lista și de export'],
        'filtre-date' => ['ce' => 'adaugat', 'rand' => '^data (data-adm-tasta = /|data-adm-filtre-automat = )$', 'cale' => '^/admin/orders(\?|$)',
            'motiv' => 'bara de filtre: „/” mută focusul în căutare, listele se aplică imediat'],
        'tab-uri-stare' => ['ce' => 'adaugat', 'rand' => '^link /admin/orders\?(search=\d+(&status=[a-z_]+)?|status=[a-z_]+)$', 'cale' => '^/admin/orders(\?|$)',
            'motiv' => 'tab-urile de status (Toate, Noi, În procesare…), cu restul filtrelor păstrate'],
        'sortare-antet' => ['ce' => 'adaugat', 'rand' => '^link \?(search=\d+&)?sort=(date|total|status)&dir=(asc|desc)$', 'cale' => '^/admin/orders(\?|$)',
            'motiv' => 'sortarea din antetul coloanelor Data, Sumă, Status (componenta tabel)'],
        'sortare-veche' => ['ce' => 'scos', 'rand' => '^link /admin/orders\?(search=\d+&)?dir=(asc|desc)$', 'cale' => '^/admin/orders(\?|$)',
            'motiv' => 'butonul ↕️ al direcției sortării, înlocuit de antetul coloanelor'],
        'resetare' => ['ce' => 'scos', 'rand' => '^link /admin/orders$', 'cale' => '^/admin/orders\?search=',
            'motiv' => '„Reset” apare doar cu un filtru ales (componenta bara-filtre); pe lista căutată, tab-ul „Toate” păstrează căutarea'],
        'panou-comanda' => ['ce' => 'adaugat', 'rand' => '^data data-adm-panou = comanda-panou$', 'cale' => '^/admin/orders(\?|$)',
            'motiv' => 'ochiul fiecărui rând deschide comanda în panoul din dreapta (sertarul), cu același conținut ca vechea fereastră'],
    ],
];
