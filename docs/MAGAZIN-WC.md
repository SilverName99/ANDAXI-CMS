# Magazinul unui site WooCommerce mutat pe cadrul capturat

Un site WordPress cu Divi, WooCommerce și MailPoet se mută pe CMS pe drumul capturat ([DEPLOY.md](DEPLOY.md), „Adoptarea unui
site WordPress”): paginile rămân octet cu octet cele de pe WordPress, iar ce depinde de vizitator (coșul, checkout-ul, contul,
calendarul, pagina abonamentului) îl randează codul nostru, în markupul site-ului, prin **regiunile dinamice**. Lucrul e împărțit
pe piste, fiecare cu spațiul ei de nume și migrarea ei; pista A (fundația, mai jos) scrie o singură dată tot ce împart pistele,
începând cu cadrul regiunilor. Fără reguli `regiuni` nicio tăiere nu se schimbă (G-capturat, G-wp).

Planul mutării (pistele, datele reale ale site-ului, capturile) stă în afara repo-ului; aici e doar ce e în cod.

## A. Fundația (pista A)

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

### Schema dialectului

`HtmlCapturat\SchemaDivi4` primește atributele `data-*` ale markupului WooCommerce și Divi pe care le poartă documentele magazinului
(`data-events` JSON; `data-columns`, `data-priority`, `data-product_id`, `data-product_sku`, `data-quantity`, `data-placeholder`
scalare), atributele regiunilor (`REGIUNI_ATRIBUTE`: `data-events`, `class`), forma jetonului unei regiuni (`RE_JETON_REGIUNE`) și
clasele corpului (`CLASE_BODY`). Doar lărgesc politica pentru documentele care au aceste atribute; niciun document de azi nu se
schimbă.

## G. Magazinul, partea 1: produsul, coșul, panoul plutitor

Rezervat pistei G.

## H. Plata Stripe

Rezervat pistei H.

## I. Descărcările și emailurile WooCommerce

Rezervat pistei I.

## L. Magazinul, partea 2: checkout-ul, comanda, contul

Rezervat pistei L.
