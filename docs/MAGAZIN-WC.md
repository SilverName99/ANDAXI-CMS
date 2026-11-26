# Magazinul unui site WooCommerce mutat pe cadrul capturat

Un site WordPress cu Divi, WooCommerce și MailPoet se mută pe CMS pe drumul capturat ([DEPLOY.md](DEPLOY.md), „Adoptarea unui
site WordPress”): paginile rămân octet cu octet cele de pe WordPress, iar ce depinde de vizitator (coșul, checkout-ul, contul,
calendarul, pagina abonamentului) îl randează codul nostru, în markupul site-ului, prin **regiunile dinamice**. Lucrul e împărțit
pe piste, fiecare cu spațiul ei de nume și migrarea ei; pista A (fundația, mai jos) a scris o singură dată tot ce împart pistele:
înregistrările (rutele, pluginurile, cheile de pe `/`, modulele runtime-ului, schema dialectului), cadrul regiunilor și pachetul
`site-uri/ateliere/`. Toate sunt inerte până la pista lor și pe orice alt site: fără `wp-compat.json` → `magazin_wc` nicio rută,
nicio cheie și nicio sursă CSP nouă nu există, iar fără reguli `regiuni` nicio tăiere nu se schimbă (G-cmp, instantaneele rutelor,
G-capturat, G-wp).

Planul mutării (pistele, datele reale ale site-ului, capturile) stă în afara repo-ului; aici e doar ce e în cod.

## A. Fundația (pista A)

### Pornirea: `wp-compat.json` → `magazin_wc`, `mailpoet`

Două chei noi ale stratului de adrese WordPress ([SEO.md](SEO.md), §1b), `true` sau lipsă: `magazin_wc` (magazinul în forma
WooCommerce: rutele, cheile WooCommerce de pe `/`, sursele Stripe din CSP; `App\Support\MagazinWc\Mod::activ()`) și `mailpoet`
(cheile MailPoet de pe `/`; `WpCompat::mailpoet()`). Pachetul `site-uri/ateliere/` le are pe amândouă.

### Regiunile dinamice

Mecanismul (regulile, tăierea, dovada, randarea, cache-ul, clasele corpului și titlul) e descris în [DEPLOY.md](DEPLOY.md),
„Regiunile dinamice”. Catalogul randatorilor (`App\Support\CadruCapturat\Regiuni::CATALOG`) e lista închisă a id-urilor; fiecare
id are felul cache-ului, modurile, pista și **clasa** randatorului, în spațiul de nume al pistei. O pistă își construiește
randatorul scriind doar clasa (o implementare `CadruCapturat\Randator`, cu un constructor fără argumente) și dovada ei în poarta ei;
catalogul nu se schimbă. Până atunci regiunea e „neconstruită”: un document cu ea nu se poate importa (dovada e NU), deci un site nu
poate ajunge pe server cu o regiune înghețată în starea capturii.

| Id | Cache | Moduri | Pista | Clasa (`App\Support\…`) |
|---|---|---|---|---|
| `wc:adauga_in_cos` | pagina | interior | G (cu data, K) | `MagazinWc\RandareProdus` |
| `wc:jsonld_produs` | pagina | interior | G | `MagazinWc\RandareProdus` |
| `wc:magazin_lista` | pagina | interior | G | `MagazinWc\RandareProdus` |
| `wc:cos_notificari` | sesiune | interior | G | `MagazinWc\RandareCos` |
| `wc:cos_produse`, `wc:cos_totaluri` | sesiune | interior; atribut `class` (modulul primește `et_pb_wc_cart_empty` la coșul gol) | G | `MagazinWc\RandareCos` |
| `wc:cos_gol` | sesiune | interior | G | `MagazinWc\RandareCos` |
| `wc:checkout_notificari`, `_facturare`, `_livrare`, `_info`, `_detalii`, `_plata` | sesiune | interior | L | `MagazinWc\RandareCheckout` |
| `wc:comanda_primita` | sesiune | interior | L | `MagazinWc\RandareComandaPrimita` |
| `wc:comanda_plata` | sesiune | interior | L | `MagazinWc\RandareCheckout` |
| `wc:cont` | sesiune | interior | L | `MagazinWc\RandareCont` |
| `wcpc:evenimente` | pagina | atribut `data-events` | K | `DateEvenimente\RandareCalendar` |
| `wcpc:categorii` | pagina | interior | K | `DateEvenimente\RandareCalendar` |
| `mailpoet:pagina` | sesiune | interior | E | `MailPoetCompat\RandarePagina` |
| `articol:titlu`, `articol:meta`, `articol:continut` | pagina | interior | B | `BlogCapturat\RandareArticol` |
| `blog:lista` | pagina | interior | B | `BlogCapturat\RandareLista` |
| `cautare:rezultate` | sesiune | interior | B | `BlogCapturat\RandareCautare` |

O clasă care servește mai multe id-uri sau ambele moduri ale unui id le deosebește prin `ContextRegiune::regiune()`, `mod()` și
`atribut()`. Contractul unui randator (`Randator`): își scapă singur datele (`Regiuni::html`, `Regiuni::attr`, `Regiuni::jsonAttr`;
ieșirea nu mai trece prin jetoane), un randator `pagina` nu citește sesiunea (`ContextRegiune::sesiune` aruncă), iar
`normalizeaza()` declară câmpurile volatile (nonce-urile, cheile rândurilor coșului, id-urile `quantity_<uniqid>`) pentru dovadă.
Clasele corpului pe care le poate cere o regiune sunt lista închisă `SchemaDivi4::CLASE_BODY` (`logged-in`,
`woocommerce-account`, `woocommerce-cart`, `woocommerce-checkout`, `woocommerce-order-received`, `woocommerce-order-pay` și cele
ale endpointurilor contului); schimbarea `woocommerce-no-js` → `woocommerce-js` o face un modul al runtime-ului, ca scriptul inline
al WooCommerce, nu o regiune.

Documentele noi ale capturii (coloana `pagini_capturate.tip`, `VARCHAR`, fără DDL): `produs` (o pagină de produs capturată,
`/product/<slug>/`, regulile cu `"document": "produs:*"`) și șabloanele `sablon_articol`, `sablon_categorie`, `sablon_cautare` (un
articol, o categorie și o căutare capturate, ale căror adrese stau în `privat/import-wordpress.json` → `sabloane`), pe care le
importă pistele G și B.

### Rutele

Înregistrate o singură dată în `src/Http/rute.php`, toate cu condiția `magazin_wc` (`Router::CONDITII`: o rută condiționată nu se
potrivește deloc fără condiție, deci pe celelalte site-uri cererea merge mai departe exact ca înainte, `/cos` și `/checkout` la
coșul și checkout-ul nucleului, restul la `/{slug*}`). Cu condiția, ele stau înaintea rutelor nucleului cu aceleași adrese.
Instantaneele rutelor (`tools/dev/instantanee/`) le arată cu „(doar cu magazin_wc)”, iar testul B al pluginurilor le cere doar pe
un site cu condiția. Până la pista lor, fiecare metodă răspunde 404 (`PistaNeconstruita`): pe o pagină, 404-ul site-ului (cel
capturat, pe cadrul capturat); sub `/api/wc/` JSON-ul scurt al gărzii, `{"ok":false,…}`, cu `Cache-Control: no-store, private`.

| Metoda și adresa | Controllerul și metoda | Proprietarul (config/plugins.php) | Pista |
|---|---|---|---|
| `GET /product/{slug}` | `MagazinWcController::produs` | `catalog` | G |
| `POST /product/{slug}` (adăugarea în coș fără JavaScript) | `MagazinWcController::produsAdauga` | `magazin` | G |
| `GET /cos`, `POST /cos` | `MagazinWcController::cos`, `cosTrimis` | `magazin` | G |
| `GET /checkout` | `MagazinWcController::checkout` | `magazin` | L |
| `GET /checkout/order-received/{comanda}` | `MagazinWcController::comandaPrimita` | `magazin` | L |
| `GET /checkout/order-pay/{comanda}` | `MagazinWcController::comandaPlata` | `magazin` | L |
| `GET`, `POST /my-account` și `/my-account/{endpoint*}` | `ContWcController::cont`, `contTrimis` | `conturi_clienti` | L |
| `GET /api/wc/jeton` | `MagazinWcController::jeton` | `magazin` | G |
| `GET /api/wc/cos-plutitor` | `MagazinWcController::cosPlutitor` | `magazin` | G |
| `POST /api/wc/cos/{actiune}` | `MagazinWcController::cosApi` | `magazin` | G |
| `POST /api/wc/checkout` | `MagazinWcController::checkoutApi` | `magazin` | L |
| `POST /api/wc/stripe/{actiune}` | `MagazinWcController::stripeApi` | `plata_stripe` | H |

Rutele magazinului propriu al CMS-ului (`/magazin`, `/produs/…`, `/cos/…` cu API-ul lui) rămân cum sunt; dacă și cum se închid pe
un site `magazin_wc` hotărăsc pistele G și L.

### Cheile de pe `/` (`App\Support\CompatWp\Interogari`)

Un singur dispecer, chemat o dată din `public/index.php`, după mentenanță și politica CSP, înaintea formularelor capturate și a
cache-ului de pagini. Doar pe calea `/`, doar cu grupul cheii pornit în `wp-compat.json`; o cheie care nu e în listă trece mai
departe neatinsă, iar mai multe chei în aceeași cerere: câștigă prima din ordinea de mai jos. Cu pluginul proprietar oprit, 404-ul
site-ului, în afara cheilor mereu deschise, care ajung la proprietar în orice stare (notificarea Stripe veche, adresele MailPoet din
emailurile trimise: dezabonarea e mereu posibilă, RFC 8058). Până la pista lor, proprietarii răspund 404.

| Cheia | Grupul | Pluginul | Mereu deschisă | Proprietarul | Pista |
|---|---|---|---|---|---|
| `wc-api` (`/?wc-api=wc_stripe`, notificarea Stripe a comenzilor importate) | `magazin_wc` | `plata_stripe` | da | `MagazinWcController::wcApi` | H |
| `wc-ajax` | `magazin_wc` | — | — | 404 în JSON (endpointurile AJAX ale WooCommerce nu se portează; scripturile noastre au `/api/wc/*`) | — |
| `download_file` | `magazin_wc` | `descarcari` | nu | `DescarcariController::descarca` | I |
| `mailpoet_router`, `mailpoet_page` | `mailpoet` | `newsletter` | da | `MailPoetCompat\Router::raspunde($cheie)` (statică) | E |
| `add-to-cart` | `magazin_wc` | `magazin` | nu | `MagazinWcController::adaugaDinAdresa` (ajunge mereu la `/cos/`) | G |

### CSP pe rută

`App\Support\CspSite` are o extensie închisă pe rută (`EXTENSII`, `RUTE_EXTINSE`), doar pe un site `magazin_wc`: `/checkout/…`,
`/my-account/payment-methods/…` și `/my-account/add-payment-method/…` (calea comparată cu litere mici) primesc sursele documentate
ale Stripe.js (`script-src` și `frame-src` `https://js.stripe.com`, `frame-src` `https://hooks.stripe.com`, `connect-src`
`https://api.stripe.com` și `https://merchant-ui-api.stripe.com`); pista H completează lista după pagina CSP a Stripe. Restul
politicii e același; pe orice altă cale și pe orice alt site politica e cea de până acum, octet cu octet (G-regiuni, G-capturat).

### Modulele runtime-ului: seturile

Manifestul `public/assets/compat/divi4/v1/sloturi.json` are `seturi`, grupuri de module adăugate la sfârșitul slotului `final`
(înaintea scriptului unui plugin) doar pe site-urile a căror temă le cere (`tema.json` → `runtime_seturi`, [PACHETE.md](PACHETE.md));
fără cheie, paginile, P1 și amprentele rămân aceleași. Fiecare modul e deocamdată un loc rezervat care nu face nimic; pista lui îl
scrie, iar contractul lui e cel al runtime-ului ([DEPLOY.md](DEPLOY.md), „Runtime-ul compat”): nu face nimic când DOM-ul lui
lipsește, fără excepții, fără scripturi inline.

| Setul | Modulele | Pista |
|---|---|---|
| `comportament` | `comun/v1/comutatoare.js`, `divi4/v1/salvattore.js`, `divi4/v1/video-fundal.js`, `divi4/v1/audio.js`, `divi4/v1/abonare.js` | C |
| `magazin_wc` | `wc/v1/cos-plutitor.js`, `wc/v1/cos.js`, `wc/v1/checkout.js`, `wc/v1/selectie.js`, `wc/v1/cont.js`, `wc/v1/stripe-oc.js` (singurul care încarcă Stripe.js, doar pe rutele cu extensia CSP) | G, L, H |
| `calendar` | `wcpc/v1/calendar.js` | K |

### Schema dialectului

`HtmlCapturat\SchemaDivi4` primește atributele `data-*` ale markupului WooCommerce și Divi pe care le poartă documentele magazinului
(`data-events` JSON; `data-columns`, `data-priority`, `data-product_id`, `data-product_sku`, `data-quantity`, `data-placeholder`
scalare), atributele regiunilor (`REGIUNI_ATRIBUTE`: `data-events`, `class`), forma jetonului unei regiuni (`RE_JETON_REGIUNE`) și
clasele corpului (`CLASE_BODY`). Doar lărgesc politica pentru documentele care au aceste atribute; niciun document de azi nu se
schimbă.

### Pluginurile noi și migrările rezervate

Trei pluginuri noi în catalog, fără rute, meniu sau cronuri până la pista lor ([PLUGINURI.md](PLUGINURI.md)): `descarcari` (cere
`magazin`), `automatizari` (cere `newsletter`), `date_evenimente` (cere `catalog`), cu tabelele migrărilor lor. Tabelele noi ale
pluginurilor existente sunt și ele în catalog (`order_item_meta`, `order_notes` la `magazin`; `metode_plata_salvate`,
`parole_setare` la `conturi_clienti`; `newsletter_mailpoet_cozi`, `newsletter_mailpoet_linkuri`, `buget_trimitere`,
`newsletter_dezabonari`, `newsletter_mailpoet_nepotriviri` la `newsletter`). Numerele migrărilor sunt fixate de acum, ca pistele să nu se ciocnească:

| Nr | Migrarea | Pista |
|---|---|---|
| 0072 | `nucleu/0072_regiuni_capturate` (făcută: tabelul `regiuni_capturate`, [MIGRARI.md](MIGRARI.md)) | A |
| 0073 | `catalog/0073_produse_wc` | D |
| 0074 | `magazin/0074_comenzi_wc` | D |
| 0075 | `descarcari/0075_descarcari` | D (schema), I |
| 0076 | `conturi_clienti/0076_conturi_wp` | D |
| 0077 | `newsletter/0077_mailpoet` | E |
| 0078 | `automatizari/0078_automatizari` | J |
| 0079 | `date_evenimente/0079_date_produse` | K |
| 0080 | `cupoane/0080_cupoane_wc` | D |
| 0081 | `plata_stripe/0081_stripe_oc` (de rezervă: retras în `migrari.lock` dacă pista H nu are nevoie de un tabel) | H |

### Ce dă pista E celorlalte piste (newsletter-ul MailPoet)

Totul în [PLUGINURI.md](PLUGINURI.md), „Newsletter” → „Pe un site mutat de pe MailPoet”. Pe scurt, ce cheamă alte piste, fără să
schimbe codul pistei E:

| Pentru | Ce | Unde |
|---|---|---|
| D, L (conturile) | un cont nou → lista utilizatorilor WordPress | `MailPoetCompat\Sincronizare::contCreat($db, $contId, $email, $prenume, $nume, $ip)` |
| D, I, L (comenzile) | fiecare comandă cu adresa de facturare (și a unui client cu cont) → clientul WooCommerce, lista clienților, prenumele și numele din facturare | `Sincronizare::comanda($db, $email, $prenume, $nume, $contId)` |
| L (checkout) | bifa `mailpoet_woocommerce_checkout_optin` (eticheta, listele și `activ` în setarea `mailpoet_optin_checkout`; cu `activ` oprit, checkout-ul n-o arată și nicio valoare trimisă nu abonează), la plasarea comenzii, în locul lui `comanda()` (o cheamă ea) | `Sincronizare::optinCheckout($db, $email, $prenume, $nume, $bifata, $ip, $setari, $contId)` |
| J (automatizările), oricine trimite | bugetul comun (limita de trimitere importată din MailPoet) | `BugetTrimitere::ia($db)` înaintea unui email care poate aștepta, `consuma($db)` după unul care nu |
| J | momentul „abonare confirmată” (o listă nouă a unui abonat activ) | punctul `abonare.confirmata` din `config/hooks.php` (`NewsletterService::abonareConfirmata`) |
| J | cozile și linkurile emailurilor automatizărilor (adresele vechi merg) | `newsletter_mailpoet_cozi` (`tip` = `automatizare`; `email_automatizare_id` îl scrie J), `newsletter_mailpoet_linkuri` |
| C (antetul, subsolul) | formularele de abonare Divi | `divi4/v1/abonare.js` + `AbonareCapturata` ([DEPLOY.md](DEPLOY.md), „Formularele de abonare”) |

### Licența de dezvoltare și pachetul

`config/plugins.php` → `licente_dev` → `ateliere`: `catalog`, `magazin`, `plata_stripe`, `cupoane`, `conturi_clienti`, `blog`,
`newsletter`, `statistici`, `descarcari`, `automatizari`, `date_evenimente` (fără `livrare_fan`: livrarea e zero, fiecare produs e
un serviciu; `migrare_wordpress` se adaugă doar pe durata mutării). `tools/dev/php.sh tools/dev/licenta.php ateliere`.

Pachetul `site-uri/ateliere/` (numele e neutru): `tema/tema.json` (cadrul capturat, `divi4@1`, seturile `comportament`,
`magazin_wc`, `calendar`), `wp-compat.json` (ca la `pensiune`, plus `magazin_wc` și `mailpoet`), `import-wordpress.json` (drumul
capturat cu dialectul `divi4` și regulile `regiuni` cu selectori neutri: clasele modulelor Divi și WooCommerce, doar pe adresele
WooCommerce ale coșului, checkout-ului și contului, pe paginile de produs, pe documentele-șablon și pe cadrul R al paginilor
MailPoet: `head > title` → jetonul titlului, `h1.entry-title` și `div.entry-content` → `mailpoet:pagina` 0 și 1), `seo.json`, `ce-se-schimba.md`
și `README.md`. Tot ce ține de site (regulile pe paginile al căror nume e al site-ului, adresele șabloanelor, scripturile scoase,
trackerele) stă în `privat/` (git-ignorat), al cărui exemplu, cu valori inventate, îl descrie README-ul pachetului.

### Poarta

**G-regiuni** (`tools/dev/test-regiuni.php`, în `tools/dev/porti.sh`; [DEZVOLTARE.md](DEZVOLTARE.md)): pe o captură inventată și
randatori de probă (`tools/dev/lib/regiuni-proba.php`, încărcați doar de unelte: `tools/dev/lib/router-regiuni.php`), gramatica
regulilor, tăierea, dovada pe octeți, randarea pe un server cu `magazin_wc` și pe unul fără, cache-ul, CSP-ul, rutele și cheile
noi, înregistrările de mai sus.

### Cum aterizează o pistă

1. Scrie clasele din spațiul ei de nume: randatorii numiți în catalog, metodele controllerelor de mai sus (în locul răspunsului
   404), proprietarul cheilor ei, modulele ei din seturi (fișierele există deja).
2. Adaugă dovada fiecărui randator în poarta ei, pe stări inventate, și o rulează pe capturile reale în afara repo-ului.
3. Nu schimbă înregistrările de mai sus; dacă îi lipsește ceva (o rută, un atribut, o clasă a corpului), o cere în fundație.
4. Fișierele generate (`database/migrari/asteptat.json`, `migrari.lock`, `ultima.php`, `tools/dev/instantanee/*`) le reface
   integratorul după fiecare îmbinare, cu generatoarele lor.

## G. Magazinul, partea 1: produsul, coșul, panoul plutitor

Rezervat pistei G.

## H. Plata Stripe

Rezervat pistei H.

## I. Descărcările și emailurile WooCommerce

Rezervat pistei I.

## L. Magazinul, partea 2: checkout-ul, comanda, contul

Rezervat pistei L.
