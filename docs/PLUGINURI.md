# Pluginurile și licența

Un site primește **nucleul** (pagini, meniu, galerie, SEO, emailuri, formular de
contact, administratori) și **pluginurile** din contractul lui. Ce pluginuri are
un site hotărăște doar CRM-ul, printr-o licență semnată; clientul nu poate porni
nimic și nu vede nimic despre pluginurile pe care nu le are.

## Unde stă fiecare lucru

| Fișier | Ce e |
|---|---|
| `config/plugins.php` | **Catalogul**: pentru fiecare plugin numele, descrierea, de ce are nevoie, rutele, paginile, intrările din meniu, cronurile, tabelele. Plus ordinea meniului adminului, rutele împărțite între pluginuri, pachetele de abonament și licențele de dezvoltare ale celor patru site-uri existente (`licente_dev`, doar pentru teste). |
| `src/Support/Licenta.php` | Citirea și verificarea licenței (`storage/licenta/licenta.json`) și memoria „ultima bună”. Aici se adaugă în Etapa 2 preluarea de la CRM. |
| `src/Support/Plugins.php` | Întrebările pentru tot codul: `activ`, `permiteInceput`, `vizibil`, `stare`; regula dependențelor; proprietarul unei rute; meniul. |
| `src/Http/rute.php` | Toate rutele site-ului (mutate din `public/index.php`). |
| `src/Http/GardaPluginuri.php` | Garda routerului: o rută a unui plugin care nu e pe site nu ajunge la controller. |
| `tools/dev/licenta.php` | Chei și licențe de test (dezvoltare). |
| `tools/dev/verifica-rute.php` | Verifică că fiecare rută are proprietar, că catalogul corespunde codului și că blocurile pluginurilor din șabloane închid HTML-ul pe care îl deschid. |
| `tools/dev/instantaneu-rute.php` | Instantaneul rutelor: ce face garda cu fiecare rută, meniul și cronurile, pe fiecare licență de test, față de fișierele din `tools/dev/instantanee/` (în git); plus regulile fixe care nu depind de catalog. |
| `tools/dev/test-pluginuri.php` | Testele: licența, garda, fiecare pachet parcurs pagină cu pagină (și cu browserul, inclusiv textul vizibil), „se oprește”, comparația cu codul de bază, la cerere fiecare plugin oprit pe rând. |
| `tools/dev/compara-cu-baza.sh` | Cu licența „toate” (sau, cu `--licenta=bioscem`, cu licența de dezvoltare a Bioscem, fără unealta internă), compară HTML-ul paginilor-cheie cu cel al codului Bioscem însuși (ultimul commit revizuit din `docs/de-portat.md`); diferențele voite sunt declarate explicit, cu motivul lor, și se scot înainte. Istoric: `--baza=f5ae5ea`, codul dinaintea pluginurilor. |
| `scripts/diagnostic.php --licenta` | Verificarea dinaintea unui deploy: licența se verifică cu cheia și site-ul din `.env`? Iese cu 1 dacă nu. |
| `tools/dev/crawl-pachet.cjs` | Trecerea cu Playwright prin paginile unui pachet (DOM și text după JavaScript, ferestrele comenzilor și ale produselor, contul clientului, erori JS, captura meniului). |
| `tools/dev/test-seo.php` | Testele SEO pe fiecare pachet: sitemap, robots.txt, date structurate, redirecționări (niciodată pe adresele mereu deschise). Vezi [SEO.md](SEO.md). |

## Stările unui plugin

| Stare | Site | Admin | Cronuri și adrese de confirmare | Date |
|---|---|---|---|---|
| `pornit` | normal | normal | normal | — |
| `se_opreste` | nu începe nimic nou: checkout-ul se închide cu un mesaj, plățile cu cardul ale pluginului nu mai apar, nu se mai trimit linkuri de plată noi (detaliile, pe pluginuri, mai jos) | paginile rămân, ca să se termine ce e în curs (comenzi, rambursări, AWB-uri, plăți) | rulează cronurile care termină lucruri; nu rulează cele care pornesc ceva nou (emailurile de coș abandonat) | neatinse |
| `oprit` (sau lipsă din licență) | adresele pluginului dau 404 (pagina 404 a site-ului) sau duc unde scrie în licență | meniul lui dispare; adresa deschisă direct arată „Modulul nu este inclus în abonament” (403) | cronurile lui nu fac nimic; adresele de confirmare răspund **mereu** | **nu se șterg niciodată**; la repornire totul revine |

În cod:

```php
use App\Support\Plugins;

Plugins::activ('magazin');           // pornit
Plugins::permiteInceput('magazin');  // pornit: se poate începe ceva nou (comandă, plată, donație)
Plugins::vizibil('magazin');         // pornit sau se_opreste: meniuri și pagini, ca să se termine ce e în curs
Plugins::stare('magazin');           // 'pornit' | 'se_opreste' | 'oprit'
```

Nucleul (`'nucleu'`) e mereu pornit. O cheie greșită în cod dă „oprit” și se
notează în jurnal; `verifica-rute.php` prinde cheile greșite din cod înainte.

**Dependențele** („are nevoie de” din catalog): un plugin nu poate fi mai pornit
decât pluginurile de care are nevoie. Dacă una e oprită, e tratat ca oprit. Dacă
una se oprește, se oprește și el: altfel, de exemplu, livrarea FAN ar dispărea
exact când magazinul își termină comenzile. O listă în listă înseamnă „cel puțin
unul dintre” (linkul de plată merge cu oricare plată cu cardul).

## Licența

`storage/licenta/licenta.json` (sau în dosarul din `LICENTA_DOSAR`):

```json
{
    "site": "bioscem",
    "emisa_la": "2026-10-18T09:15:02Z",
    "versiune": 17,
    "pluginuri": {"catalog": "pornit", "magazin": "se_opreste"},
    "redirectionari": {"blog": "/"},
    "semnatura": "<Ed25519, base64>"
}
```

- `pluginuri`: doar ce nu e oprit; o cheie lipsă = oprit; o cheie pe care codul
  nu o cunoaște se ignoră (o licență mai nouă decât codul nu strică nimic).
- `redirectionari` (opțional): unde duc adresele publice (GET) ale unui plugin
  oprit, în loc de 404. Doar căi din același site (`/…`).
- **Semnătura**: Ed25519 (sodium), separată de conținut, peste
  `"andaxi-licenta-v1\n"` + JSON-ul canonic al tuturor câmpurilor în afară de
  `semnatura`: cheile obiectelor sortate după octeți (UTF-8), fără spații, fără
  escape la `/`, la diacritice și la U+2028/U+2029; numerele doar întregi (fără
  zecimale). Cu aceste reguli, `JSON.stringify` cu cheile sortate dă exact același
  text (testul compară cu Node, inclusiv o cale cu diacritice și U+2028). Așa
  ordinea câmpurilor și spațiile din fișier nu contează, dar orice valoare schimbată da.
- **Cheia publică** a CRM-ului: `LICENTA_CHEIE_PUBLICA` (base64 sau base64url, 32
  de octeți); mai multe, separate prin virgulă, cât timp se schimbă cheia.
  `LICENTA_SITE`: identificatorul site-ului; o licență pentru alt site se respinge.

Reguli la fiecare cerere (verificarea durează câteva zeci de microsecunde):

1. `licenta.json` bună și cu `versiune` ≥ cea a ultimei bune → se folosește și
   devine `licenta-buna.json`.
2. Semnătură greșită sau lipsă, alt site, JSON stricat, versiune mai mică → se
   ignoră; rămâne `licenta-buna.json`.
3. **Nicio licență nu se verifică acum, dar site-ul a avut una** (cheia publică sau
   `LICENTA_SITE` greșite în `.env`, cheia CRM schimbată fără site, sodium lipsă,
   fișierele șterse) → site-ul merge mai departe cu **ultima licență aplicată**,
   ținută în `stare-aplicata.json` (conținutul verificat atunci, fără semnătură).
   Nimic nu se oprește: magazinul primește comenzi, cronurile BT/ERP/FAN își
   termină plățile și AWB-urile. Jurnalul spune motivul, iar în admin apare o notă
   neutră („Licența site-ului nu a putut fi verificată… contactați ANDAXI”).
4. Nicio licență, niciodată (site nou, fără `stare-aplicata.json`) → doar nucleul,
   iar în admin apare „Licența lipsește”. Cronurile pluginurilor ies atunci cu
   **codul 2** (o problemă de rezolvat, vizibilă în monitorizarea cronurilor), nu
   cu 0 ca atunci când licența oprește un plugin.

**Înainte de a urca o versiune nouă de cod pe un site** (mai ales prima dată, pe
Bioscem): `php scripts/diagnostic.php --licenta` arată licența, problemele ei și
stările pluginurilor și iese cu 1 dacă nicio licență nu se verifică cu cheia și
site-ul din `.env`. Un site nou, cu codul urcat înaintea licenței, ar merge doar
cu nucleul (magazinul ar da 404).

Fiecare problemă se scrie **o singură dată** în `storage/logs/pluginuri.log`
(nu la fiecare cerere), la fel orice schimbare de stări. La o schimbare de stări
se golește memoria rapidă a paginilor; în plus, amprenta stărilor face parte din
cheia ei, deci o pagină randată sub alte stări (de exemplu chiar în clipa
schimbării) nu se mai servește. Cererile primite la adresele de confirmare ale
unui plugin oprit se notează tot acolo, **o dată pe zi pentru fiecare adresă**
(pixelul unui newsletter vechi sau un robot nu umplu discul); ce s-a notat azi
stă în `storage/logs/pluginuri-azi.json`.

**Dosarul licenței fără drept de scriere**: stările se aplică oricum (din
`licenta.json`), dar nu se poate ține minte nimic: problema se scrie în jurnal o
dată pe zi, în admin apare nota „Licența site-ului nu poate fi salvată pe server”,
harta site-ului nu se mai reface singură, iar momentul intrării în „se oprește”
se ia din data emiterii licenței.

**`stare-aplicata.json`** (scris de site, nu de CRM): amprentele stărilor și ale
mesajelor, stările efective, de când e fiecare plugin în „se oprește”
(`se_opreste_din`, folosit de ERP) și ultima licență aplicată (pentru regula 3).
Cine poate scrie în `storage/` poate schimba și codul, deci fișierul nu e semnat.

**Etapa 2** (preluarea de la CRM la 15 minute, cu check-in): se adaugă în
`Licenta` o metodă care descarcă licența și o dă lui `Licenta::instaleaza()`.
Restul codului nu se schimbă.

### Vector de test (pentru CRM)

Cheia privată din sămânța de 32 de octeți `0x01` repetat:

- cheia publică: `iojj3XQJ8ZX9UtstPLpdcspnCb8dlBIb83SIAbQPb1w=`
- conținut: `{"site":"exemplu","emisa_la":"2026-10-18T09:15:02Z","versiune":17,"pluginuri":{"magazin":"se_opreste","catalog":"pornit"}}`
- mesajul semnat: `andaxi-licenta-v1\n{"emisa_la":"2026-10-18T09:15:02Z","pluginuri":{"catalog":"pornit","magazin":"se_opreste"},"site":"exemplu","versiune":17}`
- semnătura: `v+hUHsCMbP45/pNdtfuQAaesPpyOrrAYGaEY5pso+siktOE3kuICn/2284CZX5iL3ibD0WWlpYQEQDJIS2x6DA==`

Aceeași semnătură iese din PHP (`sodium_crypto_sign_detached`) și din Node
(`crypto.sign(null, …)`); `test-pluginuri.php` verifică vectorul.

## Garda rutelor

Fiecare rută din `src/Http/rute.php` are un proprietar în catalog:

- `rute_admin`, `rute_publice`: tipare exacte (`/admin/settings/erp`) sau prefixe
  (`/admin/coupons/*` = ruta și tot ce e sub ea), opțional cu metoda în față
  (`POST /admin/users/import-backup`). Câștigă declarația cea mai precisă:
  exactă înaintea prefixului, cu metodă înaintea celei fără, prefixul mai lung
  înaintea celui mai scurt (`/admin/products/reviews/*` al recenziilor bate
  `/admin/products/*` al catalogului);
- `rute_mereu_deschise`: adresele la care răspund procesatorii de plăți, ERP-ul
  sau linkurile din emailurile deja trimise (webhook-uri Stripe, EuPlătesc, BT,
  notificările ERP, întoarcerea de la bancă, dezabonarea din newsletter). Ajung
  la controller oricare ar fi starea pluginului, ca nicio plată sau notificare
  pornită să nu se piardă (Stripe oprește un webhook după zile de erori);
- `pagini`: pagini din baza de date ale unui plugin (`acorduri-gdpr`), servite de
  ruta generală `/{slug*}`;
- `rute_impartite`: rutele folosite de mai multe pluginuri, al căror proprietar
  depinde de un parametru (de exemplu `/admin/emails/newsletters?tab=ecommerce` e
  al magazinului, `?tab=templates` al newsletterului, `?tab=contact_forms` al
  nucleului). Se pot da și liste „oricare dintre”: pagina Setări plăți se vede
  dacă e pe site magazinul sau oricare plată, Securitate formulare (încercările de
  înregistrare și de comandă) dacă e pe site magazinul sau conturile de clienți.
  Acțiunile în masă din comenzi (`POST /admin/orders/bulk`) sunt ale magazinului,
  dar „AWB” e a livrării FAN, iar „Retrimite în ERP” a ERP-ului.

Ce face garda cu o rută a unui plugin care nu e vizibil:

- admin: „Modulul nu este inclus în abonament” (403), în aspectul adminului;
  nelogat, la login, ca orice pagină de admin;
- site: pagina 404 a site-ului (pagina „404” din admin, dacă e publicată), sau
  redirecționarea din licență, doar dacă duce la o pagină care se vede pe site
  (o redirecționare spre pagina altui plugin oprit dă 404: fără bucle, fără 302
  spre un 404); `/api/…` primesc un 404 scurt în JSON, `{"ok":false,"message":"Pagina nu a fost găsită.","error":"Pagina nu a fost găsită."}`
  (textul și în `error` din WP-12, X6: aplicațiile care vorbesc cu site-ul, de exemplu aplicația Windows a
  fișierelor din cloud a Biovitality, citesc doar `error`);
- adresele mereu deschise: ajung la controller (se notează în jurnal o dată pe zi
  pe adresă). Controllerul procesează ce vine de la procesator sau dintr-un email
  trimis (plata semnată, linkul semnat); fără pluginul pe site, unei cereri care
  nu e a lor îi răspunde cu pagina 404: întoarcerea EuPlătesc
  (`POST /checkout/succes/…`) fără rezultat semnat, `/checkout/bt/retur` fără
  plata pornită din sesiunea vizitatorului, dezabonarea și linkul de urmărire din
  newsletter cu semnătură greșită.

O rută nouă fără proprietar face `tools/dev/verifica-rute.php` să pice.

## Meniul și dashboard-ul

Meniul adminului se construiește din catalog (`meniu_admin` = ordinea grupurilor,
iar fiecare plugin își declară intrările cu grupul și ordinea). Intrările
pluginurilor care nu sunt vizibile lipsesc; un grup rămas gol nu apare. Peste
asta se aplică, ca înainte, rolurile adminilor. Cu toate pluginurile pornite,
meniul e identic, octet cu octet, cu cel de dinainte (`compara-cu-baza.sh`, pe
patru roluri de admin).

Dashboard-ul arată cardurile și listele doar pentru pluginurile vizibile
(Produse = catalog; Comenzi și „Comenzi recente” = magazin; Utilizatori și
„Utilizatori recenți” = conturi de clienți).

## Dincolo de rute: ce mai ascunde site-ul

Garda oprește adresele; restul îl fac câteva mecanisme comune, apoi verificări
puse exact în locurile unde începe ceva nou:

- **Linkurile și formularele** spre paginile unui plugin ascuns se scot din HTML-ul
  gata făcut, și pe site (antetul, meniul și subsolul scrise de client în
  Design, conținutul paginilor), și în admin (butoanele dintre pagini, tab-urile).
  `Plugins::filtreazaLinkuri()` întreabă routerul cine e proprietarul fiecărei
  adrese; un `<li>` rămas gol **din cauza filtrului** și o listă golită așa dispar
  și ele. Listele și elementele goale scrise așa de client (de umplut din
  JavaScript, separatoare de meniu) rămân neatinse. Conținutul din `<script>`,
  `<style>` și `<textarea>` nu se atinge. Cu toate pluginurile care au cod pe
  site filtrul nici nu rulează; uneltele interne (migrarea din WordPress) nu
  contează aici, deci nici licența de dezvoltare a Bioscem, fără ele, nu-l pornește.
- **Codurile din pagini** (`{{shop_catalog}}`, `{{latest_posts:3}}` …) ale unui
  plugin ascuns se scot din pagină (`Plugins::curataTokenuri`, lista lor e în
  catalog la „tokenuri”); în editorul de pagini nu mai apar în lista de coduri.
- **Harta site-ului** (`/sitemap.xml`) se construiește din bază la fiecare cerere,
  doar cu paginile pluginurilor de pe site: produsele, categoriile, mărcile și
  etichetele cu catalogul, articolele cu blogul, paginile din bază ale unui plugin
  (coș, checkout, acorduri GDPR…) doar cu pluginul lor. O copie statică cu alt nume,
  dacă există, se reface la prima cerere de după o schimbare de stări.
- **Datele structurate** (JSON-LD): firma și site-ul pe prima pagină (nucleu);
  firimiturile pe produs și categorie (catalog); `Product` cu `Offer` doar cu
  magazinul pornit (fără magazin nu se văd prețuri), stele doar cu pluginul de
  recenzii; un produs fără ofertă și fără recenzii nu primește deloc `Product`
  (Google l-ar raporta ca eroare); livrarea și returul doar cu magazinul pornit și cu
  condițiile din datele site-ului. Detalii în [SEO.md](SEO.md).
- **Livrarea 2 SEO** (WP-R, de ce stă unde stă): titlurile, numele magazinului din titluri
  (Setări magazin → SEO Google), etichetele de distribuire (`og:*`, `twitter:card`), poza
  de distribuire `/og/…` și logo-ul WebP din antet sunt ale **nucleului**, fiindcă orice
  site are pagini și antet; adresele `/categorie/{slug}`, rescrierea linkurilor vechi spre
  categorii și câmpurile SEO ale categoriilor și mărcilor sunt ale **catalogului** (fără
  catalog, linkurile din antet rămân cum le-a scris adminul); fluxul Merchant, livrarea și
  returul pentru Google și avertismentul pe produs sunt ale **magazinului** (au prețuri sau
  țin de coș și de comandă).
- **Redirecționările SEO** (adresa unică, adresele vechi ale site-ului) nu ating
  niciodată adresele mereu deschise ale pluginurilor: lista lor se ia din catalog
  (`rute_mereu_deschise`), deci un plugin nou cu un webhook își declară adresa acolo
  și e exclus automat (`verifica-rute.php` verifică). O adresă veche care ar duce la
  pagina unui plugin ascuns rămâne 404.
- **Lista de pagini** din admin și lista „Pagini” din constructorul de meniu nu mai
  arată paginile pluginurilor ascunse (coș, checkout, blog …). Paginile rămân în
  bază, neatinse.
- **Paginile de setări** care amestecă pluginuri (Setări magazin, Setări plăți) arată
  doar secțiunile pluginurilor de pe site, iar la salvare nu ating setările
  pluginurilor ascunse (bifa și datele lor rămân pentru o repornire).
- **Cronurile** pluginurilor care nu au voie să ruleze ies imediat, cu cod 0 și o
  singură linie („Pluginul … nu este pornit pe acest site; nu fac nimic.”); fără
  nicio licență, cu codul 2 („Licența site-ului lipsește…”).
- **Editoarele din admin**: editorul de pagini nu mai calculează și nu mai pune în
  sursa paginii previzualizarea codurilor pluginurilor ascunse (catalogul cu
  produsele, coșul, checkout-ul cu procesatorii, contul clientului, formularul de
  recenzii, acordurile GDPR); în previzualizările rămase, punctele, cuponul și
  procesatorii de card apar doar cu pluginurile lor. Constructorul de șablon de
  produs nu mai oferă prețul, cantitatea, butonul de coș și nota de sub el fără
  magazin, nici codurile și tab-ul de recenzii fără recenzii (și previzualizarea
  nu le mai arată).
- **API-urile catalogului** (`/api/shop/catalog`, `/api/products/best-sellers`,
  produsele similare din pagina de produs) nu mai dau numărul și media
  recenziilor fără recenzii, nici cantitățile vândute fără magazin; fără magazin,
  „cele mai vândute” vin din ordinea catalogului (etichetele, apoi cele mai noi),
  iar „Popularitate” nu mai ține cont de vânzări.
- **robots.txt** pomenește doar adresele pluginurilor de pe site (fiecare regulă din
  `App\Support\Robots::DISALLOW` are pluginul ei; ale nucleului, inclusiv `/_andaxi/`, sunt pe orice site).
- **Adresele platformei** (`/_andaxi/*`, azi pingul `GET /_andaxi/ping`; [DEZVOLTARE.md](DEZVOLTARE.md), „Unde
  scrie codul”) sunt ale nucleului (`nucleu.rute_publice`), deci răspund în orice licență.
- **Emailurile** unui plugin pleacă doar cu pluginul lui (vezi mai jos).
- **Textele care pomenesc alt plugin** se adaptează: explicațiile din comenzi, din
  Setări plăți, din fișa produsului sau din pagina ERP care spun „pleacă în ERP”,
  „se generează AWB-ul”, „returnează punctele”, „rămân pe EuPlătesc” își pierd
  bucata respectivă când pluginul nu e pe site (în `views/admin/orders.php`, de
  exemplu, prin `$cuErp('text cu ERP', 'text fără ERP')`). Datele vechi rămân așa
  cum s-au întâmplat: o comandă veche arată în continuare „Reducere cupon (COD)”,
  „Card BT” sau „ID tranzacție EuPlătesc”, ca totalul și istoricul să se lege.
- **Titlul pus de client chiar înaintea unui cod** de plugin ascuns
  (`<h2>Din blog</h2>{{latest_posts:3}}`, doar spații între ele) pleacă odată cu
  codul.
- **Etichete care depind de un plugin**: o intrare de meniu poate avea
  `eticheta_fara` și `icon_fara` (`'Setări magazin'` devine `'Setări site'` fără
  magazin, iar coșul de lângă „Trimite email test” devine un plic); la fel
  titlul paginii și tab-ul „Pagini magazin” / „Pagini site”.
- **Rolurile de administrator** legate de un plugin („Administrator magazin” fără
  catalog, „Administrator Blog” fără blog) nu se mai oferă în Administratori; un
  administrator care are deja rolul îl păstrează la salvare (câmp ascuns). După
  login, fiecare rol ajunge pe prima lui pagină care e pe site
  (`Auth::defaultPathForRole`), nu pe „Modulul nu este inclus în abonament”.
- **Setările ascunse se păstrează la salvare**: pe lângă Setări magazin și Setări
  plăți, și rândurile cu puncte din Coș flotant, bifa „încasează la aprobarea din
  ERP” a BT și restricția unui cupon pe utilizatori (fără conturi de clienți)
  rămân în bază neatinse.

Cu licența „toate” și cu licența de dezvoltare a Bioscem (lista explicită din
`licente_dev`, fără unealta internă) nimic din toate acestea nu schimbă vreun octet:
paginile-cheie (prima pagină, produs, coș, checkout, contul clientului, linkul de plată,
dashboard, comenzi cu fereastra comenzii, produse, clienți, cupoane, setări,
coșul flotant, administratori, emailuri, editorul de pagini, pe patru roluri de
admin), pagina pe care ajunge fiecare rol după login, sitemap-ul și harta adreselor
vechi se compară cu **codul Bioscem însuși**, la ultimul commit revizuit
(`compara-cu-baza.sh`, cu datele site-ului din `site-uri/bioscem/`). Singurele
diferențe sunt scrise una câte una, cu motivul lor, în `$deBioscem` din
`compara-cu-baza.php` și în `site-uri/bioscem/ce-se-schimba.md`: azi explicația
„Sitemap XML” din Setări magazin (pomenește și articolele) și locul regulii
`/cos-cumparaturi` în robots.txt, ambele din portarea generalizată a livrării 1 SEO, plus
rândul `Disallow: /_andaxi/` din robots.txt (WP-07); adresa nouă `/_andaxi/ping` (și cu „/” la sfârșit) e
declarată în `$coduriDeBioscem` (404 în Bioscem).

## Pașii de activare

Migrările fac aceeași schemă pe orice site, oricare ar fi pluginurile (OD-02), deci
un plugin nu-și face tabelele când pornește: le are deja. Ce mai pune un plugin pe
bază, o singură dată, când devine „pornit”, stă în `config/activari.php` (detaliile
în [MIGRARI.md](MIGRARI.md), „Pașii de activare ai pluginurilor”):

| Plugin | Pasul | Ce face |
|---|---|---|
| `acorduri_gdpr` | `pagina_acorduri_gdpr` | pagina `acorduri-gdpr` cu formularul, doar dacă nu există (nici ștearsă) |
| `erp` | `prag_trimitere_erp` | setarea `erp_trimite_din` = momentul pornirii, doar dacă lipsește |

Pașii rulează la prima cerere de după ce pluginul a devenit „pornit” (și în
`scripts/install.php`, `tools/dev/licenta.php`, cronul ERP), o singură dată pe bază,
doar pe o bază la zi cu migrările (pe una încă neadoptată sau în urma codului nu scriu
nimic și așteaptă `scripts/migreaza.php --aplica`): ce s-a făcut stă în setarea
`activari_pluginuri`. Un plugin trecut în „oprit” iese
de acolo, deci o repornire îi rulează pașii din nou; fiecare pas e idempotent (o
pagină care există nu se mai face, o cheie existentă nu se atinge). „Se oprește” nu
schimbă nimic. Fiecare pas rulat apare în Jurnal activitate („Plugin pornit: pas de
activare”, fără administrator). Un plugin nou care are conținut de pus pe bază își
scrie aici pasul, nu într-o migrare (migrările nu scriu conținut) și nu într-o
funcție chemată la fiecare cerere.

## Pluginurile, unul câte unul

„Se oprește” și „oprit” descriu ce se schimbă față de „pornit”. Datele nu se
șterg în niciuna dintre stări. Pentru toate: cu pluginul oprit, adresele lui de
admin dau „Modulul nu este inclus în abonament”, cele publice 404 (sau
redirecționarea din licență), intrările lui din meniu și din harta site-ului
dispar, iar linkurile spre el se scot din pagini.

### Catalog produse (`catalog`)

- **Conține**: meniul Magazin → Produse, Categorii produse, Câmpuri suplimentare,
  Template-uri produse; paginile `/magazin`, `/categorie/…`, `/marca/…`,
  `/eticheta/…`, `/produs/…`, căutarea și API-urile de produse; codurile
  `{{shop_catalog}}`, `{{shop_category}}`, `{{shop_title}}`,
  `{{featured_products}}`, `{{product_categories}}`, `{{products_by_template:…}}`, `{{produse_serviciu}}`; cardul „Produse” din
  dashboard; tab-ul „TVA” din Setări magazin; din livrarea 2 SEO (WP-R): adresele
  `/categorie/{slug}` ale categoriilor și 301-ul de la `/magazin?category=…`, rescrierea
  linkurilor vechi spre categorii din pagini, antet, meniu și subsol
  (`LinkuriCategorii`), câmpurile SEO (titlu, descriere) ale categoriilor și mărcilor
  din Admin → Categorii (`POST /admin/categories/brand-seo`) și descrierile automate ale
  listelor de produse (`SeoMeta`). Tabele: `products`,
  `product_categories`, `product_category_links`, `product_extra_*`,
  `product_template*`.
- **Vizibilitatea, ordinea și paginile catalogului (WP-19, din Biovitality și grafoanaytis)**, cu setările din
  `App\Support\Catalog` (implicitul nucleului e comportamentul nou; cele patru site-uri vechi își păstrează
  comportamentul prin înghețare, `site-uri/<site>/setari-inghetate.json`):
  - `catalog_ascunde_inactive` (`1`): un produs inactiv nu apare în nicio listă (magazinul, categoriile și numărătoarea
    lor, prima pagină, cele mai vândute, manechinul, `{{products_by_template:…}}`) și nu are pagină; se vede doar pe
    linkul lui de ciornă, `/produs/<slug>?draft=<cheie>` (`products.preview_token`, din migrarea `catalog/0026`;
    `hash_equals`; bara „Ciornă”, `Cache-Control: no-store, private`, `X-Robots-Tag: noindex, nofollow`, deci nici în
    cache-ul paginilor). Cu `0` (Bioscem, vertij, grafoanaytis înghețate; BV `1`) produsul inactiv apare ca înainte,
    iar linkul de ciornă merge oricum. Linkul îl dă Admin → Produse (editorul și eticheta „ciornă” din listă); un produs
    nou, sau unul fără cheie, o primește la salvare. Un produs inactiv nu se poate cumpăra, cu sau fără setare;
  - `products.sort_position` (Admin → Produse, „Poziție în liste”): locul fix în listele în ordinea bazei (prima pagină,
    produsele similare din alegerea adminului, `{{products_by_template:…}}`), `App\Support\ProductOrder::aseaza`, și
    cheia sortării „Ordinea din catalog” (`catalog`, `ProductOrder::dupaCatalog`: pozițiile crescător, apoi restul
    alfabetic), care există doar când `catalog_sortare_implicita` = `catalog` (grafoanaytis); altfel catalogul pornește
    cu `catalog_sortare_implicita` (`alpha_asc`) și `?sort=catalog` dă alfabetica. `catalog_sortare_vizibila` = `0`
    ascunde butonul sortării (grafoanaytis);
  - `products.hidden_in_catalog` („Ascuns din lista magazinului”): lipsește din `{{shop_catalog}}`, din
    `/api/shop/catalog` și din numărătoarea categoriilor, dar are pagina lui, e în harta site-ului și se poate cumpăra;
  - `catalog_pagina` (`magazin`): pagina din constructor care ține catalogul; alta (grafoanaytis: `produse`) se afișează
    exact ca `/magazin` (filtrul categoriei, căutarea cu noindex), `/magazin` face 301 acolo cu parametrii păstrați, iar
    harta site-ului și linkurile de rezervă („Vezi toate produsele”, pagina 404, coșul gol, mulțumirea, linkul de plată)
    duc acolo. Catalogul apare doar pe pagina din setare (verificarea BS-V1 din WP-10, o pagină Bioscem cu
    `{{shop_catalog}}` în afara „magazin”, n-a rulat; până atunci regula strictă);
  - câmpurile suplimentare (Admin → Câmpuri suplimentare): tipul „Imagine” (adresa unei imagini din galerie) și bifa
    „Apare doar la template-urile care îl folosesc” (`product_extra_fields.template_only`, `catalog/0027`): în editorul
    produsului câmpul apare doar când șablonul ales îl conține (cu mesajul celor ascunse și „Arată-le oricum”), iar pe
    pagina produsului nu intră în lista de informații și nici ca tab (nici câmpurile „Imagine”). Un câmp „html” intră în
    șablon ca markup cu `catalog_camp_html_brut` = `1` (BV), altfel escapat ca textul (Bioscem, vertij, grafoanaytis);
  - codurile `{{products_by_template:slug-sablon|limit=…|link=…|text=…|titlu=…|subtitlu=…}}` (produsele unui șablon, în
    cardurile catalogului: `views/site/components/product-card.php` și `product-card-styles.php`, aceleași în
    `{{shop_catalog}}`; un `link` doar spre o adresă a site-ului sau `http(s)://`), `{{produse_serviciu}}` (pe o pagină
    de serviciu, produsele categoriei cu slug-ul ultimului segment al paginii, în ordinea catalogului) și, în șabloanele
    de produs, `{{product_produse_similare}}` (produsele similare în cardurile simple `produse-categorie.php`, fără
    preț, stele și coș; titlul și subtitlul din `catalog_similare_titlu`/`_subtitlu`; legătura „Cere ofertă” doar cu
    `cereri_oferta` portat și pe site, WP-26, altfel „Vezi produsul”). Fără magazin, cardul catalogului nu mai are
    rândul de jos gol.
- **Are nevoie de**: nimic.
- **Se oprește**: ca pornit (nu începe nimic cu bani; produsele rămân vizibile cât
  magazinul își termină comenzile).
- **Oprit**: prima pagină fără secțiunile de produse, pagina 404 fără „Vezi toate
  produsele” și fără căutarea „poate e un produs vechi” (nici redirecționarea 301
  spre `/produs/…`, și fără o pagină „404” publicată), fără produse în harta
  site-ului și în căutare; editorul de pagini nu mai pune catalogul în sursa
  paginii; Google: fără „Merchant Center”.

### Magazin online (`magazin`)

- **Conține**: meniul Comenzi, Coș flotant, Automatizări, Email-uri ecommerce și
  ordinul de plată din Setări plăți; coșul, checkout-ul, `/checkout/succes/…`,
  coșul flotant, numărul din coș, prețurile și butoanele „Adaugă în coș”;
  emailurile de comandă (comandă nouă, schimbările de status, datele de plată
  OP); cronul `scripts/abandoned-cart-emails.php`; numerotarea comenzilor și
  cantitățile din Setări magazin; dashboard: „Comenzi” și „Comenzi recente”;
  codurile `{{cart_form}}`, `{{checkout_form}}`, `{{checkout_success_order_info}}`,
  `{{cart_count}}`; din livrarea 2 SEO (WP-R): fluxul `/feed/google-merchant.xml` (are
  prețuri; `FluxGoogleMerchant`, cu `Allow` în robots.txt) și livrarea și returul din datele
  structurate (`PoliticaLivrare`); **avertismentul pe produs** (Bioscem `0582ce9` …
  `4254ca4`, de exemplu „ATENȚIE – PRODUS REFRIGERAT”): cardul „Avertisment la coș și la
  finalizarea comenzii” din editorul produsului (titlu, text, eticheta bifei; toate goale =
  fără avertisment), fereastra de la adăugarea în coș, caseta și bifa obligatorie de deasupra
  butonului de plasare (verificată și la trimitere, pe coșul de atunci; `/api/cart/avertismente`
  reface caseta când coșul se schimbă fără reîncărcare), confirmarea pe comandă (Admin →
  Comenzi) și blocul din emailul de confirmare (`{{avertismente_produse}}`, sau singur sub
  produse); oprire de urgență `AVERTISMENT_PRODUSE=0` (textele și confirmările rămân);
  `scripts/avertisment-produse.php` pune textele unui site din `avertismente-produse.json`
  din dosarul lui (Bioscem: cele trei produse refrigerate); **transportul gratuit pe produs**
  (WP-20, C14a, din Biovitality `2b8a618`, `a14df64`): bifa „Transport gratuit pentru acest
  produs” din editorul produsului (`products.free_shipping`, migrarea `magazin/0028`); un singur
  produs bifat în coș face toată comanda fără transport, peste prag, prețul fix și tariful
  curierului (coșul arată „Ai transport gratuit pe comandă.”, cota FAN nu se mai cere), iar în
  admin (reducerea comercială, schimbarea produselor, trecerea pe FANbox) ordinea e: transportul
  pus de mână > produsul gratuit > pragul > transportul curent; livrarea din datele structurate și
  din fluxul Merchant e 0 lei la produsul bifat. Fără produs bifat (implicit), totul e ca înainte.
  Tabele: `orders`, `order_items`, `email_logs`, `cart_abandonments`, `product_warnings`,
  `order_product_warnings` (migrarea `magazin/0032`) și coloana `products.free_shipping`.
- **Are nevoie de**: catalog.
- **Se oprește**: checkout-ul și coșul arată „Momentan nu se mai pot plasa comenzi
  online.”; „Adaugă în coș” dispare de pe produse (cu același mesaj) și e refuzat
  și la cererile directe; cupoanele și punctele nu se mai aplică în coș. Comenzile
  existente se văd și se lucrează în admin (status, adresă, produse, note, AWB,
  rambursări), plățile lor se confirmă (adresele procesatorilor răspund),
  emailurile lor pleacă, sincronizarea cu ERP-ul și AWB-urile merg mai departe.
  Cronul coșului abandonat nu mai trimite (cere „pornit”).
- **Oprit**: fără coș, checkout, prețuri și butoane de coș pe tot site-ul;
  catalogul (dacă e pe site) rămâne vitrină: produsele fără preț, iar API-urile de
  produse nu mai dau prețuri. Emailurile de comandă nu mai pleacă. Contul
  clientului (dacă are conturi) arată comenzile vechi doar pentru citire, fără
  linkuri spre coș sau plată. Întoarcerea EuPlătesc (`POST /checkout/succes/…`)
  răspunde mereu rezultatului semnat al unei plăți (oricui altcuiva: 404). Setări
  magazin se numește „Setări site” (și tab-ul „Pagini site”, cu lista paginilor
  care chiar se generează); „Trimite email test” nu mai oferă și nu mai trimite
  (nici la cerere directă) șabloanele de comandă. Istoricul emailurilor fără
  coloana „Comandă” și fără „Retrimite toate eșuatele”; fără ferestrele ascunse ale
  emailurilor ecommerce și fără șablonul de start „Email comandă” în constructorul
  de newslettere. În API-urile catalogului: fără cantitățile vândute, „cele mai
  vândute” din ordinea catalogului. Setări magazin, mentenanța și cache-ul nu mai
  pomenesc plățile, checkout-ul și coșul; Google: conversia fără „finalizarea
  comenzii”; produsele: „Gestionează produsele tale”, iar rolul „Administrator
  magazin” se cheamă „Administrator produse” (pe un site cu catalog). Fără fluxul
  Merchant (404) și regula lui din robots.txt, fără livrare și retur în datele
  structurate, descrierile listelor fără prețuri și fără „Comandă online”; fără
  cardul avertismentului (un avertisment salvat rămâne în bază, neatins la salvarea
  produsului), fără fereastră, casetă și bifă. **Se oprește**, în plus: fluxul rămâne,
  fără produse, imediat (nu din cache-ul de 15 minute).

### Livrare FAN Courier (`livrare_fan`)

- **Conține**: Setări livrare (meniul Configurare); în fereastra comenzii: AWB,
  previzualizarea AWB, urmărirea, FANbox; în checkout: alegerea unui FANbox și
  tariful calculat live de FAN; AWB-ul generat automat la comanda plătită sau
  aprobată și emailul cu AWB; cu ERP-ul pe site, regula coletelor trimisă cu
  fiecare comandă (`coleteCalcul`: colet sau plic, kg maxim pe colet, minimul din
  setări, greutatea implicită, un singur AWB sau câte unul per depozit, cu
  aceleași valori implicite ca la emiterea AWB-ului) și numărul de colete ales în
  ERP la aprobare (`coleteAlese`, întreg 1–99; trece pe AWB când comanda pleacă pe
  un singur AWB; pe AWB-urile per depozit nu se aplică și se notează în jurnalul de
  activitate); `/api/fan/…`; cronurile `fan-tracking-sync`,
  `fan-lockers-sync`, `fan-nomenclator-sync` (cer „vizibil”). Tabele: `fan_*`,
  `orders.fan_*`, `orders.completed_awb_email_*` (emailul cu AWB-ul la finalizare).
- **Are nevoie de**: magazin.
- **Se oprește**: comenzile noi nu mai primesc FANbox și tarif live (rămân regulile
  de transport generale); comenzile existente își fac AWB-ul, urmărirea și
  emailul cu AWB; cronurile rulează.
- **Oprit**: nimic FAN în checkout, în comandă sau în meniu; niciun AWB nu se mai
  cere la FAN, de nicăieri: nici automat la plată, nici din aprobarea venită din
  ERP (comanda trece doar în procesare; răspunsul către ERP spune că livrarea nu
  se face prin FAN de pe site; un `coleteAlese` venit de acolo se ignoră, iar
  comenzile trimise în ERP nu mai poartă `coleteCalcul`), nici din acțiunea în
  masă din comenzi („AWB” în masă dă „Modulul nu este inclus în abonament”);
  datele de acces FAN rămân în
  setări, nefolosite. Exportul comenzilor fără coloana „AWB”. Cronurile ies cu o linie. În checkout localitatea se scrie de
  mână (nu se mai cere lista de localități FAN; județele rămân din datele
  salvate, altfel câmp liber). Explicațiile despre AWB din Automatizări, din
  pagina ERP și din comenzi dispar; emailurile „Comandă expediată” și „Comandă
  livrată” pornesc doar dintr-un AWB, deci nu mai pleacă automat (vezi mai jos).

### Plată Stripe / EuPlătesc / Banca Transilvania (`plata_stripe`, `plata_euplatesc`, `plata_bt`)

- **Conține**: opțiunea de plată din checkout; secțiunea procesatorului din Setări
  plăți (BT: tab-ul „Banca Transilvania”, testele, plata de 1 leu și butoanele
  din comandă: capturare, anulare, rambursare); adresa de confirmare
  (`/webhook/stripe`, `/webhook/euplatesc`, `/webhook/bt-ipay`,
  `/checkout/bt/retur`) — mereu deschisă; BT: cronul `bt-ipay-sync` („vizibil”).
  Tabele: `orders.stripe_*`, `orders.euplatesc_*`, `bt_ipay_transactions`,
  `bt_ipay_log`.
- **Are nevoie de**: EuPlătesc și BT de magazin; Stripe de nimic (îl folosesc și
  donațiile, și membrii).
- **Se oprește**: procesatorul nu mai apare în checkout și nu se mai trimit linkuri
  de plată noi prin el; plățile începute se confirmă, capturile și rambursările
  din comandă merg, linkurile deja trimise se pot plăti; setările lui se văd. BT:
  plata de test de 1 leu nu mai pornește (butonul e inactiv, cu explicație, iar
  cererea directă e refuzată); testele începute se pot anula sau rambursa.
- **Oprit**: dispare din checkout, din comandă și din Setări plăți (rândul lui din
  tabelul de sus, tab-ul BT); salvarea paginii nu-i atinge setările; adresa de
  confirmare răspunde în continuare (prima cerere din zi se notează în
  `storage/logs/pluginuri.log`); fără BT, `/checkout/bt/retur` fără o plată pornită
  din sesiunea vizitatorului dă 404; editorul de pagini nu mai arată procesatorul
  în previzualizarea checkout-ului. Un link de plată deja trimis prin EuPlătesc se
  plătește prin BT dacă BT e pe site; dacă procesatorul linkului nu mai e pe site
  deloc, pagina `/plata/…` arată suma și cere clientului să ne contacteze (nu mai
  pornește nicio plată). Textele BT care trimit la EuPlătesc („linkurile rămân pe
  EuPlătesc”) se schimbă.
- **Stripe, cererile spre API** (`App\Support\StripeGateway`, WP-15, VT-C10 din vertij): pe lângă Checkout, și
  PaymentIntent, Customer, Product și Subscription (creare, citire, anulare cu DELETE) pentru donații și membri
  (Etapa 4), cu `Idempotency-Key` la creări, și lista endpoint-urilor de webhook. Antetul `Stripe-Version` vine din
  setarea `stripe_api_version` (fără câmp în admin; goală = fără antet, contul folosește versiunea lui implicită, ca
  până acum pe toate cele patru site-uri); webhook-urile urmează versiunea endpoint-ului, nu antetul. Pentru teste,
  `STRIPE_API_BASE` poate trimite cererile la un server local (doar `http://127.0.0.1:…` sau `http://localhost:…`).

### Link de plată pentru diferențe (`link_plata`)

- **Conține**: butonul „Trimite link de plată” din comandă; pagina `/plata/…`.
  Tabel: `order_payment_links`. Procesatorul unui link nou: Banca Transilvania (pornită în
  producție), altfel EuPlătesc, altfel **Stripe** (WP-20, X5, din Biovitality: pornit în Setări
  plăți, cu cheia secretă): o sesiune Stripe Checkout pe referința linkului, confirmată de
  webhook-ul `checkout.session.completed` (suma verificată în bani) sau la întoarcerea clientului;
  suma se adaugă la încasat, starea comenzii nu se schimbă. Un link își păstrează procesatorul.
- **Are nevoie de**: magazin și cel puțin o plată cu cardul.
- **Se oprește**: nu se mai trimit linkuri noi; cele trimise se pot plăti.
- **Oprit**: butonul dispare, `/plata/…` dă 404.

### Legătura cu ERP-ul ANDAXI (`erp`)

- **Conține**: Setări → ERP ANDAXI; în comenzi: starea ERP, „Retrimite în ERP”,
  bannerul modulului „Magazin online” din ERP; stocul din ERP pe produse și în
  coș; expeditorul FAN pe gestiuni; cronul `scripts/erp-sync.php` („vizibil”);
  adresele `/api/erp/notificare` și `/api/erp/adrese` (mereu deschise). Tabele:
  `orders.erp_*`, `order_items.erp_*`.
- **Are nevoie de**: magazin. Ține de bani (`'bani' => true` în catalog, ca plățile):
  la CRM trece în „oprit” cel mai devreme după 30 de zile de „se oprește” (OD-36, D7);
  pe site nimic nu citește încă marcajul.
- **Pornește**: pasul de activare scrie `erp_trimite_din` = momentul pornirii, doar
  dacă setarea lipsește cu totul (o valoare goală, ca la înghețarea Bioscem,
  înseamnă „fără prag” și rămâne). Trimiterile o respectă din WP-12 (E1;
  `ErpSync::pragTrimitere` și `filtruPrag`): pornit pe un site care nu l-a avut, ERP-ul nu
  primește tot istoricul „pending”. Cronul `erp-sync.php` și „Retrimite” din Setări → ERP ANDAXI
  nu trimit istoricul pe care ERP-ul nu l-a văzut niciodată: comenzile plasate înainte de prag,
  netrimise și neîncercate de atunci (cronul scrie de ce); contoarele „În așteptare” și „Eșuate”
  le numără la fel (pe acestea separat, cu o notă), iar Admin → Comenzi le arată cu „ERP: înainte
  de legare” (`ErpSync::comenziTinuteDePrag`, același filtru); 🔄 trimite una anume de mână, cu
  confirmare. O comandă de dinainte ajunsă în ERP, sau a cărei trimitere a pornit de la prag (🔄,
  plata, confirmarea OP, o modificare din admin; ținută și de modulul „Magazin online” oprit în
  ERP), e ca oricare alta (`ErpSync::filtruPrag`): o trimitere eșuată a ei se reîncearcă automat,
  cum spune fereastra comenzii („se reîncearcă automat”), iar plata, anularea sau returul unei
  comenzi trimise merg ca înainte (`ErpSync::push`). Cheia goală: fără prag, ca înainte; cheia lipsă (pasul de activare n-a rulat,
  de exemplu baza nu e la zi cu migrările) sau o valoare care nu e o dată: nicio comandă nu pleacă
  automat până la scrierea pragului.
- **Stocul din ERP** (WP-12, C3, din Biovitality): pe paginile magazinului ERP-ul e întrebat cu un
  timeout de 3 secunde (`ErpClient::fromDbRapid`; trimiterile și restul operațiilor folosesc
  `erp_timeout`); un eșec se ține minte 60 de secunde (`cache/erp-stock-esec`: între timp nu se mai
  întreabă deloc), la fel un SKU pe care ERP-ul nu-l cunoaște (`necunoscut_la`, în `cache/erp-stock.json`).
  Rezultatul rămâne „fail open”: fără răspuns, sau pentru un SKU necunoscut, rămâne stocul de pe site.
- **Se oprește**: comenzile plasate **înainte** de oprire pleacă în ERP ca de
  obicei, oricând li s-ar confirma plata (EuPlătesc, BT, Stripe, OP) sau ar trece
  prin reîncercările cronului; doar o comandă plasată **după** momentul în care
  ERP-ul a intrat în „se oprește” (ținut minte în `stare-aplicata.json`) nu mai
  pleacă: stă „în așteptare”, cu motivul scris pe ea, și pleacă singură dacă
  pluginul e pornit din nou (fără să consume din încercări). Dacă ERP-ul se
  oprește doar pentru că se oprește magazinul (licența îl are „pornit”),
  checkout-ul e închis și toate comenzile de pe site pleacă. Comenzile trimise își
  duc mai departe modificările, anulările, retururile și AWB-ul; stocul se citește
  în continuare; cronul rulează.
- **Oprit**: site-ul nu mai vorbește deloc cu ERP-ul (`ErpClient` nu se mai
  creează): fără trimiteri, fără stoc și gestiuni din ERP; anulările și retururile
  comenzilor trimise așteaptă pe starea lor; meniul și coloanele ERP dispar;
  cronul iese cu o linie. Explicațiile care spun ce face ERP-ul (în comenzi, în
  Setări plăți, în fișa produsului: „pleacă în ERP”, „factura există deja în
  ERP”, „aprobarea din ERP”; în Setări livrare, numărul de colete schimbat din
  ERP) se scurtează la ce se întâmplă pe site; bifa BT
  „încasează la aprobarea din ERP” se ascunde și își păstrează valoarea; o
  precomandă eliberată doar iese din așteptare. Emailurile BT către magazin
  („aprobând comanda în ERP”, „nu fuseseră aprobate în ERP la timp”) pierd bucata
  despre ERP. „Retrimite în ERP” în masă dă „Modulul nu este inclus în
  abonament”. Cât pluginul e pornit, `ErpModul` (starea modulului din ERP)
  lucrează exact ca înainte.

### Cupoane (`cupoane`)

- **Conține**: meniul Cupoane și Cupoane unice (cu produsele schimbate în masă, WP-21 C12, din
  Biovitality `222ed71`); câmpul de cupon din coș, din
  coșul flotant și din checkout; reducerea cu cupon din fereastra comenzii.
  Tabel: `coupons`. Un cod scris cu spații („AZ 987”) găsește cuponul AZ987 când nu există
  exact așa (WP-20, C9, din Biovitality `aed0cee`); comanda ține codul din bază. În tranzacția
  comenzii, rândul cuponului se blochează (`SELECT … FOR UPDATE`) și cuponul se verifică din nou
  (unicul nefolosit, `max_uses_total` sub limită; marcarea unicului trebuie să schimbe exact un rând):
  altfel comanda nu se creează și clientul e trimis în coș cu „Cuponul a fost deja folosit.”.
- **Are nevoie de**: magazin.
- **Se oprește**: câmpul de cupon dispare, un cupon nu se mai aplică unei comenzi
  noi (cererea directă e refuzată, cu mesaj); comenzile existente își păstrează
  reducerea.
- **Oprit**: la fel, plus meniul, reducerea cu cupon din comandă și coloana
  „Cupon” din exportul comenzilor; previzualizările din editorul de pagini fără cupon.

### Puncte de fidelitate (`fidelitate`)

- **Conține**: meniul Puncte fidelitate; punctele din coș, checkout și contul
  clientului; punctele din fereastra comenzii și din fișa clientului. Tabele:
  `loyalty_points_*`, `users.loyalty_points`, `orders.loyalty_*`.
- **Are nevoie de**: magazin și conturi de clienți.
- **Se oprește**: comenzile noi nu mai folosesc și nu mai câștigă puncte (nici
  afișarea „Primești … puncte”); comenzile existente își primesc punctele la
  finalizare, iar la anulare punctele se întorc.
- **Oprit**: punctele nu se mai văd nicăieri și nu se mai acordă; întoarcerea
  punctelor la anulare merge oricând (banii clientului). Rândurile cu puncte din
  Setări coș flotant dispar și își păstrează valorile la salvare. Nici
  previzualizările coșului și ale contului din editorul de pagini nu le mai arată.

### Recenzii produse (`recenzii`)

- **Conține**: tab-ul și formularul de recenzii de pe produs, stelele din catalog,
  `{{product_review_form}}`, `/review-form/…`, meniul Recenzii (moderare). Tabel:
  `product_reviews`. **Anti-spamul** (WP-21, C10, din Biovitality `8ba8ddd`, `0c682b3`;
  `App\Support\ReviewGuard`, migrarea `recenzii/0030`): câmpul-capcană `review_website`,
  formularul trimis la sub 3 secunde, peste 4 recenzii pe oră de la aceeași adresă (regula
  `IpClient`, IPv6 pe /64), același text în 30 de zile, tipare de atac (SQLi, XSS, JNDI),
  linkuri, BBCode, domenii de test, nume generate. Recenzia suspectă se salvează marcată
  (`is_spam`, `spam_reason`, cu adresa în `ip_address`) și stă în filtrul „Spam”, nu în
  „Pending”; „Detectează spamul” (recenziile primite înainte), „Golește spamul”, „Spam” / „Nu e
  spam” pe rând. Bulina din meniu cu recenziile care așteaptă (fără spam), după setarea
  `recenzii_insigna_meniu` (nucleul 1; Bioscem înghețat 0). Adresa unei recenzii se golește după
  `recenzii_ip_zile` zile (implicit 90, recomandarea OD-15; 0 = cât recenzia), la o recenzie nouă, la
  deschiderea listei din admin și la fiecare rulare a cronului `scripts/abandoned-cart-emails.php`
  (pe un site fără magazin, doar primele două). Un formular fără `review_opened_at` e primit
  `ReviewGuard::ZILE_GRATIE_FARA_MOMENT` (7) zile după migrarea `recenzii/0030` (paginile din cache
  n-au câmpul), apoi e spam („formular fără momentul deschiderii”). „Aprobă” scoate recenzia din spam;
  „Golește spamul” șterge doar spamul neaprobat.
- **Are nevoie de**: catalog.
- **Se oprește**: recenziile existente se văd, cele noi nu se mai primesc.
- **Oprit**: nimic despre recenzii pe site sau în admin: nici codurile și tab-ul
  „Coduri recenzii” din constructorul de șablon de produs, nici numărul și media
  recenziilor în API-urile catalogului și în produsele similare.

### Precomenzi (`precomenzi`)

- **Conține**: vânzarea fără stoc (eticheta „Precomandă”, limitele), câmpurile de
  precomandă din formularul de produs, coloana și butonul „Eliberează spre ERP”
  din comenzi. Coloane: `products.preorder_*`, `orders.preorder_*`.
- **Are nevoie de**: magazin.
- **Se oprește**: produsele fără stoc nu mai pot fi precomandate; precomenzile
  plasate se eliberează normal din comandă.
- **Oprit**: nimic despre precomenzi pe site sau în admin; câmpurile de precomandă
  ale produselor rămân salvate (formularul de produs nu le atinge). Fără ERP,
  textele lor nu mai pomenesc „Comenzi site” din ERP: precomanda „așteaptă până
  apeși ▶”.

### Oferte BBD (`oferte_bbd`)

- **Conține**: alegerea lotului cu dată minimă de valabilitate pe produs, panoul
  lateral „Oferte”, câmpurile BBD din formularul de produs, tab-ul „Widgeturi” din
  Setări magazin. Coloane: `products.bbd_*`, `order_items.bbd_key`.
- **Are nevoie de**: magazin.
- **Se oprește**: ofertele nu mai apar și nu se mai aplică în coș; comenzile cu
  oferte își păstrează prețul.
- **Oprit**: la fel, plus câmpurile din admin (rămân salvate pe produs).

### Produse promoționale (`produse_promo`)

- **Conține**: meniul Produse promoționale; mostrele și cadourile din fereastra
  comenzii și raportul pe client. Tabele: `promotional_products`,
  `order_promo_items` (WP-21, C13, din Biovitality `657c0cf`, `2ebc0cb`, `9cca65b`: „dat la” și
  „dat de”, `given_at`/`given_by` din `produse_promo/0031`, păstrate peste re-salvările comenzii;
  exportul Excel `GET /admin/promo-products/export`, cu `App\Support\XlsxWriter` din nucleu: foile
  „Produse” și „Cui a fost dat”, pe intervalul filtrat; fereastra „Cui a fost dat” derulează).
- **Are nevoie de**: magazin.
- **Se oprește**: ca pornit (se folosește doar în admin, pe comenzile existente).
- **Oprit**: nimic promo în comenzi sau în meniu.

### Conturi clienți (`conturi_clienti`)

- **Conține**: `/login`, `/register`, `/contul-meu/…` (profil, comenzi, adrese,
  resetarea parolei), autentificarea cu Google, `{{account_section}}`,
  `{{auth_google_button}}`; meniul Listă utilizatori și Setări utilizatori;
  dashboard: „Utilizatori” și „Utilizatori recenți”; emailul de bun venit. Tabele:
  `users`, `user_addresses`, `customer_password_resets`.
- **Are nevoie de**: nimic.
- **Se oprește**: ca pornit (clienții își văd contul și comenzile).
- **Oprit**: fără conturi pe site (linkurile „Contul meu”/„Autentificare” se scot);
  Securitate formulare arată doar încercările de comandă (și dispare cu totul fără
  magazin);
  emailurile de comandă duc la prima pagină în loc de „Comenzile mele”; emailul
  de bun venit nu mai pleacă (nici în „Trimite email test”). La cupoane,
  restricția „Doar pentru utilizatori” dispare și se păstrează la salvare.

### Blog (`blog`)

- **Conține**: `/blog`, articolele, API-ul blogului, codurile `{{blog_posts}}`,
  `{{latest_posts}}`, `{{category_*}}`, `{{next_event_*}}`, articolele de pe prima
  pagină, meniul Blog; videoul postării și al evenimentului următor (`VideoEmbed`, din
  vertij `0fc2b2c`: YouTube, Vimeo sau un fișier video urcat în Galerie; playerul YouTube
  sau Vimeo se încarcă singur doar cu acordul pentru toate cookie-urile, altfel la apăsarea
  casetei „Pornește videoclipul”; Vimeo cu `dnt=1`). Tabele: `blog_*`.
- **Blogul avansat** (WP-22, din Biovitality `c078bd5` … `6ea2cb1`; migrarea `blog/0033`):
  etichetele (`blog_tags`, `blog_post_tags`), bibliografia (`blog_posts.references_html`), lățimea
  copertei (`featured_image_width`), id-ul din WordPress (`wp_post_id`). Comutatorul
  `blog_avansat` (OD-13 luată ca recomandarea, de confirmat de proprietar: un singur comutator; nucleul 1, Bioscem înghețat 0,
  `App\Support\BlogAvansat`): pe `/blog` căutarea (`?q=`, fără grijă la diacritice, cu termenul
  evidențiat; `App\Support\BlogSearch`) și chips-urile categoriilor cu articole publicate (cu
  numărul lor; `?categorie=`, `?eticheta=`); în articol cuprinsul automat (ancore pe H2/H3,
  `App\Support\BlogToc`), iar șablonul implicit are în plus cuprinsul, bibliografia, etichetele,
  articolele similare și stilurile H4–H6; secțiunea veche „Articole interesante” a unui șablon
  (`data-blog-related-list`, umplută din JavaScript la întâmplare) o umple serverul cu articole
  din aceleași categorii și etichete (`App\Support\BlogRelatedPosts`). Cu el oprit, `/blog` și
  articolele sunt cele de dinainte. **Mereu** (fără date noi nu schimbă nimic): bibliografia și
  citările „[1]” legate de ea (`App\Support\BlogReferences`, ancora `#surse` păstrată), etichetele
  articolului (adăugate la sfârșit unui șablon fără `{{blog_tags}}` / `{{blog_references}}`),
  coperta sub 100%, tokenurile noi într-un șablon (`{{blog_toc}}`, `{{blog_references}}`,
  `{{blog_tags}}`, `{{blog_related_posts}}`, `{{blog_image_width}}`), paginile
  `/blog/categorie/{slug}` și `/blog/eticheta/{slug}` (o categorie inactivă sau un slug necunoscut:
  404, pagina magazinului). Stilurile și derularea lină (`public/assets/css/blog-avansat.css`,
  `public/assets/js/blog-anchors.js`) se încarcă doar pe un articol care le folosește. Textele
  listei și ale articolului din setări (DB-13): `blog_eticheta_lista`, `blog_titlu_lista`,
  `blog_descriere_lista`, `blog_autor_implicit` (gol = numele site-ului; și inițiala lui),
  `blog_categorie_implicita`; fiecare site vechi le are înghețate la ale lui.
- **Editorul blogului avansat** (WP-23, din Biovitality `c078bd5`, `9c549c7`, `49c4f5b`, `3ea6a31`,
  `e2c33d3`): H5/H6 și „Text normal”, aliniere, chenarul pozelor din text (lățimea în procente),
  lățimea copertei, bibliografia (cu numerotare și renumerotare), etichetele (create la salvare,
  cu sugestii; `syncBlogPostTags`), dialogul de link (Ctrl+K, cu trimiterile la bibliografie),
  textul alternativ al pozelor, cardul Google (60/155 de caractere), previzualizarea desktop/telefon
  (`POST /admin/blog/posts/preview`, același cod ca pe site), „Salvează ca draft”; slug-ul unei
  postări noi păstrează literele cu diacritice (`Slug::make`); duplicarea copiază și bibliografia,
  lățimea copertei și etichetele; lista din admin își schimbă paginile fără reîncărcare. Câmpurile
  noi se scriu într-o instrucțiune separată, care tace pe o bază fără migrarea `blog/0033` (postarea
  se salvează ca înainte). Editorul se reface în Faza D.
- **Are nevoie de**: nimic. **Se oprește**: ca pornit. **Oprit**: nimic din blog
  pe site, în pagini, în meniu sau în harta site-ului; titlul pus chiar înaintea
  codului (`<h2>Din blog</h2>`) pleacă odată cu el; rolul „Administrator Blog” nu
  se mai oferă (cine îl are îl păstrează); paginile de categorie, de etichetă și căutarea
  (`/blog/...`, `/blog?q=`) dau 404 ca restul blogului.

### Newsletter (`newsletter`)

- **Conține**: meniul Template-uri, Liste abonați, Formulare opt-in, Statistici
  (și campaniile); formularele de abonare; cronul
  `scripts/newsletter-campaigns.php` („vizibil”); dezabonarea și urmărirea
  deschiderilor/clickurilor (mereu deschise). Tabele: `newsletter_*`,
  `orders.nl_campaign_id`. Din Biovitality (WP-21, C11): destinatarii unei campanii filtrați și
  paginați pe server, fără reîncărcarea paginii (`95f904f`, `f4dd2c0`); diagnosticul unei campanii
  în `tools/newsletter/diagnostic-campanie.php` (doar numere, fără adrese); blocurile din coloane fără
  fundal propriu și cu aliniere (`b9fcaf8`; un fundal gol nu mai scrie `background:;`); blocul
  „Video / TikTok” (`84e29a9`: coperta cu buton, nu player; `POST /admin/emails/builder/video-lookup`
  ia titlul și coperta prin oEmbed de la TikTok, YouTube, Vimeo și păstrează coperta în
  `uploads/video-thumbs`; ruta e și a magazinului, ca preview-ul); popup-ul de abonare în
  `public/assets/{css,js}/optin-popup.*` (`855dab2`, folosit de antetul Biovitality).
- **Are nevoie de**: nimic.
- **Se oprește**: campaniile pornite se termină (cronul le duce la capăt); „Trimite
  acum” și „Programează” sunt refuzate, iar cronul nu mai pornește campaniile
  programate (rămân programate). Linkurile din newsletterele trimise merg.
- **Oprit**: nimic din newsletter în admin sau pe site; cronul iese cu o linie;
  dezabonarea și linkurile din newsletterele trimise merg în continuare (cu
  semnătura lor); un link de dezabonare sau de urmărire nevalid dă 404. Pagina
  Email-uri (emailurile magazinului, formularele de contact, istoricul) își
  schimbă descrierea.
- **Oricând**: linkul de urmărire a clickurilor duce în afară doar dacă adresa e
  semnată de noi (era o trambulină spre orice site); altfel, la prima pagină a
  site-ului (adresa din `APP_URL`, nu bioscem.ro). Un link relativ din newsletter
  duce în site.

### Chat live (`chat_live`)

- **Conține**: bulina tawk.to de pe site (pornită doar după acordul pentru
  cookie-uri, cu numele și emailul clientului logat), tab-ul „Chat live” din
  Setări magazin. Setări: `tawk_*`. Fără tabele.
- **Are nevoie de**: nimic.
- **Se oprește**: bulina nu mai apare (o conversație nouă nu mai începe); tab-ul se
  vede.
- **Oprit**: nici bulina, nici tab-ul; setările rămân în bază.

### Manechin (`manechin`)

- **Conține**: `{{mannequin_section}}` (zonele corpului cu produsele recomandate),
  Configurare → Configurare manechin. Setări: `mannequin_*` (punctele în
  `mannequin_points_json`).
- **Are nevoie de**: catalog.
- **Se oprește**: ca pornit.
- **Oprit**: codul se scoate din pagini, meniul dispare, adresa de admin dă
  „Modulul nu este inclus în abonament”.

### Acorduri GDPR (`acorduri_gdpr`)

- **Conține**: pagina `acorduri-gdpr` cu `{{gdpr_agreements_form}}` (formularul
  de acord semnat pe tabletă), `/gdpr-agreements/…`, Utilizatori → Acorduri GDPR
  (lista, exportul Excel și blocul „Datele operatorului”, salvat la
  `POST /admin/pages/gdpr-agreements/settings`). Tabel: `gdpr_agreements`.
- **Setări** (WP-18, din grafoanaytis): `gdpr_formular_varianta` (`general`, implicit:
  „în scopul …”, CUI, compania, funcția, domeniul de activitate; `medical`: „înscrierea
  la evenimentul medical …”, CUIM, instituția medicală, tipul medicului, specializarea;
  etichetele exportului Excel o urmează), `gdpr_scop`, `gdpr_operator_{nume, sediu,
  telefon, email, regcom, cui, marca, reprezentant}` și `gdpr_operator_adresa_retragere`
  (goală = „<denumire> - <sediu>”). O valoare goală apare în formular ca spațiu de
  completat (iar stilul lui se scrie doar atunci); fără email nu apare linkul.
  Bioscem, Biovitality și vertij au textul de azi prin înghețare (cu reprezentantul,
  telefonul și emailul ca valori private), grafoanaytis varianta `general`.
- **Are nevoie de**: nimic.
- **Pornește**: pasul de activare face pagina `acorduri-gdpr` o singură dată, dacă
  nu există. O pagină ștearsă de admin rămâne ștearsă (înainte de migrări se
  readucea la fiecare cerere de admin); o readuce butonul „Generează pagini
  necesare” din Setări magazin.
- **Se oprește**: ca pornit.
- **Oprit**: pagina dă 404 (și scrisă altfel), nu se mai creează la generarea
  paginilor; meniul dispare; semnăturile rămân în bază.

### Neportate încă

Există în alte site-uri și se portează mai târziu; în catalog sunt deja, cu
dependențele lor, fără nicio rută, meniu, cron sau email (deci „oprit” și „pornit”
nu schimbă încă nimic):

| Cheie | Din site-ul | Are nevoie de | Tabele (la portare) |
|---|---|---|---|
| `pachete_produse` | Biovitality | magazin | `products.bundle_items_json` |
| `donatii` | vertij-tinitus.ro | plata_stripe | `donations` |
| `membri` | vertij-tinitus.ro | conturi_clienti, plata_stripe | `professions`, `profession_fields`, `account_verification*`, `account_activations` |
| `cereri_oferta` | grafoanaytis.ro | catalog | `quote_requests` |
| `fisiere_cloud` | Biovitality | nimic | `admin_app_tokens`, `bunny_*`, `file_locks`, `file_versions` |
| `concurenta` | Biovitality | nimic | `competition_partners`, `competition_prices` |

### Unealta internă `migrare_wordpress`

Nu se vinde și nu apare în pachete; o pune doar ANDAXI în licență, pe durata unei
migrări. **Conține**: importul din backup WordPress (UpdraftPlus) din Utilizatori →
Import utilizatori (`POST /admin/users/import-backup`); după un import fără erori (conturi, adrese,
abonați) al unui fișier citit până la capăt (rândul „-- Dump completed” al lui mysqldump sau coada
gzip, ISIZE, egală cu ce s-a citit) fișierul se șterge din `storage/import` (are datele clienților) și
mesajul o spune; altfel rămâne, cu motivul în mesaj (o arhivă tăiată, erori). Importul
articolelor dintr-un export WordPress (WXR; WP-23, din Biovitality `14d19dc`), în linia de
comandă: `$PHP tools/migrare-wordpress/import-wordpress-posts.php <export.xml> [--dry-run]`
(cere și pluginul blog și migrarea `blog/0033`; refuză cu codul 2 altfel): titlul, slug-ul,
conținutul curățat, rezumatul, data, ciorna, autorul, categoriile, etichetele, coperta, bibliografia
(câmpul „surse”), titlul și descrierea SEO; pozele de pe domeniile exportului se aduc în
`uploads/blog/<an>/<lună>` (pe copia de verificare nu); o rulare repetată actualizează (după
`blog_posts.wp_post_id`), nu dublează; `--post-type` (implicit `post`; Biovitality: `project`; cu 0
postări scriptul arată tipurile din export). **Nu suprascrie** (revizuirea WP-23): o postare din CMS
fără `wp_post_id` cu același slug (se adaugă una nouă, cu slug-ul liber următor; `--adopta-dupa-slug`
o preia; `--dry-run` listează potrivirile separat), una din coș (sărită și raportată, nu se scoate din
coș) sau una adusă de import și editată după aceea în admin (`updated_at > created_at`: importul le
scrie egale; sărită, decât cu `--suprascrie`; o salvare care schimbă doar etichetele, categoriile sau
SEO nu atinge rândul). Termenii (categorii, etichete, autori) se fac în tranzacția postării; o eroare
la ei, la legături sau la SEO anulează postarea, se numără și golește memoria termenilor. **Pozele**:
doar http/https, doar de pe domeniile exportului și `--extra-host` (verificat și după fiecare
redirecționare, urmărite de mână), doar spre adrese IP publice (nu private, loopback, link-local,
rezervate; adresa fixată cu `CURLOPT_RESOLVE`, fără proxy), fără svg, cel mult 15 MB, Content-Type și
conținut jpeg/png/webp/gif/avif; un fișier existent cu același nume e refolosit doar cu același
conținut, altfel imaginea primește „-2” (`CMS_DEV_IMPORT_ADRESE_LOCALE=1` permite 127.0.0.1, doar
pentru teste). **Fișierul exportului se șterge** (are emailurile autorilor) doar când toate sunt
adevărate: e un export WordPress (`<wp:wxr_version>`), are cel puțin o postare de tipul cerut, fără
`--limit` și `--only-published`, nicio imagine eșuată, nicio eroare, nicio postare editată sărită, e în
`storage/import`, nu e `--dry-run` sau `--pastreaza-sursa`; altfel scriptul spune de ce a rămas. **Oprit**: nu apar nici formularul, nici explicația lui; importul
CSV/XLSX de clienți rămâne (e al conturilor de clienți); importul articolelor refuză.

## Ce nu se poate ascunde fără o refacere mai adâncă

- **Setări livrare** ține la un loc tarifele FAN și regulile generale de transport
  (prag de transport gratuit, cost fix). Pagina e a pluginului FAN, deci un magazin
  fără FAN nu-și poate schimba din admin regulile generale (se aplică ce era
  salvat). Trebuie despărțită în două pagini.
- **Livrarea fără FAN**: transportul e azi doar FAN. Un magazin fără pluginul FAN
  primește comenzi (localitatea scrisă de mână, costul din regulile generale), dar
  emailurile „Comandă expediată” și „Comandă livrată” pornesc doar din AWB-ul FAN,
  deci nu mai pleacă singure, iar statusurile de livrare se pun de mână (coloana
  internă `orders.shipping_method` primește tot `fan_courier`; nu se afișează și
  nu pleacă nicăieri). E nevoie
  de o livrare generică în magazin (status „expediată” pus din admin, cu emailul
  lui), apoi FAN devine doar un curier printre altele.
- **Datele vechi ale comenzilor**: tot ce s-a salvat pe o comandă se arată și cu
  pluginul oprit, ca istoric: textele venite din ERP (note, probleme, „client
  agresiv”, discountul pus din ERP), „Card BT” / „ID tranzacție EuPlătesc”,
  „Reducere cupon (COD)”, numărul AWB. Fără ele totalurile și istoricul nu s-ar
  mai lega.
- **Coloana `erp_status`** are implicit „pending”: pornit după luni de pauză,
  pluginul ERP ar trimite și comenzile vechi, încă netrimise. Pragul
  `erp_trimite_din` (pasul de activare, mai sus) se scrie doar o dată, când cheia
  lipsește; la o repornire după o pauză trebuie ales din nou de la ce dată se
  trimit (Etapa 2, la CRM).
- **Precomenzile ținute**: cu pluginul oprit, butonul de eliberare dispare, deci
  precomenzile plasate și neeliberate rămân ținute până la repornire.
- **Textul implicit al emailului „Comandă livrată”** invită la o recenzie; e text
  editabil de client în Email-uri ecommerce, nu se ascunde singur fără recenzii.
- **Emailurile tranzacționale și formularele de contact** stau, ca în Bioscem, în
  aceeași pagină cu newsletterul (tab-urile nucleului rămân și fără newsletter).
- **Schema bazei**: migrările fac pe orice site și tabelele și coloanele
  pluginurilor oprite (OD-02, [MIGRARI.md](MIGRARI.md)); nu se văd, dar există.
- **Pagina de prezentare de rezervă** (`views/site/home.php`, folosită când nu e
  nicio pagină „acasă” în admin) are texte Bioscem; linkurile ei spre pluginuri
  ascunse se scot, textul rămâne.
- **Constructorul de meniu** din Design arată mai departe intrările deja puse în
  meniu spre pagini ale pluginurilor ascunse (sunt datele clientului; pe site
  filtrul le scoate). Doar lista de pagini propuse e filtrată.
- **Link direct în admin**: o pagină de plugin din baza de date (de exemplu
  `/admin/pages/{id}/edit` pentru „coș”) se deschide dacă cineva are adresa; din
  liste și meniuri nu mai e accesibilă.
- **Codul JavaScript** care construiește adrese în browser (ferestrele din comenzi)
  nu trece prin filtru; secțiunile lui sunt ascunse în PHP. Testul cu Playwright
  deschide ferestrele de detalii și de acțiuni ale comenzilor și fișa produselor,
  dar nu și celelalte ferestre deschise la click (harta FANbox, constructorul de
  newslettere, editoarele de șabloane).
- **Textul scris de client** în pagini, antet, subsol sau în emailurile editabile:
  linkurile și formularele spre pluginuri ascunse se scot, codurile `{{…}}` și
  titlul pus chiar înaintea lor la fel, dar orice alt text care vorbește despre un
  plugin (o frază „Vezi blogul nostru” fără link, un paragraf despre livrare FAN)
  rămâne: e conținutul clientului și se schimbă din admin. La fel JavaScript-ul
  scris de client în antet sau în pagini (de exemplu o căutare de produse care
  cheamă `/api/search/products`): primește 404 de la gardă și nu arată nimic.
- **Rolurile de administrator** sunt tot cele patru din Bioscem, cu prefixele de
  adrese scrise în `Auth::canAccessPath`; pluginurile nu-și declară încă rolurile.
  Rolurile legate de un plugin se ascund (vezi mai sus), dar un plugin nou cu
  pagini de admin are nevoie de o declarație de rol în catalog.
- **Setări plăți pe un site fără magazin** (Asociație, cu Stripe pentru donații):
  pagina arată Stripe, dar explicațiile vorbesc încă de checkout și comenzi;
  se rescriu când se portează donațiile și membrii.
- **Șabloanele de email ale magazinului** („Comandă expediată”, „Comandă livrată”)
  rămân în Email-uri ecommerce și fără FAN (sunt textul clientului); nu se mai
  trimit singure (vezi „Livrarea fără FAN”).
- **Ultima licență aplicată** (`stare-aplicata.json`) nu e semnată: cine poate
  scrie în `storage/` și șterge licențele poate pune acolo alte stări. Oricum,
  cine poate scrie acolo poate schimba și codul; în Etapa 2, check-in-ul la CRM va
  arăta un site care merge fără licență verificabilă.
- **Prima urcare a codului pe un site existent** (Bioscem): fără `licenta.json` în
  `storage/licenta/` (dosarul nu e în git) și fără stare aplicată, site-ul merge
  doar cu nucleul. Verificarea `scripts/diagnostic.php --licenta` se rulează
  înainte de a muta codul.

## Dezvoltare

`tools/dev/instaleaza.sh` pune licența „toate” (toate pluginurile, inclusiv unealta
internă). Licențele de dezvoltare ale celor patru site-uri existente sunt scrise
explicit în `config/plugins.php` → `licente_dev`: `bioscem` (ce avea codul Bioscem la
`4915478`, fără unealta internă), `biovitality`, `vertij`, `grafoanaytis` (catalog și
cereri de ofertă). Sunt liste pentru teste, după codul vechi al fiecărui site, nu
contractul clientului (acela e în CRM). Un plugin portat mai târziu nu intră singur
în ele: intră doar scris explicit, cu motivul lui (`instantaneu-rute.php` pică dacă
lista Bioscem se schimbă). Altă licență:

```sh
tools/dev/php.sh tools/dev/licenta.php prezentare            # sau catalog, magazin, asociatie, toate, bioscem, grafoanaytis…
tools/dev/php.sh tools/dev/licenta.php magazin cupoane=pornit magazin=se_opreste
tools/dev/php.sh tools/dev/licenta.php fara                  # fără licență și fără stare aplicată: doar nucleul
tools/dev/php.sh tools/dev/licenta.php arata                 # ce licență e folosită și ce probleme are
tools/dev/php.sh tools/dev/licenta.php activari              # doar pașii de activare ai licenței de acum (baza la zi cu migrările)
tools/dev/php.sh tools/dev/verifica-rute.php                 # fiecare rută cu proprietarul ei
tools/dev/php.sh tools/dev/instantaneu-rute.php              # suprafața fiecărei licențe față de tools/dev/instantanee/
tools/dev/php.sh tools/dev/instantaneu-rute.php --actualizeaza   # după o schimbare voită (fișierele intră în commit)
tools/dev/php.sh tools/dev/test-pluginuri.php                # toate testele (pornește singur serverul)
tools/dev/php.sh tools/dev/test-pluginuri.php --fiecare-plugin   # plus fiecare plugin oprit pe rând, cu restul pornite (~20 min)
tools/dev/php.sh tools/dev/test-pluginuri.php --fara-garda   # fără cererea fiecărei rute (mai repede)
tools/dev/php.sh tools/dev/test-pluginuri.php --capturi=DIR  # capturile meniului adminului, pe pachete, în DIR
tools/dev/compara-cu-baza.sh                                 # „toate” = HTML identic cu codul Bioscem
tools/dev/compara-cu-baza.sh --licenta=bioscem               # la fel, cu licența de dezvoltare a Bioscem (fără unealta internă)
tools/dev/smoke.sh --toate-pachetele                         # testul de fum pe fiecare pachet
tools/dev/smoke.sh --pachet=grafoanaytis                     # pe licența unui site existent
tools/dev/php.sh scripts/diagnostic.php --licenta            # se verifică licența? (ca înaintea unui deploy)
```

**Regula pentru cod nou într-un șablon**: tot ce ține de un plugin se pune între
`<?php if (...): ?>` și `<?php endif; ?>` scrise **la începutul rândului**
(coloana 0). PHP înghite sfârșitul de rând de după `?>`, deci cu pluginul pornit
pagina iese exact ca înainte; un `<?php if` indentat lasă spațiile în HTML, iar
`compara-cu-baza.sh` îl prinde. Blocul trebuie să închidă tot HTML-ul pe care îl
deschide (`verifica-rute.php` pică altfel). Un text care schimbă doar câteva
cuvinte se scrie la mijlocul rândului (`… plătită<?= $cuErp(' și pleacă în ERP', '') ?>.`);
un `?>` la capăt de rând înghite sfârșitul de rând, deci acolo textul echo-at
trebuie să-l conțină (`"…\n"`). O etichetă sau o explicație nouă a unui plugin se
adaugă și la `$semneText` din `test-pluginuri.php`, ca testul să o caute acolo
unde pluginul e oprit. Un plugin nou își trece în catalog și codurile
(„tokenuri”) și paginile din bază („pagini”), ca filtrele să le găsească; dacă
are coduri în editorul de pagini, previzualizarea lor intră în
`AdminController::previzualizariEditorPagini` (calculată doar cu pluginul pe site).
O rută nouă (sau un proprietar schimbat) schimbă instantaneul rutelor: se rulează
`instantaneu-rute.php --actualizeaza`, iar fișierele din `tools/dev/instantanee/` intră în
același commit, ca schimbarea suprafeței fiecărui pachet să se vadă la revizie.
O acțiune a unui plugin trimisă printr-o rută comună (de exemplu `bulk_action` din
comenzi) se declară în `rute_impartite`, ca garda s-o oprească; verificarea se
pune și acolo unde se face lucrul (de exemplu `createFanAwbInternal`). Instantaneul
are câte un rând pentru fiecare valoare din fiecare listă a `rute_impartite`, deci
o valoare mutată la alt plugin se vede în `git diff`.
O adresă la care răspunde un procesator, ERP-ul sau un link dintr-un email trimis se
declară în `rute_mereu_deschise`: așa răspunde oricare ar fi starea pluginului și
nu primește niciodată o redirecționare SEO (vezi [SEO.md](SEO.md)). Se adaugă și în
lista fixă `$mereuDeschiseFixe` din `instantaneu-rute.php`, cu metoda ei: o adresă
mereu deschisă care nu e acolo face instantaneul să pice (și cu `--actualizeaza`),
ca o adresă a coșului sau a plăților să nu poată ajunge deschisă din catalog. Paginile publice
noi ale unui plugin intră în harta site-ului doar dacă `App\Support\Sitemap` le
adaugă, cu verificarea pluginului (`Plugins::vizibil`).

Cheia privată de dezvoltare stă în `storage/logs/dev/licenta-cheie-privata.txt`
(niciodată în git); `tools/dev/comun.sh` dă aplicației cheia publică. În
dezvoltare, garda adaugă antetul `X-Andaxi-Garda` când răspunde ea (doar cu
`CMS_DEV_GARDA_ANTET=1`, pe care îl pune `comun.sh`; pe site-uri nu există).
