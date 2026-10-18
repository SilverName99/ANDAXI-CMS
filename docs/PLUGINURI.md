# Pluginurile și licența

Un site primește **nucleul** (pagini, meniu, galerie, SEO, emailuri, formular de
contact, administratori) și **pluginurile** din contractul lui. Ce pluginuri are
un site hotărăște doar CRM-ul, printr-o licență semnată; clientul nu poate porni
nimic și nu vede nimic despre pluginurile pe care nu le are.

## Unde stă fiecare lucru

| Fișier | Ce e |
|---|---|
| `config/plugins.php` | **Catalogul**: pentru fiecare plugin numele, descrierea, de ce are nevoie, rutele, paginile, intrările din meniu, cronurile, tabelele. Plus ordinea meniului adminului, rutele împărțite între pluginuri și pachetele de abonament. |
| `src/Support/Licenta.php` | Citirea și verificarea licenței (`storage/licenta/licenta.json`) și memoria „ultima bună”. Aici se adaugă în Etapa 2 preluarea de la CRM. |
| `src/Support/Plugins.php` | Întrebările pentru tot codul: `activ`, `permiteInceput`, `vizibil`, `stare`; regula dependențelor; proprietarul unei rute; meniul. |
| `src/Http/rute.php` | Toate rutele site-ului (mutate din `public/index.php`). |
| `src/Http/GardaPluginuri.php` | Garda routerului: o rută a unui plugin care nu e pe site nu ajunge la controller. |
| `tools/dev/licenta.php` | Chei și licențe de test (dezvoltare). |
| `tools/dev/verifica-rute.php` | Verifică că fiecare rută are proprietar și că catalogul corespunde codului. |
| `tools/dev/test-pluginuri.php` | Testele: licența, garda, fiecare pachet parcurs pagină cu pagină (și cu browserul), „se oprește”, comparația cu codul de bază. |
| `tools/dev/compara-cu-baza.sh` | Cu licența „toate”, compară HTML-ul paginilor-cheie cu cel al codului de dinainte de pluginuri (`f5ae5ea`). |
| `tools/dev/crawl-pachet.cjs` | Trecerea cu Playwright prin paginile unui pachet (DOM după JavaScript, erori JS, captura meniului). |

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
  `semnatura`: cheile obiectelor sortate, fără spații, fără escape la `/` și la
  diacritice (`JSON.stringify` cu cheile sortate dă același lucru). Așa ordinea
  câmpurilor și spațiile din fișier nu contează, dar orice valoare schimbată da.
- **Cheia publică** a CRM-ului: `LICENTA_CHEIE_PUBLICA` (base64 sau base64url, 32
  de octeți); mai multe, separate prin virgulă, cât timp se schimbă cheia.
  `LICENTA_SITE`: identificatorul site-ului; o licență pentru alt site se respinge.

Reguli la fiecare cerere (verificarea durează câteva zeci de microsecunde):

1. `licenta.json` bună și cu `versiune` ≥ cea a ultimei bune → se folosește și
   devine `licenta-buna.json`.
2. Semnătură greșită sau lipsă, alt site, JSON stricat, versiune mai mică → se
   ignoră; rămâne `licenta-buna.json`.
3. Nicio licență bună, niciodată → doar nucleul, iar în admin apare „Licența
   lipsește”.

Fiecare problemă se scrie **o singură dată** în `storage/logs/pluginuri.log`
(nu la fiecare cerere), la fel orice schimbare de stări. La o schimbare de stări
se golește memoria rapidă a paginilor. Cererile primite la adresele de confirmare
ale unui plugin oprit se notează tot acolo.

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
  dacă e pe site magazinul sau oricare plată.

Ce face garda cu o rută a unui plugin care nu e vizibil:

- admin: „Modulul nu este inclus în abonament” (403), în aspectul adminului;
  nelogat, la login, ca orice pagină de admin;
- site: pagina 404 a site-ului (pagina „404” din admin, dacă e publicată), sau
  redirecționarea din licență; `/api/…` primesc un 404 scurt în JSON;
- adresele mereu deschise: ajung la controller; se notează în jurnal.

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
  adrese; un `<li>` rămas fără link și o listă rămasă goală dispar și ele.
  Conținutul din `<script>`, `<style>` și `<textarea>` nu se atinge. Cu toate
  pluginurile pe site filtrul nici nu rulează.
- **Codurile din pagini** (`{{shop_catalog}}`, `{{latest_posts:3}}` …) ale unui
  plugin ascuns se scot din pagină (`Plugins::curataTokenuri`, lista lor e în
  catalog la „tokenuri”); în editorul de pagini nu mai apar în lista de coduri.
- **Harta site-ului**: la orice schimbare de stări, prima cerere o reface (dacă
  fișierul există) fără adresele pluginurilor ascunse.
- **Lista de pagini** din admin și lista „Pagini” din constructorul de meniu nu mai
  arată paginile pluginurilor ascunse (coș, checkout, blog …). Paginile rămân în
  bază, neatinse.
- **Paginile de setări** care amestecă pluginuri (Setări magazin, Setări plăți) arată
  doar secțiunile pluginurilor de pe site, iar la salvare nu ating setările
  pluginurilor ascunse (bifa și datele lor rămân pentru o repornire).
- **Cronurile** pluginurilor care nu au voie să ruleze ies imediat, cu cod 0 și o
  singură linie („Pluginul … nu este pornit pe acest site; nu fac nimic.”).
- **Emailurile** unui plugin pleacă doar cu pluginul lui (vezi mai jos).

Cu licența „toate” nimic din toate acestea nu schimbă vreun octet: paginile-cheie
(prima pagină, produs, coș, checkout, contul clientului, dashboard, comenzi cu
fereastra comenzii, produse, clienți, setări, emailuri, editorul de pagini, pe
patru roluri de admin) se compară cu codul de dinainte (`compara-cu-baza.sh`).

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
  `{{featured_products}}`, `{{product_categories}}`; cardul „Produse” din
  dashboard; tab-ul „TVA” din Setări magazin. Tabele: `products`,
  `product_categories`, `product_category_links`, `product_extra_*`,
  `product_template*`.
- **Are nevoie de**: nimic.
- **Se oprește**: ca pornit (nu începe nimic cu bani; produsele rămân vizibile cât
  magazinul își termină comenzile).
- **Oprit**: prima pagină fără secțiunile de produse, pagina 404 fără „Vezi toate
  produsele”, fără produse în harta site-ului și în căutare.

### Magazin online (`magazin`)

- **Conține**: meniul Comenzi, Coș flotant, Automatizări, Email-uri ecommerce și
  ordinul de plată din Setări plăți; coșul, checkout-ul, `/checkout/succes/…`,
  coșul flotant, numărul din coș, prețurile și butoanele „Adaugă în coș”;
  emailurile de comandă (comandă nouă, schimbările de status, datele de plată
  OP); cronul `scripts/abandoned-cart-emails.php`; numerotarea comenzilor și
  cantitățile din Setări magazin; dashboard: „Comenzi” și „Comenzi recente”;
  codurile `{{cart_form}}`, `{{checkout_form}}`, `{{checkout_success_order_info}}`,
  `{{cart_count}}`. Tabele: `orders`, `order_items`, `email_logs`,
  `cart_abandonments`.
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
  răspunde mereu.

### Livrare FAN Courier (`livrare_fan`)

- **Conține**: Setări livrare (meniul Configurare); în fereastra comenzii: AWB,
  previzualizarea AWB, urmărirea, FANbox; în checkout: alegerea unui FANbox și
  tariful calculat live de FAN; AWB-ul generat automat la comanda plătită sau
  aprobată și emailul cu AWB; `/api/fan/…`; cronurile `fan-tracking-sync`,
  `fan-lockers-sync`, `fan-nomenclator-sync` (cer „vizibil”). Tabele: `fan_*`,
  `orders.fan_*`.
- **Are nevoie de**: magazin.
- **Se oprește**: comenzile noi nu mai primesc FANbox și tarif live (rămân regulile
  de transport generale); comenzile existente își fac AWB-ul, urmărirea și
  emailul cu AWB; cronurile rulează.
- **Oprit**: nimic FAN în checkout, în comandă sau în meniu; AWB-ul automat nu se
  mai generează; cronurile ies cu o linie.

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
  din comandă merg, linkurile deja trimise se pot plăti; setările lui se văd.
- **Oprit**: dispare din checkout, din comandă și din Setări plăți; salvarea
  paginii nu-i atinge setările; adresa de confirmare răspunde în continuare
  (cererea se notează în `storage/logs/pluginuri.log`).

### Link de plată pentru diferențe (`link_plata`)

- **Conține**: butonul „Trimite link de plată” din comandă; pagina `/plata/…`.
  Tabel: `order_payment_links`.
- **Are nevoie de**: magazin și cel puțin o plată cu cardul.
- **Se oprește**: nu se mai trimit linkuri noi; cele trimise se pot plăti.
- **Oprit**: butonul dispare, `/plata/…` dă 404.

### Legătura cu ERP-ul ANDAXI (`erp`)

- **Conține**: Setări → ERP ANDAXI; în comenzi: starea ERP, „Retrimite în ERP”,
  bannerul modulului „Magazin online” din ERP; stocul din ERP pe produse și în
  coș; expeditorul FAN pe gestiuni; cronul `scripts/erp-sync.php` („vizibil”);
  adresele `/api/erp/notificare` și `/api/erp/adrese` (mereu deschise). Tabel:
  `orders.erp_*`.
- **Are nevoie de**: magazin.
- **Se oprește**: o comandă care n-a ajuns în ERP nu mai pleacă: stă „în
  așteptare”, cu motivul scris pe ea, și pleacă singură dacă pluginul e pornit din
  nou (fără să consume din încercări). Comenzile trimise își duc mai departe
  modificările, anulările, retururile și AWB-ul; stocul se citește în continuare;
  cronul rulează.
- **Oprit**: site-ul nu mai vorbește deloc cu ERP-ul (`ErpClient` nu se mai
  creează): fără trimiteri, fără stoc și gestiuni din ERP; anulările și retururile
  comenzilor trimise așteaptă pe starea lor; meniul și coloanele ERP dispar;
  cronul iese cu o linie. Cât pluginul e pornit, `ErpModul` (starea modulului
  din ERP) lucrează exact ca înainte.

### Cupoane (`cupoane`)

- **Conține**: meniul Cupoane și Cupoane unice; câmpul de cupon din coș și
  checkout; reducerea cu cupon din fereastra comenzii. Tabel: `coupons`.
- **Are nevoie de**: magazin.
- **Se oprește**: câmpul de cupon dispare, un cupon nu se mai aplică unei comenzi
  noi (cererea directă e refuzată, cu mesaj); comenzile existente își păstrează
  reducerea.
- **Oprit**: la fel, plus meniul și reducerea cu cupon din comandă.

### Puncte de fidelitate (`fidelitate`)

- **Conține**: meniul Puncte fidelitate; punctele din coș, checkout și contul
  clientului; punctele din fereastra comenzii și din fișa clientului. Tabele:
  `loyalty_points_*`, `users.loyalty_points`, `orders.loyalty_*`.
- **Are nevoie de**: magazin și conturi de clienți.
- **Se oprește**: comenzile noi nu mai folosesc și nu mai câștigă puncte (nici
  afișarea „Primești … puncte”); comenzile existente își primesc punctele la
  finalizare, iar la anulare punctele se întorc.
- **Oprit**: punctele nu se mai văd nicăieri și nu se mai acordă; întoarcerea
  punctelor la anulare merge oricând (banii clientului).

### Recenzii produse (`recenzii`)

- **Conține**: tab-ul și formularul de recenzii de pe produs, stelele din catalog,
  `{{product_review_form}}`, `/review-form/…`, meniul Recenzii (moderare). Tabel:
  `product_reviews`.
- **Are nevoie de**: catalog.
- **Se oprește**: recenziile existente se văd, cele noi nu se mai primesc.
- **Oprit**: nimic despre recenzii pe site sau în admin.

### Precomenzi (`precomenzi`)

- **Conține**: vânzarea fără stoc (eticheta „Precomandă”, limitele), câmpurile de
  precomandă din formularul de produs, coloana și butonul „Eliberează spre ERP”
  din comenzi. Coloane: `products.preorder_*`, `orders.preorder_*`.
- **Are nevoie de**: magazin.
- **Se oprește**: produsele fără stoc nu mai pot fi precomandate; precomenzile
  plasate se eliberează normal din comandă.
- **Oprit**: nimic despre precomenzi pe site sau în admin; câmpurile de precomandă
  ale produselor rămân salvate (formularul de produs nu le atinge).

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
  `order_promo_items`.
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
  emailurile de comandă duc la prima pagină în loc de „Comenzile mele”; emailul
  de bun venit nu mai pleacă.

### Blog (`blog`)

- **Conține**: `/blog`, articolele, API-ul blogului, codurile `{{blog_posts}}`,
  `{{latest_posts}}`, `{{category_*}}`, `{{next_event_*}}`, articolele de pe prima
  pagină, meniul Blog. Tabele: `blog_*`.
- **Are nevoie de**: nimic. **Se oprește**: ca pornit. **Oprit**: nimic din blog
  pe site, în pagini, în meniu sau în harta site-ului.

### Newsletter (`newsletter`)

- **Conține**: meniul Template-uri, Liste abonați, Formulare opt-in, Statistici
  (și campaniile); formularele de abonare; cronul
  `scripts/newsletter-campaigns.php` („vizibil”); dezabonarea și urmărirea
  deschiderilor/clickurilor (mereu deschise). Tabele: `newsletter_*`,
  `orders.nl_campaign_id`.
- **Are nevoie de**: nimic.
- **Se oprește**: campaniile pornite se termină (cronul le duce la capăt); „Trimite
  acum” și „Programează” sunt refuzate, iar cronul nu mai pornește campaniile
  programate (rămân programate). Linkurile din newsletterele trimise merg.
- **Oprit**: nimic din newsletter în admin sau pe site; cronul iese cu o linie;
  dezabonarea merge în continuare.

### Chat live (`chat_live`)

- **Conține**: bulina tawk.to și tab-ul „Chat” din Setări magazin.
- **Se oprește** și **oprit**: bulina nu mai apare; la „oprit” dispare și tab-ul.

### Manechin (`manechin`), Acorduri GDPR (`acorduri_gdpr`)

- **Manechin**: `{{mannequin_section}}` și Configurare manechin; are nevoie de
  catalog. Oprit: secțiunea nu se mai afișează, meniul dispare.
- **Acorduri GDPR**: pagina `acorduri-gdpr`, `{{gdpr_agreements_form}}`, lista
  semnăturilor din Utilizatori. Oprit: pagina dă 404, nu se mai creează la
  generarea paginilor, meniul dispare.

### Neportate încă

`pachete_produse`, `donatii`, `membri`, `cereri_oferta`, `fisiere_cloud`,
`concurenta` există în alte site-uri; aici nu au încă nicio rută, meniu sau cron.
Unealta internă `migrare_wordpress` are doar importul din Utilizatori.

## Ce nu se poate ascunde fără o refacere mai adâncă

- **Setări livrare** ține la un loc tarifele FAN și regulile generale de transport
  (prag de transport gratuit, cost fix). Pagina e a pluginului FAN, deci un magazin
  fără FAN nu-și poate schimba din admin regulile generale (se aplică ce era
  salvat). Trebuie despărțită în două pagini.
- **Fereastra comenzii**: textele venite din ERP (note, probleme, „client agresiv”)
  sunt date salvate pe comandă și se arată și cu ERP-ul oprit, ca istoric.
- **Coloana `erp_status`** are implicit „pending”: pornit după luni de pauză,
  pluginul ERP ar trimite și comenzile vechi, încă netrimise. La repornire trebuie
  ales de la ce dată se trimit (Etapa 2, la CRM).
- **Precomenzile ținute**: cu pluginul oprit, butonul de eliberare dispare, deci
  precomenzile plasate și neeliberate rămân ținute până la repornire.
- **Textul implicit al emailului „Comandă livrată”** invită la o recenzie; e text
  editabil de client în Email-uri ecommerce, nu se ascunde singur fără recenzii.
- **Emailurile tranzacționale și formularele de contact** stau, ca în Bioscem, în
  aceeași pagină cu newsletterul (tab-urile nucleului rămân și fără newsletter).
- **Schema bazei** (`ensureOptionalSchema` și celelalte `ensure*Schema`) creează și
  coloanele pluginurilor oprite; nu se văd, dar există.
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
  nu trece prin filtru; secțiunile lui sunt ascunse în PHP, iar testul cu
  Playwright verifică DOM-ul paginilor după încărcare (nu și ferestrele deschise
  la click).

## Dezvoltare

`tools/dev/instaleaza.sh` pune licența „toate” (ca Bioscem azi). Altă licență:

```sh
tools/dev/php.sh tools/dev/licenta.php prezentare            # sau catalog, magazin, asociatie, toate
tools/dev/php.sh tools/dev/licenta.php magazin cupoane=pornit magazin=se_opreste
tools/dev/php.sh tools/dev/licenta.php fara                  # fără licență: doar nucleul
tools/dev/php.sh tools/dev/licenta.php arata                 # ce licență e folosită și ce probleme are
tools/dev/php.sh tools/dev/verifica-rute.php                 # fiecare rută cu proprietarul ei
tools/dev/php.sh tools/dev/test-pluginuri.php                # toate testele (pornește singur serverul)
tools/dev/php.sh tools/dev/test-pluginuri.php --fara-garda   # fără cererea fiecărei rute (mai repede)
tools/dev/php.sh tools/dev/test-pluginuri.php --capturi=DIR  # capturile meniului adminului, pe pachete, în DIR
tools/dev/compara-cu-baza.sh                                 # „toate” = HTML identic cu codul de bază
tools/dev/smoke.sh --toate-pachetele                         # testul de fum pe fiecare pachet
```

**Regula pentru cod nou într-un șablon**: tot ce ține de un plugin se pune între
`<?php if (...): ?>` și `<?php endif; ?>` scrise **la începutul rândului**
(coloana 0). PHP înghite sfârșitul de rând de după `?>`, deci cu pluginul pornit
pagina iese exact ca înainte; un `<?php if` indentat lasă spațiile în HTML, iar
`compara-cu-baza.sh` îl prinde. Un plugin nou își trece în catalog și codurile
(„tokenuri”) și paginile din bază („pagini”), ca filtrele să le găsească.

Cheia privată de dezvoltare stă în `storage/logs/dev/licenta-cheie-privata.txt`
(niciodată în git); `tools/dev/comun.sh` dă aplicației cheia publică. În
dezvoltare, garda adaugă antetul `X-Andaxi-Garda` când răspunde ea (doar cu
`CMS_DEV_GARDA_ANTET=1`, pe care îl pune `comun.sh`; pe site-uri nu există).
