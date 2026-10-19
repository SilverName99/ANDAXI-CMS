# SEO în nucleu

Ce face nucleul pentru Google pe orice site, ce ține de pluginuri și ce stă în
datele fiecărui site. Portat din livrarea 1 SEO a Bioscem (19 octombrie 2026,
`Nou-site-bioscem`, commit-urile `80bb4a4`…`6d29b37`, unite în `f5eafa3`), cu
același comportament, dar fără nimic scris pentru un anumit site.

| Fișier | Ce e |
|---|---|
| `src/Support/SeoRedirects.php` | Adresa unică (gazdă, „/” final) și harta adreselor vechi; rulează în `public/index.php`, înaintea sesiunii |
| `src/Support/Robots.php` | Regulile din robots.txt (și pentru sitemap) |
| `src/Support/Sitemap.php` | `/sitemap.xml`, din baza de date, la fiecare cerere |
| `src/Support/JsonLd.php` | Datele structurate (produs, ofertă, firimituri, firmă, site) |
| `src/Support/ImageDimensions.php` | width/height ale pozelor (cache în `storage/cache/image-dimensions.json`) |
| `src/Support/Slug.php` | Slug-urile de marcă, etichetă și categorie (aceleași în pagini și în sitemap) |
| `src/Support/DateSite.php` | Datele unui site: `redirecturi.json` și `seo.json` din dosarul site-ului |
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
  `/sitemap.xml`, `/favicon.ico` (prefixe: `/cos` prinde `/cos` și `/cos/…`, nu și
  `/cosmetice`);
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
de coș din constructor). Linia `Sitemap:` arată mereu `APP_URL/sitemap.xml`.
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
- Livrarea și returul (`shippingDetails`, `hasMerchantReturnPolicy`) **nu** se scriu
  până nu le confirmă proprietarul site-ului (vor veni din setări, Etapa 1, B4).

**Organization**: numele din Setări magazin (`store_name`), altfel `seo.json` →
`organizatie.nume`, altfel `APP_NAME`; email și telefon din setări sau din linkurile
`mailto:`/`tel:` din antet și subsol; logo doar dacă are cel puțin 112×112 px și e
aproape pătrat (cel mult 1,5:1): întâi pozele din `seo.json` → `organizatie.logo`,
apoi iconița site-ului; `sameAs` doar cu profiluri sociale reale din antet și subsol.

## 6. Viteza paginilor (LCP, CLS)

- Pozele produsului (carusel, galerie, pagina fără șablon) și cardurile din catalog
  primesc `width`/`height` din fișier (`ImageDimensions`); înălțimea afișată rămâne
  proporțională (`height:auto`).
- Prima poză a produsului: `loading="eager" fetchpriority="high"`; restul „leneșe”.
- Stilurile cardurilor din catalog stau înaintea cardurilor.

## 7. Datele unui site

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
  "cai_excluse": ["/pagina-de-sistem"]
}
```

**Pachetele site-urilor** stau în `site-uri/<site>/` și se copiază în dosarul site-ului
la instalare (sau la adoptarea codului comun). `site-uri/bioscem/`: cele 113 reguli
ale livrării 1 (103 × 301, 10 × 410, plus 6 prefixe 410), regulile etichetelor D2/D3
(minim 3 produse, 12 etichete mici păstrate, 19 cu nume de boli scoase), numele
„Bioscem Shop” și logo-urile încercate, `/cos-cumparaturi` (pagina de coș din
constructorul Bioscem) blocată în robots.txt și exclusă din redirecționări.

## 8. Teste

- `tools/dev/php.sh tools/dev/test-seo.php` (și secțiunea G din `test-pluginuri.php`):
  excluderile (inclusiv fiecare adresă mereu deschisă și fiecare rută de plată, cont,
  admin, API), harta demo și pachetul Bioscem, robots, JSON-LD și sitemap-ul pe
  fiecare pachet (toate, magazin, catalog, catalog + recenzii, prezentare,
  asociație, magazinul „se oprește”, Bioscem; fiecare adresă din sitemap cerută),
  gazda canonică pe un al doilea server cu `APP_URL=https://exemplu-demo.test`
  (inventat; fiecare POST și fiecare cale exclusă pe www răspunde exact ca pe gazda
  corectă, fără buclă pe http, coșul și contul rămân pe www), mentenanța, oprirea de
  urgență;
- `tools/dev/smoke.sh` (pe fiecare pachet): secțiunea „SEO”;
- `tools/dev/compara-cu-baza.sh`: față de **codul Bioscem însuși** (care are livrarea 1),
  cu datele din `site-uri/bioscem/`: paginile-cheie, `/sitemap.xml`, adresa unică (litere
  mari, „/” final, potrivirea aproximativă a unui produs) și fiecare regulă din harta
  adreselor vechi (cod, `Location`, anteturi, pagină) ies identic; anteturile includ
  `X-Robots-Tag: noindex` și `Cache-Control` ale sitemap-ului și ale redirecționărilor
  301/410, deci și ele se dovedesc la fiecare livrare portată. Singurele diferențe sunt
  declarate în `$deBioscem` (locul regulii `/cos-cumparaturi` în robots.txt, explicația
  „Sitemap XML” din admin). Fiecare livrare SEO nouă a Bioscem se dovedește la fel, după
  ce „ultimul commit revizuit” din `docs/de-portat.md` se mută la ea. Istoric,
  `--baza=f5ae5ea`: diferențele SEO față de codul de dinainte sunt scrise explicit
  (datele structurate, unde trebuie să apară; robots.txt; width/height; poza principală;
  stilurile catalogului; textul „Sitemap XML”) și se scot doar ele;
- `tools/dev/verifica-rute.php`: fiecare adresă mereu deschisă e exclusă.

## 9. Ce nu e aici (încă)

- Managerul de redirecționări din admin, jurnalul de 404 și redirecționarea automată
  la schimbarea unui slug (Etapa 1, B2): azi harta se scrie în `redirecturi.json`.
- Titluri, descrieri, câmpuri SEO pe categorii, `og:image`, fluxul pentru Merchant
  Center, noindex pe etichetele mici (livrările 2–4 Bioscem, apoi în nucleu).
- `HEAD` pe o adresă corectă răspunde 404 (routerul n-are rute HEAD; moștenit, neschimbat).
