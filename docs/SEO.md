# SEO în nucleu

Ce face nucleul pentru Google pe orice site, ce ține de pluginuri și ce stă în
datele fiecărui site. Portat din livrările SEO ale Bioscem (`Nou-site-bioscem`), cu
același comportament, dar fără nimic scris pentru un anumit site:

| Livrarea Bioscem | Commit-uri | În CMS |
|---|---|---|
| 1 (19 octombrie 2026): robots, sitemap, adresa unică, harta adreselor vechi, date structurate, viteză | `80bb4a4`…`6d29b37`, unite în `f5eafa3` | portată (`c2b9331`) |
| 2 (20 octombrie 2026): categoriile pe `/categorie/{slug}`, titluri și descrieri, câmpuri SEO pe categorii și mărci, logo-ul WebP, livrarea și returul în datele structurate, poza de distribuire, fluxul Merchant Center, textele Google ale primei pagini, a 3 categorii și 20 de produse | `6c8c789`…`0250480` | portată (WP-R, 20 octombrie; `docs/de-portat.md`) |
| 3, 4 (29 octombrie, 5 noiembrie) | — | de portat când apar (WP-R) |

| Fișier | Ce e |
|---|---|
| `src/Support/SeoRedirects.php` | Adresa unică (gazdă, „/” final) și harta adreselor vechi; rulează în `public/index.php`, înaintea sesiunii |
| `src/Support/Robots.php` | Regulile din robots.txt (și pentru sitemap) |
| `src/Support/Sitemap.php` | `/sitemap.xml`, din baza de date, la fiecare cerere |
| `src/Support/JsonLd.php` | Datele structurate (produs, ofertă, firimituri, firmă, site) |
| `src/Support/ImageDimensions.php` | width/height ale pozelor (cache în `storage/cache/image-dimensions.json`) |
| `src/Support/Slug.php` | Slug-urile de marcă, etichetă și categorie (aceleași în pagini și în sitemap) |
| `src/Support/DateSite.php` | Datele unui site: `redirecturi.json` și `seo.json` din dosarul site-ului |
| `src/Support/LinkuriCategorii.php` | Categoriile pe `/categorie/{slug}`: linkurile din pagini, antet, meniu și subsol, ținta celor vechi (catalog) |
| `src/Support/SeoMeta.php` | Titlurile și descrierile Google, numele magazinului din titluri |
| `src/Support/LogoAntet.php` | Logo-ul din antet ca WebP mic, cu PNG-ul ca rezervă |
| `src/Support/PoliticaLivrare.php` | Livrarea și returul pentru Google (date structurate, flux), din datele site-ului (magazin) |
| `src/Support/ImagineDistribuire.php` | Poza de distribuire 1200×630 (`/og/…`) pentru Facebook și WhatsApp |
| `src/Support/FluxGoogleMerchant.php` | Fluxul de produse pentru Google Merchant Center, `/feed/google-merchant.xml` (magazin) |
| `scripts/seo-texte.php` | Aplică textele Google scrise de mână ale unui site (`seo-texte.json` din dosarul lui) |
| `site-uri/bioscem/` | Pachetul de date Bioscem (harta și regulile SEO din livrarea 1) |
| `tools/dev/site-demo/` | Pachetul de date inventat al site-ului de dezvoltare (folosit de teste) |
| `tools/dev/test-seo.php` | Testele SEO (vezi mai jos) |

## 1. O singură adresă pentru fiecare pagină

`SeoRedirects` se ocupă **doar de cererile GET și HEAD**, înainte de sesiune:

- **Gazda**: varianta cu sau fără `www` a gazdei din `APP_URL` (plus ce e trecut în
  `SEO_GAZDE_ALIAS`, separat prin virgulă) primește 301 la gazda din `APP_URL`, pe
  https. Doar când `APP_URL` e pe https și e un domeniu real (nu localhost, nu IP),
  deci niciodată în dezvoltare. Orice altă gazdă (adresa tehnică a găzduirii, un IP)
  rămâne neatinsă. **Nu există regulă de schemă** pe gazda corectă: http → https îl
  face găzduirea (pe Hostinger, marginea CDN-ului; trebuie lăsat pornit), iar o
  regulă de schemă în PHP ar trimite fiecare pagină la ea însăși dacă serverul din
  spatele CDN-ului n-ar vedea https. Cine are deja produse în coș sau contul
  deschis pe gazda veche rămâne acolo (cookie-ul de sesiune e legat de gazdă);
  roboții, vizitatorii noi, sesiunile goale și cookie-urile inventate se mută.
- **Calea**: `/x/` și `/x//y` → `/x` și `/x/y` (prima pagină rămâne `/`), doar
  pentru paginile care există: o pagină inexistentă își păstrează 404-ul.
- **Litere mari**: produsul, categoria (și numele categoriei scris altfel, de
  exemplu `/categorie/vitamine-si-minerale` → slug-ul salvat), marca, eticheta și
  pagina găsite sub altă formă primesc 301 la forma salvată
  (`SeoRedirects::redirectCanonic`, din controllere).
- **Categoriile** (pluginul catalog; livrarea 2, Bioscem `6c8c789`, `919357a`): fiecare are
  adresa ei, `/categorie/{slug}`. `/magazin?category=X` și `/magazin?categorie=X` (și
  `/Magazin…`, `/magazin/…`, pe www) primesc un singur 301 la `/categorie/{slug}`, cu
  sortarea (`sort`) și parametrii de reclamă (`gclid`, `utm_*`…) păstrați; cu alt parametru
  (de exemplu căutarea `q`), cu două categorii sau cu o categorie care nu există, adresa
  rămâne cum era. Linkurile vechi din pagini, din antetul, meniul și subsolul salvate în
  Design se scriu la afișare pe adresa nouă (`LinkuriCategorii::rescrie`), doar cu
  catalogul pe site; paginile de coș, comandă, cont și admin rămân exact ca înainte.
- Un singur salt până la ținta finală (și când aplicația însăși redirecționează,
  de exemplu potrivirea aproximativă a unui produs); parametrii din adresă (`gclid`,
  `utm_*`…) se păstrează; un 301 nu trimite `Set-Cookie` unui vizitator fără
  sesiune; `Cache-Control: no-store` pe 301 (altă valoare: `SEO_REDIRECT_CACHE`), ca
  o întoarcere la versiunea anterioară să se vadă imediat.

**Nu se redirecționează niciodată** (`SeoRedirects::caiExcluse()`):

- orice cerere care nu e GET/HEAD (confirmările de plată, formularele);
- lista nucleului: `/admin`, `/api`, `/auth`, `/webhook`, `/plata`, `/checkout`,
  `/cos`, `/contul-meu`, `/login`, `/register`, `/newsletter`, `/health`,
  `/review-form`, `/gdpr-agreements`, `/contact/send`, fișierele (`/uploads`,
  `/assets`, `/storage`), `/feed`, `/.well-known`, `/cdn-cgi`, `/robots.txt`,
  `/sitemap.xml`, `/favicon.ico`, `/_andaxi` (adresele platformei, de exemplu
  pingul monitorizării); prefixe: `/cos` prinde `/cos` și `/cos/…`, nu și
  `/cosmetice`;
- **fiecare adresă „mereu deschisă” din catalogul pluginurilor**
  (`rute_mereu_deschise` din `config/plugins.php`, partea dinaintea primului
  parametru), oricare ar fi starea pluginului: webhook-urile Stripe, EuPlătesc, BT,
  notificările ERP, întoarcerea de la bancă, linkurile din newsletter. Un plugin nou
  cu o adresă de confirmare o declară acolo și e exclus automat;
  `tools/dev/verifica-rute.php` pică dacă o adresă mereu deschisă n-ar fi exclusă;
- căile din `seo.json` → `cai_excluse` ale site-ului.

**Oprire de urgență**: `SEO_REDIRECTURI=0` în `.env` oprește tot stratul (sitemap-ul,
robots.txt și datele structurate rămân).

## 2. Adresele vechi ale site-ului (harta)

Nucleul nu conține nicio regulă. Fiecare site are lista lui, `redirecturi.json` în
dosarul site-ului:

```json
{
  "exacte": [
    ["/brand/farabella", "/marca/farabella", 301, "motivul"],
    ["/wp-login.php", null, 410, "rest tehnic WordPress"]
  ],
  "prefixe": [
    ["/wp-admin/", null, 410, "rest tehnic WordPress"]
  ]
}
```

- Adresa veche: calea, fără parametri; `/x/`, `/X` și `/x?utm=…` se potrivesc toate
  cu `/x` (cheia e decodată, cu litere mici, fără „/” final). Prefixele prind tot
  ce începe așa.
- 301 → adresa nouă (doar o cale din același site: `https://…` sau `//…` se ignoră);
  410 → „dispărut definitiv”, cu pagina 404 a site-ului.
- Se aplică **doar** unde aplicația ar răspunde 404/410 sau cu o potrivire ghicită:
  o pagină care există nu e acoperită niciodată de hartă.
- Un lanț (A → B, B → C) se urmează într-un singur salt; o buclă, un lanț care
  ajunge la un 410 și o regulă spre ea însăși rămân 404. Lanțul se urmează și prin
  regula unei adrese care între timp a devenit pagină (ca în Bioscem): nu lăsa
  reguli pe adrese care există.
- O țintă care e pagina unui plugin pe care site-ul nu-l are (de exemplu `/magazin`
  pe un site fără catalog) nu se folosește: adresa veche rămâne 404.
- Reguli de conținut (planul SEO Bioscem, §8.2): ținta răspunde 200, e indexabilă și
  are legătură cu subiectul; fără redirecționări în masă spre prima pagină; fără ținte
  care ies din Google (etichete scoase din sitemap); un produs șters fără înlocuitor
  sigur rămâne 404; regulile rămân cel puțin un an.

## 3. robots.txt

Reguli exacte (`Robots::DISALLOW`), fiecare cu pluginul ei: robots.txt pomenește
doar adresele pluginurilor de pe site. Coșul e blocat prin căile lui reale
(`/cos$`, `/cos?`, `/cos/`), nu cu prefixul `/cos`, care ar bloca și `/cosmetice`.
Site-ul poate adăuga reguli (`seo.json` → `robots_disallow`, de exemplu pagina lui
de coș din constructor). Ale nucleului, pe orice site: `/admin`, `/api/`, `/auth/`,
`/webhook/` și `/_andaxi/` (adresele platformei, de la WP-07; pentru Bioscem e o
diferență declarată, `site-uri/bioscem/ce-se-schimba.md`). Linia `Sitemap:` arată mereu
`APP_URL/sitemap.xml`.
Înaintea regulilor stă `Allow: /feed/google-merchant.xml` (`Robots::ALLOW`, doar cu pluginul
magazin): fluxul pentru Merchant Center e sub `/feed`, pe care altfel nu-l blochează nimic,
dar regula îl lasă explicit deschis robotului Google.
**În mentenanță robots.txt rămâne cel normal**: paginile răspund 503 cu
`Retry-After`, pe când un `Disallow: /` ar fi ținut minte de Google până la o zi.
**Pe site-ul de verificare** (`SITE_COPIE_VERIFICARE=1`) e invers: poarta răspunde ea
la `/robots.txt` cu `Disallow: /`, iar fiecare răspuns are
`X-Robots-Tag: noindex, nofollow, noarchive` ([VERIFICARE.md](VERIFICARE.md)).

## 4. sitemap.xml

`/sitemap.xml` se construiește din baza de date la fiecare cerere (`Sitemap::urls`),
doar cu paginile care răspund 200 și doar ale pluginurilor de pe site:

| Al cui | Ce intră |
|---|---|
| nucleu | prima pagină, paginile publicate (fără coș, checkout, cont, login, înregistrare, 404, acorduri GDPR și fără paginile pluginurilor ascunse), `/contact` |
| catalog | `/magazin`, categoriile și mărcile cu cel puțin un produs activ, etichetele după regula site-ului, produsele active (fără cele inactive sau șterse) |
| blog | `/blog` (doar dacă are articole) și articolele publicate |

Nimic din ce blochează robots.txt; `<lastmod>` din data ultimei modificări (la
categorii, mărci, etichete și `/magazin`: cel mai nou produs al lor). Etichetele
intră de la `seo.json` → `sitemap.eticheta_minim_produse` produse active (implicit
1), plus `etichete_pastrate` (dacă au produse), niciodată `etichete_excluse`.
Lipsa din sitemap nu schimbă pagina etichetei.

`public/.htaccess` (și `tools/dev/router.php`) trimit `/sitemap.xml` mereu la
aplicație, ca un fișier vechi rămas pe server să nu-l acopere. Butonul din Setări
magazin → Sitemap șterge fișierul vechi `public/sitemap.xml` (cu alt nume de fișier
scrie, ca înainte, o copie statică).

## 5. Date structurate (JSON-LD)

Un bloc `<script type="application/ld+json">` pentru fiecare tip, în `<head>`.
Doar date care se văd în pagină; adrese absolute (din `APP_URL`).

| Pagina | Ce se scrie |
|---|---|
| prima pagină (nucleu) | `Organization` (nume, adresa site-ului, logo, email, telefon, `sameAs`) și `WebSite` |
| produs (catalog) | `BreadcrumbList` (Acasă → categorie → produs); `Product` după regulile de mai jos |
| categorie (catalog) | `BreadcrumbList` (Acasă → categorie) |

**Product și Offer, pe pluginuri** (Google cere la un `Product` cel puțin una dintre
`offers`, `review`, `aggregateRating`; altfel îl raportează ca eroare și nu-l folosește):

- **Offer** doar cu magazinul **pornit**: prețul afișat (cu reducerea activă), în
  RON; stocul după ERP (`InStock`/`OutOfStock`), precomanda ca `PreOrder` (doar cu
  pluginul precomenzi pornit); `NewCondition`; `priceValidUntil` doar când reducerea
  are o perioadă reală. Fără magazin pe site nu se văd prețuri; când magazinul se
  oprește, produsul nu se mai poate cumpăra: în ambele cazuri, fără Offer.
- **aggregateRating/review** doar din recenzii reale aprobate, care se văd pe pagină
  (pluginul recenzii). **Fără stele inventate.**
- Fără ofertă și fără recenzii (de exemplu un catalog de prezentare fără recenzii):
  **nu se scrie deloc Product**, doar firimiturile.
- Produsele inactive nu primesc Product.
- **Livrarea și returul** (`shippingDetails`, `hasMerchantReturnPolicy`; livrarea 2, Bioscem
  `dfcf6bd`, `1e46f9f`; `PoliticaLivrare`) doar cu magazinul pornit și doar pentru un site
  care are condițiile confirmate de proprietar în `seo.json` → `livrare_retur` (fără ele,
  nimic). Livrarea vine din Setări livrare → prețuri fixe (exact ce încasează checkout-ul):
  doar tariful la adresă (nu și cel la punctele de ridicare), gratuit doar pentru un produs la
  preț întreg care singur atinge pragul în toată țara; la reducere sau între pragurile București
  și provincie, nimic; fără prețuri fixe active, nimic. Termenul de livrare
  (`deliveryTime`) doar când e scris în `termen_livrare`. Returul (zilele, pagina cu
  condițiile, cine plătește transportul înapoi) se scrie și la firmă
  (`Organization.hasMerchantReturnPolicy`). Oprire: `SEO_LIVRARE_RETUR=0`.

**Organization**: numele din Setări magazin (`store_name`), altfel `seo.json` →
`organizatie.nume`, altfel `APP_NAME` (același nume ca în titluri, `SeoMeta::numeMagazin`); email și telefon din setări sau din linkurile
`mailto:`/`tel:` din antet și subsol; logo doar dacă are cel puțin 112×112 px și e
aproape pătrat (cel mult 1,5:1): întâi pozele din `seo.json` → `organizatie.logo`,
apoi iconița site-ului; `sameAs` doar cu profiluri sociale reale din antet și subsol.

## 6. Viteza paginilor (LCP, CLS)

- Pozele produsului (carusel, galerie, pagina fără șablon) și cardurile din catalog
  primesc `width`/`height` din fișier (`ImageDimensions`); înălțimea afișată rămâne
  proporțională (`height:auto`).
- Prima poză a produsului: `loading="eager" fetchpriority="high"`; restul „leneșe”.
- Stilurile cardurilor din catalog stau înaintea cardurilor.
- **Logo-ul din antet** (livrarea 2, Bioscem `a14e803`; `LogoAntet`): dacă site-ul are în
  `seo.json` → `logo_antet` PNG-ul logo-ului, mărimea lui și două variante WebP (fișiere ale
  pachetului site-ului, copiate peste `public/` la adoptare), `<img>`-ul lui din antet e
  învelit într-un `<picture>` cu ele; browserele fără WebP și roboții primesc PNG-ul. Fără
  configurare, fără un fișier sau cu alt PNG (altă mărime), antetul rămâne exact cum e.

## 7. Titluri, descrieri și distribuire

Livrarea 2, Bioscem `93d803f`, `0250480`, `7455b77`, `6a42030` (`SeoMeta`,
`ImagineDistribuire`). Oprire de urgență: `SEO_TITLURI=0` (titlurile și descrierile rămân
cele scrise, fără regula de mai jos).

- **Titlul** fiecărei pagini se termină cu numele magazinului: `store_name` din Setări
  magazin → SEO Google, altfel numele implicit al site-ului (`seo.json` → `organizatie.nume`,
  altfel `APP_NAME`; același nume ca în `og:site_name` și în `Organization`). Produsul: titlul
  scris în admin, doar curățat (fără „|”, „–”, „-” rămase la final, fără sufixul vechi al
  site-ului din `seo.json` → `titluri.sufixe_vechi`, la Bioscem „BIOSCEM”, fără marca de două
  ori); un titlu care doar repetă numele și/sau marca se face din nume, „{nume} – {marcă} |
  {magazin}”, marca și magazinul doar cât încap în 60 de caractere, numele niciodată tăiat.
  Cuvintele din câmpul mărcii care nu deosebesc marca (lista nucleului plus
  `titluri.cuvinte_generice_marca`, la Bioscem „harrison”) nu contează la „marca e deja în nume”.
- **Descrierea**: cea scrisă în admin; altfel, la produs, din descrierea scurtă (tăiată la
  cuvânt); la categorie, marcă, etichetă și `/magazin`, una făcută din produsele afișate
  (câte sunt, mărcile, categoriile, prețul de la care pornesc; fără pluginul magazin, fără
  preț și fără „Comandă online”).
- **Câmpuri SEO** pe categorii și pe mărci (Admin → Categorii; pluginul catalog): titlul și
  descrierea pentru Google, salvate în `seo_pages`.
- **Distribuirea** (nucleu): `og:title`, `og:description`, `og:url`, `og:site_name`, `og:locale`
  (`ro_RO`), `og:image` cu mărimea și textul alternativ, `twitter:card`. Poza: a paginii, altfel
  poza din Setări magazin → SEO Google, altfel logo-ul site-ului (`seo.json` →
  `distribuire.logo`); dintr-o poză încărcată (`/uploads/products/`, `/uploads/gallery/`) se
  face o singură dată varianta 1200×630, sub 300 KB, la adresa `/og/{versiune}/uploads/…`
  (servită de `public/index.php` înaintea sesiunii, păstrată în `storage/cache/og/`).
- **Textele scrise de mână** ale unui site (livrarea 2, Bioscem `7c561fa`): `seo-texte.json` în
  dosarul site-ului (`{nume_magazin, asteptat, texte: [{tip: home|categorie|produs, slug,
  titlu_vechi, titlu_nou, descriere_veche, descriere_noua}]}`) și
  `php scripts/seo-texte.php --plan | --aplica | --restaureaza`: `--plan` arată doar ce s-ar
  schimba, `--aplica` scrie totul într-o tranzacție (după o copie a valorilor de acum) și
  refuză dacă baza nu e cea așteptată (o categorie sau un produs lipsă, un titlu pe care
  regula de mai sus l-ar reface), `--restaureaza` pune copia la loc. Bioscem:
  `site-uri/bioscem/seo-texte.json`.

## 8. Fluxul pentru Google Merchant Center

`/feed/google-merchant.xml` (livrarea 2, Bioscem `d277727`, `1e46f9f`; `FluxGoogleMerchant`;
pluginul magazin): produsele active cu prețul afișat (și reducerea activă, cu perioada ei),
disponibilitatea după ERP, pozele reale, marca, GTIN-ul doar valid (fără
`identifier_exists=no`), fără precomenzi; livrarea din `PoliticaLivrare` (ca în datele
structurate). Id-ul produsului: SKU-ul, altfel prefixul site-ului (`seo.json` →
`flux_merchant.prefix_id`, implicit `P-`) și id-ul. `X-Robots-Tag: noindex`; fluxul stă 15
minute în `storage/cache/`; cât magazinul se oprește, canalul rămâne, fără produse, imediat
(nu din cache). Fără pluginul magazin, 404.

## 9. Datele unui site

Dosarul: `SITE_DATE_DOSAR` din `.env` (cale absolută sau relativă la rădăcină); gol =
`<rădăcină>/site` (ignorat de git). Doar fișiere JSON, fără PHP executabil. Un site
fără dosar merge cu valorile implicite (nicio regulă de hartă, etichetele de la 1
produs, logo-ul = iconița). Un fișier stricat se ignoră (o linie în jurnalul PHP).

`seo.json` (toate cheile sunt opționale; cheile `_despre` sunt comentarii):

```json
{
  "sitemap": {"eticheta_minim_produse": 3, "etichete_pastrate": ["vitamina-d"], "etichete_excluse": ["insomnie"]},
  "organizatie": {"nume": "Numele firmei", "logo": ["/uploads/gallery/logo-patrat.png"]},
  "robots_disallow": ["/pagina-de-sistem"],
  "cai_excluse": ["/pagina-de-sistem"],
  "titluri": {"sufixe_vechi": ["NUME VECHI"], "cuvinte_generice_marca": ["cuvânt"]},
  "logo_antet": {"png": "/uploads/gallery/logo.png", "dimensiuni": [977, 240],
                 "webp_1x": "/assets/img/logo-212.webp", "webp_2x": "/assets/img/logo-423.webp"},
  "distribuire": {"logo": "/uploads/gallery/logo.png"},
  "livrare_retur": {"serviciu": "FAN Courier", "termen_livrare": null,
                    "retur": {"zile": 14, "pagina": "/termeni-conditii", "taxe": "client"}},
  "flux_merchant": {"prefix_id": "P-"}
}
```

`livrare_retur` și condițiile lui le confirmă proprietarul site-ului (`termen_livrare`:
`[pregătire min, max, transport min, max]` în zile lucrătoare, sau `null`; `taxe`: `client` sau
`gratuit`). Fișierele publice ale pachetului (de exemplu variantele WebP ale logo-ului) stau în
`site-uri/<site>/public/` și se copiază peste `public/` la adoptare.

**Pachetele site-urilor** stau în `site-uri/<site>/` și se copiază în dosarul site-ului
la instalare (sau la adoptarea codului comun). `site-uri/bioscem/`: 128 de reguli exacte, adică
cele 113 ale livrării 1 (103 × 301, 10 × 410), cele 14 × 301 aprobate de proprietar pe
19 octombrie (Bioscem `9f59e79`: produse și etichete șterse, spre înlocuitorul verificat) și
`/retur-garantie` → `/termeni-conditii` (20 octombrie, Bioscem `8c074f1`), plus
6 prefixe 410; regulile etichetelor D2/D3
(minim 3 produse, 12 etichete mici păstrate, 19 cu nume de boli scoase), numele
„Bioscem Shop” și logo-urile încercate, `/cos-cumparaturi` (pagina de coș din
constructorul Bioscem) blocată în robots.txt și exclusă din redirecționări; din livrarea 2:
sufixul vechi „BIOSCEM”, „harrison” (nu e o marcă), logo-ul din antet și variantele lui WebP
(`site-uri/bioscem/public/assets/img/`), logo-ul de distribuire, livrarea FAN Courier fără
termen (termenul de 1–3 zile lucrătoare vine cu livrarea 4, decizia proprietarului din 20
octombrie), returul de 14 zile din `/termeni-conditii` plătit de client, prefixul `BS-` al
fluxului; textele Google ale primei pagini, a 3 categorii și 20 de produse
(`seo-texte.json`).

## 10. Teste

- `tools/dev/php.sh tools/dev/test-seo.php` (și secțiunea G din `test-pluginuri.php`):
  excluderile (inclusiv fiecare adresă mereu deschisă și fiecare rută de plată, cont,
  admin, API), harta demo și pachetul Bioscem, robots, JSON-LD și sitemap-ul pe
  fiecare pachet (toate, magazin, catalog, catalog + recenzii, prezentare,
  asociație, magazinul „se oprește”, Bioscem; fiecare adresă din sitemap cerută),
  gazda canonică pe un al doilea server cu `APP_URL=https://exemplu-demo.test`
  (inventat; fiecare POST și fiecare cale exclusă pe www răspunde exact ca pe gazda
  corectă, fără buclă pe http, coșul și contul rămân pe www, categoria veche pe www într-un
  singur salt), mentenanța, oprirea de urgență; din livrarea 2: datele din pachet (numele,
  sufixul vechi, prefixul fluxului, returul, livrarea cu prețuri fixe, logo-ul WebP) și
  pachetul demo neutru, opririle `SEO_LIVRARE_RETUR=0` și `AVERTISMENT_PRODUSE=0`, pe fiecare
  pachet `Allow` pentru flux, fluxul (200 sau 404) și `/magazin?category=` (301 sau 404);
- `tools/dev/smoke.sh` (pe fiecare pachet): secțiunea „SEO”;
- `tools/dev/compara-cu-baza.sh`: față de **codul Bioscem însuși** (care are livrarea 1),
  cu datele din `site-uri/bioscem/`: paginile-cheie, `/sitemap.xml`, adresa unică (litere
  mari, „/” final, potrivirea aproximativă a unui produs) și fiecare regulă din harta
  adreselor vechi (cod, `Location`, anteturi, pagină) ies identic. Se cer regulile din
  pachet și cele din `config/redirecturi.php` al codului Bioscem, iar cele două liste
  trebuie să fie aceleași (adresa veche, ținta, codul), deci o regulă nouă a Bioscem
  neportată pică; anteturile includ
  `X-Robots-Tag: noindex` și `Cache-Control` ale sitemap-ului și ale redirecționărilor
  301/410, deci și ele se dovedesc la fiecare livrare portată. Singurele diferențe sunt
  declarate în `$deBioscem` (locul regulii `/cos-cumparaturi` în robots.txt, explicația
  „Sitemap XML” din admin). Fiecare livrare SEO nouă a Bioscem se dovedește la fel, după
  ce „ultimul commit revizuit” din `docs/de-portat.md` se mută la ea. Livrarea 2: față de
  Bioscem `4254ca4`, fără nicio intrare în `$porturiBioscem`: adresele vechi ale
  categoriilor, sortarea, fluxul, poza de distribuire și, în cazurile de salvare, antetul cu
  logo-ul și linkurile vechi, câmpurile SEO ale categoriilor și mărcilor, numele magazinului
  și livrarea cu prețuri fixe (date structurate și flux). Istoric,
  `--baza=f5ae5ea`: diferențele SEO ale livrării 1 față de codul de dinainte sunt scrise
  explicit (datele structurate, unde trebuie să apară; robots.txt; width/height; poza
  principală; stilurile catalogului; textul „Sitemap XML”) și se scot doar ele; cele ale
  livrării 2 și ale avertismentului pe produs (Bioscem `6c8c789` … `4254ca4`: og:site_name și
  og:locale, descrierile și marca în titlul produsului, linkurile și filtrul categoriilor,
  `Allow` pentru flux în robots.txt, câmpurile avertismentului) sunt declarate în
  `$porturiBioscem`, fiecare cu commit-ul Bioscem, pagina (sau paginile) și numărul exact de
  apariții (WP-R, 21 octombrie); o livrare nouă își adaugă acolo declarațiile, în același commit;
- `tools/dev/verifica-rute.php`: fiecare adresă mereu deschisă e exclusă;
- `tools/verificare/amprenta-site.php` (WP-11; [VERIFICARE.md](VERIFICARE.md), „Amprenta
  paginilor”): ce vede Google pe fiecare adresă publică a unui site adevărat (codul,
  `Location`, titlul, robots, canonical, H1, tipurile JSON-LD, linkurile interne,
  formularele, `X-Robots-Tag`, poza de distribuire), din `/sitemap.xml`, linkurile primei
  pagini, adresele vechi ale categoriilor (`/magazin?category=` → 301 într-un salt), fluxul
  și pozele `/og/`; două amprente se compară câmp cu câmp. bioscem.ro față de copia de
  verificare (codul comun pe copia de azi-noapte, amprentată cu `--gazda-sursa=bioscem.ro`:
  linkurile absolute din conținutul importat din WordPress și canonical-urile puse de mână
  sunt ale copiei, ca pe bioscem.ro) e acceptanța părții B pe datele adevărate
  (WP-37…WP-40), iar în noaptea adoptării site-ul live înainte și după. Local, în poarta
  G-calc (`tools/dev/test-amprenta-site.php`): codul Bioscem față de codul comun, aceleași
  câmpuri pe toate adresele, iar corpul diferă doar la `/robots.txt` (diferența din
  `$deBioscem`).

## 11. Ce nu e aici (încă)

- Managerul de redirecționări din admin, jurnalul de 404 și redirecționarea automată
  la schimbarea unui slug (Etapa 1, B2): azi harta se scrie în `redirecturi.json`.
- Noindex pe etichetele mici, H1, linkurile interne, termenul de livrare pentru Google
  (livrările 3–4 Bioscem, apoi în nucleu).
- Livrarea și returul din setări (Etapa 1, B4): azi stau în `seo.json` → `livrare_retur`.
- `HEAD` pe o adresă corectă răspunde 404 (routerul n-are rute HEAD; moștenit, neschimbat).
