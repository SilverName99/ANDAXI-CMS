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
ieșirea nu mai trece prin jetoane), un randator `pagina` nu citește sesiunea (`ContextRegiune::sesiune` aruncă, iar `$_SESSION` e
un tablou gol cât randează; o scriere în el = 500; docs/DEPLOY.md, „Cache-ul”), iar `normalizari()` își alege, pentru dovadă,
câmpurile volatile doar din lista închisă `Regiuni::NORMALIZARI` (§13 din specificația markupului): `quantity_uniqid` (id-urile
`quantity_<13 hexa>`), `nonce:<numele câmpului>` (nonce-ul de 10 hexa al unui `<input name="…">` sau al parametrului din adresă, nu
orice hexa), `cheie_cos` (cheile de 32 hexa ale rândurilor coșului în `cart[…]`, `data-cart-item-key`, `remove_item=`,
`undo_item=`, `fcwc-qty-…`), `recaptcha` și `atribuire_comanda` (blocurile care există doar pe live: câmpul reCAPTCHA al logării și
`<wc-order-attribution-inputs>`). O normalizare din afara listei e NU la dovadă. Octeții capturați ai regiunii îi dă
`ContextRegiune::capturat()` (la o cerere din `regiuni_capturate`, la dovadă din tăiere): de pildă clasele Divi ale modulelor
coșului, la care randatorul adaugă sau scoate `et_pb_wc_cart_empty`. Un randator care scrie în `<script type="application/ld+json">`
(`wc:jsonld_produs`) își codează datele cu `Regiuni::jsonScript` (`<`, `>`, `&`, ghilimelele ca `\u003C`…); cadrul refuză (500)
o ieșire cu `<` acolo. La dovadă, toate regiunile documentului primesc același context, iar clasele corpului și titlul de la sfârșit
trebuie să fie cele capturate (o regiune care, cu starea capturii, scoate `woocommerce-cart` sau schimbă titlul e NU).
Clasele corpului pe care le poate cere o regiune sunt lista închisă `SchemaDivi4::CLASE_BODY` (`logged-in`,
`woocommerce-account`, `woocommerce-cart`, `woocommerce-checkout`, `woocommerce-order-received`, `woocommerce-order-pay` și cele
ale endpointurilor contului); schimbarea `woocommerce-no-js` → `woocommerce-js` o face un modul al runtime-ului, ca scriptul inline
al WooCommerce, nu o regiune.

Documentele noi ale capturii (coloana `pagini_capturate.tip`, `VARCHAR`, fără DDL): `produs` (o adresă capturată sub
`/product/<slug>/`, `PlanCapturat::RE_PRODUS`, cheia `produs:<sha16>`, regulile cu `"document": "produs:*"`) și șabloanele
`sablon_articol`, `sablon_categorie`, `sablon_cautare` (un articol, o categorie și o căutare capturate) și `sablon_comanda` (comanda
primită, `/checkout/order-received/<n>/?key=…`, randată pentru orice comandă de pista L), câte unul pe intrarea din
`privat/import-wordpress.json` → `sabloane`, cu cheia `sablon:<fel>`, fără cale (nu se servește la o adresă) și regulile
`sablon:<fel>`. O adresă din `sabloane` lipsă din captură (sau cu alt status decât 200) e NU; o regulă `produs:*` sau
`sablon:<fel>` care nu ajunge la niciun document, când captura are pagini de produs sau șablonul e în `sabloane`, e NU. Locurile
regiunilor: niciuna pe `<html>`, `<head>`, `<body>` (doar jetonul `clase_body` pe `class`-ul lui `<body>`) și niciuna `interior`
într-un element cu text brut sau RCDATA (`script`, `style`, `textarea`, `title`, `noscript`, `iframe`, `xmp`…), în afara lui
`<script type="application/ld+json">`.

### Rutele

Înregistrate o singură dată în `src/Http/rute.php`, toate cu condiția `magazin_wc` (`Router::CONDITII`: o rută condiționată nu se
potrivește deloc fără condiție, deci pe celelalte site-uri cererea merge mai departe exact ca înainte, `/cos` și `/checkout` la
coșul și checkout-ul nucleului, restul la `/{slug*}`), în afara abonării, cu condiția `mailpoet`. Cu condiția, ele stau înaintea
rutelor nucleului cu aceleași adrese. Instantaneele rutelor (`tools/dev/instantanee/`) le arată cu „(doar cu magazin_wc)”, iar
testul B al pluginurilor le cere doar pe un site cu condiția. Până la pista lor, fiecare metodă răspunde 404 (`PistaNeconstruita`):
pe o pagină, 404-ul site-ului (cel capturat, pe cadrul capturat); sub `/api/wc/`, la rambursarea din admin și la abonare JSON-ul
scurt al gărzii, `{"ok":false,…}`, cu `Cache-Control: no-store, private` (pe un site cu `antete_404` = `wordpress`, ca ateliere,
și cu antetele nocache ale WordPress: `no-cache, must-revalidate, max-age=0, no-store, private`).

Proprietarii lor stau în `config/plugins.php` → `rute_conditionate` (`['magazin_wc' => […]]`, pe plugin): o declarație de acolo
contează doar pe un site cu condiția ei (`Plugins::declaratiiPentru`, `conditiiSite()`). Pe celelalte site-uri adresele rutelor
condiționate ajung la pagina generală și rămân ale nucleului, ca înainte: aceleași linkuri în pagini (`filtreazaLinkuri`), aceeași
gardă, aceleași pagini din bază (INERT-1, verificat în G-regiuni pe licențele vertij, grafoanaytis, prezentare și toate).
`verifica-rute.php` cere ca o declarație condiționată să câștige doar pe rute cu aceeași condiție, iar uneltele (instantaneele,
raportul rutelor) întreabă o rută condiționată „pe un site cu condiția ei”.

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
| `POST /api/wc/stripe/{actiune}` (`sesiune`, `verifica-plata`) | `MagazinWcController::stripeApi` | `plata_stripe` | H |
| `POST /api/wc/stripe/metode/{actiune*}` (`sterge`, `implicita`, `adauga`, `adauga/confirma`) | `MagazinWcController::stripeMetode` | `plata_stripe` | H |
| `POST /admin/orders/{id}/rambursare-stripe` | `MagazinWcController::rambursareStripe` | `magazin` (comenzile, `/admin/orders/*`) | H |
| `GET /_dev/stripe-js-fals.js` (Stripe.js-ul fals al testelor, `tools/dev/lib/stripe-js-fals.js`; doar cu `APP_ENV=local`, altfel 404) | `MagazinWcController::stripeJsFals` | `plata_stripe` | H |
| `GET /blog/page/{n}`, `GET /category/{slug*}/page/{n}` (paginile 2… ale listelor de articole) | `BlogCapturatController::paginaBlog`, `paginaCategorie` | `blog` | B |
| `POST /_andaxi/abonare/{n}` (condiția `mailpoet`: formularele de abonare Divi) | `AbonareCapturataController::trimite` | `newsletter` | E |

Rutele magazinului și ale conturilor CMS-ului au condiția inversă, `fara_magazin_wc` (decizia D5: pe acest fel de site magazinul e
doar la adresele WooCommerce): `/magazin`, `/categorie/…`, `/marca/…`, `/eticheta/…`, `/produs/…` (și recenzia), `GET /cos` și
POST-urile `/cos/…`, `/api/cart/…`, `/api/checkout/…`, `/api/products/…`, `/api/search/products`, `/api/shop/catalog`,
`/api/fan/…`, `GET` și `POST /checkout`, `GET /checkout/succes/…`, `/plata/…`, `/contul-meu` și `/contul-meu/…`, `/login`,
`/register`, `/auth/google…` (61 de rute). Pe un site `magazin_wc` ele nu se potrivesc: un GET ajunge la pagina generală (404-ul
capturat), un POST la 404-ul routerului, deci niciun al doilea drum de comandă și nicio a doua logare, pe lângă regulile
WooCommerce (data din viitor, validarea câmpurilor checkout-ului, Stripe, apărările formularelor). Pe toate celelalte site-uri
sunt exact ca înainte. Rămân fără condiție adresele mereu deschise (`POST /checkout/succes/…`, `GET /checkout/bt/retur`,
`GET /contul-meu/confirmare/…`, `GET /contul-meu/activare/retur`) și webhook-urile.

După plată, pista H cheamă `App\Support\MagazinWc\DupaPlata::ruleaza($db, $comanda, $sursa)` (locul l-a pus pista A, gol; îl umplu
pistele L și I: emailurile stărilor, permisiunile de descărcare, lista 2 a newsletterului, evenimentul comenzii, stocul). Contractul:
poate fi chemată de mai multe ori pentru aceeași comandă, și în paralel; fiecare efect se întâmplă o singură dată, păzit de propria
lui revendicare. Opțiunile Stripe.js ale checkout-ului (`appearance`, `fonts`) stau în pachet, în `stripe-aspect.json` (scheletul îl
scrie pista A, valorile pista H; `verifica-pachet.php` îi cere forma). Evenimentele Stripe în plus ale magazinului WooCommerce
(`payment_intent.processing`, `charge.refunded`, `charge.refund.updated`, `charge.dispute.created`, `charge.dispute.closed`) stau în
`config/plugins.php` → `magazin` → `stripe_evenimente_conditionate`, deci doar pe un site `magazin_wc` le cer Setări plăți și
verificarea webhook-ului; pe celelalte site-uri lista e cea de dinainte.

### Cheile WordPress din adresă: pe `/` și `?add-to-cart=` (`App\Support\CompatWp\Interogari`)

Un singur dispecer, chemat o dată din `public/index.php`, după mentenanță și politica CSP, înaintea formularelor capturate și a
cache-ului de pagini. Doar pe calea `/` (`add-to-cart`: pe orice cale, ca WooCommerce, doar cu un id numeric), doar cu grupul cheii
pornit în `wp-compat.json`; o cheie care nu e în listă trece mai departe neatinsă, iar mai multe chei în aceeași cerere: câștigă prima
din ordinea de mai jos. O adresă din care parse_url nu scoate o cale (N6, [SECURITATE.md](SECURITATE.md)) nu are nicio cheie. Cu
pluginul proprietar oprit, 404-ul site-ului, în afara cheilor mereu deschise, care ajung la proprietar în orice stare (notificarea
Stripe veche, adresele MailPoet din emailurile trimise: dezabonarea e mereu posibilă, RFC 8058). Până la pista lor, proprietarii
răspund 404 (`add-to-cart`: pagina cerută, fără nimic adăugat).

`add-to-cart` ca pe live (WooCommerce citește `?add-to-cart=<id>` pe orice pagină): proprietarul primește calea cererii
(`adaugaDinAdresa(string $cale): bool`) și trimite antetele nocache ale WooCommerce; după o adăugare reușită răspunde el, 302 la
`/cos/` (true); refuzată (stoc, dată, variantă), pune notificarea în coadă și întoarce false, iar cererea merge mai departe: pagina
adresei cerute, 200, cu notificarea (o regiune `sesiune`, deci fără cache). Un id care nu e numeric nu e o cheie: WooCommerce nu
adaugă nimic, deci nici dispecerul nu se amestecă.

| Cheia | Grupul | Pluginul | Mereu deschisă | Proprietarul | Pista |
|---|---|---|---|---|---|
| `wc-api` (`/?wc-api=wc_stripe`, notificarea Stripe a comenzilor importate) | `magazin_wc` | `plata_stripe` | da | `MagazinWcController::wcApi` | H |
| `wc-ajax` | `magazin_wc` | — | — | 404 în JSON (endpointurile AJAX ale WooCommerce nu se portează; scripturile noastre au `/api/wc/*`) | — |
| `download_file` | `magazin_wc` | `descarcari` | nu | `DescarcariController::descarca` | I |
| `mailpoet_router`, `mailpoet_page` | `mailpoet` | `newsletter` | da | `MailPoetCompat\Router::raspunde($cheie)` (statică) | E |
| `add-to-cart` (pe orice cale, cu un id numeric) | `magazin_wc` | `magazin` | nu | `MagazinWcController::adaugaDinAdresa($cale)`: 302 la `/cos/` după o adăugare reușită, altfel pagina cerută | G |

### CSP pe rută

`App\Support\CspSite` are o extensie închisă pe rută (`EXTENSII`, `RUTE_EXTINSE`), doar pe un site `magazin_wc`: `/checkout/…`,
`/my-account/payment-methods/…` și `/my-account/add-payment-method/…` (calea comparată cu litere mici) primesc sursele documentate
ale Stripe.js (`script-src` și `frame-src` `https://js.stripe.com` și `https://*.js.stripe.com`, `frame-src`
`https://hooks.stripe.com`, `connect-src` `https://api.stripe.com` și `https://merchant-ui-api.stripe.com`); gazdele cu `*` doar aici,
în constanta din cod (`tema.json` și trackerele nu pot lărgi `script-src`). Pista H completează lista după pagina CSP a Stripe. Restul
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
clasele corpului (`CLASE_BODY`). Runda 1 de reparații (SPEC-A-03) adaugă, adunate cu `Divi4::transforma` și politica `import` de
pe toate documentele capturii reale (doar numele, unealta în afara repo-ului): `data-checkout-session` și `data-parsley-names`
(JSON); `data-cart-item-key`, `data-coupon`, `data-input-classes`, `data-label`, `data-installed`, `data-order_button_text`,
`data-payment-method-type`, `data-count`, `data-automation-id`, `data-value`, `data-parsley-errors-container`, `data-toggle`,
`data-shortcode_index`, `data-mdb-original-title` (text fără `<`, ca `data-title`), `data-mdb-tooltip-init`,
`data-mdb-tooltip-initialized`, `data-start`, `data-end` (scalare). `HtmlCapturat::TAGURI_HTML` primește singurul element
personalizat al formularelor WooCommerce, `<wc-order-attribution-inputs>` (gol; fără scriptul atribuirii comenzii, care nu se
portează, e inert). După ele, pe documentele capturii reale (cu scripturile necunoscute scoase), politica nu mai refuză coșul,
checkout-ul, contul sau paginile simple; rămân doar documentele care nu sunt pagini (foile XSL ale sitemap-urilor, oEmbed) și
iframe-urile din afara listei albe (pista C). Doar lărgesc politica pentru documentele care au aceste atribute; niciun document
de azi nu se schimbă.

### Pluginurile noi și migrările rezervate

Trei pluginuri noi în catalog, fără rute, meniu sau cronuri până la pista lor ([PLUGINURI.md](PLUGINURI.md)): `descarcari` (cere
`magazin`), `automatizari` (cere `newsletter`), `date_evenimente` (cere `catalog`), cu tabelele migrărilor lor. Tabelele noi ale
pluginurilor existente sunt și ele în catalog (`order_item_meta`, `order_notes` la `magazin`; `metode_plata_salvate`,
`parole_setare` la `conturi_clienti`; `newsletter_mailpoet_cozi`, `newsletter_mailpoet_linkuri`, `buget_trimitere` la
`newsletter`). Numerele migrărilor sunt fixate de acum, ca pistele să nu se ciocnească:

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

### Licența de dezvoltare și pachetul

`config/plugins.php` → `licente_dev` → `ateliere`: `catalog`, `magazin`, `plata_stripe`, `cupoane`, `conturi_clienti`, `blog`,
`newsletter`, `statistici`, `descarcari`, `automatizari`, `date_evenimente` (fără `livrare_fan`: livrarea e zero, fiecare produs e
un serviciu; `migrare_wordpress` se adaugă doar pe durata mutării). `tools/dev/php.sh tools/dev/licenta.php ateliere`.

Pachetul `site-uri/ateliere/` (numele e neutru): `tema/tema.json` (cadrul capturat, `divi4@1`, seturile `comportament`,
`magazin_wc`, `calendar`), `wp-compat.json` (ca la `pensiune`, plus `magazin_wc` și `mailpoet`), `import-wordpress.json` (drumul
capturat cu dialectul `divi4` și regulile `regiuni` cu selectori neutri: clasele modulelor Divi și WooCommerce, doar pe adresele
WooCommerce ale coșului, checkout-ului și contului, pe paginile de produs și pe documentele-șablon), `seo.json`, `ce-se-schimba.md`
și `README.md`. Tot ce ține de site (regulile pe paginile al căror nume e al site-ului, adresele șabloanelor, scripturile scoase,
trackerele) stă în `privat/` (git-ignorat), al cărui exemplu, cu valori inventate, îl descrie README-ul pachetului.

### Poarta

**G-regiuni** (`tools/dev/test-regiuni.php`, în `tools/dev/porti.sh`; [DEZVOLTARE.md](DEZVOLTARE.md)): pe o captură inventată și
randatori de probă (`tools/dev/lib/regiuni-proba.php`, încărcați doar de unelte: `tools/dev/lib/router-regiuni.php`), gramatica
regulilor, tăierea, dovada pe octeți, randarea pe un server cu `magazin_wc` și pe unul fără, cache-ul, CSP-ul, rutele și cheile
noi, înregistrările de mai sus.

**Porțile pistei** (`tools/dev/porti.sh --wp=MAGAZIN-WC-A`: G-rute G-regiuni G-capturat G-compat G-wp G-html5 G-html-capturat
G-deploy-wp G-plug G-cmp G-smoke G-seo G-php) și ce cer ca să ruleze (IG-06):

- G-rute: verificarea 11b cere lista neagră a pachetului `ateliere` (lista canonică a site-ului, din afara repo-ului, cu rândul
  `# site: ateliere`, dată prin `CMS_DEV_LISTA_NEAGRA`; la fel lista pachetului `pensiune`); fără ea pică, cum trebuie.
- G-smoke și G-php: testul de fum rulează `test-securitate.php`, care pornește MySQL 8 pentru a doua jumătate a verificării
  limitelor; într-un mediu în care MySQL 8 nu are voie să pornească, cu `CMS_DEV_DOAR_MARIADB=1` verificarea e doar pe MariaDB
  (rândul ei o spune).
  `test-admin-faza-d.php` (tot din testul de fum) cere arborele codului Bioscem scos de `compara-cu-baza.sh`, deci G-cmp înaintea
  lui (ordinea din `porti.sh`); G-cmp cere valorile private ale înghețării Bioscem (`tools/inghetare/genereaza-setari.php
  --site=bioscem`, în `site-uri/bioscem/privat/`).
- G-plug (și testele pluginurilor din G-php) citesc comenzile demo ale bazei de dezvoltare (`cms_dev`): după o rulare întreruptă
  (un test de fum oprit la jumătate poate lăsa baza fără comenzi), baza se face din nou cu `tools/dev/instaleaza.sh` înaintea
  porților; altfel pică „API-urile catalogului” (lista celor mai vândute e goală) și „se oprește” (comanda existentă nu se găsește),
  fără nicio legătură cu codul.
- Testul de fum al pachetului însuși (`SITE_DATE_DOSAR=site-uri/ateliere tools/dev/smoke.sh --pachet=ateliere --fara-capturi`) nu e
  o poartă: e scris pentru un pachet cu magazinul CMS-ului, deci pe ateliere are 32 de eșecuri așteptate (106 trecute la runda 1 de
  reparații), toate din deciziile de mai sus:
  - rutele magazinului și ale conturilor CMS-ului închise cu `magazin_wc` (D5, 404): categoria, marca, cele patru produse (și
    datele structurate ale produsului, adresa lui cu „/” final, produsele din sitemap), căutarea, `POST /cos/adauga/{id}` (de
    două ori), `GET /cos`, `/api/cart/summary`, `GET` și `POST /checkout` (deci nici comanda din bază, nici emailul ei),
    `GET /login`, `GET /register`, `POST /login`, antetul gărzii pe `POST /produs/{slug}/review` (ruta nu mai există);
  - stratul de adrese WordPress (`slash_final`): `/magazin`, `/blog`, un articol și o pagină legală răspund 301 spre adresa cu „/”;
  - cadrul capturat fără documente capturate (baza demo, nu un import): `/contact` și adresa veche din datele demo răspund 404-ul;
  - `GET /contul-meu` cu un client: pagina demo cu secțiunea contului trimite la `/login` (302), care e închisă.
  Fără `magazin_wc` (orice alt pachet) testul de fum e cel de dinainte.

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
