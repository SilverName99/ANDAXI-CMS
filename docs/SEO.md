# SEO în nucleu

Ce face nucleul pentru Google pe orice site, ce ține de pluginuri și ce stă în
datele fiecărui site. Portat din livrările SEO ale Bioscem (`Nou-site-bioscem`), cu
același comportament, dar fără nimic scris pentru un anumit site:

| Livrarea Bioscem | Commit-uri | În CMS |
|---|---|---|
| 1 (19 octombrie 2026): robots, sitemap, adresa unică, harta adreselor vechi, date structurate, viteză | `80bb4a4`…`6d29b37`, unite în `f5eafa3` | portată (`c2b9331`) |
| 2 (20 octombrie 2026): categoriile pe `/categorie/{slug}`, titluri și descrieri, câmpuri SEO pe categorii și mărci, logo-ul WebP, livrarea și returul în datele structurate, poza de distribuire, fluxul Merchant Center, textele Google ale primei pagini, a 3 categorii și 20 de produse | `6c8c789`…`0250480` | portată (WP-R, 20 octombrie; `docs/de-portat.md`) |
| 4 (21 octombrie 2026): termenul de livrare din setări, regula D (gratuit de la prag și la reducere, ca în coș), noindex pe căutare și pe /blog gol, 404 fără canonical și pagina 404 a magazinului pe /blog/x și /categorie/x, H1 din titlu, width/height și pozele mari ca WebP (mărimile pozelor vechi de pe disc, `070e1af`), „Produse similare” în HTML, HEAD cu majuscule, scriptul de conținut (AIRCALL, linkurile din texte) | `5359ec6`…`070e1af` | portată (WP-R, 28 octombrie; `docs/de-portat.md`) |
| 3 | — | de portat când apare (WP-R) |

| Fișier | Ce e |
|---|---|
| `src/Support/SeoRedirects.php` | Adresa unică (gazdă, „/” final) și harta adreselor vechi; rulează în `public/index.php`, înaintea sesiunii |
| `src/Support/Redirectionari.php` | Harta adreselor vechi în baza de date (`seo_redirects`; WP-38): verificările unei reguli noi, pachetul ca sămânță, CSV, redirecționările automate, copia citită de `SeoRedirects` |
| `src/Support/Jurnal404.php` | Jurnalul paginilor negăsite (`seo_404_log`; WP-38), fără date personale, cu păstrare limitată |
| `src/Support/Robots.php` | Regulile din robots.txt (și pentru sitemap) |
| `src/Support/Sitemap.php` | `/sitemap.xml` (indexul) și `/sitemap-{tip}.xml` (WP-39), din baza de date, la fiecare cerere |
| `src/Support/JsonLd.php` | Datele structurate (produs, ofertă, firimituri, firmă, site) |
| `src/Support/ImageDimensions.php` | width/height ale pozelor (cache în `storage/cache/image-dimensions.json`) |
| `src/Support/Slug.php` | Slug-urile de marcă, etichetă și categorie (aceleași în pagini și în sitemap) |
| `src/Support/DateSite.php` | Datele unui site: `redirecturi.json` și `seo.json` din dosarul site-ului (formatul pachetului: [PACHETE.md](PACHETE.md)) |
| `src/Support/LinkuriCategorii.php` | Categoriile pe `/categorie/{slug}`: linkurile din pagini, antet, meniu și subsol, ținta celor vechi (catalog) |
| `src/Support/SeoMeta.php` | Titlurile și descrierile Google, numele magazinului din titluri |
| `src/Support/LogoAntet.php` | Logo-ul din antet ca WebP mic, cu PNG-ul ca rezervă |
| `src/Support/PoliticaLivrare.php` | Livrarea și returul pentru Google (date structurate, flux), din datele site-ului (magazin) |
| `src/Support/ImagineDistribuire.php` | Poza de distribuire 1200×630 (`/og/…`) pentru Facebook și WhatsApp |
| `src/Support/FluxGoogleMerchant.php` | Fluxul de produse pentru Google Merchant Center, `/feed/google-merchant.xml` (magazin) |
| `src/Support/SeoIndexare.php` | noindex pe căutarea internă și pe /blog gol, canonicalul pe 404, pagina 404 a magazinului pe /blog/x și /categorie/x, H1-ul din titlu (livrarea 4) |
| `src/Support/PozeOptimizate.php` | width/height pe pozele din șabloane; pozele mari afișate din fișierele WebP ale pachetului (livrarea 4) |
| `src/Support/ProduseSimilare.php` | „Produse similare” din aceeași categorie, cu cardurile în HTML (livrarea 4) |
| `src/Support/Seo.php` | Modelul SEO al paginilor (WP-37): rândul `seo_pages` al fiecărui fel de pagină, valorile finale din `<head>`, verificările panoului din admin |
| `src/Support/MarciEtichete.php` | Mărcile și etichetele produselor ca entități (`product_brands`, `product_tags`, `product_tag_links`; WP-37) |
| `views/admin/componente/panou-seo.php`, `public/assets/js/admin/seo.js` | Panoul SEO din admin, același în fiecare editor (WP-37) |
| `src/Support/VarianteImagini.php`, `scripts/optimizeaza-imagini.php` | Pozele în mărimile ecranului: variantele WebP și `srcset` (WP-40, B5) |
| `src/Support/SesiuneLaNevoie.php`, `src/Support/SablonSite.php`, `views/sablon-site/` | Sesiunea doar la nevoie; CSS-ul și JavaScript-ul șablonului în fișiere (WP-40, B6) |
| `src/Support/SursaComenzii.php`, `views/admin/sursa-comenzilor.php` | Sursa comenzilor și raportul ei (WP-40, B9) |
| `scripts/comutatoare-seo.php` | Comutatoarele WP-40 pe un site, din linia de comandă (`--arata`, `--porneste`, `--opreste`) |
| `scripts/seo-texte.php` | Aplică textele Google scrise de mână ale unui site (`seo-texte.json` din dosarul lui) |
| `scripts/seo-continut.php` | Schimbă o dată conținutul din bază al unui site: produse redenumite, linkuri din texte (`seo-continut.json` din dosarul lui) |
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

Nucleul nu conține nicio regulă. Din WP-38 harta stă în baza de date (`seo_redirects`, migrarea `nucleu/0046`) și se
schimbă din admin (Setări → Redirecționări și 404, §2b); fiecare site are și lista lui de pornire, `redirecturi.json` în
dosarul site-ului (sămânța, mai jos):

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

## 2b. Managerul redirecționărilor, jurnalul 404, redirecționările automate (WP-38, SEO partea B, B2)

**Harta din bază, pachetul ca sămânță.** Regulile din `redirecturi.json` intră în `seo_redirects` ca rânduri „pachet”,
exact cum sunt (aceleași ținte, coduri, prefixe și lanțuri: harta se aplică la fel ca înainte), singure la prima cerere de
după ce fișierul s-a schimbat (semnătura lui: mărimea și momentul) și din admin („Reia regulile din pachet”). O regulă din
pachet editată în admin devine „manuală” (pachetul nu o mai atinge: sincronizarea scrie doar rândurile `source = 'pachet'`);
una ștearsă din admin doar se oprește și rămâne oprită ca „piatră de mormânt” (pachetul nu o readuce, nici după ce iese din
fișier); una pornită scoasă din fișier dispare și din tabel (doar dacă fișierul se citește). **Sincronizarea automată nu
scoate mai mult de o cincime** din regulile pachetului pornite (cel puțin 3): un fișier înlocuit greșit nu golește harta;
atunci nu scoate nimic și scrie o dată pe zi în jurnalul PHP. „Reia regulile din pachet” (adminul) le scoate.
`SeoRedirects` nu citește baza: citește o copie a regulilor pornite, `storage/cache/redirecturi-<site>.json`, refăcută la
fiecare schimbare. Copia poartă o **generație** (`<copie>.generatie`): fiecare schimbare o crește înainte de a reface copia,
iar o copie cu generația mai mică e veche; refacerea (citirea bazei și scrierea copiei) stă sub un lacăt (`<copie>.lacat`,
`flock`), deci două refaceri nu se încrucișează și una mai veche nu scrie peste una mai nouă. Cât copia lipsește, e veche
sau e a altui pachet, cererea folosește regulile din fișier, ca înainte, iar la finalul ei copia se reface din bază (fără
tabel, adică înainte de migrare: o copie doar din pachet, reverificată la 5 minute). Lista din admin nu reface copia la
fiecare deschidere, doar când nu e la zi. **Dosarul cache nescriibil:** harta refăcută rămâne în memorie pentru cererea de
acum, următoarea încercare vine după 5 minute (un fișier-semn `<copie>.esec`, în cache sau în dosarul temporar), eșecul
se scrie în jurnalul PHP și pagina Redirecționări și 404 îl arată. **Aceleași reguli ca până acum:**
doar unde aplicația ar răspunde 404/410 sau ar ghici, un singur salt, parametrii păstrați, fără `Set-Cookie`, căile excluse
(§1) niciodată; `SEO_REDIRECTURI=0` oprește tot. Fiecare aplicare a unei reguli îi numără accesările (`hits`, `last_hit_at`).

**O regulă nouă** (din formular, din CSV, din jurnalul 404, de la ștergerea unei pagini; `Redirectionari::valideaza`):
- adresa veche: o cale a site-ului (o adresă întreagă pe gazda site-ului devine cale), nu prima pagină, **niciodată o cale
  exclusă** (coș, plată, cont, admin, API, confirmări, fiecare adresă mereu deschisă a pluginurilor, `cai_excluse`); un
  prefix nu poate fi nici începutul unei căi excluse (`/c` ar prinde `/cos` și `/checkout`), nici în interiorul uneia;
- nici adresa veche, nici ținta nu au segmente `.`/`..` (scrise sau codate, `%2e`, `%252e`), `\`, spații sau caractere de
  control, verificate și după decodare (`%00`, `%0d%0a`);
- ținta: o pagină a acestui site (o adresă întreagă doar pe gazda site-ului, cu sau fără www; **niciodată alt site**: nici
  `//gazdă`, `\`, `javascript:`, alt port, spații, rânduri noi), nu o cale exclusă; doar 301 și 410 (410 fără țintă);
- **fără buclă și fără lanț**: o regulă spre ea însăși, spre o adresă care trimite deja înapoi (buclă) sau spre o adresă
  redirecționată la rândul ei (lanț; mesajul spune ținta finală) se refuză; o regulă nouă pe ținta altor reguli le
  aplatizează (A → B, apoi B → C: A trimite direct la C). Admin → Redirecționări arată lanțurile rămase din pachet;
- cheia e calea decodată, cu litere mici, fără „/” final; unicitatea e pe amprenta ei (`from_hash`), deci o adresă cu
  diacriticele tăiate e altă regulă decât cea cu diacritice.

**Importul CSV** (Admin → Redirecționări și 404 → Importă din CSV, fișier sau text lipit): un rând pe adresă,
`adresa veche, ținta[, cod][, notă]`, separat prin virgulă, punct și virgulă sau tab; un antet se sare; ținta goală sau
codul 410 = dispărută. Cel mult 2 MB (fișier sau text lipit). Tabelul se citește o dată, fiecare rând trece prin aceleași
verificări pe imaginea din memorie (cu rândurile de deasupra deja puse), iar la final totul se scrie **într-o singură
tranzacție**: fie toate regulile, fie niciuna (cu mesaj); copia hărții se reface oricum. Cele refuzate se arată cu
motivul. Așa se pun, de
exemplu, adresele vechi ale articolelor Biovitality cu diacriticele tăiate din slug (lista e la proprietar, nu în git,
OD-49). Exportul CSV dă toate regulile, cu accesările lor; o celulă care începe cu `=`, `+`, `-`, `@` (sau tab, CR) primește
un apostrof în față, ca un program de calcul să n-o ia drept formulă.

**Redirecționări automate.** Când adminul schimbă slug-ul unui produs, al unei categorii de produse, al unei pagini, al unui
articol sau al unei categorii de blog, adresa veche primește 301 la cea nouă (sursa „Automată”;
`Redirectionari::laSchimbareaAdresei`): regulile care trimiteau la adresa veche trimit la cea nouă, iar o regulă rămasă pe
adresa nouă (acum o pagină) se scoate, deci înapoi la un slug vechi nu face buclă. Totul se verifică întâi și se scrie
într-o singură tranzacție. **Nu înlocuiește o regulă scrisă de om** (manuală, din CSV, din jurnal, de la o ștergere, din
pachet) pe adresa veche: o lasă cum era și adminul vede de ce; dacă un prefix acoperă adresa nouă, nu face nimic și spune
(ar fi un lanț). Slug-ul unui produs se curăță la salvare (fără `/`, `?`, `#`, spații, `.`/`..`, nici codate; un slug trimis
neschimbat rămâne exact cum e în bază). **La ștergere** (produs, categorie,
pagină, articol) pagina următoare din admin propune ce se întâmplă cu adresa veche: 301 spre pagina cea mai apropiată
(categoria produsului, altfel lista produselor; la o categorie lista produselor, fiindcă categoriile n-au părinte; la un
articol blogul; la o pagină prima pagină), 410 sau nimic (404, ca până acum). Doar administratorul general vede propunerea și
pagina managerului. Propunerile stau pe site (`storage/…/seo/propuneri-redirectionare.json`, sub `flock`, cel mult 20), nu
în sesiunea celui care a șters: o ștergere făcută de un admin cu rol parțial (magazin, blog) așteaptă până o vede
administratorul general; una se scoate doar când se arată. Pagina Redirecționări și 404 spune câte așteaptă.

**Jurnalul 404** (`seo_404_log`, `nucleu/0047`; Admin → Redirecționări și 404 → Jurnalul 404): fiecare adresă publică la
care site-ul a răspuns 404 la un GET, de câte ori, prima și ultima dată, și gazda altui site de pe care a venit omul. Doar
calea (cheia hărții), **fără parametri, fără IP, fără browser**; nu intră căile excluse, o cale cu „@”, încercările
roboților de scanare (`.php`, `.env`, `wp-admin`…). Rândurile mai vechi de 90 de zile pleacă singure și jurnalul ține cel
mult 5.000 de adrese (cele văzute cel mai demult pleacă): cele vechi pleacă la fiecare 404, și când cererea nu se notează
și cu jurnalul oprit (curățarea completă, cu limita de 5.000, rulează la întâmplare, cam o dată la 50 de rânduri notate). „@” se caută și codat de mai multe ori
(`%40`, `%2540`) și ca „＠”. Din jurnal: „Redirecționează” (formularul completat cu adresa),
„410”, „Ignoră”. Oprire: `SEO_JURNAL_404=0`.

## 2c. Verificarea adreselor vechi (WP-39, G-M7)

`tools/verificare/verifica-redirecturi.php --lista=<csv> --baza=<url> [--ua=googlebot|browser] [--pachet=<site>]
[--intentionate=<csv>] [--pauza=1] [--poarta=verificare]`: condiția fermă a planului SEO (PLAN-SEO §3 B) — niciun site nu
trece pe codul comun fără lista adreselor lui vechi (sitemap-ul vechi, paginile din Search Console pe 16 luni, linkurile
externe) verificată. Fiecare adresă trebuie să dea 200 (pagină HTML indexabilă, cu canonicalul spre ea), un 301 (sau 308)
într-un singur salt, pe același site, spre o astfel de pagină, sau 410 doar dacă e o regulă 410 a pachetului (`--pachet`)
sau o adresă din `--intentionate` (de exemplu exportul regulilor 410 din admin). Orice altceva (404, 302, lanț, țintă pe alt
site, noindex, canonicalul altei pagini) e o problemă. Scrie câte adrese au dat fiecare rezultat și căile publice care pică,
cu motivul; codul 0 doar fără probleme (1 cu probleme, 2 argumente greșite, fără nicio cerere). Doar GET, fără cookie-uri,
cel puțin o secundă între cereri pe un site care nu e local; parola porții doar în `VERIFICARE_POARTA_PAROLA`, pe https.
Rulează pe verificare cu pachetul site-ului înainte de trecere și pe domeniul live imediat după ([VERIFICARE.md](VERIFICARE.md)).
GR (OD-44): lista e a site-ului GR de azi (sitemap-ul lui + Search Console), care trebuie să răspundă la fel după trecere.

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

**WP-39 (SEO partea B, B3): `/sitemap.xml` e indexul sitemap-urilor pe tipuri** (`<sitemapindex>`, fiecare cu data celei
mai noi adrese din el), construite din baza de date la fiecare cerere (`Sitemap::grupuri`, `Sitemap::index`):
`/sitemap-pagini.xml`, `-produse`, `-categorii`, `-marci`, `-etichete`, `-articole`, `-blog-categorii`, `-blog-etichete`
(`Sitemap::TIPURI`; în index doar tipurile cu adrese; un tip gol sau necunoscut răspunde 404). După adoptare, indexul se
trimite din nou în Search Console (aceeași adresă, `/sitemap.xml`). Doar paginile care răspund 200 și doar ale
pluginurilor de pe site:

| Al cui | Ce intră |
|---|---|
| nucleu (`pagini`) | prima pagină, paginile publicate (fără coș, checkout, cont, login, înregistrare, 404, acorduri GDPR, fără paginile doar pentru membri și fără paginile pluginurilor ascunse), `/contact`, `/blog` (doar dacă are articole) |
| catalog | `/magazin` (pagina catalogului), categoriile și mărcile cu cel puțin un produs activ, etichetele după regula site-ului, produsele active (fără cele inactive sau șterse), cu pozele lor (`<image:image>`: poza principală și galeria, absolute, fără poza goală) |
| blog | articolele publicate (fără cele doar pentru membri), cu poza lor; categoriile active cu cel puțin un articol publicat; etichetele cu cel puțin `seo.json` → `blog.eticheta_minim_articole` articole publicate (implicit 1) |

Nimic din ce blochează robots.txt și **nicio pagină scoasă din Google în panoul SEO** (robots „noindex”, `Seo::refuriNoindex`:
pagină, produs, categorie, marcă, etichetă, lista produselor, articol, categoria și eticheta blogului). **Fiecare adresă are
`<lastmod>`**: data ultimei modificări (la categorii, mărci, etichete și `/magazin`: cel mai nou produs al lor; la `/blog`,
categoriile și etichetele blogului: cel mai nou articol); una fără dată proprie (o rută fixă, `/contact`) ia data celei mai
noi pagini, apoi a celei mai noi adrese din sitemap. **Niciodată `seo_pages.updated_at`**: rândurile de dinainte de
`nucleu/0041` au acolo momentul migrării, nu al unei schimbări a paginii. Etichetele de produse intră de la `seo.json` →
`sitemap.eticheta_minim_produse` produse active (implicit 1), plus `etichete_pastrate` (dacă au produse), niciodată
`etichete_excluse`. Lipsa din sitemap nu schimbă pagina etichetei; o etichetă de blog sub minim e și `noindex,follow` (§5b).

`public/.htaccess` (și `deploy/stub/.htaccess`, `tools/dev/router.php`) trimit `/sitemap.xml` și
`/sitemap-{tip}.xml` mereu la aplicație, ca un fișier vechi rămas pe server să nu le acopere. Butonul din Setări
magazin → Sitemap șterge fișierul vechi `public/sitemap.xml` (cu alt nume de fișier
scrie, ca înainte, o copie statică).

## 5. Date structurate (JSON-LD)

Un bloc `<script type="application/ld+json">` pentru fiecare tip, în `<head>`.
Doar date care se văd în pagină; adrese absolute (din `APP_URL`).

| Pagina | Ce se scrie |
|---|---|
| prima pagină (nucleu) | `Organization` (nume, adresa site-ului, logo, email, telefon, `sameAs`; tipul din `seo.json` → `organizatie.tip`, de exemplu `NGO` la vertij, WP-39 VT-S1) și `WebSite` |
| produs (catalog) | `BreadcrumbList` (Acasă → categorie → produs); `Product` după regulile de mai jos (cu `gtin8/12/13/14` din codul EAN valid al produsului, WP-39) |
| categorie (catalog) | `BreadcrumbList` (Acasă → categorie) și `ItemList` (WP-39) |
| `/magazin` (pagina catalogului), marcă, etichetă (catalog) | `ItemList` (WP-39): adresele produselor afișate, în ordinea din pagină, cel mult 50; nu pe căutare |
| articol (blog; WP-39, BV-B5) | `BlogPosting` (titlul, data publicării și a ultimei schimbări, autorul din Blog → Autori sau firma, poza articolului sau poza de distribuire implicită, descrierea, firma) și `BreadcrumbList` (Acasă → Blog → categoria activă → articolul); cu dată de eveniment și loc, și `Event` (WP-39, VT-S1: începutul, sfârșitul, locul ca `Place`, poza, prețul — „gratuit” = 0 lei — cu linkul biletelor) |
| `/blog`, categoria și eticheta blogului (WP-39) | `ItemList` cu articolele afișate; nu pe căutare |
| pagină doar pentru membri | nimic (nota pentru nemembri nu are date structurate) |

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
  doar tariful la adresă (nu și cel la punctele de ridicare); gratuit când prețul afișat atinge
  pragul în toată țara, și la produsele cu reducere, exact ca în coș (livrarea 4, Bioscem
  `196fa46`, decizia D; `PoliticaLivrare::gratuit` = `CheckoutCalculator::transportGratuit`,
  fără cupon); între pragurile București și provincie, nimic; fără prețuri fixe active, nimic.
  **Termenul de livrare** (`deliveryTime`: `handlingTime`, `transitTime`, `businessDays` luni–vineri;
  în flux `g:min/max_handling_time`, `g:min/max_transit_time`, `g:shipping_*_business_days`;
  livrarea 4, Bioscem `5359ec6`): implicit `termen_livrare` din `seo.json`, peste el cele patru
  setări `seo_livrare_pregatire_min/_max`, `seo_livrare_transport_min/_max` (tabelul settings,
  fără ecran în admin; un număr întreg 0–30, altfel valoarea implicită; un minim peste maxim
  readuce perechea); un site cu `termen_livrare` `null` îl declară doar din toate patru setările.
  Returul (zilele, pagina cu
  condițiile, cine plătește transportul înapoi) se scrie și la firmă
  (`Organization.hasMerchantReturnPolicy`). Oprire: `SEO_LIVRARE_RETUR=0`.
  **Din admin (WP-39, B4, PLAN-SEO D10)**: Setări magazin → SEO Google → „Livrare și retur în Google” (doar cu magazinul):
  „ca în pachet” (implicit: `seo.json` decide), „da, cu valorile de mai jos” sau „nu”; numele livrării în flux, cele patru
  numere ale termenului, zilele de retur, pagina condițiilor (o cale din site), cine plătește transportul înapoi. Un câmp gol
  ia valoarea din `seo.json` (arătată în câmp); fără nicio valoare, nu se spune nimic (doar condițiile completate apar).
  Setările: `seo_livrare_retur`, `seo_livrare_serviciu`, `seo_retur_zile`, `seo_retur_pagina`, `seo_retur_taxe`
  (`PoliticaLivrare::SETARI_ADMIN`), plus cele ale termenului; salvarea golește fluxul din cache.

**Organization**: numele din Setări magazin (`store_name`), altfel `seo.json` →
`organizatie.nume`, altfel `APP_NAME` (același nume ca în titluri, `SeoMeta::numeMagazin`); email și telefon din setări sau din linkurile
`mailto:`/`tel:` din antet și subsol; logo doar dacă are cel puțin 112×112 px și e
aproape pătrat (cel mult 1,5:1): întâi pozele din `seo.json` → `organizatie.logo`,
apoi iconița site-ului; `sameAs` doar cu profiluri sociale reale din antet și subsol.

## 5b. Ce intră în Google: noindex, canonicalul pe 404, H1

Livrarea 4, Bioscem `ef22b23`, `682843c`, `9fe175d`, `dd41c59`, `a64afb6` (`SeoIndexare`):

- `noindex,follow` pe căutarea internă (`/magazin?q=…`, fără canonical) și pe `/blog` cât n-are
  niciun articol publicat (Bioscem, decizia D7); etichetele rămân indexate (D2, D3).
- Pagina „nu există” (404, 410) nu are canonical și nici `og:url`, în afară de căile de plată,
  coș, cont, admin (`SeoRedirects::CAI_EXCLUSE`), care rămân ca înainte.
- Un articol de blog sau o categorie care nu există primesc pagina 404 a magazinului
  (`not-found.php`), nu un text simplu, respectiv tot magazinul; pe o cale exclusă scrisă cu „//”
  (de exemplu `//api/blog/posts`) răspunsul rămâne cel de dinainte.
- O pagină din constructor fără niciun `<h1>` (nu prima pagină, 404, magazinul și nici paginile
  cu coșul, comanda, contul) primește titlul ei ca H1, cu un stil fără specificitate (`:where`);
  pe `/blog`, titlul listei („Blog & Resurse”) e H1-ul paginii când textul ei n-are unul.
- Opriri: `SEO_NOINDEX=0`, `SEO_H1=0`.
- **Lista blogului (WP-39, BV-B5)**, cu blogul avansat (cel care citește filtrele din adresă): căutarea (`/blog?q=…`) e
  `noindex,follow`, fără canonical; `/blog?categorie=x` și `/blog?eticheta=x` au canonicalul paginii categoriei sau
  etichetei (`/blog/categorie/x`, `/blog/eticheta/x`), când ea există. Fără blogul avansat (Bioscem, înghețat) parametrii nu
  schimbă lista, deci pagina e `/blog` însuși și canonicalul rămâne `/blog`, ca înainte. Pe orice site: pagina 2, 3… a unei
  liste are canonicalul ei (`?page=N`; o pagină cerută peste ultima e ultima, cu canonicalul ei); o etichetă de blog cu mai
  puține articole publicate decât `seo.json` → `blog.eticheta_minim_articole` (implicit 1) e `noindex,follow` și lipsește din
  sitemap.
- **Catalogul pe altă pagină (WP-39, GR, OD-24)**: cu `catalog_pagina` alta decât `magazin` (GR: `produse`),
  `/produse?categorie=x` (sau `?category=x`) face 301 la `/categorie/x` într-un singur salt, cu sortarea și parametrii de
  reclamă păstrați, după aceeași regulă ca `/magazin?categorie=` (`LinkuriCategorii`); categoria se afișează în pagina
  catalogului și are canonicalul ei, `/categorie/x`.

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
- **Pozele din șabloane** (livrarea 4, Bioscem `e2c97dc`; `PozeOptimizate::dimensiuni`):
  width/height și pe pozele blogului, ale cardurilor de evenimente, ale ofertelor BBD și ale
  logo-ului din sertarul de meniu. Nu pe paginile de coș, comandă, plată, cont.
- **Pozele refăcute** (livrarea 4, Bioscem `e2c97dc`, `070e1af`; `PozeOptimizate`): pozele mari
  ale unui site se afișează (galeria produsului, cardurile, prima pagină, textul paginilor din
  constructor) din fișiere WebP ale pachetului, după `poze-optimizate.json` (`{"marimi_de_pe":
  "disc", "poze": {"/uploads/…": {"noua": "/assets/img/…webp", "octeti": N}}}`, fișierele în
  `public/` al pachetului). Doar dacă poza veche de pe disc are exact `octeti` (altă poză
  încărcată cu același nume rămâne cea încărcată). **Mărimea se ia pe server, de pe disc**
  (`stat -c %s public/uploads/gallery/<poza>`), niciodată descărcând poza: CDN-ul Hostinger
  trimite pozele refăcute, cu alți octeți, și atunci nicio poză nu trece pe WebP (Bioscem
  `070e1af`, după livrarea 4). De aceea `tools/dev/verifica-pachet.php` refuză lista fără
  `"marimi_de_pe": "disc"`, iar `scripts/diagnostic.php --pachet` arată pe server, pentru fiecare
  poză, dacă mărimea de pe disc e cea din pachet (rândurile „atentie”). Datele structurate,
  fluxul și poza de distribuire rămân pe poza veche (rezoluția mare). Oprire: `SEO_POZE=0`.
- **Produse similare** (livrarea 4, Bioscem `2d43171`; `ProduseSimilare`): 4–8 produse din
  aceeași categorie (cele alese în admin din categorie primele, apoi pe rând după produsul
  curent, epuizatele la urmă, fără cele cu ofertă BBD de ales dacă nu sunt alese în admin; sub
  4, completate cu cele alese în admin din alte categorii), cu cardurile scrise în HTML (linkuri
  reale) exact cum le face JavaScript-ul caruselului, inclusiv pluginurile (stelele doar cu
  recenziile, prețul doar cu magazinul, butonul de coș doar cât magazinul primește comenzi).
  Oprire: `SEO_PRODUSE_SIMILARE=0`.
- **HEAD** pe o adresă cu litere mari primește același răspuns ca GET (livrarea 4, `bb11ae0`).

### 6b. Viteza în nucleu (WP-40, SEO partea B, B5/B6)

Fiecare punct are un comutator în `settings` (fără câmp în admin), **pornit în nucleu** și înghețat la valoarea de azi pe
site-urile existente care nu-l aveau (`setari-inghetate.json`, `din: "manual"`; pe site-ul de referință „0”, pornite după ce
proprietarul le vede pe site-ul de verificare). Oprit, pagina e octet cu octet ca înainte (G-cmp pe înghețare; G-vizual,
a doua parte, compară aspectul cu valorile vechi și cu cele noi). Din linia de comandă, pe un site (verificare sau live):
`php scripts/comutatoare-seo.php --arata | --porneste[=a,b] | --opreste[=a,b]` (golește și cache-ul de pagini).

- **Pozele în mărimile ecranului** (`imagini_srcset`; `VarianteImagini`): la încărcare din admin și o dată pentru pozele
  existente (`php scripts/optimizeaza-imagini.php --plan|--aplica`), fiecare poză JPEG/PNG/WebP/GIF primește variante WebP
  de 160, 400, 800 și 1200 px (doar mai mici decât ea) în `/uploads/_variante/<dosar>/<nume>-<lățime>w.webp`. Cardurile,
  magazinul, produsul (poza mare și miniaturile), lista categoriei și prima pagină pun `srcset` (variantele de pe disc plus
  poza întreagă) cu `sizes`, `width`, `height`. Poza încărcată nu se schimbă (datele structurate și fluxul rămân pe ea);
  pozele de profil ale membrilor (`uploads/gallery/profile/`) nu se ating. Fără GD cu WebP nu se face nimic.
- **Sesiunea doar la nevoie** (`sesiune_doar_la_nevoie`; `SesiuneLaNevoie`): un vizitator nou care cere cu GET o pagină
  publică nu primește sesiune (niciun fișier, niciun cookie), deci pagina poate veni din cache și din CDN. Sesiunea pornește la
  orice POST, când browserul are deja cookie-ul ei și pe căile care o folosesc (adminul, coșul, comanda, plata, contul,
  logarea, API-ul coșului, adresele mereu deschise ale pluginurilor); API-urile citite pe fiecare pagină (sumarul coșului,
  căutarea, catalogul, lockerele) nu o pornesc. Plasa de siguranță: dacă o pagină fără sesiune scrie totuși în `$_SESSION`,
  pagina e ținută în buffer și sesiunea pornește la final cu ce s-a scris; doar după ce antetele au plecat scrierea se
  pierde, cu un rând în jurnalul PHP („ANDAXI SesiuneLaNevoie”).
- **Cache-ul de pagini extins** (`cache_pagini_extins`; `ResponseCache::REGULI_EXTINSE`): prima pagină, magazinul (și
  adresa catalogului), produsele, categoriile, mărcile, etichetele, 10 minute, fără reguli în admin (regulile din admin au
  întâietate). Doar pentru cine n-are coș, cont sau un mesaj în sesiune; golit la orice schimbare de conținut.
- **CSS-ul și JavaScript-ul șablonului în fișiere** (`css_js_in_fisiere`; `SablonSite`): cele șase blocuri fixe din
  `views/layout.php` (~19 KB CSS, ~40 KB JS) stau în `views/sablon-site/`; pagina leagă variantele minificate
  (`public/assets/css/sablon-site.min.css`, `public/assets/js/sablon/*.min.js`, cu `?v=<data fișierului>`). Scripturile rămân
  sincrone, în același loc. După o schimbare în `views/sablon-site/`: `tools/dev/php.sh tools/dev/minifica-sablon.php`
  (E4 și G-vizual cer fișierele la zi). Oprit, blocurile se pun în pagină din aceleași surse, octet cu octet ca înainte.
- **Fonturile din nucleu** (tema, `Tema::IMPLICIT`): DM Sans și Playfair Display din `/assets/vendor/fonturi/` (aceleași
  familii și greutăți ca cererea spre Google Fonts), fără legătură spre Google; un site care rămâne pe Google o spune în
  tema lui (`"fonturi_google": true, "fonturi": []`).
- **HSTS** pe https (`Strict-Transport-Security: max-age=31536000`, fără subdomenii, fără preload); `HSTS=0` în `.env` îl
  oprește.
- **Fișierele versionate** (`/assets/…?v=`): `Cache-Control: public, max-age=31536000, immutable` (`public/.htaccess`,
  `deploy/stub/.htaccess`; regula `AX_VERSIONAT`). Fără `?v=`, ca înainte.

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
- **Conținutul schimbat o dată** (livrarea 4, Bioscem `c3ad6ab`, `b51067a`): `seo-continut.json`
  în dosarul site-ului (`{versiune: 1, nume_magazin, asteptat: {aircall, inlocuiri}, aircall: [produse
  redenumite după cod: adresa cu litere mici, numele, titlul și descrierea Google, primul paragraf],
  inlocuiri: [text exact vechi → nou, de exact „aparitii” ori, în pagini, descrieri sau tab-uri]}`) și
  `php scripts/seo-continut.php --plan | --aplica | --restaureaza [--fisier=…] [--forteaza]`: aceleași
  reguli ca mai sus (refuz fără să scrie nimic, o tranzacție, copia în `storage/`, codul produsului
  niciodată schimbat, a doua rulare „Nimic de schimbat”). Bioscem: `site-uri/bioscem/seo-continut.json`
  (căștile AIRCALL și 30 de linkuri; deja aplicat pe site, 21 octombrie).

Dovada că titlurile, descrierile și distribuirea Bioscem ies neschimbate: comparația cu codul Bioscem (G-cmp,
`tools/dev/compara-cu-baza.sh`), din WP-14 pe baza în forma Bioscem (`cms_dev_bioscem`) cu pachetul `site-uri/bioscem/`
(`seo.json`: sufixele vechi, cuvintele generice ale mărcilor, `logo_antet`), deci cu datele pe care le va avea site-ul.

## 7b. Modelul SEO al paginilor (WP-37, SEO partea B, B1)

Fiecare pagină publică are un singur loc pentru SEO-ul scris de om: rândul ei din `seo_pages` (`page_type` + `page_ref`),
citit și scris prin `App\Support\Seo`. Felurile de pagină (`Seo::TIPURI`):

| `page_type` | `page_ref` | Pagina | Se editează în |
|---|---|---|---|
| `custom_page` | id-ul paginii | o pagină din constructor | Pagini → editorul paginii |
| `product` | id-ul produsului | `/produs/{slug}` | Produse → editorul produsului |
| `blog_post` | id-ul articolului | `/blog/{slug}` | Blog → editorul articolului |
| `category` | id-ul categoriei | `/categorie/{slug}` | Categorii produse (rândul categoriei) |
| `brand` | slug-ul | `/marca/{slug}` | Categorii produse → Mărci |
| `tag` | slug-ul | `/eticheta/{slug}` | Categorii produse → Etichete |
| `listing_default` | `magazin` | lista tuturor produselor (`/magazin` sau pagina catalogului); SEO-ul scris pe pagina ei din constructor are întâietate | Categorii produse → Lista tuturor produselor |
| `blog_category` | id-ul | `/blog/categorie/{slug}` | Blog → Categorii |
| `blog_tag` | id-ul | `/blog/eticheta/{slug}` | Blog → Categorii → Etichete blog |

Câmpurile: titlul și descrierea pentru Google, canonicalul (o adresă http/https întreagă), poza de distribuire, **robots**
(gol = fără etichetă, deci în Google; `noindex,follow`, `noindex,nofollow`), **og:type** (gol = `website`; `article`,
`product`), **textul de deasupra listei** (`intro_html`, la mărci, etichete, lista produselor, categoriile și etichetele
blogului; la categoriile de produse în `product_categories.intro_html`), și `updated_at`. **Textul de deasupra listei** se
curăță după o listă albă (`HtmlSigur::curata`, DOMDocument, fără dependențe noi), la scriere și din nou la afișare (un rând
scris altfel, dintr-o copie sau o unealtă, iese tot curățat): doar paragrafe, titluri h2–h6, liste, sublinieri, citate,
linkuri, poze, tabele simple; `script`, `style`, `iframe`, `svg`, `math`, formularele pleacă cu tot conținutul, orice altă
etichetă pleacă și textul ei rămâne; niciun atribut `on…` sau `style`; `href` doar relativ, `http`, `https`, `mailto`, `tel`,
`src` doar relativ, `http`, `https` (entitățile și caracterele de control din schemă, `&#106;avascript:`, `java&#9;script:`,
sunt decodate înainte de verificare); un link `target="_blank"` primește `rel="noopener noreferrer"`. Descrierile
produselor rămân pe `RichText::sanitize`, ca până acum (decizia proprietarului: lista albă ar putea scoate din descrierile
reale `style`, `h1`, `font`, `iframe`). Titlul, canonicalul și poza au cel mult 255 de caractere: peste, salvarea se
refuză cu un mesaj (nu se taie în tăcere). Robots „noindex”: pagina iese și din `sitemap.xml` (produs, pagină, categorie,
marcă, etichetă, lista produselor, articol), iar produsul și din fluxul Google Merchant. `seo_pages.updated_at` al rândurilor
de dinainte de `nucleu/0041` e momentul migrării (coloana nouă primește `CURRENT_TIMESTAMP`): nu spune când s-a schimbat
pagina și nu se folosește la `<lastmod>`. Migrările: `nucleu/0041` (coloanele `seo_pages`), `catalog/0042` (textul
categoriei, mărcile și etichetele).

- **Un câmp gol nu schimbă nimic.** Fără rând (sau cu câmpurile noi goale) pagina iese exact ca înainte: valorile finale
  din `<head>` le face `Seo::rezolva`, regula mutată din `views/layout.php` fără nicio schimbare (`test-seo.php` E1 o
  compară cu regula veche pe 3.456 de combinații; G-cmp față de codul Bioscem). Robots-ul dat de controller (căutarea
  internă, `/blog` gol) are întâietate față de cel scris; canonicalul scris nu se folosește pe liste (marca, eticheta,
  categoria, categoria blogului: adresa listei e canonicalul ei).
- **Un formular care nu are un câmp nou nu-l șterge** (de exemplu o pagină din cache, un formular vechi): titlul,
  descrierea, canonicalul și poza se scriu ca până acum; robots, og:type și textul listei doar când sunt în formular
  (`seo_robots`, `seo_og_type`, `seo_intro_html`; la categorie `intro_html`). Rândul dispare când toate câmpurile sunt goale
  sau pagina însăși e ștearsă.
- Un controller poate da SEO-ul și ca un singur tablou `seo` (`titlu`, `descriere`, `canonical`, `imagine`, `robots`,
  `og_type`, `fara_canonical`; `View::render` îl desface în cheile `meta*` de până acum).

**Panoul SEO din admin** (`admin_componenta('panou-seo', …)`): „Cum apare în Google” (titlul, adresa, descrierea, refăcute
cât scrii; fără titlu scris: „{nume} | {magazin}”), numărul caracterelor (60 / 155, cu roșu peste) și verificările
(`Seo::verificari`): titlu sau descriere lipsă, peste 60 / 155 de caractere, același titlu sau aceeași descriere scrise pe
altă pagină (fără diferență de litere mari și spații), cu linkul ei din admin; doar pagini publice (nu ciorne, pagini șterse
sau inactive) și doar cele pe care adminul de acum le poate edita (rolul magazinului nu vede titlurile paginilor sau ale
articolelor). Formularele SEO ale mărcii, etichetei, listei produselor și etichetei blogului cer jetonul formularului
(`_csrf`). Verificările se cer, doar în citire, la
`GET /admin/products/seo-verifica` (produse, categorii, mărci, etichete, lista produselor: rolul magazinului),
`/admin/blog/posts/seo-verifica` (articolele: rolurile blogului) și `/admin/seo/verifica` (restul: administratorul general).

**Mărcile și etichetele ca entități** (`product_brands`, `product_tags`, `product_tag_links`): sursa rămâne câmpul „Marcă”
și etichetele produsului, iar paginile `/marca/{slug}` și `/eticheta/{slug}` le citesc tot de acolo, deci adresele nu se
schimbă (slug-ul e `Slug::simplu`, ca azi; două nume cu același slug sunt același rând). Migrarea `catalog/0042` le
completează pe bucăți de câte 500 de produse; `MarciEtichete::sincronizeaza` (la deschiderea Categoriilor produse) și
`sincronizeazaProdus` (la salvarea unui produs) le țin la zi, și după un import; de două ori = aceleași rânduri. SEO-ul unei
mărci sau etichete e rândul ei din `seo_pages` după slug.

## 8. Fluxul pentru Google Merchant Center

`/feed/google-merchant.xml` (livrarea 2, Bioscem `d277727`, `1e46f9f`; `FluxGoogleMerchant`;
pluginul magazin): produsele active cu prețul afișat (și reducerea activă, cu perioada ei),
disponibilitatea după ERP, pozele reale, marca, GTIN-ul doar valid (codul EAN al produsului, `products.ean`,
`catalog/0048`, scris în editorul produsului — WP-39; unul cu cifra de control greșită nu se salvează; fără
`identifier_exists=no`), fără precomenzi; livrarea din `PoliticaLivrare` (ca în datele
structurate). Id-ul produsului: SKU-ul, altfel prefixul site-ului (`seo.json` →
`flux_merchant.prefix_id`, implicit `P-`) și id-ul. `X-Robots-Tag: noindex`; fluxul stă 15
minute în `storage/cache/`; cât magazinul se oprește, canalul rămâne, fără produse, imediat
(nu din cache). Fără pluginul magazin, 404.

Comutatorul fluxului (WP-40, B8): Setări magazin → SEO Google → „Fluxul pentru Google Merchant” (setarea `merchant_feed`,
implicit pornită, înghețată la „1” pe site-urile existente). Oprit, `/feed/google-merchant.xml` răspunde 404 și fișierul din
cache se șterge. Secțiunea arată adresa fluxului, de dat în Merchant Center.

## 8b. Sursa comenzilor (WP-40, SEO partea B, B9)

De unde a venit clientul care a comandat, pe comandă (`orders.sursa`, `magazin/0051`), și raportul din admin (Rapoarte →
Sursa comenzilor, `/admin/orders/sursa-comenzilor`, doar cu pluginul magazinului): comenzile și valoarea lor pe surse și pe
săptămâni. Felurile: `google_ads` (gclid, gbraid, wbraid sau o campanie plătită utm_source=google), `email` (utm_medium
email/newsletter sau cookie-ul newsletterului), `campanie` (alt utm_source), `google` (venit din căutarea Google),
`alt_site`, `direct`. La intrarea pe site felul vizitei se ține în cookie-ul `ax_sursa` (30 de zile; **doar felul**, niciun
id, nicio adresă); la comandă, reclama Google (`bv_gclid`, ca pentru `ad_source`) și newsletterul au întâietate. Comutatorul
`sursa_comenzii` (implicit pornit; înghețat la „0” pe site-ul de referință). Coloana `ad_source` rămâne neschimbată.

## 9. Datele unui site

Dosarul: `SITE_DATE_DOSAR` din `.env` (cale absolută sau relativă la rădăcină); gol =
`<rădăcină>/site` (ignorat de git). Doar date, fără PHP executabil: formatul întreg al
pachetului (fișierele permise, manifestul `pachet.json`, tema, verificarea) e în
[PACHETE.md](PACHETE.md). Un site fără dosar merge cu valorile implicite (nicio regulă de
hartă, etichetele de la 1 produs, logo-ul = iconița). Un fișier stricat se ignoră (o linie
în jurnalul PHP); `tools/dev/verifica-pachet.php` (în testul de fum) nu-l lasă în repo.

`seo.json` (toate cheile sunt opționale; cheile `_despre` sunt comentarii):

```json
{
  "sitemap": {"eticheta_minim_produse": 3, "etichete_pastrate": ["vitamina-d"], "etichete_excluse": ["insomnie"]},
  "organizatie": {"nume": "Numele firmei", "logo": ["/uploads/gallery/logo-patrat.png"], "tip": "NGO"},
  "blog": {"eticheta_minim_articole": 2},
  "robots_disallow": ["/pagina-de-sistem"],
  "cai_excluse": ["/pagina-de-sistem"],
  "titluri": {"sufixe_vechi": ["NUME VECHI"], "cuvinte_generice_marca": ["cuvânt"]},
  "logo_antet": {"png": "/uploads/gallery/logo.png", "dimensiuni": [977, 240],
                 "webp_1x": "/assets/img/logo-212.webp", "webp_2x": "/assets/img/logo-423.webp"},
  "distribuire": {"logo": "/uploads/gallery/logo.png"},
  "livrare_retur": {"serviciu": "FAN Courier", "termen_livrare": [0, 1, 1, 2],
                    "retur": {"zile": 14, "pagina": "/termeni-conditii", "taxe": "client"}},
  "flux_merchant": {"prefix_id": "P-"}
}
```

`livrare_retur` și condițiile lui le confirmă proprietarul site-ului (`termen_livrare`:
`[pregătire min, max, transport min, max]` în zile lucrătoare, valorile implicite pe care setările `seo_livrare_*` le pot
schimba, sau `null`; `taxe`: `client` sau
`gratuit`). Fișierele publice ale pachetului la o adresă fixă (de exemplu variantele WebP ale logo-ului) stau în
`site-uri/<site>/public/` și se copiază peste `public/` la adoptare ([PACHETE.md](PACHETE.md), „Fișierele publice la o
adresă fixă”).

**Pachetele site-urilor** stau în `site-uri/<site>/` și se copiază în dosarul site-ului
la instalare (sau la adoptarea codului comun; verificarea pe server: `scripts/diagnostic.php --pachet`). `site-uri/bioscem/`: 128 de reguli exacte, adică
cele 113 ale livrării 1 (103 × 301, 10 × 410), cele 14 × 301 aprobate de proprietar pe
19 octombrie (Bioscem `9f59e79`: produse și etichete șterse, spre înlocuitorul verificat) și
`/retur-garantie` → `/termeni-conditii` (20 octombrie, Bioscem `8c074f1`), plus
6 prefixe 410; regulile etichetelor D2/D3
(minim 3 produse, 12 etichete mici păstrate, 19 cu nume de boli scoase), numele
„Bioscem Shop” și logo-urile încercate, `/cos-cumparaturi` (pagina de coș din
constructorul Bioscem) blocată în robots.txt și exclusă din redirecționări; din livrarea 2:
sufixul vechi „BIOSCEM”, „harrison” (nu e o marcă), logo-ul din antet și variantele lui WebP
(`site-uri/bioscem/public/assets/img/`), logo-ul de distribuire, livrarea FAN Courier,
returul de 14 zile din `/termeni-conditii` plătit de client, prefixul `BS-` al
fluxului; textele Google ale primei pagini, a 3 categorii și 20 de produse
(`seo-texte.json`); din livrarea 4: termenul de livrare 0–1 + 1–2 zile lucrătoare (decizia
proprietarului din 20 octombrie), cele 10 poze mari ca WebP (`poze-optimizate.json`, mărimile de pe
discul serverului, și `public/assets/img/optimizate/`), conținutul AIRCALL și al linkurilor din texte
(`seo-continut.json`) și ținta cu litere mici a regulii `/produs/casti-antiradiatii-aircall-sp21`.

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
- `tools/dev/test-seo.php` E1 (WP-37, `tools/dev/lib/test-seo-model.php`): `Seo::rezolva` = regula veche a layout-ului;
  fiecare pagină demo fără rânduri noi e ca înainte, un rând de fiecare fel schimbă exact câmpurile lui și doar pe pagina
  lui, după ștergere totul e din nou ca la început; formularele vechi nu șterg câmpurile noi; verificările panoului (lipsă,
  prea lung, duplicat; duplicatele doar pe pagini publice și editabile de rolul de acum); `HtmlSigur` pe 16 forme XSS cunoscute
  și pe un text obișnuit, un text de listă cu script scris direct în bază iese curățat; titlul de 256 de caractere refuzat cu
  mesaj; formularul mărcii fără jeton refuzat; paginile „noindex” ies din sitemap și produsul din flux; migrarea `catalog/0042` rulată din nou de două ori și sincronizarea dau aceleași rânduri, iar fiecare
  marcă și etichetă are pagina 200;
- `tools/dev/test-seo.php` E2 (WP-38, `tools/dev/lib/test-seo-redirectionari.php`): harta din bază = pachetul (rândurile și,
  cheie cu cheie, aceeași regulă și aceeași țintă finală cu copia din bază ca doar cu fișierul, pe pachetul demo și pe cel
  Bioscem, 128 + 6); refuzurile (redirecționare deschisă în 9 forme, fiecare cale exclusă ca adresă veche și ca țintă,
  buclă, lanț, `.`/`..` scrise și codate, caractere de control după decodare, prefixe peste căile excluse) și aplatizarea;
  pachetul (piatra de mormânt rămâne, regula omului nu e atinsă, sincronizarea automată nu scoate peste o cincime, cea cerută
  da); CSV-ul (4.000 de rânduri dintr-o tranzacție, peste 2 MB refuzat, un import blocat la mijloc nu scrie nimic, exportul
  cu apostrof la formule); din admin: 301 într-un salt cu parametrii, fără `Set-Cookie`, accesările, oprirea, regula din
  pachet ștearsă care rămâne oprită, importul și exportul CSV, fără jeton refuzat; jurnalul 404 (fără parametri, fără „@”,
  `%2540`, „＠”, fără scanări, doar gazda de pe care a venit omul, păstrarea, rândurile vechi plecate și la un 404 nenotat);
  slug-ul schimbat de trei ori (un salt, fără buclă la întoarcere), regula omului pe adresa veche păstrată și prefixul peste
  adresa nouă (cu mesaj), slug-ul curățat; propunerea după ștergerea unui produs și a unei categorii (410 ales și salvat) și
  după o ștergere a rolului magazin (o vede administratorul general);
- `tools/dev/test-seo.php` E3 (WP-39, `tools/dev/lib/test-seo-sitemap-jsonld.php`): indexul și fiecare sitemap pe tip (200, XML);
  fiecare adresă din fiecare sitemap 200, cu canonicalul spre ea, fără noindex; `<lastmod>` pe fiecare; pozele produselor și
  articolelor; ce nu intră (pagina ștearsă, ciorna, doar pentru membri, de sistem, produsul inactiv, categoria de blog
  „noindex”, eticheta de blog fără articole); datele structurate pe tipuri (Product cu gtin13, BreadcrumbList, BlogPosting,
  Event, Organization și NGO, ItemList; nimic pe o pagină doar pentru membri); livrarea și returul din admin; codul EAN din
  editor (unul greșit nu se salvează); lista blogului (căutarea, filtrele, paginile 2+); `/produse?categorie=` pe catalogul
  GR; `verifica-redirecturi.php` pe 40 de adrese inventate (30 × 200, 6 × 301, 3 × 410 voit, o problemă voită);
- `tools/dev/test-seo.php` E4 (WP-40, `tools/dev/lib/test-seo-viteza.php`): sesiunea (paginile publice fără fișier și fără
  cookie, coșul pornește sesiunea și rămâne, oprit ca înainte); cache-ul extins (HIT, nu pentru cine are coș); fișierele
  șablonului (la zi, 200, `Cache-Control`; oprit, blocurile octet cu octet); fonturile (aceleași familii și greutăți, nicio
  legătură spre Google), HSTS, regula `immutable`; variantele pozelor și `srcset` (oprit, pagina octet cu octet); fluxul
  (câmpurile cerute de Merchant Center; oprit, 404); sursa comenzii (felul vizitei, cookie-ul, o comandă venită din Google,
  raportul fără date ale clienților);
- `tools/dev/vizual-valori-noi.sh` (în G-vizual): stilurile calculate ale paginilor publice cu valorile vechi față de cele noi
  ale vitezei, pe codul curent, 0 diferențe;
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

- Noindex pe etichetele mici, H1, linkurile interne, termenul de livrare pentru Google
  (livrările 3–4 Bioscem, apoi în nucleu).
- `HEAD` pe o adresă corectă răspunde 404 (routerul n-are rute HEAD; moștenit, neschimbat).
