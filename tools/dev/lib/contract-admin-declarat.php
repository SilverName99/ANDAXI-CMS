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
            'rand' => '^data (data-tasta-comenzi = [acp]|data-bulk-status = processing|data-adm-masa-tabel = comenzi-tabel|data-adm-confirmare = .+'
                . '|data-implicit-transport = )$',
            'motiv' => 'bara acțiunilor în masă: scurtăturile A/C/P, statusul pus de „Confirmă”, tabelul ei, întrebările dinaintea trimiterii '
                . '(aceleași texte ca vechile confirmări); butonul implicit ascuns al formularului („Corectează transportul”, primul, ca Enter '
                . 'în câmpul transportului să nu trimită altă acțiune)'],
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

    // WP-34: editorul produsului pe secțiuni. Doar așezarea: niciun nume, tip, valoare ascunsă sau opțiune nu se schimbă.
    'WP-34' => [
        'cuprins-linkuri' => ['ce' => 'adaugat', 'rand' => '^link #produs-(baza|pret|stoc|imagini|descriere|campuri|bbd|precomanda|afisare|avertisment|seo)$',
            'cale' => '^/admin/products$', 'motiv' => 'cuprinsul editorului (stânga): câte un link spre fiecare secțiune; Oferte BBD, Precomandă și '
                . 'Avertismentul doar cu pluginul lor'],
        'cuprins-date' => ['ce' => 'adaugat', 'rand' => '^data data-produs-sectiune = produs-(baza|pret|stoc|imagini|descriere|campuri|bbd|precomanda|afisare|avertisment|seo)$',
            'cale' => '^/admin/products$', 'motiv' => 'legătura dintre linkul cuprinsului și secțiunea lui (produs.js: derularea și secțiunea vizibilă)'],
        'cautare-tasta' => ['ce' => 'adaugat', 'rand' => '^data data-adm-tasta = /$', 'cale' => '^/admin/products$',
            'motiv' => '„/” mută focusul în căutarea listei de produse (filtre.js)'],
    ],

    // WP-35: restul paginilor pe componente și propunerile aprobate IA-01 … IA-10 (docs/ARHITECTURA-ADMIN.md). O pagină mutată
    // (unită cu alta sau devenită tab-ul alteia) are rândul „mutat” (contract_mutata: nimic din ce trimitea nu lipsește la noua
    // adresă). Tab-urile paginilor de setări sunt linkuri cu ?tab= (adresa ține tab-ul), iar părțile lor sunt deja în pagină
    // (taburi.js le arată pe rând), deci formularele rămân aceleași.
    'WP-35' => [
        // Meniul (IA-02, IA-05, IA-06, IA-10: doar etichetele, care nu intră în contract).
        'meniu-oferte-bbd' => ['ce' => 'adaugat', 'rand' => '^link /admin/products/oferte-bbd$',
            'motiv' => 'IA-05: intrarea nouă „Oferte BBD” din Produse (doar cu pluginul oferte_bbd și doar pentru cine are voie la produse)'],
        'meniu-sabloane-produse' => ['ce' => 'scos', 'rand' => '^link /admin/products/templates$',
            'motiv' => 'IA-02: „Câmpuri suplimentare” și „Template-uri produse” sunt o intrare, „Câmpuri și șabloane”; șabloanele sunt tab-ul ei'],
        'meniu-coduri-unice' => ['ce' => 'scos', 'rand' => '^link /admin/coupons/unique$',
            'motiv' => 'IA-06: „Cupoane unice” e tab-ul „Coduri unice” al paginii Cupoane, fără intrare proprie în meniu'],
        // Cupoane (IA-06) și Câmpuri și șabloane (IA-02): tab-urile paginii unite.
        'cupoane-tab' => ['ce' => 'adaugat', 'rand' => '^link /admin/coupons$', 'cale' => '^/admin/coupons',
            'motiv' => 'IA-06: tab-ul „Cupoane” al paginii unite (pe pagina codurilor unice și pe pagina cupoanelor)'],
        'campuri-tab' => ['ce' => 'adaugat', 'rand' => '^link /admin/products/fields$', 'cale' => '^/admin/products/fields$',
            'motiv' => 'IA-02: tab-ul „Câmpuri suplimentare” al paginii „Câmpuri și șabloane” (pe pagina șabloanelor ține locul vechiului buton „← Câmpuri suplimentare”)'],
        // Emailurile (IA-01): fiecare bucată își vede doar tab-urile ei.
        'emailuri-taburi-scoase' => ['ce' => 'scos', 'cale' => '^/admin/emails/newsletters\?tab=',
            'rand' => '^link /admin/emails/newsletters\?tab=(templates|campaigns|ecommerce|subscribers|optin|contact_forms|stats|history)$',
            'motiv' => 'IA-01: bara cu opt tab-uri amestecate se împarte: Newsletter (4 tab-uri), Emailuri de comandă (3), Mesaje de contact, Emailuri trimise și Statistici'],
        'emailuri-comanda-taburi' => ['ce' => 'adaugat', 'cale' => '^/admin/emails/(sender|test|newsletters\?tab=ecommerce)$',
            'rand' => '^link /admin/emails/(sender|test|newsletters\?tab=ecommerce)$',
            'motiv' => 'IA-01: tab-urile „Emailuri de comandă” (cu magazinul), „Setări trimitere”, „Trimite email test”, pe paginile lor'],
        // Clienți (IA-10, IA-07): lista și importul ca tab-uri (aceleași adrese ?panel=), punctele clientului în fișa lui.
        'clienti-taburi' => ['ce' => 'adaugat', 'rand' => '^link /admin/users\?panel=(list|import)$', 'cale' => '^/admin/users(\?|/\d+$|$)', 'rol' => 'adm',
            'motiv' => 'tab-urile „Toți clienții” și „Import clienți” sunt linkuri (?panel=, ca adresa la care ducea scriptul vechi)'],
        'clienti-taburi-vechi' => ['ce' => 'scos', 'cale' => '^/admin/users(\?|/\d+$|$)', 'rol' => 'adm',
            'rand' => '^data (data-admin-simple-tabs = |data-admin-simple-(tab|panel) = (list|import)|data-default-tab = (list|import))$',
            'motiv' => 'butoanele vechi ale celor două tab-uri și scriptul lor (care încărca aceeași adresă cu ?panel=)'],
        'fisa-puncte-celule' => ['ce' => 'adaugat', 'cale' => '^/admin/users/\d+$', 'rol' => 'adm',
            'rand' => '^celula ([+-]?\d+|-|\d{4}-\d\d-\d\d \d\d:\d\d:\d\d|[a-z_]+|Puncte pentru comanda \d+|Bonus demo acordat din admin)$',
            'motiv' => 'IA-07: istoricul punctelor clientului (data, tipul, punctele, soldul, comanda, adminul, detaliile), în fișa lui'],
        'fisa-puncte-ajustare' => ['ce' => 'adaugat', 'rand' => '^link /admin/users/points\?tab=clienti$', 'cale' => '^/admin/users(/\d+|/points)$', 'rol' => 'adm',
            'motiv' => 'IA-07: „Ajustează punctele” din fișa clientului și tab-ul „Puncte clienți” al paginii Fidelitate'],
        'fidelitate-taburi' => ['ce' => 'adaugat', 'cale' => '^/admin/users/points$', 'rol' => 'adm',
            'rand' => '^(link /admin/users/points\?tab=(program|promo|istoric|import)|data data-adm-tab(-panou)? = (program|promo|clienti|istoric|import)|data data-adm-taburi-panouri = )$',
            'motiv' => 'IA-07: tab-urile paginii Fidelitate (Program, Reguli promo, Puncte clienți, Toate tranzacțiile, Import), părți ale paginii'],
        'fidelitate-taburi-vechi' => ['ce' => 'scos', 'cale' => '^/admin/users/points$', 'rol' => 'adm',
            'rand' => '^data (data-loyalty-(tab|panel) = (settings|adjust|promo|history|claimed|unclaimed|import)|data-loyalty-tabs = |data-default-tab = settings)$',
            'motiv' => 'IA-07: cele șapte butoane vechi; adresele lor (?panel=claimed …) deschid tab-ul nou'],
        'fidelitate-istoric-client' => ['ce' => 'adaugat', 'rand' => '^(link /admin/users/\d+#client-puncte|celula Istoricul clientului)$', 'cale' => '^/admin/users/points$', 'rol' => 'adm',
            'motiv' => 'IA-07: butonul fiecărui client cu puncte duce la istoricul lui din fișa clientului (în locul ferestrei „Detalii”)'],
        'fidelitate-fereastra-veche' => ['ce' => 'scos', 'cale' => '^/admin/users/points$', 'rol' => 'adm',
            'rand' => "^(data data-loyalty-history-btn = |data data-user-id = \d+|data data-user-name = .+|celula (Detalii|Selectează un cont pentru a vedea istoricul\.))$",
            'motiv' => 'IA-07: fereastra „Detalii” cu istoricul unui client (încărcat din /admin/users/points/history, adresă care rămâne) iese de pe pagină'],
        // Setări magazin / site (IA-03) și Google (IA-04).
        'google-mutat' => ['ce' => 'adaugat', 'rand' => '^mutat 302 -> /admin/settings/store\?tab=google$', 'cale' => '^/admin/settings/google$', 'rol' => 'adm',
            'motiv' => 'IA-04: pagina Google e partea „Google” a tab-ului „SEO și Google” din Setări magazin / Setări site; adresa veche duce acolo'],
        'google-meniu' => ['ce' => 'scos', 'rand' => '^link /admin/settings/google$', 'rol' => 'adm',
            'motiv' => 'IA-04: intrarea „Google” din Setări iese din meniu (pagina e un tab al Setărilor magazin / site)'],
        'google-in-setari' => ['ce' => 'adaugat', 'cale' => '^/admin/settings/store$', 'rol' => 'adm',
            'rand' => '^(form POST /admin/settings/google|camp POST /admin/settings/google (input:checkbox google_(ads|analytics|tag_manager)_enabled = '
                . '|input:hidden code_fields_b64 = 1|input:text google_(ads_conversion_id|ads_conversion_label|analytics_id|site_verification|tag_manager_id)'
                . '|textarea google_(analytics_code|tag_manager_body_code|tag_manager_head_code)))$',
            'motiv' => 'IA-04: formularul paginii Google (aceeași adresă, aceleași câmpuri), în tab-ul „SEO și Google”'],
        'setari-taburi' => ['ce' => 'adaugat', 'cale' => '^/admin/settings/store$', 'rol' => 'adm',
            'rand' => '^(link /admin/settings/store\?tab=(general|magazin|seo|cookies|viteza|chat)|data data-adm-tab(-panou)? = (general|magazin|seo|cookies|viteza|chat)'
                . '|data data-adm-taburi-panouri = )$',
            'motiv' => 'IA-03: cele șase tab-uri (General, Magazin, SEO și Google, Cookie și popup, Viteză, Chat live), fiecare cu partea lui din pagină'],
        'setari-taburi-vechi' => ['ce' => 'scos', 'cale' => '^/admin/settings/store$', 'rol' => 'adm',
            'rand' => '^data (data-store-settings-(tab|panel) = (pages|sitemap|branding|seo|clarity|chat|maintenance|numerotare|tva|quantity|widgets|caching|popup|cookies)'
                . '|data-store-settings-tabs = |data-default-tab = pages)$',
            'motiv' => 'IA-03: cele 14 butoane de dinainte și părțile lor; adresele lor (?tab=tva …) deschid tab-ul nou la partea lor'],
        // Setări livrare (IA-08).
        'livrare-taburi' => ['ce' => 'adaugat', 'cale' => '^/admin/settings/shipping$', 'rol' => 'adm',
            'rand' => '^(link /admin/settings/shipping\?tab=(livrare|fan)|data data-adm-tab(-panou)? = (livrare|fan)|data data-adm-taburi-panouri = )$',
            'motiv' => 'IA-08: tab-urile „Livrare” și „Curier FAN”; contul FAN rămâne în formularul livrării (atributul form=)'],
        'livrare-taburi-vechi' => ['ce' => 'scos', 'cale' => '^/admin/settings/shipping$', 'rol' => 'adm',
            'rand' => '^data (data-shipping-settings-(tab|panel) = (fan-localities|fan-streets|fan-extra-km|fanbox|fan-api|delivery-settings)'
                . '|data-shipping-settings-tabs = |data-default-tab = fan-localities|data-shipping-go-tab = delivery-settings)$',
            'motiv' => 'IA-08: cele șase tab-uri de dinainte; adresele lor (?tab=fanbox …) deschid „Curier FAN” la partea lor'],
        // Setări plăți (IA-09).
        'plati-taburi' => ['ce' => 'adaugat', 'cale' => '^/admin/settings/payments', 'rol' => 'adm',
            'rand' => '^(link /admin/settings/payments\?tab=(op|euplatesc|stripe)|data data-adm-tab(-panou)? = (op|euplatesc|stripe)|data data-adm-taburi-panouri = )$',
            'motiv' => 'IA-09: câte un tab pe fiecare fel de plată (Ordin de plată, Card – EuPlătesc, Card – Stripe), părți ale aceluiași formular; BT rămâne ?tab=bt'],
        'plati-tab-vechi' => ['ce' => 'scos', 'rand' => '^link /admin/settings/payments$', 'cale' => '^/admin/settings/payments', 'rol' => 'adm',
            'motiv' => 'IA-09: tab-ul unic „EuPlătesc, Stripe, ordin de plată” devine cele trei tab-uri de mai sus'],
    ],
];

